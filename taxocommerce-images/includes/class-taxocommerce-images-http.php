<?php
/**
 * Bounded public image availability checks using the WordPress HTTP API.
 *
 * No GitHub runner, provider catalog or TaxoCommerce SEO dependency.
 *
 * @package TaxoCommerceImages
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read-only image HTTP audit for a small published-content sample.
 */
final class TaxoCommerce_Images_HTTP {

	const POSTS_PER_BATCH = 2;
	const URLS_PER_POST = 4;

	/**
	 * Register manual, authenticated inspection.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_post_taxocommerce_images_http_scan', array( __CLASS__, 'scan' ) );
	}

	/**
	 * Extract published IMG src references using WordPress HTML parser.
	 * The featured image is also included when WordPress owns its Media ID.
	 *
	 * @param int $post_id Published content.
	 * @return string[]
	 */
	private static function image_urls( $post_id ) {
		$urls = array();
		$featured = get_post_thumbnail_id( $post_id );
		if ( $featured ) {
			$url = wp_get_attachment_url( $featured );
			if ( is_string( $url ) && '' !== $url ) {
				$urls[] = $url;
			}
		}

		$content = (string) get_post_field( 'post_content', $post_id );
		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$processor = new WP_HTML_Tag_Processor( $content );
			while ( $processor->next_tag( 'img' ) && count( $urls ) < self::URLS_PER_POST ) {
				$url = $processor->get_attribute( 'src' );
				if ( is_string( $url ) && '' !== trim( $url ) ) {
					$urls[] = $url;
				}
			}
		}
		return array_slice( array_values( array_unique( $urls ) ), 0, self::URLS_PER_POST );
	}

	/**
	 * Check one URL with SSRF protections and strict network limits.
	 *
	 * @param string $url Public image URL.
	 * @return array
	 */
	private static function check( $url ) {
		$url = esc_url_raw( $url );
		if ( ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ||
			! wp_http_validate_url( $url ) ) {
			return array( 'status' => 0, 'description' => 'URL insegura o no válida' );
		}

		$args = array(
			'timeout' => 3,
			'redirection' => 2,
			'reject_unsafe_urls' => true,
			'limit_response_size' => 2048,
		);
		$response = wp_safe_remote_head( $url, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 0,
				'description' => sanitize_text_field( $response->get_error_message() ),
			);
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );
		if ( 405 === $code ) {
			$args['headers'] = array( 'Range' => 'bytes=0-0' );
			$response = wp_safe_remote_get( $url, $args );
			if ( is_wp_error( $response ) ) {
				return array( 'status' => 0, 'description' => sanitize_text_field( $response->get_error_message() ) );
			}
			$code = absint( wp_remote_retrieve_response_code( $response ) );
		}
		return array(
			'status' => $code,
			'description' => $code >= 200 && $code < 400 ? 'Disponible' : 'Revisar HTTP',
		);
	}

	/**
	 * Inspect no more than two published entries per administrator request.
	 *
	 * @return void
	 */
	public static function scan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para comprobar imágenes.', 'taxocommerce-images' ) );
		}
		check_admin_referer( 'taxocommerce_images_http_scan', 'taxocommerce_images_nonce' );

		$page = isset( $_POST['scan_page'] ) && is_scalar( $_POST['scan_page'] )
			? max( 1, min( 100000, absint( $_POST['scan_page'] ) ) )
			: 1;
		$types = array( 'post', 'page' );
		if ( post_type_exists( 'product' ) ) {
			$types[] = 'product';
		}
		$posts = new WP_Query(
			array(
				'post_type' => $types,
				'post_status' => 'publish',
				'posts_per_page' => self::POSTS_PER_BATCH,
				'paged' => $page,
				'fields' => 'ids',
				'orderby' => 'ID',
				'order' => 'ASC',
				'update_post_term_cache' => false,
			)
		);
		$rows = array();
		foreach ( (array) $posts->posts as $id ) {
			foreach ( self::image_urls( absint( $id ) ) as $url ) {
				$result = self::check( $url );
				$rows[] = array(
					'post_id' => absint( $id ),
					'url' => esc_url_raw( $url ),
					'status' => $result['status'],
					'description' => sanitize_text_field( $result['description'] ),
				);
			}
		}

		set_transient(
			'taxocommerce_images_http_report_' . get_current_user_id(),
			array(
				'page' => $page,
				'pages' => absint( $posts->max_num_pages ),
				'items' => $rows,
				'created' => current_time( 'mysql' ),
			),
			HOUR_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'admin.php?page=taxocommerce-images&tab=http' ) );
		exit;
	}

	/**
	 * Most recent page of HTTP findings.
	 *
	 * @return array
	 */
	public static function report() {
		$result = get_transient( 'taxocommerce_images_http_report_' . get_current_user_id() );
		return is_array( $result ) ? $result : array();
	}
}
