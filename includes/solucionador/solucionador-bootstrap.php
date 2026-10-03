<?php
/**
 * Solucionador - bootstrap.
 *
 * Flujo editorial básico category-first.
 *
 * Academia/Entrenador -> dossier por product_cat -> cobertura -> brief -> Editora.
 * Ingeniero, Comparador y el resto de procesos editoriales son independientes.
 *
 * Una propuesta es solo un registro. Solo al aprobar explicitamente una
 * propuesta de nuevo post se crea un borrador. Nunca se publica automaticamente.
 */

defined('ABSPATH') || exit;

if (!defined('SEO_SOLUCIONADOR_VERSION')) {
    define('SEO_SOLUCIONADOR_VERSION', '0.5.7');
}
if (!defined('SEO_SOLUCIONADOR_DB_VERSION')) {
    define('SEO_SOLUCIONADOR_DB_VERSION', '0.5.7');
}
if (!defined('SEO_SOLUCIONADOR_PATH')) {
    define('SEO_SOLUCIONADOR_PATH', __DIR__ . '/');
}

require_once SEO_SOLUCIONADOR_PATH . 'solucionador-db.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-normalizer.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-sources.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-dossiers.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-coverage.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-catalog.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-posts.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-engine.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-export.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-tests.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-admin.php';

add_action('init', array('SEO_Solucionador_DB', 'maybe_install'), 6);
SEO_Solucionador_Engine::init();
SEO_Solucionador_Posts::init();
SEO_Solucionador_Export::init();
SEO_Solucionador_Admin::init();
