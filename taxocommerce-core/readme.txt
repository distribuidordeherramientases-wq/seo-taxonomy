=== TaxoCommerce Core - Catalog & Content Management for WooCommerce ===
Contributors: davidperezmartorell
Tags: woocommerce, catalog, content, management, seo
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A modular WordPress/WooCommerce core for catalog hierarchy, unified content editing, processes, logs, and future TaxoCommerce extensions.

== Description ==

TaxoCommerce Core is the clean modular foundation for the next TaxoCommerce architecture. The 0.2.0 pilot is validated and deployed independently to STAGING.

Version 0.3.1 includes a unified Content Manager, WordPress-native SEO hierarchy and extension installer links for:

* WooCommerce products.
* WooCommerce product categories.
* WordPress posts.
* WordPress pages.

WordPress and WooCommerce remain the canonical source of truth. Core does not duplicate those objects into parallel tables.

The pilot also includes:

* A module registry contract.
* A process registry contract.
* A bounded technical log.
* A hierarchy shell for the future Cluster -> Primary Hub -> Secondary Hub -> Category -> Product model.
* Extension hooks for KPIs and future modules.

== Changelog ==

= 0.4.4 =
* Process registry now exposes progress, detail and an extension action URL.
* Process Manager can display the automatic Vocabulary classifier as a real managed process.
* Updated Vocabulary family description for automatic inventory classification.

= 0.4.3 =
* Added TaxoCommerce Vocabulary to the family catalog.
* Vocabulary is distributed by ZIP only until the owner publishes it.
* Added support for family extensions that intentionally are not yet listed on WordPress.org.

= 0.4.2 =
* Added a dedicated "Ampliar familia" screen in Core.
* TaxoCommerce Import-Export and TaxoCommerce Images are always visible there, even before installation.
* Each family extension offers WordPress.org search and ZIP upload when not installed.
* Installed extensions can be activated from the family screen.
* Active extensions can be opened or managed from Core.

= 0.4.1 =
* Added TaxoCommerce Images to the built-in family catalog.
* Core can detect, activate, deactivate and open the Images extension like Import-Export.

= 0.4.0 =
* Added Core menu manager based on the native SEO hierarchy.
* Generates a proposal from Cluster -> Primary Hub -> Secondary Hub -> Product Category.
* Creates and synchronizes a native WordPress navigation menu.
* Keeps manually added menu items during synchronization.
* Adds a native WordPress manual-edit shortcut.
* Can activate the managed menu in a registered theme location and restore the previous location map.

= 0.3.2 =
* Added built-in TaxoCommerce family catalog starting with Import-Export.
* Detects family extensions even when installed but inactive.
* Adds per-extension search in WordPress.org and ZIP upload actions.
* Adds secure Activate / Deactivate controls from Core.
* Active extensions may merge their administration pages into the Core menu.

= 0.3.1 =
* Extensions screen can open the native WordPress.org search and native ZIP upload installer.
* Registered TaxoCommerce extensions can expose an Open action back into their own administration page.
* Prepared Core integration for TaxoCommerce Import-Export.

= 0.3.0 =
* Added SEO hierarchy management: Cluster -> Primary Hub -> Secondary Hub -> Product Category.
* Added one SEO structural role per WordPress page.
* Stores hierarchy roles and links in native post meta / term meta; no duplicate page/category tables.
* Imports the active hierarchy once from the current TaxoCommerce SEO helper APIs when both plugins coexist.
* Added hierarchy KPIs, role filters, relation forms, tree view and unlink actions.
* Does not copy legacy seo_nodes or seo_relations tables.

= 0.2.1 =
* Spanish administration labels throughout the Core.
* Renamed Dashboard, Content, Hierarchy, Processes, Modules and Logs to Inicio, Contenidos, Jerarquía SEO, Procesos automáticos, Extensiones and Registro técnico.
* Added a migration map showing the equivalent feature in the current TaxoCommerce SEO plugin and migration status.
* Added equivalent-current-system notices to the main Core sections.

= 0.2.0 =
* Added unified content lists, filters and editing.
* Added basic rebuildable KPIs.
* Product editing uses WooCommerce CRUD.
* Category editing uses WordPress taxonomy APIs.
* Post and page editing uses WordPress APIs.
* No custom SQL or duplicate catalog/content tables.

= 0.1.0 =
* Initial modular Core pilot.

Deployment marker: Core 0.2.0.
