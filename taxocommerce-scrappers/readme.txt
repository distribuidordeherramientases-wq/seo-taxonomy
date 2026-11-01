=== TaxoCommerce Scrappers ===
Contributors: davidperezmartorell
Tags: ecommerce, market-research, pricing, serpapi, scraperapi
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

External research for TaxoCommerce using the proven Ojeador and Ingeniero engines.

== Description ==

TaxoCommerce Scrappers separates external market and technical research from the historical monolithic plugin.

The plugin provides five administration areas:

* Connections: shared SerpApi and ScraperAPI credentials, usage and connection test.
* Ojeador: category-first Google Shopping market intelligence, price position, discovered products, Merchant comparison and operational logs.
* Ingeniero: technical documentation, practical experience, current-sector research, traceable evidence and editorial dossiers.
* Autotest: on-demand, read-only checks of component availability, registered process hooks, database tables, and local provider configuration (no HTTP, no job execution or API charges).
* Manual: integrated user instructions and technical reference for the existing services.

SerpApi is the primary external search provider. ScraperAPI is the fallback provider when supported and when SerpApi is unavailable or its configured quota is exhausted.

Existing option and database names are intentionally retained so a site that already used Ojeador or Ingeniero can reuse its stored configuration and learned/researched data.

No automatic catalogue or content changes are made solely from external research. Administrative actions remain explicit and capability/nonce protected.

== Installation ==

1. Install and activate TaxoCommerce Scrappers.
2. Open TaxoCommerce > Scrappers > Connections.
3. Configure SerpApi and/or ScraperAPI.
4. Test the provider chain.
5. Open Ojeador or Ingeniero and review the migrated functionality.
6. Open Manual for user/technical guidance and Autotest to validate service availability without using external providers.

TaxoCommerce Core is optional. When Core is active, Scrappers registers as a family extension.

== External Services ==

This plugin can connect to:

* SerpApi for structured Google Search, Shopping, News and related search results.
* ScraperAPI as a fallback for supported structured Google Search and Shopping requests.

The administrator supplies and controls all API credentials. Provider usage can incur external costs according to the administrator's provider plan.

== Changelog ==

= 0.1.4 =
* Confirmed correction of the six blocking Plugin Check findings from the production report: five legacy translation domains and one unescaped vocabulary table lookup were already fixed in staging 0.1.3.
* Extended the offline regression check to every bundled PHP runtime class and the exact vocabulary SQL query, preventing these errors from being reintroduced.
* No changes to live Ojeador/Ingeniero behavior, existing tables or external provider calls.

= 0.1.3 =
* Bind SQL table identifiers using WordPress %i placeholders in Ingeniero and Ojeador, including supplier migration, analysis, and JSON export paths.
* Restrict JSON export table sorting/filtering to internal values and bind pagination limits.
* Clarify read-only GET status handling to avoid misleading nonce warnings; retain nonce checks for operations that write.
* Preserve the plugin text domain taxocommerce-scrappers already corrected in 0.1.2.
* Escape all generated Manual navigation links and resolve blocking SQL preparation findings in Ojeador analysis.

= 0.1.2 =
* Added an integrated Manual tab with user and technical sections covering Connections, Ojeador, Ingeniero, storage, jobs, security and troubleshooting.
* Added a grouped, on-demand, read-only Autotest tab for component and database availability, provider configuration (without exposing keys), and registered hooks. Does not call providers or launch jobs.
* Added isolated automated regression tests without shipping developer tests in the plugin ZIP.

= 0.1.1 =
* Fix fatal activation when Ingeniero runs without the historical monolithic plugin: provide a guarded process interface.
* Preserve coexistence with the legacy process contract without registering another supervisor.


= 0.1.0 =
* Created standalone TaxoCommerce Scrappers plugin.
* Copied the legacy Ojeador 0.10.0 engine.
* Copied the legacy Ingeniero 0.5.0 engine.
* Centralized SerpApi and ScraperAPI configuration in Connections.
* Added Ojeador and Ingeniero as tabs of the standalone plugin.
* Registered Scrappers in TaxoCommerce Core for local ZIP installation/activation.

== Historical origins (monolith; not standalone releases) ==
* [Complete legacy and release history](https://github.com/distribuidordeherramientases-wq/seo-taxonomy/tree/main/taxocommerce-scrappers) (changelog.txt / CHANGELOG.md).

* Legacy origin: Ojeador market observation and Ingeniero technical/editorial research operated as services of TaxoCommerce SEO; no earlier Scrappers standalone versions are asserted.
* Monolith 2.3.6–2.3.7 documented Ojeador and Google Shopping/Trends observation, commercial gaps and evidence-based opportunities.
* The 2.3.9–2.4.0 cycles documented Ingeniero source collection, technical dossiers, evidence review and controlled editorial drafts.
* Scrappers 0.1.0 copied the legacy Ojeador and Ingeniero engines into independent Configuration, Ojeador and Ingeniero screens, including compatible provider settings and UI routes.
* The first standalone release added a module bootstrap, Core registration, smoke tests and a staging ZIP/deployment workflow, with coexistence protections while the monolith was still installed.
* External scraping, ranking and publication require the administrator's configured providers and review; extraction alone is not proof of autonomous third-party access or automated publishing.
