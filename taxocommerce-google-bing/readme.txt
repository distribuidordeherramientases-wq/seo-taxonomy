=== TaxoCommerce Insights ===
Contributors: davidperezmartorell
Tags: analytics, search-console, bing, ecommerce, reporting
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.13
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

External connections, manual provider data downloads, and the restored legacy Analysis dashboard.

== Description ==

TaxoCommerce Insights keeps external service connections and manual data downloads in one place.

* Connections: Google Search Console, Google Analytics, Google Merchant Center, Bing Webmaster, Amazon and Pinterest.
* Google Search downloads.
* Google Analytics downloads.
* Bing downloads.
* Google Trends automatically compares all Spanish clusters (up to 5 per chart), then separately compares each cluster's primary hubs on page load. The underlying SEO hierarchy is dynamic, category and secondary-hub consultations remain manual; five-speed browser load controller, pause/resume/stop, and links to Google Explore. Charts are visual and do not provide machine-readable growth scores or verification of search term meaning. Historical RSS snapshots remain supported.
* Analysis restores the previous working Analista engine inside Insights, including summary, content, structure, trends, comparison and work-plan views.
* User Guide contains read-only User Manual and Technical Manual explaining active features, integrations, data and maintenance.
* The main technical folder remains taxocommerce-google-bing for compatibility; the displayed product name is TaxoCommerce Insights.

Opening reports does not automatically fetch provider data. Administrators explicitly initiate connection, download, refresh or research operations. Each external source requires the corresponding authorization and provider terms.

== Installation ==

1. Install the standalone TaxoCommerce Insights ZIP.
2. Open Insights > Connections in WordPress administration.
3. Connect only the supported providers you plan to use, with credentials you control.
4. Use the test action, then request a report download/refresh.
5. Review your access permissions, privacy policy, retention and any API quota/cost.

TaxoCommerce Core is optional. WooCommerce metrics are available only if WooCommerce is installed and the relevant data exists.

== External Services and Privacy ==

Depending on which optional providers the administrator connects, this plugin communicates with Google's Search Console, Analytics, Merchant Center or YouTube APIs; Microsoft's Bing Webmaster service; Amazon services; Pinterest; and SerpApi. These providers process data under their own terms and privacy policies. No external account is connected by default.

Administrator-provided OAuth/service credentials and downloaded reports are stored locally as WordPress settings and/or module records. Secure the WordPress admin and database, grant minimum provider permissions, and inform users about analytics collection where applicable. Reporting cannot establish individual affiliate sales attribution unless the source provides it.

== Frequently Asked Questions ==

= Do reports work without connecting external services? =

Only previously saved local information may be available. Live Google/Bing metrics require an authorized connection and explicit retrieval.

= Is this the old Google/Bing plugin? =

It is the continued plugin under the historical slug taxocommerce-google-bing, renamed to Insights and focused on unified connections and provider data downloads.

== Changelog ==

= 1.1.12 =
* Align the published WordPress.org stable tag with the plugin's 1.1.12 code version, retaining existing provider connections and Analysis reports.

= 1.1.11 =
* Add dedicated Auto Test tab with grouped non-destructive checks for Insights, providers, reports, Trends and Analysis.
* Preserve a per-site, per-user BEFORE baseline across updates and compare it with AFTER results; export safe JSON reports.
* Clearly identify skipped external API and OAuth integration tests instead of reporting false success.


= 1.1.10 =
* WordPress.org Plugin Check remediation: fix migrated Analista text domains and translator notes, and use a safelisted OAuth redirect.
* Document nonce-protected admin handlers; improve input validation and preserve the existing connections, Analysis reports, Trends and manuals.
* Build a clean distribution ZIP without development tests or FTP synchronization metadata and add official Plugin Check to continuous integration.


= 1.1.9 =
* Add User Guide with separate User Manual and Technical Manual, responsive layout and indexed H2/H3 sections.
* Describe active Connections, Analysis, Search Console, Analytics, Bing and Trends workflows; report persistence and OAuth compatibility.
* Keep provider calls, credential handling, reporting and all existing functions unchanged.


= 1.1.8 =
* Add an automatic Google Trends report for Spanish clusters and primary hubs, building on the independent Trends workload manager.

= 1.1.7 =
* Scope Google Trends to Spain (geo=ES) by cluster/primary hub and run work with a bounded load manager.

= 1.1.6 =
* Show explicit provider connection states (connected, configured but unverified, pending, error and disabled), per-service checks and latest test timestamps.

= 1.1.5 =
* Restored the previous Analista 3.9.0 engine as the Insights > Analysis tab instead of rebuilding it.
* Preserved its 28/60/90-day views, lazy partial analyses, action plan, comparison, structure, content, trends and JSON export.
* Adapted legacy admin URLs and assets to the standalone Insights plugin.

= 1.1.4 =
* Removed Analyst, Executive Reports, Market, their supporting code, assets, and tests.
* Kept Connections, Google Search, Google Analytics, Bing, and Google Trends.
* Removed Market-only YouTube and SerpApi provider configuration.

= 1.1.3 =
* Reuses the authorized Google Search Console OAuth property and credentials already configured in the historic plugin, without copying or modifying its secrets.
* Tries explicitly paired modern OAuth, legacy OAuth, and service-account properties during administrator-initiated report downloads; a 403 from one identity no longer masks another valid connection.
* Preserves the previous report schema and saves the property/auth source used for transparency.
* Fixes the HTTP status inspection in WP_Error and adds deterministic regression tests for fallback behavior.

= 1.1.2 =
* Added a concise commercial decision panel before the detailed Analyst diagnostics, with KPIs, event funnel bars, and specific customer actions.
* Existing Prepared Intelligence and Market reports remain read-only sources. Missing data is unavailable, not zero; supplier commissions cannot be inferred from WooCommerce purchases.
* Added smoke checks for commercial summary contracts, navigation, and absent data.

= 1.1.1 =
* Added persistent Prepared Intelligence built from saved Search, GA4, Bing, Trends and Market evidence.
* Analyst now consumes prepared report data instead of re-running deterministic Search/GA4 preprocessing on page load.
* Market aggregation is cached and refreshed when new Market evidence is saved.
* Detailed provider reports remain the auditable source while Analyst receives compact prepared summaries and candidate rows.

= 1.1.0 =
* Recovered the clear diagnostic dashboard from the former Analista inside the modern Analyst tab.
* Added visibility index, ranking distribution, keyword movement, Search evolution, catalog URL types, and architecture counters from saved snapshots.
* Search snapshots now keep previous-period queries/pages and daily position so Top 10/50/100 movement is auditable.
* Fixed GA4 batchRunReports by splitting report requests into batches of at most five.
* Added GA4 ecommerce funnel event counts for product views, add-to-cart, checkout and purchase diagnostics.
* Renamed report retrieval actions to “Actualizar datos” for clearer manual refresh semantics.

= 1.0.0 =
* Consolidated compatible external connections into Insights.
* Added unified Connections and global Analyst reporting.
* Retained historical settings and OAuth callback contracts.

== Historical origins (monolith; not standalone releases) ==
* Legacy origin: Google Search Console, Google Analytics (GA4), Bing, Merchant and Trends connections were originally implemented in the SEO monolith; the 2.4.1 monolith was not an Insights 1.x release.
* The 2.3.7 monolith documented Google Trends and market research; the 2.3.9/2.4.0 line brought reporting, traffic evidence and editorial recommendations.
* Independent Google/Bing and Connections work migrated existing provider configuration, callbacks and stored reporting without requiring customers to recreate OAuth credentials.
* Connections became a compatibility bridge; Insights consolidated provider screens, manual downloads, status diagnostics and the historical Analysis/Analista reporting engine.
* The legacy Analysis dashboards were restored after an experimental executive-report replacement proved incomplete; original reporting workflows remained the baseline.
* Standalone Insights then added country-scoped Trends reports, user/technical manuals, non-destructive Auto Test, before/after comparisons and WordPress.org security/packaging corrections.
