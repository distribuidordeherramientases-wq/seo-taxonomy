<?php
/**
 * SEO System — Bootstrap del subsistema Import/Export.
 *
 * Responsabilidad:
 * Cargar una sola vez el registro, el motor compatible, proveedores y cola.
 * Es el unico punto de entrada interno del subsistema.
 *
 * Seguridad de instalacion:
 * Comprueba que los archivos obligatorios sean legibles antes de cargarlos.
 * Una subida incompleta desactiva solo este subsistema y no derriba WordPress.
 *
 * Este archivo no debe procesar CSV, escribir en la base de datos ni iniciar
 * trabajos por el mero hecho de ser incluido.
 *
 * @package SEOSystem
 * @subpackage ImportExport
 * @since 2.3.2
 * Build: 031
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'SEO_IE_BUILD' ) ) {
    return;
}

$seo_ie_required_files = [
    __DIR__ . '/core/registry.php',
    __DIR__ . '/legacy-engine.php',
    __DIR__ . '/catalogs/importer.php',
];

foreach ( $seo_ie_required_files as $seo_ie_required_file ) {
    if ( ! is_readable( $seo_ie_required_file ) ) {
        $seo_ie_message = sprintf(
            'Instalacion incompleta: no se encuentra %s.',
            basename( $seo_ie_required_file )
        );

        error_log( '[SEO System Import/Export] ' . $seo_ie_message );

        if ( function_exists( 'add_action' ) ) {
            add_action(
                'admin_notices',
                static function () use ( $seo_ie_message ) {
                    if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
                        return;
                    }

                    echo '<div class="notice notice-error"><p><strong>SEO System - Importar/Exportar:</strong> '
                        . esc_html( $seo_ie_message )
                        . '</p></div>';
                }
            );
        }

        return;
    }
}

define( 'SEO_IE_BUILD', 31 );
define( 'SEO_IE_DIR', __DIR__ );
define( 'SEO_IE_MIGRATIONS_DIR', __DIR__ . '/migrations' );

require_once __DIR__ . '/core/registry.php';
require_once __DIR__ . '/legacy-engine.php';
require_once __DIR__ . '/catalogs/importer.php';
