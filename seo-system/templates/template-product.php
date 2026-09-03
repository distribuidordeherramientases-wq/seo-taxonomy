<?php
/**
 * Gestor de variante product: móvil / escritorio.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';
require_once __DIR__ . '/template-vevor-affiliate.php';
require_once __DIR__ . '/template-product-stock-alert.php';
require dht_template_device_variant_file('product');
