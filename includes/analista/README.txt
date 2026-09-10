Analista 2.1.0 - Integracion Bing Webmaster

ARCHIVOS
- analista-bing.php: nuevo adaptador de lectura para Bing Webmaster.
- analista-bootstrap.php: carga el adaptador y registra su formulario.
- analista-json.php: exporta Bing y la comparacion Bing vs Google.
- analista-informe.php: muestra resumen Bing y contraste de consultas.

INSTALACION
1. Copiar analista-bing.php al mismo directorio de los modulos Analista.
2. Sustituir analista-bootstrap.php, analista-json.php y analista-informe.php por estas versiones.
3. Abrir Informes > Analista.
4. Al final, abrir "Bing Webmaster - conexion complementaria".
5. Introducir la URL verificada y la API key de Bing Webmaster.

NOTAS
- Google Search Console sigue siendo la fuente SEO principal del Analista.
- Bing se usa como fuente secundaria para impresiones, clics, CTR, rastreo, indice, 4xx/5xx, consultas y paginas.
- IndexNow NO se duplica: Cloudflare sigue gestionando los envios.
- La API key no se incluye en el JSON exportado.
- Tambien se admite definir SEO_BING_WEBMASTER_API_KEY en wp-config.php para no guardar la clave en la opcion de WordPress.
- El JSON Analista pasa a schema version 3.

VALIDACION
Los cuatro PHP han pasado php -l sin errores de sintaxis.
