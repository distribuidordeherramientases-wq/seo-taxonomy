"""Offline regression tests for the production REST module inventory."""
import importlib.util
import io
import json
import pathlib
import unittest
import urllib.error
from contextlib import redirect_stderr
from unittest.mock import Mock

path = pathlib.Path(__file__).resolve().parents[1] / 'fetch_pro_installed_modules.py'
spec = importlib.util.spec_from_file_location('pro_inventory', path)
inventory = importlib.util.module_from_spec(spec)
spec.loader.exec_module(inventory)

HOST = 'https://www.distribuidordeherramientas.es'


class Response:
    status = 200
    def __init__(self, body, content_type='application/json; charset=UTF-8', url=None):
        self.body = body
        self.headers = {'Content-Type': content_type}
        self.url = url or HOST + inventory.ROUTES[0]
    def geturl(self):
        return self.url
    def read(self, size):
        return self.body[:size]
    def __enter__(self):
        return self
    def __exit__(self, *args):
        return False


class InventoryTests(unittest.TestCase):
    def test_valid_inventory(self):
        response = Response(b'{"modules":["taxocommerce-core","taxocommerce-database"]}')
        self.assertEqual(['taxocommerce-core', 'taxocommerce-database'],
                         inventory.fetch(HOST, opener=lambda *a, **k: response,
                                         sleeper=lambda x: None))

    def test_first_route_html_then_wordpress_query_fallback(self):
        visits = []
        def opener(request, timeout):
            visits.append(request.full_url)
            if len(visits) == 1:
                return Response(b'<html>WAF</html>', content_type='text/html')
            return Response(b'{"modules":["taxocommerce-database"]}')
        self.assertEqual(['taxocommerce-database'],
                         inventory.fetch(HOST, opener=opener, sleeper=lambda x: None))
        self.assertEqual(2, len(visits))
        self.assertIn('rest_route=', visits[1])

    def test_empty_response_retry(self):
        count = [0]
        def opener(*args, **kwargs):
            count[0] += 1
            if count[0] < 3:
                return Response(b'')
            return Response(b'{"modules":["taxocommerce-core"]}')
        self.assertEqual(['taxocommerce-core'],
                         inventory.fetch(HOST, opener=opener, sleeper=lambda x: None))
        self.assertEqual(3, count[0])

    def test_no_success_does_not_guess_or_allow_empty_inventory(self):
        with redirect_stderr(io.StringIO()):
            with self.assertRaisesRegex(RuntimeError, 'no plugin ZIP deployment'):
                inventory.fetch(HOST, opener=lambda *args, **kw: Response(b''),
                                sleeper=lambda x: None, attempts=2)

    def test_invalid_slugs_and_duplicates_rejected(self):
        for modules in ([], ['taxocommerce-core', 'taxocommerce-core'],
                        ['../../wp-config.php'], [12]):
            with self.subTest(modules=modules):
                with self.assertRaises(ValueError):
                    inventory.parse_modules({'modules': modules})

    def test_offsite_redirect_rejected(self):
        with self.assertRaisesRegex(ValueError, 'outside the expected'):
            inventory.load_once(HOST, inventory.ROUTES[0],
                                lambda *a, **k: Response(b'{"modules":["taxocommerce-core"]}',
                                                       url='https://other.example/'))

    def test_http_failure_then_success(self):
        calls = [0]
        def opener(*args, **kwargs):
            calls[0] += 1
            if calls[0] == 1:
                raise urllib.error.HTTPError(HOST, 503, 'Service unavailable', {}, None)
            return Response(b'{"modules":["taxocommerce-core"]}')
        self.assertEqual(['taxocommerce-core'],
                         inventory.fetch(HOST, opener=opener, sleeper=lambda x: None))


if __name__ == '__main__':
    unittest.main()
