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


<main class="dht-page dht-category-page dht-mobile-template">


    <!-- =====================================================
         HERO DE CATEGORÍA
    ====================================================== -->

    <section class="dht-category-hero">

        <div class="dht-container">

            <div class="dht-category-hero-grid dht-mobile-category-hero-grid">

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
        wc_set_loop_prop('columns', 2);
        wc_set_loop_prop('total', $category_products->post_count);
        ?>

        <section class="dht-section dht-category-products">

            <div class="dht-container">

                <div class="dht-category-products-panel dht-mobile-products-panel">

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

                <div class="dht-category-grid dht-mobile-related-rail">

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



    <!-- =====================================================
         VEVOR DIRECTO DESDE wp_seo_proveedores_productos
         8 productos aleatorios descartados
    ====================================================== -->

    <?php
    $dht_vevor_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT
                id,
                proveedor_id_externo,
                sku,
                url_origen,
                url_canonica,
                nombre,
                categoria_proveedor,
                precio_con_iva,
                moneda,
                imagenes
             FROM {$wpdb->prefix}seo_proveedores_productos
             WHERE proveedor = %s
               AND estado_seleccion = %s
             ORDER BY RAND()
             LIMIT %d",
            'vevor',
            'descartado',
            8
        ),
        ARRAY_A
    );

    if (!is_array($dht_vevor_rows)) {
        $dht_vevor_rows = array();
    }

    echo '<!-- DHT VEVOR DIRECT ROWS: ' . esc_html((string) count($dht_vevor_rows)) . ' -->';

    $dht_vevor_parse_images = static function ($raw) {
        $urls = array();
        $seen = array();

        $add = static function ($value) use (&$urls, &$seen) {
            if (is_array($value)) {
                foreach (array('url', 'image_url', 'src', 'image') as $key) {
                    if (!empty($value[$key])) {
                        $value = $value[$key];
                        break;
                    }
                }
            }

            if (!is_scalar($value)) {
                return;
            }

            $url = esc_url_raw(trim((string) $value));
            if ($url === '' || isset($seen[$url])) {
                return;
            }

            $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
            if (!in_array($scheme, array('http', 'https'), true)) {
                return;
            }

            $seen[$url] = true;
            $urls[] = $url;
        };

        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $value) {
                $add($value);
            }
        } else {
            $unserialized = maybe_unserialize($raw);
            if (is_array($unserialized)) {
                foreach ($unserialized as $value) {
                    $add($value);
                }
            } else {
                foreach (preg_split('/[\\r\\n|,;]+/', (string) $raw) as $value) {
                    $add($value);
                }
            }
        }

        return $urls;
    };
    ?>

    <?php if (!empty($dht_vevor_rows)) : ?>

        <section class="dht-section dht-vevor-direct-section" data-dht-vevor-direct="1">

            <style id="dht-vevor-direct-styles">
                .dht-vevor-direct-section{padding-top:56px}
                .dht-vevor-direct-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}
                .dht-vevor-direct-card{display:flex;min-width:0;overflow:hidden;flex-direction:column;border:1px solid #e2e5e9;border-radius:14px;background:#fff;box-shadow:0 4px 16px rgba(20,28,38,.05)}
                .dht-vevor-direct-media{position:relative;display:flex;align-items:center;justify-content:center;aspect-ratio:1/1;padding:10px;background:#f7f8f9;text-decoration:none;overflow:hidden}
                .dht-vevor-direct-media img{width:100%;height:100%;object-fit:contain}
                .dht-vevor-direct-badge{position:absolute;left:10px;top:10px;padding:5px 8px;border:1px solid #dfe4e8;border-radius:999px;background:rgba(255,255,255,.96);font-size:10px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#1f2933}
                .dht-vevor-direct-body{display:flex;flex:1;flex-direction:column;gap:8px;padding:13px}
                .dht-vevor-direct-category{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
                .dht-vevor-direct-title{margin:0;font-size:14px;line-height:1.4;color:#17202a;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
                .dht-vevor-direct-price{font-size:16px;font-weight:800;color:#17202a}
                .dht-vevor-direct-cta{display:inline-flex;align-items:center;justify-content:center;margin-top:auto;min-height:42px;padding:9px 10px;border-radius:8px;background:#e6452d;color:#fff!important;text-decoration:none;font-size:12px;font-weight:800}
                .dht-vevor-direct-cta:hover,.dht-vevor-direct-cta:focus{background:#c93721}
                .dht-vevor-direct-disclosure{margin:13px 0 0;font-size:11px;color:#747c85}
                @media (min-width:768px){.dht-vevor-direct-grid{grid-template-columns:repeat(4,minmax(0,1fr));gap:18px}}
            </style>

            <div class="dht-container">
                <header class="dht-section-header">
                    <span class="dht-kicker">Selección VEVOR</span>
                    <h2 class="dht-section-title">Descubre otros productos en VEVOR</h2>
                    <p class="dht-section-subtitle">8 productos aleatorios de nuestra tabla de proveedores.</p>
                </header>

                <div class="dht-vevor-direct-grid">
                    <?php foreach ($dht_vevor_rows as $dht_vevor_row) : ?>
                        <?php
                        $dht_vevor_title = trim((string) ($dht_vevor_row['nombre'] ?? 'Producto VEVOR'));
                        if ($dht_vevor_title === '') {
                            $dht_vevor_title = 'Producto VEVOR';
                        }

                        $dht_vevor_images = $dht_vevor_parse_images($dht_vevor_row['imagenes'] ?? '');
                        $dht_vevor_image = !empty($dht_vevor_images[0])
                            ? $dht_vevor_images[0]
                            : dht_template_placeholder_image_url('woocommerce_thumbnail');

                        $dht_vevor_url = trim((string) ($dht_vevor_row['url_canonica'] ?? ''));
                        if ($dht_vevor_url === '') {
                            $dht_vevor_url = trim((string) ($dht_vevor_row['url_origen'] ?? ''));
                        }
                        $dht_vevor_url = esc_url_raw($dht_vevor_url);

                        if ($dht_vevor_url !== '') {
                            $dht_vevor_url = add_query_arg(
                                array(
                                    'utm_source'   => 'inhouse',
                                    'utm_medium'   => 'affiliate',
                                    'utm_campaign' => '53435399',
                                ),
                                remove_query_arg(
                                    array('utm_source', 'utm_medium', 'utm_campaign'),
                                    $dht_vevor_url
                                )
                            );
                        }

                        $dht_vevor_category = trim((string) ($dht_vevor_row['categoria_proveedor'] ?? 'VEVOR'));
                        if ($dht_vevor_category === '') {
                            $dht_vevor_category = 'VEVOR';
                        }

                        $dht_vevor_price = isset($dht_vevor_row['precio_con_iva'])
                            ? (float) $dht_vevor_row['precio_con_iva']
                            : 0;
                        $dht_vevor_currency = strtoupper(trim((string) ($dht_vevor_row['moneda'] ?? 'EUR')));
                        ?>

                        <article class="dht-vevor-direct-card" data-vevor-row-id="<?php echo esc_attr(absint($dht_vevor_row['id'] ?? 0)); ?>">
                            <?php if ($dht_vevor_url !== '') : ?>
                                <a class="dht-vevor-direct-media" href="<?php echo esc_url($dht_vevor_url); ?>" target="_blank" rel="sponsored noopener">
                            <?php else : ?>
                                <div class="dht-vevor-direct-media">
                            <?php endif; ?>

                                <img src="<?php echo esc_url($dht_vevor_image); ?>" alt="<?php echo esc_attr($dht_vevor_title); ?>" loading="lazy" decoding="async">
                                <span class="dht-vevor-direct-badge">VEVOR</span>

                            <?php if ($dht_vevor_url !== '') : ?>
                                </a>
                            <?php else : ?>
                                </div>
                            <?php endif; ?>

                            <div class="dht-vevor-direct-body">
                                <span class="dht-vevor-direct-category"><?php echo esc_html($dht_vevor_category); ?></span>
                                <h3 class="dht-vevor-direct-title"><?php echo esc_html($dht_vevor_title); ?></h3>

                                <?php if ($dht_vevor_price > 0) : ?>
                                    <div class="dht-vevor-direct-price">
                                        <?php
                                        if ($dht_vevor_currency === 'EUR' && function_exists('wc_price')) {
                                            echo wp_kses_post(wc_price($dht_vevor_price));
                                        } else {
                                            echo esc_html(number_format_i18n($dht_vevor_price, 2) . ' ' . ($dht_vevor_currency ?: 'EUR'));
                                        }
                                        ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($dht_vevor_url !== '') : ?>
                                    <a class="dht-vevor-direct-cta" href="<?php echo esc_url($dht_vevor_url); ?>" target="_blank" rel="sponsored noopener">Ver en VEVOR</a>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <p class="dht-vevor-direct-disclosure">Enlaces patrocinados. La selección cambia cuando la página se vuelve a generar.</p>
            </div>
        </section>

    <?php elseif (current_user_can('manage_options')) : ?>
        <section class="dht-section"><div class="dht-container"><p><strong>VEVOR directo:</strong> la consulta devolvió 0 filas para proveedor=vevor y estado_seleccion=descartado.</p></div></section>
    <?php endif; ?>

    <!-- DHT VEVOR DIRECT BOTTOM PATCH 2026-09-05 -->

</main>


<?php dht_template_render_footer(); ?>