<?php
/**
 * Solucionador - bootstrap.
 *
 * Capa unica de decision editorial. Consume conclusiones de los servicios
 * especialistas, cruza cobertura y oportunidad, prioriza actuaciones y prepara
 * briefs. Los editores especializados siguen siendo el lugar de ejecucion.
 *
 * Una propuesta es solo un registro. Solo al aprobar explicitamente una
 * propuesta de nuevo post se crea un borrador. Nunca se publica automaticamente.
 */

defined('ABSPATH') || exit;

if (!defined('SEO_SOLUCIONADOR_VERSION')) {
    define('SEO_SOLUCIONADOR_VERSION', '0.4.2');
}
if (!defined('SEO_SOLUCIONADOR_DB_VERSION')) {
    define('SEO_SOLUCIONADOR_DB_VERSION', '0.4.0');
}
if (!defined('SEO_SOLUCIONADOR_PATH')) {
    define('SEO_SOLUCIONADOR_PATH', __DIR__ . '/');
}

require_once SEO_SOLUCIONADOR_PATH . 'solucionador-db.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-normalizer.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-sources.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-coverage.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-catalog.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-posts.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-engine.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-export.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-tests.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-admin.php';

add_action('init', array('SEO_Solucionador_DB', 'maybe_install'), 6);
SEO_Solucionador_Posts::init();
SEO_Solucionador_Export::init();
SEO_Solucionador_Admin::init();
