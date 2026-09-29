<?php
/**
 * Receta CSV de Decathlon para el catalogo intermedio de proveedores.
 *
 * El mapeo visual permite adaptar el CSV recibido a los campos comunes
 * sin asumir columnas, precios B2B ni enlaces de afiliacion inexistentes.
 * Registrar la receta no publica productos de WooCommerce.
 *
 * @package SEOSystem
 * @subpackage SupplierImports
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

add_filter(
    'seo_proveedores_import_recipes',
    static function ( $recipes ) {
        if ( ! is_array( $recipes ) ) {
            $recipes = [];
        }

        $recipes['decathlon'] = [
            'id'          => 'decathlon',
            'label'       => 'Decathlon - CSV (mapeo de archivo)',
            'provider'    => 'Decathlon',
            'version'     => '1.0.0',
            'mode'        => 'mapping',
            'description' => 'Importa un CSV de Decathlon al catalogo intermedio mediante el mapeo visual comun. Revisar el vendedor, la comision y los enlaces de afiliacion antes de publicar cualquier producto.',
        ];

        return $recipes;
    }
);
