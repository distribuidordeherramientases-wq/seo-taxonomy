=== TaxoCommerce Distribution - Feeds & Sitemaps for WooCommerce ===
Contributors: davidperezmartorell
Tags: woocommerce, merchant, sitemap, product-feed, bing
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish WooCommerce commercial feeds and professional XML sitemaps with bounded background processing.

== Description ==

TaxoCommerce Distribution separates technical catalog publication from marketing, templates and supplier imports.

Distribution retains the proven production behavior of the previous TaxoCommerce SEO modules:

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
* Integrated Manual (user and technical sections) covering commercial feeds, sitemaps, automation, migration, storage and troubleshooting.
* On-demand, read-only Autocheck of components, registrations, configuration and local publication files, without regeneration, background jobs or network calls.

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

== Installation ==

1. Install WooCommerce and activate TaxoCommerce Distribution.
2. Open TaxoCommerce Core > Distribution, or Distribution in the WordPress menu without Core.
3. Review Overview and configure channels in Commercial Feeds before generating any inventory.
4. Review Sitemaps and Automation; during coexistence with the legacy plugin, avoid duplicate scheduled writers.
5. Open Manual for user and technical instructions. Run Autocheck to validate availability without generating files or using external services.
6. Validate staging feeds before registering production URLs with Google, Microsoft or Pinterest.

== Changelog ==

= 0.1.1 =
* Added internal Manual with user and technical instructions for existing Distribution functions.
* Added grouped Autocheck, manually executed with permissions and nonce; checks read-only availability of feeds, sitemaps, worker hooks, settings and published files.
* Added isolated no-write regression test. Development tests remain outside the distributable ZIP.

= 0.1.0 =
* Initial modular migration of commercial feeds and sitemap generation.
* Added independent bounded feed worker.
* Added safe legacy coexistence and settings migration.
* Added Distribution admin tabs for overview, feeds, sitemaps and automation.

== Historical origins (monolith; not standalone releases) ==
* [Complete legacy and release history](https://github.com/distribuidordeherramientases-wq/seo-taxonomy/tree/main/taxocommerce-distribution) (changelog.txt / CHANGELOG.md).

* Legacy origin: sitemap output and commercial product feeds were developed in TaxoCommerce SEO before the Distribution 0.1.0 extraction; previous SEO releases are not Distribution versions.
* The monolith 2.3.7 cycle documented commercial feeds alongside catalogue, market observations and Google Shopping integrations.
* Distribution 0.1.0 separated feed generation and sitemap responsibilities into an independent module while keeping established content/catalogue relationships.
* Modular compatibility work retained a fallback for Core role data so distribution does not require the legacy monolith at runtime.
* Follow-up work addressed WordPress.org translation comments and package validation; these are maintenance changes to the same 0.1.0 line, not claimed additional releases.
