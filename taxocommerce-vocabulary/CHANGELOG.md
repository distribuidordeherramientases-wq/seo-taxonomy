# CHANGELOG — TaxoCommerce Vocabulary

> Historial maestro de versiones del plugin independiente. El origen monolítico se documenta en [`changelog.txt`](changelog.txt).

## [Unreleased]

Sin novedades pendientes documentadas.

## [0.3.4]

* Añadidas las pestañas Auto Test y Manual dentro de Vocabulary, sin alterar Etiquetas, Atributos, Clasificador, Importar / Exportar ni Control.
* Auto Test de 16 comprobaciones agrupadas: arranque, rutas, WooCommerce, MySQL, esquema canónico, columnas, lecturas acotadas, clasificador, intercambio portable y documentación; las escrituras y descargas reales se omiten explícitamente.
* Comparación de las dos últimas ejecuciones por usuario mediante transitorios de 30 días, indicando versión y regresiones.
* Manual integrado con guía de usuario y guía técnica: pantallas, grupos, datos canónicos, importación CSV, JSON MERGE/MIRROR, nonces, roles, límites y compatibilidad.
* Pruebas automatizadas y exclusión de los scripts de pruebas del ZIP distribuible y del paquete de STAGING; artefacto de despliegue con nombre estable en vez del identificador obsoleto 0.3.1.


## [0.3.3]

* Replace direct PHP filesystem access in CSV import/export with WordPress-compatible handling.
* Render import forms directly with escaped values instead of concatenating raw form markup.

## [0.3.2]

* Portable semantic backups, object identity resolution and import simulation.
* MERGE/MIRROR assignment controls and fingerprinted exports.

## [0.3.0]

* Canonical storage reuse, independent classifier and CSV master exchange.

## Fuentes

- [`changelog.txt`](changelog.txt), etapa monolítica.
- [`docs/PLUGIN-HISTORY-SOURCES.md`](../docs/PLUGIN-HISTORY-SOURCES.md), PR y referencias históricas.

## Actualizaciones

Para nuevas funcionalidades se edita este `CHANGELOG.md`. Antes de publicar, se debe reflejar el resumen de la versión en `readme.txt`, que es el único Changelog mostrado automáticamente por WordPress.org.
