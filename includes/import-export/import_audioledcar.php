<?php
/**
 * Receta oficial AUDIOLEDCAR para SEO System.
 *
 * Version 1.0.0 - flujo Python externo + importacion automatica por callback.
 *
 * Flujo:
 * 1) "AUDIOLEDCAR - Python externo" lanza el scraper en GitHub Actions.
 * 2) GitHub ejecuta scrapers/audioledcar.py.
 * 3) El CSV estandar se envia al callback privado de WordPress.
 * 4) El importador comun lo entrega a Supplier Sync V2.
 * 5) "AUDIOLEDCAR - mapeo de archivo" queda disponible como via manual.
 *
 * @package SEOSystem
 * @subpackage SupplierImports
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;


/**
 * Receta de importacion manual de AUDIOLEDCAR.
 */
add_filter(
    'seo_proveedores_import_recipes',
    static function ( $recipes ) {
        if ( ! is_array( $recipes ) ) {
            $recipes = [];
        }

        $recipes['audioledcar'] = [
            'id'          => 'audioledcar',
            'label'       => 'AUDIOLEDCAR - mapeo de archivo',
            'provider'    => 'AUDIOLEDCAR',
            'version'     => '1.0.0',
            'mode'        => 'mapping',
            'description' => 'Importa el CSV estandar generado por el scraper Python de Audioledcar. Tambien admite carga manual mediante el mapeo comun.',
        ];

        return $recipes;
    }
);


/**
 * Fuente web externa AUDIOLEDCAR mediante GitHub Actions.
 *
 * El runner recibe recipe_id=audioledcar. En el workflow suministrado, si se
 * deja desmarcado "Catalogo completo" se limita a 10 productos para una prueba
 * segura; al marcarlo se procesa el catalogo completo.
 */
add_filter(
    'seo_supplier_external_web_recipes',
    static function ( $recipes ) {
        if ( ! is_array( $recipes ) ) {
            $recipes = [];
        }

        $recipes['audioledcar_github'] = [
            'id'               => 'audioledcar_github',
            'label'            => 'AUDIOLEDCAR - Python externo',
            'provider'         => 'AUDIOLEDCAR',
            'version'          => '1.0.0',
            'runner'           => 'github',
            'import_recipe_id' => 'audioledcar',
            'description'      => 'Ejecuta el scraper Python de Audioledcar en GitHub Actions y devuelve automaticamente el CSV al Supplier Sync V2.',
        ];

        return $recipes;
    }
);
