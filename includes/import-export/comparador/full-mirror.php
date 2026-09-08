<?php
/**
 * SEO System - Mirror integral del perimetro de datos controlado por Comparador.
 *
 * PRO es siempre origen y STAGING siempre destino, independientemente del
 * WordPress desde el que se pulse el boton. Esta operacion es deliberadamente
 * destructiva en STAGING: crea/actualiza datos de PRO y elimina extras de
 * STAGING dentro del perimetro declarado. No copia la base de datos completa.
 *
 * Tablas propias espejadas completas:
 * - seo_vocabulary, seo_type_role_map
 * - sql_atributos, sql_atributos_terminos, sql_atributos_aliases
 * - sql_product_atributos, seo_object_vocabulary
 * - seo_nodes, seo_relations, seo_faq
 *
 * Tablas WordPress compartidas (solo subconjunto gestionado):
 * - posts/postmeta para product, page y post (imagenes excluidas)
 * - terms/term_taxonomy/termmeta para product_cat, product_tag y post_tag
 * - term_relationships de esas tres taxonomias
 *
 * Las categorias conservan los IDs de PRO para mantener referencias internas.
 * product_tag/post_tag se resuelven por taxonomy+slug y pueden conservar IDs
 * locales distintos. Los posts/productos/paginas se espejan con los IDs de PRO;
 * antes de escribir se bloquea cualquier colision con un post_type ajeno al
 * perimetro.
 *
 * @package SEOSystem
 * @subpackage ImportExport
 * @since 2.3.6
 */

defined('ABSPATH') || exit;

final class SEO_Environment_Full_Mirror {
    const NONCE_ACTION = 'seo_environment_full_mirror';
    const PREVIEW_PREFIX = 'seo_environment_full_mirror_preview_';
    const PREVIEW_TTL = 3600;
    const LOCK_NAME = 'seo_environment_full_mirror_staging';

    private static $post_types = array('product', 'page', 'post');
    private static $taxonomies = array('product_cat', 'product_tag', 'post_tag');

    public static function init() {
        add_action('wp_ajax_seo_environment_full_mirror_preview', array(__CLASS__, 'ajax_preview'));
        add_action('wp_ajax_seo_environment_full_mirror_apply', array(__CLASS__, 'ajax_apply'));
        add_action('admin_init', array(__CLASS__, 'consume_staging_generation'));
    }

    private static function authorize() {
        if (!current_user_can('manage_options')) {
            return new WP_Error('full_mirror_capability', 'Permisos insuficientes.');
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!function_exists('seo_environment_compare_open') || !function_exists('seo_environment_compare_db_prefix')) {
            return new WP_Error('full_mirror_compare', 'No estan disponibles las conexiones del Comparador.');
        }
        if (function_exists('seo_environment_compare_running_entity') && seo_environment_compare_running_entity()) {
            return new WP_Error('full_mirror_busy', 'Hay un escaneo del Comparador en curso. Espera a que termine o detenlo antes de ejecutar el MIRROR.');
        }
        return true;
    }

    private static function preview_key() {
        return self::PREVIEW_PREFIX . get_current_user_id();
    }

    private static function ident($value) {
        $value = (string) $value;
        if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
            return '';
        }
        return '`' . $value . '`';
    }

    private static function rows($mysqli, $sql) {
        $result = @mysqli_query($mysqli, $sql);
        if (false === $result) {
            return new WP_Error('full_mirror_sql_read', trim((string) mysqli_error($mysqli)) ?: 'Error de lectura MySQL.');
        }
        if (true === $result) {
            return array();
        }
        $rows = array();
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
        mysqli_free_result($result);
        return $rows;
    }

    private static function exec($mysqli, $sql, $code = 'full_mirror_sql_write') {
        $ok = @mysqli_query($mysqli, $sql);
        if (false === $ok) {
            return new WP_Error($code, trim((string) mysqli_error($mysqli)) ?: 'Error de escritura MySQL.');
        }
        return true;
    }

    private static function scalar($mysqli, $sql, $field = '') {
        $rows = self::rows($mysqli, $sql);
        if (is_wp_error($rows)) return $rows;
        if (!$rows) return null;
        if ($field && array_key_exists($field, $rows[0])) return $rows[0][$field];
        return reset($rows[0]);
    }

    private static function table_exists($mysqli, $table) {
        $escaped = mysqli_real_escape_string($mysqli, (string) $table);
        $rows = self::rows($mysqli, "SHOW TABLES LIKE '{$escaped}'");
        return !is_wp_error($rows) && !empty($rows);
    }

    private static function table_columns($mysqli, $table) {
        $id = self::ident($table);
        if (!$id) return new WP_Error('full_mirror_table', 'Nombre de tabla no valido.');
        $rows = self::rows($mysqli, "SHOW COLUMNS FROM {$id}");
        if (is_wp_error($rows)) return $rows;
        $columns = array();
        foreach ($rows as $row) {
            $field = (string) ($row['Field'] ?? '');
            if ($field) $columns[] = $field;
        }
        return $columns;
    }

    private static function table_engine($mysqli, $table) {
        $escaped = mysqli_real_escape_string($mysqli, (string) $table);
        $rows = self::rows($mysqli, "SHOW TABLE STATUS LIKE '{$escaped}'");
        if (is_wp_error($rows) || !$rows) return '';
        return strtoupper((string) ($rows[0]['Engine'] ?? ''));
    }

    private static function core_tables($prefix) {
        return array(
            'posts' => $prefix . 'posts',
            'postmeta' => $prefix . 'postmeta',
            'terms' => $prefix . 'terms',
            'term_taxonomy' => $prefix . 'term_taxonomy',
            'term_relationships' => $prefix . 'term_relationships',
            'termmeta' => $prefix . 'termmeta',
            'comments' => $prefix . 'comments',
            'commentmeta' => $prefix . 'commentmeta',
            'options' => $prefix . 'options',
        );
    }

    private static function custom_table_keys() {
        return array(
            'seo_vocabulary',
            'seo_type_role_map',
            'sql_atributos',
            'sql_atributos_terminos',
            'sql_atributos_aliases',
            'sql_product_atributos',
            'seo_object_vocabulary',
            'seo_nodes',
            'seo_relations',
            'seo_faq',
        );
    }

    private static function all_required_tables($prefix) {
        $tables = self::core_tables($prefix);
        foreach (self::custom_table_keys() as $key) {
            $tables[$key] = $prefix . $key;
        }
        return $tables;
    }

    private static function validate_tables($pro, $stg, $pro_tables, $stg_tables) {
        $errors = array();
        foreach ($pro_tables as $key => $table) {
            if (!self::table_exists($pro, $table)) $errors[] = 'PRO no contiene ' . $table . '.';
        }
        foreach ($stg_tables as $key => $table) {
            if (!self::table_exists($stg, $table)) $errors[] = 'STAGING no contiene ' . $table . '.';
        }
        if ($errors) return new WP_Error('full_mirror_tables', implode(' ', array_slice($errors, 0, 10)));
        return true;
    }

    /**
     * Comprueba que la conexion configurada para STAGING puede escribir.
     * Las consultas usan WHERE 1=0, por lo que validan INSERT/UPDATE/DELETE
     * sin modificar ninguna fila. PRO nunca se somete a esta prueba porque
     * el MIRROR no escribe en produccion.
     */
    private static function validate_staging_write_capability($stg, $options_table) {
        $table = self::ident($options_table);
        if (!$table) {
            return new WP_Error('full_mirror_write_table', 'No se pudo validar la tabla options de STAGING.');
        }
        $tests = array(
            "UPDATE {$table} SET option_value=option_value WHERE 1=0",
            "DELETE FROM {$table} WHERE 1=0",
            "INSERT INTO {$table} (option_name,option_value,autoload) SELECT '__seo_full_mirror_privilege_test__','','no' WHERE 1=0",
        );
        foreach ($tests as $sql) {
            $ok = @mysqli_query($stg, $sql);
            if (false === $ok) {
                $error = trim((string) mysqli_error($stg));
                return new WP_Error(
                    'full_mirror_staging_read_only',
                    'La conexion BBDD de STAGING no tiene permisos de escritura suficientes (INSERT/UPDATE/DELETE). ' .
                    'PRO puede seguir usando un usuario de solo lectura, pero STAGING necesita un usuario de escritura para el boton Alinear TODO. ' .
                    ($error ? 'MySQL: ' . $error : '')
                );
            }
        }
        return true;
    }

    private static function validate_distinct_databases($pro, $stg) {
        $pro_db = (string) self::scalar($pro, 'SELECT DATABASE() AS db', 'db');
        $stg_db = (string) self::scalar($stg, 'SELECT DATABASE() AS db', 'db');
        $pro_settings = function_exists('seo_environment_db_settings') ? (array) seo_environment_db_settings('pro') : array();
        $stg_settings = function_exists('seo_environment_db_settings') ? (array) seo_environment_db_settings('staging') : array();
        $pro_host = strtolower(trim((string) ($pro_settings['host'] ?? '')));
        $stg_host = strtolower(trim((string) ($stg_settings['host'] ?? '')));
        $pro_port = absint($pro_settings['port'] ?? 3306);
        $stg_port = absint($stg_settings['port'] ?? 3306);
        if ($pro_db && $stg_db && $pro_db === $stg_db && $pro_host === $stg_host && $pro_port === $stg_port) {
            return new WP_Error('full_mirror_same_database', 'PRO y STAGING apuntan a la misma base de datos. Operacion bloqueada para proteger produccion.');
        }
        return array(
            'pro_database' => $pro_db,
            'staging_database' => $stg_db,
            'pro_site' => (string) ($pro_settings['last_site_url'] ?? ''),
            'staging_site' => (string) ($stg_settings['last_site_url'] ?? ''),
        );
    }

    private static function post_type_sql($mysqli, $alias = '') {
        $prefix = $alias ? $alias . '.' : '';
        $values = array();
        foreach (self::$post_types as $type) {
            $values[] = "'" . mysqli_real_escape_string($mysqli, $type) . "'";
        }
        return $prefix . 'post_type IN (' . implode(',', $values) . ')';
    }

    private static function taxonomy_sql($mysqli, $alias = '') {
        $prefix = $alias ? $alias . '.' : '';
        $values = array();
        foreach (self::$taxonomies as $taxonomy) {
            $values[] = "'" . mysqli_real_escape_string($mysqli, $taxonomy) . "'";
        }
        return $prefix . 'taxonomy IN (' . implode(',', $values) . ')';
    }

    private static function meta_portable_sql($alias = '') {
        $p = $alias ? $alias . '.' : '';
        $parts = array(
            "{$p}meta_key NOT IN ('_edit_lock','_edit_last','_wp_page_template','_wp_old_slug','_wp_old_date','_wp_desired_post_slug','_pingme','_encloseme','_product_attributes')",
            "{$p}meta_key NOT LIKE '_wp_trash_meta_%'",
        );
        foreach (array('thumbnail','image','gallery','imagen','galeria','picture','photo','foto') as $needle) {
            $parts[] = "LOWER({$p}meta_key) NOT LIKE '%" . addslashes($needle) . "%'";
        }
        return '(' . implode(' AND ', $parts) . ')';
    }

    private static function termmeta_portable_sql($alias = '') {
        $p = $alias ? $alias . '.' : '';
        $parts = array();
        foreach (array('thumbnail','image','gallery','imagen','galeria','picture','photo','foto') as $needle) {
            $parts[] = "LOWER({$p}meta_key) NOT LIKE '%" . addslashes($needle) . "%'";
        }
        return '(' . implode(' AND ', $parts) . ')';
    }

    private static function ids($mysqli, $sql, $field) {
        $rows = self::rows($mysqli, $sql);
        if (is_wp_error($rows)) return $rows;
        $ids = array();
        foreach ($rows as $row) {
            $id = absint($row[$field] ?? 0);
            if ($id) $ids[] = $id;
        }
        return array_values(array_unique($ids));
    }

    private static function id_chunks($ids, $size = 1200) {
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
        return $ids ? array_chunk($ids, max(100, absint($size))) : array();
    }

    private static function sql_ids($ids) {
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
        return $ids ? implode(',', $ids) : '0';
    }

    private static function post_maps($mysqli, $table, $source_only = false) {
        $where = self::post_type_sql($mysqli) . ($source_only ? " AND post_status<>'trash'" : '');
        $rows = self::rows($mysqli, "SELECT ID,post_type,post_status,post_title FROM `{$table}` WHERE {$where} ORDER BY ID");
        if (is_wp_error($rows)) return $rows;
        $out = array();
        foreach ($rows as $row) {
            $out[absint($row['ID'])] = array(
                'type' => (string) $row['post_type'],
                'status' => (string) $row['post_status'],
                'title' => (string) $row['post_title'],
            );
        }
        return $out;
    }

    private static function category_rows($mysqli, $tables) {
        return self::rows($mysqli,
            "SELECT t.term_id,t.name,t.slug,t.term_group,tt.term_taxonomy_id,tt.description,tt.parent,tt.count
             FROM `{$tables['terms']}` t
             JOIN `{$tables['term_taxonomy']}` tt ON tt.term_id=t.term_id
             WHERE tt.taxonomy='product_cat'
             ORDER BY t.term_id"
        );
    }

    private static function tag_rows($mysqli, $tables, $taxonomy) {
        $tax = mysqli_real_escape_string($mysqli, sanitize_key($taxonomy));
        return self::rows($mysqli,
            "SELECT t.term_id,t.name,t.slug,t.term_group,tt.term_taxonomy_id,tt.description,tt.parent,tt.count
             FROM `{$tables['terms']}` t
             JOIN `{$tables['term_taxonomy']}` tt ON tt.term_id=t.term_id
             WHERE tt.taxonomy='{$tax}'
             ORDER BY t.slug,t.term_id"
        );
    }

    private static function table_digest($mysqli, $table, $where = '1=1', $columns = array()) {
        if (!$columns) {
            $columns = self::table_columns($mysqli, $table);
            if (is_wp_error($columns)) return $columns;
        }
        $expr = array();
        foreach ($columns as $column) {
            $id = self::ident($column);
            if (!$id) continue;
            $expr[] = "COALESCE(CAST({$id} AS CHAR), '')";
        }
        if (!$expr) return '0:0:0';
        $concat = 'CONCAT_WS(CHAR(31),' . implode(',', $expr) . ')';
        $sql = "SELECT COUNT(*) c,COALESCE(BIT_XOR(CRC32({$concat})),0) x,COALESCE(SUM(CRC32({$concat})),0) s FROM `{$table}` WHERE {$where}";
        $rows = self::rows($mysqli, $sql);
        if (is_wp_error($rows)) return $rows;
        $row = $rows[0] ?? array();
        return (string) ($row['c'] ?? 0) . ':' . (string) ($row['x'] ?? 0) . ':' . (string) ($row['s'] ?? 0);
    }

    private static function environment_marker($mysqli, $tables) {
        $pieces = array();
        $post_where = self::post_type_sql($mysqli) . " AND post_status<>'trash'";
        $digest = self::table_digest($mysqli, $tables['posts'], $post_where, array('ID','post_author','post_date','post_date_gmt','post_content','post_title','post_excerpt','post_status','comment_status','ping_status','post_password','post_name','to_ping','pinged','post_modified','post_modified_gmt','post_parent','guid','menu_order','post_type','post_mime_type','comment_count'));
        if (is_wp_error($digest)) return $digest;
        $pieces['posts'] = $digest;

        $meta_filter = self::meta_portable_sql('pm');
        $pwhere = self::post_type_sql($mysqli, 'p') . " AND p.post_status<>'trash'";
        $sql = "SELECT COUNT(*) c,COALESCE(BIT_XOR(CRC32(CONCAT_WS(CHAR(31),pm.post_id,pm.meta_key,pm.meta_value))),0) x,COALESCE(SUM(CRC32(CONCAT_WS(CHAR(31),pm.post_id,pm.meta_key,pm.meta_value))),0) s
                FROM `{$tables['postmeta']}` pm JOIN `{$tables['posts']}` p ON p.ID=pm.post_id
                WHERE {$pwhere} AND {$meta_filter}";
        $r = self::rows($mysqli, $sql);
        if (is_wp_error($r)) return $r;
        $pieces['postmeta'] = ($r[0]['c'] ?? 0) . ':' . ($r[0]['x'] ?? 0) . ':' . ($r[0]['s'] ?? 0);

        $tax_where = self::taxonomy_sql($mysqli, 'tt');
        $sql = "SELECT COUNT(*) c,COALESCE(BIT_XOR(CRC32(CONCAT_WS(CHAR(31),t.term_id,t.name,t.slug,t.term_group,tt.term_taxonomy_id,tt.taxonomy,tt.description,tt.parent))),0) x,COALESCE(SUM(CRC32(CONCAT_WS(CHAR(31),t.term_id,t.name,t.slug,t.term_group,tt.term_taxonomy_id,tt.taxonomy,tt.description,tt.parent))),0) s
                FROM `{$tables['terms']}` t JOIN `{$tables['term_taxonomy']}` tt ON tt.term_id=t.term_id WHERE {$tax_where}";
        $r = self::rows($mysqli, $sql);
        if (is_wp_error($r)) return $r;
        $pieces['terms'] = ($r[0]['c'] ?? 0) . ':' . ($r[0]['x'] ?? 0) . ':' . ($r[0]['s'] ?? 0);

        $sql = "SELECT COUNT(*) c,COALESCE(BIT_XOR(CRC32(CONCAT_WS(CHAR(31),tr.object_id,tt.taxonomy,t.slug,tr.term_order))),0) x,COALESCE(SUM(CRC32(CONCAT_WS(CHAR(31),tr.object_id,tt.taxonomy,t.slug,tr.term_order))),0) s
                FROM `{$tables['term_relationships']}` tr
                JOIN `{$tables['term_taxonomy']}` tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
                JOIN `{$tables['terms']}` t ON t.term_id=tt.term_id
                WHERE {$tax_where}";
        $r = self::rows($mysqli, $sql);
        if (is_wp_error($r)) return $r;
        $pieces['term_relationships'] = ($r[0]['c'] ?? 0) . ':' . ($r[0]['x'] ?? 0) . ':' . ($r[0]['s'] ?? 0);

        foreach (self::custom_table_keys() as $key) {
            $digest = self::table_digest($mysqli, $tables[$key]);
            if (is_wp_error($digest)) return $digest;
            $pieces[$key] = $digest;
        }
        return hash('sha256', wp_json_encode($pieces));
    }

    private static function count_table($mysqli, $table, $where = '1=1') {
        $value = self::scalar($mysqli, "SELECT COUNT(*) c FROM `{$table}` WHERE {$where}", 'c');
        return is_wp_error($value) ? $value : absint($value);
    }

    private static function analyze($pro, $stg, $pro_tables, $stg_tables) {
        $identity = self::validate_distinct_databases($pro, $stg);
        if (is_wp_error($identity)) return $identity;
        $valid = self::validate_tables($pro, $stg, $pro_tables, $stg_tables);
        if (is_wp_error($valid)) return $valid;
        $writable = self::validate_staging_write_capability($stg, $stg_tables['options']);
        if (is_wp_error($writable)) return $writable;

        $conflicts = array();
        $warnings = array();

        foreach ($stg_tables as $key => $table) {
            $engine = self::table_engine($stg, $table);
            if ($engine && 'INNODB' !== $engine) {
                $conflicts[] = 'La tabla STAGING ' . $table . ' usa ' . $engine . '; el MIRROR requiere InnoDB para poder revertir una fase si falla.';
            }
        }

        $pro_posts = self::post_maps($pro, $pro_tables['posts'], true);
        if (is_wp_error($pro_posts)) return $pro_posts;
        $stg_posts_all = self::post_maps($stg, $stg_tables['posts'], false);
        if (is_wp_error($stg_posts_all)) return $stg_posts_all;

        $source_post_ids = array_keys($pro_posts);
        foreach (self::id_chunks($source_post_ids) as $chunk) {
            $sql_ids = self::sql_ids($chunk);
            $rows = self::rows($stg, "SELECT ID,post_type,post_title FROM `{$stg_tables['posts']}` WHERE ID IN ({$sql_ids}) AND NOT (" . self::post_type_sql($stg) . ") ORDER BY ID LIMIT 20");
            if (is_wp_error($rows)) return $rows;
            foreach ($rows as $row) {
                $conflicts[] = 'ID de post ' . absint($row['ID']) . ' de PRO colisiona en STAGING con post_type=' . (string) $row['post_type'] . ' (' . (string) $row['post_title'] . ').';
                if (count($conflicts) >= 30) break 2;
            }
        }

        $pro_categories = self::category_rows($pro, $pro_tables);
        if (is_wp_error($pro_categories)) return $pro_categories;
        $source_cat_ids = array_values(array_unique(array_map('absint', wp_list_pluck($pro_categories, 'term_id'))));
        $source_cat_tt_ids = array_values(array_unique(array_map('absint', wp_list_pluck($pro_categories, 'term_taxonomy_id'))));

        foreach (self::id_chunks($source_cat_ids) as $chunk) {
            $sql_ids = self::sql_ids($chunk);
            $rows = self::rows($stg, "SELECT tt.term_id,tt.taxonomy,t.name,t.slug FROM `{$stg_tables['term_taxonomy']}` tt JOIN `{$stg_tables['terms']}` t ON t.term_id=tt.term_id WHERE tt.term_id IN ({$sql_ids}) AND tt.taxonomy NOT IN ('product_cat','product_tag','post_tag') LIMIT 20");
            if (is_wp_error($rows)) return $rows;
            foreach ($rows as $row) {
                $conflicts[] = 'term_id ' . absint($row['term_id']) . ' requerido por una categoria PRO se usa en STAGING por taxonomy=' . (string) $row['taxonomy'] . ' (' . (string) $row['slug'] . ').';
                if (count($conflicts) >= 30) break 2;
            }
        }
        if (count($conflicts) < 30) {
            foreach (self::id_chunks($source_cat_tt_ids) as $chunk) {
                $sql_ids = self::sql_ids($chunk);
                $rows = self::rows($stg, "SELECT term_taxonomy_id,taxonomy,term_id FROM `{$stg_tables['term_taxonomy']}` WHERE term_taxonomy_id IN ({$sql_ids}) AND taxonomy NOT IN ('product_cat','product_tag','post_tag') LIMIT 20");
                if (is_wp_error($rows)) return $rows;
                foreach ($rows as $row) {
                    $conflicts[] = 'term_taxonomy_id ' . absint($row['term_taxonomy_id']) . ' requerido por PRO se usa en STAGING por taxonomy=' . (string) $row['taxonomy'] . '.';
                    if (count($conflicts) >= 30) break 2;
                }
            }
        }

        $summary = array();
        foreach (array('product','page','post') as $type) {
            $tax = mysqli_real_escape_string($pro, $type);
            $summary[$type . '_pro'] = absint(self::scalar($pro, "SELECT COUNT(*) c FROM `{$pro_tables['posts']}` WHERE post_type='{$tax}' AND post_status<>'trash'", 'c'));
            $tax2 = mysqli_real_escape_string($stg, $type);
            $summary[$type . '_staging'] = absint(self::scalar($stg, "SELECT COUNT(*) c FROM `{$stg_tables['posts']}` WHERE post_type='{$tax2}' AND post_status<>'trash'", 'c'));
        }
        foreach (self::$taxonomies as $taxonomy) {
            $tax = mysqli_real_escape_string($pro, $taxonomy);
            $tax2 = mysqli_real_escape_string($stg, $taxonomy);
            $summary[$taxonomy . '_pro'] = absint(self::scalar($pro, "SELECT COUNT(*) c FROM `{$pro_tables['term_taxonomy']}` WHERE taxonomy='{$tax}'", 'c'));
            $summary[$taxonomy . '_staging'] = absint(self::scalar($stg, "SELECT COUNT(*) c FROM `{$stg_tables['term_taxonomy']}` WHERE taxonomy='{$tax2}'", 'c'));
        }
        foreach (self::custom_table_keys() as $key) {
            $summary[$key . '_pro'] = absint(self::scalar($pro, "SELECT COUNT(*) c FROM `{$pro_tables[$key]}`", 'c'));
            $summary[$key . '_staging'] = absint(self::scalar($stg, "SELECT COUNT(*) c FROM `{$stg_tables[$key]}`", 'c'));
        }

        $stg_controlled_ids = array_keys($stg_posts_all);
        $summary['posts_remove'] = count(array_diff($stg_controlled_ids, $source_post_ids));
        $summary['posts_create'] = count(array_diff($source_post_ids, $stg_controlled_ids));
        $summary['posts_replace'] = count(array_intersect($source_post_ids, $stg_controlled_ids));

        $stg_categories = self::category_rows($stg, $stg_tables);
        if (is_wp_error($stg_categories)) return $stg_categories;
        $stg_cat_ids = array_values(array_unique(array_map('absint', wp_list_pluck($stg_categories, 'term_id'))));
        $summary['categories_remove'] = count(array_diff($stg_cat_ids, $source_cat_ids));
        $summary['categories_create'] = count(array_diff($source_cat_ids, $stg_cat_ids));

        foreach (array('product_tag','post_tag') as $taxonomy) {
            $pr = self::tag_rows($pro, $pro_tables, $taxonomy);
            $sr = self::tag_rows($stg, $stg_tables, $taxonomy);
            if (is_wp_error($pr)) return $pr;
            if (is_wp_error($sr)) return $sr;
            $ps = array_values(array_unique(array_map('sanitize_title', wp_list_pluck($pr, 'slug'))));
            $ss = array_values(array_unique(array_map('sanitize_title', wp_list_pluck($sr, 'slug'))));
            $summary[$taxonomy . '_create'] = count(array_diff($ps, $ss));
            $summary[$taxonomy . '_remove'] = count(array_diff($ss, $ps));
        }

        $source_marker = self::environment_marker($pro, $pro_tables);
        if (is_wp_error($source_marker)) return $source_marker;
        $target_marker = self::environment_marker($stg, $stg_tables);
        if (is_wp_error($target_marker)) return $target_marker;

        return array(
            'identity' => $identity,
            'summary' => $summary,
            'conflicts' => $conflicts,
            'warnings' => $warnings,
            'source_marker' => $source_marker,
            'target_marker' => $target_marker,
        );
    }

    private static function sql_value($mysqli, $value) {
        if (null === $value) return 'NULL';
        return "'" . mysqli_real_escape_string($mysqli, (string) $value) . "'";
    }

    private static function insert_rows($mysqli, $table, $columns, $rows, $replace = false) {
        if (!$rows) return true;
        $table_id = self::ident($table);
        if (!$table_id) return new WP_Error('full_mirror_table', 'Tabla de destino no valida.');
        $col_sql = array();
        foreach ($columns as $column) {
            $id = self::ident($column);
            if (!$id) return new WP_Error('full_mirror_column', 'Columna no valida: ' . $column);
            $col_sql[] = $id;
        }
        $values_sql = array();
        foreach ($rows as $row) {
            $values = array();
            foreach ($columns as $column) {
                $values[] = self::sql_value($mysqli, array_key_exists($column, $row) ? $row[$column] : null);
            }
            $values_sql[] = '(' . implode(',', $values) . ')';
        }
        $verb = $replace ? 'REPLACE' : 'INSERT';
        return self::exec($mysqli, "{$verb} INTO {$table_id} (" . implode(',', $col_sql) . ') VALUES ' . implode(',', $values_sql));
    }

    private static function copy_custom_table($pro, $stg, $source_table, $target_table, &$stats) {
        $source_columns = self::table_columns($pro, $source_table);
        $target_columns = self::table_columns($stg, $target_table);
        if (is_wp_error($source_columns)) return $source_columns;
        if (is_wp_error($target_columns)) return $target_columns;
        if ($source_columns !== $target_columns) {
            return new WP_Error('full_mirror_schema', 'El esquema no coincide entre PRO y STAGING para ' . $source_table . '. Actualiza primero el plugin/esquema en ambos entornos.');
        }
        $target_id = self::ident($target_table);
        $r = self::exec($stg, "DELETE FROM {$target_id}");
        if (is_wp_error($r)) return $r;
        $columns_sql = implode(',', array_map(array(__CLASS__, 'ident'), $source_columns));
        $cursor = 0;
        $copied = 0;
        $has_id = in_array('id', $source_columns, true);
        while (true) {
            if ($has_id) {
                $rows = self::rows($pro, "SELECT {$columns_sql} FROM `{$source_table}` WHERE id>{$cursor} ORDER BY id ASC LIMIT 400");
            } else {
                $rows = self::rows($pro, "SELECT {$columns_sql} FROM `{$source_table}` LIMIT {$copied},400");
            }
            if (is_wp_error($rows)) return $rows;
            if (!$rows) break;
            foreach (array_chunk($rows, 100) as $chunk) {
                $r = self::insert_rows($stg, $target_table, $source_columns, $chunk, false);
                if (is_wp_error($r)) return $r;
            }
            $copied += count($rows);
            if ($has_id) $cursor = absint(end($rows)['id'] ?? $cursor);
            if (count($rows) < 400) break;
        }
        $stats[$target_table] = $copied;
        return true;
    }

    private static function delete_ids($stg, $table, $column, $ids) {
        $table_id = self::ident($table);
        $column_id = self::ident($column);
        if (!$table_id || !$column_id) return new WP_Error('full_mirror_identifier', 'Identificador SQL no valido.');
        foreach (self::id_chunks($ids) as $chunk) {
            $r = self::exec($stg, "DELETE FROM {$table_id} WHERE {$column_id} IN (" . self::sql_ids($chunk) . ')');
            if (is_wp_error($r)) return $r;
        }
        return true;
    }

    private static function mirror_posts($pro, $stg, $pro_tables, $stg_tables, &$stats) {
        $source_map = self::post_maps($pro, $pro_tables['posts'], true);
        $target_map = self::post_maps($stg, $stg_tables['posts'], false);
        if (is_wp_error($source_map)) return $source_map;
        if (is_wp_error($target_map)) return $target_map;
        $source_ids = array_keys($source_map);
        $target_ids = array_keys($target_map);
        $remove_ids = array_values(array_diff($target_ids, $source_ids));
        $mismatch_ids = array();
        foreach ($source_map as $id => $row) {
            if (isset($target_map[$id]) && (string) $target_map[$id]['type'] !== (string) $row['type']) $mismatch_ids[] = absint($id);
        }

        if ($remove_ids || $mismatch_ids) {
            $cleanup_ids = array_values(array_unique(array_merge($remove_ids, $mismatch_ids)));
            $r = self::delete_ids($stg, $stg_tables['postmeta'], 'post_id', $cleanup_ids);
            if (is_wp_error($r)) return $r;
            $r = self::delete_ids($stg, $stg_tables['term_relationships'], 'object_id', $cleanup_ids);
            if (is_wp_error($r)) return $r;
            if (self::table_exists($stg, $stg_tables['comments']) && self::table_exists($stg, $stg_tables['commentmeta'])) {
                foreach (self::id_chunks($cleanup_ids) as $chunk) {
                    $ids_sql = self::sql_ids($chunk);
                    $comment_ids = self::ids($stg, "SELECT comment_ID FROM `{$stg_tables['comments']}` WHERE comment_post_ID IN ({$ids_sql})", 'comment_ID');
                    if (is_wp_error($comment_ids)) return $comment_ids;
                    $r = self::delete_ids($stg, $stg_tables['commentmeta'], 'comment_id', $comment_ids);
                    if (is_wp_error($r)) return $r;
                    $r = self::exec($stg, "DELETE FROM `{$stg_tables['comments']}` WHERE comment_post_ID IN ({$ids_sql})");
                    if (is_wp_error($r)) return $r;
                }
            }
            $r = self::delete_ids($stg, $stg_tables['posts'], 'ID', $remove_ids);
            if (is_wp_error($r)) return $r;
        }

        $post_columns = self::table_columns($pro, $pro_tables['posts']);
        $target_columns = self::table_columns($stg, $stg_tables['posts']);
        if (is_wp_error($post_columns)) return $post_columns;
        if (is_wp_error($target_columns)) return $target_columns;
        if ($post_columns !== $target_columns) return new WP_Error('full_mirror_posts_schema', 'wp_posts no tiene el mismo esquema en PRO y STAGING.');
        $columns_sql = implode(',', array_map(array(__CLASS__, 'ident'), $post_columns));
        $where = self::post_type_sql($pro) . " AND post_status<>'trash'";
        $cursor = 0;
        $copied = 0;
        while (true) {
            $rows = self::rows($pro, "SELECT {$columns_sql} FROM `{$pro_tables['posts']}` WHERE {$where} AND ID>{$cursor} ORDER BY ID ASC LIMIT 250");
            if (is_wp_error($rows)) return $rows;
            if (!$rows) break;
            foreach (array_chunk($rows, 75) as $chunk) {
                $r = self::insert_rows($stg, $stg_tables['posts'], $post_columns, $chunk, true);
                if (is_wp_error($r)) return $r;
            }
            $copied += count($rows);
            $cursor = absint(end($rows)['ID'] ?? $cursor);
            if (count($rows) < 250) break;
        }
        $stats['posts'] = $copied;

        $meta_filter_stg = self::meta_portable_sql('pm');
        $pwhere_stg = self::post_type_sql($stg, 'p');
        $r = self::exec($stg,
            "DELETE pm FROM `{$stg_tables['postmeta']}` pm JOIN `{$stg_tables['posts']}` p ON p.ID=pm.post_id WHERE {$pwhere_stg} AND {$meta_filter_stg}"
        );
        if (is_wp_error($r)) return $r;

        $meta_filter_pro = self::meta_portable_sql('pm');
        $pwhere_pro = self::post_type_sql($pro, 'p') . " AND p.post_status<>'trash'";
        $cursor = 0;
        $copied_meta = 0;
        while (true) {
            $rows = self::rows($pro,
                "SELECT pm.meta_id,pm.post_id,pm.meta_key,pm.meta_value
                 FROM `{$pro_tables['postmeta']}` pm JOIN `{$pro_tables['posts']}` p ON p.ID=pm.post_id
                 WHERE {$pwhere_pro} AND {$meta_filter_pro} AND pm.meta_id>{$cursor}
                 ORDER BY pm.meta_id ASC LIMIT 500"
            );
            if (is_wp_error($rows)) return $rows;
            if (!$rows) break;
            $insert = array();
            foreach ($rows as $row) {
                $insert[] = array('post_id' => $row['post_id'], 'meta_key' => $row['meta_key'], 'meta_value' => $row['meta_value']);
            }
            foreach (array_chunk($insert, 120) as $chunk) {
                $r = self::insert_rows($stg, $stg_tables['postmeta'], array('post_id','meta_key','meta_value'), $chunk, false);
                if (is_wp_error($r)) return $r;
            }
            $copied_meta += count($rows);
            $cursor = absint(end($rows)['meta_id'] ?? $cursor);
            if (count($rows) < 500) break;
        }
        $stats['postmeta'] = $copied_meta;
        return true;
    }

    private static function delete_orphan_terms($stg, $tables, $term_ids) {
        $orphans = array();
        foreach (self::id_chunks($term_ids) as $chunk) {
            $ids_sql = self::sql_ids($chunk);
            $rows = self::rows($stg,
                "SELECT t.term_id FROM `{$tables['terms']}` t LEFT JOIN `{$tables['term_taxonomy']}` tt ON tt.term_id=t.term_id WHERE t.term_id IN ({$ids_sql}) AND tt.term_id IS NULL"
            );
            if (is_wp_error($rows)) return $rows;
            foreach ($rows as $row) $orphans[] = absint($row['term_id']);
        }
        $orphans = array_values(array_unique(array_filter($orphans)));
        if ($orphans) {
            $r = self::delete_ids($stg, $tables['termmeta'], 'term_id', $orphans);
            if (is_wp_error($r)) return $r;
            $r = self::delete_ids($stg, $tables['terms'], 'term_id', $orphans);
            if (is_wp_error($r)) return $r;
        }
        return true;
    }

    private static function copy_termmeta_mapped($pro, $stg, $pro_tables, $stg_tables, $source_to_target_term) {
        if (!$source_to_target_term) return true;
        $target_ids = array_values(array_unique(array_map('absint', array_values($source_to_target_term))));
        $filter_stg = self::termmeta_portable_sql('tm');
        foreach (self::id_chunks($target_ids) as $chunk) {
            $r = self::exec($stg, "DELETE tm FROM `{$stg_tables['termmeta']}` tm WHERE tm.term_id IN (" . self::sql_ids($chunk) . ") AND {$filter_stg}");
            if (is_wp_error($r)) return $r;
        }
        $source_ids = array_keys($source_to_target_term);
        $filter_pro = self::termmeta_portable_sql('tm');
        foreach (self::id_chunks($source_ids, 500) as $chunk) {
            $rows = self::rows($pro, "SELECT tm.term_id,tm.meta_key,tm.meta_value FROM `{$pro_tables['termmeta']}` tm WHERE tm.term_id IN (" . self::sql_ids($chunk) . ") AND {$filter_pro} ORDER BY tm.meta_id");
            if (is_wp_error($rows)) return $rows;
            $insert = array();
            foreach ($rows as $row) {
                $source_id = absint($row['term_id']);
                if (!isset($source_to_target_term[$source_id])) continue;
                $insert[] = array(
                    'term_id' => absint($source_to_target_term[$source_id]),
                    'meta_key' => (string) $row['meta_key'],
                    'meta_value' => $row['meta_value'],
                );
            }
            foreach (array_chunk($insert, 150) as $part) {
                $r = self::insert_rows($stg, $stg_tables['termmeta'], array('term_id','meta_key','meta_value'), $part, false);
                if (is_wp_error($r)) return $r;
            }
        }
        return true;
    }

    private static function mirror_taxonomies($pro, $stg, $pro_tables, $stg_tables, &$stats) {
        $source_categories = self::category_rows($pro, $pro_tables);
        $target_categories = self::category_rows($stg, $stg_tables);
        if (is_wp_error($source_categories)) return $source_categories;
        if (is_wp_error($target_categories)) return $target_categories;
        $source_cat_ids = array_values(array_unique(array_map('absint', wp_list_pluck($source_categories, 'term_id'))));
        $source_cat_tt = array_values(array_unique(array_map('absint', wp_list_pluck($source_categories, 'term_taxonomy_id'))));
        $target_cat_ids = array_values(array_unique(array_map('absint', wp_list_pluck($target_categories, 'term_id'))));
        $target_cat_tt = array_values(array_unique(array_map('absint', wp_list_pluck($target_categories, 'term_taxonomy_id'))));

        // Si una categoria PRO necesita un ID ocupado por una tag controlada de STAGING,
        // retiramos esa tag; se recreara por slug en el paso siguiente.
        $conflicting_controlled_tt = array();
        foreach (self::id_chunks(array_merge($source_cat_ids, $source_cat_tt)) as $chunk) {
            $ids_sql = self::sql_ids($chunk);
            $rows = self::rows($stg,
                "SELECT term_taxonomy_id,term_id FROM `{$stg_tables['term_taxonomy']}` WHERE taxonomy IN ('product_tag','post_tag') AND (term_id IN ({$ids_sql}) OR term_taxonomy_id IN ({$ids_sql}))"
            );
            if (is_wp_error($rows)) return $rows;
            foreach ($rows as $row) $conflicting_controlled_tt[] = absint($row['term_taxonomy_id']);
        }
        $conflicting_controlled_tt = array_values(array_unique(array_filter($conflicting_controlled_tt)));
        if ($conflicting_controlled_tt) {
            $r = self::delete_ids($stg, $stg_tables['term_relationships'], 'term_taxonomy_id', $conflicting_controlled_tt);
            if (is_wp_error($r)) return $r;
            $term_ids = self::ids($stg, "SELECT term_id FROM `{$stg_tables['term_taxonomy']}` WHERE term_taxonomy_id IN (" . self::sql_ids($conflicting_controlled_tt) . ')', 'term_id');
            if (is_wp_error($term_ids)) return $term_ids;
            $r = self::delete_ids($stg, $stg_tables['term_taxonomy'], 'term_taxonomy_id', $conflicting_controlled_tt);
            if (is_wp_error($r)) return $r;
            $r = self::delete_orphan_terms($stg, $stg_tables, $term_ids);
            if (is_wp_error($r)) return $r;
        }

        // Toda relacion de las taxonomias gestionadas se reconstruye desde PRO.
        $target_controlled_tt = self::ids($stg, "SELECT term_taxonomy_id FROM `{$stg_tables['term_taxonomy']}` WHERE " . self::taxonomy_sql($stg), 'term_taxonomy_id');
        if (is_wp_error($target_controlled_tt)) return $target_controlled_tt;
        $r = self::delete_ids($stg, $stg_tables['term_relationships'], 'term_taxonomy_id', $target_controlled_tt);
        if (is_wp_error($r)) return $r;

        // Reemplazo exacto de product_cat con IDs de PRO.
        if ($target_cat_tt) {
            $r = self::delete_ids($stg, $stg_tables['term_taxonomy'], 'term_taxonomy_id', $target_cat_tt);
            if (is_wp_error($r)) return $r;
            $r = self::delete_orphan_terms($stg, $stg_tables, $target_cat_ids);
            if (is_wp_error($r)) return $r;
        }
        $cat_term_rows = array();
        $cat_tt_rows = array();
        $category_term_map = array();
        $category_tt_map = array();
        foreach ($source_categories as $row) {
            $term_id = absint($row['term_id']);
            $tt_id = absint($row['term_taxonomy_id']);
            $cat_term_rows[] = array('term_id'=>$term_id,'name'=>$row['name'],'slug'=>$row['slug'],'term_group'=>$row['term_group']);
            $cat_tt_rows[] = array('term_taxonomy_id'=>$tt_id,'term_id'=>$term_id,'taxonomy'=>'product_cat','description'=>$row['description'],'parent'=>$row['parent'],'count'=>$row['count']);
            $category_term_map[$term_id] = $term_id;
            $category_tt_map[$tt_id] = $tt_id;
        }
        foreach (array_chunk($cat_term_rows, 120) as $chunk) {
            $r = self::insert_rows($stg, $stg_tables['terms'], array('term_id','name','slug','term_group'), $chunk, true);
            if (is_wp_error($r)) return $r;
        }
        foreach (array_chunk($cat_tt_rows, 120) as $chunk) {
            $r = self::insert_rows($stg, $stg_tables['term_taxonomy'], array('term_taxonomy_id','term_id','taxonomy','description','parent','count'), $chunk, true);
            if (is_wp_error($r)) return $r;
        }
        $r = self::copy_termmeta_mapped($pro, $stg, $pro_tables, $stg_tables, $category_term_map);
        if (is_wp_error($r)) return $r;
        $stats['product_cat'] = count($source_categories);

        // product_tag y post_tag se resuelven por slug; IDs locales pueden diferir.
        $source_tt_to_target = $category_tt_map;
        foreach (array('product_tag','post_tag') as $taxonomy) {
            $source_tags = self::tag_rows($pro, $pro_tables, $taxonomy);
            $target_tags = self::tag_rows($stg, $stg_tables, $taxonomy);
            if (is_wp_error($source_tags)) return $source_tags;
            if (is_wp_error($target_tags)) return $target_tags;
            $source_by_slug = array();
            foreach ($source_tags as $row) $source_by_slug[sanitize_title((string) $row['slug'])] = $row;
            $target_by_slug = array();
            foreach ($target_tags as $row) $target_by_slug[sanitize_title((string) $row['slug'])] = $row;

            $extra_slugs = array_diff(array_keys($target_by_slug), array_keys($source_by_slug));
            $extra_tt = array();
            $extra_terms = array();
            foreach ($extra_slugs as $slug) {
                $extra_tt[] = absint($target_by_slug[$slug]['term_taxonomy_id']);
                $extra_terms[] = absint($target_by_slug[$slug]['term_id']);
            }
            $r = self::delete_ids($stg, $stg_tables['term_taxonomy'], 'term_taxonomy_id', $extra_tt);
            if (is_wp_error($r)) return $r;
            $r = self::delete_orphan_terms($stg, $stg_tables, $extra_terms);
            if (is_wp_error($r)) return $r;

            $term_map = array();
            foreach ($source_by_slug as $slug => $src) {
                $target = $target_by_slug[$slug] ?? null;
                if ($target) {
                    $target_term_id = absint($target['term_id']);
                    $target_tt_id = absint($target['term_taxonomy_id']);
                    $r = self::exec($stg,
                        "UPDATE `{$stg_tables['terms']}` SET name=" . self::sql_value($stg, $src['name']) . ",slug=" . self::sql_value($stg, $src['slug']) . ",term_group=" . absint($src['term_group']) . " WHERE term_id={$target_term_id}"
                    );
                    if (is_wp_error($r)) return $r;
                    $r = self::exec($stg,
                        "UPDATE `{$stg_tables['term_taxonomy']}` SET description=" . self::sql_value($stg, $src['description']) . ",parent=0 WHERE term_taxonomy_id={$target_tt_id}"
                    );
                    if (is_wp_error($r)) return $r;
                } else {
                    $r = self::exec($stg,
                        "INSERT INTO `{$stg_tables['terms']}` (name,slug,term_group) VALUES (" . self::sql_value($stg, $src['name']) . ',' . self::sql_value($stg, $src['slug']) . ',' . absint($src['term_group']) . ')'
                    );
                    if (is_wp_error($r)) return $r;
                    $target_term_id = absint(mysqli_insert_id($stg));
                    $tax = mysqli_real_escape_string($stg, $taxonomy);
                    $r = self::exec($stg,
                        "INSERT INTO `{$stg_tables['term_taxonomy']}` (term_id,taxonomy,description,parent,count) VALUES ({$target_term_id},'{$tax}'," . self::sql_value($stg, $src['description']) . ',0,0)'
                    );
                    if (is_wp_error($r)) return $r;
                    $target_tt_id = absint(mysqli_insert_id($stg));
                }
                $source_tt_to_target[absint($src['term_taxonomy_id'])] = $target_tt_id;
                $term_map[absint($src['term_id'])] = $target_term_id;
            }
            $r = self::copy_termmeta_mapped($pro, $stg, $pro_tables, $stg_tables, $term_map);
            if (is_wp_error($r)) return $r;
            $stats[$taxonomy] = count($source_tags);
        }

        // Copia todas las relaciones de las tres taxonomias, remapeando tt de tags.
        $cursor_object = 0;
        $cursor_tt = 0;
        $copied_rel = 0;
        while (true) {
            $rows = self::rows($pro,
                "SELECT tr.object_id,tr.term_taxonomy_id,tr.term_order,tt.taxonomy
                 FROM `{$pro_tables['term_relationships']}` tr
                 JOIN `{$pro_tables['term_taxonomy']}` tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
                 WHERE " . self::taxonomy_sql($pro, 'tt') . "
                   AND (tr.object_id>{$cursor_object} OR (tr.object_id={$cursor_object} AND tr.term_taxonomy_id>{$cursor_tt}))
                 ORDER BY tr.object_id,tr.term_taxonomy_id LIMIT 800"
            );
            if (is_wp_error($rows)) return $rows;
            if (!$rows) break;
            $insert = array();
            foreach ($rows as $row) {
                $source_tt = absint($row['term_taxonomy_id']);
                if (!isset($source_tt_to_target[$source_tt])) {
                    return new WP_Error('full_mirror_term_map', 'No se pudo resolver term_taxonomy_id ' . $source_tt . ' de PRO en STAGING.');
                }
                $insert[] = array(
                    'object_id' => absint($row['object_id']),
                    'term_taxonomy_id' => absint($source_tt_to_target[$source_tt]),
                    'term_order' => (int) $row['term_order'],
                );
            }
            foreach (array_chunk($insert, 180) as $chunk) {
                $r = self::insert_rows($stg, $stg_tables['term_relationships'], array('object_id','term_taxonomy_id','term_order'), $chunk, false);
                if (is_wp_error($r)) return $r;
            }
            $copied_rel += count($rows);
            $last = end($rows);
            $cursor_object = absint($last['object_id'] ?? $cursor_object);
            $cursor_tt = absint($last['term_taxonomy_id'] ?? $cursor_tt);
            if (count($rows) < 800) break;
        }
        $stats['term_relationships'] = $copied_rel;

        $r = self::exec($stg,
            "UPDATE `{$stg_tables['term_taxonomy']}` tt
             SET tt.count=(SELECT COUNT(*) FROM `{$stg_tables['term_relationships']}` tr WHERE tr.term_taxonomy_id=tt.term_taxonomy_id)
             WHERE " . self::taxonomy_sql($stg, 'tt')
        );
        if (is_wp_error($r)) return $r;
        return true;
    }

    private static function mirror_wc_lookup_if_available($pro, $stg, $pro_prefix, $stg_prefix, &$stats) {
        $source = $pro_prefix . 'wc_product_meta_lookup';
        $target = $stg_prefix . 'wc_product_meta_lookup';
        if (!self::table_exists($pro, $source) || !self::table_exists($stg, $target)) return true;
        $source_columns = self::table_columns($pro, $source);
        $target_columns = self::table_columns($stg, $target);
        if (is_wp_error($source_columns) || is_wp_error($target_columns) || $source_columns !== $target_columns || !in_array('product_id', $source_columns, true)) return true;
        $r = self::exec($stg,
            "DELETE l FROM `{$target}` l JOIN `{$stg_prefix}posts` p ON p.ID=l.product_id WHERE p.post_type='product'"
        );
        if (is_wp_error($r)) return $r;
        $cols = implode(',', array_map(array(__CLASS__, 'ident'), $source_columns));
        $cursor = 0;
        $copied = 0;
        while (true) {
            // La primera consulta solo obtiene IDs para evitar cualquier columna
            // ambigua entre wc_product_meta_lookup y wp_posts.
            $rows = self::rows($pro,
                "SELECT l.product_id FROM `{$source}` l JOIN `{$pro_prefix}posts` p ON p.ID=l.product_id WHERE p.post_type='product' AND l.product_id>{$cursor} ORDER BY l.product_id LIMIT 500"
            );
            if (is_wp_error($rows)) return $rows;
            if (!$rows) break;
            $ids = array_values(array_unique(array_map('absint', wp_list_pluck($rows, 'product_id'))));
            if (!$ids) break;
            $clean = self::rows($pro, "SELECT {$cols} FROM `{$source}` WHERE product_id IN (" . self::sql_ids($ids) . ') ORDER BY product_id');
            if (is_wp_error($clean)) return $clean;
            foreach (array_chunk($clean, 120) as $chunk) {
                $r = self::insert_rows($stg, $target, $source_columns, $chunk, true);
                if (is_wp_error($r)) return $r;
            }
            $copied += count($clean);
            $cursor = max($ids);
            if (count($rows) < 500) break;
        }
        $stats['wc_product_meta_lookup'] = $copied;
        return true;
    }

    private static function set_staging_option($stg, $options_table, $name, $value) {
        $name_sql = self::sql_value($stg, $name);
        $value_sql = self::sql_value($stg, maybe_serialize($value));
        return self::exec($stg,
            "INSERT INTO `{$options_table}` (option_name,option_value,autoload) VALUES ({$name_sql},{$value_sql},'no')
             ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='no'"
        );
    }

    private static function invalidate_comparator_remote($stg, $prefix) {
        $compare_table = $prefix . 'seo_environment_compare';
        if (self::table_exists($stg, $compare_table)) {
            $r = self::exec($stg, "DELETE FROM `{$compare_table}`");
            if (is_wp_error($r)) return $r;
        }
        return self::exec($stg,
            "DELETE FROM `{$prefix}options` WHERE option_name LIKE 'seo_environment_compare_state\\_%' ESCAPE '\\\\' OR option_name LIKE 'seo_environment_compare_stop\\_%' ESCAPE '\\\\'"
        );
    }

    private static function invalidate_local_comparator() {
        global $wpdb;
        if (function_exists('seo_environment_compare_table')) {
            $table = seo_environment_compare_table();
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
                $wpdb->query("DELETE FROM {$table}");
            }
        }
        foreach (array_keys(function_exists('seo_environment_compare_entities') ? seo_environment_compare_entities() : array()) as $entity) {
            delete_option('seo_environment_compare_state_' . $entity);
            delete_option('seo_environment_compare_stop_' . $entity);
        }
    }

    private static function apply_mirror($pro, $stg, $pro_tables, $stg_tables, $identity) {
        $lock = self::scalar($stg, "SELECT GET_LOCK('" . mysqli_real_escape_string($stg, self::LOCK_NAME) . "',0) AS l", 'l');
        if ('1' !== (string) $lock) return new WP_Error('full_mirror_lock', 'Ya hay otra alineacion integral en curso.');
        $stats = array();
        $started = microtime(true);
        try {
            $r = self::exec($stg, 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            if (is_wp_error($r)) return $r;
            $r = self::exec($stg, 'START TRANSACTION');
            if (is_wp_error($r)) return $r;

            $r = self::mirror_posts($pro, $stg, $pro_tables, $stg_tables, $stats);
            if (is_wp_error($r)) throw new RuntimeException($r->get_error_message());
            $r = self::mirror_taxonomies($pro, $stg, $pro_tables, $stg_tables, $stats);
            if (is_wp_error($r)) throw new RuntimeException($r->get_error_message());

            foreach (self::custom_table_keys() as $key) {
                $r = self::copy_custom_table($pro, $stg, $pro_tables[$key], $stg_tables[$key], $stats);
                if (is_wp_error($r)) throw new RuntimeException($r->get_error_message());
            }

            $pro_prefix = substr($pro_tables['posts'], 0, -strlen('posts'));
            $stg_prefix = substr($stg_tables['posts'], 0, -strlen('posts'));
            $r = self::mirror_wc_lookup_if_available($pro, $stg, $pro_prefix, $stg_prefix, $stats);
            if (is_wp_error($r)) throw new RuntimeException($r->get_error_message());

            $r = self::exec($stg, 'COMMIT');
            if (is_wp_error($r)) throw new RuntimeException($r->get_error_message());
        } catch (Throwable $e) {
            @mysqli_query($stg, 'ROLLBACK');
            return new WP_Error('full_mirror_apply', 'MIRROR cancelado y revertido: ' . $e->getMessage());
        } finally {
            @mysqli_query($stg, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($stg, self::LOCK_NAME) . "')");
        }

        $generation = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('mirror-', true);
        $payload = array(
            'generation' => $generation,
            'at_gmt' => gmdate('c'),
            'source' => 'pro',
            'destination' => 'staging',
            'source_database' => (string) ($identity['pro_database'] ?? ''),
            'destination_database' => (string) ($identity['staging_database'] ?? ''),
            'stats' => $stats,
        );
        self::set_staging_option($stg, $stg_tables['options'], 'seo_environment_full_mirror_generation', $payload);
        self::set_staging_option($stg, $stg_tables['options'], 'seo_environment_full_mirror_last', $payload);
        self::set_staging_option($stg, $stg_tables['options'], 'seo_semantic_catalog_reindex_pending', array(
            'reason' => 'environment_full_mirror',
            'generation' => $generation,
            'created_at' => gmdate('c'),
            'products' => 'all',
        ));
        self::invalidate_comparator_remote($stg, substr($stg_tables['posts'], 0, -strlen('posts')));
        self::invalidate_local_comparator();

        return array(
            'stats' => $stats,
            'seconds' => round(microtime(true) - $started, 3),
            'generation' => $generation,
        );
    }

    private static function open_pair() {
        $pro = seo_environment_compare_open('pro');
        if (is_wp_error($pro)) return $pro;
        $stg = seo_environment_compare_open('staging');
        if (is_wp_error($stg)) {
            @mysqli_close($pro);
            return $stg;
        }
        return array($pro, $stg);
    }

    public static function ajax_preview() {
        $auth = self::authorize();
        if (is_wp_error($auth)) wp_send_json_error(array('message'=>$auth->get_error_message()), 403);
        @set_time_limit(0);
        $pair = self::open_pair();
        if (is_wp_error($pair)) wp_send_json_error(array('message'=>$pair->get_error_message()), 500);
        list($pro, $stg) = $pair;
        $pro_prefix = seo_environment_compare_db_prefix('pro');
        $stg_prefix = seo_environment_compare_db_prefix('staging');
        $pro_tables = self::all_required_tables($pro_prefix);
        $stg_tables = self::all_required_tables($stg_prefix);
        try {
            $analysis = self::analyze($pro, $stg, $pro_tables, $stg_tables);
            if (is_wp_error($analysis)) wp_send_json_error(array('message'=>$analysis->get_error_message()), 500);
            set_transient(self::preview_key(), array(
                'source_marker' => (string) $analysis['source_marker'],
                'target_marker' => (string) $analysis['target_marker'],
                'identity' => (array) $analysis['identity'],
                'previewed_at' => time(),
            ), self::PREVIEW_TTL);
            wp_send_json_success(array(
                'identity' => (array) $analysis['identity'],
                'summary' => (array) $analysis['summary'],
                'conflicts' => array_slice((array) $analysis['conflicts'], 0, 30),
                'conflict_count' => count((array) $analysis['conflicts']),
                'warnings' => (array) $analysis['warnings'],
                'can_apply' => empty($analysis['conflicts']),
                'scope' => array(
                    'post_types' => self::$post_types,
                    'taxonomies' => self::$taxonomies,
                    'custom_tables' => self::custom_table_keys(),
                    'images' => 'excluded',
                    'academy' => 'not_touched',
                ),
            ));
        } finally {
            @mysqli_close($pro);
            @mysqli_close($stg);
        }
    }

    public static function ajax_apply() {
        $auth = self::authorize();
        if (is_wp_error($auth)) wp_send_json_error(array('message'=>$auth->get_error_message()), 403);
        if ('1' !== (string) ($_POST['confirm'] ?? '')) {
            wp_send_json_error(array('message'=>'Falta la confirmacion explicita del MIRROR.'), 400);
        }
        $saved = get_transient(self::preview_key());
        if (!is_array($saved)) wp_send_json_error(array('message'=>'La simulacion ha caducado. Simula de nuevo antes de escribir.'), 409);
        @set_time_limit(0);
        ignore_user_abort(true);
        $pair = self::open_pair();
        if (is_wp_error($pair)) wp_send_json_error(array('message'=>$pair->get_error_message()), 500);
        list($pro, $stg) = $pair;
        $pro_prefix = seo_environment_compare_db_prefix('pro');
        $stg_prefix = seo_environment_compare_db_prefix('staging');
        $pro_tables = self::all_required_tables($pro_prefix);
        $stg_tables = self::all_required_tables($stg_prefix);
        try {
            $analysis = self::analyze($pro, $stg, $pro_tables, $stg_tables);
            if (is_wp_error($analysis)) wp_send_json_error(array('message'=>$analysis->get_error_message()), 500);
            if (!empty($analysis['conflicts'])) {
                delete_transient(self::preview_key());
                wp_send_json_error(array('message'=>'La prevalidacion contiene conflictos. No se ha escrito nada.','conflicts'=>array_slice($analysis['conflicts'],0,20)), 409);
            }
            if (!hash_equals((string) ($saved['source_marker'] ?? ''), (string) $analysis['source_marker'])) {
                delete_transient(self::preview_key());
                wp_send_json_error(array('message'=>'PRO ha cambiado desde la simulacion. No se ha escrito nada; vuelve a simular.'), 409);
            }
            if (!hash_equals((string) ($saved['target_marker'] ?? ''), (string) $analysis['target_marker'])) {
                delete_transient(self::preview_key());
                wp_send_json_error(array('message'=>'STAGING ha cambiado desde la simulacion. No se ha escrito nada; vuelve a simular.'), 409);
            }
            $result = self::apply_mirror($pro, $stg, $pro_tables, $stg_tables, (array) $analysis['identity']);
            if (is_wp_error($result)) wp_send_json_error(array('message'=>$result->get_error_message()), 500);
            delete_transient(self::preview_key());
            wp_send_json_success(array(
                'message' => 'MIRROR integral PRO → STAGING terminado. El perimetro del Comparador se ha reemplazado en STAGING.',
                'stats' => (array) ($result['stats'] ?? array()),
                'seconds' => (float) ($result['seconds'] ?? 0),
                'generation' => (string) ($result['generation'] ?? ''),
                'reindex_pending' => true,
            ));
        } finally {
            @mysqli_close($pro);
            @mysqli_close($stg);
        }
    }

    /**
     * Si la escritura se lanzo remotamente desde PRO, la siguiente carga de
     * wp-admin en STAGING invalida cache persistente para no servir objetos
     * anteriores al MIRROR. No toca Academia ni el indice aprendido.
     */
    public static function consume_staging_generation() {
        if (!function_exists('seo_environment_compare_current_env') || 'staging' !== seo_environment_compare_current_env()) return;
        $payload = get_option('seo_environment_full_mirror_generation', array());
        if (!is_array($payload) || empty($payload['generation'])) return;
        $generation = (string) $payload['generation'];
        $seen = (string) get_option('seo_environment_full_mirror_generation_seen', '');
        if ($seen === $generation) return;
        if (function_exists('wp_cache_flush')) wp_cache_flush();
        update_option('seo_environment_full_mirror_generation_seen', $generation, false);
        add_action('admin_notices', static function () {
            if (!current_user_can('manage_options')) return;
            $url = admin_url('admin.php?page=seo-dependiente');
            echo '<div class="notice notice-success"><p><strong>PRO → STAGING:</strong> el MIRROR del catálogo terminó. La caché local de STAGING se ha invalidado. <a href="' . esc_url($url) . '">Reindexa Dependiente</a> para regenerar su índice derivado con el catálogo alineado.</p></div>';
        });
    }
}

SEO_Environment_Full_Mirror::init();
