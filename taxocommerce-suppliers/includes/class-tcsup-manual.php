<?php
/**
 * In-plugin user and developer documentation for TaxoCommerce Suppliers.
 *
 * This screen is deliberately read-only. Keep instructions consistent with
 * TCSUP_Admin, TCSUP_CSV, TCSUP_Products, TCSUP_Purchasing and TCSUP_Schema.
 *
 * @package TaxoCommerceSuppliers
 */
defined( 'ABSPATH' ) || exit;

final class TCSUP_Manual {
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para consultar el manual.', 'taxocommerce-suppliers' ) );
		}
		?>
		<div class="tcsup-manual">
			<h1>Manual de TaxoCommerce Suppliers</h1>
			<p>Versión <?php echo esc_html( TAXOCOMMERCE_SUPPLIERS_VERSION ); ?>. Guía de operación y referencia para futuros desarrolladores del <strong>plugin independiente</strong>. Abrir esta pestaña no importa CSV, modifica precios ni ejecuta compras.</p>
			<nav class="tcsup-manual-index" aria-label="Índice de la documentación">
				<h2>Índice</h2>
				<strong>Manual de usuario</strong>
				<a href="#tcsup-user">Introducción</a>
				<a href="#tcsup-user-tabs">Pestañas y controles</a>
				<a href="#tcsup-user-csv">Importar un CSV</a>
				<a href="#tcsup-user-templates">Plantillas</a>
				<a href="#tcsup-user-inventory">Inventario y aceptación</a>
				<a href="#tcsup-user-prices">Precios</a>
				<a href="#tcsup-user-images">Imágenes</a>
				<a href="#tcsup-user-purchasing">Compras</a>
				<a href="#tcsup-user-problems">Incidencias</a>
				<strong>Manual técnico / desarrolladores</strong>
				<a href="#tcsup-dev">Arquitectura</a>
				<a href="#tcsup-dev-files">Componentes</a>
				<a href="#tcsup-dev-data">Datos y claves</a>
				<a href="#tcsup-dev-import">Motor CSV</a>
				<a href="#tcsup-dev-products">WooCommerce y fuentes</a>
				<a href="#tcsup-dev-hooks">API y hooks</a>
				<a href="#tcsup-dev-security">Seguridad</a>
				<a href="#tcsup-dev-tests">Pruebas y evolución</a>
			</nav>

			<h1 id="tcsup-user">Manual de usuario</h1>
			<section class="tcsup-manual-section">
				<h2>1. Objetivo, acceso y precauciones</h2>
				<h3>1.1 Qué resuelve</h3>
				<p>Suppliers recibe catálogos CSV facilitados por proveedores, los normaliza y mantiene en un inventario intermedio. Permite revisar cada referencia antes de crear o enlazar productos WooCommerce, corregir los costes, conservar referencias de imágenes externas y consultar qué proveedor corresponde a una venta.</p>
				<h3>1.2 Dónde se encuentra</h3>
				<p>Abre <strong>TaxoCommerce Core → Suppliers</strong> si Core está activo, o el menú independiente <strong>TaxoCommerce Suppliers</strong>. Su dirección administrativa es <code>admin.php?page=taxocommerce-suppliers</code>. Se requiere una cuenta autorizada para gestionar WooCommerce o las opciones del sitio.</p>
				<h3>1.3 Antes de operar</h3>
				<p>Haz una copia de seguridad de las tablas de proveedores y de la base de datos antes de una importación importante. Importar un CSV <strong>no publica productos de inmediato</strong>, pero aceptar una fila sí puede crear un producto o modificar otro ya vinculado. Trabaja primero con pocas referencias y comprueba país, proveedor, SKU, precios e IVA. El módulo no realiza scraping ni descarga catálogos de sitios web.</p>
			</section>

			<section id="tcsup-user-tabs" class="tcsup-manual-section">
				<h2>2. Pestañas y resultados de las acciones</h2>
				<div class="tcsup-manual-scroll"><table class="widefat striped">
					<thead><tr><th>Pestaña</th><th>Qué permite</th><th>Qué se modifica y qué no</th></tr></thead>
					<tbody>
						<tr><td>CSV Import</td><td>Subir, analizar, asignar columnas e importar.</td><td>Guarda el CSV original e inserta o actualiza filas del catálogo intermedio; no publica productos.</td></tr>
						<tr><td>CSV Templates</td><td>Descargar cabecera canónica, consultar y eliminar plantillas personalizadas.</td><td>Eliminar una plantilla personalizada borra su definición, no las filas ya importadas.</td></tr>
						<tr><td>Inventory</td><td>Filtrar filas, aceptar, revisar, descartar o devolver a pendiente.</td><td><strong>Aceptar</strong> puede escribir un producto WooCommerce y metadatos; las otras decisiones modifican el estado de selección.</td></tr>
						<tr><td>Prices</td><td>Editar costes de proveedor, IVA, moneda, márgenes y precios Woo.</td><td>Guardar actualiza los datos del proveedor; solo aplica precios WooCommerce cuando se marca <em>Apply Woo prices</em>.</td></tr>
						<tr><td>External Images</td><td>Editar URL y modo de imagen por referencia.</td><td>Guarda referencias y su estado; no descarga archivos a Media.</td></tr>
						<tr><td>Purchasing</td><td>Ver pedidos activos y sus posibles proveedores.</td><td>Consulta pedidos y presenta enlaces externos; no compra automáticamente ni modifica estados de pedidos.</td></tr>
						<tr><td>Manual</td><td>Consultar estas dos guías.</td><td>Solo lectura.</td></tr>
						<tr><td>Autotest</td><td>Comprobar componentes y configuraciones bajo demanda.</td><td>Solo diagnóstico; no repara tablas ni procesa pedidos.</td></tr>
					</tbody>
				</table></div>
			</section>

			<section id="tcsup-user-csv" class="tcsup-manual-section">
				<h2>3. Importación CSV paso a paso</h2>
				<h3>3.1 Analizar el archivo</h3>
				<ol>
					<li>En <strong>CSV Import</strong>, selecciona una plantilla y escribe el nombre del proveedor. Las plantillas pueden proponer uno.</li>
					<li>Selecciona un archivo <strong>.csv</strong> y pulsa <strong>Analyze CSV</strong>. El módulo admite separadores de punto y coma, coma o tabulador; intenta detectar el apropiado.</li>
					<li>Comprueba el nombre del archivo, el separador detectado y la vista previa de hasta ocho filas. El CSV debe disponer de cabecera y tener un tamaño admitido por WordPress.</li>
					<li>En <strong>Review normalization</strong>, asigna las columnas de origen a los campos de TaxoCommerce. Para una importación normal son obligatorios el identificador externo y el nombre del producto. La plantilla VEVOR utiliza su propia normalización.</li>
					<li>Opcionalmente introduce un nombre para guardar la asignación como plantilla reutilizable y pulsa <strong>Import into intermediate inventory</strong>.</li>
				</ol>
				<h3>3.2 Resultado y comprobación</h3>
				<p>Al finalizar, el aviso distingue filas procesadas, nuevas, actualizadas, sin cambios y con error. El resultado se examina en <strong>Inventory</strong> (o en <strong>Prices</strong> para la plantilla de actualización de costes). Revisa las referencias sin país, sin SKU o con conflictos antes de aceptarlas.</p>
				<h3>3.3 Actualización exclusiva de precios</h3>
				<p>Selecciona <strong>Supplier Price Update CSV</strong> cuando solo debas renovar costes de referencias existentes. Exige una identidad (ID externo o SKU) y al menos una columna de precio; para VEVOR exige <strong>ID externo y país</strong>. Este modo <strong>no crea filas nuevas</strong>. Los registros no identificados o ambiguos se contabilizan como error.</p>
				<h3>3.4 Casos VEVOR</h3>
				<p>La plantilla <strong>VEVOR official CSV</strong> exige las columnas <code>Name</code>, <code>URL to product</code>, <code>categoryPath</code>, <code>URL to image</code>, <code>Currency</code> y <code>Sales</code>. El ID se extrae del segmento <code>p_</code> de la URL oficial; el dominio y, cuando existe, la columna Country sirven para determinar el mercado. DE, NL, FR, US y otros mercados soportados se mantienen diferenciados, sin fusionar productos por compartir SKU.</p>
				<h3>3.5 Limitaciones reales</h3>
				<p>La importación se ejecuta al confirmar el formulario: no se ofrece aquí una cola de scraping o importación XLS/XLSX. Una sesión de análisis caduca a las dos horas; si aparece <em>session expired</em>, vuelve a subir el fichero. No cierres ni repitas la operación sobre un catálogo grande sin comprobar antes su resultado.</p>
			</section>

			<section id="tcsup-user-templates" class="tcsup-manual-section">
				<h2>4. Plantillas CSV</h2>
				<p>La pestaña <strong>CSV Templates</strong> permite descargar <strong>Download canonical CSV header</strong> con las columnas internas, consultar las plantillas disponibles y borrar solo las creadas por el usuario. Los modelos integrados son <strong>TaxoCommerce canonical CSV</strong>, <strong>Supplier Price Update CSV</strong>, <strong>VEVOR official CSV</strong>, <strong>SATKIT visual mapping</strong> y <strong>TodoTaladros visual mapping</strong>.</p>
				<h3>4.1 Guardar un mapeo</h3>
				<p>Durante el análisis CSV, asigna los campos, abre <strong>Save this mapping as a reusable template</strong> y escribe un nombre. Se conservan los nombres de las columnas de origen, no su posición fija: si un proveedor reordena columnas sin cambiar sus nombres, la plantilla puede reutilizarse.</p>
				<h3>4.2 Eliminar una plantilla</h3>
				<p>La acción <strong>Delete</strong> solo aparece para las personalizadas. No elimina productos, filas del inventario ni archivos CSV ya almacenados. Después de cambiar un formato de proveedor, verifica el mapeo antes de importar de nuevo.</p>
			</section>

			<section id="tcsup-user-inventory" class="tcsup-manual-section">
				<h2>5. Inventario intermedio y decisiones</h2>
				<h3>5.1 Localizar una referencia</h3>
				<p>En <strong>Inventory</strong>, filtra por proveedor, decisión, país y texto (nombre, SKU o ID externo). La lista muestra 50 filas por página con precio, stock, estado de selección, estado de sincronización y, si existe, enlace al producto WooCommerce.</p>
				<h3>5.2 Qué significa cada decisión</h3>
				<div class="tcsup-manual-scroll"><table class="widefat striped">
					<thead><tr><th>Acción</th><th>Resultado</th><th>Precaución</th></tr></thead>
					<tbody>
						<tr><td>Review</td><td>Marca la fila <code>revisar</code>.</td><td>No resuelve automáticamente un conflicto de identidad.</td></tr>
						<tr><td>Discard</td><td>Marca la fila <code>descartado</code> y sincronización ignorada.</td><td>No equivale a borrar un producto WooCommerce ya creado.</td></tr>
						<tr><td>Return to pending</td><td>Devuelve una fila a <code>pendiente</code> desde la acción masiva.</td><td>Revisa antes los datos que motivaron su descarte.</td></tr>
						<tr><td>Accept</td><td>Busca el producto relacionado y lo enlaza o crea uno nuevo.</td><td>Puede modificar precio, stock y metadatos de un producto existente.</td></tr>
					</tbody>
				</table></div>
				<h3>5.3 Aceptar un producto nuevo</h3>
				<p>Si no se encuentra un producto existente y hay categoría WooCommerce predeterminada válida, se crea un <strong>producto simple en borrador, oculto del catálogo</strong>, asociado a esa categoría. No se publica automáticamente ni se asigna directamente a la categoría final del árbol comercial. Debes revisar y completar el producto antes de publicarlo desde WooCommerce.</p>
				<h3>5.4 Aceptar uno existente</h3>
				<p>El módulo intenta localizar una vinculación previa por ID de objeto, metadatos de catálogo/proveedor o SKU compatible. Puede actualizar precios calculados con los márgenes configurados, existencia, marca, referencias externas y metadatos del proveedor. Confirma que el vínculo es correcto antes de aceptar; las referencias VEVOR por mercado no deben tratarse como un único producto.</p>
				<h3>5.5 Acciones masivas</h3>
				<p>Marca las filas deseadas de la página actual, elige <strong>Accept / link to WooCommerce</strong>, <strong>Mark for review</strong>, <strong>Discard</strong> o <strong>Return to pending</strong> y pulsa <strong>Apply</strong>. Consulta los recuentos de procesadas y errores. No interpretes el selector <em>Select all rows on this page</em> como selección de todo el catálogo.</p>
			</section>

			<section id="tcsup-user-prices" class="tcsup-manual-section">
				<h2>6. Precios, impuestos y márgenes</h2>
				<h3>6.1 Configurar márgenes</h3>
				<p>En <strong>Prices</strong>, guarda los porcentajes <strong>Default sale margin</strong> y <strong>Default regular margin</strong>. Los valores de instalación son 20 % y 45 %, respectivamente. Al aceptar un producto con coste numérico se calcula el precio mediante <em>coste base × (1 + margen/100)</em>; el módulo toma preferentemente <code>precio_con_iva</code> y, si no está, <code>precio_sin_iva</code>. Comprueba siempre que ese origen y el tratamiento fiscal sean los adecuados.</p>
				<h3>6.2 Corregir una fila</h3>
				<p>La lista de 40 filas por página permite modificar <strong>Excl. tax</strong>, <strong>Incl. tax</strong>, <strong>Tax %</strong> y <strong>Currency</strong>. Pulsar <strong>Save</strong> actualiza los datos de proveedor y sus metadatos asociados cuando existe un producto vinculado; no requiere volver a importar el CSV.</p>
				<h3>6.3 Aplicar precios a WooCommerce</h3>
				<p>Los campos <strong>Woo regular</strong> y <strong>Woo sale</strong> están habilitados únicamente para productos vinculados. Marca explícitamente <strong>Apply Woo prices</strong> para modificar los precios del producto WooCommerce. Si no lo marcas, solo se actualizan los datos de coste/metadatos del proveedor. Revisa moneda, impuestos y descuentos antes de guardar.</p>
			</section>

			<section id="tcsup-user-images" class="tcsup-manual-section">
				<h2>7. Imágenes externas</h2>
				<p>En <strong>External Images</strong>, filtra la lista y pulsa <strong>Edit</strong> sobre una referencia. Elige <strong>External references</strong>, <strong>Local Media</strong> o <strong>Inherit existing behavior</strong>. Si utilizas referencias externas, pega una URL por línea y pulsa <strong>Save external images</strong>.</p>
				<h3>7.1 Diferencia entre referencia y archivo local</h3>
				<p>El módulo guarda URLs y metadatos de imágenes del proveedor en una tabla propia; <strong>no descarga los archivos ni crea adjuntos en la Biblioteca de Medios</strong>. Elegir Local o Inherit desactiva las referencias externas activas de esa fila, pero no genera automáticamente nuevas imágenes locales. Comprueba los derechos de uso y la disponibilidad de las URLs en el sitio de origen.</p>
			</section>

			<section id="tcsup-user-purchasing" class="tcsup-manual-section">
				<h2>8. Compras a proveedores</h2>
				<p>La pestaña <strong>Purchasing</strong> consulta por defecto pedidos WooCommerce <strong>pending</strong>, <strong>on-hold</strong> y <strong>processing</strong>. Muestra número de pedido, línea, cantidad, proveedor, ID/SKU, coste y, cuando existe, enlace <strong>Purchase</strong>. También presenta los casos <em>Without source</em> para que se revise su vinculación.</p>
				<h3>8.1 Cómo utiliza los proveedores</h3>
				<p>Primero busca una fuente aceptada para el producto de la línea; si es una variación sin fuente, intenta usar la del producto padre. Si hay varias fuentes, presenta alternativas. <strong>Purchase</strong> abre la página del proveedor en otra pestaña: no envía pedidos, no realiza pagos ni reserva stock.</p>
				<h3>8.2 Qué queda fuera</h3>
				<p>La valoración de peso, portes y coste de entrega se realiza en <strong>TaxoCommerce Logistics</strong>. Suppliers ofrece la relación comercial con el proveedor, pero no sustituye la gestión del pedido en WooCommerce.</p>
			</section>

			<section id="tcsup-user-problems" class="tcsup-manual-section">
				<h2>9. Autotest y resolución de problemas</h2>
				<div class="tcsup-manual-scroll"><table class="widefat striped">
					<thead><tr><th>Situación</th><th>Comprobación inicial</th><th>Actuación segura</th></tr></thead>
					<tbody>
						<tr><td>CSV rechazado</td><td>Extensión .csv, límite de subida, permisos de almacenamiento, cabecera y delimitador.</td><td>Corregir el fichero y volver a analizarlo; no modificar tablas directamente.</td></tr>
						<tr><td>Sesión CSV caducada</td><td>Han pasado dos horas o el archivo original ya no es legible.</td><td>Repetir Analyze CSV y revisar el mapeo.</td></tr>
						<tr><td>Errores VEVOR</td><td>Cabeceras oficiales, segmento p_ de URL, país, dominio e identidad.</td><td>Corregir origen y evitar mezclar países o variantes.</td></tr>
						<tr><td>Producto no aceptado</td><td>WooCommerce, categoría por defecto, SKU ocupado y mensajes de conflicto.</td><td>Resolver identidad/categoría antes de volver a aceptar.</td></tr>
						<tr><td>Compras sin proveedor</td><td>Estado de aceptación, <code>object_id</code> y producto/variación del pedido.</td><td>Revisar vínculo de catálogo y producto.</td></tr>
						<tr><td>Imágenes que no aparecen</td><td>Modo de imagen, URLs guardadas, consumidor de imágenes externas y sitio de origen.</td><td>Verificar referencias; Suppliers no importa archivos a Media.</td></tr>
						<tr><td>Autotest con error</td><td>Componentes, hooks, opciones, tablas y columnas indicadas por el control.</td><td>Guardar evidencia y revisar despliegue antes de ejecutar migraciones.</td></tr>
					</tbody>
				</table></div>
				<p><strong>Autotest</strong> se ejecuta con su propio botón y nonce; inspecciona disponibilidad en modo de solo lectura y muestra Correcto / Aviso / Error. No comprueba el contenido semántico de cada CSV ni garantiza que un servidor externo sirva una imagen.</p>
			</section>

			<h1 id="tcsup-dev">Manual técnico para futuros desarrolladores</h1>
			<section class="tcsup-manual-section">
				<h2>10. Arquitectura y límites del módulo</h2>
				<p><strong>Alcance:</strong> plugin autónomo <code>taxocommerce-suppliers/</code>, con WordPress 6.5+, PHP 7.4+ y dependencia declarada de WooCommerce. TaxoCommerce Core es opcional para el menú y registro familiar. No confundir este código con el plugin SEO monolítico ni trasladar mejoras al legado.</p>
				<p><strong>Flujo:</strong> CSV local → análisis/mapeo temporal → fila de <code>seo_proveedores_productos</code> → decisión humana → vinculación o borrador WooCommerce → metadatos de origen e imágenes externas → consulta comercial de pedidos. No existe extracción web, crawler, ejecutor externo o pedido automático dentro de Suppliers.</p>
				<h3>10.1 Arranque y activación</h3>
				<p>El archivo principal registra <code>taxocommerce_suppliers_activate()</code> para crear/actualizar tablas y establecer márgenes iniciales. En <code>plugins_loaded</code> (prioridad 30), <code>taxocommerce_suppliers_boot()</code> llama a <code>TCSUP_Schema::maybe_install()</code> y <code>TCSUP_Admin::boot()</code>. Las pestañas Manual y Autotest se cargan desde sus clases propias. El registro en Core se realiza con <code>taxocommerce_core_register_modules</code> y el filtro de catálogo familiar.</p>
			</section>

			<section id="tcsup-dev-files" class="tcsup-manual-section">
				<h2>11. Directorios, clases y responsabilidades</h2>
				<div class="tcsup-manual-scroll"><table class="widefat striped">
					<thead><tr><th>Archivo</th><th>Clase / cometido</th><th>Puntos de extensión y precauciones</th></tr></thead>
					<tbody>
						<tr><td><code>taxocommerce-suppliers.php</code></td><td>Bootstrap, versión, alta en Core y API pública.</td><td>No introducir lógica de UI/importación dentro del bootstrap.</td></tr>
						<tr><td><code>includes/class-tcsup-admin.php</code></td><td><code>TCSUP_Admin</code>: menú, pestañas, filtros, formularios, márgenes y avisos.</td><td><code>PAGE</code> identifica exclusivamente al plugin independiente; conservar slugs y nonces.</td></tr>
						<tr><td><code>includes/class-tcsup-csv.php</code></td><td><code>TCSUP_CSV</code>: campos, plantillas, análisis, transformación, importación, descarga canónica.</td><td>Respetar identidad, prevención de duplicados, VEVOR y formato CSV.</td></tr>
						<tr><td><code>includes/class-tcsup-products.php</code></td><td><code>TCSUP_Products</code>: aceptación, WooCommerce, marcas, costes e imágenes.</td><td>Conservar locks de aceptación, compatibilidad SKU/país y metadatos.</td></tr>
						<tr><td><code>includes/class-tcsup-purchasing.php</code></td><td><code>TCSUP_Purchasing</code>: consulta de ventas y proveedores vinculados.</td><td>Usar APIs de pedidos WooCommerce; no SQL directo sobre tablas de pedidos.</td></tr>
						<tr><td><code>includes/class-tcsup-schema.php</code></td><td><code>TCSUP_Schema</code>: esquema y actualización mediante <code>dbDelta()</code>.</td><td>Evitar migraciones destructivas y preservar tablas compartidas/legadas.</td></tr>
						<tr><td><code>includes/class-tcsup-manual.php</code></td><td><code>TCSUP_Manual</code>: estas guías.</td><td>Actualizar al cambiar los controles; no añadir escrituras ni peticiones externas.</td></tr>
						<tr><td><code>includes/class-tcsup-autotest.php</code></td><td><code>TCSUP_Autotest</code>: comprobaciones locales bajo demanda.</td><td>No utilizar métodos de instalación, aceptación, importación ni envío.</td></tr>
						<tr><td><code>assets/admin.css</code>, <code>tests/smoke.php</code></td><td>Presentación y regresión de la interfaz/contratos.</td><td>Los scripts tests se excluyen del ZIP distribuible.</td></tr>
					</tbody>
				</table></div>
			</section>

			<section id="tcsup-dev-data" class="tcsup-manual-section">
				<h2>12. Modelo de datos, identidad y persistencia</h2>
				<h3>12.1 Tabla de catálogo</h3>
				<p><code>{$wpdb->prefix}seo_proveedores_productos</code> (nombre lógico; prefijo real del sitio calculado por <code>TCSUP_Schema::catalog_table()</code>). Conserva <code>proveedor</code>, <code>proveedor_id_externo</code>, <code>sku</code>, <code>source_row_key</code>, <code>pais_origen</code>, nombre, marca, categoría de origen, precios, impuestos, moneda, stock, imágenes, <code>object_id</code>, hashes, estados y fechas de importación/sincronización.</p>
				<p>La clave única definida en esquema es <code>(proveedor, pais_origen, proveedor_id_externo, source_row_key)</code>. Para datos VEVOR con país, la importación busca esa identidad compuesta y aplica una reconciliación limitada para filas heredadas sin <code>source_row_key</code>. Para otros proveedores también verifica identificador externo y SKU, y marca conflictos si conducen a filas distintas. <strong>No eliminar país o clave de variante</strong> para simplificar consultas: se producirían colisiones entre mercados.</p>
				<h3>12.2 Tabla de imágenes</h3>
				<p><code>{$wpdb->prefix}seo_supplier_images</code> contiene <code>supplier</code>, <code>supplier_product_id</code>, <code>supplier_sku</code>, <code>catalog_row_id</code>, <code>product_id</code>, <code>image_url</code>, <code>image_hash</code>, posición, imagen principal, estado y marcas de tiempo. La clave única es <code>(supplier, supplier_sku, image_hash)</code>. Guardar referencias externas desactiva las anteriores y activa/inserta las vigentes; cambiar a Local/Inherit desactiva referencias activas.</p>
				<h3>12.3 Opciones, transients y archivos</h3>
				<div class="tcsup-manual-scroll"><table class="widefat striped">
					<thead><tr><th>Identificador</th><th>Función</th></tr></thead>
					<tbody>
						<tr><td><code>taxocommerce_suppliers_db_version</code></td><td>Versión del esquema, actualmente <code>1.0.0</code>; <code>maybe_install()</code> comprueba tablas y puede ejecutar dbDelta.</td></tr>
						<tr><td><code>taxocommerce_suppliers_csv_templates</code></td><td>Array de asignaciones personalizadas; los cinco presets viven en PHP.</td></tr>
						<tr><td><code>taxocommerce_suppliers_sale_margin</code> y <code>taxocommerce_suppliers_regular_margin</code></td><td>Márgenes comerciales (por defecto 20 y 45); valores acotados por el controlador de ajustes.</td></tr>
						<tr><td><code>tcsup_csv_{user_id}</code></td><td>Transient de análisis, cabeceras, vista previa y ruta temporal; duración de dos horas.</td></tr>
						<tr><td><code>wp-content/uploads/taxocommerce-suppliers/originals/</code></td><td>CSV originales subidos; no están en la carpeta instalable del plugin y no se deben confundir con tests.</td></tr>
					</tbody>
				</table></div>
				<p>La relación WooCommerce usa, entre otros, los metadatos <code>_seo_proveedor</code>, <code>_seo_proveedor_id_externo</code>, <code>_seo_proveedor_catalogo_id</code>, <code>_seo_proveedor_pais</code>, <code>_seo_proveedor_sku</code> y <code>_seo_imagenes_externas</code>. Una migración de esquema o identidades debe considerar los consumidores externos de estas claves.</p>
			</section>

			<section id="tcsup-dev-import" class="tcsup-manual-section">
				<h2>13. Motor CSV y transformaciones</h2>
				<h3>13.1 Pasos internos</h3>
				<p><code>TCSUP_CSV::handle_analyze()</code> valida el fichero, lo almacena en uploads, detecta delimitador y guarda el estado por usuario. <code>handle_import()</code> comprueba sesión/plantilla, valida columnas obligatorias, sanea datos y recorre las filas. Los métodos <code>fields()</code> y <code>builtin_templates()</code> definen el contrato canónico.</p>
				<h3>13.2 Modos y deduplicación</h3>
				<p>En modo <code>canonical</code> se esperan las cabeceras de los campos internos; <code>mapping</code> usa nombres del fichero; <code>vevor</code> transforma las seis cabeceras oficiales, extrae el ID numérico de 12 dígitos de <code>p_</code> e infiere el país desde el dominio cuando el CSV no lo indica. <code>price_update</code> solo actualiza fuentes identificadas de manera inequívoca y no crea filas.</p>
				<p><code>row_hash()</code> y <code>changed_fields()</code> permiten identificar cambios. Las filas existentes conservan su identidad y se actualizan; las nuevas entran con <code>estado_seleccion=pendiente</code>. En conflictos de SKU/ID, el código puede marcar <code>revisar</code> y <code>conflicto</code>. Toda modificación del importador requiere casos de prueba de reimportación, duplicación y países.</p>
				<h3>13.3 Límites operativos</h3>
				<p>El código actual lee el CSV local con <code>SplFileObject</code>. No añadir llamadas de red, colas automáticas o nuevos scrapers como parte de una corrección documental. La aceptación en WooCommerce se realiza por una acción distinta de importar. El registro de resultados de la pantalla muestra contadores, no constituye un historial permanente por fila.</p>
			</section>

			<section id="tcsup-dev-products" class="tcsup-manual-section">
				<h2>14. Aceptación, sincronización y compras WooCommerce</h2>
				<h3>14.1 Selección de productos</h3>
				<p><code>TCSUP_Products::accept()</code> obtiene la fila y adquiere un lock MySQL con <code>GET_LOCK</code> antes de delegar a <code>accept_unlocked()</code>; se libera con <code>RELEASE_LOCK</code>. <code>existing_product()</code> busca por <code>object_id</code>, metadatos de vínculo y, cuando es seguro, SKU. Para VEVOR con país no debe reutilizarse un producto solo por SKU; el SKU interno nuevo se forma como <code>vevor-{country}-row-{id}</code>.</p>
				<p>Si no hay coincidencia, usa <code>WC_Product_Simple</code> y crea un borrador oculto con la categoría WooCommerce predeterminada. Para fuentes ya enlazadas puede actualizar precio, stock, marca, metadatos y enlaces de imagen. Tras guardar actualiza <code>object_id</code>, <code>hash_aplicado</code>, <code>snapshot_aplicado</code> y estado de sincronización.</p>
				<h3>14.2 Márgenes y existencias</h3>
				<p>Los márgenes definidos en opciones se aplican sobre el precio base, priorizando <code>precio_con_iva</code>, y se redondean con la precisión de WooCommerce. Stock numérico activa gestión de existencias y cantidad; sin cantidad se determina disponibilidad según el estado textual admitido. Estos comportamientos <strong>son efectos de aceptar</strong> y deben cubrirse por pruebas de regresión antes de cualquier cambio.</p>
				<h3>14.3 Consulta de compras y compatibilidad HPOS</h3>
				<p><code>TCSUP_Purchasing::collect_order_lines()</code> usa <code>wc_get_orders()</code> y los métodos de pedidos/líneas WooCommerce. El filtro <code>taxocommerce_suppliers_purchasing_order_statuses</code> define los estados activos (por defecto pending, on-hold y processing). <code>sources_for_line()</code> resuelve primero la variación/producto exacto y luego el padre; <code>sources_for_product()</code> admite estados aceptado y otros legados previstos en el código. La pantalla no crea órdenes de compra.</p>
				<p>La consulta actual usa <code>limit = -1</code> para pedidos activos, por lo que en tiendas de gran volumen conviene evaluar su coste antes de ampliar el panel. No substituir la API WooCommerce por consultas directas a <code>wp_posts</code>: debe mantenerse la compatibilidad HPOS.</p>
			</section>

			<section id="tcsup-dev-hooks" class="tcsup-manual-section">
				<h2>15. API pública, hooks y contratos</h2>
				<h3>15.1 Funciones consumibles por otros plugins</h3>
				<p><code>taxocommerce_suppliers_get_sources( $product_id )</code> devuelve filas del catálogo proveedor asociadas al producto; <code>taxocommerce_suppliers_get_external_images( $product_id )</code> devuelve referencias de imágenes activas. Comprobar <code>function_exists()</code> antes de usar estas funciones desde módulos opcionales; no asumir que las imágenes son adjuntos locales.</p>
				<h3>15.2 Acciones administrativas</h3>
				<div class="tcsup-manual-scroll"><table class="widefat striped">
					<thead><tr><th>Hook admin-post.php</th><th>Controlador</th><th>Efecto</th></tr></thead>
					<tbody>
						<tr><td><code>tcsup_analyze_csv</code>, <code>tcsup_import_csv</code></td><td><code>TCSUP_CSV</code></td><td>Subida/análisis e inserción/actualización intermedia.</td></tr>
						<tr><td><code>tcsup_save_template</code>, <code>tcsup_delete_template</code>, <code>tcsup_download_template</code></td><td><code>TCSUP_CSV</code></td><td>Gestión y descarga de plantillas.</td></tr>
						<tr><td><code>tcsup_row_action</code>, <code>tcsup_bulk_action</code></td><td><code>TCSUP_Products</code></td><td>Selección y aceptación de filas; la interfaz del inventario envía las acciones individuales por <code>tcsup_bulk_action</code>.</td></tr>
						<tr><td><code>tcsup_price_update</code>, <code>tcsup_images_update</code></td><td><code>TCSUP_Products</code></td><td>Precios de fuente/Woo y vínculos de imágenes.</td></tr>
						<tr><td><code>tcsup_settings</code></td><td><code>TCSUP_Admin</code></td><td>Guarda porcentajes de margen.</td></tr>
					</tbody>
				</table></div>
				<p>Registro familiar: <code>taxocommerce_core_register_modules</code> y <code>taxocommerce_core_family_extensions</code>. Filtro de compras: <code>taxocommerce_suppliers_purchasing_order_statuses</code>. Mantener las firmas de métodos y nombres de acciones para no romper integraciones de la familia.</p>
			</section>

			<section id="tcsup-dev-security" class="tcsup-manual-section">
				<h2>16. Seguridad, límites y compatibilidad</h2>
				<h3>16.1 Acceso y formularios</h3>
				<p>La pantalla usa la capacidad <code>manage_woocommerce</code> si WooCommerce está activo, con acceso alternativo para <code>manage_options</code>. Cada operación que modifica estado debe comprobar capacidad y nonce con <code>check_admin_referer()</code>. Las lecturas de filtros GET se saneán y las salidas de usuario pasan por las funciones de escape de WordPress.</p>
				<h3>16.2 SQL, ficheros y datos de terceros</h3>
				<p>Utilizar <code>$wpdb->prepare()</code>, con <code>%i</code> para identificadores cuando proceda, y evitar interpolar entradas no fiables. No asumir que una URL externa es un archivo local. Los CSV originales residen bajo <code>uploads</code> y pueden contener información comercial: antes de ampliar los endpoints, comprobar autorización, accesibilidad pública, tipos de fichero y límites. No introducir exportaciones o rutas de descarga de originales sin petición explícita y evaluación de seguridad.</p>
				<h3>16.3 Dependencias y exclusiones</h3>
				<p>WooCommerce es obligatorio para aceptación de productos y consultas de compras; TaxoCommerce Core es opcional para agrupar el menú. El módulo no gestiona pesos y transportes (Logistics), no ejecuta scraping (Scrappers) y no publica automáticamente productos. No modificar el plugin monolítico ni asumir que sus clases antiguas siguen disponibles.</p>
			</section>

			<section id="tcsup-dev-tests" class="tcsup-manual-section">
				<h2>17. Pruebas, mantenimiento y entrega</h2>
				<h3>17.1 Autotest</h3>
				<p><code>TCSUP_Autotest::render()</code> espera una acción POST manual con nonce <code>tcsup_autotest</code>; <code>run()</code> consulta clases, registro de acciones, opciones, tablas/columnas y API de WooCommerce. <strong>Un test correcto solo confirma disponibilidad estructural</strong>, no calidad de datos, precios, stock ni éxito de importación. No utilizar para reparación automática del esquema.</p>
				<h3>17.2 Matriz mínima antes de fusionar</h3>
				<div class="tcsup-manual-scroll"><table class="widefat striped">
					<thead><tr><th>Grupo</th><th>Casos recomendados en STAGING</th></tr></thead>
					<tbody>
						<tr><td>CSV</td><td>Cabecera válida e inválida; separadores; plantilla personalizada; reimportación; modo precio sin altas nuevas.</td></tr>
						<tr><td>Identidad internacional</td><td>VEVOR US/DE/NL/FR con igual SKU, variante distinta, país ausente, ID y URL erróneos.</td></tr>
						<tr><td>WooCommerce</td><td>Alta como borrador oculto; vínculo existente; colisión de SKU; categoría por defecto; márgenes y stock.</td></tr>
						<tr><td>Imágenes</td><td>Referencias externas nuevas, duplicadas, cambio de modo y ausencia de descargas a Media.</td></tr>
						<tr><td>Compras</td><td>Pedidos activos sin fuente, con fuente exacta, variación que hereda fuente del padre y enlaces válidos.</td></tr>
						<tr><td>Seguridad / WP.org</td><td>Permisos y nonces, escape, SQL preparado, PHP lint, smoke, Plugin Check y ZIP sin <code>tests/</code>.</td></tr>
					</tbody>
				</table></div>
				<h3>17.3 Proceso de cambios</h3>
				<p>Antes de editar, inspeccionar clases relacionadas y compatibilidad de tablas compartidas. Cada cambio debe disponer de Issue, PR vinculado, pruebas y nota en <code>CHANGELOG.md</code>. Ajustar <code>readme.txt</code> y versión del archivo principal antes de publicar en WordPress.org, conservar el <code>changelog.txt</code> histórico y excluir los scripts <code>tests/</code> del ZIP. La validación debe hacerse en STAGING; <strong>PRODUCCIÓN requiere autorización expresa</strong>.</p>
				<h3>17.4 Cómo mantener esta documentación</h3>
				<p>Si una funcionalidad cambia, actualizar tanto el procedimiento operativo (botones, riesgos, resultados) como el contrato técnico (clases, tablas, hooks y pruebas). No inventar opciones que no existan en el plugin. Conservar esta estructura de dos manuales completos en la pestaña Manual para que el siguiente desarrollador no dependa de conversaciones anteriores.</p>
			</section>
		</div>
		<?php
	}
}
