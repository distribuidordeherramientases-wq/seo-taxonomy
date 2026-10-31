<?php
/**
 * Offline proof that PRO deployment removes only the two obsolete Images tests.
 * No WordPress, hosting account or production filesystem is touched.
 */
define( 'ABSPATH', dirname( __DIR__, 2 ) . '/fake-wordpress/' );
$tmp = sys_get_temp_dir() . '/tc-images-cleanup-smoke-' . bin2hex( random_bytes( 5 ) );
define( 'WP_PLUGIN_DIR', $tmp . '/plugins' );
function add_action() {}
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function get_temp_dir() { return sys_get_temp_dir() . '/'; }
function wp_generate_uuid4() { return bin2hex( random_bytes( 12 ) ); }
function wp_delete_file( $path ) { unlink( $path ); }
class WP_Error {
	public $code;
	public function __construct( $code ) { $this->code = $code; }
}
function tc_assert( $value, $message ) {
	if ( ! $value ) { fwrite( STDERR, 'FAIL: ' . $message . "\n" ); exit( 1 ); }
	echo 'PASS: ' . $message . "\n";
}
require dirname( __DIR__, 2 ) . '/includes/seo-deploy-status.php';
tc_assert( class_exists( 'ZipArchive' ), 'zip support available' );
$images = WP_PLUGIN_DIR . '/taxocommerce-images';
mkdir( $images . '/tests', 0777, true );
$fixtures = array(
	'early-i18n-smoke.php' => '<?php // Early translation regression in Images registration',
	'functional-contract.php' => '<?php // IMAGES_CONTRACT_TEST: taxocommerce_images_test_assert',
);
foreach ( $fixtures as $name => $data ) {
	file_put_contents( $images . '/tests/' . $name, $data );
}
file_put_contents( $images . '/tests/keep.php', '<?php // unrelated custom file' );
file_put_contents( $images . '/taxocommerce-images.php', '<?php // plugin main file' );
$package = $tmp . '/release.zip';
$zip = new ZipArchive();
tc_assert( true === $zip->open( $package, ZipArchive::CREATE ), 'create release ZIP' );
$zip->addFromString( 'taxocommerce-images.php', '<?php // plugin main file' );
$zip->close();
$result = seo_taxocommerce_deploy_cleanup_images_tests( $images, $package );
tc_assert( is_array( $result ) && 2 === count( $result['removed'] ), 'removes exactly two known developer tests' );
tc_assert( ! file_exists( $images . '/tests/early-i18n-smoke.php' ) && ! file_exists( $images . '/tests/functional-contract.php' ), 'files absent in real Images target' );
tc_assert( file_exists( $images . '/tests/keep.php' ) && file_exists( $images . '/taxocommerce-images.php' ), 'other tests and runtime code untouched' );
$backup_path = get_temp_dir() . $result['backup_name'];
tc_assert( is_file( $backup_path ), 'private backup exists' );
$backup = new ZipArchive();
tc_assert( true === $backup->open( $backup_path ), 'backup ZIP readable' );
foreach ( $fixtures as $name => $original ) {
	tc_assert( $backup->getFromName( 'tests/' . $name ) === $original, 'backup verifies ' . $name );
}
$backup->close();
$repeat = seo_taxocommerce_deploy_cleanup_images_tests( $images, $package );
tc_assert( is_array( $repeat ) && array() === $repeat['removed'], 'idempotent repeat execution' );
file_put_contents( $images . '/tests/functional-contract.php', '<?php // custom file' );
$bad = seo_taxocommerce_deploy_cleanup_images_tests( $images, $package );
tc_assert( $bad instanceof WP_Error && is_file( $images . '/tests/functional-contract.php' ), 'unrecognized content is not deleted' );
unlink( $images . '/tests/functional-contract.php' );
file_put_contents( $images . '/tests/early-i18n-smoke.php', $fixtures['early-i18n-smoke.php'] );
$with_tests = $tmp . '/bad-release.zip';
$zip = new ZipArchive();
$zip->open( $with_tests, ZipArchive::CREATE );
$zip->addFromString( 'taxocommerce-images.php', '<?php // plugin main file' );
$zip->addFromString( 'tests/early-i18n-smoke.php', $fixtures['early-i18n-smoke.php'] );
$zip->close();
$bad_package = seo_taxocommerce_deploy_cleanup_images_tests( $images, $with_tests );
tc_assert( $bad_package instanceof WP_Error && is_file( $images . '/tests/early-i18n-smoke.php' ), 'ZIP containing tests cannot trigger cleanup' );
unlink( $images . '/tests/early-i18n-smoke.php' );
symlink( $images . '/tests/keep.php', $images . '/tests/early-i18n-smoke.php' );
$symlink = seo_taxocommerce_deploy_cleanup_images_tests( $images, $package );
tc_assert( $symlink instanceof WP_Error && is_link( $images . '/tests/early-i18n-smoke.php' ), 'symlink cannot be removed' );
unlink( $images . '/tests/early-i18n-smoke.php' );
unlink( $images . '/tests/keep.php' );
rmdir( $images . '/tests' );
unlink( $images . '/taxocommerce-images.php' );
rmdir( $images );
rmdir( WP_PLUGIN_DIR );
unlink( $package );
unlink( $with_tests );
rmdir( $tmp );
unlink( $backup_path );
echo "Images PRO deploy cleanup smoke passed.\n";
