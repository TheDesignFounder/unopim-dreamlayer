<?php

namespace DreamLayer\UnoPIM\Http\Controllers;

use DreamLayer\UnoPIM\Compatibility\ImageContext;
use DreamLayer\UnoPIM\Http\Requests\EditorRequest;
use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Models\Workflow;
use DreamLayer\UnoPIM\Services\ApiException;
use DreamLayer\UnoPIM\Services\Batches;
use DreamLayer\UnoPIM\Services\CatalogImages;
use DreamLayer\UnoPIM\Services\Client;
use DreamLayer\UnoPIM\Services\Endpoints;
use DreamLayer\UnoPIM\Services\Workflows;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class EditorController extends Controller
{
    public function send(EditorRequest $request)
    {
        $data = $request->validated();
        $settings = Settings::current();
        abort_unless($settings->connected(), 422, trans('dreamlayer::app.connect-first'));
        abort_if(! empty($data['selected_result']) && count($data['images']), 422, trans('dreamlayer::app.choose-source'));
        $context = new ImageContext(
            message: $data['message'], history: $data['history'], productId: null, productSku: null, productName: null,
            locale: $data['locale'], channel: $data['channel'], user: auth()->guard('admin')->user(),
            conversationId: $data['conversation_id'], requestId: $data['request_id'],
            extensions: ['dreamlayer' => ['images' => $data['images']]],
        );
        // Pin the browser request before optional model calls. Store a hash,
        // never the conversation, unless history saving was explicitly enabled.
        $identity = ['user_id' => $context->user->id, 'request_id' => $data['request_id']];
        $fingerprint = hash('sha256', json_encode($data));
        $receipt = DB::transaction(function () use ($identity, $fingerprint, $settings) {
            Settings::whereKey($settings->id)->lockForUpdate()->firstOrFail();
            $row = DB::table('dreamlayer_editor_requests')->where($identity)->first();
            if ($row) {
                abort_unless(hash_equals($row->fingerprint, $fingerprint), 409, trans('dreamlayer::app.workflow-conflict'));

                return $row;
            }
            DB::table('dreamlayer_editor_requests')->insert($identity + [
                'fingerprint' => $fingerprint, 'credential_version' => $settings->credential_version,
                'api_url' => Endpoints::api(), 'assistance' => $settings->managed_chat && $settings->privacy_consent_version >= 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('dreamlayer_editor_requests')->where($identity)->first();
        });
        if ($receipt->result) {
            return response()->json(json_decode($receipt->result, true))->header('Cache-Control', 'private, no-store');
        }
        abort_unless($receipt->credential_version === $settings->credential_version && $receipt->api_url === Endpoints::api(), 409, trans('dreamlayer::app.workflow-conflict'));
        // Validate sources before sending an optional conversation upstream.
        foreach ($data['images'] as $image) {
            app(CatalogImages::class)->resolve($image['token'], $context->user->id);
        }
        if (! empty($data['selected_result'])) {
            abort_unless(Workflow::whereKey($data['selected_result'])->where('user_id', $context->user->id)
                ->where('conversation_id', $context->conversationId)->where('status', 'completed')->exists(), 422, trans('dreamlayer::app.workflow-unavailable'));
        }
        $operation = $data['operation'];
        $instruction = $operation === 'background_remove' ? '' : $data['message'];
        // The selected source is resolved by the server. Model output can only
        // refine an instruction; it cannot choose a product or apply an image.
        if ($receipt->assistance && $operation === 'image_to_image') {
            abort_unless($settings->managed_chat && $settings->privacy_consent_version >= 1, 409, trans('dreamlayer::app.workflow-conflict'));
            try {
                $instruction = $receipt->instruction ? Crypt::decryptString($receipt->instruction)
                    : (new Client($settings->credential))->refineInstruction($data['message'], $data['history'], $data['request_id'], $context->user->id);
                DB::table('dreamlayer_editor_requests')->where($identity)->update(['instruction' => Crypt::encryptString($instruction), 'updated_at' => now()]);
            } catch (ConnectionException) {
                abort(503, trans('dreamlayer::app.request-error'));
            } catch (ApiException $error) {
                abort(in_array($error->status, [401, 403, 402, 429], true) ? $error->status : 503, $error->getMessage());
            }
        }
        $input = ['steps' => [['operation' => $operation, 'instruction' => $instruction]]];
        if (! empty($data['selected_result'])) {
            $input['selected_result'] = $data['selected_result'];
        }
        if (count($data['images']) > 1) {
            abort_unless($operation !== 'text_to_image', 422, trans('dreamlayer::app.select-one-generation'));
            $batch = app(Batches::class)->start($context, $input);
            $result = ['batch_id' => $batch->id, 'workflow_ids' => $batch->children];
        } else {
            $workflow = app(Workflows::class)->start($context, $input, $operation === 'text_to_image');
            $result = ['batch_id' => null, 'workflow_ids' => [$workflow->id]];
        }
        $reply = trans('dreamlayer::app.preview-queued');
        if ($settings->save_chat_history) {
            DB::table('dreamlayer_editor_history')->updateOrInsert(
                ['user_id' => $context->user->id, 'conversation_id' => $context->conversationId, 'request_id' => $context->requestId],
                ['messages' => Crypt::encryptString(json_encode([['role' => 'user', 'content' => $data['message']], ['role' => 'assistant', 'content' => $reply]])), 'created_at' => now(), 'updated_at' => now()],
            );
        }

        $response = $result + ['reply' => $reply];
        DB::table('dreamlayer_editor_requests')->where($identity)->update(['result' => json_encode($response), 'updated_at' => now()]);

        return response()->json($response)->header('Cache-Control', 'private, no-store');
    }
}
