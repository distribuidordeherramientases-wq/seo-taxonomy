<?php
/**
 * Tests for the downloadable blank JSON and secure import into consent options.
 * No WordPress bootstrap, network or database is required.
 */
define( 'ABSPATH', __DIR__ . '/' );
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function esc_url_raw( $url, $protocols = array( 'http', 'https' ) ) {
    return preg_match( '~^https://~i', $url ) && in_array( 'https', $protocols, true ) ? $url : '';
}
function wp_parse_url( $url ) { return parse_url( $url ); }
function assert_json( $success, $message ) {
    if ( ! $success ) {
        fwrite( STDERR, "FAIL: " . $message . PHP_EOL );
        exit( 1 );
    }
}
require dirname( __DIR__ ) . '/includes/class-taxocommerce-insights-consent.php';
require dirname( __DIR__ ) . '/includes/class-taxocommerce-insights-consent-seed.php';

$io = 'TaxoCommerce_Insights_Consent_Seed';
$template = $io::blank_template();
assert_json( $template['schema'] === 1, 'Template version' );
assert_json( $template['option'] === 'taxocommerce_insights_consent_settings', 'Correct option' );
assert_json( $template['enabled'] === 0 && $template['legal_reviewed'] === 0
    && $template['revision'] === 0 && $template['cookie_policy_url'] === '', 'No activation in template' );
assert_json( count( $template['texts'] ) === 5, 'All five languages' );
foreach ( $template['texts'] as $language => $fields ) {
    assert_json( count( $fields ) === 13, "All 13 fields in $language" );
    foreach ( $fields as $field => $value ) {
        assert_json( $value === '', "Empty editable field $language/$field" );
    }
}
assert_json( $io::parse_import( json_encode( $template ) ) === $template,
    'Download template is a valid import' );
$seed = $io::seed();
assert_json( is_array( $seed ) && false !== $io::parse_import( json_encode( $seed ) ),
    'Previously prepared five-language seed imports without editing' );

$old = TaxoCommerce_Insights_Consent::defaults();
$old['texts']['es']['accept'] = 'Botón personalizado';
$old['cookie_policy_url'] = 'https://shop.test/old-policy/';
$payload = $template;
foreach ( array_keys( TaxoCommerce_Insights_Consent::languages() ) as $language ) {
    $payload['texts'][ $language ]['intro'] = 'Nuevo texto ' . strtoupper( $language );
    $payload['texts'][ $language ]['legal'] = 'Condiciones de cookies ' . strtoupper( $language );
}
$payload['texts']['de']['accept'] = 'Alle zulassen';
$payload['cookie_policy_url'] = 'https://shop.test/new-policy/';
$payload['enabled'] = 1;
$payload['legal_reviewed'] = 1;
$payload['revision'] = 999;
$valid = $io::parse_import( json_encode( $payload ) );
assert_json( is_array( $valid ), 'Filled import validates' );
$new = $io::merge_import( $old, $valid );
assert_json( is_array( $new ), 'Disabled form accepts import' );
foreach ( array_keys( TaxoCommerce_Insights_Consent::languages() ) as $language ) {
    assert_json( $new['texts'][ $language ]['intro'] === $payload['texts'][ $language ]['intro'],
        'Imported intro ' . $language );
    assert_json( $new['texts'][ $language ]['legal'] === $payload['texts'][ $language ]['legal'],
        'Imported legal text ' . $language );
}
assert_json( $new['texts']['de']['accept'] === 'Alle zulassen', 'Nonempty custom button imported' );
assert_json( $new['texts']['es']['accept'] === 'Botón personalizado', 'Empty JSON retains existing labels' );
assert_json( $new['cookie_policy_url'] === 'https://shop.test/new-policy/', 'HTTPS policy URL imported' );
assert_json( $new['enabled'] === 0 && $new['legal_reviewed'] === 0
    && $new['revision'] === 1, 'JSON cannot enable/review/change revision' );
assert_json( $io::merge_import( $old, $io::parse_import( json_encode( $template ) ) ) === $old,
    'Empty JSON does not erase existing values' );
$active = $old;
$active['enabled'] = 1;
assert_json( false === $io::merge_import( $active, $valid ), 'Reject import on active banner' );
$active['enabled'] = 0;
$active['legal_reviewed'] = 1;
assert_json( false === $io::merge_import( $active, $valid ), 'Reject import on reviewed legal text' );

$invalid = $payload;
unset( $invalid['texts']['fr']['legal'] );
assert_json( false === $io::parse_import( json_encode( $invalid ) ), 'Reject missing field' );
$invalid = $payload;
$invalid['texts']['nl']['accept'] = array( 'unsafe' );
assert_json( false === $io::parse_import( json_encode( $invalid ) ), 'Reject typed array injection' );
$invalid = $payload;
$invalid['texts']['fr']['legal'] = str_repeat( 'z', 4097 );
assert_json( false === $io::parse_import( json_encode( $invalid ) ), 'Reject oversized legal text' );
$invalid = $payload;
$invalid['texts']['de']['accept'] = str_repeat( 'z', 301 );
assert_json( false === $io::parse_import( json_encode( $invalid ) ), 'Reject oversized button' );
$invalid = $payload;
$invalid['option'] = 'siteurl';
assert_json( false === $io::parse_import( json_encode( $invalid ) ), 'Cannot edit other WordPress options' );
$invalid = $payload;
$invalid['texts']['unexpected'] = array();
assert_json( false === $io::parse_import( json_encode( $invalid ) ), 'Reject unrecognized language' );
assert_json( false === $io::parse_import( '{"schema":' ), 'Reject malformed JSON' );
assert_json( false === $io::parse_import( str_repeat( 'a', 65537 ) ), 'Reject oversized JSON' );

$bad_url = $payload;
$bad_url['cookie_policy_url'] = 'http://shop.test/cookies/';
assert_json( false === $io::merge_import( $old, $bad_url ), 'Reject non-HTTPS policy URL' );
$bad_url['cookie_policy_url'] = 'https://user:secret@shop.test/cookies/';
assert_json( false === $io::merge_import( $old, $bad_url ), 'Reject URL credentials' );
$bad_url['cookie_policy_url'] = 'javascript:alert(1)';
assert_json( false === $io::merge_import( $old, $bad_url ), 'Reject non-HTTP scheme' );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-taxocommerce-insights-consent-seed.php' );
assert_json( strpos( $source, "admin_post_' . self::IMPORT_ACTION" ) !== false, 'Registered import endpoint' );
assert_json( strpos( $source, 'check_admin_referer( self::IMPORT_NONCE )' ) !== false, 'Import nonce' );
assert_json( strpos( $source, 'is_uploaded_file(' ) !== false, 'Uploaded file verification' );
assert_json( strpos( $source, 'enctype="multipart/form-data"' ) !== false, 'Real multipart import form' );
assert_json( strpos( $source, 'update_option( TaxoCommerce_Insights_Consent::OPTION, $next' ) !== false,
    'Import persists into correct wp_options key' );
echo 'Consent JSON roundtrip PASS: blank template, 65 fields, HTTPS, safety and wp_options import' . PHP_EOL;
