# Medición de informes (Search Console y GA4)

## Alcance de cada cifra

- **Clics, impresiones, CTR y posición generales:** `seo_google_search_totals`, consulta web final de Search Console agregada por propiedad y fecha. El informe solo la identifica como cifra comparable cuando están todos los días del periodo.
- **Páginas:** `seo_google_search_pages`, consulta agregada por página y fecha. Es la fuente de las URL de Analista y de Rendimiento de Posts cuando el periodo está completo.
- **Consultas y asociación consulta/página:** `seo_google_search_data`. Google puede ocultar consultas y limitar filas; una suma de esta tabla no equivale al total general. Ojeador utiliza esta señal de visibilidad solo de forma relativa.
- **GA4:** sesiones y eventos de la Data API. Los informes combinados consultan el mismo intervalo de fechas finalizadas que Search Console; sesiones y clics son métricas distintas.
- **Búsquedas internas:** se omiten las búsquedas de administradores al registrarlas. En Analista se excluyen también las filas históricas cuyo `user_id` corresponde a un administrador actual. Las pruebas anónimas antiguas no pueden identificarse de forma fiable.

## Migración y comprobación

1. Tras desplegar, abrir **Google Intelligence → Sincronización** y comprobar que las tablas de consultas, totales y páginas indican `OK`.
2. En la instalación que tenga conexión autorizada a Search Console, ejecutar **Sincronizar ahora**. La primera ejecución tras la migración recupera 90 días y procesa un día por petición AJAX. En staging sin conexión solo se comprueba la migración y el aviso de cobertura parcial; no copiar credenciales de producción para forzar la prueba.
3. Comparar el mismo intervalo exacto, la misma propiedad, **Búsqueda web** y **datos finalizados** en el gráfico de Search Console y en las tarjetas de Google Intelligence/Analista. Confirmar `source=gsc_property_web_final` y `days_available=days_expected` en el JSON exportado.
4. Comparar las URL de muestra por separado con la pestaña **Páginas** de Search Console. No sumar páginas y consultas para intentar reproducir el total de propiedad.
5. Entrar como administrador, navegar y buscar en el sitio, y confirmar que no se añaden búsquedas internas ni se emite el tag GA4 del plugin. Repetir sobre una página pública cacheada: la cookie `seo_skip_analytics=1` impide activar el tag. Después de cerrar sesión debe desaparecer la cookie; comprobar que un visitante sí recibe GA4.

La exclusión de administrador del plugin existía antes de esta corrección, pero la caché y otras etiquetas instaladas fuera del plugin deben comprobarse en la instalación real. GA4 no permite retirar retrospectivamente sesiones anteriores que no estuvieran identificadas como internas. Los informes marcan como parcial un periodo cuya nueva sincronización todavía no haya terminado.
