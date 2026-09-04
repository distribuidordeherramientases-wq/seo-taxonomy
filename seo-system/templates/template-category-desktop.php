<?php
/*
 * Plantilla de categorías WooCommerce
 * Sistema visual común DHT
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

$amazon_category_template = __DIR__ . '/template-amazon-category.php';
if (is_readable($amazon_category_template)) {
    require_once $amazon_category_template;
}


/* ==========================================================
   EVITAR DESCRIPCIÓN DUPLICADA DE WOOCOMMERCE
========================================================== */

remove_action(
    'woocommerce_archive_description',
    'woocommerce_taxonomy_archive_description',
    10
);


/* ==========================================================
   CATEGORÍA ACTUAL
========================================================== */

$term = get_queried_object();

if (
    !$term ||
    empty($term->term_id) ||
    empty($term->taxonomy) ||
    $term->taxonomy !== 'product_cat'
) {
    dht_template_render_header();
    echo '<main class="dht-page dht-status-page"><section class="dht-section"><div class="dht-content dht-status-card"><h1>Categoría no disponible</h1></div></section></main>';
    dht_template_render_footer();
    return;
}

/* ==========================================================
   CABECERA Y ESTILOS COMPARTIDOS
========================================================== */

dht_template_render_header();


/* ==========================================================
   DATOS SEO
========================================================== */

$excerpt = get_term_meta(
    $term->term_id,
    'seo_excerpt',
    true
);

global $wpdb;

$keywords = (string) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT keywords
         FROM {$wpdb->prefix}seo_nodes
         WHERE object_type = 'category'
           AND object_id = %d
           AND seo_role = 'category'
           AND status = 1
         ORDER BY id DESC
         LIMIT 1",
        $term->term_id
    )
);

$category_description = (string) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT keywords
         FROM {$wpdb->prefix}seo_nodes
         WHERE object_type = 'category'
           AND object_id = %d
           AND seo_role = 'description'
           AND status = 1
         ORDER BY id DESC
         LIMIT 1",
        $term->term_id
    )
);

$category_tags = array_values(
    array_filter(
        array_map(
            'trim',
            explode(',', $keywords)
        )
    )
);


/* ==========================================================
   IMAGEN
========================================================== */

$thumbnail_id = get_term_meta(
    $term->term_id,
    'thumbnail_id',
    true
);

$category_image = dht_template_term_image_url($term->term_id, 'large', true);

if (!$category_image) {
    $category_image = dht_template_placeholder_image_url('woocommerce_single');
}


/* ==========================================================
   SUBCATEGORÍAS PARA JSON-LD
========================================================== */

$subcats_json = get_terms([
    'taxonomy'   => 'product_cat',
    'parent'     => $term->term_id,
    'hide_empty' => true,
]);

$item_list = [];

if(!is_wp_error($subcats_json)){
    foreach($subcats_json as $cat){
        $term_link = get_term_link($cat);

        if(is_wp_error($term_link)){
            continue;
        }

        $item_list[] = [
            '@type'    => 'ListItem',
            'position' => count($item_list) + 1,
            'name'     => $cat->name,
            'url'      => $term_link,
        ];
    }
}

$current_term_link = get_term_link($term);

$json = [
    '@context'    => 'https://schema.org',
    '@type'       => 'CollectionPage',
    'name'        => $term->name,
    'url'         => is_wp_error($current_term_link) ? '' : $current_term_link,
    'description' => wp_strip_all_tags($category_description),
    'hasPart'     => [
        [
            '@type'           => 'ItemList',
            'itemListElement' => $item_list,
        ],
    ],
];
?>
<!-- DHT CATEGORY COMPARE + VEVOR PATCH 2026-09-04 -->


<script type="application/ld+json">
<?php
echo wp_json_encode(
    $json,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
);
?>
</script>


<main class="dht-page dht-category-page dht-desktop-template">


    <!-- =====================================================
         HERO DE CATEGORÍA
    ====================================================== -->

    <section class="dht-category-hero">

        <div class="dht-container">

            <div class="dht-category-hero-grid dht-desktop-category-hero-grid">

                <div class="dht-category-media">

                    <img
                        src="<?php echo esc_url($category_image); ?>"
                        alt="<?php echo esc_attr($term->name); ?>"
                        loading="eager"
                    >

                </div>

                <div class="dht-category-summary">

                    <span class="dht-kicker">
                        Categoría de productos
                    </span>

                    <h1>
                        <?php echo esc_html($term->name); ?>
                    </h1>

                    <?php if(!empty($excerpt)): ?>

                        <div class="dht-category-excerpt">
                            <?php echo wp_kses_post($excerpt); ?>
                        </div>

                    <?php endif; ?>

                    <?php if(!empty($category_tags)): ?>

                        <div class="dht-category-features">

                            <h2>
                                Características principales
                            </h2>

                            <div class="dht-category-tags">

                                <?php foreach($category_tags as $category_tag): ?>

                                    <span class="dht-category-tag">
                                        <?php echo esc_html($category_tag); ?>
                                    </span>

                                <?php endforeach; ?>

                            </div>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </section>


    <!-- =====================================================
         DESCRIPCIÓN DE LA CATEGORÍA
    ====================================================== -->

    <?php if(!empty($category_description)): ?>

        <section class="dht-section dht-category-description-section">

            <div class="dht-container">

                <div class="dht-category-description">
                    <?php echo wp_kses_post($category_description); ?>
                </div>

            </div>

        </section>

    <?php endif; ?>


    <!-- =====================================================
         AYUDA PARA ELEGIR / COMPARATIVA ESTÁTICA
    ====================================================== -->

    <?php
    $category_comparison_template = __DIR__ . '/template-category-comparison.php';

    if (is_readable($category_comparison_template)) {
        include $category_comparison_template;
    }
    ?>


    <!-- =====================================================
         PRODUCTOS DE LA CATEGORÍA
    ====================================================== -->

    <?php
    $category_products = new WP_Query([
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => 12,
        'orderby'        => [
            'menu_order' => 'ASC',
            'date'       => 'DESC',
        ],
        'tax_query'      => [
            [
                'taxonomy'         => 'product_cat',
                'field'            => 'term_id',
                'terms'            => [$term->term_id],
                'include_children' => true,
            ],
        ],
    ]);
    ?>

    <?php if($category_products->have_posts()): ?>

        <?php
        wc_set_loop_prop('columns', 4);
        wc_set_loop_prop('total', $category_products->post_count);
        ?>

        <section class="dht-section dht-category-products">

            <div class="dht-container">

                <div class="dht-category-products-panel dht-desktop-products-panel">

                    <header class="dht-section-header">

                        <h2 class="dht-section-title">
                            Productos de <?php echo esc_html($term->name); ?>
                        </h2>

                        <p class="dht-section-subtitle">
                            Consulta una selección de productos disponibles en esta categoría.
                        </p>

                    </header>

                    <?php
                    /*
                     * Tarjetas renderizadas por el sistema DHT para no depender
                     * de content-product.php del child theme. La imagen se resuelve:
                     * Media -> hasta 3 URLs del proveedor -> logo.
                     */
                    $grid_products = array();
                    foreach ((array) $category_products->posts as $product_post) {
                        $grid_product = wc_get_product($product_post->ID);
                        if ($grid_product && is_a($grid_product, 'WC_Product')) {
                            $grid_products[] = $grid_product;
                        }
                    }
                    dht_shared_render_product_grid($grid_products, 'dht-category-product-grid', 3, true);
                    ?>

                </div>

            </div>

        </section>

    <?php endif; ?>

    <?php wp_reset_postdata(); ?>

    <?php
    if (function_exists('dht_render_amazon_category_block')) {
        dht_render_amazon_category_block($term, array(
            'limit' => 8,
            'title' => 'Productos que te pueden interesar',
            'mode'  => 'dynamic',
        ));
    }
    ?>

    <?php
    if (function_exists('dht_render_vevor_affiliate_category_block')) {
        dht_render_vevor_affiliate_category_block($term, array(
            'limit' => 8,
            'title' => 'Descubre otros productos en VEVOR',
        ));
    }
    ?>


    <!-- =====================================================
         CATEGORÍAS RELACIONADAS
    ====================================================== -->

    <?php
    $related_categories = get_terms([
        'taxonomy'   => 'product_cat',
        'parent'     => $term->term_id,
        'hide_empty' => true,
        'number'     => 12,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);

    /*
     * Si no tiene subcategorías, mostrar categorías hermanas.
     */
    if(empty($related_categories) || is_wp_error($related_categories)){
        $related_categories = get_terms([
            'taxonomy'   => 'product_cat',
            'parent'     => (int) $term->parent,
            'exclude'    => [$term->term_id],
            'hide_empty' => true,
            'number'     => 8,
            'orderby'    => 'count',
            'order'      => 'DESC',
        ]);
    }
    ?>

    <?php if(!empty($related_categories) && !is_wp_error($related_categories)): ?>

        <section class="dht-section dht-category-related">

            <div class="dht-container">

                <header class="dht-section-header">

                    <h2 class="dht-section-title">
                        Categorías relacionadas
                    </h2>

                    <p class="dht-section-subtitle">
                        Encuentra herramientas y equipamiento dentro de categorías relacionadas.
                    </p>

                </header>

                <div class="dht-category-grid dht-desktop-related-grid">

                    <?php foreach($related_categories as $related_category): ?>

                        <?php
                        $related_link = get_term_link($related_category);

                        if(is_wp_error($related_link)){
                            continue;
                        }

                        $related_image = dht_template_term_image_url($related_category->term_id, 'woocommerce_thumbnail', true);
                        if (!$related_image) {
                            $related_image = dht_template_placeholder_image_url('woocommerce_thumbnail');
                        }

                        $related_description_html = (string) $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT keywords
                                 FROM {$wpdb->prefix}seo_nodes
                                 WHERE object_type = 'category'
                                   AND object_id = %d
                                   AND seo_role = 'description'
                                   AND status = 1
                                 ORDER BY id DESC
                                 LIMIT 1",
                                $related_category->term_id
                            )
                        );

                        $related_description = !empty($related_description_html)
                            ? wp_trim_words(
                                wp_strip_all_tags($related_description_html),
                                22
                            )
                            : 'Ver herramientas y productos disponibles en esta categoría.';
                        ?>

                        <a
                            class="dht-category-card"
                            href="<?php echo esc_url($related_link); ?>"
                        >

                            <img
                                src="<?php echo esc_url($related_image); ?>"
                                alt="<?php echo esc_attr($related_category->name); ?>"
                                loading="lazy"
                                width="300"
                                height="300"
                            >

                            <div class="dht-category-content">

                                <h3>
                                    <?php echo esc_html($related_category->name); ?>
                                </h3>

                                <p>
                                    <?php echo esc_html($related_description); ?>
                                </p>

                                <div class="dht-card-footer">

                                    <span class="dht-category-card-meta">
                                        <?php
                                        echo esc_html(
                                            number_format_i18n(
                                                $related_category->count
                                            )
                                        );
                                        ?>
                                        productos
                                    </span>

                                    <span>
                                        Ver categoría →
                                    </span>

                                </div>

                            </div>

                        </a>

                    <?php endforeach; ?>

                </div>

            </div>

        </section>

    <?php endif; ?>


    <!-- =====================================================
         FAQS DE LA CATEGORÍA
    ====================================================== -->

    <?php
    $faq_object_type = 2;
    $faq_object_id   = $term->term_id;
    $faq_ambito      = '';

    $faq_template = __DIR__ . '/template-faq.php';

    if(file_exists($faq_template)){
        include $faq_template;
    }
    ?>


    <!-- =====================================================
         FORMULARIO DE PREGUNTAS
    ====================================================== -->

    <?php
    $faq_form_object_type = 2;
    $faq_form_object_id   = $term->term_id;
    $faq_form_ambito      = '';

    $faq_form_template = __DIR__ . '/faq-form.php';

    if(file_exists($faq_form_template)){
        include $faq_form_template;
    }
    ?>


</main>


<?php dht_template_render_footer(); ?>