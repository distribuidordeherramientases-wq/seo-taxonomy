=== TaxoCommerce Images - Image Management for WooCommerce ===
Contributors: davidperezmartorell
Tags: woocommerce, images, media, thumbnails, alt, optimization
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Image management for WordPress and WooCommerce with Media as the canonical source of truth.

== Description ==

TaxoCommerce Images is an independent member of the TaxoCommerce family.

Core principles:

* WordPress Media is the source of truth.
* External URLs are intake sources, not a permanent image repository.
* External images can be downloaded into Media before assignment.
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
* External URL sideload into Media.
* Optional assignment as featured image or WooCommerce gallery image.
* Upload scaling and image quality settings.
* Optional integration into TaxoCommerce Core.

== Changelog ==

= 0.1.0 =
* Initial modular pilot.
