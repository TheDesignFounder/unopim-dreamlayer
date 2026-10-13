<?php

namespace DreamLayer\UnoPIM\Chat;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Webkul\AiAgent\Chat\ChatContext;

class JournaledTool implements Tool
{
    public function __construct(private Tool $tool, private ChatContext $context, private array $metadata) {}

    public function name(): string
    {
        return ToolNameResolver::resolve($this->tool);
    }

    public function description(): string
    {
        return $this->tool->description();
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->tool->schema($schema);
    }

    public function handle(Request $request): string
    {
        // Recheck current permissions even for a replayed receipt: a role can
        // change between the original request and a transport retry.
        $this->context->user->refresh()->load('role');
        if (($this->metadata['permission'] ?? null) && ! $this->context->hasPermission($this->metadata['permission'])) {
            return json_encode(['error' => 'Permission denied.']);
        }
        if (! $request->toolCallId()) {
            return json_encode(['error' => 'A stable tool call identity is required.']);
        }
        $id = hash('sha256', json_encode([$this->context->user->id, $this->context->requestId, $request->toolCallId()]));
        $fingerprint = hash('sha256', json_encode([$this->name(), $request->all()]));
        $new = DB::table('dreamlayer_tool_receipts')->insertOrIgnore(['id' => $id, 'user_id' => $this->context->user->id, 'request_id' => $this->context->requestId, 'tool' => $this->name(), 'fingerprint' => $fingerprint, 'created_at' => now(), 'updated_at' => now()]);
        // Claim outside the execution transaction. If execution crashes, the claim
        // remains uncertain instead of allowing a retry to repeat a possible effect.
        if (! $new) {
            $saved = DB::table('dreamlayer_tool_receipts')->where('id', $id)->first();
            if (! hash_equals($saved->fingerprint, $fingerprint)) {
                return json_encode(['error' => 'Tool identity conflict. No action was repeated.']);
            }

            return $saved->status === 'completed' ? $saved->result : json_encode(['error' => 'This action needs recovery review. It was not repeated.']);
        }

        // Database effects and the completed receipt commit together. An external
        // side effect cannot be rolled back here; an incomplete claim needs review.
        return DB::transaction(function () use ($request, $id) {
            $result = (string) $this->tool->handle($request);
            DB::table('dreamlayer_tool_receipts')->where('id', $id)->update(['status' => 'completed', 'result' => $result, 'updated_at' => now()]);

            return $result;
        });
    }
}
