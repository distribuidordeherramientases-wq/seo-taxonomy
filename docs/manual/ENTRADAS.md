# Entradas

Ruta: **SEO Taxonomy → Entradas**

## Pestañas

- Editar posts
- Solucionador
- Ingeniero
- Oportunidades posts
- Errores

## Editar posts

### Editor

Campos:

- Título.
- Slug.
- Estado.
- Excerpt.
- Contenido.
- Categorías de producto.
- Etiquetas semánticas.
- **Uso en plantillas / Rol público**: contenido general, **Dependiente · preguntas habituales** o **Ingeniero · información técnica**. El rol se guarda en `_seo_solucionador_content_role` y solo se usa para bloques públicos contextuales; no sustituye Vocabulary ni la relación con product_cat.

Controles:

- **Marcar visibles** [Consulta]: marca los elementos visibles del selector.
- **Desmarcar visibles** [Consulta]: limpia esas selecciones.
- **Guardar** [Guarda][Producción si el post está publicado].
- **Enviar a la papelera** [Elimina]: retira la entrada del flujo editorial activo.

### Listado

Filtros:

- Buscar.
- Categoría de producto.
- Estado.
- **Filtrar** [Consulta].

Tabla: ID, Entrada, Categorías de producto, Vocabulary, Puntuación Google 28 días, Estado, Modificada y Acciones.

## Solucionador

Muestra únicamente los borradores de Solucionador que conservan sus metadatos editoriales propios y una relación `post_to_category`.

El filtro de pertenencia usa metadatos de Solucionador; el **rol público** almacenado en `_seo_solucionador_content_role` es independiente y no sustituye ni al Vocabulary ni a la relación con `product_cat`.

La vista reutiliza el editor de posts: título, contenido, categorías de producto, Vocabulary, material disponible, novedades y acciones de edición.

## Ingeniero

Muestra únicamente los borradores creados por el proceso editorial de Ingeniero.

La pertenencia a esta pestaña se determina por metadatos propios de Ingeniero, en particular:

- `_seo_ingeniero_editorial_id`;
- `_seo_ingeniero_topic_key`.

Además, el post debe mantener su relación `post_to_category`.

El **rol público** `_seo_solucionador_content_role = ingeniero_qa_specialized` es un metadato distinto: indica cómo consumen el contenido las plantillas públicas, pero no se usa como criterio de origen del post y no sustituye al Vocabulary ni a `product_cat`.

La vista replica el mecanismo editorial de Solucionador: listado filtrado, edición del post, categorías de producto, Vocabulary, material técnico disponible, novedades de Ingeniero y acciones de edición.

## Oportunidades posts

Muestra oportunidades editoriales detectadas para entradas. Las oportunidades son señales de revisión; una propuesta no se publica automáticamente por aparecer en esta pestaña.

## Errores

Muestra disponibilidad y problemas técnicos de posts mediante el escáner de salud. **Iniciar escaneo**, **Parar escaneo**, filtros de estado y búsqueda actúan sobre la auditoría, no sobre el contenido editorial.
