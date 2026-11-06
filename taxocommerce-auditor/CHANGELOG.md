## [0.3.6] — 2026-11-06 — Internacionalización Auditor (#2062)

- Revisión de textos visibles, botones, estados, pestañas, informes, KPI, autochequeo, manual y mensajes de reglas con gettext nativo de WordPress.
- Conservados los identificadores de API, filtros, CSS, SQL, las funciones de auditoría y los formatos de exportación JSON.
- Actualizados los diccionarios por idioma y las pruebas de regresión. Cambios exclusivamente en TaxoCommerce Auditor.

## Unreleased - Familia i18n #2062


## [0.3.5] — 2026-11-03

- Nombre uniforme «Autocheck» y descarga autenticada del último informe JSON, con resultados por comprobación y sin ejecutar operaciones reales.
- Issue #2150; PR #2151. Mantiene las rutas internas existentes y la ejecución no destructiva.

- Dominio gettext, carga de catalogos locales, etiquetas de menús y textos HTML de salida estáticos; catálogos PO/MO sincronizados con el texto fuente.

# CHANGELOG — TaxoCommerce Auditor - Content Quality for WooCommerce

## [0.3.4] — 2026-11-03

- Cabecera común basada en TaxoCommerce Core: identidad, icono, descripción y versión con estilo responsive.
- Enlaces, pestañas, permisos y lógica interna conservados; implementación autónoma sin requerir Core. Issue #2137.

> Historial de las versiones del plugin **independiente**. Las funciones anteriores a su separación están documentadas en [`changelog.txt`](changelog.txt).
> Fuente inicial: entradas verificadas del `readme.txt` en GitHub. Solo se atribuye una fecha si la fuente la acredita.

## [Unreleased]

Sin novedades pendientes documentadas.

## [0.3.3] - 2026-11-01

* Consolidada la publicación independiente actualizada tras el informe de Plugin Check de Playground (2 errores y 6 avisos correspondientes a una revisión antigua).
* Confirmado el escape de errores de términos, `suppress_filters=false`, validación y saneamiento de los tres filtros `POST`, y el máximo de cinco etiquetas del `readme.txt`.
* Documentados con excepciones PHPCS específicas y justificadas los dos filtros `tax_query`: lotes de hasta 40 IDs por cursor y muestra exacta de hasta 201 IDs solo para categorías de como máximo 200 productos. Se mantienen filtros, límites, prioridades, contenido y solo lectura.
* Añadidas pruebas de regresión del código distribuible para impedir reintroducir los ocho hallazgos, sin modificar el monolito ni desplegar PRO. Referencias: Issue #2011.
* Revisadas las 11 consultas directas preparadas a las tablas canónicas de FAQs/Comparador/Dependiente/Suppliers; se justifican puntualmente los avisos DirectQuery/NoCaching donde la lectura debe ser fresca y no existe API de datos WordPress equivalente. Sin caches persistentes ni cambios en consultas. Issue #2021.


## [0.3.2]

* Incorporadas las pestañas Auto Test y Manual dentro del menú existente de Auditor, después de los cinco ámbitos de contenido.
* Auto Test de 16 pruebas agrupadas: servicios, hooks, productos, categorías, FAQs, reglas, prioridades, filtros, lotes, contratos JSONL, estados, informes, historial y documentación; no se ejecutan trabajos, escrituras ni exportaciones reales.
* Dos resultados por administrador en transitorio durante 30 días, con fecha, versión, duración, estados y comparación de regresiones.
* Manual integrado de usuario y técnico: cinco ámbitos, KPIs, filtros, auditoría completa, tareas WP-Cron, prioridades, vistas del informe, JSONL, accesos y seguridad.
* Compatibilidad con las rutas y las reglas anteriores, sin cambios en las auditorías, exportaciones ni datos de negocio; smoke tests PHP 7.4/8.4 y exclusión de tests del ZIP.


## [0.3.1]

* Refresh WordPress.org readiness against the current standalone Auditor code.
* Keep large read-only audit exports streamed from bounded local JSONL storage.

## [0.3.0]

* Added complete and filtered audits with full JSON exports.
* Added per-scope KPI panels and prioritised findings.
* Added optional locally stored decision context and native FAQ auditing.

## [0.2.0]

* Restore the original Auditor interface and legacy quality checks using native WordPress/WooCommerce entities across five audit domains.

## [0.1.0]

* Initial independent five-domain Auditor with bounded scans, native catalogue data and Vocabulary compatibility.

## Referencias

- [Origen monolítico y transición](changelog.txt)
- [Índice de evidencias de los 20 plugins](../docs/PLUGIN-HISTORY-SOURCES.md)

## Mantenimiento

Este es el historial maestro que se actualiza al introducir novedades. Antes de publicar cada versión en WordPress.org, su resumen debe trasladarse al apartado `== Changelog ==` de `readme.txt`; WordPress.org no presenta este Markdown automáticamente.
