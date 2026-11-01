# CHANGELOG — TaxoCommerce Vocabulary

> Historial maestro de versiones del plugin independiente. El origen monolítico se documenta en [`changelog.txt`](changelog.txt).

## [0.3.6]

## [Unreleased]

* Lectura CSV y JSON portable: comprobación local del nonce y validación del estado y la ruta temporal de la subida PHP; no se modifica el contenido del archivo ni los modos MERGE/MIRROR.
* Documentados de manera localizada los filtros GET de solo lectura (no requieren nonce) y las consultas por categoría, ya limitadas a 50 y 500 registros.
* Justificadas de forma localizada por método las excepciones WPCS de acceso SQL directo y falta de caché en tablas canónicas compartidas, cuyas escrituras externas no tienen invalidación coordinada. No se cambian las consultas ni su semántica; no se deshabilitan los chequeos globales.

## [0.3.5]

* Se preparan los identificadores de tablas de las consultas SQL de administrador, Clasificador, importación/exportación CSV y JSON portable usando `%i`, manteniendo el esquema canónico y la semántica de las consultas.
* Se sanean los filtros de navegación y análisis y se validan los metadatos del CSV (estado de subida, ruta temporal creada por PHP, tamaño máximo y tipo).
* Se preservan las pestañas Manual y Auto Test, el importador JSON portable y las acciones comerciales actuales.
* Las correcciones que ya estaban presentes en STAGING (licencia, versiones y lectura de CSV mediante `WP_Filesystem`) no se duplican; el archivo oculto FTP existente en PRO requiere limpieza independiente autorizada.

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
