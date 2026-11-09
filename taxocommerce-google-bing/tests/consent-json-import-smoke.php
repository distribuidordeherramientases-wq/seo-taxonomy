<?php
/** No-network regression for the complete, restorable cookie-consent JSON. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'OBJECT', 'OBJECT' );
$GLOBALS['policy_pages'] = array();
$GLOBALS['policy_markets'] = array();
class WP_Post {
    public $ID;
    public $post_status;
    public function __construct( $id, $status ) {
        $this->ID = $id;
        $this->post_status = $status;
    }
}
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_title( $slug ) { return strtolower( trim( preg_replace( '/[^a-zA-Z0-9-]+/', '-', $slug ), '-' ) ); }
function esc_url_raw( $url, $protocols = array( 'http', 'https' ) ) {
    return preg_match( '~^https://~i', $url ) && in_array( 'https', $protocols, true ) ? $url : '';
}
function wp_parse_url( $url ) { return parse_url( $url ); }
function get_page_by_path( $slug, $format = OBJECT, $type = 'page' ) {
    return $GLOBALS['policy_pages'][ $slug ] ?? null;
}
function get_post_meta( $id, $key, $single = true ) {
    return $GLOBALS['policy_markets'][ $id ] ?? '';
}
function get_permalink( $page ) { return 'https://shop.test/legal/' . (int) $page->ID . '/'; }
function is_wp_error( $value ) { return false; }
function assert_json( $success, $message ) {
    if ( ! $success ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}
require dirname( __DIR__ ) . '/includes/class-taxocommerce-insights-consent.php';
require dirname( __DIR__ ) . '/includes/class-taxocommerce-insights-consent-seed.php';
$io = 'TaxoCommerce_Insights_Consent_Seed';
$con = 'TaxoCommerce_Insights_Consent';

$template = $io::blank_template();
assert_json( 2 === $template['schema'], 'Version 2 blank template' );
assert_json( 'merge' === $template['mode'], 'Blank template is a non-destructive merge' );
assert_json( $con::OPTION === $template['option'], 'Correct WordPress option' );
assert_json( 0 === $template['enabled'] && 0 === $template['legal_reviewed']
    && '' === $template['cookie_policy_url'], 'Disabled blank template' );
assert_json( count( $template['texts'] ) === 5, 'Five languages' );
assert_json( count( $template['cookie_policy_pages'] ) === 5, 'Five country-linked legal pages' );
foreach ( $template['cookie_policy_pages'] as $slug ) {
    assert_json( '' === $slug, 'Blank country-page field is editable' );
}
foreach ( $template['texts'] as $lang => $fields ) {
    assert_json( 13 === count( $fields ), 'All 13 fields in ' . $lang );
    foreach ( $fields as $field => $value ) {
        assert_json( '' === $value, 'Empty editable field ' . $lang . '/' . $field );
    }
}
assert_json( $template === $io::parse_import( json_encode( $template ) ), 'Template re-imports unchanged' );
$prepared_file = dirname( __DIR__ ) . '/config/consent-settings-complete-20261109.json';
$prepared = $io::parse_import( file_get_contents( $prepared_file ) );
assert_json( is_array( $prepared ) && 2 === $prepared['schema']
    && 'replace' === $prepared['mode'], 'Ready-to-import full JSON valid' );
assert_json( count( $prepared['texts'] ) === 5
    && count( $prepared['cookie_policy_pages'] ) === 5, 'Prepared JSON contains all countries and texts' );
foreach ( $prepared['texts'] as $language => $fields ) {
    foreach ( $fields as $field => $value ) {
        assert_json( '' !== trim( $value ), 'Prepared JSON complete: ' . $language . '/' . $field );
    }
}
$seed = $io::seed();
assert_json( is_array( $seed ) && is_array( $io::parse_import( json_encode( $seed ) ) ), 'Legacy v1 seed accepted' );
$legacy = $io::parse_import( json_encode( $seed ) );
assert_json( 'merge' === $legacy['mode'] && empty( $legacy['enabled'] ), 'Legacy never activates a banner' );

$old = $con::defaults();
$old['texts']['es']['accept'] = 'Botón personalizado';
$old['cookie_policy_url'] = 'https://shop.test/old-policy/';
$payload = $template;
foreach ( array_keys( $con::languages() ) as $lang ) {
    $payload['texts'][ $lang ]['intro'] = 'Nuevo texto ' . $lang;
    $payload['texts'][ $lang ]['legal'] = 'Condiciones de cookies ' . $lang;
}
$payload['texts']['de']['accept'] = 'Alle zulassen';
$payload['cookie_policy_url'] = 'https://shop.test/new-policy/';
$payload['revision'] = 999999;
$parsed = $io::parse_import( json_encode( $payload ) );
assert_json( is_array( $parsed ), 'Filled JSON validates' );
$next = $io::merge_import( $old, $parsed );
assert_json( is_array( $next ), 'Disabled form accepts complete JSON' );
foreach ( array_keys( $con::languages() ) as $lang ) {
    assert_json( $payload['texts'][ $lang ]['intro'] === $next['texts'][ $lang ]['intro'], 'Intro ' . $lang );
    assert_json( $payload['texts'][ $lang ]['legal'] === $next['texts'][ $lang ]['legal'], 'Legal ' . $lang );
}
assert_json( $next['texts']['de']['accept'] === 'Alle zulassen', 'New button imported' );
assert_json( $next['texts']['es']['accept'] === 'Botón personalizado', 'Empty merge preserves button' );
assert_json( $next['cookie_policy_url'] === 'https://shop.test/new-policy/', 'HTTPS URL imported' );
assert_json( $next['revision'] === 1, 'Old JSON revision is never trusted' );
assert_json( $next['cookie_policy_pages'] === $con::policy_page_defaults(), 'Five mapped policies persist' );
assert_json( $io::merge_import( $old, $io::parse_import( json_encode( $template ) ) ) === $old,
    'Blank template does not remove existing fields' );
$custom = $old;
$custom['cookie_policy_pages']['NL'] = 'cookiebeleid-custom';
assert_json( $io::merge_import( $custom, $io::parse_import( json_encode( $template ) ) ) === $custom,
    'Blank template retains custom country page reference' );

// A complete export uses replace and faithfully restores all editable settings.
$snapshot = array_merge( $old, array(
    'schema' => 2,
    'option' => $con::OPTION,
    'mode' => 'replace',
) );
$snapshot['texts']['fr']['accept'] = 'Tout accepter (modifié)';
$snapshot['cookie_policy_pages']['FR'] = 'politique-de-cookies-fr-2';
$snapshot['enabled'] = 0;
$snapshot['legal_reviewed'] = 0;
$full = $io::parse_import( json_encode( $snapshot ) );
assert_json( is_array( $full ), 'Complete export JSON validates' );
$recovered = $io::merge_import( $old, $full );
assert_json( 'politique-de-cookies-fr-2' === $recovered['cookie_policy_pages']['FR'], 'Restores country page' );
assert_json( $snapshot['texts']['fr']['accept'] === $recovered['texts']['fr']['accept'], 'Restores custom button' );
assert_json( $recovered['cookie_policy_url'] === $snapshot['cookie_policy_url'], 'Restores global fallback' );
$snapshot['cookie_policy_url'] = '';
$clear = $io::merge_import( $old, $io::parse_import( json_encode( $snapshot ) ) );
assert_json( $clear['cookie_policy_url'] === '', 'Replace can clear obsolete global URL' );
$snapshot['texts']['es']['accept'] = '';
$clear = $io::merge_import( $old, $io::parse_import( json_encode( $snapshot ) ) );
assert_json( $clear['texts']['es']['accept'] === '', 'Replace restores empty draft field' );

// A restorable active banner requires real published, correct-country pages.
$published = $con::policy_page_defaults();
$id = 20;
foreach ( $published as $market => $slug ) {
    $page = new WP_Post( ++$id, 'publish' );
    $GLOBALS['policy_pages'][ $slug ] = $page;
    $GLOBALS['policy_markets'][ $id ] = $market;
}
$active = array_merge( $old, array(
    'schema' => 2,
    'option' => $con::OPTION,
    'mode' => 'replace',
    'enabled' => 1,
    'legal_reviewed' => 1,
) );
$active_payload = $io::parse_import( json_encode( $active ) );
assert_json( false === $io::merge_import( $old, $active_payload ), 'Admin legal confirmation required' );
$restored = $io::merge_import( $old, $active_payload, true );
assert_json( is_array( $restored ) && $restored['enabled'] === 1
    && $restored['legal_reviewed'] === 1, 'Complete, authorized live backup restores both switches' );
assert_json( $con::policies_ready( $restored ), 'All five policy links ready' );
assert_json( 'https://shop.test/legal/22/' === $con::policy_url_for_market( 'US', $restored ),
    'US policy resolves locally from slug' );

$GLOBALS['policy_pages'][ $published['NL'] ]->post_status = 'draft';
assert_json( ! $con::policies_ready( $active ), 'A draft policy cannot enable consent' );
assert_json( false === $io::merge_import( $old, $active_payload, true ),
    'Import cannot activate consent with draft policy' );
$GLOBALS['policy_pages'][ $published['NL'] ]->post_status = 'publish';
$GLOBALS['policy_markets'][ 23 ] = 'US';
assert_json( ! $con::policies_ready( $active ), 'Wrong country metadata cannot be linked' );
assert_json( false === $io::merge_import( $old, $active_payload, true ),
    'Import refuses a policy assigned to another country' );
$GLOBALS['policy_markets'][ 23 ] = 'FR';
$enabled_before = $old;
$enabled_before['enabled'] = 1;
$enabled_before['legal_reviewed'] = 1;
$next_disabled = $io::merge_import( $enabled_before, $parsed );
assert_json( is_array( $next_disabled ) && $next_disabled['enabled'] === 0
    && $next_disabled['legal_reviewed'] === 0, 'An explicit disabled backup can turn tracking off' );

$invalid = $payload;
unset( $invalid['texts']['fr']['legal'] );
assert_json( false === $io::parse_import( json_encode( $invalid ) ), 'Missing text field rejected' );
$invalid = $payload;
$invalid['texts']['nl']['accept'] = array( 'unsafe' );
assert_json( false === $io::parse_import( json_encode( $invalid ) ), 'Typed value injection rejected' );
$invalid = $payload;
$invalid['texts']['fr']['legal'] = str_repeat( 'z', 4097 );
assert_json( false === $io::parse_import( json_encode( $invalid ) ), 'Oversized text rejected' );
$invalid = $payload;
$invalid['texts']['de']['accept'] = str_repeat( 'z', 301 );
assert_json( false === $io::parse_import( json_encode( $invalid ) ), 'Oversized label rejected' );
$invalid = $payload;
$invalid['option'] = 'siteurl';
assert_json( false === $io::parse_import( json_encode( $invalid ) ), 'Cannot update arbitrary wp_options' );
$invalid = $payload;
$invalid['cookie_policy_pages']['XX'] = 'newmarket';
assert_json( false === $io::parse_import( json_encode( $invalid ) ), 'Unknown country rejected' );
$invalid = $payload;
$invalid['cookie_policy_pages']['US'] = '../wp-admin';
assert_json( false === $io::parse_import( json_encode( $invalid ) ), 'Unsafe slug rejected' );
$invalid = $payload;
$invalid['mode'] = 'execute';
assert_json( false === $io::parse_import( json_encode( $invalid ) ), 'Unexpected import mode rejected' );
$invalid = $payload;
$invalid['enabled'] = array( '1' );
assert_json( false === $io::parse_import( json_encode( $invalid ) ), 'Invalid boolean rejected' );
$invalid = $payload;
$invalid['texts']['unexpected'] = array();
assert_json( false === $io::parse_import( json_encode( $invalid ) ), 'Extra locale rejected' );
assert_json( false === $io::parse_import( '{"schema":' ), 'Malformed JSON rejected' );
assert_json( false === $io::parse_import( str_repeat( 'a', 65537 ) ), 'Oversized JSON rejected' );
$bad_url = $payload;
$bad_url['cookie_policy_url'] = 'http://shop.test/cookies/';
assert_json( false === $io::merge_import( $old, $bad_url ), 'HTTP policy rejected' );
$bad_url['cookie_policy_url'] = 'https://user:secret@shop.test/cookies/';
assert_json( false === $io::merge_import( $old, $bad_url ), 'URL credentials rejected' );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-taxocommerce-insights-consent-seed.php' );
assert_json( strpos( $source, "admin_post_' . self::IMPORT_ACTION" ) !== false, 'Import admin endpoint registered' );
assert_json( strpos( $source, 'check_admin_referer( self::IMPORT_NONCE )' ) !== false, 'Upload nonce required' );
assert_json( strpos( $source, 'is_uploaded_file(' ) !== false, 'Real upload required' );
assert_json( strpos( $source, 'enctype="multipart/form-data"' ) !== false, 'Upload form visible' );
assert_json( strpos( $source, 'update_option( TaxoCommerce_Insights_Consent::OPTION, $next' ) !== false,
    'Every setting persisted to one WordPress option' );
assert_json( strpos( $source, 'cookie_policy_pages' ) !== false, 'Country-page mapping is exported' );
assert_json( strpos( $source, 'tci_confirm_legal_review' ) !== false, 'Explicit legal review control' );
echo 'Consent JSON v2 PASS: 65 fields, five local pages, both switches, safe restore and security' . PHP_EOL;
