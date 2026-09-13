<?php
/**
 * Launcher CLI de la Academia automática.
 *
 * Se ejecuta fuera de WP-Cron/Action Scheduler, carga WordPress y entrega un
 * único ciclo al motor. El propio motor encadena el siguiente ciclo.
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
$not_before  = (int) $argv[3];
$signature   = (string) $argv[4];

if ('' === $wp_load || !is_readable($wp_load)) {
    fwrite(STDERR, "No se puede cargar wp-load.php.\n");
    exit(66);
}

if (!defined('WP_USE_THEMES')) {
    define('WP_USE_THEMES', false);
}
if (!defined('SEO_ACADEMY_DIRECT_WORKER')) {
    define('SEO_ACADEMY_DIRECT_WORKER', true);
}

require_once $wp_load;

if (!class_exists('SEO_Dependiente_Entrenador') || !is_callable(array('SEO_Dependiente_Entrenador', 'direct_cli_run'))) {
    fwrite(STDERR, "La Academia no ha cargado el motor directo.\n");
    exit(69);
}

SEO_Dependiente_Entrenador::direct_cli_run($dispatch_id, $not_before, $signature);
exit(0);
