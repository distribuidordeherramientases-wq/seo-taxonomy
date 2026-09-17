# Programador Social

MVP para programar publicaciones futuras de entradas y paginas/landings en las redes ya conectadas por `includes/social-network/`.

## Alcance actual

- Programacion unica por fecha y hora.
- Una tarea independiente por red seleccionada.
- Usa el publicador existente (`seo_social_network_publish_content()`), por lo que conserva UTM, historial y errores.
- Texto opcional por programacion; si queda vacio, usa la plantilla vigente de cada red al ejecutarse.
- Permite cancelar tareas pendientes y reintentar tareas fallidas/canceladas.
- No modifica la frecuencia SEO propia de posts o landings.
- No activa recurrencia automatica para evitar republicar contenido obsoleto sin revision.

## Futuro previsto

La tabla `wp_seo_social_schedule` incluye `source` y `notes` para poder recibir recomendaciones de Analista (urgencia, motivo, contenido a refrescar). La recurrencia debe anadirse cuando exista una regla clara de revision/republicacion de contenido, no como repeticion ciega del mismo mensaje.
