<?php
/**
 * Front page comercial / marketplace - MOVIL.
 * Plantilla fisica independiente para este entorno.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

dht_template_render_header();

global $wpdb;

/* ==========================================================
   JSON-LD GOOGLE: ORGANIZATION + WEBSITE
   Solo en portada. No se inventan datos corporativos ausentes.
========================================================== */
$schema_home_url = trailingslashit(home_url('/'));
$schema_site_name = trim((string) get_bloginfo('name'));
$schema_language = trim((string) get_bloginfo('language'));
$schema_organization_id = $schema_home_url . '#organization';
$schema_website_id = $schema_home_url . '#website';
$schema_logo_candidate = function_exists('dht_shared_site_logo_candidate')
    ? dht_shared_site_logo_candidate()
    : null;

$schema_organization = array(
    '@type' => 'Organization',
    '@id'   => $schema_organization_id,
    'name'  => $schema_site_name,
    'url'   => $schema_home_url,
);

/* Politicas Merchant globales referenciadas por los Offer de producto. */
if (function_exists('dht_template_schema_merchant_organization_properties')) {
    $schema_organization = array_merge(
        $schema_organization,
        dht_template_schema_merchant_organization_properties()
    );
}

if (!empty($schema_logo_candidate['url'])) {
    $schema_organization['logo'] = array(
        '@type' => 'ImageObject',
        'url'   => esc_url_raw((string) $schema_logo_candidate['url']),
    );
}

$schema_website = array(
    '@type'     => 'WebSite',
    '@id'       => $schema_website_id,
    'name'      => $schema_site_name,
    'url'       => $schema_home_url,
    'publisher' => array('@id' => $schema_organization_id),
);

if ($schema_language !== '') {
    $schema_website['inLanguage'] = $schema_language;
}

$schema_front = array(
    '@context' => 'https://schema.org',
    '@graph'   => array($schema_organization, $schema_website),
);
?>
<script type="application/ld+json" id="dht-schema-front">
<?php echo wp_json_encode($schema_front, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
</script>
<?php

/* ==========================================================
   IMAGENES DE PRODUCTO
   Orden estricto y sin prechequeos HTTP desde PHP:
   1) Media / biblioteca local
   2) Tabla de imagenes de proveedor, en orden
   3) Logo del sitio

   Si una imagen falla en el navegador, onerror prueba la siguiente
   candidata. Esto evita una peticion HTTP previa por imagen y permite
   que un proveedor tenga varias URLs aunque alguna este rota.
========================================================== */
if (!function_exists('dht_front_mobile_add_attachment_candidates')) {
    function dht_front_mobile_add_attachment_candidates(&$candidates, $attachment_id, $size = 'woocommerce_thumbnail', $source = 'media') {
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

if (!function_exists('dht_front_mobile_get_media_product_image_candidates')) {
    function dht_front_mobile_get_media_product_image_candidates($product_id, $size = 'woocommerce_thumbnail') {
        global $wpdb;

        $product_id = absint($product_id);
        if ($product_id < 1) {
            return array();
        }

        $candidates = array();
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;

        /* WooCommerce: destacada y galeria son siempre la primera prioridad. */
        if ($product && is_a($product, 'WC_Product')) {
            dht_front_mobile_add_attachment_candidates($candidates, $product->get_image_id(), $size, 'media');
            foreach ((array) $product->get_gallery_image_ids() as $gallery_id) {
                dht_front_mobile_add_attachment_candidates($candidates, $gallery_id, $size, 'media');
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
                    dht_front_mobile_add_attachment_candidates($candidates, $attachment_id, $size, 'media');
                }

                /* URL de origen como ultimo recurso dentro del bloque Media. */
                $external_url = isset($row->url_origen) ? esc_url_raw($row->url_origen) : '';
                if ($external_url && in_array(strtolower((string) wp_parse_url($external_url, PHP_URL_SCHEME)), array('http', 'https'), true)) {
                    $candidates[] = array(
                        'attachment_id' => 0,
                        'url'           => $external_url,
                        'source'        => 'media-origin',
                    );
                }
            }
        }

        return $candidates;
    }
}

if (!function_exists('dht_front_mobile_get_supplier_product_image_candidates')) {
    function dht_front_mobile_get_supplier_product_image_candidates($product_id) {
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

        /* Todas las URLs activas: principal primero y luego el resto. */
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
                if ($supplier_url && in_array(strtolower((string) wp_parse_url($supplier_url, PHP_URL_SCHEME)), array('http', 'https'), true)) {
                    $candidates[] = array(
                        'attachment_id' => 0,
                        'url'           => $supplier_url,
                        'source'        => 'supplier',
                    );
                }
            }
        }

        /* Compatibilidad con el indice/API: se deduplicara despues. */
        if (function_exists('seo_supplier_v2_external_primary_url')) {
            $supplier_url = esc_url_raw(seo_supplier_v2_external_primary_url($product_id));
            if ($supplier_url && in_array(strtolower((string) wp_parse_url($supplier_url, PHP_URL_SCHEME)), array('http', 'https'), true)) {
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

if (!function_exists('dht_front_mobile_get_site_logo_candidate')) {
    function dht_front_mobile_get_site_logo_candidate() {
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

if (!function_exists('dht_front_mobile_get_product_image_candidates')) {
    function dht_front_mobile_get_product_image_candidates($product_id, $size = 'woocommerce_thumbnail') {
        static $cache = array();

        $cache_key = absint($product_id) . '|' . (string) $size;
        if (isset($cache[$cache_key])) {
            return $cache[$cache_key];
        }

        $all = array_merge(
            dht_front_mobile_get_media_product_image_candidates($product_id, $size),
            dht_front_mobile_get_supplier_product_image_candidates($product_id)
        );

        $logo = dht_front_mobile_get_site_logo_candidate();
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

        $cache[$cache_key] = $unique;
        return $unique;
    }
}

if (!function_exists('dht_front_mobile_get_external_product_image')) {
    function dht_front_mobile_get_external_product_image($product_id, $size = 'woocommerce_thumbnail') {
        $candidates = dht_front_mobile_get_product_image_candidates($product_id, $size);
        return !empty($candidates) ? $candidates[0] : null;
    }
}

if (!function_exists('dht_front_mobile_build_image_fallback_onerror')) {
    function dht_front_mobile_build_image_fallback_onerror($fallback_urls) {
        $urls = array();
        foreach ((array) $fallback_urls as $url) {
            $url = esc_url_raw($url);
            if ($url && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        if (empty($urls)) {
            return 'this.onerror=null;';
        }

        $json = wp_json_encode(
            array_values($urls),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return "var f={$json},i=parseInt(this.getAttribute('data-dht-fallback-index')||'0',10);"
            . "this.removeAttribute('srcset');this.removeAttribute('sizes');"
            . "if(i<f.length){this.setAttribute('data-dht-fallback-index',String(i+1));this.src=f[i];}"
            . "else{this.onerror=null;}";
    }
}

if (!function_exists('dht_front_mobile_product_image_external_fallback')) {
    function dht_front_mobile_product_image_external_fallback($image, $product, $size, $attr, $placeholder, $original = '') {
        if (!is_a($product, 'WC_Product')) {
            return $image;
        }

        $candidates = dht_front_mobile_get_product_image_candidates($product->get_id(), $size);
        if (empty($candidates)) {
            return $image;
        }

        $selected = array_shift($candidates);
        if (empty($selected['url'])) {
            return $image;
        }

        $fallback_urls = array();
        foreach ($candidates as $candidate) {
            if (!empty($candidate['url'])) {
                $fallback_urls[] = $candidate['url'];
            }
        }

        $attr = is_array($attr) ? $attr : array();
        $is_logo = isset($selected['source']) && 'logo' === $selected['source'];
        $alt = $is_logo ? get_bloginfo('name') : (!empty($attr['alt']) ? $attr['alt'] : $product->get_name());
        $onerror = dht_front_mobile_build_image_fallback_onerror($fallback_urls);

        if (!empty($selected['attachment_id'])) {
            $attachment_id = absint($selected['attachment_id']);
            $requested_url = wp_get_attachment_image_url($attachment_id, $size);

            /* Mantiene srcset para Media local; si falla, onerror lo elimina antes del fallback. */
            if ($requested_url && esc_url_raw($requested_url) === esc_url_raw($selected['url'])) {
                $local_attr = array_merge($attr, array(
                    'alt'                     => $alt,
                    'loading'                 => $attr['loading'] ?? 'lazy',
                    'decoding'                => 'async',
                    'class'                   => trim(($attr['class'] ?? '') . ' attachment-woocommerce_thumbnail size-woocommerce_thumbnail wp-post-image dht-product-image dht-media-product-image'),
                    'onerror'                 => $onerror,
                    'data-dht-fallback-index' => '0',
                ));

                $local = wp_get_attachment_image($attachment_id, $size, false, $local_attr);
                if ($local) {
                    return $local;
                }
            }
        }

        return sprintf(
            '<img src="%s" alt="%s" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail wp-post-image dht-product-image dht-%s-product-image" loading="lazy" decoding="async" data-dht-fallback-index="0" onerror="%s">',
            esc_url($selected['url']),
            esc_attr($alt),
            esc_attr($selected['source'] ?? 'external'),
            esc_attr($onerror)
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

        $external = dht_front_mobile_get_external_product_image($product_id);
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

    add_filter('woocommerce_product_get_image', 'dht_front_mobile_product_image_external_fallback', 20, 6);

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

    remove_filter('woocommerce_product_get_image', 'dht_front_mobile_product_image_external_fallback', 20);
};

/* Estructura editorial real: cluster -> hub primario -> hub secundario. */
$cluster_ids = array();
$hub_primary_ids = array();
$hub_secondary_by_primary = array();
$relations_table = $wpdb->prefix . 'seo_relations';
$table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $relations_table));

if ($table_exists === $relations_table) {
    $cluster_ids = $wpdb->get_col(
        "SELECT source_id
         FROM {$relations_table}
         WHERE source_type = 'cluster'
         GROUP BY source_id
         ORDER BY MIN(id) ASC
         LIMIT 12"
    );
    $cluster_ids = dht_template_public_post_ids($cluster_ids);

    /* Prioridad estrategica de portada: negocio profesional primero, despues automocion y bricolaje. */
    $cluster_weights = array(
        'equipamiento-profesional' => 300,
        'automocion'               => 200,
        'bricolaje'                => 100,
    );
    usort($cluster_ids, static function ($left, $right) use ($cluster_weights) {
        $left_key  = sanitize_title(get_the_title($left));
        $right_key = sanitize_title(get_the_title($right));
        $left_weight  = $cluster_weights[$left_key] ?? 0;
        $right_weight = $cluster_weights[$right_key] ?? 0;
        if ($left_weight === $right_weight) {
            return $left <=> $right;
        }
        return $right_weight <=> $left_weight;
    });
    $cluster_ids = dht_template_public_post_ids(array_slice($cluster_ids, 0, 6));

    $primary_from_targets = $wpdb->get_col(
        "SELECT target_id
         FROM {$relations_table}
         WHERE target_type = 'hub_primary'
         GROUP BY target_id
         ORDER BY MIN(id) ASC
         LIMIT 30"
    );
    $primary_from_sources = $wpdb->get_col(
        "SELECT source_id
         FROM {$relations_table}
         WHERE source_type = 'hub_primary'
         GROUP BY source_id
         ORDER BY MIN(id) ASC
         LIMIT 30"
    );
    $hub_primary_ids = dht_template_public_post_ids(array_merge($primary_from_targets, $primary_from_sources));

    /* La portada no muestra todos los hubs ni depende del orden de alta.
     * Reparte hasta ocho hubs entre los clusters visibles para representar
     * de forma equilibrada la arquitectura real del catalogo. */
    if (!empty($hub_primary_ids) && !empty($cluster_ids)) {
        $candidate_lookup = array_fill_keys(array_map('absint', $hub_primary_ids), true);
        $cluster_hub_rows = $wpdb->get_results(
            "SELECT source_id, target_id, MIN(id) AS sort_id
             FROM {$relations_table}
             WHERE source_type = 'cluster'
               AND target_type = 'hub_primary'
               AND relation_type IN ('cluster_to_primary', 'cluster_to_hub_primary')
             GROUP BY source_id, target_id
             ORDER BY sort_id ASC"
        );
        $hubs_by_cluster = array();
        foreach ((array) $cluster_hub_rows as $row) {
            $cluster_id = absint($row->source_id ?? 0);
            $hub_id = absint($row->target_id ?? 0);
            if ($cluster_id < 1 || $hub_id < 1 || empty($candidate_lookup[$hub_id])) {
                continue;
            }
            $hubs_by_cluster[$cluster_id][] = $hub_id;
        }

        $selected_hubs = array();
        $max_front_hubs = 8;
        for ($round = 0; $round < $max_front_hubs && count($selected_hubs) < $max_front_hubs; $round++) {
            foreach ($cluster_ids as $cluster_id) {
                $candidate = $hubs_by_cluster[$cluster_id][$round] ?? 0;
                if ($candidate && !in_array($candidate, $selected_hubs, true)) {
                    $selected_hubs[] = $candidate;
                    if (count($selected_hubs) >= $max_front_hubs) {
                        break 2;
                    }
                }
            }
        }
        foreach ($hub_primary_ids as $candidate) {
            if (count($selected_hubs) >= $max_front_hubs) {
                break;
            }
            if (!in_array($candidate, $selected_hubs, true)) {
                $selected_hubs[] = $candidate;
            }
        }
        $hub_primary_ids = dht_template_public_post_ids($selected_hubs);
    } else {
        $hub_primary_ids = array_slice($hub_primary_ids, 0, 8);
    }

    if (!empty($hub_primary_ids)) {
        $primary_lookup = array_fill_keys(array_map('absint', $hub_primary_ids), true);
        $secondary_rows = $wpdb->get_results(
            "SELECT source_id, target_id, MIN(id) AS sort_id
             FROM {$relations_table}
             WHERE source_type = 'hub_primary'
               AND target_type = 'hub_secondary'
               AND relation_type = 'hub_primary_to_hub_secondary'
             GROUP BY source_id, target_id
             ORDER BY sort_id ASC"
        );

        foreach ((array) $secondary_rows as $row) {
            $primary_id = absint($row->source_id ?? 0);
            $secondary_id = absint($row->target_id ?? 0);
            if ($primary_id < 1 || $secondary_id < 1 || empty($primary_lookup[$primary_id])) {
                continue;
            }
            if (!isset($hub_secondary_by_primary[$primary_id])) {
                $hub_secondary_by_primary[$primary_id] = array();
            }
            if (count($hub_secondary_by_primary[$primary_id]) >= 3) {
                continue;
            }
            if (get_post_status($secondary_id) === 'publish' && get_permalink($secondary_id)) {
                $hub_secondary_by_primary[$primary_id][] = $secondary_id;
            }
        }
    }
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

$department_categories = array_slice($root_categories, 0, 8);
$more_categories = array_slice($root_categories, 8, 8);

/* Presentacion del Dependiente y servicio humano sin depender de archivos adicionales. */
$dht_service_url = home_url('/nuestro-servicio/');
$dht_phone_label = '+34 640 87 45 40';
$dht_phone_href  = 'tel:+34640874540';
$dht_whatsapp_url = 'https://wa.me/34640874540';

$dht_dependiente_image = '';

/*
 * La imagen del Dependiente se localiza por el archivo real de Media y no
 * solamente por el slug del attachment. WordPress puede modificar el slug
 * aunque el fichero siga llamandose dependiente.webp.
 */
$dht_dependiente_relative_file = '2026/09/dependiente.webp';
$dht_dependiente_ids = get_posts(array(
    'post_type'      => 'attachment',
    'post_status'    => 'inherit',
    'posts_per_page' => 1,
    'fields'         => 'ids',
    'meta_key'       => '_wp_attached_file',
    'meta_value'     => $dht_dependiente_relative_file,
    'no_found_rows'  => true,
));

if (!empty($dht_dependiente_ids)) {
    $dht_dependiente_image = wp_get_attachment_image_url((int) $dht_dependiente_ids[0], 'large');
    if (!$dht_dependiente_image) {
        $dht_dependiente_image = wp_get_attachment_url((int) $dht_dependiente_ids[0]);
    }
}

/* Fallback directo al archivo si Media no devuelve el attachment. */
if (!$dht_dependiente_image) {
    $dht_upload_dir = wp_upload_dir();
    if (empty($dht_upload_dir['error']) && !empty($dht_upload_dir['baseurl'])) {
        $dht_dependiente_image = trailingslashit($dht_upload_dir['baseurl']) . $dht_dependiente_relative_file;
    }
}

$dht_dependiente_image = (string) apply_filters('dht_front_dependiente_image_url', $dht_dependiente_image);
?>



<main class="dht-storefront dht-storefront--mobile dht-front-structure" id="dht-storefront">
    <div class="sf-layout sf-layout--mobile">
        <section class="sf-mobile-entry" aria-labelledby="dht-home-title-mobile">
            <div class="sf-mobile-shell">
                <div class="sf-mobile-finder">
                    <div class="sf-mobile-finder-copy">
                        <span class="sf-eyebrow">Tu Dependiente del catálogo</span>
                        <h1 id="dht-home-title-mobile">Encuentra lo que necesitas</h1>
                        <p>Busca por producto, necesidad, aplicación, medida, marca o referencia. Dependiente relaciona tu búsqueda con el catálogo y compara opciones.</p>
                        <div class="sf-home-search">
                            <?php if (shortcode_exists('seo_search')) : ?>
                                <?php echo do_shortcode('[seo_search placeholder="Producto, necesidad, uso o referencia..."]'); ?>
                            <?php else : ?>
                                <form class="sf-home-search-form" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
                                    <label class="screen-reader-text" for="dht-home-search-mobile">Buscar productos</label>
                                    <input id="dht-home-search-mobile" type="search" name="s" placeholder="Producto, necesidad, uso o referencia..." autocomplete="off">
                                    <input type="hidden" name="post_type" value="product">
                                    <button type="submit">Buscar</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <div class="sf-search-examples"><span>extractor rodamientos</span><span>taladro hormigón</span><span>kit plato ducha</span><span>compresor aire</span></div>
                        <a class="sf-mobile-catalog-link" href="<?php echo esc_url(dht_template_shop_url()); ?>">Ver todo el catálogo →</a>
                    </div>
                    <div class="sf-mobile-person">
                        <?php if ($dht_dependiente_image) : ?>
                            <img src="<?php echo esc_url($dht_dependiente_image); ?>" alt="Persona del equipo de Distribuidor de Herramientas" loading="eager" fetchpriority="high">
                        <?php else : ?>
                            <div class="sf-mobile-person-fallback"><svg viewBox="0 0 200 200" aria-hidden="true" focusable="false"><circle cx="100" cy="68" r="38" fill="currentColor" opacity=".95"/><path d="M35 180c7-45 32-68 65-68s58 23 65 68H35z" fill="currentColor" opacity=".95"/><path d="M58 68c3-29 20-47 42-47s39 18 42 47c-12-9-27-14-42-14s-30 5-42 14z" fill="currentColor" opacity=".75"/></svg></div>
                        <?php endif; ?>
                        <div class="sf-mobile-person-badge"><strong>Dependiente te ayuda a buscar</strong><span>Si prefieres una persona, también puedes llamarnos.</span></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="sf-mobile-service" aria-labelledby="dht-service-title-mobile">
            <div class="sf-mobile-shell">
                <div class="sf-mobile-service-panel">
                    <span class="sf-service-kicker">Soporte personal antes y después de la compra</span>
                    <h2 id="dht-service-title-mobile">Te ayudamos como intermediarios</h2>
                    <p>Si surge una incidencia, te ayudamos en castellano con la comunicación y el seguimiento frente al fabricante o distribuidor.</p>
                    <div class="sf-mobile-service-grid">
                        <div class="sf-mobile-service-point"><strong>Antes de comprar</strong><span>Orientación para encontrar y comparar opciones.</span></div>
                        <div class="sf-mobile-service-point"><strong>Después de comprar</strong><span>Ayuda con correos, tickets y seguimiento de incidencias.</span></div>
                        <div class="sf-mobile-service-point"><strong>Proveedores extranjeros</strong><span>Te ayudamos a gestionar la comunicación cuando el proceso se complica.</span></div>
                    </div>
                    <div class="sf-mobile-service-actions">
                        <a href="<?php echo esc_url($dht_phone_href); ?>">Llamar</a>
                        <a href="<?php echo esc_url($dht_whatsapp_url); ?>" target="_blank" rel="noopener noreferrer">WhatsApp</a>
                    </div>
                    <small class="sf-service-legal">Somos apoyo e interlocutor en la gestión; no sustituimos el soporte técnico del fabricante ni alteramos las responsabilidades y garantías legales aplicables.</small>
                </div>
            </div>
        </section>

        <?php if (!empty($cluster_ids)) : ?>
            <section class="sf-mobile-section sf-mobile-section--structure">
                <div class="sf-mobile-shell">
                    <div class="sf-mobile-heading"><div><span class="sf-eyebrow">Visión general</span><h2>Empieza por un área</h2></div></div>
                    <div class="sf-mobile-cluster-track sf-mobile-cluster-track--light">
                        <?php foreach ($cluster_ids as $cluster_id) :
                            $image = $get_cluster_image($cluster_id);
                            ?>
                            <a class="sf-mobile-cluster sf-mobile-cluster--light" href="<?php echo esc_url(get_permalink($cluster_id)); ?>">
                                <span><?php if ($image) : ?><img src="<?php echo esc_url($image); ?>" alt="" loading="lazy"><?php else : ?><span class="sf-structure-placeholder" aria-hidden="true">Área</span><?php endif; ?></span>
                                <strong><?php echo esc_html(get_the_title($cluster_id)); ?></strong>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($hub_primary_ids)) : ?>
            <section class="sf-mobile-section sf-mobile-section--hubs">
                <div class="sf-mobile-shell">
                    <div class="sf-mobile-heading"><div><span class="sf-eyebrow">Explora por especialidad</span><h2>Áreas principales</h2></div></div>
                    <div class="sf-mobile-hub-list">
                        <?php foreach ($hub_primary_ids as $hub_id) :
                            $secondary_ids = $hub_secondary_by_primary[$hub_id] ?? array();
                            ?>
                            <article class="sf-mobile-hub-card">
                                <a class="sf-mobile-hub-title" href="<?php echo esc_url(get_permalink($hub_id)); ?>">
                                    <strong><?php echo esc_html(get_the_title($hub_id)); ?></strong>
                                    <span aria-hidden="true">→</span>
                                </a>
                                <?php if (!empty($secondary_ids)) : ?>
                                    <div class="sf-mobile-secondary-links">
                                        <?php foreach (array_slice($secondary_ids, 0, 3) as $secondary_id) : ?>
                                            <a href="<?php echo esc_url(get_permalink($secondary_id)); ?>"><?php echo esc_html(get_the_title($secondary_id)); ?></a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($root_categories)) : ?>
            <nav class="sf-mobile-chips sf-mobile-chips--after-structure" aria-label="Categorías rápidas">
                <div class="sf-mobile-shell sf-mobile-chip-track">
                    <?php foreach (array_slice($root_categories, 0, 12) as $term) : ?>
                        <a href="<?php echo esc_url(dht_template_safe_term_link($term)); ?>"><?php echo esc_html($term->name); ?></a>
                    <?php endforeach; ?>
                </div>
            </nav>
        <?php endif; ?>

        <section class="sf-mobile-section sf-mobile-section--categories">
            <div class="sf-mobile-shell">
                <div class="sf-mobile-heading"><h2>Categorías con más referencias</h2><a href="<?php echo esc_url(dht_template_shop_url()); ?>">Ver todas</a></div>
                <div class="sf-mobile-category-grid">
                    <?php foreach (array_slice($root_categories, 0, 10) as $term) :
                        $image = $get_term_image($term, 'woocommerce_thumbnail');
                        ?>
                        <a class="sf-mobile-category" href="<?php echo esc_url(dht_template_safe_term_link($term)); ?>">
                            <span><?php if ($image) : ?><img src="<?php echo esc_url($image); ?>" alt="" loading="lazy"><?php endif; ?></span>
                            <strong><?php echo esc_html($term->name); ?></strong>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="sf-mobile-section sf-mobile-section--products">
            <div class="sf-mobile-shell">
                <div class="sf-mobile-heading"><h2>Productos populares</h2><a href="<?php echo esc_url(dht_template_shop_url()); ?>">Ver más</a></div>
                <?php $render_products(array_slice($popular_products, 0, 10), 'sf-products--mobile'); ?>
            </div>
        </section>

        <section class="sf-mobile-promo sf-mobile-promo--split">
            <div class="sf-mobile-shell">
                <div class="sf-mobile-help">
                    <span>Antes de comprar</span>
                    <strong>Busca por producto, uso, necesidad o referencia.</strong>
                    <a href="#dht-home-title-mobile">Volver al buscador</a>
                </div>
                <div class="sf-mobile-help sf-mobile-help--service">
                    <span>Después de comprar</span>
                    <strong>Si hay una incidencia, te ayudamos como intermediarios en la gestión.</strong>
                    <a href="<?php echo esc_url($dht_service_url); ?>">Cómo funciona</a>
                </div>
            </div>
        </section>

        <?php if (!empty($sale_products)) : ?>
            <section class="sf-mobile-section sf-mobile-section--products">
                <div class="sf-mobile-shell">
                    <div class="sf-mobile-heading"><h2>Ofertas</h2></div>
                    <?php $render_products(array_slice($sale_products, 0, 10), 'sf-products--mobile'); ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="sf-mobile-section sf-mobile-section--products">
            <div class="sf-mobile-shell">
                <div class="sf-mobile-heading"><h2>Novedades</h2></div>
                <?php $render_products(array_slice($new_products, 0, 10), 'sf-products--mobile'); ?>
            </div>
        </section>

        <?php if (!empty($latest_posts)) : ?>
            <section class="sf-mobile-section sf-mobile-section--content">
                <div class="sf-mobile-shell">
                    <div class="sf-mobile-heading"><h2>Guías rápidas</h2><a href="<?php echo esc_url(dht_template_blog_url()); ?>">Blog</a></div>
                    <div class="sf-mobile-article-list">
                        <?php foreach (array_slice($latest_posts, 0, 3) as $article) :
                            $image = dht_template_post_image_url($article->ID, 'thumbnail');
                            ?>
                            <a class="sf-mobile-article" href="<?php echo esc_url(get_permalink($article)); ?>">
                                <span><?php if ($image) : ?><img src="<?php echo esc_url($image); ?>" alt="" loading="lazy"><?php endif; ?></span>
                                <strong><?php echo esc_html(get_the_title($article)); ?></strong>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    </div>
</main>

<?php dht_template_render_footer(); ?>
