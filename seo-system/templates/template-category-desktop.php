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

/*
 * Las combinaciones de filtros son navegación de usuario, no landings SEO.
 * Se mantienen rastreables pero fuera del índice para evitar URLs facetadas.
 */
if (function_exists('dht_template_category_has_filter_query') && dht_template_category_has_filter_query()) {
    add_filter('wp_robots', static function ($robots) {
        $robots['noindex'] = true;
        $robots['follow'] = true;
        return $robots;
    });
}

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

$category_description_plain = trim(wp_strip_all_tags($category_description));

/*
 * Preguntas y respuestas de Dependiente.
 * Internamente siguen almacenadas en seo_faq; el cambio es de presentacion
 * publica en la categoria, no de contrato de datos.
 */
$faq_table = $wpdb->prefix . 'seo_faq';
$category_faqs = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id, question, answer
         FROM {$faq_table}
         WHERE object_type = %d
           AND object_id = %d
           AND active = 1
         ORDER BY sort_order ASC, id ASC",
        2,
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
   JSON-LD GOOGLE: COLLECTIONPAGE + ITEMLIST + BREADCRUMBLIST
========================================================== */
$subcats_json = get_terms(array(
    'taxonomy'   => 'product_cat',
    'parent'     => $term->term_id,
    'hide_empty' => true,
));

$item_list = array();
if (!is_wp_error($subcats_json)) {
    foreach ($subcats_json as $cat) {
        $term_link = get_term_link($cat);
        if (is_wp_error($term_link)) {
            continue;
        }

        $item_list[] = array(
            '@type'    => 'ListItem',
            'position' => count($item_list) + 1,
            'name'     => $cat->name,
            'item'     => $term_link,
        );
    }
}

$current_term_link = get_term_link($term);
$category_url = is_wp_error($current_term_link) ? '' : esc_url_raw((string) $current_term_link);
$collection_id = $category_url !== '' ? $category_url . '#collection' : '';
$item_list_id = $category_url !== '' ? $category_url . '#subcategories' : '';
$breadcrumb_id = $category_url !== '' ? $category_url . '#breadcrumb' : '';

$breadcrumb_items = array(
    array(
        '@type'    => 'ListItem',
        'position' => 1,
        'name'     => 'Inicio',
        'item'     => trailingslashit(home_url('/')),
    ),
);

$ancestor_ids = array_reverse(get_ancestors($term->term_id, 'product_cat', 'taxonomy'));
foreach ($ancestor_ids as $ancestor_id) {
    $ancestor = get_term(absint($ancestor_id), 'product_cat');
    if (!$ancestor || is_wp_error($ancestor)) {
        continue;
    }

    $ancestor_link = get_term_link($ancestor);
    if (is_wp_error($ancestor_link)) {
        continue;
    }

    $breadcrumb_items[] = array(
        '@type'    => 'ListItem',
        'position' => count($breadcrumb_items) + 1,
        'name'     => $ancestor->name,
        'item'     => $ancestor_link,
    );
}

$breadcrumb_items[] = array(
    '@type'    => 'ListItem',
    'position' => count($breadcrumb_items) + 1,
    'name'     => $term->name,
    'item'     => $category_url,
);

$collection = array(
    '@type' => 'CollectionPage',
    'name'  => $term->name,
    'url'   => $category_url,
);
if ($collection_id !== '') {
    $collection['@id'] = $collection_id;
}

$schema_category_description = trim(wp_strip_all_tags((string) $category_description));
if ($schema_category_description !== '') {
    $collection['description'] = $schema_category_description;
}
if ($breadcrumb_id !== '') {
    $collection['breadcrumb'] = array('@id' => $breadcrumb_id);
}
if (!empty($item_list) && $item_list_id !== '') {
    $collection['mainEntity'] = array('@id' => $item_list_id);
}

$schema_graph = array($collection);

if (!empty($item_list)) {
    $subcategories = array(
        '@type'           => 'ItemList',
        'name'            => 'Subcategorías de ' . $term->name,
        'numberOfItems'   => count($item_list),
        'itemListElement' => $item_list,
    );
    if ($item_list_id !== '') {
        $subcategories['@id'] = $item_list_id;
    }
    $schema_graph[] = $subcategories;
}

$breadcrumb = array(
    '@type'           => 'BreadcrumbList',
    'itemListElement' => $breadcrumb_items,
);
if ($breadcrumb_id !== '') {
    $breadcrumb['@id'] = $breadcrumb_id;
}
$schema_graph[] = $breadcrumb;

$json = array(
    '@context' => 'https://schema.org',
    '@graph'   => $schema_graph,
);
?>
<!-- DHT CATEGORY ORDER V2 2026-09-06 -->

<script type="application/ld+json" id="dht-schema-category">
<?php echo wp_json_encode($json, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
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
                        Explora y compara
                    </span>

                    <h1>
                        <?php echo esc_html($term->name); ?>
                    </h1>

                    <?php if(!empty($excerpt)): ?>

                        <div class="dht-category-excerpt">
                            <?php echo wp_kses_post($excerpt); ?>
                        </div>

                    <?php endif; ?>

                    <?php if ($category_description_plain !== '' || !empty($category_faqs)) : ?>
                        <div class="dht-category-knowledge-row" aria-label="Información de la categoría">
                            <?php if ($category_description_plain !== '') : ?>
                                <details class="dht-category-knowledge-card">
                                    <summary>
                                        <span>Información de la categoría</span>
                                        <span class="dht-category-knowledge-card__icon" aria-hidden="true"></span>
                                    </summary>
                                    <div class="dht-category-knowledge-card__body">
                                        <?php echo wp_kses_post($category_description); ?>
                                    </div>
                                </details>
                            <?php endif; ?>

                            <?php if (!empty($category_faqs)) : ?>
                                <details class="dht-category-knowledge-card">
                                    <summary>
                                        <span>Preguntas y respuestas de Dependiente</span>
                                        <small><?php echo esc_html(number_format_i18n(count($category_faqs))); ?> preguntas</small>
                                        <span class="dht-category-knowledge-card__icon" aria-hidden="true"></span>
                                    </summary>
                                    <div class="dht-category-knowledge-card__body dht-category-dependiente-qa">
                                        <?php foreach ($category_faqs as $category_faq) : ?>
                                            <details
                                                class="dht-category-dependiente-qa__item"
                                                data-seo-faq-id="<?php echo esc_attr((string) $category_faq->id); ?>"
                                            >
                                                <summary><?php echo esc_html($category_faq->question); ?></summary>
                                                <div class="dht-category-dependiente-qa__answer">
                                                    <?php echo wp_kses_post($category_faq->answer); ?>
                                                </div>
                                            </details>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="dht-category-hero-meta" aria-label="Resumen de la categoría">
                        <span><strong><?php echo esc_html(number_format_i18n((int) $term->count)); ?></strong> productos</span>
                        <span>Comparación disponible</span>
                    </div>

                    <div class="dht-category-hero-actions">
                        <a class="dht-btn dht-btn-primary" href="#dht-category-products">Ver productos</a>
                        <a class="dht-btn dht-btn-secondary" href="<?php echo esc_url(add_query_arg('dep_q', $term->name, dht_template_dependiente_page_url())); ?>">Ayúdame a elegir</a>
                    </div>

                    <?php if(!empty($category_tags)): ?>

                        <div class="dht-category-features">

                            <h2>
                                Características principales
                            </h2>

                            <div class="dht-category-tags">

                                <?php foreach(array_slice($category_tags, 0, 8) as $category_tag): ?>

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
         CATÁLOGO FACETADO · ESCRITORIO
    ====================================================== -->

    <?php
    $category_catalog = function_exists('dht_template_category_catalog_state')
        ? dht_template_category_catalog_state($term->term_id, 24)
        : array(
            'products' => array(),
            'total' => 0,
            'all_count' => 0,
            'active_count' => 0,
            'facets' => array(),
        );

    $grid_products = (array) ($category_catalog['products'] ?? array());
    $category_choice_criteria = array();

    if ($grid_products && function_exists('dht_template_category_choice_criteria')) {
        try {
            $category_choice_criteria = (array) dht_template_category_choice_criteria($grid_products, 6);
        } catch (Throwable $e) {
            error_log('[DHT category] choice_criteria: ' . $e->getMessage());
        }
    }

    if (function_exists('wc_set_loop_prop')) {
        wc_set_loop_prop('columns', 3);
        wc_set_loop_prop('total', count($grid_products));
    }
    ?>

    <section id="dht-category-products" class="dht-section dht-category-products dht-category-products--faceted">
        <div class="dht-container">

            <?php if (function_exists('dht_template_render_category_toolbar')) : ?>
                <?php dht_template_render_category_toolbar($category_catalog, $term->name); ?>
            <?php endif; ?>

            <?php
            if (function_exists('dht_template_render_category_active_filters')) {
                dht_template_render_category_active_filters($category_catalog);
            }
            ?>

            <div class="dht-category-catalog-layout">

                <aside class="dht-category-filter-sidebar" aria-label="Filtros de productos">
                    <?php
                    if (function_exists('dht_template_render_category_filter_form')) {
                        dht_template_render_category_filter_form($category_catalog, 'desktop');
                    }
                    ?>
                </aside>

                <div class="dht-category-products-panel dht-desktop-products-panel dht-category-products-panel--faceted">

                    <?php if (!empty($category_choice_criteria)) : ?>
                        <div class="dht-category-choice-criteria" aria-label="Criterios de comparación presentes en los productos">
                            <strong>Compara especialmente</strong>
                            <div class="dht-category-choice-criteria__items">
                                <?php foreach ($category_choice_criteria as $criterion) : ?>
                                    <span><?php echo esc_html($criterion); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($grid_products) : ?>
                        <?php
                        if (function_exists('dht_shared_render_product_grid')) {
                            try {
                                dht_shared_render_product_grid($grid_products, 'dht-category-product-grid', 3, true);
                            } catch (Throwable $e) {
                                error_log('[DHT category] render_product_grid: ' . $e->getMessage());
                            }
                        }
                        ?>

                        <?php
                        if (function_exists('dht_template_render_category_pagination')) {
                            dht_template_render_category_pagination($category_catalog);
                        }
                        ?>
                    <?php else : ?>
                        <div class="dht-category-no-results">
                            <strong>No hay productos con estos filtros.</strong>
                            <p>Quita algún filtro para ampliar los resultados.</p>
                            <a class="dht-btn dht-btn-primary" href="<?php echo esc_url($category_catalog['base_url'] ?? get_term_link($term)); ?>">Ver todos los productos</a>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

        </div>
    </section>

    <?php wp_reset_postdata(); ?>

    <section class="dht-section dht-category-assistant-section" aria-label="Ayuda para elegir">
        <div class="dht-container">
            <?php dht_template_render_dependiente_cta($term->name, 'compact'); ?>
        </div>
    </section>



    <!-- =====================================================
         CONTENIDO CONTEXTUAL PERSISTIDO
         Seleccion y render centralizados en template-helpers.php.
         No usa categorias editoriales del blog ni etiquetas.
    ====================================================== -->

    <?php
    if (function_exists('dht_template_render_category_context_blocks')) {
        dht_template_render_category_context_blocks($term->term_id);
    }
    ?>


    <!-- =====================================================
         CATEGORÍAS RELACIONADAS
    ====================================================== -->

    <?php
    $related_categories = array();
    if (function_exists('dht_template_related_product_categories')) {
        try {
            $related_categories = (array) dht_template_related_product_categories($term, 6);
        } catch (Throwable $e) {
            error_log('[DHT category] related_product_categories: ' . $e->getMessage());
            $related_categories = array();
        }
    }

    /* Fallback: si el helper no esta disponible o falla, conserva la regla
     * historica de hijas -> hermanas sin tumbar la categoria. */
    if (empty($related_categories)) {
        $related_categories = get_terms([
            'taxonomy'   => 'product_cat',
            'parent'     => (int) $term->term_id,
            'hide_empty' => true,
            'number'     => 6,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);
        if (is_wp_error($related_categories) || empty($related_categories)) {
            $related_categories = get_terms([
                'taxonomy'   => 'product_cat',
                'parent'     => (int) $term->parent,
                'exclude'    => [(int) $term->term_id],
                'hide_empty' => true,
                'number'     => 6,
                'orderby'    => 'count',
                'order'      => 'DESC',
            ]);
        }
        if (is_wp_error($related_categories)) {
            $related_categories = array();
        }
    }
    ?>

    <?php if(!empty($related_categories) && !is_wp_error($related_categories)): ?>

        <section class="dht-section dht-category-related">

            <div class="dht-container">

                <header class="dht-section-header">

                    <h2 class="dht-section-title">
                        Familias relacionadas
                    </h2>

                    <p class="dht-section-subtitle">
                        Amplía la búsqueda con familias conectadas directamente con <?php echo esc_html($term->name); ?>.
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
                                16
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
         CONSULTA HUMANA: FORMULARIO CONSERVADO, COMPACTO
    ====================================================== -->

    <section class="dht-category-question-section">
        <div class="dht-container">
            <details class="dht-category-question-details">
                <summary>
                    <span>
                        <strong>¿Prefieres escribirnos tu duda?</strong>
                        <small>Abre el formulario y envíanos una pregunta sobre esta categoría.</small>
                    </span>
                    <span class="dht-category-question-details__icon" aria-hidden="true">+</span>
                </summary>
                <div class="dht-category-question-details__content">
                    <?php
                    $faq_form_object_type = 2;
                    $faq_form_object_id   = $term->term_id;
                    $faq_form_ambito      = '';
                    $faq_form_template = __DIR__ . '/faq-form.php';
                    if(file_exists($faq_form_template)){
                        include $faq_form_template;
                    }
                    ?>
                </div>
            </details>
        </div>
    </section>

    <!-- =====================================================
         PRODUCTOS EXTERNOS / AFILIADOS
         Siempre después de todo el contenido propio de la categoría.
         Orden obligatorio: Amazon -> VEVOR -> footer.
    ====================================================== -->

    <!-- DHT EXTERNAL ORDER: AMAZON -> VEVOR -> FOOTER -->

    <?php
    if (function_exists('dht_render_amazon_category_block')) {
        dht_render_amazon_category_block($term, array(
            'limit' => 8,
            'title' => 'Más opciones relacionadas en Amazon',
            'mode'  => 'dynamic',
        ));
    }
    ?>

    <?php
    $dht_vevor_category_term = $term;
    $dht_vevor_category_keywords = $keywords;
    include __DIR__ . '/template-vevor-affiliate.php';
    unset($dht_vevor_category_term, $dht_vevor_category_keywords);
    ?>


</main>

<?php dht_template_render_footer(); ?>