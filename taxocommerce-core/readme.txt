=== TaxoCommerce Core - Catalog & Content Management for WooCommerce ===
Contributors: davidperezmartorell
Tags: woocommerce, catalog, content, management, seo
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A modular WordPress/WooCommerce core for catalog hierarchy, unified content editing, processes, logs, and future TaxoCommerce extensions.

== Description ==

TaxoCommerce Core is the clean modular foundation for the next TaxoCommerce architecture. The 0.2.0 pilot is validated and deployed independently to STAGING.

Version 0.3.0 includes a unified Content Manager and WordPress-native SEO hierarchy for:

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
