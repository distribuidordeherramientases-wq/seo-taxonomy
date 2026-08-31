<?php
defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

dht_template_render_header();
?>
<main class="dht-page dht-commerce-page dht-checkout-page">
    <section class="dht-checkout-hero">
        <div class="dht-checkout-container">
            <h1>Finalizar compra</h1>
            <p>Completa tus datos, revisa el pedido y finaliza la compra de forma segura.</p>
        </div>
    </section>

    <section class="dht-checkout-main">
        <div class="dht-checkout-container">
            <?php echo do_shortcode('[woocommerce_checkout]'); ?>
        </div>
    </section>
</main>
<?php dht_template_render_footer(); ?>
