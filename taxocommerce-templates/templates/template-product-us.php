<?php
/**
 * US product template dispatcher: mobile / desktop.
 *
 * Locale: en_US (HTML/hreflang en-US).
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';
require_once __DIR__ . '/template-product-stock-alert-us.php';

/*
 * Switch WordPress/WooCommerce translations for this request so core and
 * WooCommerce labels (for example add-to-cart controls) render in US English.
 */
$dht_us_locale_switched = false;
if (function_exists('switch_to_locale') && get_locale() !== 'en_US') {
    $dht_us_locale_switched = (bool) switch_to_locale('en_US');
}

/* Read by the SEO language/hreflang hooks in functions.php. */
$GLOBALS['dht_frontend_language_tag'] = 'en-US';
if (is_singular('product')) {
    $dht_us_product_url = get_permalink(get_queried_object_id());
    if ($dht_us_product_url) {
        $GLOBALS['dht_frontend_hreflang_url'] = $dht_us_product_url;
    }
}

require dht_template_device_variant_file('product-us');

if ($dht_us_locale_switched && function_exists('restore_previous_locale')) {
    restore_previous_locale();
}
