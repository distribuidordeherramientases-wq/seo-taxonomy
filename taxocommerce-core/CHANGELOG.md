# CHANGELOG — TaxoCommerce Core - Catalog & Content Management for WooCommerce

> Historial de las versiones del plugin **independiente**. Las funciones anteriores a su separación están documentadas en [`changelog.txt`](changelog.txt).
> Fuente inicial: entradas verificadas del `readme.txt` en GitHub. Solo se atribuye una fecha si la fuente la acredita.

## [Unreleased]

- Gestor Menús WordPress: edición explícita de las entradas `wp_navigation` referenciadas desde plantillas de cabecera de temas de bloques. Guarda los atributos de los enlaces sin alterar bloques no compatibles, comprueba nonce/permisos y rechaza cambios concurrentes. Los menús clásicos siguen siendo independientes; se evita afirmar que su guardado actualiza la cabecera. Menús embebidos y situaciones ambiguas se derivan al Editor del sitio. Issue #2060.

- Gestor de menús WordPress: aviso explícito en temas de bloques cuando el menú clásico editado no es necesariamente el que se muestra en la cabecera, con acceso al Editor del sitio. En temas clásicos informa de las ubicaciones asignadas al menú seleccionado. Sin modificación automática de menús o navegación publicada. Issue #2058.

## [0.4.43]

- Botones visibles «Guardar menú» (superior/inferior) en la pestaña Menús WordPress: guardan de forma explícita nombre, títulos, URLs, orden y jerarquía; aviso de cambios pendientes y mensaje de resultado.
- Nuevo «Eliminar menú» para un menú nativo seleccionado, con casilla y diálogo de confirmación. Protege todos los menús SEO por país y menús con elementos automáticos; informa si está asignado al tema. La eliminación usa la API nativa WordPress.
- Validación previa de todos los elementos, pertenencia y ausencia de ciclos, nonces y capacidades, sin guardar al abrir un menú. Se conserva el comportamiento anterior de «Menús SEO / WooCommerce», y los botones de añadir/quitar elementos siguen guardando inmediatamente.
- Pruebas de regresión, manuales actualizados, Issue #2056. No se toca el monolito ni se publica en producción.


## [0.4.42]

- Nueva pestaña Menús WordPress para editar menús clásicos con entradas, páginas, categorías y enlaces, sin WooCommerce ni restricciones de país.
- Separada de Menús SEO / WooCommerce. Cargar y seleccionar no modifica menús, ubicación del tema ni datos; las escrituras usan nonce y capacidades y requieren menú explícito.
- Conservadas la jerarquía SEO, los menús por país y las API existentes. Manual de usuario/técnico y pruebas sin WooCommerce. Issue #2051; sin publicar en producción.


## [0.4.41]

- Añadido selector de menús nativos de WordPress con indicación de ubicaciones del tema: permite **cargar y editar el menú actual**, sin sustituir el menú generado para cada país ni alterar el front-end al cargarlo.
- Permite crear un menú WordPress nuevo y editar sus elementos manuales desde Core: título, URL de enlaces personalizados, estructura padre/hijo y posición. Se mantienen protegidos los elementos generados por la jerarquía SEO.
- Las acciones de escritura usan permisos y nonce, y rechazan elementos externos al menú, padres de otro menú, bucles de jerarquía e identificadores de menú inválidos.
- Se preservan las funciones existentes de sincronización SEO por país, enlace al editor nativo, activación del menú en una ubicación y restauración de ubicaciones; sin alterar el monolítico ni producción.
- Añadidas pruebas aisladas del comportamiento de carga, edición, creación e independencia de menús. Issue #2047.


- Eliminados los dos scripts de pruebas no ejecutados por CI (`anomalies-smoke.php` y `family-menu-smoke.php`); las otras 11 pruebas permanecen fuera del ZIP y se ejecutan con los workflows. Las comprobaciones automáticas y los archivos ocultos quedan excluidos del paquete instalable (PR #1935).

- Limpieza controlada de STAGING (issue #1937): eliminar exclusivamente restos de pruebas de desarrollo bajo `tests/` y el antiguo `.ftp-deploy-sync-state.json` del plugin instalado. Inventario, copia de seguridad SHA-256 y verificación remota obligatorios. Se conservan las clases operativas de `includes/`, los datos y las pruebas del repositorio; sin intervención en PRODUCCIÓN.

- Auditoría de calidad unificada de los 20 módulos independientes: Plugin Check automático tras cada integración en STAGING, informes por módulo y resumen consolidado con errores, advertencias y archivos de diagnóstico; el monolítico permanece excluido (issue #1921).
- El comprobador de CI analiza paquetes distribuibles y no sustituye las comprobaciones de la instalación WordPress en PRO; ningún despliegue a PRO se ejecuta desde este control.
- Endurecido el despliegue de la familia modular: las pruebas de desarrollo y el estado FTP se mantienen fuera de las instalaciones WordPress.
- Preparada una limpieza FTPS excepcional, con inventario, copia de seguridad y verificación de hashes, limitada a `taxocommerce-*/tests/` y al archivo oculto de sincronización. El monolítico `seo-taxonomy` queda expresamente excluido.
- Seguimiento de la intervención y sus comprobaciones en el issue #1918. No se modifica el código operativo ni se incrementa la versión de Core por un ajuste de infraestructura.

## [0.4.40]

- Internacionalización nativa del administrador: menús, pestañas, botones, descripciones de módulos, editor, parte de la jerarquía y mensajes. Se utiliza el locale de WordPress y el dominio `taxocommerce-core`; no hay selector propio.
- Catálogo POT y ficheros PO/MO de `es_ES`, `en_US`, `fr_FR`, `de_DE` y `nl_NL`. Español priorizado para STAGING; traducciones de textos largos a otros idiomas todavía parciales (issue #2025).
- Conservados identificadores, contratos técnicos, relaciones SEO, contenidos, acciones, y estructura de datos. No afecta al monolítico ni a los plugins independientes de la familia.
- Añadida validación automática de carga de traducciones compiladas antes de desplegar.

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
