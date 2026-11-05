<?php
/**
 * Offline regression: user-created supplier templates from inert CSV/JSON.
 */
define( 'ABSPATH', __DIR__ );
$GLOBALS['tcsup_test_options'] = array();
class WP_Error {
	private $code;
	public function __construct( $code, $message ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function __( $value, $domain = '' ) { return $value; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function get_option( $name, $default = false ) { return $GLOBALS['tcsup_test_options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = false ) { $GLOBALS['tcsup_test_options'][ $name ] = $value; return true; }
require_once dirname( __DIR__ ) . '/includes/class-tcsup-csv.php';
function tcsup_verify( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . "\n" );
		exit( 1 );
	}
}
$json = '{"mapping":{"proveedor_id_externo":"Supplier ID","nombre":"Product Name","precio_con_iva":"Price","moneda":"Currency"}}';
$id = TCSUP_CSV::create_template_from_definition( 'My supplier', 'Vendor A', $json, 'json' );
tcsup_verify( 'custom_mysupplier' === $id, 'create JSON template' );
$templates = TCSUP_CSV::templates();
tcsup_verify( isset( $templates[ $id ] ) && 'Product Name' === $templates[ $id ]['mapping']['nombre'], 'JSON mapping persisted' );
tcsup_verify( 'Vendor A' === $templates[ $id ]['provider'], 'provider persisted' );
tcsup_verify( is_wp_error( TCSUP_CSV::create_template_from_definition( 'My supplier', 'Other', $json, 'json' ) ), 'prevent overwritten definition' );
$csv = "field;source\nproveedor_id_externo;Product ID\nnombre;Product Title\n";
$id2 = TCSUP_CSV::create_template_from_definition( 'Other supplier', 'Vendor B', $csv, 'csv' );
tcsup_verify( 'custom_othersupplier' === $id2, 'create CSV template' );
tcsup_verify( 'Product Title' === TCSUP_CSV::templates()[ $id2 ]['mapping']['nombre'], 'CSV map persisted' );
tcsup_verify( is_wp_error( TCSUP_CSV::parse_template_definition( '{"mapping":{"nombre":"Only name"}}', 'json' ) ), 'required fields' );
tcsup_verify( is_wp_error( TCSUP_CSV::parse_template_definition( '{"mapping":{"proveedor_id_externo":"id","nombre":"name","invalid":"bad"}}', 'json' ) ), 'reject unrecognized canonical field' );
tcsup_verify( is_wp_error( TCSUP_CSV::parse_template_definition( "<?php system('id');", 'txt' ) ), 'reject PHP snippets' );
tcsup_verify( is_wp_error( TCSUP_CSV::parse_template_definition( str_repeat( 'A', 65537 ), 'json' ) ), 'reject oversized content' );
tcsup_verify( is_wp_error( TCSUP_CSV::parse_template_definition( "proveedor_id_externo;id\nproveedor_id_externo;id2\nnombre;name\n", 'csv' ) ), 'reject duplicate mappings' );
tcsup_verify( is_wp_error( TCSUP_CSV::parse_template_definition( "{", 'json' ) ), 'reject invalid JSON' );
tcsup_verify( ! isset( TCSUP_CSV::templates()['canonical'] ), 'generic import modes are not supplier templates' );
tcsup_verify( 'canonical' === TCSUP_CSV::import_templates()['canonical']['id'], 'keep canonical CSV import available' );
tcsup_verify( count( TCSUP_CSV::templates() ) === 2, 'only the two user-created templates are listed' );
echo "OK: Suppliers template creation and validation\n";
