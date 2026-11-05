<?php
/**
 * Request-scoped, invalidation-aware database access for TaxoCommerce Templates.
 *
 * Reads are cached only within the current request. A unique per-request cache
 * namespace avoids serving stale site data from persistent object-cache backends.
 * Mutations invalidate all prior query keys for the remainder of this request.
 *
 * @package TaxoCommerceTemplates
 */

defined( 'ABSPATH' ) || exit;

final class TCT_DB {
    private static $request_id = null;
    private static $revision = 0;

    private static function read( $method, $arguments ) {
        global $wpdb;

        if ( ! isset( $arguments[0] ) || ! is_string( $arguments[0] )
            || ! function_exists( 'wp_cache_get' ) || ! function_exists( 'wp_cache_set' ) ) {
            return $wpdb->{$method}( ...$arguments );
        }

        if ( null === self::$request_id ) {
            self::$request_id = function_exists( 'wp_generate_uuid4' )
                ? wp_generate_uuid4() : md5( uniqid( '', true ) );
        }

        $key   = 'request_' . md5( self::$request_id . '|' . self::$revision . '|' . $method . '|' . serialize( $arguments ) );
        $found = false;
        $value = wp_cache_get( $key, 'taxocommerce_templates_sql', false, $found );
        if ( $found ) {
            return $value;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom %i tables need wpdb; this wrapper caches reads per request and invalidates on writes.
        $value = $wpdb->{$method}( ...$arguments );
        if ( empty( $wpdb->last_error ) ) {
            wp_cache_set( $key, $value, 'taxocommerce_templates_sql', 3 );
        }
        return $value;
    }

    private static function write( $method, $arguments ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Mutations of custom plugin tables cannot use WP_Query or a cached read.
        $result = $wpdb->{$method}( ...$arguments );
        ++self::$revision;
        return $result;
    }

    public static function get_var( ...$arguments ) { return self::read( 'get_var', $arguments ); }
    public static function get_row( ...$arguments ) { return self::read( 'get_row', $arguments ); }
    public static function get_col( ...$arguments ) { return self::read( 'get_col', $arguments ); }
    public static function get_results( ...$arguments ) { return self::read( 'get_results', $arguments ); }
    public static function insert( ...$arguments ) { return self::write( 'insert', $arguments ); }
    public static function update( ...$arguments ) { return self::write( 'update', $arguments ); }
    public static function delete( ...$arguments ) { return self::write( 'delete', $arguments ); }
    public static function replace( ...$arguments ) { return self::write( 'replace', $arguments ); }
    public static function query( ...$arguments ) { return self::write( 'query', $arguments ); }
}
