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

dht_template_render_header();

$shop_url = dht_template_shop_url();
$cart_count = dht_seo_cart_v3_count();
?>
<main class="dht-page dht-commerce-page dht-cart-page dht-cart-desktop">
    <style>
        .dht-cart-desktop {
            --cart-bg: #f5f7f9;
            --cart-card: #fff;
            --cart-text: #17212b;
            --cart-muted: #68747e;
            --cart-border: #e1e7eb;
            --cart-danger: #b42318;
            min-height: 70vh;
            background: var(--cart-bg);
            color: var(--cart-text);
        }

        .dht-cart-desktop .dht-container {
            width: min(1180px, calc(100% - 48px));
            margin-left: auto;
            margin-right: auto;
        }

        .dht-cart-desktop .dht-cart-hero {
            padding: 42px 0 38px;
            background: #17212b;
            color: #fff;
        }

        .dht-cart-desktop .dht-cart-hero-inner {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 36px;
        }

        .dht-cart-desktop .dht-cart-kicker {
            display: block;
            margin-bottom: 8px;
            color: #b9c6d0;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .dht-cart-desktop .dht-cart-hero h1 {
            margin: 0 0 10px;
            color: #fff;
            font-size: clamp(34px, 4vw, 50px);
            line-height: 1.05;
        }

        .dht-cart-desktop .dht-cart-hero p {
            max-width: 660px;
            margin: 0;
            color: #d3dce3;
            font-size: 16px;
            line-height: 1.6;
        }

        .dht-cart-desktop .dht-cart-main {
            padding: 30px 0 64px;
        }

        .dht-cart-desktop .dht-cart-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 18px;
        }

        .dht-cart-desktop .dht-cart-toolbar h2 {
            margin: 0;
            color: var(--cart-text);
            font-size: 21px;
        }

        .dht-cart-desktop .dht-cart-back {
            display: inline-flex;
            align-items: center;
            min-height: 40px;
            padding: 8px 13px;
            border: 1px solid var(--cart-border);
            border-radius: 9px;
            background: #fff;
            color: var(--cart-text);
            font-size: 14px;
            font-weight: 750;
            text-decoration: none;
        }

        .dht-cart-desktop .dht-cart-back:hover {
            border-color: #bfc9d0;
            background: #f9fbfc;
        }

        .dht-cart-desktop .woocommerce-notices-wrapper:empty {
            display: none;
        }

        .dht-cart-desktop .woocommerce-message,
        .dht-cart-desktop .woocommerce-error,
        .dht-cart-desktop .woocommerce-info {
            margin: 0 0 18px;
            border-radius: 10px;
            background: #fff;
        }

        .dht-cart-desktop .dht-cart-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 350px;
            gap: 24px;
            align-items: start;
        }

        .dht-cart-desktop .dht-cart-products-panel,
        .dht-cart-desktop .dht-cart-summary {
            min-width: 0;
        }

        .dht-cart-desktop .dht-cart-form {
            margin: 0;
        }

        .dht-cart-desktop .dht-cart-items {
            display: grid;
            gap: 12px;
        }

        .dht-cart-desktop .dht-cart-item {
            display: grid;
            grid-template-columns: 130px minmax(0, 1fr);
            gap: 20px;
            padding: 18px;
            border: 1px solid var(--cart-border);
            border-radius: 14px;
            background: var(--cart-card);
            box-shadow: 0 7px 20px rgba(20, 32, 43, .035);
        }

        .dht-cart-desktop .dht-cart-item-media {
            display: flex;
            align-items: flex-start;
            justify-content: center;
        }

        .dht-cart-desktop .dht-cart-item-media img {
            display: block;
            width: 120px;
            height: 120px;
            margin: 0;
            padding: 6px;
            border: 1px solid #edf0f2;
            border-radius: 11px;
            background: #fff;
            object-fit: contain;
        }

        .dht-cart-desktop .dht-cart-item-info {
            min-width: 0;
        }

        .dht-cart-desktop .dht-cart-item-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            padding-bottom: 16px;
            border-bottom: 1px solid #edf0f2;
        }

        .dht-cart-desktop .dht-cart-item-title-wrap {
            min-width: 0;
        }

        .dht-cart-desktop .dht-cart-item-title {
            display: block;
            color: var(--cart-text);
            font-size: 16px;
            font-weight: 800;
            line-height: 1.4;
            text-decoration: none;
        }

        .dht-cart-desktop .dht-cart-item-title:hover {
            text-decoration: underline;
        }

        .dht-cart-desktop .dht-cart-item-meta {
            margin-top: 7px;
            color: var(--cart-muted);
            font-size: 12px;
            line-height: 1.45;
        }

        .dht-cart-desktop .dht-cart-item-meta dl,
        .dht-cart-desktop .dht-cart-item-meta p {
            margin: 0;
        }

        .dht-cart-desktop .dht-cart-remove {
            display: inline-flex !important;
            flex: 0 0 auto;
            align-items: center;
            gap: 7px;
            min-height: 36px;
            padding: 6px 10px;
            border: 1px solid #ecd4d1;
            border-radius: 8px;
            background: #fff8f7;
            color: var(--cart-danger) !important;
            font-size: 13px;
            font-weight: 750;
            line-height: 1;
            text-decoration: none !important;
        }

        .dht-cart-desktop .dht-cart-remove:hover {
            border-color: var(--cart-danger);
            background: #fff0ee;
        }

        .dht-cart-desktop .dht-cart-remove-icon {
            font-size: 19px;
            font-weight: 500;
        }

        .dht-cart-desktop .dht-cart-item-values {
            display: grid;
            grid-template-columns: minmax(110px, 1fr) minmax(120px, 1fr) minmax(110px, 1fr);
            gap: 14px;
            padding-top: 16px;
        }

        .dht-cart-desktop .dht-cart-value {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .dht-cart-desktop .dht-cart-value-label {
            color: var(--cart-muted);
            font-size: 12px;
            font-weight: 700;
        }

        .dht-cart-desktop .dht-cart-value strong {
            color: var(--cart-text);
            font-size: 16px;
        }

        .dht-cart-desktop .quantity .qty {
            width: 78px;
            min-height: 40px;
            margin: 0;
            padding: 7px 8px;
            border: 1px solid #cbd4da;
            border-radius: 8px;
            background: #fff;
            color: var(--cart-text);
            text-align: center;
        }

        .dht-cart-desktop .dht-cart-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-top: 14px;
            padding: 14px;
            border: 1px solid var(--cart-border);
            border-radius: 12px;
            background: #fff;
        }

        .dht-cart-desktop .dht-cart-coupon {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .dht-cart-desktop .dht-cart-coupon .input-text {
            width: 180px !important;
            min-height: 41px;
            margin: 0 !important;
            padding: 8px 10px;
            border: 1px solid #cbd4da;
            border-radius: 8px;
        }

        .dht-cart-desktop .dht-cart-actions .button {
            min-height: 41px;
            margin: 0;
            padding: 9px 13px;
            border: 0;
            border-radius: 8px;
            background: #e8edf1;
            color: var(--cart-text);
            font-weight: 800;
        }

        .dht-cart-desktop .dht-cart-update {
            margin-left: auto !important;
            background: var(--dht-primary, #007acc) !important;
            color: #fff !important;
        }

        .dht-cart-desktop .dht-cart-actions button:disabled,
        .dht-cart-desktop .dht-cart-actions button:disabled[disabled] {
            opacity: .5;
        }

        .dht-cart-desktop .dht-cart-summary .cart_totals {
            float: none;
            width: 100%;
            margin: 0;
            padding: 22px;
            border: 1px solid var(--cart-border);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 10px 26px rgba(20, 32, 43, .05);
            position: sticky;
            top: 18px;
        }

        .dht-cart-desktop .dht-cart-summary .cart_totals h2 {
            margin: 0 0 14px;
            padding-bottom: 13px;
            border-bottom: 1px solid #edf0f2;
            color: var(--cart-text);
            font-size: 21px;
        }

        .dht-cart-desktop .dht-cart-summary table.shop_table {
            width: 100%;
            margin: 0;
            border: 0;
            border-collapse: collapse;
            background: transparent;
        }

        .dht-cart-desktop .dht-cart-summary table.shop_table th,
        .dht-cart-desktop .dht-cart-summary table.shop_table td {
            padding: 11px 0;
            border: 0;
            border-bottom: 1px solid #edf0f2;
            background: transparent;
        }

        .dht-cart-desktop .dht-cart-summary table.shop_table th {
            width: 47%;
            color: var(--cart-muted);
            font-size: 13px;
            font-weight: 650;
            text-align: left;
        }

        .dht-cart-desktop .dht-cart-summary table.shop_table td {
            color: var(--cart-text);
            font-weight: 800;
            text-align: right;
        }

        .dht-cart-desktop .dht-cart-summary .wc-proceed-to-checkout {
            padding: 17px 0 0;
        }

        .dht-cart-desktop .dht-cart-summary .checkout-button {
            display: flex;
            width: 100%;
            min-height: 50px;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 12px 15px;
            border-radius: 9px;
            background: var(--dht-primary, #007acc);
            color: #fff;
            font-size: 15px;
            font-weight: 850;
            text-align: center;
        }

        .dht-cart-desktop .cross-sells {
            display: none !important;
        }

        .dht-cart-desktop .dht-cart-empty {
            padding: 36px;
            border: 1px solid var(--cart-border);
            border-radius: 14px;
            background: #fff;
            text-align: center;
        }

        .dht-cart-desktop .dht-cart-empty h2 {
            margin: 0 0 8px;
        }

        .dht-cart-desktop .dht-cart-empty p {
            margin: 0 0 18px;
            color: var(--cart-muted);
        }

        @media (max-width: 1050px) {
            .dht-cart-desktop .dht-cart-layout {
                grid-template-columns: minmax(0, 1fr) 310px;
            }
        }
    </style>

    <section class="dht-cart-hero">
        <div class="dht-container dht-cart-hero-inner">
            <div>
                <span class="dht-cart-kicker">Tu pedido</span>
                <h1>Carrito</h1>
                <p>
                    <?php if ($cart_count > 0) : ?>
                        Revisa los productos, modifica las cantidades o elimina lo que no necesites antes de finalizar la compra.
                    <?php else : ?>
                        Los productos que anadas apareceran aqui antes de finalizar la compra.
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </section>

    <section class="dht-cart-main">
        <div class="dht-container">
            <div class="dht-cart-toolbar">
                <h2>Resumen de tu compra</h2>
                <a class="dht-cart-back" href="<?php echo esc_url($shop_url); ?>">&larr; Seguir comprando</a>
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
