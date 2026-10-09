<?php
defined('ABSPATH') || exit;

interface SEO_Ingeniero_Search_Provider {
    /**
     * @return array|WP_Error
     */
    public function search($query, $context = array());
}

final class SEO_Ingeniero_SerpApi_Provider implements SEO_Ingeniero_Search_Provider {
    const API_URL = 'https://serpapi.com/search.json';
    const USAGE_OPTION = 'seo_ingeniero_serpapi_usage_v1';
    const SETTINGS_OPTION = 'seo_ingeniero_settings';

    public static function defaults() {
        return array(
            'provider' => 'google_provider_chain',
            'monthly_query_limit' => 60,
            'results_per_query' => 6,
            'queries_per_category' => 3,
            'fetch_pages' => 4,
        );
    }

    public static function settings() {
        $stored = get_option(self::SETTINGS_OPTION, array());
        return wp_parse_args(is_array($stored) ? $stored : array(), self::defaults());
    }

    public static function save_settings($raw) {
        $current = self::settings();
        $raw = is_array($raw) ? $raw : array();
        $settings = array(
            'provider' => 'google_provider_chain',
            'monthly_query_limit' => max(1, min(100000, absint($raw['monthly_query_limit'] ?? $current['monthly_query_limit']))),
            'results_per_query' => max(3, min(10, absint($raw['results_per_query'] ?? $current['results_per_query']))),
            'queries_per_category' => max(1, min(4, absint($raw['queries_per_category'] ?? $current['queries_per_category']))),
            'fetch_pages' => max(0, min(8, absint($raw['fetch_pages'] ?? $current['fetch_pages']))),
        );
        update_option(self::SETTINGS_OPTION, $settings, false);
        return $settings;
    }

    private function api_key() {
        if (defined('SEO_OJEADOR_SERPAPI_KEY') && SEO_OJEADOR_SERPAPI_KEY) {
            return (string) SEO_OJEADOR_SERPAPI_KEY;
        }
        if (class_exists('SEO_Ojeador_Shopping') && is_callable(array('SEO_Ojeador_Shopping','settings'))) {
            $settings = SEO_Ojeador_Shopping::settings();
            return trim((string) ($settings['api_key'] ?? ''));
        }
        $ojeador = get_option('seo_ojeador_shopping_settings', array());
        return trim((string) (is_array($ojeador) ? ($ojeador['api_key'] ?? '') : ''));
    }

    public static function usage_month() {
        $month = gmdate('Y-m');
        $stored = get_option(self::USAGE_OPTION, array());
        $stored = is_array($stored) ? $stored : array();
        $used = absint($stored[$month] ?? 0);
        $limit = absint(self::settings()['monthly_query_limit'] ?? 60);
        return array(
            'month'=>$month,
            'used'=>$used,
            'limit'=>$limit,
            'remaining'=>max(0, $limit-$used),
        );
    }

    private static function record_request() {
        $month = gmdate('Y-m');
        $stored = get_option(self::USAGE_OPTION, array());
        $stored = is_array($stored) ? $stored : array();
        $stored[$month] = absint($stored[$month] ?? 0) + 1;
        if (count($stored) > 18) {
            ksort($stored);
            $stored = array_slice($stored, -18, null, true);
        }
        update_option(self::USAGE_OPTION, $stored, false);
    }

    public function search($query, $context = array()) {
        $query = trim((string) $query);
        if ($query === '') {
            return new WP_Error('ingeniero_query_empty', 'Consulta externa vacía.');
        }

        if (
            !class_exists('SEO_Ojeador_Shopping')
            || !is_callable(array('SEO_Ojeador_Shopping','search_google_web'))
        ) {
            return new WP_Error(
                'ingeniero_google_provider',
                'No está disponible la capa compartida de conexión Google de Ojeador.'
            );
        }

        // El contador local de Ingeniero es diagnóstico, no una cuota de
        // proveedor. La disponibilidad real la decide la cadena compartida
        // SerpApi -> ScraperAPI; por tanto no debe bloquear el fallback.
        $settings = self::settings();
        self::record_request();

        $provider_trace = array();
        $options = array('num'=>absint($settings['results_per_query']));
        if (sanitize_key((string)($context['lesson'] ?? '')) === SEO_Ingeniero::LESSON_CURRENT) {
            $options['news'] = 1;
            $options['recency_days'] = 90;
        }
        $body = SEO_Ojeador_Shopping::search_google_web(
            $query,
            $options,
            $provider_trace
        );
        if (is_wp_error($body)) {
            return new WP_Error(
                'ingeniero_google_provider',
                $body->get_error_message(),
                array(
                    'provider_trace'=>(array)($provider_trace['attempts'] ?? array()),
                    'provider'=>sanitize_key((string)($provider_trace['provider'] ?? '')),
                )
            );
        }

        $rows = array();
        foreach ((array) ($body['organic_results'] ?? array()) as $row) {
            if (!is_array($row)) continue;
            $url = esc_url_raw((string) ($row['link'] ?? $row['url'] ?? ''));
            if ($url === '') continue;
            $rows[] = array(
                'position'=>absint($row['position'] ?? 0),
                'title'=>sanitize_text_field((string) ($row['title'] ?? '')),
                'url'=>$url,
                'snippet'=>sanitize_textarea_field((string) ($row['snippet'] ?? $row['description'] ?? '')),
                'date'=>sanitize_text_field((string) ($row['date'] ?? '')),
                'source'=>sanitize_text_field((string) ($row['source'] ?? '')),
            );
        }

        return array(
            'query'=>$query,
            'results'=>$rows,
            'duration_ms'=>absint($provider_trace['duration_ms'] ?? 0),
            'search_id'=>sanitize_text_field((string) ($body['search_metadata']['id'] ?? '')),
            'provider'=>sanitize_key((string) ($provider_trace['provider'] ?? '')),
            'provider_attempts'=>(array) ($provider_trace['attempts'] ?? array()),
        );
    }
}
