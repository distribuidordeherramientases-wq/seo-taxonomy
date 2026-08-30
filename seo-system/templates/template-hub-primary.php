<?php
/**
 * Template Name: Hub primario SEO
 * Gestor de variante hub-primary: móvil / escritorio.
 *
 * Hub primario: distribuye hacia hubs secundarios y categorias vinculadas directamente.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';
require dht_template_device_variant_file('hub-primary');
