=== TaxoCommerce System Check ===
Contributors: davidperezmartorell
Tags: system, diagnostics, wordpress, security, performance
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Server, WordPress and plugin diagnostics with administrator-controlled reports and local checks.

== Description ==

TaxoCommerce System Check provides a standalone administration entry for diagnostics, and can integrate its menu with TaxoCommerce Core when installed.

* WordPress, PHP and hosting/system status screens.
* Plugin validation and local PHP checks.
* Optional HTTP URL audit of site content, when started explicitly by an administrator.
* On-demand diagnostic reports and optional administrator-configured notification emails.
* Dedicated bounded processing for longer URL checks. Neither external GitHub runners nor artificial traffic generation are necessary.
* Historical administration URLs are kept for compatibility.

Some historical diagnostic callbacks are reused when the old TaxoCommerce SEO plugin is still present. Standalone behavior must be tested with the old plugin disabled before publication.

== Installation ==

1. Install and activate TaxoCommerce System Check.
2. Open System Check in WordPress administration (or the Core submenu if Core is installed).
3. Run a system or plugin check and review the findings.
4. Start URL audits or enable scheduled email reports only when you intend to use them.

== Privacy ==

System diagnostics can include server configuration, URLs and error details. Treat exported results as potentially sensitive. No diagnostic report should be sent to a developer automatically: optional email reports must be explicitly enabled and target an address chosen by the site administrator. HTTP checks make requests to the site's specified URLs and must be used within hosting limits.

== Changelog ==

= 0.2.8 =
* Standalone system and plugin diagnostics with compatibility routes.
* Local PHP checks and optional bounded HTTP URL audits.
* Administrator-controlled reports and notifications.
