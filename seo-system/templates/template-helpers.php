<?php
/**
 * Utilidades compartidas por las plantillas públicas del plugin.
 */

defined('ABSPATH') || exit;

/* ==========================================================
   CANONICAL DHT
   WordPress conserva ID, slug y permalink. DHT controla la
   politica canonical para producto y product_cat.
   ========================================================== */
if (!function_exists('dht_template_get_canonical_url')) {
    function dht_template_get_canonical_url()
    {
        $canonical = '';
        $context = array(
            'object_type' => '',
            'object_id'   => 0,
        );

        if (function_exists('is_product_category') && is_product_category()) {
            $term = get_queried_object();

            if ($term && is_a($term, 'WP_Term') && 'product_cat' === $term->taxonomy) {
                $term_url = get_term_link($term);

                if (!is_wp_error($term_url) && $term_url) {
                    $canonical = (string) $term_url;

                    $paged = max(1, absint(get_query_var('paged')));
                    if ($paged > 1) {
                        $paged_url = get_pagenum_link($paged);
                        if ($paged_url) {
                            $canonical = (string) $paged_url;
                        }
                    }

                    $context = array(
                        'object_type' => 'product_cat',
                        'object_id'   => (int) $term->term_id,
                    );
                }
            }
        } elseif (function_exists('is_product') && is_product()) {
            $product_id = absint(get_queried_object_id());
            if ($product_id > 0) {
                $permalink = get_permalink($product_id);
                if ($permalink) {
                    $canonical = (string) $permalink;
                    $context = array(
                        'object_type' => 'product',
                        'object_id'   => $product_id,
                    );
                }
            }
        }

        $canonical = esc_url_raw($canonical);
        if ($canonical === '') {
            return '';
        }

        return esc_url_raw((string) apply_filters('dht_template_canonical_url', $canonical, $context));
    }
}

if (!function_exists('dht_template_render_wp_head')) {
    function dht_template_render_wp_head()
    {
        $canonical = dht_template_get_canonical_url();

        if ($canonical === '') {
            wp_head();
            return;
        }

        /*
         * Ejecuta wp_head completo para conservar todos sus efectos y hooks,
         * pero elimina cualquier canonical publicado por Core o plugins.
         * Despues se imprime una sola canonical DHT calculada desde la URL
         * publica actual de la entidad.
         */
        ob_start();
        wp_head();
        $head = (string) ob_get_clean();

        $head = preg_replace(
            '~<link\\b[^>]*\\brel\\s*=\\s*["\\\']canonical["\\\'][^>]*>\\s*~i',
            '',
            $head
        );

        echo $head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo "\n<link rel=\"canonical\" href=\"" . esc_url($canonical) . "\">\n";
    }
}

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

if (!function_exists('dht_shared_product_compare_data')) {
    function dht_shared_product_compare_data($compare_product, $supplier_limit = 3)
    {
        if (!is_a($compare_product, 'WC_Product')) {
            return array();
        }

        $product_id = absint($compare_product->get_id());
        if ($product_id < 1) {
            return array();
        }

        /*
         * El comparador usa como fuente principal la clasificación y los
         * atributos canónicos de SEO Taxonomy. Los atributos nativos de
         * WooCommerce quedan como fallback para no perder información legacy.
         */
        $attributes = array();

        $append_attribute_values = static function ($label, $values, $only_if_missing = false) use (&$attributes) {
            $label = trim(wp_strip_all_tags((string) $label));
            if ($label === '') {
                return;
            }

            $values = array_values(array_unique(array_filter(array_map(static function ($value) {
                $value = trim(wp_strip_all_tags((string) $value));
                return $value === '' ? '' : $value;
            }, (array) $values))));

            if (!$values) {
                return;
            }

            if ($only_if_missing && isset($attributes[$label])) {
                return;
            }

            if (isset($attributes[$label]) && !$only_if_missing) {
                $current = array_map('trim', explode(',', (string) $attributes[$label]));
                $values = array_values(array_unique(array_merge($current, $values)));
            }

            $attributes[$label] = implode(', ', $values);
        };

        $format_attribute_label = static function ($raw_label) {
            $raw_label = preg_replace('/^pa_/', '', (string) $raw_label);
            $raw_label = str_replace(array('_', '-'), ' ', $raw_label);
            $raw_label = trim((string) preg_replace('/\\s+/u', ' ', $raw_label));
            if ($raw_label === '') {
                return '';
            }
            return function_exists('mb_convert_case')
                ? mb_convert_case($raw_label, MB_CASE_TITLE, 'UTF-8')
                : ucwords($raw_label);
        };

        /* Atributos técnicos canónicos de SEO Taxonomy. */
        if (function_exists('seo_attributes_get_product_rows')) {
            $canonical_groups = array();

            foreach ((array) seo_attributes_get_product_rows($product_id) as $row) {
                if (isset($row->attribute_visible) && !(int) $row->attribute_visible) {
                    continue;
                }

                $attribute_type = sanitize_key((string) ($row->attribute_type ?? ''));
                $value = trim((string) ($row->attribute_value ?? ''));
                if ($attribute_type === '' || $value === '') {
                    continue;
                }

                $label = trim((string) ($row->attribute_name ?? ''));
                if ($label === '') {
                    $label = $format_attribute_label($attribute_type);
                }
                if ($label === '') {
                    continue;
                }

                if (!isset($canonical_groups[$label])) {
                    $canonical_groups[$label] = array();
                }
                $canonical_groups[$label][] = $value;
            }

            foreach ($canonical_groups as $label => $values) {
                $append_attribute_values($label, $values);
            }
        }

        /* Datos físicos de WooCommerce que también son comparables. */
        $weight = trim((string) $compare_product->get_weight('edit'));
        if ($weight !== '' && is_numeric($weight) && (float) $weight > 0) {
            $weight_text = function_exists('wc_format_weight')
                ? wc_format_weight($weight)
                : $weight . ' ' . get_option('woocommerce_weight_unit', 'kg');
            $append_attribute_values('Peso', array($weight_text), true);
        }

        $dimensions = $compare_product->get_dimensions(false);
        if (is_array($dimensions)) {
            $dimension_parts = array();
            foreach (array('length' => 'L', 'width' => 'An', 'height' => 'Al') as $key => $prefix) {
                $value = trim((string) ($dimensions[$key] ?? ''));
                if ($value === '' || !is_numeric($value) || (float) $value <= 0) {
                    continue;
                }
                $dimension_parts[] = $prefix . ' ' . (
                    function_exists('wc_format_localized_decimal')
                        ? wc_format_localized_decimal($value)
                        : $value
                );
            }
            if ($dimension_parts) {
                $append_attribute_values(
                    'Dimensiones',
                    array(implode(' × ', $dimension_parts) . ' ' . get_option('woocommerce_dimension_unit', 'cm')),
                    true
                );
            }
        }

        /* Atributos WooCommerce solo como fallback de información legacy. */
        foreach ((array) $compare_product->get_attributes() as $attribute) {
            if (!is_a($attribute, 'WC_Product_Attribute')) {
                continue;
            }

            $attribute_name = (string) $attribute->get_name();
            $attribute_label = function_exists('wc_attribute_label')
                ? (string) wc_attribute_label($attribute_name, $compare_product)
                : $attribute_name;

            if ($attribute_label === '' || $attribute_label === $attribute_name) {
                $attribute_label = $format_attribute_label($attribute_name);
            }

            $values = array();
            if ($attribute->is_taxonomy()) {
                $values = function_exists('wc_get_product_terms')
                    ? wc_get_product_terms($product_id, $attribute_name, array('fields' => 'names'))
                    : array();
            } else {
                $values = (array) $attribute->get_options();
            }

            if (is_wp_error($values)) {
                $values = array();
            }

            $append_attribute_values($attribute_label, $values, true);
        }

        /*
         * Etiquetas semánticas canónicas. Se presentan por grupo para que la
         * comparación sea útil y no como una bolsa de product_tag de WooCommerce.
         */
        global $wpdb;
        $semantic_labels = array(
            'tipo'       => 'Tipo',
            'rol'        => 'Rol',
            'aplicacion' => 'Aplicación',
            'plataforma' => 'Plataforma',
            'subtipo'    => 'Subtipo',
        );

        $vocabulary_table = $wpdb->prefix . 'seo_vocabulary';
        $object_table = $wpdb->prefix . 'seo_object_vocabulary';
        $semantic_available = function_exists('seo_catalog_table_exists')
            && seo_catalog_table_exists($vocabulary_table)
            && seo_catalog_table_exists($object_table);

        if ($semantic_available) {
            $semantic_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT v.semantic_group, v.label
                     FROM {$object_table} ov
                     INNER JOIN {$vocabulary_table} v
                        ON v.id = ov.vocabulary_id
                       AND v.active = 1
                     WHERE ov.object_type = 'product'
                       AND ov.object_id = %d
                       AND ov.status = 1
                       AND v.semantic_group IN ('tipo','rol','aplicacion','plataforma','subtipo')
                     ORDER BY FIELD(v.semantic_group,'tipo','rol','aplicacion','plataforma','subtipo'),
                              v.label ASC",
                    $product_id
                ),
                ARRAY_A
            );

            $semantic_groups = array();
            foreach ((array) $semantic_rows as $row) {
                $group = sanitize_key((string) ($row['semantic_group'] ?? ''));
                $label = trim((string) ($row['label'] ?? ''));
                if ($group === '' || $label === '' || !isset($semantic_labels[$group])) {
                    continue;
                }
                if (!isset($semantic_groups[$group])) {
                    $semantic_groups[$group] = array();
                }
                $semantic_groups[$group][] = $label;
            }

            foreach ($semantic_labels as $group => $display_label) {
                if (!empty($semantic_groups[$group])) {
                    $append_attribute_values($display_label, $semantic_groups[$group]);
                }
            }
        }

        /*
         * La comparativa pública muestra un único precio: el precio efectivo
         * actual de WooCommerce para este producto. Si hay una oferta activa,
         * se muestra el precio de oferta; si no, el precio normal. No se muestran
         * rangos, precios de Ojeador ni el precio anterior tachado.
         */
        $price_text = '';
        $effective_price = $compare_product->get_price('view');
        if ('' !== (string) $effective_price && is_numeric($effective_price)) {
            $display_price = function_exists('wc_get_price_to_display')
                ? wc_get_price_to_display($compare_product, array('price' => (float) $effective_price))
                : (float) $effective_price;
            $price_html = function_exists('wc_price')
                ? wc_price($display_price)
                : (string) $display_price;
            $price_text = html_entity_decode(
                wp_strip_all_tags((string) $price_html, true),
                ENT_QUOTES | ENT_HTML5,
                (string) get_bloginfo('charset') ?: 'UTF-8'
            );
            $price_text = str_replace("\xC2\xA0", ' ', $price_text);
            $price_text = trim((string) preg_replace('/[\\s\\x{00A0}]+/u', ' ', $price_text));
        }

        $excerpt = trim(wp_strip_all_tags((string) $compare_product->get_short_description()));
        if ($excerpt === '') {
            $excerpt = trim(wp_strip_all_tags((string) $compare_product->get_description()));
        }
        $excerpt = $excerpt !== '' ? wp_trim_words($excerpt, 26, '…') : '';

        $image_url = '';
        $image_candidates = dht_shared_product_image_candidates(
            $product_id,
            'woocommerce_thumbnail',
            $supplier_limit,
            true
        );
        if (!empty($image_candidates[0]['url'])) {
            $image_url = esc_url_raw((string) $image_candidates[0]['url']);
        }

        return array(
            'id'         => $product_id,
            'name'       => wp_strip_all_tags((string) $compare_product->get_name()),
            'url'        => esc_url_raw((string) get_permalink($product_id)),
            'image'      => $image_url,
            'price'      => $price_text,
            'excerpt'    => $excerpt,
            'tags'       => array(),
            'attributes' => $attributes,
        );
    }
}

if (!function_exists('dht_shared_render_category_compare_assets')) {
    function dht_shared_render_category_compare_assets()
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;
        ?>
        <style id="dht-category-live-compare-styles">
        .dht-category-product-grid .dh-product-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .dht-category-compare-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 8px 12px;
            border: 1px solid var(--dht-border-color, #d1d5db);
            border-radius: 8px;
            background: var(--dht-surface, #fff);
            color: inherit;
            font: inherit;
            font-weight: 700;
            line-height: 1.2;
            cursor: pointer;
        }
        .dht-category-compare-toggle[aria-pressed="true"] {
            border-color: currentColor;
            box-shadow: inset 0 0 0 1px currentColor;
        }
        .dht-category-compare-toggle:disabled {
            opacity: .45;
            cursor: not-allowed;
        }
        .dht-category-live-compare {
            margin-top: 20px;
            padding: 16px;
            border: 1px solid var(--dht-border-color, #e5e7eb);
            border-radius: 12px;
            background: var(--dht-surface, #fff);
        }
        .dht-category-live-compare[hidden],
        .dht-category-live-compare-result[hidden] {
            display: none !important;
        }
        .dht-category-live-compare-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .dht-category-live-compare-count {
            margin-right: auto;
            font-weight: 700;
        }
        .dht-category-live-compare-toolbar button {
            min-height: 38px;
            padding: 8px 13px;
            border: 1px solid var(--dht-border-color, #d1d5db);
            border-radius: 8px;
            background: var(--dht-surface, #fff);
            color: inherit;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }
        .dht-category-live-compare-toolbar button:disabled {
            opacity: .45;
            cursor: not-allowed;
        }
        .dht-category-live-compare-note {
            flex-basis: 100%;
            margin: 0;
            font-size: .92em;
            opacity: .75;
        }
        .dht-category-live-compare-result {
            margin-top: 18px;
        }
        .dht-category-live-compare-result h3 {
            margin: 0 0 12px;
        }
        .dht-category-live-compare-table-wrap {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .dht-category-live-compare-table {
            width: 100%;
            min-width: 760px;
            border-collapse: collapse;
        }
        .dht-category-live-compare-table th,
        .dht-category-live-compare-table td {
            padding: 12px 13px;
            border-bottom: 1px solid var(--dht-border-color, #e5e7eb);
            text-align: left;
            vertical-align: top;
        }
        .dht-category-live-compare-table thead th {
            background: var(--dht-surface-soft, #f8fafc);
            vertical-align: bottom;
        }
        .dht-category-live-compare-table tbody th {
            width: 180px;
            font-weight: 700;
            background: var(--dht-surface, #fff);
        }
        .dht-category-live-compare-table thead th:first-child,
        .dht-category-live-compare-table tbody tr:not(.dht-compare-section) > th:first-child {
            position: sticky;
            left: 0;
            z-index: 2;
        }
        .dht-category-live-compare-table thead th:first-child {
            z-index: 3;
        }
        .dht-category-live-compare-table .dht-compare-section th {
            padding-top: 14px;
            padding-bottom: 10px;
            background: var(--dht-surface-soft, #f8fafc);
            font-size: .92em;
            letter-spacing: .02em;
            text-transform: uppercase;
        }
        .dht-category-live-compare-table .dht-compare-different > th {
            box-shadow: inset 3px 0 0 currentColor;
        }
        .dht-category-live-compare-table .dht-compare-different > td {
            font-weight: 700;
        }
        .dht-category-live-compare-table td {
            min-width: 170px;
        }
        .dht-category-live-compare-product {
            display: grid;
            gap: 8px;
            min-width: 150px;
        }
        .dht-category-live-compare-product img {
            width: 72px;
            height: 72px;
            object-fit: contain;
            border-radius: 8px;
            background: #fff;
        }
        .dht-category-live-compare-product a {
            font-weight: 700;
            text-decoration: none;
        }
        .dht-category-live-compare-product-description {
            margin: 0;
            max-width: 320px;
            font-size: .84em;
            line-height: 1.4;
            font-weight: 400;
            opacity: .72;
        }
        @media (max-width: 767px) {
            .dht-category-live-compare {
                padding: 12px;
            }
            .dht-category-live-compare-toolbar button {
                flex: 1 1 auto;
            }
            .dht-category-live-compare-table th,
            .dht-category-live-compare-table td {
                padding: 10px 11px;
            }
        }
        </style>
        <script id="dht-category-live-compare-script">
        (function () {
            'use strict';

            function text(value) {
                return value === null || value === undefined || value === '' ? '—' : String(value);
            }

            function appendTextCell(row, value) {
                var cell = document.createElement('td');
                cell.textContent = text(value);
                row.appendChild(cell);
            }

            function initCompare(root) {
                if (!root || root.getAttribute('data-dht-compare-ready') === '1') {
                    return;
                }

                var dataId = root.getAttribute('data-dht-compare-data-id');
                var dataNode = dataId ? document.getElementById(dataId) : null;
                if (!dataNode) {
                    return;
                }

                var products;
                try {
                    products = JSON.parse(dataNode.textContent || '[]');
                } catch (error) {
                    return;
                }

                if (!Array.isArray(products) || products.length < 2) {
                    return;
                }

                root.setAttribute('data-dht-compare-ready', '1');

                var byId = {};
                products.forEach(function (product) {
                    byId[String(product.id)] = product;
                });

                var compareMax = Math.max(2, Math.min(6, Number(root.getAttribute('data-dht-compare-max') || 6)));
                var selected = [];
                var toolbar = root.querySelector('[data-dht-compare-toolbar]');
                var countNode = root.querySelector('[data-dht-compare-count]');
                var showButton = root.querySelector('[data-dht-compare-show]');
                var pdfButton = root.querySelector('[data-dht-compare-pdf]');
                var clearButton = root.querySelector('[data-dht-compare-clear]');
                var result = root.querySelector('[data-dht-compare-result]');
                var toggles = Array.prototype.slice.call(root.querySelectorAll('[data-dht-compare-product]'));

                function sync() {
                    toggles.forEach(function (button) {
                        var id = String(button.getAttribute('data-dht-compare-product') || '');
                        var active = selected.indexOf(id) !== -1;
                        button.setAttribute('aria-pressed', active ? 'true' : 'false');
                        button.textContent = active ? 'Seleccionado' : 'Comparar';
                        button.disabled = !active && selected.length >= compareMax;
                    });

                    if (toolbar) {
                        toolbar.hidden = selected.length === 0;
                    }
                    if (countNode) {
                        countNode.textContent = selected.length === 1
                            ? '1 producto seleccionado'
                            : selected.length + ' productos seleccionados';
                    }
                    if (showButton) {
                        showButton.disabled = selected.length < 2;
                    }
                    if (pdfButton) {
                        pdfButton.disabled = selected.length < 2;
                    }
                    if (result && selected.length < 2) {
                        result.hidden = true;
                        result.innerHTML = '';
                    }
                }

                function downloadComparisonPdf() {
                    if (selected.length < 2) {
                        return;
                    }

                    var pdfUrl = String(root.getAttribute('data-dht-compare-pdf-url') || '');
                    var pdfNonce = String(root.getAttribute('data-dht-compare-pdf-nonce') || '');
                    if (!pdfUrl || !pdfNonce) {
                        return;
                    }

                    var form = document.createElement('form');
                    form.method = 'POST';
                    form.action = pdfUrl;
                    form.target = '_blank';
                    form.style.display = 'none';

                    var fields = {
                        action: 'seo_dependiente_compare_pdf',
                        nonce: pdfNonce
                    };
                    Object.keys(fields).forEach(function (name) {
                        var input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = name;
                        input.value = fields[name];
                        form.appendChild(input);
                    });

                    selected.slice(0, compareMax).forEach(function (id) {
                        var input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'ids[]';
                        input.value = String(id);
                        form.appendChild(input);
                    });

                    document.body.appendChild(form);
                    form.submit();
                    window.setTimeout(function () {
                        form.remove();
                    }, 1000);
                }

                function renderComparison() {
                    if (!result || selected.length < 2) {
                        return;
                    }

                    var chosen = selected.map(function (id) {
                        return byId[id];
                    }).filter(Boolean);
                    if (chosen.length < 2) {
                        return;
                    }

                    try {
                        window.sessionStorage.setItem(
                            'seoLastRequestedComparison',
                            JSON.stringify(selected.slice(0, compareMax).map(function (id) { return Number(id); }).filter(Boolean))
                        );
                    } catch (storageError) { /* storage can be unavailable */ }

                    var normalizeLabel = function (value) {
                        return String(value || '')
                            .normalize('NFD')
                            .replace(/[\u0300-\u036f]/g, '')
                            .toLowerCase()
                            .trim();
                    };

                    var semanticPriority = {
                        'tipo': 10,
                        'rol': 20,
                        'aplicacion': 30,
                        'plataforma': 40,
                        'subtipo': 50
                    };

                    var technicalPriority = {
                        'marca': 10,
                        'fabricante': 20,
                        'modelo': 30,
                        'potencia': 40,
                        'presion': 50,
                        'tension': 60,
                        'voltaje': 60,
                        'caudal': 70,
                        'tecnologia de accionamiento': 80,
                        'accionamiento': 80,
                        'numero de etapas': 90,
                        'etapas': 90,
                        'capacidad': 100,
                        'material': 110,
                        'dimensiones': 400,
                        'peso': 410
                    };

                    var attributeLabels = [];
                    chosen.forEach(function (product) {
                        var attributes = product.attributes || {};
                        Object.keys(attributes).forEach(function (label) {
                            if (attributeLabels.indexOf(label) === -1) {
                                attributeLabels.push(label);
                            }
                        });
                    });

                    var semanticLabels = attributeLabels.filter(function (label) {
                        return Object.prototype.hasOwnProperty.call(semanticPriority, normalizeLabel(label));
                    });
                    var technicalLabels = attributeLabels.filter(function (label) {
                        return !Object.prototype.hasOwnProperty.call(semanticPriority, normalizeLabel(label));
                    });

                    semanticLabels.sort(function (a, b) {
                        return (semanticPriority[normalizeLabel(a)] || 999)
                            - (semanticPriority[normalizeLabel(b)] || 999);
                    });
                    technicalLabels.sort(function (a, b) {
                        var ap = technicalPriority[normalizeLabel(a)] || 999;
                        var bp = technicalPriority[normalizeLabel(b)] || 999;
                        if (ap !== bp) {
                            return ap - bp;
                        }
                        return a.localeCompare(b, 'es', {sensitivity: 'base'});
                    });

                    result.innerHTML = '';
                    result.hidden = false;

                    var title = document.createElement('h3');
                    title.textContent = 'Comparación de productos';
                    result.appendChild(title);

                    var wrap = document.createElement('div');
                    wrap.className = 'dht-category-live-compare-table-wrap';
                    var table = document.createElement('table');
                    table.className = 'dht-category-live-compare-table';

                    var thead = document.createElement('thead');
                    var headRow = document.createElement('tr');
                    var featureHead = document.createElement('th');
                    featureHead.scope = 'col';
                    featureHead.textContent = 'Característica';
                    headRow.appendChild(featureHead);

                    chosen.forEach(function (product) {
                        var th = document.createElement('th');
                        th.scope = 'col';
                        var productBox = document.createElement('div');
                        productBox.className = 'dht-category-live-compare-product';

                        if (product.image) {
                            var img = document.createElement('img');
                            img.src = product.image;
                            img.alt = product.name || '';
                            img.loading = 'lazy';
                            productBox.appendChild(img);
                        }

                        var link = document.createElement('a');
                        link.href = product.url || '#';
                        link.textContent = product.name || 'Producto';
                        productBox.appendChild(link);

                        if (product.excerpt) {
                            var description = document.createElement('p');
                            description.className = 'dht-category-live-compare-product-description';
                            description.textContent = product.excerpt;
                            productBox.appendChild(description);
                        }

                        th.appendChild(productBox);
                        headRow.appendChild(th);
                    });
                    thead.appendChild(headRow);
                    table.appendChild(thead);

                    var tbody = document.createElement('tbody');

                    function appendSection(label) {
                        var row = document.createElement('tr');
                        row.className = 'dht-compare-section';
                        var cell = document.createElement('th');
                        cell.colSpan = chosen.length + 1;
                        cell.textContent = label;
                        row.appendChild(cell);
                        tbody.appendChild(row);
                    }

                    function appendComparisonRow(label, valueResolver) {
                        var values = chosen.map(function (product) {
                            return text(valueResolver(product));
                        });
                        var normalizedValues = values.map(function (value) {
                            return normalizeLabel(value);
                        });
                        var uniqueValues = normalizedValues.filter(function (value, index, all) {
                            return all.indexOf(value) === index;
                        });

                        var row = document.createElement('tr');
                        if (uniqueValues.length > 1) {
                            row.className = 'dht-compare-different';
                        }

                        var rowHead = document.createElement('th');
                        rowHead.scope = 'row';
                        rowHead.textContent = label;
                        row.appendChild(rowHead);

                        values.forEach(function (value) {
                            appendTextCell(row, value);
                        });
                        tbody.appendChild(row);
                    }

                    appendSection('Datos comerciales');
                    appendComparisonRow('Precio', function (product) {
                        return product.price;
                    });

                    if (semanticLabels.length) {
                        appendSection('Clasificación');
                        semanticLabels.forEach(function (label) {
                            appendComparisonRow(label, function (product) {
                                var attrs = product.attributes || {};
                                return attrs[label] || '—';
                            });
                        });
                    }

                    if (technicalLabels.length) {
                        appendSection('Características técnicas');
                        technicalLabels.forEach(function (label) {
                            appendComparisonRow(label, function (product) {
                                var attrs = product.attributes || {};
                                return attrs[label] || '—';
                            });
                        });
                    }

                    table.appendChild(tbody);
                    wrap.appendChild(table);
                    result.appendChild(wrap);
                }

                root.addEventListener('click', function (event) {
                    var toggle = event.target.closest('[data-dht-compare-product]');
                    if (toggle && root.contains(toggle)) {
                        var id = String(toggle.getAttribute('data-dht-compare-product') || '');
                        if (!byId[id]) {
                            return;
                        }
                        var index = selected.indexOf(id);
                        if (index !== -1) {
                            selected.splice(index, 1);
                        } else if (selected.length < compareMax) {
                            selected.push(id);
                        }
                        sync();
                        return;
                    }

                    if (event.target.closest('[data-dht-compare-show]')) {
                        renderComparison();
                        return;
                    }

                    if (event.target.closest('[data-dht-compare-pdf]')) {
                        downloadComparisonPdf();
                        return;
                    }

                    if (event.target.closest('[data-dht-compare-clear]')) {
                        selected = [];
                        if (result) {
                            result.hidden = true;
                            result.innerHTML = '';
                        }
                        sync();
                    }
                });

                sync();
            }

            function initAll() {
                document.querySelectorAll('[data-dht-category-compare]').forEach(initCompare);
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initAll);
            } else {
                initAll();
            }
        }());
        </script>
        <?php
    }
}

if (!function_exists('dht_shared_render_product_card')) {
    function dht_shared_render_product_card($card_product, $supplier_limit = 3, $enable_compare = false)
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
            echo '<span class="onsale">' . esc_html__('Oferta', 'seo-taxonomy') . '</span>';
        }

        echo '<div class="dh-product-image">';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared image helper returns context-escaped HTML and may include functional fallback attributes.
        echo dht_shared_product_card_image_html($card_product, 'woocommerce_thumbnail', $supplier_limit);
        echo '</div>';
        echo '<div class="dh-product-title">' . esc_html($card_product->get_name()) . '</div>';
        echo '<div class="dh-product-price">' . wp_kses_post($card_product->get_price_html()) . '</div>';
        echo '</a>';
        echo '<div class="dh-product-actions">';
        if (function_exists('woocommerce_template_loop_add_to_cart')) {
            woocommerce_template_loop_add_to_cart();
        }
        if ($enable_compare) {
            echo '<button type="button" class="dht-category-compare-toggle" data-dht-compare-product="' . esc_attr((string) $card_product->get_id()) . '" aria-pressed="false">Comparar</button>';
        }
        echo '</div>';
        echo '</li>';

        wp_reset_postdata();
        $product = $previous_product;
        $post = $previous_post;
    }
}

if (!function_exists('dht_shared_render_product_grid')) {
    function dht_shared_render_product_grid($products, $extra_class = '', $supplier_limit = 3, $enable_compare = false)
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

        $compare_data = array();
        if ($enable_compare) {
            foreach ($valid as $candidate) {
                $item_data = dht_shared_product_compare_data($candidate, $supplier_limit);
                if ($item_data) {
                    $compare_data[] = $item_data;
                }
            }
        }

        if ($enable_compare && count($compare_data) >= 2) {
            static $compare_instance = 0;
            $compare_instance++;
            $compare_id = 'dht-category-compare-' . $compare_instance;
            $data_id = $compare_id . '-data';
            $compare_max = function_exists('seo_comparador_store_compare_max') ? seo_comparador_store_compare_max() : 6;

            echo '<div class="dht-category-compare-scope" data-dht-category-compare data-dht-compare-max="' . esc_attr((string) $compare_max) . '" data-dht-compare-data-id="' . esc_attr($data_id) . '" data-dht-compare-pdf-url="' . esc_url(admin_url('admin-post.php')) . '" data-dht-compare-pdf-nonce="' . esc_attr(wp_create_nonce('seo_dependiente_compare_pdf')) . '">';
        }

        echo '<ul class="products ' . esc_attr($extra_class) . '">';
        foreach ($valid as $candidate) {
            dht_shared_render_product_card($candidate, $supplier_limit, $enable_compare && count($compare_data) >= 2);
        }
        echo '</ul>';

        if ($enable_compare && count($compare_data) >= 2) {
            echo '<div class="dht-category-live-compare" data-dht-compare-toolbar hidden>';
            echo '<div class="dht-category-live-compare-toolbar">';
            echo '<span class="dht-category-live-compare-count" data-dht-compare-count>0 productos seleccionados</span>';
            echo '<button type="button" data-dht-compare-show disabled>Comparar seleccionados</button>';
            echo '<button type="button" data-dht-compare-pdf disabled>Descargar comparativa PDF</button>';
            echo '<button type="button" data-dht-compare-clear>Limpiar</button>';
            echo '<p class="dht-category-live-compare-note">Selecciona entre 2 y ' . esc_html((string) $compare_max) . ' productos. La comparativa usa la clasificación y los atributos canónicos disponibles en el catálogo.</p>';
            echo '</div>';
            echo '<div class="dht-category-live-compare-result" data-dht-compare-result hidden></div>';
            echo '</div>';
            echo '<script type="application/json" id="' . esc_attr($data_id) . '">' . wp_json_encode($compare_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
            echo '</div>';

            dht_shared_render_category_compare_assets();
        }
    }
}

if (!function_exists('dht_template_products_for_category_ids')) {
    /**
     * Devuelve productos publicados y visibles de las categorías comerciales
     * relacionadas con un contenido editorial.
     *
     * La selección no se infiere por texto: recibe term_id explícitos
     * (normalmente procedentes de post_to_category) y devuelve hasta $limit
     * productos listos para el render compartido de tarjetas.
     */
    function dht_template_products_for_category_ids($term_ids, $limit = 8)
    {
        $term_ids = array_values(array_unique(array_filter(array_map('absint', (array) $term_ids))));
        $limit = max(1, min(24, absint($limit)));

        if (!$term_ids || !function_exists('wc_get_product')) {
            return array();
        }

        $tax_query = array(
            array(
                'taxonomy'         => 'product_cat',
                'field'            => 'term_id',
                'terms'            => $term_ids,
                'include_children' => true,
                'operator'         => 'IN',
            ),
        );

        if (function_exists('wc_get_product_visibility_term_ids')) {
            $visibility = (array) wc_get_product_visibility_term_ids();
            $excluded = array_filter(array(
                absint($visibility['exclude-from-catalog'] ?? 0),
            ));

            if ($excluded) {
                $tax_query['relation'] = 'AND';
                $tax_query[] = array(
                    'taxonomy' => 'product_visibility',
                    'field'    => 'term_id',
                    'terms'    => array_values($excluded),
                    'operator' => 'NOT IN',
                );
            }
        }

        $query = new WP_Query(array(
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => $limit,
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'orderby'                => array(
                'menu_order' => 'ASC',
                'date'       => 'DESC',
            ),
            'tax_query'              => $tax_query,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ));

        $products = array();

        foreach ((array) $query->posts as $product_post) {
            try {
                $product = wc_get_product($product_post->ID);
                if (!$product || !is_a($product, 'WC_Product')) {
                    continue;
                }
                if (method_exists($product, 'is_visible') && !$product->is_visible()) {
                    continue;
                }
                $products[] = $product;
            } catch (Throwable $e) {
                error_log('[DHT template] producto relacionado no disponible ID ' . absint($product_post->ID) . ': ' . $e->getMessage());
            }
        }

        wp_reset_postdata();

        return array_slice($products, 0, $limit);
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
   CONTENIDO CONTEXTUAL EN PRODUCTOS Y CATEGORIAS
   Dependiente / Comentarista / Ingeniero.

   Las plantillas leen exclusivamente contenido ya persistido:
   - posts publicados con rol editorial estable;
   - relaciones post_to_category;
   - comentarios externos published de Comentarista.
   Nunca ejecutan Dependiente, Ingeniero, Solucionador u Ojeador
   durante la visita.
========================================================== */
if (!function_exists('dht_template_product_context_category_ids')) {
    function dht_template_product_context_category_ids($product_id, $limit = 18)
    {
        $product_id = absint($product_id);
        $limit = max(1, absint($limit));
        if ($product_id < 1) {
            return array();
        }

        $term_ids = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
        if (is_wp_error($term_ids) || empty($term_ids)) {
            return array();
        }

        $ranked = array();
        foreach (array_values(array_unique(array_map('absint', (array) $term_ids))) as $term_id) {
            if ($term_id < 1) {
                continue;
            }

            $depth = count(get_ancestors($term_id, 'product_cat', 'taxonomy'));
            $ranked[$term_id] = max($depth, $ranked[$term_id] ?? -1);

            /*
             * Un producto puede heredar una guia de su familia padre.
             * La categoria mas especifica conserva prioridad.
             */
            $ancestor_depth = $depth - 1;
            foreach (get_ancestors($term_id, 'product_cat', 'taxonomy') as $ancestor_id) {
                $ancestor_id = absint($ancestor_id);
                if ($ancestor_id < 1) {
                    continue;
                }
                $ranked[$ancestor_id] = max($ancestor_depth, $ranked[$ancestor_id] ?? -1);
                $ancestor_depth--;
            }
        }

        arsort($ranked, SORT_NUMERIC);
        return array_slice(array_keys($ranked), 0, $limit);
    }
}

if (!function_exists('dht_template_context_posts_for_categories')) {
    /**
     * Devuelve posts publicos relacionados explicitamente con product_cat
     * y clasificados para el bloque solicitado.
     */
    function dht_template_context_posts_for_categories($category_ids, $role, $limit = 4)
    {
        global $wpdb;

        $category_ids = array_values(array_unique(array_filter(array_map('absint', (array) $category_ids))));
        $role = sanitize_key((string) $role);
        $limit = max(1, min(8, absint($limit)));

        if (
            empty($category_ids)
            || !in_array($role, array('dependiente_qa_basic', 'ingeniero_qa_specialized'), true)
        ) {
            return array();
        }

        $relations_table = $wpdb->prefix . 'seo_relations';
        $exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($relations_table))
        );
        if ($exists !== $relations_table) {
            return array();
        }

        $cache_key = 'ctx_posts_' . md5(implode(',', $category_ids) . '|' . $role . '|' . $limit);
        $cached = wp_cache_get($cache_key, 'dht_template');
        if (is_array($cached)) {
            return array_values(array_filter(array_map('get_post', $cached)));
        }

        $placeholders = implode(',', array_fill(0, count($category_ids), '%d'));
        $query = "
            SELECT DISTINCT p.ID
            FROM %i r
            INNER JOIN %i p
                ON p.ID = r.source_id
               AND p.post_type = 'post'
               AND p.post_status = 'publish'
            INNER JOIN %i pm
                ON pm.post_id = p.ID
               AND pm.meta_key = %s
               AND pm.meta_value = %s
            WHERE r.source_type = 'post'
              AND r.target_type = 'product_cat'
              AND r.relation_type = 'post_to_category'
              AND r.target_id IN ({$placeholders})
            ORDER BY p.post_modified_gmt DESC, p.ID DESC
            LIMIT %d
        ";

        $params = array(
            $relations_table,
            $wpdb->posts,
            $wpdb->postmeta,
            '_seo_solucionador_content_role',
            $role,
        );
        foreach ($category_ids as $category_id) {
            $params[] = $category_id;
        }
        $params[] = $limit;

        $post_ids = (array) $wpdb->get_col($wpdb->prepare($query, $params));
        $post_ids = array_values(array_unique(array_filter(array_map('absint', $post_ids))));
        wp_cache_set($cache_key, $post_ids, 'dht_template', 300);

        return array_values(array_filter(array_map('get_post', $post_ids)));
    }
}

if (!function_exists('dht_template_context_post_excerpt')) {
    function dht_template_context_post_excerpt($post, $words = 28)
    {
        $post = get_post($post);
        if (!$post instanceof WP_Post || 'publish' !== $post->post_status) {
            return '';
        }

        $excerpt = trim(wp_strip_all_tags((string) $post->post_excerpt));
        if ($excerpt !== '') {
            return wp_trim_words($excerpt, $words, '…');
        }

        $plain = trim(wp_strip_all_tags(strip_shortcodes((string) $post->post_content)));
        return $plain !== '' ? wp_trim_words($plain, $words, '…') : '';
    }
}

if (!function_exists('dht_template_render_context_posts')) {
    /**
     * Render ligero: titulo + contexto breve + enlace al post canonico.
     * No replica respuestas completas en producto/categoria.
     */
    function dht_template_render_context_posts($posts, $title, $variant = 'dependiente', $context = 'product')
    {
        $valid = array();
        foreach ((array) $posts as $post) {
            $post = get_post($post);
            if (
                !$post instanceof WP_Post
                || 'post' !== $post->post_type
                || 'publish' !== $post->post_status
                || trim((string) $post->post_title) === ''
                || !get_permalink($post)
            ) {
                continue;
            }
            $valid[] = $post;
        }

        if (!$valid) {
            return;
        }

        $variant = in_array($variant, array('dependiente', 'ingeniero'), true) ? $variant : 'dependiente';
        $context = in_array($context, array('product', 'category'), true) ? $context : 'product';
        $kicker = 'ingeniero' === $variant ? 'Conocimiento técnico' : 'Ayuda para elegir';
        $link_label = 'ingeniero' === $variant ? 'Leer información técnica' : 'Ver respuesta';
        ?>
        <section class="dht-context-posts dht-context-posts--<?php echo esc_attr($variant); ?> dht-context-posts--<?php echo esc_attr($context); ?>">
            <?php if ('category' === $context) : ?><div class="dht-container"><?php endif; ?>
            <header class="dht-context-posts__header">
                <span class="dht-context-posts__kicker"><?php echo esc_html($kicker); ?></span>
                <h2><?php echo esc_html($title); ?></h2>
            </header>
            <div class="dht-context-posts__grid">
                <?php foreach ($valid as $context_post) : ?>
                    <?php
                    $context_excerpt = dht_template_context_post_excerpt($context_post, 30);
                    $context_url = get_permalink($context_post);
                    ?>
                    <article class="dht-context-post-card">
                        <h3>
                            <a href="<?php echo esc_url($context_url); ?>">
                                <?php echo esc_html($context_post->post_title); ?>
                            </a>
                        </h3>
                        <?php if ($context_excerpt !== '') : ?>
                            <p><?php echo esc_html($context_excerpt); ?></p>
                        <?php endif; ?>
                        <a class="dht-context-post-card__link" href="<?php echo esc_url($context_url); ?>">
                            <?php echo esc_html($link_label); ?> →
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if ('category' === $context) : ?></div><?php endif; ?>
        </section>
        <?php
    }
}

if (!function_exists('dht_template_product_external_comments')) {
    function dht_template_product_external_comments($product_id, $limit = 12)
    {
        $product_id = absint($product_id);
        $limit = max(1, min(24, absint($limit)));
        if (
            $product_id < 1
            || !function_exists('seo_comentarista_get_by_product')
        ) {
            return array();
        }

        $rows = (array) seo_comentarista_get_by_product($product_id, 'published', $limit * 2);
        $rows = array_values(array_filter(
            $rows,
            static function ($row) {
                return 'comment' === sanitize_key((string) ($row['content_type'] ?? ''));
            }
        ));

        return array_slice($rows, 0, $limit);
    }
}

if (!function_exists('dht_template_category_external_comments')) {
    function dht_template_category_external_comments($term_id, $limit = 6, $per_product = 2)
    {
        global $wpdb;

        $term_id = absint($term_id);
        $limit = max(1, min(12, absint($limit)));
        $per_product = max(1, min(4, absint($per_product)));

        if (
            $term_id < 1
            || !function_exists('seo_comentarista_table_exists')
            || !function_exists('seo_comentarista_table_name')
            || !seo_comentarista_table_exists()
        ) {
            return array();
        }

        $term_ids = array($term_id);
        $children = get_term_children($term_id, 'product_cat');
        if (!is_wp_error($children)) {
            $term_ids = array_values(array_unique(array_merge(
                $term_ids,
                array_filter(array_map('absint', (array) $children))
            )));
        }

        $cache_key = 'ctx_comments_' . md5(implode(',', $term_ids) . '|' . $limit . '|' . $per_product);
        $cached = wp_cache_get($cache_key, 'dht_template');
        if (is_array($cached)) {
            return $cached;
        }

        $placeholders = implode(',', array_fill(0, count($term_ids), '%d'));
        $scan_limit = max(30, $limit * 8);
        $query = "
            SELECT DISTINCT c.*, p.post_title AS product_title
            FROM %i c
            INNER JOIN %i p
                ON p.ID = c.product_id
               AND p.post_type = 'product'
               AND p.post_status = 'publish'
            INNER JOIN %i tr ON tr.object_id = c.product_id
            INNER JOIN %i tt
                ON tt.term_taxonomy_id = tr.term_taxonomy_id
               AND tt.taxonomy = 'product_cat'
            WHERE c.status = 'published'
              AND c.content_type = 'comment'
              AND tt.term_id IN ({$placeholders})
            ORDER BY c.display_order ASC, c.id DESC
            LIMIT %d
        ";

        $params = array(
            seo_comentarista_table_name(),
            $wpdb->posts,
            $wpdb->term_relationships,
            $wpdb->term_taxonomy,
        );
        foreach ($term_ids as $category_id) {
            $params[] = $category_id;
        }
        $params[] = $scan_limit;

        $rows = (array) $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);
        $out = array();
        $product_counts = array();

        foreach ($rows as $row) {
            $product_id = absint($row['product_id'] ?? 0);
            if ($product_id < 1) {
                continue;
            }
            if (($product_counts[$product_id] ?? 0) >= $per_product) {
                continue;
            }

            $row['product_url'] = get_permalink($product_id);
            $out[] = $row;
            $product_counts[$product_id] = ($product_counts[$product_id] ?? 0) + 1;

            if (count($out) >= $limit) {
                break;
            }
        }

        wp_cache_set($cache_key, $out, 'dht_template', 300);
        return $out;
    }
}

if (!function_exists('dht_template_external_comment_text')) {
    function dht_template_external_comment_text($row)
    {
        $text = !empty($row['source_content'])
            ? (string) $row['source_content']
            : (string) ($row['editorial_summary'] ?? '');

        $text = preg_replace(
            '/^\s*\[Resumen editorial,\s*no cita literal\]\s*/iu',
            '',
            $text
        );

        return trim((string) $text);
    }
}

if (!function_exists('dht_template_render_external_comments')) {
    /**
     * Renderiza comentarios externos persistidos por Comentarista.
     *
     * La seccion solo se imprime cuando existe al menos un comentario con texto
     * valido. Los comentarios quedan plegados inicialmente para no saturar la
     * ficha y mantienen separada cualquier valoracion externa de las reseñas
     * WooCommerce de la tienda.
     */
    function dht_template_render_external_comments($rows, $title, $context = 'product')
    {
        $context = in_array($context, array('product', 'category'), true) ? $context : 'product';
        $valid = array();

        foreach ((array) $rows as $row) {
            if (dht_template_external_comment_text($row) !== '') {
                $valid[] = $row;
            }
        }

        if (!$valid) {
            return;
        }

        $comment_count = count($valid);
        $summary_label = sprintf(
            /* translators: %d: número de comentarios externos disponibles. */
            _n(
                'Ver %d comentario externo',
                'Ver %d comentarios externos',
                $comment_count,
                'seo-taxonomy'
            ),
            $comment_count
        );
        ?>
        <section class="dht-external-comments dht-external-comments--<?php echo esc_attr($context); ?> seo-comentarista">
            <?php if ('category' === $context) : ?><div class="dht-container"><?php endif; ?>
            <header class="dht-external-comments__header">
                <span class="dht-context-posts__kicker">Experiencias externas</span>
                <h2><?php echo esc_html($title); ?></h2>
                <p>Opiniones y experiencias publicadas en fuentes externas. No son reseñas de clientes de Distribuidor de Herramientas y no forman parte de la valoración de nuestra tienda.</p>
            </header>

            <details class="dht-external-comments__details">
                <summary>
                    <span><?php echo esc_html($summary_label); ?></span>
                    <span class="dht-external-comments__toggle" aria-hidden="true">+</span>
                </summary>

                <div class="dht-external-comments__list">
                    <?php foreach ($valid as $external_comment) : ?>
                        <?php
                        $external_comment_text = dht_template_external_comment_text($external_comment);
                        $external_comment_meta = function_exists('seo_comentarista_render_source_meta')
                            ? seo_comentarista_render_source_meta($external_comment)
                            : '';
                        $external_comment_rating = function_exists('seo_comentarista_rating_text')
                            ? seo_comentarista_rating_text($external_comment)
                            : '';
                        $product_title = trim((string) ($external_comment['product_title'] ?? ''));
                        $product_url = esc_url_raw((string) ($external_comment['product_url'] ?? ''));
                        ?>
                        <article class="dht-external-comment">
                            <?php if ('category' === $context && $product_title !== '') : ?>
                                <h3 class="dht-external-comment__product">
                                    <?php if ($product_url !== '') : ?>
                                        <a href="<?php echo esc_url($product_url); ?>"><?php echo esc_html($product_title); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html($product_title); ?>
                                    <?php endif; ?>
                                </h3>
                            <?php endif; ?>

                            <div class="dht-external-comment__meta">
                                <strong>Comentario externo</strong>
                                <?php if ($external_comment_rating !== '') : ?>
                                    <span>Valoración en la fuente: <strong><?php echo esc_html($external_comment_rating); ?></strong></span>
                                <?php endif; ?>
                            </div>

                            <blockquote><?php echo wp_kses_post(wpautop($external_comment_text)); ?></blockquote>

                            <?php if ($external_comment_meta !== '') : ?>
                                <p class="dht-external-comment__source">
                                    <strong>Fuente:</strong>
                                    <?php echo wp_kses($external_comment_meta, array(
                                        'strong' => array(),
                                        'a' => array(
                                            'href' => array(),
                                            'target' => array(),
                                            'rel' => array(),
                                        ),
                                    )); ?>
                                </p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </details>
            <?php if ('category' === $context) : ?></div><?php endif; ?>
        </section>
        <?php
    }
}

/**
 * Render contextual completo de una ficha de producto.
 *
 * Contrato:
 * - seleccion editorial solo por product_cat + seo_relations(post_to_category);
 * - rol mediante _seo_solucionador_content_role;
 * - Comparador conserva su contrato publico propio;
 * - Comentarista se lee de su tabla persistida;
 * - no se usan categorias de posts, etiquetas, nombres, slugs ni texto libre.
 */
if (!function_exists('dht_template_render_product_context_blocks')) {
    function dht_template_render_product_context_blocks($product_id)
    {
        $product_id = absint($product_id);
        if ($product_id < 1) {
            return;
        }

        $category_ids = dht_template_product_context_category_ids($product_id);

        $dependiente_posts = dht_template_context_posts_for_categories(
            $category_ids,
            'dependiente_qa_basic',
            4
        );
        dht_template_render_context_posts(
            $dependiente_posts,
            'Preguntas habituales',
            'dependiente',
            'product'
        );

        if (function_exists('seo_comparador_render_product_block')) {
            seo_comparador_render_product_block($product_id);
        }

        $external_comments = dht_template_product_external_comments($product_id, 12);
        dht_template_render_external_comments(
            $external_comments,
            'Comentarios externos sobre este producto',
            'product'
        );

        $ingeniero_posts = dht_template_context_posts_for_categories(
            $category_ids,
            'ingeniero_qa_specialized',
            4
        );
        dht_template_render_context_posts(
            $ingeniero_posts,
            'Información técnica',
            'ingeniero',
            'product'
        );
    }
}

/**
 * Render contextual completo de una categoria de producto.
 *
 * La categoria usa exclusivamente su term_id product_cat. No se infiere
 * contexto desde categorias editoriales de WordPress, tags, slug o nombre.
 */
if (!function_exists('dht_template_render_category_context_blocks')) {
    function dht_template_render_category_context_blocks($term_id)
    {
        $term_id = absint($term_id);
        if ($term_id < 1) {
            return;
        }

        if (function_exists('seo_comparador_render_category_block')) {
            seo_comparador_render_category_block($term_id);
        }

        $dependiente_posts = dht_template_context_posts_for_categories(
            array($term_id),
            'dependiente_qa_basic',
            4
        );
        dht_template_render_context_posts(
            $dependiente_posts,
            'Preguntas habituales',
            'dependiente',
            'category'
        );

        $external_comments = dht_template_category_external_comments($term_id, 6, 2);
        dht_template_render_external_comments(
            $external_comments,
            'Comentarios externos sobre productos de esta categoría',
            'category'
        );

        $ingeniero_posts = dht_template_context_posts_for_categories(
            array($term_id),
            'ingeniero_qa_specialized',
            4
        );
        dht_template_render_context_posts(
            $ingeniero_posts,
            'Información técnica',
            'ingeniero',
            'category'
        );
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

/* ==========================================================
   GOOGLE MERCHANT: POLITICAS GLOBALES DE DEVOLUCION Y ENVIO
   - La politica de devoluciones visible vive en /devoluciones-y-reembolsos/.
   - La politica de envio visible vive en /envios-y-entrega/.
   - Los Offer de producto solo referencian estas politicas globales por @id.
   - No se inventan tarifas ni plazos: dependen del proveedor/producto/destino.
========================================================== */
if (!function_exists('dht_template_return_policy_url')) {
    function dht_template_return_policy_url()
    {
        $page = get_page_by_path('devoluciones-y-reembolsos');
        if (!$page instanceof WP_Post) {
            $page = get_page_by_path('devoluciones');
        }

        if ($page instanceof WP_Post) {
            $url = get_permalink($page);
            if ($url) {
                return trailingslashit((string) $url);
            }
        }

        return trailingslashit(home_url('/devoluciones-y-reembolsos/'));
    }
}

if (!function_exists('dht_template_shipping_policy_url')) {
    function dht_template_shipping_policy_url()
    {
        $page = get_page_by_path('envios-y-entrega');
        if ($page instanceof WP_Post) {
            $url = get_permalink($page);
            if ($url) {
                return trailingslashit((string) $url);
            }
        }

        return trailingslashit(home_url('/envios-y-entrega/'));
    }
}

if (!function_exists('dht_template_schema_merchant_policy_ids')) {
    function dht_template_schema_merchant_policy_ids()
    {
        $home_url = trailingslashit(home_url('/'));

        return array(
            'organization' => $home_url . '#organization',
            'returns'      => $home_url . '#merchant-return-policy',
            'shipping'     => $home_url . '#shipping-service',
        );
    }
}

if (!function_exists('dht_template_schema_merchant_organization_properties')) {
    /**
     * Propiedades para el Organization global de la tienda.
     * MerchantReturnPolicy usa merchantReturnLink, opcion admitida por Google
     * cuando la politica completa se explica en una pagina propia.
     *
     * ShippingService declara el destino general ES sin inventar coste ni plazo.
     * Esos datos dependen de cada proveedor/producto y se muestran o comunican
     * durante el proceso de compra.
     */
    function dht_template_schema_merchant_organization_properties()
    {
        $ids = dht_template_schema_merchant_policy_ids();

        return array(
            'hasMerchantReturnPolicy' => array(
                '@type'              => 'MerchantReturnPolicy',
                '@id'                => $ids['returns'],
                'merchantReturnLink' => esc_url_raw(dht_template_return_policy_url()),
            ),
            'hasShippingService' => array(
                '@type'           => 'ShippingService',
                '@id'             => $ids['shipping'],
                'name'            => 'Envíos y entrega',
                'description'     => 'Entrega estimada en 2–3 días. Acompañamos la compra desde el fabricante, durante el transporte y hasta la entrega.',
                'fulfillmentType' => 'https://schema.org/FulfillmentTypeDelivery',
                'shippingConditions' => array(
                    '@type' => 'ShippingConditions',
                    'shippingDestination' => array(
                        '@type'          => 'DefinedRegion',
                        'addressCountry' => 'ES',
                    ),
                ),
            ),
        );
    }
}

if (!function_exists('dht_template_schema_offer_merchant_policies')) {
    /**
     * Referencias desde Product > Offer a las politicas globales.
     * Google admite @id para devoluciones y OfferShippingDetails >
     * hasShippingService > @id para la politica global de envio.
     */
    function dht_template_schema_offer_merchant_policies()
    {
        $ids = dht_template_schema_merchant_policy_ids();

        return array(
            'hasMerchantReturnPolicy' => array(
                '@id' => $ids['returns'],
            ),
            'shippingDetails' => array(
                '@type' => 'OfferShippingDetails',
                'shippingDestination' => array(
                    '@type'          => 'DefinedRegion',
                    'addressCountry' => 'ES',
                ),
                'deliveryTime' => array(
                    '@type' => 'ShippingDeliveryTime',
                    'transitTime' => array(
                        '@type'    => 'QuantitativeValue',
                        'minValue' => 2,
                        'maxValue' => 3,
                        'unitCode' => 'DAY',
                    ),
                ),
                'hasShippingService' => array(
                    '@id' => $ids['shipping'],
                ),
            ),
        );
    }
}

/* ==========================================================
   PROPUESTA DE VALOR: ACOMPAÑAMIENTO ANTES Y DESPUÉS DE LA COMPRA
   Componente reutilizable para no repetir mensajes distintos
   en cada plantilla ni convertir la web en una sucesion de banners.
========================================================== */
if (!function_exists('dht_template_service_page_url')) {
    function dht_template_service_page_url()
    {
        $page = get_page_by_path('nuestro-servicio');
        if ($page instanceof WP_Post) {
            $url = get_permalink($page);
            if ($url) {
                return $url;
            }
        }

        return home_url('/nuestro-servicio/');
    }
}

if (!function_exists('dht_template_render_service_promise')) {
    function dht_template_render_service_promise($variant = 'strip', $context = 'browse')
    {
        $variant = sanitize_html_class((string) $variant);
        $context = sanitize_key((string) $context);

        $messages = array(
            'home' => array(
                'kicker' => 'Compra con respaldo',
                'title'  => 'Una persona detrás de tu compra',
                'body'   => 'Si surge una incidencia, te ayudamos a gestionar la comunicación con el fabricante o distribuidor y hacemos seguimiento contigo, en castellano.',
            ),
            'browse' => array(
                'kicker' => 'Nuestro servicio',
                'title'  => 'Una persona detrás de tu compra',
                'body'   => 'Te atendemos en castellano y, si surge una incidencia, te ayudamos a gestionar la comunicación y el seguimiento con el fabricante o distribuidor.',
            ),
            'product' => array(
                'kicker' => 'Acompañamiento posventa',
                'title'  => 'Te acompañamos también después de la compra',
                'body'   => 'Si este producto presenta una incidencia, puedes llamarnos. Te ayudamos a gestionar la comunicación con el fabricante o distribuidor y seguimos el caso contigo.',
            ),
            'checkout' => array(
                'kicker' => 'Compra con respaldo',
                'title'  => 'Después de la compra, seguimos aquí',
                'body'   => 'Si surge una incidencia con el pedido o el producto, puedes hablar con nosotros en castellano y te ayudaremos con la gestión y el seguimiento.',
            ),
            'after' => array(
                'kicker' => 'Guarda nuestro contacto',
                'title'  => 'Seguimos contigo después de la compra',
                'body'   => 'Si surge una incidencia, llámanos. Te ayudaremos a gestionar la comunicación con el fabricante o distribuidor y a hacer seguimiento del caso.',
            ),
        );

        $message = isset($messages[$context]) ? $messages[$context] : $messages['browse'];
        $service_url = dht_template_service_page_url();
        $phone_label = '+34 640 87 45 40';
        $phone_href  = 'tel:+34640874540';
        $show_points = in_array($variant, array('card', 'purchase', 'after'), true);
        ?>
        <aside class="dht-service-promise dht-service-promise--<?php echo esc_attr($variant); ?>" aria-label="Acompañamiento de compra y posventa">
            <div class="dht-service-promise__content">
                <span class="dht-service-promise__kicker"><?php echo esc_html($message['kicker']); ?></span>
                <strong class="dht-service-promise__title"><?php echo esc_html($message['title']); ?></strong>
                <p class="dht-service-promise__text"><?php echo esc_html($message['body']); ?></p>

                <?php if ($show_points) : ?>
                    <ul class="dht-service-promise__points" aria-label="Cómo te acompañamos">
                        <li>Atención en castellano</li>
                        <li>Ayuda con la gestión</li>
                        <li>Seguimiento contigo</li>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="dht-service-promise__actions">
                <a class="dht-service-promise__phone" href="<?php echo esc_url($phone_href); ?>">
                    <span>Habla con una persona</span>
                    <strong><?php echo esc_html($phone_label); ?></strong>
                </a>
                <a class="dht-service-promise__link" href="<?php echo esc_url($service_url); ?>">Cómo funciona nuestro servicio</a>
            </div>
        </aside>
        <?php
    }
}


/* ==========================================================
   DEPENDIENTE: CTA CONTEXTUAL DE MARKETING
   Una sola pieza reutilizable para convertir páginas editoriales
   y estructurales en puntos de entrada al buscador/Dependiente.
   No interpreta por sí misma: solo conserva una promesa prudente
   y envía la consulta a la página del Dependiente.
========================================================== */
if (!function_exists('dht_template_dependiente_page_url')) {
    function dht_template_dependiente_page_url()
    {
        $page = get_page_by_path('dependiente');
        if ($page instanceof WP_Post) {
            $url = get_permalink($page);
            if ($url) {
                return $url;
            }
        }

        return home_url('/dependiente/');
    }
}

if (!function_exists('dht_template_render_dependiente_cta')) {
    function dht_template_render_dependiente_cta($context = '', $variant = 'context')
    {
        $context = trim(wp_strip_all_tags((string) $context));
        $variant = sanitize_html_class((string) $variant);
        $url = dht_template_dependiente_page_url();
        $service_url = dht_template_service_page_url();
        $shop_url = dht_template_shop_url();

        if ('guide' === $variant) {
            $kicker = 'De la guía al catálogo';
            $title = 'Convierte esta información en una búsqueda concreta';
            $body = 'Escribe el producto, la necesidad, el uso, la marca o la referencia que quieres localizar. Dependiente relaciona la consulta con el catálogo y compara opciones.';
        } elseif ('compact' === $variant) {
            $kicker = 'Tu Dependiente del catálogo';
            $title = '¿Quieres localizar una opción concreta?';
            $body = 'Escribe lo que buscas y continúa en Dependiente.';
        } else {
            $kicker = 'Busca con Dependiente';
            $title = $context !== ''
                ? '¿Qué necesitas dentro de ' . $context . '?'
                : '¿No tienes claro qué producto necesitas?';
            $body = 'Empieza por un producto, una necesidad, una aplicación, una marca o una referencia. Dependiente relaciona la consulta con el catálogo para ayudarte a localizar opciones.';
        }

        $placeholder = $context !== ''
            ? 'Producto, uso o necesidad en ' . $context . '...'
            : 'Producto, necesidad, uso o referencia...';
        $input_id = wp_unique_id('dht-dependiente-query-');
        ?>
        <aside class="dht-assistant-cta dht-assistant-cta--<?php echo esc_attr($variant); ?>" aria-label="Buscar con Dependiente">
            <div class="dht-assistant-cta__copy">
                <span class="dht-assistant-cta__kicker"><?php echo esc_html($kicker); ?></span>
                <strong class="dht-assistant-cta__title"><?php echo esc_html($title); ?></strong>
                <p><?php echo esc_html($body); ?></p>
            </div>

            <div class="dht-assistant-cta__action">
                <form class="dht-assistant-cta__form" action="<?php echo esc_url($url); ?>" method="get">
                    <label class="screen-reader-text" for="<?php echo esc_attr($input_id); ?>">Describe lo que buscas</label>
                    <input id="<?php echo esc_attr($input_id); ?>" type="search" name="dep_q" value="" placeholder="<?php echo esc_attr($placeholder); ?>" autocomplete="off">
                    <button type="submit">Preguntar al Dependiente</button>
                </form>
                <div class="dht-assistant-cta__links">
                    <a href="<?php echo esc_url($shop_url); ?>">Ver catálogo</a>
                    <span aria-hidden="true">·</span>
                    <a href="<?php echo esc_url($service_url); ?>">Nuestro acompañamiento</a>
                </div>
            </div>
        </aside>
        <?php
    }
}


/* DHT CATEGORY FACETED CATALOG 2026-10-02 */
/*
 * Catálogo facetado compartido por las plantillas de categoría.
 * - Usa atributos canónicos marcados como filtrables.
 * - Mantiene los filtros como navegación de usuario; las variantes con query
 *   se marcan noindex desde la plantilla para evitar combinaciones SEO.
 * - Los valores se resuelven en servidor para no limitar el filtrado a los
 *   productos visibles en la primera página.
 */
if (!function_exists('dht_template_category_has_filter_query')) {
    function dht_template_category_has_filter_query()
    {
        foreach (array_keys((array) $_GET) as $key) {
            $key = (string) $key;
            if (0 === strpos($key, 'dht_')) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('dht_template_category_all_product_ids')) {
    function dht_template_category_all_product_ids($term_id)
    {
        $term_id = absint($term_id);
        if ($term_id < 1) {
            return array();
        }

        $cache_key = 'category_product_ids_' . $term_id;
        $cached = wp_cache_get($cache_key, 'dht_template');
        if (is_array($cached)) {
            return $cached;
        }

        $tax_query = array(
            array(
                'taxonomy'         => 'product_cat',
                'field'            => 'term_id',
                'terms'            => array($term_id),
                'include_children' => true,
                'operator'         => 'IN',
            ),
        );

        if (function_exists('wc_get_product_visibility_term_ids')) {
            $visibility = (array) wc_get_product_visibility_term_ids();
            $excluded = array_filter(array(
                absint($visibility['exclude-from-catalog'] ?? 0),
            ));
            if ($excluded) {
                $tax_query['relation'] = 'AND';
                $tax_query[] = array(
                    'taxonomy' => 'product_visibility',
                    'field'    => 'term_id',
                    'terms'    => array_values($excluded),
                    'operator' => 'NOT IN',
                );
            }
        }

        $query = new WP_Query(array(
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'tax_query'              => $tax_query,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ));

        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $query->posts))));
        wp_reset_postdata();
        wp_cache_set($cache_key, $ids, 'dht_template', 300);

        return $ids;
    }
}

if (!function_exists('dht_template_category_meta_index')) {
    function dht_template_category_meta_index($product_ids)
    {
        global $wpdb;

        $product_ids = array_values(array_unique(array_filter(array_map('absint', (array) $product_ids))));
        $index = array(
            'price'  => array(),
            'stock'  => array(),
            'rating' => array(),
        );

        if (!$product_ids) {
            return $index;
        }

        foreach (array_chunk($product_ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $sql = "SELECT post_id, meta_key, meta_value
                    FROM {$wpdb->postmeta}
                    WHERE post_id IN ({$placeholders})
                      AND meta_key IN ('_price', '_stock_status', '_wc_average_rating')";
            $rows = $wpdb->get_results($wpdb->prepare($sql, $chunk), ARRAY_A);

            foreach ((array) $rows as $row) {
                $product_id = absint($row['post_id'] ?? 0);
                if ($product_id < 1) {
                    continue;
                }

                $meta_key = (string) ($row['meta_key'] ?? '');
                $meta_value = (string) ($row['meta_value'] ?? '');

                if ('_price' === $meta_key) {
                    $index['price'][$product_id] = is_numeric($meta_value) ? (float) $meta_value : null;
                } elseif ('_stock_status' === $meta_key) {
                    $index['stock'][$product_id] = sanitize_key($meta_value);
                } elseif ('_wc_average_rating' === $meta_key) {
                    $index['rating'][$product_id] = is_numeric($meta_value) ? (float) $meta_value : 0.0;
                }
            }
        }

        return $index;
    }
}

if (!function_exists('dht_template_category_attribute_bundle')) {
    function dht_template_category_attribute_bundle($product_ids)
    {
        $product_ids = array_values(array_unique(array_filter(array_map('absint', (array) $product_ids))));
        $product_values = array();
        $facets = array();

        if (!$product_ids || !function_exists('seo_attributes_get_rows_for_products')) {
            return array(
                'product_values' => array(),
                'facets'         => array(),
            );
        }

        foreach (array_chunk($product_ids, 400) as $chunk) {
            $rows = (array) seo_attributes_get_rows_for_products($chunk);

            foreach ($rows as $row) {
                if (isset($row->attribute_filterable) && !(int) $row->attribute_filterable) {
                    continue;
                }
                if (isset($row->attribute_visible) && !(int) $row->attribute_visible) {
                    continue;
                }

                $product_id = absint($row->product_id ?? 0);
                $type = sanitize_key((string) ($row->attribute_type ?? ''));
                $value = trim(wp_strip_all_tags((string) ($row->attribute_value ?? '')));
                if ($product_id < 1 || '' === $type || '' === $value) {
                    continue;
                }

                $value_slug = sanitize_title(remove_accents($value));
                if ('' === $value_slug) {
                    continue;
                }

                $label = trim(wp_strip_all_tags((string) ($row->attribute_name ?? '')));
                if ('' === $label) {
                    $label = ucwords(str_replace(array('_', '-'), ' ', $type));
                }

                if (!isset($product_values[$product_id])) {
                    $product_values[$product_id] = array();
                }
                if (!isset($product_values[$product_id][$type])) {
                    $product_values[$product_id][$type] = array();
                }
                $product_values[$product_id][$type][$value_slug] = true;

                if (!isset($facets[$type])) {
                    $facets[$type] = array(
                        'type'     => $type,
                        'label'    => $label,
                        'coverage' => array(),
                        'values'   => array(),
                    );
                }

                $facets[$type]['coverage'][$product_id] = true;
                if (!isset($facets[$type]['values'][$value_slug])) {
                    $facets[$type]['values'][$value_slug] = array(
                        'slug'     => $value_slug,
                        'label'    => $value,
                        'products' => array(),
                    );
                }
                $facets[$type]['values'][$value_slug]['products'][$product_id] = true;
            }
        }

        $priority = array(
            'marca' => 10,
            'brand' => 11,
            'fabricante' => 12,
            'capacidad' => 20,
            'potencia' => 30,
            'presion' => 40,
            'presion_maxima' => 41,
            'voltaje' => 50,
            'frecuencia' => 60,
            'temperatura' => 70,
            'material' => 80,
            'uso' => 90,
            'aplicacion' => 91,
            'tipo' => 100,
        );

        foreach ($facets as $type => &$facet) {
            $facet['coverage_count'] = count($facet['coverage']);
            unset($facet['coverage']);

            foreach ($facet['values'] as &$value) {
                $value['count'] = count($value['products']);
                unset($value['products']);
            }
            unset($value);

            uasort($facet['values'], static function ($left, $right) {
                $count_compare = ((int) ($right['count'] ?? 0)) <=> ((int) ($left['count'] ?? 0));
                if (0 !== $count_compare) {
                    return $count_compare;
                }
                return strnatcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
            });

            $facet['values'] = array_slice($facet['values'], 0, 12, true);
            $facet['priority'] = $priority[$type] ?? 500;
        }
        unset($facet);

        $facets = array_filter($facets, static function ($facet) {
            return count((array) ($facet['values'] ?? array())) >= 2
                && (int) ($facet['coverage_count'] ?? 0) >= 2;
        });

        uasort($facets, static function ($left, $right) {
            $priority_compare = ((int) ($left['priority'] ?? 500)) <=> ((int) ($right['priority'] ?? 500));
            if (0 !== $priority_compare) {
                return $priority_compare;
            }
            $coverage_compare = ((int) ($right['coverage_count'] ?? 0)) <=> ((int) ($left['coverage_count'] ?? 0));
            if (0 !== $coverage_compare) {
                return $coverage_compare;
            }
            return strnatcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        });

        $facets = array_slice($facets, 0, 7, true);

        return array(
            'product_values' => $product_values,
            'facets'         => $facets,
        );
    }
}

if (!function_exists('dht_template_category_catalog_state')) {
    function dht_template_category_catalog_state($term_id, $per_page = 24)
    {
        $term_id = absint($term_id);
        $per_page = max(8, min(48, absint($per_page)));
        $base_url = get_term_link($term_id, 'product_cat');
        $base_url = is_wp_error($base_url) ? home_url('/') : (string) $base_url;

        $all_ids = dht_template_category_all_product_ids($term_id);

        $bundle_cache_key = 'category_facets_' . $term_id . '_' . md5(implode(',', $all_ids));
        $cached_bundle = wp_cache_get($bundle_cache_key, 'dht_template');
        if (is_array($cached_bundle) && isset($cached_bundle['meta'], $cached_bundle['attribute_bundle'])) {
            $meta = (array) $cached_bundle['meta'];
            $attribute_bundle = (array) $cached_bundle['attribute_bundle'];
        } else {
            $meta = dht_template_category_meta_index($all_ids);
            $attribute_bundle = dht_template_category_attribute_bundle($all_ids);
            wp_cache_set(
                $bundle_cache_key,
                array(
                    'meta'             => $meta,
                    'attribute_bundle' => $attribute_bundle,
                ),
                'dht_template',
                300
            );
        }

        $facets = (array) ($attribute_bundle['facets'] ?? array());
        $product_values = (array) ($attribute_bundle['product_values'] ?? array());

        $prices = array_values(array_filter(
            array_map(
                static function ($value) {
                    return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
                },
                (array) ($meta['price'] ?? array())
            ),
            static function ($value) {
                return null !== $value;
            }
        ));
        $catalog_min_price = $prices ? min($prices) : 0.0;
        $catalog_max_price = $prices ? max($prices) : 0.0;

        $selected_stock = isset($_GET['dht_stock'])
            ? sanitize_key(wp_unslash((string) $_GET['dht_stock']))
            : '';
        $selected_stock = 'instock' === $selected_stock ? 'instock' : '';

        $selected_min_price = isset($_GET['dht_min_price'])
            ? max(0.0, (float) wc_format_decimal(wp_unslash((string) $_GET['dht_min_price'])))
            : 0.0;
        $selected_max_price = isset($_GET['dht_max_price'])
            ? max(0.0, (float) wc_format_decimal(wp_unslash((string) $_GET['dht_max_price'])))
            : 0.0;

        $sort = isset($_GET['dht_sort'])
            ? sanitize_key(wp_unslash((string) $_GET['dht_sort']))
            : 'relevance';
        $allowed_sorts = array('relevance', 'price_asc', 'price_desc', 'rating', 'newest');
        if (!in_array($sort, $allowed_sorts, true)) {
            $sort = 'relevance';
        }

        $selected_attributes = array();
        foreach ($facets as $type => $facet) {
            $param = 'dht_f_' . $type;
            $raw_values = isset($_GET[$param]) ? (array) wp_unslash($_GET[$param]) : array();
            $valid_values = array_keys((array) ($facet['values'] ?? array()));
            $selected = array_values(array_unique(array_filter(array_map(
                'sanitize_title',
                $raw_values
            ))));
            $selected = array_values(array_intersect($selected, $valid_values));
            if ($selected) {
                $selected_attributes[$type] = $selected;
            }
        }

        $filtered_ids = array();
        foreach ($all_ids as $product_id) {
            $product_id = absint($product_id);
            if ($product_id < 1) {
                continue;
            }

            if ('instock' === $selected_stock && 'instock' !== ($meta['stock'][$product_id] ?? '')) {
                continue;
            }

            $price = $meta['price'][$product_id] ?? null;
            if ($selected_min_price > 0 && (!is_numeric($price) || (float) $price < $selected_min_price)) {
                continue;
            }
            if ($selected_max_price > 0 && (!is_numeric($price) || (float) $price > $selected_max_price)) {
                continue;
            }

            $attribute_match = true;
            foreach ($selected_attributes as $type => $wanted_values) {
                $available = array_keys((array) ($product_values[$product_id][$type] ?? array()));
                if (!$available || !array_intersect($wanted_values, $available)) {
                    $attribute_match = false;
                    break;
                }
            }
            if (!$attribute_match) {
                continue;
            }

            $filtered_ids[] = $product_id;
        }

        $query_args = array();
        if ('instock' === $selected_stock) {
            $query_args['dht_stock'] = 'instock';
        }
        if ($selected_min_price > 0) {
            $query_args['dht_min_price'] = wc_format_decimal($selected_min_price, 2);
        }
        if ($selected_max_price > 0) {
            $query_args['dht_max_price'] = wc_format_decimal($selected_max_price, 2);
        }
        foreach ($selected_attributes as $type => $values) {
            $query_args['dht_f_' . $type] = $values;
        }
        if ('relevance' !== $sort) {
            $query_args['dht_sort'] = $sort;
        }

        if ('price_asc' === $sort || 'price_desc' === $sort) {
            $direction = 'price_desc' === $sort ? -1 : 1;
            usort($filtered_ids, static function ($left, $right) use ($meta, $direction) {
                $left_price = $meta['price'][$left] ?? null;
                $right_price = $meta['price'][$right] ?? null;

                $left_missing = !is_numeric($left_price);
                $right_missing = !is_numeric($right_price);
                if ($left_missing && $right_missing) {
                    return $left <=> $right;
                }
                if ($left_missing) {
                    return 1;
                }
                if ($right_missing) {
                    return -1;
                }

                $compare = ((float) $left_price <=> (float) $right_price);
                return $compare * $direction;
            });
        } elseif ('rating' === $sort) {
            usort($filtered_ids, static function ($left, $right) use ($meta) {
                $left_rating = (float) ($meta['rating'][$left] ?? 0);
                $right_rating = (float) ($meta['rating'][$right] ?? 0);
                $compare = $right_rating <=> $left_rating;
                return 0 !== $compare ? $compare : ($right <=> $left);
            });
        }

        $page = isset($_GET['dht_page']) ? max(1, absint($_GET['dht_page'])) : 1;
        $total = count($filtered_ids);
        $max_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;
        if ($page > $max_pages) {
            $page = $max_pages;
        }

        $products = array();
        if ($filtered_ids) {
            $args = array(
                'post_type'              => 'product',
                'post_status'            => 'publish',
                'posts_per_page'         => $per_page,
                'paged'                  => $page,
                'post__in'               => $filtered_ids,
                'ignore_sticky_posts'    => true,
                'update_post_meta_cache' => true,
                'update_post_term_cache' => false,
            );

            if ('price_asc' === $sort || 'price_desc' === $sort || 'rating' === $sort) {
                $args['orderby'] = 'post__in';
            } elseif ('newest' === $sort) {
                $args['orderby'] = 'date';
                $args['order'] = 'DESC';
            } else {
                $args['orderby'] = array(
                    'menu_order' => 'ASC',
                    'date'       => 'DESC',
                );
            }

            $query = new WP_Query($args);
            foreach ((array) $query->posts as $product_post) {
                try {
                    $product = function_exists('wc_get_product') ? wc_get_product($product_post->ID) : null;
                    if ($product && is_a($product, 'WC_Product')) {
                        $products[] = $product;
                    }
                } catch (Throwable $e) {
                    error_log('[DHT category facets] wc_get_product ID ' . absint($product_post->ID) . ': ' . $e->getMessage());
                }
            }
            wp_reset_postdata();
        }

        $active_count = 0;
        if ('instock' === $selected_stock) {
            $active_count++;
        }
        if ($selected_min_price > 0 || $selected_max_price > 0) {
            $active_count++;
        }
        foreach ($selected_attributes as $values) {
            $active_count += count($values);
        }

        return array(
            'term_id'             => $term_id,
            'base_url'            => $base_url,
            'all_ids'             => $all_ids,
            'all_count'           => count($all_ids),
            'products'            => $products,
            'total'               => $total,
            'page'                => $page,
            'max_pages'           => $max_pages,
            'per_page'            => $per_page,
            'sort'                => $sort,
            'facets'              => $facets,
            'stock'               => $selected_stock,
            'instock_count'       => count(array_filter((array) ($meta['stock'] ?? array()), static function ($status) { return 'instock' === $status; })),
            'catalog_min_price'   => $catalog_min_price,
            'catalog_max_price'   => $catalog_max_price,
            'min_price'           => $selected_min_price,
            'max_price'           => $selected_max_price,
            'selected_attributes' => $selected_attributes,
            'query_args'          => $query_args,
            'active_count'        => $active_count,
        );
    }
}

if (!function_exists('dht_template_category_query_url')) {
    function dht_template_category_query_url($state, $overrides = array(), $remove = array())
    {
        $base_url = (string) ($state['base_url'] ?? home_url('/'));
        $args = (array) ($state['query_args'] ?? array());

        foreach ((array) $remove as $key) {
            unset($args[$key]);
        }

        /* La paginación previa no debe sobrevivir al cambio de un filtro. */
        unset($args['dht_page']);

        foreach ((array) $overrides as $key => $value) {
            if (null === $value || '' === $value || array() === $value) {
                unset($args[$key]);
            } else {
                $args[$key] = $value;
            }
        }

        return $args ? add_query_arg($args, $base_url) : $base_url;
    }
}

if (!function_exists('dht_template_render_category_filter_form')) {
    function dht_template_render_category_filter_form($state, $variant = 'desktop')
    {
        $facets = (array) ($state['facets'] ?? array());
        $selected_attributes = (array) ($state['selected_attributes'] ?? array());
        $variant = 'mobile' === $variant ? 'mobile' : 'desktop';
        ?>
        <form class="dht-category-filter-form dht-category-filter-form--<?php echo esc_attr($variant); ?>" action="<?php echo esc_url($state['base_url'] ?? ''); ?>" method="get">
            <?php if (!empty($state['sort']) && 'relevance' !== $state['sort']) : ?>
                <input type="hidden" name="dht_sort" value="<?php echo esc_attr($state['sort']); ?>">
            <?php endif; ?>

            <div class="dht-category-filter-form__head">
                <strong>Filtrar</strong>
                <?php if (!empty($state['active_count'])) : ?>
                    <a href="<?php echo esc_url($state['base_url']); ?>">Limpiar</a>
                <?php endif; ?>
            </div>

            <?php if (!empty($state['instock_count'])) : ?>
                <fieldset class="dht-category-filter-group">
                    <legend>Disponibilidad</legend>
                    <label class="dht-category-filter-option">
                        <input type="checkbox" name="dht_stock" value="instock" <?php checked('instock', $state['stock'] ?? ''); ?>>
                        <span>En stock</span>
                        <small><?php echo esc_html(number_format_i18n((int) $state['instock_count'])); ?></small>
                    </label>
                </fieldset>
            <?php endif; ?>

            <?php if ((float) ($state['catalog_max_price'] ?? 0) > 0) : ?>
                <fieldset class="dht-category-filter-group">
                    <legend>Precio</legend>
                    <div class="dht-category-price-filter">
                        <label>
                            <span>Desde</span>
                            <input type="number" min="0" step="0.01" name="dht_min_price" value="<?php echo esc_attr(($state['min_price'] ?? 0) > 0 ? wc_format_decimal($state['min_price'], 2) : ''); ?>" placeholder="<?php echo esc_attr(wc_format_decimal($state['catalog_min_price'] ?? 0, 2)); ?>">
                        </label>
                        <label>
                            <span>Hasta</span>
                            <input type="number" min="0" step="0.01" name="dht_max_price" value="<?php echo esc_attr(($state['max_price'] ?? 0) > 0 ? wc_format_decimal($state['max_price'], 2) : ''); ?>" placeholder="<?php echo esc_attr(wc_format_decimal($state['catalog_max_price'] ?? 0, 2)); ?>">
                        </label>
                    </div>
                </fieldset>
            <?php endif; ?>

            <?php foreach ($facets as $type => $facet) : ?>
                <fieldset class="dht-category-filter-group">
                    <legend><?php echo esc_html($facet['label'] ?? $type); ?></legend>
                    <div class="dht-category-filter-options">
                        <?php foreach ((array) ($facet['values'] ?? array()) as $value_slug => $value) : ?>
                            <label class="dht-category-filter-option">
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr('dht_f_' . $type); ?>[]"
                                    value="<?php echo esc_attr($value_slug); ?>"
                                    <?php checked(in_array($value_slug, (array) ($selected_attributes[$type] ?? array()), true)); ?>
                                >
                                <span><?php echo esc_html($value['label'] ?? $value_slug); ?></span>
                                <small><?php echo esc_html(number_format_i18n((int) ($value['count'] ?? 0))); ?></small>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            <?php endforeach; ?>

            <div class="dht-category-filter-actions">
                <button type="submit">Aplicar filtros</button>
                <?php if (!empty($state['active_count'])) : ?>
                    <a href="<?php echo esc_url($state['base_url']); ?>">Quitar filtros</a>
                <?php endif; ?>
            </div>
        </form>
        <?php
    }
}

if (!function_exists('dht_template_render_category_toolbar')) {
    function dht_template_render_category_toolbar($state, $term_name = '')
    {
        $term_name = trim(wp_strip_all_tags((string) $term_name));
        $query_args = (array) ($state['query_args'] ?? array());
        unset($query_args['dht_sort'], $query_args['dht_page']);
        ?>
        <div class="dht-category-catalog-toolbar">
            <div class="dht-category-catalog-toolbar__count">
                <strong><?php echo esc_html(number_format_i18n((int) ($state['total'] ?? 0))); ?> resultados</strong>
                <?php if ($term_name !== '') : ?><span>en <?php echo esc_html($term_name); ?></span><?php endif; ?>
            </div>

            <form class="dht-category-sort-form" action="<?php echo esc_url($state['base_url'] ?? ''); ?>" method="get">
                <?php foreach ($query_args as $key => $value) : ?>
                    <?php foreach ((array) $value as $item) : ?>
                        <input type="hidden" name="<?php echo esc_attr(is_array($value) ? $key . '[]' : $key); ?>" value="<?php echo esc_attr($item); ?>">
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <label>
                    <span>Ordenar por</span>
                    <select name="dht_sort" onchange="this.form.submit()">
                        <option value="relevance" <?php selected('relevance', $state['sort'] ?? 'relevance'); ?>>Relevancia</option>
                        <option value="price_asc" <?php selected('price_asc', $state['sort'] ?? ''); ?>>Precio: menor a mayor</option>
                        <option value="price_desc" <?php selected('price_desc', $state['sort'] ?? ''); ?>>Precio: mayor a menor</option>
                        <option value="rating" <?php selected('rating', $state['sort'] ?? ''); ?>>Mejor valorados</option>
                        <option value="newest" <?php selected('newest', $state['sort'] ?? ''); ?>>Novedades</option>
                    </select>
                </label>
            </form>
        </div>
        <?php
    }
}

if (!function_exists('dht_template_render_category_active_filters')) {
    function dht_template_render_category_active_filters($state)
    {
        if (empty($state['active_count'])) {
            return;
        }

        $chips = array();

        if ('instock' === ($state['stock'] ?? '')) {
            $chips[] = array(
                'label' => 'En stock',
                'url'   => dht_template_category_query_url($state, array(), array('dht_stock')),
            );
        }

        if (($state['min_price'] ?? 0) > 0 || ($state['max_price'] ?? 0) > 0) {
            $label = 'Precio';
            if (($state['min_price'] ?? 0) > 0 && ($state['max_price'] ?? 0) > 0) {
                $label .= ': ' . wc_price($state['min_price']) . ' – ' . wc_price($state['max_price']);
            } elseif (($state['min_price'] ?? 0) > 0) {
                $label .= ': desde ' . wc_price($state['min_price']);
            } else {
                $label .= ': hasta ' . wc_price($state['max_price']);
            }
            $chips[] = array(
                'label_html' => $label,
                'url'        => dht_template_category_query_url($state, array(), array('dht_min_price', 'dht_max_price')),
            );
        }

        foreach ((array) ($state['selected_attributes'] ?? array()) as $type => $values) {
            $facet = $state['facets'][$type] ?? array();
            foreach ((array) $values as $selected_value) {
                $remaining = array_values(array_diff((array) $values, array($selected_value)));
                $url = dht_template_category_query_url(
                    $state,
                    array('dht_f_' . $type => $remaining),
                    array()
                );
                $value_label = $facet['values'][$selected_value]['label'] ?? $selected_value;
                $chips[] = array(
                    'label' => (string) ($facet['label'] ?? $type) . ': ' . (string) $value_label,
                    'url'   => $url,
                );
            }
        }

        ?>
        <div class="dht-category-active-filters" aria-label="Filtros activos">
            <span>Filtros:</span>
            <?php foreach ($chips as $chip) : ?>
                <a href="<?php echo esc_url($chip['url'] ?? $state['base_url']); ?>">
                    <?php
                    if (isset($chip['label_html'])) {
                        echo wp_kses_post($chip['label_html']);
                    } else {
                        echo esc_html($chip['label'] ?? '');
                    }
                    ?>
                    <b aria-hidden="true">×</b>
                </a>
            <?php endforeach; ?>
            <a class="dht-category-active-filters__clear" href="<?php echo esc_url($state['base_url']); ?>">Borrar todo</a>
        </div>
        <?php
    }
}

if (!function_exists('dht_template_render_category_pagination')) {
    function dht_template_render_category_pagination($state)
    {
        $current = max(1, absint($state['page'] ?? 1));
        $max_pages = max(1, absint($state['max_pages'] ?? 1));
        if ($max_pages <= 1) {
            return;
        }

        $start = max(1, $current - 2);
        $end = min($max_pages, $current + 2);
        ?>
        <nav class="dht-category-pagination" aria-label="Paginación de productos">
            <?php if ($current > 1) : ?>
                <a href="<?php echo esc_url(dht_template_category_query_url($state, array('dht_page' => $current - 1))); ?>">← Anterior</a>
            <?php endif; ?>

            <?php if ($start > 1) : ?>
                <a href="<?php echo esc_url(dht_template_category_query_url($state, array('dht_page' => 1))); ?>">1</a>
                <?php if ($start > 2) : ?><span>…</span><?php endif; ?>
            <?php endif; ?>

            <?php for ($page = $start; $page <= $end; $page++) : ?>
                <?php if ($page === $current) : ?>
                    <span class="is-current" aria-current="page"><?php echo esc_html((string) $page); ?></span>
                <?php else : ?>
                    <a href="<?php echo esc_url(dht_template_category_query_url($state, array('dht_page' => $page))); ?>"><?php echo esc_html((string) $page); ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($end < $max_pages) : ?>
                <?php if ($end < $max_pages - 1) : ?><span>…</span><?php endif; ?>
                <a href="<?php echo esc_url(dht_template_category_query_url($state, array('dht_page' => $max_pages))); ?>"><?php echo esc_html((string) $max_pages); ?></a>
            <?php endif; ?>

            <?php if ($current < $max_pages) : ?>
                <a href="<?php echo esc_url(dht_template_category_query_url($state, array('dht_page' => $current + 1))); ?>">Siguiente →</a>
            <?php endif; ?>
        </nav>
        <?php
    }
}
