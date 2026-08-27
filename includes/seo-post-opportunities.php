<?php
/**
 * SEO System - Oportunidades editoriales para posts.
 *
 * Reutiliza exclusivamente datos y conexiones ya existentes en el plugin:
 * - Google Intelligence / Search Console (datos sincronizados localmente).
 * - Google Trends (tabla/módulo seo-google-trends.php).
 * - Catálogo WooCommerce y jerarquía seo_relations.
 * - Posts reales de WordPress.
 *
 * No crea nuevas credenciales ni conexiones externas.
 */

defined('ABSPATH') || exit;

if (!defined('SEO_POST_OPPORTUNITIES_VERSION')) {
    define('SEO_POST_OPPORTUNITIES_VERSION', '1.2.0');
}
if (!defined('SEO_POST_OPPORTUNITIES_SCHEMA_VERSION')) {
    define('SEO_POST_OPPORTUNITIES_SCHEMA_VERSION', '1.1');
}

add_action('admin_post_seo_post_opportunities_export', 'seo_post_opportunities_export_handler');
add_action('wp_ajax_seo_post_opportunities_post_detail', 'seo_post_opportunities_post_detail_handler');

function seo_post_opportunities_normalize($text)
{
    if (function_exists('seo_google_trends_normalize')) {
        return (string) seo_google_trends_normalize($text);
    }

    $text = remove_accents(wp_strip_all_tags((string) $text));
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/i', ' ', $text);
    return trim(preg_replace('/\s+/', ' ', $text));
}

function seo_post_opportunities_tokens($text)
{
    static $stopwords = array(
        'a','al','algo','ante','bajo','cada','como','con','contra','cual','cuando','de','del','desde','donde',
        'el','ella','en','entre','es','esta','este','esto','la','las','lo','los','mas','me','mi','o','para','pero',
        'por','que','se','sin','sobre','su','sus','tu','un','una','unas','uno','unos','y','ya','the','and','for','with',
    );

    $normalized = seo_post_opportunities_normalize($text);
    if ('' === $normalized) {
        return array();
    }

    $out = array();
    foreach (preg_split('/\s+/', $normalized) as $token) {
        if (strlen($token) < 2 || in_array($token, $stopwords, true)) {
            continue;
        }
        $out[$token] = true;
    }
    return array_keys($out);
}

function seo_post_opportunities_generic_tokens()
{
    return array(
        'accesorio','accesorios','equipo','equipos','herramienta','herramientas','maquina','maquinas','maquinaria',
        'producto','productos','profesional','profesionales','industrial','industriales','material','materiales',
        'tienda','venta','online','distribuidor','distribucion','proveedor','empresa','empresas','sistema','sistemas',
        'automocion','vehiculo','vehiculos','coche','coches','taller','bricolaje'
    );
}

function seo_post_opportunities_token_root($token)
{
    $token = (string)$token;
    $len = strlen($token);
    if ($len > 6 && substr($token, -2) === 'es') {
        return substr($token, 0, -2);
    }
    if ($len > 4 && substr($token, -1) === 's') {
        return substr($token, 0, -1);
    }
    return $token;
}

function seo_post_opportunities_anchor_tokens($text)
{
    $tokens = array_values(array_diff(
        seo_post_opportunities_tokens($text),
        seo_post_opportunities_generic_tokens()
    ));
    $roots = array();
    foreach ($tokens as $token) {
        $root = seo_post_opportunities_token_root($token);
        if ('' !== $root) $roots[$root] = true;
    }
    return array_keys($roots);
}

function seo_post_opportunities_similarity($left, $right)
{
    $a = seo_post_opportunities_normalize($left);
    $b = seo_post_opportunities_normalize($right);

    if ('' === $a || '' === $b) {
        return 0.0;
    }
    if ($a === $b) {
        return 1.0;
    }

    $ta = seo_post_opportunities_tokens($a);
    $tb = seo_post_opportunities_tokens($b);
    if (!$ta || !$tb) {
        return 0.0;
    }

    $shared = count(array_intersect($ta, $tb));
    if (!$shared) {
        return 0.0;
    }

    $union = count(array_unique(array_merge($ta, $tb)));
    $jaccard = $union ? $shared / $union : 0;
    $coverage_a = $shared / count($ta);
    $coverage_b = $shared / count($tb);
    $balanced_coverage = min($coverage_a, $coverage_b);
    similar_text($a, $b, $sequence_percent);
    $sequence = max(0, min(1, ((float) $sequence_percent) / 100));

    $score = ($jaccard * 0.58) + ($balanced_coverage * 0.27) + ($sequence * 0.15);

    $shared_anchors = count(array_intersect(
        seo_post_opportunities_anchor_tokens($a),
        seo_post_opportunities_anchor_tokens($b)
    ));

    if (0 === $shared_anchors && $shared < 3) {
        $score = min($score, 0.48);
    }

    $smaller = count($ta) <= count($tb) ? $ta : $tb;
    $larger  = count($ta) <= count($tb) ? $tb : $ta;
    $contained = count($smaller) >= 3 && !array_diff($smaller, $larger);
    if ($contained && $shared_anchors >= 1) {
        $score = max($score, 0.86);
    }

    return round(min(1, $score), 4);
}

function seo_post_opportunities_meta_text($post_id, $keys)
{
    $parts = array();
    foreach ((array) $keys as $key) {
        $value = get_post_meta($post_id, $key, true);
        $value = maybe_unserialize($value);
        if (is_array($value)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($value));
            foreach ($iterator as $item) {
                if (is_scalar($item) && '' !== trim((string) $item)) {
                    $parts[] = trim((string) $item);
                }
            }
        } elseif (is_scalar($value) && '' !== trim((string) $value)) {
            $parts[] = trim((string) $value);
        }
    }
    return implode(' ', array_unique($parts));
}

function seo_post_opportunities_clean_keyword($value, $fallback = '')
{
    $value = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $value)));
    if ('' === $value || strlen($value) > 140 || preg_match('#https?://|<[^>]+>#i', (string) $value)) {
        $value = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $fallback)));
    }
    return $value;
}

function seo_post_opportunities_get_posts()
{
    static $cache = null;
    if (null !== $cache) {
        return $cache;
    }

    $posts = get_posts(array(
        'post_type'              => 'post',
        'post_status'            => array('publish', 'future', 'draft', 'pending', 'private'),
        'numberposts'            => -1,
        'orderby'                => 'date',
        'order'                  => 'DESC',
        'suppress_filters'       => false,
        'update_post_meta_cache' => true,
        'update_post_term_cache' => true,
    ));

    $out = array();
    foreach ($posts as $post) {
        $title = trim((string) $post->post_title);
        if ('' === $title) {
            continue;
        }

        $categories = wp_get_post_terms($post->ID, 'category', array('fields' => 'names'));
        $tags = wp_get_post_terms($post->ID, 'post_tag', array('fields' => 'names'));
        $categories = is_wp_error($categories) ? array() : $categories;
        $tags = is_wp_error($tags) ? array() : $tags;

        $focus = seo_post_opportunities_clean_keyword(
            seo_post_opportunities_meta_text($post->ID, array(
                'rank_math_focus_keyword',
                '_yoast_wpseo_focuskw',
            )),
            $title
        );

        $content_plain = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $post->post_content)));
        $content_key = seo_post_opportunities_normalize($content_plain);

        $search_text = implode(' ', array_filter(array(
            $title,
            (string) $post->post_name,
            $focus,
            implode(' ', $categories),
            implode(' ', $tags),
        )));

        $out[] = array(
            'id'           => (int) $post->ID,
            'title'        => $title,
            'slug'         => (string) $post->post_name,
            'status'       => (string) $post->post_status,
            'url'          => (string) get_permalink($post->ID),
            'date'         => (string) $post->post_date,
            'modified'     => (string) $post->post_modified,
            'categories'   => array_values($categories),
            'tags'         => array_values($tags),
            'focus_keyword'=> $focus,
            'search_text'  => $search_text,
            'content_hash' => strlen($content_key) >= 120 ? md5($content_key) : '',
        );
    }

    $cache = $out;
    return $cache;
}

function seo_post_opportunities_intent($query)
{
    $n = ' ' . seo_post_opportunities_normalize($query) . ' ';

    $groups = array(
        'comparacion' => array(' vs ', ' versus ', ' diferencia ', ' diferencias ', ' comparar ', ' comparativa ', ' mejor ', ' cual elegir ', ' elegir '),
        'problema_solucion' => array(' como ', ' reparar ', ' arreglar ', ' instalar ', ' conectar ', ' cortar ', ' limpiar ', ' quitar ', ' evitar ', ' error ', ' errores ', ' problema ', ' fallo ', ' mantenimiento ', ' calibrar ', ' ajustar ', ' detectar '),
        'guia' => array(' guia ', ' tipos ', ' que es ', ' para que sirve ', ' cuanto ', ' cuantos ', ' por que ', ' cuando ', ' medidas ', ' seguridad ', ' usar ', ' uso ', ' consejos '),
        'actualidad_normativa' => array(' normativa ', ' obligatorio ', ' obligatoria ', ' ley ', ' homologacion ', ' homologado ', ' nueva ', ' nuevo ', ' lanzamiento ', ' regulacion '),
    );

    foreach ($groups as $label => $needles) {
        foreach ($needles as $needle) {
            if (false !== strpos($n, $needle)) {
                return array('label' => $label, 'score' => 1.0);
            }
        }
    }

    $commercial = array(
        ' comprar ', ' precio ', ' precios ', ' oferta ', ' ofertas ', ' tienda ', ' barato ', ' barata ',
        ' distribuidor ', ' proveedor ', ' venta online ', ' para empresas '
    );
    foreach ($commercial as $needle) {
        if (false !== strpos($n, $needle)) {
            return array('label' => 'comercial_catalogo', 'score' => 0.20);
        }
    }
    // Localidad/provincia explicita: normalmente pide proveedor o disponibilidad local.
    // No usamos un simple \"en X\" porque generaria falsos positivos como \"rampas de carga en aluminio\".
    $geo_terms = array('asturias','canarias','madrid','barcelona','valencia','sevilla','alicante','malaga','murcia','zaragoza','bilbao','llanera');
    $tokens = seo_post_opportunities_tokens($query);
    if (array_intersect($tokens, $geo_terms)) {
        return array('label' => 'local_catalogo', 'score' => 0.20);
    }

    $token_count = count($tokens);
    if ($token_count >= 4) {
        return array('label' => 'informativa_long_tail', 'score' => 0.68);
    }
    if (3 === $token_count) {
        // Puede convertirse en una guia editorial si hay contexto de catalogo suficiente.
        return array('label' => 'tema_guia', 'score' => 0.48);
    }

    return array('label' => 'indeterminada', 'score' => 0.15);
}

function seo_post_opportunities_table_exists($table)
{
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
}

function seo_post_opportunities_catalog_index()
{
    static $cache = null;
    if (null !== $cache) {
        return $cache;
    }

    $cache = array();
    if (!taxonomy_exists('product_cat')) {
        return $cache;
    }

    global $wpdb;
    $rel_table = $wpdb->prefix . 'seo_relations';
    $category_to_secondary = array();
    $secondary_to_primary = array();
    $primary_to_cluster = array();

    if (seo_post_opportunities_table_exists($rel_table)) {
        $rows = $wpdb->get_results(
            "SELECT source_id, target_id, relation_type
             FROM {$rel_table}
             WHERE relation_type IN ('hub_secondary_to_category','hub_primary_to_hub_secondary','cluster_to_primary')",
            ARRAY_A
        );

        foreach ((array) $rows as $row) {
            $source = absint($row['source_id']);
            $target = absint($row['target_id']);
            if ('hub_secondary_to_category' === $row['relation_type']) {
                $category_to_secondary[$target] = $source;
            } elseif ('hub_primary_to_hub_secondary' === $row['relation_type']) {
                $secondary_to_primary[$target] = $source;
            } elseif ('cluster_to_primary' === $row['relation_type']) {
                $primary_to_cluster[$target] = $source;
            }
        }
    }

    $terms = get_terms(array(
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
    ));
    if (is_wp_error($terms)) {
        return $cache;
    }

    foreach ($terms as $term) {
        $term_id = (int) $term->term_id;
        $secondary_id = isset($category_to_secondary[$term_id]) ? absint($category_to_secondary[$term_id]) : 0;
        $primary_id = $secondary_id && isset($secondary_to_primary[$secondary_id]) ? absint($secondary_to_primary[$secondary_id]) : 0;
        $cluster_id = $primary_id && isset($primary_to_cluster[$primary_id]) ? absint($primary_to_cluster[$primary_id]) : 0;
        $link = get_term_link($term);

        $cache[$term_id] = array(
            'term_id'        => $term_id,
            'category'       => (string) $term->name,
            'category_slug'  => (string) $term->slug,
            'category_url'   => is_wp_error($link) ? '' : (string) $link,
            'product_count'  => (int) $term->count,
            'hub_secondary_id' => $secondary_id,
            'hub_secondary'  => $secondary_id ? (string) get_the_title($secondary_id) : '',
            'hub_primary_id' => $primary_id,
            'hub_primary'    => $primary_id ? (string) get_the_title($primary_id) : '',
            'cluster_id'     => $cluster_id,
            'cluster'        => $cluster_id ? (string) get_the_title($cluster_id) : '',
            'search_text'    => implode(' ', array_filter(array(
                (string) $term->name,
                (string) $term->slug,
                $secondary_id ? (string) get_the_title($secondary_id) : '',
                $primary_id ? (string) get_the_title($primary_id) : '',
                $cluster_id ? (string) get_the_title($cluster_id) : '',
            ))),
        );
    }

    return $cache;
}

function seo_post_opportunities_context_from_term($term_id, $score = 1.0)
{
    $index = seo_post_opportunities_catalog_index();
    $term_id = absint($term_id);
    if (!$term_id || empty($index[$term_id])) {
        return array();
    }
    $context = $index[$term_id];
    $context['match_score'] = round((float) $score, 4);
    return $context;
}

function seo_post_opportunities_catalog_context($query, $seeds = array(), $top_page = '')
{
    if ($top_page && taxonomy_exists('product_cat')) {
        $path = trim((string) wp_parse_url($top_page, PHP_URL_PATH), '/');
        $parts = $path ? explode('/', $path) : array();
        $slug = $parts ? sanitize_title(end($parts)) : '';
        if ($slug) {
            $term = get_term_by('slug', $slug, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $exact = seo_post_opportunities_context_from_term((int)$term->term_id, 1.0);
                if ($exact) {
                    return $exact;
                }
            }
        }
    }

    if ($top_page && function_exists('seo_google_demand_category_for_page')) {
        $mapped = seo_google_demand_category_for_page($top_page);
        if (!empty($mapped['term_id'])) {
            return seo_post_opportunities_context_from_term($mapped['term_id'], 1.0);
        }
    }

    $index = seo_post_opportunities_catalog_index();
    if (!$index) {
        return array();
    }

    $best = array();
    $best_score = 0.0;
    $texts = array_merge(array($query), array_filter(array_map('strval', (array) $seeds)));

    foreach ($index as $context) {
        $score = 0.0;
        foreach ($texts as $i => $text) {
            $candidate_score = seo_post_opportunities_similarity($text, $context['search_text']);
            if ($i > 0) {
                $candidate_score = min(1, $candidate_score + 0.08); // La semilla Trends ya nace del catálogo.
            }
            $score = max($score, $candidate_score);
        }
        if ($score > $best_score) {
            $best_score = $score;
            $best = $context;
        }
    }

    if ($best_score < 0.34) {
        return array();
    }

    $best['match_score'] = round($best_score, 4);
    return $best;
}

function seo_post_opportunities_related_products($term_id, $limit = 3)
{
    $term_id = absint($term_id);
    if (!$term_id || !post_type_exists('product')) {
        return array();
    }

    $ids = get_posts(array(
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'posts_per_page' => max(1, min(5, absint($limit))),
        'orderby'        => 'modified',
        'order'          => 'DESC',
        'tax_query'      => array(array(
            'taxonomy' => 'product_cat',
            'field'    => 'term_id',
            'terms'    => array($term_id),
        )),
    ));

    $out = array();
    foreach ((array) $ids as $id) {
        $out[] = array(
            'id'    => (int) $id,
            'title' => (string) get_the_title($id),
            'url'   => (string) get_permalink($id),
        );
    }
    return $out;
}

function seo_post_opportunities_best_post_match($query, array $posts)
{
    $best = array();
    $strong = array();

    foreach ($posts as $post) {
        $score_title = seo_post_opportunities_similarity($query, $post['title']);
        $score_focus = !empty($post['focus_keyword'])
            ? seo_post_opportunities_similarity($query, $post['focus_keyword'])
            : 0.0;

        $context_text = implode(' ', array_merge(
            (array) ($post['categories'] ?? array()),
            (array) ($post['tags'] ?? array())
        ));
        $score_context = $context_text
            ? min(0.55, seo_post_opportunities_similarity($query, $context_text))
            : 0.0;

        $score = max($score_title, $score_focus, $score_context);

        // Consultas muy cortas de entidad (p. ej. "baliza seguridad") no deben
        // disparar una URL nueva si ya hay posts que cubren esa entidad.
        $query_anchors = seo_post_opportunities_anchor_tokens($query);
        $title_anchors = seo_post_opportunities_anchor_tokens($post['title']);
        $shared_anchors = count(array_intersect($query_anchors, $title_anchors));
        if (count($query_anchors) >= 1 && count($query_anchors) <= 2 && $shared_anchors >= 1) {
            $score = max($score, 0.80);
        }

        if ($score >= 0.80) {
            $strong[] = array('post' => $post, 'similarity' => $score);
        }
        if (!$best || $score > $best['similarity']) {
            $best = array('post' => $post, 'similarity' => $score);
        }
    }

    usort($strong, function ($a, $b) {
        return $b['similarity'] <=> $a['similarity'];
    });

    return array(
        'best'   => $best,
        'strong' => $strong,
    );
}

function seo_post_opportunities_gsc_data($days = 60)
{
    $out = array(
        'connected' => false,
        'property_id' => '',
        'period' => null,
        'rows' => array(),
        'latest' => '',
    );

    if (!function_exists('seo_google_get_settings') || !function_exists('seo_google_get_analysis_period') || !function_exists('seo_google_get_signal_queries')) {
        return $out;
    }

    $settings = seo_google_get_settings();
    $property_id = trim((string) ($settings['property_id'] ?? ''));
    if ('' === $property_id) {
        return $out;
    }

    $period = seo_google_get_analysis_period($property_id, $days);
    if (!$period) {
        return $out;
    }

    $out['connected'] = true;
    $out['property_id'] = $property_id;
    $out['period'] = $period;
    $out['latest'] = function_exists('seo_google_latest_data_date') ? (string) seo_google_latest_data_date($property_id) : '';
    $out['rows'] = (array) seo_google_get_signal_queries(
        $property_id,
        $period['current_from'],
        $period['current_to'],
        250,
        2,
        ''
    );

    return $out;
}

function seo_post_opportunities_path_key($url_or_path)
{
    if (function_exists('seo_landing_google_path_key')) {
        return (string) seo_landing_google_path_key($url_or_path);
    }
    $path = (string) wp_parse_url((string) $url_or_path, PHP_URL_PATH);
    if ('' === $path) {
        $path = (string) $url_or_path;
    }
    $path = '/' . ltrim($path, '/');
    return untrailingslashit($path) ?: '/';
}

function seo_post_opportunities_published_path_map(array $posts)
{
    $map = array();
    foreach ($posts as $post) {
        if ('publish' !== ($post['status'] ?? '')) {
            continue;
        }
        $path = seo_post_opportunities_path_key($post['url'] ?? '');
        if ($path) {
            $map[$path] = (int) $post['id'];
        }
    }
    return $map;
}

function seo_post_opportunities_load_analytics_service()
{
    if (function_exists('seo_google_analytics_run_report')) {
        return true;
    }
    if (function_exists('seo_landing_google_load_analytics_service')) {
        $state = seo_landing_google_load_analytics_service();
        return !empty($state['loaded']) && function_exists('seo_google_analytics_run_report');
    }
    return false;
}

function seo_post_opportunities_ga4_performance(array $posts, $days = 60)
{
    $days = in_array((int) $days, array(28, 60, 90), true) ? (int) $days : 60;
    $empty = array(
        'available'=>false,
        'error'=>'',
        'daily'=>array(),
        'posts'=>array(),
        'post_daily'=>array(),
        'summary'=>array('sessions'=>0,'users'=>0,'pageviews'=>0),
    );

    if (!seo_post_opportunities_load_analytics_service()) {
        $empty['error'] = 'Analytics Data API no está disponible con la conexión actual.';
        return $empty;
    }

    $cache_key = 'seo_post_opp_ga4_v2_' . get_current_blog_id() . '_' . $days;
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }

    $report = seo_google_analytics_run_report(array(
        'dateRanges'=>array(array('startDate'=>max(1,$days-1).'daysAgo','endDate'=>'today')),
        'dimensions'=>array(array('name'=>'date'),array('name'=>'pagePath')),
        'metrics'=>array(array('name'=>'sessions'),array('name'=>'activeUsers'),array('name'=>'screenPageViews')),
        'limit'=>100000,
    ));

    if (is_wp_error($report)) {
        $empty['error'] = $report->get_error_message();
        set_transient($cache_key, $empty, 5 * MINUTE_IN_SECONDS);
        return $empty;
    }

    $paths = seo_post_opportunities_published_path_map($posts);
    $post_index = array();
    foreach ($posts as $post) {
        $post_index[(int)$post['id']] = $post;
    }

    $daily = array();
    $post_rows = array();
    $post_daily = array();

    foreach ((array)($report['rows'] ?? array()) as $row) {
        $date_raw = (string)($row['dimensionValues'][0]['value'] ?? '');
        $path = seo_post_opportunities_path_key($row['dimensionValues'][1]['value'] ?? '');
        if (!$path || empty($paths[$path])) {
            continue;
        }
        $post_id = (int)$paths[$path];
        $date = preg_match('/^\d{8}$/', $date_raw)
            ? substr($date_raw,0,4).'-'.substr($date_raw,4,2).'-'.substr($date_raw,6,2)
            : $date_raw;
        $sessions = (float)($row['metricValues'][0]['value'] ?? 0);
        $users = (float)($row['metricValues'][1]['value'] ?? 0);
        $pageviews = (float)($row['metricValues'][2]['value'] ?? 0);

        if (!isset($daily[$date])) {
            $daily[$date] = array('date'=>$date,'sessions'=>0,'users'=>0,'pageviews'=>0);
        }
        $daily[$date]['sessions'] += $sessions;
        $daily[$date]['users'] += $users;
        $daily[$date]['pageviews'] += $pageviews;

        if (!isset($post_rows[$post_id])) {
            $post_rows[$post_id] = array(
                'post_id'=>$post_id,
                'title'=>(string)($post_index[$post_id]['title'] ?? ''),
                'url'=>(string)($post_index[$post_id]['url'] ?? ''),
                'sessions'=>0,'users'=>0,'pageviews'=>0,
            );
        }
        $post_rows[$post_id]['sessions'] += $sessions;
        $post_rows[$post_id]['users'] += $users;
        $post_rows[$post_id]['pageviews'] += $pageviews;

        if (!isset($post_daily[$post_id][$date])) {
            $post_daily[$post_id][$date] = array('date'=>$date,'sessions'=>0,'users'=>0,'pageviews'=>0);
        }
        $post_daily[$post_id][$date]['sessions'] += $sessions;
        $post_daily[$post_id][$date]['users'] += $users;
        $post_daily[$post_id][$date]['pageviews'] += $pageviews;
    }

    ksort($daily);
    foreach ($post_daily as &$rows) {
        ksort($rows);
        $rows = array_values($rows);
    }
    unset($rows);

    $summary = array('sessions'=>0,'users'=>0,'pageviews'=>0);
    foreach ($daily as $row) {
        $summary['sessions'] += $row['sessions'];
        $summary['users'] += $row['users'];
        $summary['pageviews'] += $row['pageviews'];
    }

    $out = array(
        'available'=>true,
        'error'=>'',
        'daily'=>array_values($daily),
        'posts'=>array_values($post_rows),
        'post_daily'=>$post_daily,
        'summary'=>$summary,
    );
    set_transient($cache_key, $out, 10 * MINUTE_IN_SECONDS);
    return $out;
}

function seo_post_opportunities_gsc_url_hashes($url)
{
    $urls = array_unique(array_filter(array(
        (string)$url,
        trailingslashit((string)$url),
        untrailingslashit((string)$url),
    )));
    return array_values(array_unique(array_map(function($candidate) {
        return hash('sha256', $candidate);
    }, $urls)));
}

function seo_post_opportunities_gsc_performance(array $posts, $days = 60)
{
    global $wpdb;
    $gsc = seo_post_opportunities_gsc_data($days);
    $empty = array(
        'available'=>false,
        'error'=>'',
        'daily'=>array(),
        'posts'=>array(),
        'post_daily'=>array(),
        'summary'=>array('clicks'=>0,'impressions'=>0,'ctr'=>0,'position'=>0),
    );

    if (empty($gsc['connected']) || empty($gsc['period']) || !function_exists('seo_google_table')) {
        return $empty;
    }

    $table = seo_google_table('search_data');
    if (function_exists('seo_google_table_exists') && !seo_google_table_exists($table)) {
        $empty['error'] = 'No existe la tabla local de Search Console.';
        return $empty;
    }

    $hash_to_post = array();
    $post_index = array();
    foreach ($posts as $post) {
        if ('publish' !== ($post['status'] ?? '')) {
            continue;
        }
        $post_id = (int)$post['id'];
        $post_index[$post_id] = $post;
        foreach (seo_post_opportunities_gsc_url_hashes($post['url'] ?? '') as $hash) {
            $hash_to_post[$hash] = $post_id;
        }
    }

    if (!$hash_to_post) {
        return $empty;
    }

    $property_hash = hash('sha256', (string)$gsc['property_id']);
    $from = (string)$gsc['period']['current_from'];
    $to = (string)$gsc['period']['current_to'];
    $daily = array();
    $post_rows = array();
    $post_daily = array();

    foreach (array_chunk(array_keys($hash_to_post), 180) as $hashes) {
        $placeholders = implode(',', array_fill(0, count($hashes), '%s'));
        $sql = $wpdb->prepare(
            "SELECT data_date,page_hash,SUM(clicks) clicks,SUM(impressions) impressions,COUNT(DISTINCT query_hash) queries,CASE WHEN SUM(impressions)>0 THEN SUM(position*impressions)/SUM(impressions) ELSE 0 END position FROM {$table} WHERE property_hash=%s AND data_date BETWEEN %s AND %s AND page_hash IN ({$placeholders}) GROUP BY data_date,page_hash ORDER BY data_date ASC",
            array_merge(array($property_hash,$from,$to),$hashes)
        );
        foreach ((array)$wpdb->get_results($sql, ARRAY_A) as $row) {
            $post_id = (int)($hash_to_post[$row['page_hash']] ?? 0);
            if (!$post_id) {
                continue;
            }
            $date = (string)$row['data_date'];
            $clicks = (float)$row['clicks'];
            $impressions = (float)$row['impressions'];
            $position = (float)$row['position'];

            if (!isset($daily[$date])) {
                $daily[$date] = array('date'=>$date,'clicks'=>0,'impressions'=>0,'weighted'=>0);
            }
            $daily[$date]['clicks'] += $clicks;
            $daily[$date]['impressions'] += $impressions;
            $daily[$date]['weighted'] += $position * $impressions;

            if (!isset($post_rows[$post_id])) {
                $post_rows[$post_id] = array(
                    'post_id'=>$post_id,
                    'title'=>(string)($post_index[$post_id]['title'] ?? ''),
                    'url'=>(string)($post_index[$post_id]['url'] ?? ''),
                    'clicks'=>0,'impressions'=>0,'weighted'=>0,'queries'=>0,
                );
            }
            $post_rows[$post_id]['clicks'] += $clicks;
            $post_rows[$post_id]['impressions'] += $impressions;
            $post_rows[$post_id]['weighted'] += $position * $impressions;
            $post_rows[$post_id]['queries'] += (int)$row['queries'];

            if (!isset($post_daily[$post_id][$date])) {
                $post_daily[$post_id][$date] = array('date'=>$date,'clicks'=>0,'impressions'=>0,'weighted'=>0);
            }
            $post_daily[$post_id][$date]['clicks'] += $clicks;
            $post_daily[$post_id][$date]['impressions'] += $impressions;
            $post_daily[$post_id][$date]['weighted'] += $position * $impressions;
        }
    }

    $finalize = function($row) {
        $impressions = (float)($row['impressions'] ?? 0);
        $row['position'] = $impressions > 0 ? (float)$row['weighted'] / $impressions : 0;
        $row['ctr'] = $impressions > 0 ? (float)$row['clicks'] / $impressions : 0;
        unset($row['weighted']);
        return $row;
    };

    ksort($daily);
    $daily = array_map($finalize, array_values($daily));
    foreach ($post_rows as $id=>$row) {
        $post_rows[$id] = $finalize($row);
    }
    foreach ($post_daily as &$rows) {
        ksort($rows);
        $rows = array_map($finalize, array_values($rows));
    }
    unset($rows);

    $summary = array('clicks'=>0,'impressions'=>0,'ctr'=>0,'position'=>0);
    $weighted = 0;
    foreach ($daily as $row) {
        $summary['clicks'] += $row['clicks'];
        $summary['impressions'] += $row['impressions'];
        $weighted += $row['position'] * $row['impressions'];
    }
    if ($summary['impressions'] > 0) {
        $summary['ctr'] = $summary['clicks'] / $summary['impressions'];
        $summary['position'] = $weighted / $summary['impressions'];
    }

    return array(
        'available'=>true,
        'error'=>'',
        'daily'=>$daily,
        'posts'=>array_values($post_rows),
        'post_daily'=>$post_daily,
        'summary'=>$summary,
    );
}

function seo_post_opportunities_performance(array $posts, $days = 60)
{
    $ga4 = seo_post_opportunities_ga4_performance($posts, $days);
    $gsc = seo_post_opportunities_gsc_performance($posts, $days);
    $rows = array();

    foreach ($posts as $post) {
        if ('publish' !== ($post['status'] ?? '')) {
            continue;
        }
        $id = (int)$post['id'];
        $rows[$id] = array(
            'post_id'=>$id,'title'=>$post['title'],'url'=>$post['url'],'date'=>$post['date'],
            'sessions'=>0,'users'=>0,'pageviews'=>0,'clicks'=>0,'impressions'=>0,'ctr'=>0,'position'=>0,'queries'=>0,
        );
    }
    foreach ((array)$ga4['posts'] as $row) {
        $id = (int)$row['post_id'];
        if (!isset($rows[$id])) continue;
        foreach (array('sessions','users','pageviews') as $key) {
            $rows[$id][$key] = (float)($row[$key] ?? 0);
        }
    }
    foreach ((array)$gsc['posts'] as $row) {
        $id = (int)$row['post_id'];
        if (!isset($rows[$id])) continue;
        foreach (array('clicks','impressions','ctr','position','queries') as $key) {
            $rows[$id][$key] = (float)($row[$key] ?? 0);
        }
    }

    $rows = array_values(array_filter($rows,function($row) {
        return $row['pageviews'] > 0 || $row['sessions'] > 0 || $row['impressions'] > 0 || $row['clicks'] > 0;
    }));
    usort($rows,function($a,$b) use ($ga4) {
        $left = !empty($ga4['available']) ? $a['pageviews'] : $a['clicks'];
        $right = !empty($ga4['available']) ? $b['pageviews'] : $b['clicks'];
        return $left == $right ? $b['impressions'] <=> $a['impressions'] : $right <=> $left;
    });

    return array('ga4'=>$ga4,'gsc'=>$gsc,'rows'=>$rows);
}

function seo_post_opportunities_top_queries_for_post($post_id, $days = 60, $limit = 10)
{
    global $wpdb;
    $gsc = seo_post_opportunities_gsc_data($days);
    if (empty($gsc['connected']) || empty($gsc['period']) || !function_exists('seo_google_table')) {
        return array();
    }
    $url = get_permalink(absint($post_id));
    if (!$url) {
        return array();
    }
    $hashes = seo_post_opportunities_gsc_url_hashes($url);
    $placeholders = implode(',',array_fill(0,count($hashes),'%s'));
    $table = seo_google_table('search_data');
    $sql = $wpdb->prepare(
        "SELECT MAX(query_text) query_text,SUM(clicks) clicks,SUM(impressions) impressions,CASE WHEN SUM(impressions)>0 THEN SUM(position*impressions)/SUM(impressions) ELSE 0 END position FROM {$table} WHERE property_hash=%s AND data_date BETWEEN %s AND %s AND page_hash IN ({$placeholders}) GROUP BY query_hash ORDER BY impressions DESC LIMIT %d",
        array_merge(
            array(hash('sha256',(string)$gsc['property_id']),$gsc['period']['current_from'],$gsc['period']['current_to']),
            $hashes,
            array(max(1,min(25,absint($limit))))
        )
    );
    return (array)$wpdb->get_results($sql,ARRAY_A);
}

function seo_post_opportunities_post_detail_handler()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message'=>'Sin permisos.'),403);
    }
    check_ajax_referer('seo_post_opportunities_detail','nonce');

    $post_id = absint($_POST['post_id'] ?? 0);
    $days = absint($_POST['days'] ?? 60);
    $days = in_array($days,array(28,60,90),true) ? $days : 60;
    if (!$post_id || 'post' !== get_post_type($post_id)) {
        wp_send_json_error(array('message'=>'Post no válido.'),400);
    }

    $performance = seo_post_opportunities_performance(seo_post_opportunities_get_posts(),$days);
    $ga4_daily = (array)($performance['ga4']['post_daily'][$post_id] ?? array());
    $gsc_daily = (array)($performance['gsc']['post_daily'][$post_id] ?? array());
    $daily = array();

    foreach ($ga4_daily as $row) {
        $date = (string)$row['date'];
        $daily[$date] = array_merge(array('date'=>$date,'sessions'=>0,'users'=>0,'pageviews'=>0,'clicks'=>0,'impressions'=>0,'position'=>0),$row);
    }
    foreach ($gsc_daily as $row) {
        $date = (string)$row['date'];
        if (!isset($daily[$date])) {
            $daily[$date] = array('date'=>$date,'sessions'=>0,'users'=>0,'pageviews'=>0,'clicks'=>0,'impressions'=>0,'position'=>0);
        }
        foreach (array('clicks','impressions','position') as $key) {
            $daily[$date][$key] = (float)($row[$key] ?? 0);
        }
    }
    ksort($daily);

    $summary = array('sessions'=>0,'users'=>0,'pageviews'=>0,'clicks'=>0,'impressions'=>0,'ctr'=>0,'position'=>0);
    foreach ($performance['rows'] as $row) {
        if ((int)$row['post_id'] === $post_id) {
            $summary = array_merge($summary,$row);
            break;
        }
    }

    wp_send_json_success(array(
        'post'=>array('id'=>$post_id,'title'=>(string)get_the_title($post_id),'url'=>(string)get_permalink($post_id)),
        'summary'=>$summary,
        'daily'=>array_values($daily),
        'top_queries'=>seo_post_opportunities_top_queries_for_post($post_id,$days,10),
        'sources'=>array('analytics'=>!empty($performance['ga4']['available']),'search_console'=>!empty($performance['gsc']['available'])),
    ));
}

function seo_post_opportunities_merge_external_candidates(array $posts, $days = 60)
{
    $candidates = array();

    $trends = function_exists('seo_google_trends_market_summary')
        ? (array) seo_google_trends_market_summary(500)
        : array();

    foreach ($trends as $trend) {
        $query = trim((string) ($trend['query'] ?? ''));
        if ('' === $query) {
            continue;
        }
        $intent = seo_post_opportunities_intent($query);
        if (in_array((string)$intent['label'], array('comercial_catalogo','local_catalogo','indeterminada'), true)) {
            continue;
        }

        $context = seo_post_opportunities_catalog_context($query, (array) ($trend['seeds'] ?? array()), '');
        if (!$context) {
            continue;
        }

        $key = seo_post_opportunities_normalize($query);
        $candidates[$key] = array(
            'query'   => $query,
            'intent'  => $intent,
            'context' => $context,
            'trends'  => array(
                'score'      => round((float) ($trend['score'] ?? 0), 2),
                'growth'     => round((float) ($trend['max_growth'] ?? 0), 2),
                'breakout'   => !empty($trend['breakout']),
                'seeds'      => array_values((array) ($trend['seeds'] ?? array())),
                'types'      => array_values((array) ($trend['types'] ?? array())),
            ),
            'gsc' => array(),
        );
    }

    $gsc = seo_post_opportunities_gsc_data($days);
    foreach ((array) $gsc['rows'] as $row) {
        $query = trim((string) ($row['label'] ?? ''));
        if ('' === $query) {
            continue;
        }
        $intent = seo_post_opportunities_intent($query);
        if (in_array((string)$intent['label'], array('comercial_catalogo','local_catalogo','indeterminada'), true)) {
            continue;
        }
        $anchors = seo_post_opportunities_anchor_tokens($query);
        $normalized_query = ' ' . seo_post_opportunities_normalize($query) . ' ';
        $explicit_info = (bool)preg_match('/\b(como|que es|por que|para que|vs|versus|diferencia|errores?|fallos?|detectar|reparar|arreglar|calibrar)\b/i', $normalized_query);
        if (count($anchors) < 2 && !$explicit_info) {
            continue;
        }

        $top_page = (string) ($row['top_page'] ?? '');
        $context = seo_post_opportunities_catalog_context($query, array(), $top_page);
        if (!$context) {
            continue;
        }

        $key = seo_post_opportunities_normalize($query);
        $gsc_signal = array(
            'impressions' => round((float) ($row['impressions'] ?? 0), 2),
            'clicks'      => round((float) ($row['clicks'] ?? 0), 2),
            'ctr'         => round((float) ($row['ctr'] ?? 0), 6),
            'position'    => round((float) ($row['position'] ?? 0), 2),
            'pages'       => absint($row['pages'] ?? 0),
            'top_page'    => $top_page,
        );

        if (isset($candidates[$key])) {
            $candidates[$key]['gsc'] = $gsc_signal;
            if ((float) ($context['match_score'] ?? 0) > (float) ($candidates[$key]['context']['match_score'] ?? 0)) {
                $candidates[$key]['context'] = $context;
            }
            continue;
        }

        $candidates[$key] = array(
            'query'   => $query,
            'intent'  => $intent,
            'context' => $context,
            'trends'  => array(),
            'gsc'     => $gsc_signal,
        );
    }

    // Agrupa variantes muy próximas para no proponer posts que se canibalicen entre sí.
    $merged = array();
    foreach (array_values($candidates) as $candidate) {
        $merged_index = null;
        foreach ($merged as $index => $previous) {
            if (seo_post_opportunities_similarity($candidate['query'], $previous['query']) >= 0.90) {
                $merged_index = $index;
                break;
            }
        }
        if (null === $merged_index) {
            $candidate['variants'] = array($candidate['query']);
            $merged[] = $candidate;
            continue;
        }

        $previous = $merged[$merged_index];
        $previous['variants'][] = $candidate['query'];
        $previous['variants'] = array_values(array_unique($previous['variants']));

        if (!empty($candidate['trends']) && (empty($previous['trends']) || (float) $candidate['trends']['score'] > (float) $previous['trends']['score'])) {
            $previous['trends'] = $candidate['trends'];
        }
        if (!empty($candidate['gsc']) && (empty($previous['gsc']) || (float) $candidate['gsc']['impressions'] > (float) $previous['gsc']['impressions'])) {
            $previous['gsc'] = $candidate['gsc'];
        }
        if ((float) ($candidate['context']['match_score'] ?? 0) > (float) ($previous['context']['match_score'] ?? 0)) {
            $previous['context'] = $candidate['context'];
        }
        $merged[$merged_index] = $previous;
    }

    return array('candidates' => $merged, 'gsc' => $gsc, 'trends_count' => count($trends));
}

function seo_post_opportunities_topic_saturation($query, array $posts)
{
    $anchors = seo_post_opportunities_anchor_tokens($query);
    if (!$anchors) {
        return 0;
    }
    $count = 0;
    foreach ($posts as $post) {
        $title_anchors = seo_post_opportunities_anchor_tokens($post['title'] ?? '');
        $shared = count(array_intersect($anchors, $title_anchors));
        if ($shared >= min(2, count($anchors)) || (count($anchors) <= 2 && $shared >= 1) || seo_post_opportunities_similarity($query, $post['title'] ?? '') >= 0.62) {
            $count++;
        }
    }
    return $count;
}

function seo_post_opportunities_linkability_score($intent, $query)
{
    $map = array(
        'comparacion' => 96,
        'problema_solucion' => 94,
        'actualidad_normativa' => 92,
        'guia' => 90,
        'informativa_long_tail' => 82,
        'tema_guia' => 74,
        'indeterminada' => 45,
    );
    $score = (int)($map[$intent] ?? 50);
    $n = ' ' . seo_post_opportunities_normalize($query) . ' ';
    foreach (array(' errores ',' seguridad ',' diferencia ',' normativa ',' detectar ',' reparar ',' elegir ',' medidas ',' mantenimiento ') as $needle) {
        if (false !== strpos($n, $needle)) {
            $score += 3;
        }
    }
    return max(1, min(100, $score));
}

function seo_post_opportunities_editorial_scores(array $candidate, array $posts, $decision, $similarity)
{
    $trends_score = (float)($candidate['trends']['score'] ?? 0);
    $impressions = (float)($candidate['gsc']['impressions'] ?? 0);
    $position = (float)($candidate['gsc']['position'] ?? 0);
    $catalog_fit = max(0, min(100, (float)($candidate['context']['match_score'] ?? 0) * 100));
    $product_count = max(0, (int)($candidate['context']['product_count'] ?? 0));
    $intent = (string)($candidate['intent']['label'] ?? 'indeterminada');
    $linkability = seo_post_opportunities_linkability_score($intent, $candidate['query'] ?? '');

    $gsc_demand = min(100, log(1 + max(0, $impressions), 10) * 42);
    $position_opportunity = 20;
    if ($position > 10 && $position <= 30) $position_opportunity = 100;
    elseif ($position > 30 && $position <= 60) $position_opportunity = 88;
    elseif ($position > 60 && $position <= 100) $position_opportunity = 68;
    elseif ($position > 0 && $position <= 10) $position_opportunity = 45;

    if ($trends_score > 0) {
        $demand = ($trends_score * 0.62) + ($gsc_demand * 0.38);
        $confidence = !empty($candidate['gsc']) ? 'ALTA' : 'MEDIA-ALTA';
    } elseif ($impressions > 0) {
        $demand = min(78, $gsc_demand);
        $confidence = 'MEDIA';
    } else {
        $demand = 30;
        $confidence = 'BAJA';
    }

    $novelty = max(0, min(100, (1 - (float)$similarity) * 100));
    $authority = ($demand * 0.43) + ($linkability * 0.42) + ($novelty * 0.15);

    $product_depth = $product_count > 0 ? min(100, 42 + log(1 + $product_count, 10) * 34) : 15;
    $sales = ($catalog_fit * 0.52) + ($product_depth * 0.28) + ($position_opportunity * 0.20);

    $saturation = seo_post_opportunities_topic_saturation($candidate['query'] ?? '', $posts);
    $priority = ($authority * 0.58) + ($sales * 0.34) + ($novelty * 0.08);
    if (!empty($candidate['trends']['breakout'])) $priority += 6;
    if ('CREATE_POST' === $decision && $saturation >= 4) $priority -= min(20, ($saturation - 3) * 5);
    if ('UPDATE_POST' === $decision && $saturation >= 3) $priority += 4;

    return array(
        'priority' => (int)round(max(1, min(100, $priority))),
        'authority_score' => (int)round(max(1, min(100, $authority))),
        'sales_score' => (int)round(max(1, min(100, $sales))),
        'demand_score' => (int)round(max(1, min(100, $demand))),
        'linkability_score' => (int)round(max(1, min(100, $linkability))),
        'catalog_fit_score' => (int)round(max(1, min(100, $catalog_fit))),
        'topic_saturation' => (int)$saturation,
        'confidence' => $confidence,
    );
}

function seo_post_opportunities_editorial_title($query, $intent, array $context = array())
{
    $query = trim(wp_strip_all_tags((string)$query));
    if ('' === $query) return '';

    if (in_array($intent, array('comparacion','problema_solucion','guia','actualidad_normativa'), true)) {
        return seo_post_opportunities_suggested_title($query);
    }

    $category = trim((string)($context['category'] ?? ''));
    if ($category && seo_post_opportunities_similarity($query, $category) >= 0.52) {
        return 'Guía de ' . $category . ': cómo elegir, usar y evitar errores comunes';
    }

    return seo_post_opportunities_suggested_title($query) . ': guía práctica, criterios de elección y errores comunes';
}

function seo_post_opportunities_priority(array $candidate, $decision, $similarity)
{
    $scores = seo_post_opportunities_editorial_scores($candidate, array(), $decision, $similarity);
    return (int)$scores['priority'];
}

function seo_post_opportunities_suggested_title($query)
{
    $query = trim(wp_strip_all_tags((string) $query));
    if ('' === $query) {
        return '';
    }
    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper(mb_substr($query, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($query, 1, null, 'UTF-8');
    }
    return ucfirst($query);
}

function seo_post_opportunities_external_recommendations(array $posts, $days = 60)
{
    $external = seo_post_opportunities_merge_external_candidates($posts, $days);
    $recommendations = array();
    $covered = 0;

    foreach ($external['candidates'] as $candidate) {
        $match = seo_post_opportunities_best_post_match($candidate['query'], $posts);
        $best = $match['best'];
        $similarity = $best ? (float)$best['similarity'] : 0.0;
        $existing = $best ? $best['post'] : array();
        $status = (string)($existing['status'] ?? '');
        $saturation = seo_post_opportunities_topic_saturation($candidate['query'], $posts);

        if ($similarity >= 0.88 && in_array($status, array('future','draft','pending'), true)) {
            $covered++;
            continue;
        }

        if ($similarity >= 0.88 && 'publish' === $status) {
            $gsc_pos = (float)($candidate['gsc']['position'] ?? 0);
            $gsc_imp = (float)($candidate['gsc']['impressions'] ?? 0);
            $trend_score = (float)($candidate['trends']['score'] ?? 0);
            if (($gsc_imp >= 5 && $gsc_pos >= 15) || $trend_score >= 60) $decision = 'UPDATE_POST';
            else { $covered++; continue; }
        } elseif ($similarity >= 0.76 || ($saturation >= 4 && 'publish' === $status)) {
            $decision = 'UPDATE_POST';
        } else {
            $decision = 'CREATE_POST';
        }

        $scores = seo_post_opportunities_editorial_scores($candidate, $posts, $decision, $similarity);
        $gsc_imp = (float)($candidate['gsc']['impressions'] ?? 0);
        $trend_score = (float)($candidate['trends']['score'] ?? 0);

        // Sin Trends no fingimos mercado externo: exigimos evidencia GSC + valor editorial + encaje comercial.
        if ('CREATE_POST' === $decision) {
            if ($scores['authority_score'] < 58 || $scores['sales_score'] < 42) {
                continue;
            }
            if ($trend_score <= 0 && $gsc_imp < 10) {
                continue;
            }
            if ('tema_guia' === ($candidate['intent']['label'] ?? '') && $gsc_imp < 14 && $trend_score < 60) {
                continue;
            }
            if ($scores['topic_saturation'] >= 5 && $trend_score < 70) {
                $covered++;
                continue;
            }
        }

        $context = $candidate['context'];
        $products = seo_post_opportunities_related_products($context['term_id'] ?? 0, 3);
        $sources = array();
        if (!empty($candidate['trends'])) $sources[] = 'Google Trends';
        if (!empty($candidate['gsc'])) $sources[] = 'Search Console';

        $reason_parts = array();
        $reason_parts[] = 'Potencial de autoridad ' . $scores['authority_score'] . '/100 y ventas ' . $scores['sales_score'] . '/100';
        if (!empty($candidate['trends'])) {
            $reason_parts[] = !empty($candidate['trends']['breakout'])
                ? 'Trends la marca como Breakout'
                : 'Trends aporta señal de demanda externa';
        } elseif (!empty($candidate['gsc'])) {
            $reason_parts[] = 'sin señal Trends almacenada: confianza de mercado limitada a Search Console';
        }
        if (!empty($candidate['gsc'])) {
            $reason_parts[] = sprintf(
                'Search Console: %s impresiones, posición media %s',
                number_format_i18n((float)$candidate['gsc']['impressions'], 0),
                number_format_i18n((float)$candidate['gsc']['position'], 1)
            );
        }
        if ('CREATE_POST' === $decision) $reason_parts[] = 'no existe un post suficientemente equivalente';
        else $reason_parts[] = 'ya existe una URL cercana; conviene reforzarla antes que abrir otra';
        if (!empty($context['category'])) $reason_parts[] = 'puede conducir de forma natural a la categoría «' . $context['category'] . '»';
        if ($scores['topic_saturation'] >= 3) $reason_parts[] = 'tema con saturación editorial ' . $scores['topic_saturation'] . '; evitar otra URL innecesaria';

        $risk = count($match['strong']) > 1 ? 'alto' : ($similarity >= 0.76 ? 'medio' : 'bajo');

        $recommendations[] = array(
            'recommendation_type' => 'growth',
            'goal'                 => 'authority_and_sales',
            'decision_code'        => $decision,
            'priority'             => $scores['priority'],
            'authority_score'      => $scores['authority_score'],
            'sales_score'          => $scores['sales_score'],
            'demand_score'         => $scores['demand_score'],
            'linkability_score'    => $scores['linkability_score'],
            'catalog_fit_score'    => $scores['catalog_fit_score'],
            'topic_saturation'     => $scores['topic_saturation'],
            'confidence'           => $scores['confidence'],
            'topic'                => $candidate['query'],
            'suggested_title'      => seo_post_opportunities_editorial_title($candidate['query'], (string)$candidate['intent']['label'], $context),
            'focus_keyword'        => seo_post_opportunities_clean_keyword($candidate['query'], $candidate['query']),
            'intent'               => (string)$candidate['intent']['label'],
            'reason'               => implode('. ', $reason_parts) . '.',
            'source'               => implode(' + ', $sources),
            'variants'             => array_values((array)($candidate['variants'] ?? array())),
            'trends'               => $candidate['trends'],
            'search_console'       => $candidate['gsc'],
            'existing_similarity' => round($similarity, 4),
            'cannibalization_risk' => $risk,
            'existing_post'       => $existing ? array(
                'id'=>(int)$existing['id'],'title'=>(string)$existing['title'],'status'=>(string)$existing['status'],'url'=>(string)$existing['url']
            ) : array(),
            'duplicate_post'      => array(),
            'hierarchy'           => array(
                'cluster_id'=>absint($context['cluster_id'] ?? 0),'cluster'=>(string)($context['cluster'] ?? ''),
                'hub_primary_id'=>absint($context['hub_primary_id'] ?? 0),'hub_primary'=>(string)($context['hub_primary'] ?? ''),
                'hub_secondary_id'=>absint($context['hub_secondary_id'] ?? 0),'hub_secondary'=>(string)($context['hub_secondary'] ?? ''),
                'category_id'=>absint($context['term_id'] ?? 0),'category'=>(string)($context['category'] ?? ''),'category_url'=>(string)($context['category_url'] ?? ''),
            ),
            'related_products' => $products,
        );
    }

    return array('recommendations'=>$recommendations,'covered'=>$covered,'gsc'=>$external['gsc'],'trends_count'=>$external['trends_count']);
}

function seo_post_opportunities_duplicate_recommendations(array $posts)
{
    $out = array();
    $seen = array();
    $count = count($posts);

    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            $a = $posts[$i];
            $b = $posts[$j];

            $title_a = seo_post_opportunities_normalize($a['title']);
            $title_b = seo_post_opportunities_normalize($b['title']);
            $slug_a = preg_replace('/-\d+$/', '', (string) $a['slug']);
            $slug_b = preg_replace('/-\d+$/', '', (string) $b['slug']);
            $similarity = seo_post_opportunities_similarity($a['title'], $b['title']);
            $same_content = $a['content_hash'] && $a['content_hash'] === $b['content_hash'];
            $exact_title = '' !== $title_a && $title_a === $title_b;
            $same_slug_base = '' !== $slug_a && $slug_a === $slug_b;

            if (!$exact_title && !$same_content && !($same_slug_base && $similarity >= 0.88) && $similarity < 0.94) {
                continue;
            }

            $pair_key = min($a['id'], $b['id']) . ':' . max($a['id'], $b['id']);
            if (isset($seen[$pair_key])) {
                continue;
            }
            $seen[$pair_key] = true;

            // Conservar publicado frente a no publicado; si ambos son iguales, conservar el ID menor.
            $rank = array('publish' => 4, 'future' => 3, 'pending' => 2, 'draft' => 1, 'private' => 1);
            $rank_a = $rank[$a['status']] ?? 0;
            $rank_b = $rank[$b['status']] ?? 0;
            if ($rank_b > $rank_a || ($rank_b === $rank_a && $b['id'] < $a['id'])) {
                $keep = $b;
                $duplicate = $a;
            } else {
                $keep = $a;
                $duplicate = $b;
            }

            $a_published = 'publish' === (string) $a['status'];
            $b_published = 'publish' === (string) $b['status'];

            if ($a_published && $b_published) {
                $decision = 'REVIEW_CONSOLIDATION';
                $priority = ($exact_title || $same_content) ? 95 : 82;
                $reason = 'Dos posts publicados cubren prácticamente la misma intención. Revisar métricas, elegir URL principal y consolidar antes de aplicar una 301.';
            } else {
                $decision = 'DELETE_UNPUBLISHED_DUPLICATE';
                $priority = ($exact_title || $same_content) ? 100 : 92;
                $reason = 'Duplicado o solapamiento casi exacto. Conservar la URL/entrada indicada y eliminar solo una copia que no esté publicada.';
            }

            $out[] = array(
                'recommendation_type' => 'maintenance',
                'goal'                => 'editorial_hygiene',
                'decision_code'       => $decision,
                'priority'            => $priority,
                'authority_score'     => 0,
                'sales_score'         => 0,
                'demand_score'        => 0,
                'linkability_score'   => 0,
                'catalog_fit_score'   => 0,
                'topic_saturation'    => 0,
                'confidence'          => 'ALTA',
                'topic'               => $keep['title'],
                'suggested_title'     => $keep['title'],
                'focus_keyword'       => seo_post_opportunities_clean_keyword($keep['focus_keyword'], $keep['title']),
                'intent'              => 'mantenimiento_editorial',
                'reason'              => $reason,
                'source'              => 'Inventario de posts',
                'variants'            => array($a['title'], $b['title']),
                'trends'              => array(),
                'search_console'      => array(),
                'existing_similarity' => round($similarity, 4),
                'cannibalization_risk'=> 'alto',
                'existing_post'       => array(
                    'id'     => (int) $keep['id'],
                    'title'  => (string) $keep['title'],
                    'status' => (string) $keep['status'],
                    'url'    => (string) $keep['url'],
                ),
                'duplicate_post'      => array(
                    'id'     => (int) $duplicate['id'],
                    'title'  => (string) $duplicate['title'],
                    'status' => (string) $duplicate['status'],
                    'url'    => (string) $duplicate['url'],
                ),
                'hierarchy'           => array(),
                'related_products'    => array(),
            );
        }
    }

    usort($out, function ($a, $b) {
        return $b['priority'] <=> $a['priority'];
    });

    return array_slice($out, 0, 100);
}

function seo_post_opportunities_build_report($days = 60)
{
    $days = in_array((int) $days, array(28, 60, 90), true) ? (int) $days : 60;
    $posts = seo_post_opportunities_get_posts();
    $external = seo_post_opportunities_external_recommendations($posts, $days);
    $duplicates = seo_post_opportunities_duplicate_recommendations($posts);
    $recommendations = array_merge($duplicates, $external['recommendations']);

    usort($recommendations, function ($a, $b) {
        if ($a['priority'] === $b['priority']) {
            return strcmp($a['decision_code'], $b['decision_code']);
        }
        return $b['priority'] <=> $a['priority'];
    });

    $summary = array(
        'posts_total'       => count($posts),
        'posts_published'   => 0,
        'posts_future'      => 0,
        'posts_draft'       => 0,
        'trends_signals'    => (int) $external['trends_count'],
        'covered_omitted'   => (int) $external['covered'],
        'create'            => 0,
        'update'            => 0,
        'delete_unpublished'=> 0,
        'consolidate'       => 0,
        'authority_opportunities' => 0,
        'sales_opportunities'     => 0,
        'balanced_opportunities'  => 0,
    );

    foreach ($posts as $post) {
        if ('publish' === $post['status']) {
            $summary['posts_published']++;
        } elseif ('future' === $post['status']) {
            $summary['posts_future']++;
        } elseif (in_array($post['status'], array('draft', 'pending'), true)) {
            $summary['posts_draft']++;
        }
    }

    foreach ($recommendations as $row) {
        if ('CREATE_POST' === $row['decision_code']) {
            $summary['create']++;
        } elseif ('UPDATE_POST' === $row['decision_code']) {
            $summary['update']++;
        } elseif ('DELETE_UNPUBLISHED_DUPLICATE' === $row['decision_code']) {
            $summary['delete_unpublished']++;
        } elseif ('REVIEW_CONSOLIDATION' === $row['decision_code']) {
            $summary['consolidate']++;
        }
        if ('growth' === ($row['recommendation_type'] ?? '')) {
            if ((int)($row['authority_score'] ?? 0) >= 65) $summary['authority_opportunities']++;
            if ((int)($row['sales_score'] ?? 0) >= 65) $summary['sales_opportunities']++;
            if ((int)($row['authority_score'] ?? 0) >= 65 && (int)($row['sales_score'] ?? 0) >= 60) $summary['balanced_opportunities']++;
        }
    }

    $gsc = $external['gsc'];
    return array(
        'schema_version' => SEO_POST_OPPORTUNITIES_SCHEMA_VERSION,
        'module_version' => SEO_POST_OPPORTUNITIES_VERSION,
        'generated_at'   => current_time('mysql'),
        'source_policy'  => 'existing_plugin_connections_only',
        'objective'      => 'gain_authority_and_sales',
        'strategy'       => array(
            'priority_weights' => array('authority'=>0.58,'sales'=>0.34,'novelty'=>0.08),
            'authority_components' => array('demand'=>0.43,'linkability'=>0.42,'novelty'=>0.15),
            'sales_components' => array('catalog_fit'=>0.52,'product_depth'=>0.28,'gsc_opportunity'=>0.20),
            'trends_policy' => (int)$external['trends_count'] > 0 ? 'market_signal_available' : 'no_market_signal_do_not_infer_trends',
        ),
        'period_days'    => $days,
        'search_console' => array(
            'available' => !empty($gsc['connected']),
            'latest'    => (string) ($gsc['latest'] ?? ''),
            'period'    => $gsc['period'] ?? null,
        ),
        'summary'         => $summary,
        'recommendations' => $recommendations,
    );
}

function seo_post_opportunities_action_label($code)
{
    $labels = array(
        'CREATE_POST'                  => 'Crear post',
        'UPDATE_POST'                  => 'Actualizar / ampliar',
        'DELETE_UNPUBLISHED_DUPLICATE' => 'Eliminar duplicado no publicado',
        'REVIEW_CONSOLIDATION'         => 'Revisar consolidación',
    );
    return $labels[$code] ?? $code;
}

function seo_post_opportunities_action_color($code)
{
    $colors = array(
        'CREATE_POST'                  => '#2271b1',
        'UPDATE_POST'                  => '#8a6700',
        'DELETE_UNPUBLISHED_DUPLICATE' => '#b32d2e',
        'REVIEW_CONSOLIDATION'         => '#7a3e9d',
    );
    return $colors[$code] ?? '#50575e';
}

function seo_post_opportunities_export_url($format, $days, $action = 'all')
{
    return wp_nonce_url(
        add_query_arg(array(
            'action'      => 'seo_post_opportunities_export',
            'format'      => sanitize_key($format),
            'days'        => absint($days),
            'post_action' => sanitize_key($action),
        ), admin_url('admin-post.php')),
        'seo_post_opportunities_export'
    );
}

function seo_post_opportunities_filter_rows(array $rows, $action)
{
    $action = sanitize_key($action);
    if ('all' === $action || '' === $action) {
        return $rows;
    }

    $map = array(
        'create'      => 'CREATE_POST',
        'update'      => 'UPDATE_POST',
        'delete'      => 'DELETE_UNPUBLISHED_DUPLICATE',
        'consolidate' => 'REVIEW_CONSOLIDATION',
    );
    if (empty($map[$action])) {
        return $rows;
    }

    return array_values(array_filter($rows, function ($row) use ($map, $action) {
        return ($row['decision_code'] ?? '') === $map[$action];
    }));
}

function seo_post_opportunities_render_performance(array $posts, $days)
{
    $performance = seo_post_opportunities_performance($posts, $days);
    $ga4 = $performance['ga4'];
    $gsc = $performance['gsc'];
    $rows = $performance['rows'];
    $top5 = array_slice($rows, 0, 5);
    $top5_metric = !empty($ga4['available']) ? 'pageviews' : 'clicks';
    $top5_label = !empty($ga4['available']) ? 'Vistas GA4' : 'Clics orgánicos';
    $chart_id = 'seo-post-performance-' . wp_rand(1000,999999);
    $nonce = wp_create_nonce('seo_post_opportunities_detail');

    $daily = array();
    foreach ((array)$ga4['daily'] as $row) {
        $date = (string)$row['date'];
        $daily[$date] = array(
            'date'=>$date,
            'pageviews'=>(float)$row['pageviews'],
            'sessions'=>(float)$row['sessions'],
            'clicks'=>0,
            'impressions'=>0,
            'position'=>0,
        );
    }
    foreach ((array)$gsc['daily'] as $row) {
        $date = (string)$row['date'];
        if (!isset($daily[$date])) {
            $daily[$date] = array('date'=>$date,'pageviews'=>0,'sessions'=>0,'clicks'=>0,'impressions'=>0,'position'=>0);
        }
        $daily[$date]['clicks'] = (float)$row['clicks'];
        $daily[$date]['impressions'] = (float)$row['impressions'];
        $daily[$date]['position'] = (float)$row['position'];
    }
    ksort($daily);

    echo '<section id="' . esc_attr($chart_id) . '" style="background:#fff;border:1px solid #dcdcde;padding:20px;border-radius:8px;margin:18px 0;">';
    echo '<div style="display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap;">';
    echo '<div><h2 style="margin:0 0 6px;">Rendimiento de Posts</h2>';
    echo '<p style="margin:0;color:#646970;">Analytics mide sesiones y vistas de los posts. Search Console mide clics, impresiones y posición orgánica. Se reutilizan las conexiones existentes.</p></div>';
    echo '<div style="display:flex;gap:6px;flex-wrap:wrap;">';
    echo '<span style="padding:4px 8px;border-radius:12px;background:' . (!empty($ga4['available']) ? '#edfaef' : '#fcf0f1') . ';">GA4 ' . (!empty($ga4['available']) ? 'activo' : 'no disponible') . '</span>';
    echo '<span style="padding:4px 8px;border-radius:12px;background:' . (!empty($gsc['available']) ? '#edfaef' : '#fcf0f1') . ';">Search Console ' . (!empty($gsc['available']) ? 'activo' : 'no disponible') . '</span>';
    echo '</div></div>';

    $cards = array(
        'Vistas de posts' => !empty($ga4['available']) ? (int)round($ga4['summary']['pageviews']) : '—',
        'Sesiones en posts' => !empty($ga4['available']) ? (int)round($ga4['summary']['sessions']) : '—',
        'Clics orgánicos' => !empty($gsc['available']) ? (int)round($gsc['summary']['clicks']) : '—',
        'Impresiones' => !empty($gsc['available']) ? (int)round($gsc['summary']['impressions']) : '—',
        'CTR orgánico' => !empty($gsc['available']) ? number_format_i18n($gsc['summary']['ctr']*100,1) . '%' : '—',
        'Posición media' => !empty($gsc['available']) && $gsc['summary']['position'] > 0 ? number_format_i18n($gsc['summary']['position'],1) : '—',
    );
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:10px;margin-top:16px;">';
    foreach ($cards as $label=>$value) {
        $display = is_numeric($value) ? number_format_i18n($value) : $value;
        echo '<div style="border:1px solid #dcdcde;border-radius:7px;padding:12px;"><div style="font-size:22px;font-weight:700;">' . esc_html($display) . '</div><div style="color:#646970;">' . esc_html($label) . '</div></div>';
    }
    echo '</div>';

    if (empty($ga4['available']) && !empty($ga4['error'])) {
        echo '<div class="notice notice-warning inline"><p><strong>Analytics:</strong> ' . esc_html($ga4['error']) . ' El panel seguirá mostrando Search Console.</p></div>';
    }

    if ($daily) {
        echo '<div style="margin-top:18px;border:1px solid #dcdcde;border-radius:7px;padding:14px;">';
        echo '<div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;">';
        echo '<div><h3 style="margin:0;">Tráfico global de posts</h3><p class="description" style="margin:4px 0 0;">Evolución diaria del conjunto de artículos. Solo URLs de posts. En posición, menor es mejor.</p></div>';
        echo '<div style="display:flex;gap:6px;flex-wrap:wrap;">';
        if (!empty($ga4['available'])) {
            echo '<button type="button" class="button button-primary" data-post-metric="pageviews">Vistas</button>';
            echo '<button type="button" class="button" data-post-metric="sessions">Sesiones</button>';
        }
        echo '<button type="button" class="button ' . (empty($ga4['available']) ? 'button-primary' : '') . '" data-post-metric="clicks">Clics Google</button>';
        echo '<button type="button" class="button" data-post-metric="impressions">Impresiones</button>';
        echo '<button type="button" class="button" data-post-metric="position">Posición</button>';
        echo '</div></div><div data-post-overall-chart style="min-height:260px;margin-top:10px;"></div></div>';
    }

    if ($top5) {
        echo '<div style="margin-top:18px;border:1px solid #dcdcde;border-radius:7px;padding:14px;">';
        echo '<div><h3 style="margin:0;">Top 5 posts del periodo</h3><p class="description" style="margin:4px 0 0;">Ranking por ' . esc_html($top5_label) . '. Pulsa una barra para abrir la evolución individual.</p></div>';
        echo '<div data-post-top5-chart style="min-height:300px;margin-top:12px;"></div>';
        echo '</div>';
    }

    echo '<div style="margin-top:18px;overflow:auto;">';
    echo '<h3>Rendimiento por post</h3><p class="description">Pulsa <strong>Ver evolución</strong> para abrir el histórico individual y las consultas que le están dando visibilidad.</p>';
    echo '<table class="widefat striped" style="min-width:1000px;"><thead><tr><th>Post</th><th>Vistas GA4</th><th>Sesiones GA4</th><th>Clics GSC</th><th>Impresiones</th><th>CTR</th><th>Posición</th><th></th></tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="8">Todavía no hay métricas de posts para este periodo.</td></tr>';
    }
    foreach (array_slice($rows,0,100) as $row) {
        echo '<tr>';
        echo '<td><strong>#' . esc_html($row['post_id']) . ' ' . esc_html($row['title']) . '</strong><br><small>' . esc_html(mysql2date('d/m/Y',$row['date'])) . '</small></td>';
        echo '<td>' . (!empty($ga4['available']) ? esc_html(number_format_i18n((int)$row['pageviews'])) : '—') . '</td>';
        echo '<td>' . (!empty($ga4['available']) ? esc_html(number_format_i18n((int)$row['sessions'])) : '—') . '</td>';
        echo '<td>' . (!empty($gsc['available']) ? esc_html(number_format_i18n((int)$row['clicks'])) : '—') . '</td>';
        echo '<td>' . (!empty($gsc['available']) ? esc_html(number_format_i18n((int)$row['impressions'])) : '—') . '</td>';
        echo '<td>' . (!empty($gsc['available']) ? esc_html(number_format_i18n($row['ctr']*100,1) . '%') : '—') . '</td>';
        echo '<td>' . (!empty($gsc['available']) && $row['position'] > 0 ? esc_html(number_format_i18n($row['position'],1)) : '—') . '</td>';
        echo '<td><button type="button" class="button button-small" data-post-detail="' . esc_attr($row['post_id']) . '">Ver evolución</button></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    echo '<div data-post-detail-panel style="display:none;margin-top:18px;border:1px solid #2271b1;border-radius:7px;padding:16px;background:#f6f7f7;">';
    echo '<div style="display:flex;justify-content:space-between;gap:10px;align-items:start;"><div><h3 data-detail-title style="margin:0 0 5px;">Detalle del post</h3><p data-detail-summary class="description" style="margin:0;"></p></div><button type="button" class="button" data-detail-close>Cerrar</button></div>';
    echo '<div data-detail-chart style="min-height:250px;margin-top:12px;"></div><div data-detail-queries style="margin-top:14px;"></div></div>';

    echo '<script>';
    echo '(function(){';
    echo 'const root=document.getElementById(' . wp_json_encode($chart_id) . ');';
    echo 'const overall=' . wp_json_encode(array_values($daily)) . ';';
    echo 'const top5=' . wp_json_encode(array_values(array_map(function($row) use ($top5_metric) {
        return array(
            'post_id'=>(int)$row['post_id'],
            'title'=>(string)$row['title'],
            'value'=>(float)$row[$top5_metric],
            'pageviews'=>(float)$row['pageviews'],
            'clicks'=>(float)$row['clicks'],
        );
    }, $top5))) . ';';
    echo 'const top5Metric=' . wp_json_encode($top5_metric) . ';';
    echo 'const top5Label=' . wp_json_encode($top5_label) . ';';
    echo 'const ajaxUrl=' . wp_json_encode(admin_url('admin-ajax.php')) . ';';
    echo 'const nonce=' . wp_json_encode($nonce) . ';';
    echo 'const days=' . wp_json_encode((int)$days) . ';';
    echo <<<'JS'
if(!root)return;
const svgNS='http://www.w3.org/2000/svg';
function fmt(v,m){v=Number(v||0);if(m==='position')return v.toLocaleString('es-ES',{minimumFractionDigits:1,maximumFractionDigits:1});return Math.round(v).toLocaleString('es-ES');}
function dateLabel(d){const p=String(d||'').split('-');return p.length===3?p[2]+'/'+p[1]:d;}
function draw(container,rows,metric){
  container.innerHTML=''; if(!rows||!rows.length){container.textContent='Sin datos.';return;}
  const W=900,H=250,M={t:18,r:18,b:34,l:58},PW=W-M.l-M.r,PH=H-M.t-M.b;
  const values=rows.map(r=>Number(r[metric]||0)); let min=Math.min(...values),max=Math.max(...values);
  if(metric!=='position')min=0;else{const span=Math.max(1,max-min);min=Math.max(0,min-span*.08);max+=span*.08;} if(max===min)max=min+1;
  const x=i=>M.l+(rows.length===1?PW/2:(i/(rows.length-1))*PW);
  const y=v=>{const ratio=(Number(v)-min)/(max-min);return metric==='position'?M.t+ratio*PH:M.t+(1-ratio)*PH;};
  const svg=document.createElementNS(svgNS,'svg');svg.setAttribute('viewBox','0 0 '+W+' '+H);svg.setAttribute('width','100%');svg.setAttribute('height','250');
  for(let i=0;i<=4;i++){const gy=M.t+(i/4)*PH,ln=document.createElementNS(svgNS,'line');ln.setAttribute('x1',M.l);ln.setAttribute('x2',W-M.r);ln.setAttribute('y1',gy);ln.setAttribute('y2',gy);ln.setAttribute('stroke','#e2e4e7');svg.appendChild(ln);const val=metric==='position'?min+(i/4)*(max-min):max-(i/4)*(max-min),tx=document.createElementNS(svgNS,'text');tx.setAttribute('x',M.l-8);tx.setAttribute('y',gy+4);tx.setAttribute('text-anchor','end');tx.setAttribute('font-size','10');tx.setAttribute('fill','#646970');tx.textContent=fmt(val,metric);svg.appendChild(tx);}
  const line=document.createElementNS(svgNS,'polyline');line.setAttribute('points',values.map((v,i)=>x(i).toFixed(2)+','+y(v).toFixed(2)).join(' '));line.setAttribute('fill','none');line.setAttribute('stroke','#2271b1');line.setAttribute('stroke-width','3');line.setAttribute('stroke-linecap','round');line.setAttribute('stroke-linejoin','round');svg.appendChild(line);
  const tickCount=Math.min(6,rows.length);for(let i=0;i<tickCount;i++){const idx=Math.round((i/Math.max(1,tickCount-1))*(rows.length-1)),tx=document.createElementNS(svgNS,'text');tx.setAttribute('x',x(idx));tx.setAttribute('y',H-10);tx.setAttribute('text-anchor','middle');tx.setAttribute('font-size','10');tx.setAttribute('fill','#646970');tx.textContent=dateLabel(rows[idx].date);svg.appendChild(tx);}
  rows.forEach((r,i)=>{const c=document.createElementNS(svgNS,'circle');c.setAttribute('cx',x(i));c.setAttribute('cy',y(r[metric]));c.setAttribute('r',rows.length<=31?'3':'2');c.setAttribute('fill','#2271b1');const t=document.createElementNS(svgNS,'title');t.textContent=dateLabel(r.date)+': '+fmt(r[metric],metric);c.appendChild(t);svg.appendChild(c);});container.appendChild(svg);
}
const overallBox=root.querySelector('[data-post-overall-chart]');let metric=root.querySelector('[data-post-metric].button-primary')?.dataset.postMetric||'clicks';if(overallBox&&overall.length)draw(overallBox,overall,metric);
root.querySelectorAll('[data-post-metric]').forEach(btn=>btn.addEventListener('click',()=>{metric=btn.dataset.postMetric;root.querySelectorAll('[data-post-metric]').forEach(x=>x.classList.toggle('button-primary',x===btn));if(overallBox)draw(overallBox,overall,metric);}));
function drawTop5(container,rows){
  container.innerHTML=''; if(!rows||!rows.length){container.textContent='Sin datos.';return;}
  const W=900,rowH=48,M={t:18,r:70,b:18,l:300},H=M.t+M.b+(rows.length*rowH),PW=W-M.l-M.r,max=Math.max(1,...rows.map(r=>Number(r.value||0)));
  const svg=document.createElementNS(svgNS,'svg');svg.setAttribute('viewBox','0 0 '+W+' '+H);svg.setAttribute('width','100%');svg.setAttribute('height',String(H));
  rows.forEach((r,i)=>{const y=M.t+i*rowH+7,h=25,w=Math.max(2,(Number(r.value||0)/max)*PW);
    const label=document.createElementNS(svgNS,'text');label.setAttribute('x',M.l-12);label.setAttribute('y',y+17);label.setAttribute('text-anchor','end');label.setAttribute('font-size','12');label.setAttribute('fill','#1d2327');let txt=String(r.title||'');label.textContent=txt.length>43?txt.slice(0,41)+'…':txt;const lt=document.createElementNS(svgNS,'title');lt.textContent=txt;label.appendChild(lt);svg.appendChild(label);
    const bg=document.createElementNS(svgNS,'rect');bg.setAttribute('x',M.l);bg.setAttribute('y',y);bg.setAttribute('width',PW);bg.setAttribute('height',h);bg.setAttribute('rx','5');bg.setAttribute('fill','#f0f0f1');svg.appendChild(bg);
    const bar=document.createElementNS(svgNS,'rect');bar.setAttribute('x',M.l);bar.setAttribute('y',y);bar.setAttribute('width',w);bar.setAttribute('height',h);bar.setAttribute('rx','5');bar.setAttribute('fill','#2271b1');bar.setAttribute('style','cursor:pointer');bar.setAttribute('data-post-id',String(r.post_id));const bt=document.createElementNS(svgNS,'title');bt.textContent=txt+' · '+fmt(r.value,top5Metric)+' '+top5Label;bar.appendChild(bt);svg.appendChild(bar);
    const value=document.createElementNS(svgNS,'text');value.setAttribute('x',M.l+PW+10);value.setAttribute('y',y+17);value.setAttribute('font-size','12');value.setAttribute('font-weight','700');value.setAttribute('fill','#1d2327');value.textContent=fmt(r.value,top5Metric);svg.appendChild(value);
  });container.appendChild(svg);
  container.querySelectorAll('[data-post-id]').forEach(el=>el.addEventListener('click',()=>loadDetail(el.getAttribute('data-post-id'))));
}
const panel=root.querySelector('[data-post-detail-panel]'),title=root.querySelector('[data-detail-title]'),summary=root.querySelector('[data-detail-summary]'),detailChart=root.querySelector('[data-detail-chart]'),queries=root.querySelector('[data-detail-queries]');
const top5Box=root.querySelector('[data-post-top5-chart]');if(top5Box&&top5.length)drawTop5(top5Box,top5);
async function loadDetail(id){
  panel.style.display='block';title.textContent='Cargando…';summary.textContent='';detailChart.textContent='';queries.textContent='';panel.scrollIntoView({behavior:'smooth',block:'nearest'});
  const body=new URLSearchParams({action:'seo_post_opportunities_post_detail',nonce:nonce,post_id:String(id),days:String(days)});
  try{const res=await fetch(ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()});const json=await res.json();if(!json.success)throw new Error(json.data?.message||'No se pudo cargar el detalle');const d=json.data,s=d.summary||{};title.textContent=d.post.title;summary.textContent='Vistas '+fmt(s.pageviews,'pageviews')+' · Sesiones '+fmt(s.sessions,'sessions')+' · Clics Google '+fmt(s.clicks,'clicks')+' · Impresiones '+fmt(s.impressions,'impressions')+' · Posición '+fmt(s.position,'position');draw(detailChart,d.daily||[],d.sources?.analytics?'pageviews':'clicks');const q=d.top_queries||[];if(q.length){let html='<h4>Consultas principales en Search Console</h4><table class="widefat striped"><thead><tr><th>Consulta</th><th>Clics</th><th>Impresiones</th><th>Posición</th></tr></thead><tbody>';q.forEach(r=>{const safe=String(r.query_text||'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));html+='<tr><td>'+safe+'</td><td>'+fmt(r.clicks,'clicks')+'</td><td>'+fmt(r.impressions,'impressions')+'</td><td>'+fmt(r.position,'position')+'</td></tr>';});queries.innerHTML=html+'</tbody></table>';}else{queries.innerHTML='<p class="description">Sin consultas de Search Console para este post en el periodo.</p>';}}
  catch(e){title.textContent='No se pudo cargar el detalle';summary.textContent=e.message||String(e);}
}
root.querySelectorAll('[data-post-detail]').forEach(btn=>btn.addEventListener('click',()=>loadDetail(btn.dataset.postDetail)));
root.querySelector('[data-detail-close]')?.addEventListener('click',()=>panel.style.display='none');
root._seoLoadPostDetail=loadDetail;
})();
JS;
    echo '</script>';
    echo '</section>';
}

function seo_post_opportunities_render_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $days = isset($_GET['post_days']) ? absint($_GET['post_days']) : 60;
    $days = in_array($days, array(28, 60, 90), true) ? $days : 60;
    $action = isset($_GET['post_action']) ? sanitize_key($_GET['post_action']) : 'all';
    $limit = isset($_GET['post_limit']) ? absint($_GET['post_limit']) : 50;
    $limit = in_array($limit, array(20, 50, 100, 200), true) ? $limit : 50;

    $report = seo_post_opportunities_build_report($days);
    $posts = seo_post_opportunities_get_posts();
    seo_post_opportunities_render_performance($posts, $days);
    $rows = seo_post_opportunities_filter_rows($report['recommendations'], $action);
    $visible = array_slice($rows, 0, $limit);
    $summary = $report['summary'];

    echo '<div style="background:#fff;border:1px solid #dcdcde;padding:20px;border-radius:8px;margin:18px 0;">';
    echo '<h2 style="margin-top:0;">Oportunidades de Posts</h2>';
    echo '<p><strong>Objetivo: ganar autoridad y ventas.</strong> El informe separa oportunidades de crecimiento (contenido que puede atraer búsquedas/enlaces y conducir al catálogo) del mantenimiento editorial. Reutiliza únicamente Trends, Search Console, Analytics, catálogo y jerarquía ya existentes.</p>';
    echo '<p><code>V' . esc_html(SEO_POST_OPPORTUNITIES_VERSION) . '</code> · Prioridad combina <strong>Autoridad 58% + Ventas 34% + Novedad 8%</strong>. No equivale a volumen de búsqueda.</p>';

    echo '<form method="get" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;">';
    echo '<input type="hidden" name="page" value="seo-reports"><input type="hidden" name="tab" value="post_opportunities">';
    echo '<label><strong>Horizonte Search Console</strong><br><select name="post_days">';
    foreach (array(28, 60, 90) as $d) {
        echo '<option value="' . esc_attr($d) . '" ' . selected($days, $d, false) . '>' . esc_html($d) . ' días</option>';
    }
    echo '</select></label>';
    echo '<label><strong>Decisión</strong><br><select name="post_action">';
    $actions = array('all'=>'Todas','create'=>'Crear','update'=>'Actualizar','delete'=>'Eliminar duplicado no publicado','consolidate'=>'Consolidar');
    foreach ($actions as $key => $label) {
        echo '<option value="' . esc_attr($key) . '" ' . selected($action, $key, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label><strong>Filas</strong><br><select name="post_limit">';
    foreach (array(20, 50, 100, 200) as $n) {
        echo '<option value="' . esc_attr($n) . '" ' . selected($limit, $n, false) . '>' . esc_html($n) . '</option>';
    }
    echo '</select></label>';
    submit_button('Recalcular', 'secondary', 'submit', false);
    echo '</form>';

    echo '<p style="margin-bottom:0;margin-top:16px;">';
    echo '<a class="button button-primary" href="' . esc_url(seo_post_opportunities_export_url('csv', $days, $action)) . '">Descargar CSV</a> ';
    echo '<a class="button" href="' . esc_url(seo_post_opportunities_export_url('json', $days, $action)) . '">Descargar JSON</a>';
    echo '</p></div>';

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:0 0 18px;">';
    $cards = array(
        'Posts publicados' => $summary['posts_published'],
        'Programados' => $summary['posts_future'],
        'Borrador/pendiente' => $summary['posts_draft'],
        'Crear' => $summary['create'],
        'Actualizar' => $summary['update'],
        'Duplicados no publicados' => $summary['delete_unpublished'],
        'Consolidar publicados' => $summary['consolidate'],
        'Autoridad >=65' => $summary['authority_opportunities'],
        'Ventas >=65' => $summary['sales_opportunities'],
        'Equilibradas' => $summary['balanced_opportunities'],
        'Trends almacenados' => $summary['trends_signals'],
    );
    foreach ($cards as $label => $value) {
        echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:7px;padding:13px;">';
        echo '<div style="font-size:22px;font-weight:700;">' . esc_html(number_format_i18n($value)) . '</div>';
        echo '<div style="color:#50575e;">' . esc_html($label) . '</div></div>';
    }
    echo '</div>';

    if (function_exists('seo_landing_google_source_status')) {
        $source_status = seo_landing_google_source_status();
        if (empty($source_status['trends']['connected'])) {
            echo '<div class="notice notice-warning inline"><p><strong>Google Trends:</strong> ' . esc_html($source_status['trends']['detail']) . ' Las oportunidades seguirán usando Search Console hasta que el proveedor existente tenga señales de Trends.</p></div>';
        }
    }

    if (!$report['search_console']['available']) {
        echo '<div class="notice notice-warning inline"><p><strong>Search Console:</strong> no hay periodo sincronizado disponible. El informe continúa con Trends + catálogo + inventario de posts.</p></div>';
    } else {
        $period = $report['search_console']['period'];
        echo '<div class="notice notice-info inline"><p><strong>Search Console:</strong> datos hasta ' . esc_html($report['search_console']['latest']) . '; periodo analizado ' . esc_html($period['current_from']) . ' → ' . esc_html($period['current_to']) . '. Temas ya cubiertos y omitidos para evitar duplicación: <strong>' . esc_html(number_format_i18n($summary['covered_omitted'])) . '</strong>.</p></div>';
    }

    echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;overflow:auto;margin-top:16px;">';
    echo '<table class="widefat striped" style="min-width:1250px;"><thead><tr>';
    echo '<th>Prioridad</th><th>Decisión</th><th>Tema / post</th><th>Autoridad / ventas</th><th>Evidencia</th><th>Post existente</th><th>Destino catálogo</th><th>Motivo</th>';
    echo '</tr></thead><tbody>';

    if (!$visible) {
        echo '<tr><td colspan="8">No hay recomendaciones para este filtro.</td></tr>';
    }

    foreach ($visible as $row) {
        $color = seo_post_opportunities_action_color($row['decision_code']);
        echo '<tr>';
        echo '<td><strong style="font-size:18px;">' . esc_html($row['priority']) . '</strong>/100</td>';
        echo '<td><span style="display:inline-block;padding:4px 8px;border-radius:12px;color:#fff;background:' . esc_attr($color) . ';font-size:11px;font-weight:700;">' . esc_html(seo_post_opportunities_action_label($row['decision_code'])) . '</span></td>';
        echo '<td><strong>' . esc_html($row['suggested_title']) . '</strong><br><small>Intento: ' . esc_html($row['intent']) . '<br>Keyword: ' . esc_html($row['focus_keyword']) . '<br>Riesgo canibalización: ' . esc_html($row['cannibalization_risk']) . '</small></td>';

        echo '<td><small>';
        if ('growth' === ($row['recommendation_type'] ?? '')) {
            echo '<strong>Autoridad:</strong> ' . esc_html((int)($row['authority_score'] ?? 0)) . '/100<br>';
            echo '<strong>Ventas:</strong> ' . esc_html((int)($row['sales_score'] ?? 0)) . '/100<br>';
            echo 'Demanda ' . esc_html((int)($row['demand_score'] ?? 0)) . ' · Enlazable ' . esc_html((int)($row['linkability_score'] ?? 0)) . '<br>';
            echo 'Confianza: <strong>' . esc_html($row['confidence'] ?? '—') . '</strong>';
        } else {
            echo 'Mantenimiento editorial<br>Confianza: <strong>' . esc_html($row['confidence'] ?? 'ALTA') . '</strong>';
        }
        echo '</small></td>';

        echo '<td><small>';
        if (!empty($row['trends'])) {
            echo '<strong>Trends:</strong> score ' . esc_html(number_format_i18n((float) ($row['trends']['score'] ?? 0), 1));
            if (!empty($row['trends']['breakout'])) {
                echo ' · <strong>Breakout</strong>';
            } elseif (isset($row['trends']['growth'])) {
                echo ' · crecimiento ' . esc_html(number_format_i18n((float) $row['trends']['growth'], 1));
            }
            echo '<br>';
        }
        if (!empty($row['search_console'])) {
            echo '<strong>GSC:</strong> ' . esc_html(number_format_i18n((float) ($row['search_console']['impressions'] ?? 0), 0)) . ' imp. · pos. ' . esc_html(number_format_i18n((float) ($row['search_console']['position'] ?? 0), 1)) . '<br>';
        }
        echo esc_html($row['source']);
        echo '</small></td>';

        echo '<td><small>';
        if (!empty($row['existing_post'])) {
            echo '<strong>#' . esc_html($row['existing_post']['id']) . '</strong> ' . esc_html($row['existing_post']['title']) . '<br>';
            echo esc_html($row['existing_post']['status']) . ' · similitud ' . esc_html(number_format_i18n((float) $row['existing_similarity'] * 100, 0)) . '%';
            if (!empty($row['existing_post']['url'])) {
                echo '<br><a href="' . esc_url($row['existing_post']['url']) . '" target="_blank" rel="noopener">Abrir</a>';
            }
            if (!empty($row['existing_post']['id']) && 'publish' === ($row['existing_post']['status'] ?? '')) {
                echo ' <button type="button" class="button-link" data-open-post-performance="' . esc_attr($row['existing_post']['id']) . '">Ver rendimiento</button>';
            }
        } else {
            echo 'No hay post equivalente.';
        }
        if (!empty($row['duplicate_post'])) {
            echo '<hr style="margin:7px 0;"><strong>Copia:</strong> #' . esc_html($row['duplicate_post']['id']) . ' ' . esc_html($row['duplicate_post']['title']) . '<br>' . esc_html($row['duplicate_post']['status']);
        }
        echo '</small></td>';

        echo '<td><small>';
        $h = $row['hierarchy'];
        if (!empty($h['cluster'])) echo '<strong>Cluster:</strong> ' . esc_html($h['cluster']) . '<br>';
        if (!empty($h['hub_primary'])) echo '<strong>Hub P:</strong> ' . esc_html($h['hub_primary']) . '<br>';
        if (!empty($h['hub_secondary'])) echo '<strong>Hub S:</strong> ' . esc_html($h['hub_secondary']) . '<br>';
        if (!empty($h['category'])) {
            echo '<strong>Categoría:</strong> ';
            if (!empty($h['category_url'])) {
                echo '<a href="' . esc_url($h['category_url']) . '" target="_blank" rel="noopener">' . esc_html($h['category']) . '</a>';
            } else {
                echo esc_html($h['category']);
            }
        }
        if (!empty($row['related_products'])) {
            echo '<br><strong>Productos:</strong> ';
            $product_names = array();
            foreach ($row['related_products'] as $product) {
                $product_names[] = $product['title'];
            }
            echo esc_html(implode(' · ', $product_names));
        }
        echo '</small></td>';

        echo '<td style="max-width:420px;">' . esc_html($row['reason']) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
    echo '<script>(function(){document.querySelectorAll("[data-open-post-performance]").forEach(function(b){b.addEventListener("click",function(){var root=document.querySelector("[id^=seo-post-performance-]");if(root&&root._seoLoadPostDetail){root._seoLoadPostDetail(b.getAttribute("data-open-post-performance"));}});});})();</script>';
    echo '<p><small>Regla de seguridad editorial: un post publicado nunca se marca para borrado automático. Si dos URLs publicadas se solapan, el informe usa <strong>Revisar consolidación</strong> para que primero se compare rendimiento y después se decida URL principal/301.</small></p>';
}

function seo_post_opportunities_export_handler()
{
    if (!current_user_can('manage_options')) {
        wp_die('Sin permisos.');
    }
    check_admin_referer('seo_post_opportunities_export');

    $format = isset($_GET['format']) ? sanitize_key($_GET['format']) : 'csv';
    $days = isset($_GET['days']) ? absint($_GET['days']) : 60;
    $days = in_array($days, array(28, 60, 90), true) ? $days : 60;
    $action = isset($_GET['post_action']) ? sanitize_key($_GET['post_action']) : 'all';

    $report = seo_post_opportunities_build_report($days);
    $report['recommendations'] = seo_post_opportunities_filter_rows($report['recommendations'], $action);
    $date = wp_date('Ymd_His');

    nocache_headers();

    if ('json' === $format) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="seo-post-opportunities-' . $date . '.json"');
        echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="seo-post-opportunities-' . $date . '.csv"');
    echo "\xEF\xBB\xBF";

    $fh = fopen('php://output', 'w');
    fputcsv($fh, array(
        'recommendation_type','goal','decision_code','priority','authority_score','sales_score','demand_score','linkability_score','catalog_fit_score','topic_saturation','confidence','topic','suggested_title','focus_keyword','intent','reason','source',
        'trends_score','trends_growth','trends_breakout','trends_seeds',
        'gsc_impressions','gsc_clicks','gsc_ctr','gsc_position','gsc_top_page',
        'existing_similarity','cannibalization_risk',
        'existing_post_id','existing_post_title','existing_post_status','existing_post_url',
        'duplicate_post_id','duplicate_post_title','duplicate_post_status','duplicate_post_url',
        'cluster_id','cluster','hub_primary_id','hub_primary','hub_secondary_id','hub_secondary',
        'category_id','category','category_url','related_products_json','variants_json'
    ), ';');

    foreach ($report['recommendations'] as $row) {
        $h = (array) ($row['hierarchy'] ?? array());
        $e = (array) ($row['existing_post'] ?? array());
        $d = (array) ($row['duplicate_post'] ?? array());
        $t = (array) ($row['trends'] ?? array());
        $g = (array) ($row['search_console'] ?? array());

        fputcsv($fh, array(
            $row['recommendation_type'] ?? '',
            $row['goal'] ?? '',
            $row['decision_code'] ?? '',
            $row['priority'] ?? 0,
            $row['authority_score'] ?? 0,
            $row['sales_score'] ?? 0,
            $row['demand_score'] ?? 0,
            $row['linkability_score'] ?? 0,
            $row['catalog_fit_score'] ?? 0,
            $row['topic_saturation'] ?? 0,
            $row['confidence'] ?? '',
            $row['topic'] ?? '',
            $row['suggested_title'] ?? '',
            $row['focus_keyword'] ?? '',
            $row['intent'] ?? '',
            $row['reason'] ?? '',
            $row['source'] ?? '',
            $t['score'] ?? '',
            $t['growth'] ?? '',
            !empty($t['breakout']) ? 1 : 0,
            implode(' | ', (array) ($t['seeds'] ?? array())),
            $g['impressions'] ?? '',
            $g['clicks'] ?? '',
            $g['ctr'] ?? '',
            $g['position'] ?? '',
            $g['top_page'] ?? '',
            $row['existing_similarity'] ?? '',
            $row['cannibalization_risk'] ?? '',
            $e['id'] ?? '',
            $e['title'] ?? '',
            $e['status'] ?? '',
            $e['url'] ?? '',
            $d['id'] ?? '',
            $d['title'] ?? '',
            $d['status'] ?? '',
            $d['url'] ?? '',
            $h['cluster_id'] ?? '',
            $h['cluster'] ?? '',
            $h['hub_primary_id'] ?? '',
            $h['hub_primary'] ?? '',
            $h['hub_secondary_id'] ?? '',
            $h['hub_secondary'] ?? '',
            $h['category_id'] ?? '',
            $h['category'] ?? '',
            $h['category_url'] ?? '',
            wp_json_encode((array) ($row['related_products'] ?? array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            wp_json_encode((array) ($row['variants'] ?? array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ), ';');
    }

    fclose($fh);
    exit;
}
