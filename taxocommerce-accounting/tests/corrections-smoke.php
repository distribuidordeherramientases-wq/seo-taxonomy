<?php
/**
 * Standalone safety regression for already validated accounting entries.
 * No WordPress, database, order, invoice, or remote service is changed.
 */
define( 'ABSPATH', __DIR__ . '/' );
function absint( $value ) { return abs( (int) $value ); }
function __( $message, $domain = '' ) { return $message; }
function add_action( $hook, $callback ) {}
function tca_valid_date( $value ) {
    if ( ! is_string( $value ) || ! preg_match( '/^(\\d{4})-(\\d{2})-(\\d{2})$/', $value, $parts ) ) return false;
    return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
}
function tca_decimal( $value ) {
    return is_scalar( $value ) && preg_match( '/^-?\\d{1,13}(?:\\.\\d{1,2})?$/', trim( (string) $value ) )
        ? round( (float) $value, 2 ) : null;
}
function current_time( $format ) { return '2026-11-17 15:00:00'; }
function wp_json_encode( $data ) { return json_encode( $data ); }
function get_current_user_id() { return 15; }
function tca_table() { return 'wp_taxocommerce_accounting_entries'; }
class WP_Error {
    public $code;
    public function __construct( $code, $message ) { $this->code = $code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class TCA_Issued_Invoice {
    public static $invoice = 'FAC-2026-000001';
    public static function for_order( $id ) {
        return 274750 === (int) $id ? self::$invoice : '';
    }
}
class TCA_Mock_DB {
    public $prefix = 'wp_';
    public $queries = array();
    public $update_data;
    public $updated_where;
    public $insert_data;
    public $update_ok = 1;
    public $insert_ok = 1;
    public function query( $sql ) { $this->queries[] = $sql; return 1; }
    public function update( $table, $values, $where ) {
        $this->update_data = $values;
        $this->updated_where = $where;
        return $this->update_ok;
    }
    public function insert( $table, $values ) { $this->insert_data = $values; return $this->insert_ok; }
}
$GLOBALS['wpdb'] = new TCA_Mock_DB();
require_once dirname( __DIR__ ) . '/includes/class-tca-corrections.php';

function tca_test( $ok, $message ) {
    if ( ! $ok ) {
        fwrite( STDERR, "FAIL: " . $message . "\n" );
        exit( 1 );
    }
}
$entry = (object) array(
    'id' => 2, 'source' => 'woocommerce', 'source_id' => 274750,
    'kind' => 'sale', 'fiscal_date' => '2026-11-16',
    'document_no' => 'FAC-2026-000001', 'party' => 'Cliente de prueba',
    'tax_id' => '', 'concept' => 'WooCommerce #274750',
    'amount_base' => '621.60', 'amount_tax' => '0.00',
    'amount_total' => '621.60', 'deductible_pct' => '0.00',
    'currency' => 'EUR', 'treatment' => 'domestic',
    'status' => 'validated', 'notes' => 'original',
    'updated_at' => '2026-11-17 14:29:31',
);
$input = array(
    'fiscal_date' => '2026-11-16', 'document_no' => 'MANIPULATED',
    'party' => 'Cliente de prueba', 'tax_id' => '',
    'concept' => 'WooCommerce #274750', 'amount_base' => '513.72',
    'amount_tax' => '107.88', 'deductible_pct' => '0',
    'reason' => 'Error detected in the sale VAT',
);
$new = TCA_Corrections::prepare( $entry, $input );
tca_test( ! is_wp_error( $new ), 'Corrected sale accepts valid amounts' );
tca_test( 513.72 === $new['amount_base'] && 107.88 === $new['amount_tax'] && 621.60 === $new['amount_total'], 'Recalculates total from base plus tax' );
tca_test( 'draft' === $new['status'], 'Previously validated entry returns to draft' );
tca_test( 'FAC-2026-000001' === $new['document_no'], 'Server never accepts tampered Billing invoice number' );
tca_test( 'domestic' === $entry->treatment && 'validated' === $entry->status && '0.00' === $entry->amount_tax, 'Original in-memory entry remains unchanged' );
tca_test( true === TCA_Corrections::save( $entry, $new, $input['reason'] ), 'Atomic save and log succeed' );
tca_test( array( 'START TRANSACTION', 'COMMIT' ) === $GLOBALS['wpdb']->queries, 'Correction uses a single transaction' );
tca_test( 2 === $GLOBALS['wpdb']->updated_where['id'] && 'validated' === $GLOBALS['wpdb']->updated_where['status'], 'Concurrency-safe update targets original status' );
tca_test( 15 === $GLOBALS['wpdb']->insert_data['user_id'] && 2 === $GLOBALS['wpdb']->insert_data['entry_id'], 'Audit identifies user and entry' );
tca_test( 'Error detected in the sale VAT' === $GLOBALS['wpdb']->insert_data['reason'], 'Audit retains mandatory reason' );
$old = json_decode( $GLOBALS['wpdb']->insert_data['before_data'], true );
$after = json_decode( $GLOBALS['wpdb']->insert_data['after_data'], true );
tca_test( '0.00' === $old['amount_tax'] && '107.88' === $after['amount_tax'] && 'draft' === $after['status'], 'Audit retains old and new values and status' );
$GLOBALS['wpdb'] = new TCA_Mock_DB();
$GLOBALS['wpdb']->insert_ok = 0;
tca_test( false === TCA_Corrections::save( $entry, $new, $input['reason'] ), 'Refuses unlogged ledger corrections' );
tca_test( array( 'START TRANSACTION', 'ROLLBACK' ) === $GLOBALS['wpdb']->queries, 'Failed audit rolls back ledger update' );
$GLOBALS['wpdb'] = new TCA_Mock_DB();
$GLOBALS['wpdb']->update_ok = 0;
tca_test( false === TCA_Corrections::save( $entry, $new, $input['reason'] ), 'Refuses concurrent update' );
tca_test( array( 'START TRANSACTION', 'ROLLBACK' ) === $GLOBALS['wpdb']->queries, 'Concurrent update is rolled back' );
$input['reason'] = '';
tca_test( is_wp_error( TCA_Corrections::prepare( $entry, $input ) ), 'Reason is compulsory' );
$input['reason'] = 'Correct VAT';
$input['amount_tax'] = '-5';
tca_test( is_wp_error( TCA_Corrections::prepare( $entry, $input ) ), 'Positive sales cannot have negative VAT' );
$input['amount_tax'] = '107.88';
$input['amount_base'] = '513.723';
tca_test( is_wp_error( TCA_Corrections::prepare( $entry, $input ) ), 'Rejects 3-decimal monetary input' );
$input['amount_base'] = '513.72';
$input['fiscal_date'] = '2026-02-31';
tca_test( is_wp_error( TCA_Corrections::prepare( $entry, $input ) ), 'Rejects invalid dates' );
$input['fiscal_date'] = '2026-11-16';
$entry->kind = 'purchase';
$entry->source = 'manual';
$input['document_no'] = 'T-111';
$input['amount_tax'] = '89.90';
$input['amount_base'] = '428.10';
$input['deductible_pct'] = '100';
$purchase = TCA_Corrections::prepare( $entry, $input );
tca_test( ! is_wp_error( $purchase ) && 'T-111' === $purchase['document_no'] && 518.00 === $purchase['amount_total'], 'Supplier invoices can be corrected independently' );
echo "Accounting audited fiscal correction regression: PASS\n";
