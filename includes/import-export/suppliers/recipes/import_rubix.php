<?php
/**
 * Receta oficial Rubix para el importador de proveedores de SEO System.
 *
 * La receta PHP no rastrea Rubix. Solo:
 * - declara la receta de importacion/mapeo;
 * - registra Rubix como scraper externo;
 * - delega el scraping en GitHub Actions, que ejecuta scrapers/rubix.py;
 * - enlaza el CSV devuelto con la receta de importacion "rubix".
 *
 * @package SEOSystem
 * @subpackage SupplierImports
 * @version 1.1.0
 */

defined( 'ABSPATH' ) || exit;

/*
 * Receta de importacion manual/comun.
 * Permite cargar el CSV devuelto por GitHub o un CSV/XLS/XLSX manual.
 */
add_filter(
    'seo_proveedores_import_recipes',
    static function ( $recipes ) {
        if ( ! is_array( $recipes ) ) {
            $recipes = [];
        }

        $recipes['rubix'] = [
            'id'          => 'rubix',
            'label'       => 'Rubix - mapeo de archivo',
            'provider'    => 'RUBIX',
            'version'     => '1.1.0',
            'mode'        => 'mapping',
            'description' => 'Importa un CSV/XLS/XLSX de Rubix mediante el mapeo visual comun. Esta receta no rastrea la web.',
        ];

        return $recipes;
    }
);

/**
 * Fuente web externa de Rubix.
 *
 * Esta entrada hace que Rubix aparezca dentro de "Scraper externo" en
 * "Obtener catalogo desde la web". Al pulsar "Iniciar obtencion", WordPress
 * llama al GitHub Python Runner con recipe_id=rubix. El workflow ejecuta
 * scrapers/rubix.py y devuelve el CSV estandar al callback de WordPress.
 */
add_filter(
    'seo_supplier_external_web_recipes',
    static function ( $recipes ) {
        if ( ! is_array( $recipes ) ) {
            $recipes = [];
        }

        $recipes['rubix_github'] = [
            'id'               => 'rubix_github',
            'label'            => 'Rubix - Python externo',
            'provider'         => 'RUBIX',
            'version'          => '1.1.0',
            'runner'           => 'github',
            'import_recipe_id' => 'rubix',
            'description'      => 'Ejecuta scrapers/rubix.py en GitHub Actions y devuelve automaticamente el CSV estandar a WordPress.',
        ];

        return $recipes;
    }
);
