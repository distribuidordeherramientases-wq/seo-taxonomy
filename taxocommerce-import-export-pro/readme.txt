=== TaxoCommerce Import-Export Pro ===
Contributors: davidperez
Tags: import, export, csv, woocommerce, batch
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Independent CSV batch import and export for WordPress and WooCommerce.

== Description ==

TaxoCommerce Import-Export Pro includes its own native CSV engine derived from Lite 0.1.5. Lite, Core and the original Import-Export plugin are not required. Each direction has separate blocks for pages, products, WordPress categories, posts, product categories, post tags and product tags.

= Features =
* One or multiple CSVs per fixed content block; up to 20 per submission in natural filename order.
* Automatic header recognition, create/update by native identity, read-only preflight of the entire queue and explicit write confirmation.
* Public scalar custom fields, compatible WooCommerce attributes, virtual/downloadable products, optional existing taxonomy and image relationships.
* Resumable, bounded exports with optional field selection, private output and authorized download.
* Five adaptive workload profiles, local PHP CPU/memory/time measurements, one site-wide worker lock, pause/resume and explicit recovery after an interrupted chunk.
* Browser-assisted execution and native WP-Cron continuation when the host supports it.
* Last five archived job summaries per administrator, direction and content type; per-file status and row errors.
* Own header, release history, functionality check, static code check with JSON report, user and technical manuals.

= Limits =
30 MB and 25,000 records per input file; 100 MB combined upload and export output. PHP and hosting limits may be lower. An owner-only private system temporary directory is required. Jobs are not transactional backups; changes already saved are not rolled back by archiving. Related terms and images must already exist in the destination. The first Pro edition does not migrate child variations, orders, customers, binary Media, protected/serialized metadata or legacy proprietary CSV schemas. The original plugin is preserved for its existing advanced workflows.

== Installation ==

1. Install and activate WooCommerce.
2. Upload and activate the independent Pro ZIP in an authorized test installation.
3. Open Import-Export Pro in the WordPress admin sidebar.
4. Use a small CSV and review preflight before confirming a large import.

== Frequently Asked Questions ==

= Does Pro require Lite or Core? =
No. Pro includes its own engine and isolated settings, jobs, actions, menu and header.

= Can I close my browser? =
WP-Cron may continue active jobs if the host invokes it and private temporary files remain available. Without working cron, keep the browser open or return to the job to continue.

= Does the CPU control set a server-wide CPU percentage? =
No. It adapts row batches and pacing to the PHP worker's CPU, memory and time.

= Does Code Check replace official Plugin Check? =
No. It performs bounded local static checks and reports findings. Official Plugin Check and real functional testing remain necessary.

== Changelog ==

= 0.1.0 =
* Independent Pro edition with fixed content blocks, multi-file import, resumable export, workload control, history, code check and manuals.
