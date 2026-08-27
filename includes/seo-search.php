<?php
/**
 * SEO Search 2.0
 * Buscador avanzado de productos para WooCommerce.
 *
 * Este archivo puede cargarse como módulo desde otro plugin. Registra
 * su propia pantalla independiente "SEO Search" en el administrador de WordPress.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* =========================================================
   CONSTANTES Y AJUSTES
========================================================= */

if (!defined('SEO_SEARCH_VERSION')) {
    define('SEO_SEARCH_VERSION', '2.2.0');
}

if (!defined('SEO_SEARCH_OPTION')) {
    define('SEO_SEARCH_OPTION', 'seo_search_options');
}

function seo_search_default_options() {
    return array(
        'results_per_page'      => 24,
        'autocomplete_enabled'  => 1,
        'autocomplete_limit'    => 8,
        'autocomplete_min_chars'=> 2,
        'search_title'          => 1,
        'search_content'        => 1,
        'search_sku'            => 1,
        'search_categories'     => 1,
        'search_tags'           => 0, // Legacy: product_tag ya no es la fuente semantica.
        'search_vocabulary'     => 1,
        'search_attributes'     => 1,
        'custom_meta_keys'      => '',
        'typo_tolerance'        => 1,
        'fuzzy_scan_limit'      => 1200,
        'synonyms'              => "movil=telefono,smartphone\nordenador=computadora,pc",
        'show_image'            => 1,
        'show_price'            => 1,
        'show_stock'            => 1,
        'show_sku'              => 1,
        'show_category'         => 1,
        'show_excerpt'          => 0,
        'filter_categories'     => 1,
        'filter_tags'           => 0, // Legacy: product_tag.
        'filter_vocabulary'     => 1,
        'advanced_filter_rol'   => 1,
        'advanced_filter_tipo'  => 1,
        'advanced_filter_aplicacion' => 1,
        'advanced_filter_plataforma' => 1,
        'advanced_filter_subtipo' => 1,
        'advanced_show_counts'  => 1,
        'advanced_auto_submit'  => 0,
        'filter_brand'          => 1,
        'brand_taxonomy'        => 'product_brand',
        'filter_attributes'     => 1,
        'filter_price'          => 1,
        'filter_stock'          => 1,
        'default_layout'        => 'grid',
        'grid_columns'          => 4,
        'log_searches'          => 1,
        'query_parameter'       => 'q',
        'results_page_id'       => 0,
        'no_results_text'       => 'No hemos encontrado productos. Prueba con otros términos o elimina algún filtro.',
    );
}

function seo_search_get_options() {
    $saved = get_option(SEO_SEARCH_OPTION, array());
    return wp_parse_args(is_array($saved) ? $saved : array(), seo_search_default_options());
}

function seo_search_get_option($key, $default = null) {
    $options = seo_search_get_options();
    return array_key_exists($key, $options) ? $options[$key] : $default;
}



/* =========================================================
   UTILIDADES DE TEXTO, SINÓNIMOS Y FILTROS
========================================================= */

function seo_search_normalize_text($text) {
    $text = wp_strip_all_tags((string) $text);
    $text = remove_accents($text);
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    $text = preg_replace('/[^a-z0-9\s\-_\.]/u', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function seo_search_parse_synonyms($raw) {
    $map = array();
    $lines = preg_split('/\r\n|\r|\n/', (string) $raw);

    foreach ($lines as $line) {
        if (false === strpos($line, '=')) {
            continue;
        }

        list($left, $right) = array_map('trim', explode('=', $line, 2));
        $left = seo_search_normalize_text($left);
        if ('' === $left) {
            continue;
        }

        $values = array_filter(array_map('trim', explode(',', $right)));
        $values = array_values(array_unique(array_filter(array_map('seo_search_normalize_text', $values))));
        if ($values) {
            $map[$left] = $values;
        }
    }

    return $map;
}

function seo_search_expand_terms($keyword) {
    $normalized = seo_search_normalize_text($keyword);
    if ('' === $normalized) {
        return array();
    }

    $terms = array($normalized);
    $words = array_values(array_filter(explode(' ', $normalized)));
    foreach ($words as $word) {
        if (strlen($word) >= 2) {
            $terms[] = $word;
        }
    }

    $synonyms = seo_search_parse_synonyms(seo_search_get_option('synonyms', ''));
    foreach ($synonyms as $source => $alternatives) {
        if ($normalized === $source || in_array($source, $words, true)) {
            $terms = array_merge($terms, $alternatives);
        }
        foreach ($alternatives as $alternative) {
            if ($normalized === $alternative || in_array($alternative, $words, true)) {
                $terms[] = $source;
                $terms = array_merge($terms, array_diff($alternatives, array($alternative)));
            }
        }
    }

    $terms = array_values(array_unique(array_filter($terms)));
    return array_slice($terms, 0, 12);
}

function seo_search_get_custom_meta_keys() {
    $raw = (string) seo_search_get_option('custom_meta_keys', '');
    $keys = array_filter(array_map('trim', explode(',', $raw)));
    return array_values(array_unique(array_map('sanitize_key', $keys)));
}


function seo_search_vocabulary_groups() {
    return array(
        'rol'        => __('ROL', 'seo-search'),
        'tipo'       => __('TIPO', 'seo-search'),
        'aplicacion' => __('Aplicacion', 'seo-search'),
        'plataforma' => __('Plataforma', 'seo-search'),
        'subtipo'    => __('Subtipo', 'seo-search'),
    );
}

function seo_search_advanced_filter_groups() {
    $groups = seo_search_vocabulary_groups();
    $options = seo_search_get_options();

    foreach (array_keys($groups) as $group) {
        $option_key = 'advanced_filter_' . $group;
        if (empty($options[$option_key])) {
            unset($groups[$group]);
        }
    }

    return $groups;
}


function seo_search_vocabulary_tables_ready() {
    global $wpdb;

    $vocabulary = $wpdb->prefix . 'seo_vocabulary';
    $objects = $wpdb->prefix . 'seo_object_vocabulary';

    static $ready = null;
    if (null !== $ready) {
        return $ready;
    }

    $v_exists = (string) $wpdb->get_var(
        $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($vocabulary))
    ) === $vocabulary;
    $o_exists = (string) $wpdb->get_var(
        $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($objects))
    ) === $objects;

    $ready = $v_exists && $o_exists;
    return $ready;
}

function seo_search_get_active_vocabulary_filters() {
    $result = array();
    $raw = isset($_GET['filter_vocab']) && is_array($_GET['filter_vocab'])
        ? wp_unslash($_GET['filter_vocab'])
        : array();

    foreach (seo_search_advanced_filter_groups() as $group => $label) {
        $value = isset($raw[$group]) ? $raw[$group] : array();
        $values = is_array($value) ? $value : array($value);
        $values = array_values(array_unique(array_filter(array_map('sanitize_title', $values))));
        if ($values) {
            $result[$group] = $values;
        }
    }

    return $result;
}

function seo_search_get_vocabulary_matching_product_ids($vocabulary_filters, $base_ids = array()) {
    global $wpdb;

    if (!seo_search_vocabulary_tables_ready() || !is_array($vocabulary_filters) || !$vocabulary_filters) {
        return array_values(array_unique(array_filter(array_map('absint', (array) $base_ids))));
    }

    $allowed = seo_search_vocabulary_groups();
    $clean = array();

    foreach ($vocabulary_filters as $group => $slugs) {
        $group = sanitize_key($group);
        if (!isset($allowed[$group])) {
            continue;
        }

        $slugs = is_array($slugs) ? $slugs : array($slugs);
        $slugs = array_values(array_unique(array_filter(array_map('sanitize_title', $slugs))));
        if ($slugs) {
            $clean[$group] = $slugs;
        }
    }

    if (!$clean) {
        return array_values(array_unique(array_filter(array_map('absint', (array) $base_ids))));
    }

    $vocabulary = $wpdb->prefix . 'seo_vocabulary';
    $objects = $wpdb->prefix . 'seo_object_vocabulary';

    $where_or = array();
    $where_params = array();
    $having = array();
    $having_params = array();

    foreach ($clean as $group => $slugs) {
        $slug_placeholders = implode(',', array_fill(0, count($slugs), '%s'));
        $where_or[] = "(v.semantic_group = %s AND v.slug IN ({$slug_placeholders}))";
        $where_params[] = $group;
        foreach ($slugs as $slug) {
            $where_params[] = $slug;
        }

        $having[] = "SUM(CASE WHEN v.semantic_group = %s AND v.slug IN ({$slug_placeholders}) THEN 1 ELSE 0 END) > 0";
        $having_params[] = $group;
        foreach ($slugs as $slug) {
            $having_params[] = $slug;
        }
    }

    $base_ids = array_values(array_unique(array_filter(array_map('absint', (array) $base_ids))));
    $base_sql = '';
    $base_params = array();
    if ($base_ids) {
        $base_placeholders = implode(',', array_fill(0, count($base_ids), '%d'));
        $base_sql = " AND ov.object_id IN ({$base_placeholders})";
        $base_params = $base_ids;
    }

    $sql = "
        SELECT ov.object_id
        FROM {$objects} ov
        INNER JOIN {$vocabulary} v
            ON v.id = ov.vocabulary_id
           AND v.active = 1
        INNER JOIN {$wpdb->posts} p
            ON p.ID = ov.object_id
           AND p.post_type = 'product'
           AND p.post_status = 'publish'
        WHERE ov.object_type = 'product'
          AND ov.status = 1
          AND (" . implode(' OR ', $where_or) . ")
          {$base_sql}
        GROUP BY ov.object_id
        HAVING " . implode(' AND ', $having) . "
        ORDER BY ov.object_id ASC
    ";

    $params = array_merge($where_params, $base_params, $having_params);
    return array_values(array_unique(array_map('absint', (array) $wpdb->get_col($wpdb->prepare($sql, $params)))));
}

function seo_search_build_global_vocabulary_facets() {
    global $wpdb;

    $result = array();
    foreach (seo_search_vocabulary_groups() as $group => $label) {
        $result[$group] = array();
    }

    if (!seo_search_vocabulary_tables_ready()) {
        return $result;
    }

    $vocabulary = $wpdb->prefix . 'seo_vocabulary';
    $objects = $wpdb->prefix . 'seo_object_vocabulary';

    $rows = $wpdb->get_results(
        "SELECT v.semantic_group, v.id, v.label AS name, v.slug,
                COUNT(DISTINCT ov.object_id) AS product_count
         FROM {$objects} ov
         INNER JOIN {$vocabulary} v
            ON v.id = ov.vocabulary_id
           AND v.active = 1
         INNER JOIN {$wpdb->posts} p
            ON p.ID = ov.object_id
           AND p.post_type = 'product'
           AND p.post_status = 'publish'
         WHERE ov.object_type = 'product'
           AND ov.status = 1
           AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
         GROUP BY v.semantic_group, v.id, v.label, v.slug
         HAVING product_count > 0
         ORDER BY v.semantic_group ASC, product_count DESC, v.label ASC",
        ARRAY_A
    );

    foreach ((array) $rows as $row) {
        $group = sanitize_key((string) ($row['semantic_group'] ?? ''));
        if (!isset($result[$group])) {
            continue;
        }
        $result[$group][] = array(
            'id'    => absint($row['id'] ?? 0),
            'name'  => (string) ($row['name'] ?? ''),
            'slug'  => (string) ($row['slug'] ?? ''),
            'count' => absint($row['product_count'] ?? 0),
        );
    }

    return $result;
}

function seo_search_current_request_value($key, $default = '') {
    if (!isset($_GET[$key]) || is_array($_GET[$key])) {
        return $default;
    }
    return sanitize_text_field(wp_unslash($_GET[$key]));
}

function seo_search_get_active_filters() {
    $attributes = array();
    $raw_attrs = isset($_GET['filter_attr']) && is_array($_GET['filter_attr']) ? wp_unslash($_GET['filter_attr']) : array();

    foreach ($raw_attrs as $taxonomy => $values) {
        $taxonomy = sanitize_key($taxonomy);
        if (0 !== strpos($taxonomy, 'pa_') || !taxonomy_exists($taxonomy)) {
            continue;
        }
        $values = is_array($values) ? $values : array($values);
        $values = array_values(array_filter(array_map('sanitize_title', $values)));
        if ($values) {
            $attributes[$taxonomy] = $values;
        }
    }

    return array(
        'category'  => sanitize_title((string) seo_search_current_request_value('filter_category', '')),
        'tag'       => sanitize_title((string) seo_search_current_request_value('filter_tag', '')),
        'brand'     => sanitize_title((string) seo_search_current_request_value('filter_brand', '')),
        'attributes'=> $attributes,
        'min_price' => isset($_GET['filter_min_price']) && !is_array($_GET['filter_min_price']) ? (function_exists('wc_format_decimal') ? wc_format_decimal(wp_unslash($_GET['filter_min_price'])) : sanitize_text_field(wp_unslash($_GET['filter_min_price']))) : '',
        'max_price' => isset($_GET['filter_max_price']) && !is_array($_GET['filter_max_price']) ? (function_exists('wc_format_decimal') ? wc_format_decimal(wp_unslash($_GET['filter_max_price'])) : sanitize_text_field(wp_unslash($_GET['filter_max_price']))) : '',
        'stock'     => in_array(seo_search_current_request_value('filter_stock', ''), array('instock', 'outofstock', 'onbackorder'), true) ? seo_search_current_request_value('filter_stock', '') : '',
        'vocabulary'=> seo_search_get_active_vocabulary_filters(),
        'orderby'   => in_array(seo_search_current_request_value('orderby', 'relevance'), array('relevance', 'date', 'price_asc', 'price_desc', 'title', 'popularity'), true) ? seo_search_current_request_value('orderby', 'relevance') : 'relevance',
        'layout'    => in_array(seo_search_current_request_value('layout', ''), array('grid', 'list'), true) ? seo_search_current_request_value('layout', '') : seo_search_get_option('default_layout', 'grid'),
    );
}

/* =========================================================
   ÍNDICE DE BÚSQUEDA Y MOTOR DE RELEVANCIA
========================================================= */

function seo_search_find_matching_ids($keyword, $max_ids = 5000) {
    global $wpdb;

    $terms = seo_search_expand_terms($keyword);
    if (!$terms) {
        return array();
    }

    $options = seo_search_get_options();
    $joins = array();
    $where_groups = array();
    $score_parts = array();
    $where_params = array();
    $score_params = array();

    $search_post_fields = array();
    if (!empty($options['search_title'])) {
        $search_post_fields[] = 'p.post_title';
    }
    if (!empty($options['search_content'])) {
        $search_post_fields[] = 'p.post_excerpt';
        $search_post_fields[] = 'p.post_content';
    }

    $meta_keys = seo_search_get_custom_meta_keys();
    if (!empty($options['search_sku'])) {
        array_unshift($meta_keys, '_sku');
    }
    $meta_keys = array_values(array_unique(array_filter($meta_keys)));

    if ($meta_keys || !empty($options['search_attributes'])) {
        $joins[] = "LEFT JOIN {$wpdb->postmeta} pm_search ON pm_search.post_id = p.ID";
    }

    $taxonomy_search = !empty($options['search_categories']) || !empty($options['search_attributes']);
    if ($taxonomy_search) {
        $joins[] = "LEFT JOIN {$wpdb->term_relationships} tr_search ON tr_search.object_id = p.ID";
        $joins[] = "LEFT JOIN {$wpdb->term_taxonomy} tt_search ON tt_search.term_taxonomy_id = tr_search.term_taxonomy_id";
        $joins[] = "LEFT JOIN {$wpdb->terms} t_search ON t_search.term_id = tt_search.term_id";
    }

    $vocabulary_search = !empty($options['search_vocabulary']) && seo_search_vocabulary_tables_ready();
    if ($vocabulary_search) {
        $objects_search = $wpdb->prefix . 'seo_object_vocabulary';
        $vocabulary_search_table = $wpdb->prefix . 'seo_vocabulary';
        $joins[] = "LEFT JOIN {$objects_search} ov_search
                    ON ov_search.object_type = 'product'
                   AND ov_search.object_id = CASE WHEN p.post_type = 'product_variation' THEN p.post_parent ELSE p.ID END
                   AND ov_search.status = 1";
        $joins[] = "LEFT JOIN {$vocabulary_search_table} v_search
                    ON v_search.id = ov_search.vocabulary_id
                   AND v_search.active = 1
                   AND v_search.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')";
    }

    foreach ($terms as $term_index => $term) {
        $like = '%' . $wpdb->esc_like($term) . '%';
        $prefix = $wpdb->esc_like($term) . '%';
        $group = array();

        foreach ($search_post_fields as $field) {
            $group[] = "LOWER({$field}) LIKE %s";
            $where_params[] = $like;
        }

        if ($meta_keys) {
            $placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
            $group[] = "(pm_search.meta_key IN ({$placeholders}) AND LOWER(pm_search.meta_value) LIKE %s)";
            foreach ($meta_keys as $meta_key) {
                $where_params[] = $meta_key;
            }
            $where_params[] = $like;
        }

        if (!empty($options['search_attributes'])) {
            $group[] = "((pm_search.meta_key = '_product_attributes' OR pm_search.meta_key LIKE 'attribute\\_%%') AND LOWER(pm_search.meta_value) LIKE %s)";
            $where_params[] = $like;
        }

        if ($taxonomy_search) {
            $tax_conditions = array();
            if (!empty($options['search_categories'])) {
                $tax_conditions[] = "tt_search.taxonomy = 'product_cat'";
            }
            if (!empty($options['search_attributes'])) {
                $tax_conditions[] = "tt_search.taxonomy LIKE 'pa\\_%%'";
            }
            if ($tax_conditions) {
                $group[] = '((' . implode(' OR ', $tax_conditions) . ') AND LOWER(t_search.name) LIKE %s)';
                $where_params[] = $like;
            }
        }

        if ($vocabulary_search) {
            $group[] = "(LOWER(v_search.label) LIKE %s OR LOWER(v_search.slug) LIKE %s)";
            $where_params[] = $like;
            $where_params[] = $like;
        }

        if ($group) {
            $where_groups[] = '(' . implode(' OR ', $group) . ')';
        }

        if (!empty($options['search_title'])) {
            $score_parts[] = 'MAX(CASE WHEN LOWER(p.post_title) = %s THEN 1200 WHEN LOWER(p.post_title) LIKE %s THEN 850 WHEN LOWER(p.post_title) LIKE %s THEN 520 ELSE 0 END)';
            $score_params[] = $term;
            $score_params[] = $prefix;
            $score_params[] = $like;
        }

        if (!empty($options['search_sku'])) {
            $score_parts[] = "MAX(CASE WHEN pm_search.meta_key = '_sku' AND LOWER(pm_search.meta_value) = %s THEN 1500 WHEN pm_search.meta_key = '_sku' AND LOWER(pm_search.meta_value) LIKE %s THEN 700 ELSE 0 END)";
            $score_params[] = $term;
            $score_params[] = $like;
        }

        if ($taxonomy_search) {
            $score_parts[] = 'MAX(CASE WHEN LOWER(t_search.name) = %s THEN 480 WHEN LOWER(t_search.name) LIKE %s THEN 260 ELSE 0 END)';
            $score_params[] = $term;
            $score_params[] = $like;
        }

        if ($vocabulary_search) {
            $score_parts[] = 'MAX(CASE WHEN LOWER(v_search.label) = %s THEN 760 WHEN LOWER(v_search.label) LIKE %s THEN 420 WHEN LOWER(v_search.slug) LIKE %s THEN 300 ELSE 0 END)';
            $score_params[] = $term;
            $score_params[] = $like;
            $score_params[] = $like;
        }
    }

    if (!$where_groups) {
        return array();
    }

    $score_sql = $score_parts ? implode(' + ', $score_parts) : '1';
    $join_sql = implode("\n", array_unique($joins));

    $sql = "
        SELECT
            CASE WHEN p.post_type = 'product_variation' THEN p.post_parent ELSE p.ID END AS product_id,
            ({$score_sql}) AS relevance
        FROM {$wpdb->posts} p
        {$join_sql}
        WHERE p.post_type IN ('product', 'product_variation')
          AND p.post_status = 'publish'
          AND (" . implode(' OR ', $where_groups) . ")
        GROUP BY product_id
        ORDER BY relevance DESC, product_id DESC
        LIMIT %d
    ";

    $params = array_merge($score_params, $where_params, array(absint($max_ids)));
    $prepared = $wpdb->prepare($sql, $params);
    $rows = $wpdb->get_results($prepared, ARRAY_A);

    $ids = array();
    foreach ($rows as $row) {
        $id = absint($row['product_id']);
        if ($id > 0) {
            $ids[$id] = isset($row['relevance']) ? (float) $row['relevance'] : 0;
        }
    }

    if (count($ids) < 5 && !empty($options['typo_tolerance'])) {
        $fuzzy_ids = seo_search_fuzzy_ids($keyword, absint($options['fuzzy_scan_limit']));
        foreach ($fuzzy_ids as $id => $score) {
            if (!isset($ids[$id])) {
                $ids[$id] = $score;
            }
        }
        arsort($ids, SORT_NUMERIC);
    }

    return array_keys($ids);
}

function seo_search_fuzzy_ids($keyword, $scan_limit = 1200) {
    global $wpdb;

    $needle = seo_search_normalize_text($keyword);
    $needle_words = array_values(array_filter(explode(' ', $needle), function ($word) {
        return strlen($word) >= 3;
    }));

    if (!$needle_words) {
        return array();
    }

    $cache_key = 'seo_search_fuzzy_index_' . min(5000, max(100, absint($scan_limit)));
    $index = get_transient($cache_key);

    if (!is_array($index)) {
        $limit = min(5000, max(100, absint($scan_limit)));
        $sql = $wpdb->prepare(
            "SELECT p.ID, p.post_title, sku.meta_value AS sku
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
             WHERE p.post_type = 'product' AND p.post_status = 'publish'
             ORDER BY p.post_modified_gmt DESC
             LIMIT %d",
            $limit
        );
        $index = $wpdb->get_results($sql, ARRAY_A);
        set_transient($cache_key, $index, 12 * HOUR_IN_SECONDS);
    }

    $matches = array();
    foreach ($index as $row) {
        $haystack = seo_search_normalize_text($row['post_title'] . ' ' . $row['sku']);
        $hay_words = array_values(array_filter(explode(' ', $haystack)));
        $total = 0;
        $matched = 0;

        foreach ($needle_words as $needle_word) {
            $best = 0;
            foreach ($hay_words as $hay_word) {
                if (abs(strlen($needle_word) - strlen($hay_word)) > 3) {
                    continue;
                }
                $distance = levenshtein($needle_word, $hay_word);
                $max_len = max(strlen($needle_word), strlen($hay_word));
                $similarity = $max_len ? 1 - ($distance / $max_len) : 0;
                $best = max($best, $similarity);
            }
            if ($best >= 0.66) {
                $matched++;
                $total += $best;
            }
        }

        if ($matched === count($needle_words)) {
            $matches[absint($row['ID'])] = 180 + (($total / max(1, $matched)) * 100);
        }
    }

    arsort($matches, SORT_NUMERIC);
    return array_slice($matches, 0, 40, true);
}

function seo_search_clear_fuzzy_cache() {
    global $wpdb;
    $keys = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_seo_search_fuzzy_index_%'");
    foreach ($keys as $key) {
        delete_transient(str_replace('_transient_', '', $key));
    }
}
add_action('save_post_product', 'seo_search_clear_fuzzy_cache');
add_action('save_post_product_variation', 'seo_search_clear_fuzzy_cache');

/* =========================================================
   CONSULTA DE PRODUCTOS, FILTROS Y FACETAS
========================================================= */

function seo_search_build_tax_query($filters) {
    $tax_query = array('relation' => 'AND');

    if (!empty($filters['category'])) {
        $tax_query[] = array('taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => $filters['category']);
    }
    $brand_taxonomy = seo_search_get_option('brand_taxonomy', 'product_brand');
    if (!empty($filters['brand']) && taxonomy_exists($brand_taxonomy)) {
        $tax_query[] = array('taxonomy' => $brand_taxonomy, 'field' => 'slug', 'terms' => $filters['brand']);
    }

    foreach ($filters['attributes'] as $taxonomy => $terms) {
        $tax_query[] = array(
            'taxonomy' => $taxonomy,
            'field'    => 'slug',
            'terms'    => $terms,
            'operator' => 'IN',
        );
    }

    return count($tax_query) > 1 ? $tax_query : array();
}

function seo_search_build_meta_query($filters) {
    $meta_query = array('relation' => 'AND');

    if ('' !== $filters['min_price'] || '' !== $filters['max_price']) {
        $price_clause = array(
            'key'     => '_price',
            'type'    => 'DECIMAL(20,6)',
            'compare' => 'BETWEEN',
            'value'   => array(
                '' !== $filters['min_price'] ? (float) $filters['min_price'] : 0,
                '' !== $filters['max_price'] ? (float) $filters['max_price'] : PHP_INT_MAX,
            ),
        );
        $meta_query[] = $price_clause;
    }

    if (!empty($filters['stock'])) {
        $meta_query[] = array('key' => '_stock_status', 'value' => $filters['stock'], 'compare' => '=');
    }

    return count($meta_query) > 1 ? $meta_query : array();
}

function seo_search_query_products($keyword, $page = 1, $limit = null, $filters = null) {
    $limit = null === $limit ? absint(seo_search_get_option('results_per_page', 24)) : absint($limit);
    $limit = min(100, max(1, $limit));
    $page = max(1, absint($page));
    $filters = is_array($filters) ? $filters : seo_search_get_active_filters();

    $matching_ids = seo_search_find_matching_ids($keyword);

    if ($matching_ids && !empty($filters['vocabulary'])) {
        $matching_ids = seo_search_get_vocabulary_matching_product_ids($filters['vocabulary'], $matching_ids);
    }

    if (!$matching_ids) {
        return array(
            'query' => null,
            'ids' => array(),
            'base_ids' => array(),
            'total' => 0,
            'pages' => 0,
            'facets' => seo_search_empty_facets(),
        );
    }

    $args = array(
        'post_type'           => 'product',
        'post_status'         => 'publish',
        'post__in'            => $matching_ids,
        'posts_per_page'      => $limit,
        'paged'               => $page,
        'ignore_sticky_posts' => true,
        'no_found_rows'       => false,
    );

    $tax_query = seo_search_build_tax_query($filters);
    if ($tax_query) {
        $args['tax_query'] = $tax_query;
    }

    $meta_query = seo_search_build_meta_query($filters);
    if ($meta_query) {
        $args['meta_query'] = $meta_query;
    }

    switch ($filters['orderby']) {
        case 'date':
            $args['orderby'] = 'date';
            $args['order'] = 'DESC';
            break;
        case 'price_asc':
            $args['meta_key'] = '_price';
            $args['orderby'] = 'meta_value_num';
            $args['order'] = 'ASC';
            break;
        case 'price_desc':
            $args['meta_key'] = '_price';
            $args['orderby'] = 'meta_value_num';
            $args['order'] = 'DESC';
            break;
        case 'title':
            $args['orderby'] = 'title';
            $args['order'] = 'ASC';
            break;
        case 'popularity':
            $args['meta_key'] = 'total_sales';
            $args['orderby'] = 'meta_value_num';
            $args['order'] = 'DESC';
            break;
        case 'relevance':
        default:
            $args['orderby'] = 'post__in';
            break;
    }

    $query = new WP_Query($args);
    $facets = seo_search_build_facets(array_slice($matching_ids, 0, 2000));

    return array(
        'query'    => $query,
        'ids'      => wp_list_pluck($query->posts, 'ID'),
        'base_ids' => $matching_ids,
        'total'    => absint($query->found_posts),
        'pages'    => absint($query->max_num_pages),
        'facets'   => $facets,
    );
}

function seo_search_products($keyword, $limit = 12) {
    $data = seo_search_query_products($keyword, 1, $limit, seo_search_get_active_filters());
    $results = array();

    if (!$data['query']) {
        return $results;
    }

    foreach ($data['query']->posts as $post) {
        $product = wc_get_product($post->ID);
        if (!$product) {
            continue;
        }
        $results[] = array(
            'id'    => $post->ID,
            'title' => get_the_title($post->ID),
            'url'   => get_permalink($post->ID),
            'price' => $product->get_price(),
            'sku'   => $product->get_sku(),
            'image' => get_the_post_thumbnail_url($post->ID, 'woocommerce_thumbnail'),
        );
    }

    return $results;
}

function seo_search_empty_facets() {
    return array('categories' => array(), 'tags' => array(), 'brands' => array(), 'attributes' => array(), 'vocabulary' => array(), 'price' => array('min' => '', 'max' => ''));
}

function seo_search_build_facets($product_ids) {
    global $wpdb;

    $product_ids = array_values(array_unique(array_filter(array_map('absint', (array) $product_ids))));
    if (!$product_ids) {
        return seo_search_empty_facets();
    }

    $taxonomies = array('product_cat');
    $brand_taxonomy = seo_search_get_option('brand_taxonomy', 'product_brand');
    if (taxonomy_exists($brand_taxonomy)) {
        $taxonomies[] = $brand_taxonomy;
    }

    if (function_exists('wc_get_attribute_taxonomies')) {
        foreach (wc_get_attribute_taxonomies() as $attribute) {
            $taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name);
            if (taxonomy_exists($taxonomy)) {
                $taxonomies[] = $taxonomy;
            }
        }
    }

    $taxonomies = array_values(array_unique($taxonomies));
    $id_sql = implode(',', $product_ids);
    $tax_placeholders = implode(',', array_fill(0, count($taxonomies), '%s'));

    $sql = "SELECT tt.taxonomy, t.term_id, t.name, t.slug, COUNT(DISTINCT tr.object_id) AS product_count
            FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
            WHERE tr.object_id IN ({$id_sql})
              AND tt.taxonomy IN ({$tax_placeholders})
            GROUP BY tt.taxonomy, t.term_id, t.name, t.slug
            ORDER BY tt.taxonomy ASC, product_count DESC, t.name ASC";

    $rows = $wpdb->get_results($wpdb->prepare($sql, $taxonomies), ARRAY_A);
    $facets = seo_search_empty_facets();

    foreach ($rows as $row) {
        $entry = array(
            'id'    => absint($row['term_id']),
            'name'  => $row['name'],
            'slug'  => $row['slug'],
            'count' => absint($row['product_count']),
        );

        if ('product_cat' === $row['taxonomy']) {
            $facets['categories'][] = $entry;
        } elseif ($brand_taxonomy === $row['taxonomy']) {
            $facets['brands'][] = $entry;
        } elseif (0 === strpos($row['taxonomy'], 'pa_')) {
            if (!isset($facets['attributes'][$row['taxonomy']])) {
                $facets['attributes'][$row['taxonomy']] = array(
                    'label' => function_exists('wc_attribute_label') ? wc_attribute_label($row['taxonomy']) : $row['taxonomy'],
                    'terms' => array(),
                );
            }
            $facets['attributes'][$row['taxonomy']]['terms'][] = $entry;
        }
    }

    if (seo_search_vocabulary_tables_ready()) {
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects = $wpdb->prefix . 'seo_object_vocabulary';

        $vocab_rows = $wpdb->get_results(
            "SELECT v.semantic_group, v.id, v.label AS name, v.slug,
                    COUNT(DISTINCT ov.object_id) AS product_count
             FROM {$objects} ov
             INNER JOIN {$vocabulary} v
                ON v.id = ov.vocabulary_id
               AND v.active = 1
             WHERE ov.object_type = 'product'
               AND ov.status = 1
               AND ov.object_id IN ({$id_sql})
               AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
             GROUP BY v.semantic_group, v.id, v.label, v.slug
             ORDER BY v.semantic_group ASC, product_count DESC, v.label ASC",
            ARRAY_A
        );

        foreach (seo_search_vocabulary_groups() as $group => $label) {
            $facets['vocabulary'][$group] = array();
        }

        foreach ((array) $vocab_rows as $row) {
            $group = sanitize_key((string) ($row['semantic_group'] ?? ''));
            if (!isset($facets['vocabulary'][$group])) {
                continue;
            }
            $facets['vocabulary'][$group][] = array(
                'id'    => absint($row['id'] ?? 0),
                'name'  => (string) ($row['name'] ?? ''),
                'slug'  => (string) ($row['slug'] ?? ''),
                'count' => absint($row['product_count'] ?? 0),
            );
        }
    }

    $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
    $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $lookup_table));
    if ($table_exists === $lookup_table) {
        $price_row = $wpdb->get_row("SELECT MIN(min_price) AS min_price, MAX(max_price) AS max_price FROM {$lookup_table} WHERE product_id IN ({$id_sql})", ARRAY_A);
        if ($price_row) {
            $facets['price']['min'] = null !== $price_row['min_price'] ? (float) $price_row['min_price'] : '';
            $facets['price']['max'] = null !== $price_row['max_price'] ? (float) $price_row['max_price'] : '';
        }
    }

    return $facets;
}

/* =========================================================
   REGISTRO Y ESTADÍSTICAS DE BÚSQUEDAS
========================================================= */

function seo_search_log_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'seo_search_log';
}

function seo_search_maybe_install_log_table() {
    $installed = get_option('seo_search_db_version', '');
    if (SEO_SEARCH_VERSION === $installed) {
        return;
    }

    global $wpdb;
    $table = seo_search_log_table_name();
    $charset = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        search_term VARCHAR(255) NOT NULL,
        normalized_term VARCHAR(255) NOT NULL,
        results_count INT UNSIGNED NOT NULL DEFAULT 0,
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        session_hash CHAR(64) NOT NULL DEFAULT '',
        searched_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY normalized_term (normalized_term),
        KEY results_count (results_count),
        KEY searched_at (searched_at)
    ) {$charset};");

    update_option('seo_search_db_version', SEO_SEARCH_VERSION, false);
}
add_action('admin_init', 'seo_search_maybe_install_log_table');

function seo_search_log_query($keyword, $results_count) {
    if (!seo_search_get_option('log_searches', 1)) {
        return;
    }

    seo_search_maybe_install_log_table();

    global $wpdb;
    $cookie_seed = isset($_COOKIE[LOGGED_IN_COOKIE]) ? wp_unslash($_COOKIE[LOGGED_IN_COOKIE]) : wp_get_session_token();
    $session_hash = hash('sha256', wp_salt('nonce') . '|' . $cookie_seed . '|' . seo_search_normalize_text($keyword));

    $wpdb->insert(
        seo_search_log_table_name(),
        array(
            'search_term'     => sanitize_text_field($keyword),
            'normalized_term' => seo_search_normalize_text($keyword),
            'results_count'   => absint($results_count),
            'user_id'         => get_current_user_id(),
            'session_hash'    => $session_hash,
            'searched_at'     => current_time('mysql'),
        ),
        array('%s', '%s', '%d', '%d', '%s', '%s')
    );
}

/* =========================================================
   AUTOCOMPLETADO AJAX
========================================================= */

function seo_search_ajax_autocomplete() {
    if (!class_exists('WooCommerce')) {
        wp_send_json_error(array('message' => __('WooCommerce no está activo.', 'seo-search')), 503);
    }

    check_ajax_referer('seo_search_autocomplete', 'nonce');

    $keyword = isset($_GET['term']) && !is_array($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';
    $min_chars = absint(seo_search_get_option('autocomplete_min_chars', 2));
    if (strlen(seo_search_normalize_text($keyword)) < $min_chars) {
        wp_send_json_success(array());
    }

    $limit = absint(seo_search_get_option('autocomplete_limit', 8));
    $products = seo_search_products($keyword, $limit);
    $payload = array();

    foreach ($products as $item) {
        $product = wc_get_product($item['id']);
        if (!$product) {
            continue;
        }
        $payload[] = array(
            'id'       => $item['id'],
            'title'    => $item['title'],
            'url'      => $item['url'],
            'sku'      => $item['sku'],
            'image'    => $item['image'] ? $item['image'] : wc_placeholder_img_src('woocommerce_thumbnail'),
            'price_html'=> $product->get_price_html(),
            'stock'    => $product->is_in_stock() ? __('En stock', 'seo-search') : __('Agotado', 'seo-search'),
        );
    }

    wp_send_json_success($payload);
}
add_action('wp_ajax_seo_search_autocomplete', 'seo_search_ajax_autocomplete');
add_action('wp_ajax_nopriv_seo_search_autocomplete', 'seo_search_ajax_autocomplete');

/* =========================================================
   SHORTCODE Y ACTIVOS DE FRONTEND
========================================================= */

function seo_search_results_url() {
    $page_id = absint(seo_search_get_option('results_page_id', 0));
    if ($page_id && 'publish' === get_post_status($page_id)) {
        return get_permalink($page_id);
    }
    return home_url('/');
}

function seo_search_enqueue_front_assets() {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    wp_enqueue_script('jquery');

    $config = array(
        'ajaxUrl'     => admin_url('admin-ajax.php'),
        'nonce'       => wp_create_nonce('seo_search_autocomplete'),
        'enabled'     => (bool) seo_search_get_option('autocomplete_enabled', 1),
        'minChars'    => absint(seo_search_get_option('autocomplete_min_chars', 2)),
        'searchLabel' => __('Ver todos los resultados', 'seo-search'),
        'emptyLabel'  => __('Sin coincidencias', 'seo-search'),
    );

    wp_add_inline_script('jquery', 'window.seoSearchConfig=' . wp_json_encode($config) . ';', 'before');
    wp_add_inline_script('jquery', seo_search_frontend_js());
    wp_register_style('seo-search-inline', false, array(), SEO_SEARCH_VERSION);
    wp_enqueue_style('seo-search-inline');
    wp_add_inline_style('seo-search-inline', seo_search_frontend_css());
}

function seo_search_frontend_js() {
    return <<<'JS'
(function($){
    'use strict';

    function escapeHtml(value) {
        return $('<div>').text(value || '').html();
    }

    function initSearch($box) {
        var $form = $box.find('.seo-search-form');
        var $input = $box.find('.seo-search-input');
        var $panel = $box.find('.seo-search-autocomplete');
        var timer = null;
        var request = null;
        var activeIndex = -1;

        if (!window.seoSearchConfig || !window.seoSearchConfig.enabled || !$panel.length) {
            return;
        }

        function closePanel() {
            $panel.attr('hidden', true).empty();
            activeIndex = -1;
        }

        function setActive(index) {
            var $items = $panel.find('[role="option"]');
            if (!$items.length) return;
            activeIndex = Math.max(0, Math.min(index, $items.length - 1));
            $items.removeClass('is-active').attr('aria-selected', 'false');
            $items.eq(activeIndex).addClass('is-active').attr('aria-selected', 'true');
        }

        function render(items, term) {
            var html = '';
            if (!items.length) {
                html = '<div class="seo-search-auto-empty">' + escapeHtml(window.seoSearchConfig.emptyLabel) + '</div>';
            } else {
                items.forEach(function(item){
                    html += '<a class="seo-search-auto-item" role="option" aria-selected="false" href="' + escapeHtml(item.url) + '">';
                    html += '<img src="' + escapeHtml(item.image) + '" alt="" loading="lazy">';
                    html += '<span class="seo-search-auto-copy"><strong>' + escapeHtml(item.title) + '</strong>';
                    if (item.sku) html += '<small>Ref. ' + escapeHtml(item.sku) + '</small>';
                    html += '</span>';
                    if (item.price_html) html += '<span class="seo-search-auto-price">' + item.price_html + '</span>';
                    html += '</a>';
                });
                html += '<button class="seo-search-auto-all" type="submit">' + escapeHtml(window.seoSearchConfig.searchLabel) + ' “' + escapeHtml(term) + '”</button>';
            }
            $panel.html(html).removeAttr('hidden');
        }

        $input.on('input', function(){
            var term = $.trim($input.val());
            clearTimeout(timer);
            if (request && request.readyState !== 4) request.abort();
            if (term.length < window.seoSearchConfig.minChars) {
                closePanel();
                return;
            }
            timer = setTimeout(function(){
                $box.addClass('is-loading');
                request = $.get(window.seoSearchConfig.ajaxUrl, {
                    action: 'seo_search_autocomplete',
                    nonce: window.seoSearchConfig.nonce,
                    term: term
                }).done(function(response){
                    render(response && response.success ? response.data : [], term);
                }).always(function(){
                    $box.removeClass('is-loading');
                });
            }, 220);
        });

        $input.on('keydown', function(event){
            if ($panel.is('[hidden]')) return;
            var count = $panel.find('[role="option"]').length;
            if (event.key === 'ArrowDown' && count) {
                event.preventDefault();
                setActive(activeIndex + 1);
            } else if (event.key === 'ArrowUp' && count) {
                event.preventDefault();
                setActive(activeIndex <= 0 ? count - 1 : activeIndex - 1);
            } else if (event.key === 'Enter' && activeIndex >= 0) {
                event.preventDefault();
                window.location.href = $panel.find('[role="option"]').eq(activeIndex).attr('href');
            } else if (event.key === 'Escape') {
                closePanel();
            }
        });

        $(document).on('click', function(event){
            if (!$.contains($box[0], event.target) && event.target !== $box[0]) closePanel();
        });
    }

    $(function(){
        $('.seo-search-box').each(function(){ initSearch($(this)); });

        $(document).on('click', '.seo-search-filter-toggle', function(){
            $('.seo-search-sidebar').toggleClass('is-open');
        });

        $(document).on('change', '.seo-search-auto-submit', function(){
            $(this).closest('form').trigger('submit');
        });
    });
})(jQuery);
JS;
}

function seo_search_frontend_css() {
    $columns = absint(seo_search_get_option('grid_columns', 4));
    return "
.seo-search-box{position:relative;width:100%;max-width:720px}.seo-search-form{display:flex;gap:8px;position:relative}.seo-search-input{width:100%;min-height:46px;padding:10px 14px;border:1px solid #d5d7da;border-radius:8px;font-size:16px;background:#fff}.seo-search-input:focus{outline:2px solid #2271b1;outline-offset:1px;border-color:#2271b1}.seo-search-button{min-width:48px;padding:10px 15px;border:0;border-radius:8px;background:#111;color:#fff;cursor:pointer;font-size:17px}.seo-search-button:hover{background:#333}.seo-search-box.is-loading:after{content:'';position:absolute;right:65px;top:15px;width:16px;height:16px;border:2px solid #ddd;border-top-color:#111;border-radius:50%;animation:seoSearchSpin .7s linear infinite}@keyframes seoSearchSpin{to{transform:rotate(360deg)}}
.seo-search-autocomplete{position:absolute;z-index:99999;top:calc(100% + 6px);left:0;right:0;max-height:470px;overflow:auto;background:#fff;border:1px solid #ddd;border-radius:10px;box-shadow:0 12px 34px rgba(0,0,0,.15)}.seo-search-auto-item{display:grid;grid-template-columns:54px 1fr auto;gap:12px;align-items:center;padding:10px 12px;color:inherit;text-decoration:none;border-bottom:1px solid #eee}.seo-search-auto-item:hover,.seo-search-auto-item.is-active{background:#f5f7f9}.seo-search-auto-item img{width:54px;height:54px;object-fit:cover;border-radius:6px}.seo-search-auto-copy{display:flex;flex-direction:column;min-width:0}.seo-search-auto-copy strong{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.seo-search-auto-copy small{color:#666}.seo-search-auto-price{font-weight:600;white-space:nowrap}.seo-search-auto-all{width:100%;padding:12px;border:0;background:#f7f7f7;cursor:pointer;font-weight:600}.seo-search-auto-empty{padding:16px;color:#666}
.seo-search-page{max-width:1440px;margin:0 auto;padding:28px 20px}.seo-search-page-header{display:flex;align-items:end;justify-content:space-between;gap:20px;margin:24px 0}.seo-search-page-header h1{margin:0}.seo-search-summary{color:#5c636a}.seo-search-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 18px;padding:12px;background:#f6f7f7;border-radius:8px}.seo-search-toolbar-right{display:flex;gap:8px;align-items:center}.seo-search-toolbar select{min-height:38px}.seo-search-filter-toggle{display:none;padding:9px 12px;border:1px solid #ccd0d4;background:#fff;border-radius:6px}.seo-search-content{display:grid;grid-template-columns:270px minmax(0,1fr);gap:28px}.seo-search-sidebar{border:1px solid #e1e3e5;border-radius:10px;padding:16px;align-self:start}.seo-search-filter-group{padding:0 0 16px;margin:0 0 16px;border-bottom:1px solid #ececec}.seo-search-filter-group:last-child{border-bottom:0;margin-bottom:0}.seo-search-filter-group h3{font-size:16px;margin:0 0 10px}.seo-search-filter-list{display:grid;gap:7px;max-height:240px;overflow:auto}.seo-search-filter-list label{display:flex;justify-content:space-between;gap:8px;font-size:14px}.seo-search-filter-list a{text-decoration:none;color:inherit}.seo-search-filter-count{color:#767676}.seo-search-price-fields{display:grid;grid-template-columns:1fr 1fr;gap:8px}.seo-search-price-fields input{width:100%}.seo-search-apply{width:100%;margin-top:10px;padding:9px;border:0;border-radius:6px;background:#2271b1;color:#fff;cursor:pointer}.seo-search-clear{display:block;text-align:center;margin-top:9px}.seo-search-products{display:grid;grid-template-columns:repeat({$columns},minmax(0,1fr));gap:20px}.seo-search-products.is-list{grid-template-columns:1fr}.seo-search-card{display:flex;flex-direction:column;border:1px solid #e2e4e7;border-radius:10px;overflow:hidden;background:#fff;transition:transform .15s,box-shadow .15s}.seo-search-card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.08)}.seo-search-products.is-list .seo-search-card{display:grid;grid-template-columns:180px 1fr}.seo-search-card-image{display:block;aspect-ratio:1/1;background:#f4f4f4}.seo-search-products.is-list .seo-search-card-image{aspect-ratio:auto;min-height:180px}.seo-search-card-image img{width:100%;height:100%;object-fit:cover}.seo-search-card-body{display:flex;flex-direction:column;gap:8px;padding:14px;height:100%}.seo-search-card-title{font-size:17px;line-height:1.3;margin:0}.seo-search-card-title a{text-decoration:none;color:inherit}.seo-search-card-meta{font-size:13px;color:#666}.seo-search-card-price{font-size:17px;font-weight:700;margin-top:auto}.seo-search-stock.in-stock{color:#16803a}.seo-search-stock.out-of-stock{color:#b32d2e}.seo-search-card-button{display:inline-flex;justify-content:center;padding:9px 12px;border-radius:6px;background:#111;color:#fff!important;text-decoration:none;margin-top:4px}.seo-search-pagination{display:flex;flex-wrap:wrap;gap:6px;justify-content:center;margin:28px 0}.seo-search-pagination .page-numbers{padding:8px 11px;border:1px solid #ddd;border-radius:5px;text-decoration:none}.seo-search-pagination .current{background:#111;color:#fff;border-color:#111}.seo-search-no-results{padding:32px;border:1px dashed #c3c4c7;border-radius:10px;text-align:center}.seo-search-hidden-fields input{display:none}

.seo-search-vocab-bar{margin:0 0 24px;padding:16px;border:1px solid #e1e3e5;border-radius:12px;background:#fff}.seo-search-vocab-grid{display:grid;grid-template-columns:repeat(5,minmax(150px,1fr));gap:12px}.seo-search-vocab-field{display:flex;flex-direction:column;gap:6px;font-size:13px;font-weight:600}.seo-search-vocab-field select{width:100%;min-height:42px;border:1px solid #ccd0d4;border-radius:7px;background:#fff;padding:7px}.seo-search-vocab-actions{display:flex;align-items:center;gap:12px;margin-top:14px}.seo-search-vocab-actions button{padding:9px 14px;border:0;border-radius:7px;background:#111;color:#fff;cursor:pointer}.seo-search-vocab-actions a{text-decoration:none}.seo-search-vocab-bar .seo-search-auto-submit{cursor:pointer}
@media(max-width:1050px){.seo-search-vocab-grid{grid-template-columns:repeat(3,minmax(150px,1fr))}.seo-search-products{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:820px){.seo-search-vocab-grid{grid-template-columns:repeat(2,minmax(140px,1fr))}.seo-search-content{grid-template-columns:1fr}.seo-search-sidebar{display:none}.seo-search-sidebar.is-open{display:block}.seo-search-filter-toggle{display:inline-block}.seo-search-products{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:560px){.seo-search-vocab-grid{grid-template-columns:1fr}.seo-search-page{padding:20px 14px}.seo-search-page-header{align-items:start;flex-direction:column}.seo-search-toolbar{align-items:stretch;flex-direction:column}.seo-search-toolbar-right{justify-content:space-between}.seo-search-products{grid-template-columns:1fr}.seo-search-products.is-list .seo-search-card{grid-template-columns:110px 1fr}.seo-search-auto-item{grid-template-columns:48px 1fr}.seo-search-auto-price{display:none}}
";
}

add_shortcode('seo_search', function ($atts) {
    if (!class_exists('WooCommerce')) {
        return '<p>' . esc_html__('SEO Search necesita WooCommerce para funcionar.', 'seo-search') . '</p>';
    }

    seo_search_enqueue_front_assets();

    $atts = shortcode_atts(array(
        'placeholder' => __('Buscar productos, referencias, categorías…', 'seo-search'),
        'button_text' => '🔍',
        'class'       => '',
    ), $atts, 'seo_search');

    $param = seo_search_get_option('query_parameter', 'q');
    $current = isset($_GET[$param]) && !is_array($_GET[$param]) ? sanitize_text_field(wp_unslash($_GET[$param])) : '';
    $id = wp_unique_id('seo-search-');

    ob_start();
    ?>
    <div class="seo-search-box <?php echo esc_attr($atts['class']); ?>">
        <form class="seo-search-form" method="get" action="<?php echo esc_url(seo_search_results_url()); ?>" role="search">
            <label class="screen-reader-text" for="<?php echo esc_attr($id); ?>"><?php esc_html_e('Buscar productos', 'seo-search'); ?></label>
            <input
                id="<?php echo esc_attr($id); ?>"
                class="seo-search-input"
                type="search"
                name="<?php echo esc_attr($param); ?>"
                value="<?php echo esc_attr($current); ?>"
                placeholder="<?php echo esc_attr($atts['placeholder']); ?>"
                autocomplete="off"
                aria-autocomplete="list"
                aria-controls="<?php echo esc_attr($id); ?>-listbox"
            >
            <input type="hidden" name="seo_search" value="1">
            <button type="submit" class="seo-search-button" aria-label="<?php esc_attr_e('Buscar', 'seo-search'); ?>"><?php echo esc_html($atts['button_text']); ?></button>
            <?php seo_search_preserve_query_fields(array($param, 'paged', 'product-page')); ?>
        </form>
        <?php if (seo_search_get_option('autocomplete_enabled', 1)) : ?>
            <div id="<?php echo esc_attr($id); ?>-listbox" class="seo-search-autocomplete" role="listbox" hidden></div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
});


function seo_search_render_advanced_search_shortcode($atts = array(), $shortcode_name = 'advanced_search') {
    if (!class_exists('WooCommerce') || !seo_search_vocabulary_tables_ready()) {
        return '';
    }

    $labels = seo_search_advanced_filter_groups();
    if (!$labels || !seo_search_get_option('filter_vocabulary', 1)) {
        return '';
    }

    seo_search_enqueue_front_assets();

    $atts = shortcode_atts(array(
        'action' => '',
        'class'  => '',
    ), $atts, $shortcode_name);

    $action = trim((string) $atts['action']);
    if ('' === $action) {
        $shop_id = function_exists('wc_get_page_id') ? absint(wc_get_page_id('shop')) : 0;
        $action = $shop_id > 0 ? get_permalink($shop_id) : home_url('/');
    }

    $facets = seo_search_build_global_vocabulary_facets();
    $active = seo_search_get_active_vocabulary_filters();
    $show_counts = (bool) seo_search_get_option('advanced_show_counts', 1);
    $auto_submit = (bool) seo_search_get_option('advanced_auto_submit', 0);

    ob_start();
    ?>
    <form class="seo-search-vocab-bar <?php echo esc_attr($atts['class']); ?>" method="get" action="<?php echo esc_url($action); ?>">
        <div class="seo-search-vocab-grid">
            <?php foreach ($labels as $group => $label) :
                $terms = isset($facets[$group]) ? $facets[$group] : array();
                if (!$terms) {
                    continue;
                }
                $current = !empty($active[$group][0]) ? $active[$group][0] : '';
                ?>
                <label class="seo-search-vocab-field">
                    <span><?php echo esc_html($label); ?></span>
                    <select class="<?php echo $auto_submit ? 'seo-search-auto-submit' : ''; ?>" name="filter_vocab[<?php echo esc_attr($group); ?>]">
                        <option value=""><?php printf(esc_html__('Todos los %s', 'seo-search'), esc_html(function_exists('mb_strtolower') ? mb_strtolower($label) : strtolower($label))); ?></option>
                        <?php foreach ($terms as $term) : ?>
                            <option value="<?php echo esc_attr($term['slug']); ?>" <?php selected($current, $term['slug']); ?>>
                                <?php echo esc_html($term['name']); ?><?php echo $show_counts ? ' (' . absint($term['count']) . ')' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endforeach; ?>
        </div>
        <?php seo_search_preserve_query_fields(array('filter_vocab', 'paged', 'product-page')); ?>
        <div class="seo-search-vocab-actions">
            <button type="submit"><?php esc_html_e('Aplicar filtros', 'seo-search'); ?></button>
            <?php $clear_url = remove_query_arg(array('filter_vocab', 'paged', 'product-page')); ?>
            <a href="<?php echo esc_url($clear_url); ?>"><?php esc_html_e('Limpiar', 'seo-search'); ?></a>
        </div>
    </form>
    <?php
    return ob_get_clean();
}

add_shortcode('advanced_search', function ($atts) {
    return seo_search_render_advanced_search_shortcode($atts, 'advanced_search');
});

// Alias conservado para no romper plantillas que ya usen el nombre anterior.
add_shortcode('seo_search_filters', function ($atts) {
    return seo_search_render_advanced_search_shortcode($atts, 'seo_search_filters');
});

add_action('woocommerce_product_query', function ($query) {
    if (is_admin() || !seo_search_vocabulary_tables_ready()) {
        return;
    }

    $filters = seo_search_get_active_vocabulary_filters();
    if (!$filters) {
        return;
    }

    $matching_ids = seo_search_get_vocabulary_matching_product_ids($filters);
    $current_ids = array_values(array_unique(array_filter(array_map('absint', (array) $query->get('post__in')))));

    if ($current_ids) {
        $matching_ids = array_values(array_intersect($current_ids, $matching_ids));
    }

    $query->set('post__in', $matching_ids ? $matching_ids : array(0));
}, 30);

function seo_search_preserve_query_fields($exclude = array()) {
    $exclude = array_merge($exclude, array('seo_search'));
    foreach ($_GET as $key => $value) {
        $key = sanitize_key($key);
        if (in_array($key, $exclude, true)) {
            continue;
        }
        if (is_array($value)) {
            foreach (wp_unslash($value) as $subkey => $subvalue) {
                if (is_array($subvalue)) {
                    foreach ($subvalue as $nested) {
                        printf('<input type="hidden" name="%s[%s][]" value="%s">', esc_attr($key), esc_attr(sanitize_key($subkey)), esc_attr(sanitize_text_field($nested)));
                    }
                } else {
                    printf('<input type="hidden" name="%s[%s]" value="%s">', esc_attr($key), esc_attr(sanitize_key($subkey)), esc_attr(sanitize_text_field($subvalue)));
                }
            }
        } else {
            printf('<input type="hidden" name="%s" value="%s">', esc_attr($key), esc_attr(sanitize_text_field(wp_unslash($value))));
        }
    }
}

/* =========================================================
   PÁGINA DE RESULTADOS Y FILTROS
========================================================= */

function seo_search_is_results_request() {
    if (!class_exists('WooCommerce')) {
        return false;
    }

    if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return false;
    }

    $param = seo_search_get_option('query_parameter', 'q');
    if (!isset($_GET[$param]) || is_array($_GET[$param]) || '' === trim((string) wp_unslash($_GET[$param]))) {
        return false;
    }

    return !empty($_GET['seo_search']) || (!isset($_GET['s']) && 'q' === $param);
}


add_filter('wp_robots', function ($robots) {
    if (seo_search_is_results_request()) {
        $robots['noindex'] = true;
        $robots['follow'] = true;
    }
    return $robots;
});

add_filter('pre_get_document_title', function ($title) {
    if (!seo_search_is_results_request()) {
        return $title;
    }
    $param = seo_search_get_option('query_parameter', 'q');
    $keyword = isset($_GET[$param]) && !is_array($_GET[$param]) ? sanitize_text_field(wp_unslash($_GET[$param])) : '';
    return sprintf(__('Resultados para “%s”', 'seo-search'), $keyword);
});

add_action('template_redirect', function () {
    if (!seo_search_is_results_request()) {
        return;
    }

    $param = seo_search_get_option('query_parameter', 'q');
    $keyword = !is_array($_GET[$param]) ? sanitize_text_field(wp_unslash($_GET[$param])) : '';
    $page = max(1, absint(isset($_GET['product-page']) ? $_GET['product-page'] : 1));
    $filters = seo_search_get_active_filters();
    $data = seo_search_query_products($keyword, $page, null, $filters);

    if (1 === $page) {
        seo_search_log_query($keyword, $data['total']);
    }

    global $seo_search_keyword, $seo_search_data, $seo_search_filters;
    $seo_search_keyword = $keyword;
    $seo_search_data = $data;
    $seo_search_filters = $filters;

    $override = locate_template(array('seo-search-results.php', 'seo-search/search-results.php'));
    if ($override) {
        include $override;
        exit;
    }

    status_header(200);
    nocache_headers();
    get_header();
    echo seo_search_render_results_page($keyword, $data, $filters); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    get_footer();
    exit;
});

function seo_search_render_results_page($keyword, $data, $filters) {
    seo_search_enqueue_front_assets();

    ob_start();
    ?>
    <main class="seo-search-page" id="seo-search-results">
        <?php echo do_shortcode('[seo_search]'); ?>

        <header class="seo-search-page-header">
            <div>
                <h1><?php printf(esc_html__('Resultados para “%s”', 'seo-search'), esc_html($keyword)); ?></h1>
                <div class="seo-search-summary">
                    <?php printf(esc_html(_n('%d producto encontrado', '%d productos encontrados', $data['total'], 'seo-search')), absint($data['total'])); ?>
                </div>
            </div>
        </header>

        <form class="seo-search-results-form" method="get" action="<?php echo esc_url(seo_search_results_url()); ?>">
            <?php $param = seo_search_get_option('query_parameter', 'q'); ?>
            <input type="hidden" name="<?php echo esc_attr($param); ?>" value="<?php echo esc_attr($keyword); ?>">
            <input type="hidden" name="seo_search" value="1">

            <div class="seo-search-toolbar">
                <button class="seo-search-filter-toggle" type="button"><?php esc_html_e('Filtros', 'seo-search'); ?></button>
                <div><?php esc_html_e('Afina los resultados con los filtros disponibles.', 'seo-search'); ?></div>
                <div class="seo-search-toolbar-right">
                    <label class="screen-reader-text" for="seo-search-orderby"><?php esc_html_e('Ordenar', 'seo-search'); ?></label>
                    <select id="seo-search-orderby" class="seo-search-auto-submit" name="orderby">
                        <?php
                        $sorting = array(
                            'relevance' => __('Relevancia', 'seo-search'),
                            'date' => __('Más recientes', 'seo-search'),
                            'price_asc' => __('Precio: menor a mayor', 'seo-search'),
                            'price_desc' => __('Precio: mayor a menor', 'seo-search'),
                            'title' => __('Nombre', 'seo-search'),
                            'popularity' => __('Popularidad', 'seo-search'),
                        );
                        foreach ($sorting as $value => $label) {
                            printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($filters['orderby'], $value, false), esc_html($label));
                        }
                        ?>
                    </select>
                    <select class="seo-search-auto-submit" name="layout" aria-label="<?php esc_attr_e('Diseño', 'seo-search'); ?>">
                        <option value="grid" <?php selected($filters['layout'], 'grid'); ?>><?php esc_html_e('Cuadrícula', 'seo-search'); ?></option>
                        <option value="list" <?php selected($filters['layout'], 'list'); ?>><?php esc_html_e('Lista', 'seo-search'); ?></option>
                    </select>
                </div>
            </div>

            <div class="seo-search-content">
                <?php echo seo_search_render_filters($data['facets'], $filters, $keyword); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

                <section>
                    <?php if ($data['query'] && $data['query']->have_posts()) : ?>
                        <div class="seo-search-products <?php echo 'list' === $filters['layout'] ? 'is-list' : 'is-grid'; ?>">
                            <?php
                            while ($data['query']->have_posts()) {
                                $data['query']->the_post();
                                echo seo_search_render_product_card(get_the_ID()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            }
                            wp_reset_postdata();
                            ?>
                        </div>
                        <?php echo seo_search_render_pagination($data['pages'], max(1, absint(isset($_GET['product-page']) ? $_GET['product-page'] : 1))); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php else : ?>
                        <div class="seo-search-no-results">
                            <h2><?php esc_html_e('No hay resultados', 'seo-search'); ?></h2>
                            <p><?php echo esc_html(seo_search_get_option('no_results_text', '')); ?></p>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </form>
    </main>
    <?php
    return ob_get_clean();
}

function seo_search_render_filters($facets, $filters, $keyword) {
    $options = seo_search_get_options();
    $has_filters = false;

    ob_start();
    ?>
    <aside class="seo-search-sidebar" aria-label="<?php esc_attr_e('Filtros de productos', 'seo-search'); ?>">
        <?php if (!empty($options['filter_categories']) && !empty($facets['categories'])) : $has_filters = true; ?>
            <?php echo seo_search_render_single_select_filter(__('Categorías', 'seo-search'), 'filter_category', $facets['categories'], $filters['category']); // phpcs:ignore ?>
        <?php endif; ?>

        <?php if (!empty($options['filter_brand']) && !empty($facets['brands'])) : $has_filters = true; ?>
            <?php echo seo_search_render_single_select_filter(__('Marca', 'seo-search'), 'filter_brand', $facets['brands'], $filters['brand']); // phpcs:ignore ?>
        <?php endif; ?>

        <?php if (!empty($options['filter_vocabulary']) && !empty($facets['vocabulary'])) : ?>
            <?php foreach (seo_search_advanced_filter_groups() as $semantic_group => $semantic_label) :
                $semantic_terms = isset($facets['vocabulary'][$semantic_group]) ? $facets['vocabulary'][$semantic_group] : array();
                if (!$semantic_terms) {
                    continue;
                }
                $has_filters = true;
                $semantic_selected = !empty($filters['vocabulary'][$semantic_group][0]) ? $filters['vocabulary'][$semantic_group][0] : '';
                echo seo_search_render_single_select_filter(
                    $semantic_label,
                    'filter_vocab[' . $semantic_group . ']',
                    $semantic_terms,
                    $semantic_selected
                ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($options['filter_attributes']) && !empty($facets['attributes'])) : $has_filters = true; ?>
            <?php foreach ($facets['attributes'] as $taxonomy => $attribute) : ?>
                <div class="seo-search-filter-group">
                    <h3><?php echo esc_html($attribute['label']); ?></h3>
                    <div class="seo-search-filter-list">
                        <?php foreach (array_slice($attribute['terms'], 0, 40) as $term) : ?>
                            <label>
                                <span><input type="checkbox" name="filter_attr[<?php echo esc_attr($taxonomy); ?>][]" value="<?php echo esc_attr($term['slug']); ?>" <?php checked(in_array($term['slug'], isset($filters['attributes'][$taxonomy]) ? $filters['attributes'][$taxonomy] : array(), true)); ?>> <?php echo esc_html($term['name']); ?></span>
                                <span class="seo-search-filter-count"><?php echo absint($term['count']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($options['filter_price']) && ('' !== $facets['price']['min'] || '' !== $facets['price']['max'])) : $has_filters = true; ?>
            <div class="seo-search-filter-group">
                <h3><?php esc_html_e('Precio', 'seo-search'); ?></h3>
                <div class="seo-search-price-fields">
                    <input type="number" step="0.01" min="0" name="filter_min_price" value="<?php echo esc_attr($filters['min_price']); ?>" placeholder="<?php echo esc_attr($facets['price']['min']); ?>" aria-label="<?php esc_attr_e('Precio mínimo', 'seo-search'); ?>">
                    <input type="number" step="0.01" min="0" name="filter_max_price" value="<?php echo esc_attr($filters['max_price']); ?>" placeholder="<?php echo esc_attr($facets['price']['max']); ?>" aria-label="<?php esc_attr_e('Precio máximo', 'seo-search'); ?>">
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($options['filter_stock'])) : $has_filters = true; ?>
            <div class="seo-search-filter-group">
                <h3><?php esc_html_e('Disponibilidad', 'seo-search'); ?></h3>
                <select name="filter_stock" style="width:100%">
                    <option value=""><?php esc_html_e('Cualquier estado', 'seo-search'); ?></option>
                    <option value="instock" <?php selected($filters['stock'], 'instock'); ?>><?php esc_html_e('En stock', 'seo-search'); ?></option>
                    <option value="onbackorder" <?php selected($filters['stock'], 'onbackorder'); ?>><?php esc_html_e('Disponible bajo pedido', 'seo-search'); ?></option>
                    <option value="outofstock" <?php selected($filters['stock'], 'outofstock'); ?>><?php esc_html_e('Agotado', 'seo-search'); ?></option>
                </select>
            </div>
        <?php endif; ?>

        <?php if ($has_filters) : ?>
            <button class="seo-search-apply" type="submit"><?php esc_html_e('Aplicar filtros', 'seo-search'); ?></button>
            <a class="seo-search-clear" href="<?php echo esc_url(add_query_arg(array(seo_search_get_option('query_parameter', 'q') => $keyword, 'seo_search' => 1), seo_search_results_url())); ?>"><?php esc_html_e('Limpiar filtros', 'seo-search'); ?></a>
        <?php else : ?>
            <p><?php esc_html_e('No hay filtros disponibles para esta búsqueda.', 'seo-search'); ?></p>
        <?php endif; ?>
    </aside>
    <?php
    return ob_get_clean();
}

function seo_search_render_single_select_filter($title, $name, $terms, $selected_value) {
    ob_start();
    ?>
    <div class="seo-search-filter-group">
        <h3><?php echo esc_html($title); ?></h3>
        <div class="seo-search-filter-list">
            <label>
                <span><input type="radio" name="<?php echo esc_attr($name); ?>" value="" <?php checked('', $selected_value); ?>> <?php esc_html_e('Todas', 'seo-search'); ?></span>
            </label>
            <?php foreach (array_slice($terms, 0, 50) as $term) : ?>
                <label>
                    <span><input type="radio" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($term['slug']); ?>" <?php checked($term['slug'], $selected_value); ?>> <?php echo esc_html($term['name']); ?></span>
                    <span class="seo-search-filter-count"><?php echo absint($term['count']); ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function seo_search_render_product_card($product_id) {
    $product = wc_get_product($product_id);
    if (!$product || !$product->is_visible()) {
        return '';
    }

    $options = seo_search_get_options();
    $classes = $product->is_in_stock() ? 'in-stock' : 'out-of-stock';
    $stock_text = $product->is_in_stock() ? __('En stock', 'seo-search') : __('Agotado', 'seo-search');

    ob_start();
    ?>
    <article class="seo-search-card">
        <?php if (!empty($options['show_image'])) : ?>
            <a class="seo-search-card-image" href="<?php echo esc_url(get_permalink($product_id)); ?>">
                <?php echo wp_kses_post($product->get_image('woocommerce_thumbnail', array('loading' => 'lazy'))); ?>
            </a>
        <?php endif; ?>
        <div class="seo-search-card-body">
            <?php if (!empty($options['show_category'])) : ?>
                <div class="seo-search-card-meta"><?php echo wp_kses_post(wc_get_product_category_list($product_id, ', ')); ?></div>
            <?php endif; ?>
            <h2 class="seo-search-card-title"><a href="<?php echo esc_url(get_permalink($product_id)); ?>"><?php echo esc_html(get_the_title($product_id)); ?></a></h2>
            <?php if (!empty($options['show_sku']) && $product->get_sku()) : ?>
                <div class="seo-search-card-meta"><?php printf(esc_html__('Ref. %s', 'seo-search'), esc_html($product->get_sku())); ?></div>
            <?php endif; ?>
            <?php if (!empty($options['show_excerpt'])) : ?>
                <div class="seo-search-card-excerpt"><?php echo esc_html(wp_trim_words(get_post_field('post_excerpt', $product_id), 24)); ?></div>
            <?php endif; ?>
            <?php if (!empty($options['show_stock'])) : ?>
                <div class="seo-search-stock <?php echo esc_attr($classes); ?>"><?php echo esc_html($stock_text); ?></div>
            <?php endif; ?>
            <?php if (!empty($options['show_price'])) : ?>
                <div class="seo-search-card-price"><?php echo wp_kses_post($product->get_price_html()); ?></div>
            <?php endif; ?>
            <a class="seo-search-card-button" href="<?php echo esc_url(get_permalink($product_id)); ?>"><?php esc_html_e('Ver producto', 'seo-search'); ?></a>
        </div>
    </article>
    <?php
    return ob_get_clean();
}

function seo_search_render_pagination($total_pages, $current_page) {
    if ($total_pages < 2) {
        return '';
    }

    $base_url = remove_query_arg('product-page');
    $links = paginate_links(array(
        'base'      => esc_url_raw(add_query_arg('product-page', '%#%', $base_url)),
        'format'    => '',
        'current'   => max(1, $current_page),
        'total'     => max(1, $total_pages),
        'type'      => 'array',
        'prev_text' => '←',
        'next_text' => '→',
    ));

    return $links ? '<nav class="seo-search-pagination" aria-label="' . esc_attr__('Paginación', 'seo-search') . '">' . implode('', $links) . '</nav>' : '';
}

/* =========================================================
   ADMINISTRACIÓN
========================================================= */

add_action('admin_menu', function () {
    add_menu_page(
        __('SEO Search', 'seo-search'),
        __('SEO Search', 'seo-search'),
        'manage_options',
        'seo-search',
        'seo_search_settings_page',
        'dashicons-search',
        58
    );
}, 99);

function seo_search_snippet_info() {
    echo '<div class="notice notice-info inline"><p><strong>' . esc_html__('Shortcodes:', 'seo-search') . '</strong> <code>[seo_search]</code> ' . esc_html__('para búsqueda normal', 'seo-search') . ' · <code>[advanced_search]</code> ' . esc_html__('para filtros semánticos avanzados.', 'seo-search') . '</p></div>';
}

function seo_search_admin_checkbox($name, $label, $description = '') {
    $options = seo_search_get_options();
    ?>
    <input type="hidden" name="<?php echo esc_attr(SEO_SEARCH_OPTION); ?>[<?php echo esc_attr($name); ?>]" value="0">
    <label><input type="checkbox" name="<?php echo esc_attr(SEO_SEARCH_OPTION); ?>[<?php echo esc_attr($name); ?>]" value="1" <?php checked(!empty($options[$name])); ?>> <strong><?php echo esc_html($label); ?></strong></label>
    <?php if ($description) : ?><p class="description"><?php echo esc_html($description); ?></p><?php endif; ?>
    <?php
}

function seo_search_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $options = seo_search_get_options();
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
    $tabs = array(
        'general' => __('General', 'seo-search'),
        'fields' => __('Campos de búsqueda', 'seo-search'),
        'display' => __('Resultados y filtros', 'seo-search'),
        'advanced' => __('Búsqueda con filtros', 'seo-search'),
        'analytics' => __('Estadísticas', 'seo-search'),
    );

    if (isset($_POST['seo_search_clear_logs']) && check_admin_referer('seo_search_clear_logs')) {
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . seo_search_log_table_name()); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Historial eliminado.', 'seo-search') . '</p></div>';
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('SEO Search 2.2', 'seo-search'); ?></h1>
        <?php seo_search_snippet_info(); ?>

        <nav class="nav-tab-wrapper">
            <?php foreach ($tabs as $slug => $label) : ?>
                <a class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(array('page' => 'seo-search', 'tab' => $slug), admin_url('admin.php'))); ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if ('analytics' === $tab) : ?>
            <?php seo_search_render_admin_analytics(); ?>
        <?php else : ?>
            <form method="post" action="options.php">
                <?php settings_fields('seo_search_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <?php if ('general' === $tab) : ?>
                        <tr><th><?php esc_html_e('Resultados por página', 'seo-search'); ?></th><td><input type="number" min="1" max="100" name="<?php echo esc_attr(SEO_SEARCH_OPTION); ?>[results_per_page]" value="<?php echo absint($options['results_per_page']); ?>"></td></tr>
                        <tr><th><?php esc_html_e('Página de resultados', 'seo-search'); ?></th><td><?php wp_dropdown_pages(array('name' => SEO_SEARCH_OPTION . '[results_page_id]', 'selected' => absint($options['results_page_id']), 'show_option_none' => __('Página de inicio', 'seo-search'))); ?><p class="description"><?php esc_html_e('El formulario enviará aquí la búsqueda. La página puede estar vacía.', 'seo-search'); ?></p></td></tr>
                        <tr><th><?php esc_html_e('Parámetro de URL', 'seo-search'); ?></th><td><input type="text" name="<?php echo esc_attr(SEO_SEARCH_OPTION); ?>[query_parameter]" value="<?php echo esc_attr($options['query_parameter']); ?>" class="regular-text"><p class="description"><?php esc_html_e('Por compatibilidad se recomienda mantener “q”.', 'seo-search'); ?></p></td></tr>
                        <tr><th><?php esc_html_e('Autocompletado', 'seo-search'); ?></th><td><?php seo_search_admin_checkbox('autocomplete_enabled', __('Activar sugerencias mientras se escribe', 'seo-search')); ?><p><label><?php esc_html_e('Mínimo de caracteres', 'seo-search'); ?> <input type="number" min="1" max="5" name="<?php echo esc_attr(SEO_SEARCH_OPTION); ?>[autocomplete_min_chars]" value="<?php echo absint($options['autocomplete_min_chars']); ?>"></label></p><p><label><?php esc_html_e('Número de sugerencias', 'seo-search'); ?> <input type="number" min="3" max="20" name="<?php echo esc_attr(SEO_SEARCH_OPTION); ?>[autocomplete_limit]" value="<?php echo absint($options['autocomplete_limit']); ?>"></label></p></td></tr>
                        <tr><th><?php esc_html_e('Tolerancia a errores', 'seo-search'); ?></th><td><?php seo_search_admin_checkbox('typo_tolerance', __('Activar coincidencias aproximadas', 'seo-search'), __('Se usa como respaldo cuando hay pocos resultados exactos.', 'seo-search')); ?><p><label><?php esc_html_e('Productos máximos del índice aproximado', 'seo-search'); ?> <input type="number" min="100" max="5000" name="<?php echo esc_attr(SEO_SEARCH_OPTION); ?>[fuzzy_scan_limit]" value="<?php echo absint($options['fuzzy_scan_limit']); ?>"></label></p></td></tr>
                        <tr><th><?php esc_html_e('Sinónimos', 'seo-search'); ?></th><td><textarea name="<?php echo esc_attr(SEO_SEARCH_OPTION); ?>[synonyms]" rows="7" class="large-text code"><?php echo esc_textarea($options['synonyms']); ?></textarea><p class="description"><?php esc_html_e('Una línea por grupo. Ejemplo: movil=telefono,smartphone', 'seo-search'); ?></p></td></tr>
                        <tr><th><?php esc_html_e('Analítica', 'seo-search'); ?></th><td><?php seo_search_admin_checkbox('log_searches', __('Registrar términos y número de resultados', 'seo-search')); ?></td></tr>
                    <?php elseif ('fields' === $tab) : ?>
                        <tr><th><?php esc_html_e('Buscar en', 'seo-search'); ?></th><td>
                            <?php seo_search_admin_checkbox('search_title', __('Título del producto', 'seo-search')); ?><br><br>
                            <?php seo_search_admin_checkbox('search_content', __('Descripción corta y descripción', 'seo-search')); ?><br><br>
                            <?php seo_search_admin_checkbox('search_sku', __('SKU / referencia', 'seo-search')); ?><br><br>
                            <?php seo_search_admin_checkbox('search_categories', __('Categorías', 'seo-search')); ?><br><br>
                            <?php seo_search_admin_checkbox('search_vocabulary', __('Vocabulario canonico: TIPO, ROL, APLICACION, PLATAFORMA y SUBTIPO', 'seo-search')); ?><br><br>
                            <?php seo_search_admin_checkbox('search_attributes', __('Atributos globales y atributos del producto', 'seo-search')); ?>
                        </td></tr>
                        <tr><th><?php esc_html_e('Metadatos personalizados', 'seo-search'); ?></th><td><input type="text" class="large-text" name="<?php echo esc_attr(SEO_SEARCH_OPTION); ?>[custom_meta_keys]" value="<?php echo esc_attr($options['custom_meta_keys']); ?>"><p class="description"><?php esc_html_e('Claves separadas por comas, por ejemplo: referencia_proveedor, codigo_fabricante', 'seo-search'); ?></p></td></tr>
                    <?php elseif ('advanced' === $tab) : ?>
                        <tr>
                            <th><?php esc_html_e('Búsqueda avanzada', 'seo-search'); ?></th>
                            <td>
                                <?php seo_search_admin_checkbox('filter_vocabulary', __('Activar filtros semánticos canónicos', 'seo-search'), __('Usa exclusivamente el vocabulario TIPO, ROL, APLICACIÓN, PLATAFORMA y SUBTIPO.', 'seo-search')); ?>
                                <p class="description"><?php esc_html_e('Esta función pertenece a SEO Search. WooCommerce solo aporta el catálogo de productos; no se registra ninguna pantalla dentro de su menú.', 'seo-search'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Filtros visibles', 'seo-search'); ?></th>
                            <td>
                                <?php seo_search_admin_checkbox('advanced_filter_rol', __('ROL', 'seo-search')); ?><br><br>
                                <?php seo_search_admin_checkbox('advanced_filter_tipo', __('TIPO', 'seo-search')); ?><br><br>
                                <?php seo_search_admin_checkbox('advanced_filter_aplicacion', __('APLICACIÓN', 'seo-search')); ?><br><br>
                                <?php seo_search_admin_checkbox('advanced_filter_plataforma', __('PLATAFORMA', 'seo-search')); ?><br><br>
                                <?php seo_search_admin_checkbox('advanced_filter_subtipo', __('SUBTIPO', 'seo-search')); ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Comportamiento', 'seo-search'); ?></th>
                            <td>
                                <?php seo_search_admin_checkbox('advanced_show_counts', __('Mostrar cantidad de productos junto a cada valor', 'seo-search')); ?><br><br>
                                <?php seo_search_admin_checkbox('advanced_auto_submit', __('Aplicar automáticamente al cambiar un filtro', 'seo-search'), __('Si está desactivado se utiliza el botón “Aplicar filtros”.', 'seo-search')); ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Shortcode', 'seo-search'); ?></th>
                            <td>
                                <code>[advanced_search]</code>
                                <p class="description"><?php esc_html_e('Inserta la barra de filtros avanzados en la tienda, una página o una plantilla. Por defecto envía los filtros a la página de tienda de WooCommerce.', 'seo-search'); ?></p>
                            </td>
                        </tr>
                    <?php elseif ('display' === $tab) : ?>
                        <tr><th><?php esc_html_e('Tarjetas de producto', 'seo-search'); ?></th><td>
                            <?php seo_search_admin_checkbox('show_image', __('Mostrar imagen', 'seo-search')); ?><br><br>
                            <?php seo_search_admin_checkbox('show_price', __('Mostrar precio', 'seo-search')); ?><br><br>
                            <?php seo_search_admin_checkbox('show_stock', __('Mostrar disponibilidad', 'seo-search')); ?><br><br>
                            <?php seo_search_admin_checkbox('show_sku', __('Mostrar SKU / referencia', 'seo-search')); ?><br><br>
                            <?php seo_search_admin_checkbox('show_category', __('Mostrar categorías', 'seo-search')); ?><br><br>
                            <?php seo_search_admin_checkbox('show_excerpt', __('Mostrar descripción corta', 'seo-search')); ?>
                        </td></tr>
                        <tr><th><?php esc_html_e('Diseño', 'seo-search'); ?></th><td><select name="<?php echo esc_attr(SEO_SEARCH_OPTION); ?>[default_layout]"><option value="grid" <?php selected($options['default_layout'], 'grid'); ?>><?php esc_html_e('Cuadrícula', 'seo-search'); ?></option><option value="list" <?php selected($options['default_layout'], 'list'); ?>><?php esc_html_e('Lista', 'seo-search'); ?></option></select> <label><?php esc_html_e('Columnas', 'seo-search'); ?> <input type="number" min="2" max="6" name="<?php echo esc_attr(SEO_SEARCH_OPTION); ?>[grid_columns]" value="<?php echo absint($options['grid_columns']); ?>"></label></td></tr>
                        <tr><th><?php esc_html_e('Filtros', 'seo-search'); ?></th><td>
                            <?php seo_search_admin_checkbox('filter_categories', __('Categoría', 'seo-search')); ?><br><br>
                            <?php seo_search_admin_checkbox('filter_brand', __('Marca', 'seo-search')); ?><br><br>
                            <?php seo_search_admin_checkbox('filter_attributes', __('Atributos', 'seo-search')); ?><br><br>
                            <?php seo_search_admin_checkbox('filter_price', __('Precio', 'seo-search')); ?><br><br>
                            <?php seo_search_admin_checkbox('filter_stock', __('Disponibilidad', 'seo-search')); ?>
                        </td></tr>
                        <tr><th><?php esc_html_e('Taxonomía de marca', 'seo-search'); ?></th><td><input type="text" name="<?php echo esc_attr(SEO_SEARCH_OPTION); ?>[brand_taxonomy]" value="<?php echo esc_attr($options['brand_taxonomy']); ?>" class="regular-text"><p class="description"><?php esc_html_e('Ejemplos habituales: product_brand, pa_brand, yith_product_brand.', 'seo-search'); ?></p></td></tr>
                        <tr><th><?php esc_html_e('Mensaje sin resultados', 'seo-search'); ?></th><td><textarea name="<?php echo esc_attr(SEO_SEARCH_OPTION); ?>[no_results_text]" rows="3" class="large-text"><?php echo esc_textarea($options['no_results_text']); ?></textarea></td></tr>
                    <?php endif; ?>
                </table>
                <?php submit_button(); ?>
            </form>

            <?php if ('general' === $tab) : ?>
                <hr>
                <h2><?php esc_html_e('Vista previa', 'seo-search'); ?></h2>
                <?php echo do_shortcode('[seo_search]'); ?>
            <?php elseif ('advanced' === $tab) : ?>
                <hr>
                <h2><?php esc_html_e('Vista previa de filtros avanzados', 'seo-search'); ?></h2>
                <?php echo do_shortcode('[advanced_search]'); ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

function seo_search_render_admin_analytics() {
    global $wpdb;
    seo_search_maybe_install_log_table();
    $table = seo_search_log_table_name();

    $top = $wpdb->get_results("SELECT search_term, COUNT(*) AS searches, MAX(results_count) AS last_results, MAX(searched_at) AS last_search FROM {$table} WHERE searched_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY normalized_term ORDER BY searches DESC, last_search DESC LIMIT 20", ARRAY_A); // phpcs:ignore
    $zero = $wpdb->get_results("SELECT search_term, COUNT(*) AS searches, MAX(searched_at) AS last_search FROM {$table} WHERE results_count = 0 AND searched_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY normalized_term ORDER BY searches DESC, last_search DESC LIMIT 20", ARRAY_A); // phpcs:ignore
    $summary = $wpdb->get_row("SELECT COUNT(*) AS total, SUM(CASE WHEN results_count = 0 THEN 1 ELSE 0 END) AS zero_count, COUNT(DISTINCT normalized_term) AS unique_terms FROM {$table} WHERE searched_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)", ARRAY_A); // phpcs:ignore
    ?>
    <div style="display:grid;grid-template-columns:repeat(3,minmax(160px,1fr));gap:16px;margin:20px 0;max-width:900px">
        <div class="postbox" style="padding:18px"><strong style="font-size:28px"><?php echo absint(isset($summary['total']) ? $summary['total'] : 0); ?></strong><br><?php esc_html_e('Búsquedas en 30 días', 'seo-search'); ?></div>
        <div class="postbox" style="padding:18px"><strong style="font-size:28px"><?php echo absint(isset($summary['unique_terms']) ? $summary['unique_terms'] : 0); ?></strong><br><?php esc_html_e('Términos distintos', 'seo-search'); ?></div>
        <div class="postbox" style="padding:18px"><strong style="font-size:28px"><?php echo absint(isset($summary['zero_count']) ? $summary['zero_count'] : 0); ?></strong><br><?php esc_html_e('Búsquedas sin resultados', 'seo-search'); ?></div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:20px">
        <?php seo_search_render_stats_table(__('Más buscado', 'seo-search'), $top, false); ?>
        <?php seo_search_render_stats_table(__('Sin resultados', 'seo-search'), $zero, true); ?>
    </div>

    <form method="post" style="margin-top:24px">
        <?php wp_nonce_field('seo_search_clear_logs'); ?>
        <button type="submit" name="seo_search_clear_logs" value="1" class="button button-secondary" onclick="return confirm('<?php echo esc_js(__('¿Eliminar todo el historial de búsquedas?', 'seo-search')); ?>')"><?php esc_html_e('Eliminar historial', 'seo-search'); ?></button>
    </form>
    <?php
}

function seo_search_render_stats_table($title, $rows, $zero_table) {
    ?>
    <div>
        <h2><?php echo esc_html($title); ?></h2>
        <table class="widefat striped">
            <thead><tr><th><?php esc_html_e('Término', 'seo-search'); ?></th><th><?php esc_html_e('Búsquedas', 'seo-search'); ?></th><?php if (!$zero_table) : ?><th><?php esc_html_e('Resultados', 'seo-search'); ?></th><?php endif; ?><th><?php esc_html_e('Última', 'seo-search'); ?></th></tr></thead>
            <tbody>
            <?php if (!$rows) : ?>
                <tr><td colspan="4"><?php esc_html_e('Todavía no hay datos.', 'seo-search'); ?></td></tr>
            <?php else : foreach ($rows as $row) : ?>
                <tr><td><?php echo esc_html($row['search_term']); ?></td><td><?php echo absint($row['searches']); ?></td><?php if (!$zero_table) : ?><td><?php echo absint($row['last_results']); ?></td><?php endif; ?><td><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $row['last_search'])); ?></td></tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/* =========================================================
   COMPATIBILIDAD Y DIAGNÓSTICO
========================================================= */

add_action('admin_notices', function () {
    if (!current_user_can('activate_plugins')) {
        return;
    }
    if (!class_exists('WooCommerce')) {
        echo '<div class="notice notice-error"><p>' . esc_html__('SEO Search 2.0 requiere WooCommerce activo.', 'seo-search') . '</p></div>';
    }
});