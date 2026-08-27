<?php
/**
 * Plantilla de pagina DHT.
 *
 * Landings:
 * - Imagen principal: Media -> categoria/producto relacionado -> proveedor -> logo.
 * - Productos: solo product_cat asociadas mediante landing_to_category.
 * - Imagen de producto: Media -> indice Media -> seo_supplier_images -> logo.
 * - Productos: agrupados por las product_cat directas de landing_to_category.
 * - Categorias: solo las product_cat asociadas directamente a la landing.
 * - Sin productos aleatorios, shortcodes de recomendacion ni fallbacks comerciales ajenos.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

dht_template_render_header();

/* ==========================================================
 * RESOLUCION VISUAL LOCAL -> PROVEEDOR -> LOGO
 * Solo afecta a la presentacion; no escribe _thumbnail_id.
 * ========================================================== */

if (!function_exists('dht_page_logo_url')) {
    function dht_page_logo_url($size = 'medium_large') {
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

        /* Logo actual del sitio. Se usa solo como fallback visual. */
        $known_logo_id = 36707;
        if (wp_attachment_is_image($known_logo_id)) {
            $url = wp_get_attachment_image_url($known_logo_id, $size);
            if ($url) {
                return $cache[$key] = (string) $url;
            }
        }

        $upload_logo = content_url('/uploads/2026/01/Logo2.webp');
        if ($upload_logo) {
            return $cache[$key] = (string) $upload_logo;
        }

        if (function_exists('wc_placeholder_img_src')) {
            return $cache[$key] = (string) wc_placeholder_img_src('woocommerce_thumbnail');
        }

        return $cache[$key] = '';
    }
}

if (!function_exists('dht_page_local_attachment_source')) {
    function dht_page_local_attachment_source($attachment_id, $size = 'medium_large') {
        $attachment_id = absint($attachment_id);

        if ($attachment_id < 1 || 'attachment' !== get_post_type($attachment_id)) {
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

if (!function_exists('dht_page_product_image_source')) {
    /**
     * Imagen de producto para tarjetas de landing.
     * Prioridad estricta: Media -> indice Media -> proveedor -> logo.
     */
    function dht_page_product_image_source($product_id, $size = 'woocommerce_thumbnail', $allow_logo = true) {
        global $wpdb;

        static $cache = array();
        static $media_tables_available = null;
        static $supplier_table_available = null;

        $product_id = absint($product_id);
        $cache_key = $product_id . '|' . $size . '|' . ($allow_logo ? '1' : '0');

        if ($product_id < 1) {
            return $allow_logo
                ? array('url' => dht_page_logo_url($size), 'attachment_id' => 0, 'source' => 'logo')
                : null;
        }

        if (array_key_exists($cache_key, $cache)) {
            return $cache[$cache_key];
        }

        /* 1) Imagen destacada local de WooCommerce. */
        $local = dht_page_local_attachment_source(get_post_thumbnail_id($product_id), $size);
        if ($local) {
            return $cache[$cache_key] = $local;
        }

        /* 2) Cualquier attachment local valido indexado para el producto. */
        $usage_table = function_exists('seo_images_table_usages')
            ? seo_images_table_usages()
            : $wpdb->prefix . 'seo_media_usos';
        $images_table = function_exists('seo_images_table_images')
            ? seo_images_table_images()
            : $wpdb->prefix . 'seo_media_imagenes';

        if (null === $media_tables_available) {
            $usage_exists = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($usage_table))
            );
            $images_exists = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($images_table))
            );
            $media_tables_available = ($usage_exists === $usage_table && $images_exists === $images_table);
        }

        if ($media_tables_available) {
            $attachment_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT u.attachment_id
                     FROM {$usage_table} u
                     WHERE u.object_id = %d
                       AND u.object_type = 'product'
                       AND u.attachment_id IS NOT NULL
                       AND u.attachment_id > 0
                     ORDER BY
                       CASE u.tipo_uso
                         WHEN 'featured' THEN 1
                         WHEN 'gallery' THEN 2
                         WHEN 'content' THEN 3
                         ELSE 9
                       END ASC,
                       u.fecha DESC
                     LIMIT 12",
                    $product_id
                )
            );

            foreach ((array) $attachment_ids as $attachment_id) {
                $local = dht_page_local_attachment_source($attachment_id, $size);
                if ($local) {
                    return $cache[$cache_key] = $local;
                }
            }
        }

        /* 3) Imagen externa activa del proveedor. */
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

        /* Compatibilidad con el helper de sincronizacion V2. */
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
            $logo = dht_page_logo_url($size);
            if ($logo) {
                return $cache[$cache_key] = array(
                    'url'           => $logo,
                    'attachment_id' => 0,
                    'source'        => 'logo',
                );
            }
        }

        return $cache[$cache_key] = null;
    }
}

if (!function_exists('dht_page_term_image_source')) {
    /** Categoria: thumbnail de Media -> producto representativo -> proveedor -> logo. */
    function dht_page_term_image_source($term_id, $size = 'medium_large', $allow_logo = true) {
        $term_id = absint($term_id);
        if ($term_id < 1) {
            return $allow_logo
                ? array('url' => dht_page_logo_url($size), 'attachment_id' => 0, 'source' => 'logo')
                : null;
        }

        $local = dht_page_local_attachment_source(get_term_meta($term_id, 'thumbnail_id', true), $size);
        if ($local) {
            return $local;
        }

        $product_ids = get_posts(array(
            'post_type'           => 'product',
            'post_status'         => 'publish',
            'posts_per_page'      => 12,
            'fields'              => 'ids',
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
            'orderby'             => 'date',
            'order'               => 'DESC',
            'tax_query'           => array(
                array(
                    'taxonomy'         => 'product_cat',
                    'field'            => 'term_id',
                    'terms'            => array($term_id),
                    'include_children' => false,
                ),
            ),
        ));

        foreach ((array) $product_ids as $product_id) {
            $source = dht_page_product_image_source($product_id, $size, false);
            if (!empty($source['url'])) {
                return $source;
            }
        }

        if ($allow_logo) {
            $logo = dht_page_logo_url($size);
            if ($logo) {
                return array('url' => $logo, 'attachment_id' => 0, 'source' => 'logo');
            }
        }

        return null;
    }
}

if (!function_exists('dht_page_main_image_source')) {
    function dht_page_main_image_source($page_id, $related_term_ids, $size = 'large') {
        $local = dht_page_local_attachment_source(get_post_thumbnail_id($page_id), $size);
        if ($local) {
            return $local;
        }

        foreach (array_map('absint', (array) $related_term_ids) as $term_id) {
            $source = dht_page_term_image_source($term_id, $size, false);
            if (!empty($source['url'])) {
                return $source;
            }
        }

        $logo = dht_page_logo_url($size);
        return $logo
            ? array('url' => $logo, 'attachment_id' => 0, 'source' => 'logo')
            : null;
    }
}

if (!function_exists('dht_page_image_tag')) {
    function dht_page_image_tag($source, $alt, $class = '', $loading = 'lazy', $fetchpriority = '') {
        if (empty($source['url'])) {
            return '';
        }

        $url = (string) $source['url'];
        $logo_url = dht_page_logo_url('medium_large');
        $onerror = '';

        if ($logo_url && $url !== $logo_url) {
            $onerror = 'this.onerror=null;this.src=' . wp_json_encode($logo_url) . ';';
        }

        $html = '<img src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '"';
        if ($class !== '') {
            $html .= ' class="' . esc_attr($class) . '"';
        }
        $html .= ' loading="' . esc_attr($loading) . '" decoding="async"';
        if ($fetchpriority !== '') {
            $html .= ' fetchpriority="' . esc_attr($fetchpriority) . '"';
        }
        if ($onerror !== '') {
            $html .= ' onerror="' . esc_attr($onerror) . '"';
        }
        $html .= '>';

        return $html;
    }
}

if (!function_exists('dht_page_related_products_by_category')) {
    /**
     * Devuelve productos publicados de las categorias DIRECTAMENTE asociadas.
     * Se consulta cada product_cat por separado y sin incluir hijas, para evitar
     * que una categoria amplia arrastre productos de ramas no pertinentes.
     */
    function dht_page_related_products_by_category($terms, $per_category = 4, $global_limit = 12) {
        $per_category = max(1, absint($per_category));
        $global_limit = max(1, absint($global_limit));

        if (empty($terms) || !function_exists('wc_get_product')) {
            return array();
        }

        $groups = array();
        $seen = array();
        $total = 0;

        foreach ((array) $terms as $term) {
            if ($total >= $global_limit || !$term || is_wp_error($term)) {
                break;
            }

            $term_id = absint($term->term_id ?? 0);
            if ($term_id < 1) {
                continue;
            }

            $remaining = $global_limit - $total;
            $limit = min($per_category, $remaining);

            $query = new WP_Query(array(
                'post_type'           => 'product',
                'post_status'         => 'publish',
                'posts_per_page'      => $limit * 2,
                'fields'              => 'ids',
                'no_found_rows'       => true,
                'ignore_sticky_posts' => true,
                'meta_key'            => 'total_sales',
                'orderby'             => array(
                    'meta_value_num' => 'DESC',
                    'date'           => 'DESC',
                ),
                'tax_query'           => array(
                    array(
                        'taxonomy'         => 'product_cat',
                        'field'            => 'term_id',
                        'terms'            => array($term_id),
                        'include_children' => false,
                    ),
                ),
            ));

            $products = array();
            foreach ((array) $query->posts as $product_id) {
                $product_id = absint($product_id);
                if ($product_id < 1 || isset($seen[$product_id])) {
                    continue;
                }

                $product = wc_get_product($product_id);
                if (!$product || !$product->is_visible()) {
                    continue;
                }

                $products[] = $product;
                $seen[$product_id] = true;
                $total++;

                if (count($products) >= $limit || $total >= $global_limit) {
                    break;
                }
            }

            if (!empty($products)) {
                $groups[$term_id] = array(
                    'term'     => $term,
                    'products' => $products,
                );
            }
        }

        return $groups;
    }
}

/* Estilos autocontenidos para que la imagen principal no desaparezca en movil
 * aunque styles-template.css sea una version anterior. */
?>
<style id="dht-page-relations-v4-css">
.dht-page-featured-section{display:block!important;padding:24px 0 8px;background:#fff}
.dht-page-featured-card{display:block!important;max-width:1040px;margin:0 auto;overflow:hidden;border:1px solid #e5e7eb;border-radius:18px;background:#fff;box-shadow:0 8px 24px rgba(0,0,0,.05)}
.dht-page-featured-card img{display:block!important;width:100%!important;height:auto!important;max-height:620px!important;object-fit:contain!important;background:#fff}
.dht-page-product-category-group{margin:0 0 30px}
.dht-page-product-category-title{margin:0 0 14px;font-size:20px;line-height:1.3}
.dht-page-product-grid{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:18px!important}
.dht-page-product-card,.dht-page-related-cat-card{overflow:hidden;border:1px solid #dcdcde;border-radius:14px;background:#fff;text-decoration:none;color:inherit;box-shadow:0 5px 18px rgba(0,0,0,.04)}
.dht-page-product-media,.dht-page-related-cat-media{display:block;aspect-ratio:1/1;background:#f6f7f7;overflow:hidden}
.dht-page-product-media img,.dht-page-related-cat-media img{display:block;width:100%;height:100%;object-fit:contain;padding:10px;box-sizing:border-box;background:#fff}
.dht-page-product-body,.dht-page-related-cat-body{padding:14px}
.dht-page-product-card h3,.dht-page-related-cat-card h3{margin:0 0 8px;font-size:16px;line-height:1.35}
.dht-page-product-card h3 a{color:inherit;text-decoration:none}
.dht-page-product-price{font-weight:700;margin-top:8px}
.dht-page-related-cat-grid{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:18px!important}
.dht-page-related-cat-count{display:block;color:#646970;font-size:12px;margin-bottom:6px}
@media (max-width:782px){
  .dht-page-featured-section{padding:16px 0 4px!important}
  .dht-page-featured-card{width:100%!important;border-radius:14px!important}
  .dht-page-featured-card img{width:100%!important;height:auto!important;max-height:440px!important;object-fit:contain!important}
  .dht-page-product-grid,.dht-page-related-cat-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:12px!important}
  .dht-page-product-body,.dht-page-related-cat-body{padding:10px!important}
  .dht-page-product-card h3,.dht-page-related-cat-card h3{font-size:14px!important}
  .dht-page-product-category-title{font-size:18px!important}
}
</style>
<?php

while (have_posts()) :
    the_post();

    global $wpdb;

    $page_id = (int) get_the_ID();
    $nodes_table = $wpdb->prefix . 'seo_nodes';
    $relations_table = $wpdb->prefix . 'seo_relations';

    $seo_role = (string) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT seo_role
             FROM {$nodes_table}
             WHERE object_type = 'page'
               AND object_id = %d
               AND status = 1
               AND seo_role IN (
                   'cluster',
                   'hub_primary',
                   'hub_secondary',
                   'landing',
                   'landing_comparative',
                   'corporate_page'
               )
             ORDER BY updated_at DESC, id DESC
             LIMIT 1",
            $page_id
        )
    );

    $is_landing = ($seo_role === 'landing');
    $related_product_cats = array();
    $related_term_ids = array();

    if ($is_landing) {
        $related_term_ids = $wpdb->get_col(
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
                 ORDER BY r.target_id ASC",
                $page_id
            )
        );

        $related_term_ids = array_values(array_unique(array_filter(array_map('absint', (array) $related_term_ids))));

        foreach ($related_term_ids as $term_id) {
            $term = get_term($term_id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $related_product_cats[] = $term;
            }
        }

        usort($related_product_cats, static function ($a, $b) {
            return strcasecmp((string) $a->name, (string) $b->name);
        });
    }

    $related_products_by_cat = $is_landing
        ? dht_page_related_products_by_category($related_product_cats, 4, 12)
        : array();

    $main_image = null;
    $local_main = dht_page_local_attachment_source(get_post_thumbnail_id($page_id), 'large');
    if ($local_main) {
        $main_image = $local_main;
    } elseif ($is_landing) {
        $main_image = dht_page_main_image_source($page_id, $related_term_ids, 'large');
    }

    $eyebrow = 'Pagina';
    if ($seo_role === 'landing') {
        $eyebrow = 'Solucion profesional';
    } elseif ($seo_role === 'corporate_page') {
        $eyebrow = 'Informacion corporativa';
    } elseif ($seo_role === 'cluster') {
        $eyebrow = 'Area de soluciones';
    } elseif ($seo_role === 'hub_primary' || $seo_role === 'hub_secondary') {
        $eyebrow = 'Guia de soluciones';
    }
    ?>

    <article <?php post_class('dht-post dht-page dht-page--conversion dht-desktop-template'); ?>>
        <header class="dht-post-hero">
            <div class="dht-container dht-post-hero-grid">
                <div class="dht-post-hero-copy">
                    <nav class="dht-post-breadcrumbs" aria-label="Migas de pan">
                        <a href="<?php echo esc_url(home_url('/')); ?>">Inicio</a>
                        <span aria-hidden="true">/</span>
                        <span><?php echo esc_html(get_the_title()); ?></span>
                    </nav>

                    <span class="dht-kicker"><?php echo esc_html($eyebrow); ?></span>
                    <h1 class="dht-post-title"><?php the_title(); ?></h1>
                    
                    <?php if (has_excerpt()) : ?>
                        <p class="dht-post-lead"><?php echo esc_html(get_the_excerpt()); ?></p>
                    <?php endif; ?>

                    <?php if ($is_landing && !empty($related_product_cats)) : ?>
                        <div class="dht-post-hero-actions">
                            <a class="dht-btn dht-btn-primary" href="#dht-related-products">Ver productos asociados</a>
                            <a class="dht-post-text-link" href="#dht-related-categories">Ver categorias asociadas ↓</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <?php if (!empty($main_image['url'])) : ?>
            <section class="dht-page-featured-section" aria-label="Imagen destacada de la pagina">
                <div class="dht-container">
                    <figure class="dht-page-featured-card">
                        <?php echo dht_page_image_tag($main_image, get_the_title(), 'dht-page-main-image', 'eager', 'high'); ?>
                    </figure>
                </div>
            </section>
        <?php endif; ?>

        <div id="dht-page-content" class="dht-container dht-post-content-wrap dht-page-content-wrap">
            <main class="dht-post-content dht-prose dht-page-content">
                <?php the_content(); ?>
            </main>
        </div>

        <?php if ($is_landing && !empty($related_product_cats)) : ?>
            <section id="dht-related-products" class="dht-post-section dht-reco dht-page-products">
                <div class="dht-container">
                    <div class="dht-post-section-heading">
                        <div>
                            <span class="dht-post-eyebrow">Catalogo relacionado</span>
                            <h2 class="dht-post-section-title">Productos asociados</h2>
                            <p class="dht-post-section-subtitle">Productos publicados que pertenecen directamente a las categorias asociadas a esta pagina.</p>
                        </div>
                    </div>

                    <?php if (!empty($related_products_by_cat)) : ?>
                        <?php foreach ($related_product_cats as $product_cat) :
                            $term_id = absint($product_cat->term_id);
                            if (empty($related_products_by_cat[$term_id]['products'])) {
                                continue;
                            }
                            ?>
                            <div class="dht-page-product-category-group">
                                <h3 class="dht-page-product-category-title"><?php echo esc_html($product_cat->name); ?></h3>
                                <div class="dht-page-product-grid">
                                    <?php foreach ($related_products_by_cat[$term_id]['products'] as $product) :
                                        $product_id = (int) $product->get_id();
                                        $product_url = get_permalink($product_id);
                                        $image_source = dht_page_product_image_source($product_id, 'woocommerce_thumbnail', true);
                                        ?>
                                        <article class="dht-page-product-card">
                                            <a class="dht-page-product-media" href="<?php echo esc_url($product_url); ?>" aria-label="<?php echo esc_attr($product->get_name()); ?>">
                                                <?php echo dht_page_image_tag($image_source, $product->get_name(), 'dht-page-product-image'); ?>
                                            </a>
                                            <div class="dht-page-product-body">
                                                <h3><a href="<?php echo esc_url($product_url); ?>"><?php echo esc_html($product->get_name()); ?></a></h3>
                                                <?php if ($product->get_price_html()) : ?>
                                                    <div class="dht-page-product-price"><?php echo wp_kses_post($product->get_price_html()); ?></div>
                                                <?php endif; ?>
                                                <a class="dht-cat-action" href="<?php echo esc_url($product_url); ?>">Ver producto →</a>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <p>No hay productos publicados asignados directamente a estas categorias.</p>
                    <?php endif; ?>
                </div>
            </section>

            <section id="dht-related-categories" class="dht-post-section dht-page-associated-categories">
                <div class="dht-container">
                    <div class="dht-post-section-heading">
                        <div>
                            <span class="dht-post-eyebrow">Navegacion comercial</span>
                            <h2 class="dht-post-section-title">Categorias asociadas</h2>
                            <p class="dht-post-section-subtitle">Categorias de producto vinculadas directamente a esta landing.</p>
                        </div>
                    </div>

                    <div class="dht-page-related-cat-grid">
                        <?php foreach ($related_product_cats as $product_cat) :
                            $term_link = get_term_link($product_cat, 'product_cat');
                            if (is_wp_error($term_link)) {
                                continue;
                            }
                            $cat_image = dht_page_term_image_source($product_cat->term_id, 'medium_large', true);
                            ?>
                            <a class="dht-page-related-cat-card" href="<?php echo esc_url($term_link); ?>">
                                <span class="dht-page-related-cat-media">
                                    <?php echo dht_page_image_tag($cat_image, $product_cat->name, 'dht-page-category-image'); ?>
                                </span>
                                <span class="dht-page-related-cat-body">
                                    <span class="dht-page-related-cat-count"><?php echo esc_html(number_format_i18n((int) $product_cat->count)); ?> productos</span>
                                    <h3><?php echo esc_html($product_cat->name); ?></h3>
                                    <span class="dht-cat-action">Ver categoria →</span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

        <?php elseif ($is_landing) : ?>
            <!-- Landing sin landing_to_category: no se inventan productos ni categorias. -->
        <?php endif; ?>

        <section class="dht-post-cta-bottom">
            <div class="dht-container">
                <div class="dht-cta-box dht-post-final-cta">
                    <div>
                        <span class="dht-badge">Asesoramiento</span>
                        <h2>¿Necesitas ayuda para elegir?</h2>
                        <p>Cuéntanos el trabajo, aplicacion o equipo que utilizas y te ayudamos a localizar una opcion adecuada.</p>
                    </div>

                    <div class="dht-cta-actions">
                        <a class="dht-post-cta-btn btn-primary" href="<?php echo esc_url(dht_template_contact_url()); ?>">Pedir asesoramiento</a>
                        <?php if ($is_landing) : ?>
                            <a class="dht-post-cta-secondary" href="<?php echo esc_url(dht_template_shop_url()); ?>">Explorar catalogo</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
    </article>

<?php endwhile; ?>

<?php dht_template_render_footer(); ?>