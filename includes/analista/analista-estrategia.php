<?php
/**
 * Estrategia ejecutiva del Analista.
 *
 * Convierte senales heterogeneas en una cartera corta de trabajo orientada a
 * tres objetivos de negocio: autoridad, visitas y ventas. Una fuente que no
 * esta disponible no puede aumentar la prioridad de una directriz.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_analista_source_state_weight')) {
    function seo_analista_source_state_weight($state) {
        $state = sanitize_key((string) $state);
        if ($state === 'ok') return 1.0;
        if ($state === 'partial') return 0.35;
        return 0.0;
    }
}

if (!function_exists('seo_analista_source_health_all')) {
    function seo_analista_source_health_all($days = 28) {
        $days = seo_analista_days($days);
        $health = function_exists('seo_analista_google_source_health')
            ? (array) seo_analista_google_source_health($days)
            : array();

        if (function_exists('seo_analista_bing_source_health')) {
            $health['bing'] = (array) seo_analista_bing_source_health($days);
        } else {
            $health['bing'] = array('label'=>'Bing Webmaster','state'=>'pending','connected'=>false,'detail'=>'Adaptador Bing no cargado.');
        }

        $competition = function_exists('seo_analista_competition_snapshot')
            ? (array) seo_analista_competition_snapshot(20)
            : array();
        $health['competition'] = array(
            'label' => 'Competencia',
            'state' => !empty($competition['available']) ? 'ok' : 'pending',
            'connected' => !empty($competition['available']),
            'detail' => !empty($competition['available'])
                ? number_format_i18n((int) ($competition['row_count'] ?? 0)) . ' posiciones externas utilizables.'
                : 'Sin posiciones externas: no influye en la prioridad.',
        );

        $search = function_exists('seo_analista_internal_search_snapshot')
            ? (array) seo_analista_internal_search_snapshot($days, 20)
            : array();
        $health['internal_search'] = array(
            'label' => 'Busqueda interna',
            'state' => !empty($search['available']) ? 'ok' : 'pending',
            'connected' => !empty($search['available']),
            'detail' => !empty($search['available'])
                ? number_format_i18n((int) ($search['total'] ?? 0)) . ' busquedas de clientes en el periodo.'
                : 'Fuente local no disponible.',
        );

        $suppliers = function_exists('seo_analista_supplier_snapshot')
            ? (array) seo_analista_supplier_snapshot(20)
            : array();
        $health['suppliers'] = array(
            'label' => 'Proveedores',
            'state' => !empty($suppliers['available']) ? 'ok' : 'pending',
            'connected' => !empty($suppliers['available']),
            'detail' => !empty($suppliers['available'])
                ? number_format_i18n((int) ($suppliers['providers'] ?? 0)) . ' proveedores disponibles.'
                : 'Fuente local no disponible.',
        );

        $ga4 = function_exists('seo_analista_ga4_snapshot') ? (array) seo_analista_ga4_snapshot($days) : array();
        $sessions = (int) ($ga4['sessions'] ?? 0);
        $purchases = (int) ($ga4['purchases'] ?? 0);
        $revenue = (float) ($ga4['revenue'] ?? 0);
        $ecommerce_state = !empty($ga4['available']) ? 'ok' : 'pending';
        $ecommerce_detail = !empty($ga4['available']) ? 'Medicion ecommerce disponible.' : 'GA4 no disponible.';
        if (!empty($ga4['available']) && $sessions >= 100 && $purchases === 0 && $revenue <= 0) {
            $ecommerce_state = 'partial';
            $ecommerce_detail = 'GA4 responde, pero no registra compras ni ingresos en el periodo. Verificar antes de usar ventas como resultado medido.';
        }
        $health['ecommerce'] = array(
            'label' => 'GA4 ecommerce',
            'state' => $ecommerce_state,
            'connected' => !empty($ga4['available']),
            'detail' => $ecommerce_detail,
        );

        foreach ($health as $key => &$row) {
            $row = is_array($row) ? $row : array();
            $row['weight'] = seo_analista_source_state_weight($row['state'] ?? 'pending');
            $row['key'] = (string) $key;
        }
        unset($row);
        return $health;
    }
}

if (!function_exists('seo_analista_growth_signal')) {
    function seo_analista_growth_signal($current, $previous) {
        $current = max(0.0, (float) $current);
        $previous = max(0.0, (float) $previous);
        $delta = $current - $previous;
        $raw = $previous > 0 ? ($delta / $previous) * 100 : ($current > 0 ? 100.0 : 0.0);
        // Suavizado bayesiano sencillo: evita que 0->3 o 1->4 parezcan una
        // tendencia tan fuerte como 100->300.
        $smoothed = (($current + 8.0) / ($previous + 8.0) - 1.0) * 100.0;
        $volume_factor = min(1.0, log(1.0 + $current) / log(101.0));
        $signal = max(-100.0, min(100.0, $smoothed * $volume_factor));
        return array(
            'current' => $current,
            'previous' => $previous,
            'delta' => $delta,
            'raw_growth_pct' => $raw,
            'smoothed_growth_pct' => $smoothed,
            'signal' => $signal,
        );
    }
}

if (!function_exists('seo_analista_issue_flags')) {
    function seo_analista_issue_flags(array $issues) {
        $text = seo_analista_normalize_text(implode(' ', array_map('strval', $issues)));
        return array(
            'thin' => strpos($text, 'cobertura textual baja') !== false || strpos($text, 'descripcion corta insuficiente') !== false,
            'links' => strpos($text, 'enlazado interno escaso') !== false,
            'vocabulary' => strpos($text, 'sin vocabulary') !== false,
            'ctr' => strpos($text, 'ctr bajo') !== false,
            'taxonomy' => strpos($text, 'sin categoria') !== false,
            'structure' => strpos($text, 'sin descendencia') !== false,
        );
    }
}

if (!function_exists('seo_analista_business_scores')) {
    function seo_analista_business_scores(array $row, array $health = array()) {
        $metrics = (array) ($row['metrics'] ?? array());
        $entity = (array) ($row['entity'] ?? array());
        $issues = array_values(array_filter((array) ($row['issues'] ?? array())));
        $flags = seo_analista_issue_flags($issues);
        $type = sanitize_key((string) ($entity['type'] ?? ''));
        $role = sanitize_key((string) ($entity['role'] ?? ''));
        $intent = sanitize_key((string) ($row['intent'] ?? ''));
        if ($intent === '' && function_exists('seo_analista_intent')) $intent = seo_analista_intent((string) ($row['topic'] ?? ''));

        $impressions = max(0.0, (float) ($metrics['impressions'] ?? 0));
        $bing_impressions = max(0.0, (float) ($metrics['bing_impressions'] ?? 0));
        $weighted_impressions = $impressions + ($bing_impressions * 0.35);
        $clicks = max(0.0, (float) ($metrics['clicks'] ?? 0));
        $ctr = max(0.0, (float) ($metrics['ctr'] ?? 0));
        $position = max(0.0, (float) ($metrics['position'] ?? 0));
        if ($position <= 0) $position = max(0.0, (float) ($metrics['bing_position'] ?? 0));
        $queries = max(0, (int) ($metrics['queries'] ?? 0));
        $previous_impressions = max(0.0, (float) ($metrics['previous_impressions'] ?? ($impressions - (float) ($metrics['impressions_delta'] ?? 0))));
        $growth = seo_analista_growth_signal($impressions, $previous_impressions);

        $volume = min(35.0, log(1.0 + $weighted_impressions) * 5.2);
        $query_breadth = min(10.0, log(1.0 + $queries) * 3.0);

        $authority = 12.0 + $volume * 0.65 + $query_breadth;
        if (in_array($type, array('category','cluster','hub_primary','hub_secondary'), true)) $authority += 18;
        elseif (in_array($type, array('post','page'), true)) $authority += 10;
        elseif ($type === 'product') $authority += 5;
        if ($flags['thin']) $authority += 8;
        if ($flags['links']) $authority += 10;
        if ($flags['vocabulary']) $authority += 8;
        if ($flags['structure']) $authority += 10;
        if ($position > 10 && $position <= 50) $authority += 12;
        elseif ($position > 50 && $position <= 100 && $impressions >= 50) $authority += 6;
        if (!empty($row['competition']) && seo_analista_source_state_weight($health['competition']['state'] ?? 'pending') > 0) $authority += 8;
        $authority = max(0, min(100, (int) round($authority)));

        $traffic = 8.0 + $volume;
        if ($position > 0 && $position <= 3) $traffic += 18;
        elseif ($position > 0 && $position <= 10) $traffic += 26;
        elseif ($position > 0 && $position <= 20) $traffic += 30;
        elseif ($position > 0 && $position <= 50) $traffic += 18;
        elseif ($position > 0 && $position <= 100) $traffic += 7;
        if ($impressions >= 20 && $position > 0 && $position <= 20 && $ctr < 0.01) $traffic += 20;
        elseif ($impressions >= 10 && $position <= 10 && $ctr < 0.02) $traffic += 10;
        $traffic += min(12.0, max(0.0, $growth['signal']) * 0.12);
        if ($growth['delta'] >= 30) $traffic += 5;
        elseif ($growth['delta'] >= 10) $traffic += 3;
        if ($bing_impressions >= 20 && $impressions >= 10) $traffic += 4;
        $traffic = max(0, min(100, (int) round($traffic)));

        $sales = 5.0 + $volume * 0.55;
        if ($intent === 'transaccional') $sales += 35;
        elseif ($intent === 'comercial') $sales += 25;
        elseif ($intent === 'informativa') $sales += 7;
        if ($type === 'category') $sales += 18;
        elseif ($type === 'product') $sales += 22;
        elseif (in_array($type, array('cluster','hub_primary','hub_secondary'), true)) $sales += 8;
        if ($position > 0 && $position <= 20) $sales += 12;
        elseif ($position > 20 && $position <= 50) $sales += 8;
        if (!empty($row['internal_search'])) $sales += 15;
        $products = isset($row['catalog']['products']) ? (int) $row['catalog']['products'] : null;
        if ($type === 'category' && null !== $products && $products > 0 && $products <= 5 && in_array($intent, array('comercial','transaccional'), true)) $sales += 7;
        $sales = max(0, min(100, (int) round($sales)));

        if ($type === 'page' && $role === 'corporate_page') {
            $authority = min($authority, 35);
            $traffic = min($traffic, 25);
            $sales = min($sales, 20);
        }

        return array(
            'authority' => $authority,
            'traffic' => $traffic,
            'sales' => $sales,
            'growth' => $growth,
        );
    }
}

if (!function_exists('seo_analista_business_confidence')) {
    function seo_analista_business_confidence(array $row, array $health) {
        $sources = array_map('seo_analista_normalize_text', (array) ($row['sources'] ?? array()));
        $source_text = implode(' ', $sources);
        $metrics = (array) ($row['metrics'] ?? array());
        $entity = (array) ($row['entity'] ?? array());
        $confidence = 10.0;

        if ((float) ($metrics['impressions'] ?? 0) > 0) $confidence += 32 * seo_analista_source_state_weight($health['search_console']['state'] ?? 'pending');
        if ($entity) $confidence += 20;
        if (strpos($source_text, 'vocabulary') !== false) $confidence += 8;
        if (strpos($source_text, 'google trends') !== false) $confidence += 10 * seo_analista_source_state_weight($health['trends']['state'] ?? 'pending');
        if (strpos($source_text, 'bing') !== false || (float) ($metrics['bing_impressions'] ?? 0) > 0) $confidence += 10 * seo_analista_source_state_weight($health['bing']['state'] ?? 'pending');
        if (!empty($row['competition'])) $confidence += 10 * seo_analista_source_state_weight($health['competition']['state'] ?? 'pending');
        if (!empty($row['internal_search'])) $confidence += 10 * seo_analista_source_state_weight($health['internal_search']['state'] ?? 'pending');
        if (!empty($row['market']['breakout'])) $confidence += 5 * seo_analista_source_state_weight($health['trends']['state'] ?? 'pending');
        return max(0, min(100, (int) round($confidence)));
    }
}

if (!function_exists('seo_analista_objective_label')) {
    function seo_analista_objective_label($key) {
        $map = array('authority'=>'AUTORIDAD','traffic'=>'VISITAS','sales'=>'VENTAS');
        return $map[$key] ?? strtoupper((string) $key);
    }
}

if (!function_exists('seo_analista_measurement_plan')) {
    function seo_analista_measurement_plan(array $row, $objective, array $health = array()) {
        $metrics = (array) ($row['metrics'] ?? array());
        $position = (float) ($metrics['position'] ?? 0);
        $impressions = (float) ($metrics['impressions'] ?? 0);
        $ctr = (float) ($metrics['ctr'] ?? 0);
        $out = array();

        if ($objective === 'traffic') {
            if ($position > 10 && $position <= 20) $out[] = 'Objetivo: entrar en Top 10 para las consultas ya detectadas.';
            elseif ($position > 20 && $position <= 50) $out[] = 'Objetivo: acercar la URL a Top 20 antes de ampliar a nuevas URLs.';
            elseif ($position > 0 && $position <= 10 && $ctr < 0.01) $out[] = 'Objetivo: aumentar CTR y clics manteniendo o mejorando la posicion actual.';
            else $out[] = 'Medir crecimiento de clics e impresiones cualificadas de la misma URL.';
        } elseif ($objective === 'sales') {
            $out[] = 'Medir clics organicos que llegan a categorias/productos y el avance de las consultas comerciales/transaccionales.';
            if (seo_analista_source_state_weight($health['ecommerce']['state'] ?? 'pending') >= 1) {
                $out[] = 'Validar impacto en compras e ingresos de GA4.';
            } else {
                $out[] = 'No usar compras/ingresos como criterio de exito hasta validar la medicion ecommerce.';
            }
        } else {
            $out[] = 'Medir mas consultas en Top 50/Top 20 y crecimiento de impresiones sobre la misma entidad.';
            $out[] = 'Comprobar que el enlazado interno y la arquitectura concentran autoridad en la URL canonica.';
        }
        if ($impressions > 0) $out[] = 'Linea base: ' . number_format_i18n($impressions, 0) . ' impresiones; posicion ' . number_format_i18n($position, 1) . '.';
        return array_values(array_unique($out));
    }
}

if (!function_exists('seo_analista_enrich_business_row')) {
    function seo_analista_enrich_business_row(array $row, array $health) {
        $scores = seo_analista_business_scores($row, $health);
        $objective_scores = array(
            'authority' => (int) $scores['authority'],
            'traffic' => (int) $scores['traffic'],
            'sales' => (int) $scores['sales'],
        );
        arsort($objective_scores);
        $keys = array_keys($objective_scores);
        $primary = $keys ? $keys[0] : 'authority';
        $values = array_values($objective_scores);
        $impact = ($values[0] ?? 0) * 0.55 + ($values[1] ?? 0) * 0.30 + ($values[2] ?? 0) * 0.15;
        $confidence = seo_analista_business_confidence($row, $health);
        $priority = (int) round($impact * 0.86 + $confidence * 0.14);

        $metrics = (array) ($row['metrics'] ?? array());
        $impressions = (float) ($metrics['impressions'] ?? 0);
        $bing_impressions = (float) ($metrics['bing_impressions'] ?? 0);
        $position = (float) ($metrics['position'] ?? 0);
        $has_demand_evidence = $impressions >= 10 || $bing_impressions >= 20 || !empty($row['internal_search']) || !empty($row['competition']) || !empty($row['market']['breakout']);
        if (!$has_demand_evidence) $priority -= 10;
        if ($position > 70 && $impressions < 100) $priority -= 7;
        if ($position <= 0 && $impressions <= 0 && $bing_impressions <= 0 && empty($row['internal_search']) && empty($row['competition'])) $priority -= 8;
        $priority = max(0, min(99, $priority));

        $bucket = 'SIN_ACCION';
        if ($priority >= 72 && $confidence >= 42 && $has_demand_evidence && max($objective_scores) >= 65) $bucket = 'HACER_AHORA';
        elseif ($priority >= 52) $bucket = 'HACER_DESPUES';
        elseif ($priority >= 35) $bucket = 'VIGILAR';

        $row['legacy_priority'] = (int) ($row['priority'] ?? 0);
        $row['priority'] = $priority;
        $row['work_bucket'] = $bucket;
        $row['objective'] = array(
            'primary' => $primary,
            'primary_label' => seo_analista_objective_label($primary),
            'authority' => (int) $scores['authority'],
            'traffic' => (int) $scores['traffic'],
            'sales' => (int) $scores['sales'],
        );
        $row['confidence'] = $confidence;
        $row['growth_quality'] = $scores['growth'];
        $row['measurement'] = seo_analista_measurement_plan($row, $primary, $health);
        $row['why_now'] = array_values(array_filter(array(
            $impressions > 0 ? number_format_i18n($impressions, 0) . ' impresiones organicas ya existentes.' : '',
            $position > 0 ? 'Posicion media ' . number_format_i18n($position, 1) . '.' : '',
            !empty($row['issues']) ? count((array) $row['issues']) . ' problemas corregibles detectados.' : '',
            'Objetivo principal: ' . seo_analista_objective_label($primary) . '.',
        )));
        return $row;
    }
}

if (!function_exists('seo_analista_bing_work')) {
    function seo_analista_bing_work($days = 28, $limit = 30) {
        if (!function_exists('seo_analista_bing_snapshot')) return array();
        $snapshot = (array) seo_analista_bing_snapshot($days, max(20, $limit));
        if (empty($snapshot['available'])) return array();
        $out = array();
        foreach ((array) ($snapshot['top_queries'] ?? array()) as $query_row) {
            $query = seo_analista_clean_query($query_row['query'] ?? $query_row['value'] ?? '');
            $impressions = (float) ($query_row['impressions'] ?? 0);
            if ($query === '' || !seo_analista_query_is_actionable($query) || $impressions < 5) continue;
            $local = function_exists('seo_analista_find_best_local_target') ? seo_analista_find_best_local_target($query, $days) : array();
            if ($local && !empty($local['row'])) {
                $row = (array) $local['row'];
                $row['sources'] = array_values(array_unique(array_merge((array) ($row['sources'] ?? array()), array('Bing Webmaster'))));
                $row['source'] = implode(' + ', $row['sources']);
                $row['evidence'] = array_values(array_unique(array_merge((array) ($row['evidence'] ?? array()), array($query))));
                $row['keywords'] = array_values(array_unique(array_merge((array) ($row['keywords'] ?? array()), array($query))));
                $row['metrics']['bing_impressions'] = $impressions;
                $row['metrics']['bing_clicks'] = (float) ($query_row['clicks'] ?? 0);
                $row['metrics']['bing_ctr'] = (float) ($query_row['ctr'] ?? 0);
                $row['metrics']['bing_position'] = (float) ($query_row['position'] ?? 0);
                $row['reason'] = rtrim((string) ($row['reason'] ?? '')) . ' Bing confirma demanda adicional para «' . $query . '».';
                $out[] = $row;
            } else {
                $meta = seo_analista_action_meta('REVISAR_COBERTURA');
                $out[] = array(
                    'priority' => 45,
                    'action' => 'REVISAR_COBERTURA',
                    'action_label' => $meta['label'],
                    'channel' => $meta['channel'],
                    'topic' => $query,
                    'reason' => 'Bing detecta demanda, pero no se ha localizado una URL canonica con afinidad suficiente. No crear una URL hasta validar el destino.',
                    'sources' => array('Bing Webmaster'),
                    'source' => 'Bing Webmaster',
                    'evidence' => array($query),
                    'keywords' => array($query),
                    'issues' => array(),
                    'recommended_changes' => array('Comprobar categoria, producto, Vocabulary y arquitectura antes de crear contenido nuevo.'),
                    'metrics' => array(
                        'bing_impressions' => $impressions,
                        'bing_clicks' => (float) ($query_row['clicks'] ?? 0),
                        'bing_ctr' => (float) ($query_row['ctr'] ?? 0),
                        'bing_position' => (float) ($query_row['position'] ?? 0),
                    ),
                    'intent' => seo_analista_intent($query),
                    'entity' => array(),
                    'target' => array('title'=>'','url'=>''),
                );
            }
            if (count($out) >= max(5, min(80, absint($limit)))) break;
        }
        return $out;
    }
}

if (!function_exists('seo_analista_prioritize_portfolio')) {
    function seo_analista_prioritize_portfolio(array $rows, $days = 28, $limit = 40, $now_limit = 10) {
        $health = seo_analista_source_health_all($days);
        $out = array();
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $out[] = seo_analista_enrich_business_row($row, $health);
        }

        $bucket_order = array('HACER_AHORA'=>0,'HACER_DESPUES'=>1,'VIGILAR'=>2,'SIN_ACCION'=>3);
        usort($out, static function($a, $b) use ($bucket_order) {
            $ab = $bucket_order[$a['work_bucket'] ?? 'SIN_ACCION'] ?? 9;
            $bb = $bucket_order[$b['work_bucket'] ?? 'SIN_ACCION'] ?? 9;
            if ($ab !== $bb) return $ab <=> $bb;
            $diff = (int) ($b['priority'] ?? 0) <=> (int) ($a['priority'] ?? 0);
            if ($diff) return $diff;
            return (float) ($b['metrics']['impressions'] ?? 0) <=> (float) ($a['metrics']['impressions'] ?? 0);
        });

        $now = 0;
        foreach ($out as &$row) {
            if (($row['work_bucket'] ?? '') !== 'HACER_AHORA') continue;
            $now++;
            if ($now > max(3, min(15, absint($now_limit)))) $row['work_bucket'] = 'HACER_DESPUES';
        }
        unset($row);

        usort($out, static function($a, $b) use ($bucket_order) {
            $ab = $bucket_order[$a['work_bucket'] ?? 'SIN_ACCION'] ?? 9;
            $bb = $bucket_order[$b['work_bucket'] ?? 'SIN_ACCION'] ?? 9;
            if ($ab !== $bb) return $ab <=> $bb;
            return (int) ($b['priority'] ?? 0) <=> (int) ($a['priority'] ?? 0);
        });
        return array_slice($out, 0, max(10, min(100, absint($limit))));
    }
}

if (!function_exists('seo_analista_strategy_summary')) {
    function seo_analista_strategy_summary(array $plan) {
        $out = array(
            'total' => count($plan),
            'hacer_ahora' => 0,
            'hacer_despues' => 0,
            'vigilar' => 0,
            'sin_accion' => 0,
            'authority' => 0,
            'traffic' => 0,
            'sales' => 0,
            'catalogo' => 0,
            'contenido' => 0,
            'seo' => 0,
            'estructura' => 0,
            'proveedores' => 0,
            'competencia' => 0,
            'seguimiento' => 0,
        );
        foreach ($plan as $row) {
            $bucket = (string) ($row['work_bucket'] ?? 'SIN_ACCION');
            if ($bucket === 'HACER_AHORA') $out['hacer_ahora']++;
            elseif ($bucket === 'HACER_DESPUES') $out['hacer_despues']++;
            elseif ($bucket === 'VIGILAR') $out['vigilar']++;
            else $out['sin_accion']++;
            $objective = (string) ($row['objective']['primary'] ?? '');
            if (isset($out[$objective])) $out[$objective]++;
            $channel = (string) ($row['channel'] ?? 'seo');
            if (isset($out[$channel])) $out[$channel]++;
            else $out['seo']++;
            if (!empty($row['competition'])) $out['competencia']++;
        }
        $out['high'] = $out['hacer_ahora'];
        return $out;
    }
}
