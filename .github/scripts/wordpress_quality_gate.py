#!/usr/bin/env python3
"""Report Plugin Check findings and validate the WordPress.org plugin package."""

import argparse
from collections import Counter
import json
import os
from pathlib import Path
import re
import sys
from zipfile import ZIP_DEFLATED, ZipFile

MAX_ZIP_BYTES = 10_000_000


def append_summary(markdown):
    summary_path = os.getenv('GITHUB_STEP_SUMMARY')
    if summary_path:
        with open(summary_path, 'a', encoding='utf-8') as output:
            output.write(markdown + '\n')
    print(markdown)


def report_findings(report_file, strict):
    report = Path(report_file)
    if not report.is_file() or not report.stat().st_size:
        append_summary('### Plugin Check: missing or empty result file\nThe check did not produce a usable report.')
        return 1

    current_file = '(plugin)'
    findings = []
    parsed_blocks = 0
    pending = ''

    for original_line in report.read_text(encoding='utf-8-sig').splitlines():
        line = original_line.strip()
        if not pending and (line.startswith('FILE:') or line.startswith('PLUGIN:')):
            current_file = line.split(':', 1)[1].strip() or '(plugin)'
            continue
        if not pending and not (line.startswith('[') or line.startswith('{')):
            continue
        pending += line
        try:
            payload = json.loads(pending)
        except json.JSONDecodeError:
            continue
        pending = ''
        parsed_blocks += 1
        rows = payload if isinstance(payload, list) else [payload]
        for item in rows:
            if not isinstance(item, dict) or 'type' not in item:
                continue
            findings.append({
                'file': str(item.get('file') or current_file),
                'line': item.get('line') or 0,
                'type': str(item.get('type', 'UNKNOWN')).upper(),
                'code': str(item.get('code') or '(no code)'),
                'message': str(item.get('message') or ''),
            })

    if pending or parsed_blocks == 0:
        append_summary('### Plugin Check: unrecognized report format\nRefusing to treat the scan as successful.')
        return 1

    by_type = Counter(item['type'] for item in findings)
    by_code = Counter(item['code'] for item in findings if item['type'] == 'WARNING')
    by_file = Counter(item['file'] for item in findings if item['type'] == 'WARNING')
    errors = by_type['ERROR']
    warnings = by_type['WARNING']
    lines = [
        '### WordPress.org Plugin Check results',
        '',
        '| Errors | Warnings | Other findings | Strict release gate |',
        '| ---: | ---: | ---: | --- |',
        f'| {errors} | {warnings} | {len(findings) - errors - warnings} | {"On" if strict else "Off"} |',
        '',
        '#### Most frequent warning codes',
        '| Code | Count |',
        '| --- | ---: |',
    ]
    lines.extend(f'| `{code.replace("|", "\\|")}` | {count} |' for code, count in by_code.most_common(15))
    if not by_code:
        lines.append('| None | 0 |')
    lines.extend(['', '#### Files with the most warnings', '| File | Count |', '| --- | ---: |'])
    lines.extend(f'| `{filename.replace("|", "\\|")}` | {count} |' for filename, count in by_file.most_common(12))
    if not by_file:
        lines.append('| None | 0 |')
    if errors:
        lines.extend(['', '#### Errors to fix first'])
        for item in (x for x in findings if x['type'] == 'ERROR'):
            lines.append(f'- `{item["file"]}:{item["line"]}` `{item["code"]}`: {item["message"][:160]}')
            if sum(row.startswith('- `') for row in lines) >= 15:
                break
    append_summary('\n'.join(lines))

    print(f'Plugin Check: {errors} errors; {warnings} warnings; strict={strict}')
    if errors or (strict and warnings):
        print('Quality gate FAILED: fix Plugin Check findings.', file=sys.stderr)
        return 1
    return 0


def header_field(content, name):
    match = re.search(r'^\s*\*?\s*' + re.escape(name) + r':\s*(.*?)\s*$', content, re.MULTILINE)
    return match.group(1) if match else ''


def check_package(package_dir, strict):
    root = Path(package_dir)
    main = root / 'seo-taxonomy.php'
    readme = root / 'readme.txt'
    if not main.is_file() or not readme.is_file():
        append_summary('### Package check: FAILED\nMissing `seo-taxonomy.php` or `readme.txt` in the WordPress.org package.')
        return 1

    main_text = main.read_text(encoding='utf-8-sig')
    readme_text = readme.read_text(encoding='utf-8-sig')
    errors = []
    for key in ('Plugin Name', 'Version', 'Requires at least', 'Requires PHP', 'Text Domain', 'License'):
        if not header_field(main_text, key):
            errors.append(f'Missing plugin header: {key}')
    plugin_version = header_field(main_text, 'Version')
    stable_tag = header_field(readme_text, 'Stable tag')
    if not stable_tag or plugin_version != stable_tag:
        errors.append(f'Plugin Version ({plugin_version!r}) does not match readme Stable tag ({stable_tag!r})')
    if header_field(main_text, 'Text Domain') != 'taxocommerce-seo':
        errors.append('Text Domain must be taxocommerce-seo')
    code_version = re.search(r"define\(\s*['\"]SEO_SYSTEM_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]", main_text)
    if code_version and code_version.group(1) != plugin_version:
        errors.append('SEO_SYSTEM_VERSION constant must match the plugin header Version')

    zip_path = Path(os.environ.get('RUNNER_TEMP', '/tmp')) / 'taxocommerce-seo-quality-check.zip'
    with ZipFile(zip_path, mode='w', compression=ZIP_DEFLATED, compresslevel=6) as package:
        for path in sorted(root.rglob('*')):
            if path.is_file():
                package.write(path, arcname=f'{root.name}/{path.relative_to(root)}')
    zip_bytes = zip_path.stat().st_size
    oversized = zip_bytes > MAX_ZIP_BYTES
    rows = [
        '### WordPress.org package preflight',
        '',
        f'- Main plugin version: `{plugin_version or "missing"}`',
        f'- Readme stable tag: `{stable_tag or "missing"}`',
        f'- ZIP size: **{zip_bytes / 1_000_000:.2f} MB** (10 MB submission threshold)',
        f'- Release gate: **{"strict" if strict else "incremental"}**',
    ]
    if oversized:
        rows.append('- WARNING: ZIP exceeds 10 MB; resolve before submitting to WordPress.org.')
    rows.extend(f'- ERROR: {error}' for error in errors)
    append_summary('\n'.join(rows))
    if errors or (strict and oversized):
        print('Package quality gate FAILED.', file=sys.stderr)
        return 1
    return 0


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('mode', choices=('report', 'package'))
    parser.add_argument('path')
    parser.add_argument('--strict', choices=('true', 'false'), default='false')
    args = parser.parse_args()
    strict = args.strict == 'true'
    if args.mode == 'report':
        return report_findings(args.path, strict)
    return check_package(args.path, strict)


if __name__ == '__main__':
    sys.exit(main())
