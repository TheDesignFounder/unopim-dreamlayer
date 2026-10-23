<?php

namespace DreamLayer\UnoPIM\Http\Controllers;

use DreamLayer\UnoPIM\Compatibility\HostSupport;
use DreamLayer\UnoPIM\Http\Requests\ConnectionRequest as Request;
use DreamLayer\UnoPIM\Http\Requests\SettingsRequest;
use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Services\ApiException;
use DreamLayer\UnoPIM\Services\Client;
use DreamLayer\UnoPIM\Services\Endpoints;
use DreamLayer\UnoPIM\Services\PendingConnection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SettingsController extends Controller
{
    private function authorizeSettings(): void
    {
        abort_unless(bouncer()->hasPermission('dreamlayer'), 403);
    }

    public function show(Request $request)
    {
        $this->authorizeSettings();
        if ($request->session()->has('dreamlayer.reconnect_notice')) {
            $request->session()->now('connection_notice', $request->session()->pull('dreamlayer.reconnect_notice'));
        }
        $connectionNotice = $request->session()->get('connection_notice');
        $settings = Settings::current();
        $connectionState = $this->connectionState($settings);

        return view('dreamlayer::settings', compact('settings', 'connectionState', 'connectionNotice'));
    }

    public function status()
    {
        $this->authorizeSettings();

        return response()->json($this->connectionState(Settings::current()))->header('Cache-Control', 'no-store');
    }

    private function connectionState(Settings $settings): array
    {
        $state = ['status' => 'disconnected', 'method' => $settings->connection_id ? trans('dreamlayer::app.message-e5bd2426af') : trans('dreamlayer::app.message-a7cca24c92'), 'balances' => null, 'account_label' => null, 'chat_available' => HostSupport::nativeChat() && (bool) core()->getConfigData('general.magic_ai.agentic_pim.enabled'), 'can_enable_chat' => filled($settings->credential), 'chat_enabled' => (bool) $settings->managed_chat, 'image_tools_enabled' => (bool) $settings->enabled];
        if ($settings->credential) {
            try {
                $balances = (new Client($settings->credential))->balances();
                $state['status'] = 'connected';
                $state['balances'] = array_intersect_key($balances, array_flip(['available', 'promotional']));
                try {
                    $profile = (new Client($settings->credential))->account();
                    $state['account_label'] = is_string($profile['account_label'] ?? null) ? $profile['account_label'] : null;
                } catch (\Throwable) { /* Existing API remains usable without a profile endpoint. */
                }
            } catch (\Throwable $e) {
                $state['status'] = $e instanceof ApiException && in_array($e->status, [401, 403], true) ? 'unauthorized' : 'unavailable';
            }
        }

        return $state;
    }

    public function openChat()
    {
        $this->authorizeSettings();

        return redirect()->route('dreamlayer.choose');
    }

    public function save(SettingsRequest $request)
    {
        $data = $request->validated();
        $data['native_chat_enabled'] = HostSupport::nativeChat() && ($data['native_chat_enabled'] ?? false);
        // Snapshot before network I/O; validation can outlive a newer administrator's save.
        $before = clone Settings::current();
        if (! empty($data['api_key'])) {
            try {
                (new Client($data['api_key']))->capabilities();
            } catch (\Throwable $e) {
                return back()->withErrors(['api_key' => trans('dreamlayer::app.message-d7c757e518')]);
            }
        }
        if (! empty($data['managed_chat'])) {
            try {
                (new Client(($data['api_key'] ?? null) ?: $before->credential))->chatLimits();
            } catch (\Throwable) {
                return back()->withErrors(['managed_chat' => trans('dreamlayer::app.message-b0786bce19')]);
            }
        }
        $current = Settings::current()->fresh();
        abort_unless($current->credential_version === $before->credential_version && $current->authorization_generation === $before->authorization_generation, 409, trans('dreamlayer::app.message-146e13e071'));
        // A manual key supersedes an account connection. Revoke that installation first, as
        // Disconnect does, so its credential is not left live in DreamLayer with nothing holding it.
        $supersedes = ! empty($data['api_key']) && $before->connection_id && $before->credential && $before->credential !== $data['api_key'];
        $notice = null;
        if ($supersedes) {
            try {
                (new Client($before->credential))->revoke();
                $notice = trans('dreamlayer::app.message-2527e97a3a');
            } catch (\Throwable $e) {
                $notice = $e instanceof ApiException && $e->status === 401
                    ? trans('dreamlayer::app.message-129295121c')
                    : trans('dreamlayer::app.message-f7dd97650c');
            }
        }
        DB::transaction(function () use ($data, $before) {
            $s = Settings::whereKey($before->id)->lockForUpdate()->firstOrFail();
            // Never overwrite a credential that changed while the previous one was being revoked.
            abort_unless($s->credential_version === $before->credential_version && $s->authorization_generation === $before->authorization_generation, 409, trans('dreamlayer::app.message-146e13e071'));
            $s->authorization_generation = (string) Str::uuid();
            $s->enabled = (bool) ($data['enabled'] ?? false);
            $s->managed_chat = (bool) ($data['managed_chat'] ?? false);
            $s->save_chat_history = (bool) ($data['save_chat_history'] ?? false);
            $s->native_chat_enabled = (bool) ($data['native_chat_enabled'] ?? false);
            $s->privacy_consent_version = ! empty($data['privacy_consent']) ? 1 : 0;
            if (! empty($data['api_key'])) {
                $s->credential = $data['api_key'];
                $s->credential_version = (string) Str::uuid();
                $s->connection_id = null;
            }
            $s->save();
        });
        $redirect = redirect()->route('dreamlayer.settings');

        return $notice ? $redirect->with('connection_notice', $notice) : $redirect;
    }

    private function deviceRequest(string $route, array $payload)
    {
        return Http::acceptJson()->withHeaders(['DreamLayer-Version' => '1'])->connectTimeout(10)->timeout(20)->withoutRedirecting()->post(Endpoints::api().'/external-api/connections/'.$route, $payload);
    }

    public function connect(Request $request)
    {
        return PendingConnection::locked($request, fn () => $this->startConnection($request));
    }

    private function startConnection(Request $request)
    {
        $this->authorizeSettings();
        abort_unless(config('dreamlayer.connections_enabled'), 404);
        $current = Settings::current();
        $includedChat = $request->boolean('included_chat');
        if ($request->boolean('automatic') && $current->credential) {
            return response()->json(['status' => 'already_configured'], 409);
        }
        $pending = PendingConnection::get($request);
        if (! $request->boolean('regenerate') && $pending && (bool) ($pending['included_chat'] ?? false) === $includedChat && ($pending['generation'] ?? null) === $current->authorization_generation && ($pending['expires_at'] ?? 0) > time() && isset($pending['user_code'], $pending['verification_uri'])) {
            return response()->json($this->publicAttempt($pending))->header('Cache-Control', 'no-store');
        }
        $s = DB::transaction(function () {
            $s = Settings::whereKey(Settings::current()->id)->lockForUpdate()->firstOrFail();
            $s->update(['authorization_generation' => (string) Str::uuid()]);

            return $s;
        });
        $payload = ['installation_id' => $s->installation_id, 'installation_name' => config('app.name').' — UnoPim', 'installation_url' => rtrim(config('app.url'), '/').'/'.trim(config('app.admin_url'), '/').'/dreamlayer/settings'];
        if ($includedChat) {
            $payload['included_chat'] = true;
        }
        $response = $this->deviceRequest('device-authorization', $payload);
        if ($response->status() === 422 && isset($payload['included_chat'])) {
            // An image-only credential cannot fulfil an explicit chat choice.
            PendingConnection::forget($request);
            abort(422, trans('dreamlayer::app.message-b0786bce19'));
        }
        abort_unless($response->successful(), 502, trans('dreamlayer::app.message-f1bff2ebd3'));
        $d = $response->json();
        abort_unless(is_string($d['device_code'] ?? null) && preg_match('/^[A-Z0-9-]{6,12}$/', $d['user_code'] ?? '') && ($d['verification_uri'] ?? '') === Endpoints::platform().'/console/connect', 502);
        $pending = ['generation' => $s->authorization_generation, 'included_chat' => $includedChat, 'device_code' => $d['device_code'], 'user_code' => $d['user_code'], 'verification_uri' => $d['verification_uri'], 'expires_at' => time() + max(1, min(600, (int) $d['expires_in'])), 'interval' => max(5, (int) $d['interval']), 'next_poll' => time() + max(5, (int) $d['interval'])];
        PendingConnection::put($request, $pending);

        return response()->json($this->publicAttempt($pending))->header('Cache-Control', 'no-store');
    }

    private function publicAttempt(array $pending): array
    {
        // The user_code is safe to display. The device_code stays in encrypted
        // server-side pending state because it can exchange approval for a credential.
        // Reopening the settings page must not restart the poll interval. A code
        // that is already due can be checked immediately in the returning tab.
        return ['attempt_id' => $pending['generation'], 'included_chat' => (bool) ($pending['included_chat'] ?? false), 'user_code' => $pending['user_code'], 'verification_uri' => $pending['verification_uri'], 'interval' => $pending['interval'], 'poll_after' => max(0, $pending['next_poll'] - time()), 'expires_in' => max(0, $pending['expires_at'] - time()), 'approved' => (bool) ($pending['approved'] ?? false)];
    }

    public function poll(Request $request)
    {
        return PendingConnection::locked($request, fn () => $this->pollConnection($request));
    }

    private function pollConnection(Request $request)
    {
        $this->authorizeSettings();
        abort_unless(config('dreamlayer.connections_enabled'), 404);
        $d = PendingConnection::get($request);
        abort_unless($d, 410);
        abort_if($request->filled('attempt_id') && $request->input('attempt_id') !== ($d['generation'] ?? null), 409, trans('dreamlayer::app.message-6e00e6d289'));
        abort_unless(isset($d['generation']) && $d['generation'] === Settings::current()->authorization_generation, 409, trans('dreamlayer::app.message-28edabe8d8'));
        abort_if(time() >= $d['expires_at'], 410, trans('dreamlayer::app.message-605498238d'));
        abort_if(time() < $d['next_poll'], 429);
        $d['next_poll'] = time() + $d['interval'];
        PendingConnection::put($request, $d);
        $payload = ['device_code' => $d['device_code'], 'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code'];
        if (empty($d['approved']) && ($d['approval_status_supported'] ?? true)) {
            $payload['approval_only'] = true;
        }
        $response = $this->deviceRequest('token', $payload);
        if ($response->status() === 422 && isset($payload['approval_only'])) {
            // Older DreamLayer servers exchange in one step. Validation did not
            // consume the poll, so retry without the optional approval observation.
            $d['approval_status_supported'] = false;
            PendingConnection::put($request, $d);
            unset($payload['approval_only']);
            $response = $this->deviceRequest('token', $payload);
        }
        $data = $response->json();
        if (! $response->successful()) {
            $error = $data['error'] ?? 'connection_unavailable';
            if (! in_array($error, ['slow_down', 'authorization_pending', 'access_denied', 'expired_token'], true)) {
                $error = 'connection_unavailable';
            }
            if ($error === 'slow_down') {
                $d['interval'] += 5;
                $d['next_poll'] = time() + $d['interval'];
                PendingConnection::put($request, $d);
            }
            if (in_array($error, ['access_denied', 'expired_token'], true)) {
                PendingConnection::forget($request);
            }

            return response()->json(['status' => $error, 'interval' => $d['interval']], $error === 'authorization_pending' ? 202 : 400);
        }
        if (($data['status'] ?? null) === 'approved' && isset($payload['approval_only'])) {
            $d['approved'] = true;
            $d['next_poll'] = time();
            PendingConnection::put($request, $d);

            return response()->json(['status' => 'approved', 'interval' => 0], 202)->header('Cache-Control', 'no-store');
        }
        abort_unless(is_string($data['access_token'] ?? null), 502);
        $chatEnabled = false;
        if (! empty($d['included_chat']) && is_string($data['scope'] ?? null) && in_array('chat:use', preg_split('/\s+/', trim($data['scope'])), true)) {
            try {
                (new Client($data['access_token']))->chatLimits();
                $chatEnabled = true;
            } catch (\Throwable) {
                // Keep image access usable and explain that chat needs attention.
            }
        }
        // Capture the replaced installation under the same lock that verifies the generation,
        // so only the credential this exchange actually superseded is ever revoked.
        $superseded = null;
        $stored = DB::transaction(function () use ($data, $d, $chatEnabled, &$superseded) {
            $s = Settings::whereKey(1)->lockForUpdate()->firstOrFail();
            if ($s->authorization_generation !== $d['generation']) {
                return false;
            }
            // Only an installation connection is revoked, as in Disconnect and the manual-key path.
            // A manual API key is never revoked here.
            if ($s->connection_id && filled($s->credential) && $s->credential !== $data['access_token']) {
                $superseded = $s->credential;
            }
            $s->update(['authorization_generation' => (string) Str::uuid(), 'credential' => $data['access_token'], 'credential_version' => (string) Str::uuid(), 'connection_id' => $data['connection_id'], 'enabled' => true, 'managed_chat' => $chatEnabled, 'privacy_consent_version' => ! empty($d['included_chat']) ? 1 : 0]);

            return true;
        });
        PendingConnection::forget($request);

        if (! $stored) {
            // This credential belongs to the superseded attempt, never the selected account.
            try {
                DB::transaction(function () use ($data) {
                    $current = Settings::whereKey(1)->lockForUpdate()->firstOrFail();
                    if ($current->credential !== $data['access_token']) {
                        (new Client($data['access_token']))->revoke();
                    }
                });
            } catch (\Throwable $e) {
                return response()->json(['status' => 'superseded', 'message' => trans('dreamlayer::app.message-b09ee42af3')], 409);
            }

            return response()->json(['status' => 'superseded'], 409);
        }

        // The new connection is committed. Revoking the previous installation is best effort
        // and never undoes the new connection; failures are reported to the administrator.
        $notice = $superseded !== null ? $this->revokeSupersededInstallation($superseded) : null;
        if (! empty($d['included_chat']) && ! $chatEnabled) {
            $notice = trim(($notice ? $notice.' ' : '').trans('dreamlayer::app.message-b0786bce19'));
        }
        if ($notice !== null) {
            // The settings page reloads after a successful poll. Keep the notice until that page
            // renders it; a flash could be consumed by a concurrent status refresh first.
            $request->session()->put('dreamlayer.reconnect_notice', $notice);

            return response()->json(['status' => 'connected', 'notice' => $notice]);
        }

        return response()->json(['status' => 'connected']);
    }

    private function revokeSupersededInstallation(string $previous): ?string
    {
        // This is a separate transaction AFTER the credential swap committed. Serialize the
        // guard and remote revoke with every settings writer; a read alone has a TOCTOU gap.
        return DB::transaction(function () use ($previous) {
            $current = Settings::whereKey(1)->lockForUpdate()->firstOrFail();
            if ($current->credential === $previous) {
                return null;
            }
            try {
                (new Client($previous))->revoke();

                return trans('dreamlayer::app.message-e0a6d1b205');
            } catch (\Throwable $e) {
                return $e instanceof ApiException && $e->status === 401
                    ? trans('dreamlayer::app.message-b9c49795f0')
                    : trans('dreamlayer::app.message-e3f83a7992');
            }
        });
    }

    public function disconnect(Request $request)
    {
        return PendingConnection::locked($request, fn () => $this->disconnectConnection($request));
    }

    private function disconnectConnection(Request $request)
    {
        $this->authorizeSettings();
        $s = Settings::current();
        $forgotten = $request->boolean('forget_local');
        if (! $forgotten && $s->connection_id && $s->credential) {
            try {
                (new Client($s->credential))->revoke();
            } catch (\Throwable $e) {
                if (! ($e instanceof ApiException && $e->status === 401)) {
                    return back()->withErrors(['connection' => trans('dreamlayer::app.message-911e1d477b')]);
                }
            }
        }
        DB::transaction(function () use ($s) {
            $current = Settings::whereKey($s->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->credential_version === $s->credential_version && $current->authorization_generation === $s->authorization_generation, 409, trans('dreamlayer::app.message-146e13e071'));
            $current->update(['credential' => null, 'credential_version' => (string) Str::uuid(), 'authorization_generation' => (string) Str::uuid(), 'connection_id' => null, 'enabled' => false, 'managed_chat' => false]);
        });
        PendingConnection::forget($request);

        return redirect()->route('dreamlayer.settings')->with('connection_notice', $forgotten ? trans('dreamlayer::app.message-dd7b628df1') : trans('dreamlayer::app.connection-disconnected'));
    }
}
