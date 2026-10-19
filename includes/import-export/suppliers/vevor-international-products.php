<?php
/**
 * Create VEVOR regional WooCommerce drafts after supplier approval.
 * All new drafts use the configured WooCommerce default product category;
 * international category assignment is a later editorial step.
 * No bulk publication or edits to Spanish products.
 */
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/vevor-direct-approval.php';

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

/**
 * Only the catalogue row's object_id (or its exact catalogue-ID backlink)
 * identifies the WooCommerce product. Provider SKUs and titles can repeat.
 */
function seo_vevor_region_existing_product( array $row, $market, $regional_sku ) {
    global $wpdb;
    unset( $regional_sku );

    $catalog_id = absint( $row['id'] ?? 0 );
    $linked_id = absint( $row['object_id'] ?? 0 );
    if ( ! $linked_id && $catalog_id ) {
        $matches = (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm
                    ON pm.post_id = p.ID
                   AND pm.meta_key = '_seo_proveedor_catalogo_id'
                 WHERE p.post_type = 'product' AND p.post_status <> 'trash'
                   AND pm.meta_value = %s ORDER BY p.ID ASC LIMIT 2",
                (string) $catalog_id
            )
        );
        if ( count( $matches ) > 1 ) {
            return new WP_Error( 'vevor_ambiguous_backlink', 'Varias fichas enlazadas a la misma fila; no se modifica ninguna.' );
        }
        $linked_id = absint( $matches[0] ?? 0 );
    }
    if ( ! $linked_id ) {
        return 0;
    }

    $linked_market = strtoupper( (string) get_post_meta( $linked_id, '_seo_pais_origen', true ) );
    $linked_provider = (string) get_post_meta( $linked_id, '_seo_proveedor', true );
    $linked_catalog_id = absint( get_post_meta( $linked_id, '_seo_proveedor_catalogo_id', true ) );
    $linked_external_id = trim( (string) get_post_meta( $linked_id, '_seo_proveedor_id_externo', true ) );
    if ( 'product' !== get_post_type( $linked_id ) || 'trash' === get_post_status( $linked_id ) ||
        0 !== strcasecmp( $linked_provider, 'VEVOR' ) ||
        ( '' !== $linked_market && $linked_market !== $market ) ||
        ( $linked_catalog_id && $linked_catalog_id !== $catalog_id ) ||
        ( '' !== $linked_external_id && $linked_external_id !== trim( (string) ( $row['proveedor_id_externo'] ?? '' ) ) ) ) {
        return new WP_Error( 'vevor_wrong_product', 'La vinculación pertenece a otra ficha, proveedor o mercado.' );
    }
    // If the original country metadata is missing, require the exact
    // catalogue-ID backlink instead of trusting a repeated SKU.
    if ( '' === $linked_market && $linked_catalog_id !== $catalog_id ) {
        return new WP_Error( 'vevor_missing_market_identity', 'No se puede verificar el país de la ficha enlazada.' );
    }
    return $linked_id;
}

function seo_vevor_region_apply_offer( array $row, $market, $linked_draft_only = false ) {
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
    // WooCommerce requires a unique internal SKU. Keep VEVOR's original
    // repeated SKU in supplier metadata; never use it to identify a variant.
    $regional_sku = 'vevor-' . strtolower( $market ) . '-row-' . absint( $row['id'] ?? 0 );
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
        if ( ! is_array( $fresh ) || 0 !== strcasecmp( (string) ( $fresh['proveedor'] ?? '' ), 'VEVOR' ) ||
            ! in_array( (string) ( $fresh['estado_seleccion'] ?? '' ), array( 'pendiente', 'revisar', 'aceptado' ), true ) ||
            $market !== strtoupper( (string) ( $fresh['pais_origen'] ?? '' ) ) ||
            $external_id !== (string) ( $fresh['proveedor_id_externo'] ?? '' ) ||
            $url !== esc_url_raw( (string) ( $fresh['url_origen'] ?? '' ) ) ||
            $expected_currency[ $market ] !== strtoupper( (string) ( $fresh['moneda'] ?? '' ) ) ) {
            return new WP_Error( 'vevor_offer_changed', 'La oferta ha cambiado durante el proceso.' );
        }

        $regional_sku = 'vevor-' . strtolower( $market ) . '-row-' . absint( $fresh['id'] );
        $existing = $linked_draft_only
            ? absint( $fresh['object_id'] ?? 0 )
            : seo_vevor_region_existing_product( $fresh, $market, $regional_sku );
        if ( is_wp_error( $existing ) ) {
            return $existing;
        }
        if ( $linked_draft_only ) {
            // The completion command MUST NOT create products, merge by SKU,
            // change published products or alter Spanish inventory.
            if ( ! $existing || 'draft' !== get_post_status( $existing ) ) {
                return new WP_Error( 'vevor_draft_link_missing', 'No existe un draft enlazado; no se crea ninguna ficha.' );
            }
            $backlink = absint( get_post_meta( $existing, '_seo_proveedor_catalogo_id', true ) );
            $provider = (string) get_post_meta( $existing, '_seo_proveedor', true );
            $market_meta = strtoupper( (string) get_post_meta( $existing, '_seo_pais_origen', true ) );
            $external_meta = (string) get_post_meta( $existing, '_seo_proveedor_id_externo', true );
            if ( 'product' !== get_post_type( $existing ) ||
                $backlink !== absint( $fresh['id'] ) ||
                0 !== strcasecmp( $provider, 'VEVOR' ) ||
                ( '' !== $market_meta && $market_meta !== $market ) ||
                ( '' !== $external_meta && $external_meta !== $external_id ) ) {
                return new WP_Error( 'vevor_draft_identity_invalid', 'Vinculacion de esta ficha no verificada; no se modifica.' );
            }
            $collisions = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table}
                     WHERE proveedor = %s AND object_id = %d AND id <> %d",
                    'VEVOR', $existing, absint( $fresh['id'] )
                )
            );
            if ( (int) $collisions > 0 ) {
                return new WP_Error( 'vevor_shared_object_id', 'Varias filas del catálogo apuntan al mismo draft; no se mezclan.' );
            }
        }
        $created = false;
        if ( $existing ) {
            $product_id = absint( $existing );
            $product = wc_get_product( $product_id );
            if ( ! $product || ! $product->is_type( 'external' ) ) {
                return new WP_Error( 'vevor_wrong_type', 'La ficha vinculada no es un producto externo VEVOR.' );
            }
            $product->set_product_url( $url );
            $buttons = array( 'US' => 'View at VEVOR', 'DE' => 'Bei VEVOR ansehen', 'NL' => 'Bekijk bij VEVOR', 'FR' => 'Voir sur VEVOR' );
            $product->set_button_text( $buttons[ $market ] );
            // Preserve editorial content. Fill empty fields only.
            if ( '' === trim( (string) $product->get_name() ) ) {
                $product->set_name( $name );
            }
            if ( '' === trim( (string) $product->get_description() ) && ! empty( $fresh['descripcion'] ) ) {
                $product->set_description( wp_kses_post( (string) $fresh['descripcion'] ) );
            }
            $product->save();
        } else {
            if ( $linked_draft_only ) {
                return new WP_Error( 'vevor_no_create', 'La sincronización de drafts no crea productos.' );
            }
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

        }

        // Copy the complete regional commercial snapshot to BOTH new and
        // previously approved products. Updates keep status, SKU and categories.
        $price_raw = trim( (string) ( $fresh['precio_con_iva'] ?? '' ) );
        if ( '' === $price_raw ) {
            $price_raw = trim( (string) ( $fresh['precio_sin_iva'] ?? '' ) );
        }
        $price = wc_format_decimal( $price_raw );
        if ( '' === $price_raw || ! is_numeric( str_replace( ',', '.', $price_raw ) ) ||
            '' === $price || (float) $price <= 0 ) {
            return new WP_Error( 'vevor_market_price_missing', 'Precio de mercado incompleto en el catálogo; no se confirma.' );
        }
        // WooCommerce uses a single shop-wide currency (EUR). USD belongs
        // to market metadata only, never a misleading EUR _regular_price.
        if ( 'EUR' === $expected_currency[ $market ] ) {
            $product->set_regular_price( $price );
            $product->set_sale_price( '' );
            $product->set_price( $price );
            $product->save();
        } elseif ( 'draft' === get_post_status( $product_id ) ) {
            $product->set_regular_price( '' );
            $product->set_sale_price( '' );
            $product->set_price( '' );
            $product->save();
        }

        $canonical = esc_url_raw( (string) ( $fresh['url_canonica'] ?? '' ) );
        if ( '' === $canonical ) {
            $canonical = esc_url_raw( (string) preg_replace( '/[?#].*$/', '', $url ) );
        }
        $metadata = array(
            '_seo_proveedor'                 => 'VEVOR',
            '_seo_proveedor_id_externo'      => $external_id,
            '_seo_proveedor_catalogo_id'     => absint( $fresh['id'] ),
            '_seo_proveedor_url_origen'      => $url,
            '_seo_proveedor_url_canonica'    => $canonical,
            '_seo_pais_origen'               => $market,
            '_seo_locale'                    => array( 'US' => 'en-US', 'DE' => 'de-DE', 'NL' => 'nl-NL', 'FR' => 'fr-FR' )[ $market ],
            '_seo_market_currency'           => $expected_currency[ $market ],
            '_seo_market_price'              => $price,
            '_seo_proveedor_moneda'          => $expected_currency[ $market ],
            '_seo_proveedor_precio_con_iva'  => sanitize_text_field( (string) ( $fresh['precio_con_iva'] ?? '' ) ),
            '_seo_proveedor_precio_sin_iva'  => sanitize_text_field( (string) ( $fresh['precio_sin_iva'] ?? '' ) ),
            '_seo_categoria_proveedor'       => sanitize_text_field( (string) ( $fresh['categoria_proveedor'] ?? '' ) ),
            '_seo_vevor_source_category'     => sanitize_text_field( (string) ( $fresh['categoria_proveedor'] ?? '' ) ),
            '_seo_proveedor_sku_original'    => sanitize_text_field( (string) ( $fresh['sku'] ?? '' ) ),
        );
        if ( 'EUR' === $expected_currency[ $market ] ) {
            $metadata['_seo_precio_proveedor'] = $price;
        }
        foreach ( $metadata as $key => $value ) {
            update_post_meta( $product_id, $key, $value );
        }
        if ( $url !== (string) get_post_meta( $product_id, '_product_url', true ) ||
            $price !== (string) get_post_meta( $product_id, '_seo_market_price', true ) ||
            $expected_currency[ $market ] !== (string) get_post_meta( $product_id, '_seo_market_currency', true ) ||
            $market !== (string) get_post_meta( $product_id, '_seo_pais_origen', true ) ) {
            return new WP_Error( 'vevor_data_not_saved', 'WooCommerce no ha conservado los datos comerciales; no se da por sincronizado.' );
        }

        // Image storage is keyed by supplier + SKU; keep that SKU regional
        // to prevent different country's image feeds overwriting each other.
        $image_row = $fresh;
        $image_row['sku'] = 'vevor-' . strtolower( $market ) . '-row-' . absint( $fresh['id'] );
        if ( ! function_exists( 'seo_proveedores_guardar_imagenes_externas' ) ) {
            return new WP_Error( 'vevor_images_unavailable', 'El servicio de imagenes externas no esta cargado.' );
        }
        $image_result = seo_proveedores_guardar_imagenes_externas( $image_row, $product_id );
        if ( is_wp_error( $image_result ) ) {
            return $image_result;
        }
        if ( ! is_array( $image_result ) || absint( $image_result['stored'] ?? 0 ) < 1 ) {
            return new WP_Error( 'vevor_images_incomplete', 'La galeria externa esta incompleta; no se marca como sincronizado.' );
        }

        if ( ! seo_supplier_sync_finalize_applied_row( $fresh, $product_id ) ) {
            return new WP_Error( 'vevor_catalog_link_failed', 'Producto creado o encontrado, pero no se pudo guardar object_id en el catalogo. Se puede reintentar sin duplicar.' );
        }
        return array( 'product_id' => $product_id, 'created' => $created, 'message' => '' );
    } catch ( Throwable $error ) {
        return new WP_Error( 'vevor_region_failure', $error->getMessage() );
    } finally {
        seo_proveedores_liberar_bloqueo_alta( $lock );
    }
}

