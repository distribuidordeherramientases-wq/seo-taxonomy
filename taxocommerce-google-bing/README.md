# TaxoCommerce Google / Bing

Módulo autónomo de conexiones e informes para Google Analytics 4, Google Search Console y Microsoft Bing Webmaster. Usa las credenciales que ya mantiene el módulo y no altera los datos históricos de TaxoCommerce SEO.

## Pestañas

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

Lectura local:

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

## Consideraciones

- Los datos de GA4 no representan peticiones HTTP y dependen de la instrumentación real del sitio.
- Search Console no garantiza todas las consultas, omite datos anonimizados y puede tardar en consolidar los últimos días.
- Los informes de Bing pueden actualizarse semanalmente y no necesariamente ofrecer las mismas dimensiones o fechas que Google.
- Las credenciales no se incluyen en los snapshots ni en los archivos JSON.
- Search Console admite conexión OAuth o cuenta de servicio Google; GA4 usa la cuenta de servicio configurada.

## Validación

El workflow de GitHub Actions taxocommerce-google-bing.yml comprueba sintaxis PHP, independencia del módulo y ejecuta el test de no-descarga reporting-smoke.php. Las PR se prueban sin despliegue: sólo el push a staging activa el FTP tras las comprobaciones.
