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
function get_term_by( $kind, $value, $tax ) {
	if ( 'pa_color' === $tax && in_array( strtolower( $value ), array( 'red', 'blue' ), true ) ) {
		return (object) array( 'term_id' => 'red' === strtolower( $value ) ? 10 : 11, 'slug' => strtolower( $value ) );
	}
	return false;
}
function get_registered_meta_keys( $object, $subtype = '' ) {
	if ( 'post' === $object && 'product' === $subtype ) return array( 'custom_warranty' => array( 'type' => 'string', 'single' => true, 'label' => 'Warranty' ) );
	if ( 'post' === $object && 'post' === $subtype ) return array( 'custom_reviewer' => array( 'type' => 'string', 'single' => true ) );
	if ( 'term' === $object && 'product_cat' === $subtype ) return array( 'category_banner' => array( 'type' => 'string', 'single' => true ) );
	return array();
}
function is_protected_meta( $key, $object ) { return '_' === substr( $key, 0, 1 ); }
function wc_get_attribute_taxonomies() { return array( (object) array( 'attribute_name' => 'color', 'attribute_label' => 'Color', 'attribute_id' => 3 ) ); }
function wc_attribute_taxonomy_name( $name ) { return 'pa_' . $name; }
function taxonomy_exists( $name ) { return 'pa_color' === $name; }
function wc_get_products( $query ) { return array_keys( $GLOBALS['products'] ); }
function get_post_meta( $id, $key = '', $single = true ) { return $GLOBALS['postmeta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['postmeta'][ $id ][ $key ] = $value; return true; }
function get_term_meta( $id, $key, $single = true ) { return $GLOBALS['termmeta'][ $id ][ $key ] ?? ''; }
function update_term_meta( $id, $key, $value ) { $GLOBALS['termmeta'][ $id ][ $key ] = $value; return true; }
class TC_Stub_DB {
	public $postmeta = 'wp_postmeta'; public $posts = 'wp_posts';
	public $termmeta = 'wp_termmeta'; public $term_taxonomy = 'wp_term_taxonomy';
	public function prepare( $query, $value ) { return str_replace( '%s', "'" . $value . "'", $query ); }
	public function get_col( $sql ) {
		if ( false !== strpos( $sql, "'product'" ) ) return array( '_price', 'custom_warranty', 'supplier_reference' );
		if ( false !== strpos( $sql, "'product_cat'" ) ) return array( 'category_banner' );
		return array();
	}
}
$GLOBALS['wpdb'] = new TC_Stub_DB();
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
class WC_Product_Attribute {
	private $id = 0; private $name = ''; private $options = array();
	private $visible = true; private $variation = false; private $position = 0;
	public function set_id( $v ) { $this->id = $v; }
	public function set_name( $v ) { $this->name = $v; }
	public function set_options( $v ) { $this->options = $v; }
	public function set_visible( $v ) { $this->visible = $v; }
	public function set_variation( $v ) { $this->variation = $v; }
	public function set_position( $v ) { $this->position = $v; }
	public function get_name() { return $this->name; }
	public function get_options() { return $this->options; }
	public function get_visible() { return $this->visible; }
	public function get_variation() { return $this->variation; }
	public function get_position() { return $this->position; }
	public function is_taxonomy() { return false !== strpos( $this->name, 'pa_' ) && 0 === strpos( $this->name, 'pa_' ); }
	public function get_terms() {
		return array_map( static function( $id ) { return (object) array( 'slug' => 10 === $id ? 'red' : 'blue' ); }, $this->options );
	}
}
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
	private $values = array( 'sku' => '', 'name' => '', 'slug' => '', 'status' => 'publish', 'downloadable' => false, 'virtual' => false, 'downloads' => array(), 'attributes' => array() );
	private $metadata = array();
	public function __call( $name, $args ) {
		if ( 0 === strpos( $name, 'set_' ) ) $this->values[ substr( $name, 4 ) ] = $args[0];
		elseif ( 0 === strpos( $name, 'get_' ) ) return $this->values[ substr( $name, 4 ) ] ?? '';
	}
	public function get_id() { return $this->id; }
	public function get_type() { return 'simple'; }
	public function get_sku() { return $this->values['sku']; }
	public function get_slug() { return $this->values['slug']; }
	public function get_downloads() { return $this->values['downloads']; }
	public function get_attributes() { return $this->values['attributes']; }
	public function update_meta_data( $key, $value ) { $this->metadata[ $key ] = $value; }
	public function get_meta( $key, $single = true ) { return $this->metadata[ $key ] ?? ''; }
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
require dirname( __DIR__ ) . '/includes/class-native-fields.php';
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
// Native fields appear even when a shop has never installed another TaxoCommerce module.
$fields = CSV::fields( 'product' );
check_tciel( isset( $fields['meta:custom_warranty'] ), 'Registered scalar product custom meta discovered' );
check_tciel( isset( $fields['meta:supplier_reference'] ), 'Existing non-registered product custom meta discovered' );
check_tciel( ! isset( $fields['meta:_price'] ), 'Protected WooCommerce data not exposed as custom meta' );
check_tciel( isset( $fields['attr:pa_color'] ), 'Global WooCommerce attribute discovered' );
check_tciel( isset( CSV::fields( 'product_cat' )['meta:category_banner'] ), 'Product category custom metadata discovered' );
check_tciel( '' === CSV::suggest( 'product', 'random_supplier' ), 'No arbitrary foreign custom meta auto-map' );
check_tciel( 'meta:custom_warranty' === CSV::suggest( 'product', 'meta:custom_warranty' ), 'Own exported meta header roundtrips' );
check_tciel( ! is_wp_error( CSV::precheck( 'product', array( 'sku' => 'TC-CUSTOM', 'name' => 'Test custom' ) ) ), 'Precheck accepts a product without category' );
check_tciel( 2 === count( $GLOBALS['products'] ), 'Read-only precheck does not create products' );
check_tciel( is_wp_error( CSV::precheck( 'product', array( 'sku' => 'TC-CUSTOM', 'name' => 'Test custom', 'attr:pa_color' => 'green' ) ) ), 'Precheck detects unknown Woo attribute term before saving' );
check_tciel( 2 === count( $GLOBALS['products'] ), 'Invalid attribute precheck is side-effect free' );
$custom = array( 'sku' => 'TC-CUSTOM', 'slug' => 'test-custom', 'name' => 'Producto personalizado', 'meta:custom_warranty' => '24 meses', 'meta:supplier_reference' => 'CLIENTE-1', 'attr:pa_color' => 'red|blue' );
$custom_id = CSV::import( 'product', $custom );
check_tciel( is_int( $custom_id ) && $custom_id > 0, 'Product with native custom fields and attributes created' );
check_tciel( '24 meses' === CSV::export_value( 'product', $custom_id, 'meta:custom_warranty' ), 'WooCommerce custom meta exported' );
check_tciel( 'CLIENTE-1' === CSV::export_value( 'product', $custom_id, 'meta:supplier_reference' ), 'Nonregistered public meta exported' );
check_tciel( 'red|blue' === CSV::export_value( 'product', $custom_id, 'attr:pa_color' ), 'Global attribute values exported' );
check_tciel( $custom_id === CSV::import( 'product', $custom ), 'Custom product reimport uses same SKU' );
check_tciel( 3 === count( $GLOBALS['products'] ), 'Custom product did not duplicate on reimport' );
$bootstrap = file_get_contents( dirname( __DIR__ ) . '/taxocommerce-import-export-lite.php' );
check_tciel( false === strpos( $bootstrap, 'taxocommerce-import-export/includes/' ), 'No old plugin imports' );
check_tciel( false === strpos( $bootstrap, 'taxocommerce_core_register_modules' ), 'No Core registration' );

/** Test the real two-phase CSV AJAX handler with no WordPress installation. */
define( 'HOUR_IN_SECONDS', 3600 );
class TCIEL_JSON_Stop extends \Exception {
	public $result;
	public function __construct( $result ) { $this->result = $result; }
}
function wp_send_json_success( $data ) { throw new TCIEL_JSON_Stop( array( 'success' => true, 'data' => $data ) ); }
function wp_send_json_error( $data ) { throw new TCIEL_JSON_Stop( array( 'success' => false, 'data' => $data ) ); }
function check_ajax_referer( $a, $b ) { return true; }
function get_current_user_id() { return 1; }
function wp_unslash( $value ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_transient( $key ) { return $GLOBALS['transients'][ $key ] ?? false; }
function set_transient( $key, $value, $expiry ) { $GLOBALS['transients'][ $key ] = $value; }
function wp_delete_file( $path ) { @unlink( $path ); }
require dirname( __DIR__ ) . '/includes/class-admin.php';
function tciel_ajax_test( $values ) {
	$_POST = $values + array( 'action' => 'tciel_import_step', 'nonce' => 'fake', 'token' => 'testtoken' );
	try { \TaxoCommerce\ImportExportLite\Admin::step(); }
	catch ( TCIEL_JSON_Stop $result ) { return $result->result; }
	return array( 'success' => false );
}
$tmp = tempnam( sys_get_temp_dir(), 'tciel-' );
file_put_contents( $tmp, "sku,name,attr:pa_color\nTC-CSV-A,Producto A,red\nTC-CSV-B,Producto B,green\n" );
$GLOBALS['transients']['tciel_1_testtoken'] = array(
	'path' => $tmp, 'headers' => array( 'sku', 'name', 'attr:pa_color' ), 'delimiter' => ',',
	'filename' => 'dos-productos.csv', 'sample' => array( 'TC-CSV-A', 'Producto A', 'red' ),
	'offset' => 0, 'checked' => 0, 'processed' => 0, 'saved' => 0,
	'errors' => 0, 'notices' => array(), 'phase' => 'uploaded', 'started' => false, 'done' => false,
);
$mapping = array( 0 => 'sku', 1 => 'name', 2 => 'attr:pa_color' );
$input = array( 'type' => 'product', 'mode' => 'upsert', 'map' => $mapping );
$preflight = tciel_ajax_test( $input );
while ( $preflight['data']['phase'] === 'checking' ) $preflight = tciel_ajax_test( array() );
check_tciel( $preflight['success'] && 'invalid' === $preflight['data']['phase'], 'Preview fails when a global attribute value is missing' );
check_tciel( 0 === $preflight['data']['saved'] && 3 === count( $GLOBALS['products'] ), 'No product is saved during failed preflight' );
file_put_contents( $tmp, "sku,name,attr:pa_color\nTC-CSV-A,Producto A,red\nTC-CSV-B,Producto B,blue\n" );
$preflight = tciel_ajax_test( $input );
while ( $preflight['data']['phase'] === 'checking' ) $preflight = tciel_ajax_test( array() );
check_tciel( $preflight['success'] && 'ready' === $preflight['data']['phase'], 'Complete preflight requires explicit confirmation' );
check_tciel( 0 === $preflight['data']['saved'] && 3 === count( $GLOBALS['products'] ), 'Preflight never saves data' );
$imported = tciel_ajax_test( $input + array( 'confirmed' => '1' ) );
while ( $imported['data']['phase'] === 'importing' ) $imported = tciel_ajax_test( array() );
check_tciel( $imported['success'] && 'done' === $imported['data']['phase'], 'Second request executes confirmed import' );
check_tciel( 2 === $imported['data']['saved'] && 0 === $imported['data']['errors'], 'Two Woo products saved from CSV' );
check_tciel( 5 === count( $GLOBALS['products'] ), 'No unrelated products modified by CSV' );
check_tciel( ! file_exists( $tmp ), 'Temporary CSV removed after completed import' );

echo "Import-Export Lite: basic + custom metadata + Woo attributes + precheck + no duplication + standalone bootstrap OK\n";
