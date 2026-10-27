<?php
/**
 * Independent adaptive pressure regulator for Import-Export.
 *
 * Keeps separate limits and telemetry for CSV imports and exports.
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_IE_Load {
    const OPTION = 'taxocommerce_ie_load_profile';
    const SAMPLE = 'taxocommerce_ie_load_sample';

    public static function labels() {
        return array( 1 => 'Suave', 2 => 'Baja', 3 => 'Normal', 4 => 'Alta', 5 => 'Máxima' );
    }

    public static function priority( $kind ) {
        $values = get_option( self::OPTION, array() );
        $value = is_array( $values ) ? absint( $values[ sanitize_key( $kind ) ] ?? 3 ) : 3;
        return max( 1, min( 5, $value ) );
    }

    public static function set_priority( $kind, $raw ) {
        $kind = sanitize_key( $kind );
        if ( ! in_array( $kind, array( 'import', 'export' ), true )
            || ! is_scalar( $raw ) || ! preg_match( '/^[1-5]$/', trim( (string) $raw ) ) ) {
            return new WP_Error( 'tc_ie_priority_invalid', 'La velocidad debe estar entre 1 y 5.' );
        }
        $values = get_option( self::OPTION, array() );
        $values = is_array( $values ) ? $values : array();
        $values[ $kind ] = (int) $raw;
        update_option( self::OPTION, $values, false );
        return (int) $raw;
    }

    public static function delay( $kind ) {
        $delays = array( 1 => 15, 2 => 8, 3 => 4, 4 => 2, 5 => 1 );
        return $delays[ self::priority( $kind ) ];
    }

    public static function batch_size( $kind ) {
        $initial = array( 1 => 1, 2 => 3, 3 => 7, 4 => 13, 5 => 23 );
        $maximum = array( 1 => 5, 2 => 13, 3 => 29, 4 => 47, 5 => 79 );
        $kind = sanitize_key( $kind );
        $priority = self::priority( $kind );
        $last = get_option( self::SAMPLE, array() );
        $last = is_array( $last ) ? (array) ( $last[ $kind ] ?? array() ) : array();
        $current = isset( $last['next_batch'] ) && absint( $last['priority'] ?? 0 ) === $priority
            ? absint( $last['next_batch'] ) : $initial[ $priority ];
        return max( 1, min( $maximum[ $priority ], $current ) );
    }

    public static function begin() {
        $cpu = function_exists( 'getrusage' ) ? getrusage() : null;
        return array(
            'start' => microtime( true ),
            'cpu' => self::cpu_seconds( $cpu ),
        );
    }

    public static function last_sample( $kind ) {
        $values = get_option( self::SAMPLE, array() );
        return is_array( $values ) ? (array) ( $values[ sanitize_key( $kind ) ] ?? array() ) : array();
    }

    public static function finish( $kind, $start, $items ) {
        $kind = sanitize_key( $kind );
        $priority = self::priority( $kind );
        $elapsed = max( 0.0001, microtime( true ) - (float) ( $start['start'] ?? microtime( true ) ) );
        $cpu = function_exists( 'getrusage' ) ? self::cpu_seconds( getrusage() ) : null;
        $used = null !== $cpu && isset( $start['cpu'] ) && null !== $start['cpu'] ? max( 0, $cpu - $start['cpu'] ) : null;
        $cpu_percent = null === $used ? null : min( 100, 100 * $used / $elapsed );
        $limit = (int) ini_get( 'memory_limit' );
        $raw = strtolower( trim( (string) ini_get( 'memory_limit' ) ) );
        if ( 'g' === substr( $raw, -1 ) ) $limit *= 1024 * 1024 * 1024;
        elseif ( 'm' === substr( $raw, -1 ) ) $limit *= 1024 * 1024;
        elseif ( 'k' === substr( $raw, -1 ) ) $limit *= 1024;
        $memory = $limit > 0 ? 100 * memory_get_peak_usage( true ) / $limit : null;
        $target = array( 1 => 20, 2 => 35, 3 => 50, 4 => 70, 5 => 90 )[ $priority ];
        $current = self::batch_size( $kind );
        $maximum = array( 1 => 5, 2 => 13, 3 => 29, 4 => 47, 5 => 79 )[ $priority ];
        $next = $current;
        if ( $elapsed >= 20 || ( null !== $memory && $memory >= 75 )
            || ( null !== $cpu_percent && $cpu_percent > $target + 10 ) ) {
            $next = max( 1, (int) floor( $current / 2 ) );
        } elseif ( null !== $cpu_percent && $cpu_percent < max( 5, $target - 15 )
            && ( null === $memory || $memory < 60 ) && $elapsed < 10 ) {
            $next = min( $maximum, $current + max( 1, (int) ceil( $current / 3 ) ) );
        }
        $sample = array(
            'priority' => $priority, 'items' => absint( $items ), 'batch' => $current,
            'next_batch' => $next, 'elapsed' => round( $elapsed, 3 ),
            'cpu_percent' => null === $cpu_percent ? null : round( $cpu_percent, 1 ),
            'memory_percent' => null === $memory ? null : round( $memory, 1 ),
            'memory_peak' => memory_get_peak_usage( true ), 'at' => gmdate( 'c' ),
        );
        $values = get_option( self::SAMPLE, array() );
        $values = is_array( $values ) ? $values : array();
        $values[ $kind ] = $sample;
        update_option( self::SAMPLE, $values, false );
        return $sample;
    }

    private static function cpu_seconds( $usage ) {
        if ( ! is_array( $usage ) ) return null;
        return (float) ( $usage['ru_utime.tv_sec'] ?? 0 )
            + (float) ( $usage['ru_utime.tv_usec'] ?? 0 ) / 1000000
            + (float) ( $usage['ru_stime.tv_sec'] ?? 0 )
            + (float) ( $usage['ru_stime.tv_usec'] ?? 0 ) / 1000000;
    }
}
