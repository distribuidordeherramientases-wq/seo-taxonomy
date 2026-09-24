<?php
/**
 * SEO System - Bootstrap de catalogos comerciales.
 *
 * Genera fuentes publicas de inventario para plataformas comerciales que
 * consumen feeds programados por URL. No envia credenciales ni usa APIs
 * externas: cada plataforma descarga el archivo desde la URL estable.
 *
 * @package SEOSystem
 * @subpackage ImportExport\CommercialFeeds
 * @since 2.3.7
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'SEO_IE_CF_VERSION' ) ) {
    return;
}

define( 'SEO_IE_CF_VERSION', '1.0.0' );
define( 'SEO_IE_CF_DIR', __DIR__ );
define( 'SEO_IE_CF_GROUP', 'seo-system-commercial-feeds' );

$seo_ie_cf_required = [
    __DIR__ . '/core.php',
    __DIR__ . '/channels.php',
    __DIR__ . '/admin.php',
];

foreach ( $seo_ie_cf_required as $seo_ie_cf_file ) {
    if ( ! is_readable( $seo_ie_cf_file ) ) {
        error_log( '[SEO System Commercial Feeds] Falta el archivo: ' . $seo_ie_cf_file );
        return;
    }
}

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/channels.php';
require_once __DIR__ . '/admin.php';

seo_ie_cf_register_runtime();
