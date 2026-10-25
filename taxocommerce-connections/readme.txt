=== TaxoCommerce Connections Compatibility Bridge ===
Contributors: davidperezmartorell
Tags: integrations, compatibility, insights
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Temporary compatibility bridge after moving all external connections into TaxoCommerce Insights.

== Description ==

From version 0.3.0, TaxoCommerce Connections is no longer an independent TaxoCommerce family service.

The canonical administration screen is:

TaxoCommerce Insights -> Connections

When Insights is active, this bridge:

* Does not register a separate Core family extension.
* Does not register a visible Connections menu.
* Redirects historical admin URLs to Insights -> Connections.
* Does not load duplicate Amazon, Pinterest, Google, Bing, or Merchant connection classes.

If Insights is accidentally disabled while this bridge is still active, the historical standalone connection implementation can load as a temporary fallback so credentials remain reachable.

Existing option names are unchanged. The consolidation does not require reconnecting Google, Bing, Merchant, Amazon, Pinterest, YouTube, or SerpApi.

Once the consolidated Insights installation is verified operationally, this compatibility plugin can be deactivated.

== Changelog ==

= 0.3.0 =
* Converted the standalone plugin into a compatibility bridge.
* Moved canonical connection ownership and UI to TaxoCommerce Insights.
* Historical Connections URLs now redirect to Insights -> Connections.
* Removed standalone family-extension registration.
* Preserved standalone fallback behavior when Insights is unavailable.

= 0.2.0 =
* Shared Google, Bing, Merchant, Amazon, and Pinterest connection layer before consolidation.
