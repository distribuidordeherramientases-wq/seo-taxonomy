<?php
/** Isolated CLI smoke: WordPress+WooCommerce stubs, no TaxoCommerce Core or old importer. */
if ( 'cli' !== PHP_SAPI ) exit( 1 );
define( 'ABSPATH', '/' );
define( 'OBJECT', 'OBJECT' );
define( 'DOMAIN', 'taxocommerce-import-export-lite' ); // CSV class is namespaced; define matching below.
class WP_Error {
	private $message;
	public function __construct( $code, $message ) { $this->message = $message; }
	public function get_error_message() { return $this->message; }
}
function __( $str, $domain = '' ) { return $str; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function current_user_can( $cap, $id = 0 ) { return true; }
function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( $s ) ); }
function sanitize_title( $s ) { return strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', $s ), '-' ) ); }
function sanitize_key( $s ) { return strtolower( trim( preg_replace( '/[^a-z0-9_-]+/i', '', $s ) ) ); }
function remove_accents( $s ) { return strtr( $s, array( 'í'=>'i','ó'=>'o','á'=>'a' ) ); }
function absint( $v ) { return abs( (int) $v ); }
function wc_format_decimal( $v ) { return str_replace( ',', '.', $v ); }
function wp_kses_post( $v ) { return (string) $v; }
function esc_url_raw( $v, $protocols = array() ) { return (string) $v; }
function wp_http_validate_url( $v ) { return false !== filter_var( $v, FILTER_VALIDATE_URL ); }
function wp_basename( $v ) { return basename( $v ); }
function wp_parse_url( $v, $component ) { return parse_url( $v, $component ); }
function attachment_url_to_postid( $url ) { return 0; }
function wp_attachment_is_image( $id ) { return false; }
function get_post_type( $id ) { return 'product'; }
function get_term_by( $kind, $value, $tax ) { return false; }
function get_page_by_path( $slug, $output = null, $type = '' ) {
	foreach ( $GLOBALS['products'] as $p ) {
		if ( 'product' === $type && $p->get_slug() === $slug ) return (object) array( 'ID' => $p->get_id() );
	}
	return null;
}
function wp_set_object_terms( $id, $terms, $tax, $append ) { return $terms; }
function wc_get_product_id_by_sku( $sku ) {
	foreach ( $GLOBALS['products'] as $p ) if ( $sku === $p->get_sku() ) return $p->get_id();
	return 0;
}
function wc_get_product( $id ) { return $GLOBALS['products'][ $id ] ?? false; }
class WC_Product_Download {
	private $name = '';
	private $file = '';
	public function set_name( $v ) { $this->name = $v; }
	public function set_file( $v ) { $this->file = $v; }
	public function get_name() { return $this->name; }
	public function get_file() { return $this->file; }
}
class WC_Product_Simple {
	private $id = 0;
	private $values = array( 'sku' => '', 'name' => '', 'slug' => '', 'status' => 'publish', 'downloadable' => false, 'virtual' => false, 'downloads' => array() );
	public function __call( $name, $args ) {
		if ( 0 === strpos( $name, 'set_' ) ) $this->values[ substr( $name, 4 ) ] = $args[0];
		elseif ( 0 === strpos( $name, 'get_' ) ) return $this->values[ substr( $name, 4 ) ] ?? '';
	}
	public function get_id() { return $this->id; }
	public function get_type() { return 'simple'; }
	public function get_sku() { return $this->values['sku']; }
	public function get_slug() { return $this->values['slug']; }
	public function get_downloads() { return $this->values['downloads']; }
	public function get_image_id() { return 0; }
	public function save() {
		if ( ! $this->id ) $this->id = ++$GLOBALS['last_id'];
		$GLOBALS['products'][ $this->id ] = $this;
		return $this->id;
	}
}
class WC_Product_External extends WC_Product_Simple {}
class WC_Product_Variable extends WC_Product_Simple {}
class WC_Product_Grouped extends WC_Product_Simple {}
function check_tciel( $value, $label ) {
	if ( ! $value ) { fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL ); exit( 1 ); }
}
$GLOBALS['products'] = array();
$GLOBALS['last_id'] = 100;
require dirname( __DIR__ ) . '/includes/class-csv.php';
use TaxoCommerce\ImportExportLite\CSV;
check_tciel( 7 === count( CSV::types() ), 'Native types all available' );
check_tciel( '' === CSV::suggest( 'product', 'Categories' ), 'Foreign categories not automatically mapped' );
check_tciel( '' === CSV::suggest( 'product', 'ID' ), 'Foreign IDs not automatically mapped' );
check_tciel( 'name' === CSV::suggest( 'product', 'Product Name' ), 'WooCommerce name mapped' );
check_tciel( 'download_1_url' === CSV::suggest( 'product', 'Download 1 URL' ), 'WooCommerce download URL mapped' );
check_tciel( "'=HYPERLINK()" === CSV::csv_safe( '=HYPERLINK()' ), 'Formula injection escaped' );
$map = array( 0 => 'sku', 1 => 'name', 2 => 'download_1_url', 3 => 'categories' );
$data = CSV::map_row( array( 'TC-TEST-1', 'Plugin test', 'https://asociacionfocazul.com/wp-content/uploads/2026/11/taxocommerce-mail.zip', '' ), $map );
check_tciel( ! isset( $data['categories'] ), 'Empty optional taxonomy absent' );
$data['slug'] = 'taxocommerce-mail-lite';
$data['status'] = 'draft';
$data['regular_price'] = '0';
$data['virtual'] = 'yes';
$data['downloadable'] = 'yes';
$data['download_1_name'] = 'Mail Lite';
$id = CSV::import( 'product', $data );
check_tciel( is_int( $id ) && $id > 0, 'New downloadable product created' );
check_tciel( 'draft' === $GLOBALS['products'][ $id ]->get_status(), 'New product draft' );
check_tciel( true === $GLOBALS['products'][ $id ]->get_downloadable(), 'Downloadable saved' );
check_tciel( true === $GLOBALS['products'][ $id ]->get_virtual(), 'Virtual saved' );
check_tciel( 'Mail Lite' === CSV::export_value( 'product', $id, 'download_1_name' ), 'Native download exported' );
check_tciel( $data['download_1_url'] === CSV::export_value( 'product', $id, 'download_1_url' ), 'Native download URL exported' );
check_tciel( $id === CSV::import( 'product', $data ), 'Re-import matches existing SKU' );
check_tciel( 1 === count( $GLOBALS['products'] ), 'Re-import does not duplicate' );
$data['sku'] = 'TC-TEST-2';
$data['slug'] = 'taxocommerce-mail-pro';
check_tciel( is_wp_error( CSV::import( 'product', $data, 'update' ) ), 'Explicit update-only rejects unknown product' );
check_tciel( is_int( CSV::import( 'product', $data ) ), 'Default mode creates second product' );
check_tciel( 2 === count( $GLOBALS['products'] ), 'Second product exists' );
$data['categories'] = 'nonexisting-taxonomy';
check_tciel( is_wp_error( CSV::import( 'product', $data ) ), 'Explicit missing category rejected without silent creation' );
check_tciel( 2 === count( $GLOBALS['products'] ), 'Rejected taxonomy does not create duplicate' );
$bootstrap = file_get_contents( dirname( __DIR__ ) . '/taxocommerce-import-export-lite.php' );
check_tciel( false === strpos( $bootstrap, 'taxocommerce-import-export/includes/' ), 'No old plugin imports' );
check_tciel( false === strpos( $bootstrap, 'taxocommerce_core_register_modules' ), 'No Core registration' );
echo "Import-Export Lite: native CSV mapping, create/upsert, downloadable, optional terms, standalone bootstrap OK\n";
