<?php
/**
 * CLI contract test: both in-plugin manuals are read-only, authorized and routed.
 *
 * This test belongs to GitHub CI; it must not ship in WordPress ZIP packages.
 */
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', __DIR__ );
$GLOBALS['tc_ie_manual_allowed'] = true;

function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr__( $text, $domain = '' ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function apply_filters( $name, $value ) { return $value; }
function current_user_can( $capability ) { return $GLOBALS['tc_ie_manual_allowed']; }
function wp_die( $message ) { throw new RuntimeException( $message ); }

function tc_ie_manual_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL manual: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

require dirname( __DIR__ ) . '/includes/class-taxocommerce-import-export-manual.php';
$sections = TaxoCommerce_Import_Export_Manual::sections();
tc_ie_manual_assert( count( $sections ) >= 16, 'user and technical coverage' );

$ids = array();
$groups = array( 'user' => 0, 'technical' => 0 );
foreach ( $sections as $section ) {
	tc_ie_manual_assert( isset( $section['id'], $section['group'], $section['title'], $section['body'] ), 'section shape' );
	tc_ie_manual_assert( ! in_array( $section['id'], $ids, true ), 'unique anchor' );
	tc_ie_manual_assert( isset( $groups[ $section['group'] ] ), 'known manual group' );
	tc_ie_manual_assert( count( $section['body'] ) >= 2, 'substantive instructions' );
	++$groups[ $section['group'] ];
	$ids[] = $section['id'];
}
tc_ie_manual_assert( $groups['user'] >= 10 && $groups['technical'] >= 5, 'both manuals documented' );

$admin = file_get_contents( dirname( __DIR__ ) . '/includes/class-taxocommerce-import-export.php' );
$entry = file_get_contents( dirname( __DIR__ ) . '/taxocommerce-import-export.php' );
tc_ie_manual_assert( false !== strpos( $entry, 'class-taxocommerce-import-export-manual.php' ), 'class included' );
tc_ie_manual_assert( false !== strpos( $admin, "TaxoCommerce_Import_Export_Manual::render()" ), 'manual routed' );
tc_ie_manual_assert( false !== strpos( $admin, "'tc_ie_tab' => 'manual'" ), 'manual link' );
tc_ie_manual_assert( false !== strpos( $admin, "TaxoCommerce_Import_Export_Auto_Test::render()" ), 'Auto Test not replaced' );
tc_ie_manual_assert( false !== strpos( $admin, "'tc_ie_tab' => 'auto-test'" ), 'Auto Test naming preserved' );

ob_start();
TaxoCommerce_Import_Export_Manual::render();
$html = ob_get_clean();
tc_ie_manual_assert( false !== strpos( $html, 'Manual de usuario' ), 'user title' );
tc_ie_manual_assert( false !== strpos( $html, 'Manual técnico' ), 'technical title' );
tc_ie_manual_assert( false !== strpos( $html, 'Auto Test' ), 'consistent Auto Test naming' );
tc_ie_manual_assert( substr_count( $html, 'class="tc-ie-manual__card"' ) === count( $sections ), 'all cards rendered' );
tc_ie_manual_assert( substr_count( $html, 'class="tc-ie-manual__index"' ) === 2, 'two navigation indexes' );
foreach ( $ids as $id ) {
	tc_ie_manual_assert( false !== strpos( $html, 'id="' . $id . '"' ), 'anchor ' . $id );
}

$GLOBALS['tc_ie_manual_allowed'] = false;
try {
	ob_start();
	TaxoCommerce_Import_Export_Manual::render();
	ob_end_clean();
	tc_ie_manual_assert( false, 'unauthorized user could view manual' );
} catch ( RuntimeException $e ) {
	if ( ob_get_level() ) {
		ob_end_clean();
	}
	tc_ie_manual_assert( false !== strpos( $e->getMessage(), 'permisos' ), 'permission guard' );
}

echo 'Import-Export Manual smoke passed: both manuals, tabs, access and read-only content.' . PHP_EOL;
