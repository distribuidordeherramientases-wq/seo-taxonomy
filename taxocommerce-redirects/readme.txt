=== TaxoCommerce Redirects - SEO Redirect Manager ===
Contributors: davidperezmartorell
Tags: redirects, redirection, seo, woocommerce, delete
Requires at least: 6.5
Requires PHP: 7.4
Stable tag: 0.1.4
License: GPLv2 or later

Independent WordPress/WooCommerce 301/302 manager with assisted deletion.

== Standalone or combined with Core ==

* WordPress-only: posts and pages.
* When WooCommerce exists: native products and product_cat categories.
* When TaxoCommerce Core exists: four additional delete buttons are inserted
  into Core product, category, post and page editors/lists without modifying Core.
* No requires-plugin declaration, external APIs, modifications to WordPress core,
  theme functions.php, .htaccess or Nginx/Apache configuration.

== Existing legacy data and functions ==

* Reuses the existing prefix + seo_redirects table; creates it only if missing.
* When legacy SEO plugin is active, disables only the old send_headers callback
  for this request, to prevent two runtime engines acting on the same rule.
* Honors stored 301 AND 302; resolves internal relative destinations correctly.
* Old admin can still inspect shared rows. Deactivation and updates never delete rules.
* Removing the plugin from WordPress deletes the shared seo_redirects table and its rules. Export before uninstalling.
* Manager has add/edit/delete, live-use hits, most-recent hit, search, pagination,
  warnings on cycles/duplicates/chains, target link and rule test link.
* Compatible JSON backup/import (redirects array), analysis export, optional
  historical hit counts and 25 MB / 50000 input row cap. Existing rules are not
  replaced during import.

== Unified assisted-delete workflow ==

1. Access from WordPress classic editor, Gutenberg More menu, native lists,
   WooCommerce taxonomy editor, or optional Core editors and lists.
2. Review title and original URL.
3. Choose a 301 to a chosen valid same-site URL OR deletion without redirect.
   Cancel is always available.
4. Server rechecks specific WordPress capabilities, nonce, original permalink,
   collisions and cycles. A new 301 is stored BEFORE deletion. If a deletion
   fails, the new rule is removed.
5. Posts, pages and products move to WordPress Trash. A permanent delete from
   Trash is only possible with extra explicit irreversible-deletion confirmation.
6. Product category deletes are permanent and require no products, no children,
   no native Core Hub link, and must not be WooCommerce's default category.

== Deliberate coexistence limitation ==

While the old TaxoCommerce SEO category pre_delete_term guard is installed,
this new plugin BLOCKS product category deletion. The old monolith mandates a
301 to the unique secondary Hub and may modify the shared table after a delete.
Bypassing that guard could create dangling SEO relationships or unexpected
deletions. The new button/form remains visible and explains the limitation.
Use the old manager for that operation until the old deletion service is
retired and all native relationships have been verified.

== Explicit exclusions ==

* Bulk deletion intentionally remains the native WordPress action; redirect
  confirmation is per object. Bulk approvals are a separate future requirement.
* Deletions by third-party REST API clients are not intercepted. Gutenberg
  edits use an API-fetch guard and show the same confirmation link.
* Redirect targets must be within the current site. Existing external redirects
  are flagged in diagnostics and are not silently executed.
* Reports are structural, not remote HTTP availability tests and not Google/Bing
  quality measurements. A green rule can still point to a missing URL.
* Old monolith will regain control if this plugin is deactivated.
* Deploying by FTPS makes the plugin available in WordPress Plugins; activation
  requires separate authenticated wp-admin or WP-CLI access.
* This code is only for STAGING until manually approved for production release.

== Changelog ==

= 0.1.4 =
* Retirada la integracion opcional del motor historico de rollback de Database. CRUD de redirecciones vuelve al flujo autonomo original, sin dependencia ni comprobaciones de Database.
* Conservado el boton Abrir y la navegacion por Core cuando esta instalado.

= 0.1.3 =
* Version experimental retirada de la integracion de rollback.

= 0.1.2 =
* Integracion de menu y accion Abrir en el catalogo Core.


= 0.1.1 =
* Verified activation lifecycle and explicit uninstall cleanup for seo_redirects.

= 0.1.0 =
* Independent runtime, administration, import/export and 4+4 assisted deletion.
