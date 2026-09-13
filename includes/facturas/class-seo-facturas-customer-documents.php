<?php
/**
 * Descarga segura de proformas y facturas para el cliente.
 *
 * - Proforma: solo para pedidos existentes que aun no estan pagados.
 * - Factura: solo cuando WooCommerce considera pagado el pedido.
 * - Compatible con compra como invitado mediante enlaces firmados y temporales.
 */

defined('ABSPATH') || exit;

final class SEO_Facturas_Customer_Documents {

    private static $initialized = false;
    private static $rendered_orders = array();

    public static function init() {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_page_assets'));
        add_action('woocommerce_thankyou', array(__CLASS__, 'render_for_order'), 20, 1);
        add_action('woocommerce_view_order', array(__CLASS__, 'render_for_order'), 20, 1);
        add_action('seo_facturas_customer_order_documents', array(__CLASS__, 'render_for_order'), 10, 1);
        add_action('template_redirect', array(__CLASS__, 'handle_download'), 12);
    }

    public static function enqueue_page_assets() {
        if (!SEO_Facturas_Settings::get('enabled', 0)) {
            return;
        }

        $checkout = function_exists('is_checkout') && is_checkout();
        $account = function_exists('is_account_page') && is_account_page();
        if ($checkout || $account) {
            self::enqueue_assets();
        }
    }

    public static function render_for_order($order_id) {
        $order_id = absint($order_id);
        if (!$order_id || isset(self::$rendered_orders[$order_id])) {
            return;
        }

        if (!SEO_Facturas_Settings::get('enabled', 0) || !function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order || !self::can_view_order($order)) {
            return;
        }

        self::$rendered_orders[$order_id] = true;
        self::enqueue_assets();

        $is_paid = (bool) $order->is_paid();
        $type = '';
        $button = '';
        $title = 'Documentacion del pedido';
        $message = '';

        if ($is_paid && SEO_Facturas_Settings::get('invoice_enabled', 1)) {
            $type = SEO_Facturas_Documents::TYPE_INVOICE;
            $button = 'DESCARGAR FACTURA PDF';
            $title = 'Factura disponible';
            $message = 'WooCommerce confirma que el pedido esta pagado. La factura fiscal puede descargarse ahora.';
        } elseif (!$is_paid && self::proforma_allowed_for_order($order) && SEO_Facturas_Settings::get('proforma_enabled', 1)) {
            $type = SEO_Facturas_Documents::TYPE_PROFORMA;
            $button = 'DESCARGAR PROFORMA PDF';
            $title = 'Pedido pendiente de pago';
            $message = 'Puedes descargar la proforma del pedido. La factura definitiva se emitira unicamente cuando WooCommerce confirme el pago.';
        } elseif (!$is_paid) {
            $title = 'Factura pendiente de pago';
            $message = 'La factura definitiva se generara cuando WooCommerce confirme el pago del pedido.';
        }

        $url = $type ? self::download_url($order, $type) : '';
        ?>
        <section class="seo-facturas-customer-docs" aria-label="Documentos del pedido">
            <h2><?php echo esc_html($title); ?></h2>
            <p><?php echo esc_html($message); ?></p>

            <?php if ($url && $button) : ?>
                <a class="button alt seo-facturas-customer-download" href="<?php echo esc_url($url); ?>">
                    <?php echo esc_html($button); ?>
                </a>
            <?php endif; ?>

            <?php if (!$is_paid) : ?>
                <p class="seo-facturas-customer-note">
                    <strong>Factura:</strong> este pedido todavia no figura como pagado. No se emite una factura fiscal de borrador. Cuando el pago quede confirmado, la factura definitiva quedara vinculada al pedido y se enviara segun la configuracion de emails de WooCommerce.
                </p>
            <?php endif; ?>
        </section>
        <?php
    }

    public static function handle_download() {
        if (empty($_GET['seo_facturas_document_download'])) {
            return;
        }

        if (!SEO_Facturas_Settings::get('enabled', 0) || !function_exists('wc_get_order')) {
            self::deny('El sistema documental no esta disponible.');
        }

        $order_id = absint($_GET['order_id'] ?? 0);
        $type = sanitize_key(wp_unslash($_GET['document_type'] ?? ''));
        $expires = absint($_GET['expires'] ?? 0);
        $signature = sanitize_text_field(wp_unslash($_GET['signature'] ?? ''));

        if (!$order_id || !in_array($type, array(SEO_Facturas_Documents::TYPE_INVOICE, SEO_Facturas_Documents::TYPE_PROFORMA), true)) {
            self::deny('Solicitud de documento no valida.');
        }

        if (!$expires || time() > $expires) {
            self::deny('El enlace de descarga ha caducado. Vuelve al pedido para generar uno nuevo.');
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            self::deny('No se encuentra el pedido.');
        }

        $expected = self::signature($order, $type, $expires);
        if (!$signature || !hash_equals($expected, $signature)) {
            self::deny('El enlace de descarga no es valido.');
        }

        if (SEO_Facturas_Documents::TYPE_INVOICE === $type) {
            if (!SEO_Facturas_Settings::get('invoice_enabled', 1)) {
                self::deny('La descarga de facturas esta desactivada.');
            }
            if (!$order->is_paid()) {
                self::deny('La factura no esta disponible porque WooCommerce todavia no considera pagado el pedido.');
            }
        }

        if (SEO_Facturas_Documents::TYPE_PROFORMA === $type) {
            if (!SEO_Facturas_Settings::get('proforma_enabled', 1)) {
                self::deny('La descarga de proformas esta desactivada.');
            }
            if ($order->is_paid()) {
                self::deny('El pedido ya esta pagado. Descarga la factura definitiva desde el pedido.');
            }
            if (!self::proforma_allowed_for_order($order)) {
                self::deny('No se genera proforma para el estado actual del pedido.');
            }
        }

        $document = SEO_Facturas_Documents::issue_for_order($order_id, $type);
        if (is_wp_error($document)) {
            self::deny($document->get_error_message());
        }

        if (empty($document->pdf_path) || !is_readable($document->pdf_path) || !self::allowed_pdf_path($document->pdf_path)) {
            self::deny('El PDF no esta disponible o su ruta no es valida.');
        }

        $filename = sanitize_file_name((string) $document->document_number . '.pdf');
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($document->pdf_path));
        header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        readfile($document->pdf_path);
        exit;
    }

    private static function download_url($order, $type) {
        $ttl = (int) apply_filters('seo_facturas_customer_download_ttl', 30 * DAY_IN_SECONDS, $order, $type);
        $ttl = max(HOUR_IN_SECONDS, min(90 * DAY_IN_SECONDS, $ttl));
        $expires = time() + $ttl;

        return add_query_arg(
            array(
                'seo_facturas_document_download' => 1,
                'order_id'                        => absint($order->get_id()),
                'document_type'                   => sanitize_key($type),
                'expires'                         => $expires,
                'signature'                       => self::signature($order, $type, $expires),
            ),
            home_url('/')
        );
    }

    private static function signature($order, $type, $expires) {
        $payload = implode('|', array(
            absint($order->get_id()),
            sanitize_key($type),
            absint($expires),
            (string) $order->get_order_key(),
        ));

        return hash_hmac('sha256', $payload, wp_salt('auth'));
    }

    private static function can_view_order($order) {
        if (current_user_can('manage_woocommerce') || current_user_can('manage_options')) {
            return true;
        }

        $user_id = absint($order->get_user_id());
        if (is_user_logged_in() && $user_id && get_current_user_id() === $user_id) {
            return true;
        }

        $request_key = sanitize_text_field(wp_unslash($_GET['key'] ?? ''));
        $order_key = (string) $order->get_order_key();
        return $request_key && $order_key && hash_equals($order_key, $request_key);
    }

    private static function proforma_allowed_for_order($order) {
        if (!$order || $order->is_paid()) {
            return false;
        }

        $blocked = array('cancelled', 'failed', 'refunded', 'trash');
        return !in_array((string) $order->get_status(), $blocked, true);
    }

    private static function allowed_pdf_path($path) {
        $real = realpath((string) $path);
        if (!$real) {
            return false;
        }

        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error']) || empty($uploads['basedir'])) {
            return false;
        }

        $base = realpath(trailingslashit($uploads['basedir']) . 'seo-facturas');
        if (!$base) {
            return false;
        }

        $base = trailingslashit(wp_normalize_path($base));
        $real = wp_normalize_path($real);
        return 0 === strpos($real, $base);
    }

    private static function enqueue_assets() {
        wp_enqueue_style(
            'seo-facturas-front',
            SEO_FACTURAS_URL . 'assets/frontend.css',
            array(),
            SEO_FACTURAS_VERSION
        );
    }

    private static function deny($message) {
        status_header(403);
        nocache_headers();
        wp_die(esc_html((string) $message), 'Documento no disponible', array('response' => 403));
    }
}
