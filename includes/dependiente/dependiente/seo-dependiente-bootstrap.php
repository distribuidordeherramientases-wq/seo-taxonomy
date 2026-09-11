<?php
/**
 * Bootstrap del modulo Dependiente de SEO Taxonomy.
 */
defined('ABSPATH') || exit;

define('SEO_DEPENDIENTE_VERSION', '0.2.13');
define('SEO_DEPENDIENTE_DB_VERSION', '0.2.0');
define('SEO_DEPENDIENTE_PATH', __DIR__ . '/');
define('SEO_DEPENDIENTE_URL', SEO_SYSTEM_URL . 'includes/dependiente/');

require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-index.php';
require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-semantics.php';
require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-help.php';
// Amazon del Dependiente se carga antes del API principal. El bloque 1C funciona
// con Partner Tag; Creators API es un enriquecimiento opcional.
require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-amazon.php';
require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-api.php';
require_once SEO_DEPENDIENTE_PATH . 'entrenador/seo-dependiente-entrenador.php';
require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-training-quality.php';
// v0.2.13: Auditor academico aislado como modulo hermano en includes/auditor.
$seo_auditor_bootstrap = dirname(rtrim(SEO_DEPENDIENTE_PATH, '/\\')) . '/auditor/seo-auditor-bootstrap.php';
if (is_readable($seo_auditor_bootstrap)) {
    require_once $seo_auditor_bootstrap;
}
require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-insights.php';
require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-reset.php';
require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-knowledge-transfer.php';
require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-admin.php';
require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-core.php';

SEO_Dependiente_Plugin::instance();
