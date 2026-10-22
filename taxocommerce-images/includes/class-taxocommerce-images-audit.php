<?php
/**
 * Read-only, resumable audit of WordPress Media images.
 *
 * Based on the checks from the historic SEO Images panel, without any
 * dependency on TaxoCommerce SEO tables, services or workers.
 *
 * @package TaxoCommerceImages
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bounded Media audit. No writes to attachments or image files.
 */
final class TaxoCommerce_Images_Audit {

	const STATE_OPTION = 'taxocommerce_images_audit_state';
	const LOCK_OPTION  = 'taxocommerce_images_audit_lock';
	const SCHEMA       = 3;
	const BATCH_SIZE   = 75;
	const FINDING_CAP  = 300;

	/**
	 * Register authenticated entry points.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_post_taxocommerce_images_start_audit', array( $this, 'handle_start' ) );
		add_action( 'admin_post_taxocommerce_images_continue_audit', array( $this, 'handle_continue' ) );
		add_action( 'admin_post_taxocommerce_images_reset_audit', array( $this, 'handle_reset' ) );
		add_action( 'wp_ajax_taxocommerce_images_audit_step', array( $this, 'handle_ajax_step' ) );
	}

	/**
	 * Read the persisted snapshot.
	 *
	 * @return array
	 */
	public function state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Names are shared by the dashboard and the audit report.
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'unattached'       => __( 'Sin asociación padre (no implica falta de uso)', 'taxocommerce-images' ),
			'missing_alt'      => __( 'Sin texto ALT', 'taxocommerce-images' ),
			'missing_file'     => __( 'Archivo físico ausente o ilegible', 'taxocommerce-images' ),
			'missing_metadata' => __( 'Metadatos de dimensiones ausentes', 'taxocommerce-images' ),
			'oversized_dims'   => __( 'Dimensiones superiores al límite', 'taxocommerce-images' ),
			'oversized_bytes'  => __( 'Peso superior al límite', 'taxocommerce-images' ),
			'missing_sizes'    => __( 'Miniaturas ausentes (en disco o metadatos)', 'taxocommerce-images' ),
			'generic_title'    => __( 'Título equivalente al nombre del archivo', 'taxocommerce-images' ),
		);
	}

	/**
	 * Begin a fresh scan only after acquiring its lock.
	 *
	 * @return void
	 */
	public function handle_start() {
		$this->guard();
		check_admin_referer( 'taxocommerce_images_start_audit', 'taxocommerce_images_nonce' );
		if ( $this->acquire_lock() ) {
			try {
				update_option( self::STATE_OPTION, $this->new_state(), false );
				$this->process_batch();
			} finally {
				$this->release_lock();
			}
		}
		$this->redirect();
	}

	/**
	 * Manual fallback if JavaScript is disabled.
	 *
	 * @return void
	 */
	public function handle_continue() {
		$this->guard();
		check_admin_referer( 'taxocommerce_images_continue_audit', 'taxocommerce_images_nonce' );
		if ( $this->acquire_lock() ) {
			try {
				$this->process_batch();
			} finally {
				$this->release_lock();
			}
		}
		$this->redirect();
	}

	/**
	 * Remove derived audit data, never media files.
	 *
	 * @return void
	 */
	public function handle_reset() {
		$this->guard();
		check_admin_referer( 'taxocommerce_images_reset_audit', 'taxocommerce_images_nonce' );
		if ( $this->acquire_lock() ) {
			try {
				delete_option( self::STATE_OPTION );
			} finally {
				$this->release_lock();
			}
		}
		$this->redirect();
	}

	/**
	 * One authenticated batch per HTTP request.
	 *
	 * @return void
	 */
	public function handle_ajax_step() {
		$this->guard();
		check_ajax_referer( 'taxocommerce_images_audit', 'nonce' );
		if ( ! $this->acquire_lock() ) {
			wp_send_json_error( array( 'message' => __( 'Hay otro lote en ejecución.', 'taxocommerce-images' ) ), 409 );
		}
		try {
			$state = $this->process_batch();
		} finally {
			$this->release_lock();
		}
		$cpu = isset( $state['cpu_last'] ) && is_array( $state['cpu_last'] ) ? $state['cpu_last'] : array();
		wp_send_json_success(
			array(
				'status' => sanitize_key( $state['status'] ?? '' ),
				'scanned' => absint( $state['scanned'] ?? 0 ),
				'total' => absint( $state['total'] ?? 0 ),
				'last_id' => absint( $state['last_id'] ?? 0 ),
				'paused' => ! empty( $state['cpu_paused'] ),
				'retry_after' => absint( $cpu['retry_after'] ?? TaxoCommerce_Images_CPU::RETRY_SECONDS ),
				'cpu' => $cpu,
			)
		);
	}

	/**
	 * Detect and replace locks orphaned by PHP timeouts.
	 *
	 * @return bool
	 */
	private function acquire_lock() {
		$now = time();
		if ( add_option( self::LOCK_OPTION, $now, '', false ) ) {
			return true;
		}
		$previous = absint( get_option( self::LOCK_OPTION, 0 ) );
		if ( $previous && $previous < $now - 120 ) {
			delete_option( self::LOCK_OPTION );
			return add_option( self::LOCK_OPTION, $now, '', false );
		}
		return false;
	}

	/**
	 * Release the current batch lock.
	 *
	 * @return void
	 */
	private function release_lock() {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Freeze the largest attachment ID at scan start. Files created later will
	 * be included in the next run, not injected into this one.
	 *
	 * @return array
	 */
	private function new_state() {
		global $wpdb;
		$counts = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS total, COALESCE(MAX(ID),0) AS max_id
				 FROM %i
				 WHERE post_type = %s AND post_status = %s AND post_mime_type LIKE %s',
				$wpdb->posts,
				'attachment',
				'inherit',
				$wpdb->esc_like( 'image/' ) . '%'
			),
			ARRAY_A
		);
		$labels = array_keys( self::labels() );
		$summary = array_fill_keys( $labels, 0 );
		$summary['jpeg_png'] = 0;
		$summary['webp_avif'] = 0;
		return array(
			'schema' => self::SCHEMA,
			'status' => 'running',
			'last_id' => 0,
			'max_id' => absint( $counts['max_id'] ?? 0 ),
			'total' => absint( $counts['total'] ?? 0 ),
			'scanned' => 0,
			'started_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
			'summary' => $summary,
			'findings' => array_fill_keys( $labels, array() ),
		);
	}

	/**
	 * Scan at most BATCH_SIZE image IDs, without OFFSET pagination.
	 *
	 * @return array
	 */
	private function process_batch() {
		global $wpdb;
		$state = $this->state();
		if ( self::SCHEMA !== absint( $state['schema'] ?? 0 ) ) {
			$state = $this->new_state();
		}
		if ( 'completed' === ( $state['status'] ?? '' ) ) {
			return $state;
		}

		$cpu = TaxoCommerce_Images_CPU::decision( 'audit' );
		$state['cpu_last'] = $cpu;
		$state['cpu_paused'] = empty( $cpu['allowed'] );
		if ( empty( $cpu['allowed'] ) ) {
			$state['updated_at'] = current_time( 'mysql' );
			update_option( self::STATE_OPTION, $state, false );
			return $state;
		}

		$last_id = absint( $state['last_id'] ?? 0 );
		$max_id = absint( $state['max_id'] ?? 0 );
		$ids = $max_id > $last_id
			? (array) $wpdb->get_col(
				$wpdb->prepare(
					'SELECT ID FROM %i
					 WHERE post_type = %s AND post_status = %s
					 AND post_mime_type LIKE %s AND ID > %d AND ID <= %d
					 ORDER BY ID ASC LIMIT %d',
					$wpdb->posts,
					'attachment',
					'inherit',
					$wpdb->esc_like( 'image/' ) . '%',
					$last_id,
					$max_id,
					self::BATCH_SIZE
				)
			)
			: array();

		$settings = TaxoCommerce_Images_Actions::settings();
		$sizes = wp_get_registered_image_subsizes();
		foreach ( TaxoCommerce_Images_Thumbnails::disabled_sizes() as $disabled_size ) {
			unset( $sizes[ $disabled_size ] );
		}
		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( $id <= $last_id ) {
				continue;
			}
			$this->audit_attachment( $id, $state, $settings, $sizes );
			$state['last_id'] = $id;
			++$state['scanned'];
		}

		$state['cpu_paused'] = false;
		$state['updated_at'] = current_time( 'mysql' );
		if ( empty( $ids ) || count( $ids ) < self::BATCH_SIZE || absint( $state['last_id'] ) >= $max_id ) {
			$state['status'] = 'completed';
			$state['completed_at'] = current_time( 'mysql' );
		}
		update_option( self::STATE_OPTION, $state, false );
		return $state;
	}

	/**
	 * Audit native metadata and files. post_parent is only an association;
	 * it must never be interpreted as proof of an unused image.
	 *
	 * @param int   $id Image ID.
	 * @param array $state Mutable report.
	 * @param array $settings Optimization thresholds.
	 * @param array $sizes Registered sub-sizes, loaded once per batch.
	 * @return void
	 */
	private function audit_attachment( $id, &$state, $settings, $sizes ) {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || ! wp_attachment_is_image( $id ) ) {
			return;
		}

		$file = get_attached_file( $id );
		$file_ok = is_string( $file ) && '' !== $file && is_file( $file ) && is_readable( $file );
		$metadata = wp_get_attachment_metadata( $id );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$width = absint( $metadata['width'] ?? 0 );
		$height = absint( $metadata['height'] ?? 0 );
		$max_width = max( 320, absint( $settings['max_width'] ?? 2000 ) );
		$max_height = max( 320, absint( $settings['max_height'] ?? 2000 ) );
		$max_bytes = max( 100000, absint( $settings['max_bytes'] ?? 1572864 ) );

		if ( 0 === absint( $post->post_parent ) ) {
			$this->flag( $state, 'unattached', $id );
		}
		if ( '' === trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) {
			$this->flag( $state, 'missing_alt', $id );
		}
		if ( ! $file_ok ) {
			$this->flag( $state, 'missing_file', $id );
		}
		if ( 0 === $width || 0 === $height ) {
			$this->flag( $state, 'missing_metadata', $id );
		}
		if ( $width > $max_width || $height > $max_height ) {
			$this->flag( $state, 'oversized_dims', $id );
		}
		if ( $file_ok ) {
			$bytes = filesize( $file );
			if ( false !== $bytes && $bytes > $max_bytes ) {
				$this->flag( $state, 'oversized_bytes', $id );
			}
		}
		if ( $file_ok && $this->has_missing_expected_sizes( $file, $metadata, $sizes ) ) {
			$this->flag( $state, 'missing_sizes', $id );
		}
		$filename = $file_ok ? pathinfo( $file, PATHINFO_FILENAME ) : '';
		$title = trim( (string) $post->post_title );
		if ( '' !== $filename && sanitize_title( $filename ) === sanitize_title( $title ) ) {
			$this->flag( $state, 'generic_title', $id );
		}

		$mime = strtolower( (string) $post->post_mime_type );
		if ( in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			++$state['summary']['jpeg_png'];
		} elseif ( in_array( $mime, array( 'image/webp', 'image/avif' ), true ) ) {
			++$state['summary']['webp_avif'];
		}
	}

	/**
	 * Detect absent metadata entries and missing actual thumbnails.
	 *
	 * @param string $file Full image path.
	 * @param array $metadata Native WP attachment metadata.
	 * @param array $sizes Registered WordPress image sizes.
	 * @return bool
	 */
	private function has_missing_expected_sizes( $file, $metadata, $sizes ) {
		$width = absint( $metadata['width'] ?? 0 );
		$height = absint( $metadata['height'] ?? 0 );
		if ( ! $width || ! $height ) {
			return false;
		}
		$generated = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array();

		// A recorded file is only useful when it physically exists.
		foreach ( $generated as $entry ) {
			$name = isset( $entry['file'] ) && is_string( $entry['file'] ) ? $entry['file'] : '';
			if ( '' === $name || wp_basename( $name ) !== $name || ! is_file( dirname( $file ) . '/' . $name ) ) {
				return true;
			}
		}

		foreach ( $sizes as $name => $size ) {
			if ( isset( $generated[ $name ] ) ) {
				continue;
			}
			$target_width = absint( $size['width'] ?? 0 );
			$target_height = absint( $size['height'] ?? 0 );
			if ( ! $target_width && ! $target_height ) {
				continue;
			}
			if ( image_resize_dimensions( $width, $height, $target_width, $target_height, $size['crop'] ?? false ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Record total occurrences and a bounded sample of IDs.
	 *
	 * @param array $state Mutable audit state.
	 * @param string $key Issue key.
	 * @param int $id Attachment ID.
	 * @return void
	 */
	private function flag( &$state, $key, $id ) {
		$state['summary'][ $key ] = absint( $state['summary'][ $key ] ?? 0 ) + 1;
		if ( count( $state['findings'][ $key ] ?? array() ) < self::FINDING_CAP ) {
			$state['findings'][ $key ][] = absint( $id );
		}
	}

	/**
	 * Require administrator rights for site-wide scans.
	 *
	 * @return void
	 */
	private function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para auditar imágenes.', 'taxocommerce-images' ) );
		}
	}

	/**
	 * Return to the non-destructive report.
	 *
	 * @return void
	 */
	private function redirect() {
		wp_safe_redirect( admin_url( 'admin.php?page=taxocommerce-images&tab=audit' ) );
		exit;
	}
}
