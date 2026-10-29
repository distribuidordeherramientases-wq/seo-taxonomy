=== TaxoCommerce Database ===
Contributors: davidperezmartorell
Tags: woocommerce, database, mysql, backup, csv
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Inspect WordPress and WooCommerce tables, download streamed SQL or CSV backups and perform controlled maintenance.

== Description ==

TaxoCommerce Database is a standalone MySQL administration module that optionally integrates under TaxoCommerce Core.

* MySQL status: on-demand database server metrics and limited history.
* Data Table: bounded, read-only browsing of existing WordPress tables.
* Backups: streamed SQL dumps for internal or full WordPress tables, plus CSV for eligible internal tables.
* Optimize: manual ANALYZE and OPTIMIZE on eligible small internal InnoDB tables, requiring administrator confirmation.
* CSV import: INSERT-only, maximum 1 MiB and 250 rows, with validation, preview and transactional rollback on failure.
* Clean DB: full former SEO data-integrity checks and repairs, all 17 WordPress/WooCommerce cleanup types, full legacy SQL backup selection and optional direct action confirmations; no 100-row overall deletion limit.
* Operations: local activity reporting without a historical rollback engine.

Neither uploaded SQL execution nor automatic deletion of orders or products is supported. Clean DB actions are manual and may take time with large tables; no cross-plugin Data Layer or historical rollback is installed.

== Security and Privacy ==

Administrator capability and nonces are required for controlled operations. Exported SQL can contain personal data, password hashes, access tokens and credentials. Treat downloads as sensitive and store them outside public web directories. SQL exports while a store is receiving writes are not guaranteed to be transactionally consistent; for disaster recovery use a verified backup from your host.

CSV preserves original cells, potentially including spreadsheet formulas. Append-only imports are atomic but not automatically reversible. Historical tables from the removed experimental rollback feature are not dropped automatically.

== Installation ==

1. Install and activate TaxoCommerce Database on a supported WordPress 6.5+ site.
2. Open its native administrator menu, or access it under Core when Core is active.
3. Export a backup before database maintenance or CSV import.
4. Review every confirmation and use verified hosting backups for full restoration.

== Changelog ==

= 0.3.0 =
* Restore all three former Clean Database screens in isolated namespace, including SEO relations/nodes/category integrity and 17 cleanup actions.
* Remove typed cleanup words, overall 100-row deletion cap, and cross-plugin Data Layer/rollback dependency; SQL backups remain available.
* Keep standalone MySQL, Data Table, Backups, Optimize, CSV import and Operations.

= 0.2.2 =
* Refresh WordPress.org readiness against the current standalone database tools.
* Preserve bounded streamed exports, insert-only CSV import and administrator-controlled maintenance.

= 0.2.1 =
* Removed experimental historical rollback support while retaining atomic CSV import and manual maintenance.

= 0.2.0 =
* Added Clean DB diagnostics and restricted table optimization.

= 0.1.0 =
* Introduced independent MySQL inventory, exports, table browser and bounded imports.
