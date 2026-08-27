<?php
/**
 *
 * Plantilla de post individual orientada a autoridad + venta.
 * La relación comercial NO se infiere por texto, tags o slugs:
 * se resuelve exclusivamente desde wp_seo_relations mediante
 * relation_type = post_to_category.
 *
 * Flujo principal:
 * contenido -> productos de la categoría relacionada -> categoría -> otros posts -> CTA.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

/*
 * DHT POST V1.3: helpers embebidos para que esta variante funcione
 * aunque WordPress la cargue directamente sin pasar por template-post.php.
 */
if (!function_exists('dht_post_v12_logo_url')) {
    function dht_post_v12_logo_url($size = 'medium_large') {
        static $cache = array();
        $key = (string) $size;

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $custom_logo_id = absint(get_theme_mod('custom_logo'));
        if ($custom_logo_id > 0 && wp_attachment_is_image($custom_logo_id)) {
            $url = wp_get_attachment_image_url($custom_logo_id, $size);
            if ($url) {
                return $cache[$key] = (string) $url;
            }
        }

        $known_logo_id = 36707;
        if (wp_attachment_is_image($known_logo_id)) {
            $url = wp_get_attachment_image_url($known_logo_id, $size);
            if ($url) {
                return $cache[$key] = (string) $url;
            }
        }

        return $cache[$key] = (string) content_url('/uploads/2026/01/Logo2.webp');
    }
}

if (!function_exists('dht_post_v12_local_image')) {
    function dht_post_v12_local_image($attachment_id, $size = 'medium_large', $reject_logo = true) {
        $attachment_id = absint($attachment_id);

        if ($attachment_id < 1 || 'attachment' !== get_post_type($attachment_id)) {
            return null;
        }

        if ($reject_logo && 36707 === $attachment_id) {
            return null;
        }

        if (!wp_attachment_is_image($attachment_id)) {
            return null;
        }

        $url = wp_get_attachment_image_url($attachment_id, $size);
        if (!$url) {
            return null;
        }

        return array(
            'url'           => (string) $url,
            'attachment_id' => $attachment_id,
            'source'        => 'media',
        );
    }
}

if (!function_exists('dht_post_v12_product_image')) {
    function dht_post_v12_product_image($product_id, $size = 'woocommerce_thumbnail', $allow_logo = true) {
        global $wpdb;

        static $cache = array();
        static $usage_table_available = null;
        static $supplier_table_available = null;

        $product_id = absint($product_id);
        $cache_key = $product_id . '|' . $size . '|' . ($allow_logo ? '1' : '0');

        if (array_key_exists($cache_key, $cache)) {
            return $cache[$cache_key];
        }

        if ($product_id < 1) {
            return $allow_logo
                ? array('url' => dht_post_v12_logo_url($size), 'attachment_id' => 0, 'source' => 'logo')
                : null;
        }

        // 1) Featured image real en Media.
        $source = dht_post_v12_local_image(get_post_thumbnail_id($product_id), $size, true);
        if ($source) {
            return $cache[$cache_key] = $source;
        }

        // 2) Cualquier attachment local indexado para el producto.
        $usage_table = function_exists('seo_images_table_usages')
            ? seo_images_table_usages()
            : $wpdb->prefix . 'seo_media_usos';

        if (null === $usage_table_available) {
            $usage_table_available = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($usage_table))
            ) === $usage_table;
        }

        if ($usage_table_available) {
            $attachment_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT attachment_id
                     FROM {$usage_table}
                     WHERE object_type = 'product'
                       AND object_id = %d
                       AND attachment_id IS NOT NULL
                       AND attachment_id > 0
                     ORDER BY
                       CASE tipo_uso
                         WHEN 'featured' THEN 1
                         WHEN 'gallery' THEN 2
                         WHEN 'content' THEN 3
                         ELSE 9
                       END ASC,
                       fecha DESC
                     LIMIT 12",
                    $product_id
                )
            );

            foreach ((array) $attachment_ids as $attachment_id) {
                $source = dht_post_v12_local_image($attachment_id, $size, true);
                if ($source) {
                    return $cache[$cache_key] = $source;
                }
            }
        }

        // 3) Imagen externa del proveedor.
        $supplier_table = $wpdb->prefix . 'seo_supplier_images';

        if (null === $supplier_table_available) {
            $supplier_table_available = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($supplier_table))
            ) === $supplier_table;
        }

        if ($supplier_table_available) {
            $supplier_url = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT image_url
                     FROM {$supplier_table}
                     WHERE product_id = %d
                       AND status = 'active'
                       AND image_url IS NOT NULL
                       AND TRIM(image_url) <> ''
                     ORDER BY is_primary DESC, position ASC, id ASC
                     LIMIT 1",
                    $product_id
                )
            );

            $supplier_url = $supplier_url ? esc_url_raw((string) $supplier_url) : '';
            if ($supplier_url && wp_http_validate_url($supplier_url)) {
                return $cache[$cache_key] = array(
                    'url'           => $supplier_url,
                    'attachment_id' => 0,
                    'source'        => 'supplier',
                );
            }
        }

        if (function_exists('seo_supplier_v2_external_primary_url')) {
            $supplier_url = esc_url_raw((string) seo_supplier_v2_external_primary_url($product_id));
            if ($supplier_url && wp_http_validate_url($supplier_url)) {
                return $cache[$cache_key] = array(
                    'url'           => $supplier_url,
                    'attachment_id' => 0,
                    'source'        => 'supplier',
                );
            }
        }

        if ($allow_logo) {
            return $cache[$cache_key] = array(
                'url'           => dht_post_v12_logo_url($size),
                'attachment_id' => 0,
                'source'        => 'logo',
            );
        }

        return $cache[$cache_key] = null;
    }
}

if (!function_exists('dht_post_v12_term_image')) {
    function dht_post_v12_term_image($term_id, $size = 'woocommerce_thumbnail', $allow_logo = true) {
        $term_id = absint($term_id);

        if ($term_id < 1) {
            return $allow_logo
                ? array('url' => dht_post_v12_logo_url($size), 'attachment_id' => 0, 'source' => 'logo')
                : null;
        }

        // 1) Imagen propia de product_cat en Media.
        $source = dht_post_v12_local_image(get_term_meta($term_id, 'thumbnail_id', true), $size, true);
        if ($source) {
            return $source;
        }

        // 2) Imagen de un producto representativo de la categoría: Media -> proveedor.
        $product_ids = get_posts(array(
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => 12,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'orderby'                => 'date',
            'order'                  => 'DESC',
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'tax_query'              => array(
                array(
                    'taxonomy'         => 'product_cat',
                    'field'            => 'term_id',
                    'terms'            => array($term_id),
                    'include_children' => false,
                ),
            ),
        ));

        foreach ((array) $product_ids as $product_id) {
            $source = dht_post_v12_product_image($product_id, $size, false);
            if (!empty($source['url'])) {
                return $source;
            }
        }

        return $allow_logo
            ? array('url' => dht_post_v12_logo_url($size), 'attachment_id' => 0, 'source' => 'logo')
            : null;
    }
}

if (!function_exists('dht_post_v12_main_image')) {
    function dht_post_v12_main_image($post_id, $term_ids, $size = 'large') {
        // Featured image del propio post, siempre que no sea el logo.
        $source = dht_post_v12_local_image(get_post_thumbnail_id($post_id), $size, true);
        if ($source) {
            return $source;
        }

        // Después usa la relación comercial del post para encontrar una imagen real.
        foreach ((array) $term_ids as $term_id) {
            $source = dht_post_v12_term_image($term_id, $size, false);
            if (!empty($source['url'])) {
                return $source;
            }
        }

        return array('url' => dht_post_v12_logo_url($size), 'attachment_id' => 0, 'source' => 'logo');
    }
}

if (!function_exists('dht_post_v12_img')) {
    function dht_post_v12_img($source, $alt, $class = '', $loading = 'lazy', $fetchpriority = '') {
        if (empty($source['url'])) {
            return '';
        }

        $html = '<img src="' . esc_url((string) $source['url']) . '" alt="' . esc_attr($alt) . '"';
        if ($class !== '') {
            $html .= ' class="' . esc_attr($class) . '"';
        }
        $html .= ' loading="' . esc_attr($loading) . '" decoding="async"';
        if ($fetchpriority !== '') {
            $html .= ' fetchpriority="' . esc_attr($fetchpriority) . '"';
        }
        $html .= '>';

        return $html;
    }
}

if (!function_exists('dht_post_v12_wc_product_image')) {
    function dht_post_v12_wc_product_image($html, $product, $size = 'woocommerce_thumbnail', $attr = array(), $placeholder = true, $image = '') {
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return $html;
        }

        $product_id = absint($product->get_id());
        $source = dht_post_v12_product_image($product_id, is_string($size) ? $size : 'woocommerce_thumbnail', true);

        if (empty($source['url'])) {
            return $html;
        }

        // Si WooCommerce ya tiene una imagen local válida, conservamos su srcset/sizes.
        $current_image_id = method_exists($product, 'get_image_id') ? absint($product->get_image_id()) : 0;
        if ('media' === $source['source'] && $current_image_id > 0 && $current_image_id === absint($source['attachment_id'])) {
            return $html;
        }

        $classes = 'attachment-woocommerce_thumbnail size-woocommerce_thumbnail wp-post-image dht-post-product-fallback-image';
        $classes .= ' dht-image-source-' . sanitize_html_class((string) $source['source']);

        return '<img src="' . esc_url((string) $source['url']) . '" class="' . esc_attr($classes) . '" alt="' . esc_attr(get_the_title($product_id)) . '" loading="lazy" decoding="async">';
    }
}

dht_template_render_header();

global $wpdb;

$relations_table = $wpdb->prefix . 'seo_relations';
$nodes_table     = $wpdb->prefix . 'seo_nodes';

while (have_posts()) :
    the_post();

    $post_id         = (int) get_the_ID();
    $post_categories = get_the_terms($post_id, 'category');
    $post_tags       = get_the_terms($post_id, 'post_tag');
    $post_categories = is_array($post_categories) ? $post_categories : array();
    $post_tags       = is_array($post_tags) ? $post_tags : array();

    $word_count = str_word_count(
        wp_strip_all_tags(
            strip_shortcodes((string) get_post_field('post_content', $post_id))
        )
    );
    $read_time = max(1, (int) ceil($word_count / 220));

    /* =========================================================
       CATEGORÍAS COMERCIALES EXPLÍCITAS DEL POST
       Fuente única: wp_seo_relations / post_to_category.
    ========================================================== */

    $related_category_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT r.target_id
             FROM {$relations_table} r
             INNER JOIN {$wpdb->term_taxonomy} tt
                ON tt.term_id = r.target_id
               AND tt.taxonomy = 'product_cat'
             WHERE r.source_type = 'post'
               AND r.source_id = %d
               AND r.target_type = 'product_cat'
               AND r.relation_type = 'post_to_category'
             ORDER BY r.id ASC",
            $post_id
        )
    );

    $related_category_ids = array_values(
        array_unique(
            array_filter(array_map('absint', (array) $related_category_ids))
        )
    );

    $related_product_cats = array();

    foreach ($related_category_ids as $category_id) {
        $term = get_term($category_id, 'product_cat');

        if (!$term || is_wp_error($term)) {
            continue;
        }

        $term_link = dht_template_safe_term_link($term);
        if ($term_link === '') {
            continue;
        }

        $related_product_cats[] = $term;
    }

    $primary_product_cat = !empty($related_product_cats) ? $related_product_cats[0] : null;
    $primary_cat_link    = $primary_product_cat ? dht_template_safe_term_link($primary_product_cat) : '';
    $product_cat_slugs   = wp_list_pluck($related_product_cats, 'slug');

    /* =========================================================
       DESCRIPCIÓN DE CATEGORÍA
       Reutiliza la misma descripción SEO de template-category.php.
    ========================================================== */

    $category_descriptions = array();

    foreach ($related_product_cats as $product_cat) {
        $description_html = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT keywords
                 FROM {$nodes_table}
                 WHERE object_type = 'category'
                   AND object_id = %d
                   AND seo_role = 'description'
                   AND status = 1
                 ORDER BY id DESC
                 LIMIT 1",
                (int) $product_cat->term_id
            )
        );

        if (trim($description_html) === '') {
            $description_html = (string) term_description($product_cat->term_id, 'product_cat');
        }

        $category_descriptions[(int) $product_cat->term_id] = trim($description_html);
    }

    /* =========================================================
       POSTS RELACIONADOS POR LA MISMA product_cat SEO.
       Nada de similitud textual: comparten relación explícita.
    ========================================================== */

    $related_post_ids = array();

    if (!empty($related_category_ids)) {
        $placeholders = implode(',', array_fill(0, count($related_category_ids), '%d'));
        $params       = array_merge($related_category_ids, array($post_id));

        $sql = "SELECT DISTINCT r.source_id
                FROM {$relations_table} r
                INNER JOIN {$wpdb->posts} p
                   ON p.ID = r.source_id
                  AND p.post_type = 'post'
                  AND p.post_status = 'publish'
                WHERE r.source_type = 'post'
                  AND r.target_type = 'product_cat'
                  AND r.relation_type = 'post_to_category'
                  AND r.target_id IN ({$placeholders})
                  AND r.source_id <> %d
                ORDER BY p.post_date DESC
                LIMIT 12";

        $related_post_ids = $wpdb->get_col($wpdb->prepare($sql, ...$params));
        $related_post_ids = array_values(array_unique(array_filter(array_map('absint', (array) $related_post_ids))));
    }

    $related_posts = new WP_Query(array(
        'post_type'           => 'post',
        'post_status'         => 'publish',
        'posts_per_page'      => 3,
        'post__in'            => !empty($related_post_ids) ? $related_post_ids : array(0),
        'orderby'             => 'post__in',
        'ignore_sticky_posts' => true,
        'no_found_rows'       => true,
    ));

    $shop_target_url = $primary_cat_link !== '' ? $primary_cat_link : dht_template_shop_url();
    $shop_target_cta = $primary_product_cat
        ? sprintf('Ver productos de %s', $primary_product_cat->name)
        : 'Ver herramientas';

    /* Imagen principal visual: Media -> categoría/producto -> proveedor -> logo. */
    $post_hero_image = dht_post_v12_main_image($post_id, $related_category_ids, 'large');
    ?>

    <!-- DHT POST V1.3 SELF-CONTAINED + SUPPLIER IMAGES - 2026-08-26 -->

<style id="dht-post-compact-v13-css">
.dht-post.dht-post-compact-v13 .dht-post-hero{
    padding:24px 0!important;
    min-height:0!important;
}
.dht-post.dht-post-compact-v13 .dht-post-hero-grid{
    gap:28px!important;
    align-items:center!important;
}
.dht-post.dht-post-compact-v13 .dht-post-breadcrumbs{
    margin-bottom:8px!important;
}
.dht-post.dht-post-compact-v13 .dht-post-kickers,
.dht-post.dht-post-compact-v13 .dht-kicker{
    margin-bottom:8px!important;
}
.dht-post.dht-post-compact-v13 .dht-post-title{
    margin-top:0!important;
    margin-bottom:8px!important;
}
.dht-post.dht-post-compact-v13 .dht-post-lead{
    margin-top:0!important;
    margin-bottom:10px!important;
}
.dht-post.dht-post-compact-v13 .dht-post-meta{
    margin-top:8px!important;
    margin-bottom:0!important;
}
.dht-post.dht-post-compact-v13 .dht-post-hero-actions{
    margin-top:14px!important;
    margin-bottom:0!important;
}
.dht-post.dht-post-compact-v13 .dht-post-hero-media{
    margin:0!important;
}
.dht-post.dht-post-compact-v13 .dht-post-hero-media img{
    display:block!important;
    width:100%!important;
    height:auto!important;
    max-height:340px!important;
    object-fit:contain!important;
}
.dht-post.dht-post-compact-v13 .dht-post-content-wrap{
    margin-top:0!important;
    padding-top:22px!important;
}
@media (max-width:782px){
    .dht-post.dht-post-compact-v13 .dht-post-hero{
        padding:14px 0!important;
    }
    .dht-post.dht-post-compact-v13 .dht-post-hero-grid{
        gap:14px!important;
    }
    .dht-post.dht-post-compact-v13 .dht-post-breadcrumbs{
        margin-bottom:6px!important;
    }
    .dht-post.dht-post-compact-v13 .dht-post-kickers,
    .dht-post.dht-post-compact-v13 .dht-kicker{
        margin-bottom:6px!important;
    }
    .dht-post.dht-post-compact-v13 .dht-post-title{
        margin-bottom:6px!important;
    }
    .dht-post.dht-post-compact-v13 .dht-post-lead{
        margin-bottom:8px!important;
    }
    .dht-post.dht-post-compact-v13 .dht-post-meta{
        margin-top:6px!important;
    }
    .dht-post.dht-post-compact-v13 .dht-post-hero-actions{
        margin-top:10px!important;
    }
    .dht-post.dht-post-compact-v13 .dht-post-hero-media img{
        max-height:280px!important;
    }
    .dht-post.dht-post-compact-v13 .dht-post-content-wrap{
        padding-top:12px!important;
    }
}
.dht-post.dht-post-compact-v13 .dht-post-product-fallback-image{
    width:100%!important;
    height:auto!important;
    aspect-ratio:1/1;
    object-fit:contain!important;
    background:#fff;
}
</style>

    <article <?php post_class('dht-post dht-post--conversion dht-desktop-template dht-post-compact-v13'); ?>>

        <header class="dht-post-hero<?php echo !empty($post_hero_image['url']) ? ' dht-post-hero--with-media' : ''; ?>">
            <div class="dht-container dht-post-hero-grid">
                <div class="dht-post-hero-copy">
                    <nav class="dht-post-breadcrumbs" aria-label="Migas de pan">
                        <a href="<?php echo esc_url(home_url('/')); ?>">Inicio</a>
                        <span aria-hidden="true">/</span>
                        <a href="<?php echo esc_url(dht_template_blog_url()); ?>">Blog</a>
                    </nav>

                    <?php if ($post_categories) : ?>
                        <div class="dht-post-kickers" aria-label="Categorías editoriales">
                            <?php foreach (array_slice($post_categories, 0, 2) as $category) : ?>
                                <a class="dht-kicker" href="<?php echo esc_url(get_category_link($category)); ?>">
                                    <?php echo esc_html($category->name); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($primary_product_cat) : ?>
                        <a class="dht-kicker" href="<?php echo esc_url($primary_cat_link); ?>">
                            <?php echo esc_html($primary_product_cat->name); ?>
                        </a>
                    <?php else : ?>
                        <span class="dht-kicker">Guía profesional</span>
                    <?php endif; ?>

                    <h1 class="dht-post-title"><?php the_title(); ?></h1>

                    <?php if (has_excerpt()) : ?>
                        <p class="dht-post-lead"><?php echo esc_html(get_the_excerpt()); ?></p>
                    <?php endif; ?>

                    <div class="dht-post-meta">
                        <span><?php echo esc_html(get_the_date()); ?></span>
                        <span aria-hidden="true">•</span>
                        <span><?php echo esc_html(sprintf('%d min de lectura', $read_time)); ?></span>
                        <span aria-hidden="true">•</span>
                        <span><?php echo esc_html(get_the_author()); ?></span>
                    </div>

                    <div class="dht-post-hero-actions">
                        <a class="dht-btn dht-btn-primary" href="<?php echo esc_url($shop_target_url); ?>">
                            <?php echo esc_html($shop_target_cta); ?>
                        </a>
                        <a class="dht-post-text-link" href="#dht-post-content">Ir a la guía ↓</a>
                    </div>
                </div>

                <?php if (!empty($post_hero_image['url'])) : ?>
                    <figure class="dht-post-hero-media" data-image-source="<?php echo esc_attr($post_hero_image['source']); ?>">
                        <?php echo dht_post_v12_img($post_hero_image, get_the_title(), 'dht-post-hero-image', 'eager', 'high'); ?>
                    </figure>
                <?php endif; ?>
            </div>
        </header>

        <div id="dht-post-content" class="dht-container dht-post-content-wrap">
            <div class="dht-post-layout">
                <main class="dht-post-content dht-prose">
                    <?php the_content(); ?>
                </main>

                <aside class="dht-post-aside" aria-label="Compra relacionada con el artículo">
                    <?php if ($primary_product_cat) : ?>
                        <div class="dht-post-aside-card">
                            <span class="dht-post-aside-eyebrow">Relacionado con esta guía</span>
                            <strong><?php echo esc_html($primary_product_cat->name); ?></strong>
                            <p>Consulta productos de la categoría relacionada directamente con este artículo.</p>
                            <a href="#dht-related-products">Ver productos ↓</a>
                        </div>
                    <?php endif; ?>

                    <?php if ($post_tags) : ?>
                        <div class="dht-post-tags">
                            <?php foreach (array_slice($post_tags, 0, 6) as $tag) : ?>
                                <a href="<?php echo esc_url(get_tag_link($tag)); ?>">#<?php echo esc_html($tag->name); ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </aside>
            </div>
        </div>

        <!-- =====================================================
             PRODUCTOS: PRIMER BLOQUE COMERCIAL TRAS EL CONTENIDO
        ====================================================== -->
        <?php if (!empty($product_cat_slugs) && function_exists('WC')) : ?>
            <section id="dht-related-products" class="dht-post-section dht-reco dht-category-products">
                <div class="dht-container">
                    <div class="dht-post-section-heading">
                        <div>
                            <span class="dht-post-eyebrow">Productos relacionados</span>
                            <h2 class="dht-post-section-title">
                                <?php
                                echo $primary_product_cat
                                    ? esc_html('Productos de ' . $primary_product_cat->name)
                                    : 'Productos relacionados';
                                ?>
                            </h2>
                            <p class="dht-post-section-subtitle">
                                Selección de productos de la categoría relacionada con esta guía.
                            </p>
                        </div>

                        <?php if ($primary_cat_link !== '') : ?>
                            <a class="dht-post-section-link" href="<?php echo esc_url($primary_cat_link); ?>">
                                Ver toda la categoría →
                            </a>
                        <?php endif; ?>
                    </div>

                    <?php
                    /* Durante este loop WooCommerce, sustituye placeholder/logo por Media -> proveedor -> logo. */
                    add_filter('woocommerce_product_get_image', 'dht_post_v12_wc_product_image', 20, 6);

                    echo do_shortcode(
                        '[products category="' .
                        esc_attr(implode(',', $product_cat_slugs)) .
                        '" limit="8" columns="4" orderby="popularity" order="DESC" visibility="visible"]'
                    );

                    remove_filter('woocommerce_product_get_image', 'dht_post_v12_wc_product_image', 20);
                    ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- =====================================================
             CATEGORÍA: DEBAJO DE LOS PRODUCTOS
        ====================================================== -->
        <?php if (!empty($related_product_cats)) : ?>
            <section id="dht-related-categories" class="dht-post-section dht-post-discovery">
                <div class="dht-container">
                    <div class="dht-post-section-heading">
                        <div>
                            <span class="dht-post-eyebrow">Categoría relacionada</span>
                            <h2 class="dht-post-section-title">Sigue explorando el catálogo</h2>
                            <p class="dht-post-section-subtitle">
                                Esta relación procede de SEO Relations y es la referencia comercial explícita del artículo.
                            </p>
                        </div>
                    </div>

                    <div class="dht-category-grid">
                        <?php foreach ($related_product_cats as $product_cat) : ?>
                            <?php
                            $category_link = dht_template_safe_term_link($product_cat);
                            $category_image_source = dht_post_v12_term_image(
                                (int) $product_cat->term_id,
                                'woocommerce_thumbnail',
                                true
                            );
                            $category_image = !empty($category_image_source['url'])
                                ? (string) $category_image_source['url']
                                : dht_post_v12_logo_url('woocommerce_thumbnail');

                            $category_description = isset($category_descriptions[(int) $product_cat->term_id])
                                ? $category_descriptions[(int) $product_cat->term_id]
                                : '';

                            $category_excerpt = $category_description !== ''
                                ? wp_trim_words(wp_strip_all_tags($category_description), 34)
                                : 'Consulta herramientas y productos disponibles en esta categoría.';
                            ?>

                            <a class="dht-category-card" href="<?php echo esc_url($category_link); ?>">
                                <?php if ($category_image !== '') : ?>
                                    <img
                                        src="<?php echo esc_url($category_image); ?>"
                                        alt="<?php echo esc_attr($product_cat->name); ?>"
                                        loading="lazy"
                                        width="300"
                                        height="300"
                                    >
                                <?php endif; ?>

                                <div class="dht-category-content">
                                    <span class="dht-post-card-label">Categoría de productos</span>
                                    <h3><?php echo esc_html($product_cat->name); ?></h3>
                                    <p><?php echo esc_html($category_excerpt); ?></p>

                                    <div class="dht-card-footer">
                                        <span class="dht-category-card-meta">
                                            <?php echo esc_html(number_format_i18n((int) $product_cat->count)); ?> productos
                                        </span>
                                        <span>Ver categoría →</span>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <!-- =====================================================
             OTROS POSTS DE LA MISMA CATEGORÍA COMERCIAL
        ====================================================== -->
        <?php if ($related_posts->have_posts()) : ?>
            <section class="dht-post-section dht-post-news">
                <div class="dht-container">
                    <div class="dht-post-section-heading">
                        <div>
                            <span class="dht-post-eyebrow">Más contenido relacionado</span>
                            <h2 class="dht-post-section-title">Sigue aprendiendo</h2>
                            <p class="dht-post-section-subtitle">
                                Artículos vinculados a la misma categoría comercial mediante SEO Relations.
                            </p>
                        </div>
                    </div>

                    <div class="dht-post-news-grid">
                        <?php while ($related_posts->have_posts()) : $related_posts->the_post(); ?>
                            <article class="dht-post-news-card">
                                <a class="dht-post-news-media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
                                    <?php
                                    $related_post_image = dht_post_v12_local_image(get_post_thumbnail_id(get_the_ID()), 'medium_large', true);
                                    if (!$related_post_image) {
                                        foreach ((array) $related_category_ids as $related_term_id) {
                                            $related_post_image = dht_post_v12_term_image($related_term_id, 'medium_large', false);
                                            if (!empty($related_post_image['url'])) {
                                                break;
                                            }
                                        }
                                    }
                                    if (!$related_post_image) {
                                        $related_post_image = array(
                                            'url' => dht_post_v12_logo_url('medium_large'),
                                            'attachment_id' => 0,
                                            'source' => 'logo',
                                        );
                                    }
                                    echo dht_post_v12_img($related_post_image, get_the_title(), '', 'lazy');
                                    ?>
                                </a>

                                <div class="dht-post-news-body">
                                    <span class="dht-post-card-label"><?php echo esc_html(get_the_date()); ?></span>
                                    <h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
                                    <p><?php echo esc_html(wp_trim_words(get_the_excerpt(), 20)); ?></p>
                                    <a class="dht-cat-action" href="<?php the_permalink(); ?>">Leer artículo →</a>
                                </div>
                            </article>
                        <?php endwhile; ?>
                    </div>
                </div>
            </section>
            <?php wp_reset_postdata(); ?>
        <?php endif; ?>

        <section class="dht-post-cta-bottom">
            <div class="dht-container">
                <div class="dht-cta-box dht-post-final-cta">
                    <div>
                        <span class="dht-badge">Del contenido a la compra</span>
                        <h2>
                            <?php
                            echo $primary_product_cat
                                ? esc_html('¿Buscas ' . $primary_product_cat->name . '?')
                                : '¿No tienes claro qué herramienta necesitas?';
                            ?>
                        </h2>
                        <p>
                            <?php if ($primary_product_cat) : ?>
                                Compara los productos disponibles en la categoría relacionada con esta guía.
                            <?php else : ?>
                                Explora el catálogo o cuéntanos el trabajo que necesitas realizar.
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="dht-cta-actions">
                        <a class="dht-post-cta-btn btn-primary" href="<?php echo esc_url($shop_target_url); ?>">
                            <?php echo esc_html($shop_target_cta); ?>
                        </a>
                        <a class="dht-post-cta-secondary" href="<?php echo esc_url(dht_template_contact_url()); ?>">
                            Pedir asesoramiento
                        </a>
                    </div>
                </div>
            </div>
        </section>

    </article>

<?php endwhile; ?>

<?php dht_template_render_footer(); ?>
