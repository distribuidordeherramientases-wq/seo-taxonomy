<?php
/**
 * Utilidades compartidas por las plantillas públicas del plugin.
 */

defined('ABSPATH') || exit;

if (!function_exists('dht_template_render_header')) {
    function dht_template_render_header()
    {
        $path = __DIR__ . '/header.php';

        if (is_readable($path)) {
            include $path;
            return;
        }

        get_header();
    }
}

if (!function_exists('dht_template_render_footer')) {
    function dht_template_render_footer()
    {
        $path = __DIR__ . '/footer.php';

        if (is_readable($path)) {
            include $path;
            return;
        }

        get_footer();
    }
}

if (!function_exists('dht_template_shop_url')) {
    function dht_template_shop_url()
    {
        if (function_exists('wc_get_page_permalink')) {
            $url = wc_get_page_permalink('shop');
            if ($url) {
                return $url;
            }
        }

        return home_url('/tienda/');
    }
}

if (!function_exists('dht_template_contact_url')) {
    function dht_template_contact_url()
    {
        $page = get_page_by_path('contacto');
        return $page ? get_permalink($page) : home_url('/contacto/');
    }
}

if (!function_exists('dht_template_blog_url')) {
    function dht_template_blog_url()
    {
        $posts_page_id = (int) get_option('page_for_posts');

        if ($posts_page_id > 0) {
            $url = get_permalink($posts_page_id);
            if ($url) {
                return $url;
            }
        }

        return home_url('/blog/');
    }
}

if (!function_exists('dht_template_placeholder_image_url')) {
    function dht_template_placeholder_image_url($size = 'woocommerce_thumbnail')
    {
        if (function_exists('wc_placeholder_img_src')) {
            return (string) wc_placeholder_img_src($size);
        }

        return '';
    }
}


/* ==========================================================
   IMAGENES COMPARTIDAS DE PRODUCTO
   Media local -> proveedor externo -> logo.
   Las URLs de proveedor no se predescargan desde PHP.
   ========================================================== */
if (!function_exists('dht_shared_is_http_url')) {
    function dht_shared_is_http_url($url)
    {
        $url = esc_url_raw((string) $url);
        if ($url === '') {
            return false;
        }

        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, array('http', 'https'), true);
    }
}

if (!function_exists('dht_shared_site_logo_candidate')) {
    function dht_shared_site_logo_candidate()
    {
        $logo_id = absint(get_theme_mod('custom_logo'));
        if ($logo_id > 0 && wp_attachment_is_image($logo_id)) {
            $url = wp_get_attachment_image_url($logo_id, 'medium');
            if (!$url) {
                $url = wp_get_attachment_image_url($logo_id, 'full');
            }
            if ($url) {
                return array('attachment_id' => $logo_id, 'url' => esc_url_raw($url), 'source' => 'logo');
            }
        }

        $site_icon = function_exists('get_site_icon_url') ? get_site_icon_url(512) : '';
        if ($site_icon) {
            return array('attachment_id' => 0, 'url' => esc_url_raw($site_icon), 'source' => 'logo');
        }

        return null;
    }
}

if (!function_exists('dht_shared_product_image_candidates')) {
    function dht_shared_product_image_candidates($product_id, $size = 'woocommerce_thumbnail', $supplier_limit = 3, $include_logo = true)
    {
        global $wpdb;

        static $cache = array();
        static $supplier_table_exists = null;

        $product_id = absint($product_id);
        $supplier_limit = max(1, min(6, absint($supplier_limit)));
        $cache_key = implode('|', array($product_id, (string) $size, $supplier_limit, $include_logo ? '1' : '0'));

        if (isset($cache[$cache_key])) {
            return $cache[$cache_key];
        }

        $candidates = array();
        $seen = array();
        $add = static function ($candidate) use (&$candidates, &$seen) {
            $url = !empty($candidate['url']) ? esc_url_raw($candidate['url']) : '';
            if (!$url || isset($seen[$url])) {
                return;
            }
            $seen[$url] = true;
            $candidate['url'] = $url;
            $candidates[] = $candidate;
        };

        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if ($product && is_a($product, 'WC_Product')) {
            $attachment_ids = array_values(array_unique(array_filter(array_merge(
                array(absint($product->get_image_id())),
                array_map('absint', (array) $product->get_gallery_image_ids())
            ))));

            foreach ($attachment_ids as $attachment_id) {
                if (!wp_attachment_is_image($attachment_id)) {
                    continue;
                }

                foreach (array($size, 'full') as $requested_size) {
                    $url = wp_get_attachment_image_url($attachment_id, $requested_size);
                    if ($url) {
                        $add(array(
                            'attachment_id' => $attachment_id,
                            'url' => $url,
                            'source' => 'media',
                            'size' => $requested_size,
                        ));
                    }
                }
            }
        }

        $supplier_table = $wpdb->prefix . 'seo_supplier_images';
        if (null === $supplier_table_exists) {
            $supplier_table_exists = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($supplier_table))
            ) === $supplier_table;
        }

        if ($supplier_table_exists) {
            $supplier_urls = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT image_url
                     FROM {$supplier_table}
                     WHERE product_id = %d
                       AND status = 'active'
                       AND image_url IS NOT NULL
                       AND TRIM(image_url) <> ''
                     ORDER BY is_primary DESC, position ASC, id ASC
                     LIMIT %d",
                    $product_id,
                    $supplier_limit
                )
            );

            foreach ((array) $supplier_urls as $supplier_url) {
                $supplier_url = esc_url_raw((string) $supplier_url);
                if (dht_shared_is_http_url($supplier_url)) {
                    $add(array('attachment_id' => 0, 'url' => $supplier_url, 'source' => 'supplier'));
                }
            }
        }

        if (function_exists('seo_supplier_v2_external_primary_url')) {
            $supplier_url = esc_url_raw((string) seo_supplier_v2_external_primary_url($product_id));
            if (dht_shared_is_http_url($supplier_url)) {
                $add(array('attachment_id' => 0, 'url' => $supplier_url, 'source' => 'supplier'));
            }
        }

        if ($include_logo) {
            $logo = dht_shared_site_logo_candidate();
            if ($logo) {
                $add($logo);
            }
        }

        $cache[$cache_key] = $candidates;
        return $candidates;
    }
}

if (!function_exists('dht_shared_image_fallback_onerror')) {
    function dht_shared_image_fallback_onerror($urls)
    {
        $clean = array();
        foreach ((array) $urls as $url) {
            $url = esc_url_raw((string) $url);
            if ($url && !in_array($url, $clean, true)) {
                $clean[] = $url;
            }
        }

        if (!$clean) {
            return 'this.onerror=null;';
        }

        $json = wp_json_encode(array_values($clean), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return "var f={$json},i=parseInt(this.getAttribute('data-dht-fallback-index')||'0',10);"
            . "this.removeAttribute('srcset');this.removeAttribute('sizes');"
            . "if(i<f.length){this.setAttribute('data-dht-fallback-index',String(i+1));this.src=f[i];}else{this.onerror=null;}";
    }
}

if (!function_exists('dht_shared_product_card_image_html')) {
    function dht_shared_product_card_image_html($product, $size = 'woocommerce_thumbnail', $supplier_limit = 3, $attr = array())
    {
        if (!is_a($product, 'WC_Product')) {
            return '';
        }

        $candidates = dht_shared_product_image_candidates($product->get_id(), $size, $supplier_limit, true);
        if (!$candidates) {
            return '';
        }

        $selected = array_shift($candidates);
        $fallback_urls = array();
        foreach ($candidates as $candidate) {
            if (!empty($candidate['url'])) {
                $fallback_urls[] = $candidate['url'];
            }
        }

        $attr = is_array($attr) ? $attr : array();
        $source = sanitize_html_class((string) ($selected['source'] ?? 'external'));
        $is_logo = ('logo' === $source);
        $alt = $is_logo ? get_bloginfo('name') : (!empty($attr['alt']) ? $attr['alt'] : $product->get_name());
        $onerror = dht_shared_image_fallback_onerror($fallback_urls);

        if (!empty($selected['attachment_id']) && 'media' === $source) {
            $attachment_id = absint($selected['attachment_id']);
            $requested_url = wp_get_attachment_image_url($attachment_id, $size);
            if ($requested_url && esc_url_raw($requested_url) === esc_url_raw($selected['url'])) {
                $local_attr = array_merge($attr, array(
                    'alt' => $alt,
                    'loading' => $attr['loading'] ?? 'lazy',
                    'decoding' => 'async',
                    'class' => trim(($attr['class'] ?? '') . ' attachment-woocommerce_thumbnail size-woocommerce_thumbnail wp-post-image dht-product-image dht-media-product-image'),
                    'onerror' => $onerror,
                    'data-dht-fallback-index' => '0',
                ));
                $html = wp_get_attachment_image($attachment_id, $size, false, $local_attr);
                if ($html) {
                    return $html;
                }
            }
        }

        $classes = array(
            'attachment-woocommerce_thumbnail',
            'size-woocommerce_thumbnail',
            'wp-post-image',
            'dht-product-image',
            'dht-' . $source . '-product-image',
        );
        if ('supplier' === $source) {
            $classes[] = 'dht-external-product-image';
        }

        return sprintf(
            '<img src="%s" alt="%s" class="%s" loading="%s" decoding="async" data-dht-fallback-index="0" onerror="%s">',
            esc_url($selected['url']),
            esc_attr($alt),
            esc_attr(implode(' ', $classes)),
            esc_attr($attr['loading'] ?? 'lazy'),
            esc_attr($onerror)
        );
    }
}

if (!function_exists('dht_shared_render_product_card')) {
    function dht_shared_render_product_card($card_product, $supplier_limit = 3)
    {
        if (!is_a($card_product, 'WC_Product') || 'publish' !== get_post_status($card_product->get_id())) {
            return;
        }

        global $product, $post;
        $previous_product = $product ?? null;
        $previous_post = $post ?? null;
        $product = $card_product;
        $post = get_post($card_product->get_id());
        if ($post) {
            setup_postdata($post);
        }

        $permalink = get_permalink($card_product->get_id());
        echo '<li class="product dh-product-card">';
        echo '<a href="' . esc_url($permalink) . '" class="dh-product-link">';

        if ($card_product->is_on_sale()) {
            echo '<span class="onsale">' . esc_html__('Oferta', 'woocommerce') . '</span>';
        }

        echo '<div class="dh-product-image">';
        echo dht_shared_product_card_image_html($card_product, 'woocommerce_thumbnail', $supplier_limit);
        echo '</div>';
        echo '<div class="dh-product-title">' . esc_html($card_product->get_name()) . '</div>';
        echo '<div class="dh-product-price">' . wp_kses_post($card_product->get_price_html()) . '</div>';
        echo '</a>';
        echo '<div class="dh-product-actions">';
        if (function_exists('woocommerce_template_loop_add_to_cart')) {
            woocommerce_template_loop_add_to_cart();
        }
        echo '</div>';
        echo '</li>';

        wp_reset_postdata();
        $product = $previous_product;
        $post = $previous_post;
    }
}

if (!function_exists('dht_shared_render_product_grid')) {
    function dht_shared_render_product_grid($products, $extra_class = '', $supplier_limit = 3)
    {
        $valid = array();
        foreach ((array) $products as $item) {
            $candidate = is_a($item, 'WC_Product') ? $item : (function_exists('wc_get_product') ? wc_get_product(absint($item)) : null);
            if ($candidate && is_a($candidate, 'WC_Product')) {
                $valid[] = $candidate;
            }
        }

        if (!$valid) {
            return;
        }

        echo '<ul class="products ' . esc_attr($extra_class) . '">';
        foreach ($valid as $candidate) {
            dht_shared_render_product_card($candidate, $supplier_limit);
        }
        echo '</ul>';
    }
}

if (!function_exists('dht_template_node_category_ids')) {
    function dht_template_node_category_ids($source_type, $source_id, $limit = 12)
    {
        global $wpdb;
        $source_id = absint($source_id);
        $limit = max(1, absint($limit));
        if ($source_id < 1) {
            return array();
        }

        $table = $wpdb->prefix . 'seo_relations';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($exists !== $table) {
            return array();
        }

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT target_id
             FROM {$table}
             WHERE source_type = %s
               AND source_id = %d
               AND target_type = 'product_cat'
             ORDER BY id ASC
             LIMIT %d",
            sanitize_key($source_type),
            $source_id,
            $limit
        ));

        return dht_template_public_term_ids($ids, 'product_cat');
    }
}

if (!function_exists('dht_template_node_image_url')) {
    function dht_template_node_image_url($source_type, $source_id, $size = 'medium_large')
    {
        $url = dht_template_post_image_url($source_id, $size);
        if ($url) {
            return $url;
        }

        foreach (dht_template_node_category_ids($source_type, $source_id, 8) as $term_id) {
            $url = dht_template_term_image_url($term_id, $size, true);
            if ($url) {
                return $url;
            }
        }

        return '';
    }
}

if (!function_exists('dht_template_safe_term_link')) {
    function dht_template_safe_term_link($term)
    {
        $url = get_term_link($term);
        return is_wp_error($url) ? '' : (string) $url;
    }
}

if (!function_exists('dht_template_term_image_url')) {
    function dht_template_term_image_url($term_id, $size = 'large', $fallback_to_product = true)
    {
        $term_id = absint($term_id);
        if ($term_id <= 0) {
            return '';
        }

        $thumbnail_id = absint(get_term_meta($term_id, 'thumbnail_id', true));
        if ($thumbnail_id > 0 && wp_attachment_is_image($thumbnail_id)) {
            $url = wp_get_attachment_image_url($thumbnail_id, $size);
            if ($url) {
                return $url;
            }
        }

        if ($fallback_to_product) {
            $product_ids = get_posts(array(
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => 6,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'tax_query'      => array(
                    array(
                        'taxonomy'         => 'product_cat',
                        'field'            => 'term_id',
                        'terms'            => array($term_id),
                        'include_children' => true,
                    ),
                ),
            ));

            foreach ((array) $product_ids as $product_id) {
                $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
                if ($product && is_a($product, 'WC_Product')) {
                    $image_id = absint($product->get_image_id());
                    if ($image_id > 0 && wp_attachment_is_image($image_id)) {
                        $url = wp_get_attachment_image_url($image_id, $size);
                        if ($url) {
                            return $url;
                        }
                    }
                }

                $candidates = dht_shared_product_image_candidates($product_id, $size, 1, false);
                foreach ($candidates as $candidate) {
                    if (!empty($candidate['url'])) {
                        return $candidate['url'];
                    }
                }
            }
        }

        return '';
    }
}

if (!function_exists('dht_template_post_image_url')) {
    function dht_template_post_image_url($post_id, $size = 'large')
    {
        $post_id = absint($post_id);
        if ($post_id <= 0) {
            return '';
        }

        $url = get_the_post_thumbnail_url($post_id, $size);
        return $url ? (string) $url : '';
    }
}

if (!function_exists('dht_template_structural_image_url')) {
    function dht_template_structural_image_url($post_id, $related_post_ids = array(), $related_term_ids = array(), $size = 'large')
    {
        $url = dht_template_post_image_url($post_id, $size);
        if ($url) {
            return $url;
        }

        foreach (array_map('absint', (array) $related_post_ids) as $related_post_id) {
            $url = dht_template_post_image_url($related_post_id, $size);
            if ($url) {
                return $url;
            }
        }

        foreach (array_map('absint', (array) $related_term_ids) as $related_term_id) {
            $url = dht_template_term_image_url($related_term_id, $size, true);
            if ($url) {
                return $url;
            }
        }

        return '';
    }
}

if (!function_exists('dht_template_post_summary')) {
    function dht_template_post_summary($post_id, $words = 24)
    {
        $post = get_post($post_id);
        if (!$post) {
            return '';
        }

        if (trim((string) $post->post_excerpt) !== '') {
            return wp_strip_all_tags($post->post_excerpt);
        }

        return wp_trim_words(wp_strip_all_tags(strip_shortcodes((string) $post->post_content)), $words);
    }
}

if (!function_exists('dht_template_public_post_ids')) {
    function dht_template_public_post_ids($ids)
    {
        $valid = array();

        foreach (array_unique(array_map('absint', (array) $ids)) as $id) {
            if ($id > 0 && get_post_status($id) === 'publish' && get_permalink($id)) {
                $valid[] = $id;
            }
        }

        return $valid;
    }
}

if (!function_exists('dht_template_public_term_ids')) {
    function dht_template_public_term_ids($ids, $taxonomy = 'product_cat')
    {
        $valid = array();

        foreach (array_unique(array_map('absint', (array) $ids)) as $id) {
            $term = get_term($id, $taxonomy);
            if ($term && !is_wp_error($term) && dht_template_safe_term_link($term)) {
                $valid[] = $id;
            }
        }

        return $valid;
    }
}

/* ==========================================================
   SELECTOR CENTRAL DE PLANTILLAS POR DISPOSITIVO
   Los archivos template-*.php gestores solo piden una ruta.
   El require se ejecuta fuera de esta funcion para conservar
   el scope normal de WordPress en la plantilla cargada.
========================================================== */
if (!function_exists('dht_template_device_variant_file')) {
    function dht_template_device_variant_file($base)
    {
        $base = strtolower((string) $base);
        $base = preg_replace('/[^a-z0-9-]/', '', $base);

        if ($base === '') {
            wp_die('Plantilla DHT no valida.');
        }

        $preferred = wp_is_mobile() ? 'mobile' : 'desktop';
        $fallback  = $preferred === 'mobile' ? 'desktop' : 'mobile';

        $preferred_file = __DIR__ . '/template-' . $base . '-' . $preferred . '.php';
        $fallback_file  = __DIR__ . '/template-' . $base . '-' . $fallback . '.php';

        if (is_readable($preferred_file)) {
            $GLOBALS['dht_template_active_variant'] = $preferred;
            $GLOBALS['dht_template_active_base']    = $base;
            return $preferred_file;
        }

        /* Respaldo seguro: evita un error 500 si falta accidentalmente
         * una de las dos variantes durante una subida. */
        if (is_readable($fallback_file)) {
            $GLOBALS['dht_template_active_variant'] = $fallback;
            $GLOBALS['dht_template_active_base']    = $base;
            return $fallback_file;
        }

        wp_die(
            esc_html(sprintf('No se encuentran las plantillas DHT para "%s".', $base)),
            'Plantilla no disponible',
            array('response' => 500)
        );
    }
}
