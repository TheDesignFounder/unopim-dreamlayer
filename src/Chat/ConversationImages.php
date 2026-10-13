<?php

namespace DreamLayer\UnoPIM\Chat;

use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Models\Workflow;
use DreamLayer\UnoPIM\Services\CatalogImages;
use Webkul\AiAgent\Events\AgentSystemPromptBuilding;

/** Add saved facts after asynchronous jobs; chat text is not execution state. */
class ConversationImages
{
    public function handle(AgentSystemPromptBuilding $event): void
    {
        // Explicit selections are resolved and authorized by the image tool, not the model.

        $context = $event->context;
        if (! Settings::current()->enabled || ! $context->conversationId || ! $context->hasPermission('catalog.products.edit')) {
            return;
        }
        $selections = CatalogImages::selected($context);
        if ($selections) {
            $event->prompt .= "\nThe user explicitly selected ".count($selections)." catalog images in the picker. For an edit, call edit_image ONCE; server selection determines sources and exact Apply destinations. Do not invent SKUs or use the latest conversation result instead. For multiple selections, supply exactly one shared step; each image becomes a separate preview with its own Apply action.\n";
        }
        $owned = Workflow::where('user_id', $context->user->id)->where('conversation_id', $context->conversationId);
        $latest = (clone $owned)->latest('created_at')->first();
        $completed = (clone $owned)->where('status', 'completed')->latest('created_at')->first();
        $event->prompt .= "\nDreamLayer image requests run asynchronously. Saved backend state below is authoritative even when chat history omits tool calls. Never infer that an earlier request was not submitted just because its tool call is absent from history. Use edit_image for a requested change to an existing result; do not substitute a new generation. An explicit sku or attribute selects the saved product image, so use selected_result for a preview follow-up, including when specifying its Apply target. New attachments take precedence. If a source is unavailable, explain that instead of generating a replacement. Keep internal result identifiers out of the user-facing reply.\n";
        $event->prompt .= "\nFor an image edit, prefer one image_to_image step describing the final result. A background color change does not need a separate removal step. Use two steps only for explicitly requested sequential operations. After submitting, give a brief acknowledgement in at most two short sentences, without a workflow list, emoji, or directions such as above/below. When queued, say the request is queued, not that processing has started. Example: Your edit is queued. You can review the preview here before applying it to the product. The preview card reports progress; never resubmit pending work to check its status.\n";
        if ($latest) {
            $event->prompt .= 'Latest owned image request: '.$latest->id.'; status: '.$latest->status.".\n";
        }
        if ($completed) {
            $event->prompt .= 'Latest completed image in this owned conversation: selected_result='.$completed->id.". Use this result for an unqualified follow-up edit.\n";
        }
    }
}
