<?php
/**
 * SEO System - Clonador portable PRO -> STAGING.
 *
 * Clona de forma destructiva el perimetro gestionado desde PRO hacia STAGING.
 * PRO is always source; STAGING is always destination. Source numeric IDs may
 * be used as transient audit/map keys but NEVER to locate a destination row.
 * STAGING is rebuilt from scratch inside the managed perimeter and all
 * relationships are rebuilt with STAGING-local IDs. No destination matching
 * against the previous STAGING catalog is performed.
 *
 * @package SEOSystem
 * @subpackage Clonador
 * @since 2.5.0
 */

defined('ABSPATH') || exit;

final class SEO_Clonador_Engine {
    const NONCE_ACTION = 'seo_clonador';
    const PREVIEW_PREFIX = 'seo_clonador_preview_';
    const PREVIEW_TTL = 3600;
    const LOCK_NAME = 'seo_clonador_staging';
    const WORKER_JOB_OPTION = 'seo_clonador_worker_job';
    const WORKER_MAP_PREFIX = 'seo_clonador_worker_map_';

    private static $post_types = array('product', 'product_variation', 'page', 'post');
    private static $taxonomies = array('product_cat', 'product_tag', 'post_tag');
    private static $progress_callback = null;

    public static function init() {
        add_action('wp_ajax_seo_clonador_preview', array(__CLASS__, 'ajax_preview'));
        add_action('wp_ajax_seo_clonador_apply', array(__CLASS__, 'ajax_apply'));
        add_action('wp_ajax_seo_clonador_status', array(__CLASS__, 'ajax_status'));
        add_action('wp_ajax_seo_clonador_stop', array(__CLASS__, 'ajax_stop'));
        add_action('wp_ajax_seo_clonador_resume', array(__CLASS__, 'ajax_resume'));
        add_action('wp_ajax_seo_clonador_speed', array(__CLASS__, 'ajax_speed'));
        add_action('admin_init', array(__CLASS__, 'consume_staging_generation'));
    }

    private static function authorize() {
        if (!current_user_can('manage_options')) {
            return new WP_Error('clonador_capability', 'Permisos insuficientes.');
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!function_exists('seo_clonador_open') || !function_exists('seo_clonador_db_prefix')) {
            return new WP_Error('clonador_connections', 'No estan disponibles las conexiones PRO/STAGING del Clonador.');
        }
        $stg_settings = function_exists('seo_clonador_db_settings') ? (array) seo_clonador_db_settings('staging') : array();
        $clone_mode = sanitize_key((string)($stg_settings['clone_mode'] ?? ''));
        if ('destructive_clone' !== $clone_mode && empty($stg_settings['destructive_clone'])) {
            return new WP_Error('clonador_policy', 'STAGING no esta autorizado para clonacion destructiva. Activa la autorizacion en la conexion STAGING antes de continuar.');
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
            return new WP_Error('clonador_sql_read', trim((string) mysqli_error($mysqli)) ?: 'Error de lectura MySQL.');
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

    private static function exec($mysqli, $sql, $code = 'clonador_sql_write') {
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
        if (!$id) return new WP_Error('clonador_table', 'Nombre de tabla no valido.');
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
        if ($errors) return new WP_Error('clonador_tables', implode(' ', array_slice($errors, 0, 10)));
        return true;
    }

    /**
     * Comprueba permisos de escritura de STAGING sin modificar filas.
     */
    private static function validate_staging_write_capability($stg, $options_table) {
        $table = self::ident($options_table);
        if (!$table) {
            return new WP_Error('clonador_write_table', 'No se pudo validar la tabla options de STAGING.');
        }
        $tests = array(
            "UPDATE {$table} SET option_value=option_value WHERE 1=0",
            "DELETE FROM {$table} WHERE 1=0",
            "INSERT INTO {$table} (option_name,option_value,autoload) SELECT '__seo_clonador_privilege_test__','','no' WHERE 1=0",
        );
        foreach ($tests as $sql) {
            if (false === @mysqli_query($stg, $sql)) {
                $error = trim((string) mysqli_error($stg));
                return new WP_Error(
                    'clonador_staging_read_only',
                    'La conexion BBDD de STAGING necesita SELECT/INSERT/UPDATE/DELETE. PRO puede permanecer en solo lectura.' .
                    ($error ? ' MySQL: ' . $error : '')
                );
            }
        }
        return true;
    }

    private static function validate_distinct_databases($pro, $stg) {
        $pro_db = (string) self::scalar($pro, 'SELECT DATABASE() AS db', 'db');
        $stg_db = (string) self::scalar($stg, 'SELECT DATABASE() AS db', 'db');
        $pro_settings = function_exists('seo_clonador_db_settings') ? (array) seo_clonador_db_settings('pro') : array();
        $stg_settings = function_exists('seo_clonador_db_settings') ? (array) seo_clonador_db_settings('staging') : array();
        $pro_host = strtolower(trim((string) ($pro_settings['host'] ?? '')));
        $stg_host = strtolower(trim((string) ($stg_settings['host'] ?? '')));
        $pro_port = absint($pro_settings['port'] ?? 3306);
        $stg_port = absint($stg_settings['port'] ?? 3306);
        if ($pro_db && $stg_db && $pro_db === $stg_db && $pro_host === $stg_host && $pro_port === $stg_port) {
            return new WP_Error('clonador_same_database', 'PRO y STAGING apuntan a la misma base de datos. Clonacion bloqueada.');
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

    private static function extra_taxonomy_sql($mysqli, $alias = '') {
        $prefix = $alias ? $alias . '.' : '';
        $values = array('category', 'product_type', 'product_visibility', 'product_shipping_class', 'product_brand');
        $quoted = array();
        foreach ($values as $taxonomy) {
            $quoted[] = "'" . mysqli_real_escape_string($mysqli, $taxonomy) . "'";
        }
        return '(' . $prefix . 'taxonomy IN (' . implode(',', $quoted) . ") OR LEFT({$prefix}taxonomy,3)='pa_')";
    }

    private static function all_managed_taxonomy_sql($mysqli, $alias = '') {
        return '(' . self::taxonomy_sql($mysqli, $alias) . ' OR ' . self::extra_taxonomy_sql($mysqli, $alias) . ')';
    }

    private static function meta_portable_sql($alias = '') {
        $p = $alias ? $alias . '.' : '';
        $parts = array(
            "{$p}meta_key NOT IN ('_edit_lock','_edit_last','_wp_old_slug','_wp_old_date','_wp_desired_post_slug','_pingme','_encloseme')",
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

    private static function remapped_postmeta_keys() {
        return array('_upsell_ids', '_crosssell_ids', '_children');
    }

    private static function remap_postmeta_value($meta_key, $meta_value, $post_map, &$stats) {
        $meta_key = (string) $meta_key;
        if (!in_array($meta_key, self::remapped_postmeta_keys(), true)) return $meta_value;
        if ('' === trim((string) $meta_value)) return $meta_value;
        $value = maybe_unserialize($meta_value);
        if (!is_array($value)) {
            return new WP_Error('clonador_postmeta_reference', 'El metadato ' . $meta_key . ' no contiene una lista de IDs como se esperaba.');
        }
        $mapped = array();
        foreach ($value as $source_id) {
            $source_id = absint($source_id);
            if (!$source_id) continue;
            if (empty($post_map[$source_id])) {
                // Upsells/cross-sells/children pueden conservar referencias antiguas
                // a productos eliminados o enviados a papelera en PRO. Ese objeto no
                // existe en el perimetro clonado, por lo que la referencia se omite
                // de forma explicita en vez de abortar toda la clonacion.
                $stats['postmeta_unresolved_references_total'] = absint($stats['postmeta_unresolved_references_total'] ?? 0) + 1;
                if (!isset($stats['postmeta_unresolved_references_by_key']) || !is_array($stats['postmeta_unresolved_references_by_key'])) {
                    $stats['postmeta_unresolved_references_by_key'] = array();
                }
                $stats['postmeta_unresolved_references_by_key'][$meta_key] = absint($stats['postmeta_unresolved_references_by_key'][$meta_key] ?? 0) + 1;
                if (!isset($stats['postmeta_unresolved_reference_samples']) || !is_array($stats['postmeta_unresolved_reference_samples'])) {
                    $stats['postmeta_unresolved_reference_samples'] = array();
                }
                if (count($stats['postmeta_unresolved_reference_samples']) < 25) {
                    $stats['postmeta_unresolved_reference_samples'][] = array('meta_key' => $meta_key, 'source_id' => $source_id);
                }
                continue;
            }
            $mapped[] = absint($post_map[$source_id]);
        }
        return maybe_serialize(array_values(array_unique($mapped)));
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

    private static function count_table($mysqli, $table, $where = '1=1') {
        $value = self::scalar($mysqli, "SELECT COUNT(*) c FROM `{$table}` WHERE {$where}", 'c');
        return is_wp_error($value) ? $value : absint($value);
    }

    private static function managed_custom_where($mysqli, $key) {
        switch ((string) $key) {
            case 'seo_object_vocabulary':
                return "object_type IN ('product','product_cat','page','post')";
            case 'seo_nodes':
                return "object_type IN ('category','product','page','post')";
            case 'seo_relations':
                $types = self::relation_managed_types();
                $quoted = array_map(static function($v) use ($mysqli) {
                    return "'" . mysqli_real_escape_string($mysqli, $v) . "'";
                }, $types);
                return 'relation_type IN (' . implode(',', $quoted) . ')';
            case 'seo_faq':
                return 'object_type IN (1,2,3)';
            default:
                return '1=1';
        }
    }

    private static function optional_woo_tables($prefix) {
        return array(
            'woocommerce_attribute_taxonomies' => $prefix . 'woocommerce_attribute_taxonomies',
            'wc_product_meta_lookup' => $prefix . 'wc_product_meta_lookup',
            'wc_product_attributes_lookup' => $prefix . 'wc_product_attributes_lookup',
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
            if ($id) $expr[] = "COALESCE(CAST({$id} AS CHAR),'')";
        }
        if (!$expr) return '0:0:0';
        $concat = 'CONCAT_WS(CHAR(31),' . implode(',', $expr) . ')';
        $rows = self::rows($mysqli, "SELECT COUNT(*) c,COALESCE(BIT_XOR(CRC32({$concat})),0) x,COALESCE(SUM(CRC32({$concat})),0) s FROM `{$table}` WHERE {$where}");
        if (is_wp_error($rows)) return $rows;
        $row = $rows[0] ?? array();
        return (string) ($row['c'] ?? 0) . ':' . (string) ($row['x'] ?? 0) . ':' . (string) ($row['s'] ?? 0);
    }

    private static function environment_marker($mysqli, $tables) {
        $pieces = array();
        $digest = self::table_digest($mysqli, $tables['posts'], self::post_type_sql($mysqli) . " AND post_status<>'trash'", array('ID','post_content','post_title','post_excerpt','post_status','post_name','post_parent','menu_order','post_type'));
        if (is_wp_error($digest)) return $digest;
        $pieces['posts'] = $digest;

        $sql = "SELECT COUNT(*) c,COALESCE(BIT_XOR(CRC32(CONCAT_WS(CHAR(31),pm.post_id,pm.meta_key,pm.meta_value))),0) x,COALESCE(SUM(CRC32(CONCAT_WS(CHAR(31),pm.post_id,pm.meta_key,pm.meta_value))),0) s FROM `{$tables['postmeta']}` pm JOIN `{$tables['posts']}` p ON p.ID=pm.post_id WHERE " . self::post_type_sql($mysqli, 'p') . " AND p.post_status<>'trash' AND " . self::meta_portable_sql('pm');
        $r = self::rows($mysqli, $sql);
        if (is_wp_error($r)) return $r;
        $pieces['postmeta'] = ($r[0]['c'] ?? 0) . ':' . ($r[0]['x'] ?? 0) . ':' . ($r[0]['s'] ?? 0);

        $sql = "SELECT COUNT(*) c,COALESCE(BIT_XOR(CRC32(CONCAT_WS(CHAR(31),t.term_id,t.name,t.slug,t.term_group,tt.term_taxonomy_id,tt.taxonomy,tt.description,tt.parent))),0) x,COALESCE(SUM(CRC32(CONCAT_WS(CHAR(31),t.term_id,t.name,t.slug,t.term_group,tt.term_taxonomy_id,tt.taxonomy,tt.description,tt.parent))),0) s FROM `{$tables['terms']}` t JOIN `{$tables['term_taxonomy']}` tt ON tt.term_id=t.term_id WHERE " . self::all_managed_taxonomy_sql($mysqli, 'tt');
        $r = self::rows($mysqli, $sql);
        if (is_wp_error($r)) return $r;
        $pieces['terms'] = ($r[0]['c'] ?? 0) . ':' . ($r[0]['x'] ?? 0) . ':' . ($r[0]['s'] ?? 0);

        $sql = "SELECT COUNT(*) c,COALESCE(BIT_XOR(CRC32(CONCAT_WS(CHAR(31),tr.object_id,tt.taxonomy,t.slug,tr.term_order))),0) x,COALESCE(SUM(CRC32(CONCAT_WS(CHAR(31),tr.object_id,tt.taxonomy,t.slug,tr.term_order))),0) s FROM `{$tables['term_relationships']}` tr JOIN `{$tables['term_taxonomy']}` tt ON tt.term_taxonomy_id=tr.term_taxonomy_id JOIN `{$tables['terms']}` t ON t.term_id=tt.term_id JOIN `{$tables['posts']}` p ON p.ID=tr.object_id WHERE " . self::all_managed_taxonomy_sql($mysqli, 'tt') . " AND " . self::post_type_sql($mysqli, 'p') . " AND p.post_status<>'trash'";
        $r = self::rows($mysqli, $sql);
        if (is_wp_error($r)) return $r;
        $pieces['term_relationships'] = ($r[0]['c'] ?? 0) . ':' . ($r[0]['x'] ?? 0) . ':' . ($r[0]['s'] ?? 0);

        $sql = "SELECT COUNT(*) c,COALESCE(BIT_XOR(CRC32(CONCAT_WS(CHAR(31),tm.term_id,tm.meta_key,tm.meta_value))),0) x,COALESCE(SUM(CRC32(CONCAT_WS(CHAR(31),tm.term_id,tm.meta_key,tm.meta_value))),0) s FROM `{$tables['termmeta']}` tm JOIN `{$tables['term_taxonomy']}` tt ON tt.term_id=tm.term_id WHERE " . self::all_managed_taxonomy_sql($mysqli, 'tt') . " AND " . self::termmeta_portable_sql('tm');
        $r = self::rows($mysqli, $sql);
        if (is_wp_error($r)) return $r;
        $pieces['termmeta'] = ($r[0]['c'] ?? 0) . ':' . ($r[0]['x'] ?? 0) . ':' . ($r[0]['s'] ?? 0);

        foreach (self::custom_table_keys() as $key) {
            $digest = self::table_digest($mysqli, $tables[$key], self::managed_custom_where($mysqli, $key));
            if (is_wp_error($digest)) return $digest;
            $pieces[$key] = $digest;
        }

        $prefix = substr($tables['posts'], 0, -strlen('posts'));
        foreach (self::optional_woo_tables($prefix) as $key=>$table) {
            if (!self::table_exists($mysqli, $table)) continue;
            $digest = self::table_digest($mysqli, $table);
            if (is_wp_error($digest)) return $digest;
            $pieces['woo:' . $key] = $digest;
        }
        return hash('sha256', wp_json_encode($pieces));
    }

    /**
     * Audita referencias de PRO que el Clonador necesita poder traducir.
     * Todo se ejecuta durante DRY RUN y no escribe ninguna fila.
     */
    private static function source_integrity_audit($pro, $tables) {
        $conflicts = array();
        $warnings = array();
        $check_count = static function($label, $count) use (&$conflicts) {
            if (is_wp_error($count)) return $count;
            $count = absint($count);
            if ($count > 0) $conflicts[] = $label . ': ' . $count . ' fila(s).';
            return true;
        };

        // Las asignaciones cuyo producto ya no existe son residuos historicos
        // de PRO: se omiten al reconstruir el catalogo activo. Los maestros
        // de atributo/termino rotos si siguen siendo un conflicto real.
        $orphan_product_attrs = self::scalar($pro,
            "SELECT COUNT(*) c
             FROM `{$tables['sql_product_atributos']}` pa
             LEFT JOIN `{$tables['posts']}` p ON p.ID=pa.product_id AND p.post_type='product' AND p.post_status<>'trash'
             WHERE p.ID IS NULL", 'c');
        if (is_wp_error($orphan_product_attrs)) return $orphan_product_attrs;
        $orphan_product_attrs = absint($orphan_product_attrs);
        if ($orphan_product_attrs > 0) {
            $warnings[] = 'PRO contiene ' . $orphan_product_attrs . ' fila(s) huerfanas en sql_product_atributos porque el producto ya no existe/no pertenece al catalogo activo. Se omitiran durante la clonacion.';
        }

        $count = self::scalar($pro,
            "SELECT COUNT(*) c
             FROM `{$tables['sql_product_atributos']}` pa
             JOIN `{$tables['posts']}` p ON p.ID=pa.product_id AND p.post_type='product' AND p.post_status<>'trash'
             LEFT JOIN `{$tables['sql_atributos']}` a ON a.id=pa.atributo_id
             LEFT JOIN `{$tables['sql_atributos_terminos']}` t ON t.id=pa.termino_id
             WHERE a.id IS NULL OR (COALESCE(pa.termino_id,0)>0 AND t.id IS NULL)", 'c');
        $r=$check_count('PRO contiene sql_product_atributos con atributo/termino maestro no resoluble',$count); if(is_wp_error($r))return$r;

        $count = self::scalar($pro,
            "SELECT COUNT(*) c FROM `{$tables['seo_vocabulary']}` v
             LEFT JOIN `{$tables['seo_vocabulary']}` parent ON parent.id=v.parent_id
             WHERE COALESCE(v.parent_id,0)>0 AND parent.id IS NULL", 'c');
        $r=$check_count('PRO contiene vocabulario con parent_id inexistente',$count); if(is_wp_error($r))return$r;

        $count = self::scalar($pro,
            "SELECT COUNT(*) c FROM `{$tables['seo_type_role_map']}` m
             LEFT JOIN `{$tables['seo_vocabulary']}` tv ON tv.id=m.type_vocabulary_id
             LEFT JOIN `{$tables['seo_vocabulary']}` rv ON rv.id=m.role_vocabulary_id
             WHERE tv.id IS NULL OR rv.id IS NULL", 'c');
        $r=$check_count('PRO contiene TIPO->ROL con vocabularios inexistentes',$count); if(is_wp_error($r))return$r;

        $count = self::scalar($pro,
            "SELECT COUNT(*) c FROM `{$tables['sql_atributos_terminos']}` t
             LEFT JOIN `{$tables['sql_atributos']}` a ON a.id=t.atributo_id
             WHERE a.id IS NULL", 'c');
        $r=$check_count('PRO contiene terminos de atributo con atributo inexistente',$count); if(is_wp_error($r))return$r;

        $count = self::scalar($pro,
            "SELECT COUNT(*) c FROM `{$tables['sql_atributos_aliases']}` al
             LEFT JOIN `{$tables['sql_atributos']}` a ON a.id=al.atributo_id
             LEFT JOIN `{$tables['sql_atributos_terminos']}` t ON t.id=al.termino_id
             WHERE a.id IS NULL OR (COALESCE(al.termino_id,0)>0 AND t.id IS NULL)", 'c');
        $r=$check_count('PRO contiene alias de atributos con referencias inexistentes',$count); if(is_wp_error($r))return$r;

        $count = self::scalar($pro,
            "SELECT COUNT(*) c
             FROM `{$tables['seo_object_vocabulary']}` ov
             LEFT JOIN `{$tables['seo_vocabulary']}` v ON v.id=ov.vocabulary_id
             LEFT JOIN `{$tables['posts']}` p ON p.ID=ov.object_id AND p.post_status<>'trash' AND (
                    (ov.object_type='product' AND p.post_type='product') OR
                    (ov.object_type='page' AND p.post_type='page') OR
                    (ov.object_type='post' AND p.post_type='post')
             )
             LEFT JOIN `{$tables['term_taxonomy']}` tt ON ov.object_type='product_cat' AND tt.term_id=ov.object_id AND tt.taxonomy='product_cat'
             WHERE ov.object_type IN ('product','product_cat','page','post')
               AND (v.id IS NULL OR (ov.object_type='product_cat' AND tt.term_taxonomy_id IS NULL) OR (ov.object_type<>'product_cat' AND p.ID IS NULL))", 'c');
        $r=$check_count('PRO contiene seo_object_vocabulary con objeto/vocabulario no resoluble',$count); if(is_wp_error($r))return$r;

        // Los seo_nodes cuyo objeto ya no existe son residuos historicos de PRO.
        // Se omiten al reconstruir el perimetro activo y quedan auditados como warning.
        $orphan_seo_nodes = self::scalar($pro,
            "SELECT COUNT(*) c
             FROM `{$tables['seo_nodes']}` n
             LEFT JOIN `{$tables['posts']}` p ON p.ID=n.object_id AND p.post_status<>'trash' AND (
                    (n.object_type='product' AND p.post_type='product') OR
                    (n.object_type='page' AND p.post_type='page') OR
                    (n.object_type='post' AND p.post_type='post')
             )
             LEFT JOIN `{$tables['term_taxonomy']}` tt ON n.object_type='category' AND tt.term_id=n.object_id AND tt.taxonomy='product_cat'
             WHERE n.object_type IN ('category','product','page','post')
               AND ((n.object_type='category' AND tt.term_taxonomy_id IS NULL) OR (n.object_type<>'category' AND p.ID IS NULL))", 'c');
        if (is_wp_error($orphan_seo_nodes)) return $orphan_seo_nodes;
        $orphan_seo_nodes = absint($orphan_seo_nodes);
        if ($orphan_seo_nodes > 0) {
            $warnings[] = 'PRO contiene ' . $orphan_seo_nodes . ' fila(s) huerfanas en seo_nodes porque el objeto ya no existe/no pertenece al perimetro activo. Se omitiran durante la clonacion.';
        }

        $count = self::scalar($pro,
            "SELECT COUNT(*) c
             FROM `{$tables['seo_faq']}` f
             LEFT JOIN `{$tables['posts']}` p ON p.ID=f.object_id AND p.post_status<>'trash' AND (
                    (f.object_type=1 AND p.post_type='page') OR
                    (f.object_type=3 AND p.post_type='product')
             )
             LEFT JOIN `{$tables['term_taxonomy']}` tt ON f.object_type=2 AND tt.term_id=f.object_id AND tt.taxonomy='product_cat'
             WHERE f.object_type IN (1,2,3)
               AND ((f.object_type=2 AND tt.term_taxonomy_id IS NULL) OR (f.object_type<>2 AND p.ID IS NULL))", 'c');
        $r=$check_count('PRO contiene FAQs con objeto no resoluble',$count); if(is_wp_error($r))return$r;

        // Las relaciones estructurales usan paginas como cluster/hubs/landing,
        // posts para post, productos para product y product_cat para categoria.
        $relations = self::rows($pro,
            "SELECT id,source_type,source_id,target_type,target_id,relation_type
             FROM `{$tables['seo_relations']}` WHERE " . self::managed_custom_where($pro,'seo_relations') . ' ORDER BY id');
        if (is_wp_error($relations)) return $relations;
        $page_list = self::ids($pro,"SELECT ID FROM `{$tables['posts']}` WHERE post_type='page' AND post_status<>'trash'",'ID');
        $post_list = self::ids($pro,"SELECT ID FROM `{$tables['posts']}` WHERE post_type='post' AND post_status<>'trash'",'ID');
        $product_list = self::ids($pro,"SELECT ID FROM `{$tables['posts']}` WHERE post_type='product' AND post_status<>'trash'",'ID');
        $cat_list = self::ids($pro,"SELECT term_id FROM `{$tables['term_taxonomy']}` WHERE taxonomy='product_cat'",'term_id');
        foreach (array($page_list,$post_list,$product_list,$cat_list) as $list) if (is_wp_error($list)) return $list;
        $page_ids = array_fill_keys($page_list,true);
        $post_ids = array_fill_keys($post_list,true);
        $product_ids = array_fill_keys($product_list,true);
        $cat_ids = array_fill_keys($cat_list,true);
        $invalid_relations=0; $samples=array();
        $endpoint_ok = static function($type,$id) use ($page_ids,$post_ids,$product_ids,$cat_ids) {
            $type=sanitize_key((string)$type); $id=absint($id);
            if (!$id) return false;
            if (in_array($type,array('product_cat','category'),true)) return isset($cat_ids[$id]);
            if ('product'===$type) return isset($product_ids[$id]);
            if ('post'===$type) return isset($post_ids[$id]);
            if (in_array($type,array('page','cluster','hub_primary','hub_secondary','hub_secundario','landing','landing_page'),true)) return isset($page_ids[$id]);
            return false;
        };
        foreach ($relations as $row) {
            if (!$endpoint_ok($row['source_type'],$row['source_id']) || !$endpoint_ok($row['target_type'],$row['target_id'])) {
                $invalid_relations++;
                if (count($samples)<20) $samples[]=$row;
            }
        }
        if ($invalid_relations) {
            $conflicts[]='PRO contiene seo_relations que no pueden remapearse: ' . $invalid_relations . ' fila(s).';
        }

        $prefix=substr($tables['posts'],0,-strlen('posts'));
        $attrs_lookup=$prefix.'wc_product_attributes_lookup';
        if (self::table_exists($pro,$attrs_lookup)) {
            $count=self::scalar($pro,
                "SELECT COUNT(*) c FROM `{$attrs_lookup}` l
                 LEFT JOIN `{$tables['posts']}` p ON p.ID=l.product_id AND " . self::post_type_sql($pro,'p') . " AND p.post_status<>'trash'
                 LEFT JOIN `{$tables['posts']}` pp ON pp.ID=l.product_or_parent_id AND " . self::post_type_sql($pro,'pp') . " AND pp.post_status<>'trash'
                 LEFT JOIN `{$tables['term_taxonomy']}` tt ON tt.term_id=l.term_id AND LEFT(tt.taxonomy,3)='pa_'
                 WHERE p.ID IS NULL OR pp.ID IS NULL OR tt.term_taxonomy_id IS NULL", 'c');
            $r=$check_count('PRO contiene wc_product_attributes_lookup con referencias no resolubles',$count); if(is_wp_error($r))return$r;
        }

        return array(
            'conflicts'=>array_values(array_unique($conflicts)),
            'warnings'=>array_values(array_unique($warnings)),
            'relation_invalid_samples'=>$samples,
            'orphan_product_attribute_rows'=>isset($orphan_product_attrs) ? absint($orphan_product_attrs) : 0,
            'orphan_seo_nodes_rows'=>isset($orphan_seo_nodes) ? absint($orphan_seo_nodes) : 0,
        );
    }

    private static function reference_audit($pro, $pro_tables) {
        $remapped = array(
            'wp_posts.post_parent -> ID local STAGING',
            'product_cat.parent -> term_id local STAGING',
            'wp_term_relationships.object_id + term_taxonomy_id -> IDs locales STAGING',
            'wp_postmeta._upsell_ids/_crosssell_ids/_children -> IDs locales STAGING',
            'wp_termmeta.term_id -> term_id local STAGING',
            'seo_object_vocabulary.object_id + vocabulary_id -> IDs locales STAGING',
            'sql_product_atributos.product_id + atributo_id + termino_id -> IDs locales STAGING',
            'seo_nodes.object_id -> ID local STAGING',
            'seo_relations.source_id/target_id -> IDs locales STAGING',
            'seo_faq.object_id -> ID local STAGING',
            'wc_product_meta_lookup.product_id -> ID local STAGING',
            'wc_product_attributes_lookup.product_or_parent_id/product_id/term_id -> IDs locales STAGING',
        );
        $excluded = array(
            '_product_attributes se copia como estructura WooCommerce (no contiene IDs de objeto)',
            'metadatos de imagen/thumbnail/gallery (imagenes excluidas por politica)',
        );
        $known = array();
        foreach (self::remapped_postmeta_keys() as $key) {
            $key_sql = mysqli_real_escape_string($pro, $key);
            $count = self::scalar($pro,
                "SELECT COUNT(*) c FROM `{$pro_tables['postmeta']}` pm JOIN `{$pro_tables['posts']}` p ON p.ID=pm.post_id WHERE " . self::post_type_sql($pro,'p') . " AND p.post_status<>'trash' AND pm.meta_key='{$key_sql}'",
                'c'
            );
            if (is_wp_error($count)) return $count;
            $known[$key]=absint($count);
        }

        // Audita tambien las referencias conocidas (_upsell_ids,
        // _crosssell_ids y _children). Si apuntan a objetos fuera del perimetro
        // (por ejemplo productos eliminados o en papelera), el APPLY las omite
        // de forma segura y las reporta como warning.
        $valid_post_ids = self::ids(
            $pro,
            "SELECT ID FROM `{$pro_tables['posts']}` WHERE " . self::post_type_sql($pro) . " AND post_status<>'trash'",
            'ID'
        );
        if (is_wp_error($valid_post_ids)) return $valid_post_ids;
        $valid_post_set = array_fill_keys(array_map('absint', $valid_post_ids), true);
        $known_keys_sql = "'" . implode("','", array_map(static function($v) use ($pro){ return mysqli_real_escape_string($pro,$v); }, self::remapped_postmeta_keys())) . "'";
        $known_rows = self::rows(
            $pro,
            "SELECT pm.post_id,pm.meta_key,pm.meta_value FROM `{$pro_tables['postmeta']}` pm JOIN `{$pro_tables['posts']}` p ON p.ID=pm.post_id WHERE " . self::post_type_sql($pro,'p') . " AND p.post_status<>'trash' AND pm.meta_key IN ({$known_keys_sql}) ORDER BY pm.meta_id"
        );
        if (is_wp_error($known_rows)) return $known_rows;
        $known_unresolved = 0;
        $known_unresolved_by_key = array();
        $known_unresolved_samples = array();
        foreach ($known_rows as $known_row) {
            $value = maybe_unserialize((string) ($known_row['meta_value'] ?? ''));
            if (!is_array($value)) continue;
            foreach ($value as $source_id) {
                $source_id = absint($source_id);
                if (!$source_id || isset($valid_post_set[$source_id])) continue;
                $key = (string) ($known_row['meta_key'] ?? '');
                $known_unresolved++;
                $known_unresolved_by_key[$key] = absint($known_unresolved_by_key[$key] ?? 0) + 1;
                if (count($known_unresolved_samples) < 25) {
                    $known_unresolved_samples[] = array(
                        'owner_post_id' => absint($known_row['post_id'] ?? 0),
                        'meta_key' => $key,
                        'missing_source_id' => $source_id,
                    );
                }
            }
        }

        $structured_where = "(LEFT(TRIM(pm.meta_value),1) IN ('{','[') OR pm.meta_value REGEXP '^(a|O|s|i|b|d):')";
        $suspicious_key = "LOWER(pm.meta_key) REGEXP '(id|ids|parent|related|relation|category|term|product|post)'";
        $audit_excluded_keys = array_merge(self::remapped_postmeta_keys(), array('_product_attributes'));
        $known_sql = "'" . implode("','", array_map(static function($v) use ($pro){ return mysqli_real_escape_string($pro,$v); }, $audit_excluded_keys)) . "'";
        $count = self::scalar($pro,
            "SELECT COUNT(*) c FROM `{$pro_tables['postmeta']}` pm JOIN `{$pro_tables['posts']}` p ON p.ID=pm.post_id WHERE " . self::post_type_sql($pro,'p') . " AND p.post_status<>'trash' AND " . self::meta_portable_sql('pm') . " AND {$structured_where} AND {$suspicious_key} AND pm.meta_key NOT IN ({$known_sql})",
            'c'
        );
        if (is_wp_error($count)) return $count;
        $samples = self::rows($pro,
            "SELECT pm.post_id,pm.meta_key,LEFT(pm.meta_value,220) sample FROM `{$pro_tables['postmeta']}` pm JOIN `{$pro_tables['posts']}` p ON p.ID=pm.post_id WHERE " . self::post_type_sql($pro,'p') . " AND p.post_status<>'trash' AND " . self::meta_portable_sql('pm') . " AND {$structured_where} AND {$suspicious_key} AND pm.meta_key NOT IN ({$known_sql}) ORDER BY pm.meta_id LIMIT 100"
        );
        if (is_wp_error($samples)) return $samples;
        $warnings=array();
        if (absint($count)>0) {
            $warnings[]='Se detectaron '.absint($count).' postmeta estructurados/JSON con nombres que sugieren referencias a IDs y que no tienen remapeo especifico. Revisa las muestras del DRY RUN antes de APPLY.';
        }
        if ($known_unresolved > 0) {
            $warnings[]='Se omitiran '.absint($known_unresolved).' referencias de _upsell_ids/_crosssell_ids/_children que apuntan a objetos PRO fuera del perimetro clonado.';
        }
        return array(
            'remapped' => $remapped,
            'excluded' => $excluded,
            'known_postmeta_reference_rows' => $known,
            'known_postmeta_unresolved_references' => absint($known_unresolved),
            'known_postmeta_unresolved_by_key' => $known_unresolved_by_key,
            'known_postmeta_unresolved_samples' => $known_unresolved_samples,
            'opaque_reference_count' => absint($count),
            'opaque_reference_samples' => $samples,
            'warnings' => $warnings,
        );
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
        foreach ($stg_tables as $table) {
            $engine = self::table_engine($stg, $table);
            if ($engine && 'INNODB' !== $engine) {
                $conflicts[] = 'La tabla STAGING ' . $table . ' usa ' . $engine . '; se requiere InnoDB para poder hacer ROLLBACK.';
            }
        }

        $pro_prefix = substr($pro_tables['posts'], 0, -strlen('posts'));
        $stg_prefix = substr($stg_tables['posts'], 0, -strlen('posts'));
        $pro_optional = self::optional_woo_tables($pro_prefix);
        $stg_optional = self::optional_woo_tables($stg_prefix);
        $optional_presence = array();
        foreach ($pro_optional as $key => $pro_table) {
            $pro_exists = self::table_exists($pro, $pro_table);
            $stg_exists = self::table_exists($stg, $stg_optional[$key]);
            $optional_presence[$key] = array('pro'=>$pro_exists, 'staging'=>$stg_exists);
            if ($pro_exists !== $stg_exists) {
                $conflicts[] = 'La tabla opcional WooCommerce ' . $key . ' no existe en ambos entornos. PRO=' . ($pro_exists?'si':'no') . ', STAGING=' . ($stg_exists?'si':'no') . '.';
                continue;
            }
            if ($stg_exists) {
                $engine = self::table_engine($stg, $stg_optional[$key]);
                $src_engine_count = self::count_table($pro, $pro_table);
                $dst_engine_count = self::count_table($stg, $stg_optional[$key]);
                if (is_wp_error($src_engine_count)) return $src_engine_count;
                if (is_wp_error($dst_engine_count)) return $dst_engine_count;
                if ($engine && 'INNODB' !== $engine && (absint($src_engine_count) > 0 || absint($dst_engine_count) > 0)) {
                    $conflicts[] = 'La tabla STAGING ' . $stg_optional[$key] . ' usa ' . $engine . '; se requiere InnoDB porque contiene datos que deben modificarse.';
                } elseif ($engine && 'INNODB' !== $engine) {
                    $warnings[] = 'La tabla STAGING ' . $stg_optional[$key] . ' usa ' . $engine . ', pero esta vacia en ambos entornos y el Clonador no la modificara.';
                }
                $pro_columns = self::table_columns($pro, $pro_table);
                $stg_columns = self::table_columns($stg, $stg_optional[$key]);
                if (is_wp_error($pro_columns)) return $pro_columns;
                if (is_wp_error($stg_columns)) return $stg_columns;
                if ($pro_columns !== $stg_columns) {
                    $conflicts[] = 'PRO y STAGING no tienen el mismo esquema de columnas en ' . $key . '.';
                }
            }
        }

        $summary = array();
        $actions = array('objects'=>array(), 'taxonomies'=>array(), 'custom_tables'=>array(), 'woocommerce'=>array());
        foreach (self::$post_types as $type) {
            $type_sql_pro = mysqli_real_escape_string($pro, $type);
            $type_sql_stg = mysqli_real_escape_string($stg, $type);
            $src = self::count_table($pro, $pro_tables['posts'], "post_type='{$type_sql_pro}' AND post_status<>'trash'");
            $dst = self::count_table($stg, $stg_tables['posts'], "post_type='{$type_sql_stg}' AND post_status<>'trash'");
            if (is_wp_error($src)) return $src;
            if (is_wp_error($dst)) return $dst;
            $summary[$type . '_pro'] = $src;
            $summary[$type . '_staging'] = $dst;
            $actions['objects'][$type] = array('source'=>$src, 'staging_before'=>$dst, 'delete_from_staging'=>$dst, 'create_from_pro'=>$src);
        }

        $src_tax_rows = self::rows($pro, "SELECT taxonomy,COUNT(*) c FROM `{$pro_tables['term_taxonomy']}` WHERE " . self::all_managed_taxonomy_sql($pro) . ' GROUP BY taxonomy ORDER BY taxonomy');
        $dst_tax_rows = self::rows($stg, "SELECT taxonomy,COUNT(*) c FROM `{$stg_tables['term_taxonomy']}` WHERE " . self::all_managed_taxonomy_sql($stg) . ' GROUP BY taxonomy ORDER BY taxonomy');
        if (is_wp_error($src_tax_rows)) return $src_tax_rows;
        if (is_wp_error($dst_tax_rows)) return $dst_tax_rows;
        $src_tax = array(); $dst_tax = array();
        foreach ($src_tax_rows as $row) $src_tax[(string)$row['taxonomy']] = absint($row['c']);
        foreach ($dst_tax_rows as $row) $dst_tax[(string)$row['taxonomy']] = absint($row['c']);
        $taxonomies = array_values(array_unique(array_merge(array_keys($src_tax), array_keys($dst_tax), self::$taxonomies)));
        sort($taxonomies, SORT_STRING);
        foreach ($taxonomies as $taxonomy) {
            $src = absint($src_tax[$taxonomy] ?? 0);
            $dst = absint($dst_tax[$taxonomy] ?? 0);
            $summary[$taxonomy . '_pro'] = $src;
            $summary[$taxonomy . '_staging'] = $dst;
            $actions['taxonomies'][$taxonomy] = array('source'=>$src, 'staging_before'=>$dst, 'delete_from_staging'=>$dst, 'create_from_pro'=>$src);
        }

        foreach (self::custom_table_keys() as $key) {
            $src_where = self::managed_custom_where($pro, $key);
            $dst_where = self::managed_custom_where($stg, $key);
            $src = self::count_table($pro, $pro_tables[$key], $src_where);
            $dst = self::count_table($stg, $stg_tables[$key], $dst_where);
            if (is_wp_error($src)) return $src;
            if (is_wp_error($dst)) return $dst;
            $summary[$key . '_pro'] = $src;
            $summary[$key . '_staging'] = $dst;
            $actions['custom_tables'][$key] = array('source'=>$src, 'staging_before'=>$dst, 'delete_from_staging'=>$dst, 'create_from_pro'=>$src);
        }

        foreach ($pro_optional as $key => $pro_table) {
            if (empty($optional_presence[$key]['pro']) || empty($optional_presence[$key]['staging'])) {
                $actions['woocommerce'][$key] = array('available'=>false, 'source'=>0, 'staging_before'=>0, 'delete_from_staging'=>0, 'create_from_pro'=>0);
                continue;
            }
            $stg_table = $stg_optional[$key];
            if ('woocommerce_attribute_taxonomies' === $key) {
                $src = self::count_table($pro, $pro_table);
                $dst = self::count_table($stg, $stg_table);
            } elseif ('wc_product_meta_lookup' === $key) {
                $src = self::scalar($pro, "SELECT COUNT(*) c FROM `{$pro_table}` l JOIN `{$pro_tables['posts']}` p ON p.ID=l.product_id WHERE " . self::post_type_sql($pro, 'p') . " AND p.post_status<>'trash'", 'c');
                $dst = self::scalar($stg, "SELECT COUNT(*) c FROM `{$stg_table}` l JOIN `{$stg_tables['posts']}` p ON p.ID=l.product_id WHERE " . self::post_type_sql($stg, 'p') . " AND p.post_status<>'trash'", 'c');
            } else {
                $src = self::scalar($pro, "SELECT COUNT(*) c FROM `{$pro_table}` l JOIN `{$pro_tables['posts']}` p ON p.ID=l.product_id WHERE " . self::post_type_sql($pro, 'p') . " AND p.post_status<>'trash'", 'c');
                $dst = self::scalar($stg, "SELECT COUNT(*) c FROM `{$stg_table}` l JOIN `{$stg_tables['posts']}` p ON p.ID=l.product_id WHERE " . self::post_type_sql($stg, 'p') . " AND p.post_status<>'trash'", 'c');
            }
            if (is_wp_error($src)) return $src;
            if (is_wp_error($dst)) return $dst;
            $src = absint($src); $dst = absint($dst);
            $actions['woocommerce'][$key] = array('available'=>true, 'source'=>$src, 'staging_before'=>$dst, 'delete_from_staging'=>$dst, 'create_from_pro'=>$src);
        }

        $source_integrity = self::source_integrity_audit($pro, $pro_tables);
        if (is_wp_error($source_integrity)) return $source_integrity;
        $conflicts = array_merge($conflicts, (array) ($source_integrity['conflicts'] ?? array()));
        $warnings = array_merge($warnings, (array) ($source_integrity['warnings'] ?? array()));
        $orphan_product_attribute_rows = absint($source_integrity['orphan_product_attribute_rows'] ?? 0);
        if (isset($actions['custom_tables']['sql_product_atributos'])) {
            $actions['custom_tables']['sql_product_atributos']['create_from_pro'] = max(0, absint($actions['custom_tables']['sql_product_atributos']['source']) - $orphan_product_attribute_rows);
            $actions['custom_tables']['sql_product_atributos']['omitted_orphans'] = $orphan_product_attribute_rows;
        }
        $orphan_seo_nodes_rows = absint($source_integrity['orphan_seo_nodes_rows'] ?? 0);
        if (isset($actions['custom_tables']['seo_nodes'])) {
            $actions['custom_tables']['seo_nodes']['create_from_pro'] = max(0, absint($actions['custom_tables']['seo_nodes']['source']) - $orphan_seo_nodes_rows);
            $actions['custom_tables']['seo_nodes']['omitted_orphans'] = $orphan_seo_nodes_rows;
        }

        $reference_audit = self::reference_audit($pro, $pro_tables);
        if (is_wp_error($reference_audit)) return $reference_audit;
        $warnings = array_merge($warnings, (array) ($reference_audit['warnings'] ?? array()));
        // Metadatos opacos como b2s_post_meta se conservan literalmente y
        // quedan auditados como warning; no bloquean el clon destructivo.

        $source_marker = self::environment_marker($pro, $pro_tables);
        if (is_wp_error($source_marker)) return $source_marker;
        $target_marker = self::environment_marker($stg, $stg_tables);
        if (is_wp_error($target_marker)) return $target_marker;

        return array(
            'identity' => $identity,
            'summary' => $summary,
            'actions' => $actions,
            'identity_resolution' => array(
                'mode' => 'full_reset',
                'matching_existing_staging' => false,
                'source_numeric_ids' => 'transient_map_keys_only',
                'destination_ids' => 'new_local_staging_ids',
                'note' => 'STAGING se vacia dentro del perimetro gestionado. Los IDs de PRO solo se usan para construir mapas temporales durante la clonacion.',
            ),
            'source_integrity' => $source_integrity,
            'reference_audit' => $reference_audit,
            'conflicts' => array_values(array_unique($conflicts)),
            'warnings' => array_values(array_unique($warnings)),
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
        if (!$table_id) return new WP_Error('clonador_table', 'Tabla de destino no valida.');
        $col_sql = array();
        foreach ($columns as $column) {
            $id = self::ident($column);
            if (!$id) return new WP_Error('clonador_column', 'Columna no valida: ' . $column);
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

    private static function delete_ids($stg, $table, $column, $ids) {
        $table_id = self::ident($table);
        $column_id = self::ident($column);
        if (!$table_id || !$column_id) return new WP_Error('clonador_identifier', 'Identificador SQL no valido.');
        foreach (self::id_chunks($ids) as $chunk) {
            $r = self::exec($stg, "DELETE FROM {$table_id} WHERE {$column_id} IN (" . self::sql_ids($chunk) . ')');
            if (is_wp_error($r)) return $r;
        }
        return true;
    }

    private static function clone_posts($pro, $stg, $pro_tables, $stg_tables, &$stats) {
        // STAGING ya ha sido vaciado dentro del perimetro. No se intenta
        // emparejar objetos existentes: se crean IDs locales nuevos y se usa
        // el ID de PRO exclusivamente como clave temporal del mapa.
        $map = array();

        $source_columns = array(
            'ID','post_date','post_date_gmt','post_content','post_title','post_excerpt','post_status',
            'comment_status','ping_status','post_password','post_name','to_ping','pinged','post_modified',
            'post_modified_gmt','post_content_filtered','post_parent','menu_order','post_type','post_mime_type'
        );
        $columns_sql = implode(',', array_map(array(__CLASS__, 'ident'), $source_columns));
        $where = self::post_type_sql($pro) . " AND post_status<>'trash'";
        $cursor = 0;
        $copied = 0;
        $parents = array();
        while (true) {
            $rows = self::rows($pro, "SELECT {$columns_sql} FROM `{$pro_tables['posts']}` WHERE {$where} AND ID>{$cursor} ORDER BY ID ASC LIMIT 250");
            if (is_wp_error($rows)) return $rows;
            if (!$rows) break;
            foreach ($rows as $row) {
                $sid = absint($row['ID']);
                $data = $row;
                unset($data['ID'], $data['post_parent']);
                // Los IDs son locales. Nunca se inserta el ID de PRO.
                $insert_columns = array_keys($data);
                $insert_values = array_values($data);
                $insert_columns[] = 'post_author'; $insert_values[] = 0;
                $insert_columns[] = 'post_parent'; $insert_values[] = 0;
                $insert_columns[] = 'guid'; $insert_values[] = '';
                $col_sql = implode(',', array_map(array(__CLASS__, 'ident'), $insert_columns));
                $val_sql = implode(',', array_map(static function($v) use ($stg){ return self::sql_value($stg, $v); }, $insert_values));
                $r = self::exec($stg, "INSERT INTO `{$stg_tables['posts']}` ({$col_sql}) VALUES ({$val_sql})");
                if (is_wp_error($r)) return $r;
                $target_id = absint(mysqli_insert_id($stg));
                if (!$target_id) return new WP_Error('clone_post_insert_id', 'STAGING no devolvio un ID local al crear un objeto.');
                $map[$sid] = $target_id;
                $parents[$sid] = absint($row['post_parent'] ?? 0);
                $copied++;
            }
            self::progress('posts_objects', 'Objetos clonados: ' . $copied . '.', $stats);
            if (function_exists('usleep')) @usleep(15000);
            $cursor = absint(end($rows)['ID'] ?? $cursor);
            if (count($rows) < 250) break;
        }

        // Jerarquia: post_parent se remapea al ID local de STAGING.
        foreach ($parents as $sid=>$source_parent) {
            $target_id = absint($map[$sid] ?? 0);
            if (!$target_id) continue;
            $target_parent = $source_parent && isset($map[$source_parent]) ? absint($map[$source_parent]) : 0;
            $r = self::exec($stg, "UPDATE `{$stg_tables['posts']}` SET post_parent={$target_parent} WHERE ID={$target_id}");
            if (is_wp_error($r)) return $r;
        }
        $stats['posts'] = $copied;

        // Metadatos portables: se reemplazan usando los IDs locales resueltos.
        $target_mapped_ids = array_values(array_unique(array_filter(array_map('absint', array_values($map)))));
        $meta_filter = self::meta_portable_sql('pm');
        foreach (self::id_chunks($target_mapped_ids) as $chunk) {
            $r = self::exec($stg, "DELETE FROM `{$stg_tables['postmeta']}` WHERE post_id IN (" . self::sql_ids($chunk) . ") AND " . self::meta_portable_sql());
            if (is_wp_error($r)) return $r;
        }
        $cursor = 0;
        $copied_meta = 0;
        while (true) {
            $rows = self::rows($pro,
                "SELECT pm.meta_id,pm.post_id,pm.meta_key,pm.meta_value
                 FROM `{$pro_tables['postmeta']}` pm JOIN `{$pro_tables['posts']}` p ON p.ID=pm.post_id
                 WHERE " . self::post_type_sql($pro, 'p') . " AND p.post_status<>'trash' AND " . self::meta_portable_sql('pm') . " AND pm.meta_id>{$cursor}
                 ORDER BY pm.meta_id ASC LIMIT 500"
            );
            if (is_wp_error($rows)) return $rows;
            if (!$rows) break;
            $insert = array();
            foreach ($rows as $row) {
                $sid = absint($row['post_id']);
                $target_id = absint($map[$sid] ?? 0);
                if (!$target_id) continue;
                $meta_value = self::remap_postmeta_value((string)$row['meta_key'], (string)$row['meta_value'], $map, $stats);
                if (is_wp_error($meta_value)) return $meta_value;
                $insert[] = array('post_id'=>$target_id,'meta_key'=>(string)$row['meta_key'],'meta_value'=>(string)$meta_value);
            }
            foreach (array_chunk($insert, 120) as $chunk) {
                $r = self::insert_rows($stg, $stg_tables['postmeta'], array('post_id','meta_key','meta_value'), $chunk, false);
                if (is_wp_error($r)) return $r;
            }
            $copied_meta += count($insert);
            self::progress('postmeta', 'Metadatos portables clonados: ' . $copied_meta . '.', $stats);
            if (function_exists('usleep')) @usleep(15000);
            $cursor = absint(end($rows)['meta_id'] ?? $cursor);
            if (count($rows) < 500) break;
        }
        $stats['postmeta'] = $copied_meta;
        return $map;
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

    private static function clone_taxonomies($pro, $stg, $pro_tables, $stg_tables, $post_map, &$stats) {
        // STAGING ya no contiene taxonomias gestionadas. Se reconstruyen desde
        // PRO y cada term_id/term_taxonomy_id de origen se convierte en un ID
        // local nuevo de STAGING.
        $rows = self::rows(
            $pro,
            "SELECT t.term_id,t.name,t.slug,t.term_group,tt.term_taxonomy_id,tt.taxonomy,tt.description,tt.parent,tt.count
             FROM `{$pro_tables['terms']}` t
             JOIN `{$pro_tables['term_taxonomy']}` tt ON tt.term_id=t.term_id
             WHERE " . self::taxonomy_sql($pro, 'tt') . "
             ORDER BY tt.taxonomy,t.term_id,tt.term_taxonomy_id"
        );
        if (is_wp_error($rows)) return $rows;

        $term_map = array();
        $tt_map = array();
        $category_term_map = array();
        $tag_term_maps = array('product_tag'=>array(), 'post_tag'=>array());
        $parents = array();
        $counts = array_fill_keys(self::$taxonomies, 0);

        foreach ($rows as $row) {
            $source_term = absint($row['term_id'] ?? 0);
            $source_tt = absint($row['term_taxonomy_id'] ?? 0);
            $taxonomy = sanitize_key((string) ($row['taxonomy'] ?? ''));
            if (!$source_term || !$source_tt || !in_array($taxonomy, self::$taxonomies, true)) continue;

            $target_term = absint($term_map[$source_term] ?? 0);
            if (!$target_term) {
                $r = self::exec(
                    $stg,
                    "INSERT INTO `{$stg_tables['terms']}` (name,slug,term_group) VALUES (" .
                    self::sql_value($stg, $row['name']) . ',' . self::sql_value($stg, $row['slug']) . ',' . absint($row['term_group']) . ')'
                );
                if (is_wp_error($r)) return $r;
                $target_term = absint(mysqli_insert_id($stg));
                if (!$target_term) return new WP_Error('clonador_term_insert', 'STAGING no devolvio term_id al clonar una taxonomia.');
                $term_map[$source_term] = $target_term;
            }

            $tax_sql = mysqli_real_escape_string($stg, $taxonomy);
            $r = self::exec(
                $stg,
                "INSERT INTO `{$stg_tables['term_taxonomy']}` (term_id,taxonomy,description,parent,count) VALUES (" .
                $target_term . ",'{$tax_sql}'," . self::sql_value($stg, $row['description']) . ',0,0)'
            );
            if (is_wp_error($r)) return $r;
            $target_tt = absint(mysqli_insert_id($stg));
            if (!$target_tt) return new WP_Error('clonador_tt_insert', 'STAGING no devolvio term_taxonomy_id al clonar una taxonomia.');

            $tt_map[$source_tt] = $target_tt;
            $parents[$source_tt] = absint($row['parent'] ?? 0);
            if ('product_cat' === $taxonomy) {
                $category_term_map[$source_term] = $target_term;
            } else {
                $tag_term_maps[$taxonomy][$source_term] = $target_term;
            }
            $counts[$taxonomy] = absint($counts[$taxonomy] ?? 0) + 1;
        }

        foreach ($parents as $source_tt => $source_parent_term) {
            $target_tt = absint($tt_map[$source_tt] ?? 0);
            $target_parent = $source_parent_term ? absint($term_map[$source_parent_term] ?? 0) : 0;
            if ($source_parent_term && !$target_parent) {
                return new WP_Error('clonador_taxonomy_parent', 'No se pudo remapear el padre term_id PRO #' . $source_parent_term . '.');
            }
            $r = self::exec($stg, "UPDATE `{$stg_tables['term_taxonomy']}` SET parent={$target_parent} WHERE term_taxonomy_id={$target_tt}");
            if (is_wp_error($r)) return $r;
        }

        $r = self::copy_termmeta_mapped($pro, $stg, $pro_tables, $stg_tables, $term_map);
        if (is_wp_error($r)) return $r;

        $rel_rows = self::rows(
            $pro,
            "SELECT tr.object_id,tr.term_taxonomy_id,tr.term_order
             FROM `{$pro_tables['term_relationships']}` tr
             JOIN `{$pro_tables['term_taxonomy']}` tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
             JOIN `{$pro_tables['posts']}` p ON p.ID=tr.object_id
             WHERE " . self::taxonomy_sql($pro, 'tt') . "
               AND " . self::post_type_sql($pro, 'p') . "
               AND p.post_status<>'trash'
             ORDER BY tr.object_id,tr.term_taxonomy_id"
        );
        if (is_wp_error($rel_rows)) return $rel_rows;

        $insert = array();
        $copied_rel = 0;
        foreach ($rel_rows as $row) {
            $target_object = absint($post_map[absint($row['object_id'])] ?? 0);
            $target_tt = absint($tt_map[absint($row['term_taxonomy_id'])] ?? 0);
            if (!$target_object || !$target_tt) {
                return new WP_Error('clonador_relationship_map', 'No se pudo remapear una relacion de taxonomia a IDs locales de STAGING.');
            }
            $insert[] = array(
                'object_id'=>$target_object,
                'term_taxonomy_id'=>$target_tt,
                'term_order'=>(int) $row['term_order'],
            );
            if (count($insert) >= 200) {
                $r = self::insert_rows($stg, $stg_tables['term_relationships'], array('object_id','term_taxonomy_id','term_order'), $insert, false);
                if (is_wp_error($r)) return $r;
                $copied_rel += count($insert);
                $insert = array();
            }
        }
        if ($insert) {
            $r = self::insert_rows($stg, $stg_tables['term_relationships'], array('object_id','term_taxonomy_id','term_order'), $insert, false);
            if (is_wp_error($r)) return $r;
            $copied_rel += count($insert);
        }

        $r = self::exec(
            $stg,
            "UPDATE `{$stg_tables['term_taxonomy']}` tt
             SET tt.count=(SELECT COUNT(*) FROM `{$stg_tables['term_relationships']}` tr WHERE tr.term_taxonomy_id=tt.term_taxonomy_id)
             WHERE " . self::taxonomy_sql($stg, 'tt')
        );
        if (is_wp_error($r)) return $r;

        foreach ($counts as $taxonomy=>$count) $stats[$taxonomy] = $count;
        $stats['term_relationships'] = $copied_rel;
        return array(
            'category_term_map'=>$category_term_map,
            'tag_term_maps'=>$tag_term_maps,
            'term_map'=>$term_map,
            'tt_map'=>$tt_map,
        );
    }


    /**
     * Clona taxonomias WordPress/WooCommerce adicionales al nucleo historico:
     * category, product_type, product_visibility, product_shipping_class y pa_*.
     * Los term_id/term_taxonomy_id de PRO solo se usan como claves temporales.
     */
    private static function clone_extra_taxonomies($pro, $stg, $pro_tables, $stg_tables, $post_map, $term_map, $tt_map, &$stats) {
        $rows = self::rows(
            $pro,
            "SELECT t.term_id,t.name,t.slug,t.term_group,tt.term_taxonomy_id,tt.taxonomy,tt.description,tt.parent,tt.count
             FROM `{$pro_tables['terms']}` t
             JOIN `{$pro_tables['term_taxonomy']}` tt ON tt.term_id=t.term_id
             WHERE " . self::extra_taxonomy_sql($pro, 'tt') . "
             ORDER BY tt.taxonomy,t.term_id,tt.term_taxonomy_id"
        );
        if (is_wp_error($rows)) return $rows;

        $parents = array();
        foreach ($rows as $row) {
            $source_term = absint($row['term_id'] ?? 0);
            $source_tt   = absint($row['term_taxonomy_id'] ?? 0);
            if (!$source_term || !$source_tt) continue;

            $target_term = absint($term_map[$source_term] ?? 0);
            if (!$target_term) {
                $r = self::exec(
                    $stg,
                    "INSERT INTO `{$stg_tables['terms']}` (name,slug,term_group) VALUES (" .
                    self::sql_value($stg, $row['name']) . ',' .
                    self::sql_value($stg, $row['slug']) . ',' . absint($row['term_group']) . ')'
                );
                if (is_wp_error($r)) return $r;
                $target_term = absint(mysqli_insert_id($stg));
                if (!$target_term) return new WP_Error('clonador_term_insert', 'STAGING no devolvio term_id al clonar una taxonomia.');
                $term_map[$source_term] = $target_term;
            }

            $taxonomy = mysqli_real_escape_string($stg, (string) $row['taxonomy']);
            $r = self::exec(
                $stg,
                "INSERT INTO `{$stg_tables['term_taxonomy']}` (term_id,taxonomy,description,parent,count) VALUES (" .
                $target_term . ",'{$taxonomy}'," . self::sql_value($stg, $row['description']) . ',0,0)'
            );
            if (is_wp_error($r)) return $r;
            $target_tt = absint(mysqli_insert_id($stg));
            if (!$target_tt) return new WP_Error('clonador_tt_insert', 'STAGING no devolvio term_taxonomy_id al clonar una taxonomia.');
            $tt_map[$source_tt] = $target_tt;
            $parents[$source_tt] = absint($row['parent'] ?? 0);
        }

        foreach ($parents as $source_tt => $source_parent_term) {
            $target_tt = absint($tt_map[$source_tt] ?? 0);
            if (!$target_tt) continue;
            $target_parent = $source_parent_term ? absint($term_map[$source_parent_term] ?? 0) : 0;
            if ($source_parent_term && !$target_parent) {
                return new WP_Error('clonador_taxonomy_parent', 'No se pudo remapear el padre term_id PRO #' . $source_parent_term . '.');
            }
            $r = self::exec($stg, "UPDATE `{$stg_tables['term_taxonomy']}` SET parent={$target_parent} WHERE term_taxonomy_id={$target_tt}");
            if (is_wp_error($r)) return $r;
        }

        $r = self::copy_termmeta_mapped($pro, $stg, $pro_tables, $stg_tables, $term_map);
        if (is_wp_error($r)) return $r;

        $rel_rows = self::rows(
            $pro,
            "SELECT tr.object_id,tr.term_taxonomy_id,tr.term_order
             FROM `{$pro_tables['term_relationships']}` tr
             JOIN `{$pro_tables['term_taxonomy']}` tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
             JOIN `{$pro_tables['posts']}` p ON p.ID=tr.object_id
             WHERE " . self::extra_taxonomy_sql($pro, 'tt') . "
               AND " . self::post_type_sql($pro, 'p') . "
               AND p.post_status<>'trash'
             ORDER BY tr.object_id,tr.term_taxonomy_id"
        );
        if (is_wp_error($rel_rows)) return $rel_rows;

        $insert = array();
        $copied = 0;
        foreach ($rel_rows as $row) {
            $target_object = absint($post_map[absint($row['object_id'])] ?? 0);
            $target_tt = absint($tt_map[absint($row['term_taxonomy_id'])] ?? 0);
            if (!$target_object || !$target_tt) {
                return new WP_Error('clonador_extra_relationship', 'No se pudo remapear una relacion de taxonomia WooCommerce/WordPress.');
            }
            $insert[] = array(
                'object_id' => $target_object,
                'term_taxonomy_id' => $target_tt,
                'term_order' => (int) $row['term_order'],
            );
            if (count($insert) >= 200) {
                $r = self::insert_rows($stg, $stg_tables['term_relationships'], array('object_id','term_taxonomy_id','term_order'), $insert, false);
                if (is_wp_error($r)) return $r;
                $copied += count($insert);
                $insert = array();
            }
        }
        if ($insert) {
            $r = self::insert_rows($stg, $stg_tables['term_relationships'], array('object_id','term_taxonomy_id','term_order'), $insert, false);
            if (is_wp_error($r)) return $r;
            $copied += count($insert);
        }

        $r = self::exec(
            $stg,
            "UPDATE `{$stg_tables['term_taxonomy']}` tt
             SET tt.count=(SELECT COUNT(*) FROM `{$stg_tables['term_relationships']}` tr WHERE tr.term_taxonomy_id=tt.term_taxonomy_id)
             WHERE " . self::extra_taxonomy_sql($stg, 'tt')
        );
        if (is_wp_error($r)) return $r;

        $stats['extra_taxonomies'] = count($rows);
        $stats['extra_term_relationships'] = $copied;
        return array('term_map'=>$term_map, 'tt_map'=>$tt_map);
    }

    private static function semantic_key($group, $slug) {
        return sanitize_key((string)$group) . '|' . sanitize_title((string)$slug);
    }

    private static function attribute_term_key($attribute_slug, $term_slug) {
        return sanitize_title((string)$attribute_slug) . '|' . sanitize_title((string)$term_slug);
    }

    private static function clone_semantic_tables($pro, $stg, $pro_tables, $stg_tables, $post_map, $category_map, &$stats) {
        // 1) Vocabulario maestro por semantic_group + slug.
        $src_vocab=self::rows($pro,"SELECT id,semantic_group,slug,label,parent_id,source,active,created_at,updated_at FROM `{$pro_tables['seo_vocabulary']}` ORDER BY id");
        $dst_vocab=self::rows($stg,"SELECT id,semantic_group,slug,label,parent_id,source,active,created_at,updated_at FROM `{$stg_tables['seo_vocabulary']}` ORDER BY id");
        if(is_wp_error($src_vocab))return$src_vocab;if(is_wp_error($dst_vocab))return$dst_vocab;
        $dst_by_key=array();foreach($dst_vocab as $r){$k=self::semantic_key($r['semantic_group'],$r['slug']);if(isset($dst_by_key[$k]))return new WP_Error('clone_vocab_duplicate','Vocabulario duplicado en STAGING: '.$k);$dst_by_key[$k]=$r;}
        $src_keys=array();$vmap=array();$parents=array();
        foreach($src_vocab as $r){$k=self::semantic_key($r['semantic_group'],$r['slug']);if(!$k||'|'===$k)return new WP_Error('clone_vocab_key','Vocabulario PRO sin clave portable.');if(isset($src_keys[$k]))return new WP_Error('clone_vocab_duplicate_pro','Vocabulario duplicado en PRO: '.$k);$src_keys[$k]=1;$sid=absint($r['id']);if(isset($dst_by_key[$k])){$tid=absint($dst_by_key[$k]['id']);$q="UPDATE `{$stg_tables['seo_vocabulary']}` SET semantic_group=".self::sql_value($stg,$r['semantic_group']).",slug=".self::sql_value($stg,$r['slug']).",label=".self::sql_value($stg,$r['label']).",source=".self::sql_value($stg,$r['source']).",active=".absint($r['active']).",updated_at=".self::sql_value($stg,$r['updated_at'])." WHERE id={$tid}";$x=self::exec($stg,$q);if(is_wp_error($x))return$x;}else{$q="INSERT INTO `{$stg_tables['seo_vocabulary']}` (semantic_group,slug,label,parent_id,source,active,created_at,updated_at) VALUES (".self::sql_value($stg,$r['semantic_group']).','.self::sql_value($stg,$r['slug']).','.self::sql_value($stg,$r['label']).",NULL,".self::sql_value($stg,$r['source']).','.absint($r['active']).','.self::sql_value($stg,$r['created_at']).','.self::sql_value($stg,$r['updated_at']).')';$x=self::exec($stg,$q);if(is_wp_error($x))return$x;$tid=absint(mysqli_insert_id($stg));}$vmap[$sid]=$tid;$parents[$sid]=absint($r['parent_id']??0);}
        foreach($parents as $sid=>$sp){$tid=absint($vmap[$sid]??0);$tp=$sp?absint($vmap[$sp]??0):0;$x=self::exec($stg,"UPDATE `{$stg_tables['seo_vocabulary']}` SET parent_id=".($tp?$tp:'NULL')." WHERE id={$tid}");if(is_wp_error($x))return$x;}

        // 2) Atributos por slug; terminos por attribute_slug + term_slug.
        $src_attr=self::rows($pro,"SELECT * FROM `{$pro_tables['sql_atributos']}` ORDER BY id");$dst_attr=self::rows($stg,"SELECT * FROM `{$stg_tables['sql_atributos']}` ORDER BY id");if(is_wp_error($src_attr))return$src_attr;if(is_wp_error($dst_attr))return$dst_attr;
        $dst_attr_by=array();foreach($dst_attr as $r){$k=sanitize_title((string)$r['slug']);if(isset($dst_attr_by[$k]))return new WP_Error('clone_attr_duplicate','Atributo duplicado en STAGING: '.$k);$dst_attr_by[$k]=$r;}
        $attr_map=array();$src_attr_keys=array();$attr_cols=array('slug','nombre','grupo','tipo','unidad_tipo','unidad_base','multiple','filtrable','visible','seo','orden','activo','created_at','updated_at');
        foreach($src_attr as $r){$k=sanitize_title((string)$r['slug']);if(!$k)return new WP_Error('clone_attr_key','Atributo PRO sin slug.');if(isset($src_attr_keys[$k]))return new WP_Error('clone_attr_duplicate_pro','Atributo duplicado en PRO: '.$k);$src_attr_keys[$k]=1;$sid=absint($r['id']);if(isset($dst_attr_by[$k])){$tid=absint($dst_attr_by[$k]['id']);$sets=array();foreach($attr_cols as $c)$sets[]=self::ident($c).'='.self::sql_value($stg,$r[$c]??null);$x=self::exec($stg,"UPDATE `{$stg_tables['sql_atributos']}` SET ".implode(',',$sets)." WHERE id={$tid}");if(is_wp_error($x))return$x;}else{$data=array();foreach($attr_cols as $c)$data[$c]=$r[$c]??null;$x=self::insert_rows($stg,$stg_tables['sql_atributos'],$attr_cols,array($data),false);if(is_wp_error($x))return$x;$tid=absint(mysqli_insert_id($stg));}$attr_map[$sid]=$tid;}
        $src_terms=self::rows($pro,"SELECT t.*,a.slug attribute_slug FROM `{$pro_tables['sql_atributos_terminos']}` t JOIN `{$pro_tables['sql_atributos']}` a ON a.id=t.atributo_id ORDER BY t.id");$dst_terms=self::rows($stg,"SELECT t.*,a.slug attribute_slug FROM `{$stg_tables['sql_atributos_terminos']}` t JOIN `{$stg_tables['sql_atributos']}` a ON a.id=t.atributo_id ORDER BY t.id");if(is_wp_error($src_terms))return$src_terms;if(is_wp_error($dst_terms))return$dst_terms;
        $dst_term_by=array();foreach($dst_terms as $r){$k=self::attribute_term_key($r['attribute_slug'],$r['slug']);if(isset($dst_term_by[$k]))return new WP_Error('clone_attr_term_duplicate','Termino de atributo duplicado en STAGING: '.$k);$dst_term_by[$k]=$r;}
        $term_map=array();$src_term_keys=array();
        foreach($src_terms as $r){$k=self::attribute_term_key($r['attribute_slug'],$r['slug']);if(isset($src_term_keys[$k]))return new WP_Error('clone_attr_term_duplicate_pro','Termino de atributo duplicado en PRO: '.$k);$src_term_keys[$k]=1;$sid=absint($r['id']);$target_attr=absint($attr_map[absint($r['atributo_id'])]??0);if(!$target_attr)return new WP_Error('clone_attr_map','No se pudo resolver atributo para '.$k);if(isset($dst_term_by[$k])){$tid=absint($dst_term_by[$k]['id']);$x=self::exec($stg,"UPDATE `{$stg_tables['sql_atributos_terminos']}` SET atributo_id={$target_attr},slug=".self::sql_value($stg,$r['slug']).",nombre=".self::sql_value($stg,$r['nombre']).",orden=".(int)$r['orden'].",activo=".absint($r['activo'])." WHERE id={$tid}");if(is_wp_error($x))return$x;}else{$x=self::exec($stg,"INSERT INTO `{$stg_tables['sql_atributos_terminos']}` (atributo_id,slug,nombre,orden,activo) VALUES ({$target_attr},".self::sql_value($stg,$r['slug']).','.self::sql_value($stg,$r['nombre']).','.(int)$r['orden'].','.absint($r['activo']).')');if(is_wp_error($x))return$x;$tid=absint(mysqli_insert_id($stg));}$term_map[$sid]=$tid;}

        // 3) Tablas dependientes: se reconstruyen con IDs locales.
        $x=self::exec($stg,"DELETE FROM `{$stg_tables['seo_type_role_map']}`");if(is_wp_error($x))return$x;
        $rows=self::rows($pro,"SELECT type_vocabulary_id,role_vocabulary_id,confidence,source,active,created_at,updated_at FROM `{$pro_tables['seo_type_role_map']}` ORDER BY id");if(is_wp_error($rows))return$rows;$ins=array();foreach($rows as $r){$tv=absint($vmap[absint($r['type_vocabulary_id'])]??0);$rv=absint($vmap[absint($r['role_vocabulary_id'])]??0);if(!$tv||!$rv)return new WP_Error('clone_type_role_map','No se pudo remapear TIPO→ROL.');$ins[]=array('type_vocabulary_id'=>$tv,'role_vocabulary_id'=>$rv,'confidence'=>$r['confidence'],'source'=>$r['source'],'active'=>$r['active'],'created_at'=>$r['created_at'],'updated_at'=>$r['updated_at']);}foreach(array_chunk($ins,150) as $c){$x=self::insert_rows($stg,$stg_tables['seo_type_role_map'],array('type_vocabulary_id','role_vocabulary_id','confidence','source','active','created_at','updated_at'),$c,false);if(is_wp_error($x))return$x;}

        $x=self::exec($stg,"DELETE FROM `{$stg_tables['sql_atributos_aliases']}`");if(is_wp_error($x))return$x;$rows=self::rows($pro,"SELECT atributo_id,termino_id,alias FROM `{$pro_tables['sql_atributos_aliases']}` ORDER BY id");if(is_wp_error($rows))return$rows;$ins=array();foreach($rows as $r){$a=absint($attr_map[absint($r['atributo_id'])]??0);$t=absint($r['termino_id']??0);$lt=$t?absint($term_map[$t]??0):0;if(!$a||($t&&!$lt))return new WP_Error('clone_alias_map','No se pudo remapear un alias de atributo.');$ins[]=array('atributo_id'=>$a,'termino_id'=>$lt?:null,'alias'=>$r['alias']);}foreach(array_chunk($ins,200) as $c){$x=self::insert_rows($stg,$stg_tables['sql_atributos_aliases'],array('atributo_id','termino_id','alias'),$c,false);if(is_wp_error($x))return$x;}

        $x=self::exec($stg,"DELETE FROM `{$stg_tables['sql_product_atributos']}`");if(is_wp_error($x))return$x;$rows=self::rows($pro,"SELECT product_id,atributo_id,termino_id,valor_texto,valor_numero,valor_numero_max,unidad,valor_original,orden FROM `{$pro_tables['sql_product_atributos']}` ORDER BY id");if(is_wp_error($rows))return$rows;$ins=array();$product_attr_copied=0;$product_attr_skipped=0;foreach($rows as $r){$p=absint($post_map[absint($r['product_id'])]??0);if(!$p){$product_attr_skipped++;continue;}$a=absint($attr_map[absint($r['atributo_id'])]??0);$st=absint($r['termino_id']??0);$t=$st?absint($term_map[$st]??0):0;if(!$a||($st&&!$t))return new WP_Error('clone_product_attr_map','No se pudo remapear un atributo/termino maestro de producto.');$ins[]=array('product_id'=>$p,'atributo_id'=>$a,'termino_id'=>$t?:null,'valor_texto'=>$r['valor_texto'],'valor_numero'=>$r['valor_numero'],'valor_numero_max'=>$r['valor_numero_max'],'unidad'=>$r['unidad'],'valor_original'=>$r['valor_original'],'orden'=>$r['orden']);$product_attr_copied++;if(count($ins)>=200){$x=self::insert_rows($stg,$stg_tables['sql_product_atributos'],array('product_id','atributo_id','termino_id','valor_texto','valor_numero','valor_numero_max','unidad','valor_original','orden'),$ins,false);if(is_wp_error($x))return$x;$ins=array();}}if($ins){$x=self::insert_rows($stg,$stg_tables['sql_product_atributos'],array('product_id','atributo_id','termino_id','valor_texto','valor_numero','valor_numero_max','unidad','valor_original','orden'),$ins,false);if(is_wp_error($x))return$x;}$stats['sql_product_atributos']=$product_attr_copied;$stats['sql_product_atributos_omitidos_huerfanos']=$product_attr_skipped;self::progress('semantic_product_attributes','Atributos de producto clonados: '.$product_attr_copied.'; huerfanos omitidos: '.$product_attr_skipped.'.',$stats);

        $managed_types=array('product','product_cat','page','post');$quoted="'".implode("','",array_map(static function($v)use($stg){return mysqli_real_escape_string($stg,$v);},$managed_types))."'";$x=self::exec($stg,"DELETE FROM `{$stg_tables['seo_object_vocabulary']}` WHERE object_type IN ({$quoted})");if(is_wp_error($x))return$x;
        $object_vocab_count=0;$cursor=0;
        while(true){
            $rows=self::rows($pro,"SELECT id,object_type,object_id,vocabulary_id,source,confidence,status,created_at,updated_at FROM `{$pro_tables['seo_object_vocabulary']}` WHERE object_type IN ('product','product_cat','page','post') AND id>{$cursor} ORDER BY id LIMIT 1000");
            if(is_wp_error($rows))return$rows;if(!$rows)break;$ins=array();
            foreach($rows as $r){
                $ot=sanitize_key((string)$r['object_type']);$sid=absint($r['object_id']);
                $oid='product_cat'===$ot?absint($category_map[$sid]??0):absint($post_map[$sid]??0);
                $vid=absint($vmap[absint($r['vocabulary_id'])]??0);
                if(!$oid||!$vid)return new WP_Error('clone_object_vocab_map','No se pudo remapear una asignacion semantica '.$ot.' #'.$sid.'.');
                $ins[]=array('object_type'=>$ot,'object_id'=>$oid,'vocabulary_id'=>$vid,'source'=>$r['source'],'confidence'=>$r['confidence'],'status'=>$r['status'],'created_at'=>$r['created_at'],'updated_at'=>$r['updated_at']);
            }
            foreach(array_chunk($ins,200) as $c){$x=self::insert_rows($stg,$stg_tables['seo_object_vocabulary'],array('object_type','object_id','vocabulary_id','source','confidence','status','created_at','updated_at'),$c,false);if(is_wp_error($x))return$x;}
            $object_vocab_count+=count($ins);$cursor=absint(end($rows)['id']??$cursor);
            $stats['seo_object_vocabulary']=$object_vocab_count;
            self::progress('semantic_object_vocabulary','Asignaciones semanticas clonadas: '.$object_vocab_count.'.',$stats);
            if(function_exists('usleep'))@usleep(15000);
            if(count($rows)<1000)break;
        }

        // 4) Eliminar maestros extra una vez remapeadas todas sus dependencias.
        $dst_terms_now=self::rows($stg,"SELECT t.id,a.slug attribute_slug,t.slug FROM `{$stg_tables['sql_atributos_terminos']}` t JOIN `{$stg_tables['sql_atributos']}` a ON a.id=t.atributo_id");if(is_wp_error($dst_terms_now))return$dst_terms_now;foreach($dst_terms_now as $r){$k=self::attribute_term_key($r['attribute_slug'],$r['slug']);if(!isset($src_term_keys[$k])){$x=self::exec($stg,"DELETE FROM `{$stg_tables['sql_atributos_terminos']}` WHERE id=".absint($r['id']));if(is_wp_error($x))return$x;}}
        $dst_attr_now=self::rows($stg,"SELECT id,slug FROM `{$stg_tables['sql_atributos']}`");if(is_wp_error($dst_attr_now))return$dst_attr_now;foreach($dst_attr_now as $r){$k=sanitize_title((string)$r['slug']);if(!isset($src_attr_keys[$k])){$x=self::exec($stg,"DELETE FROM `{$stg_tables['sql_atributos']}` WHERE id=".absint($r['id']));if(is_wp_error($x))return$x;}}
        $dst_vocab_now=self::rows($stg,"SELECT id,semantic_group,slug FROM `{$stg_tables['seo_vocabulary']}`");if(is_wp_error($dst_vocab_now))return$dst_vocab_now;foreach($dst_vocab_now as $r){$k=self::semantic_key($r['semantic_group'],$r['slug']);if(!isset($src_keys[$k])){$x=self::exec($stg,"DELETE FROM `{$stg_tables['seo_vocabulary']}` WHERE id=".absint($r['id']));if(is_wp_error($x))return$x;}}
        $stats['seo_vocabulary']=count($src_vocab);$stats['sql_atributos']=count($src_attr);$stats['sql_atributos_terminos']=count($src_terms);$stats['seo_object_vocabulary']=$object_vocab_count;self::progress('semantic_object_vocabulary','Asignaciones semanticas clonadas: '.$object_vocab_count.'.',$stats);
        return array('vocabulary_map'=>$vmap,'attribute_map'=>$attr_map,'attribute_term_map'=>$term_map);
    }

    private static function clone_nodes($pro,$stg,$pro_tables,$stg_tables,$post_map,$category_map,&$stats){
        $x=self::exec($stg,"DELETE FROM `{$stg_tables['seo_nodes']}` WHERE object_type IN ('category','product','page','post')");if(is_wp_error($x))return$x;
        $rows=self::rows($pro,"SELECT object_type,object_id,seo_role,keywords,title,status,created_at,updated_at FROM `{$pro_tables['seo_nodes']}` WHERE object_type IN ('category','product','page','post') ORDER BY id");
        if(is_wp_error($rows))return$rows;
        $ins=array();$copied=0;$skipped=0;
        foreach($rows as $r){
            $ot=sanitize_key((string)$r['object_type']);
            $sid=absint($r['object_id']);
            $oid='category'===$ot?absint($category_map[$sid]??0):absint($post_map[$sid]??0);
            if(!$oid){$skipped++;continue;}
            $ins[]=array('object_type'=>$ot,'object_id'=>$oid,'seo_role'=>$r['seo_role'],'keywords'=>$r['keywords'],'title'=>$r['title'],'status'=>$r['status'],'created_at'=>$r['created_at'],'updated_at'=>$r['updated_at']);
            if(count($ins)>=200){
                $x=self::insert_rows($stg,$stg_tables['seo_nodes'],array('object_type','object_id','seo_role','keywords','title','status','created_at','updated_at'),$ins,false);
                if(is_wp_error($x))return$x;
                $copied+=count($ins);$ins=array();
            }
        }
        if($ins){
            $x=self::insert_rows($stg,$stg_tables['seo_nodes'],array('object_type','object_id','seo_role','keywords','title','status','created_at','updated_at'),$ins,false);
            if(is_wp_error($x))return$x;
            $copied+=count($ins);
        }
        $stats['seo_nodes']=$copied;
        $stats['seo_nodes_omitted_orphans']=$skipped;
        return true;
    }

    private static function relation_managed_types(){return array('cluster_to_primary','cluster_to_hub_primary','hub_primary_to_hub_secondary','hub_primary_to_secondary','hub_secondary_to_landing','hub_secondary_to_category','cluster_to_category','hub_primary_to_category','landing_to_category','post_to_category');}
    private static function relation_endpoint_map($type,$id,$post_map,$category_map){$type=sanitize_key((string)$type);$id=absint($id);if(in_array($type,array('product_cat','category'),true))return absint($category_map[$id]??0);if(in_array($type,array('product','post','page','cluster','hub_primary','hub_secondary','hub_secundario','landing','landing_page'),true))return absint($post_map[$id]??0);return 0;}
    private static function clone_relations($pro,$stg,$pro_tables,$stg_tables,$post_map,$category_map,&$stats){$types=self::relation_managed_types();$quoted="'".implode("','",array_map(static function($v)use($stg){return mysqli_real_escape_string($stg,$v);},$types))."'";$x=self::exec($stg,"DELETE FROM `{$stg_tables['seo_relations']}` WHERE relation_type IN ({$quoted})");if(is_wp_error($x))return$x;$rows=self::rows($pro,"SELECT source_type,source_id,target_type,target_id,relation_type,created_at FROM `{$pro_tables['seo_relations']}` WHERE relation_type IN ("."'".implode("','",array_map(static function($v)use($pro){return mysqli_real_escape_string($pro,$v);},$types))."'".") ORDER BY id");if(is_wp_error($rows))return$rows;$ins=array();foreach($rows as $r){$s=self::relation_endpoint_map($r['source_type'],$r['source_id'],$post_map,$category_map);$t=self::relation_endpoint_map($r['target_type'],$r['target_id'],$post_map,$category_map);if(!$s||!$t)return new WP_Error('clone_relation_map','No se pudo remapear '.$r['relation_type'].' con IDs locales de STAGING.');$ins[]=array('source_type'=>$r['source_type'],'source_id'=>$s,'target_type'=>$r['target_type'],'target_id'=>$t,'relation_type'=>$r['relation_type'],'created_at'=>$r['created_at']);}foreach(array_chunk($ins,200) as $c){$x=self::insert_rows($stg,$stg_tables['seo_relations'],array('source_type','source_id','target_type','target_id','relation_type','created_at'),$c,false);if(is_wp_error($x))return$x;}$stats['seo_relations']=count($ins);return true;}

    private static function clone_faqs($pro,$stg,$pro_tables,$stg_tables,$post_map,$category_map,&$stats){
        $x=self::exec($stg,"DELETE FROM `{$stg_tables['seo_faq']}` WHERE object_type IN (1,2,3)");if(is_wp_error($x))return$x;
        $cursor=0;$copied=0;
        while(true){
            $rows=self::rows($pro,"SELECT id,object_type,object_id,question,answer,sort_order,active,load_count,open_count,created_at,updated_at FROM `{$pro_tables['seo_faq']}` WHERE object_type IN (1,2,3) AND id>{$cursor} ORDER BY id LIMIT 1000");
            if(is_wp_error($rows))return$rows;if(!$rows)break;$ins=array();
            foreach($rows as $r){
                $ot=absint($r['object_type']);$sid=absint($r['object_id']);$oid=2===$ot?absint($category_map[$sid]??0):absint($post_map[$sid]??0);
                if(!$oid)return new WP_Error('clone_faq_map','No se pudo remapear FAQ object_type='.$ot.' object_id='.$sid.'.');
                $ins[]=array('object_type'=>$ot,'object_id'=>$oid,'question'=>$r['question'],'answer'=>$r['answer'],'sort_order'=>$r['sort_order'],'active'=>$r['active'],'load_count'=>$r['load_count'],'open_count'=>$r['open_count'],'created_at'=>$r['created_at'],'updated_at'=>$r['updated_at']);
            }
            foreach(array_chunk($ins,150) as $c){$x=self::insert_rows($stg,$stg_tables['seo_faq'],array('object_type','object_id','question','answer','sort_order','active','load_count','open_count','created_at','updated_at'),$c,false);if(is_wp_error($x))return$x;}
            $copied+=count($ins);$cursor=absint(end($rows)['id']??$cursor);$stats['seo_faq']=$copied;
            self::progress('faqs','FAQs clonadas: '.$copied.'.',$stats);
            if(function_exists('usleep'))@usleep(15000);
            if(count($rows)<1000)break;
        }
        return true;
    }

    private static function clone_wc_lookup_if_available($pro, $stg, $pro_prefix, $stg_prefix, $post_map, &$stats) {
        $source = $pro_prefix . 'wc_product_meta_lookup';
        $target = $stg_prefix . 'wc_product_meta_lookup';
        if (!self::table_exists($pro, $source) || !self::table_exists($stg, $target)) return true;
        $source_columns = self::table_columns($pro, $source);
        $target_columns = self::table_columns($stg, $target);
        if (is_wp_error($source_columns)) return $source_columns;
        if (is_wp_error($target_columns)) return $target_columns;
        if ($source_columns !== $target_columns || !in_array('product_id', $source_columns, true)) {
            return new WP_Error('clonador_wc_lookup_schema', 'PRO y STAGING no tienen el mismo esquema en wc_product_meta_lookup.');
        }
        $target_ids=array_values(array_unique(array_filter(array_map('absint',array_values((array)$post_map)))));
        foreach(self::id_chunks($target_ids) as $chunk){$r=self::exec($stg,"DELETE FROM `{$target}` WHERE product_id IN (".self::sql_ids($chunk).')');if(is_wp_error($r))return$r;}
        $cols=implode(',',array_map(array(__CLASS__,'ident'),$source_columns));$cursor=0;$copied=0;
        while(true){$rows=self::rows($pro,"SELECT {$cols} FROM `{$source}` WHERE product_id>{$cursor} ORDER BY product_id LIMIT 500");if(is_wp_error($rows))return$rows;if(!$rows)break;$insert=array();foreach($rows as $row){$sid=absint($row['product_id']);$tid=absint($post_map[$sid]??0);if(!$tid)continue;$row['product_id']=$tid;$insert[]=$row;}foreach(array_chunk($insert,120) as $chunk){$r=self::insert_rows($stg,$target,$source_columns,$chunk,true);if(is_wp_error($r))return$r;}$copied+=count($insert);$cursor=absint(end($rows)['product_id']??$cursor);if(count($rows)<500)break;}
        $stats['wc_product_meta_lookup']=$copied;return true;
    }


    private static function clone_wc_attribute_taxonomies_if_available($pro, $stg, $pro_prefix, $stg_prefix, &$stats) {
        $source = $pro_prefix . 'woocommerce_attribute_taxonomies';
        $target = $stg_prefix . 'woocommerce_attribute_taxonomies';
        if (!self::table_exists($pro, $source) || !self::table_exists($stg, $target)) {
            $stats['woocommerce_attribute_taxonomies'] = 0;
            return true;
        }
        $source_columns = self::table_columns($pro, $source);
        $target_columns = self::table_columns($stg, $target);
        if (is_wp_error($source_columns)) return $source_columns;
        if (is_wp_error($target_columns)) return $target_columns;
        if ($source_columns !== $target_columns) {
            return new WP_Error('clonador_wc_attributes_schema', 'PRO y STAGING no tienen el mismo esquema en woocommerce_attribute_taxonomies.');
        }
        $columns = array_values(array_filter($source_columns, static function($column){ return 'attribute_id' !== $column; }));
        if (!$columns) return new WP_Error('clonador_wc_attributes_columns', 'No se pudieron resolver columnas de woocommerce_attribute_taxonomies.');

        $source_count = self::count_table($pro, $source);
        $target_count = self::count_table($stg, $target);
        if (is_wp_error($source_count)) return $source_count;
        if (is_wp_error($target_count)) return $target_count;
        if (0 === absint($source_count) && 0 === absint($target_count)) {
            $stats['woocommerce_attribute_taxonomies'] = 0;
            return true;
        }

        $r = self::exec($stg, "DELETE FROM `{$target}`");
        if (is_wp_error($r)) return $r;
        $cols_sql = implode(',', array_map(array(__CLASS__, 'ident'), $columns));
        $rows = self::rows($pro, "SELECT {$cols_sql} FROM `{$source}` ORDER BY attribute_id");
        if (is_wp_error($rows)) return $rows;
        foreach (array_chunk($rows, 100) as $chunk) {
            $r = self::insert_rows($stg, $target, $columns, $chunk, false);
            if (is_wp_error($r)) return $r;
        }
        $stats['woocommerce_attribute_taxonomies'] = count($rows);
        return true;
    }

    private static function clone_wc_product_attributes_lookup_if_available($pro, $stg, $pro_prefix, $stg_prefix, $post_map, $term_map, &$stats) {
        $source = $pro_prefix . 'wc_product_attributes_lookup';
        $target = $stg_prefix . 'wc_product_attributes_lookup';
        if (!self::table_exists($pro, $source) || !self::table_exists($stg, $target)) {
            $stats['wc_product_attributes_lookup'] = 0;
            return true;
        }
        $columns = self::table_columns($pro, $source);
        $target_columns = self::table_columns($stg, $target);
        if (is_wp_error($columns)) return $columns;
        if (is_wp_error($target_columns)) return $target_columns;
        if ($columns !== $target_columns) {
            return new WP_Error('clonador_wc_attributes_lookup_schema', 'PRO y STAGING no tienen el mismo esquema en wc_product_attributes_lookup.');
        }
        foreach (array('product_or_parent_id','product_id','term_id') as $required) {
            if (!in_array($required, $columns, true)) {
                return new WP_Error('clonador_wc_attributes_lookup_columns', 'Falta la columna ' . $required . ' en wc_product_attributes_lookup.');
            }
        }

        $r = self::exec($stg, "DELETE FROM `{$target}`");
        if (is_wp_error($r)) return $r;
        $cols_sql = implode(',', array_map(array(__CLASS__, 'ident'), $columns));
        $pro_posts = $pro_prefix . 'posts';
        $rows = self::rows($pro, "SELECT {$cols_sql} FROM `{$source}` WHERE product_id IN (SELECT ID FROM `{$pro_posts}` WHERE " . self::post_type_sql($pro) . " AND post_status<>'trash') ORDER BY product_or_parent_id,product_id,term_id");
        if (is_wp_error($rows)) return $rows;

        $insert = array();
        $copied = 0;
        foreach ($rows as $row) {
            $source_parent = absint($row['product_or_parent_id'] ?? 0);
            $source_product = absint($row['product_id'] ?? 0);
            $source_term = absint($row['term_id'] ?? 0);
            $target_parent = absint($post_map[$source_parent] ?? 0);
            $target_product = absint($post_map[$source_product] ?? 0);
            $target_term = absint($term_map[$source_term] ?? 0);
            if (!$target_parent || !$target_product || !$target_term) {
                return new WP_Error('clonador_wc_attributes_lookup_map', 'No se pudo remapear una fila de wc_product_attributes_lookup a IDs locales de STAGING.');
            }
            $row['product_or_parent_id'] = $target_parent;
            $row['product_id'] = $target_product;
            $row['term_id'] = $target_term;
            $insert[] = $row;
            if (count($insert) >= 150) {
                $r = self::insert_rows($stg, $target, $columns, $insert, false);
                if (is_wp_error($r)) return $r;
                $copied += count($insert);
                $insert = array();
            }
        }
        if ($insert) {
            $r = self::insert_rows($stg, $target, $columns, $insert, false);
            if (is_wp_error($r)) return $r;
            $copied += count($insert);
        }
        $stats['wc_product_attributes_lookup'] = $copied;
        return true;
    }

    /**
     * Verificacion interna ejecutada ANTES de COMMIT.
     * No compara IDs numericos entre entornos: compara cantidades del mismo
     * perimetro logico que acaba de reconstruirse. Cualquier desajuste provoca
     * excepcion y ROLLBACK de toda la clonacion.
     */
    private static function verify_clone_counts($pro, $stg, $pro_tables, $stg_tables) {
        $checks = array();
        $errors = array();
        $add = static function($key, $source, $target, $label = '') use (&$checks, &$errors) {
            if (is_wp_error($source)) return $source;
            if (is_wp_error($target)) return $target;
            $source = absint($source);
            $target = absint($target);
            $ok = ($source === $target);
            $checks[$key] = array(
                'label' => $label ? (string) $label : (string) $key,
                'pro' => $source,
                'staging' => $target,
                'ok' => $ok,
            );
            if (!$ok) $errors[] = ($label ? $label : $key) . ': PRO=' . $source . ', STAGING=' . $target;
            return true;
        };

        foreach (self::$post_types as $type) {
            $sp = mysqli_real_escape_string($pro, $type);
            $st = mysqli_real_escape_string($stg, $type);
            $r = $add(
                'post_type:' . $type,
                self::count_table($pro, $pro_tables['posts'], "post_type='{$sp}' AND post_status<>'trash'"),
                self::count_table($stg, $stg_tables['posts'], "post_type='{$st}' AND post_status<>'trash'"),
                'Objetos ' . $type
            );
            if (is_wp_error($r)) return $r;
        }

        $src_tax_rows = self::rows($pro, "SELECT taxonomy,COUNT(*) c FROM `{$pro_tables['term_taxonomy']}` WHERE " . self::all_managed_taxonomy_sql($pro) . ' GROUP BY taxonomy');
        $dst_tax_rows = self::rows($stg, "SELECT taxonomy,COUNT(*) c FROM `{$stg_tables['term_taxonomy']}` WHERE " . self::all_managed_taxonomy_sql($stg) . ' GROUP BY taxonomy');
        if (is_wp_error($src_tax_rows)) return $src_tax_rows;
        if (is_wp_error($dst_tax_rows)) return $dst_tax_rows;
        $src_tax=array(); $dst_tax=array();
        foreach ($src_tax_rows as $row) $src_tax[(string)$row['taxonomy']] = absint($row['c']);
        foreach ($dst_tax_rows as $row) $dst_tax[(string)$row['taxonomy']] = absint($row['c']);
        $names=array_values(array_unique(array_merge(array_keys($src_tax),array_keys($dst_tax),self::$taxonomies)));
        sort($names,SORT_STRING);
        foreach ($names as $taxonomy) {
            $r=$add('taxonomy:' . $taxonomy, absint($src_tax[$taxonomy]??0), absint($dst_tax[$taxonomy]??0), 'Taxonomia ' . $taxonomy);
            if (is_wp_error($r)) return $r;
        }

        $src_meta = self::scalar($pro, "SELECT COUNT(*) c FROM `{$pro_tables['postmeta']}` pm JOIN `{$pro_tables['posts']}` p ON p.ID=pm.post_id WHERE " . self::post_type_sql($pro,'p') . " AND p.post_status<>'trash' AND " . self::meta_portable_sql('pm'), 'c');
        $dst_meta = self::scalar($stg, "SELECT COUNT(*) c FROM `{$stg_tables['postmeta']}` pm JOIN `{$stg_tables['posts']}` p ON p.ID=pm.post_id WHERE " . self::post_type_sql($stg,'p') . " AND p.post_status<>'trash' AND " . self::meta_portable_sql('pm'), 'c');
        $r=$add('postmeta_portable',$src_meta,$dst_meta,'Metadatos portables'); if(is_wp_error($r))return$r;

        $src_rel = self::scalar($pro, "SELECT COUNT(*) c FROM `{$pro_tables['term_relationships']}` tr JOIN `{$pro_tables['term_taxonomy']}` tt ON tt.term_taxonomy_id=tr.term_taxonomy_id JOIN `{$pro_tables['posts']}` p ON p.ID=tr.object_id WHERE " . self::all_managed_taxonomy_sql($pro,'tt') . " AND " . self::post_type_sql($pro,'p') . " AND p.post_status<>'trash'", 'c');
        $dst_rel = self::scalar($stg, "SELECT COUNT(*) c FROM `{$stg_tables['term_relationships']}` tr JOIN `{$stg_tables['term_taxonomy']}` tt ON tt.term_taxonomy_id=tr.term_taxonomy_id JOIN `{$stg_tables['posts']}` p ON p.ID=tr.object_id WHERE " . self::all_managed_taxonomy_sql($stg,'tt') . " AND " . self::post_type_sql($stg,'p') . " AND p.post_status<>'trash'", 'c');
        $r=$add('term_relationships',$src_rel,$dst_rel,'Relaciones taxonomicas'); if(is_wp_error($r))return$r;

        $src_termmeta = self::scalar($pro, "SELECT COUNT(DISTINCT tm.meta_id) c FROM `{$pro_tables['termmeta']}` tm JOIN `{$pro_tables['term_taxonomy']}` tt ON tt.term_id=tm.term_id WHERE " . self::all_managed_taxonomy_sql($pro,'tt') . " AND " . self::termmeta_portable_sql('tm'), 'c');
        $dst_termmeta = self::scalar($stg, "SELECT COUNT(DISTINCT tm.meta_id) c FROM `{$stg_tables['termmeta']}` tm JOIN `{$stg_tables['term_taxonomy']}` tt ON tt.term_id=tm.term_id WHERE " . self::all_managed_taxonomy_sql($stg,'tt') . " AND " . self::termmeta_portable_sql('tm'), 'c');
        $r=$add('termmeta_portable',$src_termmeta,$dst_termmeta,'Metadatos de terminos'); if(is_wp_error($r))return$r;

        foreach (self::custom_table_keys() as $key) {
            if ('sql_product_atributos' === $key) {
                $source_count = self::scalar($pro,
                    "SELECT COUNT(*) c FROM `{$pro_tables['sql_product_atributos']}` pa
                     JOIN `{$pro_tables['posts']}` p ON p.ID=pa.product_id AND p.post_type='product' AND p.post_status<>'trash'
                     JOIN `{$pro_tables['sql_atributos']}` a ON a.id=pa.atributo_id
                     LEFT JOIN `{$pro_tables['sql_atributos_terminos']}` t ON t.id=pa.termino_id
                     WHERE COALESCE(pa.termino_id,0)=0 OR t.id IS NOT NULL", 'c');
                $target_count = self::count_table($stg,$stg_tables[$key],self::managed_custom_where($stg,$key));
            } elseif ('seo_nodes' === $key) {
                $source_count = self::scalar($pro,
                    "SELECT COUNT(*) c
                     FROM `{$pro_tables['seo_nodes']}` n
                     LEFT JOIN `{$pro_tables['posts']}` p ON p.ID=n.object_id AND p.post_status<>'trash' AND (
                            (n.object_type='product' AND p.post_type='product') OR
                            (n.object_type='page' AND p.post_type='page') OR
                            (n.object_type='post' AND p.post_type='post')
                     )
                     LEFT JOIN `{$pro_tables['term_taxonomy']}` tt ON n.object_type='category' AND tt.term_id=n.object_id AND tt.taxonomy='product_cat'
                     WHERE n.object_type IN ('category','product','page','post')
                       AND ((n.object_type='category' AND tt.term_taxonomy_id IS NOT NULL) OR (n.object_type<>'category' AND p.ID IS NOT NULL))", 'c');
                $target_count = self::count_table($stg,$stg_tables[$key],self::managed_custom_where($stg,$key));
            } else {
                $source_count = self::count_table($pro,$pro_tables[$key],self::managed_custom_where($pro,$key));
                $target_count = self::count_table($stg,$stg_tables[$key],self::managed_custom_where($stg,$key));
            }
            $r=$add('table:' . $key,$source_count,$target_count,'Tabla ' . $key);
            if(is_wp_error($r))return$r;
        }

        $pro_prefix=substr($pro_tables['posts'],0,-strlen('posts'));
        $stg_prefix=substr($stg_tables['posts'],0,-strlen('posts'));
        $pro_optional=self::optional_woo_tables($pro_prefix);
        $stg_optional=self::optional_woo_tables($stg_prefix);
        foreach ($pro_optional as $key=>$source_table) {
            $target_table=$stg_optional[$key];
            if (!self::table_exists($pro,$source_table) && !self::table_exists($stg,$target_table)) continue;
            if (!self::table_exists($pro,$source_table) || !self::table_exists($stg,$target_table)) {
                $errors[]='Woo ' . $key . ': la tabla no existe en ambos entornos';
                continue;
            }
            if ('woocommerce_attribute_taxonomies' === $key) {
                $source=self::count_table($pro,$source_table);
                $target=self::count_table($stg,$target_table);
            } else {
                $source=self::scalar($pro,"SELECT COUNT(*) c FROM `{$source_table}` l JOIN `{$pro_tables['posts']}` p ON p.ID=l.product_id WHERE " . self::post_type_sql($pro,'p') . " AND p.post_status<>'trash'",'c');
                $target=self::scalar($stg,"SELECT COUNT(*) c FROM `{$target_table}` l JOIN `{$stg_tables['posts']}` p ON p.ID=l.product_id WHERE " . self::post_type_sql($stg,'p') . " AND p.post_status<>'trash'",'c');
            }
            $r=$add('woo:' . $key,$source,$target,'Woo ' . $key); if(is_wp_error($r))return$r;
        }

        // KPIs criticos: son precisamente los fallos mas costosos de detectar a mano.
        $src_with_cat = self::scalar($pro,
            "SELECT COUNT(DISTINCT p.ID) c FROM `{$pro_tables['posts']}` p
             JOIN `{$pro_tables['term_relationships']}` tr ON tr.object_id=p.ID
             JOIN `{$pro_tables['term_taxonomy']}` tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_cat'
             WHERE p.post_type='product' AND p.post_status<>'trash'", 'c');
        $dst_with_cat = self::scalar($stg,
            "SELECT COUNT(DISTINCT p.ID) c FROM `{$stg_tables['posts']}` p
             JOIN `{$stg_tables['term_relationships']}` tr ON tr.object_id=p.ID
             JOIN `{$stg_tables['term_taxonomy']}` tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_cat'
             WHERE p.post_type='product' AND p.post_status<>'trash'", 'c');
        $r=$add('kpi:products_with_category',$src_with_cat,$dst_with_cat,'Productos con categoria');if(is_wp_error($r))return$r;

        $src_products = absint($checks['post_type:product']['pro'] ?? 0);
        $dst_products = absint($checks['post_type:product']['staging'] ?? 0);
        $r=$add('kpi:products_without_category',max(0,$src_products-absint($src_with_cat)),max(0,$dst_products-absint($dst_with_cat)),'Productos sin categoria');if(is_wp_error($r))return$r;

        $src_with_attr = self::scalar($pro,
            "SELECT COUNT(DISTINCT p.ID) c FROM `{$pro_tables['posts']}` p
             JOIN `{$pro_tables['sql_product_atributos']}` pa ON pa.product_id=p.ID
             JOIN `{$pro_tables['sql_atributos']}` a ON a.id=pa.atributo_id
             LEFT JOIN `{$pro_tables['sql_atributos_terminos']}` at ON at.id=pa.termino_id
             WHERE p.post_type='product' AND p.post_status<>'trash' AND (COALESCE(pa.termino_id,0)=0 OR at.id IS NOT NULL)", 'c');
        $dst_with_attr = self::scalar($stg,
            "SELECT COUNT(DISTINCT p.ID) c FROM `{$stg_tables['posts']}` p
             JOIN `{$stg_tables['sql_product_atributos']}` pa ON pa.product_id=p.ID
             JOIN `{$stg_tables['sql_atributos']}` a ON a.id=pa.atributo_id
             LEFT JOIN `{$stg_tables['sql_atributos_terminos']}` at ON at.id=pa.termino_id
             WHERE p.post_type='product' AND p.post_status<>'trash' AND (COALESCE(pa.termino_id,0)=0 OR at.id IS NOT NULL)", 'c');
        $r=$add('kpi:products_with_seo_attributes',$src_with_attr,$dst_with_attr,'Productos con atributos SEO');if(is_wp_error($r))return$r;
        $r=$add('kpi:products_without_seo_attributes',max(0,$src_products-absint($src_with_attr)),max(0,$dst_products-absint($dst_with_attr)),'Productos sin atributos SEO');if(is_wp_error($r))return$r;

        $failed = 0;
        foreach ($checks as $check) if (empty($check['ok'])) $failed++;
        $failed = max($failed, count($errors));
        $summary = array(
            'checks_total' => count($checks),
            'passed' => max(0, count($checks) - $failed),
            'failed' => $failed,
        );
        $critical_keys = array(
            'post_type:product',
            'taxonomy:product_cat',
            'kpi:products_with_category',
            'kpi:products_without_category',
            'kpi:products_with_seo_attributes',
            'kpi:products_without_seo_attributes',
            'table:sql_product_atributos',
            'term_relationships',
            'table:seo_faq',
            'table:seo_vocabulary',
            'table:seo_object_vocabulary',
        );
        $critical = array();
        foreach ($critical_keys as $key) if (isset($checks[$key])) $critical[$key] = $checks[$key];
        $payload = array(
            'passed' => !$errors,
            'checks' => $checks,
            'critical_kpis' => $critical,
            'summary' => $summary,
            'checked_at' => time(),
        );

        if ($errors) {
            return new WP_Error('clonador_verify', 'Verificacion final fallida: ' . implode(' | ', array_slice($errors,0,20)), $payload);
        }
        return $payload;
    }

    private static function set_staging_option($stg, $options_table, $name, $value) {
        $name_sql = self::sql_value($stg, $name);
        $value_sql = self::sql_value($stg, maybe_serialize($value));
        return self::exec($stg,
            "INSERT INTO `{$options_table}` (option_name,option_value,autoload) VALUES ({$name_sql},{$value_sql},'no')
             ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='no'"
        );
    }

    /**
     * Vaciado logico del perimetro gestionado en STAGING.
     *
     * Las tablas dedicadas del catalogo se vacian completas. En las tablas
     * compartidas de WordPress se eliminan solo posts/taxonomias gestionados;
     * nunca se hace TRUNCATE de wp_posts/wp_terms/wp_postmeta.
     */
    private static function reset_staging_scope($stg, $tables, &$stats) {
        $post_ids = self::ids(
            $stg,
            "SELECT ID FROM `{$tables['posts']}` WHERE " . self::post_type_sql($stg),
            'ID'
        );
        if (is_wp_error($post_ids)) return $post_ids;

        if ($post_ids) {
            if (self::table_exists($stg, $tables['comments']) && self::table_exists($stg, $tables['commentmeta'])) {
                foreach (self::id_chunks($post_ids) as $chunk) {
                    $ids_sql = self::sql_ids($chunk);
                    $comment_ids = self::ids($stg, "SELECT comment_ID FROM `{$tables['comments']}` WHERE comment_post_ID IN ({$ids_sql})", 'comment_ID');
                    if (is_wp_error($comment_ids)) return $comment_ids;
                    $r = self::delete_ids($stg, $tables['commentmeta'], 'comment_id', $comment_ids);
                    if (is_wp_error($r)) return $r;
                    $r = self::exec($stg, "DELETE FROM `{$tables['comments']}` WHERE comment_post_ID IN ({$ids_sql})");
                    if (is_wp_error($r)) return $r;
                }
            }
            $r = self::delete_ids($stg, $tables['postmeta'], 'post_id', $post_ids);
            if (is_wp_error($r)) return $r;
            $r = self::delete_ids($stg, $tables['term_relationships'], 'object_id', $post_ids);
            if (is_wp_error($r)) return $r;

            $prefix = substr($tables['posts'], 0, -strlen('posts'));
            foreach (array('wc_product_meta_lookup', 'wc_product_attributes_lookup') as $suffix) {
                $lookup = $prefix . $suffix;
                if (!self::table_exists($stg, $lookup)) continue;
                $columns = self::table_columns($stg, $lookup);
                if (is_wp_error($columns)) return $columns;
                if (in_array('product_id', $columns, true)) {
                    $r = self::delete_ids($stg, $lookup, 'product_id', $post_ids);
                    if (is_wp_error($r)) return $r;
                }
                if (in_array('product_or_parent_id', $columns, true)) {
                    $r = self::delete_ids($stg, $lookup, 'product_or_parent_id', $post_ids);
                    if (is_wp_error($r)) return $r;
                }
            }

            $r = self::delete_ids($stg, $tables['posts'], 'ID', $post_ids);
            if (is_wp_error($r)) return $r;
        }
        $stats['reset_posts'] = count($post_ids);

        $tt_rows = self::rows(
            $stg,
            "SELECT term_taxonomy_id,term_id FROM `{$tables['term_taxonomy']}` WHERE " . self::all_managed_taxonomy_sql($stg)
        );
        if (is_wp_error($tt_rows)) return $tt_rows;
        $tt_ids = array();
        $term_ids = array();
        foreach ($tt_rows as $row) {
            $tt_ids[] = absint($row['term_taxonomy_id'] ?? 0);
            $term_ids[] = absint($row['term_id'] ?? 0);
        }
        $r = self::delete_ids($stg, $tables['term_relationships'], 'term_taxonomy_id', $tt_ids);
        if (is_wp_error($r)) return $r;
        $r = self::delete_ids($stg, $tables['term_taxonomy'], 'term_taxonomy_id', $tt_ids);
        if (is_wp_error($r)) return $r;
        $r = self::delete_orphan_terms($stg, $tables, $term_ids);
        if (is_wp_error($r)) return $r;
        $stats['reset_taxonomies'] = count($tt_ids);

        // Primero dependencias compartidas del plugin; despues maestros.
        // Este orden tambien es compatible con instalaciones que hayan
        // incorporado claves foraneas a las tablas propias.
        foreach (array('seo_object_vocabulary','seo_nodes','seo_relations','seo_faq') as $key) {
            $where = self::managed_custom_where($stg, $key);
            $r = self::exec($stg, "DELETE FROM `{$tables[$key]}` WHERE {$where}");
            if (is_wp_error($r)) return $r;
            $stats['reset_' . $key] = absint(mysqli_affected_rows($stg));
        }

        $full_delete = array(
            'seo_type_role_map',
            'sql_product_atributos',
            'sql_atributos_aliases',
            'sql_atributos_terminos',
            'sql_atributos',
            'seo_vocabulary',
        );
        foreach ($full_delete as $key) {
            $r = self::exec($stg, "DELETE FROM `{$tables[$key]}`");
            if (is_wp_error($r)) return $r;
            $stats['reset_' . $key] = absint(mysqli_affected_rows($stg));
        }
        $stats['reset_custom_tables'] = count($full_delete) + 4;
        return true;
    }

    private static function progress($phase, $message, $stats = array()) {
        if (is_callable(self::$progress_callback)) {
            call_user_func(self::$progress_callback, sanitize_key((string) $phase), (string) $message, array('stats' => is_array($stats) ? $stats : array()));
        }
    }

    private static function apply_clone($pro, $stg, $pro_tables, $stg_tables, $identity, $progress_callback = null) {
        self::$progress_callback = is_callable($progress_callback) ? $progress_callback : null;
        self::progress('lock', 'Reservando el Clonador y preparando la transaccion.');
        $lock = self::scalar($stg, "SELECT GET_LOCK('" . mysqli_real_escape_string($stg, self::LOCK_NAME) . "',0) AS l", 'l');
        if ('1' !== (string) $lock) {
            self::$progress_callback = null;
            return new WP_Error('clonador_lock', 'Ya hay otra alineacion integral en curso.');
        }
        $stats = array();
        $started = microtime(true);
        try {
            $r = self::exec($stg, 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            if (is_wp_error($r)) return $r;
            $r = self::exec($stg, 'START TRANSACTION');
            if (is_wp_error($r)) return $r;

            self::progress('reset', 'Vaciando el perimetro gestionado de STAGING.', $stats);
            $r = self::reset_staging_scope($stg, $stg_tables, $stats);
            if (is_wp_error($r)) throw new RuntimeException($r->get_error_message());

            self::progress('posts', 'Clonando productos, posts, paginas y metadatos portables.', $stats);
            $post_map = self::clone_posts($pro, $stg, $pro_tables, $stg_tables, $stats);
            if (is_wp_error($post_map)) throw new RuntimeException($post_map->get_error_message());

            self::progress('taxonomies', 'Clonando categorias, etiquetas y taxonomias.', $stats);
            $tax_maps = self::clone_taxonomies($pro, $stg, $pro_tables, $stg_tables, $post_map, $stats);
            if (is_wp_error($tax_maps)) throw new RuntimeException($tax_maps->get_error_message());
            $category_map = (array) ($tax_maps['category_term_map'] ?? array());
            $term_map = (array) ($tax_maps['term_map'] ?? array());
            $tt_map = (array) ($tax_maps['tt_map'] ?? array());

            self::progress('woo_taxonomies', 'Clonando marcas y taxonomias WooCommerce adicionales.', $stats);
            $extra_tax_maps = self::clone_extra_taxonomies($pro, $stg, $pro_tables, $stg_tables, $post_map, $term_map, $tt_map, $stats);
            if (is_wp_error($extra_tax_maps)) throw new RuntimeException($extra_tax_maps->get_error_message());
            $term_map = (array) ($extra_tax_maps['term_map'] ?? $term_map);

            self::progress('semantic', 'Clonando vocabulario, atributos y asociaciones semanticas.', $stats);
            $semantic_maps = self::clone_semantic_tables($pro, $stg, $pro_tables, $stg_tables, $post_map, $category_map, $stats);
            if (is_wp_error($semantic_maps)) throw new RuntimeException($semantic_maps->get_error_message());

            self::progress('nodes', 'Clonando nodos SEO.', $stats);
            $r = self::clone_nodes($pro, $stg, $pro_tables, $stg_tables, $post_map, $category_map, $stats);
            if (is_wp_error($r)) throw new RuntimeException($r->get_error_message());

            self::progress('relations', 'Clonando relaciones SEO.', $stats);
            $r = self::clone_relations($pro, $stg, $pro_tables, $stg_tables, $post_map, $category_map, $stats);
            if (is_wp_error($r)) throw new RuntimeException($r->get_error_message());

            self::progress('faqs', 'Clonando FAQs y remapeando su objeto propietario.', $stats);
            $r = self::clone_faqs($pro, $stg, $pro_tables, $stg_tables, $post_map, $category_map, $stats);
            if (is_wp_error($r)) throw new RuntimeException($r->get_error_message());

            $pro_prefix = substr($pro_tables['posts'], 0, -strlen('posts'));
            $stg_prefix = substr($stg_tables['posts'], 0, -strlen('posts'));
            self::progress('woo_lookup', 'Reconstruyendo tablas lookup de WooCommerce.', $stats);
            $r = self::clone_wc_attribute_taxonomies_if_available($pro, $stg, $pro_prefix, $stg_prefix, $stats);
            if (is_wp_error($r)) throw new RuntimeException($r->get_error_message());
            $r = self::clone_wc_lookup_if_available($pro, $stg, $pro_prefix, $stg_prefix, $post_map, $stats);
            if (is_wp_error($r)) throw new RuntimeException($r->get_error_message());
            $r = self::clone_wc_product_attributes_lookup_if_available($pro, $stg, $pro_prefix, $stg_prefix, $post_map, $term_map, $stats);
            if (is_wp_error($r)) throw new RuntimeException($r->get_error_message());

            self::progress('verify', 'Verificando el clon antes de confirmar la transaccion.', $stats);
            $verification = self::verify_clone_counts($pro, $stg, $pro_tables, $stg_tables);
            if (is_wp_error($verification)) throw new RuntimeException($verification->get_error_message());
            $stats['verification'] = $verification;

            self::progress('commit', 'Verificacion correcta. Confirmando cambios en STAGING.', $stats);
            $r = self::exec($stg, 'COMMIT');
            if (is_wp_error($r)) throw new RuntimeException($r->get_error_message());
        } catch (Throwable $e) {
            @mysqli_query($stg, 'ROLLBACK');
            self::progress('rollback', 'Se produjo un error; la transaccion de STAGING se ha revertido.', $stats);
            self::$progress_callback = null;
            return new WP_Error('clonador_apply', 'Clonador cancelado y revertido: ' . $e->getMessage());
        } finally {
            @mysqli_query($stg, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($stg, self::LOCK_NAME) . "')");
        }

        $generation = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('clone-', true);
        $payload = array(
            'generation' => $generation,
            'completed_at' => time(),
            'source' => 'pro',
            'destination' => 'staging',
            'engine' => 'portable_clone_engine_worker',
            'stats' => $stats,
            'identity' => $identity,
            'duration_seconds' => round(microtime(true) - $started, 3),
        );
        self::set_staging_option($stg, $stg_tables['options'], 'seo_clonador_generation', $payload);
        self::set_staging_option($stg, $stg_tables['options'], 'seo_clonador_last', $payload);
        self::set_staging_option($stg, $stg_tables['options'], 'seo_semantic_catalog_reindex_pending', array(
            'reason' => 'environment_clonador',
            'generation' => $generation,
            'created_at' => time(),
        ));
        self::set_staging_option($stg, $stg_tables['options'], 'seo_semantic_catalog_drift', array(
            'reason' => 'environment_clonador',
            'generation' => $generation,
            'created_at' => time(),
        ));
        self::progress('completed', 'Clonacion completada y verificada.', $stats);
        self::$progress_callback = null;
        return $payload;
    }

    private static function open_pair() {
        $pro = seo_clonador_open('pro');
        if (is_wp_error($pro)) return $pro;
        $stg = seo_clonador_open('staging');
        if (is_wp_error($stg)) {
            @mysqli_close($pro);
            return $stg;
        }
        return array($pro, $stg);
    }



    /**
     * Estado y mapas persistentes del Clonador por lotes.
     * Se guardan en wp_options de STAGING con autoload=no para que cada lote
     * pueda confirmar datos + cursor en la misma transaccion MySQL.
     */
    private static function worker_option_get($stg, $options_table, $name, $default = array()) {
        $name_sql = self::sql_value($stg, $name);
        $row = self::rows($stg, "SELECT option_value FROM `{$options_table}` WHERE option_name={$name_sql} LIMIT 1");
        if (is_wp_error($row)) return $row;
        if (!$row) return $default;
        $value = maybe_unserialize((string) ($row[0]['option_value'] ?? ''));
        return null === $value ? $default : $value;
    }

    private static function worker_option_delete($stg, $options_table, $name) {
        return self::exec($stg, "DELETE FROM `{$options_table}` WHERE option_name=" . self::sql_value($stg, $name));
    }

    private static function worker_map_option($name) {
        return self::WORKER_MAP_PREFIX . sanitize_key((string) $name);
    }

    private static function worker_map_get($stg, $options_table, $name) {
        $map = self::worker_option_get($stg, $options_table, self::worker_map_option($name), array());
        return is_wp_error($map) ? $map : (is_array($map) ? $map : array());
    }

    private static function worker_map_set($stg, $options_table, $name, $map) {
        return self::set_staging_option($stg, $options_table, self::worker_map_option($name), (array) $map);
    }

    private static function worker_clear_maps($stg, $options_table) {
        foreach (array('post','term','tt','category','vocab','attribute','attribute_term') as $name) {
            $r = self::worker_option_delete($stg, $options_table, self::worker_map_option($name));
            if (is_wp_error($r)) return $r;
        }
        return true;
    }

    private static function worker_state_defaults() {
        return array(
            'job_id' => '',
            'status' => 'idle',
            'phase' => 'idle',
            'cursor' => array(),
            'message' => '',
            'created_at' => 0,
            'started_at' => 0,
            'updated_at' => 0,
            'completed_at' => 0,
            'source_marker' => '',
            'target_marker' => '',
            'identity' => array(),
            'stats' => array(),
            'warnings' => array(),
            'progress' => array(),
            'last_error' => '',
            'result' => array(),
        );
    }

    private static function worker_state_get($stg, $options_table) {
        $state = self::worker_option_get($stg, $options_table, self::WORKER_JOB_OPTION, array());
        if (is_wp_error($state)) return $state;
        return wp_parse_args(is_array($state) ? $state : array(), self::worker_state_defaults());
    }

    private static function worker_state_set($stg, $options_table, &$state) {
        $state = wp_parse_args(is_array($state) ? $state : array(), self::worker_state_defaults());
        self::worker_refresh_progress($state);
        $state['updated_at'] = time();
        return self::set_staging_option($stg, $options_table, self::WORKER_JOB_OPTION, $state);
    }

    private static function worker_stat_add(&$state, $key, $amount) {
        if (!isset($state['stats']) || !is_array($state['stats'])) $state['stats'] = array();
        $state['stats'][$key] = absint($state['stats'][$key] ?? 0) + max(0, (int) $amount);
    }

    private static function worker_warning(&$state, $message) {
        if (!isset($state['warnings']) || !is_array($state['warnings'])) $state['warnings'] = array();
        $message = sanitize_text_field((string) $message);
        if ('' !== $message && !in_array($message, $state['warnings'], true) && count($state['warnings']) < 100) {
            $state['warnings'][] = $message;
        }
    }


    /**
     * Catalogo visible de fases del worker. Se usa unicamente para informar
     * al administrador: que tabla se esta tratando, que queda pendiente y
     * cuanto ha avanzado el job. No decide la logica de copia.
     */
    private static function worker_phase_catalog() {
        $prefix = function_exists('seo_clonador_db_prefix') ? (string) seo_clonador_db_prefix('staging') : 'wp_';
        $t = static function($suffix) use ($prefix) { return $prefix . $suffix; };
        return array(
            'preflight' => array('label'=>'Prevalidando PRO y STAGING','kind'=>'check','tables'=>array()),
            'reset_posts' => array('label'=>'Vaciando objetos gestionados de STAGING','kind'=>'delete','tables'=>array($t('posts'),$t('postmeta'),$t('term_relationships'))),
            'reset_taxonomies' => array('label'=>'Vaciando taxonomias gestionadas de STAGING','kind'=>'delete','tables'=>array($t('terms'),$t('term_taxonomy'),$t('termmeta'),$t('term_relationships'))),
            'reset_custom' => array('label'=>'Vaciando tablas propias del catalogo','kind'=>'delete','tables'=>array($t('seo_object_vocabulary'),$t('seo_nodes'),$t('seo_relations'),$t('seo_faq'),$t('seo_type_role_map'),$t('sql_product_atributos'),$t('sql_atributos_aliases'),$t('sql_atributos_terminos'),$t('sql_atributos'),$t('seo_vocabulary'))),
            'posts' => array('label'=>'Copiando productos, posts y paginas','kind'=>'copy','tables'=>array($t('posts'))),
            'post_parents' => array('label'=>'Reconstruyendo jerarquia de posts','kind'=>'copy','tables'=>array($t('posts'))),
            'postmeta' => array('label'=>'Copiando metadatos portables','kind'=>'copy','tables'=>array($t('postmeta'))),
            'tax_terms' => array('label'=>'Copiando categorias, etiquetas y taxonomias','kind'=>'copy','tables'=>array($t('terms'),$t('term_taxonomy'))),
            'tax_parents' => array('label'=>'Reconstruyendo jerarquia de taxonomias','kind'=>'copy','tables'=>array($t('term_taxonomy'))),
            'termmeta' => array('label'=>'Copiando metadatos de terminos','kind'=>'copy','tables'=>array($t('termmeta'))),
            'tax_relationships' => array('label'=>'Copiando relaciones producto-contenido / taxonomia','kind'=>'copy','tables'=>array($t('term_relationships'))),
            'tax_counts' => array('label'=>'Recalculando conteos de taxonomias','kind'=>'copy','tables'=>array($t('term_taxonomy'))),
            'vocab' => array('label'=>'Copiando Vocabulary','kind'=>'copy','tables'=>array($t('seo_vocabulary'))),
            'vocab_parents' => array('label'=>'Reconstruyendo jerarquia de Vocabulary','kind'=>'copy','tables'=>array($t('seo_vocabulary'))),
            'attributes' => array('label'=>'Copiando atributos maestros','kind'=>'copy','tables'=>array($t('sql_atributos'))),
            'attribute_terms' => array('label'=>'Copiando terminos de atributos','kind'=>'copy','tables'=>array($t('sql_atributos_terminos'))),
            'type_role' => array('label'=>'Copiando mapa TIPO / ROL','kind'=>'copy','tables'=>array($t('seo_type_role_map'))),
            'attribute_aliases' => array('label'=>'Copiando alias de atributos','kind'=>'copy','tables'=>array($t('sql_atributos_aliases'))),
            'product_attributes' => array('label'=>'Copiando atributos SEO de productos','kind'=>'copy','tables'=>array($t('sql_product_atributos'))),
            'object_vocabulary' => array('label'=>'Copiando asignaciones de Vocabulary','kind'=>'copy','tables'=>array($t('seo_object_vocabulary'))),
            'nodes' => array('label'=>'Copiando nodos SEO','kind'=>'copy','tables'=>array($t('seo_nodes'))),
            'relations' => array('label'=>'Copiando relaciones SEO','kind'=>'copy','tables'=>array($t('seo_relations'))),
            'faqs' => array('label'=>'Copiando FAQs','kind'=>'copy','tables'=>array($t('seo_faq'))),
            'woo_attribute_taxonomies' => array('label'=>'Copiando taxonomias de atributos WooCommerce','kind'=>'copy','tables'=>array($t('woocommerce_attribute_taxonomies'))),
            'wc_meta_lookup' => array('label'=>'Copiando lookup principal de WooCommerce','kind'=>'copy','tables'=>array($t('wc_product_meta_lookup'))),
            'wc_attr_lookup' => array('label'=>'Copiando lookup de atributos WooCommerce','kind'=>'copy','tables'=>array($t('wc_product_attributes_lookup'))),
            'verify' => array('label'=>'VERIFICANDO la copia completa','kind'=>'verify','tables'=>array()),
            'complete' => array('label'=>'Cerrando clonacion verificada','kind'=>'verify','tables'=>array()),
            'completed' => array('label'=>'Clonacion completada y verificada','kind'=>'done','tables'=>array()),
        );
    }

    private static function worker_copied_total($stats) {
        $stats = is_array($stats) ? $stats : array();
        $total = 0;
        foreach ($stats as $key => $value) {
            if (!is_numeric($value)) continue;
            $key = (string) $key;
            if (0 === strpos($key, 'reset_')) continue;
            if (false !== strpos($key, 'omitid') || false !== strpos($key, 'omitted') || false !== strpos($key, 'huerfan')) continue;
            if ('verification' === $key) continue;
            $total += max(0, (int) $value);
        }
        return $total;
    }

    private static function worker_refresh_progress(&$state) {
        $catalog = self::worker_phase_catalog();
        $phases = array_keys($catalog);
        $phase = sanitize_key((string) ($state['phase'] ?? 'preflight'));
        $index = array_search($phase, $phases, true);
        if (false === $index) $index = 0;
        $entry = $catalog[$phase] ?? array('label'=>$phase,'kind'=>'copy','tables'=>array());

        $future_tables = array();
        for ($i = $index + 1; $i < count($phases); $i++) {
            foreach ((array) ($catalog[$phases[$i]]['tables'] ?? array()) as $table) {
                if (!in_array($table, $future_tables, true)) $future_tables[] = $table;
            }
        }
        $current_tables = array_values(array_unique((array) ($entry['tables'] ?? array())));
        $completed_tables = array();
        for ($i = 0; $i < $index; $i++) {
            foreach ((array) ($catalog[$phases[$i]]['tables'] ?? array()) as $table) {
                if (in_array($table, $current_tables, true) || in_array($table, $future_tables, true)) continue;
                if (!in_array($table, $completed_tables, true)) $completed_tables[] = $table;
            }
        }
        $percent = count($phases) > 1 ? (int) floor(($index / (count($phases) - 1)) * 100) : 0;
        if ('completed' === $phase || 'completed' === (string) ($state['status'] ?? '')) $percent = 100;

        $state['progress'] = array(
            'phase' => $phase,
            'phase_label' => sanitize_text_field((string) ($entry['label'] ?? $phase)),
            'kind' => sanitize_key((string) ($entry['kind'] ?? 'copy')),
            'phase_number' => min(count($phases), $index + 1),
            'phase_total' => count($phases),
            'percent' => max(0, min(100, $percent)),
            'current_tables' => $current_tables,
            'pending_tables' => $future_tables,
            'completed_tables' => $completed_tables,
            'copied_total' => self::worker_copied_total((array) ($state['stats'] ?? array())),
            'warnings_total' => count((array) ($state['warnings'] ?? array())),
        );
    }

    public static function initialize_manager_job($job_id, $preview) {
        $job_id = sanitize_key((string) $job_id);
        $preview = is_array($preview) ? $preview : array();
        if (!$job_id || empty($preview['source_marker']) || empty($preview['target_marker'])) {
            return new WP_Error('clonador_worker_init', 'No se puede inicializar el job sin huellas de simulacion.');
        }
        $stg = seo_clonador_open('staging');
        if (is_wp_error($stg)) return $stg;
        $options_table = seo_clonador_db_prefix('staging') . 'options';
        try {
            $r = self::worker_clear_maps($stg, $options_table);
            if (is_wp_error($r)) return $r;
            $state = self::worker_state_defaults();
            $state['job_id'] = $job_id;
            $state['status'] = 'queued';
            $state['phase'] = 'preflight';
            $state['message'] = 'Esperando al Gestor de procesos.';
            $state['created_at'] = time();
            $state['source_marker'] = (string) $preview['source_marker'];
            $state['target_marker'] = (string) $preview['target_marker'];
            $state['identity'] = isset($preview['identity']) && is_array($preview['identity']) ? $preview['identity'] : array();
            $r = self::worker_state_set($stg, $options_table, $state);
            if (is_wp_error($r)) return $r;
            return $state;
        } finally {
            @mysqli_close($stg);
        }
    }


    private static function worker_batch_reset_posts($stg, $stg_tables, &$state) {
        $limit = 300;
        $ids = self::ids($stg, "SELECT ID FROM `{$stg_tables['posts']}` WHERE " . self::post_type_sql($stg) . " ORDER BY ID LIMIT {$limit}", 'ID');
        if (is_wp_error($ids)) return $ids;
        if (!$ids) {
            $state['phase'] = 'reset_taxonomies';
            $state['cursor'] = array();
            $state['message'] = 'Objetos gestionados eliminados. Vaciando taxonomias de STAGING.';
            return true;
        }
        foreach (self::id_chunks($ids, 500) as $chunk) {
            $ids_sql = self::sql_ids($chunk);
            if (self::table_exists($stg, $stg_tables['comments']) && self::table_exists($stg, $stg_tables['commentmeta'])) {
                $comment_ids = self::ids($stg, "SELECT comment_ID FROM `{$stg_tables['comments']}` WHERE comment_post_ID IN ({$ids_sql})", 'comment_ID');
                if (is_wp_error($comment_ids)) return $comment_ids;
                $r = self::delete_ids($stg, $stg_tables['commentmeta'], 'comment_id', $comment_ids); if (is_wp_error($r)) return $r;
                $r = self::exec($stg, "DELETE FROM `{$stg_tables['comments']}` WHERE comment_post_ID IN ({$ids_sql})"); if (is_wp_error($r)) return $r;
            }
            $r = self::delete_ids($stg, $stg_tables['postmeta'], 'post_id', $chunk); if (is_wp_error($r)) return $r;
            $r = self::delete_ids($stg, $stg_tables['term_relationships'], 'object_id', $chunk); if (is_wp_error($r)) return $r;
            $prefix = substr($stg_tables['posts'], 0, -strlen('posts'));
            foreach (array('wc_product_meta_lookup','wc_product_attributes_lookup') as $suffix) {
                $lookup = $prefix . $suffix;
                if (!self::table_exists($stg, $lookup)) continue;
                $cols = self::table_columns($stg, $lookup); if (is_wp_error($cols)) return $cols;
                if (in_array('product_id', $cols, true)) { $r=self::delete_ids($stg,$lookup,'product_id',$chunk); if(is_wp_error($r))return$r; }
                if (in_array('product_or_parent_id', $cols, true)) { $r=self::delete_ids($stg,$lookup,'product_or_parent_id',$chunk); if(is_wp_error($r))return$r; }
            }
            $r = self::delete_ids($stg, $stg_tables['posts'], 'ID', $chunk); if (is_wp_error($r)) return $r;
        }
        self::worker_stat_add($state, 'reset_posts', count($ids));
        $state['message'] = 'Vaciando STAGING · objetos eliminados: ' . absint($state['stats']['reset_posts'] ?? 0) . '.';
        return true;
    }

    private static function worker_batch_reset_taxonomies($stg, $stg_tables, &$state) {
        $limit = 500;
        $rows = self::rows($stg, "SELECT term_taxonomy_id,term_id FROM `{$stg_tables['term_taxonomy']}` WHERE " . self::all_managed_taxonomy_sql($stg) . " ORDER BY term_taxonomy_id LIMIT {$limit}");
        if (is_wp_error($rows)) return $rows;
        if (!$rows) {
            $state['phase'] = 'reset_custom';
            $state['cursor'] = array('index'=>0);
            $state['message'] = 'Taxonomias gestionadas vaciadas. Limpiando tablas propias del catalogo.';
            return true;
        }
        $tt_ids=array();$term_ids=array();foreach($rows as $row){$tt_ids[]=absint($row['term_taxonomy_id']);$term_ids[]=absint($row['term_id']);}
        $r=self::delete_ids($stg,$stg_tables['term_relationships'],'term_taxonomy_id',$tt_ids);if(is_wp_error($r))return$r;
        $r=self::delete_ids($stg,$stg_tables['term_taxonomy'],'term_taxonomy_id',$tt_ids);if(is_wp_error($r))return$r;
        $r=self::delete_orphan_terms($stg,$stg_tables,$term_ids);if(is_wp_error($r))return$r;
        self::worker_stat_add($state,'reset_taxonomies',count($tt_ids));
        $state['message']='Vaciando STAGING · taxonomias eliminadas: '.absint($state['stats']['reset_taxonomies']??0).'.';
        return true;
    }

    private static function worker_batch_reset_custom($stg, $stg_tables, &$state) {
        $targets = array(
            array('key'=>'seo_object_vocabulary','where'=>self::managed_custom_where($stg,'seo_object_vocabulary')),
            array('key'=>'seo_nodes','where'=>self::managed_custom_where($stg,'seo_nodes')),
            array('key'=>'seo_relations','where'=>self::managed_custom_where($stg,'seo_relations')),
            array('key'=>'seo_faq','where'=>self::managed_custom_where($stg,'seo_faq')),
            array('key'=>'seo_type_role_map','where'=>'1=1'),
            array('key'=>'sql_product_atributos','where'=>'1=1'),
            array('key'=>'sql_atributos_aliases','where'=>'1=1'),
            array('key'=>'sql_atributos_terminos','where'=>'1=1'),
            array('key'=>'sql_atributos','where'=>'1=1'),
            array('key'=>'seo_vocabulary','where'=>'1=1'),
        );
        $index = absint($state['cursor']['index'] ?? 0);
        if ($index >= count($targets)) {
            $state['phase']='posts';$state['cursor']=array('id'=>0);$state['message']='STAGING vaciado dentro del perimetro gestionado. Reconstruyendo desde PRO.';
            return true;
        }
        $target=$targets[$index];$table=$stg_tables[$target['key']];$where=$target['where'];
        $r=self::exec($stg,"DELETE FROM `{$table}` WHERE {$where} LIMIT 5000");if(is_wp_error($r))return$r;
        $affected=max(0,(int)mysqli_affected_rows($stg));self::worker_stat_add($state,'reset_'.$target['key'],$affected);
        if($affected<5000){$index++;}
        $state['cursor']=array('index'=>$index);$state['message']='Vaciando tabla '.$target['key'].' · eliminadas '.absint($state['stats']['reset_'.$target['key']]??0).' filas.';
        return true;
    }

    private static function worker_batch_posts($pro, $stg, $pro_tables, $stg_tables, &$state) {
        $limit = 100;
        $cursor = absint($state['cursor']['id'] ?? 0);
        $source_columns = array(
            'ID','post_date','post_date_gmt','post_content','post_title','post_excerpt','post_status',
            'comment_status','ping_status','post_password','post_name','to_ping','pinged','post_modified',
            'post_modified_gmt','post_content_filtered','post_parent','menu_order','post_type','post_mime_type'
        );
        $columns_sql = implode(',', array_map(array(__CLASS__, 'ident'), $source_columns));
        $where = self::post_type_sql($pro) . " AND post_status<>'trash'";
        $rows = self::rows($pro, "SELECT {$columns_sql} FROM `{$pro_tables['posts']}` WHERE {$where} AND ID>{$cursor} ORDER BY ID ASC LIMIT {$limit}");
        if (is_wp_error($rows)) return $rows;
        $map = self::worker_map_get($stg, $stg_tables['options'], 'post');
        if (is_wp_error($map)) return $map;
        foreach ($rows as $row) {
            $sid = absint($row['ID']);
            $data = $row;
            unset($data['ID'], $data['post_parent']);
            $insert_columns = array_keys($data);
            $insert_values = array_values($data);
            $insert_columns[] = 'post_author'; $insert_values[] = 0;
            $insert_columns[] = 'post_parent'; $insert_values[] = 0;
            $insert_columns[] = 'guid'; $insert_values[] = '';
            $col_sql = implode(',', array_map(array(__CLASS__, 'ident'), $insert_columns));
            $val_sql = implode(',', array_map(static function($v) use ($stg){ return self::sql_value($stg, $v); }, $insert_values));
            $r = self::exec($stg, "INSERT INTO `{$stg_tables['posts']}` ({$col_sql}) VALUES ({$val_sql})");
            if (is_wp_error($r)) return $r;
            $tid = absint(mysqli_insert_id($stg));
            if (!$tid) return new WP_Error('clone_post_insert_id', 'STAGING no devolvio ID local al crear un objeto.');
            $map[$sid] = $tid;
            $cursor = $sid;
        }
        $r = self::worker_map_set($stg, $stg_tables['options'], 'post', $map);
        if (is_wp_error($r)) return $r;
        self::worker_stat_add($state, 'posts', count($rows));
        $state['cursor'] = array('id' => $cursor);
        $state['message'] = 'Objetos WordPress/WooCommerce clonados: ' . absint($state['stats']['posts'] ?? 0) . '.';
        if (count($rows) < $limit) {
            $state['phase'] = 'post_parents';
            $state['cursor'] = array('id' => 0);
        }
        return true;
    }

    private static function worker_batch_post_parents($pro, $stg, $pro_tables, $stg_tables, &$state) {
        $limit = 400;
        $cursor = absint($state['cursor']['id'] ?? 0);
        $rows = self::rows($pro, "SELECT ID,post_parent FROM `{$pro_tables['posts']}` WHERE " . self::post_type_sql($pro) . " AND post_status<>'trash' AND ID>{$cursor} ORDER BY ID LIMIT {$limit}");
        if (is_wp_error($rows)) return $rows;
        $map = self::worker_map_get($stg, $stg_tables['options'], 'post');
        if (is_wp_error($map)) return $map;
        foreach ($rows as $row) {
            $sid = absint($row['ID']);
            $tid = absint($map[$sid] ?? 0);
            if (!$tid) return new WP_Error('clonador_post_parent_map', 'Falta el mapa local del objeto PRO #' . $sid . '.');
            $sp = absint($row['post_parent'] ?? 0);
            $tp = $sp ? absint($map[$sp] ?? 0) : 0;
            $r = self::exec($stg, "UPDATE `{$stg_tables['posts']}` SET post_parent={$tp} WHERE ID={$tid}");
            if (is_wp_error($r)) return $r;
            $cursor = $sid;
        }
        $state['cursor'] = array('id' => $cursor);
        $state['message'] = 'Remapeando jerarquias de productos/posts/paginas.';
        if (count($rows) < $limit) {
            $state['phase'] = 'postmeta';
            $state['cursor'] = array('id' => 0);
        }
        return true;
    }

    private static function worker_batch_postmeta($pro, $stg, $pro_tables, $stg_tables, &$state) {
        $limit = 500;
        $cursor = absint($state['cursor']['id'] ?? 0);
        $rows = self::rows($pro,
            "SELECT pm.meta_id,pm.post_id,pm.meta_key,pm.meta_value
             FROM `{$pro_tables['postmeta']}` pm JOIN `{$pro_tables['posts']}` p ON p.ID=pm.post_id
             WHERE " . self::post_type_sql($pro, 'p') . " AND p.post_status<>'trash' AND " . self::meta_portable_sql('pm') . " AND pm.meta_id>{$cursor}
             ORDER BY pm.meta_id ASC LIMIT {$limit}"
        );
        if (is_wp_error($rows)) return $rows;
        $map = self::worker_map_get($stg, $stg_tables['options'], 'post');
        if (is_wp_error($map)) return $map;
        $insert = array();
        foreach ($rows as $row) {
            $sid = absint($row['post_id']);
            $tid = absint($map[$sid] ?? 0);
            if (!$tid) continue;
            $meta_value = self::remap_postmeta_value((string) $row['meta_key'], (string) $row['meta_value'], $map, $state['stats']);
            if (is_wp_error($meta_value)) return $meta_value;
            $insert[] = array('post_id'=>$tid,'meta_key'=>(string)$row['meta_key'],'meta_value'=>(string)$meta_value);
            $cursor = absint($row['meta_id']);
        }
        foreach (array_chunk($insert, 120) as $chunk) {
            $r = self::insert_rows($stg, $stg_tables['postmeta'], array('post_id','meta_key','meta_value'), $chunk, false);
            if (is_wp_error($r)) return $r;
        }
        self::worker_stat_add($state, 'postmeta', count($insert));
        $state['cursor'] = array('id' => $cursor);
        $state['message'] = 'Metadatos portables clonados: ' . absint($state['stats']['postmeta'] ?? 0) . '.';
        if (count($rows) < $limit) {
            if (!empty($state['stats']['postmeta_unresolved_references_total'])) {
                self::worker_warning($state, absint($state['stats']['postmeta_unresolved_references_total']) . ' referencias antiguas de upsell/cross-sell/children no existen en el nuevo perimetro y se omitieron.');
            }
            $state['phase'] = 'tax_terms';
            $state['cursor'] = array('id' => 0);
        }
        return true;
    }

    private static function worker_batch_tax_terms($pro, $stg, $pro_tables, $stg_tables, &$state) {
        $limit = 150;
        $cursor = absint($state['cursor']['id'] ?? 0);
        $rows = self::rows($pro,
            "SELECT t.term_id,t.name,t.slug,t.term_group,tt.term_taxonomy_id,tt.taxonomy,tt.description,tt.parent
             FROM `{$pro_tables['terms']}` t JOIN `{$pro_tables['term_taxonomy']}` tt ON tt.term_id=t.term_id
             WHERE " . self::all_managed_taxonomy_sql($pro, 'tt') . " AND tt.term_taxonomy_id>{$cursor}
             ORDER BY tt.term_taxonomy_id LIMIT {$limit}"
        );
        if (is_wp_error($rows)) return $rows;
        $term_map = self::worker_map_get($stg, $stg_tables['options'], 'term'); if (is_wp_error($term_map)) return $term_map;
        $tt_map = self::worker_map_get($stg, $stg_tables['options'], 'tt'); if (is_wp_error($tt_map)) return $tt_map;
        $category_map = self::worker_map_get($stg, $stg_tables['options'], 'category'); if (is_wp_error($category_map)) return $category_map;
        foreach ($rows as $row) {
            $source_term = absint($row['term_id']);
            $source_tt = absint($row['term_taxonomy_id']);
            $target_term = absint($term_map[$source_term] ?? 0);
            if (!$target_term) {
                $r = self::exec($stg, "INSERT INTO `{$stg_tables['terms']}` (name,slug,term_group) VALUES (" . self::sql_value($stg,$row['name']) . ',' . self::sql_value($stg,$row['slug']) . ',' . absint($row['term_group']) . ')');
                if (is_wp_error($r)) return $r;
                $target_term = absint(mysqli_insert_id($stg));
                if (!$target_term) return new WP_Error('clonador_term_insert', 'STAGING no devolvio term_id.');
                $term_map[$source_term] = $target_term;
            }
            $taxonomy = sanitize_key((string) $row['taxonomy']);
            $r = self::exec($stg, "INSERT INTO `{$stg_tables['term_taxonomy']}` (term_id,taxonomy,description,parent,count) VALUES ({$target_term}," . self::sql_value($stg,$taxonomy) . ',' . self::sql_value($stg,$row['description']) . ',0,0)');
            if (is_wp_error($r)) return $r;
            $target_tt = absint(mysqli_insert_id($stg));
            if (!$target_tt) return new WP_Error('clonador_tt_insert', 'STAGING no devolvio term_taxonomy_id.');
            $tt_map[$source_tt] = $target_tt;
            if ('product_cat' === $taxonomy) $category_map[$source_term] = $target_term;
            self::worker_stat_add($state, 'taxonomy_' . $taxonomy, 1);
            $cursor = $source_tt;
        }
        foreach (array('term'=>$term_map,'tt'=>$tt_map,'category'=>$category_map) as $name=>$map) {
            $r = self::worker_map_set($stg, $stg_tables['options'], $name, $map); if (is_wp_error($r)) return $r;
        }
        $state['cursor'] = array('id' => $cursor);
        $state['message'] = 'Taxonomias creadas: ' . count($tt_map) . '.';
        if (count($rows) < $limit) {
            $state['phase'] = 'tax_parents';
            $state['cursor'] = array('id' => 0);
        }
        return true;
    }

    private static function worker_batch_tax_parents($pro, $stg, $pro_tables, $stg_tables, &$state) {
        $limit = 500;
        $cursor = absint($state['cursor']['id'] ?? 0);
        $rows = self::rows($pro, "SELECT term_taxonomy_id,parent FROM `{$pro_tables['term_taxonomy']}` WHERE " . self::all_managed_taxonomy_sql($pro) . " AND term_taxonomy_id>{$cursor} ORDER BY term_taxonomy_id LIMIT {$limit}");
        if (is_wp_error($rows)) return $rows;
        $term_map = self::worker_map_get($stg, $stg_tables['options'], 'term'); if (is_wp_error($term_map)) return $term_map;
        $tt_map = self::worker_map_get($stg, $stg_tables['options'], 'tt'); if (is_wp_error($tt_map)) return $tt_map;
        foreach ($rows as $row) {
            $source_tt = absint($row['term_taxonomy_id']);
            $target_tt = absint($tt_map[$source_tt] ?? 0);
            if (!$target_tt) return new WP_Error('clonador_taxonomy_parent', 'Falta mapa term_taxonomy PRO #' . $source_tt . '.');
            $sp = absint($row['parent'] ?? 0);
            $tp = $sp ? absint($term_map[$sp] ?? 0) : 0;
            if ($sp && !$tp) return new WP_Error('clonador_taxonomy_parent', 'No se pudo remapear padre term_id PRO #' . $sp . '.');
            $r = self::exec($stg, "UPDATE `{$stg_tables['term_taxonomy']}` SET parent={$tp} WHERE term_taxonomy_id={$target_tt}");
            if (is_wp_error($r)) return $r;
            $cursor = $source_tt;
        }
        $state['cursor'] = array('id'=>$cursor);
        $state['message'] = 'Remapeando jerarquias de categorias/taxonomias.';
        if (count($rows) < $limit) { $state['phase']='termmeta'; $state['cursor']=array('id'=>0); }
        return true;
    }

    private static function worker_batch_termmeta($pro, $stg, $pro_tables, $stg_tables, &$state) {
        $limit = 500;
        $cursor = absint($state['cursor']['id'] ?? 0);
        $rows = self::rows($pro,
            "SELECT DISTINCT tm.meta_id,tm.term_id,tm.meta_key,tm.meta_value
             FROM `{$pro_tables['termmeta']}` tm JOIN `{$pro_tables['term_taxonomy']}` tt ON tt.term_id=tm.term_id
             WHERE " . self::all_managed_taxonomy_sql($pro,'tt') . " AND " . self::termmeta_portable_sql('tm') . " AND tm.meta_id>{$cursor}
             ORDER BY tm.meta_id LIMIT {$limit}"
        );
        if (is_wp_error($rows)) return $rows;
        $term_map = self::worker_map_get($stg, $stg_tables['options'], 'term'); if (is_wp_error($term_map)) return $term_map;
        $insert=array();
        foreach($rows as $row){
            $tid=absint($term_map[absint($row['term_id'])]??0); if(!$tid) continue;
            $insert[]=array('term_id'=>$tid,'meta_key'=>$row['meta_key'],'meta_value'=>$row['meta_value']);
            $cursor=absint($row['meta_id']);
        }
        foreach(array_chunk($insert,150) as $chunk){$r=self::insert_rows($stg,$stg_tables['termmeta'],array('term_id','meta_key','meta_value'),$chunk,false);if(is_wp_error($r))return$r;}
        self::worker_stat_add($state,'termmeta',count($insert));
        $state['cursor']=array('id'=>$cursor);$state['message']='Termmeta portable clonado: '.absint($state['stats']['termmeta']??0).'.';
        if(count($rows)<$limit){$state['phase']='tax_relationships';$state['cursor']=array('object_id'=>0,'tt'=>0);}
        return true;
    }

    private static function worker_batch_tax_relationships($pro,$stg,$pro_tables,$stg_tables,&$state){
        $limit=600;$object=absint($state['cursor']['object_id']??0);$ttc=absint($state['cursor']['tt']??0);
        $rows=self::rows($pro,"SELECT tr.object_id,tr.term_taxonomy_id,tr.term_order FROM `{$pro_tables['term_relationships']}` tr JOIN `{$pro_tables['term_taxonomy']}` tt ON tt.term_taxonomy_id=tr.term_taxonomy_id JOIN `{$pro_tables['posts']}` p ON p.ID=tr.object_id WHERE ".self::all_managed_taxonomy_sql($pro,'tt')." AND ".self::post_type_sql($pro,'p')." AND p.post_status<>'trash' AND (tr.object_id>{$object} OR (tr.object_id={$object} AND tr.term_taxonomy_id>{$ttc})) ORDER BY tr.object_id,tr.term_taxonomy_id LIMIT {$limit}");
        if(is_wp_error($rows))return$rows;
        $post_map=self::worker_map_get($stg,$stg_tables['options'],'post');if(is_wp_error($post_map))return$post_map;
        $tt_map=self::worker_map_get($stg,$stg_tables['options'],'tt');if(is_wp_error($tt_map))return$tt_map;
        $insert=array();foreach($rows as $row){$to=absint($post_map[absint($row['object_id'])]??0);$tt=absint($tt_map[absint($row['term_taxonomy_id'])]??0);if(!$to||!$tt)return new WP_Error('clonador_relationship_map','No se pudo remapear una relacion taxonomica.');$insert[]=array('object_id'=>$to,'term_taxonomy_id'=>$tt,'term_order'=>(int)$row['term_order']);$object=absint($row['object_id']);$ttc=absint($row['term_taxonomy_id']);}
        foreach(array_chunk($insert,200) as $chunk){$r=self::insert_rows($stg,$stg_tables['term_relationships'],array('object_id','term_taxonomy_id','term_order'),$chunk,false);if(is_wp_error($r))return$r;}
        self::worker_stat_add($state,'term_relationships',count($insert));$state['cursor']=array('object_id'=>$object,'tt'=>$ttc);$state['message']='Relaciones taxonomicas clonadas: '.absint($state['stats']['term_relationships']??0).'.';
        if(count($rows)<$limit){$state['phase']='tax_counts';$state['cursor']=array();}
        return true;
    }

    private static function worker_phase_tax_counts($stg,$stg_tables,&$state){
        $r=self::exec($stg,"UPDATE `{$stg_tables['term_taxonomy']}` tt SET tt.count=(SELECT COUNT(*) FROM `{$stg_tables['term_relationships']}` tr WHERE tr.term_taxonomy_id=tt.term_taxonomy_id) WHERE ".self::all_managed_taxonomy_sql($stg,'tt'));if(is_wp_error($r))return$r;
        $state['phase']='vocab';$state['cursor']=array('id'=>0);$state['message']='Conteos de taxonomias reconstruidos.';return true;
    }

    private static function worker_batch_vocab($pro,$stg,$pro_tables,$stg_tables,&$state){
        $limit=400;$cursor=absint($state['cursor']['id']??0);$rows=self::rows($pro,"SELECT id,semantic_group,slug,label,parent_id,source,active,created_at,updated_at FROM `{$pro_tables['seo_vocabulary']}` WHERE id>{$cursor} ORDER BY id LIMIT {$limit}");if(is_wp_error($rows))return$rows;
        $map=self::worker_map_get($stg,$stg_tables['options'],'vocab');if(is_wp_error($map))return$map;
        foreach($rows as $row){$sid=absint($row['id']);$r=self::exec($stg,"INSERT INTO `{$stg_tables['seo_vocabulary']}` (semantic_group,slug,label,parent_id,source,active,created_at,updated_at) VALUES (".self::sql_value($stg,$row['semantic_group']).','.self::sql_value($stg,$row['slug']).','.self::sql_value($stg,$row['label']).",NULL,".self::sql_value($stg,$row['source']).','.absint($row['active']).','.self::sql_value($stg,$row['created_at']).','.self::sql_value($stg,$row['updated_at']).')');if(is_wp_error($r))return$r;$map[$sid]=absint(mysqli_insert_id($stg));$cursor=$sid;}
        $r=self::worker_map_set($stg,$stg_tables['options'],'vocab',$map);if(is_wp_error($r))return$r;self::worker_stat_add($state,'seo_vocabulary',count($rows));$state['cursor']=array('id'=>$cursor);$state['message']='Vocabularios clonados: '.absint($state['stats']['seo_vocabulary']??0).'.';if(count($rows)<$limit){$state['phase']='vocab_parents';$state['cursor']=array('id'=>0);}return true;
    }

    private static function worker_batch_vocab_parents($pro,$stg,$pro_tables,$stg_tables,&$state){
        $limit=600;$cursor=absint($state['cursor']['id']??0);$rows=self::rows($pro,"SELECT id,parent_id FROM `{$pro_tables['seo_vocabulary']}` WHERE id>{$cursor} ORDER BY id LIMIT {$limit}");if(is_wp_error($rows))return$rows;$map=self::worker_map_get($stg,$stg_tables['options'],'vocab');if(is_wp_error($map))return$map;
        foreach($rows as $row){$sid=absint($row['id']);$tid=absint($map[$sid]??0);if(!$tid)return new WP_Error('clonador_vocab_map','Falta mapa vocabulario PRO #'.$sid.'.');$sp=absint($row['parent_id']??0);$tp=$sp?absint($map[$sp]??0):0;if($sp&&!$tp)return new WP_Error('clonador_vocab_parent','Padre vocabulario PRO #'.$sp.' no resoluble.');$r=self::exec($stg,"UPDATE `{$stg_tables['seo_vocabulary']}` SET parent_id=".($tp?$tp:'NULL')." WHERE id={$tid}");if(is_wp_error($r))return$r;$cursor=$sid;}
        $state['cursor']=array('id'=>$cursor);$state['message']='Jerarquia del vocabulario remapeada.';if(count($rows)<$limit){$state['phase']='attributes';$state['cursor']=array('id'=>0);}return true;
    }

    private static function worker_batch_attributes($pro,$stg,$pro_tables,$stg_tables,&$state){
        $limit=200;$cursor=absint($state['cursor']['id']??0);$rows=self::rows($pro,"SELECT * FROM `{$pro_tables['sql_atributos']}` WHERE id>{$cursor} ORDER BY id LIMIT {$limit}");if(is_wp_error($rows))return$rows;$map=self::worker_map_get($stg,$stg_tables['options'],'attribute');if(is_wp_error($map))return$map;
        $cols=array('slug','nombre','grupo','tipo','unidad_tipo','unidad_base','multiple','filtrable','visible','seo','orden','activo','created_at','updated_at');
        foreach($rows as $row){$sid=absint($row['id']);$data=array();foreach($cols as $c)$data[$c]=$row[$c]??null;$r=self::insert_rows($stg,$stg_tables['sql_atributos'],$cols,array($data),false);if(is_wp_error($r))return$r;$map[$sid]=absint(mysqli_insert_id($stg));$cursor=$sid;}
        $r=self::worker_map_set($stg,$stg_tables['options'],'attribute',$map);if(is_wp_error($r))return$r;self::worker_stat_add($state,'sql_atributos',count($rows));$state['cursor']=array('id'=>$cursor);$state['message']='Atributos maestros clonados: '.absint($state['stats']['sql_atributos']??0).'.';if(count($rows)<$limit){$state['phase']='attribute_terms';$state['cursor']=array('id'=>0);}return true;
    }

    private static function worker_batch_attribute_terms($pro,$stg,$pro_tables,$stg_tables,&$state){
        $limit=300;$cursor=absint($state['cursor']['id']??0);$rows=self::rows($pro,"SELECT id,atributo_id,slug,nombre,orden,activo FROM `{$pro_tables['sql_atributos_terminos']}` WHERE id>{$cursor} ORDER BY id LIMIT {$limit}");if(is_wp_error($rows))return$rows;$amap=self::worker_map_get($stg,$stg_tables['options'],'attribute');if(is_wp_error($amap))return$amap;$tmap=self::worker_map_get($stg,$stg_tables['options'],'attribute_term');if(is_wp_error($tmap))return$tmap;
        foreach($rows as $row){$sid=absint($row['id']);$a=absint($amap[absint($row['atributo_id'])]??0);if(!$a)return new WP_Error('clonador_attr_map','Atributo maestro no resoluble.');$r=self::exec($stg,"INSERT INTO `{$stg_tables['sql_atributos_terminos']}` (atributo_id,slug,nombre,orden,activo) VALUES ({$a},".self::sql_value($stg,$row['slug']).','.self::sql_value($stg,$row['nombre']).','.(int)$row['orden'].','.absint($row['activo']).')');if(is_wp_error($r))return$r;$tmap[$sid]=absint(mysqli_insert_id($stg));$cursor=$sid;}
        $r=self::worker_map_set($stg,$stg_tables['options'],'attribute_term',$tmap);if(is_wp_error($r))return$r;self::worker_stat_add($state,'sql_atributos_terminos',count($rows));$state['cursor']=array('id'=>$cursor);$state['message']='Terminos de atributos clonados: '.absint($state['stats']['sql_atributos_terminos']??0).'.';if(count($rows)<$limit){$state['phase']='type_role';$state['cursor']=array('id'=>0);}return true;
    }

    private static function worker_batch_type_role($pro,$stg,$pro_tables,$stg_tables,&$state){
        $limit=500;$cursor=absint($state['cursor']['id']??0);$rows=self::rows($pro,"SELECT id,type_vocabulary_id,role_vocabulary_id,confidence,source,active,created_at,updated_at FROM `{$pro_tables['seo_type_role_map']}` WHERE id>{$cursor} ORDER BY id LIMIT {$limit}");if(is_wp_error($rows))return$rows;$vmap=self::worker_map_get($stg,$stg_tables['options'],'vocab');if(is_wp_error($vmap))return$vmap;$ins=array();foreach($rows as $row){$tv=absint($vmap[absint($row['type_vocabulary_id'])]??0);$rv=absint($vmap[absint($row['role_vocabulary_id'])]??0);if(!$tv||!$rv)return new WP_Error('clonador_type_role','TIPO/ROL no resoluble.');$ins[]=array('type_vocabulary_id'=>$tv,'role_vocabulary_id'=>$rv,'confidence'=>$row['confidence'],'source'=>$row['source'],'active'=>$row['active'],'created_at'=>$row['created_at'],'updated_at'=>$row['updated_at']);$cursor=absint($row['id']);}foreach(array_chunk($ins,150) as $c){$r=self::insert_rows($stg,$stg_tables['seo_type_role_map'],array('type_vocabulary_id','role_vocabulary_id','confidence','source','active','created_at','updated_at'),$c,false);if(is_wp_error($r))return$r;}self::worker_stat_add($state,'seo_type_role_map',count($ins));$state['cursor']=array('id'=>$cursor);$state['message']='Mapas TIPO/ROL clonados: '.absint($state['stats']['seo_type_role_map']??0).'.';if(count($rows)<$limit){$state['phase']='attribute_aliases';$state['cursor']=array('id'=>0);}return true;
    }

    private static function worker_batch_attribute_aliases($pro,$stg,$pro_tables,$stg_tables,&$state){
        $limit=300;$cursor=absint($state['cursor']['id']??0);$rows=self::rows($pro,"SELECT id,atributo_id,termino_id,alias FROM `{$pro_tables['sql_atributos_aliases']}` WHERE id>{$cursor} ORDER BY id LIMIT {$limit}");if(is_wp_error($rows))return$rows;$amap=self::worker_map_get($stg,$stg_tables['options'],'attribute');if(is_wp_error($amap))return$amap;$tmap=self::worker_map_get($stg,$stg_tables['options'],'attribute_term');if(is_wp_error($tmap))return$tmap;$ins=array();foreach($rows as $row){$a=absint($amap[absint($row['atributo_id'])]??0);$st=absint($row['termino_id']??0);$t=$st?absint($tmap[$st]??0):0;if(!$a||($st&&!$t))return new WP_Error('clonador_alias_map','Alias de atributo no resoluble.');$ins[]=array('atributo_id'=>$a,'termino_id'=>$t?:null,'alias'=>$row['alias']);$cursor=absint($row['id']);}if($ins){$r=self::insert_rows($stg,$stg_tables['sql_atributos_aliases'],array('atributo_id','termino_id','alias'),$ins,false);if(is_wp_error($r))return$r;}self::worker_stat_add($state,'sql_atributos_aliases',count($ins));$state['cursor']=array('id'=>$cursor);$state['message']='Alias de atributos clonados.';if(count($rows)<$limit){$state['phase']='product_attributes';$state['cursor']=array('id'=>0);}return true;
    }

    private static function worker_batch_product_attributes($pro,$stg,$pro_tables,$stg_tables,&$state){
        $limit=600;$cursor=absint($state['cursor']['id']??0);$rows=self::rows($pro,"SELECT id,product_id,atributo_id,termino_id,valor_texto,valor_numero,valor_numero_max,unidad,valor_original,orden FROM `{$pro_tables['sql_product_atributos']}` WHERE id>{$cursor} ORDER BY id LIMIT {$limit}");if(is_wp_error($rows))return$rows;$pmap=self::worker_map_get($stg,$stg_tables['options'],'post');if(is_wp_error($pmap))return$pmap;$amap=self::worker_map_get($stg,$stg_tables['options'],'attribute');if(is_wp_error($amap))return$amap;$tmap=self::worker_map_get($stg,$stg_tables['options'],'attribute_term');if(is_wp_error($tmap))return$tmap;$ins=array();$skipped=0;foreach($rows as $row){$p=absint($pmap[absint($row['product_id'])]??0);if(!$p){$skipped++;$cursor=absint($row['id']);continue;}$a=absint($amap[absint($row['atributo_id'])]??0);$st=absint($row['termino_id']??0);$t=$st?absint($tmap[$st]??0):0;if(!$a||($st&&!$t))return new WP_Error('clonador_product_attr_map','Atributo/termino maestro no resoluble.');$ins[]=array('product_id'=>$p,'atributo_id'=>$a,'termino_id'=>$t?:null,'valor_texto'=>$row['valor_texto'],'valor_numero'=>$row['valor_numero'],'valor_numero_max'=>$row['valor_numero_max'],'unidad'=>$row['unidad'],'valor_original'=>$row['valor_original'],'orden'=>$row['orden']);$cursor=absint($row['id']);}foreach(array_chunk($ins,200) as $c){$r=self::insert_rows($stg,$stg_tables['sql_product_atributos'],array('product_id','atributo_id','termino_id','valor_texto','valor_numero','valor_numero_max','unidad','valor_original','orden'),$c,false);if(is_wp_error($r))return$r;}self::worker_stat_add($state,'sql_product_atributos',count($ins));self::worker_stat_add($state,'sql_product_atributos_omitidos_huerfanos',$skipped);$state['cursor']=array('id'=>$cursor);$state['message']='Atributos de producto clonados: '.absint($state['stats']['sql_product_atributos']??0).'.';if(count($rows)<$limit){if(!empty($state['stats']['sql_product_atributos_omitidos_huerfanos']))self::worker_warning($state,absint($state['stats']['sql_product_atributos_omitidos_huerfanos']).' relaciones de atributos con producto inexistente fueron omitidas.');$state['phase']='object_vocabulary';$state['cursor']=array('id'=>0);}return true;
    }

    private static function worker_batch_object_vocabulary($pro,$stg,$pro_tables,$stg_tables,&$state){
        $limit=800;$cursor=absint($state['cursor']['id']??0);$rows=self::rows($pro,"SELECT id,object_type,object_id,vocabulary_id,source,confidence,status,created_at,updated_at FROM `{$pro_tables['seo_object_vocabulary']}` WHERE object_type IN ('product','product_cat','page','post') AND id>{$cursor} ORDER BY id LIMIT {$limit}");if(is_wp_error($rows))return$rows;$pmap=self::worker_map_get($stg,$stg_tables['options'],'post');if(is_wp_error($pmap))return$pmap;$cmap=self::worker_map_get($stg,$stg_tables['options'],'category');if(is_wp_error($cmap))return$cmap;$vmap=self::worker_map_get($stg,$stg_tables['options'],'vocab');if(is_wp_error($vmap))return$vmap;$ins=array();$skip=0;foreach($rows as $row){$ot=sanitize_key((string)$row['object_type']);$sid=absint($row['object_id']);$oid='product_cat'===$ot?absint($cmap[$sid]??0):absint($pmap[$sid]??0);$vid=absint($vmap[absint($row['vocabulary_id'])]??0);if(!$oid){$skip++;$cursor=absint($row['id']);continue;}if(!$vid)return new WP_Error('clonador_object_vocab','Vocabulario maestro no resoluble para una asignacion.');$ins[]=array('object_type'=>$ot,'object_id'=>$oid,'vocabulary_id'=>$vid,'source'=>$row['source'],'confidence'=>$row['confidence'],'status'=>$row['status'],'created_at'=>$row['created_at'],'updated_at'=>$row['updated_at']);$cursor=absint($row['id']);}foreach(array_chunk($ins,200) as $c){$r=self::insert_rows($stg,$stg_tables['seo_object_vocabulary'],array('object_type','object_id','vocabulary_id','source','confidence','status','created_at','updated_at'),$c,false);if(is_wp_error($r))return$r;}self::worker_stat_add($state,'seo_object_vocabulary',count($ins));self::worker_stat_add($state,'seo_object_vocabulary_omitidos_huerfanos',$skip);$state['cursor']=array('id'=>$cursor);$state['message']='Asignaciones semanticas clonadas: '.absint($state['stats']['seo_object_vocabulary']??0).'.';if(count($rows)<$limit){if($skip)self::worker_warning($state,'Se omitieron asignaciones semanticas cuyo objeto propietario no existe en el perimetro clonado.');$state['phase']='nodes';$state['cursor']=array('id'=>0);}return true;
    }

    private static function worker_batch_nodes($pro,$stg,$pro_tables,$stg_tables,&$state){
        $limit=500;$cursor=absint($state['cursor']['id']??0);$rows=self::rows($pro,"SELECT id,object_type,object_id,seo_role,keywords,title,status,created_at,updated_at FROM `{$pro_tables['seo_nodes']}` WHERE object_type IN ('category','product','page','post') AND id>{$cursor} ORDER BY id LIMIT {$limit}");if(is_wp_error($rows))return$rows;$pmap=self::worker_map_get($stg,$stg_tables['options'],'post');if(is_wp_error($pmap))return$pmap;$cmap=self::worker_map_get($stg,$stg_tables['options'],'category');if(is_wp_error($cmap))return$cmap;$ins=array();$skip=0;foreach($rows as $row){$ot=sanitize_key((string)$row['object_type']);$sid=absint($row['object_id']);$oid='category'===$ot?absint($cmap[$sid]??0):absint($pmap[$sid]??0);if(!$oid){$skip++;$cursor=absint($row['id']);continue;}$ins[]=array('object_type'=>$ot,'object_id'=>$oid,'seo_role'=>$row['seo_role'],'keywords'=>$row['keywords'],'title'=>$row['title'],'status'=>$row['status'],'created_at'=>$row['created_at'],'updated_at'=>$row['updated_at']);$cursor=absint($row['id']);}foreach(array_chunk($ins,200) as $c){$r=self::insert_rows($stg,$stg_tables['seo_nodes'],array('object_type','object_id','seo_role','keywords','title','status','created_at','updated_at'),$c,false);if(is_wp_error($r))return$r;}self::worker_stat_add($state,'seo_nodes',count($ins));self::worker_stat_add($state,'seo_nodes_omitted_orphans',$skip);$state['cursor']=array('id'=>$cursor);$state['message']='Nodos SEO clonados: '.absint($state['stats']['seo_nodes']??0).'.';if(count($rows)<$limit){if(!empty($state['stats']['seo_nodes_omitted_orphans']))self::worker_warning($state,absint($state['stats']['seo_nodes_omitted_orphans']).' nodos SEO huerfanos fueron omitidos.');$state['phase']='relations';$state['cursor']=array('id'=>0);}return true;
    }

    private static function worker_batch_relations($pro,$stg,$pro_tables,$stg_tables,&$state){
        $limit=500;$cursor=absint($state['cursor']['id']??0);$types=self::relation_managed_types();$quoted="'".implode("','",array_map(static function($v)use($pro){return mysqli_real_escape_string($pro,$v);},$types))."'";$rows=self::rows($pro,"SELECT id,source_type,source_id,target_type,target_id,relation_type,created_at FROM `{$pro_tables['seo_relations']}` WHERE relation_type IN ({$quoted}) AND id>{$cursor} ORDER BY id LIMIT {$limit}");if(is_wp_error($rows))return$rows;$pmap=self::worker_map_get($stg,$stg_tables['options'],'post');if(is_wp_error($pmap))return$pmap;$cmap=self::worker_map_get($stg,$stg_tables['options'],'category');if(is_wp_error($cmap))return$cmap;$ins=array();$skip=0;foreach($rows as $row){$s=self::relation_endpoint_map($row['source_type'],$row['source_id'],$pmap,$cmap);$t=self::relation_endpoint_map($row['target_type'],$row['target_id'],$pmap,$cmap);if(!$s||!$t){$skip++;$cursor=absint($row['id']);continue;}$ins[]=array('source_type'=>$row['source_type'],'source_id'=>$s,'target_type'=>$row['target_type'],'target_id'=>$t,'relation_type'=>$row['relation_type'],'created_at'=>$row['created_at']);$cursor=absint($row['id']);}foreach(array_chunk($ins,200) as $c){$r=self::insert_rows($stg,$stg_tables['seo_relations'],array('source_type','source_id','target_type','target_id','relation_type','created_at'),$c,false);if(is_wp_error($r))return$r;}self::worker_stat_add($state,'seo_relations',count($ins));self::worker_stat_add($state,'seo_relations_omitidas_huerfanas',$skip);$state['cursor']=array('id'=>$cursor);$state['message']='Relaciones SEO clonadas: '.absint($state['stats']['seo_relations']??0).'.';if(count($rows)<$limit){if($skip)self::worker_warning($state,'Se omitieron relaciones SEO con extremos fuera del perimetro nuevo.');$state['phase']='faqs';$state['cursor']=array('id'=>0);}return true;
    }

    private static function worker_batch_faqs($pro,$stg,$pro_tables,$stg_tables,&$state){
        $limit=700;$cursor=absint($state['cursor']['id']??0);$rows=self::rows($pro,"SELECT id,object_type,object_id,question,answer,sort_order,active,load_count,open_count,created_at,updated_at FROM `{$pro_tables['seo_faq']}` WHERE object_type IN (1,2,3) AND id>{$cursor} ORDER BY id LIMIT {$limit}");if(is_wp_error($rows))return$rows;$pmap=self::worker_map_get($stg,$stg_tables['options'],'post');if(is_wp_error($pmap))return$pmap;$cmap=self::worker_map_get($stg,$stg_tables['options'],'category');if(is_wp_error($cmap))return$cmap;$ins=array();$skip=0;foreach($rows as $row){$ot=absint($row['object_type']);$sid=absint($row['object_id']);$oid=2===$ot?absint($cmap[$sid]??0):absint($pmap[$sid]??0);if(!$oid){$skip++;$cursor=absint($row['id']);continue;}$ins[]=array('object_type'=>$ot,'object_id'=>$oid,'question'=>$row['question'],'answer'=>$row['answer'],'sort_order'=>$row['sort_order'],'active'=>$row['active'],'load_count'=>$row['load_count'],'open_count'=>$row['open_count'],'created_at'=>$row['created_at'],'updated_at'=>$row['updated_at']);$cursor=absint($row['id']);}foreach(array_chunk($ins,150) as $c){$r=self::insert_rows($stg,$stg_tables['seo_faq'],array('object_type','object_id','question','answer','sort_order','active','load_count','open_count','created_at','updated_at'),$c,false);if(is_wp_error($r))return$r;}self::worker_stat_add($state,'seo_faq',count($ins));self::worker_stat_add($state,'seo_faq_omitidas_huerfanas',$skip);$state['cursor']=array('id'=>$cursor);$state['message']='FAQs clonadas: '.absint($state['stats']['seo_faq']??0).'.';if(count($rows)<$limit){if($skip)self::worker_warning($state,'Se omitieron FAQs cuyo propietario ya no existe en PRO.');$state['phase']='woo_attribute_taxonomies';$state['cursor']=array();}return true;
    }

    private static function worker_phase_woo_attribute_taxonomies($pro,$stg,$pro_tables,$stg_tables,&$state){
        $pp=substr($pro_tables['posts'],0,-strlen('posts'));$sp=substr($stg_tables['posts'],0,-strlen('posts'));$r=self::clone_wc_attribute_taxonomies_if_available($pro,$stg,$pp,$sp,$state['stats']);if(is_wp_error($r))return$r;$state['phase']='wc_meta_lookup';$state['cursor']=array('id'=>0);$state['message']='Tabla maestra de atributos WooCommerce procesada.';return true;
    }

    private static function worker_batch_wc_meta_lookup($pro,$stg,$pro_tables,$stg_tables,&$state){
        $pp=substr($pro_tables['posts'],0,-strlen('posts'));$sp=substr($stg_tables['posts'],0,-strlen('posts'));$source=$pp.'wc_product_meta_lookup';$target=$sp.'wc_product_meta_lookup';if(!self::table_exists($pro,$source)||!self::table_exists($stg,$target)){$state['phase']='wc_attr_lookup';$state['cursor']=array('offset'=>0);return true;}$cols=self::table_columns($pro,$source);$tcols=self::table_columns($stg,$target);if(is_wp_error($cols))return$cols;if(is_wp_error($tcols))return$tcols;if($cols!==$tcols||!in_array('product_id',$cols,true))return new WP_Error('clonador_wc_lookup_schema','Esquema wc_product_meta_lookup incompatible.');$cursor=absint($state['cursor']['id']??0);$sqlcols=implode(',',array_map(array(__CLASS__,'ident'),$cols));$rows=self::rows($pro,"SELECT {$sqlcols} FROM `{$source}` WHERE product_id>{$cursor} ORDER BY product_id LIMIT 600");if(is_wp_error($rows))return$rows;$pmap=self::worker_map_get($stg,$stg_tables['options'],'post');if(is_wp_error($pmap))return$pmap;$ins=array();foreach($rows as $row){$sid=absint($row['product_id']);$tid=absint($pmap[$sid]??0);$cursor=$sid;if(!$tid)continue;$row['product_id']=$tid;$ins[]=$row;}foreach(array_chunk($ins,150) as $c){$r=self::insert_rows($stg,$target,$cols,$c,true);if(is_wp_error($r))return$r;}self::worker_stat_add($state,'wc_product_meta_lookup',count($ins));$state['cursor']=array('id'=>$cursor);$state['message']='Lookup WooCommerce clonado: '.absint($state['stats']['wc_product_meta_lookup']??0).'.';if(count($rows)<600){$state['phase']='wc_attr_lookup';$state['cursor']=array('offset'=>0);}return true;
    }

    private static function worker_batch_wc_attr_lookup($pro,$stg,$pro_tables,$stg_tables,&$state){
        $pp=substr($pro_tables['posts'],0,-strlen('posts'));$sp=substr($stg_tables['posts'],0,-strlen('posts'));$source=$pp.'wc_product_attributes_lookup';$target=$sp.'wc_product_attributes_lookup';if(!self::table_exists($pro,$source)||!self::table_exists($stg,$target)){$state['phase']='verify';$state['cursor']=array();return true;}$cols=self::table_columns($pro,$source);$tcols=self::table_columns($stg,$target);if(is_wp_error($cols))return$cols;if(is_wp_error($tcols))return$tcols;if($cols!==$tcols)return new WP_Error('clonador_wc_attributes_lookup_schema','Esquema wc_product_attributes_lookup incompatible.');$offset=absint($state['cursor']['offset']??0);$sqlcols=implode(',',array_map(array(__CLASS__,'ident'),$cols));$rows=self::rows($pro,"SELECT {$sqlcols} FROM `{$source}` l JOIN `{$pro_tables['posts']}` p ON p.ID=l.product_id WHERE ".self::post_type_sql($pro,'p')." AND p.post_status<>'trash' ORDER BY l.product_or_parent_id,l.product_id,l.term_id LIMIT 500 OFFSET {$offset}");if(is_wp_error($rows))return$rows;$pmap=self::worker_map_get($stg,$stg_tables['options'],'post');if(is_wp_error($pmap))return$pmap;$tmap=self::worker_map_get($stg,$stg_tables['options'],'term');if(is_wp_error($tmap))return$tmap;$ins=array();foreach($rows as $row){$tp=absint($pmap[absint($row['product_or_parent_id'])]??0);$tprod=absint($pmap[absint($row['product_id'])]??0);$tt=absint($tmap[absint($row['term_id'])]??0);if(!$tp||!$tprod||!$tt)continue;$row['product_or_parent_id']=$tp;$row['product_id']=$tprod;$row['term_id']=$tt;$ins[]=$row;}foreach(array_chunk($ins,150) as $c){$r=self::insert_rows($stg,$target,$cols,$c,false);if(is_wp_error($r))return$r;}self::worker_stat_add($state,'wc_product_attributes_lookup',count($ins));$offset+=count($rows);$state['cursor']=array('offset'=>$offset);$state['message']='Lookup de atributos WooCommerce procesado.';if(count($rows)<500){$state['phase']='verify';$state['cursor']=array();}return true;
    }

    private static function worker_phase_verify($pro,$stg,$pro_tables,$stg_tables,&$state){
        $marker=self::environment_marker($pro,$pro_tables);if(is_wp_error($marker))return$marker;if(!hash_equals((string)$state['source_marker'],(string)$marker))return new WP_Error('clonador_source_changed','PRO cambio durante la clonacion. STAGING se marca incompleto; vuelve a simular y reinicia el clon.');
        $verification=self::verify_clone_counts($pro,$stg,$pro_tables,$stg_tables);
        if(is_wp_error($verification)){
            $data=$verification->get_error_data();
            $state['stats']['verification']=is_array($data)?$data:array('passed'=>false,'checks'=>array(),'summary'=>array('checks_total'=>0,'passed'=>0,'failed'=>1));
            $state['message']='VERIFICACION FALLIDA. STAGING queda incompleto y no se marca como clon correcto.';
            return $verification;
        }
        $state['stats']['verification']=$verification;$state['phase']='complete';$state['message']='Verificacion correcta. Cerrando clonacion.';return true;
    }

    private static function worker_phase_complete($stg,$stg_tables,&$state){
        $generation=function_exists('wp_generate_uuid4')?wp_generate_uuid4():uniqid('clone-',true);$payload=array('generation'=>$generation,'completed_at'=>time(),'source'=>'pro','destination'=>'staging','engine'=>'portable_clone_manual_worker_2.5.6','stats'=>(array)$state['stats'],'identity'=>(array)$state['identity'],'duration_seconds'=>max(0,time()-absint($state['started_at']??time())));
        $r=self::set_staging_option($stg,$stg_tables['options'],'seo_clonador_generation',$payload);if(is_wp_error($r))return$r;$r=self::set_staging_option($stg,$stg_tables['options'],'seo_clonador_last',$payload);if(is_wp_error($r))return$r;self::set_staging_option($stg,$stg_tables['options'],'seo_semantic_catalog_reindex_pending',array('reason'=>'environment_clonador','generation'=>$generation,'created_at'=>time()));self::set_staging_option($stg,$stg_tables['options'],'seo_semantic_catalog_drift',array('reason'=>'environment_clonador','generation'=>$generation,'created_at'=>time()));$state['status']='completed';$state['phase']='completed';$state['completed_at']=time();$state['message']='Clonacion PRO → STAGING terminada y verificada.';self::worker_refresh_progress($state);$verification=is_array($state['stats']['verification']??null)?$state['stats']['verification']:array();$state['result']=array('generation'=>$generation,'duration_seconds'=>$payload['duration_seconds'],'completed_at'=>$state['completed_at'],'copied_total'=>absint($state['progress']['copied_total']??0),'warnings_total'=>count((array)$state['warnings']),'verification'=>$verification);return true;
    }

    private static function worker_step($pro,$stg,$pro_tables,$stg_tables,&$state){
        switch((string)$state['phase']){
            case 'preflight':
                $analysis=self::analyze($pro,$stg,$pro_tables,$stg_tables);if(is_wp_error($analysis))return$analysis;if(!empty($analysis['conflicts']))return new WP_Error('clonador_worker_conflicts','Conflictos: '.implode(' | ',array_slice((array)$analysis['conflicts'],0,10)));if(!hash_equals((string)$state['source_marker'],(string)$analysis['source_marker']))return new WP_Error('clonador_worker_source_changed','PRO cambio desde la simulacion.');if(!hash_equals((string)$state['target_marker'],(string)$analysis['target_marker']))return new WP_Error('clonador_worker_target_changed','STAGING cambio desde la simulacion.');$state['identity']=(array)$analysis['identity'];$state['started_at']=time();$state['status']='running';$state['phase']='reset_posts';$state['cursor']=array();$state['message']='Prevalidacion correcta. Vaciando STAGING por lotes.';return true;
            case 'reset_posts': return self::worker_batch_reset_posts($stg,$stg_tables,$state);
            case 'reset_taxonomies': return self::worker_batch_reset_taxonomies($stg,$stg_tables,$state);
            case 'reset_custom': return self::worker_batch_reset_custom($stg,$stg_tables,$state);
            case 'posts': return self::worker_batch_posts($pro,$stg,$pro_tables,$stg_tables,$state);
            case 'post_parents': return self::worker_batch_post_parents($pro,$stg,$pro_tables,$stg_tables,$state);
            case 'postmeta': return self::worker_batch_postmeta($pro,$stg,$pro_tables,$stg_tables,$state);
            case 'tax_terms': return self::worker_batch_tax_terms($pro,$stg,$pro_tables,$stg_tables,$state);
            case 'tax_parents': return self::worker_batch_tax_parents($pro,$stg,$pro_tables,$stg_tables,$state);
            case 'termmeta': return self::worker_batch_termmeta($pro,$stg,$pro_tables,$stg_tables,$state);
            case 'tax_relationships': return self::worker_batch_tax_relationships($pro,$stg,$pro_tables,$stg_tables,$state);
            case 'tax_counts': return self::worker_phase_tax_counts($stg,$stg_tables,$state);
            case 'vocab': return self::worker_batch_vocab($pro,$stg,$pro_tables,$stg_tables,$state);
            case 'vocab_parents': return self::worker_batch_vocab_parents($pro,$stg,$pro_tables,$stg_tables,$state);
            case 'attributes': return self::worker_batch_attributes($pro,$stg,$pro_tables,$stg_tables,$state);
            case 'attribute_terms': return self::worker_batch_attribute_terms($pro,$stg,$pro_tables,$stg_tables,$state);
            case 'type_role': return self::worker_batch_type_role($pro,$stg,$pro_tables,$stg_tables,$state);
            case 'attribute_aliases': return self::worker_batch_attribute_aliases($pro,$stg,$pro_tables,$stg_tables,$state);
            case 'product_attributes': return self::worker_batch_product_attributes($pro,$stg,$pro_tables,$stg_tables,$state);
            case 'object_vocabulary': return self::worker_batch_object_vocabulary($pro,$stg,$pro_tables,$stg_tables,$state);
            case 'nodes': return self::worker_batch_nodes($pro,$stg,$pro_tables,$stg_tables,$state);
            case 'relations': return self::worker_batch_relations($pro,$stg,$pro_tables,$stg_tables,$state);
            case 'faqs': return self::worker_batch_faqs($pro,$stg,$pro_tables,$stg_tables,$state);
            case 'woo_attribute_taxonomies': return self::worker_phase_woo_attribute_taxonomies($pro,$stg,$pro_tables,$stg_tables,$state);
            case 'wc_meta_lookup': return self::worker_batch_wc_meta_lookup($pro,$stg,$pro_tables,$stg_tables,$state);
            case 'wc_attr_lookup': return self::worker_batch_wc_attr_lookup($pro,$stg,$pro_tables,$stg_tables,$state);
            case 'verify': return self::worker_phase_verify($pro,$stg,$pro_tables,$stg_tables,$state);
            case 'complete': return self::worker_phase_complete($stg,$stg_tables,$state);
            case 'completed': return true;
        }
        return new WP_Error('clonador_worker_phase','Fase desconocida: '.sanitize_text_field((string)$state['phase']));
    }

    /**
     * Ejecuta ventanas cortas desde el Gestor de procesos existente.
     * Cada batch confirma en STAGING sus filas, mapas y cursor antes de ceder.
     */
    public static function process_manager_slice($job_id, $budget = 20, $source = 'process_manager', $max_steps = 4) {
        $job_id=sanitize_key((string)$job_id);$budget=max(5,min(50,absint($budget)));$max_steps=max(1,min(4,absint($max_steps)));$started=microtime(true);
        $pair=self::open_pair();if(is_wp_error($pair))return$pair;list($pro,$stg)=$pair;$pro_tables=self::all_required_tables(seo_clonador_db_prefix('pro'));$stg_tables=self::all_required_tables(seo_clonador_db_prefix('staging'));
        $lock_name=self::LOCK_NAME.'_manager';$lock=self::scalar($stg,"SELECT GET_LOCK('".mysqli_real_escape_string($stg,$lock_name)."',0) AS l",'l');if('1'!==(string)$lock){@mysqli_close($pro);@mysqli_close($stg);return new WP_Error('clonador_worker_lock','Otra ventana del Clonador ya esta trabajando.');}
        try{
            $state=self::worker_state_get($stg,$stg_tables['options']);if(is_wp_error($state))return$state;if(!$job_id||$job_id!==sanitize_key((string)$state['job_id']))return new WP_Error('clonador_worker_job','El job local no coincide con el job activo de STAGING.');if('completed'===(string)$state['status'])return$state;if('failed'===(string)$state['status'])return new WP_Error('clonador_worker_failed',(string)$state['last_error']);
            $state['status']='running';$state['message']=$state['message']?:'Clonando por lotes mediante el Gestor de procesos.';
            $steps=0;
            while((microtime(true)-$started)<max(3,$budget-2)&&$steps<$max_steps&&'completed'!==(string)$state['status']){
                $r=self::exec($stg,'START TRANSACTION');if(is_wp_error($r))return$r;
                try{
                    $r=self::worker_step($pro,$stg,$pro_tables,$stg_tables,$state);if(is_wp_error($r))throw new RuntimeException($r->get_error_message());
                    $state['status']='completed'===(string)$state['phase']?'completed':'running';$state['updated_at']=time();$state['worker_source']=sanitize_key((string)$source);
                    $r=self::worker_state_set($stg,$stg_tables['options'],$state);if(is_wp_error($r))throw new RuntimeException($r->get_error_message());
                    $r=self::exec($stg,'COMMIT');if(is_wp_error($r))throw new RuntimeException($r->get_error_message());
                }catch(Throwable $e){@mysqli_query($stg,'ROLLBACK');$state['status']='failed';$state['last_error']=sanitize_text_field($e->getMessage());$state['message']='Clonacion detenida en fase '.sanitize_key((string)$state['phase']).'. STAGING esta incompleto; reiniciar vuelve a vaciarlo.';$state['completed_at']=time();self::worker_refresh_progress($state);self::worker_state_set($stg,$stg_tables['options'],$state);return new WP_Error('clonador_worker_slice',$e->getMessage(),array('state'=>$state));}
                $steps++;
            }
            return $state;
        }finally{@mysqli_query($stg,"SELECT RELEASE_LOCK('".mysqli_real_escape_string($stg,$lock_name)."')");@mysqli_close($pro);@mysqli_close($stg);}
    }

    /**
     * Ejecuta un plan previamente simulado desde un worker desacoplado.
     * Revalida PRO/STAGING antes de tocar datos y conserva la transaccion atomica.
     */
    public static function run_background_clone($saved, $progress_callback = null) {
        $saved = is_array($saved) ? $saved : array();
        if (empty($saved['source_marker']) || empty($saved['target_marker'])) {
            return new WP_Error('clonador_background_preview', 'El worker no recibio una simulacion valida.');
        }
        @set_time_limit(0);
        ignore_user_abort(true);
        $pair = self::open_pair();
        if (is_wp_error($pair)) return $pair;
        list($pro, $stg) = $pair;
        $pro_prefix = seo_clonador_db_prefix('pro');
        $stg_prefix = seo_clonador_db_prefix('staging');
        $pro_tables = self::all_required_tables($pro_prefix);
        $stg_tables = self::all_required_tables($stg_prefix);
        try {
            if (is_callable($progress_callback)) {
                call_user_func($progress_callback, 'preflight', 'Revalidando el plan antes de iniciar la transaccion.', array('stats'=>array()));
            }
            $analysis = self::analyze($pro, $stg, $pro_tables, $stg_tables);
            if (is_wp_error($analysis)) return $analysis;
            if (!empty($analysis['conflicts'])) {
                return new WP_Error('clonador_background_conflicts', 'La prevalidacion contiene conflictos: ' . implode(' | ', array_slice((array)$analysis['conflicts'], 0, 10)));
            }
            if (!hash_equals((string) $saved['source_marker'], (string) $analysis['source_marker'])) {
                return new WP_Error('clonador_background_source_changed', 'PRO ha cambiado desde la simulacion. No se ha escrito nada; simula de nuevo.');
            }
            if (!hash_equals((string) $saved['target_marker'], (string) $analysis['target_marker'])) {
                return new WP_Error('clonador_background_target_changed', 'STAGING ha cambiado desde la simulacion. No se ha escrito nada; simula de nuevo.');
            }
            return self::apply_clone($pro, $stg, $pro_tables, $stg_tables, (array) $analysis['identity'], $progress_callback);
        } finally {
            @mysqli_close($pro);
            @mysqli_close($stg);
        }
    }

    public static function ajax_preview() {
        $auth = self::authorize();
        if (is_wp_error($auth)) wp_send_json_error(array('message'=>$auth->get_error_message()), 403);
        @set_time_limit(0);
        $pair = self::open_pair();
        if (is_wp_error($pair)) wp_send_json_error(array('message'=>$pair->get_error_message()), 500);
        list($pro, $stg) = $pair;
        $pro_prefix = seo_clonador_db_prefix('pro');
        $stg_prefix = seo_clonador_db_prefix('staging');
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
                'actions' => (array) ($analysis['actions'] ?? array()),
                'identity_resolution' => (array) ($analysis['identity_resolution'] ?? array()),
                'reference_audit' => (array) ($analysis['reference_audit'] ?? array()),
                'plan_version' => defined('SEO_CLONADOR_VERSION') ? SEO_CLONADOR_VERSION : '2.5.6',
                'engine_revision' => 'academia-cloner-manual-worker-2.5.6',
                'dry_run' => true,
                'writes_performed' => 0,
                'conflicts' => (array) $analysis['conflicts'],
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
            wp_send_json_error(array('message'=>'Falta la confirmacion explicita de la clonacion.'), 400);
        }
        $saved = get_transient(self::preview_key());
        if (!is_array($saved)) wp_send_json_error(array('message'=>'La simulacion ha caducado. Simula de nuevo antes de escribir.'), 409);

        // La peticion web solo revalida y entrega el trabajo. La clonacion real
        // se ejecuta fuera del navegador mediante el worker del subsistema
        // includes/procesos.
        $pair = self::open_pair();
        if (is_wp_error($pair)) wp_send_json_error(array('message'=>$pair->get_error_message()), 500);
        list($pro, $stg) = $pair;
        $pro_prefix = seo_clonador_db_prefix('pro');
        $stg_prefix = seo_clonador_db_prefix('staging');
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
            if (!class_exists('SEO_Clonador_Process') || !is_callable(array('SEO_Clonador_Process','start_new'))) {
                wp_send_json_error(array('message'=>'No esta cargado el proceso manual del Clonador en includes/procesos/clonador.'), 500);
            }
            $saved['identity'] = (array) $analysis['identity'];
            $speed = max(1, min(5, absint($_POST['speed'] ?? 3)));
            $started = SEO_Clonador_Process::start_new($saved, $speed);
            if (is_wp_error($started)) wp_send_json_error(array('message'=>$started->get_error_message()), 500);
            delete_transient(self::preview_key());
            wp_send_json_success(array(
                'message' => 'Clonacion INICIADA por el usuario. El worker solo gestionara los lotes y fases.',
                'job' => $started,
                'background' => true,
            ));
        } finally {
            @mysqli_close($pro);
            @mysqli_close($stg);
        }
    }

    public static function ajax_status() {
        $auth = self::authorize();
        if (is_wp_error($auth)) wp_send_json_error(array('message'=>$auth->get_error_message()), 403);
        if (!class_exists('SEO_Clonador_Process') || !is_callable(array('SEO_Clonador_Process','public_state'))) {
            wp_send_json_error(array('message'=>'No esta disponible el estado del worker del Clonador.'), 500);
        }
        wp_send_json_success(array('job' => SEO_Clonador_Process::public_state()));
    }

    public static function ajax_stop() {
        $auth = self::authorize();
        if (is_wp_error($auth)) wp_send_json_error(array('message'=>$auth->get_error_message()), 403);
        if (!class_exists('SEO_Clonador_Process') || !is_callable(array('SEO_Clonador_Process','stop'))) {
            wp_send_json_error(array('message'=>'No esta disponible el control de parada del Clonador.'), 500);
        }
        wp_send_json_success(array(
            'message' => 'Parada solicitada por el usuario. Si habia un lote en curso, terminara ese lote y no arrancara otro.',
            'job' => SEO_Clonador_Process::stop(),
        ));
    }

    public static function ajax_resume() {
        $auth = self::authorize();
        if (is_wp_error($auth)) wp_send_json_error(array('message'=>$auth->get_error_message()), 403);
        if (!class_exists('SEO_Clonador_Process') || !is_callable(array('SEO_Clonador_Process','resume'))) {
            wp_send_json_error(array('message'=>'No esta disponible la reanudacion del Clonador.'), 500);
        }
        $job = SEO_Clonador_Process::resume();
        if (is_wp_error($job)) wp_send_json_error(array('message'=>$job->get_error_message()), 409);
        wp_send_json_success(array('message'=>'Clonacion REANUDADA por el usuario.', 'job'=>$job));
    }

    public static function ajax_speed() {
        $auth = self::authorize();
        if (is_wp_error($auth)) wp_send_json_error(array('message'=>$auth->get_error_message()), 403);
        if (!class_exists('SEO_Clonador_Process') || !is_callable(array('SEO_Clonador_Process','set_speed'))) {
            wp_send_json_error(array('message'=>'No esta disponible el control de velocidad del Clonador.'), 500);
        }
        $speed = max(1, min(5, absint($_POST['speed'] ?? 3)));
        wp_send_json_success(array('message'=>'Velocidad ajustada.', 'job'=>SEO_Clonador_Process::set_speed($speed)));
    }

    /**
     * Si la escritura se lanzo remotamente desde PRO, la siguiente carga de
     * wp-admin en STAGING invalida cache persistente para no servir objetos
     * anteriores a la clonacion. No toca Academia ni el indice aprendido.
     */
    public static function consume_staging_generation() {
        if (!function_exists('seo_clonador_current_env') || 'staging' !== seo_clonador_current_env()) return;
        $payload = get_option('seo_clonador_generation', array());
        if (!is_array($payload) || empty($payload['generation'])) return;
        $generation = (string) $payload['generation'];
        $seen = (string) get_option('seo_clonador_generation_seen', '');
        if ($seen === $generation) return;
        if (function_exists('wp_cache_flush')) wp_cache_flush();
        delete_transient('wc_attribute_taxonomies');
        if (class_exists('WC_Cache_Helper') && method_exists('WC_Cache_Helper', 'invalidate_cache_group')) {
            WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');
        }
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients();
        }
        update_option('seo_clonador_generation_seen', $generation, false);
        add_action('admin_notices', static function () {
            if (!current_user_can('manage_options')) return;
            $url = admin_url('admin.php?page=seo-dependiente');
            echo '<div class="notice notice-success"><p><strong>PRO → STAGING:</strong> la clonacion del catalogo termino. La caché local de STAGING se ha invalidado. <a href="' . esc_url($url) . '">Reindexa Dependiente</a> para regenerar su índice derivado con el catálogo alineado.</p></div>';
        });
    }
}

SEO_Clonador_Engine::init();
