<?php
/**
 * Template Name: Post editorial + conversión DHT
 * Gestor de variante post: móvil / escritorio.
 *
 * DHT POST V1.3 SELF-CONTAINED
 * Política visual: Media -> índice Media -> proveedor -> logo.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

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

require dht_template_device_variant_file('post');
