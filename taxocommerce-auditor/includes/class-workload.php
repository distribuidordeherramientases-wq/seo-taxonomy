<?php
/**
 * Independent workload controller for the five Auditor scopes.
 *
 * Speeds control work per request and spacing between WP-Cron batches. They
 * are not percentages of host CPU usage (unavailable on shared hosting).
 *
 * @package TaxoCommerceAuditor
 */
namespace TaxoCommerce\Auditor;

defined( 'ABSPATH' ) || exit;

final class Workload {
    const OPTION = 'taxocommerce_auditor_workload';
    const DEFAULT_SPEED = 3;

    public static function speed( $scope ) {
        if ( ! Runner::valid_scope( $scope ) ) {
            return self::DEFAULT_SPEED;
        }
        $saved = get_option( self::OPTION, array() );
        $value = is_array( $saved ) && isset( $saved[ $scope ] ) ? $saved[ $scope ] : self::DEFAULT_SPEED;
        return max( 1, min( 5, absint( $value ) ) );
    }

    public static function set_speed( $scope, $value ) {
        if ( ! Runner::valid_scope( $scope ) || ! is_scalar( $value )
            || ! preg_match( '/^[1-5]$/', (string) $value ) ) {
            return false;
        }
        $stored = get_option( self::OPTION, array() );
        $stored = is_array( $stored ) ? $stored : array();
        $stored[ $scope ] = (int) $value;
        update_option( self::OPTION, $stored, false );
        return true;
    }

    public static function batch_size( $scope ) {
        $sizes = array( 1 => 5, 2 => 10, 3 => 20, 4 => 30, 5 => 40 );
        return $sizes[ self::speed( $scope ) ];
    }

    public static function delay_seconds( $scope ) {
        $delays = array( 1 => 90, 2 => 60, 3 => 35, 4 => 20, 5 => 12 );
        return $delays[ self::speed( $scope ) ];
    }

    public static function budget_seconds( $scope ) {
        $budgets = array( 1 => 2, 2 => 3, 3 => 5, 4 => 8, 5 => 12 );
        return $budgets[ self::speed( $scope ) ];
    }

    public static function label( $speed ) {
        $labels = array(
            1 => __( 'Suave', 'taxocommerce-auditor' ),
            2 => __( 'Baja', 'taxocommerce-auditor' ),
            3 => __( 'Normal', 'taxocommerce-auditor' ),
            4 => __( 'Alta', 'taxocommerce-auditor' ),
            5 => __( 'Máxima', 'taxocommerce-auditor' ),
        );
        return $labels[ max( 1, min( 5, absint( $speed ) ) ) ];
    }
}
