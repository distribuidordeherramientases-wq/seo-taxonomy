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

dht_template_render_header();

$shop_url = dht_template_shop_url();
$cart_count = dht_seo_cart_v3_count();
?>
<main class="dht-page dht-commerce-page dht-cart-page dht-cart-mobile">
    <style>
        .dht-cart-mobile {
            --cart-bg: var(--dht-cart-bg, #f5f7f9);
            --cart-card: var(--dht-cart-card-bg, #fff);
            --cart-summary: var(--dht-cart-summary-bg, #fff);
            --cart-text: var(--dht-cart-text, #17212b);
            --cart-muted: var(--dht-cart-muted, #68747e);
            --cart-border: var(--dht-cart-border, #e1e7eb);
            --cart-danger: var(--dht-cart-danger, #b42318);
            --cart-danger-bg: var(--dht-cart-danger-bg, #fff8f7);
            --cart-card-radius: var(--dht-cart-card-radius, 14px);
            --cart-control-radius: var(--dht-cart-control-radius, 8px);
            --cart-image-size: var(--dht-cart-product-image-mobile, 82px);
            --cart-items-gap: var(--dht-cart-items-gap, 12px);
            --cart-shadow: var(--dht-cart-shadow, 0 5px 16px rgba(0, 0, 0, .04));
            --cart-action-bg: var(--dht-cart-action-bg, var(--dht-primary, #007acc));
            --cart-action-hover: var(--dht-cart-action-hover, var(--dht-primary-dark, #005b96));
            --cart-action-text: var(--dht-cart-action-text, #fff);
            min-height: 70vh;
            background: var(--cart-bg);
            color: var(--cart-text);
        }

        .dht-cart-mobile .dht-container {
            width: min(100% - 28px, 760px);
            margin-left: auto;
            margin-right: auto;
        }

        .dht-cart-mobile .dht-cart-mobile-head {
            padding: 23px 0 20px;
            background: var(--dht-cart-hero-bg, #17212b);
            color: var(--dht-cart-hero-title, #fff);
        }

        .dht-cart-mobile .dht-cart-mobile-head-row {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 14px;
        }

        .dht-cart-mobile .dht-cart-mobile-kicker {
            display: block;
            margin-bottom: 5px;
            color: var(--dht-cart-hero-text, #aebdc8);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .07em;
            text-transform: uppercase;
        }

        .dht-cart-mobile .dht-cart-mobile-head h1 {
            margin: 0;
            color: var(--dht-cart-hero-title, #fff);
            font-size: 31px;
            line-height: 1.08;
        }

        .dht-cart-mobile .dht-cart-mobile-count {
            flex: 0 0 auto;
            padding: 6px 9px;
            border-radius: 999px;
            background: rgba(255,255,255,.1);
            color: #e5ebef;
            font-size: 12px;
            font-weight: 750;
        }

        .dht-cart-mobile .dht-cart-mobile-main {
            padding: 16px 0 44px;
        }

        .dht-cart-mobile .dht-cart-mobile-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 13px;
        }

        .dht-cart-mobile .dht-cart-mobile-toolbar strong {
            color: var(--cart-text);
            font-size: 15px;
        }

        .dht-cart-mobile .dht-cart-mobile-back {
            color: var(--dht-primary, #007acc);
            font-size: 13px;
            font-weight: 800;
            text-decoration: none;
            white-space: nowrap;
        }

        .dht-cart-mobile .woocommerce-notices-wrapper:empty {
            display: none;
        }

        .dht-cart-mobile .woocommerce-message,
        .dht-cart-mobile .woocommerce-error,
        .dht-cart-mobile .woocommerce-info {
            margin: 0 0 13px;
            padding: 14px 13px 14px 40px;
            border-radius: 10px;
            background: var(--cart-card);
            font-size: 13px;
        }

        .dht-cart-mobile .dht-cart-layout {
            display: flex;
            flex-direction: column;
            gap: 13px;
        }

        .dht-cart-mobile .dht-cart-items {
            display: grid;
            gap: var(--cart-items-gap);
        }

        .dht-cart-mobile .dht-cart-item {
            display: grid;
            grid-template-columns: var(--cart-image-size) minmax(0, 1fr);
            gap: 12px;
            padding: 13px;
            border: 1px solid var(--cart-border);
            border-radius: var(--cart-card-radius);
            background: var(--cart-card);
            box-shadow: var(--cart-shadow);
        }

        .dht-cart-mobile .dht-cart-item-media img {
            display: block;
            width: var(--cart-image-size);
            height: var(--cart-image-size);
            margin: 0;
            padding: 4px;
            border: 1px solid var(--cart-border);
            border-radius: var(--cart-control-radius);
            background: var(--cart-card);
            object-fit: contain;
        }

        .dht-cart-mobile .dht-cart-item-info {
            min-width: 0;
        }

        .dht-cart-mobile .dht-cart-item-heading {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .dht-cart-mobile .dht-cart-item-title {
            display: block;
            color: var(--cart-text);
            font-size: 14px;
            font-weight: 800;
            line-height: 1.35;
            text-decoration: none;
        }

        .dht-cart-mobile .dht-cart-item-meta {
            margin-top: 5px;
            color: var(--cart-muted);
            font-size: 11px;
        }

        .dht-cart-mobile .dht-cart-item-meta dl,
        .dht-cart-mobile .dht-cart-item-meta p {
            margin: 0;
        }

        .dht-cart-mobile .dht-cart-remove {
            display: inline-flex !important;
            width: fit-content;
            align-items: center;
            gap: 5px;
            min-height: 31px;
            padding: 5px 8px;
            border: 1px solid color-mix(in srgb, var(--cart-danger) 25%, var(--cart-border));
            border-radius: var(--cart-control-radius);
            background: var(--cart-danger-bg);
            color: var(--cart-danger) !important;
            font-size: 12px;
            font-weight: 800;
            line-height: 1;
            text-decoration: none !important;
        }

        .dht-cart-mobile .dht-cart-remove-icon {
            font-size: 17px;
            font-weight: 500;
        }

        .dht-cart-mobile .dht-cart-item-values {
            grid-column: 1 / -1;
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 7px;
            margin-top: 11px;
            padding-top: 11px;
            border-top: 1px solid var(--cart-border);
        }

        .dht-cart-mobile .dht-cart-value {
            display: flex;
            min-width: 0;
            flex-direction: column;
            gap: 5px;
        }

        .dht-cart-mobile .dht-cart-value-label {
            color: var(--cart-muted);
            font-size: 11px;
            font-weight: 700;
        }

        .dht-cart-mobile .dht-cart-value strong {
            color: var(--cart-text);
            font-size: 13px;
            line-height: 1.25;
        }

        .dht-cart-mobile .quantity .qty {
            width: 64px;
            min-height: 37px;
            margin: 0;
            padding: 5px 7px;
            border: 1px solid var(--cart-border);
            border-radius: var(--cart-control-radius);
            background: var(--cart-card);
            color: var(--cart-text);
            text-align: center;
        }

        .dht-cart-mobile .dht-cart-actions {
            display: grid;
            gap: 9px;
            margin-top: 10px;
            padding: 12px;
            border: 1px solid var(--cart-border);
            border-radius: var(--cart-card-radius);
            background: var(--cart-card);
        }

        .dht-cart-mobile .dht-cart-coupon {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 7px;
        }

        .dht-cart-mobile .dht-cart-coupon .input-text {
            width: 100% !important;
            min-width: 0;
            min-height: 40px;
            margin: 0 !important;
            padding: 8px 9px;
            border: 1px solid var(--cart-border);
            border-radius: var(--cart-control-radius);
        }

        .dht-cart-mobile .dht-cart-actions .button {
            min-height: 40px;
            margin: 0;
            padding: 8px 10px;
            border: 0;
            border-radius: var(--cart-control-radius);
            background: #e8edf1;
            color: var(--cart-text);
            font-size: 12px;
            font-weight: 800;
        }

        .dht-cart-mobile .dht-cart-update {
            width: 100%;
            background: var(--cart-action-bg) !important;
            color: var(--cart-action-text) !important;
        }

        .dht-cart-mobile .dht-cart-actions button:disabled,
        .dht-cart-mobile .dht-cart-actions button:disabled[disabled] {
            opacity: .5;
        }

        .dht-cart-mobile .dht-cart-summary .cart_totals {
            float: none;
            width: 100%;
            margin: 0;
            padding: 16px;
            border: 1px solid var(--cart-border);
            border-radius: var(--cart-card-radius);
            background: var(--cart-summary);
            box-shadow: var(--cart-shadow);
        }

        .dht-cart-mobile .dht-cart-summary .cart_totals h2 {
            margin: 0 0 11px;
            padding-bottom: 11px;
            border-bottom: 1px solid var(--cart-border);
            color: var(--cart-text);
            font-size: 19px;
        }

        .dht-cart-mobile .dht-cart-summary table.shop_table {
            width: 100%;
            margin: 0;
            border: 0;
            border-collapse: collapse;
            background: transparent;
        }

        .dht-cart-mobile .dht-cart-summary table.shop_table tr {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            padding: 9px 0;
            border-bottom: 1px solid var(--cart-border);
        }

        .dht-cart-mobile .dht-cart-summary table.shop_table th,
        .dht-cart-mobile .dht-cart-summary table.shop_table td {
            display: block;
            width: auto;
            padding: 0;
            border: 0;
            background: transparent;
        }

        .dht-cart-mobile .dht-cart-summary table.shop_table th {
            color: var(--cart-muted);
            font-size: 12px;
            font-weight: 650;
            text-align: left;
        }

        .dht-cart-mobile .dht-cart-summary table.shop_table td {
            color: var(--cart-text);
            font-size: 13px;
            font-weight: 800;
            text-align: right;
        }

        .dht-cart-mobile .dht-cart-summary table.shop_table td::before {
            display: none !important;
        }

        .dht-cart-mobile .dht-cart-summary .wc-proceed-to-checkout {
            padding: 14px 0 0;
        }

        .dht-cart-mobile .dht-cart-summary .checkout-button {
            display: flex;
            width: 100%;
            min-height: 49px;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 11px 13px;
            border-radius: 8px;
            background: var(--cart-action-bg);
            color: var(--cart-action-text);
            font-size: 14px;
            font-weight: 850;
            text-align: center;
        }

        .dht-cart-mobile .dht-cart-update:hover,
        .dht-cart-mobile .dht-cart-summary .checkout-button:hover {
            background: var(--cart-action-hover) !important;
            color: var(--cart-action-text) !important;
        }

        .dht-cart-mobile .cross-sells {
            display: none !important;
        }

        .dht-cart-mobile .dht-cart-empty {
            padding: 25px 17px;
            border: 1px solid var(--cart-border);
            border-radius: var(--cart-card-radius);
            background: var(--cart-card);
            text-align: center;
        }

        .dht-cart-mobile .dht-cart-empty h2 {
            margin: 0 0 7px;
            font-size: 20px;
        }

        .dht-cart-mobile .dht-cart-empty p {
            margin: 0 0 15px;
            color: var(--cart-muted);
            font-size: 13px;
        }

        @media (max-width: 370px) {
            .dht-cart-mobile .dht-cart-item {
                grid-template-columns: min(var(--cart-image-size), 72px) minmax(0, 1fr);
                gap: var(--cart-items-gap);
                padding: 11px;
            }

            .dht-cart-mobile .dht-cart-item-media img {
                width: min(var(--cart-image-size), 72px);
                height: min(var(--cart-image-size), 72px);
            }

            .dht-cart-mobile .dht-cart-item-values {
                grid-template-columns: 1fr 1fr;
            }

            .dht-cart-mobile .dht-cart-value-subtotal {
                grid-column: 1 / -1;
            }

            .dht-cart-mobile .dht-cart-coupon {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <section class="dht-cart-mobile-head">
        <div class="dht-container">
            <div class="dht-cart-mobile-head-row">
                <div>
                    <span class="dht-cart-mobile-kicker">Tu pedido</span>
                    <h1>Carrito</h1>
                </div>
                <span class="dht-cart-mobile-count">
                    <?php echo esc_html(number_format_i18n($cart_count)); ?> <?php echo 1 === $cart_count ? 'articulo' : 'articulos'; ?>
                </span>
            </div>
        </div>
    </section>

    <section class="dht-cart-mobile-main">
        <div class="dht-container">
            <div class="dht-cart-mobile-toolbar">
                <strong>Tu compra</strong>
                <a class="dht-cart-mobile-back" href="<?php echo esc_url($shop_url); ?>">&larr; Seguir comprando</a>
            </div>

            <?php dht_seo_cart_v3_render(); ?>
        </div>
    </section>
</main>
<script>
(function () {
    document.addEventListener('change', function (event) {
        if (!event.target.matches('.dht-cart-form input.qty')) {
            return;
        }
        var form = event.target.closest('.dht-cart-form');
        var button = form ? form.querySelector('button[name="update_cart"]') : null;
        if (button) {
            button.disabled = false;
            button.removeAttribute('disabled');
        }
    });
})();
</script>
<?php dht_template_render_footer(); ?>
