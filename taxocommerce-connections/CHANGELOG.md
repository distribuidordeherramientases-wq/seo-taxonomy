## [0.3.3] — 2026-11-08 — Dos comprobaciones independientes en Autocheck (#2423)

- Autocheck integra «Chequeo de funcionalidades» y «Chequeo del código» como dos subpestañas de una misma pantalla, conservando los cinco casos funcionales actuales de Connections.
- La inspección de código se limita a Connections y usa tres categorías: PHP, WordPress y seguridad, sin depender del plugin PCP o de otros módulos TaxoCommerce.
- Cinco velocidades de CPU con lotes, parar/reanudar y descarga JSON privada para el administrador; sin leer credenciales ni realizar llamadas a proveedores.
- No se modifica el puente opcional hacia Insights ni la restauración de conexiones existentes. Los resultados estáticos no equivalen a certificación WordPress.org.

## [0.3.2] — 2026-11-08 · Internacionalización completa del catálogo gettext (#2062)

- Traducciones explícitas de los 61 mensajes gettext de Connections en es_ES, en_US, fr_FR, de_DE y nl_NL: menús, ajustes, OAuth, Google, Bing, Merchant, Amazon, Pinterest y respuestas de comprobación.
- Archivos PO completos y compilaciones MO correspondientes; validados identificadores, formato de catálogo y el marcador `%d`. Se conserva el `changelog.txt` histórico.
- Sin cambios de comportamiento de OAuth, credenciales, API, migración de ajustes ni puente de compatibilidad; falta validar las interfaces en los WordPress reales y auditar literales no gettext.
- Corrección del smoke de compatibilidad: verifica coincidencia entre cabecera, constante de versión y etiqueta estable, sin fijar `0.3.1`, para impedir falsos fallos al publicar versiones posteriores.

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
