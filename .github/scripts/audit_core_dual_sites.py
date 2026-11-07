#!/usr/bin/env python3
"""Read-only functional/parity audit of the SAME TaxoCommerce Core on two sites.

No writes by FTP, no database access, no site-specific Core changes. Web login
is attempted only with explicit per-site credentials supplied as CI secrets.
Results distinguish PASS, FAIL, WARNING and NOT_CHECKED.
"""
from __future__ import annotations

import argparse
from collections import Counter
import ftplib
import hashlib
from html.parser import HTMLParser
import http.cookiejar
import json
import os
from pathlib import Path
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

from deploy_staging_delta import core_source_files, remote_plugin_dir

SITES = {
    "staging": {
        "url": "https://staging.distribuidordeherramientas.es/",
        "user_env": "TC_STAGING_WP_USER",
        "password_env": "TC_STAGING_WP_PASSWORD",
    },
    "focazul": {
        "url": "https://hosting160568eu-cp518.wordpresstemporal.com/asociacionfocazul/",
        "user_env": "TC_FOCAZUL_WP_USER",
        "password_env": "TC_FOCAZUL_WP_PASSWORD",
    },
}
MAX_HTML_BYTES = 8 * 1024 * 1024
SOURCE_EXTENSIONS = (".php", ".js", ".json")
# Concrete client identifiers are never a prerequisite for a reusable plugin.
CLIENT_MARKERS = (
    re.compile(r"distribuidordeherramientas", re.I),
    re.compile(r"asociacionfocazul|focazul", re.I),
    re.compile(r"hosting160568eu", re.I),
    re.compile(r"\bdht_[a-z0-9_]+\b", re.I),
    re.compile(r"\b(?:makita|beko|becko|vevor|arebos|todotaladros|cam elion|cam elion)\b", re.I),
)
# Current Core menu editor contract shared by classic and block themes.
ADMIN_SHARED = ("tc-menu-load", "tc-menu-editor", "tc-menu-structure",
                "tc-menu-box", "tc-menu-controls")
PHP_ERROR = re.compile(r"(?:PHP Fatal error|Fatal error:|Warning:.*wp-includes/|Uncaught Error:)", re.I)


class MenuHTML(HTMLParser):
    """Capture only structure and navigation labels; never log page contents."""

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.classes = Counter()
        self.nav_count = 0
        self.nav_links = []
        self._nav_depth = 0
        self._a_in_nav = 0
        self._label = []

    def handle_starttag(self, tag, attrs):
        attributes = dict(attrs)
        for value in attributes.get("class", "").split():
            self.classes[value] += 1
        if tag == "nav":
            self._nav_depth += 1
            self.nav_count += 1
        if tag == "a" and self._nav_depth:
            self._a_in_nav += 1
            self._label = []
            self.nav_links.append({"href": attributes.get("href", ""), "text": ""})

    def handle_data(self, data):
        if self._a_in_nav:
            self._label.append(data)

    def handle_endtag(self, tag):
        if tag == "a" and self._a_in_nav:
            if self.nav_links:
                self.nav_links[-1]["text"] = " ".join(" ".join(self._label).split())[:180]
            self._a_in_nav -= 1
            self._label = []
        if tag == "nav" and self._nav_depth:
            self._nav_depth -= 1


def check(report, site, name, status, detail):
    assert status in ("PASS", "FAIL", "WARNING", "NOT_CHECKED")
    report["checks"].append(dict(site=site, name=name, status=status, detail=detail))


def read_html(opener, url, data=None, max_bytes=MAX_HTML_BYTES):
    req = urllib.request.Request(url, data=data, headers={
        "User-Agent": "TaxoCommerce-Core-QA/1.0 (two-site read-only audit)",
        "Accept": "text/html",
    })
    with opener.open(req, timeout=25) as response:
        blob = response.read(max_bytes + 1)
        if len(blob) > max_bytes:
            raise ValueError("HTTP response exceeds safe bounded size")
        encoding = response.headers.get_content_charset() or "utf-8"
        return response.geturl(), blob.decode(encoding, "replace"), response.status


def safe_report_error(exc):
    # Never put URLs with query strings, passwords, headers or server paths in report.
    if isinstance(exc, urllib.error.HTTPError):
        return "HTTP " + str(exc.code)
    return type(exc).__name__


def local_source_audit(report, paths):
    findings = []
    for relative in paths:
        file = Path("taxocommerce-core") / relative
        if not relative.endswith(SOURCE_EXTENSIONS):
            continue
        try:
            text = file.read_text(encoding="utf-8")
        except UnicodeError:
            continue
        for line_number, line in enumerate(text.splitlines(), 1):
            if any(p.search(line) for p in CLIENT_MARKERS):
                findings.append({"file": relative, "line": line_number})
                if len(findings) >= 25:
                    break
    if findings:
        # Matches may be comments, migrations, or generic examples, not site data.
        check(report, "shared_code", "Identificadores comerciales fijos",
              "WARNING", f"{len(findings)} coincidencias potenciales en código PHP/JS/JSON: " +
              ", ".join(f"{f['file']}:{f['line']}" for f in findings[:12]))
    else:
        check(report, "shared_code", "Identificadores comerciales fijos",
              "PASS", "Sin referencias explícitas a clientes y marcas conocidas en los archivos ejecutables de Core")



def global_country_audit(report):
    """Catch the legacy default ES country, which is not global/empty."""
    file = Path("taxocommerce-core/includes/class-taxocommerce-core-country.php")
    if not file.is_file():
        check(report, "shared_code", "País Global por defecto", "NOT_CHECKED",
              "Archivo de países no disponible para inspección")
        return
    source = file.read_text(encoding="utf-8")
    matches = re.findall(r"""return\s+['"]ES['"]\s*;""", source)
    if matches:
        check(report, "shared_code", "País Global por defecto", "WARNING",
              f"{len(matches)} retornos ES heredados; los contenidos sin país deberían usar Global (vacío). Seguimiento #2082")
    else:
        check(report, "shared_code", "País Global por defecto", "PASS",
              "No se detectaron retornos ES por defecto en el módulo de país")



def remote_hash(ftp, root, relative):
    parts = relative.split("/")
    ftp.cwd(root)
    for directory in parts[:-1]:
        ftp.cwd(directory)
    digest = hashlib.sha256()
    try:
        ftp.retrbinary("RETR " + parts[-1], digest.update)
    except ftplib.error_perm as exc:
        if str(exc).startswith("550"):
            return None
        raise
    return digest.hexdigest()


def ftps_parity(report, paths):
    env = ["STAGING_FTP_SERVER", "STAGING_FTP_USERNAME", "STAGING_FTP_PASSWORD"]
    if any(not os.getenv(k) for k in env):
        for site in SITES:
            check(report, site, "Mismo Core instalado (SHA-256)", "NOT_CHECKED",
                  "No hay credenciales de lectura FTPS en este entorno")
        return
    ftp = ftplib.FTP_TLS(timeout=45)
    try:
        ftp.connect(os.environ[env[0]], 21)
        ftp.login(os.environ[env[1]], os.environ[env[2]])
        ftp.prot_p()
        home = ftp.pwd()
        for site in SITES:
            try:
                ftp.cwd(home)
                ftp.cwd(remote_plugin_dir(site, "taxocommerce-core"))
                root = ftp.pwd()
                missing, modified = [], []
                for relative in paths:
                    expected = hashlib.sha256((Path("taxocommerce-core") / relative).read_bytes()).hexdigest()
                    got = remote_hash(ftp, root, relative)
                    if got is None:
                        missing.append(relative)
                    elif got != expected:
                        modified.append(relative)
                if missing or modified:
                    check(report, site, "Mismo Core instalado (SHA-256)", "FAIL",
                          f"{len(paths) - len(missing) - len(modified)}/{len(paths)} iguales; "
                          f"faltantes={missing[:6]}; diferentes={modified[:6]}")
                else:
                    check(report, site, "Mismo Core instalado (SHA-256)", "PASS",
                          f"{len(paths)}/{len(paths)} archivos instalables iguales al mismo commit; ninguna escritura")
            except (OSError, AttributeError, *ftplib.all_errors) as exc:
                check(report, site, "Mismo Core instalado (SHA-256)", "FAIL",
                      "No se pudo verificar la instalación por FTPS: " + type(exc).__name__)
    except (OSError, EOFError, AttributeError, *ftplib.all_errors) as exc:
        for site in SITES:
            if not any(c["site"] == site and c["name"] == "Mismo Core instalado (SHA-256)" for c in report["checks"]):
                check(report, site, "Mismo Core instalado (SHA-256)", "FAIL",
                      "Conexión FTPS no disponible: " + type(exc).__name__)
    finally:
        # FTP_TLS.close() nulls sock. A second quit() on a disconnected FTP
        # session raises AttributeError on Python 3.14; never mask audit results.
        if getattr(ftp, "sock", None) is not None:
            try:
                ftp.quit()
            except (OSError, AttributeError, *ftplib.all_errors):
                pass
        ftp.close()


def public_provider_head(report, site, html):
    """Inspect rendered head HTML without cookies/secrets; never imply API success."""
    if site != "staging":
        return
    head = html.split("</head>", 1)[0] if "</head>" in html.lower() else html
    gtag = len(re.findall(
        r"""<script\b[^>]*\bsrc\s*=\s*["'][^"']*googletagmanager\.com/gtag/js\?id=""",
        head,
        re.I,
    ))
    if gtag > 1:
        check(report, site, "Google Analytics: tag único", "FAIL",
              f"{gtag} gtag.js externos detectados antes de aceptar cookies (duplicado)")
    elif gtag == 1:
        check(report, site, "Google Analytics: tag único", "PASS",
              "Un solo gtag.js en HTML público; NO prueba recepción de tráfico")
    else:
        check(report, site, "Google Analytics: tag único", "NOT_CHECKED",
              "Sin gtag.js en HTML anónimo; puede requerir consentimiento, no concluye fallo")
    names = re.findall(r"""<meta\b[^>]*\bname\s*=\s*["']([^"']+)["']""", head, re.I)
    normalized = [name.lower() for name in names]
    for label, field in (
        ("Google Search Console", "google-site-verification"),
        ("Microsoft Bing", "msvalidate.01"),
        ("Pinterest Merchant", "p:domain_verify"),
    ):
        count = normalized.count(field)
        status = "FAIL" if count > 1 else ("PASS" if count == 1 else "NOT_CHECKED")
        detail = "Etiqueta de verificación única en HTML" if count == 1 else (
            "Etiquetas duplicadas" if count > 1 else "Sin meta en este HTML; DNS u otro método no verificados"
        )
        check(report, site, label + ": meta de cabecera", status, detail)


def public_navigation(report, site, base):
    try:
        final, html, code = read_html(urllib.request.build_opener(), base)
    except urllib.error.HTTPError as exc:
        status = "NOT_CHECKED" if exc.code in (401, 403, 429) else "FAIL"
        check(report, site, "Web pública", status, "HTTP " + str(exc.code))
        return
    except (OSError, ValueError, urllib.error.URLError) as exc:
        check(report, site, "Web pública", "NOT_CHECKED", "Respuesta HTTP no disponible: " + safe_report_error(exc))
        return
    origin = urllib.parse.urlparse(base)
    landed = urllib.parse.urlparse(final)
    if landed.hostname != origin.hostname:
        check(report, site, "Web pública", "WARNING", "La web redirige a un dominio distinto del esperado")
        return
    if code != 200:
        check(report, site, "Web pública", "FAIL", "HTTP " + str(code))
        return
    if PHP_ERROR.search(html):
        check(report, site, "Web pública", "FAIL", "Avisos/errores PHP visibles en la página")
    else:
        check(report, site, "Web pública", "PASS", "HTTP 200 sin errores PHP visibles en el HTML recibido")
    public_provider_head(report, site, html)
    parser = MenuHTML()
    parser.feed(html)
    if not parser.nav_count:
        check(report, site, "Navegación pública", "WARNING",
              "No hay una etiqueta nav visible en el HTML; puede ser cabecera dinámica o web en mantenimiento")
        return
    if not parser.nav_links:
        check(report, site, "Navegación pública", "WARNING", f"{parser.nav_count} nav sin enlaces HTML detectables")
        return
    suspects = []
    unexpected_local = []
    for link in parser.nav_links:
        destination = urllib.parse.urlparse(urllib.parse.urljoin(base, link["href"]))
        if site == "focazul":
            if destination.hostname == "staging.distribuidordeherramientas.es":
                suspects.append("enlace a STAGING ajeno")
            if destination.hostname == origin.hostname and not (destination.path == "/asociacionfocazul" or destination.path.startswith("/asociacionfocazul/")):
                unexpected_local.append(destination.path[:120])
            if re.search(r"\b(?:makita|beko|becko|vevor|taller mec[aá]nico)\b", link["text"], re.I):
                suspects.append("marca/categoría ajena")
        if site == "staging" and destination.hostname == origin.hostname:
            if destination.path.startswith("/asociacionfocazul/"):
                suspects.append("ruta de Focazul")
    if suspects or unexpected_local:
        check(report, site, "Navegación pública", "WARNING",
              f"{len(parser.nav_links)} enlaces en {parser.nav_count} nav; "
              f"posibles referencias cruzadas={len(suspects)}; rutas fuera del subdirectorio="
              f"{len(unexpected_local)} (muestra: {unexpected_local[:3]})")
    else:
        check(report, site, "Navegación pública", "PASS",
              f"{len(parser.nav_links)} enlaces en {parser.nav_count} nav; sin referencias cruzadas conocidas")


class SameHostRedirect(urllib.request.HTTPRedirectHandler):
    def __init__(self, domain):
        self.domain = domain

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        if urllib.parse.urlparse(newurl).hostname != self.domain:
            raise ValueError("Cross-domain redirect blocked for authenticated check")
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def authenticated_ui(report, site, cfg):
    user, password = os.getenv(cfg["user_env"], ""), os.getenv(cfg["password_env"], "")
    if not user or not password:
        check(report, site, "Gestor de menús autenticado", "NOT_CHECKED",
              "Requiere credenciales de usuario de pruebas en secretos CI; no se asume que el acceso público verifica wp-admin")
        return
    base = cfg["url"]
    domain = urllib.parse.urlparse(base).hostname
    cookies = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(cookies), SameHostRedirect(domain)
    )
    login = urllib.parse.urljoin(base, "wp-login.php")
    editor = urllib.parse.urljoin(base, "wp-admin/admin.php?page=taxocommerce-core-menu")
    try:
        read_html(opener, login)
        data = urllib.parse.urlencode({
            "log": user, "pwd": password, "wp-submit": "Log In",
            "redirect_to": editor, "testcookie": "1",
        }).encode("utf-8")
        final, html, code = read_html(opener, login, data=data)
        if "tc-menu-load" not in html:
            final, html, code = read_html(opener, editor)
        if code != 200 or "tc-menu-load" not in html:
            check(report, site, "Gestor de menús autenticado", "NOT_CHECKED",
                  "Acceso wp-admin no confirmado (puede haber 2FA, protección o error de credenciales)")
            return
        p = MenuHTML()
        p.feed(html)
        essentials = ("tc-menu-load",)
        missing = [name for name in essentials if not p.classes[name]]
        if missing:
            check(report, site, "Gestor de menús autenticado", "FAIL", "No aparece el selector común de menús")
            return
        if p.classes["tc-menu-editor"]:
            missing = [name for name in ADMIN_SHARED if not p.classes[name]]
            if missing:
                check(report, site, "Gestor de menús autenticado", "FAIL",
                      "Faltan componentes de interfaz común: " + ", ".join(missing))
            else:
                check(report, site, "Gestor de menús autenticado", "PASS",
                      "Carga, publicación, selectores y estructura de 2 columnas presentes en el HTML real; sin modificar menús")
        else:
            check(report, site, "Gestor de menús autenticado", "WARNING",
                  "Selector común presente, pero ningún menú está seleccionado para comprobar la edición")
    except (OSError, ValueError, urllib.error.URLError) as exc:
        check(report, site, "Gestor de menús autenticado", "NOT_CHECKED",
              "Acceso autenticado no comprobado: " + safe_report_error(exc))


def save_report(report, prefix):
    path = Path(prefix)
    path.parent.mkdir(parents=True, exist_ok=True)
    checks = report["checks"]
    totals = Counter(c["status"] for c in checks)
    report["totals"] = dict(totals)
    (path.with_suffix(".json")).write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    lines = [
        "# TaxoCommerce Core — auditoría comparativa STAGING y Focazul",
        "",
        f"**Commit fuente:** `{report['commit']}` · **Chequeos:** "
        + " · ".join(f"{k}={totals[k]}" for k in ("PASS", "FAIL", "WARNING", "NOT_CHECKED")),
        "",
        "STAGING y Focazul deben tener el **mismo plugin**, pero datos, menú y marcas **independientes**.",
        "",
        "| Instalación | Control | Estado | Evidencia |",
        "|:--|:--|:--|:--|",
    ]
    for c in checks:
        detail = c["detail"].replace("|", "/").replace("\n", " ")[:650]
        lines.append(f"| {c['site']} | {c['name']} | **{c['status']}** | {detail} |")
    lines.extend([
        "",
        "**Alcance:** Se intenta verificar remotamente la paridad de ficheros y las páginas públicas; cada fila declara su resultado real.",
        "La equivalencia de la interfaz de administración solo se comprueba remotamente si hay usuarios de pruebas autorizados para las DOS tiendas.",
        "Sin autenticación, las pruebas PHPUnit/PHP de CI verifican código simulado, no la funcionalidad visual de ambas instalaciones.",
        "No se comprueban bases de datos, países/categorías internos, creación/guardado efectivo ni permisos por rol sin credenciales/ejecución WordPress autorizados.",
        "No se modifica contenido, código ni ajustes de los servidores mediante esta auditoría.",
        "",
    ])
    md = "\n".join(lines)
    path.with_suffix(".md").write_text(md, encoding="utf-8")
    print(md)
    summary = os.getenv("GITHUB_STEP_SUMMARY")
    if summary:
        with open(summary, "a", encoding="utf-8") as f:
            f.write(md)
    return 1 if totals["FAIL"] else 0


def main():
    cli = argparse.ArgumentParser()
    cli.add_argument("--out", default="core-dual-audit/report")
    args = cli.parse_args()
    report = {"commit": os.getenv("GITHUB_SHA", "local").strip(), "checks": []}
    paths = core_source_files()
    local_source_audit(report, paths)
    global_country_audit(report)
    ftps_parity(report, paths)
    for site, cfg in SITES.items():
        public_navigation(report, site, cfg["url"])
        authenticated_ui(report, site, cfg)
        check(report, site, "Independencia de datos internos", "NOT_CHECKED",
              "Los países de páginas, menús no publicados, categorías y proveedores requieren una consulta autenticada a WordPress; no se infieren de la igualdad de archivos")
    return save_report(report, args.out)


if __name__ == "__main__":
    sys.exit(main())
