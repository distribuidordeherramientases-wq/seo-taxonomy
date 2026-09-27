<?php
/**
 * Investigador de Dependiente.
 *
 * Capa externa de conocimiento técnico por categoría.
 */
defined('ABSPATH') || exit;

if (!defined('SEO_INVESTIGADOR_VERSION')) {
    define('SEO_INVESTIGADOR_VERSION', '0.1.0');
}
if (!defined('SEO_INVESTIGADOR_PATH')) {
    define('SEO_INVESTIGADOR_PATH', __DIR__ . '/');
}

require_once SEO_INVESTIGADOR_PATH . 'class-seo-investigador-db.php';
require_once SEO_INVESTIGADOR_PATH . 'class-seo-investigador-search.php';
require_once SEO_INVESTIGADOR_PATH . 'class-seo-investigador.php';
require_once SEO_INVESTIGADOR_PATH . 'class-seo-investigador-process.php';
require_once SEO_INVESTIGADOR_PATH . 'class-seo-investigador-admin.php';

SEO_Investigador::init();
