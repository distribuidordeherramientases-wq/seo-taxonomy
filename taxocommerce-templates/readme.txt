=== TaxoCommerce Templates ===
Contributors: davidperezmartorell
Tags: templates, roles, pages, woocommerce
Requires at least: 6.5
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later

Gestor de plantillas independientes para WordPress y WooCommerce.

== Descripcion ==
Tres areas independientes:
1. Tipos de peticion WordPress / WooCommerce:
   - Tipos integrados de WordPress (portada, home, single, page, archive, taxonomy, 404, search, etc.).
   - Tipos de contenido y taxonomias publicas registrados en la instalacion.
   - Paginas WooCommerce clasicas (catalogo, carrito, checkout, pedido recibido y cuenta).
   - Archivos PHP internos de WooCommerce, incluidos botones Add to Cart, fragmentos de producto y correos, cuando los proporciona la instalacion.
   - Las plantillas PHP de WooCommerce se interceptan mediante wc_get_template y wc_get_template_part; los comentarios mediante comments_template.
   - Los endpoints AJAX, eventos y acciones de WooCommerce no son plantillas PHP: el plugin no inyecta HTML en respuestas JSON.
   - Sin asignacion, WordPress / WooCommerce conserva su plantilla predeterminada y la jerarquia del tema. Soporta tambien el index de respaldo.
2. Jerarquia y roles TaxoCommerce: conserva Cluster, hubs, landings y asignaciones existentes en seo_nodes/seo_templates. Las variantes movil/escritorio estan ligadas a una unica plantilla principal.
3. Editor y biblioteca PHP:
   - Inventario unico de archivos integrados y plantillas propias.
   - Consulta de codigo integrado en modo solo lectura.
   - Crear desde texto, duplicar, descargar y editar archivos propios.
   - Subir PHP desde el navegador con validacion de sintaxis y limite de 1 MB.
   - Backup automatico antes de reemplazar una plantilla propia.
   - Crear y editar variantes mobile/desktop desde la plantilla principal.
   - Asociacion automatica a una peticion cuando el editor se abre desde Tipos de peticion.
   - Biblioteca persistente fuera de la carpeta del plugin.

== Seguridad y limites ==
- El motor nuevo permanece DESACTIVADO por defecto: habilitar solo despues de probar las plantillas necesarias en STAGING.
- Los archivos subidos se almacenan fuera de la carpeta del plugin en wp-content/taxocommerce-templates-library.
- Requiere permisos suficientes para modificar plugins, permisos de administrador y edicion de PHP habilitada por WordPress.
- Los archivos integrados se consultan y duplican, pero no se sobrescriben desde el editor.
- Se comprueba sintaxis, no comportamiento ni dependencias de ejecucion: un PHP sintacticamente correcto puede fallar en runtime.
- Las plantillas de bloques HTML (wp_template) se gestionan en el Editor del sitio, no sustituyendo codigo PHP.
- No cargar directamente el PHP del plugin monolitico; sus helpers deben migrarse para que los nuevos archivos sean autonomos.
- Las rutas especializadas del piloto internacional VEVOR no se reemplazan desde el nuevo router mientras el antiguo continue ejecutandolas.
- No activar simultaneamente dos gestores que modifiquen la misma peticion sin comprobar su prioridad.

== Pruebas ==
php taxocommerce-templates/tests/smoke.php
find taxocommerce-templates -type f -name '*.php' -print0 | xargs -0 -r -n1 php -l

== Extension ==
apply_filters('tct_request_definitions', $definitions) para exponer nuevos tipos con nombre, grupo y archivo de referencia.
apply_filters('tct_request_candidates', $keys) para determinar la precedencia de resolucion para una peticion del frontend.
Los tipos que no pasan por template_include ni wc_get_template necesitan su propio filtro/gancho de integracion.

== Compatibilidad de las asociaciones de produccion ==
- Mantiene literalmente las asociaciones de seo_templates, seo_nodes y seo_mail_templates al utilizar la misma base de datos de WordPress.
- Incorpora una copia independiente de los archivos y recursos publicos de seo-system/templates para evitar depender de que el plugin antiguo siga activado.
- El cargador nuevo da prioridad a la misma clave de rol/solicitud y conserva las variantes mobile/desktop.
- La pestaña Auditoria de asociaciones permite comparar registros, ubicacion de archivo antiguo/nuevo, roles sin plantilla, variantes ausentes, asignaciones de correo y descargar un inventario CSV.
- No se ejecuta ninguna migracion que reasigne IDs de paginas ni modifique seo_nodes.
- El nuevo motor permanece apagado de forma predeterminada; antes de apagar el plugin antiguo deben validarse sus dependencias funcionales y assets en STAGING.
- El inventario de archivos demuestra la copia y el PHP lint demuestra la sintaxis, pero ninguno certifica por si solo la equivalencia visual, las integraciones con servicios del plugin antiguo o la paridad de datos PRO y STAGING.
