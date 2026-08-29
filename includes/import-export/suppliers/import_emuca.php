<?php
/**
 * Receta oficial EMUCA para SEO System.
 *
 * Flujo recomendado:
 * 1) "EMUCA - Python externo" lanza el scraper en GitHub Actions.
 * 2) GitHub ejecuta scrapers/emuca.py sobre el sitemap publico de Emuca.
 * 3) El scraper genera el CSV estandar de proveedores y lo devuelve al
 *    callback privado de WordPress.
 * 4) El motor comun conserva el CSV y actualiza el catalogo de proveedores.
 * 5) "EMUCA - mapeo de archivo" queda disponible como via manual.
 *
 * El scraper solo usa URLs publicas permitidas y URLs canonicas sin query
 * string. Los precios permanecen vacios cuando Emuca no los muestra a la
 * sesion utilizada por el scraper.
 *
 * @package SEOSystem
 * @subpackage SupplierImports
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Receta manual. Tambien es la identidad que usa el callback del runner.
 */
add_filter(
    'seo_proveedores_import_recipes',
    static function ( $recipes ) {
        if ( ! is_array( $recipes ) ) {
            $recipes = [];
        }

        $recipes['emuca'] = [
            'id'          => 'emuca',
            'label'       => 'EMUCA - catalogo / mapeo de archivo',
            'provider'    => 'EMUCA',
            'version'     => '1.0.0',
            'mode'        => 'mapping',
            'description' => 'Importa el CSV estandar generado desde el sitemap publico de Emuca. Conserva SKU, URLs, descripcion tecnica, categorias e imagenes; el precio solo se informa cuando la sesion de Emuca lo expone.',
        ];

        return $recipes;
    }
);

/**
 * Fuente web externa mediante GitHub Actions.
 *
 * catalog_complete=0: el scraper prioriza repuestos de correderas, carros,
 * ruedas, patines, carriles, Placard y armarios para una prueba controlada.
 * catalog_complete=1: procesa todas las URLs de producto detectadas en el
 * sitemap espanol.
 */
add_filter(
    'seo_supplier_external_web_recipes',
    static function ( $recipes ) {
        if ( ! is_array( $recipes ) ) {
            $recipes = [];
        }

        $recipes['emuca_github'] = [
            'id'               => 'emuca_github',
            'label'            => 'EMUCA - Python externo',
            'provider'         => 'EMUCA',
            'version'          => '1.0.0',
            'runner'           => 'github',
            'import_recipe_id' => 'emuca',
            'description'      => 'Lee el sitemap oficial de Emuca, visita fichas publicas sin parametros y devuelve el CSV estandar al catalogo de proveedores. La prueba prioriza ruedas y herrajes para puertas correderas.',
        ];

        return $recipes;
    }
);
