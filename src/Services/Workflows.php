<?php

namespace DreamLayer\UnoPIM\Services;

use DreamLayer\UnoPIM\Jobs\RunWorkflow;
use DreamLayer\UnoPIM\Models\Batch;
use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Models\Workflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Webkul\AiAgent\Chat\ChatContext;
use Webkul\Product\Models\Product;

/** Admits a durable preview request; catalog writes belong to ProductImages::apply(). */
class Workflows
{
    public function recover(ChatContext $context): ?Workflow
    {
        abort_unless($context->hasPermission('catalog.products.edit'), 403);

        return Workflow::where('user_id', $context->user->id)->where('conversation_id', $context->conversationId)->where('request_id', $context->requestId)->first();
    }

    public function start(ChatContext $context, array $input, bool $generate, bool $dispatch = true): Workflow
    {
        abort_unless($context->hasPermission('catalog.products.edit'), 403);
        abort_unless(Str::isUuid($context->requestId) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $context->conversationId ?? ''), 422, 'Chat request identity is required.');
        $selected = CatalogImages::selected($context);
        // The picker label is only display text. Resolve its encrypted token to an
        // owner-scoped catalog destination before trusting SKU, locale or channel.
        if (! $generate && count($selected) === 1 && ! $context->hasNewImages && ! $this->recover($context)) {
            $t = app(CatalogImages::class)->resolve($selected[0]['token'] ?? '', $context->user->id);
            $input['sku'] = $t['sku'];
            $input['attribute'] = $t['attribute'];
            $input['image_index'] = $t['index'];
            unset($input['selected_result']);
            $args = get_object_vars($context);
            $args['channel'] = $t['channel'];
            $args['locale'] = $t['locale'];
            $context = new ChatContext(...$args);
        }
        $data = Validator::make($input, [
            'steps'               => ['required', 'array', 'min:1', 'max:2'],
            'steps.*'             => ['array:operation,instruction'],
            'steps.*.operation'   => ['required', 'in:text_to_image,image_to_image,background_remove'],
            'steps.*.instruction' => ['nullable', 'string', 'max:4000'],
            'sku'                 => ['nullable', 'string', 'max:255'], 'attribute' => ['nullable', 'regex:/^[A-Za-z0-9_]+$/'],
            'image_index'         => ['nullable', 'integer', 'min:0'], 'selected_result' => ['nullable', 'uuid'],
            'aspect_ratio'        => ['nullable', 'in:1:1,3:4,4:3'],
        ])->validate();
        foreach ($data['steps'] as $step) {
            abort_unless($generate ? count($data['steps']) === 1 && $step['operation'] === 'text_to_image' : $step['operation'] !== 'text_to_image', 422);
            abort_unless($step['operation'] === 'background_remove' ? empty($step['instruction']) : filled($step['instruction'] ?? ''), 422, 'Provide executable instructions; removal is promptless.');
        }
        // Identity precedes source resolution: a retry must not pick its own newer result as input.
        $fingerprint = hash('sha256', json_encode([$data, $context->channel, $context->locale, $context->productId]));
        $identity = ['user_id' => $context->user->id, 'conversation_id' => $context->conversationId, 'request_id' => $context->requestId];

        return DB::transaction(function () use ($identity, $data, $context, $generate, $fingerprint, $dispatch) {
            // Settings row serializes admission and credential replacement; unique identity backs it up.
            $settings = Settings::current();
            $settings = Settings::whereKey($settings->id)->lockForUpdate()->firstOrFail();
            abort_unless($settings->connected(), 422, 'Connect DreamLayer first.');
            if ($existing = Workflow::where($identity)->first()) {
                abort_unless(hash_equals($existing->fingerprint, $fingerprint), 409);

                return $existing;
            }
            abort_if(Batch::where($identity)->exists(), 409, 'This request already owns a batch. Open its saved results.');
            $product = ! empty($data['sku']) ? Product::where('sku', $data['sku'])->firstOrFail() : ($context->productId ? Product::findOrFail($context->productId) : null);
            $target = $product ? app(ProductImages::class)->target($product, $data['attribute'] ?? null, $data['image_index'] ?? 0, $context->channel, $context->locale) : null;
            $source = null;
            $prior = null;
            if (! $generate) {
                // Explicit new inputs outrank conversation history. Retained upload
                // paths alone do not mean the user attached another image this turn.
                if ($context->hasNewImages) {
                    $source = $context->firstImagePath();
                    $root = realpath(Storage::disk('public')->path('ai-agent/images/'.$context->user->id.'/'.sha1($context->conversationId)));
                    $real = $source ? realpath($source) : false;
                    abort_unless($root && $real && str_starts_with($real, $root.DIRECTORY_SEPARATOR), 403);
                } elseif (! empty($data['selected_result'])) {
                    $prior = Workflow::whereKey($data['selected_result'])->where('user_id', $context->user->id)->where('conversation_id', $context->conversationId)->where('status', 'completed')->firstOrFail();
                    $source = Storage::disk('local')->path($prior->result_path);
                } elseif (! empty($data['sku']) || ! empty($data['attribute'])) {
                    abort_unless($product && $target, 422);
                    $source = app(ProductImages::class)->source($product, $target);
                } else {
                    $prior = Workflow::where('user_id', $context->user->id)->where('conversation_id', $context->conversationId)->where('status', 'completed')->latest('created_at')->first();
                    $source = $prior ? Storage::disk('local')->path($prior->result_path) : ($product && $target ? app(ProductImages::class)->source($product, $target) : null);
                }
                abort_unless($source && is_file($source), 422, 'Attach or select an image.');
            }
            // A result carries its destination across page navigation. Only an
            // explicit SKU/attribute overrides it; ambient page context does not.
            // Snapshot the current value so Apply still detects subsequent edits.
            if (empty($data['sku']) && empty($data['attribute']) && $prior?->target && ($destination = Product::find($prior->target['product_id']))) {
                $t = $prior->target;
                $target = app(ProductImages::class)->target($destination, $t['attribute'], $data['image_index'] ?? $t['index'], $t['channel'], $t['locale']);
            }
            $id = (string) Str::uuid();
            $sourcePath = null;
            if ($source) {
                abort_unless(filesize($source) <= config('dreamlayer.max_image_bytes'), 422);
                $bytes = file_get_contents($source);
                $info = @getimagesizefromstring($bytes);
                abort_unless($info && in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true), 422);
                $sourcePath = 'dreamlayer/'.$id.'/source';
                abort_unless(Storage::disk('local')->put($sourcePath, $bytes), 500);
            }
            $workflow = Workflow::create($identity + ['id' => $id, 'fingerprint' => $fingerprint,
                'api_url'                                  => Endpoints::api(),
                'credential_version'                       => $settings->credential_version, 'source_path' => $sourcePath,
                'target'                                   => $target, 'aspect_ratio' => $data['aspect_ratio'] ?? '1:1',
                'steps'                                    => array_map(fn ($s) => ['operation' => $s['operation'], 'instruction' => $s['instruction'] ?? '', 'status' => 'pending', 'execution_id' => null, 'input_asset_id' => null], $data['steps'])]);
            if ($dispatch) {
                // Workers need the committed row and pinned source/credential version.
                // Recovery can recreate a lost delivery without creating a new workflow.
                RunWorkflow::dispatch($id)->afterCommit();
            }

            return $workflow;
        });
    }
}
