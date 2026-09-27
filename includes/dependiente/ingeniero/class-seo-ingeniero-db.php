<?php
/**
 * Persistencia del Ingeniero de Dependiente.
 *
 * Guarda únicamente fuentes, metadatos y conocimiento resumido. Nunca copia
 * páginas, manuales o artículos completos.
 */
defined('ABSPATH') || exit;

final class SEO_Ingeniero_DB {
    const OPTION_DB_VERSION = 'seo_ingeniero_db_version';
    const DB_VERSION = '0.1.0';

    public static function table($name) {
        global $wpdb;
        $map = array(
            'sources'   => $wpdb->prefix . 'seo_ingeniero_sources',
            'knowledge' => $wpdb->prefix . 'seo_ingeniero_knowledge',
        );
        return isset($map[$name]) ? $map[$name] : '';
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
        foreach (array('sources','knowledge') as $name) {
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
        $sources = self::table('sources');
        $knowledge = self::table('knowledge');

        dbDelta("CREATE TABLE {$sources} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            term_id bigint(20) unsigned NOT NULL,
            lesson varchar(64) NOT NULL DEFAULT 'l1_technical',
            url text NOT NULL,
            url_hash char(64) NOT NULL,
            domain varchar(191) NOT NULL DEFAULT '',
            title text NULL,
            source_type varchar(64) NOT NULL DEFAULT 'web',
            trust_level varchar(32) NOT NULL DEFAULT 'medium',
            published_at datetime NULL,
            retrieved_at datetime NULL,
            http_status int(10) unsigned NOT NULL DEFAULT 0,
            content_hash char(64) NOT NULL DEFAULT '',
            status varchar(32) NOT NULL DEFAULT 'new',
            metadata_json longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY term_lesson_url (term_id,lesson,url_hash),
            KEY term_lesson (term_id,lesson),
            KEY source_type (source_type),
            KEY trust_level (trust_level),
            KEY status (status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$knowledge} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            term_id bigint(20) unsigned NOT NULL,
            lesson varchar(64) NOT NULL DEFAULT 'l1_technical',
            knowledge_type varchar(64) NOT NULL,
            concept varchar(255) NOT NULL DEFAULT '',
            summary text NULL,
            facts_json longtext NULL,
            tags_json longtext NULL,
            source_ids_json longtext NULL,
            confidence decimal(6,5) NOT NULL DEFAULT 0,
            status varchar(32) NOT NULL DEFAULT 'review',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY term_lesson_type (term_id,lesson,knowledge_type),
            KEY term_lesson (term_id,lesson),
            KEY status (status),
            KEY confidence (confidence)
        ) {$charset};");

        update_option(self::OPTION_DB_VERSION, self::DB_VERSION, false);
    }

    public static function upsert_source($data) {
        global $wpdb;
        $table = self::table('sources');
        $url = esc_url_raw((string) ($data['url'] ?? ''));
        $term_id = absint($data['term_id'] ?? 0);
        $lesson = sanitize_key((string) ($data['lesson'] ?? 'l1_technical'));
        if (!$term_id || $url === '') {
            return new WP_Error('ingeniero_source_invalid', 'La fuente no tiene categoría o URL válida.');
        }

        $url_hash = hash('sha256', strtolower($url));
        $existing = absint($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE term_id=%d AND lesson=%s AND url_hash=%s LIMIT 1",
            $term_id,
            $lesson,
            $url_hash
        )));

        $row = array(
            'term_id'       => $term_id,
            'lesson'        => $lesson,
            'url'           => $url,
            'url_hash'      => $url_hash,
            'domain'        => sanitize_text_field((string) ($data['domain'] ?? '')),
            'title'         => sanitize_text_field((string) ($data['title'] ?? '')),
            'source_type'   => sanitize_key((string) ($data['source_type'] ?? 'web')),
            'trust_level'   => sanitize_key((string) ($data['trust_level'] ?? 'medium')),
            'published_at'  => self::date_or_null($data['published_at'] ?? null),
            'retrieved_at'  => self::date_or_null($data['retrieved_at'] ?? gmdate('Y-m-d H:i:s')),
            'http_status'   => absint($data['http_status'] ?? 0),
            'content_hash'  => sanitize_text_field((string) ($data['content_hash'] ?? '')),
            'status'        => sanitize_key((string) ($data['status'] ?? 'new')),
            'metadata_json' => wp_json_encode((array) ($data['metadata'] ?? array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at'    => gmdate('Y-m-d H:i:s'),
        );

        if ($existing) {
            $ok = $wpdb->update($table, $row, array('id'=>$existing));
            return false === $ok ? new WP_Error('ingeniero_source_update', $wpdb->last_error ?: 'No se pudo actualizar la fuente.') : $existing;
        }

        $row['created_at'] = gmdate('Y-m-d H:i:s');
        $ok = $wpdb->insert($table, $row);
        return false === $ok ? new WP_Error('ingeniero_source_insert', $wpdb->last_error ?: 'No se pudo guardar la fuente.') : absint($wpdb->insert_id);
    }

    public static function upsert_knowledge($data) {
        global $wpdb;
        $table = self::table('knowledge');
        $term_id = absint($data['term_id'] ?? 0);
        $lesson = sanitize_key((string) ($data['lesson'] ?? 'l1_technical'));
        $type = sanitize_key((string) ($data['knowledge_type'] ?? ''));
        if (!$term_id || !$type) {
            return new WP_Error('ingeniero_knowledge_invalid', 'El conocimiento no tiene categoría o tipo válido.');
        }

        $existing = absint($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE term_id=%d AND lesson=%s AND knowledge_type=%s LIMIT 1",
            $term_id,
            $lesson,
            $type
        )));

        $row = array(
            'term_id'        => $term_id,
            'lesson'         => $lesson,
            'knowledge_type' => $type,
            'concept'        => sanitize_text_field((string) ($data['concept'] ?? '')),
            'summary'        => sanitize_textarea_field((string) ($data['summary'] ?? '')),
            'facts_json'     => wp_json_encode((array) ($data['facts'] ?? array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'tags_json'      => wp_json_encode(array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) ($data['tags'] ?? array()))))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'source_ids_json'=> wp_json_encode(array_values(array_unique(array_filter(array_map('absint', (array) ($data['source_ids'] ?? array()))))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'confidence'     => max(0, min(1, (float) ($data['confidence'] ?? 0))),
            'status'         => sanitize_key((string) ($data['status'] ?? 'review')),
            'updated_at'     => gmdate('Y-m-d H:i:s'),
        );

        if ($existing) {
            $ok = $wpdb->update($table, $row, array('id'=>$existing));
            return false === $ok ? new WP_Error('ingeniero_knowledge_update', $wpdb->last_error ?: 'No se pudo actualizar el conocimiento.') : $existing;
        }

        $row['created_at'] = gmdate('Y-m-d H:i:s');
        $ok = $wpdb->insert($table, $row);
        return false === $ok ? new WP_Error('ingeniero_knowledge_insert', $wpdb->last_error ?: 'No se pudo guardar el conocimiento.') : absint($wpdb->insert_id);
    }

    public static function sources_for_category($term_id, $lesson = 'l1_technical') {
        global $wpdb;
        $table = self::table('sources');
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE term_id=%d AND lesson=%s AND status<>'superseded' ORDER BY trust_level ASC,id DESC",
            absint($term_id),
            sanitize_key($lesson)
        ), ARRAY_A);
    }

    public static function knowledge_for_category($term_id, $only_active = true, $lesson = 'l1_technical') {
        global $wpdb;
        $table = self::table('knowledge');
        $sql = "SELECT * FROM {$table} WHERE term_id=%d AND lesson=%s";
        $args = array(absint($term_id), sanitize_key($lesson));
        if ($only_active) {
            $sql .= " AND status='active'";
        } else {
            $sql .= " AND status<>'superseded'";
        }
        $sql .= ' ORDER BY knowledge_type ASC';
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
        foreach ($rows as &$row) {
            $row['facts'] = self::decode_json($row['facts_json'] ?? '');
            $row['tags'] = self::decode_json($row['tags_json'] ?? '');
            $row['source_ids'] = self::decode_json($row['source_ids_json'] ?? '');
            unset($row['facts_json'], $row['tags_json'], $row['source_ids_json']);
        }
        unset($row);
        return $rows;
    }

    public static function review_knowledge($id, $status) {
        global $wpdb;
        $allowed = array('active','review','rejected');
        $status = sanitize_key((string) $status);
        if (!in_array($status, $allowed, true)) {
            return new WP_Error('ingeniero_review_status', 'Estado de revisión no válido.');
        }
        $ok = $wpdb->update(
            self::table('knowledge'),
            array('status'=>$status,'updated_at'=>gmdate('Y-m-d H:i:s')),
            array('id'=>absint($id))
        );
        return false === $ok ? new WP_Error('ingeniero_review_update', $wpdb->last_error ?: 'No se pudo guardar la revisión.') : true;
    }

    public static function supersede_category($term_id, $lesson = 'l1_technical') {
        global $wpdb;
        $where = array('term_id'=>absint($term_id),'lesson'=>sanitize_key($lesson));
        $wpdb->update(self::table('sources'), array('status'=>'superseded','updated_at'=>gmdate('Y-m-d H:i:s')), $where);
        $wpdb->update(self::table('knowledge'), array('status'=>'superseded','updated_at'=>gmdate('Y-m-d H:i:s')), $where);
    }

    public static function category_stats_map($lesson = 'l1_technical') {
        global $wpdb;
        $lesson = sanitize_key($lesson);
        $sources = self::table('sources');
        $knowledge = self::table('knowledge');
        $out = array();

        $source_rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT term_id,COUNT(*) total FROM {$sources} WHERE lesson=%s AND status<>'superseded' GROUP BY term_id",
            $lesson
        ), ARRAY_A);
        foreach ($source_rows as $row) {
            $out[absint($row['term_id'])]['sources'] = absint($row['total']);
        }

        $knowledge_rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT term_id,COUNT(*) total,AVG(confidence) avg_confidence,SUM(status='active') active_total,SUM(status='review') review_total
             FROM {$knowledge}
             WHERE lesson=%s AND status<>'superseded'
             GROUP BY term_id",
            $lesson
        ), ARRAY_A);
        foreach ($knowledge_rows as $row) {
            $tid = absint($row['term_id']);
            $out[$tid]['knowledge'] = absint($row['total']);
            $out[$tid]['avg_confidence'] = round((float) $row['avg_confidence'], 4);
            $out[$tid]['active'] = absint($row['active_total']);
            $out[$tid]['review'] = absint($row['review_total']);
        }
        return $out;
    }

    public static function totals($lesson = 'l1_technical') {
        global $wpdb;
        $lesson = sanitize_key($lesson);
        $sources = self::table('sources');
        $knowledge = self::table('knowledge');
        return array(
            'sources' => absint($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$sources} WHERE lesson=%s AND status<>'superseded'", $lesson))),
            'knowledge' => absint($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$knowledge} WHERE lesson=%s AND status<>'superseded'", $lesson))),
            'active_knowledge' => absint($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$knowledge} WHERE lesson=%s AND status='active'", $lesson))),
            'review_knowledge' => absint($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$knowledge} WHERE lesson=%s AND status='review'", $lesson))),
            'avg_confidence' => round((float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(AVG(confidence),0) FROM {$knowledge} WHERE lesson=%s AND status<>'superseded'", $lesson)), 4),
        );
    }

    public static function export_payload($term_id = 0) {
        global $wpdb;
        $term_id = absint($term_id);
        $sources = self::table('sources');
        $knowledge = self::table('knowledge');
        if ($term_id) {
            $src = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$sources} WHERE term_id=%d AND status<>'superseded' ORDER BY id ASC", $term_id), ARRAY_A);
            $kn = self::knowledge_for_category($term_id, false);
        } else {
            $src = (array) $wpdb->get_results("SELECT * FROM {$sources} WHERE status<>'superseded' ORDER BY term_id,id ASC", ARRAY_A);
            $kn = (array) $wpdb->get_results("SELECT * FROM {$knowledge} WHERE status<>'superseded' ORDER BY term_id,knowledge_type ASC", ARRAY_A);
        }

        foreach ($src as &$row) {
            $row['metadata'] = self::decode_json($row['metadata_json'] ?? '');
            unset($row['metadata_json']);
        }
        unset($row);
        foreach ($kn as &$row) {
            if (isset($row['facts_json'])) {
                $row['facts'] = self::decode_json($row['facts_json']);
                $row['tags'] = self::decode_json($row['tags_json'] ?? '');
                $row['source_ids'] = self::decode_json($row['source_ids_json'] ?? '');
                unset($row['facts_json'], $row['tags_json'], $row['source_ids_json']);
            }
        }
        unset($row);

        return array(
            'schema' => array('name'=>'seo_ingeniero','version'=>self::DB_VERSION),
            'generated_at_gmt' => gmdate('Y-m-d H:i:s'),
            'term_id' => $term_id,
            'sources' => $src,
            'knowledge' => $kn,
        );
    }

    private static function decode_json($value) {
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : array();
    }

    private static function date_or_null($value) {
        $value = trim((string) $value);
        if ($value === '') return null;
        $ts = strtotime($value);
        return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
    }
}
