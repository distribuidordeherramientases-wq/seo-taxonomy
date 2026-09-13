<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Demanda x Catalogo.
 *
 * Cruza las consultas de Search Console ya almacenadas por Google Intelligence
 * con las categorias reales de WooCommerce. Esta primera version es
 * deliberadamente conservadora: no crea ni elimina productos y no consulta
 * proveedores externos. Su objetivo es priorizar donde potenciar, ampliar,
 * mantener o revisar el catalogo actual.
 */

if (!defined('SEO_GOOGLE_DEMAND_CATALOG_VERSION')) {
    define('SEO_GOOGLE_DEMAND_CATALOG_VERSION', '1.4.0');
}

/**
 * Normaliza texto para clasificar consultas sin depender de mayusculas/acentos.
 */
function seo_google_demand_normalize_text($text) {
    $text = wp_strip_all_tags((string) $text);
    $text = remove_accents($text);
    $text = function_exists('mb_strtolower')
        ? mb_strtolower($text, 'UTF-8')
        : strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/i', ' ', $text);
    return trim(preg_replace('/\s+/', ' ', $text));
}

/**
 * Estima cuanto ayuda una consulta a tomar una decision de catalogo.
 *
 * No pretende adivinar la intencion de Google. Solo reduce el peso de terminos
 * extremadamente amplios y premia consultas con detalle de producto, medida,
 * uso o senal comercial. La consulta original y sus metricas siguen visibles.
 */
function seo_google_demand_query_profile($query) {
    $normalized = seo_google_demand_normalize_text($query);
    $tokens     = '' === $normalized ? array() : preg_split('/\s+/', $normalized);
    $count      = count($tokens);

    $generic_exact = array(
        'herramienta',
        'herramientas',
        'ferreteria',
        'maquinaria',
        'maquinas',
        'bricolaje',
        'tienda herramientas',
        'tienda de herramientas',
        'herramientas online',
        'herramientas profesionales',
    );

    if (in_array($normalized, $generic_exact, true)) {
        $score = 0.12;
    } elseif ($count <= 1) {
        $score = 0.46;
    } elseif (2 === $count) {
        $score = 0.60;
    } elseif (3 === $count) {
        $score = 0.74;
    } elseif (4 === $count) {
        $score = 0.84;
    } else {
        $score = 0.90;
    }

    $commercial_terms = array(
        'comprar', 'precio', 'precios', 'oferta', 'ofertas', 'barato', 'barata',
        'profesional', 'profesionales', 'industrial', 'taller', 'kit', 'juego',
        'pack', 'repuesto', 'recambio', 'recambios', 'accesorio', 'accesorios',
    );

    $use_terms = array(
        'para', 'con', 'sin', 'electrico', 'electrica', 'neumatico', 'neumatica',
        'hidraulico', 'hidraulica', 'bateria', 'coche', 'camion', 'moto', 'obra',
        'madera', 'metal', 'acero', 'aluminio', 'perfil', 'bajo', 'alta', 'alto',
    );

    $has_commercial = false;
    $has_use        = false;

    foreach ($tokens as $token) {
        if (in_array($token, $commercial_terms, true)) {
            $has_commercial = true;
        }
        if (in_array($token, $use_terms, true)) {
            $has_use = true;
        }
    }

    $has_spec = (bool) preg_match(
        '/(?:\d|\b(?:mm|cm|m|kg|g|t|tn|ton|v|w|kw|ah|bar|psi|nm|rpm|l|litros?|pulgadas?)\b)/i',
        $normalized
    );

    if ($has_commercial) {
        $score += 0.08;
    }
    if ($has_use) {
        $score += 0.05;
    }
    if ($has_spec) {
        $score += 0.12;
    }

    $score = max(0.05, min(1.0, $score));

    if ($score >= 0.75) {
        $label = 'Alta';
    } elseif ($score >= 0.50) {
        $label = 'Media';
    } else {
        $label = 'Baja';
    }

    return array(
        'score'      => $score,
        'label'      => $label,
        'normalized' => $normalized,
        'tokens'     => $count,
        'has_spec'   => $has_spec,
    );
}

/**
 * Indice de categorias por ID y mapa URL -> termino.
 */
function seo_google_demand_get_category_index() {
    static $cache = null;

    if (null !== $cache) {
        return $cache;
    }

    $cache = array(
        'by_id'  => array(),
        'by_url' => array(),
    );

    if (!taxonomy_exists('product_cat')) {
        return $cache;
    }

    $terms = get_terms(array(
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
    ));

    if (is_wp_error($terms)) {
        return $cache;
    }

    foreach ($terms as $term) {
        $cache['by_id'][(int) $term->term_id] = $term;
        $link = get_term_link($term);
        if (!is_wp_error($link)) {
            $cache['by_url'][seo_google_normalize_url($link)] = (int) $term->term_id;
        }
    }

    return $cache;
}

/**
 * Profundidad de una categoria en el arbol product_cat.
 */
function seo_google_demand_term_depth($term_id, array $by_id) {
    $depth   = 0;
    $current = absint($term_id);
    $seen    = array();

    while ($current && isset($by_id[$current]) && !isset($seen[$current])) {
        $seen[$current] = true;
        $parent = absint($by_id[$current]->parent);
        if (!$parent) {
            break;
        }
        $depth++;
        $current = $parent;
    }

    return $depth;
}

/**
 * Selecciona la categoria mas profunda de un producto para evitar duplicar
 * impresiones en varias ramas. Si hay empate, usa el ID menor para estabilidad.
 */
function seo_google_demand_pick_product_category($post_id, array $by_id) {
    $terms = wp_get_post_terms($post_id, 'product_cat');
    if (is_wp_error($terms) || !$terms) {
        return 0;
    }

    $best_id    = 0;
    $best_depth = -1;

    foreach ($terms as $term) {
        $term_id = absint($term->term_id);
        $depth   = seo_google_demand_term_depth($term_id, $by_id);

        if ($depth > $best_depth || ($depth === $best_depth && (!$best_id || $term_id < $best_id))) {
            $best_id    = $term_id;
            $best_depth = $depth;
        }
    }

    return $best_id;
}

/**
 * Relaciona una landing page de Search Console con una categoria WooCommerce.
 * Solo usa relaciones verificables: URL de categoria o producto existente.
 */
function seo_google_demand_category_for_page($url) {
    static $cache = array();

    $normalized = seo_google_normalize_url($url);
    $cache_key  = md5($normalized);

    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    $index = seo_google_demand_get_category_index();

    if (isset($index['by_url'][$normalized])) {
        $term_id = absint($index['by_url'][$normalized]);
        $cache[$cache_key] = array(
            'term_id'   => $term_id,
            'source'    => 'category',
            'post_id'   => 0,
            'recognized'=> true,
        );
        return $cache[$cache_key];
    }

    $post_id = url_to_postid($url);

    if ($post_id && 'product' === get_post_type($post_id)) {
        $term_id = seo_google_demand_pick_product_category($post_id, $index['by_id']);
        if ($term_id) {
            $cache[$cache_key] = array(
                'term_id'    => $term_id,
                'source'     => 'product',
                'post_id'    => absint($post_id),
                'recognized' => true,
            );
            return $cache[$cache_key];
        }
    }

    $cache[$cache_key] = array(
        'term_id'    => 0,
        'source'     => 'unmapped',
        'post_id'    => absint($post_id),
        'recognized' => false,
    );

    return $cache[$cache_key];
}

/**
 * Lee un periodo directamente de la tabla YA sincronizada por Google Intelligence.
 *
 * No importa ni duplica datos: reutiliza seo_google_search_data, que contiene una
 * fila por fecha + consulta + URL. La agregacion se hace por query_hash/page_hash.
 */
function seo_google_demand_get_period_query_page_rows($property_id, $date_from, $date_to, $min_impressions = 1, $limit = 40000) {
    global $wpdb;

    $table = seo_google_table('search_data');
    if (!seo_google_table_exists($table)) {
        return array();
    }

    $limit = max(1000, min(50000, absint($limit)));
    $min   = max(0, (float) $min_impressions);

    $sql = "SELECT
                query_hash,
                page_hash,
                MAX(query_text) AS query_text,
                MAX(page_url) AS page_url,
                SUM(impressions) AS impressions,
                SUM(clicks) AS clicks,
                SUM(position * impressions) AS position_weight
            FROM {$table}
            WHERE property_hash = %s
              AND data_date BETWEEN %s AND %s
            GROUP BY query_hash, page_hash
            HAVING SUM(impressions) >= %f
            ORDER BY SUM(impressions) DESC, SUM(clicks) DESC
            LIMIT %d";

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            $sql,
            hash('sha256', $property_id),
            $date_from,
            $date_to,
            $min,
            $limit
        ),
        ARRAY_A
    );

    return is_array($rows) ? $rows : array();
}

/**
 * Devuelve pares consulta + URL combinando dos lecturas simples y probadas.
 *
 * Se evita deliberadamente una consulta SQL con muchos CASE/HAVING: la tabla ya
 * contiene los datos importados, y fusionar ambos periodos en PHP es mas robusto
 * entre distintas versiones/configuraciones de MySQL.
 */
function seo_google_demand_get_query_page_rows($property_id, array $period, $min_impressions = 1, $limit = 40000) {
    $current = seo_google_demand_get_period_query_page_rows(
        $property_id,
        $period['current_from'],
        $period['current_to'],
        $min_impressions,
        $limit
    );

    $previous = seo_google_demand_get_period_query_page_rows(
        $property_id,
        $period['previous_from'],
        $period['previous_to'],
        $min_impressions,
        $limit
    );

    $merged = array();

    foreach ($current as $row) {
        $key = (string) $row['query_hash'] . '|' . (string) $row['page_hash'];
        $merged[$key] = array(
            'query_hash'              => (string) $row['query_hash'],
            'page_hash'               => (string) $row['page_hash'],
            'query_text'              => (string) $row['query_text'],
            'page_url'                => (string) $row['page_url'],
            'current_impressions'     => (float) $row['impressions'],
            'current_clicks'          => (float) $row['clicks'],
            'current_position_weight' => (float) $row['position_weight'],
            'previous_impressions'    => 0.0,
            'previous_clicks'         => 0.0,
        );
    }

    foreach ($previous as $row) {
        $key = (string) $row['query_hash'] . '|' . (string) $row['page_hash'];
        if (!isset($merged[$key])) {
            $merged[$key] = array(
                'query_hash'              => (string) $row['query_hash'],
                'page_hash'               => (string) $row['page_hash'],
                'query_text'              => (string) $row['query_text'],
                'page_url'                => (string) $row['page_url'],
                'current_impressions'     => 0.0,
                'current_clicks'          => 0.0,
                'current_position_weight' => 0.0,
                'previous_impressions'    => 0.0,
                'previous_clicks'         => 0.0,
            );
        }

        $merged[$key]['previous_impressions'] = (float) $row['impressions'];
        $merged[$key]['previous_clicks']      = (float) $row['clicks'];
    }

    uasort($merged, static function ($a, $b) {
        $a_total = (float) $a['current_impressions'] + (float) $a['previous_impressions'];
        $b_total = (float) $b['current_impressions'] + (float) $b['previous_impressions'];
        return $b_total <=> $a_total;
    });

    return array_values($merged);
}

/**
 * Diagnostico de la tabla ya importada. Sirve para distinguir claramente entre
 * "no hay datos" y "hay datos pero el informe no los esta mapeando".
 */
function seo_google_demand_get_storage_diagnostics($property_id, array $period) {
    global $wpdb;

    $table = seo_google_table('search_data');
    $empty = array(
        'stored_rows'          => 0,
        'current_rows'         => 0,
        'previous_rows'        => 0,
        'current_pairs'        => 0,
        'previous_pairs'       => 0,
        'latest_date'          => '',
    );

    if (!seo_google_table_exists($table)) {
        return $empty;
    }

    $property_hash = hash('sha256', $property_id);
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT
                COUNT(*) AS stored_rows,
                MAX(data_date) AS latest_date,
                SUM(CASE WHEN data_date BETWEEN %s AND %s THEN 1 ELSE 0 END) AS current_rows,
                SUM(CASE WHEN data_date BETWEEN %s AND %s THEN 1 ELSE 0 END) AS previous_rows
             FROM {$table}
             WHERE property_hash = %s",
            $period['current_from'],
            $period['current_to'],
            $period['previous_from'],
            $period['previous_to'],
            $property_hash
        ),
        ARRAY_A
    );

    if ($row) {
        $empty['stored_rows']   = absint($row['stored_rows']);
        $empty['current_rows']  = absint($row['current_rows']);
        $empty['previous_rows'] = absint($row['previous_rows']);
        $empty['latest_date']   = (string) $row['latest_date'];
    }

    foreach (array('current' => array($period['current_from'], $period['current_to']), 'previous' => array($period['previous_from'], $period['previous_to'])) as $key => $dates) {
        $pairs = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM (
                    SELECT query_hash, page_hash
                    FROM {$table}
                    WHERE property_hash = %s AND data_date BETWEEN %s AND %s
                    GROUP BY query_hash, page_hash
                ) seo_google_pairs",
                $property_hash,
                $dates[0],
                $dates[1]
            )
        );
        $empty[$key . '_pairs'] = absint($pairs);
    }

    return $empty;
}

/**
 * Cuenta productos publicados por categoria y los propaga a sus ancestros.
 * Usa sets por ID para evitar contar dos veces el mismo producto dentro de una rama.
 */
function seo_google_demand_get_catalog_counts() {
    global $wpdb;

    $index = seo_google_demand_get_category_index();
    $by_id = $index['by_id'];
    $sets  = array();

    if (!$by_id) {
        return array();
    }

    $rows = $wpdb->get_results(
        "SELECT tr.object_id, tt.term_id
         FROM {$wpdb->term_relationships} tr
         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
         INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
         WHERE tt.taxonomy = 'product_cat'
           AND p.post_type = 'product'
           AND p.post_status = 'publish'",
        ARRAY_A
    );

    foreach ($rows as $row) {
        $product_id = absint($row['object_id']);
        $term_id    = absint($row['term_id']);
        $current    = $term_id;
        $seen       = array();

        while ($current && isset($by_id[$current]) && !isset($seen[$current])) {
            $seen[$current] = true;
            if (!isset($sets[$current])) {
                $sets[$current] = array();
            }
            $sets[$current][$product_id] = true;
            $current = absint($by_id[$current]->parent);
        }
    }

    $counts = array();
    foreach ($by_id as $term_id => $term) {
        $counts[$term_id] = isset($sets[$term_id]) ? count($sets[$term_id]) : 0;
    }

    return $counts;
}

/**
 * Crea un registro vacio para una categoria observada.
 */
function seo_google_demand_empty_category_row($term_id, $term, $product_count) {
    return array(
        'term_id'                 => absint($term_id),
        'name'                    => $term->name,
        'slug'                    => $term->slug,
        'parent'                  => absint($term->parent),
        'product_count'           => absint($product_count),
        'impressions'             => 0.0,
        'clicks'                  => 0.0,
        'position_weight'         => 0.0,
        'actionable_impressions'  => 0.0,
        'previous_impressions'    => 0.0,
        'previous_actionable'     => 0.0,
        'query_hashes'            => array(),
        'landing_hashes'          => array(),
        'product_landing_hashes'  => array(),
        'category_landing_hashes' => array(),
        'top_queries'             => array(),
        'position'                => 0.0,
        'ctr'                     => 0.0,
        'specificity'             => 0.0,
        'trend'                   => null,
        'score'                   => 0,
        'recommendation'          => 'OBSERVAR',
        'recommendation_note'     => '',
        'demand_norm'             => 0.0,
        'catalog_norm'            => 0.0,
        'gap_norm'                => 0.0,
    );
}

/**
 * Agrega Search Console por categoria local.
 */
function seo_google_demand_build_category_metrics($property_id, array $period, $min_impressions = 1) {
    $cache_key = 'seo_g_demand_' . substr(
        hash(
            'sha256',
            (string) $property_id . '|'
            . (string) $period['current_from'] . '|'
            . (string) $period['current_to'] . '|'
            . (string) $period['previous_from'] . '|'
            . (string) $period['previous_to'] . '|'
            . (string) $min_impressions . '|'
            . SEO_GOOGLE_DEMAND_CATALOG_VERSION
        ),
        0,
        32
    );

    $cached = get_transient($cache_key);
    if (is_array($cached) && isset($cached['categories'])) {
        return $cached;
    }

    $rows           = seo_google_demand_get_query_page_rows($property_id, $period, $min_impressions);
    $index          = seo_google_demand_get_category_index();
    $catalog_counts = seo_google_demand_get_catalog_counts();
    $categories     = array();
    $unmapped_imp   = 0.0;
    $mapped_imp     = 0.0;
    $mapped_pairs   = 0;
    $unmapped_pairs = 0;
    $diagnostics    = seo_google_demand_get_storage_diagnostics($property_id, $period);

    foreach ($rows as $row) {
        $current_imp  = (float) $row['current_impressions'];
        $previous_imp = (float) $row['previous_impressions'];

        $relation = seo_google_demand_category_for_page($row['page_url']);
        if (empty($relation['recognized']) || empty($relation['term_id'])) {
            $unmapped_imp += $current_imp;
            $unmapped_pairs++;
            continue;
        }

        $term_id = absint($relation['term_id']);
        if (!isset($index['by_id'][$term_id])) {
            $unmapped_imp += $current_imp;
            $unmapped_pairs++;
            continue;
        }

        $profile     = seo_google_demand_query_profile($row['query_text']);
        $quality     = (float) $profile['score'];
        $current_clk = (float) $row['current_clicks'];
        $page_hash   = (string) $row['page_hash'];
        $query_hash  = (string) $row['query_hash'];

        // La senal alimenta la categoria mas concreta y sus ancestros. Esto
        // permite analizar tanto familias hoja como grupos comerciales amplios.
        $target_terms = array();
        $current_term = $term_id;
        $seen_terms   = array();

        while ($current_term && isset($index['by_id'][$current_term]) && !isset($seen_terms[$current_term])) {
            $seen_terms[$current_term] = true;
            $target_terms[] = $current_term;
            $current_term = absint($index['by_id'][$current_term]->parent);
        }

        foreach ($target_terms as $target_term_id) {
            if (!isset($categories[$target_term_id])) {
                $categories[$target_term_id] = seo_google_demand_empty_category_row(
                    $target_term_id,
                    $index['by_id'][$target_term_id],
                    isset($catalog_counts[$target_term_id]) ? $catalog_counts[$target_term_id] : 0
                );
            }

            $categories[$target_term_id]['impressions']            += $current_imp;
            $categories[$target_term_id]['clicks']                 += $current_clk;
            $categories[$target_term_id]['position_weight']        += (float) $row['current_position_weight'];
            $categories[$target_term_id]['actionable_impressions'] += $current_imp * $quality;
            $categories[$target_term_id]['previous_impressions']   += $previous_imp;
            $categories[$target_term_id]['previous_actionable']    += $previous_imp * $quality;
            $categories[$target_term_id]['query_hashes'][$query_hash] = true;
            $categories[$target_term_id]['landing_hashes'][$page_hash] = true;

            if ('product' === $relation['source']) {
                $categories[$target_term_id]['product_landing_hashes'][$page_hash] = true;
            } elseif ('category' === $relation['source']) {
                $categories[$target_term_id]['category_landing_hashes'][$page_hash] = true;
            }

            if (!isset($categories[$target_term_id]['top_queries'][$query_hash])) {
                $categories[$target_term_id]['top_queries'][$query_hash] = array(
                    'query'                  => $row['query_text'],
                    'impressions'            => 0.0,
                    'clicks'                 => 0.0,
                    'actionable_impressions' => 0.0,
                    'quality'                => $quality,
                    'quality_label'          => $profile['label'],
                );
            }

            $categories[$target_term_id]['top_queries'][$query_hash]['impressions']            += $current_imp;
            $categories[$target_term_id]['top_queries'][$query_hash]['clicks']                 += $current_clk;
            $categories[$target_term_id]['top_queries'][$query_hash]['actionable_impressions'] += $current_imp * $quality;
        }

        // Cobertura global se cuenta una sola vez aunque la senal se propague.
        $mapped_imp += $current_imp;
        $mapped_pairs++;
    }

    foreach ($categories as &$category) {
        $category['position'] = $category['impressions'] > 0
            ? $category['position_weight'] / $category['impressions']
            : 0.0;
        $category['ctr'] = $category['impressions'] > 0
            ? $category['clicks'] / $category['impressions']
            : 0.0;
        $category['specificity'] = $category['impressions'] > 0
            ? $category['actionable_impressions'] / $category['impressions']
            : 0.0;

        if ($category['previous_actionable'] > 0) {
            $category['trend'] = (
                $category['actionable_impressions'] - $category['previous_actionable']
            ) / $category['previous_actionable'];
        } elseif ($category['actionable_impressions'] > 0) {
            $category['trend'] = 1.0;
        }

        $category['query_count']            = count($category['query_hashes']);
        $category['landing_count']          = count($category['landing_hashes']);
        $category['product_landing_count']  = count($category['product_landing_hashes']);
        $category['category_landing_count'] = count($category['category_landing_hashes']);

        $top_queries = array_values($category['top_queries']);
        usort($top_queries, static function ($a, $b) {
            if ((float) $a['actionable_impressions'] === (float) $b['actionable_impressions']) {
                return (float) $b['impressions'] <=> (float) $a['impressions'];
            }
            return (float) $b['actionable_impressions'] <=> (float) $a['actionable_impressions'];
        });
        $category['top_queries'] = array_slice($top_queries, 0, 6);

        unset(
            $category['query_hashes'],
            $category['landing_hashes'],
            $category['product_landing_hashes'],
            $category['category_landing_hashes'],
            $category['position_weight'],
            $category['previous_impressions']
        );
    }
    unset($category);

    $categories = seo_google_demand_score_categories(array_values($categories));

    $report = array(
        'categories'           => $categories,
        'mapped_impressions'   => $mapped_imp,
        'unmapped_impressions' => $unmapped_imp,
        'source_rows'          => count($rows),
        'mapped_pairs'         => $mapped_pairs,
        'unmapped_pairs'       => $unmapped_pairs,
        'diagnostics'          => $diagnostics,
    );

    set_transient($cache_key, $report, 15 * MINUTE_IN_SECONDS);

    return $report;
}

/**
 * Normalizacion logaritmica resistente a categorias con volumen extremo.
 */
function seo_google_demand_log_norm($value, $max_value) {
    $value     = max(0.0, (float) $value);
    $max_value = max(0.0, (float) $max_value);

    if ($max_value <= 0) {
        return 0.0;
    }

    return min(1.0, log(1.0 + $value) / log(1.0 + $max_value));
}

/**
 * Puntua y clasifica categorias. Los estados de reduccion son avisos de revision,
 * nunca ordenes automaticas de borrar productos.
 */
function seo_google_demand_score_categories(array $categories) {
    if (!$categories) {
        return $categories;
    }

    $max_demand   = 0.0;
    $max_clicks   = 0.0;
    $max_products = 0.0;
    $max_gap      = 0.0;
    $max_queries  = 0.0;

    foreach ($categories as $category) {
        $max_demand   = max($max_demand, (float) $category['actionable_impressions']);
        $max_clicks   = max($max_clicks, (float) $category['clicks']);
        $max_products = max($max_products, (float) $category['product_count']);
        $max_queries  = max($max_queries, (float) $category['query_count']);
        $max_gap      = max(
            $max_gap,
            (float) $category['actionable_impressions'] / max(2.0, (float) $category['product_count'])
        );
    }

    foreach ($categories as &$category) {
        $demand_norm  = seo_google_demand_log_norm($category['actionable_impressions'], $max_demand);
        $click_norm   = seo_google_demand_log_norm($category['clicks'], $max_clicks);
        $catalog_norm = seo_google_demand_log_norm($category['product_count'], $max_products);
        $query_norm   = seo_google_demand_log_norm($category['query_count'], $max_queries);
        $gap_value    = (float) $category['actionable_impressions'] / max(2.0, (float) $category['product_count']);
        $gap_norm     = seo_google_demand_log_norm($gap_value, $max_gap);
        $specificity  = (float) $category['specificity'];
        $position     = (float) $category['position'];
        $trend        = null === $category['trend'] ? 0.0 : max(-1.0, min(1.0, (float) $category['trend']));
        $trend_up     = max(0.0, $trend);

        if ($position > 0 && $position <= 3) {
            $position_opportunity = 0.50;
        } elseif ($position <= 10 && $position > 0) {
            $position_opportunity = 1.00;
        } elseif ($position <= 20 && $position > 0) {
            $position_opportunity = 0.90;
        } elseif ($position <= 50 && $position > 0) {
            $position_opportunity = 0.65;
        } elseif ($position > 50) {
            $position_opportunity = 0.30;
        } else {
            $position_opportunity = 0.15;
        }

        $score = 100 * (
            (0.30 * $demand_norm)
            + (0.18 * $gap_norm)
            + (0.16 * $specificity)
            + (0.12 * $click_norm)
            + (0.12 * $position_opportunity)
            + (0.07 * $trend_up)
            + (0.05 * $query_norm)
        );

        $category['score']        = (int) round(min(100, max(0, $score)));
        $category['demand_norm']  = $demand_norm;
        $category['catalog_norm'] = $catalog_norm;
        $category['gap_norm']     = $gap_norm;

        // Search Console no puede demostrar por si solo que una rama deba reducirse.
        // La ausencia reciente se conserva como senal, no como orden de catalogo.
        if ((float) $category['impressions'] <= 0 && (float) $category['previous_actionable'] > 0) {
            $category['recommendation']      = 'SIN SENAL RECIENTE';
            $category['recommendation_note'] = 'Hubo visibilidad en el periodo anterior, pero no en el actual. Esperar mas historico y cruzar ventas antes de decidir.';
        } elseif ($category['impressions'] >= 20 && $specificity < 0.32) {
            $category['recommendation']      = 'DEMANDA GENERICA';
            $category['recommendation_note'] = 'Hay visibilidad, pero demasiada procede de consultas amplias. Conviene separar las intenciones concretas antes de ampliar surtido.';
        } elseif ($demand_norm >= 0.68 && $gap_norm >= 0.66 && $specificity >= 0.48) {
            $category['recommendation']      = 'ALTA OPORTUNIDAD';
            $category['recommendation_note'] = 'Demanda util alta en relacion con la profundidad actual. Revisar las consultas para detectar exactamente que variantes faltan.';
        } elseif ($demand_norm >= 0.55 && $specificity >= 0.45 && $position > 3 && $position <= 30) {
            $category['recommendation']      = 'POTENCIAR';
            $category['recommendation_note'] = 'Existe demanda util y Google ya concede traccion. Mejorar cobertura, contenido y surtido puede capturar mas demanda.';
        } elseif ($demand_norm >= 0.34 || $category['clicks'] > 0) {
            $category['recommendation']      = 'COBERTURA RAZONABLE';
            $category['recommendation_note'] = 'Existe demanda observable y no aparece todavia un hueco claro. Mantener y vigilar la evolucion.';
        } elseif ($category['impressions'] > 0) {
            $category['recommendation']      = 'DEMANDA INSUFICIENTE';
            $category['recommendation_note'] = 'La senal actual es pequena para recomendar una inversion de catalogo. No implica que la categoria carezca de mercado.';
        } else {
            $category['recommendation']      = 'OBSERVAR';
            $category['recommendation_note'] = 'No hay evidencia suficiente para tomar una decision de catalogo con Search Console.';
        }
    }
    unset($category);

    usort($categories, static function ($a, $b) {
        if ((int) $a['score'] === (int) $b['score']) {
            return (float) $b['actionable_impressions'] <=> (float) $a['actionable_impressions'];
        }
        return (int) $b['score'] <=> (int) $a['score'];
    });

    return $categories;
}

/**
 * Etiqueta visual de una recomendacion.
 */
function seo_google_demand_badge($recommendation) {
    $styles = array(
        'ALTA OPORTUNIDAD'    => 'background:#d1e7dd;color:#0f5132;',
        'POTENCIAR'           => 'background:#cff4fc;color:#055160;',
        'COBERTURA RAZONABLE' => 'background:#e2e3e5;color:#41464b;',
        'DEMANDA GENERICA'    => 'background:#fff3cd;color:#664d03;',
        'DEMANDA INSUFICIENTE'=> 'background:#f0f0f1;color:#50575e;',
        'SIN SENAL RECIENTE'  => 'background:#fce8d5;color:#7a3e00;',
        'OBSERVAR'            => 'background:#f0f0f1;color:#50575e;',
    );

    $style = isset($styles[$recommendation]) ? $styles[$recommendation] : $styles['OBSERVAR'];

    return '<span style="display:inline-block;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap;' . esc_attr($style) . '">' . esc_html($recommendation) . '</span>';
}

/**
 * Formatea tendencia porcentual.
 */
function seo_google_demand_format_trend($trend) {
    if (null === $trend) {
        return 'Nueva / sin base';
    }

    $percent = (float) $trend * 100;
    return ($percent > 0 ? '+' : '') . number_format_i18n($percent, 1) . '%';
}


/**
 * Resume la demanda que no puede relacionarse directamente con product_cat.
 * Es una fuente de descubrimiento: no se fuerza el mapeo si la evidencia local
 * no existe. Agrupa por consulta y conserva sus principales landing pages.
 */
function seo_google_demand_build_unmapped_queries($property_id, array $period, $min_impressions = 1, $limit = 40) {
    $rows = seo_google_demand_get_query_page_rows($property_id, $period, $min_impressions, 40000);
    $queries = array();

    foreach ($rows as $row) {
        $relation = seo_google_demand_category_for_page($row['page_url']);
        if (!empty($relation['recognized']) && !empty($relation['term_id'])) {
            continue;
        }

        $query_hash = (string) $row['query_hash'];
        $profile    = seo_google_demand_query_profile($row['query_text']);
        $imp        = (float) $row['current_impressions'];
        $clicks     = (float) $row['current_clicks'];

        if (!isset($queries[$query_hash])) {
            $queries[$query_hash] = array(
                'query'                  => (string) $row['query_text'],
                'impressions'            => 0.0,
                'clicks'                 => 0.0,
                'position_weight'        => 0.0,
                'actionable_impressions' => 0.0,
                'quality'                => (float) $profile['score'],
                'quality_label'          => $profile['label'],
                'previous_impressions'   => 0.0,
                'pages'                  => array(),
            );
        }

        $queries[$query_hash]['impressions']            += $imp;
        $queries[$query_hash]['clicks']                 += $clicks;
        $queries[$query_hash]['position_weight']        += (float) $row['current_position_weight'];
        $queries[$query_hash]['actionable_impressions'] += $imp * (float) $profile['score'];
        $queries[$query_hash]['previous_impressions']   += (float) $row['previous_impressions'];

        $page_hash = (string) $row['page_hash'];
        if (!isset($queries[$query_hash]['pages'][$page_hash])) {
            $queries[$query_hash]['pages'][$page_hash] = array(
                'url'         => (string) $row['page_url'],
                'impressions' => 0.0,
                'clicks'      => 0.0,
            );
        }
        $queries[$query_hash]['pages'][$page_hash]['impressions'] += $imp;
        $queries[$query_hash]['pages'][$page_hash]['clicks']      += $clicks;
    }

    foreach ($queries as &$query) {
        $query['position'] = $query['impressions'] > 0
            ? $query['position_weight'] / $query['impressions']
            : 0.0;
        $query['ctr'] = $query['impressions'] > 0
            ? $query['clicks'] / $query['impressions']
            : 0.0;

        if ($query['previous_impressions'] > 0) {
            $query['trend'] = ($query['impressions'] - $query['previous_impressions']) / $query['previous_impressions'];
        } elseif ($query['impressions'] > 0) {
            $query['trend'] = 1.0;
        } else {
            $query['trend'] = null;
        }

        $pages = array_values($query['pages']);
        usort($pages, static function ($a, $b) {
            return (float) $b['impressions'] <=> (float) $a['impressions'];
        });
        $query['page_count'] = count($pages);
        $query['pages'] = array_slice($pages, 0, 4);
        unset($query['position_weight'], $query['previous_impressions']);
    }
    unset($query);

    $queries = array_values($queries);
    usort($queries, static function ($a, $b) {
        if ((float) $a['actionable_impressions'] === (float) $b['actionable_impressions']) {
            return (float) $b['impressions'] <=> (float) $a['impressions'];
        }
        return (float) $b['actionable_impressions'] <=> (float) $a['actionable_impressions'];
    });

    return array_slice($queries, 0, max(10, min(200, absint($limit))));
}

/**
 * Intenta describir el tipo de landing sin convertirlo artificialmente en una
 * categoria. Ayuda a entender por que una consulta no esta mapeada.
 */
function seo_google_demand_landing_type($url) {
    $path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
    if ('' === $path) {
        return 'Inicio';
    }
    if (0 === strpos($path, 'producto/')) {
        return 'Producto no resuelto';
    }
    if (0 === strpos($path, 'tienda/')) {
        return 'Tienda/categoria no resuelta';
    }
    if (0 === strpos($path, 'tag/') || 0 === strpos($path, 'etiqueta/')) {
        return 'Etiqueta';
    }
    if (false !== strpos($path, 'buscar') || false !== strpos($url, '?s=')) {
        return 'Busqueda';
    }
    return '/' . sanitize_title(strtok($path, '/')) . '/';
}

/**
 * Tokens significativos para agrupar consultas por intencion sin servicios externos.
 * Es una agrupacion heuristica y siempre conserva las consultas originales como evidencia.
 */
function seo_google_demand_intent_tokens($query) {
    $normalized = seo_google_demand_normalize_text($query);
    $tokens = '' === $normalized ? array() : preg_split('/\s+/', $normalized);
    $stop = array(
        'de','del','la','las','el','los','y','o','para','por','con','sin','en','un','una','unos','unas',
        'a','al','que','se','online','tienda','venta','distribuidor','distribucion','profesional','profesionales'
    );
    $generic = array('herramienta','herramientas','maquina','maquinas','maquinaria','equipo','equipos','accesorio','accesorios');
    $result = array();

    foreach ($tokens as $token) {
        if (strlen($token) < 3 || in_array($token, $stop, true)) {
            continue;
        }
        // Normalizacion plural muy conservadora para unir variantes obvias.
        if (strlen($token) > 5 && substr($token, -2) === 'es') {
            $token = substr($token, 0, -2);
        } elseif (strlen($token) > 4 && substr($token, -1) === 's') {
            $token = substr($token, 0, -1);
        }
        if (!in_array($token, $result, true)) {
            $result[] = $token;
        }
    }

    // Si solo quedan palabras demasiado genericas, las conservamos para no perder la consulta.
    $specific = array_values(array_diff($result, $generic));
    return $specific ? $specific : $result;
}

/**
 * Similitud lexical entre dos consultas a partir de tokens significativos.
 */
function seo_google_demand_intent_similarity(array $a, array $b) {
    if (!$a || !$b) {
        return 0.0;
    }
    $intersection = array_intersect($a, $b);
    $union = array_unique(array_merge($a, $b));
    $jaccard = count($union) ? count($intersection) / count($union) : 0.0;
    $containment = min(count($a), count($b)) > 0
        ? count($intersection) / min(count($a), count($b))
        : 0.0;
    return max($jaccard, $containment * 0.88);
}

/**
 * Sugiere una categoria existente por similitud lexical. No la da por valida:
 * solo sirve como pista para decidir si falta mapeo o realmente falta oferta.
 */
function seo_google_demand_suggest_category(array $intent_tokens) {
    $index = seo_google_demand_get_category_index();
    $best = null;
    $best_score = 0.0;

    foreach ($index['by_id'] as $term_id => $term) {
        $term_tokens = seo_google_demand_intent_tokens($term->name);
        $score = seo_google_demand_intent_similarity($intent_tokens, $term_tokens);
        if ($score > $best_score) {
            $best_score = $score;
            $best = $term;
        }
    }

    if (!$best || $best_score < 0.56) {
        return null;
    }

    return array(
        'term_id' => absint($best->term_id),
        'name'    => $best->name,
        'score'   => $best_score,
    );
}

/**
 * Agrupa consultas no mapeadas en familias de intencion.
 * El objetivo es juntar variantes como singular/plural y modificadores cercanos,
 * no inventar relaciones semanticas que Search Console no demuestre.
 */
function seo_google_demand_build_intent_clusters(array $queries, $limit = 30) {
    $clusters = array();

    foreach ($queries as $query) {
        if ((float) $query['impressions'] <= 0) {
            continue;
        }
        $tokens = seo_google_demand_intent_tokens($query['query']);
        $best_index = null;
        $best_similarity = 0.0;

        foreach ($clusters as $idx => $cluster) {
            $similarity = seo_google_demand_intent_similarity($tokens, $cluster['tokens']);
            if ($similarity > $best_similarity) {
                $best_similarity = $similarity;
                $best_index = $idx;
            }
        }

        if (null === $best_index || $best_similarity < 0.64) {
            $clusters[] = array(
                'label'                  => $query['query'],
                'tokens'                 => $tokens,
                'queries'                => array($query),
                'impressions'            => (float) $query['impressions'],
                'actionable_impressions' => (float) $query['actionable_impressions'],
                'clicks'                 => (float) $query['clicks'],
                'position_weight'        => (float) $query['position'] * (float) $query['impressions'],
                'quality_weight'         => (float) $query['quality'] * (float) $query['impressions'],
                'previous_estimate'      => (null !== $query['trend'] && $query['trend'] > -0.999)
                    ? ((float) $query['impressions'] / (1 + (float) $query['trend']))
                    : 0.0,
                'pages'                  => $query['pages'],
                'page_count'             => absint($query['page_count']),
            );
            continue;
        }

        $cluster =& $clusters[$best_index];
        $cluster['queries'][] = $query;
        $cluster['impressions'] += (float) $query['impressions'];
        $cluster['actionable_impressions'] += (float) $query['actionable_impressions'];
        $cluster['clicks'] += (float) $query['clicks'];
        $cluster['position_weight'] += (float) $query['position'] * (float) $query['impressions'];
        $cluster['quality_weight'] += (float) $query['quality'] * (float) $query['impressions'];
        $cluster['previous_estimate'] += (null !== $query['trend'] && $query['trend'] > -0.999)
            ? ((float) $query['impressions'] / (1 + (float) $query['trend']))
            : 0.0;
        $cluster['page_count'] += absint($query['page_count']);
        $cluster['tokens'] = array_values(array_unique(array_merge($cluster['tokens'], $tokens)));
        $cluster['pages'] = array_slice(array_merge($cluster['pages'], $query['pages']), 0, 6);
        unset($cluster);
    }

    $max_actionable = 1.0;
    foreach ($clusters as $cluster) {
        $max_actionable = max($max_actionable, (float) $cluster['actionable_impressions']);
    }

    foreach ($clusters as &$cluster) {
        usort($cluster['queries'], static function ($a, $b) {
            return (float) $b['actionable_impressions'] <=> (float) $a['actionable_impressions'];
        });
        $cluster['queries'] = array_slice($cluster['queries'], 0, 8);
        $cluster['query_count'] = count($cluster['queries']);
        $cluster['position'] = $cluster['impressions'] > 0
            ? $cluster['position_weight'] / $cluster['impressions']
            : 0.0;
        $cluster['quality'] = $cluster['impressions'] > 0
            ? $cluster['quality_weight'] / $cluster['impressions']
            : 0.0;
        $cluster['ctr'] = $cluster['impressions'] > 0 ? $cluster['clicks'] / $cluster['impressions'] : 0.0;
        if ($cluster['previous_estimate'] > 0) {
            $cluster['trend'] = ($cluster['impressions'] - $cluster['previous_estimate']) / $cluster['previous_estimate'];
        } else {
            $cluster['trend'] = $cluster['impressions'] > 0 ? 1.0 : null;
        }

        $demand_norm = seo_google_demand_log_norm($cluster['actionable_impressions'], $max_actionable);
        $position_bonus = $cluster['position'] > 0 && $cluster['position'] <= 40 ? 0.14 : 0.0;
        $trend_bonus = null !== $cluster['trend'] && $cluster['trend'] > 0.20 ? 0.10 : 0.0;
        $score = 100 * min(1.0, 0.55 * $demand_norm + 0.25 * $cluster['quality'] + $position_bonus + $trend_bonus);
        $cluster['score'] = (int) round($score);
        $cluster['suggested_category'] = seo_google_demand_suggest_category($cluster['tokens']);

        if ($cluster['quality'] < 0.50) {
            $cluster['decision'] = 'DEMANDA GENERICA';
            $cluster['decision_note'] = 'Hay volumen, pero la consulta es demasiado amplia para decidir surtido. Buscar subintenciones concretas.';
        } elseif ($cluster['suggested_category'] && $cluster['score'] >= 55) {
            $cluster['decision'] = 'REFORZAR / MAPEAR';
            $cluster['decision_note'] = 'Hay demanda util y existe una categoria parecida. Revisar el mapeo y despues comprobar si faltan variantes o contenido.';
        } elseif ($cluster['score'] >= 58) {
            $cluster['decision'] = 'OPORTUNIDAD ESTRUCTURAL';
            $cluster['decision_note'] = 'Demanda util sin una categoria claramente representada. Candidata a nueva familia, landing o ampliacion estructural.';
        } elseif ($cluster['position'] > 0 && $cluster['position'] <= 35 && $cluster['actionable_impressions'] >= 20) {
            $cluster['decision'] = 'POTENCIAR SEO';
            $cluster['decision_note'] = 'Google ya concede cierta traccion. Conviene reforzar la landing que responde a esta intencion antes de ampliar masivamente el catalogo.';
        } else {
            $cluster['decision'] = 'VALIDAR';
            $cluster['decision_note'] = 'Senal interesante pero aun insuficiente. Mantener en observacion y acumular historico.';
        }
        unset($cluster['position_weight'], $cluster['quality_weight'], $cluster['previous_estimate']);
    }
    unset($cluster);

    usort($clusters, static function ($a, $b) {
        if ((int) $a['score'] === (int) $b['score']) {
            return (float) $b['actionable_impressions'] <=> (float) $a['actionable_impressions'];
        }
        return (int) $b['score'] <=> (int) $a['score'];
    });

    return array_slice($clusters, 0, max(10, min(100, absint($limit))));
}

/**
 * Clasifica si una consulta sirve para decidir catalogo o es principalmente
 * corporativa/navegacional. No descarta datos: cambia el tipo de accion.
 */
function seo_google_demand_catalog_relevance($query) {
    $text = seo_google_demand_normalize_text($query);

    $corporate_patterns = array(
        '/\\bdistribuidor(?:es)?\\b/',
        '/\\bdistribucion\\b/',
        '/\\btienda(?:s)? de herramientas(?: online)?\\b/',
        '/\\bventa de maquinas y herramientas\\b/',
        '/\\bherramienta industrial\\b$/',
        '/\\bherramientas industriales\\b$/',
        '/\\bherramientas y equipos\\b$/',
    );

    foreach ($corporate_patterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return array('type' => 'corporate', 'score' => 0.20);
        }
    }

    $bad = preg_match('/(?:eur|%0|%[0-9a-f]{2}|[a-z]+\\d{3,}[a-z]+)/i', $text);
    if ($bad) {
        return array('type' => 'noise', 'score' => 0.05);
    }

    return array('type' => 'catalog', 'score' => 1.0);
}

/**
 * Extrae dimensiones de producto expresadas en las consultas. Esta salida se
 * ha disenado para que tambien pueda ser consumida por otros procesos.
 */
function seo_google_demand_extract_dimensions(array $queries) {
    $dimensions = array(
        'capacity'      => array(),
        'power'         => array(),
        'voltage'       => array(),
        'weight_load'   => array(),
        'size'          => array(),
        'pressure'      => array(),
        'use_case'      => array(),
        'material'      => array(),
        'technology'    => array(),
    );

    $use_terms = array('camion','coche','suv','moto','taller','industrial','hosteleria','jardin','obra','caravana','camper','barco','agricola','garaje');
    $materials = array('aluminio','acero','inoxidable','plastico','pvc','goma','madera','hierro');
    $tech      = array('hidraulico','hidraulica','electrico','electrica','neumatico','neumatica','inalambrico','inalambrica','compresor','digital','automatico','automatica');

    foreach ($queries as $row) {
        $q = seo_google_demand_normalize_text($row['query'] ?? '');
        $imp = max(1.0, (float) ($row['impressions'] ?? 1));
        if ('' === $q) continue;

        $patterns = array(
            'capacity'    => '/\\b(\\d+(?:[.,]\\d+)?)\\s*(l|litro|litros)\\b/i',
            'power'       => '/\\b(\\d+(?:[.,]\\d+)?)\\s*(w|kw|cv|hp)\\b/i',
            'voltage'     => '/\\b(\\d+(?:[.,]\\d+)?)\\s*(v|volt|volts|voltios)\\b/i',
            'weight_load' => '/\\b(\\d+(?:[.,]\\d+)?)\\s*(kg|t|tn|ton|toneladas?)\\b/i',
            'size'        => '/\\b(\\d+(?:[.,]\\d+)?)\\s*(mm|cm|m|pulgadas?)\\b/i',
            'pressure'    => '/\\b(\\d+(?:[.,]\\d+)?)\\s*(bar|psi)\\b/i',
        );

        foreach ($patterns as $kind => $pattern) {
            if (preg_match_all($pattern, $q, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $value = trim($match[1] . ' ' . strtolower($match[2]));
                    if (!isset($dimensions[$kind][$value])) $dimensions[$kind][$value] = 0.0;
                    $dimensions[$kind][$value] += $imp;
                }
            }
        }

        foreach ($use_terms as $term) {
            if (preg_match('/\\b' . preg_quote($term, '/') . '\\b/u', $q)) {
                if (!isset($dimensions['use_case'][$term])) $dimensions['use_case'][$term] = 0.0;
                $dimensions['use_case'][$term] += $imp;
            }
        }
        foreach ($materials as $term) {
            if (preg_match('/\\b' . preg_quote($term, '/') . '\\b/u', $q)) {
                if (!isset($dimensions['material'][$term])) $dimensions['material'][$term] = 0.0;
                $dimensions['material'][$term] += $imp;
            }
        }
        foreach ($tech as $term) {
            if (preg_match('/\\b' . preg_quote($term, '/') . '\\b/u', $q)) {
                if (!isset($dimensions['technology'][$term])) $dimensions['technology'][$term] = 0.0;
                $dimensions['technology'][$term] += $imp;
            }
        }
    }

    foreach ($dimensions as $kind => $values) {
        arsort($values);
        $dimensions[$kind] = array_slice($values, 0, 8, true);
    }
    return $dimensions;
}

function seo_google_demand_dimension_labels(array $dimensions, $limit = 6) {
    $labels = array();
    $names = array(
        'capacity' => 'capacidad', 'power' => 'potencia', 'voltage' => 'voltaje',
        'weight_load' => 'carga', 'size' => 'medida', 'pressure' => 'presion',
        'use_case' => 'uso', 'material' => 'material', 'technology' => 'tecnologia',
    );
    foreach ($dimensions as $kind => $values) {
        foreach ($values as $value => $weight) {
            $labels[] = array('dimension' => $kind, 'label' => ($names[$kind] ?? $kind) . ': ' . $value, 'weight' => (float) $weight);
        }
    }
    usort($labels, static function($a,$b){ return $b['weight'] <=> $a['weight']; });
    return array_slice($labels, 0, max(1, absint($limit)));
}

/**
 * Decide en que sentido potenciar: profundidad, amplitud, SEO, mapeo o nueva familia.
 */
function seo_google_demand_expansion_strategy($kind, $decision, $position, $products, array $dimensions, $suggested_category = null) {
    $has_dimensions = false;
    foreach ($dimensions as $values) {
        if (!empty($values)) { $has_dimensions = true; break; }
    }

    if ('Intencion' === $kind && !$suggested_category) {
        return array('primary' => 'NUEVA_FAMILIA', 'secondary' => $has_dimensions ? 'PROFUNDIDAD' : 'VALIDAR', 'automation_ready' => false);
    }
    if ('Intencion' === $kind && $suggested_category) {
        return array('primary' => 'MAPEO', 'secondary' => $has_dimensions ? 'PROFUNDIDAD' : 'SEO', 'automation_ready' => false);
    }
    if ($products >= 25 && $position >= 20) {
        return array('primary' => 'SEO', 'secondary' => $has_dimensions ? 'PROFUNDIDAD_SELECTIVA' : 'ESTRUCTURA', 'automation_ready' => false);
    }
    if ($has_dimensions && $products > 0) {
        return array('primary' => 'PROFUNDIDAD', 'secondary' => 'SEO', 'automation_ready' => true);
    }
    if ($products <= 8 && in_array($decision, array('ALTA OPORTUNIDAD','POTENCIAR'), true)) {
        return array('primary' => 'AMPLITUD', 'secondary' => 'PROFUNDIDAD', 'automation_ready' => false);
    }
    return array('primary' => 'SEO', 'secondary' => 'OBSERVAR', 'automation_ready' => false);
}

/**
 * Salida publica para otros modulos/procesos de WordPress.
 * No modifica catalogo. Devuelve recomendaciones y evidencia estructurada.
 */
function seo_google_demand_get_catalog_guidance($property_id, $days = 60, $min_impressions = 2, $limit = 50) {
    $period = seo_google_get_analysis_period($property_id, $days);
    if (!$period) return array();

    $report = seo_google_demand_build_category_metrics($property_id, $period, $min_impressions);
    $categories = seo_google_demand_score_categories($report['categories']);
    $unmapped = seo_google_demand_build_unmapped_queries($property_id, $period, $min_impressions, 250);
    $clusters = seo_google_demand_build_intent_clusters($unmapped, 80);
    $items = seo_google_demand_build_focus_ranking($categories, $clusters, $limit);

    $payload = array(
        'version' => SEO_GOOGLE_DEMAND_CATALOG_VERSION,
        'generated_at' => current_time('mysql'),
        'period' => $period,
        'items' => $items,
    );

    return apply_filters('seo_google_demand_catalog_guidance', $payload, $property_id, $days, $min_impressions);
}

/**
 * Ranking ejecutivo: combina categorias existentes e intenciones no mapeadas.
 */
function seo_google_demand_build_focus_ranking(array $categories, array $clusters, $limit = 12) {
    $items = array();

    foreach ($categories as $category) {
        if ((float) $category['impressions'] <= 0) {
            continue;
        }
        if (!in_array($category['recommendation'], array('ALTA OPORTUNIDAD', 'POTENCIAR', 'COBERTURA RAZONABLE'), true)) {
            continue;
        }
        $items[] = array(
            'kind'       => 'Categoria',
            'label'      => $category['name'],
            'score'      => absint($category['score']),
            'decision'   => $category['recommendation'],
            'note'       => $category['recommendation_note'],
            'impressions'=> (float) $category['impressions'],
            'actionable' => (float) $category['actionable_impressions'],
            'position'   => (float) $category['position'],
            'trend'      => $category['trend'],
            'products'   => absint($category['product_count']),
            'term_id'    => absint($category['term_id']),
            'evidence'   => array_slice($category['top_queries'], 0, 6),
        );
        $idx = count($items) - 1;
        $items[$idx]['dimensions'] = seo_google_demand_extract_dimensions($items[$idx]['evidence']);
        $items[$idx]['dimension_labels'] = seo_google_demand_dimension_labels($items[$idx]['dimensions']);
        $items[$idx]['strategy'] = seo_google_demand_expansion_strategy('Categoria', $items[$idx]['decision'], $items[$idx]['position'], $items[$idx]['products'], $items[$idx]['dimensions']);
        $items[$idx]['catalog_relevance'] = 'catalog';
    }

    foreach ($clusters as $cluster) {
        if (!in_array($cluster['decision'], array('OPORTUNIDAD ESTRUCTURAL', 'REFORZAR / MAPEAR', 'POTENCIAR SEO'), true)) {
            continue;
        }
        $relevance = seo_google_demand_catalog_relevance($cluster['label']);
        if ('noise' === $relevance['type']) {
            continue;
        }
        $items[] = array(
            'kind'       => 'Intencion',
            'label'      => $cluster['label'],
            'score'      => absint($cluster['score']),
            'decision'   => $cluster['decision'],
            'note'       => $cluster['decision_note'],
            'impressions'=> (float) $cluster['impressions'],
            'actionable' => (float) $cluster['actionable_impressions'],
            'position'   => (float) $cluster['position'],
            'trend'      => $cluster['trend'],
            'products'   => null,
            'term_id'    => 0,
            'evidence'   => array_map(static function ($query) {
                return array(
                    'query' => $query['query'],
                    'impressions' => $query['impressions'],
                    'clicks' => $query['clicks'],
                    'quality_label' => $query['quality_label'],
                    'quality' => $query['quality'],
                );
            }, array_slice($cluster['queries'], 0, 4)),
            'suggested_category' => $cluster['suggested_category'],
        );
        $idx = count($items) - 1;
        $items[$idx]['catalog_relevance'] = $relevance['type'];
        if ('corporate' === $relevance['type']) {
            $items[$idx]['decision'] = 'SEO CORPORATIVO';
            $items[$idx]['note'] = 'Demanda relevante para posicionamiento de empresa, pero no debe dirigir automaticamente el surtido.';
            $items[$idx]['score'] = max(1, (int) round($items[$idx]['score'] * 0.55));
        }
        $items[$idx]['dimensions'] = seo_google_demand_extract_dimensions($items[$idx]['evidence']);
        $items[$idx]['dimension_labels'] = seo_google_demand_dimension_labels($items[$idx]['dimensions']);
        $items[$idx]['strategy'] = seo_google_demand_expansion_strategy('Intencion', $items[$idx]['decision'], $items[$idx]['position'], 0, $items[$idx]['dimensions'], $items[$idx]['suggested_category']);
    }

    usort($items, static function ($a, $b) {
        if ((int) $a['score'] === (int) $b['score']) {
            return (float) $b['actionable'] <=> (float) $a['actionable'];
        }
        return (int) $b['score'] <=> (int) $a['score'];
    });

    return array_slice($items, 0, max(5, min(30, absint($limit))));
}

/**
 * Render principal de la nueva pestana Demanda x Catalogo.
 */
function seo_google_render_demand_catalog() {
    if (!seo_google_analysis_ready()) {
        return;
    }

    $settings = seo_google_get_settings();
    $days     = isset($_GET['demand_days']) ? absint($_GET['demand_days']) : 28;
    $days     = in_array($days, array(14, 28, 60, 90), true) ? $days : 28;
    $min_imp  = isset($_GET['demand_min']) ? max(0, (float) $_GET['demand_min']) : 2;
    $decision = isset($_GET['demand_decision']) ? sanitize_text_field(wp_unslash($_GET['demand_decision'])) : '';
    $search   = isset($_GET['demand_search']) ? sanitize_text_field(wp_unslash($_GET['demand_search'])) : '';

    $period = seo_google_get_analysis_period($settings['property_id'], $days);

    if (!$period) {
        echo '<div class="notice notice-error inline"><p>No se pudo calcular el periodo de analisis.</p></div>';
        return;
    }

    $report     = seo_google_demand_build_category_metrics($settings['property_id'], $period, $min_imp);
    $categories = $report['categories'];
    // Recuperamos mas consultas para poder agrupar variantes por intencion antes de limitar la salida.
    $unmapped_queries = seo_google_demand_build_unmapped_queries($settings['property_id'], $period, $min_imp, 200);
    $intent_clusters  = seo_google_demand_build_intent_clusters($unmapped_queries, 50);
    $focus_ranking    = seo_google_demand_build_focus_ranking($categories, $intent_clusters, 12);

    $summary = array(
        'ALTA OPORTUNIDAD'     => 0,
        'POTENCIAR'            => 0,
        'COBERTURA RAZONABLE'  => 0,
        'DEMANDA GENERICA'     => 0,
        'DEMANDA INSUFICIENTE' => 0,
        'SIN SENAL RECIENTE'   => 0,
        'OBSERVAR'             => 0,
    );

    foreach ($categories as $category) {
        if (isset($summary[$category['recommendation']])) {
            $summary[$category['recommendation']]++;
        }
    }

    $filtered = array();
    foreach ($categories as $category) {
        if ('' !== $decision && $decision !== $category['recommendation']) {
            continue;
        }
        if ('' !== $search && false === stripos(remove_accents($category['name']), remove_accents($search))) {
            continue;
        }
        $filtered[] = $category;
    }

    $total_observed = (float) $report['mapped_impressions'] + (float) $report['unmapped_impressions'];
    $mapped_pct     = $total_observed > 0 ? ((float) $report['mapped_impressions'] / $total_observed) * 100 : 0;

    echo '<div style="background:#fff;border:1px solid #dcdcde;padding:20px;border-radius:6px;margin-bottom:20px;">';
    echo '<div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:flex-start;">';
    echo '<div><h3 style="margin:0 0 6px;">Demanda × Catalogo</h3>';
    echo '<p style="margin:0;max-width:950px;">Relaciona consultas reales de Search Console con las categorias y productos actuales de WooCommerce. <strong>No mide todo el mercado</strong>: mide la demanda en la que Google ya relaciona alguna URL de la web. Las consultas genericas reciben menos peso para que volumen no sea igual a prioridad.</p></div>';
    echo '<div><code>V' . esc_html(SEO_GOOGLE_DEMAND_CATALOG_VERSION) . '</code></div>';
    echo '</div>';

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:12px;margin-top:18px;">';
    $cards = array(
        'Categorias observadas' => count($categories),
        'Alta oportunidad'      => $summary['ALTA OPORTUNIDAD'],
        'Potenciar'             => $summary['POTENCIAR'],
        'Demanda generica'      => $summary['DEMANDA GENERICA'],
        'Sin senal reciente'    => $summary['SIN SENAL RECIENTE'],
        'Cobertura mapeada'     => number_format_i18n($mapped_pct, 1) . '%',
    );
    foreach ($cards as $label => $value) {
        echo '<div style="border:1px solid #dcdcde;border-radius:6px;padding:12px;">';
        echo '<small style="color:#646970;text-transform:uppercase;font-weight:700;">' . esc_html($label) . '</small><br>';
        echo '<strong style="font-size:24px;">' . esc_html($value) . '</strong>';
        echo '</div>';
    }
    echo '</div>';

    echo '<div style="margin-top:16px;background:#f6f7f7;border-left:4px solid #2271b1;padding:12px 14px;">';
    echo '<strong>Como leerlo:</strong> “Demanda util” = impresiones ponderadas por accionabilidad de la consulta. Search Console sirve para priorizar oportunidades, pero <strong>no autoriza por si solo a reducir o eliminar catalogo</strong>. La baja senal se muestra como demanda insuficiente o sin senal reciente hasta incorporar mas historico y datos comerciales.';
    echo '</div>';
    echo '</div>';

    echo '<div style="background:#fff;border:1px solid #dcdcde;padding:18px;border-radius:6px;margin-bottom:20px;">';
    echo '<form method="get" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;align-items:end;">';
    echo '<input type="hidden" name="page" value="seo-reports">';
    echo '<input type="hidden" name="tab" value="google_intelligence">';
    echo '<input type="hidden" name="google_view" value="demand_catalog">';

    echo '<label><strong>Periodo</strong><br><select name="demand_days" style="width:100%;">';
    foreach (array(14, 28, 60, 90) as $option_days) {
        echo '<option value="' . absint($option_days) . '" ' . selected($days, $option_days, false) . '>' . absint($option_days) . ' dias</option>';
    }
    echo '</select></label>';

    echo '<label><strong>Impresiones min. por consulta+URL</strong><br><input type="number" min="0" step="1" name="demand_min" value="' . esc_attr($min_imp) . '" style="width:100%;"></label>';

    echo '<label><strong>Decision</strong><br><select name="demand_decision" style="width:100%;">';
    echo '<option value="">Todas</option>';
    foreach (array_keys($summary) as $option) {
        echo '<option value="' . esc_attr($option) . '" ' . selected($decision, $option, false) . '>' . esc_html($option) . '</option>';
    }
    echo '</select></label>';

    echo '<label><strong>Categoria</strong><br><input type="search" name="demand_search" value="' . esc_attr($search) . '" placeholder="Ej.: gatos" style="width:100%;"></label>';
    echo '<div>';
    submit_button('Aplicar', 'secondary', 'submit', false);
    echo '</div>';
    echo '</form>';
    echo '<p class="description" style="margin-bottom:0;"><code>' . esc_html($period['current_from']) . '</code> → <code>' . esc_html($period['current_to']) . '</code> frente a <code>' . esc_html($period['previous_from']) . '</code> → <code>' . esc_html($period['previous_to']) . '</code>. Se procesaron ' . number_format_i18n($report['source_rows']) . ' pares consulta+URL agregados.</p>';

    $diag = isset($report['diagnostics']) && is_array($report['diagnostics']) ? $report['diagnostics'] : array();
    echo '<div style="margin-top:12px;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;">';
    echo '<strong>Diagnostico de datos almacenados:</strong> ';
    echo number_format_i18n(absint($diag['stored_rows'] ?? 0)) . ' filas totales · ';
    echo number_format_i18n(absint($diag['current_rows'] ?? 0)) . ' filas en periodo actual · ';
    echo number_format_i18n(absint($diag['current_pairs'] ?? 0)) . ' pares actuales sin filtro · ';
    echo number_format_i18n(absint($report['source_rows'])) . ' pares tras filtro · ';
    echo number_format_i18n(absint($report['mapped_pairs'] ?? 0)) . ' mapeados · ';
    echo number_format_i18n(absint($report['unmapped_pairs'] ?? 0)) . ' sin mapear.';
    if (!empty($diag['latest_date'])) {
        echo ' Ultimo dato: <code>' . esc_html($diag['latest_date']) . '</code>.';
    }
    echo '</div>';
    echo '</div>';

    echo '<div style="background:#fff;border:2px solid #2271b1;padding:18px;border-radius:6px;margin-bottom:20px;overflow:auto;">';
    echo '<h3 style="margin-top:0;">Que potenciar y en que sentido</h3>';
    echo '<p>Ranking ejecutivo para decidir <strong>donde actuar</strong> y <strong>como ampliar</strong>: mas variantes dentro de una familia, categorias cercanas, nueva familia, mapeo o trabajo SEO. La misma recomendacion se expone como datos estructurados mediante <code>seo_google_demand_get_catalog_guidance()</code> para que otros procesos puedan reutilizarla.</p>';
    if (!$focus_ranking) {
        echo '<p>No hay oportunidades suficientes con los filtros actuales.</p>';
    } else {
        echo '<table class="widefat striped" style="min-width:1120px;"><thead><tr>';
        echo '<th>Prioridad</th><th>Tipo</th><th>Que potenciar</th><th>Estrategia</th><th>Direccion detectada</th><th>Imp.</th><th>Demanda util</th><th>Pos.</th><th>Tendencia</th><th>Catalogo / pista</th><th>Por que</th>';
        echo '</tr></thead><tbody>';
        foreach ($focus_ranking as $item) {
            echo '<tr>';
            echo '<td><strong style="font-size:20px;">' . absint($item['score']) . '</strong><small>/100</small></td>';
            echo '<td>' . esc_html($item['kind']) . '</td>';
            echo '<td style="min-width:210px;"><strong>' . esc_html($item['label']) . '</strong>';
            if ('Categoria' === $item['kind'] && $item['term_id']) {
                $link = get_term_link($item['term_id'], 'product_cat');
                if (!is_wp_error($link)) {
                    echo '<br><a href="' . esc_url($link) . '" target="_blank" rel="noopener noreferrer"><small>Abrir categoria</small></a>';
                }
            }
            echo '</td>';
            echo '<td><strong>' . esc_html($item['strategy']['primary'] ?? $item['decision']) . '</strong><br><small>' . esc_html($item['decision']) . '</small></td>';
            echo '<td style="min-width:220px;">';
            if (!empty($item['dimension_labels'])) {
                $parts = array();
                foreach ($item['dimension_labels'] as $dim) { $parts[] = esc_html($dim['label']); }
                echo implode('<br>', array_slice($parts, 0, 5));
            } else {
                echo '<small>Sin atributo concreto suficiente; revisar subintenciones.</small>';
            }
            echo '</td>';
            echo '<td>' . number_format_i18n($item['impressions'], 0) . '</td>';
            echo '<td><strong>' . number_format_i18n($item['actionable'], 0) . '</strong></td>';
            echo '<td>' . ($item['position'] > 0 ? number_format_i18n($item['position'], 1) : '—') . '</td>';
            echo '<td>' . esc_html(seo_google_demand_format_trend($item['trend'])) . '</td>';
            echo '<td>';
            if ('Categoria' === $item['kind']) {
                echo number_format_i18n($item['products']) . ' productos';
            } elseif (!empty($item['suggested_category'])) {
                echo 'Posible relacion: <strong>' . esc_html($item['suggested_category']['name']) . '</strong><br><small>similitud ' . number_format_i18n($item['suggested_category']['score'] * 100, 0) . '%; revisar manualmente</small>';
            } else {
                echo 'Sin categoria clara';
            }
            echo '</td>';
            echo '<td style="min-width:340px;">' . esc_html($item['note']);
            if (!empty($item['strategy']['secondary'])) { echo '<br><small><strong>Secundaria:</strong> ' . esc_html($item['strategy']['secondary']) . '</small>'; }
            if (!empty($item['strategy']['automation_ready'])) { echo '<br><small><strong>Apta para automatizacion:</strong> si, con validacion de cobertura.</small>'; }
            if (!empty($item['evidence'])) {
                echo '<details style="margin-top:6px;"><summary>Consultas que lo justifican</summary><ol style="margin:7px 0 0 18px;">';
                foreach ($item['evidence'] as $ev) {
                    echo '<li><strong>' . esc_html($ev['query']) . '</strong> <small>(' . number_format_i18n($ev['impressions'], 0) . ' imp.)</small></li>';
                }
                echo '</ol></details>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    echo '<div style="background:#fff;border:1px solid #dcdcde;padding:18px;border-radius:6px;overflow:auto;">';
    echo '<h3 style="margin-top:0;">Prioridad por categoria</h3>';

    if (!$filtered) {
        echo '<p>No hay categorias que cumplan los filtros.</p></div>';
        return;
    }

    echo '<table class="widefat striped" style="min-width:1250px;">';
    echo '<thead><tr>';
    echo '<th>Prioridad</th><th>Categoria</th><th>Decision</th><th>Productos</th><th>Imp.</th><th>Demanda util</th><th>Precision</th><th>Clics</th><th>CTR</th><th>Pos.</th><th>Tendencia</th><th>Consultas / landings</th><th>Evidencias</th>';
    echo '</tr></thead><tbody>';

    foreach ($filtered as $category) {
        $term_link = get_term_link($category['term_id'], 'product_cat');

        echo '<tr>';
        echo '<td><strong style="font-size:20px;">' . absint($category['score']) . '</strong><small>/100</small></td>';
        echo '<td style="min-width:190px;"><strong>' . esc_html($category['name']) . '</strong>';
        if (!is_wp_error($term_link)) {
            echo '<br><a href="' . esc_url($term_link) . '" target="_blank" rel="noopener noreferrer"><small>Abrir categoria</small></a>';
        }
        echo '</td>';
        echo '<td style="min-width:150px;">' . seo_google_demand_badge($category['recommendation']);
        echo '<br><small title="' . esc_attr($category['recommendation_note']) . '">' . esc_html($category['recommendation_note']) . '</small></td>';
        echo '<td>' . number_format_i18n($category['product_count']) . '</td>';
        echo '<td>' . number_format_i18n($category['impressions'], 0) . '</td>';
        echo '<td><strong>' . number_format_i18n($category['actionable_impressions'], 0) . '</strong></td>';
        echo '<td>' . number_format_i18n($category['specificity'] * 100, 0) . '%</td>';
        echo '<td>' . number_format_i18n($category['clicks'], 0) . '</td>';
        echo '<td>' . number_format_i18n($category['ctr'] * 100, 2) . '%</td>';
        echo '<td>' . ($category['position'] > 0 ? number_format_i18n($category['position'], 1) : '—') . '</td>';
        echo '<td>' . esc_html(seo_google_demand_format_trend($category['trend'])) . '</td>';
        echo '<td>' . number_format_i18n($category['query_count']) . ' / ' . number_format_i18n($category['landing_count']);
        echo '<br><small>' . number_format_i18n($category['product_landing_count']) . ' prod. · ' . number_format_i18n($category['category_landing_count']) . ' cat.</small></td>';
        echo '<td style="min-width:360px;">';

        if ($category['top_queries']) {
            echo '<details><summary>Ver consultas principales</summary><ol style="margin:8px 0 0 18px;">';
            foreach ($category['top_queries'] as $query) {
                echo '<li style="margin-bottom:8px;"><strong>' . esc_html($query['query']) . '</strong><br><small>';
                echo number_format_i18n($query['impressions'], 0) . ' imp. · ';
                echo number_format_i18n($query['clicks'], 0) . ' clics · accionabilidad ';
                echo esc_html($query['quality_label']) . ' (' . number_format_i18n($query['quality'] * 100, 0) . '%)';
                echo '</small></li>';
            }
            echo '</ol></details>';
        } else {
            echo '—';
        }

        echo '</td></tr>';
    }

    echo '</tbody></table>';
    echo '</div>';

    echo '<div style="background:#fff;border:1px solid #dcdcde;padding:18px;border-radius:6px;margin-top:20px;overflow:auto;">';
    echo '<h3 style="margin-top:0;">Intenciones sin representar claramente</h3>';
    echo '<p>Agrupa variantes de consultas no mapeadas para responder a una pregunta mas util: <strong>que familias o necesidades esta insinuando Google aunque hoy no tengan una categoria claramente asociada</strong>. La agrupacion es heuristica; siempre se muestran las consultas originales.</p>';

    if (!$intent_clusters) {
        echo '<p>No hay intenciones no mapeadas con los filtros actuales.</p>';
    } else {
        echo '<table class="widefat striped" style="min-width:1120px;"><thead><tr>';
        echo '<th>Prioridad</th><th>Intencion</th><th>Decision</th><th>Imp.</th><th>Demanda util</th><th>Precision</th><th>Pos.</th><th>Tendencia</th><th>Posible categoria</th><th>Consultas</th>';
        echo '</tr></thead><tbody>';
        foreach ($intent_clusters as $cluster) {
            echo '<tr>';
            echo '<td><strong style="font-size:20px;">' . absint($cluster['score']) . '</strong><small>/100</small></td>';
            echo '<td style="min-width:210px;"><strong>' . esc_html($cluster['label']) . '</strong></td>';
            echo '<td style="min-width:190px;"><strong>' . esc_html($cluster['decision']) . '</strong><br><small>' . esc_html($cluster['decision_note']) . '</small></td>';
            echo '<td>' . number_format_i18n($cluster['impressions'], 0) . '</td>';
            echo '<td><strong>' . number_format_i18n($cluster['actionable_impressions'], 0) . '</strong></td>';
            echo '<td>' . number_format_i18n($cluster['quality'] * 100, 0) . '%</td>';
            echo '<td>' . ($cluster['position'] > 0 ? number_format_i18n($cluster['position'], 1) : '—') . '</td>';
            echo '<td>' . esc_html(seo_google_demand_format_trend($cluster['trend'])) . '</td>';
            echo '<td>';
            if (!empty($cluster['suggested_category'])) {
                echo '<strong>' . esc_html($cluster['suggested_category']['name']) . '</strong><br><small>Similitud lexical ' . number_format_i18n($cluster['suggested_category']['score'] * 100, 0) . '%. Solo pista.</small>';
            } else {
                echo '—';
            }
            echo '</td><td style="min-width:330px;"><details><summary>' . number_format_i18n($cluster['query_count']) . ' consulta(s) principal(es)</summary><ol style="margin:8px 0 0 18px;">';
            foreach ($cluster['queries'] as $query) {
                echo '<li style="margin-bottom:6px;"><strong>' . esc_html($query['query']) . '</strong><br><small>' . number_format_i18n($query['impressions'], 0) . ' imp. · accionabilidad ' . number_format_i18n($query['quality'] * 100, 0) . '%</small></li>';
            }
            echo '</ol></details></td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '<details style="margin-top:16px;"><summary><strong>Ver consultas individuales sin mapear</strong></summary>';
    echo '<div style="overflow:auto;margin-top:12px;">';
    echo '<h4>Detalle sin agrupar</h4>';

    if (!$unmapped_queries) {
        echo '<p>No hay demanda sin mapear con los filtros actuales.</p>';
    } else {
        echo '<table class="widefat striped" style="min-width:1050px;"><thead><tr>';
        echo '<th>Consulta</th><th>Imp.</th><th>Demanda util</th><th>Precision</th><th>Clics</th><th>CTR</th><th>Pos.</th><th>Tendencia</th><th>Landings</th>';
        echo '</tr></thead><tbody>';
        foreach ($unmapped_queries as $query) {
            echo '<tr>';
            echo '<td style="min-width:260px;"><strong>' . esc_html($query['query']) . '</strong></td>';
            echo '<td>' . number_format_i18n($query['impressions'], 0) . '</td>';
            echo '<td><strong>' . number_format_i18n($query['actionable_impressions'], 0) . '</strong></td>';
            echo '<td>' . number_format_i18n($query['quality'] * 100, 0) . '%</td>';
            echo '<td>' . number_format_i18n($query['clicks'], 0) . '</td>';
            echo '<td>' . number_format_i18n($query['ctr'] * 100, 2) . '%</td>';
            echo '<td>' . ($query['position'] > 0 ? number_format_i18n($query['position'], 1) : '—') . '</td>';
            echo '<td>' . esc_html(seo_google_demand_format_trend($query['trend'])) . '</td>';
            echo '<td style="min-width:380px;">';
            if (!empty($query['pages'])) {
                echo '<details><summary>' . number_format_i18n($query['page_count']) . ' landing(s)</summary><ul style="margin:8px 0 0 18px;">';
                foreach ($query['pages'] as $page) {
                    echo '<li style="margin-bottom:7px;"><a href="' . esc_url($page['url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($page['url']) . '</a><br><small>';
                    echo esc_html(seo_google_demand_landing_type($page['url'])) . ' · ' . number_format_i18n($page['impressions'], 0) . ' imp. · ' . number_format_i18n($page['clicks'], 0) . ' clics';
                    echo '</small></li>';
                }
                echo '</ul></details>';
            } else {
                echo '—';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></details>';
    echo '</div>';

    echo '<div style="background:#fff;border:1px solid #dcdcde;padding:18px;border-radius:6px;margin-top:20px;">';
    echo '<h3 style="margin-top:0;">Limites de esta version</h3>';
    echo '<ul style="list-style:disc;padding-left:22px;line-height:1.65;">';
    echo '<li>Search Console solo revela demanda donde Google ya relaciona el dominio con una consulta; no representa todo lo que busca el mercado.</li>';
    echo '<li>La atribucion se hace a la categoria mas profunda de la landing de producto, o directamente a la categoria cuando Google muestra su URL.</li>';
    echo '<li>Las consultas no mapeadas se agrupan por similitud lexical para descubrir intenciones. La agrupacion es orientativa y conserva siempre la evidencia original.</li>';
    echo '<li>La baja senal organica no se interpreta como orden de reducir catalogo. Para eso necesitaremos mas historico y ventas, margen, stock y estacionalidad.</li>';
    echo '<li>Keyword Planner se incorporara despues como fuente separada para descubrir demanda de mercado donde el dominio aun no aparece.</li>';
    echo '</ul>';
    echo '</div>';
}
