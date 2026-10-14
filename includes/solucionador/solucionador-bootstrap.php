<?php
/**
 * Solucionador - bootstrap.
 *
 * Flujo editorial básico category-first.
 *
 * FAQ + conocimiento consolidado de Dependiente -> dossier por product_cat -> propuesta -> Editora.
 * Ingeniero, Comparador y el resto de procesos editoriales son independientes.
 *
 * Una propuesta es solo un registro. Solo al aprobar explicitamente una
 * propuesta de nuevo post se crea un borrador. Nunca se publica automaticamente.
 */

defined('ABSPATH') || exit;

if (!defined('SEO_SOLUCIONADOR_VERSION')) {
    define('SEO_SOLUCIONADOR_VERSION', '0.7.1');
}
if (!defined('SEO_SOLUCIONADOR_DB_VERSION')) {
    define('SEO_SOLUCIONADOR_DB_VERSION', '0.7.1');
}
if (!defined('SEO_SOLUCIONADOR_PATH')) {
    define('SEO_SOLUCIONADOR_PATH', __DIR__ . '/');
}

require_once dirname(SEO_SOLUCIONADOR_PATH) . '/class-seo-editorial-service-contract.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-db.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-normalizer.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-sources.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-dossiers.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-coverage.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-catalog.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-posts.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-engine.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-process.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-export.php';
require_once SEO_SOLUCIONADOR_PATH . 'solucionador-tests.php';
// The FTP deployment to STAGING currently replaces PHP files in place.
// A concurrent request may compile an incomplete admin file into OPcache.
// Do not bring down WordPress if this optional admin UI is temporarily missing.
$seo_solucionador_admin_file = SEO_SOLUCIONADOR_PATH . 'solucionador-admin.php';
if (is_readable($seo_solucionador_admin_file)) {
    require_once $seo_solucionador_admin_file;
}

add_action('init', array('SEO_Solucionador_DB', 'maybe_install'), 6);
SEO_Solucionador_Engine::init();
SEO_Solucionador_Posts::init();
SEO_Solucionador_Export::init();
if (!class_exists('SEO_Solucionador_Admin', false) && is_readable($seo_solucionador_admin_file)) {
    // Recover a file cached while an in-place FTP transfer was incomplete.
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($seo_solucionador_admin_file, true);
    }
    // require (not require_once) deliberately retries a previously cached, empty file.
    require $seo_solucionador_admin_file;
}
if (class_exists('SEO_Solucionador_Admin', false)) {
    SEO_Solucionador_Admin::init();
} else {
    if (function_exists('seo_system_private_log')) {
        seo_system_private_log('error', '[TaxoCommerce SEO] Solucionador admin unavailable: ' . $seo_solucionador_admin_file);
    }
    add_action('admin_notices', static function () {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="notice notice-error"><p>' .
            esc_html__('Solucionador: no se pudo cargar el panel administrativo. Compruebe que el despliegue ha finalizado y recargue el archivo solucionador-admin.php.', 'taxocommerce-seo') .
            '</p></div>';
    });
}
