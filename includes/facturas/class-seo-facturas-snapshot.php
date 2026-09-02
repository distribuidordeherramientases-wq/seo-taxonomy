<?php
/**
 * Crea una copia inmutable de los datos fiscales de un pedido WooCommerce.
 */

defined('ABSPATH') || exit;

final class SEO_Facturas_Snapshot {

    public static function from_order($order, $document_type, $document_number, $issued_at) {
        if (!$order || !is_a($order, 'WC_Order')) {
            return new WP_Error('seo_facturas_invalid_order', 'Pedido WooCommerce no valido.');
        }

        $currency = (string) $order->get_currency();
        $billing = self::address_snapshot($order->get_address('billing'));
        $shipping = self::address_snapshot($order->get_address('shipping'));
        $billing['tax_id'] = self::customer_tax_id($order);

        $items = array();
        foreach ($order->get_items('line_item') as $item_id => $item) {
            $product = $item->get_product();
            $qty = (float) $item->get_quantity();
            $total = (float) $item->get_total();
            $items[] = array(
                'item_id'       => absint($item_id),
                'product_id'    => absint($item->get_product_id()),
                'variation_id'  => absint($item->get_variation_id()),
                'sku'           => $product ? (string) $product->get_sku() : '',
                'name'          => (string) $item->get_name(),
                'quantity'      => $qty,
                'subtotal'      => (float) $item->get_subtotal(),
                'subtotal_tax'  => (float) $item->get_subtotal_tax(),
                'total'         => $total,
                'total_tax'     => (float) $item->get_total_tax(),
                'unit_net'      => $qty > 0 ? ($total / $qty) : $total,
                'tax_class'     => (string) $item->get_tax_class(),
            );
        }

        $shipping_lines = array();
        foreach ($order->get_items('shipping') as $item_id => $item) {
            $shipping_lines[] = array(
                'item_id'    => absint($item_id),
                'name'       => (string) $item->get_name(),
                'method_id'  => (string) $item->get_method_id(),
                'total'      => (float) $item->get_total(),
                'total_tax'  => (float) $item->get_total_tax(),
            );
        }

        $fee_lines = array();
        foreach ($order->get_items('fee') as $item_id => $item) {
            $fee_lines[] = array(
                'item_id'   => absint($item_id),
                'name'      => (string) $item->get_name(),
                'total'     => (float) $item->get_total(),
                'total_tax' => (float) $item->get_total_tax(),
            );
        }

        $coupon_lines = array();
        foreach ($order->get_items('coupon') as $item_id => $item) {
            $coupon_lines[] = array(
                'item_id'  => absint($item_id),
                'code'     => (string) $item->get_code(),
                'discount' => (float) $item->get_discount(),
            );
        }

        $tax_lines = array();
        foreach ($order->get_items('tax') as $item_id => $item) {
            $tax_lines[] = array(
                'item_id'           => absint($item_id),
                'label'             => (string) $item->get_label(),
                'rate_id'           => absint($item->get_rate_id()),
                'rate_percent'      => self::tax_rate_percent($item->get_rate_id()),
                'tax_total'         => (float) $item->get_tax_total(),
                'shipping_tax_total'=> (float) $item->get_shipping_tax_total(),
            );
        }

        $paid_at = $order->get_date_paid();
        $created_at = $order->get_date_created();

        $snapshot = array(
            'schema_version' => 1,
            'document'       => array(
                'type'        => (string) $document_type,
                'number'      => (string) $document_number,
                'issued_at'   => (string) $issued_at,
            ),
            'seller'         => SEO_Facturas_Settings::company_snapshot(),
            'order'          => array(
                'id'                   => absint($order->get_id()),
                'number'               => (string) $order->get_order_number(),
                'status'               => (string) $order->get_status(),
                'currency'             => $currency,
                'payment_method'       => (string) $order->get_payment_method(),
                'payment_method_title' => (string) $order->get_payment_method_title(),
                'created_at'           => $created_at ? $created_at->date('Y-m-d H:i:s') : '',
                'paid_at'              => $paid_at ? $paid_at->date('Y-m-d H:i:s') : '',
                'customer_note'        => (string) $order->get_customer_note(),
            ),
            'billing'         => $billing,
            'shipping'        => $shipping,
            'items'           => $items,
            'shipping_lines'  => $shipping_lines,
            'fee_lines'       => $fee_lines,
            'coupon_lines'    => $coupon_lines,
            'tax_lines'       => $tax_lines,
            'totals'          => array(
                'subtotal_items' => (float) $order->get_subtotal(),
                'discount_total' => (float) $order->get_discount_total(),
                'shipping_total' => (float) $order->get_shipping_total(),
                'fee_total'      => self::fee_total($fee_lines),
                'total_tax'      => (float) $order->get_total_tax(),
                'total'          => (float) $order->get_total(),
                'base_total'     => max(0, (float) $order->get_total() - (float) $order->get_total_tax()),
            ),
        );

        return apply_filters('seo_facturas_order_snapshot', $snapshot, $order, $document_type);
    }

    private static function address_snapshot($address) {
        $address = is_array($address) ? $address : array();
        $country = (string) ($address['country'] ?? '');
        $state = (string) ($address['state'] ?? '');

        return array(
            'first_name'   => (string) ($address['first_name'] ?? ''),
            'last_name'    => (string) ($address['last_name'] ?? ''),
            'company'      => (string) ($address['company'] ?? ''),
            'address_1'    => (string) ($address['address_1'] ?? ''),
            'address_2'    => (string) ($address['address_2'] ?? ''),
            'postcode'     => (string) ($address['postcode'] ?? ''),
            'city'         => (string) ($address['city'] ?? ''),
            'state'        => $state,
            'state_name'   => self::state_name($country, $state),
            'country'      => $country,
            'country_name' => self::country_name($country),
            'email'        => (string) ($address['email'] ?? ''),
            'phone'        => (string) ($address['phone'] ?? ''),
        );
    }

    private static function customer_tax_id($order) {
        $value = '';
        $keys = SEO_Facturas_Settings::customer_tax_meta_keys();

        foreach ($keys as $key) {
            $candidate = trim((string) $order->get_meta($key, true));
            if ('' !== $candidate) {
                $value = $candidate;
                break;
            }
        }

        return (string) apply_filters('seo_facturas_customer_tax_id', $value, $order, $keys);
    }

    private static function tax_rate_percent($rate_id) {
        $rate_id = absint($rate_id);
        if (!$rate_id || !class_exists('WC_Tax')) {
            return null;
        }

        if (method_exists('WC_Tax', 'get_rate_percent_value')) {
            return (float) WC_Tax::get_rate_percent_value($rate_id);
        }

        if (method_exists('WC_Tax', 'get_rate_percent')) {
            $percent = (string) WC_Tax::get_rate_percent($rate_id);
            $percent = str_replace('%', '', $percent);
            return is_numeric($percent) ? (float) $percent : null;
        }

        return null;
    }

    private static function country_name($country) {
        if (function_exists('WC') && WC() && isset(WC()->countries)) {
            $countries = WC()->countries->get_countries();
            if (isset($countries[$country])) {
                return (string) $countries[$country];
            }
        }
        return $country;
    }

    private static function state_name($country, $state) {
        if (function_exists('WC') && WC() && isset(WC()->countries)) {
            $states = WC()->countries->get_states($country);
            if (is_array($states) && isset($states[$state])) {
                return (string) $states[$state];
            }
        }
        return $state;
    }

    private static function fee_total($fee_lines) {
        $total = 0.0;
        foreach ($fee_lines as $fee) {
            $total += (float) ($fee['total'] ?? 0);
        }
        return $total;
    }
}
