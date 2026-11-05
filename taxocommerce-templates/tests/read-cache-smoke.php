<?php
/** Regression tests for request-local caching and wpdb method compatibility. */
if ( 'cli' !== PHP_SAPI ) {
    exit( 1 );
}
define( 'ABSPATH', __DIR__ . '/' );
class FakeTemplatesWpdb {
    public $num_queries = 0;
    public $calls = array();
    public $value = 'initial';

    private function record( $name, $query, $options ) {
        ++$this->num_queries;
        $this->calls[] = array( $name, $query, $options );
        return $this->value;
    }

    public function get_var( $sql = null, $column = 0, $row = 0 ) {
        return $this->record( 'get_var', $sql, array( $column, $row ) );
    }
    public function get_col( $sql = null, $column = 0 ) {
        return array( $this->record( 'get_col', $sql, array( $column ) ) );
    }
    public function get_row( $sql = null, $output = 'OBJECT', $row = 0 ) {
        return (object) array( 'value' => $this->record( 'get_row', $sql, array( $output, $row ) ) );
    }
    public function get_results( $sql = null, $output = 'OBJECT' ) {
        return array( array( 'value' => $this->record( 'get_results', $sql, array( $output ) ) ) );
    }
}
function fail_test( $ok, $message ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL: ' . $message . "\n" );
        exit( 1 );
    }
}

$GLOBALS['wpdb'] = new FakeTemplatesWpdb();
require dirname( __DIR__ ) . '/includes/class-tct-read-cache.php';
$wpdb = $GLOBALS['wpdb'];
fail_test( 'initial' === TCT_Read_Query_Cache::get_var( 'SELECT 1' ), 'Initial query' );
$wpdb->value = 'updated in test';
fail_test( 'initial' === TCT_Read_Query_Cache::get_var( 'SELECT 1' ), 'Identical read uses cache' );
fail_test( 1 === count( $wpdb->calls ), 'Only one query issued for repeated read' );
$wpdb->num_queries++;
fail_test( 'updated in test' === TCT_Read_Query_Cache::get_var( 'SELECT 1' ), 'Unrelated DB activity invalidates cache' );
fail_test( 2 === count( $wpdb->calls ), 'Fresh query after write' );
TCT_Read_Query_Cache::get_var( null, 1, 2 );
TCT_Read_Query_Cache::get_var( null, 1, 2 );
fail_test( 4 === count( $wpdb->calls ), 'Null-query mode always delegated to wpdb' );
$rows = TCT_Read_Query_Cache::get_results( 'SELECT * FROM table', 'ARRAY_A' );
fail_test( is_array( $rows ) && 'get_results' === end( $wpdb->calls )[0], 'get_results return type and call preserved' );
$wpdb->value = 'changed';
TCT_Read_Query_Cache::get_results( 'SELECT * FROM table', 'ARRAY_A' );
fail_test( 5 === count( $wpdb->calls ), 'Results cache hit with same output mode' );
TCT_Read_Query_Cache::get_results( 'SELECT * FROM table', 'OBJECT' );
fail_test( 6 === count( $wpdb->calls ), 'Separate result formats use separate keys' );
TCT_Read_Query_Cache::get_col( 'SELECT id FROM table', 3 );
fail_test( $wpdb->calls[6][2][0] === 3, 'Column index is preserved' );
TCT_Read_Query_Cache::get_row( 'SELECT id FROM table', 'ARRAY_A', 2 );
fail_test( $wpdb->calls[7][2] === array( 'ARRAY_A', 2 ), 'Row output mode and index preserved' );
echo "PASS: Templates request cache preserves wpdb signatures and invalidates on other SQL\n";
