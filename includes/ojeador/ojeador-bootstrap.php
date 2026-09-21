<?php
/**
 * SEO System - Ojeador.
 *
 * Market offer observation and price intelligence for WooCommerce products.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @version 0.1.0
 */

defined('ABSPATH') || exit;

if (!defined('SEO_OJEADOR_VERSION')) {
    define('SEO_OJEADOR_VERSION', '0.1.0');
}
if (!defined('SEO_OJEADOR_PATH')) {
    define('SEO_OJEADOR_PATH', __DIR__ . '/');
}

require_once SEO_OJEADOR_PATH . 'class-ojeador-db.php';
require_once SEO_OJEADOR_PATH . 'class-ojeador-identity.php';
require_once SEO_OJEADOR_PATH . 'class-ojeador-inspector.php';
require_once SEO_OJEADOR_PATH . 'class-ojeador-sources.php';
require_once SEO_OJEADOR_PATH . 'class-ojeador-worker.php';
require_once SEO_OJEADOR_PATH . 'class-ojeador-admin.php';

SEO_Ojeador_DB::maybe_install();
SEO_Ojeador_Worker::init();
SEO_Ojeador_Admin::init();

if (!function_exists('seo_ojeador_get_comparison')) {
    /**
     * Public internal API for Dependiente/Analista/templates.
     *
     * @param int $product_id WooCommerce product ID.
     * @return array
     */
    function seo_ojeador_get_comparison($product_id) {
        return SEO_Ojeador_DB::comparison_for_object(absint($product_id));
    }
}
