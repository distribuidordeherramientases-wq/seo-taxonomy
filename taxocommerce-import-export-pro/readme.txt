=== TaxoCommerce Import-Export Pro ===
Contributors: davidperez
Tags: import, export, csv, woocommerce, batch
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Independent CSV batch import and export for WordPress and WooCommerce.

== Description ==

TaxoCommerce Import-Export Pro includes its own native CSV engine derived from Lite. Lite, Core and the original Import-Export plugin are not required. Each direction has separate blocks for pages, products, WordPress categories, posts, product categories, post tags and product tags.

= Features =
* One or multiple CSVs per fixed content block; explicit batch upload and selected-file summary, up to 20 per submission in natural filename order.
* Automatic field recognition within the chosen fixed content type, create/update by native identity, read-only preflight of the entire queue and explicit write confirmation.
* Public scalar custom fields, compatible WooCommerce attributes, virtual/downloadable products, optional existing taxonomy and image relationships.
* Resumable, bounded exports of the complete compatible schema of each content block, without field selection, with private output and authorized download.
* Five adaptive workload profiles, local PHP CPU/memory/time measurements, one site-wide worker lock, pause/resume and explicit recovery after an interrupted chunk.
* Browser-assisted execution and native WP-Cron continuation when the host supports it.
* Last five archived job summaries per administrator, direction and content type; per-file status and row errors.
* Own header, release history, functionality check, static code check with JSON report, user and technical manuals.

= Limits =
30 MB and 25,000 records per input file; 100 MB combined upload and export output. PHP and hosting limits may be lower. An owner-only private temporary directory is required; PHP uploads are copied as private data without Media upload filters. Maximum 1024 columns and 128 KiB of CSV header. Jobs are not transactional backups; changes already saved are not rolled back by archiving. Related terms and images must already exist in the destination. The first Pro edition does not migrate child variations, orders, customers, binary Media, protected/serialized metadata or legacy proprietary CSV schemas. The original plugin is preserved for its existing advanced workflows.

== Installation ==

1. Install and activate WooCommerce.
2. Download the independent Pro ZIP from its Core extension card when available, then use the native WordPress ZIP upload and activate it. Core is not required for manual installation.
3. Open Import-Export Pro under TaxoCommerce Core when Core is active, or from its own WordPress admin sidebar entry otherwise.
4. Use a small CSV and review preflight before confirming a large import.

== Frequently Asked Questions ==

= Does Pro require Lite or Core? =
No. Pro includes its own engine and isolated settings, jobs, actions and header. Core integration is optional: the active edition registers its own extension card and submenu when Core is present.

= Can I close my browser? =
WP-Cron may continue active jobs if the host invokes it and private temporary files remain available. Without working cron, keep the browser open or return to the job to continue.

= Does the CPU control set a server-wide CPU percentage? =
No. It adapts row batches and pacing to the PHP worker's CPU, memory and time.

= Does Code Check replace official Plugin Check? =
No. It performs bounded local static checks and reports findings. Official Plugin Check and real functional testing remain necessary.

== Changelog ==

= 0.1.2 =
* Explicit batch import controls and selected-file summary. Optional Core catalogue and submenu integration without a required dependency.
* Distribution: verified local ZIP available through Core's package repository on authorized test sites.

= 0.1.1 =
* Export all compatible fields per block without selection; private multi-file CSV upload without Media filters and clearer per-file errors.

= 0.1.0 =
* Independent Pro edition with fixed content blocks, multi-file import, resumable export, workload control, history, code check and manuals.
