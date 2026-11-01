=== TaxoCommerce Core - Catalog & Content Management for WooCommerce ===
Contributors: davidperezmartorell
Tags: woocommerce, catalog, content, management, seo
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.41
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
* Built-in, read-only User Guide tab containing a user manual and a technical manual for Core.

Editorial guidance does not create or modify content automatically. Only authorised administrators can upload an optional extension ZIP through Core.

== Languages ==

TaxoCommerce Core uses the WordPress gettext domain `taxocommerce-core` and the plugin `/languages` directory. The WordPress administration locale determines the language automatically. Bundled gettext catalogs cover Spanish (Spain), English (US), French, German and Dutch, with translation coverage under active expansion; technical names and catalog content remain unchanged. WordPress.org language packs will be supported once translations are approved.

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

= 0.4.41 =
* Load any existing native WordPress navigation menu into Core's menu editor without changing its country-managed menu or current theme location.
* Edit manual items (title, custom URL, parent and position) and create independent new WordPress menus from Core.
* Keep SEO-generated elements read-only, preserve country synchronization and require permissions and nonces for modifications.


= 0.4.39 =
* Package-only Plugin Check remediation: deploy and distribute without development tests or hidden FTP state files.
* Keep the full smoke/regression test suite in GitHub and CI; preserve all Core menus, country scopes, content, SEO architecture, Auto Test, and manuals.
* Verify staged package contents and clean legacy Core FTP artifacts on STAGING after deployment. No changes to WordPress content or database.


= 0.4.38 =
* Align the Logistics extension catalog link and family submenu label with the standalone module's unique admin page.
* Preserve the legacy TaxoCommerce SEO order view and all shipping settings without moving data.


= 0.4.37 =
* Move Core Auto Test into the Core Overview / User Guide / Auto Test internal tab bar rather than a WordPress sidebar submenu.
* Retain the 17 read-only checks, user transient, nonces, dashboard navigation and old URL compatibility via a safe redirect.
* No new tables or modifications to tests, catalog, SEO hierarchy, menus or extension management.


= 0.4.36 =
* Add Technical Manual alongside User Manual in the existing User Guide screen.
* Describe Core architecture, hooks, data, countries, permissions, caching, deployment and diagnostics using semantic headings and responsive tables.
* Preserve the original user manual and all operational Core screens.


= 0.4.35 =
* Add a User Guide tab with semantic H1/H2/H3 headings, table of contents, direct links and responsive presentation.
* Document Content, SEO Hierarchy, country menus, Auto Test and optional extensions without changing existing operations.


= 0.4.34 =
* Add a native Auto Test screen and dashboard shortcut for administrator-triggered, read-only functional checks.
* Report individual pass/fail/skipped results with error details, timings and version, isolating failures between checks.
* Cover runtime, WP/WooCommerce content reads, country metadata, SEO roles and relation samples, country menus, modules, vocabulary and architecture summary. Never create, delete or sync real content during tests.
* Keep the last test result in a user-specific transient for one day; writing UAT remains explicitly outside the production-safe suite.


= 0.4.33 =
* Ensure each country creates, saves and edits a dedicated native WordPress menu with a distinct country-specific name.
* Prevent stale country options from reusing and overwriting another market's menu ID; restore the correct market association by native menu name.
* Rename legacy Spanish TaxoCommerce menu in place when synchronised, preserving its menu ID and content.
* Show the selected native menu name in the editor and add regression coverage for cross-country menu ID collisions.


= 0.4.32 =
* Fix existing SEO hierarchy tables that missed the country_code migration: both seo_nodes and seo_relations are checked, without deleting or replacing existing records.
* Persist page country_code when saving or reusing a role node; synchronize active role country when page country metadata changes.
* Keep legacy relations and Spanish hierarchy intact. Relation country is also independently checked when both SEO tables already exist.
* Add isolated QA for the pre-existing-table migration and country-aware node saves.

= 0.4.31 =
* Adds a market-country selector to Core content editors for posts, pages, products and WooCommerce categories, preserving existing country metadata.
* Adds optional country_code to seo_relations and country-filtered hierarchy editing without overwriting legacy links.
* Provides independent managed menus per country and a front-end selector for TaxoCommerce-managed theme menu locations.
* The original Spanish menu and relationships remain valid; international categories are never blanket-labelled Spanish.


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

== Historical origins (monolith; not standalone releases) ==
* [Complete legacy and release history](https://github.com/distribuidordeherramientases-wq/seo-taxonomy/tree/main/taxocommerce-core) (changelog.txt / CHANGELOG.md).

* Historical source: the TaxoCommerce SEO monolith through 2.4.1 maintained the SEO architecture, category/product relationships, taxonomy and the original WordPress administration; these are not Core standalone versions.
* The 2.2.x legacy work included the vocabulary bridge, canonical semantic labels and the product/category relationship model.
* During 2.3.x the monolith organised clusters, primary and secondary hubs, landing pages, menus and associated WooCommerce categories/products.
* Core became the administrative and integration point for independent TaxoCommerce extensions, preserving the existing data contracts rather than migrating every service into Core.
* Later standalone work refined the Extensions panel, nested menu navigation, WordPress.org compliance and internal Auto Test. Individual learning, images and feeds remain owned by their respective modules.
* The international storefront uses explicit country-based pages, templates and navigation; the Core layer retains role/menu selection while Templates owns frontend rendering.
