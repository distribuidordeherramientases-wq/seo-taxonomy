<?php
/** Standalone native CSV field definitions and CRUD. No TaxoCommerce Core APIs. */
namespace TaxoCommerce\ImportExportLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CSV {
	public static function types() {
		return array(
			'product' => __( 'Productos WooCommerce', 'taxocommerce-import-export-lite' ),
			'product_cat' => __( 'Categorías de producto', 'taxocommerce-import-export-lite' ),
			'product_tag' => __( 'Etiquetas de producto', 'taxocommerce-import-export-lite' ),
			'page' => __( 'Páginas', 'taxocommerce-import-export-lite' ),
			'post' => __( 'Entradas', 'taxocommerce-import-export-lite' ),
			'category' => __( 'Categorías de entradas', 'taxocommerce-import-export-lite' ),
			'post_tag' => __( 'Etiquetas de entradas', 'taxocommerce-import-export-lite' ),
		);
	}

	/** Expose native fields only; selecting no taxonomy field never requires a term. */
	public static function fields( $type ) {
		if ( 'product' === $type ) {
			$fields = array(
				'id' => 'ID', 'type' => __( 'Tipo (simple, variable, external, grouped)', 'taxocommerce-import-export-lite' ),
				'sku' => 'SKU', 'name' => __( 'Nombre', 'taxocommerce-import-export-lite' ), 'slug' => 'Slug',
				'status' => __( 'Estado (draft/publish)', 'taxocommerce-import-export-lite' ),
				'description' => __( 'Descripción', 'taxocommerce-import-export-lite' ),
				'short_description' => __( 'Descripción corta', 'taxocommerce-import-export-lite' ),
				'regular_price' => __( 'Precio normal', 'taxocommerce-import-export-lite' ),
				'sale_price' => __( 'Precio rebajado', 'taxocommerce-import-export-lite' ),
				'virtual' => __( 'Virtual (yes/no)', 'taxocommerce-import-export-lite' ),
				'downloadable' => __( 'Descargable (yes/no)', 'taxocommerce-import-export-lite' ),
				'download_1_name' => __( 'Descarga 1: nombre', 'taxocommerce-import-export-lite' ),
				'download_1_url' => __( 'Descarga 1: URL', 'taxocommerce-import-export-lite' ),
				'download_2_name' => __( 'Descarga 2: nombre', 'taxocommerce-import-export-lite' ),
				'download_2_url' => __( 'Descarga 2: URL', 'taxocommerce-import-export-lite' ),
				'download_limit' => __( 'Límite de descargas (-1 sin límite)', 'taxocommerce-import-export-lite' ),
				'download_expiry' => __( 'Caducidad de descarga (-1 sin límite)', 'taxocommerce-import-export-lite' ),
				'manage_stock' => __( 'Gestionar existencias (yes/no)', 'taxocommerce-import-export-lite' ),
				'stock_quantity' => __( 'Unidades', 'taxocommerce-import-export-lite' ),
				'stock_status' => __( 'Existencias (instock/outofstock/onbackorder)', 'taxocommerce-import-export-lite' ),
				'backorders' => __( 'Reservas (no/notify/yes)', 'taxocommerce-import-export-lite' ),
				'categories' => __( 'Categorías existentes (opcional)', 'taxocommerce-import-export-lite' ),
				'tags' => __( 'Etiquetas existentes (opcional)', 'taxocommerce-import-export-lite' ),
				'featured_image' => __( 'Imagen existente en Medios (URL o ID)', 'taxocommerce-import-export-lite' ),
				'weight' => __( 'Peso', 'taxocommerce-import-export-lite' ), 'length' => __( 'Longitud', 'taxocommerce-import-export-lite' ),
				'width' => __( 'Anchura', 'taxocommerce-import-export-lite' ), 'height' => __( 'Altura', 'taxocommerce-import-export-lite' ),
				'catalog_visibility' => __( 'Visibilidad (visible/catalog/search/hidden)', 'taxocommerce-import-export-lite' ),
				'featured' => __( 'Destacado (yes/no)', 'taxocommerce-import-export-lite' ),
				'sold_individually' => __( 'Venta individual (yes/no)', 'taxocommerce-import-export-lite' ),
				'tax_status' => __( 'Estado fiscal', 'taxocommerce-import-export-lite' ),
				'tax_class' => __( 'Clase fiscal', 'taxocommerce-import-export-lite' ),
				'purchase_note' => __( 'Nota de compra', 'taxocommerce-import-export-lite' ),
			);
			return self::with_dynamic( $type, $fields );
		}
		if ( in_array( $type, array( 'product_cat', 'product_tag', 'category', 'post_tag' ), true ) ) {
			$fields = array( 'id' => 'ID', 'name' => __( 'Nombre', 'taxocommerce-import-export-lite' ), 'slug' => 'Slug', 'description' => __( 'Descripción', 'taxocommerce-import-export-lite' ) );
			if ( in_array( $type, array( 'category', 'product_cat' ), true ) ) {
				$fields['parent_slug'] = __( 'Categoría padre existente (slug)', 'taxocommerce-import-export-lite' );
			}
			return self::with_dynamic( $type, $fields );
		}
		$fields = array(
			'id' => 'ID', 'title' => __( 'Título', 'taxocommerce-import-export-lite' ), 'slug' => 'Slug',
			'status' => __( 'Estado', 'taxocommerce-import-export-lite' ), 'content' => __( 'Contenido', 'taxocommerce-import-export-lite' ),
			'excerpt' => __( 'Extracto', 'taxocommerce-import-export-lite' ), 'date' => __( 'Fecha', 'taxocommerce-import-export-lite' ),
			'featured_image' => __( 'Imagen existente en Medios (URL o ID)', 'taxocommerce-import-export-lite' ),
			'menu_order' => __( 'Orden del menú', 'taxocommerce-import-export-lite' ),
		);
		if ( 'post' === $type ) {
			$fields['categories'] = __( 'Categorías existentes (opcional)', 'taxocommerce-import-export-lite' );
			$fields['tags'] = __( 'Etiquetas existentes (opcional)', 'taxocommerce-import-export-lite' );
		}
		return self::with_dynamic( $type, $fields );
	}

	private static function with_dynamic( $type, $fields ) {
		return array_merge( $fields, NativeFields::fields( $type ) );
	}

	public static function normalize_header( $value ) {
		$value = strtolower( remove_accents( trim( (string) $value ) ) );
		$value = preg_replace( '/[^a-z0-9]+/', '_', $value );
		return trim( $value, '_' );
	}

	/** Do not auto-map IDs or taxonomies from foreign CSV sources. */
	public static function suggest( $type, $column ) {
		$column = trim( (string) $column );
		if ( ( 0 === strpos( $column, 'meta:' ) || 0 === strpos( $column, 'attr:' ) ) && isset( self::fields( $type )[ $column ] ) ) {
			return $column;
		}
		$key = self::normalize_header( $column );
		$aliases = array(
			'product' => array( 'product_name' => 'name', 'nombre' => 'name', 'product_type' => 'type', 'published' => 'status', 'publicado' => 'status', 'precio' => 'regular_price', 'precio_normal' => 'regular_price', 'precio_rebajado' => 'sale_price', 'short_description' => 'short_description', 'descripcion' => 'description', 'descripcion_corta' => 'short_description', 'download_1_url' => 'download_1_url', 'download_1_name' => 'download_1_name', 'download_expiry_days' => 'download_expiry' ),
			'page' => array( 'post_title' => 'title', 'post_content' => 'content', 'titulo' => 'title' ),
			'post' => array( 'post_title' => 'title', 'post_content' => 'content', 'titulo' => 'title' ),
		);
		$key = isset( $aliases[ $type ][ $key ] ) ? $aliases[ $type ][ $key ] : $key;
		if ( in_array( $key, array( 'id', 'categories', 'tags', 'parent_slug', 'featured_image' ), true ) ) {
			return '';
		}
		return array_key_exists( $key, self::fields( $type ) ) ? $key : '';
	}

	/** Map selected nonempty CSV cells only. Blank values never overwrite existing fields. */
	public static function map_row( $row, $mapping ) {
		$data = array();
		foreach ( $mapping as $index => $field ) {
			if ( '' !== $field && isset( $row[ $index ] ) ) {
				$value = trim( (string) $row[ $index ] );
				if ( '' !== $value ) {
					$data[ $field ] = $value;
				}
			}
		}
		return $data;
	}

	/** Prevent spreadsheet-formula injection in exported data. */
	public static function csv_safe( $value ) {
		$value = (string) $value;
		return preg_match( '/^[=+@\t\r]/u', $value ) ? "'" . $value : $value;
	}

	public static function boolean( $value ) {
		$value = strtolower( remove_accents( trim( (string) $value ) ) );
		if ( in_array( $value, array( 'yes', '1', 'true', 'si' ), true ) ) {
			return true;
		}
		if ( in_array( $value, array( 'no', '0', 'false' ), true ) ) {
			return false;
		}
		return new \WP_Error( 'invalid_bool', __( 'Usa yes/no, 1/0 o sí/no para campos booleanos.', 'taxocommerce-import-export-lite' ) );
	}

	private static function existing_terms( $value, $taxonomy ) {
		$ids = array();
		foreach ( preg_split( '/[|,]/', $value, -1, PREG_SPLIT_NO_EMPTY ) as $term_name ) {
			$term_name = trim( $term_name );
			$term = get_term_by( 'slug', sanitize_title( $term_name ), $taxonomy );
			if ( ! $term ) {
				$term = get_term_by( 'name', $term_name, $taxonomy );
			}
			if ( ! $term ) {
					return new \WP_Error( 'term_missing', sprintf( /* translators: %s: supplied taxonomy name */ __( 'La categoría o etiqueta «%s» no existe; omite la columna o crea el término antes.', 'taxocommerce-import-export-lite' ), $term_name ) );
			}
			$ids[] = (int) $term->term_id;
		}
		return array_values( array_unique( $ids ) );
	}

	private static function mapped_terms( $type, $data ) {
		$map = 'product' === $type ? array( 'categories' => 'product_cat', 'tags' => 'product_tag' ) : ( 'post' === $type ? array( 'categories' => 'category', 'tags' => 'post_tag' ) : array() );
		$resolved = array();
		foreach ( $map as $field => $taxonomy ) {
			if ( isset( $data[ $field ] ) ) {
				$ids = self::existing_terms( $data[ $field ], $taxonomy );
				if ( is_wp_error( $ids ) ) {
					return $ids;
				}
				$resolved[ $taxonomy ] = $ids;
			}
		}
		return $resolved;
	}

	private static function image( $value ) {
		$id = ctype_digit( (string) $value ) ? absint( $value ) : attachment_url_to_postid( esc_url_raw( $value ) );
		if ( ! $id || ! wp_attachment_is_image( $id ) ) {
			return new \WP_Error( 'image_missing', __( 'La imagen debe existir previamente en la biblioteca de medios.', 'taxocommerce-import-export-lite' ) );
		}
		return $id;
	}

	private static function status( $value ) {
		$value = strtolower( trim( $value ) );
		$values = array( '1' => 'publish', 'yes' => 'publish', '0' => 'draft', 'no' => 'draft', '-1' => 'private' );
		$value = isset( $values[ $value ] ) ? $values[ $value ] : $value;
		return in_array( $value, array( 'publish', 'draft', 'pending', 'private' ), true ) ? $value : new \WP_Error( 'status_invalid', __( 'Estado de publicación no válido.', 'taxocommerce-import-export-lite' ) );
	}

	public static function import( $type, $data, $mode = 'upsert' ) {
		if ( ! isset( self::types()[ $type ] ) || ! $data ) {
			return new \WP_Error( 'invalid_data', __( 'Fila vacía o tipo desconocido.', 'taxocommerce-import-export-lite' ) );
		}
		if ( isset( $data['status'] ) ) {
			$data['status'] = self::status( $data['status'] );
			if ( is_wp_error( $data['status'] ) ) {
				return $data['status'];
			}
		}
		$precheck = self::precheck( $type, $data, $mode );
		if ( is_wp_error( $precheck ) ) {
			return $precheck;
		}
		if ( in_array( $type, array( 'product_cat', 'product_tag', 'category', 'post_tag' ), true ) ) {
			return self::import_term( $type, $data, $mode );
		}
		return 'product' === $type ? self::import_product( $data, $mode ) : self::import_post( $type, $data, $mode );
	}

	private static function import_term( $type, $data, $mode ) {
		if ( ! current_user_can( 'manage_categories' ) && ! current_user_can( 'manage_product_terms' ) ) {
			return new \WP_Error( 'forbidden', __( 'No tienes permisos para gestionar categorías.', 'taxocommerce-import-export-lite' ) );
		}
		$term = ! empty( $data['slug'] ) ? get_term_by( 'slug', sanitize_title( $data['slug'] ), $type ) : false;
		if ( ! $term && empty( $data['slug'] ) && ! empty( $data['name'] ) ) {
			$term = get_term_by( 'name', $data['name'], $type );
		}
		if ( ! $term && 'update' === $mode ) {
			return new \WP_Error( 'not_found', __( 'Término no encontrado.', 'taxocommerce-import-export-lite' ) );
		}
		if ( ! $term && empty( $data['name'] ) ) {
			return new \WP_Error( 'missing_name', __( 'Falta el nombre del término.', 'taxocommerce-import-export-lite' ) );
		}
		$args = array();
		if ( isset( $data['description'] ) ) $args['description'] = sanitize_textarea_field( $data['description'] );
		if ( isset( $data['slug'] ) ) $args['slug'] = sanitize_title( $data['slug'] );
		if ( isset( $data['parent_slug'] ) ) {
			$parent = get_term_by( 'slug', sanitize_title( $data['parent_slug'] ), $type );
			if ( ! $parent ) return new \WP_Error( 'parent_missing', __( 'No existe la categoría padre.', 'taxocommerce-import-export-lite' ) );
			$args['parent'] = (int) $parent->term_id;
		}
		if ( $term ) {
			if ( isset( $data['name'] ) ) $args['name'] = sanitize_text_field( $data['name'] );
			$result = $args ? wp_update_term( (int) $term->term_id, $type, $args ) : (int) $term->term_id;
		} else {
			$result = wp_insert_term( sanitize_text_field( $data['name'] ), $type, $args );
		}
		if ( is_wp_error( $result ) ) return $result;
		$id = is_array( $result ) ? (int) $result['term_id'] : (int) $result;
		$meta = NativeFields::save_meta( $type, $id, $data );
		return is_wp_error( $meta ) ? $meta : $result;
	}

	private static function import_post( $type, $data, $mode ) {
		if ( ! current_user_can( 'page' === $type ? 'edit_pages' : 'edit_posts' ) ) {
			return new \WP_Error( 'forbidden', __( 'No tienes permisos para editar estos contenidos.', 'taxocommerce-import-export-lite' ) );
		}
		$terms = self::mapped_terms( $type, $data );
		if ( is_wp_error( $terms ) ) return $terms;
		$id = 0;
		if ( ! empty( $data['slug'] ) ) {
			$post = get_page_by_path( sanitize_title( $data['slug'] ), OBJECT, $type );
			$id = $post ? (int) $post->ID : 0;
		}
		if ( ! $id && empty( $data['slug'] ) && ! empty( $data['id'] ) && $type === get_post_type( absint( $data['id'] ) ) ) {
			$id = absint( $data['id'] );
		}
		if ( ! $id && 'update' === $mode ) return new \WP_Error( 'not_found', __( 'Contenido no encontrado.', 'taxocommerce-import-export-lite' ) );
		if ( ! $id && empty( $data['title'] ) ) return new \WP_Error( 'missing_title', __( 'Falta el título.', 'taxocommerce-import-export-lite' ) );
		if ( $id && ! current_user_can( 'edit_post', $id ) ) return new \WP_Error( 'forbidden', __( 'No puedes editar este contenido.', 'taxocommerce-import-export-lite' ) );
		if ( isset( $data['status'] ) && 'publish' === $data['status'] && ! current_user_can( 'publish_' . ( 'page' === $type ? 'pages' : 'posts' ) ) ) {
			return new \WP_Error( 'publish_forbidden', __( 'No tienes permiso para publicar.', 'taxocommerce-import-export-lite' ) );
		}
		$args = array( 'post_type' => $type );
		if ( $id ) $args['ID'] = $id;
		if ( ! $id ) $args['post_status'] = 'draft';
		$map = array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'slug' => 'post_name', 'status' => 'post_status', 'date' => 'post_date', 'menu_order' => 'menu_order' );
		foreach ( $map as $field => $key ) {
			if ( isset( $data[ $field ] ) ) {
				$args[ $key ] = in_array( $field, array( 'content', 'excerpt' ), true ) ? wp_kses_post( $data[ $field ] ) : ( 'menu_order' === $field ? (int) $data[ $field ] : sanitize_text_field( $data[ $field ] ) );
			}
		}
		$image_id = null;
		if ( isset( $data['featured_image'] ) ) {
			$image_id = self::image( $data['featured_image'] );
			if ( is_wp_error( $image_id ) ) return $image_id;
		}
		$id = wp_insert_post( $args, true );
		if ( is_wp_error( $id ) ) return $id;
		if ( null !== $image_id ) set_post_thumbnail( $id, $image_id );
		foreach ( $terms as $taxonomy => $ids ) {
			$result = wp_set_object_terms( $id, $ids, $taxonomy, false );
			if ( is_wp_error( $result ) ) return $result;
		}
		$meta = NativeFields::save_meta( $type, $id, $data );
		return is_wp_error( $meta ) ? $meta : $id;
	}

	/**
	 * Resolve an existing WooCommerce product without confusing an orphaned
	 * SKU lookup or a trashed post with a product that can be updated.
	 * Both preflight and import must use precisely the same identity rules.
	 *
	 * @return array|\WP_Error Product ID and object (null for an allowed new product).
	 */
	private static function resolve_product( $data, $mode ) {
		$sku     = ! empty( $data['sku'] ) ? sanitize_text_field( $data['sku'] ) : '';
		$slug    = ! empty( $data['slug'] ) ? sanitize_title( $data['slug'] ) : '';
		$sku_id  = $sku ? (int) wc_get_product_id_by_sku( $sku ) : 0;
		$by_slug = $slug ? get_page_by_path( $slug, OBJECT, 'product' ) : false;
		$slug_id = $by_slug ? (int) $by_slug->ID : 0;
		$found   = array();

		foreach ( array( 'SKU' => $sku_id, 'slug' => $slug_id ) as $origin => $candidate_id ) {
			if ( ! $candidate_id ) continue;
			$status = get_post_status( $candidate_id );
			if ( 'trash' === $status ) {
				if ( 'update' === $mode ) {
					return new \WP_Error( 'product_in_trash', sprintf(
						/* translators: %d: WooCommerce product ID in the WordPress trash. */
						__( 'El producto %d está en la papelera. Restáuralo antes de actualizarlo.', 'taxocommerce-import-export-lite' ),
						$candidate_id
					) );
				}
				continue; // Create mode never restores or updates a trashed product.
			}
			$product = wc_get_product( $candidate_id );
			if ( ! $product ) {
				if ( false === $status ) continue; // Orphaned lookup; no WordPress record exists.
				return new \WP_Error( 'invalid_product_reference', sprintf(
					/* translators: 1: SKU or slug; 2: matching WordPress post ID. */
					__( 'El %1$s apunta al registro %2$d, pero WooCommerce no puede cargar ese producto. Revisa el registro antes de importar.', 'taxocommerce-import-export-lite' ),
					$origin,
					$candidate_id
				) );
			}
			$found[ $origin ] = $product;
		}
		if ( isset( $found['SKU'], $found['slug'] ) && $found['SKU']->get_id() !== $found['slug']->get_id() ) {
			return new \WP_Error( 'identity_conflict', __( 'SKU y slug apuntan a productos diferentes; no se modificará ninguno.', 'taxocommerce-import-export-lite' ) );
		}
		$product = isset( $found['SKU'] ) ? $found['SKU'] : ( isset( $found['slug'] ) ? $found['slug'] : null );
		if ( $product && $sku && $product->get_sku() && $product->get_sku() !== $sku ) {
			return new \WP_Error( 'sku_conflict', __( 'El slug pertenece a otro SKU; no se modificará ese producto.', 'taxocommerce-import-export-lite' ) );
		}
		$id = $product ? (int) $product->get_id() : 0;
		if ( ! $id && ! $sku && ! $slug && ! empty( $data['id'] ) && 'product' === get_post_type( absint( $data['id'] ) ) ) {
			$id      = absint( $data['id'] );
			$product = wc_get_product( $id );
			if ( ! $product ) {
				return new \WP_Error( 'invalid_product_reference', __( 'El ID indicado no corresponde a un producto disponible de WooCommerce.', 'taxocommerce-import-export-lite' ) );
			}
		}
		if ( ! $id && 'update' === $mode ) {
			return new \WP_Error( 'not_found', __( 'Producto no encontrado. Usa Crear y actualizar para darlo de alta.', 'taxocommerce-import-export-lite' ) );
		}
		if ( ! $id && $sku && function_exists( 'wc_product_has_unique_sku' ) && ! wc_product_has_unique_sku( 0, $sku ) ) {
			return new \WP_Error( 'sku_reserved', sprintf(
				/* translators: %s: SKU which WooCommerce still marks as occupied. */
				__( 'El SKU «%s» está reservado por WooCommerce, pero no se encuentra un producto editable. Revisa la papelera o el índice de SKU antes de importar.', 'taxocommerce-import-export-lite' ),
				$sku
			) );
		}
		return array( 'id' => $id, 'product' => $product );
	}

	private static function import_product( $data, $mode ) {
		if ( ! function_exists( 'wc_get_product' ) || ! current_user_can( 'edit_products' ) ) {
			return new \WP_Error( 'woocommerce', __( 'WooCommerce activo y permisos de productos requeridos.', 'taxocommerce-import-export-lite' ) );
		}
		$terms = self::mapped_terms( 'product', $data );
		if ( is_wp_error( $terms ) ) return $terms;
		$lookup = self::resolve_product( $data, $mode );
		if ( is_wp_error( $lookup ) ) return $lookup;
		$id = $lookup['id'];
		if ( ! $id && empty( $data['name'] ) ) return new \WP_Error( 'missing_name', __( 'Falta el nombre del producto.', 'taxocommerce-import-export-lite' ) );
		if ( $id && ! current_user_can( 'edit_post', $id ) ) return new \WP_Error( 'forbidden', __( 'No puedes editar este producto.', 'taxocommerce-import-export-lite' ) );
		$type = isset( $data['type'] ) ? strtolower( sanitize_key( $data['type'] ) ) : 'simple';
		$classes = array( 'simple' => '\\WC_Product_Simple', 'variable' => '\\WC_Product_Variable', 'external' => '\\WC_Product_External', 'grouped' => '\\WC_Product_Grouped' );
		if ( ! isset( $classes[ $type ] ) ) return new \WP_Error( 'product_type', __( 'Tipo de producto no compatible con esta importación Lite.', 'taxocommerce-import-export-lite' ) );
		$product = $id ? $lookup['product'] : new $classes[ $type ]();
		if ( ! $product ) return new \WP_Error( 'bad_product', __( 'No se pudo cargar el producto.', 'taxocommerce-import-export-lite' ) );
		if ( $id && isset( $data['type'] ) && $product->get_type() !== $type ) {
			return new \WP_Error( 'type_mismatch', __( 'El tipo existente no coincide; no se transforma automáticamente.', 'taxocommerce-import-export-lite' ) );
		}
		if ( ! $id ) $product->set_status( 'draft' );
		if ( isset( $data['status'] ) && 'publish' === $data['status'] && ! current_user_can( 'publish_products' ) ) return new \WP_Error( 'publish_forbidden', __( 'Sin permiso para publicar productos.', 'taxocommerce-import-export-lite' ) );
		$setters = array(
			'sku' => 'set_sku', 'name' => 'set_name', 'slug' => 'set_slug',
			'status' => 'set_status', 'description' => 'set_description',
			'short_description' => 'set_short_description',
			'regular_price' => 'set_regular_price', 'sale_price' => 'set_sale_price',
			'virtual' => 'set_virtual', 'downloadable' => 'set_downloadable',
			'download_limit' => 'set_download_limit', 'download_expiry' => 'set_download_expiry',
			'manage_stock' => 'set_manage_stock', 'stock_quantity' => 'set_stock_quantity',
			'stock_status' => 'set_stock_status', 'backorders' => 'set_backorders',
			'weight' => 'set_weight', 'length' => 'set_length', 'width' => 'set_width',
			'height' => 'set_height', 'catalog_visibility' => 'set_catalog_visibility',
			'featured' => 'set_featured', 'sold_individually' => 'set_sold_individually',
			'tax_status' => 'set_tax_status', 'tax_class' => 'set_tax_class',
			'purchase_note' => 'set_purchase_note',
		);
		$boolean = array( 'virtual', 'downloadable', 'manage_stock', 'featured', 'sold_individually' );
		$decimals = array( 'regular_price', 'sale_price', 'weight', 'length', 'width', 'height' );
		$integer = array( 'download_limit', 'download_expiry', 'stock_quantity' );
		$image_id = null;
		if ( isset( $data['featured_image'] ) ) {
			$image_id = self::image( $data['featured_image'] );
			if ( is_wp_error( $image_id ) ) return $image_id;
		}
		try {
			foreach ( $setters as $field => $method ) {
				if ( ! isset( $data[ $field ] ) || ! is_callable( array( $product, $method ) ) ) continue;
				$value = $data[ $field ];
				if ( in_array( $field, $boolean, true ) ) {
					$value = self::boolean( $value );
					if ( is_wp_error( $value ) ) return $value;
				} elseif ( in_array( $field, $decimals, true ) ) {
					$value = wc_format_decimal( $value );
					if ( ! is_numeric( $value ) || (float) $value < 0 ) return new \WP_Error( 'invalid_decimal', __( 'Precio o dimensión no válidos.', 'taxocommerce-import-export-lite' ) );
				} elseif ( in_array( $field, $integer, true ) ) {
					if ( ! preg_match( '/^-?[0-9]+$/', $value ) || ( 'stock_quantity' === $field && (int) $value < 0 ) ) return new \WP_Error( 'invalid_integer', __( 'Cantidad o límite no válido.', 'taxocommerce-import-export-lite' ) );
					$value = (int) $value;
				} elseif ( 'description' === $field || 'short_description' === $field ) {
					$value = wp_kses_post( $value );
				} elseif ( 'stock_status' === $field && ! in_array( $value, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
					return new \WP_Error( 'stock_invalid', __( 'Estado de existencias no válido.', 'taxocommerce-import-export-lite' ) );
				} elseif ( 'backorders' === $field && ! in_array( $value, array( 'no', 'notify', 'yes' ), true ) ) {
					return new \WP_Error( 'backorders_invalid', __( 'Valor de reservas no válido.', 'taxocommerce-import-export-lite' ) );
				} elseif ( 'catalog_visibility' === $field && ! in_array( $value, array( 'visible', 'catalog', 'search', 'hidden' ), true ) ) {
					return new \WP_Error( 'visibility_invalid', __( 'Visibilidad no válida.', 'taxocommerce-import-export-lite' ) );
				} else {
					$value = sanitize_text_field( $value );
				}
				$product->$method( $value );
			}
			$download_urls = array();
			foreach ( array( 1, 2 ) as $i ) {
				if ( isset( $data[ 'download_' . $i . '_url' ] ) ) {
					$url = esc_url_raw( $data[ 'download_' . $i . '_url' ], array( 'https', 'http' ) );
					if ( ! $url || ! wp_http_validate_url( $url ) ) return new \WP_Error( 'download_url', __( 'La URL de descarga debe ser HTTP(S) válida.', 'taxocommerce-import-export-lite' ) );
					$download_urls[] = array( 'name' => isset( $data[ 'download_' . $i . '_name' ] ) ? sanitize_text_field( $data[ 'download_' . $i . '_name' ] ) : wp_basename( wp_parse_url( $url, PHP_URL_PATH ) ), 'url' => $url );
				}
			}
			if ( $download_urls ) {
				if ( ! is_callable( array( $product, 'set_downloads' ) ) ) return new \WP_Error( 'download_type', __( 'Este tipo no admite archivos descargables.', 'taxocommerce-import-export-lite' ) );
				$downloads = array();
				foreach ( $download_urls as $entry ) {
					$download = new \WC_Product_Download();
					$download->set_name( $entry['name'] );
					$download->set_file( $entry['url'] );
					$downloads[] = $download;
				}
				$product->set_downloads( $downloads );
				$product->set_downloadable( true );
			}
			$attributes = NativeFields::prepare_attributes( $product, $data );
			if ( is_wp_error( $attributes ) ) return $attributes;
			if ( null !== $attributes ) $product->set_attributes( $attributes );
			$meta = NativeFields::save_meta( 'product', $id, $data, $product );
			if ( is_wp_error( $meta ) ) return $meta;
			if ( null !== $image_id ) $product->set_image_id( $image_id );
			$id = $product->save();
		} catch ( \Exception $e ) {
			return new \WP_Error( 'product_save', $e->getMessage() );
		}
		foreach ( $terms as $taxonomy => $ids ) {
			$result = wp_set_object_terms( $id, $ids, $taxonomy, false );
			if ( is_wp_error( $result ) ) return $result;
		}
		return $id;
	}

	private static function term_slugs( $id, $taxonomy ) {
		$terms = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'slugs' ) );
		return is_wp_error( $terms ) ? '' : implode( '|', $terms );
	}

	public static function export_value( $type, $id, $field ) {
		if ( 0 === strpos( $field, 'meta:' ) || 0 === strpos( $field, 'attr:' ) ) {
			$product = 'product' === $type ? wc_get_product( $id ) : null;
			return NativeFields::export( $type, $id, $field, $product );
		}
		if ( 'product' === $type ) {
			$p = wc_get_product( $id );
			if ( ! $p ) return '';
			if ( 'categories' === $field || 'tags' === $field ) return self::term_slugs( $id, 'categories' === $field ? 'product_cat' : 'product_tag' );
			if ( 'featured_image' === $field ) return $p->get_image_id() ? wp_get_attachment_url( $p->get_image_id() ) : '';
			if ( 'type' === $field ) return $p->get_type();
			if ( preg_match( '/^download_([12])_(name|url)$/', $field, $match ) ) {
				$downloads = array_values( $p->get_downloads() );
				$index = (int) $match[1] - 1;
				if ( ! isset( $downloads[ $index ] ) ) return '';
				return 'name' === $match[2] ? $downloads[ $index ]->get_name() : $downloads[ $index ]->get_file();
			}
			$method = 'get_' . $field;
			if ( ! is_callable( array( $p, $method ) ) ) return '';
			$value = $p->$method();
			return is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value;
		}
		if ( in_array( $type, array( 'category', 'post_tag', 'product_cat', 'product_tag' ), true ) ) {
			$term = get_term( $id, $type );
			if ( ! $term || is_wp_error( $term ) ) return '';
			if ( 'parent_slug' === $field ) {
				$parent = $term->parent ? get_term( $term->parent, $type ) : false;
				return $parent && ! is_wp_error( $parent ) ? $parent->slug : '';
			}
			return (string) ( 'id' === $field ? $term->term_id : ( $term->$field ?? '' ) );
		}
		$post = get_post( $id );
		if ( ! $post ) return '';
		if ( 'categories' === $field || 'tags' === $field ) return self::term_slugs( $id, 'categories' === $field ? 'category' : 'post_tag' );
		if ( 'featured_image' === $field ) return get_post_thumbnail_id( $id ) ? wp_get_attachment_url( get_post_thumbnail_id( $id ) ) : '';
		$map = array( 'id' => 'ID', 'title' => 'post_title', 'slug' => 'post_name', 'status' => 'post_status', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'date' => 'post_date', 'menu_order' => 'menu_order' );
		$key = isset( $map[ $field ] ) ? $map[ $field ] : '';
		return $key ? (string) ( $post->$key ?? '' ) : '';
	}

	/**
	 * Read-only row validation, also used by the full CSV preflight.
	 * No product, taxonomy, post or metadata mutation is allowed here.
	 */
	public static function precheck( $type, $data, $mode = 'upsert' ) {
		if ( ! isset( self::types()[ $type ] ) || ! in_array( $mode, array( 'upsert', 'update' ), true ) ) {
			return new \WP_Error( 'invalid_type', __( 'Tipo o modo CSV no válido.', 'taxocommerce-import-export-lite' ) );
		}
		$metadata = NativeFields::validate_meta( $type, $data );
		if ( is_wp_error( $metadata ) ) return $metadata;
		if ( ! empty( $data['featured_image'] ) ) {
			$image = self::image( $data['featured_image'] );
			if ( is_wp_error( $image ) ) return $image;
		}
		if ( isset( $data['status'] ) ) {
			$status = self::status( $data['status'] );
			if ( is_wp_error( $status ) ) return $status;
		}
		if ( in_array( $type, array( 'product_cat', 'product_tag', 'category', 'post_tag' ), true ) ) {
			if ( ! current_user_can( in_array( $type, array( 'product_cat', 'product_tag' ), true ) ? 'manage_product_terms' : 'manage_categories' ) ) return new \WP_Error( 'forbidden', __( 'Sin permisos para gestionar términos.', 'taxocommerce-import-export-lite' ) );
			$term = ! empty( $data['slug'] ) ? get_term_by( 'slug', sanitize_title( $data['slug'] ), $type ) : false;
			if ( ! $term && empty( $data['slug'] ) && ! empty( $data['name'] ) ) $term = get_term_by( 'name', $data['name'], $type );
			if ( ! $term && 'update' === $mode ) return new \WP_Error( 'not_found', __( 'La categoría o etiqueta todavía no existe.', 'taxocommerce-import-export-lite' ) );
			if ( ! $term && empty( $data['name'] ) ) return new \WP_Error( 'name_missing', __( 'Falta el nombre de la categoría o etiqueta.', 'taxocommerce-import-export-lite' ) );
			if ( ! empty( $data['parent_slug'] ) && ! get_term_by( 'slug', sanitize_title( $data['parent_slug'] ), $type ) ) return new \WP_Error( 'parent_missing', __( 'La categoría padre indicada no existe.', 'taxocommerce-import-export-lite' ) );
			return true;
		}
		$terms = self::mapped_terms( $type, $data );
		if ( is_wp_error( $terms ) ) return $terms;
		if ( 'product' === $type ) {
			if ( ! function_exists( 'wc_get_product' ) || ! current_user_can( 'edit_products' ) ) return new \WP_Error( 'woocommerce_missing', __( 'WooCommerce no está disponible o faltan permisos.', 'taxocommerce-import-export-lite' ) );
			if ( isset( $data['status'] ) && 'publish' === self::status( $data['status'] ) && ! current_user_can( 'publish_products' ) ) return new \WP_Error( 'publish_forbidden', __( 'Sin permiso para publicar productos.', 'taxocommerce-import-export-lite' ) );
			if ( empty( $data['sku'] ) && empty( $data['slug'] ) && empty( $data['id'] ) ) return new \WP_Error( 'identity_missing', __( 'Asigna SKU o slug para crear y actualizar sin duplicar productos.', 'taxocommerce-import-export-lite' ) );
			$lookup = self::resolve_product( $data, $mode );
			if ( is_wp_error( $lookup ) ) return $lookup;
			$id = $lookup['id'];
			$product = $lookup['product'];
			if ( ! $id && empty( $data['name'] ) ) return new \WP_Error( 'name_missing', __( 'Falta el nombre del producto.', 'taxocommerce-import-export-lite' ) );
			$type_value = isset( $data['type'] ) ? sanitize_key( $data['type'] ) : 'simple';
			if ( ! in_array( $type_value, array( 'simple', 'variable', 'external', 'grouped' ), true ) ) return new \WP_Error( 'unsupported_type', __( 'Tipo de producto no compatible.', 'taxocommerce-import-export-lite' ) );
			if ( $product && isset( $data['type'] ) && $product->get_type() !== $type_value ) return new \WP_Error( 'type_mismatch', __( 'El producto existente tiene otro tipo.', 'taxocommerce-import-export-lite' ) );
			foreach ( array( 'virtual', 'downloadable', 'manage_stock', 'featured', 'sold_individually' ) as $key ) {
				if ( isset( $data[ $key ] ) && is_wp_error( self::boolean( $data[ $key ] ) ) ) return new \WP_Error( 'invalid_boolean', __( 'Campo sí/no inválido.', 'taxocommerce-import-export-lite' ) );
			}
			foreach ( array( 'regular_price', 'sale_price', 'weight', 'length', 'width', 'height' ) as $key ) {
				if ( isset( $data[ $key ] ) ) {
					$decimal = wc_format_decimal( $data[ $key ] );
					if ( ! is_numeric( $decimal ) || (float) $decimal < 0 ) return new \WP_Error( 'invalid_decimal', __( 'Precio o dimensión inválidos.', 'taxocommerce-import-export-lite' ) );
				}
			}
			foreach ( array( 'download_limit', 'download_expiry', 'stock_quantity' ) as $key ) {
				if ( isset( $data[ $key ] ) && ( ! preg_match( '/^-?[0-9]+$/D', $data[ $key ] ) || ( 'stock_quantity' === $key && (int) $data[ $key ] < 0 ) ) ) return new \WP_Error( 'invalid_integer', __( 'Cantidad o límite inválido.', 'taxocommerce-import-export-lite' ) );
			}
			foreach ( array( 'download_1_url', 'download_2_url' ) as $key ) {
				if ( isset( $data[ $key ] ) ) {
					$url = esc_url_raw( $data[ $key ], array( 'http', 'https' ) );
					if ( ! $url || ! wp_http_validate_url( $url ) ) return new \WP_Error( 'invalid_download', __( 'URL de descarga HTTP(S) inválida.', 'taxocommerce-import-export-lite' ) );
				}
			}
			$attributes = NativeFields::prepare_attributes( $product, $data );
			return is_wp_error( $attributes ) ? $attributes : true;
		}
		if ( ! current_user_can( 'page' === $type ? 'edit_pages' : 'edit_posts' ) ) return new \WP_Error( 'forbidden', __( 'No tienes permisos para importar este contenido.', 'taxocommerce-import-export-lite' ) );
		if ( isset( $data['status'] ) && 'publish' === self::status( $data['status'] ) && ! current_user_can( 'publish_' . ( 'page' === $type ? 'pages' : 'posts' ) ) ) return new \WP_Error( 'publish_forbidden', __( 'Sin permiso para publicar.', 'taxocommerce-import-export-lite' ) );
		$id = ! empty( $data['slug'] ) ? get_page_by_path( sanitize_title( $data['slug'] ), OBJECT, $type ) : false;
		if ( ! $id && empty( $data['slug'] ) && empty( $data['id'] ) ) return new \WP_Error( 'identity_missing', __( 'Asigna slug para importar páginas o entradas sin duplicados.', 'taxocommerce-import-export-lite' ) );
		if ( ! $id && ! empty( $data['id'] ) && $type === get_post_type( absint( $data['id'] ) ) ) $id = get_post( absint( $data['id'] ) );
		if ( ! $id && 'update' === $mode ) return new \WP_Error( 'post_missing', __( 'Página o entrada no encontrada.', 'taxocommerce-import-export-lite' ) );
		if ( ! $id && empty( $data['title'] ) ) return new \WP_Error( 'title_missing', __( 'Falta el título de la página o entrada.', 'taxocommerce-import-export-lite' ) );
		return true;
	}

	/** Batch IDs from standard APIs; no SEO tables, custom taxonomies or direct SQL. */
	public static function export_ids( $type, $page, $per_page = 200 ) {
		if ( 'product' === $type ) {
			return wc_get_products( array( 'limit' => $per_page, 'page' => $page, 'status' => array( 'publish', 'private', 'draft', 'pending' ), 'return' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) );
		}
		if ( in_array( $type, array( 'category', 'post_tag', 'product_cat', 'product_tag' ), true ) ) {
			$terms = get_terms( array( 'taxonomy' => $type, 'hide_empty' => false, 'fields' => 'ids', 'number' => $per_page, 'offset' => ( $page - 1 ) * $per_page ) );
			return is_wp_error( $terms ) ? array() : $terms;
		}
		return get_posts( array( 'post_type' => $type, 'post_status' => array( 'publish', 'private', 'draft', 'pending' ), 'numberposts' => $per_page, 'offset' => ( $page - 1 ) * $per_page, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids' ) );
	}
}
