=== TaxoCommerce Assistant ===
Contributors: davidperezmartorell
Tags: woocommerce, product-search, catalog, search, assistant
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.3.18
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An auditable catalog assistant for WooCommerce, with independent learning, semantic interpretation and customer-facing product search.

== Description ==

Auto Test: 51 independent read-only checks grouped by runtime, schema, L1-L10 sources/quality, M11, search/feedback, M12 and backup. Reports are per-user transients; no new tables or background jobs. Expensive or destructive UAT remains explicitly unexecuted.

TaxoCommerce Assistant builds searchable product knowledge from the site's WooCommerce catalog and available editorial content.

* L1-L10: bounded, auditable learning steps with source snapshots and quality checks.
* M11: semantic interpretation and relevance evaluation; uncertain matches can ask for clarification.
* M12: incremental updates when products or related content change.
* Search shortcode: [taxocommerce_dependiente_moderno].
* Independent administration of learning progress, quality reports, knowledge transfers and local backups.
* Built-in User Guide with user and technical manuals, with no learning or settings writes.
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

= 0.3.18 =
* Add a responsive User Guide tab with separate User Manual and Technical Manual inside Dependiente.
* Explain learning, quality recovery, Interpreter M11, M12, search and feedback, backups, plugin architecture and data contracts.
* Preserve all training controls, imports, exports, cron and frontend behavior.


= 0.3.18 =
* Auto Test reports functional readiness separately from educational outcomes and historical process errors. M11, learning and M12 remain green when endpoints, workers and state contracts are available, with diagnostic detail.
* A bounded search that returns no candidates is marked as inconclusive instead of reporting a broken search engine solely from its result set.
* No training, feedback, content or backup mutations are performed by Auto Test.


= 0.3.15 =
* Raised the adaptive maximum-speed learning ceiling from 79 to 600 items per chunk, starting from 47 and growing only when duration and memory allow it.
* Avoid reducing a fast worker simply because its own PHP process has high CPU utilisation: it is not the hosting account CPU.
* The manager can execute up to four bounded learning ticks per callback on maximum speed, subject to its runtime budget, stop flag and locks.
* The previous learning snapshots, results, cursor and quality gates remain unchanged.


= 0.3.14 =
* M11 validates 180 distributed L9 queries with the current family/product evaluator instead of 100 obsolete stored queries.
* Resume M11 regression in CPU-limited batches without restarting any of the ten lessons.
* Add M11 audit JSON, progress counters, failure evidence, revision and version tracking.


= 0.3.13 =
* Record evaluator revision, actual PHP plugin version and L9 query provenance in quality JSON reports.
* Flag old L9/L10 audit results as stale and allow repeat of only the current quality examination.
* Preserve existing L1-L9 snapshots and the 90% quality gate for L10.


= 0.3.12 =
* Rebuild L9 evaluation needs deterministically from sealed learned facts, instead of reusing historical legacy questions.
* Treat non-unique colour/material hints as family requests; retain exact-product requests for model/measure/capacity.
* Fix generic repair-token ranking bias; include per-result evidence and per-run query provenance in L9 audit.
* Re-evaluation does not clear L1-L8 memory or change source catalog content.


= 0.3.10 =
* Make L6 FAQ reading tolerant of optional columns in legacy and modern seo_faq tables.
* Diagnose empty, inactive, incompatible, or unreadable FAQ sources in the learning log without modifying FAQ content.
* Verify FAQ source compatibility with an executable regression for both database layouts.

= 0.3.9 =
* Make FAQ schema compatibility queries explicit and fully prepared.
* Remove dynamic SQL condition fragments from semantic and identity lookups.
* Harden streamed knowledge export output for WordPress Plugin Check.

= 0.3.8 =
* L6 reads both current and historical `seo_faq` schemas during migration.
* Falls back to `0 AS product_cat_id` when the legacy FAQ table has not yet been upgraded.
* Reports SQL read failures explicitly instead of misreporting them as an empty training source.

= 0.3.7 =
* Standalone auditable L1-L10 learning and M11/M12 semantic search.
* Knowledge transfer and local backup interfaces.
* Explicit shortcode for parallel testing.
