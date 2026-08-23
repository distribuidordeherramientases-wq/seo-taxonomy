<?php
/**
 * Receta oficial VEVOR para SEO System.
 *
 * V1.5.0
 * - PHP solo normaliza el CSV; NO comprueba F/M por HTTP.
 * - Conserva la imagen principal del feed en `imagenes`.
 * - Marca el CSV preparado para enriquecimiento externo por GitHub/Python.
 * - GitHub/Python devuelve el mismo CSV con F1..F12 y M1..M12 validas.
 * - El importador comun escribe despues wp_seo_supplier_images.
 * - No borra attachments ni archivos de la Biblioteca de Medios.
 *
 * @package SEOSystem
 * @subpackage SupplierImports
 * @version 1.5.0
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

if ( ! function_exists( 'seo_supplier_recipe_vevor_transform' ) ) {
    /**
     * Convierte una fila original VEVOR al CSV estandar.
     *
     * La imagen se conserva sin comprobar aqui. El enriquecimiento F/M se
     * realiza despues sobre el CSV preparado por github-python-runner.php.
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

        return [
            'proveedor_id_externo' => $identity['id'],
            'sku'                   => $identity['sku'],
            'url_origen'            => seo_supplier_recipe_vevor_clean_product_url( $product_url ),
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
            'label'       => 'VEVOR Espana - galeria externa Python',
            'provider'    => 'VEVOR',
            'version'     => '1.5.0',
            'mode'        => 'transform',
            'description' => 'Normaliza el CSV VEVOR y, si se seleccionan imagenes externas, envia el CSV preparado a GitHub/Python para validar F1-F12 y M1-M12 antes de sincronizar. No borra Media.',

            /* El importer.php v1.5 intercepta el CSV preparado por esta receta. */
            'prepared_processor'               => 'github_python',
            'prepared_processor_external_only' => true,

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
                    'operation' => 'Conservar la ficha VEVOR sin query string ni fragmento.',
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
                    'operation' => 'Conservar la principal. GitHub/Python agregara solamente F1-F12/M1-M12 que existan realmente.',
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
