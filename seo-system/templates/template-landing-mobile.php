<?php
/**
 * DHT Landing template V6 - 2026-08-26
 *
 * Layout:
 * - Hero: title + excerpt only.
 * - Main area: content 2/3 + featured image/benefits 1/3.
 * - Mobile: featured image/benefits before the long content.
 * - Products: only direct landing_to_category product_cat relations.
 * - Product images: Media -> local media index -> supplier table -> logo.
 * - Related hubs: hub_secondary nodes connected to the same product_cat branch.
 * - No random/popular/rating product fallbacks.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

dht_template_render_header();

global $wpdb;

$post_id = (int) get_the_ID();
$relations_table = $wpdb->prefix . 'seo_relations';
$nodes_table = $wpdb->prefix . 'seo_nodes';

/* ==========================================================
 * Visual helpers: Media -> supplier -> logo.
 * These helpers never write thumbnail metadata.
 * ========================================================== */

if (!function_exists('dht_landing_v6_logo_url_mobile')) {
    function dht_landing_v6_logo_url_mobile($size = 'medium_large') {
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

if (!function_exists('dht_landing_v6_local_image_mobile')) {
    function dht_landing_v6_local_image_mobile($attachment_id, $size = 'medium_large', $reject_logo = true) {
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
            'url' => (string) $url,
            'attachment_id' => $attachment_id,
            'source' => 'media',
        );
    }
}

if (!function_exists('dht_landing_v6_product_image_mobile')) {
    function dht_landing_v6_product_image_mobile($product_id, $size = 'woocommerce_thumbnail', $allow_logo = true) {
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
                ? array('url' => dht_landing_v6_logo_url_mobile($size), 'attachment_id' => 0, 'source' => 'logo')
                : null;
        }

        /* 1. Featured image in Media. */
        $source = dht_landing_v6_local_image_mobile(get_post_thumbnail_id($product_id), $size, true);
        if ($source) {
            return $cache[$cache_key] = $source;
        }

        /* 2. Any valid local attachment indexed for this product. */
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
                $source = dht_landing_v6_local_image_mobile($attachment_id, $size, true);
                if ($source) {
                    return $cache[$cache_key] = $source;
                }
            }
        }

        /* 3. Supplier image table. */
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
                    'url' => $supplier_url,
                    'attachment_id' => 0,
                    'source' => 'supplier',
                );
            }
        }

        if (function_exists('seo_supplier_v2_external_primary_url')) {
            $supplier_url = esc_url_raw((string) seo_supplier_v2_external_primary_url($product_id));
            if ($supplier_url && wp_http_validate_url($supplier_url)) {
                return $cache[$cache_key] = array(
                    'url' => $supplier_url,
                    'attachment_id' => 0,
                    'source' => 'supplier',
                );
            }
        }

        if ($allow_logo) {
            return $cache[$cache_key] = array(
                'url' => dht_landing_v6_logo_url_mobile($size),
                'attachment_id' => 0,
                'source' => 'logo',
            );
        }

        return $cache[$cache_key] = null;
    }
}

if (!function_exists('dht_landing_v6_term_image_mobile')) {
    function dht_landing_v6_term_image_mobile($term_id, $size = 'medium_large', $allow_logo = true) {
        $term_id = absint($term_id);
        if ($term_id < 1) {
            return $allow_logo
                ? array('url' => dht_landing_v6_logo_url_mobile($size), 'attachment_id' => 0, 'source' => 'logo')
                : null;
        }

        $source = dht_landing_v6_local_image_mobile(get_term_meta($term_id, 'thumbnail_id', true), $size, true);
        if ($source) {
            return $source;
        }

        $product_ids = get_posts(array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => 12,
            'fields' => 'ids',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'orderby' => 'date',
            'order' => 'DESC',
            'tax_query' => array(
                array(
                    'taxonomy' => 'product_cat',
                    'field' => 'term_id',
                    'terms' => array($term_id),
                    'include_children' => false,
                ),
            ),
        ));

        foreach ((array) $product_ids as $product_id) {
            $source = dht_landing_v6_product_image_mobile($product_id, $size, false);
            if (!empty($source['url'])) {
                return $source;
            }
        }

        return $allow_logo
            ? array('url' => dht_landing_v6_logo_url_mobile($size), 'attachment_id' => 0, 'source' => 'logo')
            : null;
    }
}

if (!function_exists('dht_landing_v6_main_image_mobile')) {
    function dht_landing_v6_main_image_mobile($page_id, $term_ids, $size = 'large') {
        $source = dht_landing_v6_local_image_mobile(get_post_thumbnail_id($page_id), $size, true);
        if ($source) {
            return $source;
        }

        foreach ((array) $term_ids as $term_id) {
            $source = dht_landing_v6_term_image_mobile($term_id, $size, false);
            if (!empty($source['url'])) {
                return $source;
            }
        }

        return array('url' => dht_landing_v6_logo_url_mobile($size), 'attachment_id' => 0, 'source' => 'logo');
    }
}

if (!function_exists('dht_landing_v6_hub_image_mobile')) {
    function dht_landing_v6_hub_image_mobile($hub_id, $size = 'medium_large') {
        global $wpdb;

        $hub_id = absint($hub_id);
        $source = dht_landing_v6_local_image_mobile(get_post_thumbnail_id($hub_id), $size, true);
        if ($source) {
            return $source;
        }

        $term_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT target_id
                 FROM {$wpdb->prefix}seo_relations
                 WHERE source_type = 'hub_secondary'
                   AND source_id = %d
                   AND target_type = 'product_cat'
                   AND relation_type = 'hub_secondary_to_category'
                 ORDER BY id ASC",
                $hub_id
            )
        );

        foreach ((array) $term_ids as $term_id) {
            $source = dht_landing_v6_term_image_mobile($term_id, $size, false);
            if (!empty($source['url'])) {
                return $source;
            }
        }

        return array('url' => dht_landing_v6_logo_url_mobile($size), 'attachment_id' => 0, 'source' => 'logo');
    }
}

if (!function_exists('dht_landing_v6_img_mobile')) {
    function dht_landing_v6_img_mobile($source, $alt, $class = '', $loading = 'lazy', $fetchpriority = '') {
        if (empty($source['url'])) {
            return '';
        }

        $url = (string) $source['url'];
        $logo = dht_landing_v6_logo_url_mobile('medium_large');
        $html = '<img src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '"';

        if ($class !== '') {
            $html .= ' class="' . esc_attr($class) . '"';
        }

        $html .= ' loading="' . esc_attr($loading) . '" decoding="async"';

        if ($fetchpriority !== '') {
            $html .= ' fetchpriority="' . esc_attr($fetchpriority) . '"';
        }

        if ($logo && $url !== $logo) {
            $html .= ' onerror="this.onerror=null;this.src=' . esc_attr(wp_json_encode($logo)) . ';"';
        }

        $html .= '>';
        return $html;
    }
}

/* ==========================================================
 * Landing relations.
 * ========================================================== */

$related_cat_ids = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT DISTINCT r.target_id
         FROM {$relations_table} r
         INNER JOIN {$wpdb->term_taxonomy} tt
            ON tt.term_id = r.target_id
           AND tt.taxonomy = 'product_cat'
         WHERE r.source_type = 'landing'
           AND r.source_id = %d
           AND r.target_type = 'product_cat'
           AND r.relation_type = 'landing_to_category'
         ORDER BY r.id ASC",
        $post_id
    )
);

$related_cat_ids = array_values(array_unique(array_filter(array_map('absint', (array) $related_cat_ids))));
$terms = array();

foreach ($related_cat_ids as $term_id) {
    $term = get_term($term_id, 'product_cat');
    if ($term && !is_wp_error($term)) {
        $terms[] = $term;
    }
}

/* Legacy fallback only when the landing has not been migrated. */
if (!$terms) {
    $legacy_related_cat = get_post_meta($post_id, 'landing_product_cat', true);
    if ($legacy_related_cat) {
        $legacy_term = get_term_by('slug', $legacy_related_cat, 'product_cat');
        if ($legacy_term && !is_wp_error($legacy_term)) {
            $terms[] = $legacy_term;
            $related_cat_ids[] = (int) $legacy_term->term_id;
        }
    }
}

/* Products grouped by directly related category, no child-category expansion. */
$product_groups = array();
$product_ids_for_schema = array();
$seen_products = array();
$global_product_limit = 12;

if (function_exists('wc_get_product')) {
    foreach ($terms as $term) {
        if (count($product_ids_for_schema) >= $global_product_limit) {
            break;
        }

        $remaining = $global_product_limit - count($product_ids_for_schema);
        $limit = min(4, $remaining);

        $query = new WP_Query(array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $limit * 2,
            'fields' => 'ids',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'meta_key' => 'total_sales',
            'orderby' => array('meta_value_num' => 'DESC', 'date' => 'DESC'),
            'tax_query' => array(
                array(
                    'taxonomy' => 'product_cat',
                    'field' => 'term_id',
                    'terms' => array((int) $term->term_id),
                    'include_children' => false,
                ),
            ),
        ));

        $products = array();
        foreach ((array) $query->posts as $product_id) {
            $product_id = absint($product_id);
            if ($product_id < 1 || isset($seen_products[$product_id])) {
                continue;
            }

            $product = wc_get_product($product_id);
            if (!$product || !$product->is_visible()) {
                continue;
            }

            $products[] = $product;
            $seen_products[$product_id] = true;
            $product_ids_for_schema[] = $product_id;

            if (count($products) >= $limit || count($product_ids_for_schema) >= $global_product_limit) {
                break;
            }
        }

        if ($products) {
            $product_groups[(int) $term->term_id] = array(
                'term' => $term,
                'products' => $products,
            );
        }
    }
}

/* Related secondary hubs that own one of the landing product categories. */
$related_hub_ids = array();
if ($related_cat_ids) {
    $placeholders = implode(',', array_fill(0, count($related_cat_ids), '%d'));
    $sql = "SELECT DISTINCT r.source_id
            FROM {$relations_table} r
            INNER JOIN {$nodes_table} n
               ON n.object_type = 'page'
              AND n.object_id = r.source_id
              AND n.seo_role = 'hub_secondary'
              AND n.status = 1
            INNER JOIN {$wpdb->posts} p
               ON p.ID = r.source_id
              AND p.post_type = 'page'
              AND p.post_status = 'publish'
            WHERE r.source_type = 'hub_secondary'
              AND r.target_type = 'product_cat'
              AND r.relation_type = 'hub_secondary_to_category'
              AND r.target_id IN ({$placeholders})
            ORDER BY p.post_title ASC";

    $related_hub_ids = $wpdb->get_col($wpdb->prepare($sql, $related_cat_ids));
    $related_hub_ids = array_values(array_unique(array_filter(array_map('absint', (array) $related_hub_ids))));
}

/* JSON-LD uses the same real product selection shown on screen. */
$item_list = array();
foreach ($product_ids_for_schema as $product_id) {
    $item_list[] = array(
        '@type' => 'ListItem',
        'position' => count($item_list) + 1,
        'name' => get_the_title($product_id),
        'url' => get_permalink($product_id),
    );
}

$json = array(
    '@context' => 'https://schema.org',
    '@graph' => array(
        array(
            '@type' => 'CollectionPage',
            '@id' => get_permalink($post_id) . '#collection',
            'url' => get_permalink($post_id),
            'name' => get_the_title($post_id),
            'description' => get_the_excerpt($post_id),
            'inLanguage' => get_bloginfo('language'),
        ),
        array(
            '@type' => 'BreadcrumbList',
            'itemListElement' => array(
                array('@type' => 'ListItem', 'position' => 1, 'name' => 'Inicio', 'item' => home_url('/')),
                array('@type' => 'ListItem', 'position' => 2, 'name' => get_the_title($post_id), 'item' => get_permalink($post_id)),
            ),
        ),
        array(
            '@type' => 'ItemList',
            '@id' => get_permalink($post_id) . '#products',
            'name' => 'Productos relacionados',
            'numberOfItems' => count($item_list),
            'itemListElement' => $item_list,
        ),
    ),
);

$main_image = dht_landing_v6_main_image_mobile($post_id, $related_cat_ids, 'large');
?>
<script type="application/ld+json">
<?php echo wp_json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?>
</script>

<style id="dht-landing-v6-css">
.dht-landing-v6{background:#fff}
.dht-landing-v6 .hub-hero{padding:24px 0!important;min-height:0!important}
.dht-landing-v6 .hub-hero .hub-title{margin:0 0 8px!important}
.dht-landing-v6 .hub-hero .hub-excerpt{margin:0!important}
.dht-landing-v6 .dht-v6-main{padding:22px 0 24px}
.dht-landing-v6 .dht-v6-layout{display:grid;grid-template-columns:minmax(0,2fr) minmax(280px,1fr);gap:42px;align-items:start}
.dht-landing-v6 .dht-v6-content{min-width:0}
.dht-landing-v6 .dht-v6-side{min-width:0;display:flex;flex-direction:column;gap:16px}
.dht-landing-v6 .dht-v6-featured{margin:0;border:1px solid #e3e6e8;border-radius:16px;overflow:hidden;background:#fff;box-shadow:0 8px 24px rgba(0,0,0,.06)}
.dht-landing-v6 .dht-v6-featured img{display:block!important;width:100%!important;height:auto!important;max-height:440px!important;object-fit:contain!important;background:#fff}
.dht-landing-v6 .dht-v6-benefits{display:grid;grid-template-columns:1fr;gap:10px}
.dht-landing-v6 .dht-v6-benefit{padding:14px 16px;border:1px solid #e3e6e8;border-radius:12px;background:#f8fafc}
.dht-landing-v6 .dht-v6-benefit strong{display:block;margin-bottom:4px;font-size:15px;color:#1d2327}
.dht-landing-v6 .dht-v6-benefit span{display:block;font-size:13px;line-height:1.45;color:#50575e}
.dht-landing-v6 .dht-v6-section{padding:38px 0;border-top:1px solid #edf0f2}
.dht-landing-v6 .dht-v6-section h2{margin:0 0 8px;font-size:30px;line-height:1.2}
.dht-landing-v6 .dht-v6-section-intro{margin:0 0 24px;color:#646970}
.dht-landing-v6 .dht-v6-group{margin:0 0 30px}
.dht-landing-v6 .dht-v6-group:last-child{margin-bottom:0}
.dht-landing-v6 .dht-v6-group-title{font-size:20px;margin:0 0 14px}
.dht-landing-v6 .dht-v6-product-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px}
.dht-landing-v6 .dht-v6-product{overflow:hidden;border:1px solid #dcdcde;border-radius:14px;background:#fff;box-shadow:0 5px 18px rgba(0,0,0,.04)}
.dht-landing-v6 .dht-v6-product-media{display:block;aspect-ratio:1/1;background:#f6f7f7;overflow:hidden}
.dht-landing-v6 .dht-v6-product-media img{display:block;width:100%;height:100%;object-fit:contain;padding:10px;box-sizing:border-box;background:#fff}
.dht-landing-v6 .dht-v6-product-body{padding:13px}
.dht-landing-v6 .dht-v6-product-title{font-size:15px;line-height:1.35;margin:0 0 8px}
.dht-landing-v6 .dht-v6-product-title a{color:#1d2327;text-decoration:none}
.dht-landing-v6 .dht-v6-price{font-weight:700;margin:7px 0 10px}
.dht-landing-v6 .dht-v6-link{font-size:13px;font-weight:700;text-decoration:none}
.dht-landing-v6 .dht-v6-hub-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}
.dht-landing-v6 .dht-v6-hub{display:block;overflow:hidden;border:1px solid #dcdcde;border-radius:14px;background:#fff;color:inherit;text-decoration:none;box-shadow:0 5px 18px rgba(0,0,0,.04)}
.dht-landing-v6 .dht-v6-hub-media{display:block;aspect-ratio:16/9;background:#f6f7f7;overflow:hidden}
.dht-landing-v6 .dht-v6-hub-media img{display:block;width:100%;height:100%;object-fit:contain;padding:10px;box-sizing:border-box;background:#fff}
.dht-landing-v6 .dht-v6-hub-body{display:block;padding:14px}
.dht-landing-v6 .dht-v6-hub-body strong{display:block;font-size:17px;line-height:1.35;margin-bottom:7px}
.dht-landing-v6 .dht-v6-hub-body span{font-size:13px;color:#646970;line-height:1.5}
@media (max-width:782px){
  .dht-landing-v6 .hub-hero{padding:16px 0!important}
  .dht-landing-v6 .hub-hero .hub-title{margin-bottom:6px!important}
  .dht-landing-v6 .dht-v6-main{padding:12px 0 18px}
  .dht-landing-v6 .dht-v6-layout{display:flex;flex-direction:column;gap:22px}
  .dht-landing-v6 .dht-v6-side{order:1;width:100%}
  .dht-landing-v6 .dht-v6-content{order:2;width:100%}
  .dht-landing-v6 .dht-v6-featured img{max-height:420px!important}
  .dht-landing-v6 .dht-v6-section{padding:28px 0}
  .dht-landing-v6 .dht-v6-section h2{font-size:24px}
  .dht-landing-v6 .dht-v6-product-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
  .dht-landing-v6 .dht-v6-hub-grid{grid-template-columns:1fr;gap:12px}
  .dht-landing-v6 .dht-v6-product-body{padding:10px}
  .dht-landing-v6 .dht-v6-product-title{font-size:14px}
}
</style>

<main class="landing-page dht-mobile-template dht-landing-v6">
<!-- DHT LANDING RELATIONS V6.1 COMPACT - 2026-08-26 -->

<?php while (have_posts()) : the_post(); ?>

<header class="hub-hero">
    <div class="hub-container">
        <h1 class="hub-title"><?php the_title(); ?></h1>
        <p class="hub-excerpt">
            <?php
            $excerpt = get_the_excerpt();
            echo $excerpt
                ? esc_html($excerpt)
                : esc_html('Soluciones profesionales para ' . get_the_title() . '.');
            ?>
        </p>
    </div>
</header>

<section class="dht-v6-main">
    <div class="hub-container dht-v6-layout">
        <div class="dht-v6-content hub-content">
            <?php the_content(); ?>
        </div>

        <aside class="dht-v6-side" aria-label="Imagen y ventajas de la solucion">
            <?php if (!empty($main_image['url'])) : ?>
                <figure class="dht-v6-featured">
                    <?php echo dht_landing_v6_img_mobile($main_image, get_the_title(), 'dht-v6-featured-image', 'eager', 'high'); ?>
                </figure>
            <?php endif; ?>

            <div class="dht-v6-benefits">
                <div class="dht-v6-benefit">
                    <strong>Calidad profesional</strong>
                    <span>Soluciones seleccionadas para un uso fiable y exigente.</span>
                </div>
                <div class="dht-v6-benefit">
                    <strong>Marcas especializadas</strong>
                    <span>Catalogo de fabricantes y proveedores especializados.</span>
                </div>
                <div class="dht-v6-benefit">
                    <strong>Compra con confianza</strong>
                    <span>Compara alternativas y revisa la opcion adecuada para tu necesidad.</span>
                </div>
            </div>
        </aside>
    </div>
</section>

<?php if ($product_groups) : ?>
<section id="productos" class="dht-v6-section dht-v6-products">
    <div class="hub-container">
        <h2>Productos relacionados</h2>
        <p class="dht-v6-section-intro">Productos publicados pertenecientes a las categorias asociadas directamente a esta landing.</p>

        <?php foreach ($terms as $term) :
            $term_id = (int) $term->term_id;
            if (empty($product_groups[$term_id]['products'])) {
                continue;
            }
        ?>
            <div class="dht-v6-group">
                <h3 class="dht-v6-group-title"><?php echo esc_html($term->name); ?></h3>
                <div class="dht-v6-product-grid">
                    <?php foreach ($product_groups[$term_id]['products'] as $product) :
                        $product_id = (int) $product->get_id();
                        $product_url = get_permalink($product_id);
                        $image = dht_landing_v6_product_image_mobile($product_id, 'woocommerce_thumbnail', true);
                    ?>
                        <article class="dht-v6-product">
                            <a class="dht-v6-product-media" href="<?php echo esc_url($product_url); ?>">
                                <?php echo dht_landing_v6_img_mobile($image, $product->get_name(), 'dht-v6-product-image'); ?>
                            </a>
                            <div class="dht-v6-product-body">
                                <h3 class="dht-v6-product-title"><a href="<?php echo esc_url($product_url); ?>"><?php echo esc_html($product->get_name()); ?></a></h3>
                                <?php if ($product->get_price_html()) : ?>
                                    <div class="dht-v6-price"><?php echo wp_kses_post($product->get_price_html()); ?></div>
                                <?php endif; ?>
                                <a class="dht-v6-link" href="<?php echo esc_url($product_url); ?>">Ver producto &rarr;</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($related_hub_ids) : ?>
<section id="hubs-relacionados" class="dht-v6-section dht-v6-hubs">
    <div class="hub-container">
        <h2>Hubs relacionados con el tema</h2>
        <p class="dht-v6-section-intro">Guias de la misma rama SEO que agrupan las categorias relacionadas con esta solucion.</p>

        <div class="dht-v6-hub-grid">
            <?php foreach ($related_hub_ids as $hub_id) :
                $hub_url = get_permalink($hub_id);
                if (!$hub_url) {
                    continue;
                }
                $hub_image = dht_landing_v6_hub_image_mobile($hub_id, 'medium_large');
                $hub_excerpt = get_the_excerpt($hub_id);
            ?>
                <a class="dht-v6-hub" href="<?php echo esc_url($hub_url); ?>">
                    <span class="dht-v6-hub-media">
                        <?php echo dht_landing_v6_img_mobile($hub_image, get_the_title($hub_id), 'dht-v6-hub-image'); ?>
                    </span>
                    <span class="dht-v6-hub-body">
                        <strong><?php echo esc_html(get_the_title($hub_id)); ?></strong>
                        <?php if ($hub_excerpt) : ?>
                            <span><?php echo esc_html(wp_trim_words($hub_excerpt, 22)); ?></span>
                        <?php else : ?>
                            <span>Explora las categorias y soluciones de esta rama.</span>
                        <?php endif; ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="landing-cta">
    <div class="landing-container">
        <h2><?php the_title(); ?></h2>
        <p>Compare las soluciones relacionadas y encuentre la opcion que mejor se adapta a sus necesidades.</p>
        <?php if ($product_groups) : ?>
            <a class="landing-btn" href="#productos">Ver productos relacionados</a>
        <?php endif; ?>
    </div>
</section>

<?php endwhile; ?>
</main>

<?php dht_template_render_footer(); ?>
