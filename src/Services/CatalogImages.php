<?php

namespace DreamLayer\UnoPIM\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Webkul\Product\Models\Product;

class CatalogImages
{
    public static function selected(object $context): array
    {
        $data = Validator::make($context->extensions, [
            'dreamlayer' => ['sometimes', 'array'],
            'dreamlayer.images' => ['sometimes', 'array', 'max:5'],
            'dreamlayer.images.*' => ['array'],
            'dreamlayer.images.*.reference' => ['sometimes', 'string', 'regex:/^Asset-[1-9][0-9]{0,12}$/'],
            'dreamlayer.images.*.mention' => ['sometimes', 'string', 'max:200', 'regex:/^[^@\x00-\x1f]+$/u'],
            'dreamlayer.images.*.token' => ['required', 'string', 'max:8000'],
        ])->validate();

        $images = $data['dreamlayer']['images'] ?? [];
        preg_match_all('/(?<![\p{L}\p{N}_])@(Asset-[1-9][0-9]*)(?![\p{L}\p{N}_-])/u', $context->message, $matches);
        $references = array_unique($matches[1]);
        if ($references) {
            $bound = array_column($images, 'reference');
            if (count($bound) !== count(array_unique($bound)) || array_diff($references, $bound)) {
                throw ValidationException::withMessages(['images' => trans('dreamlayer::app.message-268f960b4a')]);
            }
            // Text can narrow an existing selection; it can never invent a source or destination.
            $images = array_values(array_filter($images, fn ($image) => in_array($image['reference'] ?? null, $references, true)));
        }

        $labels = [];
        foreach ($images as $image) {
            if (! isset($image['mention'])) {
                continue;
            }
            if (isset($labels[$image['mention']])) {
                throw ValidationException::withMessages(['images' => trans('dreamlayer::app.message-1560bb9162')]);
            }
            $labels[$image['mention']] = true;
        }
        if ($labels) {
            $names = array_keys($labels);
            usort($names, fn ($a, $b) => strlen($b) <=> strlen($a));
            $pattern = '/(?<![\p{L}\p{N}_])@('.implode('|', array_map(fn ($name) => preg_quote($name, '/'), $names)).')(?![\p{L}\p{N}_-])/u';
            preg_match_all($pattern, $context->message, $mentioned);
            if ($mentioned[1]) {
                $images = array_values(array_filter($images, fn ($image) => in_array($image['mention'] ?? null, $mentioned[1], true)));
            }
        }

        return $images;
    }

    public function name(Product $product, string $channel, string $locale): string
    {
        $v = $product->values ?? [];

        return (string) ($v['channel_locale_specific'][$channel][$locale]['name'] ?? $v['locale_specific'][$locale]['name'] ?? $v['common']['name'] ?? $product->sku);
    }

    public function selection(Product $product, array $target, int $owner): array
    {
        return ['token' => Crypt::encryptString(json_encode(['owner' => $owner, 'target' => $target, 'expires' => now()->addHours(24)->timestamp])),
            'name' => $this->name($product, $target['channel'], $target['locale']), 'sku' => $product->sku,
            'attribute' => $target['attribute'], 'index' => $target['index'], 'channel' => $target['channel'], 'locale' => $target['locale'],
            'image_url' => route('dreamlayer.catalog.image', ['product' => $product->id, 'imageAttribute' => $target['attribute'], 'index' => $target['index'], 'channel' => $target['channel'], 'locale' => $target['locale']])];
    }

    public function resolve(string $token, int $owner): array
    {
        try {
            $selection = json_decode(Crypt::decryptString($token), true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(422, trans('dreamlayer::app.message-6157fd88c3'));
        }
        abort_unless(($selection['owner'] ?? null) === $owner && ($selection['expires'] ?? 0) > time(), 403, trans('dreamlayer::app.message-1c0232a739'));
        $t = $selection['target'];
        $product = Product::findOrFail($t['product_id']);
        $current = app(ProductImages::class)->target($product, $t['attribute'], $t['index'], $t['channel'], $t['locale']);
        abort_unless($current && hash_equals($t['version'], $current['version']), 409, trans('dreamlayer::app.message-50d669411b'));

        return $current;
    }
}
