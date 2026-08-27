## 2.3.0 - Consolidación funcional, seguridad y nueva base de desarrollo

### Funcionalidad
- Añade la opción de solicitar información para productos fuera de stock (#52).
- Mejora la gestión de productos y los flujos asociados (#10).
- Revisa y corrige la representación del estado de stock (#3).
- Amplía y consolida los procesos relacionados con incorporación y gestión de productos.
- Corrige los enlaces de clusters y hubs en la navegación (#14).

### Plantillas, interfaz e imágenes
- Revisa el sistema de plantillas y consolida su funcionamiento (#11).
- Corrige las plantillas de blog y entradas para mostrar correctamente las imágenes (#47).
- Mejora la asignación y visualización de imágenes en páginas, entradas, categorías y otros contenidos (#1, #9).
- Mejora la diferenciación visual del footer (#48).
- Mejora la presentación de errores y avisos de contenido (#27).

### Seguridad, diagnóstico y monitorización
- Añade un sistema de chequeo de seguridad (#24).
- Incorpora información relacionada con Cloudflare dentro de los controles de seguridad (#35).
- Mueve los logs del plugin fuera de ubicaciones públicas y consolida el sistema de log privado (#26).
- Añade controles y métricas relacionados con el uso de la base de datos (#34).
- Separa los resultados generados por distintos sistemas de tests para evitar colisiones de archivos JSON (#33).
- Corrige incidencias relacionadas con vocabularios y validaciones internas (#32).
- Revisa el comportamiento del sistema de redirecciones (#25).

### Arquitectura y mantenimiento
- Incorpora y consolida el subsistema `seo-system/`.
- Integra los cambios acumulados en producción desde la versión 2.2.8.
- Elimina archivos auxiliares, copias `.bak` y restos de desarrollo que ya no forman parte de producción.
- Actualiza documentación y archivos de proyecto.

### Repositorio y flujo de desarrollo
- Establece GitHub como repositorio público oficial del proyecto (#15, #29, #30).
- Sincroniza por primera vez el estado real de producción de Webempresa con el repositorio.
- A partir de esta versión, GitHub pasa a ser la fuente principal del código y Webempresa el destino de despliegue.
- 
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


# Changelog

Todos los cambios relevantes de **SEO Taxonomy** se documentan en este archivo.

> **Nota sobre el historial anterior a GitHub**
>
> El proyecto comenzó a desarrollarse varios meses antes de publicarse en GitHub.
> El historial Git disponible actualmente solo refleja la etapa más reciente del desarrollo.
>
> Por este motivo, las primeras secciones de este changelog son una reconstrucción retrospectiva de la evolución técnica del proyecto y están organizadas por fases, no por commits individuales ni por fechas exactas.
>
> A partir de la incorporación del proyecto a GitHub, los cambios se documentarán mediante commits, Issues, Pull Requests y Releases.

---

# Unreleased

## Desarrollo actual

* Continuación del desarrollo mediante Issues de GitHub como backlog principal del proyecto.
* Corrección progresiva de errores detectados por el sistema de diagnóstico.
* Revisión y consolidación de las plantillas del frontend.
* Mejora de las relaciones editoriales entre clusters, hubs y categorías.
* Evolución del sistema de importación y sincronización de proveedores.
* Consolidación del vocabulario semántico de productos.
* Preparación del proyecto para colaboración externa.
* Incorporación de documentación para contribuidores.
* Creación del changelog histórico del proyecto.
* Preparación del repositorio para su futura publicación como proyecto abierto.

---

# 2026-08 — Consolidación en GitHub

## Repositorio y gestión del desarrollo

* Migración del proyecto a GitHub.
* Adopción de GitHub Issues como sistema principal para:

  * registrar errores;
  * documentar mejoras;
  * anotar tareas pendientes;
  * mantener trazabilidad sobre el trabajo realizado;
  * mostrar públicamente la evolución del proyecto.
* Incorporación de `README.md`.
* Incorporación de `CONTRIBUTING.md`.
* Incorporación de licencia del proyecto.
* Incorporación de `CHANGELOG.md`.
* Inicio de un historial formal de commits.
* Preparación del repositorio para recibir contribuciones externas.

## Arquitectura general

* Consolidación de SEO Taxonomy como plugin independiente para WordPress y WooCommerce.
* Separación progresiva entre:

  * datos del catálogo;
  * arquitectura SEO;
  * presentación;
  * relaciones editoriales;
  * semántica de producto;
  * importación y sincronización de proveedores.
* Reducción de dependencias de código externo al plugin.
* Centralización de la lógica SEO en módulos propios.
* Organización del código en componentes administrativos, públicos y de dominio.

---

# 2026-08 — Sistema de plantillas y routing

## Plantillas propias

Se consolidó un sistema de plantillas controlado directamente por SEO Taxonomy.

Se añadieron o desarrollaron contextos específicos para:

* Cluster.
* Hub primario.
* Hub secundario.
* Categoría WooCommerce.
* Producto WooCommerce.
* Tienda WooCommerce.
* Búsqueda.
* Carrito.
* Checkout.
* Página 404.
* Páginas y contextos especiales del sistema.

## Template Manager

* Creación de un gestor central de plantillas.
* Registro de plantillas disponibles.
* Distinción entre plantillas:

  * editoriales;
  * automáticas;
  * de sistema.
* Posibilidad de activar y desactivar plantillas.
* Separación entre plantillas asignables manualmente y contextos automáticos.

## Template Loader

* Implementación de resolución automática de plantilla según el contexto de WordPress/WooCommerce.
* Resolución de páginas según su rol SEO.
* Resolución específica de categorías de producto.
* Resolución específica de productos.
* Incorporación del contexto WooCommerce `shop`.
* La página principal de tienda se detecta mediante el contexto real de WooCommerce en lugar de depender exclusivamente de una página asignada manualmente.
* Fallback al comportamiento normal del tema cuando una plantilla específica no está disponible o no está activa.

## Independencia del constructor visual

* La presentación dejó de depender exclusivamente de un constructor visual.
* La lógica de navegación, jerarquía y renderizado pasó progresivamente al plugin.
* Esto permitió separar la arquitectura SEO de la herramienta utilizada para diseñar visualmente el sitio.

---

# 2026-08 — Vocabulario semántico de productos

Se inició y consolidó una nueva arquitectura semántica para clasificar productos sin depender de las antiguas keywords o etiquetas genéricas.

## Nueva capa de vocabulario

Se crearon estructuras propias para almacenar:

* términos semánticos;
* asignaciones producto → término;
* relaciones TIPO → ROL;
* atributos técnicos;
* información editorial heredada.

Los principales grupos semánticos son:

### ROL

Cada producto dispone de un único rol funcional:

* herramienta;
* equipamiento;
* accesorio;
* consumible;
* repuesto.

### TIPO

Cada producto dispone de un único tipo principal.

El TIPO representa **qué es el producto**, independientemente de la categoría comercial en la que esté publicado.

### APLICACIÓN

Permite describir **para qué se utiliza** el producto.

Un producto puede disponer de varias aplicaciones cuando sea necesario.

### PLATAFORMA

Permite representar ecosistemas tecnológicos o plataformas compatibles, especialmente útil para familias de herramientas a batería.

Ejemplos:

* Makita LXT 18V;
* Bosch Professional 18V;
* DeWALT XR 18V;
* Milwaukee M18;
* HiKOKI 18V / Multi Volt;
* Metabo 18V;
* Festool 18V;
* FEIN AMPShare 18V.

### SUBTIPO

Permite añadir una clasificación secundaria cuando existe una diferenciación reutilizable que aporta información adicional respecto al TIPO.

## Separación entre semántica y atributos técnicos

* Los atributos técnicos dejaron de tratarse como etiquetas semánticas.
* Potencia, voltaje, dimensiones, capacidad, peso y características similares permanecen como atributos.
* La semántica describe qué es y para qué sirve el producto.
* Los atributos describen sus características técnicas.

## Migración del sistema legacy

* Inicio de la retirada del antiguo sistema basado en keywords.
* Conversión del antiguo concepto de `ámbito` al nuevo ROL canónico.
* Sincronización del ámbito de atributos con el nuevo modelo.
* Eliminación progresiva de inconsistencias entre TIPO y ROL.
* Preparación de procesos de backup y rollback antes de las migraciones masivas.
* El frontend comenzó a priorizar las etiquetas canónicas frente a las keywords antiguas.
* Se mantuvo temporalmente un fallback legacy para los productos que todavía no disponían de suficiente información canónica.

## Validación sobre catálogo real

La arquitectura semántica se validó sobre un catálogo superior a **13.000 productos**, permitiendo comprobar su funcionamiento a una escala real de WooCommerce.

Se alcanzó cobertura completa de TIPO y ROL y se comenzó la incorporación progresiva de APLICACIÓN, PLATAFORMA y SUBTIPO.

---

# 2026-08 — Marketing y relaciones editoriales

Se separó definitivamente la **estructura SEO real** de las decisiones editoriales sobre qué contenido mostrar en cada página.

## Jerarquía estructural

La estructura SEO se formalizó como:

```text
Cluster
└── Hub primario
    └── Hub secundario
        └── Categoría WooCommerce
            └── Productos
```

Las relaciones se almacenan explícitamente, en lugar de deducir toda la arquitectura exclusivamente de la jerarquía nativa de categorías de WooCommerce.

## Categorías editoriales

* Posibilidad de seleccionar categorías que se muestran como tarjetas o enlaces dentro de clusters.
* Posibilidad de seleccionar categorías visibles dentro de hubs primarios.
* Estas relaciones editoriales no modifican la jerarquía SEO estructural.
* La presentación queda separada de la arquitectura.

## Selección automática

* Desarrollo de herramientas para recorrer la rama estructural real.
* Detección de las categorías pertenecientes a cada cluster o hub.
* Posibilidad de recomendar automáticamente categorías relevantes.
* Priorización de categorías con mayor número de productos dentro de su propia rama.
* Evitación de recomendaciones de categorías externas a la estructura correspondiente.

---

# 2026-08 — Importación y exportación

## Exportación de productos

Se amplió el sistema de exportación de WooCommerce para incluir información procedente de distintas capas.

La exportación puede incorporar:

* ID;
* título;
* slug;
* estado;
* categorías;
* extracto;
* descripción;
* imagen;
* ROL;
* TIPO;
* APLICACIÓN;
* PLATAFORMA;
* SUBTIPO;
* atributos técnicos;
* información de marca.

## Exportación por jerarquía

Se añadieron filtros de exportación por:

* cluster;
* hub primario;
* hub secundario;
* categoría WooCommerce;
* estado del producto.

Esto permite trabajar con subconjuntos coherentes del catálogo en lugar de exportar siempre todos los productos.

## Importación por lotes

* Creación de una cola de importación.
* Procesamiento de archivos por lotes.
* Gestión de archivos pendientes.
* Gestión de archivos procesados.
* Gestión de archivos fallidos.
* Posibilidad de pausar el procesamiento.
* Posibilidad de reintentar archivos fallidos.
* Protección mediante bloqueos para evitar ejecuciones simultáneas.
* Continuación controlada del procesamiento cuando el servidor no ejecuta automáticamente una acción programada.

---

# 2026-08 — Sistema de proveedores

El antiguo flujo de importación evolucionó hacia un subsistema específico para catálogos de proveedores.

## Motor de proveedores

* Creación de un motor común de importación.
* Creación de catálogo interno de proveedores.
* Incorporación de acciones administrativas.
* Gestión de conexiones de proveedores.
* Estandarización del formato de entrada.

## Sincronización de proveedores

* Integración del sistema de sincronización directamente en el catálogo de proveedores.
* Comparación entre datos del proveedor y datos existentes en WooCommerce.
* Gestión de estados de sincronización.
* Preparación de acciones para aplicar cambios.
* Centralización de la lógica de sincronización.

## Importadores específicos

* Desarrollo de recetas de importación.
* Incorporación de importador específico para Amazon.
* Incorporación de exploradores y herramientas auxiliares.
* Posibilidad de utilizar archivos obtenidos externamente.

## Automatización externa

* Desarrollo de flujos capaces de ejecutar scrapers externos.
* Uso de Python para proveedores cuya información necesita procesamiento fuera de WordPress.
* Integración con GitHub Actions para ejecutar determinados procesos externos.
* Conversión del resultado a un CSV estándar.
* Envío de los resultados de vuelta a WordPress mediante callbacks controlados.
* Entrega posterior de esos datos al motor común de sincronización.

Esto permite mantener WordPress como receptor y gestor del catálogo sin obligarlo a realizar procesos de scraping pesados.

---

# 2026-08 — Buscador de productos

Se desarrolló y evolucionó un buscador específico para WooCommerce.

## Funcionalidades

* Búsqueda por título.
* Búsqueda por contenido.
* Búsqueda por SKU.
* Búsqueda por categorías.
* Búsqueda mediante vocabulario semántico.
* Búsqueda mediante atributos.
* Autocompletado.
* Tolerancia a errores tipográficos.
* Sinónimos configurables.
* Paginación.
* Registro de búsquedas.

## Filtros

* Categoría.
* Vocabulario semántico.
* Marca.
* Atributos.
* Precio.
* Stock.

## Presentación

* Resultados en cuadrícula.
* Número configurable de columnas.
* Imagen, precio, stock, SKU y categoría configurables.
* Shortcode reutilizable en páginas y componentes del sitio.

---

# 2026-08 — Diagnóstico y control de calidad

Se desarrolló un sistema de diagnóstico progresivamente más amplio para poder auditar el plugin y el sitio desde el propio sistema.

## Diagnóstico del código

* Inventario de archivos PHP.
* Comprobación de legibilidad.
* Verificación de sintaxis.
* Detección de funciones duplicadas.
* Detección de clases duplicadas.
* Inventario de hooks.
* Verificación de callbacks.
* Inventario de acciones AJAX.
* Inventario de rutas REST.
* Inventario de tareas Cron.
* Detección de posibles puntos de entrada y referencias internas.

## Diagnóstico del plugin

* Verificación de archivos necesarios.
* Verificación de módulos cargados.
* Verificación de plantillas.
* Verificación del sistema de importación/exportación.
* Verificación del motor de proveedores.
* Verificación del sistema de sincronización.
* Verificación de dependencias internas.

## Diagnóstico WooCommerce

* Comprobación de productos.
* Comprobación de categorías.
* Comprobación de tienda.
* Comprobación de carrito.
* Comprobación de checkout.
* Comprobación de métodos de pago.
* Comprobación de métodos de envío.
* Comprobación de correos WooCommerce.

## Diagnóstico frontend

* Comprobación de portada.
* Comprobación de tienda.
* Comprobación de categorías.
* Comprobación de productos.
* Comprobación de búsqueda.
* Comprobación de 404.
* Verificación del renderizado de plantillas.
* Comprobación de recursos CSS y JavaScript.

## Diagnóstico SEO

* Títulos HTML.
* Canonical.
* Meta robots.
* H1.
* Datos estructurados JSON-LD.
* Sitemap.
* Indexabilidad.
* Enlazado interno.
* Redirecciones.
* Detección de posibles soft-404.

## Diagnóstico editorial

* Calidad de categorías.
* Cobertura de descripciones.
* Duplicidad de contenido de producto.
* Alineación producto-categoría.
* Calidad de atributos.
* Integridad de FAQs.
* Cobertura de FAQs.
* Cobertura de clusters y hubs.
* Identificación de elementos que requieren revisión editorial.

## Análisis de anomalías

* Desarrollo de puntuaciones para detectar productos potencialmente problemáticos.
* Análisis de:

  * título;
  * metadatos;
  * contenido;
  * jerarquía;
  * semántica.
* Agrupación de resultados siguiendo toda la estructura:

  * cluster;
  * hub primario;
  * hub secundario;
  * categoría;
  * producto.

---

# 2026-08 — Data Layer, auditoría y rollback

Se inició una capa de operaciones destinada a hacer más seguras las modificaciones masivas.

## Auditoría de operaciones

* Registro de operaciones.
* Registro de cambios asociados a cada operación.
* Identificación del origen de los cambios.
* Uso de hashes para comprobar identidad e integridad.
* Creación de snapshots.
* Asociación de cambios con una operación concreta.

## Rollback

* Preparación de estados de rollback.
* Posibilidad de conservar información necesaria para deshacer procesos.
* Incorporación de backups antes de determinadas migraciones importantes.
* Filosofía de evitar modificaciones masivas irreversibles.

Esta capa adquiere especial importancia al trabajar con catálogos de miles de productos.

---

# Fase anterior — De las categorías a una arquitectura SEO propia

## Primera arquitectura basada en categorías

La primera versión conceptual del proyecto utilizaba las categorías de WooCommerce como principal estructura jerárquica.

La jerarquía de `product_cat` servía simultáneamente para:

* organizar el catálogo;
* agrupar productos;
* construir navegación;
* establecer relaciones entre niveles;
* definir buena parte de la arquitectura SEO.

Este enfoque permitió construir rápidamente una primera estructura funcional.

Con la evolución del proyecto quedó claro que la organización comercial del catálogo y la arquitectura SEO necesitaban poder evolucionar de forma independiente.

Esto llevó al desarrollo de una capa específica de relaciones SEO.

---

# Fase anterior — Cluster, hubs y categorías

Se introdujo un modelo estructural independiente basado en distintos niveles.

## Cluster

Nivel superior de agrupación temática.

Representa grandes áreas del catálogo y sirve como punto de entrada a ramas completas de contenido.

## Hub primario

Segundo nivel de la arquitectura.

Organiza grandes subtemas dentro de un cluster.

## Hub secundario

Nivel intermedio más específico.

Conecta los hubs primarios con las categorías reales de WooCommerce.

## Categoría

Las categorías de WooCommerce se mantienen como parte fundamental del catálogo, pero dejan de ser por sí solas toda la arquitectura SEO.

## Relaciones explícitas

Se crearon relaciones específicas para representar:

```text
cluster → hub primario
hub primario → hub secundario
hub secundario → categoría
```

Este cambio fue uno de los principales puntos de evolución del proyecto: la estructura dejó de depender únicamente del árbol nativo de categorías de WooCommerce.

---

# Fase anterior — Integración con Divi

En las primeras etapas, gran parte de la presentación del proyecto se construyó utilizando **Divi**.

## Uso inicial

* Construcción de páginas mediante Divi.
* Uso de módulos visuales para presentar contenido.
* Inserción de funcionalidades mediante shortcodes.
* Integración de componentes dinámicos dentro del constructor.
* Utilización de Divi como capa principal de presentación.

Este enfoque permitió iterar rápidamente sobre el diseño mientras se definía la arquitectura SEO.

## Evolución posterior

A medida que aumentó la lógica del sistema se trasladaron responsabilidades desde el constructor visual hacia el propio plugin.

La lógica de:

* jerarquía;
* relaciones;
* selección de contenido;
* plantillas;
* productos;
* categorías;
* navegación;

pasó progresivamente a estar controlada por SEO Taxonomy.

El objetivo pasó a ser que el plugin no dependiera de Divi para comprender ni ejecutar su arquitectura.

---

# Fase anterior — Independencia de Divi y plantillas nativas

La siguiente evolución fue separar completamente:

```text
Datos y arquitectura
        ↓
SEO Taxonomy
        ↓
Sistema de plantillas
        ↓
Tema de WordPress
```

en lugar de:

```text
Datos
  ↓
Divi
  ↓
Lógica de presentación y navegación
```

Esto permitió:

* utilizar otros temas de WordPress;
* mantener la arquitectura aunque se cambiara de constructor;
* controlar directamente las plantillas;
* reducir lógica duplicada;
* separar diseño de estructura;
* facilitar el mantenimiento futuro;
* convertir el sistema en un plugin reutilizable y no en una solución ligada a un sitio concreto.

---

# Evolución arquitectónica resumida

La evolución global del proyecto puede resumirse así:

```text
Categorías WooCommerce como jerarquía
                ↓
Páginas y presentación mediante Divi
                ↓
Clusters y hubs añadidos sobre el catálogo
                ↓
Relaciones SEO almacenadas explícitamente
                ↓
Separación entre jerarquía SEO y categorías
                ↓
Sistema de plantillas propio
                ↓
Independencia de Divi
                ↓
Importación / exportación avanzada
                ↓
Motor de proveedores
                ↓
Sincronización de proveedores
                ↓
Vocabulario semántico de productos
                ↓
TIPO + ROL + APLICACIÓN + PLATAFORMA + SUBTIPO
                ↓
Relaciones editoriales y marketing
                ↓
Buscador y filtros semánticos
                ↓
Diagnóstico integral
                ↓
Data Layer + auditoría + rollback
                ↓
GitHub + Issues + colaboración abierta
```

---

# Estado actual

SEO Taxonomy ha evolucionado desde una solución específica para organizar un catálogo WooCommerce hasta convertirse en un sistema que intenta centralizar varias capas relacionadas pero independientes:

* arquitectura SEO;
* jerarquía de contenidos;
* catálogo WooCommerce;
* semántica de productos;
* atributos técnicos;
* relaciones editoriales;
* plantillas;
* búsqueda;
* importación y exportación;
* proveedores;
* sincronización de catálogos;
* diagnóstico;
* auditoría;
* control de calidad;
* seguridad de operaciones y rollback.

El objetivo actual es continuar desacoplando estas capas, documentarlas y hacer que el sistema pueda ser comprendido, utilizado y ampliado por desarrolladores externos sin necesidad de conocer la historia interna del proyecto.


