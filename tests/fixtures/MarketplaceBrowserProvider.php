<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

// Disposable browser host only. This file is excluded from distributions.
class MarketplaceBrowserProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! app()->environment('testing') || ! env('DREAMLAYER_BROWSER_FIXTURES') || (app()->runningInConsole() && ! env('DREAMLAYER_BROWSER_WORKER'))) {
            return;
        }
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $Http = Http::class;
            if (parse_url($request->url(), PHP_URL_HOST) !== 'api.dreamlayer.io') {
                return $Http::response([]);
            }
            if (str_ends_with($request->url(), '/balance')) {
                return $Http::response(['available' => 100]);
            }
            if (str_ends_with($request->url(), '/account')) {
                return $Http::response(['account_label' => 'Local compatibility fixture']);
            }
            if (str_ends_with($request->url(), '/capabilities')) {
                return $Http::response(['api_version' => '1', 'image_workflows' => true, 'image_workflow_versions' => [1], 'operations' => ['image_to_image', 'text_to_image']]);
            }
            if (str_ends_with($request->url(), '/chat/limits')) {
                return $Http::response(['contract_version' => 1, 'included' => true]);
            }
            if (str_ends_with($request->url(), '/input-assets')) {
                return $Http::response(['input_asset_id' => (string) Str::uuid()]);
            }
            if (str_ends_with($request->url(), '/image-workflows')) {
                return $Http::response(['workflow_id' => (string) Str::uuid()]);
            }
            if (str_contains($request->url(), '/image-workflows/')) {
                return $Http::response(['contract_version' => 1, 'workflow_id' => basename($request->url()), 'status' => 'completed', 'steps' => [['description' => 'Local fixture edit', 'status' => 'completed']], 'image' => ['download_url' => 'https://api.dreamlayer.io/v1/image-jobs/'.basename($request->url()).'/asset']]);
            }
            if (str_ends_with($request->url(), '/asset')) {
                $im = imagecreatetruecolor(128, 128);
                imagefill($im, 0, 0, imagecolorallocate($im, 30, 100, 190));
                ob_start();
                imagepng($im);
                $png = ob_get_clean();
                imagedestroy($im);

                return $Http::response($png, 200, ['Content-Type' => 'image/png']);
            }
            throw new RuntimeException('Unexpected browser fixture request');
        });
    }
}
