<?php
/** Exercise actual Core catalogue discovery with an independently built Pro ZIP. */
define( 'ABSPATH', __DIR__ . '/' );
function __( $text, $domain = '' ) { return $text; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $key ) ); }
function sanitize_file_name( $name ) { return basename( $name ); }
function trailingslashit( $path ) { return rtrim( $path, '/' ) . '/'; }
function apply_filters( $hook, $value ) { return $value; }
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['uploads'], 'error' => false ); }
function get_plugins() { return $GLOBALS['plugins']; }
function check_pro( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); }
}
$GLOBALS['uploads'] = $argv[1];
$GLOBALS['plugins'] = array();
require dirname( __DIR__, 2 ) . '/taxocommerce-core/includes/class-taxocommerce-core.php';
$reflection = new ReflectionClass( 'TaxoCommerce_Core' );
$core = $reflection->newInstanceWithoutConstructor();
$catalog = $reflection->getMethod( 'family_extensions' );
$local = $reflection->getMethod( 'local_package_path' );
$slug = 'taxocommerce-import-export-pro';
$basename = $slug . '/' . $slug . '.php';
$result = $catalog->invoke( $core );
check_pro( isset( $result['import_export_pro'] ), 'Pro ZIP is discoverable without installation' );
check_pro( $basename === $result['import_export_pro']['plugin_file'], 'Native activation basename' );
check_pro( $GLOBALS['uploads'] . '/taxocommerce-core/plugins/' . $slug . '.zip' === $local->invoke( $core, $result['import_export_pro'] ), 'Core resolves the canonical Pro ZIP' );
check_pro( ! isset( $result['import_export_lite'] ) && isset( $result['import_export'] ), 'Lite remains independent; original retained' );
$GLOBALS['plugins'][ $basename ] = array( 'Name' => 'TaxoCommerce Import-Export Pro', 'Description' => 'CSV batches', 'Version' => '0.1.2' );
$result = $catalog->invoke( $core );
check_pro( 'TaxoCommerce Import-Export Pro' === $result['import_export_pro']['name'], 'Installed Pro has its own correct name' );
check_pro( 1 === count( array_filter( $result, static function ( $entry ) use ( $slug ) { return $slug === ( $entry['org_slug'] ?? '' ); } ) ), 'Installed and packaged Pro appear only once' );
echo "PASS: Import-Export Pro local ZIP discovery, canonical basename and deduplication\n";
