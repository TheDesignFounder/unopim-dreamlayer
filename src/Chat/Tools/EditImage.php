<?php

namespace DreamLayer\UnoPIM\Chat\Tools;

use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Services\Batches;
use DreamLayer\UnoPIM\Services\CatalogImages;
use DreamLayer\UnoPIM\Services\Workflows;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Webkul\AiAgent\Chat\ChatContext;
use Webkul\AiAgent\Chat\Contracts\PimTool;
use Webkul\AiAgent\Chat\Tools\ContextualTool;

class EditImage implements PimTool
{
    public function register(ChatContext $context): Tool
    {
        // Disabling changes new requests only; a transport replay still owns its admitted job.
        if ((! Settings::current()->enabled || ! Settings::current()->native_chat_enabled) && (! $context->hasPermission('catalog.products.edit') || ! app(Workflows::class)->recover($context))) {
            return app(\Webkul\AiAgent\Chat\Tools\EditImage::class)->register($context);
        }

        return new class($context) extends ContextualTool
        {
            public function name(): string
            {
                return 'edit_image';
            }

            public function description(): string
            {
                return 'DreamLayer image preview only; never saves a product. Edit an existing image. Default to ONE image_to_image step describing the final result. Changing or replacing a background, including a solid color, is one image_to_image edit; do not remove the background first. Use two ordered steps only when the user explicitly requests separate sequential operations. Pure removal to transparency must use background_remove with no prompt. Never generate a replacement for an edit request. Preserve the subject unless explicitly asked to change it. New attachments override selection; otherwise use selected_result or explicit sku/attribute, then latest conversation result, then current product image. Return the preview card and wait for explicit Apply.';
            }

            public function schema(JsonSchema $s): array
            {
                return [
                    'steps' => $s->array()->items($s->object([
                        'operation' => $s->string()->enum(['image_to_image', 'background_remove'])->required(),
                        'instruction' => $s->string()->description('Executable instruction; blank for pure background removal'),
                    ])->withoutAdditionalProperties())->min(1)->max(2)->required(),
                    'sku' => $s->string(), 'attribute' => $s->string(),
                    'image_index' => $s->integer()->min(0), 'selected_result' => $s->string(),
                    'aspect_ratio' => $s->string()->enum(['1:1', '3:4', '4:3']),
                ];
            }

            public function handle(Request $request): string
            {
                try {
                    abort_unless($this->context->hasPermission('catalog.products.edit'), 403);
                    abort_unless(Settings::current()->native_chat_enabled || app(Workflows::class)->recover($this->context), 403);
                    if (($batch = app(Batches::class)->recover($this->context)) || count(CatalogImages::selected($this->context)) > 1) {
                        $batch ??= app(Batches::class)->start($this->context, $request->all());

                        return json_encode(['mutated' => false, 'presentation' => ['type' => 'dreamlayer.batch', 'id' => $batch->id], 'result' => ['status' => $batch->fresh()->status, 'message' => trans('dreamlayer::app.batch-queued')]]);
                    }
                    $workflow = app(Workflows::class)->recover($this->context) ?? app(Workflows::class)->start($this->context, $request->all(), false);
                    $workflow->refresh(); // Hydrate database defaults on newly admitted work.

                    return json_encode(['mutated' => false, 'presentation' => ['type' => 'dreamlayer.workflow', 'id' => $workflow->id],
                        'result' => ['status' => $workflow->status, 'message' => trans('dreamlayer::app.preview-status', ['status' => trans('dreamlayer::app.status-'.$workflow->status)])]]);
                } catch (ValidationException $e) {
                    return json_encode(['error' => trans('dreamlayer::app.invalid-workflow'), 'fields' => $e->errors()]);
                } catch (HttpExceptionInterface $e) {
                    return json_encode(['error' => $e->getStatusCode() === 409 ? trans('dreamlayer::app.workflow-conflict') : trans('dreamlayer::app.workflow-unavailable')]);
                }
            }
        };
    }
}
