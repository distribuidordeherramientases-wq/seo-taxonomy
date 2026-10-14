# Editor · Contenidos (piloto STAGING)

Ruta: **TaxoCommerce SEO → Contenidos → Editor**. La tarjeta de Editor se sitúa en primer lugar.

## Objetivo y límites

Editor es una bandeja única de **borradores reales de WordPress**, clasificados por tipo y origen, sin construir otro motor de búsqueda ni duplicar las tablas de conocimiento. Lee los `post` con estado `draft` y las `page` con rol de landing comercial. No muestra páginas corporativas, clusters, hubs ni productos WooCommerce.

Esta primera entrega **NO convierte automáticamente en borradores** todos los expedientes pendientes de Ingeniero, Solucionador o Comparador. Los motores conservan temporalmente sus flujos de preparación y sus botones anteriores. Tampoco consolida aún dos posts de distintos motores sobre el mismo tema; es la siguiente fase de integración. El piloto sí permite **recibir, editar, publicar o descartar los borradores que esos servicios ya hayan creado**.

## Indicadores

- **Borradores totales:** posts y landings en la bandeja limitada.
- **Posts:** entradas WP `post` en `draft`.
- **Landings:** páginas WP `page` en `draft` con rol comercial `landing` o metadato de candidato editorial.
- **Aptos para revisión:** superan las verificaciones técnicas mínimas; no equivale a contenido validado editorialmente.
- **Necesitan completar:** no superan alguna de esas verificaciones.

Límites de lectura: **últimos 250 posts** y **últimas 125 landings**, ordenados por modificación; **30 filas por página**. Es una vista acotada, no un recuento estadístico del inventario completo si hay más borradores.

## Tabla y filtros

- **Tipo:** Todos, Posts, Landings.
- **Origen:** Todos, Solucionador, Ingeniero, Comparador, Landings, Noticias, Manual o Externo.
- **Buscar:** busca por título o ID entre los borradores cargados.
- **Filtrar / Limpiar** [Consulta]: cambia la selección sin modificar contenido.
- **Seleccionar todos los visibles** [Consulta]: marca los checkboxes de la página actual.
- **ID / Tipo:** ID WP y sección editorial (guía, comparativa, noticia o landing).
- **Título y contenido:** enlace a editor original; extracto y detalle plegable **Leer material recibido**.
- **Origen:** identificado por metadatos existentes del expediente, no por textos del artículo.
- **Categorías:** número de relaciones `product_cat`.
- **Comprobación:** `Apto para revisar` o `Completar`, con motivo.
- **Editar** [Navega]: abre el editor de posts o páginas existente, sin borrar metadatos.
- **Publicar** [Producción]: si está técnicamente listo y el usuario confirma la revisión, cambia `draft → publish`.
- **Papelera** [Elimina de la cola]: cambia `draft → trash` con `wp_trash_post`, sin borrar definitivamente.
- **Publicar seleccionados** [Producción]: publica los IDs seleccionados que cumplan requisitos y deja los no válidos sin cambios.
- **Publicar todos los aptos de esta página** [Producción]: procesa hasta 30 filas visibles y únicamente las que cumplen las reglas.
- **Papelera seleccionados** [Elimina de la cola]: mueve los IDs seleccionados a papelera, máximo 30 por envío.
- **He revisado personalmente...** [Confirmación requerida]: debe marcarse antes de cualquiera de las operaciones anteriores.

Los servicios no se ejecutan al visitar Editor. Ningún botón de la tabla investiga en APIs externas. WordPress conserva los callbacks de `transition_post_status` de Solucionador/Ingeniero/Comparador y sus vínculos con los dossiers originales.

## Validaciones de publicación

Las comprobaciones automáticas básicas requieren título con al menos 15 caracteres, contenido legible con 300 caracteres o más, ausencia de determinadas frases internas de brief y material sin terminar, y relaciones comerciales: mínimo una `post_to_category` en guías/comparativas, y al menos dos `landing_to_category` en una landing. Noticias pueden prescindir de relación comercial.

**No garantizan exactitud técnica, completitud SEO o ausencia de canibalización.** Una persona debe revisar además fuentes, título, enlaces, intención, legalidad y calidad del contenido. Los briefs de Solucionador e Ingeniero contienen indicaciones internas que bloquean la publicación directa hasta su reescritura. Las acciones masivas no sortean las validaciones.

## Importar / exportar CSV

**Descargar todo (análisis)** [Exporta]: descarga hasta 250 posts y 125 landings en un CSV UTF-8 con separador `;`. Adecuado para análisis externo, incluido ChatGPT.

**Lote 1–4** [Exporta]: descarga tandas de hasta **100 filas** que se pueden editar y reimportar. El lote se determina por orden de modificación en el momento de exportar; el ID y el hash permiten volver a identificar cada borrador incluso si cambia su orden.

Columnas exactas:

| Columna | Significado | Edición CSV |
|---|---|---|
| `id` | ID WordPress. Vacío = nuevo borrador externo | Conservar en registros existentes |
| `tipo` | `post` o `landing` | No modificar en registros existentes |
| `origen` | Servicio fuente (diagnóstico) | Solo informativa; las filas nuevas se etiquetan `Externo` |
| `seccion` | `guia`, `comparativa`, `noticia` o `landing` | No modificar en registros existentes |
| `titulo` | Título público propuesto | Editable |
| `extracto` | Extracto/introducción breve | Editable |
| `contenido` | Texto con HTML editorial permitido | Editable |
| `categoria_ids` | IDs numéricos de `product_cat`, separados por comas | Editable, se validan todas |
| `notas_editor` | Observaciones privadas para el trabajo editorial | Editable |
| `revision_hash` | Huella de versión del borrador | Conservar en existentes; vacío en nuevos |

### Añadir propuestas desde ChatGPT u otra fuente

1. Descargar un lote y conservar **orden y nombres exactos de columnas**.
2. Añadir nuevas filas con `id` y `revision_hash` vacíos; especificar `tipo`, `seccion`, `titulo`, `contenido` y categorías comerciales.
3. Para nuevos posts: `guia`, `comparativa` o `noticia`; para páginas comerciales: `tipo=landing; seccion=landing` y mínimo dos categorías de producto reales.
4. Mantener URLs verificables y referencias en el contenido o en `notas_editor`; una recomendación externa no se considera verdad automáticamente.
5. Guardar CSV UTF-8 con separación `;`, texto entrecomillado cuando incluya separador/salto de línea.

**Validar y previsualizar** [Consulta]: acepta `.csv` o `.txt`, máximo **2 MB / 100 filas**. Comprueba encabezados, tipos, títulos, contenido, relación de categorías, duplicaciones de IDs, contenido de archivo y huella de versión. No actualiza WordPress.

**Vista previa** [Consulta]: enseña fila, operación prevista, tipo, título, campos modificados y errores. Si hay errores, **ninguna fila** puede confirmarse. Caduca en **10 minutos** y pertenece al usuario que la cargó.

**Confirmar importación (sin publicar)** [Guarda]: requiere confirmación expresa. Vuelve a verificar todas las filas antes de aplicar. Modifica borradores existentes o crea nuevos con `post_status=draft`. No publica ni envía a papelera. Si se produce un error inesperado de base de datos durante la aplicación, comunica la fila afectada; los cambios que ya se hayan aplicado quedan guardados.

La importación **rechaza modificar posts publicados**, no usa comandos de estado incluidos en CSV, no da de alta categorías desconocidas de producto y no permite transformar `post` en `landing` mediante CSV. Se preserva la procedencia original en posts ya existentes.

## Siguiente fase (no incluida)

- Conectar automáticamente los expedientes de Solucionador, Ingeniero y Comparador a Editor sin tener que utilizar sus botones de aprobación antiguos.
- Consolidar propuestas equivalentes antes de crear borradores nuevos, manteniendo todas las fuentes.
- Incorporar propuestas comerciales de Ojeador cuando proceda, sin fabricar posts automáticamente.
- Cuando el circuito nuevo quede validado, ocultar los flujos antiguos de aprobación, conservando investigación y diagnóstico.
