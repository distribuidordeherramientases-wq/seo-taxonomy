<?php
/**
 * Email administrativo de pedido cancelado.
 *
 * WooCommerce envía normalmente este correo al administrador de la tienda.
 * Variables proporcionadas: $order, $email_heading, $additional_content,
 * $sent_to_admin, $plain_text y $email.
 */

defined('ABSPATH') || exit;

if (!isset($order) || !is_a($order, 'WC_Order')) {
    return;
}

$order_number = $order->get_order_number();
$customer     = $order->get_formatted_billing_full_name();

if ($customer === '') {
    $customer = $order->get_billing_email();
}

/* Cabecera estándar de WooCommerce. */
do_action('woocommerce_email_header', $email_heading, $email);
?>

<div class="dht-email dht-email--cancelled">
    <div class="dht-email-status">
        <span class="dht-email-kicker">Pedido cancelado</span>

        <p class="dht-email-greeting">Aviso para administración</p>

        <p>
            <?php
            printf(
                esc_html('El pedido #%1$s de %2$s ha sido cancelado.'),
                esc_html($order_number),
                esc_html($customer ?: 'cliente sin identificar')
            );
            ?>
        </p>

        <p class="dht-email-muted">
            Revisa el pedido antes de realizar cualquier acción de almacén, cobro o devolución.
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
