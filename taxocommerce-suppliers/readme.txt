=== TaxoCommerce Suppliers ===
Contributors: davidperez
Tags: woocommerce, suppliers, csv, inventory, prices
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.2
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
* Public service taxocommerce_suppliers_get_sources() for purchasing and other family plugins.
* Full end-user Manual and technical developer reference with separate headings, indexed sections, operating tables, contracts and regression checklist in the Manual tab.
* Read-only, manually triggered Autotest of services, administration hooks, WooCommerce, settings and schema.

Explicitly excluded:
* Scraping.
* Crawlers.
* GitHub Actions runners.
* Cloud Run jobs.
* Automatic supplier website extraction.

== Changelog ==

= 0.2.2 =
* Expand the integrated Manual into complete, separately indexed user and developer guides with real controls, side effects, architecture, schemas, hooks and test procedures.
* Preserve all existing operations and read-only Autotest.

= 0.2.1 =
* Add in-plugin Manual (user and technical) and read-only Autotest tabs.
* Preserve the existing six administration tabs and extend smoke coverage.

= 0.2.0 =
* Move WooCommerce sale-to-supplier purchasing from Logistics into Suppliers.
* Add Purchasing dashboard with supplier source, cost, purchase URL, and missing-source detection.

= 0.1.0 =
* Initial independent Suppliers module.

== Historical origins (monolith; not standalone releases) ==
* [Complete legacy and release history](https://github.com/distribuidordeherramientases-wq/seo-taxonomy/tree/main/taxocommerce-suppliers) (changelog.txt / CHANGELOG.md).

* Legacy origin: supplier catalogue recipes, CSV feeds, stock/availability imports and commercial supplier relations were maintained in TaxoCommerce SEO; legacy 2.2.x–2.4.1 are not Suppliers standalone versions.
* The monolith 2.2.0 cycle documented Supplier Import / Sync V2; 2.3.5–2.3.9 introduced successive supplier/inventory and catalogue import improvements.
* The original catalogue workflow used supplier data and an intermediate product inventory before WooCommerce publication, with availability and images kept attributable to their suppliers.
* Standalone Suppliers 0.1.0 established CSV-based supplier catalogue handling and integration with the family of modular plugins.
* Standalone 0.2.0 moved purchasing functions out of Logistics and into Suppliers, separating procurement from delivery and transport calculation.
