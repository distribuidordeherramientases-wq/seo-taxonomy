<?php
/** Standalone regression: no live WordPress/database and no FTP calls. */
define( 'ABSPATH', __DIR__ );
$GLOBALS['tc_update_filters'] = array();
$GLOBALS['tc_updates'] = false;
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
    $GLOBALS['tc_update_filters'][ $hook ] = $callback;
}
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function wp_get_upload_dir() { return $GLOBALS['tc_uploads']; }
function esc_url_raw( $url ) { return $url; }
function home_url( $path = '/' ) { return 'https://staging.distribuidordeherramientas.es' . $path; }
function get_plugins() { return $GLOBALS['tc_installed']; }
function get_site_transient( $name ) { return $GLOBALS['tc_updates']; }
function tc_assert( $ok, $message ) {
    if ( ! $ok ) { fwrite( STDERR, "FAIL: " . $message . "\n" ); exit( 1 ); }
}
tc_assert( class_exists( 'ZipArchive' ), 'ZIP extension required' );
$root = sys_get_temp_dir() . '/tc-update-' . getmypid();
$dir = $root . '/taxocommerce-core/plugins';
mkdir( $dir, 0700, true );
$GLOBALS['tc_uploads'] = array(
    'basedir' => $root,
    'baseurl' => 'https://staging.distribuidordeherramientas.es/wp-content/uploads',
    'error' => false,
);
$slug = 'taxocommerce-database';
$file = $slug . '/' . $slug . '.php';
$GLOBALS['tc_installed'] = array(
    $file => array( 'Version' => '0.4.5', 'UpdateURI' => '' ),
);
$package = $dir . '/' . $slug . '.zip';
$zip = new ZipArchive();
tc_assert( true === $zip->open( $package, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'ZIP creation' );
$zip->addFromString( $file, "<?php\n/* Plugin Name: TaxoCommerce Database\nVersion: 0.4.6 */\n" );
$zip->close();
$manifest = array( 'schema' => 1, 'plugins' => array( $slug => array(
    'version' => '0.4.6',
    'sha256' => hash_file( 'sha256', $package ),
    'size' => filesize( $package ),
    'requires_php' => '7.4',
) ) );
file_put_contents( $dir . '/manifest.json', json_encode( $manifest ) );

require dirname( __DIR__ ) . '/updates/taxocommerce-upload-update-provider.php';
$hook = 'pre_set_site_transient_update_plugins';
tc_assert( isset( $GLOBALS['tc_update_filters'][ $hook ] ), 'Native update transient is registered' );
$provider = $GLOBALS['tc_update_filters'][ $hook ];
$mk_transient = static function () use ( $file ) {
    return (object) array(
        'checked' => array( $file => '0.4.5' ),
        'response' => array(),
        'no_update' => array(),
    );
};
$updated = $provider( $mk_transient() );
tc_assert( isset( $updated->response[ $file ] ), 'Private plugin update offered from verified local ZIP' );
$info = $updated->response[ $file ];
tc_assert( $info->new_version === '0.4.6' && strpos( $info->package, $slug . '.zip' ) !== false, 'Version and package correct' );
tc_assert( ! isset( $info->autoupdate ), 'Provider never enables automatic updates' );
tc_assert( ! isset( $updated->no_update[ $file ] ), 'Newer version belongs to response' );

// WordPress.org, another plugin provider, or an existing response always wins.
$original = (object) array( 'id' => 'https://wordpress.org/plugins/taxocommerce-database/' );
$transient = $mk_transient();
$transient->response[ $file ] = $original;
tc_assert( $provider( $transient )->response[ $file ] === $original, 'Existing WordPress.org update stays untouched' );
$transient = $mk_transient();
$transient->no_update[ $file ] = $original;
tc_assert( $provider( $transient )->no_update[ $file ] === $original, 'Existing WordPress.org no-update stays untouched' );

// Equal version still exposes native enable/disable auto-update controls.
$GLOBALS['tc_installed'][ $file ]['Version'] = '0.4.6';
$equal = $provider( $mk_transient() );
tc_assert( isset( $equal->no_update[ $file ] ), 'Current version publishes no_update metadata' );
tc_assert( ! isset( $equal->response[ $file ] ), 'Current version does not offer package update' );
tc_assert( ! isset( $equal->no_update[ $file ]->autoupdate ), 'No forced automatic updates when current' );

// Non-TaxoCommerce plugins and plugins with a different update provider are not hijacked.
$GLOBALS['tc_installed'][ $file ]['UpdateURI'] = 'https://another-provider.example/plugins/database/';
$other = $provider( $mk_transient() );
tc_assert( empty( $other->response ) && empty( $other->no_update ), 'Existing Update URI provider remains the owner' );
$GLOBALS['tc_installed'][ $file ]['UpdateURI'] = '';
$GLOBALS['tc_installed'][ $file ]['Version'] = '0.4.5';
$GLOBALS['tc_installed']['hello-dolly/hello.php'] = array( 'Version' => '1.0', 'UpdateURI' => '' );
$untouched = $provider( $mk_transient() );
tc_assert( ! isset( $untouched->response['hello-dolly/hello.php'] ), 'Other installed plugins left alone' );

// A modified or missing ZIP is never announced even if manifest still exists.
file_put_contents( $package, 'corrupt package' );
$invalid = $provider( $mk_transient() );
tc_assert( empty( $invalid->response ) && empty( $invalid->no_update ), 'Tampered ZIP is rejected' );

unlink( $package );
unlink( $dir . '/manifest.json' );
rmdir( $dir );
rmdir( $root . '/taxocommerce-core' );
rmdir( $root );
echo "TaxoCommerce temporary uploads update provider smoke OK\n";
