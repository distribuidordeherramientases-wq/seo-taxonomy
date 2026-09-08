<?php
/**
 * Gestion fiscal centralizada para Espana.
 *
 * Las reglas se configuran desde Facturas y presupuestos > Fiscalidad, pero
 * los importes se siguen calculando dentro de WooCommerce. Los documentos
 * copian despues los totales del carrito/pedido, evitando recalculos distintos.
 */

defined('ABSPATH') || exit;

final class SEO_Facturas_Tax {

    const IDS_OPTION  = 'seo_facturas_tax_rate_ids';
    const HASH_OPTION = 'seo_facturas_tax_rate_hash';
    const TAX_CLASS   = 'seo-facturas-managed';

    private static $initialized = false;
    private static $rate_exists_cache = array();

    public static function init() {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        add_filter('woocommerce_find_rates', array(__CLASS__, 'filter_rates'), 99, 2);
        add_filter('woocommerce_countries_allowed_countries', array(__CLASS__, 'allowed_countries'), 99, 1);
        add_filter('woocommerce_countries_shipping_countries', array(__CLASS__, 'allowed_countries'), 99, 1);

        if (self::enabled()) {
            self::ensure_managed_rates();
        }
    }

    public static function enabled() {
        return (bool) SEO_Facturas_Settings::get('tax_manager_enabled', 0);
    }

    /**
     * Guarda/actualiza las tasas tecnicas que WooCommerce necesita para poder
     * asociar un rate_id real a los impuestos del pedido. Usamos una clase
     * fiscal interna para que estas filas no interfieran cuando el gestor esta
     * desactivado.
     */
    public static function sync_managed_rates($settings = null) {
        if (!class_exists('WooCommerce') || !class_exists('WC_Tax')) {
            return new WP_Error('seo_facturas_tax_woocommerce_missing', 'WooCommerce no esta disponible para sincronizar las reglas fiscales.');
        }

        $settings = is_array($settings) ? $settings : SEO_Facturas_Settings::taxation();
        if (empty($settings['tax_manager_enabled'])) {
            return true;
        }

        update_option('woocommerce_calc_taxes', 'yes');

        $zones = self::zone_definitions($settings);
        $ids = get_option(self::IDS_OPTION, array());
        $ids = is_array($ids) ? $ids : array();

        foreach ($zones as $zone => $definition) {
            $current_id = absint($ids[$zone] ?? 0);
            $data = array(
                'tax_rate_country'  => 'ES',
                'tax_rate_state'    => '',
                'tax_rate'          => self::format_rate($definition['rate']),
                'tax_rate_name'     => (string) $definition['label'],
                'tax_rate_priority' => 1,
                'tax_rate_compound' => 0,
                'tax_rate_shipping' => empty($settings['tax_shipping']) ? 0 : 1,
                'tax_rate_order'    => 0,
                'tax_rate_class'    => self::TAX_CLASS,
            );

            if ($current_id && self::rate_exists($current_id)) {
                self::update_rate($current_id, $data);
                $ids[$zone] = $current_id;
                continue;
            }

            $new_id = self::insert_rate($data);
            if (!$new_id) {
                return new WP_Error('seo_facturas_tax_rate_create_failed', 'No se ha podido crear la tasa fiscal interna de ' . $definition['name'] . '.');
            }
            $ids[$zone] = $new_id;
        }

        update_option(self::IDS_OPTION, $ids, false);
        update_option(self::HASH_OPTION, self::settings_hash($settings), false);
        self::$rate_exists_cache = array();
        self::flush_tax_cache();

        return true;
    }

    private static function ensure_managed_rates() {
        $settings = SEO_Facturas_Settings::taxation();
        $ids = get_option(self::IDS_OPTION, array());
        $ids = is_array($ids) ? $ids : array();
        $expected_hash = self::settings_hash($settings);
        $saved_hash = (string) get_option(self::HASH_OPTION, '');

        $complete = true;
        foreach (array_keys(self::zone_definitions($settings)) as $zone) {
            $id = absint($ids[$zone] ?? 0);
            if (!$id || !self::rate_exists($id)) {
                $complete = false;
                break;
            }
        }

        if ($complete && hash_equals($expected_hash, $saved_hash)) {
            return;
        }

        self::sync_managed_rates($settings);
    }

    public static function filter_rates($rates, $args) {
        if (!self::enabled()) {
            return $rates;
        }

        $args = is_array($args) ? $args : array();
        $country = strtoupper((string) ($args['country'] ?? ''));
        $state = strtoupper((string) ($args['state'] ?? ''));
        $postcode = strtoupper((string) ($args['postcode'] ?? ''));

        if ('' === $country && SEO_Facturas_Settings::get('tax_restrict_to_spain', 1)) {
            $country = 'ES';
        }

        if ('ES' !== $country) {
            return empty(SEO_Facturas_Settings::get('tax_restrict_to_spain', 1)) ? $rates : array();
        }

        $zone = self::classify_location($country, $state, $postcode);
        $settings = SEO_Facturas_Settings::taxation();
        $definitions = self::zone_definitions($settings);
        if (!isset($definitions[$zone])) {
            $zone = 'peninsula';
        }

        $ids = get_option(self::IDS_OPTION, array());
        $rate_id = absint(is_array($ids) ? ($ids[$zone] ?? 0) : 0);
        if (!$rate_id || !self::rate_exists($rate_id)) {
            return $rates;
        }

        $definition = $definitions[$zone];

        return array(
            $rate_id => array(
                'rate'     => self::format_rate($definition['rate']),
                'label'    => (string) $definition['label'],
                'shipping' => empty($settings['tax_shipping']) ? 'no' : 'yes',
                'compound' => 'no',
            ),
        );
    }

    public static function allowed_countries($countries) {
        if (!self::enabled() || !SEO_Facturas_Settings::get('tax_restrict_to_spain', 1)) {
            return $countries;
        }

        $label = 'España';
        if (is_array($countries) && isset($countries['ES'])) {
            $label = $countries['ES'];
        } elseif (function_exists('WC') && WC() && isset(WC()->countries)) {
            $all = WC()->countries->get_countries();
            if (is_array($all) && isset($all['ES'])) {
                $label = $all['ES'];
            }
        }

        return array('ES' => $label);
    }

    public static function classify_location($country, $state = '', $postcode = '') {
        $country = strtoupper(trim((string) $country));
        $state = strtoupper(trim((string) $state));
        $postcode = preg_replace('/[^0-9]/', '', (string) $postcode);
        $prefix = strlen($postcode) >= 2 ? substr($postcode, 0, 2) : '';

        if ('ES' !== $country) {
            return 'other';
        }
        if ('PM' === $state || '07' === $prefix) {
            return 'baleares';
        }
        if (in_array($state, array('GC', 'TF'), true) || in_array($prefix, array('35', '38'), true)) {
            return 'canarias';
        }
        if ('CE' === $state || '51' === $prefix) {
            return 'ceuta';
        }
        if ('ML' === $state || '52' === $prefix) {
            return 'melilla';
        }
        return 'peninsula';
    }

    public static function context_for_location($country, $state = '', $postcode = '') {
        $settings = SEO_Facturas_Settings::taxation();
        $zone = self::classify_location($country, $state, $postcode);
        $definitions = self::zone_definitions($settings);
        $definition = $definitions[$zone] ?? null;

        if (!$definition) {
            return array(
                'enabled'    => self::enabled(),
                'zone'       => 'other',
                'zone_label' => 'Fuera de España',
                'rate'       => null,
                'label'      => 'Impuestos',
                'note'       => '',
            );
        }

        return array(
            'enabled'    => self::enabled(),
            'zone'       => $zone,
            'zone_label' => (string) $definition['name'],
            'rate'       => (float) $definition['rate'],
            'label'      => (string) $definition['label'],
            'note'       => in_array($zone, array('canarias', 'ceuta', 'melilla'), true) ? (string) ($settings['tax_special_note'] ?? '') : '',
        );
    }

    public static function current_context() {
        $country = 'ES';
        $state = '';
        $postcode = '';

        if (function_exists('WC') && WC() && WC()->customer) {
            $customer = WC()->customer;
            $country = (string) $customer->get_shipping_country();
            $state = (string) $customer->get_shipping_state();
            $postcode = (string) $customer->get_shipping_postcode();

            if ('' === $country) {
                $country = (string) $customer->get_billing_country();
                $state = (string) $customer->get_billing_state();
                $postcode = (string) $customer->get_billing_postcode();
            }
        }

        if ('' === $country) {
            $country = 'ES';
        }

        return self::context_for_location($country, $state, $postcode);
    }

    public static function zone_definitions($settings = null) {
        $settings = is_array($settings) ? $settings : SEO_Facturas_Settings::taxation();

        return array(
            'peninsula' => array(
                'name'  => 'Península',
                'rate'  => (float) ($settings['tax_peninsula_rate'] ?? 21),
                'label' => self::rate_label((float) ($settings['tax_peninsula_rate'] ?? 21)),
            ),
            'baleares' => array(
                'name'  => 'Islas Baleares',
                'rate'  => (float) ($settings['tax_baleares_rate'] ?? 21),
                'label' => self::rate_label((float) ($settings['tax_baleares_rate'] ?? 21)),
            ),
            'canarias' => array(
                'name'  => 'Canarias',
                'rate'  => (float) ($settings['tax_canarias_rate'] ?? 0),
                'label' => self::rate_label((float) ($settings['tax_canarias_rate'] ?? 0)),
            ),
            'ceuta' => array(
                'name'  => 'Ceuta',
                'rate'  => (float) ($settings['tax_ceuta_rate'] ?? 0),
                'label' => self::rate_label((float) ($settings['tax_ceuta_rate'] ?? 0)),
            ),
            'melilla' => array(
                'name'  => 'Melilla',
                'rate'  => (float) ($settings['tax_melilla_rate'] ?? 0),
                'label' => self::rate_label((float) ($settings['tax_melilla_rate'] ?? 0)),
            ),
        );
    }

    private static function rate_label($rate) {
        $rate = (float) $rate;
        $formatted = rtrim(rtrim(number_format($rate, 3, '.', ''), '0'), '.');
        return 'IVA ' . $formatted . '%';
    }

    private static function format_rate($rate) {
        return number_format(max(0, min(100, (float) $rate)), 4, '.', '');
    }

    private static function settings_hash($settings) {
        $keys = array(
            'tax_manager_enabled', 'tax_restrict_to_spain', 'tax_shipping',
            'tax_peninsula_rate', 'tax_baleares_rate', 'tax_canarias_rate',
            'tax_ceuta_rate', 'tax_melilla_rate',
        );
        $data = array();
        foreach ($keys as $key) {
            $data[$key] = $settings[$key] ?? null;
        }
        return md5(wp_json_encode($data));
    }

    private static function rate_exists($rate_id) {
        global $wpdb;
        $rate_id = absint($rate_id);
        if (!$rate_id || empty($wpdb->prefix)) {
            return false;
        }
        if (array_key_exists($rate_id, self::$rate_exists_cache)) {
            return self::$rate_exists_cache[$rate_id];
        }
        $table = $wpdb->prefix . 'woocommerce_tax_rates';
        $found = $wpdb->get_var($wpdb->prepare("SELECT tax_rate_id FROM {$table} WHERE tax_rate_id = %d LIMIT 1", $rate_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        self::$rate_exists_cache[$rate_id] = (bool) $found;
        return self::$rate_exists_cache[$rate_id];
    }

    private static function insert_rate(array $data) {
        if (method_exists('WC_Tax', '_insert_tax_rate')) {
            return absint(WC_Tax::_insert_tax_rate($data));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'woocommerce_tax_rates';
        $inserted = $wpdb->insert($table, $data, array('%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s'));
        return $inserted ? absint($wpdb->insert_id) : 0;
    }

    private static function update_rate($rate_id, array $data) {
        if (method_exists('WC_Tax', '_update_tax_rate')) {
            WC_Tax::_update_tax_rate(absint($rate_id), $data);
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'woocommerce_tax_rates';
        $wpdb->update(
            $table,
            $data,
            array('tax_rate_id' => absint($rate_id)),
            array('%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s'),
            array('%d')
        );
    }

    private static function flush_tax_cache() {
        if (class_exists('WC_Cache_Helper') && method_exists('WC_Cache_Helper', 'invalidate_cache_group')) {
            WC_Cache_Helper::invalidate_cache_group('taxes');
        }
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients();
        }
    }
}
