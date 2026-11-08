## [0.2.8] — 2026-11-08 — Historial plegable en cabecera (#2382)

- Desplegable cerrado por defecto inmediatamente debajo del título, con el historial propio de `CHANGELOG.md`; sin nueva pestaña ni dependencia de otros plugins.
- Textos del título preparados para ES/EN/FR/DE/NL, salida escapada, desplazamiento vertical y mantenimiento de menús y funciones existentes.
- El paquete instalable y la subida incremental incluyen únicamente el historial raíz necesario. Sin cambios en base de datos ni servicios.

## [Unreleased] — Traducciones administrativas y manuales Logistics (#2062)

- Incorporadas 270 traducciones de interfaz, diagnósticos, documentación y ayudas de envíos en es_ES, en_US, fr_FR, de_DE y nl_NL (49 EN, 56 FR, 55 DE, 55 NL y 55 ES), sin sobrescribir traducciones existentes.
- Los catálogos PO conservan 142 entradas por idioma y se han regenerado los cinco MO correspondientes. Se mantienen intactos los datos, el motor de transporte, el checkout, las opciones y los identificadores gettext.
- Trabajo parcial: faltan párrafos extensos del manual y una auditoría de textos fuera de gettext, Plugin Check y pruebas en WordPress para cada idioma. Producción no autorizada.

## [0.2.7] — 2026-11-07 — Cabecera administrativa de versión y fecha (#2336)

- Muestra título, versión activa y fecha de publicación en el administrador mediante Release Date.
- La fecha no depende de la hora de consulta ni del momento de copia por FTP. Sin cambios en servicios, datos, menús ni permisos.

## [Unreleased] — Revisión i18n (seguimiento #2062)

- Completada una entrada gettext previamente vacía en ES/EN/FR/DE/NL; conserva el mismo identificador fuente y no modifica la lógica de la tienda.
- Pendiente recompilar catálogos MO y validar cobertura frente al POT, en especial discrepancias entre idiomas; no supone traducción integral del plugin.

## [Unreleased] — Reutilización segura de traducciones (#2307)

- Completadas únicamente entradas gettext PO vacías con traducciones idénticas y no ambiguas ya existentes en otros catálogos TaxoCommerce.
- Regenerados los MO de los idiomas modificados. Se conservan los textos, identificadores técnicos, funciones y datos anteriores sin cambios.
- Esta corrección es parcial: los textos restantes, las cadenas fuera de gettext y la validación visual por idioma siguen abiertos en #2062.

## [Unreleased] — Regional WordPress locale fallback (#2295)

- Respeta primero los paquetes oficiales de WordPress .mo y .l10n.php para el locale exacto.
- Si falta un catálogo local regional, usa el catálogo existente del mismo idioma (es_ES, en_US, fr_FR, de_DE o nl_NL); no cambia la configuración ni el idioma del usuario.
- Sin cambios en lógica, datos, menús o dependencias del plugin. Las traducciones de contenido incompletas siguen abiertas en #2062.

## Unreleased - Familia i18n #2062


## [0.2.6] — 2026-11-03

- Pestaña «Autocheck» con descarga JSON ya existente, conservación de controles de seguridad y de comparaciones de regresiones.
- Issue #2150, PR #2151; rutas internas y ejecuciones existentes conservadas.

- Dominio gettext, carga de catalogos locales, etiquetas de menús y textos HTML de salida estáticos; catálogos PO/MO sincronizados con el texto fuente.

# CHANGELOG — TaxoCommerce Logistics

## [0.2.5] — 2026-11-03

- Cabecera visual común basada en Core: nombre, icono, descripción, versión y diseño adaptable.
- Lógica funcional, pestañas y permisos intactos; sin dependencia de Core. Issue #2137.

> Historial maestro de versiones del plugin independiente. El origen monolítico se documenta en [`changelog.txt`](changelog.txt).

## [Unreleased]

- Inicio de internacionalización WordPress mediante gettext (`Domain Path: /languages`), catálogos por idioma y traducción de etiquetas del menú, sin cambios funcionales. Seguimiento: #2039 (plan global #2036). Cobertura restante de interfaz, Autotest y manuales pendiente de revisión por idioma.


* WordPress Plugin Check: corregidas las 12 advertencias de `includes/seo-transporte.php` sin cambiar las reglas, costes ni rutas de checkout.
* Mantener parámetros GET de enrutado y avisos como lecturas inofensivas y justificar sus excepciones de nonce solo en esos puntos.
* Exigir los nonces nativos `update-order-review` o `woocommerce-process_checkout` para aceptar destino enviado por POST; datos de destino saneados por campo y respaldo con paquete/sesión cuando no hay petición válida.
* Añadir regresión CLI de checkout (nonce ausente, inválido, revisión y finalización válidas, campos mal formados), excluirla del ZIP e impedir que Plugin Check ignore advertencias en CI.
* Issue #1947. Sin cambios en el plugin monolítico ni despliegue a producción.


## [0.2.4]

* Integrate the Logistics menu into TaxoCommerce Core when installed, keeping the standalone fallback when Core is absent.
* Preserve the Shipping Rules, Auto Test and Manual tabs and the existing shipping-option storage.
* Remove the redundant separate WordPress sidebar entry while Core is active.

## [0.2.3]

* Fix escaped output of list tags in the user and technical manuals.
* Standardize the built-in documentation tab as Manual, with Manual de usuario and Manual técnico, preserving its existing URLs.
* Keep Auto Test with 35 read-only checks and before/after comparisons.

## [0.2.2]

* Add User Guide tab with an indexed User Manual (10 chapters) and Technical Manual (11 chapters), using the same layout as the TaxoCommerce family.
* Explain real shipping regions, fees, rule priorities, missing dimensions, order audits, JSON configuration backups and the before/after Auto Test.
* Keep documentation read-only: it never loads order audits, calculates live shipping, changes settings or imports files.

## [0.2.1]

* Add separate Auto Test tab with 35 read-only checks in eight functional areas and pre/post deployment comparison.
* Check WooCommerce shipping hooks, rates, region mapping, weight/dimensions, fixed/variable/free cost rules, saved settings and import/export controllers without changing real orders or shipping rules.
* Store BEFORE and latest diagnostics by site and administrator; export JSON, detect new failures and lost check coverage.
* Mark checkout, tax, real imports and cache tests as unverified until run against staging.

## [0.2.0]

* Move sale-to-supplier purchasing to TaxoCommerce Suppliers.
* Keep Logistics focused on order weights and shipping costs.
* Add active-order shipping weight and charged-cost audit.

## [0.1.0]

* Migrated the existing Order Management and Shipping modules into an independent TaxoCommerce plugin.

## Fuentes

- [`changelog.txt`](changelog.txt), etapa monolítica.
- [`docs/PLUGIN-HISTORY-SOURCES.md`](../docs/PLUGIN-HISTORY-SOURCES.md), PR y referencias históricas.

## Actualizaciones

Para nuevas funcionalidades se edita este `CHANGELOG.md`. Antes de publicar, se debe reflejar el resumen de la versión en `readme.txt`, que es el único Changelog mostrado automáticamente por WordPress.org.
