# TaxoCommerce Insights

TaxoCommerce Insights is the reporting and decision-support layer of the TaxoCommerce family.

The plugin keeps its historical technical slug `taxocommerce-google-bing` for compatibility, but its displayed product name is **TaxoCommerce Insights**.

## Responsibilities

Insights owns external connections, reporting, market research, and strategic analysis.

Active areas:

- **Connections** — Google, Bing, Merchant, Amazon, Pinterest, YouTube, and SerpApi credentials/OAuth used by Insights and other TaxoCommerce modules.

- **Analyst** — concise commercial directives and KPIs first, with funnel event visualization; full SEO/Market diagnostics and now/next/investigate/watch sections remain below. Read-only saved data, with explicit caveats about off-site affiliate conversions.
- **Executive Report** — consolidated local view across Search Console, GA4, Bing, Trends, and Market.
- **Google Search** — clicks, impressions, CTR, average position, countries, devices, queries, and pages.
- **Google Analytics** — sessions, active/new/returning users, views, engagement, channels, countries, devices, key events, and purchases.
- **Bing** — search traffic, queries, pages, crawl health, and index visibility.
- **Google Trends** — manual trend exploration and saved trend snapshots.
- **Market** — category-first external evidence, Merchant context, optional YouTube/SerpApi sources, and opportunity support.

## Connections

Connections are now configured directly in **TaxoCommerce Insights → Connections**.

The unified screen owns:

- Google Search Console / GA4 service account.
- Google Search Console OAuth.
- Google Merchant Center.
- Microsoft Bing Webmaster.
- Market providers: YouTube Data API and SerpApi.
- Amazon Associates / Creators API.
- Pinterest Business / OAuth / board selection.
- Pinterest Merchant domain verification and catalog feed URL.

The historical option names and public compatibility helpers are deliberately preserved. Existing credentials are read in place; users do not have to reconnect services after the consolidation.

The former `TaxoCommerce Connections` plugin is retained temporarily only as a compatibility bridge. When Insights is active it does not register a separate family module or connection screen; historical URLs redirect to `Insights → Connections`.

## Compatibility

The plugin directory, option names, internal tab keys, and callback contracts remain compatible with the former Google/Bing module. Internal identifiers such as `informe` and `mercado` are retained as technical keys only; the visible UI is English.

Opening report tabs never downloads business data automatically. External requests happen only through explicit download, refresh, or research actions.

## Language

English is the base source and UI language. Future localization should use standard WordPress gettext translations while preserving historical technical slugs and option names.

## Validation

The GitHub Actions workflow validates PHP syntax, local-only report rendering, executive aggregation, Trends behavior, Market independence, and package deployment to STAGING.

## Version 1.0.0

- Merged the standalone TaxoCommerce Connections capability into TaxoCommerce Insights.
- Added a visible **Connections** tab to Insights.
- Preserved all existing Google/Bing/Merchant option names and Google OAuth callback contracts.
- Embedded Amazon Associates / Creators and Pinterest Business connection services with their existing option names and legacy helper APIs.
- Added YouTube and SerpApi Market-provider credentials to the same Connections tab.
- Removed standalone Connections from the Core family extension catalog.
- Converted the old TaxoCommerce Connections plugin into a temporary compatibility bridge that redirects historical administration URLs to Insights.
- No credential migration or reconnection is required.

## Version 0.9.1

- Makes Analyst explicitly global: Market performs individual category/product research; Analyst aggregates the saved results.
- Adds a Global Market Summary with research coverage, pending/error counts, product signals, unique products/clusters, observed brands, and observed sellers.
- Aggregates repeated Merchant/offer product signals across all analyzed categories.
- Adds market/catalog gap prioritization by contrasting accumulated product signals with local WooCommerce category depth.
- Keeps detailed category evidence, Merchant leaders, and observed competitor domains below the global summary as supporting evidence.
- No provider calls are made by Analyst; all aggregation is local over saved Market data.

## Version 0.9.0

- Added **Analyst** inside Insights rather than creating another plugin.
- Rebuilt the useful former Analista workflow on top of current saved Search, Analytics, Bing, Trends, Market, and Merchant data.
- Restored a short work queue with **NOW / NEXT / INVESTIGATE / WATCH** buckets.
- NOW requires direct demand evidence; Market/Trends-only signals remain exploratory until confirmed.
- Added a stable local-only `TaxoCommerce_Google_Bing_Analyst::report()` contract for the future TaxoCommerce Editor.
- Analyst never downloads provider data when opened or exported.
- Market comparison is explicitly evidence-based and never presented as an external ranking unless the source actually provides one.

## Version 0.8.0

- Renamed the displayed product from TaxoCommerce Google/Bing to TaxoCommerce Insights.
- Removed connection ownership from the Insights navigation.
- Redirected the old Connections tab to TaxoCommerce Connections.
- Kept existing internal slugs/options for backward compatibility.
- Standardized active Insights UI text to English.
