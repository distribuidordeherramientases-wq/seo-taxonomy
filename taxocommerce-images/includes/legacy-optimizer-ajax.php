<?php
/**
 * Small, authenticated AJAX adapter for the proven local optimizer engine.
 *
 * @package TaxoCommerceImages
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetch the next bounded group of native Media attachment IDs through WP_Query.
 * This does not require any legacy taxonomy, provider or catalog tables.
 *
 * @param int $after_id Cursor.
 * @param int $limit Page size (capped at 2).
 * @return int[]
 */
function taxocommerce_images_optimizer_next_ids( $after_id, $limit = 2 ) {
	$after_id = absint( $after_id );
	$limit    = max( 1, min( 2, absint( $limit ) ) );

	$cursor_filter = static function ( $where, $query ) use ( $after_id ) {
		global $wpdb;
		if ( $query->get( 'tci_optimizer_cursor' ) ) {
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after_id );
		}
		return $where;
	};

	add_filter( 'posts_where', $cursor_filter, 10, 2 );
	try {
		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post_mime_type'         => 'image',
				'fields'                 => 'ids',
				'posts_per_page'         => $limit,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'tci_optimizer_cursor'   => 1,
			)
		);
	} finally {
		remove_filter( 'posts_where', $cursor_filter, 10 );
	}

	return array_map( 'absint', (array) $query->posts );
}

/**
 * Process a small, explicit batch only after an administrator starts the job.
 *
 * @return void
 */
function taxocommerce_images_optimizer_ajax_batch() {
	if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'upload_files' ) ) {
		wp_send_json_error( array( 'message' => __( 'No tienes permisos para optimizar las imágenes.', 'taxocommerce-images' ) ), 403 );
	}
	check_ajax_referer( 'tci_optimizer_run', 'nonce' );

	$after_id = isset( $_POST['after_id'] ) && is_scalar( $_POST['after_id'] ) ? absint( $_POST['after_id'] ) : 0;
	$ids      = taxocommerce_images_optimizer_next_ids( $after_id, 2 );
	$result   = array(
		'processed' => 0,
		'next_after_id' => $after_id,
		'optimized_files' => 0,
		'bytes_saved' => 0,
		'errors' => array(),
		'done' => false,
	);

	foreach ( $ids as $id ) {
		$result['next_after_id'] = max( $result['next_after_id'], $id );
		try {
			$item = taxocommerce_images_optimizer_process_attachment( $id );
			++$result['processed'];
			$result['optimized_files'] += absint( $item['optimized'] ?? 0 );
			$result['bytes_saved'] += absint( $item['saved'] ?? 0 );
			if ( ! empty( $item['errors'] ) ) {
				$result['errors'] = array_merge( $result['errors'], array_slice( (array) $item['errors'], 0, 3 ) );
			}
		} catch ( Throwable $error ) {
			++$result['processed'];
			$result['errors'][] = sprintf( 'Attachment %d: %s', $id, sanitize_text_field( $error->getMessage() ) );
		}
	}
	$result['done']   = count( $ids ) < 2;
	$result['errors'] = array_slice( array_values( array_unique( $result['errors'] ) ), 0, 6 );
	wp_send_json_success( $result );
}
add_action( 'wp_ajax_tci_optimizer_batch', 'taxocommerce_images_optimizer_ajax_batch' );
