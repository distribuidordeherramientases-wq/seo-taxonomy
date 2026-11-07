## Unreleased - Familia i18n #2062

## [0.1.8] — 2026-11-07 — Bing sitemap (Issue #2259)
- Nueva revisión diaria de los sitemaps XML mediante WP-Cron y el trabajador incremental existente (250 registros por bloque, cinco velocidades); ninguna segunda implementación ni escritura durante la petición de la página.
- Evita regeneraciones simultáneas y respeta el generador monolítico cuando sea el propietario activo de la ruta. Limpia su evento recurrente al desactivar el plugin.
- Prueba del evento diario, de la recuperación de una cola ocupada, de la coexistencia legacy y del apagado; el resultado de Bing solo puede comprobarse con los XML publicados y un nuevo rastreo.
- La categoría VEVOR FR - Peinture del ejemplo de Bing tiene productos publicados; esta versión no crea URL específicas ni modifica categorías de otras tiendas.

## [0.1.7] — 2026-11-04

- Issue #2172: interfaz administrativa de Distribution traducible al español, incluidos controles de feeds; traducciones gettext es_ES completas y catálogos binarios coherentes.
- Eliminada la pestaña Overview redundante. Sus indicadores de solo lectura aparecen debajo de la cabecera común y las URL antiguas abren Feeds.
- Conservados los motores de feeds, sitemaps, automatización, acciones protegidas y Autocheck manual. Despliegue en STAGING y Focazul; no producción.



## [0.1.6] — 2026-11-04

- WordPress Plugin Check (#2161): resueltos cinco errores (comentario de traductores y escape de mensajes/nombres de ficheros lanzados como excepciones) y un aviso (eliminado error_log redundante).
- Se conserva el error persistido en el estado del trabajador, la limpieza de temporales y la publicación atómica de XML. Sin cambios en los chequeos manuales existentes.
- No implica modificación de producción ni demuestra la aprobación del comprobador oficial antes de ejecutarlo de nuevo.

## [0.1.5] — 2026-11-03

- Unifica «Autocheck» y permite descargar JSON autenticado de los resultados de la última ejecución, sin modificar servicios ni contenidos.
- Issue #2150, PR #2151; rutas de pestaña y acciones previas conservadas.

- Dominio gettext, carga de catalogos locales, etiquetas de menús y textos HTML de salida estáticos; catálogos PO/MO sincronizados con el texto fuente.

# CHANGELOG — TaxoCommerce Distribution - Feeds & Sitemaps for WooCommerce

> Historial maestro de versiones del plugin independiente. El origen monolítico se documenta en [`changelog.txt`](changelog.txt).

## [Unreleased]

Sin novedades pendientes documentadas.

## [0.1.4] — 2026-11-03

- Corrige el informe de fecha: separa inicio de ejecución, actividad de los lotes y última publicación completada; el XML antiguo no se presenta como publicación nueva.
- Los nuevos archivos hijos llevan identificador de generación único. El índice anterior y sus archivos siguen intactos hasta que el nuevo índice validado se publica al final; reanuda trabajos iniciados con nombres antiguos migrándolos dentro del directorio temporal.
- Detecta fallos de programación de WP-Cron y de almacenamiento, muestra errores explícitos y conserva los sitemaps publicados.
- El informe durante el proceso no suma archivos todavía ausentes del índice anterior; regresaión aislada de publicación, programación fallida y continuidad tras la actualización.


## [0.1.3] — 2026-11-03

- Cabecera de administración con estilo común TaxoCommerce Core, nombre, icono, descripción y versión, sin dependencias entre plugins (#2137 / PR #2138).

- Versión visible en el encabezado de Distribution y actualizada en la cabecera del plugin y en readme.txt.
- Motor de sitemap sustituido por ventanas incrementales de 250 elementos (consulta por cursor para productos), con progreso, bloqueo de concurrencia, reanudación y botón Detener.
- Regulador de cinco velocidades compatible con los perfiles de los demás plugins TaxoCommerce; telemetría local de CPU PHP, memoria y duración, con pausas automáticas por presión.
- Mantiene los grupos y la validación XML del motor anterior, los sitemaps publicados durante el procesamiento y la sustitución final del índice; sin modificar el motor de feeds ni la versión monolítica.
- Seguimiento: #2134; pendiente QA funcional en STAGING.


- Revisados los 24 avisos de Plugin Check: mensajes de estado sin mutaciones, sanitización de avisos de sitemap y justificación localizada de consultas SQL de inventario y metadatos para evitar caché obsoleta.
- Seguimiento: #1545. Validación de Plugin Check y STAGING pendiente.

## [0.1.2]

- Reorganizada la pestaña Manual en **dos documentos completos y diferenciados**: Manual de usuario y Manual técnico para futuros desarrolladores, con índice accesible, secciones independientes y diseño adaptable.
- Ampliado el manual de usuario con secuencia operativa segura; ampliado el técnico con mapa de archivos y funciones, ciclos de trabajo, almacenamiento, opciones, hooks, coexistencia con el monolito, seguridad, pruebas y procedimiento de mantenimiento.
- Documentación contrastada con el código de Distribution. No se modifica el motor de feeds, sitemaps, Autocheck ni ningún archivo del monolito. Seguimiento: #1978.


## [0.1.1]

- Añadida pestaña **Manual**, con guía de usuario y documentación técnica de los feeds comerciales, sitemaps, automatización, rutas públicas, coexistencia y resolución de incidencias.
- Añadida pestaña **Autocheck** con ejecución manual, protección de permisos/nonce y controles agrupados de motor, canales, eventos registrados, opciones de solo lectura y metadatos de los archivos publicados.
- El Autocheck no llama a inicializadores de almacenamiento que podrían crear ficheros, no genera feeds ni XML, no programa workers y no realiza peticiones externas ni escrituras.
- Incorporada prueba aislada sin WordPress real para detectar efectos secundarios y verificar ambas pestañas; sincronizada la documentación WordPress.org. Issue #1978.


## [0.1.0]

* Initial modular migration of commercial feeds and sitemap generation.
* Added independent bounded feed worker.
* Added safe legacy coexistence and settings migration.
* Added Distribution admin tabs for overview, feeds, sitemaps and automation.

## Fuentes

- [`changelog.txt`](changelog.txt), etapa monolítica.
- [`docs/PLUGIN-HISTORY-SOURCES.md`](../docs/PLUGIN-HISTORY-SOURCES.md), PR y referencias históricas.

## Actualizaciones

Para nuevas funcionalidades se edita este `CHANGELOG.md`. Antes de publicar, se debe reflejar el resumen de la versión en `readme.txt`, que es el único Changelog mostrado automáticamente por WordPress.org.
