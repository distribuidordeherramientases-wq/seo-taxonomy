<?php
/**
 * Offline Treasury regression: no WordPress, bank account, database or writes.
 *
 * @package TaxoCommerceAccounting
 */
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
}
require_once dirname( __DIR__ ) . '/includes/class-tca-treasury.php';

function tca_treasury_assert( $condition, $reason ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'Treasury FAIL: ' . $reason . PHP_EOL );
        exit( 1 );
    }
}

$accounts = array(
    (object) array( 'id' => 1, 'kind' => 'cash', 'name' => 'Till', 'opened_on' => '2026-01-01', 'opening_amount' => 100.0 ),
    (object) array( 'id' => 2, 'kind' => 'bank', 'name' => 'Account 1', 'opened_on' => '2026-01-01', 'opening_amount' => 500.0 ),
    (object) array( 'id' => 3, 'kind' => 'bank', 'name' => 'Future account', 'opened_on' => '2026-12-01', 'opening_amount' => 1000.0 ),
);
$movements = array(
    (object) array( 'account_id' => 1, 'month' => '2026-11', 'direction' => 'in', 'total' => 70.0 ),
    (object) array( 'account_id' => 1, 'month' => '2026-11', 'direction' => 'out', 'total' => 30.0 ),
    (object) array( 'account_id' => 1, 'month' => '2026-11', 'direction' => 'transfer_out', 'total' => 20.0 ),
    (object) array( 'account_id' => 2, 'month' => '2026-11', 'direction' => 'transfer_in', 'total' => 20.0 ),
    (object) array( 'account_id' => 2, 'month' => '2026-11', 'direction' => 'in', 'total' => 100.0 ),
    (object) array( 'account_id' => 2, 'month' => '2026-11', 'direction' => 'out', 'total' => 40.0 ),
);
$report = TCA_Treasury::calculate( $accounts, $movements, 2026, '2026-11-17' );
tca_treasury_assert( 120.0 === $report['current']['cash'], 'Cash opening plus receipts minus outflows and transfer' );
tca_treasury_assert( 580.0 === $report['current']['bank'], 'Multiple bank account balances and transfer receipt' );
tca_treasury_assert( 700.0 === array_sum( $report['current'] ), 'Internal transfer does not increase total liquidity' );
tca_treasury_assert( 100.0 === $report['months']['2026-10']['cash_balance'], 'Opening funds exist in prior months' );
tca_treasury_assert( 500.0 === $report['months']['2026-10']['bank_balance'], 'Opening bank balance before monthly activity' );
tca_treasury_assert( 70.0 === $report['months']['2026-11']['cash_in'], 'Cash receipts grouped by month' );
tca_treasury_assert( 50.0 === $report['months']['2026-11']['cash_out'], 'Cash payments and outgoing transfers grouped by month' );
tca_treasury_assert( 120.0 === $report['months']['2026-11']['bank_in'], 'Bank receipt and incoming transfer' );
tca_treasury_assert( 40.0 === $report['months']['2026-11']['bank_out'], 'Bank payments' );
tca_treasury_assert( 580.0 === $report['months']['2026-11']['bank_balance'], 'Month closing bank balance' );

// The STAGING 2026 case: two 500 EUR January opening balances do not
// represent monthly receipts; confirmed November settlements do.
$opening_accounts = array(
    (object) array( 'id' => 11, 'kind' => 'cash', 'name' => 'Cash', 'opened_on' => '2026-01-01', 'opening_amount' => 500.0 ),
    (object) array( 'id' => 12, 'kind' => 'bank', 'name' => 'Bank', 'opened_on' => '2026-01-01', 'opening_amount' => 500.0 ),
);
$before = TCA_Treasury::calculate( $opening_accounts, array(), 2026, '2026-11-20' );
tca_treasury_assert( 0.0 === $before['months']['2026-01']['cash_in'] && 0.0 === $before['months']['2026-01']['bank_in'], 'Opening balances are not double-counted as receipts' );
tca_treasury_assert( 500.0 === $before['current']['cash'] && 500.0 === $before['current']['bank'], 'Opening balances remain available until an actual movement' );
$confirmed_movements = array(
    (object) array( 'account_id' => 12, 'month' => '2026-11', 'direction' => 'in', 'total' => 1043.06 ),
    (object) array( 'account_id' => 12, 'month' => '2026-11', 'direction' => 'out', 'total' => 939.46 ),
);
$settled = TCA_Treasury::calculate( $opening_accounts, $confirmed_movements, 2026, '2026-11-20' );
tca_treasury_assert( 603.60 === round( $settled['current']['bank'], 2 ), 'Confirmed sales less paid supplier invoices update bank balance' );
tca_treasury_assert( 500.0 === $settled['current']['cash'], 'Bank-linked invoices do not change cash register' );
tca_treasury_assert( 1043.06 === $settled['months']['2026-11']['bank_in'], 'Confirmed WooCommerce receipts appear in Bank in' );
tca_treasury_assert( 939.46 === $settled['months']['2026-11']['bank_out'], 'Confirmed invoice payments appear in Bank out' );
$receipt_accounts = array(
    (object) array( 'id' => 11, 'kind' => 'cash', 'name' => 'Cash', 'opened_on' => '2026-01-01', 'opening_amount' => 0.0 ),
    (object) array( 'id' => 12, 'kind' => 'bank', 'name' => 'Bank', 'opened_on' => '2026-01-01', 'opening_amount' => 0.0 ),
);
$receipt_movements = array(
    (object) array( 'account_id' => 11, 'month' => '2026-01', 'direction' => 'in', 'total' => 500.0 ),
    (object) array( 'account_id' => 12, 'month' => '2026-01', 'direction' => 'in', 'total' => 500.0 ),
);
$converted = TCA_Treasury::calculate( $receipt_accounts, $receipt_movements, 2026, '2026-11-20' );
tca_treasury_assert( 500.0 === $converted['current']['cash'] && 500.0 === $converted['current']['bank'], 'Converting both openings to receipts preserves available cash' );
tca_treasury_assert( 500.0 === $converted['months']['2026-01']['cash_in'] && 500.0 === $converted['months']['2026-01']['bank_in'], 'January loan proceeds appear as receipts after explicit conversion' );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-tca-treasury.php' );
tca_treasury_assert( false !== $source, 'Treasury source readable' );
tca_treasury_assert( false !== strpos( $source, "'opening_receipt' === \$operation" ), 'Opening receipt conversion is an explicit action' );
tca_treasury_assert( false !== strpos( $source, "name=\"opening_verified\"" ), 'Conversion requires confirmation' );
tca_treasury_assert( false !== strpos( $source, "'opening_amount' => 0" ), 'Conversion zeroes old opening amount to prevent duplication' );
tca_treasury_assert( false !== strpos( $source, "check_admin_referer( 'tca_treasury_action' )" ), 'All treasury writes verify nonce' );
tca_treasury_assert( false !== strpos( $source, "current_user_can( 'manage_woocommerce' )" ), 'Treasury writes require WooCommerce role' );
tca_treasury_assert( false !== strpos( $source, "START TRANSACTION" ) && false !== strpos( $source, "ROLLBACK" ), 'Transfers are atomic' );
tca_treasury_assert( false !== strpos( $source, "'remaining' => \$principal" ), 'New loans start at their principal' );
tca_treasury_assert( false !== strpos( $source, "next_due_date" ), 'Loan due date is tracked' );
tca_treasury_assert( false === strpos( $source, "get_total_tax(" ), 'Treasury cash is not derived from VAT' );
tca_treasury_assert( false === strpos( $source, "wc_get_orders(" ), 'Treasury cash is not invented from invoices' );
echo "TaxoCommerce Accounting Treasury isolated smoke: PASS\n";
