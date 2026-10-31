=== TaxoCommerce Logistics ===
Contributors: davidperezmartorell
Tags: woocommerce, logistics, shipping, weight, orders
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.4
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

= 0.2.4 =
* Integrate the Logistics menu into TaxoCommerce Core when installed, keeping the standalone fallback when Core is absent.
* Preserve the Shipping Rules, Auto Test and Manual tabs and the existing shipping-option storage.
* Remove the redundant separate WordPress sidebar entry while Core is active.


= 0.2.3 =
* Fix escaped output of list tags in the user and technical manuals.
* Standardize the built-in documentation tab as Manual, with Manual de usuario and Manual técnico, preserving its existing URLs.
* Keep Auto Test with 35 read-only checks and before/after comparisons.


= 0.2.2 =
* Add User Guide tab with an indexed User Manual (10 chapters) and Technical Manual (11 chapters), using the same layout as the TaxoCommerce family.
* Explain real shipping regions, fees, rule priorities, missing dimensions, order audits, JSON configuration backups and the before/after Auto Test.
* Keep documentation read-only: it never loads order audits, calculates live shipping, changes settings or imports files.


= 0.2.1 =
* Add separate Auto Test tab with 35 read-only checks in eight functional areas and pre/post deployment comparison.
* Check WooCommerce shipping hooks, rates, region mapping, weight/dimensions, fixed/variable/free cost rules, saved settings and import/export controllers without changing real orders or shipping rules.
* Store BEFORE and latest diagnostics by site and administrator; export JSON, detect new failures and lost check coverage.
* Mark checkout, tax, real imports and cache tests as unverified until run against staging.


= 0.2.0 =
* Move sale-to-supplier purchasing to TaxoCommerce Suppliers.
* Keep Logistics focused on order weights and shipping costs.
* Add active-order shipping weight and charged-cost audit.

= 0.1.0 =
* Migrated the existing Order Management and Shipping modules into an independent TaxoCommerce plugin.

== Historical origins (monolith; not standalone releases) ==
* [Complete legacy and release history](https://github.com/distribuidordeherramientases-wq/seo-taxonomy/tree/main/taxocommerce-logistica) (changelog.txt / CHANGELOG.md).

* Legacy origin: shipping, transport-rate settings and WooCommerce checkout integration were originally functions of the TaxoCommerce SEO monolith; former monolith versions are not Logistics releases.
* The original implementation stored transport configuration in seo_transporte_costes_settings; extracting Logistics preserved existing shipping rules.
* The independent plugin was created by a one-to-one migration together with Billing, with original shipping logic retained rather than replaced.
* Supplier purchasing operations were moved from Logistics into Suppliers so shipping rules and procurement have separate owners.
* Later standalone releases added grouped Auto Test checks, before/after comparison, user/technical manuals, and integration into the Core family menu without claiming independent checkout behavior.
