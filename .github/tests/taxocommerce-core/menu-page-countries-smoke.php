<?php
/**
 * Regression: menu countries come only from page assignments; Global is empty.
 * php .github/tests/taxocommerce-core/menu-page-countries-smoke.php
 */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
    private $message;
    public function __construct( $code, $message ) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
class WP_Post {
    public $ID;
    public $post_name;
    public $post_status = 'publish';
    public function __construct( $id, $status = 'publish' ) {
        $this->ID = $id;
        $this->post_name = 'page-' . $id;
        $this->post_status = $status;
    }
}
class WP_Term {
    public $term_id;
    public $name;
    public function __construct( $id ) {
        $this->term_id = $id;
        $this->name = 'Category ' . $id;
    }
}
class TaxoCommerce_Core_Logger { public function info( $event, $context ) {} }
class TaxoCommerce_Core_Hierarchy_Classic {
    public static function role_pages( $role ) { return $GLOBALS['role_pages'][ $role ] ?? array(); }
    public static function map( $type, $country = '' ) { return $GLOBALS['maps'][ $type ] ?? array(); }
    public static function sync_role_country( $id ) { return true; }
}
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return $text; }
function esc_html_e( $text, $domain = '' ) { echo esc_html__( $text, $domain ); }
function esc_url( $url ) { return $url; }
function admin_url( $path ) { return '/wp-admin/' . $path; }
function get_the_title( $page ) { return 'Page ' . $page->ID; }
function wp_get_nav_menu_items( $id ) { return array(); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function selected( $current, $expected, $echo = true ) {
    $value = (string) $current === (string) $expected ? ' selected="selected"' : '';
    if ( $echo ) { echo $value; }
    return $value;
}
function wp_nonce_field( $action, $name ) {}
function absint( $value ) { return abs( (int) $value ); }
function current_user_can( $cap, $id = 0 ) { return true; }
function get_post_type( $id ) { return 'page'; }
function get_post( $id ) { return $GLOBALS['pages'][ $id ] ?? null; }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['post_meta'][ $id ][ $key ] ?? ''; }
function get_term_meta( $id, $key, $single = true ) { return $GLOBALS['term_meta'][ $id ][ $key ] ?? ''; }
function get_ancestors( $id, $taxonomy, $type = 'taxonomy' ) { return $id === 201 ? array( 200 ) : array(); }
function get_posts( $query ) {
    $ids = array();
    foreach ( $GLOBALS['pages'] as $id => $page ) {
        if ( ! in_array( $page->post_status, (array) ( $query['post_status'] ?? array() ), true ) ) { continue; }
        if ( ! array_key_exists( $query['meta_key'] ?? '', $GLOBALS['post_meta'][ $id ] ?? array() ) ) { continue; }
        $ids[] = $id;
    }
    return $ids;
}
function get_terms( $query ) { return array_values( $GLOBALS['terms'] ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = true ) { $GLOBALS['options'][ $key ] = $value; return true; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['post_meta'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['post_meta'][ $id ][ $key ] ); return true; }
function wp_get_nav_menu_object( $value ) {
    if ( is_numeric( $value ) ) { return $GLOBALS['menus'][ (int) $value ] ?? false; }
    foreach ( $GLOBALS['menus'] as $menu ) {
        if ( $menu->name === $value ) { return $menu; }
    }
    return false;
}
function wp_create_nav_menu( $name ) {
    $id = max( array_keys( $GLOBALS['menus'] ) ) + 1;
    $GLOBALS['menus'][ $id ] = (object) array( 'term_id' => $id, 'name' => $name );
    return $id;
}
function wp_update_nav_menu_object( $id, $args ) {
    $GLOBALS['menus'][ $id ]->name = $args['menu-name'];
    return $id;
}
function is_admin() { return false; }
function get_theme_mod( $name, $default = array() ) { return $GLOBALS['theme_locations']; }
function is_singular( $types ) { return true; }
function is_tax( $taxonomy ) { return false; }
function get_queried_object_id() { return $GLOBALS['queried_id']; }
function verify( $result, $message ) {
    if ( ! $result ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}

$GLOBALS['pages'] = array();
foreach ( array( 10, 11, 12, 20, 21, 22, 30, 31, 32, 40, 50 ) as $id ) {
    $GLOBALS['pages'][ $id ] = new WP_Post( $id, 50 === $id ? 'trash' : 'publish' );
}
$GLOBALS['post_meta'] = array( 50 => array( '_seo_pais_origen' => 'US' ) );
$GLOBALS['term_meta'] = array();
$GLOBALS['terms'] = array(
    100 => new WP_Term( 100 ),
    200 => new WP_Term( 200 ),
    201 => new WP_Term( 201 ),
    300 => new WP_Term( 300 ),
);
$GLOBALS['role_pages'] = array(
    'cluster' => array( $GLOBALS['pages'][10], $GLOBALS['pages'][20], $GLOBALS['pages'][30] ),
    'hub_primary' => array( $GLOBALS['pages'][11], $GLOBALS['pages'][21], $GLOBALS['pages'][31] ),
    'hub_secondary' => array( $GLOBALS['pages'][12], $GLOBALS['pages'][22], $GLOBALS['pages'][32] ),
);
$GLOBALS['maps'] = array(
    'cluster_to_primary' => array( 10 => array( 11 => true, 21 => true ), 20 => array( 21 => true ), 30 => array( 31 => true ) ),
    'hub_primary_to_hub_secondary' => array( 11 => array( 12 => true ), 21 => array( 22 => true ), 31 => array( 32 => true ) ),
    'hub_secondary_to_category' => array( 12 => array( 100 => true, 200 => true ), 22 => array( 200 => true, 201 => true ), 32 => array( 300 => true ) ),
);
$GLOBALS['options'] = array( 'taxocommerce_core_menu_id' => 99 );
$GLOBALS['menus'] = array( 99 => (object) array( 'term_id' => 99, 'name' => 'TaxoCommerce' ) );
$GLOBALS['queried_id'] = 10;
$GLOBALS['theme_locations'] = array();

require dirname( __DIR__, 3 ) . '/taxocommerce-core/includes/class-taxocommerce-core-country.php';
require dirname( __DIR__, 3 ) . '/taxocommerce-core/includes/class-taxocommerce-core-menu.php';

verify( array_keys( TaxoCommerce_Core_Menu::available_countries() ) === array( '' ), 'fresh site only has Global (no configured country)' );
$proposal = new TaxoCommerce_Core_Menu( new TaxoCommerce_Core_Logger() );
$before_menus = count( $GLOBALS['menus'] );
ob_start();
$proposal->render_hierarchy_proposal();
$empty_country_proposal = ob_get_clean();
verify( false !== strpos( $empty_country_proposal, '<option value=""' ), 'propose menu renders Global with empty country' );
verify( false === strpos( $empty_country_proposal, '<option value="ES"' ), 'propose menu never includes implicit ES' );
verify( false === strpos( $empty_country_proposal, '<option value="US"' ), 'propose menu never includes implicit US' );
verify( $before_menus === count( $GLOBALS['menus'] ), 'view proposal never creates menus' );
verify( TaxoCommerce_Core_Menu::country() === '', 'menu selection without a country remains Global' );
verify( TaxoCommerce_Core_Menu::page_country( 10 ) === '', 'page without country is Global despite legacy ES fallback' );
verify( TaxoCommerce_Core_Country::post( 10 ) === 'ES', 'legacy Core country inference is left intact' );
verify( TaxoCommerce_Core_Menu::menu_option( '' ) === 'taxocommerce_core_menu_id_global', 'Global has own menu option' );
verify( TaxoCommerce_Core_Menu::menu_name( '' ) === 'TaxoCommerce - Global', 'Global menu has explicit name' );
verify( TaxoCommerce_Core_Menu::managed_menu_id( 'ES', false ) === 99, 'existing ES menu is retained' );
$global_id = TaxoCommerce_Core_Menu::managed_menu_id( '', true );
verify( $global_id !== 99, 'creating Global does not adopt or rename ES legacy menu' );
verify( $GLOBALS['menus'][99]->name === 'TaxoCommerce', 'legacy menu name was not changed' );
verify( $GLOBALS['options']['taxocommerce_core_menu_id_global'] === $global_id, 'Global ID is stored independently' );

ob_start();
TaxoCommerce_Core_Country::native_post_box( $GLOBALS['pages'][10] );
$global_field = ob_get_clean();
verify( false !== strpos( $global_field, '<option value="" selected="selected">' ), 'unassigned page shows Global selected' );
verify( false === strpos( $global_field, '<option value="ES" selected="selected">' ), 'ES is not automatically selected' );

foreach ( array( 20, 21, 22 ) as $id ) { $GLOBALS['post_meta'][ $id ]['_seo_pais_origen'] = 'FR'; }
foreach ( array( 30, 31, 32 ) as $id ) { $GLOBALS['post_meta'][ $id ]['_seo_pais_origen'] = 'DE'; }
$GLOBALS['term_meta'][200] = array( '_seo_pais_origen' => 'FR' );
$GLOBALS['term_meta'][300] = array( '_seo_pais_origen' => 'DE' );
verify( array_keys( TaxoCommerce_Core_Menu::available_countries() ) === array( '', 'DE', 'FR' ), 'only explicitly assigned page countries appear' );
verify( ! array_key_exists( 'ES', TaxoCommerce_Core_Menu::available_countries() ), 'implicit Spain never appears' );
verify( ! array_key_exists( 'US', TaxoCommerce_Core_Menu::available_countries() ), 'trashed pages do not introduce a market' );
$menus_before_assigned_proposal = count( $GLOBALS['menus'] );
ob_start();
$proposal->render_hierarchy_proposal();
$assigned_country_proposal = ob_get_clean();
verify( false !== strpos( $assigned_country_proposal, '<option value="FR"' ), 'propose menu includes country used on pages' );
verify( false !== strpos( $assigned_country_proposal, '<option value="DE"' ), 'propose menu includes another page country' );
verify( false === strpos( $assigned_country_proposal, '<option value="ES"' ), 'propose menu excludes unused ES' );
verify( false === strpos( $assigned_country_proposal, '<option value="US"' ), 'propose menu excludes trashed-only US' );
verify( $menus_before_assigned_proposal === count( $GLOBALS['menus'] ), 'rendering a proposal does not modify existing native menus' );
verify( TaxoCommerce_Core_Menu::term_country( 201 ) === 'FR', 'category inherits explicit country' );

$menu = new TaxoCommerce_Core_Menu( new TaxoCommerce_Core_Logger() );
$method = new ReflectionMethod( TaxoCommerce_Core_Menu::class, 'build_tree' );
$global_tree = $method->invoke( $menu, '' );
$fr_tree = $method->invoke( $menu, 'FR' );
$de_tree = $method->invoke( $menu, 'DE' );
verify( count( $global_tree ) === 1 && $global_tree[0]['page']->ID === 10, 'Global tree only has unassigned clusters' );
verify( count( $global_tree[0]['primaries'] ) === 1 && $global_tree[0]['primaries'][0]['page']->ID === 11, 'Global excludes cross-country primary relations' );
verify( count( $global_tree[0]['primaries'][0]['secondaries'][0]['categories'] ) === 1, 'Global excludes FR categories' );
verify( $global_tree[0]['primaries'][0]['secondaries'][0]['categories'][0]->term_id === 100, 'Global includes unassigned category' );
verify( count( $fr_tree ) === 1 && $fr_tree[0]['page']->ID === 20, 'FR gets its own cluster' );
verify( count( $fr_tree[0]['primaries'][0]['secondaries'][0]['categories'] ) === 2, 'FR includes inherited-country categories' );
verify( count( $de_tree ) === 1 && $de_tree[0]['page']->ID === 30, 'DE gets its own cluster' );

$fr_id = TaxoCommerce_Core_Menu::managed_menu_id( 'FR', true );
$GLOBALS['theme_locations'] = array( 'primary' => $global_id );
$GLOBALS['queried_id'] = 20;
$args = $menu->route_country_menu( array( 'theme_location' => 'primary' ) );
verify( (int) $args['menu'] === $fr_id, 'published page with FR uses its country menu' );
$GLOBALS['queried_id'] = 10;
$args = $menu->route_country_menu( array( 'theme_location' => 'primary' ) );
verify( (int) $args['menu'] === $global_id, 'page with no country uses Global' );

verify( TaxoCommerce_Core_Country::set_post( 40, 'GB' ) === true, 'new explicitly assigned two-letter code can be stored' );
verify( array_key_exists( 'GB', TaxoCommerce_Core_Menu::available_countries() ), 'page with GB makes GB available without a preset' );
verify( TaxoCommerce_Core_Country::set_post( 40, '' ) === true, 'choosing Global removes country meta' );
verify( ! array_key_exists( '_seo_pais_origen', $GLOBALS['post_meta'][40] ?? array() ), 'Global is stored as no country meta' );
verify( ! array_key_exists( 'GB', TaxoCommerce_Core_Menu::available_countries() ), 'removed page country disappears from menu selector' );

echo 'Core menu countries from pages / Global smoke OK' . PHP_EOL;
