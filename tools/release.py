"""Dependency-free release packaging. Run from any directory with Python 3.10+."""
import argparse
import hashlib
import io
import os
from pathlib import Path
import re
import subprocess
import tarfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'dist' / 'releases'
VERSION = (ROOT / 'VERSION').read_text().strip()
DIRECTORIES = {'.github', 'apps', 'packages', 'content', 'contracts', 'docs', 'infra', 'tests', 'tools', 'LICENSES'}
FILES = {'.gitignore', '.gitattributes', '.dockerignore', '.editorconfig', 'README.md', 'VERSION', 'LICENSE', 'THIRD_PARTY_NOTICES.md', 'CHANGELOG.md', 'CONTRIBUTING.md', 'SECURITY.md', 'Play.cmd'}
SKIP = {'.git', '.tools', '.agents', '.codex', 'vendor', 'node_modules', '__pycache__', 'backups', 'data', 'bin', 'lib', '.phpunit.cache'}
SUFFIXES = {'.sql', '.sqlite', '.key', '.pem', '.log', '.pyc', '.ppu', '.o', '.obj', '.or', '.compiled', '.lps', '.bak', '.exe', '.dll'}

def allowed(path):
    p = Path(path)
    if any(part in SKIP for part in p.parts):
        return False
    if p.name.startswith('.env'):
        return p.name.endswith('.example')
    if p.suffix.lower() in SUFFIXES or p.name in {'auth.json', '.DS_Store', 'Thumbs.db'} or '.sqlite-' in p.name:
        return False
    s = p.as_posix()
    if '/storage/' in s:
        return p.name == '.gitignore'
    if s.startswith('apps/server/bootstrap/cache/'):
        return p.name == '.gitignore'
    return True

def source_files():
    for child in sorted(ROOT.iterdir()):
        if child.name in FILES and child.is_file():
            yield child
        elif child.name in DIRECTORIES and child.is_dir():
            for directory, dirs, names in os.walk(child, followlinks=False):
                dirs[:] = sorted(d for d in dirs if d not in SKIP and not (Path(directory) / d).is_symlink())
                for name in sorted(names):
                    f = Path(directory) / name
                    if not f.is_symlink() and allowed(f.relative_to(ROOT)):
                        yield f

def notices():
    return {p.relative_to(ROOT).as_posix(): p.read_bytes() for p in source_files()
            if p.name in {'LICENSE', 'THIRD_PARTY_NOTICES.md', 'VERSION'} or p.parts[len(ROOT.parts)] == 'LICENSES'}

def archive(name, entries, linux=False):
    entries = dict(entries)
    sums = ''.join(f'{hashlib.sha256(data).hexdigest()}  {path}\n' for path, data in sorted(entries.items()))
    entries['SHA256SUMS.txt'] = sums.encode()
    OUT.mkdir(parents=True, exist_ok=True)
    target = OUT / (name + ('.tar.gz' if linux else '.zip'))
    if linux:
        with tarfile.open(target, 'w:gz') as tf:
            for path, data in sorted(entries.items()):
                info = tarfile.TarInfo(name + '/' + path)
                info.size = len(data)
                info.mode = 0o755 if path == 'grim-hollow' or path.endswith('.sh') else 0o644
                info.mtime = int(os.getenv('SOURCE_DATE_EPOCH', '0'))
                tf.addfile(info, io.BytesIO(data))
    else:
        with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED) as zf:
            for path, data in sorted(entries.items()):
                zf.writestr(name + '/' + path, data)
    print(target)

def check(tag=None):
    if not re.fullmatch(r'\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?', VERSION):
        raise SystemExit('Invalid VERSION')
    if tag and tag != 'v' + VERSION:
        raise SystemExit(f'Tag {tag} does not match VERSION {VERSION}')
    for path in FILES:
        if not (ROOT / path).is_file():
            raise SystemExit('Missing release file: ' + path)
    if (ROOT / '.git').exists():
        tracked = subprocess.check_output(['git', 'ls-files', '-z'], cwd=ROOT).decode().split('\0')
        bad = [p for p in tracked if p and (Path(p).parts[0] not in DIRECTORIES | FILES or not allowed(p))]
        if bad:
            raise SystemExit('Unexpected/private/generated tracked files: ' + ', '.join(bad))
    print('Release layout OK: ' + VERSION)

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('kind', choices=['check', 'source', 'server', 'client', 'checksums'])
    parser.add_argument('--tag')
    parser.add_argument('--platform', choices=['windows-x64', 'linux-x64'])
    parser.add_argument('--binary', type=Path)
    args = parser.parse_args()
    check(args.tag)
    name = 'GrimHollow-' + VERSION
    if args.kind == 'source':
        archive(name + '-source', {p.relative_to(ROOT).as_posix(): p.read_bytes() for p in source_files()})
    elif args.kind == 'server':
        entries = notices()
        for path in ['infra/compose/production.yaml', 'infra/compose/.env.production.example', 'infra/nginx/game.conf', 'infra/caddy/Caddyfile', 'docs/16-distribution.md']:
            entries[path] = (ROOT / path).read_bytes()
        entries['README.md'] = b'Server deployment bundle. Read docs/16-distribution.md. Use the published GAME_IMAGE and compose up --no-build. No secrets or database are bundled.\n'
        archive(name + '-server', entries, linux=True)
    elif args.kind == 'client':
        if not args.platform or not args.binary or not args.binary.is_file():
            parser.error('client requires --platform and an existing --binary')
        linux = args.platform == 'linux-x64'
        entries = notices()
        entries['grim-hollow' if linux else 'GrimHollow.exe'] = args.binary.read_bytes()
        entries['README-ru.md'] = (ROOT / 'docs/17-player-guide.md').read_bytes()
        archive(name + '-' + args.platform, entries, linux=linux)
    elif args.kind == 'checksums':
        files = sorted(p for p in OUT.glob(name + '-*') if p.is_file() and p.name.endswith(('.zip', '.tar.gz')))
        if not files:
            raise SystemExit('No release archives found')
        (OUT / 'SHA256SUMS.txt').write_text(''.join(hashlib.sha256(p.read_bytes()).hexdigest() + '  ' + p.name + '\n' for p in files), encoding='utf8')

if __name__ == '__main__':
    main()
