<?php
/**
 * Google Shopping client for Ojeador via SerpApi.
 *
 * Ojeador does not scrape merchant sites. It asks a structured Google Shopping
 * results provider for matching products and their store offers.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @since 0.6.0
 */

defined('ABSPATH') || exit;

final class SEO_Ojeador_Shopping {
    const OPTION_SETTINGS = 'seo_ojeador_shopping_settings';
    const API_URL = 'https://serpapi.com/search.json';
    const ACCOUNT_URL = 'https://serpapi.com/account.json';
    const OPTION_USAGE = 'seo_ojeador_serpapi_usage_v1'; // local outbound request attempts, not billing.
    const ACCOUNT_TRANSIENT = 'seo_ojeador_serpapi_account_v1';

    public static function defaults() {
        return array(
            'api_key' => '',
            'auto_enabled' => 0,
            // Category market snapshots are intentionally slower than exact-product pricing.
            'interval_hours' => 720,
            // Number of category queries per worker pulse. It never limits Google results.
            'batch_size' => 1,
            // Reuse an identical category query locally before spending another API request.
            'query_reuse_hours' => 24,
            // Local safety ceiling for the current SerpApi plan. Increase it when the plan changes.
            'monthly_query_limit' => 250,
        );
    }

    public static function settings() {
        $stored = get_option(self::OPTION_SETTINGS, array());
        $settings = wp_parse_args(is_array($stored) ? $stored : array(), self::defaults());
        if (defined('SEO_OJEADOR_SERPAPI_KEY') && SEO_OJEADOR_SERPAPI_KEY) {
            $settings['api_key'] = (string) SEO_OJEADOR_SERPAPI_KEY;
        }
        return self::sanitize_settings($settings);
    }

    public static function sanitize_settings($raw) {
        $raw = wp_parse_args(is_array($raw) ? $raw : array(), self::defaults());
        return array(
            'api_key' => sanitize_text_field((string) $raw['api_key']),
            'auto_enabled' => empty($raw['auto_enabled']) ? 0 : 1,
            'interval_hours' => max(6, min(2160, absint($raw['interval_hours']))),
            'batch_size' => max(1, min(20, absint($raw['batch_size']))),
            'query_reuse_hours' => max(1, min(168, absint($raw['query_reuse_hours']))),
            'monthly_query_limit' => max(1, min(1000000, absint($raw['monthly_query_limit']))),
        );
    }

    public static function save_settings($raw) {
        $current = self::settings();
        $raw = is_array($raw) ? $raw : array();
        if (empty($raw['api_key']) && !defined('SEO_OJEADOR_SERPAPI_KEY')) {
            $raw['api_key'] = (string) ($current['api_key'] ?? '');
        }
        $settings = self::sanitize_settings($raw);
        update_option(self::OPTION_SETTINGS, $settings, false);
        return $settings;
    }

    public static function readiness() {
        $s = self::settings();
        if ($s['api_key'] === '') {
            return new WP_Error('ojeador_shopping_key', 'Falta la API key de SerpApi para consultar Google Shopping.');
        }
        $usage = self::usage_month();
        if ($usage['limit'] > 0 && $usage['used'] >= $usage['limit']) {
            return new WP_Error('ojeador_shopping_budget', 'Se ha alcanzado el límite mensual configurado de consultas a SerpApi.');
        }
        return true;
    }

    /**
     * Authoritative SerpApi account usage. Account API is not a search and is
     * cached briefly so workers do not add unnecessary network latency.
     */
    public static function provider_usage($force = false) {
        $settings = self::settings();
        if ($settings['api_key'] === '') {
            return new WP_Error('ojeador_shopping_key', 'Falta la API key de SerpApi.');
        }
        if (!$force) {
            $cached = get_transient(self::ACCOUNT_TRANSIENT);
            if (is_array($cached)) {
                if (!empty($cached['_error'])) {
                    return new WP_Error(
                        sanitize_key((string) ($cached['_error_code'] ?? 'ojeador_serpapi_account')),
                        sanitize_text_field((string) $cached['_error'])
                    );
                }
                return $cached;
            }
        }

        $response = wp_safe_remote_get(add_query_arg(array('api_key'=>$settings['api_key']), self::ACCOUNT_URL), array(
            'timeout' => 15,
            'redirection' => 2,
            'headers' => array(
                'Accept' => 'application/json',
                'User-Agent' => 'SEO-System-Ojeador/' . (defined('SEO_OJEADOR_VERSION') ? SEO_OJEADOR_VERSION : '0.6.4'),
            ),
        ));
        if (is_wp_error($response)) {
            set_transient(self::ACCOUNT_TRANSIENT, array(
                '_error' => $response->get_error_message(),
                '_error_code' => $response->get_error_code(),
            ), 30);
            return $response;
        }
        $code = absint(wp_remote_retrieve_response_code($response));
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($body)) {
            $error = new WP_Error('ojeador_serpapi_account_http', 'SerpApi Account API HTTP ' . $code . '.');
            set_transient(self::ACCOUNT_TRANSIENT, array(
                '_error' => $error->get_error_message(),
                '_error_code' => $error->get_error_code(),
            ), 30);
            return $error;
        }
        if (!empty($body['error'])) {
            $message = is_array($body['error']) ? (string) ($body['error']['message'] ?? 'Error de SerpApi Account API.') : (string) $body['error'];
            $error = new WP_Error('ojeador_serpapi_account_api', sanitize_text_field($message));
            set_transient(self::ACCOUNT_TRANSIENT, array(
                '_error' => $error->get_error_message(),
                '_error_code' => $error->get_error_code(),
            ), 30);
            return $error;
        }

        // Deliberately exclude api_key/account_email from the returned snapshot.
        $clean = array(
            'account_status' => sanitize_text_field((string) ($body['account_status'] ?? '')),
            'plan_id' => sanitize_key((string) ($body['plan_id'] ?? '')),
            'plan_name' => sanitize_text_field((string) ($body['plan_name'] ?? '')),
            'plan_renewal_date' => sanitize_text_field((string) ($body['plan_renewal_date'] ?? '')),
            'searches_per_month' => absint($body['searches_per_month'] ?? 0),
            'this_month_usage' => absint($body['this_month_usage'] ?? 0),
            'plan_searches_left' => absint($body['plan_searches_left'] ?? 0),
            'total_searches_left' => absint($body['total_searches_left'] ?? 0),
            'this_hour_searches' => absint($body['this_hour_searches'] ?? 0),
            'last_hour_searches' => absint($body['last_hour_searches'] ?? 0),
            'account_rate_limit_per_hour' => absint($body['account_rate_limit_per_hour'] ?? 0),
            'checked_at_utc' => gmdate('Y-m-d H:i:s'),
        );
        set_transient(self::ACCOUNT_TRANSIENT, $clean, 30);
        return $clean;
    }

    /**
     * Monthly usage used by Ojeador.
     *
     * `used` is provider-authoritative whenever Account API is available.
     * `local_requests` is only our outbound attempt counter; cached SerpApi
     * searches are free, so it is expected that both values can differ.
     */
    public static function usage_month() {
        $settings = self::settings();
        $month = gmdate('Y-m');
        $stored = get_option(self::OPTION_USAGE, array());
        $stored = is_array($stored) ? $stored : array();
        $local_requests = isset($stored[$month]) ? absint($stored[$month]) : 0;
        $configured_limit = absint($settings['monthly_query_limit']);

        $provider = self::provider_usage(false);
        if (!is_wp_error($provider)) {
            $provider_used = absint($provider['this_month_usage'] ?? 0);
            $provider_limit = absint($provider['searches_per_month'] ?? 0);
            $effective_limit = $configured_limit;
            if ($provider_limit > 0) {
                $effective_limit = $effective_limit > 0 ? min($effective_limit, $provider_limit) : $provider_limit;
            }
            return array(
                'month' => $month,
                'used' => $provider_used,
                'limit' => $effective_limit,
                'remaining' => $effective_limit > 0 ? max(0, $effective_limit - $provider_used) : 0,
                'source' => 'serpapi_account',
                'local_requests' => $local_requests,
                'provider_used' => $provider_used,
                'provider_limit' => $provider_limit,
                'provider_remaining' => absint($provider['total_searches_left'] ?? $provider['plan_searches_left'] ?? 0),
                'difference_local_minus_provider' => $local_requests - $provider_used,
                'provider' => $provider,
            );
        }

        return array(
            'month' => $month,
            'used' => $local_requests,
            'limit' => $configured_limit,
            'remaining' => $configured_limit > 0 ? max(0, $configured_limit - $local_requests) : 0,
            'source' => 'local_fallback',
            'local_requests' => $local_requests,
            'provider_used' => null,
            'provider_limit' => null,
            'provider_remaining' => null,
            'difference_local_minus_provider' => null,
            'provider_error' => $provider->get_error_message(),
        );
    }

    private static function record_request_attempt() {
        $month = gmdate('Y-m');
        $stored = get_option(self::OPTION_USAGE, array());
        $stored = is_array($stored) ? $stored : array();
        $stored[$month] = absint($stored[$month] ?? 0) + 1;
        if (count($stored) > 18) {
            ksort($stored);
            $stored = array_slice($stored, -18, null, true);
        }
        update_option(self::OPTION_USAGE, $stored, false);
    }

    private static function trace_error($code, $message, $trace = array()) {
        $error = new WP_Error($code, $message);
        $error->add_data(array(
            'ojeador_api_queries' => absint($trace['api_queries'] ?? 0),
            'ojeador_query_log_id' => absint($trace['query_log_id'] ?? 0),
        ));
        return $error;
    }

    public static function build_query($identity) {
        $identity = is_array($identity) ? $identity : array();
        $gtin = SEO_Ojeador_Identity::normalize_gtin($identity['gtin'] ?? '');
        $mpn = trim((string) ($identity['mpn'] ?? ''));
        $brand = trim((string) ($identity['brand'] ?? ''));
        $model = trim((string) ($identity['model'] ?? ''));
        $name = trim((string) ($identity['name'] ?? ''));

        if ($gtin !== '') {
            return $gtin;
        }
        if ($brand !== '' && $mpn !== '') {
            return trim($brand . ' ' . $mpn);
        }
        if ($brand !== '' && $model !== '') {
            return trim($brand . ' ' . $model);
        }
        if ($mpn !== '') {
            return $mpn;
        }
        if ($model !== '') {
            return trim($brand . ' ' . $model);
        }
        return $name;
    }

    /**
     * Build ordered query candidates without spending API requests.
     *
     * Admission is strict: only approved mappings with an explicit
     * shopping_query are eligible. The operational query is always first; the
     * remaining reviewed labels are retained only as diagnostic candidates.
     *
     * @param int|array $category Category term ID or category context.
     * @return array
     */
    public static function build_category_query_candidates($category) {
        if (is_numeric($category)) {
            $category = SEO_Ojeador_DB::category_context(absint($category));
        }
        if (is_wp_error($category) || !is_array($category)) {
            return array();
        }

        $term_id = absint($category['term_id'] ?? 0);
        if ($term_id < 1 || !function_exists('seo_classifier_google_schema_get_mapping')) {
            return array();
        }

        $mapping = seo_classifier_google_schema_get_mapping('product_cat', $term_id);
        if (!is_array($mapping) || (string) ($mapping['status'] ?? '') !== 'approved') {
            return array();
        }

        // shopping_query is now the admission ticket for Ojeador. This field is
        // the reviewed operational phrase for Google Shopping; categories in
        // review/pending or approved rows without it are never queried.
        $shopping_query = sanitize_text_field((string) ($mapping['shopping_query'] ?? ''));
        if ($shopping_query === '') {
            return array();
        }

        $candidates = array($shopping_query);
        foreach (array('google_alias_es', 'suggested_wp_name', 'google_name_en') as $field) {
            $value = sanitize_text_field((string) ($mapping[$field] ?? ''));
            if ($value !== '') {
                $candidates[] = $value;
            }
        }

        $unique = array();
        $seen = array();
        foreach ($candidates as $candidate) {
            $candidate = trim((string) apply_filters('seo_ojeador_category_query_candidate', $candidate, $category));
            if ($candidate === '') {
                continue;
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($candidate, 'UTF-8') : strtolower($candidate);
            $key = preg_replace('/\s+/u', ' ', $key);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $candidate;
        }
        return $unique;
    }

    /**
     * Build the single market query used for this category.
     *
     * Ojeador intentionally performs no speculative multi-query fan-out. A good
     * shopping_query can therefore improve recall without multiplying API cost.
     *
     * @param int|array $category Category term ID or category context.
     * @return string
     */
    public static function build_category_query($category) {
        if (is_numeric($category)) {
            $category = SEO_Ojeador_DB::category_context(absint($category));
        }
        if (is_wp_error($category) || !is_array($category)) {
            return '';
        }
        $candidates = self::build_category_query_candidates($category);
        $query = $candidates ? (string) reset($candidates) : '';
        return trim((string) apply_filters('seo_ojeador_category_query', $query, $category, $candidates));
    }

    /**
     * Extract every product block available in one Google Shopping response.
     *
     * Besides the main shopping_results array, Google can return categorized
     * shopping blocks in the same response. Flattening them here increases the
     * number of useful market rows without issuing extra searches.
     *
     * @param array $search Raw SerpApi response.
     * @return array
     */
    private static function collect_category_results($search) {
        $search = is_array($search) ? $search : array();
        $rows = array();

        foreach ((array) ($search['shopping_results'] ?? array()) as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        foreach ((array) ($search['categorized_shopping_results'] ?? array()) as $group) {
            if (!is_array($group)) {
                continue;
            }
            foreach ((array) ($group['shopping_results'] ?? array()) as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }

        // Kept defensive: some Google layouts expose additional shopping blocks
        // under these keys. If absent, this costs nothing.
        foreach (array('inline_shopping_results', 'featured_shopping_results') as $key) {
            foreach ((array) ($search[$key] ?? array()) as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }

        $results = array();
        $seen = array();
        foreach ($rows as $row) {
            $normalized = self::normalize_category_result($row);
            if ($normalized === null) {
                continue;
            }
            $product_id = sanitize_text_field((string) ($normalized['google_product_id'] ?? ''));
            if ($product_id !== '') {
                $key = 'google|' . $product_id . '|' . sanitize_title((string) ($normalized['merchant'] ?? ''));
            } else {
                $key = 'fallback|' . strtolower(
                    (string) ($normalized['title'] ?? '') . '|' .
                    (string) ($normalized['merchant'] ?? '') . '|' .
                    (string) ($normalized['price'] ?? '') . '|' .
                    (string) ($normalized['merchant_url'] ?? '')
                );
            }
            $hash = hash('sha256', $key);
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $results[] = $normalized;
        }
        return $results;
    }

    /**
     * One unique category query = at most one Google Shopping request.
     *
     * Identical queries scanned recently are reused from Ojeador's own database,
     * so two categories with the same operational query do not spend two API
     * requests. Google Shopping currently returns a fixed first-page result set;
     * Ojeador deliberately does not paginate or issue broadening retries.
     *
     * @param int|array $category WooCommerce category term ID/context.
     * @return array|WP_Error
     */
    public static function scan_category($category, $run_id = 0) {
        $ready = self::readiness();
        if (is_wp_error($ready)) {
            return $ready;
        }
        if (is_numeric($category)) {
            $category = SEO_Ojeador_DB::category_context(absint($category));
        }
        if (is_wp_error($category) || !is_array($category)) {
            return new WP_Error('ojeador_category', 'Categoría no válida para Google Shopping.');
        }
        $query = self::build_category_query($category);
        $term_id = absint($category['term_id'] ?? 0);
        if ($query === '') {
            $log_id = SEO_Ojeador_DB::create_query_log(array(
                'run_id' => absint($run_id),
                'context_type' => 'category',
                'term_id' => $term_id,
                'category_name' => (string) ($category['name'] ?? ''),
                'query_text' => '',
                'engine' => 'google_shopping',
                'event_status' => 'query_error',
                'request_attempted' => 0,
                'error_code' => 'ojeador_category_query',
                'error_message' => 'La categoría no genera una consulta válida.',
                'completed_at' => SEO_Ojeador_DB::utc_now(),
            ));
            return self::trace_error('ojeador_category_query', 'La categoría no genera una consulta válida.', array(
                'api_queries' => 0,
                'query_log_id' => is_wp_error($log_id) ? 0 : absint($log_id),
            ));
        }

        $settings = self::settings();
        $reuse_hours = max(1, absint($settings['query_reuse_hours'] ?? 24));
        if ($term_id > 0 && method_exists('SEO_Ojeador_DB', 'recent_category_scan_for_query')) {
            $reused = SEO_Ojeador_DB::recent_category_scan_for_query($query, $term_id, $reuse_hours);
            if (is_array($reused)) {
                $count = count((array) ($reused['results'] ?? array()));
                $log_id = SEO_Ojeador_DB::create_query_log(array(
                    'run_id' => absint($run_id),
                    'context_type' => 'category',
                    'term_id' => $term_id,
                    'category_name' => (string) ($category['name'] ?? ''),
                    'query_text' => $query,
                    'engine' => 'google_shopping',
                    'event_status' => 'reused',
                    'request_attempted' => 0,
                    'raw_result_count' => $count,
                    'normalized_result_count' => $count,
                    'saved_result_count' => 0,
                    'metadata' => array(
                        'reused_from_term_id' => absint($reused['reused_from_term_id'] ?? 0),
                        'source_last_scan_at' => (string) (($reused['raw_search']['source_last_scan_at'] ?? '')),
                    ),
                    'completed_at' => SEO_Ojeador_DB::utc_now(),
                ));
                $reused['query'] = $query;
                $reused['api_queries'] = 0;
                $reused['query_log_id'] = is_wp_error($log_id) ? 0 : absint($log_id);
                $reused['reused'] = 1;
                return $reused;
            }
        }

        $trace = array();
        $search = self::request(array(
            'engine' => 'google_shopping',
            'q' => $query,
            'google_domain' => 'google.es',
            'gl' => 'es',
            'hl' => 'es',
            'device' => 'desktop',
            // no_cache is intentionally omitted: SerpApi may serve an identical
            // request from its one-hour cache without charging another search.
        ), array(
            'run_id' => absint($run_id),
            'context_type' => 'category',
            'term_id' => $term_id,
            'category_name' => (string) ($category['name'] ?? ''),
            'query_text' => $query,
        ), $trace);
        if (is_wp_error($search)) {
            return $search;
        }

        $results = self::collect_category_results($search);
        $log_id = absint($trace['query_log_id'] ?? 0);
        if ($log_id > 0) {
            SEO_Ojeador_DB::update_query_log($log_id, array(
                'event_status' => 'parsed',
                'normalized_result_count' => count($results),
            ));
        }

        return array(
            'status' => $results ? 'ok' : 'no_results',
            'query' => $query,
            'results' => $results,
            'api_queries' => absint($trace['api_queries'] ?? 1),
            'query_log_id' => $log_id,
            'reused' => 0,
            'raw_search' => self::compact_raw($search),
        );
    }

    /**
     * Find one Google Shopping product and return its store offers.
     *
     * @param array $identity Canonical WooCommerce identity.
     * @return array|WP_Error
     */
    public static function scan($identity) {
        $ready = self::readiness();
        if (is_wp_error($ready)) {
            return $ready;
        }

        $query = self::build_query($identity);
        if ($query === '') {
            return new WP_Error('ojeador_identity_empty', 'El producto no tiene datos suficientes para consultar Google Shopping.');
        }

        $search = self::request(array(
            'engine' => 'google_shopping',
            'q' => $query,
            'google_domain' => 'google.es',
            'gl' => 'es',
            'hl' => 'es',
            'device' => 'desktop',
        ));
        if (is_wp_error($search)) {
            return $search;
        }

        $candidate = self::best_candidate((array) ($search['shopping_results'] ?? array()), $identity, $query);
        if (!$candidate) {
            return array(
                'status' => 'no_match',
                'query' => $query,
                'google_product_id' => '',
                'google_title' => '',
                'match_confidence' => 0,
                'offers' => array(),
                'raw_search' => self::compact_raw($search),
            );
        }

        $offers = array();
        $token = (string) ($candidate['immersive_product_page_token'] ?? '');
        if ($token !== '') {
            $details = self::request(array(
                'engine' => 'google_immersive_product',
                'page_token' => $token,
                'more_stores' => 'true',
                'google_domain' => 'google.es',
                'gl' => 'es',
                'hl' => 'es',
                'device' => 'desktop',
            ));
            if (!is_wp_error($details)) {
                $offers = self::extract_stores($details);
            }
        }

        // Fallback: Google Shopping results themselves already expose seller and
        // price. This keeps the system useful when immersive store details are
        // unavailable for a particular product.
        if (!$offers) {
            $offers = self::extract_search_offers((array) ($search['shopping_results'] ?? array()), $identity);
        }

        // No recortamos la respuesta: se conservan todas las ofertas que
        // Google Shopping/SerpApi haya devuelto para esta consulta.

        return array(
            'status' => $offers ? 'ok' : 'no_offers',
            'query' => $query,
            'google_product_id' => sanitize_text_field((string) ($candidate['product_id'] ?? '')),
            'google_title' => sanitize_text_field((string) ($candidate['title'] ?? '')),
            'match_confidence' => (float) ($candidate['_ojeador_score'] ?? 0),
            'offers' => $offers,
            'raw_search' => self::compact_raw($search),
        );
    }

    private static function request($args, $context = array(), &$trace = null) {
        $s = self::settings();
        $args = (array) $args;
        $context = is_array($context) ? $context : array();
        $trace = array(
            'api_queries' => 0,
            'query_log_id' => 0,
            'raw_result_count' => 0,
        );

        $query = sanitize_text_field((string) ($context['query_text'] ?? $args['q'] ?? ''));
        $engine = sanitize_key((string) ($args['engine'] ?? 'google_shopping')) ?: 'google_shopping';
        $log_id = SEO_Ojeador_DB::create_query_log(array(
            'run_id' => absint($context['run_id'] ?? 0),
            'context_type' => sanitize_key((string) ($context['context_type'] ?? 'request')) ?: 'request',
            'term_id' => absint($context['term_id'] ?? 0),
            'object_id' => absint($context['object_id'] ?? 0),
            'category_name' => (string) ($context['category_name'] ?? ''),
            'query_text' => $query,
            'engine' => $engine,
            'event_status' => 'prepared',
            'request_attempted' => 0,
        ));
        if (!is_wp_error($log_id)) {
            $trace['query_log_id'] = absint($log_id);
        }

        $usage = self::usage_month();
        if ($usage['limit'] > 0 && $usage['used'] >= $usage['limit']) {
            if ($trace['query_log_id'] > 0) {
                SEO_Ojeador_DB::update_query_log($trace['query_log_id'], array(
                    'event_status' => 'budget_blocked',
                    'error_code' => 'ojeador_shopping_budget',
                    'error_message' => 'Límite mensual de consultas alcanzado.',
                    'completed_at' => SEO_Ojeador_DB::utc_now(),
                ));
            }
            return self::trace_error('ojeador_shopping_budget', 'Límite mensual de consultas alcanzado.', $trace);
        }

        $args = array_merge($args, array('api_key' => $s['api_key']));
        $url = add_query_arg($args, self::API_URL);
        $started = microtime(true);
        self::record_request_attempt();
        $trace['api_queries'] = 1;
        if ($trace['query_log_id'] > 0) {
            SEO_Ojeador_DB::update_query_log($trace['query_log_id'], array(
                'event_status' => 'requesting',
                'request_attempted' => 1,
            ));
        }

        $response = wp_safe_remote_get($url, array(
            'timeout' => 35,
            'redirection' => 3,
            'headers' => array(
                'Accept' => 'application/json',
                'User-Agent' => 'SEO-System-Ojeador/' . (defined('SEO_OJEADOR_VERSION') ? SEO_OJEADOR_VERSION : '0.6.4'),
            ),
        ));
        $duration_ms = max(0, (int) round((microtime(true) - $started) * 1000));

        if (is_wp_error($response)) {
            if ($trace['query_log_id'] > 0) {
                SEO_Ojeador_DB::update_query_log($trace['query_log_id'], array(
                    'event_status' => 'network_error',
                    'duration_ms' => $duration_ms,
                    'error_code' => $response->get_error_code(),
                    'error_message' => $response->get_error_message(),
                    'completed_at' => SEO_Ojeador_DB::utc_now(),
                ));
            }
            return self::trace_error($response->get_error_code() ?: 'ojeador_network', $response->get_error_message(), $trace);
        }

        $code = absint(wp_remote_retrieve_response_code($response));
        $raw_body = wp_remote_retrieve_body($response);
        $body = json_decode($raw_body, true);
        if ($code < 200 || $code >= 300) {
            $message = 'Google Shopping API HTTP ' . $code . '.';
            if (is_array($body) && !empty($body['error'])) {
                $message = is_array($body['error']) ? (string) ($body['error']['message'] ?? $message) : (string) $body['error'];
            }
            if ($trace['query_log_id'] > 0) {
                SEO_Ojeador_DB::update_query_log($trace['query_log_id'], array(
                    'event_status' => 'http_error',
                    'http_code' => $code,
                    'duration_ms' => $duration_ms,
                    'error_code' => 'ojeador_shopping_http',
                    'error_message' => $message,
                    'completed_at' => SEO_Ojeador_DB::utc_now(),
                ));
            }
            return self::trace_error('ojeador_shopping_http', sanitize_text_field($message), $trace);
        }
        if (!is_array($body)) {
            $message = 'SerpApi devolvió una respuesta que no es JSON válido.';
            if ($trace['query_log_id'] > 0) {
                SEO_Ojeador_DB::update_query_log($trace['query_log_id'], array(
                    'event_status' => 'parse_error',
                    'http_code' => $code,
                    'duration_ms' => $duration_ms,
                    'error_code' => 'ojeador_shopping_json',
                    'error_message' => $message,
                    'completed_at' => SEO_Ojeador_DB::utc_now(),
                ));
            }
            return self::trace_error('ojeador_shopping_json', $message, $trace);
        }
        if (!empty($body['error'])) {
            $message = is_array($body['error']) ? (string) ($body['error']['message'] ?? 'Error de Google Shopping.') : (string) $body['error'];
            if ($trace['query_log_id'] > 0) {
                SEO_Ojeador_DB::update_query_log($trace['query_log_id'], array(
                    'event_status' => 'api_error',
                    'http_code' => $code,
                    'duration_ms' => $duration_ms,
                    'error_code' => 'ojeador_shopping_api',
                    'error_message' => $message,
                    'completed_at' => SEO_Ojeador_DB::utc_now(),
                ));
            }
            return self::trace_error('ojeador_shopping_api', sanitize_text_field($message), $trace);
        }

        $metadata = is_array($body['search_metadata'] ?? null) ? $body['search_metadata'] : array();
        $raw_result_count = self::count_response_results($body);
        $trace['raw_result_count'] = $raw_result_count;
        if ($trace['query_log_id'] > 0) {
            SEO_Ojeador_DB::update_query_log($trace['query_log_id'], array(
                'event_status' => 'response_ok',
                'http_code' => $code,
                'provider_search_id' => (string) ($metadata['id'] ?? ''),
                'provider_status' => (string) ($metadata['status'] ?? ''),
                'provider_created_at' => (string) ($metadata['created_at'] ?? ''),
                'provider_processed_at' => (string) ($metadata['processed_at'] ?? ''),
                'raw_result_count' => $raw_result_count,
                'duration_ms' => $duration_ms,
                'metadata' => array(
                    'search_metadata' => array(
                        'id' => (string) ($metadata['id'] ?? ''),
                        'status' => (string) ($metadata['status'] ?? ''),
                        'created_at' => (string) ($metadata['created_at'] ?? ''),
                        'processed_at' => (string) ($metadata['processed_at'] ?? ''),
                        'total_time_taken' => $metadata['total_time_taken'] ?? null,
                    ),
                ),
                'completed_at' => SEO_Ojeador_DB::utc_now(),
            ));
        }
        return $body;
    }

    private static function count_response_results($body) {
        $body = is_array($body) ? $body : array();
        $count = count((array) ($body['shopping_results'] ?? array()));
        foreach ((array) ($body['categorized_shopping_results'] ?? array()) as $group) {
            if (is_array($group)) {
                $count += count((array) ($group['shopping_results'] ?? array()));
            }
        }
        foreach (array('inline_shopping_results','featured_shopping_results','stores') as $key) {
            $count += count((array) ($body[$key] ?? array()));
        }
        return $count;
    }

    private static function best_candidate($rows, $identity, $query) {
        $best = null;
        $best_score = 0.0;
        $gtin = SEO_Ojeador_Identity::normalize_gtin($identity['gtin'] ?? '');
        $mpn = SEO_Ojeador_Identity::normalize_code($identity['mpn'] ?? '');
        $model = SEO_Ojeador_Identity::normalize_code($identity['model'] ?? '');
        $brand = SEO_Ojeador_Identity::normalize_text($identity['brand'] ?? '');
        $own_title = SEO_Ojeador_Identity::normalize_text($identity['name'] ?? '');

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = (string) ($row['title'] ?? '');
            if ($title === '') {
                continue;
            }
            $title_code = SEO_Ojeador_Identity::normalize_code($title);
            $title_text = SEO_Ojeador_Identity::normalize_text($title);
            $score = 0.0;

            if ($gtin !== '' && preg_match('/^\d{8,14}$/', trim((string) $query))) {
                $score += 0.35;
            }
            if ($mpn !== '' && strpos($title_code, $mpn) !== false) {
                $score += 0.40;
            } elseif ($model !== '' && strpos($title_code, $model) !== false) {
                $score += 0.35;
            }
            if ($brand !== '' && strpos($title_text, $brand) !== false) {
                $score += 0.15;
            }
            if ($own_title !== '') {
                similar_text($own_title, $title_text, $pct);
                $score += min(0.15, max(0, ((float) $pct / 100) * 0.15));
            }
            if (!empty($row['product_id']) || !empty($row['immersive_product_page_token'])) {
                $score += 0.05;
            }
            $score = min(1.0, $score);
            if ($score > $best_score) {
                $row['_ojeador_score'] = $score;
                $best = $row;
                $best_score = $score;
            }
        }

        // Exact GTIN searches can still be useful when Google does not repeat the
        // manufacturer reference in the title. Non-GTIN searches require stronger
        // textual evidence.
        $threshold = $gtin !== '' ? 0.45 : 0.55;
        return ($best && $best_score >= $threshold) ? $best : null;
    }

    private static function extract_stores($details) {
        $stores = array();
        if (!empty($details['product_results']['stores']) && is_array($details['product_results']['stores'])) {
            $stores = $details['product_results']['stores'];
        } elseif (!empty($details['sellers_results']['online_sellers']) && is_array($details['sellers_results']['online_sellers'])) {
            $stores = $details['sellers_results']['online_sellers'];
        }

        $out = array();
        foreach ($stores as $store) {
            if (!is_array($store)) {
                continue;
            }
            $merchant = sanitize_text_field((string) ($store['name'] ?? $store['source'] ?? ''));
            $price = self::number($store['extracted_price'] ?? $store['base_price'] ?? $store['price'] ?? null);
            if ($merchant === '' || $price === null || $price <= 0) {
                continue;
            }
            $shipping = self::number($store['shipping_extracted'] ?? ($store['additional_price']['shipping'] ?? null));
            $total = self::number($store['extracted_total'] ?? $store['total_price'] ?? null);
            if ($total === null && $shipping !== null) {
                $total = $price + $shipping;
            }
            $url = esc_url_raw((string) ($store['direct_link'] ?? $store['link'] ?? ''));
            $out[] = array(
                'merchant' => $merchant,
                'url' => $url,
                'price' => $price,
                'shipping_price' => $shipping,
                'total_price' => $total,
                'currency' => self::currency_from_values($store),
                'stock_text' => self::stock_text($store),
                'condition_label' => sanitize_text_field((string) ($store['condition'] ?? '')),
                'position' => absint($store['position'] ?? 0),
                'source' => 'google_shopping',
                'raw' => self::compact_raw($store),
            );
        }
        return self::dedupe_offers($out);
    }

    private static function extract_search_offers($rows, $identity) {
        $out = array();
        $mpn = SEO_Ojeador_Identity::normalize_code($identity['mpn'] ?? '');
        $model = SEO_Ojeador_Identity::normalize_code($identity['model'] ?? '');
        $brand = SEO_Ojeador_Identity::normalize_text($identity['brand'] ?? '');

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = (string) ($row['title'] ?? '');
            $title_code = SEO_Ojeador_Identity::normalize_code($title);
            $title_text = SEO_Ojeador_Identity::normalize_text($title);
            $strong = ($mpn !== '' && strpos($title_code, $mpn) !== false)
                || ($model !== '' && strpos($title_code, $model) !== false);
            if (!$strong && $brand !== '' && strpos($title_text, $brand) === false) {
                continue;
            }
            $merchant = sanitize_text_field((string) ($row['source'] ?? $row['seller'] ?? ''));
            $price = self::number($row['extracted_price'] ?? $row['price'] ?? null);
            if ($merchant === '' || $price === null || $price <= 0) {
                continue;
            }
            $out[] = array(
                'merchant' => $merchant,
                'url' => esc_url_raw((string) ($row['product_link'] ?? $row['link'] ?? '')),
                'price' => $price,
                'shipping_price' => null,
                'total_price' => null,
                'currency' => self::currency_from_values($row),
                'stock_text' => sanitize_text_field((string) ($row['delivery'] ?? '')),
                'condition_label' => sanitize_text_field((string) ($row['second_hand_condition'] ?? '')),
                'position' => absint($row['position'] ?? 0),
                'source' => 'google_shopping_search',
                'raw' => self::compact_raw($row),
            );
        }
        return self::dedupe_offers($out);
    }

    private static function dedupe_offers($offers) {
        $seen = array();
        $out = array();
        foreach ($offers as $offer) {
            $merchant_key = sanitize_title((string) ($offer['merchant'] ?? ''));
            $price = isset($offer['price']) ? number_format((float) $offer['price'], 4, '.', '') : '';
            $key = $merchant_key . '|' . $price . '|' . strtolower((string) ($offer['url'] ?? ''));
            if ($merchant_key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $offer;
        }
        usort($out, static function ($a, $b) {
            $ta = isset($a['total_price']) && $a['total_price'] !== null ? (float) $a['total_price'] : (float) ($a['price'] ?? PHP_FLOAT_MAX);
            $tb = isset($b['total_price']) && $b['total_price'] !== null ? (float) $b['total_price'] : (float) ($b['price'] ?? PHP_FLOAT_MAX);
            return $ta <=> $tb;
        });
        return $out;
    }

    private static function number($value) {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return round((float) $value, 6);
        }
        $value = html_entity_decode(wp_strip_all_tags((string) $value), ENT_QUOTES, 'UTF-8');
        $value = preg_replace('/[^0-9,\.\-]/', '', $value);
        if ($value === '' || $value === '-') {
            return null;
        }
        if (strpos($value, ',') !== false && strpos($value, '.') !== false) {
            if (strrpos($value, ',') > strrpos($value, '.')) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif (strpos($value, ',') !== false) {
            $value = str_replace(',', '.', $value);
        }
        return is_numeric($value) ? round((float) $value, 6) : null;
    }

    private static function currency_from_values($row) {
        $currency = strtoupper(sanitize_text_field((string) ($row['currency'] ?? '')));
        if ($currency !== '') {
            return substr($currency, 0, 8);
        }
        $strings = array(
            (string) ($row['price'] ?? ''),
            (string) ($row['base_price'] ?? ''),
            (string) ($row['total'] ?? ''),
            (string) ($row['total_price'] ?? ''),
        );
        foreach ($strings as $text) {
            if (strpos($text, '€') !== false || stripos($text, 'EUR') !== false) {
                return 'EUR';
            }
            if (strpos($text, '$') !== false) {
                return 'USD';
            }
            if (strpos($text, '£') !== false) {
                return 'GBP';
            }
        }
        return 'EUR';
    }

    private static function stock_text($row) {
        $parts = array();
        foreach (array('delivery', 'availability', 'badge', 'tag') as $key) {
            if (!empty($row[$key]) && is_scalar($row[$key])) {
                $parts[] = sanitize_text_field((string) $row[$key]);
            }
        }
        if (!empty($row['details_and_offers']) && is_array($row['details_and_offers'])) {
            foreach ($row['details_and_offers'] as $item) {
                if (is_string($item)) {
                    $parts[] = sanitize_text_field($item);
                } elseif (is_array($item) && !empty($item['text'])) {
                    $parts[] = sanitize_text_field((string) $item['text']);
                }
                if (count($parts) >= 3) {
                    break;
                }
            }
        }
        return implode(' · ', array_values(array_unique(array_filter($parts))));
    }

    private static function compact_raw($value) {
        if (!is_array($value)) {
            return $value;
        }
        $copy = $value;
        foreach (array('raw_html_file', 'thumbnail', 'thumbnails', 'serpapi_thumbnail', 'serpapi_thumbnails', 'source_icon', 'logo', 'product_images', 'images') as $key) {
            unset($copy[$key]);
        }
        return $copy;
    }
}
