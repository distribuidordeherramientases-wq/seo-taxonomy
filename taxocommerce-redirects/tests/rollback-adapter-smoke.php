<?php
/**
 * Invoke Redirects' existing standalone smoke while a mock optional
 * Database journal is active. No SQL and no WordPress are required.
 */
class TCDB_Journal {
    public static $changes = array();
    public static function ready() { return true; }
    public static function supports( $table, $source = '' ) {
        return 'wp_seo_redirects' === $table && 'redirects' === $source;
    }
    public static function mutate( $source, $label, $table, $action, $id, $callback ) {
        $result = call_user_func( $callback );
        if ( false !== $result && ( ! class_exists( 'WP_Error' ) || ! $result instanceof WP_Error ) ) {
            self::$changes[] = array( 'source' => $source, 'table' => $table, 'action' => $action, 'label' => $label );
        }
        return $result;
    }
}
require __DIR__ . '/smoke.php';

$types = array_column( TCDB_Journal::$changes, 'action' );
foreach ( array( 'insert', 'update', 'delete' ) as $action ) {
    if ( ! in_array( $action, $types, true ) ) {
        fwrite( STDERR, "FAIL: Redirects did not call optional journal for $action\n" );
        exit( 1 );
    }
}
foreach ( TCDB_Journal::$changes as $change ) {
    if ( 'redirects' !== $change['source'] || 'wp_seo_redirects' !== $change['table'] ) {
        fwrite( STDERR, "FAIL: only Redirects own table is allowed\n" );
        exit( 1 );
    }
}
echo "Redirects optional rollback adapter smoke OK\n";
