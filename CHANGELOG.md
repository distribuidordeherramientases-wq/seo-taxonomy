# Changelog

## [2.3.8.1] - 2026-09-26

### Marketing y campañas

- Completada la gestión de campañas existentes con edición explícita mediante **Guardar cambios**.
- Añadidos **Vaciar productos** y **Eliminar campaña** con restauración previa de precios aplicados y cancelación de tareas programadas.
- Ampliada la importación/exportación a un formato completo con registros `campaign` y `product`, incluyendo producto, SKU, precio de campaña y posición.
- La importación completa mantiene compatibilidad con los CSV/JSON antiguos que solo contienen calendario.
- Añadido modo de importación por **fusión** (predeterminado) y opción explícita para **sustituir completamente los productos** de las campañas incluidas.
- Los productos importados se resuelven por `product_id` y, como respaldo, por SKU; se mantienen las validaciones de solapamiento entre campañas.

## [2.3.7] - 2026-09-25

Release centrada en la evolución comercial del sistema, el análisis de mercado, la automatización de campañas, la integración de inventarios con proveedores, la mejora de Dependiente y Solucionador, la optimización de informes y varias mejoras relacionadas con feeds comerciales, opiniones externas y Google Trends.

### Marketing y campañas comerciales

- Creado el nuevo **servicio de campañas comerciales**.
- Integrado **Analista** para proponer campañas de marketing a partir de señales de catálogo, demanda y rendimiento.
- Añadidas **plantillas para campañas publicitarias** y espacios promocionales en la tienda.
- Incorporada la generación de **imágenes para campañas**.
- Añadida exportación e importación de campañas y programación mediante **JSON**.
- Integradas las campañas comerciales en el **programador de redes sociales**.
- Añadidos **precios de oferta y periodo de vigencia** a los feeds comerciales.

### Ojeador y análisis de mercado

- Mejorados los indicadores de **Ojeador** para presentar comparativas de precios más claras.
- Incorporado un sistema de clasificación visual mediante **semáforo de precios**:
  - precio competitivo;
  - precio dentro de mercado;
  - precio poco competitivo.
- Añadida detección de **productos estrella** y oportunidades comerciales.
- Añadida una nueva capa de **análisis de mercado y acciones prioritarias**.
- Mejorada la identificación de productos sobre los que puede resultar interesante crear una oferta.
- Simplificada la lectura de indicadores comerciales para facilitar decisiones de precio y promoción.

### Inventarios y proveedores

- Creado un sistema de **inventarios comerciales por proveedor**.
- Incorporada la posibilidad de seleccionar y enviar determinados productos a proveedores.
- Añadidos procesos automáticos de actualización y sincronización de inventarios con proveedores.
- Preparada la infraestructura para utilizar disponibilidad, precio y catálogo de proveedores como parte del análisis comercial.

### Dependiente V3 y cobertura del catálogo

- Conectado **Dependiente V3** con el sistema actual.
- Reforzada la cobertura editorial y semántica utilizada por Dependiente.
- Mejorada la integración de categorías, productos y conocimiento para responder a búsquedas de usuario.
- Preparada la base para continuar evolucionando la retroalimentación entre Dependiente y las búsquedas realizadas.

### Solucionador

- Creado el nuevo servicio **Solucionador**.
- Añadida exportación del informe de Solucionador en formato **JSON**.
- Integrado el nuevo servicio dentro de la arquitectura de análisis y ayuda a la selección de productos.

### Comentarista y opiniones externas

- Corregido un error en el sistema de **importación/exportación de Comentarista**.
- Añadida integración de **valoraciones externas de productos**.
- Mejorado el tratamiento de productos sin opiniones para evitar problemas relacionados con `aggregateRating` y datos estructurados.
- Preparada la infraestructura para incorporar progresivamente información externa de opiniones y valoraciones.

### Informes y rendimiento

- Optimizada la carga de **SEO Reports**, evitando generar automáticamente informes pesados al entrar en la pantalla.
- Los informes pasan a generarse bajo demanda mediante un botón **Crear informe**.
- Añadida opción **Cerrar informe**.
- Añadida descarga individual de informes en formato **JSON**.
- Reducida la carga inicial y el consumo de recursos en la sección de Informes.

### Google Trends

- Corregida la integración de **Google Trends**.
- Mejorado el tratamiento de respuestas y señales obtenidas desde Trends.
- Reforzada su integración con Analista y los sistemas de detección de oportunidades.

### Feeds comerciales

- Añadido soporte para:
  - precio normal;
  - precio de oferta;
  - fecha de inicio de oferta;
  - fecha de finalización de oferta.
- Preparados estos datos para su utilización en feeds comerciales y plataformas externas.

### Estabilidad y mantenimiento

- Realizadas correcciones adicionales en servicios de importación, análisis y generación de informes.
- Mejorada la separación entre procesos pesados y carga de las pantallas administrativas.
- Reforzada la generación de JSON como formato de intercambio y revisión externa.

### Issues principales

- #299 — Añadir precios de oferta y vigencia a feeds comerciales.
- #298 — Añadir imágenes de los posts/campañas y exportación JSON.
- #297 — Enlazar Analista para proponer campañas de marketing.
- #296 — Crear plantillas para campañas publicitarias.
- #295 — Crear sistema de fidelización de clientes.
- #293 — Crear servicio de campañas comerciales.
- #292 — Agregar procesos automáticos de inventarios con proveedores.
- #291 — Crear semáforo para visualizar mejor las ofertas de Ojeador.
- #290 — Añadir productos estrella y oportunidades de oferta.
- #289 — Mejorar indicadores de Ojeador con comparativas más claras.
- #288 — Sistema de envío de productos a proveedores e inventarios comerciales.
- #287 — Añadir capa de análisis de mercado y acciones prioritarias a Ojeador.
- #286 — Conectar Dependiente V3 y reforzar cobertura editorial.
- #285 — Añadir JSON al servicio Solucionador.
- #284 — Crear servicio Solucionador.
- #283 — Corregir importación/exportación de Comentarista.
- #282 — Optimizar carga de informes de `seo-reports`.
- #281 — Corregir Google Trends.
- #243 — Agregar valoraciones externas de productos.
- #196 — Corregir ausencia de opiniones y `aggregateRating`.
## [2.3.6] - 2026-09-23

Release centrada en la evolución del sistema de análisis y aprendizaje, la integración con Google Shopping, la mejora de Dependiente, la auditoría SEO, el control del catálogo y diversas tareas de estabilidad y mantenimiento.

### Ojeador y Google Shopping

- Incorporado el nuevo servicio **Ojeador** para obtener y analizar información externa de mercado.
- Mejorada la priorización de las consultas para concentrar recursos en categorías y áreas con mejores señales de rendimiento.
- Adaptadas las búsquedas a la nomenclatura y clasificación utilizada por **Google Product Taxonomy**.
- Mejorados los KPI y la información de seguimiento de Ojeador.
- Añadidos logs y exportación JSON para facilitar el diagnóstico y el análisis de resultados.
- Eliminado el límite artificial de consultas/descargas de Google Shopping.
- Corregidos errores detectados durante la ejecución del servicio Ojeador.

### Academia y Entrenador

- Incorporada la **Lección 9**, incluyendo sistema de preguntas.
- Incorporada y revisada la **Lección 10** como fase de repaso y consolidación.
- Optimizada la ejecución de la Lección 10.
- Mejorado el Entrenador para gestionar de forma más eficiente los procesos relacionados con la nueva fase de aprendizaje.
- Ajustada la lógica de aprendizaje y revisión para reducir trabajo redundante.

### Dependiente

- Desplegada una nueva evolución de **Dependiente**.
- Mejorada la coordinación entre Dependiente y los filtros del catálogo.
- Añadido sistema de feedback para aprovechar mejor las consultas y respuestas de los clientes.
- Incorporada edición de las plantillas utilizadas por Dependiente.
- Añadido mecanismo para eliminar conocimiento aprendido cuando sea necesario.
- Mejorada la integración y presencia de Dependiente dentro del sistema.
- Incorporado **Comentarista** en las fichas de producto.

### SEO, auditoría y validación

- Ampliados los chequeos del plugin con nuevos controles SEO y técnicos.
- Incorporadas comprobaciones derivadas de incidencias y recomendaciones detectadas durante las revisiones con Google.
- Corregida la generación de `canonical` en plantillas de categorías.
- Mejorado el tratamiento de errores HTTP internos:
  - los timeouts de transporte ya no se interpretan automáticamente como errores SEO;
  - los resultados no concluyentes se diferencian de errores reales.
- Mejorada la priorización del plan de acción SEO.
- Revisado y mejorado el sistema de análisis de tiempos de carga de páginas.
- Corregidos problemas en el escaneo externo de páginas que podían producir respuestas HTTP 503.
- Alineada parte de la terminología y clasificación del catálogo con los esquemas utilizados por Google.

### FAQs

- Modernizado el sistema de FAQs.
- Mejorada la gestión y preparación de las FAQs para auditoría, análisis y evolución posterior.

### Redirecciones

- Mejorado el gestor de redirects.
- Añadidas funciones de **exportación e importación**.
- Añadido JSON de análisis para poder estudiar:
  - actividad de las reglas;
  - número de hits;
  - cadenas;
  - ciclos;
  - incidencias estructurales;
  - posibles redirecciones sospechosas.

### Redes sociales

- Mejorado el sistema de redes sociales.
- Añadidas funciones de exportación e importación para facilitar la gestión y automatización de publicaciones.

### Inventario y catálogo

- Incorporado un inventario de relación **categoría-producto** para analizar la distribución real del catálogo.
- Añadida exportación JSON del inventario para análisis externo.
- Mejoradas las plantillas con el objetivo de reforzar su orientación comercial.
- Adaptada la portada para poder utilizar información estadística y priorizar dinámicamente los contenidos con mayor interés.

### Importación, proveedores e imágenes

- Corregida la rotación y limpieza de archivos generados por Importar/Exportar.
- Mejorada la eliminación automática de archivos antiguos ya procesados.
- Corregida la gestión de imágenes para evitar almacenar innecesariamente en Media imágenes que deben permanecer asociadas al proveedor.
- Mejorado el Clonador PRO para ampliar la cobertura de los elementos transferidos entre Producción y Staging.

### Rendimiento y estabilidad

- Revisados los tiempos anómalos detectados durante los escaneos de páginas.
- Mejorada la interacción entre los workers externos, WordPress y los sistemas de caché.
- Reducidos falsos positivos provocados por peticiones HTTP internas.
- Mejorada la estabilidad de las tareas de auditoría y diagnóstico.

### Mantenimiento

- Eliminados datos antiguos de componentes SEO ya obsoletos.
- Limpiadas tablas heredadas de plugins y sistemas anteriores que ya no forman parte de la arquitectura actual.
- Eliminadas copias y componentes redundantes detectados durante las validaciones de integridad.
- Mejorada la consistencia general del plugin antes del despliegue a Producción.

### Resumen de la release

Esta versión consolida varios de los servicios principales de SEO System:

- **Ojeador** amplía la inteligencia competitiva y la integración con Google Shopping.
- **Academia y Entrenador** avanzan en el aprendizaje progresivo del sistema.
- **Dependiente** gana nuevas capacidades de respuesta, feedback y gestión de conocimiento.
- **Auditoría y validación** reducen falsos positivos y amplían los controles técnicos y SEO.
- **Inventario, redirects, FAQs y redes sociales** incorporan nuevas herramientas de análisis y exportación.
- Se refuerzan la estabilidad, el mantenimiento y la observabilidad general del sistema.

## [2.3.5]- 2026-09-17

### Resumen

Actualización amplia de STAGING centrada en la evolución de **Dependiente**, el **Intérprete**, la formación semántica mediante **Lingüista/Academia**, mejoras de arquitectura comercial y plantillas, y nuevas herramientas de importación de proveedores.

Esta versión supone una evolución importante del sistema de consulta asistida, manteniendo separadas las funciones de decisión en frontend de los procesos administrativos, informes y aprendizaje.

---

### Dependiente e Intérprete

- Evolución del servicio **Intérprete** con nuevas versiones y mejoras sucesivas en el análisis de consultas.
- Incorporación de diálogo inteligente de **desambiguación** antes de emitir una recomendación cuando la intención del usuario no es suficientemente precisa.
- El diálogo entre **Intérprete y Dependiente** pasa a ser acumulativo, conservando el contexto generado durante la conversación.
- Mejora de la coordinación entre ambos sistemas para evitar respuestas aisladas y aprovechar las decisiones tomadas en pasos anteriores.
- Priorización de familias de producto concretas al generar preguntas de aclaración.
- Corrección de casos en los que Dependiente no encontraba correctamente posts o contenido relacionado.
- Corrección de la carga de imágenes en el frontend de Dependiente.
- Mejoras generales del comportamiento y presentación del Dependiente en frontend.

### Academia, Lingüista y aprendizaje

- Incorporación y evolución del módulo **Lingüista** como soporte semántico del Intérprete.
- Integración de los procesos de formación de Lingüista en el gestor de workers.
- Añadida exportación de la evolución y estado de Lingüista en formato JSON para diagnóstico y seguimiento.
- Incorporada **actualización incremental de la Academia de Dependiente**, evitando regeneraciones completas cuando solo es necesario procesar información nueva o modificada.
- Mejoras en el flujo de aprendizaje para mantener actualizados los datos utilizados por Dependiente sin sustituir el conocimiento histórico existente.

### Arquitectura comercial y contenido

- Nuevas recomendaciones de arquitectura para **Clusters, Hubs primarios y Hubs secundarios**.
- Mejoras de las plantillas para incorporar señales y recomendaciones de marketing.
- Ajustes de frontend y helpers de plantillas para mantener coherencia entre STAGING y producción.
- Actualización de informes cuya validación de relaciones entre posts y categorías había quedado obsoleta.

### Proveedores e importación

- Incorporado soporte para un **importador externo**.
- Actualización de la integración con **Rubix** para procesos de scraping.
- Añadido lanzador específico para Rubix.
- Eliminada una receta antigua de importación de Emuca que ya no formaba parte del flujo vigente.
- Mejoras en la gestión e inventario reducido utilizado durante procesos de importación.
- Mejorado el exportador reducido de productos.

### Mantenimiento y limpieza

- Eliminación de archivos de backup antiguos y residuos que ya no debían formar parte del árbol activo del plugin.
- Limpieza progresiva de código legacy derivado de anteriores versiones de Dependiente e Intérprete.
- Ajustes para reducir duplicidades y mantener una única implementación canónica de cada componente.

### Compatibilidad

- WordPress 7.1
- WooCommerce 11.1.x
- PHP 8.4.x

### Notas de despliegue

Los cambios se han desarrollado y validado primero en **STAGING**.

Antes de promover esta versión a producción se recomienda:

1. Ejecutar el chequeo completo del plugin.
2. Confirmar que no existen funciones, clases o métodos duplicados.
3. Validar Dependiente, Intérprete y Academia desde administración.
4. Comprobar producto, categoría, buscador y frontend de Dependiente.
5. Revisar que los procesos de proveedores y workers no tengan tareas bloqueadas.
6. Promover `staging` hacia `main` mediante Pull Request después de revisar `Files changed`.


## [2.3.4] 2026-09-13 Reorganización interna y consolidación de `seo-taxonomy`


Se realizó una revisión general de la arquitectura del plugin con el objetivo de consolidar los módulos activos, eliminar implementaciones heredadas y reducir la coexistencia de código duplicado.

La estructura de `includes/` fue reorganizada progresivamente para mantener los servicios dentro de sus directorios específicos. Se eliminaron directorios y archivos legacy relacionados con Analista, Dependiente, Clonador, Productos, Social Network e imágenes, manteniendo como referencia las implementaciones canónicas de cada subsistema.

También se actualizaron los mecanismos de carga y bootstrap para adaptarlos a la nueva organización interna y evitar la inclusión simultánea de versiones antiguas y modernas de un mismo servicio.

La revisión de integridad posterior confirmó la eliminación de duplicados de código: las funciones globales, tipos y métodos duplicados pasaron a cero en el entorno de staging.

Durante el mismo ciclo se introdujeron mejoras adicionales en distintos componentes del sistema, entre ellas:

* evolución del servicio Dependiente y corrección de interrupciones relacionadas con cambios de catálogo;
* mejoras en Academia y ampliación de contenidos y FAQs;
* correcciones y ampliaciones del Auditor de datos, incluyendo reducción de falsos positivos;
* mejoras en plantillas y en su sistema de comprobación y sincronización;
* actualización de filtros de producto;
* incorporación de migas de pan en la cabecera;
* nuevas herramientas para identificar y eliminar imágenes locales procedentes de proveedores;
* ajustes en el cargador de plantillas, autoload y archivos de versión;
* diversas correcciones de estabilidad y mantenimiento.

Tras estos cambios, el plugin mantiene operativos los principales subsistemas de proveedores, catálogo, Data Layer y WooCommerce. Permanecen pendientes determinadas incidencias funcionales y de configuración del entorno de staging, principalmente relacionadas con la portada, la resolución de las páginas de tienda, carrito y checkout, y varias comprobaciones SEO y semánticas que se abordarán de forma independiente.


## [2.3.3] - 2026-09-08

Release correspondiente al **Milestone 2.3.3**, centrada en SEO técnico y datos estructurados, importación y sincronización de datos, logística y facturación, calidad del dato, plantillas y estabilidad general del plugin.

### Añadido

- Añadido JSON-LD para portada, categorías y productos, incluyendo la base para `Organization`, `WebSite`, `Product` y navegación estructurada. (#173)
- Creado un sistema de clonación/sincronización para trasladar datos entre producción y staging. (#174)
- Añadido soporte para importar desde PRO a STAGING páginas, categorías, entradas y productos. (#146)
- Añadida asociación de las etiquetas de páginas con el vocabulario SEO. (#152)
- Añadida asociación de las etiquetas de FAQs con el vocabulario SEO. (#165)
- Añadido control propio de costes de envío para complementar o sustituir la lógica estándar de WooCommerce. (#150)
- Añadido nombre descriptivo de los portes según la regla logística aplicada. (#157)
- Añadido control sobre la intensidad/frecuencia de tareas programadas de WooCommerce. (#153)
- Implementado tracking de compras WooCommerce en GA4. (#71)
- Ampliado el sistema de facturas, presupuestos y proformas para contemplar portes e impuestos. (#151)
- Mejorado el tratamiento de impuestos y costes asociados a envíos a Canarias, Ceuta y Melilla. (#131)

### Mejorado

- Mejorado el sistema de actualización de productos durante los procesos de importación. (#163)
- Mejorado el comparador de tablas y datos de productos. (#154)
- Revisadas las plantillas para mostrar la información con una jerarquía más consistente. (#141)
- Mejorada la paridad entre STAGING y PRO para reducir diferencias en plantillas y comportamiento. (#155)
- Revisada la separación entre STAGING y PRO para evitar canibalización e indexación no deseada. (#161)
- Mejorados los informes y controles basados en información procedente de Google, Trends y otras fuentes. (#126)
- Ampliada la revisión y disponibilidad de información asociada a imágenes. (#28)
- Reforzados los controles de calidad del dato orientados a detectar contenido pobre, repetitivo o poco útil. (#147)
- Revisados pesos y dimensiones de productos para detectar datos incorrectos. (#162)
- Mejorada la sincronización de atributos y etiquetas entre STAGING y PRO. (#145)
- Reorganizado el sistema de chequeos en una estructura de carpetas específica y más mantenible. (#172)

### Corregido

- Corregida la incidencia que indicaba erróneamente que el motor propio de Import / Export no estaba disponible. (#148)
- Corregidos problemas del sistema de logística y transporte. (#156)
- Corregidos modos/configuraciones de MySQL que podían provocar errores. (#158)
- Reclasificados avisos de MySQL que no representan errores reales para que se muestren como información. (#159)
- Ajustados parámetros de control de WooCommerce para reducir avisos y errores innecesarios. (#160)
- Recuperado el comparador que había dejado de mostrarse o funcionar correctamente. (#139)
- Recuperado el pie específico de VEVOR. (#138)
- Corregido/eliminado el mensaje de afiliación de Amazon mostrado indebidamente en el pie de páginas. (#137)
- Revisados y corregidos problemas detectados a partir del informe de Semrush. (#64)

### Datos, SEO y compatibilidad

- Se ha reforzado la integración entre Vocabulary, etiquetas, FAQs, categorías y páginas.
- Se han mejorado las comprobaciones de integridad del contenido y del catálogo.
- Se han revisado diferencias de codificación y estructura de base de datos entre entornos; el issue #167 quedó cerrado como duplicado.
- Se mantienen las comprobaciones de compatibilidad con WordPress, WooCommerce y los subsistemas propios de importación, proveedores y plantillas.

### Notas de despliegue

- Versión del plugin: **2.3.3**.
- Rama de integración: `staging`.
- Rama de producción: `main`.
- La versión fue promovida mediante Pull Request de `staging` a `main` y desplegada posteriormente a producción.
- 
## 2.3.2 - Consolidación operativa, Dependiente y catálogo
- Evoluciona Dependiente con entrenamiento, conocimiento persistente, importación/exportación de conocimiento y mejores respuestas ante búsquedas sin resultados.
- Amplía la integración de producto con Amazon y otros proveedores, incorporando productos alternativos y mejorando la disponibilidad de resultados en consultas y comparativas.
- Refuerza el sistema de clasificación semántica y vocabulario, utilizando diccionarios y datos canónicos para mantener coherencia entre productos, etiquetas, atributos y categorías.
- Mejora los procesos de importación, exportación y sincronización de proveedores, incluyendo peso, dimensiones, imágenes, enlaces, control de procesos y exportaciones de apoyo para futuras cargas.
- Optimiza la gestión de imágenes de producto, incluyendo conversión a WebP, reducción de peso, sincronización y tratamiento de imágenes externas.
- Incorpora mejoras comerciales en carrito, checkout, portes, presupuestos y facturación, incluyendo cálculo de envío basado en las características del producto y generación de documentación para clientes.
- Amplía los informes y herramientas SEO, con nuevos filtros, exportaciones JSON, inventarios de contenidos, comparativas y controles para reducir canibalización y detectar contenido sin clasificar.
- Refuerza el mantenimiento interno mediante limpieza de base de datos, rotación de logs, eliminación programada de imports antiguos y mejoras de observabilidad.
- Corrige múltiples incidencias de interfaz y funcionamiento en Dependiente, comparadores, checkout, carrito, búsqueda SEO, estadísticas y conexiones con proveedores.
- Prepara y valida el plugin para el entorno actualizado de WordPress 7.1.

## 2.2.8 - Cierre de etiquetas legacy de producto

- Elimina el fallback público a `wp_seo_nodes` para etiquetas de producto: la ficha solo muestra APLICACIÓN, PLATAFORMA y SUBTIPO canónicos.
- Bloquea nuevas escrituras de `seo_nodes/product/product` desde el importador legacy y desde la pantalla antigua de clasificación de productos.
- El importador mantiene la columna `etiquetas` por compatibilidad de formato, pero ya no la persiste como etiqueta legacy de producto.
- La exportación V2 rellena `etiquetas` con las facetas canónicas públicas (APLICACIÓN / PLATAFORMA / SUBTIPO), no con keywords legacy.
- Los informes de contenido consideran la clasificación semántica canónica (TIPO y facetas) en lugar de depender de las keywords legacy.
- Las propuestas de categoría que usan señales de producto leen las facetas canónicas públicas.
- La pantalla Etiquetas pasa de modo transición a modo canónico y conserva el contador legacy como control de regresión.

## 2.2.7 - Guardado semantico seguro

- El editor de APLICACION, PLATAFORMA y SUBTIPO ya no reescribe asignaciones sin cambios.
- Un simple Actualizar conserva source y confidence de las asignaciones automaticas existentes.
- Solo los terminos realmente anadidos por el usuario pasan a source=manual/confidence=1.0000.
- Solo los terminos realmente retirados se desactivan.

## 2.2.6 - Hotfix filtro SUBTIPO en Etiquetas
- Corrige el error crítico al abrir SEO Taxonomy → Etiquetas después de cargar SUBTIPO.
- El selector de filtros inicializa ahora el grupo `subtipo` igual que ROL, APLICACIÓN y PLATAFORMA.
- No modifica datos, vocabulario ni asignaciones existentes.

## 2.2.5 - Etiquetas canónicas visibles y editor integrado
- La ficha pública prioriza APLICACIÓN, PLATAFORMA y SUBTIPO canónicos en «Aplicaciones y características».
- Las keywords legacy quedan como fallback únicamente cuando el producto no tiene ninguna etiqueta canónica pública.
- La edición semántica se integra como pestaña «Etiquetas semánticas» dentro de «Datos del producto» de WooCommerce.
- TIPO y Ámbito/ROL siguen siendo solo lectura; APLICACIÓN, PLATAFORMA y SUBTIPO son editables y guardan source=manual.
- No elimina etiquetas legacy ni atributos técnicos.

## 2.2.4 - Edición manual del vocabulario semántico de producto

- Añade metabox `Clasificación semántica / Etiquetas` en la ficha nativa de producto.
- TIPO y Ámbito/ROL permanecen de solo lectura para proteger la identidad canónica.
- APLICACIÓN, PLATAFORMA y SUBTIPO se pueden seleccionar, limpiar y crear manualmente.
- Las decisiones guardadas manualmente usan `source=manual` y `confidence=1.0000`.
- La pantalla Etiquetas añade filtro por SUBTIPO y enlaza directamente al bloque de clasificación del producto.
- Control de integridad muestra también asignaciones inválidas de SUBTIPO.
- No elimina etiquetas legacy ni modifica atributos técnicos.

## 2.2.3 - Etiquetas / vocabulary control view

- Añade la pestaña visible **SEO Taxonomy > Etiquetas**.
- Vista de producto en solo lectura con Ámbito/ROL, TIPO, APLICACIÓN, PLATAFORMA, SUBTIPO y etiquetas SEO legacy en la misma fila.
- Filtros por producto/SKU/ID, TIPO, ROL, APLICACIÓN, PLATAFORMA y cobertura de facetas.
- Añade explorador del vocabulario canónico por grupo, con términos, productos, asignaciones, confianza y fuentes.
- Añade una pestaña Control que comprueba cobertura TIPO/ROL, duplicados, asignaciones inválidas y coherencia TIPO -> ROL.
- No modifica datos de catálogo ni elimina etiquetas antiguas; esta versión sirve para validar visualmente el resultado de la migración antes de continuar con SUBTIPO y la retirada del modelo legacy.

## 2.2.2 - Canonical product role reads

- Product semantic validation now reads the canonical ROL derived from active TIPO -> `wp_seo_type_role_map`; `seo_nodes/product/ambito` remains fallback only.
- Product CSV export no longer reads `seo_nodes/product/ambito` directly; public column `ambito` is still emitted from canonical ROL.
- Category `ambito` remains legacy/editorial and is explicitly not derived from product roles.
- Fixes a category semantic-validation regression where a product-role lookup could be referenced in category scope evaluation.
- No database migration is included in this release.

# Changelog

## 2.2.1 - Vocabulary bridge
- ROL canónico de producto leído desde TIPO -> wp_seo_type_role_map, con fallback materializado/legacy.
- La cabecera CSV pública `ambito` se conserva, pero exporta el ROL canónico del producto.
- Las importaciones legacy no pueden contradecir un ROL canónico existente; si no existe todavía, pueden crear un ROL provisional de compatibilidad.
- `consumible` pasa a ser un ámbito/rol válido en importación, validación e informes.
- Los atributos SEO JSON admiten explícitamente el scope especial `global`.
- Las categorías mantienen su `ambito` legacy en esta fase; no se derivan del ROL de sus productos.

# CHANGELOG

Todos los cambios relevantes de **SEO Taxonomy** se documentan en este
archivo.

El proyecto utiliza versionado **SemVer (MAJOR.MINOR.PATCH)**.

-   **MAJOR** → Cambios importantes de arquitectura o funcionamiento.
-   **MINOR** → Nuevas funcionalidades compatibles.
-   **PATCH** → Correcciones y mejoras internas.

------------------------------------------------------------------------

## 2.2.0 - Supplier Import / Sync V2
- Separada la seleccion comercial del estado de sincronizacion.
- Actualizacion automatica por proveedor + SKU conservando object_id.
- Proteccion de titulo, descripcion, excerpt, categorias, atributos y etiquetas en actualizaciones.
- Productos nuevos a la categoria Nuevos productos.
- Modo de imagen persistente local/external; externo como opcion recomendada para nuevas importaciones.
- Reconstruccion autoritativa de galerias externas por SKU.
- Fallback visual: imagen local -> imagen externa -> logo de tienda.
- Runs auditables y deteccion segura de bajas solo en catalogos completos sin errores.
- Bajas reversibles y reactivacion del mismo object_id.
- CSV del crawler limitado a registros vistos en el ciclo actual.


# \[2.0.0\] - Próxima versión

## Arquitectura

Esta versión introduce la nueva arquitectura **SEO Persistence Layer**,
cuyo objetivo es centralizar todas las operaciones de escritura sobre la
base de datos y las entidades gestionadas por el plugin.

A partir de esta versión, las modificaciones persistentes dejarán de
realizarse directamente desde los distintos módulos y pasarán por una
capa común responsable de validar, registrar, auditar y proteger todas
las operaciones críticas.

## Añadido

-   Nueva arquitectura SEO Persistence Layer.
-   API centralizada para operaciones de escritura.
-   Historial de operaciones.
-   Auditoría completa de modificaciones.
-   Preparación para snapshots de datos.
-   Sistema de rollback seguro.
-   Simulación de operaciones (Dry Run).
-   Gestión de transacciones.
-   Registro del contexto de ejecución.
-   Preparación para migraciones versionadas.
-   Inicio del sistema oficial de publicación de versiones.

## Mejorado

-   Base para futuras actualizaciones seguras.
-   Preparación del plugin para mantenimiento sin pérdida de datos.
-   Infraestructura para operaciones masivas protegidas.

------------------------------------------------------------------------

# \[1.5.0\]

## Auditoría y validación

-   Auditoría estructural del sistema SEO.
-   Validación de Clusters, Hubs Primarios, Hubs Secundarios y
    Categorías.
-   Detección de anomalías estructurales.
-   Validación de imágenes, plantillas y contenido.
-   Informes de integridad.
-   Exportación de tablas SEO.
-   Herramientas de limpieza y validación de base de datos.

------------------------------------------------------------------------

# \[1.4.0\]

## Arquitectura SEO

-   Implantación de la estructura basada en Clusters.
-   Incorporación de Hubs Primarios.
-   Incorporación de Hubs Secundarios.
-   Relaciones semánticas entre niveles.
-   Gestión independiente de nodos SEO.
-   Generación de páginas estructurales.

------------------------------------------------------------------------

# \[1.3.0\]

## Clasificación

-   Sistema de clasificación SEO mediante relaciones.
-   Asignación automática de categorías.
-   Gestión de taxonomía SEO independiente.
-   Relaciones entre categorías y estructura editorial.

------------------------------------------------------------------------

# \[1.2.0\]

## Automatización

-   Importación y sincronización de datos.
-   Gestión de atributos SEO.
-   Sistema de redirecciones.
-   Diccionario SEO.
-   Gestión de etiquetas.

------------------------------------------------------------------------

# \[1.1.0\]

## Infraestructura

-   Organización modular del plugin.
-   Bootstrap inicial.
-   Primeras herramientas administrativas.
-   Base de tablas internas del sistema SEO.

------------------------------------------------------------------------

# \[1.0.0\]

## Primera versión

Primera versión funcional de **SEO Taxonomy**.

Incluye la infraestructura inicial del proyecto y las primeras
herramientas para gestionar relaciones SEO, páginas estructurales y
organización semántica del catálogo.

