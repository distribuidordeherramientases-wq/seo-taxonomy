<?php
/**
 * Isolated regression: WordPress sidebar shows plugins, while all Core routes
 * remain registered and reachable from the Core dashboard.
 *
 * @package TaxoCommerceCore
 */
define( 'ABSPATH', __DIR__ . '/' );

class TaxoCommerce_Core_Content_Manager {
    const PAGE_SLUG = 'taxocommerce-core-content';
}
class TaxoCommerce_Core_User_Guide {
    const PAGE_SLUG = 'taxocommerce-core-user-guide';
}
function __( $message, $domain = '' ) { return $message; }
function apply_filters( $hook, $default ) { return $default; }
$GLOBALS['tc_inline_css'] = array();
function wp_style_is( $handle, $status ) { return 'taxocommerce-core-admin' === $handle && 'enqueued' === $status; }
function wp_add_inline_style( $handle, $css ) { $GLOBALS['tc_inline_css'][] = array( 'handle' => $handle, 'css' => $css ); return true; }

$GLOBALS['menu'] = array();
$GLOBALS['submenu'] = array();
$GLOBALS['tc_registered_pages'] = array();
function add_menu_page( $title, $menu_title, $capability, $slug, $callback, $icon, $position ) {
    $GLOBALS['menu'][] = array( $menu_title, $capability, $slug, $title, $icon );
    $GLOBALS['submenu'][ $slug ] = array();
    $GLOBALS['tc_registered_pages'][ $slug ] = $callback;
}
function add_submenu_page( $parent, $title, $menu_title, $capability, $slug, $callback ) {
    $GLOBALS['submenu'][ $parent ][] = array( $menu_title, $capability, $slug );
    $GLOBALS['tc_registered_pages'][ $slug ] = $callback;
}

require_once dirname( __DIR__, 3 ) . '/taxocommerce-core/includes/class-taxocommerce-core.php';

function check( $ok, $reason ) {
    if ( ! $ok ) {
        throw new RuntimeException( $reason );
    }
}
function menu_slugs() {
    return array_map(
        static function ( $item ) { return $item[2]; },
        $GLOBALS['submenu']['taxocommerce-core']
    );
}

$core = TaxoCommerce_Core::instance();
$core->register_menu();
// Simulate a plugin or WordPress repaint incorrectly renaming the parent.
$GLOBALS['menu'][0][0] = 'Marketing campaigns';
$core->normalize_family_submenu_labels();
check( 'TaxoCommerce Core' === $GLOBALS['menu'][0][0], 'Core parent title must not become Marketing campaigns' );
check( 'taxocommerce-core' === $GLOBALS['menu'][0][2], 'Core parent slug must remain unchanged' );
$initial = menu_slugs();
$internal = array(
    TaxoCommerce_Core_Content_Manager::PAGE_SLUG,
    'taxocommerce-core-hierarchy',
    'taxocommerce-core-menu',
    TaxoCommerce_Core_User_Guide::PAGE_SLUG,
);
foreach ( $internal as $slug ) {
    check( in_array( $slug, $initial, true ), 'Core route not registered: ' . $slug );
    check( isset( $GLOBALS['tc_registered_pages'][ $slug ] ), 'WordPress callback missing: ' . $slug );
}

// Preserve all registered submenu entries: WordPress needs them to route
// admin.php?page=... links and validate user capabilities.
$core->hide_core_internal_submenus();
check( $initial === menu_slugs(), 'Core submenu registry must remain intact' );
$GLOBALS['tc_inline_css'] = array();
$core->hide_sidebar_submenu();
check( 1 === count( $GLOBALS['tc_inline_css'] ), 'Core must use WordPress enqueued styles' );
$style = $GLOBALS['tc_inline_css'][0]['css'];
check( false !== strpos( $style, 'display:none' ), 'Core-only sidebar must stay compact' );

// With other plugins active, their menu items stay visible.
$core->register_menu();
foreach ( array( 'taxocommerce-images', 'taxocommerce-vocabulary', 'seo-facturas' ) as $slug ) {
    $GLOBALS['submenu']['taxocommerce-core'][] = array( $slug, 'manage_options', $slug );
}
$core->normalize_family_submenu_labels();
$core->hide_core_internal_submenus();
$core->hide_core_internal_submenus(); // Idempotent on repeated hook runs.
$expected = array_merge( $initial, array( 'taxocommerce-images', 'taxocommerce-vocabulary', 'seo-facturas' ) );
check( $expected === array_values( menu_slugs() ), 'WordPress route registry retains plugins and internal Core pages' );
foreach ( $internal as $slug ) {
    check( isset( $GLOBALS['tc_registered_pages'][ $slug ] ), 'Hiding sidebar must not unregister Core routes: ' . $slug );
}
$GLOBALS['tc_inline_css'] = array();
$core->hide_sidebar_submenu();
check( 1 === count( $GLOBALS['tc_inline_css'] ), 'Core must use WordPress enqueued styles' );
$style = $GLOBALS['tc_inline_css'][0]['css'];
check( false !== strpos( $style, 'page=taxocommerce-core-content' ), 'Core internal pages are hidden visually, not unregistered' );
check( false === strpos( $style, '> ul.wp-submenu{display:none' ), 'Other plugin entries must remain visible' );

$dashboard = file_get_contents( dirname( __DIR__, 3 ) . '/taxocommerce-core/includes/class-taxocommerce-core-dashboard.php' );
foreach ( array(
    'TaxoCommerce_Core_Content_Manager::PAGE_SLUG',
    "'taxocommerce-core-hierarchy'",
    "'taxocommerce-core-menu'",
    'TaxoCommerce_Core_User_Guide::PAGE_SLUG',
) as $link ) {
    check( false !== strpos( $dashboard, $link ), 'Internal Core navigation missing: ' . $link );
}
echo "Core sidebar plugin-only smoke: OK\n";
