<?php
/**
 * Media package import/export service.
 *
 * @package TaxoCommerceImportExport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports and exports WordPress image attachments as a ZIP package.
 */
final class TaxoCommerce_Import_Export_Media {

	/**
	 * Export Media package.
	 *
	 * @return void
	 */
	public function handle_export() {
		$this->guard();
		check_admin_referer( 'taxocommerce_import_export_media_export', 'taxocommerce_import_export_nonce' );

		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'El servidor no tiene disponible ZipArchive.', 'taxocommerce-import-export' ) );
		}

		$temp_zip = wp_tempnam( 'taxocommerce-media.zip' );
		$temp_csv = wp_tempnam( 'taxocommerce-media.csv' );
		if ( ! $temp_zip || ! $temp_csv ) {
			wp_die( esc_html__( 'No se pudieron crear los archivos temporales.', 'taxocommerce-import-export' ) );
		}

		$csv = $this->stream_open( $temp_csv, 'w' );
		if ( false === $csv ) {
			wp_delete_file( $temp_zip );
			wp_delete_file( $temp_csv );
			wp_die( esc_html__( 'No se pudo crear media.csv.', 'taxocommerce-import-export' ) );
		}

		$this->stream_write( $csv, "\xEF\xBB\xBF" );
		fputcsv(
			$csv,
			array(
				'record_type',
				'old_attachment_id',
				'title',
				'alt',
				'caption',
				'description',
				'mime_type',
				'original_filename',
				'package_file',
				'post_parent_id',
				'post_parent_type',
				'post_parent_slug',
				'relations_json',
			)
		);

		$relations = $this->build_relation_map();
		$zip       = new ZipArchive();
		$opened    = $zip->open( $temp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		if ( true !== $opened ) {
			$this->stream_close( $csv );
			wp_delete_file( $temp_zip );
			wp_delete_file( $temp_csv );
			wp_die( esc_html__( 'No se pudo crear el ZIP de Media.', 'taxocommerce-import-export' ) );
		}

		$page = 1;
		do {
			$query = new WP_Query(
				array(
					'post_type'              => 'attachment',
					'post_status'            => 'inherit',
					'post_mime_type'         => 'image',
					'posts_per_page'         => 200,
					'paged'                  => $page,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => false,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => false,
				)
			);

			foreach ( $query->posts as $attachment ) {
				$file = get_attached_file( $attachment->ID );
				if ( ! $file || ! file_exists( $file ) ) {
					continue;
				}

				$basename     = sanitize_file_name( wp_basename( $file ) );
				$package_file = 'files/' . absint( $attachment->ID ) . '/' . $basename;
				$parent       = $attachment->post_parent ? get_post( $attachment->post_parent ) : null;

				$zip->addFile( $file, $package_file );
				fputcsv(
					$csv,
					array(
						'image',
						$attachment->ID,
						$attachment->post_title,
						(string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
						$attachment->post_excerpt,
						$attachment->post_content,
						$attachment->post_mime_type,
						$basename,
						$package_file,
						$parent ? $parent->ID : 0,
						$parent ? $parent->post_type : '',
						$parent ? $parent->post_name : '',
						wp_json_encode( isset( $relations[ $attachment->ID ] ) ? $relations[ $attachment->ID ] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					)
				);
			}

			$page++;
			$max_pages = (int) $query->max_num_pages;
			wp_reset_postdata();
		} while ( $page <= $max_pages );

		$this->stream_close( $csv );
		$zip->addFile( $temp_csv, 'media.csv' );
		$zip->addFromString(
			'manifest.json',
			wp_json_encode(
				array(
					'plugin'       => 'TaxoCommerce Import-Export',
					'version'      => TAXOCOMMERCE_IMPORT_EXPORT_VERSION,
					'package_type' => 'media_images',
					'created_gmt'  => gmdate( 'c' ),
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			)
		);
		$zip->close();

		$filename = 'taxocommerce_media_' . gmdate( 'Ymd_His' ) . '.zip';
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $temp_zip ) );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $temp_zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a generated download.
		wp_delete_file( $temp_zip );
		wp_delete_file( $temp_csv );
		exit;
	}

	/**
	 * Import Media package.
	 *
	 * @return void
	 */
	public function handle_import() {
		$this->guard();
		check_admin_referer( 'taxocommerce_import_export_media_import', 'taxocommerce_import_export_nonce' );

		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->redirect_error( __( 'El servidor no tiene disponible ZipArchive.', 'taxocommerce-import-export' ) );
		}

		if ( empty( $_FILES['media_zip'] ) || ! is_array( $_FILES['media_zip'] ) ) {
			$this->redirect_error( __( 'No se ha recibido ningún ZIP de Media.', 'taxocommerce-import-export' ) );
		}

		$file = $_FILES['media_zip']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated below.
		if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) ) {
			$this->redirect_error( __( 'La subida del ZIP ha fallado.', 'taxocommerce-import-export' ) );
		}

		$name = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
		if ( 'zip' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			$this->redirect_error( __( 'El archivo de Media debe tener extensión .zip.', 'taxocommerce-import-export' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $file['tmp_name'] ) ) {
			$this->redirect_error( __( 'No se pudo abrir el ZIP de Media.', 'taxocommerce-import-export' ) );
		}

		$csv = $zip->getStream( 'media.csv' );
		if ( false === $csv ) {
			$zip->close();
			$this->redirect_error( __( 'El ZIP no contiene media.csv.', 'taxocommerce-import-export' ) );
		}

		$headers = fgetcsv( $csv );
		if ( ! is_array( $headers ) ) {
			$this->stream_close( $csv );
			$zip->close();
			$this->redirect_error( __( 'media.csv no contiene cabecera.', 'taxocommerce-import-export' ) );
		}

		$headers = array_map( array( $this, 'normalize_header' ), $headers );
		$required = array( 'record_type', 'old_attachment_id', 'original_filename', 'package_file' );
		foreach ( $required as $required_header ) {
			if ( ! in_array( $required_header, $headers, true ) ) {
				$this->stream_close( $csv );
				$zip->close();
				$this->redirect_error( __( 'El paquete de Media no tiene el formato esperado.', 'taxocommerce-import-export' ) );
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$id_map    = array();
		$relations = array();
		$count     = 0;

		while ( false !== ( $row = fgetcsv( $csv ) ) ) {
			if ( empty( array_filter( $row, 'strlen' ) ) ) {
				continue;
			}

			$row  = array_pad( $row, count( $headers ), '' );
			$data = array_combine( $headers, array_slice( $row, 0, count( $headers ) ) );
			if ( ! is_array( $data ) || 'image' !== sanitize_key( (string) $data['record_type'] ) ) {
				continue;
			}

			$package_file = ltrim( str_replace( '\\', '/', (string) $data['package_file'] ), '/' );
			if ( 0 !== strpos( $package_file, 'files/' ) || false !== strpos( $package_file, '../' ) ) {
				$this->stream_close( $csv );
				$zip->close();
				$this->redirect_error( __( 'El ZIP contiene una ruta de archivo no válida.', 'taxocommerce-import-export' ) );
			}

			$stream = $zip->getStream( $package_file );
			if ( false === $stream ) {
				continue;
			}

			$original_name = sanitize_file_name( (string) $data['original_filename'] );
			if ( ! $original_name ) {
				$this->stream_close( $stream );
				continue;
			}

			$tmp = wp_tempnam( $original_name );
			if ( ! $tmp ) {
				$this->stream_close( $stream );
				continue;
			}

			$out = $this->stream_open( $tmp, 'w' );
			if ( false === $out ) {
				$this->stream_close( $stream );
				wp_delete_file( $tmp );
				continue;
			}

			while ( ! feof( $stream ) ) {
				$chunk = $this->stream_read( $stream, 1048576 );
				if ( false === $chunk ) {
					break;
				}
				$this->stream_write( $out, $chunk );
			}
			$this->stream_close( $out );
			$this->stream_close( $stream );

			$parent_id = $this->resolve_parent(
				absint( isset( $data['post_parent_id'] ) ? $data['post_parent_id'] : 0 ),
				sanitize_key( isset( $data['post_parent_type'] ) ? $data['post_parent_type'] : '' ),
				sanitize_title( isset( $data['post_parent_slug'] ) ? $data['post_parent_slug'] : '' )
			);

			$file_array = array(
				'name'     => $original_name,
				'tmp_name' => $tmp,
				'error'    => 0,
				'size'     => filesize( $tmp ),
			);

			$post_data = array(
				'post_title'   => sanitize_text_field( isset( $data['title'] ) ? $data['title'] : '' ),
				'post_excerpt' => wp_kses_post( isset( $data['caption'] ) ? $data['caption'] : '' ),
				'post_content' => wp_kses_post( isset( $data['description'] ) ? $data['description'] : '' ),
			);

			$new_id = media_handle_sideload( $file_array, $parent_id, null, $post_data );
			if ( is_wp_error( $new_id ) ) {
				wp_delete_file( $tmp );
				$this->stream_close( $csv );
				$zip->close();
				$this->redirect_error( $new_id->get_error_message() );
			}

			$new_id = absint( $new_id );
			update_post_meta( $new_id, '_wp_attachment_image_alt', sanitize_text_field( isset( $data['alt'] ) ? $data['alt'] : '' ) );

			$old_id = absint( isset( $data['old_attachment_id'] ) ? $data['old_attachment_id'] : 0 );
			if ( $old_id ) {
				$id_map[ $old_id ] = $new_id;
			}

			$relation_data = json_decode( isset( $data['relations_json'] ) ? (string) $data['relations_json'] : '', true );
			if ( is_array( $relation_data ) ) {
				$relations[] = array(
					'new_attachment_id' => $new_id,
					'items'             => $relation_data,
				);
			}

			$count++;
		}

		$this->stream_close( $csv );
		$zip->close();

		foreach ( $relations as $relation_set ) {
			$this->apply_relations( absint( $relation_set['new_attachment_id'] ), (array) $relation_set['items'] );
		}

		$url = add_query_arg(
			array(
				'page'         => 'taxocommerce-import-export',
				'tc_ie_result' => 'ok',
				'tc_ie_count'  => $count,
				'tc_ie_kind'   => 'media',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Build attachment relationship map without direct SQL.
	 *
	 * @return array
	 */
	private function build_relation_map() {
		$map = array();

		foreach ( array( 'product', 'post', 'page' ) as $post_type ) {
			$page = 1;
			do {
				$query = new WP_Query(
					array(
						'post_type'              => $post_type,
						'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
						'posts_per_page'         => 250,
						'paged'                  => $page,
						'fields'                 => 'ids',
						'orderby'                => 'ID',
						'order'                  => 'ASC',
						'no_found_rows'          => false,
						'update_post_meta_cache' => true,
						'update_post_term_cache' => false,
					)
				);

				foreach ( $query->posts as $object_id ) {
					$slug       = (string) get_post_field( 'post_name', $object_id );
					$featured   = absint( get_post_thumbnail_id( $object_id ) );
					if ( $featured ) {
						$map[ $featured ][] = array(
							'kind'        => 'featured',
							'object_type' => $post_type,
							'object_id'   => absint( $object_id ),
							'object_slug' => $slug,
						);
					}

					if ( 'product' === $post_type && function_exists( 'wc_get_product' ) ) {
						$product = wc_get_product( $object_id );
						if ( $product ) {
							foreach ( array_map( 'absint', $product->get_gallery_image_ids() ) as $gallery_id ) {
								if ( $gallery_id ) {
									$map[ $gallery_id ][] = array(
										'kind'        => 'product_gallery',
										'object_type' => 'product',
										'object_id'   => absint( $object_id ),
										'object_slug' => $slug,
									);
								}
							}
						}
					}
				}

				$page++;
				$max_pages = (int) $query->max_num_pages;
				wp_reset_postdata();
			} while ( $page <= $max_pages );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$image_id = absint( get_term_meta( $term->term_id, 'thumbnail_id', true ) );
				if ( $image_id ) {
					$map[ $image_id ][] = array(
						'kind'      => 'product_category',
						'term_id'   => absint( $term->term_id ),
						'term_slug' => $term->slug,
					);
				}
			}
		}

		return $map;
	}

	/**
	 * Apply exported attachment relations.
	 *
	 * @param int   $attachment_id New attachment ID.
	 * @param array $items         Relations.
	 * @return void
	 */
	private function apply_relations( $attachment_id, $items ) {
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$kind = sanitize_key( isset( $item['kind'] ) ? $item['kind'] : '' );
			if ( 'product_category' === $kind ) {
				$term = $this->resolve_category(
					absint( isset( $item['term_id'] ) ? $item['term_id'] : 0 ),
					sanitize_title( isset( $item['term_slug'] ) ? $item['term_slug'] : '' )
				);
				if ( $term ) {
					update_term_meta( $term->term_id, 'thumbnail_id', $attachment_id );
				}
				continue;
			}

			$post = $this->resolve_object(
				absint( isset( $item['object_id'] ) ? $item['object_id'] : 0 ),
				sanitize_key( isset( $item['object_type'] ) ? $item['object_type'] : '' ),
				sanitize_title( isset( $item['object_slug'] ) ? $item['object_slug'] : '' )
			);
			if ( ! $post ) {
				continue;
			}

			if ( 'featured' === $kind ) {
				set_post_thumbnail( $post->ID, $attachment_id );
			} elseif ( 'product_gallery' === $kind && 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $post->ID );
				if ( $product ) {
					$gallery = array_map( 'absint', $product->get_gallery_image_ids() );
					if ( ! in_array( $attachment_id, $gallery, true ) ) {
						$gallery[] = $attachment_id;
						$product->set_gallery_image_ids( $gallery );
						$product->save();
					}
				}
			}
		}
	}

	/**
	 * Resolve post parent.
	 *
	 * @param int    $id   Original ID.
	 * @param string $type Post type.
	 * @param string $slug Post slug.
	 * @return int
	 */
	private function resolve_parent( $id, $type, $slug ) {
		$post = $this->resolve_object( $id, $type, $slug );
		return $post ? absint( $post->ID ) : 0;
	}

	/**
	 * Resolve an object by ID first, then by slug/type.
	 *
	 * @param int    $id   ID.
	 * @param string $type Post type.
	 * @param string $slug Slug.
	 * @return WP_Post|null
	 */
	private function resolve_object( $id, $type, $slug ) {
		if ( $id ) {
			$post = get_post( $id );
			if ( $post && ( ! $type || $post->post_type === $type ) ) {
				return $post;
			}
		}

		if ( $type && $slug ) {
			$posts = get_posts(
				array(
					'post_type'      => $type,
					'name'           => $slug,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page' => 1,
				)
			);
			if ( ! empty( $posts ) ) {
				return $posts[0];
			}
		}

		return null;
	}

	/**
	 * Resolve product category by ID or slug.
	 *
	 * @param int    $id   ID.
	 * @param string $slug Slug.
	 * @return WP_Term|null
	 */
	private function resolve_category( $id, $slug ) {
		if ( $id ) {
			$term = get_term( $id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term;
			}
		}

		if ( $slug ) {
			$term = get_term_by( 'slug', $slug, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term;
			}
		}

		return null;
	}

	/**
	 * Open a native stream for incremental Media package I/O.
	 *
	 * @param string $path Path.
	 * @param string $mode Mode.
	 * @return resource|false
	 */
	private function stream_open( $path, $mode ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Incremental ZIP/CSV transport requires native stream resources to keep memory bounded.
		return fopen( $path, $mode );
	}

	/**
	 * Write bytes to a native stream.
	 *
	 * @param resource $handle Stream.
	 * @param string   $data Data.
	 * @return int|false
	 */
	private function stream_write( $handle, $data ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Same bounded native stream returned by stream_open().
		return fwrite( $handle, $data );
	}

	/**
	 * Read a bounded chunk from a native stream.
	 *
	 * @param resource $handle Stream.
	 * @param int      $length Bytes.
	 * @return string|false
	 */
	private function stream_read( $handle, $length ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Bounded 1 MB chunks avoid loading Media files into memory.
		return fread( $handle, $length );
	}

	/**
	 * Close a native stream.
	 *
	 * @param resource $handle Stream.
	 * @return bool
	 */
	private function stream_close( $handle ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Explicit close of the native bounded stream.
		return fclose( $handle );
	}

	/**
	 * Normalize CSV header.
	 *
	 * @param string $header Header.
	 * @return string
	 */
	private function normalize_header( $header ) {
		$header = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header );
		return sanitize_key( trim( $header ) );
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
			wp_die( esc_html__( 'No tienes permisos para importar o exportar Media.', 'taxocommerce-import-export' ) );
		}
	}
}
