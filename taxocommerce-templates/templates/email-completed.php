<?php
/**
 * Email de pedido completado.
 *
 * Variables proporcionadas por WooCommerce:
 * $order, $email_heading, $additional_content, $sent_to_admin,
 * $plain_text y $email.
 */

defined('ABSPATH') || exit;

if (!isset($order) || !is_a($order, 'WC_Order')) {
    return;
}

$customer_name = $order->get_billing_first_name();
$order_number  = $order->get_order_number();

/* Cabecera estándar de WooCommerce. */
do_action('woocommerce_email_header', $email_heading, $email);
?>

<div class="dht-email dht-email--completed">
    <div class="dht-email-status">
        <span class="dht-email-kicker">Pedido completado</span>

        <p class="dht-email-greeting">
            <?php
            echo $customer_name
                ? esc_html(sprintf('Hola %s,', $customer_name))
                : esc_html('Hola,');
            ?>
        </p>

        <p>
            <?php
            printf(
                esc_html('El pedido #%s se ha completado correctamente.'),
                esc_html($order_number)
            );
            ?>
        </p>

        <p class="dht-email-muted">
            Gracias por confiar en DistribuidorDeHerramientas.es.
        </p>
    </div>

    <?php
    /* Detalle del pedido, metadatos y datos del cliente. */
    do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email);
    do_action('woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email);
    do_action('woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email);
    ?>

    <?php if (!empty($additional_content)) : ?>
        <div class="dht-email-additional">
            <?php echo wp_kses_post(wpautop(wptexturize($additional_content))); ?>
        </div>
    <?php endif; ?>
</div>

<?php
/* Pie estándar de WooCommerce. */
do_action('woocommerce_email_footer', $email);
