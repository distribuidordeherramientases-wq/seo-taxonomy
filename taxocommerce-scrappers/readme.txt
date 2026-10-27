=== TaxoCommerce Scrappers ===
Contributors: davidperezmartorell
Tags: ecommerce, market-research, pricing, serpapi, scraperapi
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

External research for TaxoCommerce using the proven Ojeador and Ingeniero engines.

== Description ==

TaxoCommerce Scrappers separates external market and technical research from the historical monolithic plugin.

The plugin provides three administration areas:

* Connections: shared SerpApi and ScraperAPI credentials, usage and connection test.
* Ojeador: category-first Google Shopping market intelligence, price position, discovered products, Merchant comparison and operational logs.
* Ingeniero: technical documentation, practical experience, current-sector research, traceable evidence and editorial dossiers.

SerpApi is the primary external search provider. ScraperAPI is the fallback provider when supported and when SerpApi is unavailable or its configured quota is exhausted.

Existing option and database names are intentionally retained so a site that already used Ojeador or Ingeniero can reuse its stored configuration and learned/researched data.

No automatic catalogue or content changes are made solely from external research. Administrative actions remain explicit and capability/nonce protected.

== Installation ==

1. Install and activate TaxoCommerce Scrappers.
2. Open TaxoCommerce > Scrappers > Connections.
3. Configure SerpApi and/or ScraperAPI.
4. Test the provider chain.
5. Open Ojeador or Ingeniero and review the migrated functionality.

TaxoCommerce Core is optional. When Core is active, Scrappers registers as a family extension.

== External Services ==

This plugin can connect to:

* SerpApi for structured Google Search, Shopping, News and related search results.
* ScraperAPI as a fallback for supported structured Google Search and Shopping requests.

The administrator supplies and controls all API credentials. Provider usage can incur external costs according to the administrator's provider plan.

== Changelog ==

= 0.1.0 =
* Created standalone TaxoCommerce Scrappers plugin.
* Copied the legacy Ojeador 0.10.0 engine.
* Copied the legacy Ingeniero 0.5.0 engine.
* Centralized SerpApi and ScraperAPI configuration in Connections.
* Added Ojeador and Ingeniero as tabs of the standalone plugin.
* Registered Scrappers in TaxoCommerce Core for local ZIP installation/activation.
