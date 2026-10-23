<?php

namespace DreamLayer\UnoPIM\Compatibility;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Webkul\AiAgent\Chat\ChatContext;
use Webkul\AiAgent\Chat\ExtensionRegistry;
use Webkul\AiAgent\Chat\ResultRendererRegistry;
use Webkul\Product\Models\Product;
use Webkul\Product\Repositories\ProductRepository;

class HostSupport
{
    public static function available(): bool
    {
        return class_exists(Product::class)
            && class_exists(ProductRepository::class)
            && method_exists(ProductRepository::class, 'updateWithValues');
    }

    public static function nativeChat(): bool
    {
        $registry = ExtensionRegistry::class;

        return class_exists($registry) && defined($registry.'::COMPOSER_REFERENCES')
            && $registry::COMPOSER_REFERENCES >= 2
            && class_exists(ResultRendererRegistry::class)
            && property_exists(ChatContext::class, 'hasNewImages')
            && interface_exists(Tool::class)
            && interface_exists(JsonSchema::class);
    }
}
