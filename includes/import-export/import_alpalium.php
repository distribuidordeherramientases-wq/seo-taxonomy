<?php
/**
 * Receta ALPALIUM - indice comercial + enriquecimiento web.
 *
 * Lee el tarifario normalizado por columnas y, cuando existe url_origen,
 * consulta la ficha publica del proveedor para completar nombre, descripcion,
 * categoria, especificaciones e imagenes remotas.
 *
 * IMPORTANTE: el campo "imagenes" conserva URLs remotas. Si el alta final del
 * producto usa un gestor que descarga imagenes localmente, debe adaptarse ese
 * gestor para la estrategia de imagen externa antes de crear productos.
 *
 * @package SEOSystem
 * @subpackage SupplierImports
 * @version 1.5.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'seo_supplier_recipe_alpalium_decimal' ) ) {
    function seo_supplier_recipe_alpalium_decimal( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value ) {
            return '';
        }
        $value = str_replace( [ '€', 'EUR', "\xc2\xa0", ' ' ], '', $value );
        $value = str_replace( ',', '.', $value );
        return is_numeric( $value ) ? number_format( (float) $value, 2, '.', '' ) : '';
    }
}

if ( ! function_exists( 'seo_supplier_recipe_alpalium_text' ) ) {
    function seo_supplier_recipe_alpalium_text( $value ) {
        $value = wp_strip_all_tags( (string) $value );
        return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
    }
}

if ( ! function_exists( 'seo_supplier_recipe_alpalium_minimum' ) ) {
    function seo_supplier_recipe_alpalium_minimum( $value, $presentation = '' ) {
        $raw = seo_supplier_recipe_alpalium_text( $value );
        if ( '' === $raw ) {
            return '';
        }
        if ( preg_match( '/[[:alpha:]áéíóúñü]/iu', $raw ) ) {
            return $raw;
        }
        $numeric = str_replace( ',', '.', $raw );
        if ( ! is_numeric( $numeric ) ) {
            return $raw;
        }
        $number = (float) $numeric;
        $number = ( floor( $number ) === $number )
            ? (string) (int) $number
            : rtrim( rtrim( number_format( $number, 2, '.', '' ), '0' ), '.' );

        $presentation = seo_supplier_recipe_alpalium_text( $presentation );
        if ( false !== stripos( $presentation, 'blister' ) || false !== stripos( $presentation, 'blíster' ) ) {
            return $number . ' blísteres';
        }
        if ( false !== stripos( $presentation, 'box' ) || false !== stripos( $presentation, 'caja' ) ) {
            return $number . ' cajas';
        }
        return $number . ' presentaciones';
    }
}

if ( ! function_exists( 'seo_supplier_recipe_alpalium_absolute_url' ) ) {
    function seo_supplier_recipe_alpalium_absolute_url( $url, $base ) {
        $url = trim( html_entity_decode( (string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( '' === $url || 0 === strpos( $url, 'data:' ) || 0 === strpos( $url, 'javascript:' ) ) {
            return '';
        }
        if ( preg_match( '#^https?://#i', $url ) ) {
            return esc_url_raw( $url );
        }
        $scheme = (string) wp_parse_url( $base, PHP_URL_SCHEME );
        $host   = (string) wp_parse_url( $base, PHP_URL_HOST );
        if ( '' === $scheme || '' === $host ) {
            return '';
        }
        if ( 0 === strpos( $url, '//' ) ) {
            return esc_url_raw( $scheme . ':' . $url );
        }
        if ( 0 === strpos( $url, '/' ) ) {
            return esc_url_raw( $scheme . '://' . $host . $url );
        }
        $path = (string) wp_parse_url( $base, PHP_URL_PATH );
        $dir  = trailingslashit( dirname( $path ) );
        return esc_url_raw( $scheme . '://' . $host . $dir . ltrim( $url, '/' ) );
    }
}

if ( ! function_exists( 'seo_supplier_recipe_alpalium_jsonld_products' ) ) {
    function seo_supplier_recipe_alpalium_jsonld_products( $value, &$products ) {
        if ( ! is_array( $value ) ) {
            return;
        }
        $type = $value['@type'] ?? '';
        $types = is_array( $type ) ? $type : [ $type ];
        foreach ( $types as $one_type ) {
            if ( 'product' === strtolower( (string) $one_type ) ) {
                $products[] = $value;
                break;
            }
        }
        foreach ( $value as $child ) {
            if ( is_array( $child ) ) {
                seo_supplier_recipe_alpalium_jsonld_products( $child, $products );
            }
        }
    }
}

if ( ! function_exists( 'seo_supplier_recipe_alpalium_scrape' ) ) {
    function seo_supplier_recipe_alpalium_scrape( $url ) {
        $empty = [
            'name' => '', 'description' => '', 'brand' => '', 'category' => '',
            'specifications' => [], 'images' => [], 'error' => '',
        ];
        if ( ! preg_match( '#^https?://#i', (string) $url ) ) {
            $empty['error'] = 'URL de origen no válida.';
            return $empty;
        }

        $cache_key = 'seo_alpalium_scrape_' . md5( $url );
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return array_merge( $empty, $cached );
        }

        $response = wp_remote_get(
            $url,
            [
                'timeout'     => 20,
                'redirection' => 5,
                'headers'     => [
                    'User-Agent' => 'Mozilla/5.0 (compatible; DistribuidorDeHerramientas/1.0; +https://www.distribuidordeherramientas.es)',
                    'Accept'     => 'text/html,application/xhtml+xml',
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            $empty['error'] = $response->get_error_message();
            return $empty;
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        $html   = (string) wp_remote_retrieve_body( $response );
        if ( $status < 200 || $status >= 400 || '' === trim( $html ) ) {
            $empty['error'] = 'HTTP ' . $status;
            return $empty;
        }

        if ( ! class_exists( 'DOMDocument' ) ) {
            $empty['error'] = 'DOMDocument no disponible en PHP.';
            return $empty;
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        if ( ! $loaded ) {
            $empty['error'] = 'No se pudo interpretar el HTML.';
            return $empty;
        }
        $xpath = new DOMXPath( $dom );
        $result = $empty;

        // 1) JSON-LD Product: fuente prioritaria.
        $products = [];
        foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) as $script ) {
            $json = trim( (string) $script->textContent );
            if ( '' === $json ) {
                continue;
            }
            $decoded = json_decode( $json, true );
            if ( is_array( $decoded ) ) {
                seo_supplier_recipe_alpalium_jsonld_products( $decoded, $products );
            }
        }
        if ( ! empty( $products ) ) {
            $product = $products[0];
            $result['name'] = seo_supplier_recipe_alpalium_text( $product['name'] ?? '' );
            $result['description'] = trim( wp_kses_post( (string) ( $product['description'] ?? '' ) ) );
            $brand = $product['brand'] ?? '';
            if ( is_array( $brand ) ) {
                $brand = $brand['name'] ?? '';
            }
            $result['brand'] = seo_supplier_recipe_alpalium_text( $brand );
            $result['category'] = seo_supplier_recipe_alpalium_text( $product['category'] ?? '' );

            $json_images = $product['image'] ?? [];
            if ( is_string( $json_images ) ) {
                $json_images = [ $json_images ];
            } elseif ( is_array( $json_images ) && isset( $json_images['url'] ) ) {
                $json_images = [ $json_images['url'] ];
            }
            foreach ( (array) $json_images as $image ) {
                if ( is_array( $image ) ) {
                    $image = $image['url'] ?? $image['contentUrl'] ?? '';
                }
                $absolute = seo_supplier_recipe_alpalium_absolute_url( $image, $url );
                if ( '' !== $absolute ) {
                    $result['images'][] = $absolute;
                }
            }

            foreach ( (array) ( $product['additionalProperty'] ?? [] ) as $property ) {
                if ( ! is_array( $property ) ) {
                    continue;
                }
                $label = seo_supplier_recipe_alpalium_text( $property['name'] ?? '' );
                $value = seo_supplier_recipe_alpalium_text( $property['value'] ?? '' );
                if ( '' !== $label && '' !== $value ) {
                    $result['specifications'][ $label ] = $value;
                }
            }
        }

        // 2) Fallbacks de metadatos y H1.
        if ( '' === $result['name'] ) {
            $nodes = $xpath->query( '//h1[1]' );
            if ( $nodes && $nodes->length ) {
                $result['name'] = seo_supplier_recipe_alpalium_text( $nodes->item( 0 )->textContent );
            }
        }
        if ( '' === $result['name'] ) {
            $nodes = $xpath->query( '//meta[@property="og:title"]/@content' );
            if ( $nodes && $nodes->length ) {
                $result['name'] = seo_supplier_recipe_alpalium_text( $nodes->item( 0 )->nodeValue );
            }
        }
        if ( '' === $result['description'] ) {
            foreach ( [ '//meta[@property="og:description"]/@content', '//meta[@name="description"]/@content' ] as $query ) {
                $nodes = $xpath->query( $query );
                if ( $nodes && $nodes->length ) {
                    $candidate = seo_supplier_recipe_alpalium_text( $nodes->item( 0 )->nodeValue );
                    if ( '' !== $candidate ) {
                        $result['description'] = wpautop( esc_html( $candidate ) );
                        break;
                    }
                }
            }
        }

        // 3) Descripcion visible si la ficha ofrece un bloque reconocible.
        if ( '' === trim( wp_strip_all_tags( $result['description'] ) ) ) {
            $queries = [
                '//*[contains(concat(" ", normalize-space(@class), " "), " product-description ")]',
                '//*[@id="description"]',
                '//*[contains(@class,"description")][1]',
            ];
            foreach ( $queries as $query ) {
                $nodes = $xpath->query( $query );
                if ( ! $nodes || ! $nodes->length ) {
                    continue;
                }
                $node = $nodes->item( 0 );
                $candidate = '';
                foreach ( $node->childNodes as $child ) {
                    $candidate .= $dom->saveHTML( $child );
                }
                $candidate = trim( wp_kses_post( $candidate ) );
                if ( strlen( wp_strip_all_tags( $candidate ) ) >= 40 ) {
                    $result['description'] = $candidate;
                    break;
                }
            }
        }

        // 4) Tablas/dl como especificaciones tecnicas.
        foreach ( $xpath->query( '//table//tr' ) as $tr ) {
            $cells = [];
            foreach ( $tr->childNodes as $child ) {
                if ( XML_ELEMENT_NODE === $child->nodeType && in_array( strtolower( $child->nodeName ), [ 'th', 'td' ], true ) ) {
                    $cells[] = seo_supplier_recipe_alpalium_text( $child->textContent );
                }
            }
            if ( count( $cells ) >= 2 && '' !== $cells[0] && '' !== $cells[1] && strlen( $cells[0] ) <= 120 ) {
                $result['specifications'][ $cells[0] ] = $cells[1];
            }
        }
        foreach ( $xpath->query( '//dl/dt' ) as $dt ) {
            $dd = $dt->nextSibling;
            while ( $dd && ( XML_ELEMENT_NODE !== $dd->nodeType || 'dd' !== strtolower( $dd->nodeName ) ) ) {
                $dd = $dd->nextSibling;
            }
            if ( $dd ) {
                $label = seo_supplier_recipe_alpalium_text( $dt->textContent );
                $value = seo_supplier_recipe_alpalium_text( $dd->textContent );
                if ( '' !== $label && '' !== $value ) {
                    $result['specifications'][ $label ] = $value;
                }
            }
        }

        // 5) Imagen principal y galeria como respaldo. Evita iconos/logos evidentes.
        foreach ( [ '//meta[@property="og:image"]/@content', '//img[@src]/@src', '//img[@data-src]/@data-src', '//img[@data-large-file]/@data-large-file' ] as $query ) {
            foreach ( $xpath->query( $query ) as $node ) {
                $absolute = seo_supplier_recipe_alpalium_absolute_url( $node->nodeValue, $url );
                if ( '' === $absolute || preg_match( '/(?:logo|icon|sprite|avatar|favicon|banner)/i', $absolute ) ) {
                    continue;
                }
                if ( ! preg_match( '/\.(?:jpe?g|png|webp|gif)(?:\?|$)/i', $absolute ) ) {
                    continue;
                }
                $result['images'][] = $absolute;
            }
        }

        $result['images'] = array_values( array_slice( array_unique( array_filter( $result['images'] ) ), 0, 20 ) );
        $result['specifications'] = array_slice( $result['specifications'], 0, 40, true );

        // Cache una semana para no volver a golpear la web en previews/reintentos.
        set_transient( $cache_key, $result, 7 * DAY_IN_SECONDS );
        return $result;
    }
}

if ( ! function_exists( 'seo_supplier_recipe_alpalium_transform' ) ) {
    function seo_supplier_recipe_alpalium_transform( $row, $state = [], $recipe = [] ) {
        unset( $state, $recipe );

        // Compatible con importadores que entregan fila indexada o asociativa.
        if ( array_keys( $row ) !== range( 0, count( $row ) - 1 ) ) {
            $row = array_values( $row );
        }

        $reference = preg_replace( '/\D+/', '', (string) ( $row[0] ?? '' ) );
        if ( '' === $reference || strlen( $reference ) < 6 ) {
            return null;
        }
        $presentation = seo_supplier_recipe_alpalium_text( $row[1] ?? '' );
        if ( '' === $presentation ) {
            return null;
        }
        $packing = seo_supplier_recipe_alpalium_text( $row[2] ?? '' );

        $min_1 = seo_supplier_recipe_alpalium_minimum( $row[3] ?? '', $presentation );
        $price_1 = seo_supplier_recipe_alpalium_decimal( $row[4] ?? '' );
        $price_1_with_vat = '';
        if ( '' !== $price_1 && is_numeric( $price_1 ) ) {
            $price_1_with_vat = number_format( (float) $price_1 * 1.21, 4, '.', '' );
        }
        $min_2 = seo_supplier_recipe_alpalium_minimum( $row[5] ?? '', $presentation );
        $price_2 = seo_supplier_recipe_alpalium_decimal( $row[11] ?? '' );
        $min_3 = seo_supplier_recipe_alpalium_minimum( $row[12] ?? '', $presentation );
        $price_3 = seo_supplier_recipe_alpalium_decimal( $row[19] ?? '' );
        $ean = preg_replace( '/\D+/', '', (string) ( $row[20] ?? '' ) );
        $url = trim( (string) ( $row[21] ?? '' ) );
        if ( ! preg_match( '#^https?://#i', $url ) ) {
            $url = '';
        }

        $web = '' !== $url ? seo_supplier_recipe_alpalium_scrape( $url ) : [
            'name' => '', 'description' => '', 'brand' => '', 'category' => '',
            'specifications' => [], 'images' => [], 'error' => 'Sin URL de producto.',
        ];

        $commercial = [];
        $commercial[] = '<h3>Condiciones comerciales del proveedor</h3>';
        $commercial[] = '<p><strong>Referencia:</strong> ' . esc_html( $reference ) . '</p>';
        $commercial[] = '<p><strong>Presentación:</strong> ' . esc_html( $presentation ) . '</p>';
        if ( '' !== $packing ) {
            $commercial[] = '<p><strong>Embalaje:</strong> ' . esc_html( $packing ) . '</p>';
        }
        if ( '' !== $min_1 ) {
            $commercial[] = '<p><strong>Compra mínima:</strong> ' . esc_html( $min_1 ) . '</p>';
        }
        if ( '' !== $price_1 ) {
            $commercial[] = '<p><strong>Precio neto sin IVA:</strong> ' . esc_html( $price_1 ) . ' EUR</p>';
        }
        if ( '' !== $min_2 && '' !== $price_2 ) {
            $commercial[] = '<p><strong>Segundo tramo:</strong> desde ' . esc_html( $min_2 ) . ' → ' . esc_html( $price_2 ) . ' EUR</p>';
        }
        if ( '' !== $min_3 && '' !== $price_3 ) {
            $commercial[] = '<p><strong>Tercer tramo:</strong> desde ' . esc_html( $min_3 ) . ' → ' . esc_html( $price_3 ) . ' EUR</p>';
        }
        if ( '' !== $ean ) {
            $commercial[] = '<p><strong>EAN:</strong> ' . esc_html( $ean ) . '</p>';
        }

        $spec_html = '';
        if ( ! empty( $web['specifications'] ) ) {
            $spec_html .= '<h3>Especificaciones</h3><ul>';
            foreach ( $web['specifications'] as $label => $value ) {
                $spec_html .= '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . '</li>';
            }
            $spec_html .= '</ul>';
        }

        $description = trim( (string) ( $web['description'] ?? '' ) );
        if ( '' !== $spec_html ) {
            $description .= "\n" . $spec_html;
        }
        $description .= "\n" . implode( "\n", $commercial );
        if ( ! empty( $web['error'] ) ) {
            $description .= "\n<p><em>Enriquecimiento web pendiente: " . esc_html( $web['error'] ) . '</em></p>';
        }

        return [
            'proveedor_id_externo' => $reference,
            'sku'                   => $reference,
            'mpn'                   => $ean,
            'url_origen'            => $url,
            'url_canonica'          => $url,
            'nombre'                => '' !== (string) ( $web['name'] ?? '' ) ? $web['name'] : 'Alpalium ' . $reference,
            'descripcion'           => $description,
            'marca'                 => '' !== (string) ( $web['brand'] ?? '' ) ? $web['brand'] : 'Alpalium',
            'categoria_proveedor'   => (string) ( $web['category'] ?? '' ),
            'precio_sin_iva'        => $price_1,
            'precio_con_iva'        => $price_1_with_vat,
            'iva_porcentaje'        => '21',
            'moneda'                => 'EUR',
            'stock_estado'          => '',
            'stock_cantidad'        => '',
            'stock_texto'           => '' !== $min_1 ? 'Compra mínima proveedor: ' . $min_1 : '',
            'imagenes'              => implode( "\n", (array) ( $web['images'] ?? [] ) ),
        ];
    }
}

add_filter(
    'seo_proveedores_import_recipes',
    static function ( $recipes ) {
        $recipes['alpalium'] = [
            'id'                 => 'alpalium',
            'label'              => 'Alpalium 2026 - comercial + scraping + IVA 21%',
            'provider'           => 'ALPALIUM',
            'version'            => '1.5.0',
            'mode'               => 'transform',
            'indexed_rows'       => true,
            'description'        => 'Lee SKU, EAN, precio y mínimos del tarifario y visita la ficha web para mapear título, descripción, especificaciones, categoría e imágenes remotas.',
            'required_headers'   => [],
            'transform_callback' => 'seo_supplier_recipe_alpalium_transform',
            'relations'          => [
                [ 'source' => 'Columna A', 'target' => 'proveedor_id_externo', 'operation' => 'Conservar la referencia Alpalium como identificador externo.' ],
                [ 'source' => 'Columna A', 'target' => 'sku', 'operation' => 'Usar la referencia Alpalium como SKU.' ],
                [ 'source' => 'Columna E', 'target' => 'precio_sin_iva', 'operation' => 'Primer precio neto sin IVA aplicable al pedido mínimo. La receta calcula además el precio con IVA sumando un 21%.' ],
                [ 'source' => 'Columna D', 'target' => 'stock_texto', 'operation' => 'Conservar el mínimo inicial de compra como condición comercial; no representa stock real.' ],
                [ 'source' => 'Columnas B/C/F-L/M-T + ficha web', 'target' => 'descripcion', 'operation' => 'Combinar descripción obtenida de la ficha con presentación, embalaje, mínimos y escalones de precio.' ],
                [ 'source' => 'Columna U', 'target' => 'mpn', 'operation' => 'Conservar temporalmente el EAN en MPN hasta disponer de campo EAN dedicado.' ],
                [ 'source' => 'Hipervínculo de columna V', 'target' => 'url_origen', 'operation' => 'Extraer la URL real del XLSX y usarla para visitar la ficha.' ],
                [ 'source' => 'Ficha web', 'target' => 'nombre', 'operation' => 'Obtener título desde JSON-LD, H1 u Open Graph.' ],
                [ 'source' => 'Ficha web', 'target' => 'marca', 'operation' => 'Obtener marca estructurada; usar Alpalium como respaldo.' ],
                [ 'source' => 'Ficha web', 'target' => 'categoria_proveedor', 'operation' => 'Obtener categoría del producto cuando la ficha la exponga.' ],
                [ 'source' => 'Ficha web', 'target' => 'imagenes', 'operation' => 'Recolectar hasta 20 URLs de imágenes remotas, sin descargarlas al hosting.' ],
            ],
        ];
        return $recipes;
    }
);
