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
                                /* translators: %s: número localizado de productos encontrados. */
                                _n('%s producto encontrado', '%s productos encontrados', (int) $wp_query->found_posts, 'taxocommerce-templates'),
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
                                        <span class="onsale"><?php echo esc_html__('Oferta', 'taxocommerce-templates'); ?></span>
                                    <?php endif; ?>

                                    <div class="dh-product-image dht-search-product-image">
                                        <?php
                                        if (function_exists('dht_shared_product_card_image_html')) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared image helper returns context-escaped HTML and may include functional fallback attributes.
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

<?php
wp_enqueue_style(
    'dht-search-template',
    TCT_URL . 'templates/assets/search.css',
    array(),
    TCT_VERSION
);
if (did_action('wp_head')) {
    wp_print_styles('dht-search-template');
}
?>

<?php
if (function_exists('dht_template_render_footer')) {
    dht_template_render_footer();
} else {
    get_footer();
}
