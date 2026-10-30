=== TaxoCommerce Billing ===
Contributors: davidperezmartorell
Tags: woocommerce, invoices, proforma, quotes, pdf
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: MIT

WooCommerce invoices, proformas and quotations migrated from TaxoCommerce SEO.

== Description ==

TaxoCommerce Billing keeps the existing document workflow while moving it into an independent plugin.

Features:

* Company and tax settings.
* Invoices and proforma invoices.
* Cart quotations for classic Cart and Cart Block.
* Order document registry and snapshots.
* Document numbering and series.
* PDF generation using the same Dompdf 3.1.6 engine as the historical module.
* Email attachments and resending.
* WooCommerce order metabox and customer documents.

The plugin reuses the existing seo_facturas_* options and seo_factura_documents table.
It does not create a parallel document database and does not include a destructive uninstall routine.

== Changelog ==

= 0.1.0 =
* Migrated the existing document module into an independent TaxoCommerce plugin.

== Historical origins (monolith; not standalone releases) ==
* Historical source: document generation, invoices and quotations were maintained in the TaxoCommerce SEO monolith before the standalone Billing module; this section is not a Billing release.
* The legacy implementation used seo_facturas_* options and the seo_factura_documents table; the standalone extraction retained those stores instead of resetting customer documents.
* Earlier checkout and tax corrections avoided adding VAT twice to retail prices already expressed with tax included.
* The original document enable/disable control was restored after an earlier regression; billing behavior depends on those explicit administrative settings.
* Standalone 0.1.0 was a one-to-one extraction of the legacy billing/document functions, keeping existing options and document data rather than redesigning accounting.
