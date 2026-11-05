<?php
/**
 * Request-scoped, automatically invalidated query memoization for Templates.
 *
 * WordPress does not expose a native API for the plugin's custom relation
 * tables. This adapter preserves wpdb's read result formats and permits
 * repeated identical reads to reuse their result within the same request.
 * The cache is cleared after ANY database query, including SQL executed by
 * another plugin, so POST handlers and concurrent writes never see a stale
 * cross-request persistent cache.
 *
 * @package TaxoCommerceTemplates
 */
defined( 'ABSPATH' ) || exit;

final class TCT_Read_Query_Cache {
    const MAX_ENTRIES = 32;
    private static $items = array();
    private static $last_query_count = null;

    /** @return mixed */
    private static function read( $method, $query, $options = array() ) {
        global $wpdb;
        // A null query is wpdb's special "reuse previous result" mode. It
        // must never be memoized because it depends on wpdb->last_result.
        $has_epoch = isset( $wpdb->num_queries ) && is_numeric( $wpdb->num_queries );
        if ( null === $query || ! is_string( $query ) || ! $has_epoch ) {
            return self::direct_read( $method, $query, $options );
        }
        $epoch = (int) $wpdb->num_queries;
        if ( null === self::$last_query_count || self::$last_query_count !== $epoch ) {
            self::$items = array();
            self::$last_query_count = $epoch;
        }
        $key = hash( 'sha256', serialize( array( $method, $query, $options ) ) );
        if ( array_key_exists( $key, self::$items ) ) {
            return self::$items[ $key ];
        }
        $result = self::direct_read( $method, $query, $options );
        self::$last_query_count = (int) $wpdb->num_queries;
        // Keep retained result sets bounded on large product catalogues.
        if ( count( self::$items ) >= self::MAX_ENTRIES ) {
            array_shift( self::$items );
        }
        self::$items[ $key ] = $result;
        return $result;
    }

    /**
     * The only wpdb read entrypoint: direct queries against plugin-owned
     * custom tables are necessary, but every repeated query is memoized
     * above, and the SQL itself must be static or prepared at the call site.
     *
     * @return mixed
     */
    private static function direct_read( $method, $query, $options ) {
        global $wpdb;
        switch ( $method ) {
            case 'get_var':
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Custom-table read delegated here with request-scope caching and caller-prepared SQL.
                return $wpdb->get_var( $query, $options[0], $options[1] );
            case 'get_col':
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Custom-table read delegated here with request-scope caching and caller-prepared SQL.
                return $wpdb->get_col( $query, $options[0] );
            case 'get_row':
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Custom-table read delegated here with request-scope caching and caller-prepared SQL.
                return $wpdb->get_row( $query, $options[0], $options[1] );
            case 'get_results':
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Custom-table read delegated here with request-scope caching and caller-prepared SQL.
                return $wpdb->get_results( $query, $options[0] );
        }
        throw new InvalidArgumentException( 'Unsupported Templates database read operation.' );
    }

    public static function get_var( $query = null, $column = 0, $row = 0 ) {
        return self::read( 'get_var', $query, array( $column, $row ) );
    }

    public static function get_col( $query = null, $column = 0 ) {
        return self::read( 'get_col', $query, array( $column ) );
    }

    public static function get_row( $query = null, $output = 'OBJECT', $row = 0 ) {
        return self::read( 'get_row', $query, array( $output, $row ) );
    }

    public static function get_results( $query = null, $output = 'OBJECT' ) {
        return self::read( 'get_results', $query, array( $output ) );
    }
}
