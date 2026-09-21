<?php
/**
 * Ojeador market database.
 *
 * Stores only the current Google Shopping comparison needed by the product
 * comparator. Legacy manual/scraping tables are not used by v0.5.0.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @since 0.5.0
 */

defined('ABSPATH') || exit;

final class SEO_Ojeador_DB {
    const OPTION_DB_VERSION = 'seo_ojeador_db_version';
    const DB_VERSION = '0.5.0';

    public static function table($name) {
        global $wpdb;
        $map = array(
            'products' => $wpdb->prefix . 'seo_ojeador_market_products',
            'offers'   => $wpdb->prefix . 'seo_ojeador_market_offers',
            'runs'     => $wpdb->prefix . 'seo_ojeador_market_runs',
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
        self::install();
    }

    private static function tables_exist() {
        global $wpdb;
        foreach (array('products','offers','runs') as $name) {
            $table = self::table($name);
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                return false;
            }
        }
        return true;
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $products = self::table('products');
        $offers = self::table('offers');
        $runs = self::table('runs');

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

        dbDelta("CREATE TABLE {$runs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source varchar(32) NOT NULL DEFAULT 'manual',
            status varchar(32) NOT NULL DEFAULT 'pending',
            total_candidates int(10) unsigned NOT NULL DEFAULT 0,
            processed_products int(10) unsigned NOT NULL DEFAULT 0,
            compared_products int(10) unsigned NOT NULL DEFAULT 0,
            no_match_products int(10) unsigned NOT NULL DEFAULT 0,
            offers_seen int(10) unsigned NOT NULL DEFAULT 0,
            errors_count int(10) unsigned NOT NULL DEFAULT 0,
            last_object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            last_error text NULL,
            started_at datetime NULL,
            heartbeat_at datetime NULL,
            completed_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};");

        update_option(self::OPTION_DB_VERSION, self::DB_VERSION, false);
    }

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
            // A successful Google response replaces the active market snapshot.
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
        if ($exists) {
            $ok = $wpdb->update(self::table('products'), $row, array('object_id'=>$object_id));
        } else {
            $ok = $wpdb->insert(self::table('products'), $row);
        }
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

    public static function offers_for_object($object_id, $limit = 13) {
        global $wpdb;
        $limit = max(1, min(50, absint($limit)));
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
        $offers = self::offers_for_object($object_id, 13);
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

    public static function create_run($source = 'manual') {
        global $wpdb;
        $now = self::utc_now();
        $wpdb->insert(self::table('runs'), array(
            'source' => sanitize_key((string) $source) ?: 'manual',
            'status' => 'pending',
            'total_candidates' => self::count_due_products(),
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
        $allowed = array('status','total_candidates','processed_products','compared_products','no_match_products','offers_seen','errors_count','last_object_id','last_error','started_at','heartbeat_at','completed_at');
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
