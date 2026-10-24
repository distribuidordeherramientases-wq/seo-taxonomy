=== TaxoCommerce Import-Export - Import & Export for WooCommerce ===
Contributors: davidperezmartorell
Tags: woocommerce, csv, import, export, media
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import and export catalog, content and Media for WordPress/WooCommerce.

== Description ==

TaxoCommerce Import-Export is an independent member of the TaxoCommerce family.

Version 0.3.1 includes:

* Products CSV import/export.
* Safe partial correction CSV imports: only present columns are updated.
* Automatic delimiter detection and safe product-type inference for correction CSVs.
* Brand/provider round-trip fields for products.
* Product categories CSV import/export.
* Posts CSV import/export.
* Pages CSV import/export.
* Media image ZIP export with original files, metadata and relationships.
* Media image ZIP import into real WordPress attachments.
* Featured-image, WooCommerce gallery and product-category image relationship restoration.
* WordPress-generated thumbnails are rebuilt on import instead of transported.
* Optional integration into TaxoCommerce Core.
* No direct SQL.
* Sequential multi-file batch queue with natural filename ordering.
* Independent bounded worker owned by Import-Export, without the legacy central process manager.
* Pause after current file, safe stop/resume from byte offset, imported/failed buckets and JSON sidecar diagnostics.
* Extensible mini-importer registry so FAQs, Redirects, suppliers or other modules can register their own CSV handlers.

== Media package ==

A Media export contains:

* manifest.json
* media.csv
* files/<old-attachment-id>/<original-file>

The package transports original image files plus title, ALT, caption, description, post_parent and known image relationships.

== Changelog ==

= 0.3.0 =
* Migrated the sequential multi-file CSV queue into the modular Import-Export plugin.
* Added the Importación por lotes tab with multi-upload, start, pause, stop/resume and manual next-block controls.
* Added an independent bounded Action Scheduler/WP-Cron worker with no dependency on the legacy central process manager.
* Added built-in mini-importers for products, product categories, posts and pages.
* Added extension hooks for other TaxoCommerce plugins to register detectors and mini-importers.
* Added imported/failed file buckets and JSON sidecar diagnostics.

= 0.2.0 =
* Added Media image ZIP import/export.
* Moved Media transport responsibility out of TaxoCommerce Images.
* Avoided early translation calls during Core registration.

= 0.1.1 =
* Core family integration pilot.

= 0.1.0 =
* Initial CSV import/export pilot.
