<?php
/**
 * Standalone tests: bundled 5-language JSON imports into wp_options safely.
 *
 * Run: php taxocommerce-google-bing/tests/consent-wpoptions-seed-smoke.php
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['tci_opts'] = array();
$GLOBALS['tci_archives'] = array();
function get_option( $name, $default = false ) {
    return array_key_exists( $name, $GLOBALS['tci_opts'] ) ? $GLOBALS['tci_opts'][ $name ] : $default;
}
function add_option( $name, $value, $deprecated = '', $autoload = false ) {
    if ( array_key_exists( $name, $GLOBALS['tci_opts'] ) ) { return false; }
    $GLOBALS['tci_opts'][ $name ] = $value;
    return true;
}
function update_option( $name, $value, $autoload = null ) {
    $changed = ! array_key_exists( $name, $GLOBALS['tci_opts'] ) || $GLOBALS['tci_opts'][ $name ] !== $value;
    $GLOBALS['tci_opts'][ $name ] = $value;
    return $changed;
}
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, (array) $args ); }
function current_user_can( $capability ) { return 'manage_options' === $capability; }
function current_time( $format, $utc = false ) { return '2026-11-08 22:00:00'; }
function verify( $condition, $label ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL );
        exit( 1 );
    }
}
class TaxoCommerce_Insights_Consent_Ledger {
    public static function archive( $settings ) {
        $GLOBALS['tci_archives'][] = $settings;
    }
}
require dirname( __DIR__ ) . '/includes/class-taxocommerce-insights-consent.php';
require dirname( __DIR__ ) . '/includes/class-taxocommerce-insights-consent-seed.php';
$seed = TaxoCommerce_Insights_Consent_Seed::seed();
verify( is_array( $seed ), 'JSON is loadable' );
verify( count( $seed['texts'] ) === 5, 'Exactly five languages' );
foreach ( $seed['texts'] as $lang => $row ) {
    verify( count( $row ) === 13, 'All 13 fields are present: ' . $lang );
    foreach ( $row as $key => $value ) {
        verify( is_string( $value ) && trim( $value ) !== '', 'Nonempty field ' . $lang . '/' . $key );
    }
}
$defaults = TaxoCommerce_Insights_Consent::defaults();
$defaults['texts']['es']['accept'] = 'Custom administrator accept';
$defaults['cookie_policy_url'] = 'https://example.test/existing-policy/';
$GLOBALS['tci_opts'][ TaxoCommerce_Insights_Consent::OPTION ] = $defaults;
TaxoCommerce_Insights_Consent_Seed::maybe_migrate();
$after = get_option( TaxoCommerce_Insights_Consent::OPTION );
verify( $after['texts']['es']['accept'] === 'Custom administrator accept', 'Preserves custom button' );
verify( $after['texts']['es']['intro'] === $seed['texts']['es']['intro'], 'New Spanish intro saved' );
verify( $after['texts']['fr']['legal'] === $seed['texts']['fr']['legal'], 'New French legal saved' );
verify( $after['texts']['nl']['reopen'] === $seed['texts']['nl']['reopen'], 'All optional button defaults available' );
verify( $after['enabled'] === 0 && $after['legal_reviewed'] === 0, 'No automatic activation or legal review' );
verify( $after['cookie_policy_url'] === 'https://example.test/existing-policy/', 'Does not alter policy URL' );
verify( $after['revision'] === 2, 'New legal revision on changed wording' );
verify( count( $GLOBALS['tci_archives'] ) === 2, 'Old and new legal texts archived' );
verify( get_option( TaxoCommerce_Insights_Consent_Seed::MARKER )['completed'] === 1, 'Migration marker set' );
verify( get_option( TaxoCommerce_Insights_Consent_Seed::BACKUP )['previous']['texts']['es']['accept'] === 'Custom administrator accept', 'Exact backup saved' );
$version = get_option( TaxoCommerce_Insights_Consent::OPTION )['revision'];
TaxoCommerce_Insights_Consent_Seed::maybe_migrate();
verify( get_option( TaxoCommerce_Insights_Consent::OPTION )['revision'] === $version, 'Re-run is idempotent' );
verify( count( $GLOBALS['tci_archives'] ) === 2, 'No duplicated historical record' );
$GLOBALS['tci_opts'] = array();
$GLOBALS['tci_archives'] = array();
$active = TaxoCommerce_Insights_Consent::defaults();
$active['enabled'] = 1;
$active['legal_reviewed'] = 1;
$active['cookie_policy_url'] = 'https://example.test/real-policy/';
$GLOBALS['tci_opts'][ TaxoCommerce_Insights_Consent::OPTION ] = $active;
TaxoCommerce_Insights_Consent_Seed::maybe_migrate();
verify( get_option( TaxoCommerce_Insights_Consent::OPTION ) === $active, 'Do not rewrite active legally reviewed consent' );
verify( ! get_option( TaxoCommerce_Insights_Consent_Seed::MARKER ), 'Do not mark active settings as migrated' );
$GLOBALS['tci_opts'] = array();
TaxoCommerce_Insights_Consent_Seed::maybe_migrate();
$installed = get_option( TaxoCommerce_Insights_Consent::OPTION );
verify( is_array( $installed ) && $installed['texts']['de']['legal'] === $seed['texts']['de']['legal'], 'Initial option created from bundled JSON' );
verify( $installed['enabled'] === 0 && $installed['legal_reviewed'] === 0, 'New install inactive' );
echo 'Insights consent JSON seed: PASS (5 x 13 fields, wp_options, backup, security, idempotence)' . PHP_EOL;
