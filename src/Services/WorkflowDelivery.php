<?php

namespace DreamLayer\UnoPIM\Services;

use DreamLayer\UnoPIM\Models\Workflow;

/** A committed delivery plan separates upstream polling from bounded, resumable downloads. */
class WorkflowDelivery
{
    public function __construct(private ImageStore $images) {}

    public function prepare(Workflow $workflow, array $remote, ?array $progress): bool
    {
        $assets = [];
        foreach ($remote['steps'] ?? [] as $index => $step) {
            if ($step['status'] !== 'completed' || empty($step['image']['download_url'])) {
                continue;
            }
            $url = $step['image']['download_url'];
            $path = $assets[$url]['path'] ?? 'dreamlayer/'.$workflow->id.'/step-'.$index.'.png';
            $assets[$url] ??= ['url' => $url, 'path' => $path];
            // The path is safe to expose only once complete PNG bytes are present.
            if ($this->images->valid($path)) {
                $progress[$index]['result_path'] = $path;
            }
            $assets[$url]['steps'][] = $index;
        }
        $status = $remote['status'];
        $finalPath = null;
        if ($status === 'completed') {
            $url = $remote['image']['download_url'] ?? '';
            if (! is_string($url) || $url === '') {
                throw new \RuntimeException('Completed workflow is missing its image');
            }
            // The API returns the last step's image as the final image. Reuse its
            // validated local file when the asset URL matches; never download it twice.
            $finalPath = $assets[$url]['path'] ?? 'dreamlayer/'.$workflow->id.'/result.png';
            $assets[$url] ??= ['url' => $url, 'path' => $finalPath];
        }
        $creditFailure = ($remote['error_code'] ?? null) === 402;
        $plan = ['status' => $status, 'assets' => array_values($assets), 'final_path' => $finalPath,
            'error_code' => $creditFailure ? 402 : null,
            'error' => $status === 'failed' ? ($creditFailure ? (new ApiException(402))->getMessage() : trans('dreamlayer::app.message-0eb6c14f32')) : null];
        $workflow->update(['progress' => $progress, 'status' => 'running', 'error' => null,
            'delivery' => $plan, 'updated_at' => now()]);
        if ($this->complete($plan)) {
            return $this->publish($workflow, $plan);
        }

        return true; // Commit the plan before any download begins, then release the job.
    }

    public function advance(Workflow $workflow, Client $client): bool
    {
        $plan = $workflow->delivery;
        foreach ($plan['assets'] as $asset) {
            if ($this->images->valid($asset['path'])) {
                continue; // Also survives a crash after atomic file publication but before DB commit.
            }
            $bytes = WorkflowDiagnostics::measure($workflow, 'download_image', fn () => $client->image($asset['url']));
            WorkflowDiagnostics::measure($workflow, 'save_image', fn () => $this->images->put($asset['path'], $bytes));
            break; // At most one image transfer per queue attempt, within the worker's budget.
        }
        $progress = $workflow->progress;
        foreach ($plan['assets'] as $asset) {
            if ($this->images->valid($asset['path'])) {
                foreach ($asset['steps'] ?? [] as $index) {
                    $progress[$index]['result_path'] = $asset['path'];
                }
            }
        }
        $workflow->update(['progress' => $progress, 'error' => null, 'updated_at' => now()]);
        if ($this->complete($plan)) {
            return $this->publish($workflow, $plan);
        }

        return true;
    }

    private function complete(array $plan): bool
    {
        foreach ($plan['assets'] as $asset) {
            if (! $this->images->valid($asset['path'])) {
                return false;
            }
        }

        return true;
    }

    private function publish(Workflow $workflow, array $plan): bool
    {
        $workflow->update(['status' => $plan['status'], 'delivery' => null,
            'result_path' => $plan['final_path'], 'error' => $plan['error'],
            'error_code' => $plan['error_code'], 'updated_at' => now(), 'completed_at' => $plan['status'] === 'completed' ? ($workflow->completed_at ?: now()) : null]);

        return ! $workflow->terminal();
    }
}
