=== TaxoCommerce Auditor - Content Quality for WooCommerce ===
Contributors: davidperezmartorell
Tags: woocommerce, audit, seo, content, quality
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later

Independent WordPress/WooCommerce content Auditor inspired by the legacy TaxoCommerce SEO 0.14.0 service.

== Five independent content audits ==

* Productos - title, excerpt, description, category, SKU, duplicate title/SKU candidates, content identity, product tag drift, malformed attributes, conflicting specifications, Vocabulary terms and an internal quality index.
* Categorias y arquitectura - native product_cat description and count, slug/title alignment, Vocabulary assignments, Core parent metadata, split/merge review and TIPO cohort candidates when native taxonomy evidence supports them.
* Posts - titles, slugs, content, Vocabulary coherence, repeated templates, confirmed exact-title/slug overlaps with canonical clusters/hubs or landings and semantically supported near-overlap.
* Paginas y landings - excludes functional/corporate pages, checks native Core page role/parent, differentiation from posts and structure, near-overlap with native Vocabulary, possible duplicate landings and merge review signals.
* FAQs - reads the canonical TaxoCommerce FAQs store when available; validates question/answer, page/hub/category/product owner, required product category, semantic drift and duplicate questions within the same owner. A third-party FAQ CPT remains supported as fallback.

== Auditing workflow ==

* Five cards as in the former Auditor, now with two explicit modes: Auditoria completa and Auditoria filtrada.
* Long scans run by WP-Cron in bounded batches; manual next batch, pause and resume.
* Complete scans traverse the whole published scope. Full findings are spooled under uploads/taxocommerce-auditor/ so the WordPress option keeps only a bounded UI sample.
* JSON completo streams every finding and priority task instead of truncating the export to the UI sample.
* Each content type has a dedicated KPI tab with general health plus type-specific quality/integrity/error metrics.
* Scope reports contain summary, KPI dashboard, severity, P1-P5 prioritization, internal quality, findings, decision context and bounded run history.
* Categories include review metrics and explain supported splitting/merging candidates; these recommendations do not change WooCommerce objects.
* Five internal quality dimensions: relevance/utility 25, trust 20, differentiation 20, architecture 20, technical preparation 15.
* Finding and priority totals cover all scanned objects; individual findings and worst-scoring objects are bounded samples, explicitly labeled.
* No invented Google/Bing score; no automatic content creation, deletion, category movement or redirects.

== Canonical data sources ==

* WordPress posts, pages, terms, termmeta, postmeta and native relationships.
* WooCommerce CRUD and registered product taxonomies.
* Vocabulary tc_semantic_tag / tc_tipo and native Vocabulary assignment metadata when available.
* Core native hierarchy metadata.
* TaxoCommerce FAQs canonical `{prefix}seo_faq` store is read directly when that module is installed; no monolith PHP code is loaded.
* Persisted local context may be read from TaxoCommerce Insights, Assistant/Dependiente, Suppliers and Comparador profiles. Auditor never starts their workers or remote API calls.
* An unavailable source is not reported as an observed count of zero.

== Optional integration contracts ==

* taxocommerce_auditor_faq_post_types - native FAQ post type candidates (default tc_faq, taxocommerce_faq, faq).
* taxocommerce_auditor_faq_owner - fallback contract for third-party FAQ CPTs; returns array('type'=>'page'|'product'|'product_cat','id'=>123).
* taxocommerce_auditor_category_excerpt - returns string when category summary has been migrated into native termmeta; returns null by default (not interpreted as empty).
* taxocommerce_auditor_product_identity_scope - returns provider/country scope for comparisons of SKUs shared across countries; missing scope is flagged as a candidate only.
* taxocommerce_auditor_entity_context - optional enrichment of the per-finding persisted context.
* taxocommerce_auditor_entity_category_ids - optional category mapping for entities that do not expose product_cat natively.

== Deliberately outside Auditor ==

* Apply/rollback for category split/merge and redirects. Auditor is a read-only specialist and only proposes review work.
* Academia/Estudiante checks remain a separate service.
* Native category excerpt remains optional because core WordPress does not define that term field.
* External providers are never queried during an audit; only already-persisted evidence is consumed.

== Safety ==

Actions and exports require WordPress administrator capability and valid nonces.
Long scans are read-only and do not touch the monolith or production.
Only deploy and test in STAGING; production is released separately by the owner.

== Changelog ==

= 0.3.0 =
* Added full-scope audits plus complete JSON export backed by an uploads JSONL spool.
* Added dedicated KPI dashboards for Products, Categories, Posts, Pages/Landings and FAQs.
* Connected persisted local decision context from Insights, Assistant/Dependiente, Suppliers and Comparador without remote calls.
* Migrated FAQ auditing to the canonical TaxoCommerce FAQs table while preserving third-party CPT fallback.
* Kept filtered 10/25/50/100/250 runs as a separate quick diagnostic mode.

= 0.2.0 =
* Ported the five-block content Auditor workflows and editorial owner/landing diagnostics to native WordPress/WooCommerce storage.
* Recreated category size and evidence-based review proposals without automatic catalog changes.
* Restored product attribute/identity checks, FAQ owner checks, P1-P5 classes, quality reports, history and WordPress admin report navigation.
* Made scan starts asynchronous and added pause/restart safeguards.

= 0.1.0 =
* Initial five-block native Auditor pilot.
