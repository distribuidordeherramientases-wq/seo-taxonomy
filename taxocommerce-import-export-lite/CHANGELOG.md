# TaxoCommerce Import-Export Lite

## [0.1.7] — 2026-11-27 — Administrative translations (#2997)

- Add standalone gettext catalogs for five locales: 116 of 204 keys per locale (580 entries), including administrative controls, CSV field labels, upload errors and progress counters. Localize JavaScript progress and check results. Preserve canonical CSV keys, accepted values, data and import behavior. Remaining validation and manual strings will be translated separately.

## [0.1.6] — 2026-11-27 — Recepción privada de CSV (#2999)

- Recibe CSV de páginas, productos y demás bloques mediante archivos temporales privados con nombre único, sin pasar por Media ni depender del MIME enviado por el navegador.
- Comprueba la procedencia HTTP, extensión, tamaño real, permisos y ubicación; usa el directorio temporal de PHP si el de WordPress no es privado o falla. Diagnósticos diferenciados y limpieza de archivos rechazados.
- Mantiene el prechequeo, identificación y confirmación antes de guardar; pruebas de subida HTTP multipart y regresiones de importación.

## [0.1.5] — 2026-11-25 — Importación y exportación por bloques (#2971)

- Bloques independientes para páginas, productos, categorías de entradas, entradas, categorías de producto y ambos tipos de etiquetas. Sin selector de tipo, acción ni asignación manual.
- Reconocimiento único de cabeceras en servidor, creación/actualización automática, campos omitidos visibles y relaciones mediante cabeceras canónicas. Los IDs externos no se utilizan.
- Prechequeo y confirmación conservados. Exportación directa con campos opcionales plegados. Pruebas de esquema, interfaz y flujo AJAX.

## [0.1.4] — 2026-11-25 — Slugs de archivos multimedia (#2962)

- Restringe get_page_by_path al tipo de contenido solicitado; evita confundir ZIP, imágenes y otros adjuntos con productos, páginas o entradas.
- Regresión de prechequeo e importación con slug multimedia coincidente. Conserva los conflictos reales y no modifica archivos adjuntos.

## [0.1.3] — 2026-11-25 — Cabecera administrativa uniforme (#2966)

- Cabecera autónoma de tres zonas en Import-Export Lite: identificación y descripción locales del plugin con enlace a la ficha de su código; historial de versiones desplegable desde el `CHANGELOG.md` incluido; versión y fecha del archivo principal.
- Diseño adaptable a móvil y al idioma administrativo de WordPress (ES/EN/FR/DE/NL); el historial permanece plegado por defecto y sin consultas externas.
- Se conservan sin cambios los formularios, importación/exportación CSV, prechequeos, datos, permisos, pestañas y funcionamiento autónomo sin Core.

## [0.1.2] — 2026-11-25 — Resolución segura de productos inexistentes (#2962)

- El modo Crear y actualizar ya no confunde una referencia huérfana de SKU/slug o un producto en la papelera con un producto actualizable. Evita el error genérico «El producto relacionado no existe» para nuevos productos; no restaura, sobrescribe ni borra registros antiguos.
- Comprobación de identidad centralizada para el prechequeo de solo lectura y la importación real. Conflictos auténticos entre SKU/slug o índices reservados presentan un mensaje identificable y bloquean la importación sin modificar otros productos.
- Regressiones para productos Mail Lite y Pro, reimportación, referencias huérfanas, papelera y SKU ocupados.

## [0.1.1] — 2026-11-25 — Campos de cada instalación y validación previa (#2958)

- Descubre campos personalizados **escalares, públicos y nativos** de páginas, entradas, productos y términos desde el registro de metadatos y tablas estándar de WordPress; ignora metadatos internos protegidos y valores complejos.
- Detecta atributos globales registrados en WooCommerce y atributos locales de productos existentes; los valores globales deben existir previamente en sus taxonomías y los locales usan texto estándar de WooCommerce.
- Expone los campos adicionales en la selección de exportación y en el asignador de columnas de importación. Conserva los campos no seleccionados y no autoasigna categorías, etiquetas ni identidades ajenas.
- Añade prechequeo de **todas las filas del CSV en modo de solo lectura** y confirmación expresa antes de guardar. Identifica tipos, identidad SKU/slug, categorías o atributos inexistentes, números y URLs inválidos, y registros repetidos dentro del archivo.
- Pruebas CLI: detección de metadatos públicos, rechazo de internos, atributos Woo, reimportación sin duplicados y simulación completa de CSV de dos productos con error de atributo que no escribe hasta corregirlo.
- No introduce dependencias de TaxoCommerce Core ni del antiguo Import-Export; no modifica datos ni código del plugin heredado. La versión Pro avanzada se desarrollará posteriormente sobre esta base limpia.

## [0.1.0] — 2026-11-25

- Primera instalación autorizada exclusivamente en STAGING y Asociación Focazul mediante el despliegue selectivo de GitHub Actions (Issue #2952); no se instala en producción ni se altera el plugin Import-Export original.

- First standalone Lite release, separate from the current TaxoCommerce Import-Export plugin and its advanced batch importer.
- Native WordPress pages, posts, categories and tags; native WooCommerce products, product categories and product tags.
- Single CSV upload; per-column mapping; default create-or-update with SKU/slug identity; new content saved as draft unless explicitly published with appropriate capability.
- CSV export with explicit field selection and incremental native API pagination.
- Native WooCommerce virtual/downloadable products, up to two HTTP(S) download links, prices, stock, dimensions and tax fields.
- No implicit taxonomy or foreign ID mapping; categories remain optional. No SEO taxonomy, supplier, Core, cross-plugin data or configuration required.
- Independent admin screen with version and release date, import/export, read-only checks, user and technical manuals.
- Security: capabilities, nonces, temporary private files, bounded per-request batches, field allowlists, output escaping, formula-injection prevention.
- Excludes child variation migration, orders, customers, binary media and third-party metadata in this first Lite release. The original plugin is unmodified.
