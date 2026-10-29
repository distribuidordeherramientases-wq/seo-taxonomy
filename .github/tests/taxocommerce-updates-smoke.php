<?php
/** Self-contained CLI regression test: never invokes WordPress or installs ZIPs. */
define( 'ABSPATH', __DIR__ );
$GLOBALS['tc_update_filters'] = array();
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
    $GLOBALS['tc_update_filters'][ $hook ] = $callback;
}
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function wp_get_upload_dir() { return $GLOBALS['tc_uploads']; }
function esc_url_raw( $url ) { return $url; }
function home_url( $part = '/' ) { return 'https://staging.distribuidordeherramientas.es' . $part; }
function tc_assert( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); }
}
tc_assert( class_exists( 'ZipArchive' ), 'ZIP extension required for real update verification' );
$root = sys_get_temp_dir() . '/tc-update-' . getmypid();
$dir = $root . '/taxocommerce-core/plugins';
mkdir( $dir, 0700, true );
$GLOBALS['tc_uploads'] = array( 'basedir' => $root, 'baseurl' => 'https://staging.distribuidordeherramientas.es/wp-content/uploads', 'error' => false );
$slug = 'taxocommerce-database';
$uri = 'https://www.distribuidordeherramientas.es/taxocommerce-updates/' . $slug . '/';
$file = $slug . '/' . $slug . '.php';
$data = array( 'UpdateURI' => $uri, 'Version' => '0.4.5' );

require dirname( __DIR__ ) . '/updates/taxocommerce-upload-update-provider.php';
$hook = 'update_plugins_www.distribuidordeherramientas.es';
tc_assert( isset( $GLOBALS['tc_update_filters'][ $hook ] ), 'WordPress Update URI hook registered' );
$provider = $GLOBALS['tc_update_filters'][ $hook ];
tc_assert( false === $provider( false, $data, $file, array() ), 'No updates without manifest' );

$package = $dir . '/' . $slug . '.zip';
$zip = new ZipArchive();
tc_assert( true === $zip->open( $package, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'ZIP creation' );
$zip->addFromString( $slug . '/' . $slug . '.php', "<?php\n/* Plugin Name: TaxoCommerce Database\nVersion: 0.4.6 */\n" );
$zip->close();
$manifest = array( 'schema' => 1, 'plugins' => array( $slug => array(
    'version' => '0.4.6',
    'sha256' => hash_file( 'sha256', $package ),
    'size' => filesize( $package ),
    'requires_php' => '7.4'
) ) );
file_put_contents( $dir . '/manifest.json', json_encode( $manifest ) );
/* Repository lookup is memoized during the current WP request. Fresh process needed
 * for manifest appearance test; provider response is tested with manifest present
 * in a second isolated child PHP run below. */
$script = $root . '/child.php';
$child = '<?php
define("ABSPATH",__DIR__);
function add_filter($h,$f,$p=10,$n=1){}
function trailingslashit($s){return rtrim($s,"/")."/";}
function wp_get_upload_dir(){return array("basedir"=>' . var_export( $root, true ) . ',"baseurl"=>"https://staging.distribuidordeherramientas.es/wp-content/uploads","error"=>false);}
function esc_url_raw($u){return $u;}
function home_url($p="/"){return "https://staging.distribuidordeherramientas.es".$p;}
require ' . var_export( dirname( __DIR__ ) . '/updates/taxocommerce-upload-update-provider.php', true ) . ';
$uri=' . var_export( $uri, true ) . ';
$f=' . var_export( $file, true ) . ';
$good=TaxoCommerce_Upload_Update_Provider::check(false,array("UpdateURI"=>$uri,"Version"=>"0.4.5"),$f,array());
if (!is_array($good) || $good["version"]!=="0.4.6" || empty($good["package"]) || isset($good["autoupdate"])) {exit(2);}
$equal=TaxoCommerce_Upload_Update_Provider::check(false,array("UpdateURI"=>$uri,"Version"=>"0.4.6"),$f,array());
if (!is_array($equal) || isset($equal["package"])) {exit(3);}
$other=TaxoCommerce_Upload_Update_Provider::check(false,array("UpdateURI"=>"https://wordpress.org/plugins/other/","Version"=>"0.1"),$f,array());
if ($other!==false) {exit(4);}
exit(0);
';
file_put_contents( $script, $child );
$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script );
exec( $command, $output, $status );
tc_assert( 0 === $status, 'Version-aware update check, native opt-in and exact plugin targeting (child exit ' . $status . ')' );
unlink( $script );
unlink( $dir . '/manifest.json' );
unlink( $package );
rmdir( $dir );
rmdir( $root . '/taxocommerce-core' );
rmdir( $root );
echo "TaxoCommerce uploads update provider smoke OK\n";
