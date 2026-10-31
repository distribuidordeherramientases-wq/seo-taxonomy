=== TaxoCommerce Auditor - Content Quality for WooCommerce ===
Contributors: davidperezmartorell
Tags: woocommerce, audit, seo, content, quality
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.2
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

* In-plugin Auto Test: 16 grouped checks of plugin availability, route registration, five scopes, read-only report and history access, quality rules and export contracts. No audit jobs, downloads, or content modifications occur during tests.
* Only two reports per administrator are held for 30 days to compare results between updates; absent old audit reports are not a failure.
* Integrated user and technical manuals explain all five content areas, filtered and full audits, priorities, JSONL reports, WP-Cron, security and limits.

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

= 0.3.2 =
* Add internal Auto Test tab with 16 functional checks, no audit execution, and previous-result comparison.
* Add integrated user and technical manual covering five scopes, safe operation, background process and JSONL report delivery.
* Keep existing read-only audit rules, scope navigation and export endpoints unchanged.
* Extend PHP 7.4/8.4 offline smoke tests and keep tests out of installable ZIP packages.


= 0.3.1 =
* Refresh WordPress.org readiness against the current standalone Auditor code.
* Keep large read-only audit exports streamed from bounded local JSONL storage.

= 0.3.0 =
* Added complete and filtered audits with full JSON exports.
* Added per-scope KPI panels and prioritised findings.
* Added optional locally stored decision context and native FAQ auditing.

= 0.2.0 =
* Restore the original Auditor interface and legacy quality checks using native WordPress/WooCommerce entities across five audit domains.

= 0.1.0 =
* Initial independent five-domain Auditor with bounded scans, native catalogue data and Vocabulary compatibility.

== Historical origins (monolith; not standalone releases) ==
* [Complete legacy and release history](https://github.com/distribuidordeherramientases-wq/seo-taxonomy/tree/main/taxocommerce-auditor) (changelog.txt / CHANGELOG.md).

* The monolithic TaxoCommerce SEO 2.3.x contained the first Auditor quality reports; these historical releases are not standalone Auditor versions.
* The historic review covered products, categories, posts, landing pages and FAQs, with separate quality findings and evidence.
* Standalone Auditor 0.1.0 established five groups of checks based on native WordPress and WooCommerce data.
* Standalone 0.2.0 restored the original Auditor checks and interface; 0.3.0 expanded complete audit results, JSON export, KPIs and decision context.
* The independent reports were kept separate from the external traffic reporting now owned by Insights.
