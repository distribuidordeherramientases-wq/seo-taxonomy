<?php
/**
 * Discover native scalar metadata and WooCommerce product attributes.
 *
 * Metadata is read only from WordPress core tables. Protected keys and
 * complex values are intentionally outside the Lite CSV contract.
 *
 * @package TaxoCommerceImportExportLite
 */
namespace TaxoCommerce\ImportExportLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NativeFields {
	const META_PREFIX = 'meta:';
	const ATTR_PREFIX = 'attr:';
	const MAX_KEYS = 200;
	private static $fields_cache = array();
	private static $attributes_cache = null;

	/** Is this a supported scalar, public metadata key? */
	private static function valid_meta_key( $key, $object ) {
		if ( ! is_string( $key ) || ! preg_match( '/^[a-zA-Z][a-zA-Z0-9_\-]{0,100}$/D', $key ) ) {
			return false;
		}
		if ( 0 === strpos( $key, '_' ) || 0 === strpos( strtolower( $key ), 'wp_' ) ) {
			return false;
		}
		return ! is_protected_meta( $key, $object );
	}

	/** Registered WP fields plus keys actually stored for the selected content type. */
	private static function meta_keys( $type ) {
		$term_types = array( 'product_cat', 'product_tag', 'category', 'post_tag' );
		$term      = in_array( $type, $term_types, true );
		$object    = $term ? 'term' : 'post';
		$cache_key = 'meta_keys_' . $type;
		if ( function_exists( 'wp_cache_get' ) ) {
			$cached = wp_cache_get( $cache_key, 'tciel_native_fields' );
			if ( is_array( $cached ) ) return $cached;
		}
		$keys      = array();
		foreach ( array( '', $type ) as $subtype ) {
			foreach ( (array) get_registered_meta_keys( $object, $subtype ) as $key => $args ) {
				if ( ! self::valid_meta_key( $key, $object ) || ( isset( $args['single'] ) && ! $args['single'] ) ) {
					continue;
				}
				if ( ! in_array( isset( $args['type'] ) ? $args['type'] : 'string', array( 'string', 'integer', 'number', 'boolean' ), true ) ) {
					continue;
				}
				$keys[ $key ] = isset( $args['label'] ) && is_string( $args['label'] ) && '' !== $args['label'] ? $args['label'] : $key;
			}
		}
		// Query only standard WP tables. The subtype is a hardcoded, whitelisted content type.
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->postmeta, $wpdb->posts, $wpdb->termmeta, $wpdb->term_taxonomy ) ) {
			if ( $term ) {
				$sql = "SELECT DISTINCT m.meta_key FROM {$wpdb->termmeta} AS m INNER JOIN {$wpdb->term_taxonomy} AS t ON t.term_id = m.term_id WHERE t.taxonomy = %s ORDER BY m.meta_key LIMIT " . self::MAX_KEYS;
			} else {
				$sql = "SELECT DISTINCT m.meta_key FROM {$wpdb->postmeta} AS m INNER JOIN {$wpdb->posts} AS p ON p.ID = m.post_id WHERE p.post_type = %s ORDER BY m.meta_key LIMIT " . self::MAX_KEYS;
			}
			$stored = $wpdb->get_col( $wpdb->prepare( $sql, $type ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL table names are the built-in WPDB table properties and type is prepared.
			foreach ( (array) $stored as $key ) {
				if ( self::valid_meta_key( $key, $object ) && ! isset( $keys[ $key ] ) ) {
					$keys[ $key ] = $key;
				}
			}
		}
		ksort( $keys );
		$keys = array_slice( $keys, 0, self::MAX_KEYS, true );
		if ( function_exists( 'wp_cache_set' ) ) wp_cache_set( $cache_key, $keys, 'tciel_native_fields', 60 );
		return $keys;
	}

	/** Discover global attributes and local (product-level) attributes. */
	public static function attributes() {
		if ( null !== self::$attributes_cache ) {
			return self::$attributes_cache;
		}
		$attributes = array();
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( (array) wc_get_attribute_taxonomies() as $attribute ) {
				if ( empty( $attribute->attribute_name ) ) {
					continue;
				}
				$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );
				if ( ! taxonomy_exists( $taxonomy ) ) {
					continue;
				}
				$attributes[ self::ATTR_PREFIX . $taxonomy ] = array(
					'name'     => $taxonomy,
					'label'    => isset( $attribute->attribute_label ) && $attribute->attribute_label ? $attribute->attribute_label : $taxonomy,
					'taxonomy' => true,
					'id'       => (int) $attribute->attribute_id,
				);
			}
		}
		// Local attributes are not registered globally. Sample actual WooCommerce products.
		if ( function_exists( 'wc_get_products' ) ) {
			$ids = wc_get_products( array( 'limit' => 100, 'status' => array( 'publish', 'draft', 'private', 'pending' ), 'return' => 'ids', 'orderby' => 'ID', 'order' => 'DESC' ) );
			foreach ( (array) $ids as $id ) {
				$p = wc_get_product( $id );
				if ( ! $p || ! is_callable( array( $p, 'get_attributes' ) ) ) {
					continue;
				}
				foreach ( (array) $p->get_attributes() as $attribute ) {
					if ( ! $attribute instanceof \WC_Product_Attribute || $attribute->is_taxonomy() ) {
						continue;
					}
					$name = $attribute->get_name();
					$key = self::ATTR_PREFIX . sanitize_title( $name );
					if ( ! isset( $attributes[ $key ] ) && strlen( $key ) < 130 ) {
						$attributes[ $key ] = array( 'name' => $name, 'label' => $name, 'taxonomy' => false, 'id' => 0 );
					}
				}
			}
		}
		ksort( $attributes );
		self::$attributes_cache = $attributes;
		return $attributes;
	}

	/** Extra selectable fields: only registered/stored custom meta and native Woo attributes. */
	public static function fields( $type ) {
		if ( isset( self::$fields_cache[ $type ] ) ) {
			return self::$fields_cache[ $type ];
		}
		$fields = array();
		foreach ( self::meta_keys( $type ) as $key => $label ) {
			$fields[ self::META_PREFIX . $key ] = sprintf( /* translators: %s: custom field label */ __( 'Campo personalizado: %s', 'taxocommerce-import-export-lite' ), $label );
		}
		if ( 'product' === $type ) {
			foreach ( self::attributes() as $key => $attribute ) {
				$fields[ $key ] = sprintf( /* translators: %s: WooCommerce attribute label */ __( 'Atributo de producto: %s', 'taxocommerce-import-export-lite' ), $attribute['label'] );
			}
		}
		self::$fields_cache[ $type ] = $fields;
		return $fields;
	}

	private static function registered_meta( $type, $key ) {
		$object = in_array( $type, array( 'product_cat', 'product_tag', 'category', 'post_tag' ), true ) ? 'term' : 'post';
		return (array) get_registered_meta_keys( $object, $type ) + (array) get_registered_meta_keys( $object, '' );
	}

	/** Validate scalar metadata without writing any content. */
	public static function validate_meta( $type, $data ) {
		$fields = self::fields( $type );
		foreach ( $data as $field => $value ) {
			if ( 0 !== strpos( $field, self::META_PREFIX ) ) {
				continue;
			}
			if ( ! isset( $fields[ $field ] ) || ! is_scalar( $value ) || ( function_exists( 'is_serialized' ) && is_serialized( $value ) ) ) {
				return new \WP_Error( 'unknown_meta', __( 'El campo personalizado no está disponible o contiene datos complejos no compatibles con Lite.', 'taxocommerce-import-export-lite' ) );
			}
			$key  = substr( $field, strlen( self::META_PREFIX ) );
			$regs = self::registered_meta( $type, $key );
			$args = isset( $regs[ $key ] ) ? $regs[ $key ] : array();
			$type_name = isset( $args['type'] ) ? $args['type'] : 'string';
			if ( 'integer' === $type_name && ! preg_match( '/^-?[0-9]+$/D', (string) $value ) ) {
				return new \WP_Error( 'invalid_meta_integer', __( 'El campo personalizado requiere un número entero.', 'taxocommerce-import-export-lite' ) );
			}
			if ( 'number' === $type_name && ! is_numeric( $value ) ) {
				return new \WP_Error( 'invalid_meta_number', __( 'El campo personalizado requiere un número.', 'taxocommerce-import-export-lite' ) );
			}
			if ( 'boolean' === $type_name && is_wp_error( CSV::boolean( $value ) ) ) {
				return new \WP_Error( 'invalid_meta_boolean', __( 'El campo personalizado requiere sí/no.', 'taxocommerce-import-export-lite' ) );
			}
		}
		return true;
	}

	/** Prepare WC_Product_Attribute objects, preserving unselected attributes. No writes. */
	public static function prepare_attributes( $product, $data ) {
		$available = self::attributes();
		$existing  = $product && is_callable( array( $product, 'get_attributes' ) ) ? (array) $product->get_attributes() : array();
		$changed   = false;
		foreach ( $data as $field => $value ) {
			if ( 0 !== strpos( $field, self::ATTR_PREFIX ) ) {
				continue;
			}
			if ( ! isset( $available[ $field ] ) ) {
				return new \WP_Error( 'unknown_attribute', __( 'El atributo no existe en este WooCommerce.', 'taxocommerce-import-export-lite' ) );
			}
			$info = $available[ $field ];
			$values = array_values( array_unique( array_filter( array_map( 'trim', explode( '|', (string) $value ) ), 'strlen' ) ) );
			if ( count( $values ) > 100 ) {
				return new \WP_Error( 'too_many_attribute_values', __( 'Demasiados valores en el atributo.', 'taxocommerce-import-export-lite' ) );
			}
			$prior = null;
			foreach ( $existing as $item ) {
				if ( $item instanceof \WC_Product_Attribute && sanitize_title( $item->get_name() ) === sanitize_title( $info['name'] ) ) {
					$prior = $item;
					break;
				}
			}
			if ( $info['taxonomy'] ) {
				$term_ids = array();
				foreach ( $values as $entry ) {
					$term = get_term_by( 'slug', sanitize_title( $entry ), $info['name'] );
					if ( ! $term ) {
						$term = get_term_by( 'name', $entry, $info['name'] );
					}
					if ( ! $term ) {
						return new \WP_Error( 'attribute_term_missing', sprintf( /* translators: 1: attribute, 2: term */ __( 'El atributo %1$s no tiene el valor %2$s; créalo en WooCommerce o excluye la columna.', 'taxocommerce-import-export-lite' ), $info['label'], $entry ) );
					}
					$term_ids[] = (int) $term->term_id;
				}
				$options = $term_ids;
			} else {
				$options = array_map( 'sanitize_text_field', $values );
			}
			$attribute = new \WC_Product_Attribute();
			$attribute->set_id( $info['id'] );
			$attribute->set_name( $info['name'] );
			$attribute->set_options( $options );
			$attribute->set_visible( $prior ? $prior->get_visible() : true );
			$attribute->set_variation( $prior ? $prior->get_variation() : false );
			$attribute->set_position( $prior ? $prior->get_position() : count( $existing ) );
			$existing[ sanitize_title( $info['name'] ) ] = $attribute;
			$changed = true;
		}
		return $changed ? $existing : null;
	}

	/** Save selected public scalar metadata using standard core/WooCommerce CRUD. */
	public static function save_meta( $type, $id, $data, $product = null ) {
		$term = in_array( $type, array( 'product_cat', 'product_tag', 'category', 'post_tag' ), true );
		foreach ( $data as $field => $raw ) {
			if ( 0 !== strpos( $field, self::META_PREFIX ) ) {
				continue;
			}
			$key = substr( $field, strlen( self::META_PREFIX ) );
			if ( ! ( ! $term && 0 === (int) $id && $product && current_user_can( 'edit_products' ) ) && ! current_user_can( $term ? 'edit_term_meta' : 'edit_post_meta', $id, $key ) ) {
				return new \WP_Error( 'meta_permission', __( 'No tienes permiso para editar este campo personalizado.', 'taxocommerce-import-export-lite' ) );
			}
			$values = self::registered_meta( $type, $key );
			$kind   = isset( $values[ $key ]['type'] ) ? $values[ $key ]['type'] : 'string';
			$value  = 'boolean' === $kind ? CSV::boolean( $raw ) : ( 'integer' === $kind ? (int) $raw : ( 'number' === $kind ? (float) $raw : sanitize_text_field( $raw ) ) );
			if ( is_wp_error( $value ) ) {
				return $value;
			}
			if ( $product && is_callable( array( $product, 'update_meta_data' ) ) ) {
				$product->update_meta_data( $key, $value );
			} elseif ( $term ) {
				update_term_meta( $id, $key, $value );
			} else {
				update_post_meta( $id, $key, $value );
			}
		}
		return true;
	}

	/** Export only scalar values; never serialize arrays or internal WC metadata. */
	public static function export( $type, $id, $field, $product = null ) {
		if ( 0 === strpos( $field, self::META_PREFIX ) ) {
			$key = substr( $field, strlen( self::META_PREFIX ) );
			$term = in_array( $type, array( 'product_cat', 'product_tag', 'category', 'post_tag' ), true );
			$value = $term ? get_term_meta( $id, $key, true ) : ( $product && is_callable( array( $product, 'get_meta' ) ) ? $product->get_meta( $key, true ) : get_post_meta( $id, $key, true ) );
			return is_scalar( $value ) ? ( is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value ) : '';
		}
		if ( 'product' === $type && 0 === strpos( $field, self::ATTR_PREFIX ) && $product ) {
			$info = self::attributes();
			if ( ! isset( $info[ $field ] ) ) {
				return '';
			}
			foreach ( (array) $product->get_attributes() as $attribute ) {
				if ( $attribute instanceof \WC_Product_Attribute && sanitize_title( $attribute->get_name() ) === sanitize_title( $info[ $field ]['name'] ) ) {
					if ( $attribute->is_taxonomy() ) {
						return implode( '|', array_map( static function ( $term ) { return $term->slug; }, (array) $attribute->get_terms() ) );
					}
					return implode( '|', (array) $attribute->get_options() );
				}
			}
		}
		return '';
	}
}
