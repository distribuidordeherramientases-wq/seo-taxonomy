<?php
/**
 * Receta TOTHERRAMIENTA mediante Shopify JSON + sitemap + Ajax API, con UCP/MCP como ultimo fallback.
 *
 * No usa GitHub ni scraping HTML. La via principal son endpoints JSON publicos
 * de Shopify. En catalogo completo contrasta el resultado con sitemap.xml y
 * completa los productos que falten mediante /products/{handle}.js.
 *
 * Flujo:
 * 1) /products.json (hasta 250 por pagina).
 * 2) /collections/all/products.json como segunda fuente y comprobacion.
 * 3) sitemap.xml + sitemaps de producto para verificar el universo publicado.
 * 4) Ajax Product API /products/{handle}.js para cualquier handle faltante.
 * 5) UCP/MCP solo como ultimo fallback de diagnostico/prueba.
 * 6) Genera CSV estandar para el importador y un CSV ampliado de auditoria.
 *
 * El CSV ampliado conserva identificadores y metadatos que el esquema estandar
 * actual no tiene como columnas propias: GTIN/EAN/barcode, IDs Shopify, peso,
 * tags, compare-at price, opciones, fechas y otros datos expuestos por Shopify.
 *
 * @package SEOSystem
 * @subpackage SupplierImports
 * @version 1.2.0
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
            'https://shopify.dev/ucp/agent-profiles/examples/2026-08-25/valid-with-capabilities.json'
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
     * @param int    $timeout Timeout HTTP.
     * @return array|WP_Error structuredContent.
     */
    function seo_supplier_recipe_totherramienta_ucp_call( $tool, array $catalog, $request_id = 1, $timeout = 45 ) {
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
                'timeout'     => max( 5, min( 60, absint( $timeout ) ) ),
                'redirection' => 3,
                'headers'     => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                    'User-Agent'   => 'DistribuidorDeHerramientas-SEOSystem/1.2; +' . home_url( '/' ),
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


if ( ! function_exists( 'seo_supplier_recipe_totherramienta_store_url' ) ) {
    /**
     * URL base publica de la tienda.
     *
     * @return string
     */
    function seo_supplier_recipe_totherramienta_store_url() {
        return untrailingslashit(
            (string) apply_filters( 'seo_totherramienta_store_url', 'https://totherramienta.com' )
        );
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_remote_get' ) ) {
    /**
     * GET publico con reintentos suaves para 429/5xx.
     *
     * @param string $url URL.
     * @param int    $timeout Timeout por intento.
     * @return array|WP_Error Array con body/status/headers.
     */
    function seo_supplier_recipe_totherramienta_remote_get( $url, $timeout = 45 ) {
        $url = esc_url_raw( (string) $url );
        if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
            return new WP_Error( 'totherramienta_http_url', 'URL no valida: ' . (string) $url );
        }

        $last_error = null;
        for ( $attempt = 0; $attempt < 3; $attempt++ ) {
            $response = wp_safe_remote_get(
                $url,
                [
                    'timeout'     => max( 10, absint( $timeout ) ),
                    'redirection' => 5,
                    'headers'     => [
                        'Accept'       => 'application/json, application/xml, text/xml, */*;q=0.8',
                        'User-Agent'   => 'DistribuidorDeHerramientas-SEOSystem/1.1; +' . home_url( '/' ),
                        'Cache-Control'=> 'no-cache',
                    ],
                ]
            );

            if ( is_wp_error( $response ) ) {
                $last_error = $response;
                if ( $attempt < 2 ) {
                    sleep( 1 + $attempt );
                    continue;
                }
                return new WP_Error( 'totherramienta_http', $response->get_error_message() );
            }

            $status = (int) wp_remote_retrieve_response_code( $response );
            $body   = (string) wp_remote_retrieve_body( $response );

            if ( $status >= 200 && $status < 300 ) {
                return [
                    'status'  => $status,
                    'body'    => $body,
                    'headers' => wp_remote_retrieve_headers( $response ),
                ];
            }

            $last_error = new WP_Error(
                'totherramienta_http_status',
                'HTTP ' . $status . ' al consultar ' . $url . '. ' . substr( trim( wp_strip_all_tags( $body ) ), 0, 240 )
            );

            if ( ( 429 === $status || $status >= 500 ) && $attempt < 2 ) {
                $retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
                sleep( $retry_after > 0 && $retry_after <= 10 ? $retry_after : ( 1 + $attempt ) );
                continue;
            }

            return $last_error;
        }

        return is_wp_error( $last_error )
            ? $last_error
            : new WP_Error( 'totherramienta_http_unknown', 'No se pudo completar la peticion HTTP.' );
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_json_get' ) ) {
    /**
     * GET JSON publico.
     *
     * @param string $url URL.
     * @param int    $timeout Timeout.
     * @return array|WP_Error
     */
    function seo_supplier_recipe_totherramienta_json_get( $url, $timeout = 45 ) {
        $response = seo_supplier_recipe_totherramienta_remote_get( $url, $timeout );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $decoded = json_decode( (string) $response['body'], true );
        if ( ! is_array( $decoded ) ) {
            return new WP_Error( 'totherramienta_json_invalid', 'La respuesta de ' . $url . ' no contiene JSON valido.' );
        }
        return $decoded;
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_abs_url' ) ) {
    /**
     * Normaliza URLs absolutas/protocolo-relativas/relativas.
     *
     * @param mixed $url URL.
     * @return string
     */
    function seo_supplier_recipe_totherramienta_abs_url( $url ) {
        $url = trim( (string) $url );
        if ( '' === $url ) {
            return '';
        }
        if ( 0 === strpos( $url, '//' ) ) {
            return esc_url_raw( 'https:' . $url );
        }
        if ( preg_match( '#^https?://#i', $url ) ) {
            return esc_url_raw( $url );
        }
        return esc_url_raw( seo_supplier_recipe_totherramienta_store_url() . '/' . ltrim( $url, '/' ) );
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_shopify_decimal' ) ) {
    /**
     * Convierte precios Shopify a decimal. Ajax Product API usa minor units;
     * products.json suele devolver importes decimales como texto.
     *
     * @param mixed  $value Importe.
     * @param string $source Fuente: ajax o feed.
     * @return string
     */
    function seo_supplier_recipe_totherramienta_shopify_decimal( $value, $source = 'feed' ) {
        if ( null === $value || '' === trim( (string) $value ) || ! is_numeric( $value ) ) {
            return '';
        }
        $number = (float) $value;
        if ( 'ajax' === $source ) {
            $number /= 100;
        }
        return number_format( $number, 2, '.', '' );
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_product_key' ) ) {
    /**
     * Clave estable de producto Shopify.
     *
     * @param array $product Producto.
     * @return string
     */
    function seo_supplier_recipe_totherramienta_product_key( array $product ) {
        $handle = sanitize_title( (string) ( $product['handle'] ?? '' ) );
        if ( '' !== $handle ) {
            return 'h:' . $handle;
        }
        $id = trim( (string) ( $product['id'] ?? '' ) );
        return '' !== $id ? 'i:' . $id : '';
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_merge_product' ) ) {
    /**
     * Fusion no destructiva: conserva datos existentes y rellena huecos.
     * Variantes e imagenes se fusionan por ID/URL cuando es posible.
     *
     * @param array $base Producto base.
     * @param array $extra Producto complementario.
     * @return array
     */
    function seo_supplier_recipe_totherramienta_merge_product( array $base, array $extra ) {
        foreach ( $extra as $key => $value ) {
            if ( in_array( $key, [ 'variants', 'images' ], true ) ) {
                continue;
            }
            $empty = ! array_key_exists( $key, $base ) || null === $base[ $key ] || '' === $base[ $key ] || [] === $base[ $key ];
            if ( $empty && null !== $value && '' !== $value && [] !== $value ) {
                $base[ $key ] = $value;
            }
        }

        $variants = [];
        foreach ( array_merge( (array) ( $base['variants'] ?? [] ), (array) ( $extra['variants'] ?? [] ) ) as $variant ) {
            if ( ! is_array( $variant ) ) {
                continue;
            }
            $key = trim( (string) ( $variant['id'] ?? '' ) );
            if ( '' === $key ) {
                $key = 'sku:' . trim( (string) ( $variant['sku'] ?? '' ) ) . ':' . trim( (string) ( $variant['title'] ?? '' ) );
            }
            if ( isset( $variants[ $key ] ) ) {
                foreach ( $variant as $vkey => $vvalue ) {
                    if ( ! isset( $variants[ $key ][ $vkey ] ) || '' === $variants[ $key ][ $vkey ] || null === $variants[ $key ][ $vkey ] ) {
                        $variants[ $key ][ $vkey ] = $vvalue;
                    }
                }
            } else {
                $variants[ $key ] = $variant;
            }
        }
        if ( ! empty( $variants ) ) {
            $base['variants'] = array_values( $variants );
        }

        $images = [];
        foreach ( array_merge( (array) ( $base['images'] ?? [] ), (array) ( $extra['images'] ?? [] ) ) as $image ) {
            $url = is_array( $image ) ? ( $image['src'] ?? $image['url'] ?? '' ) : $image;
            $url = seo_supplier_recipe_totherramienta_abs_url( $url );
            if ( '' !== $url ) {
                $images[ $url ] = $image;
            }
        }
        if ( ! empty( $images ) ) {
            $base['images'] = array_values( $images );
        }

        return $base;
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_feed_collect' ) ) {
    /**
     * Pagina un feed publico Shopify products.json.
     *
     * @param string $base_endpoint Endpoint sin query.
     * @param bool   $catalog_complete Catalogo completo.
     * @return array|WP_Error
     */
    function seo_supplier_recipe_totherramienta_feed_collect( $base_endpoint, $catalog_complete ) {
        $limit     = $catalog_complete ? 250 : 50;
        $max_pages = $catalog_complete ? 200 : 1;
        $products  = [];
        $raw_count = 0;
        $pages     = 0;
        $last_sig  = '';
        $warning   = '';

        for ( $page = 1; $page <= $max_pages; $page++ ) {
            $url = add_query_arg(
                [
                    'limit' => $limit,
                    'page'  => $page,
                ],
                $base_endpoint
            );
            $decoded = seo_supplier_recipe_totherramienta_json_get( $url, 60 );
            if ( is_wp_error( $decoded ) ) {
                if ( 1 === $page ) {
                    return $decoded;
                }
                $warning = 'El feed ' . $base_endpoint . ' fallo en la pagina ' . $page . ': ' . $decoded->get_error_message();
                break;
            }

            $page_products = [];
            if ( isset( $decoded['products'] ) && is_array( $decoded['products'] ) ) {
                $page_products = $decoded['products'];
            } elseif ( array_keys( $decoded ) === range( 0, count( $decoded ) - 1 ) ) {
                $page_products = $decoded;
            }

            $pages++;
            $raw_count += count( $page_products );
            if ( empty( $page_products ) ) {
                break;
            }

            $first = is_array( reset( $page_products ) ) ? reset( $page_products ) : [];
            $last  = is_array( end( $page_products ) ) ? end( $page_products ) : [];
            $sig   = seo_supplier_recipe_totherramienta_product_key( (array) $first ) . '|' . seo_supplier_recipe_totherramienta_product_key( (array) $last ) . '|' . count( $page_products );
            if ( $page > 1 && '' !== $sig && $sig === $last_sig ) {
                $warning = 'El feed ' . $base_endpoint . ' repitio una pagina; se detuvo la paginacion y el sitemap completara/verificara el resultado.';
                break;
            }
            $last_sig = $sig;

            foreach ( $page_products as $product ) {
                if ( ! is_array( $product ) ) {
                    continue;
                }
                $product['_seo_source'] = 'feed';
                $key = seo_supplier_recipe_totherramienta_product_key( $product );
                if ( '' === $key ) {
                    continue;
                }
                $products[ $key ] = isset( $products[ $key ] )
                    ? seo_supplier_recipe_totherramienta_merge_product( $products[ $key ], $product )
                    : $product;
            }

            if ( ! $catalog_complete ) {
                break;
            }
        }

        return [
            'products'  => $products,
            'raw_count' => $raw_count,
            'pages'     => $pages,
            'warning'   => $warning,
        ];
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_xml_locs' ) ) {
    /**
     * Extrae todos los <loc> de un sitemap XML.
     *
     * @param string $xml XML.
     * @return array<int,string>
     */
    function seo_supplier_recipe_totherramienta_xml_locs( $xml ) {
        $locs = [];
        if ( function_exists( 'simplexml_load_string' ) ) {
            $previous = function_exists( 'libxml_use_internal_errors' ) ? libxml_use_internal_errors( true ) : null;
            $sx = simplexml_load_string( (string) $xml, 'SimpleXMLElement', LIBXML_NOCDATA );
            if ( $sx instanceof SimpleXMLElement ) {
                $nodes = $sx->xpath( '//*[local-name()="loc"]' );
                if ( is_array( $nodes ) ) {
                    foreach ( $nodes as $node ) {
                        $url = html_entity_decode( trim( (string) $node ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
                        if ( '' !== $url ) {
                            $locs[] = $url;
                        }
                    }
                }
            }
            if ( function_exists( 'libxml_clear_errors' ) ) {
                libxml_clear_errors();
            }
            if ( null !== $previous && function_exists( 'libxml_use_internal_errors' ) ) {
                libxml_use_internal_errors( $previous );
            }
        }

        if ( empty( $locs ) && preg_match_all( '#<loc[^>]*>(.*?)</loc>#is', (string) $xml, $matches ) ) {
            foreach ( $matches[1] as $raw ) {
                $url = html_entity_decode( trim( wp_strip_all_tags( $raw ) ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
                if ( '' !== $url ) {
                    $locs[] = $url;
                }
            }
        }

        return array_values( array_unique( $locs ) );
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_handle_from_url' ) ) {
    /**
     * Extrae handle de una URL publica /products/{handle}.
     *
     * @param string $url URL.
     * @return string
     */
    function seo_supplier_recipe_totherramienta_handle_from_url( $url ) {
        $path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
        if ( ! preg_match( '#/products/([^/]+)#i', $path, $match ) ) {
            return '';
        }
        return sanitize_title( rawurldecode( $match[1] ) );
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_sitemap_products' ) ) {
    /**
     * Lee sitemap.xml y devuelve el universo de handles de producto publicados.
     *
     * @return array|WP_Error
     */
    function seo_supplier_recipe_totherramienta_sitemap_products() {
        $root_url = seo_supplier_recipe_totherramienta_store_url() . '/sitemap.xml';
        $root = seo_supplier_recipe_totherramienta_remote_get( $root_url, 60 );
        if ( is_wp_error( $root ) ) {
            return $root;
        }

        $root_locs = seo_supplier_recipe_totherramienta_xml_locs( $root['body'] );
        if ( empty( $root_locs ) ) {
            return new WP_Error( 'totherramienta_sitemap_empty', 'sitemap.xml no contiene entradas <loc>.' );
        }

        $handles = [];
        $product_sitemaps = [];
        foreach ( $root_locs as $loc ) {
            $handle = seo_supplier_recipe_totherramienta_handle_from_url( $loc );
            if ( '' !== $handle ) {
                $handles[ $handle ] = $loc;
                continue;
            }
            if ( false !== stripos( $loc, 'sitemap_products_' ) || false !== stripos( $loc, 'sitemap-products' ) ) {
                $product_sitemaps[] = $loc;
            }
        }

        $product_sitemaps = array_values( array_unique( $product_sitemaps ) );
        foreach ( $product_sitemaps as $sitemap_url ) {
            $response = seo_supplier_recipe_totherramienta_remote_get( $sitemap_url, 60 );
            if ( is_wp_error( $response ) ) {
                return new WP_Error(
                    'totherramienta_sitemap_child',
                    'No se pudo leer un sitemap de productos: ' . $response->get_error_message()
                );
            }
            foreach ( seo_supplier_recipe_totherramienta_xml_locs( $response['body'] ) as $loc ) {
                $handle = seo_supplier_recipe_totherramienta_handle_from_url( $loc );
                if ( '' !== $handle ) {
                    $handles[ $handle ] = $loc;
                }
            }
        }

        if ( empty( $handles ) ) {
            return new WP_Error( 'totherramienta_sitemap_no_products', 'No se localizaron URLs de producto en los sitemaps publicados.' );
        }

        return [
            'handles'          => $handles,
            'product_sitemaps' => count( $product_sitemaps ),
        ];
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_ajax_product' ) ) {
    /**
     * Obtiene un producto por Ajax Product API /products/{handle}.js.
     *
     * @param string $handle Handle Shopify.
     * @return array|WP_Error
     */
    function seo_supplier_recipe_totherramienta_ajax_product( $handle ) {
        $handle = sanitize_title( (string) $handle );
        if ( '' === $handle ) {
            return new WP_Error( 'totherramienta_ajax_handle', 'Handle Shopify vacio.' );
        }
        $url = seo_supplier_recipe_totherramienta_store_url() . '/products/' . rawurlencode( $handle ) . '.js';
        $product = seo_supplier_recipe_totherramienta_json_get( $url, 35 );
        if ( is_wp_error( $product ) ) {
            return $product;
        }
        if ( empty( $product['id'] ) && empty( $product['handle'] ) ) {
            return new WP_Error( 'totherramienta_ajax_shape', 'El Ajax Product API no devolvio un producto valido para ' . $handle . '.' );
        }
        $product['_seo_source'] = 'ajax';
        if ( empty( $product['handle'] ) ) {
            $product['handle'] = $handle;
        }
        return $product;
    }
}


if ( ! function_exists( 'seo_supplier_recipe_totherramienta_ajax_products_batch' ) ) {
    /**
     * Recupera varios productos Ajax. Usa curl_multi cuando esta disponible
     * para que el enriquecimiento de catalogos grandes no requiera miles de
     * peticiones estrictamente secuenciales. Los fallos se devuelven por handle.
     *
     * @param array<int,string> $handles Handles.
     * @return array{products:array<string,array>,errors:array<string,string>}
     */
    function seo_supplier_recipe_totherramienta_ajax_products_batch( array $handles ) {
        $handles = array_values( array_unique( array_filter( array_map( 'sanitize_title', $handles ) ) ) );
        $result = [ 'products' => [], 'errors' => [] ];
        if ( empty( $handles ) ) {
            return $result;
        }

        if ( ! function_exists( 'curl_multi_init' ) || ! function_exists( 'curl_init' ) ) {
            foreach ( $handles as $handle ) {
                $product = seo_supplier_recipe_totherramienta_ajax_product( $handle );
                if ( is_wp_error( $product ) ) {
                    $result['errors'][ $handle ] = $product->get_error_message();
                } else {
                    $result['products'][ $handle ] = $product;
                }
            }
            return $result;
        }

        $concurrency = (int) apply_filters( 'seo_totherramienta_ajax_concurrency', 8 );
        $concurrency = max( 1, min( 16, $concurrency ) );
        $base = seo_supplier_recipe_totherramienta_store_url();
        $user_agent = 'DistribuidorDeHerramientas-SEOSystem/1.1; +' . home_url( '/' );

        foreach ( array_chunk( $handles, $concurrency ) as $chunk ) {
            $mh = curl_multi_init();
            $map = [];
            foreach ( $chunk as $handle ) {
                $ch = curl_init();
                $url = $base . '/products/' . rawurlencode( $handle ) . '.js';
                curl_setopt_array(
                    $ch,
                    [
                        CURLOPT_URL            => $url,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_MAXREDIRS      => 3,
                        CURLOPT_CONNECTTIMEOUT => 10,
                        CURLOPT_TIMEOUT        => 30,
                        CURLOPT_USERAGENT      => $user_agent,
                        CURLOPT_HTTPHEADER     => [ 'Accept: application/json', 'Cache-Control: no-cache' ],
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_SSL_VERIFYHOST => 2,
                        CURLOPT_ENCODING       => '',
                    ]
                );
                curl_multi_add_handle( $mh, $ch );
                $map[] = [ 'handle' => $handle, 'ch' => $ch ];
            }

            $running = null;
            do {
                $status = curl_multi_exec( $mh, $running );
                if ( CURLM_OK !== $status ) {
                    break;
                }
                if ( $running ) {
                    $selected = curl_multi_select( $mh, 1.0 );
                    if ( -1 === $selected ) {
                        usleep( 10000 );
                    }
                }
            } while ( $running );

            foreach ( $map as $item ) {
                $handle = $item['handle'];
                $ch = $item['ch'];
                $body = (string) curl_multi_getcontent( $ch );
                $http = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
                $error = curl_error( $ch );
                if ( $http >= 200 && $http < 300 && '' !== $body ) {
                    $decoded = json_decode( $body, true );
                    if ( is_array( $decoded ) && ( ! empty( $decoded['id'] ) || ! empty( $decoded['handle'] ) ) ) {
                        $decoded['_seo_source'] = 'ajax';
                        if ( empty( $decoded['handle'] ) ) {
                            $decoded['handle'] = $handle;
                        }
                        $result['products'][ $handle ] = $decoded;
                    } else {
                        $result['errors'][ $handle ] = 'JSON Ajax invalido.';
                    }
                } else {
                    $result['errors'][ $handle ] = '' !== $error ? $error : ( 'HTTP ' . $http );
                }
                curl_multi_remove_handle( $mh, $ch );
                curl_close( $ch );
            }
            curl_multi_close( $mh );
            usleep( 25000 );
        }

        return $result;
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_shopify_images' ) ) {
    /**
     * Normaliza galeria Shopify.
     *
     * @param array $product Producto.
     * @param array $variant Variante.
     * @return array<int,string>
     */
    function seo_supplier_recipe_totherramienta_shopify_images( array $product, array $variant = [] ) {
        $images = [];

        $featured = $variant['featured_image'] ?? null;
        if ( is_array( $featured ) ) {
            $featured = $featured['src'] ?? $featured['url'] ?? '';
        }
        $featured = seo_supplier_recipe_totherramienta_abs_url( $featured );
        if ( '' !== $featured ) {
            $images[] = $featured;
        }

        foreach ( (array) ( $product['images'] ?? [] ) as $image ) {
            $url = is_array( $image ) ? ( $image['src'] ?? $image['url'] ?? '' ) : $image;
            $url = seo_supplier_recipe_totherramienta_abs_url( $url );
            if ( '' !== $url ) {
                $images[] = $url;
            }
        }

        $featured_product = $product['featured_image'] ?? '';
        if ( is_array( $featured_product ) ) {
            $featured_product = $featured_product['src'] ?? $featured_product['url'] ?? '';
        }
        $featured_product = seo_supplier_recipe_totherramienta_abs_url( $featured_product );
        if ( '' !== $featured_product ) {
            $images[] = $featured_product;
        }

        return array_values( array_slice( array_unique( $images ), 0, 50 ) );
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_shopify_category' ) ) {
    /**
     * Categoria preferente desde product_type/type/collections; tags solo fallback.
     *
     * @param array $product Producto.
     * @return string
     */
    function seo_supplier_recipe_totherramienta_shopify_category( array $product ) {
        $parts = [];
        $type = seo_supplier_recipe_totherramienta_text( $product['product_type'] ?? $product['type'] ?? '' );
        if ( '' !== $type ) {
            $parts[] = $type;
        }
        foreach ( (array) ( $product['collections'] ?? [] ) as $collection ) {
            $title = is_array( $collection )
                ? seo_supplier_recipe_totherramienta_text( $collection['title'] ?? $collection['name'] ?? '' )
                : seo_supplier_recipe_totherramienta_text( $collection );
            if ( '' !== $title ) {
                $parts[] = $title;
            }
        }
        $parts = array_values( array_unique( $parts ) );
        if ( ! empty( $parts ) ) {
            return implode( ' | ', array_slice( $parts, 0, 8 ) );
        }

        $tags = $product['tags'] ?? [];
        if ( is_string( $tags ) ) {
            $tags = preg_split( '/\s*,\s*/', $tags, -1, PREG_SPLIT_NO_EMPTY );
        }
        $tags = array_values( array_filter( array_map( 'seo_supplier_recipe_totherramienta_text', (array) $tags ) ) );
        return implode( ' | ', array_slice( array_unique( $tags ), 0, 5 ) );
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_shopify_description' ) ) {
    /**
     * Descripcion HTML publica Shopify.
     *
     * @param array $product Producto.
     * @return string
     */
    function seo_supplier_recipe_totherramienta_shopify_description( array $product ) {
        foreach ( [ 'body_html', 'description' ] as $key ) {
            if ( isset( $product[ $key ] ) && is_string( $product[ $key ] ) && '' !== trim( $product[ $key ] ) ) {
                return trim( wp_kses_post( $product[ $key ] ) );
            }
        }
        return '';
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_shopify_rows' ) ) {
    /**
     * Convierte producto Shopify JSON/Ajax en filas estandar, conservando extras.
     *
     * @param array $product Producto.
     * @return array<int,array<string,mixed>>
     */
    function seo_supplier_recipe_totherramienta_shopify_rows( array $product ) {
        $source       = 'ajax' === ( $product['_seo_source'] ?? '' ) ? 'ajax' : 'feed';
        $product_id   = trim( (string) ( $product['id'] ?? '' ) );
        $handle       = sanitize_title( (string) ( $product['handle'] ?? '' ) );
        $title        = seo_supplier_recipe_totherramienta_text( $product['title'] ?? '' );
        $description  = seo_supplier_recipe_totherramienta_shopify_description( $product );
        $brand        = seo_supplier_recipe_totherramienta_text( $product['vendor'] ?? $product['brand'] ?? '' );
        $category     = seo_supplier_recipe_totherramienta_shopify_category( $product );
        $product_type = seo_supplier_recipe_totherramienta_text( $product['product_type'] ?? $product['type'] ?? '' );
        $canonical    = '' !== $handle
            ? seo_supplier_recipe_totherramienta_store_url() . '/products/' . rawurlencode( $handle )
            : seo_supplier_recipe_totherramienta_abs_url( $product['url'] ?? '' );

        if ( '' === $title || ( '' === $product_id && '' === $handle ) ) {
            return [];
        }

        $tags = $product['tags'] ?? [];
        if ( is_string( $tags ) ) {
            $tags = preg_split( '/\s*,\s*/', $tags, -1, PREG_SPLIT_NO_EMPTY );
        }
        $tags = array_values( array_filter( array_map( 'seo_supplier_recipe_totherramienta_text', (array) $tags ) ) );

        $variants = array_values( array_filter( (array) ( $product['variants'] ?? [] ), 'is_array' ) );
        if ( empty( $variants ) ) {
            $variants[] = [
                'id'        => $product_id,
                'title'     => '',
                'price'     => $product['price'] ?? $product['price_min'] ?? '',
                'available' => $product['available'] ?? null,
                'sku'       => $product['sku'] ?? '',
                'barcode'   => $product['barcode'] ?? '',
            ];
        }

        $rows = [];
        foreach ( $variants as $variant ) {
            $variant_id = trim( (string) ( $variant['id'] ?? '' ) );
            $sku        = seo_supplier_recipe_totherramienta_text( $variant['sku'] ?? '' );
            $barcode    = seo_supplier_recipe_totherramienta_text( $variant['barcode'] ?? $variant['gtin'] ?? $variant['ean'] ?? $variant['upc'] ?? '' );
            $mpn        = seo_supplier_recipe_totherramienta_text(
                $variant['mpn'] ?? $variant['manufacturer_part_number'] ?? $variant['part_number'] ?? ''
            );
            if ( '' === $mpn ) {
                // Mantiene la convencion del importador historico sin confundir GTIN con MPN.
                $mpn = $sku;
            }

            $external_id = '' !== $variant_id ? $variant_id : ( '' !== $sku ? $sku : ( '' !== $barcode ? $barcode : $product_id ) );
            if ( '' === $external_id ) {
                $external_id = $handle;
            }

            $variant_title = seo_supplier_recipe_totherramienta_text( $variant['title'] ?? '' );
            $name = $title;
            if (
                '' !== $variant_title
                && ! in_array( strtolower( $variant_title ), [ 'default title', 'default', 'predeterminado' ], true )
                && 0 !== strcasecmp( $variant_title, $title )
            ) {
                $name .= ' - ' . $variant_title;
            }

            $price = seo_supplier_recipe_totherramienta_shopify_decimal( $variant['price'] ?? $product['price'] ?? '', $source );
            $compare_at = seo_supplier_recipe_totherramienta_shopify_decimal( $variant['compare_at_price'] ?? $product['compare_at_price'] ?? '', $source );

            $available = $variant['available'] ?? $product['available'] ?? null;
            $qty = '';
            foreach ( [ 'inventory_quantity', 'quantity_available', 'inventory_qty' ] as $qty_key ) {
                if ( isset( $variant[ $qty_key ] ) && is_numeric( $variant[ $qty_key ] ) ) {
                    $qty = (string) (int) $variant[ $qty_key ];
                    if ( null === $available ) {
                        $available = (int) $variant[ $qty_key ] > 0;
                    }
                    break;
                }
            }

            if ( true === $available || 1 === $available || 'true' === $available ) {
                $stock_state = 'in_stock';
                $stock_text  = 'Disponible';
            } elseif ( false === $available || 0 === $available || 'false' === $available ) {
                $stock_state = 'out_of_stock';
                $stock_text  = 'No disponible';
            } else {
                $stock_state = 'unknown';
                $stock_text  = '';
            }

            $url = $canonical;
            if ( '' !== $variant_id && '' !== $url ) {
                $url = add_query_arg( 'variant', rawurlencode( $variant_id ), $url );
            }

            $images = seo_supplier_recipe_totherramienta_shopify_images( $product, $variant );
            $options = $variant['options'] ?? [];
            if ( empty( $options ) ) {
                $options = array_values( array_filter( [ $variant['option1'] ?? null, $variant['option2'] ?? null, $variant['option3'] ?? null ], static function ( $v ) { return null !== $v && '' !== $v; } ) );
            }

            $rows[] = [
                'proveedor_id_externo' => $external_id,
                'sku'                   => $sku,
                'mpn'                   => $mpn,
                'url_origen'            => $url,
                'url_canonica'          => $canonical,
                'nombre'                => $name,
                'descripcion'           => $description,
                'marca'                 => $brand,
                'categoria_proveedor'   => $category,
                'precio_sin_iva'        => '',
                'precio_con_iva'        => $price,
                'iva_porcentaje'        => '',
                'moneda'                => 'EUR',
                'stock_estado'          => $stock_state,
                'stock_cantidad'        => $qty,
                'stock_texto'           => $stock_text,
                'imagenes'              => wp_json_encode( $images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                '_extra'                => [
                    'gtin_barcode'         => $barcode,
                    'shopify_product_id'   => $product_id,
                    'shopify_variant_id'   => $variant_id,
                    'handle'               => $handle,
                    'vendor'               => $brand,
                    'product_type'         => $product_type,
                    'tags'                 => implode( ' | ', $tags ),
                    'compare_at_price'     => $compare_at,
                    'weight'               => isset( $variant['weight'] ) ? (string) $variant['weight'] : ( isset( $variant['grams'] ) ? (string) $variant['grams'] : '' ),
                    'weight_unit'          => seo_supplier_recipe_totherramienta_text( $variant['weight_unit'] ?? ( isset( $variant['grams'] ) ? 'g' : '' ) ),
                    'inventory_management' => seo_supplier_recipe_totherramienta_text( $variant['inventory_management'] ?? '' ),
                    'inventory_policy'     => seo_supplier_recipe_totherramienta_text( $variant['inventory_policy'] ?? '' ),
                    'requires_shipping'    => isset( $variant['requires_shipping'] ) ? ( $variant['requires_shipping'] ? '1' : '0' ) : '',
                    'taxable'              => isset( $variant['taxable'] ) ? ( $variant['taxable'] ? '1' : '0' ) : '',
                    'variant_title'        => $variant_title,
                    'options'              => wp_json_encode( $options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                    'published_at'         => seo_supplier_recipe_totherramienta_text( $product['published_at'] ?? '' ),
                    'created_at'           => seo_supplier_recipe_totherramienta_text( $product['created_at'] ?? '' ),
                    'updated_at'           => seo_supplier_recipe_totherramienta_text( $product['updated_at'] ?? '' ),
                    'source'               => $source,
                ],
            ];
        }

        return $rows;
    }
}


if ( ! function_exists( 'seo_supplier_recipe_totherramienta_needs_ajax_enrichment' ) ) {
    /**
     * Indica si el feed agregado omite campos que el Ajax Product API puede aportar.
     * En particular, /products.json puede omitir barcode/GTIN aunque exista.
     *
     * @param array $product Producto.
     * @return bool
     */
    function seo_supplier_recipe_totherramienta_needs_ajax_enrichment( array $product ) {
        $variants = array_values( array_filter( (array) ( $product['variants'] ?? [] ), 'is_array' ) );
        if ( empty( $variants ) ) {
            return true;
        }
        foreach ( $variants as $variant ) {
            if ( ! array_key_exists( 'barcode', $variant ) || ! array_key_exists( 'available', $variant ) ) {
                return true;
            }
        }
        return false;
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_write_extended_csv' ) ) {
    /**
     * Escribe CSV ampliado de auditoria sin alterar el esquema comun de importacion.
     *
     * @param array  $storage Storage preparado.
     * @param string $recipe_id ID receta.
     * @param array  $rows Filas con _extra.
     * @return array|WP_Error
     */
    function seo_supplier_recipe_totherramienta_write_extended_csv( array $storage, $recipe_id, array $rows ) {
        $extra_columns = [
            'gtin_barcode', 'shopify_product_id', 'shopify_variant_id', 'handle', 'vendor',
            'product_type', 'tags', 'compare_at_price', 'weight', 'weight_unit',
            'inventory_management', 'inventory_policy', 'requires_shipping', 'taxable',
            'variant_title', 'options', 'published_at', 'created_at', 'updated_at', 'source',
        ];
        $standard = seo_proveedores_cabecera_estandar();
        $columns  = array_merge( $standard, $extra_columns );

        $filename = wp_unique_filename(
            $storage['dir'],
            'shopify_extended_' . sanitize_key( $recipe_id ) . '_' . wp_date( 'Ymd_His' ) . '.csv'
        );
        $path = trailingslashit( $storage['dir'] ) . $filename;
        $fh = fopen( $path, 'w' );
        if ( false === $fh ) {
            return new WP_Error( 'totherramienta_extended_open', 'No se pudo crear el CSV ampliado de TotHerramienta.' );
        }
        fwrite( $fh, "\xEF\xBB\xBF" );
        fputcsv( $fh, $columns, ';', '"', '' );

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $extra = is_array( $row['_extra'] ?? null ) ? $row['_extra'] : [];
            $out = [];
            foreach ( $standard as $key ) {
                $value = $row[ $key ] ?? '';
                if ( ! is_scalar( $value ) ) {
                    $value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
                }
                $value = (string) $value;
                if ( preg_match( '/^[=+@]/', $value ) ) {
                    $value = "'" . $value;
                }
                $out[] = $value;
            }
            foreach ( $extra_columns as $key ) {
                $value = $extra[ $key ] ?? '';
                if ( ! is_scalar( $value ) ) {
                    $value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
                }
                $value = (string) $value;
                if ( preg_match( '/^[=+@]/', $value ) ) {
                    $value = "'" . $value;
                }
                $out[] = $value;
            }
            fputcsv( $fh, $out, ';', '"', '' );
        }
        fclose( $fh );

        return [
            'filename' => $filename,
            'path'     => wp_normalize_path( $path ),
            'url'      => trailingslashit( $storage['url'] ) . rawurlencode( $filename ),
        ];
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_write_csv' ) ) {
    /**
     * Escribe CSV estandar importable y CSV ampliado de auditoria.
     *
     * @param array  $recipe Receta.
     * @param array  $rows Filas.
     * @param bool   $catalog_complete Catalogo completo verificado.
     * @param string $source Fuente principal.
     * @return array|WP_Error
     */
    function seo_supplier_recipe_totherramienta_write_csv( array $recipe, array $rows, $catalog_complete, $source = 'shopify_json' ) {
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
            'shopify_' . sanitize_key( $recipe['id'] ) . '_' . wp_date( 'Ymd_His' ) . '.csv'
        );
        $path = trailingslashit( $storage['dir'] ) . $filename;
        $fh = fopen( $path, 'w' );
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

        $extended = seo_supplier_recipe_totherramienta_write_extended_csv( $storage, $recipe['id'], $rows );
        if ( is_wp_error( $extended ) ) {
            $extended = [ 'filename' => '', 'path' => '', 'url' => '' ];
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
                'extended_filename'    => (string) ( $extended['filename'] ?? '' ),
                'extended_path'        => (string) ( $extended['path'] ?? '' ),
                'extended_url'         => (string) ( $extended['url'] ?? '' ),
                'original_filename'    => 'shopify-json-public',
                'original_path'        => '',
                'created'              => time(),
                'automatic_source'     => true,
                'v2_source'            => sanitize_key( $source ),
                'v2_catalog_complete'  => $catalog_complete ? 1 : 0,
                'v2_auto_apply'        => 0,
                'v2_auto_bajas'        => 0,
                'v2_image_mode'        => 'external',
                'v2_force_image_mode'  => 0,
            ]
        );
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_ucp_rows_fallback' ) ) {
    /**
     * Ultimo fallback para prueba corta. No se acepta como catalogo completo sin
     * verificacion externa, porque search_catalog puede devolver un subconjunto.
     *
     * @param int $limit Limite.
     * @return array|WP_Error
     */
    function seo_supplier_recipe_totherramienta_ucp_rows_fallback( $limit = 50 ) {
        $catalog = [
            'filters' => [ 'available' => false ],
            'context' => [
                'address_country' => 'ES',
                'language'        => 'es',
                'currency'        => 'EUR',
                'intent'          => 'Catalog synchronization for product comparison and resale.',
            ],
            'pagination' => [ 'limit' => min( 250, max( 1, absint( $limit ) ) ) ],
        ];
        $structured = seo_supplier_recipe_totherramienta_ucp_call( 'search_catalog', $catalog, 1 );
        if ( is_wp_error( $structured ) ) {
            return $structured;
        }
        $rows = [];
        foreach ( (array) ( $structured['products'] ?? [] ) as $product ) {
            if ( ! is_array( $product ) ) {
                continue;
            }
            foreach ( seo_supplier_recipe_totherramienta_product_rows( $product ) as $row ) {
                $row['_extra'] = [ 'source' => 'ucp_mcp' ];
                $rows[] = $row;
            }
        }
        return $rows;
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_managed_delay' ) ) {
    /**
     * Ritmo adaptativo del proceso gestionado. No duerme dentro de la peticion:
     * guarda el instante de la siguiente ejecucion y devuelve el control al worker.
     *
     * @param array  $state Estado.
     * @param string $outcome success|throttle|server_error|network_error|blocked.
     * @param int    $hint Segundos sugeridos por Retry-After.
     * @return array
     */
    function seo_supplier_recipe_totherramienta_managed_delay( array $state, $outcome, $hint = 0 ) {
        $minimum = 15;
        $maximum = 30 * MINUTE_IN_SECONDS;
        $current = max( $minimum, absint( $state['adaptive_delay'] ?? $minimum ) );
        $streak  = absint( $state['success_streak'] ?? 0 );

        if ( 'success' === $outcome ) {
            $streak++;
            if ( $streak >= 6 && $current > $minimum ) {
                $current = max( $minimum, (int) floor( $current * 0.85 ) );
                $streak  = 0;
            }
        } elseif ( 'throttle' === $outcome ) {
            $current = max( $current * 3, absint( $hint ), 60 );
            $streak  = 0;
        } elseif ( 'server_error' === $outcome ) {
            $current = max( $current * 2, 60 );
            $streak  = 0;
        } elseif ( 'network_error' === $outcome ) {
            $current = max( (int) ceil( $current * 1.5 ), 45 );
            $streak  = 0;
        } elseif ( 'blocked' === $outcome ) {
            $current = max( $current * 4, 5 * MINUTE_IN_SECONDS );
            $streak  = 0;
        }

        $state['adaptive_delay'] = min( $maximum, max( $minimum, absint( $current ) ) );
        $state['success_streak'] = $streak;
        $state['last_rate_event']= sanitize_key( (string) $outcome );
        return $state;
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_reset_staging' ) ) {
    /**
     * Vacía exclusivamente el staging temporal de esta receta. No toca el
     * catálogo comercial ni productos WooCommerce.
     *
     * @param string $recipe_id ID.
     * @return void
     */
    function seo_supplier_recipe_totherramienta_reset_staging( $recipe_id ) {
        if ( ! function_exists( 'seo_supplier_crawl_install_table' ) || ! function_exists( 'seo_supplier_crawl_records_table' ) ) {
            return;
        }
        seo_supplier_crawl_install_table();
        global $wpdb;
        $table = seo_supplier_crawl_records_table();
        $wpdb->delete( $table, [ 'recipe' => sanitize_key( $recipe_id ) ], [ '%s' ] );
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_manager_pending' ) ) {
    /**
     * El supervisor solo recoge el proceso despues de un arranque manual.
     *
     * @param array $recipe Receta.
     * @return bool
     */
    function seo_supplier_recipe_totherramienta_manager_pending( $recipe ) {
        if ( ! is_array( $recipe ) || empty( $recipe['id'] ) || ! function_exists( 'seo_supplier_crawl_state' ) ) {
            return false;
        }
        $state  = seo_supplier_crawl_state( $recipe['id'] );
        $status = sanitize_key( (string) ( $state['status'] ?? '' ) );
        return ! empty( $state['manual_started'] )
            && ! empty( $state['enabled'] )
            && in_array( $status, [ 'queued', 'running', 'waiting', 'finalizing' ], true );
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_managed_get' ) ) {
    /**
     * Una sola petición HTTP. Los reintentos y las esperas pertenecen al estado
     * del worker, no a un bucle bloqueante dentro de esta función.
     *
     * @param string $url URL.
     * @param int    $budget Presupuesto orientativo del supervisor.
     * @return array|WP_Error
     */
    function seo_supplier_recipe_totherramienta_managed_get( $url, $budget = 20 ) {
        $timeout = max( 5, min( 20, absint( $budget ) ) );
        $response = wp_safe_remote_get(
            esc_url_raw( $url ),
            [
                'timeout'     => $timeout,
                'redirection' => 4,
                'headers'     => [
                    'Accept'          => 'application/json,*/*;q=0.6',
                    'Accept-Language' => 'es-ES,es;q=0.9,en;q=0.5',
                    'Cache-Control'   => 'no-cache',
                    'User-Agent'      => 'DistribuidorDeHerramientas-SEOSystem/1.2; +' . home_url( '/' ),
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return [
            'status'      => (int) wp_remote_retrieve_response_code( $response ),
            'body'        => (string) wp_remote_retrieve_body( $response ),
            'retry_after' => absint( wp_remote_retrieve_header( $response, 'retry-after' ) ),
            'content_type'=> (string) wp_remote_retrieve_header( $response, 'content-type' ),
        ];
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_stage_rows' ) ) {
    /**
     * Guarda filas normalizadas en el staging común de proveedores.
     *
     * @param array $recipe Receta.
     * @param array $rows Filas estandar.
     * @return int Filas aceptadas.
     */
    function seo_supplier_recipe_totherramienta_stage_rows( array $recipe, array $rows ) {
        if ( ! function_exists( 'seo_supplier_crawl_upsert_record' ) ) {
            return 0;
        }
        $accepted = 0;
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $saved = seo_supplier_crawl_upsert_record( $recipe, $row );
            if ( ! is_wp_error( $saved ) ) {
                $accepted++;
            }
        }
        return $accepted;
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_switch_ucp' ) ) {
    /**
     * Cambia de fuente sin convertir una busqueda UCP en prueba de completitud.
     *
     * Para catalogo completo se intenta primero el feed alternativo
     * /collections/all/products.json. UCP/MCP se reserva para pruebas parciales:
     * search_catalog puede ser paginado, pero sigue siendo una busqueda y no se
     * usa como certificacion del universo completo publicado por la tienda.
     *
     * @param array  $state Estado.
     * @param string $reason Motivo.
     * @return array
     */
    function seo_supplier_recipe_totherramienta_switch_ucp( array $state, $reason ) {
        if ( ! empty( $state['catalog_complete_requested'] ) ) {
            $feed = sanitize_key( (string) ( $state['shopify_feed'] ?? 'products' ) );
            if ( 'collections_all' !== $feed ) {
                $state['phase']                    = 'shopify';
                $state['status']                   = 'waiting';
                $state['shopify_feed']             = 'collections_all';
                $state['shopify_page']             = 1;
                $state['last_page_signature']      = '';
                $state['source_failures']          = 0;
                $state['shopify_fallback_failures']= 0;
                $state['success_streak']           = 0;
                $state['adaptive_delay']           = max( 30, min( 300, absint( $state['adaptive_delay'] ?? 30 ) ) );
                $state['next_attempt_at']          = time() + absint( $state['adaptive_delay'] );
                $state['last_message']             = 'El feed principal no permite continuar con seguridad. Se conserva el staging y se probará /collections/all/products.json desde el inicio. ' . sanitize_text_field( $reason );
                $state['source']                   = 'shopify_collection_all_managed';
                return $state;
            }

            $fallback_failures = absint( $state['shopify_fallback_failures'] ?? 0 ) + 1;
            $state['shopify_fallback_failures'] = $fallback_failures;
            $state = seo_supplier_recipe_totherramienta_managed_delay( $state, 'blocked' );
            if ( $fallback_failures >= 8 ) {
                $state['status']       = 'error';
                $state['enabled']      = false;
                $state['last_error']   = 'Los dos feeds Shopify quedaron bloqueados de forma repetida; no se puede certificar el catalogo completo.';
                $state['last_message'] = $state['last_error'] . ' El staging parcial se conserva y no se ejecutan bajas.';
                return $state;
            }

            $state['phase']           = 'shopify';
            $state['status']          = 'waiting';
            $state['source_failures'] = 0;
            $state['next_attempt_at'] = time() + absint( $state['adaptive_delay'] );
            $state['last_message']    = 'El feed alternativo sigue bloqueado. Se conserva el checkpoint y se reintentará sin importar el catálogo como completo. ' . sanitize_text_field( $reason );
            return $state;
        }

        $state['phase']            = 'ucp';
        $state['status']           = 'running';
        $state['ucp_cursor']       = '';
        $state['ucp_page']         = 1;
        $state['ucp_last_cursor']  = '';
        $state['source_failures']  = 0;
        $state['success_streak']   = 0;
        $state['adaptive_delay']   = max( 15, min( 120, absint( $state['adaptive_delay'] ?? 15 ) ) );
        $state['next_attempt_at']  = time() + 5;
        $state['last_message']     = 'Shopify JSON no permite completar la prueba corta. Se cambia a UCP/MCP paginado como fuente parcial. ' . sanitize_text_field( $reason );
        $state['source']           = 'ucp_mcp_partial';
        return $state;
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_finalize_managed' ) ) {
    /**
     * Genera el CSV desde el staging y lo entrega al importador común. Solo
     * declara catálogo completo cuando la fuente paginada alcanzó su final.
     *
     * @param array $recipe Receta.
     * @param array $state Estado actual.
     * @return array
     */
    function seo_supplier_recipe_totherramienta_finalize_managed( array $recipe, array $state ) {
        if ( ! function_exists( 'seo_supplier_crawl_build_standard_csv' ) || ! function_exists( 'seo_proveedores_importar_csv_estandar' ) ) {
            $state['status']       = 'error';
            $state['enabled']      = false;
            $state['last_error']   = 'El pipeline común de proveedores no está disponible.';
            $state['last_message'] = $state['last_error'];
            return $state;
        }

        $prepared = seo_supplier_crawl_build_standard_csv( $recipe );
        if ( is_wp_error( $prepared ) ) {
            $state['status']       = 'error';
            $state['enabled']      = false;
            $state['last_error']   = $prepared->get_error_message();
            $state['last_message'] = 'No se pudo preparar el CSV final: ' . $prepared->get_error_message();
            return $state;
        }

        $verified_complete = ! empty( $state['catalog_complete_requested'] ) && ! empty( $state['catalog_proven_complete'] );
        $prepared['state']['v2_source']           = sanitize_key( (string) ( $state['source'] ?? 'managed_supplier' ) );
        $prepared['state']['v2_catalog_complete'] = $verified_complete ? 1 : 0;
        $prepared['state']['v2_auto_apply']       = 0;
        $prepared['state']['v2_auto_bajas']       = 0;
        $prepared['state']['v2_image_mode']       = 'external';
        $prepared['state']['v2_force_image_mode'] = 0;
        $prepared['log']['errores']               = 0;
        $prepared['log']['omitidos']              = 0;
        $prepared['log']['detalles'][] = sprintf(
            'Descarga gestionada por worker: %d productos observados, %d referencias normalizadas, %d peticiones.',
            absint( $state['products_seen'] ?? 0 ),
            absint( $state['references_seen'] ?? 0 ),
            absint( $state['requests'] ?? 0 )
        );
        $prepared['log']['detalles'][] = $verified_complete
            ? 'La fuente paginada alcanzó explícitamente el final; catálogo completo verificado sin depender de sitemap.xml.'
            : 'Ejecución parcial: no se habilita detección de bajas.';

        $result = seo_proveedores_importar_csv_estandar( $prepared['state'], $prepared['log'] );
        if ( is_wp_error( $result ) ) {
            $state['status']       = 'error';
            $state['enabled']      = false;
            $state['last_error']   = $result->get_error_message();
            $state['last_message'] = 'Falló la importación del CSV preparado: ' . $result->get_error_message();
            return $state;
        }

        if ( function_exists( 'seo_supplier_crawl_records_table' ) ) {
            global $wpdb;
            $table = seo_supplier_crawl_records_table();
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET imported_hash = record_hash WHERE recipe = %s",
                    $recipe['id']
                )
            );
        }

        $state['status']          = 'completed';
        $state['enabled']         = false;
        $state['completed_at']    = current_time( 'mysql' );
        $state['next_attempt_at'] = 0;
        $state['progress']        = 100;
        $state['last_error']      = '';
        $state['last_csv_filename']= (string) ( $prepared['state']['filename'] ?? '' );
        $state['last_import_created']   = absint( $result['creados'] ?? 0 );
        $state['last_import_updated']   = absint( $result['actualizados'] ?? 0 );
        $state['last_import_unchanged'] = absint( $result['sin_cambios'] ?? 0 );
        $state['last_message'] = sprintf(
            'TotHerramienta finalizado: %d productos observados, %d referencias. Nuevos: %d; actualizados: %d; sin cambios: %d. Catálogo completo: %s.',
            absint( $state['products_seen'] ?? 0 ),
            absint( $state['references_seen'] ?? 0 ),
            absint( $result['creados'] ?? 0 ),
            absint( $result['actualizados'] ?? 0 ),
            absint( $result['sin_cambios'] ?? 0 ),
            $verified_complete ? 'sí' : 'no'
        );
        return $state;
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_manager_slice' ) ) {
    /**
     * Una ventana del worker. Como máximo realiza una petición remota y guarda
     * checkpoint antes de devolver el control al supervisor.
     *
     * @param int    $budget Segundos asignados.
     * @param string $source Motor del supervisor.
     * @param array  $recipe Receta.
     * @return bool True si se realizó trabajo.
     */
    function seo_supplier_recipe_totherramienta_manager_slice( $budget, $source, $recipe ) {
        unset( $source );
        if ( ! is_array( $recipe ) || empty( $recipe['id'] ) ) {
            return false;
        }

        $recipe_id = sanitize_key( $recipe['id'] );
        $state     = seo_supplier_crawl_state( $recipe_id );
        if ( empty( $state['enabled'] ) ) {
            return false;
        }

        $due = absint( $state['next_attempt_at'] ?? 0 );
        if ( $due && $due > time() ) {
            $state['status'] = 'waiting';
            seo_supplier_crawl_store_state( $recipe_id, $state );
            return false;
        }

        if ( 'finalizing' === sanitize_key( (string) ( $state['status'] ?? '' ) ) || 'finalize' === sanitize_key( (string) ( $state['phase'] ?? '' ) ) ) {
            $state = seo_supplier_recipe_totherramienta_finalize_managed( $recipe, $state );
            seo_supplier_crawl_store_state( $recipe_id, $state );
            return true;
        }

        $state['status'] = 'running';
        $state['last_worker_at'] = time();
        $phase = sanitize_key( (string) ( $state['phase'] ?? 'shopify' ) );
        $limit = max( 10, min( 100, absint( $state['page_size'] ?? 50 ) ) );

        if ( 'shopify' === $phase ) {
            $page = max( 1, absint( $state['shopify_page'] ?? 1 ) );
            $feed = sanitize_key( (string) ( $state['shopify_feed'] ?? 'products' ) );
            $feed_path = 'collections_all' === $feed
                ? '/collections/all/products.json'
                : '/products.json';
            $url  = add_query_arg(
                [ 'limit' => $limit, 'page' => $page ],
                seo_supplier_recipe_totherramienta_store_url() . $feed_path
            );
            $response = seo_supplier_recipe_totherramienta_managed_get( $url, $budget );
            $state['requests'] = absint( $state['requests'] ?? 0 ) + 1;
            $state['last_url'] = $url;

            if ( is_wp_error( $response ) ) {
                $state['source_failures'] = absint( $state['source_failures'] ?? 0 ) + 1;
                $state = seo_supplier_recipe_totherramienta_managed_delay( $state, 'network_error' );
                if ( $state['source_failures'] >= 4 && empty( $state['catalog_complete_requested'] ) ) {
                    $state = seo_supplier_recipe_totherramienta_switch_ucp( $state, 'Errores de red repetidos en Shopify JSON.' );
                } else {
                    $state['status']          = 'waiting';
                    $state['next_attempt_at'] = time() + absint( $state['adaptive_delay'] );
                    $state['last_error']      = $response->get_error_message();
                    $state['last_message']    = 'Error de red en Shopify JSON. Se conserva el checkpoint y se reintentará más tarde: ' . $response->get_error_message();
                }
                seo_supplier_crawl_store_state( $recipe_id, $state );
                return true;
            }

            $http = absint( $response['status'] ?? 0 );
            $body = (string) ( $response['body'] ?? '' );
            $state['last_http'] = $http;

            if ( 429 === $http || $http >= 500 ) {
                $state['source_failures'] = absint( $state['source_failures'] ?? 0 ) + 1;
                $challenge = 429 === $http && (
                    false !== stripos( $body, 'enable javascript' )
                    || false !== stripos( $body, 'verifying your connection' )
                    || false !== stripos( $body, 'cloudflare' )
                );
                $state = seo_supplier_recipe_totherramienta_managed_delay(
                    $state,
                    $challenge ? 'blocked' : ( 429 === $http ? 'throttle' : 'server_error' ),
                    absint( $response['retry_after'] ?? 0 )
                );
                if ( $state['source_failures'] >= 4 && ( empty( $state['catalog_complete_requested'] ) || $challenge ) ) {
                    $state = seo_supplier_recipe_totherramienta_switch_ucp( $state, 'Shopify JSON respondió HTTP ' . $http . ( $challenge ? ' con verificación de navegador' : '' ) . ' repetidamente.' );
                } else {
                    $state['status']          = 'waiting';
                    $state['next_attempt_at'] = time() + absint( $state['adaptive_delay'] );
                    $state['last_message']    = 'Shopify JSON respondió HTTP ' . $http . '. Se conserva el checkpoint; pausa adaptativa de ' . absint( $state['adaptive_delay'] ) . ' s.';
                }
                seo_supplier_crawl_store_state( $recipe_id, $state );
                return true;
            }

            if ( in_array( $http, [ 401, 403, 404 ], true ) ) {
                $state = seo_supplier_recipe_totherramienta_managed_delay( $state, 'blocked' );
                $state = seo_supplier_recipe_totherramienta_switch_ucp( $state, $feed_path . ' no está accesible (HTTP ' . $http . ').' );
                seo_supplier_crawl_store_state( $recipe_id, $state );
                return true;
            }

            if ( $http < 200 || $http >= 300 ) {
                $state['source_failures'] = absint( $state['source_failures'] ?? 0 ) + 1;
                $state = seo_supplier_recipe_totherramienta_managed_delay( $state, 'server_error' );
                if ( $state['source_failures'] >= 3 ) {
                    $state = seo_supplier_recipe_totherramienta_switch_ucp( $state, 'Respuesta HTTP no válida en ' . $feed_path . ': ' . $http . '.' );
                } else {
                    $state['next_attempt_at'] = time() + absint( $state['adaptive_delay'] );
                    $state['last_message'] = 'Respuesta HTTP ' . $http . ' en Shopify JSON; se reintentará.';
                }
                seo_supplier_crawl_store_state( $recipe_id, $state );
                return true;
            }

            $decoded = json_decode( $body, true );
            $products = is_array( $decoded ) && isset( $decoded['products'] ) && is_array( $decoded['products'] )
                ? $decoded['products']
                : null;

            if ( null === $products ) {
                $challenge = false !== stripos( $body, 'enable javascript' )
                    || false !== stripos( $body, 'verifying your connection' )
                    || false !== stripos( $body, 'cloudflare' );
                $state['source_failures'] = absint( $state['source_failures'] ?? 0 ) + 1;
                $state = seo_supplier_recipe_totherramienta_managed_delay( $state, $challenge ? 'blocked' : 'server_error' );
                if ( $state['source_failures'] >= 3 ) {
                    $state = seo_supplier_recipe_totherramienta_switch_ucp( $state, $feed_path . ' no devolvió JSON de catálogo válido.' );
                } else {
                    $state['next_attempt_at'] = time() + absint( $state['adaptive_delay'] );
                    $state['last_message'] = $challenge
                        ? 'El proveedor ha activado verificación del navegador. Se reduce el ritmo antes de reintentar.'
                        : 'Shopify JSON devolvió un cuerpo no válido. Se reintentará con pausa.';
                }
                seo_supplier_crawl_store_state( $recipe_id, $state );
                return true;
            }

            if ( empty( $products ) ) {
                if ( 1 === $page ) {
                    $state = seo_supplier_recipe_totherramienta_switch_ucp( $state, $feed_path . ' devolvió cero productos en la primera página.' );
                } else {
                    $state['catalog_proven_complete'] = ! empty( $state['catalog_complete_requested'] ) ? 1 : 0;
                    $state['phase']             = 'finalize';
                    $state['status']            = 'finalizing';
                    $state['next_attempt_at']   = 0;
                    $state['last_message']      = 'Shopify JSON alcanzó una página vacía. La paginación ha terminado y se prepara la importación.';
                }
                seo_supplier_crawl_store_state( $recipe_id, $state );
                if ( function_exists( 'seo_process_supervisor_nudge' ) ) {
                    seo_process_supervisor_nudge( 0, 'supplier_imports' );
                }
                return true;
            }

            $first = is_array( reset( $products ) ) ? reset( $products ) : [];
            $last  = is_array( end( $products ) ) ? end( $products ) : [];
            $sig   = seo_supplier_recipe_totherramienta_product_key( (array) $first ) . '|' . seo_supplier_recipe_totherramienta_product_key( (array) $last ) . '|' . count( $products );
            if ( $page > 1 && '' !== $sig && hash_equals( (string) ( $state['last_page_signature'] ?? '' ), $sig ) ) {
                $state = seo_supplier_recipe_totherramienta_switch_ucp( $state, $feed_path . ' repitió la misma página y no permite continuar la paginación con seguridad.' );
                seo_supplier_crawl_store_state( $recipe_id, $state );
                return true;
            }

            $references = 0;
            foreach ( $products as $product ) {
                if ( ! is_array( $product ) ) {
                    continue;
                }
                $product['_seo_source'] = 'feed';
                $references += seo_supplier_recipe_totherramienta_stage_rows(
                    $recipe,
                    seo_supplier_recipe_totherramienta_shopify_rows( $product )
                );
            }

            $state['products_seen']      = absint( $state['products_seen'] ?? 0 ) + count( $products );
            $state['references_seen']    = absint( $state['references_seen'] ?? 0 ) + $references;
            $state['shopify_page']       = $page + 1;
            $state['last_page_signature']= $sig;
            $state['source_failures']    = 0;
            $state['last_error']         = '';
            $state['source']             = 'collections_all' === $feed ? 'shopify_collection_all_managed' : 'shopify_json_managed';
            $state = seo_supplier_recipe_totherramienta_managed_delay( $state, 'success' );

            if ( empty( $state['catalog_complete_requested'] ) ) {
                $state['phase']           = 'finalize';
                $state['status']          = 'finalizing';
                $state['next_attempt_at'] = 0;
                $state['last_message']    = 'Prueba corta obtenida: ' . count( $products ) . ' productos en una página. Se prepara una importación parcial.';
            } else {
                $state['next_attempt_at'] = time() + absint( $state['adaptive_delay'] );
                $state['last_message'] = sprintf(
                    'Shopify JSON %s página %d: %d productos, %d referencias. Acumulado: %d observaciones. Siguiente petición en %d s.',
                    $feed_path,
                    $page,
                    count( $products ),
                    $references,
                    absint( $state['products_seen'] ),
                    absint( $state['adaptive_delay'] )
                );
            }
            seo_supplier_crawl_store_state( $recipe_id, $state );
            return true;
        }

        if ( 'ucp' === $phase ) {
            $cursor = trim( (string) ( $state['ucp_cursor'] ?? '' ) );
            $page   = max( 1, absint( $state['ucp_page'] ?? 1 ) );
            $catalog = [
                // available=false significa incluir también los no disponibles.
                'filters' => [ 'available' => false ],
                'context' => [
                    'address_country' => 'ES',
                    'language'        => 'es',
                    'currency'        => 'EUR',
                    'intent'          => 'Catalog synchronization for product comparison and resale.',
                ],
                'pagination' => [ 'limit' => min( 250, $limit ) ],
            ];
            if ( '' !== $cursor ) {
                $catalog['pagination']['cursor'] = $cursor;
            }

            $structured = seo_supplier_recipe_totherramienta_ucp_call( 'search_catalog', $catalog, 1000 + $page, max( 5, min( 20, absint( $budget ) ) ) );
            $state['requests'] = absint( $state['requests'] ?? 0 ) + 1;

            if ( is_wp_error( $structured ) ) {
                $message = $structured->get_error_message();
                $throttle = false !== stripos( $message, '429' );
                $server   = preg_match( '/HTTP\s+5\d\d/i', $message );
                $state['source_failures'] = absint( $state['source_failures'] ?? 0 ) + 1;
                $state = seo_supplier_recipe_totherramienta_managed_delay( $state, $throttle ? 'throttle' : ( $server ? 'server_error' : 'network_error' ) );

                if ( $state['source_failures'] >= 8 && ! $throttle && ! $server ) {
                    $state['status']       = 'error';
                    $state['enabled']      = false;
                    $state['last_error']   = $message;
                    $state['last_message'] = 'UCP/MCP no permite continuar después de varios intentos: ' . $message;
                } else {
                    $state['status']          = 'waiting';
                    $state['next_attempt_at'] = time() + absint( $state['adaptive_delay'] );
                    $state['last_error']      = $message;
                    $state['last_message']    = 'UCP/MCP temporalmente no disponible. Se reintentará en ' . absint( $state['adaptive_delay'] ) . ' s. ' . $message;
                }
                seo_supplier_crawl_store_state( $recipe_id, $state );
                return true;
            }

            $products   = array_values( array_filter( (array) ( $structured['products'] ?? [] ), 'is_array' ) );
            $pagination = is_array( $structured['pagination'] ?? null ) ? $structured['pagination'] : [];
            $next_cursor= trim( (string) ( $pagination['cursor'] ?? '' ) );
            $has_next   = array_key_exists( 'has_next_page', $pagination ) ? (bool) $pagination['has_next_page'] : null;
            $total_count= isset( $pagination['total_count'] ) && is_numeric( $pagination['total_count'] ) ? absint( $pagination['total_count'] ) : 0;

            $references = 0;
            foreach ( $products as $product ) {
                $references += seo_supplier_recipe_totherramienta_stage_rows(
                    $recipe,
                    seo_supplier_recipe_totherramienta_product_rows( $product )
                );
            }

            $state['products_seen']   = absint( $state['products_seen'] ?? 0 ) + count( $products );
            $state['references_seen'] = absint( $state['references_seen'] ?? 0 ) + $references;
            $state['ucp_total_count'] = $total_count ?: absint( $state['ucp_total_count'] ?? 0 );
            $state['source_failures'] = 0;
            $state = seo_supplier_recipe_totherramienta_managed_delay( $state, 'success' );

            if ( false === $has_next ) {
                if ( 0 === absint( $state['products_seen'] ?? 0 ) ) {
                    $state['status']       = 'error';
                    $state['enabled']      = false;
                    $state['last_error']   = 'UCP/MCP terminó sin devolver productos.';
                    $state['last_message'] = $state['last_error'];
                } elseif ( ! empty( $state['catalog_complete_requested'] ) ) {
                    $state['status']       = 'error';
                    $state['enabled']      = false;
                    $state['catalog_proven_complete'] = 0;
                    $state['last_error']   = 'UCP/MCP agotó sus resultados, pero search_catalog no se usa como prueba del universo completo de la tienda.';
                    $state['last_message'] = $state['last_error'] . ' El staging se conserva y no se ejecutan bajas.';
                } else {
                    $state['catalog_proven_complete'] = 0;
                    $state['phase']           = 'finalize';
                    $state['status']          = 'finalizing';
                    $state['next_attempt_at'] = 0;
                    $state['last_message']    = 'UCP/MCP indicó has_next_page=false. La prueba parcial terminó y se prepara la importación sin bajas.';
                }
                seo_supplier_crawl_store_state( $recipe_id, $state );
                if ( function_exists( 'seo_process_supervisor_nudge' ) ) {
                    seo_process_supervisor_nudge( 0, 'supplier_imports' );
                }
                return true;
            }

            if ( true === $has_next ) {
                if ( '' === $next_cursor || ( '' !== $cursor && hash_equals( $cursor, $next_cursor ) ) ) {
                    $state['status']       = 'error';
                    $state['enabled']      = false;
                    $state['last_error']   = 'UCP/MCP anunció otra página pero no entregó un cursor nuevo.';
                    $state['last_message'] = $state['last_error'];
                    seo_supplier_crawl_store_state( $recipe_id, $state );
                    return true;
                }
                $state['ucp_last_cursor'] = $cursor;
                $state['ucp_cursor']      = $next_cursor;
                $state['ucp_page']        = $page + 1;
            } else {
                // Sin metadata de paginación no se declara catálogo completo.
                if ( empty( $state['catalog_complete_requested'] ) ) {
                    $state['phase']           = 'finalize';
                    $state['status']          = 'finalizing';
                    $state['next_attempt_at'] = 0;
                    $state['last_message']    = 'UCP/MCP devolvió una página sin metadata de continuidad. Se importa como prueba parcial.';
                    seo_supplier_crawl_store_state( $recipe_id, $state );
                    return true;
                }
                $state['status']       = 'error';
                $state['enabled']      = false;
                $state['last_error']   = 'UCP/MCP no devolvió pagination.has_next_page; no se puede certificar catálogo completo.';
                $state['last_message'] = $state['last_error'];
                seo_supplier_crawl_store_state( $recipe_id, $state );
                return true;
            }

            if ( empty( $state['catalog_complete_requested'] ) ) {
                $state['phase']           = 'finalize';
                $state['status']          = 'finalizing';
                $state['next_attempt_at'] = 0;
                $state['last_message']    = 'Prueba corta UCP/MCP obtenida. Se prepara una importación parcial.';
            } else {
                $state['next_attempt_at'] = time() + absint( $state['adaptive_delay'] );
                if ( $total_count > 0 ) {
                    $state['progress'] = min( 99, (int) floor( 100 * min( $total_count, absint( $state['products_seen'] ) ) / $total_count ) );
                }
                $state['last_message'] = sprintf(
                    'UCP/MCP página %d: %d productos, %d referencias. Acumulado: %d%s. Siguiente petición en %d s.',
                    $page,
                    count( $products ),
                    $references,
                    absint( $state['products_seen'] ),
                    $total_count ? ' de ~' . number_format_i18n( $total_count ) : '',
                    absint( $state['adaptive_delay'] )
                );
            }
            seo_supplier_crawl_store_state( $recipe_id, $state );
            return true;
        }

        $state['status']       = 'error';
        $state['enabled']      = false;
        $state['last_error']   = 'Fase desconocida del proceso de TotHerramienta.';
        $state['last_message'] = $state['last_error'];
        seo_supplier_crawl_store_state( $recipe_id, $state );
        return false;
    }
}

if ( ! function_exists( 'seo_supplier_recipe_totherramienta_start' ) ) {
    /**
     * Inicializa el proceso y devuelve la petición web al administrador. Toda la
     * descarga se realiza después en el Gestor de workers.
     *
     * @param array $recipe Receta.
     * @param bool  $catalog_complete Si el usuario marcó catálogo completo.
     * @return array|WP_Error
     */
    function seo_supplier_recipe_totherramienta_start( $recipe, $catalog_complete = false ) {
        if ( ! is_array( $recipe ) || empty( $recipe['id'] ) ) {
            return new WP_Error( 'totherramienta_recipe_invalid', 'Receta TotHerramienta no válida.' );
        }
        if ( ! function_exists( 'seo_supplier_crawl_state' ) || ! function_exists( 'seo_supplier_crawl_store_state' ) ) {
            return new WP_Error( 'totherramienta_worker_missing', 'El gestor de staging/procesos de proveedores no está cargado.' );
        }

        $recipe_id = sanitize_key( $recipe['id'] );
        $existing  = seo_supplier_crawl_state( $recipe_id );
        if ( seo_supplier_recipe_totherramienta_manager_pending( $recipe ) ) {
            if ( function_exists( 'seo_process_supervisor_nudge' ) ) {
                seo_process_supervisor_nudge( 0, 'supplier_imports' );
            }
            return [
                'message' => 'TotHerramienta ya tiene una importación en curso. El Gestor de workers continuará desde el último checkpoint.',
            ];
        }

        $existing_status = sanitize_key( (string) ( $existing['status'] ?? '' ) );
        $same_mode = ( ! empty( $existing['catalog_complete_requested'] ) ) === (bool) $catalog_complete;
        if ( ! empty( $existing['manual_started'] ) && $same_mode && in_array( $existing_status, [ 'error', 'blocked' ], true ) ) {
            $existing['enabled']                    = true;
            $existing['status']                     = 'queued';
            $existing['next_attempt_at']            = 0;
            $existing['source_failures']            = 0;
            $existing['shopify_fallback_failures']  = 0;
            $existing['last_error']                 = '';
            $existing['last_message']               = 'Importación reanudada desde el último checkpoint conservado. El worker continuará sin vaciar el staging.';
            seo_supplier_crawl_store_state( $recipe_id, $existing );
            if ( function_exists( 'seo_process_supervisor_nudge' ) ) {
                seo_process_supervisor_nudge( 0, 'supplier_imports' );
            }
            return [ 'message' => $existing['last_message'] ];
        }

        seo_supplier_recipe_totherramienta_reset_staging( $recipe_id );

        $state = [
            'manual_started'            => true,
            'enabled'                   => true,
            'status'                    => 'queued',
            'phase'                     => 'shopify',
            'source'                    => 'shopify_json_managed',
            'catalog_complete_requested'=> $catalog_complete ? 1 : 0,
            'catalog_proven_complete'   => 0,
            'page_size'                 => 50,
            'shopify_feed'              => 'products',
            'shopify_page'              => 1,
            'shopify_fallback_failures' => 0,
            'ucp_page'                  => 1,
            'ucp_cursor'                => '',
            'ucp_last_cursor'           => '',
            'products_seen'             => 0,
            'references_seen'           => 0,
            'requests'                  => 0,
            'source_failures'           => 0,
            'adaptive_delay'            => 15,
            'success_streak'            => 0,
            'next_attempt_at'           => 0,
            'progress'                  => 0,
            'started_at'                => current_time( 'mysql' ),
            'run_token'                 => wp_generate_password( 16, false, false ),
            'last_error'                => '',
            'last_message'              => $catalog_complete
                ? 'Importación completa encolada. El worker descargará TotHerramienta en bloques de 50 y guardará un checkpoint tras cada petición.'
                : 'Prueba corta encolada. El worker descargará un bloque de hasta 50 productos.',
        ];
        if ( ! empty( $existing['last_csv_filename'] ) ) {
            $state['previous_csv_filename'] = (string) $existing['last_csv_filename'];
        }
        seo_supplier_crawl_store_state( $recipe_id, $state );

        if ( function_exists( 'seo_process_supervisor_managed_update' ) ) {
            seo_process_supervisor_managed_update(
                'supplier-import-' . $recipe_id,
                [
                    'name'         => 'Importación proveedores · TotHerramienta',
                    'pending'      => 1,
                    'healthy'      => 1,
                    'last_checked' => time(),
                    'last_result'  => 'queued',
                    'last_error'   => '',
                    'detail'       => $state['last_message'],
                ]
            );
        }
        if ( function_exists( 'seo_process_supervisor_nudge' ) ) {
            seo_process_supervisor_nudge( 0, 'supplier_imports' );
        }

        return [
            'message' => $state['last_message'] . ' Puedes seguir el avance en Procesos > Gestor de workers.',
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
            'label'       => 'TotHerramienta - archivo / Shopify JSON',
            'provider'    => 'TOTHERRAMIENTA',
            'version'     => '1.2.0',
            'mode'        => 'mapping',
            'description' => 'Permite cargar manualmente CSV/XLS/XLSX. La obtención automática se ejecuta por lotes en el Gestor de workers, con feeds Shopify paginados; UCP/MCP queda como apoyo para pruebas parciales.',
        ];
        return $recipes;
    }
);

/**
 * Proceso local: aparece en "Obtener catalogo desde la web".
 */
add_filter(
    'seo_supplier_crawl_recipes',
    static function ( $recipes ) {
        if ( ! is_array( $recipes ) ) {
            $recipes = [];
        }
        $recipes['totherramienta_ucp'] = [
            'id'                   => 'totherramienta_ucp',
            'label'                => 'TotHerramienta - Shopify JSON/UCP por worker',
            'provider'             => 'TOTHERRAMIENTA',
            'version'              => '1.2.0',
            'execution'            => 'local_process',
            'start_callback'          => 'seo_supplier_recipe_totherramienta_start',
            'manager_pending_callback'=> 'seo_supplier_recipe_totherramienta_manager_pending',
            'manager_slice_callback'  => 'seo_supplier_recipe_totherramienta_manager_slice',
            'manager_label'           => 'Importación proveedores · TotHerramienta',
            'requires_local_files'    => false,
            'description'             => 'El botón solo encola el proceso. El Gestor de workers descarga el catálogo en bloques de 50, guarda checkpoints y adapta el ritmo ante 429/5xx. Para catálogo completo exige agotar un feed Shopify paginado; UCP/MCP se reserva para pruebas parciales y nunca certifica bajas.',
        ];
        return $recipes;
    }
);
