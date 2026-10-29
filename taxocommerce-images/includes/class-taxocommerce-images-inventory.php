<?php
/**
 * Generic inventory and attachment-use checks.
 *
 * No dependency on TaxoCommerce SEO, Core or WooCommerce.
 *
 * @package TaxoCommerceImages
 */

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Images_Inventory {

	/**
	 * Count native Media formats using the registered WordPress attachment MIME.
	 *
	 * @return array<string,int>
	 */
	public static function formats() {
		global $wpdb;

		$counts = array(
			'jpeg' => 0,
			'png' => 0,
			'webp' => 0,
			'avif' => 0,
			'other' => 0,
			'total' => 0,
			'convertible' => 0,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One grouped MIME count is cheaper than loading each attachment through WP_Query; read fresh on dashboard request.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_mime_type AS mime, COUNT(*) AS qty
				 FROM {$wpdb->posts}
				 WHERE post_type = %s AND post_status = %s AND post_mime_type LIKE %s
				 GROUP BY post_mime_type",
				'attachment',
				'inherit',
				'image/%'
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$mime = strtolower( (string) $row['mime'] );
			$key = array(
				'image/jpeg' => 'jpeg',
				'image/png' => 'png',
				'image/webp' => 'webp',
				'image/avif' => 'avif',
			);
			$format = isset( $key[ $mime ] ) ? $key[ $mime ] : 'other';
			$qty = absint( $row['qty'] );
			$counts[ $format ] += $qty;
			$counts['total'] += $qty;
		}
		$counts['convertible'] = $counts['jpeg'] + $counts['png'];
		return $counts;
	}

	/**
	 * Get a bounded Media page.
	 *
	 * @param int $page Page number.
	 * @param int $limit Per-page limit.
	 * @return WP_Query
	 */
	public static function media( $page = 1, $limit = 40 ) {
		return new WP_Query(
			array(
				'post_type' => 'attachment',
				'post_status' => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => min( 100, max( 1, absint( $limit ) ) ),
				'paged' => max( 1, absint( $page ) ),
				'orderby' => 'ID',
				'order' => 'DESC',
				'update_post_term_cache' => false,
			)
		);
	}

	/**
	 * Conservative use check. Returns true for any possible reference to avoid
	 * declaring in-use assets safe to delete. A false result means "candidate
	 * for manual review", NOT proof of being unused.
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	public static function is_referenced( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id || ! wp_attachment_is_image( $id ) ) {
			return true;
		}

		$parent = absint( get_post_field( 'post_parent', $id ) );
		// A parent alone is not proof of use, but protect it pending review.
		if ( $parent && get_post_status( $parent ) && 'trash' !== get_post_status( $parent ) ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Check native product/gallery IDs across all posts using exact and delimited values; stale cache would mislabel media unused.
		$native_meta = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_id FROM {$wpdb->postmeta}
				 WHERE meta_key IN ('_thumbnail_id','_product_image_gallery','_product_image_id')
				   AND (meta_value = %s OR meta_value REGEXP %s)
				 LIMIT 1",
				(string) $id,
				'(^|,)[[:space:]]*' . $id . '[[:space:]]*(,|$)'
			)
		);
		if ( $native_meta ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Resolve taxonomy image references across all terms; cache cannot safely track every external meta update.
		$term_meta = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_id FROM {$wpdb->termmeta}
				 WHERE meta_key IN ('thumbnail_id','image_id','featured_image','logo')
				   AND meta_value = %s LIMIT 1",
				(string) $id
			)
		);
		if ( $term_meta ) {
			return true;
		}

		// Content-based images (Gutenberg, legacy IMG markup and raw Media links).
		$url = wp_get_attachment_url( $id );
		$filename = $url ? wp_basename( wp_parse_url( $url, PHP_URL_PATH ) ) : '';
		$needle = '%' . $wpdb->esc_like( 'wp-image-' . $id ) . '%';
		$secondary = $filename ? '%' . $wpdb->esc_like( $filename ) . '%' : $needle;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conservative search through live Gutenberg/HTML content for a specific attachment; cached negatives would be unsafe.
		$content_ref = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type <> 'attachment' AND post_status NOT IN ('trash','auto-draft')
				   AND (post_content LIKE %s OR post_content LIKE %s
				     OR post_excerpt LIKE %s OR post_content_filtered LIKE %s)
				 LIMIT 1",
				$needle,
				$secondary,
				$secondary,
				$secondary
			)
		);
		if ( $content_ref ) {
			return true;
		}

		// Customizer logo, site icon, widgets and most serialized theme settings.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Check current serialized theme and widget options; caching a negative result could permit a false unused classification.
		$opt = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_id FROM {$wpdb->options}
				 WHERE (option_name = 'site_icon' OR option_name LIKE %s)
				   AND (option_value = %s OR option_value LIKE %s)
				 LIMIT 1",
				$wpdb->esc_like( 'theme_mods_' ) . '%',
				(string) $id,
				'%' . $wpdb->esc_like( 'i:' . $id . ';' ) . '%'
			)
		);
		if ( $opt ) {
			return true;
		}
		// Integrations may explicitly protect externally referenced media.
		return (bool) apply_filters( 'taxocommerce_images_is_attachment_used', false, $id );
	}

}
