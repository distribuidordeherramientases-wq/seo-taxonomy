<?php
/** Offline traffic-light regression: no WordPress or bank writes. */
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
function __( $string, $domain = '' ) { return $string; }
class TCA_Review_Navigation {
    public static function inspect( $entry ) {
        return array(
            'error' => ! empty( $entry->error ),
            'pending' => 'validated' !== $entry->status || 'review' === $entry->treatment,
            'incident' => ! empty( $entry->incident ),
        );
    }
}
class TCA_Corrections {
    public static function tab_for( $kind ) {
        return array(
            'sale' => 'sales', 'refund' => 'sales', 'purchase' => 'received',
            'expense' => 'expenses', 'asset' => 'assets', 'intra' => 'intra',
        )[ $kind ] ?? 'summary';
    }
}
require dirname( __DIR__ ) . '/includes/class-tca-status-colors.php';
function check_status( $actual, $expected, $message ) {
    if ( $actual !== $expected ) {
        fwrite( STDERR, 'Status FAIL: ' . $message . ' (' . $actual . ')' . PHP_EOL );
        exit( 1 );
    }
}
function entry( $id, $kind, $status, $total, $extra = array() ) {
    return (object) array_merge( array(
        'id' => $id, 'kind' => $kind, 'status' => $status,
        'treatment' => 'domestic', 'amount_total' => $total,
        'error' => false, 'incident' => false,
    ), $extra );
}
$sale = entry( 1, 'sale', 'validated', 16.01 );
$expense = entry( 2, 'expense', 'validated', 16.94 );
$draft = entry( 3, 'purchase', 'draft', 20.56 );
$wrong = entry( 4, 'sale', 'validated', 30.00, array( 'error' => true ) );
check_status( TCA_Status_Colors::entry( $sale, 16.01 ), 'closed', 'Fully linked validated sale is green' );
check_status( TCA_Status_Colors::entry( $expense, 0 ), 'pending', 'Validated FNMT expense without a linked bank movement is amber, not red' );
check_status( TCA_Status_Colors::entry( $draft, 20.56 ), 'pending', 'Paid draft is still awaiting fiscal validation' );
check_status( TCA_Status_Colors::entry( $wrong, 30.00 ), 'error', 'Verified invoice mismatch is red' );
check_status( TCA_Status_Colors::entry( $sale, 16.50 ), 'error', 'Excess linked payment is an error' );
check_status( TCA_Status_Colors::entry( entry( 5, 'sale', 'validated', 0 ), 0 ), 'closed', 'Zero-valued approved entry is not treated as unpaid' );
check_status( TCA_Status_Colors::aggregate( array() ), 'neutral', 'Empty section remains neutral' );
check_status( TCA_Status_Colors::aggregate( array( 'closed', 'pending' ) ), 'pending', 'Pending blocks all-green tab' );
check_status( TCA_Status_Colors::aggregate( array( 'pending', 'error' ) ), 'error', 'Verified error dominates pending' );
$tabs = TCA_Status_Colors::tabs( array( $sale, $expense, $draft, $wrong ), array( 1 => 16.01, 2 => 0, 3 => 20.56, 4 => 30.00 ) );
check_status( $tabs['sales'], 'error', 'Sales tab highlights invoice mismatch' );
check_status( $tabs['expenses'], 'pending', 'Expenses tab highlights unmatched payment' );
check_status( $tabs['received'], 'pending', 'Received invoice needs validation' );
check_status( $tabs['assets'], 'neutral', 'No assets remain neutral' );
$limited = TCA_Status_Colors::tabs( array( $sale ), array( 1 => 16.01 ), true );
check_status( $limited['sales'], 'pending', 'Truncated yearly read cannot claim full completion' );
check_status( TCA_Status_Colors::loan( (object) array( 'principal' => 362.57, 'remaining' => 362.57 ) ), 'pending', 'Sponsor loan outstanding is pending, not an error' );
check_status( TCA_Status_Colors::loan( (object) array( 'principal' => 362.57, 'remaining' => 0 ) ), 'closed', 'Repaid sponsor loan is closed' );
check_status( TCA_Status_Colors::loan( (object) array( 'principal' => 362.57, 'remaining' => -5 ) ), 'error', 'Negative principal balance is a real error' );
echo "Accounting traffic-light status smoke: PASS\n";
