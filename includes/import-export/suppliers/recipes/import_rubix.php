<?php
/**
 * Receta oficial RUBIX para el importador de proveedores de SEO System.
 *
 * Flujo:
 * - La receta de importacion solo declara RUBIX para el mapeo/CSV comun.
 * - La obtencion web NO se ejecuta en PHP/WordPress.
 * - "RUBIX - Python externo" delega el scraping en GitHub Actions.
 * - GitHub ejecuta scrapers/rubix.py y devuelve el CSV estandar a WordPress.
 *
 * @package SEOSystem
 * @subpackage SupplierImports
 * @version 1.1.0
 */

defined( 'ABSPATH' ) || exit;

/* Receta de importacion/mapeo del CSV devuelto por el scraper. */
add_filter(
    'seo_proveedores_import_recipes',
    static function ( $recipes ) {
        if ( ! is_array( $recipes ) ) {
            $recipes = [];
        }

        $recipes['rubix'] = [
            'id'          => 'rubix',
            'label'       => 'RUBIX - mapeo de archivo',
            'provider'    => 'RUBIX',
            'version'     => '1.1.0',
            'mode'        => 'mapping',
            'description' => 'Importa el CSV estandar generado por el scraper Python de Rubix. Tambien permite carga manual CSV/XLS/XLSX mediante el mapeo comun.',
        ];

        return $recipes;
    }
);

/**
 * Fuente web externa RUBIX mediante GitHub Actions.
 *
 * Esta entrada hace que RUBIX aparezca en el grupo "Scraper externo" de
 * "Obtener catalogo desde la web". No registra ninguna receta en
 * seo_supplier_crawl_recipes, por lo que RUBIX no aparece en el rastreo PHP.
 */
add_filter(
    'seo_supplier_external_web_recipes',
    static function ( $recipes ) {
        if ( ! is_array( $recipes ) ) {
            $recipes = [];
        }

        $recipes['rubix_github'] = [
            'id'               => 'rubix_github',
            'label'            => 'RUBIX - Python externo',
            'provider'         => 'RUBIX',
            'version'          => '1.1.0',
            'runner'           => 'github',
            'import_recipe_id' => 'rubix',
            'description'      => 'Ejecuta scrapers/rubix.py en GitHub Actions y devuelve automaticamente el CSV estandar a WordPress.',
        ];

        return $recipes;
    }
);
