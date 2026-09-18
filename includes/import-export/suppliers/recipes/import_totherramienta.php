<?php
/**
 * Receta TOTHERRAMIENTA mediante Shopify Storefront Catalog UCP/MCP.
 *
 * No usa GitHub ni scraping HTML. Consulta el endpoint publico UCP/MCP que
 * publica la propia tienda Shopify, pagina el catalogo y genera directamente
 * el CSV estandar del motor de proveedores.
 *
 * Flujo:
 * 1) Admin -> Importar proveedor -> Obtener catalogo desde la web.
 * 2) Seleccionar "TotHerramienta - Shopify UCP/MCP".
 * 3) WordPress llama a /api/ucp/mcp con search_catalog.
 * 4) Se pagina el catalogo (hasta 250 productos por llamada).
 * 5) Se normaliza a CSV estandar y se entrega al importador comun.
 *
 * En modo prueba (Catalogo completo desmarcado) se procesa una sola pagina
 * de hasta 50 productos. En modo catalogo completo se recorre toda la
 * paginacion y solo se marca como completo si se alcanza el final sin errores.
 *
 * @package SEOSystem
 * @subpackage SupplierImports
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_agent_profile' ) ) {
    /**
     * Perfil UCP usado para negociar capacidades de catalogo.
     *
     * Shopify publica este perfil de ejemplo valido. Se deja filtrable para
     * poder sustituirlo mas adelante por un perfil propio alojado en el sitio.
     *
     * @return string
     */
    function seo_supplier_recipe_totherramienta_agent_profile() {
        return (string) apply_filters(
            'seo_totherramienta_ucp_agent_profile',
            'https://shopify.dev/ucp/agent-profiles/2026-08-25/valid-with-capabilities.json'
        );
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_endpoint' ) ) {
    /**
     * Endpoint UCP/MCP publicado por TotHerramienta.
     *
     * @return string
     */
    function seo_supplier_recipe_totherramienta_endpoint() {
        return (string) apply_filters(
            'seo_totherramienta_ucp_endpoint',
            'https://totherramienta.com/api/ucp/mcp'
        );
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_gid_tail' ) ) {
    /**
     * Devuelve el tramo estable final de un GID Shopify.
     *
     * @param mixed $value GID.
     * @return string
     */
    function seo_supplier_recipe_totherramienta_gid_tail( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value ) {
            return '';
        }
        $path = parse_url( $value, PHP_URL_PATH );
        if ( is_string( $path ) && '' !== $path ) {
            $tail = basename( $path );
            if ( '' !== $tail && '.' !== $tail && '/' !== $tail ) {
                return sanitize_text_field( $tail );
            }
        }
        if ( preg_match( '#/([^/]+)$#', $value, $match ) ) {
            return sanitize_text_field( $match[1] );
        }
        return sanitize_text_field( $value );
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_amount' ) ) {
    /**
     * Convierte UCP Amount (minor units) a decimal.
     *
     * UCP expresa amount en la unidad menor de la moneda. EUR usa 2 decimales.
     * Se cubren tambien monedas habituales de 0 y 3 decimales por robustez.
     *
     * @param mixed  $amount Minor units.
     * @param string $currency ISO 4217.
     * @return string
     */
    function seo_supplier_recipe_totherramienta_amount( $amount, $currency ) {
        if ( ! is_numeric( $amount ) ) {
            return '';
        }

        $currency = strtoupper( trim( (string) $currency ) );
        $zero_decimals = [ 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' ];
        $three_decimals = [ 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' ];

        if ( in_array( $currency, $zero_decimals, true ) ) {
            $places = 0;
        } elseif ( in_array( $currency, $three_decimals, true ) ) {
            $places = 3;
        } else {
            $places = 2;
        }

        $divisor = 10 ** $places;
        return number_format( (float) $amount / $divisor, $places, '.', '' );
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_text' ) ) {
    /**
     * Texto sencillo normalizado.
     *
     * @param mixed $value Valor.
     * @return string
     */
    function seo_supplier_recipe_totherramienta_text( $value ) {
        if ( is_array( $value ) || is_object( $value ) ) {
            return '';
        }
        $value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = wp_strip_all_tags( $value );
        return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_description' ) ) {
    /**
     * Extrae descripcion UCP manteniendo HTML seguro cuando existe.
     *
     * @param mixed $description Objeto description.
     * @return string
     */
    function seo_supplier_recipe_totherramienta_description( $description ) {
        if ( is_string( $description ) ) {
            return trim( wp_kses_post( $description ) );
        }
        if ( ! is_array( $description ) ) {
            return '';
        }
        if ( isset( $description['html'] ) && '' !== trim( (string) $description['html'] ) ) {
            return trim( wp_kses_post( (string) $description['html'] ) );
        }
        if ( isset( $description['plain'] ) ) {
            $plain = seo_supplier_recipe_totherramienta_text( $description['plain'] );
            return '' !== $plain ? wpautop( esc_html( $plain ) ) : '';
        }
        return '';
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_category' ) ) {
    /**
     * Categoria preferente: taxonomia merchant UCP; despues colecciones Shopify.
     *
     * @param array $product Producto UCP.
     * @return string
     */
    function seo_supplier_recipe_totherramienta_category( array $product ) {
        $fallback = [];
        foreach ( (array) ( $product['categories'] ?? [] ) as $category ) {
            if ( ! is_array( $category ) ) {
                continue;
            }
            $value = seo_supplier_recipe_totherramienta_text( $category['value'] ?? '' );
            if ( '' === $value ) {
                continue;
            }
            $taxonomy = strtolower( seo_supplier_recipe_totherramienta_text( $category['taxonomy'] ?? '' ) );
            if ( 'merchant' === $taxonomy ) {
                return $value;
            }
            $fallback[] = $value;
        }

        $collections = [];
        foreach ( (array) ( $product['collections'] ?? [] ) as $collection ) {
            if ( ! is_array( $collection ) ) {
                continue;
            }
            $title = seo_supplier_recipe_totherramienta_text( $collection['title'] ?? '' );
            if ( '' !== $title ) {
                $collections[] = $title;
            }
        }
        $collections = array_values( array_unique( $collections ) );
        if ( ! empty( $collections ) ) {
            return implode( ' | ', array_slice( $collections, 0, 6 ) );
        }

        return (string) ( $fallback[0] ?? '' );
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_brand' ) ) {
    /**
     * UCP Storefront no garantiza vendor/marca. Solo se usa cuando el propio
     * payload la expone expresamente; nunca se infiere desde seller.
     *
     * @param array $product Producto UCP.
     * @return string
     */
    function seo_supplier_recipe_totherramienta_brand( array $product ) {
        foreach ( [ 'brand', 'vendor', 'manufacturer' ] as $key ) {
            if ( ! array_key_exists( $key, $product ) ) {
                continue;
            }
            $value = $product[ $key ];
            if ( is_array( $value ) ) {
                $value = $value['name'] ?? $value['value'] ?? '';
            }
            $value = seo_supplier_recipe_totherramienta_text( $value );
            if ( '' !== $value ) {
                return $value;
            }
        }

        $metadata = $product['metadata'] ?? [];
        if ( is_array( $metadata ) ) {
            foreach ( [ 'brand', 'vendor', 'manufacturer' ] as $key ) {
                $value = seo_supplier_recipe_totherramienta_text( $metadata[ $key ] ?? '' );
                if ( '' !== $value ) {
                    return $value;
                }
            }
        }
        return '';
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_images' ) ) {
    /**
     * Galeria de imagenes expuesta por UCP.
     *
     * @param array $product Producto UCP.
     * @return array<int,string>
     */
    function seo_supplier_recipe_totherramienta_images( array $product ) {
        $images = [];
        foreach ( (array) ( $product['media'] ?? [] ) as $media ) {
            if ( ! is_array( $media ) ) {
                continue;
            }
            $type = strtolower( seo_supplier_recipe_totherramienta_text( $media['type'] ?? '' ) );
            if ( '' !== $type && 'image' !== $type ) {
                continue;
            }
            $url = esc_url_raw( trim( (string) ( $media['url'] ?? '' ) ) );
            if ( '' !== $url && preg_match( '#^https?://#i', $url ) ) {
                $images[] = $url;
            }
        }
        return array_values( array_slice( array_unique( $images ), 0, 30 ) );
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_ucp_call' ) ) {
    /**
     * Ejecuta una llamada JSON-RPC contra Storefront Catalog UCP/MCP.
     *
     * @param string $tool Nombre de herramienta.
     * @param array  $catalog Argumentos catalog.
     * @param int    $request_id ID JSON-RPC.
     * @return array|WP_Error structuredContent.
     */
    function seo_supplier_recipe_totherramienta_ucp_call( $tool, array $catalog, $request_id = 1 ) {
        $endpoint = esc_url_raw( seo_supplier_recipe_totherramienta_endpoint() );
        $profile  = esc_url_raw( seo_supplier_recipe_totherramienta_agent_profile() );

        if ( '' === $endpoint || '' === $profile ) {
            return new WP_Error( 'totherramienta_ucp_config', 'Endpoint o perfil UCP no valido.' );
        }

        $payload = [
            'jsonrpc' => '2.0',
            'method'  => 'tools/call',
            'id'      => absint( $request_id ) ?: 1,
            'params'  => [
                'name'      => sanitize_key( $tool ),
                'arguments' => [
                    'meta'    => [
                        'ucp-agent' => [
                            'profile' => $profile,
                        ],
                    ],
                    'catalog' => $catalog,
                ],
            ],
        ];

        $response = wp_safe_remote_post(
            $endpoint,
            [
                'timeout'     => 60,
                'redirection' => 3,
                'headers'     => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                    'User-Agent'   => 'DistribuidorDeHerramientas-SEOSystem/1.0; +' . home_url( '/' ),
                ],
                'body'        => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'data_format' => 'body',
            ]
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'totherramienta_ucp_http', $response->get_error_message() );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $body   = (string) wp_remote_retrieve_body( $response );
        if ( $status < 200 || $status >= 300 ) {
            return new WP_Error(
                'totherramienta_ucp_status',
                'TotHerramienta UCP/MCP respondio HTTP ' . $status . '. ' . substr( trim( wp_strip_all_tags( $body ) ), 0, 300 )
            );
        }

        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            return new WP_Error( 'totherramienta_ucp_json', 'La respuesta UCP/MCP no contiene JSON valido.' );
        }

        if ( ! empty( $decoded['error'] ) ) {
            $message = is_array( $decoded['error'] )
                ? (string) ( $decoded['error']['message'] ?? wp_json_encode( $decoded['error'] ) )
                : (string) $decoded['error'];
            return new WP_Error( 'totherramienta_ucp_rpc', 'Error JSON-RPC: ' . $message );
        }

        $structured = $decoded['result']['structuredContent'] ?? null;
        if ( ! is_array( $structured ) ) {
            return new WP_Error( 'totherramienta_ucp_shape', 'La respuesta UCP/MCP no contiene result.structuredContent.' );
        }

        return $structured;
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_product_rows' ) ) {
    /**
     * Convierte un producto UCP a una o varias filas estandar.
     *
     * Si Shopify devuelve varias variantes con SKU/precio propios, cada variante
     * se conserva como fila independiente. Para productos simples se obtiene una
     * sola fila. Esto evita perder referencias reales del proveedor.
     *
     * @param array $product Producto UCP.
     * @return array<int,array<string,string>>
     */
    function seo_supplier_recipe_totherramienta_product_rows( array $product ) {
        $product_id   = seo_supplier_recipe_totherramienta_gid_tail( $product['id'] ?? '' );
        $product_gid  = trim( (string) ( $product['id'] ?? '' ) );
        $title        = seo_supplier_recipe_totherramienta_text( $product['title'] ?? '' );
        $url          = esc_url_raw( trim( (string) ( $product['url'] ?? '' ) ) );
        $description  = seo_supplier_recipe_totherramienta_description( $product['description'] ?? [] );
        $category     = seo_supplier_recipe_totherramienta_category( $product );
        $brand        = seo_supplier_recipe_totherramienta_brand( $product );
        $images       = seo_supplier_recipe_totherramienta_images( $product );
        $variants     = array_values( array_filter( (array) ( $product['variants'] ?? [] ), 'is_array' ) );

        if ( '' === $title || '' === $product_id ) {
            return [];
        }

        $rows = [];
        if ( empty( $variants ) ) {
            $price    = $product['price_range']['min'] ?? [];
            $currency = strtoupper( seo_supplier_recipe_totherramienta_text( $price['currency'] ?? 'EUR' ) );
            $price_vat= seo_supplier_recipe_totherramienta_amount( $price['amount'] ?? '', $currency );
            $rows[] = [
                'proveedor_id_externo' => $product_id,
                'sku'                   => '',
                'mpn'                   => '',
                'url_origen'            => $url,
                'url_canonica'          => $url,
                'nombre'                => $title,
                'descripcion'           => $description,
                'marca'                 => $brand,
                'categoria_proveedor'   => $category,
                'precio_sin_iva'        => '',
                'precio_con_iva'        => $price_vat,
                'iva_porcentaje'        => '',
                'moneda'                => $currency ?: 'EUR',
                'stock_estado'          => 'unknown',
                'stock_cantidad'        => '',
                'stock_texto'           => '',
                'imagenes'              => wp_json_encode( $images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            ];
            return $rows;
        }

        foreach ( $variants as $variant ) {
            $variant_id    = seo_supplier_recipe_totherramienta_gid_tail( $variant['id'] ?? '' );
            $sku           = seo_supplier_recipe_totherramienta_text( $variant['sku'] ?? '' );
            $variant_title = seo_supplier_recipe_totherramienta_text( $variant['title'] ?? '' );
            $price         = is_array( $variant['price'] ?? null ) ? $variant['price'] : [];
            $currency      = strtoupper( seo_supplier_recipe_totherramienta_text( $price['currency'] ?? 'EUR' ) );
            $price_vat     = seo_supplier_recipe_totherramienta_amount( $price['amount'] ?? '', $currency );
            $available     = $variant['availability']['available'] ?? null;
            $external_id   = '' !== $variant_id ? $variant_id : ( '' !== $sku ? $sku : $product_id );

            $name = $title;
            if (
                '' !== $variant_title
                && ! in_array( strtolower( $variant_title ), [ 'default title', 'default', 'predeterminado' ], true )
                && 0 !== strcasecmp( $variant_title, $title )
            ) {
                $name .= ' - ' . $variant_title;
            }

            $variant_url = $url;
            if ( '' !== $variant_id && '' !== $variant_url ) {
                $variant_url = add_query_arg( 'variant', rawurlencode( $variant_id ), $variant_url );
            }

            $variant_description = seo_supplier_recipe_totherramienta_description( $variant['description'] ?? [] );
            $row_description = $description;
            if ( '' !== trim( wp_strip_all_tags( $variant_description ) ) && $variant_description !== $description ) {
                $row_description .= ( '' !== $row_description ? "\n\n" : '' ) . $variant_description;
            }

            if ( true === $available ) {
                $stock_state = 'in_stock';
                $stock_text  = 'Disponible';
            } elseif ( false === $available ) {
                $stock_state = 'out_of_stock';
                $stock_text  = 'No disponible';
            } else {
                $stock_state = 'unknown';
                $stock_text  = '';
            }

            $raw = [
                'shopify_product_gid' => $product_gid,
                'shopify_variant_gid' => (string) ( $variant['id'] ?? '' ),
                'handle'              => (string) ( $product['handle'] ?? '' ),
                'options'             => $variant['options'] ?? [],
                'tags'                => $variant['tags'] ?? [],
            ];

            $rows[] = [
                'proveedor_id_externo' => $external_id,
                'sku'                   => $sku,
                'mpn'                   => $sku,
                'url_origen'            => $variant_url,
                'url_canonica'          => $url,
                'nombre'                => $name,
                'descripcion'           => $row_description,
                'marca'                 => $brand,
                'categoria_proveedor'   => $category,
                'precio_sin_iva'        => '',
                'precio_con_iva'        => $price_vat,
                'iva_porcentaje'        => '',
                'moneda'                => $currency ?: 'EUR',
                'stock_estado'          => $stock_state,
                'stock_cantidad'        => '',
                'stock_texto'           => $stock_text,
                'imagenes'              => wp_json_encode( $images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                '_raw'                  => $raw,
            ];
        }

        return $rows;
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_write_csv' ) ) {
    /**
     * Escribe las filas en CSV estandar y devuelve el estado analizado.
     *
     * @param array $recipe Receta.
     * @param array $rows Filas.
     * @param bool  $catalog_complete Catalogo completo verificado.
     * @return array|WP_Error
     */
    function seo_supplier_recipe_totherramienta_write_csv( array $recipe, array $rows, $catalog_complete ) {
        if (
            ! function_exists( 'seo_proveedores_storage_receta' )
            || ! function_exists( 'seo_proveedores_cabecera_estandar' )
            || ! function_exists( 'seo_proveedores_analizar_csv' )
        ) {
            return new WP_Error( 'totherramienta_pipeline_missing', 'El motor comun de proveedores no esta cargado.' );
        }

        $storage = seo_proveedores_storage_receta( 'prepared', $recipe['id'] );
        if ( is_wp_error( $storage ) ) {
            return $storage;
        }

        $filename = wp_unique_filename(
            $storage['dir'],
            'ucp_' . sanitize_key( $recipe['id'] ) . '_' . wp_date( 'Ymd_His' ) . '.csv'
        );
        $path = trailingslashit( $storage['dir'] ) . $filename;
        $fh   = fopen( $path, 'w' );
        if ( false === $fh ) {
            return new WP_Error( 'totherramienta_csv_open', 'No se pudo crear el CSV de TotHerramienta.' );
        }

        $columns = seo_proveedores_cabecera_estandar();
        fwrite( $fh, "\xEF\xBB\xBF" );
        fputcsv( $fh, $columns, ';', '"', '' );

        $written = 0;
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $csv_row = [];
            foreach ( $columns as $key ) {
                $value = $row[ $key ] ?? '';
                if ( ! is_scalar( $value ) ) {
                    $value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
                }
                $value = (string) $value;
                if ( preg_match( '/^[=+@]/', $value ) ) {
                    $value = "'" . $value;
                }
                $csv_row[] = $value;
            }
            fputcsv( $fh, $csv_row, ';', '"', '' );
            $written++;
        }
        fclose( $fh );

        if ( 0 === $written ) {
            @unlink( $path );
            return new WP_Error( 'totherramienta_csv_empty', 'TotHerramienta no devolvio ningun producto util.' );
        }

        $analysis = seo_proveedores_analizar_csv( $path );
        if ( is_wp_error( $analysis ) ) {
            return $analysis;
        }

        return array_merge(
            $analysis,
            [
                'recipe_id'            => $recipe['id'],
                'recipe_label'         => $recipe['label'],
                'recipe_version'       => (string) ( $recipe['version'] ?? '' ),
                'proveedor'            => $recipe['provider'],
                'filename'             => $filename,
                'path'                 => wp_normalize_path( $path ),
                'url'                  => trailingslashit( $storage['url'] ) . rawurlencode( $filename ),
                'original_filename'    => 'shopify-ucp-mcp',
                'original_path'        => '',
                'created'              => time(),
                'automatic_source'     => true,
                'v2_source'            => 'local_ucp_mcp',
                'v2_catalog_complete'  => $catalog_complete ? 1 : 0,
                'v2_auto_apply'        => 0,
                'v2_auto_bajas'        => 0,
                'v2_image_mode'        => 'external',
                'v2_force_image_mode'  => 0,
            ]
        );
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_start' ) ) {
    /**
     * Descarga el catalogo por UCP/MCP y lo importa en el catalogo intermedio.
     *
     * @param array $recipe Receta.
     * @param bool  $catalog_complete Si el usuario marco catalogo completo.
     * @return array|WP_Error
     */
    function seo_supplier_recipe_totherramienta_start( $recipe, $catalog_complete = false ) {
        if ( ! is_array( $recipe ) ) {
            return new WP_Error( 'totherramienta_recipe_invalid', 'Receta TotHerramienta no valida.' );
        }
        if ( ! function_exists( 'seo_proveedores_importar_csv_estandar' ) ) {
            return new WP_Error( 'totherramienta_importer_missing', 'El importador comun de proveedores no esta cargado.' );
        }

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );
        }

        $page_limit    = $catalog_complete ? 250 : 50;
        $max_pages     = $catalog_complete ? 100 : 1;
        $cursor        = '';
        $request_id    = 1;
        $pages         = 0;
        $products_seen = 0;
        $all_rows      = [];
        $seen_rows     = [];
        $completed_all = false;
        $last_total    = null;

        do {
            $catalog = [
                // UCP permite una operacion browse sin query cuando hay filtros.
                // available=false en la extension Shopify incluye tambien los
                // productos no disponibles, imprescindible para un snapshot completo.
                'filters' => [
                    'available' => false,
                ],
                'context' => [
                    'address_country' => 'ES',
                    'language'        => 'es',
                    'currency'        => 'EUR',
                    'intent'          => 'Catalog synchronization for product comparison and resale.',
                ],
                'pagination' => [
                    'limit' => $page_limit,
                ],
            ];
            if ( '' !== $cursor ) {
                $catalog['pagination']['cursor'] = $cursor;
            }

            $structured = seo_supplier_recipe_totherramienta_ucp_call( 'search_catalog', $catalog, $request_id++ );
            if ( is_wp_error( $structured ) ) {
                return $structured;
            }

            $products = (array) ( $structured['products'] ?? [] );
            $pagination = is_array( $structured['pagination'] ?? null ) ? $structured['pagination'] : [];
            $pages++;
            $products_seen += count( $products );
            if ( isset( $pagination['total_count'] ) && is_numeric( $pagination['total_count'] ) ) {
                $last_total = absint( $pagination['total_count'] );
            }

            foreach ( $products as $product ) {
                if ( ! is_array( $product ) ) {
                    continue;
                }
                foreach ( seo_supplier_recipe_totherramienta_product_rows( $product ) as $row ) {
                    $external_id = trim( (string) ( $row['proveedor_id_externo'] ?? '' ) );
                    $name        = trim( (string) ( $row['nombre'] ?? '' ) );
                    if ( '' === $external_id || '' === $name ) {
                        continue;
                    }
                    $key = $external_id;
                    if ( isset( $seen_rows[ $key ] ) ) {
                        continue;
                    }
                    $seen_rows[ $key ] = true;
                    unset( $row['_raw'] );
                    $all_rows[] = $row;
                }
            }

            $has_next = ! empty( $pagination['has_next_page'] );
            if ( ! $catalog_complete ) {
                $completed_all = false;
                break;
            }

            if ( ! $has_next ) {
                $completed_all = true;
                break;
            }

            $next_cursor = trim( (string) ( $pagination['cursor'] ?? '' ) );
            if ( '' === $next_cursor ) {
                return new WP_Error( 'totherramienta_ucp_cursor', 'UCP indica que hay otra pagina pero no devuelve cursor. No se importa un catalogo parcial.' );
            }
            if ( $next_cursor === $cursor ) {
                return new WP_Error( 'totherramienta_ucp_cursor_loop', 'UCP repitio el cursor de paginacion. Se detiene para evitar un bucle y no se importa un catalogo parcial.' );
            }
            $cursor = $next_cursor;

            if ( $pages >= $max_pages ) {
                return new WP_Error( 'totherramienta_ucp_page_guard', 'Se alcanzo el limite de seguridad de paginas UCP sin llegar al final. No se importa un catalogo parcial.' );
            }
        } while ( true );

        if ( empty( $all_rows ) ) {
            return new WP_Error( 'totherramienta_ucp_empty', 'UCP/MCP no devolvio productos importables.' );
        }

        $state = seo_supplier_recipe_totherramienta_write_csv( $recipe, $all_rows, $catalog_complete && $completed_all );
        if ( is_wp_error( $state ) ) {
            return $state;
        }

        $preparation_log = [
            'procesados' => count( $all_rows ),
            'preparados' => count( $all_rows ),
            'omitidos'   => 0,
            'errores'    => 0,
            'detalles'   => [
                sprintf(
                    'TotHerramienta UCP/MCP: %d productos UCP, %d filas normalizadas, %d pagina(s).',
                    $products_seen,
                    count( $all_rows ),
                    $pages
                ),
            ],
        ];

        $result = seo_proveedores_importar_csv_estandar( $state, $preparation_log );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $message = sprintf(
            'UCP/MCP completado: %d productos recibidos, %d referencias importadas en %d pagina(s). Nuevos: %d; actualizados: %d; sin cambios: %d.%s',
            $products_seen,
            count( $all_rows ),
            $pages,
            absint( $result['creados'] ?? 0 ),
            absint( $result['actualizados'] ?? 0 ),
            absint( $result['sin_cambios'] ?? 0 ),
            null !== $last_total ? ' Total informado por Shopify: ' . number_format_i18n( $last_total ) . '.' : ''
        );

        if ( ! $catalog_complete ) {
            $message .= ' Prueba corta: no se ha marcado como catalogo completo.';
        }

        return [
            'message'          => $message,
            'products'         => $products_seen,
            'rows'             => count( $all_rows ),
            'pages'            => $pages,
            'catalog_complete' => $catalog_complete && $completed_all,
            'filename'         => (string) ( $state['filename'] ?? '' ),
        ];
    }
}

/**
 * Receta de archivo manual como respaldo/auditoria.
 */
add_filter(
    'seo_proveedores_import_recipes',
    static function ( $recipes ) {
        if ( ! is_array( $recipes ) ) {
            $recipes = [];
        }
        $recipes['totherramienta'] = [
            'id'          => 'totherramienta',
            'label'       => 'TotHerramienta - archivo / UCP',
            'provider'    => 'TOTHERRAMIENTA',
            'version'     => '1.0.0',
            'mode'        => 'mapping',
            'description' => 'Permite cargar manualmente un CSV/XLS/XLSX de TotHerramienta. La obtencion automatica recomendada usa Shopify UCP/MCP desde el bloque web.',
        ];
        return $recipes;
    }
);

/**
 * Proceso local: aparece en "Obtener catalogo desde la web" y se ejecuta en
 * WordPress, sin GitHub y sin crawler HTML.
 */
add_filter(
    'seo_supplier_crawl_recipes',
    static function ( $recipes ) {
        if ( ! is_array( $recipes ) ) {
            $recipes = [];
        }
        $recipes['totherramienta_ucp'] = [
            'id'                   => 'totherramienta_ucp',
            'label'                => 'TotHerramienta - Shopify UCP/MCP',
            'provider'             => 'TOTHERRAMIENTA',
            'version'              => '1.0.0',
            'execution'            => 'local_process',
            'start_callback'       => 'seo_supplier_recipe_totherramienta_start',
            'requires_local_files' => false,
            'description'          => 'Descarga el catalogo publico mediante el endpoint Shopify UCP/MCP y lo convierte directamente al CSV estandar. No usa GitHub ni scraping HTML.',
        ];
        return $recipes;
    }
);
