=== TaxoCommerce Assistant ===
Contributors: davidperezmartorell
Tags: woocommerce, product-search, catalog, search, assistant
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.3.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An auditable catalog assistant for WooCommerce, with independent learning, semantic interpretation and customer-facing product search.

== Description ==

TaxoCommerce Assistant builds searchable product knowledge from the site's WooCommerce catalog and available editorial content.

* L1-L10: bounded, auditable learning steps with source snapshots and quality checks.
* M11: semantic interpretation and relevance evaluation; uncertain matches can ask for clarification.
* M12: incremental updates when products or related content change.
* Search shortcode: [taxocommerce_dependiente_moderno].
* Independent administration of learning progress, quality reports, knowledge transfers and local backups.
* Uses its own tables for snapshots, learning, semantic rules and feedback.
* Keeps the legacy public shortcode untouched when the former plugin is active in production.

Learning does not edit the source products. The plugin runs local background tasks and needs WordPress cron to process queued learning.

== Installation ==

1. Install and activate WooCommerce.
2. Install and activate TaxoCommerce Assistant from its standalone ZIP.
3. Open its administration page to check learning state and launch or review training.
4. Add [taxocommerce_dependiente_moderno] to a page to test the new storefront search.
5. Confirm relevance and resource usage before replacing an existing storefront search.

TaxoCommerce Core is optional for central menu integration.

== Privacy ==

Product and editorial information is processed in the WordPress database. The assistant can store search queries, clicks and clarifications as feedback events to improve aggregate relevance. According to its current data contract, these records exclude visitor IP addresses, names and email addresses. Site administrators should disclose this processing in their privacy notice, evaluate their lawful basis and data retention, and test the frontend before enabling it for visitors.

The plugin does not need an external AI inference service for its default local catalog learning workflow.

== Frequently Asked Questions ==

= Does it replace my existing public search automatically? =

No. Use [taxocommerce_dependiente_moderno] explicitly. Existing shortcodes remain under the original plugin during the migration.

= Does learning modify WooCommerce product descriptions or prices? =

No. Learning builds and evaluates separate knowledge records.

= Does it require TaxoCommerce Core? =

No. Core provides optional grouped administration.

== Changelog ==

= 0.3.7 =
* Standalone auditable L1-L10 learning and M11/M12 semantic search.
* Knowledge transfer and local backup interfaces.
* Explicit shortcode for parallel testing.
