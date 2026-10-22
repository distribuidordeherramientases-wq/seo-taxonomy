# TaxoCommerce FAQs 0.1.0

Extension de la familia TaxoCommerce. WordPress no trae un CPT FAQ nativo:
se reutiliza la tabla historica `$wpdb->prefix . 'seo_faq'` para conservar
el trabajo editorial ya publicado, sin duplicar preguntas.

## Datos y dependencias
- FAQ: id, object_type (1 hubs/paginas; 2 product_cat; 3 producto),
  object_id, question, answer, sort_order, active, load_count, open_count,
  created_at, updated_at.
- Etiquetas semanticas: `seo_vocabulary` y `seo_object_vocabulary`,
  usando `object_type='faq'`, `object_id=faq.id`.
- Si faltan las tablas Vocabulary, FAQ sigue funcionando sin editar etiquetas.
- No se modifica TaxoCommerce SEO ni se migran sus tablas.

## CSV
Cabecera: `id,object_type,object_id,question,answer,sort_order,active,rol,tipo,aplicacion,plataforma,subtipo`.
Los grupos contienen slugs separados por `|`, recuperables desde Vocabulary.
Se permiten hasta 500 filas por carga y 2 MB. ID existente: actualizar;
ID desconocido o 0: crear un ID local nuevo. Los destinos deben existir.

## Instalacion
En Core > Extensiones se encuentra la tarjeta de FAQs. Subir el ZIP con el
instalador de WordPress, volver a Core y activar. Despues, Contenidos > FAQs.
Sin Core, menu propio TaxoCommerce FAQs.

## Alcance
Esta primera version gestiona FAQs de hubs/paginas, categorias y productos;
no reimplementa el frontend, la telemetria detallada ni los informes
avanzados del monolito. La eliminacion es manual y permanente con confirmacion.
Verificar en STAGING los roles reales de hubs antes de cargar CSV masivos.

## Versión 0.1.1: categoría obligatoria

El campo `product_cat_id` señala una categoría real de WooCommerce. Se añade de manera incremental a la tabla histórica `seo_faq`. Solo se asignan automáticamente las preguntas que ya tienen un destino de tipo 2 y una categoría válida; el resto se conserva sin alterar y se refleja como 'Sin categoría'. Cada nueva creación o edición exige una categoría válida. El CSV añade la columna `product_cat_id`; las filas sin categoría válida se rechazan. La interfaz permite filtrar por categoría y ofrece KPIs de categorías cubiertas y FAQs sin clasificar.
