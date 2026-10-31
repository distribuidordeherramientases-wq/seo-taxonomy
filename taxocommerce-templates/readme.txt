=== TaxoCommerce Templates ===
Contributors: davidperezmartorell
Tags: templates, roles, pages, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Register WordPress and WooCommerce request templates, maintain SEO role assignments and audit existing PHP template associations.

== Description ==

TaxoCommerce Templates is a standalone request-template routing and management plugin for WordPress and WooCommerce. It supports three areas:

* Request types: WordPress front page, posts, pages, archives, taxonomies, search, 404, custom post types and registered public taxonomies, as well as classic WooCommerce shop/cart/checkout/account/order pages and selected native WooCommerce PHP template hooks.
* Editorial roles: retain cluster, primary and secondary hub, landing and mail-template assignments using compatible `seo_nodes`, `seo_templates` and `seo_mail_templates` records. Mobile and desktop variants belong to one primary assignment.
* Template library: inspect built-in source code read-only; create, duplicate, download and edit custom PHP templates; upload PHP files subject to syntax checking and a 1 MiB limit; back up custom files before replacement.

Custom templates are stored in a separate WordPress content library rather than the plugin folder. Built-in source files are never overwritten from the editor.

**Important:** PHP source upload and execution are advanced trusted-administrator features that may not meet the security and policy requirements for new plugins distributed through the public WordPress.org directory. This version is a private migration and staging-validation build, **not an approved public-directory release**. Do not submit it to WordPress.org until the executable PHP editing feature has been removed, split off or explicitly accepted by the directory reviewers.

== Installation ==

1. Install and activate on a test WordPress installation; TaxoCommerce Core is not required.
2. Inspect all request types and imported associations in the audit area.
3. Leave the new template routing engine disabled until existing templates and dependencies are validated in STAGING.
4. Verify that the old routing engine is not active for the same request during migration.
5. Enable only individual tested request assignments. A syntactically valid PHP file may still cause runtime failures.

== Security ==

The PHP template editor requires administrator permissions and WordPress permission to edit PHP/plugin code. Validate every uploaded file and keep hosting backups. Library files are stored outside the plugin directory under `wp-content/taxocommerce-templates-library`; review web-server execution restrictions and filesystem permissions carefully. Never permit untrusted users to upload or edit executable PHP.

AJAX/JSON endpoints and WooCommerce action callbacks are not themselves request templates; this module must not insert HTML into JSON responses. Block-based `wp_template` content should be edited with the native WordPress Site Editor.

== Migration and Compatibility ==

The plugin preserves existing role/request records and copies compatible historic template resources without directly loading the old monolith's PHP files. It does not reassign page IDs or automatically alter existing `seo_nodes` relations. Pilot-specific international VEVOR template routes remain with their existing owner until explicitly migrated.

The association-audit panel compares old and new file locations, roles without templates, missing desktop/mobile variants and mail templates, and exports a CSV inventory. A file inventory or PHP lint pass does not establish visual or functional equivalence.

== Developer Notes ==

Use `tct_request_definitions` to extend named request definitions and `tct_request_candidates` to customise frontend request resolution precedence. Request types not using `template_include` or the native WooCommerce template hooks need their own dedicated integration.

== Changelog ==

= 0.4.0 =
* Standalone request and WooCommerce template routing.
* Existing editorial role and assignment compatibility.
* Independent custom PHP template library, variants and audit reports.

== Historical origins (monolith; not standalone releases) ==
* Legacy origin: the WordPress/WooCommerce theme templates, content-role routing, cluster/hub page presentation and product/category layouts were developed within TaxoCommerce SEO; old monolith releases are not Templates versions.
* The monolith 2.3.x–2.4.0 line included frontend content integration, editorial roles, product/category information blocks, menu assignments and canonical content relationships.
* Standalone Templates evolved into an independent template library and editor with explicit assignment and compatibility checks for existing WordPress pages.
* International storefront work introduced country-specific presentation for Spain, United States, Germany, Netherlands and France, with separate menus and country metadata.
* Follow-up work aligned the United States template with the shared international layout and investigated header menu fallback where raw categories appeared instead of configured menus.
* WordPress.org preparation addressed translation strings and template/FAQ rendering, while private PHP editing remained a controlled administrator-only capability.
* Version 0.4.0 is the current standalone series; earlier monolith milestones must not be represented as fabricated 0.x Templates releases.
