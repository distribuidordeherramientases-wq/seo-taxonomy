# TaxoCommerce Google / Bing

Módulo autónomo de conexiones e informes para Google Analytics 4, Google Search Console y Microsoft Bing Webmaster, con servicio Mercado para categorías de WooCommerce. Usa las credenciales que ya mantiene el módulo y no altera los datos históricos de TaxoCommerce SEO.

## Pestañas

- **Informe:** lectura ejecutiva consolidada y sin llamadas externas. Combina los últimos snapshots guardados de Search Console, GA4, Bing, Trends y Mercado/Merchant; recupera la priorización útil de los informes antiguos sin duplicar las vistas detalladas de cada proveedor. Expone un contrato local para Analista.
- **Conexiones:** Google OAuth, cuenta de servicio Google, propiedades GA4/Search Console, Bing Webmaster API, Merchant Center y Cloud Run. Guardar y probar conexión son acciones explícitas, sin sincronización de informes.
- **Google Analytics:** sesiones, usuarios (nuevos y recurrentes), vistas, eventos, tasa de interacción y rebote, duración, eventos clave y compras registradas por GA4. Gráficos de tráfico por día, canales, países, dispositivos y tabla de páginas más vistas.
- **Google Search:** clics, impresiones, CTR, posición media; gráficos diarios, países y dispositivos; principales consultas y páginas. Usa Search Analytics para búsqueda web y muestra como máximo 1.000 filas principales por tabla. No equivale al informe completo de indexación de Search Console.
- **Bing Webmaster:** clics, impresiones, CTR, gráficos diarios, principales búsquedas y páginas; respuestas de rastreo, errores, robots, URLs en índice y enlaces entrantes. Si Bing no permite GetQueryStats o GetPageStats, se conserva el resto del informe y se señala el error.

## Descarga manual, nunca en segundo plano

Entrar a cualquier pestaña, cambiar el periodo y navegar por informes almacenados **no solicita datos a Google ni Bing**. El único punto de descarga es el formulario WordPress admin-post.php?action=tgb_download_report (POST, usuario administrador y nonce específico por fuente).

Los botones permiten elegir **7, 30 o 90 días** o cualquier rango personalizado de hasta 90 días, siempre con días completos anteriores a hoy. El periodo anterior es inmediatamente precedente y tiene la misma duración. El informe de cada fuente se solicita por separado, sólo al pulsar **Descargar informe**.

La descarga almacena una copia con los periodos consultados, la fecha de extracción y sus datos en opciones de WordPress sin autoload. Se conservan como máximo 10 rangos recientes por proveedor. Si todavía no existe el rango seleccionado, se muestra la última copia disponible con sus **fechas reales**, sin consultar la API. La sección de informes guardados permite abrir una copia concreta.

El enlace **Exportar copia JSON** descarga únicamente la copia almacenada (sin llamadas externas).

## API local para Analista y otros módulos

Informe ejecutivo consolidado, sólo lectura y sin red:

    $executive = TaxoCommerce_Google_Bing_Executive::report();
    // $executive['sources'], ['overview'], ['opportunities'], ['content'], ['demand']

Analista debe consumir este contrato para sus resúmenes, indicadores y visualizaciones en vez de reconstruir conexiones o volver a consultar proveedores.

Snapshots de fuente, también en lectura local:

    $reports = TaxoCommerce_Google_Bing_Reports::saved_reports( 'search' );
    // analytics | search | bing
    $report = TaxoCommerce_Google_Bing_Reports::get_saved_snapshot(
        'search', '2026-10-01', '2026-10-07'
    );
    // $report['downloaded_at'], ['current'], ['previous'], ['data']

Hook posterior a descarga satisfactoria:

    add_action( 'tgb_manual_report_saved', function( $source, $ranges, $report ) {
        // Procesar el snapshot sin conocer credenciales.
    }, 10, 3 );

Ninguna lectura local inicia sincronización, OAuth ni acceso de red. Las APIs sólo se solicitan a través de formularios autenticados.


## Mercado · fuentes y expedientes por categoría (v0.7.0)

La pestaña **Mercado** vive dentro del mismo plugin. **No depende de TaxoCommerce Core ni de los módulos antiguos de Ojeador/Comparador.** Comparte la cuenta Google/Merchant existente, y admite claves opcionales de YouTube Data API v3 y SerpApi en su propio formulario.

Al activar el plugin (y al actualizar una instalación activa), se crean tres tablas nuevas con prefijo WordPress:
- **tgb_market_categories**: una fila por categoría de WooCommerce (product_cat, incluso vacías); nombre, slug, padre, idioma, país, categoría Google opcional, estado y último análisis.
- **tgb_market_sources**: resultados web, vídeos, comentarios públicos, ofertas observadas y señales Merchant; URL cuando exista, proveedor, fecha, fiabilidad de la fuente y metadatos. Deduplicación por categoría y referencia. Los comentarios de YouTube se eliminan a los 28 días si se visita el panel de administración.
- **tgb_market_runs**: historial de escaneos por categoría, resultados de cada fuente y errores.

La sincronización local inicial de categorías se hace en lotes de hasta **150 categorías por solicitud administrativa**, sin llamadas externas, y continúa en posteriores visitas. Los cambios en categorías marcan fuentes anteriores como antiguas; al borrar una categoría se archiva su historial. Si WooCommerce no está activo, Google/Bing sigue funcionando.

**Escaneo:** categoría individual manual o cola propia con WP-Cron, una categoría por ejecución. Protección por defecto: **10 categorías con llamadas externas por día** y **200 llamadas SerpApi por mes**. La cola se inicia y detiene mediante botones y NO empieza al instalar. Si WP-Cron no ejecuta, los escaneos automáticos se retrasan; se mantiene el control manual. La vista nunca consulta las APIs.

- **YouTube:** busca vídeos y recupera comentarios públicos sobre la categoría. Requiere una API key propia de YouTube Data API (no sirve la credencial GA4/Search automáticamente). La búsqueda suele costar 100 unidades de cuota.
- **Web:** búsquedas de Google mediante SerpApi opcional. Un resultado y su snippet son un enlace descubierto, no una opinión contrastada.
- **Ofertas:** resultados Google Shopping de SerpApi; ofertas individuales, no promedios de productos dispares. Requiere comprobar equivalencia técnica antes de comparar precios.
- **Merchant:** best-sellers agrupados por ID oficial de categoría de Google y país; requiere Market Insights y una correspondencia de categoría validada. No entrega comentarios ni una lista de precios individualizados de competidores.

El expediente por categoría permite ver fuentes, errores, fechas y **exportar dossier JSON** destinado al futuro Editor. No se importan correcciones JSON genéricas, no se crean borradores WordPress ni se publican posts automáticamente. Las conclusiones editoriales necesitan análisis y verificación posterior; los datos encontrados no autorizan inventar experiencias o promedios.

Nota: deben respetarse las condiciones de reutilización, almacenamiento y cacheado de YouTube, Merchant, SerpApi y cada fuente original.

## Consideraciones

- Los datos de GA4 no representan peticiones HTTP y dependen de la instrumentación real del sitio.
- Search Console no garantiza todas las consultas, omite datos anonimizados y puede tardar en consolidar los últimos días.
- Los informes de Bing pueden actualizarse semanalmente y no necesariamente ofrecer las mismas dimensiones o fechas que Google.
- Las credenciales no se incluyen en los snapshots ni en los archivos JSON.
- Search Console admite conexión OAuth o cuenta de servicio Google; GA4 usa la cuenta de servicio configurada.

## Validación

El workflow de GitHub Actions taxocommerce-google-bing.yml comprueba sintaxis PHP, independencia del módulo y ejecuta el test de no-descarga reporting-smoke.php. Las PR se prueban sin despliegue: sólo el push a staging activa el FTP tras las comprobaciones.
