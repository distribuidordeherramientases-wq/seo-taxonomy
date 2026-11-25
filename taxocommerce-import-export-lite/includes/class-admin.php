<?php
/** Single-file CSV wizard. Uses only native WordPress and WooCommerce APIs. */
namespace TaxoCommerce\ImportExportLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin {
	const MAX_CSV_BYTES = 31457280;
	const CHUNK = 25;

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_tciel_upload', array( __CLASS__, 'upload' ) );
		add_action( 'admin_post_tciel_export', array( __CLASS__, 'export' ) );
		add_action( 'wp_ajax_tciel_import_step', array( __CLASS__, 'step' ) );
	}

	public static function allowed() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acceso no autorizado.', 'taxocommerce-import-export-lite' ), '', array( 'response' => 403 ) );
		}
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function menu() {
		add_menu_page( __( 'Import-Export Lite', 'taxocommerce-import-export-lite' ), __( 'Import-Export Lite', 'taxocommerce-import-export-lite' ), 'manage_woocommerce', SLUG, array( __CLASS__, 'render' ), 'dashicons-database-import', 58 );
	}

	public static function assets() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin screen selector.
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( SLUG !== $page ) return;
		wp_enqueue_style( SLUG, plugins_url( '../assets/admin.css', __FILE__ ), array(), VERSION );
		wp_enqueue_script( SLUG, plugins_url( '../assets/admin.js', __FILE__ ), array(), VERSION, true );
		wp_localize_script( SLUG, 'tcielWizard', array(
			'endpoint' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'tciel_step' ),
			'messages' => array(
				'running' => __( 'Importando por bloques…', 'taxocommerce-import-export-lite' ),
				'done' => __( 'Importación finalizada.', 'taxocommerce-import-export-lite' ),
				'error' => __( 'Error de conexión: puedes reintentar sin duplicar la importación.', 'taxocommerce-import-export-lite' ),
				'duplicate' => __( 'Dos columnas están asignadas al mismo campo.', 'taxocommerce-import-export-lite' ),
			),
		) );
	}

	public static function render() {
		self::allowed();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selector.
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'import';
		if ( ! in_array( $tab, array( 'import', 'export', 'check', 'manual', 'technical' ), true ) ) $tab = 'import';
		echo '<div class="wrap tciel-admin"><div class="tciel-header"><h1>' . esc_html__( 'TaxoCommerce Import-Export Lite', 'taxocommerce-import-export-lite' ) . '</h1><p>' . esc_html__( 'Importación y exportación nativa de WordPress y WooCommerce. Sin dependencia de otros plugins TaxoCommerce.', 'taxocommerce-import-export-lite' ) . '</p>';
		echo '<span class="tciel-version">' . esc_html__( 'Versión', 'taxocommerce-import-export-lite' ) . ' ' . esc_html( VERSION ) . ' · ' . esc_html( RELEASE_DATE ) . '</span></div>';
		$tabs = array( 'import' => __( 'Importar CSV', 'taxocommerce-import-export-lite' ), 'export' => __( 'Exportar CSV', 'taxocommerce-import-export-lite' ), 'check' => __( 'Chequeo', 'taxocommerce-import-export-lite' ), 'manual' => __( 'Manual de usuario', 'taxocommerce-import-export-lite' ), 'technical' => __( 'Manual técnico', 'taxocommerce-import-export-lite' ) );
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Secciones de Import-Export Lite', 'taxocommerce-import-export-lite' ) . '">';
		foreach ( $tabs as $key => $label ) {
			echo '<a class="nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( array( 'tab' => $key ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		if ( 'import' === $tab ) self::render_import();
		if ( 'export' === $tab ) self::render_export();
		if ( 'check' === $tab ) AutoCheck::render();
		if ( 'manual' === $tab ) Manual::user();
		if ( 'technical' === $tab ) Manual::technical();
		echo '</div>';
	}

	private static function selector( $name, $selected = '' ) {
		echo '<select name="' . esc_attr( $name ) . '" id="tciel-type">';
		foreach ( CSV::types() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $selected, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	private static function transient_key( $token ) {
		return 'tciel_' . get_current_user_id() . '_' . sanitize_key( $token );
	}

	public static function render_import() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only reference to the current user's private upload state.
		$token = isset( $_GET['token'] ) && is_string( $_GET['token'] ) ? sanitize_key( wp_unslash( $_GET['token'] ) ) : '';
		$state = $token ? get_transient( self::transient_key( $token ) ) : false;
		echo '<section class="tciel-panel"><h2>' . esc_html__( 'Importar un CSV', 'taxocommerce-import-export-lite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Selecciona un archivo y asigna las columnas. Por defecto crea registros nuevos y actualiza los existentes por SKU o slug. No necesitas categorías.', 'taxocommerce-import-export-lite' ) . '</p>';
		if ( ! is_array( $state ) ) {
			echo '<form enctype="multipart/form-data" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tciel_upload">';
			wp_nonce_field( 'tciel_upload' );
			echo '<label for="tciel-file">' . esc_html__( 'Archivo CSV', 'taxocommerce-import-export-lite' ) . '</label> <input id="tciel-file" type="file" name="csv_file" accept=".csv,text/csv" required> ';
			submit_button( __( 'Leer columnas', 'taxocommerce-import-export-lite' ), 'primary', 'submit', false );
			echo '</form>';
		} else {
			echo '<p><strong>' . esc_html( $state['filename'] ) . '</strong> · ' . esc_html( count( $state['headers'] ) ) . ' ' . esc_html__( 'columnas', 'taxocommerce-import-export-lite' ) . '</p>';
			echo '<form id="tciel-import-form"><input type="hidden" name="token" value="' . esc_attr( $token ) . '"><p><label for="tciel-type">' . esc_html__( 'Importar como', 'taxocommerce-import-export-lite' ) . '</label> ';
			self::selector( 'type', 'product' );
			echo ' <label for="tciel-mode">' . esc_html__( 'Acción', 'taxocommerce-import-export-lite' ) . '</label> <select id="tciel-mode" name="mode"><option value="upsert">' . esc_html__( 'Crear nuevos y actualizar existentes (recomendado)', 'taxocommerce-import-export-lite' ) . '</option><option value="update">' . esc_html__( 'Solo actualizar existentes', 'taxocommerce-import-export-lite' ) . '</option></select></p>';
			echo '<p class="description">' . esc_html__( 'Identificación por SKU o slug. ID, categorías, etiquetas e imágenes no se asignan automáticamente desde archivos externos; así evitas modificar registros ajenos o exigir categorías inexistentes.', 'taxocommerce-import-export-lite' ) . '</p>';
			echo '<div class="tciel-scroll"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Columna del CSV', 'taxocommerce-import-export-lite' ) . '</th><th>' . esc_html__( 'Campo de destino', 'taxocommerce-import-export-lite' ) . '</th><th>' . esc_html__( 'Ejemplo', 'taxocommerce-import-export-lite' ) . '</th></tr></thead><tbody>';
			foreach ( $state['headers'] as $i => $name ) {
				echo '<tr><td>' . esc_html( $name ) . '</td><td><select class="tciel-field-map" name="map[' . esc_attr( (string) $i ) . ']" data-column="' . esc_attr( $name ) . '"></select></td><td>' . esc_html( isset( $state['sample'][ $i ] ) ? $state['sample'][ $i ] : '' ) . '</td></tr>';
			}
			echo '</tbody></table></div><p>';
			submit_button( __( 'Iniciar importación', 'taxocommerce-import-export-lite' ), 'primary', 'start', false );
			echo '</p></form><p id="tciel-progress" role="status" aria-live="polite"></p><pre id="tciel-errors" class="tciel-errors"></pre>';
			$definitions = array();
			foreach ( CSV::types() as $key => $label ) $definitions[ $key ] = CSV::fields( $key );
			echo '<script type="application/json" id="tciel-fields">' . wp_json_encode( $definitions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Encoded JSON with HTML-safe hex escapes.
		}
		echo '</section>';
	}

	public static function render_export() {
		echo '<section class="tciel-panel"><h2>' . esc_html__( 'Exportar datos nativos a CSV', 'taxocommerce-import-export-lite' ) . '</h2><p>' . esc_html__( 'Selecciona el tipo y los campos que quieres exportar.', 'taxocommerce-import-export-lite' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tciel_export">';
		wp_nonce_field( 'tciel_export' );
		self::selector( 'type' );
		foreach ( CSV::types() as $type => $title ) {
			echo '<fieldset class="tciel-field-group" data-type="' . esc_attr( $type ) . '"><legend><strong>' . esc_html( $title ) . '</strong></legend>';
			foreach ( CSV::fields( $type ) as $field => $name ) {
				echo '<label><input type="checkbox" name="fields[' . esc_attr( $type ) . '][]" value="' . esc_attr( $field ) . '" checked> ' . esc_html( $name ) . '</label>';
			}
			echo '</fieldset>';
		}
		submit_button( __( 'Descargar CSV', 'taxocommerce-import-export-lite' ) );
		echo '</form></section>';
	}

	public static function upload() {
		self::allowed();
		check_admin_referer( 'tciel_upload' );
		if ( ! isset( $_FILES['csv_file'] ) || ! is_array( $_FILES['csv_file'] ) ) {
			wp_die( esc_html__( 'No se ha adjuntado ningún archivo.', 'taxocommerce-import-export-lite' ) );
		}
		$file = $_FILES['csv_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Uploaded file metadata is checked immediately below.
		$name = isset( $file['name'] ) && is_string( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '';
		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		$tmp = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? -1 ) || $size < 1 || $size > self::MAX_CSV_BYTES || 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) || ! is_uploaded_file( $tmp ) ) {
			wp_die( esc_html__( 'Adjunta un CSV válido de hasta 30 MB.', 'taxocommerce-import-export-lite' ) );
		}
		// Store the CSV outside the public uploads directory using WordPress upload handling.
		// The filter is scoped to this single operation and always removed afterwards.
		$temp_upload_dir = static function ( $uploads ) {
			$uploads['path']    = trailingslashit( get_temp_dir() );
			$uploads['basedir'] = trailingslashit( get_temp_dir() );
			$uploads['subdir']  = '';
			$uploads['url']     = '';
			$uploads['baseurl'] = '';
			return $uploads;
		};
		add_filter( 'upload_dir', $temp_upload_dir );
		$uploaded = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => array( 'csv' => 'text/csv' ),
			)
		);
		remove_filter( 'upload_dir', $temp_upload_dir );
		$path = ! empty( $uploaded['file'] ) ? $uploaded['file'] : '';
		if ( ! $path || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'No se pudo almacenar temporalmente el CSV.', 'taxocommerce-import-export-lite' ) );
		}
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Temporary uploaded CSV, read-only.
		if ( ! $handle ) {
			wp_delete_file( $path );
			wp_die( esc_html__( 'El CSV no se puede abrir.', 'taxocommerce-import-export-lite' ) );
		}
		$first = fgets( $handle, 32768 );
		$counts = array();
		foreach ( array( ',', ';', "\t" ) as $delimiter ) $counts[ $delimiter ] = is_string( $first ) ? count( str_getcsv( $first, $delimiter, '"', '' ) ) : 0;
		arsort( $counts );
		$delimiter = key( $counts );
		rewind( $handle );
		$headers = fgetcsv( $handle, 32768, $delimiter, '"', '' );
		$sample = fgetcsv( $handle, 65536, $delimiter, '"', '' );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- End uploaded CSV inspection.
		if ( ! is_array( $headers ) || count( $headers ) < 1 || count( $headers ) > 200 || ! is_array( $sample ) || count( $sample ) !== count( $headers ) ) {
			wp_delete_file( $path );
			wp_die( esc_html__( 'Cabecera o primera fila CSV incorrectas.', 'taxocommerce-import-export-lite' ) );
		}
		$headers[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $headers[0] );
		$token = str_replace( '-', '', wp_generate_uuid4() );
		$state = array( 'path' => $path, 'filename' => $name, 'headers' => $headers, 'sample' => $sample, 'delimiter' => $delimiter, 'offset' => 0, 'processed' => 0, 'saved' => 0, 'errors' => 0, 'notices' => array(), 'started' => false, 'done' => false );
		set_transient( self::transient_key( $token ), $state, HOUR_IN_SECONDS );
		wp_safe_redirect( self::url( array( 'tab' => 'import', 'token' => $token ) ) );
		exit;
	}

	public static function step() {
		self::allowed();
		check_ajax_referer( 'tciel_step', 'nonce' );
		$token = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$key = self::transient_key( $token );
		$state = get_transient( $key );
		if ( ! is_array( $state ) || empty( $state['path'] ) ) wp_send_json_error( array( 'message' => __( 'Archivo caducado. Vuelve a cargar el CSV.', 'taxocommerce-import-export-lite' ) ) );
		if ( ! $state['started'] ) {
			$type = isset( $_POST['type'] ) && is_string( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
			$mode = isset( $_POST['mode'] ) && is_string( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
			$raw = isset( $_POST['map'] ) && is_array( $_POST['map'] ) ? wp_unslash( $_POST['map'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Values validated via explicit field allowlist.
			if ( ! isset( CSV::types()[ $type ] ) || ! in_array( $mode, array( 'upsert', 'update' ), true ) ) wp_send_json_error( array( 'message' => __( 'Tipo o acción no válidos.', 'taxocommerce-import-export-lite' ) ) );
			$map = array();
			foreach ( $raw as $index => $field ) {
				$field = is_scalar( $field ) ? sanitize_key( (string) $field ) : '';
				if ( '' !== $field && ctype_digit( (string) $index ) && isset( $state['headers'][ (int) $index ] ) && isset( CSV::fields( $type )[ $field ] ) ) $map[ (int) $index ] = $field;
			}
			if ( ! $map || count( $map ) !== count( array_unique( array_values( $map ) ) ) ) wp_send_json_error( array( 'message' => __( 'Asignación de columnas vacía o duplicada.', 'taxocommerce-import-export-lite' ) ) );
			$state['map'] = $map;
			$state['type'] = $type;
			$state['mode'] = $mode;
			$state['started'] = true;
		}
		if ( $state['done'] ) wp_send_json_success( self::progress( $state ) );
		$handle = fopen( $state['path'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Private temporary CSV, read-only.
		if ( ! $handle ) wp_send_json_error( array( 'message' => __( 'CSV temporal no disponible.', 'taxocommerce-import-export-lite' ) ) );
		if ( 0 === (int) $state['offset'] ) fgetcsv( $handle, 32768, $state['delimiter'], '"', '' );
		else fseek( $handle, (int) $state['offset'] );
		$count = 0;
		while ( $count < self::CHUNK && false !== ( $row = fgetcsv( $handle, 65536, $state['delimiter'], '"', '' ) ) ) {
			++$count;
			++$state['processed'];
			if ( ! is_array( $row ) || count( $row ) !== count( $state['headers'] ) ) $result = new \WP_Error( 'csv_columns', __( 'Número de columnas incorrecto.', 'taxocommerce-import-export-lite' ) );
			else $result = CSV::import( $state['type'], CSV::map_row( $row, $state['map'] ), $state['mode'] );
			if ( is_wp_error( $result ) ) {
				++$state['errors'];
				$state['notices'][] = sprintf( /* translators: 1: CSV row number; 2: error */ __( 'Fila %1$d: %2$s', 'taxocommerce-import-export-lite' ), $state['processed'] + 1, $result->get_error_message() );
				$state['notices'] = array_slice( $state['notices'], -20 );
			} else ++$state['saved'];
		}
		$state['offset'] = ftell( $handle );
		$state['done'] = feof( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- End private temporary CSV chunk.
		if ( $state['done'] ) wp_delete_file( $state['path'] );
		set_transient( $key, $state, HOUR_IN_SECONDS );
		wp_send_json_success( self::progress( $state ) );
	}

	private static function progress( $state ) {
		return array( 'processed' => $state['processed'], 'saved' => $state['saved'], 'errors' => $state['errors'], 'notices' => $state['notices'], 'done' => $state['done'] );
	}

	public static function export() {
		self::allowed();
		check_admin_referer( 'tciel_export' );
		$type = isset( $_POST['type'] ) && is_string( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		if ( ! isset( CSV::types()[ $type ] ) ) wp_die( esc_html__( 'Tipo de datos incorrecto.', 'taxocommerce-import-export-lite' ) );
		$selected = isset( $_POST['fields'][ $type ] ) && is_array( $_POST['fields'][ $type ] ) ? wp_unslash( $_POST['fields'][ $type ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every value filtered with allowlist below.
		$allowed = CSV::fields( $type );
		$fields = array_values( array_unique( array_filter( array_map( 'sanitize_key', $selected ), static function ( $field ) use ( $allowed ) { return isset( $allowed[ $field ] ); } ) ) );
		if ( ! $fields ) wp_die( esc_html__( 'Selecciona al menos un campo.', 'taxocommerce-import-export-lite' ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'taxocommerce-lite-' . $type . '-' . gmdate( 'Ymd-His' ) . '.csv' ) . '"' );
		$out = fopen( 'php://output', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming CSV response to authorized administrator.
		fputcsv( $out, $fields, ',', '"', '' );
		for ( $page = 1; $page <= 1000; ++$page ) {
			$ids = CSV::export_ids( $type, $page, 200 );
			foreach ( $ids as $id ) {
				$row = array();
				foreach ( $fields as $field ) $row[] = CSV::csv_safe( CSV::export_value( $type, $id, $field ) );
				fputcsv( $out, $row, ',', '"', '' );
			}
			if ( count( $ids ) < 200 ) break;
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- End CSV stream.
		exit;
	}
}
