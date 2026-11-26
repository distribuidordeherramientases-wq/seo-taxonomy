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
				'checking' => __( 'Comprobando todas las filas antes de guardar…', 'taxocommerce-import-export-lite' ),
				'ready' => __( 'Prechequeo correcto. Confirma para importar.', 'taxocommerce-import-export-lite' ),
				'invalid' => __( 'Prechequeo con errores. Corrige el CSV antes de importar.', 'taxocommerce-import-export-lite' ),
				'validate' => __( 'Comprobar CSV', 'taxocommerce-import-export-lite' ),
				'confirm' => __( 'Confirmar e importar', 'taxocommerce-import-export-lite' ),
				'done' => __( 'Importación finalizada.', 'taxocommerce-import-export-lite' ),
				'error' => __( 'Error de conexión: puedes reintentar sin duplicar la importación.', 'taxocommerce-import-export-lite' ),
			),
		) );
	}

	public static function render() {
		self::allowed();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selector.
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'import';
		if ( ! in_array( $tab, array( 'import', 'export', 'check', 'manual', 'technical' ), true ) ) $tab = 'import';
		echo '<div class="wrap tciel-admin">';
		Header::render();
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

	private static function transient_key( $token ) {
		return 'tciel_' . get_current_user_id() . '_' . sanitize_key( $token );
	}

	/** Fixed content blocks share the same native CSV schema and never a type selector. */
	public static function render_import() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only reference to the current user's private upload state.
		$token = isset( $_GET['token'] ) && is_string( $_GET['token'] ) ? sanitize_key( wp_unslash( $_GET['token'] ) ) : '';
		$state = $token ? get_transient( self::transient_key( $token ) ) : false;
		echo '<p>' . esc_html__( 'Carga el CSV en el bloque correspondiente. Las columnas se reconocen automáticamente: se crean los registros nuevos y se actualizan los existentes. Se comprueba todo el archivo antes de guardar.', 'taxocommerce-import-export-lite' ) . '</p>';
		foreach ( CSV::types() as $type => $title ) {
			echo '<section class="tciel-panel" id="tciel-import-' . esc_attr( $type ) . '"><h2>' . esc_html( $title ) . '</h2>';
			echo '<form enctype="multipart/form-data" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tciel_upload"><input type="hidden" name="type" value="' . esc_attr( $type ) . '">';
			wp_nonce_field( 'tciel_upload_' . $type );
			echo '<label for="tciel-file-' . esc_attr( $type ) . '">' . esc_html__( 'Archivo CSV', 'taxocommerce-import-export-lite' ) . '</label> <input id="tciel-file-' . esc_attr( $type ) . '" type="file" name="csv_file" accept=".csv,text/csv" required> ';
			submit_button( __( 'Cargar CSV', 'taxocommerce-import-export-lite' ), 'primary', 'submit', false );
			echo '</form>';
			if ( is_array( $state ) && ( $state['type'] ?? '' ) === $type && isset( $state['map'], $state['ignored'] ) ) {
				echo '<p><strong>' . esc_html( $state['filename'] ) . '</strong></p><p>' . esc_html__( 'Campos reconocidos:', 'taxocommerce-import-export-lite' ) . ' ' . esc_html( implode( ', ', $state['map'] ) ) . '</p>';
				if ( $state['ignored'] ) {
					echo '<p>' . esc_html__( 'Columnas omitidas (sin modificar datos):', 'taxocommerce-import-export-lite' ) . ' ' . esc_html( implode( ', ', $state['ignored'] ) ) . '</p>';
				}
				echo '<form id="tciel-import-form"><input type="hidden" name="token" value="' . esc_attr( $token ) . '">';
				submit_button( __( 'Comprobar CSV', 'taxocommerce-import-export-lite' ), 'primary', 'start', false );
				echo '</form><p id="tciel-progress" role="status" aria-live="polite"></p><pre id="tciel-errors" class="tciel-errors"></pre>';
			}
			echo '</section>';
		}
	}

	public static function render_export() {
		echo '<p>' . esc_html__( 'Descarga directamente el CSV del bloque que necesites. Por defecto incluye todos los campos compatibles.', 'taxocommerce-import-export-lite' ) . '</p>';
		foreach ( CSV::types() as $type => $title ) {
			echo '<section class="tciel-panel"><h2>' . esc_html( $title ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tciel_export"><input type="hidden" name="type" value="' . esc_attr( $type ) . '">';
			wp_nonce_field( 'tciel_export_' . $type );
			echo '<details><summary>' . esc_html__( 'Campos opcionales de exportación', 'taxocommerce-import-export-lite' ) . '</summary><fieldset class="tciel-field-group">';
			foreach ( CSV::fields( $type ) as $field => $name ) {
				echo '<label><input type="checkbox" name="fields[' . esc_attr( $type ) . '][]" value="' . esc_attr( $field ) . '" checked> ' . esc_html( $name ) . '</label>';
			}
			echo '</fieldset></details>';
			submit_button( __( 'Descargar CSV', 'taxocommerce-import-export-lite' ) );
			echo '</form></section>';
		}
	}

	public static function upload() {
		self::allowed();
		$type = isset( $_POST['type'] ) && is_string( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		if ( ! isset( CSV::types()[ $type ] ) ) wp_die( esc_html__( 'Tipo de datos incorrecto.', 'taxocommerce-import-export-lite' ) );
		check_admin_referer( 'tciel_upload_' . $type );
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
		$mapping = CSV::automatic_mapping( $type, $headers );
		if ( is_wp_error( $mapping ) ) {
			wp_delete_file( $path );
			wp_die( esc_html( $mapping->get_error_message() ), '', array( 'back_link' => true ) );
		}
		$token = str_replace( '-', '', wp_generate_uuid4() );
		$state = array( 'type' => $type, 'map' => $mapping['map'], 'ignored' => $mapping['ignored'], 'path' => $path, 'filename' => $name, 'headers' => $headers, 'delimiter' => $delimiter, 'offset' => 0, 'processed' => 0, 'saved' => 0, 'errors' => 0, 'notices' => array(), 'done' => false, 'phase' => 'uploaded', 'checked' => 0, 'seen' => array() );
		set_transient( self::transient_key( $token ), $state, HOUR_IN_SECONDS );
		wp_safe_redirect( self::url( array( 'tab' => 'import', 'token' => $token ) ) . '#tciel-import-' . $type );
		exit;
	}

	/** Two-pass browser-assisted import: read-only preflight, then explicit confirmation. */
	public static function step() {
		self::allowed();
		check_ajax_referer( 'tciel_step', 'nonce' );
		$token = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$key = self::transient_key( $token );
		$state = get_transient( $key );
		if ( ! is_array( $state ) || empty( $state['path'] ) ) wp_send_json_error( array( 'message' => __( 'Archivo caducado. Vuelve a cargar el CSV.', 'taxocommerce-import-export-lite' ) ) );
		$phase = isset( $state['phase'] ) ? $state['phase'] : 'uploaded';
		if ( 'done' === $phase ) wp_send_json_success( self::progress( $state ) );

		// The upload token binds the content type and header mapping; AJAX callers cannot change them.
		if ( ! isset( $state['type'], $state['map'], $state['ignored'] ) || ! isset( CSV::types()[ $state['type'] ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Vuelve a cargar el CSV en su bloque de contenido.', 'taxocommerce-import-export-lite' ) ) );
		}
		if ( in_array( $phase, array( 'uploaded', 'invalid', 'ready' ), true ) ) {
			$confirmed = isset( $_POST['confirmed'] ) && is_string( $_POST['confirmed'] ) && '1' === sanitize_key( wp_unslash( $_POST['confirmed'] ) );
			if ( 'ready' === $phase && $confirmed ) {
				$state['phase'] = 'importing';
				$state['offset'] = 0;
				$state['processed'] = 0;
				$state['saved'] = 0;
				$state['errors'] = 0;
				$state['notices'] = array();
			} else {
				$state['phase'] = 'checking';
				$state['offset'] = 0;
				$state['checked'] = 0;
				$state['errors'] = 0;
				$state['notices'] = array();
				$state['seen'] = array();
			}
			$phase = $state['phase'];
		}
		$handle = fopen( $state['path'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Private uploaded CSV, read-only.
		if ( ! $handle ) wp_send_json_error( array( 'message' => __( 'CSV temporal no disponible.', 'taxocommerce-import-export-lite' ) ) );
		if ( 0 === (int) $state['offset'] ) fgetcsv( $handle, 32768, $state['delimiter'], '"', '' );
		else fseek( $handle, (int) $state['offset'] );
		$count = 0;
		while ( $count < self::CHUNK && false !== ( $row = fgetcsv( $handle, 65536, $state['delimiter'], '"', '' ) ) ) {
			++$count;
			$number = 'checking' === $phase ? ++$state['checked'] : ++$state['processed'];
			if ( ! is_array( $row ) || count( $row ) !== count( $state['headers'] ) ) {
				$result = new \WP_Error( 'csv_columns', __( 'Número de columnas incorrecto.', 'taxocommerce-import-export-lite' ) );
			} else {
				$data = CSV::map_row( $row, $state['map'] );
				if ( 'checking' === $phase ) {
					$result = CSV::precheck( $state['type'], $data, 'upsert' );
					if ( ! is_wp_error( $result ) ) {
						$identity = 'product' === $state['type'] ? ( ! empty( $data['sku'] ) ? 'sku:' . $data['sku'] : 'slug:' . ( $data['slug'] ?? '' ) ) :
							( ! empty( $data['slug'] ) ? 'slug:' . $data['slug'] : ( ! empty( $data['name'] ) ? 'name:' . $data['name'] : 'id:' . ( $data['id'] ?? '' ) ) );
						$hash = hash( 'sha256', $identity );
						if ( isset( $state['seen'][ $hash ] ) ) $result = new \WP_Error( 'repeated_identity', __( 'Esta identidad se repite en el CSV.', 'taxocommerce-import-export-lite' ) );
						else $state['seen'][ $hash ] = true;
					}
				} else {
					$result = CSV::import( $state['type'], $data, 'upsert' );
				}
			}
			if ( is_wp_error( $result ) ) {
				++$state['errors'];
				$state['notices'][] = sprintf( /* translators: 1: CSV row number; 2: error */ __( 'Fila %1$d: %2$s', 'taxocommerce-import-export-lite' ), $number + 1, $result->get_error_message() );
				$state['notices'] = array_slice( $state['notices'], -30 );
			} elseif ( 'importing' === $phase ) {
				++$state['saved'];
			}
		}
		$state['offset'] = ftell( $handle );
		$finished = feof( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- End private temporary CSV chunk.
		if ( $finished ) {
			if ( 'checking' === $phase ) {
				$state['phase'] = $state['errors'] ? 'invalid' : 'ready';
				$state['offset'] = 0;
				unset( $state['seen'] );
			} else {
				$state['phase'] = 'done';
				$state['done'] = true;
				wp_delete_file( $state['path'] );
			}
		}
		set_transient( $key, $state, HOUR_IN_SECONDS );
		wp_send_json_success( self::progress( $state ) );
	}

	private static function progress( $state ) {
		return array(
			'processed' => $state['processed'], 'checked' => isset( $state['checked'] ) ? $state['checked'] : 0,
			'saved' => $state['saved'], 'errors' => $state['errors'], 'notices' => $state['notices'],
			'phase' => $state['phase'], 'done' => ! empty( $state['done'] ),
		);
	}

	public static function export() {
		self::allowed();
		$type = isset( $_POST['type'] ) && is_string( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		if ( ! isset( CSV::types()[ $type ] ) ) wp_die( esc_html__( 'Tipo de datos incorrecto.', 'taxocommerce-import-export-lite' ) );
		check_admin_referer( 'tciel_export_' . $type );
		$selected = isset( $_POST['fields'][ $type ] ) && is_array( $_POST['fields'][ $type ] ) ? wp_unslash( $_POST['fields'][ $type ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every value filtered with allowlist below.
		$allowed = CSV::fields( $type );
		$fields = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $selected ), static function ( $field ) use ( $allowed ) { return isset( $allowed[ $field ] ); } ) ) );
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
