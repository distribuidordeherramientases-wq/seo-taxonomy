<?php
/**
 * Receta oficial AUDIOLEDCAR para SEO System.
 *
 * Version 1.1.0 - flujo Python externo con CSV artifact e importacion manual.
 *
 * Flujo:
 * 1) "AUDIOLEDCAR - Python externo" lanza el scraper en GitHub Actions.
 * 2) GitHub ejecuta scrapers/audioledcar.py con control de flujo por lotes.
 * 3) El CSV estandar queda guardado como artifact descargable de GitHub.
 * 4) El usuario descarga ese CSV y lo importa con la receta de archivo.
 * 5) No depende de callback ni de una sincronizacion automatica posterior.
 *
 * @package SEOSystem
 * @subpackage SupplierImports
 * @version 1.1.0
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
            'version'     => '1.1.0',
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
            'version'          => '1.1.0',
            'runner'           => 'github',
            'import_recipe_id' => 'audioledcar',
            'description'      => 'Ejecuta el scraper Python de Audioledcar en GitHub Actions. El CSV queda como artifact descargable para importarlo despues con la receta AUDIOLEDCAR.',
        ];

        return $recipes;
    }
);
