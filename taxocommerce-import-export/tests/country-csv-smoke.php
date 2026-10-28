<?php
/** Country CSV regression without database or WordPress installation. */
if ( 'cli' !== PHP_SAPI ) { exit; }
define( 'ABSPATH', '/' );
class WP_Error {
	public $code;
	public function __construct( $code, $message ) { $this->code = $code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function __( $v, $domain = '' ) { return $v; }
$GLOBALS['post_meta'] = array( 1 => array( '_seo_pais_origen' => 'FR' ) );
$GLOBALS['term_meta'] = array( 10 => array( '_seo_pais_origen' => 'DE' ) );
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['post_meta'][ $id ][ $key ] ?? ''; }
function get_term_meta( $id, $key, $single = true ) { return $GLOBALS['term_meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['post_meta'][ $id ][ $key ] = $value; }
function update_term_meta( $id, $key, $value ) { $GLOBALS['term_meta'][ $id ][ $key ] = $value; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['post_meta'][ $id ][ $key ] ); }
function delete_term_meta( $id, $key ) { unset( $GLOBALS['term_meta'][ $id ][ $key ] ); }
require dirname( __DIR__ ) . '/includes/class-taxocommerce-import-export-csv.php';
$csv = new TaxoCommerce_Import_Export_CSV();
function check( $condition, $label ) {
	if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $label . "\n" ); exit( 1 ); }
}
$invoke = static function ( $method, ...$args ) use ( $csv ) {
	$reflection = new ReflectionMethod( $csv, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $csv, $args );
};
foreach ( array( 'products', 'products_reduced', 'product_categories', 'pages', 'posts' ) as $type ) {
	$header = $csv->export_header_for_job( $type );
	check( count( array_filter( $header, static function( $item ) { return $item === 'country_code'; } ) ) === 1, $type . ' has one country column' );
	check( end( $header ) === 'country_code', $type . ' country is appended for legacy CSV compatibility' );
}
check( $invoke( 'country_for_export', 1 ) === 'FR', 'stored post country' );
check( $invoke( 'country_for_export', 2 ) === '', 'missing country stays empty' );
check( $invoke( 'country_for_export', 10, true ) === 'DE', 'stored term country' );
check( $invoke( 'country_from_row', array() ) === null, 'old CSV preserves country' );
check( $invoke( 'country_from_row', array( 'country_code' => 'us' ) ) === 'US', 'ISO code normalized' );
check( $invoke( 'country_from_row', array( 'country_code' => '' ), true ) === null, 'partial blank preserves' );
check( $invoke( 'country_from_row', array( 'country_code' => '' ) ) === '', 'full blank clears' );
check( is_wp_error( $invoke( 'country_from_row', array( 'country_code' => 'XX' ) ) ), 'unknown country rejected' );
$invoke( 'save_country', 1, null );
check( $invoke( 'country_for_export', 1 ) === 'FR', 'missing column preserves old country' );
$invoke( 'save_country', 1, 'NL' );
check( $invoke( 'country_for_export', 1 ) === 'NL', 'new country saved on post' );
$invoke( 'save_country', 10, 'US', true );
check( $invoke( 'country_for_export', 10, true ) === 'US', 'new country saved on category' );
$invoke( 'save_country', 1, '' );
check( $invoke( 'country_for_export', 1 ) === '', 'full blank clears old country' );
check( in_array( 'country_code', $csv->normalize_headers_for_batch( array( 'pais_origen' ) ), true ), 'legacy country alias' );
echo "PASS: five CSV schemas and country import/export compatibility.\n";
