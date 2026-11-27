<?php
/**
 * Fixed content blocks and authenticated job controls.
 *
 * @package TaxoCommerceImportExportPro
 */
namespace TaxoCommerce\ImportExportPro;

defined( 'ABSPATH' ) || exit;

final class Admin {
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_tciep_create_import', array( __CLASS__, 'create_import' ) );
		add_action( 'admin_post_tciep_create_export', array( __CLASS__, 'create_export' ) );
		add_action( 'admin_post_tciep_download', array( __CLASS__, 'download' ) );
		add_action( 'wp_ajax_tciep_command', array( __CLASS__, 'command' ) );
	}

	public static function allowed() {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Acceso no autorizado.', 'taxocommerce-import-export-pro' ), '', array( 'response' => 403 ) );
		}
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => ADMIN_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function menu() {
		add_menu_page( __( 'Import-Export Pro', 'taxocommerce-import-export-pro' ), __( 'Import-Export Pro', 'taxocommerce-import-export-pro' ), 'manage_woocommerce', ADMIN_SLUG, array( __CLASS__, 'render' ), 'dashicons-database-import', 58 );
	}

	public static function labels() {
		return array(
			'checking' => __( 'Comprobando CSV', 'taxocommerce-import-export-pro' ),
			'ready' => __( 'Comprobado. Confirma para importar.', 'taxocommerce-import-export-pro' ),
			'invalid' => __( 'Prechequeo con errores. No se ha importado el lote.', 'taxocommerce-import-export-pro' ),
			'importing' => __( 'Importando', 'taxocommerce-import-export-pro' ),
			'exporting' => __( 'Exportando', 'taxocommerce-import-export-pro' ),
			'done' => __( 'Finalizado', 'taxocommerce-import-export-pro' ),
			'pending' => __( 'Pendiente', 'taxocommerce-import-export-pro' ),
			'checked' => __( 'Comprobado', 'taxocommerce-import-export-pro' ),
			'imported' => __( 'Importado', 'taxocommerce-import-export-pro' ),
			'failed' => __( 'Error de importación. Revisa los registros ya guardados.', 'taxocommerce-import-export-pro' ),
			'paused' => __( 'Pausado', 'taxocommerce-import-export-pro' ),
			'confirm' => __( 'Confirmar e importar', 'taxocommerce-import-export-pro' ),
			'pause' => __( 'Pausar', 'taxocommerce-import-export-pro' ),
			'resume' => __( 'Reanudar', 'taxocommerce-import-export-pro' ),
			'clear' => __( 'Archivar y limpiar', 'taxocommerce-import-export-pro' ),
			'speed' => __( 'Aplicar velocidad', 'taxocommerce-import-export-pro' ),
			'checked_rows' => __( 'Comprobadas', 'taxocommerce-import-export-pro' ),
			'processed_rows' => __( 'Procesadas', 'taxocommerce-import-export-pro' ),
			'saved_rows' => __( 'Guardadas', 'taxocommerce-import-export-pro' ),
			'errors' => __( 'Errores', 'taxocommerce-import-export-pro' ),
			'rows' => __( 'Filas', 'taxocommerce-import-export-pro' ),
			'file' => __( 'Archivo', 'taxocommerce-import-export-pro' ),
			'status' => __( 'Estado', 'taxocommerce-import-export-pro' ),
			'ignored' => __( 'Columnas omitidas', 'taxocommerce-import-export-pro' ),
			'cpu' => __( 'CPU del proceso PHP', 'taxocommerce-import-export-pro' ),
			'memory' => __( 'Memoria PHP', 'taxocommerce-import-export-pro' ),
			'next' => __( 'Siguiente bloque', 'taxocommerce-import-export-pro' ),
			'seconds' => __( 'Segundos', 'taxocommerce-import-export-pro' ),
			'confirm_question' => __( '¿Confirmas la escritura de todos los CSV comprobados de este bloque?', 'taxocommerce-import-export-pro' ),
			'clear_question' => __( '¿Archivar y eliminar los archivos temporales? Los cambios ya guardados no se deshacen.', 'taxocommerce-import-export-pro' ),
			'resume_question' => __( '¿Reanudar desde el último punto de control? Si hubo una interrupción, revisa antes los registros guardados.', 'taxocommerce-import-export-pro' ),
			'connection' => __( 'No se pudo consultar el proceso. Recarga la página para comprobar su estado.', 'taxocommerce-import-export-pro' ),
		);
	}

	public static function assets() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin screen selector.
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ADMIN_SLUG !== $page ) {
			return;
		}
		wp_enqueue_style( SLUG, plugins_url( '../assets/admin.css', __FILE__ ), array(), VERSION );
		wp_enqueue_script( SLUG, plugins_url( '../assets/admin.js', __FILE__ ), array(), VERSION, true );
		wp_localize_script( SLUG, 'tciepBatch', array( 'endpoint' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'tciep_command' ), 'labels' => self::labels() ) );
	}

	public static function render() {
		self::allowed();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'import';
		$tabs = array(
			'import' => __( 'Importar CSV', 'taxocommerce-import-export-pro' ),
			'export' => __( 'Exportar CSV', 'taxocommerce-import-export-pro' ),
			'check' => __( 'Chequeo', 'taxocommerce-import-export-pro' ),
			'manual' => __( 'Manual de usuario', 'taxocommerce-import-export-pro' ),
			'technical' => __( 'Manual técnico', 'taxocommerce-import-export-pro' ),
		);
		if ( current_user_can( 'manage_options' ) ) {
			$tabs['code'] = __( 'Chequeo de código', 'taxocommerce-import-export-pro' );
		}
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'import';
		echo '<div class="wrap tciep-admin">';
		Header::render();
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Secciones de Import-Export Pro', 'taxocommerce-import-export-pro' ) . '">';
		foreach ( $tabs as $key => $label ) {
			echo '<a class="nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( array( 'tab' => $key ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		if ( 'import' === $tab || 'export' === $tab ) {
			self::blocks( $tab );
		} elseif ( 'check' === $tab ) {
			AutoCheck::render();
		} elseif ( 'manual' === $tab ) {
			Manual::user();
		} elseif ( 'technical' === $tab ) {
			Manual::technical();
		} elseif ( 'code' === $tab && current_user_can( 'manage_options' ) ) {
			\TaxoCommerce_Import_Export_Pro_Autotest_Code::render();
		}
		echo '</div>';
	}

	private static function speeds( $id, $speed = 3 ) {
		$labels = array(
			1 => __( '1 · Suave', 'taxocommerce-import-export-pro' ),
			2 => __( '2 · Baja', 'taxocommerce-import-export-pro' ),
			3 => __( '3 · Normal', 'taxocommerce-import-export-pro' ),
			4 => __( '4 · Alta', 'taxocommerce-import-export-pro' ),
			5 => __( '5 · Máxima', 'taxocommerce-import-export-pro' ),
		);
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html__( 'Velocidad', 'taxocommerce-import-export-pro' ) . '</label> <select id="' . esc_attr( $id ) . '" name="speed">';
		foreach ( $labels as $number => $label ) {
			echo '<option value="' . esc_attr( (string) $number ) . '"' . selected( $speed, $number, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
	}

	public static function blocks( $kind ) {
		echo '<p>' . esc_html( 'import' === $kind ? __( 'Carga uno o varios CSV en el bloque correspondiente. Se comprueban todos antes de confirmar la importación. No hay selectores de tipo ni asignación manual de columnas.', 'taxocommerce-import-export-pro' ) : __( 'Genera un CSV con todos los campos compatibles del bloque, sin seleccionar columnas. El archivo estará disponible al finalizar.', 'taxocommerce-import-export-pro' ) ) . '</p>';
		echo '<p>' . esc_html__( 'La velocidad regula filas, pausas, tiempo y memoria del proceso PHP, no un porcentaje de la CPU global. Si cierras el navegador, WP-Cron puede continuar los trabajos; depende de la configuración del alojamiento.', 'taxocommerce-import-export-pro' ) . '</p>';
		foreach ( CSV::types() as $type => $title ) {
			if ( ! Batch::allowed( $kind, $type ) ) {
				continue;
			}
			$state = Batch::state( get_current_user_id(), $kind, $type );
			echo '<section class="tciep-panel" id="tciep-' . esc_attr( $kind . '-' . $type ) . '"><h2>' . esc_html( $title ) . '</h2>';
			if ( ! $state ) {
				echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tciep_create_' . esc_attr( $kind ) . '"><input type="hidden" name="type" value="' . esc_attr( $type ) . '">';
				wp_nonce_field( 'tciep_create_' . $kind . '_' . $type );
				if ( 'import' === $kind ) {
					echo '<label for="tciep-files-' . esc_attr( $type ) . '">' . esc_html__( 'Archivos CSV', 'taxocommerce-import-export-pro' ) . '</label> <input type="file" id="tciep-files-' . esc_attr( $type ) . '" name="csv_files[]" accept=".csv,text/csv" multiple required><p class="description">' . esc_html__( 'Hasta 20 archivos, 30 MB por archivo y 100 MB en conjunto, sujetos al límite del servidor. Orden natural por nombre.', 'taxocommerce-import-export-pro' ) . '</p>';
				}
				self::speeds( 'tciep-speed-' . $kind . '-' . $type );
				submit_button( 'import' === $kind ? __( 'Cargar y comprobar CSV', 'taxocommerce-import-export-pro' ) : __( 'Generar CSV por lotes', 'taxocommerce-import-export-pro' ), 'primary', 'submit', false );
				echo '</form>';
			} else {
				$view = Batch::view( $state );
				echo '<div class="tciep-job" data-job="' . esc_attr( wp_json_encode( $view ) ) . '">';
				echo '<p class="tciep-progress" role="status" aria-live="polite">' . esc_html( self::labels()[ $state['phase'] ] ?? $state['phase'] ) . '</p><p class="tciep-telemetry"></p><div class="tciep-files"></div><div class="tciep-notices"></div><div class="tciep-controls">';
				self::speeds( 'tciep-job-speed-' . $kind . '-' . $type, $state['speed'] );
				echo '<button type="button" class="button" data-op="speed">' . esc_html__( 'Aplicar velocidad', 'taxocommerce-import-export-pro' ) . '</button> ';
				foreach ( array( 'confirm', 'pause', 'resume', 'clear' ) as $action ) {
					echo '<button type="button" class="button ' . ( 'confirm' === $action ? 'button-primary' : 'button-secondary' ) . '" data-op="' . esc_attr( $action ) . '" hidden>' . esc_html( self::labels()[ $action ] ) . '</button> ';
				}
				echo '</div>';
				if ( 'export' === $kind ) {
					echo '<form class="tciep-download" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" hidden><input type="hidden" name="action" value="tciep_download"><input type="hidden" name="type" value="' . esc_attr( $type ) . '"><input type="hidden" name="token" value="' . esc_attr( $state['token'] ) . '">';
					wp_nonce_field( 'tciep_download_' . $type );
					submit_button( __( 'Descargar CSV', 'taxocommerce-import-export-pro' ), 'primary', 'submit', false );
					echo '</form>';
				}
				echo '</div>';
			}
			$history = Batch::history( get_current_user_id(), $kind, $type );
			if ( $history ) {
				echo '<details class="tciep-history"><summary>' . esc_html__( 'Historial de trabajos de este bloque (últimos 5)', 'taxocommerce-import-export-pro' ) . '</summary><ul>';
				foreach ( $history as $entry ) {
					echo '<li>' . esc_html( (string) $entry['updated'] . ' · ' . ( self::labels()[ $entry['phase'] ] ?? $entry['phase'] ) ) . ' · ' . esc_html( (string) $entry['rows'] ) . ' ' . esc_html__( 'filas exportadas', 'taxocommerce-import-export-pro' );
					foreach ( $entry['files'] as $file ) {
						echo '<p>' . esc_html( (string) $file['name'] . ' · ' . ( self::labels()[ $file['phase'] ] ?? $file['phase'] ) . ' · ' . (int) $file['saved'] . ' ' . __( 'guardadas', 'taxocommerce-import-export-pro' ) . ' · ' . (int) $file['errors'] . ' ' . __( 'errores', 'taxocommerce-import-export-pro' ) ) . '</p>';
						foreach ( $file['notices'] as $notice ) {
							echo '<p>' . esc_html( $notice ) . '</p>';
						}
					}
					echo '</li>';
				}
				echo '</ul></details>';
			}
			echo '</section>';
		}
	}

	private static function redirect( $kind, $type, $result ) {
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'back_link' => true ) );
		}
		wp_safe_redirect( self::url( array( 'tab' => $kind ) ) . '#tciep-' . $kind . '-' . $type );
		exit;
	}

	public static function create_import() {
		self::allowed();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The allowlisted type selects the specific nonce checked immediately below.
		$type = isset( $_POST['type'] ) && is_string( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		if ( ! Batch::allowed( 'import', $type ) ) {
			wp_die( esc_html__( 'Tipo o permiso no válido.', 'taxocommerce-import-export-pro' ) );
		}
		check_admin_referer( 'tciep_create_import_' . $type );
		$speed = isset( $_POST['speed'] ) && is_scalar( $_POST['speed'] ) ? absint( wp_unslash( $_POST['speed'] ) ) : 3;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Batch validates each upload, size, error, extension and actual uploaded temp path before private CSV storage.
		$files = isset( $_FILES['csv_files'] ) && is_array( $_FILES['csv_files'] ) ? $_FILES['csv_files'] : array();
		self::redirect( 'import', $type, Batch::create_import( $type, $speed, $files ) );
	}

	public static function create_export() {
		self::allowed();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The allowlisted type selects the specific nonce checked immediately below.
		$type = isset( $_POST['type'] ) && is_string( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		if ( ! Batch::allowed( 'export', $type ) ) {
			wp_die( esc_html__( 'Tipo o permiso no válido.', 'taxocommerce-import-export-pro' ) );
		}
		check_admin_referer( 'tciep_create_export_' . $type );
		$speed = isset( $_POST['speed'] ) && is_scalar( $_POST['speed'] ) ? absint( wp_unslash( $_POST['speed'] ) ) : 3;
		self::redirect( 'export', $type, Batch::create_export( $type, $speed ) );
	}

	public static function command() {
		self::allowed();
		check_ajax_referer( 'tciep_command', 'nonce' );
		$kind = isset( $_POST['kind'] ) && is_string( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$type = isset( $_POST['type'] ) && is_string( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$token = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$op = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$speed = isset( $_POST['speed'] ) && is_scalar( $_POST['speed'] ) ? absint( wp_unslash( $_POST['speed'] ) ) : 3;
		$result = Batch::command( get_current_user_id(), $kind, $type, $token, $op, $speed );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( Batch::view( $result ) );
	}

	public static function download() {
		self::allowed();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The allowlisted type selects the specific nonce checked immediately below.
		$type = isset( $_POST['type'] ) && is_string( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		if ( ! Batch::allowed( 'export', $type ) ) {
			wp_die( esc_html__( 'Tipo o permiso no válido.', 'taxocommerce-import-export-pro' ) );
		}
		check_admin_referer( 'tciep_download_' . $type );
		$token = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$handle = Batch::locked( static function () use ( $type, $token ) {
			$state = Batch::state( get_current_user_id(), 'export', $type );
			$path = $state && hash_equals( $state['token'], $token ) ? Batch::download_path( $state ) : false;
			return $path ? fopen( $path, 'rb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Open only completed private job output under lock.
		} );
		if ( ! is_resource( $handle ) ) {
			wp_die( esc_html__( 'El CSV finalizado no está disponible.', 'taxocommerce-import-export-pro' ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'taxocommerce-pro-' . $type . '-' . gmdate( 'Ymd-His' ) . '.csv' ) . '"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authorized binary-safe CSV download, not HTML.
		fpassthru( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close download stream.
		exit;
	}
}
