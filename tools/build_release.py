"""Build only tracked install/runtime files from HEAD, without fixtures or local logs."""
import json
import subprocess
import zipfile
from pathlib import Path

root = Path(__file__).resolve().parents[1]
def git(*args):
    return subprocess.check_output(['git', '-C', str(root), *args])

manifest = json.loads(git('show', 'HEAD:composer.json'))
files = git('ls-tree', '-r', '--name-only', 'HEAD').decode().splitlines()
prefixes = ('src/', 'config/', 'routes/', 'resources/', 'database/', 'patches/')
standalone = {'composer.json', 'README.md', 'NOTICE', 'LICENSE', 'NEW_USER_TEST.md', 'docs/EXPERIENCE_0_2.md', 'docs/CORE_PATCH.md', 'docs/LEGACY_CORE_PATCH.md', 'docs/REVIEWING.md', 'docs/FRESH_INSTALL_TEST.md', 'docs/WINDOWS_INSTALL.md', 'docs/HOST_PREREQUISITE.md', 'docs/API_CONTRACT_V1.md', 'docs/BACKEND_SETUP.md', 'docs/OPERATIONS.md', 'docs/workflow-v1.schema.json', 'docs/VERIFICATION_0_2_16.md', 'tools/check-host.php'}
selected = sorted(p for p in files if p.startswith(prefixes) or p in standalone)
output = root / 'dist' / ('unopim-dreamlayer-' + manifest['version'] + '.zip')
output.parent.mkdir(exist_ok=True)
with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
    for path in selected:
        info = zipfile.ZipInfo(path, (2026, 10, 11, 0, 0, 0))
        info.external_attr = 0o100644 << 16
        archive.writestr(info, git('show', 'HEAD:' + path), compress_type=zipfile.ZIP_DEFLATED)
with zipfile.ZipFile(output) as archive:
    assert sorted(archive.namelist()) == selected
    for path in selected:
        assert archive.read(path) == git('show', 'HEAD:' + path)
print(output)
print('Verified', len(selected), 'tracked files at', git('rev-parse', 'HEAD').decode().strip())
