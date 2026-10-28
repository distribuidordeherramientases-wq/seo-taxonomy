=== TaxoCommerce Import-Export - Import & Export for WooCommerce ===
Contributors: davidperezmartorell
Tags: woocommerce, csv, import, export, media
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.4.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import and export catalog, content and Media for WordPress/WooCommerce.

== Description ==

TaxoCommerce Import-Export is an independent member of the TaxoCommerce family.

Version 0.4.1 includes:

* Dedicated Exportación tab with resumable block-based CSV generation.
* Selectable exports for full products, reduced portable product inventory, product categories, posts and pages.
* Product export filters for status, Cluster, primary Hub, secondary Hub and multiple product categories.
* Reduced portable inventory with identity, hierarchy, WooCommerce tags/attributes, brand, Vocabulary and canonical SEO attributes; excludes long content, prices, stock, supplier and images.
* Export jobs resume after reloading the admin screen and expose retry, cancel and authenticated download controls.
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

= 0.4.1 =
* Product CSV export resumes after interruptions by truncating incomplete blocks back to the last persisted byte offset, preventing duplicate rows.
* Export progress now distinguishes examined records, written rows, skipped products and completed blocks.
* Product export supports scheduled (`future`) status alongside publish, draft, pending, private and trash.
* Safely rejects in-flight jobs created before the new byte-checkpoint format instead of corrupting the CSV.


= 0.4.0 =
* Added a dedicated Exportación tab.
* Added resumable AJAX export jobs processed in bounded blocks.
* Added full and reduced portable product export modes.
* Added product hierarchy/category/status filters using modular Core metadata.
* Added progress, retry, cancel and authenticated completed-file downloads.
* Kept the reduced inventory intentionally non-importable by the normal round-trip importer.


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

== 0.4.2 ==
* CSV imports: each successful block immediately dispatches a new signed local HTTP request; Action Scheduler and WP-Cron are recovery/watchdog paths, not pacing dependencies.
* Browser-side AJAX assistance drives the queue while the admin is open; live rows/sec, duration, next block and adaptation reason.
* Adaptive regulator starts with 10 rows at level 3, grows/shrinks according to measured duration and memory, does not interpret PHP-request CPU usage as global server pressure.
* Individual CSV import starts immediately after upload; explicitly queued/paused files still require the administrator to press Start.
* If the hosting blocks internal loopback and WordPress cron is disabled, unattended execution cannot be guaranteed without a real server cron.
