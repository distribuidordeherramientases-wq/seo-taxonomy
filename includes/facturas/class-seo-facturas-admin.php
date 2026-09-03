<?php
/**
 * Administracion del modulo documental.
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
        add_action('admin_notices', array(__CLASS__, 'render_notice'));

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
            'desc'  => 'Facturas, proformas y presupuestos PDF conectados a WooCommerce.',
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

        $tabs = array(
            'company'   => 'Datos empresa',
            'invoices'  => 'Facturas',
            'proformas' => 'Proformas',
            'quotes'    => 'Presupuestos',
            'documents' => 'Documentos',
        );

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'company';
        if (!isset($tabs[$tab])) {
            $tab = 'company';
        }
        ?>
        <div class="wrap seo-facturas-wrap">
            <h1>Facturas y presupuestos</h1>
            <p>WooCommerce conserva productos, carrito, pedidos, clientes, impuestos y pagos. Este modulo utiliza esos datos para generar documentos.</p>

            <?php self::render_system_status(); ?>

            <nav class="nav-tab-wrapper" aria-label="Configuracion de documentos">
                <?php foreach ($tabs as $key => $label) : ?>
                    <a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=seo-facturas&tab=' . $key)); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php
            switch ($tab) {
                case 'invoices':
                    self::render_invoice_tab();
                    break;
                case 'proformas':
                    self::render_proforma_tab();
                    break;
                case 'quotes':
                    self::render_quote_tab();
                    break;
                case 'documents':
                    self::render_documents_tab();
                    break;
                case 'company':
                default:
                    self::render_company_tab();
                    break;
            }
            ?>
        </div>
        <?php
    }


    public static function render_notice() {
        if (!current_user_can('manage_options') && !current_user_can('manage_woocommerce')) {
            return;
        }

        if (!empty($_GET['sf_error'])) {
            $message = sanitize_text_field(wp_unslash($_GET['sf_error']));
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
            return;
        }

        if (!empty($_GET['sf_notice'])) {
            $notice = sanitize_key(wp_unslash($_GET['sf_notice']));
            $messages = array(
                'document_generated' => 'Documento generado correctamente.',
                'document_emailed'   => 'Documento enviado por email correctamente.',
            );
            if (isset($messages[$notice])) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$notice]) . '</p></div>';
            }
        }
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

        if (SEO_Facturas_Settings::get('proforma_enabled', 1)) {
            self::render_order_document_row($order, $proforma, SEO_Facturas_Documents::TYPE_PROFORMA);
        }
        if (SEO_Facturas_Settings::get('invoice_enabled', 1)) {
            self::render_order_document_row($order, $invoice, SEO_Facturas_Documents::TYPE_INVOICE);
        }

        if (!SEO_Facturas_Settings::get('enabled', 0)) {
            echo '<p class="description">El sistema documental esta desactivado en Datos empresa.</p>';
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
        $message = '<p>Adjuntamos ' . esc_html(strtolower($label)) . ' <strong>' . esc_html($document->document_number) . '</strong> asociada a su pedido <strong>' . esc_html($order->get_order_number()) . '</strong>.</p><p>Gracias.</p>';

        $sent = wp_mail(
            $to,
            apply_filters('seo_facturas_manual_email_subject', $subject, $document, $order),
            apply_filters('seo_facturas_manual_email_message', $message, $document, $order),
            array('Content-Type: text/html; charset=UTF-8'),
            array($document->pdf_path)
        );

        if (!$sent) {
            self::redirect_back(array('sf_error' => 'WordPress no ha podido enviar el email.'), $order->get_id());
        }

        SEO_Facturas_Documents::mark_emailed($document->id);
        self::redirect_back(array('sf_notice' => 'document_emailed'), $order->get_id());
    }

    private static function render_company_tab() {
        $s = SEO_Facturas_Settings::company();
        $option = SEO_Facturas_Settings::COMPANY_OPTION;
        ?>
        <form method="post" action="options.php" class="seo-facturas-settings-form">
            <?php settings_fields('seo_facturas_company_group'); ?>
            <h2>Configuracion comun</h2>
            <p>Estos datos se utilizan en facturas, proformas y presupuestos. Se configuran una sola vez.</p>
            <table class="form-table" role="presentation">
                <?php self::checkbox_row($option, 'enabled', 'Sistema documental', $s['enabled'], 'Activar facturas, proformas y presupuestos configurados en sus respectivas pestañas.'); ?>
                <?php self::text_row($option, 'company_name', 'Razon social', $s['company_name']); ?>
                <?php self::text_row($option, 'company_trade_name', 'Nombre comercial', $s['company_trade_name']); ?>
                <?php self::text_row($option, 'company_tax_id', 'NIF / CIF', $s['company_tax_id']); ?>
                <?php self::text_row($option, 'company_address', 'Direccion', $s['company_address']); ?>
                <?php self::text_row($option, 'company_postcode', 'Codigo postal', $s['company_postcode']); ?>
                <?php self::text_row($option, 'company_city', 'Localidad', $s['company_city']); ?>
                <?php self::text_row($option, 'company_region', 'Provincia / region', $s['company_region']); ?>
                <?php self::country_row($option, $s['company_country']); ?>
                <?php self::text_row($option, 'company_phone', 'Telefono', $s['company_phone']); ?>
                <?php self::text_row($option, 'company_email', 'Email', $s['company_email'], 'email'); ?>
                <?php self::text_row($option, 'company_website', 'Web', $s['company_website'], 'url'); ?>
                <?php self::logo_row($option, absint($s['logo_id'])); ?>
                <?php self::textarea_row($option, 'footer_text', 'Pie comun', $s['footer_text'], 'Aparece al final de los documentos, salvo que la plantilla decida ocultarlo.'); ?>
                <?php self::text_row($option, 'customer_tax_meta_keys', 'Metas posibles para NIF/CIF cliente', $s['customer_tax_meta_keys'], 'text', 'Claves separadas por comas. WooCommerce no define un NIF/CIF estandar.'); ?>
            </table>
            <?php submit_button('Guardar datos de empresa'); ?>
        </form>
        <?php self::logo_script(); ?>
        <?php
    }

    private static function render_invoice_tab() {
        $s = SEO_Facturas_Settings::invoice();
        $option = SEO_Facturas_Settings::INVOICE_OPTION;
        ?>
        <form method="post" action="options.php" class="seo-facturas-settings-form">
            <?php settings_fields('seo_facturas_invoice_group'); ?>
            <h2>Facturas</h2>
            <p>La factura se emite a partir de un pedido que WooCommerce considera pagado.</p>
            <table class="form-table" role="presentation">
                <?php self::checkbox_row($option, 'invoice_enabled', 'Facturas activas', $s['invoice_enabled'], 'Permitir emision de facturas.'); ?>
                <?php self::text_row($option, 'invoice_title', 'Titulo del PDF', $s['invoice_title']); ?>
                <?php self::text_row($option, 'invoice_series', 'Serie', $s['invoice_series']); ?>
                <?php self::number_row($option, 'invoice_padding', 'Digitos de secuencia', $s['invoice_padding'], 3, 10); ?>
                <?php self::checkbox_row($option, 'auto_invoice', 'Generacion automatica', $s['auto_invoice'], 'Emitir cuando WooCommerce confirme el pago o el pedido entre en un estado pagado.'); ?>
                <?php self::checkbox_row($option, 'invoice_attach_to_woo_emails', 'Adjuntar a emails WooCommerce', $s['invoice_attach_to_woo_emails'], 'Adjuntar el PDF a los emails configurados abajo.'); ?>
                <?php self::text_row($option, 'invoice_email_ids', 'IDs de email WooCommerce', $s['invoice_email_ids'], 'text', 'Separados por comas. Ej.: customer_processing_order,customer_completed_order'); ?>
                <?php self::checkbox_row($option, 'invoice_show_order_reference', 'Referencia del pedido', $s['invoice_show_order_reference'], 'Mostrar pedido WooCommerce y fecha del pedido.'); ?>
                <?php self::checkbox_row($option, 'invoice_show_payment_method', 'Metodo de pago', $s['invoice_show_payment_method'], 'Mostrar el metodo de pago en el PDF.'); ?>
                <?php self::checkbox_row($option, 'invoice_show_sku', 'SKU / referencia', $s['invoice_show_sku'], 'Mostrar SKU en las lineas de producto.'); ?>
                <?php self::textarea_row($option, 'invoice_footer_text', 'Pie exclusivo de factura', $s['invoice_footer_text'], 'Se muestra ademas del pie comun.'); ?>
            </table>
            <?php submit_button('Guardar facturas'); ?>
        </form>
        <?php
    }

    private static function render_proforma_tab() {
        $s = SEO_Facturas_Settings::proforma();
        $option = SEO_Facturas_Settings::PROFORMA_OPTION;
        ?>
        <form method="post" action="options.php" class="seo-facturas-settings-form">
            <?php settings_fields('seo_facturas_proforma_group'); ?>
            <h2>Facturas proforma</h2>
            <p>La proforma corresponde a un pedido existente todavia no pagado. No sustituye a la factura fiscal.</p>
            <table class="form-table" role="presentation">
                <?php self::checkbox_row($option, 'proforma_enabled', 'Proformas activas', $s['proforma_enabled'], 'Permitir generacion de facturas proforma.'); ?>
                <?php self::text_row($option, 'proforma_title', 'Titulo del PDF', $s['proforma_title']); ?>
                <?php self::text_row($option, 'proforma_series', 'Serie', $s['proforma_series']); ?>
                <?php self::number_row($option, 'proforma_padding', 'Digitos de secuencia', $s['proforma_padding'], 3, 10); ?>
                <?php self::checkbox_row($option, 'auto_proforma', 'Generacion automatica', $s['auto_proforma'], 'Emitir al entrar en cualquiera de los estados configurados.'); ?>
                <?php self::text_row($option, 'proforma_order_statuses', 'Estados WooCommerce', $s['proforma_order_statuses'], 'text', 'Slugs separados por comas. Por defecto: on-hold'); ?>
                <?php self::checkbox_row($option, 'proforma_attach_to_woo_emails', 'Adjuntar a emails WooCommerce', $s['proforma_attach_to_woo_emails'], 'Adjuntar el PDF a los emails configurados abajo.'); ?>
                <?php self::text_row($option, 'proforma_email_ids', 'IDs de email WooCommerce', $s['proforma_email_ids'], 'text', 'Por defecto: customer_on_hold_order'); ?>
                <?php self::checkbox_row($option, 'proforma_show_order_reference', 'Referencia del pedido', $s['proforma_show_order_reference'], 'Mostrar pedido WooCommerce y fecha del pedido.'); ?>
                <?php self::checkbox_row($option, 'proforma_show_payment_method', 'Metodo de pago', $s['proforma_show_payment_method'], 'Mostrar el metodo de pago seleccionado.'); ?>
                <?php self::checkbox_row($option, 'proforma_show_sku', 'SKU / referencia', $s['proforma_show_sku'], 'Mostrar SKU en las lineas de producto.'); ?>
                <?php self::checkbox_row($option, 'proforma_show_payment_info', 'Instrucciones de pago', $s['proforma_show_payment_info'], 'Incluir datos para transferencia/Bizum en la proforma.'); ?>
                <?php self::text_row($option, 'proforma_beneficiary', 'Beneficiario', $s['proforma_beneficiary']); ?>
                <?php self::text_row($option, 'proforma_iban', 'IBAN', $s['proforma_iban']); ?>
                <?php self::text_row($option, 'proforma_bizum', 'Bizum', $s['proforma_bizum']); ?>
                <?php self::textarea_row($option, 'proforma_payment_instructions', 'Texto de pago', $s['proforma_payment_instructions'], 'Ej.: indique como concepto el numero de pedido.'); ?>
                <?php self::textarea_row($option, 'proforma_footer_text', 'Pie exclusivo de proforma', $s['proforma_footer_text'], 'Se muestra ademas del pie comun.'); ?>
            </table>
            <?php submit_button('Guardar proformas'); ?>
        </form>
        <?php
    }

    private static function render_quote_tab() {
        $s = SEO_Facturas_Settings::quote();
        $option = SEO_Facturas_Settings::QUOTE_OPTION;
        $engine = SEO_Facturas_PDF::engine_status();
        ?>
        <form method="post" action="options.php" class="seo-facturas-settings-form">
            <?php settings_fields('seo_facturas_quote_group'); ?>
            <h2>Presupuestos desde el carrito</h2>
            <p>El cliente descarga un PDF a partir del carrito actual. No se crea pedido, cliente ni historial de presupuestos.</p>
            <table class="form-table" role="presentation">
                <?php self::checkbox_row($option, 'quote_enabled', 'Presupuestos activos', $s['quote_enabled'], 'Mostrar la opcion de presupuesto en el carrito.'); ?>
                <?php self::text_row($option, 'quote_title', 'Titulo del PDF', $s['quote_title']); ?>
                <?php self::text_row($option, 'quote_button_text', 'Texto del boton', $s['quote_button_text']); ?>
                <?php self::text_row($option, 'quote_series', 'Serie', $s['quote_series']); ?>
                <?php self::number_row($option, 'quote_padding', 'Digitos de secuencia', $s['quote_padding'], 3, 10); ?>
                <?php self::number_row($option, 'quote_validity_days', 'Validez (dias)', $s['quote_validity_days'], 1, 365); ?>
                <?php self::checkbox_row($option, 'quote_guest_allowed', 'Visitantes', $s['quote_guest_allowed'], 'Permitir presupuestos sin iniciar sesion.'); ?>
            </table>

            <h3>Datos que puede facilitar el comprador</h3>
            <table class="form-table" role="presentation">
                <?php self::checkbox_row($option, 'quote_ask_company', 'Empresa / nombre', $s['quote_ask_company'], 'Mostrar el campo.'); ?>
                <?php self::checkbox_row($option, 'quote_ask_tax_id', 'NIF / CIF', $s['quote_ask_tax_id'], 'Mostrar el campo.'); ?>
                <?php self::checkbox_row($option, 'quote_ask_contact', 'Persona de contacto', $s['quote_ask_contact'], 'Mostrar el campo.'); ?>
                <?php self::checkbox_row($option, 'quote_ask_email', 'Email', $s['quote_ask_email'], 'Mostrar el campo email.'); ?>
                <?php self::checkbox_row($option, 'quote_require_email', 'Email obligatorio', $s['quote_require_email'], 'No generar el presupuesto sin un email valido.'); ?>
            </table>

            <h3>Contenido del PDF</h3>
            <table class="form-table" role="presentation">
                <?php self::checkbox_row($option, 'quote_show_sku', 'SKU / referencia', $s['quote_show_sku'], 'Mostrar SKU.'); ?>
                <?php self::checkbox_row($option, 'quote_show_tax', 'Impuestos', $s['quote_show_tax'], 'Desglosar base e impuestos.'); ?>
                <?php self::checkbox_row($option, 'quote_show_shipping', 'Transporte', $s['quote_show_shipping'], 'Mostrar transporte y destino utilizado por WooCommerce.'); ?>
                <?php self::checkbox_row($option, 'quote_show_discounts', 'Descuentos', $s['quote_show_discounts'], 'Mostrar descuentos aplicados al carrito.'); ?>
                <?php self::checkbox_row($option, 'quote_show_images', 'Imagen de producto', $s['quote_show_images'], 'Incluir miniaturas locales en el PDF.'); ?>
                <?php self::checkbox_row($option, 'quote_send_email_copy', 'Copia por email', $s['quote_send_email_copy'], 'Si el comprador facilita email, enviar tambien una copia. La descarga sigue siendo directa.'); ?>
                <?php self::number_row($option, 'quote_hourly_limit', 'Limite por sesion / hora', $s['quote_hourly_limit'], 1, 200); ?>
                <?php self::textarea_row($option, 'quote_terms_text', 'Condiciones del presupuesto', $s['quote_terms_text'], 'Texto de validez comercial, stock y ausencia de valor fiscal.'); ?>
                <?php self::textarea_row($option, 'quote_footer_text', 'Pie exclusivo de presupuesto', $s['quote_footer_text'], 'Se muestra ademas del pie comun.'); ?>
            </table>

            <p class="description">Motor PDF: <strong><?php echo esc_html($engine['label']); ?></strong>. Los presupuestos necesitan el motor PDF operativo porque se generan y descargan en el momento.</p>
            <p class="description">Compatibilidad: se inserta en el carrito clasico y tambien se anade al bloque WooCommerce Cart. Se incluye el shortcode <code>[seo_facturas_presupuesto]</code> como ubicacion manual de respaldo.</p>
            <?php submit_button('Guardar presupuestos'); ?>
        </form>
        <?php
    }

    private static function render_documents_tab() {
        $documents = SEO_Facturas_Documents::list_recent(150);
        ?>
        <h2>Documentos emitidos</h2>
        <p>Este registro contiene facturas y proformas vinculadas a pedidos WooCommerce. Los presupuestos de carrito son efimeros y no se almacenan.</p>
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
                        <td><?php echo esc_html($document->email_sent_at ?: '-'); ?></td>
                        <td><?php echo self::document_actions_html($document); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private static function render_system_status() {
        $engine = SEO_Facturas_PDF::engine_status();
        $master = (bool) SEO_Facturas_Settings::get('enabled', 0);
        $woo = class_exists('WooCommerce');
        ?>
        <div class="seo-facturas-status-grid">
            <div class="seo-facturas-status-card"><strong>Sistema documental</strong><br><?php echo esc_html($master ? 'Activo' : 'Desactivado'); ?></div>
            <div class="seo-facturas-status-card"><strong>WooCommerce</strong><br><?php echo esc_html($woo ? 'Disponible' : 'No disponible'); ?></div>
            <div class="seo-facturas-status-card"><strong>Motor PDF</strong><br><?php echo esc_html($engine['label']); ?></div>
        </div>
        <?php
    }

    private static function render_order_document_row($order, $document, $type) {
        $label = self::type_label($type);
        echo '<div class="seo-facturas-order-document"><strong>' . esc_html($label) . '</strong><br>';
        if ($document) {
            echo esc_html($document->document_number) . ' - ' . esc_html($document->status) . '<br>';
            echo self::document_actions_html($document); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } else {
            $url = wp_nonce_url(
                admin_url('admin-post.php?action=seo_facturas_generate&order_id=' . absint($order->get_id()) . '&type=' . rawurlencode($type)),
                'seo_facturas_generate'
            );
            echo '<a class="button button-small" href="' . esc_url($url) . '">Generar</a>';
        }
        echo '</div>';
    }

    private static function document_actions_html($document) {
        if (!$document || empty($document->id)) {
            return '';
        }

        $parts = array();
        if (!empty($document->pdf_path) && is_readable($document->pdf_path)) {
            $download = wp_nonce_url(
                admin_url('admin-post.php?action=seo_facturas_download&document_id=' . absint($document->id)),
                'seo_facturas_download_' . absint($document->id)
            );
            $parts[] = '<a class="button button-small" href="' . esc_url($download) . '">PDF</a>';
        } else {
            $generate = wp_nonce_url(
                admin_url('admin-post.php?action=seo_facturas_generate&order_id=' . absint($document->order_id) . '&type=' . rawurlencode($document->document_type)),
                'seo_facturas_generate'
            );
            $parts[] = '<a class="button button-small" href="' . esc_url($generate) . '">Reintentar PDF</a>';
        }

        $email = wp_nonce_url(
            admin_url('admin-post.php?action=seo_facturas_email&document_id=' . absint($document->id)),
            'seo_facturas_email_' . absint($document->id)
        );
        $parts[] = '<a class="button button-small" href="' . esc_url($email) . '">Reenviar</a>';

        return implode(' ', $parts);
    }

    private static function resolve_order_from_screen($post_or_order) {
        if (is_a($post_or_order, 'WC_Order')) {
            return $post_or_order;
        }
        if (is_object($post_or_order) && isset($post_or_order->ID) && function_exists('wc_get_order')) {
            return wc_get_order(absint($post_or_order->ID));
        }
        if (isset($_GET['id']) && function_exists('wc_get_order')) {
            return wc_get_order(absint($_GET['id']));
        }
        return null;
    }

    private static function type_label($type) {
        return SEO_Facturas_Documents::TYPE_INVOICE === $type ? 'Factura' : 'Proforma';
    }

    private static function require_order_capability() {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die('No tienes permisos suficientes.');
        }
    }

    private static function redirect_back($args, $order_id = 0) {
        $args = is_array($args) ? $args : array();
        if ($order_id) {
            $url = SEO_Facturas_Documents::order_admin_url($order_id);
        } else {
            $url = admin_url('admin.php?page=seo-facturas&tab=documents');
        }
        wp_safe_redirect(add_query_arg($args, $url));
        exit;
    }

    private static function allowed_pdf_path($path) {
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error']) || empty($uploads['basedir'])) {
            return false;
        }
        $base = realpath(trailingslashit($uploads['basedir']) . 'seo-facturas');
        $real = realpath((string) $path);
        return $base && $real && 0 === strpos($real, $base . DIRECTORY_SEPARATOR);
    }

    private static function checkbox_row($option, $key, $label, $value, $description = '') {
        ?>
        <tr>
            <th scope="row"><?php echo esc_html($label); ?></th>
            <td>
                <label><input type="checkbox" name="<?php echo esc_attr($option); ?>[<?php echo esc_attr($key); ?>]" value="1" <?php checked(1, $value); ?>> <?php echo esc_html($description); ?></label>
            </td>
        </tr>
        <?php
    }

    private static function text_row($option, $key, $label, $value, $type = 'text', $description = '') {
        ?>
        <tr>
            <th scope="row"><label for="sf-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td>
                <input class="regular-text" type="<?php echo esc_attr($type); ?>" id="sf-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($option); ?>[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($value); ?>">
                <?php if ($description) : ?><p class="description"><?php echo esc_html($description); ?></p><?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private static function number_row($option, $key, $label, $value, $min, $max) {
        ?>
        <tr>
            <th scope="row"><label for="sf-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td><input type="number" id="sf-<?php echo esc_attr($key); ?>" min="<?php echo esc_attr($min); ?>" max="<?php echo esc_attr($max); ?>" name="<?php echo esc_attr($option); ?>[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($value); ?>"></td>
        </tr>
        <?php
    }

    private static function textarea_row($option, $key, $label, $value, $description = '') {
        ?>
        <tr>
            <th scope="row"><label for="sf-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td>
                <textarea class="large-text" rows="4" id="sf-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($option); ?>[<?php echo esc_attr($key); ?>]"><?php echo esc_textarea($value); ?></textarea>
                <?php if ($description) : ?><p class="description"><?php echo esc_html($description); ?></p><?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private static function country_row($option, $selected) {
        $countries = array('ES' => 'España');
        if (function_exists('WC') && WC() && isset(WC()->countries)) {
            $wc_countries = WC()->countries->get_countries();
            if (is_array($wc_countries) && $wc_countries) {
                $countries = $wc_countries;
            }
        }
        ?>
        <tr>
            <th scope="row"><label for="sf-company-country">Pais</label></th>
            <td><select id="sf-company-country" name="<?php echo esc_attr($option); ?>[company_country]">
                <?php foreach ($countries as $code => $name) : ?><option value="<?php echo esc_attr($code); ?>" <?php selected($selected, $code); ?>><?php echo esc_html($name); ?></option><?php endforeach; ?>
            </select></td>
        </tr>
        <?php
    }

    private static function logo_row($option, $logo_id) {
        $url = $logo_id ? wp_get_attachment_image_url($logo_id, 'medium') : '';
        ?>
        <tr>
            <th scope="row">Logo</th>
            <td>
                <input type="hidden" id="seo-facturas-logo-id" name="<?php echo esc_attr($option); ?>[logo_id]" value="<?php echo esc_attr($logo_id); ?>">
                <div id="seo-facturas-logo-preview"><?php if ($url) : ?><img src="<?php echo esc_url($url); ?>" alt="" style="max-width:180px;max-height:80px;"><?php endif; ?></div>
                <p><button type="button" class="button" id="seo-facturas-select-logo">Seleccionar logo</button></p>
            </td>
        </tr>
        <?php
    }

    private static function logo_script() {
        ?>
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
}
