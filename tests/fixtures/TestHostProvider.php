<?php

// Local fixture only. Never include this provider in a deployed host.

namespace DreamLayer\UnoPIM\Tests\Fixtures;

use DreamLayer\UnoPIM\Chat\Tools\EditImage;
use DreamLayer\UnoPIM\Chat\Tools\GenerateImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request as ToolRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\AiAgent\Chat\AgentRunner;
use Webkul\AiAgent\Chat\ChatContext;
use Webkul\AiAgent\Chat\ToolRegistry;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;

class TestHostProvider extends ServiceProvider
{
    private function configureRunner(): void
    {
        if (! app()->environment('testing')) {
            throw new \RuntimeException('Fixture provider is testing-only');
        }
        $this->app->bind(AgentRunner::class, fn () => new class(app(ToolRegistry::class)) extends AgentRunner
        {
            public function run(ChatContext $context): array
            {
                if (str_starts_with($context->message, 'Native history fixture')) {
                    $product = Product::where('sku', 'EXTENSION-FIXTURE')->firstOrFail();

                    return ['reply' => 'Native export ready. Shall I proceed?', 'action' => 'agent_response', 'result' => ['marker' => 'native-history-result', 'rows' => 10], 'product_url' => route('admin.catalog.products.edit', $product->id, false), 'download_url' => '/__fixture/native-export.csv'];
                }
                if ($context->message === 'No, cancel') {
                    return ['reply' => 'Cancelled the native fixture request.', 'action' => 'agent_response'];
                }
                $edit = str_contains(strtolower($context->message), 'edit') || str_contains(strtolower($context->message), 'remove');
                $steps = $edit ? [['operation' => 'image_to_image', 'instruction' => 'Change only the background to pastel pink']] : [['operation' => 'text_to_image', 'instruction' => 'Studio photograph of a blue vase']];
                if (str_contains(strtolower($context->message), 'remove')) {
                    $steps = [['operation' => 'background_remove']];
                }
                if (str_contains(strtolower($context->message), 'partial')) {
                    $steps = [['operation' => 'image_to_image', 'instruction' => 'Partial fixture first edit'], ['operation' => 'background_remove']];
                    $edit = true;
                }
                $tool = app($edit ? EditImage::class : GenerateImage::class)->register($context);
                $input = ['steps' => $steps];
                if (str_contains($context->message, 'EXTENSION-FIXTURE')) {
                    $input['sku'] = 'EXTENSION-FIXTURE';
                }
                $payload = json_decode($tool->handle(new ToolRequest($input)), true);

                return ['reply' => 'Fixture image workflow queued. Review the result before applying.', 'action' => 'agent_response'] + $payload;
            }

            public function runStreaming(ChatContext $context): StreamedResponse
            {

                return new StreamedResponse(function () use ($context) {
                    $r = $this->run($context);
                    echo "event: text_delta\ndata: ".json_encode(['chunk' => $r['reply']])."\n\n";
                    echo "event: complete\ndata: ".json_encode($r)."\n\n";
                }, 200, ['Content-Type' => 'text/event-stream']);
            }
        });
    }

    public function boot(): void
    {
        $this->configureRunner();
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'gravatar.com')) {
                return Http::response('', 404);
            }
            if (str_ends_with($url, '/capabilities')) {
                return Http::response(['operations' => ['text_to_image', 'image_to_image', 'background_remove'], 'key_mode' => 'test', 'api_version' => '1', 'live_charge_credits_per_image' => 1, 'image_batches' => true, 'image_batch_versions' => [1], 'image_workflows' => ! Storage::disk('local')->exists('fixture-legacy-mode'), 'image_workflow_versions' => [1]]);
            }
            if (str_ends_with($url, '/chat/limits')) {
                return Http::response(['contract_version' => 1, 'included' => true]);
            }
            if (str_ends_with($url, '/chat/completions')) {
                $messages = $request['messages'];
                $last = end($messages);
                $clarify = ($last['role'] === 'user') && str_contains(json_encode($last['content']), 'Ask me which background first');
                $tool = $last['role'] !== 'tool' && ! $clarify;
                $call = ['id' => 'fixture-call-'.substr(hash('sha256', $request->header('Idempotency-Key')[0]), 0, 20), 'type' => 'function', 'function' => ['name' => 'edit_image', 'arguments' => json_encode(['steps' => [['operation' => 'image_to_image', 'instruction' => 'Change background to pink, preserve product']]])]];
                $message = $tool ? ['role' => 'assistant', 'content' => null, 'tool_calls' => [$call]] : ['role' => 'assistant', 'content' => $clarify ? 'Which background would you like?' : 'Your previews are queued. Review each result before applying.'];
                $base = ['id' => 'chatcmpl-fixture', 'model' => 'dreamlayer-chat', 'created' => time()];
                $usage = ['prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30];
                if (! $request['stream']) {
                    return Http::response($base + ['object' => 'chat.completion', 'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $tool ? 'tool_calls' : 'stop']], 'usage' => $usage]);
                }
                $delta = $tool ? ['tool_calls' => [['index' => 0] + $call]] : ['content' => $message['content']];
                $body = 'data: '.json_encode($base + ['object' => 'chat.completion.chunk', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant'] + $delta, 'finish_reason' => null]]])."\n\n";
                $body .= 'data: '.json_encode($base + ['object' => 'chat.completion.chunk', 'choices' => [['index' => 0, 'delta' => (object) [], 'finish_reason' => $tool ? 'tool_calls' : 'stop']], 'usage' => $usage])."\n\ndata: [DONE]\n\n";

                return Http::response($body, 200, ['Content-Type' => 'text/event-stream']);
            }
            if (str_ends_with($url, '/balance')) {
                return Http::response(['available' => 3, 'promotional' => 3, 'purchased' => 0]);
            }
            if (str_ends_with($url, '/input-assets')) {
                return Http::response(['input_asset_id' => (string) Str::uuid()], 201);
            }
            if (str_ends_with($url, '/image-batches')) {
                $identity = $request->header('Idempotency-Key')[0];
                $file = 'fixture-batches/'.hash('sha256', $identity).'.json';
                if (Storage::disk('local')->exists($file)) {
                    $state = json_decode(Storage::disk('local')->get($file), true);
                } else {
                    $state = ['id' => (string) Str::uuid(), 'children' => []];
                    foreach ($request['input_asset_ids'] as $asset) {
                        $child = ['id' => (string) Str::uuid(), 'started' => time(), 'steps' => [$request['step']]];
                        Storage::disk('local')->put('fixture-executions/'.$child['id'].'.json', json_encode($child));
                        $state['children'][] = $child['id'];
                    }
                    Storage::disk('local')->put($file, json_encode($state));
                    Storage::disk('local')->put('fixture-batches/'.$state['id'].'.json', json_encode($state));
                }

                return Http::response(['batch_id' => $state['id']], 202);
            }
            if (preg_match('~/image-batches/([a-f0-9-]+)$~', $url, $m)) {
                $state = json_decode(Storage::disk('local')->get('fixture-batches/'.$m[1].'.json'), true);

                return Http::response(['contract_version' => 1, 'batch_id' => $m[1], 'status' => 'completed', 'error' => null, 'children' => array_map(fn ($id, $i) => ['position' => $i, 'workflow_id' => $id], $state['children'], array_keys($state['children']))]);
            }
            if (str_ends_with($url, '/image-workflows') || str_ends_with($url, '/execute')) {
                $identity = $request->header('Idempotency-Key')[0];
                $file = 'fixture-executions/'.hash('sha256', $identity).'.json';
                if (Storage::disk('local')->exists($file)) {
                    $state = json_decode(Storage::disk('local')->get($file), true);
                } else {
                    $state = ['id' => (string) Str::uuid(), 'started' => time(), 'steps' => $request['steps'] ?? []];
                    Storage::disk('local')->put($file, json_encode($state));
                    Storage::disk('local')->put('fixture-executions/'.$state['id'].'.json', json_encode($state));
                }

                return Http::response([str_ends_with($url, '/execute') ? 'execution_id' : 'workflow_id' => $state['id']], 202);
            }
            if (preg_match('~/executions/([a-f0-9-]+)$~', $url, $m)) {
                $s = json_decode(Storage::disk('local')->get('fixture-executions/'.$m[1].'.json'), true);

                return Http::response(['execution_id' => $m[1], 'status' => time() - $s['started'] >= 5 ? 'completed' : 'running', 'image_job' => ['finished_assets' => [['download_url' => 'https://api.dreamlayer.io/v1/image-jobs/'.$m[1].'/asset']]]]);
            }
            if (preg_match('~/image-workflows/([a-f0-9-]+)$~', $url, $m)) {
                $s = json_decode(Storage::disk('local')->get('fixture-executions/'.$m[1].'.json'), true);
                $done = time() - $s['started'] >= 5;

                if (($s['steps'][0]['instruction'] ?? '') === 'Partial fixture first edit' && $done) {
                    return Http::response(['workflow_id' => $m[1], 'contract_version' => 1, 'status' => 'failed', 'steps' => [['description' => 'Change the background', 'status' => 'completed', 'image' => ['download_url' => 'https://api.dreamlayer.io/v1/image-jobs/'.$m[1].'/asset']], ['description' => 'Remove background', 'status' => 'failed', 'image' => null]], 'image' => null]);
                }

                return Http::response(['workflow_id' => $m[1], 'status' => $done ? 'completed' : 'running', 'steps' => array_map(fn ($x) => ['description' => $x['operation'] === 'background_remove' ? 'Remove background' : $x['instruction'], 'status' => $done ? 'completed' : 'running'], $s['steps']), 'image' => $done ? ['download_url' => 'https://api.dreamlayer.io/v1/image-jobs/'.$m[1].'/asset'] : null]);
            }
            if (str_ends_with($url, '/asset')) {
                return Http::response(Storage::disk('local')->get('fixture-result.png'), 200, ['Content-Type' => 'image/png']);
            }
            throw new \RuntimeException('Unexpected fixture HTTP request');
        });
        Route::middleware('web')->get('/__fixture/login', function () {
            auth()->guard('admin')->login(Admin::where('email', 'fixture@dreamlayer.invalid')->firstOrFail());

            return redirect('/admin/dreamlayer/settings');
        });
        Route::middleware('web')->get('/__fixture/native-export.csv', function () {
            abort_unless(auth()->guard('admin')->check(), 403);

            return response("sku,name\nEXTENSION-FIXTURE,Native export\n", 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="native-export.csv"']);
        });
    }
}
