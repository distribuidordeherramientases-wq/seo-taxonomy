<?php
/**
 * Gestor de variante product: móvil / escritorio.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';
require_once __DIR__ . '/template-product-stock-alert.php';
require_once __DIR__ . '/template-pilot-market.php';
$dht_pilot_market = '';
$product_id = absint(get_queried_object_id());
$product_categories = $product_id ? get_the_terms($product_id, 'product_cat') : array();
if (is_array($product_categories)) {
    foreach ($product_categories as $candidate) {
        $dht_pilot_market = dht_pilot_market_for_term($candidate);
        if ($dht_pilot_market !== '') { break; }
    }
}
if ($dht_pilot_market !== '') {
    require __DIR__ . '/template-product-' . strtolower($dht_pilot_market) . '.php';
    return;
}
require dht_template_device_variant_file('product');
