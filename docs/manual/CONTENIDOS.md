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

El acceso visible a FAQs está en **SEO Taxonomy → Contenidos → FAQs**. La pantalla conserva el slug administrativo histórico `seo-faq`; sólo cambia su ubicación dentro del lanzador.

Pestañas: **Hubs SEO**, **Categorías**, **Productos** e **Informe**.

En edición se puede seleccionar el elemento, crear una **Nueva FAQ**, ordenar, editar Pregunta/Respuesta, activar o desactivar y Guardar/Actualizar.

La pestaña **Informe** permite filtrar por Buscar, Nivel, Estado, Diagnóstico, renders, aperturas y orden. Incluye KPIs de cobertura, calidad, diagnóstico editorial e interacción, además de las limpiezas de copias y huérfanas ya existentes.

Mover el acceso a Contenidos **no modifica** tablas, datos, informes, handlers ni la lógica de FAQs.

## Auditor de contenidos

El Auditor de datos se accede desde **SEO Taxonomy → Contenidos → Auditor**. Ejecuta auditorías independientes y ligeras de **Productos, Categorías, Posts, Páginas/Landings y FAQs**. Los chequeos de motor/índice se realizan desde **Plugin Validation** y la antigua auditoría global de catálogo ya no se ofrece por su coste en catálogos grandes.

Cada auditoría genera, además de hallazgos y calidad, un **Plan de trabajo priorizado**. Auditor no ejecuta ni redacta cambios: clasifica cada hallazgo para que Editora reciba una cola accionable:

- **P1 · ATENDER PRIMERO**: la prioridad ya no implica por sí sola modificar contenido. Puede ser `CORREGIR_AHORA` cuando el error está confirmado o `VERIFICAR_AHORA` cuando necesita proveedor/fuente; en este último caso el estado es `NEEDS_SOURCE_VERIFICATION` y `ready_now=false`.
- **P2 · ESPERAR_ENRIQUECIMIENTO**: contenido insuficiente que debe esperar conocimiento de Ingeniero/Solucionador en vez de rellenarse con texto genérico.
- **P3 · MIGRAR_A_SOLUCIONADOR**: FAQs con intención útil de elección, uso, compatibilidad, mantenimiento, seguridad o problema real. Antes de retirar una FAQ se exige preservar `faq_id + object_type + object_id/category_id + pregunta + respuesta + hash + origen`.
- **P4 · REVISAR**: señales heurísticas que requieren criterio humano y no justifican modificación automática. Un bajo solapamiento literal de una categoría queda aquí salvo evidencia independiente fuerte de contenido cruzado.
- **P5 · INFORMATIVO**: métricas/contexto sin acción editorial directa.

Desde Auditor 0.12.x, `_seo_proveedor_id_externo` se audita con alcance **proveedor + ID externo**; el mismo valor en proveedores distintos no genera por sí solo un duplicado. GTIN/EAN/MPN conservan su semántica propia y no heredan automáticamente ese alcance.

La auditoría de FAQs incluye `faq_migration_inventory`, un inventario completo e independiente del límite visual de hallazgos, con clases `MIGRATE`, `RETIRE_CANDIDATE`, `REVIEW` e `INVALID_OWNER`. Auditor sigue siendo de solo lectura y ninguna FAQ útil queda autorizada para retirada mientras la preservación en Solucionador no esté confirmada.

La salida JSON incorpora `task_id`, `priority`, `priority_score`, `action_class`, `status`, `ready_now`, `requires_source_check`, `depends_on`, entidad, problema, evidencia, recomendación y `expected_impact`. También conserva `first_seen`, `last_seen`, `previous_status` y las tareas resueltas entre auditorías. `priority_queue_meta` y `findings_meta` declaran explícitamente totales y truncamiento; la UI puede mostrar una muestra mientras el JSON conserva la cola completa. El impacto de tráfico queda marcado como **pendiente de Analista** hasta que esa integración aporte métricas fiables.

La auditoría de **Academia / Estudiante** queda separada dentro de **Dependiente → Auditor Academia**.

### Equilibrar categorías

La subvista **Equilibrar categorías** distingue entre diagnóstico y ejecución:

- **Revisar por división**: categorías por encima del tamaño objetivo, aunque todavía no haya cohortes TIPO/SUBTIPO suficientes para proponer cómo dividir.
- **Propuestas de división**: subconjunto con evidencia semántica suficiente y destinos/cohortes revisables.
- **Revisar concentración**: categorías de 1–4 productos, aunque todavía no exista una hermana suficientemente parecida.
- **Propuestas de concentración**: subconjunto con un destino sugerido suficientemente similar.

Un contador a cero en **Propuestas** no significa que la taxonomía esté equilibrada; los contadores de **Revisar** muestran los casos que merecen atención aunque Auditor todavía no pueda proponer una operación segura.

Las filas diagnósticas son de solo lectura. Solo una propuesta aprobada puede ejecutarse.
