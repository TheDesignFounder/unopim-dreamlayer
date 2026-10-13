<?php

use DreamLayer\UnoPIM\Jobs\RunBatch;
use DreamLayer\UnoPIM\Jobs\RunWorkflow;
use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Models\Workflow;
use DreamLayer\UnoPIM\Services\Batches;
use DreamLayer\UnoPIM\Services\CatalogImages;
use DreamLayer\UnoPIM\Services\ManagedChat;
use DreamLayer\UnoPIM\Services\ProductImages;
use DreamLayer\UnoPIM\Services\Workflows;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Webkul\AiAgent\Chat\ChatContext;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeFamily;
use Webkul\MagicAI\Models\MagicAIPlatform;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

final class ExperienceTest extends TestCase
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
        Settings::current()->update(['enabled' => true, 'credential' => 'dl_test_'.str_repeat('x', 40), 'credential_version' => (string) Str::uuid(), 'managed_chat' => false]);
        Http::fake(['*/capabilities' => Http::response(['api_version' => '1', 'operations' => ['text_to_image'], 'image_batches' => true, 'image_batch_versions' => [1]])]);
    }

    private function gallery(): array
    {
        $im = imagecreatetruecolor(16, 16);
        ob_start();
        imagepng($im);
        $png = ob_get_clean();
        $a = Attribute::factory()->create(['code' => 'gal_'.Str::lower(Str::random(5)), 'type' => 'gallery', 'value_per_locale' => false, 'value_per_channel' => false]);
        $f = AttributeFamily::factory()->withRequiredAttributes($a)->create();
        $p = Product::factory()->create(['attribute_family_id' => $f->id, 'values' => ['common' => ['name' => 'Blue serum', $a->code => ['one.png', 'two.png']]]]);
        Storage::disk('public')->put('one.png', $png);
        Storage::disk('public')->put('two.png', $png);
        $selected = [];
        foreach ([0, 1] as $index) {
            $t = app(ProductImages::class)->target($p, $a->code, $index, 'default', 'en_US');
            $selected[] = app(CatalogImages::class)->selection($p, $t, $this->admin->id);
        }

        return [$p, $a, $selected, $png];
    }

    private function context(array $selected): ChatContext
    {
        return new ChatContext(message: 'pink background', history: [], productId: null, productSku: null, productName: null, locale: 'en_US', channel: 'default', platform: new MagicAIPlatform(['provider' => 'openai']), user: $this->admin, conversationId: 'batch-test', requestId: (string) Str::uuid(), extensions: ['dreamlayer' => ['images' => $selected]]);
    }

    private function input(): array
    {
        return ['steps' => [['operation' => 'image_to_image', 'instruction' => 'Change background to pink, preserve product.']]];
    }

    public function test_named_references_choose_only_the_mentioned_gallery_slot(): void
    {
        [$p, $a, $selected] = $this->gallery();
        $selected[0]['reference'] = 'Asset-1';
        $selected[1]['reference'] = 'Asset-2';
        $args = get_object_vars($this->context($selected));
        $args['message'] = 'Edit @Asset-2, please';
        $context = new ChatContext(...$args);
        $this->assertSame([$selected[1]['token']], array_column(CatalogImages::selected($context), 'token'));
        $workflow = app(Workflows::class)->start($context, $this->input() + ['sku' => 'wrong-model-sku'], false);
        $this->assertSame($p->id, $workflow->target['product_id']);
        $this->assertSame(1, $workflow->target['index']);
        $this->assertSame($a->code, $workflow->target['attribute']);
    }

    public function test_product_name_mentions_keep_distinct_gallery_destinations(): void
    {
        [$p, $a, $selected] = $this->gallery();
        $selected[0] += ['reference' => 'Asset-1', 'mention' => 'Sérum [Blue] + Care'];
        $selected[1] += ['reference' => 'Asset-2', 'mention' => 'Sérum [Blue] + Care (2)'];
        $args = get_object_vars($this->context($selected));
        $args['message'] = 'Please edit @Sérum [Blue] + Care (2), preserve the bottle.';
        $context = new ChatContext(...$args);
        $this->assertSame([$selected[1]['token']], array_column(CatalogImages::selected($context), 'token'));
        $workflow = app(Workflows::class)->start($context, $this->input(), false);
        $this->assertSame($p->id, $workflow->target['product_id']);
        $this->assertSame(1, $workflow->target['index']);
    }

    public function test_server_selection_matches_shared_browser_boundary_cases(): void
    {
        [,, $images] = $this->gallery();
        Queue::fake(); // Product fixture creation may schedule completeness work.
        $cases = json_decode(file_get_contents(__DIR__.'/fixtures/mention-cases.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            $selected = $images;
            foreach ($selected as $i => &$image) {
                $image['reference'] = 'Asset-'.($i + 1);
                $image['mention'] = $case['labels'][$i];
            }
            unset($image);
            $args = get_object_vars($this->context($selected));
            $args['message'] = $case['message'];
            $this->assertSame(
                array_map(fn ($i) => $selected[$i]['token'], $case['indices']),
                array_column(CatalogImages::selected(new ChatContext(...$args)), 'token'),
                $case['name']
            );
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_unbound_reference_is_rejected_before_provider_or_queue_work(): void
    {
        $args = get_object_vars($this->context([]));
        $args['message'] = 'Edit @Asset-1';
        try {
            app(Workflows::class)->start(new ChatContext(...$args), $this->input(), false);
            $this->fail('Unbound reference accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('picker again', $e->getMessage());
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_duplicate_reference_cannot_select_two_different_sources(): void
    {
        [,, $selected] = $this->gallery();
        foreach ($selected as &$image) {
            $image['reference'] = 'Asset-1';
        }
        $args = get_object_vars($this->context($selected));
        $args['message'] = 'Edit @Asset-1';
        $this->expectException(ValidationException::class);
        CatalogImages::selected(new ChatContext(...$args));
    }

    public function test_catalog_search_returns_named_authorized_gallery_slots(): void
    {
        [$p,$a] = $this->gallery();
        $r = $this->getJson(route('dreamlayer.catalog', ['q' => $p->sku]))->assertOk();
        $r->assertJsonPath('products.0.name', 'Blue serum')->assertJsonCount(2, 'products.0.images');
        $this->get($r->json('products.0.images.1.image_url'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->getJson(route('dreamlayer.catalog', ['q' => 'does-not-exist']))->assertJsonCount(0, 'products');
    }

    public function test_selection_binds_owner_and_rejects_changed_image(): void
    {
        [$p,$a,$selections] = $this->gallery();
        $this->assertSame(1, app(CatalogImages::class)->resolve($selections[1]['token'], $this->admin->id)['index']);
        $p->forceFill(['values' => ['common' => [$a->code => ['changed.png', 'two.png']]]])->save();
        $this->expectException(HttpException::class);
        app(CatalogImages::class)->resolve($selections[1]['token'], $this->admin->id);
    }

    public function test_batch_snapshot_replay_and_two_gallery_applies(): void
    {
        [$p,$a,$selections,$png] = $this->gallery();
        $context = $this->context($selections);
        $before = $p->values;
        $batch = app(Batches::class)->start($context, $this->input());
        $again = app(Batches::class)->start($context, $this->input());
        $this->assertSame($batch->id, $again->id);
        $this->assertCount(2, $batch->children);
        Queue::assertPushed(RunBatch::class, 1);
        Queue::assertNotPushed(RunWorkflow::class);
        $this->assertSame($before, $p->fresh()->values);
        foreach ($batch->children as $index => $id) {
            $w = Workflow::findOrFail($id);
            $this->assertSame($index, $w->target['index']);
            $this->assertSame($png, Storage::disk('local')->get($w->source_path));
            $path = 'dreamlayer/'.$id.'/result.png';
            Storage::disk('local')->put($path, $png);
            $w->update(['status' => 'completed', 'result_path' => $path]);
        }
        $this->postJson(route('dreamlayer.apply', $batch->children[0]))->assertOk();
        $first = $p->fresh()->values['common'][$a->code][0];
        $this->postJson(route('dreamlayer.apply', $batch->children[1]))->assertOk();
        $current = $p->fresh()->values['common'][$a->code];
        $this->assertSame($first, $current[0]);
        $this->assertNotSame('two.png', $current[1]);
        $this->postJson(route('dreamlayer.apply', $batch->children[1]))->assertOk();
        $this->assertSame($current, $p->fresh()->values['common'][$a->code]);
        $this->getJson(route('dreamlayer.results', ['request_id' => $context->requestId]))->assertJsonPath('workflows.0.presentation.type', 'dreamlayer.batch');
    }

    public function test_batch_does_not_allow_external_gallery_changes(): void
    {
        [$p,$a,$selections,$png] = $this->gallery();
        $b = app(Batches::class)->start($this->context($selections), $this->input());
        foreach ($b->children as $id) {
            $w = Workflow::findOrFail($id);
            $path = 'dreamlayer/'.$id.'/result.png';
            Storage::disk('local')->put($path, $png);
            $w->update(['status' => 'completed', 'result_path' => $path]);
        }
        $this->postJson(route('dreamlayer.apply', $b->children[0]))->assertOk();
        $v = $p->fresh()->values;
        $v['common'][$a->code][1] = 'externally-changed.png';
        $p->forceFill(['values' => $v])->save();
        $this->postJson(route('dreamlayer.apply', $b->children[1]))->assertConflict();
    }

    public function test_single_picker_selection_overrides_model_sku_and_latest_result(): void
    {
        [$p,$a,$selected] = $this->gallery();
        $w = app(Workflows::class)->start($this->context([$selected[1]]), $this->input() + ['sku' => 'invented-sku'], false);
        $this->assertSame($p->id, $w->target['product_id']);
        $this->assertSame(1, $w->target['index']);
    }

    public function test_unattached_batch_children_never_dispatch_replacement_work(): void
    {
        [,, $selected] = $this->gallery();
        $b = app(Batches::class)->start($this->context($selected), $this->input());
        Http::fake();
        (new RunWorkflow($b->children[0]))->handle();
        Http::assertNothingSent();
    }

    public function test_results_search_matches_product_name_without_json_id_type_error(): void
    {
        [$p, $a, $selected] = $this->gallery();
        $workflow = app(Workflows::class)->start($this->context([$selected[0]]), $this->input(), false);
        $this->getJson(route('dreamlayer.results', ['q' => 'Blue serum']))->assertOk()->assertJsonPath('workflows.0.id', $workflow->id);
        $this->getJson(route('dreamlayer.results', ['q' => $p->sku]))->assertOk()->assertJsonCount(1, 'workflows');
        $this->getJson(route('dreamlayer.results', ['q' => 'absent-result']))->assertOk()->assertJsonCount(0, 'workflows');
    }

    public function test_malformed_selection_is_rejected_before_paid_work(): void
    {
        $c = $this->context([]);
        $args = get_object_vars($c);
        $args['extensions'] = ['dreamlayer' => ['images' => 'invalid']];
        $this->expectException(ValidationException::class);
        app(Workflows::class)->start(new ChatContext(...$args), $this->input(), false);
    }

    public function test_chat_headers_reuse_same_identity_when_stream_transport_changes(): void
    {
        $chat = new ManagedChat;
        $chat->turn = (string) Str::uuid();
        $a = $chat->headers(json_encode(['messages' => [['role' => 'user', 'content' => 'hello']], 'stream' => true]));
        $b = $chat->headers(json_encode(['stream' => false, 'messages' => [['role' => 'user', 'content' => 'hello']]]));
        $this->assertSame($a, $b);
    }

    public function test_chat_replay_keeps_identity_when_journal_skips_nested_model_call(): void
    {
        $first = new ManagedChat;
        $retry = new ManagedChat;
        $first->turn = $retry->turn = (string) Str::uuid();
        $outer = json_encode(['messages' => [['role' => 'user', 'content' => 'Summarize catalog']]]);
        $nested = json_encode(['messages' => [['role' => 'user', 'content' => 'Describe selected image']]]);
        $final = json_encode(['messages' => [['role' => 'tool', 'content' => 'Saved tool result']]]);
        $this->assertSame($first->headers($outer), $retry->headers($outer));
        $nestedIdentity = $first->headers($nested);
        $completedIdentity = $first->headers($final);
        $this->assertSame($completedIdentity, $retry->headers($final));
        $this->assertNotSame($nestedIdentity, $completedIdentity);
    }
}
