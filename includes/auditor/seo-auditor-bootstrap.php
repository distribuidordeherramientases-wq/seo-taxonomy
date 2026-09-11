<?php
/**
 * Auditor academico de SEO Taxonomy.
 *
 * Modulo de solo lectura: inspecciona coherencia de fuentes, relaciones y
 * homogeneidad. Nunca modifica contenido canonico ni conocimiento aprendido.
 */
defined('ABSPATH') || exit;

if (!defined('SEO_AUDITOR_VERSION')) {
    define('SEO_AUDITOR_VERSION', '0.1.0');
}
if (!defined('SEO_AUDITOR_PATH')) {
    define('SEO_AUDITOR_PATH', __DIR__ . '/');
}
if (!defined('SEO_AUDITOR_URL') && defined('SEO_SYSTEM_URL')) {
    define('SEO_AUDITOR_URL', SEO_SYSTEM_URL . 'includes/auditor/');
}

require_once SEO_AUDITOR_PATH . 'class-seo-auditor.php';
SEO_Auditor::init();
