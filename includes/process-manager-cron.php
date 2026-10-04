<?php

// CLI entrypoint: block every direct web request before loading WordPress.
if ( ! defined( 'ABSPATH' ) ) {
    'cli' === PHP_SAPI || exit;
}

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

if ($argc < 2) {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Salida de diagnóstico a STDERR en un entrypoint CLI; no escribe archivos del plugin.
    fwrite(STDERR, "Falta la ruta de wp-load.php.\n");
    exit(64);
}

$wp_load = (string) $argv[1];
if ($wp_load === '' || !is_readable($wp_load)) {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Salida de diagnóstico a STDERR en un entrypoint CLI; no escribe archivos del plugin.
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
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Salida de diagnóstico a STDERR en un entrypoint CLI; no escribe archivos del plugin.
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
