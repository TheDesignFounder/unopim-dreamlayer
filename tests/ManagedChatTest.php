<?php

use DreamLayer\UnoPIM\Chat\JournaledTool;
use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Services\ManagedChat;
use DreamLayer\UnoPIM\Services\ManagedSimilarity;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Webkul\AiAgent\Chat\AgentRunner;
use Webkul\AiAgent\Chat\ChatContext;
use Webkul\AiAgent\Chat\ToolRegistry;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

final class ManagedChatTest extends TestCase
{
    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->admin = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);
        $this->actingAs($this->admin, 'admin');
        Settings::current()->update(['enabled' => true, 'credential' => 'dl_test_'.str_repeat('x', 40), 'credential_version' => (string) Str::uuid(), 'managed_chat' => true]);
        request()->merge(['request_id' => (string) Str::uuid()]);
    }

    private function context(): ChatContext
    {
        $chat = app(ManagedChat::class);

        return new ChatContext(message: 'Find my product', history: [], productId: null, productSku: null, productName: null, locale: 'en_US', channel: 'default', platform: $chat->platform(), model: 'dreamlayer-chat', user: $this->admin, conversationId: 'managed-test', requestId: $chat->turn);
    }

    public function test_real_native_agent_loop_executes_catalog_tool_through_managed_provider(): void
    {
        $c = $this->context();
        $n = 0;
        Http::fake(function ($r) use (&$n) {
            $this->assertSame('https://api.dreamlayer.io/v1/chat/completions', $r->url());
            $this->assertSame('dreamlayer-chat', $r['model']);
            $this->assertNotEmpty($r->header('Idempotency-Key'));
            $this->assertSame('1', $r->header('DreamLayer-Version')[0]);
            if ($n++ === 0) {
                $message = ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'native-details-1', 'type' => 'function', 'function' => ['name' => 'get_product_details', 'arguments' => '{"sku":"missing-test-product"}']]]];
            } else {
                $this->assertStringContainsString('Product not found', json_encode($r['messages']));
                $message = ['role' => 'assistant', 'content' => 'No matching product was found.'];
            }

            return Http::response(['id' => 'chatcmpl-fixture', 'model' => 'dreamlayer-chat', 'object' => 'chat.completion', 'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $n === 1 ? 'tool_calls' : 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]]);
        });
        $runner = new AgentRunner(app(ToolRegistry::class));
        $result = $runner->run($c);
        $this->assertSame('No matching product was found.', $result['reply']);
        $this->assertSame(2, $n);
        $this->assertDatabaseHas('dreamlayer_tool_receipts', ['tool' => 'get_product_details', 'status' => 'completed']);
        $this->assertDatabaseHas('dreamlayer_chat_turns', ['request_id' => $c->requestId]);
    }

    public function test_nested_embeddings_use_managed_endpoint_and_restore_configuration(): void
    {
        $this->context();
        $old = config('ai.default_for_embeddings');
        Http::fake(function ($r) {
            $this->assertSame('https://api.dreamlayer.io/v1/embeddings', $r->url());
            $this->assertSame('dreamlayer-embeddings', $r['model']);
            $this->assertNotEmpty($r->header('Idempotency-Key'));

            return Http::response(['object' => 'list', 'model' => 'dreamlayer-embeddings', 'data' => [['index' => 0, 'embedding' => [1, 0]], ['index' => 1, 'embedding' => [1, 0]], ['index' => 2, 'embedding' => [0, 1]]], 'usage' => ['prompt_tokens' => 4, 'total_tokens' => 4]]);
        });
        $rank = app(ManagedSimilarity::class)->rank('serum-'.Str::uuid(), ['serum', 'shoe']);
        $this->assertCount(2, $rank);
        $this->assertSame(0, $rank[0]['index']);
        $this->assertSame($old, config('ai.default_for_embeddings'));
        Http::assertSentCount(1);
    }

    public function test_replayed_native_write_does_not_run_again_and_rechecks_permission(): void
    {
        $c = $this->context();
        $tool = new class implements Tool
        {
            public int $calls = 0;

            public function name(): string
            {
                return 'catalog_write';
            }

            public function description(): string
            {
                return 'fixture';
            }

            public function schema(JsonSchema $s): array
            {
                return [];
            }

            public function handle(ToolRequest $r): string
            {
                $this->calls++;

                return '{"saved":true}';
            }
        };
        $j = new JournaledTool($tool, $c, ['permission' => 'catalog.products.edit']);
        $r = new ToolRequest(['value' => 'x'], 'stable-write');
        $this->assertSame($j->handle($r), $j->handle($r));
        $this->assertSame(1, $tool->calls);
        $this->assertStringContainsString('conflict', $j->handle(new ToolRequest(['value' => 'y'], 'stable-write')));
        $c->user->role->forceFill(['permission_type' => 'custom', 'permissions' => []])->save();
        $this->assertStringContainsString('Permission denied', $j->handle($r));
        $this->assertSame(1, $tool->calls);
    }

    public function test_account_switch_rejects_previous_turn(): void
    {
        $this->context();
        Settings::current()->update(['credential_version' => (string) Str::uuid()]);
        $this->expectException(HttpException::class);
        app(ManagedChat::class)->platform();
    }

    public function test_upgrade_preserves_identity_scheme_of_existing_paid_turns(): void
    {
        $c = $this->context();
        $query = DB::table('dreamlayer_chat_turns')->where('request_id', $c->requestId);
        $this->assertSame(2, (int) $query->value('identity_version'));
        $query->update(['identity_version' => 1]);
        $chat = app(ManagedChat::class);
        $chat->platform();
        $this->assertSame('unopim-chat-'.$c->requestId.'-0', $chat->headers('{"messages":[]}')['Idempotency-Key']);
        $this->assertSame(1, (int) DB::table('dreamlayer_chat_turns')->where('request_id', $c->requestId)->value('identity_version'));
    }

    public function test_redirect_is_rejected_before_credential_forwarding(): void
    {
        $this->context();
        $chat = app(ManagedChat::class);
        $mock = new MockHandler([new Response(307, ['Location' => 'https://other.invalid/chat/completions']), new Response(200)]);
        $stack = HandlerStack::create($mock);
        $stack->push($chat->transport(...));
        $client = new Client(['handler' => $stack]);
        try {
            $client->post($chat->endpoint.'/chat/completions', ['headers' => ['Authorization' => 'Bearer '.$chat->key], 'json' => ['messages' => []]]);
            $this->fail('Redirect accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('redirects', $e->getMessage());
        }
        $this->assertSame(1, $mock->count());
    }
}
