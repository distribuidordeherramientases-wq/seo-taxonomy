<?php
/**
 * Receta oficial RUBIX para SEO System.
 *
 * Flujo:
 * 1) "RUBIX - Python externo" lanza el scraper en GitHub Actions.
 * 2) GitHub ejecuta scrapers/rubix.py y genera el CSV estandar.
 * 3) El callback privado devuelve el CSV a WordPress.
 * 4) El motor comun actualiza el Catalogo de proveedores / Supplier Sync.
 * 5) "RUBIX - mapeo de archivo" queda disponible como via manual.
 *
 * La receta WordPress no realiza el scraping. Solo registra RUBIX en los
 * inventarios dinamicos y enlaza el runner externo con import_recipe_id=rubix.
 *
 * @package SEOSystem
 * @subpackage SupplierImports
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Identidad de importacion RUBIX.
 *
 * Tambien es necesaria para el callback del GitHub Runner: cuando vuelve el
 * CSV, el sistema resuelve recipe_id=rubix contra las recetas de importacion.
 */
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
            'version'     => '1.0.0',
            'mode'        => 'mapping',
            'description' => 'Importa el CSV estandar generado por scrapers/rubix.py. Tambien admite carga manual CSV/XLS/XLSX mediante el mapeo comun.',
        ];

        return $recipes;
    }
);

/**
 * Fuente web externa RUBIX mediante GitHub Actions.
 *
 * Al pulsar "Iniciar obtencion", WordPress envia recipe_id=rubix al runner.
 * El checkbox "Catalogo completo" se reenvia como catalog_complete.
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
            'version'          => '1.0.0',
            'runner'           => 'github',
            'import_recipe_id' => 'rubix',
            'description'      => 'Ejecuta scrapers/rubix.py en GitHub Actions y devuelve automaticamente el CSV estandar al Catalogo de proveedores.',
        ];

        return $recipes;
    }
);
