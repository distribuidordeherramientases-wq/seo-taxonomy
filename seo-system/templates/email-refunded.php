<?php
/**
 * Email de pedido reembolsado.
 *
 * Variables proporcionadas por WooCommerce:
 * $order, $email_heading, $additional_content, $sent_to_admin,
 * $plain_text, $email, $partial_refund y $blogname.
 */

defined('ABSPATH') || exit;

if (!isset($order) || !is_a($order, 'WC_Order')) {
    return;
}

$customer_name  = $order->get_billing_first_name();
$order_number   = $order->get_order_number();
$partial_refund = !empty($partial_refund);

/* Cabecera estándar de WooCommerce. */
do_action('woocommerce_email_header', $email_heading, $email);
?>

<div class="dht-email dht-email--refunded">
    <div class="dht-email-status">
        <span class="dht-email-kicker">
            <?php echo $partial_refund ? esc_html('Reembolso parcial') : esc_html('Pedido reembolsado'); ?>
        </span>

        <p class="dht-email-greeting">
            <?php
            echo $customer_name
                ? esc_html(sprintf('Hola %s,', $customer_name))
                : esc_html('Hola,');
            ?>
        </p>

        <p>
            <?php if ($partial_refund) : ?>
                <?php
                printf(
                    esc_html('Se ha realizado un reembolso parcial del pedido #%s.'),
                    esc_html($order_number)
                );
                ?>
            <?php else : ?>
                <?php
                printf(
                    esc_html('Se ha realizado el reembolso del pedido #%s.'),
                    esc_html($order_number)
                );
                ?>
            <?php endif; ?>
        </p>

        <p class="dht-email-muted">
            El plazo para que el importe aparezca en tu cuenta depende del método de pago y de tu entidad bancaria.
        </p>
    </div>

    <?php
    /* Detalle del pedido y del reembolso. */
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
