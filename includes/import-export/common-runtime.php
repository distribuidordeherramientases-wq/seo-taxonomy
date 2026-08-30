<?php
/**
 * Runtime comun para recetas web de proveedores de SEO System.
 *
 * Centraliza la normalizacion, clasificacion y parseo compartidos por las
 * recetas web. Las recetas conservan unicamente su configuracion especifica.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SEO_Supplier_Web_Recipe_Runtime' ) ) {
    final class SEO_Supplier_Web_Recipe_Runtime {
        public static function text( $value ) {
            $value = wp_strip_all_tags( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
            return trim( (string) preg_replace( '/\\s+/u', ' ', $value ) );
        }

        private static function lower( $value ) {
            $value = self::text( $value );
            if ( function_exists( 'remove_accents' ) ) {
                $value = remove_accents( $value );
            }
            return strtolower( $value );
        }

        private static function absolute_url( $href, $base_url ) {
            $href = trim( html_entity_decode( (string) $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
            if ( '' === $href || 0 === strpos( $href, '#' ) || preg_match( '#^(?:javascript:|mailto:|tel:|data:)#i', $href ) ) {
                return '';
            }
            if ( preg_match( '#^https?://#i', $href ) ) {
                return $href;
            }
            $base = wp_parse_url( (string) $base_url );
            if ( ! is_array( $base ) || empty( $base['host'] ) ) {
                return '';
            }
            $scheme = $base['scheme'] ?? 'https';
            if ( 0 === strpos( $href, '//' ) ) {
                return $scheme . ':' . $href;
            }
            $origin = $scheme . '://' . $base['host'] . ( ! empty( $base['port'] ) ? ':' . absint( $base['port'] ) : '' );
            if ( 0 === strpos( $href, '/' ) ) {
                return $origin . $href;
            }
            $path = (string) ( $base['path'] ?? '/' );
            $dir  = preg_replace( '#/[^/]*$#', '/', $path );
            return $origin . $dir . $href;
        }

        public static function canonicalize( $url, $recipe = [] ) {
            $url = self::absolute_url( $url, $recipe['base_url'] ?? '' );
            if ( '' === $url ) {
                return '';
            }
            $parts = wp_parse_url( $url );
            if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
                return '';
            }
            $host = strtolower( (string) $parts['host'] );
            if ( ! in_array( $host, (array) ( $recipe['allowed_hosts'] ?? [] ), true ) ) {
                return '';
            }
            $base = wp_parse_url( (string) ( $recipe['base_url'] ?? '' ) );
            $canonical_host = ! empty( $base['host'] ) ? strtolower( (string) $base['host'] ) : $host;
            $path = '/' . ltrim( (string) ( $parts['path'] ?? '/' ), '/' );
            $path = preg_replace( '#/{2,}#', '/', $path );
            if ( '/' !== $path ) {
                $path = rtrim( $path, '/' );
            }
            $query_out = [];
            if ( ! empty( $parts['query'] ) ) {
                parse_str( (string) $parts['query'], $query );
                foreach ( (array) ( $recipe['keep_query'] ?? [] ) as $key ) {
                    if ( isset( $query[ $key ] ) && is_scalar( $query[ $key ] ) && '' !== (string) $query[ $key ] ) {
                        $query_out[ $key ] = (string) $query[ $key ];
                    }
                }
            }
            $result = 'https://' . $canonical_host . $path;
            if ( ! empty( $query_out ) ) {
                $result .= '?' . http_build_query( $query_out, '', '&', PHP_QUERY_RFC3986 );
            }
            return esc_url_raw( $result );
        }

        private static function ignored_path( $path ) {
            foreach ( [
                '/blog/', '/contact', '/contacto', '/login', '/signin', '/registro', '/register', '/account', '/cuenta',
                '/cart', '/carrito', '/checkout', '/pedido', '/wishlist', '/favoritos', '/privacy', '/privacidad', '/cookies',
                '/aviso-legal', '/legal/', '/terms', '/condiciones', '/newsletter', '/empleo', '/jobs', '/media/', '/assets/'
            ] as $needle ) {
                if ( false !== strpos( strtolower( $path ), $needle ) ) {
                    return true;
                }
            }
            return false;
        }

        public static function classify( $url, $recipe = [] ) {
            $url = self::canonicalize( $url, $recipe );
            if ( '' === $url ) {
                return 'ignore';
            }
            $parts = wp_parse_url( $url );
            $path  = (string) ( $parts['path'] ?? '/' );
            if ( self::ignored_path( $path ) ) {
                return 'ignore';
            }
            foreach ( (array) ( $recipe['product_patterns'] ?? [] ) as $pattern ) {
                if ( @preg_match( $pattern, $path ) ) {
                    return 'product';
                }
            }
            foreach ( (array) ( $recipe['category_patterns'] ?? [] ) as $pattern ) {
                if ( @preg_match( $pattern, $path ) ) {
                    return 'category';
                }
            }
            foreach ( (array) ( $recipe['category_prefixes'] ?? [] ) as $prefix ) {
                if ( 0 === strpos( $path, $prefix ) ) {
                    return 'category';
                }
            }
            if ( ! empty( $recipe['sitewide_catalog'] ) ) {
                foreach ( (array) ( $recipe['sitewide_prefixes'] ?? [ '/' ] ) as $prefix ) {
                    if ( 0 === strpos( $path, $prefix ) ) {
                        return 'category';
                    }
                }
            }
            return 'ignore';
        }

        private static function external_id( $url, $recipe, $sku = '', $mpn = '' ) {
            if ( '' !== trim( (string) $sku ) ) {
                return trim( (string) $sku );
            }
            if ( '' !== trim( (string) $mpn ) ) {
                return trim( (string) $mpn );
            }
            $path = (string) wp_parse_url( $url, PHP_URL_PATH );
            foreach ( (array) ( $recipe['external_id_patterns'] ?? [] ) as $pattern ) {
                if ( preg_match( $pattern, $path, $match ) && ! empty( $match[1] ) ) {
                    return sanitize_text_field( (string) $match[1] );
                }
            }
            return substr( hash( 'sha256', (string) $url ), 0, 24 );
        }

        private static function price_number( $value ) {
            if ( is_int( $value ) || is_float( $value ) ) {
                return (float) $value;
            }
            $value = self::text( $value );
            if ( '' === $value ) {
                return null;
            }
            $value = preg_replace( '/[^0-9,.-]/', '', $value );
            if ( false !== strpos( $value, ',' ) && false !== strpos( $value, '.' ) ) {
                if ( strrpos( $value, ',' ) > strrpos( $value, '.' ) ) {
                    $value = str_replace( '.', '', $value );
                    $value = str_replace( ',', '.', $value );
                } else {
                    $value = str_replace( ',', '', $value );
                }
            } elseif ( false !== strpos( $value, ',' ) ) {
                $value = str_replace( ',', '.', $value );
            }
            return is_numeric( $value ) ? (float) $value : null;
        }

        private static function price_fields( $price, $vat ) {
            $number = self::price_number( $price );
            if ( null === $number || $number < 0 ) {
                return [ '', '' ];
            }
            $with = number_format( $number, 2, '.', '' );
            $without = $vat > 0 ? number_format( $number / ( 1 + ( $vat / 100 ) ), 2, '.', '' ) : $with;
            return [ $without, $with ];
        }

        private static function collect_products( $node, &$products ) {
            if ( ! is_array( $node ) ) {
                return;
            }
            $type = $node['@type'] ?? '';
            $types = is_array( $type ) ? $type : [ $type ];
            foreach ( $types as $candidate ) {
                if ( 'product' === strtolower( (string) $candidate ) ) {
                    $products[] = $node;
                    break;
                }
            }
            foreach ( $node as $value ) {
                if ( is_array( $value ) ) {
                    self::collect_products( $value, $products );
                }
            }
        }

        private static function product_offer( $product ) {
            $offers = $product['offers'] ?? [];
            if ( isset( $offers['@type'] ) || isset( $offers['price'] ) || isset( $offers['lowPrice'] ) ) {
                return $offers;
            }
            foreach ( (array) $offers as $offer ) {
                if ( is_array( $offer ) && ( isset( $offer['price'] ) || isset( $offer['lowPrice'] ) ) ) {
                    return $offer;
                }
            }
            return is_array( $offers ) ? $offers : [];
        }

        private static function seller_from_offer( $offer ) {
            foreach ( [ 'seller', 'merchant' ] as $key ) {
                if ( empty( $offer[ $key ] ) ) {
                    continue;
                }
                $seller = $offer[ $key ];
                if ( is_array( $seller ) ) {
                    $seller = $seller['name'] ?? ( $seller['legalName'] ?? '' );
                }
                $seller = self::text( $seller );
                if ( '' !== $seller ) {
                    return $seller;
                }
            }
            return '';
        }

        private static function primary_seller( $product, $html ) {
            $offer = self::product_offer( $product );
            $seller = self::seller_from_offer( $offer );
            if ( '' !== $seller ) {
                return $seller;
            }
            $text = self::text( $html );
            if ( preg_match( '/Vendido\\s+por\\s+(.{2,70}?)(?=\\s+(?:Envio|Entrega|Ver disponibilidad|[0-9]+\\s+Otras? ofertas?|Anadir|Devolucion|$))/iu', $text, $match ) ) {
                return self::text( $match[1] );
            }
            return '';
        }

        private static function seller_allowed( $seller, $recipe ) {
            if ( empty( $recipe['seller_required'] ) ) {
                return true;
            }
            $seller = self::lower( $seller );
            if ( '' === $seller ) {
                return false;
            }
            foreach ( (array) ( $recipe['seller_names'] ?? [] ) as $allowed ) {
                $allowed = self::lower( $allowed );
                if ( '' !== $allowed && ( $seller === $allowed || false !== strpos( $seller, $allowed ) ) ) {
                    return true;
                }
            }
            return false;
        }

        private static function xpath_text( $xpath, $query, $context = null ) {
            $nodes = $xpath->query( $query, $context );
            return $nodes && $nodes->length ? self::text( $nodes->item( 0 )->textContent ) : '';
        }

        private static function xpath_attr( $xpath, $query, $attr, $context = null ) {
            $nodes = $xpath->query( $query, $context );
            if ( ! $nodes || ! $nodes->length || ! $nodes->item( 0 )->attributes ) {
                return '';
            }
            $node = $nodes->item( 0 )->attributes->getNamedItem( $attr );
            return $node ? trim( (string) $node->nodeValue ) : '';
        }

        private static function inner_html( $dom, $node ) {
            $html = '';
            if ( ! $node ) {
                return $html;
            }
            foreach ( $node->childNodes as $child ) {
                $html .= $dom->saveHTML( $child );
            }
            return trim( $html );
        }

        private static function breadcrumb( $xpath ) {
            $nodes = $xpath->query( '//*[contains(concat(" ",normalize-space(@class)," ")," breadcrumb ")]//a | //nav[contains(translate(@aria-label,"BREADCRUMB","breadcrumb"),"breadcrumb")]//a' );
            $parts = [];
            if ( $nodes ) {
                foreach ( $nodes as $node ) {
                    $text = self::text( $node->textContent );
                    if ( '' !== $text && ! in_array( strtolower( $text ), [ 'inicio', 'home' ], true ) ) {
                        $parts[] = $text;
                    }
                }
            }
            return implode( ' > ', array_values( array_unique( $parts ) ) );
        }

        private static function product_record( $dom, $xpath, $product, $url, $recipe, $html ) {
            $offer  = self::product_offer( $product );
            $seller = self::primary_seller( $product, $html );
            if ( ! self::seller_allowed( $seller, $recipe ) ) {
                return null;
            }
            $canonical = self::canonicalize( $product['url'] ?? $url, $recipe );
            if ( '' === $canonical ) {
                $canonical = self::canonicalize( $url, $recipe );
            }
            $name = self::text( $product['name'] ?? '' );
            if ( '' === $name ) {
                $name = self::xpath_text( $xpath, '//h1[1]' );
            }
            if ( '' === $canonical || '' === $name ) {
                return null;
            }
            $sku = self::text( $product['sku'] ?? ( $product['productID'] ?? '' ) );
            $mpn = self::text( $product['mpn'] ?? ( $product['model'] ?? '' ) );
            $brand = $product['brand'] ?? '';
            if ( is_array( $brand ) ) {
                $brand = $brand['name'] ?? '';
            }
            $brand = self::text( $brand );
            if ( '' === $brand ) {
                $brand = self::xpath_attr( $xpath, '//meta[@itemprop="brand"]', 'content' );
            }
            $description = trim( wp_kses_post( (string) ( $product['description'] ?? '' ) ) );
            if ( strlen( self::text( $description ) ) < 30 ) {
                foreach ( [
                    '//*[@id="description"]',
                    '//*[contains(concat(" ",normalize-space(@class)," ")," product-description ")][1]',
                    '//*[contains(concat(" ",normalize-space(@class)," ")," description ")][1]',
                    '//*[contains(concat(" ",normalize-space(@class)," ")," product-detail ")][1]'
                ] as $query ) {
                    $nodes = $xpath->query( $query );
                    if ( $nodes && $nodes->length ) {
                        $candidate = self::inner_html( $dom, $nodes->item( 0 ) );
                        if ( strlen( self::text( $candidate ) ) >= 30 ) {
                            $description = wp_kses_post( $candidate );
                            break;
                        }
                    }
                }
            }
            $category = self::text( $product['category'] ?? '' );
            if ( '' === $category ) {
                $category = self::breadcrumb( $xpath );
            }
            $price = $offer['price'] ?? ( $offer['lowPrice'] ?? '' );
            if ( '' === (string) $price ) {
                $price = self::xpath_attr( $xpath, '//*[@itemprop="price"][1]', 'content' );
            }
            if ( '' === (string) $price ) {
                $price = self::xpath_attr( $xpath, '//meta[@property="product:price:amount"][1]', 'content' );
            }
            [ $without_vat, $with_vat ] = self::price_fields( $price, absint( $recipe['vat_percent'] ?? 21 ) );
            $currency = self::text( $offer['priceCurrency'] ?? '' );
            if ( '' === $currency ) {
                $currency = self::xpath_attr( $xpath, '//meta[@property="product:price:currency"][1]', 'content' );
            }
            $availability = self::lower( $offer['availability'] ?? '' );
            $stock_state = '';
            $stock_text  = '';
            if ( false !== strpos( $availability, 'outofstock' ) || false !== strpos( $availability, 'agotado' ) ) {
                $stock_state = 'outofstock';
                $stock_text  = 'Agotado';
            } elseif ( false !== strpos( $availability, 'instock' ) || false !== strpos( $availability, 'disponible' ) ) {
                $stock_state = 'instock';
                $stock_text  = 'Disponible';
            } else {
                $body_text = self::lower( $html );
                if ( false !== strpos( $body_text, 'sin stock' ) || false !== strpos( $body_text, 'agotado' ) ) {
                    $stock_state = 'outofstock';
                    $stock_text  = 'Agotado';
                } elseif ( false !== strpos( $body_text, 'disponible online' ) || false !== strpos( $body_text, 'en stock' ) || false !== strpos( $body_text, 'anadir al carrito' ) ) {
                    $stock_state = 'instock';
                    $stock_text  = 'Disponible';
                }
            }
            $images = [];
            $image_data = $product['image'] ?? [];
            if ( is_string( $image_data ) ) {
                $image_data = [ $image_data ];
            } elseif ( is_array( $image_data ) && isset( $image_data['url'] ) ) {
                $image_data = [ $image_data['url'] ];
            }
            foreach ( (array) $image_data as $image ) {
                if ( is_array( $image ) ) {
                    $image = $image['url'] ?? ( $image['contentUrl'] ?? '' );
                }
                $image = esc_url_raw( (string) $image );
                if ( preg_match( '#^https?://#i', $image ) ) {
                    $images[] = $image;
                }
            }
            if ( empty( $images ) ) {
                $og = self::xpath_attr( $xpath, '//meta[@property="og:image"][1]', 'content' );
                if ( preg_match( '#^https?://#i', $og ) ) {
                    $images[] = esc_url_raw( $og );
                }
            }
            $properties = [];
            foreach ( (array) ( $product['additionalProperty'] ?? [] ) as $property ) {
                if ( is_array( $property ) ) {
                    $key = self::text( $property['name'] ?? '' );
                    $val = self::text( $property['value'] ?? '' );
                    if ( '' !== $key && '' !== $val ) {
                        $properties[ $key ] = $val;
                    }
                }
            }
            if ( ! empty( $properties ) ) {
                $description .= "\\n<h3>Especificaciones publicas</h3><ul>";
                foreach ( array_slice( $properties, 0, 40, true ) as $key => $val ) {
                    $description .= '<li><strong>' . esc_html( $key ) . ':</strong> ' . esc_html( $val ) . '</li>';
                }
                $description .= '</ul>';
            }
            return [
                'proveedor_id_externo' => self::external_id( $canonical, $recipe, $sku, $mpn ),
                'sku'                   => $sku,
                'mpn'                   => $mpn,
                'url_origen'            => $canonical,
                'url_canonica'          => $canonical,
                'nombre'                => $name,
                'descripcion'           => $description,
                'marca'                 => $brand,
                'categoria_proveedor'   => $category,
                'precio_sin_iva'        => $without_vat,
                'precio_con_iva'        => $with_vat,
                'iva_porcentaje'        => (string) absint( $recipe['vat_percent'] ?? 21 ),
                'moneda'                => $currency ?: 'EUR',
                'stock_estado'          => $stock_state,
                'stock_cantidad'        => '',
                'stock_texto'           => $stock_text,
                'imagenes'              => implode( "\\n", array_values( array_unique( $images ) ) ),
            ];
        }

        private static function ancestor_context( $node ) {
            $current = $node;
            $best = '';
            for ( $i = 0; $i < 6 && $current; $i++ ) {
                $text = self::text( $current->textContent );
                if ( strlen( $text ) > strlen( $best ) && strlen( $text ) <= 1800 ) {
                    $best = $text;
                }
                if ( preg_match( '/(?:EUR|EUR|\\x{20AC})/u', $text ) && ( false !== stripos( $text, 'Anadir' ) || false !== stripos( $text, 'Vendido por' ) || false !== stripos( $text, 'Disponible' ) ) ) {
                    return $text;
                }
                $current = $current->parentNode;
            }
            return $best;
        }

        private static function context_seller( $context ) {
            $context = self::text( $context );
            if ( preg_match( '/Vendido\\s+por\\s+(.{2,70}?)(?=\\s+(?:Envio|Entrega|Ver disponibilidad|[0-9]+\\s+Otras? ofertas?|Anadir|Devolucion|$))/iu', $context, $match ) ) {
                return self::text( $match[1] );
            }
            return '';
        }

        private static function context_price( $context ) {
            $context = self::text( $context );
            if ( preg_match_all( '/([0-9]{1,5}(?:[.,][0-9]{1,2})?)\\s*(?:EUR|\\x{20AC})/u', $context, $matches ) && ! empty( $matches[1] ) ) {
                return end( $matches[1] );
            }
            return '';
        }

        private static function keyword_match( $text, $recipe ) {
            $text = self::lower( $text );
            foreach ( (array) ( $recipe['category_keywords'] ?? [] ) as $keyword ) {
                $keyword = self::lower( $keyword );
                if ( '' !== $keyword && false !== strpos( $text, $keyword ) ) {
                    return true;
                }
            }
            return false;
        }

        public static function parse_page( $html, $url, $recipe, $job = [] ) {
            unset( $job );
            if ( ! class_exists( 'DOMDocument' ) ) {
                return new WP_Error( 'supplier_web_dom_missing', 'DOMDocument no esta disponible en PHP.' );
            }
            $dom = new DOMDocument();
            $previous = libxml_use_internal_errors( true );
            $loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . (string) $html, LIBXML_NOWARNING | LIBXML_NOERROR );
            libxml_clear_errors();
            libxml_use_internal_errors( $previous );
            if ( ! $loaded ) {
                return new WP_Error( 'supplier_web_html_invalid', 'No se pudo interpretar el HTML del proveedor.' );
            }
            $xpath = new DOMXPath( $dom );
            $records = [];
            $enqueue = [];
            $products = [];
            foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) as $script ) {
                $decoded = json_decode( trim( (string) $script->textContent ), true );
                if ( is_array( $decoded ) ) {
                    self::collect_products( $decoded, $products );
                }
            }
            $current_type = self::classify( $url, $recipe );
            if ( ! empty( $products ) || 'product' === $current_type ) {
                if ( empty( $products ) ) {
                    $products[] = [];
                }
                foreach ( $products as $product ) {
                    $record = self::product_record( $dom, $xpath, $product, $url, $recipe, $html );
                    if ( is_array( $record ) && ! empty( $record['nombre'] ) ) {
                        $records[] = $record;
                        break;
                    }
                }
                return [
                    'type'    => 'product',
                    'records' => $records,
                    'enqueue' => [],
                    'message' => ! empty( $records ) ? 'Ficha de producto enriquecida.' : 'Ficha leida pero no coincide con los filtros de esta receta.',
                ];
            }

            $category = self::xpath_text( $xpath, '//h1[1]' );
            $seen_products = [];
            $seen_queue = [];
            $links = $xpath->query( '//a[@href]' );
            if ( $links ) {
                foreach ( $links as $link ) {
                    $href = trim( (string) $link->getAttribute( 'href' ) );
                    $candidate = self::canonicalize( $href, $recipe );
                    if ( '' === $candidate || isset( $seen_queue[ $candidate ] ) ) {
                        continue;
                    }
                    $label = self::text( $link->getAttribute( 'aria-label' ) );
                    if ( '' === $label ) {
                        $label = self::text( $link->getAttribute( 'title' ) );
                    }
                    if ( '' === $label ) {
                        $label = self::text( $link->textContent );
                    }
                    $kind = self::classify( $candidate, $recipe );
                    $context = '';
                    if ( 'product' !== $kind && ! empty( $recipe['product_card_heuristic'] ) ) {
                        $context = self::ancestor_context( $link );
                        if ( '' !== $label && strlen( $label ) >= 4 && preg_match( '/(?:EUR|\\x{20AC})/u', $context ) && ( false !== stripos( $context, 'Anadir' ) || false !== stripos( $context, 'Vendido por' ) ) ) {
                            $kind = 'product';
                        }
                    }
                    if ( 'product' === $kind ) {
                        if ( isset( $seen_products[ $candidate ] ) ) {
                            continue;
                        }
                        $seen_products[ $candidate ] = true;
                        if ( '' === $context ) {
                            $context = self::ancestor_context( $link );
                        }
                        $seller = self::context_seller( $context );
                        $seller_ok = self::seller_allowed( $seller, $recipe );
                        if ( empty( $recipe['seller_required'] ) || $seller_ok ) {
                            $price = self::context_price( $context );
                            [ $without_vat, $with_vat ] = self::price_fields( $price, absint( $recipe['vat_percent'] ?? 21 ) );
                            if ( '' !== $label ) {
                                $records[] = [
                                    'proveedor_id_externo' => self::external_id( $candidate, $recipe ),
                                    'sku'                   => '',
                                    'mpn'                   => '',
                                    'url_origen'            => $candidate,
                                    'url_canonica'          => $candidate,
                                    'nombre'                => $label,
                                    'descripcion'           => '',
                                    'marca'                 => '',
                                    'categoria_proveedor'   => $category,
                                    'precio_sin_iva'        => $without_vat,
                                    'precio_con_iva'        => $with_vat,
                                    'iva_porcentaje'        => (string) absint( $recipe['vat_percent'] ?? 21 ),
                                    'moneda'                => 'EUR',
                                    'stock_estado'          => '',
                                    'stock_cantidad'        => '',
                                    'stock_texto'           => '',
                                    'imagenes'              => '',
                                ];
                            }
                        }
                        $enqueue[] = [ 'url' => $candidate, 'type' => 'product', 'priority' => 50, 'source' => $url ];
                        $seen_queue[ $candidate ] = true;
                        continue;
                    }
                    if ( 'category' === $kind ) {
                        if ( ! empty( $recipe['category_keywords'] ) && empty( $recipe['category_prefix_strict'] ) && ! self::keyword_match( $label . ' ' . $candidate, $recipe ) ) {
                            continue;
                        }
                        $enqueue[] = [ 'url' => $candidate, 'type' => 'category', 'priority' => 20, 'source' => $url ];
                        $seen_queue[ $candidate ] = true;
                        continue;
                    }
                    if ( 'ignore' === $kind && self::keyword_match( $label, $recipe ) ) {
                        $path = (string) wp_parse_url( $candidate, PHP_URL_PATH );
                        foreach ( (array) ( $recipe['keyword_category_prefixes'] ?? [] ) as $prefix ) {
                            if ( 0 === strpos( $path, $prefix ) ) {
                                $enqueue[] = [ 'url' => $candidate, 'type' => 'category', 'priority' => 25, 'source' => $url ];
                                $seen_queue[ $candidate ] = true;
                                break;
                            }
                        }
                    }
                    if ( count( $enqueue ) >= absint( $recipe['max_enqueue_per_page'] ?? 400 ) ) {
                        break;
                    }
                }
            }
            foreach ( $xpath->query( '//a[@rel="next"][@href]' ) as $next ) {
                $next_url = self::canonicalize( $next->getAttribute( 'href' ), $recipe );
                if ( '' !== $next_url && ! isset( $seen_queue[ $next_url ] ) ) {
                    $enqueue[] = [ 'url' => $next_url, 'type' => 'category', 'priority' => 15, 'source' => $url ];
                    $seen_queue[ $next_url ] = true;
                }
            }
            return [
                'type'    => 'category',
                'records' => $records,
                'enqueue' => $enqueue,
                'message' => sprintf( 'Pagina leida: %d productos visibles y %d URLs nuevas candidatas.', count( $records ), count( $enqueue ) ),
            ];
        }
    }
}
