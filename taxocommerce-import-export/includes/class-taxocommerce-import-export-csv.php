<?php
/**
 * CSV import/export service.
 *
 * @package TaxoCommerceImportExport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streams catalog/content CSV files using WordPress/WooCommerce APIs.
 */
final class TaxoCommerce_Import_Export_CSV {

	/**
	 * Export request.
	 *
	 * @return void
	 */
	public function handle_export() {
		$this->guard();
		check_admin_referer( 'taxocommerce_import_export_export', 'taxocommerce_import_export_nonce' );

		$type = isset( $_POST['content_type'] ) ? sanitize_key( wp_unslash( $_POST['content_type'] ) ) : '';
		if ( ! in_array( $type, array( 'products', 'product_categories', 'posts', 'pages' ), true ) ) {
			wp_die( esc_html__( 'Tipo de contenido no válido.', 'taxocommerce-import-export' ) );
		}

		$filename = 'taxocommerce_' . $type . '_' . gmdate( 'Ymd_His' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		$output = $this->stream_open( 'php://output', 'w' );
		if ( false === $output ) {
			wp_die( esc_html__( 'No se pudo iniciar la exportación.', 'taxocommerce-import-export' ) );
		}
		$this->stream_write( $output, "\xEF\xBB\xBF" );

		if ( 'products' === $type ) {
			$this->export_products( $output );
		} elseif ( 'product_categories' === $type ) {
			$this->export_product_categories( $output );
		} else {
			$this->export_posts( $output, 'pages' === $type ? 'page' : 'post' );
		}

		$this->stream_close( $output );
		exit;
	}

	/**
	 * Import request.
	 *
	 * @return void
	 */
	public function handle_import() {
		$this->guard();
		check_admin_referer( 'taxocommerce_import_export_import', 'taxocommerce_import_export_nonce' );

		if ( class_exists( 'TaxoCommerce_Import_Export_Batch' ) && TaxoCommerce_Import_Export_Batch::is_running() ) {
			wp_die( esc_html__( 'Hay una importación por lotes en curso. Páusala o detenla antes de iniciar una importación individual.', 'taxocommerce-import-export' ) );
		}

		if ( empty( $_FILES['csv_file'] ) || ! is_array( $_FILES['csv_file'] ) ) {
			$this->redirect_error( __( 'No se ha recibido ningún CSV.', 'taxocommerce-import-export' ) );
		}

		$file = $_FILES['csv_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated locally.
		if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) ) {
			$this->redirect_error( __( 'La subida del CSV ha fallado.', 'taxocommerce-import-export' ) );
		}

		$name = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
		if ( 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			$this->redirect_error( __( 'El archivo debe tener extensión .csv.', 'taxocommerce-import-export' ) );
		}

		$handle = $this->stream_open( $file['tmp_name'], 'r' );
		if ( false === $handle ) {
			$this->redirect_error( __( 'No se pudo abrir el CSV.', 'taxocommerce-import-export' ) );
		}

		$delimiter = $this->detect_delimiter( $handle );
		$headers   = fgetcsv( $handle, 0, $delimiter, '"', '' );
		if ( ! is_array( $headers ) ) {
			$this->stream_close( $handle );
			$this->redirect_error( __( 'El CSV no contiene cabecera.', 'taxocommerce-import-export' ) );
		}

		$headers = array_map( array( $this, 'normalize_header' ), $headers );
		$implicit_record_type = '';
		if ( ! in_array( 'record_type', $headers, true ) ) {
			$implicit_record_type = $this->infer_record_type_from_headers( $headers );
			if ( ! $implicit_record_type ) {
				$this->stream_close( $handle );
				$this->redirect_error( __( 'No se puede identificar con seguridad el tipo de contenido del CSV. Añade record_type o usa cabeceras específicas del tipo.', 'taxocommerce-import-export' ) );
			}
		}

		$count = 0;
		while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) ) {
			if ( empty( array_filter( $row, 'strlen' ) ) ) {
				continue;
			}
			$row  = array_pad( $row, count( $headers ), '' );
			$data = array_combine( $headers, array_slice( $row, 0, count( $headers ) ) );
			if ( ! is_array( $data ) ) {
				continue;
			}
			if ( $implicit_record_type ) {
				$data['record_type']    = $implicit_record_type;
				$data['_partial_update'] = '1';
			}

			$result = $this->import_row( $data );
			if ( is_wp_error( $result ) ) {
				$this->stream_close( $handle );
				$this->redirect_error(
					sprintf(
						/* translators: 1: row number, 2: error message. */
						__( 'Error en la fila %1$d: %2$s', 'taxocommerce-import-export' ),
						$count + 2,
						$result->get_error_message()
					)
				);
			}
			$count++;
		}
		$this->stream_close( $handle );

		$url = add_query_arg(
			array(
				'page'         => 'taxocommerce-import-export',
				'tc_ie_result' => 'ok',
				'tc_ie_count'  => $count,
				'tc_ie_kind'   => 'csv',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Products export.
	 *
	 * @param resource $output CSV stream.
	 * @return void
	 */
	private function export_products( $output ) {
		fputcsv(
			$output,
			array(
				'record_type', 'id', 'product_type', 'status', 'name', 'slug', 'sku', 'regular_price', 'sale_price',
				'stock_status', 'manage_stock', 'stock_quantity', 'short_description', 'description', 'categories_json',
				'tags_json', 'attributes_json', 'external_url', 'button_text',
				'brand_taxonomy', 'brand_ids', 'brand', 'manufacturer', 'supplier',
				'supplier_external_id', 'supplier_catalog_id', 'supplier_category', 'supplier_price',
			),
			',',
			'"',
			''
		);

		$page = 1;
		do {
			$query = new WP_Query(
				array(
					'post_type'              => 'product',
					'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'trash' ),
					'posts_per_page'         => 250,
					'paged'                  => $page,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'fields'                 => 'ids',
					'no_found_rows'          => false,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			foreach ( $query->posts as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( ! $product ) {
					continue;
				}

				$attributes = array();
				foreach ( $product->get_attributes() as $attribute ) {
					if ( ! is_a( $attribute, 'WC_Product_Attribute' ) ) {
						continue;
					}
					$values = $attribute->is_taxonomy()
						? wc_get_product_terms( $product_id, $attribute->get_name(), array( 'fields' => 'names' ) )
						: $attribute->get_options();

					$attributes[] = array(
						'name'      => $attribute->get_name(),
						'visible'   => $attribute->get_visible(),
						'variation' => $attribute->get_variation(),
						'options'   => array_values( array_map( 'strval', (array) $values ) ),
					);
				}

				$external_url = '';
				$button_text  = '';
				if ( $product->is_type( 'external' ) && is_a( $product, 'WC_Product_External' ) ) {
					$external_url = $product->get_product_url( 'edit' );
					$button_text  = $product->get_button_text( 'edit' );
				}

				$brand = $this->product_brand_export_payload( $product_id );

				fputcsv(
					$output,
					array(
						'product',
						$product_id,
						$product->get_type(),
						$product->get_status( 'edit' ),
						$product->get_name( 'edit' ),
						$product->get_slug( 'edit' ),
						$product->get_sku( 'edit' ),
						$product->get_regular_price( 'edit' ),
						$product->get_sale_price( 'edit' ),
						$product->get_stock_status( 'edit' ),
						$product->get_manage_stock( 'edit' ) ? '1' : '0',
						$product->get_stock_quantity( 'edit' ),
						$product->get_short_description( 'edit' ),
						$product->get_description( 'edit' ),
						wp_json_encode( $this->terms_payload( $product_id, 'product_cat' ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
						wp_json_encode( $this->terms_payload( $product_id, 'product_tag' ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
						wp_json_encode( $attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
						$external_url,
						$button_text,
						(string) ( $brand['taxonomy'] ?? '' ),
						implode( ',', (array) ( $brand['ids'] ?? array() ) ),
						implode( ' | ', (array) ( $brand['names'] ?? array() ) ),
						(string) get_post_meta( $product_id, '_seo_fabricante', true ),
						(string) get_post_meta( $product_id, '_seo_proveedor', true ),
						(string) get_post_meta( $product_id, '_seo_proveedor_id_externo', true ),
						(string) get_post_meta( $product_id, '_seo_proveedor_catalogo_id', true ),
						(string) get_post_meta( $product_id, '_seo_categoria_proveedor', true ),
						(string) get_post_meta( $product_id, '_seo_precio_proveedor', true ),
					),
					',',
					'"',
					''
				);
			}

			$page++;
			$max_pages = (int) $query->max_num_pages;
			wp_reset_postdata();
		} while ( $page <= $max_pages );
	}

	/**
	 * Product categories export.
	 *
	 * @param resource $output CSV stream.
	 * @return void
	 */
	private function export_product_categories( $output ) {
		fputcsv( $output, array( 'record_type', 'id', 'name', 'slug', 'parent_id', 'description', 'image_id' ), ',', '"', '' );

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return;
		}

		foreach ( $terms as $term ) {
			fputcsv(
				$output,
				array(
					'product_category',
					$term->term_id,
					$term->name,
					$term->slug,
					$term->parent,
					$term->description,
					absint( get_term_meta( $term->term_id, 'thumbnail_id', true ) ),
				)
			);
		}
	}

	/**
	 * Posts/pages export.
	 *
	 * @param resource $output CSV stream.
	 * @param string   $post_type Post type.
	 * @return void
	 */
	private function export_posts( $output, $post_type ) {
		fputcsv( $output, array( 'record_type', 'id', 'status', 'title', 'slug', 'excerpt', 'content', 'date_gmt', 'author_id', 'categories_json', 'tags_json' ), ',', '"', '' );

		$page = 1;
		do {
			$query = new WP_Query(
				array(
					'post_type'      => $post_type,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
					'posts_per_page' => 250,
					'paged'          => $page,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);

			foreach ( $query->posts as $post ) {
				fputcsv(
					$output,
					array(
						$post_type,
						$post->ID,
						$post->post_status,
						$post->post_title,
						$post->post_name,
						$post->post_excerpt,
						$post->post_content,
						get_post_time( 'Y-m-d H:i:s', true, $post ),
						$post->post_author,
						wp_json_encode( 'post' === $post_type ? $this->terms_payload( $post->ID, 'category' ) : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
						wp_json_encode( 'post' === $post_type ? $this->terms_payload( $post->ID, 'post_tag' ) : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					),
					',',
					'"',
					''
				);
			}

			$page++;
			$max_pages = (int) $query->max_num_pages;
			wp_reset_postdata();
		} while ( $page <= $max_pages );
	}

	/**
	 * Public row adapter used by the modular batch queue.
	 *
	 * @param array $data Row.
	 * @param array $context Queue context.
	 * @return true|WP_Error
	 */
	public function import_row_for_batch( $data, $context = array() ) {
		$type     = sanitize_key( (string) ( $data['record_type'] ?? '' ) );
		$expected = sanitize_key( (string) ( $context['type'] ?? '' ) );

		if ( ! $type && $expected ) {
			$data['record_type']     = $expected;
			$data['_partial_update'] = '1';
			$type = $expected;
		}

		if ( $expected && $type !== $expected ) {
			return new WP_Error(
				'taxocommerce_ie_batch_record_type',
				__( 'La fila no coincide con el miniimportador asignado al archivo.', 'taxocommerce-import-export' )
			);
		}

		return $this->import_row( $data );
	}

	/**
	 * Normalize a CSV header for the batch coordinator.
	 *
	 * @param array $headers Raw headers.
	 * @return array
	 */
	public function normalize_headers_for_batch( $headers ) {
		return array_map( array( $this, 'normalize_header' ), (array) $headers );
	}

	/**
	 * Import one row.
	 *
	 * @param array $data Row.
	 * @return true|WP_Error
	 */
	private function import_row( $data ) {
		$type = sanitize_key( (string) ( isset( $data['record_type'] ) ? $data['record_type'] : '' ) );

		if ( 'product' === $type ) {
			return $this->import_product( $data );
		}
		if ( 'product_category' === $type ) {
			return $this->import_product_category( $data );
		}
		if ( in_array( $type, array( 'post', 'page' ), true ) ) {
			return $this->import_post( $data, $type );
		}

		return new WP_Error( 'taxocommerce_ie_record_type', __( 'record_type no reconocido.', 'taxocommerce-import-export' ) );
	}

	/**
	 * Product import.
	 *
	 * @param array $data Row.
	 * @return true|WP_Error
	 */
	private function import_product( $data ) {
		$id           = absint( isset( $data['id'] ) ? $data['id'] : 0 );
		$this->should_apply_field( $data, 'sku', $partial )      = array_key_exists( 'sku', $data );
		$sku          = $has_sku ? sanitize_text_field( (string) $data['sku'] ) : '';
		$partial      = ! empty( $data['_partial_update'] );
		$product_type = sanitize_key( (string) ( isset( $data['product_type'] ) ? $data['product_type'] : 'simple' ) );

		if ( $partial && ! $id && ! $sku ) {
			return new WP_Error(
				'taxocommerce_ie_partial_identifier',
				__( 'Un CSV parcial de productos debe incluir product_id/id o SKU para localizar el producto existente.', 'taxocommerce-import-export' )
			);
		}

		$product = $id ? wc_get_product( $id ) : false;
		if ( ! $product && $sku ) {
			$found_id = wc_get_product_id_by_sku( $sku );
			$product  = $found_id ? wc_get_product( $found_id ) : false;
		}

		if ( ! $product && $partial ) {
			return new WP_Error(
				'taxocommerce_ie_partial_missing_product',
				__( 'El CSV parcial sólo puede actualizar productos existentes; no se encontró el producto indicado.', 'taxocommerce-import-export' )
			);
		}

		if ( ! $product ) {
			if ( 'external' === $product_type ) {
				$product = new WC_Product_External();
			} elseif ( 'simple' === $product_type ) {
				$product = new WC_Product_Simple();
			} else {
				return new WP_Error( 'taxocommerce_ie_product_type', __( 'La versión inicial sólo crea productos simples o externos.', 'taxocommerce-import-export' ) );
			}
			if ( ! array_key_exists( 'name', $data ) || '' === trim( (string) $data['name'] ) ) {
				return new WP_Error( 'taxocommerce_ie_product_name', __( 'Para crear un producto nuevo el CSV debe incluir name/titulo.', 'taxocommerce-import-export' ) );
			}
		}

		if ( $this->should_apply_field( $data, 'status', $partial ) ) {
			$status = sanitize_key( (string) $data['status'] );
			if ( 'trash' === $status && $product->get_id() ) {
				return wp_trash_post( $product->get_id() ) ? true : new WP_Error( 'taxocommerce_ie_product_trash', __( 'No se pudo mover el producto a la papelera.', 'taxocommerce-import-export' ) );
			}
			if ( ! in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
				return new WP_Error( 'taxocommerce_ie_product_status', __( 'Estado de producto no válido.', 'taxocommerce-import-export' ) );
			}
		} elseif ( ! $product->get_id() ) {
			$status = 'draft';
		} else {
			$status = '';
		}

		try {
			if ( $this->should_apply_field( $data, 'name', $partial ) ) {
				$name = trim( sanitize_text_field( (string) $data['name'] ) );
				if ( '' === $name ) {
					return new WP_Error( 'taxocommerce_ie_product_name_empty', __( 'El nombre del producto no puede quedar vacío.', 'taxocommerce-import-export' ) );
				}
				$product->set_name( $name );
			}
			if ( $this->should_apply_field( $data, 'slug', $partial ) ) {
				$product->set_slug( sanitize_title( (string) $data['slug'] ) );
			}
			if ( '' !== $status ) {
				$product->set_status( $status );
			}
			if ( $has_sku ) {
				$product->set_sku( $sku );
			}
			if ( $this->should_apply_field( $data, 'regular_price', $partial ) ) {
				$product->set_regular_price( wc_format_decimal( (string) $data['regular_price'] ) );
			}
			if ( $this->should_apply_field( $data, 'sale_price', $partial ) ) {
				$product->set_sale_price( wc_format_decimal( (string) $data['sale_price'] ) );
			}
			if ( $this->should_apply_field( $data, 'stock_status', $partial ) ) {
				$product->set_stock_status( $this->stock_status( $data['stock_status'] ) );
			}
			if ( $this->should_apply_field( $data, 'manage_stock', $partial ) ) {
				$product->set_manage_stock( '1' === (string) $data['manage_stock'] );
			}
			if ( $this->should_apply_field( $data, 'stock_quantity', $partial ) ) {
				$product->set_stock_quantity( '' === (string) $data['stock_quantity'] ? null : wc_stock_amount( $data['stock_quantity'] ) );
			}
			if ( $this->should_apply_field( $data, 'short_description', $partial ) ) {
				$product->set_short_description( wp_kses_post( (string) $data['short_description'] ) );
			}
			if ( $this->should_apply_field( $data, 'description', $partial ) ) {
				$product->set_description( wp_kses_post( (string) $data['description'] ) );
			}
			if ( $this->should_apply_field( $data, 'categories_json', $partial ) ) {
				$product->set_category_ids( $this->resolve_terms_from_json( $data['categories_json'], 'product_cat' ) );
			}
			if ( $this->should_apply_field( $data, 'tags_json', $partial ) ) {
				$product->set_tag_ids( $this->resolve_terms_from_json( $data['tags_json'], 'product_tag' ) );
			}
			if ( $this->should_apply_field( $data, 'attributes_json', $partial ) ) {
				$product->set_attributes( $this->attributes_from_json( $data['attributes_json'] ) );
			}

			if ( is_a( $product, 'WC_Product_External' ) ) {
				if ( $this->should_apply_field( $data, 'external_url', $partial ) ) {
					$product->set_product_url( esc_url_raw( (string) $data['external_url'] ) );
				}
				if ( $this->should_apply_field( $data, 'button_text', $partial ) ) {
					$product->set_button_text( sanitize_text_field( (string) $data['button_text'] ) );
				}
			}

			$product_id = absint( $product->save() );
			if ( ! $product_id ) {
				return new WP_Error( 'taxocommerce_ie_product_save_id', __( 'WooCommerce no devolvió un ID al guardar el producto.', 'taxocommerce-import-export' ) );
			}

			$brand_provider = $this->apply_brand_provider_fields( $product_id, $data );
			if ( is_wp_error( $brand_provider ) ) {
				return $brand_provider;
			}
		} catch ( Exception $exception ) {
			return new WP_Error( 'taxocommerce_ie_product_save', sanitize_text_field( $exception->getMessage() ) );
		}

		return true;
	}

	/**
	 * Whether a CSV field should be applied.
	 *
	 * In partial correction CSVs, blank cells mean "leave unchanged".
	 * Full round-trip imports still apply blank values when the column exists.
	 *
	 * @param array  $data Row.
	 * @param string $field Field.
	 * @param bool   $partial Partial correction mode.
	 * @return bool
	 */
	private function should_apply_field( $data, $field, $partial ) {
		if ( ! array_key_exists( $field, $data ) ) {
			return false;
		}
		if ( ! $partial ) {
			return true;
		}
		return '' !== trim( (string) $data[ $field ] );
	}

	/**
	 * Apply brand/provider fields only when their columns are present.
	 *
	 * Unknown brands are never created implicitly.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $data CSV row.
	 * @return true|WP_Error
	 */
	private function apply_brand_provider_fields( $product_id, $data ) {
		$product_id = absint( $product_id );
		$brand_keys = array(
			'brand_taxonomy', 'brand_ids', 'brand', 'manufacturer', 'supplier',
			'supplier_external_id', 'supplier_catalog_id', 'supplier_category', 'supplier_price',
		);
		if ( ! array_intersect( array_keys( (array) $data ), $brand_keys ) ) {
			return true;
		}

		$taxonomy = array_key_exists( 'brand_taxonomy', $data )
			? sanitize_key( (string) $data['brand_taxonomy'] )
			: sanitize_key( (string) get_post_meta( $product_id, '_seo_taxonomia_marca', true ) );

		if ( ! $taxonomy && taxonomy_exists( 'product_brand' ) ) {
			$taxonomy = 'product_brand';
		}

		$has_brand_values = ( array_key_exists( 'brand_ids', $data ) && '' !== trim( (string) $data['brand_ids'] ) )
			|| ( array_key_exists( 'brand', $data ) && '' !== trim( (string) $data['brand'] ) );

		if ( $has_brand_values ) {
			if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
				return new WP_Error( 'taxocommerce_ie_brand_taxonomy', __( 'La taxonomía de marca indicada no existe.', 'taxocommerce-import-export' ) );
			}

			$brand_ids = $this->resolve_existing_brand_terms(
				$taxonomy,
				isset( $data['brand_ids'] ) ? $data['brand_ids'] : '',
				isset( $data['brand'] ) ? $data['brand'] : ''
			);
			if ( is_wp_error( $brand_ids ) ) {
				return $brand_ids;
			}

			$assigned = wp_set_object_terms( $product_id, $brand_ids, $taxonomy, false );
			if ( is_wp_error( $assigned ) ) {
				return $assigned;
			}

			update_post_meta( $product_id, '_seo_taxonomia_marca', $taxonomy );
			if ( array_key_exists( 'brand', $data ) && '' !== trim( (string) $data['brand'] ) ) {
				update_post_meta( $product_id, '_seo_marca_proveedor', sanitize_text_field( (string) $data['brand'] ) );
			}
		}

		$meta_map = array(
			'manufacturer'         => '_seo_fabricante',
			'supplier'             => '_seo_proveedor',
			'supplier_external_id' => '_seo_proveedor_id_externo',
			'supplier_catalog_id'  => '_seo_proveedor_catalogo_id',
			'supplier_category'    => '_seo_categoria_proveedor',
			'supplier_price'       => '_seo_precio_proveedor',
		);
		foreach ( $meta_map as $field => $meta_key ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) $data[ $field ] );
			if ( '' !== $value ) {
				update_post_meta( $product_id, $meta_key, $value );
			}
		}

		return true;
	}

	/**
	 * Resolve brand terms without creating new terms from a correction CSV.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $ids_raw IDs.
	 * @param string $names_raw Names.
	 * @return int[]|WP_Error
	 */
	private function resolve_existing_brand_terms( $taxonomy, $ids_raw, $names_raw ) {
		$ids = array();
		foreach ( preg_split( '/[|,;\\s]+/', trim( (string) $ids_raw ) ) as $raw_id ) {
			$id = absint( $raw_id );
			if ( ! $id ) {
				continue;
			}
			$term = get_term( $id, $taxonomy );
			if ( ! $term || is_wp_error( $term ) ) {
				return new WP_Error(
					'taxocommerce_ie_brand_id',
					sprintf(
						/* translators: %d: existing brand term ID from the CSV. */
						__( 'La marca ID %d no existe en la taxonomía indicada.', 'taxocommerce-import-export' ),
						$id
					)
				);
			}
			$ids[] = $id;
		}

		if ( ! $ids && '' !== trim( (string) $names_raw ) ) {
			$names = preg_split( '/\\s*\\|\\s*/', trim( (string) $names_raw ) );
			foreach ( $names as $name ) {
				$name = trim( sanitize_text_field( $name ) );
				if ( '' === $name ) {
					continue;
				}
				$term = get_term_by( 'name', $name, $taxonomy );
				if ( ! $term ) {
					$term = get_term_by( 'slug', sanitize_title( $name ), $taxonomy );
				}
				if ( ! $term || is_wp_error( $term ) ) {
					return new WP_Error(
						'taxocommerce_ie_brand_name',
						sprintf(
							/* translators: %s: brand name from the CSV. */
							__( 'La marca «%s» no existe; el importador parcial no crea marcas nuevas.', 'taxocommerce-import-export' ),
							$name
						)
					);
				}
				$ids[] = absint( $term->term_id );
			}
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( ! $ids ) {
			return new WP_Error( 'taxocommerce_ie_brand_empty', __( 'No se pudo resolver ninguna marca existente.', 'taxocommerce-import-export' ) );
		}
		return $ids;
	}

	/**
	 * Product category import.
	 *
	 * @param array $data Row.
	 * @return true|WP_Error
	 */
	private function import_product_category( $data ) {
		$id          = absint( isset( $data['id'] ) ? $data['id'] : 0 );
		$name        = sanitize_text_field( (string) ( isset( $data['name'] ) ? $data['name'] : '' ) );
		$slug        = sanitize_title( (string) ( isset( $data['slug'] ) ? $data['slug'] : '' ) );
		$parent_id   = absint( isset( $data['parent_id'] ) ? $data['parent_id'] : 0 );
		$description = wp_kses_post( (string) ( isset( $data['description'] ) ? $data['description'] : '' ) );
		$image_id    = absint( isset( $data['image_id'] ) ? $data['image_id'] : 0 );

		if ( ! $name ) {
			return new WP_Error( 'taxocommerce_ie_category_name', __( 'La categoría necesita nombre.', 'taxocommerce-import-export' ) );
		}

		$term = $id ? get_term( $id, 'product_cat' ) : null;
		if ( $term && ! is_wp_error( $term ) ) {
			$result  = wp_update_term( $id, 'product_cat', array( 'name' => $name, 'slug' => $slug, 'parent' => $parent_id, 'description' => $description ) );
			$term_id = $id;
		} else {
			$result  = wp_insert_term( $name, 'product_cat', array( 'slug' => $slug, 'parent' => $parent_id, 'description' => $description ) );
			$term_id = is_wp_error( $result ) ? 0 : absint( $result['term_id'] );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $term_id && $image_id ) {
			update_term_meta( $term_id, 'thumbnail_id', $image_id );
		}
		return true;
	}

	/**
	 * Post/page import.
	 *
	 * @param array  $data Row.
	 * @param string $post_type Post type.
	 * @return true|WP_Error
	 */
	private function import_post( $data, $post_type ) {
		$id     = absint( isset( $data['id'] ) ? $data['id'] : 0 );
		$status = sanitize_key( (string) ( isset( $data['status'] ) ? $data['status'] : 'draft' ) );

		if ( 'trash' === $status && $id && get_post( $id ) ) {
			return wp_trash_post( $id ) ? true : new WP_Error( 'taxocommerce_ie_post_trash', __( 'No se pudo mover el contenido a la papelera.', 'taxocommerce-import-export' ) );
		}
		if ( ! in_array( $status, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ) {
			$status = 'draft';
		}

		$postarr = array(
			'post_type'    => $post_type,
			'post_status'  => $status,
			'post_title'   => sanitize_text_field( (string) ( isset( $data['title'] ) ? $data['title'] : '' ) ),
			'post_name'    => sanitize_title( (string) ( isset( $data['slug'] ) ? $data['slug'] : '' ) ),
			'post_excerpt' => wp_kses_post( (string) ( isset( $data['excerpt'] ) ? $data['excerpt'] : '' ) ),
			'post_content' => wp_kses_post( (string) ( isset( $data['content'] ) ? $data['content'] : '' ) ),
			'post_author'  => absint( isset( $data['author_id'] ) ? $data['author_id'] : get_current_user_id() ),
		);
		if ( $id && get_post( $id ) && $post_type === get_post_type( $id ) ) {
			$postarr['ID'] = $id;
		}

		$date_gmt = sanitize_text_field( (string) ( isset( $data['date_gmt'] ) ? $data['date_gmt'] : '' ) );
		if ( $date_gmt ) {
			$postarr['post_date_gmt'] = $date_gmt;
			$postarr['post_date']     = get_date_from_gmt( $date_gmt );
		}

		$result = isset( $postarr['ID'] ) ? wp_update_post( $postarr, true ) : wp_insert_post( $postarr, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( 'post' === $post_type ) {
			wp_set_post_terms( $result, $this->resolve_terms_from_json( isset( $data['categories_json'] ) ? $data['categories_json'] : '', 'category' ), 'category', false );
			wp_set_post_terms( $result, $this->resolve_terms_from_json( isset( $data['tags_json'] ) ? $data['tags_json'] : '', 'post_tag' ), 'post_tag', false );
		}
		return true;
	}

	/**
	 * Product brand/provider taxonomy payload for round-trip exports.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private function product_brand_export_payload( $product_id ) {
		$taxonomy = sanitize_key( (string) get_post_meta( $product_id, '_seo_taxonomia_marca', true ) );
		if ( ! $taxonomy && taxonomy_exists( 'product_brand' ) ) {
			$taxonomy = 'product_brand';
		}
		if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return array( 'taxonomy'=>'', 'ids'=>array(), 'names'=>array() );
		}

		$terms = wp_get_object_terms( $product_id, $taxonomy );
		if ( is_wp_error( $terms ) ) {
			return array( 'taxonomy'=>$taxonomy, 'ids'=>array(), 'names'=>array() );
		}

		$ids = array();
		$names = array();
		foreach ( (array) $terms as $term ) {
			$ids[] = absint( $term->term_id );
			$names[] = (string) $term->name;
		}
		return array(
			'taxonomy'=>$taxonomy,
			'ids'=>array_values( array_unique( array_filter( $ids ) ) ),
			'names'=>array_values( array_unique( array_filter( $names, 'strlen' ) ) ),
		);
	}

	/**
	 * Export taxonomy payload.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $taxonomy Taxonomy.
	 * @return array
	 */
	private function terms_payload( $object_id, $taxonomy ) {
		$terms = wp_get_object_terms( $object_id, $taxonomy );
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$out = array();
		foreach ( $terms as $term ) {
			$out[] = array(
				'id'   => absint( $term->term_id ),
				'slug' => $term->slug,
				'name' => $term->name,
			);
		}
		return $out;
	}

	/**
	 * Resolve/create terms from JSON payload.
	 *
	 * @param string $json JSON.
	 * @param string $taxonomy Taxonomy.
	 * @return int[]
	 */
	private function resolve_terms_from_json( $json, $taxonomy ) {
		$items = json_decode( (string) $json, true );
		if ( ! is_array( $items ) ) {
			return array();
		}

		$ids = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$term = null;
			$id   = absint( isset( $item['id'] ) ? $item['id'] : 0 );
			$slug = sanitize_title( isset( $item['slug'] ) ? $item['slug'] : '' );
			$name = sanitize_text_field( isset( $item['name'] ) ? $item['name'] : '' );

			if ( $id ) {
				$term = get_term( $id, $taxonomy );
			}
			if ( ( ! $term || is_wp_error( $term ) ) && $slug ) {
				$term = get_term_by( 'slug', $slug, $taxonomy );
			}
			if ( ( ! $term || is_wp_error( $term ) ) && $name ) {
				$term = get_term_by( 'name', $name, $taxonomy );
			}
			if ( ( ! $term || is_wp_error( $term ) ) && $name ) {
				$created = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
				if ( ! is_wp_error( $created ) ) {
					$ids[] = absint( $created['term_id'] );
				}
				continue;
			}
			if ( $term && ! is_wp_error( $term ) ) {
				$ids[] = absint( $term->term_id );
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Convert JSON attributes to WC objects.
	 *
	 * @param string $json JSON.
	 * @return array
	 */
	private function attributes_from_json( $json ) {
		$items = json_decode( (string) $json, true );
		if ( ! is_array( $items ) ) {
			return array();
		}

		$out      = array();
		$position = 0;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$name = sanitize_text_field( (string) ( isset( $item['name'] ) ? $item['name'] : '' ) );
			if ( ! $name ) {
				continue;
			}

			$options = array_map( 'sanitize_text_field', (array) ( isset( $item['options'] ) ? $item['options'] : array() ) );
			$attr    = new WC_Product_Attribute();
			$attr_id = taxonomy_exists( $name ) ? wc_attribute_taxonomy_id_by_name( $name ) : 0;

			$attr->set_id( absint( $attr_id ) );
			$attr->set_name( $name );
			$attr->set_options( $options );
			$attr->set_position( $position++ );
			$attr->set_visible( ! empty( $item['visible'] ) );
			$attr->set_variation( ! empty( $item['variation'] ) );
			$out[] = $attr;
		}

		return $out;
	}

	/**
	 * Detect CSV delimiter without consuming the stream.
	 *
	 * @param resource $handle Stream.
	 * @return string
	 */
	private function detect_delimiter( $handle ) {
		$position = ftell( $handle );
		$line     = fgets( $handle );
		fseek( $handle, $position );

		if ( false === $line ) {
			return ',';
		}

		$scores = array();
		foreach ( array( ';', ',', "\t" ) as $candidate ) {
			$scores[ $candidate ] = count( str_getcsv( $line, $candidate, '"', '' ) );
		}
		arsort( $scores, SORT_NUMERIC );
		$delimiter = (string) key( $scores );

		return in_array( $delimiter, array( ';', ',', "\t" ), true ) ? $delimiter : ',';
	}

	/**
	 * Open a native stream required by fgetcsv/fputcsv incremental processing.
	 *
	 * @param string $path Path or PHP stream.
	 * @param string $mode Open mode.
	 * @return resource|false
	 */
	private function stream_open( $path, $mode ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CSV streaming requires a native resource and avoids loading large files into memory.
		return fopen( $path, $mode );
	}

	/**
	 * Write bytes to an incremental CSV stream.
	 *
	 * @param resource $handle Stream.
	 * @param string   $data Data.
	 * @return int|false
	 */
	private function stream_write( $handle, $data ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Same native CSV stream returned by stream_open().
		return fwrite( $handle, $data );
	}

	/**
	 * Close an incremental CSV stream.
	 *
	 * @param resource $handle Stream.
	 * @return bool
	 */
	private function stream_close( $handle ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Explicit close of the native incremental CSV stream.
		return fclose( $handle );
	}

	/**
	 * Normalize header.
	 *
	 * @param string $header Header.
	 * @return string
	 */
	private function normalize_header( $header ) {
		$header = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header );
		$key    = sanitize_key( trim( $header ) );

		$aliases = array(
			'product_id'            => 'id',
			'producto_id'           => 'id',
			'post_id'               => 'id',
			'titulo'                => 'name',
			'nombre'                => 'name',
			'title'                 => 'name',
			'estado'                => 'status',
			'tipo_producto'         => 'product_type',
			'precio_normal'         => 'regular_price',
			'precio_rebajado'       => 'sale_price',
			'estado_stock'          => 'stock_status',
			'gestionar_stock'       => 'manage_stock',
			'cantidad_stock'        => 'stock_quantity',
			'excerpt'               => 'short_description',
			'resumen'               => 'short_description',
			'categories_json'       => 'categories_json',
			'etiquetas_json'        => 'tags_json',
			'atributos_wc_json'     => 'attributes_json',
			'url_externa'           => 'external_url',
			'texto_boton'           => 'button_text',
			'marca_taxonomia'       => 'brand_taxonomy',
			'marca_ids'             => 'brand_ids',
			'marca'                 => 'brand',
			'fabricante'            => 'manufacturer',
			'proveedor'             => 'supplier',
			'proveedor_id_externo'  => 'supplier_external_id',
			'proveedor_catalogo_id' => 'supplier_catalog_id',
			'categoria_proveedor'   => 'supplier_category',
			'precio_proveedor'      => 'supplier_price',
		);

		return isset( $aliases[ $key ] ) ? $aliases[ $key ] : $key;
	}

	/**
	 * Infer a safe record type from distinctive headers.
	 *
	 * Only unambiguous correction CSVs are accepted without record_type.
	 *
	 * @param string[] $headers Normalized headers.
	 * @return string
	 */
	public function infer_record_type_from_headers( $headers ) {
		$headers = array_values( array_unique( array_map( 'sanitize_key', (array) $headers ) ) );

		$product_markers = array(
			'sku', 'product_type', 'regular_price', 'sale_price', 'stock_status',
			'manage_stock', 'stock_quantity', 'short_description', 'categories_json',
			'tags_json', 'attributes_json', 'external_url', 'button_text',
			'brand_taxonomy', 'brand_ids', 'brand', 'manufacturer', 'supplier',
			'supplier_external_id', 'supplier_catalog_id', 'supplier_category', 'supplier_price',
		);
		if ( array_intersect( $headers, $product_markers ) ) {
			return 'product';
		}

		if ( in_array( 'parent_id', $headers, true ) || in_array( 'image_id', $headers, true ) ) {
			return 'product_category';
		}

		if ( in_array( 'author_id', $headers, true ) || in_array( 'date_gmt', $headers, true ) || in_array( 'content', $headers, true ) ) {
			return 'post';
		}

		return '';
	}

	/**
	 * Validate stock status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function stock_status( $status ) {
		$status = sanitize_key( (string) $status );
		return in_array( $status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ? $status : 'instock';
	}

	/**
	 * Redirect import error.
	 *
	 * @param string $message Error.
	 * @return void
	 */
	private function redirect_error( $message ) {
		$url = add_query_arg(
			array(
				'page'         => 'taxocommerce-import-export',
				'tc_ie_result' => 'error',
				'tc_ie_error'  => sanitize_text_field( $message ),
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Permission guard.
	 *
	 * @return void
	 */
	private function guard() {
		$capability = (string) apply_filters( 'taxocommerce_import_export_capability', 'manage_woocommerce' );
		if ( ! current_user_can( $capability ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para importar o exportar.', 'taxocommerce-import-export' ) );
		}
	}
}
