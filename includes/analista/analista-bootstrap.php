<?php
/**
 * Bootstrap del modulo Analista.
 *
 * El directorio se mantiene como /includes/anlista/ por compatibilidad con la
 * estructura solicitada. El modulo expone un informe de mercado basado en los
 * datos propios ya almacenados por Google Intelligence y deja preparado un
 * adaptador para incorporar posiciones externas de competidores sin acoplar el
 * informe a un proveedor concreto.
 */

defined('ABSPATH') || exit;

if (!defined('SEO_ANALISTA_VERSION')) {
    define('SEO_ANALISTA_VERSION', '1.0.0');
}

$seo_analista_files = array(
    __DIR__ . '/analista-core.php',
    __DIR__ . '/analista-mercado.php',
    __DIR__ . '/analista-informe.php',
);

foreach ($seo_analista_files as $seo_analista_file) {
    if (is_readable($seo_analista_file)) {
        require_once $seo_analista_file;
    }
}

if (function_exists('seo_analista_save_settings_handler')) {
    add_action('admin_post_seo_analista_save_settings', 'seo_analista_save_settings_handler');
}
