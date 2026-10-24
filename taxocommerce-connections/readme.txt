=== TaxoCommerce Connections ===
Contributors: davidperezmartorell
Tags: integrations, api, amazon, pinterest, oauth
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shared external connections for the TaxoCommerce plugin family.

== Description ==

TaxoCommerce Connections centralizes credentials and OAuth that are consumed by more than one TaxoCommerce module.

Version 0.1.0 includes:

* Amazon Associates Partner Tag.
* Optional Amazon Creators API client.
* Backward-compatible helpers used by Templates and Dependiente.
* Pinterest Business App ID / App Secret.
* Pinterest OAuth with the existing stable callback URL.
* Pinterest board selection and API test.
* Pinterest Merchant domain verification and catalog feed URL.
* Compatibility synchronization with the legacy TaxoCommerce SEO options while both plugins coexist.

Connections does not own reports, feeds, campaigns or social publication workflows. Those remain in the functional modules that consume each connection.

Cloudflare is intentionally not migrated in 0.1.0 because the current installation has it disabled and no required modular consumer depends on it.

Google and Microsoft Bing connections are candidates for a later migration to this shared layer. Their reports, Trends and market analysis should remain in a reporting/insights module rather than Connections.

== Changelog ==

= 0.1.0 =
* Added shared Amazon Associates / Creators connection.
* Added shared Pinterest Business / OAuth connection.
* Added legacy compatibility for Templates, Dependiente and Pinterest Merchant.
