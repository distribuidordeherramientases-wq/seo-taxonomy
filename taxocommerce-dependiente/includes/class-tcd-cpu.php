<?php
/**
 * Cooperative CPU throttle for the standalone Dependiente Academy.
 *
 * /proc/stat measures the whole Linux host, not CloudLinux/cPanel account
 * quota. An authenticated hosting-specific integration may provide its own
 * 0..100 account percentage through taxocommerce_dependiente_cpu_percent.
 */
defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- TCD_ es el prefijo histórico de Dependiente; renombrar rompería integraciones y referencias existentes.
final class TCD_CPU {
    const LIMIT_OPTION = 'tcd_academy_cpu_limit';
    const SAMPLE_OPTION = 'tcd_academy_cpu_sample';
    const DEFAULT_LIMIT = 50;

    public static function limit() {
        $value = get_option( self::LIMIT_OPTION, self::DEFAULT_LIMIT );
        return max( 1, min( 100, absint( $value ) ) );
    }

    public static function set_limit( $raw ) {
        if ( ! is_scalar( $raw ) || ! preg_match( '/^\d{1,3}$/', trim( (string) $raw ) ) ) {
            return new WP_Error( 'tcd_cpu_invalid_limit', 'Introduce un porcentaje de CPU entero entre 1 y 100.' );
        }
        $value = (int) $raw;
        if ( $value < 1 || $value > 100 ) {
            return new WP_Error( 'tcd_cpu_invalid_limit', 'El umbral de CPU debe estar entre 1 y 100 %.' );
        }
        update_option( self::LIMIT_OPTION, $value, false );
        return $value;
    }

    /**
     * Returns available=true only for a real two-sample utilization delta or a
     * hosting-provided utilization metric. Never interprets load average as %.
     */
    public static function measure() {
        $provided = apply_filters( 'taxocommerce_dependiente_cpu_percent', null );
        if ( is_numeric( $provided ) && (float) $provided >= 0 && (float) $provided <= 100 ) {
            return array(
                'available' => true,
                'percent' => round( (float) $provided, 1 ),
                'source' => 'hosting_filter',
                'reason' => '',
                'at' => gmdate( 'c' ),
            );
        }

        $sample = self::proc_stat_sample();
        if ( null === $sample ) {
            return array(
                'available' => false, 'percent' => null,
                'source' => 'unavailable',
                'reason' => 'No se puede leer /proc/stat. Es necesario un medidor compatible con el hosting.',
                'at' => gmdate( 'c' ),
            );
        }
        $before = get_option( self::SAMPLE_OPTION, array() );
        update_option( self::SAMPLE_OPTION, $sample, false );
        $elapsed = $sample['time'] - (int) ( $before['time'] ?? 0 );
        if ( $elapsed < 1 || $elapsed > 300 || ! isset( $before['total'], $before['idle'] ) ) {
            return array(
                'available' => false, 'percent' => null,
                'source' => 'proc_stat_host',
                'reason' => 'Preparando la primera muestra de CPU; se necesita una segunda lectura.',
                'at' => gmdate( 'c' ),
                'warmup' => true,
            );
        }
        $delta_total = $sample['total'] - (float) $before['total'];
        $delta_idle = $sample['idle'] - (float) $before['idle'];
        if ( $delta_total <= 0 || $delta_idle < 0 || $delta_idle > $delta_total ) {
            return array(
                'available' => false, 'percent' => null,
                'source' => 'proc_stat_host',
                'reason' => 'Los contadores de CPU no proporcionan una muestra válida.',
                'at' => gmdate( 'c' ),
            );
        }
        return array(
            'available' => true,
            'percent' => round( 100 * ( $delta_total - $delta_idle ) / $delta_total, 1 ),
            'source' => 'proc_stat_host',
            'reason' => '',
            'at' => gmdate( 'c' ),
        );
    }

    /**
     * Read the first aggregate /proc/stat line. The first eight counters are
     * mutually exclusive; guest counters are already included in user/nice.
     */
    private static function proc_stat_sample() {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Read-only trusted /proc/stat OS pseudo-file, no user-controlled path, closed immediately.
        $handle = @fopen( '/proc/stat', 'r' );
        if ( false === $handle ) {
            return null;
        }
        $line = @fgets( $handle, 512 );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Read-only trusted /proc/stat OS pseudo-file, no user-controlled path, closed immediately.
        @fclose( $handle );
        if ( ! is_string( $line ) || ! preg_match( '/^cpu\s+((?:\d+\s+){7}\d+)/', trim( $line ), $matches ) ) {
            return null;
        }
        $parts = preg_split( '/\s+/', trim( $matches[1] ) );
        if ( count( $parts ) !== 8 ) {
            return null;
        }
        $counters = array_map( 'floatval', $parts );
        return array(
            'total' => array_sum( $counters ),
            'idle' => $counters[3] + $counters[4],
            'time' => time(),
        );
    }
}
