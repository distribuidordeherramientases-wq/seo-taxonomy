<?php
/**
 * CLI regression contract for FAQs Auto Test.
 * No WordPress bootstrap, database changes, FAQ writes or filesystem writes.
 */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ );
define( 'TAXOCOMMERCE_FAQS_VERSION', '0.1.6' );
function tc_faq_at_check( $ok, $name ) {
	if ( ! $ok ) { fwrite( STDERR, 'FAIL FAQ Auto Test: ' . $name . PHP_EOL ); exit( 1 ); }
}
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function get_current_blog_id() { return 2; }
function get_current_user_id() { return 37; }
function current_time( $format ) { return '2026-10-29 18:00:00'; }
function absint( $value ) { return abs( (int) $value ); }
function __( $s, $domain = '' ) { return $s; }
function esc_attr__( $s, $domain = '' ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $s, $domain = '' ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr_e( $s, $domain = '' ) { echo esc_attr__( $s, $domain ); }
function esc_html_e( $s, $domain = '' ) { echo esc_html__( $s, $domain ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return esc_attr( $s ); }
function current_user_can( $s ) { return 'manage_options' === $s; }
function wp_die( $s ) { throw new RuntimeException( $s ); }
function get_user_meta( $id, $key, $single ) { return array(); }
function update_user_meta( $id, $key, $value ) { throw new RuntimeException( 'Auto Test attempted data write on read-only test' ); }
function admin_url( $s ) { return 'https://example.test/wp-admin/' . $s; }
function wp_nonce_field( $action, $name ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="test">'; }
require dirname( __DIR__ ) . '/includes/class-taxocommerce-faqs-auto-test.php';

$groups = TaxoCommerce_FAQs_Auto_Test::groups();
$cases = TaxoCommerce_FAQs_Auto_Test::cases();
tc_faq_at_check( count( $groups ) === 6, 'groups not equivalent' );
tc_faq_at_check( count( $cases ) >= 25, 'insufficient coverage' );
foreach ( $cases as $id => $labels ) {
	tc_faq_at_check( isset( $groups[ $labels[0] ] ), 'unknown group for ' . $id );
}

$report = TaxoCommerce_FAQs_Auto_Test::run_suite( 'uat' );
tc_faq_at_check( $report['totals']['skip'] === 5, 'real operations must be marked manual' );
tc_faq_at_check( $report['totals']['not_run'] === count( $cases ) - 5, 'group scope' );
tc_faq_at_check( $report['totals']['pass'] === 0 && $report['totals']['fail'] === 0, 'unexpected work in UAT test' );
tc_faq_at_check( ! isset( $report['faq_id'] ) && ! isset( $report['question'] ) && ! isset( $report['answer'] ), 'private data in report' );

$before = array( 'site_hash' => 'site-A', 'phase' => 'before', 'group' => 'all', 'timestamp' => 100, 'rows' =>
	array( array( 'id' => 'a', 'status' => 'pass' ), array( 'id' => 'b', 'status' => 'fail' ), array( 'id' => 'c', 'status' => 'pass' ) ) );
$after = array( 'site_hash' => 'site-A', 'phase' => 'after', 'group' => 'all', 'timestamp' => 200, 'rows' =>
	array( array( 'id' => 'a', 'status' => 'fail' ), array( 'id' => 'b', 'status' => 'pass' ), array( 'id' => 'c', 'status' => 'skip' ), array( 'id' => 'd', 'status' => 'fail' ) ) );
$diff = TaxoCommerce_FAQs_Auto_Test::compare( $before, $after );
tc_faq_at_check( count( $diff['new_failures'] ) === 2, 'new failures' );
tc_faq_at_check( count( $diff['resolved'] ) === 1, 'resolved regressions' );
tc_faq_at_check( count( $diff['coverage_lost'] ) === 1, 'lost coverage' );
tc_faq_at_check( count( $diff['new_checks'] ) === 1, 'new cases' );
$after['site_hash'] = 'another-site';
tc_faq_at_check( null === TaxoCommerce_FAQs_Auto_Test::compare( $before, $after ), 'cross-site comparison must be rejected' );
$after['site_hash'] = 'site-A';
$after['timestamp'] = 10;
tc_faq_at_check( null === TaxoCommerce_FAQs_Auto_Test::compare( $before, $after ), 'reverse chronology rejected' );
$after['timestamp'] = 200;
$after['group'] = 'data';
tc_faq_at_check( null === TaxoCommerce_FAQs_Auto_Test::compare( $before, $after ), 'partial comparison rejected' );

$entry = file_get_contents( dirname( __DIR__ ) . '/taxocommerce-faqs.php' );
$admin = file_get_contents( dirname( __DIR__ ) . '/includes/class-taxocommerce-faqs.php' );
tc_faq_at_check( false !== strpos( $entry, 'class-taxocommerce-faqs-auto-test.php' ), 'entrypoint include' );
tc_faq_at_check( false !== strpos( $entry, 'TaxoCommerce_FAQs_Auto_Test::register()' ), 'actions registered' );
tc_faq_at_check( false !== strpos( $admin, 'TaxoCommerce_FAQs_Auto_Test::render()' ), 'UI routing' );
tc_faq_at_check( false !== strpos( $admin, "'auto-test' => __( 'Auto Test', 'taxocommerce-faqs' )" ), 'consistent Auto Test tab' );
tc_faq_at_check( false !== strpos( $admin, 'self::ensure_category()' ), 'legacy schema method preserved' );
tc_faq_at_check( false !== strpos( $admin, 'taxocommerce_faqs_save' ), 'FAQ CRUD preserved' );

ob_start();
TaxoCommerce_FAQs_Auto_Test::render();
$html = ob_get_clean();
tc_faq_at_check( false !== strpos( $html, 'Guardar estado ANTES' ), 'baseline button' );
tc_faq_at_check( false !== strpos( $html, 'Comprobar DESPUÉS' ), 'after button' );
tc_faq_at_check( false !== strpos( $html, 'Auto Test' ), 'consistent test name' );
tc_faq_at_check( substr_count( $html, '<details' ) === count( $groups ), 'groups rendered' );

echo 'FAQ Auto Test smoke passed: 25 checks, comparison, UI, permissions and no FAQ writes.' . PHP_EOL;
