<?php
/**
 * Receta oficial AREBOS para SEO System.
 *
 * Version 1.0.0 - flujo Python externo + importacion asistida.
 *
 * Esta receta ya NO rastrea AREBOS desde el hosting de WordPress.
 *
 * Flujo:
 * 1) "AREBOS - Python externo" lanza el scraper en GitHub Actions.
 * 2) GitHub genera el CSV estandar de AREBOS.
 * 3) Mientras el callback automatico no se utilice, el CSV puede descargarse
 *    como Artifact desde GitHub.
 * 4) "AREBOS - mapeo de archivo" permite subir ese CSV/XLS/XLSX manualmente.
 * 5) El importador comun lo entrega a el motor comun de catalogo.
 *
 * @package SEOSystem
 * @subpackage SupplierImports
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;


/**
 * Receta de importacion manual de AREBOS.
 *
 * Acepta los archivos externos que soporte el importador comun
 * (CSV/XLS/XLSX) y muestra el mapeo visual de columnas.
 */
add_filter(
    'seo_proveedores_import_recipes',
    static function ( $recipes ) {
        if ( ! is_array( $recipes ) ) {
            $recipes = [];
        }

        $recipes['arebos'] = [
            'id'          => 'arebos',
            'label'       => 'AREBOS - mapeo de archivo',
            'provider'    => 'AREBOS',
            'version'     => '1.0.0',
            'mode'        => 'mapping',
            'description' => 'Importa un CSV/XLS/XLSX de AREBOS mediante el mapeo visual comun. El archivo puede proceder del scraper Python ejecutado en GitHub Actions.',
        ];

        return $recipes;
    }
);


/**
 * Fuente web externa AREBOS.
 *
 * Hace que AREBOS aparezca en "Obtener catalogo desde la web" y delega el
 * scraping en GitHub Actions. El runner debe reconocer recipe_id=arebos.
 *
 * import_recipe_id enlaza el CSV resultante con la receta de importacion
 * manual "arebos".
 */
add_filter(
    'seo_supplier_external_web_recipes',
    static function ( $recipes ) {
        if ( ! is_array( $recipes ) ) {
            $recipes = [];
        }

        $recipes['arebos_github'] = [
            'id'               => 'arebos_github',
            'label'            => 'AREBOS - Python externo',
            'provider'         => 'AREBOS',
            'version'          => '1.0.0',
            'runner'           => 'github',
            'import_recipe_id' => 'arebos',
            'description'      => 'Ejecuta el scraper Python de AREBOS en GitHub Actions. El CSV generado puede importarse despues con la receta AREBOS - mapeo de archivo.',
        ];

        return $recipes;
    }
);
