<?php
/**
 * Offline regression for optional WooCommerce sale-to-issued-invoice link.
 * No WordPress database, checkout or external services are used.
 *
 * @package TaxoCommerce_Accounting
 */

define( 'ABSPATH', __DIR__ . '/' );
function absint( $value ) {
    return abs( (int) $value );
}
require_once dirname( __DIR__ ) . '/includes/class-tca-issued-invoice.php';

function tca_invoice_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . "\n" );
        exit( 1 );
    }
}

// Accounting must function without the separate Billing plugin.
tca_invoice_assert( '' === TCA_Issued_Invoice::for_order( 274750 ), 'Billing remains optional' );

class TCA_Mock_Billing {
    public static $invoice = null;
    public static $asked_order = 0;
    public static $asked_type = '';
    public static function get_for_order( $order_id, $type ) {
        self::$asked_order = $order_id;
        self::$asked_type = $type;
        return self::$invoice;
    }
}
class_alias( 'TCA_Mock_Billing', 'SEO_Facturas_Documents' );

// A real invoice can be linked by the exact WooCommerce order ID.
TCA_Mock_Billing::$invoice = (object) array(
    'order_id' => 274750,
    'document_type' => 'invoice',
    'status' => 'issued',
    'document_number' => 'FAC-2026-000001',
);
tca_invoice_assert(
    'FAC-2026-000001' === TCA_Issued_Invoice::for_order( 274750 ),
    'A matching issued invoice pre-fills the order reference'
);
tca_invoice_assert(
    274750 === TCA_Mock_Billing::$asked_order &&
    'invoice' === TCA_Mock_Billing::$asked_type,
    'Only the invoice for this exact order is queried'
);

// Invoice from another order must never be assigned by mistake.
TCA_Mock_Billing::$invoice->order_id = 274751;
tca_invoice_assert( '' === TCA_Issued_Invoice::for_order( 274750 ), 'Reject another order invoice' );
TCA_Mock_Billing::$invoice->order_id = 274750;

// Proformas and unfinished documents are not tax invoices.
TCA_Mock_Billing::$invoice->document_type = 'proforma';
tca_invoice_assert( '' === TCA_Issued_Invoice::for_order( 274750 ), 'Reject proformas' );
TCA_Mock_Billing::$invoice->document_type = 'invoice';
TCA_Mock_Billing::$invoice->status = 'rendering';
tca_invoice_assert( '' === TCA_Issued_Invoice::for_order( 274750 ), 'Reject unfinished invoices' );
TCA_Mock_Billing::$invoice->status = 'issued';
TCA_Mock_Billing::$invoice->document_number = '';
tca_invoice_assert( '' === TCA_Issued_Invoice::for_order( 274750 ), 'Reject missing numbers' );
TCA_Mock_Billing::$invoice = null;
tca_invoice_assert( '' === TCA_Issued_Invoice::for_order( 274750 ), 'Reject absent invoices' );

// The reference cannot be silently imported from a different document type.
$source = file_get_contents( dirname( __DIR__ ) . '/taxocommerce-accounting.php' );
tca_invoice_assert( false !== $source, 'Read Accounting source' );
tca_invoice_assert(
    false !== strpos( $source, "'document_no' => TCA_Issued_Invoice::for_order( \$order->get_id() )" ),
    'New sales import the matching invoice number'
);
tca_invoice_assert(
    false !== strpos( $source, "TCA_Issued_Invoice::for_order( \$entry->source_id )" ),
    'Approving a sale resolves the actual invoice on the server'
);
tca_invoice_assert(
    false !== strpos( $source, "TCA_Issued_Invoice::for_order( \$row->source_id )" ),
    'Already-imported draft sales display current invoice without reimport'
);
tca_invoice_assert(
    false !== strpos( $source, "'wc_refund'" ),
    'Refunds remain separately reviewable; no original invoice auto-join'
);
echo "Accounting issued-invoice optional link: PASS\n";
