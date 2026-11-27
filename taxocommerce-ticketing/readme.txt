=== TaxoCommerce Ticketing ===
Contributors: davidperezmartorell
Tags: tickets, support, helpdesk, customer-service, contact
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Independent helpdesk for WordPress websites and WooCommerce stores, with a customizable support form and private ticket history.

== Description ==

TaxoCommerce Ticketing supports customers, shipping incidents, product questions and technical queries without depending on other TaxoCommerce plugins or WooCommerce.

* Public form via [taxocommerce_ticket_form] or [taxocommerce_tickets].
* Editable form heading, introduction, field labels, categories and email address.
* Blue, green and brown form palettes; optional logo from the WordPress Media Library.
* Customer name, email, subject, message, optional order/product references and one optional attachment.
* Private customer link emailed on creation; replies and downloads require possession of that unique link.
* Private admin inbox, statuses, priorities, assignments, thread history and internal notes.
* Email notifications use native wp_mail() and depend on the site's mail delivery setup.
* Attachments restricted to PDF, JPG, PNG and TXT, maximum 2 MB per upload; stored privately in a plugin table, never in a publicly served uploads directory.
* Request throttling and spam honeypot on public form submissions; rejections do not display a success notice.
* Administrative Activity log shows only fixed event types, UTC date, and ticket numbers (no customer personal data).
* Separate Functionality Check with synthetic insert/read/delete validation, and Code Check with batch scanning and JSON downloads.
* In-plugin user manual and technical manual, accessible from Tickets tabs.
* Optional action hook tctk_ticket_admin_actions for integration with other applications; no required plugin coupling.

This plugin stores personal data in dedicated database tables. Exports, erasure, retention and legal privacy obligations remain the responsibility of the site administrator. Backup before uninstalling or changing database data; deactivation retains records.

== Installation ==

1. Install and activate TaxoCommerce Ticketing in WordPress.
2. Open Tickets > Form settings. Configure headings, notification email, categories and optional branding.
3. Create a WordPress page and add [taxocommerce_ticket_form].
4. Send a test request using an email inbox you control and verify both notifications and private reply link.
5. Do not assume wp_mail() means delivery; configure a reliable outgoing mail transport.

== Frequently Asked Questions ==

= Does this need WooCommerce or TaxoCommerce Core? =
No. Both are optional.

= Are ticket attachments publicly accessible? =
No. Files are stored in dedicated database tables and streamed only after staff permission or a valid private ticket token check.

= Can I change the form's branding? =
Yes. Its title, text labels, request types, logo attachment ID and blue/green/brown palette are configurable.

= Can customers reply directly by email? =
The email includes a private reply link. This first version does not ingest inbound email replies.

== Changelog ==

= 0.1.5 =
* Localize 55 additional fixed administrative labels and diagnostic messages in five locales (275 entries). Display translated functionality-check details, check and status labels, and activity-event descriptions alongside unchanged event identifiers. Preserve stored diagnostics, JSON exports, customer data and all check behavior. Rebuild and verify five MO catalogs, now 311 entries per locale. No production deployment.

= 0.1.4 =
* Complete all 256 existing gettext keys in five locales (460 additional entries): technical manual, code-check controls and diagnostic guidance. Rebuild and validate five MO catalogs against the POT and preserve prior translations, identifiers, configured content and behavior. Coverage refers to extracted gettext keys only; review hardcoded interface strings separately. No production deployment.

= 0.1.3 =
* Add a native gettext template with 256 static interface keys and five bundled PO/MO catalogs (es_ES, en_US, fr_FR, de_DE, nl_NL). First batch: 164 translated keys per locale (820 entries) covering ticket inbox, statuses, priorities, form defaults, fixed notifications, form settings, functional-check labels and activity-log controls. Include the user manual; leave 92 technical manual/code-check strings for later review. Prefer official language packs with bundled regional fallback on init; keep configured labels, ticket content, stored categories, access controls, notifications and database behavior unchanged. No production deployment.

= 0.1.2 =
* Fix false success when anti-spam rejects a request and preserve new message ID before timestamp updates.
* Add administrator event log with bounded private-event identifiers and ticket IDs.
* Split Autocheck into a live synthetic Functionality Check and an independent PHP/WordPress/Security Code Check with JSON exports.
* Add built-in user and technical manuals.


= 0.1.1 =
* Make the official Focazul plugin page discoverable and prepare installation on authorized test sites and Core local ZIP management. Issue #2871.


= 0.1.0 =
* Initial independent Ticketing plugin with configurable forms, private conversation, staff inbox, notifications and attachments.
