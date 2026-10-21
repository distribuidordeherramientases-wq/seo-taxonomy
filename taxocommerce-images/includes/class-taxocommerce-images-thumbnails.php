<?php
/**
 * Reuse WordPress native thumbnail registration and let administrators omit
 * selected sizes for future uploads (never deletes existing derivatives).
 *
 * @package TaxoCommerceImages
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thumbnail policies.
 */
final class TaxoCommerce_Images_Thumbnails {

	const OPTION = 'taxocommerce_images_disabled_thumbnail_sizes';

	/**
	 * Register hooks without touching legacy plugins.
	 *
	 * @return void
	 */
	public static function boot() {
		add_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'filter_sizes' ), 20, 3 );
		add_action( 'admin_post_taxocommerce_images_save_thumbnail_policy', array( __CLASS__, 'save' ) );
	}

	/**
	 * Retrieve a sanitized allowlist of disabled sizes.
	 *
	 * @return string[]
	 */
	public static function disabled_sizes() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $stored ) ) ) );
	}

	/**
	 * Remove selected intermediate sizes from future Media generation.
	 *
	 * @param array $sizes Native registered size arguments.
	 * @param array $metadata Attachment metadata.
	 * @param int $attachment_id WordPress Media attachment.
	 * @return array
	 */
	public static function filter_sizes( $sizes, $metadata, $attachment_id ) {
		if ( ! is_array( $sizes ) ) {
			return $sizes;
		}
		foreach ( self::disabled_sizes() as $name ) {
			unset( $sizes[ $name ] );
		}
		return $sizes;
	}

	/**
	 * Persist only registered size keys, protecting external plugins.
	 *
	 * @return void
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para modificar las miniaturas.', 'taxocommerce-images' ) );
		}
		check_admin_referer( 'taxocommerce_images_save_thumbnail_policy', 'taxocommerce_images_nonce' );

		$requested = isset( $_POST['disabled_sizes'] ) && is_array( $_POST['disabled_sizes'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['disabled_sizes'] ) )
			: array();
		$registered = array_keys( wp_get_registered_image_subsizes() );
		$allowed = array_values( array_intersect( $requested, $registered ) );
		update_option( self::OPTION, $allowed, false );
		wp_safe_redirect( admin_url( 'admin.php?page=taxocommerce-images&tab=thumbnails&saved=1' ) );
		exit;
	}
}
