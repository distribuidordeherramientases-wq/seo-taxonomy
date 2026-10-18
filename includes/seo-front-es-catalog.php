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

    if ( in_array( strtoupper( (string) ( $GLOBALS['dht_pilot_market'] ?? '' ) ), array( 'US', 'DE', 'NL' ), true ) ) {
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

/** Public Spanish WP_Query/WC_Product_Query results accept ES and legacy unmarked entries only. */
add_action( 'pre_get_posts', static function ( $query ) {
    if ( ! $query instanceof WP_Query || ! seo_front_es_is_storefront() ) {
        return;
    }

    $types = (array) $query->get( 'post_type' );
    if ( array( 'product' ) !== array_values( $types ) ) {
        return;
    }

    // The main singular and taxonomy queries must resolve unmodified so
    // the international template router can identify their market.
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
    $query->set( '_seo_es_catalog_scoped', true );

    $old_meta = $query->get( 'meta_query' );
    $meta_query = array( 'relation' => 'AND' );
    if ( is_array( $old_meta ) && $old_meta ) {
        $meta_query[] = $old_meta;
    }
    // The original Spanish WooCommerce catalog predates country tagging.
    // Missing/empty market fields are therefore legacy ES, while explicit
    // foreign values never enter the Spanish product listings.
    foreach ( array(
        '_seo_pais_origen' => array( '', 'ES' ),
        '_seo_locale'      => array( '', 'es-ES' ),
    ) as $key => $spanish_values ) {
        $meta_query[] = array(
            'relation' => 'OR',
            array( 'key' => $key, 'compare' => 'NOT EXISTS' ),
            array( 'key' => $key, 'value' => $spanish_values, 'compare' => 'IN' ),
        );
    }
    $query->set( 'meta_query', $meta_query );

    $excluded = seo_front_es_international_category_ids();
    if ( $excluded ) {
        $old_tax = $query->get( 'tax_query' );
        $tax_query = array( 'relation' => 'AND' );
        if ( is_array( $old_tax ) && $old_tax ) {
            $tax_query[] = $old_tax;
        }
        $tax_query[] = array(
            'taxonomy'         => 'product_cat',
            'field'            => 'term_id',
            'terms'            => $excluded,
            'include_children' => false,
            'operator'         => 'NOT IN',
        );
        $query->set( 'tax_query', $tax_query );
    }
}, 999 );
