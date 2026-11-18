<?php
/**
 * Explicit settlement records link fiscal entries to real cash or bank movements.
 *
 * @package TaxoCommerceAccounting
 */
defined( 'ABSPATH' ) || exit;

final class TCA_Settlements {
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tca_treasury_settlements';
    }

    public static function install_schema() {
        global $wpdb;
        $table = esc_sql( self::table() );
        $collation = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entry_id bigint(20) unsigned NOT NULL,
            movement_id bigint(20) unsigned NOT NULL,
            request_key varchar(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY movement_id (movement_id),
            UNIQUE KEY request_key (request_key),
            KEY entry_id (entry_id)
        ) {$collation};" );
    }

    public static function direction( $kind ) {
        return 'sale' === $kind ? 'in' : 'out';
    }

    public static function outstanding( $total, $already_paid ) {
        return max( 0.0, round( abs( (float) $total ) - (float) $already_paid, 2 ) );
    }

    public static function sum( $entry_id ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Real-time settlements of plugin-owned tables.
        return (float) $wpdb->get_var( $wpdb->prepare(
            'SELECT COALESCE(SUM(m.amount),0) FROM %i AS s INNER JOIN %i AS m ON m.id = s.movement_id WHERE s.entry_id = %d',
            self::table(), TCA_Treasury::movements_table(), absint( $entry_id )
        ) );
    }

    public static function accounts() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Needed to select an actual cash or bank account.
        return (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT id, name, kind, opened_on FROM %i ORDER BY kind DESC, name, id', TCA_Treasury::accounts_table()
        ) );
    }

    public static function settle( $entry_id, $account_id, $date, $amount, $key ) {
        global $wpdb;
        $entry_id = absint( $entry_id );
        $account_id = absint( $account_id );
        $amount = tca_decimal( $amount );
        if ( ! $entry_id || ! $account_id || ! is_string( $key ) || ! preg_match( '/^[a-f0-9-]{36}$/', $key ) ||
            ! tca_valid_date( $date ) || $date > current_time( 'Y-m-d' ) || null === $amount || $amount <= 0 ) {
            return new WP_Error( 'settlement_invalid', __( 'Check payment date, account and amount.', 'taxocommerce-accounting' ) );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic settlement and duplicate-request check.
        $wpdb->query( 'START TRANSACTION' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock entry to prevent concurrent overpayment.
        $entry = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', tca_table(), $entry_id ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Idempotency key unique across all payments.
        $existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE request_key = %s', self::table(), $key ) );
        if ( $existing ) {
            $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- End idempotent request.
            return true;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Validate chosen account still exists.
        $account = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', TCA_Treasury::accounts_table(), $account_id ) );
        if ( ! $entry || ! in_array( $entry->kind, array( 'sale', 'refund', 'purchase', 'expense', 'asset', 'intra' ), true ) ||
            ! $account || ! in_array( $account->kind, array( 'cash', 'bank' ), true ) ||
            $date < $account->opened_on ||
            $amount > self::outstanding( $entry->amount_total, self::sum( $entry_id ) ) ) {
            $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Invalid settlement never posts movement.
            return new WP_Error( 'settlement_invalid', __( 'Payment exceeds the outstanding amount or has invalid dates/account.', 'taxocommerce-accounting' ) );
        }
        $direction = self::direction( $entry->kind );
        // Refund entries are negative totals in some WooCommerce installations; use absolute amount and money out.
        $saved = $wpdb->insert( TCA_Treasury::movements_table(), array(
            'account_id' => $account_id,
            'posted_on' => $date,
            'direction' => $direction,
            'amount' => $amount,
            'reference' => substr( $entry->document_no ?: (string) $entry->id, 0, 191 ),
            'created_at' => current_time( 'mysql' ),
        ), array( '%d', '%s', '%s', '%f', '%s', '%s' ) );
        $movement_id = $saved ? (int) $wpdb->insert_id : 0;
        $link = $saved && $wpdb->insert( self::table(), array(
            'entry_id' => $entry_id, 'movement_id' => $movement_id,
            'request_key' => $key, 'created_at' => current_time( 'mysql' ),
        ), array( '%d', '%d', '%s', '%s' ) );
        if ( ! $link ) {
            $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- No orphan movement.
            return new WP_Error( 'settlement_failed', __( 'Payment could not be recorded.', 'taxocommerce-accounting' ) );
        }
        $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Payment and ledger link committed together.
        return true;
    }

    public static function handle() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'taxocommerce-accounting' ) );
        }
        $entry_id = isset( $_POST['entry_id'] ) ? absint( wp_unslash( $_POST['entry_id'] ) ) : 0;
        check_admin_referer( 'tca_settle_' . $entry_id );
        $account_id = isset( $_POST['account_id'] ) ? absint( wp_unslash( $_POST['account_id'] ) ) : 0;
        $date = isset( $_POST['posted_on'] ) ? sanitize_text_field( wp_unslash( $_POST['posted_on'] ) ) : '';
        $amount = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';
        $key = isset( $_POST['request_key'] ) ? sanitize_text_field( wp_unslash( $_POST['request_key'] ) ) : '';
        $result = self::settle( $entry_id, $account_id, $date, $amount, $key );
        $tab = isset( $_POST['return_tab'] ) ? sanitize_key( wp_unslash( $_POST['return_tab'] ) ) : 'expenses';
        if ( ! in_array( $tab, array( 'sales', 'received', 'expenses', 'assets', 'intra' ), true ) ) {
            $tab = 'expenses';
        }
        wp_safe_redirect( tca_admin_url( $tab, array( 'notice' => is_wp_error( $result ) ? $result->get_error_code() : 'settlement_saved' ) ) );
        exit;
    }

    public static function render( $entry ) {
        $total = abs( (float) $entry->amount_total );
        $paid = self::sum( (int) $entry->id );
        $due = self::outstanding( $total, $paid );
        echo '<p><strong>' . esc_html__( 'Paid/received:', 'taxocommerce-accounting' ) . '</strong> ' . esc_html( tca_money( $paid ) ) . ' / ' . esc_html( tca_money( $total ) ) . '</p>';
        if ( $due <= 0 ) {
            return;
        }
        $accounts = self::accounts();
        if ( ! $accounts ) {
            echo '<p class="description">' . esc_html__( 'Add a bank or cash account in Treasury to record an actual payment.', 'taxocommerce-accounting' ) . '</p>';
            return;
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tca_settle_' . (int) $entry->id );
        echo '<input type="hidden" name="action" value="tca_settle_entry">';
        echo '<input type="hidden" name="entry_id" value="' . esc_attr( (string) $entry->id ) . '">';
        echo '<input type="hidden" name="request_key" value="' . esc_attr( wp_generate_uuid4() ) . '">';
        $tab = array( 'sale' => 'sales', 'refund' => 'sales', 'purchase' => 'received', 'expense' => 'expenses', 'asset' => 'assets', 'intra' => 'intra' );
        echo '<input type="hidden" name="return_tab" value="' . esc_attr( $tab[ $entry->kind ] ?? 'expenses' ) . '">';
        echo '<label>' . esc_html__( 'Paid from / received into', 'taxocommerce-accounting' ) . ' <select name="account_id" required>';
        foreach ( $accounts as $account ) {
            echo '<option value="' . esc_attr( (string) $account->id ) . '">' . esc_html( $account->name . ' (' . $account->kind . ')' ) . '</option>';
        }
        echo '</select></label> ';
        echo '<label>' . esc_html__( 'Payment date', 'taxocommerce-accounting' ) . ' <input type="date" name="posted_on" value="' . esc_attr( $entry->fiscal_date <= current_time( 'Y-m-d' ) ? $entry->fiscal_date : current_time( 'Y-m-d' ) ) . '" required></label> ';
        echo '<label>' . esc_html__( 'Amount', 'taxocommerce-accounting' ) . ' <input type="number" step="0.01" min="0.01" max="' . esc_attr( number_format( $due, 2, '.', '' ) ) . '" name="amount" value="' . esc_attr( number_format( $due, 2, '.', '' ) ) . '" required></label> ';
        submit_button( 'sale' === $entry->kind ? __( 'Record receipt', 'taxocommerce-accounting' ) : __( 'Record payment', 'taxocommerce-accounting' ), 'secondary small', 'submit', false );
        echo '</form>';
        echo '<p class="description">' . esc_html__( 'Record only actual bank/cash payments. Importing or validating an invoice never assumes it was paid.', 'taxocommerce-accounting' ) . '</p>';
    }
}
add_action( 'admin_post_tca_settle_entry', array( 'TCA_Settlements', 'handle' ) );
