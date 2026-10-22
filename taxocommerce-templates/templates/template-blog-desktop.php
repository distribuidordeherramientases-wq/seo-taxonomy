<?php
/**
 * Índice editorial de entradas - portada visual tipo noticias.
 * V2: jerarquía editorial, portada destacada, rail secundario y parrilla.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

global $wpdb;

/* =========================================================
 * IMÁGENES DEL ÍNDICE
 * Media -> relación comercial/product_cat -> proveedor -> logo.
 * Todo es fallback visual: no se escribe ningún _thumbnail_id.
 * ======================================================= */

if (!function_exists('dht_blog_v2_logo_url')) {
    function dht_blog_v2_logo_url($size = 'medium_large') {
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

        if (wp_attachment_is_image(36707)) {
            $url = wp_get_attachment_image_url(36707, $size);
            if ($url) {
                return $cache[$key] = (string) $url;
            }
        }

        return $cache[$key] = (string) content_url('/uploads/2026/01/Logo2.webp');
    }
}

if (!function_exists('dht_blog_v2_local_image')) {
    function dht_blog_v2_local_image($attachment_id, $size = 'medium_large', $reject_logo = true) {
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

if (!function_exists('dht_blog_v2_product_image')) {
    function dht_blog_v2_product_image($product_id, $size = 'medium_large', $allow_logo = false) {
        global $wpdb;

        static $cache = array();
        static $usage_table_available = null;
        static $supplier_table_available = null;

        $product_id = absint($product_id);
        $cache_key  = $product_id . '|' . $size . '|' . ($allow_logo ? '1' : '0');

        if (array_key_exists($cache_key, $cache)) {
            return $cache[$cache_key];
        }

        if ($product_id < 1) {
            return $allow_logo
                ? array('url' => dht_blog_v2_logo_url($size), 'attachment_id' => 0, 'source' => 'logo')
                : null;
        }

        $source = dht_blog_v2_local_image(get_post_thumbnail_id($product_id), $size, true);
        if ($source) {
            return $cache[$cache_key] = $source;
        }

        $usage_table = function_exists('seo_images_table_usages')
            ? seo_images_table_usages()
            : $wpdb->prefix . 'seo_media_usos';

        if (null === $usage_table_available) {
            $usage_table_available = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($usage_table))
            ) === $usage_table;
        }

        if ($usage_table_available) {
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $usage_table comes from the internal Media table helper and is existence-checked immediately above.
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
                     LIMIT 10",
                    $product_id
                )
            );

            foreach ((array) $attachment_ids as $attachment_id) {
                $source = dht_blog_v2_local_image($attachment_id, $size, true);
                if ($source) {
                    return $cache[$cache_key] = $source;
                }
            }
        }

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

        return $cache[$cache_key] = $allow_logo
            ? array('url' => dht_blog_v2_logo_url($size), 'attachment_id' => 0, 'source' => 'logo')
            : null;
    }
}

if (!function_exists('dht_blog_v2_term_image')) {
    function dht_blog_v2_term_image($term_id, $size = 'medium_large', $allow_logo = false) {
        static $cache = array();

        $term_id   = absint($term_id);
        $cache_key = $term_id . '|' . $size . '|' . ($allow_logo ? '1' : '0');
        if (array_key_exists($cache_key, $cache)) {
            return $cache[$cache_key];
        }

        $source = dht_blog_v2_local_image(get_term_meta($term_id, 'thumbnail_id', true), $size, true);
        if ($source) {
            return $cache[$cache_key] = $source;
        }

        $product_ids = get_posts(array(
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => 8,
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
            $source = dht_blog_v2_product_image($product_id, $size, false);
            if (!empty($source['url'])) {
                return $cache[$cache_key] = $source;
            }
        }

        return $cache[$cache_key] = $allow_logo
            ? array('url' => dht_blog_v2_logo_url($size), 'attachment_id' => 0, 'source' => 'logo')
            : null;
    }
}

if (!function_exists('dht_blog_v2_post_image')) {
    function dht_blog_v2_post_image($post_id, $size = 'medium_large') {
        global $wpdb;

        static $cache = array();
        $post_id   = absint($post_id);
        $cache_key = $post_id . '|' . $size;
        if (isset($cache[$cache_key])) {
            return $cache[$cache_key];
        }

        $source = dht_blog_v2_local_image(get_post_thumbnail_id($post_id), $size, true);
        if ($source) {
            return $cache[$cache_key] = $source;
        }

        $content = (string) get_post_field('post_content', $post_id);
        if ($content !== '' && preg_match('/wp-image-([0-9]+)/i', $content, $match)) {
            $source = dht_blog_v2_local_image(absint($match[1]), $size, true);
            if ($source) {
                return $cache[$cache_key] = $source;
            }
        }

        $related_term_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT target_id
                 FROM {$wpdb->prefix}seo_relations
                 WHERE source_type = 'post'
                   AND source_id = %d
                   AND target_type = 'product_cat'
                   AND relation_type = 'post_to_category'
                 ORDER BY id ASC",
                $post_id
            )
        );

        foreach ((array) $related_term_ids as $term_id) {
            $source = dht_blog_v2_term_image($term_id, $size, false);
            if (!empty($source['url'])) {
                return $cache[$cache_key] = $source;
            }
        }

        return $cache[$cache_key] = array(
            'url'           => dht_blog_v2_logo_url($size),
            'attachment_id' => 0,
            'source'        => 'logo',
        );
    }
}

if (!function_exists('dht_blog_v2_img')) {
    function dht_blog_v2_img($source, $alt, $class = '', $loading = 'lazy', $fetchpriority = '') {
        if (empty($source['url'])) {
            return '';
        }

        $class_attr = trim($class . ' dht-image-source-' . sanitize_html_class((string) $source['source']));
        $html  = '<img src="' . esc_url((string) $source['url']) . '" alt="' . esc_attr($alt) . '"';
        $html .= $class_attr !== '' ? ' class="' . esc_attr($class_attr) . '"' : '';
        $html .= ' loading="' . esc_attr($loading) . '" decoding="async"';
        if ($fetchpriority !== '') {
            $html .= ' fetchpriority="' . esc_attr($fetchpriority) . '"';
        }
        $html .= '>';
        return $html;
    }
}

dht_template_render_header();

$blog_page_id = (int) get_option('page_for_posts');
$paged        = max(1, (int) get_query_var('paged'), (int) get_query_var('page'));
$per_page     = max(1, (int) get_option('posts_per_page', 10));

$current_category = is_category() ? get_queried_object() : null;
$is_blog_category = $current_category instanceof WP_Term && 'category' === $current_category->taxonomy;

$editorial_category_ids = array();
foreach (array('guias-y-comparativas', 'noticias') as $editorial_slug) {
    $editorial_category = get_category_by_slug($editorial_slug);
    if ($editorial_category instanceof WP_Term) {
        $editorial_category_ids[] = (int) $editorial_category->term_id;
    }
}
$editorial_category_ids = array_values(array_unique(array_filter($editorial_category_ids)));

if ($is_blog_category) {
    $blog_title = trim((string) $current_category->name);
    $blog_intro = trim(wp_strip_all_tags((string) term_description($current_category->term_id)));
} else {
    $blog_title = $blog_page_id ? trim((string) get_the_title($blog_page_id)) : '';
    $blog_intro = '';

    if ($blog_page_id) {
        $blog_intro = trim((string) get_post_field('post_excerpt', $blog_page_id));
        if ($blog_intro === '') {
            $blog_intro = wp_trim_words(
                wp_strip_all_tags(strip_shortcodes((string) get_post_field('post_content', $blog_page_id))),
                34
            );
        }
    }
}

if ($blog_title === '') {
    $blog_title = $is_blog_category ? 'Artículos' : 'Blog';
}

if ($blog_intro === '') {
    $blog_intro = $is_blog_category
        ? 'Artículos, guías y novedades de ' . $blog_title . ' seleccionados para ayudarte a encontrar información útil y relacionada.'
        : 'Guías, análisis y criterios técnicos para elegir, utilizar y mantener herramientas y equipamiento profesional con más contexto.';
}

$blog_query_args = array(
    'post_type'           => 'post',
    'post_status'         => 'publish',
    'posts_per_page'      => $per_page,
    'paged'               => $paged,
    'ignore_sticky_posts' => false,
    'no_found_rows'       => false,
);

if ($is_blog_category) {
    $blog_query_args['category__in'] = array((int) $current_category->term_id);
} elseif (!empty($editorial_category_ids)) {
    $blog_query_args['category__in'] = $editorial_category_ids;
}

$blog_query = new WP_Query($blog_query_args);

$dht_blog_read_time = static function ($post_id) {
    $content = (string) get_post_field('post_content', $post_id);
    $words   = str_word_count(wp_strip_all_tags(strip_shortcodes($content)));
    return max(1, (int) ceil($words / 220));
};

$dht_blog_excerpt = static function ($post_id, $words = 30) {
    $excerpt = trim((string) get_the_excerpt($post_id));
    if ($excerpt === '') {
        $excerpt = wp_trim_words(
            wp_strip_all_tags(strip_shortcodes((string) get_post_field('post_content', $post_id))),
            $words
        );
    }
    return $excerpt;
};

$blog_category_args = array(
    'taxonomy'   => 'category',
    'hide_empty' => true,
    'orderby'    => 'count',
    'order'      => 'DESC',
);

if (!empty($editorial_category_ids)) {
    $blog_category_args['include'] = $editorial_category_ids;
    $blog_category_args['orderby'] = 'include';
}

$blog_categories = get_categories($blog_category_args);
$blog_categories = is_array($blog_categories) ? $blog_categories : array();

$item_list = array();
if ($blog_query->have_posts()) {
    $position = (($paged - 1) * $per_page) + 1;
    foreach ($blog_query->posts as $blog_post) {
        $item_list[] = array(
            '@type'    => 'ListItem',
            'position' => $position++,
            'name'     => get_the_title($blog_post->ID),
            'url'      => get_permalink($blog_post->ID),
        );
    }
}

$blog_url = $blog_page_id ? get_permalink($blog_page_id) : dht_template_blog_url();

if ($is_blog_category) {
    $category_url = get_category_link($current_category);
    if (!is_wp_error($category_url) && $category_url) {
        $blog_url = (string) $category_url;
    }
}
$json = array(
    '@context' => 'https://schema.org',
    '@graph'   => array(
        array(
            '@type'       => 'CollectionPage',
            '@id'         => $blog_url . '#collection',
            'url'         => $blog_url,
            'name'        => $blog_title,
            'description' => $blog_intro,
            'inLanguage'  => get_bloginfo('language'),
        ),
        array(
            '@type'           => 'ItemList',
            '@id'             => $blog_url . '#articulos',
            'name'            => 'Artículos y actualidad profesional',
            'numberOfItems'   => count($item_list),
            'itemListElement' => $item_list,
        ),
    ),
);

$posts      = (array) $blog_query->posts;
$featured   = ($paged === 1 && !empty($posts)) ? $posts[0] : null;
$rail_posts = ($paged === 1) ? array_slice($posts, 1, 4) : array();
$grid_posts = ($paged === 1) ? array_slice($posts, 5) : $posts;
?>

<script type="application/ld+json">
<?php echo wp_json_encode($json, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRETTY_PRINT); ?>
</script>

<?php
wp_enqueue_style(
    'dht-blog-template',
    TCT_URL . 'templates/assets/blog.css',
    array(),
    TCT_VERSION
);
if (did_action('wp_head')) {
    wp_print_styles('dht-blog-template');
}
?>

<!-- DHT BLOG INDEX VIEWPORT-FIRST V3 - 2026-08-26 -->
<main class="dht-blog-index dht-blog-news-v3 dht-desktop-template">
    <header class="dht-blog-hero">
        <div class="hub-container">
            <span class="dht-kicker">Actualidad · Guías · Análisis</span>
            <h1 class="hub-title"><?php echo esc_html($blog_title); ?></h1>
            <p class="hub-excerpt"><?php echo esc_html($blog_intro); ?></p>
            <div class="dht-blog-hero-meta" aria-label="Tipos de contenido">
                <span>Guías prácticas</span><span>Criterios técnicos</span><span>Noticias y contexto</span>
            </div>
        </div>
    </header>

    <?php if ($blog_categories) : ?>
        <nav class="dht-blog-topics" aria-label="Temas del blog">
            <div class="hub-container">
                <div class="dht-blog-topic-list">
                    <?php foreach ($blog_categories as $category) : ?>
                        <a class="dht-blog-topic" href="<?php echo esc_url(get_category_link($category)); ?>">
                            <span><?php echo esc_html($category->name); ?></span>
                            <small><?php echo esc_html((int) $category->count); ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </nav>
    <?php endif; ?>

    <section class="dht-assistant-section dht-assistant-section--index">
        <div class="hub-container">
            <?php dht_template_render_dependiente_cta('', 'compact'); ?>
        </div>
    </section>

    <?php if ($blog_query->have_posts()) : ?>
        <?php if ($featured) :
            $featured_id         = (int) $featured->ID;
            $featured_categories = get_the_category($featured_id);
            $featured_category   = !empty($featured_categories) ? $featured_categories[0] : null;
            $featured_image      = dht_blog_v2_post_image($featured_id, 'large');
        ?>
            <section class="dht-news-section" aria-labelledby="dht-blog-portada-title">
                <div class="hub-container">
                    <div class="dht-section-head">
                        <div><span class="dht-eyebrow">En portada</span><h2 id="dht-blog-portada-title">Últimos artículos</h2></div>
                        <p>Cinco lecturas visibles de inmediato; el resto del archivo queda debajo.</p>
                    </div>
                    <div class="dht-front-grid">
                        <article class="dht-lead-card">
                            <a class="dht-lead-media" href="<?php echo esc_url(get_permalink($featured_id)); ?>" aria-label="<?php echo esc_attr(get_the_title($featured_id)); ?>">
                                <?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Image helper builds HTML with context-specific escaping.
                        echo dht_blog_v2_img($featured_image, get_the_title($featured_id), '', 'eager', 'high'); ?>
                            </a>
                            <div class="dht-lead-body">
                                <div class="dht-card-meta">
                                    <?php if ($featured_category) : ?><a href="<?php echo esc_url(get_category_link($featured_category)); ?>"><?php echo esc_html($featured_category->name); ?></a><span>•</span><?php endif; ?>
                                    <span><?php echo esc_html(get_the_date('', $featured_id)); ?></span><span>•</span><span><?php echo esc_html($dht_blog_read_time($featured_id) . ' min'); ?></span>
                                </div>
                                <h2><a href="<?php echo esc_url(get_permalink($featured_id)); ?>"><?php echo esc_html(get_the_title($featured_id)); ?></a></h2>
                                <p><?php echo esc_html($dht_blog_excerpt($featured_id, 42)); ?></p>
                                <a class="dht-read-link" href="<?php echo esc_url(get_permalink($featured_id)); ?>">Leer artículo →</a>
                            </div>
                        </article>

                        <?php if ($rail_posts) : ?>
                            <div class="dht-front-rail">
                                <?php foreach ($rail_posts as $rail_post) :
                                    $rail_id         = (int) $rail_post->ID;
                                    $rail_categories = get_the_category($rail_id);
                                    $rail_category   = !empty($rail_categories) ? $rail_categories[0] : null;
                                    $rail_image      = dht_blog_v2_post_image($rail_id, 'medium_large');
                                ?>
                                    <article class="dht-rail-card">
                                        <a class="dht-rail-media" href="<?php echo esc_url(get_permalink($rail_id)); ?>" tabindex="-1" aria-hidden="true"><?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Image helper builds HTML with context-specific escaping.
                        echo dht_blog_v2_img($rail_image, get_the_title($rail_id)); ?></a>
                                        <div class="dht-rail-body">
                                            <div class="dht-card-meta"><?php if ($rail_category) : ?><a href="<?php echo esc_url(get_category_link($rail_category)); ?>"><?php echo esc_html($rail_category->name); ?></a><span>•</span><?php endif; ?><span><?php echo esc_html($dht_blog_read_time($rail_id) . ' min'); ?></span></div>
                                            <h3><a href="<?php echo esc_url(get_permalink($rail_id)); ?>"><?php echo esc_html(get_the_title($rail_id)); ?></a></h3>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($grid_posts) : ?>
            <section class="dht-news-section" aria-labelledby="dht-blog-latest-title">
                <div class="hub-container">
                    <div class="dht-section-head">
                        <div><span class="dht-eyebrow"><?php echo $paged > 1 ? 'Archivo' : 'Más lecturas'; ?></span><h2 id="dht-blog-latest-title"><?php echo $paged > 1 ? 'Artículos' : 'Últimos artículos'; ?></h2></div>
                        <p>Noticias, guías y análisis organizados para encontrar rápidamente el contenido que necesitas.</p>
                    </div>
                    <div class="dht-news-grid">
                        <?php foreach ($grid_posts as $blog_post) :
                            $post_id         = (int) $blog_post->ID;
                            $post_categories = get_the_category($post_id);
                            $post_category   = !empty($post_categories) ? $post_categories[0] : null;
                            $post_image      = dht_blog_v2_post_image($post_id, 'medium_large');
                        ?>
                            <article class="dht-news-card">
                                <a class="dht-news-card-media" href="<?php echo esc_url(get_permalink($post_id)); ?>" tabindex="-1" aria-hidden="true"><?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Image helper builds HTML with context-specific escaping.
                        echo dht_blog_v2_img($post_image, get_the_title($post_id)); ?></a>
                                <div class="dht-news-card-body">
                                    <div class="dht-card-meta"><?php if ($post_category) : ?><a href="<?php echo esc_url(get_category_link($post_category)); ?>"><?php echo esc_html($post_category->name); ?></a><span>•</span><?php endif; ?><span><?php echo esc_html(get_the_date('', $post_id)); ?></span><span>•</span><span><?php echo esc_html($dht_blog_read_time($post_id) . ' min'); ?></span></div>
                                    <h3><a href="<?php echo esc_url(get_permalink($post_id)); ?>"><?php echo esc_html(get_the_title($post_id)); ?></a></h3>
                                    <p><?php echo esc_html($dht_blog_excerpt($post_id, 22)); ?></p>
                                    <a class="dht-read-link" href="<?php echo esc_url(get_permalink($post_id)); ?>">Leer →</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <?php
        $pagination = paginate_links(array(
            'total'     => (int) $blog_query->max_num_pages,
            'current'   => $paged,
            'type'      => 'list',
            'prev_text' => '← Anterior',
            'next_text' => 'Siguiente →',
        ));
        if ($pagination) : ?>
            <div class="hub-container"><nav class="dht-blog-pagination" aria-label="Paginación del blog"><?php echo wp_kses_post($pagination); ?></nav></div>
        <?php endif; ?>
    <?php else : ?>
        <section class="dht-news-section"><div class="hub-container"><div class="dht-empty-state"><h2>Todavía no hay artículos publicados</h2><p>Cuando publiquemos nuevas guías y noticias profesionales aparecerán aquí.</p></div></div></section>
    <?php endif; ?>

    <?php wp_reset_postdata(); ?>
</main>

<?php dht_template_render_footer(); ?>
