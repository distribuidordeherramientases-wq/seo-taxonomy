<?php
/**
 * SEO Taxonomy - entrada CLI del gestor periodico de procesos.
 *
 * Ejecutar desde el cron real del hosting, idealmente cada minuto.
 */

if ('cli' !== PHP_SAPI) {
    if (!headers_sent()) {
        http_response_code(404);
    }
    exit(1);
}

$wp_load = dirname(__DIR__, 4) . '/wp-load.php';
if (!is_readable($wp_load)) {
    fwrite(STDERR, "No se puede cargar wp-load.php.\n");
    exit(66);
}

if (!defined('WP_USE_THEMES')) {
    define('WP_USE_THEMES', false);
}
if (!defined('SEO_PROCESS_MANAGER_SERVER_CRON')) {
    define('SEO_PROCESS_MANAGER_SERVER_CRON', true);
}

require_once $wp_load;

if (!function_exists('seo_process_supervisor_run_manager_window') || !function_exists('seo_process_supervisor_settings')) {
    fwrite(STDERR, "El plugin no ha cargado el gestor de procesos.\n");
    exit(69);
}

$settings = seo_process_supervisor_settings();
if (empty($settings['enabled'])) {
    exit(0);
}

$runtime = max(10, min(55, absint($settings['runtime_seconds'] ?? 45)));
seo_process_supervisor_run_manager_window('server_cron', $runtime);
exit(0);
