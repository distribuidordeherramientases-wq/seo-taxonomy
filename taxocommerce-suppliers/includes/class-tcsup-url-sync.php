<?php
/**
 * Bounded supplier URL observations and manual source linking.
 *
 * @package TaxoCommerceSuppliers
 */
defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Established plugin prefix.
final class TCSUP_URL_Sync {
	public static function boot() {
		add_action( 'wp_ajax_tcsup_url_check', array( __CLASS__, 'check' ) );
		add_action( 'admin_post_tcsup_url_link', array( __CLASS__, 'link' ) );
	}

	private static function permitted() {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	private static function url() {
		return admin_url( 'admin.php?page=taxocommerce-suppliers&tab=sync' );
	}

	private static function providers() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current supplier directory.
		return (array) $wpdb->get_col( $wpdb->prepare( "SELECT proveedor FROM %i WHERE proveedor<>'' UNION SELECT proveedor FROM %i WHERE proveedor<>'' ORDER BY proveedor", TCSUP_Schema::catalog_table(), TCSUP_Schema::profiles_table() ) );
	}

	private static function provider_select( $id, $name = '', $required = false ) {
		printf( '<select id="%s" name="%s" %s>', esc_attr( $id ), esc_attr( $name ), $required ? 'required' : '' );
		printf( '<option value="">%s</option>', esc_html( $required ? __( 'Select supplier', 'taxocommerce-suppliers' ) : __( 'All suppliers', 'taxocommerce-suppliers' ) ) );
		foreach ( self::providers() as $provider ) {
			printf( '<option value="%s">%s</option>', esc_attr( $provider ), esc_html( $provider ) );
		}
		echo '</select>';
	}

	private static function relations() {
		static $rows = null;
		if ( null !== $rows ) {
			return $rows;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'seo_relations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Optional existing hierarchy, no installation dependency.
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return $rows = array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read canonical hierarchy edges only.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT source_id,target_id,relation_type FROM %i WHERE relation_type IN (%s,%s,%s,%s,%s) AND source_id>0 AND target_id>0', $table, 'cluster_to_primary', 'cluster_to_hub_primary', 'hub_primary_to_hub_secondary', 'hub_primary_to_secondary', 'hub_secondary_to_category' ), ARRAY_A );
		return $rows;
	}

	public static function category_ids( $rows, $cluster, $primary, $secondary, $category ) {
		$sets = array();
		foreach ( array( 'cluster' => $cluster, 'primary' => $primary, 'secondary' => $secondary ) as $level => $id ) {
			if ( ! $id ) {
				continue;
			}
			$ids = array( (int) $id );
			$steps = array(
				'cluster' => array( 'cluster_to_primary', 'cluster_to_hub_primary' ),
				'primary' => array( 'hub_primary_to_hub_secondary', 'hub_primary_to_secondary' ),
				'secondary' => array( 'hub_secondary_to_category' ),
			);
			$started = false;
			foreach ( $steps as $step => $types ) {
				$started = $started || $step === $level;
				if ( ! $started ) {
					continue;
				}
				$next = array();
				foreach ( $rows as $edge ) {
					if ( in_array( $edge['relation_type'], $types, true ) && in_array( (int) $edge['source_id'], $ids, true ) ) {
						$next[] = (int) $edge['target_id'];
					}
				}
				$ids = array_values( array_unique( $next ) );
			}
			$sets[] = $ids;
		}
		if ( $category ) {
			$sets[] = array( (int) $category );
		}
		if ( ! $sets ) {
			return null;
		}
		$ids = array_shift( $sets );
		foreach ( $sets as $set ) {
			$ids = array_values( array_intersect( $ids, $set ) );
		}
		return $ids;
	}

	private static function hierarchy_select( $id, $label, $types ) {
		$ids = array();
		foreach ( self::relations() as $edge ) {
			if ( in_array( $edge['relation_type'], $types, true ) ) {
				$ids[] = (int) $edge['source_id'];
			}
		}
		if ( ! $ids ) {
			return;
		}
		$posts = get_posts( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'draft', 'private' ), 'post__in' => array_unique( $ids ), 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		printf( '<p><label for="%s">%s</label> <select id="%s"><option value="0">%s</option>', esc_attr( $id ), esc_html( $label ), esc_attr( $id ), esc_html__( 'All', 'taxocommerce-suppliers' ) );
		foreach ( $posts as $post ) {
			printf( '<option value="%d">%s</option>', absint( $post->ID ), esc_html( $post->post_title ) );
		}
		echo '</select></p>';
	}

	/** Extract only unambiguous, structured product observations; never infer stock from an error. */
	public static function observation( $html, $row ) {
		$products = array();
		if ( preg_match_all( '~<script\b[^>]*\btype\s*=\s*["\x27]application/ld\+json["\x27][^>]*>(.*?)</script\s*>~is', $html, $matches ) ) {
			foreach ( $matches[1] as $json ) {
				self::collect( json_decode( trim( $json ), true ), $products, 0 );
			}
		}
		$matched = array();
		$identity = array_filter( array_map( 'strval', array( $row['sku'] ?? '', $row['mpn'] ?? '', $row['proveedor_id_externo'] ?? '' ) ), 'strlen' );
		foreach ( $products as $product ) {
			$refs = array();
			foreach ( array( 'sku', 'mpn', 'productID' ) as $key ) {
				if ( isset( $product[ $key ] ) && is_scalar( $product[ $key ] ) ) {
					$refs[] = (string) $product[ $key ];
				}
			}
			if ( array_intersect( $refs, $identity ) ) {
				$matched[] = $product;
			}
		}
		$verified = 1 === count( $matched );
		$product = $verified ? $matched[0] : ( 1 === count( $products ) ? $products[0] : array() );
		$offer = $product['offers'] ?? array();
		if ( isset( $offer[0] ) && 1 === count( $offer ) ) {
			$offer = $offer[0];
		}
		$price = is_array( $offer ) ? ( $offer['price'] ?? null ) : null;
		$currency = is_array( $offer ) ? ( $offer['priceCurrency'] ?? '' ) : '';
		$availability = is_array( $offer ) ? ( $offer['availability'] ?? '' ) : '';
		$stock = '';
		if ( is_string( $availability ) && preg_match( '~^(?:https?://schema\.org/)?(InStock|OutOfStock|PreOrder|BackOrder|Discontinued|LimitedAvailability)$~D', $availability, $match ) ) {
			$stock = $match[1];
		}
		return array(
			'identity' => $verified ? 'matched' : 'unverified',
			'price' => is_numeric( $price ) && is_finite( (float) $price ) && (float) $price >= 0 ? (string) $price : '',
			'currency' => is_string( $currency ) && preg_match( '/^[A-Z]{3}$/D', $currency ) ? $currency : '',
			'stock' => $stock,
		);
	}

	private static function collect( $node, &$products, $depth ) {
		if ( ! is_array( $node ) || $depth > 12 || count( $products ) >= 50 ) {
			return;
		}
		$type = (array) ( $node['@type'] ?? array() );
		if ( in_array( 'Product', $type, true ) || in_array( 'https://schema.org/Product', $type, true ) ) {
			$products[] = $node;
		}
		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				self::collect( $value, $products, $depth + 1 );
			}
		}
	}

	/** One request per AJAX invocation; no redirects, cookies or credentials. */
	public static function check() {
		check_ajax_referer( 'tcsup_url_sync' );
		if ( ! self::permitted() ) {
			wp_send_json_error( array(), 403 );
		}
		$id = isset( $_POST['row_id'] ) ? absint( $_POST['row_id'] ) : 0;
		$cursor = isset( $_POST['cursor'] ) ? absint( $_POST['cursor'] ) : 0;
		$provider = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '';
		$object_id = isset( $_POST['object_id'] ) ? absint( $_POST['object_id'] ) : 0;
		$sku = isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( $_POST['sku'] ) ) : '';
		$cluster = isset( $_POST['cluster'] ) ? absint( $_POST['cluster'] ) : 0;
		$primary = isset( $_POST['primary'] ) ? absint( $_POST['primary'] ) : 0;
		$secondary = isset( $_POST['secondary'] ) ? absint( $_POST['secondary'] ) : 0;
		$category = isset( $_POST['category'] ) ? absint( $_POST['category'] ) : 0;
		$categories = self::category_ids( ( $cluster || $primary || $secondary ) ? self::relations() : array(), $cluster, $primary, $secondary, $category );
		if ( array() === $categories ) {
			wp_send_json_success( array( 'done' => true ) );
		}
		$woo_id = '' !== $sku && function_exists( 'wc_get_product_id_by_sku' ) ? absint( wc_get_product_id_by_sku( $sku ) ) : 0;
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current filtered inventory, one row per request.
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT c.* FROM %i c WHERE c.id>%d AND (%d=0 OR c.id=%d) AND (%s=%s OR c.proveedor=%s) AND (%d=0 OR c.object_id=%d) AND (%s=%s OR c.sku=%s OR (%d>0 AND c.object_id=%d)) AND (%d=0 OR EXISTS (SELECT 1 FROM %i tr INNER JOIN %i tt ON tr.term_taxonomy_id=tt.term_taxonomy_id WHERE tr.object_id=c.object_id AND tt.taxonomy=%s AND FIND_IN_SET(tt.term_id,%s)>0)) ORDER BY c.id ASC LIMIT 1',
			TCSUP_Schema::catalog_table(), $cursor, $id, $id, $provider, '', $provider, $object_id, $object_id, $sku, '', $sku, $woo_id, $woo_id, null === $categories ? 0 : 1, $wpdb->term_relationships, $wpdb->term_taxonomy, 'product_cat', implode( ',', $categories ?? array() )
		), ARRAY_A );
		if ( ! $row ) {
			wp_send_json_success( array( 'done' => true ) );
		}
		$url = trim( (string) ( $row['url_origen'] ?? '' ) );
		if ( '' === $url ) {
			$url = trim( (string) ( $row['url_canonica'] ?? '' ) );
		}
		$result = array( 'id' => absint( $row['id'] ), 'name' => (string) $row['nombre'], 'provider' => (string) $row['proveedor'], 'http' => 0, 'identity' => 'unverified', 'price' => '', 'currency' => '', 'stock' => '', 'status' => 'invalid_url', 'checked' => gmdate( 'c' ) );
		if ( wp_http_validate_url( $url ) ) {
			$response = wp_safe_remote_get( $url, array( 'timeout' => 8, 'redirection' => 0, 'limit_response_size' => 1048576, 'cookies' => array() ) );
			if ( is_wp_error( $response ) ) {
				$result['status'] = 'request_error';
			} else {
				$result['http'] = (int) wp_remote_retrieve_response_code( $response );
				$result['status'] = 200 === $result['http'] ? 'observed' : 'http_error';
				if ( 200 === $result['http'] ) {
					$result = array_merge( $result, self::observation( wp_remote_retrieve_body( $response ), $row ) );
					if ( '' === $result['price'] && '' === $result['stock'] ) {
						$result['status'] = 'no_data';
					}
				}
			}
		}
		// Per-user bounded recent log; do not overwrite inventory or WooCommerce values.
		$key = 'tcsup_url_log_' . get_current_user_id();
		$log = get_transient( $key );
		$log = is_array( $log ) ? $log : array();
		array_unshift( $log, $result );
		set_transient( $key, array_slice( $log, 0, 100 ), DAY_IN_SECONDS );
		wp_send_json_success( $result );
	}

	public static function link() {
		check_admin_referer( 'tcsup_url_link' );
		if ( ! self::permitted() ) {
			wp_die( esc_html__( 'You do not have permission to manage TaxoCommerce Suppliers.', 'taxocommerce-suppliers' ) );
		}
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$provider = isset( $_POST['provider'] ) ? TCSUP_Profiles::supplier_in_directory( sanitize_text_field( wp_unslash( $_POST['provider'] ) ) ) : '';
		$url = isset( $_POST['supplier_url'] ) ? esc_url_raw( wp_unslash( $_POST['supplier_url'] ), array( 'http', 'https' ) ) : '';
		$reference = isset( $_POST['reference'] ) ? sanitize_text_field( wp_unslash( $_POST['reference'] ) ) : '';
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
		global $wpdb;
		$status = 'invalid';
		if ( $product && 'product' === get_post_type( $product_id ) && 'trash' !== get_post_status( $product_id ) && current_user_can( 'edit_post', $product_id ) && $provider && wp_http_validate_url( $url ) && strlen( $reference ) <= 190 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Never replace an existing relationship.
			$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE object_id=%d OR (proveedor=%s AND (url_origen=%s OR url_canonica=%s OR proveedor_id_externo=%s)) LIMIT 1', TCSUP_Schema::catalog_table(), $product_id, $provider, $url, $url, $reference ) );
			if ( $existing || get_post_meta( $product_id, '_seo_proveedor_catalogo_id', true ) || get_post_meta( $product_id, '_seo_proveedor', true ) ) {
				$status = 'conflict';
			} else {
				$external = '' !== $reference ? $reference : 'url:' . hash( 'sha256', $url );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit administrator-approved source registration.
				$inserted = $wpdb->insert( TCSUP_Schema::catalog_table(), array(
					'proveedor' => $provider, 'proveedor_id_externo' => $external, 'sku' => $reference,
					'url_origen' => $url, 'url_canonica' => $url, 'nombre' => sanitize_text_field( $product->get_name() ),
					'object_id' => $product_id, 'moneda' => get_woocommerce_currency(),
					'estado_seleccion' => 'aceptado', 'estado_sincronizacion' => 'pendiente',
					'primera_importacion' => current_time( 'mysql' ), 'ultima_importacion' => current_time( 'mysql' ),
				) );
				if ( $inserted ) {
					$catalog_id = (int) $wpdb->insert_id;
					update_post_meta( $product_id, '_seo_proveedor_catalogo_id', $catalog_id );
					update_post_meta( $product_id, '_seo_proveedor', $provider );
					update_post_meta( $product_id, '_seo_proveedor_id_externo', $external );
					update_post_meta( $product_id, '_seo_proveedor_sku', $reference );
					update_post_meta( $product_id, '_seo_proveedor_url_origen', $url );
					update_post_meta( $product_id, '_seo_proveedor_url_canonica', $url );
					$status = 'linked';
				} else {
					$status = 'conflict';
				}
			}
		}
		wp_safe_redirect( add_query_arg( 'link_status', $status, self::url() ) );
		exit;
	}

	public static function render() {
		if ( ! self::permitted() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice.
		$status = isset( $_GET['link_status'] ) ? sanitize_key( wp_unslash( $_GET['link_status'] ) ) : '';
		$log = get_transient( 'tcsup_url_log_' . get_current_user_id() );
		?>
		<section class="tcsup-card">
			<h2><?php esc_html_e( 'Sincronización', 'taxocommerce-suppliers' ); ?></h2>
			<p><?php esc_html_e( 'Consulta de solo lectura: no modifica precios, stock ni contenidos.', 'taxocommerce-suppliers' ); ?></p>
			<p><label for="tcsup-sync-provider"><?php esc_html_e( 'Supplier', 'taxocommerce-suppliers' ); ?></label> <?php self::provider_select( 'tcsup-sync-provider' ); ?></p>
			<p><label for="tcsup-sync-id"><?php esc_html_e( 'ID de inventario (opcional)', 'taxocommerce-suppliers' ); ?></label> <input id="tcsup-sync-id" type="number" min="1" /></p>
			<p><label for="tcsup-sync-object"><?php esc_html_e( 'WooCommerce product ID', 'taxocommerce-suppliers' ); ?></label> <input id="tcsup-sync-object" type="number" min="1" /></p>
			<p><label for="tcsup-sync-sku"><?php esc_html_e( 'SKU', 'taxocommerce-suppliers' ); ?></label> <input id="tcsup-sync-sku" type="text" /></p>
			<?php
			self::hierarchy_select( 'tcsup-sync-cluster', __( 'Clúster', 'taxocommerce-suppliers' ), array( 'cluster_to_primary', 'cluster_to_hub_primary' ) );
			self::hierarchy_select( 'tcsup-sync-primary', __( 'Hub primario', 'taxocommerce-suppliers' ), array( 'hub_primary_to_hub_secondary', 'hub_primary_to_secondary' ) );
			self::hierarchy_select( 'tcsup-sync-secondary', __( 'Hub secundario', 'taxocommerce-suppliers' ), array( 'hub_secondary_to_category' ) );
			?>
			<p><label for="tcsup-sync-category"><?php esc_html_e( 'Category', 'taxocommerce-suppliers' ); ?></label>
			<?php wp_dropdown_categories( array( 'taxonomy' => 'product_cat', 'id' => 'tcsup-sync-category', 'name' => 'tcsup-sync-category', 'show_option_all' => __( 'All', 'taxocommerce-suppliers' ), 'hide_empty' => false, 'hierarchical' => true ) ); ?></p>
			<button type="button" class="button button-primary" id="tcsup-sync-start" data-nonce="<?php echo esc_attr( wp_create_nonce( 'tcsup_url_sync' ) ); ?>"><?php esc_html_e( 'Comprobar enlaces', 'taxocommerce-suppliers' ); ?></button>
			<button type="button" class="button" id="tcsup-sync-stop"><?php esc_html_e( 'Stop', 'taxocommerce-suppliers' ); ?></button>
			<p id="tcsup-sync-progress" role="status"></p>
			<table class="widefat striped"><thead><tr><th>ID</th><th><?php esc_html_e( 'Supplier', 'taxocommerce-suppliers' ); ?></th><th><?php esc_html_e( 'Product', 'taxocommerce-suppliers' ); ?></th><th>HTTP</th><th><?php esc_html_e( 'Status', 'taxocommerce-suppliers' ); ?></th><th><?php esc_html_e( 'Price', 'taxocommerce-suppliers' ); ?></th><th><?php esc_html_e( 'Stock', 'taxocommerce-suppliers' ); ?></th><th><?php esc_html_e( 'Date', 'taxocommerce-suppliers' ); ?></th></tr></thead><tbody id="tcsup-sync-results">
			<?php foreach ( is_array( $log ) ? $log : array() as $entry ) : ?>
				<tr><?php foreach ( array( 'id', 'provider', 'name', 'http', 'status', 'price', 'stock', 'checked' ) as $key ) : ?><td><?php echo esc_html( (string) ( $entry[ $key ] ?? '' ) . ( 'status' === $key ? ' / ' . ( $entry['identity'] ?? '' ) : ( 'price' === $key ? ' ' . ( $entry['currency'] ?? '' ) : '' ) ) ); ?></td><?php endforeach; ?></tr>
			<?php endforeach; ?>
			</tbody></table>
		</section>
		<section class="tcsup-card">
			<h2><?php esc_html_e( 'Vincular producto manual', 'taxocommerce-suppliers' ); ?></h2>
			<?php if ( $status ) : ?><p role="status"><?php echo esc_html( $status ); ?></p><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tcsup_url_link" />
				<?php wp_nonce_field( 'tcsup_url_link' ); ?>
				<p><label for="tcsup-link-product"><?php esc_html_e( 'WooCommerce product ID', 'taxocommerce-suppliers' ); ?></label> <input id="tcsup-link-product" name="product_id" type="number" min="1" required /></p>
				<p><label for="tcsup-link-provider"><?php esc_html_e( 'Supplier', 'taxocommerce-suppliers' ); ?></label> <?php self::provider_select( 'tcsup-link-provider', 'provider', true ); ?></p>
				<p><label for="tcsup-link-url"><?php esc_html_e( 'Supplier URL', 'taxocommerce-suppliers' ); ?></label> <input id="tcsup-link-url" name="supplier_url" type="url" required /></p>
				<p><label for="tcsup-link-ref"><?php esc_html_e( 'Supplier SKU', 'taxocommerce-suppliers' ); ?></label> <input id="tcsup-link-ref" name="reference" type="text" maxlength="190" /></p>
				<?php submit_button( __( 'Vincular producto manual', 'taxocommerce-suppliers' ) ); ?>
			</form>
		</section>
		<?php
	}
}
