"""Build a deterministic distribution from the committed tree and export-ignore rules."""
import hashlib, json, subprocess, zipfile
from pathlib import Path
root=Path(__file__).resolve().parents[1]
def git(*args):return subprocess.check_output(['git','-C',str(root),*args])
if git('status','--porcelain').strip():raise SystemExit('Commit the reviewed changes before building a distribution.')
manifest=json.loads(git('show','HEAD:composer.json'));out=root/'dist'/('unopim-dreamlayer-'+manifest['version']+'.zip');out.parent.mkdir(exist_ok=True)
subprocess.run(['git','-C',str(root),'archive','--format=zip','--output='+str(out),'HEAD'],check=True)
with zipfile.ZipFile(out) as z:
    files=z.namelist()
    assert 'composer.json' in files and 'README.md' in files
    assert not any(p.startswith(('tests/','tools/','.github/','patches/','dist/')) or p=='NEW_USER_TEST.md' for p in files)
    assert not any('LEGACY_CORE_PATCH' in p for p in files)
print(out);print('SHA256',hashlib.sha256(out.read_bytes()).hexdigest());print('Commit',git('rev-parse','HEAD').decode().strip());print('Files',len(files))
