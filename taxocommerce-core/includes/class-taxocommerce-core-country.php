<?php
/**
 * Shared market identity for TaxoCommerce content and SEO relations.
 *
 * Uses existing WordPress postmeta/termmeta; does not alter core WP tables.
 *
 * @package TaxoCommerceCore
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Core_Country {
	const META = '_seo_pais_origen';
	const FIELD = 'tc_country_code';
	const NONCE = 'tc_country_nonce';
	const NONCE_ACTION = 'tc_country_save';

	public static function countries() {
		return array(
			'ES' => __( 'España', 'taxocommerce-core' ),
			'US' => __( 'Estados Unidos', 'taxocommerce-core' ),
			'FR' => __( 'Francia', 'taxocommerce-core' ),
			'DE' => __( 'Alemania', 'taxocommerce-core' ),
			'NL' => __( 'Países Bajos', 'taxocommerce-core' ),
		);
	}

	public static function valid( $country ) {
		return isset( self::countries()[ strtoupper( trim( (string) $country ) ) ] );
	}

	public static function normalize( $country ) {
		$country = strtoupper( trim( (string) $country ) );
		return self::valid( $country ) ? $country : '';
	}

	public static function locale_country( $locale ) {
		$locale = strtolower( str_replace( '_', '-', trim( (string) $locale ) ) );
		$map = array( 'es' => 'ES', 'en-us' => 'US', 'fr' => 'FR', 'de' => 'DE', 'nl' => 'NL' );
		foreach ( $map as $prefix => $country ) {
			if ( $locale === $prefix || 0 === strpos( $locale, $prefix . '-' ) ) {
				return $country;
			}
		}
		return '';
	}

	private static function slug_country( $slug ) {
		$slug = strtolower( (string) $slug );
		return preg_match( '/^vevor-(us|fr|de|nl)(?:-|$)/', $slug, $matches ) ? strtoupper( $matches[1] ) : '';
	}

	public static function post( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) { return 'ES'; }
		$country = self::normalize( get_post_meta( $post_id, self::META, true ) );
		if ( $country ) { return $country; }
		$country = self::normalize( get_post_meta( $post_id, 'dht_pilot_market', true ) );
		if ( $country ) { return $country; }
		$country = self::locale_country( get_post_meta( $post_id, '_seo_locale', true ) );
		if ( $country ) { return $country; }
		$post = get_post( $post_id );
		if ( $post ) {
			$country = self::slug_country( $post->post_name );
			if ( $country ) { return $country; }
		}
		// Legacy pages and posts belong to the original Spanish site.
		return 'ES';
	}

	public static function term( $term_id ) {
		$term_id = absint( $term_id );
		if ( ! $term_id ) { return 'ES'; }
		$ids = array_merge( array( $term_id ), (array) get_ancestors( $term_id, 'product_cat', 'taxonomy' ) );
		foreach ( $ids as $id ) {
			$country = self::normalize( get_term_meta( $id, self::META, true ) );
			if ( $country ) { return $country; }
			$country = self::locale_country( get_term_meta( $id, '_seo_locale', true ) );
			if ( $country ) { return $country; }
			$term = get_term( $id, 'product_cat' );
			if ( $term instanceof WP_Term ) {
				$country = self::slug_country( $term->slug );
				if ( $country ) { return $country; }
			}
		}
		return 'ES';
	}

	public static function object( $type, $id ) {
		if ( in_array( $type, array( 'product_cat', 'category' ), true ) ) {
			return self::term( $id );
		}
		return self::post( $id );
	}

	public static function relation( $row ) {
		$source_type = (string) ( $row['source_type'] ?? '' );
		$target_type = (string) ( $row['target_type'] ?? '' );
		$source_id = absint( $row['source_id'] ?? 0 );
		$target_id = absint( $row['target_id'] ?? 0 );
		if ( ! $source_id || ! $target_id ) { return ''; }
		$source_country = self::object( $source_type, $source_id );
		$target_country = self::object( $target_type, $target_id );
		$declared = self::normalize( $row['country_code'] ?? '' );
		// A link between two different markets is never a valid hierarchy edge.
		if ( $source_country !== $target_country ) { return ''; }
		return $declared && $declared !== $source_country ? '' : $source_country;
	}

	public static function options( $current = '' ) {
		$current = self::normalize( $current );
		foreach ( self::countries() as $code => $label ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $current, $code, false ) . '>' .
				esc_html( $label . ' (' . $code . ')' ) . '</option>';
		}
	}

	public static function field( $country, $name = self::FIELD ) {
		echo '<div class="tc-field"><label class="tc-field-label" for="tc-edit-country">' .
			esc_html__( 'País', 'taxocommerce-core' ) . '</label>';
		echo '<select class="tc-input" id="tc-edit-country" name="' . esc_attr( $name ) . '">';
		self::options( $country );
		echo '</select><p class="tc-field-help">' .
			esc_html__( 'Define el mercado de este contenido. No cambia la URL, el idioma ni sus relaciones SEO.', 'taxocommerce-core' ) .
			'</p></div>';
	}

	public static function label( $country ) {
		$country = self::normalize( $country );
		$all = self::countries();
		return $country ? $all[ $country ] . ' (' . $country . ')' : __( 'Sin asignar', 'taxocommerce-core' );
	}

	public static function read_posted( $name = self::FIELD ) {
		if ( ! isset( $_POST[ $name ] ) || ! is_string( $_POST[ $name ] ) ) { return null; }
		$value = strtoupper( trim( sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) ) );
		return self::valid( $value )
			? $value
			: new WP_Error( 'tc_country_invalid', __( 'País no válido.', 'taxocommerce-core' ) );
	}

	public static function set_post( $id, $country ) {
		$id = absint( $id );
		if ( ! current_user_can( 'edit_post', $id ) || ! self::valid( $country ) ) {
			return new WP_Error( 'tc_country_permission', __( 'No se puede actualizar el país de este contenido.', 'taxocommerce-core' ) );
		}
		if ( self::normalize( get_post_meta( $id, self::META, true ) ) !== $country
			&& false === update_post_meta( $id, self::META, $country ) ) {
			return new WP_Error( 'tc_country_save', __( 'No se pudo guardar el país.', 'taxocommerce-core' ) );
		}
		// The role form can change the country and SEO role in one submission.
		// Synchronise the country of the existing role node after saving postmeta.
		if ( 'page' === get_post_type( $id ) && class_exists( 'TaxoCommerce_Core_Hierarchy_Classic', false ) ) {
			TaxoCommerce_Core_Hierarchy_Classic::sync_role_country( $id );
		}
		return true;
	}

	public static function set_term( $id, $country ) {
		$id = absint( $id );
		$taxonomy = get_taxonomy( 'product_cat' );
		if ( ! $taxonomy || ! current_user_can( $taxonomy->cap->edit_terms ) || ! self::valid( $country ) ) {
			return new WP_Error( 'tc_country_permission', __( 'No se puede actualizar el país de esta categoría.', 'taxocommerce-core' ) );
		}
		if ( self::normalize( get_term_meta( $id, self::META, true ) ) === $country ) { return true; }
		return false !== update_term_meta( $id, self::META, $country )
			? true : new WP_Error( 'tc_country_save', __( 'No se pudo guardar el país.', 'taxocommerce-core' ) );
	}

	public static function register_hooks() {
		foreach ( array( 'page', 'post' ) as $type ) {
			add_action( 'add_meta_boxes_' . $type, array( __CLASS__, 'register_box' ) );
			add_action( 'save_post_' . $type, array( __CLASS__, 'save_native_post' ) );
		}
		add_action( 'product_cat_add_form_fields', array( __CLASS__, 'add_category_field' ) );
		add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'edit_category_field' ) );
		add_action( 'created_product_cat', array( __CLASS__, 'save_native_category' ) );
		add_action( 'edited_product_cat', array( __CLASS__, 'save_native_category' ) );
	}

	public static function register_box( $post ) {
		add_meta_box( 'tc-core-country', __( 'País', 'taxocommerce-core' ), array( __CLASS__, 'native_post_box' ), $post->post_type, 'side', 'default' );
	}

	public static function native_post_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE );
		self::field( self::post( $post->ID ) );
	}

	public static function save_native_post( $id ) {
		if ( wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) || ! isset( $_POST[ self::NONCE ] )
			|| ! is_string( $_POST[ self::NONCE ] ) ) { return; }
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE_ACTION ) ) { return; }
		$country = self::read_posted();
		if ( is_string( $country ) ) { self::set_post( $id, $country ); }
	}

	public static function add_category_field() {
		echo '<div class="form-field"><label for="tc-edit-country">' . esc_html__( 'País', 'taxocommerce-core' ) . '</label>';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE );
		echo '<select name="' . esc_attr( self::FIELD ) . '" id="tc-edit-country">';
		self::options( 'ES' );
		echo '</select></div>';
	}

	public static function edit_category_field( $term ) {
		echo '<tr class="form-field"><th scope="row"><label for="tc-edit-country">' . esc_html__( 'País', 'taxocommerce-core' ) .
			'</label></th><td>';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE );
		echo '<select name="' . esc_attr( self::FIELD ) . '" id="tc-edit-country">';
		self::options( self::term( $term->term_id ) );
		echo '</select></td></tr>';
	}

	public static function save_native_category( $term_id ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! is_string( $_POST[ self::NONCE ] ) ) { return; }
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE_ACTION ) ) { return; }
		$country = self::read_posted();
		if ( is_string( $country ) ) { self::set_term( $term_id, $country ); }
	}
}
