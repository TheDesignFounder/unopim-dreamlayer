<?php

namespace DreamLayer\UnoPIM\Services;

use Illuminate\Support\Facades\Http;
use Webkul\AiAgent\Exceptions\ApiException;
use Webkul\AiAgent\Http\Client\AiApiClient;

class ManagedApiClient extends AiApiClient
{
    protected function execute(string $method, string $url, ?array $data = null): array
    {
        $chat = app(ManagedChat::class);
        if (! $chat->active || $url !== $chat->endpoint.'/chat/completions' || $this->apiKey !== $chat->key) {
            return parent::execute($method, $url, $data);
        }
        $r = Http::withToken($chat->key)->withHeaders($chat->headers(json_encode($data)))->acceptJson()->connectTimeout(10)->timeout(120)->withoutRedirecting()->post($url, $data);
        if (! $r->successful()) {
            throw new ApiException('DreamLayer chat unavailable: '.($r->json('detail') ?? 'Check connection and limits.'), $r->status());
        }

        return $r->json();
    }
}
