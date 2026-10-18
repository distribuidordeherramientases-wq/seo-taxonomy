=== TaxoCommerce Import-Export - Import & Export for WooCommerce ===
Contributors: davidperezmartorell
Tags: woocommerce, csv, import, export, media
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import and export catalog, content and Media for WordPress/WooCommerce.

== Description ==

TaxoCommerce Import-Export is an independent member of the TaxoCommerce family.

Version 0.2.0 includes:

* Products CSV import/export.
* Product categories CSV import/export.
* Posts CSV import/export.
* Pages CSV import/export.
* Media image ZIP export with original files, metadata and relationships.
* Media image ZIP import into real WordPress attachments.
* Featured-image, WooCommerce gallery and product-category image relationship restoration.
* WordPress-generated thumbnails are rebuilt on import instead of transported.
* Optional integration into TaxoCommerce Core.
* No direct SQL.

== Media package ==

A Media export contains:

* manifest.json
* media.csv
* files/<old-attachment-id>/<original-file>

The package transports original image files plus title, ALT, caption, description, post_parent and known image relationships.

== Changelog ==

= 0.2.0 =
* Added Media image ZIP import/export.
* Moved Media transport responsibility out of TaxoCommerce Images.
* Avoided early translation calls during Core registration.

= 0.1.1 =
* Core family integration pilot.

= 0.1.0 =
* Initial CSV import/export pilot.
