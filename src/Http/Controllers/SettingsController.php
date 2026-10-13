<?php

namespace DreamLayer\UnoPIM\Http\Controllers;

use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Services\ApiException;
use DreamLayer\UnoPIM\Services\Client;
use DreamLayer\UnoPIM\Services\Endpoints;
use DreamLayer\UnoPIM\Services\PendingConnection;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Webkul\Admin\SystemSettings;
use Webkul\Core\Repositories\CoreConfigRepository;

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
        $settings = Settings::current();
        $connectionState = $this->connectionState($settings);

        return view('dreamlayer::settings', compact('settings', 'connectionState'));
    }

    public function status()
    {
        $this->authorizeSettings();

        return response()->json($this->connectionState(Settings::current()))->header('Cache-Control', 'no-store');
    }

    private function connectionState(Settings $settings): array
    {
        $state = ['status' => 'disconnected', 'method' => $settings->connection_id ? 'DreamLayer account' : 'Manual API key', 'balances' => null, 'account_label' => null, 'chat_available' => (bool) core()->getConfigData('general.magic_ai.agentic_pim.enabled'), 'can_enable_chat' => $this->canEnableChat(), 'chat_enabled' => (bool) $settings->managed_chat, 'image_tools_enabled' => (bool) $settings->enabled];
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

    private function canEnableChat(): bool
    {
        $permissions = ['configuration', ...app(SystemSettings::class)->aclKeysForConfigGroup('general.magic_ai')];

        return collect($permissions)->every(fn ($permission) => bouncer()->hasPermission($permission));
    }

    public function openChat()
    {
        $this->authorizeSettings();
        abort_unless($this->canEnableChat(), 403);
        abort_unless(Settings::current()->connected(), 409, 'Connect DreamLayer before enabling chat.');
        // Explicit administrator action. Do not enable enrichment, write tools, or send a message.
        app(CoreConfigRepository::class)->create([
            'general' => ['magic_ai' => ['agentic_pim' => ['enabled' => '1']]],
        ]);

        return redirect()->route('dreamlayer.settings', ['open_chat' => 1]);
    }

    public function save(Request $request)
    {
        $this->authorizeSettings();
        // Remove the secret before any framework validation exception can flash input.
        $key = $request->input('api_key');
        $request->request->remove('api_key');
        if ($request->isJson()) {
            $request->json()->remove('api_key');
        }
        $request->session()->forget('_old_input.api_key');
        $validator = Validator::make(['enabled' => $request->input('enabled'), 'managed_chat' => $request->input('managed_chat'), 'api_key' => $key], ['enabled' => ['nullable', 'boolean'], 'managed_chat' => ['nullable', 'boolean'], 'api_key' => ['nullable', 'string', 'max:512']]);
        if ($validator->fails()) {
            return back()->withErrors($validator);
        }
        $data = $validator->validated();
        if ($request->has('mode')) {
            $request->validate(['mode' => ['required', 'in:chat_images,images,disabled']]);
            $data['enabled'] = $request->input('mode') !== 'disabled';
            $data['managed_chat'] = $request->input('mode') === 'chat_images';
        }
        // Snapshot before network I/O; validation can outlive a newer administrator's save.
        $before = Settings::current();
        if (! empty($data['api_key'])) {
            try {
                (new Client($data['api_key']))->capabilities();
            } catch (\Throwable $e) {
                return back()->withErrors(['api_key' => 'DreamLayer could not validate this key.']);
            }
        }
        if (! empty($data['managed_chat'])) {
            try {
                (new Client(($data['api_key'] ?? null) ?: $before->credential))->chatLimits();
            } catch (\Throwable) {
                return back()->withErrors(['managed_chat' => 'Included chat is unavailable or needs additional connection approval. Image tools and your existing provider remain usable.']);
            }
        }
        $current = Settings::current();
        abort_unless($current->credential_version === $before->credential_version && $current->authorization_generation === $before->authorization_generation, 409, 'Connection settings changed. Review the current connection.');
        // A manual key supersedes an account connection. Revoke that installation first, as
        // Disconnect does, so its credential is not left live in DreamLayer with nothing holding it.
        $supersedes = ! empty($data['api_key']) && $before->connection_id && $before->credential && $before->credential !== $data['api_key'];
        $notice = null;
        if ($supersedes) {
            try {
                (new Client($before->credential))->revoke();
                $notice = 'Manual API key saved. The previous DreamLayer installation connection was revoked.';
            } catch (\Throwable $e) {
                $notice = $e instanceof ApiException && $e->status === 401
                    ? 'Manual API key saved. The previous DreamLayer installation connection was already revoked.'
                    : 'Manual API key saved. The previous DreamLayer installation could not be revoked; revoke it in DreamLayer.';
            }
        }
        DB::transaction(function () use ($data, $before) {
            $s = Settings::whereKey($before->id)->lockForUpdate()->firstOrFail();
            // Never overwrite a credential that changed while the previous one was being revoked.
            abort_unless($s->credential_version === $before->credential_version && $s->authorization_generation === $before->authorization_generation, 409, 'Connection settings changed. Review the current connection.');
            $s->authorization_generation = (string) Str::uuid();
            $s->enabled = (bool) ($data['enabled'] ?? false);
            $s->managed_chat = (bool) ($data['managed_chat'] ?? false);
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
        if ($request->boolean('automatic') && $current->credential) {
            return response()->json(['status' => 'already_configured'], 409);
        }
        $pending = PendingConnection::get($request);
        if (! $request->boolean('regenerate') && $pending && ($pending['generation'] ?? null) === $current->authorization_generation && ($pending['expires_at'] ?? 0) > time() && isset($pending['user_code'], $pending['verification_uri'])) {
            return response()->json($this->publicAttempt($pending))->header('Cache-Control', 'no-store');
        }
        $s = DB::transaction(function () {
            $s = Settings::whereKey(Settings::current()->id)->lockForUpdate()->firstOrFail();
            $s->update(['authorization_generation' => (string) Str::uuid()]);

            return $s;
        });
        $payload = ['installation_id' => $s->installation_id, 'installation_name' => config('app.name').' — UnoPIM', 'installation_url' => rtrim(config('app.url'), '/').'/'.trim(config('app.admin_url'), '/').'/dreamlayer/settings'];
        if ($request->boolean('included_chat', true)) {
            $payload['included_chat'] = true;
        }
        $response = $this->deviceRequest('device-authorization', $payload);
        if ($response->status() === 422 && isset($payload['included_chat'])) {
            unset($payload['included_chat']);
            $response = $this->deviceRequest('device-authorization', $payload);
        }
        abort_unless($response->successful(), 502, 'DreamLayer connection is unavailable.');
        $d = $response->json();
        abort_unless(is_string($d['device_code'] ?? null) && preg_match('/^[A-Z0-9-]{6,12}$/', $d['user_code'] ?? '') && ($d['verification_uri'] ?? '') === Endpoints::platform().'/console/connect', 502);
        $pending = ['generation' => $s->authorization_generation, 'device_code' => $d['device_code'], 'user_code' => $d['user_code'], 'verification_uri' => $d['verification_uri'], 'expires_at' => time() + max(1, min(600, (int) $d['expires_in'])), 'interval' => max(5, (int) $d['interval']), 'next_poll' => time() + max(5, (int) $d['interval'])];
        PendingConnection::put($request, $pending);

        return response()->json($this->publicAttempt($pending))->header('Cache-Control', 'no-store');
    }

    private function publicAttempt(array $pending): array
    {
        // The user_code is safe to display. The device_code stays in encrypted
        // server-side pending state because it can exchange approval for a credential.
        // Reopening the settings page must not restart the poll interval. A code
        // that is already due can be checked immediately in the returning tab.
        return ['attempt_id' => $pending['generation'], 'user_code' => $pending['user_code'], 'verification_uri' => $pending['verification_uri'], 'interval' => $pending['interval'], 'poll_after' => max(0, $pending['next_poll'] - time()), 'expires_in' => max(0, $pending['expires_at'] - time()), 'approved' => (bool) ($pending['approved'] ?? false)];
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
        abort_if($request->filled('attempt_id') && $request->input('attempt_id') !== ($d['generation'] ?? null), 409, 'This code was replaced. Refresh to see the current code.');
        abort_unless(isset($d['generation']) && $d['generation'] === Settings::current()->authorization_generation, 409, 'Connection settings changed. Connect again.');
        abort_if(time() >= $d['expires_at'], 410, 'Authorization expired. Connect again.');
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
        // Capture the replaced installation under the same lock that verifies the generation,
        // so only the credential this exchange actually superseded is ever revoked.
        $superseded = null;
        $stored = DB::transaction(function () use ($data, $d, &$superseded) {
            $s = Settings::whereKey(1)->lockForUpdate()->firstOrFail();
            if ($s->authorization_generation !== $d['generation']) {
                return false;
            }
            // Only an installation connection is revoked, as in Disconnect and the manual-key path.
            // A manual API key is never revoked here.
            if ($s->connection_id && filled($s->credential) && $s->credential !== $data['access_token']) {
                $superseded = $s->credential;
            }
            $s->update(['authorization_generation' => (string) Str::uuid(), 'credential' => $data['access_token'], 'credential_version' => (string) Str::uuid(), 'connection_id' => $data['connection_id'], 'enabled' => true, 'managed_chat' => in_array('chat:use', explode(' ', $data['scope'] ?? ''), true)]);

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
                return response()->json(['status' => 'superseded', 'message' => 'Settings changed. Remove the unused installation in DreamLayer connection management.'], 409);
            }

            return response()->json(['status' => 'superseded'], 409);
        }

        // The new connection is committed. Revoking the previous installation is best effort
        // and never undoes the new connection; failures are reported to the administrator.
        $notice = $superseded !== null ? $this->revokeSupersededInstallation($superseded) : null;
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

                return 'DreamLayer connected. The previous DreamLayer installation connection was revoked.';
            } catch (\Throwable $e) {
                return $e instanceof ApiException && $e->status === 401
                    ? 'DreamLayer connected. The previous DreamLayer installation connection was already revoked.'
                    : 'DreamLayer connected. The previous DreamLayer installation could not be revoked; revoke it in DreamLayer connection management.';
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
                    return back()->withErrors(['connection' => 'Remote revocation could not be confirmed. Retry, or forget locally and revoke the installation in DreamLayer.']);
                }
            }
        }
        DB::transaction(function () use ($s) {
            $current = Settings::whereKey($s->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->credential_version === $s->credential_version && $current->authorization_generation === $s->authorization_generation, 409, 'Connection settings changed. Review the current connection.');
            $current->update(['credential' => null, 'credential_version' => (string) Str::uuid(), 'authorization_generation' => (string) Str::uuid(), 'connection_id' => null, 'enabled' => false, 'managed_chat' => false]);
        });
        PendingConnection::forget($request);

        return redirect()->route('dreamlayer.settings')->with('connection_notice', $forgotten ? 'Credential forgotten locally. Remote revocation was not confirmed; manage installations in DreamLayer.' : 'Disconnected.');
    }
}
