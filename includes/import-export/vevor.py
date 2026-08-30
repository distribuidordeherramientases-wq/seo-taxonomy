#!/usr/bin/env python3
"""VEVOR -> enriquecimiento de imagenes para SEO System.

Entrada: CSV estandar preparado por WordPress.
Salida: el mismo CSV, con `imagenes` enriquecido con F1..F12 y M1..M12
que respondan HTTP 200 y Content-Type image/*.

El script NO escribe en WordPress ni en WooCommerce. Solo devuelve el CSV
por el callback privado que ya usa github-python-runner.php.
"""

from __future__ import annotations

import csv
import json
import os
import re
import sys
import threading
from concurrent.futures import ThreadPoolExecutor, as_completed
from dataclasses import dataclass, field
from pathlib import Path
from typing import Dict, Iterable, List, Optional, Set, Tuple
from urllib.parse import quote, unquote, urlsplit

import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry


SOURCE_URL = os.environ.get("SEO_SOURCE_URL", "").strip()
CALLBACK_URL = os.environ.get("SEO_CALLBACK_URL", "").strip()
CALLBACK_TOKEN = os.environ.get("SEO_CALLBACK_TOKEN", "").strip()
REMOTE_RUN_ID = os.environ.get("SEO_REMOTE_RUN_ID", "").strip()
RECIPE_ID = os.environ.get("SEO_RECIPE_ID", "vevor").strip() or "vevor"
PROVIDER = os.environ.get("SEO_PROVIDER", "VEVOR").strip() or "VEVOR"
OUTPUT_DIR = Path(os.environ.get("SEO_OUTPUT_DIR", "output/VEVOR"))
OUTPUT_DIR.mkdir(parents=True, exist_ok=True)

INPUT_PATH = OUTPUT_DIR / "vevor_input.csv"
OUTPUT_PATH = OUTPUT_DIR / "vevor_enriched.csv"

MAX_INDEX = int(os.environ.get("SEO_VEVOR_MAX_INDEX", "12"))
HTTP_WORKERS = max(4, min(64, int(os.environ.get("SEO_VEVOR_HTTP_WORKERS", "32"))))
BATCH_ROWS = max(10, min(500, int(os.environ.get("SEO_VEVOR_BATCH_ROWS", "100"))))

TRANSIENT_HTTP = {0, 403, 408, 425, 429, 500, 502, 503, 504}
_thread_local = threading.local()


@dataclass
class ImageParts:
    product_folder: str
    directory: str
    version: int
    base: str


@dataclass
class Plan:
    row_index: int
    primary: str
    parts: ImageParts
    pending: Set[str]
    found: Dict[str, str] = field(default_factory=dict)
    uncertain: Set[str] = field(default_factory=set)


def wordpress_callback(status: str, message: str = "", file_path: Optional[Path] = None, required: bool = False, **metrics) -> bool:
    if not CALLBACK_URL or not CALLBACK_TOKEN:
        if required:
            raise RuntimeError("Faltan SEO_CALLBACK_URL o SEO_CALLBACK_TOKEN.")
        return False

    data = {"status": str(status), "message": str(message)[:500]}
    for key, value in metrics.items():
        if value is not None:
            data[key] = str(value)

    headers = {"Authorization": f"Bearer {CALLBACK_TOKEN}"}
    files = None
    fh = None
    try:
        if file_path is not None:
            fh = open(file_path, "rb")
            files = {"file": (file_path.name, fh, "text/csv")}

        response = requests.post(
            CALLBACK_URL,
            headers=headers,
            data=data,
            files=files,
            timeout=180,
        )
        if not 200 <= response.status_code < 300:
            text = (response.text or "").strip().replace("\n", " ")[:500]
            raise RuntimeError(f"Callback WordPress HTTP {response.status_code}: {text}")
        return True
    except Exception as exc:
        if required:
            raise
        print(f"Aviso callback WordPress: {exc}", file=sys.stderr)
        return False
    finally:
        if fh is not None:
            fh.close()


def get_session() -> requests.Session:
    session = getattr(_thread_local, "session", None)
    if session is not None:
        return session

    session = requests.Session()
    retry = Retry(
        total=2,
        connect=2,
        read=1,
        status=1,
        backoff_factor=0.25,
        status_forcelist=[500, 502, 503, 504],
        allowed_methods=frozenset(["GET"]),
        raise_on_status=False,
    )
    session.mount("https://", HTTPAdapter(max_retries=retry, pool_connections=8, pool_maxsize=8))
    session.headers.update(
        {
            "User-Agent": (
                "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                "AppleWebKit/537.36 (KHTML, like Gecko) "
                "Chrome/126.0 Safari/537.36"
            ),
            "Accept": "image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8",
            "Referer": "https://www.vevor.es/",
        }
    )
    _thread_local.session = session
    return session


def download_source() -> None:
    if not SOURCE_URL:
        raise RuntimeError("GitHub no ha recibido SEO_SOURCE_URL.")

    print(f"Descargando CSV preparado desde WordPress: {SOURCE_URL}")
    response = requests.get(
        SOURCE_URL,
        headers={"User-Agent": "SEO-System-GitHub-VEVOR/1.0"},
        timeout=180,
    )
    response.raise_for_status()
    if not response.content:
        raise RuntimeError("WordPress devolvio un CSV vacio.")
    INPUT_PATH.write_bytes(response.content)


def parse_image_list(value: str) -> List[str]:
    value = (value or "").strip()
    if not value:
        return []

    try:
        decoded = json.loads(value)
        if isinstance(decoded, list):
            return [str(item).strip() for item in decoded if str(item).strip()]
    except json.JSONDecodeError:
        pass

    # Respaldo para archivos historicos con URLs separadas.
    pieces = re.split(r"[\r\n|,]+", value)
    return [piece.strip() for piece in pieces if piece.strip().startswith(("http://", "https://"))]


def image_parts(url: str) -> Optional[ImageParts]:
    try:
        path = unquote(urlsplit(url).path).strip("/")
    except Exception:
        return None

    parts = [part for part in path.split("/") if part]
    if len(parts) < 4:
        return None

    filename = parts[-1]
    directory = parts[-2]
    product_folder = parts[-3]

    marker = filename.lower().rfind("-m100")
    if marker < 1:
        return None

    base = filename[:marker]
    match = re.search(r"-v(\d+)$", directory, re.IGNORECASE)
    version = int(match.group(1)) if match else 1

    if not product_folder or not base:
        return None
    return ImageParts(product_folder=product_folder, directory=directory, version=max(1, version), base=base)


def candidate_directories(parts: ImageParts) -> List[str]:
    candidates = [
        f"goods_img_big-v{parts.version}",
        f"original_img-v{parts.version}",
        f"goods_img-v{parts.version}",
        parts.directory,
        f"goods_thumb-v{parts.version}",
    ]
    result: List[str] = []
    for item in candidates:
        if item and item not in result:
            result.append(item)
    return result


def candidate_url(parts: ImageParts, directory: str, suffix: str) -> str:
    filename = f"{parts.base}-{suffix}.jpg"
    encoded_path = "%2F".join(
        [
            quote("es", safe=""),
            quote(parts.product_folder, safe=""),
            quote(directory, safe=""),
            quote(filename, safe=""),
        ]
    )
    return f"https://img.vevorstatic.com/{encoded_path}?format=webp"


def probe(url: str) -> Tuple[str, int, str]:
    """Devuelve (estado, http, content_type): ok | absent | transient."""
    session = get_session()
    try:
        response = session.get(url, allow_redirects=True, stream=True, timeout=(7, 20))
        status = int(response.status_code)
        content_type = (response.headers.get("Content-Type") or "").lower().strip()
        response.close()
    except requests.RequestException:
        return "transient", 0, ""

    if status == 200 and content_type.startswith("image/"):
        return "ok", status, content_type
    if status in TRANSIENT_HTTP:
        return "transient", status, content_type
    return "absent", status, content_type


def load_csv() -> Tuple[List[str], List[Dict[str, str]]]:
    with open(INPUT_PATH, "r", encoding="utf-8-sig", newline="") as fh:
        reader = csv.DictReader(fh, delimiter=";", quotechar='"')
        fieldnames = list(reader.fieldnames or [])
        rows = [dict(row) for row in reader]

    required = {"proveedor_id_externo", "sku", "nombre", "imagenes"}
    missing = sorted(required.difference(fieldnames))
    if missing:
        raise RuntimeError("CSV estandar incompleto. Faltan: " + ", ".join(missing))
    return fieldnames, rows


def suffix_order() -> List[str]:
    return [f"f{i}" for i in range(1, MAX_INDEX + 1)] + [f"m{i}" for i in range(1, MAX_INDEX + 1)]


def enrich_batch(rows: List[Dict[str, str]], offset: int, executor: ThreadPoolExecutor) -> Tuple[int, int, int]:
    suffixes = suffix_order()
    plans: List[Plan] = []
    without_pattern = 0

    for local_index, row in enumerate(rows):
        images = parse_image_list(row.get("imagenes", ""))
        primary = images[0] if images else ""
        if not primary:
            # Producto sin imagen principal: no inventamos ruta.
            without_pattern += 1
            continue

        parts = image_parts(primary)
        if parts is None:
            raise RuntimeError(
                f"No se pudo interpretar el patron de la imagen VEVOR en la fila {offset + local_index + 2}: {primary[:220]}"
            )

        plans.append(
            Plan(
                row_index=local_index,
                primary=primary,
                parts=parts,
                pending=set(suffixes),
            )
        )

    # Calidad primero. Cada ronda prueba una familia de carpeta y solo deja
    # pendientes los sufijos que aun no tienen una URL valida.
    max_dirs = max((len(candidate_directories(plan.parts)) for plan in plans), default=0)
    for directory_index in range(max_dirs):
        futures = {}
        for plan in plans:
            dirs = candidate_directories(plan.parts)
            if directory_index >= len(dirs):
                continue
            directory = dirs[directory_index]
            for suffix in list(plan.pending):
                url = candidate_url(plan.parts, directory, suffix)
                future = executor.submit(probe, url)
                futures[future] = (plan, suffix, url)

        for future in as_completed(futures):
            plan, suffix, url = futures[future]
            state, http_status, content_type = future.result()
            if state == "ok":
                plan.found[suffix] = url
                plan.pending.discard(suffix)
                plan.uncertain.discard(suffix)
            elif state == "transient":
                # Puede resolverse si otra familia devuelve 200. Si al final
                # sigue pendiente, abortamos el CSV completo para no borrar una
                # galeria buena por un 403/429/timeout temporal.
                plan.uncertain.add(suffix)

    unresolved_uncertain = []
    for plan in plans:
        unresolved = sorted(plan.pending.intersection(plan.uncertain))
        if unresolved:
            unresolved_uncertain.append((plan.row_index, unresolved[:6]))

    if unresolved_uncertain:
        local_index, sample = unresolved_uncertain[0]
        raise RuntimeError(
            "VEVOR/CDN devolvio un error temporal durante el chequeo de imagenes "
            f"(fila {offset + local_index + 2}, sufijos {', '.join(sample)}). "
            "No se importa el CSV para evitar perder galerias validas."
        )

    images_found = 0
    rows_enriched = 0
    for plan in plans:
        ordered = [plan.primary]
        for suffix in suffixes:
            url = plan.found.get(suffix)
            if url:
                ordered.append(url)
        ordered = list(dict.fromkeys(ordered))
        rows[plan.row_index]["imagenes"] = json.dumps(ordered, ensure_ascii=False, separators=(",", ":"))
        complementaries = max(0, len(ordered) - 1)
        images_found += complementaries
        if complementaries:
            rows_enriched += 1

    return images_found, rows_enriched, without_pattern


def write_csv(fieldnames: List[str], rows: List[Dict[str, str]]) -> None:
    with open(OUTPUT_PATH, "w", encoding="utf-8-sig", newline="") as fh:
        writer = csv.DictWriter(
            fh,
            fieldnames=fieldnames,
            delimiter=";",
            quotechar='"',
            quoting=csv.QUOTE_MINIMAL,
            extrasaction="ignore",
            lineterminator="\n",
        )
        writer.writeheader()
        writer.writerows(rows)


def main() -> None:
    wordpress_callback(
        "started",
        "VEVOR: descargando CSV preparado para enriquecer imagenes.",
        required=False,
    )

    download_source()
    fieldnames, all_rows = load_csv()
    total = len(all_rows)
    if total == 0:
        raise RuntimeError("El CSV VEVOR no contiene productos.")

    print(f"Filas VEVOR: {total}")
    print(f"F/M: 1..{MAX_INDEX}")
    print(f"HTTP workers: {HTTP_WORKERS}")

    images_found_total = 0
    rows_enriched_total = 0
    rows_without_pattern_total = 0

    with ThreadPoolExecutor(max_workers=HTTP_WORKERS) as executor:
        for start in range(0, total, BATCH_ROWS):
            end = min(total, start + BATCH_ROWS)
            batch = all_rows[start:end]
            found, enriched, no_pattern = enrich_batch(batch, start, executor)
            images_found_total += found
            rows_enriched_total += enriched
            rows_without_pattern_total += no_pattern

            wordpress_callback(
                "progress",
                f"VEVOR: {end}/{total} productos comprobados.",
                required=False,
                products_done=end,
                products_total=total,
                images_found=images_found_total,
                rows_enriched=rows_enriched_total,
                rows_without_pattern=rows_without_pattern_total,
                errors=0,
            )
            print(
                f"{end}/{total} | complementarias={images_found_total} "
                f"| productos_enriquecidos={rows_enriched_total}"
            )

    write_csv(fieldnames, all_rows)

    wordpress_callback(
        "completed",
        f"VEVOR finalizado: {total} productos; {images_found_total} imagenes complementarias validas.",
        file_path=OUTPUT_PATH,
        required=True,
        products_done=total,
        products_total=total,
        images_found=images_found_total,
        rows_enriched=rows_enriched_total,
        rows_without_pattern=rows_without_pattern_total,
        errors=0,
    )

    print(f"CSV enriquecido: {OUTPUT_PATH}")


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        wordpress_callback(
            "error",
            f"VEVOR fallo: {type(exc).__name__}: {exc}",
            required=False,
            errors=1,
        )
        raise
