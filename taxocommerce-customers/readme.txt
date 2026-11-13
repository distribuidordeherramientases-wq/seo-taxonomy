=== TaxoCommerce Customers ===
Contributors: davidperezmartorell
Tags: woocommerce, crm, customers, contacts, csv
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A private, modular CRM for WooCommerce purchasers and manual contacts, with bulk CSV import/export.

== Description ==

TaxoCommerce Customers is independent of other TaxoCommerce extensions.
It reads paid orders through WooCommerce public APIs (HPOS compatible), including guests, and stores only minimal purchaser identities and CRM fields, not copies of WooCommerce orders. New paid orders update the CRM automatically; existing orders can be synchronized in small batches without WP-Cron.

* Private directory: paid WooCommerce buyers and manually created contacts (email, phone or WhatsApp).
* Customer records: name, company, contact and address details, preferred channel, labels and private notes.
* New contacts do NOT become WooCommerce/WordPress accounts. No emails or WhatsApp messages are sent.
* Exact email is an optional link: a manual contact can become a purchaser while retaining its notes. Names/phones alone are never auto-merged.
* CSV import/export: 5 speeds, progress and bounded chunks; new imports create manual contacts, updates use the local ID and verified email.
* WooCommerce retains full ownership of orders, statuses, payments and accounting documents.
* Customer records are only visible to users allowed to manage WooCommerce (or WordPress admins).
* Self-contained TaxoCommerce admin header, two manuals, code autocheck and ES/EN/FR/DE/NL translations.
* Data is never published on the site. No marketing consent is assumed or campaigns triggered.

When TaxoCommerce Core is active, the plugin appears under its family menu; otherwise it has a standalone administrator menu. WooCommerce is optional for manual contacts, but required to discover purchaser orders.

== Installation ==

1. Activate the plugin; two private tables are created using the local WordPress prefix.
2. Open TaxoCommerce > Customers, or Customers when Core is unavailable.
3. Open Customers to add contacts manually or synchronize existing paid WooCommerce orders. New paid orders are discovered automatically.
4. Export a backup CSV before bulk changes, then use the Import / Export tab. Blank CSV values overwrite CRM fields.
5. See User manual / Technical manual and run Autotest. Review your privacy/retention obligations.

== Frequently Asked Questions ==

= Are guests included? =
Yes, paid guest orders with a valid billing email are linked by exact normalized email. Orders without an email cannot be reliably identified and are skipped.

= Does deleting a contact delete a WooCommerce user or order? =
No. A record linked to existing orders cannot be removed from WooCommerce through Customers; the plugin can clear its private notes and added fields. Manual contacts without orders can be deleted completely.

= Can a manual contact log in to the shop? =
No. Adding a manual contact does not create a WordPress user account.

= Will an import send marketing messages? =
No. CSV import is purely administrative.

== Changelog ==

= 0.1.0 =
* Initial independent customer directory, WooCommerce purchase sync, manual contacts and CSV management.
