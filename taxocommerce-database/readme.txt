=== TaxoCommerce Database ===
Contributors: davidperezmartorell
Tags: woocommerce, mysql, database, backup, seo
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Standalone migration of the original Clean Database and SEO Data Table tools from TaxoCommerce SEO.

== Description ==

Version 0.4.1 preserves the two original database tools:

* Clean Database: Data Errors, Database Cleanup and Database Export tabs.
* Validation and repair of SEO nodes, relationships, scopes, categories and bulk supplier acceptance locks.
* All 17 original WordPress, WooCommerce and Action Scheduler cleanup actions, without a total deletion limit of 100 records or typed confirmation words. Cleanup remains manual and synchronous as in the original plugin.
* Full WordPress database SQL backup and SEO-only SQL backup.
* SEO Data Table: SEO table inventory, searchable and sortable explorer, per-table CSV/SQL exports and ZIP archives of all SEO tables in CSV, SQL or both.

The only deliberate omission is the cross-plugin Data Layer and its operation history and rollback features. SEO table repairs use standalone SQL transactions. No other plugin needs this module in order to record its database changes. Backups provide the recovery mechanism.

The newer, additional MySQL diagnostics, replacement table explorer, optimization, CSV importer, separate backups and maintenance services have been removed. The original administrator permissions, nonce verification and normal delete confirmation dialogs remain.

== Installation ==

1. Install and activate TaxoCommerce Database.
2. Open Database in its own menu, or under TaxoCommerce Core if Core is active.
3. Use the original Clean Database and SEO Data Table tools.
4. Download an SQL backup before any irreversible cleanup operation.

== Changelog ==

= 0.4.1 =
* Show the six original screens in one horizontal responsive tab bar, preserving all existing routes and actions.
* Restored the two original database services and removed duplicated or non-original tools.
* Kept the original diagnostics, repairs, cleanup actions and data exports.
* Excluded the cross-plugin Data Layer and historical rollback support.
