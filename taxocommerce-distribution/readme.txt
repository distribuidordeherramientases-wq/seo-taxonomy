=== TaxoCommerce Distribution - Feeds & Sitemaps for WooCommerce ===
Contributors: davidperezmartorell
Tags: woocommerce, merchant, sitemap, product-feed, bing
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

== Language ==

English is the base source and UI language. Future localization should use standard WordPress gettext translations without changing public feed filenames or compatibility keys.

== Changelog ==

= 0.1.0 =
* Initial modular migration of commercial feeds and sitemap generation.
* Added independent bounded feed worker.
* Added safe legacy coexistence and settings migration.
* Added Distribution admin tabs for overview, feeds, sitemaps and automation.

= Historical origin in TaxoCommerce SEO (through 2.4.1; not a standalone release) =
* Legacy origin: sitemap output and commercial product feeds were developed in TaxoCommerce SEO before the Distribution 0.1.0 extraction; previous SEO releases are not Distribution versions.
* The monolith 2.3.7 cycle documented commercial feeds alongside catalogue, market observations and Google Shopping integrations.
* Distribution 0.1.0 separated feed generation and sitemap responsibilities into an independent module while keeping established content/catalogue relationships.
* Modular compatibility work retained a fallback for Core role data so distribution does not require the legacy monolith at runtime.
* Follow-up work addressed WordPress.org translation comments and package validation; these are maintenance changes to the same 0.1.0 line, not claimed additional releases.
