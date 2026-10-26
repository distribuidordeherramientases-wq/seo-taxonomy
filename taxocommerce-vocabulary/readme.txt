=== TaxoCommerce Vocabulary ===
Contributors: davidperezmartorell
Tags: woocommerce, taxonomy, attributes, classification, csv
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Canonical semantic vocabulary, product characteristics and assisted classification for WooCommerce.

== Description ==

TaxoCommerce Vocabulary helps site administrators maintain canonical labels, technical attributes and assignments to WooCommerce content.

* Uses canonical existing vocabulary, role-mapping and technical attribute storage on migrating installations.
* Provides a classifier with proposals based on product data; classification is not applied automatically.
* Supports CSV management of semantic and attribute masters and portable JSON import/export of assignments.
* Mandatory import simulation and MERGE/MIRROR modes make bulk updates reviewable.
* Numeric source IDs are audit-only: portable references are resolved against the target site's real objects.

== Installation ==

1. Install and activate TaxoCommerce Vocabulary alongside WooCommerce.
2. Check existing masters and assignments before changing anything.
3. Export a backup before running an import.
4. Simulate JSON imports, review unresolved objects and then approve intentional changes.

== Changelog ==

= 0.3.3 =
* Replace direct PHP filesystem access in CSV import/export with WordPress-compatible handling.
* Render import forms directly with escaped values instead of concatenating raw form markup.

= 0.3.2 =
* Portable semantic backups, object identity resolution and import simulation.
* MERGE/MIRROR assignment controls and fingerprinted exports.

= 0.3.0 =
* Canonical storage reuse, independent classifier and CSV master exchange.
