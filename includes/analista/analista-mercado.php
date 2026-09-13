<?php
/**
 * Mercado, tendencias y adaptadores externos del Analista.
 *
 * En 3.0 las senales de discovery de Google Trends dejan de descartarse:
 * se convierten en oportunidades y se cruzan con catalogo y contenido local.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_analista_market_signals')) {
    function seo_analista_market_signals($limit = 20) {
        if (!function_exists('seo_google_trends_market_summary')) return array();
        $rows = (array) seo_google_trends_market_summary(500);
        $usable = array();
        $seen = array();

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $query = seo_analista_clean_query($row['query'] ?? $row['label'] ?? '');
            if ($query === '' || !seo_analista_query_is_actionable($query)) continue;

            $kind = sanitize_key((string) ($row['signal_kind'] ?? 'market'));
            $score = (float) ($row['score'] ?? 0);
            $growth = (float) ($row['max_growth'] ?? $row['interest_change_pct'] ?? $row['growth'] ?? 0);
            $breakout = !empty($row['breakout']) || stripos((string) ($row['growth_label'] ?? ''), 'breakout') !== false;

            // Discovery es precisamente la fuente de nuevas consultas. Aunque
            // algunas filas no tengan score historico, un crecimiento o
            // breakout las hace utilizables.
            if ($score <= 0 && !$breakout && $growth <= 0 && 'discovery' !== $kind) continue;

            $key = seo_analista_normalize_text($query);
            if ($key === '' || isset($seen[$key])) continue;
            $seen[$key] = true;

            $row['query'] = $query;
            $row['signal_kind'] = $kind ?: 'market';
            $row['score'] = $score > 0 ? $score : min(100, max(25, $growth > 0 ? 35 + min(55, log(1 + $growth) * 12) : 45));
            $row['max_growth'] = $growth;
            $row['breakout'] = $breakout;
            $row['catalog'] = function_exists('seo_google_opportunity_catalog_context')
                ? (array) seo_google_opportunity_catalog_context($query, (array) ($row['seeds'] ?? array()))
                : array();
            $usable[] = $row;
        }

        usort($usable, static function ($a, $b) {
            $ab = !empty($a['breakout']) ? 20 : 0;
            $bb = !empty($b['breakout']) ? 20 : 0;
            $as = (float) ($a['score'] ?? 0) + $ab + min(20, max(0, (float) ($a['max_growth'] ?? 0)) / 10);
            $bs = (float) ($b['score'] ?? 0) + $bb + min(20, max(0, (float) ($b['max_growth'] ?? 0)) / 10);
            return $bs <=> $as;
        });

        return array_slice($usable, 0, max(5, min(100, absint($limit))));
    }
}

if (!function_exists('seo_analista_search_acceleration')) {
    function seo_analista_search_acceleration($days = 28, $limit = 30) {
        $data = seo_analista_get_data($days);
        $previous = seo_analista_rows_map((array) ($data['previous_queries'] ?? array()), 'query_hash');
        $out = array();

        foreach ((array) ($data['queries'] ?? array()) as $row) {
            $query = seo_analista_clean_query($row['query_text'] ?? '');
            if ($query === '' || !seo_analista_query_is_actionable($query)) continue;
            $impressions = (float) ($row['impressions'] ?? 0);
            $position = (float) ($row['position'] ?? 0);
            if ($impressions < 5 || $position <= 0 || $position > 100) continue;

            $prev = (array) ($previous[$row['query_hash'] ?? ''] ?? array());
            $prev_imp = (float) ($prev['impressions'] ?? 0);
            $delta = $impressions - $prev_imp;
            $growth = $prev_imp > 0 ? ($delta / $prev_imp) * 100 : ($impressions >= 8 ? 100.0 : 0.0);
            $prev_pos = (float) ($prev['position'] ?? 0);
            $position_gain = ($prev_pos > 0 && $position > 0) ? $prev_pos - $position : 0.0;

            // Un +100 % con 3-5 impresiones no equivale a una tendencia real.
            if ($growth < 25 && $delta < 8 && $position_gain < 8) continue;

            $score = 30;
            $score += min(24, log(1 + $impressions) * 4.2);
            if ($delta >= 30) $score += min(16, max(0, $growth) / 12);
            elseif ($delta >= 12) $score += min(10, max(0, $growth) / 20);
            elseif ($delta >= 8) $score += min(6, max(0, $growth) / 30);
            else $score += min(3, max(0, $growth) / 50);
            $score += min(10, max(0, $position_gain));
            if ($position <= 20 && $impressions >= 10) $score += 8;
            $score = min(100, (int) round($score));

            $catalog = function_exists('seo_google_opportunity_catalog_context')
                ? (array) seo_google_opportunity_catalog_context($query)
                : array();
            $local = function_exists('seo_analista_local_category_context') ? seo_analista_local_category_context($query) : array();
            if ($local) $catalog = array_merge($catalog, $local);

            $out[] = array(
                'query' => $query,
                'score' => $score,
                'impressions' => $impressions,
                'previous_impressions' => $prev_imp,
                'delta_impressions' => $delta,
                'growth' => $growth,
                'position' => $position,
                'previous_position' => $prev_pos,
                'position_gain' => $position_gain,
                'intent' => seo_analista_intent($query),
                'catalog' => $catalog,
            );
        }

        usort($out, static function ($a, $b) {
            return (int) ($b['score'] ?? 0) <=> (int) ($a['score'] ?? 0);
        });
        return array_slice($out, 0, max(5, min(100, absint($limit))));
    }
}


if (!function_exists('seo_analista_trend_action_from_target')) {
    function seo_analista_trend_action_from_target(array $target_row) {
        $type = (string) ($target_row['entity']['type'] ?? '');
        $issues = !empty($target_row['issues']);
        if ($type === '') return 'REVISAR_COBERTURA';
        return seo_analista_entity_action($type, $issues);
    }
}

if (!function_exists('seo_analista_trend_work_row')) {
    function seo_analista_trend_work_row($query, $score, array $catalog, $days, $source, array $market = array(), array $extra_metrics = array()) {
        $query = seo_analista_clean_query($query);
        if ($query === '' || !seo_analista_query_is_actionable($query)) return array();

        // El inventario local manda sobre una sugerencia externa aproximada.
        $local_catalog = function_exists('seo_analista_local_category_context') ? seo_analista_local_category_context($query) : array();
        if ($local_catalog) $catalog = array_merge($catalog, $local_catalog);

        $category = trim((string) ($catalog['category'] ?? $catalog['category_name'] ?? ''));
        $term_id = absint($catalog['term_id'] ?? $catalog['category_id'] ?? 0);
        $products = isset($catalog['product_count']) ? (int) $catalog['product_count'] : (isset($catalog['products']) ? (int) $catalog['products'] : null);
        $category_url = (string) ($catalog['category_url'] ?? '');

        // Si el proveedor sugiere una categoría que no se parece a la consulta,
        // la descartamos. Mejor SIN TARGET que un destino equivocado.
        if ($category !== '' && seo_analista_text_similarity($query, $category) < 0.42 && !$local_catalog) {
            $category = '';
            $term_id = 0;
            $products = null;
            $category_url = '';
        }

        $target = array('title' => '', 'url' => '');
        $entity = array();
        $issues = array();
        $changes = array();
        $intent = seo_analista_intent($query);

        if ($category !== '') {
            $category_row = ($term_id && function_exists('seo_analista_find_content_entity'))
                ? seo_analista_find_content_entity('category', $term_id, $days)
                : array();
            $has_issues = $category_row && !empty($category_row['issues']);
            $action = $has_issues ? 'MEJORAR_CATEGORIA' : 'IMPULSAR_CATEGORIA';
            $reason = $source . ' detecta movimiento en «' . $query . '» y existe una categoría propia claramente relacionada. Conviene concentrar la demanda en esa familia.';
            $target = array('title' => $category, 'url' => $category_url);
            $entity = array('type' => 'category', 'type_label' => 'Categoría', 'id' => $term_id, 'title' => $category, 'url' => $category_url, 'edit_url' => $term_id ? (string) get_edit_term_link($term_id, 'product_cat') : '');
            if ($category_row) {
                $target = (array) ($category_row['target'] ?? $target);
                $entity = (array) ($category_row['entity'] ?? $entity);
                $issues = (array) ($category_row['issues'] ?? array());
                $changes = (array) ($category_row['recommended_changes'] ?? array());
            }
            array_unshift($changes, 'Incorporar la intención «' . $query . '» en la literatura de la categoría si encaja de forma natural.');
            $changes[] = 'Reforzar enlaces internos desde posts, hubs y productos relacionados hacia esta categoría.';
            if (null !== $products && $products <= 5) $changes[] = 'Revisar surtido: la categoría tiene poca profundidad y puede necesitar más productos o variantes.';
        } else {
            $local = function_exists('seo_analista_find_best_local_target') ? seo_analista_find_best_local_target($query, $days) : array();
            if ($local && !empty($local['row'])) {
                $target_row = (array) $local['row'];
                $action = seo_analista_trend_action_from_target($target_row);
                $entity = (array) ($target_row['entity'] ?? array());
                $target = (array) ($target_row['target'] ?? array());
                $issues = (array) ($target_row['issues'] ?? array());
                $changes = (array) ($target_row['recommended_changes'] ?? array());
                array_unshift($changes, 'Aprovechar el movimiento de «' . $query . '» ampliando su cobertura en esta URL existente.');
                $reason = $source . ' detecta movimiento y Analista ha localizado una URL propia con afinidad suficiente. Es preferible reforzarla antes de crear otra.';
            } else {
                $action = in_array($intent, array('informativa','navegacion'), true) ? 'CREAR_CONTENIDO' : 'REVISAR_COBERTURA';
                $reason = $source . ' detecta movimiento en «' . $query . '», pero no hay una URL propia con afinidad suficiente. Hay que resolver el destino antes de repartir autoridad.';
                $changes = array(
                    'Validar la intención y decidir si debe resolverse con post, página, categoría o ampliación de una familia existente.',
                    'Comprobar productos y Vocabulary relacionados antes de crear una URL nueva.',
                    'Si se crea contenido, enlazarlo desde la rama estructural y hacia la conversión adecuada.',
                );
            }
        }

        $meta = seo_analista_action_meta($action);
        $growth = (float) ($market['max_growth'] ?? $market['growth'] ?? $extra_metrics['growth'] ?? 0);
        $priority = min(100, max(45, (int) round($score)) + (!empty($market['breakout']) ? 8 : 0));
        $is_trends = stripos((string) $source, 'Google Trends') !== false;
        return array(
            'priority' => $priority,
            'action' => $action,
            'action_label' => $meta['label'],
            'channel' => $meta['channel'],
            'topic' => $entity ? (string) ($entity['title'] ?? $query) : $query,
            'reason' => $reason,
            'sources' => array($source),
            'source' => $source,
            'evidence' => array($query),
            'keywords' => array($query),
            'issues' => $issues,
            'recommended_changes' => array_values(array_unique(array_filter($changes))),
            'entity' => $entity,
            'target' => $target,
            'intent' => $intent,
            'metrics' => array_merge(array(
                'impressions' => (float) ($extra_metrics['impressions'] ?? 0),
                'position' => (float) ($extra_metrics['position'] ?? 0),
                'search_score' => (float) ($extra_metrics['search_score'] ?? 0),
                'market_score' => $is_trends ? (float) ($market['score'] ?? $score) : 0.0,
                'impressions_growth_pct' => (float) ($extra_metrics['growth'] ?? 0),
            ), $extra_metrics),
            'market' => array(
                'score' => $is_trends ? (float) ($market['score'] ?? 0) : 0.0,
                'growth' => $growth,
                'breakout' => !empty($market['breakout']),
                'signal_kind' => (string) ($market['signal_kind'] ?? ''),
                'seeds' => (array) ($market['seeds'] ?? array()),
            ),
            'catalog' => array('category' => $category, 'products' => $products, 'term_id' => $term_id),
        );
    }
}


if (!function_exists('seo_analista_consolidate_trend_rows')) {
    function seo_analista_consolidate_trend_rows(array $rows) {
        $groups = array();
        foreach ($rows as $row) {
            $entity = is_array($row['entity'] ?? null) ? (array) $row['entity'] : array();
            if (!empty($entity['type']) && !empty($entity['id'])) {
                $key = 'entity|' . sanitize_key((string) $entity['type']) . '|' . absint($entity['id']);
            } elseif (!empty($row['target']['url'])) {
                $key = 'url|' . strtolower((string) $row['target']['url']);
            } else {
                $key = 'topic|' . seo_analista_normalize_text((string) ($row['topic'] ?? ''));
            }
            if ($key === 'topic|') continue;

            if (!isset($groups[$key])) {
                $groups[$key] = $row;
                $groups[$key]['_signal_count'] = 1;
                continue;
            }
            $g = &$groups[$key];
            $g['_signal_count']++;
            $g['priority'] = max((int) ($g['priority'] ?? 0), (int) ($row['priority'] ?? 0));
            if (strpos((string) ($row['action'] ?? ''), 'MEJORAR_') === 0 && strpos((string) ($g['action'] ?? ''), 'IMPULSAR_') === 0) {
                $g['action'] = $row['action'];
                $g['action_label'] = $row['action_label'] ?? $g['action_label'];
                $g['channel'] = $row['channel'] ?? $g['channel'];
            }
            foreach (array('sources','evidence','keywords','issues','recommended_changes') as $field) {
                $g[$field] = array_values(array_unique(array_filter(array_merge((array) ($g[$field] ?? array()), (array) ($row[$field] ?? array())))));
            }
            $g['evidence'] = array_slice($g['evidence'], 0, 12);
            $g['keywords'] = array_slice($g['keywords'], 0, 12);
            $g['source'] = implode(' + ', (array) $g['sources']);
            $gm = (array) ($g['metrics'] ?? array());
            $rm = (array) ($row['metrics'] ?? array());
            $gm['impressions'] = (float) ($gm['impressions'] ?? 0) + (float) ($rm['impressions'] ?? 0);
            $gm['previous_impressions'] = (float) ($gm['previous_impressions'] ?? 0) + (float) ($rm['previous_impressions'] ?? 0);
            $gm['search_score'] = max((float) ($gm['search_score'] ?? 0), (float) ($rm['search_score'] ?? 0));
            $gm['market_score'] = max((float) ($gm['market_score'] ?? 0), (float) ($rm['market_score'] ?? 0));
            $g['metrics'] = $gm;
            if (!empty($row['market'])) {
                $g['market']['score'] = max((float) ($g['market']['score'] ?? 0), (float) ($row['market']['score'] ?? 0));
                $g['market']['growth'] = max((float) ($g['market']['growth'] ?? 0), (float) ($row['market']['growth'] ?? 0));
                $g['market']['breakout'] = !empty($g['market']['breakout']) || !empty($row['market']['breakout']);
            }
            unset($g);
        }
        $out = array_values($groups);
        foreach ($out as &$row) {
            if ((int) ($row['_signal_count'] ?? 1) > 1) {
                $row['reason'] = rtrim((string) ($row['reason'] ?? '')) . ' Se han agrupado ' . (int) $row['_signal_count'] . ' señales relacionadas en una sola directriz.';
            }
            unset($row['_signal_count']);
        }
        unset($row);
        usort($out, static function($a,$b){ return (int) ($b['priority'] ?? 0) <=> (int) ($a['priority'] ?? 0); });
        return $out;
    }
}

if (!function_exists('seo_analista_trend_work')) {
    function seo_analista_trend_work($days = 28, $limit = 60) {
        $days = seo_analista_days($days);
        $out = array();
        $seen = array();

        foreach (seo_analista_market_signals(80) as $row) {
            $query = seo_analista_clean_query($row['query'] ?? '');
            $key = seo_analista_normalize_text($query);
            if ($key === '' || isset($seen[$key])) continue;
            $candidate = seo_analista_trend_work_row($query, (float) ($row['score'] ?? 0), (array) ($row['catalog'] ?? array()), $days, 'Google Trends', $row);
            if ($candidate) {
                $seen[$key] = true;
                $out[] = $candidate;
            }
        }

        // Si Trends no ha descubierto suficiente mercado, no dejamos la
        // pestaña muda: se muestran consultas que aceleran en Search Console,
        // claramente identificadas como señal propia y no como Trends.
        foreach (seo_analista_search_acceleration($days, 50) as $row) {
            $query = seo_analista_clean_query($row['query'] ?? '');
            $key = seo_analista_normalize_text($query);
            if ($key === '' || isset($seen[$key])) continue;
            $candidate = seo_analista_trend_work_row(
                $query,
                (float) ($row['score'] ?? 0),
                (array) ($row['catalog'] ?? array()),
                $days,
                'Search Console · aceleración',
                array(),
                array(
                    'impressions' => (float) ($row['impressions'] ?? 0),
                    'position' => (float) ($row['position'] ?? 0),
                    'growth' => (float) ($row['growth'] ?? 0),
                    'search_score' => (float) ($row['score'] ?? 0),
                    'previous_impressions' => (float) ($row['previous_impressions'] ?? 0),
                    'previous_position' => (float) ($row['previous_position'] ?? 0),
                    'position_gain' => (float) ($row['position_gain'] ?? 0),
                )
            );
            if ($candidate) {
                $seen[$key] = true;
                $out[] = $candidate;
            }
        }

        $out = seo_analista_consolidate_trend_rows($out);
        return array_slice($out, 0, max(10, min(120, absint($limit))));
    }
}

if (!function_exists('seo_analista_catalog_guidance')) {
    function seo_analista_catalog_guidance($days = 28, $limit = 12) {
        if (!seo_analista_is_ready() || !function_exists('seo_google_demand_get_catalog_guidance')) return array();
        $property_id = function_exists('seo_analista_resolve_property_id') ? seo_analista_resolve_property_id() : '';
        if ($property_id === '') return array();
        $rows = (array) seo_google_demand_get_catalog_guidance($property_id, seo_analista_days($days), 2, max(5, min(30, absint($limit) * 2)));
        $out = array();
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $label = seo_analista_clean_query($row['label'] ?? '');
            if ($label === '' || !seo_analista_query_is_actionable($label)) continue;
            $row['label'] = $label;
            $clean_evidence = array();
            foreach ((array) ($row['evidence'] ?? array()) as $ev) {
                if (!is_array($ev)) continue;
                $q = seo_analista_clean_query($ev['query'] ?? '');
                if ($q === '' || !seo_analista_query_is_actionable($q)) continue;
                $ev['query'] = $q;
                $clean_evidence[] = $ev;
            }
            $row['evidence'] = $clean_evidence;
            $out[] = $row;
            if (count($out) >= max(5, min(30, absint($limit)))) break;
        }
        return $out;
    }
}


if (!function_exists('seo_analista_competitor_rankings')) {
    function seo_analista_competitor_rankings(array $keywords, array $competitors) {
        $keywords = array_values(array_filter(array_map('sanitize_text_field', $keywords)));
        $competitors = seo_analista_sanitize_domains($competitors);

        /**
         * Adaptador de proveedor externo.
         *
         * Cualquier conector de rankings compatible puede inyectar filas sin
         * modificar el modulo Analista:
         *
         * [
         *   'keyword' => 'herramientas de taller',
         *   'domain' => 'competidor.es',
         *   'position' => 8,
         *   'url' => 'https://competidor.es/...',
         *   'volume' => 590,
         *   'difficulty' => 13,
         *   'source' => 'proveedor',
         * ]
         */
        $rows = apply_filters(
            'seo_analista_competitor_rankings',
            array(),
            $keywords,
            $competitors,
            array('country' => 'ES', 'device' => 'desktop')
        );

        return is_array($rows) ? $rows : array();
    }
}

if (!function_exists('seo_analista_competitor_summary')) {
    function seo_analista_competitor_summary(array $opportunities, array $competitors) {
        $keywords = array();
        foreach (array_slice($opportunities, 0, 50) as $row) {
            $keyword = trim((string) ($row['query_text'] ?? ''));
            if ($keyword !== '') $keywords[] = $keyword;
        }
        $keywords = array_values(array_unique($keywords));
        $rows = seo_analista_competitor_rankings($keywords, $competitors);

        $summary = array();
        foreach ($competitors as $domain) {
            $summary[$domain] = array(
                'domain' => $domain,
                'keywords' => 0,
                'top3' => 0,
                'top10' => 0,
                'top20' => 0,
                'top100' => 0,
                'rows' => array(),
            );
        }

        foreach ($rows as $row) {
            $domain = strtolower((string) ($row['domain'] ?? ''));
            $domain = preg_replace('/^www\./', '', $domain);
            if (!isset($summary[$domain])) continue;
            $position = (float) ($row['position'] ?? 0);
            if ($position <= 0) continue;
            $summary[$domain]['keywords']++;
            if ($position <= 3) $summary[$domain]['top3']++;
            if ($position <= 10) $summary[$domain]['top10']++;
            if ($position <= 20) $summary[$domain]['top20']++;
            if ($position <= 100) $summary[$domain]['top100']++;
            $summary[$domain]['rows'][] = $row;
        }

        return array(
            'provider_available' => !empty($rows),
            'keywords' => $keywords,
            'rows' => $rows,
            'domains' => array_values($summary),
        );
    }
}
