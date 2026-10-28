<?php
/** In-memory WooCommerce stub: unchanged imports must not re-save products. */
if ( 'cli' !== PHP_SAPI ) { exit; }
define( 'ABSPATH', '/' );
class WP_Error {
    public $code;
    public function __construct( $code, $message ) { $this->code = $code; }
}
function __( $v, $domain = '' ) { return $v; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_title( $v ) { return strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', (string) $v ), '-' ) ); }
function wp_kses_post( $v ) { return (string) $v; }
function esc_url_raw( $v ) { return (string) $v; }
function get_post_meta( $id, $key, $single = true ) {
    $GLOBALS['wpdb']->num_queries++;
    return $GLOBALS['post_meta'][ $id ][ $key ] ?? '';
}
function metadata_exists( $type, $id, $key ) {
    return isset( $GLOBALS['post_meta'][ $id ][ $key ] );
}
function update_post_meta( $id, $key, $v ) {
    $GLOBALS['wpdb']->num_queries++;
    $GLOBALS['post_meta'][ $id ][ $key ] = $v;
}
function delete_post_meta( $id, $key ) {
    $GLOBALS['wpdb']->num_queries++;
    unset( $GLOBALS['post_meta'][ $id ][ $key ] );
}
class WC_Product_Simple {
    private $id;
    private $props;
    private $dirty = array();
    public $save_count = 0;
    public function __construct( $id = 0, $props = array() ) {
        $this->id = $id;
        $this->props = array_merge( array(
            'name' => '', 'slug' => '', 'status' => 'draft',
        ), $props );
    }
    public function get_id() { return $this->id; }
    public function get_changes() { return $this->dirty; }
    private function set( $key, $value ) {
        if ( $this->props[ $key ] !== $value ) {
            $this->dirty[ $key ] = $value;
            $this->props[ $key ] = $value;
        }
    }
    public function set_name( $v ) { $this->set( 'name', $v ); }
    public function set_slug( $v ) { $this->set( 'slug', $v ); }
    public function set_status( $v ) { $this->set( 'status', $v ); }
    public function save() {
        ++$this->save_count;
        $GLOBALS['wpdb']->num_queries += 100;
        if ( ! $this->id ) {
            $this->id = 2;
            $GLOBALS['products'][2] = $this;
        }
        $this->dirty = array();
        return $this->id;
    }
}
class WC_Product_External extends WC_Product_Simple {}
function wc_get_product( $id ) { $GLOBALS['wpdb']->num_queries++; return $GLOBALS['products'][ $id ] ?? false; }
function wc_get_product_id_by_sku( $sku ) { return 0; }
function check_noop( $ok, $message ) { if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); } }

$GLOBALS['wpdb'] = (object) array( 'num_queries' => 0 );
$GLOBALS['post_meta'] = array( 1 => array( '_seo_pais_origen' => 'ES' ) );
$GLOBALS['products'] = array( 1 => new WC_Product_Simple( 1, array(
    'name' => 'Taladro de prueba', 'slug' => 'taladro-de-prueba', 'status' => 'publish',
) ) );
require dirname( __DIR__ ) . '/includes/class-taxocommerce-import-export-csv.php';
$csv = new TaxoCommerce_Import_Export_CSV();
$row = array(
    'record_type' => 'product', 'id' => '1', 'product_type' => 'simple',
    'name' => 'Taladro de prueba', 'slug' => 'taladro-de-prueba',
    'status' => 'publish', 'country_code' => 'ES',
);
check_noop( true === $csv->import_row_for_batch( $row, array( 'type' => 'product' ) ), 'same product succeeds' );
check_noop( 0 === $GLOBALS['products'][1]->save_count, 'same product avoids expensive WooCommerce save' );
check_noop( 1 === $csv->last_product_profile()['skipped_unchanged_save'], 'no-op measurement registered' );
$row['name'] = 'Taladro modificado';
check_noop( true === $csv->import_row_for_batch( $row, array( 'type' => 'product' ) ), 'changed product succeeds' );
check_noop( 1 === $GLOBALS['products'][1]->save_count, 'changed product is saved' );
check_noop( 1 === $csv->last_product_profile()['saved'], 'updated save measured' );
$row['id'] = '';
$row['name'] = 'Producto nuevo';
$row['slug'] = 'producto-nuevo';
check_noop( true === $csv->import_row_for_batch( $row, array( 'type' => 'product' ) ), 'new product succeeds' );
check_noop( 1 === $GLOBALS['products'][2]->save_count, 'new product always saved' );
check_noop( 'ES' === $GLOBALS['post_meta'][2]['_seo_pais_origen'], 'country saved for new product' );
echo "Product save no-op: OK (unchanged skipped, changed and new saved; metadata preserved)" . PHP_EOL;
