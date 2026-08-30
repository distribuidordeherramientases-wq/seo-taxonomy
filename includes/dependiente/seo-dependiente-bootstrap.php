<?php
/**
 * Bootstrap del modulo Dependiente de SEO Taxonomy.
 */
defined('ABSPATH') || exit;

define('SEO_DEPENDIENTE_VERSION', '0.1.6');
define('SEO_DEPENDIENTE_DB_VERSION', '0.1.1');
define('SEO_DEPENDIENTE_PATH', __DIR__ . '/');
define('SEO_DEPENDIENTE_URL', SEO_SYSTEM_URL . 'includes/dependiente/');

require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-index.php';
require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-api.php';
require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-admin.php';
require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-core.php';

SEO_Dependiente_Plugin::instance();
