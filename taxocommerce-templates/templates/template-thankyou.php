<?php
defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

$order_id = absint(get_query_var('order-received'));
if (!$order_id && isset($GLOBALS['wp']->query_vars['order-received'])) {
    $order_id = absint($GLOBALS['wp']->query_vars['order-received']);
}
$order = ($order_id && function_exists('wc_get_order')) ? wc_get_order($order_id) : null;

/*
 * Google Customer Reviews (GCR).
 *
 * El opt-in solo se prepara en la pagina de pedido recibido y se imprime justo
 * antes del footer, es decir, al final de la pagina de confirmacion. Google
 * exige ID de pedido, email, pais de entrega y fecha estimada de entrega.
 */
$gcr_payload = array();

if ($order && is_a($order, 'WC_Order')) {
    $merchant_id = absint(get_option('seo_ojeador_merchant_account_v1', 0));
    $merchant_id = absint(apply_filters('seo_gcr_merchant_id', $merchant_id, $order));
    $order_number = trim((string) $order->get_order_number());
    $customer_email = sanitize_email((string) $order->get_billing_email());
    $delivery_country = strtoupper(trim((string) $order->get_shipping_country()));

    if ($delivery_country === '') {
        $delivery_country = strtoupper(trim((string) $order->get_billing_country()));
    }

    /*
     * La tienda comunica una entrega estimada de 2-3 dias. Para la encuesta
     * usamos el extremo conservador: 3 dias laborables desde la creacion del
     * pedido. El filtro permite sustituir este calculo si en el futuro existe
     * una fecha de entrega real proporcionada por transportista/proveedor.
     */
    $estimated_delivery_date = '';
    $created = $order->get_date_created();

    if ($created) {
        try {
            $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('Europe/Madrid');
            $delivery = new DateTimeImmutable('@' . $created->getTimestamp());
            $delivery = $delivery->setTimezone($timezone);
            $business_days = 0;

            while ($business_days < 3) {
                $delivery = $delivery->modify('+1 day');
                if ((int) $delivery->format('N') <= 5) {
                    $business_days++;
                }
            }

            $estimated_delivery_date = $delivery->format('Y-m-d');
        } catch (Exception $e) {
            $estimated_delivery_date = wp_date('Y-m-d', strtotime('+3 weekdays'));
        }
    }

    $estimated_delivery_date = (string) apply_filters(
        'seo_gcr_estimated_delivery_date',
        $estimated_delivery_date,
        $order
    );

    /*
     * GTIN es opcional para GCR. Lo enviamos solo cuando encontramos un GTIN
     * numerico valido (8, 12, 13 o 14 digitos), usando las mismas fuentes que
     * ya maneja el catalogo/feeds del plugin.
     */
    $gcr_products = array();
    $seen_gtins = array();

    foreach ($order->get_items('line_item') as $item) {
        if (!is_a($item, 'WC_Order_Item_Product')) {
            continue;
        }

        $product = $item->get_product();
        if (!$product || !is_a($product, 'WC_Product')) {
            continue;
        }

        $gtin = '';
        if (method_exists($product, 'get_global_unique_id')) {
            $gtin = trim((string) $product->get_global_unique_id());
        }

        $meta_ids = array_filter(array_unique(array(
            absint($item->get_variation_id()),
            absint($item->get_product_id()),
        )));

        if ($gtin === '') {
            $meta_keys = array(
                '_global_unique_id',
                '_wc_gla_gtin',
                '_alg_ean',
                '_gtin',
                'gtin',
                '_ean',
                'ean',
                'ean13',
                'gtin13',
                'wpm_gtin_code',
                '_barcode',
                'barcode',
            );

            foreach ($meta_ids as $meta_id) {
                foreach ($meta_keys as $meta_key) {
                    $candidate = trim((string) get_post_meta($meta_id, $meta_key, true));
                    if ($candidate !== '') {
                        $gtin = $candidate;
                        break 2;
                    }
                }
            }
        }

        $gtin = preg_replace('/\D+/', '', (string) $gtin);
        $gtin_length = strlen($gtin);

        if (!in_array($gtin_length, array(8, 12, 13, 14), true) || isset($seen_gtins[$gtin])) {
            continue;
        }

        $seen_gtins[$gtin] = true;
        $gcr_products[] = array('gtin' => $gtin);
    }

    if (
        $merchant_id > 0 &&
        $order_number !== '' &&
        is_email($customer_email) &&
        preg_match('/^[A-Z]{2}$/', $delivery_country) &&
        preg_match('/^\d{4}-\d{2}-\d{2}$/', $estimated_delivery_date)
    ) {
        $gcr_payload = array(
            'merchant_id' => $merchant_id,
            'order_id' => $order_number,
            'email' => $customer_email,
            'delivery_country' => $delivery_country,
            'estimated_delivery_date' => $estimated_delivery_date,
            'opt_in_style' => 'CENTER_DIALOG',
        );

        if (!empty($gcr_products)) {
            $gcr_payload['products'] = $gcr_products;
        }
    }
}

if (!empty($gcr_payload)) {
    $gcr_handle = 'taxocommerce-google-customer-reviews';
    $gcr_src = 'https://apis.google.com/js/platform.js?onload=renderOptIn';

    wp_register_script($gcr_handle, $gcr_src, array(), TCT_VERSION, true);
    wp_enqueue_script($gcr_handle);

    $gcr_inline = "window.___gcfg = { lang: 'es' };\n"
        . "window.renderOptIn = function () {\n"
        . "    if (!window.gapi) { return; }\n"
        . "    window.gapi.load('surveyoptin', function () {\n"
        . "        window.gapi.surveyoptin.render(" . wp_json_encode($gcr_payload) . ");\n"
        . "    });\n"
        . "};";

    wp_add_inline_script($gcr_handle, $gcr_inline, 'before');
}

dht_template_render_header();
?>
<main class="dht-page dht-status-page">
    <section class="dht-section">
        <div class="dht-content dht-status-card">
            <span class="dht-kicker">Pedido recibido</span>
            <h1>Gracias por tu compra</h1>

            <?php if ($order) : ?>
                <p>El pedido <strong>#<?php echo esc_html($order->get_order_number()); ?></strong> se ha recibido correctamente.</p>
                <p>
                    Estado: <strong><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></strong>
                    &nbsp;&middot;&nbsp;
                    Total: <strong><?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong>
                </p>

                <?php do_action('seo_facturas_customer_order_documents', $order_id); ?>
            <?php else : ?>
                <p>El pedido se ha recibido correctamente. Recibiras la informacion de seguimiento cuando este disponible.</p>
            <?php endif; ?>

            <?php dht_template_render_service_promise('after', 'after'); ?>

            <a class="dht-btn dht-btn-primary" href="<?php echo esc_url(dht_template_shop_url()); ?>">Volver a la tienda</a>
        </div>
    </section>
</main>

<?php dht_template_render_footer(); ?>
