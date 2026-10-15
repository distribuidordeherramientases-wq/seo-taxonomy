<?php
/**
 * Template Name: US WooCommerce SEO Category
 *
 * US category router:
 * - mobile  -> template-category-us-mobile.php
 * - desktop -> template-category-us-desktop.php
 *
 * WordPress locale: en_US
 * HTML / hreflang language tag: en-US
 */

defined('ABSPATH') || exit;

if (!is_product_category()) {
    wp_safe_redirect(home_url('/'));
    exit;
}

require_once __DIR__ . '/template-helpers.php';

$GLOBALS['dht_frontend_language_tag'] = 'en-US';
$GLOBALS['dht_frontend_market'] = 'us';

$current_term = get_queried_object();
if ($current_term instanceof WP_Term) {
    $current_term_url = get_term_link($current_term);
    if (!is_wp_error($current_term_url)) {
        $GLOBALS['dht_frontend_hreflang_url'] = (string) $current_term_url;
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
    if ($dht_us_locale_switched && function_exists('restore_previous_locale')) {
        restore_previous_locale();
    }

    wp_die(
        esc_html(
            sprintf(
                'US category template not found: %s',
                basename($template_file)
            )
        )
    );
}

require $template_file;

if ($dht_us_locale_switched && function_exists('restore_previous_locale')) {
    restore_previous_locale();
}
