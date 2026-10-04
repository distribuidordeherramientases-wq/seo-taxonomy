<?php
/**
 * Comparador - bootstrap.
 *
 * Inteligencia comparativa de catálogo propio + mercado observado.
 */

defined('ABSPATH') || exit;

if (!defined('SEO_COMPARADOR_VERSION')) {
    define('SEO_COMPARADOR_VERSION', '1.2.2');
}
if (!defined('SEO_COMPARADOR_PATH')) {
    define('SEO_COMPARADOR_PATH', __DIR__ . '/');
}

require_once SEO_COMPARADOR_PATH . 'comparador-db.php';
$seo_editorial_coverage = dirname(SEO_COMPARADOR_PATH) . '/editorial/class-seo-editorial-coverage.php';
if (is_readable($seo_editorial_coverage)) {
    require_once $seo_editorial_coverage;
}
require_once SEO_COMPARADOR_PATH . 'comparador-engine.php';
require_once SEO_COMPARADOR_PATH . 'comparador-process.php';
require_once SEO_COMPARADOR_PATH . 'comparador-io.php';
require_once SEO_COMPARADOR_PATH . 'comparador-public.php';
require_once SEO_COMPARADOR_PATH . 'comparador-integration.php';
require_once SEO_COMPARADOR_PATH . 'comparador-tests.php';
require_once SEO_COMPARADOR_PATH . 'comparador-admin.php';

SEO_Comparador_Engine::init();
add_action('init', array('SEO_Comparador_DB','maybe_install'), 6);
SEO_Comparador_Public::init();
SEO_Comparador_Integration::init();
SEO_Comparador_IO::init();
SEO_Comparador_Admin::init();

if (!function_exists('seo_comparador_store_compare_max')) {
    function seo_comparador_store_compare_max() {
        $settings = SEO_Comparador_Engine::settings();
        return max(2, min(6, absint($settings['store_compare_max'] ?? 6)));
    }
}
