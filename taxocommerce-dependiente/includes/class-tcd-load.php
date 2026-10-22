<?php
/**
 * Adaptive work-pressure controller for TaxoCommerce Dependiente.
 *
 * The administrator selects only a priority from 1 to 5. Internally the
 * controller adapts the batch size from the measured cost of the previous
 * request. It never requires host-wide CPU metrics.
 */
defined( 'ABSPATH' ) || exit;

final class TCD_Load {
    const PRIORITY_OPTION = 'tcd_work_priority';
    const BATCH_OPTION = 'tcd_adaptive_batch';
    const LAST_SAMPLE_OPTION = 'tcd_load_last_sample';
    const DEFAULT_PRIORITY = 3;

    private static function levels() {
        return array( 1, 3, 5, 7, 11, 13, 17, 23, 29, 37, 47, 59, 79 );
    }

    public static function priority() {
        return max( 1, min( 5, absint( get_option( self::PRIORITY_OPTION, self::DEFAULT_PRIORITY ) ) ) );
    }

    public static function set_priority( $raw ) {
        if ( ! is_scalar( $raw ) || ! preg_match( '/^[1-5]$/', trim( (string) $raw ) ) ) {
            return new WP_Error( 'tcd_priority_invalid', 'La prioridad debe estar entre 1 y 5.' );
        }
        $priority = (int) $raw;
        update_option( self::PRIORITY_OPTION, $priority, false );
        update_option( self::BATCH_OPTION, self::initial_batch( $priority ), false );
        return $priority;
    }

    public static function label( $priority = null ) {
        $priority = null === $priority ? self::priority() : max( 1, min( 5, absint( $priority ) ) );
        $labels = array(
            1 => 'Suave',
            2 => 'Baja',
            3 => 'Normal',
            4 => 'Alta',
            5 => 'Máxima',
        );
        return $labels[ $priority ];
    }

    public static function target_cpu( $priority = null ) {
        $priority = null === $priority ? self::priority() : max( 1, min( 5, absint( $priority ) ) );
        $targets = array( 1 => 20, 2 => 35, 3 => 50, 4 => 70, 5 => 90 );
        return $targets[ $priority ];
    }

    public static function delay( $priority = null ) {
        $priority = null === $priority ? self::priority() : max( 1, min( 5, absint( $priority ) ) );
        $delays = array( 1 => 15, 2 => 8, 3 => 4, 4 => 2, 5 => 1 );
        return $delays[ $priority ];
    }

    public static function initial_batch( $priority = null ) {
        $priority = null === $priority ? self::priority() : max( 1, min( 5, absint( $priority ) ) );
        $initial = array( 1 => 1, 2 => 3, 3 => 7, 4 => 13, 5 => 23 );
        return $initial[ $priority ];
    }

    public static function max_batch( $priority = null ) {
        $priority = null === $priority ? self::priority() : max( 1, min( 5, absint( $priority ) ) );
        $maximum = array( 1 => 5, 2 => 13, 3 => 29, 4 => 47, 5 => 79 );
        return $maximum[ $priority ];
    }

    public static function batch_size() {
        $current = absint( get_option( self::BATCH_OPTION, 0 ) );
        if ( $current < 1 ) {
            $current = self::initial_batch();
            update_option( self::BATCH_OPTION, $current, false );
        }
        return min( $current, self::max_batch() );
    }

    public static function last_sample() {
        $sample = get_option( self::LAST_SAMPLE_OPTION, array() );
        return is_array( $sample ) ? $sample : array();
    }

    public static function begin() {
        return array(
            'wall' => microtime( true ),
            'cpu' => self::cpu_seconds(),
            'memory' => memory_get_usage( true ),
            'queries' => function_exists( 'get_num_queries' ) ? get_num_queries() : 0,
        );
    }

    public static function finish( $start, $items ) {
        $start = is_array( $start ) ? $start : array();
        $elapsed = max( 0.0001, microtime( true ) - (float) ( $start['wall'] ?? microtime( true ) ) );
        $cpu_start = isset( $start['cpu'] ) && is_numeric( $start['cpu'] ) ? (float) $start['cpu'] : null;
        $cpu_end = self::cpu_seconds();
        $cpu_seconds = null;
        $cpu_percent = null;
        if ( null !== $cpu_start && null !== $cpu_end && $cpu_end >= $cpu_start ) {
            $cpu_seconds = $cpu_end - $cpu_start;
            $cpu_percent = min( 100, max( 0, 100 * $cpu_seconds / $elapsed ) );
        }

        $memory_peak = memory_get_peak_usage( true );
        $memory_limit = self::memory_limit_bytes();
        $memory_percent = $memory_limit > 0 ? min( 100, 100 * $memory_peak / $memory_limit ) : null;
        $queries_now = function_exists( 'get_num_queries' ) ? get_num_queries() : 0;
        $query_delta = max( 0, absint( $queries_now ) - absint( $start['queries'] ?? 0 ) );

        $current = self::batch_size();
        $next = $current;
        $target = self::target_cpu();
        $must_reduce = ( null !== $memory_percent && $memory_percent >= 75 )
            || $elapsed >= 20
            || ( null !== $cpu_percent && $cpu_percent > ( $target + 10 ) );

        if ( $must_reduce ) {
            $next = self::half_level( $current );
        } elseif ( null !== $cpu_percent
            && $cpu_percent < max( 5, $target - 15 )
            && ( null === $memory_percent || $memory_percent < 60 )
            && $elapsed < 10
        ) {
            $next = self::next_level( $current, self::max_batch() );
        }

        update_option( self::BATCH_OPTION, $next, false );

        $sample = array(
            'priority' => self::priority(),
            'label' => self::label(),
            'items' => absint( $items ),
            'batch' => $current,
            'next_batch' => $next,
            'elapsed' => round( $elapsed, 3 ),
            'cpu_seconds' => null === $cpu_seconds ? null : round( $cpu_seconds, 4 ),
            'cpu_percent' => null === $cpu_percent ? null : round( $cpu_percent, 1 ),
            'memory_peak' => $memory_peak,
            'memory_limit' => $memory_limit,
            'memory_percent' => null === $memory_percent ? null : round( $memory_percent, 1 ),
            'queries' => $query_delta,
            'at' => gmdate( 'c' ),
        );
        update_option( self::LAST_SAMPLE_OPTION, $sample, false );
        return $sample;
    }

    private static function next_level( $current, $maximum ) {
        foreach ( self::levels() as $level ) {
            if ( $level > $current && $level <= $maximum ) {
                return $level;
            }
        }
        return min( $current, $maximum );
    }

    private static function half_level( $current ) {
        $target = max( 1, (int) floor( $current / 2 ) );
        $chosen = 1;
        foreach ( self::levels() as $level ) {
            if ( $level > $target ) {
                break;
            }
            $chosen = $level;
        }
        return $chosen;
    }

    private static function cpu_seconds() {
        if ( ! function_exists( 'getrusage' ) ) {
            return null;
        }
        $usage = getrusage();
        if ( ! is_array( $usage ) ) {
            return null;
        }
        $user = (float) ( $usage['ru_utime.tv_sec'] ?? 0 ) + ( (float) ( $usage['ru_utime.tv_usec'] ?? 0 ) / 1000000 );
        $system = (float) ( $usage['ru_stime.tv_sec'] ?? 0 ) + ( (float) ( $usage['ru_stime.tv_usec'] ?? 0 ) / 1000000 );
        return $user + $system;
    }

    private static function memory_limit_bytes() {
        $raw = trim( (string) ini_get( 'memory_limit' ) );
        if ( '' === $raw || '-1' === $raw ) {
            return 0;
        }
        $unit = strtolower( substr( $raw, -1 ) );
        $value = (float) $raw;
        if ( 'g' === $unit ) {
            $value *= 1024 * 1024 * 1024;
        } elseif ( 'm' === $unit ) {
            $value *= 1024 * 1024;
        } elseif ( 'k' === $unit ) {
            $value *= 1024;
        }
        return max( 0, (int) $value );
    }
}
