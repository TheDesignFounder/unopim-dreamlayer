<?php

use DreamLayer\UnoPIM\Jobs\RunWorkflow;
use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Models\Workflow;
use DreamLayer\UnoPIM\Services\CatalogImages;
use DreamLayer\UnoPIM\Services\PendingConnection;
use DreamLayer\UnoPIM\Services\ProductImages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeFamily;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

final class PortableEditorTest extends TestCase
{
    private Admin $admin;

    private Product $product;

    private array $input;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        Storage::fake('public');
        $this->admin = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);
        $this->actingAs($this->admin, 'admin');
        Settings::current()->update(['enabled' => true, 'credential' => 'local-fake-key', 'credential_version' => (string) Str::uuid(), 'managed_chat' => false, 'save_chat_history' => false, 'native_chat_enabled' => false, 'privacy_consent_version' => 0]);
        $im = imagecreatetruecolor(32, 32);
        ob_start();
        imagepng($im);
        $png = ob_get_clean();
        imagedestroy($im);
        Storage::disk('public')->put('editor-test.png', $png);
        $family = AttributeFamily::firstOrFail();
        $attribute = Attribute::firstOrCreate(['code' => 'image'], ['type' => 'image', 'value_per_locale' => 0, 'value_per_channel' => 0]);
        $group = DB::table('attribute_family_group_mappings')->where('attribute_family_id', $family->id)->first();
        DB::table('attribute_group_mappings')->updateOrInsert(['attribute_id' => $attribute->id, 'attribute_family_group_id' => $group->id], ['position' => 50]);
        $this->product = Product::create(['sku' => 'EDITOR-'.Str::random(8), 'type' => 'simple', 'status' => 1, 'attribute_family_id' => $family->id, 'values' => ['common' => ['image' => 'editor-test.png']]]);
        $this->product->forceFill(['values' => ['common' => ['image' => 'editor-test.png']]])->save();
        $target = app(ProductImages::class)->target($this->product, 'image', 0, 'default', 'en_US');
        $selection = app(CatalogImages::class)->selection($this->product, $target, $this->admin->id);
        $this->input = ['request_id' => (string) Str::uuid(), 'conversation_id' => (string) Str::uuid(), 'message' => 'Make the background blue', 'operation' => 'image_to_image', 'history' => [], 'images' => [['token' => $selection['token']]], 'locale' => 'en_US', 'channel' => 'default'];
    }

    public function test_editor_needs_no_native_chat_or_conversation_storage(): void
    {
        $r = $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertOk();
        $this->assertCount(1, $r->json('workflow_ids'));
        $this->assertSame('queued', Workflow::findOrFail($r->json('workflow_ids.0'))->status);
        $this->assertSame('editor-test.png', $this->product->fresh()->values['common']['image']);
        $this->assertSame(0, DB::table('dreamlayer_editor_history')->count());
        $this->assertFalse((bool) config('ai-agent.history.enabled'));
        Http::assertNothingSent();
    }

    private function twoReferencedImages(): Product
    {
        $other = $this->product->replicate();
        $other->sku = 'EDITOR-'.Str::random(8);
        $other->save();
        $target = app(ProductImages::class)->target($other, 'image', 0, 'default', 'en_US');
        $selection = app(CatalogImages::class)->selection($other, $target, $this->admin->id);
        $this->input['images'][0]['reference'] = 'Asset-1';
        $this->input['images'][] = ['token' => $selection['token'], 'reference' => 'Asset-2'];

        return $other;
    }

    public function test_inline_reference_targets_only_its_bound_product_and_replay_is_identical(): void
    {
        $other = $this->twoReferencedImages();
        Settings::current()->update(['save_chat_history' => true]);
        $this->input['message'] = 'Give @Asset-2 a blue background.';
        $result = $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertOk()->json();
        $this->assertNull($result['batch_id']);
        $this->assertCount(1, $result['workflow_ids']);
        $workflow = Workflow::findOrFail($result['workflow_ids'][0]);
        $this->assertSame($other->sku, $workflow->target['sku']);
        $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertOk()->assertExactJson($result);
        $saved = DB::table('dreamlayer_editor_history')->where('request_id', $this->input['request_id'])->first();
        $this->assertSame($this->input['message'], json_decode(Crypt::decryptString($saved->messages), true)[0]['content']);
        $this->assertSame(1, Workflow::where('user_id', $this->admin->id)->count());
        Http::assertNothingSent();
    }

    public function test_unbound_or_malformed_inline_references_fail_before_receipts_chat_and_jobs(): void
    {
        $this->twoReferencedImages();
        Settings::current()->update(['managed_chat' => true, 'privacy_consent_version' => 1]);
        foreach (['@Asset-3', '@Asset-', '@Asset-0', '@Asset-2wrong'] as $reference) {
            $this->input['message'] = 'Edit '.$reference;
            $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertUnprocessable();
        }
        $this->input['message'] = 'Edit @Asset-1';
        $this->input['images'][1]['reference'] = 'Asset-1';
        $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertUnprocessable();
        $this->assertSame(0, DB::table('dreamlayer_editor_requests')->where('user_id', $this->admin->id)->count());
        $this->assertSame(0, Workflow::where('user_id', $this->admin->id)->count());
        Queue::assertNotPushed(RunWorkflow::class);
        Queue::assertNotPushed(\DreamLayer\UnoPIM\Jobs\RunBatch::class);
        Http::assertNothingSent();
    }

    public function test_two_inline_references_keep_batch_order_and_identify_each_child_to_the_model(): void
    {
        $other = $this->twoReferencedImages();
        Http::fake(['*' => Http::response(['managed_execute' => true, 'operations' => ['text_to_image', 'image_to_image', 'background_remove']])]);
        $this->input['message'] = 'Use a blue background for @Asset-1 and a white one for @Asset-2.';
        $result = $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertOk()->json();
        $this->assertNotNull($result['batch_id']);
        $this->assertCount(2, $result['workflow_ids']);
        foreach ($result['workflow_ids'] as $index => $id) {
            $workflow = Workflow::findOrFail($id);
            $this->assertSame([$this->product->sku, $other->sku][$index], $workflow->target['sku']);
            $this->assertStringStartsWith('The supplied image is @Asset-'.($index + 1).'.', $workflow->steps[0]['instruction']);
            $this->assertStringContainsString($this->input['message'], $workflow->steps[0]['instruction']);
        }
        $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertOk()->assertExactJson($result);
    }

    public function test_batch_reference_context_reserves_prompt_space_without_truncation_or_dispatch(): void
    {
        $this->twoReferencedImages();
        Http::fake(['*' => Http::response(['managed_execute' => true, 'operations' => ['text_to_image', 'image_to_image', 'background_remove']])]);
        $this->input['message'] = str_pad('Edit @Asset-1 and @Asset-2. ', 4000, 'x');
        $response = $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertUnprocessable();
        $response->assertJsonValidationErrors('message');
        $this->assertStringContainsString('3928', $response->json('errors.message.0'));
        $this->assertSame(0, Workflow::where('user_id', $this->admin->id)->count());
        Queue::assertNotPushed(RunWorkflow::class);
        Queue::assertNotPushed(\DreamLayer\UnoPIM\Jobs\RunBatch::class);
    }

    public function test_retry_with_inline_references_keeps_the_authenticated_original_source(): void
    {
        $other = $this->twoReferencedImages();
        $this->input['message'] = 'Give @Asset-2 a blue background.';
        $first = $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertOk()->json();
        $original = Workflow::findOrFail($first['workflow_ids'][0]);
        Storage::disk('local')->put('original.png', Storage::disk('public')->get('editor-test.png'));
        $original->update(['status' => 'completed', 'source_path' => 'original.png']);
        $this->input['request_id'] = (string) Str::uuid();
        $this->input['images'] = [];
        $this->input['retry_result'] = $original->id;
        $retry = $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertOk()->json();
        $this->assertSame($other->sku, Workflow::findOrFail($retry['workflow_ids'][0])->target['sku']);
        Http::assertNothingSent();
    }

    public function test_batch_retry_displays_user_wording_and_keeps_source_scope_on_repeated_retries(): void
    {
        $this->twoReferencedImages();
        Http::fake(['*' => Http::response(['managed_execute' => true, 'operations' => ['text_to_image', 'image_to_image', 'background_remove']])]);
        $message = $this->input['message'] = 'Blue for @Asset-1, white for @Asset-2.';
        $batch = $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertOk()->json();
        $original = Workflow::findOrFail($batch['workflow_ids'][1]);
        $bytes = Storage::disk('local')->get($original->source_path);
        $original->update(['status' => 'failed']);
        $this->getJson(route('dreamlayer.workflow', $original->id))->assertOk()->assertJsonPath('retry.instruction', $message);
        $this->assertSame(0, DB::table('dreamlayer_editor_history')->where('user_id', $this->admin->id)->count());
        $this->input['images'] = [];
        for ($i = 0; $i < 2; $i++) {
            $this->input['request_id'] = (string) Str::uuid();
            $this->input['retry_result'] = $original->id;
            $response = $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertOk()->json();
            $next = Workflow::findOrFail($response['workflow_ids'][0]);
            $this->assertSame($bytes, Storage::disk('local')->get($next->source_path));
            $this->assertSame($original->target['product_id'], $next->target['product_id']);
            $this->assertSame('The supplied image is @Asset-2. Apply instructions for this image only. '.$message, $next->steps[0]['instruction']);
            $next->update(['status' => 'failed']);
            $this->getJson(route('dreamlayer.workflow', $next->id))->assertOk()->assertJsonPath('retry.instruction', $message);
            $original = $next;
        }
        $this->input['request_id'] = (string) Str::uuid();
        $this->input['message'] = str_pad($message, 4000, 'x');
        $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertUnprocessable();
    }

    public function test_legacy_batch_retry_uses_saved_message_and_cleans_unsaved_fallback(): void
    {
        $this->twoReferencedImages();
        Settings::current()->update(['save_chat_history' => true]);
        Http::fake(['*' => Http::response(['managed_execute' => true, 'operations' => ['text_to_image', 'image_to_image', 'background_remove']])]);
        $message = $this->input['message'] = 'Blue for @Asset-1, white for @Asset-2.';
        $batch = $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertOk()->json();
        $w = Workflow::findOrFail($batch['workflow_ids'][0]);
        $steps = $w->steps;
        unset($steps[0]['user_instruction'], $steps[0]['source_reference']);
        $clean = 'Give @Asset-1 a blue background and @Asset-2 a white background.';
        $steps[0]['instruction'] = 'The supplied image is @Asset-1. Apply instructions for this image only. '.$clean;
        $w->update(['status' => 'failed', 'steps' => $steps]);
        $this->getJson(route('dreamlayer.workflow', $w->id))->assertOk()->assertJsonPath('retry.instruction', $message);
        DB::table('dreamlayer_editor_history')->where('user_id', $this->admin->id)->delete();
        $this->getJson(route('dreamlayer.workflow', $w->id))->assertOk()->assertJsonPath('retry.instruction', $clean);
    }

    public function test_replay_is_identical_and_changed_inputs_are_rejected(): void
    {
        $first = $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertOk()->json();
        $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertOk()->assertExactJson($first);
        $changed = $this->input;
        $changed['message'] = 'Make it red';
        $this->postJson(route('dreamlayer.editor.send'), $changed)->assertConflict();
        $this->assertSame(1, Workflow::where('user_id', $this->admin->id)->count());
        Http::assertNothingSent();
    }

    public function test_history_is_explicit_encrypted_and_does_not_enable_other_providers(): void
    {
        Settings::current()->update(['save_chat_history' => true]);
        $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertOk();
        $saved = DB::table('dreamlayer_editor_history')->where('user_id', $this->admin->id)->first();
        $this->assertStringNotContainsString($this->input['message'], $saved->messages);
        $this->assertSame($this->input['message'], json_decode(Crypt::decryptString($saved->messages), true)[0]['content']);
        Settings::current()->update(['save_chat_history' => false]);
        $this->input['request_id'] = (string) Str::uuid();
        $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertOk();
        $this->assertSame(1, DB::table('dreamlayer_editor_history')->where('user_id', $this->admin->id)->count());
        $this->assertFalse((bool) config('ai-agent.history.enabled'));
        Http::assertNothingSent();
    }

    public function test_chat_assistance_requires_consent_and_does_not_repeat_on_replay(): void
    {
        $this->postJson(route('dreamlayer.settings.save'), ['enabled' => 1, 'managed_chat' => 1])->assertUnprocessable();
        $this->assertFalse(Settings::current()->managed_chat);
        Http::assertNothingSent();
        Settings::current()->update(['managed_chat' => true, 'privacy_consent_version' => 1]);
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'Give the selected image a plain blue background.']]]])]);
        $r = $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertOk();
        $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertOk()->assertExactJson($r->json());
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['model'] === 'dreamlayer-chat' && ! str_contains($r->body(), $this->product->sku));
        $this->assertSame(0, DB::table('dreamlayer_editor_history')->where('user_id', $this->admin->id)->count());
    }

    public function test_refining_one_batch_result_rejects_historical_references_in_a_rewrite(): void
    {
        $this->twoReferencedImages();
        $batchMessage = 'Use a blue background for @Asset-1 and a white background for @Asset-2.';
        $this->input['message'] = $batchMessage;
        Http::fake(['*/capabilities' => Http::response(['managed_execute' => true, 'operations' => ['text_to_image', 'image_to_image', 'background_remove']])]);
        $batch = $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertOk()->json();
        $original = Workflow::findOrFail($batch['workflow_ids'][0]);
        Storage::disk('local')->put('batch-result.png', Storage::disk('public')->get('editor-test.png'));
        $original->update(['status' => 'completed', 'result_path' => 'batch-result.png']);
        Settings::current()->update(['managed_chat' => true, 'privacy_consent_version' => 1]);
        $polluted = 'Add a soft shadow under the car for @Asset-1 (blue background) and add a soft shadow under the car for @Asset-2 (white background). Keep everything else unchanged.';
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => $polluted]]]])]);
        $this->input = array_replace($this->input, ['request_id' => (string) Str::uuid(), 'images' => [], 'selected_result' => $original->id,
            'history' => [['role' => 'user', 'content' => $batchMessage]], 'message' => 'Add a soft shadow under the car. Keep everything else unchanged.']);
        $result = $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertOk()->json();
        $this->assertCount(1, $result['workflow_ids']);
        $refine = Workflow::findOrFail($result['workflow_ids'][0]);
        $this->assertSame($this->input['message'], $refine->steps[0]['instruction']);
        $this->assertSame($this->product->sku, $refine->target['sku']);
        $this->assertSame(Storage::disk('local')->get($original->result_path), Storage::disk('local')->get($refine->source_path));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/chat/completions')
            && str_contains($request['messages'][0]['content'], 'Exactly one source image')
            && str_contains($request['messages'][0]['content'], 'Never introduce references from history')
            && array_slice($request['messages'], -1)[0]['content'] === $this->input['message']);
        // An admission interrupted on 0.5.2 may still have the bad rewrite cached.
        DB::table('dreamlayer_editor_requests')->where('request_id', $this->input['request_id'])
            ->update(['instruction' => Crypt::encryptString($polluted), 'result' => null]);
        $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertOk()->assertExactJson($result);
        $this->assertSame(3, Workflow::where('user_id', $this->admin->id)->count());
    }

    public function test_rewrite_preserves_only_current_references_and_keeps_valid_clarifications(): void
    {
        $message = 'Use blue for @Asset-1 and white for @Asset-2.';
        foreach (['Use blue for @Asset-1.', 'Use blue for @Asset-1 and white for @Asset-3.', 'Use blue and white backgrounds.', 'Use blue for @Asset-1 and white for @Asset-2wrong.'] as $bad) {
            $this->assertSame($message, \DreamLayer\UnoPIM\Services\Client::scopedInstruction($message, $bad));
        }
        $valid = 'Use a plain white background for @Asset-2 and a plain blue background for @Asset-1. Preserve the products.';
        $this->assertSame($valid, \DreamLayer\UnoPIM\Services\Client::scopedInstruction($message, $valid));
        $this->assertSame('Add a soft ground shadow. Preserve the blue background and framing.',
            \DreamLayer\UnoPIM\Services\Client::scopedInstruction('Add a shadow. Keep everything else unchanged.', 'Add a soft ground shadow. Preserve the blue background and framing.'));
    }

    public function test_account_chat_grant_is_used_by_editor_without_enabling_history(): void
    {
        config(['dreamlayer.connections_enabled' => true]);
        Settings::current()->update(['credential' => null, 'enabled' => false]);
        $this->startSession();
        $this->withCredentials()->withCookie(config('session.cookie'), app('session.store')->getId());
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/device-authorization')) {
                $this->assertTrue($request['included_chat']);

                return Http::response(['device_code' => 'fixture-private', 'user_code' => 'TEST-1234', 'verification_uri' => config('dreamlayer.platform_url').'/console/connect', 'expires_in' => 600, 'interval' => 5]);
            }
            if (str_ends_with($request->url(), '/token')) {
                return Http::response(['access_token' => 'fixture-chat-scope', 'connection_id' => 'fixture-connection', 'scope' => 'images:execute chat:use']);
            }
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer fixture-chat-scope'));
            if (str_ends_with($request->url(), '/chat/limits')) {
                return Http::response(['contract_version' => 1, 'included' => true, 'image_credits_charged' => false]);
            }
            if (str_ends_with($request->url(), '/chat/completions')) {
                return Http::response(['choices' => [['message' => ['content' => 'Use a plain blue background and keep the product unchanged.']]]]);
            }
            throw new RuntimeException('Unexpected request in onboarding/editor journey');
        });
        $attempt = $this->postJson(route('dreamlayer.connect'), ['included_chat' => true, 'privacy_consent' => true])->assertOk()->json('attempt_id');
        $request = Request::create('/');
        $request->setLaravelSession(app('session.store'));
        $pending = PendingConnection::get($request);
        $pending['next_poll'] = 0;
        $pending['approved'] = true;
        PendingConnection::put($request, $pending);
        $this->postJson(route('dreamlayer.connect.poll'), ['attempt_id' => $attempt])->assertOk();
        $first = $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertOk()->json();
        $workflow = Workflow::findOrFail($first['workflow_ids'][0]);
        $this->assertSame('Use a plain blue background and keep the product unchanged.', $workflow->steps[0]['instruction']);
        $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertOk()->assertExactJson($first);
        $this->assertTrue(Settings::current()->managed_chat);
        $this->assertFalse(Settings::current()->save_chat_history);
        $this->assertFalse(Settings::current()->native_chat_enabled);
        $this->assertSame(0, DB::table('dreamlayer_editor_history')->count());
        $this->assertSame('editor-test.png', $this->product->fresh()->values['common']['image']);
        Http::assertSentCount(4);
        Queue::assertPushed(RunWorkflow::class);
    }

    public function test_tampered_or_another_owners_selection_cannot_start_paid_work(): void
    {
        $this->input['images'][0]['token'] = 'invalid';
        $this->postJson(route('dreamlayer.editor.send'), $this->input)->assertUnprocessable();
        $this->assertSame(0, Workflow::where('user_id', $this->admin->id)->count());
        Http::assertNothingSent();
    }

    public function test_role_without_product_edit_cannot_use_editor_or_apply(): void
    {
        $other = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dreamlayer_results', 'dreamlayer_results.editor_send']])->id]);
        $this->actingAs($other, 'admin')->postJson(route('dreamlayer.editor.send'), $this->input)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_every_route_has_a_named_permission_and_settings_cache_invalidates(): void
    {
        $acl = collect(config('acl'))->pluck('route')->all();
        foreach (app('router')->getRoutes() as $route) {
            if (str_starts_with($route->getName() ?? '', 'dreamlayer.')) {
                $this->assertContains($route->getName(), $acl);
            }
        }
        $id = Settings::current()->id;
        Settings::whereKey($id)->firstOrFail()->update(['enabled' => false]);
        $this->assertFalse(Settings::current()->enabled);
        DB::enableQueryLog();
        DB::flushQueryLog();
        for ($i = 0; $i < 10; $i++) {
            Settings::current();
        }
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_catalog_attribute_lookup_does_not_grow_per_product(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $p = $this->product->replicate();
            $p->sku = 'EDITOR-MANY-'.$i;
            $p->save();
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson(route('dreamlayer.catalog'))->assertOk();
        $lookups = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'attribute_group_mappings'));
        $this->assertCount(1, $lookups);
        DB::disableQueryLog();
    }
}
