<?php
/** Offline regression: sitemap publication dates, job scheduling and safe rollover. */
define( 'ABSPATH', __DIR__ );
$GLOBALS['options'] = array();
$GLOBALS['scheduled'] = false;
$GLOBALS['schedule_ok'] = true;
$GLOBALS['uuid'] = '01234567-89ab-cdef-0123-456789abcdef';
$GLOBALS['root'] = sys_get_temp_dir() . '/tcdist-sitemap-regression-' . getmypid() . '-' . uniqid();
mkdir( $GLOBALS['root'], 0700, true );
function absint( $x ) { return abs( (int) $x ); }
function __( $text, $domain = '' ) { return $text; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $s ) ); }
function sanitize_text_field( $s ) { return (string) $s; }
function get_option( $name, $default = false ) { return $GLOBALS['options'][$name] ?? $default; }
function update_option( $name, $val, $autoload = false ) { $GLOBALS['options'][$name] = $val; return true; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error { private $message; public function __construct( $code, $message ) { $this->message = $message; } public function get_error_message() { return $this->message; } }
function wp_next_scheduled( $hook ) { return $GLOBALS['scheduled'] ? time() + 5 : false; }
function wp_schedule_single_event( $time, $hook ) { if ( !$GLOBALS['schedule_ok'] ) { return false; } $GLOBALS['scheduled'] = true; return true; }
function wp_generate_uuid4() { return $GLOBALS['uuid']; }
function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0700, true ); }
function add_action( $hook, $function, $priority = 10 ) {}
function tcdist_sitemap_sitemap_storage() { return array( 'dir' => $GLOBALS['root'] . '/' ); }
function tcdist_sitemap_get_sitemap_manifest() { return $GLOBALS['manifest'] ?? array(); }
function tcdist_sitemap_remove_directory( $dir ) {
    if ( ! is_dir( $dir ) ) { return; }
    foreach ( glob( $dir . '*' ) as $path ) { unlink( $path ); }
    rmdir( $dir );
}
function tcdist_sitemap_atomic_rename( $source, $target ) { return rename( $source, $target ); }
function tcdist_sitemap_publish_sitemap_file( $source, $target ) { return copy( $source, $target ); }
function tcdist_sitemap_validate_xml_file( $path, $root ) { return array( 'valid' => is_file( $path ) ); }
function tcdist_sitemap_build_sitemap_index_xml( $files ) { return implode( ',', array_column( $files, 'filename' ) ); }
function tcdist_sitemap_write_validated_xml( $path, $xml, $root ) { file_put_contents( $path, $xml ); return array( 'count' => 1, 'bytes' => strlen( $xml ) ); }
function tcdist_sitemap_sitemap_public_url( $filename ) { return 'https://example.test/' . $filename; }
function tcdist_sitemap_write_sitemap_manifest( $dir, $manifest ) { $GLOBALS['manifest'] = $manifest; return true; }
function wp_delete_file( $file ) { return unlink( $file ); }
require dirname( __DIR__ ) . '/includes/sitemaps/batch-worker.php';
function assert_check( $ok, $what ) { if ( !$ok ) { throw new RuntimeException( $what ); } }
$public = $GLOBALS['root'] . '/sitemap.xml';
$old = $GLOBALS['root'] . '/sitemap-products-1.xml';
file_put_contents( $public, 'Old public sitemap index' );
file_put_contents( $old, 'Old public child' );
$GLOBALS['manifest'] = array( 'generated_at_utc' => '2026-10-25T17:06:00+00:00' );
assert_check( tcdist_sitemap_publication_time() === strtotime( '2026-10-25T17:06:00+00:00' ), 'Last publication is old manifest, not job start.' );
$GLOBALS['scheduled'] = false;
$GLOBALS['schedule_ok'] = false;
$failed = tcdist_sitemap_start_background_build();
assert_check( $failed['type'] === 'error' && tcdist_sitemap_job_state()['status'] === 'error', 'Scheduling failure cannot look like running.' );
$GLOBALS['schedule_ok'] = true;
$GLOBALS['scheduled'] = false;
$started = tcdist_sitemap_start_background_build();
assert_check( $started['type'] === 'success', 'Job can be started after failed schedule.' );
$state = tcdist_sitemap_job_state();
assert_check( $state['status'] === 'running' && $state['started_at'] > 0, 'Job has timestamp and active status.' );
assert_check( tcdist_sitemap_publication_time() === strtotime( '2026-10-25T17:06:00+00:00' ), 'Starting job must not change published timestamp.' );
$build = tcdist_sitemap_job_build_directory( $state );
$scoped = tcdist_sitemap_scoped_filename( $state, 'products', 1 );
assert_check( $scoped === 'sitemap-products-0123456789ab-1.xml', 'Fresh children are uniquely named.' );
file_put_contents( $build . 'sitemap-products-1.xml', 'New valid child' );
$state['files'] = array( array( 'filename' => 'sitemap-products-1.xml', 'group' => 'products', 'url_count' => 1 ) );
$state['publish_cursor'] = 0;
tcdist_sitemap_publish_window( $state, $build, $GLOBALS['root'] . '/' );
assert_check( $state['phase'] === 'index', 'Private child successfully published.' );
assert_check( $state['files'][0]['filename'] === $scoped, 'Legacy in-flight files moved to scoped names.' );
assert_check( file_get_contents( $old ) === 'Old public child', 'Existing public child stays unchanged before index switch.' );
assert_check( file_get_contents( $public ) === 'Old public sitemap index', 'Existing index remains unchanged until new build finishes.' );
assert_check( file_get_contents( $GLOBALS['root'] . '/' . $scoped ) === 'New valid child', 'New child published safely at unique path.' );
unlink( $old );
unlink( $public );
unlink( $GLOBALS['root'] . '/' . $scoped );
tcdist_sitemap_remove_directory( $build );
rmdir( $GLOBALS['root'] );
echo "Distribution sitemap publication/scheduling smoke: OK\n";
