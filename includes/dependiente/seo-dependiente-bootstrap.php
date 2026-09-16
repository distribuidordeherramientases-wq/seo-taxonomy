<?php
/**
 * Dependiente 3.0 - flujo nuevo.
 *
 * Esta version no carga el runtime historico de Dependiente/Interprete.
 * Conserva intactas sus tablas de conocimiento y las consulta directamente
 * desde un pipeline nuevo, con clases y endpoints nuevos.
 */
defined('ABSPATH') || exit;

if (!defined('SEO_DEPENDIENTE_VERSION')) {
    define('SEO_DEPENDIENTE_VERSION', '3.0.0');
}
if (!defined('SEO_DEPENDIENTE_PATH')) {
    define('SEO_DEPENDIENTE_PATH', __DIR__ . '/');
}
if (!defined('SEO_DEPENDIENTE_URL')) {
    define('SEO_DEPENDIENTE_URL', SEO_SYSTEM_URL . 'includes/dependiente/');
}
if (!defined('SEO_DEPENDIENTE_V3_PATH')) {
    define('SEO_DEPENDIENTE_V3_PATH', __DIR__ . '/v3/');
}
if (!defined('SEO_DEPENDIENTE_V3_URL')) {
    define('SEO_DEPENDIENTE_V3_URL', SEO_DEPENDIENTE_URL . 'v3/');
}

require_once SEO_DEPENDIENTE_V3_PATH . 'class-dependiente-v3-db.php';
require_once SEO_DEPENDIENTE_V3_PATH . 'class-dependiente-v3-interpreter.php';
require_once SEO_DEPENDIENTE_V3_PATH . 'class-dependiente-v3-catalog.php';
require_once SEO_DEPENDIENTE_V3_PATH . 'class-dependiente-v3-api.php';
require_once SEO_DEPENDIENTE_V3_PATH . 'class-dependiente-v3-frontend.php';
require_once SEO_DEPENDIENTE_V3_PATH . 'class-dependiente-v3-app.php';

SEO_Dependiente_V3_App::boot();
