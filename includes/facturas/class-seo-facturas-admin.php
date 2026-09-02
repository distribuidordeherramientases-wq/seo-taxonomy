<?php
/**
 * Administracion del modulo de facturas.
 */

defined('ABSPATH') || exit;

final class SEO_Facturas_Admin {

    private static $initialized = false;

    public static function init() {
        if (self::$initialized || !is_admin()) {
            return;
        }
        self::$initialized = true;

        add_action('admin_menu', array(__CLASS__, 'register_page'), 30);
        add_filter('seo_tools_items', array(__CLASS__, 'add_tools_card'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_action('add_meta_boxes', array(__CLASS__, 'register_order_meta_box'));

        add_action('admin_post_seo_facturas_generate', array(__CLASS__, 'handle_generate'));
        add_action('admin_post_seo_facturas_download', array(__CLASS__, 'handle_download'));
        add_action('admin_post_seo_facturas_email', array(__CLASS__, 'handle_email'));
    }

    public static function register_page() {
        add_submenu_page(
            null,
            'Facturas y presupuestos',
            'Facturas y presupuestos',
            'manage_options',
            'seo-facturas',
            array(__CLASS__, 'render_page')
        );
    }

    public static function add_tools_card($tools) {
        $tools = is_array($tools) ? $tools : array();
        foreach ($tools as $tool) {
            if (!empty($tool['page']) && 'seo-facturas' === $tool['page']) {
                return $tools;
            }
        }

        $tools[] = array(
            'title' => 'Facturas y presupuestos',
            'icon'  => 'dashicons-media-spreadsheet',
            'page'  => 'seo-facturas',
            'desc'  => 'Proformas y facturas conectadas a los pedidos y pagos de WooCommerce.',
        );

        return $tools;
    }

    public static function enqueue_assets($hook_suffix) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ('seo-facturas' !== $page) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style(
            'seo-facturas-admin',
            SEO_FACTURAS_URL . 'assets/admin.css',
            array(),
            SEO_FACTURAS_VERSION
        );
    }

    public static function register_order_meta_box() {
        add_meta_box(
            'seo-facturas-order-box',
            'Facturacion',
            array(__CLASS__, 'render_order_meta_box'),
            'shop_order',
            'side',
            'default'
        );

        add_meta_box(
            'seo-facturas-order-box',
            'Facturacion',
            array(__CLASS__, 'render_order_meta_box'),
            'woocommerce_page_wc-orders',
            'side',
            'default'
        );
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para acceder a esta pagina.', 'seo-taxonomy'));
        }

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings';
        if (!in_array($tab, array('settings', 'documents'), true)) {
            $tab = 'settings';
        }

        self::render_notice();

        $settings_url = admin_url('admin.php?page=seo-facturas&tab=settings');
        $documents_url = admin_url('admin.php?page=seo-facturas&tab=documents');
        ?>
        <div class="wrap seo-facturas-wrap">
            <h1>Facturas y presupuestos</h1>
            <p>WooCommerce conserva pedidos, clientes y pagos. Este modulo solo genera y conserva los documentos.</p>

            <?php self::render_system_status(); ?>

            <nav class="nav-tab-wrapper">
                <a class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url($settings_url); ?>">Configuracion</a>
                <a class="nav-tab <?php echo 'documents' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url($documents_url); ?>">Documentos</a>
            </nav>

            <?php
            if ('documents' === $tab) {
                self::render_documents_tab();
            } else {
                self::render_settings_tab();
            }
            ?>
        </div>
        <?php
    }

    public static function render_order_meta_box($post_or_order) {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            echo '<p>Sin permisos.</p>';
            return;
        }

        $order = self::resolve_order_from_screen($post_or_order);
        if (!$order) {
            echo '<p>No se ha podido cargar el pedido.</p>';
            return;
        }

        $invoice = SEO_Facturas_Documents::get_for_order($order->get_id(), SEO_Facturas_Documents::TYPE_INVOICE);
        $proforma = SEO_Facturas_Documents::get_for_order($order->get_id(), SEO_Facturas_Documents::TYPE_PROFORMA);

        echo '<p><strong>Pago WooCommerce:</strong> ' . esc_html($order->is_paid() ? 'Pagado' : 'Pendiente') . '</p>';
        self::render_order_document_row($order, $proforma, SEO_Facturas_Documents::TYPE_PROFORMA);
        self::render_order_document_row($order, $invoice, SEO_Facturas_Documents::TYPE_INVOICE);

        if (!SEO_Facturas_Settings::get('enabled', 0)) {
            echo '<p class="description">El modulo esta desactivado en Herramientas &gt; Facturas y presupuestos.</p>';
        }
    }

    public static function handle_generate() {
        self::require_order_capability();
        check_admin_referer('seo_facturas_generate');

        $order_id = absint($_GET['order_id'] ?? 0);
        $type = sanitize_key(wp_unslash($_GET['type'] ?? ''));

        $document = SEO_Facturas_Documents::issue_for_order($order_id, $type);
        if (is_wp_error($document)) {
            self::redirect_back(array('sf_error' => $document->get_error_message()), $order_id);
        }

        self::redirect_back(array('sf_notice' => 'document_generated'), $order_id);
    }

    public static function handle_download() {
        self::require_order_capability();

        $document_id = absint($_GET['document_id'] ?? 0);
        check_admin_referer('seo_facturas_download_' . $document_id);

        $document = SEO_Facturas_Documents::get($document_id);
        if (!$document) {
            wp_die('Documento no encontrado.');
        }

        if (empty($document->pdf_path) || !is_readable($document->pdf_path) || !self::allowed_pdf_path($document->pdf_path)) {
            wp_die('El PDF no esta disponible o su ruta no es valida.');
        }

        $filename = sanitize_file_name($document->document_number . '.pdf');
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($document->pdf_path));
        readfile($document->pdf_path);
        exit;
    }

    public static function handle_email() {
        self::require_order_capability();

        $document_id = absint($_GET['document_id'] ?? 0);
        check_admin_referer('seo_facturas_email_' . $document_id);

        $document = SEO_Facturas_Documents::get($document_id);
        if (!$document) {
            self::redirect_back(array('sf_error' => 'Documento no encontrado.'));
        }

        if ('issued' !== $document->status || empty($document->pdf_path) || !is_readable($document->pdf_path)) {
            $order_id = absint($document->order_id);
            $document = SEO_Facturas_Documents::retry_pdf($document);
            if (is_wp_error($document)) {
                self::redirect_back(array('sf_error' => $document->get_error_message()), $order_id);
            }
        }

        $order = function_exists('wc_get_order') ? wc_get_order(absint($document->order_id)) : null;
        if (!$order) {
            self::redirect_back(array('sf_error' => 'No se encuentra el pedido WooCommerce.'), absint($document->order_id));
        }

        $to = sanitize_email($order->get_billing_email());
        if (!$to) {
            self::redirect_back(array('sf_error' => 'El pedido no tiene un email de facturacion valido.'), $order->get_id());
        }

        $label = SEO_Facturas_Documents::TYPE_INVOICE === $document->document_type ? 'Factura' : 'Factura proforma';
        $subject = $label . ' ' . $document->document_number . ' - pedido ' . $order->get_order_number();
        $message = '<p>Adjuntamos ' . esc_html(strtolower($label)) . ' <strong>' . esc_html($document->document_number) . '</strong> asociada a su pedido <strong>' . esc_html($order->get_order_number()) . '</strong>.</p>';
        $message .= '<p>Gracias.</p>';

        $subject = apply_filters('seo_facturas_manual_email_subject', $subject, $document, $order);
        $message = apply_filters('seo_facturas_manual_email_message', $message, $document, $order);

        $sent = wp_mail(
            $to,
            $subject,
            $message,
            array('Content-Type: text/html; charset=UTF-8'),
            array($document->pdf_path)
        );

        if (!$sent) {
            self::redirect_back(array('sf_error' => 'WordPress no ha podido enviar el email.'), $order->get_id());
        }

        SEO_Facturas_Documents::mark_emailed($document->id);
        self::redirect_back(array('sf_notice' => 'document_emailed'), $order->get_id());
    }

    private static function render_settings_tab() {
        $s = SEO_Facturas_Settings::all();
        $option = SEO_Facturas_Settings::OPTION;
        $engine = SEO_Facturas_PDF::engine_status();
        ?>
        <form method="post" action="options.php" class="seo-facturas-settings-form">
            <?php settings_fields('seo_facturas_settings_group'); ?>

            <h2>Activacion</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Modulo activo</th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr($option); ?>[enabled]" value="1" <?php checked(1, $s['enabled']); ?>> Activar generacion documental</label></td>
                </tr>
            </table>

            <h2>Datos fiscales del vendedor</h2>
            <table class="form-table" role="presentation">
                <?php self::text_row($option, 'company_name', 'Razon social', $s['company_name']); ?>
                <?php self::text_row($option, 'company_tax_id', 'NIF/CIF', $s['company_tax_id']); ?>
                <?php self::text_row($option, 'company_address', 'Direccion', $s['company_address']); ?>
                <?php self::text_row($option, 'company_postcode', 'Codigo postal', $s['company_postcode']); ?>
                <?php self::text_row($option, 'company_city', 'Ciudad', $s['company_city']); ?>
                <?php self::text_row($option, 'company_region', 'Provincia/region', $s['company_region']); ?>
                <?php self::country_row($option, $s['company_country']); ?>
                <?php self::text_row($option, 'company_phone', 'Telefono', $s['company_phone']); ?>
                <?php self::text_row($option, 'company_email', 'Email', $s['company_email'], 'email'); ?>
                <?php self::text_row($option, 'company_website', 'Web', $s['company_website'], 'url'); ?>
                <?php self::logo_row($option, absint($s['logo_id'])); ?>
                <tr>
                    <th scope="row">Texto de pie</th>
                    <td><textarea class="large-text" rows="3" name="<?php echo esc_attr($option); ?>[footer_text]"><?php echo esc_textarea($s['footer_text']); ?></textarea></td>
                </tr>
            </table>

            <h2>Numeracion</h2>
            <table class="form-table" role="presentation">
                <?php self::text_row($option, 'invoice_series', 'Serie facturas', $s['invoice_series']); ?>
                <?php self::text_row($option, 'proforma_series', 'Serie proformas', $s['proforma_series']); ?>
                <tr>
                    <th scope="row">Digitos de secuencia</th>
                    <td><input type="number" min="3" max="10" name="<?php echo esc_attr($option); ?>[number_padding]" value="<?php echo esc_attr($s['number_padding']); ?>"></td>
                </tr>
            </table>

            <h2>Automatizacion WooCommerce</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Factura automatica</th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr($option); ?>[auto_invoice]" value="1" <?php checked(1, $s['auto_invoice']); ?>> Emitir cuando WooCommerce considere pagado el pedido</label></td>
                </tr>
                <tr>
                    <th scope="row">Proforma automatica</th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr($option); ?>[auto_proforma]" value="1" <?php checked(1, $s['auto_proforma']); ?>> Emitir cuando el pedido pase a on-hold</label></td>
                </tr>
                <tr>
                    <th scope="row">Adjuntar a emails Woo</th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr($option); ?>[attach_to_woo_emails]" value="1" <?php checked(1, $s['attach_to_woo_emails']); ?>> Usar los emails existentes de WooCommerce</label></td>
                </tr>
                <?php self::text_row($option, 'invoice_email_ids', 'Emails Woo para factura', $s['invoice_email_ids']); ?>
                <?php self::text_row($option, 'proforma_email_ids', 'Emails Woo para proforma', $s['proforma_email_ids']); ?>
                <?php self::text_row($option, 'customer_tax_meta_keys', 'Metas posibles para NIF cliente', $s['customer_tax_meta_keys']); ?>
            </table>

            <p class="description">Motor PDF: <strong><?php echo esc_html($engine['label']); ?></strong>. Si no esta disponible, el documento queda registrado pero en estado de error hasta instalar Dompdf y reintentar.</p>

            <?php submit_button('Guardar configuracion'); ?>
        </form>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
            var button = document.getElementById('seo-facturas-select-logo');
            var input = document.getElementById('seo-facturas-logo-id');
            var preview = document.getElementById('seo-facturas-logo-preview');
            if (!button || !input || typeof wp === 'undefined' || !wp.media) return;
            button.addEventListener('click', function (event) {
                event.preventDefault();
                var frame = wp.media({title: 'Seleccionar logo', multiple: false, library: {type: 'image'}});
                frame.on('select', function () {
                    var item = frame.state().get('selection').first().toJSON();
                    input.value = item.id || '';
                    if (preview && item.url) preview.innerHTML = '<img src="' + item.url + '" alt="" style="max-width:180px;max-height:80px;">';
                });
                frame.open();
            });
        });
        </script>
        <?php
    }

    private static function render_documents_tab() {
        $documents = SEO_Facturas_Documents::list_recent(150);
        ?>
        <h2>Ultimos documentos</h2>
        <table class="widefat striped seo-facturas-table">
            <thead>
                <tr>
                    <th>Documento</th>
                    <th>Pedido Woo</th>
                    <th>Tipo</th>
                    <th>Fecha</th>
                    <th>Estado</th>
                    <th>Email manual</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$documents) : ?>
                <tr><td colspan="7">Todavia no hay documentos.</td></tr>
            <?php else : ?>
                <?php foreach ($documents as $document) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($document->document_number); ?></strong></td>
                        <td><a href="<?php echo esc_url(SEO_Facturas_Documents::order_admin_url($document->order_id)); ?>">#<?php echo esc_html($document->order_id); ?></a></td>
                        <td><?php echo esc_html(self::type_label($document->document_type)); ?></td>
                        <td><?php echo esc_html($document->issued_at); ?></td>
                        <td><?php echo esc_html($document->status); ?><?php if (!empty($document->last_error)) : ?><br><small><?php echo esc_html($document->last_error); ?></small><?php endif; ?></td>
                        <td><?php echo $document->email_sent_at ? esc_html($document->email_sent_at) : '&mdash;'; ?></td>
                        <td><?php self::render_document_actions($document); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private static function render_system_status() {
        $engine = SEO_Facturas_PDF::engine_status();
        $woo = class_exists('WooCommerce') && function_exists('wc_get_order');
        ?>
        <div class="seo-facturas-status-grid">
            <div class="seo-facturas-status-card"><strong>WooCommerce</strong><br><?php echo esc_html($woo ? 'Disponible' : 'No disponible'); ?></div>
            <div class="seo-facturas-status-card"><strong>Motor PDF</strong><br><?php echo esc_html($engine['label']); ?></div>
            <div class="seo-facturas-status-card"><strong>Automatizacion</strong><br><?php echo esc_html(SEO_Facturas_Settings::get('enabled', 0) ? 'Activa' : 'Desactivada'); ?></div>
        </div>
        <?php
    }

    private static function render_order_document_row($order, $document, $type) {
        $label = self::type_label($type);
        echo '<div class="seo-facturas-order-document">';
        echo '<p><strong>' . esc_html($label) . ':</strong> ';

        if ($document) {
            echo esc_html($document->document_number) . '<br><small>Estado: ' . esc_html($document->status) . '</small></p>';
            self::render_document_actions($document);
        } else {
            echo 'No emitida</p>';
            $can_generate = SEO_Facturas_Documents::TYPE_PROFORMA === $type ? !$order->is_paid() : $order->is_paid();
            if ($can_generate) {
                $url = wp_nonce_url(
                    admin_url('admin-post.php?action=seo_facturas_generate&order_id=' . $order->get_id() . '&type=' . $type),
                    'seo_facturas_generate'
                );
                echo '<p><a class="button" href="' . esc_url($url) . '">Generar ' . esc_html(strtolower($label)) . '</a></p>';
            }
        }
        echo '</div>';
    }

    private static function render_document_actions($document) {
        if (!$document) {
            return;
        }

        if ('issued' === $document->status && !empty($document->pdf_path) && is_readable($document->pdf_path)) {
            $download = wp_nonce_url(
                admin_url('admin-post.php?action=seo_facturas_download&document_id=' . absint($document->id)),
                'seo_facturas_download_' . absint($document->id)
            );
            $email = wp_nonce_url(
                admin_url('admin-post.php?action=seo_facturas_email&document_id=' . absint($document->id)),
                'seo_facturas_email_' . absint($document->id)
            );
            echo '<a class="button button-small" href="' . esc_url($download) . '">PDF</a> ';
            echo '<a class="button button-small" href="' . esc_url($email) . '">Reenviar</a>';
            return;
        }

        $generate = wp_nonce_url(
            admin_url('admin-post.php?action=seo_facturas_generate&order_id=' . absint($document->order_id) . '&type=' . sanitize_key($document->document_type)),
            'seo_facturas_generate'
        );
        echo '<a class="button button-small" href="' . esc_url($generate) . '">Reintentar PDF</a>';
    }

    private static function render_notice() {
        if (!empty($_GET['sf_error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html(wp_unslash($_GET['sf_error'])) . '</p></div>';
            return;
        }

        $notice = isset($_GET['sf_notice']) ? sanitize_key(wp_unslash($_GET['sf_notice'])) : '';
        if ('document_generated' === $notice) {
            echo '<div class="notice notice-success"><p>Documento generado correctamente.</p></div>';
        } elseif ('document_emailed' === $notice) {
            echo '<div class="notice notice-success"><p>Documento enviado al cliente.</p></div>';
        }
    }

    private static function text_row($option, $key, $label, $value, $type = 'text') {
        ?>
        <tr>
            <th scope="row"><label for="seo-facturas-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td><input class="regular-text" id="seo-facturas-<?php echo esc_attr($key); ?>" type="<?php echo esc_attr($type); ?>" name="<?php echo esc_attr($option); ?>[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($value); ?>"></td>
        </tr>
        <?php
    }

    private static function country_row($option, $selected_country) {
        $countries = array('ES' => 'Spain');
        if (function_exists('WC') && WC() && isset(WC()->countries)) {
            $wc_countries = WC()->countries->get_countries();
            if (is_array($wc_countries) && $wc_countries) {
                $countries = $wc_countries;
            }
        }
        ?>
        <tr>
            <th scope="row">Pais</th>
            <td><select name="<?php echo esc_attr($option); ?>[company_country]">
                <?php foreach ($countries as $code => $name) : ?>
                    <option value="<?php echo esc_attr($code); ?>" <?php selected($selected_country, $code); ?>><?php echo esc_html($name); ?></option>
                <?php endforeach; ?>
            </select></td>
        </tr>
        <?php
    }

    private static function logo_row($option, $logo_id) {
        ?>
        <tr>
            <th scope="row">Logo</th>
            <td>
                <input id="seo-facturas-logo-id" type="hidden" name="<?php echo esc_attr($option); ?>[logo_id]" value="<?php echo esc_attr($logo_id); ?>">
                <div id="seo-facturas-logo-preview" style="margin-bottom:8px;">
                    <?php if ($logo_id) echo wp_kses_post(wp_get_attachment_image($logo_id, 'medium')); ?>
                </div>
                <button type="button" class="button" id="seo-facturas-select-logo">Seleccionar logo</button>
            </td>
        </tr>
        <?php
    }

    private static function resolve_order_from_screen($post_or_order) {
        if (is_a($post_or_order, 'WC_Order')) {
            return $post_or_order;
        }
        if (is_object($post_or_order) && !empty($post_or_order->ID) && function_exists('wc_get_order')) {
            return wc_get_order(absint($post_or_order->ID));
        }
        if (!empty($_GET['id']) && function_exists('wc_get_order')) {
            return wc_get_order(absint($_GET['id']));
        }
        return null;
    }

    private static function type_label($type) {
        return SEO_Facturas_Documents::TYPE_INVOICE === $type ? 'Factura' : 'Proforma';
    }

    private static function require_order_capability() {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die('No tienes permisos para realizar esta accion.');
        }
    }

    private static function redirect_back($args = array(), $order_id = 0) {
        $target = wp_get_referer();
        if (!$target) {
            $target = $order_id ? SEO_Facturas_Documents::order_admin_url($order_id) : admin_url('admin.php?page=seo-facturas&tab=documents');
        }
        $target = add_query_arg($args, $target);
        wp_safe_redirect($target);
        exit;
    }

    private static function allowed_pdf_path($path) {
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error'])) {
            return false;
        }

        $base = realpath(trailingslashit($uploads['basedir']) . 'seo-facturas');
        $real = realpath($path);
        if (!$base || !$real) {
            return false;
        }
        return 0 === strpos($real, $base . DIRECTORY_SEPARATOR);
    }
}
