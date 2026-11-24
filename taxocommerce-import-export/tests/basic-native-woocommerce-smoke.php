<?php
/**
 * Basic WooCommerce CSV import/export smoke test with native WP/Woo stubs.
 * No WordPress installation, Core or TaxoCommerce family plugin is required.
 */
if ( 'cli' !== PHP_SAPI ) {
    exit( 1 );
}
define( 'ABSPATH', '/' );
define( 'OBJECT', 'OBJECT' );

class WP_Error {
    private $code;
    private $message;
    public function __construct( $code, $message ) {
        $this->code = $code;
        $this->message = $message;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function __( $value, $domain = '' ) { return $value; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function current_user_can( $capability ) { return true; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_title( $value ) { return strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', (string) $value ), '-' ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_kses_post( $value ) { return (string) $value; }
function wc_format_decimal( $value ) { return str_replace( ',', '.', (string) $value ); }
function esc_url_raw( $value ) { return (string) $value; }
function wp_parse_url( $value, $part = -1 ) { return parse_url( $value, $part ); }
function get_post_type( $id ) { return isset( WC_Product_Simple::$products[ $id ] ) ? 'product' : ''; }
function get_page_by_path( $slug, $output = OBJECT, $type = 'page' ) {
    if ( 'product' !== $type ) {
        return false;
    }
    foreach ( WC_Product_Simple::$products as $id => $product ) {
        if ( $product->get_slug() === $slug ) {
            return (object) array( 'ID' => $id );
        }
    }
    return false;
}
function wc_get_product( $id ) { return WC_Product_Simple::$products[ $id ] ?? false; }
function wc_get_product_id_by_sku( $sku ) {
    foreach ( WC_Product_Simple::$products as $id => $product ) {
        if ( $product->get_sku() === $sku ) {
            return $id;
        }
    }
    return 0;
}
function wp_set_object_terms( $id, $terms, $taxonomy, $append = false ) {
    ++$GLOBALS['native_terms_written'];
    return array();
}
function wp_get_object_terms( $id, $taxonomy, $args = array() ) { return array(); }

class WC_Product_Download {
    private $props = array();
    public function set_id( $v ) { $this->props['id'] = $v; }
    public function set_name( $v ) { $this->props['name'] = $v; }
    public function set_file( $v ) { $this->props['file'] = $v; }
    public function get_name() { return $this->props['name'] ?? ''; }
    public function get_file() { return $this->props['file'] ?? ''; }
}
class WC_Product_Simple {
    public static $products = array();
    public static $next_id = 100;
    private $id = 0;
    private $props = array( 'type' => 'simple', 'status' => 'draft', 'downloads' => array() );
    public function get_id() { return $this->id; }
    public function __call( $name, $args ) {
        if ( 0 === strpos( $name, 'set_' ) ) {
            $this->props[ substr( $name, 4 ) ] = $args[0];
            return;
        }
        if ( 0 === strpos( $name, 'get_' ) ) {
            return $this->props[ substr( $name, 4 ) ] ?? '';
        }
        throw new RuntimeException( 'Unexpected WooCommerce API call: ' . $name );
    }
    public function save() {
        if ( ! $this->id ) {
            $this->id = ++self::$next_id;
        }
        self::$products[ $this->id ] = $this;
        return $this->id;
    }
}
function assert_basic( $condition, $description ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: $description\n" );
        exit( 1 );
    }
}
$GLOBALS['native_terms_written'] = 0;
require dirname( __DIR__ ) . '/includes/class-taxocommerce-import-export-basic.php';
$basic = new TaxoCommerce_Import_Export_Basic();
$import = new ReflectionMethod( TaxoCommerce_Import_Export_Basic::class, 'import_record' );
$import->setAccessible( true );
$export = new ReflectionMethod( TaxoCommerce_Import_Export_Basic::class, 'export_value' );
$export->setAccessible( true );
$fields = new ReflectionMethod( TaxoCommerce_Import_Export_Basic::class, 'fields' );
$fields->setAccessible( true );

$woo = array(
    'sku' => 'TC-MAIL-LITE',
    'name' => 'Mail Lite',
    'product_type' => 'simple, virtual, downloadable',
    'published' => '-1',
    'regular_price' => '0',
    'download_1_name' => 'Mail Lite 0.1.3',
    'download_1_url' => 'https://example.org/wp-content/uploads/mail-lite.zip',
    'download_limit' => '1',
    'download_expiry' => '-1',
);
assert_basic( true === $import->invoke( $basic, 'product', $woo, 'create' ), 'New downloadable product created' );
assert_basic( 1 === count( WC_Product_Simple::$products ), 'Exactly one product created' );
$id = wc_get_product_id_by_sku( 'TC-MAIL-LITE' );
$product = wc_get_product( $id );
assert_basic( $id > 0 && 'draft' === $product->get_status(), 'Published=-1 maps to draft' );
assert_basic( true === $product->get_virtual() && true === $product->get_downloadable(), 'Woo Type sets virtual/downloadable' );
assert_basic( 1 === $product->get_download_limit() && -1 === $product->get_download_expiry(), 'Download conditions stored' );
assert_basic( 0 === $GLOBALS['native_terms_written'], 'No categories, tags or SEO terms are required' );
assert_basic( 'Mail Lite 0.1.3' === $export->invoke( $basic, 'product', $id, 'download_1_name' ), 'Download name exports' );
assert_basic( $woo['download_1_url'] === $export->invoke( $basic, 'product', $id, 'download_1_url' ), 'Download URL exports' );
assert_basic( 'simple, virtual, downloadable' === $export->invoke( $basic, 'product', $id, 'product_type' ), 'Product type round trip' );

$again = $woo;
$again['name'] = 'Mail Lite (updated)';
assert_basic( true === $import->invoke( $basic, 'product', $again, 'create' ), 'Existing SKU updated in create/update mode' );
assert_basic( 1 === count( WC_Product_Simple::$products ), 'Repeated import does not duplicate SKU' );
assert_basic( 'Mail Lite (updated)' === wc_get_product( $id )->get_name(), 'Existing product updated' );
assert_basic( is_wp_error( $import->invoke( $basic, 'product', array( 'sku' => 'MISSING', 'name' => 'Missing' ), 'update' ) ), 'Update-only still protects missing products' );
assert_basic( 1 === count( WC_Product_Simple::$products ), 'Update-only creates nothing' );

$bad_url = array( 'sku' => 'BAD', 'name' => 'Bad URL', 'download_1_url' => 'javascript:alert(1)' );
$error = $import->invoke( $basic, 'product', $bad_url, 'create' );
assert_basic( is_wp_error( $error ) && 'invalid_download_url' === $error->get_error_code(), 'Non-HTTP download rejected' );
assert_basic( 1 === count( WC_Product_Simple::$products ), 'Invalid URL never saved' );

$known = $fields->invoke( null, 'product' );
foreach ( array( 'product_type', 'published', 'downloadable', 'download_1_name', 'download_1_url', 'download_limit', 'download_expiry' ) as $key ) {
    assert_basic( isset( $known[ $key ] ), 'Basic mapper exposes ' . $key );
}
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-taxocommerce-import-export-basic.php' );
assert_basic( strpos( $source, '<option value="create">' ) < strpos( $source, '<option value="update">' ), 'Create/update is the default action' );
assert_basic( false !== strpos( $source, "suggested==='categories'||suggested==='tags'" ), 'Categories and tags are not mapped automatically' );
echo "Basic WooCommerce CSV: create, update, no mandatory terms, downloads and export OK\n";
