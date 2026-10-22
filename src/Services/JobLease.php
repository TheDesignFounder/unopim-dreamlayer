<?php

namespace DreamLayer\UnoPIM\Services;

use DreamLayer\UnoPIM\Models\Batch;
use DreamLayer\UnoPIM\Models\Workflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Short database claims serialize deliveries without keeping locks during HTTP. */
class JobLease
{
    public static function expire(Model $record, ?int $attemptDeadline = null): void
    {
        DB::transaction(function () use ($record, $attemptDeadline) {
            $current = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->first();
            if (! $current || in_array($current->status, ['completed', 'partial', 'failed'], true)) {
                return;
            }
            // A delivery from before an explicit recovery cannot expire the new attempt.
            if ($attemptDeadline && $current->poll_deadline && $current->poll_deadline->timestamp > $attemptDeadline && $current->poll_deadline->isFuture()) {
                return;
            }
            $deadline = now()->subSecond();
            if ($current instanceof Batch) {
                // Active children retain their own bounded delivery jobs. Only the
                // parent can admit waiting children, so stop those when it expires.
                Workflow::where('batch_id', $current->id)->where('status', 'queued')
                    ->update(['status' => 'failed', 'poll_deadline' => $deadline,
                        'error' => trans('dreamlayer::app.poll-expired')]);
            }
            $current->update(['status' => 'failed', 'poll_deadline' => $deadline,
                'error' => trans('dreamlayer::app.poll-expired')]);
        });
    }

    public static function run(string $model, string $id, callable $advance): bool
    {
        $token = (string) Str::uuid();
        $record = DB::transaction(function () use ($model, $id, $token) {
            $record = $model::whereKey($id)->lockForUpdate()->first();
            if (! $record) {
                return null;
            }
            if ($record->lease_expires_at && $record->lease_expires_at->isFuture()) {
                return false;
            }
            if ($record->poll_deadline && $record->poll_deadline->isPast()) {
                if (! in_array($record->status, ['completed', 'partial', 'failed'], true)) {
                    self::expire($record);
                }

                return null;
            }
            $record->update(['lease_token' => $token, 'lease_expires_at' => now()->addSeconds(180),
                'poll_deadline' => $record->poll_deadline ?: now()->addSeconds(config('dreamlayer.workflow_timeout', 1800))]);

            return $record;
        });
        if ($record === false) {
            return true;
        }
        if (! $record) {
            return false;
        }
        try {
            return $advance($record);
        } finally {
            // Do not clear another worker's claim if this process was suspended.
            $model::whereKey($id)->where('lease_token', $token)->update(['lease_token' => null, 'lease_expires_at' => null]);
        }
    }
}
