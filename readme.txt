=== TaxoCommerce SEO - Catalog, Trends, Content & Feeds ===

Contributors: davidperezmartorell
Tags: seo, woocommerce, taxonomy, catalog, automation
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.3.10
License: MIT
License URI: https://opensource.org/license/mit

SEO and commercial platform for WooCommerce: taxonomy, catalog, analytics, automation, campaigns, suppliers, and maintenance tools.

== Description ==

TaxoCommerce SEO is a modular platform for WordPress and WooCommerce designed for sites with large product catalogs and integrated SEO, commercial, and operational management needs.

The plugin centralizes tools that are often spread across multiple screens and processes.

Key features:

* SEO architecture based on Cluster -> Primary Hub -> Secondary Hub -> Category -> Product.
* Product, category, page, post, and image management.
* Vocabulary, semantic labels, and attributes.
* SEO reports, auditing, and quality controls.
* Redirect management.
* Templates for products, categories, cart, checkout, and other site areas.
* Supplier import, export, and synchronization.
* Local and external image management.
* Market monitoring and price comparison.
* Demand analysis and signals.
* Commercial campaigns with products, prices, dates, and automatic restoration.
* Social networks and publication scheduling.
* FAQs and content management.
* Dependent, Interpreter, Academy, and learning services.
* Process manager and workers for heavy operations.
* Diagnostic, maintenance, and validation tools.

TaxoCommerce SEO is actively developed and uses staging to validate changes before promoting them to production.

== Installation ==

1. Download the plugin package.
2. Upload the `seo-taxonomy` folder to `/wp-content/plugins/` or install the ZIP file from WordPress.
3. Activate **TaxoCommerce SEO** from **Plugins**.
4. Open **TaxoCommerce SEO** in the WordPress administration area.
5. Review the modules you intend to use and configure external connections only when required.
6. On WooCommerce installations, verify the catalog, taxes, currency, and environment before running bulk operations.

For significant changes, validation in a staging environment is recommended before production use.

== Frequently Asked Questions ==

= Does SEO Taxonomy require WooCommerce? =

The primary target is WordPress with WooCommerce. Many catalog, product, campaign, pricing, and supplier features depend on WooCommerce. Some general tools can work without it, but the distribution is intended for WooCommerce sites.

= Does the plugin automatically modify prices and products? =

Only modules that perform explicit write operations do so. For example, an enabled commercial campaign can apply a price during its configured period and restore the previous sale state afterwards. Administrative screens indicate when an action saves, modifies, or deletes information.

= Can I test changes before applying them to production? =

Yes. The recommended development workflow uses a staging branch and a test WordPress installation before promoting a release to production.

= Can campaign imports remove products? =

By default, campaign imports use merge mode: they add or update entries without removing products that are absent from the import. Products are fully replaced only when that option is explicitly enabled.

= Are external APIs mandatory? =

Not all of them. Some modules can use external services to obtain additional information. Credentials are configured per module and must not be included in public files or in the distributed package.

== External services ==

TaxoCommerce SEO can connect to external services only when the administrator enables or uses the corresponding module. The plugin does not automatically load third-party analytics beacons.

= Google APIs (Search Console, Analytics, OAuth, Trends, and Cloud Run) =

Used to query Search Console and Analytics, obtain Google Trends signals, and, when configured by the administrator, run technical processes in Google Cloud Run.

Data sent: configured URL/site, property or project identifiers, search/trend queries, technical request parameters, and OAuth or service-account credentials when required. Data is sent only when saving/testing the connection or running the corresponding module.

Terms: https://policies.google.com/terms
Privacy: https://policies.google.com/privacy

= Meta Graph API (Facebook and Instagram) =

Used to connect pages/accounts and publish content when the administrator enables these integrations.

Data sent: page/account identifiers, OAuth tokens, publication text, links, and images/media URLs selected by the administrator.

Terms: https://www.facebook.com/terms.php
Privacy: https://www.facebook.com/privacy/policy/

= LinkedIn API =

Used to connect an organization and publish content to LinkedIn when the administrator enables the integration.

Data sent: organization identifier, OAuth credentials, publication text, and selected media/link references.

Terms: https://www.linkedin.com/legal/user-agreement
Privacy: https://www.linkedin.com/legal/privacy-policy

= Pinterest API =

Used to connect an account/board and create Pins when the administrator enables the integration.

Data sent: account/board identifiers, OAuth credentials, title/description, links, and images associated with the Pin.

Terms: https://policy.pinterest.com/terms-of-service
Privacy: https://policy.pinterest.com/privacy-policy

= X API =

Used to connect an account, publish content, and query publication metrics when the administrator enables the integration.

Data sent: OAuth credentials, account/publication identifier, and content selected by the administrator.

Terms: https://x.com/en/tos
Privacy: https://x.com/en/privacy

= Microsoft Bing Webmaster API =

Used to query Bing Webmaster metrics when the administrator configures the connection.

Data sent: site URL, query parameters, and credentials/API key configured by the administrator.

Terms: https://www.microsoft.com/servicesagreement
Privacy: https://privacy.microsoft.com/privacystatement

= SerpApi =

Market-monitoring and technical-research modules can use SerpApi to query Google Shopping and technical search results.

Data sent: API key, search terms, category/market, and language required for the query. Personal data from store visitors is not sent.

Terms and Privacy: https://serpapi.com/legal

= Amazon APIs =

Amazon importers/integrations can request tokens and query products when the administrator configures the required credentials.

Data sent: application credentials, marketplace, and product search terms/identifiers required for the query.

Conditions of Use: https://www.amazon.com/gp/help/customer/display.html?nodeId=508088
Privacy Notice: https://www.amazon.com/gp/help/customer/display.html?nodeId=GX7NJQ4ZB8MHFRNJ

= GitHub API and GitHub Actions =

Optional diagnostic, visual-checking, and automation processes can launch workflows or query repositories configured by the administrator.

Data sent: owner/repository/workflow, process parameters, callback URL when applicable, and the token configured by the administrator.

Terms: https://docs.github.com/site-policy/github-terms/github-terms-of-service
Privacy: https://docs.github.com/site-policy/privacy-policies/github-general-privacy-statement

= Cloudflare API =

The connections module can query a Cloudflare zone when the administrator configures an API token. SEO Taxonomy does not automatically load Cloudflare Web Analytics.

Data sent: API token, zone identifier, and request parameters required to verify the connection.

Terms: https://www.cloudflare.com/website-terms/
Privacy: https://www.cloudflare.com/privacypolicy/

= TikTok embeds =

The reviews/content module can display a TikTok player only when imported or configured content explicitly includes a TikTok URL or identifier. In that case, the visitor's browser can connect to TikTok to load the player.

Terms: https://www.tiktok.com/legal/terms-of-service
Privacy: https://www.tiktok.com/legal/privacy-policy

= Supplier and external content sources =

Supplier, image, classification, and technical-research modules can download feeds, public pages, images, or documentation from external URLs configured by the administrator or associated with products/suppliers. Requests can include the requested URL and technical HTTP headers. Personal data from store visitors is not sent. The administrator is responsible for having authorization to use each source and for reviewing its terms and privacy policy.

These connections are optional unless a specific function expressly depends on them. Credentials are stored in the WordPress installation and must not be distributed with the plugin.

== Screenshots ==

1. Main SEO Taxonomy dashboard.
2. Product and catalog management.
3. Category and taxonomy architecture.
4. Reports and auditing.
5. Market monitoring and comparison.
6. Marketing and commercial campaigns.
7. Dependent and learning tools.
8. Process manager and workers.

== Changelog ==

= 2.3.9 - 2026-09-30 =

* Consolidates the full 2.3.8.x patch cycle into a stable release.
* Improves Dependent, Academy, and Interpreter, including L9/L10 stability and question-based training.
* Adds Engineer and its integration with Classifier to expand category knowledge, attributes, and vocabulary.
* Extends Auditor with SEO quality controls, block audits, and category balance.
* Improves Marketing, campaigns, calendar, and social-network reports.
* Reorganizes Content, Editor, and Review modules.
* Improves comparison tools, cart, estimates/proformas, and PDF generation.
* Corrects VAT-inclusive retail-price handling to avoid duplicate taxes in cart and checkout.
* Extends operational documentation, Wiki, and publishing workflow.

= 2.3.8.1 - 2026-09-26 =

* Extended commercial campaign management.
* Added explicit editing of existing campaigns.
* Added safe campaign product clearing and deletion.
* Campaign import/export includes products, SKU, prices, and position.
* Added merge and full-replacement modes.
* Added compatibility with older files and product-row autodetection.
* Strengthened safe price restoration and overlap prevention.

= 2.3.7 - 2026-09-25 =

* Added the commercial campaign service and its integration with Analyst.
* Improved market monitoring with price indicators, opportunities, and comparisons.
* Added commercial inventories and supplier synchronization processes.
* Strengthened Dependent V3 and catalog coverage.
* Added Solver and expanded the Review module.
* Improved reports, Google Trends, and commercial feeds.

= 2.3.6 - 2026-09-23 =

* Expanded market monitoring and Google Shopping integration.
* Added new Academy and Trainer phases.
* Evolved Dependent and Interpreter.
* Expanded auditing, validation, FAQs, redirects, and inventories.
* Improved imports, suppliers, images, and worker stability.

= 2.3.5 - 2026-09-17 =

* Major evolution of Dependent, Interpreter, and Linguist/Academy.
* Improved commercial architecture, templates, and reports.
* Added supplier import tools.
* Improved plugin maintenance and cleanup.

= 2.0.0 =

* First documented public release.
* New administration interface.
* Product, category, page, and image management.
* Reports, advanced tools, and semantic learning.

The full technical history is maintained in `CHANGELOG.md`.

== Upgrade Notice ==

= 2.3.9 =

Consolidates the 2.3.8.x patches into stable version 2.3.9. Includes improvements to Dependent/Academy, Engineer and Classifier, Auditor, Marketing, categories, comparison tools, commercial documents, and VAT-inclusive pricing. Catalog, cart/checkout, and automated processes should be validated after updating.

= 2.3.8.1 =

Extends Marketing > Campaigns with editing, complete product/price imports, clearing, and safe deletion. Active campaigns should be reviewed after updating.

== License ==

SEO Taxonomy is distributed under the MIT license. See the included `LICENSE` file.
