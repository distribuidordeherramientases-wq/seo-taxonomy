<?php
/**
 * CLI contract: FAQs manuals preserve the admin navigation and are read-only.
 * This test is kept in GitHub and must not ship with the production ZIP.
 */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ );
$GLOBALS['tc_faq_manual_allowed'] = true;

function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr__( $text, $domain = '' ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function current_user_can( $capability ) { return 'manage_options' === $capability && $GLOBALS['tc_faq_manual_allowed']; }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function tc_faq_manual_assert( $condition, $label ) {
	if ( ! $condition ) { fwrite( STDERR, 'FAIL FAQ manual: ' . $label . PHP_EOL ); exit( 1 ); }
}
require dirname( __DIR__ ) . '/includes/class-taxocommerce-faqs-manual.php';

$sections = TaxoCommerce_FAQs_Manual::sections();
$groups = array( 'user' => 0, 'technical' => 0 );
$ids = array();
foreach ( $sections as $section ) {
	tc_faq_manual_assert( isset( $section['id'], $section['group'], $section['title'], $section['body'] ), 'section shape' );
	tc_faq_manual_assert( isset( $groups[ $section['group'] ] ), 'known group' );
	tc_faq_manual_assert( ! in_array( $section['id'], $ids, true ), 'unique section ID' );
	tc_faq_manual_assert( count( $section['body'] ) >= 2, 'substantive manual section' );
	$ids[] = $section['id'];
	++$groups[ $section['group'] ];
}
tc_faq_manual_assert( count( $sections ) >= 17, 'manual coverage' );
tc_faq_manual_assert( $groups['user'] >= 10 && $groups['technical'] >= 6, 'both manuals' );

$entry = file_get_contents( dirname( __DIR__ ) . '/taxocommerce-faqs.php' );
$admin = file_get_contents( dirname( __DIR__ ) . '/includes/class-taxocommerce-faqs.php' );
tc_faq_manual_assert( false !== strpos( $entry, 'class-taxocommerce-faqs-manual.php' ), 'manual loaded' );
tc_faq_manual_assert( false !== strpos( $admin, 'TaxoCommerce_FAQs_Manual::render()' ), 'manual route' );
tc_faq_manual_assert( false !== strpos( $admin, "'manual' => __( 'Manual', 'taxocommerce-faqs' )" ), 'manual tab name' );
tc_faq_manual_assert( false !== strpos( $admin, "'auto-test' => __( 'Auto Test', 'taxocommerce-faqs' )" ), 'Auto Test preserved' );
tc_faq_manual_assert( false !== strpos( $admin, 'TaxoCommerce_FAQs_Auto_Test::render()' ), 'Auto Test route preserved' );
tc_faq_manual_assert( false !== strpos( $admin, "self::ensure_category()" ), 'FAQ schema retained' );

ob_start();
TaxoCommerce_FAQs_Manual::render();
$html = ob_get_clean();
tc_faq_manual_assert( false !== strpos( $html, 'Manual de usuario' ), 'user heading' );
tc_faq_manual_assert( false !== strpos( $html, 'Manual técnico' ), 'technical heading' );
tc_faq_manual_assert( false !== strpos( $html, 'Auto Test' ), 'consistent Auto Test name' );
tc_faq_manual_assert( substr_count( $html, 'class="tc-faq-manual__index"' ) === 2, 'two indexes' );
tc_faq_manual_assert( substr_count( $html, 'class="tc-faq-manual__card"' ) === count( $sections ), 'all sections' );
foreach ( $ids as $id ) {
	tc_faq_manual_assert( false !== strpos( $html, 'id="' . $id . '"' ), 'missing anchor ' . $id );
}
$GLOBALS['tc_faq_manual_allowed'] = false;
try {
	ob_start();
	TaxoCommerce_FAQs_Manual::render();
	ob_end_clean();
	tc_faq_manual_assert( false, 'unauthorized manual access' );
} catch ( RuntimeException $e ) {
	if ( ob_get_level() ) { ob_end_clean(); }
	tc_faq_manual_assert( false !== strpos( $e->getMessage(), 'permisos' ), 'permission guard' );
}

echo 'FAQ Manual smoke passed: user and technical guides, tabs, permission guard and read-only render.' . PHP_EOL;
