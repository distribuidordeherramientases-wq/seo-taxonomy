#!/usr/bin/env python3
"""Auditor externo de URLs publicadas en un sitemap XML.

Pensado para ejecutarse en GitHub Actions y devolver los resultados a WordPress
mediante un callback HMAC. Solo un 200 directo se considera sano para sitemap.
"""

from __future__ import annotations

import argparse
import concurrent.futures
import hashlib
import hmac
import json
import os
import sys
import threading
import time
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Dict, Iterable, List, Optional, Tuple
from urllib.parse import urlparse
import xml.etree.ElementTree as ET

import requests
from requests import Response
from requests.exceptions import (
    ConnectionError as RequestsConnectionError,
    InvalidURL,
    RequestException,
    SSLError,
    Timeout,
    TooManyRedirects,
)

USER_AGENT = "SEO-System-Sitemap-Auditor/1.0 (+external-health-check)"
DEFAULT_TIMEOUT = 15.0
DEFAULT_WORKERS = 15
CALLBACK_BATCH = 250
MAX_SITEMAPS = 5000
MAX_SITEMAP_DEPTH = 8
MAX_REDIRECTS = 12
_thread_local = threading.local()


@dataclass(frozen=True)
class SitemapProblem:
    url: str
    message: str
    http_status: int = 0
    final_status: int = 0
    final_url: str = ""
    error_type: str = "sitemap_fetch_error"


def utc_now_iso() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def strip_ns(tag: str) -> str:
    return tag.split("}", 1)[-1] if "}" in tag else tag


def same_host(url: str, expected_host: str) -> bool:
    try:
        return (urlparse(url).hostname or "").lower() == expected_host.lower()
    except ValueError:
        return False


def session() -> requests.Session:
    s = getattr(_thread_local, "session", None)
    if s is None:
        s = requests.Session()
        s.max_redirects = MAX_REDIRECTS
        s.headers.update(
            {
                "User-Agent": USER_AGENT,
                "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
                "Accept-Language": "es,en;q=0.8",
                "Cache-Control": "no-cache",
            }
        )
        _thread_local.session = s
    return s


def request_get(url: str, timeout: float, stream: bool = True) -> Response:
    """GET con un reintento solo para fallos de transporte."""
    last_exc: Optional[BaseException] = None
    for attempt in range(2):
        try:
            return session().get(
                url,
                timeout=timeout,
                allow_redirects=True,
                stream=stream,
                verify=True,
            )
        except (Timeout, RequestsConnectionError, SSLError) as exc:
            last_exc = exc
            if attempt == 0:
                time.sleep(0.5)
                continue
            raise
    assert last_exc is not None
    raise last_exc


def response_chain(response: Response) -> List[dict]:
    chain: List[dict] = []
    for hop in response.history:
        chain.append(
            {
                "status": int(hop.status_code),
                "url": hop.url,
                "location": hop.headers.get("Location", ""),
            }
        )
    if response.history:
        chain.append(
            {
                "status": int(response.status_code),
                "url": response.url,
                "location": "",
            }
        )
    return chain


def classify_response(initial: int, final: int, redirects: int) -> Tuple[str, str]:
    if redirects > 0:
        if final in (404, 410):
            return "redirect_to_404", f"Redireccion termina en HTTP {final}"
        if final == 403:
            return "redirect_to_403", "Redireccion termina en HTTP 403"
        if 500 <= final <= 599:
            return "redirect_to_5xx", f"Redireccion termina en HTTP {final}"
        if final == 429:
            return "redirect_to_429", "Redireccion termina en HTTP 429"
        if final == 200:
            return "redirect", f"La URL del sitemap redirige ({initial} -> 200)"
        return "redirect_error", f"Redireccion termina en HTTP {final}"

    if final == 200:
        return "", ""
    if final in (404, 410):
        return "http_404" if final == 404 else "http_410", f"HTTP {final}"
    if final == 403:
        return "http_403", "HTTP 403"
    if final == 429:
        return "rate_limited", "HTTP 429"
    if 500 <= final <= 599:
        return "http_5xx", f"HTTP {final}"
    if 400 <= final <= 499:
        return "http_4xx", f"HTTP {final}"
    if 300 <= final <= 399:
        return "http_3xx", f"HTTP {final} sin destino final"
    return "http_status", f"HTTP inesperado {final}"


def check_url(url: str, sitemap_url: str, timeout: float) -> dict:
    started = time.perf_counter()
    checked_at = utc_now_iso()
    try:
        response = request_get(url, timeout=timeout, stream=True)
        try:
            redirects = len(response.history)
            initial = int(response.history[0].status_code) if response.history else int(response.status_code)
            final = int(response.status_code)
            error_type, error_message = classify_response(initial, final, redirects)
            return {
                "resource_type": "page",
                "sitemap_url": sitemap_url,
                "url": url,
                "http_status": initial,
                "final_status": final,
                "final_url": response.url,
                "redirect_count": redirects,
                "redirect_chain": response_chain(response),
                "response_ms": int((time.perf_counter() - started) * 1000),
                "error_type": error_type,
                "error_message": error_message,
                "checked_at": checked_at,
            }
        finally:
            response.close()
    except TooManyRedirects as exc:
        error_type, message = "redirect_loop", f"Demasiadas redirecciones o bucle: {exc}"
    except Timeout as exc:
        error_type, message = "timeout", f"Timeout: {exc}"
    except SSLError as exc:
        error_type, message = "ssl_error", f"SSL: {exc}"
    except InvalidURL as exc:
        error_type, message = "invalid_url", f"URL invalida: {exc}"
    except RequestsConnectionError as exc:
        error_type, message = "connection_error", f"Conexion/DNS: {exc}"
    except RequestException as exc:
        error_type, message = "request_error", f"Peticion: {exc}"
    except Exception as exc:  # defensivo: el runner debe informar, no morir por una URL
        error_type, message = "unexpected_error", f"{type(exc).__name__}: {exc}"

    return {
        "resource_type": "page",
        "sitemap_url": sitemap_url,
        "url": url,
        "http_status": 0,
        "final_status": 0,
        "final_url": "",
        "redirect_count": 0,
        "redirect_chain": [],
        "response_ms": int((time.perf_counter() - started) * 1000),
        "error_type": error_type,
        "error_message": message[:1000],
        "checked_at": checked_at,
    }


def sitemap_problem_row(problem: SitemapProblem) -> dict:
    return {
        "resource_type": "sitemap",
        "sitemap_url": problem.url,
        "url": problem.url,
        "http_status": problem.http_status,
        "final_status": problem.final_status,
        "final_url": problem.final_url,
        "redirect_count": 0,
        "redirect_chain": [],
        "response_ms": 0,
        "error_type": problem.error_type,
        "error_message": problem.message[:1000],
        "checked_at": utc_now_iso(),
    }


def parse_sitemap_tree(
    root_sitemap: str,
    timeout: float,
) -> Tuple[Dict[str, str], List[SitemapProblem]]:
    """Devuelve {url_pagina: sitemap_origen} y problemas de sitemap."""
    root_host = urlparse(root_sitemap).hostname or ""
    if not root_host:
        raise ValueError("El sitemap raiz no contiene un host valido")

    pages: Dict[str, str] = {}
    problems: List[SitemapProblem] = []
    visited: set[str] = set()

    def visit(sitemap_url: str, depth: int) -> None:
        if sitemap_url in visited:
            return
        if len(visited) >= MAX_SITEMAPS:
            problems.append(SitemapProblem(sitemap_url, "Limite maximo de sitemaps alcanzado", error_type="sitemap_limit"))
            return
        if depth > MAX_SITEMAP_DEPTH:
            problems.append(SitemapProblem(sitemap_url, "Profundidad maxima de sitemap alcanzada", error_type="sitemap_depth"))
            return
        if not same_host(sitemap_url, root_host):
            problems.append(SitemapProblem(sitemap_url, "Sitemap hijo fuera del host auditado", error_type="sitemap_external"))
            return

        visited.add(sitemap_url)
        try:
            response = request_get(sitemap_url, timeout=timeout, stream=False)
        except RequestException as exc:
            problems.append(SitemapProblem(sitemap_url, f"No se pudo descargar: {exc}"))
            return

        try:
            initial = int(response.history[0].status_code) if response.history else int(response.status_code)
            final = int(response.status_code)
            if response.history and final == 200:
                problems.append(
                    SitemapProblem(
                        sitemap_url,
                        f"El sitemap redirige ({initial} -> 200)",
                        http_status=initial,
                        final_status=final,
                        final_url=response.url,
                        error_type="sitemap_redirect",
                    )
                )
            if final != 200:
                problems.append(
                    SitemapProblem(
                        sitemap_url,
                        f"El sitemap devuelve HTTP {final}",
                        http_status=initial,
                        final_status=final,
                        final_url=response.url,
                    )
                )
                return

            try:
                root = ET.fromstring(response.content)
            except ET.ParseError as exc:
                problems.append(
                    SitemapProblem(
                        sitemap_url,
                        f"XML no valido: {exc}",
                        http_status=initial,
                        final_status=final,
                        final_url=response.url,
                        error_type="sitemap_xml_error",
                    )
                )
                return

            kind = strip_ns(root.tag).lower()
            if kind == "sitemapindex":
                for node in root.iter():
                    if strip_ns(node.tag).lower() != "loc" or not node.text:
                        continue
                    child = node.text.strip()
                    if child:
                        visit(child, depth + 1)
                return

            if kind == "urlset":
                for url_node in list(root):
                    if strip_ns(url_node.tag).lower() != "url":
                        continue
                    loc = ""
                    for child in list(url_node):
                        if strip_ns(child.tag).lower() == "loc" and child.text:
                            loc = child.text.strip()
                            break
                    if loc and same_host(loc, root_host):
                        pages.setdefault(loc, sitemap_url)
                return

            problems.append(
                SitemapProblem(
                    sitemap_url,
                    f"Raiz XML no soportada: {kind}",
                    http_status=initial,
                    final_status=final,
                    final_url=response.url,
                    error_type="sitemap_xml_root",
                )
            )
        finally:
            response.close()

    visit(root_sitemap, 0)
    return pages, problems


def signed_callback(callback_url: str, secret: str, payload: dict, timeout: float = 30.0) -> None:
    body = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    timestamp = str(int(time.time()))
    signature = hmac.new(secret.encode("utf-8"), timestamp.encode("ascii") + b"." + body, hashlib.sha256).hexdigest()
    headers = {
        "Content-Type": "application/json",
        "User-Agent": USER_AGENT,
        "X-SEO-Timestamp": timestamp,
        "X-SEO-Signature": signature,
    }

    last_error: Optional[Exception] = None
    for attempt in range(3):
        try:
            response = requests.post(callback_url, data=body, headers=headers, timeout=timeout)
            if 200 <= response.status_code < 300:
                return
            last_error = RuntimeError(f"Callback HTTP {response.status_code}: {response.text[:500]}")
        except RequestException as exc:
            last_error = exc
        time.sleep(1.0 + attempt)

    raise RuntimeError(f"No se pudo entregar el callback: {last_error}")


def chunked(items: List[dict], size: int) -> Iterable[List[dict]]:
    for i in range(0, len(items), size):
        yield items[i : i + size]


def bucket(result: dict) -> str:
    """Buckets exclusivos para que la suma coincida con el total de paginas."""
    redirects = int(result.get("redirect_count") or 0)
    final = int(result.get("final_status") or result.get("http_status") or 0)
    if redirects > 0:
        return "3xx"
    if final == 200 and not result.get("error_type"):
        return "200"
    if final in (404, 410):
        return "404"
    if final == 403:
        return "403"
    if 500 <= final <= 599:
        return "5xx"
    return "other"


def run(args: argparse.Namespace) -> int:
    secret = os.getenv("SEO_SCAN_CALLBACK_SECRET", "").strip()
    if not secret:
        print("ERROR: falta SEO_SCAN_CALLBACK_SECRET", file=sys.stderr)
        return 2

    started = time.perf_counter()
    try:
        pages, sitemap_problems = parse_sitemap_tree(args.sitemap_url, timeout=args.timeout)
        total_urls = len(pages)

        signed_callback(
            args.callback_url,
            secret,
            {"scan_id": args.scan_id, "event": "start", "total_urls": total_urls},
        )

        if sitemap_problems:
            rows = [sitemap_problem_row(p) for p in sitemap_problems]
            for batch in chunked(rows, CALLBACK_BATCH):
                signed_callback(
                    args.callback_url,
                    secret,
                    {
                        "scan_id": args.scan_id,
                        "event": "batch",
                        "total_urls": total_urls,
                        "results": batch,
                    },
                )

        results_buffer: List[dict] = []
        counts = {"200": 0, "3xx": 0, "404": 0, "403": 0, "5xx": 0, "other": 0}
        processed = 0

        with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as executor:
            futures = {
                executor.submit(check_url, url, sitemap_url, args.timeout): url
                for url, sitemap_url in pages.items()
            }
            for future in concurrent.futures.as_completed(futures):
                result = future.result()
                processed += 1
                counts[bucket(result)] += 1

                # Los 200 directos no necesitan detalle persistente. Se guardan solo incidencias.
                if result.get("error_type") or int(result.get("redirect_count") or 0) > 0 or int(result.get("final_status") or 0) != 200:
                    results_buffer.append(result)

                if len(results_buffer) >= CALLBACK_BATCH or processed % 1000 == 0:
                    signed_callback(
                        args.callback_url,
                        secret,
                        {
                            "scan_id": args.scan_id,
                            "event": "batch",
                            "total_urls": total_urls,
                            "processed_urls": processed,
                            "results": results_buffer,
                        },
                    )
                    results_buffer = []

                if processed % 1000 == 0:
                    print(f"Procesadas {processed}/{total_urls} URLs", flush=True)

        if results_buffer:
            signed_callback(
                args.callback_url,
                secret,
                {
                    "scan_id": args.scan_id,
                    "event": "batch",
                    "total_urls": total_urls,
                    "processed_urls": processed,
                    "results": results_buffer,
                },
            )

        # Los problemas del XML/sitemap son incidencias adicionales, pero no alteran
        # los buckets de paginas; se suman a other_errors para el objetivo global 0.
        other_errors = counts["other"] + len(sitemap_problems)
        summary = {
            "processed_urls": processed,
            "total_urls": total_urls,
            "status_200": counts["200"],
            "status_3xx": counts["3xx"],
            "status_404": counts["404"],
            "status_403": counts["403"],
            "status_5xx": counts["5xx"],
            "other_errors": other_errors,
            "duration_ms": int((time.perf_counter() - started) * 1000),
        }
        signed_callback(
            args.callback_url,
            secret,
            {"scan_id": args.scan_id, "event": "complete", "summary": summary},
        )
        print(json.dumps(summary, indent=2, ensure_ascii=False))
        return 0

    except Exception as exc:
        message = f"{type(exc).__name__}: {exc}"
        print(f"ERROR: {message}", file=sys.stderr)
        try:
            signed_callback(
                args.callback_url,
                secret,
                {"scan_id": args.scan_id, "event": "failed", "error_message": message[:2000]},
            )
        except Exception as callback_exc:
            print(f"ERROR adicional enviando callback de fallo: {callback_exc}", file=sys.stderr)
        return 1


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Audita todas las URLs publicadas en un sitemap XML")
    parser.add_argument("--scan-id", required=True)
    parser.add_argument("--sitemap-url", required=True)
    parser.add_argument("--callback-url", required=True)
    parser.add_argument("--workers", type=int, default=DEFAULT_WORKERS)
    parser.add_argument("--timeout", type=float, default=DEFAULT_TIMEOUT)
    args = parser.parse_args()
    args.workers = max(1, min(40, args.workers))
    args.timeout = max(3.0, min(60.0, args.timeout))
    return args


if __name__ == "__main__":
    raise SystemExit(run(parse_args()))
