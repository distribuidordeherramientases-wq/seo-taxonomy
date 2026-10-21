<?php
/**
 * Review-only UI adapter for the original comprehensive attachment-reference
 * analyzer. It never deletes media and does not inspect supplier databases.
 *
 * @package TaxoCommerceImages
 */

defined( 'ABSPATH' ) || exit;

/**
 * Run one bounded slice of the original deep reference audit on demand.
 */
final class TaxoCommerce_Images_References {

	const PAGE_SIZE = 20;

	/**
	 * Register authenticated report generation.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_post_taxocommerce_images_check_references', array( __CLASS__, 'scan' ) );
	}

	/**
	 * Persist a single page of evidence after explicit administrator action.
	 *
	 * @return void
	 */
	public static function scan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para revisar referencias.', 'taxocommerce-images' ) );
		}
		check_admin_referer( 'taxocommerce_images_check_references', 'taxocommerce_images_nonce' );

		$page = isset( $_POST['media_page'] ) && is_scalar( $_POST['media_page'] )
			? max( 1, min( 100000, absint( $_POST['media_page'] ) ) )
			: 1;

		$media = TaxoCommerce_Images_Inventory::media( $page, self::PAGE_SIZE );
		$attachments = array_values( (array) $media->posts );
		$usage = tci_refs_build_usage_report( $attachments );
		$uploads = wp_get_upload_dir();
		$root = isset( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
		$key = 'taxocommerce_images_reference_hashes_' . get_current_user_id();
		$hashes = 1 === $page ? array() : get_transient( $key );
		$hashes = is_array( $hashes ) ? $hashes : array();

		$rows = array();
		foreach ( $attachments as $attachment ) {
			$id = absint( $attachment->ID );
			$references = absint( $usage[ $id ] ?? 0 );
			$row = array(
				'id' => $id,
				'title' => sanitize_text_field( (string) $attachment->post_title ),
				'known_references' => $references,
				'bytes' => max( 0, (int) tci_refs_get_attachment_size( $id ) ),
				'duplicate_of' => 0,
			);

			$original = get_attached_file( $id );
			$real = is_string( $original ) && '' !== $original ? realpath( $original ) : false;
			if ( $root && $real && 0 === strpos( $real, trailingslashit( $root ) ) && is_readable( $real ) ) {
				$size = filesize( $real );
				// Never hash huge assets inside an admin HTTP request.
				if ( false !== $size && $size > 0 && $size <= 8388608 ) {
					$hash = hash_file( 'sha256', $real );
					if ( is_string( $hash ) && '' !== $hash ) {
						$signature = $hash . ':' . $size;
						if ( isset( $hashes[ $signature ] ) && absint( $hashes[ $signature ] ) !== $id ) {
							$row['duplicate_of'] = absint( $hashes[ $signature ] );
						} else {
							$hashes[ $signature ] = $id;
						}
					}
				}
			}
			$rows[] = $row;
		}

		set_transient( $key, $hashes, 6 * HOUR_IN_SECONDS );
		set_transient(
			'taxocommerce_images_reference_report_' . get_current_user_id(),
			array(
				'page' => $page,
				'pages' => absint( $media->max_num_pages ),
				'checked' => count( $rows ),
				'items' => $rows,
				'created' => current_time( 'mysql' ),
			),
			HOUR_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'admin.php?page=taxocommerce-images&tab=references' ) );
		exit;
	}

	/**
	 * Current administrator's last report.
	 *
	 * @return array
	 */
	public static function report() {
		$report = get_transient( 'taxocommerce_images_reference_report_' . get_current_user_id() );
		return is_array( $report ) ? $report : array();
	}
}
