=== TaxoCommerce Tasks ===
Contributors: davidperezmartorell
Tags: tasks, calendar, productivity, dashboard, admin
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A private task agenda with categories, priorities, assignments, calendar and indicators. Independent of WooCommerce.

== Description ==

TaxoCommerce Tasks provides a standalone task management area in the WordPress administrator.

* Create, edit and delete tasks and private task categories.
* Prioritize tasks as Low, Normal, High or Urgent.
* Track pending, in-progress, blocked and completed tasks.
* Assign tasks to registered WordPress users; control access with manage_options.
* Filter and sort tasks by status, priority, category, assignee and due date.
* Navigate a monthly calendar displaying due dates.
* Monitor dashboard indicators for overdue tasks, states, priorities and assignees.
* Run a private, read-only Autocheck and download its JSON report.
* Rename existing task categories safely without changing their IDs or task assignments.
* Read a built-in User Manual and Technical Manual (Spanish, English, French, German and Dutch) from separate admin tabs.

Tasks and task categories live exclusively in two custom database tables using the site's WordPress prefix. No WordPress posts, pages, WooCommerce products or public endpoints are created. No external services or data transfers are used. This plugin does not require WooCommerce, TaxoCommerce Core or any other TaxoCommerce plugin.

Administrative labels and both manuals use native WordPress gettext with bundled POT/PO/MO files in Spanish, English, French, German and Dutch; WordPress language packs take precedence. Task titles, descriptions and category names are entered by administrators and are not automatically translated.

== Installation ==

1. Upload the `taxocommerce-tasks` directory into `wp-content/plugins`, or install the ZIP in WordPress.
2. Activate TaxoCommerce Tasks. Two private SQL tables are created with the site's own table prefix.
3. Open Tasks in the WordPress administrator and add categories and tasks.
4. Use Calendar, Dashboard and Autocheck tabs for planning and validation.
5. Open User Manual and Technical Manual in the same screen to consult the instructions and architecture.

Only users with the `manage_options` capability can view or modify the agenda. Uninstalling the plugin does not delete task records.

== Frequently Asked Questions ==

= Does it publish tasks on my website? =

No. Tasks are not registered as post types and no public REST, shortcode or frontend view exists.

= Does it share categories with WooCommerce? =

No. Categories are private to this plugin and are held in a dedicated SQL table.

= Does it depend on TaxoCommerce Core? =

No. It is standalone.

When TaxoCommerce Core is active, Tasks appears within its administration submenu and its Extensions overview. Without Core, Tasks provides its own standalone administration menu. The private task tables and page slug remain unchanged.

== Changelog ==

= 0.1.3 =
* Included both manuals and all new category-editor controls in native gettext catalog files for ES, EN, FR, DE and NL.
* Updated PO, MO and POT with the complete 126-message set and added automated checks for catalog integrity.
* No changes to task/category tables, permissions, public content or Core dependency.

= 0.1.2 =
* Added in-plugin User Manual and Technical Manual in ES, EN, FR and DE.
* Added edit/rename to the Tasks Categories screen, preserving IDs and task associations, with validation and error handling.
* Expanded automated coverage of category updates, duplicate names, manual rendering and both menu configurations.

= 0.1.1 =
* Display Tasks under the TaxoCommerce Core menu and register it as a family extension when Core is active.
* Preserve standalone navigation when Core is absent; no schema or task data changes.

= 0.1.0 =
* Initial private agenda: task and category CRUD, monthly calendar, indicators and read-only Autocheck JSON.
* Fully standalone storage; no public content or store integrations.
