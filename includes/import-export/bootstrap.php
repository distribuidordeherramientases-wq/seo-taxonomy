<?php
/**
 * TaxoCommerce SEO — Bootstrap del subsistema Import/Export.
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
 * Build: 041
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'SEO_IE_BUILD' ) ) {
    return;
}

$seo_ie_required_files = [
    __DIR__ . '/core/registry.php',
    __DIR__ . '/legacy-engine.php',
    __DIR__ . '/catalogs/importer.php',
    __DIR__ . '/semantic-catalog/semantic-catalog.php',
    __DIR__ . '/commercial-feeds/bootstrap.php',
];

foreach ( $seo_ie_required_files as $seo_ie_required_file ) {
    if ( ! is_readable( $seo_ie_required_file ) ) {
        $seo_ie_message = sprintf(
            'Instalacion incompleta: no se encuentra %s.',
            basename( $seo_ie_required_file )
        );

        if (function_exists('seo_system_private_log')) {
            seo_system_private_log('error',  '[TaxoCommerce SEO Import/Export] ' . $seo_ie_message );
        }

        if ( function_exists( 'add_action' ) ) {
            add_action(
                'admin_notices',
                static function () use ( $seo_ie_message ) {
                    if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
                        return;
                    }

                    echo '<div class="notice notice-error"><p><strong>TaxoCommerce SEO - Importar/Exportar:</strong> '
                        . esc_html( $seo_ie_message )
                        . '</p></div>';
                }
            );
        }

        return;
    }
}

define( 'SEO_IE_BUILD', 41 );
define( 'SEO_IE_DIR', __DIR__ );
define( 'SEO_IE_MIGRATIONS_DIR', __DIR__ . '/migrations' );

require_once __DIR__ . '/core/registry.php';
require_once __DIR__ . '/legacy-engine.php';
require_once __DIR__ . '/category-translations.php';
// Conversion voluntaria y acotada de ofertas VEVOR internacionales aceptadas.
if ( is_readable( __DIR__ . '/suppliers/vevor-international-products.php' ) ) {
    require_once __DIR__ . '/suppliers/vevor-international-products.php';
    if ( is_readable( __DIR__ . '/suppliers/vevor-accepted-recovery.php' ) ) {
        require_once __DIR__ . '/suppliers/vevor-accepted-recovery.php';
        require_once __DIR__ . '/suppliers/vevor-accepted-queue.php';
        require_once __DIR__ . '/suppliers/vevor-normal-import.php';
        // Retire all temporary VEVOR recovery cards and manual batch tools.
        remove_action( 'seo_vevor_region_batch_card', 'seo_vevor_region_batch_card' );
        remove_action( 'admin_init', 'seo_vevor_region_batch_post' );
    }
}
require_once __DIR__ . '/catalogs/importer.php';
require_once __DIR__ . '/semantic-catalog/semantic-catalog.php';
require_once __DIR__ . '/commercial-feeds/bootstrap.php';
