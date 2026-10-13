<?php

namespace DreamLayer\UnoPIM\Jobs;

use DreamLayer\UnoPIM\Models\Batch;
use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Models\Workflow;
use DreamLayer\UnoPIM\Services\ApiException;
use DreamLayer\UnoPIM\Services\Client;
use DreamLayer\UnoPIM\Services\Endpoints;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RunBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $timeout = 110;

    public function __construct(public string $batchId)
    {
        $this->onConnection('dreamlayer')->onQueue(config('dreamlayer.queue'));
    }

    private function fail(Batch $batch, string $error): void
    {
        $children = Workflow::where('batch_id', $batch->id)->get();
        foreach ($children as $child) {
            if (! $child->upstream_workflow_id) {
                $child->update(['status' => 'failed', 'error' => 'This image was not started. '.$error]);
            }
        }
        $batch->update(['status' => $children->contains(fn ($w) => $w->status === 'completed') ? 'partial' : 'failed', 'error' => $error]);
    }

    public function handle(): void
    {
        $pending = DB::transaction(function () {
            $b = Batch::whereKey($this->batchId)->lockForUpdate()->first();
            if (! $b || in_array($b->status, ['completed', 'partial', 'failed'], true)) {
                return false;
            }
            if ($b->api_url !== Endpoints::api()) {
                $b->update(['error' => 'Restore the original API endpoint to resume this batch.']);

                return true;
            }
            $s = Settings::current();
            if (! $s->credential || $s->credential_version !== $b->credential_version) {
                $this->fail($b, 'Connection changed. Review the existing batch before starting another.');

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
                            $w->update(['status' => 'failed', 'error' => 'This image was not started. Check available credits and connection.']);
                        }
                    }
                }
                $b->update(['status' => $remote['status'], 'error' => $remote['error'] ?? null]);

                return ! $terminal;
            } catch (ApiException $e) {
                $b->update(['error' => $e->getMessage()]);
                if (in_array($e->status, [400, 401, 402, 403, 404, 409, 422], true)) {
                    $this->fail($b, $e->getMessage());

                    return false;
                }
            } catch (\Throwable) {
                $b->update(['error' => 'Connection interrupted. Resuming the same batch.']);
            }

            return true;
        });
        if ($pending && $this->job) {
            $this->release(2);
        }
    }
}
