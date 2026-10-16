<?php
/**
 * Safe, opt-in bridge from accepted VEVOR regional offers to WooCommerce drafts.
 *
 * A supplier approval is NOT a WooCommerce product creation. This service
 * explicitly materializes up to five reviewed offers at a time, in a user-
 * chosen WooCommerce category of the corresponding market.
 *
 * No cron, no bulk publication, no edits to Spanish products.
 */
defined( 'ABSPATH' ) || exit;

function seo_vevor_region_category_market( $category_id ) {
    $term = get_term( absint( $category_id ), 'product_cat' );
    if ( ! $term instanceof WP_Term || is_wp_error( $term ) ) {
        return '';
    }
    // Country roots are not sufficiently precise destinations for products.
    if ( in_array( $term->slug, array( 'vevor-us', 'vevor-de', 'vevor-nl' ), true ) ) {
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
        }
    }
    return '';
}

function seo_vevor_region_product_market( $product_id ) {
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
 * Resolve only a clear, market-local category from the VEVOR categoryPath.
 * Fails closed: never fallback to a Spanish category or a generic market root.
 */
function seo_vevor_region_category_for_offer( array $row ) {
    $market = strtoupper( trim( (string) ( $row['pais_origen'] ?? '' ) ) );
    if ( ! in_array( $market, array( 'US', 'DE', 'NL' ), true ) ) {
        return new WP_Error( 'vevor_market_invalid', 'El mercado VEVOR no esta soportado.' );
    }
    $path = trim( (string) ( $row['categoria_proveedor'] ?? '' ) );
    if ( '' === $path ) {
        return new WP_Error( 'vevor_category_missing', 'La oferta no contiene categoryPath; selecciona su categoria internacional en el servicio de revision.' );
    }
    // VEVOR category paths are hierarchy labels, not arbitrary keywords.
    $segments = preg_split( '/\s*(?:>|»|::|\||\/)\s*/u', $path );
    $parts = array();
    foreach ( (array) $segments as $index => $segment ) {
        $key = sanitize_title( trim( $segment ) );
        if ( '' !== $key ) {
            $parts[ $key ] = max( (int) ( $parts[ $key ] ?? -1 ), (int) $index );
        }
    }
    $terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
    if ( is_wp_error( $terms ) ) {
        return $terms;
    }
    $best = array();
    $score = -1;
    foreach ( (array) $terms as $term ) {
        if ( seo_vevor_region_category_market( $term->term_id ) !== $market ) {
            continue;
        }
        $key = sanitize_title( (string) $term->name );
        if ( ! isset( $parts[ $key ] ) ) {
            continue;
        }
        // Prefer the deepest matched VEVOR path segment.
        $current = (int) $parts[ $key ];
        if ( $current > $score ) {
            $best = array( (int) $term->term_id );
            $score = $current;
        } elseif ( $current === $score ) {
            $best[] = (int) $term->term_id;
        }
    }
    if ( 1 !== count( $best ) ) {
        return new WP_Error(
            'vevor_category_review',
            'No se ha identificado una categoria internacional inequivoca para categoryPath. Usa el bloque VEVOR internacional y selecciona la categoria.'
        );
    }
    return $best[0];
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

function seo_vevor_region_apply_offer( array $row, $market, $category_id ) {
    global $wpdb;
    if ( ! class_exists( 'WC_Product_External' ) || ! function_exists( 'wc_get_product_id_by_sku' ) ) {
        return new WP_Error( 'vevor_wc_missing', 'WooCommerce no esta disponible.' );
    }
    if ( 'VEVOR' !== strtoupper( trim( (string) ( $row['proveedor'] ?? '' ) ) ) ||
        $market !== strtoupper( trim( (string) ( $row['pais_origen'] ?? '' ) ) ) ||
        ! in_array( (string) ( $row['estado_seleccion'] ?? '' ), array( 'pendiente', 'revisar', 'aceptado' ), true ) ||
        $market !== seo_vevor_region_category_market( $category_id ) ) {
        return new WP_Error( 'vevor_invalid_scope', 'Proveedor, pais, aprobacion o categoria incorrectos.' );
    }

    $expected_host = array( 'US' => 'vevor.com', 'DE' => 'vevor.de', 'NL' => 'vevor.nl' );
    $expected_currency = array( 'US' => 'USD', 'DE' => 'EUR', 'NL' => 'EUR' );
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
            $buttons = array( 'US' => 'View at VEVOR', 'DE' => 'Bei VEVOR ansehen', 'NL' => 'Bekijk bij VEVOR' );
            $product->set_button_text( $buttons[ $market ] );
            $product->set_category_ids( array( absint( $category_id ) ) );
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
            update_post_meta( $product_id, '_seo_locale', array( 'US' => 'en-US', 'DE' => 'de-DE', 'NL' => 'nl-NL' )[ $market ] );
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

function seo_vevor_region_batch_post() {
    if ( ! isset( $_POST['seo_vevor_region_batch_action'] ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Permisos insuficientes.', 'taxocommerce-seo' ) );
    }
    check_admin_referer( 'seo_vevor_region_batch', 'seo_vevor_region_batch_nonce' );
    $action = sanitize_key( wp_unslash( (string) $_POST['seo_vevor_region_batch_action'] ) );
    $market = strtoupper( sanitize_key( wp_unslash( (string) ( $_POST['vevor_region_market'] ?? '' ) ) ) );
    $category_id = absint( $_POST['vevor_region_category_id'] ?? 0 );
    $source_category = sanitize_text_field( wp_unslash( (string) ( $_POST['vevor_region_source_category'] ?? '' ) ) );
    $report = array(
        'filters' => array( 'market' => $market, 'category_id' => $category_id, 'source_category' => $source_category ),
        'created' => 0, 'existing' => 0, 'errors' => array(), 'rows' => array(),
    );
    if ( ! in_array( $action, array( 'preview', 'create' ), true ) ||
         ! in_array( $market, array( 'US', 'DE', 'NL' ), true ) ||
         $market !== seo_vevor_region_category_market( $category_id ) ||
         mb_strlen( $source_category ) < 5 ) {
        $report['errors'][] = 'Indica un mercado, su categoria de destino y la ruta original de categoria VEVOR (minimo 5 caracteres).';
    } elseif ( 'create' === $action && empty( $_POST['vevor_region_confirm'] ) ) {
        $report['errors'][] = 'Confirma expresamente la creacion de hasta cinco borradores.';
    } else {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_proveedores_productos';
        // Exact categoryPath: no accidental mixing of unrelated supplier ranges.
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE proveedor = %s AND pais_origen = %s
               AND estado_seleccion = %s
               AND categoria_proveedor = %s
               AND (object_id IS NULL OR object_id = 0)
             ORDER BY id ASC LIMIT 5",
            'VEVOR', $market, 'aceptado', $source_category
        );
        $rows = (array) $wpdb->get_results( $sql, ARRAY_A );
        if ( empty( $rows ) ) {
            $report['errors'][] = 'No hay ofertas VEVOR aceptadas y sin enlazar para esa ruta exacta.';
        }
        foreach ( $rows as $row ) {
            $label = '#' . absint( $row['id'] ) . ' ' . sanitize_text_field( (string) ( $row['nombre'] ?? '' ) );
            if ( 'preview' === $action ) {
                $report['rows'][] = $label;
                continue;
            }
            $result = seo_vevor_region_apply_offer( $row, $market, $category_id );
            if ( is_wp_error( $result ) ) {
                $report['errors'][] = $label . ': ' . $result->get_error_message();
                continue;
            }
            ++$report[ ! empty( $result['created'] ) ? 'created' : 'existing' ];
            $report['rows'][] = '#' . absint( $result['product_id'] ) . ' ' . $label . (string) ( $result['message'] ?? '' );
        }
    }
    set_transient( 'seo_vevor_region_batch_' . get_current_user_id(), $report, 15 * MINUTE_IN_SECONDS );
    wp_safe_redirect( add_query_arg(
        array( 'page' => 'seo-import-export', 'seo_ie_tab' => 'catalogo-proveedores' ),
        admin_url( 'admin.php' )
    ) );
    exit;
}
add_action( 'admin_init', 'seo_vevor_region_batch_post' );

function seo_vevor_region_batch_card() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $key = 'seo_vevor_region_batch_' . get_current_user_id();
    $report = get_transient( $key );
    if ( is_array( $report ) ) {
        delete_transient( $key );
    } else {
        $report = array();
    }
    $filters = (array) ( $report['filters'] ?? array() );
    $terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
    global $wpdb;
    $table = $wpdb->prefix . 'seo_proveedores_productos';
    $backlog = $wpdb->get_results( $wpdb->prepare(
        "SELECT pais_origen, COUNT(*) AS total FROM {$table}
         WHERE proveedor = %s
           AND pais_origen IN ('US', 'DE', 'NL')
           AND estado_seleccion = %s
           AND (object_id IS NULL OR object_id = 0)
         GROUP BY pais_origen",
        'VEVOR', 'aceptado'
    ), ARRAY_A );
    ?>
    <div class="card" style="max-width:none;padding:20px;">
        <h2>VEVOR internacional: crear fichas de producto para revisar</h2>
        <p>Al aceptar <strong>una oferta individual</strong> de US, DE o NL, el plugin intenta crear su ficha WooCommerce en <code>draft</code> con categoria validada. Las antiguas aceptaciones masivas solo actualizaron el catalogo intermedio: este bloque recupera esas ofertas pendientes en tandas de cinco, sin volver a importar los CSV ni modificar productos espanoles.</p>
        <p><strong>Ofertas aceptadas sin vínculo WooCommerce:</strong>
            <?php if ( empty( $backlog ) ) : ?>
                0
            <?php else : ?>
                <?php foreach ( (array) $backlog as $market_backlog ) :
                    $code = strtoupper( (string) ( $market_backlog['pais_origen'] ?? '' ) );
                    $target_url = add_query_arg(
                        array(
                            'page' => 'seo-import-export',
                            'seo_ie_tab' => 'catalogo-proveedores',
                            'f_proveedor' => 'VEVOR',
                            'f_estado' => 'aceptado',
                            'f_pais' => $code,
                            'f_woo_link' => 'accepted_unlinked',
                        ),
                        admin_url( 'admin.php' )
                    );
                    ?>
                    <a href="<?php echo esc_url( $target_url ); ?>"><?php echo esc_html( $code . ': ' . number_format_i18n( absint( $market_backlog['total'] ) ) ); ?></a>&nbsp;
                <?php endforeach; ?>
            <?php endif; ?>
        </p>
        <p class="description">Pulsa en US, DE o NL para ver las ofertas aceptadas pendientes de convertir o de enlazar con una ficha existente. No son productos WooCommerce en borrador. El recuento procede de la base de datos actual y se recalcula al abrir la pantalla.</p>
        <?php if ( ! empty( $report ) ) : ?>
            <div class="notice notice-info inline"><p>
                <?php echo esc_html( sprintf( 'Nuevas fichas: %d. Fichas anteriores vinculadas: %d.', absint( $report['created'] ?? 0 ), absint( $report['existing'] ?? 0 ) ) ); ?>
            </p></div>
            <?php foreach ( (array) ( $report['errors'] ?? array() ) as $error ) : ?>
                <p style="color:#b32d2e"><?php echo esc_html( $error ); ?></p>
            <?php endforeach; ?>
            <?php foreach ( (array) ( $report['rows'] ?? array() ) as $detail ) : ?>
                <p><?php echo esc_html( $detail ); ?></p>
            <?php endforeach; ?>
        <?php endif; ?>
        <form method="post">
            <?php wp_nonce_field( 'seo_vevor_region_batch', 'seo_vevor_region_batch_nonce' ); ?>
            <p><label>Mercado
                <select name="vevor_region_market">
                    <?php foreach ( array( 'US' => 'Estados Unidos', 'DE' => 'Alemania', 'NL' => 'Paises Bajos' ) as $market => $label ) : ?>
                        <option value="<?php echo esc_attr( $market ); ?>" <?php selected( $filters['market'] ?? '', $market ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label></p>
            <p><label>Categoria WooCommerce internacional de destino
                <select name="vevor_region_category_id" required>
                    <option value="">Selecciona la categoria de destino</option>
                    <?php foreach ( is_array( $terms ) ? $terms : array() as $term ) :
                        $country = seo_vevor_region_category_market( $term->term_id );
                        if ( '' === $country ) { continue; }
                        ?>
                        <option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( absint( $filters['category_id'] ?? 0 ), $term->term_id ); ?>>
                            <?php echo esc_html( $country . ': ' . $term->name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label></p>
            <p><label>Ruta exacta <code>categoryPath</code> de VEVOR<br>
                <input type="text" name="vevor_region_source_category" required style="width:min(700px,100%);" value="<?php echo esc_attr( $filters['source_category'] ?? '' ); ?>" placeholder="Tools &gt; Hand Tools &gt; ..." />
            </label></p>
            <p class="description">Copia la ruta exacta de una oferta aceptada en el catalogo de proveedores. No se asignan automaticamente categorias dudosas. Primero pulsa Previsualizar para ver las primeras 5 fichas.</p>
            <p><label><input type="checkbox" name="vevor_region_confirm" value="1"> Confirmo la creacion de un maximo de 5 borradores por lote.</label></p>
            <p>
                <button class="button" name="seo_vevor_region_batch_action" value="preview" type="submit">Previsualizar ofertas</button>
                <button class="button button-primary" name="seo_vevor_region_batch_action" value="create" type="submit">Crear hasta 5 fichas en borrador</button>
            </p>
        </form>
        <p class="description">El producto se crea como <code>external</code>, conserva el enlace afiliado completo y almacena el precio en la moneda de origen como metadato, sin convertir USD a EUR. Los textos y las imagenes deben revisarse antes de publicar.</p>
    </div>
    <?php
}
add_action( 'seo_vevor_region_batch_card', 'seo_vevor_region_batch_card' );
