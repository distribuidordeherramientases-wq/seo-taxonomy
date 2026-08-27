<?php
/**
 * Front page comercial / marketplace - ESCRITORIO.
 * Plantilla fisica independiente para este entorno.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

dht_template_render_header();

global $wpdb;

/* ==========================================================
   IMAGENES DE PRODUCTO
   Orden estricto:
   1) Media / biblioteca local
   2) Tabla de imagenes de proveedor (probando una a una)
   3) Logo del sitio

   Las URLs externas se validan antes de publicarlas. El resultado
   se cachea para no penalizar cada carga de portada.
========================================================== */
if (!function_exists('dht_shop_template_image_candidate_is_loadable')) {
    function dht_shop_template_image_candidate_is_loadable($candidate) {
        $url = !empty($candidate['url']) ? esc_url_raw($candidate['url']) : '';
        if (!$url) {
            return false;
        }

        static $runtime_cache = array();
        if (array_key_exists($url, $runtime_cache)) {
            return $runtime_cache[$url];
        }

        /* Si la URL pertenece a uploads, comprobamos primero el fichero real. */
        $uploads = wp_get_upload_dir();
        $baseurl = isset($uploads['baseurl']) ? untrailingslashit($uploads['baseurl']) : '';
        $basedir = isset($uploads['basedir']) ? untrailingslashit($uploads['basedir']) : '';

        if ($baseurl && $basedir && 0 === strpos($url, $baseurl . '/')) {
            $relative = substr($url, strlen($baseurl));
            $path = wp_normalize_path($basedir . rawurldecode($relative));
            $ok = is_file($path) && is_readable($path) && filesize($path) > 0;
            $runtime_cache[$url] = $ok;
            return $ok;
        }

        /* Cache persistente: positivas 12 h, negativas 30 min. */
        $transient_key = 'dht_img_ok_' . md5($url);
        $cached = get_transient($transient_key);
        if ('1' === $cached || '0' === $cached) {
            $runtime_cache[$url] = ('1' === $cached);
            return $runtime_cache[$url];
        }

        $response = wp_safe_remote_get($url, array(
            'timeout'             => 3,
            'redirection'         => 3,
            'limit_response_size' => 4096,
            'headers'             => array(
                'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
            ),
            'user-agent'          => 'Mozilla/5.0 (compatible; DHT-Image-Check/1.0; +' . home_url('/') . ')',
        ));

        $ok = false;
        if (!is_wp_error($response)) {
            $code = (int) wp_remote_retrieve_response_code($response);
            $content_type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
            $body = (string) wp_remote_retrieve_body($response);

            $looks_like_image = 0 === strpos($content_type, 'image/');

            if (!$looks_like_image && $body !== '') {
                $prefix = substr($body, 0, 32);
                $looks_like_image = (
                    0 === strncmp($prefix, "\xFF\xD8\xFF", 3) ||
                    0 === strncmp($prefix, "\x89PNG\r\n\x1A\n", 8) ||
                    0 === strncmp($prefix, 'GIF87a', 6) ||
                    0 === strncmp($prefix, 'GIF89a', 6) ||
                    (strlen($prefix) >= 12 && 0 === strncmp($prefix, 'RIFF', 4) && 'WEBP' === substr($prefix, 8, 4)) ||
                    false !== stripos($prefix, '<svg') ||
                    (strlen($prefix) >= 12 && 'ftyp' === substr($prefix, 4, 4) && in_array(substr($prefix, 8, 4), array('avif', 'avis'), true))
                );
            }

            $ok = ($code >= 200 && $code < 300 && $looks_like_image);
        }

        set_transient($transient_key, $ok ? '1' : '0', $ok ? 12 * HOUR_IN_SECONDS : 30 * MINUTE_IN_SECONDS);
        $runtime_cache[$url] = $ok;
        return $ok;
    }
}

if (!function_exists('dht_shop_template_add_attachment_candidates')) {
    function dht_shop_template_add_attachment_candidates(&$candidates, $attachment_id, $size = 'woocommerce_thumbnail', $source = 'media') {
        $attachment_id = absint($attachment_id);
        if ($attachment_id < 1 || !wp_attachment_is_image($attachment_id)) {
            return;
        }

        $urls = array(
            wp_get_attachment_image_url($attachment_id, $size),
            wp_get_attachment_image_url($attachment_id, 'full'),
        );

        foreach ($urls as $url) {
            $url = $url ? esc_url_raw($url) : '';
            if ($url) {
                $candidates[] = array(
                    'attachment_id' => $attachment_id,
                    'url'           => $url,
                    'source'        => $source,
                );
            }
        }
    }
}

if (!function_exists('dht_shop_template_get_media_product_image_candidates')) {
    function dht_shop_template_get_media_product_image_candidates($product_id, $size = 'woocommerce_thumbnail') {
        global $wpdb;

        $product_id = absint($product_id);
        if ($product_id < 1) {
            return array();
        }

        $candidates = array();
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;

        /* WooCommerce: destacada y galeria tambien son Media. */
        if ($product && is_a($product, 'WC_Product')) {
            dht_shop_template_add_attachment_candidates($candidates, $product->get_image_id(), $size, 'media');
            foreach ((array) $product->get_gallery_image_ids() as $gallery_id) {
                dht_shop_template_add_attachment_candidates($candidates, $gallery_id, $size, 'media');
            }
        }

        /* Indice Media SEO. */
        $usage_table = function_exists('seo_images_table_usages')
            ? seo_images_table_usages()
            : $wpdb->prefix . 'seo_media_usos';
        $images_table = function_exists('seo_images_table_images')
            ? seo_images_table_images()
            : $wpdb->prefix . 'seo_media_imagenes';

        static $media_tables_available = array();
        $media_key = $usage_table . '|' . $images_table;
        if (!array_key_exists($media_key, $media_tables_available)) {
            $usage_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($usage_table)));
            $images_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($images_table)));
            $media_tables_available[$media_key] = ($usage_exists === $usage_table && $images_exists === $images_table);
        }

        if ($media_tables_available[$media_key]) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT u.attachment_id, u.tipo_uso, i.url_origen
                     FROM {$usage_table} u
                     LEFT JOIN {$images_table} i ON i.attachment_id = u.attachment_id
                     WHERE u.object_id = %d
                       AND u.object_type = 'product'
                     ORDER BY CASE u.tipo_uso
                         WHEN 'featured' THEN 1
                         WHEN 'gallery' THEN 2
                         WHEN 'content' THEN 3
                         ELSE 9 END ASC,
                         u.fecha DESC",
                    $product_id
                )
            );

            foreach ((array) $rows as $row) {
                $attachment_id = absint($row->attachment_id ?? 0);
                if ($attachment_id > 0) {
                    dht_shop_template_add_attachment_candidates($candidates, $attachment_id, $size, 'media');
                }

                $external_url = isset($row->url_origen) ? esc_url_raw($row->url_origen) : '';
                if ($external_url) {
                    $candidates[] = array(
                        'attachment_id' => 0,
                        'url'           => $external_url,
                        'source'        => 'media',
                    );
                }
            }
        }

        return $candidates;
    }
}

if (!function_exists('dht_shop_template_get_supplier_product_image_candidates')) {
    function dht_shop_template_get_supplier_product_image_candidates($product_id) {
        global $wpdb;

        $product_id = absint($product_id);
        if ($product_id < 1) {
            return array();
        }

        $candidates = array();
        $supplier_table = $wpdb->prefix . 'seo_supplier_images';
        static $supplier_table_exists = null;

        if (null === $supplier_table_exists) {
            $supplier_table_exists = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($supplier_table))
            ) === $supplier_table;
        }

        /* La tabla manda: recorremos TODAS las activas en su orden. */
        if ($supplier_table_exists) {
            $supplier_urls = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT image_url
                     FROM {$supplier_table}
                     WHERE product_id = %d
                       AND status = 'active'
                       AND image_url IS NOT NULL
                       AND TRIM(image_url) <> ''
                     ORDER BY is_primary DESC, position ASC, id ASC",
                    $product_id
                )
            );

            foreach ((array) $supplier_urls as $supplier_url) {
                $supplier_url = esc_url_raw($supplier_url);
                if ($supplier_url) {
                    $candidates[] = array(
                        'attachment_id' => 0,
                        'url'           => $supplier_url,
                        'source'        => 'supplier',
                    );
                }
            }
        }

        /* Compatibilidad con el indice/API si aporta una URL no presente en tabla. */
        if (function_exists('seo_supplier_v2_external_primary_url')) {
            $supplier_url = esc_url_raw(seo_supplier_v2_external_primary_url($product_id));
            if ($supplier_url) {
                $candidates[] = array(
                    'attachment_id' => 0,
                    'url'           => $supplier_url,
                    'source'        => 'supplier',
                );
            }
        }

        return $candidates;
    }
}

if (!function_exists('dht_shop_template_get_site_logo_candidate')) {
    function dht_shop_template_get_site_logo_candidate() {
        $logo_id = absint(get_theme_mod('custom_logo'));
        if ($logo_id > 0 && wp_attachment_is_image($logo_id)) {
            $logo_url = wp_get_attachment_image_url($logo_id, 'medium');
            if (!$logo_url) {
                $logo_url = wp_get_attachment_image_url($logo_id, 'full');
            }
            if ($logo_url) {
                return array(
                    'attachment_id' => $logo_id,
                    'url'           => esc_url_raw($logo_url),
                    'source'        => 'logo',
                );
            }
        }

        $site_icon = function_exists('get_site_icon_url') ? get_site_icon_url(512) : '';
        if ($site_icon) {
            return array(
                'attachment_id' => 0,
                'url'           => esc_url_raw($site_icon),
                'source'        => 'logo',
            );
        }

        return null;
    }
}

if (!function_exists('dht_shop_template_get_product_image_candidates')) {
    function dht_shop_template_get_product_image_candidates($product_id, $size = 'woocommerce_thumbnail') {
        $all = array_merge(
            dht_shop_template_get_media_product_image_candidates($product_id, $size),
            dht_shop_template_get_supplier_product_image_candidates($product_id)
        );

        $logo = dht_shop_template_get_site_logo_candidate();
        if ($logo) {
            $all[] = $logo;
        }

        /* Elimina URLs repetidas conservando el primer origen/prioridad. */
        $seen = array();
        $unique = array();
        foreach ($all as $candidate) {
            $url = !empty($candidate['url']) ? esc_url_raw($candidate['url']) : '';
            if (!$url || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $candidate['url'] = $url;
            $unique[] = $candidate;
        }

        return $unique;
    }
}

if (!function_exists('dht_shop_template_get_external_product_image')) {
    function dht_shop_template_get_external_product_image($product_id, $size = 'woocommerce_thumbnail') {
        static $best_cache = array();

        $cache_key = absint($product_id) . '|' . (string) $size;
        if (array_key_exists($cache_key, $best_cache)) {
            return $best_cache[$cache_key];
        }

        foreach (dht_shop_template_get_product_image_candidates($product_id, $size) as $candidate) {
            if (dht_shop_template_image_candidate_is_loadable($candidate)) {
                $best_cache[$cache_key] = $candidate;
                return $candidate;
            }
        }

        $best_cache[$cache_key] = null;
        return null;
    }
}

if (!function_exists('dht_shop_template_product_image_external_fallback')) {
    function dht_shop_template_product_image_external_fallback($image, $product, $size, $attr, $placeholder, $original = '') {
        if (!is_a($product, 'WC_Product')) {
            return $image;
        }

        $selected = dht_shop_template_get_external_product_image($product->get_id(), $size);
        if (empty($selected['url'])) {
            return $image;
        }

        $attr = is_array($attr) ? $attr : array();
        $is_logo = isset($selected['source']) && 'logo' === $selected['source'];
        $alt = $is_logo ? get_bloginfo('name') : (!empty($attr['alt']) ? $attr['alt'] : $product->get_name());

        $logo_fallback_url = '';
        if (!$is_logo) {
            $logo_candidate = dht_shop_template_get_site_logo_candidate();
            if ($logo_candidate && !empty($logo_candidate['url']) && dht_shop_template_image_candidate_is_loadable($logo_candidate)) {
                $logo_fallback_url = esc_url_raw($logo_candidate['url']);
            }
        }

        $onerror = $logo_fallback_url
            ? "this.onerror=null;this.src='" . esc_js($logo_fallback_url) . "';"
            : '';

        if (!empty($selected['attachment_id'])) {
            $attachment_id = absint($selected['attachment_id']);
            $requested_url = wp_get_attachment_image_url($attachment_id, $size);

            /* Solo usamos wp_get_attachment_image si va a pintar exactamente la URL validada. */
            if ($requested_url && esc_url_raw($requested_url) === esc_url_raw($selected['url'])) {
                $local_attr = array_merge($attr, array(
                    'alt'      => $alt,
                    'loading'  => $attr['loading'] ?? 'lazy',
                    'decoding' => 'async',
                    'class'    => trim(($attr['class'] ?? '') . ' attachment-woocommerce_thumbnail size-woocommerce_thumbnail wp-post-image dht-validated-product-image'),
                ));
                if ($onerror) {
                    $local_attr['onerror'] = $onerror;
                }

                $local = wp_get_attachment_image($attachment_id, $size, false, $local_attr);
                if ($local) {
                    return $local;
                }
            }
        }

        return sprintf(
            '<img src="%s" alt="%s" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail wp-post-image dht-validated-product-image dht-%s-product-image" loading="lazy" decoding="async"%s>',
            esc_url($selected['url']),
            esc_attr($alt),
            esc_attr($selected['source'] ?? 'external'),
            $onerror ? ' onerror="' . esc_attr($onerror) . '"' : ''
        );
    }
}


$default_product_cat = absint(get_option('default_product_cat'));

$root_categories = get_terms(array(
    'taxonomy'   => 'product_cat',
    'hide_empty' => true,
    'parent'     => 0,
    'exclude'    => $default_product_cat ? array($default_product_cat) : array(),
    'number'     => 16,
    'orderby'    => 'count',
    'order'      => 'DESC',
));

if (is_wp_error($root_categories)) {
    $root_categories = array();
}

$get_children = static function ($term_id, $limit = 4) use ($default_product_cat) {
    $children = get_terms(array(
        'taxonomy'   => 'product_cat',
        'hide_empty' => true,
        'parent'     => absint($term_id),
        'exclude'    => $default_product_cat ? array($default_product_cat) : array(),
        'number'     => absint($limit),
        'orderby'    => 'count',
        'order'      => 'DESC',
    ));

    return is_wp_error($children) ? array() : $children;
};

$get_term_image = static function ($term, $size = 'medium_large') use ($wpdb) {
    if (!$term || is_wp_error($term)) {
        return '';
    }

    /* 1) Imagen real de la categoria. */
    $thumbnail_id = absint(get_term_meta($term->term_id, 'thumbnail_id', true));
    if ($thumbnail_id > 0 && wp_attachment_is_image($thumbnail_id)) {
        $url = wp_get_attachment_image_url($thumbnail_id, $size);
        if ($url) {
            return $url;
        }
    }

    /* 2) Producto representativo de la categoria: local o proveedor. */
    $product_ids = get_posts(array(
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => 6,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'tax_query'      => array(array(
            'taxonomy'         => 'product_cat',
            'field'            => 'term_id',
            'terms'            => array(absint($term->term_id)),
            'include_children' => true,
        )),
    ));

    foreach ((array) $product_ids as $product_id) {
        $image_id = absint(get_post_thumbnail_id($product_id));
        if ($image_id > 0 && wp_attachment_is_image($image_id)) {
            $url = wp_get_attachment_image_url($image_id, $size);
            if ($url) {
                return $url;
            }
        }

        $external = dht_shop_template_get_external_product_image($product_id);
        if (!empty($external['url'])) {
            return $external['url'];
        }
    }

    /* 3) Sin imagen util: mejor fondo neutro que una imagen enorme/incorrecta. */
    return '';
};

$popular_products  = function_exists('wc_get_products') ? wc_get_products(array(
    'limit'   => 12,
    'status'  => 'publish',
    'orderby' => 'popularity',
)) : array();

$sale_products = function_exists('wc_get_products') ? wc_get_products(array(
    'limit'   => 12,
    'status'  => 'publish',
    'on_sale' => true,
    'orderby' => 'date',
    'order'   => 'DESC',
)) : array();

$new_products = function_exists('wc_get_products') ? wc_get_products(array(
    'limit'   => 12,
    'status'  => 'publish',
    'orderby' => 'date',
    'order'   => 'DESC',
)) : array();

$featured_products = function_exists('wc_get_products') ? wc_get_products(array(
    'limit'    => 12,
    'status'   => 'publish',
    'featured' => true,
    'orderby'  => 'date',
    'order'    => 'DESC',
)) : array();

if (empty($featured_products)) {
    $featured_products = $popular_products;
}

$render_products = static function ($products, $extra_class = '') {
    if (empty($products)) {
        echo '<p class="sf-empty">No hay productos disponibles en este bloque.</p>';
        return;
    }

    add_filter('woocommerce_product_get_image', 'dht_shop_template_product_image_external_fallback', 20, 6);

    echo '<ul class="products sf-products ' . esc_attr($extra_class) . '">';

    foreach ($products as $product) {
        if (!$product || !is_a($product, 'WC_Product')) {
            continue;
        }

        global $post;
        $post = get_post($product->get_id());
        if (!$post) {
            continue;
        }

        setup_postdata($post);
        wc_get_template_part('content', 'product');
    }

    wp_reset_postdata();
    echo '</ul>';

    remove_filter('woocommerce_product_get_image', 'dht_shop_template_product_image_external_fallback', 20);
};

/* Clusters reales del sistema SEO, si existe la tabla de relaciones. */
$cluster_ids = array();
$relations_table = $wpdb->prefix . 'seo_relations';
$table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $relations_table));

if ($table_exists === $relations_table) {
    $cluster_ids = $wpdb->get_col(
        "SELECT DISTINCT source_id
         FROM {$relations_table}
         WHERE source_type = 'cluster'
         ORDER BY source_id ASC
         LIMIT 8"
    );
    $cluster_ids = dht_template_public_post_ids($cluster_ids);
}

$get_cluster_image = static function ($cluster_id) use ($wpdb, $relations_table, $table_exists) {
    $image = dht_template_post_image_url($cluster_id, 'medium_large');
    if ($image || $table_exists !== $relations_table) {
        return $image;
    }

    $term_id = absint(
        $wpdb->get_var(
            $wpdb->prepare(
                "SELECT target_id
                 FROM {$relations_table}
                 WHERE source_type = 'cluster'
                   AND source_id = %d
                   AND target_type = 'product_cat'
                 ORDER BY id ASC
                 LIMIT 1",
                $cluster_id
            )
        )
    );

    return $term_id ? dht_template_term_image_url($term_id, 'medium_large', true) : '';
};

$latest_posts = get_posts(array(
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'posts_per_page' => 4,
    'orderby'        => 'date',
    'order'          => 'DESC',
    'no_found_rows'  => true,
));

$hero_categories = array_slice($root_categories, 0, 3);
$department_categories = array_slice($root_categories, 0, 8);
$more_categories = array_slice($root_categories, 8, 8);
?>

<main class="dht-storefront dht-storefront--desktop" id="dht-storefront">
    <!-- =========================================================
         DESKTOP / TABLET GRANDE
    ========================================================== -->
    <div class="sf-layout sf-layout--desktop">
        <section class="sf-hero" aria-label="Portada de la tienda">
            <div class="sf-shell sf-hero-grid">
                <div class="sf-hero-main">
                    <div class="sf-hero-copy">
                        <span class="sf-eyebrow">Herramientas · taller · automoción · mantenimiento</span>
                        <h1>Encuentra la herramienta que necesitas, sin perderte en el catálogo</h1>
                        <p>Compra por departamento, aplicación o producto. Una tienda técnica pensada para localizar rápido herramientas, maquinaria, consumibles y equipamiento profesional.</p>
                        <div class="sf-actions">
                            <a class="sf-btn sf-btn--primary" href="<?php echo esc_url(dht_template_shop_url()); ?>">Ver todo el catálogo</a>
                            <a class="sf-btn sf-btn--ghost" href="<?php echo esc_url(dht_template_contact_url()); ?>">Ayuda para elegir</a>
                        </div>
                    </div>
                </div>

                <div class="sf-hero-picks" aria-label="Departamentos destacados">
                    <?php foreach ($hero_categories as $index => $term) :
                        $image = $get_term_image($term, 'large');
                        $link  = dht_template_safe_term_link($term);
                        ?>
                        <a class="sf-hero-pick sf-hero-pick--<?php echo esc_attr((string) ($index + 1)); ?>" href="<?php echo esc_url($link); ?>">
                            <?php if ($image) : ?>
                                <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($term->name); ?>" loading="<?php echo $index === 0 ? 'eager' : 'lazy'; ?>">
                            <?php endif; ?>
                            <span><?php echo esc_html($term->name); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <?php if (!empty($root_categories)) : ?>
            <nav class="sf-quick-nav" aria-label="Accesos rápidos a departamentos">
                <div class="sf-shell sf-quick-nav-row">
                    <?php foreach (array_slice($root_categories, 0, 10) as $term) : ?>
                        <a href="<?php echo esc_url(dht_template_safe_term_link($term)); ?>"><?php echo esc_html($term->name); ?></a>
                    <?php endforeach; ?>
                </div>
            </nav>
        <?php endif; ?>

        <section class="sf-section sf-section--departments">
            <div class="sf-shell">
                <div class="sf-section-head">
                    <div>
                        <span class="sf-eyebrow">Compra por departamento</span>
                        <h2>Todo el catálogo, organizado para encontrarlo rápido</h2>
                    </div>
                    <a class="sf-text-link" href="<?php echo esc_url(dht_template_shop_url()); ?>">Ver catálogo completo <span aria-hidden="true">→</span></a>
                </div>

                <div class="sf-department-grid">
                    <?php foreach ($department_categories as $term) :
                        $children = $get_children($term->term_id, 4);
                        ?>
                        <article class="sf-department-card">
                            <div class="sf-department-title-row">
                                <h3><?php echo esc_html($term->name); ?></h3>
                                <a href="<?php echo esc_url(dht_template_safe_term_link($term)); ?>">Ver todo</a>
                            </div>

                            <?php if (!empty($children)) : ?>
                                <div class="sf-subcat-grid">
                                    <?php foreach ($children as $child) :
                                        $child_image = $get_term_image($child, 'woocommerce_thumbnail');
                                        ?>
                                        <a class="sf-subcat" href="<?php echo esc_url(dht_template_safe_term_link($child)); ?>">
                                            <span class="sf-subcat-media">
                                                <?php if ($child_image) : ?>
                                                    <img src="<?php echo esc_url($child_image); ?>" alt="<?php echo esc_attr($child->name); ?>" loading="lazy">
                                                <?php endif; ?>
                                            </span>
                                            <span class="sf-subcat-name"><?php echo esc_html($child->name); ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else :
                                $root_image = $get_term_image($term, 'large');
                                ?>
                                <a class="sf-department-fallback" href="<?php echo esc_url(dht_template_safe_term_link($term)); ?>">
                                    <?php if ($root_image) : ?>
                                        <img src="<?php echo esc_url($root_image); ?>" alt="<?php echo esc_attr($term->name); ?>" loading="lazy">
                                    <?php endif; ?>
                                    <span>Explorar <?php echo esc_html($term->name); ?></span>
                                </a>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="sf-section sf-section--products">
            <div class="sf-shell">
                <div class="sf-section-head sf-section-head--tight">
                    <div><span class="sf-eyebrow">Lo que más se busca</span><h2>Productos populares</h2></div>
                    <a class="sf-text-link" href="<?php echo esc_url(dht_template_shop_url()); ?>">Ver más <span aria-hidden="true">→</span></a>
                </div>
                <?php $render_products($popular_products, 'sf-products--desktop'); ?>
            </div>
        </section>

        <?php if (!empty($cluster_ids)) : ?>
            <section class="sf-section sf-section--dark">
                <div class="sf-shell">
                    <div class="sf-section-head sf-section-head--inverse">
                        <div><span class="sf-eyebrow">Explora por trabajo o necesidad</span><h2>Áreas especializadas</h2></div>
                    </div>
                    <div class="sf-cluster-grid">
                        <?php foreach (array_slice($cluster_ids, 0, 6) as $cluster_id) :
                            $cluster_image = $get_cluster_image($cluster_id);
                            $summary = dht_template_post_summary($cluster_id, 18);
                            ?>
                            <a class="sf-cluster-card" href="<?php echo esc_url(get_permalink($cluster_id)); ?>">
                                <span class="sf-cluster-media">
                                    <?php if ($cluster_image) : ?>
                                        <img src="<?php echo esc_url($cluster_image); ?>" alt="<?php echo esc_attr(get_the_title($cluster_id)); ?>" loading="lazy">
                                    <?php endif; ?>
                                </span>
                                <span class="sf-cluster-body">
                                    <strong><?php echo esc_html(get_the_title($cluster_id)); ?></strong>
                                    <?php if ($summary) : ?><span><?php echo esc_html($summary); ?></span><?php endif; ?>
                                    <em>Explorar <span aria-hidden="true">→</span></em>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <section class="sf-section sf-promo-row">
            <div class="sf-shell sf-promo-grid">
                <?php if (!empty($root_categories[0])) :
                    $promo_term = $root_categories[0];
                    $promo_image = $get_term_image($promo_term, 'large');
                    ?>
                    <a class="sf-promo sf-promo--image" href="<?php echo esc_url(dht_template_safe_term_link($promo_term)); ?>">
                        <?php if ($promo_image) : ?><img src="<?php echo esc_url($promo_image); ?>" alt="" loading="lazy"><?php endif; ?>
                        <span class="sf-promo-overlay"></span>
                        <span class="sf-promo-content"><small>Selección destacada</small><strong><?php echo esc_html($promo_term->name); ?></strong><em>Ver departamento →</em></span>
                    </a>
                <?php endif; ?>

                <div class="sf-promo sf-promo--service">
                    <span class="sf-promo-content">
                        <small>¿Dudas de compatibilidad?</small>
                        <strong>Te ayudamos a elegir antes de comprar</strong>
                        <span>Consulta medidas, usos, compatibilidad o alternativas directamente con nosotros.</span>
                        <a class="sf-btn sf-btn--light" href="https://wa.me/34640874540" target="_blank" rel="noopener noreferrer">Consultar por WhatsApp</a>
                    </span>
                </div>
            </div>
        </section>

        <?php if (!empty($sale_products)) : ?>
            <section class="sf-section sf-section--products sf-section--soft">
                <div class="sf-shell">
                    <div class="sf-section-head sf-section-head--tight">
                        <div><span class="sf-eyebrow">Oportunidades</span><h2>Ofertas destacadas</h2></div>
                    </div>
                    <?php $render_products($sale_products, 'sf-products--desktop'); ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($more_categories)) : ?>
            <section class="sf-section">
                <div class="sf-shell">
                    <div class="sf-section-head sf-section-head--tight">
                        <div><span class="sf-eyebrow">Sigue explorando</span><h2>Más departamentos</h2></div>
                    </div>
                    <div class="sf-category-strip">
                        <?php foreach ($more_categories as $term) :
                            $image = $get_term_image($term, 'woocommerce_thumbnail');
                            ?>
                            <a class="sf-category-mini" href="<?php echo esc_url(dht_template_safe_term_link($term)); ?>">
                                <span><?php if ($image) : ?><img src="<?php echo esc_url($image); ?>" alt="" loading="lazy"><?php endif; ?></span>
                                <strong><?php echo esc_html($term->name); ?></strong>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <section class="sf-section sf-section--products">
            <div class="sf-shell">
                <div class="sf-section-head sf-section-head--tight">
                    <div><span class="sf-eyebrow">Recién incorporado</span><h2>Novedades del catálogo</h2></div>
                </div>
                <?php $render_products($new_products, 'sf-products--desktop'); ?>
            </div>
        </section>

        <?php if (!empty($latest_posts)) : ?>
            <section class="sf-section sf-section--content">
                <div class="sf-shell">
                    <div class="sf-section-head">
                        <div><span class="sf-eyebrow">Guías y consejos</span><h2>Contenido para comprar y trabajar mejor</h2></div>
                        <a class="sf-text-link" href="<?php echo esc_url(dht_template_blog_url()); ?>">Ir al blog <span aria-hidden="true">→</span></a>
                    </div>
                    <div class="sf-article-grid">
                        <?php foreach ($latest_posts as $article) :
                            $image = dht_template_post_image_url($article->ID, 'medium_large');
                            ?>
                            <article class="sf-article-card">
                                <a class="sf-article-media" href="<?php echo esc_url(get_permalink($article)); ?>">
                                    <?php if ($image) : ?><img src="<?php echo esc_url($image); ?>" alt="" loading="lazy"><?php endif; ?>
                                </a>
                                <div class="sf-article-body">
                                    <h3><a href="<?php echo esc_url(get_permalink($article)); ?>"><?php echo esc_html(get_the_title($article)); ?></a></h3>
                                    <p><?php echo esc_html(dht_template_post_summary($article->ID, 18)); ?></p>
                                    <a href="<?php echo esc_url(get_permalink($article)); ?>">Leer guía →</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <section class="sf-service-bar">
            <div class="sf-shell sf-service-grid">
                <div><strong>Atención técnica</strong><span>Ayuda real antes y después de la compra</span></div>
                <div><strong>Compra segura</strong><span>Pago protegido y seguimiento del pedido</span></div>
                <div><strong>Catálogo especializado</strong><span>Herramientas, maquinaria y equipamiento técnico</span></div>
                <div><strong>Contacto directo</strong><span>Teléfono, email y WhatsApp</span></div>
            </div>
        </section>
    </div>
</main>

<?php dht_template_render_footer(); ?>