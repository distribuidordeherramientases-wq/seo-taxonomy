# Categorías

Ruta: **SEO Taxonomy → Categorías**

## Pestañas

- Categorías
- Informe categorías
- Reasignación de Categorías
- Informes Google
- Inventario
- Tabla catálogo

## Categorías

### Crear nueva categoría

Campos visibles:

- **Nombre**: nombre de la categoría.
- **Excerpt SEO**: resumen corto.
- **Descripción**: contenido SEO largo.
- **Etiquetas SEO**: términos de apoyo.
- **Ámbito**: clasificación de uso.
- **Crear categoría** [Guarda]: da de alta la categoría con los datos indicados.

La caja **Guía para crear nuevas categorías SEO** es ayuda contextual; abrirla no modifica datos.

### Edición de cada categoría

Cada tarjeta muestra la URL actual, Cluster, Hub primario, Hub secundario, ID y productos asociados.

Controles:

- **Grabar esta categoría** [Guarda]: guarda la tarjeta actual.
- **Slug**: permite editar la parte de URL.
- **Modificar slug** [Modifica][Producción]: pide confirmación y crea una redirección 301 desde la URL anterior.
- **Ver productos** [Consulta]: abre el listado de productos asociados.
- **Nombre de la Categoría** [Guarda].
- **Excerpt SEO** [Guarda].
- **Etiquetas SEO** [Guarda].
- **Descripción (Contenido SEO)** [Guarda].

### Eliminar categoría

El borrado de una `product_cat` utiliza el servicio canónico de categorías:

1. resuelve la relación `hub_secondary_to_category`;
2. exige un único Hub secundario publicado;
3. elimina el término mediante la API de WordPress/WooCommerce;
4. crea o actualiza automáticamente un redirect 301 desde la URL de la categoría eliminada hacia el Hub secundario;
5. registra el redirect y limpia relaciones, nodos, Vocabulary y FAQs mediante SEO Data Layer.

Si no existe un Hub secundario publicado único o el redirect produciría un ciclo, el borrado se bloquea para evitar dejar una URL huérfana.

**Eliminar Categoría** [Elimina][Producción] no requiere elegir manualmente el destino del 301.

**Guardar todos los cambios** [Guarda] persiste las ediciones realizadas en las tarjetas.

## Informe categorías

Informe estructural de categorías. Sirve para localizar carencias, relaciones incoherentes y cobertura. Las señales del informe son diagnósticos; una corrección debe revisarse antes de aplicarla.

## Reasignación de Categorías

Tabla:

- Categoría.
- Hub Actual.
- **Reasignar a…**: selector de nuevo Hub secundario.
- **Modificar** [Modifica]: mueve la relación estructural de esa categoría.

## Equilibrar categorías desde Auditor

Ruta: **SEO Taxonomy → Contenidos → Auditor → Equilibrar categorías**.

La vista separa dos niveles:

- **Revisión por tamaño**: avisa de categorías fuera del objetivo operativo aunque todavía no exista una solución semántica segura.
- **Propuesta ejecutable**: aparece solo cuando Auditor dispone de evidencia suficiente para proponer una división o una concentración concreta.

Para división:

- las categorías con más de 10 productos aparecen como revisión por tamaño;
- una propuesta ejecutable exige cohortes TIPO/SUBTIPO suficientemente diferenciadas;
- los productos ambiguos o sin asignación segura permanecen en la categoría origen.

Para concentración:

- las categorías con 1–4 productos aparecen como revisión por tamaño;
- una propuesta ejecutable exige una categoría hermana suficientemente parecida en nombre o Vocabulary;
- no se fusiona una categoría únicamente por ser pequeña.

Las filas de diagnóstico solo permiten **Abrir categoría** [Consulta]. No mueven productos ni modifican la taxonomía.

Las propuestas ejecutables conservan el flujo:

1. revisar;
2. aprobar o descartar;
3. aplicar de forma explícita;
4. revalidar antes de mover productos.

Al concentrar una categoría, el sistema valida que el origen pueda eliminarse y, cuando se elimina, crea automáticamente el redirect 301 hacia su Hub secundario mediante el mismo servicio canónico.

## Informes Google

Muestra el informe Google específico de categorías y permite revisar visibilidad y señales de rendimiento disponibles para cada categoría.

## Inventario

El inventario editorial por categoría puede mostrar:

- Categoría;
- Jerarquía superior;
- Vocabulary / etiquetas;
- FAQs;
- Posts;
- Landings;
- Productos;
- Carga URL.

**Ordenar inventario** y **Ordenar** cambian la vista [Consulta].

## Tabla catálogo

Título: **Inventario real categorías ↔ productos**.

Botón **Descargar JSON** [Exporta] genera el inventario.

KPIs visibles incluyen categorías, categorías con productos, vacías, productos reales, publicados, sin categoría, asignaciones, multicategoría, categorías por producto y descuadres WooCommerce.

Filtros:

- Buscar categoría / producto / SKU.
- Estado: Todas / Con productos / Vacías.
- Orden: más o menos productos, más publicados, nombre A-Z o Z-A.
- Por página: 25/50/100.
- **Aplicar** [Consulta].
- **Limpiar** [Consulta].

Tabla: Categoría, Ruta, Reales, Publicados, No publicados, Contador Woo y Productos asignados directamente. Los enlaces **Editor SEO**, **Editar WC** y **Ver** llevan a las vistas correspondientes.


## Bloques contextuales en la categoría pública

Después del catálogo/contenido propio y antes de las familias/afiliados, la plantilla puede mostrar:

1. **Comparativa**: extracto del post canónico publicado de Comparador.
2. **Preguntas habituales**: posts `publish` relacionados con el `term_id` de la `product_cat` actual mediante `seo_relations.relation_type = post_to_category` y marcados con `_seo_solucionador_content_role = dependiente_qa_basic`.
3. **Comentarios externos**: comentarios `published` y `content_type = comment` de Comentarista sobre productos publicados de la categoría o sus hijas; máximo seis, con máximo dos por producto.
4. **Información técnica**: posts `publish` relacionados mediante `post_to_category` y marcados con `_seo_solucionador_content_role = ingeniero_qa_specialized`.

La selección editorial **no usa categorías WordPress del blog, etiquetas, nombres, slugs ni coincidencias de texto**. La categoría pública usa exclusivamente su `term_id` WooCommerce para Preguntas habituales e Información técnica.

Los posts se presentan como título + extracto + enlace, con dos tarjetas por fila en escritorio y una en móvil. Los comentarios externos se mantienen plegados inicialmente mediante `details`, conservando fuente, autor y valoración cuando están disponibles.

Las plantillas son de solo lectura: no consultan servicios externos ni recalculan conocimiento. Comparador sólo lee su resultado público persistido. Un bloque sin contenido válido no genera título, contenedor ni hueco visual. Soluciones/Landings no se incorporan todavía a este bloque.
