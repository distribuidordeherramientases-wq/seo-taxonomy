<?php
/**
 * Incremental CSV export jobs for TaxoCommerce Import-Export.
 *
 * @package TaxoCommerceImportExport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates large CSV exports in short, resumable AJAX blocks.
 */
final class TaxoCommerce_Import_Export_Exporter {

	const STATE_PREFIX = 'taxocommerce_ie_export_';
	const LOCK_PREFIX  = 'taxocommerce_ie_export_lock_';
	const TTL          = 7200;

	/**
	 * CSV formatter.
	 *
	 * @var TaxoCommerce_Import_Export_CSV
	 */
	private $csv;

	/**
	 * Constructor.
	 *
	 * @param TaxoCommerce_Import_Export_CSV $csv CSV service.
	 */
	public function __construct( $csv ) {
		$this->csv = $csv;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'wp_ajax_taxocommerce_ie_export_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_taxocommerce_ie_export_step', array( $this, 'ajax_step' ) );
		add_action( 'wp_ajax_taxocommerce_ie_export_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_taxocommerce_ie_export_cancel', array( $this, 'ajax_cancel' ) );
		add_action( 'admin_post_taxocommerce_ie_export_download', array( $this, 'download' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Supported export datasets.
	 *
	 * @return array
	 */
	public static function types() {
		return array(
			'products' => array(
				'label'       => __( 'Productos · CSV completo', 'taxocommerce-import-export' ),
				'description' => __( 'Round-trip completo de WooCommerce: identidad, estado, precios, stock, textos, categorías, etiquetas, atributos, marca/proveedor y producto externo.', 'taxocommerce-import-export' ),
				'filters'     => 'products',
			),
			'products_reduced' => array(
				'label'       => __( 'Productos · inventario reducido portable', 'taxocommerce-import-export' ),
				'description' => __( 'Inventario ligero para análisis o traslado: identidad, arquitectura, categorías, etiquetas, marca, atributos, Vocabulary y atributos SEO. Excluye textos largos, precios, stock, proveedor e imágenes.', 'taxocommerce-import-export' ),
				'filters'     => 'products',
			),
			'product_categories' => array(
				'label'       => __( 'Categorías de producto', 'taxocommerce-import-export' ),
				'description' => __( 'ID, nombre, slug, padre, descripción e imagen de categoría.', 'taxocommerce-import-export' ),
				'filters'     => 'categories',
			),
			'posts' => array(
				'label'       => __( 'Entradas', 'taxocommerce-import-export' ),
				'description' => __( 'Estado, título, slug, extracto, contenido, fecha, autor, categorías y etiquetas.', 'taxocommerce-import-export' ),
				'filters'     => 'content',
			),
			'pages' => array(
				'label'       => __( 'Páginas', 'taxocommerce-import-export' ),
				'description' => __( 'Estado, título, slug, extracto, contenido, fecha y autor.', 'taxocommerce-import-export' ),
				'filters'     => 'content',
			),
		);
	}

	/**
	 * Render export screen.
	 *
	 * @return void
	 */
	public function render() {
		$types      = self::types();
		$hierarchy  = $this->hierarchy_options();
		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $categories ) ) {
			$categories = array();
		}
		?>
		<section class="card" style="max-width:none;padding:20px;">
			<h2 style="margin-top:0"><?php esc_html_e( 'Exportación por bloques', 'taxocommerce-import-export' ); ?></h2>
			<p><?php esc_html_e( 'Elige qué dato quieres exportar. El fichero se construye en peticiones cortas y reanudables para no mantener PHP ocupado durante todo el catálogo.', 'taxocommerce-import-export' ); ?></p>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Los filtros se combinan. Si no seleccionas ningún estado, categoría ni nivel jerárquico, se exportan todos los registros de ese tipo.', 'taxocommerce-import-export' ); ?></p></div>

			<form id="tc-ie-export-form" method="post" action="#">
				<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:24px;align-items:start;">
					<div>
						<label for="tc-ie-export-type"><strong><?php esc_html_e( 'Tipo de dato', 'taxocommerce-import-export' ); ?></strong></label>
						<select id="tc-ie-export-type" name="content_type" style="width:100%;margin-top:6px;">
							<?php foreach ( $types as $key => $meta ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" data-filter-group="<?php echo esc_attr( $meta['filters'] ); ?>"><?php echo esc_html( $meta['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<div id="tc-ie-export-type-description" class="description" style="margin-top:8px;"></div>

						<fieldset id="tc-ie-export-statuses" style="margin-top:20px;">
							<legend><strong><?php esc_html_e( 'Estado', 'taxocommerce-import-export' ); ?></strong></legend>
							<p class="description"><?php esc_html_e( 'Sin marcar ninguno = todos los estados válidos.', 'taxocommerce-import-export' ); ?></p>
							<div style="display:flex;gap:12px;flex-wrap:wrap;">
								<?php
								foreach (
									array(
										'publish' => __( 'Publicado', 'taxocommerce-import-export' ),
										'draft'   => __( 'Borrador', 'taxocommerce-import-export' ),
										'pending' => __( 'Pendiente de revisión', 'taxocommerce-import-export' ),
										'private' => __( 'Privado', 'taxocommerce-import-export' ),
										'future'  => __( 'Programado', 'taxocommerce-import-export' ),
										'trash'   => __( 'Papelera', 'taxocommerce-import-export' ),
									) as $status => $label
								) :
									?>
									<label><input type="checkbox" name="statuses[]" value="<?php echo esc_attr( $status ); ?>"> <?php echo esc_html( $label ); ?></label>
								<?php endforeach; ?>
							</div>
						</fieldset>
					</div>

					<div id="tc-ie-export-product-filters">
						<h3 style="margin-top:0"><?php esc_html_e( 'Filtros de catálogo', 'taxocommerce-import-export' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Puedes combinar Cluster, Hub primario, Hub secundario y categorías. Las categorías sin jerarquía siguen disponibles cuando dejas los niveles en “Todos”.', 'taxocommerce-import-export' ); ?></p>

						<?php if ( ! empty( $hierarchy['available'] ) ) : ?>
							<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:12px 0;">
								<label><strong><?php esc_html_e( 'Cluster', 'taxocommerce-import-export' ); ?></strong><br>
									<select id="tc-ie-export-cluster" name="cluster_id" style="width:100%;">
										<option value="0"><?php esc_html_e( 'Todos los clusters', 'taxocommerce-import-export' ); ?></option>
										<?php foreach ( $hierarchy['clusters'] as $row ) : ?>
											<option value="<?php echo esc_attr( (string) $row['id'] ); ?>"><?php echo esc_html( $row['label'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
								<label><strong><?php esc_html_e( 'Hub primario', 'taxocommerce-import-export' ); ?></strong><br>
									<select id="tc-ie-export-primary" name="hub_primary_id" style="width:100%;">
										<option value="0"><?php esc_html_e( 'Todos los hubs primarios', 'taxocommerce-import-export' ); ?></option>
										<?php foreach ( $hierarchy['primaries'] as $row ) : ?>
											<option value="<?php echo esc_attr( (string) $row['id'] ); ?>" data-parent="<?php echo esc_attr( (string) $row['parent'] ); ?>"><?php echo esc_html( $row['label'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
								<label><strong><?php esc_html_e( 'Hub secundario', 'taxocommerce-import-export' ); ?></strong><br>
									<select id="tc-ie-export-secondary" name="hub_secondary_id" style="width:100%;">
										<option value="0"><?php esc_html_e( 'Todos los hubs secundarios', 'taxocommerce-import-export' ); ?></option>
										<?php foreach ( $hierarchy['secondaries'] as $row ) : ?>
											<option value="<?php echo esc_attr( (string) $row['id'] ); ?>" data-parent="<?php echo esc_attr( (string) $row['parent'] ); ?>"><?php echo esc_html( $row['label'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
							</div>
						<?php else : ?>
							<div class="notice notice-warning inline"><p><?php esc_html_e( 'No se ha detectado la jerarquía modular de TaxoCommerce Core. Puedes seguir exportando por categorías y estados.', 'taxocommerce-import-export' ); ?></p></div>
						<?php endif; ?>

						<label for="tc-ie-export-categories"><strong><?php esc_html_e( 'Categorías', 'taxocommerce-import-export' ); ?></strong></label><br>
						<select id="tc-ie-export-categories" name="category_ids[]" multiple size="9" style="width:100%;max-width:760px;margin-top:6px;">
							<?php foreach ( $categories as $term ) : ?>
								<option value="<?php echo esc_attr( (string) $term->term_id ); ?>"><?php echo esc_html( $term->name ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Ctrl/Cmd permite seleccionar varias. Si eliges una rama jerárquica y categorías concretas, se exporta la intersección.', 'taxocommerce-import-export' ); ?></p>
					</div>
				</div>

				<p style="margin-top:20px;">
					<button id="tc-ie-export-start" type="submit" class="button button-primary"><?php esc_html_e( 'Crear CSV por bloques', 'taxocommerce-import-export' ); ?></button>
				</p>
			</form>

			<div id="tc-ie-export-progress" class="notice notice-info inline" style="display:none;margin-top:18px;padding:12px 16px;" role="status" aria-live="polite">
				<p id="tc-ie-export-message" style="margin-top:0;"></p>
				<progress id="tc-ie-export-bar" max="100" value="0" style="width:min(760px,100%);height:18px;"></progress>
				<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
					<button id="tc-ie-export-retry" type="button" class="button" style="display:none;"><?php esc_html_e( 'Reintentar bloque', 'taxocommerce-import-export' ); ?></button>
					<button id="tc-ie-export-cancel" type="button" class="button" style="display:none;"><?php esc_html_e( 'Cancelar exportación', 'taxocommerce-import-export' ); ?></button>
					<a id="tc-ie-export-download" class="button button-primary" href="#" style="display:none;"><?php esc_html_e( 'Descargar CSV terminado', 'taxocommerce-import-export' ); ?></a>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Enqueue export controller.
	 *
	 * @param string $hook Admin hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = isset( $_GET['tc_ie_tab'] ) ? sanitize_key( wp_unslash( $_GET['tc_ie_tab'] ) ) : 'export'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'taxocommerce-import-export' !== $page || 'export' !== $tab ) {
			return;
		}

		wp_enqueue_script(
			'taxocommerce-ie-exporter',
			plugin_dir_url( TAXOCOMMERCE_IMPORT_EXPORT_FILE ) . 'assets/exporter.js',
			array(),
			TAXOCOMMERCE_IMPORT_EXPORT_VERSION,
			true
		);
		wp_localize_script(
			'taxocommerce-ie-exporter',
			'taxocommerceIeExporter',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'taxocommerce_ie_exporter' ),
				'types'   => self::types(),
			)
		);
	}

	/**
	 * Start export job.
	 *
	 * @return void
	 */
	public function ajax_start() {
		$this->guard_ajax();
		check_ajax_referer( 'taxocommerce_ie_exporter', 'nonce' );

		$type = isset( $_POST['content_type'] ) ? sanitize_key( wp_unslash( $_POST['content_type'] ) ) : '';
		if ( ! isset( self::types()[ $type ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Tipo de exportación no válido.', 'taxocommerce-import-export' ) ), 400 );
		}

		$this->delete_current_job();

		$filters = $this->filters_from_request( $type );
		$scope   = $this->resolve_category_scope( $filters );
		if ( is_wp_error( $scope ) ) {
			wp_send_json_error( array( 'message' => $scope->get_error_message() ), 400 );
		}
		$filters['category_scope_active'] = $scope['active'];
		$filters['category_scope']        = $scope['ids'];

		$job_id = wp_generate_uuid4();
		$dir    = $this->export_dir();
		if ( is_wp_error( $dir ) ) {
			wp_send_json_error( array( 'message' => $dir->get_error_message() ), 500 );
		}
		$this->cleanup_export_dir( $dir );

		$filename = 'taxocommerce_' . $type . '_' . gmdate( 'Ymd_His' ) . '.csv';
		$path     = trailingslashit( $dir ) . get_current_user_id() . '-' . sanitize_file_name( $job_id ) . '.csv';
		$handle   = fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Incremental CSV file.
		if ( false === $handle ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo crear el CSV temporal.', 'taxocommerce-import-export' ) ), 500 );
		}

		fwrite( $handle, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		$header = $this->csv->export_header_for_job( $type );
		fputcsv( $handle, $header, ',', '"', '' );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$total = $this->count_total( $type, $filters );
		if ( is_wp_error( $total ) ) {
			wp_delete_file( $path );
			wp_send_json_error( array( 'message' => $total->get_error_message() ), 500 );
		}

		$state = array(
			'id'        => $job_id,
			'user_id'   => get_current_user_id(),
			'type'      => $type,
			'type_label'=> (string) self::types()[ $type ]['label'],
			'path'      => $path,
			'filename'  => $filename,
			'filters'   => $filters,
			'cursor'    => 0,
			'examined'  => 0,
			'processed' => 0,
			'skipped'   => 0,
			'blocks'    => 0,
			'file_size' => (int) filesize( $path ),
			'total'     => absint( $total ),
			'complete'  => false,
			'created'   => time(),
			'updated'   => time(),
		);
		$this->store_state( $state );

		wp_send_json_success(
			array(
				'job'       => $job_id,
				'typeLabel' => $state['type_label'],
				'processed' => 0,
				'examined'  => 0,
				'skipped'   => 0,
				'blocks'    => 0,
				'total'     => $state['total'],
			)
		);
	}

	/**
	 * Process one bounded export block.
	 *
	 * @return void
	 */
	public function ajax_step() {
		$this->guard_ajax();
		check_ajax_referer( 'taxocommerce_ie_exporter', 'nonce' );

		$state = $this->state();
		$job   = isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : '';
		if ( ! $state || ! $job || ! hash_equals( (string) $state['id'], $job ) ) {
			wp_send_json_error( array( 'message' => __( 'La exportación ha caducado o pertenece a otra sesión.', 'taxocommerce-import-export' ) ), 409 );
		}
		if ( ! empty( $state['complete'] ) ) {
			wp_send_json_success( $this->response_state( $state ) );
		}

		$token = $this->acquire_lock( $job );
		if ( '' === $token ) {
			wp_send_json_error( array( 'message' => __( 'Ya hay un bloque de exportación en curso. Reintenta en unos segundos.', 'taxocommerce-import-export' ) ), 409 );
		}

		try {
			$result = $this->process_block( $state );
		} catch ( Throwable $e ) {
			$this->release_lock( $token );
			wp_send_json_error( array( 'message' => sanitize_text_field( $e->getMessage() ) ), 500 );
		}
		$this->release_lock( $token );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		$this->store_state( $result );
		wp_send_json_success( $this->response_state( $result ) );
	}

	/**
	 * Current job status.
	 *
	 * @return void
	 */
	public function ajax_status() {
		$this->guard_ajax();
		check_ajax_referer( 'taxocommerce_ie_exporter', 'nonce' );
		$state = $this->state();
		if ( ! $state ) {
			wp_send_json_success( array( 'active' => false ) );
		}
		$data           = $this->response_state( $state );
		$data['active'] = true;
		wp_send_json_success( $data );
	}

	/**
	 * Cancel current job and remove its partial file.
	 *
	 * @return void
	 */
	public function ajax_cancel() {
		$this->guard_ajax();
		check_ajax_referer( 'taxocommerce_ie_exporter', 'nonce' );
		$this->delete_current_job();
		delete_option( $this->lock_key() );
		wp_send_json_success( array( 'cancelled' => true ) );
	}

	/**
	 * Authenticated completed-file download.
	 *
	 * @return void
	 */
	public function download() {
		$this->guard();
		$job = isset( $_GET['job'] ) ? sanitize_text_field( wp_unslash( $_GET['job'] ) ) : '';
		check_admin_referer( 'taxocommerce_ie_export_download_' . $job );

		$state = $this->state();
		if ( ! $state || ! $job || ! hash_equals( (string) $state['id'], $job ) || empty( $state['complete'] ) ) {
			wp_die( esc_html__( 'El CSV no está terminado o ha caducado.', 'taxocommerce-import-export' ) );
		}
		if ( empty( $state['path'] ) || ! is_file( $state['path'] ) ) {
			wp_die( esc_html__( 'El archivo exportado ya no está disponible.', 'taxocommerce-import-export' ) );
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $state['filename'] ) . '"' );
		header( 'Content-Length: ' . (int) filesize( $state['path'] ) );
		header( 'X-Content-Type-Options: nosniff' );

		$handle = fopen( $state['path'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			wp_die( esc_html__( 'No se pudo abrir el CSV.', 'taxocommerce-import-export' ) );
		}
		fpassthru( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fpassthru,WordPress.Security.EscapeOutput.OutputNotEscaped
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Process up to one short block.
	 *
	 * @param array $state Job state.
	 * @return array|WP_Error
	 */
	private function process_block( $state ) {
		$type    = sanitize_key( (string) ( $state['type'] ?? '' ) );
		$filters = is_array( $state['filters'] ?? null ) ? $state['filters'] : array();
		$cursor  = absint( $state['cursor'] ?? 0 );
		$limit   = max( 10, min( 250, absint( apply_filters( 'taxocommerce_import_export_export_rows_per_block', 50 ) ) ) );
		$seconds = max( 2, min( 15, (float) apply_filters( 'taxocommerce_import_export_export_runtime_seconds', 6 ) ) );
		$deadline = microtime( true ) + $seconds;

		$ids = $this->next_ids( $type, $cursor, $limit, $filters );
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}
		if ( ! $ids ) {
			$state['complete'] = true;
			$state['updated']  = time();
			return $state;
		}

		// A PHP timeout may leave rows appended after the last committed cursor.
		// Rewind to the last saved byte offset before repeating that short block.
		$handle = fopen( $state['path'], 'c+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return new WP_Error( 'tc_ie_export_open', __( 'No se pudo reabrir el CSV temporal.', 'taxocommerce-import-export' ) );
		}

		if ( ! isset( $state['file_size'] ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'tc_ie_export_legacy_job', __( 'Esta exportación empezó con una versión anterior. Inicia una nueva para proteger el CSV.', 'taxocommerce-import-export' ) );
		}
		$committed_size = absint( $state['file_size'] ?? 0 );
		$actual_size = (int) filesize( $state['path'] );
		if ( $actual_size < $committed_size || ! ftruncate( $handle, $committed_size ) || 0 !== fseek( $handle, 0, SEEK_END ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'tc_ie_export_recover', __( 'No se ha podido recuperar el CSV parcial tras una interrupción.', 'taxocommerce-import-export' ) );
		}

		$processed_ids = 0;
		foreach ( $ids as $id ) {
			if ( microtime( true ) >= $deadline && $processed_ids > 0 ) {
				break;
			}

			$row = $this->csv->export_row_for_job( $type, absint( $id ) );
			if ( is_array( $row ) && false === fputcsv( $handle, $row, ',', '"', '' ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return new WP_Error( 'tc_ie_export_write', __( 'No se pudo escribir una fila del CSV. Reintenta el bloque.', 'taxocommerce-import-export' ) );
			}
			$state['cursor'] = absint( $id );
			$processed_ids++;
			$state['examined'] = absint( $state['examined'] ?? 0 ) + 1;
			if ( is_array( $row ) ) {
				$state['processed'] = absint( $state['processed'] ?? 0 ) + 1;
			} else {
				$state['skipped'] = absint( $state['skipped'] ?? 0 ) + 1;
			}
		}
		if ( ! fflush( $handle ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'tc_ie_export_flush', __( 'No se pudo confirmar el bloque escrito. Reintenta.', 'taxocommerce-import-export' ) );
		}
		$state['file_size'] = (int) ftell( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$state['blocks'] = absint( $state['blocks'] ?? 0 ) + 1;
		$state['updated'] = time();
		if ( $processed_ids === count( $ids ) && count( $ids ) < $limit ) {
			$state['complete'] = true;
		}
		return $state;
	}

	/**
	 * Get the next ordered identifiers after the cursor.
	 *
	 * @param string $type Dataset.
	 * @param int    $cursor Last ID.
	 * @param int    $limit Max IDs.
	 * @param array  $filters Filters.
	 * @return array|WP_Error
	 */
	private function next_ids( $type, $cursor, $limit, $filters ) {
		if ( 'product_categories' === $type ) {
			if ( ! empty( $filters['category_scope_active'] ) && empty( $filters['category_scope'] ) ) {
				return array();
			}
			$ids = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'fields'     => 'ids',
					'orderby'    => 'id',
					'order'      => 'ASC',
					'include'    => ! empty( $filters['category_scope_active'] ) ? (array) $filters['category_scope'] : array(),
				)
			);
			if ( is_wp_error( $ids ) ) {
				return $ids;
			}
			$ids = array_values(
				array_filter(
					array_map( 'absint', (array) $ids ),
					static function ( $id ) use ( $cursor ) {
						return $id > $cursor;
					}
				)
			);
			return array_slice( $ids, 0, $limit );
		}

		$post_type = in_array( $type, array( 'products', 'products_reduced' ), true )
			? 'product'
			: ( 'pages' === $type ? 'page' : 'post' );

		$args = array(
			'post_type'              => $post_type,
			'post_status'            => (array) $filters['statuses'],
			'posts_per_page'         => $limit,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'suppress_filters'       => false,
		);
		if ( 'product' === $post_type && ! empty( $filters['category_scope_active'] ) ) {
			$category_ids = array_values( array_filter( array_map( 'absint', (array) $filters['category_scope'] ) ) );
			if ( ! $category_ids ) {
				return array();
			}
			$args['tax_query'] = array(
				array(
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => $category_ids,
					'include_children' => true,
				),
			);
		}

		global $wpdb;
		$where = static function ( $where_sql, $query ) use ( $cursor, $wpdb ) {
			return $where_sql . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $cursor );
		};
		add_filter( 'posts_where', $where, 10, 2 );
		$query = new WP_Query( $args );
		remove_filter( 'posts_where', $where, 10 );
		return array_values( array_map( 'absint', (array) $query->posts ) );
	}

	/**
	 * Count filtered rows once at start for progress.
	 *
	 * @param string $type Dataset.
	 * @param array  $filters Filters.
	 * @return int|WP_Error
	 */
	private function count_total( $type, $filters ) {
		if ( 'product_categories' === $type ) {
			if ( ! empty( $filters['category_scope_active'] ) ) {
				return count( array_values( array_filter( array_map( 'absint', (array) $filters['category_scope'] ) ) ) );
			}
			$count = wp_count_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
			return is_wp_error( $count ) ? $count : absint( $count );
		}

		$post_type = in_array( $type, array( 'products', 'products_reduced' ), true )
			? 'product'
			: ( 'pages' === $type ? 'page' : 'post' );
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => (array) $filters['statuses'],
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		);
		if ( 'product' === $post_type && ! empty( $filters['category_scope_active'] ) ) {
			$ids = array_values( array_filter( array_map( 'absint', (array) $filters['category_scope'] ) ) );
			if ( ! $ids ) {
				return 0;
			}
			$args['tax_query'] = array(
				array(
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => $ids,
					'include_children' => true,
				),
			);
		}
		$query = new WP_Query( $args );
		return absint( $query->found_posts );
	}

	/**
	 * Read and normalize filters.
	 *
	 * @param string $type Dataset.
	 * @return array
	 */
	private function filters_from_request( $type ) {
		$product_statuses = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );
		$content_statuses = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );
		$allowed_statuses = in_array( $type, array( 'products', 'products_reduced' ), true ) ? $product_statuses : $content_statuses;

		$raw_statuses = isset( $_POST['statuses'] ) && is_array( $_POST['statuses'] ) ? wp_unslash( $_POST['statuses'] ) : array();
		$raw_statuses = array_map( 'sanitize_key', array_filter( $raw_statuses, 'is_scalar' ) );
		$statuses = array_values( array_intersect( $allowed_statuses, array_unique( $raw_statuses ) ) );
		if ( ! $statuses ) {
			$statuses = $allowed_statuses;
		}

		$raw_categories = isset( $_POST['category_ids'] ) && is_array( $_POST['category_ids'] ) ? wp_unslash( $_POST['category_ids'] ) : array();
		$raw_categories = array_map( 'absint', array_filter( $raw_categories, 'is_scalar' ) );

		$cluster = isset( $_POST['cluster_id'] ) && is_scalar( $_POST['cluster_id'] ) ? absint( wp_unslash( $_POST['cluster_id'] ) ) : 0;
		$primary = isset( $_POST['hub_primary_id'] ) && is_scalar( $_POST['hub_primary_id'] ) ? absint( wp_unslash( $_POST['hub_primary_id'] ) ) : 0;
		$secondary = isset( $_POST['hub_secondary_id'] ) && is_scalar( $_POST['hub_secondary_id'] ) ? absint( wp_unslash( $_POST['hub_secondary_id'] ) ) : 0;

		return array(
			'statuses'         => $statuses,
			'cluster_id'       => $cluster,
			'hub_primary_id'   => $primary,
			'hub_secondary_id' => $secondary,
			'category_ids'     => array_values( array_unique( array_filter( $raw_categories ) ) ),
		);
	}

	/**
	 * Resolve hierarchy + explicit category filters into one category scope.
	 *
	 * @param array $filters Filters.
	 * @return array|WP_Error
	 */
	private function resolve_category_scope( $filters ) {
		$explicit = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $filters['category_ids'] ?? array() ) ) ) ) );
		$cluster  = absint( $filters['cluster_id'] ?? 0 );
		$primary  = absint( $filters['hub_primary_id'] ?? 0 );
		$secondary= absint( $filters['hub_secondary_id'] ?? 0 );
		$has_hierarchy = $cluster || $primary || $secondary;

		if ( ! $has_hierarchy ) {
			return array( 'active' => ! empty( $explicit ), 'ids' => $explicit );
		}

		if ( $primary && $cluster ) {
			$parents = array_values( array_filter( array_map( 'absint', (array) get_post_meta( $primary, '_taxocommerce_core_parent_cluster', false ) ) ) );
			if ( ! in_array( $cluster, $parents, true ) ) {
				return new WP_Error( 'tc_ie_export_hierarchy_primary', __( 'El Hub primario seleccionado no pertenece al Cluster indicado.', 'taxocommerce-import-export' ) );
			}
		}
		if ( $secondary && $primary ) {
			$parents = array_values( array_filter( array_map( 'absint', (array) get_post_meta( $secondary, '_taxocommerce_core_parent_hub_primary', false ) ) ) );
			if ( ! in_array( $primary, $parents, true ) ) {
				return new WP_Error( 'tc_ie_export_hierarchy_secondary', __( 'El Hub secundario seleccionado no pertenece al Hub primario indicado.', 'taxocommerce-import-export' ) );
			}
		}
		if ( $secondary && $cluster && ! $primary ) {
			$secondary_parents = array_values( array_filter( array_map( 'absint', (array) get_post_meta( $secondary, '_taxocommerce_core_parent_hub_primary', false ) ) ) );
			$valid = false;
			foreach ( $secondary_parents as $candidate_primary ) {
				$clusters = array_values( array_filter( array_map( 'absint', (array) get_post_meta( $candidate_primary, '_taxocommerce_core_parent_cluster', false ) ) ) );
				if ( in_array( $cluster, $clusters, true ) ) {
					$valid = true;
					break;
				}
			}
			if ( ! $valid ) {
				return new WP_Error( 'tc_ie_export_hierarchy_secondary_cluster', __( 'El Hub secundario seleccionado no pertenece al Cluster indicado.', 'taxocommerce-import-export' ) );
			}
		}

		$secondary_ids = array();
		if ( $secondary ) {
			$secondary_ids = array( $secondary );
		} elseif ( $primary ) {
			$secondary_ids = $this->child_pages_by_meta( '_taxocommerce_core_parent_hub_primary', array( $primary ) );
		} elseif ( $cluster ) {
			$primary_ids   = $this->child_pages_by_meta( '_taxocommerce_core_parent_cluster', array( $cluster ) );
			$secondary_ids = $this->child_pages_by_meta( '_taxocommerce_core_parent_hub_primary', $primary_ids );
		}

		$ids = $this->categories_by_secondaries( $secondary_ids );
		if ( $explicit ) {
			$ids = array_values( array_intersect( $ids, $explicit ) );
		}

		return array( 'active' => true, 'ids' => $ids );
	}

	/**
	 * Hierarchy options sourced from modular Core metadata.
	 *
	 * @return array
	 */
	private function hierarchy_options() {
		$clusters = $this->pages_with_role( 'cluster' );
		$primaries = $this->pages_with_role( 'hub_primary' );
		$secondaries = $this->pages_with_role( 'hub_secondary' );

		foreach ( $primaries as &$row ) {
			$parents = array_values( array_filter( array_map( 'absint', (array) get_post_meta( $row['id'], '_taxocommerce_core_parent_cluster', false ) ) ) );
			$row['parent'] = $parents ? $parents[0] : 0;
		}
		unset( $row );
		foreach ( $secondaries as &$row ) {
			$parents = array_values( array_filter( array_map( 'absint', (array) get_post_meta( $row['id'], '_taxocommerce_core_parent_hub_primary', false ) ) ) );
			$row['parent'] = $parents ? $parents[0] : 0;
		}
		unset( $row );

		return array(
			'available'   => ! empty( $clusters ) || ! empty( $primaries ) || ! empty( $secondaries ),
			'clusters'    => $clusters,
			'primaries'   => $primaries,
			'secondaries' => $secondaries,
		);
	}

	/**
	 * Pages carrying one Core role.
	 *
	 * @param string $role Role.
	 * @return array
	 */
	private function pages_with_role( $role ) {
		$ids = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_key'       => '_taxocommerce_core_seo_role',
				'meta_value'     => sanitize_key( $role ),
			)
		);
		$out = array();
		foreach ( (array) $ids as $id ) {
			$out[] = array(
				'id'     => absint( $id ),
				'label'  => (string) get_the_title( $id ),
				'parent' => 0,
			);
		}
		return $out;
	}

	/**
	 * Resolve page children using repeated parent metadata.
	 *
	 * @param string $meta_key Parent meta.
	 * @param array  $parents Parent IDs.
	 * @return array
	 */
	private function child_pages_by_meta( $meta_key, $parents ) {
		$parents = array_values( array_filter( array_map( 'absint', (array) $parents ) ) );
		if ( ! $parents ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => sanitize_key( $meta_key ),
						'value'   => $parents,
						'compare' => 'IN',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		return array_values( array_map( 'absint', (array) $ids ) );
	}

	/**
	 * Resolve categories assigned to selected secondary hubs.
	 *
	 * @param array $secondary_ids Hub IDs.
	 * @return array
	 */
	private function categories_by_secondaries( $secondary_ids ) {
		$secondary_ids = array_values( array_filter( array_map( 'absint', (array) $secondary_ids ) ) );
		if ( ! $secondary_ids ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'fields'     => 'ids',
				'orderby'    => 'id',
				'order'      => 'ASC',
				'meta_query' => array(
					array(
						'key'     => '_taxocommerce_core_parent_hub_secondary',
						'value'   => $secondary_ids,
						'compare' => 'IN',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		return is_wp_error( $terms ) ? array() : array_values( array_map( 'absint', (array) $terms ) );
	}

	/**
	 * Export directory protected against direct web access.
	 *
	 * @return string|WP_Error
	 */
	private function export_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return new WP_Error( 'tc_ie_export_dir', __( 'WordPress no ha podido resolver uploads.', 'taxocommerce-import-export' ) );
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'taxocommerce-import-export/exports';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'tc_ie_export_mkdir', __( 'No se pudo crear el directorio privado de exportación.', 'taxocommerce-import-export' ) );
		}
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$webconfig = trailingslashit( $dir ) . 'web.config';
		if ( ! file_exists( $webconfig ) ) {
			file_put_contents( $webconfig, "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	/**
	 * Remove abandoned CSV files after one day.
	 *
	 * Job state itself expires after two hours; keeping completed files a little
	 * longer allows a download retry while preventing indefinite disk growth.
	 *
	 * @param string $dir Export directory.
	 * @return void
	 */
	private function cleanup_export_dir( $dir ) {
		$paths = glob( trailingslashit( (string) $dir ) . '*.csv' );
		if ( ! is_array( $paths ) ) {
			return;
		}
		$cutoff = time() - DAY_IN_SECONDS;
		foreach ( $paths as $path ) {
			if ( ! is_file( $path ) ) {
				continue;
			}
			$modified = filemtime( $path );
			if ( $modified && $modified < $cutoff ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Response shape.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private function response_state( $state ) {
		return array(
			'job'         => (string) ( $state['id'] ?? '' ),
			'typeLabel'   => (string) ( $state['type_label'] ?? '' ),
			'processed'   => absint( $state['processed'] ?? 0 ),
			'examined'    => absint( $state['examined'] ?? $state['processed'] ?? 0 ),
			'skipped'     => absint( $state['skipped'] ?? 0 ),
			'blocks'      => absint( $state['blocks'] ?? 0 ),
			'total'       => absint( $state['total'] ?? 0 ),
			'complete'    => ! empty( $state['complete'] ),
			'downloadUrl' => ! empty( $state['complete'] ) ? $this->download_url( $state ) : '',
		);
	}

	/**
	 * Download URL.
	 *
	 * @param array $state State.
	 * @return string
	 */
	private function download_url( $state ) {
		return add_query_arg(
			array(
				'action'   => 'taxocommerce_ie_export_download',
				'job'      => (string) $state['id'],
				'_wpnonce' => wp_create_nonce( 'taxocommerce_ie_export_download_' . (string) $state['id'] ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Per-user transient key.
	 *
	 * @return string
	 */
	private function state_key() {
		return self::STATE_PREFIX . get_current_user_id();
	}

	/**
	 * Per-user lock key.
	 *
	 * @return string
	 */
	private function lock_key() {
		return self::LOCK_PREFIX . get_current_user_id();
	}

	/**
	 * Read state.
	 *
	 * @return array|null
	 */
	private function state() {
		$state = get_transient( $this->state_key() );
		if ( ! is_array( $state ) || empty( $state['id'] ) || absint( $state['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return null;
		}
		if ( empty( $state['path'] ) || ! is_file( $state['path'] ) ) {
			delete_transient( $this->state_key() );
			return null;
		}
		return $state;
	}

	/**
	 * Store state.
	 *
	 * @param array $state State.
	 * @return void
	 */
	private function store_state( $state ) {
		set_transient( $this->state_key(), $state, self::TTL );
	}

	/**
	 * Delete current job/file.
	 *
	 * @return void
	 */
	private function delete_current_job() {
		$state = $this->state();
		if ( $state && ! empty( $state['path'] ) && is_file( $state['path'] ) ) {
			wp_delete_file( $state['path'] );
		}
		delete_transient( $this->state_key() );
	}

	/**
	 * Acquire short-lived per-user lock.
	 *
	 * @param string $job Job ID.
	 * @return string
	 */
	private function acquire_lock( $job ) {
		$key = $this->lock_key();
		$old = get_option( $key, array() );
		if ( is_array( $old ) && time() - absint( $old['created'] ?? 0 ) > 90 ) {
			delete_option( $key );
		}
		$token = wp_generate_uuid4();
		return add_option( $key, array( 'token' => $token, 'job' => $job, 'created' => time() ), '', false ) ? $token : '';
	}

	/**
	 * Release own lock.
	 *
	 * @param string $token Token.
	 * @return void
	 */
	private function release_lock( $token ) {
		$key = $this->lock_key();
		$old = get_option( $key, array() );
		if ( is_array( $old ) && hash_equals( (string) ( $old['token'] ?? '' ), (string) $token ) ) {
			delete_option( $key );
		}
	}

	/**
	 * AJAX permission guard.
	 *
	 * @return void
	 */
	private function guard_ajax() {
		$capability = (string) apply_filters( 'taxocommerce_import_export_capability', 'manage_woocommerce' );
		if ( ! current_user_can( $capability ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos para exportar.', 'taxocommerce-import-export' ) ), 403 );
		}
	}

	/**
	 * Normal permission guard.
	 *
	 * @return void
	 */
	private function guard() {
		$capability = (string) apply_filters( 'taxocommerce_import_export_capability', 'manage_woocommerce' );
		if ( ! current_user_can( $capability ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos para exportar.', 'taxocommerce-import-export' ) );
		}
	}
}
