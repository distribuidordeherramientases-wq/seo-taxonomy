=== TaxoCommerce Templates ===
Contributors: davidperezmartorell
Tags: templates, roles, pages, woocommerce
Requires at least: 6.5
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later

Independent role-to-template manager for WordPress pages.

== Description ==
- Uses existing seo_nodes and seo_templates tables without erasing existing assignments.
- Remains functional when TaxoCommerce Core and legacy SEO Taxonomy are disabled.
- Registers as a module when TaxoCommerce Core is active.
- Creates roles, assigns them to WordPress pages, and maps roles to PHP files.
- Front-end template overrides are OFF by default.
- Bundled template-default.php is a safe generic fallback example.

== Important ==
The legacy catalog of shop, checkout, product, international and landing templates relies on old services. Those files require compatibility adaptation and manual QA before activating the new rendering engine.
This plugin does not transfer the SEO relation graph away from Core, does not delete legacy tables and does not modify Production.
