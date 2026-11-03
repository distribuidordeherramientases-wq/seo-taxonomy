<?php
/**
 * WordPress-free smoke tests for the installed Core Autocheck's native-menu logic.
 * Deliberately excluded from the distributable plugin ZIP.
 */
define( 'ABSPATH', __DIR__ );
define( 'TAXOCOMMERCE_CORE_VERSION', '0.4.52' );

$GLOBALS['block_theme'] = false;
$GLOBALS['locations'] = array();
$GLOBALS['assigned'] = array();
$GLOBALS['menus'] = array();
$GLOBALS['items'] = array();
$GLOBALS['posts'] = array();
$GLOBALS['templates'] = array();
$GLOBALS['countries'] = array( '' => 'Global' );
$GLOBALS['options'] = array();
$GLOBALS['missing_action'] = '';
$GLOBALS['writes'] = 0;
$_GET = array();

function absint( $value ) { return abs( (int) $value ); }
function current_time( $type ) { return '2026-11-03 10:49:00'; }
function is_wp_error( $value ) { return false; }
function has_action( $hook ) { return $GLOBALS['missing_action'] !== $hook; }
function get_registered_nav_menus() { return $GLOBALS['locations']; }
function get_theme_mod( $name, $default = false ) { return 'nav_menu_locations' === $name ? $GLOBALS['assigned'] : $default; }
function wp_is_block_theme() { return $GLOBALS['block_theme']; }
function wp_get_nav_menu_object( $name ) {
    if ( is_numeric( $name ) ) {
        return $GLOBALS['menus'][ (int) $name ] ?? false;
    }
    foreach ( $GLOBALS['menus'] as $menu ) {
        if ( $name === $menu->name ) {
            return $menu;
        }
    }
    return false;
}
function wp_get_nav_menu_items( $id, $args = array() ) {
    return $GLOBALS['items'][ (int) $id ] ?? false;
}
function get_option( $key, $fallback = false ) { return $GLOBALS['options'][ $key ] ?? $fallback; }
function post_type_exists( $type ) { return 'wp_navigation' === $type; }
function get_post( $id ) { return $GLOBALS['posts'][ (int) $id ] ?? null; }
function get_block_templates( $query, $type ) { return $GLOBALS['templates'][ $type ] ?? array(); }
function parse_blocks( $content ) {
    if ( 0 === strpos( $content, 'ref:' ) ) {
        return array( array( 'blockName' => 'core/navigation', 'attrs' => array( 'ref' => (int) substr( $content, 4 ) ), 'innerBlocks' => array() ) );
    }
    if ( 'inline' === $content ) {
        return array( array( 'blockName' => 'core/navigation', 'attrs' => array(), 'innerBlocks' => array() ) );
    }
    return array( array( 'blockName' => 'core/navigation-link', 'attrs' => array( 'label' => 'About' ), 'innerBlocks' => array() ) );
}
function wp_create_nav_menu() { ++$GLOBALS['writes']; throw new RuntimeException( 'Autocheck must not create menus' ); }
function update_option() { ++$GLOBALS['writes']; throw new RuntimeException( 'Autocheck must not write options' ); }
function wp_update_post() { ++$GLOBALS['writes']; throw new RuntimeException( 'Autocheck must not update posts' ); }

class TaxoCommerce_Core_Country {
    public static function page_countries() { return $GLOBALS['countries']; }
}
require dirname( __DIR__, 3 ) . '/taxocommerce-core/includes/class-taxocommerce-core-menu.php';
require dirname( __DIR__, 3 ) . '/taxocommerce-core/includes/class-taxocommerce-core-auto-test.php';

function check_status( $case, $expected, $detail ) {
    $results = TaxoCommerce_Core_Auto_Test::run_suite( array( $case => array( 'Menús', $case ) ) );
    $actual = $results['rows'][0]['status'];
    if ( $expected !== $actual ) {
        throw new RuntimeException( $detail . ': expected ' . $expected . ', got ' . $actual . ' / ' . $results['rows'][0]['message'] );
    }
}

$GLOBALS['locations'] = array( 'header-custom' => 'Cabecera' );
$GLOBALS['assigned'] = array( 'header-custom' => 17 );
$GLOBALS['menus'] = array( 17 => (object) array( 'term_id' => 17, 'name' => 'Navegación activa' ) );
$GLOBALS['items'] = array( 17 => array( (object) array( 'ID' => 1, 'title' => 'Inicio' ) ) );
check_status( 'native_classic', 'pass', 'Classic active menu loads' );
check_status( 'native_blocks', 'skip', 'Block navigation ignored under classic theme' );
check_status( 'menu_actions', 'pass', 'Native save/load/delete handlers are registered' );

$GLOBALS['missing_action'] = 'admin_post_' . TaxoCommerce_Core_Block_Navigation::SAVE;
$GLOBALS['missing_action'] = 'admin_post_' . TaxoCommerce_Core_Block_Navigation::ACTION;
check_status( 'menu_actions', 'fail', 'Missing block save handler is detected' );
$GLOBALS['missing_action'] = '';

$GLOBALS['assigned'] = array();
check_status( 'native_classic', 'skip', 'Unassigned theme menu is not a failure' );
$GLOBALS['assigned'] = array( 'header-custom' => 999 );
check_status( 'native_classic', 'fail', 'Missing assigned menu is detected' );
$GLOBALS['assigned'] = array( 'header-custom' => 17, 'footer-custom' => 18 );
$GLOBALS['locations']['footer-custom'] = 'Pie';
$GLOBALS['menus'][18] = (object) array( 'term_id' => 18, 'name' => 'Footer' );
$GLOBALS['items'][18] = array();
check_status( 'native_classic', 'pass', 'Multiple active menus require explicit selection' );
$GLOBALS['assigned'] = array( 'header-custom' => 17 );
$GLOBALS['items'][17] = false; // WordPress returns false for an empty native menu.
check_status( 'native_classic', 'pass', 'An empty native menu remains loadable' );

$GLOBALS['block_theme'] = true;
$GLOBALS['posts'][77] = (object) array( 'ID' => 77, 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_content' => 'block-links' );
$GLOBALS['templates'] = array( 'wp_template_part' => array( (object) array( 'slug' => 'header', 'title' => 'Cabecera', 'content' => 'ref:77' ) ) );
check_status( 'native_classic', 'skip', 'Classic check ignored under block theme' );
check_status( 'native_blocks', 'pass', 'Referenced wp_navigation loads' );
$GLOBALS['templates'] = array( 'wp_template_part' => array( (object) array( 'slug' => 'header', 'title' => 'Cabecera', 'content' => 'inline' ) ) );
check_status( 'native_blocks', 'pass', 'Inline navigation is valid' );
$GLOBALS['templates'] = array();
check_status( 'native_blocks', 'skip', 'Absent header reference is inconclusive' );

$GLOBALS['block_theme'] = false;
$GLOBALS['countries'] = array( '' => 'Global', 'PT' => 'Portugal' );
$GLOBALS['options'] = array( 'taxocommerce_core_menu_id_pt' => 31 );
$GLOBALS['menus'][31] = (object) array( 'term_id' => 31, 'name' => 'TaxoCommerce - PT' );
$GLOBALS['items'][31] = false;
check_status( 'menus', 'pass', 'Only configured markets are checked, empty menus accepted' );
$GLOBALS['options']['taxocommerce_core_menu_id_pt'] = 999;
check_status( 'menus', 'fail', 'A stored but missing country menu is an error' );
$GLOBALS['options'] = array();
check_status( 'menus', 'skip', 'No SEO menus on a fresh site is expected' );

if ( 0 !== $GLOBALS['writes'] ) {
    throw new RuntimeException( 'Autocheck mutated WordPress data' );
}
echo "Core native menu Autocheck smoke: OK\n";
