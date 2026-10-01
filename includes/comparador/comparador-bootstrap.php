<?php
/**
 * Comparador - bootstrap.
 *
 * Inteligencia comparativa de catálogo propio + mercado observado.
 */

defined('ABSPATH') || exit;

if (!defined('SEO_COMPARADOR_VERSION')) {
    define('SEO_COMPARADOR_VERSION', '1.0.0');
}
if (!defined('SEO_COMPARADOR_PATH')) {
    define('SEO_COMPARADOR_PATH', __DIR__ . '/');
}

require_once SEO_COMPARADOR_PATH . 'comparador-db.php';
require_once SEO_COMPARADOR_PATH . 'comparador-engine.php';
require_once SEO_COMPARADOR_PATH . 'comparador-public.php';
require_once SEO_COMPARADOR_PATH . 'comparador-integration.php';
require_once SEO_COMPARADOR_PATH . 'comparador-tests.php';
require_once SEO_COMPARADOR_PATH . 'comparador-admin.php';

add_action('init', array('SEO_Comparador_DB','maybe_install'), 6);
SEO_Comparador_Public::init();
SEO_Comparador_Integration::init();
SEO_Comparador_Admin::init();
