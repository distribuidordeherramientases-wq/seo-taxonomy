=== TaxoCommerce Auditor - Content Quality for WooCommerce ===
Contributors: davidperezmartorell
Tags: woocommerce, audit, seo, content, quality, taxonomy
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later

Independent, native-data content audits in five bounded WordPress scopes.

== Description ==

* Five read-only audits: products, product categories/architecture, posts, pages/landings, FAQs.
* No SEO Taxonomy globals, functions, SQL tables, or legacy includes.
* Product data via WooCommerce CRUD; posts, pages and categories via WordPress APIs.
* Vocabulary terms from tc_semantic_tag and category assignment term meta, only when Vocabulary is active.
* Native Core hierarchy metakeys for structural-page and product-category relationships.
* Internal five-dimension quality index: utility 25, trust 20, differentiation 20, architecture 20, technical 15.
* Exact title collision and canonical-owner overlap signals for human review, not proof of cannibalization.
* Background jobs in bounded batches via WP-Cron; manual next-batch, pause and resume.
* Persisted latest completed report per block, status/progress, prioritized findings, JSON export.
* Limited UI findings samples preserve full rule/severity counts.
* Core integration when Core is present; standalone admin menu otherwise.

== Not included in the initial native pilot ==

* Approval/apply workflow for splitting or merging categories and redirects (read-only signals only).
* Full legacy semantically-supported near-duplicate/landing merge/split diagnostics.
* Cross-service persisted demand/market/customer context (Analista, Ojeador, Dependiente, Comparador).
* Auditorium of Academia/Estudiante (belongs to Dependiente).
* Automated migration or deletion of seo_* tables or legacy FAQ data.
* FAQ owner integrity until a native FAQ plugin provides owner data through taxocommerce_auditor_faq_owner.
* WordPress has no standard native category excerpt field; category description is audited instead.
* All auditable objects' rows in JSON: reports retain counts and bounded samples to avoid multi-MB options.

== FAQ integration ==

WordPress has no built-in FAQ post type. Plugin discovers tc_faq, taxocommerce_faq or faq.
Filter taxocommerce_auditor_faq_post_types can override the candidate list.
Filter taxocommerce_auditor_faq_owner receives (null, WP_Post) and should return
array('type'=>'product'|'product_cat', 'id'=>123) when the owner is known.
Without an FAQ CPT the status is unavailable (never reported as 0 FAQs).

== Safety ==

Administrator permission and nonce required for all actions and exports.
No automatic content writes. Work is restricted to STAGING for this development.
Quality is an internal prioritization aid, not a Google/Bing score.
