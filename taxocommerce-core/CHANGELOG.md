# CHANGELOG — TaxoCommerce Core - Catalog & Content Management for WooCommerce

> Historial de las versiones del plugin **independiente**. Las funciones anteriores a su separación están documentadas en [`changelog.txt`](changelog.txt).
> Fuente inicial: entradas verificadas del `readme.txt` en GitHub. Solo se atribuye una fecha si la fuente la acredita.

## [Unreleased]

- Limpieza controlada de STAGING (issue #1937): eliminar exclusivamente restos de pruebas de desarrollo bajo `tests/` y el antiguo `.ftp-deploy-sync-state.json` del plugin instalado. Inventario, copia de seguridad SHA-256 y verificación remota obligatorios. Se conservan las clases operativas de `includes/`, los datos y las pruebas del repositorio; sin intervención en PRODUCCIÓN.

- Auditoría de calidad unificada de los 20 módulos independientes: Plugin Check automático tras cada integración en STAGING, informes por módulo y resumen consolidado con errores, advertencias y archivos de diagnóstico; el monolítico permanece excluido (issue #1921).
- El comprobador de CI analiza paquetes distribuibles y no sustituye las comprobaciones de la instalación WordPress en PRO; ningún despliegue a PRO se ejecuta desde este control.
- Endurecido el despliegue de la familia modular: las pruebas de desarrollo y el estado FTP se mantienen fuera de las instalaciones WordPress.
- Preparada una limpieza FTPS excepcional, con inventario, copia de seguridad y verificación de hashes, limitada a `taxocommerce-*/tests/` y al archivo oculto de sincronización. El monolítico `seo-taxonomy` queda expresamente excluido.
- Seguimiento de la intervención y sus comprobaciones en el issue #1918. No se modifica el código operativo ni se incrementa la versión de Core por un ajuste de infraestructura.

## [0.4.39]

- Package-only Plugin Check remediation: distribute without development tests or hidden FTP state files.
- Retain full regression tests in CI and keep Core menus, international scopes, SEO architecture and data unchanged.



## [0.4.38]

* Align the Logistics extension catalog link and family submenu label with the standalone module's unique admin page.
* Preserve the legacy TaxoCommerce SEO order view and all shipping settings without moving data.

## [0.4.37]

* Move Core Auto Test into the Core Overview / User Guide / Auto Test internal tab bar rather than a WordPress sidebar submenu.
* Retain the 17 read-only checks, user transient, nonces, dashboard navigation and old URL compatibility via a safe redirect.
* No new tables or modifications to tests, catalog, SEO hierarchy, menus or extension management.

## [0.4.36]

* Add Technical Manual alongside User Manual in the existing User Guide screen.
* Describe Core architecture, hooks, data, countries, permissions, caching, deployment and diagnostics using semantic headings and responsive tables.
* Preserve the original user manual and all operational Core screens.

## [0.4.35]

* Add a User Guide tab with semantic H1/H2/H3 headings, table of contents, direct links and responsive presentation.
* Document Content, SEO Hierarchy, country menus, Auto Test and optional extensions without changing existing operations.

## [0.4.34]

* Add a native Auto Test screen and dashboard shortcut for administrator-triggered, read-only functional checks.
* Report individual pass/fail/skipped results with error details, timings and version, isolating failures between checks.
* Cover runtime, WP/WooCommerce content reads, country metadata, SEO roles and relation samples, country menus, modules, vocabulary and architecture summary. Never create, delete or sync real content during tests.
* Keep the last test result in a user-specific transient for one day; writing UAT remains explicitly outside the production-safe suite.

## [0.4.33]

* Ensure each country creates, saves and edits a dedicated native WordPress menu with a distinct country-specific name.
* Prevent stale country options from reusing and overwriting another market's menu ID; restore the correct market association by native menu name.
* Rename legacy Spanish TaxoCommerce menu in place when synchronised, preserving its menu ID and content.
* Show the selected native menu name in the editor and add regression coverage for cross-country menu ID collisions.

## [0.4.32]

* Fix existing SEO hierarchy tables that missed the country_code migration: both seo_nodes and seo_relations are checked, without deleting or replacing existing records.
* Persist page country_code when saving or reusing a role node; synchronize active role country when page country metadata changes.
* Keep legacy relations and Spanish hierarchy intact. Relation country is also independently checked when both SEO tables already exist.
* Add isolated QA for the pre-existing-table migration and country-aware node saves.

## [0.4.31]

* Adds a market-country selector to Core content editors for posts, pages, products and WooCommerce categories, preserving existing country metadata.
* Adds optional country_code to seo_relations and country-filtered hierarchy editing without overwriting legacy links.
* Provides independent managed menus per country and a front-end selector for TaxoCommerce-managed theme menu locations.
* The original Spanish menu and relationships remain valid; international categories are never blanket-labelled Spanish.

## [0.4.30]

* Move Extensions from the Core submenu into responsive cards at the bottom of the Core home screen.
* Show live installed/active/ZIP states, version, descriptions and guarded management actions per extension.
* Remove redundant migration descriptions while retaining old bookmarks via a redirect.

## [0.4.29]

* Added Core > Content > Inventory summary with read-only totals, 15-minute cache and an administrator-initiated protected refresh.
* Counted canonical WordPress/WooCommerce records without invoking Auditor scans.
* Refresh WordPress.org readiness against the current modular Core implementation.
* Preserve native content, hierarchy, menu and extension-management contracts while validating the standalone package.

## [0.4.28]

* Consolidated external connections under TaxoCommerce Insights.
* Retained independent extension discovery and administrator-initiated installation.

## [0.4.27]

* Clarified Supplier and Logistics responsibilities.

## [0.4.26]

* Registered Suppliers in the extension catalogue.

## [0.4.24]

* Registered Logistics and Billing modules.

## [0.4.12]

* Added FAQ content screens and removed an unused technical log page.

## [0.4.10]

* Added administrator-controlled process management.

## [0.4.9]

* Added editing guidance for all four content types.

## [0.4.8]

* Consolidated extension management into one screen.

## [0.4.0]

* Added native WordPress menu management for the SEO hierarchy.

## [0.3.0]

* Introduced the native cluster and hub hierarchy.

## Referencias

- [Origen monolítico y transición](changelog.txt)
- [Índice de evidencias de los 20 plugins](../docs/PLUGIN-HISTORY-SOURCES.md)

## Mantenimiento

Este es el historial maestro que se actualiza al introducir novedades. Antes de publicar cada versión en WordPress.org, su resumen debe trasladarse al apartado `== Changelog ==` de `readme.txt`; WordPress.org no presenta este Markdown automáticamente.
