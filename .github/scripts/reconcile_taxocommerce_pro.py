#!/usr/bin/env python3
"""Compare GitHub deployment ZIPs with PRO and delete only explicitly approved files.

No production file is removed during the default (plan) run. The approval
fingerprint covers the source commit, ZIP contents and exact remote file hashes.
"""

import argparse
import ftplib
import hashlib
import json
import os
import posixpath
import re
import subprocess
import zipfile
from pathlib import Path

ROOT = './wp-content/plugins'
OUTPUT = Path('.taxocommerce-cleanup')
SAFE_SEGMENT = re.compile(r'^[A-Za-z0-9._+@-]+$')
SAFE_MODULE = re.compile(r'^taxocommerce-[a-z0-9-]+$')
PROTECTED_DIRS = frozenset({
    'uploads', 'upload', 'data', 'storage', 'cache', 'caches', 'logs', 'log',
    'backup', 'backups', 'tmp', 'temp', 'custom', 'node_modules', 'config',
    'secrets', 'vendor', '.git',
})
MAX_FILE = 2 * 1024 * 1024
MAX_TOTAL = 16 * 1024 * 1024
MAX_CANDIDATES = 120


def canonical(value):
    return json.dumps(value, ensure_ascii=True, sort_keys=True, separators=(',', ':')).encode('utf-8')


def fingerprint(value):
    return hashlib.sha256(canonical(value)).hexdigest()


def safe_relative(path):
    return bool(path and all(SAFE_SEGMENT.fullmatch(p) and p not in ('.', '..')
                             for p in path.split('/')))


def protected(path, module):
    pieces = path.split('/')
    if not safe_relative(path):
        return 'unsafe filename'
    if path in (module + '.php', 'uninstall.php'):
        return 'critical plugin lifecycle file'
    for part in pieces:
        if part.lower() in PROTECTED_DIRS:
            return 'protected runtime directory'
        if part.startswith('.') and part != '.ftp-deploy-sync-state.json':
            return 'hidden/configuration file'
    return ''


def installed_modules():
    modules = json.loads(os.environ['INSTALLED_MODULES_JSON'])
    if not isinstance(modules, list) or len(set(modules)) != len(modules):
        raise ValueError('Invalid or repeated installed modules')
    if not all(isinstance(m, str) and SAFE_MODULE.fullmatch(m) for m in modules):
        raise ValueError('Unexpected module slug in installed modules')
    return sorted(modules)


def source_files(module):
    result = subprocess.check_output(['git', 'ls-files', '-z', '--', module])
    prefix = (module + '/').encode('utf-8')
    return {p[len(prefix):].decode('utf-8') for p in result.split(b'\0') if p.startswith(prefix)}


def expected_package(module):
    path = Path('/tmp') / (module + '.zip')
    if not path.is_file():
        raise FileNotFoundError('Missing deployed package: ' + str(path))
    with zipfile.ZipFile(path) as z:
        info = [i for i in z.infolist() if not i.is_dir()]
        names = [i.filename for i in info]
        if not names or len(set(names)) != len(names) or not all(safe_relative(n) for n in names):
            raise ValueError('Unsafe, empty or duplicate ZIP entries for ' + module)
        if module + '.php' not in names:
            raise ValueError('Main plugin PHP missing from deployed package ' + module)
        manifest = sorted((i.filename, i.CRC, i.file_size) for i in info)
    return set(names), fingerprint(manifest)


def ftp_connect():
    ftp = ftplib.FTP_TLS(timeout=60)
    ftp.connect(os.environ['TC_FTP_HOST'], 21)
    ftp.login(os.environ['TC_FTP_USER'], os.environ['TC_FTP_PASS'])
    ftp.prot_p()
    ftp.cwd(ROOT)
    return ftp, ftp.pwd()


def ftp_list(ftp):
    try:
        return list(ftp.mlsd())
    except ftplib.error_perm as error:
        raise RuntimeError('FTPS MLSD required for safe inventory; no files deleted') from error


def collect(ftp, root, module, expected, tracked, contents, candidates, skipped):
    """Inventory a single module, collecting content only for bounded extra files."""
    try:
        ftp.cwd(root + '/' + module)
    except ftplib.error_perm as error:
        if not str(error).startswith('550'):
            raise
        skipped.append({
            'module': module,
            'path': module + '/',
            'reason': 'FTP plugin directory missing or inaccessible; WordPress directory is authoritative',
        })
        print('::warning::FTPS directory unavailable for', module, '- comparison omitted, no deletion allowed')
        return
    scanned = [0]
    downloaded = [sum(len(v) for v in contents.values())]

    def walk(prefix='', depth=0):
        if depth > 15:
            raise RuntimeError('Remote plugin exceeds inventory depth limit')
        for name, facts in ftp_list(ftp):
            if name in ('.', '..'):
                continue
            path = prefix + name
            scanned[0] += 1
            if scanned[0] > 20000:
                raise RuntimeError('Remote module exceeds safe inventory limit')
            if not SAFE_SEGMENT.fullmatch(name):
                skipped.append({'module': module, 'path': path, 'reason': 'unsafe filename'})
                continue
            kind = str(facts.get('type', '')).lower()
            if kind == 'dir':
                if protected(path, module):
                    skipped.append({'module': module, 'path': path + '/', 'reason': protected(path, module)})
                    continue
                ftp.cwd(name)
                try:
                    walk(path + '/', depth + 1)
                finally:
                    ftp.cwd('..')
                continue
            if kind != 'file':
                skipped.append({'module': module, 'path': path, 'reason': 'unknown FTPS entry type'})
                continue
            if path in expected:
                continue
            guard = protected(path, module)
            if guard:
                skipped.append({'module': module, 'path': path, 'reason': guard})
                continue
            try:
                size = int(facts.get('size', '-1'))
            except (TypeError, ValueError):
                size = -1
            if size < 0 or size > MAX_FILE:
                skipped.append({'module': module, 'path': path, 'reason': 'unknown or excessive file size'})
                continue
            if len(candidates) >= MAX_CANDIDATES or downloaded[0] + size > MAX_TOTAL:
                skipped.append({'module': module, 'path': path, 'reason': 'bounded cleanup budget exceeded'})
                continue
            buf = bytearray()
            ftp.retrbinary('RETR ' + name, buf.extend)
            if len(buf) != size:
                raise RuntimeError('Remote size changed during scan: ' + module + '/' + path)
            data = bytes(buf)
            contents[module + '/' + path] = data
            downloaded[0] += len(data)
            candidates.append({
                'module': module, 'path': path, 'size': len(data),
                'sha256': hashlib.sha256(data).hexdigest(),
                'classification': 'excluded_from_deployment' if path in tracked else 'not_in_source',
            })

    walk()


def write_summary(plan, approved):
    text = [
        '## PRO vs GitHub deploy package: obsolete file review',
        '',
        'Source commit: ' + plan['source_commit'],
        '',
        'Proposed files: **' + str(len(plan['candidates'])) + '**; '
        'protected/unknown: **' + str(len(plan['skipped'])) + '**.',
        '',
        'Approval SHA-256: ' + plan['approval_sha256'],
        '',
        'Deletion is **' + ('approved for this exact manifest' if approved else 'NOT authorised; preview only') + '**.',
        '',
        '| Module | Extra file | Size (bytes) | Classification |',
        '| --- | --- | ---: | --- |',
    ]
    for entry in plan['candidates']:
        text.append('| ' + entry['module'] + ' | ' + entry['path'] + ' | '
                    + str(entry['size']) + ' | ' + entry['classification'] + ' |')
    if plan['skipped']:
        text += ['', 'Protected files/dirs remain untouched; see the JSON artifact for the full list.']
    print('\n'.join(text))
    summary = os.environ.get('GITHUB_STEP_SUMMARY')
    if summary:
        with open(summary, 'a', encoding='utf-8') as handle:
            handle.write('\n'.join(text) + '\n')


def plan():
    modules = installed_modules()
    if not modules:
        raise ValueError('No installed modules; refusing incomplete inventory')
    expected = {}
    tracked = {}
    packages = {}
    for module in modules:
        expected[module], packages[module] = expected_package(module)
        tracked[module] = source_files(module)
    source_commit = subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip()
    candidates = []
    skipped = []
    contents = {}
    ftp, root = ftp_connect()
    try:
        for module in modules:
            collect(ftp, root, module, expected[module], tracked[module], contents, candidates, skipped)
    finally:
        ftp.quit()
    candidates.sort(key=lambda x: (x['module'], x['path']))
    skipped.sort(key=lambda x: (x['module'], x['path']))
    approval_data = {'schema': 1, 'source_commit': source_commit,
                     'packages': packages, 'candidates': candidates}
    digest = fingerprint(approval_data)
    output = {'source_commit': source_commit, 'packages': packages,
              'candidates': candidates, 'skipped': skipped, 'approval_sha256': digest}
    OUTPUT.mkdir(exist_ok=True)
    (OUTPUT / 'plan.json').write_text(json.dumps(output, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    requested = os.environ.get('TC_APPROVED_CLEANUP_SHA256', '').strip().lower()
    inaccessible = any('FTP plugin directory missing or inaccessible' in x['reason'] for x in skipped)
    if requested and inaccessible:
        write_summary(output, False)
        raise RuntimeError('FTPS inventory incomplete: deletion approval is prohibited')
    if requested and not re.fullmatch(r'[0-9a-f]{64}', requested):
        raise ValueError('Cleanup approval must be a 64-character SHA-256 digest')
    if requested and requested != digest:
        write_summary(output, False)
        raise ValueError('Cleanup approval does not match the current source and remote manifest; review again')
    if requested:
        with zipfile.ZipFile(OUTPUT / 'backup.zip', 'w', compression=zipfile.ZIP_DEFLATED) as backup:
            for entry in candidates:
                backup.writestr(entry['module'] + '/' + entry['path'],
                                contents[entry['module'] + '/' + entry['path']])
            backup.writestr('cleanup-plan.json', json.dumps(output, indent=2))
    write_summary(output, bool(requested))


def remote_file(ftp, root, module, path):
    """Recheck MLSD type and read the exact file immediately before removal."""
    if protected(path, module):
        raise ValueError('Protected file cannot be removed: ' + module + '/' + path)
    parent = posixpath.dirname(path)
    name = posixpath.basename(path)
    ftp.cwd(root + '/' + module + (('/' + parent) if parent else ''))
    records = dict(ftp_list(ftp))
    if name not in records or records[name].get('type', '').lower() != 'file':
        raise RuntimeError('File disappeared or changed type: ' + module + '/' + path)
    size = int(records[name].get('size', '-1'))
    if size < 0 or size > MAX_FILE:
        raise RuntimeError('File exceeds safety limit: ' + module + '/' + path)
    content = bytearray()
    ftp.retrbinary('RETR ' + name, content.extend)
    if len(content) != size:
        raise RuntimeError('File changed during approved cleanup: ' + module + '/' + path)
    return bytes(content)


def apply():
    requested = os.environ.get('TC_APPROVED_CLEANUP_SHA256', '').strip().lower()
    report = json.loads((OUTPUT / 'plan.json').read_text(encoding='utf-8'))
    if not re.fullmatch(r'[0-9a-f]{64}', requested) or requested != report['approval_sha256']:
        raise RuntimeError('No matching explicit approval; deletion blocked')
    candidates = report['candidates']
    if any('FTP plugin directory missing or inaccessible' in x['reason']
           for x in report.get('skipped', [])):
        raise RuntimeError('FTPS inventory incomplete: refusing all deletions')
    if not candidates:
        print('Nothing to remove')
        return
    backup_path = OUTPUT / 'backup.zip'
    if not backup_path.is_file():
        raise RuntimeError('Deletion backup has not been prepared/uploaded')
    with zipfile.ZipFile(backup_path) as backup:
        for entry in candidates:
            key = entry['module'] + '/' + entry['path']
            if hashlib.sha256(backup.read(key)).hexdigest() != entry['sha256']:
                raise RuntimeError('Deletion backup does not match approval: ' + key)
    ftp, root = ftp_connect()
    try:
        # Verify the entire approval set before deleting the first file.
        for entry in candidates:
            content = remote_file(ftp, root, entry['module'], entry['path'])
            if hashlib.sha256(content).hexdigest() != entry['sha256']:
                raise RuntimeError('PRO file changed after approval: '
                                   + entry['module'] + '/' + entry['path'])
        for entry in candidates:
            module, path = entry['module'], entry['path']
            parent = posixpath.dirname(path)
            ftp.cwd(root + '/' + module + (('/' + parent) if parent else ''))
            ftp.delete(posixpath.basename(path))
            print('Deleted approved obsolete file:', module + '/' + path)
        # Remove only directories left empty by those deletions.
        directories = {(e['module'], posixpath.dirname(e['path']))
                       for e in candidates if '/' in e['path']}
        for module, path in sorted(directories, key=lambda x: -x[1].count('/')):
            if not path or protected(path, module):
                continue
            ftp.cwd(root + '/' + module)
            try:
                ftp.rmd(path)
            except ftplib.error_perm as error:
                if not str(error).startswith('550'):
                    raise
        print('Approved deletion complete:', len(candidates), 'files')
    finally:
        ftp.quit()


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('command', choices=('plan', 'apply'))
    args = parser.parse_args()
    if args.command == 'plan':
        plan()
    else:
        apply()
