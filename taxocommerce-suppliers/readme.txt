=== TaxoCommerce Suppliers ===
Contributors: davidperez
Tags: woocommerce, suppliers, csv, inventory, prices
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later

CSV-only supplier catalogue management for the TaxoCommerce family.

== Description ==

TaxoCommerce Suppliers owns the supplier intake and intermediate supplier catalogue workflow.

Included:
* CSV-only supplier imports.
* Reusable CSV mapping templates.
* Canonical TaxoCommerce supplier CSV template.
* Local VEVOR official CSV normalization without web requests.
* Intermediate inventory with pending, review, accepted, and discarded decisions.
* WooCommerce product linking/creation on acceptance.
* Supplier price correction and optional WooCommerce price updates.
* External supplier image references stored without downloading to Media.
* Purchasing view that matches active WooCommerce sales to accepted supplier sources.
* Compatibility with the existing seo_proveedores_productos and seo_supplier_images tables.
* Public service taxocommerce_suppliers_get_sources() for Logistics and other family plugins.

Explicitly excluded:
* Scraping.
* Crawlers.
* GitHub Actions runners.
* Cloud Run jobs.
* Automatic supplier website extraction.

== Changelog ==

= 0.2.0 =
* Move WooCommerce sale-to-supplier purchasing from Logistics into Suppliers.
* Add Purchasing dashboard with supplier source, cost, purchase URL, and missing-source detection.

= 0.1.0 =
* Initial independent Suppliers module.
