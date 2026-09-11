<?php
/**
 * SEO System - Bootstrap del Clonador PRO -> STAGING.
 *
 * @package SEOSystem
 * @subpackage Clonador
 * @since 2.5.8
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'SEO_CLONADOR_LOADED' ) ) {
    return;
}
define( 'SEO_CLONADOR_LOADED', true );
define( 'SEO_CLONADOR_VERSION', '2.5.8' );

define( 'SEO_CLONADOR_DIR', __DIR__ );

$seo_clonador_required = [
    __DIR__ . '/connections.php',
    __DIR__ . '/engine.php',
    __DIR__ . '/admin.php',
];

foreach ( $seo_clonador_required as $seo_clonador_file ) {
    if ( ! is_readable( $seo_clonador_file ) ) {
        error_log( '[SEO System Clonador] Instalacion incompleta: ' . basename( $seo_clonador_file ) );
        add_action(
            'admin_notices',
            static function () use ( $seo_clonador_file ) {
                if ( ! current_user_can( 'manage_options' ) ) {
                    return;
                }
                echo '<div class="notice notice-error"><p><strong>SEO System - Clonador:</strong> Instalacion incompleta: falta '
                    . esc_html( basename( $seo_clonador_file ) ) . '.</p></div>';
            }
        );
        return;
    }
}

require_once __DIR__ . '/connections.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/admin.php';
