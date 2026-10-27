=== TaxoCommerce Insights ===
Contributors: davidperezmartorell
Tags: analytics, search-console, bing, ecommerce, reporting
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

External connections, manual provider data downloads, and the restored legacy Analysis dashboard.

== Description ==

TaxoCommerce Insights keeps external service connections and manual data downloads in one place.

* Connections: Google Search Console, Google Analytics, Google Merchant Center, Bing Webmaster, Amazon and Pinterest.
* Google Search downloads.
* Google Analytics downloads.
* Bing downloads.
* Google Trends exploration and saved snapshots.
* Analysis restores the previous working Analista engine inside Insights, including summary, content, structure, trends, comparison and work-plan views.
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
