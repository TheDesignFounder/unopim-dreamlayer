<?php

namespace DreamLayer\UnoPIM\Services;

use Illuminate\Support\Facades\Log;
use Webkul\AiAgent\Services\EmbeddingSimilarityService;
use Webkul\MagicAI\Services\ScopedProviderConfig;

class ManagedSimilarity extends EmbeddingSimilarityService
{
    public function rank(string $query, array $documents, ?int $limit = null): array
    {
        $chat = app(ManagedChat::class);
        if (! $chat->active) {
            return parent::rank($query, $documents, $limit);
        }
        $old = config('ai.default_for_embeddings');
        config(['ai.default_for_embeddings' => 'openai-compatible']);
        try {
            // The merged core's rank() selects a native MagicAI platform. Managed
            // chat must instead use its scoped endpoint and installation key.
            return ScopedProviderConfig::run('openai-compatible', ['driver' => 'openai-compatible', 'key' => $chat->key, 'url' => $chat->endpoint, 'models' => ['embeddings' => ['default' => 'dreamlayer-embeddings']]], fn () => parent::rankOrFail($query, $documents, $limit));
        } catch (\Throwable $e) {
            Log::warning('DreamLayer similarity ranking failed.');

            return [];
        } finally {
            config(['ai.default_for_embeddings' => $old]);
        }
    }

    public function rankProducts(string $query, ?int $limit = null, ?int $attributeFamilyId = null): array
    {
        // Existing indexes can use another model/dimension. Use the native on-demand
        // ranking fallback instead of querying a semantically incompatible index.
        return app(ManagedChat::class)->active ? [] : parent::rankProducts($query, $limit, $attributeFamilyId);
    }
}
