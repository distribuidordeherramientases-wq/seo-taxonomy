<?php
/**
 * Sequential multi-file CSV queue for TaxoCommerce Import-Export.
 *
 * The queue coordinates files only. Business rules live in mini-importers
 * registered through taxocommerce_import_export_batch_importers.
 *
 * @package TaxoCommerceImportExport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TaxoCommerce_Import_Export_Batch {

	const OPTION_STATE = 'taxocommerce_ie_batch_state';
	const LOCK_KEY     = 'taxocommerce_ie_batch_lock';
	const WORKER_HOOK  = 'taxocommerce_ie_batch_worker';
	const AS_GROUP     = 'taxocommerce-import-export-batch';

	/** @var TaxoCommerce_Import_Export_CSV */
	private $csv;

	/**
	 * @param TaxoCommerce_Import_Export_CSV $csv CSV mini-importer.
	 */
	public function __construct( TaxoCommerce_Import_Export_CSV $csv ) {
		$this->csv = $csv;
	}

	/**
	 * Register hooks owned exclusively by this plugin.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'admin_post_taxocommerce_ie_batch_upload', array( $this, 'handle_upload' ) );
		add_action( 'admin_post_taxocommerce_ie_batch_control', array( $this, 'handle_control' ) );
		add_action( 'admin_post_taxocommerce_ie_batch_delete_pending', array( $this, 'handle_delete_pending' ) );
		add_action( self::WORKER_HOOK, array( $this, 'worker' ) );
		add_action( 'wp_ajax_taxocommerce_ie_batch_step', array( $this, 'ajax_step' ) );
		add_action( 'wp_ajax_taxocommerce_ie_batch_continue', array( $this, 'internal_continue' ) );
		add_action( 'wp_ajax_nopriv_taxocommerce_ie_batch_continue', array( $this, 'internal_continue' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_runner' ) );
	}

	const INDIVIDUAL_STATE = 'taxocommerce_ie_individual_last';

	/** Queue a single CSV without processing rows in the upload request. */
	public function queue_individual( $file ) {
		$this->guard();
		$this->prepare_directories();
		$state = $this->state();
		if ( ! empty( $state['enabled'] ) || ! empty( $state['current_file'] ) || $this->files( $this->paths()['pending'] ) ) {
			return new WP_Error( 'tc_ie_individual_busy', __( 'Hay una importación pendiente o en curso. Finaliza la cola antes de subir otro CSV.', 'taxocommerce-import-export' ) );
		}
		$name = isset( $file['name'] ) && is_scalar( $file['name'] ) ? sanitize_file_name( wp_unslash( (string) $file['name'] ) ) : '';
		$size = isset( $file['size'] ) && is_scalar( $file['size'] ) ? absint( $file['size'] ) : 0;
		$error = isset( $file['error'] ) && is_scalar( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		$tmp = isset( $file['tmp_name'] ) && is_scalar( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		if ( UPLOAD_ERR_OK !== $error || 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) || $size < 1 || $size > 50 * MB_IN_BYTES || ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'tc_ie_individual_upload', __( 'No se ha podido recibir un archivo CSV válido de hasta 50 MB.', 'taxocommerce-import-export' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$handled = wp_handle_upload( array(
			'name' => $name, 'type' => 'text/csv', 'tmp_name' => $tmp,
			'error' => UPLOAD_ERR_OK, 'size' => $size,
		), array( 'test_form' => false ) );
		if ( ! is_array( $handled ) || ! empty( $handled['error'] ) || empty( $handled['file'] ) ) {
			return new WP_Error( 'tc_ie_individual_move', __( 'WordPress no pudo almacenar el archivo recibido.', 'taxocommerce-import-export' ) );
		}
		$source = wp_normalize_path( (string) $handled['file'] );
		$target = $this->unique_path( $this->paths()['pending'], $name );
		if ( ! $this->move_file( $source, $target ) ) {
			return new WP_Error( 'tc_ie_individual_store', __( 'No se pudo añadir el archivo a la cola privada.', 'taxocommerce-import-export' ) );
		}
		$record = array(
			'file' => basename( $target ), 'status' => 'pending', 'rows' => 0,
			'errors' => 0, 'events' => array( current_time( 'mysql' ) . ' · Archivo recibido y guardado en la cola.' ),
			'updated' => current_time( 'mysql' ), 'message' => __( 'Pendiente de validación.', 'taxocommerce-import-export' ),
		);
		update_option( self::INDIVIDUAL_STATE, $record, false );
		$state['enabled'] = true;
		$state['user_id'] = get_current_user_id();
		$state['dispatch_token'] = wp_generate_password( 48, false, false );
		$state['pause_after_file'] = true;
		$state['stop_requested'] = false;
		$state['status'] = 'waiting_next';
		$state['message'] = __( 'Archivo individual recibido: se procesará por bloques.', 'taxocommerce-import-export' );
		$this->store_state( $state );
		// Start processing immediately instead of waiting for WP-Cron.
		$this->worker();
		return true;
	}

	/** Read-only status for the last individually uploaded CSV. */
	public function individual_status() {
		$this->guard();
		$record = get_option( self::INDIVIDUAL_STATE, array() );
		if ( ! is_array( $record ) || empty( $record['file'] ) ) return array();
		$state = $this->state();
		$file = (string) $record['file'];
		if ( $file === (string) ( $state['current_file'] ?? '' ) ) {
			$record['status'] = sanitize_key( (string) ( $state['status'] ?? 'processing' ) );
			$record['rows'] = absint( $state['processed_rows'] ?? 0 );
			$record['errors'] = absint( $state['error_rows'] ?? 0 );
			$record['message'] = sanitize_text_field( (string) ( $state['message'] ?? '' ) );
			$record['updated'] = (string) ( $state['updated_at'] ?? '' );
			$record['details'] = array_slice( (array) ( $state['errors'] ?? array() ), -10 );
		} elseif ( $file === (string) ( $state['last_file'] ?? '' ) ) {
			$record['status'] = 'failed' === ( $record['status'] ?? '' ) || 'failed' === ( $state['status'] ?? '' ) ? 'failed' : ( ! empty( $record['errors'] ) ? 'completed_with_errors' : 'completed' );
			$record['message'] = sanitize_text_field( (string) ( $state['message'] ?? '' ) );
			$record['updated'] = (string) ( $state['updated_at'] ?? '' );
		}
		return $record;
	}

	/**
	 * Whether the queue currently owns an import file.
	 *
	 * @return bool
	 */
	public static function is_running() {
		$state = get_option( self::OPTION_STATE, array() );
		if ( ! is_array( $state ) ) {
			return false;
		}
		return in_array( sanitize_key( (string) ( $state['status'] ?? '' ) ), array( 'processing', 'waiting_next', 'stopping' ), true );
	}

	/**
	 * Render the queue tab.
	 *
	 * @return void
	 */
	public function render() {
		$this->guard();
		$this->prepare_directories();

		$state = $this->state();
		$files = array();
		foreach ( array( 'pending', 'processing', 'imported', 'failed' ) as $bucket ) {
			$files[ $bucket ] = $this->files( $this->paths()[ $bucket ] );
		}
		?>
		<div class="tcc-family-grid" style="margin-top:20px;">
			<div class="card">
				<h2><?php esc_html_e( '1. Subir trabajos', 'taxocommerce-import-export' ); ?></h2>
				<p><?php esc_html_e( 'Selecciona uno o varios CSV. Se ordenan por nombre y se procesan de forma secuencial.', 'taxocommerce-import-export' ); ?></p>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="taxocommerce_ie_batch_upload">
					<?php wp_nonce_field( 'taxocommerce_ie_batch_upload', 'taxocommerce_ie_batch_nonce' ); ?>
					<p><input type="file" name="batch_files[]" accept=".csv,text/csv" multiple required></p>
					<p>
						<button class="button" name="queue_mode" value="pending"><?php esc_html_e( 'Guardar sin iniciar (pausado)', 'taxocommerce-import-export' ); ?></button>
						<button class="button button-primary" name="queue_mode" value="start"><?php esc_html_e( 'Añadir e iniciar', 'taxocommerce-import-export' ); ?></button>
					</p>
					<p class="description"><?php esc_html_e( 'Máximo 20 archivos por subida. El nombre controla el orden natural: 001_..., 002_..., etc.', 'taxocommerce-import-export' ); ?></p>
				</form>
			</div>

			<div class="card">
				<h2><?php esc_html_e( '2. Estado y controles', 'taxocommerce-import-export' ); ?></h2>
				<table class="widefat striped">
					<tbody>
						<tr><th><?php esc_html_e( 'Estado', 'taxocommerce-import-export' ); ?></th><td><?php echo esc_html( (string) ( $state['status'] ?? 'idle' ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Archivo actual', 'taxocommerce-import-export' ); ?></th><td><?php echo esc_html( (string) ( $state['current_file'] ?? '—' ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Tipo', 'taxocommerce-import-export' ); ?></th><td><?php echo esc_html( (string) ( $state['entity'] ?? '—' ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Velocidad', 'taxocommerce-import-export' ); ?></th><td><?php echo esc_html( TaxoCommerce_IE_Load::priority( 'import' ) . ' · ' . TaxoCommerce_IE_Load::labels()[ TaxoCommerce_IE_Load::priority( 'import' ) ] ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Último lote', 'taxocommerce-import-export' ); ?></th><td><?php $tc_ie_last = TaxoCommerce_IE_Load::last_sample( 'import' ); echo esc_html( absint( $tc_ie_last['items'] ?? 0 ) . ' filas · ' . number_format_i18n( (float) ( $tc_ie_last['elapsed'] ?? 0 ), 2 ) . ' s · siguiente lote ' . TaxoCommerce_IE_Load::batch_size( 'import' ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Filas procesadas', 'taxocommerce-import-export' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $state['processed_rows'] ?? 0 ) ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Errores', 'taxocommerce-import-export' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $state['error_rows'] ?? 0 ) ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Mensaje', 'taxocommerce-import-export' ); ?></th><td><?php echo esc_html( (string) ( $state['message'] ?? '' ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Actualización', 'taxocommerce-import-export' ); ?></th><td><?php echo esc_html( (string) ( $state['updated_at'] ?? '—' ) ); ?></td></tr>
					</tbody>
				</table>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
					<input type="hidden" name="action" value="taxocommerce_ie_batch_control">
					<?php wp_nonce_field( 'taxocommerce_ie_batch_control', 'taxocommerce_ie_batch_nonce' ); ?>
					<label for="tc-ie-import-priority"><strong><?php esc_html_e( 'Velocidad de importación', 'taxocommerce-import-export' ); ?></strong></label>
					<select id="tc-ie-import-priority" name="priority">
						<?php foreach ( TaxoCommerce_IE_Load::labels() as $number => $label ) : ?>
							<option value="<?php echo esc_attr( (string) $number ); ?>" <?php selected( $number, TaxoCommerce_IE_Load::priority( 'import' ) ); ?>><?php echo esc_html( $number . ' · ' . $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button class="button button-secondary" name="queue_action" value="priority"><?php esc_html_e( 'Guardar velocidad', 'taxocommerce-import-export' ); ?></button>
					<p class="description"><?php esc_html_e( 'El cambio se aplica al siguiente bloque sin perder el progreso. Nivel 3 recomendado.', 'taxocommerce-import-export' ); ?></p>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
					<input type="hidden" name="action" value="taxocommerce_ie_batch_control">
					<?php wp_nonce_field( 'taxocommerce_ie_batch_control', 'taxocommerce_ie_batch_nonce' ); ?>
					<button class="button button-primary" name="queue_action" value="start"><?php esc_html_e( 'Iniciar / continuar', 'taxocommerce-import-export' ); ?></button>
					<button class="button" name="queue_action" value="pause"><?php esc_html_e( 'Pausar después del archivo actual', 'taxocommerce-import-export' ); ?></button>
					<button class="button" name="queue_action" value="stop"><?php esc_html_e( 'Detener importación actual', 'taxocommerce-import-export' ); ?></button>
					<button class="button" name="queue_action" value="tick"><?php esc_html_e( 'Procesar siguiente bloque', 'taxocommerce-import-export' ); ?></button>
				</form>
				<p class="description"><?php esc_html_e( 'Cada bloque lanza inmediatamente el siguiente; Action Scheduler y WP-Cron son solo respaldo. La velocidad regula el tamaño de lote en función de tiempo y memoria, sin esperas entre bloques. Detener conserva el offset.', 'taxocommerce-import-export' ); ?></p>
				<p id="tc-ie-batch-live" role="status" aria-live="polite" class="notice notice-info inline" style="padding:9px;margin-top:10px;">Consultando ejecución continua…</p>
			</div>
		</div>

		<div class="card" style="max-width:none;margin-top:20px;">
			<h2><?php esc_html_e( '3. Gestor de archivos', 'taxocommerce-import-export' ); ?></h2>
			<?php
			foreach (
				array(
					'pending'    => __( 'Pendientes', 'taxocommerce-import-export' ),
					'processing' => __( 'En proceso', 'taxocommerce-import-export' ),
					'imported'   => __( 'Importados', 'taxocommerce-import-export' ),
					'failed'     => __( 'Fallidos', 'taxocommerce-import-export' ),
				) as $bucket => $label
			) :
				?>
				<details <?php echo esc_attr( in_array( $bucket, array( 'pending', 'processing' ), true ) ? 'open' : '' ); ?>>
					<summary><strong><?php echo esc_html( $label ); ?> (<?php echo esc_html( number_format_i18n( count( $files[ $bucket ] ) ) ); ?>)</strong></summary>
					<?php if ( empty( $files[ $bucket ] ) ) : ?>
						<p><?php esc_html_e( 'No hay archivos.', 'taxocommerce-import-export' ); ?></p>
					<?php else : ?>
						<ul>
							<?php foreach ( array_slice( $files[ $bucket ], 0, 100 ) as $path ) : ?>
								<li style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:5px 0;">
									<code><?php echo esc_html( basename( $path ) ); ?></code>
									<?php if ( 'pending' === $bucket ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('¿Eliminar este CSV pendiente? No se puede deshacer.');" style="display:inline;margin:0;">
											<input type="hidden" name="action" value="taxocommerce_ie_batch_delete_pending">
											<input type="hidden" name="pending_file" value="<?php echo esc_attr( basename( $path ) ); ?>">
											<?php wp_nonce_field( 'taxocommerce_ie_batch_delete_pending_' . basename( $path ), 'taxocommerce_ie_batch_nonce' ); ?>
											<button type="submit" class="button button-small button-link-delete" aria-label="<?php echo esc_attr( sprintf( __( 'Eliminar CSV pendiente %s', 'taxocommerce-import-export' ), basename( $path ) ) ); ?>"><?php esc_html_e( 'Eliminar', 'taxocommerce-import-export' ); ?></button>
										</form>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</details>
			<?php endforeach; ?>
		</div>

		<div class="card" style="max-width:none;margin-top:20px;">
			<h2><?php esc_html_e( 'Miniimportadores registrados', 'taxocommerce-import-export' ); ?></h2>
			<p><?php esc_html_e( 'La cola detecta el tipo y delega cada fila en su miniimportador. Otros plugins pueden registrar sus propios tipos sin añadir su lógica a Import-Export.', 'taxocommerce-import-export' ); ?></p>
			<ul>
				<?php foreach ( $this->importers() as $type => $importer ) : ?>
					<li><code><?php echo esc_html( $type ); ?></code> — <?php echo esc_html( (string) ( $importer['label'] ?? $type ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Handle multi-file upload.
	 *
	 * @return void
	 */
	public function handle_upload() {
		$this->guard();
		check_admin_referer( 'taxocommerce_ie_batch_upload', 'taxocommerce_ie_batch_nonce' );
		$this->prepare_directories();

		$mode  = isset( $_POST['queue_mode'] ) ? sanitize_key( wp_unslash( $_POST['queue_mode'] ) ) : 'pending';
		$files = isset( $_FILES['batch_files'] ) && is_array( $_FILES['batch_files'] ) ? $_FILES['batch_files'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$names = isset( $files['name'] ) ? (array) $files['name'] : array();
		$tmp   = isset( $files['tmp_name'] ) ? (array) $files['tmp_name'] : array();
		$types = isset( $files['type'] ) ? (array) $files['type'] : array();
		$errs  = isset( $files['error'] ) ? (array) $files['error'] : array();
		$sizes = isset( $files['size'] ) ? (array) $files['size'] : array();

		if ( empty( $names ) || count( $names ) > 20 ) {
			$this->redirect( 'error', __( 'Selecciona entre 1 y 20 archivos CSV.', 'taxocommerce-import-export' ) );
		}

		$pending = $this->paths()['pending'];
		if ( '' === $pending ) {
			$this->redirect( 'error', __( 'WordPress no ha podido resolver el directorio uploads para crear la cola.', 'taxocommerce-import-export' ) );
		}
		$added   = 0;
		foreach ( $names as $index => $raw_name ) {
			$name = sanitize_file_name( wp_unslash( (string) $raw_name ) );
			if ( UPLOAD_ERR_OK !== (int) ( $errs[ $index ] ?? UPLOAD_ERR_NO_FILE ) || 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
				continue;
			}

			$tmp_name = (string) ( $tmp[ $index ] ?? '' );
			$size     = absint( $sizes[ $index ] ?? 0 );
			if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) || $size < 1 || $size > 50 * MB_IN_BYTES ) {
				continue;
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			$single = array(
				'name'     => $name,
				'type'     => sanitize_mime_type( (string) ( $types[ $index ] ?? 'text/csv' ) ),
				'tmp_name' => $tmp_name,
				'error'    => UPLOAD_ERR_OK,
				'size'     => $size,
			);
			$handled = wp_handle_upload( $single, array( 'test_form' => false ) );
			if ( ! is_array( $handled ) || ! empty( $handled['error'] ) || empty( $handled['file'] ) ) {
				continue;
			}

			$source = wp_normalize_path( (string) $handled['file'] );
			$target = $this->unique_path( $pending, $name );
			if ( $this->move_file( $source, $target ) ) {
				$added++;
			} elseif ( is_file( $source ) ) {
				wp_delete_file( $source );
			}
		}

		if ( $added < 1 ) {
			$this->redirect( 'error', __( 'No se añadió ningún CSV válido a la cola.', 'taxocommerce-import-export' ) );
		}

		if ( 'start' === $mode ) {
			$state                     = $this->state();
			$state['enabled']          = true;
			$state['user_id']          = get_current_user_id();
			$state['dispatch_token']   = wp_generate_password( 48, false, false );
			$state['pause_after_file'] = false;
			$state['stop_requested']   = false;
			$state['status']           = empty( $state['current_file'] ) ? 'waiting_next' : ( $state['status'] ?? 'processing' );
			$state['message']          = sprintf(
				/* translators: %d: number of CSV files added to the batch queue. */
				_n( '%d archivo añadido. Cola iniciada.', '%d archivos añadidos. Cola iniciada.', $added, 'taxocommerce-import-export' ),
				$added
			);
			$this->store_state( $state );
			// Process the first bounded block immediately so starting a queue does
			// not depend on an Action Scheduler backlog or the next WP-Cron tick.
			$this->worker();
		}

		$this->redirect(
			'ok',
			sprintf(
				/* translators: %d: number of CSV files added to the batch queue. */
				_n( '%d archivo añadido a la cola.', '%d archivos añadidos a la cola.', $added, 'taxocommerce-import-export' ),
				$added
			)
		);
	}


	/**
	 * Delete exactly one queued CSV before any importer starts processing it.
	 * Rejects traversal, symlinks, other queue buckets and concurrent workers.
	 *
	 * @param string $filename Exact basename in the pending directory.
	 * @return true|WP_Error
	 */
	public function delete_pending_file( $filename ) {
		$this->guard();
		if ( ! is_string( $filename )
			|| '' === $filename
			|| strlen( $filename ) > 255
			|| false !== strpos( $filename, '/' )
			|| false !== strpos( $filename, '\\' )
			|| false !== strpos( $filename, "\0" )
			|| 'csv' !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'tc_ie_delete_invalid', __( 'El nombre de archivo pendiente no es válido.', 'taxocommerce-import-export' ) );
		}

		$lock = $this->acquire_lock();
		if ( '' === $lock ) {
			return new WP_Error( 'tc_ie_delete_busy', __( 'Se está procesando otro bloque. Vuelve a intentar borrar este archivo cuando finalice.', 'taxocommerce-import-export' ) );
		}

		try {
			$pending = $this->paths()['pending'];
			if ( ! $pending ) {
				return new WP_Error( 'tc_ie_delete_storage', __( 'El directorio de archivos pendientes no está disponible.', 'taxocommerce-import-export' ) );
			}

			$target = trailingslashit( $pending ) . $filename;
			$actual_parent = realpath( dirname( $target ) );
			$pending_parent = realpath( $pending );
			if ( ! $actual_parent || ! $pending_parent || $actual_parent !== $pending_parent
				|| is_link( $target )
				|| ! in_array( $target, $this->files( $pending ), true )
				|| ! is_file( $target ) ) {
				return new WP_Error( 'tc_ie_delete_not_pending', __( 'El archivo ya no está pendiente o ha comenzado a procesarse; no se ha eliminado.', 'taxocommerce-import-export' ) );
			}

			wp_delete_file( $target );
			if ( file_exists( $target ) ) {
				return new WP_Error( 'tc_ie_delete_failed', __( 'No se ha podido borrar el CSV pendiente. Comprueba los permisos del alojamiento.', 'taxocommerce-import-export' ) );
			}

			$individual = get_option( self::INDIVIDUAL_STATE, array() );
			if ( is_array( $individual ) && $filename === (string) ( $individual['file'] ?? '' )
				&& 'pending' === (string) ( $individual['status'] ?? '' ) ) {
				$individual['status'] = 'cancelled';
				$individual['message'] = __( 'CSV pendiente eliminado antes de la importación.', 'taxocommerce-import-export' );
				$individual['updated'] = current_time( 'mysql' );
				$individual['events'][] = $individual['updated'] . ' · ' . $individual['message'];
				update_option( self::INDIVIDUAL_STATE, $individual, false );
			}

			$state = $this->state();
			if ( ! empty( $state['enabled'] ) && empty( $state['current_file'] )
				&& ! $this->files( $pending ) ) {
				$state['enabled'] = false;
				$state['status'] = 'idle';
				$state['message'] = __( 'Se ha eliminado el último CSV pendiente; la cola está vacía.', 'taxocommerce-import-export' );
				$this->store_state( $state );
			}
			return true;
		} finally {
			$this->release_lock( $lock );
		}
	}

	/** Handle deletion of one pending CSV after permission and CSRF checks. */
	public function handle_delete_pending() {
		$this->guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce action includes the exact file name and is verified below.
		$raw = isset( $_POST['pending_file'] ) && is_string( $_POST['pending_file'] )
			? wp_unslash( $_POST['pending_file'] ) : '';
		if ( '' === $raw || strlen( $raw ) > 255 ) {
			$this->redirect( 'error', __( 'No se ha indicado un CSV pendiente válido.', 'taxocommerce-import-export' ) );
		}
		check_admin_referer( 'taxocommerce_ie_batch_delete_pending_' . $raw, 'taxocommerce_ie_batch_nonce' );
		$result = $this->delete_pending_file( $raw );
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', $result->get_error_message() );
		}
		$this->redirect( 'ok', sprintf(
			/* translators: %s: deleted CSV file basename. */
			__( 'CSV pendiente eliminado: %s.', 'taxocommerce-import-export' ),
			$raw
		) );
	}

	/**
	 * Handle queue controls.
	 *
	 * @return void
	 */
	public function handle_control() {
		$this->guard();
		check_admin_referer( 'taxocommerce_ie_batch_control', 'taxocommerce_ie_batch_nonce' );

		$action = isset( $_POST['queue_action'] ) ? sanitize_key( wp_unslash( $_POST['queue_action'] ) ) : '';
		$state  = $this->state();

		if ( 'priority' === $action ) {
			$raw = isset( $_POST['priority'] ) ? wp_unslash( $_POST['priority'] ) : '';
			$updated = TaxoCommerce_IE_Load::set_priority( 'import', $raw );
			$this->redirect( is_wp_error( $updated ) ? 'error' : 'ok', is_wp_error( $updated ) ? $updated->get_error_message() : __( 'Velocidad de importación actualizada.', 'taxocommerce-import-export' ) );
		}
		if ( 'start' === $action ) {
			$state['enabled']          = true;
			$state['user_id']          = get_current_user_id();
			$state['dispatch_token']   = wp_generate_password( 48, false, false );
			$state['pause_after_file'] = false;
			$state['stop_requested']   = false;
			$state['status']           = empty( $state['current_file'] ) ? 'waiting_next' : 'processing';
			$state['message']          = __( 'Cola iniciada o reanudada.', 'taxocommerce-import-export' );
			$this->store_state( $state );
			$this->worker();
		} elseif ( 'pause' === $action ) {
			$state['pause_after_file'] = true;
			$state['message']          = __( 'La cola se pausará al terminar el archivo actual.', 'taxocommerce-import-export' );
			if ( empty( $state['current_file'] ) ) {
				$state['enabled'] = false;
				$state['status']  = 'paused';
			}
			$this->store_state( $state );
		} elseif ( 'stop' === $action ) {
			$state['stop_requested'] = true;
			$state['status']         = empty( $state['current_file'] ) ? 'paused' : 'stopping';
			$state['enabled']        = false;
			$state['message']        = __( 'Se detendrá antes de procesar la siguiente fila. El offset actual se conserva.', 'taxocommerce-import-export' );
			$this->store_state( $state );
		} elseif ( 'tick' === $action ) {
			$state['enabled']        = true;
			$state['user_id']        = get_current_user_id();
			$state['dispatch_token'] = wp_generate_password( 48, false, false );
			$state['stop_requested'] = false;
			$this->store_state( $state );
			$this->worker();
		}

		$this->redirect( 'ok', __( 'Control de cola aplicado.', 'taxocommerce-import-export' ) );
	}

	/**
	 * Queue worker. One bounded block per invocation.
	 *
	 * @return void
	 */
	public function worker() {
		$this->prepare_directories();
		$paths = $this->paths();
		if ( empty( $paths['base'] ) ) {
			$this->store_state(
				array(
					'enabled' => false,
					'status'  => 'failed',
					'message' => __( 'WordPress no ha podido resolver el directorio uploads para ejecutar la cola.', 'taxocommerce-import-export' ),
				)
			);
			return;
		}

		$token = $this->acquire_lock();
		if ( '' === $token ) {
			return;
		}

		$previous_user = get_current_user_id();
		$continue = false;

		try {
			$state = $this->state();
			$worker_user = absint( $state['user_id'] ?? 0 );
			if ( $worker_user > 0 && $worker_user !== $previous_user ) {
				wp_set_current_user( $worker_user );
			}

			$capability = (string) apply_filters( 'taxocommerce_import_export_capability', 'manage_woocommerce' );
			if ( $worker_user < 1 || ( ! current_user_can( $capability ) && ! current_user_can( 'manage_options' ) ) ) {
				$this->store_state(
					array(
						'enabled' => false,
						'status'  => 'failed',
						'message' => __( 'El usuario que inició la cola ya no tiene permisos para importar.', 'taxocommerce-import-export' ),
					)
				);
				return;
			}

			if ( ! empty( $state['stop_requested'] ) ) {
				$state['status']  = 'paused';
				$state['enabled'] = false;
				$state['message'] = __( 'Importación detenida con el offset conservado.', 'taxocommerce-import-export' );
				$this->store_state( $state );
				return;
			}
			if ( empty( $state['enabled'] ) ) {
				return;
			}

			if ( empty( $state['current_file'] ) ) {
				$state = $this->start_next_file( $state );
				if ( is_wp_error( $state ) ) {
					$this->store_state(
						array(
							'enabled' => false,
							'status'  => 'failed',
							'message' => $state->get_error_message(),
						)
					);
					return;
				}
				if ( empty( $state['current_file'] ) ) {
					$remaining = $this->files( $this->paths()['pending'] );
					if ( ! empty( $remaining ) && ! empty( $state['enabled'] ) ) {
						$state['status'] = 'waiting_next';
						$this->store_state( $state );
						$continue = true;
						return;
					}

					$state['enabled'] = false;
					$state['status']  = 'idle';
					$state['message'] = __( 'Cola terminada. No quedan archivos pendientes.', 'taxocommerce-import-export' );
					$this->store_state( $state );
					return;
				}
			}

			$result = $this->process_block( $state );
			if ( is_wp_error( $result ) ) {
				$this->fail_current_file( $state, $result->get_error_message() );
				return;
			}

			$state = $result;
			// Admin stop/pause can arrive while a block is still working. Never
			// overwrite a newer stop or disable command with the older snapshot.
			$latest = $this->state();
			if ( ! empty( $latest['stop_requested'] ) || empty( $latest['enabled'] ) ) {
				$state['enabled'] = false;
				$state['stop_requested'] = ! empty( $latest['stop_requested'] );
				$state['status'] = 'paused';
				$state['message'] = __( 'Importación detenida o pausada. Se conserva el offset del último bloque.', 'taxocommerce-import-export' );
			} elseif ( ! empty( $latest['pause_after_file'] ) ) {
				$state['pause_after_file'] = true;
			}
			$this->store_state( $state );

			$continue = ! empty( $state['enabled'] )
				&& in_array( (string) ( $state['status'] ?? '' ), array( 'processing', 'waiting_next' ), true );
		} finally {
			if ( get_current_user_id() !== $previous_user ) {
				wp_set_current_user( $previous_user );
			}
			$this->release_lock( $token );
			// Next request starts only after the previous lock is released.
			if ( $continue ) {
				$this->dispatch_next();
			}
		}
	}

	/**
	 * Move next pending file into processing and detect its mini-importer.
	 *
	 * @param array $state Current state.
	 * @return array|WP_Error
	 */
	private function start_next_file( $state ) {
		$paths   = $this->paths();
		$pending = $this->files( $paths['pending'] );
		if ( empty( $pending ) ) {
			$state['current_file'] = '';
			return $state;
		}

		$source   = $pending[0];
		$filename = basename( $source );
		$target   = $this->unique_path( $paths['processing'], $filename );
		if ( ! $this->move_file( $source, $target ) ) {
			return new WP_Error( 'tc_ie_batch_move', __( 'No se pudo mover el siguiente CSV a processing.', 'taxocommerce-import-export' ) );
		}

		$detected = $this->detect_importer( $target );
		if ( is_wp_error( $detected ) ) {
			$failed = $this->unique_path( $paths['failed'], $filename );
			$this->move_file( $target, $failed );
			file_put_contents(
				$failed . '.log.json',
				wp_json_encode(
					array(
						'file'        => basename( $failed ),
						'type'        => '',
						'error_rows'  => 0,
						'file_error'  => sanitize_text_field( $detected->get_error_message() ),
						'finished_at' => current_time( 'mysql' ),
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				)
			); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$individual = get_option( self::INDIVIDUAL_STATE, array() );
			if ( is_array( $individual ) && (string) ( $individual['file'] ?? '' ) === $filename ) {
				$individual['status'] = 'failed';
				$individual['errors'] = 1;
				$individual['message'] = sanitize_text_field( $detected->get_error_message() );
				$individual['updated'] = current_time( 'mysql' );
				$individual['events'][] = $individual['updated'] . ' · Archivo rechazado: ' . $individual['message'];
				update_option( self::INDIVIDUAL_STATE, $individual, false );
			}
			$state['current_file'] = '';
			$state['last_file']    = basename( $failed );
			$state['entity']       = '';
			$state['status']       = 'waiting_next';
			$state['message']      = sprintf(
				/* translators: 1: CSV file name, 2: detection error. */
				__( 'El archivo %1$s no pudo asignarse a un miniimportador (%2$s). Se movió a Fallidos y la cola continúa.', 'taxocommerce-import-export' ),
				basename( $failed ),
				$detected->get_error_message()
			);
			return $state;
		}

		$state['current_file']  = basename( $target );
		$state['entity']        = $detected['type'];
		$state['header']        = $detected['header'];
		$state['delimiter']     = $detected['delimiter'];
		$state['offset']        = $detected['offset'];
		$state['processed_rows']= 0;
		$state['error_rows']    = 0;
		$state['errors']        = array();
		$state['status']        = 'processing';
		$state['message']       = sprintf(
			/* translators: 1: CSV file name, 2: mini-importer type. */
			__( 'Procesando %1$s con miniimportador %2$s.', 'taxocommerce-import-export' ),
			basename( $target ),
			$detected['type']
		);
		$state['updated_at']    = current_time( 'mysql' );
		return $state;
	}

	/**
	 * Process a bounded number of rows from the current CSV.
	 *
	 * @param array $state Queue state.
	 * @return array|WP_Error
	 */
	private function process_block( $state ) {
		$paths = $this->paths();
		$path  = trailingslashit( $paths['processing'] ) . sanitize_file_name( (string) ( $state['current_file'] ?? '' ) );
		if ( ! is_file( $path ) ) {
			return new WP_Error( 'tc_ie_batch_missing', __( 'El archivo actual ya no existe en processing.', 'taxocommerce-import-export' ) );
		}

		$importers = $this->importers();
		$type      = sanitize_key( (string) ( $state['entity'] ?? '' ) );
		if ( empty( $importers[ $type ]['import_row'] ) || ! is_callable( $importers[ $type ]['import_row'] ) ) {
			return new WP_Error( 'tc_ie_batch_importer_missing', __( 'El miniimportador registrado ya no está disponible.', 'taxocommerce-import-export' ) );
		}

		$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Incremental CSV streaming.
		if ( false === $handle ) {
			return new WP_Error( 'tc_ie_batch_open', __( 'No se pudo abrir el CSV actual.', 'taxocommerce-import-export' ) );
		}

		$delimiter = isset( $state['delimiter'] ) && in_array( $state['delimiter'], array( ';', ',', "\t" ), true )
			? $state['delimiter']
			: ',';
		$raw_header = fgetcsv( $handle, 0, $delimiter );
		if ( ! is_array( $raw_header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'tc_ie_batch_header', __( 'El CSV no contiene una cabecera válida.', 'taxocommerce-import-export' ) );
		}
		$header = $this->csv->normalize_headers_for_batch( $raw_header );

		$offset = absint( $state['offset'] ?? 0 );
		if ( $offset > 0 ) {
			fseek( $handle, $offset );
		}

		$limit    = min( TaxoCommerce_IE_Load::batch_size( 'import' ), max( 1, min( 250, absint( apply_filters( 'taxocommerce_import_export_batch_rows_per_tick', 200 ) ) ) ) );
		$measure  = TaxoCommerce_IE_Load::begin();
		$deadline = microtime( true ) + max( 2, min( 20, (float) apply_filters( 'taxocommerce_import_export_batch_runtime_seconds', 8 ) ) );
		$done     = 0;
		$eof      = false;

		while ( $done < $limit && microtime( true ) < $deadline ) {
			if ( ! empty( $this->state()['stop_requested'] ) ) {
				break;
			}

			$row = fgetcsv( $handle, 0, $delimiter );
			if ( false === $row ) {
				$eof = true;
				break;
			}
			if ( empty( array_filter( $row, 'strlen' ) ) ) {
				$state['offset'] = ftell( $handle );
				continue;
			}

			$row  = array_pad( $row, count( $header ), '' );
			$data = array_combine( $header, array_slice( $row, 0, count( $header ) ) );
			if ( ! is_array( $data ) ) {
				$state['error_rows'] = absint( $state['error_rows'] ?? 0 ) + 1;
				$state['offset']     = ftell( $handle );
				continue;
			}

			$result = call_user_func( $importers[ $type ]['import_row'], $data, array( 'file' => basename( $path ), 'type' => $type ) );
			$state['processed_rows'] = absint( $state['processed_rows'] ?? 0 ) + 1;
			if ( is_wp_error( $result ) ) {
				$state['error_rows'] = absint( $state['error_rows'] ?? 0 ) + 1;
				$errors = (array) ( $state['errors'] ?? array() );
				if ( count( $errors ) < 50 ) {
					$errors[] = array(
						'row'     => $state['processed_rows'],
						'message' => sanitize_text_field( $result->get_error_message() ),
					);
				}
				$state['errors'] = $errors;
			}

			$state['offset'] = ftell( $handle );
			$done++;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		TaxoCommerce_IE_Load::finish( 'import', $measure, $done );
		$state['updated_at'] = current_time( 'mysql' );

		if ( ! $eof ) {
			$state['status']  = ! empty( $this->state()['stop_requested'] ) ? 'stopping' : 'processing';
			$state['message'] = sprintf(
				/* translators: 1: processed row count, 2: error row count. */
				__( 'Bloque procesado. %1$d filas acumuladas; %2$d errores.', 'taxocommerce-import-export' ),
				absint( $state['processed_rows'] ?? 0 ),
				absint( $state['error_rows'] ?? 0 )
			);
			return $state;
		}

		return $this->finish_current_file( $state, $path );
	}

	/**
	 * Finalize current file and continue/pause.
	 *
	 * @param array  $state State.
	 * @param string $path Current processing path.
	 * @return array
	 */
	private function finish_current_file( $state, $path ) {
		$paths      = $this->paths();
		$has_errors = 0 < absint( $state['error_rows'] ?? 0 );
		$bucket     = $has_errors ? 'failed' : 'imported';
		$target     = $this->unique_path( $paths[ $bucket ], basename( $path ) );

		if ( ! $this->move_file( $path, $target ) ) {
			$state['enabled'] = false;
			$state['status']  = 'failed';
			$state['message'] = __( 'El CSV terminó, pero no se pudo mover fuera de processing.', 'taxocommerce-import-export' );
			return $state;
		}

		$log = array(
			'file'           => basename( $target ),
			'type'           => sanitize_key( (string) ( $state['entity'] ?? '' ) ),
			'processed_rows' => absint( $state['processed_rows'] ?? 0 ),
			'error_rows'     => absint( $state['error_rows'] ?? 0 ),
			'errors'         => array_values( (array) ( $state['errors'] ?? array() ) ),
			'finished_at'    => current_time( 'mysql' ),
		);
		file_put_contents( $target . '.log.json', wp_json_encode( $log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$individual = get_option( self::INDIVIDUAL_STATE, array() );
		if ( is_array( $individual ) && (string) ( $individual['file'] ?? '' ) === basename( $path ) ) {
			$individual['rows'] = absint( $state['processed_rows'] ?? 0 );
			$individual['errors'] = absint( $state['error_rows'] ?? 0 );
			$individual['details'] = array_slice( (array) ( $state['errors'] ?? array() ), -10 );
			$individual['status'] = $has_errors ? 'completed_with_errors' : 'completed';
			$individual['message'] = $has_errors ? __( 'Finalizado con errores. Revisa las filas indicadas.', 'taxocommerce-import-export' ) : __( 'Importación finalizada correctamente.', 'taxocommerce-import-export' );
			$individual['updated'] = current_time( 'mysql' );
			$individual['events'][] = $individual['updated'] . ' · ' . $individual['message'];
			update_option( self::INDIVIDUAL_STATE, $individual, false );
		}
		$pause = ! empty( $state['pause_after_file'] ) || ! empty( $this->state()['pause_after_file'] );
		$state['last_file']      = basename( $target );
		$state['current_file']   = '';
		$state['entity']         = '';
		$state['header']         = array();
		$state['delimiter']      = '';
		$state['offset']         = 0;
		$state['processed_rows'] = 0;
		$state['error_rows']     = 0;
		$state['errors']         = array();
		$state['stop_requested'] = false;

		if ( $pause ) {
			$state['enabled']          = false;
			$state['pause_after_file'] = false;
			$state['status']           = 'paused';
			$state['message']          = __( 'Archivo terminado. Cola pausada antes del siguiente archivo.', 'taxocommerce-import-export' );
		} else {
			$state['status']  = 'waiting_next';
			$state['message'] = $has_errors
				? __( 'Archivo finalizado con errores y movido a Fallidos. Se continúa con el siguiente.', 'taxocommerce-import-export' )
				: __( 'Archivo importado. Se programa el siguiente.', 'taxocommerce-import-export' );
		}
		return $state;
	}

	/**
	 * Fail current file at file-level.
	 *
	 * @param array  $state State.
	 * @param string $message Error.
	 * @return void
	 */
	private function fail_current_file( $state, $message ) {
		$paths = $this->paths();
		$name  = sanitize_file_name( (string) ( $state['current_file'] ?? '' ) );
		if ( $name ) {
			$source = trailingslashit( $paths['processing'] ) . $name;
			if ( is_file( $source ) ) {
				$this->move_file( $source, $this->unique_path( $paths['failed'], $name ) );
			}
		}
		$individual = get_option( self::INDIVIDUAL_STATE, array() );
		if ( is_array( $individual ) && $name === (string) ( $individual['file'] ?? '' ) ) {
			$individual['status'] = 'failed';
			$individual['message'] = sanitize_text_field( $message );
			$individual['updated'] = current_time( 'mysql' );
			$individual['events'][] = $individual['updated'] . ' · Error: ' . $individual['message'];
			update_option( self::INDIVIDUAL_STATE, $individual, false );
		}
		$this->store_state(
			array(
				'enabled'      => false,
				'status'       => 'failed',
				'current_file' => '',
				'last_file'    => $name,
				'entity'       => sanitize_key( (string) ( $state['entity'] ?? '' ) ),
				'message'      => sanitize_text_field( $message ),
			)
		);
	}

	/**
	 * Registry of row mini-importers.
	 *
	 * @return array
	 */
	private function importers() {
		$callback = array( $this->csv, 'import_row_for_batch' );
		$items    = array(
			'product'          => array( 'label' => __( 'Productos WooCommerce', 'taxocommerce-import-export' ), 'import_row' => $callback ),
			'product_category' => array( 'label' => __( 'Categorías WooCommerce', 'taxocommerce-import-export' ), 'import_row' => $callback ),
			'post'             => array( 'label' => __( 'Entradas', 'taxocommerce-import-export' ), 'import_row' => $callback ),
			'page'             => array( 'label' => __( 'Páginas', 'taxocommerce-import-export' ), 'import_row' => $callback ),
		);

		/**
		 * Register additional queue mini-importers.
		 *
		 * Each importer: type => array( 'label' => string, 'import_row' => callable ).
		 * The callable receives ($row, $context) and returns true|WP_Error.
		 */
		return (array) apply_filters( 'taxocommerce_import_export_batch_importers', $items );
	}

	/**
	 * Detect importer from record_type in the first data row.
	 *
	 * @param string $path CSV path.
	 * @return array|WP_Error
	 */
	private function detect_importer( $path ) {
		$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return new WP_Error( 'tc_ie_batch_detect_open', __( 'No se pudo abrir el CSV para detectar su tipo.', 'taxocommerce-import-export' ) );
		}
		$delimiter  = $this->detect_delimiter( $handle );
		$raw_header = fgetcsv( $handle, 0, $delimiter );
		$data_offset = ftell( $handle );
		$first      = fgetcsv( $handle, 0, $delimiter );
		if ( ! is_array( $raw_header ) || ! is_array( $first ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'tc_ie_batch_detect_empty', __( 'El CSV no contiene cabecera y al menos una fila de datos.', 'taxocommerce-import-export' ) );
		}

		$header = $this->csv->normalize_headers_for_batch( $raw_header );
		$first  = array_pad( $first, count( $header ), '' );
		$data   = array_combine( $header, array_slice( $first, 0, count( $header ) ) );
		$type   = is_array( $data ) ? sanitize_key( (string) ( $data['record_type'] ?? '' ) ) : '';
		if ( ! $type && method_exists( $this->csv, 'infer_record_type_from_headers' ) ) {
			$type = sanitize_key( (string) $this->csv->infer_record_type_from_headers( $header ) );
		}
		$offset = $data_offset;
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( ! isset( $this->importers()[ $type ] ) ) {
			/**
			 * Allow a module to detect its own legacy/custom CSV schema.
			 *
			 * Returning a registered type is sufficient; the queue still owns
			 * scheduling and the target plugin owns the row import callback.
			 */
			$type = sanitize_key(
				(string) apply_filters(
					'taxocommerce_import_export_batch_detect_type',
					$type,
					$header,
					$data,
					basename( $path )
				)
			);
		}

		if ( ! isset( $this->importers()[ $type ] ) ) {
			return new WP_Error(
				'tc_ie_batch_unknown_type',
				__( 'No se reconoce el tipo del CSV. Debe incluir record_type o un módulo debe registrar su detector y miniimportador.', 'taxocommerce-import-export' )
			);
		}

		return array(
			'type'      => $type,
			'header'    => $header,
			'delimiter' => $delimiter,
			'offset'    => $offset,
		);
	}

	/**
	 * Detect the most likely CSV delimiter without consuming the stream.
	 *
	 * @param resource $handle CSV stream.
	 * @return string
	 */
	private function detect_delimiter( $handle ) {
		$position = ftell( $handle );
		$line     = fgets( $handle );
		fseek( $handle, $position );

		if ( false === $line ) {
			return ',';
		}

		$scores = array();
		foreach ( array( ';', ',', "\t" ) as $candidate ) {
			$scores[ $candidate ] = count( str_getcsv( $line, $candidate ) );
		}
		arsort( $scores, SORT_NUMERIC );
		$delimiter = (string) key( $scores );

		return in_array( $delimiter, array( ';', ',', "\t" ), true ) ? $delimiter : ',';
	}

	/**
	 * Queue filesystem paths.
	 *
	 * @return array
	 */
	private function paths() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return array(
				'base'       => '',
				'pending'    => '',
				'processing' => '',
				'imported'   => '',
				'failed'     => '',
			);
		}

		$base = trailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) ) . 'taxocommerce-import-export/queue';

		return array(
			'base'       => $base,
			'pending'    => trailingslashit( $base ) . 'pending',
			'processing' => trailingslashit( $base ) . 'processing',
			'imported'   => trailingslashit( $base ) . 'imported',
			'failed'     => trailingslashit( $base ) . 'failed',
		);
	}

	/**
	 * Create private queue directories.
	 *
	 * @return void
	 */
	private function prepare_directories() {
		$paths = $this->paths();
		foreach ( $paths as $path ) {
			if ( ! $path ) {
				continue;
			}
			wp_mkdir_p( $path );
			$index = trailingslashit( $path ) . 'index.php';
			if ( ! file_exists( $index ) ) {
				file_put_contents( $index, "<?php
// Silence is golden.
" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
			$deny = trailingslashit( $path ) . '.htaccess';
			if ( ! file_exists( $deny ) ) {
				file_put_contents( $deny, "<IfModule mod_authz_core.c>
Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
Deny from all
</IfModule>
" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}

	/**
	 * Sorted CSV files in one bucket.
	 *
	 * @param string $directory Directory.
	 * @return array
	 */
	private function files( $directory ) {
		if ( ! is_dir( $directory ) ) {
			return array();
		}
		$items = array();
		foreach ( (array) scandir( $directory ) as $name ) {
			if ( 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
				continue;
			}
			$path = trailingslashit( $directory ) . $name;
			if ( is_file( $path ) ) {
				$items[] = $path;
			}
		}
		natcasesort( $items );
		return array_values( $items );
	}

	/**
	 * Persistent state.
	 *
	 * @return array
	 */
	private function state() {
		$state = get_option( self::OPTION_STATE, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Store persistent state preserving keys not supplied.
	 *
	 * @param array $state State.
	 * @return void
	 */
	private function store_state( $state ) {
		$previous            = $this->state();
		$state               = array_merge( $previous, (array) $state );
		$state['updated_at'] = current_time( 'mysql' );
		update_option( self::OPTION_STATE, $state, false );
	}

	/**
	 * Watchdog only: normal CSV blocks never wait for Action Scheduler.
	 * This path can recover a failed loopback when cron eventually runs.
	 */
	private function schedule( $delay = 60 ) {
		$delay = max( 60, absint( $delay ) );
		if ( function_exists( 'as_schedule_single_action' )
			&& ( ! function_exists( 'as_has_scheduled_action' )
				|| ! as_has_scheduled_action( self::WORKER_HOOK, array(), self::AS_GROUP ) ) ) {
			as_schedule_single_action( time() + $delay, self::WORKER_HOOK, array(), self::AS_GROUP, true, 10 );
		}
		if ( false === wp_next_scheduled( self::WORKER_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::WORKER_HOOK );
		}
	}

	/** Dispatch the next bounded request immediately via local loopback. */
	private function dispatch_next() {
		$state = $this->state();
		if ( empty( $state['enabled'] )
			|| ! in_array( (string) ( $state['status'] ?? '' ), array( 'processing', 'waiting_next' ), true ) ) {
			return;
		}
		$token = (string) ( $state['dispatch_token'] ?? '' );
		$this->schedule( 60 ); // Backup only.
		if ( '' === $token ) {
			return;
		}
		$response = wp_remote_post( admin_url( 'admin-ajax.php' ), array(
			'blocking'    => false,
			'timeout'     => 1,
			'redirection' => 0,
			'sslverify'   => true,
			'body'        => array(
				'action' => 'taxocommerce_ie_batch_continue',
				'token'  => $token,
			),
		) );
		if ( is_wp_error( $response ) ) {
			$state['message'] = __( 'Falló la autollamada; la recuperación por cron y desde el administrador sigue disponible.', 'taxocommerce-import-export' );
			$this->store_state( $state );
		}
	}

	/** Restricted internal continuation, no front-end nonce disclosed. */
	public function internal_continue() {
		$state = $this->state();
		$expected = (string) ( $state['dispatch_token'] ?? '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Secret internal bearer token checked with hash_equals.
		$submitted = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( '' === $expected || strlen( $submitted ) > 64 || ! hash_equals( $expected, $submitted )
			|| empty( $state['enabled'] ) ) {
			wp_die( 'Acceso denegado', '', array( 'response' => 403 ) );
		}
		$this->worker();
		wp_die( '', '', array( 'response' => 200 ) );
	}

	/** Browser-assisted continuation with explicit WordPress permissions. */
	public function ajax_step() {
		check_ajax_referer( 'taxocommerce_ie_batch_step', 'nonce' );
		$this->guard();
		$state = $this->state();
		$busy = (bool) get_transient( self::LOCK_KEY );
		if ( ! empty( $state['enabled'] ) && ! $busy ) {
			$this->worker();
		}
		$state = $this->state();
		$sample = TaxoCommerce_IE_Load::last_sample( 'import' );
		wp_send_json_success( array(
			'enabled' => ! empty( $state['enabled'] ),
			'busy'    => $busy,
			'status'  => sanitize_key( (string) ( $state['status'] ?? 'idle' ) ),
			'file'    => sanitize_file_name( (string) ( $state['current_file'] ?? '' ) ),
			'rows'    => absint( $state['processed_rows'] ?? 0 ),
			'errors'  => absint( $state['error_rows'] ?? 0 ),
			'message' => sanitize_text_field( (string) ( $state['message'] ?? '' ) ),
			'last_batch' => absint( $sample['items'] ?? 0 ),
			'next_batch' => TaxoCommerce_IE_Load::batch_size( 'import' ),
			'elapsed' => (float) ( $sample['elapsed'] ?? 0 ),
			'rows_per_second' => (float) ( $sample['rows_per_second'] ?? 0 ),
			'reason' => sanitize_text_field( (string) ( $sample['reason'] ?? '' ) ),
		) );
	}

	/** Only load the browser helper on the Import-Export admin screen. */
	public function enqueue_runner() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page selector.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'taxocommerce-import-export' !== $page ) {
			return;
		}
		wp_enqueue_script(
			'taxocommerce-ie-batch-runner',
			plugin_dir_url( TAXOCOMMERCE_IMPORT_EXPORT_FILE ) . 'assets/batch-runner.js',
			array(),
			TAXOCOMMERCE_IMPORT_EXPORT_VERSION,
			true
		);
		wp_add_inline_script( 'taxocommerce-ie-batch-runner', 'window.tcIeBatchRunner = ' . wp_json_encode( array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'taxocommerce_ie_batch_step' ),
		) ) . ';', 'before' );
	}

	/**
	 * Short lock to prevent concurrent workers.
	 *
	 * @return string
	 */
	private function acquire_lock() {
		if ( get_transient( self::LOCK_KEY ) ) {
			return '';
		}
		$token = strtolower( wp_generate_password( 24, false, false ) );
		set_transient( self::LOCK_KEY, $token, 10 * MINUTE_IN_SECONDS );
		return get_transient( self::LOCK_KEY ) === $token ? $token : '';
	}

	/**
	 * @param string $token Token.
	 * @return void
	 */
	private function release_lock( $token ) {
		if ( $token && get_transient( self::LOCK_KEY ) === $token ) {
			delete_transient( self::LOCK_KEY );
		}
	}

	/**
	 * Unique destination path.
	 *
	 * @param string $directory Directory.
	 * @param string $filename File name.
	 * @return string
	 */
	private function unique_path( $directory, $filename ) {
		$filename = sanitize_file_name( $filename );
		$target   = trailingslashit( $directory ) . $filename;
		if ( ! file_exists( $target ) ) {
			return $target;
		}
		$info = pathinfo( $filename );
		$name = sanitize_file_name( $info['filename'] ?? 'import' );
		$ext  = sanitize_key( $info['extension'] ?? 'csv' );
		$n    = 1;
		do {
			$target = trailingslashit( $directory ) . $name . '-' . $n . '.' . $ext;
			$n++;
		} while ( file_exists( $target ) && $n < 10000 );
		return $target;
	}

	/**
	 * Move file inside managed queue.
	 *
	 * @param string $source Source.
	 * @param string $target Target.
	 * @return bool
	 */
	private function move_file( $source, $target ) {
		wp_mkdir_p( dirname( $target ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic move inside one managed uploads tree.
		if ( @rename( $source, $target ) ) {
			return true;
		}
		if ( ! @copy( $source, $target ) ) {
			return false;
		}
		wp_delete_file( $source );
		return ! is_file( $source );
	}

	/**
	 * Capability guard.
	 *
	 * @return void
	 */
	private function guard() {
		$capability = (string) apply_filters( 'taxocommerce_import_export_capability', 'manage_woocommerce' );
		if ( ! current_user_can( $capability ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para gestionar la cola de importación.', 'taxocommerce-import-export' ) );
		}
	}

	/**
	 * Redirect back to batch tab.
	 *
	 * @param string $status Status.
	 * @param string $message Message.
	 * @return void
	 */
	private function redirect( $status, $message ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'taxocommerce-import-export',
					'tc_ie_tab'     => 'batch',
					'tc_ie_result'  => sanitize_key( $status ),
					'tc_ie_message' => rawurlencode( sanitize_text_field( $message ) ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
