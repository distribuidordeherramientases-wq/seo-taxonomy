<?php
/**
 * Plantilla de resultados de búsqueda.
 *
 * - Las búsquedas de productos siguen siendo búsquedas: no cargan template-shop.php.
 * - El loop principal de WordPress/WooCommerce ya trae los productos encontrados.
 * - Esta plantilla solo cambia su presentación a una parrilla comercial ligera.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

$search_term = get_search_query();
$requested_post_type = get_query_var('post_type');

if (!$requested_post_type && isset($_GET['post_type']) && !is_array($_GET['post_type'])) {
    $requested_post_type = sanitize_key(wp_unslash($_GET['post_type']));
}

$is_product_search = ('product' === $requested_post_type);

if (function_exists('dht_template_render_header')) {
    dht_template_render_header();
} else {
    get_header();
}
?>

<main class="dht-page dht-search-page dht-search-template">
    <section class="dht-section dht-search-section">
        <div class="dht-container">
            <div class="dht-section-header dht-section-header--left dht-search-heading">
                <span class="dht-kicker">Búsqueda</span>
                <h1>Resultados para “<?php echo esc_html($search_term); ?>”</h1>
                <?php if ($is_product_search && have_posts()) : ?>
                    <p class="dht-search-count">
                        <?php
                        global $wp_query;
                        echo esc_html(
                            sprintf(
                                _n('%s producto encontrado', '%s productos encontrados', (int) $wp_query->found_posts, 'woocommerce'),
                                number_format_i18n((int) $wp_query->found_posts)
                            )
                        );
                        ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="dht-search-form-wrap">
                <?php get_search_form(); ?>
            </div>

            <?php if ($is_product_search) : ?>

                <?php if (have_posts()) : ?>
                    <?php
                    $search_products = array();

                    while (have_posts()) {
                        the_post();
                        $candidate = function_exists('wc_get_product') ? wc_get_product(get_the_ID()) : null;
                        if ($candidate && is_a($candidate, 'WC_Product') && $candidate->is_visible()) {
                            $search_products[] = $candidate;
                        }
                    }

                    wp_reset_postdata();

                    $compare_data = array();
                    if (function_exists('dht_shared_product_compare_data')) {
                        foreach ($search_products as $candidate) {
                            $item = dht_shared_product_compare_data($candidate, 3);
                            if ($item) {
                                $compare_data[] = $item;
                            }
                        }
                    }

                    $compare_enabled = count($compare_data) >= 2;
                    $compare_id = 'dht-search-compare-' . wp_rand(1000, 999999);
                    $compare_data_id = $compare_id . '-data';
                    ?>

                    <?php if ($compare_enabled) : ?>
                        <div class="dht-search-compare-scope dht-category-compare-scope"
                             data-dht-category-compare
                             data-dht-compare-data-id="<?php echo esc_attr($compare_data_id); ?>">
                    <?php endif; ?>

                    <ul class="products dht-search-product-grid">
                        <?php foreach ($search_products as $card_product) : ?>
                            <?php
                            global $product, $post;
                            $previous_product = $product ?? null;
                            $previous_post = $post ?? null;

                            $product = $card_product;
                            $post = get_post($card_product->get_id());
                            if ($post) {
                                setup_postdata($post);
                            }

                            $product_id = $card_product->get_id();
                            $permalink = get_permalink($product_id);
                            $description = (string) $card_product->get_short_description();
                            if ('' === trim(wp_strip_all_tags($description))) {
                                $description = (string) $card_product->get_description();
                            }
                            $description = wp_trim_words(
                                wp_strip_all_tags(strip_shortcodes($description)),
                                22,
                                '…'
                            );
                            ?>

                            <li class="product dh-product-card dht-search-product-card">
                                <a href="<?php echo esc_url($permalink); ?>" class="dh-product-link dht-search-product-link">
                                    <?php if ($card_product->is_on_sale()) : ?>
                                        <span class="onsale"><?php echo esc_html__('Oferta', 'woocommerce'); ?></span>
                                    <?php endif; ?>

                                    <div class="dh-product-image dht-search-product-image">
                                        <?php
                                        if (function_exists('dht_shared_product_card_image_html')) {
                                            echo dht_shared_product_card_image_html(
                                                $card_product,
                                                'woocommerce_thumbnail',
                                                3,
                                                array('loading' => 'lazy')
                                            ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                        } else {
                                            echo wp_kses_post($card_product->get_image('woocommerce_thumbnail'));
                                        }
                                        ?>
                                    </div>

                                    <h2 class="dh-product-title dht-search-product-title">
                                        <?php echo esc_html($card_product->get_name()); ?>
                                    </h2>

                                    <?php if ($description !== '') : ?>
                                        <p class="dht-search-product-description"><?php echo esc_html($description); ?></p>
                                    <?php endif; ?>

                                    <div class="dh-product-price dht-search-product-price">
                                        <?php echo wp_kses_post($card_product->get_price_html()); ?>
                                    </div>
                                </a>

                                <div class="dh-product-actions dht-search-product-actions">
                                    <?php if (function_exists('woocommerce_template_loop_add_to_cart')) : ?>
                                        <?php woocommerce_template_loop_add_to_cart(); ?>
                                    <?php endif; ?>

                                    <?php if ($compare_enabled) : ?>
                                        <button type="button"
                                                class="dht-category-compare-toggle dht-search-compare-toggle"
                                                data-dht-compare-product="<?php echo esc_attr((string) $product_id); ?>"
                                                aria-pressed="false">
                                            Comparar
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </li>

                            <?php
                            wp_reset_postdata();
                            $product = $previous_product;
                            $post = $previous_post;
                            ?>
                        <?php endforeach; ?>
                    </ul>

                    <?php if ($compare_enabled) : ?>
                        <div class="dht-category-live-compare dht-search-live-compare" data-dht-compare-toolbar hidden>
                            <div class="dht-category-live-compare-toolbar">
                                <span class="dht-category-live-compare-count" data-dht-compare-count>0 productos seleccionados</span>
                                <button type="button" data-dht-compare-show disabled>Comparar seleccionados</button>
                                <button type="button" data-dht-compare-clear>Limpiar</button>
                                <p class="dht-category-live-compare-note">Selecciona entre 2 y 4 productos para ver sus diferencias.</p>
                            </div>
                            <div class="dht-category-live-compare-result" data-dht-compare-result hidden></div>
                        </div>

                        <script type="application/json" id="<?php echo esc_attr($compare_data_id); ?>"><?php
                            echo wp_json_encode(
                                $compare_data,
                                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                            );
                        ?></script>
                        </div>

                        <?php
                        if (function_exists('dht_shared_render_category_compare_assets')) {
                            dht_shared_render_category_compare_assets();
                        }
                        ?>
                    <?php endif; ?>

                    <nav class="dht-search-pagination" aria-label="Paginación de resultados">
                        <?php
                        the_posts_pagination(array(
                            'mid_size'  => 2,
                            'prev_text' => '←',
                            'next_text' => '→',
                        ));
                        ?>
                    </nav>

                <?php else : ?>
                    <div class="dht-empty-state">
                        <h2>Sin resultados</h2>
                        <p>Prueba con términos más generales o consulta el catálogo completo.</p>
                        <a class="dht-btn dht-btn-primary" href="<?php echo esc_url(dht_template_shop_url()); ?>">Ver tienda</a>
                    </div>
                <?php endif; ?>

            <?php else : ?>

                <?php if (have_posts()) : ?>
                    <div class="dht-search-results">
                        <?php while (have_posts()) : the_post(); ?>
                            <article class="dht-search-result">
                                <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                                <p><?php echo esc_html(wp_trim_words(wp_strip_all_tags(get_the_excerpt()), 30)); ?></p>
                            </article>
                        <?php endwhile; ?>
                    </div>
                    <?php the_posts_pagination(); ?>
                <?php else : ?>
                    <div class="dht-empty-state">
                        <h2>Sin resultados</h2>
                        <p>Prueba con términos más generales.</p>
                    </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    </section>
</main>

<style id="dht-search-product-grid-styles">
.dht-search-template .dht-search-heading {
    margin-bottom: 18px;
}
.dht-search-template .dht-search-count {
    margin: 8px 0 0;
    color: #64748b;
    font-size: 14px;
}
.dht-search-template .dht-search-form-wrap {
    margin-bottom: 24px;
}
.dht-search-template .dht-search-product-grid {
    display: grid !important;
    grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
    gap: 16px !important;
    margin: 0 !important;
    padding: 0 !important;
    list-style: none !important;
}
.dht-search-template .dht-search-product-card {
    position: relative;
    display: flex !important;
    min-width: 0 !important;
    width: auto !important;
    max-width: none !important;
    height: 100%;
    margin: 0 !important;
    padding: 12px !important;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid #e2e8f0 !important;
    border-radius: 12px !important;
    background: #fff !important;
    box-shadow: 0 3px 12px rgba(15, 23, 42, .05) !important;
}
.dht-search-template .dht-search-product-link {
    display: flex;
    min-width: 0;
    flex: 1;
    flex-direction: column;
    color: inherit;
    text-decoration: none;
}
.dht-search-template .dht-search-product-image {
    display: grid;
    height: 190px;
    place-items: center;
    margin-bottom: 10px;
    overflow: hidden;
    border-radius: 8px;
    background: #fff;
}
.dht-search-template .dht-search-product-image img {
    width: 100% !important;
    height: 190px !important;
    margin: 0 !important;
    padding: 0 !important;
    object-fit: contain !important;
    background: #fff !important;
}
.dht-search-template .dht-search-product-title {
    min-height: 3.8em;
    margin: 0 0 8px !important;
    color: #0f172a;
    font-size: 14px !important;
    font-weight: 750;
    line-height: 1.35;
    overflow-wrap: anywhere;
}
.dht-search-template .dht-search-product-description {
    display: -webkit-box;
    min-height: 4.2em;
    margin: 0 0 10px;
    overflow: hidden;
    color: #64748b;
    font-size: 12px;
    line-height: 1.4;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 3;
}
.dht-search-template .dht-search-product-price {
    margin-top: auto;
    margin-bottom: 10px;
    color: #b12704;
    font-size: 17px;
    font-weight: 850;
}
.dht-search-template .dht-search-product-price del {
    color: #64748b;
    font-size: 12px;
    font-weight: 600;
}
.dht-search-template .dht-search-product-price ins {
    text-decoration: none;
}
.dht-search-template .dht-search-product-actions {
    display: grid !important;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-top: auto;
}
.dht-search-template .dht-search-product-actions .button,
.dht-search-template .dht-search-compare-toggle {
    display: inline-flex !important;
    width: 100% !important;
    min-height: 38px !important;
    align-items: center;
    justify-content: center;
    margin: 0 !important;
    padding: 8px 9px !important;
    border-radius: 8px !important;
    font-size: 11px !important;
    font-weight: 800 !important;
    line-height: 1.2;
    text-align: center;
}
.dht-search-template .dht-search-product-actions .button {
    border: 1px solid #f2b705 !important;
    background: #f2b705 !important;
    color: #111827 !important;
}
.dht-search-template .dht-search-compare-toggle {
    border: 1px solid #cbd5e1 !important;
    background: #fff !important;
    color: #0f172a !important;
}
.dht-search-template .dht-search-compare-toggle[aria-pressed="true"] {
    border-color: #0f5f8f !important;
    background: #eef7fb !important;
    color: #0f5f8f !important;
}
.dht-search-template .dht-search-live-compare {
    margin-top: 18px;
}
.dht-search-template .dht-search-pagination {
    margin-top: 28px;
}
.dht-search-template .dht-search-pagination .nav-links {
    display: flex;
    justify-content: center;
    gap: 7px;
    flex-wrap: wrap;
}
.dht-search-template .dht-search-pagination .page-numbers {
    display: grid;
    min-width: 42px;
    min-height: 42px;
    place-items: center;
    padding: 8px 11px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #fff;
    text-decoration: none;
}
.dht-search-template .dht-search-pagination .page-numbers.current {
    border-color: #0f5f8f;
    background: #0f5f8f;
    color: #fff;
}
@media (max-width: 1023px) {
    .dht-search-template .dht-search-product-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
    }
}
@media (max-width: 768px) {
    .dht-search-template .dht-search-product-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 9px !important;
    }
    .dht-search-template .dht-search-product-card {
        padding: 9px !important;
        border-radius: 10px !important;
    }
    .dht-search-template .dht-search-product-image,
    .dht-search-template .dht-search-product-image img {
        height: 138px !important;
    }
    .dht-search-template .dht-search-product-title {
        min-height: 3.7em;
        font-size: 12px !important;
        line-height: 1.32;
    }
    .dht-search-template .dht-search-product-description {
        min-height: 3.9em;
        font-size: 10.5px;
        -webkit-line-clamp: 3;
    }
    .dht-search-template .dht-search-product-price {
        font-size: 15px;
    }
    .dht-search-template .dht-search-product-actions {
        grid-template-columns: 1fr;
    }
    .dht-search-template .dht-search-product-actions .button,
    .dht-search-template .dht-search-compare-toggle {
        min-height: 35px !important;
        padding: 7px 5px !important;
        font-size: 10px !important;
    }
}
@media (max-width: 380px) {
    .dht-search-template .dht-search-product-image,
    .dht-search-template .dht-search-product-image img {
        height: 122px !important;
    }
}
</style>

<?php
if (function_exists('dht_template_render_footer')) {
    dht_template_render_footer();
} else {
    get_footer();
}
