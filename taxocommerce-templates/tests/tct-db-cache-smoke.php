<?php
/** Offline regression coverage for query caching and mutation invalidation. */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
$GLOBALS['tct_db_fake_cache'] = array();
function wp_generate_uuid4() { return 'e6a478e3-9798-4f9c-b2d8-1736436a147d'; }
function wp_cache_get( $key, $group, $force = false, &$found = null ) {
    $id = $group . ':' . $key;
    $found = array_key_exists( $id, $GLOBALS['tct_db_fake_cache'] );
    return $found ? $GLOBALS['tct_db_fake_cache'][ $id ] : false;
}
function wp_cache_set( $key, $value, $group = '', $expire = 0 ) {
    $GLOBALS['tct_db_fake_cache'][ $group . ':' . $key ] = $value;
    return true;
}
class TCT_DB_Mock {
    public $last_error = '';
    public $reads = 0;
    public $writes = 0;
    public function get_var( $sql ) { ++$this->reads; return $sql; }
    public function update( ...$args ) { ++$this->writes; return 1; }
}
$GLOBALS['wpdb'] = new TCT_DB_Mock();
require_once dirname( __DIR__ ) . '/includes/class-tct-db.php';
$first = TCT_DB::get_var( 'SELECT 7' );
$second = TCT_DB::get_var( 'SELECT 7' );
if ( 'SELECT 7' !== $first || $first !== $second || 1 !== $GLOBALS['wpdb']->reads ) {
    fwrite( STDERR, "FAIL: query cache did not reuse an identical read\n" );
    exit( 1 );
}
if ( 1 !== TCT_DB::update( 'wp_tct', array( 'enabled' => 1 ), array( 'id' => 3 ) ) ) {
    fwrite( STDERR, "FAIL: DB update result changed\n" );
    exit( 1 );
}
$third = TCT_DB::get_var( 'SELECT 7' );
if ( 2 !== $GLOBALS['wpdb']->reads || 'SELECT 7' !== $third || 1 !== $GLOBALS['wpdb']->writes ) {
    fwrite( STDERR, "FAIL: successful update did not invalidate prior SQL cache\n" );
    exit( 1 );
}
echo "PASS: request-cache reuse and invalidation after mutation\n";
