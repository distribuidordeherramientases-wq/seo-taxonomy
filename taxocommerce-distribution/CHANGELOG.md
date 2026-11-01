# CHANGELOG — TaxoCommerce Distribution - Feeds & Sitemaps for WooCommerce

> Historial maestro de versiones del plugin independiente. El origen monolítico se documenta en [`changelog.txt`](changelog.txt).

## [Unreleased]

- Revisados los 24 avisos de Plugin Check: mensajes de estado sin mutaciones, sanitización de avisos de sitemap y justificación localizada de consultas SQL de inventario y metadatos para evitar caché obsoleta.
- Seguimiento: #1545. Validación de Plugin Check y STAGING pendiente.

## [0.1.2]

- Reorganizada la pestaña Manual en **dos documentos completos y diferenciados**: Manual de usuario y Manual técnico para futuros desarrolladores, con índice accesible, secciones independientes y diseño adaptable.
- Ampliado el manual de usuario con secuencia operativa segura; ampliado el técnico con mapa de archivos y funciones, ciclos de trabajo, almacenamiento, opciones, hooks, coexistencia con el monolito, seguridad, pruebas y procedimiento de mantenimiento.
- Documentación contrastada con el código de Distribution. No se modifica el motor de feeds, sitemaps, Autocheck ni ningún archivo del monolito. Seguimiento: #1978.


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
