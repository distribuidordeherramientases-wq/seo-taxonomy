<?php
/**
 * Franja publica de campanas - escritorio.
 *
 * Personalizacion rapida: modifica las variables de color del bloque
 * .dht-campaigns-shell para mantener el formato comun cambiando la identidad.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

if (!function_exists('seo_marketing_campaigns_get_public_active')) {
    return;
}

$dht_campaigns = seo_marketing_campaigns_get_public_active();
if (!$dht_campaigns) {
    return;
}
?>
<style id="dht-campaign-strip-desktop-style">
    .dht-campaigns-shell {
        --dht-campaign-bg: #f5f7fa;
        --dht-campaign-surface: #ffffff;
        --dht-campaign-text: #17212b;
        --dht-campaign-muted: #64717d;
        --dht-campaign-border: #dfe5eb;
        --dht-campaign-accent: var(--dht-primary, #007acc);
        --dht-campaign-accent-dark: var(--dht-primary-dark, #005b96);
        --dht-campaign-price: #b42318;
        --dht-campaign-badge-bg: #fff1f0;
        --dht-campaign-badge-text: #9f1d16;
        background: var(--dht-campaign-bg);
        border-bottom: 1px solid var(--dht-campaign-border);
        color: var(--dht-campaign-text);
        padding: 16px 0 18px;
    }
    /*
     * Personalizacion opcional por serie. Ejemplo:
     * .dht-campaign--navidad { --dht-campaign-accent: #b42318; }
     * .dht-campaign--dia-del-padre { --dht-campaign-bg: #eef5ff; }
     */
    .dht-campaigns-inner {
        width: min(94%, var(--dht-container, 1180px));
        margin: 0 auto;
    }
    .dht-campaign-block + .dht-campaign-block {
        margin-top: 18px;
        padding-top: 18px;
        border-top: 1px solid var(--dht-campaign-border);
    }
    .dht-campaign-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 10px;
    }
    .dht-campaign-title {
        font-size: 20px;
        line-height: 1.2;
        font-weight: 850;
        letter-spacing: -.01em;
    }
    .dht-campaign-edition {
        margin-left: 7px;
        color: var(--dht-campaign-muted);
        font-size: 12px;
        font-weight: 700;
    }
    .dht-campaign-nav {
        display: flex;
        gap: 6px;
        flex: 0 0 auto;
    }
    .dht-campaign-nav button {
        width: 40px;
        height: 40px;
        border: 1px solid var(--dht-campaign-border);
        border-radius: 999px;
        background: var(--dht-campaign-surface);
        color: var(--dht-campaign-text);
        font-size: 22px;
        line-height: 1;
        cursor: pointer;
    }
    .dht-campaign-nav button:hover,
    .dht-campaign-nav button:focus-visible {
        border-color: var(--dht-campaign-accent);
        color: var(--dht-campaign-accent-dark);
        outline: none;
    }
    .dht-campaign-viewport {
        overflow-x: auto;
        scroll-behavior: smooth;
        scroll-snap-type: x mandatory;
        scrollbar-width: none;
        -webkit-overflow-scrolling: touch;
    }
    .dht-campaign-viewport::-webkit-scrollbar { display: none; }
    .dht-campaign-track {
        display: flex;
        gap: 16px;
        align-items: stretch;
    }
    .dht-campaign-card {
        flex: 0 0 calc((100% - 48px) / 4);
        min-width: 0;
        scroll-snap-align: start;
        border: 1px solid var(--dht-campaign-border);
        border-radius: 12px;
        background: var(--dht-campaign-surface);
        overflow: hidden;
        box-shadow: 0 3px 14px rgba(17, 32, 48, .06);
    }
    .dht-campaign-card-link {
        display: grid;
        grid-template-rows: auto 1fr;
        height: 100%;
        color: inherit;
        text-decoration: none;
    }
    .dht-campaign-image {
        position: relative;
        display: grid;
        place-items: center;
        min-height: 138px;
        padding: 10px;
        background: #fff;
    }
    .dht-campaign-image img {
        display: block;
        width: 100%;
        height: 138px;
        object-fit: contain;
    }
    .dht-campaign-discount {
        position: absolute;
        top: 9px;
        left: 9px;
        padding: 5px 8px;
        border-radius: 999px;
        background: var(--dht-campaign-badge-bg);
        color: var(--dht-campaign-badge-text);
        font-size: 12px;
        font-weight: 850;
    }
    .dht-campaign-card-body {
        display: flex;
        flex-direction: column;
        min-width: 0;
        padding: 12px 14px 14px;
    }
    .dht-campaign-product-name {
        display: -webkit-box;
        min-height: 2.6em;
        overflow: hidden;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
        color: var(--dht-campaign-text);
        font-size: 14px;
        font-weight: 750;
        line-height: 1.3;
    }
    .dht-campaign-prices {
        display: flex;
        flex-wrap: wrap;
        align-items: baseline;
        gap: 7px;
        margin-top: 9px;
    }
    .dht-campaign-price {
        color: var(--dht-campaign-price);
        font-size: 20px;
        font-weight: 900;
        line-height: 1;
    }
    .dht-campaign-regular {
        color: var(--dht-campaign-muted);
        font-size: 12px;
        text-decoration: line-through;
    }
    .dht-campaign-cta {
        margin-top: auto;
        padding-top: 10px;
        color: var(--dht-campaign-accent-dark);
        font-size: 12px;
        font-weight: 800;
    }
</style>

<section class="dht-campaigns-shell dht-campaigns-shell--desktop" aria-label="Campañas promocionales activas">
    <div class="dht-campaigns-inner">
        <?php foreach ($dht_campaigns as $dht_campaign_item) :
            $dht_campaign = $dht_campaign_item['campaign'];
            $dht_products = $dht_campaign_item['products'];
            $dht_series_class = $dht_campaign->series_key !== '' ? ' dht-campaign--' . sanitize_html_class($dht_campaign->series_key) : '';
            $dht_needs_nav = count($dht_products) > 4;
        ?>
            <div class="dht-campaign-block<?php echo esc_attr($dht_series_class); ?>" data-campaign-id="<?php echo esc_attr((string) $dht_campaign->id); ?>">
                <div class="dht-campaign-head">
                    <div class="dht-campaign-title" role="heading" aria-level="2">
                        <?php echo esc_html($dht_campaign->name); ?>
                        <?php if ($dht_campaign->edition_label !== '') : ?>
                            <span class="dht-campaign-edition"><?php echo esc_html($dht_campaign->edition_label); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($dht_needs_nav) : ?>
                        <div class="dht-campaign-nav" aria-label="Desplazar productos de la campaña">
                            <button type="button" data-dht-campaign-scroll="prev" aria-label="Productos anteriores">&#8249;</button>
                            <button type="button" data-dht-campaign-scroll="next" aria-label="Productos siguientes">&#8250;</button>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="dht-campaign-viewport" tabindex="0">
                    <div class="dht-campaign-track">
                        <?php foreach ($dht_products as $dht_product_item) :
                            $dht_product = $dht_product_item['product'];
                            $dht_regular = (float) $dht_product_item['regular_price'];
                            $dht_campaign_price = (float) $dht_product_item['campaign_price'];
                            $dht_image_html = function_exists('dht_shared_product_card_image_html')
                                ? dht_shared_product_card_image_html(
                                    $dht_product,
                                    'woocommerce_thumbnail',
                                    3,
                                    array(
                                        'loading' => 'lazy',
                                        'alt'     => (string) $dht_product_item['name'],
                                    )
                                )
                                : $dht_product->get_image(
                                    'woocommerce_thumbnail',
                                    array(
                                        'loading'  => 'lazy',
                                        'decoding' => 'async',
                                    )
                                );
                        ?>
                            <article class="dht-campaign-card">
                                <a class="dht-campaign-card-link" href="<?php echo esc_url($dht_product_item['url']); ?>">
                                    <div class="dht-campaign-image">
                                        <?php if ((int) $dht_product_item['discount_percent'] > 0) : ?>
                                            <span class="dht-campaign-discount">-<?php echo esc_html((string) $dht_product_item['discount_percent']); ?>%</span>
                                        <?php endif; ?>
                                        <?php
                                        // El helper ya escapa URL, alt, clases y atributos.
                                        // Se imprime sin wp_kses_post para conservar el onerror que
                                        // permite saltar a otra imagen de proveedor si una URL falla.
                                        echo $dht_image_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                        ?>
                                    </div>
                                    <div class="dht-campaign-card-body">
                                        <div class="dht-campaign-product-name"><?php echo esc_html($dht_product_item['name']); ?></div>
                                        <div class="dht-campaign-prices">
                                            <span class="dht-campaign-price"><?php echo wp_kses_post(wc_price($dht_campaign_price)); ?></span>
                                            <?php if ($dht_regular > $dht_campaign_price && $dht_regular > 0) : ?>
                                                <span class="dht-campaign-regular"><?php echo wp_kses_post(wc_price($dht_regular)); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="dht-campaign-cta">Ver producto</div>
                                    </div>
                                </a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<script>
(function () {
    if (window.dhtCampaignStripBound) return;
    window.dhtCampaignStripBound = true;

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-dht-campaign-scroll]');
        if (!button) return;

        var block = button.closest('.dht-campaign-block');
        if (!block) return;

        var viewport = block.querySelector('.dht-campaign-viewport');
        if (!viewport) return;

        var direction = button.getAttribute('data-dht-campaign-scroll') === 'prev' ? -1 : 1;
        viewport.scrollBy({
            left: direction * Math.max(240, viewport.clientWidth * 0.88),
            behavior: 'smooth'
        });
    });
}());
</script>
<?php
unset($dht_campaigns, $dht_campaign_item, $dht_campaign, $dht_products, $dht_product_item, $dht_product, $dht_image_html);
