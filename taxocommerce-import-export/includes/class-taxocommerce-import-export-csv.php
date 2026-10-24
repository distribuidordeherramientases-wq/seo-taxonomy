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

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			wp_die( esc_html__( 'No se pudo iniciar la exportación.', 'taxocommerce-import-export' ) );
		}
		fwrite( $output, "\xEF\xBB\xBF" );

		if ( 'products' === $type ) {
			$this->export_products( $output );
		} elseif ( 'product_categories' === $type ) {
			$this->export_product_categories( $output );
		} else {
			$this->export_posts( $output, 'pages' === $type ? 'page' : 'post' );
		}

		fclose( $output );
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

		$handle = fopen( $file['tmp_name'], 'r' );
		if ( false === $handle ) {
			$this->redirect_error( __( 'No se pudo abrir el CSV.', 'taxocommerce-import-export' ) );
		}

		$headers = fgetcsv( $handle );
		if ( ! is_array( $headers ) ) {
			fclose( $handle );
			$this->redirect_error( __( 'El CSV no contiene cabecera.', 'taxocommerce-import-export' ) );
		}

		$headers = array_map( array( $this, 'normalize_header' ), $headers );
		if ( ! in_array( 'record_type', $headers, true ) ) {
			fclose( $handle );
			$this->redirect_error( __( 'El CSV no parece generado por TaxoCommerce Import-Export.', 'taxocommerce-import-export' ) );
		}

		$count = 0;
		while ( false !== ( $row = fgetcsv( $handle ) ) ) {
			if ( empty( array_filter( $row, 'strlen' ) ) ) {
				continue;
			}
			$row  = array_pad( $row, count( $headers ), '' );
			$data = array_combine( $headers, array_slice( $row, 0, count( $headers ) ) );
			if ( ! is_array( $data ) ) {
				continue;
			}

			$result = $this->import_row( $data );
			if ( is_wp_error( $result ) ) {
				fclose( $handle );
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
		fclose( $handle );

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
			)
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
					)
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
		fputcsv( $output, array( 'record_type', 'id', 'name', 'slug', 'parent_id', 'description', 'image_id' ) );

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
		fputcsv( $output, array( 'record_type', 'id', 'status', 'title', 'slug', 'excerpt', 'content', 'date_gmt', 'author_id', 'categories_json', 'tags_json' ) );

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
					)
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
		$sku          = sanitize_text_field( (string) ( isset( $data['sku'] ) ? $data['sku'] : '' ) );
		$product_type = sanitize_key( (string) ( isset( $data['product_type'] ) ? $data['product_type'] : 'simple' ) );

		$product = $id ? wc_get_product( $id ) : false;
		if ( ! $product && $sku ) {
			$found_id = wc_get_product_id_by_sku( $sku );
			$product  = $found_id ? wc_get_product( $found_id ) : false;
		}

		if ( ! $product ) {
			if ( 'external' === $product_type ) {
				$product = new WC_Product_External();
			} elseif ( 'simple' === $product_type ) {
				$product = new WC_Product_Simple();
			} else {
				return new WP_Error( 'taxocommerce_ie_product_type', __( 'La versión inicial sólo crea productos simples o externos.', 'taxocommerce-import-export' ) );
			}
		}

		$status = sanitize_key( (string) ( isset( $data['status'] ) ? $data['status'] : 'draft' ) );
		if ( 'trash' === $status && $product->get_id() ) {
			return wp_trash_post( $product->get_id() ) ? true : new WP_Error( 'taxocommerce_ie_product_trash', __( 'No se pudo mover el producto a la papelera.', 'taxocommerce-import-export' ) );
		}
		if ( ! in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
			$status = 'draft';
		}

		try {
			$product->set_name( sanitize_text_field( (string) ( isset( $data['name'] ) ? $data['name'] : '' ) ) );
			$product->set_slug( sanitize_title( (string) ( isset( $data['slug'] ) ? $data['slug'] : '' ) ) );
			$product->set_status( $status );
			$product->set_sku( $sku );
			$product->set_regular_price( wc_format_decimal( (string) ( isset( $data['regular_price'] ) ? $data['regular_price'] : '' ) ) );
			$product->set_sale_price( wc_format_decimal( (string) ( isset( $data['sale_price'] ) ? $data['sale_price'] : '' ) ) );
			$product->set_stock_status( $this->stock_status( isset( $data['stock_status'] ) ? $data['stock_status'] : 'instock' ) );
			$product->set_manage_stock( '1' === (string) ( isset( $data['manage_stock'] ) ? $data['manage_stock'] : '0' ) );
			$product->set_stock_quantity( '' === (string) ( isset( $data['stock_quantity'] ) ? $data['stock_quantity'] : '' ) ? null : wc_stock_amount( $data['stock_quantity'] ) );
			$product->set_short_description( wp_kses_post( (string) ( isset( $data['short_description'] ) ? $data['short_description'] : '' ) ) );
			$product->set_description( wp_kses_post( (string) ( isset( $data['description'] ) ? $data['description'] : '' ) ) );
			$product->set_category_ids( $this->resolve_terms_from_json( isset( $data['categories_json'] ) ? $data['categories_json'] : '', 'product_cat' ) );
			$product->set_tag_ids( $this->resolve_terms_from_json( isset( $data['tags_json'] ) ? $data['tags_json'] : '', 'product_tag' ) );
			$product->set_attributes( $this->attributes_from_json( isset( $data['attributes_json'] ) ? $data['attributes_json'] : '' ) );

			if ( is_a( $product, 'WC_Product_External' ) ) {
				$product->set_product_url( esc_url_raw( (string) ( isset( $data['external_url'] ) ? $data['external_url'] : '' ) ) );
				$product->set_button_text( sanitize_text_field( (string) ( isset( $data['button_text'] ) ? $data['button_text'] : '' ) ) );
			}
			$product->save();
		} catch ( Exception $exception ) {
			return new WP_Error( 'taxocommerce_ie_product_save', sanitize_text_field( $exception->getMessage() ) );
		}

		return true;
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
	 * Normalize header.
	 *
	 * @param string $header Header.
	 * @return string
	 */
	private function normalize_header( $header ) {
		$header = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header );
		return sanitize_key( trim( $header ) );
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
