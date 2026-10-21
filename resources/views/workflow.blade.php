@include('dreamlayer::styles')
@pushOnce('scripts')
<script type="text/x-template" id="dreamlayer-workflow-template">
    <section class="dl-workflow rounded-xl border border-primary-200 dark:border-cherry-700 bg-white dark:bg-cherry-800 p-3 text-gray-700 dark:text-gray-200" aria-live="polite">
        <details v-if="state?.detailed_progress" open><summary class="cursor-pointer text-sm font-semibold" v-text="title"></summary>
            <ol v-if="state" class="mt-2 space-y-2"><li v-for="(step,index) in state.steps" :key="index" class="text-xs"><span v-text="step.status === 'completed' ? '✓ ' : step.status === 'running' ? '◌ ' : step.status === 'failed' ? '× ' : '○ '"></span><span v-text="step.label"></span><span class="ml-2 text-gray-500" v-text="step.status"></span></li></ol>
        </details>
        <p v-else class="text-sm font-semibold" v-text="title"></p>
        <p v-if="statusHelp" class="text-xs mt-2" v-text="statusHelp"></p>
        <p v-if="error || state?.error" class="text-xs mt-2" role="alert" v-text="error || state.error"></p>
        <div v-if="state?.status === 'failed' && state?.error_code === 402 && state?.billing_url" class="mb-3"><a :href="state.billing_url" target="_blank" rel="noopener noreferrer" class="primary-button">Add credits</a></div>
        <p v-if="state?.billing_notice" class="text-xs mt-2" v-text="state.billing_notice"></p>
        <template v-if="state?.partial_success">
            <p class="text-sm mt-2 font-semibold">Partially completed — completed images are available</p>
            <div v-for="(step,index) in state.steps" :key="index">
                <template v-if="step.image_url"><p class="text-xs mt-2" v-text="step.label"></p><img :src="step.image_url" alt="Completed step preview" class="mt-2 rounded-lg w-full object-contain" style="max-height:320px"><a :href="step.image_url+'&download=1'" class="inline-block mt-2 underline text-xs">Download completed step</a></template>
            </div>
        </template>
        <a href="{{ route('dreamlayer.results') }}" class="inline-block text-xs underline mt-2">All your image results</a>
        <template v-if="state?.image_url">
            <a :href="state.image_url" target="_blank" rel="noopener"><img :src="state.image_url" alt="DreamLayer image preview" class="mt-3 rounded-lg object-contain" style="max-width:100%;width:auto;height:auto;max-height:320px;display:block;margin-inline:auto;background:repeating-conic-gradient(#eee 0% 25%,#ccc 0% 50%) 50% / 16px 16px"></a>
            <a :href="state.image_url+'?download=1'" class="secondary-button dl-result-action">@lang('dreamlayer::app.download')</a>
            <div v-if="state.target" class="mt-3 text-xs"><p><strong v-text="state.target.sku"></strong> · <span v-text="state.target.attribute"></span> · image <span v-text="state.target.index + 1"></span> · <span v-text="state.target.channel"></span> / <span v-text="state.target.locale"></span></p>
            <p class="mt-2">Your catalog changes only when you click Apply.</p><button class="secondary-button mt-2" @click="apply" :disabled="applying || state.applied" :title="state.applied ? 'This result has already been saved to this product destination.' : null" v-text="state.applied ? labels.applied : labels.apply"></button></div>
        </template>
    </section>
</script>
<script type="module">
app.component('v-dreamlayer-workflow', {
    template: '#dreamlayer-workflow-template',
    props: { descriptor: { type: Object, required: true }, visible: { type: Boolean, default: true } },
    data: () => ({
        state: null,
        error: null,
        timer: null,
        applying: false,
        stopped: false,
        labels: @json(trans('dreamlayer::app'))
    }),
    computed: {
        title() {
            if (!this.state) return 'Loading image workflow…';
            if (this.state.status === 'failed')
                return this.state.error_code === 402 ? 'Out of image credits' : this.labels.failed;
            if (this.state.status === 'completed')
                return this.state.kind === 'generate' ? this.labels.ready : this.labels.complete;
            if (this.state.phase === 'waiting') return this.labels.waiting;
            if (this.state.phase === 'downloading') return this.labels.downloading;
            if (this.state.phase === 'retrying_delivery') return this.labels.retrying_delivery;
            if (this.state.delayed) return this.labels.delayed;
            if (this.state.status === 'queued') return this.labels.queued;
            if (!this.state.detailed_progress) return this.labels.processing;
            return this.state.kind === 'generate' ? this.labels.generating : this.labels.editing;
        },
        statusHelp() {
            if (!this.state || ['completed', 'failed'].includes(this.state.status)) return '';
            if (this.state.phase === 'waiting') return this.labels.waiting_help;
            if (['downloading', 'retrying_delivery'].includes(this.state.phase)) return this.labels.delivery_help;
            if (this.state.delayed) return this.labels.delayed_help;
            return this.state.status === 'queued' ? this.labels.queued_help : '';
        }
    },
    mounted() {
        document.addEventListener('visibilitychange', this.wake);
        this.poll();
    },
    beforeUnmount() {
        this.stopped = true;
        clearTimeout(this.timer);
        document.removeEventListener('visibilitychange', this.wake);
    },
    watch: {
        visible(v) {
            if (v) this.wake();
        }
    },
    methods: {
        url() {
            return @json(url(config('app.admin_url').'/dreamlayer/workflows')) + '/' + encodeURIComponent(this.descriptor.id);
        },
        wake() {
            clearTimeout(this.timer);
            this.poll();
        },
        async poll() {
            // This is a read of the existing local workflow, never an image submission.
            // Queue workers continue when the card is hidden or the browser closes.
            if (this.stopped || !this.visible || document.hidden) return;
            try {
                this.state = (await this.$axios.get(this.url())).data;
                this.error = null;
            } catch (e) {
                this.error = 'Could not load image progress.';
                if ([401, 403, 404].includes(e.response?.status)) return;
            }
            if (!this.stopped && !['completed', 'failed'].includes(this.state?.status))
                this.timer = setTimeout(() => this.poll(), 2000);
        },
        async apply() {
            // Only this explicit action writes to the product. The server rechecks
            // ownership, permissions, destination version and prior application.
            this.applying = true;
            try {
                this.state = (await this.$axios.post(this.url() + '/apply')).data;
                this.error = null;
            } catch (e) {
                this.error =
                    e.response?.status === 409
                        ? 'The saved image changed after this preview. Start a new preview before applying.'
                        : 'Could not apply image. Check permissions and attribute limits.';
            } finally {
                this.applying = false;
            }
        }
    }
});
</script>
@endPushOnce
