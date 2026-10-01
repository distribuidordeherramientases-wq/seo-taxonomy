<?php
/**
 * Comparador - persistencia v1.0.
 */

defined('ABSPATH') || exit;

final class SEO_Comparador_DB {
    const VERSION_OPTION = 'seo_comparador_db_version';
    const DB_VERSION = '1.1.0';

    public static function table($name) {
        global $wpdb;
        $map = array(
            'profiles'  => $wpdb->prefix . 'seo_comparador_profiles',
            'axes'      => $wpdb->prefix . 'seo_comparador_axes',
            'products'  => $wpdb->prefix . 'seo_comparador_products',
            'values'    => $wpdb->prefix . 'seo_comparador_values',
            'editorial' => $wpdb->prefix . 'seo_comparador_editorial',
            'post_map'  => $wpdb->prefix . 'seo_comparador_post_map',
            'workflow'  => $wpdb->prefix . 'seo_comparador_workflow',
        );
        return isset($map[$name]) ? $map[$name] : '';
    }

    public static function table_exists($table) {
        global $wpdb;
        $table = (string) $table;
        if ($table === '') return false;
        return (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
        ) === $table;
    }

    public static function tables_exist() {
        foreach (array('profiles','axes','products','values','editorial','post_map','workflow') as $name) {
            if (!self::table_exists(self::table($name))) return false;
        }
        return true;
    }

    public static function maybe_install() {
        $installed = (string) get_option(self::VERSION_OPTION, '0');
        if (version_compare($installed, self::DB_VERSION, '>=') && self::tables_exist()) return true;
        return self::install();
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();

        $profiles = self::table('profiles');
        $axes = self::table('axes');
        $products = self::table('products');
        $values = self::table('values');
        $editorial = self::table('editorial');
        $post_map = self::table('post_map');
        $workflow = self::table('workflow');

        dbDelta("CREATE TABLE {$profiles} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            canonical_key varchar(191) NOT NULL,
            canonical_name varchar(255) NOT NULL DEFAULT '',
            category_ids longtext NULL,
            primary_category_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(40) NOT NULL DEFAULT 'detected',
            own_products_count int(10) unsigned NOT NULL DEFAULT 0,
            external_products_seen int(10) unsigned NOT NULL DEFAULT 0,
            external_products_comparable int(10) unsigned NOT NULL DEFAULT 0,
            comparison_axes_count int(10) unsigned NOT NULL DEFAULT 0,
            confidence decimal(7,4) NOT NULL DEFAULT 0,
            source_snapshot_at datetime NULL,
            generated_at datetime NULL,
            source_hash char(64) NOT NULL DEFAULT '',
            last_error text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY canonical_key (canonical_key),
            KEY primary_category_id (primary_category_id),
            KEY status (status),
            KEY updated_at (updated_at)
        ) {$collate};");

        dbDelta("CREATE TABLE {$axes} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            profile_id bigint(20) unsigned NOT NULL,
            axis_key varchar(191) NOT NULL,
            label varchar(255) NOT NULL DEFAULT '',
            unit varchar(32) NOT NULL DEFAULT '',
            priority int(10) unsigned NOT NULL DEFAULT 50,
            coverage decimal(7,4) NOT NULL DEFAULT 0,
            min_confidence decimal(7,4) NOT NULL DEFAULT 0.6,
            publishable tinyint(1) unsigned NOT NULL DEFAULT 0,
            manual_override tinyint(1) unsigned NOT NULL DEFAULT 0,
            source varchar(32) NOT NULL DEFAULT 'auto',
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY profile_axis (profile_id,axis_key),
            KEY profile_publishable (profile_id,publishable),
            KEY priority (priority)
        ) {$collate};");

        dbDelta("CREATE TABLE {$products} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            profile_id bigint(20) unsigned NOT NULL,
            source_type varchar(24) NOT NULL,
            source_id varchar(191) NOT NULL DEFAULT '',
            own_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
            brand varchar(191) NOT NULL DEFAULT '',
            model varchar(191) NOT NULL DEFAULT '',
            title text NULL,
            representative tinyint(1) unsigned NOT NULL DEFAULT 0,
            dedupe_key varchar(191) NOT NULL,
            source_snapshot_at datetime NULL,
            raw_meta longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY profile_product (profile_id,source_type,dedupe_key),
            KEY own_product_id (own_product_id),
            KEY source_type (source_type)
        ) {$collate};");

        dbDelta("CREATE TABLE {$values} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            comparison_product_id bigint(20) unsigned NOT NULL,
            axis_key varchar(191) NOT NULL,
            raw_value text NULL,
            normalized_value varchar(500) NOT NULL DEFAULT '',
            unit varchar(32) NOT NULL DEFAULT '',
            confidence decimal(7,4) NOT NULL DEFAULT 0,
            verification_status varchar(32) NOT NULL DEFAULT 'unknown',
            source varchar(64) NOT NULL DEFAULT '',
            source_ref text NULL,
            observed_at datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY product_axis (comparison_product_id,axis_key),
            KEY axis_key (axis_key),
            KEY verification_status (verification_status)
        ) {$collate};");

        dbDelta("CREATE TABLE {$editorial} (
            profile_id bigint(20) unsigned NOT NULL,
            summary longtext NULL,
            suggested_title text NULL,
            excerpt text NULL,
            comparison_text longtext NULL,
            product_types longtext NULL,
            main_differences longtext NULL,
            buying_criteria longtext NULL,
            use_cases longtext NULL,
            market_overview longtext NULL,
            own_catalog_position longtext NULL,
            limits longtext NULL,
            editorial_limitations longtext NULL,
            conclusion longtext NULL,
            origin varchar(32) NOT NULL DEFAULT 'generated',
            source_hash_at_edit char(64) NOT NULL DEFAULT '',
            source_snapshot_at_edit datetime NULL,
            import_meta longtext NULL,
            version int(10) unsigned NOT NULL DEFAULT 1,
            generated_at datetime NULL,
            imported_at datetime NULL,
            imported_by bigint(20) unsigned NOT NULL DEFAULT 0,
            reviewed_at datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (profile_id)
        ) {$collate};");

        dbDelta("CREATE TABLE {$post_map} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            profile_id bigint(20) unsigned NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            relationship_type varchar(32) NOT NULL DEFAULT 'canonical',
            status varchar(32) NOT NULL DEFAULT 'linked',
            published_at datetime NULL,
            last_synced_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY profile_post (profile_id,post_id),
            KEY post_id (post_id),
            KEY status (status)
        ) {$collate};");

        dbDelta("CREATE TABLE {$workflow} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            profile_id bigint(20) unsigned NOT NULL,
            from_state varchar(40) NULL,
            to_state varchar(40) NOT NULL,
            action_code varchar(64) NOT NULL DEFAULT '',
            reason text NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            origin varchar(32) NOT NULL DEFAULT 'system',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY profile_id (profile_id),
            KEY to_state (to_state),
            KEY created_at (created_at)
        ) {$collate};");

        update_option(self::VERSION_OPTION, self::DB_VERSION, false);
        return self::tables_exist();
    }

    public static function decode_json($value, $default = array()) {
        if (is_array($value)) return $value;
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : $default;
    }

    public static function get_profile($profile_id) {
        global $wpdb;
        return (array) $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table('profiles') . ' WHERE id=%d LIMIT 1', absint($profile_id)),
            ARRAY_A
        );
    }

    public static function profile_for_category($term_id) {
        global $wpdb;
        $term_id = absint($term_id);
        if (!$term_id) return array();
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::table('profiles') . " WHERE primary_category_id=%d OR category_ids LIKE %s ORDER BY updated_at DESC,id DESC",
                $term_id,
                '%' . $wpdb->esc_like((string) $term_id) . '%'
            ),
            ARRAY_A
        );
        foreach ($rows as $row) {
            $ids = array_map('absint', (array) self::decode_json($row['category_ids'] ?? '[]'));
            if (absint($row['primary_category_id'] ?? 0) === $term_id || in_array($term_id, $ids, true)) return $row;
        }
        return array();
    }

    public static function list_profiles($limit = 500) {
        global $wpdb;
        $limit = max(1, min(2000, absint($limit)));
        return (array) $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . self::table('profiles') . ' ORDER BY updated_at DESC,id DESC LIMIT %d', $limit),
            ARRAY_A
        );
    }

    public static function axes($profile_id) {
        global $wpdb;
        return (array) $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . self::table('axes') . ' WHERE profile_id=%d ORDER BY priority DESC,label ASC', absint($profile_id)),
            ARRAY_A
        );
    }

    public static function products($profile_id, $source_type = '') {
        global $wpdb;
        $sql = 'SELECT * FROM ' . self::table('products') . ' WHERE profile_id=%d';
        $params = array(absint($profile_id));
        if ($source_type !== '') {
            $sql .= ' AND source_type=%s';
            $params[] = sanitize_key($source_type);
        }
        $sql .= ' ORDER BY representative DESC,id ASC';
        return (array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }

    public static function values_for_profile($profile_id) {
        global $wpdb;
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT v.*,p.source_type,p.source_id,p.own_product_id,p.brand,p.model,p.title
                 FROM ' . self::table('values') . ' v
                 JOIN ' . self::table('products') . ' p ON p.id=v.comparison_product_id
                 WHERE p.profile_id=%d
                 ORDER BY v.axis_key,p.source_type,p.id',
                absint($profile_id)
            ),
            ARRAY_A
        );
    }

    public static function editorial($profile_id) {
        global $wpdb;
        return (array) $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table('editorial') . ' WHERE profile_id=%d LIMIT 1', absint($profile_id)),
            ARRAY_A
        );
    }

    public static function post_map($profile_id) {
        global $wpdb;
        return (array) $wpdb->get_row(
            $wpdb->prepare(
                "SELECT m.*,p.post_title,p.post_status
                 FROM " . self::table('post_map') . " m
                 LEFT JOIN {$wpdb->posts} p ON p.ID=m.post_id
                 WHERE m.profile_id=%d AND m.relationship_type='canonical'
                 ORDER BY m.id DESC LIMIT 1",
                absint($profile_id)
            ),
            ARRAY_A
        );
    }

    public static function update_status($profile_id, $to_state, $action = '', $reason = '', $origin = 'system') {
        global $wpdb;
        $profile = self::get_profile($profile_id);
        if (!$profile) return false;
        $from = sanitize_key((string) ($profile['status'] ?? 'detected'));
        $to = sanitize_key((string) $to_state) ?: $from;
        $wpdb->update(self::table('profiles'), array(
            'status'=>$to,
            'updated_at'=>current_time('mysql'),
        ), array('id'=>absint($profile_id)));
        $wpdb->insert(self::table('workflow'), array(
            'profile_id'=>absint($profile_id),
            'from_state'=>$from,
            'to_state'=>$to,
            'action_code'=>sanitize_key((string) $action),
            'reason'=>sanitize_textarea_field((string) $reason),
            'user_id'=>get_current_user_id(),
            'origin'=>sanitize_key((string) $origin) ?: 'system',
            'created_at'=>current_time('mysql'),
        ));
        return true;
    }

    public static function register_data_layer($tables) {
        $tables = is_array($tables) ? $tables : array();
        foreach (array('profiles','axes','products','values','editorial','post_map','workflow') as $name) {
            $tables['comparador_' . $name] = array(
                'table'=>self::table($name),
                'primary_key'=>array($name === 'editorial' ? 'profile_id' : 'id'),
                'entity_type'=>'comparison_' . $name,
            );
        }
        return $tables;
    }
}
