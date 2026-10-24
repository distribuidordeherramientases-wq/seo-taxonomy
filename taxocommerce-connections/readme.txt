=== TaxoCommerce Connections ===
Contributors: davidperezmartorell
Tags: integrations, api, amazon, pinterest, oauth
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shared external connections for the TaxoCommerce plugin family.

== Description ==

TaxoCommerce Connections centralizes credentials and OAuth that are consumed by more than one TaxoCommerce module.

Version 0.2.0 includes:

* Google Search Console / GA4 service-account settings.
* Google Merchant Center account settings.
* Microsoft Bing Webmaster API settings.
* Google OAuth presentation and callback compatibility with Insights.
* Amazon Associates Partner Tag.
* Optional Amazon Creators API client.
* Backward-compatible helpers used by Templates and Assistant.
* Pinterest Business App ID / App Secret.
* Pinterest OAuth with the existing stable callback URL.
* Pinterest board selection and API test.
* Pinterest Merchant domain verification and catalog feed URL.
* Compatibility synchronization with the legacy TaxoCommerce SEO options while both plugins coexist.

Connections does not own reports, feeds, campaigns or social publication workflows. Those remain in the functional modules that consume each connection.

Cloudflare is intentionally not migrated in 0.2.0 because the current installation has it disabled and no required modular consumer depends on it.


== Language ==

English is the base source and UI language. Future localization should use standard WordPress gettext translations without changing technical slugs or option keys.

== Changelog ==

= 0.2.0 =
* Added shared Google Search Console / GA4 service-account settings.
* Added Google Merchant Center account settings and connection tests.
* Added Microsoft Bing Webmaster settings and connection tests.
* Moved the visible connection ownership away from Insights while preserving the existing option names and OAuth callback contracts.
* Standardized the base UI language to English.


= 0.1.0 =
* Added shared Amazon Associates / Creators connection.
* Added shared Pinterest Business / OAuth connection.
* Added legacy compatibility for Templates, Dependiente and Pinterest Merchant.
