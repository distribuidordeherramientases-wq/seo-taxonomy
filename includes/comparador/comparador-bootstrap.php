<?php
/**
 * Comparador - bootstrap v2.
 *
 * Ojeador recopila el mercado. Comparador analiza snapshots ya persistidos,
 * prepara un dossier interno por categoria y propone un post de mercado.
 */

defined('ABSPATH') || exit;

if (!defined('SEO_COMPARADOR_VERSION')) {
    define('SEO_COMPARADOR_VERSION', '2.0.0');
}
if (!defined('SEO_COMPARADOR_PATH')) {
    define('SEO_COMPARADOR_PATH', __DIR__ . '/');
}

require_once SEO_COMPARADOR_PATH . 'comparador-db.php';
$seo_editorial_coverage = dirname(SEO_COMPARADOR_PATH) . '/editorial/class-seo-editorial-coverage.php';
if (is_readable($seo_editorial_coverage)) {
    require_once $seo_editorial_coverage;
}

// El motor legado conserva utilidades de normalizacion, persistencia y enlace
// de posts. Su init() no se ejecuta: el flujo automatico v1 queda sustituido
// por SEO_Comparador_Service + SEO_Comparador_Process.
require_once SEO_COMPARADOR_PATH . 'comparador-engine.php';
require_once SEO_COMPARADOR_PATH . 'comparador-service.php';
require_once SEO_COMPARADOR_PATH . 'comparador-process.php';
require_once SEO_COMPARADOR_PATH . 'comparador-public.php';
require_once SEO_COMPARADOR_PATH . 'comparador-integration.php';
require_once SEO_COMPARADOR_PATH . 'comparador-tests.php';
require_once SEO_COMPARADOR_PATH . 'comparador-admin.php';

add_action('init', array('SEO_Comparador_DB','maybe_install'), 6);
SEO_Comparador_Service::init();
SEO_Comparador_Public::init();
SEO_Comparador_Integration::init();
SEO_Comparador_Admin::init();

if (!function_exists('seo_comparador_store_compare_max')) {
    function seo_comparador_store_compare_max() {
        $settings = SEO_Comparador_Engine::settings();
        return max(2, min(6, absint($settings['store_compare_max'] ?? 6)));
    }
}

