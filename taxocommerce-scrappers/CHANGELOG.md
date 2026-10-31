# CHANGELOG — TaxoCommerce Scrappers

> Historial maestro de versiones del plugin independiente. El origen monolítico se documenta en [`changelog.txt`](changelog.txt).

## [Unreleased]

Sin novedades pendientes documentadas.

## [0.1.2]

- Incorporada una pestaña Manual con guía de usuario y referencia técnica de Connections, Ojeador e Ingeniero, incluidos permisos, tablas, hooks, configuración, prevención de costes y diagnóstico.
- Añadido Autotest manual y no destructivo por grupos: componentes, tablas, configuración local de proveedores y registro de controladores. No consulta APIs externas ni inicia procesos, no modifica la base de datos y nunca expone claves.
- Pruebas de regresión sin conexión, incluyendo protección de credenciales y comportamiento de estado. Los scripts de desarrollo permanecen excluidos del ZIP distribuible.
- Corregidos cinco usos del dominio de traducción heredado `taxocommerce-seo` en Ojeador e Ingeniero, sustituidos por `taxocommerce-scrappers` para superar el control de internacionalización del plugin independiente.
- Parametrizados los identificadores SQL de las tablas de vocabulario mediante `%i` en la consulta de asignación de categorías, conservando los filtros y resultados. Seguimiento de Plugin Check: #1926. Manual y Autotest: #1964.


## [0.1.1]

- Corregido el fallo fatal en la activación independiente de Scrappers al no estar definida la interfaz del proceso Ingeniero. Contrato compatible y protegido frente a redeclaración cuando coexiste con el monolítico.
- Se conserva el supervisor existente, sin duplicar procesos ni modificar el monolítico. Issue #1926.

## [0.1.1]

- Corregido el fallo fatal en la activación independiente de Scrappers al no estar definida la interfaz del proceso Ingeniero. Contrato compatible y protegido frente a redeclaración cuando coexiste con el monolítico.
- Se conserva el supervisor existente, sin duplicar procesos ni modificar el monolítico. Issue #1926.


## [0.1.0]

* Created standalone TaxoCommerce Scrappers plugin.
* Copied the legacy Ojeador 0.10.0 engine.
* Copied the legacy Ingeniero 0.5.0 engine.
* Centralized SerpApi and ScraperAPI configuration in Connections.
* Added Ojeador and Ingeniero as tabs of the standalone plugin.
* Registered Scrappers in TaxoCommerce Core for local ZIP installation/activation.

## Fuentes

- [`changelog.txt`](changelog.txt), etapa monolítica.
- [`docs/PLUGIN-HISTORY-SOURCES.md`](../docs/PLUGIN-HISTORY-SOURCES.md), PR y referencias históricas.

## Actualizaciones

Para nuevas funcionalidades se edita este `CHANGELOG.md`. Antes de publicar, se debe reflejar el resumen de la versión en `readme.txt`, que es el único Changelog mostrado automáticamente por WordPress.org.
