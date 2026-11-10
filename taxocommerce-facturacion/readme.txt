=== TaxoCommerce Billing ===
Contributors: davidperezmartorell
Tags: woocommerce, invoices, proforma, quotes, pdf
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.11
License: MIT

WooCommerce invoices, proformas and quotations migrated from TaxoCommerce SEO.

== Description ==

* The developer's Plugin URI is the only official source; the public REST excerpt is cached and its last verified value survives connection failures. On a first-install offline shop, the plugin author's bundled Description is displayed until connectivity returns. The client cannot edit the official URL. History remains local and separate.



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
* Integrated user and technical Manual on the Billing settings screen.
* On-demand, read-only Autotest to check module availability, saved settings, document table, PDF loader and WooCommerce hooks without issuing documents or sending emails.

The plugin reuses the existing seo_facturas_* options and seo_factura_documents table.
It does not create a parallel document database and does not include a destructive uninstall routine.

== Installation ==

1. Install and activate WooCommerce and TaxoCommerce Billing.
2. Open TaxoCommerce > Billing when TaxoCommerce Core is active (otherwise use the Billing menu).
3. Review Datos empresa and fiscal settings, then enable the document system only after verifying company details and document series.
4. Configure invoices, proformas and cart quotations as required. Review the integrated Manual and run Autotest before using real customer orders.
5. Confirm PDF generation and permitted customer downloads in a staging environment before live billing.

== Changelog ==

= 0.1.11 =
* Display the last verified official excerpt from the developer's Plugin URI during HTTP errors; avoid stale negative cache and show bundled author Description on first-install network failure. No dependency on customer content. Issue #2489.



= 0.1.10 =
* Three-zone responsive admin header, centered official developer information and own Markdown history; remove shop administrator URL configuration. Issue #2476.

= 0.1.6 =
* Added an optional, collapsed version history beneath the admin header title, reading only this plugin's bundled CHANGELOG.md. No new admin tab, Core dependency, data changes or external requests.


= 0.1.4 =
* Unifica «Autocheck» y permite descargar JSON autenticado de los resultados de la última ejecución, sin modificar servicios ni contenidos.

= 0.1.3 =
* Cabecera administrativa uniforme de TaxoCommerce con nombre, icono y versión visible.
* Compatible sin Core; controles, pestañas y permisos intactos.


= 0.1.2 =
* Billing administration now has a unique route, separate from the monolithic plugin, with eight visible tabs including Manual and Autotest.
* Preserved existing invoice, proforma and quotation functionality while removing duplicate legacy navigation from the independent screen.
* Added an isolated routing regression test covering coexistence with the legacy controller.

= 0.1.1 =
* Added in-plugin user and technical manuals for Billing administration and WooCommerce integrations.
* Added a read-only Autotest, restricted to administrators, without document generation, database modification or outgoing requests.
* Added isolated regression coverage; developer tests remain excluded from release ZIPs.

= 0.1.0 =
* Migrated the existing document module into an independent TaxoCommerce plugin.

== Historical origins (monolith; not standalone releases) ==
* [Complete legacy and release history](https://github.com/distribuidordeherramientases-wq/seo-taxonomy/tree/main/taxocommerce-facturacion) (changelog.txt / CHANGELOG.md).

* Historical source: document generation, invoices and quotations were maintained in the TaxoCommerce SEO monolith before the standalone Billing module; this section is not a Billing release.
* The legacy implementation used seo_facturas_* options and the seo_factura_documents table; the standalone extraction retained those stores instead of resetting customer documents.
* Earlier checkout and tax corrections avoided adding VAT twice to retail prices already expressed with tax included.
* The original document enable/disable control was restored after an earlier regression; billing behavior depends on those explicit administrative settings.
* Standalone 0.1.0 was a one-to-one extraction of the legacy billing/document functions, keeping existing options and document data rather than redesigning accounting.

== External Services ==

Optional: if an administrator enters an HTTPS URL to an official WordPress page, the plugin requests that site's public `/wp-json/wp/v2/pages` endpoint with the page slug to read only its published excerpt and link. No visitor identity or credentials are submitted. A 12-hour cache (15 minutes after failure) avoids repeated requests. The administrator chooses and should review the source site's privacy policy and terms. No provider is configured by default.

== Official excerpt ==

The optional admin information panel defaults to this plugin's `Plugin URI`, a public project page. Only its published excerpt is retrieved via safe WordPress REST HTTPS and cached locally. Administrators may override or explicitly clear the URL to disable requests. No network dependency for core functionality.
