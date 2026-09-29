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
| Editor | Propuestas editoriales y borradores a partir de señales internas del catálogo y otros servicios | **Abrir** [Consulta] |
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
