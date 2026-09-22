<?php
/**
 * SEO System - Ojeador.
 *
 * Category-first market intelligence using structured Google Shopping results.
 * No merchant HTML scrapers and no internal cut on results returned per query.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @version 0.6.1
 */

defined('ABSPATH') || exit;

if (!defined('SEO_OJEADOR_VERSION')) {
    define('SEO_OJEADOR_VERSION', '0.6.2');
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

// Exact-product helpers remain available for a future product matching layer.
if (!function_exists('seo_ojeador_get_comparison')) {
    function seo_ojeador_get_comparison($product_id) {
        return SEO_Ojeador_DB::comparison_for_object(absint($product_id));
    }
}

if (!function_exists('seo_ojeador_get_offers')) {
    function seo_ojeador_get_offers($product_id, $limit = 8) {
        return SEO_Ojeador_DB::offers_for_object(absint($product_id), absint($limit));
    }
}

if (!function_exists('seo_ojeador_get_category_market')) {
    function seo_ojeador_get_category_market($term_id, $limit = 1000) {
        return SEO_Ojeador_DB::results_for_category(absint($term_id), absint($limit));
    }
}

if (!function_exists('seo_ojeador_sync_google')) {
    function seo_ojeador_sync_google() {
        return SEO_Ojeador_Worker::start_run('api');
    }
}
