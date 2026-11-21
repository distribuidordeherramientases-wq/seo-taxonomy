<?php
/**
 * Simple, independent cash/bank and loan register for Accounting.
 *
 * Actual balances are based solely on opening funds and confirmed
 * cash movements. Unpaid invoices and projected loan instalments never imply cash.
 *
 * @package TaxoCommerceAccounting
 */
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-tca-loan-schedule.php';

final class TCA_Treasury {
    private static function table( $suffix ) {
        global $wpdb;
        return $wpdb->prefix . 'tca_treasury_' . $suffix;
    }

    /** Owned tables shared with the invoice-settlement component. */
    public static function accounts_table() {
        return self::table( 'accounts' );
    }

    public static function movements_table() {
        return self::table( 'movements' );
    }

    public static function install_schema() {
        global $wpdb;
        $collation = $wpdb->get_charset_collate();
        $accounts = esc_sql( self::table( 'accounts' ) );
        $movements = esc_sql( self::table( 'movements' ) );
        $loans = esc_sql( self::table( 'loans' ) );
        dbDelta( "CREATE TABLE {$accounts} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            kind varchar(8) NOT NULL,
            name varchar(191) NOT NULL,
            opened_on date NOT NULL,
            opening_amount decimal(18,2) NOT NULL DEFAULT 0.00,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY kind (kind)
        ) {$collation};" );
        dbDelta( "CREATE TABLE {$movements} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            account_id bigint(20) unsigned NOT NULL,
            posted_on date NOT NULL,
            direction varchar(16) NOT NULL,
            amount decimal(18,2) NOT NULL,
            reference varchar(191) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY account_date (account_id,posted_on),
            KEY posted_on (posted_on)
        ) {$collation};" );
        dbDelta( "CREATE TABLE {$loans} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            lender varchar(191) NOT NULL DEFAULT '',
            principal decimal(18,2) NOT NULL,
            remaining decimal(18,2) NOT NULL,
            monthly_payment decimal(18,2) NOT NULL,
            annual_rate decimal(7,4) NOT NULL DEFAULT 0,
            term_months smallint(5) unsigned NOT NULL DEFAULT 0,
            upfront_payment decimal(18,2) NOT NULL DEFAULT 0.00,
            balloon_payment decimal(18,2) NOT NULL DEFAULT 0.00,
            funding_movement_id bigint(20) unsigned NOT NULL DEFAULT 0,
            request_key varchar(64) NULL DEFAULT NULL,
            start_date date NOT NULL,
            next_due_date date NOT NULL,
            first_due_date date NOT NULL DEFAULT '1970-01-01',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY request_key (request_key),
            KEY next_due_date (next_due_date)
        ) {$collation};" );
        $payments = esc_sql( self::table( 'loan_payments' ) );
        dbDelta( "CREATE TABLE {$payments} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            loan_id bigint(20) unsigned NOT NULL,
            instalment_number smallint(5) unsigned NOT NULL,
            movement_id bigint(20) unsigned NOT NULL,
            principal_amount decimal(18,2) NOT NULL,
            interest_amount decimal(18,2) NOT NULL,
            paid_amount decimal(18,2) NOT NULL,
            paid_on date NOT NULL,
            request_key varchar(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY loan_period (loan_id,instalment_number),
            UNIQUE KEY movement_id (movement_id),
            UNIQUE KEY request_key (request_key),
            KEY loan_id (loan_id)
        ) {$collation};" );
    }

    private static function posted( $key ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This helper is called only after verifying the action nonce in handle().
        if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
            return '';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The action nonce was checked in handle().
        return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
    }

    private static function money( $value, $allow_negative = false ) {
        $normalized = str_replace( ',', '.', trim( (string) $value ) );
        $number = tca_decimal( $normalized );
        if ( null === $number || abs( $number ) > 999999999999.99 ||
            ( ! $allow_negative && $number < 0 ) ) {
            return null;
        }
        return $number;
    }

    private static function date( $date, $allow_future = false ) {
        return tca_valid_date( $date ) &&
            ( $allow_future || $date <= current_time( 'Y-m-d' ) );
    }

    private static function redirect( $notice ) {
        wp_safe_redirect( tca_admin_url( 'treasury', array( 'notice' => 'treasury_' . $notice ) ) );
        exit;
    }

    private static function get_account( $account_id ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own cash account table, latest data required.
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d LIMIT 1',
            self::table( 'accounts' ), absint( $account_id )
        ) );
    }

    private static function insert_movement( $account_id, $date, $direction, $amount, $reference ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Ledger writes to an owned table.
        return $wpdb->insert( self::table( 'movements' ), array(
            'account_id' => absint( $account_id ),
            'posted_on' => $date,
            'direction' => $direction,
            'amount' => $amount,
            'reference' => $reference,
            'created_at' => current_time( 'mysql' ),
        ), array( '%d', '%s', '%s', '%f', '%s', '%s' ) );
    }

    public static function handle() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'taxocommerce-accounting' ) );
        }
        check_admin_referer( 'tca_treasury_action' );
        $operation = sanitize_key( self::posted( 'treasury_action' ) );
        global $wpdb;
        if ( 'account' === $operation ) {
            $kind = sanitize_key( self::posted( 'kind' ) );
            $name = self::posted( 'name' );
            $date = self::posted( 'opened_on' );
            $opening = self::money( self::posted( 'opening_amount' ), true );
            if ( ! in_array( $kind, array( 'cash', 'bank' ), true ) ||
                '' === $name || ! self::date( $date ) || null === $opening ) {
                self::redirect( 'invalid' );
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own account table.
            $ok = $wpdb->insert( self::table( 'accounts' ), array(
                'kind' => $kind, 'name' => $name, 'opened_on' => $date,
                'opening_amount' => $opening, 'created_at' => current_time( 'mysql' ),
            ), array( '%s', '%s', '%s', '%f', '%s' ) );
            self::redirect( $ok ? 'saved' : 'error' );
        }
        if ( 'edit_account' === $operation ) {
            $account = self::get_account( self::posted( 'account_id' ) );
            $name = self::posted( 'name' );
            if ( ! $account || '' === $name ) self::redirect( 'invalid' );
            $ok = $wpdb->update( self::table( 'accounts' ),
                array( 'name' => $name ), array( 'id' => $account->id ),
                array( '%s' ), array( '%d' ) );
            self::redirect( false !== $ok ? 'saved' : 'error' );
        }
        if ( 'edit_movement' === $operation ) {
            $id = absint( self::posted( 'movement_id' ) );
            $account = self::get_account( self::posted( 'account_id' ) );
            $date = self::posted( 'posted_on' );
            $amount = self::money( self::posted( 'amount' ) );
            $direction = sanitize_key( self::posted( 'direction' ) );
            $movement = $wpdb->get_row( $wpdb->prepare(
                'SELECT * FROM %i WHERE id = %d', self::table( 'movements' ), $id
            ) );
            if ( ! $movement || ! in_array( $movement->direction, array( 'in', 'out' ), true )
                || ! in_array( $direction, array( 'in', 'out' ), true ) || ! $account
                || ! self::date( $date ) || $date < $account->opened_on
                || null === $amount || $amount <= 0 ) self::redirect( 'invalid' );
            // Do not edit invoice settlements, loan payments or paired transfers.
            $settled = $wpdb->get_var( $wpdb->prepare(
                'SELECT movement_id FROM %i WHERE movement_id = %d',
                $wpdb->prefix . 'tca_treasury_settlements', $id
            ) );
            $loan = $wpdb->get_var( $wpdb->prepare(
                'SELECT movement_id FROM %i WHERE movement_id = %d',
                self::table( 'loan_payments' ), $id
            ) );
            if ( $settled || $loan ) self::redirect( 'invalid' );
            $ok = $wpdb->update( self::table( 'movements' ),
                array( 'account_id' => $account->id, 'posted_on' => $date,
                    'direction' => $direction, 'amount' => $amount, 'reference' => self::posted( 'reference' ) ),
                array( 'id' => $id ), array( '%d', '%s', '%s', '%f', '%s' ), array( '%d' ) );
            self::redirect( false !== $ok ? 'saved' : 'error' );
        }
        if ( 'opening_receipt' === $operation ) {
            $account_id = absint( self::posted( 'account_id' ) );
            $date = self::posted( 'posted_on' );
            $reference = self::posted( 'reference' );
            if ( '1' !== self::posted( 'opening_verified' ) || ! $account_id ||
                ! self::date( $date ) || '' === $reference ) {
                self::redirect( 'invalid' );
            }
            // Move money from the opening-balance column to a dated receipt.
            // Exactly one cash/bank receipt is created; available funds do not change.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own account and ledger need one transaction.
            $wpdb->query( 'START TRANSACTION' );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock account so repeated requests cannot duplicate the opening contribution.
            $account = $wpdb->get_row( $wpdb->prepare(
                'SELECT * FROM %i WHERE id = %d FOR UPDATE', self::table( 'accounts' ), $account_id
            ) );
            if ( ! $account || ! in_array( $account->kind, array( 'cash', 'bank' ), true ) ||
                $date < $account->opened_on || (float) $account->opening_amount <= 0 ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reject duplicate conversion.
                $wpdb->query( 'ROLLBACK' );
                self::redirect( 'invalid' );
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own opening balance conversion under lock.
            $changed = $wpdb->update( self::table( 'accounts' ),
                array( 'opening_amount' => 0 ),
                array( 'id' => $account_id, 'opening_amount' => $account->opening_amount ),
                array( '%f' ), array( '%d', '%f' )
            );
            $movement = 1 === $changed && self::insert_movement( $account_id, $date, 'in',
                (float) $account->opening_amount, $reference );
            if ( ! $movement ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Never remove money without a corresponding receipt.
                $wpdb->query( 'ROLLBACK' );
                self::redirect( 'error' );
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Commit opening balance conversion once.
            $wpdb->query( 'COMMIT' );
            self::redirect( 'saved' );
        }
        if ( 'movement' === $operation || 'transfer' === $operation ) {
            $date = self::posted( 'posted_on' );
            $amount = self::money( self::posted( 'amount' ) );
            $from = self::get_account( self::posted( 'account_id' ) );
            $reference = self::posted( 'reference' );
            if ( ! self::date( $date ) || null === $amount || $amount <= 0 ||
                ! $from || $date < $from->opened_on ) {
                self::redirect( 'invalid' );
            }
            if ( 'movement' === $operation ) {
                $direction = sanitize_key( self::posted( 'direction' ) );
                if ( ! in_array( $direction, array( 'in', 'out' ), true ) ) {
                    self::redirect( 'invalid' );
                }
                self::redirect( self::insert_movement( $from->id, $date, $direction, $amount, $reference ) ? 'saved' : 'error' );
            }
            $to = self::get_account( self::posted( 'destination_id' ) );
            if ( ! $to || $from->id === $to->id || $date < $to->opened_on ) {
                self::redirect( 'invalid' );
            }
            // A transfer is atomic: never post money out without posting it in.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own ledger transfer transaction.
            $wpdb->query( 'START TRANSACTION' );
            $out = self::insert_movement( $from->id, $date, 'transfer_out', $amount, $reference );
            $in = $out ? self::insert_movement( $to->id, $date, 'transfer_in', $amount, $reference ) : false;
            if ( ! $out || ! $in ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Roll back incomplete ledger transfer.
                $wpdb->query( 'ROLLBACK' );
                self::redirect( 'error' );
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Commit balanced transfer.
            $wpdb->query( 'COMMIT' );
            self::redirect( 'saved' );
        }
        if ( 'loan' === $operation ) {
            $name = self::posted( 'name' );
            $lender = self::posted( 'lender' );
            $start = self::posted( 'start_date' );
            $due = self::posted( 'next_due_date' );
            $principal = self::money( self::posted( 'principal' ) );
            $months = absint( self::posted( 'term_months' ) );
            $rate = self::money( self::posted( 'annual_rate' ) );
            $upfront = self::money( self::posted( 'upfront_payment' ) );
            $balloon = self::money( self::posted( 'balloon_payment' ) );
            $monthly = self::money( self::posted( 'monthly_payment' ) );
            $request_key = self::posted( 'loan_request_key' );
            $funding_received = '1' === self::posted( 'funding_received' );
            $funding_account = $funding_received ? self::get_account( self::posted( 'funding_account_id' ) ) : false;
            if ( '' === $name || ! self::date( $start ) || ! self::date( $due, true ) ||
                $due < $start || null === $principal || $principal <= 0 ||
                ! preg_match( '/^[a-f0-9-]{36}$/', $request_key ) ||
                null === $rate || $rate < 0 || $rate > 100 ||
                null === $upfront || null === $balloon || $balloon > $principal ||
                $months > 360 ||
                ( $funding_received && ( ! $funding_account ||
                    'bank' !== $funding_account->kind || $start < $funding_account->opened_on ) ) ) {
                self::redirect( 'invalid' );
            }
            if ( $months > 0 ) {
                $schedule = TCA_Loan_Schedule::calculate( $principal, $rate, $months, $due, $balloon );
                if ( ! $schedule ) {
                    self::redirect( 'invalid' );
                }
                // Calculated loans derive the instalment from TIN and term.
                $monthly = (float) $schedule[0]['payment'];
            } elseif ( null === $monthly || $monthly <= 0 || $rate > 0 || $balloon > 0 ) {
                // Pre-existing manual-amount mode remains available without a term.
                self::redirect( 'invalid' );
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Replayed request must never generate a second loan or bank receipt.
            $existing_loan = $wpdb->get_var( $wpdb->prepare(
                'SELECT id FROM %i WHERE request_key = %s LIMIT 1', self::table( 'loans' ), $request_key
            ) );
            if ( $existing_loan ) {
                self::redirect( 'saved' );
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own loan/funding transaction.
            $wpdb->query( 'START TRANSACTION' );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own loan register.
            $saved = $wpdb->insert( self::table( 'loans' ), array(
                'name' => $name, 'lender' => $lender, 'principal' => $principal,
                'remaining' => $principal, 'monthly_payment' => $monthly,
                'annual_rate' => $rate, 'term_months' => $months, 'request_key' => $request_key,
                'upfront_payment' => $upfront, 'balloon_payment' => $balloon,
                'start_date' => $start, 'next_due_date' => $due, 'first_due_date' => $due,
                'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
            ), array( '%s', '%s', '%f', '%f', '%f', '%f', '%d', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s' ) );
            $loan_id = $saved ? (int) $wpdb->insert_id : 0;
            if ( $saved && $funding_received ) {
                // The purchase invoice/payment must be recorded separately.
                $saved = self::insert_movement( $funding_account->id, $start, 'in', $principal, 'Loan #' . $loan_id . ': ' . $name );
                $movement_id = $saved ? (int) $wpdb->insert_id : 0;
                if ( $saved ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own loan funding link.
                    $saved = 1 === $wpdb->update( self::table( 'loans' ), array(
                        'funding_movement_id' => $movement_id,
                    ), array( 'id' => $loan_id ), array( '%d' ), array( '%d' ) );
                }
            }
            if ( $saved ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic loan/funding write.
                $wpdb->query( 'COMMIT' );
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic loan/funding write.
                $wpdb->query( 'ROLLBACK' );
            }
            self::redirect( $saved ? 'saved' : 'error' );
        }
        if ( 'loan_payment' === $operation ) {
            if ( '1' !== self::posted( 'loan_payment_confirmed' ) ) {
                self::redirect( 'invalid' );
            }
            $id = absint( self::posted( 'loan_id' ) );
            $date = self::posted( 'posted_on' );
            $key = self::posted( 'request_key' );
            $account = self::get_account( self::posted( 'account_id' ) );
            if ( ! $id || ! self::date( $date ) || ! $account ||
                ! in_array( $account->kind, array( 'bank', 'cash' ), true ) ||
                ! preg_match( '/^[a-f0-9-]{36}$/', $key ) ) {
                self::redirect( 'invalid' );
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own instalment transaction.
            $wpdb->query( 'START TRANSACTION' );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock the loan while reconciling an instalment.
            $loan = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', self::table( 'loans' ), $id ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Idempotent request key.
            $previous = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE request_key = %s', self::table( 'loan_payments' ), $key ) );
            if ( $previous ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Idempotent replay.
                $wpdb->query( 'COMMIT' );
                self::redirect( 'saved' );
            }
            if ( ! $loan || (int) $loan->term_months < 1 || $date < $loan->start_date || $date < $account->opened_on ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reject invalid payment.
                $wpdb->query( 'ROLLBACK' );
                self::redirect( 'invalid' );
            }
            $schedule = TCA_Loan_Schedule::calculate( $loan->principal, $loan->annual_rate,
                $loan->term_months, $loan->first_due_date, $loan->balloon_payment );
            // A managed loan stores the original first due date independently from
            // the updated next due date, so later instalments remain deterministic.
            if ( ! $schedule ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- No cash posted.
                $wpdb->query( 'ROLLBACK' );
                self::redirect( 'invalid' );
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Latest settled instalment.
            $last = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COALESCE(MAX(instalment_number),0) FROM %i WHERE loan_id = %d', self::table( 'loan_payments' ), $id
            ) );
            if ( ! isset( $schedule[ $last ] ) ||
                abs( (float) $loan->remaining - ( $last ? $schedule[ $last - 1 ]['remaining'] : (float) $loan->principal ) ) > 0.02 ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prevent inconsistent repayment.
                $wpdb->query( 'ROLLBACK' );
                self::redirect( 'invalid' );
            }
            $instalment = $schedule[ $last ];
            $saved = self::insert_movement( $account->id, $date, 'out', $instalment['payment'],
                'Loan #' . $id . ' instalment ' . $instalment['number'] );
            $movement_id = $saved ? (int) $wpdb->insert_id : 0;
            if ( $saved ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own repayment audit linked to one movement.
                $saved = $wpdb->insert( self::table( 'loan_payments' ), array(
                    'loan_id' => $id, 'instalment_number' => $instalment['number'],
                    'movement_id' => $movement_id, 'principal_amount' => $instalment['principal'],
                    'interest_amount' => $instalment['interest'], 'paid_amount' => $instalment['payment'],
                    'paid_on' => $date, 'request_key' => $key, 'created_at' => current_time( 'mysql' ),
                ), array( '%d', '%d', '%d', '%f', '%f', '%f', '%s', '%s', '%s' ) );
            }
            if ( $saved ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Principal moves only when the actual instalment is paid.
                $saved = 1 === $wpdb->update( self::table( 'loans' ), array(
                    'remaining' => $instalment['remaining'],
                    'next_due_date' => isset( $schedule[ $last + 1 ] ) ? $schedule[ $last + 1 ]['due_date'] : $instalment['due_date'],
                    'updated_at' => current_time( 'mysql' ),
                ), array( 'id' => $id ), array( '%f', '%s', '%s' ), array( '%d' ) );
            }
            if ( $saved ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- No half-paid instalments.
                $wpdb->query( 'COMMIT' );
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- No half-paid instalments.
                $wpdb->query( 'ROLLBACK' );
            }
            self::redirect( $saved ? 'saved' : 'error' );
        }
        if ( 'loan_update' === $operation ) {
            $id = absint( self::posted( 'loan_id' ) );
            $remaining = self::money( self::posted( 'remaining' ) );
            $monthly = self::money( self::posted( 'monthly_payment' ) );
            $due = self::posted( 'next_due_date' );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own loan table.
            $loan = $wpdb->get_row( $wpdb->prepare(
                'SELECT * FROM %i WHERE id = %d LIMIT 1', self::table( 'loans' ), $id
            ) );
            if ( ! $loan || (int) $loan->term_months > 0 || null === $remaining || $remaining > (float) $loan->principal ||
                null === $monthly || $monthly <= 0 || ! self::date( $due, true ) ) {
                self::redirect( 'invalid' );
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Only own loan status.
            $ok = $wpdb->update( self::table( 'loans' ), array(
                'remaining' => $remaining, 'monthly_payment' => $monthly,
                'next_due_date' => $due, 'updated_at' => current_time( 'mysql' ),
            ), array( 'id' => $id ), array( '%f', '%f', '%s', '%s' ), array( '%d' ) );
            self::redirect( false !== $ok ? 'saved' : 'error' );
        }
        self::redirect( 'invalid' );
    }

    /**
     * Pure calculations for monthly bank/cash reports; testable without WP.
     * $movements are aggregated by account, month and direction.
     */
    public static function calculate( $accounts, $movements, $year, $today ) {
        $accounts_by_id = array();
        foreach ( $accounts as $account ) {
            $accounts_by_id[ (int) $account->id ] = $account;
        }
        $current = array( 'cash' => 0.0, 'bank' => 0.0 );
        $months = array();
        for ( $month = 1; $month <= 12; ++$month ) {
            $key = sprintf( '%04d-%02d', $year, $month );
            $asof = min( $today, gmdate( 'Y-m-t', strtotime( $key . '-01' ) ) );
            $row = array(
                'month' => $key, 'cash_in' => 0.0, 'cash_out' => 0.0,
                'bank_in' => 0.0, 'bank_out' => 0.0,
                'cash_balance' => 0.0, 'bank_balance' => 0.0,
            );
            foreach ( $accounts as $account ) {
                if ( $account->opened_on <= $asof ) {
                    $row[ $account->kind . '_balance' ] += (float) $account->opening_amount;
                }
            }
            foreach ( $movements as $movement ) {
                $id = (int) $movement->account_id;
                if ( ! isset( $accounts_by_id[ $id ] ) ) {
                    continue;
                }
                $kind = $accounts_by_id[ $id ]->kind;
                $positive = in_array( $movement->direction, array( 'in', 'transfer_in' ), true );
                $value = (float) $movement->total;
                if ( $movement->month <= $key ) {
                    $row[ $kind . '_balance' ] += $positive ? $value : -$value;
                }
                if ( $movement->month === $key ) {
                    $row[ $kind . ( $positive ? '_in' : '_out' ) ] += $value;
                }
            }
            $months[ $key ] = $row;
        }
        foreach ( $accounts as $account ) {
            if ( $account->opened_on <= $today ) {
                $current[ $account->kind ] += (float) $account->opening_amount;
            }
        }
        foreach ( $movements as $movement ) {
            if ( ! isset( $accounts_by_id[ (int) $movement->account_id ] ) ||
                $movement->month > substr( $today, 0, 7 ) ) {
                continue;
            }
            $kind = $accounts_by_id[ (int) $movement->account_id ]->kind;
            $current[ $kind ] += in_array( $movement->direction, array( 'in', 'transfer_in' ), true )
                ? (float) $movement->total : -(float) $movement->total;
        }
        return array( 'current' => $current, 'months' => $months );
    }

    private static function start_form( $action ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tca_treasury_action' );
        echo '<input type="hidden" name="action" value="tca_treasury_action">';
        echo '<input type="hidden" name="treasury_action" value="' . esc_attr( $action ) . '">';
    }

    private static function account_select( $accounts, $name, $selected = 0 ) {
        echo '<select name="' . esc_attr( $name ) . '" required>';
        foreach ( $accounts as $account ) {
            echo '<option value="' . esc_attr( (string) $account->id ) . '"' . selected( (int) $account->id, $selected, false ) . '>' . esc_html( $account->name ) . ' (' .
                esc_html( 'cash' === $account->kind ? __( 'Cash', 'taxocommerce-accounting' ) : __( 'Bank', 'taxocommerce-accounting' ) ) . ')</option>';
        }
        echo '</select>';
    }

    public static function render( $year ) {
        global $wpdb;
        $today = current_time( 'Y-m-d' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Treasury dashboard needs current owned account balances.
        $accounts = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM %i ORDER BY kind ASC, name ASC, id ASC', self::table( 'accounts' )
        ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Monthly aggregate of the plugin's own movements.
        $movements = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT account_id, DATE_FORMAT(posted_on, %s) AS month, direction, SUM(amount) AS total FROM %i WHERE posted_on <= %s GROUP BY account_id, month, direction ORDER BY month',
            '%Y-%m', self::table( 'movements' ), $today
        ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own loan balance register.
        $loans = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM %i ORDER BY next_due_date ASC, id ASC', self::table( 'loans' )
        ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregated owned loan payments are read-only.
        $loan_payments = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT loan_id, COUNT(*) AS paid_count, COALESCE(SUM(interest_amount),0) AS interest_paid FROM %i GROUP BY loan_id',
            self::table( 'loan_payments' )
        ) );
        $payments_by_loan = array();
        foreach ( $loan_payments as $paid ) {
            $payments_by_loan[ (int) $paid->loan_id ] = $paid;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Recent recorded transfers and cash movements.
        $recent = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM %i ORDER BY posted_on DESC, id DESC LIMIT 50', self::table( 'movements' )
        ) );
        $report = self::calculate( $accounts, $movements, $year, $today );
        $cash = $report['current']['cash'];
        $bank = $report['current']['bank'];
        $debt = 0.0;
        $monthly = 0.0;
        foreach ( $loans as $loan ) {
            $debt += (float) $loan->remaining;
            if ( (float) $loan->remaining > 0 ) {
                $monthly += (float) $loan->monthly_payment;
            }
        }
        echo '<h2>' . esc_html__( 'Treasury: cash, bank and loans', 'taxocommerce-accounting' ) . '</h2>';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result message after a nonce-protected settlement form.
        if ( isset( $_GET['notice'] ) && 'bulk_settlement_result' === sanitize_key( wp_unslash( $_GET['notice'] ) ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- These GET numbers are displayed only, not used for writes.
            $posted = isset( $_GET['posted'] ) ? absint( wp_unslash( $_GET['posted'] ) ) : 0;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only counts.
            $skipped = isset( $_GET['skipped'] ) ? absint( wp_unslash( $_GET['skipped'] ) ) : 0;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only counts.
            $errors = isset( $_GET['errors'] ) ? absint( wp_unslash( $_GET['errors'] ) ) : 0;
            echo '<div class="notice notice-info inline"><p>' . esc_html(
                sprintf(
                    /* translators: 1: payments posted, 2: payments skipped, 3: payments with errors. */
                    __( 'Historical reconciliation: %1$d payments posted, %2$d skipped, %3$d errors. Review the bank balance before posting anything again.', 'taxocommerce-accounting' ),
                    $posted, $skipped, $errors
                )
            ) . '</p></div>';
        }
        echo '<p class="description">' . esc_html__( 'Balances use opening funds and confirmed payments/receipts (including invoice-linked settlements). An opening balance is not a monthly Cash in / Bank in. If it actually represents a contribution received this year, you can convert it to a dated receipt below without changing the balance. Unpaid invoices and future loan instalments never move money.', 'taxocommerce-accounting' ) . '</p>';
        echo '<div class="tca-treasury-kpis">';
        $indicators = array(
            __( 'Cash on hand', 'taxocommerce-accounting' ) => $cash,
            __( 'Bank accounts', 'taxocommerce-accounting' ) => $bank,
            __( 'Total available funds', 'taxocommerce-accounting' ) => $cash + $bank,
            __( 'Loans outstanding (reported)', 'taxocommerce-accounting' ) => $debt,
            __( 'Scheduled monthly loan instalments', 'taxocommerce-accounting' ) => $monthly,
        );
        foreach ( $indicators as $label => $value ) {
            echo '<div class="tca-treasury-kpi"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( tca_money( $value ) ) . '</strong></div>';
        }
        echo '</div>';

        echo '<h3>' . esc_html__( 'Cash and bank accounts', 'taxocommerce-accounting' ) . '</h3>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Account', 'taxocommerce-accounting' ) . '</th><th>' . esc_html__( 'Type', 'taxocommerce-accounting' ) . '</th><th>' . esc_html__( 'Opening balance', 'taxocommerce-accounting' ) . '</th><th>' . esc_html__( 'Current balance', 'taxocommerce-accounting' ) . '</th><th>' . esc_html__( 'Funding entry', 'taxocommerce-accounting' ) . '</th><th>' . esc_html__( 'Edit', 'taxocommerce-accounting' ) . '</th></tr></thead><tbody>';
        $by_id = array();
        foreach ( $accounts as $account ) {
            $by_id[ (int) $account->id ] = $account;
            $balance = $account->opened_on <= $today ? (float) $account->opening_amount : 0.0;
            foreach ( $movements as $movement ) {
                if ( (int) $movement->account_id === (int) $account->id ) {
                    $balance += in_array( $movement->direction, array( 'in', 'transfer_in' ), true )
                        ? (float) $movement->total : -(float) $movement->total;
                }
            }
            echo '<tr><td>' . esc_html( $account->name ) . '</td><td>' . esc_html( 'cash' === $account->kind ? __( 'Cash', 'taxocommerce-accounting' ) : __( 'Bank', 'taxocommerce-accounting' ) ) . '</td><td>' . esc_html( tca_money( $account->opening_amount ) ) . '</td><td>' . esc_html( tca_money( $balance ) ) . '</td><td>';
            if ( (float) $account->opening_amount > 0 ) {
                self::start_form( 'opening_receipt' );
                echo '<input type="hidden" name="account_id" value="' . esc_attr( (string) $account->id ) . '">';
                echo '<label>' . esc_html__( 'Actual contribution date', 'taxocommerce-accounting' ) . ' <input name="posted_on" type="date" min="' . esc_attr( $account->opened_on ) . '" max="' . esc_attr( $today ) . '" value="' . esc_attr( $account->opened_on ) . '" required></label> ';
                echo '<label>' . esc_html__( 'Funding reference', 'taxocommerce-accounting' ) . ' <input name="reference" maxlength="191" required placeholder="' . esc_attr__( 'Initial loan / contribution', 'taxocommerce-accounting' ) . '"></label> ';
                echo '<label><input type="checkbox" name="opening_verified" value="1" required> ' . esc_html__( 'Convert the opening balance, without adding funds twice', 'taxocommerce-accounting' ) . '</label> ';
                submit_button( __( 'Convert to receipt', 'taxocommerce-accounting' ), 'secondary small', 'submit', false );
                echo '</form>';
            } else {
                echo '&mdash;';
            }
            echo '</td><td>';
            self::start_form( 'edit_account' );
            echo '<input type="hidden" name="account_id" value="' . esc_attr( (string) $account->id ) . '">';
            echo '<input type="text" name="name" maxlength="191" value="' . esc_attr( $account->name ) . '" required>';
            submit_button( __( 'Rename account', 'taxocommerce-accounting' ), 'secondary small', 'submit', false );
            echo '</form></td></tr>';
        }
        if ( ! $accounts ) {
            echo '<tr><td colspan="6">' . esc_html__( 'Add your cash register or first bank account to start tracking funds.', 'taxocommerce-accounting' ) . '</td></tr>';
        }
        echo '</tbody></table>';
        TCA_Settlements::render_bulk( $year );

        echo '<div class="tca-treasury-forms"><div>';
        echo '<h4>' . esc_html__( 'Create a new cash or bank account (not a receipt)', 'taxocommerce-accounting' ) . '</h4>';
        echo '<p class="description">' . esc_html__( 'Use the account name ING or CAJA. Record donations, initial contributions and customer receipts as movements into an existing account.', 'taxocommerce-accounting' ) . '</p>';
        self::start_form( 'account' );
        echo '<p><label>' . esc_html__( 'Name', 'taxocommerce-accounting' ) . ' <input type="text" name="name" maxlength="191" required></label></p>';
        echo '<p><label>' . esc_html__( 'Type', 'taxocommerce-accounting' ) . ' <select name="kind"><option value="cash">' . esc_html__( 'Cash', 'taxocommerce-accounting' ) . '</option><option value="bank">' . esc_html__( 'Bank', 'taxocommerce-accounting' ) . '</option></select></label></p>';
        echo '<p><label>' . esc_html__( 'Opening date', 'taxocommerce-accounting' ) . ' <input type="date" name="opened_on" value="' . esc_attr( $today ) . '" required></label></p>';
        echo '<p><label>' . esc_html__( 'Opening balance (€)', 'taxocommerce-accounting' ) . ' <input type="number" name="opening_amount" step="0.01" value="0" required></label></p>';
        submit_button( __( 'Add account', 'taxocommerce-accounting' ), 'secondary', 'submit', false );
        echo '</form></div>';
        if ( $accounts ) {
            echo '<div><h4>' . esc_html__( 'Record a payment or receipt', 'taxocommerce-accounting' ) . '</h4>';
            self::start_form( 'movement' );
            echo '<p><label>' . esc_html__( 'Account', 'taxocommerce-accounting' ) . ' ';
            self::account_select( $accounts, 'account_id' );
            echo '</label></p><p><label>' . esc_html__( 'Movement', 'taxocommerce-accounting' ) . ' <select name="direction"><option value="in">' . esc_html__( 'Money in', 'taxocommerce-accounting' ) . '</option><option value="out">' . esc_html__( 'Money out', 'taxocommerce-accounting' ) . '</option></select></label></p>';
            echo '<p><label>' . esc_html__( 'Date', 'taxocommerce-accounting' ) . ' <input type="date" name="posted_on" value="' . esc_attr( $today ) . '" required></label></p>';
            echo '<p><label>' . esc_html__( 'Amount (€)', 'taxocommerce-accounting' ) . ' <input type="number" min="0.01" step="0.01" name="amount" required></label></p>';
            echo '<p><label>' . esc_html__( 'Reference / concept', 'taxocommerce-accounting' ) . ' <input type="text" maxlength="191" name="reference"></label></p>';
            submit_button( __( 'Save movement', 'taxocommerce-accounting' ), 'secondary', 'submit', false );
            echo '</form></div>';
        }
        if ( count( $accounts ) > 1 ) {
            echo '<div><h4>' . esc_html__( 'Transfer between accounts', 'taxocommerce-accounting' ) . '</h4>';
            self::start_form( 'transfer' );
            echo '<p><label>' . esc_html__( 'From', 'taxocommerce-accounting' ) . ' ';
            self::account_select( $accounts, 'account_id' );
            echo '</label></p><p><label>' . esc_html__( 'To', 'taxocommerce-accounting' ) . ' ';
            self::account_select( $accounts, 'destination_id' );
            echo '</label></p>';
            echo '<p><label>' . esc_html__( 'Date', 'taxocommerce-accounting' ) . ' <input type="date" name="posted_on" value="' . esc_attr( $today ) . '" required></label></p>';
            echo '<p><label>' . esc_html__( 'Amount (€)', 'taxocommerce-accounting' ) . ' <input type="number" min="0.01" step="0.01" name="amount" required></label></p>';
            echo '<p><label>' . esc_html__( 'Reference / concept', 'taxocommerce-accounting' ) . ' <input type="text" maxlength="191" name="reference"></label></p>';
            submit_button( __( 'Transfer funds', 'taxocommerce-accounting' ), 'secondary', 'submit', false );
            echo '</form></div>';
        }
        echo '</div>';

        echo '<h3>' . esc_html__( 'Monthly cash flow', 'taxocommerce-accounting' ) . ' — ' . esc_html( (string) $year ) . '</h3>';
        echo '<div class="tca-treasury-scroll"><table class="widefat striped"><thead><tr>';
        foreach ( array(
            __( 'Month', 'taxocommerce-accounting' ),
            __( 'Cash in', 'taxocommerce-accounting' ), __( 'Cash out', 'taxocommerce-accounting' ),
            __( 'Bank in', 'taxocommerce-accounting' ), __( 'Bank out', 'taxocommerce-accounting' ),
            __( 'Cash balance', 'taxocommerce-accounting' ), __( 'Bank balance', 'taxocommerce-accounting' ),
        ) as $heading ) {
            echo '<th>' . esc_html( $heading ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ( $report['months'] as $row ) {
            echo '<tr><td>' . esc_html( $row['month'] ) . '</td>';
            foreach ( array( 'cash_in', 'cash_out', 'bank_in', 'bank_out', 'cash_balance', 'bank_balance' ) as $key ) {
                $value = $row['month'] > substr( $today, 0, 7 ) ? '—' : tca_money( $row[ $key ] );
                echo '<td>' . esc_html( $value ) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        echo '<h3>' . esc_html__( 'Recent cash and bank movements', 'taxocommerce-accounting' ) . '</h3>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'taxocommerce-accounting' ) . '</th><th>' . esc_html__( 'Account', 'taxocommerce-accounting' ) . '</th><th>' . esc_html__( 'Movement', 'taxocommerce-accounting' ) . '</th><th>' . esc_html__( 'Amount', 'taxocommerce-accounting' ) . '</th><th>' . esc_html__( 'Reference', 'taxocommerce-accounting' ) . '</th><th>' . esc_html__( 'Edit', 'taxocommerce-accounting' ) . '</th></tr></thead><tbody>';
        foreach ( $recent as $movement ) {
            $positive = in_array( $movement->direction, array( 'in', 'transfer_in' ), true );
            echo '<tr><td>' . esc_html( $movement->posted_on ) . '</td><td>' . esc_html( isset( $by_id[ (int) $movement->account_id ] ) ? $by_id[ (int) $movement->account_id ]->name : '' ) . '</td><td>' .
                esc_html( $positive ? __( 'Money in', 'taxocommerce-accounting' ) : __( 'Money out', 'taxocommerce-accounting' ) ) . '</td><td>' .
                esc_html( ( $positive ? '+' : '−' ) . tca_money( $movement->amount ) ) . '</td><td>' . esc_html( $movement->reference ) . '</td></tr>';
        }
        if ( ! $recent ) {
            echo '<tr><td colspan="5">' . esc_html__( 'No movements recorded yet.', 'taxocommerce-accounting' ) . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h3>' . esc_html__( 'Loans', 'taxocommerce-accounting' ) . '</h3>';
        echo '<p class="description">' . esc_html__(
            'New fixed-rate loans show monthly capital/interest forecasts. Interest on ordinary money loans is generally VAT-exempt; the schedule never changes model 303. Neither a future instalment nor an unconfirmed loan disbursement changes bank balances. Older manual loans retain their original update controls.',
            'taxocommerce-accounting'
        ) . '</p>';
        echo '<div class="tca-treasury-scroll"><table class="widefat striped"><thead><tr>';
        foreach ( array(
            __( 'Loan', 'taxocommerce-accounting' ), __( 'Lender', 'taxocommerce-accounting' ),
            __( 'Original loan', 'taxocommerce-accounting' ), __( 'Monthly payment', 'taxocommerce-accounting' ),
            __( 'Outstanding', 'taxocommerce-accounting' ), __( 'Next payment date', 'taxocommerce-accounting' ),
            __( 'Payment / updates', 'taxocommerce-accounting' ),
        ) as $heading ) {
            echo '<th>' . esc_html( $heading ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ( $loans as $loan ) {
            $managed = (int) $loan->term_months > 0;
            $paid_count = isset( $payments_by_loan[ (int) $loan->id ] ) ? (int) $payments_by_loan[ (int) $loan->id ]->paid_count : 0;
            $schedule = $managed
                ? TCA_Loan_Schedule::calculate( $loan->principal, $loan->annual_rate, $loan->term_months, $loan->first_due_date, $loan->balloon_payment )
                : array();
            $next = $schedule[ $paid_count ] ?? null;
            echo '<tr><td>' . esc_html( $loan->name ) . '</td><td>' . esc_html( $loan->lender ) .
                '</td><td>' . esc_html( tca_money( $loan->principal ) ) .
                '</td><td>' . esc_html( tca_money( $next ? $next['payment'] : $loan->monthly_payment ) ) .
                '</td><td>' . esc_html( tca_money( $loan->remaining ) ) .
                '</td><td>' . esc_html( (float) $loan->remaining <= 0 ? '—' : $loan->next_due_date ) . '</td><td>';
            if ( $managed ) {
                echo '<p>' . esc_html( (string) $loan->term_months ) . ' ' .
                    esc_html__( 'months; annual nominal rate', 'taxocommerce-accounting' ) . ': ' .
                    esc_html( number_format_i18n( (float) $loan->annual_rate, 2 ) ) .
                    '%; ' . esc_html__( 'Down payment', 'taxocommerce-accounting' ) . ': ' .
                    esc_html( tca_money( $loan->upfront_payment ) ) . '</p>';
                if ( isset( $payments_by_loan[ (int) $loan->id ] ) ) {
                    echo '<p>' . esc_html__( 'Interest already paid', 'taxocommerce-accounting' ) . ': ' .
                        esc_html( tca_money( $payments_by_loan[ (int) $loan->id ]->interest_paid ) ) . '</p>';
                }
                if ( $next && $accounts ) {
                    echo '<p><strong>' . esc_html__( 'Next instalment', 'taxocommerce-accounting' ) . ': ' .
                        esc_html( tca_money( $next['payment'] ) ) . '</strong> (' .
                        esc_html__( 'Capital', 'taxocommerce-accounting' ) . ' ' . esc_html( tca_money( $next['principal'] ) ) .
                        ' + ' . esc_html__( 'Interest', 'taxocommerce-accounting' ) . ' ' . esc_html( tca_money( $next['interest'] ) ) . ')</p>';
                    self::start_form( 'loan_payment' );
                    echo '<input type="hidden" name="loan_id" value="' . esc_attr( (string) $loan->id ) . '">';
                    echo '<input type="hidden" name="request_key" value="' . esc_attr( wp_generate_uuid4() ) . '">';
                    echo '<label>' . esc_html__( 'Pay from', 'taxocommerce-accounting' ) . ' ';
                    self::account_select( $accounts, 'account_id' );
                    echo '</label> ';
                    echo '<label>' . esc_html__( 'Actual payment date', 'taxocommerce-accounting' ) .
                        ' <input type="date" name="posted_on" value="' . esc_attr( $today ) . '" required></label> ';
                    echo '<p><label><input type="checkbox" name="loan_payment_confirmed" value="1" required> ' .
                        esc_html__( 'I confirm this instalment was actually paid from this account.', 'taxocommerce-accounting' ) . '</label></p>';
                    submit_button( __( 'Confirm instalment paid', 'taxocommerce-accounting' ), 'secondary small', 'submit', false );
                    echo '</form>';
                }
                if ( $schedule ) {
                    echo '<details><summary>' . esc_html__( 'Amortisation schedule', 'taxocommerce-accounting' ) . '</summary>';
                    echo '<table class="widefat striped"><thead><tr><th>#</th><th>' .
                        esc_html__( 'Due date', 'taxocommerce-accounting' ) . '</th><th>' .
                        esc_html__( 'Capital', 'taxocommerce-accounting' ) . '</th><th>' .
                        esc_html__( 'Interest', 'taxocommerce-accounting' ) . '</th><th>' .
                        esc_html__( 'Instalment', 'taxocommerce-accounting' ) . '</th><th>' .
                        esc_html__( 'Outstanding', 'taxocommerce-accounting' ) . '</th><th>' .
                        esc_html__( 'Status', 'taxocommerce-accounting' ) . '</th></tr></thead><tbody>';
                    foreach ( $schedule as $row ) {
                        echo '<tr><td>' . esc_html( (string) $row['number'] ) . '</td><td>' .
                            esc_html( $row['due_date'] ) . '</td><td>' . esc_html( tca_money( $row['principal'] ) ) .
                            '</td><td>' . esc_html( tca_money( $row['interest'] ) ) .
                            '</td><td>' . esc_html( tca_money( $row['payment'] ) ) .
                            '</td><td>' . esc_html( tca_money( $row['remaining'] ) ) .
                            '</td><td>' . esc_html( $row['number'] <= $paid_count
                                ? __( 'Paid', 'taxocommerce-accounting' ) : __( 'Forecast', 'taxocommerce-accounting' ) ) . '</td></tr>';
                    }
                    echo '</tbody></table></details>';
                }
            } else {
                // The original manual loans have no original TIN/term contract.
                self::start_form( 'loan_update' );
                echo '<input type="hidden" name="loan_id" value="' . esc_attr( (string) $loan->id ) . '">';
                echo '<label>' . esc_html__( 'Outstanding', 'taxocommerce-accounting' ) . ' <input type="number" min="0" step="0.01" name="remaining" value="' . esc_attr( (string) $loan->remaining ) . '" required></label> ';
                echo '<label>' . esc_html__( 'Monthly payment', 'taxocommerce-accounting' ) . ' <input type="number" min="0.01" step="0.01" name="monthly_payment" value="' . esc_attr( (string) $loan->monthly_payment ) . '" required></label> ';
                echo '<label>' . esc_html__( 'Next payment date', 'taxocommerce-accounting' ) . ' <input type="date" name="next_due_date" value="' . esc_attr( $loan->next_due_date ) . '" required></label> ';
                submit_button( __( 'Update loan', 'taxocommerce-accounting' ), 'secondary', 'submit', false );
                echo '</form>';
            }
            echo '</td></tr>';
        }
        if ( ! $loans ) {
            echo '<tr><td colspan="7">' . esc_html__( 'No loans entered.', 'taxocommerce-accounting' ) . '</td></tr>';
        }
        echo '</tbody></table></div>';

        echo '<h4>' . esc_html__( 'Add a loan', 'taxocommerce-accounting' ) . '</h4>';
        self::start_form( 'loan' );
        echo '<input type="hidden" name="loan_request_key" value="' . esc_attr( wp_generate_uuid4() ) . '">';
        echo '<p class="description">' . esc_html__(
            'Enter the amount actually borrowed, excluding any down payment. Set TIN and term to calculate instalments; leave term zero and enter a manual monthly amount for an old-style loan. The optional final balloon is extra principal due with the last instalment.',
            'taxocommerce-accounting'
        ) . '</p>';
        echo '<div class="tca-treasury-forms"><div><p><label>' . esc_html__( 'Loan name', 'taxocommerce-accounting' ) . ' <input type="text" maxlength="191" name="name" required></label></p>';
        echo '<p><label>' . esc_html__( 'Lender', 'taxocommerce-accounting' ) . ' <input type="text" maxlength="191" name="lender"></label></p>';
        echo '<p><label>' . esc_html__( 'Annual nominal interest (TIN %)', 'taxocommerce-accounting' ) . ' <input type="number" min="0" max="100" step="0.0001" name="annual_rate" value="0" required></label></p></div>';
        echo '<div><p><label>' . esc_html__( 'Financed principal (€)', 'taxocommerce-accounting' ) . ' <input type="number" min="0.01" step="0.01" name="principal" required></label></p>';
        echo '<p><label>' . esc_html__( 'Term in months (0 = manual)', 'taxocommerce-accounting' ) . ' <input type="number" min="0" max="360" step="1" name="term_months" value="12" required></label></p>';
        echo '<p><label>' . esc_html__( 'Down payment from own funds (€)', 'taxocommerce-accounting' ) . ' <input type="number" min="0" step="0.01" name="upfront_payment" value="0" required></label></p>';
        echo '<p><label>' . esc_html__( 'Extra principal on final instalment (€)', 'taxocommerce-accounting' ) . ' <input type="number" min="0" step="0.01" name="balloon_payment" value="0" required></label></p>';
        echo '<p><label>' . esc_html__( 'Manual monthly payment (€; only term 0)', 'taxocommerce-accounting' ) . ' <input type="number" min="0.01" step="0.01" name="monthly_payment"></label></p></div>';
        echo '<div><p><label>' . esc_html__( 'Loan date', 'taxocommerce-accounting' ) . ' <input type="date" name="start_date" value="' . esc_attr( $today ) . '" required></label></p>';
        echo '<p><label>' . esc_html__( 'First instalment date', 'taxocommerce-accounting' ) . ' <input type="date" name="next_due_date" required></label></p>';
        if ( $accounts ) {
            echo '<p><label><input type="checkbox" name="funding_received" value="1"> ' .
                esc_html__( 'Loan funds actually received in bank (confirmed)', 'taxocommerce-accounting' ) . '</label></p>';
            echo '<p><label>' . esc_html__( 'Bank receiving loan', 'taxocommerce-accounting' ) . ' <select name="funding_account_id">';
            echo '<option value="0">' . esc_html__( 'Select bank', 'taxocommerce-accounting' ) . '</option>';
            foreach ( $accounts as $account ) {
                if ( 'bank' === $account->kind ) {
                    echo '<option value="' . esc_attr( (string) $account->id ) . '">' . esc_html( $account->name ) . '</option>';
                }
            }
            echo '</select></label></p>';
        }
        echo '</div></div>';
        echo '<p class="description">' . esc_html__(
            'Confirm funds received only if a real transfer into this bank account occurred. Saving the loan does not repeat the supplier payment; the down payment is informational and never moves cash automatically. Loan instalments are forecast until manually confirmed.',
            'taxocommerce-accounting'
        ) . '</p>';
        submit_button( __( 'Save loan', 'taxocommerce-accounting' ), 'secondary', 'submit', false );
        echo '</form>';
        TCA_Expense_CSV::render( $year );
    }
}
add_action( 'admin_post_tca_treasury_action', array( 'TCA_Treasury', 'handle' ) );
