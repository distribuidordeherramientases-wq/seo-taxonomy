<?php
/**
 * Optional read-only lookup of the invoice issued for a WooCommerce order.
 *
 * Billing is not a required plugin. Independent shops can keep entering
 * external invoice references manually when Billing is not installed.
 *
 * @package TaxoCommerce_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class TCA_Issued_Invoice {

    /**
     * Return the actual issued invoice number for one order, if available.
     * Never associate a proforma, quotation or credit note with a sale.
     *
     * @param int $order_id WooCommerce order ID.
     * @return string Issued invoice number, or an empty string.
     */
    public static function for_order( $order_id ) {
        $order_id = absint( $order_id );
        if ( ! $order_id || ! class_exists( 'SEO_Facturas_Documents' ) ||
            ! method_exists( 'SEO_Facturas_Documents', 'get_for_order' ) ) {
            return '';
        }

        $invoice = SEO_Facturas_Documents::get_for_order( $order_id, 'invoice' );

        if ( ! is_object( $invoice ) ||
            ! isset( $invoice->order_id, $invoice->document_type, $invoice->status, $invoice->document_number ) ||
            $order_id !== absint( $invoice->order_id ) ||
            'invoice' !== (string) $invoice->document_type ||
            'issued' !== (string) $invoice->status ) {
            return '';
        }

        $number = trim( (string) $invoice->document_number );
        return '' !== $number && strlen( $number ) <= 100 ? $number : '';
    }
}
