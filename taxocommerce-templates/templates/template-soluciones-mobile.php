<?php
/**
 * Índice general de landings / soluciones - portada visual tipo magazine.
 * SOLO indexa seo_role = landing. No modifica plantillas individuales.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

global $wpdb;

/* =========================================================
 * HELPERS AUTÓNOMOS DE IMAGEN
 * landing: featured -> product_cat relacionada -> producto Media/proveedor
 *          -> imagen del contenido -> adjunto -> logo.
 * ======================================================= */

if (!function_exists('dht_solutions_v2_logo_url_mobile')) {
    function dht_solutions_v2_logo_url_mobile($size = 'medium_large') {
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

if (!function_exists('dht_solutions_v2_local_image_mobile')) {
    function dht_solutions_v2_local_image_mobile($attachment_id, $size = 'medium_large', $reject_logo = true) {
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
        return array('url' => (string) $url, 'attachment_id' => $attachment_id, 'source' => 'media');
    }
}

if (!function_exists('dht_solutions_v2_product_image_mobile')) {
    function dht_solutions_v2_product_image_mobile($product_id, $size = 'medium_large', $allow_logo = false) {
        global $wpdb;

        static $cache = array();
        static $usage_table_available = null;
        static $supplier_table_available = null;

        $product_id = absint($product_id);
        $cache_key  = $product_id . '|' . $size . '|' . ($allow_logo ? '1' : '0');
        if (array_key_exists($cache_key, $cache)) {
            return $cache[$cache_key];
        }

        $source = dht_solutions_v2_local_image_mobile(get_post_thumbnail_id($product_id), $size, true);
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
                $source = dht_solutions_v2_local_image_mobile($attachment_id, $size, true);
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
                return $cache[$cache_key] = array('url' => $supplier_url, 'attachment_id' => 0, 'source' => 'supplier');
            }
        }

        if (function_exists('seo_supplier_v2_external_primary_url')) {
            $supplier_url = esc_url_raw((string) seo_supplier_v2_external_primary_url($product_id));
            if ($supplier_url && wp_http_validate_url($supplier_url)) {
                return $cache[$cache_key] = array('url' => $supplier_url, 'attachment_id' => 0, 'source' => 'supplier');
            }
        }

        return $cache[$cache_key] = $allow_logo
            ? array('url' => dht_solutions_v2_logo_url_mobile($size), 'attachment_id' => 0, 'source' => 'logo')
            : null;
    }
}

if (!function_exists('dht_solutions_v2_related_terms_mobile')) {
    function dht_solutions_v2_related_terms_mobile($landing_id) {
        global $wpdb;
        static $cache = array();

        $landing_id = absint($landing_id);
        if (isset($cache[$landing_id])) {
            return $cache[$landing_id];
        }

        $term_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT r.target_id
                 FROM {$wpdb->prefix}seo_relations r
                 INNER JOIN {$wpdb->term_taxonomy} tt
                    ON tt.term_id = r.target_id
                   AND tt.taxonomy = 'product_cat'
                 WHERE r.source_type = 'landing'
                   AND r.source_id = %d
                   AND r.target_type = 'product_cat'
                   AND r.relation_type = 'landing_to_category'
                 ORDER BY r.id ASC",
                $landing_id
            )
        );

        $terms = array();
        foreach ((array) $term_ids as $term_id) {
            $term = get_term(absint($term_id), 'product_cat');
            if ($term && !is_wp_error($term)) {
                $terms[] = $term;
            }
        }

        return $cache[$landing_id] = $terms;
    }
}

if (!function_exists('dht_solutions_v2_term_image_mobile')) {
    function dht_solutions_v2_term_image_mobile($term_id, $size = 'medium_large', $allow_logo = false) {
        static $cache = array();

        $term_id   = absint($term_id);
        $cache_key = $term_id . '|' . $size . '|' . ($allow_logo ? '1' : '0');
        if (array_key_exists($cache_key, $cache)) {
            return $cache[$cache_key];
        }

        $source = dht_solutions_v2_local_image_mobile(get_term_meta($term_id, 'thumbnail_id', true), $size, true);
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
            $source = dht_solutions_v2_product_image_mobile($product_id, $size, false);
            if (!empty($source['url'])) {
                return $cache[$cache_key] = $source;
            }
        }

        return $cache[$cache_key] = $allow_logo
            ? array('url' => dht_solutions_v2_logo_url_mobile($size), 'attachment_id' => 0, 'source' => 'logo')
            : null;
    }
}

if (!function_exists('dht_solutions_v2_content_image_mobile')) {
    function dht_solutions_v2_content_image_mobile($post_id, $size = 'medium_large') {
        $content = (string) get_post_field('post_content', $post_id);
        if ($content !== '' && preg_match('/wp-image-([0-9]+)/i', $content, $match)) {
            $source = dht_solutions_v2_local_image_mobile(absint($match[1]), $size, true);
            if ($source) {
                return $source;
            }
        }
        if ($content !== '' && preg_match('/<img[^>]+(?:src|data-src)=["\']([^"\']+)["\']/i', $content, $match)) {
            $url = esc_url_raw(html_entity_decode($match[1], ENT_QUOTES, get_bloginfo('charset')));
            if ($url && wp_http_validate_url($url)) {
                return array('url' => $url, 'attachment_id' => 0, 'source' => 'content');
            }
        }
        return null;
    }
}

if (!function_exists('dht_solutions_v2_page_image_mobile')) {
    function dht_solutions_v2_page_image_mobile($post_id, $size = 'medium_large', $use_relations = true) {
        static $cache = array();
        $post_id   = absint($post_id);
        $cache_key = $post_id . '|' . $size . '|' . ($use_relations ? '1' : '0');
        if (isset($cache[$cache_key])) {
            return $cache[$cache_key];
        }

        $source = dht_solutions_v2_local_image_mobile(get_post_thumbnail_id($post_id), $size, true);
        if ($source) {
            return $cache[$cache_key] = $source;
        }

        if ($use_relations) {
            foreach (dht_solutions_v2_related_terms_mobile($post_id) as $term) {
                $source = dht_solutions_v2_term_image_mobile($term->term_id, $size, false);
                if (!empty($source['url'])) {
                    return $cache[$cache_key] = $source;
                }
            }
        }

        $source = dht_solutions_v2_content_image_mobile($post_id, $size);
        if ($source) {
            return $cache[$cache_key] = $source;
        }

        $attachments = get_children(array(
            'post_parent'    => $post_id,
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'numberposts'    => 3,
            'orderby'        => 'menu_order ID',
            'order'          => 'ASC',
        ));
        foreach ((array) $attachments as $attachment) {
            $source = dht_solutions_v2_local_image_mobile($attachment->ID, $size, true);
            if ($source) {
                return $cache[$cache_key] = $source;
            }
        }

        return $cache[$cache_key] = array('url' => dht_solutions_v2_logo_url_mobile($size), 'attachment_id' => 0, 'source' => 'logo');
    }
}

if (!function_exists('dht_solutions_v2_img_mobile')) {
    function dht_solutions_v2_img_mobile($source, $alt, $loading = 'lazy', $fetchpriority = '') {
        if (empty($source['url'])) {
            return '';
        }
        $class = 'dht-image-source-' . sanitize_html_class((string) $source['source']);
        $html  = '<img src="' . esc_url((string) $source['url']) . '" alt="' . esc_attr($alt) . '" class="' . esc_attr($class) . '" loading="' . esc_attr($loading) . '" decoding="async"';
        if ($fetchpriority !== '') {
            $html .= ' fetchpriority="' . esc_attr($fetchpriority) . '"';
        }
        $html .= '>';
        return $html;
    }
}

if (!function_exists('dht_solutions_v2_excerpt_mobile')) {
    function dht_solutions_v2_excerpt_mobile($post_id, $words = 28) {
        $excerpt = trim((string) get_the_excerpt($post_id));
        if ($excerpt === '') {
            $excerpt = wp_trim_words(
                wp_strip_all_tags(strip_shortcodes((string) get_post_field('post_content', $post_id))),
                $words
            );
        }
        return $excerpt;
    }
}

dht_template_render_header();

$page_id     = get_the_ID();
$paged       = max(1, (int) get_query_var('paged'), (int) get_query_var('page'));
$per_page    = 18;
$nodes_table = $wpdb->prefix . 'seo_nodes';

$landing_ids = $wpdb->get_col(
    "SELECT DISTINCT n.object_id
     FROM {$nodes_table} n
     INNER JOIN {$wpdb->posts} p ON p.ID = n.object_id
     WHERE n.object_type = 'page'
       AND n.seo_role = 'landing'
       AND n.status = 1
       AND p.post_type = 'page'
       AND p.post_status = 'publish'"
);
$landing_ids = array_values(array_unique(array_filter(array_map('intval', (array) $landing_ids))));
$landing_ids = array_values(array_diff($landing_ids, array((int) $page_id)));

$landing_query = new WP_Query(array(
    'post_type'           => 'page',
    'post_status'         => 'publish',
    'post__in'            => !empty($landing_ids) ? $landing_ids : array(0),
    'posts_per_page'      => $per_page,
    'paged'               => $paged,
    'orderby'             => 'date',
    'order'               => 'DESC',
    'ignore_sticky_posts' => true,
));

$cluster_ids = $wpdb->get_col(
    "SELECT DISTINCT n.object_id
     FROM {$nodes_table} n
     INNER JOIN {$wpdb->posts} p ON p.ID = n.object_id
     WHERE n.object_type = 'page'
       AND n.seo_role = 'cluster'
       AND n.status = 1
       AND p.post_type = 'page'
       AND p.post_status = 'publish'"
);
$cluster_ids = array_values(array_unique(array_filter(array_map('intval', (array) $cluster_ids))));
$cluster_query = new WP_Query(array(
    'post_type'           => 'page',
    'post_status'         => 'publish',
    'post__in'            => !empty($cluster_ids) ? $cluster_ids : array(0),
    'posts_per_page'      => 3,
    'orderby'             => array('menu_order' => 'ASC', 'title' => 'ASC'),
    'ignore_sticky_posts' => true,
    'no_found_rows'       => true,
));

$page_title = trim((string) get_the_title($page_id));
$page_intro = trim((string) get_the_excerpt($page_id));
if ($page_intro === '') {
    $page_intro = wp_trim_words(
        wp_strip_all_tags(strip_shortcodes((string) get_post_field('post_content', $page_id))),
        40
    );
}
if ($page_intro === '') {
    $page_intro = 'Guías de compra y soluciones prácticas organizadas por trabajo, necesidad y criterios de elección.';
}

$item_list = array();
if ($landing_query->have_posts()) {
    $position = (($paged - 1) * $per_page) + 1;
    foreach ($landing_query->posts as $landing_post) {
        $item_list[] = array('@type' => 'ListItem', 'position' => $position++, 'name' => get_the_title($landing_post->ID), 'url' => get_permalink($landing_post->ID));
    }
}

$json = array(
    '@context' => 'https://schema.org',
    '@graph'   => array(
        array('@type' => 'CollectionPage', '@id' => get_permalink($page_id) . '#collection', 'url' => get_permalink($page_id), 'name' => $page_title, 'description' => $page_intro, 'inLanguage' => get_bloginfo('language')),
        array('@type' => 'ItemList', '@id' => get_permalink($page_id) . '#soluciones', 'name' => 'Proyectos', 'numberOfItems' => count($item_list), 'itemListElement' => $item_list),
    ),
);

$landing_posts = (array) $landing_query->posts;
$featured      = ($paged === 1 && !empty($landing_posts)) ? $landing_posts[0] : null;
$rail_posts    = ($paged === 1) ? array_slice($landing_posts, 1, 4) : array();
$grid_posts    = ($paged === 1) ? array_slice($landing_posts, 5) : $landing_posts;
?>

<script type="application/ld+json"><?php echo wp_json_encode($json, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRETTY_PRINT); ?></script>

<?php
wp_enqueue_style(
    'dht-soluciones-mobile',
    TCT_URL . 'templates/assets/soluciones-mobile.css',
    array(),
    TCT_VERSION
);
if (did_action('wp_head')) {
    wp_print_styles('dht-soluciones-mobile');
}
?>

<!-- DHT SOLUCIONES INDEX VIEWPORT-FIRST V3 - 2026-08-26 -->
<main class="solutions-index-page dht-solutions-news-v3 dht-mobile-template">
    <header class="dht-solutions-hero">
        <div class="hub-container">
            <span class="dht-kicker">Guías de compra · Aplicaciones · Proyectos</span>
            <h1><?php echo esc_html($page_title); ?></h1>
            <p><?php echo esc_html($page_intro); ?></p>
            <div class="dht-solutions-hero-meta" aria-label="Resumen del índice">
                <span><?php echo esc_html(count($landing_ids)); ?> proyectos publicados</span><span>Selección por necesidad</span><span>Conexión con catálogo real</span>
            </div>
        </div>
    </header>

    <section class="dht-assistant-section dht-assistant-section--index">
        <div class="hub-container">
            <?php dht_template_render_dependiente_cta('', 'compact'); ?>
        </div>
    </section>

    <?php if ($landing_query->have_posts()) : ?>
        <?php if ($featured) :
            $featured_id    = (int) $featured->ID;
            $featured_image = dht_solutions_v2_page_image_mobile($featured_id, 'large', true);
            $featured_terms = dht_solutions_v2_related_terms_mobile($featured_id);
        ?>
            <section class="dht-news-section" aria-labelledby="dht-solutions-portada-title">
                <div class="hub-container">
                    <div class="dht-section-head">
                        <div><span class="dht-eyebrow">En portada</span><h2 id="dht-solutions-portada-title">Explora proyectos</h2></div>
                        <p>Cinco proyectos visibles de inmediato; el archivo completo queda debajo.</p>
                    </div>
                    <div class="dht-front-grid">
                        <article class="dht-lead-card">
                            <a class="dht-lead-media" href="<?php echo esc_url(get_permalink($featured_id)); ?>" aria-label="<?php echo esc_attr(get_the_title($featured_id)); ?>"><?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Image helper builds HTML with context-specific escaping.
                        echo dht_solutions_v2_img_mobile($featured_image, get_the_title($featured_id), 'eager', 'high'); ?></a>
                            <div class="dht-lead-body">
                                <?php if ($featured_terms) : ?><div class="dht-solution-pills"><?php foreach (array_slice($featured_terms, 0, 3) as $term) : ?><span class="dht-solution-pill"><?php echo esc_html($term->name); ?></span><?php endforeach; ?></div><?php endif; ?>
                                <h2><a href="<?php echo esc_url(get_permalink($featured_id)); ?>"><?php echo esc_html(get_the_title($featured_id)); ?></a></h2>
                                <p><?php echo esc_html(dht_solutions_v2_excerpt_mobile($featured_id, 42)); ?></p>
                                <a class="dht-read-link" href="<?php echo esc_url(get_permalink($featured_id)); ?>">Ver solución →</a>
                            </div>
                        </article>

                        <?php if ($rail_posts) : ?>
                            <div class="dht-front-rail">
                                <?php foreach ($rail_posts as $rail_post) :
                                    $rail_id    = (int) $rail_post->ID;
                                    $rail_image = dht_solutions_v2_page_image_mobile($rail_id, 'medium_large', true);
                                    $rail_terms = dht_solutions_v2_related_terms_mobile($rail_id);
                                ?>
                                    <article class="dht-rail-card">
                                        <a class="dht-rail-media" href="<?php echo esc_url(get_permalink($rail_id)); ?>" tabindex="-1" aria-hidden="true"><?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Image helper builds HTML with context-specific escaping.
                        echo dht_solutions_v2_img_mobile($rail_image, get_the_title($rail_id)); ?></a>
                                        <div class="dht-rail-body">
                                            <?php if ($rail_terms) : ?><div class="dht-solution-pills"><span class="dht-solution-pill"><?php echo esc_html($rail_terms[0]->name); ?></span></div><?php endif; ?>
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
            <section class="dht-news-section" aria-labelledby="solutions-index-title">
                <div class="hub-container">
                    <div class="dht-section-head">
                        <div><span class="dht-eyebrow"><?php echo $paged > 1 ? 'Archivo' : 'Más proyectos'; ?></span><h2 id="solutions-index-title"><?php echo $paged > 1 ? 'Proyectos' : 'Todos los proyectos'; ?></h2></div>
                        <p>Guías organizadas alrededor de una necesidad real: oficio, trabajo, aplicación, comparación o decisión de compra.</p>
                    </div>
                    <div class="dht-solutions-grid">
                        <?php foreach ($grid_posts as $landing_post) :
                            $landing_id    = (int) $landing_post->ID;
                            $landing_image = dht_solutions_v2_page_image_mobile($landing_id, 'medium_large', true);
                            $landing_terms = dht_solutions_v2_related_terms_mobile($landing_id);
                        ?>
                            <article class="dht-solution-card">
                                <a class="dht-solution-media" href="<?php echo esc_url(get_permalink($landing_id)); ?>" tabindex="-1" aria-hidden="true"><?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Image helper builds HTML with context-specific escaping.
                        echo dht_solutions_v2_img_mobile($landing_image, get_the_title($landing_id)); ?></a>
                                <div class="dht-solution-body">
                                    <?php if ($landing_terms) : ?><div class="dht-solution-pills"><?php foreach (array_slice($landing_terms, 0, 2) as $term) : ?><span class="dht-solution-pill"><?php echo esc_html($term->name); ?></span><?php endforeach; ?></div><?php endif; ?>
                                    <h3><a href="<?php echo esc_url(get_permalink($landing_id)); ?>"><?php echo esc_html(get_the_title($landing_id)); ?></a></h3>
                                    <p><?php echo esc_html(dht_solutions_v2_excerpt_mobile($landing_id, 22)); ?></p>
                                    <a class="dht-read-link" href="<?php echo esc_url(get_permalink($landing_id)); ?>">Ver solución →</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <?php
                    $pagination = paginate_links(array('total' => (int) $landing_query->max_num_pages, 'current' => $paged, 'type' => 'list', 'prev_text' => '← Anterior', 'next_text' => 'Siguiente →'));
                    if ($pagination) : ?>
                        <nav class="solutions-pagination" aria-label="Paginación de proyectos"><?php echo wp_kses_post($pagination); ?></nav>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    <?php else : ?>
        <section class="dht-news-section"><div class="hub-container"><p>Todavía no hay proyectos publicados.</p></div></section>
    <?php endif; ?>

    <?php wp_reset_postdata(); ?>

    <?php if ($cluster_query->have_posts()) : ?>
        <section class="dht-cluster-section" aria-labelledby="solutions-clusters-title">
            <div class="hub-container">
                <div class="dht-section-head">
                    <div><span class="dht-eyebrow">Explorar catálogo</span><h2 id="solutions-clusters-title">Grandes áreas</h2></div>
                    <p>Acceso directo a las principales ramas del catálogo cuando prefieras navegar por área en lugar de por solución.</p>
                </div>
                <div class="dht-cluster-grid">
                    <?php while ($cluster_query->have_posts()) : $cluster_query->the_post();
                        $cluster_id    = get_the_ID();
                        $cluster_image = dht_solutions_v2_page_image_mobile($cluster_id, 'medium_large', false);
                        $cluster_text  = dht_solutions_v2_excerpt_mobile($cluster_id, 20);
                    ?>
                        <article class="dht-cluster-card">
                            <a class="dht-cluster-media" href="<?php echo esc_url(get_permalink($cluster_id)); ?>" tabindex="-1" aria-hidden="true"><?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Image helper builds HTML with context-specific escaping.
                        echo dht_solutions_v2_img_mobile($cluster_image, get_the_title($cluster_id)); ?></a>
                            <div class="dht-cluster-body"><h3><a href="<?php echo esc_url(get_permalink($cluster_id)); ?>"><?php echo esc_html(get_the_title($cluster_id)); ?></a></h3><p><?php echo esc_html($cluster_text); ?></p><a class="dht-read-link" href="<?php echo esc_url(get_permalink($cluster_id)); ?>">Explorar →</a></div>
                        </article>
                    <?php endwhile; ?>
                </div>
            </div>
        </section>
        <?php wp_reset_postdata(); ?>
    <?php endif; ?>
</main>

<?php dht_template_render_footer(); ?>
