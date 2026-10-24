<?php
/**
 * Assign category-related WordPress Media to content missing a representative image.
 *
 * Uses WordPress terms, thumbnails and optional existing SEO relations. Automatic
 * suggestions stay category-related; administrators may also choose an existing
 * local image explicitly from WordPress Media. Supplier images are never imported.
 *
 * @package TaxoCommerceImages
 */

defined( 'ABSPATH' ) || exit;

/**
 * Local image assignment by genuine category relationship.
 */
final class TaxoCommerce_Images_Assignment {

	const PAGE_SIZE  = 25;
	const BULK_LIMIT = 25;

	/**
	 * Register explicit, authenticated administrator actions.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_post_taxocommerce_images_assign_one', array( __CLASS__, 'handle_assign_one' ) );
		add_action( 'admin_post_taxocommerce_images_assign_auto', array( __CLASS__, 'handle_assign_auto' ) );
		add_action( 'admin_post_taxocommerce_images_assign_upload', array( __CLASS__, 'handle_assign_upload' ) );
		add_action( 'admin_post_taxocommerce_images_assign_media', array( __CLASS__, 'handle_assign_media' ) );
	}

	/**
	 * Four independent reports. With WooCommerce inactive, native WP categories
	 * replace WooCommerce categories; products are then not available.
	 *
	 * @return array<string,string>
	 */
	public static function scopes() {
		return array(
			'page'                             => __( 'Páginas', 'taxocommerce-images' ),
			'post'                             => __( 'Entradas', 'taxocommerce-images' ),
			( taxonomy_exists( 'product_cat' ) ? 'product_cat' : 'category' ) => __( 'Categorías', 'taxocommerce-images' ),
			'product'                          => __( 'Productos', 'taxocommerce-images' ),
		);
	}

	/**
	 * Test scope independently from caller input.
	 *
	 * @param string $scope Object type.
	 * @return bool
	 */
	private static function valid_scope( $scope ) {
		return isset( self::scopes()[ $scope ] );
	}

	/**
	 * Only objects with no featured/term image *assigned* are listed.
	 * Broken non-zero references remain an Anomalías responsibility.
	 *
	 * @param string $scope Native post type or taxonomy.
	 * @param int    $id Object ID.
	 * @return bool
	 */
	public static function has_image( $scope, $id ) {
		$id = absint( $id );
		if ( in_array( $scope, array( 'product_cat', 'category' ), true ) ) {
			return absint( get_term_meta( $id, 'thumbnail_id', true ) ) > 0;
		}
		return absint( get_post_thumbnail_id( $id ) ) > 0;
	}

	/**
	 * Bounded, independently paged WordPress query for each section.
	 *
	 * @param string $scope Scope.
	 * @param int    $page Report page.
	 * @param int    $limit Max objects.
	 * @return array{items:array,total:int,pages:int}
	 */
	public static function missing( $scope, $page = 1, $limit = self::PAGE_SIZE ) {
		$page  = max( 1, absint( $page ) );
		$limit = max( 1, min( self::BULK_LIMIT, absint( $limit ) ) );

		if ( ! self::valid_scope( $scope ) ) {
			return array( 'items' => array(), 'total' => 0, 'pages' => 0 );
		}
		if ( in_array( $scope, array( 'product_cat', 'category' ), true ) ) {
			if ( ! taxonomy_exists( $scope ) ) {
				return array( 'items' => array(), 'total' => 0, 'pages' => 0 );
			}
			$args = array(
				'taxonomy' => $scope,
				'hide_empty' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required native termmeta test, paginated output.
				'meta_query' => array(
					'relation' => 'OR',
					array( 'key' => 'thumbnail_id', 'compare' => 'NOT EXISTS' ),
					array( 'key' => 'thumbnail_id', 'value' => '0', 'compare' => '=' ),
					array( 'key' => 'thumbnail_id', 'value' => '', 'compare' => '=' ),
				),
			);
			$total = get_terms( array_merge( $args, array( 'fields' => 'count' ) ) );
			$total = is_wp_error( $total ) ? 0 : absint( $total );
			$terms = get_terms( array_merge(
				$args,
				array( 'number' => $limit, 'offset' => ( $page - 1 ) * $limit, 'orderby' => 'name', 'order' => 'ASC' )
			) );
			$items = array();
			foreach ( is_wp_error( $terms ) ? array() : (array) $terms as $term ) {
				$items[] = array( 'id' => absint( $term->term_id ), 'title' => (string) $term->name );
			}
			return array( 'items' => $items, 'total' => $total, 'pages' => (int) ceil( $total / $limit ) );
		}

		if ( ! post_type_exists( $scope ) ) {
			return array( 'items' => array(), 'total' => 0, 'pages' => 0 );
		}
		$query = new WP_Query(
			array(
				'post_type' => $scope,
				'post_status' => array( 'publish', 'future', 'private', 'draft', 'pending' ),
				'posts_per_page' => $limit,
				'paged' => $page,
				'fields' => 'ids',
				'orderby' => 'ID',
				'order' => 'ASC',
				'ignore_sticky_posts' => true,
				'no_found_rows' => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Native thumbnail metadata, bounded result set.
				'meta_query' => array(
					'relation' => 'OR',
					array( 'key' => '_thumbnail_id', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_thumbnail_id', 'value' => '0', 'compare' => '=' ),
					array( 'key' => '_thumbnail_id', 'value' => '', 'compare' => '=' ),
				),
			)
		);
		$items = array();
		foreach ( (array) $query->posts as $id ) {
			$items[] = array( 'id' => absint( $id ), 'title' => get_the_title( $id ) );
		}
		return array(
			'items' => $items,
			'total' => absint( $query->found_posts ),
			'pages' => absint( $query->max_num_pages ),
		);
	}

	/**
	 * A read-only look-up of existing category relations. The old SEO relations
	 * table is optional; no old plugin PHP function or custom table is required
	 * to activate this plugin. Native Core parent metadata works independently.
	 *
	 * @param string $scope Source kind.
	 * @param int    $id Source ID.
	 * @return array<string,int[]> Term IDs keyed by taxonomy.
	 */
	public static function related_terms( $scope, $id ) {
		static $cache = array();
		$id = absint( $id );
		$cache_key = sanitize_key( $scope ) . ':' . $id;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}
		$terms = array( 'product_cat' => array(), 'category' => array() );
		if ( ! $id ) {
			return $terms;
		}
		if ( 'product_cat' === $scope || 'category' === $scope ) {
			$terms[ $scope ][] = $id;
		} elseif ( 'product' === $scope ) {
			$terms['product_cat'] = self::native_terms( $id, 'product_cat' );
		} elseif ( in_array( $scope, array( 'page', 'post' ), true ) ) {
			$terms['product_cat'] = self::native_terms( $id, 'product_cat' );
			$terms['category'] = self::native_terms( $id, 'category' );
			$terms['product_cat'] = array_merge( $terms['product_cat'], self::relation_categories( $scope, $id ) );

			// Current Core uses native parent meta for the SEO hierarchy.
			if ( 'page' === $scope ) {
				$terms['product_cat'] = array_merge( $terms['product_cat'], self::core_categories( $id ) );
			}

			// Canonical product categories outrank ordinary blog categories, including
			// generic "Uncategorized". Never propose that unrelated blog image when
			// the editorial content is linked to a definite WooCommerce category.
			if ( array_filter( $terms['product_cat'] ) ) {
				$terms['category'] = array();
			}
		}

		$terms = apply_filters( 'taxocommerce_images_assignment_related_terms', $terms, $scope, $id );
		if ( ! is_array( $terms ) ) {
			return array( 'product_cat' => array(), 'category' => array() );
		}
		$validated = array( 'product_cat' => array(), 'category' => array() );
		foreach ( array_keys( $validated ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			foreach ( array_slice( array_unique( array_filter( array_map( 'absint', (array) ( $terms[ $taxonomy ] ?? array() ) ) ) ), 0, 20 ) as $term_id ) {
				if ( term_exists( $term_id, $taxonomy ) ) {
					$validated[ $taxonomy ][] = $term_id;
				}
			}
		}
		$cache[ $cache_key ] = $validated;
		return $validated;
	}

	/**
	 * Native taxonomy relations only (no title/keyword guesses).
	 *
	 * @param int $id Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @return int[]
	 */
	private static function native_terms( $id, $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$ids = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );
		return is_wp_error( $ids ) ? array() : array_map( 'absint', (array) $ids );
	}

	/**
	 * Existing SEO relations, when its table is present. This is read-only and
	 * optional; Images neither creates nor depends upon that table.
	 *
	 * @param string $scope Post or page.
	 * @param int $id WordPress post ID.
	 * @return int[]
	 */
	private static function relation_categories( $scope, $id ) {
		global $wpdb;
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}
		$table = $wpdb->prefix . 'seo_relations';
		static $has_table = null;
		if ( null === $has_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only presence lookup, cached statically per request.
			$has_table = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		}
		if ( ! $has_table ) {
			return array();
		}
		if ( 'post' === $scope ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared lookup max 20 IDs, related_terms memoized.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT target_id FROM %i
					WHERE source_type = 'post' AND source_id = %d
					AND target_type = 'product_cat' AND relation_type = 'post_to_category'
					LIMIT 20",
					$table,
					$id
				)
			);
			return array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
		}

		// Legacy canonical direct mappings, including editorial landing pages.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared lookup max 20, memoized for this request.
		$ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT target_id FROM %i
				WHERE source_id = %d AND source_type IN ('page','landing','hub_secondary','hub_primary','cluster')
				AND target_type = 'product_cat'
				AND relation_type IN ('landing_to_category','hub_secondary_to_category','page_to_category')
				LIMIT 20",
				$table,
				$id
			)
		);

		// Primary hub -> secondary hub -> product category.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed SQL, prepared identifiers and ID, max 20.
		$primary_ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT c.target_id FROM %i h
				JOIN %i c ON c.source_type = 'hub_secondary' AND c.source_id = h.target_id
					AND c.target_type = 'product_cat' AND c.relation_type = 'hub_secondary_to_category'
				WHERE h.source_type = 'hub_primary' AND h.source_id = %d
					AND h.target_type = 'hub_secondary' AND h.relation_type = 'hub_primary_to_hub_secondary'
				LIMIT 20",
				$table,
				$table,
				$id
			)
		);

		// Cluster -> primary hub -> secondary hub -> product category.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed SQL, prepared identifiers and ID, max 20.
		$cluster_ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT c.target_id FROM %i cp
				JOIN %i hs ON hs.source_type = 'hub_primary' AND hs.source_id = cp.target_id
					AND hs.target_type = 'hub_secondary' AND hs.relation_type = 'hub_primary_to_hub_secondary'
				JOIN %i c ON c.source_type = 'hub_secondary' AND c.source_id = hs.target_id
					AND c.target_type = 'product_cat' AND c.relation_type = 'hub_secondary_to_category'
				WHERE cp.source_type = 'cluster' AND cp.source_id = %d
					AND cp.target_type = 'hub_primary' AND cp.relation_type = 'cluster_to_primary'
				LIMIT 20",
				$table,
				$table,
				$table,
				$id
			)
		);
		return array_values( array_unique( array_filter( array_map( 'absint', array_merge( $ids, $primary_ids, $cluster_ids ) ) ) ) );
	}

	/**
	 * Native Core parent metadata, without requiring TaxoCommerce Core classes.
	 *
	 * @param int $page_id Page.
	 * @return int[]
	 */
	private static function core_categories( $page_id ) {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}
		$role = sanitize_key( (string) get_post_meta( $page_id, '_taxocommerce_core_seo_role', true ) );
		if ( 'hub_secondary' === $role ) {
			$secondaries = array( $page_id );
		} elseif ( 'hub_primary' === $role ) {
			$secondaries = self::linked_pages( '_taxocommerce_core_parent_hub_primary', array( $page_id ) );
		} elseif ( 'cluster' === $role ) {
			$primaries = self::linked_pages( '_taxocommerce_core_parent_cluster', array( $page_id ) );
			$secondaries = self::linked_pages( '_taxocommerce_core_parent_hub_primary', $primaries );
		} else {
			$secondaries = array();
		}

		$ids = array();
		foreach ( array_slice( $secondaries, 0, 15 ) as $secondary_id ) {
			$children = get_terms(
				array(
					'taxonomy' => 'product_cat',
					'hide_empty' => false,
					'number' => 20,
					'fields' => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Native Core term relation, limited to 20 IDs.
					'meta_query' => array(
						array(
							'key' => '_taxocommerce_core_parent_hub_secondary',
							'value' => (string) $secondary_id,
						),
					),
				)
			);
			if ( ! is_wp_error( $children ) ) {
				$ids = array_merge( $ids, array_map( 'absint', (array) $children ) );
			}
			if ( count( $ids ) >= 20 ) {
				break;
			}
		}
		return array_slice( array_unique( $ids ), 0, 20 );
	}

	/**
	 * Find pages linked through Core parent metadata.
	 *
	 * @param string $key Native meta key.
	 * @param int[] $parents Parent IDs.
	 * @return int[]
	 */
	private static function linked_pages( $key, $parents ) {
		$parents = array_slice( array_filter( array_map( 'absint', (array) $parents ) ), 0, 15 );
		if ( ! $parents ) {
			return array();
		}
		return array_map( 'absint', get_posts(
			array(
				'post_type' => 'page',
				'post_status' => array( 'publish', 'future', 'private' ),
				'posts_per_page' => 20,
				'fields' => 'ids',
				'no_found_rows' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Core parent relation, bounded to 20 pages.
				'meta_query' => array(
					array( 'key' => $key, 'value' => array_map( 'strval', $parents ), 'compare' => 'IN' ),
				),
			)
		) );
	}

	/**
	 * Attachment is a real, locally readable image in WordPress Media.
	 *
	 * @param int $attachment_id Media ID.
	 * @return bool
	 */
	private static function valid_media( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			return false;
		}
		$file = get_attached_file( $attachment_id );
		return is_string( $file ) && '' !== $file && is_file( $file ) && is_readable( $file );
	}

	/**
	 * Deterministic, category-specific Media candidates.
	 *
	 * 1. Thumbnail assigned to a related category.
	 * 2. Featured images from products in that same WooCommerce category.
	 * 3. Product gallery entries from that same category.
	 * On native WordPress post categories, use posts within the category.
	 * Never search Media globally or access supplier images.
	 *
	 * @param string $scope Object type.
	 * @param int $id Object ID.
	 * @param int $limit Maximum candidate images.
	 * @return array
	 */
	public static function candidates( $scope, $id, $limit = 4 ) {
		if ( ! self::valid_scope( $scope ) ) {
			return array();
		}
		$limit = max( 1, min( 8, absint( $limit ) ) );
		// A product never borrows the image of another product or a category.
		if ( 'product' === $scope ) {
			$own = array();
			foreach ( array_unique( array_map( 'absint', explode( ',', (string) get_post_meta( $id, '_product_image_gallery', true ) ) ) ) as $image ) {
				if ( self::valid_media( $image ) ) {
					$own[] = array( 'id' => $image, 'label' => __( 'Imagen de este mismo producto', 'taxocommerce-images' ), 'term_id' => 0 );
					if ( count( $own ) >= $limit ) { break; }
				}
			}
			return $own;
		}
		$terms = self::related_terms( $scope, $id );
		$found = array();

		$add = static function ( $attachment_id, $label, $term_id ) use ( &$found, $limit ) {
			$attachment_id = absint( $attachment_id );
			if ( count( $found ) >= $limit || isset( $found[ $attachment_id ] ) || ! self::valid_media( $attachment_id ) ) {
				return;
			}
			$found[ $attachment_id ] = array(
				'id' => $attachment_id,
				'label' => sanitize_text_field( $label ),
				'term_id' => absint( $term_id ),
			);
		};

		// Prioritize *all* explicitly related category thumbnails first.
		foreach ( $terms as $taxonomy => $term_ids ) {
			foreach ( $term_ids as $term_id ) {
				$term = get_term( $term_id, $taxonomy );
				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}
				$add( get_term_meta( $term_id, 'thumbnail_id', true ), sprintf( 'Categoría: %s', $term->name ), $term_id );
			}
		}
		if ( count( $found ) >= $limit ) {
			return array_values( $found );
		}

		// Then take media from items in those very same categories.
		foreach ( $terms as $taxonomy => $term_ids ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			foreach ( $term_ids as $term_id ) {
				$kind = 'product_cat' === $taxonomy ? 'product' : 'post';
				if ( ! post_type_exists( $kind ) ) {
					continue;
				}
				$ids = get_posts(
					array(
						'post_type' => $kind,
						'post_status' => 'publish',
						'posts_per_page' => 150,
						'fields' => 'ids',
						'orderby' => 'ID',
						'order' => 'DESC',
						'no_found_rows' => true,
						'update_post_meta_cache' => true,
						'update_post_term_cache' => false,
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Only verified related product categories, max 150 IDs.
						'tax_query' => array(
							array(
								'taxonomy' => $taxonomy,
								'field' => 'term_id',
								'terms' => array( $term_id ),
								'include_children' => true,
							),
						),
					)
				);
				// Prefer featured images to gallery images.
				foreach ( (array) $ids as $post_id ) {
					$add( get_post_thumbnail_id( $post_id ), sprintf( 'Producto de la categoría: %s', get_the_title( $post_id ) ), $term_id );
					if ( count( $found ) >= $limit ) {
						return array_values( $found );
					}
				}
				if ( 'product' === $kind ) {
					foreach ( (array) $ids as $post_id ) {
						$gallery = explode( ',', (string) get_post_meta( $post_id, '_product_image_gallery', true ) );
						foreach ( $gallery as $gallery_id ) {
							$add( absint( $gallery_id ), sprintf( 'Galería del producto: %s', get_the_title( $post_id ) ), $term_id );
							if ( count( $found ) >= $limit ) {
								return array_values( $found );
							}
						}
					}
				}
			}
		}
		return array_values( $found );
	}

	/**
	 * Verify that the target exists and is editable by the current user.
	 *
	 * @param string $scope Object kind.
	 * @param int $id Object ID.
	 * @return bool
	 */
	private static function editable_target( $scope, $id ) {
		$id = absint( $id );
		if ( ! $id || ! self::valid_scope( $scope ) || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( in_array( $scope, array( 'product_cat', 'category' ), true ) ) {
			$term = get_term( $id, $scope );
			return $term instanceof WP_Term && current_user_can( 'edit_term', $id );
		}
		return $scope === get_post_type( $id ) && current_user_can( 'edit_post', $id );
	}

	/**
	 * Apply a chosen local attachment only when the target is still unassigned.
	 * No provider URLs, auto-imports or new image indexes.
	 *
	 * @param string $scope Object kind.
	 * @param int $id Object ID.
	 * @param int $attachment_id WP Media ID.
	 * @return bool
	 */
	private static function apply( $scope, $id, $attachment_id ) {
		if ( ! self::editable_target( $scope, $id ) || self::has_image( $scope, $id ) || ! self::valid_media( $attachment_id ) ) {
			return false;
		}
		if ( in_array( $scope, array( 'product_cat', 'category' ), true ) ) {
			return false !== update_term_meta( $id, 'thumbnail_id', $attachment_id );
		}
		return (bool) set_post_thumbnail( $id, $attachment_id );
	}

	/**
	 * Assign an explicitly chosen existing WordPress Media image.
	 *
	 * This path is deliberately manual: unlike automatic suggestions, the image
	 * does not need to belong to a related category because the administrator is
	 * choosing it consciously from Media. It must still be a valid local image,
	 * the target must be editable, and an existing image is never overwritten.
	 *
	 * @param string $scope Object kind.
	 * @param int    $id Object ID.
	 * @param int    $attachment_id Existing Media attachment ID.
	 * @return true|WP_Error
	 */
	public static function assign_from_media( $scope, $id, $attachment_id ) {
		$scope = sanitize_key( (string) $scope );
		$id = absint( $id );
		$attachment_id = absint( $attachment_id );

		if ( ! self::editable_target( $scope, $id ) ) {
			return new WP_Error( 'taxocommerce_images_media_target', __( 'El elemento no existe o no se puede editar.', 'taxocommerce-images' ) );
		}
		if ( self::has_image( $scope, $id ) ) {
			return new WP_Error( 'taxocommerce_images_media_existing', __( 'El elemento ya tiene una imagen asignada.', 'taxocommerce-images' ) );
		}
		if ( ! self::valid_media( $attachment_id ) ) {
			return new WP_Error( 'taxocommerce_images_media_invalid', __( 'La imagen seleccionada ya no está disponible en Media o su archivo local no existe.', 'taxocommerce-images' ) );
		}
		if ( ! self::apply( $scope, $id, $attachment_id ) ) {
			return new WP_Error( 'taxocommerce_images_media_assign', __( 'No se pudo asignar la imagen seleccionada.', 'taxocommerce-images' ) );
		}
		return true;
	}

	/**
	 * Save navigation and operation results without leaking filesystem paths.
	 *
	 * @param string $scope Group.
	 * @param string $kind Notice status.
	 * @param string $message Short message.
	 * @return void
	 */
	private static function redirect( $scope, $kind, $message ) {
		wp_safe_redirect( add_query_arg(
			array(
				'page' => 'taxocommerce-images',
				'tab' => 'assignment',
				'tc_result' => sanitize_key( $kind ),
				'tc_message' => sanitize_text_field( $message ),
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Validate the submitted scope and target before any write action.
	 *
	 * @param string $action Nonce action.
	 * @return array{scope:string,id:int}
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para asignar imágenes.', 'taxocommerce-images' ) );
		}
		check_admin_referer( $action, 'taxocommerce_images_nonce' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() already completed at start of guard().
		$scope = isset( $_POST['scope'] ) && is_string( $_POST['scope'] )
			? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by guard() before POST access.
		$id = isset( $_POST['object_id'] ) && is_scalar( $_POST['object_id'] )
			? absint( $_POST['object_id'] ) : 0;
		if ( ! self::valid_scope( $scope ) ) {
			wp_die( esc_html__( 'Tipo de contenido no válido.', 'taxocommerce-images' ) );
		}
		return array( 'scope' => $scope, 'id' => $id );
	}

	/**
	 * Assign a selected candidate already in the related category.
	 *
	 * @return void
	 */
	public static function handle_assign_one() {
		$data = self::guard( 'taxocommerce_images_assign_one' );
		$scope = $data['scope'];
		$id = $data['id'];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by guard() before POST access.
		$attachment_id = isset( $_POST['attachment_id'] ) && is_scalar( $_POST['attachment_id'] )
			? absint( $_POST['attachment_id'] ) : 0;
		$allowed = wp_list_pluck( self::candidates( $scope, $id, 8 ), 'id' );
		if ( ! in_array( $attachment_id, $allowed, true ) ) {
			self::redirect( $scope, 'error', __( 'La imagen no pertenece a las categorías relacionadas o ya no está disponible.', 'taxocommerce-images' ) );
		}
		if ( self::apply( $scope, $id, $attachment_id ) ) {
			self::redirect( $scope, 'ok', __( 'Imagen relacionada asignada correctamente.', 'taxocommerce-images' ) );
		}
		self::redirect( $scope, 'error', __( 'No se pudo asignar la imagen o el contenido ya tenía una imagen.', 'taxocommerce-images' ) );
	}

	/**
	 * Assign an image explicitly selected from the native WordPress Media modal.
	 *
	 * @return void
	 */
	public static function handle_assign_media() {
		$data = self::guard( 'taxocommerce_images_assign_media' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by guard() before POST access.
		$attachment_id = isset( $_POST['attachment_id'] ) && is_scalar( $_POST['attachment_id'] )
			? absint( $_POST['attachment_id'] ) : 0;

		$result = self::assign_from_media( $data['scope'], $data['id'], $attachment_id );
		if ( is_wp_error( $result ) ) {
			self::redirect( $data['scope'], 'error', $result->get_error_message() );
		}
		self::redirect( $data['scope'], 'ok', __( 'Imagen existente de Media asignada correctamente.', 'taxocommerce-images' ) );
	}

	/**
	 * Assign at most 25 items per request, first eligible category candidate.
	 *
	 * @return void
	 */
	public static function process_assignment_batch( $scope, $after_id = 0 ) {
		if ( ! current_user_can( 'manage_options' ) || ! self::valid_scope( $scope ) ) { return new WP_Error( 'permission', 'Sin permisos o tipo no válido.' ); }
		$after_id = absint( $after_id );
		$post_filter = static function ( $where, $query ) use ( $scope, $after_id ) {
			global $wpdb;
			if ( $scope === $query->get( 'post_type' ) && $query->get( 'meta_query' ) ) { $where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after_id ); }
			return $where;
		};
		$term_args = static function ( $args, $taxonomies ) use ( $scope ) {
			if ( in_array( $scope, (array) $taxonomies, true ) && ! empty( $args['meta_query'] ) ) { $args['orderby'] = 'term_id'; $args['order'] = 'ASC'; }
			return $args;
		};
		$term_filter = static function ( $clauses, $taxonomies, $args ) use ( $scope, $after_id ) {
			global $wpdb;
			if ( in_array( $scope, (array) $taxonomies, true ) && ! empty( $args['meta_query'] ) ) { $clauses['where'] .= $wpdb->prepare( ' AND t.term_id > %d', $after_id ); }
			return $clauses;
		};
		add_filter( 'posts_where', $post_filter, 10, 2 );
		add_filter( 'get_terms_args', $term_args, 10, 2 );
		add_filter( 'terms_clauses', $term_filter, 10, 3 );
		try { $report = self::missing( $scope, 1, self::BULK_LIMIT ); }
		finally {
			remove_filter( 'posts_where', $post_filter, 10 );
			remove_filter( 'get_terms_args', $term_args, 10 );
			remove_filter( 'terms_clauses', $term_filter, 10 );
		}
		$assigned = 0;
		foreach ( $report['items'] as $item ) {
			$after_id = max( $after_id, absint( $item['id'] ) );
			$candidates = self::candidates( $scope, $item['id'], 1 );
			if ( $candidates && self::apply( $scope, $item['id'], $candidates[0]['id'] ) ) { ++$assigned; }
		}
		$all = self::missing( $scope, 1, 1 );
		return array( 'seen' => count( $report['items'] ), 'assigned' => $assigned, 'cursor' => $after_id, 'remaining' => $all['total'], 'done' => count( $report['items'] ) < self::BULK_LIMIT || 0 === $all['total'] );
	}

	public static function handle_assign_auto() {
		$data = self::guard( 'taxocommerce_images_assign_auto' );
		$key = 'taxocommerce_images_assign_cursor_' . get_current_user_id() . '_' . $data['scope'];
		$result = self::process_assignment_batch( $data['scope'], absint( get_transient( $key ) ) );
		if ( is_wp_error( $result ) ) { self::redirect( $data['scope'], 'error', $result->get_error_message() ); }
		if ( $result['done'] ) { delete_transient( $key ); } else { set_transient( $key, $result['cursor'], DAY_IN_SECONDS ); }
		$message = sprintf(
			/* translators: 1: reviewed objects, 2: assigned images, 3: all remaining objects. */
			__( 'Revisados: %1$d. Asignados: %2$d. Pendientes totales: %3$d.', 'taxocommerce-images' ),
			$result['seen'], $result['assigned'], $result['remaining']
		);
		$message .= $result['done'] ? ' Pasada terminada; los casos sin imagen propia siguen pendientes.' : ' El siguiente clic continúa con el siguiente lote.';
		self::redirect( $data['scope'], 'ok', $message );
	}

	/**
	 * Upload one explicitly chosen local file, then attach it to that target.
	 * This is the one path where a new Media image is created.
	 *
	 * @return void
	 */
	public static function handle_assign_upload() {
		$data = self::guard( 'taxocommerce_images_assign_upload' );
		$scope = $data['scope'];
		$id = $data['id'];
		if ( ! current_user_can( 'upload_files' ) || ! self::editable_target( $scope, $id ) || self::has_image( $scope, $id ) ) {
			self::redirect( $scope, 'error', __( 'No tienes permisos o el contenido ya tiene imagen.', 'taxocommerce-images' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked by guard(); raw upload metadata is validated and passed to native Media API.
		$file = isset( $_FILES['manual_image'] ) && is_array( $_FILES['manual_image'] ) ? $_FILES['manual_image'] : array();
		$limit = wp_max_upload_size();
		if ( ! isset( $file['error'], $file['size'], $file['name'] ) ||
			UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] < 1 ||
			( $limit > 0 && (int) $file['size'] > $limit ) || ! is_string( $file['name'] ) ) {
			self::redirect( $scope, 'error', __( 'El archivo no es válido o supera el límite de subida de WordPress.', 'taxocommerce-images' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$allowed_mimes = array_filter(
			get_allowed_mime_types(),
			static function ( $mime ) {
				return 0 === strpos( (string) $mime, 'image/' ) && 'image/svg+xml' !== $mime;
			}
		);
		$attachment_id = media_handle_upload(
			'manual_image',
			0,
			array( 'post_title' => sanitize_text_field( pathinfo( wp_basename( $file['name'] ), PATHINFO_FILENAME ) ) ),
			array( 'test_form' => false, 'mimes' => $allowed_mimes )
		);
		if ( is_wp_error( $attachment_id ) ) {
			self::redirect( $scope, 'error', __( 'WordPress no ha podido incorporar el archivo a Media.', 'taxocommerce-images' ) );
		}
		if ( self::apply( $scope, $id, absint( $attachment_id ) ) ) {
			self::redirect( $scope, 'ok', __( 'Archivo subido a Media y asignado correctamente.', 'taxocommerce-images' ) );
		}
		// Keep the user's uploaded Media file for manual recovery; never delete it silently.
		self::redirect( $scope, 'error', __( 'La imagen está en Media, pero no ha podido asociarse. Revisa el contenido.', 'taxocommerce-images' ) );
	}
}
