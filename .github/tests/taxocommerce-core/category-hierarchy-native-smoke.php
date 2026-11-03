<?php
/**
 * Offline regression of native product-category texts and migration of page roles.
 * Run: php .github/tests/taxocommerce-core/category-hierarchy-native-smoke.php
 */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function sanitize_title( $v ) { return sanitize_key( $v ); }
function sanitize_text_field( $v ) { return (string) $v; }
function wp_kses_post( $v ) { return (string) $v; }
function wp_unslash( $v ) { return $v; }
function __( $v, $domain = '' ) { return $v; }
function current_user_can( $cap ) { return true; }
function get_taxonomy( $taxonomy ) { return (object) array( 'cap' => (object) array( 'edit_terms' => 'manage_categories' ) ); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function get_post_type( $id ) { return 'page'; }
function get_term( $id, $tax ) { return (object) array( 'term_id' => $id ); }
function wp_update_term( $id, $tax, $values ) { $GLOBALS['saved_term'] = $values; return array( 'term_id' => $id ); }
function update_term_meta( $id, $key, $value ) { $GLOBALS['term_meta'][ $id ][ $key ] = $value; return true; }
function get_term_meta( $id, $key, $single = true ) { return $GLOBALS['term_meta'][ $id ][ $key ] ?? ''; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['options'][ $key ] = $value; return true; }
function metadata_exists( $type, $id, $key ) { return array_key_exists( $key, $GLOBALS['post_meta'][ $id ] ?? array() ); }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['post_meta'][ $id ][ $key ] ?? ''; }
function add_post_meta( $id, $key, $value, $unique = false ) {
    if ( metadata_exists( 'post', $id, $key ) && $unique ) { return false; }
    $GLOBALS['post_meta'][ $id ][ $key ] = $value;
    return true;
}
function check( $okay, $message ) {
    if ( ! $okay ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}
class WP_Error {
    public function __construct( $code, $message ) {}
}
class TaxoCommerce_Core_Editor_UI {
    public static function image_id() { return null; }
}
class TaxoCommerce_Core_Hierarchy {
    const ROLE_META = '_taxocommerce_core_seo_role';
    public function roles() { return array( 'cluster' => 'Cluster', 'hub_primary' => 'Primary', 'hub_secondary' => 'Secondary', 'landing' => 'Landing' ); }
}
class TaxoCommerce_Core_Country {
    public static function post( $id ) { return ''; }
}
class NativeSmokeDb {
    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    public $calls = 0;
    public function esc_like( $s ) { return $s; }
    public function prepare( $q, ...$args ) { return $q; }
    public function get_var( $q ) { return false !== strpos( $q, 'SHOW TABLES' ) ? 'wp_seo_nodes' : null; }
    public function get_results( $q, $format = null ) {
        ++$this->calls;
        return array(
            array( 'object_id' => 10, 'seo_role' => 'cluster' ),
            array( 'object_id' => 20, 'seo_role' => 'hub_secondary' ),
            array( 'object_id' => 30, 'seo_role' => 'hub_primary' ),
        );
    }
}
$GLOBALS['wpdb'] = new NativeSmokeDb();
$GLOBALS['options'] = array();
$GLOBALS['post_meta'] = array( 20 => array( TaxoCommerce_Core_Hierarchy::ROLE_META => 'landing' ) );
require dirname( __DIR__, 3 ) . '/taxocommerce-core/includes/class-taxocommerce-core-content-manager.php';
require dirname( __DIR__, 3 ) . '/taxocommerce-core/includes/class-taxocommerce-core-hierarchy-classic.php';

$manager = (new ReflectionClass( 'TaxoCommerce_Core_Content_Manager' ))->newInstanceWithoutConstructor();
$save = new ReflectionMethod( 'TaxoCommerce_Core_Content_Manager', 'save_product_category' );
$save->setAccessible( true );
$_POST = array(
    'name' => 'Example', 'slug' => 'example', 'parent' => '0',
    'content' => '<p>Long description</p>', 'excerpt' => 'Native category summary'
);
check( true === $save->invoke( $manager, 55 ), 'category save succeeds' );
check( $GLOBALS['saved_term']['description'] === '<p>Long description</p>', 'description saved natively' );
check( get_term_meta( 55, 'seo_excerpt', true ) === 'Native category summary', 'excerpt saved into termmeta' );

$_POST = array( 'name' => 'Example', 'slug' => 'example', 'parent' => '0', 'content' => 'Updated' );
check( true === $save->invoke( $manager, 55 ), 'native category edit without excerpt' );
check( get_term_meta( 55, 'seo_excerpt', true ) === 'Native category summary', 'missing excerpt does not erase stored data' );

$hierarchy = new TaxoCommerce_Core_Hierarchy();
TaxoCommerce_Core_Hierarchy_Classic::migrate_page_roles_to_native( $hierarchy );
check( TaxoCommerce_Core_Hierarchy_Classic::role_node( 10 ) === 'cluster', 'historical role migrated' );
check( TaxoCommerce_Core_Hierarchy_Classic::role_node( 20 ) === 'landing', 'existing WordPress role preserved' );
check( TaxoCommerce_Core_Hierarchy_Classic::role_node( 30 ) === 'hub_primary', 'remaining historical role migrated' );
check( $GLOBALS['wpdb']->calls === 1, 'one historical read only' );
TaxoCommerce_Core_Hierarchy_Classic::migrate_page_roles_to_native( $hierarchy );
check( $GLOBALS['wpdb']->calls === 1, 'migration is idempotent' );
check( true === get_option( 'taxocommerce_core_roles_native_v1', false ), 'migration completion recorded' );
echo "Core native categories and page-role migration OK\n";
