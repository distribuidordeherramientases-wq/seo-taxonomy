<?php
/**
 * SEO System - Ojeador.
 *
 * Automatic market comparison using Google Shopping results. Ojeador no longer
 * maintains merchant HTML scrapers or per-store extraction recipes.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @version 0.5.1
 */

defined('ABSPATH') || exit;

if (!defined('SEO_OJEADOR_VERSION')) {
    define('SEO_OJEADOR_VERSION', '0.5.1');
}
if (!defined('SEO_OJEADOR_PATH')) {
    define('SEO_OJEADOR_PATH', __DIR__ . '/');
}

require_once SEO_OJEADOR_PATH . 'class-ojeador-identity.php';
require_once SEO_OJEADOR_PATH . 'class-ojeador-db.php';
require_once SEO_OJEADOR_PATH . 'class-ojeador-shopping.php';
require_once SEO_OJEADOR_PATH . 'class-ojeador-worker.php';
require_once SEO_OJEADOR_PATH . 'class-ojeador-process.php';
require_once SEO_OJEADOR_PATH . 'class-ojeador-admin.php';

SEO_Ojeador_DB::maybe_install();
SEO_Ojeador_Worker::init();
SEO_Ojeador_Process::init();
SEO_Ojeador_Admin::init();

if (!function_exists('seo_ojeador_get_comparison')) {
    /**
     * Comparison data for product templates, Dependiente or future client UI.
     *
     * @param int $product_id WooCommerce product ID.
     * @return array
     */
    function seo_ojeador_get_comparison($product_id) {
        return SEO_Ojeador_DB::comparison_for_object(absint($product_id));
    }
}

if (!function_exists('seo_ojeador_get_offers')) {
    /**
     * Current external offers for the same product.
     *
     * @param int $product_id WooCommerce product ID.
     * @param int $limit Maximum offers.
     * @return array
     */
    function seo_ojeador_get_offers($product_id, $limit = 8) {
        return SEO_Ojeador_DB::offers_for_object(absint($product_id), absint($limit));
    }
}

if (!function_exists('seo_ojeador_sync_google')) {
    /**
     * Start/continue the automatic Google Shopping catalog scan.
     *
     * @return array|WP_Error
     */
    function seo_ojeador_sync_google() {
        return SEO_Ojeador_Worker::start_run('api');
    }
}
