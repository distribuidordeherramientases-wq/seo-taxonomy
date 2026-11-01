# CHANGELOG — TaxoCommerce Campaigns

> Historial de las versiones del plugin **independiente**. Las funciones anteriores a su separación están documentadas en [`changelog.txt`](changelog.txt).
> Fuente inicial: entradas verificadas del `readme.txt` en GitHub. Solo se atribuye una fecha si la fuente la acredita.

## [Unreleased]

- Revisados los avisos de Plugin Check del módulo Campaigns: identificadores de tablas SQL con `%i` en `$wpdb->prepare()`, entradas de administración y comentarios PHPCS localizados para operaciones sobre tablas propias sin API alternativa.
- Conservadas las reglas de precios, programación y productos. Seguimiento: #1544; validar CI y pruebas funcionales de STAGING.

- Incorporadas pestañas Manual (usuario y técnico) y Autotest interno de solo lectura; comprueba carga, tablas, versión de esquema y dependencias sin modificar campañas, precios ni programación.
- Ampliados y separados claramente el manual operativo para usuarios y la guía técnica para desarrolladores futuros: navegación, procedimientos comerciales, estructura del código, tablas y migraciones, hooks, compatibilidad, seguridad, pruebas y diagnóstico, siguiendo el formato de los plugins Suppliers, Billing y Scrappers.


## [0.1.0]

* Extracted campaigns, product offers and their commercial calendar into an independent module.
* Retained canonical campaign records and compatibility with existing public template hooks.

## Referencias

- [Origen monolítico y transición](changelog.txt)
- [Índice de evidencias de los 20 plugins](../docs/PLUGIN-HISTORY-SOURCES.md)

## Mantenimiento

Este es el historial maestro que se actualiza al introducir novedades. Antes de publicar cada versión en WordPress.org, su resumen debe trasladarse al apartado `== Changelog ==` de `readme.txt`; WordPress.org no presenta este Markdown automáticamente.
