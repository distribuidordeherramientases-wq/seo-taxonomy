<?php
/**
 * Receta oficial VEVOR para TaxoCommerce SEO.
 *
 * V1.7.0
 * - PHP normaliza el CSV y lo entrega directamente al importador comun.
 * - Conserva la imagen principal del feed en `imagenes`.
 * - No necesita GitHub/Python ni enriquecimiento F1..F12/M1..M12 para el flujo manual.
 * - Las imagenes externas siguen alojadas por VEVOR; no crea attachments.
 * - No borra archivos de la Biblioteca de Medios.
 *
 * @package SEOSystem
 * @subpackage SupplierImports
 * @version 1.7.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'seo_supplier_recipe_vevor_clean_product_url' ) ) {
    function seo_supplier_recipe_vevor_clean_product_url( $url ) {
        $url = trim( (string) $url );
        if ( '' === $url ) {
            return '';
        }

        $query_pos    = strpos( $url, '?' );
        $fragment_pos = strpos( $url, '#' );
        $positions    = array_filter(
            [ $query_pos, $fragment_pos ],
            static function ( $position ) {
                return false !== $position;
            }
        );
        $cut_at = empty( $positions ) ? false : min( $positions );

        return false === $cut_at ? $url : substr( $url, 0, $cut_at );
    }
}

if ( ! function_exists( 'seo_supplier_recipe_vevor_extract_identity' ) ) {
    /**
     * Extrae el ID de 12 digitos de ...p_012345678901.
     *
     * @param string $product_url URL VEVOR.
     * @return array{id:string,sku:string}
     * @throws RuntimeException Si no existe una identidad valida.
     */
    function seo_supplier_recipe_vevor_extract_identity( $product_url ) {
        $product_url = trim( (string) $product_url );
        $marker      = strrpos( $product_url, 'p_' );

        if ( false === $marker ) {
            throw new RuntimeException( 'La URL del producto no contiene el marcador p_.' );
        }

        $id         = substr( $product_url, $marker + 2, 12 );
        $terminator = substr( $product_url, $marker + 14, 1 );

        if ( 12 !== strlen( $id ) || ! ctype_digit( $id ) ) {
            throw new RuntimeException( 'No se pudo extraer un ID VEVOR de 12 digitos.' );
        }

        if ( '' !== $terminator && ! in_array( $terminator, [ '?', '&', '#' ], true ) ) {
            throw new RuntimeException( 'El ID VEVOR no termina con un delimitador reconocido.' );
        }

        return [
            'id'  => $id,
            'sku' => 'p_' . $id,
        ];
    }
}

/**
 * Determina el mercado VEVOR. Country en el CSV es la fuente preferente;
 * los CSV Goods_Export no incluyen Country, por lo que se usa el dominio.
 * No se deduce el mercado de la moneda (EUR sirve para varios paises).
 */
if ( ! function_exists( 'seo_supplier_recipe_vevor_market' ) ) {
    function seo_supplier_recipe_vevor_market( $row, $product_url ) {
        $hosts = [
            'vevor.de' => 'DE',
            'vevor.nl' => 'NL',
            'vevor.com' => 'US',
            'vevor.fr' => 'FR',
            'vevor.co.uk' => 'GB',
            'vevor.es' => 'ES',
            'vevor.it' => 'IT',
            'vevor.ca' => 'CA',
            'vevor.com.au' => 'AU',
        ];
        $languages = [
            'DE' => 'de',
            'NL' => 'nl',
            'US' => 'en',
            'FR' => 'fr',
            'GB' => 'en',
            'ES' => 'es',
            'IT' => 'it',
            'CA' => 'en',
            'AU' => 'en',
        ];
        $host = strtolower( (string) wp_parse_url( $product_url, PHP_URL_HOST ) );
        $host = preg_replace( '/^www\\./', '', $host );
        $csv_country = strtoupper( trim( (string) ( $row['Country'] ?? $row['country'] ?? '' ) ) );
        $host_country = $hosts[ $host ] ?? '';
        if ( '' === $host_country ) {
            throw new RuntimeException( 'Dominio VEVOR no reconocido: ' . $host );
        }
        if ( '' !== $csv_country && ! isset( $languages[ $csv_country ] ) ) {
            throw new RuntimeException( 'Mercado VEVOR no soportado: ' . $csv_country );
        }
        // .com puede servir a distintos mercados. Un dominio nacional no.
        if ( '' !== $csv_country && 'vevor.com' !== $host && $csv_country !== $host_country ) {
            throw new RuntimeException( 'Country no coincide con el dominio VEVOR de la fila.' );
        }
        $country = '' !== $csv_country ? $csv_country : $host_country;
        return [ 'pais_origen' => $country, 'idioma_origen' => $languages[ $country ] ];
    }
}

if ( ! function_exists( 'seo_supplier_recipe_vevor_transform' ) ) {
    /**
     * Convierte una fila original VEVOR al CSV estandar.
     *
     * La imagen principal se conserva como URL externa. Este flujo manual no
     * necesita enriquecimiento F/M ni una segunda fase en GitHub/Python.
     *
     * @param array $row Fila original.
     * @param array $state Estado del archivo.
     * @param array $recipe Receta.
     * @return array
     */
    function seo_supplier_recipe_vevor_transform( $row, $state = [], $recipe = [] ) {
        unset( $state, $recipe );

        $product_url = trim( (string) ( $row['URL to product'] ?? '' ) );
        $identity    = seo_supplier_recipe_vevor_extract_identity( $product_url );
        $primary     = esc_url_raw( trim( (string) ( $row['URL to image'] ?? '' ) ) );
        $market      = seo_supplier_recipe_vevor_market( $row, $product_url );

        return [
            'proveedor_id_externo' => $identity['id'],
            'sku'                   => $identity['sku'],
            'pais_origen'           => $market['pais_origen'],
            'idioma_origen'         => $market['idioma_origen'],
            'url_origen'            => esc_url_raw( $product_url ),
            'url_canonica'          => seo_supplier_recipe_vevor_clean_product_url( $product_url ),
            'nombre'                => trim( (string) ( $row['Name'] ?? '' ) ),
            'categoria_proveedor'   => trim( (string) ( $row['categoryPath'] ?? '' ) ),
            'imagenes'              => '' === $primary
                ? ''
                : wp_json_encode( [ $primary ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
            'precio_con_iva'        => trim( (string) ( $row['Sales'] ?? '' ) ),
            'moneda'                => strtoupper( trim( (string) ( $row['Currency'] ?? '' ) ) ),
        ];
    }
}

add_filter(
    'seo_proveedores_import_recipes',
    static function ( $recipes ) {
        if ( ! is_array( $recipes ) ) {
            $recipes = [];
        }

        $recipes['vevor'] = [
            'id'          => 'vevor',
            'label'       => 'VEVOR - imagen principal externa',
            'provider'    => 'VEVOR',
            'version'     => '1.7.0',
            'mode'        => 'transform',
            'description' => 'Normaliza el CSV VEVOR, conserva la imagen principal como externa y lo importa directamente al catalogo intermedio. No necesita GitHub/Python ni borra Media.',

            'required_headers' => [
                'Name',
                'URL to product',
                'categoryPath',
                'URL to image',
                'Currency',
                'Sales',
            ],
            'transform_callback' => 'seo_supplier_recipe_vevor_transform',
            'relations' => [
                [
                    'source'    => 'URL to product',
                    'target'    => 'proveedor_id_externo',
                    'operation' => 'Tomar los 12 digitos posteriores al ultimo p_.',
                ],
                [
                    'source'    => 'URL to product',
                    'target'    => 'sku',
                    'operation' => 'Conservar el SKU historico VEVOR como p_ + 12 digitos.',
                ],
                [
                    'source'    => 'URL to product',
                    'target'    => 'url_origen',
                    'operation' => 'Conservar el enlace completo con parametros de afiliacion y shortkey.',
                ],
                [
                    'source'    => 'Country / dominio de URL to product',
                    'target'    => 'pais_origen',
                    'operation' => 'Identificar el mercado por Country y, si falta, por vevor.de / vevor.nl / vevor.com.',
                ],
                [
                    'source'    => 'Country / dominio de URL to product',
                    'target'    => 'idioma_origen',
                    'operation' => 'Asignar idioma base del mercado, reutilizable entre paises.',
                ],
                [
                    'source'    => 'Name',
                    'target'    => 'nombre',
                    'operation' => 'Conservar el nombre original del producto.',
                ],
                [
                    'source'    => 'categoryPath',
                    'target'    => 'categoria_proveedor',
                    'operation' => 'Conservar la ruta de categoria original de VEVOR.',
                ],
                [
                    'source'    => 'URL to image',
                    'target'    => 'imagenes',
                    'operation' => 'Conservar la imagen principal del feed como imagen externa, sin enriquecer F1-F12/M1-M12.',
                ],
                [
                    'source'    => 'Sales',
                    'target'    => 'precio_con_iva',
                    'operation' => 'Normalizar el precio como decimal.',
                ],
                [
                    'source'    => 'Currency',
                    'target'    => 'moneda',
                    'operation' => 'Normalizar la moneda en mayusculas.',
                ],
            ],
        ];

        return $recipes;
    }
);
