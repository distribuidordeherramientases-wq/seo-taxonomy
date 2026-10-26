=== TaxoCommerce Insights ===
Contributors: davidperezmartorell
Tags: analytics, search-console, bing, ecommerce, reporting
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Search, analytics and market reporting with optional external service connections and an executive Analyst view.

== Description ==

TaxoCommerce Insights helps WordPress and WooCommerce administrators compare search visibility, audience metrics and saved market research.

* Connections: Google Search Console, Google Analytics, Google Merchant Center, Bing Webmaster, optional Amazon and Pinterest services, YouTube and SerpApi.
* Search, Analytics and Bing reporting with date comparisons and downloadable summaries where connected.
* Trends and saved market evidence for categories and products.
* Analyst and Executive reports aggregate locally stored results into priorities.
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

It is the continued plugin under the historical slug taxocommerce-google-bing, renamed to Insights and extended to unified connections and Analyst reporting.

== Changelog ==

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
