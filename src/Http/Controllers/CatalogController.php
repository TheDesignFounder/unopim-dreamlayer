<?php

namespace DreamLayer\UnoPIM\Http\Controllers;

use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Services\CatalogImages;
use DreamLayer\UnoPIM\Services\Client;
use DreamLayer\UnoPIM\Services\ProductImages;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Webkul\Attribute\Models\AttributeFamily;
use Webkul\Product\Models\Product;

class CatalogController extends Controller
{
    private function scope(Request $request): array
    {
        abort_unless(bouncer()->hasPermission('catalog.products.edit'), 403);
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'family' => ['nullable', 'integer'], 'product_id' => ['nullable', 'integer'], 'page' => ['nullable', 'integer', 'min:1'],
            'channel'           => ['nullable', 'regex:/^[A-Za-z0-9_-]+$/'], 'locale' => ['nullable', 'regex:/^[A-Za-z0-9_-]+$/']]);

        return [$request->input('channel', core()->getRequestedChannelCode()), $request->input('locale', core()->getRequestedLocaleCode())];
    }

    public function options(Request $request)
    {
        $this->scope($request);
        $s = Settings::current();
        abort_unless($s->connected(), 422, 'Connect DreamLayer first.');
        try {
            $caps = (new Client($s->credential))->capabilities();
        } catch (\Throwable) {
            abort(503, 'Image pricing and capabilities are unavailable. Try again shortly.');
        }

        return response()->json(['batch_supported' => ($caps['image_batches'] ?? false) && in_array(1, $caps['image_batch_versions'] ?? [], true),
            'credits_per_image'                    => $caps['live_charge_credits_per_image'] ?? null, 'test_mode' => ($caps['key_mode'] ?? null) === 'test',
            'maximum_steps'                        => ($caps['image_workflows'] ?? false) && in_array(1, $caps['image_workflow_versions'] ?? [], true) ? 2 : 1])->header('Cache-Control', 'private, no-store');
    }

    public function page(Request $request)
    {
        $this->scope($request);

        return view('dreamlayer::choose');
    }

    public function index(Request $request, CatalogImages $catalog, ProductImages $images)
    {
        [$channel, $locale] = $this->scope($request);
        $query = Product::query()->with('attribute_family');
        if ($request->filled('q')) {
            $search = '%'.addcslashes($request->input('q'), '%_\\').'%';
            $query->where(function ($q) use ($search, $channel, $locale) {
                $q->where('sku', 'like', $search)->orWhere('values->common->name', 'like', $search)
                    ->orWhere('values->locale_specific->'.$locale.'->name', 'like', $search)
                    ->orWhere('values->channel_locale_specific->'.$channel.'->'.$locale.'->name', 'like', $search);
            });
        }
        if ($request->filled('product_id')) {
            $query->whereKey($request->integer('product_id'));
        }
        if ($request->filled('family')) {
            $query->where('attribute_family_id', $request->integer('family'));
        }
        $products = $query->orderBy('id')->paginate(12);
        $result = [];
        foreach ($products as $product) {
            $selections = [];
            foreach ($product->attribute_family?->customAttributes()->whereIn('attributes.type', ['image', 'gallery'])->get() ?? [] as $a) {
                $value = $a->getValueFromProductValues($product->values ?? [], $channel, $locale);
                $count = $a->type === 'gallery' ? count(is_array($value) ? $value : array_filter(explode(',', (string) $value))) : 1;
                for ($index = 0; $index < $count; $index++) {
                    $target = $images->target($product, $a->code, $index, $channel, $locale);
                    try {
                        $images->source($product, $target);
                    } catch (\Throwable) {
                        continue;
                    }
                    $selections[] = $catalog->selection($product, $target, auth()->guard('admin')->id());
                }
            }
            $result[] = ['id' => $product->id, 'name' => $catalog->name($product, $channel, $locale), 'sku' => $product->sku, 'images' => $selections];
        }

        return response()->json(['products' => $result, 'next_page' => $products->hasMorePages() ? $products->currentPage() + 1 : null,
            'families'                      => AttributeFamily::orderBy('code')->get(['id', 'code'])])->header('Cache-Control', 'private, no-store');
    }

    public function image(Request $request, Product $product, string $imageAttribute, int $index, ProductImages $images)
    {
        [$channel, $locale] = $this->scope($request);
        abort_if($index < 0, 422);
        $p = $product;
        $target = $images->target($p, $imageAttribute, $index, $channel, $locale);
        abort_unless($target, 404);
        $path = $images->source($p, $target);
        $mime = mime_content_type($path);
        abort_unless(in_array($mime, ['image/png', 'image/jpeg', 'image/webp']), 422);

        return response()->file($path, ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
