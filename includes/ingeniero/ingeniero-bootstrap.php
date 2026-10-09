<?php
/**
 * Ingeniero.
 *
 * Servicio independiente de conocimiento técnico y proceso editorial por categoría.
 */
defined('ABSPATH') || exit;

if (!defined('SEO_INGENIERO_VERSION')) {
    define('SEO_INGENIERO_VERSION', '0.4.0');
}
if (!defined('SEO_INGENIERO_PATH')) {
    define('SEO_INGENIERO_PATH', __DIR__ . '/');
}

require_once SEO_INGENIERO_PATH . 'class-seo-ingeniero-db.php';
require_once SEO_INGENIERO_PATH . 'class-seo-ingeniero-search.php';
require_once SEO_INGENIERO_PATH . 'class-seo-ingeniero.php';
require_once SEO_INGENIERO_PATH . 'class-seo-ingeniero-process.php';
require_once SEO_INGENIERO_PATH . 'class-seo-ingeniero-posts.php';
require_once SEO_INGENIERO_PATH . 'class-seo-ingeniero-tests.php';
require_once SEO_INGENIERO_PATH . 'class-seo-ingeniero-exchange.php';
require_once SEO_INGENIERO_PATH . 'class-seo-ingeniero-admin.php';

SEO_Ingeniero::init();
