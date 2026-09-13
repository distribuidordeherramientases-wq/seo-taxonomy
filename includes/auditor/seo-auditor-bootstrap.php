<?php
/**
 * Auditor de datos canonicos y comportamiento de SEO Taxonomy.
 *
 * Modulo de solo lectura: audita la fuente canonica y contrasta indices, Academia
 * y aprendizaje como capas derivadas. Nunca modifica contenido ni conocimiento.
 */
defined('ABSPATH') || exit;

if (!defined('SEO_AUDITOR_VERSION')) {
    define('SEO_AUDITOR_VERSION', '0.5.0');
}
if (!defined('SEO_AUDITOR_PATH')) {
    define('SEO_AUDITOR_PATH', __DIR__ . '/');
}
if (!defined('SEO_AUDITOR_URL') && defined('SEO_SYSTEM_URL')) {
    define('SEO_AUDITOR_URL', SEO_SYSTEM_URL . 'includes/auditor/');
}

require_once SEO_AUDITOR_PATH . 'class-seo-auditor.php';
SEO_Auditor::init();
