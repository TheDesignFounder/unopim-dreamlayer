@if(bouncer()->hasPermission('catalog.products.edit') && bouncer()->hasPermission('dreamlayer_results.choose'))
@pushOnce('scripts')
<script>
(() => {
    if(window.dreamlayerStudio){window.dreamlayerStudio.sync(true);return;}
    const base=@json(url(config('app.admin_url').'/dreamlayer'));
    const panelKey=@json('dreamlayer-panel:'.hash_hmac('sha256', auth()->guard('admin')->id().'|'.request()->session()->getId(), config('app.key')));
    const labels=@json(trans('dreamlayer::studio'));
    let open=false,frame=null,lastHost=base+'/settings',lastProduct=null;
    try{const previous=JSON.parse(sessionStorage.getItem(panelKey)||'null');open=!!previous?.open;lastHost=previous?.lastHost||lastHost;}catch(e){}
    const style=document.createElement('style');style.id='dreamlayer-shell-style';style.textContent=`
        #dreamlayer-shell{position:fixed;right:16px;top:88px;bottom:20px;width:min(460px,calc(100vw - 24px));z-index:45;border:1px solid #dfe3ea;border-radius:18px;overflow:hidden;box-shadow:0 16px 64px #11182730;background:white}
        #dreamlayer-shell[hidden],#dreamlayer-launcher[hidden]{display:none!important}
        #dreamlayer-shell iframe{border:0;width:100%;height:100%;display:block;background:white}
        #dreamlayer-shell.expanded{border-radius:14px;box-shadow:none;bottom:auto;z-index:15}
        #dreamlayer-launcher{position:fixed;right:24px;bottom:84px;z-index:44;border:1px solid #bfceec;border-radius:28px;padding:12px 18px;background:#fff;color:#2563eb;box-shadow:0 6px 28px #17295d22;font:600 14px/1.5 Inter,system-ui,sans-serif;cursor:pointer;display:flex;align-items:center;gap:8px}
        #dreamlayer-launcher:focus-visible{outline:3px solid #2563eb;outline-offset:4px}
        .dark #dreamlayer-shell{border-color:#48465f;background:#1e1d30}.dark #dreamlayer-launcher{background:#29283e;color:#c8b4ff;border-color:#615082}
        @media(max-width:600px){#dreamlayer-shell{inset:8px;width:auto;z-index:1001;border-radius:14px}#dreamlayer-launcher{right:14px;bottom:80px;padding:10px 14px}#dreamlayer-shell.expanded{z-index:15}}
    `;document.head.append(style);
    const shell=document.createElement('section');shell.id='dreamlayer-shell';shell.hidden=true;shell.setAttribute('aria-label',labels.brand);
    const launch=document.createElement('button');launch.id='dreamlayer-launcher';launch.type='button';launch.textContent='◇ '+labels.brand;launch.setAttribute('aria-label',labels['open-studio']);launch.setAttribute('aria-expanded','false');
    document.body.append(shell,launch);
    function save(){try{sessionStorage.setItem(panelKey,JSON.stringify({open,lastHost}));}catch(e){}}
    function product(){const query=new URLSearchParams(location.search).get('product_id');const match=location.pathname.match(/\/catalog\/products\/edit\/(\d+)/);return query||match?.[1]||null;}
    function context(refresh=false){frame?.contentWindow?.postMessage({dreamlayer:true,action:'context',productId:product(),expanded:!!document.getElementById('dreamlayer-editor-mount'),dark:document.documentElement.classList.contains('dark'),refresh},location.origin);}
    function sync(refresh=false){
        const mount=document.getElementById('dreamlayer-editor-mount');
        if(mount){open=true;const rect=mount.getBoundingClientRect();mount.style.height=Math.max(300,innerHeight-rect.top-20)+'px';shell.classList.add('expanded');Object.assign(shell.style,{left:rect.left+'px',top:rect.top+'px',width:rect.width+'px',height:Math.max(300,innerHeight-rect.top-20)+'px',right:'auto',bottom:'auto'});}
        else{shell.classList.remove('expanded');shell.removeAttribute('style');}
        if(open&&!frame){frame=document.createElement('iframe');frame.title=labels.brand+' — '+labels['open-studio'];frame.src=base+'/studio';frame.setAttribute('allow','clipboard-write');shell.append(frame);}
        shell.hidden=!open;launch.hidden=open||!!mount;launch.setAttribute('aria-expanded',String(open));
        const nowProduct=product();context(refresh||nowProduct!==lastProduct);lastProduct=nowProduct;save();
    }
    function navigate(url){const target=new URL(url,location.origin);if(target.origin!==location.origin||!target.pathname.startsWith(@json('/'.trim(config('app.admin_url'), '/').'/')))return;save();if(window.unopim?.visit)window.unopim.visit(target.href);else location.assign(target.href);}
    launch.addEventListener('click',()=>{open=true;sync(true);});
    window.addEventListener('message',event=>{
        if(event.origin!==location.origin||event.source!==frame?.contentWindow||!event.data?.dreamlayer)return;
        const action=event.data.action;
        if(action==='ready'){context(true);return;}
        if(action==='expand'){lastHost=location.href;navigate(base+'/choose');}
        if(action==='collapse'){open=true;navigate(lastHost);}
        if(action==='close'){open=false;if(document.getElementById('dreamlayer-editor-mount'))navigate(lastHost);else sync();launch.focus();}
        if(action==='navigate'){navigate(event.data.url);}
    });
    window.addEventListener('unopim:navigate:success',()=>sync(true));
    window.addEventListener('resize',()=>sync());
    document.addEventListener('scroll',()=>{if(document.getElementById('dreamlayer-editor-mount'))sync();},true);
    new MutationObserver(()=>context()).observe(document.documentElement,{attributes:true,attributeFilter:['class']});
    const observer=new MutationObserver(()=>{const mount=document.getElementById('dreamlayer-editor-mount');if(!!mount!==shell.classList.contains('expanded'))sync();});observer.observe(document.getElementById('app'),{childList:true,subtree:true});
    function logout(){frame?.contentWindow?.postMessage({dreamlayer:true,action:'logout'},location.origin);for(let i=sessionStorage.length-1;i>=0;i--){const key=sessionStorage.key(i);if(key.startsWith('dreamlayer-studio:')||key.startsWith('dreamlayer-panel:'))sessionStorage.removeItem(key);}}
    document.addEventListener('click',e=>{if(e.target.closest('a[href*="logout"]'))logout();},true);
    document.addEventListener('submit',e=>{if(e.target.action?.includes('logout'))logout();},true);
    window.dreamlayerStudio={sync};requestAnimationFrame(()=>sync(true));
})();
</script>
@endPushOnce
@endif
