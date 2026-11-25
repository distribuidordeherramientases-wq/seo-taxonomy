<?php
/** Regression: SKU, slug, missing Woo objects and trashed Woo posts. */
define( 'ABSPATH', '/' );
define( 'OBJECT', 'OBJECT' );
class WP_Error {
    private $msg;
    function __construct( $code, $msg ) { $this->msg = $msg; }
    function get_error_message() { return $this->msg; }
}
function __( $s, $domain = '' ) { return $s; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_text_field( $v ) { return (string) $v; }
function sanitize_title( $v ) { return strtolower( (string) $v ); }
function absint( $v ) { return abs( (int) $v ); }
function get_post_type( $id ) { return isset( $GLOBALS['wp_posts'][ $id ] ) ? $GLOBALS['wp_posts'][ $id ]['type'] : false; }
function get_post_status( $id ) { return isset( $GLOBALS['wp_posts'][ $id ] ) ? $GLOBALS['wp_posts'][ $id ]['status'] : false; }
function wc_get_product_id_by_sku( $sku ) { return isset( $GLOBALS['sku_index'][ $sku ] ) ? $GLOBALS['sku_index'][ $sku ] : 0; }
function get_page_by_path( $slug, $output, $type ) { return isset( $GLOBALS['slug_index'][ $slug ] ) ? (object) array( 'ID' => $GLOBALS['slug_index'][ $slug ] ) : false; }
function wc_get_product( $id ) { return isset( $GLOBALS['wc_products'][ $id ] ) ? $GLOBALS['wc_products'][ $id ] : false; }
function wc_product_has_unique_sku( $id, $sku ) { return ! isset( $GLOBALS['reserved'][ $sku ] ); }
class MockWCProduct {
    private $id; private $sku;
    function __construct( $id, $sku ) { $this->id = $id; $this->sku = $sku; }
    function get_id() { return $this->id; }
    function get_sku() { return $this->sku; }
}
require dirname( __DIR__ ) . '/includes/class-csv.php';
$ref = new ReflectionMethod( 'TaxoCommerce\\ImportExportLite\\CSV', 'resolve_product' );
$ref->setAccessible( true );
function run_lookup( $data, $mode = 'upsert' ) {
    global $ref;
    return $ref->invoke( null, $data, $mode );
}
function ck( $value, $msg ) { if ( ! $value ) { fwrite( STDERR, 'FAIL: ' . $msg . "\n" ); exit( 1 ); } }
$GLOBALS['wp_posts'] = $GLOBALS['sku_index'] = $GLOBALS['slug_index'] = $GLOBALS['wc_products'] = $GLOBALS['reserved'] = array();
$lite = array( 'sku' => 'TC-MAIL-LITE', 'slug' => 'taxocommerce-mail-lite' );
$pro = array( 'sku' => 'TC-MAIL-PRO', 'slug' => 'taxocommerce-mail-pro' );
ck( 0 === run_lookup( $lite )['id'], 'Mail Lite new' );
ck( 0 === run_lookup( $pro )['id'], 'Mail Pro new' );
ck( is_wp_error( run_lookup( $pro, 'update' ) ), 'Update-only rejects genuinely new' );
$GLOBALS['sku_index']['TC-MAIL-PRO'] = 9991;
ck( 0 === run_lookup( $pro )['id'], 'Stale SKU lookup does not block create' );
$GLOBALS['slug_index']['taxocommerce-mail-pro'] = 9992;
ck( 0 === run_lookup( $pro )['id'], 'Stale slug lookup does not block create' );
$GLOBALS['wp_posts'][9992] = array( 'type' => 'product', 'status' => 'trash' );
ck( 0 === run_lookup( $pro )['id'], 'Trashed product is ignored on create' );
ck( is_wp_error( run_lookup( $pro, 'update' ) ), 'Trashed product is not overwritten in update-only' );
$GLOBALS['reserved']['TC-MAIL-PRO'] = true;
ck( is_wp_error( run_lookup( $pro ) ), 'Trashed SKU remains blocked when WooCommerce reserves it' );
unset( $GLOBALS['reserved']['TC-MAIL-PRO'] );
$GLOBALS['wp_posts'][9991] = array( 'type' => 'product', 'status' => 'publish' );
ck( is_wp_error( run_lookup( $pro ) ), 'Live broken product blocked, not overwritten' );
unset( $GLOBALS['wp_posts'][9991], $GLOBALS['sku_index']['TC-MAIL-PRO'] );
$GLOBALS['wp_posts'][300] = array( 'type' => 'product', 'status' => 'draft' );
$GLOBALS['wc_products'][300] = new MockWCProduct( 300, 'TC-MAIL-PRO' );
$GLOBALS['sku_index']['TC-MAIL-PRO'] = 300;
$GLOBALS['slug_index']['taxocommerce-mail-pro'] = 300;
ck( 300 === run_lookup( $pro )['id'], 'Existing product resolved for update' );
$GLOBALS['wp_posts'][301] = array( 'type' => 'product', 'status' => 'draft' );
$GLOBALS['wc_products'][301] = new MockWCProduct( 301, 'ANOTHER-SKU' );
$GLOBALS['slug_index']['taxocommerce-mail-pro'] = 301;
ck( is_wp_error( run_lookup( $pro ) ), 'SKU/slug conflict blocked without writes' );
ck( 2 === count( $GLOBALS['wc_products'] ), 'No products modified during lookup' );
echo "Product identity smoke passed: create, upsert, orphan SKU, orphan slug, trash, uniqueness, conflicts.\n";
