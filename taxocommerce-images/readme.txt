=== TaxoCommerce Images - Image Management for WooCommerce ===
Contributors: davidperezmartorell
Tags: woocommerce, images, media, thumbnails, alt, optimization
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Image management for WordPress and WooCommerce with Media as the canonical source of truth.

== Description ==

TaxoCommerce Images is an independent member of the TaxoCommerce family.

Core principles:

* WordPress Media is the source of truth.
* No custom image tables are required in 0.1.0.
* WordPress and WooCommerce native APIs remain authoritative.

Version 0.1.0 includes:

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

= 0.1.1 =
* Removed external URL / Media import from the Images extension.
* Media import/export is delegated to TaxoCommerce Import-Export.
* Images now focuses only on audit, metadata, thumbnails and optimization.

= 0.1.0 =
* Initial modular pilot.
