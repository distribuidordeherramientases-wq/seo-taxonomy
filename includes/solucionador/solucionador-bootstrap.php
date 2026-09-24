<?php
/**
 * Solucionador - bootstrap.
 *
 * Convierte senales de Dependiente/Interprete, Analista, Auditor y Comentarista
 * en problemas canonicos. Comprueba cobertura editorial y propone posts.
 *
 * Una propuesta es solo un registro. Solo al aprobar una propuesta de nuevo
 * post se crea un borrador, ya relacionado con product_cat y Vocabulary.
 */

defined('ABSPATH') || exit;

if (!defined('SEO_SOLUCIONADOR_VERSION')) {
    define('SEO_SOLUCIONADOR_VERSION', '0.2.1');
}
if (!defined('SEO_SOLUCIONADOR_DB_VERSION')) {
    define('SEO_SOLUCIONADOR_DB_VERSION', '0.2.0');
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
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-admin.php';

add_action('init', array('SEO_Solucionador_DB', 'maybe_install'), 6);
SEO_Solucionador_Posts::init();
SEO_Solucionador_Export::init();
SEO_Solucionador_Admin::init();
