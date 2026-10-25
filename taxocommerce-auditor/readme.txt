=== TaxoCommerce Auditor - Content Quality for WooCommerce ===
Contributors: davidperezmartorell
Tags: woocommerce, audit, seo, content, quality
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Audit WordPress and WooCommerce content quality, structure, duplicate risks and FAQs with per-type metrics and full JSON exports.

== Description ==

TaxoCommerce Auditor provides independent read-only content reviews across five scopes:

* Products: descriptions, titles, SKUs, categories, attributes, vocabulary and possible duplicate content.
* Product categories and SEO architecture: taxonomy completeness, native relationships and evidence-based restructuring candidates.
* Posts: titles, slugs, content patterns and potential overlap with landing pages.
* Pages and landings: content structure, WordPress-native SEO roles, hierarchy and potentially overlapping pages.
* FAQs: ownership, questions, answers, target product categories and semantic consistency. The TaxoCommerce FAQs module is optional.

Each scope has volume and quality indicators, severity counts, a prioritised review queue and complete exportable JSON findings. Audits use bounded scheduled batches and may be paused or resumed.

Complete audit results are streamed as JSON Lines files in WordPress uploads. Only a bounded sample is kept in WordPress options. Large exports stream the full results instead of loading the catalogue into memory.

The plugin reads canonical WordPress posts, taxonomies and metadata, supported WooCommerce product APIs, optional Core hierarchy data and locally saved data from other TaxoCommerce modules. It does not initiate external requests or automatically change, delete or redirect content.

== Installation ==

1. Install the standalone plugin on a supported WordPress/WooCommerce site.
2. Activate TaxoCommerce Auditor and open its administrator panel.
3. Choose a content scope and run a filtered audit or a full bounded audit.
4. Inspect findings, download JSON where needed, and apply corrections manually.
5. Verify cron execution and PHP upload-directory write permissions for full reports.

== Frequently Asked Questions ==

= Does an audit change content automatically? =

No. Every audit is read-only and offers recommendations without applying changes.

= Is the original TaxoCommerce SEO plugin required? =

No. Core and other TaxoCommerce modules provide optional integrations.

= Does it publish information to Google or another provider? =

No. It reads already-saved local evidence; no third-party requests are started.

== Changelog ==

= 0.3.0 =
* Added complete and filtered audits with full JSON exports.
* Added per-scope KPI panels and prioritised findings.
* Added optional locally stored decision context and native FAQ auditing.
