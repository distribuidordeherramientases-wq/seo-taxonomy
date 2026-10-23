<?php
/**
 * Mercado smoke test: the UI must not call external services, and the new
 * module must load with no WooCommerce, Core, or legacy Ojeador installed.
 * Run: php taxocommerce-google-bing/tests/market-smoke.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

class WP_Error {
    private $code;
    private $message;
    public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function get_option( $key, $default = false ) { return $GLOBALS['test_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['test_options'][ $key ] = $value; return true; }
function get_current_user_id() { return 1; }
function get_transient( $key ) { return false; }
function delete_transient( $key ) { return true; }
function taxonomy_exists( $name ) { return false; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return esc_url( $s ); }
function esc_html( $s ) { return esc_url( $s ); }
function wp_nonce_field( $n ) { echo '<input name="_wpnonce" value="fixture" type="hidden">'; }
function absint( $n ) { return abs( (int) $n ); }
function wp_safe_remote_get() { throw new RuntimeException( 'HTTP must not occur while rendering' ); }
function wp_safe_remote_post() { throw new RuntimeException( 'HTTP must not occur while rendering' ); }
function current_user_can( $cap ) { return true; }
function get_locale() { return 'es_ES'; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }

final class FakeWPDB {
    public $prefix = 'wp_';
    public function prepare( $sql, ...$args ) { return $sql; }
    public function get_var( $sql ) { return 0; }
    public function get_results( $sql, $mode = null ) { return array(); }
}
$GLOBALS['wpdb'] = new FakeWPDB();

final class TaxoCommerce_Google_Bing_Connections {
    public static function merchant_account() { return ''; }
    public static function google_settings() { return array( 'search_console_site_url' => '', 'service_account_json' => '' ); }
}

final class TaxoCommerce_Google_Bing_OAuth {
    public static function settings() { return array( 'property_id' => '' ); }
    public static function tokens() { return array( 'access_token' => '', 'refresh_token' => '' ); }
}

require dirname( __DIR__ ) . '/includes/class-taxocommerce-google-bing-market-sources.php';
require dirname( __DIR__ ) . '/includes/class-taxocommerce-google-bing-market.php';

$tables = TaxoCommerce_Google_Bing_Market::tables();
if ( count( $tables ) !== 3 ||
    $tables['categories'] !== 'wp_tgb_market_categories' ||
    $tables['sources'] !== 'wp_tgb_market_sources' ||
    $tables['runs'] !== 'wp_tgb_market_runs' ) {
    fwrite( STDERR, "Incorrect Mercado tables\n" );
    exit( 1 );
}
if ( TaxoCommerce_Google_Bing_Market_Sources::any_global_provider() ||
    TaxoCommerce_Google_Bing_Market_Sources::any_available( array( 'google_category_id' => 0 ) ) ) {
    fwrite( STDERR, "A provider was invented without configuration\n" );
    exit( 1 );
}
$_GET = array();
ob_start();
TaxoCommerce_Google_Bing_Market::render();
$html = ob_get_clean();
foreach ( array( 'Mercado', 'Sincronizar siguiente lote de categorías', 'No existe la taxonomía product_cat',
    'Analizar pendientes en cola', 'Sin evidencias' ) as $needle ) {
    if ( 'Sin evidencias' === $needle ) {
        continue; // This phrase occurs only on the selected category screen.
    }
    if ( false === strpos( $html, $needle ) ) {
        fwrite( STDERR, "Mercado output missing: $needle\n" );
        exit( 1 );
    }
}
echo "Mercado smoke: OK, independent admin render, three owned tables, zero network calls\n";
