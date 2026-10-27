=== TaxoCommerce Core - Catalog & Content Management for WooCommerce ===
Contributors: davidperezmartorell
Tags: woocommerce, catalog, content, management, seo
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.30
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage WooCommerce catalog structure and WordPress content from a modular dashboard with optional independent extensions.

== Description ==

TaxoCommerce Core provides a modular foundation for WordPress and WooCommerce catalog and content management. WordPress posts, pages, terms and WooCommerce products remain the canonical data sources; Core does not duplicate them into a separate content store.

* Editorial management for products, product categories, posts and pages using native WordPress editing.
* Cached read-only inventory dashboard for products, posts, pages, categories, media, statuses and optional FAQs.
* SEO architecture based on clusters, primary hubs, secondary hubs, product categories and products.
* Native WordPress navigation menu generation with optional manual entries and safe regeneration.
* Architecture reporting with unique counts, explicit manual refresh and local 15-minute caching.
* Read-only editorial guidance for products, categories, posts and pages based on useful content and product identity.
* Process and extension registries with bounded technical logs for independent modules.
* Optional extension discovery for the other TaxoCommerce modules.

Editorial guidance does not create or modify content automatically. Only authorised administrators can upload an optional extension ZIP through Core.

== Installation ==

1. Install and activate TaxoCommerce Core on WordPress 6.5 or newer.
2. Open the Core dashboard to configure content and SEO architecture.
3. If using WooCommerce, inspect product and category information before importing data.
4. Install any optional TaxoCommerce extensions separately and review their dependencies.
5. During migration, verify unique module ownership and avoid simultaneous writes by the original SEO plugin.

== Frequently Asked Questions ==

= Does Core need the original TaxoCommerce SEO plugin? =

No. Core operates independently; integrations with other modules are optional.

= Does Core automatically rewrite my product descriptions? =

No. Editors and guidance preserve manual author control.

= Does Core support optional extension installation? =

Yes. Administrators may explicitly upload a verified module ZIP through Core's extension panel. Native WordPress installation APIs install the package after temporary validation.

== Changelog ==

= 0.4.30 =
* Move Extensions from the Core submenu into responsive cards at the bottom of the Core home screen.
* Show live installed/active/ZIP states, version, descriptions and guarded management actions per extension.
* Remove redundant migration descriptions while retaining old bookmarks via a redirect.


= 0.4.29 =
* Added Core > Content > Inventory summary with read-only totals, 15-minute cache and an administrator-initiated protected refresh.
* Counted canonical WordPress/WooCommerce records without invoking Auditor scans.
* Refresh WordPress.org readiness against the current modular Core implementation.
* Preserve native content, hierarchy, menu and extension-management contracts while validating the standalone package.

= 0.4.28 =
* Consolidated external connections under TaxoCommerce Insights.
* Retained independent extension discovery and administrator-initiated installation.

= 0.4.27 =
* Clarified Supplier and Logistics responsibilities.

= 0.4.26 =
* Registered Suppliers in the extension catalogue.

= 0.4.24 =
* Registered Logistics and Billing modules.

= 0.4.12 =
* Added FAQ content screens and removed an unused technical log page.

= 0.4.10 =
* Added administrator-controlled process management.

= 0.4.9 =
* Added editing guidance for all four content types.

= 0.4.8 =
* Consolidated extension management into one screen.

= 0.4.0 =
* Added native WordPress menu management for the SEO hierarchy.

= 0.3.0 =
* Introduced the native cluster and hub hierarchy.
