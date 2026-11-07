=== TaxoCommerce Tasks ===
Contributors: davidperezmartorell
Tags: tasks, calendar, productivity, dashboard, admin
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
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

Tasks and task categories live exclusively in two custom database tables using the site's WordPress prefix. No WordPress posts, pages, WooCommerce products or public endpoints are created. No external services or data transfers are used. This plugin does not require WooCommerce, TaxoCommerce Core or any other TaxoCommerce plugin.

Administrative labels have built-in Spanish, English, French and German fallbacks. WordPress language packs take precedence. Task titles, descriptions and category names are entered by administrators and are not automatically translated.

== Installation ==

1. Upload the `taxocommerce-tasks` directory into `wp-content/plugins`, or install the ZIP in WordPress.
2. Activate TaxoCommerce Tasks. Two private SQL tables are created with the site's own table prefix.
3. Open Tasks in the WordPress administrator and add categories and tasks.
4. Use Calendar, Dashboard and Autocheck tabs for planning and validation.

Only users with the `manage_options` capability can view or modify the agenda. Uninstalling the plugin does not delete task records.

== Frequently Asked Questions ==

= Does it publish tasks on my website? =

No. Tasks are not registered as post types and no public REST, shortcode or frontend view exists.

= Does it share categories with WooCommerce? =

No. Categories are private to this plugin and are held in a dedicated SQL table.

= Does it depend on TaxoCommerce Core? =

No. It is standalone.

== Changelog ==

= 0.1.0 =
* Initial private agenda: task and category CRUD, monthly calendar, indicators and read-only Autocheck JSON.
* Fully standalone storage; no public content or store integrations.
