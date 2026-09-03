<?php
/**
 * Presupuestos PDF efimeros generados desde el carrito WooCommerce.
 *
 * No crea pedidos, clientes ni registros de presupuesto. Usa el carrito vivo
 * de WooCommerce como fuente de verdad y genera el PDF bajo demanda.
 */

defined('ABSPATH') || exit;

final class SEO_Facturas_Quotes {

    private static $initialized = false;
    private static $rendered = false;

    public static function init() {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_action('woocommerce_proceed_to_checkout', array(__CLASS__, 'render_cart_form'), 30);
        add_filter('render_block_woocommerce/cart', array(__CLASS__, 'append_to_cart_block'), 20, 2);
        add_shortcode('seo_facturas_presupuesto', array(__CLASS__, 'shortcode'));
        add_action('template_redirect', array(__CLASS__, 'handle_download'), 20);
    }

    public static function enqueue_assets() {
        if (!self::is_available() || !function_exists('is_cart') || !is_cart()) {
            return;
        }
        self::enqueue_front_assets();
    }

    public static function render_cart_form() {
        if (self::$rendered || !self::is_available()) {
            return;
        }
        self::$rendered = true;
        echo self::form_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public static function append_to_cart_block($block_content, $block) {
        if (self::$rendered || is_admin() || !self::is_available()) {
            return $block_content;
        }

        self::$rendered = true;
        return $block_content . self::form_html();
    }

    public static function shortcode() {
        if (!self::is_available()) {
            return '';
        }
        self::enqueue_front_assets();
        return self::form_html();
    }

    private static function enqueue_front_assets() {
        wp_enqueue_style(
            'seo-facturas-front',
            SEO_FACTURAS_URL . 'assets/frontend.css',
            array(),
            SEO_FACTURAS_VERSION
        );
        wp_enqueue_script(
            'seo-facturas-front',
            SEO_FACTURAS_URL . 'assets/frontend.js',
            array(),
            SEO_FACTURAS_VERSION,
            true
        );
    }

    private static function form_html() {
        if (!is_user_logged_in() && !SEO_Facturas_Settings::get('quote_guest_allowed', 1)) {
            return '<div class="woocommerce-info seo-facturas-quote-info">Los presupuestos descargables estan disponibles para clientes identificados.</div>';
        }

        $error = self::pull_session_notice('seo_facturas_quote_error');
        $s = SEO_Facturas_Settings::quote();
        $button_text = trim((string) $s['quote_button_text']);
        if ('' === $button_text) {
            $button_text = 'Descargar presupuesto';
        }

        ob_start();
        ?>
        <section class="seo-facturas-quote-box" data-seo-quote-box>
            <?php if ($error) : ?>
                <div class="woocommerce-error" role="alert"><?php echo esc_html($error); ?></div>
            <?php endif; ?>

            <button type="button" class="button alt seo-facturas-quote-toggle" data-seo-quote-toggle aria-expanded="false">
                <?php echo esc_html($button_text); ?>
            </button>

            <div class="seo-facturas-quote-panel" data-seo-quote-panel hidden>
                <h3>Preparar presupuesto</h3>
                <p class="seo-facturas-quote-help">Generaremos un PDF con los productos, descuentos, impuestos y transporte calculados actualmente por WooCommerce. No se crea ningun pedido.</p>

                <form method="post" action="<?php echo esc_url(self::cart_url()); ?>" class="seo-facturas-quote-form">
                    <?php wp_nonce_field('seo_facturas_quote_download', 'seo_facturas_quote_nonce'); ?>
                    <input type="hidden" name="seo_facturas_quote_download" value="1">

                    <?php if (!empty($s['quote_ask_company'])) : ?>
                        <p class="form-row form-row-wide">
                            <label for="seo-quote-company">Empresa / nombre</label>
                            <input type="text" id="seo-quote-company" name="seo_quote_company" maxlength="160" autocomplete="organization">
                        </p>
                    <?php endif; ?>

                    <?php if (!empty($s['quote_ask_tax_id'])) : ?>
                        <p class="form-row form-row-first">
                            <label for="seo-quote-tax-id">NIF / CIF</label>
                            <input type="text" id="seo-quote-tax-id" name="seo_quote_tax_id" maxlength="60">
                        </p>
                    <?php endif; ?>

                    <?php if (!empty($s['quote_ask_contact'])) : ?>
                        <p class="form-row form-row-last">
                            <label for="seo-quote-contact">Persona de contacto</label>
                            <input type="text" id="seo-quote-contact" name="seo_quote_contact" maxlength="160" autocomplete="name">
                        </p>
                    <?php endif; ?>

                    <?php if (!empty($s['quote_ask_email'])) : ?>
                        <p class="form-row form-row-wide">
                            <label for="seo-quote-email">Email<?php echo !empty($s['quote_require_email']) ? ' *' : ' (opcional)'; ?></label>
                            <input type="email" id="seo-quote-email" name="seo_quote_email" maxlength="190" autocomplete="email" <?php echo !empty($s['quote_require_email']) ? 'required' : ''; ?>>
                        </p>
                    <?php endif; ?>

                    <div class="clear"></div>
                    <p class="seo-facturas-quote-validity">Validez configurada: <strong><?php echo esc_html(absint($s['quote_validity_days'])); ?> dias</strong>. El presupuesto no reserva stock.</p>
                    <button type="submit" class="button alt seo-facturas-quote-submit"><?php echo esc_html($button_text); ?> PDF</button>
                </form>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    public static function handle_download() {
        if ('POST' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) {
            return;
        }
        if (empty($_POST['seo_facturas_quote_download'])) {
            return;
        }

        if (!self::is_available()) {
            self::fail('La descarga de presupuestos no esta disponible.');
        }

        $nonce = isset($_POST['seo_facturas_quote_nonce']) ? sanitize_text_field(wp_unslash($_POST['seo_facturas_quote_nonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'seo_facturas_quote_download')) {
            self::fail('La solicitud de presupuesto ha caducado. Recarga el carrito y vuelve a intentarlo.');
        }

        if (!is_user_logged_in() && !SEO_Facturas_Settings::get('quote_guest_allowed', 1)) {
            self::fail('Debes iniciar sesion para descargar un presupuesto.');
        }

        if (function_exists('wc_load_cart') && (!function_exists('WC') || !WC() || !WC()->cart)) {
            wc_load_cart();
        }

        if (!function_exists('WC') || !WC() || !WC()->cart || WC()->cart->is_empty()) {
            self::fail('El carrito esta vacio.');
        }

        $rate_error = self::check_rate_limit();
        if (is_wp_error($rate_error)) {
            self::fail($rate_error->get_error_message());
        }

        $buyer = self::buyer_from_request();
        if (is_wp_error($buyer)) {
            self::fail($buyer->get_error_message());
        }

        $engine = SEO_Facturas_PDF::engine_status();
        if (empty($engine['available'])) {
            self::fail('No se puede generar el PDF porque Dompdf no esta instalado en includes/facturas/vendor/.');
        }

        WC()->cart->calculate_totals();

        if (
            SEO_Facturas_Settings::get('quote_show_shipping', 1)
            && WC()->cart->needs_shipping()
            && WC()->customer
            && is_callable(array(WC()->customer, 'has_calculated_shipping'))
            && !WC()->customer->has_calculated_shipping()
        ) {
            self::fail('Para generar un presupuesto completo, indica primero el destino de envio en el carrito para que WooCommerce calcule el transporte.');
        }

        $numbering = SEO_Facturas_Documents::reserve_number(SEO_Facturas_Documents::TYPE_QUOTE);
        if (is_wp_error($numbering)) {
            self::fail($numbering->get_error_message());
        }

        $snapshot = self::snapshot_from_cart($buyer, $numbering);
        if (is_wp_error($snapshot)) {
            self::fail($snapshot->get_error_message());
        }

        $html = SEO_Facturas_PDF::render_html($snapshot);
        if (is_wp_error($html)) {
            self::fail($html->get_error_message());
        }

        $binary = SEO_Facturas_PDF::render_binary($html, (string) $numbering['number'], 0);
        if (is_wp_error($binary)) {
            self::fail($binary->get_error_message());
        }

        if (SEO_Facturas_Settings::get('quote_send_email_copy', 0) && !empty($buyer['email'])) {
            self::email_copy((string) $buyer['email'], (string) $numbering['number'], $binary);
        }

        self::mark_rate_limit();

        $filename = sanitize_file_name((string) $numbering['number'] . '.pdf');
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($binary));
        header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        echo $binary; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    private static function buyer_from_request() {
        $s = SEO_Facturas_Settings::quote();
        $buyer = array(
            'company' => sanitize_text_field(wp_unslash($_POST['seo_quote_company'] ?? '')),
            'tax_id'  => sanitize_text_field(wp_unslash($_POST['seo_quote_tax_id'] ?? '')),
            'contact' => sanitize_text_field(wp_unslash($_POST['seo_quote_contact'] ?? '')),
            'email'   => sanitize_email(wp_unslash($_POST['seo_quote_email'] ?? '')),
        );

        if (!empty($s['quote_require_email']) && empty($buyer['email'])) {
            return new WP_Error('seo_facturas_quote_email_required', 'Introduce un email valido para generar el presupuesto.');
        }

        return $buyer;
    }

    private static function snapshot_from_cart(array $buyer, array $numbering) {
        $cart = WC()->cart;
        $currency = get_woocommerce_currency();
        $profile = SEO_Facturas_Settings::document_profile(SEO_Facturas_Documents::TYPE_QUOTE);
        $validity_days = max(1, absint($profile['validity_days'] ?? 15));
        if (function_exists('current_datetime')) {
            $valid_until = current_datetime()->modify('+' . $validity_days . ' days')->format('Y-m-d H:i:s');
        } else {
            $valid_until = date('Y-m-d H:i:s', time() + ($validity_days * DAY_IN_SECONDS));
        }

        $items = array();
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product = isset($cart_item['data']) && is_a($cart_item['data'], 'WC_Product') ? $cart_item['data'] : null;
            $qty = max(0.0, (float) ($cart_item['quantity'] ?? 0));
            $line_subtotal = (float) ($cart_item['line_subtotal'] ?? 0);
            $line_total = (float) ($cart_item['line_total'] ?? 0);

            $items[] = array(
                'cart_item_key' => sanitize_text_field((string) $cart_item_key),
                'product_id'    => absint($cart_item['product_id'] ?? 0),
                'variation_id'  => absint($cart_item['variation_id'] ?? 0),
                'sku'           => $product ? (string) $product->get_sku() : '',
                'name'          => $product ? (string) $product->get_name() : '',
                'quantity'      => $qty,
                'subtotal'      => $line_subtotal,
                'subtotal_tax'  => (float) ($cart_item['line_subtotal_tax'] ?? 0),
                'total'         => $line_total,
                'total_tax'     => (float) ($cart_item['line_tax'] ?? 0),
                'unit_net'      => $qty > 0 ? ($line_total / $qty) : $line_total,
                'image_data_uri'=> !empty($profile['show_images']) ? self::product_image_data_uri($product) : '',
            );
        }

        if (!$items) {
            return new WP_Error('seo_facturas_quote_items_empty', 'No hay productos que incluir en el presupuesto.');
        }

        $tax_lines = array();
        foreach ((array) $cart->get_tax_totals() as $tax) {
            $tax_lines[] = array(
                'label'       => isset($tax->label) ? (string) $tax->label : 'Impuesto',
                'tax_total'   => isset($tax->amount) ? (float) $tax->amount : 0.0,
                'rate_percent'=> null,
            );
        }

        $coupons = array();
        foreach ((array) $cart->get_coupons() as $code => $coupon) {
            $coupons[] = array(
                'code'     => sanitize_text_field((string) $code),
                'discount' => (float) $cart->get_coupon_discount_amount($code, false),
            );
        }

        $customer = WC()->customer;
        $billing = self::customer_address($customer, 'billing');
        $shipping = self::customer_address($customer, 'shipping');

        if (!empty($buyer['company'])) {
            $billing['company'] = (string) $buyer['company'];
        }
        if (!empty($buyer['contact'])) {
            $billing['contact'] = (string) $buyer['contact'];
        }
        if (!empty($buyer['email'])) {
            $billing['email'] = (string) $buyer['email'];
        }
        $billing['tax_id'] = (string) $buyer['tax_id'];

        $snapshot = array(
            'schema_version' => 2,
            'document'       => array_merge(
                array(
                    'type'        => SEO_Facturas_Documents::TYPE_QUOTE,
                    'number'      => (string) $numbering['number'],
                    'issued_at'   => (string) $numbering['issued_at'],
                    'valid_until' => $valid_until,
                ),
                $profile
            ),
            'seller'         => SEO_Facturas_Settings::company_snapshot(),
            'cart'           => array(
                'currency' => $currency,
            ),
            'billing'        => $billing,
            'shipping'       => $shipping,
            'items'          => $items,
            'coupon_lines'   => $coupons,
            'tax_lines'      => $tax_lines,
            'totals'         => array(
                'subtotal_items' => (float) $cart->get_subtotal(),
                'discount_total' => (float) $cart->get_discount_total(),
                'shipping_total' => (float) $cart->get_shipping_total(),
                'fee_total'      => (float) $cart->get_fee_total(),
                'total_tax'      => (float) $cart->get_total_tax(),
                'total'          => (float) $cart->get_total('edit'),
                'base_total'     => max(0, (float) $cart->get_total('edit') - (float) $cart->get_total_tax()),
            ),
        );

        return apply_filters('seo_facturas_quote_snapshot', $snapshot, $cart, $buyer);
    }

    private static function customer_address($customer, $kind) {
        $kind = ('shipping' === $kind) ? 'shipping' : 'billing';
        if (!$customer) {
            return array();
        }

        $getter = static function ($field) use ($customer, $kind) {
            $method = 'get_' . $kind . '_' . $field;
            return is_callable(array($customer, $method)) ? (string) $customer->{$method}() : '';
        };

        $country = $getter('country');
        $state = $getter('state');

        return array(
            'first_name'   => $getter('first_name'),
            'last_name'    => $getter('last_name'),
            'company'      => $getter('company'),
            'contact'      => '',
            'address_1'    => $getter('address_1'),
            'address_2'    => $getter('address_2'),
            'postcode'     => $getter('postcode'),
            'city'         => $getter('city'),
            'state'        => $state,
            'state_name'   => self::state_name($country, $state),
            'country'      => $country,
            'country_name' => self::country_name($country),
            'email'        => ('billing' === $kind) ? $getter('email') : '',
            'phone'        => ('billing' === $kind) ? $getter('phone') : '',
            'tax_id'       => '',
        );
    }

    private static function country_name($country) {
        if (function_exists('WC') && WC() && isset(WC()->countries)) {
            $countries = WC()->countries->get_countries();
            if (isset($countries[$country])) {
                return (string) $countries[$country];
            }
        }
        return (string) $country;
    }

    private static function state_name($country, $state) {
        if (function_exists('WC') && WC() && isset(WC()->countries)) {
            $states = WC()->countries->get_states($country);
            if (is_array($states) && isset($states[$state])) {
                return (string) $states[$state];
            }
        }
        return (string) $state;
    }

    private static function product_image_data_uri($product) {
        if (!$product || !is_a($product, 'WC_Product')) {
            return '';
        }

        $attachment_id = absint($product->get_image_id());
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
        if (!is_string($bytes) || '' === $bytes) {
            return '';
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    private static function email_copy($to, $number, $binary) {
        $to = sanitize_email($to);
        if (!$to || !is_string($binary) || '' === $binary) {
            return false;
        }

        $tmp_base = wp_tempnam(sanitize_file_name($number));
        if (!$tmp_base) {
            return false;
        }
        $tmp = $tmp_base . '.pdf';
        @unlink($tmp_base);

        $written = file_put_contents($tmp, $binary, LOCK_EX);
        if (false === $written || $written <= 0) {
            @unlink($tmp);
            return false;
        }

        $subject = 'Presupuesto ' . $number;
        $message = '<p>Adjuntamos el presupuesto <strong>' . esc_html($number) . '</strong> generado desde el carrito.</p>';
        $sent = wp_mail(
            $to,
            apply_filters('seo_facturas_quote_email_subject', $subject, $number),
            apply_filters('seo_facturas_quote_email_message', $message, $number),
            array('Content-Type: text/html; charset=UTF-8'),
            array($tmp)
        );
        @unlink($tmp);
        return (bool) $sent;
    }

    private static function check_rate_limit() {
        if (!function_exists('WC') || !WC() || !WC()->session) {
            return true;
        }

        $last = absint(WC()->session->get('seo_facturas_quote_last_ts', 0));
        if ($last && (time() - $last) < 3) {
            return new WP_Error('seo_facturas_quote_too_fast', 'Espera unos segundos antes de generar otro presupuesto.');
        }

        $bucket = wp_date('YmdH');
        $stored_bucket = (string) WC()->session->get('seo_facturas_quote_hour_bucket', '');
        $count = absint(WC()->session->get('seo_facturas_quote_hour_count', 0));
        if ($stored_bucket !== $bucket) {
            return true;
        }

        $limit = max(1, absint(SEO_Facturas_Settings::get('quote_hourly_limit', 20)));
        if ($count >= $limit) {
            return new WP_Error('seo_facturas_quote_limit', 'Se ha alcanzado temporalmente el limite de presupuestos de esta sesion.');
        }

        return true;
    }

    private static function mark_rate_limit() {
        if (!function_exists('WC') || !WC() || !WC()->session) {
            return;
        }

        $bucket = wp_date('YmdH');
        $stored_bucket = (string) WC()->session->get('seo_facturas_quote_hour_bucket', '');
        $count = absint(WC()->session->get('seo_facturas_quote_hour_count', 0));
        if ($stored_bucket !== $bucket) {
            $count = 0;
        }

        WC()->session->set('seo_facturas_quote_hour_bucket', $bucket);
        WC()->session->set('seo_facturas_quote_hour_count', $count + 1);
        WC()->session->set('seo_facturas_quote_last_ts', time());
    }

    private static function is_available() {
        return (bool) (
            SEO_Facturas_Settings::get('enabled', 0)
            && SEO_Facturas_Settings::get('quote_enabled', 0)
            && class_exists('WooCommerce')
        );
    }

    private static function cart_url() {
        if (function_exists('wc_get_cart_url')) {
            return wc_get_cart_url();
        }
        return home_url('/cart/');
    }

    private static function fail($message) {
        self::set_session_notice('seo_facturas_quote_error', sanitize_text_field((string) $message));
        if (!headers_sent()) {
            wp_safe_redirect(self::cart_url());
            exit;
        }
        wp_die(esc_html((string) $message));
    }

    private static function set_session_notice($key, $message) {
        if (function_exists('WC') && WC() && WC()->session) {
            WC()->session->set($key, $message);
        }
    }

    private static function pull_session_notice($key) {
        if (!function_exists('WC') || !WC() || !WC()->session) {
            return '';
        }
        $message = (string) WC()->session->get($key, '');
        if ('' !== $message) {
            WC()->session->__unset($key);
        }
        return $message;
    }
}
