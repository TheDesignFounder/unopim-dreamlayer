<?php

namespace DreamLayer\UnoPIM\Services;

use DreamLayer\UnoPIM\Models\Workflow;
use Illuminate\Support\Facades\Log;

class WorkflowDiagnostics
{
    public static function measure(Workflow $workflow, string $operation, callable $work): mixed
    {
        // Deliberately exclude prompts, credentials, URLs and exception messages.
        // A start without a finish identifies the operation interrupted by a worker timeout.
        $context = ['workflow_id' => $workflow->id, 'operation' => $operation];
        $start = hrtime(true);
        Log::debug('DreamLayer workflow operation started', $context);
        try {
            $result = $work();
            Log::debug('DreamLayer workflow operation finished', $context + ['duration_ms' => round((hrtime(true) - $start) / 1e6)]);

            return $result;
        } catch (\Throwable $error) {
            Log::warning('DreamLayer workflow operation interrupted', $context + [
                'duration_ms' => round((hrtime(true) - $start) / 1e6),
                'error_type' => $error::class,
                'http_status' => $error instanceof ApiException ? $error->status : null,
            ]);
            throw $error;
        }
    }
}
