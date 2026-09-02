# Reinicio de conocimiento de Dependiente (staging)

El boton **Borrar conocimiento del Dependiente** ejecuta el mismo alcance que este procedimiento manual.

## Se elimina

- indice derivado de productos: `{$wpdb->prefix}seo_dependiente_index`
- logs/evidencias de busqueda: `{$wpdb->prefix}seo_dependiente_search_log`
- reglas semanticas cuyo `source` no sea `seed`
- banco de preguntas del Entrenador: `{$wpdb->prefix}seo_dependiente_trainer_questions`
- ejecuciones del Entrenador: `{$wpdb->prefix}seo_dependiente_trainer_runs`
- estado operativo de reindexacion (`seo_dependiente_background_page` y `seo_dependiente_last_full_index`)

## Se conserva

- reglas semanticas base con `source = 'seed'`
- productos WooCommerce
- categorias, etiquetas, atributos y vocabulario SEO
- configuracion del modulo y pagina publica
- solicitudes de ayuda (`seo_dependiente_help_requests`)

## SQL manual equivalente

Sustituye `wp_` por el prefijo real de WordPress antes de ejecutar.

```sql
START TRANSACTION;

DELETE FROM wp_seo_dependiente_trainer_runs;
DELETE FROM wp_seo_dependiente_trainer_questions;
DELETE FROM wp_seo_dependiente_search_log;
DELETE FROM wp_seo_dependiente_semantics
WHERE source <> 'seed' OR source IS NULL;
DELETE FROM wp_seo_dependiente_index;

COMMIT;

DELETE FROM wp_options
WHERE option_name IN (
  'seo_dependiente_background_page',
  'seo_dependiente_last_full_index'
);
```

Despues del reset, el indice queda vacio. Reindexa el catalogo antes de iniciar una nueva leccion.
