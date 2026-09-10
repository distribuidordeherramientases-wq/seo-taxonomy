<?php
/**
 * Comparacion competitiva del Analista.
 *
 * Consume posiciones externas genericas y las reduce a indicadores, dominios,
 * brechas y palabras clave vigiladas. La vista no depende de ninguna marca o
 * proveedor concreto de datos.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_analista_competition_rows')) {
    function seo_analista_competition_rows() {
        $snapshot = seo_analista_competition_source_snapshot();
        $rows = (array) ($snapshot['rows'] ?? array());
        if ($rows) return $rows;

        // Permite que un conector de rankings sustituya al CSV sin tocar la vista.
        $settings = seo_analista_get_settings();
        return seo_analista_competitor_rankings(
            (array) ($settings['tracked_keywords'] ?? array()),
            (array) ($settings['competitors'] ?? array())
        );
    }
}

if (!function_exists('seo_analista_find_query_position')) {
    function seo_analista_find_query_position(array $rows, $keyword) {
        $keyword = seo_analista_clean_query($keyword);
        $needle = seo_analista_normalize_text($keyword);
        if ($needle === '') return array();

        $best = array();
        $best_similarity = 0.0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $query = seo_analista_clean_query($row['query_text'] ?? $row['query'] ?? '');
            if ($query === '') continue;
            $key = seo_analista_normalize_text($query);
            if ($key === $needle) return $row;

            $similarity = function_exists('seo_analista_topic_similarity')
                ? (float) seo_analista_topic_similarity($keyword, $query)
                : 0.0;
            if ($similarity >= 0.86 && $similarity > $best_similarity) {
                $best_similarity = $similarity;
                $best = $row;
            }
        }
        return $best;
    }
}

if (!function_exists('seo_analista_tracked_keyword_snapshot')) {
    function seo_analista_tracked_keyword_snapshot(array $current_queries, array $previous_queries) {
        $settings = seo_analista_get_settings();
        $tracked = seo_analista_sanitize_keywords((array) ($settings['tracked_keywords'] ?? array()));
        if (!$tracked) return array();

        $external_rows = seo_analista_competition_rows();
        $own_domain = preg_replace('/^www\./', '', strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST)));
        $out = array();

        foreach ($tracked as $keyword) {
            $current = seo_analista_find_query_position($current_queries, $keyword);
            $previous = seo_analista_find_query_position($previous_queries, $keyword);
            $own_position = (float) ($current['position'] ?? 0);
            $previous_position = (float) ($previous['position'] ?? 0);
            $own_change = ($own_position > 0 && $previous_position > 0)
                ? $previous_position - $own_position
                : null;

            $competitors = array();
            foreach ($external_rows as $row) {
                if (!is_array($row)) continue;
                $domain = preg_replace('/^www\./', '', strtolower(trim((string) ($row['domain'] ?? ''))));
                if ($domain === '' || $domain === $own_domain) continue;
                $row_keyword = seo_analista_clean_query($row['keyword'] ?? '');
                if ($row_keyword === '') continue;
                $exact = seo_analista_normalize_text($row_keyword) === seo_analista_normalize_text($keyword);
                $similarity = $exact ? 1.0 : (function_exists('seo_analista_topic_similarity') ? (float) seo_analista_topic_similarity($keyword, $row_keyword) : 0.0);
                if (!$exact && $similarity < 0.90) continue;
                $position = (float) ($row['position'] ?? 0);
                if ($position <= 0) continue;
                $competitors[] = array(
                    'domain' => $domain,
                    'position' => $position,
                    'url' => (string) ($row['url'] ?? ''),
                    'volume' => (float) ($row['volume'] ?? 0),
                    'difficulty' => (float) ($row['difficulty'] ?? 0),
                );
            }
            usort($competitors, static function($a, $b) {
                return (float) ($a['position'] ?? 0) <=> (float) ($b['position'] ?? 0);
            });
            $best = $competitors ? $competitors[0] : array();
            $best_position = (float) ($best['position'] ?? 0);
            $gap = ($own_position > 0 && $best_position > 0) ? $own_position - $best_position : null;

            $state = 'Sin comparación externa';
            if ($own_position > 0 && $best_position > 0) {
                if ($gap <= 0) {
                    $state = 'Por delante';
                } elseif (null !== $own_change && $own_change >= 1.0) {
                    $state = 'Acercándonos';
                } elseif (null !== $own_change && $own_change <= -1.0) {
                    $state = 'Alejándonos';
                } else {
                    $state = 'Distancia estable';
                }
            } elseif ($own_position > 0) {
                $state = 'Seguimiento propio';
            } elseif ($best_position > 0) {
                $state = 'Nosotros no visibles';
            }

            $periods_to_overtake = null;
            if (null !== $gap && $gap > 0 && null !== $own_change && $own_change > 0.5) {
                $estimate = (int) ceil($gap / $own_change);
                if ($estimate > 0 && $estimate <= 24) $periods_to_overtake = $estimate;
            }

            $out[] = array(
                'keyword' => $keyword,
                'own_position' => $own_position,
                'previous_own_position' => $previous_position,
                'own_change' => $own_change,
                'impressions' => (float) ($current['impressions'] ?? 0),
                'clicks' => (float) ($current['clicks'] ?? 0),
                'best_competitor' => $best,
                'gap' => $gap,
                'state' => $state,
                'periods_to_overtake' => $periods_to_overtake,
                'competitors' => array_slice($competitors, 0, 5),
            );
        }

        return $out;
    }
}

if (!function_exists('seo_analista_competition_snapshot')) {
    function seo_analista_competition_snapshot($limit = 50) {
        $snapshot = seo_analista_competition_source_snapshot();
        $rows = seo_analista_competition_rows();
        $own = (string) ($snapshot['own_domain'] ?? '');
        if ($own === '') {
            $own = preg_replace('/^www\./', '', strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST)));
        }

        $domains = array();
        $keywords = array();
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $keyword = seo_analista_clean_query($row['keyword'] ?? '');
            $domain = preg_replace('/^www\./', '', strtolower(trim((string) ($row['domain'] ?? ''))));
            $position = (float) ($row['position'] ?? 0);
            if ($keyword === '' || $domain === '' || $position <= 0) continue;

            if (!isset($domains[$domain])) {
                $domains[$domain] = array(
                    'domain' => $domain,
                    'keywords' => 0,
                    'top3' => 0,
                    'top10' => 0,
                    'top20' => 0,
                    'top50' => 0,
                    'top100' => 0,
                    'position_sum' => 0.0,
                    'visibility_points' => 0.0,
                );
            }
            $domains[$domain]['keywords']++;
            $domains[$domain]['position_sum'] += $position;
            if ($position <= 3) $domains[$domain]['top3']++;
            if ($position <= 10) $domains[$domain]['top10']++;
            if ($position <= 20) $domains[$domain]['top20']++;
            if ($position <= 50) $domains[$domain]['top50']++;
            if ($position <= 100) $domains[$domain]['top100']++;
            $domains[$domain]['visibility_points'] += max(0, 101 - min(100, $position));

            $key = seo_analista_normalize_text($keyword);
            if (!isset($keywords[$key])) {
                $keywords[$key] = array(
                    'keyword' => $keyword,
                    'volume' => (float) ($row['volume'] ?? 0),
                    'difficulty' => (float) ($row['difficulty'] ?? 0),
                    'own_position' => 0.0,
                    'own_url' => '',
                    'competitors' => array(),
                );
            }
            $keywords[$key]['volume'] = max($keywords[$key]['volume'], (float) ($row['volume'] ?? 0));
            $keywords[$key]['difficulty'] = max($keywords[$key]['difficulty'], (float) ($row['difficulty'] ?? 0));
            if ($domain === $own) {
                if ($keywords[$key]['own_position'] <= 0 || $position < $keywords[$key]['own_position']) {
                    $keywords[$key]['own_position'] = $position;
                    $keywords[$key]['own_url'] = (string) ($row['url'] ?? '');
                }
            } else {
                $keywords[$key]['competitors'][] = array(
                    'domain' => $domain,
                    'position' => $position,
                    'url' => (string) ($row['url'] ?? ''),
                );
            }
        }

        foreach ($domains as &$domain) {
            $domain['average_position'] = $domain['keywords'] > 0
                ? round($domain['position_sum'] / $domain['keywords'], 1)
                : 0.0;
            $domain['visibility_index'] = $domain['keywords'] > 0
                ? round(($domain['visibility_points'] / ($domain['keywords'] * 100)) * 100, 1)
                : 0.0;
            unset($domain['position_sum'], $domain['visibility_points']);
        }
        unset($domain);

        uasort($domains, static function($a, $b) {
            if ((float) $a['visibility_index'] === (float) $b['visibility_index']) {
                return (int) $b['top10'] <=> (int) $a['top10'];
            }
            return (float) $b['visibility_index'] <=> (float) $a['visibility_index'];
        });

        $gaps = array();
        foreach ($keywords as $item) {
            if (!$item['competitors']) continue;
            usort($item['competitors'], static function($a, $b) {
                return (float) $a['position'] <=> (float) $b['position'];
            });
            $best = $item['competitors'][0];
            $own_position = (float) $item['own_position'];
            $best_position = (float) $best['position'];
            if ($best_position <= 0) continue;

            $missing = $own_position <= 0;
            $gap = $missing ? 101 - $best_position : $own_position - $best_position;
            if (!$missing && $gap < 3) continue;

            $volume = (float) $item['volume'];
            $difficulty = (float) $item['difficulty'];
            $score = 40;
            $score += min(25, log(1 + max(0, $volume)) * 4.0);
            $score += min(20, max(0, $gap) * 0.45);
            $score += $missing ? 10 : 0;
            $score -= min(15, max(0, $difficulty) * 0.15);

            $gaps[] = array(
                'keyword' => $item['keyword'],
                'own_position' => $own_position,
                'best_competitor' => $best,
                'gap' => round($gap, 1),
                'volume' => $volume,
                'difficulty' => $difficulty,
                'priority' => max(0, min(100, (int) round($score))),
                'competitors' => array_slice($item['competitors'], 0, 5),
                'own_url' => $item['own_url'],
            );
        }

        usort($gaps, static function($a, $b) {
            if ((int) $a['priority'] === (int) $b['priority']) {
                return (float) $b['volume'] <=> (float) $a['volume'];
            }
            return (int) $b['priority'] <=> (int) $a['priority'];
        });

        $history = function_exists('seo_analista_competition_history') ? seo_analista_competition_history() : array();
        $previous_domains = array();
        if (count($history) >= 2) {
            $previous_entry = $history[count($history) - 2];
            foreach ((array) ($previous_entry['domains'] ?? array()) as $row) {
                $domain_key = (string) ($row['domain'] ?? '');
                if ($domain_key !== '') $previous_domains[$domain_key] = $row;
            }
        }

        foreach ($domains as &$domain) {
            $previous = (array) ($previous_domains[$domain['domain']] ?? array());
            $domain['visibility_change'] = isset($previous['visibility_index'])
                ? round((float) $domain['visibility_index'] - (float) $previous['visibility_index'], 1)
                : null;
            $domain['top10_change'] = isset($previous['top10'])
                ? (int) $domain['top10'] - (int) $previous['top10']
                : null;
        }
        unset($domain);

        $own_summary = isset($domains[$own]) ? $domains[$own] : array(
            'domain' => $own,
            'keywords' => 0,
            'top3' => 0,
            'top10' => 0,
            'top20' => 0,
            'top50' => 0,
            'top100' => 0,
            'average_position' => 0.0,
            'visibility_index' => 0.0,
            'visibility_change' => null,
            'top10_change' => null,
        );

        return array(
            'available' => !empty($rows),
            'source' => !empty($snapshot['rows']) ? 'competition_csv' : (!empty($rows) ? 'ranking_adapter' : ''),
            'imported_at' => (string) ($snapshot['imported_at'] ?? ''),
            'own_domain' => $own,
            'own' => $own_summary,
            'domains' => array_values($domains),
            'keyword_gaps' => array_slice($gaps, 0, max(10, min(200, absint($limit)))),
            'row_count' => count($rows),
            'history' => array_slice($history, -12),
        );
    }
}
