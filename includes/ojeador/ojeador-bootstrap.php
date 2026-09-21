<?php
/**
 * SEO System - Ojeador.
 *
 * Market offer observation and price intelligence for WooCommerce products.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @version 0.3.0
 */

defined('ABSPATH') || exit;

if (!defined('SEO_OJEADOR_VERSION')) {
    define('SEO_OJEADOR_VERSION', '0.3.0');
}
if (!defined('SEO_OJEADOR_PATH')) {
    define('SEO_OJEADOR_PATH', __DIR__ . '/');
}

require_once SEO_OJEADOR_PATH . 'class-ojeador-db.php';
require_once SEO_OJEADOR_PATH . 'class-ojeador-identity.php';
require_once SEO_OJEADOR_PATH . 'class-ojeador-inspector.php';
require_once SEO_OJEADOR_PATH . 'class-ojeador-sources.php';
require_once SEO_OJEADOR_PATH . 'class-ojeador-worker.php';
require_once SEO_OJEADOR_PATH . 'class-ojeador-process.php';
require_once SEO_OJEADOR_PATH . 'class-ojeador-import-export.php';
require_once SEO_OJEADOR_PATH . 'class-ojeador-admin.php';

SEO_Ojeador_DB::maybe_install();
SEO_Ojeador_Worker::init();
SEO_Ojeador_Process::init();
SEO_Ojeador_Import_Export::init();
SEO_Ojeador_Admin::init();

if (!function_exists('seo_ojeador_get_comparison')) {
    /**
     * Internal API for Dependiente, Analista and templates.
     *
     * @param int $product_id WooCommerce product ID.
     * @return array
     */
    function seo_ojeador_get_comparison($product_id) {
        return SEO_Ojeador_DB::comparison_for_object(absint($product_id));
    }
}

if (!function_exists('seo_ojeador_scan_product')) {
    /**
     * Queue one product for observation. The Process Manager executes it.
     *
     * @param int $product_id WooCommerce product ID.
     * @return array|WP_Error
     */
    function seo_ojeador_scan_product($product_id) {
        return SEO_Ojeador_Worker::start_run('single', 1, absint($product_id), 'api');
    }
}
