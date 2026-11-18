<?php
/**
 * Offline settlement checks: no real database, bank account or WordPress writes.
 *
 * @package TaxoCommerceAccounting
 */
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
}
require_once dirname( __DIR__ ) . '/includes/class-tca-settlements.php';

function tca_settlement_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'Settlement FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}
tca_settlement_assert( 'in' === TCA_Settlements::direction( 'sale' ), 'Received sale is a money-in movement' );
foreach ( array( 'expense', 'purchase', 'asset', 'intra', 'refund' ) as $kind ) {
    tca_settlement_assert( 'out' === TCA_Settlements::direction( $kind ), 'Outgoing entry is a money-out movement: ' . $kind );
}
tca_settlement_assert( 13.80 === TCA_Settlements::outstanding( 362.57, 348.77 ), 'Outstanding balance supports cents rounding' );
tca_settlement_assert( 24.20 === TCA_Settlements::outstanding( 24.20, 0 ), 'Unpaid invoice remains fully outstanding' );
tca_settlement_assert( 14.20 === TCA_Settlements::outstanding( 24.20, 10 ), 'Partial payment decreases outstanding' );
tca_settlement_assert( 0.0 === TCA_Settlements::outstanding( 24.20, 24.20 ), 'Fully paid invoice leaves zero outstanding' );
tca_settlement_assert( 0.0 === TCA_Settlements::outstanding( 24.20, 30 ), 'Overpayment does not create negative available payment' );
tca_settlement_assert( 24.20 === TCA_Settlements::outstanding( -24.20, 0 ), 'Signed WooCommerce refund uses absolute payout' );
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-tca-settlements.php' );
tca_settlement_assert( false !== strpos( $source, "check_admin_referer( 'tca_settle_'"), 'Payment handler verifies nonce' );
tca_settlement_assert( false !== strpos( $source, "current_user_can( 'manage_woocommerce' )" ), 'Payment handler requires WooCommerce capability' );
tca_settlement_assert( false !== strpos( $source, 'FOR UPDATE' ), 'Entry lock prevents concurrent overpayment' );
tca_settlement_assert( false !== strpos( $source, 'UNIQUE KEY request_key' ), 'Idempotency constraint prevents retry duplication' );
tca_settlement_assert( false !== strpos( $source, 'ROLLBACK' ) && false !== strpos( $source, 'COMMIT' ), 'Payment and link share one transaction' );
tca_settlement_assert( false === strpos( $source, "update( tca_table()" ), 'Settlement never edits approved fiscal entries' );
echo "TaxoCommerce Accounting settlement smoke: PASS\n";
