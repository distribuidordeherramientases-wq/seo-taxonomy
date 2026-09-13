<?php
/**
 * Receta oficial SATKIT para el importador de proveedores de SEO System.
 *
 * Conserva el mapeo visual historico utilizado con SATKIT. El usuario revisa
 * la relacion entre las columnas del archivo y la plantilla comun; despues
 * se crea y conserva el CSV estandar y lo importa el motor general.
 *
 * @package SEOSystem
 * @subpackage SupplierImports
 * @version 1.2.0
 */

defined( 'ABSPATH' ) || exit;

/* Esta receta solo transforma columnas. No debe escribir productos ni programar la cola. */

add_filter(
    'seo_proveedores_import_recipes',
    static function ( $recipes ) {
        $recipes['satkit'] = [
            'id'          => 'satkit',
            'label'       => 'SATKIT - mapeo historico',
            'provider'    => 'SATKIT',
            'version'     => '1.2.0',
            'mode'        => 'mapping',
            'description' => 'Receta oficial separada del antiguo importador general. Permite revisar y ajustar la relación entre columnas antes de preparar y conservar el CSV estándar.',
        ];

        return $recipes;
    }
);
