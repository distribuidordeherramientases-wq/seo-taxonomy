<?php
/**
 * Standalone read-only regression for Distribution Autocheck.
 *
 * Uses a temporary fixture. Autocheck itself must never modify that fixture,
 * schedule jobs, touch the network, or call the generation/storage engines.
 */
define( 'ABSPATH', __DIR__ );
define( 'TAXOCOMMERCE_DISTRIBUTION_VERSION', '0.1.1' );
define( 'TCDIST_WORKER_HOOK', 'taxocommerce_distribution_feed_worker' );
define( 'TCDIST_LEGACY_FEED_ACTIVE', false );
define( 'TCDIST_LEGACY_SITEMAP_ACTIVE', false );

function distribution_autocheck_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

class WooCommerce {}
function wc_get_product( $id ) { return null; }
function tcdist_feed_channels() { return array_fill_keys( array( 'google', 'microsoft', 'pinterest', 'universal', 'json' ), array( 'label' => 'test' ) ); }
function tcdist_feed_state() { throw new RuntimeException( 'Autocheck called a process state function' ); }
function tcdist_feed_settings() { throw new RuntimeException( 'Autocheck called a settings migration function' ); }
function tcdist_feed_run_manager_slice() { throw new RuntimeException( 'Autocheck launched feed generation' ); }
function tcdist_feed_channel_finalize() { throw new RuntimeException( 'Autocheck published a feed' ); }
function tcdist_distribution_feed_worker_tick() { throw new RuntimeException( 'Autocheck launched the worker' ); }
function tcdist_sitemap_create_sitemaps() { throw new RuntimeException( 'Autocheck generated sitemaps' ); }
function tcdist_sitemap_validate_xml_file() { throw new RuntimeException( 'Autocheck parsed a sitemap' ); }
function tcdist_sitemap_get_sitemap_report() { throw new RuntimeException( 'Autocheck executed the sitemap reporting engine' ); }
function tcdist_sitemap_sitemap_public_url() { throw new RuntimeException( 'Autocheck constructed a sitemap URL' ); }
function tcdist_feed_storage() { throw new RuntimeException( 'Autocheck attempted a storage initializer' ); }
function tcdist_sitemap_sitemap_storage() { throw new RuntimeException( 'Autocheck attempted sitemap initialization' ); }
function wp_remote_get() { throw new RuntimeException( 'Autocheck attempted HTTP' ); }
function wp_remote_post() { throw new RuntimeException( 'Autocheck attempted HTTP' ); }
function update_option() { throw new RuntimeException( 'Autocheck attempted a database write' ); }
function wp_schedule_single_event() { throw new RuntimeException( 'Autocheck scheduled an event' ); }
function wp_schedule_event() { throw new RuntimeException( 'Autocheck scheduled an event' ); }
function wp_mkdir_p() { throw new RuntimeException( 'Autocheck created a directory' ); }

function has_action( $name, $callback = false ) {
    $hooks = array(
        'taxocommerce_distribution_feed_worker',
        'admin_post_tcdist_feed_save_settings',
        'admin_post_tcdist_feed_regenerate',
        'admin_post_tcdist_feed_stop',
        'admin_post_tcdist_sitemap_regenerate',
        'tcdist_sitemap_regenerate_sitemaps_event',
    );
    return in_array( $name, $hooks, true ) ? 10 : false;
}
function get_option( $key, $default = false ) {
    $options = array(
        'tcdist_commercial_feeds_settings_v1' => array( 'enabled_channels' => array( 'google' ) ),
        'tcdist_commercial_feeds_state_v1' => array( 'status' => 'done' ),
    );
    return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
}
function trailingslashit( $path ) { return rtrim( (string) $path, '/' ) . '/'; }
function wp_upload_dir( $time = null, $create_dir = true ) {
    global $tcdist_smoke_root;
    if ( $create_dir ) { throw new RuntimeException( 'Autocheck requested writable uploads initialization' ); }
    return array( 'error' => false, 'basedir' => $tcdist_smoke_root );
}

$tcdist_smoke_root = sys_get_temp_dir() . '/tcdist-autocheck-' . bin2hex( random_bytes( 6 ) );
mkdir( $tcdist_smoke_root, 0700, true );
mkdir( $tcdist_smoke_root . '/seo-system-feeds', 0700 );
mkdir( $tcdist_smoke_root . '/seo-sitemaps', 0700 );
file_put_contents( $tcdist_smoke_root . '/seo-system-feeds/google-merchant.xml', '<rss />' );
file_put_contents( $tcdist_smoke_root . '/seo-sitemaps/sitemap.xml', '<sitemapindex />' );

require dirname( __DIR__ ) . '/includes/autocheck.php';
$before = array(
    filemtime( $tcdist_smoke_root . '/seo-system-feeds/google-merchant.xml' ),
    filemtime( $tcdist_smoke_root . '/seo-sitemaps/sitemap.xml' ),
    scandir( $tcdist_smoke_root ),
);
$checks = taxocommerce_distribution_autocheck_run();
$after = array(
    filemtime( $tcdist_smoke_root . '/seo-system-feeds/google-merchant.xml' ),
    filemtime( $tcdist_smoke_root . '/seo-sitemaps/sitemap.xml' ),
    scandir( $tcdist_smoke_root ),
);
distribution_autocheck_assert( count( $checks ) >= 13, 'grouped Autocheck has all expected availability checks' );
distribution_autocheck_assert( $before === $after, 'Autocheck does not mutate published files or directories' );
distribution_autocheck_assert( ! in_array( 'error', array_column( $checks, 'status' ), true ), 'fully available components report no errors' );

$admin = file_get_contents( dirname( __DIR__ ) . '/includes/admin.php' );
$main = file_get_contents( dirname( __DIR__ ) . '/taxocommerce-distribution.php' );
$manual = file_get_contents( dirname( __DIR__ ) . '/includes/manual.php' );
distribution_autocheck_assert(
    strpos( $admin, "'manual'     => __( 'Manual'" ) !== false &&
    strpos( $admin, "'autocheck'  => __( 'Autocheck'" ) !== false &&
    strpos( $admin, 'taxocommerce_distribution_render_autocheck();' ) !== false &&
    strpos( $admin, 'taxocommerce_distribution_render_manual();' ) !== false &&
    strpos( $main, "'includes/autocheck.php'" ) !== false &&
    strpos( $manual, 'Manual de usuario' ) !== false &&
    strpos( $manual, 'Manual técnico para futuros desarrolladores' ) !== false &&
    strpos( $manual, 'tcdist-manual-usuario' ) !== false &&
    strpos( $manual, 'tcdist-manual-tecnico' ) !== false &&
    strpos( $manual, 'Mapa de código y responsabilidades' ) !== false &&
    strpos( $manual, 'Contrato de datos y eventos' ) !== false &&
    strpos( $manual, 'Procedimiento para futuros desarrolladores' ) !== false &&
    strpos( $manual, 'tcdist_distribution_feed_worker_tick()' ) !== false &&
    strpos( $manual, 'tcdist_sitemap_create_sitemaps()' ) !== false,
    'both tabs are served by the independent Distribution admin'
);

unlink( $tcdist_smoke_root . '/seo-system-feeds/google-merchant.xml' );
unlink( $tcdist_smoke_root . '/seo-sitemaps/sitemap.xml' );
rmdir( $tcdist_smoke_root . '/seo-system-feeds' );
rmdir( $tcdist_smoke_root . '/seo-sitemaps' );
rmdir( $tcdist_smoke_root );
echo "TaxoCommerce Distribution Manual/Autocheck smoke passed.\n";
