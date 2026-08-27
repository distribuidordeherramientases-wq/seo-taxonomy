<?php
/**
 * Template principal de tienda WooCommerce.
 *
 * Uso previsto: activar esta plantilla para la condicion is_shop().
 * Mantiene el loop principal de WooCommerce (ordenacion, contador y paginacion)
 * y aplica el lenguaje visual del storefront/front de DHT.
 *
 * Imagen de producto, orden estricto:
 * 1) Media / biblioteca local
 * 2) Tabla de imagenes de proveedores, validando cada URL antes de usarla
 * 3) Logo del sitio
 */

defined('ABSPATH') || exit;

$helpers_file = __DIR__ . '/template-helpers.php';
if (is_readable($helpers_file)) {
    require_once $helpers_file;
}

if (function_exists('dht_template_render_header')) {
    dht_template_render_header();
} else {
    get_header();
}

global $wpdb, $wp_query;

/* ==========================================================
   IMAGENES DE PRODUCTO
========================================================== */
if (!function_exists('dht_shop_image_candidate_is_loadable')) {
    function dht_shop_image_candidate_is_loadable($candidate) {
        $url = !empty($candidate['url']) ? esc_url_raw($candidate['url']) : '';
        if (!$url) {
            return false;
        }

        static $runtime_cache = array();
        if (array_key_exists($url, $runtime_cache)) {
            return $runtime_cache[$url];
        }

        /* URLs de uploads: comprobar el archivo real evita una peticion HTTP. */
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

        /* Cache persistente para reducir el coste de las comprobaciones externas. */
        $transient_key = 'dht_shop_img_ok_' . md5($url);
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
            'user-agent'          => 'Mozilla/5.0 (compatible; DHT-Shop-Image-Check/1.0; +' . home_url('/') . ')',
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

        set_transient(
            $transient_key,
            $ok ? '1' : '0',
            $ok ? 12 * HOUR_IN_SECONDS : 30 * MINUTE_IN_SECONDS
        );

        $runtime_cache[$url] = $ok;
        return $ok;
    }
}

if (!function_exists('dht_shop_add_attachment_candidates')) {
    function dht_shop_add_attachment_candidates(&$candidates, $attachment_id, $size = 'woocommerce_thumbnail') {
        $attachment_id = absint($attachment_id);
        if ($attachment_id < 1 || !wp_attachment_is_image($attachment_id)) {
            return;
        }

        foreach (array(
            wp_get_attachment_image_url($attachment_id, $size),
            wp_get_attachment_image_url($attachment_id, 'full'),
        ) as $url) {
            $url = $url ? esc_url_raw($url) : '';
            if ($url) {
                $candidates[] = array(
                    'attachment_id' => $attachment_id,
                    'url'           => $url,
                    'source'        => 'media',
                );
            }
        }
    }
}

if (!function_exists('dht_shop_get_media_candidates')) {
    function dht_shop_get_media_candidates($product_id, $size = 'woocommerce_thumbnail') {
        global $wpdb;

        $product_id = absint($product_id);
        if ($product_id < 1) {
            return array();
        }

        $candidates = array();
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;

        /* Destacada y galeria: prioridad maxima. */
        if ($product && is_a($product, 'WC_Product')) {
            dht_shop_add_attachment_candidates($candidates, $product->get_image_id(), $size);
            foreach ((array) $product->get_gallery_image_ids() as $gallery_id) {
                dht_shop_add_attachment_candidates($candidates, $gallery_id, $size);
            }
        }

        /* Indice Media SEO, si esta disponible. */
        $usage_table = function_exists('seo_images_table_usages')
            ? seo_images_table_usages()
            : $wpdb->prefix . 'seo_media_usos';
        $images_table = function_exists('seo_images_table_images')
            ? seo_images_table_images()
            : $wpdb->prefix . 'seo_media_imagenes';

        static $tables_available = array();
        $tables_key = $usage_table . '|' . $images_table;

        if (!array_key_exists($tables_key, $tables_available)) {
            $usage_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($usage_table)));
            $images_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($images_table)));
            $tables_available[$tables_key] = ($usage_exists === $usage_table && $images_exists === $images_table);
        }

        if ($tables_available[$tables_key]) {
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
                    dht_shop_add_attachment_candidates($candidates, $attachment_id, $size);
                }

                $origin_url = isset($row->url_origen) ? esc_url_raw($row->url_origen) : '';
                if ($origin_url) {
                    $candidates[] = array(
                        'attachment_id' => 0,
                        'url'           => $origin_url,
                        'source'        => 'media',
                    );
                }
            }
        }

        return $candidates;
    }
}

if (!function_exists('dht_shop_get_supplier_candidates')) {
    function dht_shop_get_supplier_candidates($product_id) {
        global $wpdb;

        $product_id = absint($product_id);
        if ($product_id < 1) {
            return array();
        }

        $candidates = array();
        $supplier_table = $wpdb->prefix . 'seo_supplier_images';
        static $table_exists = null;

        if (null === $table_exists) {
            $table_exists = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($supplier_table))
            ) === $supplier_table;
        }

        if ($table_exists) {
            $urls = $wpdb->get_col(
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

            foreach ((array) $urls as $url) {
                $url = esc_url_raw($url);
                if ($url) {
                    $candidates[] = array(
                        'attachment_id' => 0,
                        'url'           => $url,
                        'source'        => 'supplier',
                    );
                }
            }
        }

        /* Compatibilidad con el helper de proveedor si existe. */
        if (function_exists('seo_supplier_v2_external_primary_url')) {
            $url = esc_url_raw(seo_supplier_v2_external_primary_url($product_id));
            if ($url) {
                $candidates[] = array(
                    'attachment_id' => 0,
                    'url'           => $url,
                    'source'        => 'supplier',
                );
            }
        }

        return $candidates;
    }
}

if (!function_exists('dht_shop_get_logo_candidate')) {
    function dht_shop_get_logo_candidate() {
        $logo_id = absint(get_theme_mod('custom_logo'));
        if ($logo_id > 0 && wp_attachment_is_image($logo_id)) {
            $url = wp_get_attachment_image_url($logo_id, 'medium');
            if (!$url) {
                $url = wp_get_attachment_image_url($logo_id, 'full');
            }
            if ($url) {
                return array(
                    'attachment_id' => $logo_id,
                    'url'           => esc_url_raw($url),
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

if (!function_exists('dht_shop_get_product_candidates')) {
    function dht_shop_get_product_candidates($product_id, $size = 'woocommerce_thumbnail') {
        $all = array_merge(
            dht_shop_get_media_candidates($product_id, $size),
            dht_shop_get_supplier_candidates($product_id)
        );

        $logo = dht_shop_get_logo_candidate();
        if ($logo) {
            $all[] = $logo;
        }

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

if (!function_exists('dht_shop_get_best_product_image')) {
    function dht_shop_get_best_product_image($product_id, $size = 'woocommerce_thumbnail') {
        static $cache = array();
        $key = absint($product_id) . '|' . (string) $size;

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        foreach (dht_shop_get_product_candidates($product_id, $size) as $candidate) {
            if (dht_shop_image_candidate_is_loadable($candidate)) {
                $cache[$key] = $candidate;
                return $candidate;
            }
        }

        $cache[$key] = null;
        return null;
    }
}

if (!function_exists('dht_shop_product_image_filter')) {
    function dht_shop_product_image_filter($image, $product, $size, $attr, $placeholder, $original = '') {
        if (!is_a($product, 'WC_Product')) {
            return $image;
        }

        $selected = dht_shop_get_best_product_image($product->get_id(), $size);
        if (empty($selected['url'])) {
            return $image;
        }

        $attr = is_array($attr) ? $attr : array();
        $is_logo = isset($selected['source']) && 'logo' === $selected['source'];
        $alt = $is_logo
            ? get_bloginfo('name')
            : (!empty($attr['alt']) ? $attr['alt'] : $product->get_name());

        $logo_fallback_url = '';
        if (!$is_logo) {
            $logo = dht_shop_get_logo_candidate();
            if ($logo && !empty($logo['url']) && dht_shop_image_candidate_is_loadable($logo)) {
                $logo_fallback_url = esc_url_raw($logo['url']);
            }
        }

        $onerror = $logo_fallback_url
            ? "this.onerror=null;this.src='" . esc_js($logo_fallback_url) . "';"
            : '';

        if (!empty($selected['attachment_id'])) {
            $attachment_id = absint($selected['attachment_id']);
            $requested_url = wp_get_attachment_image_url($attachment_id, $size);

            if ($requested_url && esc_url_raw($requested_url) === esc_url_raw($selected['url'])) {
                $local_attr = array_merge($attr, array(
                    'alt'      => $alt,
                    'loading'  => $attr['loading'] ?? 'lazy',
                    'decoding' => 'async',
                    'class'    => trim(($attr['class'] ?? '') . ' dht-validated-product-image'),
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

/* ==========================================================
   DATOS DE NAVEGACION DE LA TIENDA
========================================================== */
$default_product_cat = absint(get_option('default_product_cat'));
$root_categories = get_terms(array(
    'taxonomy'   => 'product_cat',
    'hide_empty' => true,
    'parent'     => 0,
    'exclude'    => $default_product_cat ? array($default_product_cat) : array(),
    'number'     => 12,
    'orderby'    => 'count',
    'order'      => 'DESC',
));

if (is_wp_error($root_categories)) {
    $root_categories = array();
}

if (!function_exists('dht_shop_term_image_url')) {
    function dht_shop_term_image_url($term, $size = 'medium_large') {
        if (!$term || is_wp_error($term)) {
            return '';
        }

        $thumbnail_id = absint(get_term_meta($term->term_id, 'thumbnail_id', true));
        if ($thumbnail_id > 0 && wp_attachment_is_image($thumbnail_id)) {
            $url = wp_get_attachment_image_url($thumbnail_id, $size);
            if ($url) {
                return $url;
            }
        }

        return '';
    }
}

if (!function_exists('dht_shop_term_link')) {
    function dht_shop_term_link($term) {
        $link = get_term_link($term);
        return is_wp_error($link) ? home_url('/') : $link;
    }
}

$hero_categories = array_slice($root_categories, 0, 3);
$category_cards = array_slice($root_categories, 0, 8);
$current_page = max(1, absint(get_query_var('paged')));
$total_products = isset($wp_query->found_posts) ? absint($wp_query->found_posts) : 0;
$shop_page_id = function_exists('wc_get_page_id') ? absint(wc_get_page_id('shop')) : 0;
$shop_title = $shop_page_id > 0 ? get_the_title($shop_page_id) : '';
if (!$shop_title) {
    $shop_title = 'Tienda';
}
?>

<main class="dht-storefront dht-shop-template" id="dht-shop-template">

    <section class="sf-hero shop-hero" aria-labelledby="dht-shop-title">
        <div class="sf-shell sf-hero-grid shop-hero-grid">
            <div class="sf-hero-main">
                <div class="sf-hero-copy">
                    <span class="sf-eyebrow">Catálogo profesional</span>
                    <h1 id="dht-shop-title"><?php echo esc_html($shop_title); ?> de herramientas y equipamiento</h1>
                    <p>Explora herramientas, maquinaria, consumibles y equipamiento técnico. Filtra, ordena y entra directamente en la categoría o producto que necesitas.</p>

                    <?php if (shortcode_exists('seo_search')) : ?>
                        <?php echo do_shortcode('[seo_search placeholder="Buscar herramienta, máquina, referencia, tipo o aplicación..."]'); ?>
                    <?php else : ?>
                        <form class="shop-hero-search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
                            <label class="screen-reader-text" for="dht-shop-search">Buscar productos</label>
                            <input id="dht-shop-search" type="search" name="s" placeholder="Buscar herramienta, máquina, referencia..." autocomplete="off">
                            <input type="hidden" name="post_type" value="product">
                            <button type="submit">Buscar</button>
                        </form>
                    <?php endif; ?>

                    <div class="shop-hero-meta">
                        <?php if ($total_products > 0) : ?>
                            <span><strong><?php echo esc_html(number_format_i18n($total_products)); ?></strong> productos disponibles</span>
                        <?php endif; ?>
                        <a href="#catalogo">Ir al catálogo <span aria-hidden="true">↓</span></a>
                    </div>
                </div>
            </div>

            <?php if (!empty($hero_categories)) : ?>
                <div class="sf-hero-picks shop-hero-picks" aria-label="Categorías destacadas">
                    <?php foreach ($hero_categories as $index => $term) :
                        $image = dht_shop_term_image_url($term, 'large');
                        ?>
                        <a class="sf-hero-pick sf-hero-pick--<?php echo esc_attr((string) ($index + 1)); ?>" href="<?php echo esc_url(dht_shop_term_link($term)); ?>">
                            <?php if ($image) : ?>
                                <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($term->name); ?>" loading="<?php echo 0 === $index ? 'eager' : 'lazy'; ?>">
                            <?php else : ?>
                                <span class="shop-hero-pick-placeholder" aria-hidden="true"></span>
                            <?php endif; ?>
                            <span><?php echo esc_html($term->name); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if (!empty($root_categories)) : ?>
        <nav class="sf-quick-nav shop-quick-nav" aria-label="Categorías de producto">
            <div class="sf-shell sf-quick-nav-row">
                <?php foreach ($root_categories as $term) : ?>
                    <a href="<?php echo esc_url(dht_shop_term_link($term)); ?>"><?php echo esc_html($term->name); ?></a>
                <?php endforeach; ?>
            </div>
        </nav>
    <?php endif; ?>

    <?php if (1 === $current_page && !empty($category_cards)) : ?>
        <section class="sf-section shop-category-section">
            <div class="sf-shell">
                <div class="sf-section-head shop-section-head">
                    <div>
                        <span class="sf-eyebrow">Compra por categoría</span>
                        <h2>Encuentra antes lo que necesitas</h2>
                    </div>
                    <span class="shop-section-note">Categorías con más productos</span>
                </div>

                <div class="shop-category-grid">
                    <?php foreach ($category_cards as $term) :
                        $image = dht_shop_term_image_url($term, 'medium_large');
                        ?>
                        <a class="shop-category-card" href="<?php echo esc_url(dht_shop_term_link($term)); ?>">
                            <span class="shop-category-media">
                                <?php if ($image) : ?>
                                    <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($term->name); ?>" loading="lazy">
                                <?php else : ?>
                                    <span class="shop-category-placeholder" aria-hidden="true">DHT</span>
                                <?php endif; ?>
                            </span>
                            <span class="shop-category-copy">
                                <strong><?php echo esc_html($term->name); ?></strong>
                                <small><?php echo esc_html(number_format_i18n(absint($term->count))); ?> productos</small>
                                <em>Explorar <span aria-hidden="true">→</span></em>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="sf-section shop-catalog-section" id="catalogo">
        <div class="sf-shell">
            <div class="shop-catalog-panel">
                <div class="sf-section-head shop-catalog-heading">
                    <div>
                        <span class="sf-eyebrow">Catálogo completo</span>
                        <h2><?php echo 1 === $current_page ? 'Todos los productos' : 'Productos · página ' . esc_html((string) $current_page); ?></h2>
                    </div>
                </div>

                <?php if (shortcode_exists('seo_search_filters')) : ?>
                    <?php echo do_shortcode('[seo_search_filters class="shop-semantic-filters"]'); ?>
                <?php endif; ?>

                <?php if (function_exists('woocommerce_product_loop') && woocommerce_product_loop()) : ?>
                    <div class="shop-loop-tools">
                        <?php do_action('woocommerce_before_shop_loop'); ?>
                    </div>

                    <?php add_filter('woocommerce_product_get_image', 'dht_shop_product_image_filter', 20, 6); ?>

                    <ul class="products sf-products shop-products">
                        <?php while (have_posts()) : the_post(); ?>
                            <?php wc_get_template_part('content', 'product'); ?>
                        <?php endwhile; ?>
                    </ul>

                    <?php remove_filter('woocommerce_product_get_image', 'dht_shop_product_image_filter', 20); ?>

                    <div class="shop-pagination">
                        <?php do_action('woocommerce_after_shop_loop'); ?>
                    </div>
                <?php else : ?>
                    <div class="shop-no-products">
                        <?php do_action('woocommerce_no_products_found'); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="sf-service-bar shop-service-bar" aria-label="Ventajas de la tienda">
        <div class="sf-shell sf-service-grid">
            <div><strong>Catálogo especializado</strong><span>Herramienta, maquinaria y equipamiento técnico</span></div>
            <div><strong>Atención técnica</strong><span>Ayuda para elegir producto y compatibilidad</span></div>
            <div><strong>Compra segura</strong><span>Pago protegido y seguimiento de pedidos</span></div>
            <div><strong>Contacto directo</strong><span>Teléfono, email y WhatsApp</span></div>
        </div>
    </section>
</main>

<?php
if (function_exists('dht_template_render_footer')) {
    dht_template_render_footer();
} else {
    get_footer();
}
