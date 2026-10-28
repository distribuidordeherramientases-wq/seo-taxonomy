=== TaxoCommerce Images - Image Management for WooCommerce ===
Contributors: davidperezmartorell
Tags: images, media, thumbnails, optimization, webp
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.10
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

= 0.2.9 =
* Changed unused/duplicate analysis and labels/attributes review into full-library jobs that continue automatically until the complete Media snapshot is scanned.
* Kept adaptive 1-5 CPU/memory regulation as an internal batching mechanism only; batch size no longer reads as the scope of the report.
* Added explicit KPI/report counts for total/scanned images, used/unused media, exact SHA-256 duplicates, duplicate keepers, missing ALT, titles to review, dimensions, missing files and review cases.
* Added report filters for actionable findings, unused images, duplicate groups, used images, review cases and all rows.
* Duplicate detection remains exact original-file SHA-256 comparison; names, dimensions and file sizes alone never classify a duplicate.

= 0.2.8 =
* Added independent adaptive workload control for bounded local image scans.
* Added request-local CPU, memory and query observations without hosting-level CPU dependencies.
* Kept Images workload priority independent from Dependiente and other modules.

= 0.2.7 =
* Added explicit WordPress Media selection for individual image assignment.
* Preserved the rule that products never borrow another product's image automatically.

= 0.2.5 =
* Removed the hosting-dependent CPU checker and /proc/stat gating.
* Long-running audits use bounded batches, persistent cursors, request pacing and mutation locks.
* Image anomaly, label/attribute and unused/duplicate scans register in TaxoCommerce Core Processes.

= 0.2.4 =
* Previous CPU checker release; superseded by 0.2.5 because hosting-level CPU metrics are not portable from WordPress.


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

== 0.2.10 ==
* Media audit in Images > Anomalías has a dedicated five-speed adaptive load regulator, independent from assignment, labels and cleanup jobs.
* Automatically sizes batches from 25 to 1200 attachments depending on the selected speed and observed time, request-local CPU and memory pressure; no longer fixed at 75.
* The audit keeps its scan cursor and existing findings when the administrator changes speed. No attachments are modified or removed.
* With the audit tab open, a sequential AJAX continuation processes the next batch immediately. Pause and resume controls apply to this browser tab; the manual Next batch action remains available.
* Live progress shows processed images, last batch size and duration, next batch goal, throughput and PHP load. Host-wide CPU is not estimated by the PHP worker.
* Existing per-attachment anomaly checks remain unchanged.
