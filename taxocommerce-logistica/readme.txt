=== TaxoCommerce Logistics ===
Contributors: davidperezmartorell
Tags: woocommerce, logistics, shipping, weight, orders
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0
License: MIT

Manage shipping costs, package weight and dimensions in WooCommerce with manual rules and shipment audits.

== Description ==

TaxoCommerce Logistics is the shipping-cost domain for the TaxoCommerce family.

Features:

* Active WooCommerce order weight audit.
* Missing product-weight detection.
* Shipping method and charged shipping-cost visibility.
* Shipping rules by region, order amount, weight, dimensions, and volume.
* Fixed, per-kg, and per-unit shipping cost formulas.
* JSON import and export for shipping rules.
* WooCommerce shipping rate integration.
* Optional integration with TaxoCommerce Core.

The plugin reuses the historical seo_transporte_costes_settings option, so existing shipping rules remain intact.
It does not contain supplier matching, purchasing, or supplier-catalog logic.

== Changelog ==

= 0.2.0 =
* Move sale-to-supplier purchasing to TaxoCommerce Suppliers.
* Keep Logistics focused on order weights and shipping costs.
* Add active-order shipping weight and charged-cost audit.

= 0.1.0 =
* Migrated the existing Order Management and Shipping modules into an independent TaxoCommerce plugin.
