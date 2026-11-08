## [0.1.12] - 2026-11-08 - Autotest funcional y de codigo (#2401)

- Conserva autotest funcional y JSON; nuevo Code Check independiente del propio plugin: PHP, WordPress, seguridad. Cinco velocidades, lotes y JSON separado. Sin PCP ni Core obligatorio.

## [0.1.11] — 2026-11-08 — Historial plegable en cabecera (#2382)

- Desplegable cerrado por defecto inmediatamente debajo del título, con el historial propio de `CHANGELOG.md`; sin nueva pestaña ni dependencia de otros plugins.
- Textos del título preparados para ES/EN/FR/DE/NL, salida escapada, desplazamiento vertical y mantenimiento de menús y funciones existentes.
- El paquete instalable y la subida incremental incluyen únicamente el historial raíz necesario. Sin cambios en base de datos ni servicios.

## [Unreleased] — Finalización de los catálogos de Redirects (#2062)

- Añadidas las 53 traducciones restantes del manual técnico por idioma (265 entradas adicionales). Las 144 cadenas no vacías de cada catálogo PO quedan traducidas en ES/EN/FR/DE/NL; 145 entradas por archivo incluyendo la cabecera.
- Regenerados los cinco MO; se conservan los nombres y referencias técnicas, los contratos JSON y advertencias de borrado. No se modifican código funcional, redirecciones ni datos.
- Pendientes auditoría de textos fuera de gettext, Plugin Check y pruebas reales por idioma antes de declarar aptitud WordPress.org. Producción no autorizada.

## [Unreleased] — Traducciones de interfaz y documentación (#2062)

- Internacionalizadas las 57 primeras cadenas todavía vacías del manual del usuario en ES/EN/FR/DE/NL (285 traducciones), con instrucciones sobre 301/302, reglas, copias JSON, borrado asistido, advertencias y diagnósticos.
- Regenerados los cinco archivos MO con 145 entradas por idioma; sin modificar reglas, tablas, datos, rutas, motor de redirecciones ni PHP/JS.
- Continúa pendiente la traducción del manual técnico y la comprobación real de interfaz/idiomas en WordPress; no desplegar a producción.

## [0.1.10] — 2026-11-07 — Cabecera administrativa de versión y fecha (#2336)

- Muestra título, versión activa y fecha de publicación en la cabecera del administrador mediante metadatos locales (Release Date).
- La fecha no depende de la hora de consulta ni del momento de copia por FTP. No altera servicios, datos, menús ni permisos.
- Se conserva el funcionamiento independiente y se comprueba con el test de cabeceras de la familia.

## [Unreleased] — Revisión i18n (seguimiento #2062)

- Completada una entrada gettext previamente vacía en ES/EN/FR/DE/NL; conserva el mismo identificador fuente y no modifica la lógica de la tienda.
- Pendiente recompilar catálogos MO y validar cobertura frente al POT, en especial discrepancias entre idiomas; no supone traducción integral del plugin.

## [Unreleased] — Primera fase i18n Redirects (#2286)

- Traducidas 33 cadenas iniciales de menús, Auto Test y navegación de manual en es_ES, en_US, fr_FR, de_DE y nl_NL.
- Regenerados los cinco catálogos MO sin modificar el motor de redirecciones, las reglas 301/302 ni la eliminación asistida.
- Pendientes las 111 cadenas restantes del POT y los textos fuera de gettext, así como revisar los metadatos con la URL del cliente histórico antes de publicar en WordPress.org.

## [Unreleased] — Regional WordPress locale fallback (#2295)

- Respeta primero los paquetes oficiales de WordPress .mo y .l10n.php para el locale exacto.
- Si falta un catálogo local regional, usa el catálogo existente del mismo idioma (es_ES, en_US, fr_FR, de_DE o nl_NL); no cambia la configuración ni el idioma del usuario.
- Sin cambios en lógica, datos, menús o dependencias del plugin. Las traducciones de contenido incompletas siguen abiertas en #2062.

## Unreleased - Familia i18n #2062


## [0.1.9] — 2026-11-03

- Pestaña «Autocheck» con descarga JSON ya existente, conservación de controles de seguridad y de comparaciones de regresiones.
- Issue #2150, PR #2151; rutas internas y ejecuciones existentes conservadas.

- Dominio gettext, carga de catalogos locales, etiquetas de menús y textos HTML de salida estáticos; catálogos PO/MO sincronizados con el texto fuente.

# CHANGELOG — TaxoCommerce Redirects - SEO Redirect Manager

## [0.1.8] — 2026-11-03

- Cabecera común basada en TaxoCommerce Core: identidad, icono, descripción y versión con estilo responsive.
- Enlaces, pestañas, permisos y lógica interna conservados; implementación autónoma sin requerir Core. Issue #2137.

> Historial maestro de versiones del plugin independiente. El origen monolítico se documenta en [`changelog.txt`](changelog.txt).

## [Unreleased]

Sin novedades pendientes documentadas.


## [0.1.7]

* Add User Guide tab with separate User Manual and Technical Manual, indexed sections, accessible navigation, responsive typography and links to Redirects/Auto Test.
* Document existing 301/302 functionality, administration, rules diagnostics, JSON backups, assisted deletion, data schema, legacy coexistence and the destructive uninstall caveat.
* Manual rendering is read-only and does not load the redirects table or invoke any handlers.

## [0.1.6]

* Add native Auto Test tab with 26 read-only checks in 8 groups for redirects, schema, assisted deletion, integration, and safety.
* Save a per-user, per-site BEFORE baseline and compare it with AFTER results, including lost coverage, new errors and resolved issues.
* Allow downloading private diagnostic JSON; no redirects, hit counters, or content changes are made by tests.
* Keep the Redirects manager and all existing rules, legacy integration, and assisted-delete workflows unchanged.

## [0.1.5]

* WordPress.org release metadata cleanup and English plugin description.
* Confirmed bounded assisted-delete queries keep WordPress filters enabled.

## [0.1.4]

* Retirada la integracion opcional del motor historico de rollback de Database. CRUD de redirecciones vuelve al flujo autonomo original, sin dependencia ni comprobaciones de Database.
* Conservado el boton Abrir y la navegacion por Core cuando esta instalado.

## [0.1.3]

* Version experimental retirada de la integracion de rollback.

## [0.1.2]

* Integracion de menu y accion Abrir en el catalogo Core.

## [0.1.1]

* Verified activation lifecycle and explicit uninstall cleanup for seo_redirects.

## [0.1.0]

* Independent runtime, administration, import/export and 4+4 assisted deletion.

## Fuentes

- [`changelog.txt`](changelog.txt), etapa monolítica.
- [`docs/PLUGIN-HISTORY-SOURCES.md`](../docs/PLUGIN-HISTORY-SOURCES.md), PR y referencias históricas.

## Actualizaciones

Para nuevas funcionalidades se edita este `CHANGELOG.md`. Antes de publicar, se debe reflejar el resumen de la versión en `readme.txt`, que es el único Changelog mostrado automáticamente por WordPress.org.
