<?php

namespace DreamLayer\UnoPIM\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Image-only bridge to DreamLayer's managed execution API. */
class Client
{
    public const BASE_URL = 'https://api.dreamlayer.io/v1';

    public const MODEL = 'dreamlayer-auto';

    public function __construct(private string $key) {}

    private function request()
    {
        return Http::withToken($this->key)->acceptJson()
            ->withHeaders(['DreamLayer-Version' => '1'])
            ->connectTimeout(10)->timeout(30)->withoutRedirecting();
    }

    private function checked(Response $response): Response
    {
        if (! $response->successful()) {
            throw new ApiException($response->status());
        }

        return $response;
    }

    public function upload(string $bytes): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $extension = match ($mime) {
            'image/jpeg' => 'jpg', 'image/webp' => 'webp', default => 'png'
        };
        $data = $this->checked($this->request()->attach('file', $bytes, 'source.'.$extension)
            ->post(Endpoints::api().'/input-assets'))->json();
        if (! Str::isUuid($data['input_asset_id'] ?? '')) {
            throw new \RuntimeException('Invalid input asset acknowledgement.');
        }

        return $data['input_asset_id'];
    }

    public function balances(): array
    {
        return $this->checked($this->request()->get(Endpoints::api().'/balance'))->json();
    }

    public function account(): array
    {
        return $this->checked($this->request()->get(Endpoints::api().'/account'))->json();
    }

    public function chatLimits(): array
    {
        $data = $this->checked($this->request()->get(Endpoints::api().'/chat/limits'))->json();
        if (($data['contract_version'] ?? null) !== 1 || ($data['included'] ?? false) !== true) {
            throw new ApiException(400);
        }

        return $data;
    }

    public function revoke(): void
    {
        $this->checked($this->request()->delete(Endpoints::api().'/external-api/connections/current'));
    }

    public function capabilities(): array
    {
        $data = $this->checked($this->request()->get(Endpoints::api().'/capabilities'))->json();
        if (! in_array('text_to_image', $data['operations'] ?? [], true)) {
            throw new \RuntimeException('This DreamLayer account does not support text-to-image.');
        }

        if (($data['api_version'] ?? '1') !== '1') {
            throw new ApiException(400);
        }

        return $data;
    }

    public function executionPath(): string
    {
        $caps = $this->capabilities();

        return ($caps['image_workflows'] ?? false) === true && in_array(1, $caps['image_workflow_versions'] ?? [], true) ? 'workflow_v1' : 'execute_v1';
    }

    public function submitExecution(array $payload, string $identity): string
    {
        $data = $this->checked($this->request()->withHeaders(['Idempotency-Key' => 'unopim-execute-'.$identity])->post(Endpoints::api().'/execute', $payload))->json();
        if (! Str::isUuid($data['execution_id'] ?? '')) {
            throw new \RuntimeException('Unexpected execution acknowledgement. Resume the same request.');
        }

        return $data['execution_id'];
    }

    public function executionStatus(string $id): array
    {
        if (! Str::isUuid($id)) {
            throw new \RuntimeException('Invalid execution identity.');
        }

        return $this->checked($this->request()->get(Endpoints::api().'/executions/'.$id))->json();
    }

    public function submit(array $payload, string $identity): string
    {
        $data = $this->checked($this->request()
            ->withHeaders(['Idempotency-Key' => 'unopim-'.$identity])
            ->post(Endpoints::api().'/image-workflows', $payload))->json();
        $id = $data['workflow_id'] ?? '';
        if (! Str::isUuid($id)) {
            throw new \RuntimeException('DreamLayer returned an unexpected acknowledgement. Resume the same request.');
        }

        return $id;
    }

    public function status(string $execution): array
    {
        if (! Str::isUuid($execution)) {
            throw new \RuntimeException('Invalid DreamLayer execution identifier.');
        }

        return $this->checked($this->request()->get(Endpoints::api().'/image-workflows/'.$execution))->json();
    }

    public function submitBatch(array $payload, string $identity): string
    {
        $d = $this->checked($this->request()->withHeaders(['Idempotency-Key' => 'unopim-batch-'.$identity])->post(Endpoints::api().'/image-batches', $payload))->json();
        if (! Str::isUuid($d['batch_id'] ?? '')) {
            throw new \RuntimeException('Invalid batch acknowledgement.');
        }

        return $d['batch_id'];
    }

    public function batchStatus(string $id): array
    {
        if (! Str::isUuid($id)) {
            throw new \RuntimeException('Invalid batch identity.');
        }

        return $this->checked($this->request()->get(Endpoints::api().'/image-batches/'.$id))->json();
    }

    public function image(string $url): string
    {
        // One shared deadline includes the API request and every storage redirect.
        // Leave time for PNG validation/storage before the 110-second worker timeout.
        $deadline = hrtime(true) / 1e9 + 60;
        // Credentials go only to the operator-configured API origin, never to signed storage URLs.
        if (! str_starts_with($url, Endpoints::api().'/') || parse_url($url, PHP_URL_HOST) !== parse_url(Endpoints::api(), PHP_URL_HOST)) {
            throw new \RuntimeException('DreamLayer returned an unexpected asset location.');
        }
        $response = $this->request()->get($url);
        for ($i = 0; $response->redirect() && $i < 3; $i++) {
            $location = $response->header('Location');
            $host = strtolower((string) parse_url($location, PHP_URL_HOST));
            if (parse_url($location, PHP_URL_SCHEME) !== 'https'
                || ! ($host === 'storage.googleapis.com' || str_ends_with($host, '.storage.googleapis.com'))) {
                throw new \RuntimeException('DreamLayer asset redirect was not an approved storage host.');
            }
            $remaining = $deadline - hrtime(true) / 1e9;
            if ($remaining <= 0) {
                throw new ConnectionException('Image download deadline exceeded');
            }
            $response = Http::connectTimeout(min(10, $remaining))->timeout($remaining)->withoutRedirecting()->get($location);
        }
        $bytes = $this->checked($response)->body();
        ImageStore::validate($bytes);

        return $bytes;
    }
}
