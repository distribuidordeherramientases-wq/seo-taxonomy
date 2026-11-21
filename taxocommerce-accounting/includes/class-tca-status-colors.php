<?php
/**
 * Read-only traffic-light presentation for the Accounting register.
 * Green means validated and linked to the full recorded payment, NOT
 * independently reconciled with an external bank statement.
 *
 * @package TaxoCommerceAccounting
 */
defined( 'ABSPATH' ) || exit;

final class TCA_Status_Colors {
    public static function paid_by_entry_for_year( $year ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current amounts from owned invoice-to-bank links; one aggregate query instead of one per invoice.
        $results = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT e.id AS entry_id, COALESCE(SUM(m.amount),0) AS paid FROM %i AS e LEFT JOIN %i AS s ON s.entry_id = e.id LEFT JOIN %i AS m ON m.id = s.movement_id WHERE e.fiscal_date BETWEEN %s AND %s GROUP BY e.id',
            tca_table(), TCA_Settlements::table(), TCA_Treasury::movements_table(),
            sprintf( '%04d-01-01', $year ), sprintf( '%04d-12-31', $year )
        ) );
        $totals = array();
        foreach ( $results as $result ) {
            $totals[ (int) $result->entry_id ] = (float) $result->paid;
        }
        return $totals;
    }

    /** Only a proven inconsistency is red; a missing linked payment is pending. */
    public static function entry( $entry, $paid, $inspection = null ) {
        $inspection = null === $inspection ? TCA_Review_Navigation::inspect( $entry ) : $inspection;
        $total = abs( (float) $entry->amount_total );
        if ( $inspection['error'] || (float) $paid > $total + 0.02 ) {
            return 'error';
        }
        // A validated record with the explicit [scope=review] note is not closed.
        // Never interpret arbitrary free-text notes as proof of an error.
        $explicit_review = isset( $entry->notes ) &&
            false !== strpos( (string) $entry->notes, '[scope=review]' );
        if ( $inspection['pending'] || $inspection['incident'] || $explicit_review ||
            (float) $paid + 0.02 < $total ) {
            return 'pending';
        }
        return 'closed';
    }

    public static function aggregate( $states ) {
        if ( ! $states ) {
            return 'neutral';
        }
        if ( in_array( 'error', $states, true ) ) {
            return 'error';
        }
        if ( in_array( 'pending', $states, true ) ) {
            return 'pending';
        }
        return 'closed';
    }

    public static function label( $state ) {
        switch ( $state ) {
            case 'error':
                return __( 'Error detected', 'taxocommerce-accounting' );
            case 'pending':
                return __( 'Pending', 'taxocommerce-accounting' );
            case 'closed':
                return __( 'Validated and linked to payment', 'taxocommerce-accounting' );
            default:
                return __( 'No records', 'taxocommerce-accounting' );
        }
    }

    public static function tabs( $rows, $paid_by_id, $truncated = false ) {
        $groups = array(
            'sales' => array(), 'received' => array(), 'expenses' => array(),
            'assets' => array(), 'intra' => array(),
        );
        foreach ( $rows as $entry ) {
            $tab = TCA_Corrections::tab_for( (string) $entry->kind );
            if ( isset( $groups[ $tab ] ) ) {
                $groups[ $tab ][] = self::entry(
                    $entry, $paid_by_id[ (int) $entry->id ] ?? 0.0
                );
            }
        }
        $result = array();
        foreach ( $groups as $tab => $states ) {
            $result[ $tab ] = self::aggregate( $states );
            // A limited yearly read cannot prove that the entire tab is closed.
            if ( $truncated && 'closed' === $result[ $tab ] ) {
                $result[ $tab ] = 'pending';
            }
        }
        return $result;
    }

    public static function loan( $loan ) {
        $remaining = (float) $loan->remaining;
        $principal = (float) $loan->principal;
        if ( $remaining < -0.02 || $remaining > $principal + 0.02 ) {
            return 'error';
        }
        return $remaining <= 0.02 ? 'closed' : 'pending';
    }

    public static function treasury_for_year( $year, $entry_states ) {
        global $wpdb;
        $states = array_values( array_filter( $entry_states, static function ( $state ) {
            return 'neutral' !== $state;
        } ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live outstanding debts determine whether treasury is settled.
        $loans = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT principal, remaining FROM %i WHERE start_date <= %s',
            $wpdb->prefix . 'tca_treasury_loans', sprintf( '%04d-12-31', $year )
        ) );
        foreach ( $loans as $loan ) {
            $states[] = self::loan( $loan );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Manual entries not linked to an invoice or loan cannot be marked fully reconciled.
        $cash = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN m.direction IN ('in','out') AND s.id IS NULL AND lp.id IS NULL AND l.id IS NULL THEN 1 ELSE 0 END),0) AS unlinked FROM %i AS m LEFT JOIN %i AS s ON s.movement_id = m.id LEFT JOIN %i AS lp ON lp.movement_id = m.id LEFT JOIN %i AS l ON l.funding_movement_id = m.id WHERE m.posted_on BETWEEN %s AND %s",
            TCA_Treasury::movements_table(), TCA_Settlements::table(),
            $wpdb->prefix . 'tca_treasury_loan_payments', $wpdb->prefix . 'tca_treasury_loans',
            sprintf( '%04d-01-01', $year ), sprintf( '%04d-12-31', $year )
        ) );
        if ( $cash && (int) $cash->total > 0 ) {
            $states[] = (int) $cash->unlinked > 0 ? 'pending' : 'closed';
        }
        return self::aggregate( $states );
    }
}
