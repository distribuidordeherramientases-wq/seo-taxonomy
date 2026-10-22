=== TaxoCommerce Templates ===
Contributors: davidperezmartorell
Tags: templates, roles, pages, woocommerce
Requires at least: 6.5
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later

Independent route and template manager for WordPress and WooCommerce.

== Description ==
- Preserves seo_nodes and seo_templates without erasing existing assignments or uploaded templates.
- Seeds missing legacy route definitions without overwriting existing rows.
- Supports single-page, multi-page and automatic routes.
- Resolves blog, front page, categories, posts, products, shop, search, WooCommerce cart and checkout.
- Supports per-device variants (name-mobile.php and name-desktop.php), with dispatcher safety checks.
- Allows administrators who can update plugins to upload PHP templates to a persistent wp-content/taxocommerce-templates-library/ directory.
- Requires explicit replacement confirmation and creates backup copies when overwriting uploaded templates.
- Remains independent of the old plugin; does not load its PHP files.
- Template routing stays OFF by default. Test all new template files in STAGING before enabling.
- The old international VEVOR pilot may depend on monolithic services and should not be re-enabled via the new router without explicit migration and QA.

== Important ==
Do not enable the new router on the live site until the required PHP template files and dependencies have been migrated and validated. The old router and the new router must not compete on a production request. PHP template uploads are executable server-side code and must only be provided by trusted administrators.
