<?php
/**
 * Configuracion del modulo de facturas.
 */

defined('ABSPATH') || exit;

final class SEO_Facturas_Settings {

    const OPTION = 'seo_facturas_settings';

    public static function init() {
        if (is_admin()) {
            add_action('admin_init', array(__CLASS__, 'register'));
        }
    }

    public static function defaults() {
        return array(
            'enabled'                  => 0,
            'company_name'             => get_bloginfo('name'),
            'company_tax_id'           => '',
            'company_address'          => '',
            'company_postcode'         => '',
            'company_city'             => '',
            'company_region'           => '',
            'company_country'          => 'ES',
            'company_phone'            => '',
            'company_email'            => get_option('admin_email'),
            'company_website'          => home_url('/'),
            'logo_id'                  => 0,
            'invoice_series'           => 'FAC',
            'proforma_series'          => 'PRO',
            'number_padding'           => 6,
            'auto_invoice'             => 1,
            'auto_proforma'            => 1,
            'attach_to_woo_emails'     => 1,
            'invoice_email_ids'        => 'customer_processing_order,customer_completed_order',
            'proforma_email_ids'       => 'customer_on_hold_order',
            'customer_tax_meta_keys'   => '_billing_nif,_billing_cif,_billing_vat,billing_nif,billing_cif,billing_vat',
            'footer_text'              => '',
        );
    }

    public static function all() {
        $saved = get_option(self::OPTION, array());
        if (!is_array($saved)) {
            $saved = array();
        }
        return wp_parse_args($saved, self::defaults());
    }

    public static function get($key, $default = null) {
        $settings = self::all();
        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    public static function register() {
        register_setting(
            'seo_facturas_settings_group',
            self::OPTION,
            array(
                'type'              => 'array',
                'sanitize_callback' => array(__CLASS__, 'sanitize'),
                'default'           => self::defaults(),
            )
        );
    }

    public static function sanitize($input) {
        $input = is_array($input) ? $input : array();
        $defaults = self::defaults();
        $out = array();

        $out['enabled']              = empty($input['enabled']) ? 0 : 1;
        $out['auto_invoice']         = empty($input['auto_invoice']) ? 0 : 1;
        $out['auto_proforma']        = empty($input['auto_proforma']) ? 0 : 1;
        $out['attach_to_woo_emails'] = empty($input['attach_to_woo_emails']) ? 0 : 1;

        $out['company_name']       = sanitize_text_field($input['company_name'] ?? $defaults['company_name']);
        $out['company_tax_id']     = sanitize_text_field($input['company_tax_id'] ?? '');
        $out['company_address']    = sanitize_text_field($input['company_address'] ?? '');
        $out['company_postcode']   = sanitize_text_field($input['company_postcode'] ?? '');
        $out['company_city']       = sanitize_text_field($input['company_city'] ?? '');
        $out['company_region']     = sanitize_text_field($input['company_region'] ?? '');
        $out['company_country']    = sanitize_text_field($input['company_country'] ?? 'ES');
        $out['company_phone']      = sanitize_text_field($input['company_phone'] ?? '');
        $out['company_email']      = sanitize_email($input['company_email'] ?? '');
        $out['company_website']    = esc_url_raw($input['company_website'] ?? '');
        $out['logo_id']            = absint($input['logo_id'] ?? 0);
        $out['invoice_series']     = self::sanitize_series($input['invoice_series'] ?? 'FAC', 'FAC');
        $out['proforma_series']    = self::sanitize_series($input['proforma_series'] ?? 'PRO', 'PRO');
        if ($out['invoice_series'] === $out['proforma_series']) {
            $out['proforma_series'] = ('PRO' === $out['invoice_series']) ? 'PROF' : 'PRO';
        }
        $out['number_padding']     = max(3, min(10, absint($input['number_padding'] ?? 6)));
        $out['invoice_email_ids']  = self::sanitize_csv_keys($input['invoice_email_ids'] ?? $defaults['invoice_email_ids']);
        $out['proforma_email_ids'] = self::sanitize_csv_keys($input['proforma_email_ids'] ?? $defaults['proforma_email_ids']);
        $out['customer_tax_meta_keys'] = self::sanitize_csv_meta_keys($input['customer_tax_meta_keys'] ?? $defaults['customer_tax_meta_keys']);
        $out['footer_text']        = sanitize_textarea_field($input['footer_text'] ?? '');

        return $out;
    }

    public static function company_snapshot() {
        $settings = self::all();

        return array(
            'name'          => (string) $settings['company_name'],
            'tax_id'        => (string) $settings['company_tax_id'],
            'address'       => (string) $settings['company_address'],
            'postcode'      => (string) $settings['company_postcode'],
            'city'          => (string) $settings['company_city'],
            'region'        => (string) $settings['company_region'],
            'country'       => (string) $settings['company_country'],
            'phone'         => (string) $settings['company_phone'],
            'email'         => (string) $settings['company_email'],
            'website'       => (string) $settings['company_website'],
            'logo_data_uri' => self::logo_data_uri(absint($settings['logo_id'])),
            'footer_text'   => (string) $settings['footer_text'],
        );
    }

    public static function email_ids($type) {
        $key = ('invoice' === $type) ? 'invoice_email_ids' : 'proforma_email_ids';
        $raw = (string) self::get($key, '');
        $ids = array_filter(array_map('sanitize_key', array_map('trim', explode(',', $raw))));
        return array_values(array_unique($ids));
    }

    public static function customer_tax_meta_keys() {
        $raw = (string) self::get('customer_tax_meta_keys', '');
        $keys = array_filter(array_map('trim', explode(',', $raw)));
        return array_values(array_unique($keys));
    }

    private static function sanitize_series($value, $fallback) {
        $value = strtoupper((string) $value);
        $value = preg_replace('/[^A-Z0-9_-]/', '', $value);
        $value = substr($value, 0, 20);
        return '' !== $value ? $value : $fallback;
    }

    private static function sanitize_csv_keys($value) {
        $items = array_filter(array_map('sanitize_key', array_map('trim', explode(',', (string) $value))));
        return implode(',', array_values(array_unique($items)));
    }

    private static function sanitize_csv_meta_keys($value) {
        $items = array_filter(array_map('trim', explode(',', (string) $value)));
        $clean = array();
        foreach ($items as $item) {
            $item = preg_replace('/[^A-Za-z0-9_-]/', '', $item);
            if ('' !== $item) {
                $clean[] = $item;
            }
        }
        return implode(',', array_values(array_unique($clean)));
    }

    private static function logo_data_uri($attachment_id) {
        if (!$attachment_id) {
            return '';
        }

        $path = get_attached_file($attachment_id);
        if (!$path || !is_readable($path)) {
            return '';
        }

        $mime = get_post_mime_type($attachment_id);
        if (!$mime || 0 !== strpos($mime, 'image/')) {
            return '';
        }

        $bytes = file_get_contents($path);
        if (false === $bytes || '' === $bytes) {
            return '';
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }
}
