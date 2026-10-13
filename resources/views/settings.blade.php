<x-admin::layouts>
    <x-slot:title>DreamLayer</x-slot>
    @include('dreamlayer::styles')
    <div class="dl-page text-gray-800 dark:text-gray-200">
        @foreach($errors->all() as $error)<p class="dl-error" role="alert">{{ $error }}</p>@endforeach
        @if(session('connection_notice'))<p class="dl-notice" role="status">{{ session('connection_notice') }}</p>@endif
        <v-dreamlayer-connection-status :initial='@json($connectionState)'></v-dreamlayer-connection-status>
        <section class="dl-section">
            <details><summary>{{ $settings->credential ? 'Account settings' : 'Advanced settings' }}</summary>
                <div class="dl-stack">
                    <form method="POST" action="{{ route('dreamlayer.settings.save') }}" class="dl-stack">@csrf
                        <label class="dl-field"><strong>How you use DreamLayer</strong>
                            <select name="mode">
                                <option value="chat_images" @selected(!$settings->credential || ($settings->enabled && $settings->managed_chat))>Chat &amp; Images (recommended)</option>
                                <option value="images" @selected($settings->credential && $settings->enabled && !$settings->managed_chat)>Images only — use another chat provider</option>
                                <option value="disabled" @selected($settings->credential && !$settings->enabled)>Pause DreamLayer tools</option>
                            </select>
                            <span class="dl-muted">Chat &amp; Images uses DreamLayer for conversations and image tools. Images only keeps your selected chat provider.</span>
                        </label>
                        <details><summary>Advanced: manual API key</summary><div class="dl-stack">
                            <p class="dl-muted">Connecting your account handles this for you. Use a manual key only if you need to manage the connection yourself.</p>
                            <label class="dl-field"><span>{{ $settings->credential ? 'Replace API key' : 'API key' }}</span><input type="password" name="api_key" autocomplete="new-password" value=""><span class="dl-muted">Leave this blank to keep your current connection.</span></label>
                        </div></details>
                        <div class="dl-actions"><button class="primary-button" type="submit">Save settings</button></div>
                    </form>
                    @if($settings->credential)
                        <div class="dl-section"><h2>Connection</h2><p class="dl-muted">Manage the account connected to this installation. Your saved image results stay in UnoPIM when you disconnect or switch accounts.</p>
                            <div class="dl-actions"><a class="dl-link" href="{{ config('dreamlayer.billing_url') }}" target="_blank" rel="noopener noreferrer">Manage credits and billing</a></div>
                            <form method="POST" action="{{ route('dreamlayer.disconnect') }}" onsubmit="return window.confirm('Disconnect DreamLayer from this UnoPIM installation?');">@csrf<div class="dl-actions"><button class="secondary-button" type="submit">Disconnect DreamLayer</button></div></form>
                            @if(config('dreamlayer.connections_enabled'))
                                <details><summary>Connect another account</summary><div class="dl-stack"><p class="dl-muted">Get a code and approve it with the account you want to use. Your current connection stays in place until you approve the new one.</p><v-dreamlayer-connect></v-dreamlayer-connect></div></details>
                            @endif
                            <details><summary>Troubleshooting</summary><div class="dl-stack"><p class="dl-muted">If disconnecting fails, you can remove the saved connection from UnoPIM. You will still need to revoke the installation in your DreamLayer Console.</p><form method="POST" action="{{ route('dreamlayer.disconnect') }}" onsubmit="return window.confirm('Remove the local connection? You will still need to revoke it in DreamLayer.');">@csrf<button class="dl-button-link dl-danger" type="submit" name="forget_local" value="1">Remove local connection</button></form></div></details>
                        </div>
                    @endif
                </div>
            </details>
        </section>
    </div>
    @pushOnce('scripts')
    <script type="text/x-template" id="dreamlayer-connection-status-template">
        <div>
            <div class="dl-heading"><h1>DreamLayer</h1><span class="dl-status-chip" :class="{'is-connected': state.status === 'connected', 'is-pending': waitingForConnection && state.status !== 'connected'}" role="status" v-text="state.status === 'connected' ? 'Connected' : waitingForConnection ? 'Waiting for connection' : state.status === 'unavailable' ? 'Unable to check connection' : state.status === 'unauthorized' ? 'Reconnect needed' : 'Not Connected'"></span></div>
            <p v-if="refreshError" class="dl-muted" role="status" v-text="refreshError"></p>
            <section class="dl-section"><h2>About</h2><p>Choose the product images you want to edit and describe the changes you’d like to make. DreamLayer uses the selected images, catalog information, and chat context to process your request and show you a preview first.</p><p>Your catalog is only updated when you click <strong>Apply to product</strong>.</p></section>
            <section v-if="state.status === 'connected'" class="dl-section">
                <dl class="dl-account"><div><dt>Connected account</dt><dd v-text="state.account_label || state.method"></dd><dd class="dl-muted" v-text="connectionLabel"></dd></div><div v-if="state.balances"><dt>Available API credits</dt><dd class="dl-balance" v-text="state.balances.available ?? '—'"></dd><dd class="dl-muted">Includes @{{ state.balances.promotional ?? '—' }} promotional credits</dd></div></dl>
            </section>
            <section v-else class="dl-section"><h2>Step 1: Connect your account</h2>
                <template v-if="state.status === 'disconnected'">
                    <p>Open DreamLayer, sign in or create an account, and enter the code below. Review the installation details and approve the connection.</p>
                    @if(config('dreamlayer.connections_enabled'))<v-dreamlayer-connect :automatic="true" @pending-change="waitingForConnection = $event"></v-dreamlayer-connect>@else<p>Use a manual API key in Advanced settings to connect.</p>@endif
                </template>
                <template v-else-if="state.status === 'unauthorized'"><p class="dl-error" role="alert">Your connection is no longer authorized. Connect your account again to continue.</p><v-dreamlayer-connect @pending-change="waitingForConnection = $event"></v-dreamlayer-connect></template>
                <template v-else><p>We couldn’t check your saved connection. Try again in a moment.</p><div class="dl-actions"><button class="secondary-button" @click="refresh" :disabled="reading">Check connection</button></div></template>
            </section>
            <section class="dl-section"><h2 v-text="state.status === 'connected' ? 'Start using DreamLayer' : 'Step 2: Open the chat'"></h2>
                <template v-if="state.status === 'connected' && state.image_tools_enabled">
                    <p>Open the chat, choose your product images, and describe what you’d like to change. You can review and apply your results from the conversation.</p>
                    <div class="dl-actions"><button v-if="state.chat_available" type="button" class="primary-button" @click="openChat">Open chat</button>
                    <form v-else-if="state.can_enable_chat" method="POST" action="{{ route('dreamlayer.chat.open') }}">@csrf<button class="primary-button" type="submit">Enable and open chat</button></form>
                    <a class="secondary-button" href="{{ route('dreamlayer.results') }}">My image results</a></div>
                    <p v-if="!state.chat_available" class="dl-muted" v-text="state.can_enable_chat ? 'This enables chat for your UnoPIM installation. Opening it does not send a message.' : 'Ask an administrator with configuration access to enable UnoPIM chat.'"></p>
                </template>
                <p v-else-if="state.status === 'connected'">Your DreamLayer tools are paused. Choose Chat &amp; Images or Images only in Account settings to continue.</p>
                <p v-else class="dl-muted">Once you approve the connection, this page will update and you can open the chat here.</p>
            </section>
        </div>
    </script>
    <script type="text/x-template" id="dreamlayer-connect-template">
        <div class="dl-stack">
            <div v-if="code" class="dl-code-box"><strong>Your connection code</strong><div class="dl-actions"><code class="dl-code" v-text="code"></code><button type="button" class="secondary-button" @click="copyCode" :disabled="copying || !remaining" aria-live="polite" v-text="copied ? 'Copied' : 'Copy'"></button><button type="button" class="secondary-button" @click="connect(true)" :disabled="busy">Generate new code</button></div><p v-if="copyError" class="dl-error" role="alert" v-text="copyError"></p><p class="dl-muted" v-text="remaining > 0 ? 'Expires in ' + Math.floor(remaining / 60) + ':' + String(remaining % 60).padStart(2, '0') + '. A new code appears when this one expires.' : 'Getting a new code…'"></p></div>
            <div class="dl-actions"><a v-if="code && remaining > 0" class="primary-button" :href="uri" target="_blank" rel="noopener noreferrer">Open DreamLayer</a><button v-if="!code" type="button" class="secondary-button" @click="connect(false)" :disabled="busy" v-text="busy ? 'Getting your code…' : 'Get connection code'"></button></div>
            <p v-if="code" class="dl-muted">After approving in DreamLayer, return here. Connecting can take a few seconds. This page will update automatically when it’s ready.</p>
            <p v-if="error" role="alert" class="dl-error" v-text="error"></p>
        </div>
    </script>
    <script type="module">
app.component('v-dreamlayer-connection-status', {
    template: '#dreamlayer-connection-status-template',
    props: ['initial'],
    data() {
        return {
            state: this.initial,
            waitingForConnection: false,
            timer: null,
            reading: false,
            stopped: false,
            refreshError: null,
            nextRefreshAt: 0
        };
    },
    computed: {
        connectionLabel() {
            return this.state.image_tools_enabled
                ? this.state.chat_enabled
                    ? 'DreamLayer – Chat & Images'
                    : 'DreamLayer – Images only'
                : 'DreamLayer – Tools disabled';
        }
    },
    mounted() {
        this.timer = setTimeout(() => this.refresh(), 30000);
        document.addEventListener('visibilitychange', this.visible);
        window.addEventListener('focus', this.visible);
        if (
            new URLSearchParams(window.location.search).get('open_chat') === '1' &&
            this.state.status === 'connected' &&
            this.state.chat_available
        )
            this.$nextTick(() => {
                this.openChat();
                const url = new URL(window.location.href);
                url.searchParams.delete('open_chat');
                window.history.replaceState(null, '', url);
            });
    },
    beforeUnmount() {
        this.stopped = true;
        clearTimeout(this.timer);
        document.removeEventListener('visibilitychange', this.visible);
        window.removeEventListener('focus', this.visible);
    },
    methods: {
        openChat() {
            window.dispatchEvent(new CustomEvent('ai-agent:compose'));
        },
        visible() {
            if (!document.hidden && Date.now() >= this.nextRefreshAt) this.refresh();
        },
        async refresh() {
            if (this.stopped || this.reading || Date.now() < this.nextRefreshAt) return;
            clearTimeout(this.timer);
            let delay = 30000;
            if (!document.hidden) {
                this.reading = true;
                this.nextRefreshAt = Date.now() + 5000;
                try {
                    const r = await this.$axios.get(@json(route('dreamlayer.settings.status')));
                    if (!this.stopped) {
                        this.refreshError = null;
                        if (r.data.status === 'connected' && this.state.status === 'disconnected') {
                            window.location.reload();
                            return;
                        }
                        this.state = r.data;
                    }
                } catch (e) {
                    // A failed read does not change the saved connection or invalidate its code.
                    if (!this.stopped) {
                        if (e.response?.status !== 429)
                            this.refreshError =
                                'We couldn’t refresh the connection status. We’ll try again shortly.';
                        delay = Math.max(5000, (Number(e.response?.headers?.['retry-after']) || 30) * 1000);
                        this.nextRefreshAt = Date.now() + delay;
                    }
                } finally {
                    this.reading = false;
                }
            }
            if (!this.stopped) this.timer = setTimeout(() => this.refresh(), delay);
        }
    }
});
app.component('v-dreamlayer-connect', {
    template: '#dreamlayer-connect-template',
    props: { automatic: { type: Boolean, default: false } },
    emits: ['pending-change'],
    data: () => ({
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
                        if (!document.execCommand('copy')) throw Error('Copy unavailable');
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
                    this.copyError = 'Could not copy automatically. Select the code and copy it manually.';
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
            if (this.busy || this.stopped) return;
            clearTimeout(this.timer);
            this.busy = true;
            this.error = null;
            try {
                const r = await this.$axios.post(@json(route('dreamlayer.connect')), {
                    included_chat: true,
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
                    this.error = keepCode
                        ? 'Please wait a moment before getting a new code. Your current code still works.'
                        : 'Could not get a code. Try again in a moment.';
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
                            ? 'Connection denied. Get a new code when you’re ready.'
                            : 'Connection settings changed. Refresh this page to continue.';
                    return;
                }
                if (s !== 'slow_down' && e.response?.status !== 429)
                    this.error = 'Connection check interrupted. We’ll try again shortly.';
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
