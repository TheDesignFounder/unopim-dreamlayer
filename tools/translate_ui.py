"""Generate draft UI translations from public English labels, never customer data.
Run explicitly with --refresh. Review language quality before publishing.
"""
import concurrent.futures, json, re, sys, time, urllib.parse, urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
LANG=ROOT/'resources/lang'
LOCALES={'ar_AE':'ar','ca_ES':'ca','da_DK':'da','de_DE':'de','en_AU':'en','en_GB':'en','en_NZ':'en','es_ES':'es','es_VE':'es','fi_FI':'fi','fr_FR':'fr','hi_IN':'hi','hr_HR':'hr','id_ID':'id','it_IT':'it','ja_JP':'ja','ko_KR':'ko','mn_MN':'mn','nl_NL':'nl','no_NO':'no','pl_PL':'pl','pt_BR':'pt','pt_PT':'pt-PT','ro_RO':'ro','ru_RU':'ru','sv_SE':'sv','tl_PH':'tl','tr_TR':'tr','uk_UA':'uk','vi_VN':'vi','zh_CN':'zh-CN','zh_TW':'zh-TW'}
def read():
    pairs=re.findall(r"'((?:\\.|[^'\\])*)'\s*=>\s*'((?:\\.|[^'\\])*)'",(LANG/'en_US/app.php').read_text())
    return {k:v.replace("\\'", "'").replace('\\\\','\\') for k,v in pairs}
def quote(s):return "'"+s.replace('\\','\\\\').replace("'","\\'")+"'"
def translate(target, source):
    cache=ROOT/'tools/translation-cache'/f'{target}.json';cache.parent.mkdir(exist_ok=True)
    saved=json.loads(cache.read_text()) if cache.exists() else {}
    missing=[v for v in dict.fromkeys(source.values()) if v not in saved]
    # Translate one label per request. Batch markers can cross sentence boundaries.
    for value in missing:
        tokens={}
        def hide(m):
            token=f'ZZPH{len(tokens)}ZZ';tokens[token]=m[0];return token
        protected=re.sub(r':[a-z_]+|DreamLayer|UnoPim|SKU|PNG|API',hide,value)
        url='https://translate.googleapis.com/translate_a/single?'+urllib.parse.urlencode({'client':'gtx','sl':'en','tl':target,'dt':'t','q':protected})
        for attempt in range(5):
            try:
                with urllib.request.urlopen(url,timeout=30) as r:data=json.load(r)
                result=''.join(x[0] for x in data[0] if x[0])
                batch=[value];parts=[result]
                for old,new in zip(batch,parts):
                    for token,original in tokens.items():
                        pattern=r'\s*'.join(re.escape(c) for c in token)
                        new=re.sub(pattern,lambda m:original,new,flags=re.I)
                    new=new.strip()
                    if not new or sorted(re.findall(r':(?:count|name|time|status)\b',old))!=sorted(re.findall(r':(?:count|name|time|status)\b',new)) or re.search('ZZPH',new,re.I):raise ValueError('Translation placeholders changed')
                    saved[old]=new
                cache.write_text(json.dumps(saved,ensure_ascii=False,indent=2));break
            except Exception:
                if attempt==4:raise
                time.sleep(60 if getattr(sys.exception(), 'code', None) == 429 else 2+attempt*2)
        time.sleep(2)
    return {k:saved[v] for k,v in source.items()}
def main():
    if '--refresh' not in sys.argv:raise SystemExit('Use --refresh to translate public UI labels.')
    source=read();targets=sorted(set(LOCALES.values())-{'en'});translations={'en':source}
    with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
        futures={pool.submit(translate,t,source):t for t in targets}
        for f in concurrent.futures.as_completed(futures):
            target=futures[f]
            try: translations[target]=f.result();print('Translated',target,flush=True)
            except Exception as e: print('FAILED',target,str(e),flush=True)
    if set(targets)-translations.keys(): raise SystemExit('Some translations need repair; caches retained.')
    for locale,target in LOCALES.items():
        path=LANG/locale/'app.php';path.parent.mkdir(exist_ok=True)
        path.write_text('<?php\n\n// Draft translation; see docs/TRANSLATIONS.md.\nreturn [\n'+''.join('    '+quote(k)+' => '+quote(v)+',\n' for k,v in translations[target].items())+'];\n')
    print('Wrote',len(LOCALES)+1,'locales with',len(source),'keys',flush=True)
if __name__=='__main__':main()
