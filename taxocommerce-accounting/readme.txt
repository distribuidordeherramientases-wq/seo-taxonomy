=== TaxoCommerce Accounting ===
Contributors: davidperez
Tags: woocommerce, accounting, invoices, vat, bookkeeping
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Independent WooCommerce fiscal register with purchase invoices, monthly VAT estimates and internal exports.

== Description ==

TaxoCommerce Accounting records fiscal data without changing WooCommerce orders, customers, supplier catalogues or invoice generation.

* Configure a per-store first commercial date. All earlier test orders are excluded from new sales imports.
* Manually import paid-status WooCommerce orders in bounded batches of 25, with deduplicated draft entries.
* Capture supplier purchase invoices, business expenses, capital assets and special cross-border operations manually.
* Store taxable base, input/output VAT, total, tax classification, deductible percentage, document number and supplier data.
* Inspect monthly fiscal balances using validated domestic records only.
* Review indicative Model 303 figures and export internal CSV or JSON.
* Run a read-only functional Autocheck with explicit incomplete-data errors.
* Standalone administration if TaxoCommerce Core is absent; nested TaxoCommerce administration when present.
* Uses a dedicated prefix-aware database table and WooCommerce's public order APIs (including HPOS).

Important: The financial overview is preliminary, NOT an official VAT form, an accounting system with double-entry books, tax/legal advice or a replacement for invoices. No forms are filed or submitted to the Spanish Tax Agency. Ensure applicability and deductible VAT with a qualified advisor before approving.

== Installation ==

1. Install WooCommerce and activate TaxoCommerce Accounting.
2. Find Accounting under TaxoCommerce if Core exists; otherwise find the Accounting menu.
3. Configure your first real commercial date in Settings before importing orders.
4. Import paid WooCommerce orders page by page. Entries remain drafts.
5. Record actual supplier invoices and expense receipts manually.
6. Check financial and tax treatment; validate eligible records, then inspect reports and exports.

All entries are retained when the plugin is deactivated or uninstalled; this protects fiscal records.

== Frequently Asked Questions ==

= Does it automatically modify or issue WooCommerce invoices? =
No. Invoice emission and series remain with the shop's existing invoicing solution.

= Can I use it without any other TaxoCommerce plugin? =
Yes. Only WooCommerce is required. TaxoCommerce Core is optional.

= Does the Model 303 screen file my return? =
No. It is a working summary of validated domestic records, not an official AEAT filing.

= Does it treat every business expense as deductible? =
No. Input VAT defaults to 0% deductible and records start in draft. A qualified professional should verify the tax treatment.

== Changelog ==

= 0.1.0 =
* Initial standalone accounting register, fiscal summaries, draft WooCommerce import, manual invoices, CSV/JSON exports and functional Autocheck.

== Privacy ==

Stores customer names, invoice numbers and supplier tax identifiers in an administrator-only database table. Does not transmit data externally.
