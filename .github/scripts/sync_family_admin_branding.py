#!/usr/bin/env python3
"""Verify/regenerate self-contained TaxoCommerce admin headers.

One canonical source lives under .github/templates. Each plugin ships its own
copy, so it works even if Core and every other TaxoCommerce plugin are absent.
"""
import argparse
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TEMPLATE = ROOT / ".github/templates/tc-family-admin-branding.php.tpl"
PLUGINS = {
    "auditor": ("dashicons-chart-area", "Audita la calidad del contenido, los indicadores y los informes de WooCommerce."),
    "redirects": ("dashicons-randomize", "Gestiona redirecciones y eliminaciones asistidas con controles de seguridad."),
    "faqs": ("dashicons-editor-help", "Organiza las preguntas frecuentes de productos, categorías y páginas."),
    "images": ("dashicons-format-image", "Audita y optimiza las imágenes de tu catálogo y biblioteca multimedia."),
    "social": ("dashicons-share", "Gestiona conexiones sociales, publicaciones, plantillas y calendario."),
    "database": ("dashicons-database", "Consulta y mantén las tablas de datos de WordPress y WooCommerce."),
    "campaigns": ("dashicons-megaphone", "Organiza campañas comerciales, ofertas, creatividades y calendario."),
    "suppliers": ("dashicons-store", "Gestiona catálogos, compras, precios y relaciones con proveedores."),
    "scrappers": ("dashicons-search", "Investiga productos y mercados con Ojeador e Ingeniero."),
    "logistica": ("dashicons-car", "Gestiona pesos, dimensiones y costes de envío de WooCommerce."),
    "vocabulary": ("dashicons-tag", "Administra el vocabulario semántico y los atributos del catálogo."),
    "facturacion": ("dashicons-media-spreadsheet", "Gestiona facturas, proformas y presupuestos en WooCommerce."),
    "dependiente": ("dashicons-businessperson", "Configura el asistente de ventas, su aprendizaje y sus pruebas."),
    "google-bing": ("dashicons-chart-bar", "Conecta servicios externos y consulta informes de análisis."),
    "distribution": ("dashicons-rss", "Publica feeds comerciales y sitemaps XML de tu catálogo."),
    "import-export": ("dashicons-upload", "Importa y exporta contenidos y productos mediante procesos controlados."),
    "system-check": ("dashicons-shield", "Comprueba el estado de WordPress, el servidor y los plugins."),
    "templates": ("dashicons-layout", "Gestiona plantillas, enrutado de páginas y reglas de presentación."),
    "tasks": ("dashicons-clipboard", "Private task management, calendar and indicators."),
}

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true", help="Only check consistency; never write.")
    args = parser.parse_args()
    template = TEMPLATE.read_text(encoding="utf-8")
    errors = []
    for slug, (icon, description) in PLUGINS.items():
        result = (template
                  .replace("__TC_DOMAIN__", "taxocommerce-" + slug)
                  .replace("__TC_ICON__", icon)
                  .replace("__TC_DESCRIPTION__", description))
        path = ROOT / ("taxocommerce-" + slug) / "includes/admin-branding.php"
        if args.check:
            if not path.is_file() or path.read_text(encoding="utf-8") != result:
                errors.append(slug)
        else:
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(result, encoding="utf-8")
    if errors:
        raise SystemExit("Branding files differ from canonical template: " + ", ".join(errors))
    print(str(len(PLUGINS)) + " independent TaxoCommerce admin headers " + ("match." if args.check else "regenerated."))

if __name__ == "__main__":
    main()
