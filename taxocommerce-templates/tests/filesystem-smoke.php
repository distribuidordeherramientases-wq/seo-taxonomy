<?php
/**
 * Offline filesystem regression for the WordPress.org Plugin Check hotfix.
 * Execute: php taxocommerce-templates/tests/filesystem-smoke.php
 *
 * The real plugin writes only when an authorized administrator submits the
 * existing editor form. This CLI test uses an isolated temporary directory.
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/tct-pc-' . getmypid() );
define( 'TCT_DIR', dirname( __DIR__ ) . '/' );
function trailingslashit( $v ) { return rtrim( $v, '/\\' ) . '/'; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_mkdir_p( $p ) { return is_dir( $p ) || mkdir( $p, 0700, true ); }
function wp_delete_file( $p ) { if ( file_exists( $p ) ) { unlink( $p ); } }
class WP_Error {
    private $message;
    public function __construct( $code, $message ) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
class Filesystem_Mock {
    public function is_dir( $p ) { return is_dir( $p ); }
    public function is_writable( $p ) { return is_writable( $p ); }
    public function get_contents( $p ) { return file_get_contents( $p ); }
    public function put_contents( $p, $content, $mode = 0644 ) {
        return strlen( $content ) === file_put_contents( $p, $content );
    }
    public function move( $src, $dest, $overwrite = false ) {
        return ( $overwrite || !file_exists( $dest ) ) && rename( $src, $dest );
    }
    public function chmod( $p, $mode ) { return chmod( $p, $mode ); }
}
$GLOBALS['wp_filesystem'] = null;
$GLOBALS['filesystem_fails'] = false;
function WP_Filesystem() {
    if ( $GLOBALS['filesystem_fails'] ) { return false; }
    $GLOBALS['wp_filesystem'] = new Filesystem_Mock();
    return true;
}
function verify( $condition, $message ) {
    if ( !$condition ) { fwrite( STDERR, 'FAILED: ' . $message . "\n" ); exit( 1 ); }
}
require_once TCT_DIR . 'includes/class-tct-read-cache.php';
require TCT_DIR . 'includes/class-tct-router.php';
require TCT_DIR . 'includes/class-tct-template-editor.php';
$dir = TCT_Router::library_dir();
verify( !is_wp_error( TCT_Template_Editor::prepare_directory() ), 'Library created using WordPress filesystem' );
verify( file_exists( $dir . '.htaccess' ) && file_exists( $dir . 'web.config' ), 'Upload execution restrictions retained' );
$source = $dir . 'variant.php';
file_put_contents( $source, "<?php\n/* dht_template_device_variant_file( */\n" );
verify( TCT_Router::is_dispatcher( $source ), 'Dispatcher detected through WP_Filesystem' );
file_put_contents( $source, '<?php echo 1;' );
verify( !TCT_Router::is_dispatcher( $source ), 'Normal PHP template is not a dispatcher' );
$GLOBALS['wp_filesystem'] = null;
$GLOBALS['filesystem_fails'] = true;
verify( !TCT_Router::is_dispatcher( $source ), 'Unusable filesystem fails closed for routing' );
$failure = TCT_Template_Editor::prepare_directory();
verify( is_wp_error( $failure ), 'Unusable filesystem blocks writes' );
$GLOBALS['filesystem_fails'] = false;
$GLOBALS['wp_filesystem'] = null;
verify( !is_wp_error( TCT_Template_Editor::prepare_directory() ), 'Filesystem recovers for later authorized request' );
$filesystem = $GLOBALS['wp_filesystem'];
$temp = $dir . 'temp-edit.php';
$target = $dir . 'saved-edit.php';
verify( $filesystem->put_contents( $temp, '<?php echo 4;', 0640 ), 'Write permitted by WP_Filesystem' );
verify( $filesystem->move( $temp, $target, true ) && !file_exists( $temp ), 'Atomic replacement via filesystem' );
verify( file_get_contents( $target ) === '<?php echo 4;', 'Edited PHP content preserved' );
wp_delete_file( $source );
wp_delete_file( $target );
wp_delete_file( $dir . '.htaccess' );
wp_delete_file( $dir . 'web.config' );
wp_delete_file( $dir . 'index.php' );
rmdir( $dir );
rmdir( WP_CONTENT_DIR );
$restricted = array(
    'includes/class-tct-router.php' => array( 'fopen(', 'fread(', 'fclose(' ),
    'includes/class-tct-template-editor.php' => array( 'if (!move_uploaded_file(', '@unlink(', '@chmod(', 'if (!rename($temp,', '!is_writable($dir)' ),
    'includes/class-tct-association-audit.php' => array( 'fclose($output)' ),
    'taxocommerce-templates.php' => array( 'readfile($path)' ),
);
foreach ( $restricted as $path => $patterns ) {
    $php = file_get_contents( TCT_DIR . $path );
    foreach ( $patterns as $pattern ) {
        verify( false === strpos( $php, $pattern ), 'Removed forbidden operation: ' . $path . ' ' . $pattern );
    }
}
echo "PASS: Templates filesystem, protections, dispatcher and Plugin Check operations\n";
