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
| Solucionador | Diagnóstico y decisión editorial de Dependiente/Academia y señales comunes; prioriza actuaciones y prepara briefs | **Abrir** [Consulta] |
| Ingeniero | Investigación técnica por categoría, dossiers editoriales, briefs y borradores especializados | **Abrir** [Consulta] |
| Auditor | Calidad, coherencia y arquitectura de productos, categorías, páginas, entradas, FAQs e índice | **Abrir** [Consulta] |
| FAQs | Gestión de preguntas frecuentes, cobertura, calidad e interacción | **Abrir** [Consulta] |

El botón **Abrir** solo navega al editor correspondiente.

## Procesos editoriales coordinados

La arquitectura editorial dispone de tres procesos especializados:

- **Academia/Dependiente → Solucionador** para preguntas y necesidades aprendidas.
- **Ingeniero → Editorial** para conocimiento técnico trazable.
- **Ojeador → Comparador** para contenido comparativo.

Los tres pueden terminar en posts clasificados y comparten cobertura editorial. La medición global continúa en Analista.

Por tanto:

- **Entradas** conserva edición y ejecución; sus oportunidades, rendimiento y errores se visualizan desde Solucionador.
- **Páginas** conserva estructura, landings y páginas corporativas; el informe de landings y los errores se visualizan desde Solucionador.
- **Categorías** conserva edición, reasignación, inventario y tabla real catálogo-productos; los informes de estructura y Google se visualizan desde Solucionador.
- **Imágenes** sigue siendo fuente e instrumento de ejecución para inventario, asignación, optimización y limpieza.
- **Ingeniero** conserva investigación técnica y dispone de su propio workflow Editorial; no depende de Solucionador para proponer posts técnicos.

La centralización no elimina las funciones que calculan los informes. **Se reutilizan como fuentes internas**, evitando mantener dos implementaciones del mismo cálculo.

## Compatibilidad

Las pantallas internas conservan sus slugs administrativos históricos:

- Productos: `product-page-admin`
- Categorías: `category-seo-admin`
- Páginas: `seo-page-admin`
- Entradas: `seo-post-editor`
- Imágenes: `seo-pictures-admin`
- FAQs: `seo-faq`
- Ingeniero: `seo-ingeniero`

Esto permite mantener enlaces internos, formularios y redirecciones existentes. Cuando se abre cualquiera de estas pantallas, WordPress mantiene **Contenidos** como sección activa del menú de SEO Taxonomy.

## FAQs

La tarjeta **FAQs** abre **SEO Taxonomy → Contenidos → FAQs**.

El manual operativo completo está en [FAQs](FAQS.md) e incluye pestañas, filtros jerárquicos, búsqueda, selección múltiple, campos Pregunta/Respuesta/Orden/Activa, creación, edición, activación, borrado, informe, duplicados y huérfanas.

## Auditor de contenidos

La tarjeta **Auditor** abre **SEO Taxonomy → Contenidos → Auditor**.

El manual operativo completo está en [Auditor](AUDITOR.md) e incluye cada bloque de auditoría, botones Auditar/Repetir/Ver último/JSON, métricas, prioridades y la separación respecto a Auditor Academia y Plugin Validation.

