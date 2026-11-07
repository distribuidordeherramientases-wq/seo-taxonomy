## [0.3.12] — 2026-11-07 — Cabecera administrativa de versión y fecha (#2336)

- Muestra título, versión activa y fecha de publicación en el administrador mediante Release Date.
- La fecha no depende de la hora de consulta ni del momento de copia por FTP. Sin cambios en servicios, datos, menús ni permisos.

## [0.3.11] — 2026-11-07 — Registro Core sin traducciones prematuras (#2330)

- Registro de metadatos de este plugin en TaxoCommerce Core pospuesto a `init` prioridad 11 cuando Core solicita los datos durante `plugins_loaded`.
- Conserva el registro de módulos, traducciones por locale, menús, acciones, contenido y funcionamiento independiente sin modificar Core.
- Evita el aviso de WordPress 6.7+ `_load_textdomain_just_in_time` causado por la descripción traducida del módulo.
- La desaparición del aviso requiere confirmación en STAGING tras desplegar.

## [0.3.10] — 2026-11-07 — Vocabulary de entradas y landings (#2298)

- El JSON portable de Vocabulary incorpora dos capas opcionales: entradas y páginas nativas de WordPress; las landings se resuelven como páginas sin dependencia de TaxoCommerce SEO ni Core.
- Se conserva el contrato anterior de productos, categorías, atributos y etiquetas, con simulación obligatoria y modos MERGE/MIRROR. La identidad se resuelve mediante slug o ruta jerárquica de destino, nunca mediante un ID de otra tienda.
- La simulación informa de conflictos por páginas ausentes, papelera y términos canónicos desconocidos, evitando modificaciones silenciosas; la importación usa la tabla canónica de relaciones.
- Pruebas offline de identidad y seguridad, lint de PHP y validación de regresión. No migra ni inventa datos específicos de ningún comercio.

## [Unreleased] — Regional WordPress locale fallback (#2295)

- Respeta primero los paquetes oficiales de WordPress .mo y .l10n.php para el locale exacto.
- Si falta un catálogo local regional, usa el catálogo existente del mismo idioma (es_ES, en_US, fr_FR, de_DE o nl_NL); no cambia la configuración ni el idioma del usuario.
- Sin cambios en lógica, datos, menús o dependencias del plugin. Las traducciones de contenido incompletas siguen abiertas en #2062.

## [Unreleased] — Completar los cinco catálogos de Vocabulary (#2263)

- Traducidas las 62 cadenas iniciales y 49 textos adicionales de la interfaz (111 entradas visibles) a español, inglés, francés, alemán y neerlandés.
- Regenerados los cinco catálogos binarios GNU gettext (PO/MO) conservando el text domain y la selección de locale de WordPress. Pendiente internacionalizar el manual integrado, que todavía contiene HTML literal en español.
- Sin cambios en importación/exportación, clasificación, datos o interfaces de otros plugins. La cobertura de POT no sustituye las pruebas visuales en WordPress.

## [0.3.9] — 2026-11-05 — Recuperación del clasificador monolítico (#2232)

- Recuperado el análisis contextual local: título, descripción corta/larga, categorías, etiquetas WooCommerce, marcas, SKU y metadatos genéricos/de migración de proveedores, con extensión opcional mediante un filtro estándar.
- Incorporadas equivalencias léxicas conservadoras, puntuación con evidencia de origen, reconocimiento de términos controlados y sus alias, y lectura explícita de medidas técnicas con unidades (tensión, potencia, diámetro, piezas, etc.).
- El motor funciona de forma independiente: no llama a funciones del monolito ni a servicios externos; no crea ni asigna etiquetas/atributos automáticamente y conserva las propuestas, filtros, JSON y clasificación de categorías existentes.
- Pruebas de regresión para coincidencia contextual, alias, medidas con unidades, ausencia de falsos positivos, datos vacíos y contratos del módulo; PHP lint y Plugin Check.

## Unreleased - Familia i18n #2062


## [0.3.8] — 2026-11-03

- Nombre uniforme «Autocheck» y exportación JSON autenticada de los resultados con detalle por prueba.
- Issue #2150, PR #2151; rutas existentes conservadas y sin pruebas de escritura.

- Dominio gettext, carga de catalogos locales, etiquetas de menús y textos HTML de salida estáticos; catálogos PO/MO sincronizados con el texto fuente.

# CHANGELOG — TaxoCommerce Vocabulary

## [0.3.7] — 2026-11-03

- Cabecera visual común basada en Core: nombre, icono, descripción, versión y diseño adaptable.
- Lógica funcional, pestañas y permisos intactos; sin dependencia de Core. Issue #2137.

> Historial maestro de versiones del plugin independiente. El origen monolítico se documenta en [`changelog.txt`](changelog.txt).

## [0.3.6]

## [Unreleased]

* Lectura CSV y JSON portable: comprobación local del nonce y validación del estado y la ruta temporal de la subida PHP; no se modifica el contenido del archivo ni los modos MERGE/MIRROR.
* Documentados de manera localizada los filtros GET de solo lectura (no requieren nonce) y las consultas por categoría, ya limitadas a 50 y 500 registros.
* Justificadas de forma localizada por método las excepciones WPCS de acceso SQL directo y falta de caché en tablas canónicas compartidas, cuyas escrituras externas no tienen invalidación coordinada. No se cambian las consultas ni su semántica; no se deshabilitan los chequeos globales.

## [0.3.5]

* Se preparan los identificadores de tablas de las consultas SQL de administrador, Clasificador, importación/exportación CSV y JSON portable usando `%i`, manteniendo el esquema canónico y la semántica de las consultas.
* Se sanean los filtros de navegación y análisis y se validan los metadatos del CSV (estado de subida, ruta temporal creada por PHP, tamaño máximo y tipo).
* Se preservan las pestañas Manual y Auto Test, el importador JSON portable y las acciones comerciales actuales.
* Las correcciones que ya estaban presentes en STAGING (licencia, versiones y lectura de CSV mediante `WP_Filesystem`) no se duplican; el archivo oculto FTP existente en PRO requiere limpieza independiente autorizada.

## [0.3.4]

* Añadidas las pestañas Auto Test y Manual dentro de Vocabulary, sin alterar Etiquetas, Atributos, Clasificador, Importar / Exportar ni Control.
* Auto Test de 16 comprobaciones agrupadas: arranque, rutas, WooCommerce, MySQL, esquema canónico, columnas, lecturas acotadas, clasificador, intercambio portable y documentación; las escrituras y descargas reales se omiten explícitamente.
* Comparación de las dos últimas ejecuciones por usuario mediante transitorios de 30 días, indicando versión y regresiones.
* Manual integrado con guía de usuario y guía técnica: pantallas, grupos, datos canónicos, importación CSV, JSON MERGE/MIRROR, nonces, roles, límites y compatibilidad.
* Pruebas automatizadas y exclusión de los scripts de pruebas del ZIP distribuible y del paquete de STAGING; artefacto de despliegue con nombre estable en vez del identificador obsoleto 0.3.1.


## [0.3.3]

* Replace direct PHP filesystem access in CSV import/export with WordPress-compatible handling.
* Render import forms directly with escaped values instead of concatenating raw form markup.

## [0.3.2]

* Portable semantic backups, object identity resolution and import simulation.
* MERGE/MIRROR assignment controls and fingerprinted exports.

## [0.3.0]

* Canonical storage reuse, independent classifier and CSV master exchange.

## Fuentes

- [`changelog.txt`](changelog.txt), etapa monolítica.
- [`docs/PLUGIN-HISTORY-SOURCES.md`](../docs/PLUGIN-HISTORY-SOURCES.md), PR y referencias históricas.

## Actualizaciones

Para nuevas funcionalidades se edita este `CHANGELOG.md`. Antes de publicar, se debe reflejar el resumen de la versión en `readme.txt`, que es el único Changelog mostrado automáticamente por WordPress.org.
