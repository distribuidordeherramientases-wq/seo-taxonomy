<?php
/**
 * Ojeador market database.
 *
 * v0.6 keeps the legacy exact-product tables for future matching, but the
 * active worker is category-first: one Google Shopping query per WooCommerce
 * product category and every returned result is stored without an internal cut.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @since 0.6.0
 */

defined('ABSPATH') || exit;

final class SEO_Ojeador_DB {
    const OPTION_DB_VERSION = 'seo_ojeador_db_version';
    const DB_VERSION = '0.6.0';

    public static function table($name) {
        global $wpdb;
        $map = array(
            // Legacy/exact-product layer. Retained for later product matching.
            'products'         => $wpdb->prefix . 'seo_ojeador_market_products',
            'offers'           => $wpdb->prefix . 'seo_ojeador_market_offers',
            // Category-first market layer.
            'categories'       => $wpdb->prefix . 'seo_ojeador_market_categories',
            'category_results' => $wpdb->prefix . 'seo_ojeador_market_category_results',
            'runs'             => $wpdb->prefix . 'seo_ojeador_market_runs',
        );
        return isset($map[$name]) ? $map[$name] : '';
    }

    public static function utc_now() {
        return gmdate('Y-m-d H:i:s');
    }

    public static function maybe_install() {
        $current = (string) get_option(self::OPTION_DB_VERSION, '');
        if ($current === self::DB_VERSION && self::tables_exist()) {
            return;
        }
        self::install($current);
    }

    private static function tables_exist() {
        global $wpdb;
        foreach (array('products','offers','categories','category_results','runs') as $name) {
            $table = self::table($name);
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                return false;
            }
        }
        return true;
    }

    public static function install($previous_version = '') {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $products = self::table('products');
        $offers = self::table('offers');
        $categories = self::table('categories');
        $category_results = self::table('category_results');
        $runs = self::table('runs');

        // Exact-product layer retained so historical data/helpers keep working.
        dbDelta("CREATE TABLE {$products} (
            object_id bigint(20) unsigned NOT NULL,
            gtin varchar(32) NOT NULL DEFAULT '',
            mpn varchar(191) NOT NULL DEFAULT '',
            brand varchar(191) NOT NULL DEFAULT '',
            model varchar(191) NOT NULL DEFAULT '',
            query_text varchar(500) NOT NULL DEFAULT '',
            google_product_id varchar(191) NOT NULL DEFAULT '',
            google_title text NULL,
            match_confidence decimal(7,6) NOT NULL DEFAULT 0,
            status varchar(32) NOT NULL DEFAULT 'pending',
            offer_count int(10) unsigned NOT NULL DEFAULT 0,
            market_min decimal(18,6) NULL,
            market_median decimal(18,6) NULL,
            market_max decimal(18,6) NULL,
            currency varchar(8) NOT NULL DEFAULT 'EUR',
            own_price_snapshot decimal(18,6) NULL,
            last_scan_at datetime NULL,
            next_scan_at datetime NULL,
            last_error text NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (object_id),
            KEY status (status),
            KEY next_scan_at (next_scan_at),
            KEY gtin (gtin),
            KEY google_product_id (google_product_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$offers} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            object_id bigint(20) unsigned NOT NULL,
            google_product_id varchar(191) NOT NULL DEFAULT '',
            merchant varchar(255) NOT NULL DEFAULT '',
            merchant_key varchar(191) NOT NULL DEFAULT '',
            offer_hash char(64) NOT NULL,
            url text NULL,
            price decimal(18,6) NOT NULL,
            shipping_price decimal(18,6) NULL,
            total_price decimal(18,6) NULL,
            currency varchar(8) NOT NULL DEFAULT 'EUR',
            stock_text varchar(500) NOT NULL DEFAULT '',
            condition_label varchar(191) NOT NULL DEFAULT '',
            offer_position int(10) unsigned NOT NULL DEFAULT 0,
            source varchar(64) NOT NULL DEFAULT 'google_shopping',
            active tinyint(1) unsigned NOT NULL DEFAULT 1,
            first_seen_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            observed_at datetime NOT NULL,
            raw_json longtext NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY object_offer (object_id,offer_hash),
            KEY object_active (object_id,active),
            KEY merchant_key (merchant_key),
            KEY last_seen_at (last_seen_at)
        ) {$charset};");

        // One row per WooCommerce product category that Ojeador has touched.
        dbDelta("CREATE TABLE {$categories} (
            term_id bigint(20) unsigned NOT NULL,
            taxonomy varchar(64) NOT NULL DEFAULT 'product_cat',
            category_name varchar(255) NOT NULL DEFAULT '',
            query_text varchar(500) NOT NULL DEFAULT '',
            product_count int(10) unsigned NOT NULL DEFAULT 0,
            status varchar(32) NOT NULL DEFAULT 'pending',
            result_count int(10) unsigned NOT NULL DEFAULT 0,
            unique_result_count int(10) unsigned NOT NULL DEFAULT 0,
            last_scan_at datetime NULL,
            next_scan_at datetime NULL,
            last_error text NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (term_id),
            KEY status (status),
            KEY next_scan_at (next_scan_at),
            KEY last_scan_at (last_scan_at)
        ) {$charset};");

        // Full market snapshot returned by Google Shopping for each category.
        dbDelta("CREATE TABLE {$category_results} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            term_id bigint(20) unsigned NOT NULL,
            result_hash char(64) NOT NULL,
            google_product_id varchar(191) NOT NULL DEFAULT '',
            immersive_token text NULL,
            gtin varchar(32) NOT NULL DEFAULT '',
            mpn varchar(191) NOT NULL DEFAULT '',
            brand varchar(191) NOT NULL DEFAULT '',
            model varchar(191) NOT NULL DEFAULT '',
            title text NULL,
            description text NULL,
            merchant varchar(255) NOT NULL DEFAULT '',
            merchant_key varchar(191) NOT NULL DEFAULT '',
            price decimal(18,6) NULL,
            old_price decimal(18,6) NULL,
            currency varchar(8) NOT NULL DEFAULT 'EUR',
            delivery varchar(500) NOT NULL DEFAULT '',
            rating decimal(7,3) NULL,
            reviews int(10) unsigned NOT NULL DEFAULT 0,
            image_url text NULL,
            merchant_url text NULL,
            product_url text NULL,
            result_position int(10) unsigned NOT NULL DEFAULT 0,
            source varchar(64) NOT NULL DEFAULT 'google_shopping',
            active tinyint(1) unsigned NOT NULL DEFAULT 1,
            first_seen_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            observed_at datetime NOT NULL,
            raw_json longtext NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY term_result (term_id,result_hash),
            KEY term_active (term_id,active),
            KEY google_product_id (google_product_id),
            KEY merchant_key (merchant_key),
            KEY last_seen_at (last_seen_at)
        ) {$charset};");

        // Old product counters remain for backwards compatibility; v0.6 adds
        // category counters used by the current worker and Procesos monitor.
        dbDelta("CREATE TABLE {$runs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source varchar(32) NOT NULL DEFAULT 'manual',
            status varchar(32) NOT NULL DEFAULT 'pending',
            total_candidates int(10) unsigned NOT NULL DEFAULT 0,
            processed_products int(10) unsigned NOT NULL DEFAULT 0,
            compared_products int(10) unsigned NOT NULL DEFAULT 0,
            no_match_products int(10) unsigned NOT NULL DEFAULT 0,
            offers_seen int(10) unsigned NOT NULL DEFAULT 0,
            last_object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            processed_categories int(10) unsigned NOT NULL DEFAULT 0,
            categories_with_results int(10) unsigned NOT NULL DEFAULT 0,
            categories_without_results int(10) unsigned NOT NULL DEFAULT 0,
            results_seen int(10) unsigned NOT NULL DEFAULT 0,
            api_queries int(10) unsigned NOT NULL DEFAULT 0,
            last_term_id bigint(20) unsigned NOT NULL DEFAULT 0,
            errors_count int(10) unsigned NOT NULL DEFAULT 0,
            last_error text NULL,
            started_at datetime NULL,
            heartbeat_at datetime NULL,
            completed_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};");

        // A product-based run from v0.5 must never continue silently as a
        // category run after deployment. Stop it and let the next start be clean.
        if ($previous_version !== '' && version_compare($previous_version, '0.6.0', '<')) {
            $wpdb->query("UPDATE {$runs} SET status='stopped', completed_at=UTC_TIMESTAMP(), heartbeat_at=UTC_TIMESTAMP() WHERE status IN ('pending','running')");

            // Product-era settings can mean something very different after the
            // switch to categories (for example batch_size=20 would become 20
            // paid API queries per pulse). Migrate conservatively and require an
            // explicit re-enable from the new screen.
            $settings = get_option('seo_ojeador_shopping_settings', array());
            $settings = is_array($settings) ? $settings : array();
            $settings['auto_enabled'] = 0;
            $settings['batch_size'] = 1;
            if (empty($settings['interval_hours']) || absint($settings['interval_hours']) < 24) {
                $settings['interval_hours'] = 720;
            }
            if (empty($settings['monthly_query_limit'])) {
                $settings['monthly_query_limit'] = 250;
            }
            update_option('seo_ojeador_shopping_settings', $settings, false);
        }

        update_option(self::OPTION_DB_VERSION, self::DB_VERSION, false);
    }

    /* ---------------------------------------------------------------------
     * CATEGORY-FIRST MARKET SCAN
     * ------------------------------------------------------------------ */

    private static function google_schema_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_google_schema_map';
    }

    private static function google_schema_available() {
        global $wpdb;
        static $available = null;
        if ($available !== null) {
            return $available;
        }
        $table = self::google_schema_table();
        $available = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);
        return $available;
    }

    private static function normalize_query_text($value) {
        $value = trim(wp_strip_all_tags((string) $value));
        $value = preg_replace('/\s+/u', ' ', $value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /**
     * Categories that Ojeador is allowed to send to Google Shopping.
     *
     * Eligibility is deliberately strict: the Google-schema record must be
     * approved and it must contain an explicit shopping_query. Ojeador no longer
     * falls back to an unreviewed WooCommerce category name.
     */
    private static function eligible_category_rows() {
        global $wpdb;
        if (!self::google_schema_available()) {
            return array();
        }

        $market = self::table('categories');
        $schema = self::google_schema_table();
        return (array) $wpdb->get_results(
            "SELECT tt.term_id,
                    t.name AS category_name,
                    tt.count AS product_count,
                    gm.shopping_query AS trusted_query,
                    gm.status AS mapping_status,
                    c.query_text,
                    c.status AS market_status,
                    c.result_count,
                    c.last_scan_at,
                    c.next_scan_at,
                    c.last_error
             FROM {$wpdb->term_taxonomy} tt
             JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
             JOIN {$schema} gm
               ON gm.object_type='product_cat'
              AND gm.object_id=tt.term_id
             LEFT JOIN {$market} c ON c.term_id=tt.term_id
             WHERE tt.taxonomy='product_cat'
               AND tt.count>0
               AND gm.status='approved'
               AND gm.shopping_query IS NOT NULL
               AND TRIM(gm.shopping_query)<>''",
            ARRAY_A
        );
    }

    private static function needs_trusted_scan(array $row) {
        if (empty($row['last_scan_at'])) {
            return true;
        }
        $used = self::normalize_query_text($row['query_text'] ?? '');
        $trusted = self::normalize_query_text($row['trusted_query'] ?? '');
        return $trusted === '' || $used !== $trusted;
    }

    private static function is_category_due(array $row, $now = '') {
        if (self::needs_trusted_scan($row)) {
            return true;
        }
        $next = trim((string) ($row['next_scan_at'] ?? ''));
        if ($next === '') {
            return true;
        }
        $now = $now !== '' ? $now : self::utc_now();
        return $next <= $now;
    }

    private static function normalize_url_path($url) {
        $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
        if ($path === '') {
            return '/';
        }
        $path = rawurldecode($path);
        $path = '/' . trim($path, '/');
        return $path === '' ? '/' : $path;
    }

    /**
     * Traffic signals already calculated by Analista for the last 28 days.
     * Cached because seo_analista_get_data() is much more expensive than the
     * Ojeador queue selection itself.
     */
    private static function analista_page_metrics() {
        static $request_cache = null;
        if (is_array($request_cache)) {
            return $request_cache;
        }

        $cache_key = 'seo_ojeador_analista_category_metrics_v1';
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            $request_cache = $cached;
            return $request_cache;
        }

        $request_cache = array();
        if (!function_exists('seo_analista_get_data')) {
            return $request_cache;
        }

        $data = seo_analista_get_data(28);
        if (!is_array($data) || empty($data['ready'])) {
            return $request_cache;
        }

        foreach ((array) ($data['pages'] ?? array()) as $page) {
            if (!is_array($page)) {
                continue;
            }
            $path = self::normalize_url_path($page['page_url'] ?? '');
            if ($path === '/') {
                continue;
            }
            $request_cache[$path] = array(
                'clicks' => max(0.0, (float) ($page['clicks'] ?? 0)),
                'impressions' => max(0.0, (float) ($page['impressions'] ?? 0)),
                'position' => max(0.0, (float) ($page['position'] ?? 0)),
            );
        }

        set_transient($cache_key, $request_cache, HOUR_IN_SECONDS);
        return $request_cache;
    }

    private static function add_analista_metrics(array $rows) {
        $metrics = self::analista_page_metrics();
        foreach ($rows as &$row) {
            $row['analista_clicks'] = 0.0;
            $row['analista_impressions'] = 0.0;
            $row['analista_position'] = 0.0;
            $term_id = absint($row['term_id'] ?? 0);
            if ($term_id < 1 || !$metrics) {
                continue;
            }
            $url = get_term_link($term_id, 'product_cat');
            if (is_wp_error($url) || !$url) {
                continue;
            }
            $path = self::normalize_url_path($url);
            if (!isset($metrics[$path])) {
                continue;
            }
            $row['analista_clicks'] = (float) ($metrics[$path]['clicks'] ?? 0);
            $row['analista_impressions'] = (float) ($metrics[$path]['impressions'] ?? 0);
            $row['analista_position'] = (float) ($metrics[$path]['position'] ?? 0);
        }
        unset($row);
        return $rows;
    }

    private static function sort_priority_rows(array $rows) {
        usort($rows, static function ($a, $b) {
            $clicks = (float) ($b['analista_clicks'] ?? 0) <=> (float) ($a['analista_clicks'] ?? 0);
            if ($clicks !== 0) {
                return $clicks;
            }
            $impressions = (float) ($b['analista_impressions'] ?? 0) <=> (float) ($a['analista_impressions'] ?? 0);
            if ($impressions !== 0) {
                return $impressions;
            }
            $products = absint($b['product_count'] ?? 0) <=> absint($a['product_count'] ?? 0);
            if ($products !== 0) {
                return $products;
            }
            $a_scan = trim((string) ($a['last_scan_at'] ?? '')) ?: '1970-01-01 00:00:00';
            $b_scan = trim((string) ($b['last_scan_at'] ?? '')) ?: '1970-01-01 00:00:00';
            if ($a_scan !== $b_scan) {
                return strcmp($a_scan, $b_scan);
            }
            return absint($a['term_id'] ?? 0) <=> absint($b['term_id'] ?? 0);
        });
        return $rows;
    }

    public static function count_target_categories() {
        return count(self::eligible_category_rows());
    }

    public static function count_excluded_categories() {
        global $wpdb;
        $all = absint($wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy='product_cat' AND count>0"
        ));
        return max(0, $all - self::count_target_categories());
    }

    public static function count_due_categories() {
        $now = self::utc_now();
        $due = array();
        $first = array();
        foreach (self::eligible_category_rows() as $row) {
            if (!self::is_category_due($row, $now)) {
                continue;
            }
            $due[] = $row;
            if (self::needs_trusted_scan($row)) {
                $first[] = $row;
            }
        }
        // While trusted coverage is incomplete, do not spend quota repeating
        // categories that already have a snapshot for their current query.
        return count($first ? $first : $due);
    }

    /**
     * Pick the next categories using the three business criteria requested:
     * 1) complete first trusted coverage before repeating categories;
     * 2) among candidates, Analista traffic (clicks, then impressions);
     * 3) product count; oldest snapshot is only the final tie breaker.
     */
    public static function due_category_ids($limit) {
        $limit = max(1, min(20, absint($limit)));
        $now = self::utc_now();
        $due = array();
        $first = array();

        foreach (self::eligible_category_rows() as $row) {
            if (!self::is_category_due($row, $now)) {
                continue;
            }
            $due[] = $row;
            if (self::needs_trusted_scan($row)) {
                $first[] = $row;
            }
        }

        $pool = $first ? $first : $due;
        if (!$pool) {
            return array();
        }

        $pool = self::add_analista_metrics($pool);
        $pool = self::sort_priority_rows($pool);
        $pool = array_slice($pool, 0, $limit);
        return array_values(array_map(static function ($row) {
            return absint($row['term_id'] ?? 0);
        }, $pool));
    }

    public static function category_context($term_id) {
        $term_id = absint($term_id);
        $term = get_term($term_id, 'product_cat');
        if (!$term || is_wp_error($term)) {
            return new WP_Error('ojeador_category', 'Categoría WooCommerce no válida.');
        }
        return array(
            'term_id' => $term_id,
            'name' => sanitize_text_field((string) $term->name),
            'slug' => sanitize_title((string) $term->slug),
            'product_count' => absint($term->count),
            'parent' => absint($term->parent),
        );
    }

    public static function save_category_scan($term_id, $scan, $interval_hours) {
        global $wpdb;
        $term_id = absint($term_id);
        $context = self::category_context($term_id);
        if (is_wp_error($context)) {
            return $context;
        }
        $scan = is_array($scan) ? $scan : array();
        $now = self::utc_now();
        $status = sanitize_key((string) ($scan['status'] ?? 'error')) ?: 'error';
        $results = isset($scan['results']) && is_array($scan['results']) ? $scan['results'] : array();

        if (in_array($status, array('ok','no_results'), true)) {
            $wpdb->update(self::table('category_results'), array('active'=>0), array('term_id'=>$term_id));
        }

        $seen = 0;
        $unique_keys = array();
        foreach ($results as $result) {
            $saved = self::upsert_category_result($term_id, $result, $now);
            if (is_wp_error($saved)) {
                continue;
            }
            $seen++;
            $identity_key = sanitize_text_field((string) ($result['google_product_id'] ?? ''));
            if ($identity_key === '') {
                $identity_key = hash('sha256', strtolower(
                    (string) ($result['title'] ?? '') . '|' .
                    (string) ($result['merchant'] ?? '') . '|' .
                    (string) ($result['merchant_url'] ?? '')
                ));
            }
            $unique_keys[$identity_key] = true;
        }

        if ($status === 'ok' && $seen < 1) {
            $status = 'no_results';
        }

        $interval_hours = max(6, absint($interval_hours));
        $next = gmdate('Y-m-d H:i:s', time() + $interval_hours * HOUR_IN_SECONDS);
        if ($status === 'error') {
            $next = gmdate('Y-m-d H:i:s', time() + 12 * HOUR_IN_SECONDS);
        }

        $row = array(
            'term_id' => $term_id,
            'taxonomy' => 'product_cat',
            'category_name' => $context['name'],
            'query_text' => sanitize_text_field((string) ($scan['query'] ?? $context['name'])),
            'product_count' => absint($context['product_count']),
            'status' => $status,
            'result_count' => $seen,
            'unique_result_count' => count($unique_keys),
            'last_scan_at' => $now,
            'next_scan_at' => $next,
            'last_error' => sanitize_textarea_field((string) ($scan['error'] ?? '')),
            'updated_at' => $now,
        );

        $exists = $wpdb->get_var($wpdb->prepare(
            'SELECT term_id FROM ' . self::table('categories') . ' WHERE term_id=%d',
            $term_id
        ));
        $ok = $exists
            ? $wpdb->update(self::table('categories'), $row, array('term_id'=>$term_id))
            : $wpdb->insert(self::table('categories'), $row);

        if ($ok === false) {
            return new WP_Error('ojeador_category_save', 'No se pudo guardar el mercado de la categoría.');
        }

        return array(
            'term_id' => $term_id,
            'status' => $status,
            'results' => $seen,
            'unique_results' => count($unique_keys),
        );
    }

    public static function save_category_error($term_id, $error, $query = '') {
        $context = self::category_context($term_id);
        if (is_wp_error($context)) {
            return $context;
        }
        return self::save_category_scan($term_id, array(
            'status' => 'error',
            'query' => $query !== '' ? $query : $context['name'],
            'results' => array(),
            'error' => is_wp_error($error) ? $error->get_error_message() : (string) $error,
        ), 12);
    }

    private static function upsert_category_result($term_id, $result, $now) {
        global $wpdb;
        $result = is_array($result) ? $result : array();
        $google_product_id = sanitize_text_field((string) ($result['google_product_id'] ?? ''));
        $title = sanitize_text_field((string) ($result['title'] ?? ''));
        $merchant = sanitize_text_field((string) ($result['merchant'] ?? ''));
        $merchant_url = esc_url_raw((string) ($result['merchant_url'] ?? ''));
        $product_url = esc_url_raw((string) ($result['product_url'] ?? ''));
        $price = self::decimal_or_null($result['price'] ?? null);

        if ($title === '' && $google_product_id === '') {
            return new WP_Error('ojeador_category_result', 'Resultado de Google Shopping sin identidad utilizable.');
        }

        $hash_basis = $google_product_id !== ''
            ? 'google|' . $google_product_id . '|' . sanitize_title($merchant)
            : strtolower($title . '|' . $merchant . '|' . $merchant_url . '|' . (string) $price);
        $result_hash = hash('sha256', $hash_basis);

        $data = array(
            'term_id' => absint($term_id),
            'result_hash' => $result_hash,
            'google_product_id' => $google_product_id,
            'immersive_token' => sanitize_text_field((string) ($result['immersive_token'] ?? '')),
            'gtin' => SEO_Ojeador_Identity::normalize_gtin($result['gtin'] ?? ''),
            'mpn' => sanitize_text_field((string) ($result['mpn'] ?? '')),
            'brand' => sanitize_text_field((string) ($result['brand'] ?? '')),
            'model' => sanitize_text_field((string) ($result['model'] ?? '')),
            'title' => $title,
            'description' => sanitize_textarea_field((string) ($result['description'] ?? '')),
            'merchant' => $merchant,
            'merchant_key' => sanitize_title($merchant),
            'price' => $price,
            'old_price' => self::decimal_or_null($result['old_price'] ?? null),
            'currency' => strtoupper(substr(sanitize_text_field((string) ($result['currency'] ?? 'EUR')), 0, 8)) ?: 'EUR',
            'delivery' => sanitize_text_field((string) ($result['delivery'] ?? '')),
            'rating' => self::decimal_or_null($result['rating'] ?? null),
            'reviews' => absint($result['reviews'] ?? 0),
            'image_url' => esc_url_raw((string) ($result['image_url'] ?? '')),
            'merchant_url' => $merchant_url,
            'product_url' => $product_url,
            'result_position' => absint($result['position'] ?? 0),
            'source' => sanitize_key((string) ($result['source'] ?? 'google_shopping')) ?: 'google_shopping',
            'active' => 1,
            'last_seen_at' => $now,
            'observed_at' => $now,
            'raw_json' => isset($result['raw']) ? wp_json_encode($result['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        );

        $existing = $wpdb->get_row($wpdb->prepare(
            'SELECT id,first_seen_at FROM ' . self::table('category_results') . ' WHERE term_id=%d AND result_hash=%s LIMIT 1',
            absint($term_id),
            $result_hash
        ), ARRAY_A);

        if ($existing) {
            $wpdb->update(self::table('category_results'), $data, array('id'=>absint($existing['id'])));
            return absint($existing['id']);
        }

        $data['first_seen_at'] = $now;
        $wpdb->insert(self::table('category_results'), $data);
        return $wpdb->insert_id
            ? absint($wpdb->insert_id)
            : new WP_Error('ojeador_category_result_insert', 'No se pudo guardar el resultado de Google Shopping.');
    }

    public static function category_summary() {
        $rows = self::eligible_category_rows();
        $target = count($rows);
        $consulted = 0;
        $with_results = 0;
        $without_results = 0;
        $errors = 0;

        foreach ($rows as $row) {
            // A historical scan made with a different/unapproved query does not
            // count as trusted coverage after the vocabulary has changed.
            if (self::needs_trusted_scan($row)) {
                continue;
            }
            $consulted++;
            $status = sanitize_key((string) ($row['market_status'] ?? ''));
            if ($status === 'ok' && absint($row['result_count'] ?? 0) > 0) {
                $with_results++;
            } elseif ($status === 'no_results') {
                $without_results++;
            } elseif ($status === 'error') {
                $errors++;
            }
        }

        return array(
            'target' => $target,
            'excluded' => self::count_excluded_categories(),
            'consulted' => $consulted,
            'pending' => max(0, $target - $consulted),
            'with_results' => $with_results,
            'without_results' => $without_results,
            'errors' => $errors,
            'coverage' => $target > 0 ? round(($consulted / $target) * 100, 1) : 0,
            'due' => self::count_due_categories(),
        );
    }

    public static function list_market_categories($args = array()) {
        global $wpdb;
        $args = wp_parse_args($args, array('limit'=>1000, 'search'=>''));
        $limit = max(1, min(2000, absint($args['limit'])));
        $search = trim((string) $args['search']);
        $categories = self::table('categories');
        $schema = self::google_schema_table();
        $has_schema = self::google_schema_available();

        $where = "WHERE tt.taxonomy='product_cat' AND tt.count>0";
        $params = array();
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= $has_schema
                ? ' AND (t.name LIKE %s OR c.query_text LIKE %s OR gm.shopping_query LIKE %s)'
                : ' AND (t.name LIKE %s OR c.query_text LIKE %s)';
            $params = $has_schema ? array($like, $like, $like) : array($like, $like);
        }

        if ($has_schema) {
            $sql = "SELECT tt.term_id,t.name AS woo_category,tt.count AS woo_product_count,
                           c.category_name,c.query_text,c.status,c.result_count,c.unique_result_count,
                           c.last_scan_at,c.next_scan_at,c.last_error,
                           gm.status AS google_schema_status,gm.shopping_query AS trusted_query
                    FROM {$wpdb->term_taxonomy} tt
                    JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
                    LEFT JOIN {$categories} c ON c.term_id=tt.term_id
                    LEFT JOIN {$schema} gm ON gm.object_type='product_cat' AND gm.object_id=tt.term_id
                    {$where}
                    LIMIT {$limit}";
        } else {
            $sql = "SELECT tt.term_id,t.name AS woo_category,tt.count AS woo_product_count,
                           c.category_name,c.query_text,c.status,c.result_count,c.unique_result_count,
                           c.last_scan_at,c.next_scan_at,c.last_error,
                           '' AS google_schema_status,'' AS trusted_query
                    FROM {$wpdb->term_taxonomy} tt
                    JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
                    LEFT JOIN {$categories} c ON c.term_id=tt.term_id
                    {$where}
                    LIMIT {$limit}";
        }
        if ($params) {
            $sql = $wpdb->prepare($sql, $params);
        }
        $rows = (array) $wpdb->get_results($sql, ARRAY_A);

        foreach ($rows as &$row) {
            $row['product_count'] = absint($row['woo_product_count'] ?? 0);
            $row['market_status'] = (string) ($row['status'] ?? '');
            $eligible = (string) ($row['google_schema_status'] ?? '') === 'approved'
                && trim((string) ($row['trusted_query'] ?? '')) !== '';
            $row['ojeador_eligible'] = $eligible ? 1 : 0;
            $row['needs_trusted_scan'] = $eligible && self::needs_trusted_scan($row) ? 1 : 0;
            $row['ojeador_due'] = $eligible && self::is_category_due($row) ? 1 : 0;
        }
        unset($row);

        $rows = self::add_analista_metrics($rows);
        usort($rows, static function ($a, $b) {
            $eligible = absint($b['ojeador_eligible'] ?? 0) <=> absint($a['ojeador_eligible'] ?? 0);
            if ($eligible !== 0) return $eligible;
            $first = absint($b['needs_trusted_scan'] ?? 0) <=> absint($a['needs_trusted_scan'] ?? 0);
            if ($first !== 0) return $first;
            $clicks = (float) ($b['analista_clicks'] ?? 0) <=> (float) ($a['analista_clicks'] ?? 0);
            if ($clicks !== 0) return $clicks;
            $impressions = (float) ($b['analista_impressions'] ?? 0) <=> (float) ($a['analista_impressions'] ?? 0);
            if ($impressions !== 0) return $impressions;
            $products = absint($b['woo_product_count'] ?? 0) <=> absint($a['woo_product_count'] ?? 0);
            if ($products !== 0) return $products;
            return strcasecmp((string) ($a['woo_category'] ?? ''), (string) ($b['woo_category'] ?? ''));
        });
        return $rows;
    }

    /**
     * Reuse a recent snapshot produced by the exact same Google Shopping query.
     * This avoids spending a second request when different categories resolve to
     * the same operational shopping_query.
     *
     * @param string $query Exact query text.
     * @param int $exclude_term_id Target category that must not be its own source.
     * @param int $max_age_hours Maximum age of the reusable snapshot.
     * @return array|null
     */
    public static function recent_category_scan_for_query($query, $exclude_term_id = 0, $max_age_hours = 24) {
        global $wpdb;
        $query = sanitize_text_field((string) $query);
        $exclude_term_id = absint($exclude_term_id);
        $max_age_hours = max(1, min(168, absint($max_age_hours)));
        if ($query === '') {
            return null;
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - ($max_age_hours * HOUR_IN_SECONDS));
        $source = $wpdb->get_row($wpdb->prepare(
            "SELECT term_id,status,result_count,last_scan_at
             FROM " . self::table('categories') . "
             WHERE query_text=%s
               AND term_id<>%d
               AND status IN ('ok','no_results')
               AND last_scan_at IS NOT NULL
               AND last_scan_at>=%s
             ORDER BY last_scan_at DESC
             LIMIT 1",
            $query,
            $exclude_term_id,
            $cutoff
        ), ARRAY_A);

        if (!is_array($source) || empty($source['term_id'])) {
            return null;
        }

        $status = sanitize_key((string) ($source['status'] ?? ''));
        $results = array();
        if ($status === 'ok') {
            foreach (self::results_for_category(absint($source['term_id']), 5000) as $row) {
                $raw = array();
                if (!empty($row['raw_json'])) {
                    $decoded = json_decode((string) $row['raw_json'], true);
                    if (is_array($decoded)) {
                        $raw = $decoded;
                    }
                }
                $results[] = array(
                    'google_product_id' => (string) ($row['google_product_id'] ?? ''),
                    'immersive_token' => (string) ($row['immersive_token'] ?? ''),
                    'gtin' => (string) ($row['gtin'] ?? ''),
                    'mpn' => (string) ($row['mpn'] ?? ''),
                    'brand' => (string) ($row['brand'] ?? ''),
                    'model' => (string) ($row['model'] ?? ''),
                    'title' => (string) ($row['title'] ?? ''),
                    'description' => (string) ($row['description'] ?? ''),
                    'merchant' => (string) ($row['merchant'] ?? ''),
                    'price' => $row['price'] !== null ? (float) $row['price'] : null,
                    'old_price' => $row['old_price'] !== null ? (float) $row['old_price'] : null,
                    'currency' => (string) ($row['currency'] ?? 'EUR'),
                    'delivery' => (string) ($row['delivery'] ?? ''),
                    'rating' => $row['rating'] !== null ? (float) $row['rating'] : null,
                    'reviews' => absint($row['reviews'] ?? 0),
                    'image_url' => (string) ($row['image_url'] ?? ''),
                    'merchant_url' => (string) ($row['merchant_url'] ?? ''),
                    'product_url' => (string) ($row['product_url'] ?? ''),
                    'position' => absint($row['result_position'] ?? 0),
                    'source' => (string) ($row['source'] ?? 'google_shopping_category'),
                    'raw' => $raw,
                );
            }
            if (!$results) {
                return null;
            }
        }

        return array(
            'status' => $status === 'ok' && $results ? 'ok' : 'no_results',
            'query' => $query,
            'results' => $results,
            'reused_from_term_id' => absint($source['term_id']),
            'raw_search' => array(
                'ojeador_reused_query' => true,
                'source_term_id' => absint($source['term_id']),
                'source_last_scan_at' => (string) ($source['last_scan_at'] ?? ''),
            ),
        );
    }

    public static function results_for_category($term_id, $limit = 1000) {
        global $wpdb;
        $limit = max(1, min(5000, absint($limit)));
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table('category_results') . ' WHERE term_id=%d AND active=1 ORDER BY result_position ASC,id ASC LIMIT %d',
            absint($term_id),
            $limit
        ), ARRAY_A);
    }

    /* ---------------------------------------------------------------------
     * LEGACY / EXACT-PRODUCT LAYER (kept for future matching)
     * ------------------------------------------------------------------ */

    public static function live_price($object_id) {
        $object_id = absint($object_id);
        if ($object_id < 1 || !function_exists('wc_get_product')) {
            return null;
        }
        $product = wc_get_product($object_id);
        if (!$product) {
            return null;
        }
        $price = $product->get_price();
        return is_numeric($price) && (float) $price > 0 ? round((float) $price, 6) : null;
    }

    public static function count_published_products() {
        $counts = wp_count_posts('product');
        return isset($counts->publish) ? absint($counts->publish) : 0;
    }

    public static function count_due_products() {
        global $wpdb;
        $products = self::table('products');
        $now = self::utc_now();
        $sql = $wpdb->prepare(
            "SELECT COUNT(p.ID)
             FROM {$wpdb->posts} p
             LEFT JOIN {$products} m ON m.object_id=p.ID
             WHERE p.post_type='product' AND p.post_status='publish'
               AND (m.object_id IS NULL OR m.next_scan_at IS NULL OR m.next_scan_at <= %s)",
            $now
        );
        return absint($wpdb->get_var($sql));
    }

    public static function due_product_ids($limit) {
        global $wpdb;
        $limit = max(1, min(50, absint($limit)));
        $products = self::table('products');
        $now = self::utc_now();
        $sql = $wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             LEFT JOIN {$products} m ON m.object_id=p.ID
             WHERE p.post_type='product' AND p.post_status='publish'
               AND (m.object_id IS NULL OR m.next_scan_at IS NULL OR m.next_scan_at <= %s)
             ORDER BY CASE WHEN m.object_id IS NULL THEN 0 ELSE 1 END ASC,
                      m.last_scan_at ASC,
                      p.ID ASC
             LIMIT %d",
            $now,
            $limit
        );
        return array_map('absint', (array) $wpdb->get_col($sql));
    }

    public static function save_scan($identity, $scan, $interval_hours) {
        global $wpdb;
        $identity = is_array($identity) ? $identity : array();
        $scan = is_array($scan) ? $scan : array();
        $object_id = absint($identity['object_id'] ?? 0);
        if ($object_id < 1) {
            return new WP_Error('ojeador_object', 'Producto local no válido.');
        }
        $now = self::utc_now();
        $status = sanitize_key((string) ($scan['status'] ?? 'error')) ?: 'error';
        $offers = isset($scan['offers']) && is_array($scan['offers']) ? $scan['offers'] : array();
        $google_product_id = sanitize_text_field((string) ($scan['google_product_id'] ?? ''));
        $currency = 'EUR';

        if (in_array($status, array('ok','no_offers'), true)) {
            $wpdb->update(self::table('offers'), array('active'=>0), array('object_id'=>$object_id));
        }

        $prices = array();
        $seen = 0;
        foreach ($offers as $offer) {
            $saved = self::upsert_offer($object_id, $google_product_id, $offer, $now);
            if (is_wp_error($saved)) {
                continue;
            }
            $seen++;
            $price = self::comparable_price($offer);
            if ($price !== null && $price > 0) {
                $prices[] = $price;
            }
            if (!empty($offer['currency'])) {
                $currency = strtoupper(substr(sanitize_text_field((string) $offer['currency']), 0, 8));
            }
        }

        sort($prices, SORT_NUMERIC);
        $min = $prices ? min($prices) : null;
        $max = $prices ? max($prices) : null;
        $median = self::median($prices);
        $next = gmdate('Y-m-d H:i:s', time() + max(6, absint($interval_hours)) * HOUR_IN_SECONDS);
        if ($status === 'error') {
            $next = gmdate('Y-m-d H:i:s', time() + 12 * HOUR_IN_SECONDS);
        }
        if ($status === 'weak_identity') {
            $next = gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS);
        }

        $row = array(
            'object_id' => $object_id,
            'gtin' => SEO_Ojeador_Identity::normalize_gtin($identity['gtin'] ?? ''),
            'mpn' => sanitize_text_field((string) ($identity['mpn'] ?? '')),
            'brand' => sanitize_text_field((string) ($identity['brand'] ?? '')),
            'model' => sanitize_text_field((string) ($identity['model'] ?? '')),
            'query_text' => sanitize_text_field((string) ($scan['query'] ?? SEO_Ojeador_Shopping::build_query($identity))),
            'google_product_id' => $google_product_id,
            'google_title' => sanitize_text_field((string) ($scan['google_title'] ?? '')),
            'match_confidence' => max(0, min(1, (float) ($scan['match_confidence'] ?? 0))),
            'status' => $status,
            'offer_count' => $seen,
            'market_min' => $min,
            'market_median' => $median,
            'market_max' => $max,
            'currency' => $currency ?: 'EUR',
            'own_price_snapshot' => self::live_price($object_id),
            'last_scan_at' => $now,
            'next_scan_at' => $next,
            'last_error' => sanitize_textarea_field((string) ($scan['error'] ?? '')),
            'updated_at' => $now,
        );

        $exists = $wpdb->get_var($wpdb->prepare('SELECT object_id FROM ' . self::table('products') . ' WHERE object_id=%d', $object_id));
        $ok = $exists
            ? $wpdb->update(self::table('products'), $row, array('object_id'=>$object_id))
            : $wpdb->insert(self::table('products'), $row);
        if ($ok === false) {
            return new WP_Error('ojeador_save_scan', 'No se pudo guardar la comparación del producto.');
        }
        return array('object_id'=>$object_id, 'offers'=>$seen, 'status'=>$status);
    }

    public static function save_error($identity, $error, $interval_hours = 12) {
        $identity = is_array($identity) ? $identity : array();
        return self::save_scan($identity, array(
            'status' => 'error',
            'query' => SEO_Ojeador_Shopping::build_query($identity),
            'offers' => array(),
            'error' => is_wp_error($error) ? $error->get_error_message() : (string) $error,
        ), $interval_hours);
    }

    public static function save_weak_identity($identity) {
        return self::save_scan($identity, array(
            'status' => 'weak_identity',
            'query' => SEO_Ojeador_Shopping::build_query($identity),
            'offers' => array(),
            'error' => 'Identidad insuficiente para una comparación fiable.',
        ), 720);
    }

    private static function upsert_offer($object_id, $google_product_id, $offer, $now) {
        global $wpdb;
        $offer = is_array($offer) ? $offer : array();
        $merchant = sanitize_text_field((string) ($offer['merchant'] ?? ''));
        $price = self::decimal_or_null($offer['price'] ?? null);
        if ($merchant === '' || $price === null || $price <= 0) {
            return new WP_Error('ojeador_offer', 'Oferta incompleta.');
        }
        $merchant_key = sanitize_title($merchant);
        $url = esc_url_raw((string) ($offer['url'] ?? ''));
        $offer_hash = hash('sha256', strtolower($merchant_key . '|' . $url . '|' . $google_product_id));
        $data = array(
            'object_id' => absint($object_id),
            'google_product_id' => sanitize_text_field((string) $google_product_id),
            'merchant' => $merchant,
            'merchant_key' => $merchant_key,
            'offer_hash' => $offer_hash,
            'url' => $url,
            'price' => $price,
            'shipping_price' => self::decimal_or_null($offer['shipping_price'] ?? null),
            'total_price' => self::decimal_or_null($offer['total_price'] ?? null),
            'currency' => strtoupper(substr(sanitize_text_field((string) ($offer['currency'] ?? 'EUR')), 0, 8)) ?: 'EUR',
            'stock_text' => sanitize_text_field((string) ($offer['stock_text'] ?? '')),
            'condition_label' => sanitize_text_field((string) ($offer['condition_label'] ?? '')),
            'offer_position' => absint($offer['position'] ?? 0),
            'source' => sanitize_key((string) ($offer['source'] ?? 'google_shopping')) ?: 'google_shopping',
            'active' => 1,
            'last_seen_at' => $now,
            'observed_at' => $now,
            'raw_json' => isset($offer['raw']) ? wp_json_encode($offer['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        );
        $existing = $wpdb->get_row($wpdb->prepare(
            'SELECT id,first_seen_at FROM ' . self::table('offers') . ' WHERE object_id=%d AND offer_hash=%s LIMIT 1',
            absint($object_id),
            $offer_hash
        ), ARRAY_A);
        if ($existing) {
            $wpdb->update(self::table('offers'), $data, array('id'=>absint($existing['id'])));
            return absint($existing['id']);
        }
        $data['first_seen_at'] = $now;
        $wpdb->insert(self::table('offers'), $data);
        return $wpdb->insert_id ? absint($wpdb->insert_id) : new WP_Error('ojeador_offer_insert', 'No se pudo guardar la oferta.');
    }

    private static function comparable_price($offer) {
        if (isset($offer['total_price']) && $offer['total_price'] !== null && is_numeric($offer['total_price'])) {
            return (float) $offer['total_price'];
        }
        if (isset($offer['price']) && is_numeric($offer['price'])) {
            return (float) $offer['price'];
        }
        return null;
    }

    private static function median($values) {
        $values = array_values(array_filter((array) $values, 'is_numeric'));
        if (!$values) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $n = count($values);
        $mid = intdiv($n, 2);
        if ($n % 2) {
            return round((float) $values[$mid], 6);
        }
        return round(((float) $values[$mid - 1] + (float) $values[$mid]) / 2, 6);
    }

    private static function decimal_or_null($value) {
        return ($value === null || $value === '' || !is_numeric($value)) ? null : round((float) $value, 6);
    }

    public static function offers_for_object($object_id, $limit = 1000) {
        global $wpdb;
        $limit = max(1, min(1000, absint($limit)));
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table('offers') . ' WHERE object_id=%d AND active=1 ORDER BY COALESCE(total_price,price) ASC, offer_position ASC LIMIT %d',
            absint($object_id),
            $limit
        ), ARRAY_A);
    }

    public static function comparison_for_object($object_id) {
        global $wpdb;
        $object_id = absint($object_id);
        $market = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('products') . ' WHERE object_id=%d LIMIT 1', $object_id), ARRAY_A);
        $our = self::live_price($object_id);
        $offers = self::offers_for_object($object_id, 1000);
        $median = isset($market['market_median']) && $market['market_median'] !== null ? (float) $market['market_median'] : null;
        $diff = ($our !== null && $median !== null) ? $our - $median : null;
        $pct = ($diff !== null && $median > 0) ? ($diff / $median) * 100 : null;
        return array(
            'object_id' => $object_id,
            'our_price' => $our,
            'market_min' => isset($market['market_min']) ? self::decimal_or_null($market['market_min']) : null,
            'market_median' => $median,
            'market_max' => isset($market['market_max']) ? self::decimal_or_null($market['market_max']) : null,
            'difference' => $diff,
            'difference_pct' => $pct,
            'currency' => (string) ($market['currency'] ?? 'EUR'),
            'status' => (string) ($market['status'] ?? 'pending'),
            'last_scan_at' => (string) ($market['last_scan_at'] ?? ''),
            'offers' => $offers,
        );
    }

    public static function list_comparisons($args = array()) {
        global $wpdb;
        $args = wp_parse_args($args, array('limit'=>250,'search'=>''));
        $limit = max(1, min(1000, absint($args['limit'])));
        $search = trim((string) $args['search']);
        $products = self::table('products');
        $where = "WHERE m.status IN ('ok','no_offers','no_match','error','weak_identity')";
        $params = array();
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (p.post_title LIKE %s OR m.gtin LIKE %s OR m.mpn LIKE %s OR m.brand LIKE %s OR m.model LIKE %s)';
            $params = array($like,$like,$like,$like,$like);
        }
        $sql = "SELECT m.*,p.post_title
                FROM {$products} m
                LEFT JOIN {$wpdb->posts} p ON p.ID=m.object_id
                {$where}
                ORDER BY CASE WHEN m.status='ok' THEN 0 ELSE 1 END, m.last_scan_at DESC, m.object_id DESC
                LIMIT {$limit}";
        if ($params) {
            $sql = $wpdb->prepare($sql, $params);
        }
        return $wpdb->get_results($sql, ARRAY_A);
    }

    public static function summary() {
        global $wpdb;
        $products = self::table('products');
        $offers = self::table('offers');
        $rows = $wpdb->get_results("SELECT status,own_price_snapshot,market_median,offer_count FROM {$products}", ARRAY_A);
        $out = array(
            'published' => self::count_published_products(),
            'scanned' => count($rows),
            'compared' => 0,
            'cheaper' => 0,
            'aligned' => 0,
            'dearer' => 0,
            'no_data' => 0,
            'offers' => absint($wpdb->get_var("SELECT COUNT(*) FROM {$offers} WHERE active=1")),
            'due' => self::count_due_products(),
        );
        foreach ($rows as $row) {
            if ($row['status'] !== 'ok' || !is_numeric($row['market_median']) || (float) $row['market_median'] <= 0 || !is_numeric($row['own_price_snapshot'])) {
                $out['no_data']++;
                continue;
            }
            $out['compared']++;
            $pct = (((float) $row['own_price_snapshot'] - (float) $row['market_median']) / (float) $row['market_median']) * 100;
            if ($pct < -3) {
                $out['cheaper']++;
            } elseif ($pct > 3) {
                $out['dearer']++;
            } else {
                $out['aligned']++;
            }
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * RUNS
     * ------------------------------------------------------------------ */

    public static function create_run($source = 'manual') {
        global $wpdb;
        $now = self::utc_now();
        $wpdb->insert(self::table('runs'), array(
            'source' => sanitize_key((string) $source) ?: 'manual',
            'status' => 'pending',
            'total_candidates' => self::count_due_categories(),
            'created_at' => $now,
        ));
        return absint($wpdb->insert_id);
    }

    public static function active_run() {
        global $wpdb;
        return $wpdb->get_row("SELECT * FROM " . self::table('runs') . " WHERE status IN ('pending','running') ORDER BY id ASC LIMIT 1", ARRAY_A);
    }

    public static function latest_run() {
        global $wpdb;
        return $wpdb->get_row('SELECT * FROM ' . self::table('runs') . ' ORDER BY id DESC LIMIT 1', ARRAY_A);
    }

    public static function update_run($run_id, $data) {
        global $wpdb;
        $allowed = array(
            'status','total_candidates',
            'processed_products','compared_products','no_match_products','offers_seen','last_object_id',
            'processed_categories','categories_with_results','categories_without_results','results_seen','api_queries','last_term_id',
            'errors_count','last_error','started_at','heartbeat_at','completed_at'
        );
        $clean = array();
        foreach ($allowed as $key) {
            if (array_key_exists($key, (array) $data)) {
                $clean[$key] = $data[$key];
            }
        }
        return $clean ? $wpdb->update(self::table('runs'), $clean, array('id'=>absint($run_id))) : false;
    }

    public static function stop_active_run() {
        global $wpdb;
        $run = self::active_run();
        if (!$run) {
            return false;
        }
        return false !== $wpdb->update(self::table('runs'), array(
            'status'=>'stopped',
            'completed_at'=>self::utc_now(),
            'heartbeat_at'=>self::utc_now(),
        ), array('id'=>absint($run['id'])));
    }
}
