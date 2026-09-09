<?php
/**
 * Mercado y adaptadores externos del Analista.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_analista_market_signals')) {
    function seo_analista_market_signals($limit = 20) {
        if (!function_exists('seo_google_trends_market_summary')) return array();
        $rows = (array) seo_google_trends_market_summary(400);
        $usable = array();

        foreach ($rows as $row) {
            $kind = (string) ($row['signal_kind'] ?? '');
            if ('discovery' === $kind) continue;
            if ((float) ($row['score'] ?? 0) <= 0) continue;

            $row['catalog'] = function_exists('seo_google_opportunity_catalog_context')
                ? (array) seo_google_opportunity_catalog_context($row['query'] ?? '', (array) ($row['seeds'] ?? array()))
                : array();
            $usable[] = $row;
        }

        usort($usable, static function ($a, $b) {
            return (float) ($b['score'] ?? 0) <=> (float) ($a['score'] ?? 0);
        });

        return array_slice($usable, 0, max(5, min(50, absint($limit))));
    }
}

if (!function_exists('seo_analista_catalog_guidance')) {
    function seo_analista_catalog_guidance($days = 28, $limit = 12) {
        if (!seo_analista_is_ready() || !function_exists('seo_google_demand_get_catalog_guidance')) return array();
        $settings = seo_google_get_settings();
        $property_id = (string) ($settings['property_id'] ?? '');
        if ($property_id === '') return array();
        return (array) seo_google_demand_get_catalog_guidance($property_id, seo_analista_days($days), 2, max(5, min(30, absint($limit))));
    }
}

if (!function_exists('seo_analista_competitor_rankings')) {
    function seo_analista_competitor_rankings(array $keywords, array $competitors) {
        $keywords = array_values(array_filter(array_map('sanitize_text_field', $keywords)));
        $competitors = seo_analista_sanitize_domains($competitors);

        /**
         * Adaptador de proveedor externo.
         *
         * Un conector futuro (SEMrush API, DataForSEO, SerpAPI, etc.) puede
         * inyectar filas sin modificar el modulo Analista:
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
