<?php
/**
 * Motor de comparación entre Media local y fuentes externas de imagen.
 *
 * El análisis es deliberadamente por lotes. No depende de WP-CLI y evita
 * consultas monolíticas que puedan superar los límites de phpMyAdmin/PHP.
 *
 * @package SEOSystem
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_images_cleanup_table_source_index')) {
    function seo_images_cleanup_table_source_index() {
        global $wpdb;
        return $wpdb->prefix . 'seo_image_cleanup_source_index';
    }
}

if (!function_exists('seo_images_cleanup_table_candidates')) {
    function seo_images_cleanup_table_candidates() {
        global $wpdb;
        return $wpdb->prefix . 'seo_image_cleanup_candidates';
    }
}

if (!function_exists('seo_images_cleanup_table_log')) {
    function seo_images_cleanup_table_log() {
        global $wpdb;
        return $wpdb->prefix . 'seo_image_cleanup_log';
    }
}

if (!function_exists('seo_images_cleanup_install_tables')) {
    /**
     * Crea las tablas auxiliares del módulo. No modifica las tablas canónicas
     * de WordPress ni las de proveedores.
     *
     * @return void
     */
    function seo_images_cleanup_install_tables() {
        global $wpdb;

        $schema_version = '1.0.1';
        if (get_option('seo_images_cleanup_db_version', '') === $schema_version) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $source  = seo_images_cleanup_table_source_index();
        $cand    = seo_images_cleanup_table_candidates();
        $log     = seo_images_cleanup_table_log();

        dbDelta("CREATE TABLE {$source} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_key VARCHAR(64) NOT NULL,
            source_row_key VARCHAR(190) NOT NULL,
            source_label VARCHAR(190) NOT NULL DEFAULT '',
            provider VARCHAR(190) NOT NULL DEFAULT '',
            product_id BIGINT UNSIGNED NULL,
            image_url LONGTEXT NOT NULL,
            canonical_url LONGTEXT NOT NULL,
            canonical_path TEXT NOT NULL,
            basename VARCHAR(255) NOT NULL,
            path_signature TEXT NOT NULL,
            basename_key CHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
            path_key CHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
            origin_key CHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY source_row (source_key, source_row_key),
            KEY basename_key (basename_key),
            KEY path_key (path_key),
            KEY origin_key (origin_key),
            KEY product_id (product_id),
            KEY source_key (source_key)
        ) {$charset};");

        dbDelta("CREATE TABLE {$cand} (
            attachment_id BIGINT UNSIGNED NOT NULL,
            media_filename VARCHAR(255) NOT NULL DEFAULT '',
            media_path_tail VARCHAR(255) NOT NULL DEFAULT '',
            attached_file LONGTEXT NULL,
            media_url LONGTEXT NULL,
            post_parent BIGINT UNSIGNED NOT NULL DEFAULT 0,
            post_title TEXT NULL,
            match_rules VARCHAR(190) NOT NULL DEFAULT '',
            sources TEXT NULL,
            providers TEXT NULL,
            source_rows_matched BIGINT UNSIGNED NOT NULL DEFAULT 0,
            source_products_matched BIGINT UNSIGNED NOT NULL DEFAULT 0,
            source_paths_matched BIGINT UNSIGNED NOT NULL DEFAULT 0,
            has_origin_url_match TINYINT(1) NOT NULL DEFAULT 0,
            has_full_encoded_path_match TINYINT(1) NOT NULL DEFAULT 0,
            matches_product TINYINT(1) NOT NULL DEFAULT 0,
            example_source_url LONGTEXT NULL,
            decision VARCHAR(48) NOT NULL,
            safe_to_delete TINYINT(1) NOT NULL DEFAULT 0,
            analyzed_at DATETIME NOT NULL,
            PRIMARY KEY (attachment_id),
            KEY decision (decision),
            KEY safe_to_delete (safe_to_delete)
        ) {$charset};");

        dbDelta("CREATE TABLE {$log} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            attachment_id BIGINT UNSIGNED NOT NULL,
            decision VARCHAR(48) NOT NULL DEFAULT '',
            media_filename VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(32) NOT NULL,
            gallery_refs_cleaned INT UNSIGNED NOT NULL DEFAULT 0,
            term_refs_cleaned INT UNSIGNED NOT NULL DEFAULT 0,
            seo_rows_cleaned INT UNSIGNED NOT NULL DEFAULT 0,
            message TEXT NULL,
            processed_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY attachment_id (attachment_id),
            KEY status (status),
            KEY processed_at (processed_at)
        ) {$charset};");

        update_option('seo_images_cleanup_db_version', $schema_version, false);
    }
}

if (!function_exists('seo_images_cleanup_percent_encoding_upper')) {
    function seo_images_cleanup_percent_encoding_upper($value) {
        return preg_replace_callback(
            '/%[0-9a-fA-F]{2}/',
            static function ($match) {
                return strtoupper($match[0]);
            },
            (string) $value
        );
    }
}

if (!function_exists('seo_images_cleanup_canonical_url')) {
    /**
     * Canonicaliza una URL para comparar su identidad lógica sin query/fragment.
     * No descarga el recurso ni presume que dos URLs distintas tengan el mismo
     * binario.
     *
     * @param string $url URL.
     * @return string
     */
    function seo_images_cleanup_canonical_url($url) {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'https';
        $host   = strtolower((string) $parts['host']);
        $port   = isset($parts['port']) ? ':' . absint($parts['port']) : '';
        $path   = isset($parts['path']) ? (string) $parts['path'] : '/';
        $path   = seo_images_cleanup_percent_encoding_upper($path);

        return $scheme . '://' . $host . $port . $path;
    }
}

if (!function_exists('seo_images_cleanup_external_signature')) {
    /**
     * Normaliza los componentes que podemos comparar sin tocar el contenido.
     *
     * @param string $url URL externa.
     * @return array{canonical_url:string,canonical_path:string,basename:string,path_signature:string}
     */
    function seo_images_cleanup_external_signature($url) {
        $canonical_url = seo_images_cleanup_canonical_url($url);
        if ($canonical_url === '') {
            return array(
                'canonical_url'  => '',
                'canonical_path' => '',
                'basename'       => '',
                'path_signature' => '',
            );
        }

        $parts = wp_parse_url($canonical_url);
        $path = isset($parts['path']) ? ltrim((string) $parts['path'], '/') : '';
        $path = seo_images_cleanup_percent_encoding_upper($path);

        // Los proveedores como VEVOR codifican / como %2F dentro del path.
        $path_for_basename = str_ireplace('%2F', '/', $path);
        $decoded_for_basename = rawurldecode($path_for_basename);
        $basename = wp_basename($decoded_for_basename);
        $basename = sanitize_file_name((string) $basename);
        $basename = strtolower((string) $basename);

        // Reproduce la huella histórica que WordPress dejó en nombres locales:
        // es%2Fabc%2Ffoto.jpg -> es2fabc2ffoto.jpg.
        $path_signature = strtolower(str_replace('%', '', $path));

        return array(
            'canonical_url'  => $canonical_url,
            'canonical_path' => strtolower($path),
            'basename'       => $basename,
            'path_signature' => $path_signature,
        );
    }
}

if (!function_exists('seo_images_cleanup_media_filename')) {
    function seo_images_cleanup_media_filename($attached_file, $guid) {
        $source = trim((string) $attached_file);
        if ($source === '') {
            $source = (string) $guid;
        }
        $source = preg_replace('/[?#].*$/', '', $source);
        return strtolower((string) wp_basename($source));
    }
}

if (!function_exists('seo_images_cleanup_media_path_tail')) {
    function seo_images_cleanup_media_path_tail($filename) {
        $filename = strtolower((string) $filename);

        // Solo tratamos como ruta codificada los nombres históricos que empiezan
        // por un prefijo de país (es2f, de2f, fr2f...) y contienen varias barras
        // codificadas. Así un nombre normal que incluya accidentalmente "2f" no
        // pierde parte de su basename.
        if (!preg_match('/^[a-z]{2}2f/i', $filename) || substr_count($filename, '2f') < 2) {
            return $filename;
        }

        $pos = strrpos($filename, '2f');
        if ($pos === false) {
            return $filename;
        }
        $tail = substr($filename, $pos + 2);
        return $tail !== '' ? $tail : $filename;
    }
}

if (!function_exists('seo_images_cleanup_state_default')) {
    function seo_images_cleanup_state_default() {
        return array(
            'status'            => 'idle',
            'phase'             => 'idle',
            'source_keys'       => array(),
            'source_index'      => 0,
            'source_cursor'     => 0,
            'source_total'      => 0,
            'source_indexed'    => 0,
            'media_total'       => 0,
            'media_scanned'     => 0,
            'attachment_cursor' => 0,
            'started_at'        => '',
            'updated_at'        => '',
            'completed_at'      => '',
            'last_error'        => '',
        );
    }
}

if (!function_exists('seo_images_cleanup_get_state')) {
    function seo_images_cleanup_get_state() {
        $stored = get_option('seo_images_cleanup_state', array());
        return wp_parse_args(is_array($stored) ? $stored : array(), seo_images_cleanup_state_default());
    }
}

if (!function_exists('seo_images_cleanup_set_state')) {
    function seo_images_cleanup_set_state(array $state) {
        $state['updated_at'] = current_time('mysql');
        update_option('seo_images_cleanup_state', $state, false);
        return $state;
    }
}

if (!function_exists('seo_images_cleanup_start_audit')) {
    /**
     * Inicializa una auditoría desde cero.
     *
     * @return array|WP_Error
     */
    function seo_images_cleanup_start_audit() {
        global $wpdb;

        seo_images_cleanup_install_tables();

        $sources = seo_images_cleanup_external_sources();
        if (empty($sources)) {
            return new WP_Error(
                'seo_images_cleanup_no_sources',
                'No hay ninguna fuente externa de imágenes disponible para comparar con Media.'
            );
        }

        $source_table = seo_images_cleanup_table_source_index();
        $cand_table   = seo_images_cleanup_table_candidates();

        $wpdb->query("TRUNCATE TABLE {$source_table}");
        $wpdb->query("TRUNCATE TABLE {$cand_table}");

        $source_total = 0;
        foreach ($sources as $source) {
            $source_total += seo_images_cleanup_source_count($source);
        }

        if ($source_total < 1) {
            return new WP_Error(
                'seo_images_cleanup_empty_sources',
                'Las fuentes externas están registradas, pero no contienen imágenes utilizables para comparar.'
            );
        }

        $media_total = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_mime_type LIKE 'image/%'"
        );

        $state = seo_images_cleanup_state_default();
        $state['status']         = 'running';
        $state['phase']          = 'indexing_sources';
        $state['source_keys']    = array_keys($sources);
        $state['source_total']   = $source_total;
        $state['media_total']    = $media_total;
        $state['started_at']     = current_time('mysql');

        return seo_images_cleanup_set_state($state);
    }
}

if (!function_exists('seo_images_cleanup_insert_source_rows')) {
    /**
     * Inserta filas de fuente normalizadas en bloques para que catálogos de
     * decenas de miles de imágenes no generen una consulta INSERT por fila.
     *
     * @param array $source Definición de fuente.
     * @param array $rows   Filas.
     * @return int
     */
    function seo_images_cleanup_insert_source_rows(array $source, array $rows) {
        global $wpdb;

        $table = seo_images_cleanup_table_source_index();
        $normalized = array();

        foreach ($rows as $row) {
            $url = isset($row['image_url']) ? trim((string) $row['image_url']) : '';
            $sig = seo_images_cleanup_external_signature($url);

            if ($url === '' || $sig['basename'] === '' || $sig['canonical_url'] === '') {
                continue;
            }

            $source_row_key = sanitize_text_field((string) ($row['source_row_key'] ?? ''));
            if ($source_row_key === '') {
                $source_row_key = md5($url . '|' . absint($row['product_id'] ?? 0) . '|' . sanitize_text_field((string) ($row['provider'] ?? '')));
            }

            $normalized[] = array(
                (string) $source['key'],
                $source_row_key,
                (string) $source['label'],
                sanitize_text_field((string) ($row['provider'] ?? '')),
                absint($row['product_id'] ?? 0),
                $url,
                $sig['canonical_url'],
                $sig['canonical_path'],
                $sig['basename'],
                $sig['path_signature'],
                md5($sig['basename']),
                md5($sig['path_signature']),
                md5($sig['canonical_url']),
            );
        }

        if (empty($normalized)) {
            return 0;
        }

        $inserted = 0;
        $columns = "source_key, source_row_key, source_label, provider, product_id,
                    image_url, canonical_url, canonical_path, basename, path_signature,
                    basename_key, path_key, origin_key";

        foreach (array_chunk($normalized, 250) as $chunk) {
            $placeholders = array();
            $params = array();

            foreach ($chunk as $values) {
                $placeholders[] = '(%s,%s,%s,%s,%d,%s,%s,%s,%s,%s,%s,%s,%s)';
                foreach ($values as $value) {
                    $params[] = $value;
                }
            }

            $sql = "INSERT IGNORE INTO {$table} ({$columns}) VALUES " . implode(',', $placeholders);
            $result = $wpdb->query($wpdb->prepare($sql, $params));

            if ($result !== false) {
                $inserted += (int) $result;
            }
        }

        return $inserted;
    }
}

if (!function_exists('seo_images_cleanup_audit_source_batch')) {
    function seo_images_cleanup_audit_source_batch(array $state, $limit = 2500) {
        $sources = seo_images_cleanup_external_sources();
        $keys    = isset($state['source_keys']) && is_array($state['source_keys']) ? $state['source_keys'] : array();
        $index   = absint($state['source_index'] ?? 0);

        if ($index >= count($keys)) {
            $state['phase']              = 'scanning_media';
            $state['attachment_cursor']  = 0;
            return seo_images_cleanup_set_state($state);
        }

        $source_key = $keys[$index];
        if (!isset($sources[$source_key])) {
            $state['source_index']  = $index + 1;
            $state['source_cursor'] = 0;
            return seo_images_cleanup_set_state($state);
        }

        $source = $sources[$source_key];
        $batch = seo_images_cleanup_source_rows($source, $state['source_cursor'] ?? 0, $limit);
        $inserted = seo_images_cleanup_insert_source_rows($source, $batch['rows']);

        $state['source_indexed'] = absint($state['source_indexed']) + $inserted;
        $state['source_cursor']  = $batch['cursor'];

        if (!empty($batch['done'])) {
            $state['source_index']  = $index + 1;
            $state['source_cursor'] = 0;

            if ($state['source_index'] >= count($keys)) {
                $state['phase']             = 'scanning_media';
                $state['attachment_cursor'] = 0;
            }
        }

        return seo_images_cleanup_set_state($state);
    }
}

if (!function_exists('seo_images_cleanup_sql_in_strings')) {
    /**
     * Genera placeholders y parámetros para una lista de strings.
     *
     * @param array $values Valores.
     * @return array{sql:string,params:array}
     */
    function seo_images_cleanup_sql_in_strings(array $values) {
        $values = array_values(array_unique(array_filter(array_map('strval', $values))));
        if (empty($values)) {
            return array('sql' => '', 'params' => array());
        }
        return array(
            'sql'    => implode(',', array_fill(0, count($values), '%s')),
            'params' => $values,
        );
    }
}

if (!function_exists('seo_images_cleanup_source_rows_by_key')) {
    /**
     * Recupera del índice las filas que casan con un conjunto de hashes.
     *
     * @param string $column Columna permitida.
     * @param array  $keys   Hashes MD5.
     * @return array
     */
    function seo_images_cleanup_source_rows_by_key($column, array $keys) {
        global $wpdb;

        $allowed = array('basename_key', 'path_key', 'origin_key');
        if (!in_array($column, $allowed, true)) {
            return array();
        }

        $in = seo_images_cleanup_sql_in_strings($keys);
        if ($in['sql'] === '') {
            return array();
        }

        $table = seo_images_cleanup_table_source_index();
        $sql = "SELECT * FROM {$table} WHERE {$column} IN ({$in['sql']})";
        return (array) $wpdb->get_results($wpdb->prepare($sql, $in['params']), ARRAY_A);
    }
}

if (!function_exists('seo_images_cleanup_collect_media_context')) {
    /**
     * Obtiene orígenes y usos SEO de un lote de attachments con consultas
     * indexadas, evitando una consulta por imagen.
     *
     * @param array $attachment_ids IDs.
     * @return array{origins:array,products:array}
     */
    function seo_images_cleanup_collect_media_context(array $attachment_ids) {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('absint', $attachment_ids))));
        $origins  = array();
        $products = array();

        if (empty($ids)) {
            return compact('origins', 'products');
        }

        $id_sql = implode(',', $ids);

        // Metadato canónico guardado por SEO Images.
        $meta_rows = (array) $wpdb->get_results(
            "SELECT post_id, meta_value
             FROM {$wpdb->postmeta}
             WHERE post_id IN ({$id_sql})
               AND meta_key = '_seo_url_origen'
               AND meta_value <> ''",
            ARRAY_A
        );
        foreach ($meta_rows as $row) {
            $id = absint($row['post_id']);
            $url = seo_images_cleanup_canonical_url($row['meta_value']);
            if ($id && $url !== '') {
                $origins[$id][$url] = $url;
            }
        }

        // Índice SEO histórico: puede conservar más de un origen por attachment.
        if (function_exists('seo_images_table_images')) {
            $media_table = seo_images_table_images();
            if (function_exists('seo_images_table_exists') && seo_images_table_exists($media_table)) {
                $rows = (array) $wpdb->get_results(
                    "SELECT attachment_id, url_origen
                     FROM {$media_table}
                     WHERE attachment_id IN ({$id_sql})
                       AND url_origen <> ''",
                    ARRAY_A
                );
                foreach ($rows as $row) {
                    $id = absint($row['attachment_id']);
                    $url = seo_images_cleanup_canonical_url($row['url_origen']);
                    if ($id && $url !== '') {
                        $origins[$id][$url] = $url;
                    }
                }
            }
        }

        // Relaciones registradas por el propio módulo de imágenes.
        if (function_exists('seo_images_table_usages')) {
            $usage_table = seo_images_table_usages();
            if (function_exists('seo_images_table_exists') && seo_images_table_exists($usage_table)) {
                $rows = (array) $wpdb->get_results(
                    "SELECT attachment_id, object_id, object_type
                     FROM {$usage_table}
                     WHERE attachment_id IN ({$id_sql})
                       AND object_id > 0",
                    ARRAY_A
                );
                foreach ($rows as $row) {
                    $id = absint($row['attachment_id']);
                    $object_id = absint($row['object_id']);
                    $object_type = sanitize_key($row['object_type']);
                    if ($id && $object_id && in_array($object_type, array('product', 'post', ''), true)) {
                        $products[$id][$object_id] = $object_id;
                    }
                }
            }
        }

        foreach ($origins as $id => $values) {
            $origins[$id] = array_values($values);
        }
        foreach ($products as $id => $values) {
            $products[$id] = array_values($values);
        }

        return compact('origins', 'products');
    }
}

if (!function_exists('seo_images_cleanup_index_source_maps')) {
    function seo_images_cleanup_index_source_maps(array $rows, $column) {
        $map = array();
        foreach ($rows as $row) {
            if (!isset($row[$column])) {
                continue;
            }
            $map[(string) $row[$column]][] = $row;
        }
        return $map;
    }
}

if (!function_exists('seo_images_cleanup_save_candidate')) {
    function seo_images_cleanup_save_candidate(array $data) {
        global $wpdb;
        $table = seo_images_cleanup_table_candidates();

        return $wpdb->replace(
            $table,
            array(
                'attachment_id'               => absint($data['attachment_id']),
                'media_filename'               => (string) $data['media_filename'],
                'media_path_tail'              => (string) $data['media_path_tail'],
                'attached_file'                => (string) $data['attached_file'],
                'media_url'                    => (string) $data['media_url'],
                'post_parent'                  => absint($data['post_parent']),
                'post_title'                   => (string) $data['post_title'],
                'match_rules'                  => (string) $data['match_rules'],
                'sources'                      => (string) $data['sources'],
                'providers'                    => (string) $data['providers'],
                'source_rows_matched'          => absint($data['source_rows_matched']),
                'source_products_matched'      => absint($data['source_products_matched']),
                'source_paths_matched'         => absint($data['source_paths_matched']),
                'has_origin_url_match'         => empty($data['has_origin_url_match']) ? 0 : 1,
                'has_full_encoded_path_match'  => empty($data['has_full_encoded_path_match']) ? 0 : 1,
                'matches_product'              => empty($data['matches_product']) ? 0 : 1,
                'example_source_url'           => (string) $data['example_source_url'],
                'decision'                     => (string) $data['decision'],
                'safe_to_delete'               => empty($data['safe_to_delete']) ? 0 : 1,
                'analyzed_at'                  => current_time('mysql'),
            ),
            array('%d','%s','%s','%s','%s','%d','%s','%s','%s','%s','%d','%d','%d','%d','%d','%d','%s','%s','%d','%s')
        );
    }
}

if (!function_exists('seo_images_cleanup_release_source_index')) {
    /**
     * El índice de fuentes solo es necesario durante la comparación. Se vacía
     * al terminar para no dejar decenas de miles de URLs duplicadas ocupando BD.
     *
     * @return void
     */
    function seo_images_cleanup_release_source_index() {
        global $wpdb;
        $table = seo_images_cleanup_table_source_index();
        $wpdb->query("TRUNCATE TABLE {$table}");
    }
}

if (!function_exists('seo_images_cleanup_audit_media_batch')) {
    /**
     * Compara un lote de Media con el índice de fuentes.
     *
     * @param array $state Estado.
     * @param int   $limit Lote.
     * @return array
     */
    function seo_images_cleanup_audit_media_batch(array $state, $limit = 250) {
        global $wpdb;

        $limit  = max(25, min(500, absint($limit)));
        $cursor = absint($state['attachment_cursor'] ?? 0);

        $media_rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_parent, post_title, guid
                 FROM {$wpdb->posts}
                 WHERE post_type = 'attachment'
                   AND post_mime_type LIKE 'image/%%'
                   AND ID > %d
                 ORDER BY ID ASC
                 LIMIT %d",
                $cursor,
                $limit
            ),
            ARRAY_A
        );

        if (empty($media_rows)) {
            seo_images_cleanup_release_source_index();
            $state['phase']        = 'complete';
            $state['status']       = 'complete';
            $state['completed_at'] = current_time('mysql');
            return seo_images_cleanup_set_state($state);
        }

        $ids = array_map('absint', wp_list_pluck($media_rows, 'ID'));
        $id_sql = implode(',', $ids);
        $attached_map = array();

        $attached_rows = (array) $wpdb->get_results(
            "SELECT post_id, meta_value
             FROM {$wpdb->postmeta}
             WHERE post_id IN ({$id_sql})
               AND meta_key = '_wp_attached_file'",
            ARRAY_A
        );
        foreach ($attached_rows as $row) {
            $attached_map[absint($row['post_id'])] = (string) $row['meta_value'];
        }

        $context = seo_images_cleanup_collect_media_context($ids);

        $media_signatures = array();
        $basename_keys = array();
        $path_keys     = array();
        $origin_keys   = array();

        foreach ($media_rows as $row) {
            $id = absint($row['ID']);
            $attached = $attached_map[$id] ?? '';
            $filename = seo_images_cleanup_media_filename($attached, $row['guid']);
            $tail     = seo_images_cleanup_media_path_tail($filename);
            $origins  = $context['origins'][$id] ?? array();

            $media_signatures[$id] = array(
                'filename' => $filename,
                'tail'     => $tail,
                'origins'  => $origins,
            );

            if ($tail !== '') {
                $basename_keys[] = md5($tail);
            }
            if ($filename !== '') {
                $path_keys[] = md5($filename);
            }
            foreach ($origins as $origin) {
                $origin_keys[] = md5($origin);
            }
        }

        $basename_rows = seo_images_cleanup_source_rows_by_key('basename_key', $basename_keys);
        $path_rows     = seo_images_cleanup_source_rows_by_key('path_key', $path_keys);
        $origin_rows   = seo_images_cleanup_source_rows_by_key('origin_key', $origin_keys);

        $basename_map = seo_images_cleanup_index_source_maps($basename_rows, 'basename_key');
        $path_map     = seo_images_cleanup_index_source_maps($path_rows, 'path_key');
        $origin_map   = seo_images_cleanup_index_source_maps($origin_rows, 'origin_key');

        foreach ($media_rows as $row) {
            $id = absint($row['ID']);
            $sig = $media_signatures[$id];
            $filename = $sig['filename'];
            $tail = $sig['tail'];

            $matched = array();
            $rules = array();
            $has_origin = false;
            $has_path   = false;

            if ($tail !== '') {
                foreach ($basename_map[md5($tail)] ?? array() as $source_row) {
                    if ((string) $source_row['basename'] === $tail) {
                        $matched[$source_row['id']] = $source_row;
                        $rules['TAIL_FILENAME'] = 'TAIL_FILENAME';
                    }
                }
            }

            if ($filename !== '') {
                foreach ($path_map[md5($filename)] ?? array() as $source_row) {
                    if ((string) $source_row['path_signature'] === $filename) {
                        $matched[$source_row['id']] = $source_row;
                        $rules['FULL_ENCODED_PATH'] = 'FULL_ENCODED_PATH';
                        $has_path = true;
                    }
                }
            }

            foreach ($sig['origins'] as $origin) {
                foreach ($origin_map[md5($origin)] ?? array() as $source_row) {
                    if ((string) $source_row['canonical_url'] === $origin) {
                        $matched[$source_row['id']] = $source_row;
                        $rules['ORIGIN_URL'] = 'ORIGIN_URL';
                        $has_origin = true;
                    }
                }
            }

            if (empty($matched)) {
                continue;
            }

            $source_keys = array();
            $providers   = array();
            $products    = array();
            $paths       = array();
            $example_url = '';
            $product_refs = array();

            $post_parent = absint($row['post_parent']);
            if ($post_parent > 0) {
                $product_refs[$post_parent] = $post_parent;
            }
            foreach ($context['products'][$id] ?? array() as $product_id) {
                $product_id = absint($product_id);
                if ($product_id > 0) {
                    $product_refs[$product_id] = $product_id;
                }
            }

            $matches_product = false;

            foreach ($matched as $source_row) {
                $source_keys[(string) $source_row['source_key']] = (string) $source_row['source_label'];
                $provider = trim((string) $source_row['provider']);
                if ($provider !== '') {
                    $providers[$provider] = $provider;
                }
                $product_id = absint($source_row['product_id']);
                if ($product_id > 0) {
                    $products[$product_id] = $product_id;
                    if (isset($product_refs[$product_id])) {
                        $matches_product = true;
                    }
                }
                $path = (string) $source_row['canonical_path'];
                if ($path !== '') {
                    $paths[$path] = $path;
                }
                if ($example_url === '') {
                    $example_url = (string) $source_row['image_url'];
                }
            }

            if ($has_origin) {
                $decision = 'BORRAR_URL_ORIGEN_EXACTA';
                $safe = 1;
            } elseif ($has_path) {
                $decision = 'BORRAR_RUTA_EXACTA';
                $safe = 1;
            } elseif ($matches_product) {
                $decision = 'BORRAR_MISMO_PRODUCTO';
                $safe = 1;
            } elseif (count($paths) === 1) {
                $decision = 'BORRAR_RUTA_PROVEEDOR_UNICA';
                $safe = 0;
            } else {
                $decision = 'REVISAR_NOMBRE_COMPARTIDO';
                $safe = 0;
            }

            seo_images_cleanup_save_candidate(array(
                'attachment_id'              => $id,
                'media_filename'              => $filename,
                'media_path_tail'             => $tail,
                'attached_file'               => $attached_map[$id] ?? '',
                'media_url'                   => $row['guid'],
                'post_parent'                 => $post_parent,
                'post_title'                  => $row['post_title'],
                'match_rules'                 => implode(',', array_values($rules)),
                'sources'                     => implode(', ', array_values($source_keys)),
                'providers'                   => implode(', ', array_values($providers)),
                'source_rows_matched'         => count($matched),
                'source_products_matched'     => count($products),
                'source_paths_matched'        => count($paths),
                'has_origin_url_match'        => $has_origin,
                'has_full_encoded_path_match' => $has_path,
                'matches_product'             => $matches_product,
                'example_source_url'          => $example_url,
                'decision'                    => $decision,
                'safe_to_delete'              => $safe,
            ));
        }

        $state['attachment_cursor'] = max($ids);
        $state['media_scanned'] = absint($state['media_scanned']) + count($media_rows);

        if (count($media_rows) < $limit) {
            seo_images_cleanup_release_source_index();
            $state['phase']        = 'complete';
            $state['status']       = 'complete';
            $state['completed_at'] = current_time('mysql');
        }

        return seo_images_cleanup_set_state($state);
    }
}

if (!function_exists('seo_images_cleanup_run_audit_batch')) {
    /**
     * Ejecuta el siguiente lote según la fase actual.
     *
     * @return array|WP_Error
     */
    function seo_images_cleanup_run_audit_batch() {
        $state = seo_images_cleanup_get_state();

        if (($state['status'] ?? '') !== 'running') {
            return $state;
        }

        try {
            if (($state['phase'] ?? '') === 'indexing_sources') {
                return seo_images_cleanup_audit_source_batch($state, 2500);
            }
            if (($state['phase'] ?? '') === 'scanning_media') {
                return seo_images_cleanup_audit_media_batch($state, 250);
            }
            if (($state['phase'] ?? '') === 'complete') {
                $state['status'] = 'complete';
                return seo_images_cleanup_set_state($state);
            }
        } catch (Throwable $e) {
            $state['status']     = 'error';
            $state['phase']      = 'error';
            $state['last_error'] = $e->getMessage();
            seo_images_cleanup_set_state($state);
            return new WP_Error('seo_images_cleanup_audit_error', $e->getMessage());
        }

        return new WP_Error('seo_images_cleanup_invalid_state', 'Estado de auditoría no reconocido.');
    }
}

if (!function_exists('seo_images_cleanup_candidate_stats')) {
    function seo_images_cleanup_candidate_stats() {
        global $wpdb;

        seo_images_cleanup_install_tables();
        $table = seo_images_cleanup_table_candidates();

        $stats = array(
            'total'                        => 0,
            'safe'                         => 0,
            'BORRAR_URL_ORIGEN_EXACTA'     => 0,
            'BORRAR_RUTA_EXACTA'           => 0,
            'BORRAR_MISMO_PRODUCTO'        => 0,
            'BORRAR_RUTA_PROVEEDOR_UNICA'  => 0,
            'REVISAR_NOMBRE_COMPARTIDO'    => 0,
        );

        $rows = (array) $wpdb->get_results(
            "SELECT decision, safe_to_delete, COUNT(*) AS total
             FROM {$table}
             GROUP BY decision, safe_to_delete",
            ARRAY_A
        );

        foreach ($rows as $row) {
            $decision = (string) $row['decision'];
            $count = absint($row['total']);
            $stats['total'] += $count;
            if (!empty($row['safe_to_delete'])) {
                $stats['safe'] += $count;
            }
            if (array_key_exists($decision, $stats)) {
                $stats[$decision] += $count;
            }
        }

        return $stats;
    }
}

if (!function_exists('seo_images_cleanup_candidate_rows')) {
    function seo_images_cleanup_candidate_rows($limit = 50, $safe_only = false) {
        global $wpdb;

        $limit = max(1, min(200, absint($limit)));
        $table = seo_images_cleanup_table_candidates();
        $where = $safe_only ? 'WHERE safe_to_delete = 1' : '';

        return (array) $wpdb->get_results(
            "SELECT * FROM {$table}
             {$where}
             ORDER BY safe_to_delete DESC,
                      CASE decision
                        WHEN 'BORRAR_URL_ORIGEN_EXACTA' THEN 1
                        WHEN 'BORRAR_RUTA_EXACTA' THEN 2
                        WHEN 'BORRAR_MISMO_PRODUCTO' THEN 3
                        WHEN 'BORRAR_RUTA_PROVEEDOR_UNICA' THEN 4
                        ELSE 9
                      END,
                      attachment_id ASC
             LIMIT {$limit}",
            ARRAY_A
        );
    }
}
