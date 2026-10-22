<x-admin::layouts>
    <x-slot:title>{{ trans('dreamlayer::app.editor') }}</x-slot>
    @include('dreamlayer::styles')
    <div class="dl-page text-gray-800 dark:text-gray-200">
        <div class="dl-heading"><h1>@lang('dreamlayer::app.editor')</h1><a class="secondary-button" href="{{ route('dreamlayer.settings') }}">@lang('dreamlayer::app.settings')</a><a class="secondary-button" href="{{ route('dreamlayer.results') }}">@lang('dreamlayer::app.results')</a></div>
        <p class="dl-muted">@lang('dreamlayer::app.editor-help')</p>
        @if(!\DreamLayer\UnoPIM\Models\Settings::current()->connected())
            <p class="dl-section"><a class="primary-button" href="{{ route('dreamlayer.settings') }}">@lang('dreamlayer::app.connect')</a></p>
        @else
            <v-dreamlayer-editor></v-dreamlayer-editor>
        @endif
    </div>
    @include('dreamlayer::workflow')
    @pushOnce('scripts')
    <script type="text/x-template" id="dreamlayer-editor-template">
        <div class="dl-editor-grid">
            <section class="dl-stack" :aria-label="labels.catalog">
                <h2 v-text="labels.catalog"></h2>
                <form @submit.prevent="search"><x-admin::form.control-group><x-admin::form.control-group.label for="dl-search">@lang('dreamlayer::app.search-sku')</x-admin::form.control-group.label><x-admin::form.control-group.control type="text" name="query" id="dl-search" v-model="query" maxlength="100" :placeholder="trans('dreamlayer::app.search-sku')" /></x-admin::form.control-group><button class="secondary-button mt-2" :disabled="loading" v-text="labels.search"></button></form>
                <p v-if="catalogError" class="dl-error" role="alert" v-text="catalogError"></p>
                <div v-for="product in products" :key="product.id" class="dl-result-card">
                    <strong v-text="product.name"></strong><span class="dl-muted" v-text="product.sku"></span>
                    <div class="dl-picker-images"><button v-for="image in product.images" :key="image.token" type="button" @click="toggle(image)" :aria-pressed="selected.some(i=>i.token===image.token)" :aria-label="labels.select+' '+product.sku" class="dl-image-choice"><img :src="image.image_url" :alt="product.name"><span v-text="image.attribute+' · '+(image.index+1)"></span><span v-if="selected.some(i=>i.token===image.token)">✓</span></button></div>
                </div>
                <p v-if="!loading && !products.length" v-text="labels['no-products']"></p>
                <button v-if="nextPage" class="secondary-button" @click="load(nextPage)" :disabled="loading" v-text="labels['load-more']"></button>
            </section>
            <section class="dl-stack" :aria-label="labels.conversation">
                <div class="dl-actions"><h2 v-text="labels.conversation"></h2><button class="secondary-button" @click="reset" :disabled="sending" v-text="labels['new-conversation']"></button></div>
                <p class="dl-muted" v-text="selectedResult ? labels['refining-result'] : selected.length ? labels['selected-count'].replace(':count',selected.length) : labels['select-image']"></p>
                <ol class="dl-stack" aria-live="polite"><li v-for="turn in turns" :key="turn.id" class="dl-stack">
                    <p class="dl-message" v-text="turn.message"></p><p v-if="turn.reply" v-text="turn.reply"></p>
                    <div v-for="id in turn.workflow_ids" :key="id" class="dl-stack"><v-dreamlayer-workflow :descriptor="{type:'dreamlayer.workflow',id}" @ready="ready(id)"></v-dreamlayer-workflow><button v-if="completed.includes(id)" type="button" class="secondary-button" @click="refine(id)" v-text="labels['refine-this']"></button></div>
                </li></ol>
                <form @submit.prevent="send" class="dl-stack">
                    <x-admin::form.control-group><x-admin::form.control-group.label for="dl-operation">@lang('dreamlayer::app.operation')</x-admin::form.control-group.label><x-admin::form.control-group.control type="select" name="operation" id="dl-operation" v-model="operation" :options="json_encode([['id'=>'image_to_image','label'=>trans('dreamlayer::app.edit')],['id'=>'background_remove','label'=>trans('dreamlayer::app.remove-background')],['id'=>'text_to_image','label'=>trans('dreamlayer::app.generate')]])" track-by="id" label-by="label" /></x-admin::form.control-group>
                    <x-admin::form.control-group><x-admin::form.control-group.label for="dl-instruction">@lang('dreamlayer::app.instruction')</x-admin::form.control-group.label><x-admin::form.control-group.control type="textarea" name="message" id="dl-instruction" v-model="message" maxlength="4000" rows="3" :placeholder="trans('dreamlayer::app.instruction-placeholder')" /></x-admin::form.control-group>
                    <p v-if="error" class="dl-error" role="alert" v-text="error"></p>
                    <button class="primary-button" :disabled="sending || !message.trim() || (selectedResult && !completed.includes(selectedResult))" v-text="sending ? labels.sending : labels['create-preview']"></button>
                    <p class="dl-muted" v-text="labels['approval-help']"></p>
                </form>
            </section>
        </div>
    </script>
    <script type="module">
    app.component('v-dreamlayer-editor', {
        template: '#dreamlayer-editor-template',
        data: () => ({ labels: @json(trans('dreamlayer::app')), products: [], selected: [], selectedResult: null, nextPage: null, query: '', loading: false, catalogError: null, message: '', operation: 'image_to_image', turns: [], completed: [], sending: false, error: null, conversationId: crypto.randomUUID(), pending: null }),
        mounted() { this.search(); },
        methods: {
            reset() { this.conversationId=crypto.randomUUID(); this.turns=[]; this.selected=[]; this.selectedResult=null; this.pending=null; this.error=null; },
            search() { this.products=[]; this.load(1); },
            async load(page) {
                this.loading=true; this.catalogError=null;
                try { const response=await this.$axios.get(@json(route('dreamlayer.catalog')), {params:{q:this.query,page,product_id:new URLSearchParams(location.search).get('product_id')||undefined}}); this.products.push(...response.data.products); this.nextPage=response.data.next_page; }
                catch(e) { this.catalogError=this.labels['catalog-error']; } finally { this.loading=false; }
            },
            toggle(image) { const i=this.selected.findIndex(x=>x.token===image.token); if(i>=0)this.selected.splice(i,1);else if(this.selected.length<5)this.selected.push(image);this.selectedResult=null;this.pending=null; },
            ready(id) { if(!this.completed.includes(id))this.completed.push(id); },
            refine(id) { this.selected=[];this.selectedResult=id;this.operation='image_to_image';this.pending=null; },
            async send() {
                if(this.sending)return;
                const input={conversation_id:this.conversationId,message:this.message,operation:this.operation,images:this.selected.map(i=>({token:i.token})),selected_result:this.selectedResult,
                    channel:@json(core()->getRequestedChannelCode()),locale:@json(core()->getRequestedLocaleCode()),history:this.turns.flatMap(t=>[{role:'user',content:t.message},...(t.reply?[{role:'assistant',content:t.reply}]:[])]).slice(-20)};
                const fingerprint=JSON.stringify(input);
                if(!this.pending||this.pending.fingerprint!==fingerprint)this.pending={fingerprint,request_id:crypto.randomUUID()};
                this.sending=true;this.error=null;
                try {
                    const response=await this.$axios.post(@json(route('dreamlayer.editor.send')),{...input,request_id:this.pending.request_id});
                    this.turns.push({id:this.pending.request_id,message:this.message,...response.data});this.message='';this.pending=null;
                    // Clear the original selection so a follow-up can use the completed result.
                    this.selected=[];if(response.data.workflow_ids.length===1)this.selectedResult=response.data.workflow_ids[0];
                } catch(e) { this.error=e.response?.data?.message || this.labels['request-error']; }
                finally {this.sending=false;}
            }
        }
    });
    </script>
    @endPushOnce
</x-admin::layouts>
