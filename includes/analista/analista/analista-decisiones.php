<?php
/**
 * Motor ejecutivo unificado de Analista.
 *
 * Absorbe las recomendaciones de Google Intelligence, las normaliza y las
 * cruza con competencia, busqueda interna, catalogo y proveedores. El objetivo
 * es una lista corta de trabajos, no repetir cada consulta como una accion.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_analista_action_meta')) {
    function seo_analista_action_meta($code) {
        $map = array(
            'POTENCIAR_CATEGORIA' => array('label' => 'Potenciar categoria', 'channel' => 'seo'),
            'AMPLIAR_PRODUCTOS' => array('label' => 'Ampliar productos', 'channel' => 'catalogo'),
            'POTENCIAR_LANDING' => array('label' => 'Potenciar landing', 'channel' => 'seo'),
            'ESTUDIAR_LANDING' => array('label' => 'Estudiar landing', 'channel' => 'contenido'),
            'ACTUALIZAR_POST' => array('label' => 'Actualizar contenido', 'channel' => 'contenido'),
            'CREAR_POST' => array('label' => 'Crear contenido', 'channel' => 'contenido'),
            'REVISAR_COBERTURA' => array('label' => 'Revisar cobertura', 'channel' => 'seo'),
            'POTENCIAR_SEO' => array('label' => 'Superar competidor', 'channel' => 'competencia'),
            'INVESTIGAR_CATALOGO' => array('label' => 'Investigar hueco de catalogo', 'channel' => 'catalogo'),
            'INVESTIGAR_PRODUCTO' => array('label' => 'Cubrir demanda interna', 'channel' => 'catalogo'),
            'REVISAR_PROVEEDOR' => array('label' => 'Revisar proveedor', 'channel' => 'proveedores'),
            'VIGILAR' => array('label' => 'Vigilar', 'channel' => 'seguimiento'),
        );
        return $map[$code] ?? array('label' => ucwords(strtolower(str_replace('_', ' ', (string) $code))), 'channel' => 'seo');
    }
}

if (!function_exists('seo_analista_topic_similarity')) {
    function seo_analista_topic_similarity($left, $right) {
        $a = array_values(array_unique(array_filter(explode(' ', seo_analista_normalize_text($left)))));
        $b = array_values(array_unique(array_filter(explode(' ', seo_analista_normalize_text($right)))));
        if (!$a || !$b) return 0.0;
        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));
        return $union > 0 ? $intersection / $union : 0.0;
    }
}

if (!function_exists('seo_analista_clean_evidence')) {
    function seo_analista_clean_evidence(array $evidence, $limit = 8) {
        $out = array();
        $seen = array();
        foreach ($evidence as $row) {
            $query = is_array($row) ? ($row['query'] ?? $row['query_text'] ?? '') : $row;
            $query = seo_analista_clean_query($query);
            $key = seo_analista_normalize_text($query);
            if ($query === '' || $key === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $query;
            if (count($out) >= max(1, absint($limit))) break;
        }
        return $out;
    }
}

if (!function_exists('seo_analista_google_plan')) {
    function seo_analista_google_plan($days = 28) {
        $days = seo_analista_days($days);
        if (!function_exists('seo_google_opportunity_build')) return array();

        $payload = (array) seo_google_opportunity_build($days, false);
        $grouped = array();

        foreach ((array) ($payload['rows'] ?? array()) as $row) {
            if (!is_array($row) || (int) ($row['priority'] ?? 0) < 45) continue;

            $topic = seo_analista_clean_query($row['topic'] ?? '');
            if ($topic === '' || !seo_analista_query_is_actionable($topic)) continue;

            $intent = seo_analista_intent($topic);
            $catalog = (array) ($row['catalog'] ?? array());
            $target = (array) ($row['target'] ?? array());
            $category = trim((string) ($catalog['category'] ?? ''));
            $products = isset($catalog['products']) ? (int) $catalog['products'] : null;
            $action = (string) ($row['action'] ?? 'REVISAR_COBERTURA');
            $priority = (int) ($row['priority'] ?? 0);
            $reason = trim((string) ($row['reason'] ?? ''));

            // Google Intelligence puede convertir variantes comerciales en
            // posts independientes. Analista las agrupa por familia y elige
            // primero la URL/categoria que ya existe.
            if (in_array($intent, array('comercial', 'transaccional'), true)) {
                if ($category !== '') {
                    if (null !== $products && $products > 0 && $products <= 5) {
                        $action = 'AMPLIAR_PRODUCTOS';
                        $reason = 'Hay demanda comercial y la categoria existe, pero tiene poca profundidad de producto. Revisar variantes y surtido antes de crear nuevas URLs.';
                    } else {
                        $action = 'POTENCIAR_CATEGORIA';
                        $reason = 'Hay demanda comercial y ya existe una categoria relacionada. Conviene concentrar autoridad, cobertura semantica y enlazado en esa familia antes de abrir nuevas paginas.';
                    }
                } elseif (in_array($action, array('CREAR_POST', 'ACTUALIZAR_POST'), true)) {
                    $action = 'REVISAR_COBERTURA';
                    $reason = 'La demanda es comercial, pero no hay una categoria o landing claramente resuelta. Validar destino estable antes de crear contenido adicional.';
                }
            }

            $meta = seo_analista_action_meta($action);
            $sources = array_values(array_unique(array_filter(array_map('strval', (array) ($row['sources'] ?? array())))));
            $evidence = seo_analista_clean_evidence((array) ($row['evidence'] ?? array()), 8);
            $metrics = (array) ($row['metrics'] ?? array());
            $market = (array) ($row['market'] ?? array());

            if (in_array($intent, array('comercial', 'transaccional'), true) && $category !== '') {
                $group_key = 'category|' . seo_analista_normalize_text($category);
                $display_topic = $category;
            } elseif (!empty($target['url'])) {
                $group_key = 'target|' . strtolower((string) $target['url']);
                $display_topic = $topic;
            } else {
                $group_key = $action . '|' . seo_analista_normalize_text($topic);
                $display_topic = $topic;
            }

            $candidate = array(
                'priority' => $priority,
                'action' => $action,
                'action_label' => $meta['label'],
                'channel' => $meta['channel'],
                'topic' => $display_topic,
                'reason' => $reason,
                'sources' => $sources,
                'source' => implode(' + ', $sources),
                'evidence' => $evidence,
                'detail' => implode(' · ', array_slice($evidence, 0, 3)),
                'intent' => $intent,
                'metrics' => array(
                    'impressions' => (float) ($metrics['impressions'] ?? 0),
                    'position' => (float) ($metrics['position'] ?? 0),
                    'search_score' => (float) ($metrics['search_score'] ?? 0),
                    'market_score' => (float) ($metrics['market_score'] ?? 0),
                ),
                'catalog' => array(
                    'category' => $category,
                    'products' => $products,
                    'term_id' => (int) ($catalog['term_id'] ?? 0),
                ),
                'market' => array(
                    'score' => (float) ($market['score'] ?? 0),
                    'growth' => (float) ($market['max_growth'] ?? $market['growth'] ?? 0),
                    'breakout' => !empty($market['breakout']),
                ),
                'target' => array(
                    'title' => (string) ($target['title'] ?? ''),
                    'url' => (string) ($target['url'] ?? ''),
                ),
                'related_topics' => array($topic),
            );

            if (!isset($grouped[$group_key])) {
                $grouped[$group_key] = $candidate;
                continue;
            }

            $existing = &$grouped[$group_key];
            if ($candidate['priority'] > $existing['priority']) {
                $existing['priority'] = $candidate['priority'];
                $existing['reason'] = $candidate['reason'];
                $existing['metrics'] = $candidate['metrics'];
                $existing['market'] = $candidate['market'];
                $existing['target'] = $candidate['target'];
            }
            $existing['sources'] = array_values(array_unique(array_merge($existing['sources'], $candidate['sources'])));
            $existing['source'] = implode(' + ', $existing['sources']);
            $existing['evidence'] = array_slice(array_values(array_unique(array_merge($existing['evidence'], $candidate['evidence']))), 0, 10);
            $existing['related_topics'] = array_slice(array_values(array_unique(array_merge($existing['related_topics'], $candidate['related_topics']))), 0, 12);
            $existing['detail'] = implode(' · ', array_slice($existing['evidence'], 0, 3));
            unset($existing);
        }

        $rows = array_values($grouped);
        usort($rows, static function($a, $b) {
            if ((int) $a['priority'] === (int) $b['priority']) {
                return (float) ($b['metrics']['impressions'] ?? 0) <=> (float) ($a['metrics']['impressions'] ?? 0);
            }
            return (int) $b['priority'] <=> (int) $a['priority'];
        });
        return $rows;
    }
}

if (!function_exists('seo_analista_competition_opportunities')) {
    function seo_analista_competition_opportunities($limit = 20) {
        $competition = seo_analista_competition_snapshot(max(50, $limit));
        $out = array();
        foreach ((array) ($competition['keyword_gaps'] ?? array()) as $gap) {
            $keyword = seo_analista_clean_query($gap['keyword'] ?? '');
            if ($keyword === '') continue;
            $own = (float) ($gap['own_position'] ?? 0);
            $best = (array) ($gap['best_competitor'] ?? array());
            $comp = (float) ($best['position'] ?? 0);
            $volume = (float) ($gap['volume'] ?? 0);
            $kd = (float) ($gap['difficulty'] ?? 0);
            $priority = (int) ($gap['priority'] ?? 0);

            if ($own > 0) {
                $code = 'POTENCIAR_SEO';
                $reason = 'Ya aparecemos, pero ' . (string) ($best['domain'] ?? 'un competidor') . ' esta en posicion ' . number_format_i18n($comp, 0) . '.';
            } else {
                $code = 'INVESTIGAR_CATALOGO';
                $reason = (string) ($best['domain'] ?? 'Un competidor') . ' capta esta demanda y nosotros no figuramos en el conjunto competitivo importado.';
            }
            $meta = seo_analista_action_meta($code);
            $out[] = array(
                'priority' => $priority,
                'action' => $code,
                'action_label' => $meta['label'],
                'channel' => $meta['channel'],
                'topic' => $keyword,
                'reason' => $reason,
                'sources' => array('Competencia'),
                'source' => 'Competencia',
                'detail' => ($own > 0 ? 'Posicion propia ' . number_format_i18n($own, 0) . ' · ' : '')
                    . 'Competidor ' . number_format_i18n($comp, 0)
                    . ' · volumen ' . number_format_i18n($volume, 0)
                    . ' · KD ' . number_format_i18n($kd, 0),
                'competition' => $gap,
                'evidence' => array($keyword),
            );
            if (count($out) >= max(5, min(100, absint($limit)))) break;
        }
        return $out;
    }
}

if (!function_exists('seo_analista_merge_external_signal')) {
    function seo_analista_merge_external_signal(array &$plan, array $signal, $threshold = 0.62) {
        $best_index = null;
        $best_similarity = 0.0;
        foreach ($plan as $index => $row) {
            $similarity = seo_analista_topic_similarity($signal['topic'] ?? '', $row['topic'] ?? '');
            if ($similarity > $best_similarity) {
                $best_similarity = $similarity;
                $best_index = $index;
            }
        }

        if (null !== $best_index && $best_similarity >= $threshold) {
            $plan[$best_index]['priority'] = min(100, max((int) $plan[$best_index]['priority'], (int) ($signal['priority'] ?? 0)) + 5);
            $plan[$best_index]['sources'] = array_values(array_unique(array_merge(
                (array) ($plan[$best_index]['sources'] ?? array()),
                (array) ($signal['sources'] ?? array((string) ($signal['source'] ?? '')))
            )));
            $plan[$best_index]['source'] = implode(' + ', array_filter($plan[$best_index]['sources']));
            if (!empty($signal['competition'])) $plan[$best_index]['competition'] = $signal['competition'];
            if (!empty($signal['internal_search'])) $plan[$best_index]['internal_search'] = $signal['internal_search'];
            return true;
        }
        return false;
    }
}

if (!function_exists('seo_analista_decision_plan')) {
    function seo_analista_decision_plan($days = 28, $limit = 15) {
        $days = seo_analista_days($days);
        $plan = seo_analista_google_plan($days);

        foreach (seo_analista_competition_opportunities(30) as $row) {
            if (!seo_analista_merge_external_signal($plan, $row, 0.58)) $plan[] = $row;
        }

        $search = seo_analista_internal_search_snapshot($days, 40);
        foreach (array_slice((array) ($search['gaps'] ?? array()), 0, 15) as $row) {
            $count = (int) ($row['searches'] ?? 0);
            $zero = (int) ($row['zero_count'] ?? 0);
            $priority = min(95, 55 + min(25, $count * 5) + min(15, $zero * 4));
            $topic = seo_analista_clean_query($row['search_term'] ?? '');
            if ($topic === '') continue;
            $meta = seo_analista_action_meta('INVESTIGAR_PRODUCTO');
            $signal = array(
                'priority' => $priority,
                'action' => 'INVESTIGAR_PRODUCTO',
                'action_label' => $meta['label'],
                'channel' => $meta['channel'],
                'topic' => $topic,
                'reason' => 'Los visitantes lo buscan dentro de la tienda y encuentran pocos o ningun resultado.',
                'sources' => array('Busqueda interna'),
                'source' => 'Busqueda interna',
                'detail' => number_format_i18n($count) . ' busquedas · ' . number_format_i18n((float) ($row['avg_results'] ?? 0), 1) . ' resultados medios',
                'internal_search' => $row,
                'evidence' => array($topic),
            );
            if (!seo_analista_merge_external_signal($plan, $signal, 0.68)) $plan[] = $signal;
        }

        $suppliers = seo_analista_supplier_snapshot(30);
        foreach (array_slice((array) ($suppliers['issues'] ?? array()), 0, 5) as $issue) {
            $meta = seo_analista_action_meta('REVISAR_PROVEEDOR');
            $plan[] = array(
                'priority' => 60,
                'action' => 'REVISAR_PROVEEDOR',
                'action_label' => $meta['label'],
                'channel' => $meta['channel'],
                'topic' => (string) ($issue['provider'] ?? ''),
                'reason' => implode('; ', (array) ($issue['problems'] ?? array())),
                'sources' => array('Proveedores'),
                'source' => 'Proveedores',
                'detail' => 'La calidad y frescura del feed afecta al catalogo y a las decisiones del Analista.',
                'evidence' => array(),
            );
        }

        usort($plan, static function($a, $b) {
            $diff = (int) ($b['priority'] ?? 0) <=> (int) ($a['priority'] ?? 0);
            return $diff ?: strcmp((string) ($a['topic'] ?? ''), (string) ($b['topic'] ?? ''));
        });

        $dedup = array();
        $seen = array();
        foreach ($plan as $row) {
            $key = seo_analista_normalize_text(($row['action'] ?? '') . ' ' . ($row['topic'] ?? ''));
            if ($key === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $dedup[] = $row;
            if (count($dedup) >= max(5, min(60, absint($limit)))) break;
        }
        return $dedup;
    }
}

if (!function_exists('seo_analista_plan_summary')) {
    function seo_analista_plan_summary(array $plan) {
        $out = array(
            'total' => count($plan),
            'high' => 0,
            'catalogo' => 0,
            'contenido' => 0,
            'seo' => 0,
            'proveedores' => 0,
            'competencia' => 0,
            'seguimiento' => 0,
        );
        foreach ($plan as $row) {
            if ((int) ($row['priority'] ?? 0) >= 75) $out['high']++;
            $channel = (string) ($row['channel'] ?? 'seo');
            if (isset($out[$channel])) $out[$channel]++;
            else $out['seo']++;
            if (stripos((string) ($row['source'] ?? ''), 'competencia') !== false || !empty($row['competition'])) {
                $out['competencia']++;
            }
        }
        return $out;
    }
}
