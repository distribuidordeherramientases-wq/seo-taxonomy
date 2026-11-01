# CHANGELOG — TaxoCommerce Scrappers

> Historial maestro de versiones del plugin independiente. El origen monolítico se documenta en [`changelog.txt`](changelog.txt).

## [Unreleased]

Sin novedades adicionales pendientes.

## [0.1.6]

- Auditoría individual de los 208 avisos restantes de WordPress Plugin Check sobre Scrappers 0.1.5, sin desactivar reglas de análisis globales.
- Justificados en cada llamada los accesos SQL directos a tablas específicas de investigación/Ojeador/Ingeniero (con lecturas en tiempo real y escrituras), comprobaciones de esquema y uso administrativo de resultados actuales. No se añade caché sin una política fiable de invalidación.
- Documentadas en cada punto las lecturas GET de navegación y los datos de formularios leídos después de `guard()` (capacidad y nonce), los nombres de extensión heredados y las búsquedas acotadas por `product_cat`.
- Sin cambio de funciones, nombres públicos, consultas de negocio, datos, credenciales, configuraciones, procesos ni monolítico. Vinculado al Issue #2010.
- Las excepciones de Plugin Check son específicas y revisables; el número de advertencias no implica por sí solo que existiesen 208 fallos funcionales.

## [0.1.5]

- Preparada correctamente la tabla de ejecución en la migración de Ojeador mediante `%i` y `$wpdb->prepare`, sin modificar las condiciones ni las transiciones de estado.
- Ajustada la anotación SQL del exportador JSON a la llamada realmente analizada, con filtros y ordenaciones de lista blanca, y documentada la vinculación de los IDs de Ingeniero al `prepare`.
- Ampliado el smoke para verificar que la migración de Ojeador no interpola la tabla SQL ni permite volver al patrón antiguo.
- Se mantienen intactas las APIs heredadas, los workers y las credenciales. Persisten los avisos de acceso directo a tablas propias que no pueden reemplazarse por APIs de WordPress sin alterar funcionalidad; no se han deshabilitado comprobaciones globales. Issue #2010.

## [0.1.4]

- Contrastado el informe de Plugin Check de PRO del 01/11/2026 (6 errores y 290 avisos) con la rama `staging`: los cinco errores de dominio de traducción y la consulta insegura de vocabulario ya estaban corregidos en `0.1.3` (CI Plugin Check: 0 errores, 217 avisos).
- Ampliadas las pruebas de regresión para examinar todos los PHP de ejecución en `includes/` y asegurar que `assign_category_vocabulary()` usa `%i` para ambas tablas y `%d` para la categoría. Las pruebas no se empaquetan para WordPress.org.
- Publicación exclusivamente en STAGING mediante el workflow habitual; la instalación instalada en PRO conserva el estado anterior hasta una autorización expresa para producción. Issue #2010.

## [0.1.3]

- Revisión de Plugin Check a partir de los resultados del plugin instalado en PRO (5 errores heredados de textdomain y avisos SQL/nonce). Confirmado que los 5 usos heredados del dominio ya estaban corregidos en STAGING desde 0.1.2; se conserva `taxocommerce-scrappers`.
- Sustituidas interpolaciones de identificadores SQL por `%i` en consultas de Ingeniero y Ojeador; parámetros de paginación preparados y exportación JSON con fragmentos ORDER BY/WHERE limitados a una lista fija de confianza.
- Documentados los filtros GET puramente informativos. Se mantienen las validaciones nonce para cambios de estado, y las operaciones de investigación, compras de API y tareas existentes.
- Corregidos nueve errores de escape de enlaces HTML del Manual con `wp_kses_post()`; ajustadas dos consultas SQL del análisis de mercado para superar errores de Plugin Check sin cambiar sus filtros.
- Ampliadas pruebas smoke para impedir regresiones de texto y consultas; no se han alterado las tablas ni el monolítico. Issue #2010.

## [0.1.2]

- Incorporada una pestaña Manual con guía de usuario y referencia técnica de Connections, Ojeador e Ingeniero, incluidos permisos, tablas, hooks, configuración, prevención de costes y diagnóstico.
- Añadido Autotest manual y no destructivo por grupos: componentes, tablas, configuración local de proveedores y registro de controladores. No consulta APIs externas ni inicia procesos, no modifica la base de datos y nunca expone claves.
- Pruebas de regresión sin conexión, incluyendo protección de credenciales y comportamiento de estado. Los scripts de desarrollo permanecen excluidos del ZIP distribuible.
- Corregidos cinco usos del dominio de traducción heredado `taxocommerce-seo` en Ojeador e Ingeniero, sustituidos por `taxocommerce-scrappers` para superar el control de internacionalización del plugin independiente.
- Parametrizados los identificadores SQL de las tablas de vocabulario mediante `%i` en la consulta de asignación de categorías, conservando los filtros y resultados. Seguimiento de Plugin Check: #1926. Manual y Autotest: #1964.


## [0.1.1]

- Corregido el fallo fatal en la activación independiente de Scrappers al no estar definida la interfaz del proceso Ingeniero. Contrato compatible y protegido frente a redeclaración cuando coexiste con el monolítico.
- Se conserva el supervisor existente, sin duplicar procesos ni modificar el monolítico. Issue #1926.

## [0.1.1]

- Corregido el fallo fatal en la activación independiente de Scrappers al no estar definida la interfaz del proceso Ingeniero. Contrato compatible y protegido frente a redeclaración cuando coexiste con el monolítico.
- Se conserva el supervisor existente, sin duplicar procesos ni modificar el monolítico. Issue #1926.


## [0.1.0]

* Created standalone TaxoCommerce Scrappers plugin.
* Copied the legacy Ojeador 0.10.0 engine.
* Copied the legacy Ingeniero 0.5.0 engine.
* Centralized SerpApi and ScraperAPI configuration in Connections.
* Added Ojeador and Ingeniero as tabs of the standalone plugin.
* Registered Scrappers in TaxoCommerce Core for local ZIP installation/activation.

## Fuentes

- [`changelog.txt`](changelog.txt), etapa monolítica.
- [`docs/PLUGIN-HISTORY-SOURCES.md`](../docs/PLUGIN-HISTORY-SOURCES.md), PR y referencias históricas.

## Actualizaciones

Para nuevas funcionalidades se edita este `CHANGELOG.md`. Antes de publicar, se debe reflejar el resumen de la versión en `readme.txt`, que es el único Changelog mostrado automáticamente por WordPress.org.
