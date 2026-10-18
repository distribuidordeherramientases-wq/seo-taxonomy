<?php
/**
 * Media-library audit for TaxoCommerce Images.
 *
 * @package TaxoCommerceImages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audits images in bounded admin batches without custom tables.
 */
final class TaxoCommerce_Images_Audit {

	const STATE_OPTION = 'taxocommerce_images_audit_state';
	const BATCH_SIZE   = 150;
	const FINDING_CAP  = 500;

	/**
	 * Register audit actions.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_post_taxocommerce_images_start_audit', array( $this, 'handle_start' ) );
		add_action( 'admin_post_taxocommerce_images_continue_audit', array( $this, 'handle_continue' ) );
		add_action( 'admin_post_taxocommerce_images_reset_audit', array( $this, 'handle_reset' ) );
	}

	/**
	 * Returns current state.
	 *
	 * @return array
	 */
	public function state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Start a new audit and process the first batch.
	 *
	 * @return void
	 */
	public function handle_start() {
		$this->guard();
		check_admin_referer( 'taxocommerce_images_start_audit', 'taxocommerce_images_nonce' );

		update_option( self::STATE_OPTION, $this->new_state(), false );
		$this->process_batch();
		$this->redirect();
	}

	/**
	 * Continue current audit.
	 *
	 * @return void
	 */
	public function handle_continue() {
		$this->guard();
		check_admin_referer( 'taxocommerce_images_continue_audit', 'taxocommerce_images_nonce' );

		$this->process_batch();
		$this->redirect();
	}

	/**
	 * Reset audit.
	 *
	 * @return void
	 */
	public function handle_reset() {
		$this->guard();
		check_admin_referer( 'taxocommerce_images_reset_audit', 'taxocommerce_images_nonce' );
		delete_option( self::STATE_OPTION );
		$this->redirect();
	}

	/**
	 * New empty state.
	 *
	 * @return array
	 */
	private function new_state() {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);

		return array(
			'status'     => 'running',
			'page'       => 1,
			'total'      => absint( $query->found_posts ),
			'scanned'    => 0,
			'started_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
			'summary'    => array(
				'unattached'       => 0,
				'missing_alt'      => 0,
				'missing_file'     => 0,
				'oversized_dims'   => 0,
				'oversized_bytes'  => 0,
				'missing_sizes'    => 0,
				'generic_title'    => 0,
				'jpeg_png'         => 0,
				'webp_avif'        => 0,
			),
			'findings'   => array(
				'unattached'      => array(),
				'missing_alt'     => array(),
				'missing_file'    => array(),
				'oversized_dims'  => array(),
				'oversized_bytes' => array(),
				'missing_sizes'   => array(),
				'generic_title'   => array(),
			),
		);
	}

	/**
	 * Process one bounded page of attachments.
	 *
	 * @return void
	 */
	private function process_batch() {
		$state = $this->state();
		if ( empty( $state ) || 'completed' === ( $state['status'] ?? '' ) ) {
			$state = $this->new_state();
		}

		$page  = max( 1, absint( $state['page'] ?? 1 ) );
		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post_mime_type'         => 'image',
				'posts_per_page'         => self::BATCH_SIZE,
				'paged'                  => $page,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => false,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);

		$settings = TaxoCommerce_Images_Actions::settings();
		foreach ( (array) $query->posts as $attachment_id ) {
			$this->audit_attachment( absint( $attachment_id ), $state, $settings );
		}

		$state['scanned']    = absint( $state['scanned'] ?? 0 ) + count( $query->posts );
		$state['total']      = absint( $query->found_posts );
		$state['updated_at'] = current_time( 'mysql' );

		if ( $page >= max( 1, absint( $query->max_num_pages ) ) || empty( $query->posts ) ) {
			$state['status']       = 'completed';
			$state['completed_at'] = current_time( 'mysql' );
		} else {
			$state['status'] = 'running';
			$state['page']   = $page + 1;
		}

		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Audit one attachment.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $state         Audit state by reference.
	 * @param array $settings      Settings.
	 * @return void
	 */
	private function audit_attachment( $attachment_id, &$state, $settings ) {
		$post = get_post( $attachment_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$file      = get_attached_file( $attachment_id );
		$file_ok   = $file && file_exists( $file );
		$metadata  = wp_get_attachment_metadata( $attachment_id );
		$metadata  = is_array( $metadata ) ? $metadata : array();
		$alt       = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		$width     = absint( $metadata['width'] ?? 0 );
		$height    = absint( $metadata['height'] ?? 0 );
		$max_width = max( 320, absint( $settings['max_width'] ?? 2000 ) );
		$max_height= max( 320, absint( $settings['max_height'] ?? 2000 ) );
		$max_bytes = max( 100000, absint( $settings['max_bytes'] ?? 1572864 ) );
		$bytes     = $file_ok ? (int) filesize( $file ) : 0;
		$filename  = $file ? pathinfo( $file, PATHINFO_FILENAME ) : '';
		$title     = trim( (string) $post->post_title );
		$mime      = strtolower( (string) $post->post_mime_type );

		if ( 0 === absint( $post->post_parent ) ) {
			$this->flag( $state, 'unattached', $attachment_id );
		}
		if ( '' === $alt ) {
			$this->flag( $state, 'missing_alt', $attachment_id );
		}
		if ( ! $file_ok ) {
			$this->flag( $state, 'missing_file', $attachment_id );
		}
		if ( $width > $max_width || $height > $max_height ) {
			$this->flag( $state, 'oversized_dims', $attachment_id );
		}
		if ( $bytes > $max_bytes ) {
			$this->flag( $state, 'oversized_bytes', $attachment_id );
		}
		if ( $this->has_missing_expected_sizes( $metadata ) ) {
			$this->flag( $state, 'missing_sizes', $attachment_id );
		}
		if ( '' !== $filename && sanitize_title( $filename ) === sanitize_title( $title ) ) {
			$this->flag( $state, 'generic_title', $attachment_id );
		}

		if ( in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			$state['summary']['jpeg_png'] = absint( $state['summary']['jpeg_png'] ?? 0 ) + 1;
		} elseif ( in_array( $mime, array( 'image/webp', 'image/avif' ), true ) ) {
			$state['summary']['webp_avif'] = absint( $state['summary']['webp_avif'] ?? 0 ) + 1;
		}
	}

	/**
	 * Add one finding and increment its counter.
	 *
	 * @param array  $state Audit state.
	 * @param string $key   Finding key.
	 * @param int    $id    Attachment ID.
	 * @return void
	 */
	private function flag( &$state, $key, $id ) {
		$state['summary'][ $key ] = absint( $state['summary'][ $key ] ?? 0 ) + 1;
		if ( count( $state['findings'][ $key ] ?? array() ) < self::FINDING_CAP ) {
			$state['findings'][ $key ][] = absint( $id );
		}
	}

	/**
	 * Check whether registered image sizes that could exist are missing.
	 *
	 * @param array $metadata Attachment metadata.
	 * @return bool
	 */
	private function has_missing_expected_sizes( $metadata ) {
		$width  = absint( $metadata['width'] ?? 0 );
		$height = absint( $metadata['height'] ?? 0 );
		if ( ! $width || ! $height ) {
			return false;
		}

		$created = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array();
		foreach ( wp_get_registered_image_subsizes() as $name => $size ) {
			if ( isset( $created[ $name ] ) ) {
				continue;
			}

			$target_width  = absint( $size['width'] ?? 0 );
			$target_height = absint( $size['height'] ?? 0 );
			$crop          = $size['crop'] ?? false;

			if ( ! $target_width && ! $target_height ) {
				continue;
			}

			if ( image_resize_dimensions( $width, $height, $target_width, $target_height, $crop ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Capability guard.
	 *
	 * @return void
	 */
	private function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para ejecutar la auditoría de imágenes.', 'taxocommerce-images' ) );
		}
	}

	/**
	 * Redirect back to audit screen.
	 *
	 * @return void
	 */
	private function redirect() {
		wp_safe_redirect( admin_url( 'admin.php?page=taxocommerce-images&tab=audit' ) );
		exit;
	}
}
