=== TaxoCommerce Import-Export Lite ===
Contributors: davidperez
Tags: import, export, csv, woocommerce, products
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.1.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Independent native WordPress and WooCommerce CSV import/export with automatic header recognition. No other TaxoCommerce plugins needed.

== Description ==

TaxoCommerce Import-Export Lite imports and exports CSV files for pages, posts, WordPress categories and tags, WooCommerce products, product categories and product tags using only standard WordPress/WooCommerce APIs. This plugin is separate from the previous TaxoCommerce Import-Export and has no shared state or configuration.

= Features =
* Upload one CSV in its dedicated content block; headers are recognized automatically on the server.
* Automatic create-or-update by SKU or slug, safe draft creation.
* Download CSV directly from each content block; optional field selection remains available.
* Include existing public, scalar WordPress/WooCommerce custom fields and product attributes from the current installation.
* Validate the entire CSV without writing, review errors by row, then explicitly confirm import.
* Product prices, stock, virtual/downloadable flags, two downloadable URLs, tax fields, and basic properties.
* Categories/tags optional; exact canonical relationship headers request existing terms. Foreign IDs are ignored.
* Browser-assisted 25-row import batches, read-only functional self-check and user manual.

= Limits =
The first Lite edition does not migrate child product variations, advanced attribute definitions, WooCommerce orders, customers, binary media files or third-party metadata. Product variations require a dedicated WooCommerce migration workflow. Remote download URLs are recorded as links, not copied. WordPress Media images must already exist for explicit image references. WooCommerce download security is configured in WooCommerce.

== Installation ==

1. Install and activate WooCommerce.
2. Upload the ZIP via Plugins > Add New > Upload Plugin.
3. Activate TaxoCommerce Import-Export Lite.
4. Open Import-Export Lite in the WordPress administration sidebar.
5. No TaxoCommerce Core or previous plugin is required.

== Frequently Asked Questions ==

= Does the Lite plugin require TaxoCommerce Core? =
No. Only WordPress and WooCommerce are required.

= Must categories exist in the target store? =
No for products without a category field. An exact categories or tags header requests native terms already present in the target store; omit those columns when they are not needed.

= What happens when a product is not found? =
The default mode creates a draft product; it does not fail with Product not found. Existing products are matched by SKU first, then slug.

= Can I import downloadable products? =
Yes. Include the headers virtual, downloadable, download_1_name and download_1_url (optionally a second download) to WooCommerce product fields. The URL must be a valid HTTP(S) address.

== Changelog ==

= 0.1.9 =
* Complete 77 untranslated active gettext keys per locale (385 entries), including import validation messages and the user manual. Refresh the POT/PO references for the current four-tab Lite edition, removing 12 obsolete technical-manual keys; all 192 active keys are translated in each of the five locales. Rebuild five MO catalogs and verify format placeholders and canonical CSV examples. Make the header regression read the current version and release date from plugin metadata instead of pinning old release numbers. Preserve CSV processing, data, permissions, existing active translations and the Lite/Pro feature separation.

= 0.1.8 =
* Simplify Lite administration to import, export, functional check and user manual; remove the technical manual screen. Retain all five bundled language catalogs and CSV operations.

= 0.1.7 =
* Add standalone gettext catalogs for five locales: 116 of 204 keys per locale (580 entries), including administrative controls, CSV field labels, upload errors and progress counters. Localize JavaScript progress and check results. Preserve canonical CSV keys, accepted values, data and import behavior. Remaining validation and manual strings will be translated separately.

= 0.1.6 =
* Receive CSV uploads into private temporary files independently of Media; validate upload origin, size and storage, with safe fallback and actionable errors.

= 0.1.5 =
* Separate import and export blocks for every supported content type; automatic create-or-update and server-side header recognition.
* Remove type, action and manual field-mapping selectors; retain read-only preflight and explicit confirmation.

= 0.1.4 =
* Restrict slug lookup to the requested post type; media attachments no longer block new product imports.

= 0.1.3 =
* Consistent three-zone admin header with plugin metadata, a local collapsible CHANGELOG.md, version and date.
* Responsive presentation and WordPress-language labels. No change to CSV logic or independent operation.

= 0.1.2 =
* Fix WooCommerce SKU/slug lookups pointing to missing or trashed records: create safely in upsert mode; report real conflicts without modifying other products.


= 0.1.1 =
* Discover native public scalar custom metadata and global/local WooCommerce attributes.
* Read-only full-CSV precheck and explicit confirmation before importing.
* Independent from every other TaxoCommerce plugin; no custom TaxoCommerce tables.

= 0.1.0 =
* New independent Lite plugin. See CHANGELOG.md.
