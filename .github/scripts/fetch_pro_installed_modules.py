#!/usr/bin/env python3
"""Get installed PRO plugin slugs with bounded retries and strict JSON validation.

Read-only: never infer installed modules from repository contents, an empty
response, or a failed request. Standard output is only a GitHub step output.
"""
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

SLUG = re.compile(r'^taxocommerce-[a-z0-9-]+$')
ROUTES = (
    '/wp-json/taxocommerce/v1/deploy-active-modules',
    '/?rest_route=%2Ftaxocommerce%2Fv1%2Fdeploy-active-modules',
)


def parse_modules(data):
    if not isinstance(data, dict) or not isinstance(data.get('modules'), list):
        raise ValueError('REST response must contain a modules array')
    modules = data['modules']
    if not modules or len(modules) > 50:
        raise ValueError('Unexpected empty or excessive PRO plugin inventory')
    if not all(isinstance(module, str) and SLUG.fullmatch(module) for module in modules):
        raise ValueError('Unrecognized PRO plugin slug')
    if len(set(modules)) != len(modules):
        raise ValueError('Duplicate PRO plugin slug')
    return modules


def load_once(host, route, opener):
    url = host.rstrip('/') + route
    request = urllib.request.Request(url, headers={
        'Accept': 'application/json',
        'Cache-Control': 'no-cache',
        'User-Agent': 'TaxoCommerce-Deploy-Inventory/1.0',
    })
    with opener(request, timeout=20) as response:
        final = urllib.parse.urlsplit(response.geturl())
        original = urllib.parse.urlsplit(host)
        if final.scheme != 'https' or final.netloc != original.netloc:
            raise ValueError('REST inventory redirected outside the expected PRO origin')
        status = response.status
        content_type = response.headers.get('Content-Type', '')
        raw = response.read(65537)
        if status != 200:
            raise ValueError(f'HTTP {status} from PRO inventory')
        if len(raw) > 65536:
            raise ValueError('PRO inventory response exceeds 64 KB')
        if 'application/json' not in content_type.lower():
            raise ValueError('PRO inventory sent non-JSON Content-Type: ' + content_type[:100])
        try:
            data = json.loads(raw)
        except (ValueError, UnicodeDecodeError) as exc:
            raise ValueError('PRO inventory returned invalid or empty JSON') from exc
        return parse_modules(data)


def fetch(host, opener=urllib.request.urlopen, sleeper=time.sleep, attempts=3):
    if not host.startswith('https://'):
        raise ValueError('PRO_SITE_URL must use HTTPS')
    diagnostics = []
    for attempt in range(attempts):
        for route in ROUTES:
            try:
                modules = load_once(host, route, opener)
                print(f'PRO inventory verified using {route}: {len(modules)} installed plugins',
                      file=sys.stderr)
                return modules
            except (urllib.error.URLError, TimeoutError, OSError, ValueError) as exc:
                message = f'Attempt {attempt + 1}/{attempts} {route}: {type(exc).__name__}: {exc}'
                print('::warning::PRO inventory ' + message[:260], file=sys.stderr)
                diagnostics.append(message)
        if attempt < attempts - 1:
            sleeper(2 ** attempt)
    raise RuntimeError('PRO inventory unavailable or invalid after retries; no plugin ZIP deployment. '
                       + diagnostics[-1][:220])


def main():
    host = os.environ.get('PRO_SITE_URL', '').strip()
    modules = fetch(host)
    print('modules=' + json.dumps(modules, separators=(',', ':')))


if __name__ == '__main__':
    try:
        main()
    except (ValueError, RuntimeError) as exc:
        print('::error::' + str(exc), file=sys.stderr)
        sys.exit(1)
