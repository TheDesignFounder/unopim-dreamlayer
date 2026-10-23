<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

// Disposable browser host only. Excluded from distributions. Review mode replays
// previously generated images; it must never be presented as a live AI test.
class MarketplaceBrowserProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! app()->environment('testing') || ! env('DREAMLAYER_BROWSER_FIXTURES') || (app()->runningInConsole() && ! env('DREAMLAYER_BROWSER_WORKER'))) {
            return;
        }
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (parse_url($request->url(), PHP_URL_HOST) !== 'api.dreamlayer.io') {
                return Http::response([], 404);
            }
            if (str_ends_with($request->url(), '/device-authorization')) {
                $device = 'review-'.Str::uuid();
                Cache::put($device, (bool) ($request['included_chat'] ?? false), 600);

                return Http::response(['device_code' => $device, 'user_code' => 'DEMO-1234', 'verification_uri' => config('dreamlayer.platform_url').'/console/connect', 'expires_in' => 600, 'interval' => 5]);
            }
            if (str_ends_with($request->url(), '/token')) {
                if ($request['approval_only'] ?? false) {
                    return Http::response(['status' => 'approved']);
                }
                $chat = Cache::get($request['device_code'], false);

                return Http::response(['access_token' => $chat ? 'review-chat-key' : 'review-image-key', 'connection_id' => 'review-'.Str::uuid(), 'scope' => 'images:execute'.($chat ? ' chat:use' : '')]);
            }
            if (str_ends_with($request->url(), '/connections/current')) {
                return Http::response([], 204);
            }
            if (str_contains($request->url(), '/chat/')) {
                if (! $request->hasHeader('Authorization', 'Bearer review-chat-key')) {
                    return Http::response(['detail' => 'Chat permission required'], 403);
                }
                if (str_ends_with($request->url(), '/chat/limits')) {
                    return Http::response(['contract_version' => 1, 'included' => true, 'image_credits_charged' => false]);
                }
                $messages = $request['messages'];
                $last = end($messages);

                // Regression fixture: reproduce the actual 0.5.2 assistant error,
                // rather than letting the echo provider mask reference pollution.
                if ($last['content'] === 'Add a soft shadow under the car. Keep everything else unchanged.'
                    && str_contains(json_encode($messages), '@Asset-1') && str_contains(json_encode($messages), '@Asset-2')) {
                    return Http::response(['choices' => [['message' => ['content' => 'Add a soft shadow under the car for @Asset-1 (blue background) and add a soft shadow under the car for @Asset-2 (white background). Keep everything else unchanged.']]]]);
                }

                return Http::response(['choices' => [['message' => ['content' => $last['content']]]]]);
            }
            if (str_ends_with($request->url(), '/balance')) {
                return Http::response(['available' => 100]);
            }
            if (str_ends_with($request->url(), '/account')) {
                return Http::response(['account_label' => 'Local review demonstration']);
            }
            if (str_ends_with($request->url(), '/capabilities')) {
                return Http::response(['api_version' => '1', 'managed_execute' => true, 'image_workflows' => true, 'image_workflow_versions' => [1], 'operations' => ['image_to_image', 'text_to_image', 'background_remove']]);
            }
            if (str_ends_with($request->url(), '/input-assets')) {
                return Http::response(['input_asset_id' => (string) Str::uuid()]);
            }
            if (str_ends_with($request->url(), '/execute')) {
                $id = (string) Str::uuid();
                $asset = $request['operation'] === 'background_remove' ? 'cutout' : 'blue';
                Storage::disk('local')->put('review-workflows/'.$id.'.json', json_encode(['asset' => $asset]));

                return Http::response(['execution_id' => $id], 202);
            }
            if (str_contains($request->url(), '/executions/')) {
                $id = basename($request->url());

                return Http::response(['execution_id' => $id, 'status' => 'completed', 'image_job' => ['finished_assets' => [['download_url' => 'https://api.dreamlayer.io/v1/image-jobs/'.$id.'/asset']]]]);
            }
            if (str_ends_with($request->url(), '/image-workflows')) {
                $id = (string) Str::uuid();
                $step = $request['steps'][0];
                $instruction = strtolower($step['instruction'] ?? '');
                $asset = $step['operation'] === 'background_remove' || str_contains($instruction, 'remove') ? 'cutout' : (str_contains($instruction, 'parking') ? 'parking' : 'blue');
                Storage::disk('local')->put('review-workflows/'.$id.'.json', json_encode(['asset' => $asset, 'step' => $step]));

                return Http::response(['workflow_id' => $id]);
            }
            if (str_contains($request->url(), '/image-workflows/')) {
                $id = basename($request->url());
                $state = json_decode(Storage::disk('local')->get('review-workflows/'.$id.'.json'), true);

                return Http::response(['contract_version' => 1, 'workflow_id' => $id, 'status' => 'completed', 'steps' => [['description' => $state['step']['instruction'] ?? 'Remove background', 'status' => 'completed']], 'image' => ['download_url' => 'https://api.dreamlayer.io/v1/image-jobs/'.$id.'/asset']]);
            }
            if (str_ends_with($request->url(), '/asset')) {
                $id = basename(dirname($request->url()));
                $state = json_decode(Storage::disk('local')->get('review-workflows/'.$id.'.json'), true);
                $directory = env('DREAMLAYER_REVIEW_ASSETS');
                if ($directory && is_file($directory.'/result-'.$state['asset'].'.png')) {
                    return Http::response(file_get_contents($directory.'/result-'.$state['asset'].'.png'), 200, ['Content-Type' => 'image/png']);
                }
                if ($directory) {
                    $im = imagecreatefromwebp($directory.'/unopim-car-'.$state['asset'].'.webp');
                    imagealphablending($im, false);
                    imagesavealpha($im, true);
                } else {
                    $im = imagecreatetruecolor(128, 128);
                    imagefill($im, 0, 0, imagecolorallocate($im, 30, 100, 190));
                }
                ob_start();
                imagepng($im);
                $png = ob_get_clean();
                imagedestroy($im);

                return Http::response($png, 200, ['Content-Type' => 'image/png']);
            }
            throw new RuntimeException('Unexpected browser fixture request');
        });
    }
}
