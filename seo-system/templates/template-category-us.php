<?php
/**
 * US WooCommerce category template dispatcher.
 * Locale: en-US.
 */

defined('ABSPATH') || exit;

if (!is_product_category()) {
    wp_safe_redirect(home_url('/'));
    exit;
}

require_once __DIR__ . '/template-helpers.php';

$GLOBALS['dht_frontend_language_tag'] = 'en-US';

$term = get_queried_object();
if ($term instanceof WP_Term) {
    $term_url = get_term_link($term);
    if (!is_wp_error($term_url) && $term_url) {
        $GLOBALS['dht_frontend_hreflang_url'] = (string) $term_url;
    }
}

$dht_us_locale_switched = false;
if (function_exists('switch_to_locale') && get_locale() !== 'en_US') {
    $dht_us_locale_switched = (bool) switch_to_locale('en_US');
}

$template_file = wp_is_mobile()
    ? __DIR__ . '/template-category-us-mobile.php'
    : __DIR__ . '/template-category-us-desktop.php';

if (!is_readable($template_file)) {
    wp_die(
        esc_html('US category template not found: ' . basename($template_file)),
        esc_html('Template unavailable'),
        array('response' => 500)
    );
}

require $template_file;

if ($dht_us_locale_switched && function_exists('restore_previous_locale')) {
    restore_previous_locale();
}
