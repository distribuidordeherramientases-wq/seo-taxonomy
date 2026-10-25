=== TaxoCommerce Social ===
Contributors: taxocommerce
Requires at least: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later

Standalone social publishing module for the TaxoCommerce family.

== 0.1.0 ==
* Migrates the existing Facebook, Instagram, LinkedIn, Pinterest and X social connectors.
* Reuses the existing seo_social_network_settings_v1 option and the seo_social_publications / seo_social_schedule tables.
* Provides exactly three administration tabs: Connections, Templates and Calendar.
* Calendar includes manual scheduling, CSV agenda tools, queue/history and monthly calendar.
* Removes campaign/offer integration and does not apply special scheduling rules for News content.
* Keeps its own tcsocial_* functions so it can coexist briefly with the legacy plugin for verification.
* Does not delete shared social tables or connection data on deactivation/uninstall.

== Migration ==
Activate alongside the legacy plugin only for a short read-only verification period. Both modules see the same stored social configuration and operational tables. Do not manage the same connection or schedule simultaneously from both plugins.
