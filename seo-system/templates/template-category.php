<?php
/**
 * Template Name: Categoria WooCommerce SEO
 *
 * Router de categorias WooCommerce:
 * - movil      -> template-category-mobile.php
 * - escritorio -> template-category-desktop.php
 */

defined('ABSPATH') || exit;

if (!is_product_category()) {
    wp_safe_redirect(home_url('/'));
    exit;
}

require_once __DIR__ . '/template-helpers.php';

$template_file = wp_is_mobile()
    ? __DIR__ . '/template-category-mobile.php'
    : __DIR__ . '/template-category-desktop.php';

if (!is_readable($template_file)) {
    wp_die(
        'No se encuentra la plantilla de categoria: ' .
        esc_html(basename($template_file))
    );
}

require $template_file;
