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
function sanitize_title( $s ) { return strtolower( trim( preg_replace( '/[^a-z0-9_-]+/i', '-', $s ), '-' ) ); }
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
function get_post_type( $id ) { return isset( $GLOBALS['posts'][ $id ] ) ? $GLOBALS['posts'][ $id ]->post_type : 'product'; }
function get_post_status( $id ) {
	if ( isset( $GLOBALS['lookup_post_status_override'][ $id ] ) ) return $GLOBALS['lookup_post_status_override'][ $id ];
	if ( isset( $GLOBALS['products'][ $id ] ) ) return $GLOBALS['products'][ $id ]->get_status();
	return isset( $GLOBALS['posts'][ $id ] ) ? $GLOBALS['posts'][ $id ]->post_status : false;
}
function get_term_by( $kind, $value, $tax ) {
	if ( 'pa_color' === $tax && in_array( strtolower( $value ), array( 'red', 'blue' ), true ) ) {
		return (object) array( 'term_id' => 'red' === strtolower( $value ) ? 10 : 11, 'slug' => strtolower( $value ) );
	}
	foreach ( (array) ( $GLOBALS['terms'][ $tax ] ?? array() ) as $term ) {
		if ( isset( $term->$kind ) && $term->$kind === $value ) return $term;
	}
	return false;
}
function wp_insert_term( $name, $tax, $args = array() ) {
	$id = ++$GLOBALS['last_term_id'];
	$GLOBALS['terms'][ $tax ][ $id ] = (object) array( 'term_id' => $id, 'name' => $name, 'slug' => $args['slug'] ?? sanitize_title( $name ), 'description' => $args['description'] ?? '', 'parent' => $args['parent'] ?? 0 );
	return array( 'term_id' => $id, 'term_taxonomy_id' => $id );
}
function wp_update_term( $id, $tax, $args ) {
	foreach ( $args as $key => $value ) $GLOBALS['terms'][ $tax ][ $id ]->$key = $value;
	return array( 'term_id' => $id );
}
function get_term( $id, $tax ) { return $GLOBALS['terms'][ $tax ][ $id ] ?? false; }
function wp_insert_post( $args, $return_error = true ) {
	$id = isset( $args['ID'] ) ? (int) $args['ID'] : ++$GLOBALS['last_id'];
	$defaults = array( 'ID' => $id, 'post_type' => 'post', 'post_title' => '', 'post_name' => '', 'post_status' => 'draft', 'post_content' => '', 'post_excerpt' => '', 'post_date' => '', 'menu_order' => 0 );
	$old = isset( $GLOBALS['posts'][ $id ] ) ? (array) $GLOBALS['posts'][ $id ] : array();
	$GLOBALS['posts'][ $id ] = (object) array_merge( $defaults, $old, $args );
	return $id;
}
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? false; }
function set_post_thumbnail( $id, $image_id ) { return true; }
function get_post_thumbnail_id( $id ) { return 0; }
function wp_get_object_terms( $id, $tax, $args ) { return array(); }
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
	$types = is_array( $type ) ? $type : array( $type, 'attachment' );
	if ( in_array( 'attachment', $types, true ) && isset( $GLOBALS['attachment_slug_index'][ $slug ] ) ) return (object) array( 'ID' => $GLOBALS['attachment_slug_index'][ $slug ] );
	$type = is_array( $type ) ? reset( $type ) : $type;
	if ( 'product' === $type && isset( $GLOBALS['lookup_slug_override'][ $slug ] ) ) return (object) array( 'ID' => $GLOBALS['lookup_slug_override'][ $slug ] );
	foreach ( $GLOBALS['products'] as $p ) {
		if ( 'product' === $type && $p->get_slug() === $slug ) return (object) array( 'ID' => $p->get_id() );
	}
	foreach ( $GLOBALS['posts'] as $p ) if ( $type === $p->post_type && $p->post_name === $slug ) return $p;
	return null;
}
function wp_set_object_terms( $id, $terms, $tax, $append ) { return $terms; }
function wc_get_product_id_by_sku( $sku ) {
	if ( isset( $GLOBALS['lookup_sku_override'][ $sku ] ) ) return $GLOBALS['lookup_sku_override'][ $sku ];
	foreach ( $GLOBALS['products'] as $p ) if ( $sku === $p->get_sku() ) return $p->get_id();
	return 0;
}
function wc_get_product( $id ) { return $GLOBALS['products'][ $id ] ?? false; }
function wc_product_has_unique_sku( $id, $sku ) { return ! isset( $GLOBALS['reserved_skus'][ $sku ] ); }
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
$GLOBALS['last_term_id'] = 900;
$GLOBALS['posts'] = array();
$GLOBALS['terms'] = array();
$GLOBALS['postmeta'] = array();
$GLOBALS['termmeta'] = array();
require dirname( __DIR__ ) . '/includes/class-native-fields.php';
require dirname( __DIR__ ) . '/includes/class-csv.php';
use TaxoCommerce\ImportExportLite\CSV;
check_tciel( 7 === count( CSV::types() ), 'Native types all available' );
check_tciel( '' === CSV::suggest( 'product', 'Categories' ), 'Foreign categories not automatically mapped' );
check_tciel( '' === CSV::suggest( 'product', 'ID' ), 'Foreign IDs not automatically mapped' );
check_tciel( 'name' === CSV::suggest( 'product', 'Product Name' ), 'WooCommerce name mapped' );
check_tciel( 'download_1_url' === CSV::suggest( 'product', 'Download 1 URL' ), 'WooCommerce download URL mapped' );
check_tciel( "'=HYPERLINK()" === CSV::csv_safe( '=HYPERLINK()' ), 'Formula injection escaped' );
// A CSV for two new Mail products must pass without requiring existing records.
$lite_row = array( 'sku' => 'TC-MAIL-LITE', 'slug' => 'mail-product-fresh-lite', 'name' => 'Mail Lite' );
$pro_row  = array( 'sku' => 'TC-MAIL-PRO', 'slug' => 'mail-product-fresh-pro', 'name' => 'Mail Pro' );
check_tciel( true === CSV::precheck( 'product', $lite_row ), 'New Mail Lite precheck accepts upsert mode' );
check_tciel( true === CSV::precheck( 'product', $pro_row ), 'New Mail Pro precheck accepts upsert mode' );
check_tciel( is_wp_error( CSV::precheck( 'product', $pro_row, 'update' ) ), 'Update-only still rejects missing products' );
$GLOBALS['attachment_slug_index']['mail-product-fresh-pro'] = 20965;
$GLOBALS['lookup_post_status_override'][20965] = 'inherit';
check_tciel( true === CSV::precheck( 'product', $pro_row ), 'Attachment slug collision does not block precheck' );
check_tciel( is_wp_error( CSV::precheck( 'product', $pro_row, 'update' ) ), 'Attachment is not an existing product' );
unset( $GLOBALS['attachment_slug_index'], $GLOBALS['lookup_post_status_override'][20965] );
// WooCommerce SKU lookups can point to old records that no longer exist.
$GLOBALS['lookup_sku_override']['TC-MAIL-PRO'] = 9988;
check_tciel( true === CSV::precheck( 'product', $pro_row ), 'Orphaned SKU lookup is not a product' );
$GLOBALS['lookup_slug_override']['mail-product-fresh-pro'] = 9989;
check_tciel( true === CSV::precheck( 'product', $pro_row ), 'Orphaned slug lookup is not a product' );
// A trash record must never be updated or restored implicitly.
$GLOBALS['lookup_post_status_override'][9989] = 'trash';
check_tciel( true === CSV::precheck( 'product', $pro_row ), 'Trashed product ignored for new upsert' );
check_tciel( is_wp_error( CSV::precheck( 'product', $pro_row, 'update' ) ), 'Trashed product not updated' );
// Real catalog integrity errors must be visible, not silently overwritten.
$GLOBALS['reserved_skus']['TC-MAIL-PRO'] = true;
check_tciel( is_wp_error( CSV::precheck( 'product', $pro_row ) ), 'Reserved SKU still rejected' );
unset( $GLOBALS['reserved_skus']['TC-MAIL-PRO'], $GLOBALS['lookup_post_status_override'][9989] );
$GLOBALS['lookup_post_status_override'][9988] = 'publish';
check_tciel( is_wp_error( CSV::precheck( 'product', $pro_row ) ), 'Active broken product not overwritten' );
unset( $GLOBALS['lookup_sku_override'], $GLOBALS['lookup_slug_override'], $GLOBALS['lookup_post_status_override'] );
check_tciel( 0 === count( $GLOBALS['products'] ), 'No products saved during lookup precheck' );

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
$GLOBALS['attachment_slug_index']['taxocommerce-mail-pro'] = 20965;
$GLOBALS['lookup_post_status_override'][20965] = 'inherit';
check_tciel( is_int( CSV::import( 'product', $data ) ), 'Default mode creates second product despite attachment slug' );
check_tciel( 20965 === $GLOBALS['attachment_slug_index']['taxocommerce-mail-pro'], 'Attachment remains unchanged' );
unset( $GLOBALS['attachment_slug_index'], $GLOBALS['lookup_post_status_override'][20965] );
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

// Pages, posts and categories use only native WordPress tables and preserve custom fields.
$post_data = array( 'slug' => 'prueba-post', 'title' => 'Entrada de prueba', 'meta:custom_reviewer' => 'Marina' );
check_tciel( ! is_wp_error( CSV::precheck( 'post', $post_data ) ), 'WordPress post preview succeeds' );
$post_id = CSV::import( 'post', $post_data );
check_tciel( is_int( $post_id ) && $post_id > 0, 'WordPress post created' );
check_tciel( 'Marina' === CSV::export_value( 'post', $post_id, 'meta:custom_reviewer' ), 'WordPress post custom metadata exported' );
check_tciel( $post_id === CSV::import( 'post', $post_data ), 'WordPress post reimport finds slug' );
$page_data = array( 'slug' => 'prueba-pagina', 'title' => 'Página de prueba' );
$page_id = CSV::import( 'page', $page_data );
check_tciel( is_int( $page_id ) && $page_id > 0, 'WordPress page created without taxonomy' );
check_tciel( $page_id === CSV::import( 'page', $page_data ), 'WordPress page import is idempotent' );
$term_data = array( 'slug' => 'general', 'name' => 'General', 'meta:category_banner' => 'Oferta estándar' );
check_tciel( ! is_wp_error( CSV::precheck( 'product_cat', $term_data ) ), 'WooCommerce product category precheck succeeds' );
$term_result = CSV::import( 'product_cat', $term_data );
check_tciel( is_array( $term_result ) && ! empty( $term_result['term_id'] ), 'Product category created' );
check_tciel( 'Oferta estándar' === CSV::export_value( 'product_cat', $term_result['term_id'], 'meta:category_banner' ), 'Product category custom metadata exported' );
$term_id = $term_result['term_id'];
check_tciel( $term_id === CSV::import( 'product_cat', $term_data )['term_id'], 'Product category reimport by slug is idempotent' );
// Existing local WooCommerce attribute discovered and imported independently.
$local_attr = new WC_Product_Attribute();
$local_attr->set_name( 'Material' );
$local_attr->set_options( array( 'Acero' ) );
$GLOBALS['products'][ $custom_id ]->set_attributes( array( 'pa_color' => $GLOBALS['products'][ $custom_id ]->get_attributes()['pa_color'], 'material' => $local_attr ) );
$reflection = new ReflectionClass( \TaxoCommerce\ImportExportLite\NativeFields::class );
foreach ( array( 'attributes_cache' => null, 'fields_cache' => array() ) as $key => $value ) {
	$prop = $reflection->getProperty( $key );
	$prop->setAccessible( true );
	$prop->setValue( $value );
}
check_tciel( isset( CSV::fields( 'product' )['attr:material'] ), 'Local WooCommerce attribute is discoverable from products' );
$local_data = array( 'sku' => 'TC-CUSTOM', 'attr:material' => 'Acero|Aluminio' );
check_tciel( $custom_id === CSV::import( 'product', $local_data ), 'Selected local attribute updates existing product' );
check_tciel( 'Acero|Aluminio' === CSV::export_value( 'product', $custom_id, 'attr:material' ), 'Local WooCommerce attribute exported without a custom table' );
check_tciel( 'red|blue' === CSV::export_value( 'product', $custom_id, 'attr:pa_color' ), 'Other global attribute preserved on local import' );

// Every content block recognizes its own native export headers automatically.
foreach ( CSV::types() as $kind => $title ) {
	$headers = array_keys( CSV::fields( $kind ) );
	$auto = CSV::automatic_mapping( $kind, $headers );
	check_tciel( ! is_wp_error( $auto ), 'Canonical export headers recognized for ' . $kind );
	check_tciel( ! in_array( 'id', $auto['map'], true ), 'Foreign ID is never imported for ' . $kind );
	check_tciel( array( 'id' ) === $auto['ignored'], 'Only ID omitted from canonical export of ' . $kind );
}
$auto = CSV::automatic_mapping( 'product', array( 'SKU', 'Product Name', 'Slug', 'Download 1 URL', 'meta:custom_warranty', 'attr:pa_color', 'Categories', 'Unknown' ) );
check_tciel( array( 'sku', 'name', 'slug', 'download_1_url', 'meta:custom_warranty', 'attr:pa_color' ) === array_values( $auto['map'] ), 'Native aliases and existing custom fields recognized' );
check_tciel( array( 'Categories', 'Unknown' ) === $auto['ignored'], 'Foreign taxonomy and unknown header disclosed' );
check_tciel( is_wp_error( CSV::automatic_mapping( 'product', array( 'sku', 'SKU', 'name' ) ) ), 'Duplicate aliases rejected' );
check_tciel( is_wp_error( CSV::automatic_mapping( 'product', array( 'id', 'name' ) ) ), 'ID-only product identity rejected' );
check_tciel( is_wp_error( CSV::automatic_mapping( 'page', array( 'id', 'title' ) ) ), 'Page requires slug rather than foreign ID' );
check_tciel( 'slug' === CSV::automatic_mapping( 'page', array( 'post_name', 'post_title' ) )['map'][0], 'WordPress native slug alias recognized' );
check_tciel( 'categories' === CSV::automatic_mapping( 'product', array( 'sku', 'categories' ) )['map'][1], 'Explicit canonical relationship header retained' );

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
	'type' => 'product', 'map' => CSV::automatic_mapping( 'product', array( 'sku', 'name', 'attr:pa_color' ) )['map'], 'ignored' => array(),
	'filename' => 'dos-productos.csv', 'sample' => array( 'TC-CSV-A', 'Producto A', 'red' ),
	'offset' => 0, 'checked' => 0, 'processed' => 0, 'saved' => 0,
	'errors' => 0, 'notices' => array(), 'phase' => 'uploaded', 'started' => false, 'done' => false,
);
// AJAX accepts only the token and confirmation; client-supplied type/mode/map must never override the bound schema.
$input = array();
$preflight = tciel_ajax_test( $input );
while ( $preflight['data']['phase'] === 'checking' ) $preflight = tciel_ajax_test( array() );
check_tciel( $preflight['success'] && 'invalid' === $preflight['data']['phase'], 'Preview fails when a global attribute value is missing' );
check_tciel( 0 === $preflight['data']['saved'] && 3 === count( $GLOBALS['products'] ), 'No product is saved during failed preflight' );
file_put_contents( $tmp, "sku,name,attr:pa_color\nTC-CSV-A,Producto A,red\nTC-CSV-B,Producto B,blue\n" );
$preflight = tciel_ajax_test( array( 'type' => 'page', 'mode' => 'update', 'map' => array( 0 => 'id' ) ) );
while ( $preflight['data']['phase'] === 'checking' ) $preflight = tciel_ajax_test( array() );
check_tciel( $preflight['success'] && 'ready' === $preflight['data']['phase'], 'Complete preflight requires explicit confirmation' );
check_tciel( 0 === $preflight['data']['saved'] && 3 === count( $GLOBALS['products'] ), 'Preflight never saves data' );
$imported = tciel_ajax_test( array( 'confirmed' => '1', 'type' => 'page', 'mode' => 'update', 'map' => array( 0 => 'id' ) ) );
while ( $imported['data']['phase'] === 'importing' ) $imported = tciel_ajax_test( array() );
check_tciel( $imported['success'] && 'done' === $imported['data']['phase'], 'Second request executes confirmed import' );
check_tciel( 2 === $imported['data']['saved'] && 0 === $imported['data']['errors'], 'Two Woo products saved from CSV' );
check_tciel( 5 === count( $GLOBALS['products'] ), 'No unrelated products modified by CSV' );
check_tciel( ! file_exists( $tmp ), 'Temporary CSV removed after completed import' );

echo "Import-Export Lite: basic + custom metadata + Woo attributes + precheck + no duplication + standalone bootstrap OK\n";

// Header regression: entirely self-contained, using local plugin metadata and
// bundled changelog, no Core, remote services or administrator settings.
function get_file_data( $file, $fields, $context = '' ) {
	$source = file_get_contents( $file );
	$info   = array();
	foreach ( $fields as $key => $header ) {
		$found = preg_match( '/^[ \t]*\*[ \t]*' . preg_quote( $header, '/' ) . ':[ \t]*(.+)$/mi', $source, $match );
		$info[ $key ] = $found ? trim( $match[1] ) : '';
	}
	return $info;
}
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function determine_locale() { return 'es_ES'; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $value ) { return (string) $value; }
require dirname( __DIR__ ) . '/includes/class-header.php';
ob_start();
\TaxoCommerce\ImportExportLite\Header::render();
$header = ob_get_clean();
check_tciel( 1 === substr_count( $header, '<h1>' ), 'One header title; no duplicate H1' );
check_tciel( false !== strpos( $header, 'TaxoCommerce Import-Export Lite' ), 'Plugin metadata title in header' );
check_tciel( false !== strpos( $header, 'Versión 0.1.7' ), 'Version follows main plugin header' );
check_tciel( false !== strpos( $header, 'Última actualización: 2026-11-27' ), 'Valid release date displayed' );
check_tciel( false !== strpos( $header, 'Independent CSV import and export' ), 'Plugin metadata description displayed' );
check_tciel( false !== strpos( $header, '<details class="tciel-hero__history">' ), 'Own changelog is collapsed by default' );
check_tciel( false !== strpos( $header, '[0.1.7]' ), 'Latest changelog entry included' );
check_tciel( false !== strpos( $header, 'Historial de versiones y mejoras' ), 'WordPress Spanish locale fallback' );
check_tciel( false !== strpos( $header, 'github.com/distribuidordeherramientases-wq/seo-taxonomy/tree/staging/taxocommerce-import-export-lite' ), 'Official source link from plugin URI' );
echo "Import-Export Lite: autonomous admin header metadata, localized labels and bundled history OK\n";


// Fixed blocks render their own file and download forms, without type, action or field-mapping selectors.
function esc_attr( $value ) { return esc_html( $value ); }
function esc_html__( $value, $domain = '' ) { return esc_html( $value ); }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function wp_nonce_field( $action, $name = '_wpnonce', $referer = true, $display = true ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $action ) . '">'; }
function submit_button( $text, $type = 'primary', $name = 'submit', $wrap = true ) { echo '<input type="submit" value="' . esc_attr( $text ) . '">'; }
$_GET = array();
ob_start();
\TaxoCommerce\ImportExportLite\Admin::render_import();
$import_html = ob_get_clean();
ob_start();
\TaxoCommerce\ImportExportLite\Admin::render_export();
$export_html = ob_get_clean();
check_tciel( 7 === substr_count( $import_html, 'name="csv_file"' ), 'One import form per supported type' );
check_tciel( 7 === substr_count( $export_html, 'value="tciel_export"' ), 'One export form per supported type' );
check_tciel( false === strpos( $import_html . $export_html, '<select' ), 'No content or mapping selectors remain' );
foreach ( CSV::types() as $kind => $title ) {
	check_tciel( false !== strpos( $import_html, 'id="tciel-import-' . $kind . '"' ), 'Fixed import block for ' . $kind );
	check_tciel( false !== strpos( $export_html, 'name="type" value="' . $kind . '"' ), 'Fixed export block for ' . $kind );
}
echo "Import-Export Lite: fixed content blocks and server-bound automatic mapping OK\n";

 // Use a separate process: the HTTP upload regression needs an actual document root.
$upload_exit = 1;
passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/upload-storage-smoke.php' ), $upload_exit );
check_tciel( 0 === $upload_exit, 'Private HTTP CSV storage regression' );

// The Lite navigation exposes four sections only; old technical bookmarks fall back safely.
define( 'TaxoCommerce\\ImportExportLite\\SLUG', 'taxocommerce-import-export-lite' );
function esc_attr__( $value, $domain = '' ) { return esc_attr( $value ); }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function get_option( $key, $default = false ) { return $default; }
require dirname( __DIR__ ) . '/includes/class-manual.php';
require dirname( __DIR__ ) . '/includes/class-autocheck.php';
foreach ( array( 'import', 'export', 'check', 'manual', 'technical', 'code' ) as $requested ) {
    $_GET = array( 'tab' => $requested );
    ob_start();
    \TaxoCommerce\ImportExportLite\Admin::render();
    $screen = ob_get_clean();
    preg_match( '~<nav class="nav-tab-wrapper".*?</nav>~s', $screen, $nav );
    check_tciel( isset( $nav[0] ) && 4 === substr_count( $nav[0], '<a class="nav-tab' ), 'Exactly four Lite tabs: ' . $requested );
    foreach ( array( 'import', 'export', 'check', 'manual' ) as $visible ) {
        check_tciel( false !== strpos( $nav[0], 'tab=' . $visible ), 'Retained Lite tab: ' . $visible );
    }
    check_tciel( false === strpos( $nav[0], 'tab=technical' ) && false === strpos( $nav[0], 'tab=code' ), 'No professional tabs in Lite' );
    if ( in_array( $requested, array( 'technical', 'code' ), true ) ) {
        check_tciel( false !== strpos( $screen, 'id="tciel-import-page"' ), 'Old professional route falls back to import' );
    }
}
check_tciel( ! method_exists( \TaxoCommerce\ImportExportLite\Manual::class, 'technical' ), 'No unused technical manual renderer in Lite' );
echo "Import-Export Lite: four tabs, user manual and functional check retained, professional routes excluded OK\n";
