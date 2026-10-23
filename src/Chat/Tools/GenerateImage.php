<?php

namespace DreamLayer\UnoPIM\Chat\Tools;

use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Services\Workflows;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Webkul\AiAgent\Chat\ChatContext;
use Webkul\AiAgent\Chat\Contracts\PimTool;
use Webkul\AiAgent\Chat\Tools\ContextualTool;

class GenerateImage implements PimTool
{
    public function register(ChatContext $context): Tool
    {
        // Disabling changes new requests only; a transport replay still owns its admitted job.
        if ((! Settings::current()->enabled || ! Settings::current()->native_chat_enabled) && (! $context->hasPermission('catalog.products.edit') || ! app(Workflows::class)->recover($context))) {
            return app(\Webkul\AiAgent\Chat\Tools\GenerateImage::class)->register($context);
        }

        return new class($context) extends ContextualTool
        {
            public function name(): string
            {
                return 'generate_image';
            }

            public function description(): string
            {
                return 'DreamLayer image preview only; never saves a product. Generate a new image. Supply exactly one text_to_image step. For changes to an existing image or background removal, use edit_image instead. Specify sku/attribute only for the intended Apply destination. Return the preview card and wait for explicit Apply.';
            }

            public function schema(JsonSchema $s): array
            {
                return [
                    'steps' => $s->array()->items($s->object([
                        'operation' => $s->string()->enum(['text_to_image'])->required(),
                        'instruction' => $s->string()->description('Describe the requested new image'),
                    ])->withoutAdditionalProperties())->min(1)->max(1)->required(),
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
                    $workflow = app(Workflows::class)->recover($this->context) ?? app(Workflows::class)->start($this->context, $request->all(), true);
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
