=== TaxoCommerce Import-Export Lite ===
Contributors: davidperez
Tags: import, export, csv, woocommerce, products
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Independent native WordPress and WooCommerce CSV import/export with column mapping. No other TaxoCommerce plugins needed.

== Description ==

TaxoCommerce Import-Export Lite imports and exports CSV files for pages, posts, WordPress categories and tags, WooCommerce products, product categories and product tags using only standard WordPress/WooCommerce APIs. This plugin is separate from the previous TaxoCommerce Import-Export and has no shared state or configuration.

= Features =
* Upload one CSV and map the columns to native fields.
* Default create-or-update by SKU or slug, safe draft creation.
* Export a selected subset of native fields in standard CSV.
* Include existing public, scalar WordPress/WooCommerce custom fields and product attributes from the current installation.
* Validate the entire CSV without writing, review errors by row, then explicitly confirm import.
* Product prices, stock, virtual/downloadable flags, two downloadable URLs, tax fields, and basic properties.
* Categories/tags optional and never automatically assigned from a foreign CSV.
* Browser-assisted 25-row import batches, read-only self-check, manuals.

= Limits =
The first Lite edition does not migrate child product variations, advanced attribute definitions, WooCommerce orders, customers, binary media files or third-party metadata. Product variations require a dedicated WooCommerce migration workflow. Remote download URLs are recorded as links, not copied. WordPress Media images must already exist for explicit image mapping. WooCommerce download security is configured in WooCommerce.

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
No for products without a category field. Categories are only assigned when the administrator explicitly maps that CSV column to native taxonomy terms already in the target store.

= What happens when a product is not found? =
The default mode creates a draft product; it does not fail with Product not found. Existing products are matched by SKU first, then slug.

= Can I import downloadable products? =
Yes. Map virtual, downloadable, download_1_name and download_1_url (optionally a second download) to WooCommerce product fields. The URL must be a valid HTTP(S) address.

== Changelog ==

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
