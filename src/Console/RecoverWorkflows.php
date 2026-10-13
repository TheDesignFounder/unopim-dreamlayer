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
    protected $signature = 'dreamlayer:recover {--verify-deliveries : Validate and repair previously terminal image deliveries}';

    protected $description = 'Recover durable image workflows after a queue interruption';

    public function handle(): int
    {
        // Recovery checks durable database payloads before dispatching. A different
        // driver cannot provide that deduplication contract; do not guess and requeue.
        if (config('queue.connections.dreamlayer.driver') !== 'database') {
            $this->error('DreamLayer recovery requires the dreamlayer queue connection to use the database driver. The default UnoPIM queue may use another driver.');

            return self::FAILURE;
        }

        Batch::whereIn('status', ['queued', 'running'])->where('updated_at', '<', now()->subMinutes(2))->each(fn ($b) => $this->dispatchMissing($b, RunBatch::class));
        Workflow::whereNotIn('status', ['completed', 'failed'])->where('updated_at', '<', now()->subMinutes(2))->chunkById(100, function ($rows) {
            foreach ($rows as $w) {
                // Batch children are admitted by their parent, then get their own delivery job.
                if (! $w->batch_id || $w->upstream_workflow_id) {
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
            $queued = DB::connection(config('queue.connections.dreamlayer.connection'))
                ->table(config('queue.connections.dreamlayer.table'))
                ->where('queue', config('dreamlayer.queue'))
                ->where('payload', 'like', '%'.$current->id.'%')
                ->get(['payload'])->contains(function ($row) use ($current, $job) {
                    // Laravel's payload column is text, not a database JSON column.
                    $data = json_decode($row->payload, true)['data'] ?? [];

                    return ($data['commandName'] ?? null) === $job
                        && is_string($data['command'] ?? null)
                        && str_contains($data['command'], serialize((string) $current->id));
                });
            if (! $queued) {
                // The extension queue uses this database; commit job and row lock together.
                $job::dispatch($current->id)->beforeCommit();
            }
        });
    }
}
