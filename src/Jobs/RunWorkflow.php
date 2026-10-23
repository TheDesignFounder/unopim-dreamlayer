<?php

namespace DreamLayer\UnoPIM\Jobs;

use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Models\Workflow;
use DreamLayer\UnoPIM\Services\ApiException;
use DreamLayer\UnoPIM\Services\Client;
use DreamLayer\UnoPIM\Services\Endpoints;
use DreamLayer\UnoPIM\Services\ImageStore;
use DreamLayer\UnoPIM\Services\JobLease;
use DreamLayer\UnoPIM\Services\WorkflowDelivery;
use DreamLayer\UnoPIM\Services\WorkflowDiagnostics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/** Local durable delivery bridge. DreamLayer exclusively executes and reports steps. */
class RunWorkflow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 500;

    public ?int $retryDeadline = null;

    public function retryUntil(): \DateTimeInterface
    {
        return (new \DateTimeImmutable)->setTimestamp($this->retryDeadline ?? time());
    }

    public int $timeout = 110;

    public function __construct(public string $workflowId)
    {
        $this->retryDeadline = time() + (int) config('dreamlayer.workflow_timeout', 1800);
        $this->onConnection(config('dreamlayer.queue_connection') ?: config('queue.default'))->onQueue(config('dreamlayer.queue'));
    }

    public function failed(?\Throwable $exception): void
    {
        if ($workflow = Workflow::find($this->workflowId)) {
            JobLease::expire($workflow, $this->retryDeadline);
        }
    }

    public function handle(): void
    {
        // One delivery advances the saved state, then releases the same job to poll.
        // Keep the workflow UUID as the upstream idempotency key: a lost response or
        // stopped worker must not admit another paid edit. No catalog write occurs here.
        $delay = (int) config('dreamlayer.poll_interval', 5);
        $pending = JobLease::run(Workflow::class, $this->workflowId, function (Workflow $w) use (&$delay) {
            $images = app(ImageStore::class);
            if (! $w || ($w->terminal() && ! $images->damaged($w))) {
                return false;
            }
            if ($w->terminal() && ! $w->upstream_workflow_id && ! $w->upstream_execution_id) {
                $w->update(['error' => trans('dreamlayer::app.message-86c4d1a6e9')]);

                return false;
            }
            if (($w->api_url ?? Client::BASE_URL) !== Endpoints::api()) {
                $w->update(['error' => trans('dreamlayer::app.message-c4c1743d5e')]);

                return true; // Preserve identity and status; never send an old job to another backend.
            }
            if ($w->batch_id && ! $w->upstream_workflow_id && ! $w->independentlyAdmitted()) {
                return false;
            }
            $settings = Settings::current();
            if (! $settings->credential || $settings->credential_version !== $w->credential_version) {
                $w->update(['status' => 'failed', 'error' => trans('dreamlayer::app.message-99c493dceb')]);

                return false;
            }
            $client = new Client($settings->credential);
            $delivering = $w->delivery && empty($w->delivery['refresh']);
            try {
                if ($delivering) {
                    $pending = app(WorkflowDelivery::class)->advance($w, $client);
                    if ($w->delivery) $delay = 0;

                    return $pending;
                }
                if (! $w->execution_path) {
                    // Existing admitted workflows retain their path even if capabilities changed.
                    $path = $w->upstream_workflow_id ? 'workflow_v1' : WorkflowDiagnostics::measure($w, 'capabilities', fn () => $client->executionPath(count($w->steps) === 1));
                    $w->update(['execution_path' => $path]);
                    $delay = 0;

                    return true; // Commit the choice before any upstream admission.
                }
                if ($w->execution_path === 'execute_v1' && count($w->steps) !== 1) {
                    $w->update(['status' => 'failed', 'error' => trans('dreamlayer::app.message-87ed76bc53')]);

                    return false;
                }
                if ($w->source_path && ! $w->input_asset_id) {
                    $w->update(['input_asset_id' => WorkflowDiagnostics::measure($w, 'upload_source', fn () => $client->upload(Storage::disk('local')->get($w->source_path)))]);

                    $delay = 0;

                    return true;
                }
                if ($w->execution_path === 'execute_v1') {
                    if (! $w->upstream_execution_id) {
                        $step = $w->steps[0];
                        $id = WorkflowDiagnostics::measure($w, 'submit_execution', fn () => $client->submitExecution(['operation' => $step['operation'], 'prompt' => $step['instruction'] ?: 'Remove the background and preserve the foreground in a transparent PNG.', 'input_asset_id' => $w->input_asset_id, 'aspect_ratio' => $w->aspect_ratio, 'max_credits' => 1], $w->id));
                        $w->update(['upstream_execution_id' => $id, 'status' => 'running', 'error' => null]);

                        return true;
                    }
                    $execution = WorkflowDiagnostics::measure($w, 'execution_status', fn () => $client->executionStatus($w->upstream_execution_id));
                    if (in_array($execution['status'] ?? '', ['failed', 'cancelled', 'needs_input'], true)) {
                        $creditFailure = ($execution['error']['reason'] ?? null) === 'insufficient_credits';
                        $w->update(['status' => 'failed', 'error_code' => $creditFailure ? 402 : null,
                            'error' => $creditFailure ? (new ApiException(402))->getMessage() : trans('dreamlayer::app.message-c8b01c869d')]);

                        return false;
                    }
                    if (($execution['status'] ?? '') === 'completed') {
                        $delay = 0;

                        return app(WorkflowDelivery::class)->prepare($w, ['status' => 'completed', 'image' => $execution['image_job']['finished_assets'][0] ?? null], null);
                    }
                    // An unchanged healthy poll is still a heartbeat. Eloquent otherwise
                    // leaves updated_at unchanged and the UI incorrectly reports a delay.
                    $w->update(['status' => 'running', 'progress' => null, 'error' => null, 'updated_at' => now()]);

                    return true;
                }
                if (! $w->upstream_workflow_id) {
                    $payload = ['steps' => array_map(fn ($s) => ['operation' => $s['operation'], 'instruction' => $s['instruction']], $w->steps), 'aspect_ratio' => $w->aspect_ratio, 'input_asset_id' => $w->input_asset_id];
                    $w->update(['upstream_workflow_id' => WorkflowDiagnostics::measure($w, 'submit_workflow', fn () => $client->submit($payload, $w->id)), 'status' => 'running', 'error' => null]);

                    return true;
                }
                $remote = WorkflowDiagnostics::measure($w, 'workflow_status', fn () => $client->status($w->upstream_workflow_id));
                if (($remote['contract_version'] ?? 1) !== 1) {
                    $w->update(['status' => 'failed', 'error' => trans('dreamlayer::app.message-a1b1c47551')]);

                    return false;
                }
                if (($remote['workflow_id'] ?? '') !== $w->upstream_workflow_id || ! is_array($remote['steps'] ?? null)) {
                    throw new \RuntimeException('Invalid workflow response');
                }
                $progress = array_map(function ($s) {
                    if (! is_string($s['description'] ?? null) || strlen($s['description']) > 2000 || ! in_array($s['status'] ?? '', ['pending', 'running', 'completed', 'failed', 'skipped'], true)) {
                        throw new \RuntimeException('Invalid progress');
                    }

                    return ['description' => $s['description'], 'status' => $s['status'],
                        'execution_id' => \Illuminate\Support\Str::isUuid($s['execution_id'] ?? '') ? $s['execution_id'] : null];
                }, $remote['steps']);
                if (count($progress) < 1 || count($progress) > 2) {
                    throw new \RuntimeException('Invalid step count');
                }
                if (! in_array($remote['status'] ?? '', ['queued', 'running', 'completed', 'failed'], true)) {
                    throw new \RuntimeException('Invalid workflow status');
                }

                $pending = app(WorkflowDelivery::class)->prepare($w, $remote, $progress);
                if ($w->delivery) $delay = 0;

                return $pending;
            } catch (ApiException $e) {
                if ($delivering) {
                    // Refresh expiring/revoked asset references from the SAME upstream
                    // workflow. Download errors must never admit replacement paid work.
                    if (in_array($e->status, [403, 404, 410], true)) {
                        $w->delivery = $w->delivery + ['refresh' => true];
                    }
                    $w->fill(['error' => trans('dreamlayer::app.message-eff400d4c0'), 'updated_at' => now()])->save();

                    return true;
                }
                if (in_array($e->status, [400, 401, 402, 403, 404, 409, 422], true)) {
                    $w->update(['status' => 'failed', 'error_code' => $e->status, 'error' => $e->getMessage()]);

                    return false;
                }
                $w->update(['error' => $e->getMessage(), 'updated_at' => now()]);
            } catch (\Throwable $e) {
                $w->update(['error' => trans('dreamlayer::app.message-f76396e34d'), 'updated_at' => now()]);
            }

            return true;
        });
        if ($pending && $this->job) {
            $this->release($delay);
        }
    }
}
