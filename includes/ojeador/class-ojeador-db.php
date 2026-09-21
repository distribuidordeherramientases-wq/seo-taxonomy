<?php
/**
 * Ojeador database and repositories.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @since 0.3.0
 */

defined('ABSPATH') || exit;

final class SEO_Ojeador_DB {
    const OPTION_DB_VERSION = 'seo_ojeador_db_version';
    const DB_VERSION = '0.3.0';

    public static function table($name) {
        global $wpdb;
        $map = array(
            'products' => $wpdb->prefix . 'seo_ojeador_products',
            'offers'   => $wpdb->prefix . 'seo_ojeador_offers',
            'history'  => $wpdb->prefix . 'seo_ojeador_price_history',
            'runs'     => $wpdb->prefix . 'seo_ojeador_runs',
        );
        return isset($map[$name]) ? $map[$name] : '';
    }

    public static function utc_now() {
        return gmdate('Y-m-d H:i:s');
    }

    public static function maybe_install() {
        if (get_option(self::OPTION_DB_VERSION, '') === self::DB_VERSION) {
            return true;
        }
        return self::install();
    }

    public static function schema_ready() {
        global $wpdb;
        foreach (array('products', 'offers', 'history', 'runs') as $key) {
            $table = self::table($key);
            if (!$table) {
                return false;
            }
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($found !== $table) {
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
        $history = self::table('history');
        $runs = self::table('runs');

        $sql_products = "CREATE TABLE {$products} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            object_id BIGINT UNSIGNED NOT NULL,
            sku VARCHAR(190) NOT NULL DEFAULT '',
            gtin VARCHAR(32) NOT NULL DEFAULT '',
            mpn VARCHAR(190) NOT NULL DEFAULT '',
            brand VARCHAR(190) NOT NULL DEFAULT '',
            model VARCHAR(190) NOT NULL DEFAULT '',
            canonical_name VARCHAR(255) NOT NULL DEFAULT '',
            normalized_name VARCHAR(255) NOT NULL DEFAULT '',
            source_provider VARCHAR(190) NOT NULL DEFAULT '',
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
            scan_interval_hours INT UNSIGNED NOT NULL DEFAULT 168,
            last_scan_at DATETIME NULL,
            next_scan_at DATETIME NULL,
            last_offer_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY object_id (object_id),
            KEY gtin (gtin),
            KEY mpn (mpn),
            KEY brand (brand),
            KEY status (status),
            KEY next_scan_at (next_scan_at)
        ) {$charset};";

        $sql_offers = "CREATE TABLE {$offers} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ojeador_product_id BIGINT UNSIGNED NOT NULL,
            object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            refresh_mode VARCHAR(24) NOT NULL DEFAULT 'import_only',
            import_batch VARCHAR(64) NOT NULL DEFAULT '',
            imported_at DATETIME NULL,
            source_key VARCHAR(80) NOT NULL DEFAULT '',
            source_type VARCHAR(32) NOT NULL DEFAULT 'external_url',
            merchant_name VARCHAR(190) NOT NULL DEFAULT '',
            seller_name VARCHAR(190) NOT NULL DEFAULT '',
            external_product_id VARCHAR(190) NOT NULL DEFAULT '',
            offer_key CHAR(64) NOT NULL,
            url_hash CHAR(64) NOT NULL DEFAULT '',
            url LONGTEXT NULL,
            observed_title VARCHAR(255) NOT NULL DEFAULT '',
            observed_gtin VARCHAR(32) NOT NULL DEFAULT '',
            observed_mpn VARCHAR(190) NOT NULL DEFAULT '',
            observed_brand VARCHAR(190) NOT NULL DEFAULT '',
            observed_model VARCHAR(190) NOT NULL DEFAULT '',
            price_raw DECIMAL(18,6) NULL,
            price_net DECIMAL(18,6) NULL,
            price_gross DECIMAL(18,6) NULL,
            vat_rate DECIMAL(8,4) NULL,
            vat_mode VARCHAR(20) NOT NULL DEFAULT 'unknown',
            shipping_price DECIMAL(18,6) NULL,
            shipping_mode VARCHAR(20) NOT NULL DEFAULT 'unknown',
            total_price DECIMAL(18,6) NULL,
            currency VARCHAR(12) NOT NULL DEFAULT 'EUR',
            stock_status VARCHAR(50) NOT NULL DEFAULT '',
            stock_text VARCHAR(255) NOT NULL DEFAULT '',
            condition_label VARCHAR(80) NOT NULL DEFAULT '',
            match_method VARCHAR(40) NOT NULL DEFAULT '',
            match_confidence DECIMAL(6,5) NOT NULL DEFAULT 0,
            match_status VARCHAR(24) NOT NULL DEFAULT 'review',
            extraction_method VARCHAR(40) NOT NULL DEFAULT '',
            fingerprint CHAR(64) NOT NULL DEFAULT '',
            first_seen_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            observed_at DATETIME NOT NULL,
            expires_at DATETIME NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            raw_json LONGTEXT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY product_offer (ojeador_product_id,offer_key),
            KEY object_id (object_id),
            KEY refresh_mode (refresh_mode),
            KEY source_key (source_key),
            KEY merchant_name (merchant_name),
            KEY match_status (match_status),
            KEY active (active),
            KEY observed_at (observed_at),
            KEY expires_at (expires_at)
        ) {$charset};";

        $sql_history = "CREATE TABLE {$history} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            offer_id BIGINT UNSIGNED NOT NULL,
            object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            fingerprint CHAR(64) NOT NULL,
            price_raw DECIMAL(18,6) NULL,
            price_net DECIMAL(18,6) NULL,
            price_gross DECIMAL(18,6) NULL,
            vat_rate DECIMAL(8,4) NULL,
            vat_mode VARCHAR(20) NOT NULL DEFAULT 'unknown',
            shipping_price DECIMAL(18,6) NULL,
            shipping_mode VARCHAR(20) NOT NULL DEFAULT 'unknown',
            total_price DECIMAL(18,6) NULL,
            currency VARCHAR(12) NOT NULL DEFAULT 'EUR',
            stock_status VARCHAR(50) NOT NULL DEFAULT '',
            observed_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY offer_fingerprint (offer_id,fingerprint),
            KEY offer_id (offer_id),
            KEY object_id (object_id),
            KEY observed_at (observed_at)
        ) {$charset};";

        $sql_runs = "CREATE TABLE {$runs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_type VARCHAR(24) NOT NULL DEFAULT 'manual',
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            source VARCHAR(40) NOT NULL DEFAULT '',
            started_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            max_products INT UNSIGNED NOT NULL DEFAULT 0,
            total_candidates INT UNSIGNED NOT NULL DEFAULT 0,
            cursor_object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            processed_products INT UNSIGNED NOT NULL DEFAULT 0,
            offers_seen INT UNSIGNED NOT NULL DEFAULT 0,
            offers_created INT UNSIGNED NOT NULL DEFAULT 0,
            offers_updated INT UNSIGNED NOT NULL DEFAULT 0,
            errors_count INT UNSIGNED NOT NULL DEFAULT 0,
            started_at DATETIME NULL,
            heartbeat_at DATETIME NULL,
            completed_at DATETIME NULL,
            last_error TEXT NULL,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta($sql_products);
        dbDelta($sql_offers);
        dbDelta($sql_history);
        dbDelta($sql_runs);

        // v0.3.0: las ofertas pasan a conservar también el object_id común.
        // Esto permite consultar la comparativa directamente por el ID WooCommerce
        // sin tratar nuestra propia ficha como una oferta de Ojeador.
        $wpdb->query("UPDATE {$offers} o INNER JOIN {$products} p ON p.id=o.ojeador_product_id SET o.object_id=p.object_id WHERE o.object_id=0");
        $wpdb->query("UPDATE {$history} h INNER JOIN {$offers} o ON o.id=h.offer_id SET h.object_id=o.object_id WHERE h.object_id=0");

        if (!self::schema_ready()) {
            return new WP_Error('ojeador_schema', 'No se pudieron crear todas las tablas de Ojeador.');
        }

        update_option(self::OPTION_DB_VERSION, self::DB_VERSION, false);
        return true;
    }

    public static function upsert_product($identity) {
        global $wpdb;
        $table = self::table('products');
        $object_id = absint($identity['object_id'] ?? 0);
        if ($object_id < 1) {
            return new WP_Error('ojeador_product_id', 'Producto no valido.');
        }

        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE object_id=%d LIMIT 1", $object_id), ARRAY_A);
        $now = self::utc_now();
        $row = array(
            'sku' => sanitize_text_field((string) ($identity['sku'] ?? '')),
            'gtin' => sanitize_text_field((string) ($identity['gtin'] ?? '')),
            'mpn' => sanitize_text_field((string) ($identity['mpn'] ?? '')),
            'brand' => sanitize_text_field((string) ($identity['brand'] ?? '')),
            'model' => sanitize_text_field((string) ($identity['model'] ?? '')),
            'canonical_name' => sanitize_text_field((string) ($identity['name'] ?? '')),
            'normalized_name' => sanitize_text_field((string) ($identity['normalized_name'] ?? '')),
            'source_provider' => sanitize_text_field((string) ($identity['source_provider'] ?? '')),
            'status' => sanitize_key((string) ($identity['status'] ?? 'active')) ?: 'active',
            'updated_at' => $now,
        );

        if ($existing) {
            // A refresh must not reactivate a product that an administrator paused.
            unset($row['status']);
            $wpdb->update($table, $row, array('id' => absint($existing['id'])));
            return absint($existing['id']);
        }

        $row['object_id'] = $object_id;
        $row['priority'] = max(1, min(10, absint($identity['priority'] ?? 5)));
        $row['scan_interval_hours'] = max(1, absint($identity['scan_interval_hours'] ?? 168));
        $row['created_at'] = $now;
        $wpdb->insert($table, $row);
        return $wpdb->insert_id ? absint($wpdb->insert_id) : new WP_Error('ojeador_product_insert', 'No se pudo registrar el producto en Ojeador.');
    }

    public static function touch_product_scan($ojeador_product_id, $hours = 168, $offer_seen = false) {
        global $wpdb;
        $table = self::table('products');
        $now_ts = time();
        $data = array(
            'last_scan_at' => gmdate('Y-m-d H:i:s', $now_ts),
            'next_scan_at' => gmdate('Y-m-d H:i:s', $now_ts + (max(1, absint($hours)) * HOUR_IN_SECONDS)),
            'updated_at' => gmdate('Y-m-d H:i:s', $now_ts),
        );
        if ($offer_seen) {
            $data['last_offer_at'] = gmdate('Y-m-d H:i:s', $now_ts);
        }
        $wpdb->update($table, $data, array('id' => absint($ojeador_product_id)));
    }

    public static function product_row_by_object($object_id) {
        global $wpdb;
        $table = self::table('products');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE object_id=%d LIMIT 1", absint($object_id)), ARRAY_A);
    }

    public static function offer_rows_for_product($ojeador_product_id, $include_inactive = false) {
        global $wpdb;
        $table = self::table('offers');
        $where = $include_inactive ? '' : ' AND active=1';
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE ojeador_product_id=%d {$where} ORDER BY match_confidence DESC, observed_at DESC, id DESC",
            absint($ojeador_product_id)
        ), ARRAY_A);
    }

    public static function offer_rows_for_object($object_id, $include_inactive = false) {
        global $wpdb;
        $table = self::table('offers');
        $where = $include_inactive ? '' : ' AND active=1';
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE object_id=%d {$where} ORDER BY match_confidence DESC, observed_at DESC, id DESC",
            absint($object_id)
        ), ARRAY_A);
    }

    public static function offer_row($offer_id) {
        global $wpdb;
        $table = self::table('offers');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1", absint($offer_id)), ARRAY_A);
    }

    public static function save_offer($ojeador_product_id, $offer) {
        global $wpdb;
        $table = self::table('offers');
        $ojeador_product_id = absint($ojeador_product_id);
        if ($ojeador_product_id < 1) {
            return new WP_Error('ojeador_offer_product', 'Producto Ojeador no valido.');
        }

        $offer_key = (string) ($offer['offer_key'] ?? '');
        if ($offer_key === '') {
            $offer_key = hash('sha256', implode('|', array(
                (string) ($offer['source_key'] ?? ''),
                (string) ($offer['merchant_name'] ?? ''),
                (string) ($offer['external_product_id'] ?? ''),
                (string) ($offer['url'] ?? ''),
            )));
        }
        $url = esc_url_raw((string) ($offer['url'] ?? ''));
        $url_hash = $url !== '' ? hash('sha256', $url) : '';
        $now = self::utc_now();
        $object_id = absint($offer['object_id'] ?? 0);
        if ($object_id < 1) {
            $object_id = absint($wpdb->get_var($wpdb->prepare(
                'SELECT object_id FROM ' . self::table('products') . ' WHERE id=%d LIMIT 1',
                $ojeador_product_id
            )));
        }
        if ($object_id < 1) {
            return new WP_Error('ojeador_offer_object', 'No se pudo resolver el object_id de la oferta.');
        }
        $observed_at = self::sanitize_mysql_date($offer['observed_at'] ?? '') ?: $now;
        $expires_at = self::sanitize_mysql_date($offer['expires_at'] ?? '');

        $snapshot = array(
            'price_raw' => self::decimal_or_null($offer['price_raw'] ?? null),
            'price_net' => self::decimal_or_null($offer['price_net'] ?? null),
            'price_gross' => self::decimal_or_null($offer['price_gross'] ?? null),
            'vat_rate' => self::decimal_or_null($offer['vat_rate'] ?? null),
            'vat_mode' => sanitize_key((string) ($offer['vat_mode'] ?? 'unknown')) ?: 'unknown',
            'shipping_price' => self::decimal_or_null($offer['shipping_price'] ?? null),
            'shipping_mode' => sanitize_key((string) ($offer['shipping_mode'] ?? 'unknown')) ?: 'unknown',
            'total_price' => self::decimal_or_null($offer['total_price'] ?? null),
            'currency' => strtoupper(substr(sanitize_text_field((string) ($offer['currency'] ?? 'EUR')), 0, 12)),
            'stock_status' => sanitize_key((string) ($offer['stock_status'] ?? '')),
        );
        $fingerprint = hash('sha256', wp_json_encode($snapshot));

        $refresh_mode = sanitize_key((string) ($offer['refresh_mode'] ?? 'import_only'));
        if (!in_array($refresh_mode, array('import_only', 'generic_web', 'internal_feed'), true)) {
            $refresh_mode = 'import_only';
        }
        $data = array(
            'object_id' => $object_id,
            'refresh_mode' => $refresh_mode,
            'import_batch' => sanitize_text_field((string) ($offer['import_batch'] ?? '')),
            'imported_at' => self::sanitize_mysql_date($offer['imported_at'] ?? '') ?: null,
            'source_key' => sanitize_key((string) ($offer['source_key'] ?? '')),
            'source_type' => sanitize_key((string) ($offer['source_type'] ?? 'external_url')) ?: 'external_url',
            'merchant_name' => sanitize_text_field((string) ($offer['merchant_name'] ?? '')),
            'seller_name' => sanitize_text_field((string) ($offer['seller_name'] ?? '')),
            'external_product_id' => sanitize_text_field((string) ($offer['external_product_id'] ?? '')),
            'offer_key' => $offer_key,
            'url_hash' => $url_hash,
            'url' => $url,
            'observed_title' => sanitize_text_field((string) ($offer['observed_title'] ?? '')),
            'observed_gtin' => sanitize_text_field((string) ($offer['observed_gtin'] ?? '')),
            'observed_mpn' => sanitize_text_field((string) ($offer['observed_mpn'] ?? '')),
            'observed_brand' => sanitize_text_field((string) ($offer['observed_brand'] ?? '')),
            'observed_model' => sanitize_text_field((string) ($offer['observed_model'] ?? '')),
            'price_raw' => $snapshot['price_raw'],
            'price_net' => $snapshot['price_net'],
            'price_gross' => $snapshot['price_gross'],
            'vat_rate' => $snapshot['vat_rate'],
            'vat_mode' => $snapshot['vat_mode'],
            'shipping_price' => $snapshot['shipping_price'],
            'shipping_mode' => $snapshot['shipping_mode'],
            'total_price' => $snapshot['total_price'],
            'currency' => $snapshot['currency'] ?: 'EUR',
            'stock_status' => $snapshot['stock_status'],
            'stock_text' => sanitize_text_field((string) ($offer['stock_text'] ?? '')),
            'condition_label' => sanitize_text_field((string) ($offer['condition_label'] ?? '')),
            'match_method' => sanitize_key((string) ($offer['match_method'] ?? '')),
            'match_confidence' => max(0, min(1, (float) ($offer['match_confidence'] ?? 0))),
            'match_status' => sanitize_key((string) ($offer['match_status'] ?? 'review')) ?: 'review',
            'extraction_method' => sanitize_key((string) ($offer['extraction_method'] ?? '')),
            'fingerprint' => $fingerprint,
            'last_seen_at' => $now,
            'observed_at' => $observed_at,
            'expires_at' => $expires_at ?: null,
            'active' => empty($offer['active']) && array_key_exists('active', $offer) ? 0 : 1,
            'raw_json' => isset($offer['raw']) ? wp_json_encode($offer['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        );

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE ojeador_product_id=%d AND offer_key=%s LIMIT 1",
            $ojeador_product_id,
            $offer_key
        ), ARRAY_A);

        if ($existing) {
            $data['first_seen_at'] = (string) $existing['first_seen_at'];
            $wpdb->update($table, $data, array('id' => absint($existing['id'])));
            $offer_id = absint($existing['id']);
            $changed = (string) ($existing['fingerprint'] ?? '') !== $fingerprint;
            if ($changed) {
                self::insert_history($offer_id, $data, $fingerprint);
            }
            return array('id' => $offer_id, 'created' => false, 'changed' => $changed);
        }

        $data['ojeador_product_id'] = $ojeador_product_id;
        $data['first_seen_at'] = $now;
        $wpdb->insert($table, $data);
        $offer_id = absint($wpdb->insert_id);
        if ($offer_id < 1) {
            return new WP_Error('ojeador_offer_insert', 'No se pudo guardar la oferta.');
        }
        self::insert_history($offer_id, $data, $fingerprint);
        return array('id' => $offer_id, 'created' => true, 'changed' => true);
    }

    private static function insert_history($offer_id, $offer, $fingerprint) {
        global $wpdb;
        $table = self::table('history');
        $row = array(
            'offer_id' => absint($offer_id),
            'object_id' => absint($offer['object_id'] ?? 0),
            'fingerprint' => (string) $fingerprint,
            'price_raw' => self::decimal_or_null($offer['price_raw'] ?? null),
            'price_net' => self::decimal_or_null($offer['price_net'] ?? null),
            'price_gross' => self::decimal_or_null($offer['price_gross'] ?? null),
            'vat_rate' => self::decimal_or_null($offer['vat_rate'] ?? null),
            'vat_mode' => sanitize_key((string) ($offer['vat_mode'] ?? 'unknown')) ?: 'unknown',
            'shipping_price' => self::decimal_or_null($offer['shipping_price'] ?? null),
            'shipping_mode' => sanitize_key((string) ($offer['shipping_mode'] ?? 'unknown')) ?: 'unknown',
            'total_price' => self::decimal_or_null($offer['total_price'] ?? null),
            'currency' => strtoupper(substr(sanitize_text_field((string) ($offer['currency'] ?? 'EUR')), 0, 12)),
            'stock_status' => sanitize_key((string) ($offer['stock_status'] ?? '')),
            'observed_at' => self::sanitize_mysql_date($offer['observed_at'] ?? '') ?: self::utc_now(),
            'created_at' => self::utc_now(),
        );
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE offer_id=%d AND fingerprint=%s LIMIT 1",
            $row['offer_id'],
            $row['fingerprint']
        ));
        if (!$exists) {
            $wpdb->insert($table, $row);
        }
    }

    public static function set_offer_status($offer_id, $status) {
        global $wpdb;
        $status = sanitize_key((string) $status);
        if (!in_array($status, array('confirmed', 'probable', 'review', 'rejected'), true)) {
            return false;
        }
        return false !== $wpdb->update(self::table('offers'), array('match_status' => $status), array('id' => absint($offer_id)));
    }

    public static function create_run($args = array()) {
        global $wpdb;
        $args = wp_parse_args($args, array(
            'run_type' => 'manual',
            'source' => '',
            'started_by' => 0,
            'max_products' => 100,
            'total_candidates' => 0,
            'meta' => array(),
        ));
        $now = self::utc_now();
        $wpdb->insert(self::table('runs'), array(
            'run_type' => sanitize_key((string) $args['run_type']) ?: 'manual',
            'status' => 'pending',
            'source' => sanitize_key((string) $args['source']),
            'started_by' => absint($args['started_by']),
            'max_products' => max(1, absint($args['max_products'])),
            'total_candidates' => max(0, absint($args['total_candidates'])),
            'cursor_object_id' => 0,
            'processed_products' => 0,
            'offers_seen' => 0,
            'offers_created' => 0,
            'offers_updated' => 0,
            'errors_count' => 0,
            'meta_json' => wp_json_encode((array) $args['meta']),
            'created_at' => $now,
            'updated_at' => $now,
        ));
        return absint($wpdb->insert_id);
    }

    public static function active_run() {
        global $wpdb;
        $table = self::table('runs');
        return $wpdb->get_row("SELECT * FROM {$table} WHERE status IN ('pending','running') ORDER BY id ASC LIMIT 1", ARRAY_A);
    }

    public static function latest_run() {
        global $wpdb;
        return $wpdb->get_row("SELECT * FROM " . self::table('runs') . ' ORDER BY id DESC LIMIT 1', ARRAY_A);
    }

    public static function run_row($run_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('runs') . ' WHERE id=%d LIMIT 1', absint($run_id)), ARRAY_A);
    }

    public static function update_run($run_id, $data) {
        global $wpdb;
        if (!is_array($data)) {
            return false;
        }
        $allowed = array(
            'status','cursor_object_id','processed_products','offers_seen','offers_created','offers_updated','errors_count',
            'started_at','heartbeat_at','completed_at','last_error','meta_json','updated_at'
        );
        $clean = array();
        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) {
                $clean[$key] = $data[$key];
            }
        }
        $clean['updated_at'] = self::utc_now();
        return false !== $wpdb->update(self::table('runs'), $clean, array('id' => absint($run_id)));
    }

    public static function recent_products($limit = 50) {
        global $wpdb;
        $products = self::table('products');
        $offers = self::table('offers');
        $limit = max(1, min(200, absint($limit)));
        $sql = "SELECT p.*,
                       COUNT(CASE WHEN o.active=1 AND o.source_type<>'provider_catalog' AND o.match_status IN ('confirmed','probable') THEN 1 END) AS offer_count,
                       MIN(CASE WHEN o.active=1 AND o.source_type<>'provider_catalog' AND o.match_status IN ('confirmed','probable') THEN COALESCE(o.total_price,o.price_gross,o.price_raw) END) AS market_min,
                       MAX(CASE WHEN o.active=1 AND o.source_type<>'provider_catalog' AND o.match_status IN ('confirmed','probable') THEN COALESCE(o.total_price,o.price_gross,o.price_raw) END) AS market_max,
                       MAX(o.observed_at) AS offers_observed_at
                FROM {$products} p
                LEFT JOIN {$offers} o ON o.object_id=p.object_id
                GROUP BY p.id
                ORDER BY COALESCE(p.last_scan_at,p.created_at) DESC, p.id DESC
                LIMIT {$limit}";
        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    public static function summary() {
        global $wpdb;
        $products = self::table('products');
        $offers = self::table('offers');
        $now = self::utc_now();
        return array(
            'products' => absint($wpdb->get_var("SELECT COUNT(*) FROM {$products}")),
            'offers' => absint($wpdb->get_var("SELECT COUNT(*) FROM {$offers} WHERE active=1 AND source_type<>'provider_catalog'")),
            'supplier_offers' => absint($wpdb->get_var("SELECT COUNT(*) FROM {$offers} WHERE active=1 AND source_type='provider_catalog'")),
            'import_only' => absint($wpdb->get_var("SELECT COUNT(*) FROM {$offers} WHERE active=1 AND refresh_mode='import_only'")),
            'confirmed' => absint($wpdb->get_var("SELECT COUNT(*) FROM {$offers} WHERE active=1 AND source_type<>'provider_catalog' AND match_status='confirmed'")),
            'review' => absint($wpdb->get_var("SELECT COUNT(*) FROM {$offers} WHERE active=1 AND source_type<>'provider_catalog' AND match_status='review'")),
            'fresh' => absint($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$offers} WHERE active=1 AND source_type<>'provider_catalog' AND (expires_at IS NULL OR expires_at >= %s)", $now))),
        );
    }

    public static function comparison_for_object($object_id) {
        $object_id = absint($object_id);
        $product_row = self::product_row_by_object($object_id);
        if (!$product_row && class_exists('SEO_Ojeador_Identity')) {
            $identity = SEO_Ojeador_Identity::from_product($object_id);
            if (!is_wp_error($identity)) {
                $product_row = array(
                    'object_id' => $object_id,
                    'sku' => (string) ($identity['sku'] ?? ''),
                    'gtin' => (string) ($identity['gtin'] ?? ''),
                    'mpn' => (string) ($identity['mpn'] ?? ''),
                    'brand' => (string) ($identity['brand'] ?? ''),
                    'model' => (string) ($identity['model'] ?? ''),
                    'canonical_name' => (string) ($identity['name'] ?? ''),
                );
            }
        }
        $offers = self::offer_rows_for_object($object_id);
        $market_offers = array_values(array_filter($offers, static function ($row) {
            return (string) ($row['source_type'] ?? '') !== 'provider_catalog';
        }));
        $supplier_offers = array_values(array_filter($offers, static function ($row) {
            return (string) ($row['source_type'] ?? '') === 'provider_catalog';
        }));
        return array(
            'product' => $product_row,
            'offers' => $market_offers,
            'all_offers' => $offers,
            'supplier_offers' => $supplier_offers,
            'stats' => self::stats_for_offers($market_offers),
            'supplier_stats' => self::stats_for_offers($supplier_offers),
        );
    }

    private static function stats_for_offers($offers) {
        $usable = array_values(array_filter((array) $offers, static function ($row) {
            return !empty($row['active']) && in_array((string) ($row['match_status'] ?? ''), array('confirmed', 'probable'), true);
        }));
        $values = array();
        foreach ($usable as $row) {
            $value = null;
            foreach (array('total_price', 'price_gross', 'price_raw') as $key) {
                if (isset($row[$key]) && $row[$key] !== null && $row[$key] !== '') {
                    $value = (float) $row[$key];
                    break;
                }
            }
            if (null !== $value && $value > 0) {
                $values[] = $value;
            }
        }
        sort($values, SORT_NUMERIC);
        return array(
            'count' => count($values),
            'min' => $values ? min($values) : null,
            'max' => $values ? max($values) : null,
            'median' => self::median($values),
        );
    }


    public static function set_product_status($object_id, $status) {
        global $wpdb;
        $status = sanitize_key((string) $status);
        if (!in_array($status, array('active', 'paused'), true)) {
            return false;
        }
        return false !== $wpdb->update(
            self::table('products'),
            array('status' => $status, 'updated_at' => self::utc_now()),
            array('object_id' => absint($object_id))
        );
    }

    public static function set_offer_active($offer_id, $active) {
        global $wpdb;
        return false !== $wpdb->update(
            self::table('offers'),
            array('active' => $active ? 1 : 0),
            array('id' => absint($offer_id))
        );
    }

    public static function list_products($args = array()) {
        global $wpdb;
        $args = wp_parse_args($args, array(
            'limit' => 100,
            'offset' => 0,
            'search' => '',
            'status' => '',
        ));
        $limit = max(1, min(500, absint($args['limit'])));
        $offset = max(0, absint($args['offset']));
        $where = array('1=1');
        $params = array();
        $status = sanitize_key((string) $args['status']);
        if (in_array($status, array('active', 'paused'), true)) {
            $where[] = 'p.status=%s';
            $params[] = $status;
        }
        $search = trim((string) $args['search']);
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            if (ctype_digit($search)) {
                $where[] = '(p.object_id=%d OR p.canonical_name LIKE %s OR p.sku LIKE %s OR p.gtin LIKE %s OR p.mpn LIKE %s OR p.brand LIKE %s OR p.model LIKE %s)';
                $params[] = absint($search);
                foreach (range(1, 6) as $unused) { $params[] = $like; }
            } else {
                $where[] = '(p.canonical_name LIKE %s OR p.sku LIKE %s OR p.gtin LIKE %s OR p.mpn LIKE %s OR p.brand LIKE %s OR p.model LIKE %s)';
                foreach (range(1, 6) as $unused) { $params[] = $like; }
            }
        }
        $products = self::table('products');
        $offers = self::table('offers');
        $sql = "SELECT p.*,
                       COUNT(CASE WHEN o.active=1 AND o.source_type<>'provider_catalog' THEN 1 END) AS offer_count,
                       COUNT(CASE WHEN o.active=1 AND o.source_type<>'provider_catalog' AND o.match_status IN ('confirmed','probable') THEN 1 END) AS usable_offer_count,
                       MIN(CASE WHEN o.active=1 AND o.source_type<>'provider_catalog' AND o.match_status IN ('confirmed','probable') THEN COALESCE(o.total_price,o.price_gross,o.price_raw) END) AS market_min,
                       MAX(CASE WHEN o.active=1 AND o.source_type<>'provider_catalog' AND o.match_status IN ('confirmed','probable') THEN COALESCE(o.total_price,o.price_gross,o.price_raw) END) AS market_max,
                       MAX(o.observed_at) AS offers_observed_at
                FROM {$products} p
                LEFT JOIN {$offers} o ON o.object_id=p.object_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY p.id
                ORDER BY COALESCE(p.last_scan_at,p.created_at) DESC, p.id DESC
                LIMIT {$limit} OFFSET {$offset}";
        if ($params) {
            $sql = $wpdb->prepare($sql, $params);
        }
        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    public static function list_offers($args = array()) {
        global $wpdb;
        $args = wp_parse_args($args, array(
            'limit' => 100,
            'offset' => 0,
            'search' => '',
            'match_status' => '',
            'active' => '',
        ));
        $limit = max(1, min(500, absint($args['limit'])));
        $offset = max(0, absint($args['offset']));
        $where = array('1=1');
        $params = array();
        $match_status = sanitize_key((string) $args['match_status']);
        if (in_array($match_status, array('confirmed','probable','review','rejected'), true)) {
            $where[] = 'o.match_status=%s';
            $params[] = $match_status;
        }
        if ($args['active'] !== '') {
            $where[] = 'o.active=%d';
            $params[] = empty($args['active']) ? 0 : 1;
        }
        $search = trim((string) $args['search']);
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(p.canonical_name LIKE %s OR o.merchant_name LIKE %s OR o.seller_name LIKE %s OR o.observed_title LIKE %s OR o.observed_gtin LIKE %s OR o.observed_mpn LIKE %s)';
            foreach (range(1, 6) as $unused) { $params[] = $like; }
        }
        $sql = "SELECT o.*, o.object_id, p.canonical_name, p.brand AS canonical_brand, p.mpn AS canonical_mpn, p.gtin AS canonical_gtin
                FROM " . self::table('offers') . " o
                LEFT JOIN " . self::table('products') . " p ON p.object_id=o.object_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY o.observed_at DESC, o.id DESC
                LIMIT {$limit} OFFSET {$offset}";
        if ($params) {
            $sql = $wpdb->prepare($sql, $params);
        }
        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    public static function list_history($limit = 150) {
        global $wpdb;
        $limit = max(1, min(500, absint($limit)));
        $sql = "SELECT h.*, o.merchant_name, o.url, o.match_status, o.object_id, p.canonical_name
                FROM " . self::table('history') . " h
                INNER JOIN " . self::table('offers') . " o ON o.id=h.offer_id
                LEFT JOIN " . self::table('products') . " p ON p.object_id=o.object_id
                ORDER BY h.observed_at DESC, h.id DESC
                LIMIT {$limit}";
        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    public static function list_runs($limit = 80) {
        global $wpdb;
        $limit = max(1, min(300, absint($limit)));
        return (array) $wpdb->get_results(
            'SELECT * FROM ' . self::table('runs') . ' ORDER BY id DESC LIMIT ' . $limit,
            ARRAY_A
        );
    }

    public static function market_summary() {
        global $wpdb;
        $products = self::table('products');
        $offers = self::table('offers');
        $history = self::table('history');
        $runs = self::table('runs');
        $now = self::utc_now();
        return array(
            'products' => absint($wpdb->get_var("SELECT COUNT(*) FROM {$products}")),
            'products_active' => absint($wpdb->get_var("SELECT COUNT(*) FROM {$products} WHERE status='active'")),
            'products_paused' => absint($wpdb->get_var("SELECT COUNT(*) FROM {$products} WHERE status='paused'")),
            'products_compared' => absint($wpdb->get_var("SELECT COUNT(DISTINCT o.object_id) FROM {$offers} o WHERE o.active=1 AND o.source_type<>'provider_catalog' AND o.match_status IN ('confirmed','probable')")),
            'offers' => absint($wpdb->get_var("SELECT COUNT(*) FROM {$offers} WHERE active=1 AND source_type<>'provider_catalog'")),
            'supplier_offers' => absint($wpdb->get_var("SELECT COUNT(*) FROM {$offers} WHERE active=1 AND source_type='provider_catalog'")),
            'import_only' => absint($wpdb->get_var("SELECT COUNT(*) FROM {$offers} WHERE active=1 AND refresh_mode='import_only'")),
            'confirmed' => absint($wpdb->get_var("SELECT COUNT(*) FROM {$offers} WHERE active=1 AND source_type<>'provider_catalog' AND match_status='confirmed'")),
            'review' => absint($wpdb->get_var("SELECT COUNT(*) FROM {$offers} WHERE active=1 AND source_type<>'provider_catalog' AND match_status='review'")),
            'fresh' => absint($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$offers} WHERE active=1 AND source_type<>'provider_catalog' AND (expires_at IS NULL OR expires_at >= %s)", $now))),
            'stale' => absint($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$offers} WHERE active=1 AND source_type<>'provider_catalog' AND expires_at IS NOT NULL AND expires_at < %s", $now))),
            'merchants' => absint($wpdb->get_var("SELECT COUNT(DISTINCT merchant_name) FROM {$offers} WHERE active=1 AND source_type<>'provider_catalog' AND merchant_name<>''")),
            'history_rows' => absint($wpdb->get_var("SELECT COUNT(*) FROM {$history}")),
            'runs' => absint($wpdb->get_var("SELECT COUNT(*) FROM {$runs}")),
        );
    }

    private static function median($values) {
        $count = count($values);
        if (!$count) {
            return null;
        }
        $middle = (int) floor($count / 2);
        if ($count % 2) {
            return (float) $values[$middle];
        }
        return ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
    }

    private static function decimal_or_null($value) {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $value = str_replace(array(' ', ','), array('', '.'), trim($value));
        }
        return is_numeric($value) ? round((float) $value, 6) : null;
    }

    private static function sanitize_mysql_date($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $ts = strtotime($value);
        return $ts ? gmdate('Y-m-d H:i:s', $ts) : '';
    }
}
