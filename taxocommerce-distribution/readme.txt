=== TaxoCommerce Distribution - Feeds & Sitemaps for WooCommerce ===
Contributors: davidperezmartorell
Tags: woocommerce, merchant, sitemap, product-feed, bing, pinterest
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish WooCommerce commercial feeds and professional XML sitemaps with bounded background processing.

== Description ==

TaxoCommerce Distribution separates technical catalog publication from marketing, templates and supplier imports.

Version 0.1.0 migrates the proven production behavior of the previous TaxoCommerce SEO modules:

* Google Merchant Center XML feed.
* Microsoft Merchant Center / Bing Shopping feed.
* Pinterest Catalogs CSV feed.
* Universal CSV and JSON catalogs.
* Adaptive, cursor-based feed generation in bounded worker windows.
* Atomic feed publication: partial files never replace the last valid feed.
* Product, product-category, post, page, cluster and hub XML sitemaps.
* Product sitemap files split into bounded groups.
* XML validation before publication.
* Atomic sitemap publication and stable root-domain URLs.
* Automatic sitemap regeneration after catalog/content changes when Distribution owns the endpoint.
* Safe coexistence mode with the legacy monolithic plugin during migration.

== Compatibility ==

Public filenames and storage remain compatible with the existing installation:

* google-merchant.xml
* microsoft-merchant.txt
* pinterest-catalog.csv
* catalogo-universal.csv
* catalogo-universal.json
* /sitemap.xml and its child sitemap files

When the legacy plugin is active, it retains scheduled ownership to avoid duplicate workers. Manual generation from Distribution remains available for parity testing. Once the legacy owner is removed, Distribution schedules its own feeds and sitemap updates.

== Changelog ==

= 0.1.0 =
* Initial modular migration of commercial feeds and sitemap generation.
* Added independent bounded feed worker.
* Added safe legacy coexistence and settings migration.
* Added Distribution admin tabs for overview, feeds, sitemaps and automation.
