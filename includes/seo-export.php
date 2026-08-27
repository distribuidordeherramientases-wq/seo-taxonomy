<?php
/**
 * SEO System — Puente seguro de compatibilidad de Import/Export.
 *
 * Responsabilidad:
 * Mantener la ruta historica includes/seo-export.php usada por el bootstrap
 * principal del plugin y localizar el subsistema includes/import-export/.
 *
 * Tolerancia a instalaciones incompletas:
 * - Busca primero includes/import-export/bootstrap.php.
 * - Admite temporalmente import-export/ en la raiz del plugin.
 * - Admite una carpeta intermedia creada por algunos descompresores.
 * - Si no encuentra el subsistema, registra un aviso y permite que WordPress
 *   continue cargando en lugar de provocar un error fatal.
 *
 * Este archivo no contiene importadores, exportadores ni logica de cola.
 * No debe iniciar trabajos al ser incluido.
 *
 * @package SEOSystem
 * @subpackage ImportExport
 * @since 2.3.1
 * Build: 030
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registra un error de instalacion sin derribar WordPress.
 *
 * @param string $message Mensaje tecnico y administrativo.
 * @return void
 */
function seo_ie_bridge_installation_error( $message ) {
    error_log( '[SEO System Import/Export] ' . $message );

    if ( function_exists( 'add_action' ) ) {
        add_action(
            'admin_notices',
            static function () use ( $message ) {
                if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
                    return;
                }

                echo '<div class="notice notice-error"><p><strong>SEO System - Importar/Exportar:</strong> '
                    . esc_html( $message )
                    . '</p></div>';
            }
        );
    }
}

$seo_ie_plugin_dir = dirname( __DIR__ );
$seo_ie_candidates = [
    __DIR__ . '/import-export/bootstrap.php',
    $seo_ie_plugin_dir . '/import-export/bootstrap.php',
];

/*
 * Algunos paneles crean una carpeta intermedia al descomprimir. Solo se
 * inspecciona un nivel y siempre dentro de includes o de la raiz del plugin.
 */
foreach ( [ __DIR__, $seo_ie_plugin_dir ] as $seo_ie_search_root ) {
    $seo_ie_matches = glob( $seo_ie_search_root . '/*/import-export/bootstrap.php' );

    if ( is_array( $seo_ie_matches ) ) {
        foreach ( $seo_ie_matches as $seo_ie_match ) {
            $seo_ie_candidates[] = $seo_ie_match;
        }
    }
}

$seo_ie_bootstrap = '';
$seo_ie_plugin_real = realpath( $seo_ie_plugin_dir );

foreach ( array_unique( $seo_ie_candidates ) as $seo_ie_candidate ) {
    if ( ! is_readable( $seo_ie_candidate ) ) {
        continue;
    }

    $seo_ie_candidate_real = realpath( $seo_ie_candidate );

    if (
        false === $seo_ie_candidate_real
        || false === $seo_ie_plugin_real
        || 0 !== strpos( $seo_ie_candidate_real, $seo_ie_plugin_real . DIRECTORY_SEPARATOR )
    ) {
        continue;
    }

    $seo_ie_bootstrap = $seo_ie_candidate_real;
    break;
}

if ( '' === $seo_ie_bootstrap ) {
    seo_ie_bridge_installation_error(
        'No se encuentra import-export/bootstrap.php. Copia la carpeta import-export dentro de wp-content/plugins/seo-taxonomy/includes/. El resto del sitio sigue disponible.'
    );
    return;
}

require_once $seo_ie_bootstrap;
