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
require dht_template_device_variant_file('category');
