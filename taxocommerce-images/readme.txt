=== TaxoCommerce Images - Image Management for WooCommerce ===
Contributors: davidperezmartorell
Tags: images, media, thumbnails, optimization, webp
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Image management for WordPress and WooCommerce with Media as the canonical source of truth.

== Description ==

TaxoCommerce Images is an independent member of the TaxoCommerce family.

Core principles:

* WordPress Media is the source of truth.
* No custom image tables or supplier image indexes are required.
* WordPress and WooCommerce native APIs remain authoritative.

Current functionality includes:

* Media dashboard.
* Bounded image audit in batches.
* Reports for missing ALT text, unattached images, missing physical files, oversized dimensions/bytes, missing registered thumbnails and generic titles.
* Product-without-image KPI when WooCommerce is active.
* Metadata editing: title, ALT, caption and description.
* Registered thumbnail-size inventory.
* Thumbnail regeneration.
* Reversible original-image resizing with a local backup.
* Upload scaling and image quality settings.
* Optional integration into TaxoCommerce Core.

== Changelog ==

= 0.2.4 =
* Added an administrator-configurable CPU ceiling for long image jobs (50% by default).
* Audit, metadata/attribute scan, unused/duplicate scan, optimizer and WebP conversion pause between batches when CPU is unavailable or reaches the configured ceiling.
* Long jobs keep their cursor and stored results while paused and automatically recheck before continuing.
* CPU percentage is based on a two-sample /proc/stat utilization delta or a hosting integration filter; load average is never treated as CPU percentage.


= 0.2.3 =
* Products only reuse their own gallery; missing product images stay reported.
* 25-row reports, full KPIs, and resumable cursors past unresolved rows.
* Replaced HTTP inspection with local ALT, title and dimensions review.
* Full-library duplicate/unused scan, persistent results and exact original hashes.
* No new permanent JPG backups or administrator history.
* Automatic deletion/consolidation is not enabled; cleanup remains read-only.


= 0.2.1 =
* Four separately paginated reports: Pages, Posts, Categories and Products without assigned images.
* Each candidate is restricted to an actual category relation: first its category thumbnail, then local Media belonging to products in that category.
* Assign a selected Media image individually or apply the first valid category candidate automatically to up to 25 missing items.
* Upload a local image through WordPress Media and associate it with one chosen item.
* Preserve the existing images and avoid all supplier-image catalogs, remote imports and random global Media suggestions.
* Works standalone, with WooCommerce categories and products when that plugin is active.


= 0.2.0 =
* Restored isolated image reference audit from the historical plugin (review only; no deletion).
* Restored legacy recompression of originals and thumbnails, in small administrator-triggered batches.
* Restored JPG/PNG-to-WebP conversion and redirects for known internal legacy links.
* Added future-thumbnail exclusion settings, without removing existing thumbnails.
* Added bounded HTTP availability checks for images linked from published pages, posts and products.
* Removed all supplier-image indexing and provider image inventory from Images.
* This release must pass independent WordPress Plugin Check and staging validation before distribution.


= 0.1.3 =
* Read-only Media audit now uses bounded, resumable ID cursors.
* Detect missing physical thumbnail files and missing dimensions metadata.
* Keep unattached images distinct from images actually unused.
* A bounded AJAX continuation runs the audit automatically, with manual fallback.


= 0.1.1 =
* Removed external URL / Media import from the Images extension.
* Media import/export is delegated to TaxoCommerce Import-Export.
* Images now focuses only on audit, metadata, thumbnails and optimization.

= 0.1.0 =
* Initial modular pilot.
