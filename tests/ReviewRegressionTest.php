<?php

use DreamLayer\UnoPIM\Chat\Tools\EditImage;
use DreamLayer\UnoPIM\Chat\Tools\GenerateImage;
use DreamLayer\UnoPIM\Compatibility\HostSupport;
use DreamLayer\UnoPIM\Compatibility\ImageContext as ChatContext;
use DreamLayer\UnoPIM\Jobs\RunWorkflow;
use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Models\Workflow;
use DreamLayer\UnoPIM\Services\PendingConnection;
use DreamLayer\UnoPIM\Services\Workflows;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;
use Webkul\MagicAI\Models\MagicAIPlatform;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

final class ReviewRegressionTest extends TestCase
{
    private $admin;

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
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        Storage::fake('public');
        $this->admin = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);
        $this->actingAs($this->admin, 'admin');
        Settings::current()->update(['enabled' => true, 'managed_chat' => false, 'credential' => 'review-fake-key', 'credential_version' => (string) Str::uuid(), 'authorization_generation' => (string) Str::uuid()]);
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(32, 32);
        ob_start();
        imagepng($image);

        return ob_get_clean();
    }

    private function context(): ChatContext
    {
        return new ChatContext(message: 'Preview', history: [], productId: null, productSku: null, productName: null,
            locale: 'en_US', channel: 'default', platform: new MagicAIPlatform(['provider' => 'openai']),
            user: $this->admin, conversationId: 'review_conversation', requestId: (string) Str::uuid());
    }

    public function test_invalid_form_never_flashes_api_key(): void
    {
        $key = 'dl_live_review_secret_not_real';
        $this->post(route('dreamlayer.settings.save'), ['enabled' => 'invalid', 'api_key' => $key])
            ->assertSessionHasErrors('enabled')->assertSessionMissing('_old_input.api_key');
    }

    public function test_remote_revocation_allows_local_disconnect(): void
    {
        Settings::current()->update(['connection_id' => (string) Str::uuid()]);
        Http::fake(['*/connections/current' => Http::response([], 401)]);
        $this->post(route('dreamlayer.disconnect'))->assertSessionHasNoErrors();
        $this->assertNull(Settings::current()->credential);
        $this->assertFalse(Settings::current()->enabled);
    }

    public function test_partial_success_image_and_billing_are_presented(): void
    {
        $w = app(Workflows::class)->start($this->context(), ['steps' => [['operation' => 'text_to_image', 'instruction' => 'Vase']]], true);
        $remote = (string) Str::uuid();
        $w->update(['execution_path' => 'workflow_v1', 'upstream_workflow_id' => $remote]);
        Http::fake(['*/asset' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']), '*/image-workflows/*' => Http::response(['workflow_id' => $remote, 'contract_version' => 1, 'status' => 'failed',
            'steps' => [['description' => 'Change background', 'status' => 'completed', 'image' => ['download_url' => 'https://api.dreamlayer.io/v1/image-jobs/'.Str::uuid().'/asset']], ['description' => 'Remove background', 'status' => 'failed', 'image' => null]], 'image' => null])]);
        (new RunWorkflow($w->id))->handle();
        $r = $this->getJson(route('dreamlayer.workflow', $w->id));
        $r->assertOk()->assertJsonPath('phase', 'downloading');
        (new RunWorkflow($w->id))->handle();
        $r = $this->getJson(route('dreamlayer.workflow', $w->id));
        $r->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('steps.0.status', 'completed')->assertJsonPath('image_url', null)->assertJsonPath('partial_success', true);
        $this->assertStringContainsString('credits', $r->json('billing_notice'));
        $this->get($r->json('steps.0.image_url'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get($r->json('steps.0.image_url').'&download=1')->assertOk();
        $this->postJson(route('dreamlayer.apply', $w->id))->assertUnprocessable();
        $this->assertNull($w->fresh()->result_path);
    }

    public function test_generic_conversation_preserves_native_metadata_and_usage(): void
    {
        if (! HostSupport::nativeChat()) {
            $this->markTestSkipped('Native hook contract is absent on this released host.');
        }
        Settings::current()->update(['native_chat_enabled' => true]);
        // The administrator explicitly enables the host's own history for this test.
        config(['ai-agent.history.enabled' => true]);

        $r = $this->postJson(route('ai-agent.conversations.store'), ['conversation_id' => 'review_native', 'metadata' => ['provider' => 'native'],
            'messages' => [['role' => 'assistant', 'content' => 'Native result', 'tool_calls' => [['name' => 'search_products']], 'tokens_used' => 321]]]);
        $r->assertOk();
        $saved = $this->getJson(route('ai-agent.conversations.show', $r->json('id')))->assertOk();
        $this->assertSame(['provider' => 'native'], json_decode($saved->json('conversation.metadata'), true));
        $this->assertSame([['name' => 'search_products']], json_decode($saved->json('messages.0.tool_calls'), true));
        $saved->assertJsonPath('messages.0.tokens_used', 321);
    }

    public function test_stale_snapshot_preserves_saved_preview_reference(): void
    {
        if (! HostSupport::nativeChat()) {
            $this->markTestSkipped('Native hook contract is absent on this released host.');
        }
        Settings::current()->update(['native_chat_enabled' => true]);
        // The administrator explicitly enables the host's own history for this test.
        config(['ai-agent.history.enabled' => true]);

        $url = route('ai-agent.conversations.store');
        $first = ['role' => 'user', 'content' => 'Generate', 'message_id' => 'u1'];
        $second = ['role' => 'assistant', 'content' => 'Preview', 'message_id' => 'a1', 'presentation' => ['type' => 'dreamlayer.workflow', 'id' => (string) Str::uuid()]];
        $r = $this->postJson($url, ['conversation_id' => 'review_stale', 'messages' => [$first, $second]])->assertOk();
        $this->postJson($url, ['conversation_id' => 'review_stale', 'messages' => [$first]])->assertOk();
        $this->getJson(route('ai-agent.conversations.show', $r->json('id')))->assertJsonCount(2, 'messages')->assertJsonPath('messages.1.presentation', $second['presentation']);
    }

    public function test_replanned_retry_recovers_existing_preview_card(): void
    {
        if (! HostSupport::nativeChat()) {
            $this->markTestSkipped('Native hook contract is absent on this released host.');
        }
        Settings::current()->update(['native_chat_enabled' => true]);

        $context = $this->context();
        $tool = app(GenerateImage::class)->register(new Webkul\AiAgent\Chat\ChatContext(...get_object_vars($context)));
        $a = json_decode($tool->handle(new Request(['steps' => [['operation' => 'text_to_image', 'instruction' => 'Blue vase']]])), true);
        $b = json_decode($tool->handle(new Request(['steps' => [['operation' => 'text_to_image', 'instruction' => 'A blue vase']]])), true);
        $this->assertArrayHasKey('presentation', $a);
        $this->assertSame($a['presentation'], $b['presentation']);
        $this->assertSame(1, Workflow::where('user_id', $this->admin->id)->count());
        $this->getJson(route('dreamlayer.results', ['conversation_id' => $context->conversationId, 'request_id' => $context->requestId]))->assertOk()->assertJsonPath('workflows.0.presentation', $a['presentation']);
    }

    public function test_old_device_authorization_cannot_overwrite_newer_manual_credential(): void
    {
        config(['dreamlayer.connections_enabled' => true]);
        Http::fake(['*/capabilities' => Http::response(['operations' => ['text_to_image']]),
            '*/token' => Http::response(['access_token' => 'review-old-device-account', 'connection_id' => (string) Str::uuid()])]);
        $device = Crypt::encryptString(json_encode(['generation' => Settings::current()->authorization_generation, 'device_code' => 'review-secret', 'expires_at' => time() + 600, 'next_poll' => time() - 1, 'interval' => 5]));
        $this->pending(json_decode(Crypt::decryptString($device), true));
        $this->post(route('dreamlayer.settings.save'), ['enabled' => 1, 'api_key' => 'review-new-manual-account'])->assertSessionHasNoErrors();
        $this->assertSame('review-new-manual-account', Settings::current()->credential);
        $this->postJson(route('dreamlayer.connect.poll'))->assertConflict();
        $this->assertSame('review-new-manual-account', Settings::current()->credential);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/token'));
    }

    public function test_all_validation_failures_keep_secret_out_of_session_and_response(): void
    {
        foreach ([['enabled' => 'invalid', 'api_key' => 'synthetic-short-secret'], ['enabled' => 1, 'api_key' => str_repeat('secret', 100)], ['enabled' => 1, 'api_key' => ['nested-secret']]] as $input) {
            $r = $this->post(route('dreamlayer.settings.save'), $input)->assertSessionHasErrors()->assertSessionMissing('_old_input.api_key');
            $secret = is_array($input['api_key']) ? $input['api_key'][0] : $input['api_key'];
            $this->assertStringNotContainsString($secret, json_encode(session()->all()));
            $this->assertStringNotContainsString($secret, $r->getContent());
        }
    }

    public function test_disconnect_then_poll_is_superseded_and_local_forget_handles_outage(): void
    {
        config(['dreamlayer.connections_enabled' => true]);
        Settings::current()->update(['connection_id' => (string) Str::uuid()]);
        $d = Crypt::encryptString(json_encode(['generation' => Settings::current()->authorization_generation, 'device_code' => 'secret', 'expires_at' => time() + 600, 'next_poll' => time() - 1, 'interval' => 5]));
        Http::fake(['*/connections/current' => Http::response([], 503)]);
        $this->post(route('dreamlayer.disconnect'))->assertSessionHasErrors('connection');
        $this->assertNotNull(Settings::current()->credential);
        $this->post(route('dreamlayer.disconnect'), ['forget_local' => 1])->assertRedirect();
        $this->assertNull(Settings::current()->credential);
        $this->pending(json_decode(Crypt::decryptString($d), true));
        $this->postJson(route('dreamlayer.connect.poll'))->assertConflict();
    }

    public function test_second_admin_attempt_supersedes_first(): void
    {
        config(['dreamlayer.connections_enabled' => true]);
        Http::fake(['*/device-authorization' => Http::response(['device_code' => 'secret', 'user_code' => 'BCDF-2345', 'verification_uri' => config('dreamlayer.platform_url').'/console/connect', 'expires_in' => 600, 'interval' => 5])]);
        $this->postJson(route('dreamlayer.connect'))->assertOk();
        $d = $this->pending();
        $d['next_poll'] = time() - 1;
        $other = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);
        $this->actingAs($other, 'admin')->postJson(route('dreamlayer.connect'))->assertOk();
        $this->actingAs($this->admin, 'admin');
        $this->pending($d);
        $this->postJson(route('dreamlayer.connect.poll'))->assertConflict();
    }

    public function test_replacement_during_exchange_revokes_orphan_without_overwriting(): void
    {
        config(['dreamlayer.connections_enabled' => true]);
        $d = Crypt::encryptString(json_encode(['generation' => Settings::current()->authorization_generation, 'device_code' => 'secret', 'expires_at' => time() + 600, 'next_poll' => time() - 1, 'interval' => 5]));
        Http::fake(function ($r) {
            if (str_ends_with($r->url(), '/token')) {
                Settings::current()->update(['credential' => 'new-account', 'credential_version' => (string) Str::uuid(), 'authorization_generation' => (string) Str::uuid()]);

                return Http::response(['access_token' => 'orphan-token', 'connection_id' => (string) Str::uuid()]);
            }

            return Http::response([], 204);
        });
        $this->pending(json_decode(Crypt::decryptString($d), true));
        $this->postJson(route('dreamlayer.connect.poll'))->assertConflict();
        $this->assertSame('new-account', Settings::current()->credential);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/current') && $r->header('Authorization')[0] === 'Bearer orphan-token');
    }

    private function reconnectAttempt(?string $previous, ?string $connection = null): void
    {
        config(['dreamlayer.connections_enabled' => true]);
        Settings::current()->update(['credential' => $previous, 'connection_id' => $connection, 'credential_version' => (string) Str::uuid(), 'authorization_generation' => (string) Str::uuid()]);
        $this->pending(['generation' => Settings::current()->authorization_generation, 'device_code' => 'reconnect-secret', 'expires_at' => time() + 600, 'next_poll' => time() - 1, 'interval' => 5]);
    }

    private function revokedWith(string $token): bool
    {
        return Http::recorded(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/connections/current') && $r->header('Authorization')[0] === 'Bearer '.$token)->isNotEmpty();
    }

    public function test_reconnect_revokes_the_superseded_installation(): void
    {
        $this->reconnectAttempt('old-installation-token', (string) Str::uuid());
        $connection = (string) Str::uuid();
        Http::fake(['*/token' => Http::response(['access_token' => 'new-installation-token', 'connection_id' => $connection, 'scope' => 'images:use chat:use']), '*/connections/current' => Http::response([], 204)]);
        $this->postJson(route('dreamlayer.connect.poll'))->assertOk()->assertJsonPath('status', 'connected')
            ->assertJsonPath('notice', fn ($n) => str_contains($n, 'was revoked'));
        $this->assertSame('new-installation-token', Settings::current()->credential);
        $this->assertSame($connection, Settings::current()->connection_id);
        $this->assertTrue($this->revokedWith('old-installation-token'));
        $this->assertFalse($this->revokedWith('new-installation-token'));
        // A status refresh before the reload must not consume the notice; the page shows it once.
        Http::fake(['https://gravatar.com/*' => Http::response('', 404), '*/balance' => Http::response([], 503)]);
        $this->getJson(route('dreamlayer.settings.status'))->assertOk();
        $this->get(route('dreamlayer.settings'))->assertOk()->assertSee('previous DreamLayer installation connection was revoked');
        $this->get(route('dreamlayer.settings'))->assertOk()->assertDontSee('<p class="dl-notice" role="status">', false);
    }

    public function test_reconnect_keeps_new_connection_and_warns_when_old_revoke_fails(): void
    {
        $this->reconnectAttempt('old-installation-token', (string) Str::uuid());
        $connection = (string) Str::uuid();
        Http::fake(['*/token' => Http::response(['access_token' => 'new-installation-token', 'connection_id' => $connection]), '*/connections/current' => Http::response([], 503)]);
        $this->postJson(route('dreamlayer.connect.poll'))->assertOk()->assertJsonPath('status', 'connected')
            ->assertJsonPath('notice', fn ($n) => str_contains($n, 'could not be revoked') && str_contains($n, 'connection management'))
            ->assertSessionHas('dreamlayer.reconnect_notice');
        $this->assertSame('new-installation-token', Settings::current()->credential);
        $this->assertSame($connection, Settings::current()->connection_id);
        $this->assertTrue($this->revokedWith('old-installation-token'));
        Http::fake(['https://gravatar.com/*' => Http::response('', 404), '*/balance' => Http::response([], 503)]);
        $this->get(route('dreamlayer.settings'))->assertOk()->assertSee('could not be revoked; revoke it in DreamLayer connection management');
    }

    public function test_reconnect_treats_old_credential_401_as_already_revoked(): void
    {
        $this->reconnectAttempt('old-installation-token', (string) Str::uuid());
        Http::fake(['*/token' => Http::response(['access_token' => 'new-installation-token', 'connection_id' => (string) Str::uuid()]), '*/connections/current' => Http::response([], 401)]);
        $this->postJson(route('dreamlayer.connect.poll'))->assertOk()->assertJsonPath('status', 'connected')
            ->assertJsonPath('notice', fn ($n) => str_contains($n, 'already revoked'));
        $this->assertSame('new-installation-token', Settings::current()->credential);
    }

    public function test_first_connection_or_replaced_manual_key_never_revokes(): void
    {
        $this->reconnectAttempt(null);
        Http::fake(['*/token' => Http::response(['access_token' => 'new-installation-token', 'connection_id' => (string) Str::uuid()]), '*/connections/current' => Http::response([], 204)]);
        $this->postJson(route('dreamlayer.connect.poll'))->assertOk()->assertJsonPath('status', 'connected')->assertJsonMissingPath('notice');
        // A manual API key is not an installation connection; like Disconnect and the manual-key path, never revoke it.
        $this->reconnectAttempt('manual-api-key');
        $this->postJson(route('dreamlayer.connect.poll'))->assertOk()->assertJsonPath('status', 'connected')->assertJsonMissingPath('notice');
        $this->assertSame('new-installation-token', Settings::current()->credential);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/connections/current'));
    }

    public function test_superseded_reconnect_never_revokes_previous_or_current_credential(): void
    {
        $this->reconnectAttempt('old-installation-token', (string) Str::uuid());
        Http::fake(function ($r) {
            if (str_ends_with($r->url(), '/token')) {
                Settings::current()->update(['credential' => 'concurrent-installation', 'connection_id' => (string) Str::uuid(), 'credential_version' => (string) Str::uuid(), 'authorization_generation' => (string) Str::uuid()]);

                return Http::response(['access_token' => 'orphan-token', 'connection_id' => (string) Str::uuid()]);
            }

            return Http::response([], 204);
        });
        $this->postJson(route('dreamlayer.connect.poll'))->assertConflict()->assertJsonPath('status', 'superseded');
        $this->assertSame('concurrent-installation', Settings::current()->credential);
        $this->assertTrue($this->revokedWith('orphan-token'));
        $this->assertFalse($this->revokedWith('old-installation-token'));
        $this->assertFalse($this->revokedWith('concurrent-installation'));
    }

    public function test_superseded_exchange_cannot_revoke_an_already_selected_token(): void
    {
        $this->reconnectAttempt('old-installation-token', (string) Str::uuid());
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/token')) {
                $connection = (string) Str::uuid();
                Settings::current()->update(['credential' => 'already-selected-token', 'connection_id' => $connection, 'authorization_generation' => (string) Str::uuid()]);

                return Http::response(['access_token' => 'already-selected-token', 'connection_id' => $connection]);
            }

            return Http::response([], 204);
        });
        $this->postJson(route('dreamlayer.connect.poll'))->assertConflict();
        $this->assertSame('already-selected-token', Settings::current()->credential);
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }

    public function test_reconnect_never_revokes_previous_credential_restored_concurrently(): void
    {
        $this->reconnectAttempt('old-installation-token', (string) Str::uuid());
        Http::fake(['*/token' => Http::response(['access_token' => 'new-installation-token', 'connection_id' => (string) Str::uuid()]), '*/connections/current' => Http::response([], 204)]);
        $restored = false;
        // Another administrator restores the previous credential right after the swap commits.
        Settings::saved(function (Settings $s) use (&$restored) {
            if (! $restored && $s->credential === 'new-installation-token') {
                $restored = true;
                DB::afterCommit(fn () => Settings::find(1)->forceFill(['credential' => 'old-installation-token', 'credential_version' => (string) Str::uuid()])->saveQuietly());
            }
        });
        $this->postJson(route('dreamlayer.connect.poll'))->assertOk()->assertJsonPath('status', 'connected');
        $this->assertTrue($restored);
        $this->assertSame('old-installation-token', Settings::current()->credential);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/connections/current'));
    }

    public function test_manual_key_replacing_connection_revokes_previous_installation(): void
    {
        Settings::current()->update(['credential' => 'installation-token', 'connection_id' => (string) Str::uuid()]);
        Http::fake(['*/capabilities' => Http::response(['operations' => ['text_to_image']]), '*/connections/current' => Http::response([], 204)]);
        $this->post(route('dreamlayer.settings.save'), ['enabled' => '1', 'api_key' => 'manual-key'])
            ->assertRedirect(route('dreamlayer.settings'))->assertSessionHas('connection_notice', fn ($n) => str_contains($n, 'was revoked'));
        $this->assertSame('manual-key', Settings::current()->credential);
        $this->assertNull(Settings::current()->connection_id);
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/connections/current') && $r->header('Authorization')[0] === 'Bearer installation-token');
    }

    public function test_manual_key_still_saves_and_warns_when_installation_revocation_fails(): void
    {
        Settings::current()->update(['credential' => 'installation-token', 'connection_id' => (string) Str::uuid()]);
        Http::fake(['*/capabilities' => Http::response(['operations' => ['text_to_image']]), '*/connections/current' => Http::response([], 503)]);
        $this->post(route('dreamlayer.settings.save'), ['enabled' => '1', 'api_key' => 'manual-key'])
            ->assertSessionHas('connection_notice', fn ($n) => str_contains($n, 'could not be revoked'));
        $this->assertSame('manual-key', Settings::current()->credential);
    }

    public function test_manual_key_reports_installation_already_revoked_in_dreamlayer(): void
    {
        Settings::current()->update(['credential' => 'installation-token', 'connection_id' => (string) Str::uuid()]);
        Http::fake(['*/capabilities' => Http::response(['operations' => ['text_to_image']]), '*/connections/current' => Http::response([], 401)]);
        $this->post(route('dreamlayer.settings.save'), ['enabled' => '1', 'api_key' => 'manual-key'])
            ->assertSessionHas('connection_notice', fn ($n) => str_contains($n, 'already revoked'));
        $this->assertSame('manual-key', Settings::current()->credential);
        $this->assertNull(Settings::current()->connection_id);
    }

    public function test_rejected_manual_key_keeps_the_connection_and_never_revokes_it(): void
    {
        $connection = (string) Str::uuid();
        Settings::current()->update(['credential' => 'installation-token', 'connection_id' => $connection]);
        Http::fake(['*/capabilities' => Http::response([], 401), '*/connections/current' => Http::response([], 204)]);
        $this->post(route('dreamlayer.settings.save'), ['enabled' => '1', 'api_key' => 'wrong-key'])->assertSessionHasErrors('api_key');
        $this->assertSame('installation-token', Settings::current()->credential);
        $this->assertSame($connection, Settings::current()->connection_id);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/connections/current'));
    }

    public function test_manual_key_or_toggle_without_connection_never_revokes(): void
    {
        Http::fake(['*/capabilities' => Http::response(['operations' => ['text_to_image']])]);
        $this->post(route('dreamlayer.settings.save'), ['enabled' => '1', 'api_key' => 'manual-key-2'])->assertSessionHasNoErrors();
        Settings::current()->update(['connection_id' => (string) Str::uuid()]);
        $this->post(route('dreamlayer.settings.save'), ['enabled' => '0'])->assertSessionHasNoErrors();
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/connections/current'));
    }

    public function test_manual_key_never_overwrites_a_connection_changed_during_revocation(): void
    {
        Settings::current()->update(['credential' => 'installation-token', 'connection_id' => (string) Str::uuid()]);
        Http::fake(function ($r) {
            if (str_ends_with($r->url(), '/connections/current')) {
                Settings::current()->update(['credential' => 'newer-connection', 'credential_version' => (string) Str::uuid()]);

                return Http::response([], 204);
            }

            return Http::response(['operations' => ['text_to_image']]);
        });
        $this->post(route('dreamlayer.settings.save'), ['enabled' => '1', 'api_key' => 'manual-key'])->assertStatus(409);
        $this->assertSame('newer-connection', Settings::current()->credential);
    }

    public function test_validation_race_never_revokes_or_overwrites_newer_connection(): void
    {
        Settings::current()->update(['connection_id' => (string) Str::uuid()]);
        Http::fake(function ($r) {
            $this->assertStringEndsWith('/capabilities', $r->url());
            Settings::current()->update(['credential' => 'newer-connection', 'credential_version' => (string) Str::uuid(), 'authorization_generation' => (string) Str::uuid()]);

            return Http::response(['operations' => ['text_to_image']]);
        });
        $this->post(route('dreamlayer.settings.save'), ['enabled' => '1', 'api_key' => 'delayed-manual-key'])->assertConflict();
        $this->assertSame('newer-connection', Settings::current()->credential);
        Http::assertSentCount(1);
    }

    public function test_validation_race_preserves_newer_authorization_without_revoking(): void
    {
        Settings::current()->update(['connection_id' => (string) Str::uuid()]);
        $generation = (string) Str::uuid();
        Http::fake(function () use ($generation) {
            Settings::current()->update(['authorization_generation' => $generation]);

            return Http::response(['operations' => ['text_to_image']]);
        });
        $this->post(route('dreamlayer.settings.save'), ['enabled' => '1', 'api_key' => 'delayed-manual-key'])->assertConflict();
        $this->assertSame('review-fake-key', Settings::current()->credential);
        $this->assertSame($generation, Settings::current()->authorization_generation);
        Http::assertSentCount(1);
    }

    public function test_status_distinguishes_remote_rejection_from_outage_without_changing_settings(): void
    {
        $before = Settings::current()->getAttributes();
        Http::fake(['*/balance' => Http::sequence()->push([], 401)->push([], 503)->push([], 403)]);
        foreach (['unauthorized', 'unavailable', 'unauthorized'] as $state) {
            $r = $this->getJson(route('dreamlayer.settings.status'))->assertOk();
            $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
            $r->assertJsonPath('status', $state)->assertJsonPath('balances', null);
            $this->assertStringNotContainsString('review-fake-key', $r->getContent());
            $this->assertSame($before, Settings::current()->getAttributes());
        }
    }

    public function test_status_supports_manual_key_when_connections_disabled_and_returns_only_balances(): void
    {
        config(['dreamlayer.connections_enabled' => false]);
        Settings::current()->update(['connection_id' => null]);
        Http::fake(['*/balance' => Http::response(['available' => 2, 'promotional' => 2, 'internal' => 'must-not-escape']), '*/account' => Http::response([], 404)]);
        $this->getJson(route('dreamlayer.settings.status'))->assertExactJson(['status' => 'connected', 'method' => 'Manual API key', 'balances' => ['available' => 2, 'promotional' => 2], 'account_label' => null, 'chat_available' => (bool) core()->getConfigData('general.magic_ai.agentic_pim.enabled'), 'can_enable_chat' => true, 'chat_enabled' => false, 'image_tools_enabled' => true]);
        Settings::current()->update(['credential' => null]);
        $this->getJson(route('dreamlayer.settings.status'))->assertOk()->assertJsonPath('status', 'disconnected');
        Http::assertSentCount(2);
    }

    public function test_status_reports_live_image_and_chat_settings_without_mutating_credentials(): void
    {
        Http::fake(['*/balance' => Http::response(['available' => 3, 'promotional' => 0]), '*/account' => Http::response([], 404)]);
        $credential = Settings::current()->credential;
        foreach ([[true, false], [true, true], [false, true], [true, false]] as [$images, $chat]) {
            Settings::current()->update(['enabled' => $images, 'managed_chat' => $chat]);
            $this->getJson(route('dreamlayer.settings.status'))->assertOk()
                ->assertJsonPath('image_tools_enabled', $images)
                ->assertJsonPath('chat_enabled', $chat);
            $this->assertSame($credential, Settings::current()->credential);
        }
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_status_requires_settings_permission(): void
    {
        $denied = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard']])->id]);
        $this->actingAs($denied, 'admin');
        $this->getJson(route('dreamlayer.settings.status'))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_history_survives_more_than_fifty_messages_and_delayed_saves(): void
    {
        if (! HostSupport::nativeChat()) {
            $this->markTestSkipped('Native hook contract is absent on this released host.');
        }
        Settings::current()->update(['native_chat_enabled' => true]);
        // The administrator explicitly enables the host's own history for this test.
        config(['ai-agent.history.enabled' => true]);

        $messages = array_map(fn ($i) => ['message_id' => 'message_'.$i, 'role' => 'assistant', 'content' => 'Result '.$i], range(1, 100));
        $r = $this->postJson(route('ai-agent.conversations.store'), ['conversation_id' => 'long_chat', 'messages' => $messages])->assertOk();
        $this->postJson(route('ai-agent.conversations.store'), ['conversation_id' => 'long_chat', 'messages' => array_slice($messages, 50)])->assertOk();
        $this->getJson(route('ai-agent.conversations.show', $r->json('id')))->assertJsonCount(100, 'messages');
    }

    public function test_recovery_listing_images_and_apply_are_owner_scoped(): void
    {
        $w = app(Workflows::class)->start($this->context(), ['steps' => [['operation' => 'text_to_image', 'instruction' => 'Vase']]], true);
        $other = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);
        $this->actingAs($other, 'admin');
        $this->getJson(route('dreamlayer.results'))->assertJsonCount(0, 'workflows');
        $this->getJson(route('dreamlayer.image', $w->id))->assertNotFound();
        $this->postJson(route('dreamlayer.apply', $w->id))->assertNotFound();
    }

    public function test_disabling_tools_cannot_reroute_an_admitted_transport_retry(): void
    {
        if (! HostSupport::nativeChat()) {
            $this->markTestSkipped('Native hook contract is absent on this released host.');
        }
        $context = $this->context();
        $original = app(Workflows::class)->start($context, ['steps' => [['operation' => 'text_to_image', 'instruction' => 'Vase']]], true);
        Settings::current()->update(['enabled' => false]);
        foreach ([GenerateImage::class, EditImage::class] as $class) {
            $tool = app($class)->register(new Webkul\AiAgent\Chat\ChatContext(...get_object_vars($context)));
            $response = json_decode($tool->handle(new Request(['steps' => [['operation' => 'text_to_image', 'instruction' => 'Replanned vase']]])), true);
            $this->assertSame($original->id, $response['presentation']['id']);
        }
        $this->assertSame(1, Workflow::where('user_id', $this->admin->id)->count());
        Http::assertNothingSent();
    }
}
