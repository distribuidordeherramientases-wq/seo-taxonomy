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
				'rows' => __( 'Filas:', 'taxocommerce-import-export-lite' ),
				'saved' => __( 'Guardadas:', 'taxocommerce-import-export-lite' ),
				'errors' => __( 'Errores:', 'taxocommerce-import-export-lite' ),
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

	/**
	 * Resolve a writable private directory. Never fall back to a directory
	 * inside WordPress, wp-content or the web server's document root.
	 *
	 * @param string $candidate Directory supplied by PHP or WordPress.
	 * @return string
	 */
	private static function private_temp_dir( $candidate ) {
		$directory = realpath( $candidate );
		if ( false === $directory || ! is_dir( $directory ) || ! wp_is_writable( $directory ) ) {
			return '';
		}
		$roots = array( ABSPATH, WP_CONTENT_DIR );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Server document root is canonicalized with realpath, never used as an upload destination.
		if ( isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) && '' !== $_SERVER['DOCUMENT_ROOT'] ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserve the actual filesystem path for the containment check.
			$roots[] = wp_unslash( $_SERVER['DOCUMENT_ROOT'] );
		}
		$normalized = trailingslashit( strtolower( wp_normalize_path( $directory ) ) );
		foreach ( $roots as $root ) {
			$resolved = realpath( $root );
			if ( false !== $resolved && 0 === strpos( $normalized, trailingslashit( strtolower( wp_normalize_path( $resolved ) ) ) ) ) {
				return '';
			}
		}
		return $directory;
	}

	/**
	 * Receive an actual PHP HTTP upload as private, inert CSV data.
	 * CSV is parsed by the existing schema/preflight before any content is
	 * written. No Media upload hooks, public URLs or browser MIME are used.
	 *
	 * @param array $file PHP upload metadata.
	 * @return array|\WP_Error
	 */
	public static function store_upload( $file ) {
		$error = isset( $file['error'] ) && is_int( $file['error'] ) ? $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $error ) {
			if ( in_array( $error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
				return new \WP_Error( 'upload_size', __( 'El servidor ha rechazado el tamaño del CSV. Comprueba el límite de subida.', 'taxocommerce-import-export-lite' ) );
			}
			if ( in_array( $error, array( UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE ), true ) ) {
				return new \WP_Error( 'upload_php_storage', __( 'PHP no pudo recibir el CSV en su directorio temporal. Comprueba los permisos y el espacio disponible.', 'taxocommerce-import-export-lite' ) );
			}
			return new \WP_Error( 'upload_incomplete', __( 'No se ha recibido un CSV completo. Vuelve a seleccionar el archivo.', 'taxocommerce-import-export-lite' ) );
		}

		$name = isset( $file['name'] ) && is_string( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '';
		$size = isset( $file['size'] ) && is_int( $file['size'] ) ? $file['size'] : 0;
		$tmp  = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		if ( $size < 1 || $size > self::MAX_CSV_BYTES || 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) || ! is_uploaded_file( $tmp ) ) {
			return new \WP_Error( 'upload_invalid', __( 'Adjunta un CSV válido de hasta 30 MB.', 'taxocommerce-import-export-lite' ) );
		}
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// The uploaded PHP temporary file is local to this worker, not an FTP
		// installation path. Use WordPress' direct filesystem implementation.
		if ( ! class_exists( '\WP_Filesystem_Direct', false ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		}
		$filesystem = new \WP_Filesystem_Direct( null );

		$directories = array_unique( array( get_temp_dir(), dirname( $tmp ) ) );
		$has_private = false;
		$has_reservation = false;
		foreach ( $directories as $candidate ) {
			$directory = self::private_temp_dir( $candidate );
			if ( '' === $directory ) {
				continue;
			}
			$has_private = true;
			// Opaque, uniquely reserved filename, never the client-provided name.
			$path = wp_tempnam( 'tciel-' . wp_generate_uuid4(), trailingslashit( $directory ) );
			if ( ! is_string( $path ) || ! is_file( $path ) || is_link( $path ) || realpath( dirname( $path ) ) !== $directory ) {
				continue;
			}
			$has_reservation = true;
			if ( ! $filesystem->chmod( $path, 0600 ) ) {
				wp_delete_file( $path );
				continue;
			}
			// PHP has already verified this source with is_uploaded_file(). A
			// local copy preserves the reservation; PHP cleans its upload at
			// request shutdown. No arbitrary files or Media uploads are copied.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Keep private server paths out of upload error responses.
			if ( ! @$filesystem->copy( $tmp, $path, true, 0600 ) ) {
				wp_delete_file( $path );
				continue;
			}
			clearstatcache( true, $path );
			if ( ! $filesystem->chmod( $path, 0600 ) || ! is_readable( $path ) || $size !== wp_filesize( $path ) ) {
				wp_delete_file( $path );
				return new \WP_Error( 'upload_integrity', __( 'El CSV temporal no es legible o no coincide con el tamaño recibido. No se ha importado ningún dato.', 'taxocommerce-import-export-lite' ) );
			}
			return array( 'path' => $path, 'name' => $name );
		}

		if ( ! $has_private ) {
			return new \WP_Error( 'upload_private_dir', __( 'No hay un directorio temporal privado y escribible. Revisa WP_TEMP_DIR o el directorio temporal de PHP.', 'taxocommerce-import-export-lite' ) );
		}
		if ( ! $has_reservation ) {
			return new \WP_Error( 'upload_reserve', __( 'No se pudo crear un archivo temporal privado. Comprueba los permisos y el espacio disponible.', 'taxocommerce-import-export-lite' ) );
		}
		return new \WP_Error( 'upload_move', __( 'El servidor no pudo copiar el CSV recibido al archivo temporal privado. Comprueba las restricciones de PHP y los permisos.', 'taxocommerce-import-export-lite' ) );
	}

	public static function upload() {
		self::allowed();
		$type = isset( $_POST['type'] ) && is_string( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		if ( ! isset( CSV::types()[ $type ] ) ) wp_die( esc_html__( 'Tipo de datos incorrecto.', 'taxocommerce-import-export-lite' ) );
		check_admin_referer( 'tciel_upload_' . $type );
		if ( ! isset( $_FILES['csv_file'] ) || ! is_array( $_FILES['csv_file'] ) ) {
			wp_die( esc_html__( 'No se ha adjuntado ningún archivo.', 'taxocommerce-import-export-lite' ), '', array( 'back_link' => true ) );
		}
		$file = $_FILES['csv_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- store_upload validates extension, size, upload provenance and errors.
		$stored = self::store_upload( $file );
		if ( is_wp_error( $stored ) ) {
			wp_die( esc_html( $stored->get_error_message() ), '', array( 'back_link' => true ) );
		}
		$path = $stored['path'];
		$name = $stored['name'];
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
		if ( ! is_array( $headers ) || count( $headers ) < 1 || count( $headers ) > 200 || ! is_array( $sample ) || count( $sample ) !== count( $headers ) || false !== strpos( implode( '', $headers ) . implode( '', $sample ), "\0" ) ) {
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
		if ( ! set_transient( self::transient_key( $token ), $state, HOUR_IN_SECONDS ) ) {
			wp_delete_file( $path );
			wp_die( esc_html__( 'No se pudo guardar el estado temporal de la importación. Vuelve a cargar el CSV.', 'taxocommerce-import-export-lite' ), '', array( 'back_link' => true ) );
		}
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
