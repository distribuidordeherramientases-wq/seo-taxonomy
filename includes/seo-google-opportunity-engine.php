<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Motor central de decisiones de Google Intelligence.
 *
 * Convierte fuentes independientes en preguntas de negocio:
 * - qué landing o categoría potenciar;
 * - qué contenido crear o actualizar;
 * - qué producto, variante o familia investigar;
 * - qué tendencia vigilar;
 * - qué activos existentes muestran tracción.
 */
if (!defined('SEO_GOOGLE_OPPORTUNITY_ENGINE_VERSION')) {
    define('SEO_GOOGLE_OPPORTUNITY_ENGINE_VERSION', '2.1.0');
}

function seo_google_opportunity_normalize($text) {
    if (function_exists('seo_google_trends_normalize')) {
        return seo_google_trends_normalize($text);
    }
    $text = remove_accents(wp_strip_all_tags((string) $text));
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    return trim((string) preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/i', ' ', $text)));
}

function seo_google_opportunity_tokens($text) {
    if (function_exists('seo_google_trends_tokens')) {
        return seo_google_trends_tokens($text);
    }
    return array_values(array_filter(explode(' ', seo_google_opportunity_normalize($text))));
}

function seo_google_opportunity_similarity($left, $right) {
    $left_normalized = seo_google_opportunity_normalize($left);
    $right_normalized = seo_google_opportunity_normalize($right);
    if ($left_normalized === '' || $right_normalized === '') {
        return 0.0;
    }
    if ($left_normalized === $right_normalized) {
        return 1.0;
    }

    $left_tokens = seo_google_opportunity_tokens($left);
    $right_tokens = seo_google_opportunity_tokens($right);
    if (!$left_tokens || !$right_tokens) {
        return 0.0;
    }

    $shared = count(array_intersect($left_tokens, $right_tokens));
    $coverage = $shared / max(1, min(count($left_tokens), count($right_tokens)));
    $union = count(array_unique(array_merge($left_tokens, $right_tokens)));
    $jaccard = $shared / max(1, $union);
    $phrase = (
        false !== strpos(' ' . $left_normalized . ' ', ' ' . $right_normalized . ' ')
        || false !== strpos(' ' . $right_normalized . ' ', ' ' . $left_normalized . ' ')
    ) ? 0.12 : 0.0;

    return min(1.0, ($coverage * 0.72) + ($jaccard * 0.28) + $phrase);
}

function seo_google_opportunity_best_match($topic, array $index, $minimum = 0.0) {
    $best = array();
    $best_score = 0.0;
    foreach ($index as $row) {
        $search_text = (string) ($row['search_text'] ?? $row['title'] ?? '');
        $score = seo_google_opportunity_similarity($topic, $search_text);
        if ($score > $best_score) {
            $best_score = $score;
            $best = $row;
        }
    }
    if (!$best || $best_score < (float) $minimum) {
        return array();
    }
    $best['similarity'] = round($best_score, 4);
    return $best;
}

function seo_google_opportunity_classify_intent($topic, $signal_kind = '') {
    $normalized = ' ' . seo_google_opportunity_normalize($topic) . ' ';
    $tokens = seo_google_opportunity_tokens($topic);

    $news_terms = array(
        ' normativa ', ' obligatorio ', ' obligatoria ', ' ley ', ' regulacion ', ' homologacion ',
        ' prohibido ', ' prohibida ', ' retirada ', ' alerta ', ' lanzamiento ', ' entra en vigor ',
    );
    foreach ($news_terms as $term) {
        if (false !== strpos($normalized, $term)) {
            return 'actualidad_normativa';
        }
    }

    if ('emerging' === $signal_kind && preg_match('/\b(v|version|modelo)[ -]?[0-9]{1,4}\b/i', $normalized)) {
        return 'actualidad_producto';
    }

    $information_terms = array(
        ' como ', ' que es ', ' para que ', ' por que ', ' cuando ', ' guia ', ' consejos ', ' tipos ',
        ' diferencia ', ' diferencias ', ' versus ', ' vs ', ' comparar ', ' comparativa ', ' elegir ',
        ' reparar ', ' arreglar ', ' instalar ', ' usar ', ' mantenimiento ', ' error ', ' problema ',
    );
    foreach ($information_terms as $term) {
        if (false !== strpos($normalized, $term)) {
            return 'informativa';
        }
    }

    $commercial_terms = array(
        ' comprar ', ' precio ', ' precios ', ' oferta ', ' ofertas ', ' tienda ', ' proveedor ',
        ' distribuidor ', ' barato ', ' barata ', ' venta ', ' stock ',
    );
    foreach ($commercial_terms as $term) {
        if (false !== strpos($normalized, $term)) {
            return 'comercial';
        }
    }

    /*
     * La longitud no determina una intención informativa. Consultas de producto
     * como "nevera camion 24v bajo asiento" son long tail comerciales y no
     * deben desviarse automáticamente al informe de posts.
     */
    if (count($tokens) <= 3) {
        return 'producto_categoria';
    }
    return 'demanda_comercial';
}

function seo_google_opportunity_action_meta($code) {
    $map = array(
        'POTENCIAR_LANDING'    => array('label' => 'Potenciar landing', 'channel' => 'landing', 'tone' => 'blue'),
        'ESTUDIAR_LANDING'     => array('label' => 'Estudiar landing', 'channel' => 'landing', 'tone' => 'blue'),
        'POTENCIAR_CATEGORIA'  => array('label' => 'Potenciar categoría', 'channel' => 'catalog', 'tone' => 'blue'),
        'CREAR_POST'           => array('label' => 'Crear post', 'channel' => 'content', 'tone' => 'purple'),
        'ACTUALIZAR_POST'      => array('label' => 'Actualizar post', 'channel' => 'content', 'tone' => 'purple'),
        'CONSOLIDAR_CONTENIDO' => array('label' => 'Consolidar contenido', 'channel' => 'content', 'tone' => 'purple'),
        'LIMPIAR_DUPLICADO'    => array('label' => 'Limpiar duplicado', 'channel' => 'content', 'tone' => 'purple'),
        'PUBLICAR_PRODUCTO'    => array('label' => 'Priorizar publicación de producto', 'channel' => 'catalog', 'tone' => 'orange'),
        'AMPLIAR_PRODUCTOS'    => array('label' => 'Ampliar productos/variantes', 'channel' => 'catalog', 'tone' => 'orange'),
        'INVESTIGAR_PRODUCTO'  => array('label' => 'Investigar producto/variante', 'channel' => 'catalog', 'tone' => 'orange'),
        'ESTUDIAR_CATEGORIA'   => array('label' => 'Estudiar categoría', 'channel' => 'catalog', 'tone' => 'orange'),
        'INVESTIGAR_CATALOGO'  => array('label' => 'Investigar oportunidad de catálogo', 'channel' => 'catalog', 'tone' => 'orange'),
        'POTENCIAR_SEO'        => array('label' => 'Potenciar SEO, no surtido', 'channel' => 'seo', 'tone' => 'green'),
        'REVISAR_COBERTURA'    => array('label' => 'Revisar cobertura', 'channel' => 'seo', 'tone' => 'gray'),
        'VIGILAR_TENDENCIA'    => array('label' => 'Vigilar tendencia', 'channel' => 'watch', 'tone' => 'gray'),
    );

    return $map[$code] ?? array('label' => $code, 'channel' => 'watch', 'tone' => 'gray');
}

function seo_google_opportunity_confidence(array $sources, $priority, $exploratory = false) {
    $sources = array_values(array_unique(array_filter($sources)));
    if ($exploratory && count($sources) <= 2) {
        return 'EXPLORATORIA';
    }
    if (count($sources) >= 3 && $priority >= 68) {
        return 'ALTA';
    }
    if (count($sources) >= 2) {
        return 'MEDIA-ALTA';
    }
    return 'MEDIA';
}

function seo_google_opportunity_add(array &$rows, array $row) {
    $topic = sanitize_text_field((string) ($row['topic'] ?? ''));
    $action = sanitize_key((string) ($row['action'] ?? ''));
    if ($topic === '' || $action === '') {
        return;
    }

    $meta = seo_google_opportunity_action_meta(strtoupper($action));
    $action = strtoupper($action);
    $row['topic'] = $topic;
    $row['action'] = $action;
    $row['action_label'] = $meta['label'];
    $row['channel'] = $meta['channel'];
    $row['priority'] = max(1, min(100, absint($row['priority'] ?? 1)));
    $row['sources'] = array_values(array_unique(array_filter((array) ($row['sources'] ?? array()))));
    $row['evidence'] = array_values(array_unique(array_filter((array) ($row['evidence'] ?? array()))));
    $row['confidence'] = $row['confidence'] ?? seo_google_opportunity_confidence(
        $row['sources'],
        $row['priority'],
        !empty($row['exploratory'])
    );
    $row['intent'] = (string) ($row['intent'] ?? '');
    $row['reason'] = sanitize_textarea_field((string) ($row['reason'] ?? ''));
    $row['target'] = is_array($row['target'] ?? null) ? $row['target'] : array();
    $row['metrics'] = is_array($row['metrics'] ?? null) ? $row['metrics'] : array();
    $row['market'] = is_array($row['market'] ?? null) ? $row['market'] : array();
    $row['catalog'] = is_array($row['catalog'] ?? null) ? $row['catalog'] : array();

    $key = $row['channel'] . '|' . $action . '|' . seo_google_opportunity_normalize($topic);
    if (!isset($rows[$key])) {
        $rows[$key] = $row;
        return;
    }

    $existing = $rows[$key];
    if ($row['priority'] > $existing['priority']) {
        $existing['priority'] = $row['priority'];
        if ($row['reason'] !== '') {
            $existing['reason'] = $row['reason'];
        }
    }
    $existing['sources'] = array_values(array_unique(array_merge($existing['sources'], $row['sources'])));
    $existing['evidence'] = array_values(array_unique(array_merge($existing['evidence'], $row['evidence'])));
    $existing['metrics'] = array_merge($existing['metrics'], $row['metrics']);
    $existing['market'] = array_merge($existing['market'], $row['market']);
    $existing['catalog'] = array_merge($existing['catalog'], $row['catalog']);
    if (empty($existing['target']) && !empty($row['target'])) {
        $existing['target'] = $row['target'];
    }
    $existing['confidence'] = seo_google_opportunity_confidence(
        $existing['sources'],
        $existing['priority'],
        !empty($existing['exploratory']) && !empty($row['exploratory'])
    );
    $rows[$key] = $existing;
}

function seo_google_opportunity_landing_index() {
    static $cache = null;
    if (null !== $cache) {
        return $cache;
    }

    $cache = array();
    if (!function_exists('seo_landing_get_existing')) {
        return $cache;
    }
    foreach ((array) seo_landing_get_existing() as $landing) {
        $title = (string) ($landing->post_title ?? '');
        $id = absint($landing->ID ?? 0);
        if ($title === '' || !$id) {
            continue;
        }
        $cache[] = array(
            'id'          => $id,
            'title'       => $title,
            'url'         => (string) get_permalink($id),
            'status'      => (string) ($landing->post_status ?? ''),
            'views_30d'   => (int) ($landing->views_30d ?? 0),
            'search_text' => $title,
        );
    }
    return $cache;
}

function seo_google_opportunity_post_index() {
    static $cache = null;
    if (null !== $cache) {
        return $cache;
    }

    $cache = array();
    if (!function_exists('seo_post_opportunities_get_posts')) {
        return $cache;
    }
    foreach ((array) seo_post_opportunities_get_posts() as $post) {
        $cache[] = array(
            'id'          => absint($post['id'] ?? 0),
            'title'       => (string) ($post['title'] ?? ''),
            'url'         => (string) ($post['url'] ?? ''),
            'status'      => (string) ($post['status'] ?? ''),
            'search_text' => trim((string) ($post['title'] ?? '') . ' ' . (string) ($post['focus_keyword'] ?? '')),
        );
    }
    return $cache;
}

function seo_google_opportunity_product_index() {
    static $cache = null;
    if (null !== $cache) {
        return $cache;
    }

    global $wpdb;
    $cache = array();
    $rows = $wpdb->get_results(
        "SELECT ID, post_title, post_name
         FROM {$wpdb->posts}
         WHERE post_type = 'product'
           AND post_status = 'publish'
         ORDER BY ID DESC
         LIMIT 6000",
        ARRAY_A
    );
    foreach ((array) $rows as $row) {
        $id = absint($row['ID'] ?? 0);
        $search_text = trim(
            (string) ($row['post_title'] ?? '')
            . ' '
            . str_replace('-', ' ', (string) ($row['post_name'] ?? ''))
        );
        $cache[] = array(
            'id'          => $id,
            'title'       => (string) ($row['post_title'] ?? ''),
            'url'         => (string) get_permalink($id),
            'search_text' => $search_text,
            'tokens'      => seo_google_opportunity_tokens($search_text),
        );
    }
    return $cache;
}

/**
 * Productos existentes que todavía no están publicados. Permite distinguir
 * "nos falta el producto" de "ya lo tenemos pero falta publicarlo".
 */
function seo_google_opportunity_pending_product_index() {
    static $cache = null;
    if (null !== $cache) {
        return $cache;
    }

    global $wpdb;
    $cache = array();
    $rows = $wpdb->get_results(
        "SELECT ID, post_title, post_name, post_status
         FROM {$wpdb->posts}
         WHERE post_type = 'product'
           AND post_status IN ('draft','pending','private','future')
         ORDER BY ID DESC
         LIMIT 6000",
        ARRAY_A
    );
    foreach ((array) $rows as $row) {
        $id = absint($row['ID'] ?? 0);
        $search_text = trim(
            (string) ($row['post_title'] ?? '')
            . ' '
            . str_replace('-', ' ', (string) ($row['post_name'] ?? ''))
        );
        if (!$id || $search_text === '') {
            continue;
        }
        $cache[] = array(
            'id'          => $id,
            'title'       => (string) ($row['post_title'] ?? ''),
            'url'         => (string) get_edit_post_link($id, 'raw'),
            'status'      => (string) ($row['post_status'] ?? ''),
            'search_text' => $search_text,
            'tokens'      => seo_google_opportunity_tokens($search_text),
        );
    }
    return $cache;
}

function seo_google_opportunity_pending_product_coverage($topic) {
    $topic_tokens = seo_google_opportunity_tokens($topic);
    $products = seo_google_opportunity_pending_product_index();
    if (!$topic_tokens || !$products) {
        return array('covered' => false, 'similarity' => 0.0, 'coverage' => 0.0, 'match' => array());
    }

    $candidate_scores = array();
    foreach ($products as $index => $product) {
        $shared = count(array_intersect($topic_tokens, (array) ($product['tokens'] ?? array())));
        if ($shared > 0) {
            $candidate_scores[$index] = $shared;
        }
    }
    if (!$candidate_scores) {
        return array('covered' => false, 'similarity' => 0.0, 'coverage' => 0.0, 'match' => array());
    }
    arsort($candidate_scores, SORT_NUMERIC);
    $candidates = array();
    foreach (array_slice(array_keys($candidate_scores), 0, 300) as $index) {
        if (isset($products[$index])) {
            $candidates[] = $products[$index];
        }
    }
    $match = seo_google_opportunity_best_match($topic, $candidates, 0.45);
    if (!$match) {
        return array('covered' => false, 'similarity' => 0.0, 'coverage' => 0.0, 'match' => array());
    }
    $match_tokens = (array) ($match['tokens'] ?? seo_google_opportunity_tokens($match['search_text'] ?? ''));
    $shared = count(array_intersect($topic_tokens, $match_tokens));
    $coverage = $shared / max(1, count($topic_tokens));
    $covered = (
        (float) $match['similarity'] >= 0.76
        && ($shared >= 2 || count($topic_tokens) === 1)
        && $coverage >= 0.70
    );
    return array(
        'covered'    => $covered,
        'similarity' => (float) $match['similarity'],
        'coverage'   => round($coverage, 4),
        'match'      => $match,
    );
}

/**
 * Reduce el conjunto de productos antes de calcular similitud. El índice
 * invertido evita comparar cada señal de mercado con miles de títulos.
 */
function seo_google_opportunity_product_candidates($topic, $limit = 450) {
    static $token_map = null;
    $products = seo_google_opportunity_product_index();
    if (!$products) {
        return array();
    }

    if (null === $token_map) {
        $token_map = array();
        foreach ($products as $index => $product) {
            foreach ((array) ($product['tokens'] ?? array()) as $token) {
                if (!isset($token_map[$token])) {
                    $token_map[$token] = array();
                }
                $token_map[$token][] = $index;
            }
        }
    }

    $candidate_scores = array();
    foreach (seo_google_opportunity_tokens($topic) as $token) {
        foreach ((array) ($token_map[$token] ?? array()) as $index) {
            $candidate_scores[$index] = (int) ($candidate_scores[$index] ?? 0) + 1;
        }
    }
    if (!$candidate_scores) {
        return array();
    }

    arsort($candidate_scores, SORT_NUMERIC);
    $out = array();
    foreach (array_keys($candidate_scores) as $index) {
        if (!isset($products[$index])) {
            continue;
        }
        $out[] = $products[$index];
        if (count($out) >= max(25, min(1000, absint($limit)))) {
            break;
        }
    }
    return $out;
}

function seo_google_opportunity_product_coverage($topic) {
    $topic_tokens = seo_google_opportunity_tokens($topic);
    if (!$topic_tokens) {
        return array('covered' => false, 'similarity' => 0.0, 'coverage' => 0.0, 'match' => array());
    }

    $candidates = seo_google_opportunity_product_candidates($topic);
    $match = seo_google_opportunity_best_match($topic, $candidates, 0.45);
    if (!$match) {
        return array('covered' => false, 'similarity' => 0.0, 'coverage' => 0.0, 'match' => array());
    }

    $match_tokens = !empty($match['tokens']) && is_array($match['tokens'])
        ? $match['tokens']
        : seo_google_opportunity_tokens($match['search_text'] ?? '');
    $shared = count(array_intersect($topic_tokens, $match_tokens));
    $coverage = $shared / max(1, count($topic_tokens));
    $covered = (
        (float) $match['similarity'] >= 0.78
        && ($shared >= 2 || count($topic_tokens) === 1)
        && $coverage >= 0.72
    );

    return array(
        'covered'    => $covered,
        'similarity' => (float) $match['similarity'],
        'coverage'   => round($coverage, 4),
        'match'      => $match,
    );
}

function seo_google_opportunity_catalog_context($topic, array $seeds = array(), $term_id = 0) {
    $term_id = absint($term_id);
    if ($term_id && taxonomy_exists('product_cat')) {
        $term = get_term($term_id, 'product_cat');
        if ($term && !is_wp_error($term)) {
            $link = get_term_link($term);
            return array(
                'term_id'       => $term_id,
                'category'      => (string) $term->name,
                'category_url'  => is_wp_error($link) ? '' : (string) $link,
                'product_count' => (int) $term->count,
                'match_score'   => 1.0,
            );
        }
    }

    if (function_exists('seo_post_opportunities_catalog_context')) {
        $context = (array) seo_post_opportunities_catalog_context($topic, $seeds, '');
        if ($context) {
            if (empty($context['term_id']) && !empty($context['category_id'])) {
                $context['term_id'] = absint($context['category_id']);
            }
            if (empty($context['category']) && !empty($context['category_name'])) {
                $context['category'] = (string) $context['category_name'];
            }
        }
        return $context;
    }

    return array();
}

function seo_google_opportunity_market_match($label, array $market) {
    $best = array();
    $best_similarity = 0.0;
    foreach ($market as $row) {
        if ('discovery' === (string) ($row['signal_kind'] ?? '')) {
            continue;
        }
        $similarity = seo_google_opportunity_similarity($label, $row['query'] ?? '');
        foreach ((array) ($row['seeds'] ?? array()) as $seed) {
            $similarity = max($similarity, seo_google_opportunity_similarity($label, $seed) * 0.94);
        }
        if ($similarity > $best_similarity) {
            $best_similarity = $similarity;
            $best = $row;
        }
    }

    if (!$best || $best_similarity < 0.48) {
        return array();
    }
    $best['similarity'] = round($best_similarity, 4);
    return $best;
}

function seo_google_opportunity_source_status() {
    if (function_exists('seo_landing_google_source_status')) {
        return seo_landing_google_source_status();
    }

    $gsc_connected = function_exists('seo_google_connection_status') && 'connected' === seo_google_connection_status();
    $trends_status = function_exists('seo_google_trends_provider_status')
        ? seo_google_trends_provider_status()
        : array();

    return array(
        'search_console' => array('connected' => $gsc_connected, 'detail' => $gsc_connected ? 'Conectado' : 'No conectado'),
        'analytics'      => array('connected' => false, 'detail' => 'No comprobado'),
        'trends'         => array(
            'connected' => !empty($trends_status['overall']['connected']),
            'detail'    => (string) ($trends_status['overall']['detail'] ?? 'No comprobado'),
        ),
    );
}

function seo_google_opportunity_evidence_from_item(array $item) {
    $evidence = array();
    foreach (array_slice((array) ($item['evidence'] ?? array()), 0, 4) as $row) {
        $query = sanitize_text_field((string) ($row['query'] ?? ''));
        if ($query !== '') {
            $evidence[] = $query;
        }
    }
    return $evidence;
}

function seo_google_opportunity_add_demand_rows(array &$rows, array $guidance, array $market, array &$matched_market) {
    $landings = seo_google_opportunity_landing_index();
    $posts = seo_google_opportunity_post_index();

    foreach ((array) ($guidance['items'] ?? array()) as $item) {
        if ('corporate' === (string) ($item['catalog_relevance'] ?? '')) {
            continue;
        }

        $topic = sanitize_text_field((string) ($item['label'] ?? ''));
        if ($topic === '') {
            continue;
        }

        $kind = (string) ($item['kind'] ?? 'Intencion');
        $intent = seo_google_opportunity_classify_intent($topic, 'captured');
        $market_match = seo_google_opportunity_market_match($topic, $market);
        if ($market_match) {
            $matched_market[seo_google_opportunity_normalize($market_match['query'] ?? '')] = true;
        }

        $search_score = max(0, min(100, (float) ($item['score'] ?? 0)));
        $market_score = max(0, min(100, (float) ($market_match['score'] ?? 0)));
        $position = (float) ($item['position'] ?? 0);
        $impressions = (float) ($item['impressions'] ?? 0);
        $term_id = absint($item['term_id'] ?? 0);
        $context = seo_google_opportunity_catalog_context(
            $topic,
            (array) ($market_match['seeds'] ?? array()),
            $term_id
        );
        $products = null !== ($item['products'] ?? null)
            ? (int) $item['products']
            : (int) ($context['product_count'] ?? 0);
        $landing_match = seo_google_opportunity_best_match($topic, $landings, 0.52);
        $post_match = seo_google_opportunity_best_match($topic, $posts, 0.62);
        $position_need = $position > 0 ? min(100, max(0, ($position - 3) * 3.0)) : 45;
        $priority = (int) round(($search_score * 0.53) + ($market_score * 0.25) + ($position_need * 0.14) + (min(100, log(1 + max(0, $impressions)) * 12) * 0.08));
        $sources = array('Search Console', 'Catálogo');
        if ($market_match) {
            $sources[] = 'Google Trends';
        }
        $evidence = seo_google_opportunity_evidence_from_item($item);
        $metrics = array(
            'impressions' => $impressions,
            'position'    => $position,
            'search_score'=> round($search_score, 1),
            'market_score'=> round($market_score, 1),
        );
        $catalog = array(
            'products' => $products,
            'category' => (string) ($context['category'] ?? ''),
            'term_id'  => absint($context['term_id'] ?? $term_id),
        );

        if (in_array($intent, array('informativa', 'informativa_probable', 'actualidad_normativa'), true)) {
            $action = $post_match ? 'ACTUALIZAR_POST' : 'CREAR_POST';
            seo_google_opportunity_add($rows, array(
                'topic'      => $topic,
                'action'     => $action,
                'priority'   => max(45, min(95, $priority)),
                'intent'     => $intent,
                'sources'    => $sources,
                'evidence'   => $evidence,
                'metrics'    => $metrics,
                'catalog'    => $catalog,
                'market'     => $market_match,
                'target'     => $post_match,
                'reason'     => $post_match
                    ? 'La consulta informativa ya tiene un post relacionado, pero mantiene demanda y margen de mejora en Google.'
                    : 'Existe demanda informativa capturada por Google y no se ha localizado un post suficientemente equivalente.',
            ));
            continue;
        }

        if ('Categoria' === $kind) {
            if ($products <= 5 && ($search_score >= 45 || $market_score >= 55)) {
                seo_google_opportunity_add($rows, array(
                    'topic'    => $topic,
                    'action'   => 'AMPLIAR_PRODUCTOS',
                    'priority' => max(45, min(96, $priority + 10)),
                    'intent'   => $intent,
                    'sources'  => $sources,
                    'evidence' => $evidence,
                    'metrics'  => $metrics,
                    'catalog'  => $catalog,
                    'market'   => $market_match,
                    'target'   => array('id' => $term_id, 'title' => $topic, 'url' => (string) ($context['category_url'] ?? '')),
                    'reason'   => 'La categoría recibe demanda, pero su profundidad de producto es baja. Conviene revisar variantes y surtido antes de crear nuevas estructuras.',
                ));
            } elseif ($position >= 11 || $search_score >= 65 || $market_score >= 65) {
                seo_google_opportunity_add($rows, array(
                    'topic'    => $topic,
                    'action'   => 'POTENCIAR_CATEGORIA',
                    'priority' => max(42, min(94, $priority)),
                    'intent'   => $intent,
                    'sources'  => $sources,
                    'evidence' => $evidence,
                    'metrics'  => $metrics,
                    'catalog'  => $catalog,
                    'market'   => $market_match,
                    'target'   => array('id' => $term_id, 'title' => $topic, 'url' => (string) ($context['category_url'] ?? '')),
                    'reason'   => 'La categoría ya dispone de catálogo, pero Google muestra demanda y todavía existe margen orgánico. Priorizar contenido, enlazado y cobertura semántica.',
                ));
            }
            continue;
        }

        if ($landing_match) {
            seo_google_opportunity_add($rows, array(
                'topic'    => $topic,
                'action'   => 'POTENCIAR_LANDING',
                'priority' => max(45, min(96, $priority + 4)),
                'intent'   => $intent,
                'sources'  => array_merge($sources, array('WordPress')),
                'evidence' => $evidence,
                'metrics'  => $metrics,
                'catalog'  => $catalog,
                'market'   => $market_match,
                'target'   => $landing_match,
                'reason'   => 'Ya existe una landing relacionada. La demanda debe concentrarse en esa URL antes de plantear una página nueva.',
            ));
        } elseif ($products >= 8) {
            seo_google_opportunity_add($rows, array(
                'topic'       => $topic,
                'action'      => 'ESTUDIAR_LANDING',
                'priority'    => max(40, min(92, $priority)),
                'intent'      => $intent,
                'sources'     => $sources,
                'evidence'    => $evidence,
                'metrics'     => $metrics,
                'catalog'     => $catalog,
                'market'      => $market_match,
                'exploratory' => !$market_match,
                'reason'      => 'La intención tiene demanda y catálogo suficiente, pero no se ha localizado una landing equivalente. Debe validarse diferenciación y destino estable antes de crearla.',
            ));
        } elseif ($products > 0) {
            seo_google_opportunity_add($rows, array(
                'topic'    => $topic,
                'action'   => 'AMPLIAR_PRODUCTOS',
                'priority' => max(38, min(90, $priority)),
                'intent'   => $intent,
                'sources'  => $sources,
                'evidence' => $evidence,
                'metrics'  => $metrics,
                'catalog'  => $catalog,
                'market'   => $market_match,
                'reason'   => 'Hay encaje con una categoría actual, pero la oferta parece demasiado corta para sostener una landing específica.',
            ));
        } elseif ($market_score >= 58) {
            seo_google_opportunity_add($rows, array(
                'topic'       => $topic,
                'action'      => 'INVESTIGAR_CATALOGO',
                'priority'    => max(42, min(90, $priority)),
                'intent'      => $intent,
                'sources'     => $sources,
                'evidence'    => $evidence,
                'metrics'     => $metrics,
                'market'      => $market_match,
                'exploratory' => true,
                'reason'      => 'Google muestra demanda y Trends aporta confirmación, pero no existe cobertura clara en el catálogo. Investigar producto y viabilidad antes de crear una categoría.',
            ));
        } else {
            seo_google_opportunity_add($rows, array(
                'topic'       => $topic,
                'action'      => 'REVISAR_COBERTURA',
                'priority'    => max(30, min(76, $priority)),
                'intent'      => $intent,
                'sources'     => $sources,
                'evidence'    => $evidence,
                'metrics'     => $metrics,
                'catalog'     => $catalog,
                'exploratory' => true,
                'reason'      => 'La consulta existe en Search Console, pero todavía no hay evidencia suficiente para decidir entre landing, categoría o contenido.',
            ));
        }
    }
}

function seo_google_opportunity_add_market_rows(array &$rows, array $market, array $matched_market) {
    $landings = seo_google_opportunity_landing_index();
    $posts = seo_google_opportunity_post_index();

    foreach (array_slice($market, 0, 400) as $signal) {
        $topic = sanitize_text_field((string) ($signal['query'] ?? ''));
        $key = seo_google_opportunity_normalize($topic);
        if ($topic === '' || isset($matched_market[$key])) {
            continue;
        }

        $signal_kind = (string) ($signal['signal_kind'] ?? 'market');
        $intent = seo_google_opportunity_classify_intent($topic, $signal_kind);
        $score = max(0, min(100, (float) ($signal['score'] ?? 0)));
        if ($score < 42) {
            continue;
        }

        $context = seo_google_opportunity_catalog_context($topic, (array) ($signal['seeds'] ?? array()), 0);
        $products = (int) ($context['product_count'] ?? 0);
        $product_coverage = seo_google_opportunity_product_coverage($topic);
        $pending_product_coverage = seo_google_opportunity_pending_product_coverage($topic);
        $landing_match = seo_google_opportunity_best_match($topic, $landings, 0.54);
        $post_match = seo_google_opportunity_best_match($topic, $posts, 0.62);
        $sources = array('Google Trends');
        if ($context) {
            $sources[] = 'Catálogo';
        }
        if ($landing_match || $post_match) {
            $sources[] = 'WordPress';
        }
        if (!empty($pending_product_coverage['covered'])) {
            $sources[] = 'Inventario de productos';
        }
        $market_data = array(
            'score'           => round($score, 1),
            'signal_kind'     => $signal_kind,
            'traffic'         => (float) ($signal['traffic'] ?? 0),
            'traffic_label'   => (string) ($signal['traffic_label'] ?? ''),
            'growth'          => (float) ($signal['max_growth'] ?? 0),
            'breakout'        => !empty($signal['breakout']),
            'observed_at'     => (string) ($signal['observed_at'] ?? ''),
            'providers'       => array_values((array) ($signal['providers'] ?? array())),
            'matched_seeds'   => array_values((array) ($signal['seeds'] ?? array())),
            'interest_index'  => (float) ($signal['interest_index'] ?? 0),
            'interest_change_pct' => (float) ($signal['interest_change_pct'] ?? 0),
            'interest_average'=> (float) ($signal['interest_average'] ?? 0),
        );
        $catalog = array(
            'category'      => (string) ($context['category'] ?? ''),
            'term_id'       => absint($context['term_id'] ?? 0),
            'products'      => $products,
            'product_match' => (string) ($product_coverage['match']['title'] ?? ''),
            'product_covered' => !empty($product_coverage['covered']),
            'pending_product_match' => (string) ($pending_product_coverage['match']['title'] ?? ''),
            'pending_product_status' => (string) ($pending_product_coverage['match']['status'] ?? ''),
            'pending_product_covered' => !empty($pending_product_coverage['covered']),
        );
        $base_priority = (int) round(35 + ($score * 0.55));
        $is_news = in_array($intent, array('actualidad_normativa', 'actualidad_producto'), true);
        $is_information = in_array($intent, array('informativa', 'informativa_probable'), true);

        if ($is_news || $is_information) {
            seo_google_opportunity_add($rows, array(
                'topic'       => $topic,
                'action'      => $post_match ? 'ACTUALIZAR_POST' : 'CREAR_POST',
                'priority'    => min(96, $base_priority + ($is_news ? 9 : 2)),
                'intent'      => $intent,
                'sources'     => $sources,
                'evidence'    => array_values((array) ($signal['seeds'] ?? array())),
                'market'      => $market_data,
                'catalog'     => $catalog,
                'target'      => $post_match,
                'exploratory' => true,
                'reason'      => $post_match
                    ? 'El tema está creciendo o aparece en actualidad y ya existe un post relacionado. Revisar vigencia, enfoque y actualización.'
                    : 'El radar externo ha detectado un tema informativo o de actualidad relacionado con el negocio que todavía no tiene un post equivalente.',
            ));
        }

        if (empty($product_coverage['covered']) && !empty($pending_product_coverage['covered'])) {
            $pending_match = (array) ($pending_product_coverage['match'] ?? array());
            seo_google_opportunity_add($rows, array(
                'topic'       => $topic,
                'action'      => 'PUBLICAR_PRODUCTO',
                'priority'    => min(97, $base_priority + 10),
                'intent'      => $intent,
                'sources'     => $sources,
                'market'      => $market_data,
                'catalog'     => $catalog,
                'target'      => $pending_match,
                'exploratory' => false,
                'reason'      => 'Google Trends detecta demanda y ya existe un producto equivalente en WordPress, pero todavía no está publicado. Revisar ficha, stock y condiciones y priorizar su publicación antes de buscar un producto nuevo.',
            ));
            continue;
        }

        if ('actualidad_producto' === $intent && empty($product_coverage['covered'])) {
            seo_google_opportunity_add($rows, array(
                'topic'       => $topic,
                'action'      => 'INVESTIGAR_PRODUCTO',
                'priority'    => min(94, $base_priority + 5),
                'intent'      => $intent,
                'sources'     => $sources,
                'market'      => $market_data,
                'catalog'     => $catalog,
                'exploratory' => true,
                'reason'      => 'Aparece una versión, modelo o variante emergente y no se ha localizado un producto suficientemente equivalente. Confirmar realidad comercial, proveedor y normativa antes de incorporarlo.',
            ));
            continue;
        }

        if ($is_news || $is_information) {
            continue;
        }

        if (!empty($product_coverage['covered'])) {
            if ($landing_match) {
                seo_google_opportunity_add($rows, array(
                    'topic'    => $topic,
                    'action'   => 'POTENCIAR_LANDING',
                    'priority' => min(92, $base_priority),
                    'intent'   => $intent,
                    'sources'  => $sources,
                    'market'   => $market_data,
                    'catalog'  => $catalog,
                    'target'   => $landing_match,
                    'reason'   => 'La demanda externa está creciendo, existe producto y ya hay una landing relacionada. Concentrar la mejora en esa URL.',
                ));
            } elseif ($products >= 8) {
                seo_google_opportunity_add($rows, array(
                    'topic'       => $topic,
                    'action'      => 'ESTUDIAR_LANDING',
                    'priority'    => min(90, $base_priority),
                    'intent'      => $intent,
                    'sources'     => $sources,
                    'market'      => $market_data,
                    'catalog'     => $catalog,
                    'exploratory' => true,
                    'reason'      => 'La demanda externa tiene producto y categoría de apoyo, pero no se ha localizado una landing diferenciada.',
                ));
            } else {
                seo_google_opportunity_add($rows, array(
                    'topic'       => $topic,
                    'action'      => 'POTENCIAR_SEO',
                    'priority'    => min(86, $base_priority),
                    'intent'      => $intent,
                    'sources'     => $sources,
                    'market'      => $market_data,
                    'catalog'     => $catalog,
                    'exploratory' => true,
                    'reason'      => 'Existe producto equivalente, pero la cobertura estructural es limitada. Revisar primero la URL actual antes de abrir una nueva landing.',
                ));
            }
        } elseif ($context) {
            seo_google_opportunity_add($rows, array(
                'topic'       => $topic,
                'action'      => 'INVESTIGAR_PRODUCTO',
                'priority'    => min(94, $base_priority + 4),
                'intent'      => $intent,
                'sources'     => $sources,
                'market'      => $market_data,
                'catalog'     => $catalog,
                'exploratory' => true,
                'reason'      => 'La tendencia encaja en una familia actual, pero no se ha encontrado un producto que cubra suficientemente la consulta. Revisar variante, medida, tecnología o modelo.',
            ));
        } elseif ($score >= 68) {
            seo_google_opportunity_add($rows, array(
                'topic'       => $topic,
                'action'      => 'INVESTIGAR_CATALOGO',
                'priority'    => min(90, $base_priority),
                'intent'      => $intent,
                'sources'     => $sources,
                'market'      => $market_data,
                'exploratory' => true,
                'reason'      => 'Trends detecta una demanda relacionada con el perímetro comercial, pero no existe categoría ni producto claramente asociado. Investigar antes de modificar el catálogo.',
            ));
        } else {
            seo_google_opportunity_add($rows, array(
                'topic'       => $topic,
                'action'      => 'VIGILAR_TENDENCIA',
                'priority'    => min(78, $base_priority),
                'intent'      => $intent,
                'sources'     => $sources,
                'market'      => $market_data,
                'exploratory' => true,
                'reason'      => 'Es una señal externa relacionada, pero todavía no tiene suficiente cobertura o evidencia para justificar una acción estructural.',
            ));
        }
    }
}

function seo_google_opportunity_add_post_report_rows(array &$rows, $days) {
    if (!function_exists('seo_post_opportunities_build_report')) {
        return array();
    }

    $report = seo_post_opportunities_build_report($days);
    foreach ((array) ($report['recommendations'] ?? array()) as $recommendation) {
        $decision = (string) ($recommendation['decision_code'] ?? '');
        $action_map = array(
            'CREATE_POST'                  => 'CREAR_POST',
            'UPDATE_POST'                  => 'ACTUALIZAR_POST',
            'REVIEW_CONSOLIDATION'         => 'CONSOLIDAR_CONTENIDO',
            'DELETE_UNPUBLISHED_DUPLICATE' => 'LIMPIAR_DUPLICADO',
        );
        if (!isset($action_map[$decision])) {
            continue;
        }

        $hierarchy = (array) ($recommendation['hierarchy'] ?? array());
        $trends = (array) ($recommendation['trends'] ?? array());
        $gsc = (array) ($recommendation['search_console'] ?? array());
        $sources = array_filter(array_map('trim', explode('+', (string) ($recommendation['source'] ?? ''))));
        if (!$sources) {
            $sources = array('Inventario de posts');
        }

        seo_google_opportunity_add($rows, array(
            'topic'      => (string) ($recommendation['topic'] ?? $recommendation['suggested_title'] ?? ''),
            'action'     => $action_map[$decision],
            'priority'   => (int) ($recommendation['priority'] ?? 50),
            'intent'     => (string) ($recommendation['intent'] ?? ''),
            'sources'    => $sources,
            'evidence'   => array_values((array) ($recommendation['variants'] ?? array())),
            'reason'     => (string) ($recommendation['reason'] ?? ''),
            'target'     => (array) ($recommendation['existing_post'] ?? array()),
            'market'     => array(
                'score'    => (float) ($trends['score'] ?? 0),
                'growth'   => (float) ($trends['growth'] ?? 0),
                'breakout' => !empty($trends['breakout']),
            ),
            'metrics'    => array(
                'impressions' => (float) ($gsc['impressions'] ?? 0),
                'position'    => (float) ($gsc['position'] ?? 0),
            ),
            'catalog'    => array(
                'category' => (string) ($hierarchy['category'] ?? ''),
                'term_id'  => absint($hierarchy['category_id'] ?? 0),
            ),
        ));
    }

    return $report;
}

function seo_google_opportunity_build_results($days = 60) {
    $results = array('landings' => array(), 'posts' => array(), 'availability' => array());

    if (function_exists('seo_landing_get_existing')) {
        $ga4_map = function_exists('seo_landing_google_analytics_page_map')
            ? (array) seo_landing_google_analytics_page_map()
            : array();
        $gsc_map = function_exists('seo_landing_google_search_console_page_map')
            ? (array) seo_landing_google_search_console_page_map()
            : array();

        foreach ((array) seo_landing_get_existing() as $landing) {
            $id = absint($landing->ID ?? 0);
            if (!$id || 'publish' !== (string) ($landing->post_status ?? '')) {
                continue;
            }

            $url = (string) get_permalink($id);
            $ga4 = array();
            $gsc = array();
            if ($url !== '' && function_exists('seo_landing_google_path_key')) {
                $path_key = seo_landing_google_path_key($url);
                $ga4 = (array) ($ga4_map[$path_key] ?? array());
                $gsc = (array) ($gsc_map[$path_key] ?? array());
            } elseif (function_exists('seo_landing_google_metrics_for_page')) {
                $metrics = (array) seo_landing_google_metrics_for_page($id);
                $ga4 = (array) ($metrics['ga4'] ?? array());
                $gsc = (array) ($metrics['gsc'] ?? array());
            }

            $pageviews = (int) ($ga4['pageviews'] ?? $landing->views_30d ?? 0);
            $sessions = (int) ($ga4['sessions'] ?? 0);
            $impressions = (int) ($gsc['impressions'] ?? 0);
            $clicks = (int) ($gsc['clicks'] ?? 0);
            $position = (float) ($gsc['position'] ?? 0);

            if ($pageviews >= 30 || $clicks >= 5 || $impressions >= 250) {
                $assessment = 'CON TRACCIÓN';
            } elseif ($impressions >= 40 && $position >= 12) {
                $assessment = 'NECESITA POTENCIACIÓN';
            } else {
                $assessment = 'SIN EVIDENCIA SUFICIENTE';
            }

            $results['landings'][] = array(
                'id'          => $id,
                'title'       => (string) ($landing->post_title ?? ''),
                'url'         => $url,
                'assessment'  => $assessment,
                'pageviews'   => $pageviews,
                'sessions'    => $sessions,
                'clicks'      => $clicks,
                'impressions' => $impressions,
                'position'    => $position,
            );
        }
        usort($results['landings'], static function ($left, $right) {
            $left_score = ((int) $left['pageviews'] * 2) + (int) $left['impressions'];
            $right_score = ((int) $right['pageviews'] * 2) + (int) $right['impressions'];
            return $right_score <=> $left_score;
        });
    }

    if (
        function_exists('seo_post_opportunities_get_posts')
        && function_exists('seo_post_opportunities_performance')
    ) {
        $posts = seo_post_opportunities_get_posts();
        $performance = seo_post_opportunities_performance($posts, $days);
        foreach ((array) ($performance['rows'] ?? array()) as $row) {
            $pageviews = (int) ($row['pageviews'] ?? 0);
            $clicks = (int) ($row['clicks'] ?? 0);
            $impressions = (int) ($row['impressions'] ?? 0);
            $position = (float) ($row['position'] ?? 0);
            if ($pageviews >= 30 || $clicks >= 5 || $impressions >= 250) {
                $assessment = 'CON TRACCIÓN';
            } elseif ($impressions >= 40 && $position >= 12) {
                $assessment = 'ACTUALIZABLE';
            } else {
                $assessment = 'SIN EVIDENCIA SUFICIENTE';
            }
            $row['assessment'] = $assessment;
            $results['posts'][] = $row;
        }
        $results['availability'] = array(
            'analytics'      => !empty($performance['ga4']['available']),
            'search_console' => !empty($performance['gsc']['available']),
        );
    }

    return $results;
}

function seo_google_opportunity_build($days = 60, $include_results = false) {
    static $cache = array();
    $days = in_array((int) $days, array(28, 60, 90), true) ? (int) $days : 60;
    $cache_key = $days . '|' . ($include_results ? '1' : '0');
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    $sources = seo_google_opportunity_source_status();
    $market = function_exists('seo_google_trends_market_summary')
        ? (array) seo_google_trends_market_summary(700)
        : array();
    $guidance = array();
    if (
        function_exists('seo_google_get_settings')
        && function_exists('seo_google_connection_status')
        && 'connected' === seo_google_connection_status()
        && function_exists('seo_google_demand_get_catalog_guidance')
    ) {
        $settings = seo_google_get_settings();
        if (!empty($settings['property_id'])) {
            $guidance = (array) seo_google_demand_get_catalog_guidance($settings['property_id'], $days, 2, 80);
        }
    }

    $rows = array();
    $matched_market = array();
    seo_google_opportunity_add_demand_rows($rows, $guidance, $market, $matched_market);
    seo_google_opportunity_add_market_rows($rows, $market, $matched_market);
    $post_report = seo_google_opportunity_add_post_report_rows($rows, $days);

    $rows = array_values($rows);
    usort($rows, static function ($left, $right) {
        if ((int) $left['priority'] === (int) $right['priority']) {
            return strcmp((string) $left['action'], (string) $right['action']);
        }
        return (int) $right['priority'] <=> (int) $left['priority'];
    });

    $summary = array(
        'total'   => count($rows),
        'landing' => 0,
        'content' => 0,
        'catalog' => 0,
        'seo'     => 0,
        'watch'   => 0,
        'high'    => 0,
        'actions' => array(),
    );
    foreach ($rows as $row) {
        $channel = (string) ($row['channel'] ?? 'watch');
        if (isset($summary[$channel])) {
            $summary[$channel]++;
        }
        if ((int) $row['priority'] >= 75) {
            $summary['high']++;
        }
        $summary['actions'][$row['action']] = (int) ($summary['actions'][$row['action']] ?? 0) + 1;
    }

    $payload = array(
        'version'       => SEO_GOOGLE_OPPORTUNITY_ENGINE_VERSION,
        'generated_at'  => current_time('mysql'),
        'period_days'   => $days,
        'sources'       => $sources,
        'market'        => $market,
        'guidance'      => $guidance,
        'post_report'   => $post_report,
        'rows'          => $rows,
        'summary'       => $summary,
        'results'       => $include_results ? seo_google_opportunity_build_results($days) : array(),
    );

    $cache[$cache_key] = apply_filters('seo_google_opportunity_engine_payload', $payload, $days, $include_results);
    return $cache[$cache_key];
}

/**
 * URL del JSON compartible de decisiones.
 *
 * El fichero exportado contiene resultados y evidencias útiles para decidir,
 * nunca credenciales OAuth, secretos, tokens ni configuración técnica.
 */
function seo_google_opportunity_json_export_url($days = 60) {
    $days = in_array((int) $days, array(28, 60, 90), true) ? (int) $days : 60;

    return wp_nonce_url(
        add_query_arg(
            array(
                'action' => 'seo_google_export_decisions_json',
                'days'   => $days,
            ),
            admin_url('admin-post.php')
        ),
        'seo_google_export_decisions_json'
    );
}

/**
 * Determina si una decisión está respaldada por una señal de Google.
 */
function seo_google_opportunity_is_google_backed_row(array $row) {
    foreach ((array) ($row['sources'] ?? array()) as $source) {
        $normalized = seo_google_opportunity_normalize($source);
        if (
            false !== strpos($normalized, 'search console')
            || false !== strpos($normalized, 'google trends')
            || 'trends' === $normalized
        ) {
            return true;
        }
    }

    $metrics = (array) ($row['metrics'] ?? array());
    $market = (array) ($row['market'] ?? array());

    return !empty($metrics['impressions'])
        || !empty($metrics['position'])
        || !empty($metrics['search_score'])
        || !empty($market['score'])
        || !empty($market['traffic'])
        || !empty($market['growth'])
        || !empty($market['breakout']);
}

/**
 * Reduce una decisión al contrato JSON compartible.
 *
 * Se separa expresamente la evidencia Google de la cobertura interna usada
 * para interpretar esa evidencia. No se exportan IDs internos ni ajustes.
 */
function seo_google_opportunity_export_action_row(array $row) {
    $metrics = (array) ($row['metrics'] ?? array());
    $market = (array) ($row['market'] ?? array());
    $catalog = (array) ($row['catalog'] ?? array());
    $target = (array) ($row['target'] ?? array());

    $search_console = array_filter(
        array(
            'impressions'  => isset($metrics['impressions']) ? (float) $metrics['impressions'] : null,
            'position'     => isset($metrics['position']) ? (float) $metrics['position'] : null,
            'search_score' => isset($metrics['search_score']) ? (float) $metrics['search_score'] : null,
        ),
        static function ($value) {
            return null !== $value;
        }
    );

    $trends = array_filter(
        array(
            'score'         => isset($market['score']) ? (float) $market['score'] : null,
            'signal_kind'   => (string) ($market['signal_kind'] ?? ''),
            'traffic'       => isset($market['traffic']) ? (float) $market['traffic'] : null,
            'traffic_label' => (string) ($market['traffic_label'] ?? ''),
            'growth'        => isset($market['growth']) ? (float) $market['growth'] : null,
            'breakout'      => !empty($market['breakout']),
            'observed_at'   => (string) ($market['observed_at'] ?? ''),
            'providers'     => array_values((array) ($market['providers'] ?? array())),
            'matched_seeds' => array_values((array) ($market['matched_seeds'] ?? array())),
        ),
        static function ($value) {
            return '' !== $value && null !== $value && array() !== $value;
        }
    );

    $catalog_context = array_filter(
        array(
            'category'        => (string) ($catalog['category'] ?? ''),
            'products'        => isset($catalog['products']) ? (int) $catalog['products'] : null,
            'product_match'   => (string) ($catalog['product_match'] ?? ''),
            'product_covered' => isset($catalog['product_covered']) ? (bool) $catalog['product_covered'] : null,
        ),
        static function ($value) {
            return '' !== $value && null !== $value;
        }
    );

    $target_context = array_filter(
        array(
            'title'      => (string) ($target['title'] ?? ''),
            'url'        => (string) ($target['url'] ?? ''),
            'similarity' => isset($target['similarity']) ? round((float) $target['similarity'], 4) : null,
        ),
        static function ($value) {
            return '' !== $value && null !== $value;
        }
    );

    return array(
        'topic'       => (string) ($row['topic'] ?? ''),
        'decision'    => array(
            'code'       => (string) ($row['action'] ?? ''),
            'label'      => (string) ($row['action_label'] ?? ''),
            'channel'    => (string) ($row['channel'] ?? ''),
            'priority'   => (int) ($row['priority'] ?? 0),
            'confidence' => (string) ($row['confidence'] ?? ''),
            'intent'     => (string) ($row['intent'] ?? ''),
            'reason'     => (string) ($row['reason'] ?? ''),
        ),
        'google_evidence' => array_filter(
            array(
                'search_console' => $search_console,
                'trends'         => $trends,
                'queries'        => array_values((array) ($row['evidence'] ?? array())),
            ),
            static function ($value) {
                return array() !== $value;
            }
        ),
        'coverage_context' => array_filter(
            array(
                'catalog'        => $catalog_context,
                'current_target' => $target_context,
            ),
            static function ($value) {
                return array() !== $value;
            }
        ),
    );
}

/**
 * Reduce una señal de mercado a los campos necesarios para compartirla.
 */
function seo_google_opportunity_export_market_row(array $row) {
    return array_filter(
        array(
            'query'           => (string) ($row['query'] ?? ''),
            'signal_kind'     => (string) ($row['signal_kind'] ?? ''),
            'score'           => isset($row['score']) ? (float) $row['score'] : null,
            'growth'          => isset($row['max_growth']) ? (float) $row['max_growth'] : null,
            'breakout'        => !empty($row['breakout']),
            'traffic'         => isset($row['traffic']) ? (float) $row['traffic'] : null,
            'traffic_label'   => (string) ($row['traffic_label'] ?? ''),
            'relevance_score' => isset($row['relevance_score']) ? (float) $row['relevance_score'] : null,
            'observed_at'     => (string) ($row['observed_at'] ?? ''),
            'providers'       => array_values((array) ($row['providers'] ?? array())),
            'related_to'      => array_values((array) ($row['seeds'] ?? array())),
            'interest_index'  => isset($row['interest_index']) ? (float) $row['interest_index'] : null,
            'interest_change_pct' => isset($row['interest_change_pct']) ? (float) $row['interest_change_pct'] : null,
            'interest_average'=> isset($row['interest_average']) ? (float) $row['interest_average'] : null,
            'source_note'     => (string) ($row['source_note'] ?? ''),
        ),
        static function ($value) {
            return '' !== $value && null !== $value && array() !== $value;
        }
    );
}

/**
 * Contrato JSON para compartir resultados de Google y decisiones derivadas.
 *
 * No incluye estado de conexiones, Client ID, secretos, tokens, service
 * accounts ni el volcado técnico del sistema.
 */
function seo_google_opportunity_export_payload($days = 60) {
    $days = in_array((int) $days, array(28, 60, 90), true) ? (int) $days : 60;
    $payload = seo_google_opportunity_build($days, false);

    $actions = array();
    foreach ((array) ($payload['rows'] ?? array()) as $row) {
        if (!is_array($row) || !seo_google_opportunity_is_google_backed_row($row)) {
            continue;
        }
        $actions[] = seo_google_opportunity_export_action_row($row);
    }

    $market_signals = array();
    foreach ((array) ($payload['market'] ?? array()) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $market_signals[] = seo_google_opportunity_export_market_row($row);
    }

    $summary = array(
        'actions_total'  => count($actions),
        'priority_high'  => 0,
        'market_signals' => count($market_signals),
        'by_channel'     => array(),
        'by_action'      => array(),
    );
    foreach ($actions as $row) {
        $decision = (array) ($row['decision'] ?? array());
        $channel = (string) ($decision['channel'] ?? '');
        $action = (string) ($decision['code'] ?? '');
        if ((int) ($decision['priority'] ?? 0) >= 75) {
            $summary['priority_high']++;
        }
        if ($channel !== '') {
            $summary['by_channel'][$channel] = (int) ($summary['by_channel'][$channel] ?? 0) + 1;
        }
        if ($action !== '') {
            $summary['by_action'][$action] = (int) ($summary['by_action'][$action] ?? 0) + 1;
        }
    }
    ksort($summary['by_channel']);
    ksort($summary['by_action']);

    return array(
        'schema'         => 'seo_google_intelligence_decisions',
        'schema_version' => '1.0',
        'engine_version' => SEO_GOOGLE_OPPORTUNITY_ENGINE_VERSION,
        'generated_at'   => current_time('mysql'),
        'site'           => home_url('/'),
        'period_days'    => $days,
        'purpose'        => 'Resultados de Google y acciones recomendadas para priorizar SEO, contenido y catálogo.',
        'privacy'        => array(
            'credentials_included'            => false,
            'tokens_included'                 => false,
            'technical_configuration_included'=> false,
        ),
        'summary'        => $summary,
        'actions'        => $actions,
        'market_signals' => $market_signals,
    );
}

function seo_google_opportunity_filter_rows(array $rows, array $channels) {
    return array_values(array_filter($rows, static function ($row) use ($channels) {
        return in_array((string) ($row['channel'] ?? ''), $channels, true);
    }));
}

function seo_google_opportunity_badge($text, $tone = 'gray') {
    $tones = array(
        'blue'   => array('#e9f2fb', '#135e96'),
        'purple' => array('#f2ecfa', '#5f3b8c'),
        'orange' => array('#fff3e3', '#8a4b08'),
        'green'  => array('#edfaef', '#116329'),
        'gray'   => array('#f0f0f1', '#3c434a'),
        'red'    => array('#fcf0f1', '#8a2424'),
    );
    $colors = $tones[$tone] ?? $tones['gray'];
    return '<span class="seo-opp-badge" style="background:' . esc_attr($colors[0]) . ';color:' . esc_attr($colors[1]) . ';">' . esc_html($text) . '</span>';
}

function seo_google_opportunity_action_tone($action) {
    $meta = seo_google_opportunity_action_meta($action);
    return (string) ($meta['tone'] ?? 'gray');
}

function seo_google_opportunity_metric_text(array $row) {
    $parts = array();
    $metrics = (array) ($row['metrics'] ?? array());
    $market = (array) ($row['market'] ?? array());
    $catalog = (array) ($row['catalog'] ?? array());

    if (!empty($metrics['impressions'])) {
        $parts[] = number_format_i18n((float) $metrics['impressions'], 0) . ' impresiones';
    }
    if (!empty($metrics['position'])) {
        $parts[] = 'posición ' . number_format_i18n((float) $metrics['position'], 1);
    }
    if (!empty($market['score'])) {
        $parts[] = 'mercado ' . number_format_i18n((float) $market['score'], 0) . '/100';
    }
    if (!empty($market['interest_index'])) {
        $parts[] = 'interés vs referencia ' . number_format_i18n((float) $market['interest_index'], 0);
    }
    if (isset($market['interest_change_pct']) && abs((float) $market['interest_change_pct']) >= 5) {
        $parts[] = 'cambio reciente ' . number_format_i18n((float) $market['interest_change_pct'], 0) . '%';
    }
    if (!empty($market['traffic_label'])) {
        $parts[] = 'tendencia ' . sanitize_text_field($market['traffic_label']);
    } elseif (!empty($market['growth'])) {
        $parts[] = '+' . number_format_i18n((float) $market['growth'], 0) . '% Trends';
    }
    if (isset($catalog['products'])) {
        $parts[] = number_format_i18n((int) $catalog['products']) . ' productos relacionados';
    }
    if (!empty($catalog['product_match'])) {
        $parts[] = 'producto parecido: ' . sanitize_text_field($catalog['product_match']);
    }

    return implode(' · ', array_filter($parts));
}

function seo_google_opportunity_render_rows(array $rows, $empty_message, $limit = 50) {
    if (!$rows) {
        echo '<div class="seo-opp-empty"><p>' . esc_html($empty_message) . '</p></div>';
        return;
    }

    echo '<div class="seo-opp-list">';
    foreach (array_slice($rows, 0, max(1, absint($limit))) as $row) {
        $target = (array) ($row['target'] ?? array());
        $metric_text = seo_google_opportunity_metric_text($row);
        echo '<article class="seo-opp-row">';
        echo '<div class="seo-opp-score">' . absint($row['priority']) . '<small>/100</small></div>';
        echo '<div class="seo-opp-body"><div class="seo-opp-head">';
        echo seo_google_opportunity_badge($row['action_label'], seo_google_opportunity_action_tone($row['action']));
        echo seo_google_opportunity_badge($row['confidence'], 'gray');
        echo '<strong>' . esc_html($row['topic']) . '</strong></div>';
        echo '<p>' . esc_html($row['reason']) . '</p>';
        if ($metric_text !== '') {
            echo '<div class="seo-opp-meta">' . esc_html($metric_text) . '</div>';
        }
        if (!empty($target['title'])) {
            echo '<div class="seo-opp-meta"><strong>Destino actual:</strong> ';
            if (!empty($target['url'])) {
                echo '<a href="' . esc_url($target['url']) . '" target="_blank" rel="noopener">' . esc_html($target['title']) . '</a>';
            } else {
                echo esc_html($target['title']);
            }
            if (!empty($target['similarity'])) {
                echo ' · similitud ' . esc_html((string) round((float) $target['similarity'] * 100)) . '%';
            }
            echo '</div>';
        }
        if (!empty($row['evidence'])) {
            echo '<div class="seo-opp-meta"><strong>Evidencias:</strong> ' . esc_html(implode(' · ', array_slice($row['evidence'], 0, 4))) . '</div>';
        }
        echo '<div class="seo-opp-sources">';
        foreach ($row['sources'] as $source) {
            echo seo_google_opportunity_badge($source, 'gray');
        }
        echo '</div></div></article>';
    }
    echo '</div>';
}

function seo_google_opportunity_render_header($title, $description, $days, $view) {
    $current_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'google_intelligence';
    $in_growth_report = 'growth_executive' === $current_tab;
    $days_field = $in_growth_report ? 'growth_exec_days' : 'opportunity_days';

    echo '<div class="seo-opp-card seo-opp-header"><div><h2>' . esc_html($title) . '</h2><p>' . wp_kses_post($description) . '</p><div class="seo-opp-meta"><code>Motor V' . esc_html(SEO_GOOGLE_OPPORTUNITY_ENGINE_VERSION) . '</code> · Search Console: ' . absint($days) . ' días · generado ' . esc_html(current_time('mysql')) . '</div></div>';
    echo '<form method="get"><input type="hidden" name="page" value="seo-reports"><input type="hidden" name="tab" value="' . esc_attr($in_growth_report ? 'growth_executive' : 'google_intelligence') . '">';
    if (!$in_growth_report) {
        echo '<input type="hidden" name="google_view" value="' . esc_attr($view) . '">';
    }
    echo '<label><strong>Horizonte</strong> <select name="' . esc_attr($days_field) . '">';
    foreach (array(28, 60, 90) as $option) {
        echo '<option value="' . absint($option) . '" ' . selected($days, $option, false) . '>' . absint($option) . ' días</option>';
    }
    echo '</select></label> ';
    submit_button('Actualizar', 'secondary', 'submit', false);
    echo '</form></div>';
}

function seo_google_opportunity_render_source_cards(array $sources) {
    $labels = array(
        'search_console' => 'Search Console',
        'analytics'      => 'Analytics',
        'trends'         => 'Google Trends',
    );
    echo '<div class="seo-opp-source-grid">';
    foreach ($labels as $key => $label) {
        $source = (array) ($sources[$key] ?? array());
        $connected = !empty($source['connected']);
        echo '<div class="seo-opp-card"><h3>' . esc_html($label) . '</h3>';
        echo seo_google_opportunity_badge($connected ? 'OPERATIVO' : 'REVISAR', $connected ? 'green' : 'red');
        echo '<p>' . esc_html((string) ($source['detail'] ?? 'Sin diagnóstico.')) . '</p></div>';
    }
    echo '</div>';
}

function seo_google_opportunity_render_actions(array $payload, $days) {
    $summary = $payload['summary'];
    seo_google_opportunity_render_header(
        'Qué hacer ahora',
        'Una única cola de decisiones. <strong>Google descubre y mide; el catálogo y WordPress comprueban cobertura; el motor propone la acción.</strong>',
        $days,
        'actions'
    );

    echo '<div class="seo-opp-kpis">';
    $cards = array(
        array('Prioridad alta', $summary['high'], 'Acciones con puntuación 75 o superior'),
        array('Landings', $summary['landing'], 'Potenciar o estudiar páginas de destino'),
        array('Contenido', $summary['content'], 'Posts a crear, actualizar o consolidar'),
        array('Catálogo', $summary['catalog'], 'Productos, variantes y categorías'),
        array('SEO / vigilancia', $summary['seo'] + $summary['watch'], 'Mejorar activos o esperar más evidencia'),
    );
    foreach ($cards as $card) {
        echo '<div class="seo-opp-kpi"><span>' . esc_html($card[0]) . '</span><strong>' . number_format_i18n($card[1]) . '</strong><small>' . esc_html($card[2]) . '</small></div>';
    }
    echo '</div>';

    seo_google_opportunity_render_source_cards($payload['sources']);
    echo '<div class="seo-opp-card"><h3>JSON para compartir</h3><p>Exporta únicamente los <strong>resultados de Google y las decisiones derivadas</strong>. No incluye credenciales, tokens ni configuración técnica.</p><p style="margin-bottom:0;"><a class="button button-primary" href="' . esc_url(seo_google_opportunity_json_export_url($days)) . '">Descargar JSON de decisiones</a></p></div>';
    echo '<div class="seo-opp-card"><h3>Decisiones recomendadas</h3><p class="seo-opp-meta">Una señal de Trends sin cobertura se marca como investigación o vigilancia; nunca crea automáticamente una landing, un post, una categoría ni un producto.</p>';
    seo_google_opportunity_render_rows($payload['rows'], 'Todavía no hay evidencia suficiente para generar decisiones.', 40);
    echo '</div>';
}

function seo_google_opportunity_render_market(array $payload, $days) {
    seo_google_opportunity_render_header(
        'Mercado y tendencias externas',
        'Separa <strong>radar emergente</strong> de <strong>demanda capturada</strong>. Las páginas propias no determinan qué está buscando el mercado.',
        $days,
        'market'
    );

    $emerging = array_values(array_filter($payload['market'], static function ($row) {
        return 'emerging' === (string) ($row['signal_kind'] ?? '');
    }));
    $market = array_values(array_filter($payload['market'], static function ($row) {
        return 'emerging' !== (string) ($row['signal_kind'] ?? '');
    }));

    echo '<div class="seo-opp-two">';
    echo '<div class="seo-opp-card"><h3>Radar emergente</h3><p class="seo-opp-meta">Temas que están creciendo ahora y que el filtro ha relacionado con el perímetro comercial.</p>';
    if (!$emerging) {
        echo '<p>No hay tendencias actuales relevantes almacenadas. Esto puede significar que el radar funciona pero hoy no hay coincidencias comerciales.</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr><th>Tendencia</th><th>Relevancia</th><th>Volumen agrupado</th><th>Área relacionada</th><th>Observada</th></tr></thead><tbody>';
        foreach (array_slice($emerging, 0, 80) as $row) {
            echo '<tr><td><strong>' . esc_html($row['query']) . '</strong></td><td>' . number_format_i18n((float) $row['relevance_score'], 0) . '/100</td><td>' . esc_html($row['traffic_label'] ?: number_format_i18n((float) $row['traffic'], 0)) . '</td><td>' . esc_html(implode(', ', array_slice((array) $row['seeds'], 0, 3))) . '</td><td>' . esc_html($row['observed_at']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    echo '<div class="seo-opp-card"><h3>Exploración de mercado</h3><p class="seo-opp-meta">Búsquedas relacionadas, principales o en aumento incorporadas desde CSV o proveedor autorizado.</p>';
    if (!$market) {
        echo '<p>No hay datos de Explore almacenados. El radar de actualidad puede seguir operativo de forma independiente.</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr><th>Búsqueda</th><th>Puntuación</th><th>Crecimiento</th><th>Semilla</th><th>Fuente</th></tr></thead><tbody>';
        foreach (array_slice($market, 0, 100) as $row) {
            echo '<tr><td><strong>' . esc_html($row['query']) . '</strong></td><td>' . number_format_i18n((float) $row['score'], 0) . '/100</td><td>' . (!empty($row['breakout']) ? '<strong>BREAKOUT</strong>' : esc_html(number_format_i18n((float) $row['max_growth'], 0) . '%')) . '</td><td>' . esc_html(implode(', ', array_slice((array) $row['seeds'], 0, 3))) . '</td><td>' . esc_html(implode(', ', (array) $row['providers'])) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></div>';

    echo '<div class="seo-opp-card"><a class="button button-primary" href="' . esc_url(seo_google_admin_url('trends_market')) . '">Configurar y sincronizar Trends</a></div>';
}

function seo_google_opportunity_render_channel(array $payload, $days, $view) {
    $config = array(
        'landings_plan' => array(
            'title'       => 'Landings y cobertura SEO',
            'description' => 'Distingue entre <strong>mejorar una URL existente</strong> y <strong>estudiar una landing nueva</strong>. Las consultas informativas se envían a contenido.',
            'channels'    => array('landing', 'seo'),
            'empty'       => 'No hay recomendaciones de landing o SEO con la evidencia actual.',
        ),
        'content_plan' => array(
            'title'       => 'Contenido y noticias',
            'description' => 'Posts a crear o actualizar por demanda informativa, normativa, novedades y temas emergentes del entorno comercial.',
            'channels'    => array('content'),
            'empty'       => 'No hay recomendaciones editoriales con la evidencia actual.',
        ),
        'catalog_plan' => array(
            'title'       => 'Catálogo: productos, variantes y categorías',
            'description' => 'Separa falta de profundidad, producto emergente y posible familia nueva. Una tendencia aislada siempre queda como investigación, no como alta automática.',
            'channels'    => array('catalog'),
            'empty'       => 'No hay recomendaciones de catálogo con la evidencia actual.',
        ),
    );
    $current = $config[$view];
    seo_google_opportunity_render_header($current['title'], $current['description'], $days, $view);
    $rows = seo_google_opportunity_filter_rows($payload['rows'], $current['channels']);
    echo '<div class="seo-opp-card">';
    seo_google_opportunity_render_rows($rows, $current['empty'], 80);
    echo '</div>';
}

function seo_google_opportunity_render_results(array $payload, $days) {
    seo_google_opportunity_render_header(
        'Resultados de lo que ya existe',
        'Lectura actual de landings y posts mediante <strong>Analytics + Search Console</strong>. Indica tracción o falta de evidencia; no atribuye causalidad sin una comparación temporal controlada.',
        $days,
        'results'
    );

    $results = (array) ($payload['results'] ?? array());
    echo '<div class="seo-opp-two">';
    echo '<div class="seo-opp-card"><h3>Landings</h3>';
    if (empty($results['landings'])) {
        echo '<p>No hay métricas disponibles para landings publicadas.</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr><th>Landing</th><th>Diagnóstico</th><th>GA4</th><th>Search Console</th></tr></thead><tbody>';
        foreach (array_slice($results['landings'], 0, 80) as $row) {
            echo '<tr><td><a href="' . esc_url($row['url']) . '" target="_blank" rel="noopener"><strong>' . esc_html($row['title']) . '</strong></a></td><td>' . seo_google_opportunity_badge($row['assessment'], 'gray') . '</td><td>' . number_format_i18n((int) $row['pageviews']) . ' vistas · ' . number_format_i18n((int) $row['sessions']) . ' sesiones</td><td>' . number_format_i18n((int) $row['clicks']) . ' clics · ' . number_format_i18n((int) $row['impressions']) . ' impresiones · pos. ' . ($row['position'] ? number_format_i18n((float) $row['position'], 1) : '—') . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    echo '<div class="seo-opp-card"><h3>Posts</h3>';
    if (empty($results['posts'])) {
        echo '<p>No hay métricas disponibles para posts publicados.</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr><th>Post</th><th>Diagnóstico</th><th>GA4</th><th>Search Console</th></tr></thead><tbody>';
        foreach (array_slice($results['posts'], 0, 80) as $row) {
            echo '<tr><td><a href="' . esc_url($row['url']) . '" target="_blank" rel="noopener"><strong>' . esc_html($row['title']) . '</strong></a></td><td>' . seo_google_opportunity_badge($row['assessment'], 'gray') . '</td><td>' . number_format_i18n((int) $row['pageviews']) . ' vistas · ' . number_format_i18n((int) $row['sessions']) . ' sesiones</td><td>' . number_format_i18n((int) $row['clicks']) . ' clics · ' . number_format_i18n((int) $row['impressions']) . ' impresiones · pos. ' . (!empty($row['position']) ? number_format_i18n((float) $row['position'], 1) : '—') . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></div>';
}

function seo_google_opportunity_render_sources(array $payload, $days) {
    seo_google_opportunity_render_header(
        'Fuentes y diagnóstico',
        'Estado operativo de cada fuente y acceso a los informes técnicos. La configuración y sincronización de Google Intelligence se gestionan ahora desde Conexiones con proveedores.',
        $days,
        'sources'
    );
    seo_google_opportunity_render_source_cards($payload['sources']);

    $links = array(
        'Resumen Search Console'     => 'summary',
        'Consultas y páginas'        => 'signals',
        'Cambios de periodo'         => 'changes',
        'Google vs. catálogo'        => 'comparison',
        'Demanda × catálogo'         => 'demand_catalog',
        'Mercado Google · Trends'    => 'trends_market',
        'Cobertura'                  => 'coverage',
        'Laboratorio'                => 'laboratory',
    );
    echo '<div class="seo-opp-card"><h3>Informes técnicos conservados</h3><p>Estas pantallas siguen disponibles como evidencia y diagnóstico; ya no tienen que interpretarse una a una para saber qué hacer.</p><div style="display:flex;gap:8px;flex-wrap:wrap;">';
    foreach ($links as $label => $view) {
        echo '<a class="button" href="' . esc_url(seo_google_admin_url($view)) . '">' . esc_html($label) . '</a>';
    }
    echo '</div></div>';
}

function seo_google_opportunity_render_styles() {
    echo '<style>
    .seo-opp-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;margin:0 0 18px}.seo-opp-header{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;flex-wrap:wrap}.seo-opp-header h2{margin:0 0 6px}.seo-opp-header p{margin:0 0 7px;max-width:980px}.seo-opp-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:0 0 18px}.seo-opp-kpi{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px}.seo-opp-kpi span,.seo-opp-kpi small{display:block;color:#646970}.seo-opp-kpi strong{display:block;font-size:27px;margin:4px 0}.seo-opp-source-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin:0 0 18px}.seo-opp-source-grid .seo-opp-card{margin:0}.seo-opp-source-grid h3{margin-top:0}.seo-opp-two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}@media(max-width:1100px){.seo-opp-two{grid-template-columns:1fr}}.seo-opp-list{display:flex;flex-direction:column;gap:10px}.seo-opp-row{display:flex;gap:14px;border:1px solid #dcdcde;border-left:5px solid #2271b1;border-radius:6px;padding:13px;background:#fff}.seo-opp-score{font-size:24px;font-weight:700;min-width:64px}.seo-opp-score small{font-size:11px;font-weight:400}.seo-opp-body{min-width:0;flex:1}.seo-opp-head{display:flex;align-items:center;gap:7px;flex-wrap:wrap}.seo-opp-head strong{font-size:15px}.seo-opp-body p{margin:7px 0}.seo-opp-meta{color:#646970;font-size:12px;line-height:1.55}.seo-opp-sources{display:flex;gap:5px;flex-wrap:wrap;margin-top:8px}.seo-opp-badge{display:inline-block;border-radius:999px;padding:3px 8px;font-size:11px;font-weight:700}.seo-opp-empty{padding:18px;background:#f6f7f7;border-radius:6px}.seo-opp-card table td{vertical-align:top}
    </style>';
}

function seo_google_opportunity_render($view = 'actions', $days = 60) {
    $allowed = array('actions', 'market', 'landings_plan', 'content_plan', 'catalog_plan', 'results', 'sources');
    $view = in_array($view, $allowed, true) ? $view : 'actions';
    $days = in_array((int) $days, array(28, 60, 90), true) ? (int) $days : 60;
    $payload = seo_google_opportunity_build($days, 'results' === $view);

    seo_google_opportunity_render_styles();
    if ('actions' === $view) {
        seo_google_opportunity_render_actions($payload, $days);
    } elseif ('market' === $view) {
        seo_google_opportunity_render_market($payload, $days);
    } elseif (in_array($view, array('landings_plan', 'content_plan', 'catalog_plan'), true)) {
        seo_google_opportunity_render_channel($payload, $days, $view);
    } elseif ('results' === $view) {
        seo_google_opportunity_render_results($payload, $days);
    } elseif ('sources' === $view) {
        seo_google_opportunity_render_sources($payload, $days);
    }
}
