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

    /** Import speeds are 10x the former values. Exports are unchanged. */
    const IMPORT_BATCH_PROFILE = 2;

    public static function size_limits( $kind ) {
        $kind = sanitize_key( $kind );
        $priority = self::priority( $kind );
        $initial = array( 1 => 3, 2 => 6, 3 => 10, 4 => 20, 5 => 35 );
        $maximum = array( 1 => 10, 2 => 30, 3 => 75, 4 => 125, 5 => 200 );
        $scale = 'import' === $kind ? 10 : 1;
        return array(
            'initial' => $initial[ $priority ] * $scale,
            'maximum' => $maximum[ $priority ] * $scale,
        );
    }

    public static function batch_size( $kind ) {
        $kind = sanitize_key( $kind );
        $priority = self::priority( $kind );
        $limits = self::size_limits( $kind );
        $last = self::last_sample( $kind );
        $has_sample = isset( $last['next_batch'] ) && absint( $last['priority'] ?? 0 ) === $priority;
        $current = $has_sample ? absint( $last['next_batch'] ) : $limits['initial'];
        // Upgrade a live import without losing progress or retaining its
        // obsolete 6-row window. E.g. 6 -> 60, then 60 -> 120 when fast.
        if ( $has_sample && 'import' === $kind
            && absint( $last['batch_profile'] ?? 1 ) < self::IMPORT_BATCH_PROFILE ) {
            $current *= 10;
        }
        return max( 1, min( $limits['maximum'], $current ) );
    }

    /** Percentage of the PHP worker's memory limit; null when unlimited. */
    public static function memory_percent() {
        $raw = strtolower( trim( (string) ini_get( 'memory_limit' ) ) );
        $limit = (float) $raw;
        if ( 'g' === substr( $raw, -1 ) ) {
            $limit *= 1024 * 1024 * 1024;
        } elseif ( 'm' === substr( $raw, -1 ) ) {
            $limit *= 1024 * 1024;
        } elseif ( 'k' === substr( $raw, -1 ) ) {
            $limit *= 1024;
        }
        return $limit > 0 ? 100 * memory_get_peak_usage( true ) / $limit : null;
    }

    /**
     * Deterministic 10x-batch controller, testable without a WordPress host.
     *
     * @return array Next row target and an explanatory reason.
     */
    public static function import_adjustment( $current, $maximum, $items, $elapsed, $memory, $budget, $cpu_percent = null ) {
        $current = max( 1, absint( $current ) );
        $maximum = max( $current, absint( $maximum ) );
        $items = absint( $items );
        $elapsed = max( 0, (float) $elapsed );
        $budget = max( 2, (float) $budget );
        if ( 0 === $items ) {
            return array( $current, 'sin filas: sin modificar velocidad' );
        }
        if ( null !== $memory && $memory >= 80 ) {
            return array( max( 1, (int) floor( $current / 2 ) ), 'reducido por memoria alta' );
        }
        // PHP CPU is not server-wide CPU. Only apply CPU back-pressure when
        // the process is itself saturated near the wall-clock deadline.
        if ( null !== $cpu_percent && $cpu_percent >= 95 && $elapsed >= 0.85 * $budget ) {
            return array( max( 1, (int) floor( $current * 0.75 ) ), 'reducido por CPU local y tiempo elevados' );
        }
        if ( $elapsed >= $budget * 0.96 ) {
            // Estimate a sustainable batch from observed rows/second.
            return array(
                max( 1, min( $current, (int) floor( $items * 0.8 ) ) ),
                'ajustado al tiempo por fila'
            );
        }
        if ( $items >= $current && $elapsed < $budget * 0.90
            && ( null === $memory || $memory < 72 ) ) {
            $next = min( $maximum, $current * 2 );
            return array(
                $next,
                $next > $current ? 'duplicado por tiempo/memoria disponibles' : 'límite de velocidad alcanzado'
            );
        }
        return array( $current, $items < $current
            ? 'bloque parcial: sin aumentar artificialmente' : 'estabilizado' );
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
        $memory = self::memory_percent();
        $current = self::batch_size( $kind );
        $maximum = self::size_limits( $kind )['maximum'];
        $next = $current;
        $reason = 'estabilizado';
        $budget = max( 2.0, (float) ( $start['budget'] ?? ( 'import' === $kind ? 25 : 8 ) ) );
        $items = absint( $items );

        if ( 'import' === $kind ) {
            list( $next, $reason ) = self::import_adjustment(
                $current, $maximum, $items, $elapsed, $memory, $budget, $cpu_percent
            );
        } else {
            // No changes to the exporter regulator.
            if ( $elapsed >= 8 || ( null !== $memory && $memory >= 75 ) ) {
                $next = max( 1, (int) floor( $current / 2 ) );
                $reason = 'reducido por tiempo/memoria';
            } elseif ( $elapsed < 4 && ( null === $memory || $memory < 65 ) ) {
                $next = min( $maximum, $current + max( 1, (int) ceil( $current / 3 ) ) );
                $reason = 'ampliado por margen de tiempo/memoria';
            }
        }

        $sample = array(
            'priority' => $priority, 'items' => $items, 'batch' => $current,
            'next_batch' => $next, 'elapsed' => round( $elapsed, 3 ),
            'batch_profile' => 'import' === $kind ? self::IMPORT_BATCH_PROFILE : 1,
            'budget_seconds' => round( $budget, 2 ),
            'cpu_percent' => null === $cpu_percent ? null : round( $cpu_percent, 1 ),
            'memory_percent' => null === $memory ? null : round( $memory, 1 ),
            'memory_peak' => memory_get_peak_usage( true ), 'at' => gmdate( 'c' ),
            'reason' => $reason,
            'rows_per_second' => round( $items / $elapsed, 2 ),
            'average_row_seconds' => $items ? round( $elapsed / $items, 3 ) : 0,
            'row_save_seconds' => round( (float) ( $start['row_save_seconds'] ?? 0 ), 3 ),
            'slowest_row_seconds' => round( (float) ( $start['slowest_row_seconds'] ?? 0 ), 3 ),
            'db_queries' => absint( $start['db_queries'] ?? 0 ),
            'db_queries_per_row' => $items ? round( absint( $start['db_queries'] ?? 0 ) / $items, 2 ) : 0,
        );
        // Phase breakdown helps distinguish expensive WooCommerce hooks and
        // SQL from queue pacing. All measurements are for the PHP request.
        foreach ( array( 'lookup_seconds', 'lookup_sql', 'save_seconds', 'save_sql',
            'meta_seconds', 'meta_sql', 'skipped_unchanged_save', 'saved' ) as $field ) {
            $value = (float) ( $start[ 'product_' . $field ] ?? 0 );
            $sample[ 'product_' . $field ] = strpos( $field, 'seconds' ) !== false
                ? round( $value, 3 ) : absint( $value );
        }
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
