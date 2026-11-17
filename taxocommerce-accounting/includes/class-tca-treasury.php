<?php
/**
 * Simple, independent cash/bank and loan register for Accounting.
 *
 * Actual balances are based solely on opening funds and manually recorded
 * cash movements. Invoices and projected loan instalments never imply cash.
 *
 * @package TaxoCommerceAccounting
 */
defined( 'ABSPATH' ) || exit;

final class TCA_Treasury {
    private static function table( $suffix ) {
        global $wpdb;
        return $wpdb->prefix . 'tca_treasury_' . $suffix;
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
            start_date date NOT NULL,
            next_due_date date NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY next_due_date (next_due_date)
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
            $monthly = self::money( self::posted( 'monthly_payment' ) );
            if ( '' === $name || ! self::date( $start ) || ! self::date( $due, true ) ||
                $due < $start || null === $principal || $principal <= 0 ||
                null === $monthly || $monthly <= 0 ) {
                self::redirect( 'invalid' );
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own loan register.
            $ok = $wpdb->insert( self::table( 'loans' ), array(
                'name' => $name, 'lender' => $lender, 'principal' => $principal,
                'remaining' => $principal, 'monthly_payment' => $monthly,
                'start_date' => $start, 'next_due_date' => $due,
                'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
            ), array( '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s' ) );
            self::redirect( $ok ? 'saved' : 'error' );
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
            if ( ! $loan || null === $remaining || $remaining > (float) $loan->principal ||
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

    private static function account_select( $accounts, $name ) {
        echo '<select name="' . esc_attr( $name ) . '" required>';
        foreach ( $accounts as $account ) {
            echo '<option value="' . esc_attr( (string) $account->id ) . '">' . esc_html( $account->name ) . ' (' .
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
        echo '<p class="description">' . esc_html__( 'Balances use only recorded opening funds and actual cash movements. Issued invoices, unpaid purchases and future loan instalments do not move money automatically.', 'taxocommerce-accounting' ) . '</p>';
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
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Account', 'taxocommerce-accounting' ) . '</th><th>' . esc_html__( 'Type', 'taxocommerce-accounting' ) . '</th><th>' . esc_html__( 'Opening balance', 'taxocommerce-accounting' ) . '</th><th>' . esc_html__( 'Current balance', 'taxocommerce-accounting' ) . '</th></tr></thead><tbody>';
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
            echo '<tr><td>' . esc_html( $account->name ) . '</td><td>' . esc_html( 'cash' === $account->kind ? __( 'Cash', 'taxocommerce-accounting' ) : __( 'Bank', 'taxocommerce-accounting' ) ) . '</td><td>' . esc_html( tca_money( $account->opening_amount ) ) . '</td><td>' . esc_html( tca_money( $balance ) ) . '</td></tr>';
        }
        if ( ! $accounts ) {
            echo '<tr><td colspan="4">' . esc_html__( 'Add your cash register or first bank account to start tracking funds.', 'taxocommerce-accounting' ) . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<div class="tca-treasury-forms"><div>';
        echo '<h4>' . esc_html__( 'Add cash register / bank account', 'taxocommerce-accounting' ) . '</h4>';
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
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'taxocommerce-accounting' ) . '</th><th>' . esc_html__( 'Account', 'taxocommerce-accounting' ) . '</th><th>' . esc_html__( 'Movement', 'taxocommerce-accounting' ) . '</th><th>' . esc_html__( 'Amount', 'taxocommerce-accounting' ) . '</th><th>' . esc_html__( 'Reference', 'taxocommerce-accounting' ) . '</th></tr></thead><tbody>';
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
        echo '<p class="description">' . esc_html__( 'Outstanding debt and next due date must be updated from the lender statement. Instalments are forecasts, not confirmed payments; record actual bank payments separately. No interest is calculated.', 'taxocommerce-accounting' ) . '</p>';
        echo '<div class="tca-treasury-scroll"><table class="widefat striped"><thead><tr>';
        foreach ( array(
            __( 'Loan', 'taxocommerce-accounting' ), __( 'Lender', 'taxocommerce-accounting' ),
            __( 'Original loan', 'taxocommerce-accounting' ), __( 'Monthly payment', 'taxocommerce-accounting' ),
            __( 'Outstanding', 'taxocommerce-accounting' ), __( 'Next payment date', 'taxocommerce-accounting' ),
            __( 'Update outstanding amount', 'taxocommerce-accounting' ),
        ) as $heading ) {
            echo '<th>' . esc_html( $heading ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ( $loans as $loan ) {
            echo '<tr><td>' . esc_html( $loan->name ) . '</td><td>' . esc_html( $loan->lender ) . '</td><td>' . esc_html( tca_money( $loan->principal ) ) . '</td><td>' . esc_html( tca_money( $loan->monthly_payment ) ) . '</td><td>' . esc_html( tca_money( $loan->remaining ) ) . '</td><td>' . esc_html( $loan->next_due_date ) . '</td><td>';
            self::start_form( 'loan_update' );
            echo '<input type="hidden" name="loan_id" value="' . esc_attr( (string) $loan->id ) . '">';
            echo '<label>' . esc_html__( 'Outstanding', 'taxocommerce-accounting' ) . ' <input type="number" min="0" step="0.01" name="remaining" value="' . esc_attr( (string) $loan->remaining ) . '" required></label> ';
            echo '<label>' . esc_html__( 'Monthly payment', 'taxocommerce-accounting' ) . ' <input type="number" min="0.01" step="0.01" name="monthly_payment" value="' . esc_attr( (string) $loan->monthly_payment ) . '" required></label> ';
            echo '<label>' . esc_html__( 'Next payment date', 'taxocommerce-accounting' ) . ' <input type="date" name="next_due_date" value="' . esc_attr( $loan->next_due_date ) . '" required></label> ';
            submit_button( __( 'Update loan', 'taxocommerce-accounting' ), 'secondary', 'submit', false );
            echo '</form></td></tr>';
        }
        if ( ! $loans ) {
            echo '<tr><td colspan="7">' . esc_html__( 'No loans entered.', 'taxocommerce-accounting' ) . '</td></tr>';
        }
        echo '</tbody></table></div>';

        echo '<h4>' . esc_html__( 'Add a loan', 'taxocommerce-accounting' ) . '</h4>';
        self::start_form( 'loan' );
        echo '<div class="tca-treasury-forms"><div><p><label>' . esc_html__( 'Loan name', 'taxocommerce-accounting' ) . ' <input type="text" maxlength="191" name="name" required></label></p>';
        echo '<p><label>' . esc_html__( 'Lender', 'taxocommerce-accounting' ) . ' <input type="text" maxlength="191" name="lender"></label></p></div>';
        echo '<div><p><label>' . esc_html__( 'Original loan (€)', 'taxocommerce-accounting' ) . ' <input type="number" min="0.01" step="0.01" name="principal" required></label></p>';
        echo '<p><label>' . esc_html__( 'Monthly payment (€)', 'taxocommerce-accounting' ) . ' <input type="number" min="0.01" step="0.01" name="monthly_payment" required></label></p></div>';
        echo '<div><p><label>' . esc_html__( 'Loan date', 'taxocommerce-accounting' ) . ' <input type="date" name="start_date" value="' . esc_attr( $today ) . '" required></label></p>';
        echo '<p><label>' . esc_html__( 'Next payment date', 'taxocommerce-accounting' ) . ' <input type="date" name="next_due_date" required></label></p></div></div>';
        submit_button( __( 'Save loan', 'taxocommerce-accounting' ), 'secondary', 'submit', false );
        echo '</form>';
    }
}
add_action( 'admin_post_tca_treasury_action', array( 'TCA_Treasury', 'handle' ) );
