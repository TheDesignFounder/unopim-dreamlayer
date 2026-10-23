@if(bouncer()->hasPermission('catalog.products.edit') && bouncer()->hasPermission('dreamlayer_results.choose'))
@pushOnce('scripts')
<script>
(() => {
    const base=@json(url(config('app.admin_url').'/dreamlayer'));
    const panelKey=@json('dreamlayer-panel:'.hash_hmac('sha256', auth()->guard('admin')->id().'|'.request()->session()->getId(), config('app.key')));
    if(window.dreamlayerStudio?.key===panelKey){window.dreamlayerStudio.sync(true);return;}
    window.dreamlayerStudio?.destroy();
    const listeners=new AbortController(),listen=(target,type,fn,options={})=>target.addEventListener(type,fn,{...options,signal:listeners.signal});
    const loginPath=new URL(@json(route('admin.session.create')),location.origin).pathname;
    const logoutPath=new URL(@json(route('admin.session.destroy')),location.origin).pathname;
    const labels=@json(trans('dreamlayer::studio'));
    let open=false,frame=null,lastHost=base+'/settings',lastProduct=null;
    try{const previous=JSON.parse(sessionStorage.getItem(panelKey)||'null');open=!!previous?.open;lastHost=previous?.lastHost||lastHost;}catch(e){}
    const style=document.createElement('style');style.id='dreamlayer-shell-style';style.textContent=`
        #dreamlayer-shell{position:fixed;right:0;top:0;bottom:0;width:420px;max-width:100vw;z-index:10000;border:0;border-left:1px solid #e5e7eb;border-radius:0;overflow:hidden;box-shadow:none;background:white}
        body.dreamlayer-docked #app{margin-right:420px;contain:paint;transition:none}
        body.dreamlayer-expanded #app{transition:none}
        @media(min-width:1025px){body.dreamlayer-docked .unsaved-bar{right:420px}}
        #dreamlayer-shell[hidden],#dreamlayer-launcher[hidden]{display:none!important}
        #dreamlayer-shell iframe{border:0;width:100%;height:100%;display:block;background:white}
        #dreamlayer-shell.expanded{border:0;border-radius:0;box-shadow:none;bottom:auto;z-index:15}
        #dreamlayer-launcher{position:fixed;right:24px;bottom:84px;z-index:44;border:1px solid #bfceec;border-radius:28px;padding:12px 18px;background:#fff;color:#2563eb;box-shadow:0 6px 28px #17295d22;font:600 14px/1.5 Inter,system-ui,sans-serif;cursor:pointer;display:flex;align-items:center;gap:8px}
        #dreamlayer-launcher:focus-visible{outline:3px solid #2563eb;outline-offset:4px}
        .dark #dreamlayer-shell{border-color:#453c5f;background:#1f1b2d}.dark #dreamlayer-launcher{background:#29283e;color:#c8b4ff;border-color:#615082}
        @media(max-width:1024px){body.dreamlayer-docked #app{margin-right:0;contain:none}#dreamlayer-shell:not(.expanded){inset:0;width:100vw;max-width:100vw;border:0;z-index:10002}}
        @media(max-width:600px){#dreamlayer-launcher{right:14px;bottom:80px;padding:10px 14px}}
    `;document.head.append(style);
    const shell=document.createElement('section');shell.id='dreamlayer-shell';shell.hidden=true;shell.setAttribute('aria-label',labels.brand);
    const launch=document.createElement('button');launch.id='dreamlayer-launcher';launch.type='button';const logo=document.createElement('img');logo.src=@json(\DreamLayer\UnoPIM\Support\Brand::logo());logo.alt='';logo.width=25;logo.height=20;launch.append(logo,document.createTextNode(labels.brand));launch.setAttribute('aria-label',labels['open-studio']);launch.setAttribute('aria-expanded','false');
    document.body.append(shell,launch);
    function save(){try{sessionStorage.setItem(panelKey,JSON.stringify({open,lastHost}));}catch(e){}}
    function product(){const query=new URLSearchParams(location.search).get('product_id');const match=location.pathname.match(/\/catalog\/products\/edit\/(\d+)/);return query||match?.[1]||null;}
    function context(refresh=false){frame?.contentWindow?.postMessage({dreamlayer:true,action:'context',productId:product(),expanded:!!document.getElementById('dreamlayer-editor-mount'),dark:document.documentElement.classList.contains('dark'),refresh},location.origin);}
    function sync(refresh=false){
        if(location.pathname===loginPath){destroy();return;}
        const mount=document.getElementById('dreamlayer-editor-mount');
        if(mount)open=true;
        document.body.classList.toggle('dreamlayer-docked',open&&!mount);
        document.body.classList.toggle('dreamlayer-expanded',!!mount);
        if(mount){open=true;const rect=mount.getBoundingClientRect();mount.style.height=Math.max(300,innerHeight-rect.top-20)+'px';shell.classList.add('expanded');Object.assign(shell.style,{left:rect.left+'px',top:rect.top+'px',width:rect.width+'px',height:Math.max(300,innerHeight-rect.top-20)+'px',right:'auto',bottom:'auto'});}
        else{shell.classList.remove('expanded');shell.removeAttribute('style');}
        if(open&&!frame){frame=document.createElement('iframe');frame.title=labels.brand+' — '+labels['open-studio'];frame.src=base+'/studio';frame.setAttribute('allow','clipboard-write');shell.append(frame);}
        shell.hidden=!open;launch.hidden=open||!!mount;launch.setAttribute('aria-expanded',String(open));
        const nowProduct=product();context(refresh||nowProduct!==lastProduct);lastProduct=nowProduct;save();
    }
    function navigate(url){const target=new URL(url,location.origin);if(target.origin!==location.origin||!target.pathname.startsWith(@json('/'.trim(config('app.admin_url'), '/').'/')))return;save();if(window.unopim?.visit)window.unopim.visit(target.href);else location.assign(target.href);}
    listen(launch,'click',()=>{open=true;sync(true);});
    listen(window,'message',event=>{
        if(event.origin!==location.origin||event.source!==frame?.contentWindow||!event.data?.dreamlayer)return;
        const action=event.data.action;
        if(action==='expired'){destroy();return;}
        if(action==='ready'){context(true);return;}
        if(action==='expand'){lastHost=location.href;navigate(base+'/choose');}
        if(action==='collapse'){open=true;navigate(lastHost);}
        if(action==='close'){open=false;if(document.getElementById('dreamlayer-editor-mount'))navigate(lastHost);else sync();launch.focus();}
        if(action==='navigate'){navigate(event.data.url);}
    });
    listen(window,'unopim:navigate:success',()=>sync(true));
    listen(window,'resize',()=>sync());
    listen(document,'scroll',()=>{if(document.getElementById('dreamlayer-editor-mount'))sync();},{capture:true});
    const themeObserver=new MutationObserver(()=>context());themeObserver.observe(document.documentElement,{attributes:true,attributeFilter:['class']});
    const observer=new MutationObserver(()=>{const mount=document.getElementById('dreamlayer-editor-mount');if(!!mount!==shell.classList.contains('expanded'))sync();});observer.observe(document.getElementById('app'),{childList:true,subtree:true});
    function destroy(){
        frame?.contentWindow?.postMessage({dreamlayer:true,action:'logout'},location.origin);
        open=false;frame?.remove();frame=null;
        for(let i=sessionStorage.length-1;i>=0;i--){const key=sessionStorage.key(i);if(key.startsWith('dreamlayer-studio:')||key.startsWith('dreamlayer-panel:'))sessionStorage.removeItem(key);}
        shell.remove();launch.remove();style.remove();
        document.body.classList.remove('dreamlayer-docked','dreamlayer-expanded');listeners.abort();observer.disconnect();themeObserver.disconnect();delete window.dreamlayerStudio;
    }
    listen(document,'click',e=>{const link=e.target.closest('a[href]');if(link&&new URL(link.href,location.origin).pathname===logoutPath)destroy();},{capture:true});
    listen(document,'submit',e=>{if(e.target.action&&new URL(e.target.action,location.origin).pathname===logoutPath)destroy();},{capture:true});
    window.dreamlayerStudio={key:panelKey,sync,destroy};requestAnimationFrame(()=>sync(true));
})();
</script>
@endPushOnce
@endif
