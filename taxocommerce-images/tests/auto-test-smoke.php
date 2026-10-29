<?php
/** Offline smoke test for Images Auto Test, with no WordPress or database. */
define( 'ABSPATH', __DIR__ );
define( 'TAXOCOMMERCE_IMAGES_VERSION', '0.2.15' );
define( 'DAY_IN_SECONDS', 86400 );
class WP_Error {}
function current_time( $format ) { return '2026-10-29 12:00:00'; }
function apply_filters( $name, $value ) { return $value; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['allowed'] ); }
function esc_html__( $value, $domain ) { return $value; }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function check_admin_referer( $action, $field ) { throw new RuntimeException( 'Invalid nonce' ); }
function assert_auto( $ok, $message ) {
	if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}
require dirname( __DIR__ ) . '/includes/class-taxocommerce-images-auto-test.php';
$cases = TaxoCommerce_Images_Auto_Test::cases();
assert_auto( 17 === count( $cases ), 'All 17 checks registered' );
assert_auto( isset( $cases['product_policy'], $cases['background'], $cases['write_uat'] ), 'Images-specific cases available' );
$report = TaxoCommerce_Images_Auto_Test::run_suite( array(
	'write_uat' => $cases['write_uat'],
	'not_implemented' => array( 'Sample', 'Missing test' ),
	'runtime' => $cases['runtime'],
) );
assert_auto( 1 === $report['totals']['skip'], 'Write UAT must not claim success' );
assert_auto( 2 === $report['totals']['fail'], 'Missing method and missing runtime fail individually' );
assert_auto( count( $report['rows'] ) === 3, 'No failure hides subsequent rows' );
assert_auto( $report['version'] === '0.2.15', 'Report carries version' );
$GLOBALS['allowed'] = false;
try {
	TaxoCommerce_Images_Auto_Test::handle_run();
	assert_auto( false, 'Permission must be denied' );
} catch ( RuntimeException $e ) {
	assert_auto( false !== strpos( $e->getMessage(), 'permisos' ), 'Permissions checked before nonce' );
}
$GLOBALS['allowed'] = true;
try {
	TaxoCommerce_Images_Auto_Test::handle_run();
	assert_auto( false, 'Nonce must be checked' );
} catch ( RuntimeException $e ) {
	assert_auto( 'Invalid nonce' === $e->getMessage(), 'Nonce protected' );
}
echo "Images Auto Test: PASS 17 cases, traffic light, exception isolation, permissions, nonce" . PHP_EOL;
