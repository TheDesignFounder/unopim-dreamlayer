<?php

namespace DreamLayer\UnoPIM\Jobs;

use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Models\Workflow;
use DreamLayer\UnoPIM\Services\ApiException;
use DreamLayer\UnoPIM\Services\Client;
use DreamLayer\UnoPIM\Services\Endpoints;
use DreamLayer\UnoPIM\Services\ImageStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Local durable delivery bridge. DreamLayer exclusively executes and reports steps. */
class RunWorkflow implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $timeout = 110;

    public function __construct(public string $workflowId)
    {
        $this->onConnection('dreamlayer')->onQueue(config('dreamlayer.queue'));
    }

    public function handle(): void
    {
        // One delivery advances the saved state, then releases the same job to poll.
        // Keep the workflow UUID as the upstream idempotency key: a lost response or
        // stopped worker must not admit another paid edit. No catalog write occurs here.
        $pending = DB::transaction(function () {
            $w = Workflow::whereKey($this->workflowId)->lockForUpdate()->first();
            $images = app(ImageStore::class);
            if (! $w || ($w->terminal() && ! $images->damaged($w))) {
                return false;
            }
            if ($w->terminal() && ! $w->upstream_workflow_id && ! $w->upstream_execution_id) {
                $w->update(['error' => 'Saved image is unavailable and its original execution reference is missing.']);

                return false;
            }
            if (($w->api_url ?? Client::BASE_URL) !== Endpoints::api()) {
                $w->update(['error' => 'The DreamLayer endpoint changed. Restore the original endpoint to resume this request.']);

                return true; // Preserve identity and status; never send an old job to another backend.
            }
            if ($w->batch_id && ! $w->upstream_workflow_id) {
                return false;
            }
            $settings = Settings::current();
            if (! $settings->credential || $settings->credential_version !== $w->credential_version) {
                $w->update(['status' => 'failed', 'error' => 'Connection changed. Review upstream work before starting another preview.']);

                return false;
            }
            $client = new Client($settings->credential);
            try {
                if (! $w->execution_path) {
                    // Existing admitted workflows retain their path even if capabilities changed.
                    $path = $w->upstream_workflow_id ? 'workflow_v1' : $client->executionPath();
                    $w->update(['execution_path' => $path]);

                    return true; // Commit the choice before any upstream admission.
                }
                if ($w->execution_path === 'execute_v1' && count($w->steps) !== 1) {
                    $w->update(['status' => 'failed', 'error' => 'This API supports one image operation per request. Sequential edits require the workflow capability.']);

                    return false;
                }
                if ($w->source_path && ! $w->input_asset_id) {
                    $w->update(['input_asset_id' => $client->upload(Storage::disk('local')->get($w->source_path))]);

                    return true;
                }
                if ($w->execution_path === 'execute_v1') {
                    if (! $w->upstream_execution_id) {
                        $step = $w->steps[0];
                        $id = $client->submitExecution(['operation' => $step['operation'], 'prompt' => $step['instruction'] ?: 'Remove the background and preserve the foreground in a transparent PNG.', 'input_asset_id' => $w->input_asset_id, 'aspect_ratio' => $w->aspect_ratio, 'max_credits' => 1], $w->id);
                        $w->update(['upstream_execution_id' => $id, 'status' => 'running', 'error' => null]);

                        return true;
                    }
                    $execution = $client->executionStatus($w->upstream_execution_id);
                    if (in_array($execution['status'] ?? '', ['failed', 'cancelled', 'needs_input'], true)) {
                        $creditFailure = ($execution['error']['reason'] ?? null) === 'insufficient_credits';
                        $w->update(['status' => 'failed', 'error_code' => $creditFailure ? 402 : null,
                            'error' => $creditFailure ? (new ApiException(402))->getMessage() : 'The image operation could not complete.']);

                        return false;
                    }
                    if (($execution['status'] ?? '') === 'completed') {
                        return $this->deliver($w, $client, $execution['image_job']['finished_assets'][0]['download_url'] ?? '', null);
                    }
                    // An unchanged healthy poll is still a heartbeat. Eloquent otherwise
                    // leaves updated_at unchanged and the UI incorrectly reports a delay.
                    $w->update(['status' => 'running', 'progress' => null, 'error' => null, 'updated_at' => now()]);

                    return true;
                }
                if (! $w->upstream_workflow_id) {
                    $payload = ['steps' => array_map(fn ($s) => ['operation' => $s['operation'], 'instruction' => $s['instruction']], $w->steps), 'aspect_ratio' => $w->aspect_ratio, 'input_asset_id' => $w->input_asset_id];
                    $w->update(['upstream_workflow_id' => $client->submit($payload, $w->id), 'status' => 'running', 'error' => null]);

                    return true;
                }
                $remote = $client->status($w->upstream_workflow_id);
                if (($remote['contract_version'] ?? 1) !== 1) {
                    $w->update(['status' => 'failed', 'error' => 'Unsupported workflow response version. The existing upstream job has not been resubmitted.']);

                    return false;
                }
                if (($remote['workflow_id'] ?? '') !== $w->upstream_workflow_id || ! is_array($remote['steps'] ?? null)) {
                    throw new \RuntimeException('Invalid workflow response');
                }
                $progress = array_map(function ($s) {
                    if (! is_string($s['description'] ?? null) || strlen($s['description']) > 2000 || ! in_array($s['status'] ?? '', ['pending', 'running', 'completed', 'failed', 'skipped'], true)) {
                        throw new \RuntimeException('Invalid progress');
                    }

                    return ['description' => $s['description'], 'status' => $s['status']];
                }, $remote['steps']);
                if (count($progress) < 1 || count($progress) > 2) {
                    throw new \RuntimeException('Invalid step count');
                }
                // Cache every completed output, including work preceding a later failure.
                foreach ($remote['steps'] as $index => $step) {
                    if ($step['status'] !== 'completed' || empty($step['image']['download_url'])) {
                        continue;
                    }
                    $path = $w->progress[$index]['result_path'] ?? 'dreamlayer/'.$w->id.'/step-'.$index.'.png';
                    if (! $images->valid($path)) {
                        $bytes = $client->image($step['image']['download_url']);
                        $images->put($path, $bytes);
                    }
                    $progress[$index]['result_path'] = $path;
                }
                if (($remote['status'] ?? '') === 'failed') {
                    // A structured credit code selects local, actionable UI copy.
                    // Do not infer billing state from arbitrary upstream error text.
                    $creditFailure = ($remote['error_code'] ?? null) === 402;
                    $w->update(['progress' => $progress, 'status' => 'failed', 'error_code' => $creditFailure ? 402 : null,
                        'error' => $creditFailure ? (new ApiException(402))->getMessage() : 'Image workflow failed. Later steps were not started.']);

                    return false;
                }
                if (($remote['status'] ?? '') === 'completed') {
                    return $this->deliver($w, $client, $remote['image']['download_url'] ?? '', $progress);
                }
                // Refresh liveness even when the same step is still running.
                $w->update(['progress' => $progress, 'status' => 'running', 'error' => null, 'updated_at' => now()]);
            } catch (ApiException $e) {
                if (in_array($e->status, [400, 401, 402, 403, 404, 409, 422], true)) {
                    $w->update(['status' => 'failed', 'error_code' => $e->status, 'error' => $e->getMessage()]);

                    return false;
                }
                $w->update(['error' => $e->getMessage()]);
            } catch (\Throwable $e) {
                $w->update(['error' => 'Connection or delivery interrupted. Resuming the same workflow.']);
            }

            return true;
        });
        if ($pending && $this->job) {
            $this->release(2);
        }
    }

    private function deliver(Workflow $w, Client $client, string $url, ?array $progress): bool
    {
        $bytes = $client->image($url);
        $path = 'dreamlayer/'.$w->id.'/result.png';
        app(ImageStore::class)->put($path, $bytes);
        $w->update(['progress' => $progress, 'status' => 'completed', 'result_path' => $path, 'error' => null, 'error_code' => null]);

        return false;
    }
}
