<?php
/**
 * Receta web RUBIX para SEO System.
 *
 * Objetivo:
 * - Descubrir solo familias relevantes del catalogo publico Rubix Espana.
 * - Guardar los hallazgos en staging de crawler-queue.php.
 * - Generar el CSV estandar interno del proveedor.
 * - Entregarlo al catalogo intermedio de proveedores, donde el usuario decide
 *   mediante estados que productos interesan. Nunca publica en WooCommerce.
 *
 * Ramas iniciales:
 * - Herramientas y metrologia (c-35)
 * - Equipamiento (c-45)
 * - Mantenimiento y reparacion (c-60)
 * - Soldadura (c-90)
 *
 * @package SEOSystem
 * @subpackage SupplierImports
 * @version 0.1.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SEO_Supplier_Rubix_Recipe_Runtime' ) ) {
    final class SEO_Supplier_Rubix_Recipe_Runtime {

        /**
         * Limpia texto manteniendo espacios legibles.
         *
         * @param mixed $value Valor.
         * @return string
         */
        public static function text( $value ) {
            $value = wp_strip_all_tags(
                html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' )
            );
            return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
        }

        /**
         * Texto en minusculas y sin acentos para comparaciones.
         *
         * @param mixed $value Valor.
         * @return string
         */
        private static function lower( $value ) {
            $value = self::text( $value );
            if ( function_exists( 'remove_accents' ) ) {
                $value = remove_accents( $value );
            }
            return strtolower( $value );
        }

        /**
         * Convierte una URL relativa en absoluta.
         *
         * @param string $href URL.
         * @param string $base_url Base.
         * @return string
         */
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

            $origin = $scheme . '://' . $base['host'];
            if ( ! empty( $base['port'] ) ) {
                $origin .= ':' . absint( $base['port'] );
            }

            if ( 0 === strpos( $href, '/' ) ) {
                return $origin . $href;
            }

            $path = (string) ( $base['path'] ?? '/' );
            $dir  = preg_replace( '#/[^/]*$#', '/', $path );
            return $origin . $dir . $href;
        }

        /**
         * URL canonica dentro de Rubix. Solo conserva ?page=.
         *
         * @param string $url URL.
         * @param array  $recipe Receta.
         * @return string
         */
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

            $path = '/' . ltrim( (string) ( $parts['path'] ?? '/' ), '/' );
            $path = preg_replace( '#/{2,}#', '/', $path );
            if ( '/' !== $path ) {
                $path = rtrim( $path, '/' );
            }

            $query_out = [];
            if ( ! empty( $parts['query'] ) ) {
                parse_str( (string) $parts['query'], $query );
                if ( isset( $query['page'] ) && is_scalar( $query['page'] ) ) {
                    $page = absint( $query['page'] );
                    if ( $page >= 2 && $page <= 500 ) {
                        $query_out['page'] = (string) $page;
                    }
                }
            }

            $result = 'https://es.rubix.com' . $path;
            if ( ! empty( $query_out ) ) {
                $result .= '?' . http_build_query( $query_out, '', '&', PHP_QUERY_RFC3986 );
            }

            return esc_url_raw( $result );
        }

        /**
         * Clasifica solo productos y categorias pertenecientes a las cuatro
         * ramas seleccionadas. No recorre el catalogo global de Rubix.
         *
         * @param string $url URL.
         * @param array  $recipe Receta.
         * @return string product|category|ignore
         */
        public static function classify( $url, $recipe = [] ) {
            $url = self::canonicalize( $url, $recipe );
            if ( '' === $url ) {
                return 'ignore';
            }

            $path = (string) wp_parse_url( $url, PHP_URL_PATH );

            if ( preg_match( '#/p-G[0-9]+$#i', $path ) ) {
                return 'product';
            }

            // Rubix codifica las categorias descendientes conservando el codigo raiz.
            // c-35..., c-45..., c-60... y c-90... son las unicas ramas admitidas.
            if ( preg_match( '#/c-(?:35|45|60|90)(?:-[0-9]+)*$#i', $path ) ) {
                return 'category';
            }

            return 'ignore';
        }

        /**
         * Convierte precio europeo a float.
         *
         * @param mixed $value Precio.
         * @return float|null
         */
        private static function price_number( $value ) {
            $value = self::text( $value );
            if ( '' === $value ) {
                return null;
            }

            $value = preg_replace( '/[^0-9,.-]/', '', $value );
            if ( '' === $value ) {
                return null;
            }

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

        /**
         * Rubix publica precio sin IVA. Devuelve sin/con IVA.
         *
         * @param mixed $price_sin_iva Precio publicado.
         * @param int   $vat IVA.
         * @return array{0:string,1:string}
         */
        private static function rubix_price_fields( $price_sin_iva, $vat ) {
            $number = self::price_number( $price_sin_iva );
            if ( null === $number || $number < 0 ) {
                return [ '', '' ];
            }

            $without = number_format( $number, 2, '.', '' );
            $with    = number_format( $number * ( 1 + ( max( 0, $vat ) / 100 ) ), 2, '.', '' );
            return [ $without, $with ];
        }

        /**
         * DOM + XPath tolerante a HTML imperfecto.
         *
         * @param string $html HTML.
         * @return array{0:DOMDocument,1:DOMXPath}|null
         */
        private static function dom_xpath( $html ) {
            if ( ! class_exists( 'DOMDocument' ) || ! class_exists( 'DOMXPath' ) ) {
                return null;
            }

            $dom = new DOMDocument();
            $old = libxml_use_internal_errors( true );
            $ok  = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . (string) $html );
            libxml_clear_errors();
            libxml_use_internal_errors( $old );

            if ( ! $ok ) {
                return null;
            }

            return [ $dom, new DOMXPath( $dom ) ];
        }

        /**
         * Primer texto XPath.
         *
         * @param DOMXPath $xpath XPath.
         * @param string   $query Consulta.
         * @param DOMNode  $context Contexto.
         * @return string
         */
        private static function xpath_text( $xpath, $query, $context = null ) {
            $nodes = $xpath->query( $query, $context );
            return $nodes && $nodes->length ? self::text( $nodes->item( 0 )->textContent ) : '';
        }

        /**
         * Primer atributo XPath.
         *
         * @param DOMXPath $xpath XPath.
         * @param string   $query Consulta.
         * @param string   $attr Atributo.
         * @param DOMNode  $context Contexto.
         * @return string
         */
        private static function xpath_attr( $xpath, $query, $attr, $context = null ) {
            $nodes = $xpath->query( $query, $context );
            if ( ! $nodes || ! $nodes->length || ! $nodes->item( 0 )->attributes ) {
                return '';
            }
            $node = $nodes->item( 0 )->attributes->getNamedItem( $attr );
            return $node ? trim( (string) $node->nodeValue ) : '';
        }

        /**
         * HTML interior de un nodo.
         *
         * @param DOMDocument $dom DOM.
         * @param DOMNode     $node Nodo.
         * @return string
         */
        private static function inner_html( $dom, $node ) {
            if ( ! $node ) {
                return '';
            }
            $html = '';
            foreach ( $node->childNodes as $child ) {
                $html .= $dom->saveHTML( $child );
            }
            return trim( $html );
        }

        /**
         * Busca el contenedor mas pequeno que parezca una tarjeta Rubix.
         *
         * @param DOMNode $node Nodo enlace.
         * @return DOMNode|null
         */
        private static function product_card_node( $node ) {
            $current = $node;
            $fallback = null;

            for ( $i = 0; $i < 9 && $current; $i++ ) {
                $text = self::text( $current->textContent );
                $len  = strlen( $text );

                if ( $len >= 20 && $len <= 2500 ) {
                    $fallback = $current;
                }

                if (
                    $len >= 30 && $len <= 2500
                    && preg_match( '/\bRubix\s*:\s*[A-Z0-9-]{6,}/i', $text )
                    && ( preg_match( '/\bEAN\s*:\s*[0-9]{8,14}/i', $text ) || false !== strpos( $text, '€' ) )
                ) {
                    return $current;
                }

                $current = $current->parentNode;
            }

            return $fallback;
        }

        /**
         * Extrae referencias publicas de Rubix desde texto de tarjeta/ficha.
         *
         * @param string $text Texto.
         * @return array<string,string>
         */
        private static function references_from_text( $text ) {
            $text = self::text( $text );
            $out  = [
                'rubix_ref' => '',
                'ean'       => '',
                'brand'     => '',
                'mpn'       => '',
            ];

            if ( preg_match( '/\b(?:Referencia\s+local\s+)?Rubix\s*:?\s*([A-Z0-9-]{6,})\b/i', $text, $m ) ) {
                $out['rubix_ref'] = sanitize_text_field( $m[1] );
            }

            if ( preg_match( '/\bEAN\s*:?\s*([0-9]{8,14})\b/i', $text, $m ) ) {
                $out['ean'] = sanitize_text_field( $m[1] );
            }

            if ( preg_match( '/\bfabricante\s*:?\s*([A-Z0-9][A-Z0-9 ._\/-]{1,60})/iu', $text, $m ) ) {
                $out['mpn'] = trim( sanitize_text_field( $m[1] ) );
            }

            // En listados Rubix suele aparecer "bahco:479-16 Rubix:798...".
            if ( preg_match( '/(?:^|\s)([A-ZÀ-Ÿa-zà-ÿ0-9&+._-]{2,32})\s*:\s*(.{1,70}?)\s+Rubix\s*:/u', $text, $m ) ) {
                $brand = self::text( $m[1] );
                $mpn   = self::text( $m[2] );
                $reserved = [ 'rubix', 'ean', 'fabricante', 'referencia' ];
                if ( ! in_array( self::lower( $brand ), $reserved, true ) ) {
                    $out['brand'] = $brand;
                    if ( '' === $out['mpn'] ) {
                        $out['mpn'] = $mpn;
                    }
                }
            }

            return $out;
        }

        /**
         * Precio sin IVA publicado por Rubix.
         *
         * @param string $text Texto.
         * @return string
         */
        private static function price_from_text( $text ) {
            $text = self::text( $text );
            if ( preg_match( '/([0-9]{1,3}(?:\.[0-9]{3})*,[0-9]{2}|[0-9]+(?:[.,][0-9]{2}))\s*€\s*(?:Sin\s+IVA)?/iu', $text, $m ) ) {
                return $m[1];
            }
            return '';
        }

        /**
         * Estado/texto de disponibilidad publico.
         *
         * @param string $text Texto.
         * @return array{0:string,1:string}
         */
        private static function stock_from_text( $text ) {
            $text  = self::text( $text );
            $lower = self::lower( $text );

            if ( false !== strpos( $lower, 'sin stock' ) || false !== strpos( $lower, 'agotado' ) ) {
                return [ 'outofstock', 'Agotado' ];
            }
            if ( preg_match( '/En\s+stock[^.]{0,80}/iu', $text, $m ) ) {
                return [ 'instock', self::text( $m[0] ) ];
            }
            if ( preg_match( '/Entregado\s+normalmente\s+en\s*\([^)]*\)\s*d[ií]as/iu', $text, $m ) ) {
                return [ 'instock', self::text( $m[0] ) ];
            }
            if ( preg_match( '/Entrega\s+estimada\s*\([^)]*\)\s*d[ií]as/iu', $text, $m ) ) {
                return [ '', self::text( $m[0] ) ];
            }

            return [ '', '' ];
        }

        /**
         * Categoria legible desde breadcrumb o h1.
         *
         * @param DOMXPath $xpath XPath.
         * @return string
         */
        private static function breadcrumb( $xpath ) {
            $queries = [
                '//*[contains(concat(" ",normalize-space(@class)," ")," breadcrumb ")]//a',
                '//nav[contains(translate(@aria-label,"BREADCRUMB","breadcrumb"),"breadcrumb")]//a',
            ];

            $parts = [];
            foreach ( $queries as $query ) {
                $nodes = $xpath->query( $query );
                if ( ! $nodes || ! $nodes->length ) {
                    continue;
                }
                foreach ( $nodes as $node ) {
                    $text = self::text( $node->textContent );
                    if ( '' !== $text && ! in_array( self::lower( $text ), [ 'inicio', 'home', 'nuestros productos' ], true ) ) {
                        $parts[] = $text;
                    }
                }
                if ( ! empty( $parts ) ) {
                    break;
                }
            }

            return implode( ' > ', array_values( array_unique( $parts ) ) );
        }

        /**
         * Lee tablas y pares dt/dd como informacion tecnica.
         *
         * @param DOMXPath $xpath XPath.
         * @return array<string,string>
         */
        private static function technical_attributes( $xpath ) {
            $attrs = [];

            $rows = $xpath->query( '//table//tr' );
            if ( $rows ) {
                foreach ( $rows as $row ) {
                    $cells = $xpath->query( './th|./td', $row );
                    if ( ! $cells || $cells->length < 2 ) {
                        continue;
                    }
                    $key = self::text( $cells->item( 0 )->textContent );
                    $val = self::text( $cells->item( 1 )->textContent );
                    if ( '' !== $key && '' !== $val && strlen( $key ) <= 90 && strlen( $val ) <= 300 ) {
                        $attrs[ $key ] = $val;
                    }
                    if ( count( $attrs ) >= 50 ) {
                        break;
                    }
                }
            }

            if ( count( $attrs ) < 50 ) {
                $dts = $xpath->query( '//dt' );
                if ( $dts ) {
                    foreach ( $dts as $dt ) {
                        $dd = $dt->nextSibling;
                        while ( $dd && XML_TEXT_NODE === $dd->nodeType && '' === trim( (string) $dd->textContent ) ) {
                            $dd = $dd->nextSibling;
                        }
                        if ( ! $dd || 'dd' !== strtolower( (string) $dd->nodeName ) ) {
                            continue;
                        }
                        $key = self::text( $dt->textContent );
                        $val = self::text( $dd->textContent );
                        if ( '' !== $key && '' !== $val && strlen( $key ) <= 90 && strlen( $val ) <= 300 ) {
                            $attrs[ $key ] = $val;
                        }
                        if ( count( $attrs ) >= 50 ) {
                            break;
                        }
                    }
                }
            }

            return $attrs;
        }

        /**
         * Construye descripcion util sin copiar navegacion completa.
         *
         * @param DOMDocument $dom DOM.
         * @param DOMXPath    $xpath XPath.
         * @param array       $attrs Atributos.
         * @return string
         */
        private static function description( $dom, $xpath, $attrs ) {
            $description = '';

            foreach ( [
                '//*[@id="description"]',
                '//*[contains(concat(" ",normalize-space(@class)," ")," product-description ")][1]',
                '//*[contains(concat(" ",normalize-space(@class)," ")," product-details ")][1]',
                '//*[contains(concat(" ",normalize-space(@class)," ")," product-detail ")][1]',
            ] as $query ) {
                $nodes = $xpath->query( $query );
                if ( $nodes && $nodes->length ) {
                    $candidate = self::inner_html( $dom, $nodes->item( 0 ) );
                    if ( strlen( self::text( $candidate ) ) >= 40 ) {
                        $description = wp_kses_post( $candidate );
                        break;
                    }
                }
            }

            // Rubix rotula la descripcion como "Detalles de producto". Si no hay
            // contenedor semantico, tomamos el primer bloque razonable posterior.
            if ( strlen( self::text( $description ) ) < 40 ) {
                $headings = $xpath->query( '//*[self::h2 or self::h3][contains(translate(normalize-space(.),"DETALLES DE PRODUCTO","detalles de producto"),"detalles de producto")]' );
                if ( $headings && $headings->length ) {
                    $heading = $headings->item( 0 );
                    $html    = '';
                    $node    = $heading->nextSibling;
                    $guard   = 0;
                    while ( $node && $guard < 12 ) {
                        if ( XML_ELEMENT_NODE === $node->nodeType && in_array( strtolower( (string) $node->nodeName ), [ 'h2', 'h3' ], true ) ) {
                            break;
                        }
                        if ( XML_ELEMENT_NODE === $node->nodeType ) {
                            $html .= $dom->saveHTML( $node );
                        }
                        $node = $node->nextSibling;
                        $guard++;
                    }
                    if ( strlen( self::text( $html ) ) >= 40 ) {
                        $description = wp_kses_post( $html );
                    }
                }
            }

            if ( ! empty( $attrs ) ) {
                $description .= "\n<h3>Informacion tecnica</h3><ul>";
                foreach ( array_slice( $attrs, 0, 50, true ) as $key => $value ) {
                    $description .= '<li><strong>' . esc_html( $key ) . ':</strong> ' . esc_html( $value ) . '</li>';
                }
                $description .= '</ul>';
            }

            return trim( wp_kses_post( $description ) );
        }

        /**
         * Imagenes publicas de ficha.
         *
         * @param DOMXPath $xpath XPath.
         * @param array    $json_product JSON-LD Product opcional.
         * @return string[]
         */
        private static function images( $xpath, $json_product = [] ) {
            $images = [];

            $raw = $json_product['image'] ?? [];
            if ( is_string( $raw ) ) {
                $raw = [ $raw ];
            } elseif ( is_array( $raw ) && isset( $raw['url'] ) ) {
                $raw = [ $raw['url'] ];
            }

            foreach ( (array) $raw as $image ) {
                if ( is_array( $image ) ) {
                    $image = $image['url'] ?? ( $image['contentUrl'] ?? '' );
                }
                $image = esc_url_raw( (string) $image );
                if ( preg_match( '#^https?://#i', $image ) ) {
                    $images[] = $image;
                }
            }

            foreach ( [ '//meta[@property="og:image"][1]', '//meta[@name="twitter:image"][1]' ] as $query ) {
                $image = self::xpath_attr( $xpath, $query, 'content' );
                if ( preg_match( '#^https?://#i', $image ) ) {
                    $images[] = esc_url_raw( $image );
                }
            }

            return array_values( array_unique( $images ) );
        }

        /**
         * Busca el primer Product de JSON-LD.
         *
         * @param DOMXPath $xpath XPath.
         * @return array
         */
        private static function json_ld_product( $xpath ) {
            $scripts = $xpath->query( '//script[@type="application/ld+json"]' );
            if ( ! $scripts ) {
                return [];
            }

            $stack = [];
            foreach ( $scripts as $script ) {
                $decoded = json_decode( trim( (string) $script->textContent ), true );
                if ( is_array( $decoded ) ) {
                    $stack[] = $decoded;
                }
            }

            while ( ! empty( $stack ) ) {
                $node = array_shift( $stack );
                if ( ! is_array( $node ) ) {
                    continue;
                }
                $type  = $node['@type'] ?? '';
                $types = is_array( $type ) ? $type : [ $type ];
                foreach ( $types as $candidate ) {
                    if ( 'product' === strtolower( (string) $candidate ) ) {
                        return $node;
                    }
                }
                foreach ( $node as $value ) {
                    if ( is_array( $value ) ) {
                        $stack[] = $value;
                    }
                }
            }

            return [];
        }

        /**
         * Registro completo desde ficha Rubix.
         *
         * @param DOMDocument $dom DOM.
         * @param DOMXPath    $xpath XPath.
         * @param string      $html HTML.
         * @param string      $url URL.
         * @param array       $recipe Receta.
         * @return array|null
         */
        private static function product_record( $dom, $xpath, $html, $url, $recipe ) {
            $canonical = self::canonicalize( $url, $recipe );
            if ( '' === $canonical ) {
                return null;
            }

            $json = self::json_ld_product( $xpath );
            $name = self::text( $json['name'] ?? '' );
            if ( '' === $name ) {
                $name = self::xpath_text( $xpath, '//h1[1]' );
            }
            if ( '' === $name ) {
                return null;
            }

            $body = self::text( $html );
            $refs = self::references_from_text( $body );

            $sku = $refs['rubix_ref'];
            if ( '' === $sku ) {
                $sku = self::text( $json['sku'] ?? ( $json['productID'] ?? '' ) );
            }

            $mpn = $refs['mpn'];
            if ( '' === $mpn ) {
                $mpn = self::text( $json['mpn'] ?? ( $json['model'] ?? '' ) );
            }

            $brand = $refs['brand'];
            if ( '' === $brand && ! empty( $json['brand'] ) ) {
                $brand_data = $json['brand'];
                if ( is_array( $brand_data ) ) {
                    $brand_data = $brand_data['name'] ?? '';
                }
                $brand = self::text( $brand_data );
            }

            $price = self::price_from_text( $body );
            if ( '' === $price && ! empty( $json['offers'] ) ) {
                $offers = $json['offers'];
                if ( isset( $offers['price'] ) ) {
                    $price = $offers['price'];
                } elseif ( is_array( $offers ) ) {
                    foreach ( $offers as $offer ) {
                        if ( is_array( $offer ) && isset( $offer['price'] ) ) {
                            $price = $offer['price'];
                            break;
                        }
                    }
                }
            }

            [ $without_vat, $with_vat ] = self::rubix_price_fields( $price, absint( $recipe['vat_percent'] ?? 21 ) );
            [ $stock_state, $stock_text ] = self::stock_from_text( $body );

            $attrs       = self::technical_attributes( $xpath );
            $description = self::description( $dom, $xpath, $attrs );
            if ( strlen( self::text( $description ) ) < 30 && ! empty( $json['description'] ) ) {
                $description = wp_kses_post( (string) $json['description'] );
            }

            $category = self::breadcrumb( $xpath );
            $images   = self::images( $xpath, $json );

            $url_path = (string) wp_parse_url( $canonical, PHP_URL_PATH );
            $group_id = '';
            if ( preg_match( '#/p-(G[0-9]+)$#i', $url_path, $m ) ) {
                $group_id = strtoupper( $m[1] );
            }

            // El identificador mas estable para nuestro staging es la referencia
            // local Rubix. Si no aparece, usamos EAN y por ultimo el grupo p-G.
            $external_id = $refs['rubix_ref'];
            if ( '' === $external_id ) {
                $external_id = $refs['ean'];
            }
            if ( '' === $external_id ) {
                $external_id = $group_id;
            }
            if ( '' === $external_id ) {
                $external_id = substr( hash( 'sha256', $canonical ), 0, 24 );
            }

            return [
                'proveedor_id_externo' => $external_id,
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
                'moneda'                => 'EUR',
                'stock_estado'          => $stock_state,
                'stock_cantidad'        => '',
                'stock_texto'           => $stock_text,
                'imagenes'              => implode( "\n", $images ),
            ];
        }

        /**
         * Registro ligero desde tarjeta de listado. Luego la ficha lo enriquece.
         *
         * @param DOMXPath $xpath XPath.
         * @param DOMNode  $link Enlace producto.
         * @param string   $candidate URL.
         * @param string   $category Categoria.
         * @param array    $recipe Receta.
         * @return array|null
         */
        private static function card_record( $xpath, $link, $candidate, $category, $recipe ) {
            $card = self::product_card_node( $link );
            if ( ! $card ) {
                return null;
            }

            $context = self::text( $card->textContent );
            $name    = self::text( $link->getAttribute( 'aria-label' ) );
            if ( '' === $name ) {
                $name = self::text( $link->getAttribute( 'title' ) );
            }
            if ( '' === $name ) {
                $name = self::text( $link->textContent );
            }
            if ( '' === $name || strlen( $name ) < 4 ) {
                $heading = $xpath->query( './/*[self::h2 or self::h3 or self::h4][1]', $card );
                if ( $heading && $heading->length ) {
                    $name = self::text( $heading->item( 0 )->textContent );
                }
            }
            if ( '' === $name ) {
                return null;
            }

            $refs  = self::references_from_text( $context );
            $price = self::price_from_text( $context );
            [ $without_vat, $with_vat ] = self::rubix_price_fields( $price, absint( $recipe['vat_percent'] ?? 21 ) );
            [ $stock_state, $stock_text ] = self::stock_from_text( $context );

            $path = (string) wp_parse_url( $candidate, PHP_URL_PATH );
            $group_id = '';
            if ( preg_match( '#/p-(G[0-9]+)$#i', $path, $m ) ) {
                $group_id = strtoupper( $m[1] );
            }

            $external_id = $refs['rubix_ref'] ?: ( $refs['ean'] ?: $group_id );
            if ( '' === $external_id ) {
                return null;
            }

            return [
                'proveedor_id_externo' => $external_id,
                'sku'                   => $refs['rubix_ref'],
                'mpn'                   => $refs['mpn'],
                'url_origen'            => $candidate,
                'url_canonica'          => $candidate,
                'nombre'                => $name,
                'descripcion'           => '',
                'marca'                 => $refs['brand'],
                'categoria_proveedor'   => $category,
                'precio_sin_iva'        => $without_vat,
                'precio_con_iva'        => $with_vat,
                'iva_porcentaje'        => (string) absint( $recipe['vat_percent'] ?? 21 ),
                'moneda'                => 'EUR',
                'stock_estado'          => $stock_state,
                'stock_cantidad'        => '',
                'stock_texto'           => $stock_text,
                'imagenes'              => '',
            ];
        }

        /**
         * Parser principal de pagina.
         *
         * @param string $html HTML.
         * @param string $url URL.
         * @param array  $recipe Receta.
         * @param array  $job Trabajo de cola.
         * @return array|WP_Error
         */
        public static function parse_page( $html, $url, $recipe, $job = [] ) {
            $dom_data = self::dom_xpath( $html );
            if ( ! is_array( $dom_data ) ) {
                return new WP_Error( 'rubix_dom_unavailable', 'No se pudo interpretar el HTML publico de Rubix.' );
            }

            [ $dom, $xpath ] = $dom_data;
            $type = self::classify( $url, $recipe );

            if ( 'product' === $type ) {
                $record = self::product_record( $dom, $xpath, $html, $url, $recipe );
                return [
                    'type'    => 'product',
                    'records' => is_array( $record ) ? [ $record ] : [],
                    'enqueue' => [],
                    'message' => is_array( $record )
                        ? 'Ficha Rubix leida y enriquecida.'
                        : 'Ficha Rubix leida sin datos suficientes para staging.',
                ];
            }

            if ( 'category' !== $type && 'home' !== sanitize_key( (string) ( $job['job_type'] ?? '' ) ) ) {
                return [
                    'type'    => 'ignore',
                    'records' => [],
                    'enqueue' => [],
                    'message' => 'URL fuera de las ramas Rubix seleccionadas.',
                ];
            }

            $records = [];
            $enqueue = [];
            $seen    = [];
            $category = self::xpath_text( $xpath, '//h1[1]' );

            $links = $xpath->query( '//a[@href]' );
            if ( $links ) {
                foreach ( $links as $link ) {
                    $candidate = self::canonicalize( $link->getAttribute( 'href' ), $recipe );
                    if ( '' === $candidate || isset( $seen[ $candidate ] ) ) {
                        continue;
                    }

                    $kind = self::classify( $candidate, $recipe );
                    if ( 'product' === $kind ) {
                        $seen[ $candidate ] = true;

                        $record = self::card_record( $xpath, $link, $candidate, $category, $recipe );
                        if ( is_array( $record ) ) {
                            $records[] = $record;
                        }

                        // Prioridad baja numericamente: la cola 0.3.x usa ASC.
                        // Las fichas deben consumirse antes que seguir expandiendo categorias.
                        $enqueue[] = [
                            'url'      => $candidate,
                            'type'     => 'product',
                            'priority' => 5,
                            'source'   => $url,
                        ];
                        continue;
                    }

                    if ( 'category' === $kind ) {
                        $seen[ $candidate ] = true;
                        $enqueue[] = [
                            'url'      => $candidate,
                            'type'     => 'category',
                            'priority' => 30,
                            'source'   => $url,
                        ];
                    }

                    if ( count( $enqueue ) >= absint( $recipe['max_enqueue_per_page'] ?? 250 ) ) {
                        break;
                    }
                }
            }

            // Las paginas siguientes conservan el mismo c-XX y solo ?page=N.
            $pagination = $xpath->query( '//a[@href and (contains(@href,"?page=") or @rel="next")]' );
            if ( $pagination ) {
                foreach ( $pagination as $link ) {
                    $candidate = self::canonicalize( $link->getAttribute( 'href' ), $recipe );
                    if ( '' === $candidate || isset( $seen[ $candidate ] ) || 'category' !== self::classify( $candidate, $recipe ) ) {
                        continue;
                    }
                    $seen[ $candidate ] = true;
                    $enqueue[] = [
                        'url'      => $candidate,
                        'type'     => 'category',
                        'priority' => 20,
                        'source'   => $url,
                    ];
                    if ( count( $enqueue ) >= absint( $recipe['max_enqueue_per_page'] ?? 250 ) ) {
                        break;
                    }
                }
            }

            return [
                'type'    => 'category',
                'records' => $records,
                'enqueue' => $enqueue,
                'message' => sprintf(
                    'Rubix: %d productos visibles preparados y %d URLs relevantes en cola.',
                    count( $records ),
                    count( $enqueue )
                ),
            ];
        }
    }
}

add_filter( 'seo_supplier_crawl_recipes', static function ( $recipes ) {
    if ( ! is_array( $recipes ) ) {
        $recipes = [];
    }

    $recipes['rubix'] = [
        'id'          => 'rubix',
        'label'       => 'RUBIX - herramientas industriales',
        'provider'    => 'RUBIX',
        'version'     => '0.1.0',
        'base_url'    => 'https://es.rubix.com/es/',
        'description' => 'Descubre solo herramientas, equipamiento, mantenimiento y soldadura. Genera CSV interno y pasa al catalogo intermedio para revision por estados; no publica productos automaticamente.',

        'allowed_hosts' => [
            'es.rubix.com',
        ],

        // Se mantiene la politica conservadora del crawler comun.
        'respect_robots' => true,
        'min_delay'      => 60,
        'initial_delay'  => 90,
        'max_delay'      => 1800,
        'refresh_hours'  => 72,
        'revisit_days'   => 21,
        'max_attempts'   => 4,

        // El CSV es una etapa intermedia. El usuario decide despues por estados.
        'csv_flush_rows'     => 50,
        'csv_flush_interval' => 1800,
        'vat_percent'        => 21,

        // Deliberadamente SIN sitemap global: evita llenar la cola con cientos
        // de miles de referencias ajenas a nuestro catalogo objetivo.
        'sitemap_urls' => [],
        'seed_urls'    => [
            'https://es.rubix.com/es/herramientas-y-metrologia/c-35',
            'https://es.rubix.com/es/equipamiento/c-45',
            'https://es.rubix.com/es/mantenimiento-y-reparacion/c-60',
            'https://es.rubix.com/es/soldadura/c-90',
        ],

        'keep_query'           => [ 'page' ],
        'max_enqueue_per_page' => 250,

        'canonicalize_callback' => [ 'SEO_Supplier_Rubix_Recipe_Runtime', 'canonicalize' ],
        'classify_url_callback' => [ 'SEO_Supplier_Rubix_Recipe_Runtime', 'classify' ],
        'parse_page_callback'   => [ 'SEO_Supplier_Rubix_Recipe_Runtime', 'parse_page' ],
    ];

    return $recipes;
} );
