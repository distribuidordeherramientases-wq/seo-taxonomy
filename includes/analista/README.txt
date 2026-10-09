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

ANALISTA 3.7.0 - PROPUESTAS DE CAMPANAS
- Nuevo analista-campanas.php.
- Cruza Ojeador, Search Console y demanda registrada por Dependiente.
- Propone grupos de hasta 5 productos, precio recomendado, precio para igualar mercado y precio para competir.
- Calcula margen bruto orientativo, markup sobre coste y margen sobre venta.
- Los productos con precio malo se muestran como demanda frenada por precio y no son activables.
- El JSON maestro pasa a schema version 6 e incluye la seccion campanas.
- Marketing > Campanas > Propuestas de Analista permite convertir una propuesta en campana real con un boton.
- Al activar se guarda un snapshot de productos, fechas, coste, mercado, demanda y margenes; la campana usa el motor existente de aplicacion/restauracion de precios.


ANALISTA 3.8.1 - VALIDACION DE INSTRUCCIONES
- Mantiene la carga bajo demanda de 3.8.0.
- Añade analista-validacion.php y analista-tests.php.
- Valida consulta-entidad-destino antes de convertir una señal en instrucción.
- Distingue match exacto, parcial, no demostrado y conflicto de modelo.
- Añade buckets INVESTIGAR y ESPERAR_DATOS, además de HACER_AHORA/HACER_DESPUES/VIGILAR.
- Penaliza muestras pequeñas y crecimientos con base previa insuficiente mediante umbrales configurables.
- Comprueba title/meta efectivos antes de marcar ausencia.
- Separa preparación comercial (stock, precio, proveedor, margen/comisión) de la oportunidad SEO.
- Deduplica nueva creación frente a contenido publicado de Dependiente, Ingeniero y Comparador.
- Exporta contrato de tareas en JSON schema 7 y persiste baseline/revisiones 28/60/90 sin autoejecución.
- Fuentes muestra diagnóstico de Action Scheduler y siete pruebas de aceptación.


ANALISTA 3.8.2 - PLAN DE ACCION DESBLOQUEABLE
- Corrección puntual sobre 3.8.1; no cambia otros módulos.
- Intenta resolver destinos con product_cat e índice local antes de INVESTIGAR.
- INVESTIGAR queda para entidad/modelo/URL no resueltos.
- Bloqueos comerciales se separan como BLOCKED_COMMERCIAL y mantienen la oportunidad en HACER_DESPUES cuando corresponde.
- Cada tarea incluye blocker_type, investigation_steps, unlock_condition y bucket_if_unblocked.
- El score de oportunidad permanece estable; los gates afectan ejecutabilidad/confianza, no inflan prioridad.
- Añadido gate_summary para auditar auto-resoluciones y oportunidades valiosas bloqueadas.
- JSON unificado pasa a schema version 8.
- Pruebas 3.8.2 cubren desbloqueo comercial, INVESTIGAR concreto y diagnóstico de gates.
