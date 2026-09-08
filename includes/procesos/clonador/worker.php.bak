<?php
/**
 * Launcher CLI del Clonador para Academia.
 *
 * Se ejecuta fuera de la peticion del navegador, carga WordPress y entrega el
 * job firmado al proceso del Clonador.
 */

if ('cli' !== PHP_SAPI) {
    if (!headers_sent()) http_response_code(404);
    exit(1);
}

if ($argc < 6) {
    fwrite(STDERR, "Argumentos insuficientes.\n");
    exit(64);
}

$wp_load = (string) $argv[1];
$dispatch_id = (string) $argv[2];
$dispatch_at = (int) $argv[3];
$job_id = (string) $argv[4];
$signature = (string) $argv[5];

if ('' === $wp_load || !is_readable($wp_load)) {
    fwrite(STDERR, "No se puede cargar wp-load.php.\n");
    exit(66);
}

if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
if (!defined('SEO_CLONADOR_DIRECT_WORKER')) define('SEO_CLONADOR_DIRECT_WORKER', true);

require_once $wp_load;

if (!class_exists('SEO_Clonador_Process') || !is_callable(array('SEO_Clonador_Process', 'direct_cli_run'))) {
    fwrite(STDERR, "El proceso del Clonador no esta disponible.\n");
    exit(69);
}

SEO_Clonador_Process::direct_cli_run($dispatch_id, $dispatch_at, $job_id, $signature);
exit(0);
