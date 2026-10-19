<?php
/**
 * Bootstrap only the French pilot landing category, not the supplier
 * catalogue, its subcategories or its products.
 *
 * Creating a single missing root during an authorised admin page view is
 * idempotent and avoids a public request writing to the taxonomy database.
 * The experimental market switcher can then link to a real FR URL.
 */
defined( 'ABSPATH' ) || exit;

add_action( 'admin_init', static function () {
    if (
        ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
        || ! current_user_can( 'manage_woocommerce' )
        || ! taxonomy_exists( 'product_cat' )
    ) {
        return;
    }

    $root = get_term_by( 'slug', 'vevor-fr', 'product_cat' );
    if ( ! $root instanceof WP_Term ) {
        $created = wp_insert_term(
            'France - VEVOR',
            'product_cat',
            array(
                'slug'        => 'vevor-fr',
                'parent'      => 0,
                'description' => 'Découvrez les catégories d’outillage et d’équipement VEVOR pour la France. Les achats et le service après-vente sont assurés directement par VEVOR.',
            )
        );
        if ( is_wp_error( $created ) ) {
            return;
        }
        $root = get_term( absint( $created['term_id'] ?? 0 ), 'product_cat' );
    }

    // A pre-existing slug under another category is not a valid country home:
    // leave it untouched rather than silently changing its parent or linking
    // users into the wrong regional hierarchy.
    if ( ! $root instanceof WP_Term || (int) $root->parent !== 0 ) {
        return;
    }
    if ( 'fr-FR' !== get_term_meta( $root->term_id, '_seo_locale', true ) ) {
        update_term_meta( $root->term_id, '_seo_locale', 'fr-FR' );
    }
    if ( 'FR' !== get_term_meta( $root->term_id, '_seo_pais_origen', true ) ) {
        update_term_meta( $root->term_id, '_seo_pais_origen', 'FR' );
    }
}, 25 );
