<?php
/**
 * Integrated user and technical manual for the standalone Suppliers plugin.
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
		<section class="tcsup-card tcsup-manual">
			<h2>Manual de TaxoCommerce Suppliers</h2>
			<p>Versión <?php echo esc_html( TAXOCOMMERCE_SUPPLIERS_VERSION ); ?>. Documentación del plugin independiente, no del sistema monolítico.</p>
			<p><a href="#tcsup-user">Manual de usuario</a> · <a href="#tcsup-technical">Manual técnico</a> · <a href="#tcsup-diagnostics">Diagnóstico</a></p>
			<h2 id="tcsup-user">Manual de usuario</h2>
			<h3>1. Importación de archivos CSV</h3>
			<p>En <strong>CSV Import</strong>, selecciona una plantilla, indica el proveedor, sube un archivo CSV y pulsa <strong>Analyze CSV</strong>. Comprueba las columnas detectadas, su asignación y la vista previa antes de importar al inventario intermedio. El identificador externo y el nombre del producto son obligatorios en una importación normal. Solo se admiten CSV: no hay scraping ni importación directa de XLS/XLSX.</p>
			<h3>2. Plantillas CSV</h3>
			<p><strong>CSV Templates</strong> permite consultar plantillas predefinidas y gestionar asignaciones reutilizables. Incluye la plantilla canónica TaxoCommerce, actualización de precios, VEVOR oficial, SATKIT y TodoTaladros. La plantilla VEVOR normaliza localmente el feed oficial; no accede a la web del proveedor.</p>
			<h3>3. Inventario y selección</h3>
			<p>En <strong>Inventory</strong>, filtra por proveedor, texto y estado. Las filas se mantienen en inventario intermedio hasta su decisión: pendiente, revisar, aceptado o descartado. Aceptar una fila puede crear o vincular un producto WooCommerce; revisa su categoría, SKU y precio antes de hacerlo. Las operaciones masivas afectan a las filas seleccionadas.</p>
			<h3>4. Precios</h3>
			<p>En <strong>Prices</strong>, consulta y corrige los precios del proveedor. Los márgenes de venta y regular se guardan como opciones; distingue los datos de coste del proveedor de los precios WooCommerce. Una actualización de precio del catálogo no debe confundirse con una importación de productos nuevos.</p>
			<h3>5. Imágenes externas</h3>
			<p><strong>External Images</strong> permite revisar las URL y el modo de imagen de cada fila. En modo externo se guardan referencias a las imágenes del proveedor, sin descargarlas a la biblioteca de medios. Comprueba que las URL sean válidas y que el proveedor permita su utilización.</p>
			<h3>6. Compras a proveedores</h3>
			<p><strong>Purchasing</strong> muestra líneas de pedidos WooCommerce pendientes, en espera o en proceso y busca proveedores aceptados asociados al producto o a su producto padre. Presenta el enlace de compra cuando está disponible. No realiza pedidos automáticamente; los costes de transporte y pesos pertenecen al plugin Logistics.</p>
			<h2 id="tcsup-technical">Manual técnico</h2>
			<h3>7. Arquitectura y requisitos</h3>
			<p>Plugin independiente <code>taxocommerce-suppliers/taxocommerce-suppliers.php</code>, con PHP 7.4 o superior, WordPress 6.5 o superior y WooCommerce. Clases: <code>TCSUP_Admin</code> (interfaz y acciones), <code>TCSUP_CSV</code> (importación y plantillas), <code>TCSUP_Products</code> (aceptación y sincronización), <code>TCSUP_Purchasing</code> (consulta de pedidos), <code>TCSUP_Schema</code> (tablas), <code>TCSUP_Manual</code> y <code>TCSUP_Autotest</code>. TaxoCommerce Core es opcional para situar el menú dentro de su familia.</p>
			<h3>8. Persistencia y claves</h3>
			<p>Tablas <code>{prefix}seo_proveedores_productos</code> y <code>{prefix}seo_supplier_images</code>. La identidad del catálogo incorpora proveedor, país, identificador externo y clave estable de variante; no se deben mezclar productos de distintos mercados. Opciones: <code>taxocommerce_suppliers_db_version</code>, <code>taxocommerce_suppliers_csv_templates</code>, <code>taxocommerce_suppliers_sale_margin</code>, <code>taxocommerce_suppliers_regular_margin</code>. El análisis CSV mantiene estado temporal por usuario; los archivos originales se guardan en <code>uploads/taxocommerce-suppliers/originals</code>.</p>
			<h3>9. Integraciones y puntos de entrada</h3>
			<p>El arranque registra el módulo en TaxoCommerce Core cuando está disponible. Las funciones públicas <code>taxocommerce_suppliers_get_sources( $product_id )</code> y <code>taxocommerce_suppliers_get_external_images( $product_id )</code> exponen fuentes e imágenes. Las acciones administrativas de importación, plantillas, decisiones, precios e imágenes usan el prefijo <code>admin_post_tcsup_</code>. El filtro <code>taxocommerce_suppliers_purchasing_order_statuses</code> permite alterar los estados consultados por Purchasing. Las compras se leen con las APIs de WooCommerce, compatibles con HPOS.</p>
			<h3>10. Seguridad y mantenimiento</h3>
			<p>Las modificaciones administrativas requieren capacidad <code>manage_woocommerce</code> o <code>manage_options</code> y validación de nonce. Antes de actualizar el esquema o alterar importaciones, realiza copia de seguridad de las tablas y revisa las dependencias. No modifiques el plugin monolítico para mantener este módulo. La publicación en WordPress.org requiere comprobar Plugin Check y sincronizar <code>readme.txt</code> con <code>CHANGELOG.md</code>.</p>
			<h2 id="tcsup-diagnostics">Diagnóstico</h2>
			<h3>11. Autotest</h3>
			<p>La pestaña <strong>Autotest</strong> comprueba componentes, acciones, tablas, columnas, opciones e integración con WooCommerce en modo de solo lectura. Un aviso indica una dependencia o configuración que conviene revisar; un error indica que falta un elemento necesario. No valida la calidad comercial del CSV, el resultado de importaciones ni la disponibilidad de URLs externas.</p>
			<h3>12. Incidencias habituales</h3>
			<p>Si un CSV no se importa, comprueba extensión, tamaño, delimitador, cabecera, permisos de subida y asignación de ID/nombre. Si faltan proveedores en Purchasing, comprueba que el producto esté vinculado a una fila aceptada. Si faltan imágenes, revisa las referencias externas y el modo elegido. Para problemas de tablas, consulta primero Autotest y las copias de seguridad; no ejecutes reparaciones destructivas directamente.</p>
		</section>
		<?php
	}
}
