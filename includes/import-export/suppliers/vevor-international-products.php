<?php
/**
 * Create VEVOR regional WooCommerce drafts after supplier approval.
 * All new drafts use the configured WooCommerce default product category;
 * international category assignment is a later editorial step.
 * No bulk publication or edits to Spanish products.
 */
defined( 'ABSPATH' ) || exit;

function seo_vevor_region_category_market( $category_id ) {
    $term = get_term( absint( $category_id ), 'product_cat' );
    if ( ! $term instanceof WP_Term || is_wp_error( $term ) ) {
        return '';
    }
    // Country roots are not sufficiently precise destinations for products.
    if ( in_array( $term->slug, array( 'vevor-us', 'vevor-de', 'vevor-nl', 'vevor-fr' ), true ) ) {
        return '';
    }
    $ancestors = get_ancestors( $term->term_id, 'product_cat', 'taxonomy' );
    foreach ( (array) $ancestors as $ancestor_id ) {
        $ancestor = get_term( absint( $ancestor_id ), 'product_cat' );
        if ( $ancestor instanceof WP_Term ) {
            if ( 'vevor-us' === $ancestor->slug ) {
                return 'US';
            }
            if ( 'vevor-de' === $ancestor->slug ) {
                return 'DE';
            }
            if ( 'vevor-nl' === $ancestor->slug ) {
                return 'NL';
            }
            if ( 'vevor-fr' === $ancestor->slug ) {
                return 'FR';
            }
        }
    }
    return '';
}

function seo_vevor_region_product_market( $product_id ) {
    // Drafts awaiting classification have only the WooCommerce default category.
    $meta_market = strtoupper( (string) get_post_meta( absint( $product_id ), '_seo_pais_origen', true ) );
    $provider = (string) get_post_meta( absint( $product_id ), '_seo_proveedor', true );
    if ( in_array( $meta_market, array( 'US', 'DE', 'NL', 'FR' ), true ) && 0 === strcasecmp( $provider, 'VEVOR' ) ) {
        return $meta_market;
    }
    $terms = wp_get_post_terms( absint( $product_id ), 'product_cat', array( 'fields' => 'ids' ) );
    if ( is_wp_error( $terms ) ) {
        return '';
    }
    $found = array();
    foreach ( (array) $terms as $id ) {
        $market = seo_vevor_region_category_market( $id );
        if ( '' !== $market ) {
            $found[ $market ] = true;
        }
    }
    return count( $found ) === 1 ? (string) key( $found ) : '';
}

function seo_vevor_region_existing_product( array $row, $market, $regional_sku ) {
    $linked_id = absint( $row['object_id'] ?? 0 );
    if ( $linked_id && 'product' === get_post_type( $linked_id ) && 'trash' !== get_post_status( $linked_id ) ) {
        // Never overwrite or reassign a Spanish product.
        if ( seo_vevor_region_product_market( $linked_id ) !== $market ) {
            return new WP_Error( 'vevor_wrong_product', 'La oferta apunta a un producto de otro mercado; no se modifica.' );
        }
        return $linked_id;
    }

    $sku_owner = function_exists( 'wc_get_product_id_by_sku' ) ? absint( wc_get_product_id_by_sku( $regional_sku ) ) : 0;
    if ( $sku_owner ) {
        // A trashed WooCommerce record is not a successfully created draft.
        if ( 'trash' === get_post_status( $sku_owner ) ) {
            return new WP_Error( 'vevor_sku_in_trash', 'El SKU pertenece a una ficha en la papelera; no se aceptara hasta resolverla.' );
        }
        if ( seo_vevor_region_product_market( $sku_owner ) !== $market ||
            (string) get_post_meta( $sku_owner, '_seo_proveedor_id_externo', true ) !== (string) ( $row['proveedor_id_externo'] ?? '' ) ) {
            return new WP_Error( 'vevor_sku_collision', 'El SKU regional pertenece a otra ficha; no se modifica.' );
        }
        return $sku_owner;
    }

    // The 32 earlier pilot products used p_XXXXXXXXXXXX instead of market SKUs.
    $older_ids = get_posts( array(
        'post_type'      => 'product',
        'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
        'fields'         => 'ids',
        'posts_per_page' => 20,
        'meta_query'     => array(
            array( 'key' => '_seo_proveedor_id_externo', 'value' => (string) ( $row['proveedor_id_externo'] ?? '' ) ),
        ),
    ) );
    foreach ( (array) $older_ids as $id ) {
        if ( seo_vevor_region_product_market( $id ) === $market &&
            0 === strcasecmp( (string) get_post_meta( $id, '_seo_proveedor', true ), 'VEVOR' ) ) {
            return absint( $id );
        }
    }
    return 0;
}

function seo_vevor_region_apply_offer( array $row, $market ) {
    global $wpdb;
    if ( ! class_exists( 'WC_Product_External' ) || ! function_exists( 'wc_get_product_id_by_sku' ) ) {
        return new WP_Error( 'vevor_wc_missing', 'WooCommerce no esta disponible.' );
    }
    if ( 'VEVOR' !== strtoupper( trim( (string) ( $row['proveedor'] ?? '' ) ) ) ||
        $market !== strtoupper( trim( (string) ( $row['pais_origen'] ?? '' ) ) ) ||
        ! in_array( (string) ( $row['estado_seleccion'] ?? '' ), array( 'pendiente', 'revisar', 'aceptado' ), true ) ||
        ! in_array( $market, array( 'US', 'DE', 'NL', 'FR' ), true ) ) {
        return new WP_Error( 'vevor_invalid_scope', 'Proveedor, pais o aprobacion incorrectos.' );
    }

    $expected_host = array( 'US' => 'vevor.com', 'DE' => 'vevor.de', 'NL' => 'vevor.nl', 'FR' => 'vevor.fr' );
    $expected_currency = array( 'US' => 'USD', 'DE' => 'EUR', 'NL' => 'EUR', 'FR' => 'EUR' );
    $url = esc_url_raw( (string) ( $row['url_origen'] ?? '' ) );
    $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
    $query = (string) wp_parse_url( $url, PHP_URL_QUERY );
    $params = array();
    wp_parse_str( $query, $params );
    if ( ! isset( $expected_host[ $market ] ) ||
        ! in_array( $host, array( $expected_host[ $market ], 'www.' . $expected_host[ $market ] ), true ) ||
        'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ||
        '53435399' !== (string) ( $params['utm_campaign'] ?? '' ) ||
        'affiliate' !== (string) ( $params['utm_medium'] ?? '' ) ||
        $expected_currency[ $market ] !== strtoupper( trim( (string) ( $row['moneda'] ?? '' ) ) ) ) {
        return new WP_Error( 'vevor_market_affiliate_invalid', 'URL de afiliacion o moneda no corresponde al mercado; no se crea producto.' );
    }

    $external_id = trim( (string) ( $row['proveedor_id_externo'] ?? '' ) );
    $name = sanitize_text_field( (string) ( $row['nombre'] ?? '' ) );
    if ( ! preg_match( '/^\d{12}$/', $external_id ) || '' === $name || empty( $row['imagenes'] ) ) {
        return new WP_Error( 'vevor_incomplete_offer', 'Faltan identidad VEVOR, titulo o imagen externa.' );
    }
    $regional_sku = 'vevor-' . strtolower( $market ) . '-p_' . $external_id;
    $lock = seo_proveedores_adquirir_bloqueo_alta( $row, 5 );
    if ( is_wp_error( $lock ) ) {
        return $lock;
    }
    try {
        $table = $wpdb->prefix . 'seo_proveedores_productos';
        $fresh = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", absint( $row['id'] ?? 0 ) ),
            ARRAY_A
        );
        if ( ! is_array( $fresh ) || ! in_array( (string) ( $fresh['estado_seleccion'] ?? '' ), array( 'pendiente', 'revisar', 'aceptado' ), true ) ||
            $market !== strtoupper( (string) ( $fresh['pais_origen'] ?? '' ) ) ) {
            return new WP_Error( 'vevor_offer_changed', 'La oferta ha cambiado durante el proceso.' );
        }

        $existing = seo_vevor_region_existing_product( $fresh, $market, $regional_sku );
        if ( is_wp_error( $existing ) ) {
            return $existing;
        }
        $created = false;
        if ( $existing ) {
            $product_id = absint( $existing );
        } else {
            $product = new WC_Product_External();
            $product->set_name( $name );
            $product->set_status( 'draft' );
            // Visibility takes effect only if an editor later publishes.
            $product->set_catalog_visibility( 'visible' );
            $product->set_sku( $regional_sku );
            $product->set_product_url( $url );
            $buttons = array( 'US' => 'View at VEVOR', 'DE' => 'Bei VEVOR ansehen', 'NL' => 'Bekijk bij VEVOR', 'FR' => 'Voir sur VEVOR' );
            $product->set_button_text( $buttons[ $market ] );
            // Category mapping is deliberately deferred until after approval.
            // Do not use categoryPath or create market categories here.
            $default_id = absint( get_option( 'default_product_cat', 0 ) );
            $default_term = $default_id ? get_term( $default_id, 'product_cat' ) : null;
            $product->set_category_ids( $default_term instanceof WP_Term ? array( $default_id ) : array() );
            $product->set_description( wp_kses_post( (string) ( $fresh['descripcion'] ?? '' ) ) );
            // WooCommerce has one site-wide currency (EUR). Do NOT write a
            // US-dollar supplier offer as a bogus EUR WooCommerce price.
            $product_id = absint( $product->save() );
            if ( ! $product_id ) {
                return new WP_Error( 'vevor_wc_create_failed', 'WooCommerce no ha guardado el borrador.' );
            }
            $created = true;
            update_post_meta( $product_id, '_seo_proveedor', 'VEVOR' );
            update_post_meta( $product_id, '_seo_proveedor_id_externo', $external_id );
            update_post_meta( $product_id, '_seo_proveedor_catalogo_id', absint( $fresh['id'] ) );
            update_post_meta( $product_id, '_seo_proveedor_url_origen', $url );
            update_post_meta( $product_id, '_seo_proveedor_url_canonica', esc_url_raw( (string) ( $fresh['url_canonica'] ?? '' ) ) );
            update_post_meta( $product_id, '_seo_pais_origen', $market );
            update_post_meta( $product_id, '_seo_locale', array( 'US' => 'en-US', 'DE' => 'de-DE', 'NL' => 'nl-NL', 'FR' => 'fr-FR' )[ $market ] );
            update_post_meta( $product_id, '_seo_market_currency', $expected_currency[ $market ] );
            update_post_meta( $product_id, '_seo_market_price', sanitize_text_field( (string) ( $fresh['precio_con_iva'] ?? '' ) ) );
            update_post_meta( $product_id, '_seo_vevor_source_category', sanitize_text_field( (string) ( $fresh['categoria_proveedor'] ?? '' ) ) );
        }

        // Image storage is keyed by supplier + SKU; keep that SKU regional
        // to prevent different country's image feeds overwriting each other.
        $image_row = $fresh;
        $image_row['sku'] = $regional_sku;
        $image_note = '';
        if ( function_exists( 'seo_proveedores_guardar_imagenes_externas' ) ) {
            $image_result = seo_proveedores_guardar_imagenes_externas( $image_row, $product_id );
            if ( is_wp_error( $image_result ) ) {
                $image_note = ' No se pudieron registrar las imagenes externas.';
            }
        }
        if ( ! seo_supplier_sync_finalize_applied_row( $fresh, $product_id ) ) {
            return new WP_Error( 'vevor_catalog_link_failed', 'Producto creado o encontrado, pero no se pudo guardar object_id en el catalogo. Se puede reintentar sin duplicar.' );
        }
        return array( 'product_id' => $product_id, 'created' => $created, 'message' => $image_note );
    } catch ( Throwable $error ) {
        return new WP_Error( 'vevor_region_failure', $error->getMessage() );
    } finally {
        seo_proveedores_liberar_bloqueo_alta( $lock );
    }
}

