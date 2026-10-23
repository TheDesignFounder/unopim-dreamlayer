<?php

namespace DreamLayer\UnoPIM\Console;

use DreamLayer\UnoPIM\Jobs\RunBatch;
use DreamLayer\UnoPIM\Jobs\RunWorkflow;
use DreamLayer\UnoPIM\Models\Batch;
use DreamLayer\UnoPIM\Models\Workflow;
use DreamLayer\UnoPIM\Services\ImageStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverWorkflows extends Command
{
    protected $signature = 'dreamlayer:recover {--verify-deliveries : Validate and repair previously terminal image deliveries} {--resume-expired : Resume existing expired requests using their original identifiers}';

    protected $description = 'Recover durable image workflows after a queue interruption';

    public function handle(): int
    {
        // Uploads expire after a day; admitted requests already own a durable source copy.
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        foreach ($disk->allFiles('dreamlayer/uploads') as $file) {
            if ($disk->lastModified($file) < now()->subDay()->timestamp) $disk->delete($file);
        }
        $connection = config('dreamlayer.queue_connection') ?: config('queue.default');
        if (config('queue.connections.'.$connection.'.driver') === 'sync') {
            $this->error(trans('dreamlayer::app.async-queue-required'));

            return self::FAILURE;
        }
        if ($this->option('resume-expired')) {
            foreach ([Batch::class, Workflow::class] as $model) {
                $model::where('status', 'failed')->where('poll_deadline', '<', now())->chunkById(100, function ($rows) use ($model) {
                    foreach ($rows as $record) {
                        // Unsubmitted batch children return to their parent's admission
                        // queue. Restarting every child directly would exceed its limit.
                        $waiting = $record instanceof Workflow && $record->batch_id && ! $record->upstream_workflow_id;
                        $record->update(['status' => $waiting ? 'queued' : 'running', 'poll_deadline' => now()->addSeconds(config('dreamlayer.workflow_timeout', 1800)), 'error' => null]);
                        if (! $waiting) {
                            $this->dispatchMissing($record, $model === Workflow::class ? RunWorkflow::class : RunBatch::class);
                        } elseif ($parent = Batch::find($record->batch_id)) {
                            if (in_array($parent->status, ['partial', 'failed'], true)) {
                                $parent->update(['status' => 'running', 'poll_deadline' => now()->addSeconds(config('dreamlayer.workflow_timeout', 1800)), 'error' => null]);
                            }
                            $this->dispatchMissing($parent, RunBatch::class);
                        }
                    }
                });
            }
        }

        Batch::whereIn('status', ['queued', 'running'])->where('updated_at', '<', now()->subMinutes(2))->each(fn ($b) => $this->dispatchMissing($b, RunBatch::class));
        Workflow::whereNotIn('status', ['completed', 'failed'])->where('updated_at', '<', now()->subMinutes(2))->chunkById(100, function ($rows) {
            foreach ($rows as $w) {
                // Batch children are admitted by their parent, then get their own delivery job.
                if (! $w->batch_id || $w->upstream_workflow_id || $w->independentlyAdmitted()) {
                    $this->dispatchMissing($w, RunWorkflow::class);
                }
            }
        });

        if ($this->option('verify-deliveries')) {
            Workflow::whereIn('status', ['completed', 'failed'])->chunkById(100, function ($rows) {
                foreach ($rows as $w) {
                    if (app(ImageStore::class)->damaged($w)) {
                        $this->dispatchMissing($w, RunWorkflow::class, true);
                    }
                }
            });
        }

        return self::SUCCESS;
    }

    private function dispatchMissing(Workflow|Batch $record, string $job, bool $repair = false): void
    {
        DB::transaction(function () use ($record, $job, $repair) {
            $current = $record->newQuery()->whereKey($record->id)->lockForUpdate()->first();
            if (! $current || (! $repair && in_array($current->status, ['completed', 'partial', 'failed'], true))) {
                return;
            }
            // Database queue jobs already survive a dead worker, including expired
            // reservations. Only rebuild a missing delivery. Match the serialized
            // UUID as a complete value, without deserializing a queue payload.
            $driver = config('queue.connections.'.(config('dreamlayer.queue_connection') ?: config('queue.default')).'.driver');
            $queued = $driver === 'database' && DB::connection(config('queue.connections.'.(config('dreamlayer.queue_connection') ?: config('queue.default')).'.connection'))
                ->table(config('queue.connections.'.(config('dreamlayer.queue_connection') ?: config('queue.default')).'.table'))
                ->where('queue', config('dreamlayer.queue'))
                ->where('payload', 'like', '%'.$current->id.'%')
                ->get(['payload'])->contains(function ($row) use ($current, $job) {
                    // Laravel's payload column is text, not a database JSON column.
                    $data = json_decode($row->payload, true)['data'] ?? [];

                    if (is_string($data['command'] ?? null) && preg_match('/retryDeadline";i:(\d+);/', $data['command'], $match) && (int) $match[1] < time()) {
                        return false;
                    }

                    return ($data['commandName'] ?? null) === $job
                        && is_string($data['command'] ?? null)
                        && str_contains($data['command'], serialize((string) $current->id));
                });
            if (! $queued) {
                if ($repair) {
                    $current->update(['poll_deadline' => now()->addSeconds(config('dreamlayer.workflow_timeout', 1800))]);
                }
                // The configured queue may be external. A lost delivery is recoverable;
                // the record lease prevents duplicate jobs from advancing concurrently.
                $job::dispatch($current->id)->beforeCommit();
            }
        });
    }
}
