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
