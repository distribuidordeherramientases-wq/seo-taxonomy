# CHANGELOG — TaxoCommerce Social

> Historial maestro de versiones del plugin independiente. El origen monolítico se documenta en [`changelog.txt`](changelog.txt).

## [Unreleased]

Sin novedades pendientes documentadas.

## [0.1.3]

- Corregida la parametrización SQL de consultas a publicaciones y tareas programadas, incluidos identificadores `%i` y límites `%d` (WordPress 6.7+).
- Confirmadas las lecturas GET de navegación y calendario sin operaciones de escritura; las credenciales POST siguen pasando por la validación específica de cada proveedor y el nonce administrativo.
- Documentadas de manera local las consultas SQL directas necesarias para contadores de clics, publicaciones, estados de WP-Cron, informes y exportaciones en tiempo real, sin añadir caché que altere el funcionamiento.
- Conservada la compatibilidad de la API pública de Core y actualizados los metadatos/readme para distinguir la versión corregida. Issue #1551.


## [0.1.2]

* Reorganizado el Manual en dos guías claramente independientes con H1, índice navegable y H2/H3: manual de usuario y manual técnico para futuros desarrolladores, siguiendo el patrón utilizado por TaxoCommerce Database.
* El manual de usuario incluye acceso, cinco conexiones, pasos de autorización, plantillas, programación, estados de tarea, calendario, limitaciones CSV, errores habituales y Auto Test.
* El manual técnico documenta mapa de archivos, clases y funciones, esquema y opciones persistentes, callbacks de proveedores, endpoints/nonce, hooks, WP-Cron, seguimiento, contratos CSV, pruebas offline, seguridad de tokens y procedimiento Issue/PR/CI/STAGING.
* Corregida la documentación heredada de CSV: existen APIs de backend, pero el calendario del plugin independiente no presenta actualmente controles CSV; no se han añadido funcionalidades.
* Sin cambios en conexiones, datos, comportamiento de publicaciones ni Auto Test; actualizadas pruebas de estructura documental.

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
