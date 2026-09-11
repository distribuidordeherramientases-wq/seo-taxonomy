<?php
/**
 * Bootstrap del modulo Analista.
 *
 * Analista es la capa ejecutiva unica de inteligencia: reutiliza los motores
 * existentes de Search Console, Analytics y Trends, cruza catalogo,
 * proveedores y competencia, y devuelve una guia corta de trabajo.
 */

defined('ABSPATH') || exit;

if (!defined('SEO_ANALISTA_VERSION')) {
    define('SEO_ANALISTA_VERSION', '3.2.0');
}

$seo_analista_files = array(
    __DIR__ . '/analista-core.php',
    __DIR__ . '/analista-mercado.php',
    __DIR__ . '/analista-fuentes.php',
    __DIR__ . '/analista-google.php',
    __DIR__ . '/analista-evolucion.php',
    __DIR__ . '/analista-catalogo.php',
    __DIR__ . '/analista-literatura.php',
    __DIR__ . '/analista-competencia.php',
    __DIR__ . '/analista-decisiones.php',
    __DIR__ . '/analista-json.php',
    __DIR__ . '/analista-informe.php',
    __DIR__ . '/analista-lazy.php',
);

foreach ($seo_analista_files as $seo_analista_file) {
    if (is_readable($seo_analista_file)) {
        require_once $seo_analista_file;
    }
}

if (function_exists('seo_analista_save_settings_handler')) {
    add_action('admin_post_seo_analista_save_settings', 'seo_analista_save_settings_handler');
}
if (function_exists('seo_analista_export_json_handler')) {
    add_action('admin_post_seo_analista_export_json', 'seo_analista_export_json_handler');
}
if (function_exists('seo_analista_ajax_partial_report')) {
    add_action('wp_ajax_seo_analista_partial_report', 'seo_analista_ajax_partial_report');
}
