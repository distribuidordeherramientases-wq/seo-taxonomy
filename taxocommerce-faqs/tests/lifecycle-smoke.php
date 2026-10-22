<?php
if ( 'cli' !== PHP_SAPI || defined( 'ABSPATH' ) ) { exit; }
/** Isolated behavioral tests. No hosting, live database or network calls. */
$fixture = sys_get_temp_dir() . '/tc-faq-test-' . uniqid();
mkdir( $fixture . '/wp-admin/includes', 0777, true );
file_put_contents( $fixture . '/wp-admin/includes/upgrade.php', '<?php // dbDelta is supplied by the test harness.' );
define( 'ABSPATH', $fixture . '/' );
$GLOBALS['site'] = 1;
$GLOBALS['network'] = false;
$GLOBALS['sites'] = array( 1 );
$GLOBALS['stack'] = array();
$GLOBALS['options'] = array();
$GLOBALS['hooks'] = array();
$GLOBALS['checks'] = 0;
class WP_Error {
    private $message;
    public function __construct( $code, $message ) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_die( $message, $title = '', $args = array() ) { throw new RuntimeException( $message ); }
function esc_html( $s ) { return htmlspecialchars( $s ); }
function esc_sql( $s ) { return $s; }
function get_option( $k, $fallback = false ) { return $GLOBALS['options'][ $GLOBALS['site'] ][ $k ] ?? $fallback; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['options'][ $GLOBALS['site'] ][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $GLOBALS['site'] ][ $k ] ); return true; }
function delete_transient( $k ) { delete_option( '_transient_' . $k ); delete_option( '_transient_timeout_' . $k ); }
function get_current_user_id() { return 1; }
function is_multisite() { return $GLOBALS['network']; }
function get_sites( $args ) { return array_slice( $GLOBALS['sites'], $args['offset'], $args['number'] ); }
function switch_to_blog( $id ) { $GLOBALS['stack'][] = $GLOBALS['site']; $GLOBALS['site'] = $id; $GLOBALS['wpdb']->set_blog( $id ); }
function restore_current_blog() { $GLOBALS['site'] = array_pop( $GLOBALS['stack'] ); $GLOBALS['wpdb']->set_blog( $GLOBALS['site'] ); }
function register_activation_hook( $f, $cb ) { $GLOBALS['activation'] = $cb; }
function add_action( $hook, $cb, $priority = 10, $count = 1 ) { $GLOBALS['hooks'][ $hook ][] = $cb; }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function check( $ok, $name ) { $GLOBALS['checks']++; if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $name ); } echo 'OK ' . $name . "\n"; }
class FakeDB {
    public $prefix;
    public $options;
    public $term_taxonomy;
    public $tables = array();
    public $events = array();
    public $fail_create = false;
    public $fail_alter = false;
    public $fail_drop = false;
    public $fail_delete = false;
    public function __construct() { $this->set_blog( 1 ); }
    public function set_blog( $id ) { $this->prefix = 'shop_' . $id . '_'; $this->options = $this->prefix . 'options'; $this->term_taxonomy = $this->prefix . 'term_taxonomy'; }
    public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
    public function prepare( $sql, ...$args ) {
        if ( isset( $args[0] ) && is_array( $args[0] ) ) { $args = $args[0]; }
        $n = 0;
        return preg_replace_callback( '/%[isd]/', function( $m ) use ( &$n, $args ) {
            $v = $args[ $n++ ];
            return '%i' === $m[0] ? '`' . $v . '`' : ( '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( $v ) . "'" );
        }, $sql );
    }
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
    public function get_var( $sql ) {
        if ( preg_match( "/SHOW TABLES LIKE '(.*)'/", $sql, $m ) ) {
            $name = str_replace( array( '\\_', '\\%' ), array( '_', '%' ), stripslashes( $m[1] ) );
            return isset( $this->tables[ $name ] ) ? $name : null;
        }
        if ( preg_match( '/SHOW COLUMNS FROM `?([a-z0-9_]+)`? LIKE/', $sql, $m ) ) {
            return ! empty( $this->tables[ $m[1] ]['category_column'] ) ? 'product_cat_id' : null;
        }
        throw new RuntimeException( 'Unexpected read: ' . $sql );
    }
    public function query( $sql ) {
        $this->events[] = $sql;
        if ( preg_match( '/^ALTER TABLE `?([a-z0-9_]+)`?/', $sql, $m ) ) {
            if ( $this->fail_alter ) { return false; }
            $this->tables[ $m[1] ]['category_column'] = true; return 1;
        }
        if ( 0 === strpos( $sql, 'UPDATE ' ) ) { return 0; }
        if ( preg_match( '/^DROP TABLE IF EXISTS `([^`]+)`/', $sql, $m ) ) {
            if ( $this->fail_drop ) { return false; }
            unset( $this->tables[ $m[1] ] ); return 1;
        }
        throw new RuntimeException( 'Unexpected query: ' . $sql );
    }
    public function delete( $table, $where, $formats ) {
        if ( $this->fail_delete ) { return false; }
        check( array( 'object_type' => 'faq' ) === $where, 'relation cleanup is scoped to faq' );
        $this->tables[ $table ]['rows'] = array_values( array_filter( $this->tables[ $table ]['rows'], static function( $r ) { return 'faq' !== $r['object_type']; } ) );
        return 1;
    }
    public function get_col( $sql ) {
        check( false !== strpos( $sql, '`' . $this->options . '`' ), 'notice cleanup uses current site options' );
        return array_values( array_filter( array_keys( $GLOBALS['options'][ $GLOBALS['site'] ] ?? array() ), static function( $n ) { return (bool) preg_match( '/^_transient_(?:timeout_)?taxocommerce_faqs_notice_/', $n ); } ) );
    }
}
$GLOBALS['wpdb'] = new FakeDB();
function dbDelta( $sql ) {
    global $wpdb;
    $wpdb->events[] = $sql;
    check( false !== strpos( $sql, 'product_cat_id' ), 'fresh schema contains product category' );
    if ( $wpdb->fail_create ) { return array(); }
    $wpdb->tables[ $wpdb->prefix . 'seo_faq' ] = array( 'category_column' => true, 'rows' => array() );
    return array( 'created' );
}
if ( in_array( '--cold-uninstall', $argv, true ) ) {
    update_option( 'taxocommerce_faqs_category_schema', '1' );
    $wpdb->tables['shop_1_seo_faq'] = array( 'rows' => array( array( 'id' => 1 ) ) );
    define( 'WP_UNINSTALL_PLUGIN', 'taxocommerce-faqs/taxocommerce-faqs.php' );
    require dirname( __DIR__ ) . '/uninstall.php';
    check( ! class_exists( 'TaxoCommerce_FAQs', false ), 'cold uninstall does not boot main plugin' );
    check( ! isset( $wpdb->tables['shop_1_seo_faq'] ), 'cold uninstall removes marked table' );
    unlink( $fixture . '/wp-admin/includes/upgrade.php' );
    rmdir( $fixture . '/wp-admin/includes' ); rmdir( $fixture . '/wp-admin' ); rmdir( $fixture );
    exit;
}
require dirname( __DIR__ ) . '/taxocommerce-faqs.php';
try {
    check( is_callable( $GLOBALS['activation'] ), 'activation callback is public/callable' );
    check( is_callable( array( 'TaxoCommerce_FAQs', 'ensure_category' ) ), 'legacy admin callback remains callable' );
    check( empty( $GLOBALS['hooks']['deactivate_taxocommerce-faqs/taxocommerce-faqs.php'] ), 'no data deletion on deactivation' );
    call_user_func( $GLOBALS['activation'], false );
    check( isset( $wpdb->tables['shop_1_seo_faq'] ), 'activation creates prefixed FAQ table' );
    check( 1 === get_option( TaxoCommerce_FAQs_Lifecycle::MANAGED ), 'successful activation records managed site' );
    $wpdb->tables['shop_1_seo_faq']['rows'] = array( array( 'id' => 9, 'question' => 'Keep existing FAQ' ) );
    call_user_func( $GLOBALS['activation'], false );
    check( 9 === $wpdb->tables['shop_1_seo_faq']['rows'][0]['id'], 'reactivation preserves existing FAQs' );
    $wpdb->tables['shop_1_seo_faq']['category_column'] = false;
    call_user_func( $GLOBALS['activation'], false );
    check( $wpdb->tables['shop_1_seo_faq']['category_column'], 'activation upgrades legacy category column' );
    check( 1 === count( $wpdb->tables['shop_1_seo_faq']['rows'] ), 'schema upgrade preserves rows' );
    TaxoCommerce_FAQs_Lifecycle::uninstall();
    check( isset( $wpdb->tables['shop_1_seo_faq'] ), 'uninstall without WordPress constant does nothing' );
    // A failed installation must not leave a success marker.
    switch_to_blog( 2 ); $wpdb->fail_create = true; $caught = false;
    try { TaxoCommerce_FAQs_Lifecycle::activate(); } catch ( RuntimeException $e ) { $caught = true; }
    check( $caught, 'CREATE failure is reported' );
    check( false === get_option( TaxoCommerce_FAQs_Lifecycle::SCHEMA ), 'CREATE failure clears misleading schema marker' );
    check( false === get_option( TaxoCommerce_FAQs_Lifecycle::MANAGED ), 'CREATE failure never claims table' );
    $wpdb->fail_create = false;
    $wpdb->tables['shop_2_seo_faq'] = array( 'category_column' => false, 'rows' => array( array( 'id' => 88 ) ) );
    $wpdb->fail_alter = true; $caught = false;
    try { TaxoCommerce_FAQs_Lifecycle::activate(); } catch ( RuntimeException $e ) { $caught = true; }
    check( $caught && false === get_option( TaxoCommerce_FAQs_Lifecycle::MANAGED ), 'ALTER failure does not claim success' );
    check( 88 === $wpdb->tables['shop_2_seo_faq']['rows'][0]['id'], 'failed upgrade never removes old FAQ' );
    $wpdb->fail_alter = false; restore_current_blog();
    // Network activation and uninstall switch prefixes; unused sites are untouched.
    $GLOBALS['network'] = true; $GLOBALS['sites'] = array( 1, 2 );
    TaxoCommerce_FAQs_Lifecycle::activate( true );
    check( 1 === $GLOBALS['site'], 'network activation restores original blog' );
    check( 1 === $GLOBALS['options'][2][ TaxoCommerce_FAQs_Lifecycle::MANAGED ], 'network activation initializes second site' );
    $GLOBALS['sites'][] = 3;
    $wpdb->tables['shop_3_seo_faq'] = array( 'category_column' => true, 'rows' => array( array( 'id' => 333 ) ) );
    $wpdb->tables['shop_1_seo_vocabulary'] = array( 'rows' => array( array( 'id' => 7 ) ) );
    $wpdb->tables['shop_1_terms'] = array( 'rows' => array( array( 'term_id' => 4 ) ) );
    $wpdb->tables['shop_1_posts'] = array( 'rows' => array( array( 'ID' => 55 ) ) );
    $wpdb->tables['shop_1_seo_faq_metrics'] = array( 'rows' => array( 'legacy' ) );
    $wpdb->tables['shop_1_seo_object_vocabulary'] = array( 'rows' => array( array( 'object_type' => 'faq' ), array( 'object_type' => 'product' ), array( 'object_type' => 'page' ) ) );
    update_option( '_transient_taxocommerce_faqs_notice_10', 'message' );
    update_option( '_transient_timeout_taxocommerce_faqs_notice_10', 999 );
    update_option( '_transient_some_other_plugin', 'keep' );
    unset( $GLOBALS['options'][2][ TaxoCommerce_FAQs_Lifecycle::MANAGED ] ); // Old-release fallback.
    define( 'WP_UNINSTALL_PLUGIN', 'taxocommerce-faqs/taxocommerce-faqs.php' );
    // Drop failure must preserve both data and retry markers.
    $wpdb->fail_drop = true; $caught = false;
    try { TaxoCommerce_FAQs_Lifecycle::uninstall(); } catch ( RuntimeException $e ) { $caught = true; }
    check( $caught && isset( $wpdb->tables['shop_1_seo_faq'] ), 'DROP failure reports error and keeps data' );
    check( 1 === get_option( TaxoCommerce_FAQs_Lifecycle::MANAGED ), 'failed uninstall retains retry marker' );
    check( 1 === $GLOBALS['site'], 'failed network uninstall restores blog' );
    $wpdb->fail_drop = false;
    require dirname( __DIR__ ) . '/uninstall.php';
    check( ! isset( $wpdb->tables['shop_1_seo_faq'] ), 'WordPress uninstall drops FAQ table' );
    check( ! isset( $wpdb->tables['shop_2_seo_faq'] ), 'uninstall supports old schema marker' );
    check( isset( $wpdb->tables['shop_3_seo_faq'] ), 'uninstall preserves never-initialized site table' );
    check( isset( $wpdb->tables['shop_1_seo_vocabulary'] ), 'Vocabulary table and terms preserved' );
    check( 2 === count( $wpdb->tables['shop_1_seo_object_vocabulary']['rows'] ), 'non-FAQ vocabulary assignments preserved' );
    check( isset( $wpdb->tables['shop_1_terms'] ) && isset( $wpdb->tables['shop_1_posts'] ), 'categories and posts preserved' );
    check( isset( $wpdb->tables['shop_1_seo_faq_metrics'] ), 'unowned legacy metrics table preserved' );
    check( false === get_option( TaxoCommerce_FAQs_Lifecycle::SCHEMA ) && false === get_option( TaxoCommerce_FAQs_Lifecycle::MANAGED ), 'own options removed' );
    check( false === get_option( '_transient_taxocommerce_faqs_notice_10' ), 'own transient removed' );
    check( 'keep' === get_option( '_transient_some_other_plugin' ), 'unrelated transient preserved' );
    TaxoCommerce_FAQs_Lifecycle::uninstall();
    check( 1 === $GLOBALS['site'], 'repeated uninstall is harmless and restores context' );
    $GLOBALS['network'] = false;
    TaxoCommerce_FAQs_Lifecycle::activate();
    check( isset( $wpdb->tables['shop_1_seo_faq'] ) && empty( $wpdb->tables['shop_1_seo_faq']['rows'] ), 'reinstall recreates empty FAQ table' );
    $main = file_get_contents( dirname( __DIR__ ) . '/taxocommerce-faqs.php' );
    check( false !== strpos( $main, 'Version: ' . TAXOCOMMERCE_FAQS_VERSION ), 'header and runtime versions agree' );
    echo 'PASS: ' . $GLOBALS['checks'] . " isolated lifecycle checks\n";
} finally {
    unlink( $fixture . '/wp-admin/includes/upgrade.php' );
    rmdir( $fixture . '/wp-admin/includes' ); rmdir( $fixture . '/wp-admin' ); rmdir( $fixture );
}
