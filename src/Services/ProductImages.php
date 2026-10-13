<?php

namespace DreamLayer\UnoPIM\Services;

use DreamLayer\UnoPIM\Models\Workflow;
use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\AiAgent\Chat\Tools\EditImage;
use Webkul\Attribute\Models\Attribute;
use Webkul\Core\Filesystem\FileStorer;
use Webkul\Product\Models\Product;
use Webkul\Product\Repositories\ProductRepository;

class ProductImages
{
    public function target(Product $p, ?string $code, int $index, string $channel, string $locale): ?array
    {
        $attributes = $p->attribute_family?->customAttributes()->whereIn('attributes.type', ['image', 'gallery']);
        $a = $code ? $attributes?->where('attributes.code', $code)->first() : $attributes?->orderBy('attributes.id')->first();
        if (! $a) {
            abort_if($code, 422, 'Image attribute is not in this product family.');

            return null;
        }
        $value = $a->getValueFromProductValues($p->values ?? [], $channel, $locale);

        return ['product_id' => $p->id, 'sku' => $p->sku, 'attribute' => $a->code,
            'attribute_id'   => $a->id, 'scope' => app(EditImage::class)->getAttributeScope($a),
            'channel'        => $channel, 'locale' => $locale, 'index' => $index,
            'version'        => hash('sha256', json_encode($value))];
    }

    public function source(Product $p, array $t): string
    {
        $a = Attribute::findOrFail($t['attribute_id']);
        $value = $a->getValueFromProductValues($p->values ?? [], $t['channel'], $t['locale']);
        if ($a->type === 'gallery') {
            $value = (is_array($value) ? array_values($value) : explode(',', (string) $value))[$t['index']] ?? null;
        }
        abort_unless(is_string($value) && $value !== '' && ! str_contains($value, '..') && ! str_starts_with($value, '/'), 422);
        $root = realpath(Storage::disk('public')->path(''));
        $path = realpath(Storage::disk('public')->path($value));
        abort_unless($root && $path && str_starts_with($path, $root.DIRECTORY_SEPARATOR), 422);

        return $path;
    }

    public function apply(string $id, int $owner): Workflow
    {
        // Apply is a separate, owner-authorized write. Lock the receipt and product
        // so repeat clicks are harmless and an older preview cannot overwrite a new image.
        abort_unless(bouncer()->hasPermission('catalog.products.edit'), 403);

        return DB::transaction(function () use ($id, $owner) {
            $w = Workflow::whereKey($id)->where('user_id', $owner)->lockForUpdate()->firstOrFail();
            abort_unless($w->status === 'completed' && $w->target, 422);
            if ($w->applied_at) {
                return $w;
            }
            $t = $w->target;
            $p = Product::whereKey($t['product_id'])->lockForUpdate()->firstOrFail();
            $fresh = $this->target($p, $t['attribute'], $t['index'], $t['channel'], $t['locale']);
            abort_unless($fresh && $fresh['scope'] === $t['scope'] && $fresh['attribute_id'] === $t['attribute_id'] && $this->compatibleVersion($w, $fresh, $t), 409, 'The product image changed since this preview. Start a new preview before applying.');
            $a = Attribute::findOrFail($t['attribute_id']);
            abort_unless(app(ImageStore::class)->valid($w->result_path), 503, 'Image delivery is unavailable.');
            $path = Storage::disk('local')->path($w->result_path);
            $limit = $a->resolvedMaxKilobytes((int) (core()->getConfigData('catalog.products.attribute.image_attribute_upload_size') ?? 2048));
            abort_if($limit > 0 && filesize($path) > $limit * 1024, 422, 'The result exceeds this attribute upload limit.');
            $stored = app(FileStorer::class)->store('product/'.$p->id.'/'.$a->code, new File($path), ['disk' => 'public', FileStorer::HASHED_FOLDER_NAME_KEY => true]);
            abort_unless($stored, 500);
            $value = $stored;
            if ($a->type === 'gallery') {
                $current = $a->getValueFromProductValues($p->values ?? [], $t['channel'], $t['locale']);
                $value = is_array($current) ? array_values($current) : array_values(array_filter(explode(',', (string) $current)));
                abort_if($t['index'] > count($value), 422);
                $value[$t['index']] = $stored;
            }
            // Build from the locked latest values; preserve unrelated concurrent edits.
            $values = $p->values ?? [];
            $code = $a->code;
            match ($t['scope']) {
                'channel_locale_specific' => $values['channel_locale_specific'][$t['channel']][$t['locale']][$code] = $value,
                'channel_specific'        => $values['channel_specific'][$t['channel']][$code] = $value,
                'locale_specific'         => $values['locale_specific'][$t['locale']][$code] = $value,
                default                   => $values['common'][$code] = $value,
            };
            app(ProductRepository::class)->updateWithValues(['values' => $values], $p->id);
            if ($w->batch_id) {
                $after = $this->target($p->fresh(), $t['attribute'], $t['index'], $t['channel'], $t['locale']);
                DB::table('dreamlayer_applications')->insert(['batch_id' => $w->batch_id, 'workflow_id' => $w->id, 'product_id' => $p->id,
                    'destination'                                        => $this->destination($t), 'slot' => $t['index'], 'before_version' => $fresh['version'], 'after_version' => $after['version'],
                    'created_at'                                         => now(), 'updated_at' => now()]);
            }
            $w->update(['applied_at' => now()]);

            return $w;
        });
    }

    private function destination(array $target): string
    {
        return hash('sha256', json_encode([$target['attribute_id'], $target['scope'], $target['channel'], $target['locale']]));
    }

    private function compatibleVersion(Workflow $w, array $fresh, array $target): bool
    {
        // Gallery previews in one batch share an initial version. Allow only a
        // recorded chain of sibling-slot applications; same-slot or unrelated edits
        // still invalidate this preview instead of silently overwriting newer data.
        $version = $target['version'];
        if (hash_equals($version, $fresh['version'])) {
            return true;
        }
        if (! $w->batch_id) {
            return false;
        }
        foreach (DB::table('dreamlayer_applications')->where('batch_id', $w->batch_id)->where('product_id', $target['product_id'])->where('destination', $this->destination($target))->orderBy('id')->get() as $change) {
            if (hash_equals($version, $change->before_version)) {
                if ($change->slot === $target['index']) {
                    return false;
                }
                $version = $change->after_version;
            }
        }

        return hash_equals($version, $fresh['version']);
    }
}
