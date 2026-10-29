<?php
/**
 * User and technical guide regression test. Boots the real Logistics plugin
 * and prevents any access to WooCommerce orders or persisted options.
 *
 * php taxocommerce-logistica/tests/user-guide-smoke.php
 */
define( 'ABSPATH', __DIR__ );
$GLOBALS['guide_styles'] = array();
$GLOBALS['guide_actions'] = array();
$GLOBALS['guide_filters'] = array();

function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/taxocommerce-logistica/'; }
function add_action( $hook, $handler, $priority = 10, $arguments = 1 ) { $GLOBALS['guide_actions'][ $hook ][] = $handler; }
function add_filter( $hook, $handler, $priority = 10, $arguments = 1 ) { $GLOBALS['guide_filters'][ $hook ][] = $handler; }
function add_menu_page( $page_title, $menu_title, $capability, $slug, $callback, $icon = '', $position = null ) {
    $GLOBALS['guide_menu'][] = array( 'slug' => $slug, 'title' => $menu_title, 'callback' => $callback );
    return 'toplevel_page_' . $slug;
}
function current_user_can( $cap ) { return ! empty( $GLOBALS['guide_denied'] ) ? false : 'manage_woocommerce' === $cap || 'manage_options' === $cap; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_url( $value ); }
function esc_html( $value ) { return esc_url( $value ); }
function esc_html_e( $value, $domain = '' ) { echo esc_html( $value ); }
function esc_attr_e( $value, $domain = '' ) { echo esc_attr( $value ); }
function esc_html__( $value, $domain = '' ) { return $value; }
function __( $value, $domain = '' ) { return $value; }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function wp_enqueue_style( $handle, $source, $dependencies, $version ) { $GLOBALS['guide_styles'][ $handle ] = $source; }
function get_option( $name, $default = false ) { throw new RuntimeException( 'The guide must not read shipping options.' ); }
function update_option() { throw new RuntimeException( 'The guide must never write shipping options.' ); }
function wc_get_orders() { throw new RuntimeException( 'The guide must not read real orders.' ); }
function wp_remote_get() { throw new RuntimeException( 'The guide must not contact external servers.' ); }
function wp_remote_post() { throw new RuntimeException( 'The guide must not contact external servers.' ); }
function update_user_meta() { throw new RuntimeException( 'The guide must not write usermeta.' ); }

class WooCommerce {}
require dirname( __DIR__ ) . '/taxocommerce-logistica.php';

function assert_guide( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL Logistics User Guide: ' . $message . PHP_EOL );
        exit( 1 );
    }
}
assert_guide( defined( 'TAXOCOMMERCE_LOGISTICA_VERSION' ) && '0.2.4' === TAXOCOMMERCE_LOGISTICA_VERSION, 'Version 0.2.4' );
taxocommerce_logistica_register_menu();
assert_guide( count( $GLOBALS['guide_menu'] ?? array() ) === 1, 'Standalone fallback menu registered when Core is absent' );
assert_guide( 'taxocommerce-logistica' === $GLOBALS['guide_menu'][0]['slug'], 'Standalone fallback retains unique route' );
assert_guide( isset( $GLOBALS['guide_actions']['admin_enqueue_scripts'] ), 'Manual CSS registered' );
$_GET['page'] = 'taxocommerce-logistica';
$_GET['tc_logistica_tab'] = 'user-guide';
$_GET['tc_logistica_manual'] = 'user';
TaxoCommerce_Logistica_User_Guide::assets();
assert_guide( isset( $GLOBALS['guide_styles']['tc-logistica-user-guide'] ), 'Only manual CSS loaded' );

ob_start();
TaxoCommerce_Logistica_Transporte_Costes::render_page();
$user = ob_get_clean();
assert_guide( false !== strpos( $user, '>Manual</a>' ) && false !== strpos( $user, 'User Manual' ) && false !== strpos( $user, 'Technical Manual' ), 'Homogeneous manual tab and document navigation' );
assert_guide( false !== strpos( $user, 'Shipping Rules' ) && false !== strpos( $user, 'Auto Test' ), 'Existing tabs preserved' );
assert_guide( 10 === substr_count( $user, '<section id="tc-log-user-' ), 'Ten user chapters' );
assert_guide( false !== strpos( $user, '#tc-log-user-json' ) && false !== strpos( $user, '#tc-log-user-autotest' ), 'Linked index entries' );
assert_guide( false !== strpos( $user, 'REEMPLAZA' ), 'Destructive JSON import warning visible' );
assert_guide( false !== strpos( $user, 'aria-current="page"' ), 'Active manual accessible' );
assert_guide( false === strpos( $user, 'name="seo_transporte[enabled]"' ), 'No operational settings form' );

$_GET['tc_logistica_manual'] = 'technical';
ob_start();
TaxoCommerce_Logistica_Transporte_Costes::render_page();
$technical = ob_get_clean();
assert_guide( 11 === substr_count( $technical, '<section id="tc-log-tech-' ), 'Eleven technical chapters' );
assert_guide( false !== strpos( $technical, '#tc-log-tech-rules' ) && false !== strpos( $technical, '#tc-log-tech-security' ), 'Technical index links' );
assert_guide( false !== strpos( $technical, 'seo_transporte_costes_settings' ), 'Historical configuration documented' );
assert_guide( false !== strpos( $technical, 'woocommerce_package_rates' ), 'Shipping integrations documented' );
assert_guide( false !== strpos( $technical, '<h3>' ), 'H3 headings rendered' );

$_GET['tc_logistica_manual'] = 'unknown';
ob_start();
TaxoCommerce_Logistica_Transporte_Costes::render_page();
$default = ob_get_clean();
assert_guide( false !== strpos( $default, 'tc-log-user-intro' ), 'Unknown selector safely defaults to user manual' );

$GLOBALS['guide_denied'] = true;
try {
    TaxoCommerce_Logistica_Transporte_Costes::render_page();
    assert_guide( false, 'Unauthorized documentation screen must reject access' );
} catch ( RuntimeException $e ) {
    assert_guide( false !== strpos( $e->getMessage(), 'permisos' ), 'Capability check is applied' );
}
echo 'Logistics User and Technical Manual smoke passed.' . PHP_EOL;
