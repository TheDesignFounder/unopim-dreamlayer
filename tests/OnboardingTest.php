<?php

use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Services\PendingConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

final class OnboardingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['dreamlayer.connections_enabled' => true]);
        $this->actingAs(Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]), 'admin');
        Settings::current()->update(['credential' => null, 'enabled' => false, 'managed_chat' => false]);
        $this->startSession();
        $this->withCredentials()->withCookie(config('session.cookie'), app('session.store')->getId());
    }

    private function device(): void
    {
        Http::fake(['*/device-authorization' => Http::response(['device_code' => 'private-device-secret', 'user_code' => 'ABCD-1234', 'verification_uri' => config('dreamlayer.platform_url').'/console/connect', 'expires_in' => 600, 'interval' => 5])]);
    }

    private function request(): Request
    {
        $request = Request::create('/');
        $request->setLaravelSession(app('session.store'));

        return $request;
    }

    private function loadDefaultPlatformConfiguration(): void
    {
        $key = 'DREAMLAYER_PLATFORM_URL';
        $environment = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);

        try {
            $defaults = require __DIR__.'/../config/dreamlayer.php';
            config(['dreamlayer.platform_url' => $defaults['platform_url'], 'dreamlayer.billing_url' => $defaults['billing_url']]);
        } finally {
            if ($environment[0] !== null) {
                $_ENV[$key] = $environment[0];
            }
            if ($environment[1] !== null) {
                $_SERVER[$key] = $environment[1];
            }
            putenv($environment[2] === false ? $key : $key.'='.$environment[2]);
        }
    }

    public function test_shipped_defaults_accept_the_production_console_url(): void
    {
        $this->loadDefaultPlatformConfiguration();
        // Keep the server response literal: deriving it from configuration hid the
        // production hostname mismatch in the original onboarding fixtures.
        Http::fake(['*/device-authorization' => Http::response([
            'device_code' => 'private-device-secret', 'user_code' => 'ABCD-1234',
            'verification_uri' => 'https://platform.dreamlayer.io/console/connect',
            'expires_in' => 600, 'interval' => 5,
        ])]);

        $this->postJson(route('dreamlayer.connect'), ['automatic' => true])->assertOk()
            ->assertJsonPath('verification_uri', 'https://platform.dreamlayer.io/console/connect')
            ->assertDontSee('private-device-secret');
        $this->assertSame('https://platform.dreamlayer.io/console/billing', config('dreamlayer.billing_url'));
    }

    public function test_production_defaults_still_reject_an_untrusted_console_origin(): void
    {
        $this->loadDefaultPlatformConfiguration();
        Http::fake(['*/device-authorization' => Http::response([
            'device_code' => 'private-device-secret', 'user_code' => 'ABCD-1234',
            'verification_uri' => 'https://platform.dreamlayer.io.attacker.example/console/connect',
            'expires_in' => 600, 'interval' => 5,
        ])]);

        $this->postJson(route('dreamlayer.connect'), ['automatic' => true])->assertStatus(502);
        $this->assertNull(Settings::current()->credential);
        $this->assertNull(PendingConnection::get($this->request()));
    }

    public function test_automatic_code_is_reused_and_chat_is_not_requested_by_default(): void
    {
        $this->device();
        $first = $this->postJson(route('dreamlayer.connect'), ['automatic' => true])->assertOk()->assertJsonPath('expires_in', 600);
        $second = $this->postJson(route('dreamlayer.connect'), ['automatic' => true])->assertOk();
        $this->assertSame($first->json('attempt_id'), $second->json('attempt_id'));
        $this->assertStringNotContainsString('private-device-secret', $first->getContent());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => ! isset($request['included_chat']) && str_ends_with($request['installation_url'], '/dreamlayer/settings'));
    }

    public function test_status_checks_code_requests_and_token_polls_have_independent_rate_limits(): void
    {
        $this->device();
        Http::fake(['*/token' => Http::response(['error' => 'authorization_pending'], 400)]);
        for ($i = 0; $i < 10; $i++) {
            $this->getJson(route('dreamlayer.settings.status'))->assertOk();
        }
        $this->getJson(route('dreamlayer.settings.status'))->assertStatus(429);
        $attempt = $this->postJson(route('dreamlayer.connect'), ['automatic' => true])->assertOk()->json('attempt_id');
        for ($i = 0; $i < 15; $i++) {
            $pending = PendingConnection::get($this->request());
            $pending['next_poll'] = 0;
            PendingConnection::put($this->request(), $pending);
            $this->postJson(route('dreamlayer.connect.poll'), ['attempt_id' => $attempt])->assertStatus(202);
        }
        $this->postJson(route('dreamlayer.connect.poll'), ['attempt_id' => $attempt])->assertStatus(429);
        $this->postJson(route('dreamlayer.connect'), ['automatic' => true])->assertOk()->assertJsonPath('attempt_id', $attempt);
    }

    public function test_reopening_an_existing_code_preserves_its_poll_deadline(): void
    {
        $this->device();
        $first = $this->postJson(route('dreamlayer.connect'), ['automatic' => true])->assertOk();
        $this->assertGreaterThan(0, $first->json('poll_after'));
        $this->assertLessThanOrEqual(5, $first->json('poll_after'));
        $pending = PendingConnection::get($this->request());
        $pending['next_poll'] = time() - 10;
        PendingConnection::put($this->request(), $pending);
        $this->postJson(route('dreamlayer.connect'), ['automatic' => true])->assertOk()
            ->assertJsonPath('attempt_id', $first->json('attempt_id'))
            ->assertJsonPath('poll_after', 0)
            ->assertJsonPath('interval', 5);
        $pending['approved'] = true;
        $pending['next_poll'] = time() + 20;
        PendingConnection::put($this->request(), $pending);
        $retry = $this->postJson(route('dreamlayer.connect'), ['automatic' => true])->assertOk()->assertJsonPath('approved', true);
        $this->assertGreaterThan(0, $retry->json('poll_after'));
        $this->assertLessThanOrEqual(20, $retry->json('poll_after'));
        Http::assertSentCount(1);
    }

    public function test_manual_regeneration_invalidates_poll_for_the_old_code(): void
    {
        $this->device();
        $first = $this->postJson(route('dreamlayer.connect'), ['automatic' => true])->assertOk();
        $second = $this->postJson(route('dreamlayer.connect'), ['automatic' => true, 'regenerate' => true])->assertOk();
        $this->assertNotSame($first->json('attempt_id'), $second->json('attempt_id'));
        $this->postJson(route('dreamlayer.connect.poll'), ['attempt_id' => $first->json('attempt_id')])->assertStatus(409);
        Http::assertSentCount(2);
    }

    public function test_approval_is_reported_before_the_credential_is_exchanged(): void
    {
        $this->device();
        Http::fake(['*/token' => Http::sequence()
            ->push(['error' => 'authorization_pending'], 400)
            ->push(['status' => 'approved'])
            ->push(['access_token' => 'private-installed-key', 'connection_id' => 'connection-1', 'scope' => 'images:execute chat:use'])]);
        $attempt = $this->postJson(route('dreamlayer.connect'), ['automatic' => true])->assertOk()->assertJsonPath('approved', false)->json('attempt_id');
        foreach (['authorization_pending', 'approved'] as $status) {
            $pending = PendingConnection::get($this->request());
            $pending['next_poll'] = 0;
            PendingConnection::put($this->request(), $pending);
            $this->postJson(route('dreamlayer.connect.poll'), ['attempt_id' => $attempt])->assertStatus(202)->assertJsonPath('status', $status);
            $this->assertNull(Settings::current()->credential);
        }
        $this->postJson(route('dreamlayer.connect'), ['automatic' => true])->assertOk()->assertJsonPath('approved', true);
        $this->postJson(route('dreamlayer.connect.poll'), ['attempt_id' => $attempt])->assertOk()->assertJsonPath('status', 'connected')->assertDontSee('private-installed-key');
        $this->assertSame('private-installed-key', Settings::current()->credential);
        $this->assertFalse((bool) Settings::current()->managed_chat);
        $this->assertNull(PendingConnection::get($this->request()));
        $polls = Http::recorded(fn ($request) => str_ends_with($request->url(), '/token'))->values();
        $this->assertTrue($polls[0][0]['approval_only']);
        $this->assertTrue($polls[1][0]['approval_only']);
        $this->assertArrayNotHasKey('approval_only', $polls[2][0]->data());
    }

    public function test_older_servers_can_still_exchange_without_approval_observation(): void
    {
        $this->device();
        Http::fake(['*/token' => Http::sequence()
            ->push(['detail' => 'Extra inputs are not permitted'], 422)
            ->push(['access_token' => 'legacy-key', 'connection_id' => 'legacy-connection', 'scope' => 'images:execute chat:use'])]);
        $attempt = $this->postJson(route('dreamlayer.connect'), ['automatic' => true])->assertOk()->json('attempt_id');
        $pending = PendingConnection::get($this->request());
        $pending['next_poll'] = 0;
        PendingConnection::put($this->request(), $pending);
        $this->postJson(route('dreamlayer.connect.poll'), ['attempt_id' => $attempt])->assertOk()->assertJsonPath('status', 'connected');
        $this->assertSame('legacy-key', Settings::current()->credential);
    }

    public function test_expired_attempt_is_replaced_but_saved_connection_is_never_replaced_automatically(): void
    {
        $this->device();
        $first = $this->postJson(route('dreamlayer.connect'), ['automatic' => true])->assertOk();
        $pending = PendingConnection::get($this->request());
        $pending['expires_at'] = time() - 1;
        PendingConnection::put($this->request(), $pending);
        $second = $this->postJson(route('dreamlayer.connect'), ['automatic' => true])->assertOk();
        $this->assertNotSame($first->json('attempt_id'), $second->json('attempt_id'));
        Settings::current()->update(['credential' => 'saved-owner-key']);
        $this->postJson(route('dreamlayer.connect'), ['automatic' => true, 'regenerate' => true])->assertStatus(409)->assertJsonPath('status', 'already_configured');
        $this->assertSame('saved-owner-key', Settings::current()->credential);
        Http::assertSentCount(2);
    }

    public function test_editor_shortcut_leaves_host_chat_configuration_unchanged(): void
    {
        $before = core()->getConfigData('general.magic_ai.agentic_pim.enabled');
        $this->post(route('dreamlayer.chat.open'))->assertRedirect(route('dreamlayer.choose'));
        $this->assertSame($before, core()->getConfigData('general.magic_ai.agentic_pim.enabled'));
        Http::assertNothingSent();
    }

    public function test_mode_selector_saves_images_only_without_disabling_image_tools(): void
    {
        Settings::current()->update(['credential' => 'saved-key', 'enabled' => true, 'managed_chat' => true]);
        $this->post(route('dreamlayer.settings.save'), ['enabled' => 1, 'managed_chat' => 0])->assertRedirect(route('dreamlayer.settings'));
        $this->assertTrue(Settings::current()->enabled);
        $this->assertFalse((bool) Settings::current()->managed_chat);
        $this->assertSame('saved-key', Settings::current()->credential);
        Http::assertNothingSent();
    }

    public function test_settings_page_renders_each_connection_state_without_exposing_the_credential(): void
    {
        Http::fake(['*/balance' => Http::response(['available' => 3, 'promotional' => 3]), '*/account' => Http::response(['account_label' => 'fixture@example.invalid']), 'https://gravatar.com/*' => Http::response('', 404)]);
        foreach ([false, true] as $connected) {
            Settings::current()->update(['credential' => $connected ? 'private-fixture-key' : null, 'enabled' => $connected, 'managed_chat' => $connected]);
            $response = $this->get(route('dreamlayer.settings'))->assertOk();
            $response->assertSee('What data is shared')->assertSee('DreamLayer image editor')->assertDontSee('private-fixture-key');
            if ($directory = getenv('ONBOARDING_EVIDENCE')) {
                file_put_contents($directory.'/unopim-'.($connected ? 'connected' : 'disconnected').'.html', $response->getContent());
            }
        }
    }
}
