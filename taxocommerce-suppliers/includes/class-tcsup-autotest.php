<?php
/**
 * On-demand, read-only diagnostics for the independent Suppliers module.
 *
 * @package TaxoCommerceSuppliers
 */
defined( 'ABSPATH' ) || exit;

final class TCSUP_Autotest {
	private static function check( &$rows, $group, $name, $status, $detail ) {
		$rows[] = array( 'group' => $group, 'name' => $name, 'status' => $status, 'detail' => $detail );
	}

	public static function run() {
		global $wpdb;
		$rows = array();
		self::check( $rows, 'Sistema', 'Plugin y versión', defined( 'TAXOCOMMERCE_SUPPLIERS_VERSION' ) ? 'ok' : 'error', defined( 'TAXOCOMMERCE_SUPPLIERS_VERSION' ) ? 'Suppliers ' . TAXOCOMMERCE_SUPPLIERS_VERSION : 'Constante de versión ausente.' );
		$woo = class_exists( 'WooCommerce', false ) && function_exists( 'wc_get_orders' ) && function_exists( 'wc_get_product' );
		self::check( $rows, 'Sistema', 'WooCommerce', $woo ? 'ok' : 'error', $woo ? 'API de pedidos y productos disponible.' : 'WooCommerce no está disponible para productos o compras.' );

		$classes = array( 'TCSUP_Schema', 'TCSUP_CSV', 'TCSUP_Products', 'TCSUP_Purchasing', 'TCSUP_Admin', 'TCSUP_Manual' );
		$missing = array();
		foreach ( $classes as $class ) {
			if ( ! class_exists( $class, false ) ) {
				$missing[] = $class;
			}
		}
		self::check( $rows, 'Componentes', 'Clases del plugin', $missing ? 'error' : 'ok', $missing ? 'Faltan: ' . implode( ', ', $missing ) : 'Todos los componentes principales están cargados.' );

		$hooks = array(
			'admin_post_tcsup_analyze_csv'      => array( 'TCSUP_CSV', 'handle_analyze' ),
			'admin_post_tcsup_import_csv'       => array( 'TCSUP_CSV', 'handle_import' ),
			'admin_post_tcsup_save_template'    => array( 'TCSUP_CSV', 'handle_save_template' ),
			'admin_post_tcsup_delete_template'  => array( 'TCSUP_CSV', 'handle_delete_template' ),
			'admin_post_tcsup_download_template'=> array( 'TCSUP_CSV', 'handle_download_template' ),
			'admin_post_tcsup_row_action'       => array( 'TCSUP_Products', 'handle_row_action' ),
			'admin_post_tcsup_bulk_action'      => array( 'TCSUP_Products', 'handle_bulk_action' ),
			'admin_post_tcsup_price_update'     => array( 'TCSUP_Products', 'handle_price_update' ),
			'admin_post_tcsup_images_update'    => array( 'TCSUP_Products', 'handle_images_update' ),
			'admin_post_tcsup_settings'         => array( 'TCSUP_Admin', 'handle_settings' ),
		);
		$missing = array();
		foreach ( $hooks as $hook => $callback ) {
			if ( false === has_action( $hook, $callback ) ) {
				$missing[] = $hook;
			}
		}
		self::check( $rows, 'Administración', 'Controladores registrados', $missing ? 'error' : 'ok', $missing ? 'No registrados: ' . implode( ', ', $missing ) : 'Los 10 controladores están registrados; ninguno se ejecuta.' );
		self::check( $rows, 'Servicios', 'Funciones públicas', function_exists( 'taxocommerce_suppliers_get_sources' ) && function_exists( 'taxocommerce_suppliers_get_external_images' ) ? 'ok' : 'error', 'Consulta de fuentes e imágenes de proveedor (sin invocar operaciones).' );
		$templates = get_option( 'taxocommerce_suppliers_csv_templates', array() );
		self::check( $rows, 'Configuración', 'Plantillas CSV personalizadas', is_array( $templates ) ? 'ok' : 'error', is_array( $templates ) ? 'Estructura válida; ' . count( $templates ) . ' plantillas almacenadas.' : 'La opción no contiene un array.' );
		$builtin = class_exists( 'TCSUP_CSV', false ) ? TCSUP_CSV::builtin_templates() : array();
		$expected = array( 'canonical', 'price_update', 'vevor', 'satkit', 'todotaladros' );
		self::check( $rows, 'Configuración', 'Plantillas integradas', ! array_diff( $expected, array_keys( $builtin ) ) ? 'ok' : 'error', 'Comprobación de los cinco modelos integrados sin importar CSV.' );
		foreach ( array( 'taxocommerce_suppliers_sale_margin' => 'Margen de venta', 'taxocommerce_suppliers_regular_margin' => 'Margen regular' ) as $option => $label ) {
			$value = get_option( $option, false );
			self::check( $rows, 'Configuración', $label, false !== $value && is_numeric( $value ) && is_finite( (float) $value ) ? 'ok' : 'warning', false !== $value && is_numeric( $value ) ? 'Valor numérico guardado.' : 'Opción ausente o no numérica; revisar ajustes.' );
		}
		$db_version = get_option( 'taxocommerce_suppliers_db_version', '' );
		self::check( $rows, 'Persistencia', 'Versión del esquema', class_exists( 'TCSUP_Schema', false ) && TCSUP_Schema::DB_VERSION === (string) $db_version ? 'ok' : 'warning', 'Versión guardada: ' . ( $db_version ? $db_version : 'sin registrar' ) . '. No se ejecutan migraciones.' );
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! class_exists( 'TCSUP_Schema', false ) ) {
			self::check( $rows, 'Persistencia', 'Base de datos', 'error', 'Conexión o esquema no disponibles.' );
		} else {
			$tables = array(
				'Catálogo intermedio' => array( TCSUP_Schema::catalog_table(), array( 'id', 'proveedor', 'proveedor_id_externo', 'pais_origen', 'source_row_key', 'estado_seleccion', 'object_id' ) ),
				'Imágenes externas'   => array( TCSUP_Schema::images_table(), array( 'id', 'supplier', 'supplier_sku', 'product_id', 'image_url', 'status' ) ),
			);
			foreach ( $tables as $label => $spec ) {
				$table = $spec[0];
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit manual inspection, read-only.
				$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
				if ( $exists !== $table ) {
					self::check( $rows, 'Persistencia', $label, 'error', 'No se encuentra la tabla ' . $table . '.' );
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit manual schema inspection.
				$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
				$missing_columns = array_diff( $spec[1], is_array( $columns ) ? $columns : array() );
				self::check( $rows, 'Persistencia', $label, $missing_columns ? 'error' : 'ok', $missing_columns ? 'Columnas ausentes: ' . implode( ', ', $missing_columns ) : 'Tabla y columnas esenciales disponibles.' );
			}
		}
		self::check( $rows, 'Compras', 'Consulta de estados', class_exists( 'TCSUP_Purchasing', false ) && is_array( TCSUP_Purchasing::active_order_statuses() ) ? 'ok' : 'error', 'Filtro de estados cargado; no se consultan ni modifican pedidos.' );
		return $rows;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para ejecutar el autotest.', 'taxocommerce-suppliers' ) );
		}
		echo '<section class="tcsup-card"><h2>Autotest de Suppliers</h2><p>Diagnóstico manual y de solo lectura. No importa CSV, no modifica productos ni precios, no descarga imágenes, no crea pedidos y no hace solicitudes HTTP.</p>';
		echo '<form method="post">';
		wp_nonce_field( 'tcsup_autotest' );
		echo '<input type="hidden" name="tcsup_run_autotest" value="1">';
		submit_button( 'Ejecutar autotest', 'secondary', 'submit', false );
		echo '</form>';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified before executing the diagnostics.
		if ( ! isset( $_POST['tcsup_run_autotest'] ) ) {
			echo '<p class="description">Los resultados solo se muestran cuando se pulsa el botón; no se guardan.</p></section>';
			return;
		}
		check_admin_referer( 'tcsup_autotest' );
		$rows = self::run();
		$counts = array( 'ok' => 0, 'warning' => 0, 'error' => 0 );
		foreach ( $rows as $row ) {
			$counts[ $row['status'] ]++;
		}
		echo '<h3>Resumen</h3><p>' . esc_html( count( $rows ) ) . ' comprobaciones: ' . esc_html( $counts['ok'] ) . ' correctas, ' . esc_html( $counts['warning'] ) . ' avisos y ' . esc_html( $counts['error'] ) . ' errores.</p>';
		$group = '';
		foreach ( $rows as $row ) {
			if ( $group !== $row['group'] ) {
				if ( $group ) {
					echo '</tbody></table>';
				}
				$group = $row['group'];
				echo '<h3>' . esc_html( $group ) . '</h3><table class="widefat striped"><thead><tr><th>Comprobación</th><th>Estado</th><th>Detalle</th></tr></thead><tbody>';
			}
			$names = array( 'ok' => 'Correcto', 'warning' => 'Aviso', 'error' => 'Error' );
			echo '<tr><th scope="row">' . esc_html( $row['name'] ) . '</th><td>' . esc_html( $names[ $row['status'] ] ) . '</td><td>' . esc_html( $row['detail'] ) . '</td></tr>';
		}
		if ( $group ) {
			echo '</tbody></table>';
		}
		echo '<p class="description">Autotest de disponibilidad, no de resultados comerciales o de importación. No repara ni modifica tablas.</p></section>';
	}
}
