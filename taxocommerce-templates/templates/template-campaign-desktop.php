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

wp_enqueue_style(
    'dht-campaign-desktop',
    SEO_SYSTEM_URL . 'seo-system/templates/assets/campaign-desktop.css',
    array(),
    SEO_SYSTEM_VERSION
);
wp_enqueue_script(
    'dht-campaign-desktop',
    SEO_SYSTEM_URL . 'seo-system/templates/assets/campaign-desktop.js',
    array(),
    SEO_SYSTEM_VERSION,
    true
);
if (did_action('wp_head')) {
    wp_print_styles('dht-campaign-desktop');
}
?>


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
                            <?php
                            $dht_campaign_url = function_exists('seo_marketing_performance_campaign_url')
                                ? seo_marketing_performance_campaign_url($dht_campaign, absint($dht_product_item['id']), (string) $dht_product_item['url'])
                                : (string) $dht_product_item['url'];
                            ?>
                            <article class="dht-campaign-card">
                                <a class="dht-campaign-card-link" href="<?php echo esc_url($dht_campaign_url); ?>">
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


<?php
unset($dht_campaigns, $dht_campaign_item, $dht_campaign, $dht_products, $dht_product_item, $dht_product, $dht_image_html, $dht_campaign_url);
