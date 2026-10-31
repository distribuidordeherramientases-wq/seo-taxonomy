# CHANGELOG — TaxoCommerce Distribution - Feeds & Sitemaps for WooCommerce

> Historial maestro de versiones del plugin independiente. El origen monolítico se documenta en [`changelog.txt`](changelog.txt).

## [Unreleased]

Sin novedades pendientes documentadas.

## [0.1.1]

- Añadida pestaña **Manual**, con guía de usuario y documentación técnica de los feeds comerciales, sitemaps, automatización, rutas públicas, coexistencia y resolución de incidencias.
- Añadida pestaña **Autocheck** con ejecución manual, protección de permisos/nonce y controles agrupados de motor, canales, eventos registrados, opciones de solo lectura y metadatos de los archivos publicados.
- El Autocheck no llama a inicializadores de almacenamiento que podrían crear ficheros, no genera feeds ni XML, no programa workers y no realiza peticiones externas ni escrituras.
- Incorporada prueba aislada sin WordPress real para detectar efectos secundarios y verificar ambas pestañas; sincronizada la documentación WordPress.org. Issue #1978.


## [0.1.0]

* Initial modular migration of commercial feeds and sitemap generation.
* Added independent bounded feed worker.
* Added safe legacy coexistence and settings migration.
* Added Distribution admin tabs for overview, feeds, sitemaps and automation.

## Fuentes

- [`changelog.txt`](changelog.txt), etapa monolítica.
- [`docs/PLUGIN-HISTORY-SOURCES.md`](../docs/PLUGIN-HISTORY-SOURCES.md), PR y referencias históricas.

## Actualizaciones

Para nuevas funcionalidades se edita este `CHANGELOG.md`. Antes de publicar, se debe reflejar el resumen de la versión en `readme.txt`, que es el único Changelog mostrado automáticamente por WordPress.org.
