<?php
/**
 * Template Name: Categoria WooCommerce SEO
 * Gestor de variante de categoria: movil / escritorio.
 *
 * La informacion esencial debe ser equivalente en ambas variantes;
 * solo cambia la presentacion visual.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

$amazon_category_template = __DIR__ . '/template-amazon-category.php';
if (is_readable($amazon_category_template)) {
    require_once $amazon_category_template;
}
require_once __DIR__ . '/template-vevor-affiliate.php';
require dht_template_device_variant_file('category');
