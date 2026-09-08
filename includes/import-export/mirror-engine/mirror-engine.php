<?php
/**
 * SEO System - MirrorEngine portable PRO -> STAGING.
 *
 * Comparator detects/explains/verifies. This service owns destructive writes.
 * PRO is always source; STAGING is always destination. Source numeric IDs may
 * be used as transient audit/map keys but NEVER to locate a destination row.
 * Destination objects are resolved by portable identity and all relationships
 * are rebuilt with STAGING-local IDs.
 *
 * Portable identities:
 * - product: catalog_uid -> provider+external_id -> unique SKU -> unique slug
 * - page/post: post_type + unique slug
 * - product_cat: full path of slugs
 * - product_tag/post_tag: taxonomy + slug
 * - semantic vocabulary: semantic_group + slug
 * - attributes: attribute slug; terms: attribute slug + term slug
 *
 * @package SEOSystem
 * @subpackage ImportExport
 * @since 2.4.0
 */

defined('ABSPATH') || exit;

final class SEO_Environment_Mirror_Engine {
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
        $stg_settings = function_exists('seo_environment_db_settings') ? (array) seo_environment_db_settings('staging') : array();
        $staging_mode = sanitize_key((string)($stg_settings['staging_mode'] ?? ''));
        if ('disposable_mirror' !== $staging_mode && empty($stg_settings['disposable_mirror'])) {
            return new WP_Error('full_mirror_policy', 'STAGING no esta marcado como laboratorio desechable. Activa la politica disposable_mirror en la conexion STAGING antes de permitir una copia destructiva.');
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
            "{$p}meta_key NOT IN ('_edit_lock','_edit_last','_wp_page_template','_wp_old_slug','_wp_old_date','_wp_desired_post_slug','_pingme','_encloseme','_product_attributes','_children')",
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
        return array('_upsell_ids', '_crosssell_ids');
    }

    private static function remap_postmeta_value($meta_key, $meta_value, $post_map) {
        $meta_key = (string) $meta_key;
        if (!in_array($meta_key, self::remapped_postmeta_keys(), true)) {
            return $meta_value;
        }
        $value = maybe_unserialize($meta_value);
        if (!is_array($value)) {
            return new WP_Error('mirror_postmeta_reference', 'El metadato ' . $meta_key . ' no contiene una lista portable de IDs como se esperaba.');
        }
        $mapped = array();
        foreach ($value as $source_id) {
            $source_id = absint($source_id);
            if (!$source_id) continue;
            if (!isset($post_map[$source_id]) || !absint($post_map[$source_id])) {
                return new WP_Error('mirror_postmeta_reference', 'No se pudo remapear ' . $meta_key . ': el objeto PRO #' . $source_id . ' no tiene destino local en STAGING.');
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


    private static function post_identity_rows($mysqli, $tables, $source_only = false) {
        $where = self::post_type_sql($mysqli, 'p');
        if ($source_only) $where .= " AND p.post_status<>'trash'";
        $rows = self::rows($mysqli,
            "SELECT p.ID,p.post_type,p.post_status,p.post_title,p.post_name,
                    MAX(CASE WHEN pm.meta_key='_seo_catalog_uid' THEN pm.meta_value END) catalog_uid,
                    MAX(CASE WHEN pm.meta_key='_seo_proveedor' THEN pm.meta_value END) provider,
                    MAX(CASE WHEN pm.meta_key='_seo_proveedor_id_externo' THEN pm.meta_value END) external_id,
                    MAX(CASE WHEN pm.meta_key='_sku' THEN pm.meta_value END) sku
             FROM `{$tables['posts']}` p
             LEFT JOIN `{$tables['postmeta']}` pm ON pm.post_id=p.ID
                AND pm.meta_key IN ('_seo_catalog_uid','_seo_proveedor','_seo_proveedor_id_externo','_sku')
             WHERE {$where}
             GROUP BY p.ID,p.post_type,p.post_status,p.post_title,p.post_name
             ORDER BY p.post_type,p.ID"
        );
        if (is_wp_error($rows)) return $rows;
        foreach ($rows as &$row) {
            $row['ID'] = absint($row['ID']);
            $row['post_type'] = sanitize_key((string) $row['post_type']);
            $row['post_name'] = sanitize_title((string) $row['post_name']);
            foreach (array('catalog_uid','provider','external_id','sku') as $key) {
                $row[$key] = trim((string) ($row[$key] ?? ''));
            }
        }
        unset($row);
        return $rows;
    }

    private static function post_portable_keys($row) {
        $type = sanitize_key((string) ($row['post_type'] ?? ''));
        $keys = array();
        if ('product' === $type) {
            $uid = trim((string) ($row['catalog_uid'] ?? ''));
            $provider = trim((string) ($row['provider'] ?? ''));
            $external = trim((string) ($row['external_id'] ?? ''));
            $sku = trim((string) ($row['sku'] ?? ''));
            $slug = sanitize_title((string) ($row['post_name'] ?? ''));
            if ($uid !== '') $keys[] = array('kind'=>'catalog_uid','value'=>$uid);
            if ($provider !== '' && $external !== '') $keys[] = array('kind'=>'provider_external','value'=>$provider . "\x1f" . $external);
            if ($sku !== '') $keys[] = array('kind'=>'sku','value'=>$sku);
            if ($slug !== '') $keys[] = array('kind'=>'slug','value'=>$slug);
        } else {
            $slug = sanitize_title((string) ($row['post_name'] ?? ''));
            if ($slug !== '') $keys[] = array('kind'=>'slug','value'=>$slug);
        }
        return $keys;
    }

    private static function build_post_identity_plan($pro, $stg, $pro_tables, $stg_tables) {
        $source = self::post_identity_rows($pro, $pro_tables, true);
        $target = self::post_identity_rows($stg, $stg_tables, true);
        if (is_wp_error($source)) return $source;
        if (is_wp_error($target)) return $target;

        $source_indexes = array();
        $target_indexes = array();
        foreach ($source as $row) {
            $type = (string) $row['post_type'];
            foreach (self::post_portable_keys($row) as $key) {
                $idx = $type . '|' . $key['kind'] . '|' . $key['value'];
                $source_indexes[$idx][] = absint($row['ID']);
            }
        }
        foreach ($target as $row) {
            $type = (string) $row['post_type'];
            foreach (self::post_portable_keys($row) as $key) {
                $idx = $type . '|' . $key['kind'] . '|' . $key['value'];
                $target_indexes[$idx][] = absint($row['ID']);
            }
        }

        $conflicts = array();
        $warnings = array();
        $blocked_source = array();
        foreach ($source_indexes as $idx => $ids) {
            $ids = array_values(array_unique(array_map('absint', (array) $ids)));
            if (count($ids) > 1) {
                $conflicts[] = 'PRO tiene una identidad portable duplicada: ' . str_replace("\x1f", ' + ', $idx) . ' (IDs ' . implode(',', $ids) . ').';
                foreach ($ids as $id) $blocked_source[$id] = true;
            }
        }
        foreach ($target_indexes as $idx => $ids) {
            $ids = array_values(array_unique(array_map('absint', (array) $ids)));
            if (count($ids) > 1) {
                $conflicts[] = 'STAGING tiene una identidad portable duplicada: ' . str_replace("\x1f", ' + ', $idx) . ' (IDs ' . implode(',', $ids) . ').';
            }
        }

        $map = array();
        $resolved_target = array();
        $create = 0;
        $matched = 0;
        $fallbacks = array();
        $create_items = array();
        $resolution_counts = array();
        $actions_by_type = array();
        foreach (self::$post_types as $type) {
            $resolution_counts[$type] = array(
                'catalog_uid' => 0,
                'provider_external' => 0,
                'sku' => 0,
                'slug' => 0,
                'create' => 0,
            );
            $actions_by_type[$type] = array(
                'source' => 0,
                'target' => 0,
                'create' => 0,
                'matched_existing' => 0,
                'remove' => 0,
            );
        }
        foreach ($source as $row) {
            $type = sanitize_key((string) ($row['post_type'] ?? ''));
            if (isset($actions_by_type[$type])) $actions_by_type[$type]['source']++;
        }
        foreach ($target as $row) {
            $type = sanitize_key((string) ($row['post_type'] ?? ''));
            if (isset($actions_by_type[$type])) $actions_by_type[$type]['target']++;
        }

        foreach ($source as $row) {
            $sid = absint($row['ID']);
            $type = (string) $row['post_type'];
            $keys = self::post_portable_keys($row);
            if (!$keys) {
                $conflicts[] = strtoupper($type) . ' PRO #' . $sid . ' no tiene una identidad portable utilizable.';
                continue;
            }
            if (isset($blocked_source[$sid])) continue;

            $candidate = 0;
            $matched_key = null;
            $ambiguous = false;
            foreach ($keys as $key) {
                $idx = $type . '|' . $key['kind'] . '|' . $key['value'];
                $ids = array_values(array_unique(array_map('absint', (array) ($target_indexes[$idx] ?? array()))));
                if (count($ids) > 1) {
                    $conflicts[] = 'STAGING no puede resolver de forma unica ' . str_replace("\x1f", ' + ', $idx) . ' (' . implode(',', $ids) . ').';
                    $ambiguous = true;
                    break;
                }
                if (1 === count($ids)) {
                    if ($candidate && $candidate !== $ids[0]) {
                        $conflicts[] = 'Las claves portables de PRO #' . $sid . ' apuntan a objetos STAGING distintos (' . $candidate . ' y ' . $ids[0] . ').';
                        $ambiguous = true;
                        break;
                    }
                    if (!$candidate) {
                        $candidate = $ids[0];
                        $matched_key = $key;
                    }
                }
            }
            if ($ambiguous) continue;

            if ($candidate) {
                if (isset($resolved_target[$candidate]) && $resolved_target[$candidate] !== $sid) {
                    $conflicts[] = 'Dos objetos PRO intentan resolver al mismo objeto STAGING #' . $candidate . '.';
                    continue;
                }
                $map[$sid] = $candidate;
                $resolved_target[$candidate] = $sid;
                $matched++;
                if (isset($actions_by_type[$type])) $actions_by_type[$type]['matched_existing']++;
                $matched_kind = (string) ($matched_key['kind'] ?? '');
                if (isset($resolution_counts[$type][$matched_kind])) $resolution_counts[$type][$matched_kind]++;

                $primary = $keys[0];
                if ($matched_kind && $matched_kind !== (string) $primary['kind']) {
                    $fallbacks[] = array(
                        'post_type' => $type,
                        'source_id_audit' => $sid,
                        'target_id_audit' => $candidate,
                        'title' => (string) ($row['post_title'] ?? ''),
                        'slug' => (string) ($row['post_name'] ?? ''),
                        'preferred_identity' => (string) $primary['kind'],
                        'preferred_value' => str_replace("\x1f", ' + ', (string) $primary['value']),
                        'matched_identity' => $matched_kind,
                        'matched_value' => str_replace("\x1f", ' + ', (string) ($matched_key['value'] ?? '')),
                    );
                }
            } else {
                $map[$sid] = 0;
                $create++;
                if (isset($actions_by_type[$type])) $actions_by_type[$type]['create']++;
                if (isset($resolution_counts[$type]['create'])) $resolution_counts[$type]['create']++;
                $primary = $keys[0];
                $create_items[] = array(
                    'post_type' => $type,
                    'source_id_audit' => $sid,
                    'title' => (string) ($row['post_title'] ?? ''),
                    'slug' => (string) ($row['post_name'] ?? ''),
                    'preferred_identity' => (string) ($primary['kind'] ?? ''),
                    'preferred_value' => str_replace("\x1f", ' + ', (string) ($primary['value'] ?? '')),
                );
            }
        }

        $target_by_id = array();
        foreach ($target as $row) $target_by_id[absint($row['ID'])] = $row;
        $target_ids = array_keys($target_by_id);
        $matched_ids = array_keys($resolved_target);
        $remove = array_values(array_diff($target_ids, $matched_ids));
        $remove_items = array();
        foreach ($remove as $tid) {
            $tr = (array) ($target_by_id[$tid] ?? array());
            $remove_items[] = array(
                'post_type' => sanitize_key((string) ($tr['post_type'] ?? '')),
                'target_id_audit' => absint($tid),
                'title' => (string) ($tr['post_title'] ?? ''),
                'slug' => (string) ($tr['post_name'] ?? ''),
            );
            $type = sanitize_key((string) ($target_by_id[$tid]['post_type'] ?? ''));
            if (isset($actions_by_type[$type])) $actions_by_type[$type]['remove']++;
        }

        return array(
            'source_rows' => $source,
            'target_rows' => $target,
            'map' => $map,
            'remove_ids' => $remove,
            'create' => $create,
            'update' => $matched,
            'matched' => $matched,
            'remove' => count($remove),
            'actions_by_type' => $actions_by_type,
            'resolution_counts' => $resolution_counts,
            'fallbacks' => $fallbacks,
            'fallback_count' => count($fallbacks),
            'create_items' => $create_items,
            'remove_items' => $remove_items,
            'conflicts' => $conflicts,
            'warnings' => $warnings,
        );
    }

    private static function category_rows_with_paths($mysqli, $tables) {
        $rows = self::category_rows($mysqli, $tables);
        if (is_wp_error($rows)) return $rows;
        $by_id = array();
        foreach ($rows as $row) $by_id[absint($row['term_id'])] = $row;
        $cache = array();
        $path_for = function($id) use (&$path_for, &$cache, $by_id) {
            $id = absint($id);
            if (!$id || !isset($by_id[$id])) return '';
            if (isset($cache[$id])) return $cache[$id];
            $parts = array();
            $seen = array();
            $cur = $id;
            while ($cur && isset($by_id[$cur]) && !isset($seen[$cur])) {
                $seen[$cur] = 1;
                array_unshift($parts, sanitize_title((string) $by_id[$cur]['slug']));
                $cur = absint($by_id[$cur]['parent'] ?? 0);
            }
            return $cache[$id] = implode('/', array_filter($parts));
        };
        foreach ($rows as &$row) $row['portable_path'] = $path_for($row['term_id']);
        unset($row);
        usort($rows, static function($a,$b){
            $da = substr_count((string)($a['portable_path']??''), '/');
            $db = substr_count((string)($b['portable_path']??''), '/');
            return $da === $db ? strcmp((string)$a['portable_path'], (string)$b['portable_path']) : ($da <=> $db);
        });
        return $rows;
    }

    private static function build_category_identity_plan($pro, $stg, $pro_tables, $stg_tables) {
        $source = self::category_rows_with_paths($pro, $pro_tables);
        $target = self::category_rows_with_paths($stg, $stg_tables);
        if (is_wp_error($source)) return $source;
        if (is_wp_error($target)) return $target;
        $src_idx = array(); $tgt_idx = array(); $conflicts = array();
        foreach ($source as $row) {
            $path = (string) ($row['portable_path'] ?? '');
            if (!$path) { $conflicts[]='Categoria PRO #' . absint($row['term_id']) . ' sin ruta portable.'; continue; }
            if (isset($src_idx[$path])) $conflicts[]='Ruta product_cat duplicada en PRO: ' . $path . '.';
            $src_idx[$path] = $row;
        }
        foreach ($target as $row) {
            $path = (string) ($row['portable_path'] ?? '');
            if (!$path) continue;
            if (isset($tgt_idx[$path])) $conflicts[]='Ruta product_cat duplicada en STAGING: ' . $path . '.';
            $tgt_idx[$path] = $row;
        }
        $map = array(); $tt_map = array(); $create=0; $update=0; $create_rows=array();
        foreach ($src_idx as $path=>$row) {
            if (isset($tgt_idx[$path])) {
                $map[absint($row['term_id'])] = absint($tgt_idx[$path]['term_id']);
                $tt_map[absint($row['term_taxonomy_id'])] = absint($tgt_idx[$path]['term_taxonomy_id']);
                $update++;
            } else {
                $map[absint($row['term_id'])] = 0;
                $tt_map[absint($row['term_taxonomy_id'])] = 0;
                $create++;
                $create_rows[]=$row;
            }
        }
        $remove_rows = array();
        foreach ($tgt_idx as $path=>$row) if (!isset($src_idx[$path])) $remove_rows[]=$row;
        return array('source_rows'=>$source,'target_rows'=>$target,'map'=>$map,'tt_map'=>$tt_map,'remove_rows'=>$remove_rows,'create_rows'=>$create_rows,'create'=>$create,'update'=>$update,'remove'=>count($remove_rows),'conflicts'=>$conflicts);
    }

    private static function build_tag_identity_plan($pro, $stg, $pro_tables, $stg_tables, $taxonomy) {
        $source = self::tag_rows($pro, $pro_tables, $taxonomy);
        $target = self::tag_rows($stg, $stg_tables, $taxonomy);
        if (is_wp_error($source)) return $source;
        if (is_wp_error($target)) return $target;
        $src = array(); $tgt = array(); $conflicts = array();
        foreach ($source as $row) {
            $slug=sanitize_title((string)$row['slug']);
            if (!$slug) { $conflicts[]=$taxonomy.' PRO term sin slug.'; continue; }
            if (isset($src[$slug])) $conflicts[]=$taxonomy.' slug duplicado en PRO: '.$slug.'.';
            $src[$slug]=$row;
        }
        foreach ($target as $row) {
            $slug=sanitize_title((string)$row['slug']);
            if (!$slug) continue;
            if (isset($tgt[$slug])) $conflicts[]=$taxonomy.' slug duplicado en STAGING: '.$slug.'.';
            $tgt[$slug]=$row;
        }
        $map=array();$tt_map=array();$create=0;$update=0;$create_rows=array();
        foreach($src as $slug=>$row){
            if(isset($tgt[$slug])){
                $map[absint($row['term_id'])]=absint($tgt[$slug]['term_id']);
                $tt_map[absint($row['term_taxonomy_id'])]=absint($tgt[$slug]['term_taxonomy_id']);$update++;
            } else {$map[absint($row['term_id'])]=0;$tt_map[absint($row['term_taxonomy_id'])]=0;$create++;$create_rows[]=$row;}
        }
        $remove_rows=array();foreach($tgt as $slug=>$row)if(!isset($src[$slug]))$remove_rows[]=$row;
        return array('source_rows'=>$source,'target_rows'=>$target,'map'=>$map,'tt_map'=>$tt_map,'remove_rows'=>$remove_rows,'create_rows'=>$create_rows,'create'=>$create,'update'=>$update,'remove'=>count($remove_rows),'conflicts'=>$conflicts);
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

    /**
     * Preflight de identidades portables de maestros propios.
     *
     * Estas comprobaciones viven en DRY RUN para que APPLY nunca sea el primer
     * lugar donde descubrimos una colision de semantic_group+slug, atributo
     * slug o atributo+term slug. Los IDs numericos solo aparecen en mensajes
     * de auditoria; no participan en la resolucion del destino.
     */
    private static function semantic_identity_conflicts($pro, $stg, $pro_tables, $stg_tables) {
        $conflicts = array();

        foreach (array('PRO'=>$pro, 'STAGING'=>$stg) as $label=>$db) {
            $tables = ('PRO' === $label) ? $pro_tables : $stg_tables;

            $vocab = self::rows($db, "SELECT id,semantic_group,slug,parent_id FROM `{$tables['seo_vocabulary']}` ORDER BY id");
            if (is_wp_error($vocab)) return $vocab;
            $seen = array();
            $ids = array();
            foreach ($vocab as $row) {
                $id = absint($row['id'] ?? 0);
                if ($id) $ids[$id] = true;
                $key = self::semantic_key($row['semantic_group'] ?? '', $row['slug'] ?? '');
                if (!$key || '|' === $key) {
                    $conflicts[] = "Vocabulario {$label} #{$id} sin semantic_group+slug portable.";
                    continue;
                }
                if (isset($seen[$key])) {
                    $conflicts[] = "Vocabulario duplicado en {$label}: {$key} (IDs {$seen[$key]} y {$id}).";
                } else {
                    $seen[$key] = $id;
                }
            }
            foreach ($vocab as $row) {
                $id = absint($row['id'] ?? 0);
                $parent = absint($row['parent_id'] ?? 0);
                if ($parent && !isset($ids[$parent])) {
                    $conflicts[] = "Vocabulario {$label} #{$id} apunta a parent_id inexistente #{$parent}.";
                }
            }

            $attrs = self::rows($db, "SELECT id,slug FROM `{$tables['sql_atributos']}` ORDER BY id");
            if (is_wp_error($attrs)) return $attrs;
            $seen = array();
            foreach ($attrs as $row) {
                $id = absint($row['id'] ?? 0);
                $key = sanitize_title((string) ($row['slug'] ?? ''));
                if (!$key) {
                    $conflicts[] = "Atributo {$label} #{$id} sin slug portable.";
                    continue;
                }
                if (isset($seen[$key])) {
                    $conflicts[] = "Atributo duplicado en {$label}: {$key} (IDs {$seen[$key]} y {$id}).";
                } else {
                    $seen[$key] = $id;
                }
            }

            $terms = self::rows($db,
                "SELECT t.id,t.atributo_id,t.slug,a.slug attribute_slug
                 FROM `{$tables['sql_atributos_terminos']}` t
                 LEFT JOIN `{$tables['sql_atributos']}` a ON a.id=t.atributo_id
                 ORDER BY t.id"
            );
            if (is_wp_error($terms)) return $terms;
            $seen = array();
            foreach ($terms as $row) {
                $id = absint($row['id'] ?? 0);
                $attribute_slug = sanitize_title((string) ($row['attribute_slug'] ?? ''));
                $term_slug = sanitize_title((string) ($row['slug'] ?? ''));
                if (!$attribute_slug) {
                    $conflicts[] = "Termino de atributo {$label} #{$id} apunta a atributo inexistente #" . absint($row['atributo_id'] ?? 0) . '.';
                    continue;
                }
                $key = self::attribute_term_key($attribute_slug, $term_slug);
                if (!$term_slug || !$key || '|' === $key) {
                    $conflicts[] = "Termino de atributo {$label} #{$id} sin clave portable atributo+slug.";
                    continue;
                }
                if (isset($seen[$key])) {
                    $conflicts[] = "Termino de atributo duplicado en {$label}: {$key} (IDs {$seen[$key]} y {$id}).";
                } else {
                    $seen[$key] = $id;
                }
            }
        }

        return $conflicts;
    }

    private static function set_action_summary($source_keys, $target_keys) {
        $source_keys = array_values(array_unique(array_filter(array_map('strval', (array) $source_keys), 'strlen')));
        $target_keys = array_values(array_unique(array_filter(array_map('strval', (array) $target_keys), 'strlen')));
        return array(
            'pro' => count($source_keys),
            'staging' => count($target_keys),
            'create' => count(array_diff($source_keys, $target_keys)),
            'keep' => count(array_intersect($source_keys, $target_keys)),
            'remove' => count(array_diff($target_keys, $source_keys)),
        );
    }

    private static function master_action_summary($pro, $stg, $pro_tables, $stg_tables) {
        $out = array();
        $conflicts = array();
        $queries = array(
            'vocabulary' => "SELECT id,LOWER(TRIM(semantic_group)) g,LOWER(TRIM(slug)) s FROM `%s` ORDER BY id",
            'attributes' => "SELECT id,LOWER(TRIM(slug)) s FROM `%s` ORDER BY id",
        );
        foreach (array('PRO'=>array($pro,$pro_tables),'STAGING'=>array($stg,$stg_tables)) as $label=>$pair) {
            list($db,$tables)=$pair;
            $sets = array();
            $rows = self::rows($db, sprintf($queries['vocabulary'], $tables['seo_vocabulary']));
            if (is_wp_error($rows)) return $rows;
            foreach ($rows as $r) $sets['vocabulary'][]=(string)$r['g'].'|'.(string)$r['s'];
            $rows = self::rows($db, sprintf($queries['attributes'], $tables['sql_atributos']));
            if (is_wp_error($rows)) return $rows;
            foreach ($rows as $r) $sets['attributes'][]=(string)$r['s'];
            $rows = self::rows($db, "SELECT t.id,LOWER(TRIM(a.slug)) a,LOWER(TRIM(t.slug)) s FROM `{$tables['sql_atributos_terminos']}` t JOIN `{$tables['sql_atributos']}` a ON a.id=t.atributo_id ORDER BY t.id");
            if (is_wp_error($rows)) return $rows;
            foreach ($rows as $r) $sets['attribute_terms'][]=(string)$r['a'].'|'.(string)$r['s'];
            $rows = self::rows($db, "SELECT al.id,LOWER(TRIM(a.slug)) a,TRIM(al.alias) x FROM `{$tables['sql_atributos_aliases']}` al JOIN `{$tables['sql_atributos']}` a ON a.id=al.atributo_id ORDER BY al.id");
            if (is_wp_error($rows)) return $rows;
            foreach ($rows as $r) $sets['attribute_aliases'][]=(string)$r['a'].'|'.(string)$r['x'];
            foreach ($sets as $kind=>$keys) {
                $counts=array_count_values($keys);
                foreach ($counts as $key=>$count) {
                    if ($key !== '' && $count > 1) $conflicts[] = $kind . ' tiene clave portable duplicada en ' . $label . ': ' . $key . ' (' . $count . ' filas).';
                }
                $out[$label][$kind]=$keys;
            }
        }
        $actions=array();
        foreach (array('vocabulary','attributes','attribute_terms','attribute_aliases') as $kind) {
            $actions[$kind]=self::set_action_summary($out['PRO'][$kind]??array(),$out['STAGING'][$kind]??array());
        }
        return array('actions'=>$actions,'conflicts'=>$conflicts);
    }

    private static function reference_audit($pro, $pro_tables) {
        $remapped = array(
            'wp_posts.post_parent -> ID local STAGING',
            'product_cat.parent -> term_id local STAGING',
            'wp_term_relationships.object_id + term_taxonomy_id -> IDs locales STAGING',
            'wp_postmeta._upsell_ids/_crosssell_ids -> product IDs locales STAGING',
            'wp_termmeta.term_id -> term_id local STAGING',
            'seo_object_vocabulary.object_id + vocabulary_id -> IDs locales STAGING',
            'sql_product_atributos.product_id + atributo_id + termino_id -> IDs locales STAGING',
            'seo_nodes.object_id -> ID local STAGING',
            'seo_relations.source_id/target_id -> IDs locales STAGING',
            'seo_faq.object_id -> ID local STAGING',
            'wc_product_meta_lookup.product_id -> ID local STAGING',
        );
        $excluded = array(
            '_product_attributes (fuera del postmeta portable; atributos se reconstruyen por las tablas gestionadas)',
            '_children (variaciones WooCommerce no forman parte del perimetro actual del Comparador)',
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

        $structured_where = "(LEFT(TRIM(pm.meta_value),1) IN ('{','[') OR pm.meta_value REGEXP '^(a|O|s|i|b|d):')";
        $suspicious_key = "LOWER(pm.meta_key) REGEXP '(id|ids|parent|related|relation|category|term|product|post)'";
        $known_sql = "'" . implode("','", array_map(static function($v) use ($pro){ return mysqli_real_escape_string($pro,$v); }, self::remapped_postmeta_keys())) . "'";
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
        return array(
            'remapped' => $remapped,
            'excluded' => $excluded,
            'known_postmeta_reference_rows' => $known,
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
                $conflicts[] = 'La tabla STAGING ' . $table . ' usa ' . $engine . '; el MirrorEngine requiere InnoDB para poder revertir la operacion.';
            }
        }

        $post_plan = self::build_post_identity_plan($pro, $stg, $pro_tables, $stg_tables);
        if (is_wp_error($post_plan)) return $post_plan;
        $cat_plan = self::build_category_identity_plan($pro, $stg, $pro_tables, $stg_tables);
        if (is_wp_error($cat_plan)) return $cat_plan;
        $product_tag_plan = self::build_tag_identity_plan($pro, $stg, $pro_tables, $stg_tables, 'product_tag');
        if (is_wp_error($product_tag_plan)) return $product_tag_plan;
        $post_tag_plan = self::build_tag_identity_plan($pro, $stg, $pro_tables, $stg_tables, 'post_tag');
        if (is_wp_error($post_tag_plan)) return $post_tag_plan;
        $semantic_conflicts = self::semantic_identity_conflicts($pro, $stg, $pro_tables, $stg_tables);
        if (is_wp_error($semantic_conflicts)) return $semantic_conflicts;
        $master_plan = self::master_action_summary($pro, $stg, $pro_tables, $stg_tables);
        if (is_wp_error($master_plan)) return $master_plan;
        $reference_audit = self::reference_audit($pro, $pro_tables);
        if (is_wp_error($reference_audit)) return $reference_audit;

        $conflicts = array_merge(
            $conflicts,
            (array) $semantic_conflicts,
            (array) ($master_plan['conflicts'] ?? array()),
            (array) ($post_plan['conflicts'] ?? array()),
            (array) ($cat_plan['conflicts'] ?? array()),
            (array) ($product_tag_plan['conflicts'] ?? array()),
            (array) ($post_tag_plan['conflicts'] ?? array())
        );
        $warnings = array_merge($warnings, (array) ($post_plan['warnings'] ?? array()), (array) ($reference_audit['warnings'] ?? array()));

        $summary = array();
        foreach (array('product','page','post') as $type) {
            $summary[$type . '_pro'] = 0;
            $summary[$type . '_staging'] = 0;
        }
        foreach ((array) ($post_plan['source_rows'] ?? array()) as $row) {
            $type = sanitize_key((string) ($row['post_type'] ?? ''));
            if (isset($summary[$type . '_pro'])) $summary[$type . '_pro']++;
        }
        foreach ((array) ($post_plan['target_rows'] ?? array()) as $row) {
            $type = sanitize_key((string) ($row['post_type'] ?? ''));
            if (isset($summary[$type . '_staging'])) $summary[$type . '_staging']++;
        }
        foreach ((array) ($post_plan['actions_by_type'] ?? array()) as $type=>$actions) {
            foreach ((array) $actions as $action=>$value) $summary[$type . '_' . $action] = absint($value);
        }
        $summary['identity_fallback_count'] = absint($post_plan['fallback_count'] ?? 0);
        $summary['posts_create'] = absint($post_plan['create'] ?? 0);
        $summary['posts_remove'] = absint($post_plan['remove'] ?? 0);
        $summary['posts_replace'] = absint($post_plan['update'] ?? 0);
        $summary['product_cat_pro'] = count((array) ($cat_plan['source_rows'] ?? array()));
        $summary['product_cat_staging'] = count((array) ($cat_plan['target_rows'] ?? array()));
        $summary['categories_create'] = absint($cat_plan['create'] ?? 0);
        $summary['categories_remove'] = absint($cat_plan['remove'] ?? 0);
        foreach (array('product_tag'=>$product_tag_plan,'post_tag'=>$post_tag_plan) as $taxonomy=>$plan) {
            $summary[$taxonomy . '_pro'] = count((array) ($plan['source_rows'] ?? array()));
            $summary[$taxonomy . '_staging'] = count((array) ($plan['target_rows'] ?? array()));
            $summary[$taxonomy . '_create'] = absint($plan['create'] ?? 0);
            $summary[$taxonomy . '_remove'] = absint($plan['remove'] ?? 0);
        }
        foreach (self::custom_table_keys() as $key) {
            $summary[$key . '_pro'] = absint(self::scalar($pro, "SELECT COUNT(*) c FROM `{$pro_tables[$key]}`", 'c'));
            $summary[$key . '_staging'] = absint(self::scalar($stg, "SELECT COUNT(*) c FROM `{$stg_tables[$key]}`", 'c'));
        }

        $source_marker = self::environment_marker($pro, $pro_tables);
        if (is_wp_error($source_marker)) return $source_marker;
        $target_marker = self::environment_marker($stg, $stg_tables);
        if (is_wp_error($target_marker)) return $target_marker;

        return array(
            'identity' => $identity,
            'summary' => $summary,
            'actions' => array(
                'objects' => (array) ($post_plan['actions_by_type'] ?? array()),
                'categories' => array('pro'=>count((array)($cat_plan['source_rows']??array())),'staging'=>count((array)($cat_plan['target_rows']??array())),'create'=>absint($cat_plan['create']??0),'matched_existing'=>absint($cat_plan['update']??0),'remove'=>absint($cat_plan['remove']??0),'create_items'=>array_values(array_map(static function($r){return array('source_id_audit'=>absint($r['term_id']??0),'path'=>(string)($r['portable_path']??''),'slug'=>(string)($r['slug']??''),'name'=>(string)($r['name']??''));},(array)($cat_plan['create_rows']??array()))),'remove_items'=>array_values(array_map(static function($r){return array('target_id_audit'=>absint($r['term_id']??0),'path'=>(string)($r['portable_path']??''),'slug'=>(string)($r['slug']??''),'name'=>(string)($r['name']??''));},(array)($cat_plan['remove_rows']??array())))),
                'product_tag' => array('pro'=>count((array)($product_tag_plan['source_rows']??array())),'staging'=>count((array)($product_tag_plan['target_rows']??array())),'create'=>absint($product_tag_plan['create']??0),'matched_existing'=>absint($product_tag_plan['update']??0),'remove'=>absint($product_tag_plan['remove']??0),'create_items'=>array_values(array_map(static function($r){return array('source_id_audit'=>absint($r['term_id']??0),'slug'=>(string)($r['slug']??''),'name'=>(string)($r['name']??''));},(array)($product_tag_plan['create_rows']??array()))),'remove_items'=>array_values(array_map(static function($r){return array('target_id_audit'=>absint($r['term_id']??0),'slug'=>(string)($r['slug']??''),'name'=>(string)($r['name']??''));},(array)($product_tag_plan['remove_rows']??array())))),
                'post_tag' => array('pro'=>count((array)($post_tag_plan['source_rows']??array())),'staging'=>count((array)($post_tag_plan['target_rows']??array())),'create'=>absint($post_tag_plan['create']??0),'matched_existing'=>absint($post_tag_plan['update']??0),'remove'=>absint($post_tag_plan['remove']??0),'create_items'=>array_values(array_map(static function($r){return array('source_id_audit'=>absint($r['term_id']??0),'slug'=>(string)($r['slug']??''),'name'=>(string)($r['name']??''));},(array)($post_tag_plan['create_rows']??array()))),'remove_items'=>array_values(array_map(static function($r){return array('target_id_audit'=>absint($r['term_id']??0),'slug'=>(string)($r['slug']??''),'name'=>(string)($r['name']??''));},(array)($post_tag_plan['remove_rows']??array())))),
                'masters' => (array) ($master_plan['actions'] ?? array()),
            ),
            'identity_resolution' => array(
                'counts' => (array) ($post_plan['resolution_counts'] ?? array()),
                'fallback_count' => absint($post_plan['fallback_count'] ?? 0),
                'fallbacks' => (array) ($post_plan['fallbacks'] ?? array()),
                'unmatched_creates' => (array) ($post_plan['create_items'] ?? array()),
                'staging_removals' => (array) ($post_plan['remove_items'] ?? array()),
                'policy' => array('product'=>array('catalog_uid','provider_external','sku','slug'),'page'=>array('slug'),'post'=>array('slug')),
            ),
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
        $plan = self::build_post_identity_plan($pro, $stg, $pro_tables, $stg_tables);
        if (is_wp_error($plan)) return $plan;
        if (!empty($plan['conflicts'])) {
            return new WP_Error('mirror_post_identity', implode(' ', array_slice((array)$plan['conflicts'], 0, 10)));
        }
        $map = (array) ($plan['map'] ?? array());
        $remove_ids = array_values(array_filter(array_map('absint', (array) ($plan['remove_ids'] ?? array()))));

        // STAGING es desechable solo dentro del perimetro controlado.
        if ($remove_ids) {
            $r = self::delete_ids($stg, $stg_tables['postmeta'], 'post_id', $remove_ids);
            if (is_wp_error($r)) return $r;
            $r = self::delete_ids($stg, $stg_tables['term_relationships'], 'object_id', $remove_ids);
            if (is_wp_error($r)) return $r;
            if (self::table_exists($stg, $stg_tables['comments']) && self::table_exists($stg, $stg_tables['commentmeta'])) {
                foreach (self::id_chunks($remove_ids) as $chunk) {
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
                if (!array_key_exists($sid, $map)) {
                    return new WP_Error('mirror_post_map', 'No existe mapa portable para el objeto PRO #' . $sid . '.');
                }
                $target_id = absint($map[$sid]);
                $data = $row;
                unset($data['ID'], $data['post_parent']);
                if ($target_id) {
                    $sets = array();
                    foreach ($data as $column=>$value) {
                        $id = self::ident($column);
                        if ($id) $sets[] = $id . '=' . self::sql_value($stg, $value);
                    }
                    $r = self::exec($stg, "UPDATE `{$stg_tables['posts']}` SET " . implode(',', $sets) . " WHERE ID={$target_id}");
                    if (is_wp_error($r)) return $r;
                } else {
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
                    if (!$target_id) return new WP_Error('mirror_post_insert_id', 'STAGING no devolvio un ID local al crear un objeto.');
                    $map[$sid] = $target_id;
                }
                $parents[$sid] = absint($row['post_parent'] ?? 0);
                $copied++;
            }
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
                $meta_value = self::remap_postmeta_value((string)$row['meta_key'], (string)$row['meta_value'], $map);
                if (is_wp_error($meta_value)) return $meta_value;
                $insert[] = array('post_id'=>$target_id,'meta_key'=>(string)$row['meta_key'],'meta_value'=>(string)$meta_value);
            }
            foreach (array_chunk($insert, 120) as $chunk) {
                $r = self::insert_rows($stg, $stg_tables['postmeta'], array('post_id','meta_key','meta_value'), $chunk, false);
                if (is_wp_error($r)) return $r;
            }
            $copied_meta += count($insert);
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

    private static function mirror_taxonomies($pro, $stg, $pro_tables, $stg_tables, $post_map, &$stats) {
        $cat_plan = self::build_category_identity_plan($pro, $stg, $pro_tables, $stg_tables);
        $product_tag_plan = self::build_tag_identity_plan($pro, $stg, $pro_tables, $stg_tables, 'product_tag');
        $post_tag_plan = self::build_tag_identity_plan($pro, $stg, $pro_tables, $stg_tables, 'post_tag');
        foreach (array($cat_plan,$product_tag_plan,$post_tag_plan) as $plan) if (is_wp_error($plan)) return $plan;
        $all_conflicts = array_merge((array)$cat_plan['conflicts'],(array)$product_tag_plan['conflicts'],(array)$post_tag_plan['conflicts']);
        if ($all_conflicts) return new WP_Error('mirror_taxonomy_identity', implode(' ', array_slice($all_conflicts,0,10)));

        // Las relaciones de taxonomias gestionadas se reconstruyen por completo.
        $target_controlled_tt = self::ids($stg, "SELECT term_taxonomy_id FROM `{$stg_tables['term_taxonomy']}` WHERE " . self::taxonomy_sql($stg), 'term_taxonomy_id');
        if (is_wp_error($target_controlled_tt)) return $target_controlled_tt;
        $r = self::delete_ids($stg, $stg_tables['term_relationships'], 'term_taxonomy_id', $target_controlled_tt);
        if (is_wp_error($r)) return $r;

        // Eliminar extras STAGING por identidad portable, nunca por ID de PRO.
        $remove_rows = array_merge(
            (array)($cat_plan['remove_rows'] ?? array()),
            (array)($product_tag_plan['remove_rows'] ?? array()),
            (array)($post_tag_plan['remove_rows'] ?? array())
        );
        $remove_tt=array();$remove_terms=array();
        foreach($remove_rows as $row){$remove_tt[]=absint($row['term_taxonomy_id']??0);$remove_terms[]=absint($row['term_id']??0);}
        $r=self::delete_ids($stg,$stg_tables['term_taxonomy'],'term_taxonomy_id',$remove_tt);if(is_wp_error($r))return$r;
        $r=self::delete_orphan_terms($stg,$stg_tables,$remove_terms);if(is_wp_error($r))return$r;

        $source_tt_to_target=array();
        $category_term_map=(array)($cat_plan['map']??array());
        $category_tt_map=(array)($cat_plan['tt_map']??array());
        $category_parents=array();
        foreach((array)$cat_plan['source_rows'] as $row){
            $sid=absint($row['term_id']);$stt=absint($row['term_taxonomy_id']);
            $target_term=absint($category_term_map[$sid]??0);$target_tt=absint($category_tt_map[$stt]??0);
            if($target_term && $target_tt){
                $r=self::exec($stg,"UPDATE `{$stg_tables['terms']}` SET name=".self::sql_value($stg,$row['name']).",slug=".self::sql_value($stg,$row['slug']).",term_group=".absint($row['term_group'])." WHERE term_id={$target_term}");if(is_wp_error($r))return$r;
                $r=self::exec($stg,"UPDATE `{$stg_tables['term_taxonomy']}` SET description=".self::sql_value($stg,$row['description'])." WHERE term_taxonomy_id={$target_tt}");if(is_wp_error($r))return$r;
            } else {
                $r=self::exec($stg,"INSERT INTO `{$stg_tables['terms']}` (name,slug,term_group) VALUES (".self::sql_value($stg,$row['name']).','.self::sql_value($stg,$row['slug']).','.absint($row['term_group']).')');if(is_wp_error($r))return$r;
                $target_term=absint(mysqli_insert_id($stg));
                $r=self::exec($stg,"INSERT INTO `{$stg_tables['term_taxonomy']}` (term_id,taxonomy,description,parent,count) VALUES ({$target_term},'product_cat',".self::sql_value($stg,$row['description']).",0,0)");if(is_wp_error($r))return$r;
                $target_tt=absint(mysqli_insert_id($stg));
                $category_term_map[$sid]=$target_term;$category_tt_map[$stt]=$target_tt;
            }
            $category_parents[$sid]=absint($row['parent']??0);
            $source_tt_to_target[$stt]=$target_tt;
        }
        foreach($category_parents as $sid=>$source_parent){
            $target_tt=0;
            foreach((array)$cat_plan['source_rows'] as $row){if(absint($row['term_id'])===$sid){$target_tt=absint($category_tt_map[absint($row['term_taxonomy_id'])]??0);break;}}
            if(!$target_tt)continue;
            $target_parent=$source_parent?absint($category_term_map[$source_parent]??0):0;
            $r=self::exec($stg,"UPDATE `{$stg_tables['term_taxonomy']}` SET parent={$target_parent} WHERE term_taxonomy_id={$target_tt}");if(is_wp_error($r))return$r;
        }
        $r=self::copy_termmeta_mapped($pro,$stg,$pro_tables,$stg_tables,$category_term_map);if(is_wp_error($r))return$r;
        $stats['product_cat']=count((array)$cat_plan['source_rows']);

        $tag_term_maps=array();
        foreach(array('product_tag'=>$product_tag_plan,'post_tag'=>$post_tag_plan) as $taxonomy=>$plan){
            $term_map=(array)($plan['map']??array());$tt_map=(array)($plan['tt_map']??array());
            foreach((array)$plan['source_rows'] as $row){
                $sid=absint($row['term_id']);$stt=absint($row['term_taxonomy_id']);$target_term=absint($term_map[$sid]??0);$target_tt=absint($tt_map[$stt]??0);
                if($target_term && $target_tt){
                    $r=self::exec($stg,"UPDATE `{$stg_tables['terms']}` SET name=".self::sql_value($stg,$row['name']).",slug=".self::sql_value($stg,$row['slug']).",term_group=".absint($row['term_group'])." WHERE term_id={$target_term}");if(is_wp_error($r))return$r;
                    $r=self::exec($stg,"UPDATE `{$stg_tables['term_taxonomy']}` SET description=".self::sql_value($stg,$row['description']).",parent=0 WHERE term_taxonomy_id={$target_tt}");if(is_wp_error($r))return$r;
                }else{
                    $r=self::exec($stg,"INSERT INTO `{$stg_tables['terms']}` (name,slug,term_group) VALUES (".self::sql_value($stg,$row['name']).','.self::sql_value($stg,$row['slug']).','.absint($row['term_group']).')');if(is_wp_error($r))return$r;
                    $target_term=absint(mysqli_insert_id($stg));$tax=mysqli_real_escape_string($stg,$taxonomy);
                    $r=self::exec($stg,"INSERT INTO `{$stg_tables['term_taxonomy']}` (term_id,taxonomy,description,parent,count) VALUES ({$target_term},'{$tax}',".self::sql_value($stg,$row['description']).",0,0)");if(is_wp_error($r))return$r;
                    $target_tt=absint(mysqli_insert_id($stg));$term_map[$sid]=$target_term;$tt_map[$stt]=$target_tt;
                }
                $source_tt_to_target[$stt]=$target_tt;
            }
            $r=self::copy_termmeta_mapped($pro,$stg,$pro_tables,$stg_tables,$term_map);if(is_wp_error($r))return$r;
            $tag_term_maps[$taxonomy]=$term_map;$stats[$taxonomy]=count((array)$plan['source_rows']);
        }

        // Relaciones: el object_id tambien se remapea al ID local de STAGING.
        $cursor_object=0;$cursor_tt=0;$copied_rel=0;
        while(true){
            $rows=self::rows($pro,
                "SELECT tr.object_id,tr.term_taxonomy_id,tr.term_order,tt.taxonomy
                 FROM `{$pro_tables['term_relationships']}` tr
                 JOIN `{$pro_tables['term_taxonomy']}` tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
                 JOIN `{$pro_tables['posts']}` p ON p.ID=tr.object_id
                 WHERE ".self::taxonomy_sql($pro,'tt')." AND ".self::post_type_sql($pro,'p')." AND p.post_status<>'trash'
                   AND (tr.object_id>{$cursor_object} OR (tr.object_id={$cursor_object} AND tr.term_taxonomy_id>{$cursor_tt}))
                 ORDER BY tr.object_id,tr.term_taxonomy_id LIMIT 800");
            if(is_wp_error($rows))return$rows;if(!$rows)break;$insert=array();
            foreach($rows as $row){$source_object=absint($row['object_id']);$source_tt=absint($row['term_taxonomy_id']);$target_object=absint($post_map[$source_object]??0);$target_tt=absint($source_tt_to_target[$source_tt]??0);if(!$target_object||!$target_tt)return new WP_Error('mirror_relationship_map','No se pudo resolver una relacion PRO con claves locales de STAGING.');$insert[]=array('object_id'=>$target_object,'term_taxonomy_id'=>$target_tt,'term_order'=>(int)$row['term_order']);}
            foreach(array_chunk($insert,180) as $chunk){$r=self::insert_rows($stg,$stg_tables['term_relationships'],array('object_id','term_taxonomy_id','term_order'),$chunk,false);if(is_wp_error($r))return$r;}
            $copied_rel+=count($insert);$last=end($rows);$cursor_object=absint($last['object_id']??$cursor_object);$cursor_tt=absint($last['term_taxonomy_id']??$cursor_tt);if(count($rows)<800)break;
        }
        $stats['term_relationships']=$copied_rel;
        $r=self::exec($stg,"UPDATE `{$stg_tables['term_taxonomy']}` tt SET tt.count=(SELECT COUNT(*) FROM `{$stg_tables['term_relationships']}` tr WHERE tr.term_taxonomy_id=tt.term_taxonomy_id) WHERE ".self::taxonomy_sql($stg,'tt'));if(is_wp_error($r))return$r;
        return array('category_term_map'=>$category_term_map,'category_tt_map'=>$category_tt_map,'tag_term_maps'=>$tag_term_maps,'tt_map'=>$source_tt_to_target);
    }


    private static function semantic_key($group, $slug) {
        return sanitize_key((string)$group) . '|' . sanitize_title((string)$slug);
    }

    private static function attribute_term_key($attribute_slug, $term_slug) {
        return sanitize_title((string)$attribute_slug) . '|' . sanitize_title((string)$term_slug);
    }

    private static function mirror_semantic_tables($pro, $stg, $pro_tables, $stg_tables, $post_map, $category_map, &$stats) {
        // 1) Vocabulario maestro por semantic_group + slug.
        $src_vocab=self::rows($pro,"SELECT id,semantic_group,slug,label,parent_id,source,active,created_at,updated_at FROM `{$pro_tables['seo_vocabulary']}` ORDER BY id");
        $dst_vocab=self::rows($stg,"SELECT id,semantic_group,slug,label,parent_id,source,active,created_at,updated_at FROM `{$stg_tables['seo_vocabulary']}` ORDER BY id");
        if(is_wp_error($src_vocab))return$src_vocab;if(is_wp_error($dst_vocab))return$dst_vocab;
        $dst_by_key=array();foreach($dst_vocab as $r){$k=self::semantic_key($r['semantic_group'],$r['slug']);if(isset($dst_by_key[$k]))return new WP_Error('mirror_vocab_duplicate','Vocabulario duplicado en STAGING: '.$k);$dst_by_key[$k]=$r;}
        $src_keys=array();$vmap=array();$parents=array();
        foreach($src_vocab as $r){$k=self::semantic_key($r['semantic_group'],$r['slug']);if(!$k||'|'===$k)return new WP_Error('mirror_vocab_key','Vocabulario PRO sin clave portable.');if(isset($src_keys[$k]))return new WP_Error('mirror_vocab_duplicate_pro','Vocabulario duplicado en PRO: '.$k);$src_keys[$k]=1;$sid=absint($r['id']);if(isset($dst_by_key[$k])){$tid=absint($dst_by_key[$k]['id']);$q="UPDATE `{$stg_tables['seo_vocabulary']}` SET semantic_group=".self::sql_value($stg,$r['semantic_group']).",slug=".self::sql_value($stg,$r['slug']).",label=".self::sql_value($stg,$r['label']).",source=".self::sql_value($stg,$r['source']).",active=".absint($r['active']).",updated_at=".self::sql_value($stg,$r['updated_at'])." WHERE id={$tid}";$x=self::exec($stg,$q);if(is_wp_error($x))return$x;}else{$q="INSERT INTO `{$stg_tables['seo_vocabulary']}` (semantic_group,slug,label,parent_id,source,active,created_at,updated_at) VALUES (".self::sql_value($stg,$r['semantic_group']).','.self::sql_value($stg,$r['slug']).','.self::sql_value($stg,$r['label']).",NULL,".self::sql_value($stg,$r['source']).','.absint($r['active']).','.self::sql_value($stg,$r['created_at']).','.self::sql_value($stg,$r['updated_at']).')';$x=self::exec($stg,$q);if(is_wp_error($x))return$x;$tid=absint(mysqli_insert_id($stg));}$vmap[$sid]=$tid;$parents[$sid]=absint($r['parent_id']??0);}
        foreach($parents as $sid=>$sp){$tid=absint($vmap[$sid]??0);$tp=$sp?absint($vmap[$sp]??0):0;$x=self::exec($stg,"UPDATE `{$stg_tables['seo_vocabulary']}` SET parent_id=".($tp?$tp:'NULL')." WHERE id={$tid}");if(is_wp_error($x))return$x;}

        // 2) Atributos por slug; terminos por attribute_slug + term_slug.
        $src_attr=self::rows($pro,"SELECT * FROM `{$pro_tables['sql_atributos']}` ORDER BY id");$dst_attr=self::rows($stg,"SELECT * FROM `{$stg_tables['sql_atributos']}` ORDER BY id");if(is_wp_error($src_attr))return$src_attr;if(is_wp_error($dst_attr))return$dst_attr;
        $dst_attr_by=array();foreach($dst_attr as $r){$k=sanitize_title((string)$r['slug']);if(isset($dst_attr_by[$k]))return new WP_Error('mirror_attr_duplicate','Atributo duplicado en STAGING: '.$k);$dst_attr_by[$k]=$r;}
        $attr_map=array();$src_attr_keys=array();$attr_cols=array('slug','nombre','grupo','tipo','unidad_tipo','unidad_base','multiple','filtrable','visible','seo','orden','activo','created_at','updated_at');
        foreach($src_attr as $r){$k=sanitize_title((string)$r['slug']);if(!$k)return new WP_Error('mirror_attr_key','Atributo PRO sin slug.');if(isset($src_attr_keys[$k]))return new WP_Error('mirror_attr_duplicate_pro','Atributo duplicado en PRO: '.$k);$src_attr_keys[$k]=1;$sid=absint($r['id']);if(isset($dst_attr_by[$k])){$tid=absint($dst_attr_by[$k]['id']);$sets=array();foreach($attr_cols as $c)$sets[]=self::ident($c).'='.self::sql_value($stg,$r[$c]??null);$x=self::exec($stg,"UPDATE `{$stg_tables['sql_atributos']}` SET ".implode(',',$sets)." WHERE id={$tid}");if(is_wp_error($x))return$x;}else{$data=array();foreach($attr_cols as $c)$data[$c]=$r[$c]??null;$x=self::insert_rows($stg,$stg_tables['sql_atributos'],$attr_cols,array($data),false);if(is_wp_error($x))return$x;$tid=absint(mysqli_insert_id($stg));}$attr_map[$sid]=$tid;}
        $src_terms=self::rows($pro,"SELECT t.*,a.slug attribute_slug FROM `{$pro_tables['sql_atributos_terminos']}` t JOIN `{$pro_tables['sql_atributos']}` a ON a.id=t.atributo_id ORDER BY t.id");$dst_terms=self::rows($stg,"SELECT t.*,a.slug attribute_slug FROM `{$stg_tables['sql_atributos_terminos']}` t JOIN `{$stg_tables['sql_atributos']}` a ON a.id=t.atributo_id ORDER BY t.id");if(is_wp_error($src_terms))return$src_terms;if(is_wp_error($dst_terms))return$dst_terms;
        $dst_term_by=array();foreach($dst_terms as $r){$k=self::attribute_term_key($r['attribute_slug'],$r['slug']);if(isset($dst_term_by[$k]))return new WP_Error('mirror_attr_term_duplicate','Termino de atributo duplicado en STAGING: '.$k);$dst_term_by[$k]=$r;}
        $term_map=array();$src_term_keys=array();
        foreach($src_terms as $r){$k=self::attribute_term_key($r['attribute_slug'],$r['slug']);if(isset($src_term_keys[$k]))return new WP_Error('mirror_attr_term_duplicate_pro','Termino de atributo duplicado en PRO: '.$k);$src_term_keys[$k]=1;$sid=absint($r['id']);$target_attr=absint($attr_map[absint($r['atributo_id'])]??0);if(!$target_attr)return new WP_Error('mirror_attr_map','No se pudo resolver atributo para '.$k);if(isset($dst_term_by[$k])){$tid=absint($dst_term_by[$k]['id']);$x=self::exec($stg,"UPDATE `{$stg_tables['sql_atributos_terminos']}` SET atributo_id={$target_attr},slug=".self::sql_value($stg,$r['slug']).",nombre=".self::sql_value($stg,$r['nombre']).",orden=".(int)$r['orden'].",activo=".absint($r['activo'])." WHERE id={$tid}");if(is_wp_error($x))return$x;}else{$x=self::exec($stg,"INSERT INTO `{$stg_tables['sql_atributos_terminos']}` (atributo_id,slug,nombre,orden,activo) VALUES ({$target_attr},".self::sql_value($stg,$r['slug']).','.self::sql_value($stg,$r['nombre']).','.(int)$r['orden'].','.absint($r['activo']).')');if(is_wp_error($x))return$x;$tid=absint(mysqli_insert_id($stg));}$term_map[$sid]=$tid;}

        // 3) Tablas dependientes: se reconstruyen con IDs locales.
        $x=self::exec($stg,"DELETE FROM `{$stg_tables['seo_type_role_map']}`");if(is_wp_error($x))return$x;
        $rows=self::rows($pro,"SELECT type_vocabulary_id,role_vocabulary_id,confidence,source,active,created_at,updated_at FROM `{$pro_tables['seo_type_role_map']}` ORDER BY id");if(is_wp_error($rows))return$rows;$ins=array();foreach($rows as $r){$tv=absint($vmap[absint($r['type_vocabulary_id'])]??0);$rv=absint($vmap[absint($r['role_vocabulary_id'])]??0);if(!$tv||!$rv)return new WP_Error('mirror_type_role_map','No se pudo remapear TIPO→ROL.');$ins[]=array('type_vocabulary_id'=>$tv,'role_vocabulary_id'=>$rv,'confidence'=>$r['confidence'],'source'=>$r['source'],'active'=>$r['active'],'created_at'=>$r['created_at'],'updated_at'=>$r['updated_at']);}foreach(array_chunk($ins,150) as $c){$x=self::insert_rows($stg,$stg_tables['seo_type_role_map'],array('type_vocabulary_id','role_vocabulary_id','confidence','source','active','created_at','updated_at'),$c,false);if(is_wp_error($x))return$x;}

        $x=self::exec($stg,"DELETE FROM `{$stg_tables['sql_atributos_aliases']}`");if(is_wp_error($x))return$x;$rows=self::rows($pro,"SELECT atributo_id,termino_id,alias FROM `{$pro_tables['sql_atributos_aliases']}` ORDER BY id");if(is_wp_error($rows))return$rows;$ins=array();foreach($rows as $r){$a=absint($attr_map[absint($r['atributo_id'])]??0);$t=absint($r['termino_id']??0);$lt=$t?absint($term_map[$t]??0):0;if(!$a||($t&&!$lt))return new WP_Error('mirror_alias_map','No se pudo remapear un alias de atributo.');$ins[]=array('atributo_id'=>$a,'termino_id'=>$lt?:null,'alias'=>$r['alias']);}foreach(array_chunk($ins,200) as $c){$x=self::insert_rows($stg,$stg_tables['sql_atributos_aliases'],array('atributo_id','termino_id','alias'),$c,false);if(is_wp_error($x))return$x;}

        $x=self::exec($stg,"DELETE FROM `{$stg_tables['sql_product_atributos']}`");if(is_wp_error($x))return$x;$rows=self::rows($pro,"SELECT product_id,atributo_id,termino_id,valor_texto,valor_numero,valor_numero_max,unidad,valor_original,orden FROM `{$pro_tables['sql_product_atributos']}` ORDER BY id");if(is_wp_error($rows))return$rows;$ins=array();foreach($rows as $r){$p=absint($post_map[absint($r['product_id'])]??0);$a=absint($attr_map[absint($r['atributo_id'])]??0);$st=absint($r['termino_id']??0);$t=$st?absint($term_map[$st]??0):0;if(!$p||!$a||($st&&!$t))return new WP_Error('mirror_product_attr_map','No se pudo remapear un atributo de producto.');$ins[]=array('product_id'=>$p,'atributo_id'=>$a,'termino_id'=>$t?:null,'valor_texto'=>$r['valor_texto'],'valor_numero'=>$r['valor_numero'],'valor_numero_max'=>$r['valor_numero_max'],'unidad'=>$r['unidad'],'valor_original'=>$r['valor_original'],'orden'=>$r['orden']);if(count($ins)>=200){$x=self::insert_rows($stg,$stg_tables['sql_product_atributos'],array('product_id','atributo_id','termino_id','valor_texto','valor_numero','valor_numero_max','unidad','valor_original','orden'),$ins,false);if(is_wp_error($x))return$x;$ins=array();}}if($ins){$x=self::insert_rows($stg,$stg_tables['sql_product_atributos'],array('product_id','atributo_id','termino_id','valor_texto','valor_numero','valor_numero_max','unidad','valor_original','orden'),$ins,false);if(is_wp_error($x))return$x;}

        $managed_types=array('product','product_cat','page','post');$quoted="'".implode("','",array_map(static function($v)use($stg){return mysqli_real_escape_string($stg,$v);},$managed_types))."'";$x=self::exec($stg,"DELETE FROM `{$stg_tables['seo_object_vocabulary']}` WHERE object_type IN ({$quoted})");if(is_wp_error($x))return$x;
        $rows=self::rows($pro,"SELECT object_type,object_id,vocabulary_id,source,confidence,status,created_at,updated_at FROM `{$pro_tables['seo_object_vocabulary']}` WHERE object_type IN ('product','product_cat','page','post') ORDER BY id");if(is_wp_error($rows))return$rows;$object_vocab_count=count($rows);$ins=array();foreach($rows as $r){$ot=sanitize_key((string)$r['object_type']);$sid=absint($r['object_id']);$oid='product_cat'===$ot?absint($category_map[$sid]??0):absint($post_map[$sid]??0);$vid=absint($vmap[absint($r['vocabulary_id'])]??0);if(!$oid||!$vid)return new WP_Error('mirror_object_vocab_map','No se pudo remapear una asignacion semantica '.$ot.' #'.$sid.'.');$ins[]=array('object_type'=>$ot,'object_id'=>$oid,'vocabulary_id'=>$vid,'source'=>$r['source'],'confidence'=>$r['confidence'],'status'=>$r['status'],'created_at'=>$r['created_at'],'updated_at'=>$r['updated_at']);if(count($ins)>=200){$x=self::insert_rows($stg,$stg_tables['seo_object_vocabulary'],array('object_type','object_id','vocabulary_id','source','confidence','status','created_at','updated_at'),$ins,false);if(is_wp_error($x))return$x;$ins=array();}}if($ins){$x=self::insert_rows($stg,$stg_tables['seo_object_vocabulary'],array('object_type','object_id','vocabulary_id','source','confidence','status','created_at','updated_at'),$ins,false);if(is_wp_error($x))return$x;}

        // 4) Eliminar maestros extra una vez remapeadas todas sus dependencias.
        $dst_terms_now=self::rows($stg,"SELECT t.id,a.slug attribute_slug,t.slug FROM `{$stg_tables['sql_atributos_terminos']}` t JOIN `{$stg_tables['sql_atributos']}` a ON a.id=t.atributo_id");if(is_wp_error($dst_terms_now))return$dst_terms_now;foreach($dst_terms_now as $r){$k=self::attribute_term_key($r['attribute_slug'],$r['slug']);if(!isset($src_term_keys[$k])){$x=self::exec($stg,"DELETE FROM `{$stg_tables['sql_atributos_terminos']}` WHERE id=".absint($r['id']));if(is_wp_error($x))return$x;}}
        $dst_attr_now=self::rows($stg,"SELECT id,slug FROM `{$stg_tables['sql_atributos']}`");if(is_wp_error($dst_attr_now))return$dst_attr_now;foreach($dst_attr_now as $r){$k=sanitize_title((string)$r['slug']);if(!isset($src_attr_keys[$k])){$x=self::exec($stg,"DELETE FROM `{$stg_tables['sql_atributos']}` WHERE id=".absint($r['id']));if(is_wp_error($x))return$x;}}
        $dst_vocab_now=self::rows($stg,"SELECT id,semantic_group,slug FROM `{$stg_tables['seo_vocabulary']}`");if(is_wp_error($dst_vocab_now))return$dst_vocab_now;foreach($dst_vocab_now as $r){$k=self::semantic_key($r['semantic_group'],$r['slug']);if(!isset($src_keys[$k])){$x=self::exec($stg,"DELETE FROM `{$stg_tables['seo_vocabulary']}` WHERE id=".absint($r['id']));if(is_wp_error($x))return$x;}}
        $stats['seo_vocabulary']=count($src_vocab);$stats['sql_atributos']=count($src_attr);$stats['sql_atributos_terminos']=count($src_terms);$stats['seo_object_vocabulary']=$object_vocab_count;
        return array('vocabulary_map'=>$vmap,'attribute_map'=>$attr_map,'attribute_term_map'=>$term_map);
    }

    private static function mirror_nodes($pro,$stg,$pro_tables,$stg_tables,$post_map,$category_map,&$stats){
        $x=self::exec($stg,"DELETE FROM `{$stg_tables['seo_nodes']}` WHERE object_type IN ('category','page','post')");if(is_wp_error($x))return$x;
        $rows=self::rows($pro,"SELECT object_type,object_id,seo_role,keywords,title,status,created_at,updated_at FROM `{$pro_tables['seo_nodes']}` WHERE object_type IN ('category','page','post') ORDER BY id");if(is_wp_error($rows))return$rows;$ins=array();foreach($rows as $r){$ot=sanitize_key((string)$r['object_type']);$sid=absint($r['object_id']);$oid='category'===$ot?absint($category_map[$sid]??0):absint($post_map[$sid]??0);if(!$oid)return new WP_Error('mirror_node_map','No se pudo remapear seo_nodes '.$ot.' #'.$sid.'.');$ins[]=array('object_type'=>$ot,'object_id'=>$oid,'seo_role'=>$r['seo_role'],'keywords'=>$r['keywords'],'title'=>$r['title'],'status'=>$r['status'],'created_at'=>$r['created_at'],'updated_at'=>$r['updated_at']);if(count($ins)>=200){$x=self::insert_rows($stg,$stg_tables['seo_nodes'],array('object_type','object_id','seo_role','keywords','title','status','created_at','updated_at'),$ins,false);if(is_wp_error($x))return$x;$ins=array();}}if($ins){$x=self::insert_rows($stg,$stg_tables['seo_nodes'],array('object_type','object_id','seo_role','keywords','title','status','created_at','updated_at'),$ins,false);if(is_wp_error($x))return$x;}$stats['seo_nodes']=count($rows);return true;
    }

    private static function relation_managed_types(){return array('cluster_to_primary','cluster_to_hub_primary','hub_primary_to_hub_secondary','hub_secondary_to_landing','hub_secondary_to_category','cluster_to_category','hub_primary_to_category','landing_to_category','post_to_category');}
    private static function relation_endpoint_map($type,$id,$post_map,$category_map){$type=sanitize_key((string)$type);$id=absint($id);if(in_array($type,array('product_cat','category'),true))return absint($category_map[$id]??0);if(in_array($type,array('product','post','page','cluster','hub_primary','hub_secondary','landing','landing_page'),true))return absint($post_map[$id]??0);return 0;}
    private static function mirror_relations($pro,$stg,$pro_tables,$stg_tables,$post_map,$category_map,&$stats){$types=self::relation_managed_types();$quoted="'".implode("','",array_map(static function($v)use($stg){return mysqli_real_escape_string($stg,$v);},$types))."'";$x=self::exec($stg,"DELETE FROM `{$stg_tables['seo_relations']}` WHERE relation_type IN ({$quoted})");if(is_wp_error($x))return$x;$rows=self::rows($pro,"SELECT source_type,source_id,target_type,target_id,relation_type,created_at FROM `{$pro_tables['seo_relations']}` WHERE relation_type IN ("."'".implode("','",array_map(static function($v)use($pro){return mysqli_real_escape_string($pro,$v);},$types))."'".") ORDER BY id");if(is_wp_error($rows))return$rows;$ins=array();foreach($rows as $r){$s=self::relation_endpoint_map($r['source_type'],$r['source_id'],$post_map,$category_map);$t=self::relation_endpoint_map($r['target_type'],$r['target_id'],$post_map,$category_map);if(!$s||!$t)return new WP_Error('mirror_relation_map','No se pudo remapear '.$r['relation_type'].' con IDs locales de STAGING.');$ins[]=array('source_type'=>$r['source_type'],'source_id'=>$s,'target_type'=>$r['target_type'],'target_id'=>$t,'relation_type'=>$r['relation_type'],'created_at'=>$r['created_at']);}foreach(array_chunk($ins,200) as $c){$x=self::insert_rows($stg,$stg_tables['seo_relations'],array('source_type','source_id','target_type','target_id','relation_type','created_at'),$c,false);if(is_wp_error($x))return$x;}$stats['seo_relations']=count($ins);return true;}

    private static function mirror_faqs($pro,$stg,$pro_tables,$stg_tables,$post_map,$category_map,&$stats){$x=self::exec($stg,"DELETE FROM `{$stg_tables['seo_faq']}` WHERE object_type IN (1,2,3)");if(is_wp_error($x))return$x;$rows=self::rows($pro,"SELECT object_type,object_id,question,answer,sort_order,active,load_count,open_count,created_at,updated_at FROM `{$pro_tables['seo_faq']}` WHERE object_type IN (1,2,3) ORDER BY id");if(is_wp_error($rows))return$rows;$ins=array();foreach($rows as $r){$ot=absint($r['object_type']);$sid=absint($r['object_id']);$oid=2===$ot?absint($category_map[$sid]??0):absint($post_map[$sid]??0);if(!$oid)return new WP_Error('mirror_faq_map','No se pudo remapear FAQ object_type='.$ot.' object_id='.$sid.'.');$ins[]=array('object_type'=>$ot,'object_id'=>$oid,'question'=>$r['question'],'answer'=>$r['answer'],'sort_order'=>$r['sort_order'],'active'=>$r['active'],'load_count'=>$r['load_count'],'open_count'=>$r['open_count'],'created_at'=>$r['created_at'],'updated_at'=>$r['updated_at']);if(count($ins)>=150){$x=self::insert_rows($stg,$stg_tables['seo_faq'],array('object_type','object_id','question','answer','sort_order','active','load_count','open_count','created_at','updated_at'),$ins,false);if(is_wp_error($x))return$x;$ins=array();}}if($ins){$x=self::insert_rows($stg,$stg_tables['seo_faq'],array('object_type','object_id','question','answer','sort_order','active','load_count','open_count','created_at','updated_at'),$ins,false);if(is_wp_error($x))return$x;}$stats['seo_faq']=count($rows);return true;}

    private static function mirror_wc_lookup_if_available($pro, $stg, $pro_prefix, $stg_prefix, $post_map, &$stats) {
        $source = $pro_prefix . 'wc_product_meta_lookup';
        $target = $stg_prefix . 'wc_product_meta_lookup';
        if (!self::table_exists($pro, $source) || !self::table_exists($stg, $target)) return true;
        $source_columns = self::table_columns($pro, $source);
        $target_columns = self::table_columns($stg, $target);
        if (is_wp_error($source_columns) || is_wp_error($target_columns) || $source_columns !== $target_columns || !in_array('product_id', $source_columns, true)) return true;
        $target_ids=array_values(array_unique(array_filter(array_map('absint',array_values((array)$post_map)))));
        foreach(self::id_chunks($target_ids) as $chunk){$r=self::exec($stg,"DELETE FROM `{$target}` WHERE product_id IN (".self::sql_ids($chunk).')');if(is_wp_error($r))return$r;}
        $cols=implode(',',array_map(array(__CLASS__,'ident'),$source_columns));$cursor=0;$copied=0;
        while(true){$rows=self::rows($pro,"SELECT {$cols} FROM `{$source}` WHERE product_id>{$cursor} ORDER BY product_id LIMIT 500");if(is_wp_error($rows))return$rows;if(!$rows)break;$insert=array();foreach($rows as $row){$sid=absint($row['product_id']);$tid=absint($post_map[$sid]??0);if(!$tid)continue;$row['product_id']=$tid;$insert[]=$row;}foreach(array_chunk($insert,120) as $chunk){$r=self::insert_rows($stg,$target,$source_columns,$chunk,true);if(is_wp_error($r))return$r;}$copied+=count($insert);$cursor=absint(end($rows)['product_id']??$cursor);if(count($rows)<500)break;}
        $stats['wc_product_meta_lookup']=$copied;return true;
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

            $post_map = self::mirror_posts($pro, $stg, $pro_tables, $stg_tables, $stats);
            if (is_wp_error($post_map)) throw new RuntimeException($post_map->get_error_message());
            $tax_maps = self::mirror_taxonomies($pro, $stg, $pro_tables, $stg_tables, $post_map, $stats);
            if (is_wp_error($tax_maps)) throw new RuntimeException($tax_maps->get_error_message());
            $category_map = (array) ($tax_maps['category_term_map'] ?? array());

            $semantic_maps = self::mirror_semantic_tables($pro, $stg, $pro_tables, $stg_tables, $post_map, $category_map, $stats);
            if (is_wp_error($semantic_maps)) throw new RuntimeException($semantic_maps->get_error_message());
            $r = self::mirror_nodes($pro, $stg, $pro_tables, $stg_tables, $post_map, $category_map, $stats);
            if (is_wp_error($r)) throw new RuntimeException($r->get_error_message());
            $r = self::mirror_relations($pro, $stg, $pro_tables, $stg_tables, $post_map, $category_map, $stats);
            if (is_wp_error($r)) throw new RuntimeException($r->get_error_message());
            $r = self::mirror_faqs($pro, $stg, $pro_tables, $stg_tables, $post_map, $category_map, $stats);
            if (is_wp_error($r)) throw new RuntimeException($r->get_error_message());

            $pro_prefix = substr($pro_tables['posts'], 0, -strlen('posts'));
            $stg_prefix = substr($stg_tables['posts'], 0, -strlen('posts'));
            $r = self::mirror_wc_lookup_if_available($pro, $stg, $pro_prefix, $stg_prefix, $post_map, $stats);
            if (is_wp_error($r)) throw new RuntimeException($r->get_error_message());

            $r = self::exec($stg, 'COMMIT');
            if (is_wp_error($r)) throw new RuntimeException($r->get_error_message());
        } catch (Throwable $e) {
            @mysqli_query($stg, 'ROLLBACK');
            return new WP_Error('full_mirror_apply', 'MirrorEngine cancelado y revertido: ' . $e->getMessage());
        } finally {
            @mysqli_query($stg, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($stg, self::LOCK_NAME) . "')");
        }

        $generation = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('mirror-', true);
        $payload = array(
            'generation' => $generation,
            'completed_at' => time(),
            'source' => 'pro',
            'destination' => 'staging',
            'engine' => 'portable_mirror_engine',
            'stats' => $stats,
            'identity' => $identity,
            'duration_seconds' => round(microtime(true) - $started, 3),
        );
        self::set_staging_option($stg, $stg_tables['options'], 'seo_environment_full_mirror_generation', $payload);
        self::set_staging_option($stg, $stg_tables['options'], 'seo_environment_full_mirror_last', $payload);
        self::set_staging_option($stg, $stg_tables['options'], 'seo_semantic_catalog_reindex_pending', array(
            'reason' => 'environment_full_mirror',
            'generation' => $generation,
            'created_at' => time(),
        ));
        self::set_staging_option($stg, $stg_tables['options'], 'seo_semantic_catalog_drift', array(
            'reason' => 'environment_full_mirror',
            'generation' => $generation,
            'created_at' => time(),
        ));
        $r = self::invalidate_comparator_remote($stg, substr($stg_tables['posts'], 0, -strlen('posts')));
        if (is_wp_error($r)) return $r;
        self::invalidate_local_comparator();
        return $payload;
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
                'actions' => (array) ($analysis['actions'] ?? array()),
                'identity_resolution' => (array) ($analysis['identity_resolution'] ?? array()),
                'reference_audit' => (array) ($analysis['reference_audit'] ?? array()),
                'plan_version' => '2.4.1',
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
                'seconds' => (float) ($result['duration_seconds'] ?? 0),
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

SEO_Environment_Mirror_Engine::init();
if (!class_exists('SEO_Environment_Full_Mirror')) {
    class_alias('SEO_Environment_Mirror_Engine', 'SEO_Environment_Full_Mirror');
}
