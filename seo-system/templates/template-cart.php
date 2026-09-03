<?php
/**
 * Carrito DHT v3.
 *
 * Archivo principal asociado a template_key `cart`.
 * Renderiza el carrito directamente con la API estable de WooCommerce
 * para no depender de overrides de cart.php ni de cart-collaterals.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

if (!function_exists('dht_seo_cart_v3_cart')) {
    function dht_seo_cart_v3_cart()
    {
        if (!function_exists('WC')) {
            return null;
        }

        $wc = WC();
        if (!$wc || empty($wc->cart) || !is_object($wc->cart)) {
            return null;
        }

        return $wc->cart;
    }
}

if (!function_exists('dht_seo_cart_v3_count')) {
    function dht_seo_cart_v3_count()
    {
        $cart = dht_seo_cart_v3_cart();
        if (!$cart || !method_exists($cart, 'get_cart_contents_count')) {
            return 0;
        }

        return (int) $cart->get_cart_contents_count();
    }
}

if (!function_exists('dht_seo_cart_v3_money')) {
    function dht_seo_cart_v3_money($amount)
    {
        if (function_exists('wc_price')) {
            return wc_price((float) $amount);
        }

        return esc_html(number_format_i18n((float) $amount, 2));
    }
}

if (!function_exists('dht_seo_cart_v3_empty')) {
    function dht_seo_cart_v3_empty()
    {
        $shop_url = dht_template_shop_url();
        ?>
        <div class="woocommerce dht-native-cart">
            <div class="dht-cart-empty">
                <h2>Tu carrito está vacío</h2>
                <p>Todavía no has añadido productos al carrito.</p>
                <a class="button dht-cart-shop-button" href="<?php echo esc_url($shop_url); ?>">Ver productos</a>
            </div>
        </div>
        <?php
    }
}

if (!function_exists('dht_seo_cart_v3_render_summary')) {
    function dht_seo_cart_v3_render_summary($cart)
    {
        $subtotal = method_exists($cart, 'get_cart_subtotal') ? $cart->get_cart_subtotal() : '';
        $discount = method_exists($cart, 'get_discount_total') ? (float) $cart->get_discount_total() : 0.0;
        $shipping = method_exists($cart, 'get_shipping_total') ? (float) $cart->get_shipping_total() : 0.0;
        $shipping_tax = method_exists($cart, 'get_shipping_tax') ? (float) $cart->get_shipping_tax() : 0.0;
        $total = method_exists($cart, 'get_total') ? $cart->get_total() : '';
        $needs_shipping = method_exists($cart, 'needs_shipping') ? (bool) $cart->needs_shipping() : false;
        $checkout_url = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/finalizar-compra/');
        ?>
        <div class="cart_totals">
            <h2>Totales del carrito</h2>
            <table class="shop_table shop_table_responsive" cellspacing="0">
                <tbody>
                    <tr class="cart-subtotal">
                        <th>Subtotal</th>
                        <td><?php echo wp_kses_post($subtotal); ?></td>
                    </tr>

                    <?php if ($discount > 0) : ?>
                        <tr class="cart-discount">
                            <th>Descuento</th>
                            <td>-<?php echo wp_kses_post(dht_seo_cart_v3_money($discount)); ?></td>
                        </tr>
                    <?php endif; ?>

                    <?php if ($needs_shipping) : ?>
                        <tr class="shipping">
                            <th>Envío</th>
                            <td>
                                <?php if (($shipping + $shipping_tax) > 0) : ?>
                                    <?php echo wp_kses_post(dht_seo_cart_v3_money($shipping + $shipping_tax)); ?>
                                <?php else : ?>
                                    <span>Se calcula al finalizar</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <tr class="order-total">
                        <th>Total</th>
                        <td><strong><?php echo wp_kses_post($total); ?></strong></td>
                    </tr>
                </tbody>
            </table>

            <div class="wc-proceed-to-checkout">
                <a href="<?php echo esc_url($checkout_url); ?>" class="checkout-button button alt wc-forward">
                    Finalizar compra
                </a>
            </div>

            <?php do_action('seo_facturas_cart_documents'); ?>
        </div>
        <?php
    }
}

if (!function_exists('dht_seo_cart_v3_render')) {
    function dht_seo_cart_v3_render()
    {
        $cart = dht_seo_cart_v3_cart();

        if (!$cart || !method_exists($cart, 'get_cart')) {
            echo '<div class="woocommerce"><div class="woocommerce-error">No se ha podido inicializar el carrito de WooCommerce.</div></div>';
            return;
        }

        if (function_exists('wc_print_notices')) {
            wc_print_notices();
        }

        if (method_exists($cart, 'is_empty') && $cart->is_empty()) {
            dht_seo_cart_v3_empty();
            return;
        }

        $cart_url = function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/carrito/');
        $items = $cart->get_cart();
        ?>
        <div class="woocommerce dht-native-cart">
            <div class="dht-cart-layout">
                <div class="dht-cart-products-panel">
                    <form class="woocommerce-cart-form dht-cart-form" action="<?php echo esc_url($cart_url); ?>" method="post">
                        <div class="dht-cart-items" role="list">
                            <?php foreach ((array) $items as $cart_item_key => $cart_item) : ?>
                                <?php
                                $product = isset($cart_item['data']) && is_object($cart_item['data']) ? $cart_item['data'] : null;
                                $quantity = isset($cart_item['quantity']) ? (float) $cart_item['quantity'] : 0;

                                if (!$product || $quantity <= 0) {
                                    continue;
                                }

                                if (method_exists($product, 'exists') && !$product->exists()) {
                                    continue;
                                }

                                $product_id = isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
                                $name = method_exists($product, 'get_name') ? $product->get_name() : ('Producto #' . $product_id);
                                $sku = method_exists($product, 'get_sku') ? $product->get_sku() : '';
                                $permalink = '';

                                if (method_exists($product, 'is_visible') && $product->is_visible() && method_exists($product, 'get_permalink')) {
                                    $permalink = (string) $product->get_permalink($cart_item);
                                }

                                $thumbnail = method_exists($product, 'get_image') ? $product->get_image('woocommerce_thumbnail') : '';
                                $price = method_exists($cart, 'get_product_price') ? $cart->get_product_price($product) : '';
                                $subtotal = method_exists($cart, 'get_product_subtotal') ? $cart->get_product_subtotal($product, $quantity) : '';

                                if (function_exists('wc_get_cart_remove_url')) {
                                    $remove_url = wc_get_cart_remove_url($cart_item_key);
                                } else {
                                    $remove_url = wp_nonce_url(add_query_arg('remove_item', $cart_item_key, $cart_url), 'woocommerce-cart');
                                }

                                $sold_individually = method_exists($product, 'is_sold_individually') ? (bool) $product->is_sold_individually() : false;
                                $max_quantity = method_exists($product, 'get_max_purchase_quantity') ? (float) $product->get_max_purchase_quantity() : 0;
                                $remove_label = sprintf('Eliminar %s del carrito', wp_strip_all_tags((string) $name));
                                ?>
                                <article class="dht-cart-item" role="listitem">
                                    <div class="dht-cart-item-media">
                                        <?php if ($permalink !== '') : ?>
                                            <a href="<?php echo esc_url($permalink); ?>"><?php echo wp_kses_post($thumbnail); ?></a>
                                        <?php else : ?>
                                            <?php echo wp_kses_post($thumbnail); ?>
                                        <?php endif; ?>
                                    </div>

                                    <div class="dht-cart-item-info">
                                        <div class="dht-cart-item-heading">
                                            <div class="dht-cart-item-title-wrap">
                                                <?php if ($permalink !== '') : ?>
                                                    <a class="dht-cart-item-title" href="<?php echo esc_url($permalink); ?>"><?php echo esc_html($name); ?></a>
                                                <?php else : ?>
                                                    <span class="dht-cart-item-title"><?php echo esc_html($name); ?></span>
                                                <?php endif; ?>

                                                <?php if (function_exists('wc_get_formatted_cart_item_data')) : ?>
                                                    <?php $item_data = wc_get_formatted_cart_item_data($cart_item); ?>
                                                    <?php if ($item_data !== '') : ?>
                                                        <div class="dht-cart-item-meta"><?php echo wp_kses_post($item_data); ?></div>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>

                                            <a
                                                class="dht-cart-remove"
                                                href="<?php echo esc_url($remove_url); ?>"
                                                aria-label="<?php echo esc_attr($remove_label); ?>"
                                                data-product_id="<?php echo esc_attr($product_id); ?>"
                                                data-product_sku="<?php echo esc_attr($sku); ?>"
                                            >
                                                <span class="dht-cart-remove-icon" aria-hidden="true">&times;</span>
                                                <span>Eliminar</span>
                                            </a>
                                        </div>

                                        <div class="dht-cart-item-values">
                                            <div class="dht-cart-value dht-cart-value-price">
                                                <span class="dht-cart-value-label">Precio</span>
                                                <strong><?php echo wp_kses_post($price); ?></strong>
                                            </div>

                                            <div class="dht-cart-value dht-cart-value-quantity">
                                                <label class="dht-cart-value-label" for="dht-v3-qty-<?php echo esc_attr($cart_item_key); ?>">Cantidad</label>
                                                <?php if ($sold_individually) : ?>
                                                    <input type="hidden" name="cart[<?php echo esc_attr($cart_item_key); ?>][qty]" value="1" />
                                                    <strong>1</strong>
                                                <?php else : ?>
                                                    <div class="quantity">
                                                        <input
                                                            type="number"
                                                            id="dht-v3-qty-<?php echo esc_attr($cart_item_key); ?>"
                                                            class="input-text qty text"
                                                            name="cart[<?php echo esc_attr($cart_item_key); ?>][qty]"
                                                            value="<?php echo esc_attr($quantity); ?>"
                                                            min="0"
                                                            step="1"
                                                            <?php if ($max_quantity > 0) : ?>max="<?php echo esc_attr($max_quantity); ?>"<?php endif; ?>
                                                            inputmode="numeric"
                                                        />
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <div class="dht-cart-value dht-cart-value-subtotal">
                                                <span class="dht-cart-value-label">Total</span>
                                                <strong><?php echo wp_kses_post($subtotal); ?></strong>
                                            </div>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>

                        <div class="dht-cart-actions">
                            <?php if (function_exists('wc_coupons_enabled') && wc_coupons_enabled()) : ?>
                                <div class="coupon dht-cart-coupon">
                                    <label class="screen-reader-text" for="coupon_code">Código de cupón</label>
                                    <input type="text" name="coupon_code" class="input-text" id="coupon_code" value="" placeholder="Código de cupón" />
                                    <button type="submit" class="button" name="apply_coupon" value="Aplicar cupón">Aplicar cupón</button>
                                </div>
                            <?php endif; ?>

                            <button type="submit" class="button dht-cart-update" name="update_cart" value="Actualizar carrito">Actualizar carrito</button>
                            <?php wp_nonce_field('woocommerce-cart', 'woocommerce-cart-nonce'); ?>
                        </div>
                    </form>
                </div>

                <aside class="dht-cart-summary" aria-label="Resumen del pedido">
                    <?php dht_seo_cart_v3_render_summary($cart); ?>
                </aside>
            </div>
        </div>
        <?php
    }
}

/*
 * El template-loader del plugin puede resolver directamente la variante
 * desktop/mobile y saltarse este archivo principal. Las variantes cargan
 * este archivo como bootstrap para disponer de las funciones del carrito.
 * En ese caso no debemos volver a despachar otra variante.
 */
if (!defined('DHT_SEO_CART_VARIANT_BOOTSTRAP')) {
    require dht_template_device_variant_file('cart');
}
