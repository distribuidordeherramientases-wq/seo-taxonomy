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
        array('@type' => 'ItemList', '@id' => get_permalink($page_id) . '#soluciones', 'name' => 'Soluciones', 'numberOfItems' => count($item_list), 'itemListElement' => $item_list),
    ),
);

$landing_posts = (array) $landing_query->posts;
$featured      = ($paged === 1 && !empty($landing_posts)) ? $landing_posts[0] : null;
$rail_posts    = ($paged === 1) ? array_slice($landing_posts, 1, 4) : array();
$grid_posts    = ($paged === 1) ? array_slice($landing_posts, 5) : $landing_posts;
?>

<script type="application/ld+json"><?php echo wp_json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?></script>

<style id="dht-solutions-news-v3-css">
.solutions-index-page.dht-solutions-news-v3{--dht-ink:#172033;--dht-navy:#22314f;--dht-accent:#4d46ff;--dht-soft:#f4f6fa;--dht-line:#e3e7ef;--dht-muted:#667085;background:#fff;color:var(--dht-ink)}
.solutions-index-page.dht-solutions-news-v3 .hub-container{width:min(1180px,calc(100% - 40px));margin-inline:auto}
.solutions-index-page.dht-solutions-news-v3 a{text-decoration:none}
.solutions-index-page.dht-solutions-news-v3 .dht-solutions-hero{background:var(--dht-navy);color:#fff;padding:34px 0 30px}
.solutions-index-page.dht-solutions-news-v3 .dht-solutions-hero h1{margin:5px 0 8px;color:#fff;font-size:clamp(2.05rem,4.6vw,3.5rem);line-height:1.02;letter-spacing:-.035em}
.solutions-index-page.dht-solutions-news-v3 .dht-solutions-hero p{max-width:820px;margin:0;color:rgba(255,255,255,.84);font-size:1.03rem;line-height:1.65}
.solutions-index-page.dht-solutions-news-v3 .dht-kicker,.solutions-index-page.dht-solutions-news-v3 .dht-eyebrow{display:inline-flex;align-items:center;font-size:.76rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
.solutions-index-page.dht-solutions-news-v3 .dht-kicker{color:#cfd5ff}
.solutions-index-page.dht-solutions-news-v3 .dht-solutions-hero-meta{display:flex;flex-wrap:wrap;gap:9px;margin-top:16px}
.solutions-index-page.dht-solutions-news-v3 .dht-solutions-hero-meta span{border:1px solid rgba(255,255,255,.18);border-radius:999px;padding:6px 10px;color:rgba(255,255,255,.9);font-size:.78rem}
.solutions-index-page.dht-solutions-news-v3 .dht-news-section{padding:36px 0}
.solutions-index-page.dht-solutions-news-v3 .dht-news-section+.dht-news-section{padding-top:8px}
.solutions-index-page.dht-solutions-news-v3 .dht-section-head{display:flex;align-items:end;justify-content:space-between;gap:20px;margin-bottom:18px}
.solutions-index-page.dht-solutions-news-v3 .dht-section-head h2{margin:3px 0 0;font-size:clamp(1.55rem,3vw,2.1rem);line-height:1.08;letter-spacing:-.025em}
.solutions-index-page.dht-solutions-news-v3 .dht-section-head p{max-width:560px;margin:0;color:var(--dht-muted);font-size:.93rem}
.solutions-index-page.dht-solutions-news-v3 .dht-eyebrow{color:var(--dht-accent)}
.solutions-index-page.dht-solutions-news-v3 .dht-front-grid{display:grid;grid-template-columns:minmax(0,1.72fr) minmax(290px,.78fr);gap:22px}
.solutions-index-page.dht-solutions-news-v3 .dht-lead-card{overflow:hidden;border:1px solid var(--dht-line);border-radius:18px;background:#fff;box-shadow:0 14px 38px rgba(26,35,57,.08)}
.solutions-index-page.dht-solutions-news-v3 .dht-lead-media{display:block;aspect-ratio:16/8.7;overflow:hidden;background:var(--dht-soft)}
.solutions-index-page.dht-solutions-news-v3 .dht-lead-media img{width:100%;height:100%;display:block;object-fit:cover}
.solutions-index-page.dht-solutions-news-v3 .dht-image-source-logo{object-fit:contain!important;padding:28px;background:#fff}
.solutions-index-page.dht-solutions-news-v3 .dht-lead-body{padding:22px 24px 24px}
.solutions-index-page.dht-solutions-news-v3 .dht-solution-pills{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:9px}
.solutions-index-page.dht-solutions-news-v3 .dht-solution-pill{display:inline-flex;border-radius:999px;background:#efefff;color:#3f38d8;padding:5px 9px;font-size:.72rem;font-weight:800;line-height:1.2}
.solutions-index-page.dht-solutions-news-v3 .dht-lead-body h2{margin:8px 0 10px;font-size:clamp(1.65rem,3vw,2.45rem);line-height:1.08;letter-spacing:-.03em}
.solutions-index-page.dht-solutions-news-v3 .dht-lead-body h2 a,.solutions-index-page.dht-solutions-news-v3 .dht-solution-card h3 a,.solutions-index-page.dht-solutions-news-v3 .dht-rail-card h3 a{color:var(--dht-ink)}
.solutions-index-page.dht-solutions-news-v3 .dht-lead-body p{margin:0;color:#4b5565;line-height:1.65;font-size:.97rem}
.solutions-index-page.dht-solutions-news-v3 .dht-read-link{display:inline-flex;margin-top:16px;color:var(--dht-accent);font-size:.9rem;font-weight:800}
.solutions-index-page.dht-solutions-news-v3 .dht-front-rail{display:grid;grid-template-rows:repeat(3,minmax(0,1fr));gap:12px}
.solutions-index-page.dht-solutions-news-v3 .dht-rail-card{display:grid;grid-template-columns:118px minmax(0,1fr);overflow:hidden;border:1px solid var(--dht-line);border-radius:15px;background:#fff;min-height:126px}
.solutions-index-page.dht-solutions-news-v3 .dht-rail-media{display:block;overflow:hidden;background:var(--dht-soft)}
.solutions-index-page.dht-solutions-news-v3 .dht-rail-media img{width:100%;height:100%;display:block;object-fit:cover}
.solutions-index-page.dht-solutions-news-v3 .dht-rail-body{padding:12px 13px;min-width:0}
.solutions-index-page.dht-solutions-news-v3 .dht-rail-card h3{margin:7px 0 0;font-size:1rem;line-height:1.25;letter-spacing:-.012em;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.solutions-index-page.dht-solutions-news-v3 .dht-solutions-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}
.solutions-index-page.dht-solutions-news-v3 .dht-solution-card{overflow:hidden;border:1px solid var(--dht-line);border-radius:16px;background:#fff;transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease}
.solutions-index-page.dht-solutions-news-v3 .dht-solution-card:hover{transform:translateY(-3px);border-color:#ccd2df;box-shadow:0 12px 28px rgba(26,35,57,.08)}
.solutions-index-page.dht-solutions-news-v3 .dht-solution-media{display:block;aspect-ratio:16/10;overflow:hidden;background:var(--dht-soft)}
.solutions-index-page.dht-solutions-news-v3 .dht-solution-media img{width:100%;height:100%;display:block;object-fit:cover;transition:transform .25s ease}
.solutions-index-page.dht-solutions-news-v3 .dht-solution-card:hover .dht-solution-media img{transform:scale(1.025)}
.solutions-index-page.dht-solutions-news-v3 .dht-solution-body{padding:15px 16px 17px}
.solutions-index-page.dht-solutions-news-v3 .dht-solution-card h3{margin:8px 0 8px;font-size:1.15rem;line-height:1.24;letter-spacing:-.015em;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.solutions-index-page.dht-solutions-news-v3 .dht-solution-card p{margin:0;color:#596273;font-size:.88rem;line-height:1.55;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.solutions-index-page.dht-solutions-news-v3 .solutions-pagination{padding:28px 0 4px}
.solutions-index-page.dht-solutions-news-v3 .solutions-pagination .page-numbers{display:flex;gap:7px;list-style:none;margin:0;padding:0;flex-wrap:wrap}
.solutions-index-page.dht-solutions-news-v3 .solutions-pagination a,.solutions-index-page.dht-solutions-news-v3 .solutions-pagination span{display:grid;place-items:center;min-width:38px;height:38px;padding:0 10px;border:1px solid var(--dht-line);border-radius:9px;color:var(--dht-ink);background:#fff;font-weight:700}
.solutions-index-page.dht-solutions-news-v3 .solutions-pagination .current{background:var(--dht-navy);border-color:var(--dht-navy);color:#fff}
.solutions-index-page.dht-solutions-news-v3 .dht-cluster-section{margin-top:12px;padding:36px 0 44px;background:var(--dht-soft);border-top:1px solid var(--dht-line)}
.solutions-index-page.dht-solutions-news-v3 .dht-cluster-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.solutions-index-page.dht-solutions-news-v3 .dht-cluster-card{display:grid;grid-template-columns:116px minmax(0,1fr);overflow:hidden;background:#fff;border:1px solid var(--dht-line);border-radius:15px}
.solutions-index-page.dht-solutions-news-v3 .dht-cluster-media{min-height:128px;overflow:hidden;background:#fff}
.solutions-index-page.dht-solutions-news-v3 .dht-cluster-media img{width:100%;height:100%;display:block;object-fit:cover}
.solutions-index-page.dht-solutions-news-v3 .dht-cluster-body{padding:14px;min-width:0}.solutions-index-page.dht-solutions-news-v3 .dht-cluster-body h3{margin:0 0 6px;font-size:1rem;line-height:1.25}.solutions-index-page.dht-solutions-news-v3 .dht-cluster-body h3 a{color:var(--dht-ink)}.solutions-index-page.dht-solutions-news-v3 .dht-cluster-body p{margin:0;color:var(--dht-muted);font-size:.8rem;line-height:1.45;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
@media(max-width:900px){.solutions-index-page.dht-solutions-news-v3 .dht-front-grid{grid-template-columns:1fr}.solutions-index-page.dht-solutions-news-v3 .dht-front-rail{grid-template-columns:repeat(3,minmax(0,1fr));grid-template-rows:none}.solutions-index-page.dht-solutions-news-v3 .dht-rail-card{grid-template-columns:1fr}.solutions-index-page.dht-solutions-news-v3 .dht-rail-media{aspect-ratio:16/9}.solutions-index-page.dht-solutions-news-v3 .dht-solutions-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.solutions-index-page.dht-solutions-news-v3 .dht-cluster-grid{grid-template-columns:1fr}}
@media(max-width:640px){.solutions-index-page.dht-solutions-news-v3 .hub-container{width:min(100% - 28px,1180px)}.solutions-index-page.dht-solutions-news-v3 .dht-solutions-hero{padding:24px 0 22px}.solutions-index-page.dht-solutions-news-v3 .dht-solutions-hero h1{font-size:2.15rem}.solutions-index-page.dht-solutions-news-v3 .dht-solutions-hero p{font-size:.94rem;line-height:1.55}.solutions-index-page.dht-solutions-news-v3 .dht-news-section{padding:26px 0}.solutions-index-page.dht-solutions-news-v3 .dht-section-head{display:block}.solutions-index-page.dht-solutions-news-v3 .dht-section-head p{margin-top:7px}.solutions-index-page.dht-solutions-news-v3 .dht-front-rail{grid-template-columns:1fr}.solutions-index-page.dht-solutions-news-v3 .dht-rail-card{grid-template-columns:105px minmax(0,1fr);min-height:110px}.solutions-index-page.dht-solutions-news-v3 .dht-rail-media{aspect-ratio:auto}.solutions-index-page.dht-solutions-news-v3 .dht-lead-media{aspect-ratio:16/10}.solutions-index-page.dht-solutions-news-v3 .dht-lead-body{padding:17px}.solutions-index-page.dht-solutions-news-v3 .dht-lead-body h2{font-size:1.65rem}.solutions-index-page.dht-solutions-news-v3 .dht-solutions-grid{grid-template-columns:1fr;gap:14px}.solutions-index-page.dht-solutions-news-v3 .dht-solution-card{display:grid;grid-template-columns:118px minmax(0,1fr)}.solutions-index-page.dht-solutions-news-v3 .dht-solution-media{aspect-ratio:auto;min-height:132px}.solutions-index-page.dht-solutions-news-v3 .dht-solution-body{padding:12px 13px}.solutions-index-page.dht-solutions-news-v3 .dht-solution-card h3{font-size:1.02rem;-webkit-line-clamp:3}.solutions-index-page.dht-solutions-news-v3 .dht-solution-card p{display:none}.solutions-index-page.dht-solutions-news-v3 .dht-cluster-card{grid-template-columns:100px minmax(0,1fr)}}

/* =========================================================
 * V3 VIEWPORT-FIRST
 * El usuario ve soluciones reales sin scroll obligatorio.
 * ======================================================= */
.solutions-index-page.dht-solutions-news-v3 .dht-solutions-hero{padding:12px 0 11px!important;min-height:0!important;margin:0!important}
.solutions-index-page.dht-solutions-news-v3 .dht-solutions-hero>.hub-container{display:grid;grid-template-columns:minmax(180px,.5fr) minmax(0,1.5fr);grid-template-rows:auto auto auto;column-gap:24px;row-gap:2px;align-items:center}
.solutions-index-page.dht-solutions-news-v3 .dht-solutions-hero .dht-kicker{grid-column:1;grid-row:1;margin:0;font-size:.66rem}
.solutions-index-page.dht-solutions-news-v3 .dht-solutions-hero h1{grid-column:1;grid-row:2/4;margin:0!important;font-size:clamp(2rem,3.4vw,2.65rem)!important;line-height:.98!important}
.solutions-index-page.dht-solutions-news-v3 .dht-solutions-hero> .hub-container > p{grid-column:2;grid-row:1/3;max-width:none!important;margin:0!important;font-size:.88rem!important;line-height:1.35!important;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.solutions-index-page.dht-solutions-news-v3 .dht-solutions-hero-meta{grid-column:2;grid-row:3;margin:4px 0 0!important;gap:5px!important}
.solutions-index-page.dht-solutions-news-v3 .dht-solutions-hero-meta span{padding:3px 7px!important;font-size:.68rem!important}
.solutions-index-page.dht-solutions-news-v3 .dht-news-section{padding:14px 0 20px!important;margin:0!important}
.solutions-index-page.dht-solutions-news-v3 .dht-news-section+.dht-news-section{padding-top:6px!important}
.solutions-index-page.dht-solutions-news-v3 .dht-section-head{margin:0 0 9px!important;gap:14px!important;align-items:center!important}
.solutions-index-page.dht-solutions-news-v3 .dht-section-head h2{margin:1px 0 0!important;font-size:1.38rem!important;line-height:1.05!important}
.solutions-index-page.dht-solutions-news-v3 .dht-section-head p{font-size:.79rem!important;line-height:1.3!important;max-width:440px!important}
.solutions-index-page.dht-solutions-news-v3 .dht-eyebrow{font-size:.64rem!important}
.solutions-index-page.dht-solutions-news-v3 .dht-front-grid{grid-template-columns:minmax(0,1.38fr) minmax(390px,.92fr)!important;gap:12px!important;align-items:stretch!important}
.solutions-index-page.dht-solutions-news-v3 .dht-lead-card{display:grid!important;grid-template-columns:43% minmax(0,57%)!important;min-height:224px!important;max-height:236px!important;border-radius:14px!important}
.solutions-index-page.dht-solutions-news-v3 .dht-lead-media{aspect-ratio:auto!important;height:100%!important;min-height:0!important}
.solutions-index-page.dht-solutions-news-v3 .dht-lead-body{padding:13px 15px!important;overflow:hidden!important;display:flex!important;flex-direction:column!important;justify-content:center!important}
.solutions-index-page.dht-solutions-news-v3 .dht-lead-body h2{margin:7px 0!important;font-size:1.4rem!important;line-height:1.1!important;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.solutions-index-page.dht-solutions-news-v3 .dht-lead-body p{font-size:.82rem!important;line-height:1.38!important;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.solutions-index-page.dht-solutions-news-v3 .dht-read-link{margin-top:8px!important;font-size:.78rem!important}
.solutions-index-page.dht-solutions-news-v3 .dht-solution-pills{gap:4px!important;margin:0!important}
.solutions-index-page.dht-solutions-news-v3 .dht-solution-pill{padding:3px 6px!important;font-size:.63rem!important}
.solutions-index-page.dht-solutions-news-v3 .dht-front-rail{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;grid-template-rows:repeat(2,minmax(0,1fr))!important;gap:8px!important}
.solutions-index-page.dht-solutions-news-v3 .dht-rail-card{grid-template-columns:82px minmax(0,1fr)!important;min-height:108px!important;max-height:114px!important;border-radius:12px!important}
.solutions-index-page.dht-solutions-news-v3 .dht-rail-body{padding:9px!important;display:flex!important;flex-direction:column!important;justify-content:center!important}
.solutions-index-page.dht-solutions-news-v3 .dht-rail-card h3{margin:4px 0 0!important;font-size:.86rem!important;line-height:1.19!important;-webkit-line-clamp:3!important}
.solutions-index-page.dht-solutions-news-v3 .dht-solutions-grid{gap:12px!important}
.solutions-index-page.dht-solutions-news-v3 .dht-solution-body{padding:12px 13px 14px!important}
.solutions-index-page.dht-solutions-news-v3 .dht-solution-card h3{font-size:1.02rem!important;margin:6px 0!important}
.solutions-index-page.dht-solutions-news-v3 .dht-cluster-section{padding-top:10px!important}

@media(max-width:900px){
.solutions-index-page.dht-solutions-news-v3 .dht-front-grid{grid-template-columns:1fr!important}
.solutions-index-page.dht-solutions-news-v3 .dht-front-rail{grid-template-columns:repeat(2,minmax(0,1fr))!important}
}
@media(max-width:640px){
.solutions-index-page.dht-solutions-news-v3 .hub-container{width:min(100% - 20px,1180px)!important}
.solutions-index-page.dht-solutions-news-v3 .dht-solutions-hero{padding:9px 0 8px!important}
.solutions-index-page.dht-solutions-news-v3 .dht-solutions-hero>.hub-container{display:block!important}
.solutions-index-page.dht-solutions-news-v3 .dht-solutions-hero .dht-kicker{display:none!important}
.solutions-index-page.dht-solutions-news-v3 .dht-solutions-hero h1{font-size:1.7rem!important;margin:0 0 2px!important}
.solutions-index-page.dht-solutions-news-v3 .dht-solutions-hero>.hub-container>p{font-size:.76rem!important;line-height:1.25!important;-webkit-line-clamp:2!important}
.solutions-index-page.dht-solutions-news-v3 .dht-solutions-hero-meta{display:none!important}
.solutions-index-page.dht-solutions-news-v3 .dht-news-section{padding:9px 0 12px!important}
.solutions-index-page.dht-solutions-news-v3 .dht-section-head{margin-bottom:6px!important}
.solutions-index-page.dht-solutions-news-v3 .dht-section-head .dht-eyebrow,.solutions-index-page.dht-solutions-news-v3 .dht-section-head p{display:none!important}
.solutions-index-page.dht-solutions-news-v3 .dht-section-head h2{font-size:1.08rem!important;margin:0!important}
.solutions-index-page.dht-solutions-news-v3 .dht-front-grid{display:block!important}
.solutions-index-page.dht-solutions-news-v3 .dht-lead-card{grid-template-columns:108px minmax(0,1fr)!important;min-height:112px!important;max-height:118px!important;margin-bottom:7px!important}
.solutions-index-page.dht-solutions-news-v3 .dht-lead-body{padding:8px 9px!important}
.solutions-index-page.dht-solutions-news-v3 .dht-lead-body h2{font-size:.94rem!important;line-height:1.15!important;margin:4px 0!important;-webkit-line-clamp:3!important}
.solutions-index-page.dht-solutions-news-v3 .dht-lead-body p,.solutions-index-page.dht-solutions-news-v3 .dht-lead-body .dht-read-link{display:none!important}
.solutions-index-page.dht-solutions-news-v3 .dht-lead-body .dht-solution-pills{max-height:20px;overflow:hidden}
.solutions-index-page.dht-solutions-news-v3 .dht-front-rail{grid-template-columns:repeat(2,minmax(0,1fr))!important;grid-template-rows:repeat(2,82px)!important;gap:6px!important}
.solutions-index-page.dht-solutions-news-v3 .dht-rail-card{grid-template-columns:58px minmax(0,1fr)!important;min-height:82px!important;max-height:82px!important}
.solutions-index-page.dht-solutions-news-v3 .dht-rail-body{padding:6px!important}
.solutions-index-page.dht-solutions-news-v3 .dht-rail-body .dht-solution-pills{display:none!important}
.solutions-index-page.dht-solutions-news-v3 .dht-rail-card h3{font-size:.74rem!important;line-height:1.14!important;margin:0!important;-webkit-line-clamp:4!important}
.solutions-index-page.dht-solutions-news-v3 .dht-solutions-grid{grid-template-columns:1fr!important;gap:8px!important}
}

</style>

<!-- DHT SOLUCIONES INDEX VIEWPORT-FIRST V3 - 2026-08-26 -->
<main class="solutions-index-page dht-solutions-news-v3 dht-mobile-template">
    <header class="dht-solutions-hero">
        <div class="hub-container">
            <span class="dht-kicker">Guías de compra · Aplicaciones · Soluciones</span>
            <h1><?php echo esc_html($page_title); ?></h1>
            <p><?php echo esc_html($page_intro); ?></p>
            <div class="dht-solutions-hero-meta" aria-label="Resumen del índice">
                <span><?php echo esc_html(count($landing_ids)); ?> soluciones publicadas</span><span>Selección por necesidad</span><span>Conexión con catálogo real</span>
            </div>
        </div>
    </header>

    <?php if ($landing_query->have_posts()) : ?>
        <?php if ($featured) :
            $featured_id    = (int) $featured->ID;
            $featured_image = dht_solutions_v2_page_image_mobile($featured_id, 'large', true);
            $featured_terms = dht_solutions_v2_related_terms_mobile($featured_id);
        ?>
            <section class="dht-news-section" aria-labelledby="dht-solutions-portada-title">
                <div class="hub-container">
                    <div class="dht-section-head">
                        <div><span class="dht-eyebrow">En portada</span><h2 id="dht-solutions-portada-title">Explora soluciones</h2></div>
                        <p>Cinco soluciones visibles de inmediato; el archivo completo queda debajo.</p>
                    </div>
                    <div class="dht-front-grid">
                        <article class="dht-lead-card">
                            <a class="dht-lead-media" href="<?php echo esc_url(get_permalink($featured_id)); ?>" aria-label="<?php echo esc_attr(get_the_title($featured_id)); ?>"><?php echo dht_solutions_v2_img_mobile($featured_image, get_the_title($featured_id), 'eager', 'high'); ?></a>
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
                                        <a class="dht-rail-media" href="<?php echo esc_url(get_permalink($rail_id)); ?>" tabindex="-1" aria-hidden="true"><?php echo dht_solutions_v2_img_mobile($rail_image, get_the_title($rail_id)); ?></a>
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
                        <div><span class="dht-eyebrow"><?php echo $paged > 1 ? 'Archivo' : 'Más soluciones'; ?></span><h2 id="solutions-index-title"><?php echo $paged > 1 ? 'Soluciones' : 'Todas las soluciones'; ?></h2></div>
                        <p>Guías organizadas alrededor de una necesidad real: oficio, trabajo, aplicación, comparación o decisión de compra.</p>
                    </div>
                    <div class="dht-solutions-grid">
                        <?php foreach ($grid_posts as $landing_post) :
                            $landing_id    = (int) $landing_post->ID;
                            $landing_image = dht_solutions_v2_page_image_mobile($landing_id, 'medium_large', true);
                            $landing_terms = dht_solutions_v2_related_terms_mobile($landing_id);
                        ?>
                            <article class="dht-solution-card">
                                <a class="dht-solution-media" href="<?php echo esc_url(get_permalink($landing_id)); ?>" tabindex="-1" aria-hidden="true"><?php echo dht_solutions_v2_img_mobile($landing_image, get_the_title($landing_id)); ?></a>
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
                        <nav class="solutions-pagination" aria-label="Paginación de soluciones"><?php echo wp_kses_post($pagination); ?></nav>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    <?php else : ?>
        <section class="dht-news-section"><div class="hub-container"><p>Todavía no hay soluciones publicadas.</p></div></section>
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
                            <a class="dht-cluster-media" href="<?php echo esc_url(get_permalink($cluster_id)); ?>" tabindex="-1" aria-hidden="true"><?php echo dht_solutions_v2_img_mobile($cluster_image, get_the_title($cluster_id)); ?></a>
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
