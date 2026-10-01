# Contenidos

Ruta: **SEO Taxonomy → Contenidos**

La pantalla **Contenidos** agrupa los editores que se utilizan con más frecuencia para mantener el catálogo y el contenido editorial. Su objetivo es reducir el número de entradas visibles en el menú principal sin modificar la lógica ni las rutas internas de cada módulo.

## Tarjetas

| Tarjeta | Qué abre | Acción |
|---|---|---|
| Productos | Gestión de productos, inventario, edición, informes y recategorización | **Abrir** [Consulta] |
| Categorías | Categorías, contenido SEO, relaciones e inventarios | **Abrir** [Consulta] |
| Páginas | Hubs, landings, páginas corporativas y estructura editorial | **Abrir** [Consulta] |
| Entradas | Posts, guías, comparativas, oportunidades y contenido editorial | **Abrir** [Consulta] |
| Imágenes | Inventario, anomalías, optimización y asignación de imágenes | **Abrir** [Consulta] |
| Solucionador | Propuestas editoriales y borradores a partir de señales internas del catálogo y otros servicios | **Abrir** [Consulta] |
| Auditor | Calidad, coherencia y arquitectura de productos, categorías, páginas, entradas, FAQs e índice | **Abrir** [Consulta] |

El botón **Abrir** solo navega al editor correspondiente.

## Compatibilidad

Las pantallas internas conservan sus slugs administrativos históricos:

- Productos: `product-page-admin`
- Categorías: `category-seo-admin`
- Páginas: `seo-page-admin`
- Entradas: `seo-post-editor`
- Imágenes: `seo-pictures-admin`

Esto permite mantener enlaces internos, formularios y redirecciones existentes. Cuando se abre cualquiera de estas pantallas, WordPress mantiene **Contenidos** como sección activa del menú de SEO Taxonomy.

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
