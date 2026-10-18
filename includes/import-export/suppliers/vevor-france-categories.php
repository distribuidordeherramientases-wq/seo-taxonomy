<?php
/**
 * French VEVOR pilot category hierarchy.
 *
 * Creates only WordPress categories. The supplier CSV and regional WooCommerce
 * drafts remain unchanged; merchants classify drafts later in their own CSV.
 * Explicit admin action, never implicit on page load or supplier import.
 */
defined( 'ABSPATH' ) || exit;

/** Stable country-prefixed term identifier, independent from local WP term IDs. */
function seo_vevor_fr_category_slug( $segments ) {
    $parts = array_map( 'sanitize_title', (array) $segments );
    $parts = array_values( array_filter( $parts ) );
    return 'vevor-fr' . ( $parts ? '-' . implode( '-', $parts ) : '' );
}

/** Create missing terms only; do not reparent or overwrite existing categories. */
function seo_vevor_fr_ensure_category( $name, $slug, $parent, $description ) {
    $found = get_term_by( 'slug', $slug, 'product_cat' );
    if ( $found instanceof WP_Term ) {
        if ( (int) $found->parent !== (int) $parent ) {
            return new WP_Error( 'seo_fr_wrong_parent', 'La categorie ' . $slug . ' existe avec un parent different.' );
        }
        $id = (int) $found->term_id;
        $created = false;
    } else {
        $new = wp_insert_term( $name, 'product_cat', array(
            'slug'        => $slug,
            'parent'      => (int) $parent,
            'description' => $description,
        ) );
        if ( is_wp_error( $new ) ) {
            return $new;
        }
        $id = absint( $new['term_id'] ?? 0 );
        $created = true;
    }
    if ( ! $id ) {
        return new WP_Error( 'seo_fr_missing_id', 'No se pudo recuperar la categoria francesa.' );
    }
    update_term_meta( $id, '_seo_locale', 'fr-FR' );
    update_term_meta( $id, '_seo_pais_origen', 'FR' );
    if ( ! get_term_meta( $id, 'seo_excerpt', true ) ) {
        update_term_meta( $id, 'seo_excerpt', $description );
    }
    return array( 'id' => $id, 'created' => $created );
}

/**
 * Build France > main category > subcategory from actual FR categoryPath.
 * The 2026-10-18 feed contains 29 main categories and 214 main/sub pairs.
 * Reading the supplier catalogue keeps this correct for the next VEVOR feed.
 */
function seo_vevor_fr_create_categories() {
    global $wpdb;
    if ( ! taxonomy_exists( 'product_cat' ) ) {
        return new WP_Error( 'seo_fr_no_wc', 'WooCommerce no esta disponible.' );
    }
    $table = $wpdb->prefix . 'seo_proveedores_productos';
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( $exists !== $table ) {
        return new WP_Error( 'seo_fr_no_catalog', 'La tabla de proveedores no esta disponible.' );
    }
    $paths = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT categoria_proveedor FROM {$table}
         WHERE proveedor = %s AND pais_origen = %s
         AND categoria_proveedor IS NOT NULL AND categoria_proveedor <> ''
         ORDER BY categoria_proveedor LIMIT 3000",
        'VEVOR', 'FR'
    ) );
    if ( ! is_array( $paths ) || ! $paths ) {
        return new WP_Error( 'seo_fr_no_rows', 'Importa primero el CSV original VEVOR Francia al catalogo de proveedores.' );
    }

    $tree = array();
    foreach ( $paths as $path ) {
        $segments = preg_split( '/\\s*>\\s*/u', (string) $path );
        $segments = array_values( array_filter( array_map( 'trim', (array) $segments ), 'strlen' ) );
        if ( ! $segments ) {
            continue;
        }
        // Category/subcategory from the supplier, no automatic translation.
        $first = $segments[0];
        $slug1 = seo_vevor_fr_category_slug( array( $first ) );
        if ( ! isset( $tree[ $slug1 ] ) ) {
            $tree[ $slug1 ] = array( 'name' => $first, 'sub' => array() );
        }
        if ( ! empty( $segments[1] ) ) {
            $second = $segments[1];
            $slug2 = seo_vevor_fr_category_slug( array( $first, $second ) );
            $tree[ $slug1 ]['sub'][ $slug2 ] = $second;
        }
    }
    if ( ! $tree ) {
        return new WP_Error( 'seo_fr_empty_tree', 'No hay rutas de categorias validas en el feed.' );
    }
    $stats = array( 'created' => 0, 'existing' => 0, 'errors' => 0, 'main' => 0, 'sub' => 0, 'details' => array() );
    $root = seo_vevor_fr_ensure_category(
        'France - VEVOR', 'vevor-fr', 0,
        'Explorez les catégories d’outillage, de jardin, de maison et d’équipement VEVOR en France. Les commandes et le service après-vente sont assurés directement par VEVOR.'
    );
    if ( is_wp_error( $root ) ) {
        return $root;
    }
    $stats[ $root['created'] ? 'created' : 'existing' ]++;
    foreach ( $tree as $slug1 => $main ) {
        $main_name = sanitize_text_field( $main['name'] );
        $main_term = seo_vevor_fr_ensure_category(
            $main_name, $slug1, $root['id'],
            sprintf( 'Explorez les équipements de la catégorie %s proposés par VEVOR en France. Consultez les caractéristiques de chaque référence avant de commander sur VEVOR.', $main_name )
        );
        if ( is_wp_error( $main_term ) ) {
            ++$stats['errors'];
            if ( count( $stats['details'] ) < 10 ) { $stats['details'][] = $slug1 . ': ' . $main_term->get_error_message(); }
            continue;
        }
        ++$stats['main'];
        ++$stats[ $main_term['created'] ? 'created' : 'existing' ];
        foreach ( $main['sub'] as $slug2 => $sub_name ) {
            $sub_name = sanitize_text_field( $sub_name );
            $sub_term = seo_vevor_fr_ensure_category(
                $sub_name, $slug2, $main_term['id'],
                sprintf( 'Découvrez les produits VEVOR de la catégorie %s, dans l’univers %s. Vérifiez les dimensions, les compatibilités, le prix et la disponibilité chez VEVOR.', $sub_name, $main_name )
            );
            if ( is_wp_error( $sub_term ) ) {
                ++$stats['errors'];
                if ( count( $stats['details'] ) < 10 ) { $stats['details'][] = $slug2 . ': ' . $sub_term->get_error_message(); }
                continue;
            }
            ++$stats['sub'];
            ++$stats[ $sub_term['created'] ? 'created' : 'existing' ];
        }
    }
    // New terms are not linked to WooCommerce products until manual review.
    return $stats;
}

function seo_vevor_fr_category_admin_action() {
    if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Permisos insuficientes.' );
    }
    check_admin_referer( 'seo_vevor_fr_categories_create' );
    $report = seo_vevor_fr_create_categories();
    set_transient( 'seo_vevor_fr_category_report_' . get_current_user_id(), $report, 15 * MINUTE_IN_SECONDS );
    wp_safe_redirect( add_query_arg( array(
        'page' => 'seo-import-export', 'seo_ie_tab' => 'catalogo-proveedores',
        'f_proveedor' => 'VEVOR', 'seo_fr_categories_done' => '1',
    ), admin_url( 'admin.php' ) ) );
    exit;
}
add_action( 'admin_post_seo_vevor_fr_categories_create', 'seo_vevor_fr_category_admin_action' );

/** Visible merchant control; never creates terms simply by loading an admin page. */
function seo_vevor_fr_render_categories_admin() {
    if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( isset( $_GET['seo_fr_categories_done'] ) ) {
        $report = get_transient( 'seo_vevor_fr_category_report_' . get_current_user_id() );
        delete_transient( 'seo_vevor_fr_category_report_' . get_current_user_id() );
        if ( is_wp_error( $report ) ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html( $report->get_error_message() ) . '</p></div>';
        } elseif ( is_array( $report ) ) {
            echo '<div class="notice notice-success inline"><p>France : '
                . esc_html( (string) $report['created'] ) . ' categories creees, '
                . esc_html( (string) $report['existing'] ) . ' deja presentes, '
                . esc_html( (string) $report['main'] ) . ' principales, '
                . esc_html( (string) $report['sub'] ) . ' sous-categories, '
                . esc_html( (string) $report['errors'] ) . ' erreurs. '
                . esc_html( implode( ' | ', $report['details'] ) ) . '</p></div>';
        }
    }
    echo '<div class="notice notice-info inline" style="padding:12px;margin:14px 0;">'
        . '<p><strong>VEVOR France · categories en francais</strong></p>'
        . '<p>Construit uniquement France &rarr; categories &rarr; sous-categories a partir des '
        . '<code>categoryPath</code> francais deja importes. Action repetable sans doublons. '
        . 'Ne modifie pas les CSV, ne reclassifie pas les produits et ne les publie pas.</p>'
        . '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
    wp_nonce_field( 'seo_vevor_fr_categories_create' );
    echo '<input type="hidden" name="action" value="seo_vevor_fr_categories_create">'
        . '<button type="submit" class="button button-secondary">Creer / completer les categories France</button></form></div>';
}

add_action( 'seo_vevor_fr_categories_card', 'seo_vevor_fr_render_categories_admin' );
