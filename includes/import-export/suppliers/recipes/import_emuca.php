<?php
/**
 * Receta oficial EMUCA para SEO System.
 *
 * Flujo:
 * 1) "EMUCA - Python externo" lanza el scraper en GitHub Actions.
 * 2) GitHub ejecuta scrapers/emuca.py y genera el CSV estandar.
 * 3) El callback privado devuelve el CSV a WordPress.
 * 4) El motor comun actualiza el Catalogo de proveedores / Supplier Sync.
 * 5) "EMUCA - mapeo de archivo" queda disponible como via manual.
 *
 * La receta WordPress no contiene el scraper. Su responsabilidad es registrar
 * EMUCA en los inventarios dinamicos del importador y enlazarlo con el runner
 * GitHub mediante import_recipe_id=emuca.
 *
 * @package SEOSystem
 * @subpackage SupplierImports
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Identidad de importacion EMUCA.
 *
 * Esta entrada es necesaria tambien para el callback del GitHub Runner: cuando
 * vuelve el CSV, seo_github_python_runner_callback() resuelve recipe_id=emuca
 * contra seo_proveedores_recetas_importacion().
 */
add_filter(
    'seo_proveedores_import_recipes',
    static function ( $recipes ) {
        if ( ! is_array( $recipes ) ) {
            $recipes = [];
        }

        $recipes['emuca'] = [
            'id'          => 'emuca',
            'label'       => 'EMUCA - mapeo de archivo',
            'provider'    => 'EMUCA',
            'version'     => '1.0.0',
            'mode'        => 'mapping',
            'description' => 'Importa el CSV estandar generado por el scraper Python de Emuca. Tambien admite carga manual CSV/XLS/XLSX mediante el mapeo comun.',
        ];

        return $recipes;
    }
);

/**
 * Fuente web externa EMUCA mediante GitHub Actions.
 *
 * El runner recibe recipe_id=emuca. Con "Catalogo completo" desmarcado el
 * scraper puede ejecutar la prueba corta definida en GitHub; al marcarlo puede
 * recorrer el catalogo completo segun la configuracion de emuca.py.
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
            'description'      => 'Ejecuta el scraper Python de Emuca en GitHub Actions y devuelve automaticamente el CSV estandar al Catalogo de proveedores.',
        ];

        return $recipes;
    }
);
