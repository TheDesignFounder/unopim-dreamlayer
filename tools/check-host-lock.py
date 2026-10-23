"""Fail if adding the extension changed any existing locked host package."""
import json,sys
old,new=(json.load(open(p)) for p in sys.argv[1:])
def packages(d):return {p['name']:(p['version'],p.get('source',{}).get('reference'),p.get('dist',{}).get('reference')) for p in d.get('packages',[])+d.get('packages-dev',[])}
a,b=packages(old),packages(new)
changed=[k for k,v in a.items() if b.get(k)!=v]
added=set(b)-set(a)
assert not changed,changed
assert added <= {'dreamlayer/unopim-dreamlayer'},added
print('Verified:',len(a),'host packages unchanged; additions:',sorted(added))
