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

