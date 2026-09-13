<?php
/**
 * Evolucion organica del Analista.
 *
 * Calcula movimiento real entre periodos con los datos locales de Search
 * Console. La metrica es propia y transparente: muestra
 * cuantas consultas avanzan, retroceden o entran en cada tramo de SERP.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_analista_movement_snapshot')) {
    function seo_analista_movement_snapshot(array $current_rows, array $previous_rows) {
        $current = seo_analista_rows_map($current_rows, 'query_hash');
        $previous = seo_analista_rows_map($previous_rows, 'query_hash');

        $out = array(
            'improved' => 0,
            'stable' => 0,
            'declined' => 0,
            'new' => 0,
            'lost' => 0,
            'entered_top100' => 0,
            'entered_top50' => 0,
            'entered_top20' => 0,
            'entered_top10' => 0,
            'entered_top3' => 0,
            'left_top100' => 0,
            'net_top100' => 0,
            'net_top50' => 0,
            'net_top20' => 0,
            'net_top10' => 0,
            'net_top3' => 0,
        );

        foreach ($current as $hash => $row) {
            $position = (float) ($row['position'] ?? 0);
            if ($position <= 0) continue;

            if (!isset($previous[$hash])) {
                $out['new']++;
                if ($position <= 100) $out['entered_top100']++;
                if ($position <= 50) $out['entered_top50']++;
                if ($position <= 20) $out['entered_top20']++;
                if ($position <= 10) $out['entered_top10']++;
                if ($position <= 3) $out['entered_top3']++;
                continue;
            }

            $previous_position = (float) ($previous[$hash]['position'] ?? 0);
            if ($previous_position <= 0) {
                $out['new']++;
                continue;
            }

            $movement = $previous_position - $position; // positivo = mejora.
            if ($movement >= 1.0) {
                $out['improved']++;
            } elseif ($movement <= -1.0) {
                $out['declined']++;
            } else {
                $out['stable']++;
            }

            foreach (array(100, 50, 20, 10, 3) as $threshold) {
                if ($previous_position > $threshold && $position <= $threshold) {
                    $out['entered_top' . $threshold]++;
                }
            }
        }

        foreach ($previous as $hash => $row) {
            if (isset($current[$hash])) continue;
            $position = (float) ($row['position'] ?? 0);
            if ($position <= 0) continue;
            $out['lost']++;
            if ($position <= 100) $out['left_top100']++;
        }

        $current_distribution = seo_analista_distribution($current_rows);
        $previous_distribution = seo_analista_distribution($previous_rows);
        $threshold_total = static function(array $distribution, $threshold) {
            $value = (int) ($distribution['top3'] ?? 0);
            if ($threshold >= 10) $value += (int) ($distribution['top10'] ?? 0);
            if ($threshold >= 20) $value += (int) ($distribution['top20'] ?? 0);
            if ($threshold >= 50) $value += (int) ($distribution['top50'] ?? 0);
            if ($threshold >= 100) $value += (int) ($distribution['top100'] ?? 0);
            return $value;
        };

        foreach (array(100, 50, 20, 10, 3) as $threshold) {
            $out['net_top' . $threshold] = $threshold_total($current_distribution, $threshold)
                - $threshold_total($previous_distribution, $threshold);
        }

        return $out;
    }
}

if (!function_exists('seo_analista_trend_series')) {
    function seo_analista_trend_series($days = 180) {
        $days = max(28, min(365, absint($days)));
        $property_id = seo_analista_resolve_property_id();
        if ($property_id === '' || !function_exists('seo_google_get_summary_trend_data')) return array();
        return (array) seo_google_get_summary_trend_data($property_id, $days);
    }
}

if (!function_exists('seo_analista_evolution_snapshot')) {
    function seo_analista_evolution_snapshot($days = 28) {
        $data = seo_analista_get_data($days);
        return array(
            'ready' => !empty($data['ready']),
            'period' => (array) ($data['period'] ?? array()),
            'visibility_index' => (float) ($data['visibility_index'] ?? 0),
            'previous_visibility_index' => (float) ($data['previous_visibility_index'] ?? 0),
            'distribution' => (array) ($data['distribution'] ?? array()),
            'previous_distribution' => (array) ($data['previous_distribution'] ?? array()),
            'movement' => seo_analista_movement_snapshot(
                (array) ($data['queries'] ?? array()),
                (array) ($data['previous_queries'] ?? array())
            ),
            'trend' => !empty($data['ready']) ? seo_analista_trend_series(max(120, seo_analista_days($days) * 3)) : array(),
        );
    }
}
