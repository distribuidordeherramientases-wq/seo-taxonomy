## Unreleased - Familia i18n #2062

## [0.2.6] — 2026-11-05

- Issue #2198: formulario plegado en CSV Templates para crear plantillas reutilizables desde JSON o CSV/TXT subidos o texto pegado.
- Validación de correspondencias canónicas, campos obligatorios, límite de tamaño, nonce, permisos y protección frente a sobrescrituras accidentales. Exportación JSON de las plantillas personalizadas.
- Reutilización del almacenamiento actual de WordPress sin ejecutar código, importar productos ni modificar las plantillas integradas. Pruebas de regresión offline.



## [0.2.5] — 2026-11-03

- Pestaña homogénea «Autocheck» y descarga JSON del último informe, por usuario y sitio, con control de permisos y nonce.
- Issue #2150, PR #2151; rutas existentes conservadas y sin pruebas de escritura.

- Dominio gettext, carga de catalogos locales, etiquetas de menús y textos HTML de salida estáticos; catálogos PO/MO sincronizados con el texto fuente.

# CHANGELOG — TaxoCommerce Suppliers

## [0.2.4] — 2026-11-03

- Cabecera visual común basada en Core: nombre, icono, descripción, versión y diseño adaptable.
- Lógica funcional, pestañas y permisos intactos. Plugin autónomo sin dependencia de Core. Issue #2137.

> Historial maestro de versiones del plugin independiente. El origen monolítico se documenta en [`changelog.txt`](changelog.txt).

## [Unreleased]

## [0.2.3]

- Comprobación de nonce dentro de cada acción POST de importación CSV, plantillas, precios, imágenes y decisiones sobre proveedores, manteniendo permisos y contratos de formulario.
- Saneamiento previo de importes y de la asignación de columnas CSV sin cambiar la semántica del valor «-1»; saneamiento de URLs de imágenes externas.
- Preparado el identificador de la tabla de imágenes de proveedor mediante `%i`; consultas SQL directas del inventario propio justificadas solamente en las instrucciones afectadas y sin introducir cachés obsoletas.
- Mantenidos los nombres públicos de clases `TCSUP_*` por compatibilidad, con justificación local del prefijo, y las búsquedas WooCommerce limitadas por metadatos.
- Plugin Check y pruebas PHP/ZIP estrictas requeridas antes de fusionar. Sin cambios en monolítico ni publicación en producción.


## [0.2.2]

- Reestructurado y ampliado el Manual integrado: dos guías completas y diferenciadas, usuario y desarrolladores, con índice, H1/H2/H3, tablas, funcionamiento y riesgos reales.
- Documentados flujos de CSV, decisiones, creación de borradores WooCommerce, precios, imágenes, compras, arquitectura, claves, hooks, permisos, pruebas y despliegue.
- Ampliadas las regresiones smoke y los estilos adaptables del manual; sin cambios de lógica de negocio ni del monolítico.


- Documentación integrada de usuario y técnica en la pestaña Manual del módulo independiente.
- Autotest manual de solo lectura para componentes, hooks, integración WooCommerce, plantillas, ajustes y esquema, con permisos y nonce.
- Conservadas las seis pestañas anteriores y añadidas Manual y Autotest; ampliada la verificación smoke.

## [0.2.1]

- Incorporadas las pestañas Manual y Autotest dentro de TaxoCommerce Suppliers.


## [0.2.0]

* Move WooCommerce sale-to-supplier purchasing from Logistics into Suppliers.
* Add Purchasing dashboard with supplier source, cost, purchase URL, and missing-source detection.

## [0.1.0]

* Initial independent Suppliers module.

## Fuentes

- [`changelog.txt`](changelog.txt), etapa monolítica.
- [`docs/PLUGIN-HISTORY-SOURCES.md`](../docs/PLUGIN-HISTORY-SOURCES.md), PR y referencias históricas.

## Actualizaciones

Para nuevas funcionalidades se edita este `CHANGELOG.md`. Antes de publicar, se debe reflejar el resumen de la versión en `readme.txt`, que es el único Changelog mostrado automáticamente por WordPress.org.
