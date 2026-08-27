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

$blog_title = $blog_page_id ? trim((string) get_the_title($blog_page_id)) : '';
if ($blog_title === '') {
    $blog_title = 'Blog';
}

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
if ($blog_intro === '') {
    $blog_intro = 'Guías, análisis y criterios técnicos para elegir, utilizar y mantener herramientas y equipamiento profesional con más contexto.';
}

$blog_query = new WP_Query(array(
    'post_type'           => 'post',
    'post_status'         => 'publish',
    'posts_per_page'      => $per_page,
    'paged'               => $paged,
    'ignore_sticky_posts' => false,
    'no_found_rows'       => false,
));

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

$blog_categories = get_categories(array(
    'taxonomy'   => 'category',
    'hide_empty' => true,
    'number'     => 12,
    'orderby'    => 'count',
    'order'      => 'DESC',
));
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
<?php echo wp_json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?>
</script>

<style id="dht-blog-news-v3-css">
.dht-blog-index.dht-blog-news-v3{--dht-ink:#172033;--dht-navy:#22314f;--dht-accent:#4d46ff;--dht-soft:#f4f6fa;--dht-line:#e3e7ef;--dht-muted:#667085;background:#fff;color:var(--dht-ink)}
.dht-blog-index.dht-blog-news-v3 .hub-container{width:min(1180px,calc(100% - 40px));margin-inline:auto}
.dht-blog-index.dht-blog-news-v3 a{text-decoration:none}
.dht-blog-index.dht-blog-news-v3 .dht-blog-hero{background:var(--dht-navy);color:#fff;padding:34px 0 30px}
.dht-blog-index.dht-blog-news-v3 .dht-blog-hero .hub-title{margin:5px 0 8px;color:#fff;font-size:clamp(2.05rem,4.6vw,3.5rem);line-height:1.02;letter-spacing:-.035em}
.dht-blog-index.dht-blog-news-v3 .dht-blog-hero .hub-excerpt{max-width:790px;margin:0;color:rgba(255,255,255,.83);font-size:1.03rem;line-height:1.65}
.dht-blog-index.dht-blog-news-v3 .dht-kicker,.dht-blog-index.dht-blog-news-v3 .dht-eyebrow{display:inline-flex;align-items:center;gap:7px;font-size:.76rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
.dht-blog-index.dht-blog-news-v3 .dht-kicker{color:#cfd5ff}
.dht-blog-index.dht-blog-news-v3 .dht-blog-hero-meta{display:flex;flex-wrap:wrap;gap:9px;margin-top:16px}
.dht-blog-index.dht-blog-news-v3 .dht-blog-hero-meta span{border:1px solid rgba(255,255,255,.18);border-radius:999px;padding:6px 10px;color:rgba(255,255,255,.9);font-size:.78rem}
.dht-blog-index.dht-blog-news-v3 .dht-blog-topics{border-bottom:1px solid var(--dht-line);background:#fff}
.dht-blog-index.dht-blog-news-v3 .dht-blog-topic-list{display:flex;gap:8px;overflow:auto;padding:14px 0;scrollbar-width:none}
.dht-blog-index.dht-blog-news-v3 .dht-blog-topic-list::-webkit-scrollbar{display:none}
.dht-blog-index.dht-blog-news-v3 .dht-blog-topic{display:inline-flex;flex:0 0 auto;align-items:center;gap:7px;border:1px solid var(--dht-line);border-radius:999px;padding:8px 12px;color:var(--dht-ink);background:#fff;font-size:.86rem;font-weight:700}
.dht-blog-index.dht-blog-news-v3 .dht-blog-topic small{font-weight:600;color:var(--dht-muted)}
.dht-blog-index.dht-blog-news-v3 .dht-blog-topic:hover{border-color:var(--dht-accent);color:var(--dht-accent)}
.dht-blog-index.dht-blog-news-v3 .dht-news-section{padding:36px 0}
.dht-blog-index.dht-blog-news-v3 .dht-news-section+.dht-news-section{padding-top:8px}
.dht-blog-index.dht-blog-news-v3 .dht-section-head{display:flex;align-items:end;justify-content:space-between;gap:20px;margin-bottom:18px}
.dht-blog-index.dht-blog-news-v3 .dht-section-head h2{margin:3px 0 0;font-size:clamp(1.55rem,3vw,2.1rem);line-height:1.08;letter-spacing:-.025em}
.dht-blog-index.dht-blog-news-v3 .dht-section-head p{max-width:520px;margin:0;color:var(--dht-muted);font-size:.93rem}
.dht-blog-index.dht-blog-news-v3 .dht-eyebrow{color:var(--dht-accent)}
.dht-blog-index.dht-blog-news-v3 .dht-front-grid{display:grid;grid-template-columns:minmax(0,1.72fr) minmax(290px,.78fr);gap:22px;align-items:stretch}
.dht-blog-index.dht-blog-news-v3 .dht-lead-card{overflow:hidden;border:1px solid var(--dht-line);border-radius:18px;background:#fff;box-shadow:0 14px 38px rgba(26,35,57,.08)}
.dht-blog-index.dht-blog-news-v3 .dht-lead-media{display:block;aspect-ratio:16/8.7;overflow:hidden;background:var(--dht-soft)}
.dht-blog-index.dht-blog-news-v3 .dht-lead-media img{width:100%;height:100%;display:block;object-fit:cover}
.dht-blog-index.dht-blog-news-v3 .dht-image-source-logo{object-fit:contain!important;padding:28px;background:#fff}
.dht-blog-index.dht-blog-news-v3 .dht-lead-body{padding:22px 24px 24px}
.dht-blog-index.dht-blog-news-v3 .dht-card-meta{display:flex;flex-wrap:wrap;align-items:center;gap:7px;color:var(--dht-muted);font-size:.78rem;font-weight:650}
.dht-blog-index.dht-blog-news-v3 .dht-card-meta a{color:var(--dht-accent);font-weight:800}
.dht-blog-index.dht-blog-news-v3 .dht-lead-body h2{margin:10px 0 10px;font-size:clamp(1.65rem,3vw,2.45rem);line-height:1.08;letter-spacing:-.03em}
.dht-blog-index.dht-blog-news-v3 .dht-lead-body h2 a,.dht-blog-index.dht-blog-news-v3 .dht-news-card h3 a,.dht-blog-index.dht-blog-news-v3 .dht-rail-card h3 a{color:var(--dht-ink)}
.dht-blog-index.dht-blog-news-v3 .dht-lead-body p{margin:0;color:#4b5565;line-height:1.65;font-size:.97rem}
.dht-blog-index.dht-blog-news-v3 .dht-read-link{display:inline-flex;margin-top:16px;color:var(--dht-accent);font-size:.9rem;font-weight:800}
.dht-blog-index.dht-blog-news-v3 .dht-front-rail{display:grid;grid-template-rows:repeat(3,minmax(0,1fr));gap:12px}
.dht-blog-index.dht-blog-news-v3 .dht-rail-card{display:grid;grid-template-columns:118px minmax(0,1fr);overflow:hidden;border:1px solid var(--dht-line);border-radius:15px;background:#fff;min-height:126px}
.dht-blog-index.dht-blog-news-v3 .dht-rail-media{display:block;background:var(--dht-soft);overflow:hidden}
.dht-blog-index.dht-blog-news-v3 .dht-rail-media img{width:100%;height:100%;display:block;object-fit:cover}
.dht-blog-index.dht-blog-news-v3 .dht-rail-body{padding:12px 13px;min-width:0}
.dht-blog-index.dht-blog-news-v3 .dht-rail-card h3{margin:6px 0 0;font-size:1rem;line-height:1.25;letter-spacing:-.012em;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.dht-blog-index.dht-blog-news-v3 .dht-news-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}
.dht-blog-index.dht-blog-news-v3 .dht-news-card{overflow:hidden;border:1px solid var(--dht-line);border-radius:16px;background:#fff;transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease}
.dht-blog-index.dht-blog-news-v3 .dht-news-card:hover{transform:translateY(-3px);border-color:#ccd2df;box-shadow:0 12px 28px rgba(26,35,57,.08)}
.dht-blog-index.dht-blog-news-v3 .dht-news-card-media{display:block;aspect-ratio:16/10;overflow:hidden;background:var(--dht-soft)}
.dht-blog-index.dht-blog-news-v3 .dht-news-card-media img{width:100%;height:100%;display:block;object-fit:cover;transition:transform .25s ease}
.dht-blog-index.dht-blog-news-v3 .dht-news-card:hover .dht-news-card-media img{transform:scale(1.025)}
.dht-blog-index.dht-blog-news-v3 .dht-news-card-body{padding:15px 16px 17px}
.dht-blog-index.dht-blog-news-v3 .dht-news-card h3{margin:8px 0 8px;font-size:1.15rem;line-height:1.24;letter-spacing:-.015em;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.dht-blog-index.dht-blog-news-v3 .dht-news-card p{margin:0;color:#596273;font-size:.88rem;line-height:1.55;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.dht-blog-index.dht-blog-news-v3 .dht-blog-pagination{padding:6px 0 42px}
.dht-blog-index.dht-blog-news-v3 .dht-blog-pagination .page-numbers{display:flex;gap:7px;list-style:none;margin:0;padding:0;flex-wrap:wrap}
.dht-blog-index.dht-blog-news-v3 .dht-blog-pagination a,.dht-blog-index.dht-blog-news-v3 .dht-blog-pagination span{display:grid;place-items:center;min-width:38px;height:38px;padding:0 10px;border:1px solid var(--dht-line);border-radius:9px;color:var(--dht-ink);background:#fff;font-weight:700}
.dht-blog-index.dht-blog-news-v3 .dht-blog-pagination .current{background:var(--dht-navy);border-color:var(--dht-navy);color:#fff}
.dht-blog-index.dht-blog-news-v3 .dht-empty-state{padding:48px 0;text-align:center}
@media(max-width:900px){.dht-blog-index.dht-blog-news-v3 .dht-front-grid{grid-template-columns:1fr}.dht-blog-index.dht-blog-news-v3 .dht-front-rail{grid-template-columns:repeat(3,minmax(0,1fr));grid-template-rows:none}.dht-blog-index.dht-blog-news-v3 .dht-rail-card{grid-template-columns:1fr}.dht-blog-index.dht-blog-news-v3 .dht-rail-media{aspect-ratio:16/9}.dht-blog-index.dht-blog-news-v3 .dht-news-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:640px){.dht-blog-index.dht-blog-news-v3 .hub-container{width:min(100% - 28px,1180px)}.dht-blog-index.dht-blog-news-v3 .dht-blog-hero{padding:24px 0 22px}.dht-blog-index.dht-blog-news-v3 .dht-blog-hero .hub-title{font-size:2.15rem}.dht-blog-index.dht-blog-news-v3 .dht-blog-hero .hub-excerpt{font-size:.94rem;line-height:1.55}.dht-blog-index.dht-blog-news-v3 .dht-news-section{padding:26px 0}.dht-blog-index.dht-blog-news-v3 .dht-section-head{display:block}.dht-blog-index.dht-blog-news-v3 .dht-section-head p{margin-top:7px}.dht-blog-index.dht-blog-news-v3 .dht-front-rail{grid-template-columns:1fr}.dht-blog-index.dht-blog-news-v3 .dht-rail-card{grid-template-columns:105px minmax(0,1fr);min-height:110px}.dht-blog-index.dht-blog-news-v3 .dht-rail-media{aspect-ratio:auto}.dht-blog-index.dht-blog-news-v3 .dht-lead-media{aspect-ratio:16/10}.dht-blog-index.dht-blog-news-v3 .dht-lead-body{padding:17px}.dht-blog-index.dht-blog-news-v3 .dht-lead-body h2{font-size:1.65rem}.dht-blog-index.dht-blog-news-v3 .dht-news-grid{grid-template-columns:1fr;gap:14px}.dht-blog-index.dht-blog-news-v3 .dht-news-card{display:grid;grid-template-columns:118px minmax(0,1fr)}.dht-blog-index.dht-blog-news-v3 .dht-news-card-media{aspect-ratio:auto;min-height:132px}.dht-blog-index.dht-blog-news-v3 .dht-news-card-body{padding:12px 13px}.dht-blog-index.dht-blog-news-v3 .dht-news-card h3{font-size:1.02rem;-webkit-line-clamp:3}.dht-blog-index.dht-blog-news-v3 .dht-news-card p{display:none}}

/* =========================================================
 * V3 VIEWPORT-FIRST
 * Objetivo: mostrar contenidos reales en el primer viewport.
 * ======================================================= */
.dht-blog-index.dht-blog-news-v3 .dht-blog-hero{padding:12px 0 11px!important;min-height:0!important;margin:0!important}
.dht-blog-index.dht-blog-news-v3 .dht-blog-hero>.hub-container{display:grid;grid-template-columns:minmax(150px,.42fr) minmax(0,1.58fr);grid-template-rows:auto auto auto;column-gap:24px;row-gap:2px;align-items:center}
.dht-blog-index.dht-blog-news-v3 .dht-blog-hero .dht-kicker{grid-column:1;grid-row:1;margin:0;font-size:.66rem}
.dht-blog-index.dht-blog-news-v3 .dht-blog-hero .hub-title{grid-column:1;grid-row:2/4;margin:0!important;font-size:clamp(2rem,3.4vw,2.65rem)!important;line-height:.98!important}
.dht-blog-index.dht-blog-news-v3 .dht-blog-hero .hub-excerpt{grid-column:2;grid-row:1/3;max-width:none!important;margin:0!important;font-size:.88rem!important;line-height:1.35!important;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.dht-blog-index.dht-blog-news-v3 .dht-blog-hero-meta{grid-column:2;grid-row:3;margin:4px 0 0!important;gap:5px!important}
.dht-blog-index.dht-blog-news-v3 .dht-blog-hero-meta span{padding:3px 7px!important;font-size:.68rem!important}
.dht-blog-index.dht-blog-news-v3 .dht-blog-topics{margin:0!important;padding:0!important;min-height:0!important;height:auto!important;border-bottom:1px solid var(--dht-line)!important}
.dht-blog-index.dht-blog-news-v3 .dht-blog-topics .hub-container{margin-top:0!important;margin-bottom:0!important;padding-top:0!important;padding-bottom:0!important;min-height:0!important}
.dht-blog-index.dht-blog-news-v3 .dht-blog-topic-list{padding:6px 0!important;margin:0!important;gap:6px!important;min-height:0!important}
.dht-blog-index.dht-blog-news-v3 .dht-blog-topic{padding:4px 8px!important;font-size:.74rem!important;line-height:1.15!important}
.dht-blog-index.dht-blog-news-v3 .dht-news-section{padding:14px 0 20px!important;margin:0!important}
.dht-blog-index.dht-blog-news-v3 .dht-news-section+.dht-news-section{padding-top:6px!important}
.dht-blog-index.dht-blog-news-v3 .dht-section-head{margin:0 0 9px!important;gap:14px!important;align-items:center!important}
.dht-blog-index.dht-blog-news-v3 .dht-section-head h2{margin:1px 0 0!important;font-size:1.38rem!important;line-height:1.05!important}
.dht-blog-index.dht-blog-news-v3 .dht-section-head p{font-size:.79rem!important;line-height:1.3!important;max-width:430px!important}
.dht-blog-index.dht-blog-news-v3 .dht-eyebrow{font-size:.64rem!important}
.dht-blog-index.dht-blog-news-v3 .dht-front-grid{grid-template-columns:minmax(0,1.38fr) minmax(390px,.92fr)!important;gap:12px!important;align-items:stretch!important}
.dht-blog-index.dht-blog-news-v3 .dht-lead-card{display:grid!important;grid-template-columns:43% minmax(0,57%)!important;min-height:224px!important;max-height:236px!important;border-radius:14px!important}
.dht-blog-index.dht-blog-news-v3 .dht-lead-media{aspect-ratio:auto!important;height:100%!important;min-height:0!important}
.dht-blog-index.dht-blog-news-v3 .dht-lead-body{padding:13px 15px!important;overflow:hidden!important;display:flex!important;flex-direction:column!important;justify-content:center!important}
.dht-blog-index.dht-blog-news-v3 .dht-lead-body h2{margin:7px 0 7px!important;font-size:1.4rem!important;line-height:1.1!important;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.dht-blog-index.dht-blog-news-v3 .dht-lead-body p{font-size:.82rem!important;line-height:1.38!important;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.dht-blog-index.dht-blog-news-v3 .dht-card-meta{font-size:.67rem!important;gap:5px!important}
.dht-blog-index.dht-blog-news-v3 .dht-read-link{margin-top:8px!important;font-size:.78rem!important}
.dht-blog-index.dht-blog-news-v3 .dht-front-rail{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;grid-template-rows:repeat(2,minmax(0,1fr))!important;gap:8px!important}
.dht-blog-index.dht-blog-news-v3 .dht-rail-card{grid-template-columns:82px minmax(0,1fr)!important;min-height:108px!important;max-height:114px!important;border-radius:12px!important}
.dht-blog-index.dht-blog-news-v3 .dht-rail-body{padding:9px 9px!important;display:flex!important;flex-direction:column!important;justify-content:center!important}
.dht-blog-index.dht-blog-news-v3 .dht-rail-card h3{margin:4px 0 0!important;font-size:.86rem!important;line-height:1.19!important;-webkit-line-clamp:3!important}
.dht-blog-index.dht-blog-news-v3 .dht-news-grid{gap:12px!important}
.dht-blog-index.dht-blog-news-v3 .dht-news-card-body{padding:12px 13px 14px!important}
.dht-blog-index.dht-blog-news-v3 .dht-news-card h3{font-size:1.02rem!important;margin:6px 0!important}

@media(max-width:900px){
.dht-blog-index.dht-blog-news-v3 .dht-front-grid{grid-template-columns:1fr!important}
.dht-blog-index.dht-blog-news-v3 .dht-front-rail{grid-template-columns:repeat(2,minmax(0,1fr))!important}
}
@media(max-width:640px){
.dht-blog-index.dht-blog-news-v3 .hub-container{width:min(100% - 20px,1180px)!important}
.dht-blog-index.dht-blog-news-v3 .dht-blog-hero{padding:9px 0 8px!important}
.dht-blog-index.dht-blog-news-v3 .dht-blog-hero>.hub-container{display:block!important}
.dht-blog-index.dht-blog-news-v3 .dht-blog-hero .dht-kicker{display:none!important}
.dht-blog-index.dht-blog-news-v3 .dht-blog-hero .hub-title{font-size:1.7rem!important;margin:0 0 2px!important}
.dht-blog-index.dht-blog-news-v3 .dht-blog-hero .hub-excerpt{font-size:.76rem!important;line-height:1.25!important;-webkit-line-clamp:2!important}
.dht-blog-index.dht-blog-news-v3 .dht-blog-hero-meta{display:none!important}
.dht-blog-index.dht-blog-news-v3 .dht-blog-topic-list{padding:4px 0!important;gap:4px!important}
.dht-blog-index.dht-blog-news-v3 .dht-blog-topic{padding:3px 6px!important;font-size:.66rem!important}
.dht-blog-index.dht-blog-news-v3 .dht-news-section{padding:9px 0 12px!important}
.dht-blog-index.dht-blog-news-v3 .dht-section-head{margin-bottom:6px!important}
.dht-blog-index.dht-blog-news-v3 .dht-section-head .dht-eyebrow,.dht-blog-index.dht-blog-news-v3 .dht-section-head p{display:none!important}
.dht-blog-index.dht-blog-news-v3 .dht-section-head h2{font-size:1.08rem!important;margin:0!important}
.dht-blog-index.dht-blog-news-v3 .dht-front-grid{display:block!important}
.dht-blog-index.dht-blog-news-v3 .dht-lead-card{grid-template-columns:108px minmax(0,1fr)!important;min-height:112px!important;max-height:118px!important;margin-bottom:7px!important}
.dht-blog-index.dht-blog-news-v3 .dht-lead-body{padding:8px 9px!important}
.dht-blog-index.dht-blog-news-v3 .dht-lead-body h2{font-size:.94rem!important;line-height:1.15!important;margin:4px 0!important;-webkit-line-clamp:3!important}
.dht-blog-index.dht-blog-news-v3 .dht-lead-body p,.dht-blog-index.dht-blog-news-v3 .dht-lead-body .dht-read-link{display:none!important}
.dht-blog-index.dht-blog-news-v3 .dht-card-meta{font-size:.59rem!important;gap:3px!important}
.dht-blog-index.dht-blog-news-v3 .dht-front-rail{grid-template-columns:repeat(2,minmax(0,1fr))!important;grid-template-rows:repeat(2,82px)!important;gap:6px!important}
.dht-blog-index.dht-blog-news-v3 .dht-rail-card{grid-template-columns:58px minmax(0,1fr)!important;min-height:82px!important;max-height:82px!important}
.dht-blog-index.dht-blog-news-v3 .dht-rail-body{padding:6px!important}
.dht-blog-index.dht-blog-news-v3 .dht-rail-body .dht-card-meta{display:none!important}
.dht-blog-index.dht-blog-news-v3 .dht-rail-card h3{font-size:.74rem!important;line-height:1.14!important;margin:0!important;-webkit-line-clamp:4!important}
.dht-blog-index.dht-blog-news-v3 .dht-news-grid{grid-template-columns:1fr!important;gap:8px!important}
}

</style>

<!-- DHT BLOG INDEX VIEWPORT-FIRST V3 - 2026-08-26 -->
<main class="dht-blog-index dht-blog-news-v3 dht-mobile-template">
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
                                <?php echo dht_blog_v2_img($featured_image, get_the_title($featured_id), '', 'eager', 'high'); ?>
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
                                        <a class="dht-rail-media" href="<?php echo esc_url(get_permalink($rail_id)); ?>" tabindex="-1" aria-hidden="true"><?php echo dht_blog_v2_img($rail_image, get_the_title($rail_id)); ?></a>
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
                                <a class="dht-news-card-media" href="<?php echo esc_url(get_permalink($post_id)); ?>" tabindex="-1" aria-hidden="true"><?php echo dht_blog_v2_img($post_image, get_the_title($post_id)); ?></a>
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
