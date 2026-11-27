<?php
/** Isolated CLI smoke: WordPress+WooCommerce stubs, no TaxoCommerce Core or old importer. */
if ( 'cli' !== PHP_SAPI ) exit( 1 );
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'OBJECT', 'OBJECT' );
define( 'DOMAIN', 'taxocommerce-import-export-pro' ); // CSV class is namespaced; define matching below.
class WP_Error {
	private $message;
	private $code;
	public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function __( $str, $domain = '' ) { return $str; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function current_user_can( $cap, $id = 0 ) { return empty( $GLOBALS['denied_caps'][ $cap ] ); }
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
function wc_get_products( $query ) { $ids = array_keys( $GLOBALS['products'] ); sort( $ids, SORT_NUMERIC ); $limit = $query['limit'] ?? count( $ids ); return $limit > 0 ? array_slice( $ids, ( ( $query['page'] ?? 1 ) - 1 ) * $limit, $limit ) : $ids; }
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
function check_tciep( $value, $label ) {
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
use TaxoCommerce\ImportExportPro\CSV;
check_tciep( 7 === count( CSV::types() ), 'Native types all available' );
check_tciep( '' === CSV::suggest( 'product', 'Categories' ), 'Foreign categories not automatically mapped' );
check_tciep( '' === CSV::suggest( 'product', 'ID' ), 'Foreign IDs not automatically mapped' );
check_tciep( 'name' === CSV::suggest( 'product', 'Product Name' ), 'WooCommerce name mapped' );
check_tciep( 'download_1_url' === CSV::suggest( 'product', 'Download 1 URL' ), 'WooCommerce download URL mapped' );
check_tciep( "'=HYPERLINK()" === CSV::csv_safe( '=HYPERLINK()' ), 'Formula injection escaped' );
// A CSV for two new Mail products must pass without requiring existing records.
$lite_row = array( 'sku' => 'TC-MAIL-LITE', 'slug' => 'mail-product-fresh-lite', 'name' => 'Mail Lite' );
$pro_row  = array( 'sku' => 'TC-MAIL-PRO', 'slug' => 'mail-product-fresh-pro', 'name' => 'Mail Pro' );
check_tciep( true === CSV::precheck( 'product', $lite_row ), 'New Mail Lite precheck accepts upsert mode' );
check_tciep( true === CSV::precheck( 'product', $pro_row ), 'New Mail Pro precheck accepts upsert mode' );
check_tciep( is_wp_error( CSV::precheck( 'product', $pro_row, 'update' ) ), 'Update-only still rejects missing products' );
$GLOBALS['attachment_slug_index']['mail-product-fresh-pro'] = 20965;
$GLOBALS['lookup_post_status_override'][20965] = 'inherit';
check_tciep( true === CSV::precheck( 'product', $pro_row ), 'Attachment slug collision does not block precheck' );
check_tciep( is_wp_error( CSV::precheck( 'product', $pro_row, 'update' ) ), 'Attachment is not an existing product' );
unset( $GLOBALS['attachment_slug_index'], $GLOBALS['lookup_post_status_override'][20965] );
// WooCommerce SKU lookups can point to old records that no longer exist.
$GLOBALS['lookup_sku_override']['TC-MAIL-PRO'] = 9988;
check_tciep( true === CSV::precheck( 'product', $pro_row ), 'Orphaned SKU lookup is not a product' );
$GLOBALS['lookup_slug_override']['mail-product-fresh-pro'] = 9989;
check_tciep( true === CSV::precheck( 'product', $pro_row ), 'Orphaned slug lookup is not a product' );
// A trash record must never be updated or restored implicitly.
$GLOBALS['lookup_post_status_override'][9989] = 'trash';
check_tciep( true === CSV::precheck( 'product', $pro_row ), 'Trashed product ignored for new upsert' );
check_tciep( is_wp_error( CSV::precheck( 'product', $pro_row, 'update' ) ), 'Trashed product not updated' );
// Real catalog integrity errors must be visible, not silently overwritten.
$GLOBALS['reserved_skus']['TC-MAIL-PRO'] = true;
check_tciep( is_wp_error( CSV::precheck( 'product', $pro_row ) ), 'Reserved SKU still rejected' );
unset( $GLOBALS['reserved_skus']['TC-MAIL-PRO'], $GLOBALS['lookup_post_status_override'][9989] );
$GLOBALS['lookup_post_status_override'][9988] = 'publish';
check_tciep( is_wp_error( CSV::precheck( 'product', $pro_row ) ), 'Active broken product not overwritten' );
unset( $GLOBALS['lookup_sku_override'], $GLOBALS['lookup_slug_override'], $GLOBALS['lookup_post_status_override'] );
check_tciep( 0 === count( $GLOBALS['products'] ), 'No products saved during lookup precheck' );

$map = array( 0 => 'sku', 1 => 'name', 2 => 'download_1_url', 3 => 'categories' );
$data = CSV::map_row( array( 'TC-TEST-1', 'Plugin test', 'https://asociacionfocazul.com/wp-content/uploads/2026/11/taxocommerce-mail.zip', '' ), $map );
check_tciep( ! isset( $data['categories'] ), 'Empty optional taxonomy absent' );
$data['slug'] = 'taxocommerce-mail-lite';
$data['status'] = 'draft';
$data['regular_price'] = '0';
$data['virtual'] = 'yes';
$data['downloadable'] = 'yes';
$data['download_1_name'] = 'Mail Lite';
$id = CSV::import( 'product', $data );
check_tciep( is_int( $id ) && $id > 0, 'New downloadable product created' );
check_tciep( 'draft' === $GLOBALS['products'][ $id ]->get_status(), 'New product draft' );
check_tciep( true === $GLOBALS['products'][ $id ]->get_downloadable(), 'Downloadable saved' );
check_tciep( true === $GLOBALS['products'][ $id ]->get_virtual(), 'Virtual saved' );
check_tciep( 'Mail Lite' === CSV::export_value( 'product', $id, 'download_1_name' ), 'Native download exported' );
check_tciep( $data['download_1_url'] === CSV::export_value( 'product', $id, 'download_1_url' ), 'Native download URL exported' );
check_tciep( $id === CSV::import( 'product', $data ), 'Re-import matches existing SKU' );
check_tciep( 1 === count( $GLOBALS['products'] ), 'Re-import does not duplicate' );
$data['sku'] = 'TC-TEST-2';
$data['slug'] = 'taxocommerce-mail-pro';
check_tciep( is_wp_error( CSV::import( 'product', $data, 'update' ) ), 'Explicit update-only rejects unknown product' );
$GLOBALS['attachment_slug_index']['taxocommerce-mail-pro'] = 20965;
$GLOBALS['lookup_post_status_override'][20965] = 'inherit';
check_tciep( is_int( CSV::import( 'product', $data ) ), 'Default mode creates second product despite attachment slug' );
check_tciep( 20965 === $GLOBALS['attachment_slug_index']['taxocommerce-mail-pro'], 'Attachment remains unchanged' );
unset( $GLOBALS['attachment_slug_index'], $GLOBALS['lookup_post_status_override'][20965] );
check_tciep( 2 === count( $GLOBALS['products'] ), 'Second product exists' );
$data['categories'] = 'nonexisting-taxonomy';
check_tciep( is_wp_error( CSV::import( 'product', $data ) ), 'Explicit missing category rejected without silent creation' );
check_tciep( 2 === count( $GLOBALS['products'] ), 'Rejected taxonomy does not create duplicate' );
// Native fields appear even when a shop has never installed another TaxoCommerce module.
$fields = CSV::fields( 'product' );
check_tciep( isset( $fields['meta:custom_warranty'] ), 'Registered scalar product custom meta discovered' );
check_tciep( isset( $fields['meta:supplier_reference'] ), 'Existing non-registered product custom meta discovered' );
check_tciep( ! isset( $fields['meta:_price'] ), 'Protected WooCommerce data not exposed as custom meta' );
check_tciep( isset( $fields['attr:pa_color'] ), 'Global WooCommerce attribute discovered' );
check_tciep( isset( CSV::fields( 'product_cat' )['meta:category_banner'] ), 'Product category custom metadata discovered' );
check_tciep( '' === CSV::suggest( 'product', 'random_supplier' ), 'No arbitrary foreign custom meta auto-map' );
check_tciep( 'meta:custom_warranty' === CSV::suggest( 'product', 'meta:custom_warranty' ), 'Own exported meta header roundtrips' );
check_tciep( ! is_wp_error( CSV::precheck( 'product', array( 'sku' => 'TC-CUSTOM', 'name' => 'Test custom' ) ) ), 'Precheck accepts a product without category' );
check_tciep( 2 === count( $GLOBALS['products'] ), 'Read-only precheck does not create products' );
check_tciep( is_wp_error( CSV::precheck( 'product', array( 'sku' => 'TC-CUSTOM', 'name' => 'Test custom', 'attr:pa_color' => 'green' ) ) ), 'Precheck detects unknown Woo attribute term before saving' );
check_tciep( 2 === count( $GLOBALS['products'] ), 'Invalid attribute precheck is side-effect free' );
$custom = array( 'sku' => 'TC-CUSTOM', 'slug' => 'test-custom', 'name' => 'Producto personalizado', 'meta:custom_warranty' => '24 meses', 'meta:supplier_reference' => 'CLIENTE-1', 'attr:pa_color' => 'red|blue' );
$custom_id = CSV::import( 'product', $custom );
check_tciep( is_int( $custom_id ) && $custom_id > 0, 'Product with native custom fields and attributes created' );
check_tciep( '24 meses' === CSV::export_value( 'product', $custom_id, 'meta:custom_warranty' ), 'WooCommerce custom meta exported' );
check_tciep( 'CLIENTE-1' === CSV::export_value( 'product', $custom_id, 'meta:supplier_reference' ), 'Nonregistered public meta exported' );
check_tciep( 'red|blue' === CSV::export_value( 'product', $custom_id, 'attr:pa_color' ), 'Global attribute values exported' );
check_tciep( $custom_id === CSV::import( 'product', $custom ), 'Custom product reimport uses same SKU' );
check_tciep( 3 === count( $GLOBALS['products'] ), 'Custom product did not duplicate on reimport' );
$bootstrap = file_get_contents( dirname( __DIR__ ) . '/taxocommerce-import-export-pro.php' );
check_tciep( false === strpos( $bootstrap, 'taxocommerce-import-export/includes/' ), 'No old plugin imports' );
check_tciep( false === strpos( $bootstrap, 'taxocommerce_core_register_modules' ), 'No Core registration' );

// Pages, posts and categories use only native WordPress tables and preserve custom fields.
$post_data = array( 'slug' => 'prueba-post', 'title' => 'Entrada de prueba', 'meta:custom_reviewer' => 'Marina' );
check_tciep( ! is_wp_error( CSV::precheck( 'post', $post_data ) ), 'WordPress post preview succeeds' );
$post_id = CSV::import( 'post', $post_data );
check_tciep( is_int( $post_id ) && $post_id > 0, 'WordPress post created' );
check_tciep( 'Marina' === CSV::export_value( 'post', $post_id, 'meta:custom_reviewer' ), 'WordPress post custom metadata exported' );
check_tciep( $post_id === CSV::import( 'post', $post_data ), 'WordPress post reimport finds slug' );
$page_data = array( 'slug' => 'prueba-pagina', 'title' => 'Página de prueba' );
$page_id = CSV::import( 'page', $page_data );
check_tciep( is_int( $page_id ) && $page_id > 0, 'WordPress page created without taxonomy' );
check_tciep( $page_id === CSV::import( 'page', $page_data ), 'WordPress page import is idempotent' );
$term_data = array( 'slug' => 'general', 'name' => 'General', 'meta:category_banner' => 'Oferta estándar' );
check_tciep( ! is_wp_error( CSV::precheck( 'product_cat', $term_data ) ), 'WooCommerce product category precheck succeeds' );
$term_result = CSV::import( 'product_cat', $term_data );
check_tciep( is_array( $term_result ) && ! empty( $term_result['term_id'] ), 'Product category created' );
check_tciep( 'Oferta estándar' === CSV::export_value( 'product_cat', $term_result['term_id'], 'meta:category_banner' ), 'Product category custom metadata exported' );
$term_id = $term_result['term_id'];
check_tciep( $term_id === CSV::import( 'product_cat', $term_data )['term_id'], 'Product category reimport by slug is idempotent' );
// Existing local WooCommerce attribute discovered and imported independently.
$local_attr = new WC_Product_Attribute();
$local_attr->set_name( 'Material' );
$local_attr->set_options( array( 'Acero' ) );
$GLOBALS['products'][ $custom_id ]->set_attributes( array( 'pa_color' => $GLOBALS['products'][ $custom_id ]->get_attributes()['pa_color'], 'material' => $local_attr ) );
$reflection = new ReflectionClass( \TaxoCommerce\ImportExportPro\NativeFields::class );
foreach ( array( 'attributes_cache' => null, 'fields_cache' => array() ) as $key => $value ) {
	$prop = $reflection->getProperty( $key );
	$prop->setAccessible( true );
	$prop->setValue( $value );
}
check_tciep( isset( CSV::fields( 'product' )['attr:material'] ), 'Local WooCommerce attribute is discoverable from products' );
$local_data = array( 'sku' => 'TC-CUSTOM', 'attr:material' => 'Acero|Aluminio' );
check_tciep( $custom_id === CSV::import( 'product', $local_data ), 'Selected local attribute updates existing product' );
check_tciep( 'Acero|Aluminio' === CSV::export_value( 'product', $custom_id, 'attr:material' ), 'Local WooCommerce attribute exported without a custom table' );
check_tciep( 'red|blue' === CSV::export_value( 'product', $custom_id, 'attr:pa_color' ), 'Other global attribute preserved on local import' );

// Every content block recognizes its own native export headers automatically.
foreach ( CSV::types() as $kind => $title ) {
	$headers = array_keys( CSV::fields( $kind ) );
	$auto = CSV::automatic_mapping( $kind, $headers );
	check_tciep( ! is_wp_error( $auto ), 'Canonical export headers recognized for ' . $kind );
	check_tciep( ! in_array( 'id', $auto['map'], true ), 'Foreign ID is never imported for ' . $kind );
	check_tciep( array( 'id' ) === $auto['ignored'], 'Only ID omitted from canonical export of ' . $kind );
}
$auto = CSV::automatic_mapping( 'product', array( 'SKU', 'Product Name', 'Slug', 'Download 1 URL', 'meta:custom_warranty', 'attr:pa_color', 'Categories', 'Unknown' ) );
check_tciep( array( 'sku', 'name', 'slug', 'download_1_url', 'meta:custom_warranty', 'attr:pa_color' ) === array_values( $auto['map'] ), 'Native aliases and existing custom fields recognized' );
check_tciep( array( 'Categories', 'Unknown' ) === $auto['ignored'], 'Foreign taxonomy and unknown header disclosed' );
check_tciep( is_wp_error( CSV::automatic_mapping( 'product', array( 'sku', 'SKU', 'name' ) ) ), 'Duplicate aliases rejected' );
check_tciep( is_wp_error( CSV::automatic_mapping( 'product', array( 'id', 'name' ) ) ), 'ID-only product identity rejected' );
check_tciep( is_wp_error( CSV::automatic_mapping( 'page', array( 'id', 'title' ) ) ), 'Page requires slug rather than foreign ID' );
check_tciep( 'slug' === CSV::automatic_mapping( 'page', array( 'post_name', 'post_title' ) )['map'][0], 'WordPress native slug alias recognized' );
check_tciep( 'categories' === CSV::automatic_mapping( 'product', array( 'sku', 'categories' ) )['map'][1], 'Explicit canonical relationship header retained' );


define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['tciep_options'] = $GLOBALS['tciep_events'] = $GLOBALS['tciep_hooks'] = array();
$GLOBALS['tciep_user'] = 1;
$GLOBALS['tciep_salt'] = bin2hex( random_bytes( 16 ) );
function get_current_user_id() { return $GLOBALS['tciep_user']; }
function get_current_blog_id() { return 1; }
function get_userdata( $id ) { return $id > 0 ? (object) array( 'ID' => $id ) : false; }
function wp_set_current_user( $id ) { $GLOBALS['tciep_user'] = $id; }
function wp_salt( $scheme = '' ) { return $GLOBALS['tciep_salt']; }
function wp_unslash( $value ) { return $value; }
function get_temp_dir() { return sys_get_temp_dir(); }
function wp_is_writable( $dir ) { return is_writable( $dir ); }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0700, true ); }
function wp_generate_uuid4() { return bin2hex( random_bytes( 16 ) ); }
function wp_delete_file( $path ) { if ( is_file( $path ) ) unlink( $path ); }
function get_option( $key, $default = false ) { return $GLOBALS['tciep_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['tciep_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['tciep_options'][ $key ] ); return true; }
function wp_next_scheduled( $hook, $args ) { return $GLOBALS['tciep_events'][ serialize( $args ) ] ?? false; }
function wp_schedule_single_event( $at, $hook, $args ) { $GLOBALS['tciep_events'][ serialize( $args ) ] = $at; return true; }
function wp_clear_scheduled_hook( $hook, $args ) { unset( $GLOBALS['tciep_events'][ serialize( $args ) ] ); }
function wp_unschedule_hook( $hook ) { $GLOBALS['tciep_events'] = array(); }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['tciep_hooks'][ $hook ][] = $callback; }
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['tciep_filters'][ $hook ][] = $callback; }
function register_deactivation_hook( $file, $callback ) { $GLOBALS['tciep_deactivate'] = $callback; }
function wp_strip_all_tags( $text ) { return strip_tags( $text ); }
function determine_locale() { return 'es_ES'; }
function get_file_data( $file, $fields, $context = '' ) {
    $source = file_get_contents( $file );
    $result = array();
    foreach ( $fields as $key => $label ) {
        $result[ $key ] = preg_match( '/^\s*\*\s*' . preg_quote( $label, '/' ) . ':\s*(.+)$/m', $source, $match ) ? trim( $match[1] ) : '';
    }
    return $result;
}
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function esc_html__( $value, $domain = '' ) { return esc_html( $value ); }
function esc_attr__( $value, $domain = '' ) { return esc_attr( $value ); }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function add_menu_page( ...$args ) { $GLOBALS['tciep_menus'][] = array( 'top', $args ); }
function add_submenu_page( ...$args ) { $GLOBALS['tciep_menus'][] = array( 'sub', $args ); }
function selected( $a, $b, $echo = true ) { return $a == $b ? ' selected="selected"' : ''; }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $action ) . '">'; }
function submit_button( $label, $class = 'primary', $name = 'submit', $wrap = true ) { echo '<button type="submit">' . esc_html( $label ) . '</button>'; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
require dirname( __DIR__ ) . '/taxocommerce-import-export-pro.php';
foreach ( $GLOBALS['tciep_hooks']['plugins_loaded'] as $boot ) { $boot(); }
use TaxoCommerce\ImportExportPro\Batch;
use TaxoCommerce\ImportExportPro\Load;
use TaxoCommerce\ImportExportPro\Admin;
check_tciep( isset( $GLOBALS['tciep_hooks']['wp_ajax_tciep_command'], $GLOBALS['tciep_hooks'][Batch::HOOK], $GLOBALS['tciep_hooks']['admin_post_taxocommerce_import_export_pro_code_check'] ), 'Independent hooks registered' );
check_tciep( ! isset( $GLOBALS['tciep_hooks']['wp_ajax_tciel_import_step'] ), 'No Lite hooks registered' );
check_tciep( 5 === count( Load::profiles() ), 'Five CPU profiles' );
check_tciep( 10 === Load::adjust( 20, 100, 20, 2, 8, 80, 30 ), 'Memory pressure reduces batch' );
check_tciep( 40 === Load::adjust( 20, 100, 20, 1, 8, 20, 30 ), 'Headroom increases batch' );
check_tciep( 20 === Load::adjust( 20, 100, 5, 2, 8, 20, 30 ), 'Partial chunk does not grow' );
check_tciep( 'taxocommerce-ie-pro' === \TaxoCommerce\ImportExportPro\ADMIN_SLUG, 'Admin route does not collide with original prefix' );

$root = Batch::root();
check_tciep( is_string( $root ) && is_dir( $root ), 'Private test spool available' );
function seed_job( $type, $csvs ) {
    $initial = new ReflectionMethod( Batch::class, 'initial' );
    $initial->setAccessible( true );
    $state = $initial->invoke( null, 'import', $type, 1 );
    check_tciep( is_array( $state ), 'Job initialized' );
    $dir = Batch::root() . '/' . $state['token'];
    foreach ( $csvs as $i => $csv ) {
        $path = $dir . '/input-' . $i . '.csv';
        file_put_contents( $path, $csv );
        $file = Batch::inspect( $path, $type, 'batch-' . $i . '.csv' );
        check_tciep( ! is_wp_error( $file ), 'File inspected: ' . $i );
        $state['files'][] = $file;
    }
    update_option( 'tciep_job_1_import_' . $type, $state, false );
    return $state;
}
function command_job( $state, $op, $speed = 3 ) {
    return Batch::command( $state['owner'], $state['kind'], $state['type'], $state['token'], $op, $speed );
}
function run_until( $state, $phase ) {
    for ( $i = 0; $i < 300 && $phase === $state['phase'] && ! $state['paused']; ++$i ) {
        $state = command_job( $state, 'step' );
        check_tciep( is_array( $state ), 'Worker returned a job state' );
    }
    check_tciep( $phase !== $state['phase'] || $state['paused'], 'Bounded worker terminates or pauses' );
    return $state;
}
$products_before = count( $GLOBALS['products'] );
$posts_before = count( $GLOBALS['posts'] );
$p = seed_job( 'product', array( "sku,name,slug\nBATCH-1,First,batch-first\n", "sku;name;slug\nBATCH-2;Second;batch-second\n" ) );
$q = seed_job( 'page', array( "slug,title\nbatch-page,Page\n" ) );
check_tciep( is_wp_error( command_job( $p, 'confirm' ) ), 'Cannot confirm before full preflight' );
check_tciep( is_wp_error( Batch::command( 1, 'import', 'page', $p['token'], 'step' ) ), 'Product token cannot address page job' );
check_tciep( is_wp_error( Batch::command( 2, 'import', 'product', $p['token'], 'step' ) ), 'Other user cannot operate owner job' );
$lock = fopen( $root . '/worker.lock', 'c' );
flock( $lock, LOCK_EX );
check_tciep( is_wp_error( command_job( $p, 'step' ) ), 'Concurrent worker refused' );
flock( $lock, LOCK_UN );
fclose( $lock );
$p = run_until( $p, 'checking' );
check_tciep( 'ready' === $p['phase'] && $products_before === count( $GLOBALS['products'] ), 'Both files checked without writes' );
check_tciep( 2 === array_sum( array_column( $p['files'], 'checked' ) ), 'Both CSV delimiters handled' );
check_tciep( 'checking' === Batch::state( 1, 'import', 'page' )['phase'] && $posts_before === count( $GLOBALS['posts'] ), 'Page queue is independent' );
$p = command_job( $p, 'confirm' );
$p = command_job( $p, 'pause' );
check_tciep( $p['paused'] && $products_before === count( $GLOBALS['products'] ), 'Pause before writing' );
check_tciep( $p === command_job( $p, 'step' ), 'Paused worker cannot write' );
$p = command_job( $p, 'speed', 5 );
check_tciep( 5 === $p['speed'], 'Change load profile on job' );
$p = command_job( $p, 'resume' );
$p = run_until( $p, 'importing' );
check_tciep( 'done' === $p['phase'] && $products_before + 2 === count( $GLOBALS['products'] ), 'Two files imported' );
check_tciep( 2 === array_sum( array_column( $p['files'], 'saved' ) ), 'Per-file saved counters' );
check_tciep( array() === command_job( $p, 'clear' ), 'Archive completed import' );
check_tciep( 1 === count( Batch::history( 1, 'import', 'product' ) ), 'Job history kept' );
check_tciep( ! Batch::state( 1, 'import', 'product' ), 'Active state cleared' );

$q = run_until( $q, 'checking' );
check_tciep( 'ready' === $q['phase'] && $posts_before === count( $GLOBALS['posts'] ), 'Page preflight read-only' );
$q = command_job( $q, 'confirm' );
$q['inflight'] = true;
update_option( 'tciep_job_1_import_page', $q, false );
$q = command_job( $q, 'step' );
check_tciep( $q['paused'] && $posts_before === count( $GLOBALS['posts'] ), 'Interrupted marker pauses without replay' );
$q = command_job( $q, 'resume' );
$q = run_until( $q, 'importing' );
check_tciep( 'done' === $q['phase'] && $posts_before + 1 === count( $GLOBALS['posts'] ), 'Explicit resume imports page' );
command_job( $q, 'clear' );

$bad = seed_job( 'product', array( "sku,name\nGOOD-1,Good\n", "sku,name\nBAD-1,Duplicate\nBAD-1,Duplicate again\n" ) );
$before = count( $GLOBALS['products'] );
$bad = run_until( $bad, 'checking' );
check_tciep( 'invalid' === $bad['phase'] && $before === count( $GLOBALS['products'] ), 'Bad second CSV blocks entire batch' );
check_tciep( 1 === $bad['files'][1]['errors'] && is_wp_error( command_job( $bad, 'confirm' ) ), 'Duplicate row reported; confirm blocked' );
command_job( $bad, 'clear' );
for ( $i = 0; $i < 115; ++$i ) {
    check_tciep( is_int( CSV::import( 'product', array( 'sku' => 'EXPORT-' . $i, 'name' => 'Export ' . $i, 'slug' => 'export-' . $i ) ) ), 'Create export fixture' );
}
$expected_fields = array_keys( CSV::fields( 'product' ) );
$e = Batch::create_export( 'product', 1 );
check_tciep( is_array( $e ) && $expected_fields === $e['export']['fields'], 'Complete compatible export schema fixed by content type' );
check_tciep( false === Batch::download_path( $e ), 'Partial export is not downloadable' );
$e = command_job( $e, 'step' );
check_tciep( 'exporting' === $e['phase'] && $e['export']['rows'] > 0, 'Export proceeds by bounded chunks' );
file_put_contents( $root . '/' . $e['token'] . '/export.csv', "UNCONFIRMED,BYTES\n", FILE_APPEND );
$e = command_job( $e, 'speed', 5 );
$e = run_until( $e, 'exporting' );
check_tciep( 'done' === $e['phase'], 'Export reaches completion over fixed pages' );
$path = Batch::download_path( $e );
check_tciep( is_string( $path ), 'Authorized completed file exists' );
$csv = array_map( 'str_getcsv', file( $path, FILE_IGNORE_NEW_LINES ) );
check_tciep( $expected_fields === array_shift( $csv ), 'Native CSV header preserved' );
check_tciep( count( $GLOBALS['products'] ) === count( $csv ) && count( $csv ) === count( array_unique( array_column( $csv, array_search( 'sku', $expected_fields, true ) ) ) ), 'All records exported once across multiple pages and crash bytes removed' );
$GLOBALS['denied_caps']['read_private_products'] = true;
check_tciep( ! Batch::allowed( 'export', 'product' ), 'Private export needs capabilities' );
check_tciep( is_wp_error( command_job( $e, 'clear' ) ), 'Revoked permission blocks controls' );
unset( $GLOBALS['denied_caps'] );
command_job( $e, 'clear' );
check_tciep( ! is_file( $path ), 'Archive removes private export' );
check_tciep( ! isset( $GLOBALS['tciep_events'][ serialize( array( 1, 'export', 'product', $e['token'] ) ) ] ), 'Completed job cron cleared' );

ob_start();
\TaxoCommerce\ImportExportPro\Header::render();
$header = ob_get_clean();
check_tciep( 1 === substr_count( $header, '<header class="tciep-hero">' ) && false !== strpos( $header, '<h1>TaxoCommerce Import-Export Pro</h1>' ) && false !== strpos( $header, '0.1.2' ), 'One independent Pro header' );
ob_start();
Admin::blocks( 'import' );
$import = ob_get_clean();
ob_start();
Admin::blocks( 'export' );
$export = ob_get_clean();
check_tciep( 7 === substr_count( $import, 'name="csv_files[]"' ) && 7 === substr_count( $import, 'multiple required' ), 'Seven separate multi-file import forms' );
check_tciep( 7 === substr_count( $import, 'Seleccionar archivos CSV del lote' ) && 7 === substr_count( $import, 'Cargar lote y comprobar CSV' ) && 7 === substr_count( $import, 'class="tciep-selection"' ), 'Explicit batch upload and file selection summary in each block' );
check_tciep( 7 === substr_count( $export, 'value="tciep_create_export"' ), 'Seven separate export forms' );
check_tciep( false === strpos( $import, 'name="map' ) && false === strpos( $import, 'name="mode' ), 'No mapping or action selector' );
check_tciep( false === strpos( $export, 'name="fields' ) && false === strpos( $export, 'type="checkbox"' ), 'No export field selectors' );
check_tciep( false === strpos( $import . $export, '<select name="type"' ), 'No type selection' );
check_tciep( class_exists( 'TaxoCommerce_Import_Export_Pro_Autotest_Code' ) && 5 === count( TaxoCommerce_Import_Export_Pro_Autotest_Code::speeds() ), 'Independent code check is available' );
// Optional integration registers only one route, with or without Core.
$GLOBALS['tciep_menus'] = array();
Admin::menu();
check_tciep( 1 === count( $GLOBALS['tciep_menus'] ) && 'top' === $GLOBALS['tciep_menus'][0][0] && \TaxoCommerce\ImportExportPro\ADMIN_SLUG === $GLOBALS['tciep_menus'][0][1][3], 'Standalone top-level menu without Core' );
$GLOBALS['tciep_menus'] = array();
define( 'TAXOCOMMERCE_CORE_VERSION', 'test' );
Admin::menu();
check_tciep( 1 === count( $GLOBALS['tciep_menus'] ) && 'sub' === $GLOBALS['tciep_menus'][0][0] && 'taxocommerce-core' === $GLOBALS['tciep_menus'][0][1][0] && \TaxoCommerce\ImportExportPro\ADMIN_SLUG === $GLOBALS['tciep_menus'][0][1][4], 'Only the Core submenu when Core is active' );
$catalog = array( 'import_export' => array( 'name' => 'Original' ), 'other' => array( 'name' => 'Other' ) );
foreach ( $GLOBALS['tciep_filters']['taxocommerce_core_family_extensions'] as $filter ) { $catalog = $filter( $catalog ); }
check_tciep( 3 === count( $catalog ) && isset( $catalog['import_export'], $catalog['other'] ), 'Core card preserves other extensions' );
check_tciep( 'taxocommerce-import-export-pro/taxocommerce-import-export-pro.php' === $catalog['import_export_pro']['plugin_file'] && 'taxocommerce-import-export-pro' === $catalog['import_export_pro']['org_slug'] && false === $catalog['import_export_pro']['wordpress_org'], 'Pro card has exact plugin basename and independent identity' );
$core = new class {
    public $modules = array();
    public function register_module( $id, $module ) { $this->modules[ $id ] = $module; }
};
foreach ( $GLOBALS['tciep_hooks']['taxocommerce_core_register_modules'] as $hook ) { $hook( $core ); }
check_tciep( '0.1.2' === $core->modules['import_export_pro']['version'] && $catalog['import_export_pro']['admin_url'] === $core->modules['import_export_pro']['admin_url'] && false !== strpos( $core->modules['import_export_pro']['admin_url'], 'page=taxocommerce-ie-pro' ), 'Core module opens exact Pro route' );
echo "Import-Export Pro: explicit multi-file controls, optional Core card and menu OK\n";
unlink( $root . '/worker.lock' );
rmdir( $root );
echo "Import-Export Pro: native CSV, seven blocks, multi-file preflight/confirm, independent queues, pause/resume, lock, history, adaptive export and code check OK\n";

// Run the actual multipart regression in the existing plugin CI entrypoint.
require __DIR__ . '/upload-storage-smoke.php';
