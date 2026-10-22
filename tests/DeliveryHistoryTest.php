<?php

use DreamLayer\UnoPIM\Compatibility\HostSupport;
use DreamLayer\UnoPIM\Compatibility\ImageContext as ChatContext;
use DreamLayer\UnoPIM\Jobs\RunWorkflow;
use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Models\Workflow;
use DreamLayer\UnoPIM\Services\ImageStore;
use DreamLayer\UnoPIM\Services\Workflows;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use Webkul\MagicAI\Models\MagicAIPlatform;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

final class DeliveryHistoryTest extends TestCase
{
    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        Storage::fake('public');
        $this->admin = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);
        $this->actingAs($this->admin, 'admin');
        Settings::current()->update(['enabled' => true, 'credential' => 'synthetic-delivery-only', 'credential_version' => (string) Str::uuid()]);
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(32, 32);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 8, 8, 24, 24, imagecolorallocatealpha($image, 30, 80, 200, 0));
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return ob_get_clean();
    }

    private function partial(): Workflow
    {
        $source = 'ai-agent/images/'.$this->admin->id.'/'.sha1('delivery_review').'/source.png';
        Storage::disk('public')->put($source, $this->png());
        $context = new ChatContext(message: 'Preview', history: [], productId: null, productSku: null, productName: null, locale: 'en_US', channel: 'default', platform: new MagicAIPlatform(['provider' => 'openai']), user: $this->admin, conversationId: 'delivery_review', requestId: (string) Str::uuid(), uploadedImagePaths: [Storage::disk('public')->path($source)], hasNewImages: true);
        $w = app(Workflows::class)->start($context, ['steps' => [['operation' => 'image_to_image', 'instruction' => 'Pink background'], ['operation' => 'background_remove']]], false);
        $remote = (string) Str::uuid();
        $w->update(['execution_path' => 'workflow_v1', 'input_asset_id' => (string) Str::uuid(), 'upstream_workflow_id' => $remote]);
        Http::fake(['*/image-workflows/*' => fn ($request) => Http::response(['workflow_id' => basename($request->url()), 'contract_version' => 1, 'status' => 'failed', 'steps' => [
            ['description' => 'Pink background', 'status' => 'completed', 'image' => ['download_url' => 'https://api.dreamlayer.io/v1/image-jobs/'.basename($request->url()).'/asset']],
            ['description' => 'Remove background', 'status' => 'failed', 'image' => null]], 'image' => null]), '*/asset' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);

        return $w;
    }

    public function test_empty_truncated_and_missing_iend_cache_are_repaired_without_new_execution(): void
    {
        foreach (['', substr($this->png(), 0, 12), substr($this->png(), 0, -12)] as $residue) {
            $w = $this->partial();
            $path = 'dreamlayer/'.$w->id.'/step-0.png';
            Storage::disk('local')->put($path, $residue);
            (new RunWorkflow($w->id))->handle();
            (new RunWorkflow($w->id))->handle(); // Resume the committed delivery checkpoint.
            $r = $this->getJson(route('dreamlayer.workflow', $w->id))->assertOk()->assertJsonPath('partial_success', true)->assertJsonPath('delivery_unavailable', false);
            $this->assertSame($this->png(), Storage::disk('local')->get($path));
            $this->get($r->json('steps.0.image_url'))->assertOk()->assertContent($this->png());
            (new RunWorkflow($w->id))->handle();
            $this->assertSame($w->upstream_workflow_id, $w->fresh()->upstream_workflow_id);
        }
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_old_terminal_corruption_is_not_served_and_can_be_recovered(): void
    {
        $w = $this->partial();
        $path = 'dreamlayer/'.$w->id.'/step-0.png';
        Storage::disk('local')->put($path, 'broken');
        $w->update(['status' => 'failed', 'progress' => [['description' => 'Pink background', 'status' => 'completed', 'result_path' => $path], ['description' => 'Remove background', 'status' => 'failed']]]);
        $this->getJson(route('dreamlayer.workflow', $w->id))->assertJsonPath('steps.0.image_url', null)->assertJsonPath('delivery_unavailable', true);
        $this->getJson(route('dreamlayer.image', ['id' => $w->id, 'step' => 0]))->assertStatus(503);
        $this->artisan('dreamlayer:recover', ['--verify-deliveries' => true])->assertSuccessful();
        Queue::assertPushed(RunWorkflow::class, fn ($job) => $job->workflowId === $w->id);
        (new RunWorkflow($w->id))->handle();
        (new RunWorkflow($w->id))->handle();
        $this->getJson(route('dreamlayer.workflow', $w->id))->assertJsonPath('partial_success', true)->assertJsonPath('delivery_unavailable', false);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_valid_cache_is_reused_and_alpha_bytes_are_unchanged(): void
    {
        $w = $this->partial();
        $path = 'dreamlayer/'.$w->id.'/step-0.png';
        app(ImageStore::class)->put($path, $this->png());
        (new RunWorkflow($w->id))->handle();
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/asset'));
        $this->assertSame($this->png(), app(ImageStore::class)->read($path));
    }

    public function test_atomic_failure_preserves_old_file_and_never_publishes_partial_bytes(): void
    {
        $store = new class extends ImageStore
        {
            protected function writeBytes($stream, string $bytes): void
            {
                fwrite($stream, substr($bytes, 0, 12));
                throw new RuntimeException('Simulated interrupted write');
            }
        };
        $path = 'dreamlayer/atomic/result.png';
        app(ImageStore::class)->put($path, $this->png());
        foreach ([$path, 'dreamlayer/atomic/new.png'] as $target) {
            try {
                $store->put($target, $this->png());
                $this->fail('Expected interruption');
            } catch (RuntimeException $e) {
                $this->assertSame('Simulated interrupted write', $e->getMessage());
            }
        }
        $this->assertSame($this->png(), app(ImageStore::class)->read($path));
        $this->assertFalse(Storage::disk('local')->exists('dreamlayer/atomic/new.png'));
        $this->assertCount(1, Storage::disk('local')->allFiles('dreamlayer/atomic'));
    }

    public function test_failed_delivery_remains_retriable_until_verified(): void
    {
        $w = $this->partial();
        $real = app(ImageStore::class);
        app()->instance(ImageStore::class, new class extends ImageStore
        {
            public function put(string $path, string $bytes): void
            {
                throw new RuntimeException('Disk full');
            }
        });
        (new RunWorkflow($w->id))->handle();
        (new RunWorkflow($w->id))->handle(); // Exercise the failing write, after status is saved.
        $this->assertFalse($w->fresh()->terminal());
        $this->getJson(route('dreamlayer.workflow', $w->id))->assertJsonPath('phase', 'retrying_delivery')
            ->assertJsonPath('steps.0.status', 'completed')->assertJsonPath('steps.0.image_url', null);
        app()->instance(ImageStore::class, $real);
        (new RunWorkflow($w->id))->handle();
        $this->getJson(route('dreamlayer.workflow', $w->id))->assertJsonPath('partial_success', true);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    private function native(): array
    {
        return ['role' => 'assistant', 'message_id' => 'native_result', 'content' => 'Export ready. Shall I proceed?', 'result' => ['rows' => 10, 'nested' => ['literal' => '<script>no execution</script>']], 'product_url' => '/admin/catalog/products/edit/1', 'download_url' => 'https://storage.example.invalid/exports/my%20file.csv', '_confirmed' => false, 'action' => 'agent_response'];
    }

    public function test_native_display_roundtrip_and_confirmation_is_monotonic(): void
    {
        if (! HostSupport::nativeChat()) {
            $this->markTestSkipped('Native hook contract is absent on this released host.');
        }
        Settings::current()->update(['native_chat_enabled' => true]);
        // The administrator explicitly enables the host's own history for this test.
        config(['ai-agent.history.enabled' => true]);

        $native = $this->native();
        $url = route('ai-agent.conversations.store');
        $r = $this->postJson($url, ['conversation_id' => 'native_contract', 'messages' => [$native]])->assertOk();
        $id = $r->json('id');
        $native['_confirmed'] = true;
        $this->postJson($url, ['conversation_id' => 'native_contract', 'messages' => [$native]])->assertOk();
        $this->postJson($url, ['conversation_id' => 'native_contract', 'messages' => [$this->native()]])->assertOk();
        $saved = $this->getJson(route('ai-agent.conversations.show', $id))->assertOk();
        foreach (['result', 'product_url', 'download_url', '_confirmed', 'action'] as $field) {
            $saved->assertJsonPath('messages.0.'.$field, $native[$field]);
        }
        $this->actingAs(Admin::factory()->create(['role_id' => $this->admin->role_id]), 'admin')->getJson(route('ai-agent.conversations.show', $id))->assertNotFound();
    }

    public function test_pre_upgrade_native_message_can_be_enriched_without_replacing_content(): void
    {
        if (! HostSupport::nativeChat()) {
            $this->markTestSkipped('Native hook contract is absent on this released host.');
        }
        Settings::current()->update(['native_chat_enabled' => true]);
        // The administrator explicitly enables the host's own history for this test.
        config(['ai-agent.history.enabled' => true]);

        $native = $this->native();
        $old = array_intersect_key($native, array_flip(['role', 'content', 'message_id']));
        $r = $this->postJson(route('ai-agent.conversations.store'), ['conversation_id' => 'native_upgrade', 'messages' => [$old]])->assertOk();
        $this->postJson(route('ai-agent.conversations.store'), ['conversation_id' => 'native_upgrade', 'messages' => [$native]])->assertOk();
        $this->getJson(route('ai-agent.conversations.show', $r->json('id')))->assertJsonPath('messages.0.result.rows', 10);
    }

    public function test_unsafe_links_and_oversized_nested_result_are_rejected(): void
    {
        if (! HostSupport::nativeChat()) {
            $this->markTestSkipped('Native hook contract is absent on this released host.');
        }
        Settings::current()->update(['native_chat_enabled' => true]);
        // The administrator explicitly enables the host's own history for this test.
        config(['ai-agent.history.enabled' => true]);

        foreach (['javascript:alert(1)', 'data:text/html,bad', '//evil.invalid', '/%5cevil.invalid', 'https://user:pass@example.invalid/file'] as $url) {
            $native = $this->native();
            $native['download_url'] = $url;
            $this->postJson(route('ai-agent.conversations.store'), ['messages' => [$native]])->assertUnprocessable();
        }
        $native = $this->native();
        $native['result'] = ['large' => str_repeat('x', 65537)];
        $this->postJson(route('ai-agent.conversations.store'), ['messages' => [$native]])->assertUnprocessable();
        $native = $this->native();
        $native['result'] = ['__proto__' => ['admin' => true]];
        $this->postJson(route('ai-agent.conversations.store'), ['messages' => [$native]])->assertUnprocessable();
        $native = $this->native();
        $native['product_url'] = 'https://evil.invalid/product';
        $this->postJson(route('ai-agent.conversations.store'), ['messages' => [$native]])->assertUnprocessable();
    }
}
