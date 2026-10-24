=== TaxoCommerce Logística ===
Contributors: davidperezmartorell
Tags: woocommerce, logistics, shipping, suppliers, orders
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: MIT

WooCommerce order, supplier and shipping management migrated from TaxoCommerce SEO.

== Description ==

TaxoCommerce Logística keeps the existing logistics workflow while moving it into an independent plugin.

Features:

* Active WooCommerce order management.
* Supplier matching through the existing seo_proveedores_productos table.
* Supplier purchase and catalog links.
* Shipping rules by region, order amount, weight and dimensions.
* JSON import and export for shipping rules.
* WooCommerce shipping rate integration.
* Optional integration with TaxoCommerce Core.

The plugin reuses the historical seo_transporte_costes_settings option and does not create a parallel supplier table.
It does not include a destructive uninstall routine.

== Changelog ==

= 0.1.0 =
* Migrated the existing Order Management and Shipping modules into an independent TaxoCommerce plugin.
