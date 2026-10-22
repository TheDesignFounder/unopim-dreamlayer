<?php

namespace DreamLayer\UnoPIM\Jobs;

use DreamLayer\UnoPIM\Models\Batch;
use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Models\Workflow;
use DreamLayer\UnoPIM\Services\ApiException;
use DreamLayer\UnoPIM\Services\Client;
use DreamLayer\UnoPIM\Services\Endpoints;
use DreamLayer\UnoPIM\Services\JobLease;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RunBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 500;

    public ?int $retryDeadline = null;

    public function retryUntil(): \DateTimeInterface
    {
        return (new \DateTimeImmutable)->setTimestamp($this->retryDeadline ?? time());
    }

    public int $timeout = 110;

    public function __construct(public string $batchId)
    {
        $this->retryDeadline = time() + (int) config('dreamlayer.workflow_timeout', 1800);
        $this->onConnection(config('dreamlayer.queue_connection') ?: config('queue.default'))->onQueue(config('dreamlayer.queue'));
    }

    private function advanceIndependent(Batch $batch): bool
    {
        // The parent row is locked by handle(). Lock children in a stable order;
        // child workers never take a parent lock. Admission and queue writes commit
        // together, so duplicate parent deliveries cannot consume extra slots.
        $children = Workflow::where('batch_id', $batch->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $settings = Settings::current();
        $stop = ! $settings->credential || $settings->credential_version !== $batch->credential_version
            || $children->contains(fn ($w) => $w->status === 'failed' && in_array($w->error_code, [401, 402, 403, 410], true));
        $active = $children->filter(fn ($w) => $w->status === 'running')->count();
        foreach ($batch->children as $id) {
            $child = $children->get($id);
            if (! $child || $child->status !== 'queued') {
                continue;
            }
            if ($stop) {
                $child->update(['status' => 'failed', 'error' => trans('dreamlayer::app.message-2971396008')]);
            } elseif ($active < $batch->concurrency) {
                $child->update(['status' => 'running', 'error' => null]);
                RunWorkflow::dispatch($child->id)->beforeCommit();
                $active++;
            }
        }
        $pending = $children->contains(fn ($w) => ! $w->terminal());
        $completed = $children->filter(fn ($w) => $w->status === 'completed')->count();
        $batch->update(['status' => $pending ? 'running' : ($completed === $children->count() ? 'completed' : ($completed ? 'partial' : 'failed')),
            'error' => $stop ? trans('dreamlayer::app.message-e9e1cacfac') : null, 'updated_at' => now()]);

        return $pending;
    }

    private function fail(Batch $batch, string $error): void
    {
        $children = Workflow::where('batch_id', $batch->id)->get();
        foreach ($children as $child) {
            if (! $child->upstream_workflow_id) {
                $child->update(['status' => 'failed', 'error' => trans('dreamlayer::app.message-c86e66e758').$error]);
            }
        }
        $batch->update(['status' => $children->contains(fn ($w) => $w->status === 'completed') ? 'partial' : 'failed', 'error' => $error]);
    }

    public function failed(?\Throwable $exception): void
    {
        if ($batch = Batch::find($this->batchId)) {
            JobLease::expire($batch, $this->retryDeadline);
        }
    }

    public function handle(): void
    {
        $pending = JobLease::run(Batch::class, $this->batchId, function (Batch $b) {
            if (! $b || in_array($b->status, ['completed', 'partial', 'failed'], true)) {
                return false;
            }
            if ($b->api_url !== Endpoints::api()) {
                $b->update(['error' => trans('dreamlayer::app.message-db506fc716')]);

                return true;
            }
            if ($b->dispatch_mode === 'independent') {
                return DB::transaction(fn () => $this->advanceIndependent($b));
            }
            $s = Settings::current();
            if (! $s->credential || $s->credential_version !== $b->credential_version) {
                $this->fail($b, trans('dreamlayer::app.message-2f015e28e6'));

                return false;
            }
            $client = new Client($s->credential);
            try {
                $children = collect($b->children)->map(fn ($id) => Workflow::findOrFail($id));
                foreach ($children as $w) {
                    if (! $w->input_asset_id) {
                        $w->update(['input_asset_id' => $client->upload(Storage::disk('local')->get($w->source_path))]);

                        return true;
                    }
                }
                if (! $b->upstream_id) {
                    $first = $children->first();
                    $step = $first->steps[0];
                    $b->update(['upstream_id' => $client->submitBatch(['input_asset_ids' => $children->pluck('input_asset_id')->all(), 'step' => ['operation' => $step['operation'], 'instruction' => $step['instruction']], 'aspect_ratio' => $first->aspect_ratio], $b->id), 'status' => 'running']);

                    return true;
                }
                $remote = $client->batchStatus($b->upstream_id);
                if (($remote['contract_version'] ?? null) !== 1 || ($remote['batch_id'] ?? '') !== $b->upstream_id) {
                    throw new \RuntimeException('Invalid batch response');
                }
                foreach ($remote['children'] as $item) {
                    $w = $children[$item['position']] ?? null;
                    if (! $w || ! Str::isUuid($item['workflow_id'])) {
                        throw new \RuntimeException('Invalid child');
                    }
                    if (! $w->upstream_workflow_id) {
                        $w->update(['upstream_workflow_id' => $item['workflow_id'], 'status' => 'running']);
                        RunWorkflow::dispatch($w->id)->afterCommit();
                    } elseif ($w->upstream_workflow_id !== $item['workflow_id']) {
                        throw new \RuntimeException('Child identity changed');
                    }
                }
                $terminal = in_array($remote['status'], ['completed', 'partial', 'failed'], true);
                if ($terminal) {
                    foreach ($children as $w) {
                        if (! $w->fresh()->upstream_workflow_id) {
                            $w->update(['status' => 'failed', 'error' => trans('dreamlayer::app.message-2971396008')]);
                        }
                    }
                }
                $b->update(['status' => $remote['status'], 'error' => $remote['error'] ?? null, 'updated_at' => now()]);

                return ! $terminal;
            } catch (ApiException $e) {
                $b->update(['error' => $e->getMessage()]);
                if (in_array($e->status, [400, 401, 402, 403, 404, 409, 422], true)) {
                    $this->fail($b, $e->getMessage());

                    return false;
                }
            } catch (\Throwable) {
                $b->update(['error' => trans('dreamlayer::app.message-a25d4ee600')]);
            }

            return true;
        });
        if ($pending && $this->job) {
            $this->release((int) config('dreamlayer.poll_interval', 5));
        }
    }
}
