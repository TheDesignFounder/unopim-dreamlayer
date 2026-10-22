<?php

namespace DreamLayer\UnoPIM\Http\Controllers;

use DreamLayer\UnoPIM\Http\Requests\CatalogRequest as Request;
use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Services\CatalogImages;
use DreamLayer\UnoPIM\Services\Client;
use DreamLayer\UnoPIM\Services\ProductImages;
use Illuminate\Routing\Controller;
use Webkul\Attribute\Models\AttributeFamily;
use Webkul\Product\Models\Product;

class CatalogController extends Controller
{
    private function scope(Request $request): array
    {
        abort_unless(bouncer()->hasPermission('catalog.products.edit'), 403);

        return [$request->input('channel', core()->getRequestedChannelCode()), $request->input('locale', core()->getRequestedLocaleCode())];
    }

    public function options(Request $request)
    {
        $this->scope($request);
        $s = Settings::current();
        abort_unless($s->connected(), 422, trans('dreamlayer::app.message-32322cd6fa'));
        try {
            $caps = (new Client($s->credential))->capabilities();
        } catch (\Throwable) {
            abort(503, trans('dreamlayer::app.message-5bf8f9ce3a'));
        }

        return response()->json(['batch_supported' => ($caps['image_workflows'] ?? false) && in_array(1, $caps['image_workflow_versions'] ?? [], true),
            'credits_per_image' => $caps['live_charge_credits_per_image'] ?? null, 'test_mode' => ($caps['key_mode'] ?? null) === 'test',
            'maximum_steps' => ($caps['image_workflows'] ?? false) && in_array(1, $caps['image_workflow_versions'] ?? [], true) ? 2 : 1])->header('Cache-Control', 'private, no-store');
    }

    public function page(Request $request)
    {
        $this->scope($request);

        return view('dreamlayer::choose');
    }

    public function index(Request $request, CatalogImages $catalog, ProductImages $images)
    {
        [$channel, $locale] = $this->scope($request);
        $query = Product::query();
        if ($request->filled('q')) {
            $query->where('sku', 'like', addcslashes($request->input('q'), '%_\\').'%');
        }
        if ($request->filled('product_id')) {
            $query->whereKey($request->integer('product_id'));
        }
        if ($request->filled('family')) {
            $query->where('attribute_family_id', $request->integer('family'));
        }
        $products = $query->orderBy('id')->paginate(12);
        $images->preload($products->getCollection());
        $result = [];
        foreach ($products as $product) {
            $selections = [];
            foreach ($product->getRelation('dreamlayerImageAttributes') as $a) {
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
            'families' => AttributeFamily::orderBy('code')->get(['id', 'code'])])->header('Cache-Control', 'private, no-store');
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
