<?php
/**
 * Carrito DHT - movil.
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
    'dht-cart-mobile',
    SEO_SYSTEM_URL . 'seo-system/templates/assets/cart-mobile.css',
    array(),
    SEO_SYSTEM_VERSION
);
wp_enqueue_script(
    'dht-cart-mobile',
    SEO_SYSTEM_URL . 'seo-system/templates/assets/cart-mobile.js',
    array(),
    SEO_SYSTEM_VERSION,
    true
);

dht_template_render_header();

$shop_url = dht_template_shop_url();
$cart_count = dht_seo_cart_v3_count();
?>
<main class="dht-page dht-commerce-page dht-cart-page dht-cart-mobile">
    

    <section class="dht-cart-mobile-main">
        <div class="dht-container">
            <div class="dht-cart-mobile-toolbar">
                <div class="dht-cart-mobile-toolbar-copy">
                    <span class="dht-cart-mobile-toolbar-kicker">Tu pedido</span>
                    <div class="dht-cart-mobile-toolbar-title">
                        <h1>Carrito</h1>
                        <span class="dht-cart-mobile-toolbar-count">
                            <?php echo esc_html(number_format_i18n($cart_count)); ?> <?php echo 1 === $cart_count ? 'articulo' : 'articulos'; ?>
                        </span>
                    </div>
                </div>
                <a class="dht-cart-mobile-back" href="<?php echo esc_url($shop_url); ?>">&larr; Seguir comprando</a>
            </div>

            <?php dht_seo_cart_v3_render(); ?>

            <?php dht_template_render_service_promise('strip', 'checkout'); ?>
        </div>
    </section>
</main>

<?php dht_template_render_footer(); ?>
