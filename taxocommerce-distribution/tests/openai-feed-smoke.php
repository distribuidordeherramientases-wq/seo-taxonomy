<?php
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code;
    public function __construct( $code, $message ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function tcdist_feed_plain_text( $v, $max ) { return substr( trim( strip_tags( (string) $v ) ), 0, $max ); }
function apply_filters( $name, $v ) { return $v; }
function sanitize_key( $v ) { return strtolower( $v ); }
function check( $ok, $msg ) { if ( ! $ok ) { throw new RuntimeException( $msg ); } }
require dirname( __DIR__ ) . '/includes/feeds/channels.php';
$context = [ 'seller_name' => 'Store', 'seller_url' => 'https://example.com/', 'target_countries' => 'ES', 'store_country' => 'ES' ];
$record = [ 'id' => 'wc-42', 'title' => 'Tool, "professional"', 'description' => 'A tool', 'brand' => 'Brand', 'link' => 'https://example.com/tool', 'image_link' => 'https://example.com/tool.jpg', 'price' => '12.50 EUR', 'availability' => 'in_stock', 'gtin' => '4006381333931', 'condition' => 'new', 'supplier' => 'PRIVATE' ];
$row = tcdist_feed_openai_row( $record, $context );
check( ! is_wp_error( $row ), 'valid record' );
check( array_keys( $row ) === tcdist_feed_openai_columns(), 'column order' );
check( $row['is_eligible_search'] === 'true' && $row['is_ads_eligible'] === 'false' && $row['is_eligible_checkout'] === 'false', 'organic only' );
check( $row['target_countries'] === 'ES' && $row['price'] === '12.50 EUR', 'real market and price' );
check( $row['gtin'] === '4006381333931' && ! isset( $row['supplier'] ), 'public data only' );
check( tcdist_feed_openai_gtin( '4006381333932' ) === '', 'invalid GTIN' );
check( tcdist_feed_openai_row( array_merge( $record, [ 'brand' => '' ] ), $context )->get_error_code() === 'missing_brand', 'missing brand excluded' );
check( tcdist_feed_openai_row( array_merge( $record, [ 'availability' => 'out_of_stock' ] ), $context )['is_eligible_search'] === 'false', 'out of stock not eligible' );
check( tcdist_feed_openai_row( array_merge( $record, [ 'link' => 'javascript:bad' ] ), $context )->get_error_code() === 'invalid_public_url', 'invalid URL' );
check( tcdist_feed_channel_columns( 'google' )[0] === 'id', 'other channel intact' );
$path = tempnam( sys_get_temp_dir(), 'tcdist-openai-' );
try {
    check( true === tcdist_feed_channel_write_header( 'openai', $path ), 'write header' );
    check( true === tcdist_feed_channel_append_record( 'openai', $path, $record, $context ), 'append record' );
    check( true === tcdist_feed_channel_finalize( 'openai', $path ), 'finalize' );
    $handle = fopen( $path, 'rb' );
    $header = fgetcsv( $handle, 0, ',', '"', '' );
    $values = fgetcsv( $handle, 0, ',', '"', '' );
    fclose( $handle );
    check( $header === array_keys( $row ) && $values === array_values( $row ), 'CSV round trip' );
} finally { unlink( $path ); }
echo "OpenAI feed smoke passed.\n";
