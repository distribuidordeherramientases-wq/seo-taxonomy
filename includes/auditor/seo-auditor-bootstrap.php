<?php
/**
 * Auditor de SEO Taxonomy.
 *
 * El motor es compartido, pero la interfaz separa dos responsabilidades:
 * - datos/contenidos desde SEO Taxonomy -> Contenidos -> Auditor;
 * - Academia/Estudiante desde Dependiente -> Auditor Academia.
 * Nunca modifica contenido ni conocimiento.
 */
defined('ABSPATH') || exit;

if (!defined('SEO_AUDITOR_VERSION')) {
    define('SEO_AUDITOR_VERSION', '0.11.0');
}
if (!defined('SEO_AUDITOR_PATH')) {
    define('SEO_AUDITOR_PATH', __DIR__ . '/');
}
if (!defined('SEO_AUDITOR_URL') && defined('SEO_SYSTEM_URL')) {
    define('SEO_AUDITOR_URL', SEO_SYSTEM_URL . 'includes/auditor/');
}

require_once SEO_AUDITOR_PATH . 'class-seo-auditor.php';
require_once SEO_AUDITOR_PATH . 'class-seo-auditor-category-rebalance.php';
SEO_Auditor::init();
SEO_Auditor_Category_Rebalance::init();
