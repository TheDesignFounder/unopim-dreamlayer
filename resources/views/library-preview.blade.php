@pushOnce('scripts')
<style>
.dl-result-dialog{border:1px solid #8886;border-radius:14px;padding:24px;width:min(850px,92vw);max-height:90vh;overflow:auto;background:white;color:#20212b}.dl-result-dialog img[alt="DreamLayer image preview"]{max-height:60vh!important}.dl-result-dialog::backdrop{background:#0009}.dark .dl-result-dialog{background:#211b31;color:#eee}
</style>
<script type="text/x-template" id="dl-library-preview">
<div>
<button v-if="imageUrl" type="button" style="width:100%" :aria-label="'Open '+title+' preview'" @click="show"><img :src="imageUrl" :alt="title+' preview'" style="width:100%;height:190px;object-fit:contain" loading="lazy"></button>
<button type="button" class="secondary-button mt-3" style="font-size:14px;padding:9px 14px;line-height:20px;display:inline-flex;width:auto" @click="show">Review preview</button>
<teleport to="body"><dialog ref="dialog" class="dl-result-dialog" @close="open=false"><div style="display:flex;justify-content:space-between;gap:16px;margin-bottom:16px"><h2 class="font-semibold" v-text="title"></h2><button type="button" aria-label="Close result preview" class="secondary-button" @click="$refs.dialog.close()">Close</button></div><v-dreamlayer-workflow v-if="open" :descriptor="descriptor" :visible="open"></v-dreamlayer-workflow></dialog></teleport>
</div>
</script>
<script type="module">
app.component('v-dreamlayer-library-preview',{template:'#dl-library-preview',props:['descriptor','title','imageUrl'],data:()=>({open:false}),methods:{show(){this.open=true;this.$nextTick(()=>this.$refs.dialog.showModal())}}});
</script>
@endPushOnce
