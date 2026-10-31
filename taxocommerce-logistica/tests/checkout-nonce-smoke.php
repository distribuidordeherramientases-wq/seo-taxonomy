<?php
/**
 * CLI-only regression for native WooCommerce checkout nonces and destinations.
 * This file is excluded from every installed Logistics plugin ZIP.
 */
define('ABSPATH', __DIR__);
function add_action() {}
function add_filter() {}
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes($value); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function wp_verify_nonce($nonce, $action) {
    return ($nonce === 'valid-review' && $action === 'update-order-review')
        || ($nonce === 'valid-checkout' && $action === 'woocommerce-process_checkout') ? 1 : false;
}
function wp_parse_args($args, $defaults) { return array_merge($defaults, (array) $args); }

require_once dirname(__DIR__) . '/includes/seo-transporte.php';

$reflection = new ReflectionMethod('TaxoCommerce_Logistica_Transporte_Costes', 'resolve_destination');
$reflection->setAccessible(true);
$original = array('destination' => array('country' => 'ES', 'state' => 'B', 'postcode' => '08001', 'city' => 'Barcelona'));
$cases = array(
    array(
        'name' => 'No nonce: ignore POST and preserve package',
        'post' => array('post_data' => 'billing_country=ES&billing_state=M&billing_postcode=28001'),
        'expected_state' => 'B',
        'expected_postcode' => '08001',
    ),
    array(
        'name' => 'Invalid review nonce: ignore POST',
        'post' => array('security' => 'invalid', 'post_data' => 'billing_state=M&billing_postcode=28001'),
        'expected_state' => 'B',
        'expected_postcode' => '08001',
    ),
    array(
        'name' => 'Valid native review nonce: replace stale destination from serialized checkout',
        'post' => array('security' => 'valid-review', 'post_data' => 'billing_country=ES&billing_state=M&billing_postcode=28001'),
        'expected_state' => 'M',
        'expected_postcode' => '28001',
    ),
    array(
        'name' => 'Valid native process checkout nonce: update shipping destination directly',
        'post' => array(
            'woocommerce-process-checkout-nonce' => 'valid-checkout',
            'ship_to_different_address' => '1',
            'shipping_country' => 'ES',
            'shipping_state' => 'PM',
            'shipping_postcode' => '07001',
        ),
        'expected_state' => 'PM',
        'expected_postcode' => '07001',
    ),
    array(
        'name' => 'Unrecognised arrays are never interpreted as checkout fields',
        'post' => array(
            'security' => 'valid-review',
            'shipping_state' => array('M'),
            'shipping_postcode' => array('28001'),
        ),
        'expected_state' => 'B',
        'expected_postcode' => '08001',
    ),
);

foreach ($cases as $case) {
    $_POST = $case['post'];
    $destination = $reflection->invoke(null, $original);
    if ($destination['state'] !== $case['expected_state'] || $destination['postcode'] !== $case['expected_postcode']) {
        fwrite(STDERR, 'FAIL: ' . $case['name'] . ' => ' . json_encode($destination) . PHP_EOL);
        exit(1);
    }
}
unset($_POST);
echo "Logistics native WooCommerce checkout nonces and destination regression passed.\n";
