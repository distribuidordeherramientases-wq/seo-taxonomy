# Contenidos

Ruta: **SEO Taxonomy → Contenidos**

La pantalla **Contenidos** agrupa los editores que se utilizan con más frecuencia para mantener el catálogo y el contenido editorial. Su objetivo es reducir el número de entradas visibles en el menú principal sin modificar la lógica ni las rutas internas de cada módulo.

## Tarjetas

| Tarjeta | Qué abre | Acción |
|---|---|---|
| Productos | Gestión de productos, inventario, edición y recategorización | **Abrir** [Consulta] |
| Categorías | Categorías, contenido SEO, relaciones e inventarios | **Abrir** [Consulta] |
| Páginas | Hubs, landings, páginas corporativas y estructura editorial | **Abrir** [Consulta] |
| Entradas | Edición y gestión de posts, guías, comparativas y contenido editorial | **Abrir** [Consulta] |
| Imágenes | Inventario, anomalías, optimización y asignación de imágenes | **Abrir** [Consulta] |
| Solucionador | Diagnóstico y decisión editorial centralizada; unifica informes, prioriza actuaciones y prepara briefs | **Abrir** [Consulta] |
| Auditor | Calidad, coherencia y arquitectura de productos, categorías, páginas, entradas, FAQs e índice | **Abrir** [Consulta] |
| FAQs | Gestión de preguntas frecuentes, cobertura, calidad e interacción | **Abrir** [Consulta] |

El botón **Abrir** solo navega al editor correspondiente.

## Un único punto de análisis editorial

La información estadística/editorial visible se centraliza en **Solucionador → Diagnóstico editorial**.

Por tanto:

- **Entradas** conserva edición y ejecución; sus oportunidades, rendimiento y errores se visualizan desde Solucionador.
- **Páginas** conserva estructura, landings y páginas corporativas; el informe de landings y los errores se visualizan desde Solucionador.
- **Categorías** conserva edición, reasignación, inventario y tabla real catálogo-productos; los informes de estructura y Google se visualizan desde Solucionador.
- **Imágenes** sigue siendo fuente e instrumento de ejecución para inventario, asignación, optimización y limpieza. Sus señales pueden alimentar el diagnóstico editorial sin convertir el editor de imágenes en un segundo sistema de decisión.

La centralización no elimina las funciones que calculan los informes. **Se reutilizan como fuentes internas**, evitando mantener dos implementaciones del mismo cálculo.

## Compatibilidad

Las pantallas internas conservan sus slugs administrativos históricos:

- Productos: `product-page-admin`
- Categorías: `category-seo-admin`
- Páginas: `seo-page-admin`
- Entradas: `seo-post-editor`
- Imágenes: `seo-pictures-admin`
- FAQs: `seo-faq`

Esto permite mantener enlaces internos, formularios y redirecciones existentes. Cuando se abre cualquiera de estas pantallas, WordPress mantiene **Contenidos** como sección activa del menú de SEO Taxonomy.

## FAQs

El acceso visible a FAQs está en **SEO Taxonomy → Contenidos → FAQs**. La pantalla conserva el slug administrativo histórico `seo-faq`; sólo cambia su ubicación dentro del lanzador.

Pestañas: **Hubs SEO**, **Categorías**, **Productos** e **Informe**.

En edición se puede seleccionar el elemento, crear una **Nueva FAQ**, ordenar, editar Pregunta/Respuesta, activar o desactivar y Guardar/Actualizar.

La pestaña **Informe** permite filtrar por Buscar, Nivel, Estado, Diagnóstico, renders, aperturas y orden. Incluye KPIs de cobertura, calidad, diagnóstico editorial e interacción, además de las limpiezas de copias y huérfanas ya existentes.

Mover el acceso a Contenidos **no modifica** tablas, datos, informes, handlers ni la lógica de FAQs.

## Auditor de contenidos

El Auditor de datos se accede desde **SEO Taxonomy → Contenidos → Auditor**. Permite ejecutar auditorías independientes de Productos, Categorías, Posts, Páginas, FAQs y Motor/índice, además de la auditoría global del catálogo y la vista de Calidad SEO.

La auditoría de **Academia / Estudiante** queda separada dentro de **Dependiente → Auditor Academia**.

### Equilibrar categorías

La subvista **Equilibrar categorías** distingue entre diagnóstico y ejecución:

- **Revisar por división**: categorías por encima del tamaño objetivo, aunque todavía no haya cohortes TIPO/SUBTIPO suficientes para proponer cómo dividir.
- **Propuestas de división**: subconjunto con evidencia semántica suficiente y destinos/cohortes revisables.
- **Revisar concentración**: categorías de 1–4 productos, aunque todavía no exista una hermana suficientemente parecida.
- **Propuestas de concentración**: subconjunto con un destino sugerido suficientemente similar.

Un contador a cero en **Propuestas** no significa que la taxonomía esté equilibrada; los contadores de **Revisar** muestran los casos que merecen atención aunque Auditor todavía no pueda proponer una operación segura.

Las filas diagnósticas son de solo lectura. Solo una propuesta aprobada puede ejecutarse.
