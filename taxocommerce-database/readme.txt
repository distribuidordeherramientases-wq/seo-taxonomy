=== TaxoCommerce Database ===
Contributors: davidperezmartorell
Tags: woocommerce, database, mysql, backup, csv
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Standalone WordPress database inventory, MySQL diagnostics, selective SQL/CSV exports and controlled maintenance.

== Description ==

TaxoCommerce Database provides manually initiated WordPress database inspection, maintenance and backups. It functions independently; TaxoCommerce Core offers optional grouped administration.

* MySQL: on-demand server and table diagnostics, without continuous scanning.
* Data Table: read-only inventory and paginated browsing of supported module tables.
* Backups: download CSV or SQL for selected internal tables, or SQL for all WordPress-prefix tables, without writing dumps to public uploads.
* Optimize: manually confirm bounded ANALYZE TABLE and OPTIMIZE TABLE actions on supported internal tables.
* CSV imports: preview and validate insertion into supported InnoDB internal tables; limits are 1 MiB and 250 rows. A transaction cancels the entire import on error. This is not a general SQL restore feature.
* Clean DB: identify expired transients and sessions and report stale records. Bounded removal covers expired/disposable data only and does not directly delete orders, products or categories.
* Operations: local informational log; no historical rollback engine.

The UI requires administrator capabilities and uses nonces. As with any database tool, a validated hosting backup is required before changing live data.

== Installation ==

1. Upload and activate TaxoCommerce Database under WordPress Plugins.
2. Open its menu (or the optional TaxoCommerce Core submenu).
3. Begin with a read-only inventory and export a backup of the relevant tables.
4. Test each optimization, import or cleanup against STAGING before acting on production.

== Privacy and Limitations ==

Database exports may contain customer personal information, passwords or hashes, authentication tokens and API keys. Handle downloaded files as sensitive records: encrypt them, restrict access and delete them under your retention policy.

SQL dumps created during concurrent store updates are not guaranteed to be transactionally consistent. Use verified hosting backups for complete recovery. Exported CSV values may be interpreted as formulas by spreadsheet programs; inspect untrusted content before opening exports.

An earlier experimental rollback history was discontinued in 0.2.1. This module does not offer one-click historical restoration.

== Changelog ==

= 0.2.1 =
* Removed experimental historical rollback and its endpoints.
* Kept independent CSV inserts, backups, MySQL diagnostics and bounded cleanup.

= 0.2.0 =
* Added extended manual maintenance diagnostics and optimization limits.

= 0.1.0 =
* Initial standalone MySQL inventory and safe data operations.
