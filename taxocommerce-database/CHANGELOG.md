# CHANGELOG — TaxoCommerce Database

> Historial maestro de versiones del plugin independiente. El origen monolítico se documenta en [`changelog.txt`](changelog.txt).

## [Unreleased]

- Inicio de internacionalización WordPress mediante gettext (`Domain Path: /languages`), catálogos por idioma y traducción de etiquetas del menú, sin cambios funcionales. Seguimiento: #2037 (plan global #2036). Cobertura restante de interfaz, Autotest y manuales pendiente de revisión por idioma.


- Limpieza controlada de STAGING (issue #1937): eliminar exclusivamente restos de pruebas de desarrollo bajo `tests/` y el antiguo `.ftp-deploy-sync-state.json` del plugin instalado. Inventario, copia de seguridad SHA-256 y verificación remota obligatorios. Se conservan las clases operativas de `includes/`, los datos y las pruebas del repositorio; sin intervención en PRODUCCIÓN.

Sin novedades pendientes documentadas.


## [0.4.5]

* Fixed Auto Test false positives: an empty complete backup is a failure; absent SEO tables are reported as not executed instead of success.
* Improved the 17-cleanup-action regression contract and documented the Auto Test in the user/developer manuals.
* No changes to cleanup, repair, export engines or SQL operations.

## [0.4.4]

* Use prepared SQL identifier placeholders for critical Database queries and fix the dynamic-role prepared statement.
* Verify nonce in the object-reset action handler; keep all original cleanups, exports and Auto Test.

## [0.4.3]

* Added read-only Database Auto Test: 17 independent checks of original services, MySQL connectivity, SEO schemas, read-only sampling, export availability, hooks, and developer manual.
* Per-user last two reports (30-day expiration), showing changes between before/after deployments. No destructive SQL or real export downloads.

## [0.4.2]

* Add a seventh Manual tab with complete user and developer documentation, readable in WordPress admin.
* Document all existing buttons and cleanup actions, file architecture, hooks, SQL data model, limitations and extension testing.
* Show the six original screens in one horizontal responsive tab bar, preserving all existing routes and actions.
* Restored the two original database services and removed duplicated or non-original tools.
* Kept the original diagnostics, repairs, cleanup actions and data exports.
* Excluded the cross-plugin Data Layer and historical rollback support.

## [0.4.1]

* Organise the six restored database panels into one navigation bar.

## [0.4.0]

* Restore Clean Database and SEO Data Table from the monolith.

## [0.3.0]

* Restore the original three Clean Database panels and legacy maintenance checks.

## [0.2.0]

* Introduce standalone database diagnostics and optional maintenance controls.

## Fuentes

- [`changelog.txt`](changelog.txt), etapa monolítica.
- [`docs/PLUGIN-HISTORY-SOURCES.md`](../docs/PLUGIN-HISTORY-SOURCES.md), PR y referencias históricas.

## Actualizaciones

Para nuevas funcionalidades se edita este `CHANGELOG.md`. Antes de publicar, se debe reflejar el resumen de la versión en `readme.txt`, que es el único Changelog mostrado automáticamente por WordPress.org.
