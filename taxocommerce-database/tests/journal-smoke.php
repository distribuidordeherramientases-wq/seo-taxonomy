<?php
/**
 * CLI-only, in-memory checks for optional SQL journaling (no live DB).
 */
define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
    private $message;
    public function __construct( $code, $message ) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function absint( $v ) { return abs( (int) $v ); }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function get_option( $k, $def = false ) { return $GLOBALS['options'][ $k ] ?? $def; }
function update_option( $k, $v, $autoload = false ) { $GLOBALS['options'][ $k ] = $v; return true; }
function current_time( $type, $gmt = false ) { return '2026-10-24 18:35:00'; }
function get_current_user_id() { return 7; }
function do_action() {}
function apply_filters( $name, $fallback ) {
    return 'tcdb_rollback_supported_tables' === $name
        ? array( 'redirects' => array( 'wp_seo_redirects' ) ) : $fallback;
}

class TCDB_Fake_DB {
    public $prefix = 'wp_';
    public $base_prefix = 'wp_';
    public $blogid = 1;
    public $last_error = '';
    public $insert_id = 0;
    public $data = array();
    public $next_id = array();
    private $saved = null;
    public function __construct() {
        foreach ( array( 'wp_seo_redirects', 'wp_tcdb_operations', 'wp_tcdb_changes', 'wp_posts' ) as $table ) {
            $this->data[ $table ] = array();
            $this->next_id[ $table ] = 1;
        }
    }
    public function esc_like( $v ) { return $v; }
    public function prepare( $sql ) {
        $args = array_slice( func_get_args(), 1 );
        if ( count( $args ) === 1 && is_array( $args[0] ) ) { $args = $args[0]; }
        foreach ( $args as $arg ) {
            $replacement = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
            $sql = preg_replace( '/%[ds]/', $replacement, $sql, 1 );
        }
        return $sql;
    }
    private function schema( $table ) {
        if ( 'wp_seo_redirects' === $table ) {
            return array(
                array( 'Field' => 'id', 'Key' => 'PRI', 'Extra' => 'auto_increment' ),
                array( 'Field' => 'origin_url', 'Key' => '', 'Extra' => '' ),
                array( 'Field' => 'target_url', 'Key' => '', 'Extra' => '' ),
            );
        }
        return array( array( 'Field' => 'id', 'Key' => 'PRI', 'Extra' => 'auto_increment' ) );
    }
    public function get_results( $sql, $type = null ) {
        if ( false !== strpos( $sql, 'information_schema.TABLES' ) ) {
            $result = array();
            foreach ( $this->data as $table => $rows ) {
                $result[] = array(
                    'TABLE_NAME' => $table, 'TABLE_ROWS' => count( $rows ), 'ENGINE' => 'InnoDB',
                    'DATA_LENGTH' => 100, 'INDEX_LENGTH' => 100, 'DATA_FREE' => 0,
                    'TABLE_COLLATION' => 'utf8mb4_unicode_ci',
                );
            }
            return $result;
        }
        if ( preg_match( "/SHOW COLUMNS FROM [\\x60]?([a-z0-9_]+)/i", $sql, $m ) ) {
            return $this->schema( $m[1] );
        }
        if ( preg_match( "/FROM wp_tcdb_changes WHERE operation_id\\s*=\\s*'?([0-9]+)'?/i", $sql, $m ) ) {
            $rows = array_filter( $this->data['wp_tcdb_changes'], static function ( $r ) use ( $m ) {
                return (int) $r['operation_id'] === (int) $m[1];
            } );
            usort( $rows, static function ( $a, $b ) {
                return (int) $b['sequence_number'] <=> (int) $a['sequence_number'];
            } );
            return $rows;
        }
        if ( false !== strpos( $sql, 'SELECT id,source,label,status' ) ) {
            return array_values( $this->data['wp_tcdb_operations'] );
        }
        return array();
    }
    public function get_row( $sql, $type = null ) {
        if ( preg_match( "/FROM [\\x60]?([a-z0-9_]+)[\\x60]? WHERE [\\x60]?([a-z0-9_]+)[\\x60]?\\s*=\\s*'?([0-9]+)'?/i", $sql, $m ) ) {
            foreach ( $this->data[ $m[1] ] ?? array() as $row ) {
                if ( isset( $row[ $m[2] ] ) && (string) $row[ $m[2] ] === (string) $m[3] ) {
                    return $row;
                }
            }
        }
        return null;
    }
    public function get_var( $sql ) {
        if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", $sql, $m ) ) {
            return isset( $this->data[ $m[1] ] ) ? $m[1] : null;
        }
        return null;
    }
    public function insert( $table, $data, $format = null ) {
        if ( ! isset( $this->data[ $table ] ) ) { return false; }
        $id = isset( $data['id'] ) ? (int) $data['id'] : $this->next_id[ $table ]++;
        if ( isset( $this->data[ $table ][ $id ] ) ) { return false; }
        $this->next_id[ $table ] = max( $this->next_id[ $table ], $id + 1 );
        $this->data[ $table ][ $id ] = array_merge( $data, array( 'id' => (string) $id ) );
        $this->insert_id = $id;
        $this->last_error = '';
        return 1;
    }
    public function update( $table, $data, $where, $formats = null, $where_format = null ) {
        $id = (int) $where['id'];
        if ( ! isset( $this->data[ $table ][ $id ] ) ) { return false; }
        $this->data[ $table ][ $id ] = array_merge( $this->data[ $table ][ $id ], $data );
        return 1;
    }
    public function delete( $table, $where, $format = null ) {
        $id = (int) $where['id'];
        if ( ! isset( $this->data[ $table ][ $id ] ) ) { return 0; }
        unset( $this->data[ $table ][ $id ] );
        return 1;
    }
    public function query( $sql ) {
        if ( 'START TRANSACTION' === $sql ) {
            $this->saved = serialize( array( $this->data, $this->next_id ) );
        } elseif ( 'ROLLBACK' === $sql && null !== $this->saved ) {
            list( $this->data, $this->next_id ) = unserialize( $this->saved );
            $this->saved = null;
        } elseif ( 'COMMIT' === $sql ) {
            $this->saved = null;
        }
        return 1;
    }
}

$GLOBALS['wpdb'] = new TCDB_Fake_DB();
$GLOBALS['options'] = array( 'tcdb_journal_schema_version' => '0.2.0' );
require dirname( __DIR__ ) . '/includes/class-tcdb-storage.php';
require dirname( __DIR__ ) . '/includes/class-tcdb-journal.php';
function check_journal( $ok, $description ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL ' . $description . "\n" );
        exit( 1 );
    }
}

global $wpdb;
check_journal( TCDB_Journal::supports( 'wp_seo_redirects', 'redirects' ), 'opt-in redirects table supported' );
check_journal( ! TCDB_Journal::supports( 'wp_posts', 'redirects' ), 'WordPress posts protected' );
check_journal( ! TCDB_Journal::supports( 'wp_seo_redirects', 'other' ), 'other plugin rejected' );

$id = TCDB_Journal::mutate( 'redirects', 'Create', 'wp_seo_redirects', 'insert', 0, static function () use ( $wpdb ) {
    $wpdb->insert( 'wp_seo_redirects', array( 'origin_url' => '/a', 'target_url' => '/b' ) );
    return $wpdb->insert_id;
} );
check_journal( 1 === $id && TCDB_Journal::preview( 1 )['allowed'], 'insert tracked and reversible' );

$wpdb->update( 'wp_seo_redirects', array( 'target_url' => '/c' ), array( 'id' => 1 ) );
check_journal( ! TCDB_Journal::preview( 1 )['allowed'], 'later modification causes conflict' );
check_journal( is_wp_error( TCDB_Journal::execute( 1 ) ), 'rollback refuses conflict' );
$wpdb->update( 'wp_seo_redirects', array( 'target_url' => '/b' ), array( 'id' => 1 ) );
check_journal( ! is_wp_error( TCDB_Journal::execute( 1 ) ), 'insert rollback executes' );
check_journal( ! isset( $wpdb->data['wp_seo_redirects'][1] ), 'insert removed on rollback' );

$wpdb->insert( 'wp_seo_redirects', array( 'id' => 9, 'origin_url' => '/old', 'target_url' => '/original' ) );
$u = TCDB_Journal::mutate( 'redirects', 'Change', 'wp_seo_redirects', 'update', 9, static function () use ( $wpdb ) {
    $wpdb->update( 'wp_seo_redirects', array( 'target_url' => '/changed' ), array( 'id' => 9 ) );
    return 9;
} );
check_journal( 9 === $u && TCDB_Journal::preview( 2 )['allowed'], 'update tracked' );
check_journal( ! is_wp_error( TCDB_Journal::execute( 2 ) ), 'update reverted' );
check_journal( '/original' === $wpdb->data['wp_seo_redirects'][9]['target_url'], 'original value restored' );

$delete = TCDB_Journal::mutate( 'redirects', 'Remove', 'wp_seo_redirects', 'delete', 9, static function () use ( $wpdb ) {
    return 1 === $wpdb->delete( 'wp_seo_redirects', array( 'id' => 9 ) );
} );
check_journal( true === $delete && TCDB_Journal::preview( 3 )['allowed'], 'delete tracked' );
check_journal( ! is_wp_error( TCDB_Journal::execute( 3 ) ), 'delete reverted' );
check_journal( '/original' === $wpdb->data['wp_seo_redirects'][9]['target_url'], 'deleted record reinserted' );

$rows = TCDB_Journal::insert_batch( 'redirects', 'Batch', 'wp_seo_redirects', array(
    array( 'origin_url' => '/x', 'target_url' => '/y' ),
    array( 'origin_url' => '/p', 'target_url' => '/q' ),
) );
check_journal( is_array( $rows ) && 2 === $rows['rows'], 'multiple inserted and recorded as single operation' );
check_journal( ! is_wp_error( TCDB_Journal::execute( $rows['operation_id'] ) ), 'bulk inserts reverted atomically' );
check_journal( 1 === count( $wpdb->data['wp_seo_redirects'] ), 'existing old record preserved' );

echo "Journal smoke OK: INSERT UPDATE DELETE, conflict checks, batch and optional registration.\n";
