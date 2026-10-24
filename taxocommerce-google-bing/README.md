# TaxoCommerce Insights

TaxoCommerce Insights is the reporting and decision-support layer of the TaxoCommerce family.

The plugin keeps its historical technical slug `taxocommerce-google-bing` for compatibility, but its displayed product name is **TaxoCommerce Insights**.

## Responsibilities

Insights owns reporting and analysis, not credentials.

Active areas:

- **Executive Report** — consolidated local view across Search Console, GA4, Bing, Trends, and Market.
- **Google Search** — clicks, impressions, CTR, average position, countries, devices, queries, and pages.
- **Google Analytics** — sessions, active/new/returning users, views, engagement, channels, countries, devices, key events, and purchases.
- **Bing** — search traffic, queries, pages, crawl health, and index visibility.
- **Google Trends** — manual trend exploration and saved trend snapshots.
- **Market** — category-first external evidence, Merchant context, optional YouTube/SerpApi sources, and opportunity support.

## Connections

Credentials and OAuth are configured in **TaxoCommerce Connections**.

Connections currently owns the visible configuration for:

- Google Search Console / GA4 service account
- Google Merchant Center
- Microsoft Bing Webmaster
- Amazon Associates / Creators API
- Pinterest Business / OAuth

Insights intentionally continues to read the existing Google/Bing option names during migration so no credentials or historical configuration are lost.

## Compatibility

The plugin directory, option names, internal tab keys, and callback contracts remain compatible with the former Google/Bing module. Internal identifiers such as `informe` and `mercado` are retained as technical keys only; the visible UI is English.

Opening report tabs never downloads business data automatically. External requests happen only through explicit download, refresh, or research actions.

## Validation

The GitHub Actions workflow validates PHP syntax, local-only report rendering, executive aggregation, Trends behavior, Market independence, and package deployment to STAGING.

## Version 0.8.0

- Renamed the displayed product from TaxoCommerce Google/Bing to TaxoCommerce Insights.
- Removed connection ownership from the Insights navigation.
- Redirected the old Connections tab to TaxoCommerce Connections.
- Kept existing internal slugs/options for backward compatibility.
- Standardized active Insights UI text to English.
