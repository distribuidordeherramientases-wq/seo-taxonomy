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

        $attributes = array();
        foreach ((array) $compare_product->get_attributes() as $attribute) {
            if (!is_a($attribute, 'WC_Product_Attribute')) {
                continue;
            }

            if (method_exists($attribute, 'get_visible') && !$attribute->get_visible()) {
                continue;
            }

            $attribute_name = (string) $attribute->get_name();
            $attribute_label = function_exists('wc_attribute_label')
                ? (string) wc_attribute_label($attribute_name, $compare_product)
                : $attribute_name;

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

            $values = array_values(array_unique(array_filter(array_map(static function ($value) {
                $value = wp_strip_all_tags((string) $value);
                return '' === trim($value) ? '' : trim($value);
            }, (array) $values))));

            if (!$values || '' === trim($attribute_label)) {
                continue;
            }

            $attributes[$attribute_label] = implode(', ', $values);
        }

        $tags = wp_get_post_terms($product_id, 'product_tag', array('fields' => 'names'));
        if (is_wp_error($tags)) {
            $tags = array();
        }
        $tags = array_values(array_unique(array_filter(array_map(static function ($tag) {
            $tag = wp_strip_all_tags((string) $tag);
            return '' === trim($tag) ? '' : trim($tag);
        }, (array) $tags))));

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
            'price'      => wp_strip_all_tags((string) $compare_product->get_price_html()),
            'tags'       => $tags,
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

                var selected = [];
                var toolbar = root.querySelector('[data-dht-compare-toolbar]');
                var countNode = root.querySelector('[data-dht-compare-count]');
                var showButton = root.querySelector('[data-dht-compare-show]');
                var clearButton = root.querySelector('[data-dht-compare-clear]');
                var result = root.querySelector('[data-dht-compare-result]');
                var toggles = Array.prototype.slice.call(root.querySelectorAll('[data-dht-compare-product]'));

                function sync() {
                    toggles.forEach(function (button) {
                        var id = String(button.getAttribute('data-dht-compare-product') || '');
                        var active = selected.indexOf(id) !== -1;
                        button.setAttribute('aria-pressed', active ? 'true' : 'false');
                        button.textContent = active ? 'Seleccionado' : 'Comparar';
                        button.disabled = !active && selected.length >= 4;
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
                    if (result && selected.length < 2) {
                        result.hidden = true;
                        result.innerHTML = '';
                    }
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

                    var attributeLabels = [];
                    chosen.forEach(function (product) {
                        var attributes = product.attributes || {};
                        Object.keys(attributes).forEach(function (label) {
                            if (attributeLabels.indexOf(label) === -1) {
                                attributeLabels.push(label);
                            }
                        });
                    });
                    attributeLabels.sort(function (a, b) {
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
                        th.appendChild(productBox);
                        headRow.appendChild(th);
                    });
                    thead.appendChild(headRow);
                    table.appendChild(thead);

                    var tbody = document.createElement('tbody');

                    var priceRow = document.createElement('tr');
                    var priceHead = document.createElement('th');
                    priceHead.scope = 'row';
                    priceHead.textContent = 'Precio';
                    priceRow.appendChild(priceHead);
                    chosen.forEach(function (product) {
                        appendTextCell(priceRow, product.price);
                    });
                    tbody.appendChild(priceRow);

                    var tagRow = document.createElement('tr');
                    var tagHead = document.createElement('th');
                    tagHead.scope = 'row';
                    tagHead.textContent = 'Etiquetas';
                    tagRow.appendChild(tagHead);
                    chosen.forEach(function (product) {
                        appendTextCell(tagRow, Array.isArray(product.tags) && product.tags.length ? product.tags.join(', ') : '—');
                    });
                    tbody.appendChild(tagRow);

                    attributeLabels.forEach(function (label) {
                        var row = document.createElement('tr');
                        var rowHead = document.createElement('th');
                        rowHead.scope = 'row';
                        rowHead.textContent = label;
                        row.appendChild(rowHead);
                        chosen.forEach(function (product) {
                            var attrs = product.attributes || {};
                            appendTextCell(row, attrs[label] || '—');
                        });
                        tbody.appendChild(row);
                    });

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
                        } else if (selected.length < 4) {
                            selected.push(id);
                        }
                        sync();
                        return;
                    }

                    if (event.target.closest('[data-dht-compare-show]')) {
                        renderComparison();
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

            echo '<div class="dht-category-compare-scope" data-dht-category-compare data-dht-compare-data-id="' . esc_attr($data_id) . '">';
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
            echo '<button type="button" data-dht-compare-clear>Limpiar</button>';
            echo '<p class="dht-category-live-compare-note">Selecciona entre 2 y 4 productos. La tabla usa sus atributos visibles y etiquetas de producto.</p>';
            echo '</div>';
            echo '<div class="dht-category-live-compare-result" data-dht-compare-result hidden></div>';
            echo '</div>';
            echo '<script type="application/json" id="' . esc_attr($data_id) . '">' . wp_json_encode($compare_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
            echo '</div>';

            dht_shared_render_category_compare_assets();
        }
    }
}


/* ==========================================================
   CATEGORIAS: CRITERIOS DE ELECCION Y FAMILIAS RELACIONADAS
   Las plantillas de categoria desktop/mobile consumen estos
   helpers. Deben devolver arrays vacios, nunca provocar un
   fatal, cuando no exista informacion suficiente.
   ========================================================== */
if (!function_exists('dht_template_category_choice_criteria')) {
    /**
     * Obtiene los atributos visibles que mejor sirven para comparar
     * los productos mostrados en una categoria.
     *
     * Prioriza atributos presentes en varios productos y con valores
     * distintos entre referencias. Devuelve solo las etiquetas, porque
     * la plantilla las presenta como "Compara especialmente".
     */
    function dht_template_category_choice_criteria($products, $limit = 6)
    {
        $limit = max(1, min(12, absint($limit)));
        $stats = array();

        foreach ((array) $products as $item) {
            $candidate = is_a($item, 'WC_Product')
                ? $item
                : (function_exists('wc_get_product') ? wc_get_product(absint($item)) : null);

            if (!$candidate || !is_a($candidate, 'WC_Product')) {
                continue;
            }

            $product_id = absint($candidate->get_id());
            if ($product_id < 1) {
                continue;
            }

            $seen_in_product = array();

            foreach ((array) $candidate->get_attributes() as $attribute) {
                if (!is_a($attribute, 'WC_Product_Attribute')) {
                    continue;
                }

                if (method_exists($attribute, 'get_visible') && !$attribute->get_visible()) {
                    continue;
                }

                $attribute_name = (string) $attribute->get_name();
                $label = function_exists('wc_attribute_label')
                    ? (string) wc_attribute_label($attribute_name, $candidate)
                    : $attribute_name;
                $label = trim(wp_strip_all_tags($label));

                if ($label === '') {
                    continue;
                }

                $key = sanitize_title(remove_accents($label));
                if ($key === '' || isset($seen_in_product[$key])) {
                    continue;
                }
                $seen_in_product[$key] = true;

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

                $values = array_values(array_unique(array_filter(array_map(static function ($value) {
                    $value = trim(wp_strip_all_tags((string) $value));
                    return $value === '' ? '' : $value;
                }, (array) $values))));

                if (!$values) {
                    continue;
                }

                if (!isset($stats[$key])) {
                    $stats[$key] = array(
                        'label'    => $label,
                        'products' => 0,
                        'values'   => array(),
                    );
                }

                $stats[$key]['products']++;
                foreach ($values as $value) {
                    $value_key = function_exists('mb_strtolower')
                        ? mb_strtolower($value, 'UTF-8')
                        : strtolower($value);
                    $stats[$key]['values'][$value_key] = true;
                }
            }
        }

        if (!$stats) {
            return array();
        }

        $ranked = array();
        foreach ($stats as $key => $item) {
            $value_count = count($item['values']);
            $product_count = (int) $item['products'];

            /* Un criterio es especialmente util si aparece en mas de una
             * referencia y tiene valores distintos. Los de una sola referencia
             * quedan como respaldo para categorias con datos escasos. */
            $ranked[] = array(
                'label'       => $item['label'],
                'products'    => $product_count,
                'values'      => $value_count,
                'is_variable' => $value_count > 1 ? 1 : 0,
            );
        }

        usort($ranked, static function ($a, $b) {
            if ($a['is_variable'] !== $b['is_variable']) {
                return $b['is_variable'] <=> $a['is_variable'];
            }
            if ($a['products'] !== $b['products']) {
                return $b['products'] <=> $a['products'];
            }
            if ($a['values'] !== $b['values']) {
                return $b['values'] <=> $a['values'];
            }
            return strcasecmp((string) $a['label'], (string) $b['label']);
        });

        $labels = array();
        foreach ($ranked as $item) {
            if (count($labels) >= $limit) {
                break;
            }

            /* Si hay varios productos, evitamos ocupar el bloque con una
             * caracteristica constante salvo que no existan alternativas. */
            if ($item['products'] > 1 && !$item['is_variable']) {
                continue;
            }

            $labels[] = $item['label'];
        }

        if (!$labels) {
            foreach ($ranked as $item) {
                $labels[] = $item['label'];
                if (count($labels) >= $limit) {
                    break;
                }
            }
        }

        return array_values(array_unique($labels));
    }
}

if (!function_exists('dht_template_related_product_categories')) {
    /**
     * Devuelve familias de producto relacionadas por jerarquia WooCommerce.
     * 1) Si la categoria tiene hijas publicas con productos, muestra esas hijas.
     * 2) Si no, muestra categorias hermanas del mismo padre.
     *
     * Esto replica la regla historica de las plantillas de categoria, pero la
     * centraliza para desktop y mobile y evita WP_Error/fatales en la vista.
     */
    function dht_template_related_product_categories($term, $limit = 6)
    {
        $limit = max(1, min(24, absint($limit)));

        if (is_numeric($term)) {
            $term = get_term(absint($term), 'product_cat');
        }

        if (!$term || is_wp_error($term) || !is_a($term, 'WP_Term') || 'product_cat' !== $term->taxonomy) {
            return array();
        }

        $children = get_terms(array(
            'taxonomy'   => 'product_cat',
            'parent'     => (int) $term->term_id,
            'hide_empty' => true,
            'number'     => $limit,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ));

        if (!is_wp_error($children) && !empty($children)) {
            return array_values(array_filter($children, static function ($candidate) {
                return $candidate instanceof WP_Term && dht_template_safe_term_link($candidate) !== '';
            }));
        }

        $siblings = get_terms(array(
            'taxonomy'   => 'product_cat',
            'parent'     => (int) $term->parent,
            'exclude'    => array((int) $term->term_id),
            'hide_empty' => true,
            'number'     => $limit,
            'orderby'    => 'count',
            'order'      => 'DESC',
        ));

        if (is_wp_error($siblings) || empty($siblings)) {
            return array();
        }

        return array_values(array_filter($siblings, static function ($candidate) {
            return $candidate instanceof WP_Term && dht_template_safe_term_link($candidate) !== '';
        }));
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
                'description'     => 'Los gastos y plazos de entrega dependen del producto, proveedor, origen logístico y destino. Las condiciones aplicables se muestran o comunican durante la compra.',
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
