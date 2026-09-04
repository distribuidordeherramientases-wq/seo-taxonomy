<?php
/**
 * Launcher CLI del supervisor de procesos SEO Taxonomy.
 *
 * No es un endpoint web. Carga WordPress y mantiene el supervisor vivo fuera
 * de WP-Cron y Action Scheduler.
 */

if ('cli' !== PHP_SAPI) {
    if (!headers_sent()) {
        http_response_code(404);
    }
    exit(1);
}

if ($argc < 5) {
    fwrite(STDERR, "Argumentos insuficientes.\n");
    exit(64);
}

$wp_load     = (string) $argv[1];
$dispatch_id = (string) $argv[2];
$dispatch_at = (int) $argv[3];
$signature   = (string) $argv[4];

if ('' === $wp_load || !is_readable($wp_load)) {
    fwrite(STDERR, "No se puede cargar wp-load.php.\n");
    exit(66);
}

if (!defined('WP_USE_THEMES')) {
    define('WP_USE_THEMES', false);
}
if (!defined('SEO_PROCESS_SUPERVISOR_CLI')) {
    define('SEO_PROCESS_SUPERVISOR_CLI', true);
}

require_once $wp_load;

if (
    !function_exists('seo_process_supervisor_dispatch_valid')
    || !function_exists('seo_process_supervisor_claim_dispatch')
    || !function_exists('seo_process_supervisor_run_loop')
) {
    fwrite(STDERR, "El plugin no ha cargado el supervisor.\n");
    exit(69);
}

if (!seo_process_supervisor_dispatch_valid($dispatch_id, $dispatch_at, $signature)) {
    exit(0);
}
if (!seo_process_supervisor_claim_dispatch($dispatch_id, $dispatch_at, $signature, 'direct_cli')) {
    exit(0);
}

seo_process_supervisor_run_loop('direct_cli', 21600);
exit(0);
