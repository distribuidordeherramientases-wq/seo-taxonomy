# TaxoCommerce Google / Bing

Plugin independiente de la familia TaxoCommerce dedicado **exclusivamente a conexiones** con Google y Microsoft Bing.

## Responsabilidad

Este plugin:

- guarda credenciales y configuración de conexión;
- gestiona OAuth, access tokens y refresh tokens;
- conserva identificadores de propiedades/cuentas;
- permite pruebas explícitas de conexión mediante autenticación y metadatos;
- expone las conexiones para que otros módulos puedan utilizarlas más adelante.

Este plugin **no**:

- descarga consultas, clics, impresiones, posiciones ni páginas de Search Console;
- descarga sesiones, usuarios, visitas, compras ni eventos de GA4;
- descarga benchmarks, productos populares ni informes de Merchant;
- descarga métricas de Bing Webmaster;
- ejecuta Cloud Run Jobs;
- ejecuta Google Trends;
- programa sincronizaciones o crons de extracción;
- actualiza tablas o snapshots de datos de negocio.

Los datos históricos ya existentes no se eliminan.

## Conexiones

### Google Search Console OAuth

- Client ID.
- Client Secret.
- URI de redirección.
- Propiedad Search Console.
- OAuth 2.0.
- Access token y refresh token.
- Prueba explícita mediante listado de propiedades accesibles.

### Google Analytics / Search Console · cuenta de servicio

- Cuenta de servicio JSON.
- Propiedad Search Console.
- Propiedad GA4.
- Measurement ID.
- Prueba explícita mediante token y metadatos de propiedad.

Se conserva la opción histórica `seo_google_search_settings`.

### Google Merchant Center

- ID de Merchant Center.
- Usa la cuenta de servicio Google.
- Prueba explícita mediante metadatos básicos de la cuenta.
- No consulta Merchant Reports.

Se conserva la opción histórica `seo_ojeador_merchant_account_v1`.

### Google Cloud Run

- Project ID.
- Región.
- Nombre del Job.
- Cuenta de servicio JSON.
- Prueba explícita mediante lectura de la configuración del Job.
- La prueba no ejecuta el Job.

Se conserva la opción histórica `seo_google_python_runner_settings`.

### Microsoft Bing Webmaster

- Activación/desactivación de la conexión.
- Sitio de Bing Webmaster.
- API key.
- Compatibilidad con `SEO_BING_WEBMASTER_API_KEY` en `wp-config.php`.
- Prueba explícita mediante `GetUserSites`.
- No consulta tráfico, queries, páginas ni crawl stats.

Se conserva la opción histórica `seo_analista_bing_settings`.

## Arquitectura futura

Las futuras pestañas o módulos de informes decidirán:

1. qué fuente utilizar;
2. qué dataset solicitar;
3. qué periodo consultar;
4. qué dimensiones y métricas descargar;
5. qué caché o persistencia aplicar.

La capa de conexiones no decide ninguno de esos puntos.


## Informes bajo demanda

La pestaña **Informes** es independiente de **Conexiones**.

- No se ejecuta desde Conexiones.
- No tiene cron ni sincronización automática.
- Sólo consulta APIs al abrir Informes.
- Usa caché transitoria de 15 minutos.
- Compara 7, 30 o 90 días con el período inmediatamente anterior.

### Google Analytics 4

Indicadores:

- usuarios activos;
- usuarios nuevos;
- usuarios recurrentes;
- sesiones;
- vistas de página;
- tasa de interacción;
- evolución diaria de sesiones;
- evolución diaria de vistas;
- usuarios por país;
- comparación nuevos/recurrentes.

Search Console no forma parte de esta pestaña.

### Microsoft Bing Webmaster

Indicadores:

- impresiones;
- clics;
- CTR;
- páginas rastreadas;
- respuestas 2xx, 4xx y 5xx;
- errores de rastreo;
- bloqueos por robots.txt;
- URLs en índice;
- enlaces entrantes;
- evolución diaria de impresiones y clics.

No se muestran queries ni páginas concretas.
