## [Unreleased] — Revisión i18n (seguimiento #2062)

- Completada una entrada gettext previamente vacía en ES/EN/FR/DE/NL; conserva el mismo identificador fuente y no modifica la lógica de la tienda.
- Pendiente recompilar catálogos MO y validar cobertura frente al POT, en especial discrepancias entre idiomas; no supone traducción integral del plugin.

## Unreleased - Familia i18n #2062


## [0.3.1] — 2026-11-03

- Nuevo Autocheck independiente del puente de compatibilidad y del modo de respaldo, con JSON descargable protegido; no expone credenciales.
- Issue #2150; PR #2151. Mantiene las rutas internas existentes y la ejecución no destructiva.

- Dominio gettext, carga de catalogos locales, etiquetas de menús y textos HTML de salida estáticos; catálogos PO/MO sincronizados con el texto fuente.

# CHANGELOG — TaxoCommerce Connections Compatibility Bridge

> Historial de las versiones del plugin **independiente**. Las funciones anteriores a su separación están documentadas en [`changelog.txt`](changelog.txt).
> Fuente inicial: entradas verificadas del `readme.txt` en GitHub. Solo se atribuye una fecha si la fuente la acredita.

## [Unreleased]

Sin novedades pendientes documentadas.


## [0.3.0]

* Converted the standalone plugin into a compatibility bridge.
* Moved canonical connection ownership and UI to TaxoCommerce Insights.
* Historical Connections URLs now redirect to Insights -> Connections.
* Removed standalone family-extension registration.
* Preserved standalone fallback behavior when Insights is unavailable.

## [0.2.0]

* Shared Google, Bing, Merchant, Amazon, and Pinterest connection layer before consolidation.

## Referencias

- [Origen monolítico y transición](changelog.txt)
- [Índice de evidencias de los 20 plugins](../docs/PLUGIN-HISTORY-SOURCES.md)

## Mantenimiento

Este es el historial maestro que se actualiza al introducir novedades. Antes de publicar cada versión en WordPress.org, su resumen debe trasladarse al apartado `== Changelog ==` de `readme.txt`; WordPress.org no presenta este Markdown automáticamente.
