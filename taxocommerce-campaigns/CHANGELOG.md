## [0.1.4] — 2026-11-05 — Traducciones de campañas de marketing (#2239)

- Menú y pestañas con nombre funcional «Campañas de marketing» en español y equivalentes naturales en inglés, francés, alemán y neerlandés.
- Completados y compilados los cinco catálogos `.po/.mo` nativos, con 151 mensajes por idioma en lugar de archivos vacíos. Actualizada la plantilla `.pot`.
- Internacionalizados los encabezados, textos administrativos, formularios de campañas, calendarios, banners y controles de Autocheck. Corregidas erratas ortográficas en las traducciones españolas sin alterar claves de datos, acciones ni URLs.
- Añadidas pruebas de integridad de los catálogos y conservadas las integraciones y reglas comerciales. Publicación sujeta a Plugin Check y validación funcional en STAGING/Focazul; sin despliegue en producción.

## Unreleased - Familia i18n #2062


## [0.1.3] — 2026-11-03

- Nombre uniforme «Autocheck» y descarga autenticada del último informe JSON, con resultados por comprobación y sin ejecutar operaciones reales.
- Issue #2150; PR #2151. Mantiene las rutas internas existentes y la ejecución no destructiva.

- Dominio gettext, carga de catalogos locales, etiquetas de menús y textos HTML de salida estáticos; catálogos PO/MO sincronizados con el texto fuente.

# CHANGELOG — TaxoCommerce Campaigns

## [0.1.2] — 2026-11-03

- Cabecera visual común basada en Core: nombre, icono, descripción, versión y diseño adaptable.
- Lógica funcional, pestañas y permisos intactos. Plugin autónomo sin dependencia de Core. Issue #2137.

> Historial de las versiones del plugin **independiente**. Las funciones anteriores a su separación están documentadas en [`changelog.txt`](changelog.txt).
> Fuente inicial: entradas verificadas del `readme.txt` en GitHub. Solo se atribuye una fecha si la fuente la acredita.

## [Unreleased]

Sin novedades pendientes documentadas.

## [0.1.1] — 2026-11-01

- Identificada explícitamente la versión 0.1.1 para distinguir el paquete corregido de la 0.1.0 instalada anteriormente; sincronizados la cabecera, la constante y el Stable tag de WordPress.org.
- Revisados los avisos de Plugin Check del módulo Campaigns: identificadores de tablas SQL con `%i` en `$wpdb->prepare()`, entradas de administración y comentarios PHPCS localizados para operaciones sobre tablas propias sin API alternativa.
- Revisadas también las consultas de Autotest y conservadas explícitamente las APIs públicas históricas de integración con Core, Templates y GD (sin renombrarlas).
- Conservadas las reglas de precios, programación y productos. Seguimiento: #1544; QA automatizada superada, pendiente validación funcional en STAGING.

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
