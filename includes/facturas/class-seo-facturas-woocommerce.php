<?php
/**
 * Adaptador WooCommerce. Woo sigue siendo la fuente de verdad del pedido y pago.
 */

defined('ABSPATH') || exit;

final class SEO_Facturas_WooCommerce {

    private static $initialized = false;

    public static function init() {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        add_action('woocommerce_payment_complete', array(__CLASS__, 'on_payment_complete'), 8, 1);
        add_action('woocommerce_order_status_changed', array(__CLASS__, 'on_status_changed'), 8, 4);
        add_filter('woocommerce_email_attachments', array(__CLASS__, 'email_attachments'), 9, 4);
    }

    public static function on_payment_complete($order_id) {
        if (!self::enabled() || !SEO_Facturas_Settings::get('auto_invoice', 1)) {
            return;
        }
        self::safe_issue(absint($order_id), SEO_Facturas_Documents::TYPE_INVOICE, 'payment_complete');
    }

    public static function on_status_changed($order_id, $from, $to, $order) {
        if (!self::enabled()) {
            return;
        }

        $order_id = absint($order_id);
        $to = sanitize_key($to);

        if (SEO_Facturas_Settings::get('auto_proforma', 1) && 'on-hold' === $to) {
            self::safe_issue($order_id, SEO_Facturas_Documents::TYPE_PROFORMA, 'status_on_hold');
        }

        $paid_statuses = function_exists('wc_get_is_paid_statuses') ? wc_get_is_paid_statuses() : array('processing', 'completed');
        if (SEO_Facturas_Settings::get('auto_invoice', 1) && in_array($to, $paid_statuses, true)) {
            self::safe_issue($order_id, SEO_Facturas_Documents::TYPE_INVOICE, 'status_paid');
        }
    }

    public static function email_attachments($attachments, $email_id, $object, $email = null) {
        $attachments = is_array($attachments) ? $attachments : array();

        if (!self::enabled() || !SEO_Facturas_Settings::get('attach_to_woo_emails', 1)) {
            return $attachments;
        }

        $order = self::resolve_order($object);
        if (!$order) {
            return $attachments;
        }

        $email_id = sanitize_key((string) $email_id);
        $type = null;

        if (in_array($email_id, SEO_Facturas_Settings::email_ids(SEO_Facturas_Documents::TYPE_PROFORMA), true)) {
            $type = SEO_Facturas_Documents::TYPE_PROFORMA;
        }

        if (in_array($email_id, SEO_Facturas_Settings::email_ids(SEO_Facturas_Documents::TYPE_INVOICE), true)) {
            $type = SEO_Facturas_Documents::TYPE_INVOICE;
        }

        if (!$type) {
            return $attachments;
        }

        if (SEO_Facturas_Documents::TYPE_INVOICE === $type && !$order->is_paid()) {
            return $attachments;
        }
        if (SEO_Facturas_Documents::TYPE_PROFORMA === $type && $order->is_paid()) {
            return $attachments;
        }

        $document = SEO_Facturas_Documents::issue_for_order($order->get_id(), $type);
        if (is_wp_error($document)) {
            self::log_error($document, $order->get_id(), 'email_attachment_' . $email_id);
            return $attachments;
        }

        if (!empty($document->pdf_path) && is_readable($document->pdf_path)) {
            $attachments[] = $document->pdf_path;
        }

        return array_values(array_unique($attachments));
    }

    private static function safe_issue($order_id, $type, $context) {
        $document = SEO_Facturas_Documents::issue_for_order($order_id, $type);
        if (is_wp_error($document)) {
            self::log_error($document, $order_id, $context);
        }
        return $document;
    }

    private static function resolve_order($object) {
        if (is_a($object, 'WC_Order')) {
            return $object;
        }
        if (is_numeric($object) && function_exists('wc_get_order')) {
            return wc_get_order(absint($object));
        }
        return null;
    }

    private static function enabled() {
        return (bool) SEO_Facturas_Settings::get('enabled', 0);
    }

    private static function log_error($error, $order_id, $context) {
        if (!is_wp_error($error)) {
            return;
        }

        $data = array(
            'source'   => 'seo-facturas',
            'order_id' => absint($order_id),
            'context'  => sanitize_key($context),
            'code'     => $error->get_error_code(),
        );

        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error($error->get_error_message(), $data);
            return;
        }

        error_log('[seo-facturas] ' . $error->get_error_message());
    }
}
