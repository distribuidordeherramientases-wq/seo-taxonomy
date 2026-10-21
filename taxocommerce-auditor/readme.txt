=== TaxoCommerce Auditor - Content Quality for WooCommerce ===
Contributors: davidperezmartorell
Tags: woocommerce, audit, seo, content, quality, taxonomy
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later

Independent WordPress/WooCommerce content Auditor inspired by the legacy TaxoCommerce SEO 0.14.0 service.

== Five independent content audits ==

* Productos - title, excerpt, description, category, SKU, duplicate title/SKU candidates, content identity, product tag drift, malformed attributes, conflicting specifications, Vocabulary terms and an internal quality index.
* Categorias y arquitectura - native product_cat description and count, slug/title alignment, Vocabulary assignments, Core parent metadata, split/merge review and TIPO cohort candidates when native taxonomy evidence supports them.
* Posts - titles, slugs, content, Vocabulary coherence, repeated templates, confirmed exact-title/slug overlaps with canonical clusters/hubs or landings and semantically supported near-overlap.
* Paginas y landings - excludes functional/corporate pages, checks native Core page role/parent, differentiation from posts and structure, near-overlap with native Vocabulary, possible duplicate landings and merge review signals.
* FAQs - only if a native FAQ post type exists; validates question/answer, verified owner type/identity if the FAQ plugin supplies it, semantic drift and duplicate questions within the same verified owner.

== Auditing workflow ==

* Five cards as in the former Auditor: Auditar/Repetir, Ver ultimo, JSON.
* Long scans run by WP-Cron in bounded batches; manual next batch, pause and resume.
* Scope reports contain summary, severity, P1-P5 prioritization, internal quality, findings, decision context status and bounded run history.
* Categories include review metrics and explain supported splitting/merging candidates; these recommendations do not change WooCommerce objects.
* Five internal quality dimensions: relevance/utility 25, trust 20, differentiation 20, architecture 20, technical preparation 15.
* Finding and priority totals cover all scanned objects; individual findings and worst-scoring objects are bounded samples, explicitly labeled.
* No invented Google/Bing score; no automatic content creation, deletion, category movement or redirects.

== Canonical data sources ==

* WordPress posts, pages, terms, termmeta, postmeta and native relationships.
* WooCommerce CRUD and registered product taxonomies.
* Vocabulary tc_semantic_tag / tc_tipo and native Vocabulary assignment metadata when available.
* Core native hierarchy metadata. No old seo_nodes, seo_relations, seo_vocabulary, seo_object_vocabulary or seo_faq SQL table reads.
* An unavailable source is not reported as an observed count of zero.

== Optional integration contracts ==

* taxocommerce_auditor_faq_post_types - native FAQ post type candidates (default tc_faq, taxocommerce_faq, faq).
* taxocommerce_auditor_faq_owner - returns array('type'=>'product'|'product_cat','id'=>123) for an existing native FAQ.
* taxocommerce_auditor_category_excerpt - returns string when category summary has been migrated into native termmeta; returns null by default (not interpreted as empty).
* taxocommerce_auditor_product_identity_scope - returns provider/country scope for comparisons of SKUs shared across countries; missing scope is flagged as a candidate only.
* taxocommerce_auditor_decision_context - optional already-persisted local source context (no remote API calls).

== Still pending from the 0.14.0 legacy Auditor ==

* Fully transactional apply/rollback for category split/merge and 301 redirects with explicit approval. This release shows review proposals but never applies them.
* Exhaustive near-duplicate/identity-crossover investigation and landing split signals that require native category-to-landing relationships.
* Real native persisted snapshots from Analista, Ojeador, Dependiente and Comparador; the Context tab explicitly indicates unavailable sources.
* Academia/Estudiante checks are a separate service and intentionally not bundled.
* Native FAQ content/owner checks depend on an installed FAQ CPT module.
* Native category excerpt is optional because core WordPress does not define that term field.

== Safety ==

Actions and exports require WordPress administrator capability and valid nonces.
Long scans are read-only and do not touch the monolith or production.
Only deploy and test in STAGING; production is released separately by the owner.

== Changelog ==

= 0.2.0 =
* Ported the five-block content Auditor workflows and editorial owner/landing diagnostics to native WordPress/WooCommerce storage.
* Recreated category size and evidence-based review proposals without automatic catalog changes.
* Restored product attribute/identity checks, FAQ owner checks, P1-P5 classes, quality reports, history and WordPress admin report navigation.
* Made scan starts asynchronous and added pause/restart safeguards.

= 0.1.0 =
* Initial five-block native Auditor pilot.
