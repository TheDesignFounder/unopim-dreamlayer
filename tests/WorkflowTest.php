<?php

use DreamLayer\UnoPIM\Chat\Tools\GenerateImage;
use DreamLayer\UnoPIM\Compatibility\HostSupport;
use DreamLayer\UnoPIM\Compatibility\ImageContext as ChatContext;
use DreamLayer\UnoPIM\Jobs\RunWorkflow;
use DreamLayer\UnoPIM\Models\Batch;
use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Models\Workflow;
use DreamLayer\UnoPIM\Services\Client;
use DreamLayer\UnoPIM\Services\PendingConnection;
use DreamLayer\UnoPIM\Services\ProductImages;
use DreamLayer\UnoPIM\Services\Workflows;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Webkul\AiAgent\Chat\ResultRendererRegistry;
use Webkul\AiAgent\Chat\ToolRegistry;
use Webkul\AiAgent\Events\AgentSystemPromptBuilding;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeFamily;
use Webkul\MagicAI\Models\MagicAIPlatform;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

final class WorkflowTest extends TestCase
{
    private Admin $admin;

    private QueueManager $realQueue;

    private function pending(?array $attempt = null): ?array
    {
        $this->startSession();
        $this->withCredentials()->withCookie(config('session.cookie'), app('session.store')->getId());
        $request = Illuminate\Http\Request::create('/');
        $request->setLaravelSession(app('session.store'));
        if ($attempt !== null) {
            PendingConnection::put($request, $attempt);
        }

        return PendingConnection::get($request);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->realQueue = Queue::getFacadeRoot();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        Storage::fake('public');
        $this->admin = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);
        $this->actingAs($this->admin, 'admin');
        Settings::current()->update(['enabled' => true, 'credential' => 'dl_test_'.str_repeat('x', 40), 'credential_version' => (string) Str::uuid()]);
    }

    private function context(?string $requestId = null, array $paths = [], bool $new = false, ?int $product = null): ChatContext
    {
        return new ChatContext(message: 'Preview', history: [], productId: $product, productSku: null, productName: null,
            locale: 'en_US', channel: 'default', platform: new MagicAIPlatform(['provider' => 'openai']),
            uploadedImagePaths: $paths, user: $this->admin, conversationId: 'test_conversation', requestId: $requestId ?? (string) Str::uuid(), hasNewImages: $new);
    }

    private function png(): string
    {
        $im = imagecreatetruecolor(32, 32);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledrectangle($im, 8, 8, 24, 24, imagecolorallocatealpha($im, 20, 40, 200, 0));
        ob_start();
        imagepng($im);

        return ob_get_clean();
    }

    private function generate(?ChatContext $c = null): Workflow
    {
        return app(Workflows::class)->start($c ?? $this->context(), ['steps' => [['operation' => 'text_to_image', 'instruction' => 'Blue vase']]], true);
    }

    public function test_new_tool_acknowledgement_reports_the_saved_queued_status(): void
    {
        if (! HostSupport::nativeChat()) {
            $this->markTestSkipped('Native hook contract is absent on this released host.');
        }
        Settings::current()->update(['native_chat_enabled' => true]);

        $tool = app(GenerateImage::class)->register(new Webkul\AiAgent\Chat\ChatContext(...get_object_vars($this->context())));
        $result = json_decode($tool->handle(new Request(['steps' => [['operation' => 'text_to_image', 'instruction' => 'A white bowl']]])), true);
        $this->assertSame('queued', $result['result']['status']);
        $this->assertStringContainsString('Preview status: Queued', $result['result']['message']);
    }

    public function test_status_distinguishes_a_delay_without_changing_or_resubmitting_work(): void
    {
        $this->admin->update(['use_gravatar' => false]);
        $w = $this->generate();
        $this->getJson(route('dreamlayer.workflow', $w->id))->assertOk()
            ->assertJsonPath('status', 'queued')->assertJsonPath('delayed', false);
        $this->get(route('dreamlayer.results'))->assertOk()->assertSee('· Queued');
        $this->travel(121)->seconds();
        $this->getJson(route('dreamlayer.workflow', $w->id))->assertOk()
            ->assertJsonPath('status', 'queued')->assertJsonPath('delayed', true);
        $this->get(route('dreamlayer.results'))->assertOk()->assertSee('· '.trans('dreamlayer::app.delayed'));
        $w->update(['status' => 'running']);
        $this->getJson(route('dreamlayer.workflow', $w->id))->assertOk()
            ->assertJsonPath('status', 'running')->assertJsonPath('delayed', false);
        $this->travel(121)->seconds();
        $this->getJson(route('dreamlayer.workflow', $w->id))->assertOk()->assertJsonPath('delayed', true);
        $w->update(['status' => 'failed']);
        $this->travel(121)->seconds();
        $this->getJson(route('dreamlayer.workflow', $w->id))->assertOk()->assertJsonPath('delayed', false);
        Queue::assertPushed(RunWorkflow::class, 1);
        Http::assertNothingSent();
    }

    public function test_unchanged_successful_polls_keep_long_running_work_live_on_both_api_paths(): void
    {
        foreach (['execute_v1', 'workflow_v1'] as $path) {
            $w = $this->generate();
            $upstream = (string) Str::uuid();
            $progress = [['description' => 'Generating image', 'status' => 'running']];
            $w->update(['execution_path' => $path, 'status' => 'running',
                'upstream_execution_id' => $path === 'execute_v1' ? $upstream : null,
                'upstream_workflow_id' => $path === 'workflow_v1' ? $upstream : null,
                'progress' => $path === 'workflow_v1' ? $progress : null]);
            Http::fake([Client::BASE_URL.($path === 'execute_v1' ? '/executions/' : '/image-workflows/').$upstream => Http::response(['contract_version' => 1, 'workflow_id' => $upstream,
                'execution_id' => $upstream, 'status' => 'running', 'steps' => $progress])]);
            // Each identical poll used to leave updated_at unchanged. After two
            // minutes the UI called healthy work delayed and recovery scanned it.
            for ($i = 0; $i < 3; $i++) {
                $this->travel(121)->seconds();
                $this->assertTrue($w->fresh()->delayed());
                (new RunWorkflow($w->id))->handle();
                $this->assertFalse($w->fresh()->delayed(), $path);
                $this->assertSame('running', $w->fresh()->status);
            }
            Http::assertSentCount(3);
        }
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_recovery_refuses_a_synchronous_connection(): void
    {
        config(['dreamlayer.queue_connection' => 'sync']);
        $this->artisan('dreamlayer:recover')->expectsOutputToContain(trans('dreamlayer::app.async-queue-required'))->assertFailed();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_recovery_preserves_existing_queue_jobs_and_rebuilds_only_missing_jobs(): void
    {
        config(['queue.default' => 'redis']); // Only the extension's connection must be database-backed.
        $w = $this->generate(); // Fake admission; exercise recovery against the real test database queue.
        $w->update(['updated_at' => now()->subMinutes(3)]);
        Queue::swap($this->realQueue);
        $jobs = fn () => DB::table('jobs')->where('queue', config('dreamlayer.queue'))
            ->where('payload', 'like', '%'.$w->id.'%');
        for ($i = 0; $i < 3; $i++) {
            $this->artisan('dreamlayer:recover')->assertSuccessful();
        }
        $this->assertSame(1, $jobs()->count(), 'One delivery even when the worker is stopped');
        $original = $jobs()->value('id');
        foreach ([time(), time() - 300] as $reservedAt) {
            $jobs()->update(['reserved_at' => $reservedAt]);
            $this->artisan('dreamlayer:recover')->assertSuccessful();
            $this->assertSame([$original], $jobs()->pluck('id')->all(), 'Reserved jobs, even expired ones, are already recoverable');
        }
        $jobs()->delete();
        $this->artisan('dreamlayer:recover')->assertSuccessful();
        $this->assertSame(1, $jobs()->count(), 'A genuinely missing queue delivery is recovered');
        $this->assertSame('queued', $w->fresh()->status);
        $this->assertNull($w->fresh()->upstream_workflow_id);
        Http::assertNothingSent();
    }

    public function test_recovery_waits_for_batch_admission_before_dispatching_a_child(): void
    {
        $w = $this->generate();
        $batch = Batch::create([
            'id' => (string) Str::uuid(), 'user_id' => $this->admin->id,
            'conversation_id' => $w->conversation_id, 'request_id' => (string) Str::uuid(),
            'fingerprint' => str_repeat('a', 64), 'credential_version' => $w->credential_version,
            'api_url' => $w->api_url, 'children' => [$w->id], 'updated_at' => now()->subMinutes(3),
        ]);
        $w->update(['batch_id' => $batch->id, 'updated_at' => now()->subMinutes(3)]);
        Queue::swap($this->realQueue);
        $jobs = fn ($id) => DB::table('jobs')->where('queue', config('dreamlayer.queue'))
            ->where('payload', 'like', '%'.$id.'%')->count();
        $this->artisan('dreamlayer:recover')->assertSuccessful();
        $this->artisan('dreamlayer:recover')->assertSuccessful();
        $this->assertSame(1, $jobs($batch->id));
        $this->assertSame(0, $jobs($w->id));
        $w->update(['upstream_workflow_id' => (string) Str::uuid()]);
        $this->travel(181)->seconds();
        $this->artisan('dreamlayer:recover')->assertSuccessful();
        $this->assertSame(1, $jobs($w->id));
        Http::assertNothingSent();
    }

    public function test_saved_result_context_is_authoritative_and_scoped(): void
    {
        if (! HostSupport::nativeChat()) {
            $this->markTestSkipped('Native hook contract is absent on this released host.');
        }
        Settings::current()->update(['native_chat_enabled' => true]);

        $w = $this->generate();
        $w->update(['status' => 'completed']);
        $event = new AgentSystemPromptBuilding('Base', new Webkul\AiAgent\Chat\ChatContext(...get_object_vars($this->context())));
        event($event);
        $this->assertStringContainsString('selected_result='.$w->id, $event->prompt);
        $this->assertStringContainsString('Use edit_image', $event->prompt);
        $w->update(['conversation_id' => 'another_conversation']);
        $event = new AgentSystemPromptBuilding('Base', new Webkul\AiAgent\Chat\ChatContext(...get_object_vars($this->context())));
        event($event);
        $this->assertStringNotContainsString($w->id, $event->prompt);
        Settings::current()->update(['enabled' => false]);
        $event = new AgentSystemPromptBuilding('Base', new Webkul\AiAgent\Chat\ChatContext(...get_object_vars($this->context())));
        event($event);
        $this->assertSame('Base', $event->prompt);
    }

    public function test_followup_inherits_product_target_with_a_fresh_snapshot(): void
    {
        $attribute = Attribute::factory()->create(['type' => 'image', 'code' => 'followup_image', 'value_per_locale' => false, 'value_per_channel' => false]);
        $family = AttributeFamily::factory()->withRequiredAttributes($attribute)->create();
        $product = Product::factory()->create(['attribute_family_id' => $family->id, 'values' => ['common' => ['followup_image' => 'original.png']]]);
        $prior = $this->generate($this->context(product: $product->id));
        Storage::disk('local')->put('finished.png', $this->png());
        $prior->update(['status' => 'completed', 'result_path' => 'finished.png']);
        $oldVersion = $prior->target['version'];
        $product->forceFill(['values' => ['common' => ['followup_image' => 'changed.png']]])->save();
        $followup = app(Workflows::class)->start($this->context(), ['selected_result' => $prior->id, 'steps' => [['operation' => 'image_to_image', 'instruction' => 'Make the background pink']]], false);
        $this->assertSame($product->id, $followup->target['product_id']);
        $this->assertSame($attribute->code, $followup->target['attribute']);
        $this->assertNotSame($oldVersion, $followup->target['version']);
        $this->assertSame($this->png(), Storage::disk('local')->get($followup->source_path));
    }

    public function test_endpoint_change_pauses_the_same_job_without_submitting(): void
    {
        $w = $this->generate();
        $this->assertSame(Client::BASE_URL, $w->api_url);
        config(['dreamlayer.api_url' => 'https://staging.dreamlayer.example/v1']);
        (new RunWorkflow($w->id))->handle();
        $w->refresh();
        $this->assertNull($w->upstream_execution_id);
        $this->assertNull($w->upstream_workflow_id);
        $this->assertStringContainsString('endpoint changed', $w->error);
        Http::assertNothingSent();
    }

    public function test_followup_keeps_its_destination_after_navigating_to_another_product(): void
    {
        $attribute = Attribute::factory()->create(['type' => 'image', 'code' => 'followup_target', 'value_per_locale' => false, 'value_per_channel' => false]);
        $family = AttributeFamily::factory()->withRequiredAttributes($attribute)->create();
        $original = Product::factory()->create(['attribute_family_id' => $family->id, 'values' => ['common' => [$attribute->code => 'original.png']]]);
        $other = Product::factory()->create(['attribute_family_id' => $family->id, 'values' => ['common' => [$attribute->code => 'other.png']]]);
        $prior = $this->generate($this->context(product: $original->id));
        Storage::disk('local')->put('followup.png', $this->png());
        $prior->update(['status' => 'completed', 'result_path' => 'followup.png']);
        foreach ([[], ['selected_result' => $prior->id]] as $selection) {
            $followup = app(Workflows::class)->start($this->context(product: $other->id), $selection + ['steps' => [['operation' => 'image_to_image', 'instruction' => 'Pink background']]], false);
            $this->assertSame($original->id, $followup->target['product_id']);
            $this->assertSame($this->png(), Storage::disk('local')->get($followup->source_path));
        }
        // An explicit destination remains authoritative.
        $redirected = app(Workflows::class)->start($this->context(product: $other->id), ['sku' => $other->sku, 'selected_result' => $prior->id, 'steps' => [['operation' => 'image_to_image', 'instruction' => 'Pink background']]], false);
        $this->assertSame($other->id, $redirected->target['product_id']);
    }

    public function test_credentials_are_encrypted_and_hidden(): void
    {
        $s = Settings::current();
        $this->assertStringStartsWith('dl_test_', $s->credential);
        $this->assertStringNotContainsString('dl_test_', DB::table('dreamlayer_settings')->value('credential'));
        $this->assertArrayNotHasKey('credential', $s->toArray());
    }

    public function test_followup_honors_an_explicit_gallery_slot_on_its_inherited_destination(): void
    {
        $attribute = Attribute::factory()->create(['type' => 'gallery', 'code' => 'followup_gallery', 'value_per_locale' => false, 'value_per_channel' => false]);
        $family = AttributeFamily::factory()->withRequiredAttributes($attribute)->create();
        $product = Product::factory()->create(['attribute_family_id' => $family->id, 'values' => ['common' => [$attribute->code => ['first.png', 'second.png']]]]);
        $prior = app(Workflows::class)->start($this->context(product: $product->id), ['attribute' => $attribute->code, 'image_index' => 0, 'steps' => [['operation' => 'text_to_image', 'instruction' => 'A vase']]], true);
        Storage::disk('local')->put('gallery-followup.png', $this->png());
        $prior->update(['status' => 'completed', 'result_path' => 'gallery-followup.png']);
        $followup = app(Workflows::class)->start($this->context(product: $product->id), ['selected_result' => $prior->id, 'image_index' => 1, 'steps' => [['operation' => 'image_to_image', 'instruction' => 'Pink background']]], false);
        $this->assertSame($product->id, $followup->target['product_id']);
        $this->assertSame(1, $followup->target['index']);
        $this->assertSame($this->png(), Storage::disk('local')->get($followup->source_path));
    }

    public function test_disabled_tools_delegate_and_names_are_unique(): void
    {
        if (! HostSupport::nativeChat()) {
            $this->markTestSkipped('Native hook contract is absent on this released host.');
        }
        Settings::current()->update(['native_chat_enabled' => true]);

        Settings::current()->update(['enabled' => false]);
        $tools = app(ToolRegistry::class)->build(new Webkul\AiAgent\Chat\ChatContext(...get_object_vars($this->context())));
        $names = array_map(fn ($t) => $t->name(), $tools);
        $this->assertSame(1, count(array_filter($names, fn ($n) => $n === 'generate_image')));
        $tool = array_values(array_filter($tools, fn ($t) => $t->name() === 'edit_image'))[0];
        $this->assertStringContainsString('saves the edited image back', (string) $tool->description());
    }

    public function test_admission_idempotency_and_conflict(): void
    {
        $c = $this->context();
        $w = $this->generate($c);
        $this->assertSame($w->id, $this->generate($c)->id);
        $this->assertSame(1, Workflow::where('user_id', $this->admin->id)->count());
        try {
            app(Workflows::class)->start($c, ['steps' => [['operation' => 'text_to_image', 'instruction' => 'Other']]], true);
            $this->fail('conflict required');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_restart_and_completed_private_preview(): void
    {
        $w = $this->generate();
        $id = (string) Str::uuid();
        $bytes = $this->png();
        Http::fake(['*/capabilities' => Http::response(['operations' => ['text_to_image'], 'image_workflows' => true, 'image_workflow_versions' => [1]]), '*/image-workflows' => Http::response(['workflow_id' => $id], 202), '*/image-workflows/*' => Http::response(['workflow_id' => $id, 'status' => 'completed', 'steps' => [['description' => 'Actual API instruction', 'status' => 'completed']], 'image' => ['download_url' => 'https://api.dreamlayer.io/v1/image-jobs/'.$id.'/asset']]), '*/asset' => Http::response($bytes, 200, ['Content-Type' => 'image/png'])]);
        (new RunWorkflow($w->id))->handle();
        (new RunWorkflow($w->id))->handle();
        $this->assertSame($id, $w->fresh()->upstream_workflow_id);
        (new RunWorkflow($w->id))->handle();
        (new RunWorkflow($w->id))->handle();
        $this->assertSame('completed', $w->fresh()->status);
        Http::assertSentCount(4);
        $this->getJson(route('dreamlayer.workflow', $w->id))->assertOk()->assertJsonPath('steps.0.label', 'Actual API instruction')->assertJsonMissingPath('steps.0.execution_id');
        $this->get(route('dreamlayer.image', $w->id))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertNull($w->fresh()->applied_at);
    }

    public function test_two_edits_are_submitted_as_one_workflow_and_api_owns_progress(): void
    {
        $path = 'ai-agent/images/'.$this->admin->id.'/'.sha1('test_conversation').'/new.png';
        Storage::disk('public')->put($path, $this->png());
        $w = app(Workflows::class)->start($this->context(paths: [Storage::disk('public')->path($path)], new: true), ['steps' => [['operation' => 'image_to_image', 'instruction' => 'Pink background'], ['operation' => 'background_remove']]], false);
        Storage::disk('public')->delete($path);
        $id = (string) Str::uuid();
        $asset = (string) Str::uuid();
        Http::fake(['*/capabilities' => Http::response(['operations' => ['text_to_image'], 'image_workflows' => true, 'image_workflow_versions' => [1]]), '*/input-assets' => Http::response(['input_asset_id' => $asset], 201), '*/image-workflows' => Http::response(['workflow_id' => $id], 202), '*/image-workflows/*' => Http::response(['workflow_id' => $id, 'status' => 'running', 'steps' => [['description' => 'API first step', 'status' => 'completed'], ['description' => 'API second step', 'status' => 'running']]])]);
        for ($i = 0; $i < 4; $i++) {
            (new RunWorkflow($w->id))->handle();
        }
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/image-workflows') && count($r['steps']) === 2 && $r['input_asset_id'] === $asset);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'background-removals') || str_ends_with($r->url(), '/execute'));
        $this->getJson(route('dreamlayer.workflow', $w->id))->assertOk()->assertJsonPath('steps.1.label', 'API second step')->assertJsonPath('steps.1.status', 'running');
    }

    public function test_api_failure_never_starts_local_replacement_work(): void
    {
        $w = $this->generate();
        $id = (string) Str::uuid();
        $w->update(['upstream_workflow_id' => $id]);
        Http::fake(['*/image-workflows/*' => Http::response(['workflow_id' => $id, 'status' => 'failed', 'steps' => [['description' => 'Actual step', 'status' => 'failed']]])]);
        (new RunWorkflow($w->id))->handle();
        (new RunWorkflow($w->id))->handle();
        $this->assertSame('failed', $w->fresh()->status);
        Http::assertSentCount(1);
        $this->getJson(route('dreamlayer.workflow', $w->id))->assertOk()->assertJsonPath('billing_url', null);
    }

    public static function creditFailurePaths(): array
    {
        return [['workflow_v1'], ['execute_v1'], ['admission']];
    }

    #[DataProvider('creditFailurePaths')]
    public function test_credit_failure_keeps_its_reason_and_billing_link_without_resubmitting(string $path): void
    {
        config(['dreamlayer.billing_url' => 'https://dreamlayer.example/console/billing']);
        $w = $this->generate();
        $id = (string) Str::uuid();
        if ($path === 'execute_v1') {
            $w->update(['execution_path' => $path, 'upstream_execution_id' => $id]);
            Http::fake(['*/executions/*' => Http::response(['status' => 'failed', 'error' => ['reason' => 'insufficient_credits', 'message' => 'Untrusted upstream text']])]);
        } else {
            $w->update(['execution_path' => 'workflow_v1', 'upstream_workflow_id' => $path === 'admission' ? null : $id]);
            Http::fake(['*/image-workflows*' => $path === 'admission' ? Http::response([], 402) : Http::response([
                'workflow_id' => $id, 'status' => 'failed', 'error_code' => 402, 'error' => 'Untrusted upstream text',
                'steps' => [['description' => 'Requested edit', 'status' => 'failed']],
            ])]);
        }
        (new RunWorkflow($w->id))->handle();
        (new RunWorkflow($w->id))->handle();
        $this->getJson(route('dreamlayer.workflow', $w->id))->assertOk()
            ->assertJsonPath('status', 'failed')->assertJsonPath('error_code', 402)
            ->assertJsonPath('error', "You're out of image credits. Add credits to continue.")
            ->assertJsonPath('billing_url', 'https://dreamlayer.example/console/billing');
        Http::assertSentCount(1);
        $this->assertNull($w->fresh()->applied_at);
    }

    public function test_workflow_owner_and_permission_are_enforced(): void
    {
        $w = $this->generate();
        $this->actingAs(Admin::factory()->create(['role_id' => $this->admin->role_id]), 'admin');
        $this->getJson(route('dreamlayer.workflow', $w->id))->assertNotFound();
        $this->postJson(route('dreamlayer.apply', $w->id))->assertNotFound();
        $limited = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard']])->id]);
        $this->actingAs($limited, 'admin');
        $this->getJson(route('dreamlayer.workflow', $w->id))->assertForbidden();
        $this->getJson(route('dreamlayer.settings'))->assertForbidden();
    }

    public function test_followup_uses_latest_owned_result_not_old_attachment(): void
    {
        $old = $this->generate();
        Storage::disk('local')->put('dreamlayer/prior.png', $this->png());
        $old->update(['status' => 'completed', 'result_path' => 'dreamlayer/prior.png']);
        $w = app(Workflows::class)->start($this->context(paths: ['/expired/attachment.png']), ['steps' => [['operation' => 'background_remove']]], false);
        $this->assertSame($this->png(), Storage::disk('local')->get($w->source_path));
    }

    private function productPreview(bool $scoped = false): array
    {
        $a = Attribute::factory()->create(['code' => 'photo_'.Str::lower(Str::random(6)), 'type' => 'image', 'value_per_locale' => $scoped, 'value_per_channel' => $scoped]);
        $family = AttributeFamily::factory()->withRequiredAttributes($a)->create();
        $values = $scoped ? ['channel_locale_specific' => ['default' => ['en_US' => [$a->code => 'original.png']]]] : ['common' => [$a->code => 'original.png', 'description' => 'Before']];
        $product = Product::factory()->create(['attribute_family_id' => $family->id, 'values' => $values]);
        $w = $this->generate($this->context(product: $product->id));
        Storage::disk('local')->put('dreamlayer/'.$w->id.'/0.png', $this->png());
        $w->update(['status' => 'completed', 'result_path' => 'dreamlayer/'.$w->id.'/0.png']);

        return [$product, $a, $w];
    }

    public function test_apply_is_explicit_scoped_and_idempotent(): void
    {
        [$p,$a,$w] = $this->productPreview(true);
        $this->assertSame('original.png', $p->fresh()->values['channel_locale_specific']['default']['en_US'][$a->code]);
        app(ProductImages::class)->apply($w->id, $this->admin->id);
        $saved = $p->fresh()->values['channel_locale_specific']['default']['en_US'][$a->code];
        $this->assertNotSame('original.png', $saved);
        app(ProductImages::class)->apply($w->id, $this->admin->id);
        $this->assertSame($saved, $p->fresh()->values['channel_locale_specific']['default']['en_US'][$a->code]);
    }

    public function test_apply_preserves_unrelated_edits_and_rejects_changed_target(): void
    {
        [$p,$a,$w] = $this->productPreview();
        $values = $p->values;
        $values['common']['description'] = 'Concurrent change';
        $p->forceFill(['values' => $values])->save();
        app(ProductImages::class)->apply($w->id, $this->admin->id);
        $this->assertSame('Concurrent change', $p->fresh()->values['common']['description']);
        [$p,$a,$w] = $this->productPreview();
        $values = $p->values;
        $values['common'][$a->code] = 'changed.png';
        $p->forceFill(['values' => $values])->save();
        $this->postJson(route('dreamlayer.apply', $w->id))->assertConflict();
        $this->assertNull($w->fresh()->applied_at);
    }

    public function test_presentation_history_is_bounded_and_restored(): void
    {
        if (! HostSupport::nativeChat()) {
            $this->markTestSkipped('Native hook contract is absent on this released host.');
        }
        Settings::current()->update(['native_chat_enabled' => true]);
        // The administrator explicitly enables the host's own history for this test.
        config(['ai-agent.history.enabled' => true]);

        $descriptor = ['type' => 'dreamlayer.workflow', 'id' => (string) Str::uuid()];
        $r = $this->postJson(route('ai-agent.conversations.store'), ['messages' => [['role' => 'assistant', 'content' => 'Preview', 'message_id' => 'message_1', 'presentation' => $descriptor]]]);
        $r->assertOk();
        $this->getJson(route('ai-agent.conversations.show', $r->json('id')))->assertOk()->assertJsonPath('messages.0.presentation', $descriptor)->assertJsonPath('messages.0.message_id', 'message_1');
        $this->postJson(route('ai-agent.conversations.store'), ['messages' => [['role' => 'assistant', 'content' => 'x', 'presentation' => ['type' => 'x', 'id' => 'x', 'template' => 'evil']]]])->assertUnprocessable();
    }

    public function test_settings_and_widget_compile_with_no_dreamlayer_backend(): void
    {
        if (! HostSupport::nativeChat()) {
            $this->markTestSkipped('Native hook contract is absent on this released host.');
        }
        Settings::current()->update(['native_chat_enabled' => true]);

        Http::fake(['https://gravatar.com/*' => Http::response('', 404), '*/balance' => Http::response(['available' => 3, 'promotional' => 3, 'purchased' => 0])]);
        $this->get(route('dreamlayer.settings'))->assertOk()->assertDontSee('dl_test_')->assertDontSee('Paid removals');
        $html = view('ai-agent::components.chat-widget')->render().view()->yieldPushContent('scripts');
        $this->assertSame('v-dreamlayer-workflow', app(ResultRendererRegistry::class)->components()['dreamlayer.workflow']);
    }

    public function test_device_connection_exchanges_only_on_server_and_encrypts_credential(): void
    {
        config(['dreamlayer.connections_enabled' => true]);
        $secret = 'dl_live_inst_'.str_repeat('q', 40);
        Http::fake(['*/device-authorization' => Http::response(['device_code' => 'private-device-secret', 'user_code' => 'BCDF-2345', 'verification_uri' => config('dreamlayer.platform_url').'/console/connect', 'expires_in' => 600, 'interval' => 5]), '*/token' => Http::response(['access_token' => $secret, 'connection_id' => (string) Str::uuid()])]);
        $this->postJson(route('dreamlayer.connect'))->assertOk()->assertJsonMissingPath('device_code');
        $d = $this->pending();
        $d['next_poll'] = time() - 1;
        $this->pending($d);
        $this->postJson(route('dreamlayer.connect.poll'))->assertOk()->assertJson(['status' => 'connected'])->assertDontSee($secret);
        $this->assertSame($secret, Settings::current()->credential);
        $this->assertStringNotContainsString($secret, DB::table('dreamlayer_settings')->value('credential'));
    }

    public function test_stale_session_response_cannot_replace_pending_connection(): void
    {
        config(['dreamlayer.connections_enabled' => true]);
        Http::fake(['*/device-authorization' => Http::response(['device_code' => 'new-private-device', 'user_code' => 'BCDF-2345', 'verification_uri' => config('dreamlayer.platform_url').'/console/connect', 'expires_in' => 600, 'interval' => 5]), '*/token' => Http::response(['access_token' => 'new-installation-key', 'connection_id' => (string) Str::uuid()])]);
        $this->postJson(route('dreamlayer.connect'))->assertOk()->assertSessionMissing('dreamlayer.device');
        $d = $this->pending();
        $this->assertSame('new-private-device', $d['device_code']);
        $d['next_poll'] = time() - 1;
        $this->pending($d);
        // A late request writes the old session payload after the new connect.
        $this->withSession(['dreamlayer.device' => Crypt::encryptString(json_encode(['generation' => 'old-attempt', 'device_code' => 'old-private-device']))]);
        $this->postJson(route('dreamlayer.connect.poll'))->assertOk()->assertJson(['status' => 'connected'])->assertSessionMissing('dreamlayer.device');
        $this->assertSame('new-installation-key', Settings::current()->credential);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/token') && $r['device_code'] === 'new-private-device');
        $this->assertNull($this->pending());
    }

    public function test_manual_key_remains_usable_without_workflows_or_account_connection(): void
    {
        config(['dreamlayer.connections_enabled' => false]);
        Http::fake(['*/capabilities' => Http::response(['operations' => ['text_to_image']])]);
        $this->post(route('dreamlayer.settings.save'), ['api_key' => 'unverified-new-key', 'enabled' => 1])->assertSessionHasNoErrors();
        $this->assertSame('unverified-new-key', Settings::current()->credential);
        $this->postJson(route('dreamlayer.connect'))->assertNotFound();
    }

    public function test_private_png_delivery_preserves_alpha_and_strips_storage_credentials(): void
    {
        $bytes = $this->png();
        Http::fake(['https://api.dreamlayer.io/v1/image-jobs/fixture/asset' => Http::response('', 302, ['Location' => 'https://storage.googleapis.com/fixture/image.png']), 'https://storage.googleapis.com/*' => Http::response($bytes)]);
        $image = (new Client('private-test-key'))->image('https://api.dreamlayer.io/v1/image-jobs/fixture/asset');
        $this->assertSame($bytes, $image);
        $decoded = imagecreatefromstring($image);
        $this->assertSame(127, (imagecolorat($decoded, 0, 0) >> 24) & 0x7F);
        $this->assertSame(0, (imagecolorat($decoded, 16, 16) >> 24) & 0x7F);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://storage.googleapis.com/') && ! $r->hasHeader('Authorization'));
    }

    public function test_existing_api_generation_and_edit_use_one_authoritative_execution(): void
    {
        config(['dreamlayer.connections_enabled' => false]);
        $id = (string) Str::uuid();
        $asset = (string) Str::uuid();
        $bytes = $this->png();
        Http::fake(['*/capabilities' => Http::response(['api_version' => '1', 'operations' => ['text_to_image', 'image_to_image', 'background_remove']]), '*/input-assets' => Http::response(['input_asset_id' => $asset]), '*/execute' => Http::response(['execution_id' => $id], 202), '*/executions/*' => Http::response(['execution_id' => $id, 'status' => 'completed', 'image_job' => ['finished_assets' => [['download_url' => 'https://api.dreamlayer.io/v1/image-jobs/'.$id.'/asset']]]]), '*/asset' => Http::response($bytes)]);
        $w = $this->generate();
        for ($i = 0; $i < 4; $i++) {
            (new RunWorkflow($w->id))->handle();
        }
        $this->assertSame('completed', $w->fresh()->status);
        $this->getJson(route('dreamlayer.workflow', $w->id))->assertJsonPath('detailed_progress', false)->assertJsonPath('steps', []);
        $edit = app(Workflows::class)->start($this->context(), ['steps' => [['operation' => 'background_remove']]], false);
        for ($i = 0; $i < 5; $i++) {
            (new RunWorkflow($edit->id))->handle();
        }
        $this->assertSame('completed', $edit->fresh()->status);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/execute') && $r['operation'] === 'background_remove' && $r['input_asset_id'] === $asset);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'image-workflows') || str_contains($r->url(), 'connections') || str_contains($r->url(), 'background-removals'));
    }

    public function test_lost_execution_ack_never_switches_to_new_workflow_path(): void
    {
        $calls = [];
        $id = (string) Str::uuid();
        $w = $this->generate();
        $w->update(['execution_path' => 'execute_v1']);
        Http::fake(function ($r) use (&$calls, $id) {
            if (str_ends_with($r->url(), '/execute')) {
                $calls[] = $r->header('Idempotency-Key')[0];
                if (count($calls) === 1) {
                    throw new ConnectionException('lost acknowledgement');
                }

                return Http::response(['execution_id' => $id], 202);
            }
            throw new RuntimeException('Execution path changed');
        });
        (new RunWorkflow($w->id))->handle();
        (new RunWorkflow($w->id))->handle();
        $this->assertSame([$calls[0], $calls[0]], $calls);
        $this->assertSame($id, $w->fresh()->upstream_execution_id);
        $this->assertSame('execute_v1', $w->fresh()->execution_path);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'image-workflows') || str_contains($r->url(), 'capabilities'));
    }

    public function test_lost_workflow_ack_never_switches_to_existing_execution_path(): void
    {
        $calls = [];
        $id = (string) Str::uuid();
        $w = $this->generate();
        $w->update(['execution_path' => 'workflow_v1']);
        Http::fake(function ($r) use (&$calls, $id) {
            if (str_ends_with($r->url(), '/image-workflows')) {
                $calls[] = $r->header('Idempotency-Key')[0];
                if (count($calls) === 1) {
                    throw new ConnectionException('lost acknowledgement');
                }

                return Http::response(['workflow_id' => $id], 202);
            }
            throw new RuntimeException('Execution path changed');
        });
        (new RunWorkflow($w->id))->handle();
        (new RunWorkflow($w->id))->handle();
        $this->assertSame([$calls[0], $calls[0]], $calls);
        $this->assertSame($id, $w->fresh()->upstream_workflow_id);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/execute') || str_contains($r->url(), 'capabilities'));
    }

    public function test_unknown_workflow_version_uses_existing_api_before_admission(): void
    {
        Http::fake(['*/capabilities' => Http::response(['api_version' => '1', 'operations' => ['text_to_image'], 'image_workflows' => true, 'image_workflow_versions' => [2]])]);
        $this->assertSame('execute_v1', (new Client('test'))->executionPath());
        $this->assertFalse(Schema::hasTable('external_removal_ledger'));
    }

    public function test_manual_key_survives_unavailable_onboarding(): void
    {
        config(['dreamlayer.connections_enabled' => true]);
        Http::fake(['*/device-authorization' => Http::response([], 503), '*/capabilities' => Http::response(['api_version' => '1', 'operations' => ['text_to_image']])]);
        $this->postJson(route('dreamlayer.connect'))->assertStatus(502);
        $this->post(route('dreamlayer.settings.save'), ['api_key' => 'working-manual-key', 'enabled' => 1])->assertSessionHasNoErrors();
        $this->assertSame('working-manual-key', Settings::current()->credential);
        $this->assertTrue(Settings::current()->connected());
    }

    public function test_existing_api_rejects_sequential_plan_before_paid_submission(): void
    {
        $w = $this->generate();
        $w->update(['execution_path' => 'execute_v1', 'steps' => [['operation' => 'image_to_image', 'instruction' => 'Pink'], ['operation' => 'background_remove', 'instruction' => '']]]);
        (new RunWorkflow($w->id))->handle();
        $this->assertSame('failed', $w->fresh()->status);
        $this->assertStringContainsString('Sequential edits require', $w->fresh()->error);
        Http::assertNothingSent();
    }

    public function test_enabled_without_credentials_does_not_silently_use_native_image_saving(): void
    {
        if (! HostSupport::nativeChat()) {
            $this->markTestSkipped('Native hook contract is absent on this released host.');
        }
        Settings::current()->update(['native_chat_enabled' => true]);

        Settings::current()->update(['enabled' => true, 'credential' => null]);
        $tool = app(GenerateImage::class)->register(new Webkul\AiAgent\Chat\ChatContext(...get_object_vars($this->context())));
        $reply = json_decode($tool->handle(new Request(['steps' => [['operation' => 'text_to_image', 'instruction' => 'Blue vase']]])), true);
        $this->assertArrayHasKey('error', $reply);
        $this->assertSame(0, Workflow::where('user_id', $this->admin->id)->count());
        Http::assertNothingSent();
    }

    public function test_gallery_replace_append_and_stale_value_conflict(): void
    {
        foreach ([0, 2] as $index) {
            $a = Attribute::factory()->create(['code' => 'gallery_'.Str::lower(Str::random(6)), 'type' => 'gallery', 'value_per_locale' => false, 'value_per_channel' => false]);
            $family = AttributeFamily::factory()->withRequiredAttributes($a)->create();
            $p = Product::factory()->create(['attribute_family_id' => $family->id, 'values' => ['common' => [$a->code => ['one.png', 'two.png']]]]);
            $w = app(Workflows::class)->start($this->context(product: $p->id), ['steps' => [['operation' => 'text_to_image', 'instruction' => 'Vase']], 'attribute' => $a->code, 'image_index' => $index], true);
            $path = 'dreamlayer/'.$w->id.'/result.png';
            Storage::disk('local')->put($path, $this->png());
            $w->update(['status' => 'completed', 'result_path' => $path]);
            $this->postJson(route('dreamlayer.apply', $w->id))->assertOk();
            $values = $p->fresh()->values['common'][$a->code];
            $this->assertCount($index === 2 ? 3 : 2, $values);
            $this->assertSame('two.png', $values[1]);
            $this->assertNotSame('one.png', $values[$index]);
            $this->postJson(route('dreamlayer.apply', $w->id))->assertOk();
            $this->assertSame($values, $p->fresh()->values['common'][$a->code]);
            $stale = app(Workflows::class)->start($this->context(product: $p->id), ['steps' => [['operation' => 'text_to_image', 'instruction' => 'New vase']], 'attribute' => $a->code], true);
            $stale->update(['status' => 'completed', 'result_path' => $path]);
            $p->forceFill(['values' => ['common' => [$a->code => ['changed.png']]]])->save();
            $this->postJson(route('dreamlayer.apply', $stale->id))->assertConflict();
        }
    }

    public function test_channel_only_and_locale_only_apply(): void
    {
        foreach (['channel_specific', 'locale_specific'] as $scope) {
            $a = Attribute::factory()->create(['code' => 'photo_'.Str::lower(Str::random(6)), 'type' => 'image', 'value_per_locale' => $scope === 'locale_specific', 'value_per_channel' => $scope === 'channel_specific']);
            $key = $scope === 'locale_specific' ? 'en_US' : 'default';
            $family = AttributeFamily::factory()->withRequiredAttributes($a)->create();
            $p = Product::factory()->create(['attribute_family_id' => $family->id, 'values' => [$scope => [$key => [$a->code => 'original.png'], 'unrelated' => [$a->code => 'other.png']]]]);
            $w = $this->generate($this->context(product: $p->id));
            $path = 'dreamlayer/'.$w->id.'/result.png';
            Storage::disk('local')->put($path, $this->png());
            $w->update(['status' => 'completed', 'result_path' => $path]);
            $this->postJson(route('dreamlayer.apply', $w->id))->assertOk();
            $values = $p->fresh()->values[$scope];
            $this->assertNotSame('original.png', $values[$key][$a->code]);
            $this->assertSame('other.png', $values['unrelated'][$a->code]);
        }
    }
}
