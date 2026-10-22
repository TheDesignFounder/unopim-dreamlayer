@pushOnce('scripts')
<style>
.dl-result-dialog{border:1px solid var(--dl-border);border-radius:14px;padding:24px;width:min(850px,92vw);max-height:90vh;overflow:auto;background:Canvas;color:CanvasText}.dl-result-dialog img[alt="{{ trans('dreamlayer::app.ui-953da4c231') }}"]{max-height:60vh!important}.dl-result-dialog::backdrop{background:color-mix(in srgb,CanvasText 60%,transparent)}.dark .dl-result-dialog{background:Canvas;color:CanvasText}
</style>
<script type="text/x-template" id="dl-library-preview">
<div>
<button v-if="imageUrl" type="button" style="width:100%" :aria-label="labels['open-preview'].replace(':name',title)" @click="show"><img :src="imageUrl" :alt="title" style="width:100%;height:190px;object-fit:contain" loading="lazy"></button>
<button type="button" class="secondary-button mt-3" style="font-size:14px;padding:9px 14px;line-height:20px;display:inline-flex;width:auto" @click="show">@lang('dreamlayer::app.ui-3ff97c7a84')</button>
<teleport to="body"><dialog ref="dialog" class="dl-result-dialog" @close="open=false"><div style="display:flex;justify-content:space-between;gap:16px;margin-bottom:16px"><h2 class="font-semibold" v-text="title"></h2><button type="button" aria-label="{{ trans('dreamlayer::app.ui-eb335c7f98') }}" class="secondary-button" @click="$refs.dialog.close()">@lang('dreamlayer::app.ui-7d9eb7acb1')</button></div><v-dreamlayer-workflow v-if="open" :descriptor="descriptor" :visible="open"></v-dreamlayer-workflow></dialog></teleport>
</div>
</script>
<script type="module">
app.component('v-dreamlayer-library-preview',{template:'#dl-library-preview',props:['descriptor','title','imageUrl'],data:()=>({labels:@json(trans('dreamlayer::app')),open:false}),methods:{show(){this.open=true;this.$nextTick(()=>this.$refs.dialog.showModal())}}});
</script>
@endPushOnce
