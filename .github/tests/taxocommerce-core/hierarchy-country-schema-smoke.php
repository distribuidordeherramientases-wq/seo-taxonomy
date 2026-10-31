<?php
/**
 * Standalone regression for non-destructive country schema migration.
 * No real WordPress installation or database connection.
 */
if ( 'cli' !== PHP_SAPI ) { exit; }
define( 'ABSPATH', __DIR__ . '/' );
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function current_time( $type ) { return '2026-10-28 12:00:00'; }
function get_post_type( $id ) { return 'page'; }
class WP_Error {
    public function __construct( $code, $message ) { throw new RuntimeException( $code . ': ' . $message ); }
}
class TaxoCommerce_Core_Country {
    public static function post( $id ) { return 123 === (int) $id ? 'FR' : 'ES'; }
}
class CountrySchemaFakeDb {
    public $prefix = 'wp_';
    public $columns = array( 'wp_seo_nodes' => array(), 'wp_seo_relations' => array() );
    public $alterations = array();
    public $updates = array();
    public $inserts = array();

    public function esc_like( $s ) { return $s; }
    public function prepare( $sql, ...$values ) {
        return preg_replace_callback( '/%[sdi]/', static function ( $match ) use ( &$values ) {
            $v = array_shift( $values );
            return '%i' === $match[0] ? '`' . str_replace( '`', '``', (string) $v ) . '`' : ( '%d' === $match[0] ? (string) (int) $v : "'" . str_replace( "'", "''", (string) $v ) . "'" );
        }, $sql );
    }
    public function get_var( $sql ) {
        if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", $sql, $m ) ) {
            return array_key_exists( $m[1], $this->columns ) ? $m[1] : null;
        }
        if ( preg_match( "/SHOW COLUMNS FROM `?(wp_seo_[a-z]+)`? LIKE 'country_code'/", $sql, $m ) ) {
            return in_array( 'country_code', $this->columns[ $m[1] ], true ) ? 'country_code' : null;
        }
        return null;
    }
    public function query( $sql ) {
        if ( preg_match( '/^ALTER TABLE `?(wp_seo_(?:nodes|relations))`? ADD COLUMN `?country_code`? CHAR\\(2\\)/', $sql, $m ) ) {
            $this->columns[ $m[1] ][] = 'country_code';
            $this->alterations[] = $sql;
        }
        return 0;
    }
    public function update( $table, $data, $where ) {
        $this->updates[] = array( $table, $data, $where );
        return 1;
    }
    public function insert( $table, $data ) {
        $this->inserts[] = array( $table, $data );
        return 1;
    }
}
function verify( $v, $label ) {
    if ( ! $v ) { fwrite( STDERR, 'FAIL: ' . $label . "\n" ); exit( 1 ); }
}
$GLOBALS['wpdb'] = new CountrySchemaFakeDb();
require ( dirname( __DIR__, 3 ) . '/taxocommerce-core' ) . '/includes/class-taxocommerce-core-hierarchy-classic.php';
TaxoCommerce_Core_Hierarchy_Classic::ensure_country_schema();
verify( count( $wpdb->alterations ) === 2, 'migrate both pre-existing tables' );
verify( in_array( 'country_code', $wpdb->columns['wp_seo_nodes'], true ), 'nodes column present' );
verify( in_array( 'country_code', $wpdb->columns['wp_seo_relations'], true ), 'relations column present' );
TaxoCommerce_Core_Hierarchy_Classic::ensure_country_schema();
verify( count( $wpdb->alterations ) === 2, 'migration re-entry causes no DDL' );
$ok = TaxoCommerce_Core_Hierarchy_Classic::save_role_node( 123, 'hub_secondary' );
verify( true === $ok, 'save international role' );
verify( count( $wpdb->inserts ) === 1, 'one role insert' );
$entry = $wpdb->inserts[0];
verify( $entry[0] === 'wp_seo_nodes', 'canonical nodes table' );
verify( $entry[1]['country_code'] === 'FR', 'French country persisted with node' );
verify( $entry[1]['seo_role'] === 'hub_secondary', 'role persisted unchanged' );
verify( true === TaxoCommerce_Core_Hierarchy_Classic::sync_role_country( 123 ), 'country sync' );
$update = $wpdb->updates[count( $wpdb->updates ) - 1];
verify( $update[0] === 'wp_seo_nodes' && $update[1]['country_code'] === 'FR', 'page-country sync uses nodes' );
verify( $update[2]['object_type'] === 'page' && $update[2]['object_id'] === 123 && $update[2]['status'] === 1, 'sync only targets active role of same page' );
verify( count( $wpdb->alterations ) === 2, 'role operations never modify schema again' );
echo "PASS: canonical country columns, idempotent upgrade, country-aware node insert and sync.\n";
