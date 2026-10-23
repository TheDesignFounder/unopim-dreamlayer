<x-admin::layouts>
    <x-slot:title>@lang('dreamlayer::app.ui-df37e16ca0')</x-slot>
    @include('dreamlayer::styles')
    <div class="dl-page dl-settings dl-stack text-gray-800 dark:text-gray-200">
        <h1>@lang('dreamlayer::app.ui-df37e16ca0')</h1>
        @foreach($errors->all() as $error)<p class="dl-error" role="alert">{{ $error }}</p>@endforeach
        @if($connectionNotice)<p class="dl-notice" role="status">{{ $connectionNotice }}</p>@endif
        <p>@lang('dreamlayer::app.settings-help')</p>
        <p><strong>@lang('dreamlayer::app.connection-status'):</strong> {{ trans('dreamlayer::app.connection-'.$connectionState['status']) }}</p>
        @if($connectionState['balances'])<p>@lang('dreamlayer::app.available-credits'): {{ $connectionState['balances']['available'] ?? '—' }}</p>@endif
        <section class="dl-editor-entry" aria-labelledby="dl-editor-entry-title">
            <h2 id="dl-editor-entry-title">@lang('dreamlayer::app.editor')</h2>
            <p class="dl-muted">@lang('dreamlayer::app.editor-help')</p>
            @if($settings->connected())
                <a class="dl-link" href="{{ route('dreamlayer.choose') }}">@lang('dreamlayer::app.open-editor')</a>
            @else
                <p class="dl-muted">@lang('dreamlayer::app.editor-entry-setup')</p>
            @endif
        </section>
        @if(config('dreamlayer.connections_enabled'))<section class="dl-section"><h2>@lang('dreamlayer::app.connect')</h2><v-dreamlayer-connect :connected="@json((bool) $settings->credential)"></v-dreamlayer-connect></section>@endif
        @if(view()->exists('admin::components.form.unsaved-changes'))
            @include('admin::components.form.unsaved-changes')
            <v-dreamlayer-settings-changes :hide-save-when-tracked="true">
        @endif
        <x-admin::form :action="route('dreamlayer.settings.save')" method="POST" :track-dirty="false">
            <div class="dl-stack">
                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>@lang('dreamlayer::app.key')</x-admin::form.control-group.label>
                    <x-admin::form.control-group.control type="password" name="api_key" value="" autocomplete="new-password" />
                    <x-admin::form.control-group.error control-name="api_key" />
                    <p class="dl-muted">@lang('dreamlayer::app.key-help')</p>
                </x-admin::form.control-group>
                @foreach(['enabled','managed_chat','save_chat_history'] as $field)
                    <x-admin::form.control-group class="dl-switch">
                        <x-admin::form.control-group.label :for="$field">{{ trans('dreamlayer::app.setting-'.$field) }}</x-admin::form.control-group.label>
                        <x-admin::form.control-group.control type="switch" :name="$field" value="1" :checked="(bool) $settings->$field" :label="trans('dreamlayer::app.setting-'.$field)" />
                        <p class="dl-muted">{{ trans('dreamlayer::app.setting-'.$field.'-help') }}</p>
                    </x-admin::form.control-group>
                @endforeach
                @if(\DreamLayer\UnoPIM\Compatibility\HostSupport::nativeChat())
                    <x-admin::form.control-group class="dl-switch">
                        <x-admin::form.control-group.label for="native_chat_enabled">@lang('dreamlayer::app.setting-native_chat_enabled')</x-admin::form.control-group.label>
                        <x-admin::form.control-group.control type="switch" name="native_chat_enabled" value="1" :checked="(bool) $settings->native_chat_enabled" />
                        <p class="dl-muted">@lang('dreamlayer::app.setting-native_chat_enabled-help')</p>
                    </x-admin::form.control-group>
                @endif
                <section class="dl-section" id="dreamlayer-data-sharing"><h2>@lang('dreamlayer::app.privacy')</h2>
                    @foreach(['images','chat','connection','storage','providers'] as $topic)<p>{{ trans('dreamlayer::app.privacy-'.$topic) }}</p>@endforeach
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label for="privacy_consent">@lang('dreamlayer::app.privacy-consent')</x-admin::form.control-group.label>
                        <x-admin::form.control-group.control type="checkbox" name="privacy_consent" value="1" :checked="$settings->privacy_consent_version >= 1" />
                        <x-admin::form.control-group.error control-name="privacy_consent" />
                    </x-admin::form.control-group>
                </section>
                <button class="primary-button" type="submit">@lang('dreamlayer::app.save')</button>
            </div>
        </x-admin::form>
        @if(view()->exists('admin::components.form.unsaved-changes'))
            </v-dreamlayer-settings-changes>
        @endif
        @if($settings->credential)
            <section class="dl-section"><a class="dl-link" href="{{ config('dreamlayer.billing_url') }}" target="_blank" rel="noopener noreferrer">@lang('dreamlayer::app.billing')</a>
                <form method="POST" action="{{ route('dreamlayer.disconnect') }}">@csrf<button type="submit" class="secondary-button">@lang('dreamlayer::app.disconnect')</button></form>
                <details><summary>@lang('dreamlayer::app.troubleshooting')</summary><p>@lang('dreamlayer::app.forget-help')</p><form method="POST" action="{{ route('dreamlayer.disconnect') }}">@csrf<button type="submit" name="forget_local" value="1" class="secondary-button">@lang('dreamlayer::app.forget')</button></form></details>
            </section>
        @endif
    </div>
    @pushOnce('scripts')
<script type="text/x-template" id="dreamlayer-connect-template">
        <div class="dl-stack">
            <div v-if="!code" class="dl-stack">
                <p v-text="labels['connection-setup-help']"></p>
                <x-admin::form.control-group class="dl-switch">
                    <x-admin::form.control-group.label for="dl-connect-chat">@lang('dreamlayer::app.setting-managed_chat')</x-admin::form.control-group.label>
                    <x-admin::form.control-group.control type="switch" name="connection_chat" id="dl-connect-chat" value="1" @change="includeChat = $event.target.checked; chatConsent = false" ::disabled="busy" :label="trans('dreamlayer::app.setting-managed_chat')" />
                    <p class="dl-muted">@lang('dreamlayer::app.setting-managed_chat-help')</p>
                </x-admin::form.control-group>
                <div v-if="includeChat" class="dl-stack">
                    <p class="dl-muted">@lang('dreamlayer::app.privacy-chat')</p>
                    <a class="dl-link" href="#dreamlayer-data-sharing">@lang('dreamlayer::app.privacy')</a>
                    <x-admin::form.control-group class="dl-switch">
                        <x-admin::form.control-group.label for="dl-connect-consent">@lang('dreamlayer::app.privacy-consent')</x-admin::form.control-group.label>
                        <x-admin::form.control-group.control type="switch" name="connection_consent" id="dl-connect-consent" value="1" @change="chatConsent = $event.target.checked" ::disabled="busy" :label="trans('dreamlayer::app.privacy-consent')" />
                    </x-admin::form.control-group>
                </div>
            </div>
            <div v-if="code" class="dl-code-box"><strong>@lang('dreamlayer::app.ui-22bf90a2e2')</strong><div class="dl-actions"><code class="dl-code" v-text="code"></code><button type="button" class="secondary-button" @click="copyCode" :disabled="copying || !remaining" aria-live="polite" v-text="copied ? labels['ui-8d525e5f15'] : labels['ui-e21f935f11']"></button><button type="button" class="secondary-button" @click="connect(true)" :disabled="busy">@lang('dreamlayer::app.ui-c7730178f4')</button></div><p v-if="copyError" class="dl-error" role="alert" v-text="copyError"></p><p class="dl-muted" v-text="remaining > 0 ? labels['expires-in'].replace(':time', Math.floor(remaining / 60) + ':' + String(remaining % 60).padStart(2, '0')) : labels['ui-b9bb9b3937']"></p></div>
            <div class="dl-actions"><a v-if="code && remaining > 0" class="primary-button" :href="uri" target="_blank" rel="noopener noreferrer">@lang('dreamlayer::app.ui-a020541168')</a><button v-if="!code" type="button" class="secondary-button" @click="connect(false)" :disabled="busy || (includeChat && !chatConsent)" v-text="busy ? labels['ui-76e286b20d'] : connected ? labels['connection-reconnect'] : labels['ui-67f1079f27']"></button><button v-if="code" type="button" class="secondary-button" @click="changeOptions" :disabled="busy" v-text="labels['connection-change-options']"></button></div>
            <p v-if="code" class="dl-muted">@lang('dreamlayer::app.ui-b0dba24f61')</p>
            <p v-if="error" role="alert" class="dl-error" v-text="error"></p>
        </div>
    </script>
<script type="module">
// Keep the host's save/discard behavior, scoped to this settings form. Some
// hosts leave delayed initialization callbacks running after AJAX navigation.
// Ignore those callbacks once the form has been removed; never patch the global
// component or UnoPim's core files.
const hostSettingsChanges = app.component('v-unsaved-changes');
if (hostSettingsChanges) {
    const guardedMethods = {};
    for (const name of ['snapshot', 'recompute', 'removeInFormSave']) {
        const original = hostSettingsChanges.methods?.[name];
        if (typeof original !== 'function') continue;
        guardedMethods[name] = function (...args) {
            if (!this.$refs.root?.isConnected) return;
            return original.apply(this, args);
        };
    }
    app.component('v-dreamlayer-settings-changes', {
        extends: hostSettingsChanges,
        methods: guardedMethods
    });
}
app.component('v-dreamlayer-connect', {
    template: '#dreamlayer-connect-template',
    props: { automatic: { type: Boolean, default: false }, connected: { type: Boolean, default: false } },
    emits: ['pending-change'],
    data: () => ({
        labels: @json(trans('dreamlayer::app')),
        includeChat: false,
        chatConsent: false,
        code: null,
        uri: null,
        attempt: null,
        error: null,
        busy: false,
        timer: null,
        ticker: null,
        deadline: 0,
        remaining: 0,
        interval: 5,
        nextPollAt: 0,
        stopped: false,
        copied: false,
        copying: false,
        copyError: null,
        copyTimer: null
    }),
    watch: {
        code() {
            this.resetCopy();
        }
    },
    mounted() {
        if (this.automatic) this.connect(false);
        this.ticker = setInterval(() => this.tick(), 1000);
        document.addEventListener('visibilitychange', this.visible);
        window.addEventListener('focus', this.visible);
    },
    beforeUnmount() {
        this.stopped = true;
        this.$emit('pending-change', false);
        clearTimeout(this.timer);
        clearInterval(this.ticker);
        clearTimeout(this.copyTimer);
        document.removeEventListener('visibilitychange', this.visible);
        window.removeEventListener('focus', this.visible);
    },
    methods: {
        changeOptions() {
            clearTimeout(this.timer);
            this.code = null;
            this.attempt = null;
            this.deadline = 0;
            this.includeChat = false;
            this.chatConsent = false;
            this.error = null;
        },
        resetCopy() {
            clearTimeout(this.copyTimer);
            this.copied = false;
            this.copyError = null;
        },
        async copyCode() {
            // Clipboard access is asynchronous; report success only for the code
            // still displayed, never for a code replaced while copying was pending.
            if (!this.code || !this.remaining || this.copying) return;
            const code = this.code;
            this.copying = true;
            this.resetCopy();
            try {
                if (navigator.clipboard?.writeText) await navigator.clipboard.writeText(code);
                else {
                    const field = document.createElement('textarea'),
                        focused = document.activeElement;
                    field.value = code;
                    field.style.position = 'fixed';
                    field.style.opacity = '0';
                    document.body.appendChild(field);
                    try {
                        field.select();
                        if (!document.execCommand('copy')) throw Error(this.labels['ui-77203498d5']);
                    } finally {
                        field.remove();
                        focused?.focus();
                    }
                }
                if (!this.stopped && this.code === code) {
                    this.copied = true;
                    this.copyTimer = setTimeout(() => this.resetCopy(), 2000);
                }
            } catch {
                if (!this.stopped && this.code === code)
                    this.copyError = this.labels['ui-f5490ffc6c'];
            } finally {
                this.copying = false;
            }
        },
        visible() {
            if (!document.hidden) {
                this.tick();
                if (this.code && this.remaining && !this.busy) {
                    clearTimeout(this.timer);
                    this.poll();
                }
            }
        },
        tick() {
            this.remaining = Math.max(0, Math.ceil((this.deadline - Date.now()) / 1000));
            if (!document.hidden && this.code && !this.remaining && !this.busy) this.connect(false);
        },
        schedule(seconds) {
            clearTimeout(this.timer);
            this.nextPollAt = Date.now() + seconds * 1000;
            if (!this.stopped && this.code) this.timer = setTimeout(() => this.poll(), seconds * 1000);
        },
        async connect(regenerate = false) {
            if (this.busy || this.stopped || (this.includeChat && !this.chatConsent)) return;
            clearTimeout(this.timer);
            this.busy = true;
            this.error = null;
            try {
                const r = await this.$axios.post(@json(route('dreamlayer.connect')), {
                    included_chat: this.includeChat,
                    privacy_consent: this.includeChat && this.chatConsent,
                    automatic: this.automatic,
                    regenerate
                });
                if (this.stopped) return;
                this.code = r.data.user_code;
                this.uri = r.data.verification_uri + '?connect=1';
                this.attempt = r.data.attempt_id;
                this.deadline = Date.now() + r.data.expires_in * 1000;
                this.remaining = r.data.expires_in;
                this.interval = r.data.interval || 5;
                this.$emit('pending-change', r.data.approved === true);
                this.schedule(r.data.poll_after ?? (r.data.approved ? 0 : this.interval));
            } catch (e) {
                if (!this.stopped) {
                    if (e.response?.data?.status === 'already_configured') {
                        window.location.reload();
                        return;
                    }
                    const keepCode = e.response?.status === 429 && this.code && Date.now() < this.deadline;
                    this.error = e.response?.status === 422 ? this.labels['message-b0786bce19'] : keepCode
                        ? this.labels['ui-0897795b07']
                        : this.labels['ui-6e882e97ad'];
                    if (keepCode)
                        this.schedule(
                            Math.max(this.interval, Number(e.response?.headers?.['retry-after']) || 0)
                        );
                    // Without a valid code, stop automatic retries until the visitor tries again.
                    else {
                        this.code = null;
                        this.uri = null;
                        this.$emit('pending-change', false);
                    }
                }
            } finally {
                this.busy = false;
            }
        },
        async poll() {
            // Returning from the approval tab should check as soon as allowed,
            // without restarting the server's polling interval or bypassing it.
            if (this.stopped || this.busy || !this.code) return;
            if (document.hidden) return; // Resume as soon as the visitor returns, respecting the server interval.
            if (Date.now() < this.nextPollAt) {
                this.timer = setTimeout(() => this.poll(), this.nextPollAt - Date.now());
                return;
            }
            if (Date.now() >= this.deadline) {
                this.connect(false);
                return;
            }
            this.busy = true;
            try {
                const r = await this.$axios.post(@json(route('dreamlayer.connect.poll')), { attempt_id: this.attempt });
                if (this.stopped) return;
                if (r.data.status === 'connected') {
                    this.$emit('pending-change', true);
                    window.location.reload();
                    return;
                }
                if (r.data.status === 'approved') {
                    // A pending code is still "Not Connected". Show the waiting
                    // state only after approval, while the token exchange finishes.
                    this.$emit('pending-change', true);
                    this.error = null;
                    this.schedule(0);
                    return;
                }
                this.$emit('pending-change', false);
                this.error = null;
                this.schedule(r.data.interval || this.interval);
            } catch (e) {
                if (this.stopped) return;
                const s = e.response?.data?.status;
                if (s === 'expired_token' || e.response?.status === 410) {
                    this.deadline = 0;
                    this.$emit('pending-change', false);
                    return;
                }
                if (
                    s === 'access_denied' ||
                    e.response?.status === 409 ||
                    e.response?.status === 401 ||
                    e.response?.status === 403
                ) {
                    this.code = null;
                    this.uri = null;
                    this.$emit('pending-change', false);
                    this.error =
                        s === 'access_denied'
                            ? this.labels['ui-e1d2cda809']
                            : this.labels['ui-cc589395a4'];
                    return;
                }
                if (s !== 'slow_down' && e.response?.status !== 429)
                    this.error = this.labels['ui-819dfb9601'];
                this.schedule(
                    Math.max(
                        e.response?.data?.interval || this.interval,
                        Number(e.response?.headers?.['retry-after']) || 0
                    )
                );
            } finally {
                this.busy = false;
            }
        }
    }
});

</script>
    @endPushOnce
</x-admin::layouts>
