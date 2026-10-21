<?php

namespace DreamLayer\UnoPIM\Services;

use DreamLayer\UnoPIM\Jobs\RunBatch;
use DreamLayer\UnoPIM\Models\Batch;
use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Models\Workflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\AiAgent\Chat\ChatContext;

class Batches
{
    public function recover(ChatContext $c): ?Batch
    {
        abort_unless($c->hasPermission('catalog.products.edit'), 403);

        return Batch::where(['user_id' => $c->user->id, 'conversation_id' => $c->conversationId, 'request_id' => $c->requestId])->first();
    }

    public function start(ChatContext $c, array $input): Batch
    {
        abort_unless($c->hasPermission('catalog.products.edit'), 403);
        abort_unless(Str::isUuid($c->requestId) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $c->conversationId ?? ''), 422);
        $selected = CatalogImages::selected($c);
        abort_unless(is_array($selected) && count($selected) >= 2 && count($selected) <= 5 && is_array($input['steps'] ?? null) && count($input['steps']) === 1 && ! $c->hasNewImages, 422, 'Choose two to five catalog images and one editing step.');

        return DB::transaction(function () use ($c, $input, $selected) {
            $settings = Settings::whereKey(Settings::current()->id)->lockForUpdate()->firstOrFail();
            abort_unless($settings->connected(), 422);
            $fingerprint = hash('sha256', json_encode([$input, array_column($selected, 'token')]));
            if ($existing = $this->recover($c)) {
                abort_unless(hash_equals($fingerprint, $existing->fingerprint), 409);

                return $existing;
            }
            abort_if(Workflow::where(['user_id' => $c->user->id, 'conversation_id' => $c->conversationId, 'request_id' => $c->requestId])->exists(), 409, 'This request already owns an image workflow.');
            $caps = (new Client($settings->credential))->capabilities();
            abort_unless(($caps['image_workflows'] ?? false) && in_array(1, $caps['image_workflow_versions'] ?? [], true), 422, 'This API does not support independent image workflows. Select one image.');
            $id = (string) Str::uuid();
            $children = [];
            $destinations = [];
            foreach ($selected as $index => $selection) {
                abort_unless(is_array($selection) && is_string($selection['token'] ?? null), 422, 'Invalid image selection.');
                $t = app(CatalogImages::class)->resolve($selection['token'], $c->user->id);
                $identity = json_encode([$t['product_id'], $t['attribute'], $t['index'], $t['channel'], $t['locale']]);
                abort_if(in_array($identity, $destinations, true), 422, 'Select each image once.');
                $destinations[] = $identity;
                $args = get_object_vars($c);
                $args['requestId'] = (string) Str::uuid();
                $args['channel'] = $t['channel'];
                $args['locale'] = $t['locale'];
                $args['extensions'] = [];
                $data = $input;
                unset($data['selected_result']);
                $data['sku'] = $t['sku'];
                $data['attribute'] = $t['attribute'];
                $data['image_index'] = $t['index'];
                $w = app(Workflows::class)->start(new ChatContext(...$args), $data, false, false);
                $w->update(['batch_id' => $id, 'execution_path' => 'workflow_v1']);
                $children[] = $w->id;
            }
            $batch = Batch::create(['id' => $id, 'user_id' => $c->user->id, 'conversation_id' => $c->conversationId, 'request_id' => $c->requestId,
                'fingerprint'            => $fingerprint, 'credential_version' => $settings->credential_version, 'api_url' => Endpoints::api(), 'children' => $children,
                'dispatch_mode'          => 'independent', 'concurrency' => max(1, min(5, (int) config('dreamlayer.batch_concurrency', 2)))]);
            RunBatch::dispatch($id)->afterCommit();

            return $batch;
        });
    }
}
