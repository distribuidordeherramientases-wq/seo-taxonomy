=== TaxoCommerce Social ===
Contributors: davidperezmartorell
Tags: social-media, scheduling, pinterest, publishing, calendar
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage authorized social connections, publication templates and a content calendar.

== Description ==

TaxoCommerce Social supports optional Facebook, Instagram, LinkedIn, Pinterest and X connections, manual publication planning, CSV-based scheduling and queue/history views.

* Administration areas: Connections, Templates and Calendar.
* Existing social settings and publication/schedule tables are reused on migrating sites.
* Dedicated local calendar; offer/campaign planning belongs to TaxoCommerce Campaigns.
* No social account is connected or published to by default. Administrators must authorize a provider and perform the action.

The plugin interacts with selected social network services when an administrator connects an account or starts publishing. External platforms operate under their own access tokens, API quotas and terms; configure only accounts you are authorized to manage.

== Installation ==

1. Install and activate TaxoCommerce Social.
2. Open Social > Connections and authorize only the desired providers.
3. Configure a publication template and inspect your social platform permissions.
4. Test a single scheduled publication before enabling automated operation.

== Privacy ==

Social publishing sends authorized content and associated account data to the selected external social network. Store tokens securely, review provider policies and avoid including personal information in generated posts without permission. Disconnect accounts when the integration is not required.

== Changelog ==

= 0.1.0 =
* Extracted social connections, templates and calendar into a standalone plugin.
* Preserved existing settings and history without deleting them on deactivation.

== Historical origins (monolith; not standalone releases) ==
* [Complete legacy and release history](https://github.com/distribuidordeherramientases-wq/seo-taxonomy/tree/main/taxocommerce-social) (changelog.txt / CHANGELOG.md).

* Legacy origin: social-network publishing, scheduling and commercial reporting were features of TaxoCommerce SEO before the independent Social plugin; those monolith versions are not Social standalone releases.
* The monolith 2.3.6 and 2.3.9 cycles documented social channels and marketing/reporting connections alongside commercial campaigns.
* Standalone 0.1.0 moved network integrations and scheduled publication into an independent module with its own bootstrap, navigation and Core registration.
* Staging and deployment work subsequently introduced a separate ZIP workflow and reconciled the canonical shared storage used by the former monolith and the new plugin.
* Marketing campaign planning and banners belong to Campaigns, while Social owns its authorised publishing actions; no external-network publication is implied merely by installing the module.
