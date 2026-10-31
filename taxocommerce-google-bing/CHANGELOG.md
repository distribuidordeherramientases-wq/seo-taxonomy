# CHANGELOG — TaxoCommerce Insights

> Historial maestro de versiones del plugin independiente. El origen monolítico se documenta en [`changelog.txt`](changelog.txt).

## Versiones independientes

### En desarrollo — STAGING (posterior a 1.1.14)

* Unify the former **Qué hacer** and **Plan de acción** reports into one executive Analysis view while preserving the original specialized reports.
* Build one prioritised strategic shortlist from GA4 funnel investigations and up to five evidence-backed SEO/GEO signals drawn from Analista, Auditor, Merchant and Product JSON-LD; the complete validated roadmap remains available below.
* Never equate referred AI traffic with brand mentions, infer individual checkout abandonment from aggregate events, or claim direct GPTCore integration.
* Reuse a single decision-plan computation for the GEO summary and roadmap, keep Analytics and source snapshots read-only, and add standalone regression coverage.
* Preserve the historical SEO plugin and `changelog.txt` unchanged. No WordPress.org release or production deployment in this change.

### 1.1.14

* Use the TaxoCommerce Insights plugin prefix for seven new GEO functions flagged by WordPress Plugin Check.
* Update all internal report, JSON, AJAX bridge and smoke-test references without changing saved data, provider calls or decision logic.

### 1.1.12

* Align the published WordPress.org stable tag with the plugin's 1.1.12 code version, retaining existing provider connections and Analysis reports.

### 1.1.11

* Add dedicated Auto Test tab with grouped non-destructive checks for Insights, providers, reports, Trends and Analysis.
* Preserve a per-site, per-user BEFORE baseline across updates and compare it with AFTER results; export safe JSON reports.
* Clearly identify skipped external API and OAuth integration tests instead of reporting false success.

### 1.1.10

* WordPress.org Plugin Check remediation: fix migrated Analista text domains and translator notes, and use a safelisted OAuth redirect.
* Document nonce-protected admin handlers; improve input validation and preserve the existing connections, Analysis reports, Trends and manuals.
* Build a clean distribution ZIP without development tests or FTP synchronization metadata and add official Plugin Check to continuous integration.

### 1.1.9

* Add User Guide with separate User Manual and Technical Manual, responsive layout and indexed H2/H3 sections.
* Describe active Connections, Analysis, Search Console, Analytics, Bing and Trends workflows; report persistence and OAuth compatibility.
* Keep provider calls, credential handling, reporting and all existing functions unchanged.

### 1.1.8

* Add an automatic Google Trends report for Spanish clusters and primary hubs, building on the independent Trends workload manager.

### 1.1.7

* Scope Google Trends to Spain (geo=ES) by cluster/primary hub and run work with a bounded load manager.

### 1.1.6

* Show explicit provider connection states (connected, configured but unverified, pending, error and disabled), per-service checks and latest test timestamps.

### 1.1.5

* Restored the previous Analista 3.9.0 engine as the Insights > Analysis tab instead of rebuilding it.
* Preserved its 28/60/90-day views, lazy partial analyses, action plan, comparison, structure, content, trends and JSON export.
* Adapted legacy admin URLs and assets to the standalone Insights plugin.

### 1.1.4

* Removed Analyst, Executive Reports, Market, their supporting code, assets, and tests.
* Kept Connections, Google Search, Google Analytics, Bing, and Google Trends.
* Removed Market-only YouTube and SerpApi provider configuration.

### 1.1.3

* Reuses the authorized Google Search Console OAuth property and credentials already configured in the historic plugin, without copying or modifying its secrets.
* Tries explicitly paired modern OAuth, legacy OAuth, and service-account properties during administrator-initiated report downloads; a 403 from one identity no longer masks another valid connection.
* Preserves the previous report schema and saves the property/auth source used for transparency.
* Fixes the HTTP status inspection in WP_Error and adds deterministic regression tests for fallback behavior.

### 1.1.2

* Added a concise commercial decision panel before the detailed Analyst diagnostics, with KPIs, event funnel bars, and specific customer actions.
* Existing Prepared Intelligence and Market reports remain read-only sources. Missing data is unavailable, not zero; supplier commissions cannot be inferred from WooCommerce purchases.
* Added smoke checks for commercial summary contracts, navigation, and absent data.

### 1.1.1

* Added persistent Prepared Intelligence built from saved Search, GA4, Bing, Trends and Market evidence.
* Analyst now consumes prepared report data instead of re-running deterministic Search/GA4 preprocessing on page load.
* Market aggregation is cached and refreshed when new Market evidence is saved.
* Detailed provider reports remain the auditable source while Analyst receives compact prepared summaries and candidate rows.

### 1.1.0

* Recovered the clear diagnostic dashboard from the former Analista inside the modern Analyst tab.
* Added visibility index, ranking distribution, keyword movement, Search evolution, catalog URL types, and architecture counters from saved snapshots.
* Search snapshots now keep previous-period queries/pages and daily position so Top 10/50/100 movement is auditable.
* Fixed GA4 batchRunReports by splitting report requests into batches of at most five.
* Added GA4 ecommerce funnel event counts for product views, add-to-cart, checkout and purchase diagnostics.
* Renamed report retrieval actions to “Actualizar datos” for clearer manual refresh semantics.

### 1.0.0

* Consolidated compatible external connections into Insights.
* Added unified Connections and global Analyst reporting.
* Retained historical settings and OAuth callback contracts.

## Fuentes

- [`changelog.txt`](changelog.txt), etapa monolítica.
- [`docs/PLUGIN-HISTORY-SOURCES.md`](../docs/PLUGIN-HISTORY-SOURCES.md), PR y referencias históricas.

## Actualizaciones

Para nuevas funcionalidades se edita este `CHANGELOG.md`. Antes de publicar, se debe reflejar el resumen de la versión en `readme.txt`, que es el único Changelog mostrado automáticamente por WordPress.org.
