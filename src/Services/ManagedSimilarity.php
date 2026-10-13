<?php

namespace DreamLayer\UnoPIM\Services;

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
            return ScopedProviderConfig::run('openai-compatible', ['driver' => 'openai-compatible', 'key' => $chat->key, 'url' => $chat->endpoint, 'models' => ['embeddings' => ['default' => 'dreamlayer-embeddings']]], fn () => parent::rank($query, $documents, $limit));
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
