"""Translate only new studio labels and the two revised storage explanations.
Public UI copy only. Existing reviewed app translations are otherwise preserved.
"""
import concurrent.futures, importlib.util, json, re, sys, time, urllib.parse, urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('old', ROOT/'tools/translate_ui.py');old=importlib.util.module_from_spec(spec);spec.loader.exec_module(old)
def read(file):
    return {k:v.replace("\\'", "'").replace('\\\\','\\') for k,v in re.findall(r"'((?:\\.|[^'\\])*)'\s*=>\s*'((?:\\.|[^'\\])*)'",file.read_text())}
source=read(ROOT/'resources/lang/en_US/studio.php');app=read(ROOT/'resources/lang/en_US/app.php');updated={k:app[k] for k in ['setting-save_chat_history-help','privacy-storage']}
alltext={**source,**updated}
def translate(target):
    cache=ROOT/'tools/translation-cache'/f'studio-{target}.json';cache.parent.mkdir(exist_ok=True)
    saved=json.loads(cache.read_text()) if cache.exists() else {}
    missing=[v for v in dict.fromkeys(alltext.values()) if v not in saved]
    while missing:
        batch=missing[:12];tokens={}
        def hide(m):
            token=f'ZZPH{len(tokens)}ZZ';tokens[token]=m[0];return token
        protected=[re.sub(r':[a-z_]+|DreamLayer|UnoPim|SKU|PNG|API',hide,v) for v in batch]
        query='\n'.join(f'{i+1}. {v}' for i,v in enumerate(protected))
        url='https://translate.googleapis.com/translate_a/single?'+urllib.parse.urlencode({'client':'gtx','sl':'en','tl':target,'dt':'t','q':query})
        for attempt in range(5):
            try:
                with urllib.request.urlopen(url,timeout=25) as response:data=json.load(response)
                result=''.join(x[0] for x in data[0] if x[0])
                parts=re.split(r'(?m)^\s*\d+[.、)．]\s*',result)[1:]
                if len(parts)!=len(batch):raise ValueError('Changed label boundaries')
                for value,part in zip(batch,parts):
                    for token,original in tokens.items():part=re.sub(r'\s*'.join(re.escape(c) for c in token),lambda _:original,part,flags=re.I)
                    part=' '.join(part.split()).strip()
                    if not part or re.search('ZZPH',part,re.I) or sorted(re.findall(r':[a-z_]+',value))!=sorted(re.findall(r':[a-z_]+',part)):raise ValueError('Changed placeholders')
                    saved[value]=part
                cache.write_text(json.dumps(saved,ensure_ascii=False,indent=2));break
            except Exception:
                if attempt==4:raise
                time.sleep(3+attempt*3)
        missing=missing[len(batch):];time.sleep(1)
    return {k:saved[v] for k,v in alltext.items()}
if '--refresh' not in sys.argv:raise SystemExit('Use --refresh to translate public labels')
translations={'en':alltext}
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    futures={pool.submit(translate,target):target for target in sorted(set(old.LOCALES.values())-{'en'})}
    for future in concurrent.futures.as_completed(futures):
        target=futures[future]
        try:translations[target]=future.result();print('Translated',target,flush=True)
        except Exception as exc:print('FAILED',target,str(exc),flush=True)
if set(old.LOCALES.values())-translations.keys():raise SystemExit('Incomplete translations; caches kept')
for locale,target in old.LOCALES.items():
    values=translations[target];directory=ROOT/'resources/lang'/locale
    (directory/'studio.php').write_text('<?php\n\n// Draft translation; see docs/TRANSLATIONS.md.\nreturn [\n'+''.join('    '+old.quote(k)+' => '+old.quote(values[k])+',\n' for k in source)+'];\n')
    file=directory/'app.php';body=file.read_text()
    for key in updated:
        body=re.sub(r"'"+re.escape(key)+r"'\s*=>\s*'(?:\\.|[^'\\])*'",lambda m:old.quote(key)+' => '+old.quote(values[key]),body)
    file.write_text(body)
print('Wrote only new studio dictionaries and two updated explanations',flush=True)
