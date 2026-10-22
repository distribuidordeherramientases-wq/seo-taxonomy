<?php
/**
 * Carrito DHT - escritorio.
 */

defined('ABSPATH') || exit;

/*
 * IMPORTANTE: el router de plantillas puede cargar esta variante directamente,
 * sin ejecutar antes template-cart.php. Cargamos el principal como bootstrap
 * de funciones y bloqueamos su redispatch para evitar recursion.
 */
if (!defined('DHT_SEO_CART_VARIANT_BOOTSTRAP')) {
    define('DHT_SEO_CART_VARIANT_BOOTSTRAP', true);
}
require_once __DIR__ . '/template-cart.php';

wp_enqueue_style(
    'dht-cart-desktop',
    TCT_URL . 'templates/assets/cart-desktop.css',
    array(),
    defined('TCT_VERSION') ? TCT_VERSION : null
);
wp_enqueue_script(
    'dht-cart-desktop',
    TCT_URL . 'templates/assets/cart-desktop.js',
    array(),
    defined('TCT_VERSION') ? TCT_VERSION : null,
    true
);

dht_template_render_header();

$shop_url = dht_template_shop_url();
$cart_count = dht_seo_cart_v3_count();
?>
<main class="dht-page dht-commerce-page dht-cart-page dht-cart-desktop">
<section class="dht-cart-main">
        <div class="dht-container">
            <div class="dht-cart-toolbar">
                <div class="dht-cart-toolbar-copy">
                    <span class="dht-cart-toolbar-kicker">Tu pedido</span>
                    <div class="dht-cart-toolbar-title">
                        <h1>Carrito</h1>
                        <span class="dht-cart-toolbar-count">
                            <?php echo esc_html(number_format_i18n($cart_count)); ?> <?php echo 1 === $cart_count ? 'articulo' : 'articulos'; ?>
                        </span>
                    </div>
                    <p>
                        <?php if ($cart_count > 0) : ?>
                            Revisa productos, cantidades y total antes de finalizar la compra.
                        <?php else : ?>
                            Los productos que anadas apareceran aqui.
                        <?php endif; ?>
                    </p>
                </div>
                <a class="dht-cart-back" href="<?php echo esc_url($shop_url); ?>">&larr; Seguir comprando</a>
            </div>

            <?php dht_seo_cart_v3_render(); ?>

            <?php dht_template_render_service_promise('strip', 'checkout'); ?>
        </div>
    </section>
</main>
<?php dht_template_render_footer(); ?>
