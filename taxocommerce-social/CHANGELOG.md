# CHANGELOG — TaxoCommerce Social

> Historial maestro de versiones del plugin independiente. El origen monolítico se documenta en [`changelog.txt`](changelog.txt).

## [Unreleased]

Sin novedades pendientes documentadas.

## [0.1.1]

* Añadidas las pestañas Auto Test y Manual dentro de Social; conservadas Conexiones, Plantillas y Calendario.
* Auto Test de 16 pruebas locales, 15 seguras y una omitida por requerir operaciones reales, que verifica servicios, cinco proveedores, callbacks, configuración, dos tablas, lecturas acotadas, plantillas, agenda, WP-Cron, CSV, protección de secretos y contratos de publicación; sin HTTP, publicaciones ni cambios de agenda.
* Dos informes por administrador en transitorios durante 30 días, con fecha, versión, tiempos y comparación de regresiones.
* Manual integrado de usuario y manual técnico: autorización de redes, uso de plantillas, agenda, CSV, tablas canónicas, WP-Cron, opciones, seguridad y límites.
* Ampliadas pruebas de regresión PHP 7.4 y 8.4 y empaquetado sin archivos de test ni estado FTP.
* Sin nuevas tablas, modificaciones a conexiones, migraciones de datos ni cambios automáticos en publicaciones.


## [0.1.0]

* Extracted social connections, templates and calendar into a standalone plugin.
* Preserved existing settings and history without deleting them on deactivation.

## Fuentes

- [`changelog.txt`](changelog.txt), etapa monolítica.
- [`docs/PLUGIN-HISTORY-SOURCES.md`](../docs/PLUGIN-HISTORY-SOURCES.md), PR y referencias históricas.

## Actualizaciones

Para nuevas funcionalidades se edita este `CHANGELOG.md`. Antes de publicar, se debe reflejar el resumen de la versión en `readme.txt`, que es el único Changelog mostrado automáticamente por WordPress.org.
