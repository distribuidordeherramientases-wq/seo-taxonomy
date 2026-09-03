<?php
defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

$order_id = absint(get_query_var('order-received'));
if (!$order_id && isset($GLOBALS['wp']->query_vars['order-received'])) {
    $order_id = absint($GLOBALS['wp']->query_vars['order-received']);
}
$order = ($order_id && function_exists('wc_get_order')) ? wc_get_order($order_id) : null;

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

            <a class="dht-btn dht-btn-primary" href="<?php echo esc_url(dht_template_shop_url()); ?>">Volver a la tienda</a>
        </div>
    </section>
</main>
<?php dht_template_render_footer(); ?>
