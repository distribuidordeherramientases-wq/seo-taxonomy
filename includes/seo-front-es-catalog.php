<?php
/**
 * Public Spanish storefront: ES products and legacy entries without a market.
 * Explicitly non-ES products and categories belong to their own storefronts.
 *
 * Restrict public listings, not stored categories/products or admin workflows.
 * The international pilot pages retain their own unfiltered queries.
 */
defined( 'ABSPATH' ) || exit;

/**
 * International category IDs, including descendants of a regional root.
 * Uses one prepared query per request instead of a term-by-term lookup.
 */
function seo_front_es_international_category_ids() {
    static $excluded = null;
    if ( null !== $excluded ) {
        return $excluded;
    }

    global $wpdb;
    $excluded = array();
    $sql = $wpdb->prepare(
        "SELECT t.term_id, t.slug, tt.parent, tm.meta_value AS locale
         FROM {$wpdb->terms} t
         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
         LEFT JOIN {$wpdb->termmeta} tm
           ON tm.term_id = t.term_id AND tm.meta_key = %s
         WHERE tt.taxonomy = %s",
        '_seo_locale',
        'product_cat'
    );
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The SQL above has already been prepared.
    $rows = $wpdb->get_results( $sql, ARRAY_A );
    if ( ! is_array( $rows ) ) {
        return $excluded;
    }

    $parents = array();
    $market_roots = array();
    foreach ( $rows as $row ) {
        $id = absint( $row['term_id'] ?? 0 );
        if ( ! $id ) {
            continue;
        }
        $parents[ $id ] = absint( $row['parent'] ?? 0 );
        $slug = strtolower( (string) ( $row['slug'] ?? '' ) );
        $locale = strtolower( (string) ( $row['locale'] ?? '' ) );
        if (
            ( '' !== $locale && 'es-es' !== $locale )
            || preg_match( '/^(?:vevor-)?(?:us|usa|eua|eeuu|estados-unidos|united-states|en-us)(?:-|$)/', $slug )
            || preg_match( '/^(?:vevor-)?(?:(?:de|deu)(?:$|-(?:best-sellers|bestsellers|kategorie|category|produkte|products)(?:-|$))|(?:deutschland|alemania|germany|de-de)(?:-|$))/', $slug )
            || preg_match( '/^(?:vevor-)?(?:nl|nld|nederland|netherlands|holanda|paises-bajos|nl-nl)(?:-|$)/', $slug )
            || preg_match( '/^(?:vevor-)?(?:fr|fra|france|fr-fr)(?:-|$)/', $slug )
        ) {
            $market_roots[ $id ] = true;
        }
    }

    foreach ( $parents as $id => $parent ) {
        $cursor = $id;
        $visited = array();
        while ( $cursor && isset( $parents[ $cursor ] ) && ! isset( $visited[ $cursor ] ) ) {
            if ( isset( $market_roots[ $cursor ] ) ) {
                $excluded[] = (int) $id;
                break;
            }
            $visited[ $cursor ] = true;
            $cursor = $parents[ $cursor ];
        }
    }

    return $excluded;
}

/** Only a public Spanish view is in scope. Never alter admin, REST or workers. */
function seo_front_es_is_storefront() {
    if (
        is_admin()
        || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
        || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
        || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
        || ( defined( 'WP_CLI' ) && WP_CLI )
        || ( function_exists( 'is_feed' ) && is_feed() )
    ) {
        return false;
    }

    if ( in_array( strtoupper( (string) ( $GLOBALS['dht_pilot_market'] ?? '' ) ), array( 'US', 'DE', 'NL', 'FR' ), true ) ) {
        return false;
    }

    if ( function_exists( 'is_tax' ) && is_tax( 'product_cat' ) ) {
        $term = get_queried_object();
        if ( $term instanceof WP_Term && in_array( (int) $term->term_id, seo_front_es_international_category_ids(), true ) ) {
            return false;
        }
    }

    if ( function_exists( 'is_singular' ) && is_singular( 'product' ) ) {
        $id = absint( get_queried_object_id() );
        $market = strtoupper( (string) get_post_meta( $id, '_seo_pais_origen', true ) );
        $locale = strtolower( (string) get_post_meta( $id, '_seo_locale', true ) );
        if (
            ( '' !== $locale && 'es-es' !== $locale )
            || ( '' !== $market && 'ES' !== $market )
        ) {
            return false;
        }
    }

    return true;
}

/**
 * Apply exclusions before LIMIT/ORDER BY, so Spanish category lists still
 * receive their requested number of entries instead of losing slots.
 */
add_filter( 'get_terms_args', static function ( $args, $taxonomies ) {
    // Country flag navigation must resolve the real foreign roots even from
    // an ES storefront, without exposing them in Spanish category listings.
    if ( ! empty( $args['seo_pilot_country_switcher'] ) ) {
        return $args;
    }
    if ( ! did_action( 'wp' ) || ! seo_front_es_is_storefront() ) {
        return $args;
    }
    $requested = (array) ( $args['taxonomy'] ?? $taxonomies );
    if ( array( 'product_cat' ) !== array_values( $requested ) ) {
        return $args;
    }
    // Product -> category relationships must remain accessible to the
    // international router; only filter directory/list queries.
    if ( ! empty( $args['object_ids'] ) || ! empty( $args['include'] ) ) {
        return $args;
    }
    $excluded = seo_front_es_international_category_ids();
    if ( $excluded ) {
        $args['exclude'] = array_values( array_unique( array_merge(
            array_map( 'absint', (array) ( $args['exclude'] ?? array() ) ),
            $excluded
        ) ) );
    }
    return $args;
}, 50, 2 );

/**
 * Reusable market constraints for both native WP_Query and WC_Product_Query.
 * The original ES catalog predates explicit country metadata, so missing
 * or empty country/locale values remain Spanish unless other evidence exists.
 */
function seo_front_es_scope_product_query_args( $args ) {
    $original_meta = isset( $args['meta_query'] ) && is_array( $args['meta_query'] )
        ? $args['meta_query'] : array();
    $meta_query = array( 'relation' => 'AND' );
    if ( $original_meta ) {
        $meta_query[] = $original_meta;
    }

    foreach ( array(
        '_seo_pais_origen' => array( '', 'ES' ),
        '_seo_locale'      => array( '', 'es-ES' ),
    ) as $key => $es_values ) {
        $meta_query[] = array(
            'relation' => 'OR',
            array( 'key' => $key, 'compare' => 'NOT EXISTS' ),
            array( 'key' => $key, 'value' => $es_values, 'compare' => 'IN' ),
        );
    }
    $args['meta_query'] = $meta_query;

    $excluded = seo_front_es_international_category_ids();
    if ( $excluded ) {
        $previous = isset( $args['tax_query'] ) && is_array( $args['tax_query'] )
            ? $args['tax_query'] : array();
        $tax_query = array( 'relation' => 'AND' );
        if ( $previous ) {
            $tax_query[] = $previous;
        }
        $tax_query[] = array(
            'taxonomy'         => 'product_cat',
            'field'            => 'term_id',
            'terms'            => $excluded,
            'include_children' => false,
            'operator'         => 'NOT IN',
        );
        $args['tax_query'] = $tax_query;
    }

    $args['_seo_es_catalog_scoped'] = true;
    return $args;
}

/**
 * Homepage/shop use wc_get_products(), which translates its own query args.
 * Apply the country policy before WooCommerce's LIMIT / ORDER BY to ensure
 * that Spanish products fill all 12/10 cards, not just the first surviving IDs.
 *
 * @see https://developer.woocommerce.com/docs/features/products/wc-get-products/
 */
add_filter( 'woocommerce_product_data_store_cpt_get_products_query', static function ( $wp_query_args, $query_vars ) {
    if (
        empty( $query_vars['seo_es_catalog_only'] )
        || ! seo_front_es_is_storefront()
    ) {
        return $wp_query_args;
    }
    return seo_front_es_scope_product_query_args( $wp_query_args );
}, 50, 2 );


/**
 * Last-line storefront safeguard: never render a foreign product card in ES.
 * An early provider import could have created a simple product with missing
 * region metadata; its supplier link or regional SKU still identifies it.
 *
 * This protects purchase buttons even if a third-party WooCommerce query
 * bypasses the datastore and posts_where hooks.
 */
function seo_front_es_product_is_visible( $product ) {
    if ( ! $product instanceof WC_Product ) {
        return false;
    }
    $id = absint( $product->get_id() );
    if ( ! $id ) {
        return false;
    }
    static $checked = array();
    if ( isset( $checked[ $id ] ) ) {
        return $checked[ $id ];
    }

    $country = strtoupper( trim( (string) get_post_meta( $id, '_seo_pais_origen', true ) ) );
    $locale  = strtolower( trim( (string) get_post_meta( $id, '_seo_locale', true ) ) );
    if (
        ( '' !== $country && 'ES' !== $country )
        || ( '' !== $locale && 'es-es' !== $locale )
    ) {
        return $checked[ $id ] = false;
    }

    $sku = strtolower( (string) $product->get_sku() );
    if ( preg_match( '/^vevor-(?:us|de|nl|fr)-/', $sku ) ) {
        return $checked[ $id ] = false;
    }

    $assigned = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'ids' ) );
    if ( is_array( $assigned ) && array_intersect( array_map( 'absint', $assigned ), seo_front_es_international_category_ids() ) ) {
        return $checked[ $id ] = false;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'seo_proveedores_productos';
    static $has_supplier_table = null;
    if ( null === $has_supplier_table ) {
        $has_supplier_table = $table === $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
        );
    }
    if ( $has_supplier_table ) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- A trusted WordPress-prefixed table, with all values bound.
        $is_foreign = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE object_id = %d AND proveedor = %s
             AND pais_origen NOT IN (%s,%s) LIMIT 1",
            $id, 'VEVOR', '', 'ES'
        ) );
        if ( $is_foreign ) {
            return $checked[ $id ] = false;
        }
    }
    return $checked[ $id ] = true;
}

/** Public Spanish native WordPress product loops also get the same constraints. */
add_action( 'pre_get_posts', static function ( $query ) {
    if ( ! $query instanceof WP_Query || ! seo_front_es_is_storefront() ) {
        return;
    }
    // Homepage has its own cached VEVOR showcase; do not attach costly
    // per-country postmeta and supplier NOT EXISTS filters to its category images.
    // Category, shop, search and product views retain their existing protections.
    if ( function_exists( 'is_front_page' ) && is_front_page() ) {
        return;
    }
    $types = (array) $query->get( 'post_type' );
    if ( array( 'product' ) !== array_values( $types ) ) {
        return;
    }
    // Do not rewrite the main singular/term query: it is needed to route
    // foreign URLs to the correct country template.
    if ( $query->is_main_query() && ( $query->is_singular() || $query->is_tax( 'product_cat' ) ) ) {
        return;
    }
    if (
        ! did_action( 'wp' )
        && ! ( $query->is_main_query() && ( $query->is_search() || $query->is_post_type_archive( 'product' ) ) )
    ) {
        return;
    }
    if ( $query->get( '_seo_es_catalog_scoped' ) ) {
        return;
    }

    $scoped = seo_front_es_scope_product_query_args( array(
        'meta_query' => $query->get( 'meta_query' ),
        'tax_query'  => $query->get( 'tax_query' ),
    ) );
    $query->set( 'meta_query', $scoped['meta_query'] );
    if ( isset( $scoped['tax_query'] ) ) {
        $query->set( 'tax_query', $scoped['tax_query'] );
    }
    $query->set( '_seo_es_catalog_scoped', true );
}, 999 );

/**
 * Secondary legacy protection: some VEVOR products were originally imported
 * as simple products without the country meta. Supplier inventory remains
 * the source of truth for their object_id -> pais_origen relationship.
 *
 * The supplier table has an object_id index, so the correlated NOT EXISTS is
 * inexpensive for the small public listings. Never run on import/admin/REST.
 * Regional SKU prefixes additionally catch orphaned legacy records.
 */
add_filter( 'posts_where', static function ( $where, $query ) {
    if (
        ! $query instanceof WP_Query
        || ! $query->get( '_seo_es_catalog_scoped' )
        || ! seo_front_es_is_storefront()
    ) {
        return $where;
    }

    global $wpdb;
    $where .= $wpdb->prepare(
        " AND NOT EXISTS (
            SELECT 1 FROM {$wpdb->postmeta} es_sku
            WHERE es_sku.post_id = {$wpdb->posts}.ID
              AND es_sku.meta_key = %s
              AND (
                es_sku.meta_value LIKE %s
                OR es_sku.meta_value LIKE %s
                OR es_sku.meta_value LIKE %s
                OR es_sku.meta_value LIKE %s
              )
        )",
        '_sku',
        $wpdb->esc_like( 'vevor-us-' ) . '%',
        $wpdb->esc_like( 'vevor-de-' ) . '%',
        $wpdb->esc_like( 'vevor-nl-' ) . '%',
        $wpdb->esc_like( 'vevor-fr-' ) . '%'
    );

    static $supplier_table_present = null;
    if ( null === $supplier_table_present ) {
        $supplier_table = $wpdb->prefix . 'seo_proveedores_productos';
        $supplier_table_present = $supplier_table === $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $supplier_table ) )
        );
    }
    if ( $supplier_table_present ) {
        $supplier_table = $wpdb->prefix . 'seo_proveedores_productos';
        $where .= $wpdb->prepare(
            " AND NOT EXISTS (
                SELECT 1 FROM {$supplier_table} es_supplier
                WHERE es_supplier.object_id = {$wpdb->posts}.ID
                  AND es_supplier.proveedor = %s
                  AND es_supplier.pais_origen NOT IN ( %s, %s )
            )",
            'VEVOR',
            '',
            'ES'
        );
    }

    return $where;
}, 20, 2 );
