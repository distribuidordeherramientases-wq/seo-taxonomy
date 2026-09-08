<?php
/**
 * SEO System - Direct portable semantic catalog synchronization PRO -> STAGING.
 *
 * Reads PRO through the existing read-only environment connection, builds the
 * same portable document used by Import/Export, previews it against local
 * STAGING and only then applies MIRROR through SEO_Semantic_Catalog_Transfer.
 * Source numeric IDs are audit-only.
 *
 * @package SEOSystem
 * @subpackage ImportExport
 * @since 2.3.5
 */

defined('ABSPATH') || exit;

final class SEO_Semantic_Catalog_Direct_Sync {
    const NONCE_ACTION = 'seo_semantic_catalog_direct';
    const PREVIEW_PREFIX = 'seo_semantic_catalog_direct_preview_';
    const PREVIEW_TTL = 3600;

    public static function init() {
        add_action('wp_ajax_seo_semantic_catalog_direct_preview', array(__CLASS__, 'ajax_preview'));
        add_action('wp_ajax_seo_semantic_catalog_direct_apply', array(__CLASS__, 'ajax_apply'));
    }

    public static function scopes() {
        return array(
            'masters',
            'product_tags',
            'product_semantic',
            'product_attributes',
            'category_semantic',
            'category_labels',
        );
    }

    private static function authorize() {
        if (!current_user_can('manage_options')) {
            return new WP_Error('semantic_direct_capability', 'Permisos insuficientes.');
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!class_exists('SEO_Semantic_Catalog_Transfer')) {
            return new WP_Error('semantic_direct_engine', 'El motor de Catalogo semantico no esta disponible.');
        }
        if (!function_exists('seo_environment_compare_current_env') || 'staging' !== seo_environment_compare_current_env()) {
            return new WP_Error('semantic_direct_environment', 'La alineacion directa PRO -> STAGING solo puede ejecutarse desde STAGING.');
        }
        if (function_exists('seo_environment_compare_running_entity') && seo_environment_compare_running_entity()) {
            return new WP_Error('semantic_direct_busy', 'Hay un escaneo del Comparador en curso. Detenlo o espera a que termine antes de alinear el catalogo.');
        }
        return true;
    }

    private static function rows($mysqli, $sql) {
        if (!function_exists('seo_environment_compare_query_rows')) {
            return new WP_Error('semantic_direct_compare', 'No esta disponible el lector remoto del Comparador.');
        }
        return seo_environment_compare_query_rows($mysqli, $sql);
    }

    private static function table_exists($mysqli, $table) {
        $pattern = str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), (string) $table);
        $escaped = mysqli_real_escape_string($mysqli, $pattern);
        $rows = self::rows($mysqli, "SHOW TABLES LIKE '{$escaped}'");
        return !is_wp_error($rows) && !empty($rows);
    }

    private static function required_remote_tables($prefix) {
        return array(
            'posts' => $prefix . 'posts',
            'postmeta' => $prefix . 'postmeta',
            'terms' => $prefix . 'terms',
            'term_taxonomy' => $prefix . 'term_taxonomy',
            'term_relationships' => $prefix . 'term_relationships',
            'vocabulary' => $prefix . 'seo_vocabulary',
            'object_vocabulary' => $prefix . 'seo_object_vocabulary',
            'type_role_map' => $prefix . 'seo_type_role_map',
            'attributes' => $prefix . 'sql_atributos',
            'attribute_terms' => $prefix . 'sql_atributos_terminos',
            'attribute_aliases' => $prefix . 'sql_atributos_aliases',
            'product_attributes' => $prefix . 'sql_product_atributos',
            'nodes' => $prefix . 'seo_nodes',
        );
    }

    private static function validate_remote_tables($mysqli, $tables) {
        foreach ($tables as $key => $table) {
            if (!self::table_exists($mysqli, $table)) {
                return new WP_Error('semantic_direct_missing_table', 'PRO no contiene la tabla requerida: ' . $table . ' (' . $key . ').');
            }
        }
        return true;
    }

    private static function build_remote_masters($mysqli, $t) {
        $vocab_rows = self::rows($mysqli, "SELECT id,semantic_group,slug,label,parent_id,source,active,created_at,updated_at FROM `{$t['vocabulary']}` ORDER BY semantic_group,slug,id");
        if (is_wp_error($vocab_rows)) return $vocab_rows;
        $vocab_by_id = array();
        foreach ($vocab_rows as $row) $vocab_by_id[absint($row['id'])] = $row;
        $vocabulary = array();
        foreach ($vocab_rows as $row) {
            $parent = null;
            $parent_id = absint($row['parent_id'] ?? 0);
            if ($parent_id && isset($vocab_by_id[$parent_id])) {
                $parent = array(
                    'semantic_group' => sanitize_key((string) $vocab_by_id[$parent_id]['semantic_group']),
                    'slug' => sanitize_title((string) $vocab_by_id[$parent_id]['slug']),
                );
            }
            $vocabulary[] = array(
                'key' => array('semantic_group' => sanitize_key((string) $row['semantic_group']), 'slug' => sanitize_title((string) $row['slug'])),
                'label' => (string) $row['label'],
                'parent' => $parent,
                'source' => (string) $row['source'],
                'active' => absint($row['active']) ? 1 : 0,
                'audit' => array('source_id' => absint($row['id']), 'created_at' => (string) $row['created_at'], 'updated_at' => (string) $row['updated_at']),
            );
        }

        $map_rows = self::rows($mysqli,
            "SELECT m.id,m.type_vocabulary_id,tv.slug type_slug,m.role_vocabulary_id,rv.slug role_slug,m.confidence,m.source,m.active,m.created_at,m.updated_at
             FROM `{$t['type_role_map']}` m
             JOIN `{$t['vocabulary']}` tv ON tv.id=m.type_vocabulary_id AND tv.semantic_group='tipo'
             JOIN `{$t['vocabulary']}` rv ON rv.id=m.role_vocabulary_id AND rv.semantic_group='rol'
             ORDER BY tv.slug,m.id"
        );
        if (is_wp_error($map_rows)) return $map_rows;
        $type_role_map = array();
        foreach ($map_rows as $row) {
            $type_role_map[] = array(
                'type' => array('semantic_group' => 'tipo', 'slug' => sanitize_title((string) $row['type_slug'])),
                'role' => array('semantic_group' => 'rol', 'slug' => sanitize_title((string) $row['role_slug'])),
                'confidence' => (string) $row['confidence'],
                'source' => (string) $row['source'],
                'active' => absint($row['active']) ? 1 : 0,
                'audit' => array(
                    'source_id' => absint($row['id']),
                    'type_vocabulary_id' => absint($row['type_vocabulary_id']),
                    'role_vocabulary_id' => absint($row['role_vocabulary_id']),
                    'created_at' => (string) $row['created_at'],
                    'updated_at' => (string) $row['updated_at'],
                ),
            );
        }

        $attribute_rows = self::rows($mysqli, "SELECT * FROM `{$t['attributes']}` ORDER BY slug,id");
        if (is_wp_error($attribute_rows)) return $attribute_rows;
        $attributes = array();
        $attribute_by_id = array();
        foreach ($attribute_rows as $row) {
            $attribute_slug = sanitize_key((string) $row['slug']);
            $attribute_by_id[absint($row['id'])] = $attribute_slug;
            $attributes[] = array(
                'key' => array('attribute_slug' => $attribute_slug),
                'name' => (string) $row['nombre'],
                'group' => (string) $row['grupo'],
                'type' => (string) $row['tipo'],
                'unit_type' => (string) $row['unidad_tipo'],
                'base_unit' => (string) $row['unidad_base'],
                'multiple' => absint($row['multiple']),
                'filterable' => absint($row['filtrable']),
                'visible' => absint($row['visible']),
                'seo' => absint($row['seo']),
                'sort_order' => (int) $row['orden'],
                'active' => absint($row['activo']) ? 1 : 0,
                'audit' => array('source_id' => absint($row['id']), 'created_at' => (string) ($row['created_at'] ?? ''), 'updated_at' => (string) ($row['updated_at'] ?? '')),
            );
        }

        $term_rows = self::rows($mysqli, "SELECT * FROM `{$t['attribute_terms']}` ORDER BY atributo_id,slug,id");
        if (is_wp_error($term_rows)) return $term_rows;
        $terms = array();
        $term_by_id = array();
        foreach ($term_rows as $row) {
            $attribute_slug = $attribute_by_id[absint($row['atributo_id'])] ?? '';
            if (!$attribute_slug) continue;
            $term_slug = sanitize_title((string) $row['slug']);
            $term_by_id[absint($row['id'])] = array('attribute_slug' => $attribute_slug, 'term_slug' => $term_slug);
            $terms[] = array(
                'key' => array('attribute_slug' => $attribute_slug, 'term_slug' => $term_slug),
                'name' => (string) $row['nombre'],
                'sort_order' => (int) $row['orden'],
                'active' => absint($row['activo']) ? 1 : 0,
                'audit' => array('source_id' => absint($row['id']), 'attribute_id' => absint($row['atributo_id'])),
            );
        }

        $alias_rows = self::rows($mysqli, "SELECT id,atributo_id,termino_id,alias FROM `{$t['attribute_aliases']}` ORDER BY atributo_id,alias,id");
        if (is_wp_error($alias_rows)) return $alias_rows;
        $alias_groups = array();
        foreach ($alias_rows as $row) {
            $attribute_slug = $attribute_by_id[absint($row['atributo_id'])] ?? '';
            if (!$attribute_slug) continue;
            $key = $attribute_slug . '|' . mb_strtolower((string) $row['alias'], 'UTF-8');
            if (!isset($alias_groups[$key])) {
                $alias_groups[$key] = array(
                    'key' => array('attribute_slug' => $attribute_slug, 'alias' => (string) $row['alias']),
                    'term_slugs' => array(),
                    'audit' => array('source_ids' => array()),
                );
            }
            $term_id = absint($row['termino_id'] ?? 0);
            $alias_groups[$key]['term_slugs'][] = ($term_id && isset($term_by_id[$term_id])) ? $term_by_id[$term_id]['term_slug'] : '';
            $alias_groups[$key]['audit']['source_ids'][] = absint($row['id']);
        }
        $aliases = array_values($alias_groups);
        foreach ($aliases as &$alias) {
            $alias['term_slugs'] = array_values(array_unique($alias['term_slugs']));
            sort($alias['term_slugs'], SORT_STRING);
        }
        unset($alias);

        $tag_rows = self::rows($mysqli,
            "SELECT t.term_id,t.name,t.slug,tt.description,tt.count
             FROM `{$t['terms']}` t JOIN `{$t['term_taxonomy']}` tt ON tt.term_id=t.term_id
             WHERE tt.taxonomy='product_tag' ORDER BY t.slug,t.term_id"
        );
        if (is_wp_error($tag_rows)) return $tag_rows;
        $product_tags = array();
        foreach ($tag_rows as $row) {
            $product_tags[] = array(
                'key' => array('taxonomy' => 'product_tag', 'slug' => sanitize_title((string) $row['slug'])),
                'name' => (string) $row['name'],
                'description' => (string) $row['description'],
                'audit' => array('source_term_id' => absint($row['term_id']), 'count' => absint($row['count'])),
            );
        }

        return array(
            'semantic_vocabulary' => $vocabulary,
            'type_role_map' => $type_role_map,
            'attributes' => $attributes,
            'attribute_terms' => $terms,
            'attribute_aliases' => $aliases,
            'product_tags' => $product_tags,
        );
    }

    private static function product_identity_string($identity) {
        foreach (array('catalog_uid', 'sku', 'slug') as $key) {
            $value = trim((string) ($identity[$key] ?? ''));
            if ('' !== $value) return $key . '|' . mb_strtolower($value, 'UTF-8');
        }
        $provider = trim((string) ($identity['provider'] ?? ''));
        $external = trim((string) ($identity['external_id'] ?? ''));
        return ($provider || $external) ? 'provider|' . mb_strtolower($provider, 'UTF-8') . '|external|' . mb_strtolower($external, 'UTF-8') : '';
    }

    private static function id_sql($ids) {
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
        return $ids ? implode(',', $ids) : '0';
    }

    private static function build_remote_products($mysqli, $t) {
        $rows = self::rows($mysqli,
            "SELECT p.ID,p.post_name,p.post_title,
                    MAX(CASE WHEN pm.meta_key='_seo_catalog_uid' THEN pm.meta_value END) catalog_uid,
                    MAX(CASE WHEN pm.meta_key='_seo_proveedor' THEN pm.meta_value END) provider,
                    MAX(CASE WHEN pm.meta_key='_seo_proveedor_id_externo' THEN pm.meta_value END) external_id,
                    MAX(CASE WHEN pm.meta_key='_sku' THEN pm.meta_value END) sku
             FROM `{$t['posts']}` p
             LEFT JOIN `{$t['postmeta']}` pm ON pm.post_id=p.ID AND pm.meta_key IN ('_seo_catalog_uid','_seo_proveedor','_seo_proveedor_id_externo','_sku')
             WHERE p.post_type='product' AND p.post_status<>'trash'
             GROUP BY p.ID,p.post_name,p.post_title
             ORDER BY p.ID"
        );
        if (is_wp_error($rows)) return $rows;
        $ids = array_values(array_filter(array_map('absint', wp_list_pluck($rows, 'ID'))));
        $tag_map = array();
        $semantic_map = array();
        $attribute_map = array();

        foreach (array_chunk($ids, 1500) as $chunk) {
            $id_sql = self::id_sql($chunk);
            $tag_rows = self::rows($mysqli,
                "SELECT tr.object_id,t.slug
                 FROM `{$t['term_relationships']}` tr
                 JOIN `{$t['term_taxonomy']}` tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_tag'
                 JOIN `{$t['terms']}` t ON t.term_id=tt.term_id
                 WHERE tr.object_id IN ({$id_sql}) ORDER BY tr.object_id,t.slug"
            );
            if (is_wp_error($tag_rows)) return $tag_rows;
            foreach ($tag_rows as $tag) $tag_map[absint($tag['object_id'])][] = sanitize_title((string) $tag['slug']);

            $sem_rows = self::rows($mysqli,
                "SELECT ov.object_id,ov.source,ov.confidence,v.semantic_group,v.slug
                 FROM `{$t['object_vocabulary']}` ov
                 JOIN `{$t['vocabulary']}` v ON v.id=ov.vocabulary_id
                 WHERE ov.object_type='product' AND ov.status=1 AND v.active=1 AND ov.object_id IN ({$id_sql})
                 ORDER BY ov.object_id,v.semantic_group,v.slug"
            );
            if (is_wp_error($sem_rows)) return $sem_rows;
            foreach ($sem_rows as $sem) {
                $semantic_map[absint($sem['object_id'])][] = array(
                    'key' => array('semantic_group' => sanitize_key((string) $sem['semantic_group']), 'slug' => sanitize_title((string) $sem['slug'])),
                    'source' => (string) $sem['source'],
                    'confidence' => (string) $sem['confidence'],
                );
            }

            $attr_rows = self::rows($mysqli,
                "SELECT pa.id,pa.product_id,a.slug attribute_slug,at.slug term_slug,
                        pa.valor_texto,pa.valor_numero,pa.valor_numero_max,pa.unidad,pa.valor_original,pa.orden
                 FROM `{$t['product_attributes']}` pa
                 JOIN `{$t['attributes']}` a ON a.id=pa.atributo_id
                 LEFT JOIN `{$t['attribute_terms']}` at ON at.id=pa.termino_id
                 WHERE pa.product_id IN ({$id_sql})
                 ORDER BY pa.product_id,a.slug,pa.orden,pa.id"
            );
            if (is_wp_error($attr_rows)) return $attr_rows;
            foreach ($attr_rows as $attr) {
                $attribute_map[absint($attr['product_id'])][] = array(
                    'attribute_slug' => sanitize_key((string) $attr['attribute_slug']),
                    'term_slug' => sanitize_title((string) ($attr['term_slug'] ?? '')),
                    'value_text' => null === $attr['valor_texto'] ? null : (string) $attr['valor_texto'],
                    'value_number' => null === $attr['valor_numero'] ? null : (string) $attr['valor_numero'],
                    'value_number_max' => null === $attr['valor_numero_max'] ? null : (string) $attr['valor_numero_max'],
                    'unit' => null === $attr['unidad'] ? null : (string) $attr['unidad'],
                    'original_value' => null === $attr['valor_original'] ? null : (string) $attr['valor_original'],
                    'sort_order' => (int) $attr['orden'],
                    'audit' => array('source_id' => absint($attr['id'])),
                );
            }
        }

        $out = array();
        foreach ($rows as $row) {
            $id = absint($row['ID']);
            $tags = array_values(array_unique($tag_map[$id] ?? array()));
            sort($tags, SORT_STRING);
            $out[] = array(
                'identity' => array(
                    'catalog_uid' => trim((string) $row['catalog_uid']),
                    'provider' => trim((string) $row['provider']),
                    'external_id' => trim((string) $row['external_id']),
                    'sku' => trim((string) $row['sku']),
                    'slug' => sanitize_title((string) $row['post_name']),
                ),
                'display_name' => (string) $row['post_title'],
                'audit' => array('source_id' => $id),
                'product_tags' => $tags,
                'semantic' => array_values($semantic_map[$id] ?? array()),
                'attributes' => array_values($attribute_map[$id] ?? array()),
            );
        }
        usort($out, static function ($a, $b) {
            return strcmp(self::product_identity_string($a['identity'] ?? array()), self::product_identity_string($b['identity'] ?? array()));
        });
        return $out;
    }

    private static function category_path_slugs($term_id, $by_id) {
        $path = array();
        $seen = array();
        $current = absint($term_id);
        while ($current && isset($by_id[$current]) && empty($seen[$current])) {
            $seen[$current] = true;
            array_unshift($path, sanitize_title((string) $by_id[$current]['slug']));
            $current = absint($by_id[$current]['parent'] ?? 0);
        }
        return $path;
    }

    private static function build_remote_categories($mysqli, $t) {
        $rows = self::rows($mysqli,
            "SELECT trm.term_id,trm.name,trm.slug,tt.parent
             FROM `{$t['terms']}` trm JOIN `{$t['term_taxonomy']}` tt ON tt.term_id=trm.term_id
             WHERE tt.taxonomy='product_cat' ORDER BY trm.term_id"
        );
        if (is_wp_error($rows)) return $rows;
        $by_id = array();
        foreach ($rows as $row) $by_id[absint($row['term_id'])] = $row;
        $ids = array_keys($by_id);
        $semantic_map = array();
        $label_map = array();
        foreach (array_chunk($ids, 1500) as $chunk) {
            $id_sql = self::id_sql($chunk);
            $sem_rows = self::rows($mysqli,
                "SELECT ov.object_id,ov.source,ov.confidence,v.semantic_group,v.slug
                 FROM `{$t['object_vocabulary']}` ov
                 JOIN `{$t['vocabulary']}` v ON v.id=ov.vocabulary_id
                 WHERE ov.object_type='product_cat' AND ov.status=1 AND v.active=1 AND ov.object_id IN ({$id_sql})
                 ORDER BY ov.object_id,v.semantic_group,v.slug"
            );
            if (is_wp_error($sem_rows)) return $sem_rows;
            foreach ($sem_rows as $sem) {
                $semantic_map[absint($sem['object_id'])][] = array(
                    'key' => array('semantic_group' => sanitize_key((string) $sem['semantic_group']), 'slug' => sanitize_title((string) $sem['slug'])),
                    'source' => (string) $sem['source'],
                    'confidence' => (string) $sem['confidence'],
                );
            }
            $node_rows = self::rows($mysqli,
                "SELECT id,object_id,keywords,title,status,created_at,updated_at
                 FROM `{$t['nodes']}`
                 WHERE object_type='category' AND seo_role='category' AND object_id IN ({$id_sql})
                 ORDER BY object_id,id"
            );
            if (is_wp_error($node_rows)) return $node_rows;
            foreach ($node_rows as $node) {
                $label_map[absint($node['object_id'])] = array(
                    'keywords' => (string) $node['keywords'],
                    'title' => (string) $node['title'],
                    'status' => absint($node['status']) ? 1 : 0,
                    'audit' => array('source_id' => absint($node['id']), 'created_at' => (string) $node['created_at'], 'updated_at' => (string) $node['updated_at']),
                );
            }
        }
        $out = array();
        foreach ($rows as $row) {
            $id = absint($row['term_id']);
            $out[] = array(
                'identity' => array(
                    'taxonomy' => 'product_cat',
                    'slug' => sanitize_title((string) $row['slug']),
                    'path_slugs' => self::category_path_slugs($id, $by_id),
                ),
                'display_name' => (string) $row['name'],
                'audit' => array('source_term_id' => $id),
                'semantic' => array_values($semantic_map[$id] ?? array()),
                'category_labels' => $label_map[$id] ?? null,
            );
        }
        usort($out, static function ($a, $b) {
            return strcmp(implode('/', (array) ($a['identity']['path_slugs'] ?? array())), implode('/', (array) ($b['identity']['path_slugs'] ?? array())));
        });
        return $out;
    }

    private static function build_pro_document() {
        if (!function_exists('seo_environment_compare_open') || !function_exists('seo_environment_compare_db_prefix')) {
            return new WP_Error('semantic_direct_compare', 'El Comparador no tiene disponibles las conexiones de entorno.');
        }
        $mysqli = seo_environment_compare_open('pro');
        if (is_wp_error($mysqli)) return $mysqli;
        if (!($mysqli instanceof mysqli)) return new WP_Error('semantic_direct_connection', 'No se pudo abrir la conexion de lectura a PRO.');
        $prefix = seo_environment_compare_db_prefix('pro');
        $tables = self::required_remote_tables($prefix);
        try {
            $valid = self::validate_remote_tables($mysqli, $tables);
            if (is_wp_error($valid)) return $valid;
            $masters = self::build_remote_masters($mysqli, $tables);
            if (is_wp_error($masters)) return $masters;
            $products = self::build_remote_products($mysqli, $tables);
            if (is_wp_error($products)) return $products;
            $categories = self::build_remote_categories($mysqli, $tables);
            if (is_wp_error($categories)) return $categories;
            $settings = function_exists('seo_environment_db_settings') ? (array) seo_environment_db_settings('pro') : array();
            return SEO_Semantic_Catalog_Transfer::compose_portable_document(
                array('masters' => $masters, 'products' => $products, 'categories' => $categories),
                self::scopes(),
                'pro',
                (string) ($settings['last_site_url'] ?? '')
            );
        } finally {
            @mysqli_close($mysqli);
        }
    }

    private static function source_fingerprint($document) {
        return (string) ($document['manifest']['fingerprints']['content'] ?? '');
    }

    private static function preview_key() {
        return self::PREVIEW_PREFIX . get_current_user_id();
    }

    private static function invalidate_comparator_layers() {
        global $wpdb;
        $entities = array(
            'vocabulary_master', 'product_tag_master', 'attribute_master', 'attribute_term_master', 'attribute_alias_master',
            'product_tags', 'product_semantic', 'product_attributes', 'category_tags', 'category_semantic',
        );
        if (function_exists('seo_environment_compare_table')) {
            $table = seo_environment_compare_table();
            foreach ($entities as $entity) {
                $wpdb->delete($table, array('entity' => $entity), array('%s'));
            }
        }
        foreach ($entities as $entity) {
            delete_option('seo_environment_compare_state_' . $entity);
            delete_option('seo_environment_compare_stop_' . $entity);
        }
    }

    public static function ajax_preview() {
        $auth = self::authorize();
        if (is_wp_error($auth)) wp_send_json_error(array('message' => $auth->get_error_message()), 403);
        @set_time_limit(0);
        $document = self::build_pro_document();
        if (is_wp_error($document)) wp_send_json_error(array('message' => $document->get_error_message()), 500);
        $preview = SEO_Semantic_Catalog_Transfer::preview_portable_document($document, 'mirror', true);
        if (is_wp_error($preview)) wp_send_json_error(array('message' => $preview->get_error_message()), 500);
        set_transient(self::preview_key(), array(
            'source_fingerprint' => self::source_fingerprint($document),
            'plan_fingerprint' => (string) ($preview['plan_fingerprint'] ?? ''),
            'package_id' => (string) ($document['manifest']['package_id'] ?? ''),
            'previewed_at' => time(),
        ), self::PREVIEW_TTL);
        wp_send_json_success(array(
            'summary' => (array) ($preview['summary'] ?? array()),
            'conflicts' => array_slice((array) ($preview['conflicts'] ?? array()), 0, 50),
            'conflict_count' => count((array) ($preview['conflicts'] ?? array())),
            'counts' => (array) ($document['manifest']['counts'] ?? array()),
            'generated_at' => (string) ($document['manifest']['generated_at'] ?? ''),
            'can_apply' => empty($preview['conflicts']),
        ));
    }

    public static function ajax_apply() {
        $auth = self::authorize();
        if (is_wp_error($auth)) wp_send_json_error(array('message' => $auth->get_error_message()), 403);
        if (empty($_POST['confirm']) || '1' !== (string) $_POST['confirm']) {
            wp_send_json_error(array('message' => 'Falta la confirmacion explicita de alineacion.'), 400);
        }
        $saved = get_transient(self::preview_key());
        if (!is_array($saved)) {
            wp_send_json_error(array('message' => 'La simulacion ha caducado. Simula de nuevo antes de aplicar.'), 409);
        }
        @set_time_limit(0);
        ignore_user_abort(true);
        $document = self::build_pro_document();
        if (is_wp_error($document)) wp_send_json_error(array('message' => $document->get_error_message()), 500);
        $current_source = self::source_fingerprint($document);
        if (!$current_source || !hash_equals((string) ($saved['source_fingerprint'] ?? ''), $current_source)) {
            delete_transient(self::preview_key());
            wp_send_json_error(array('message' => 'PRO ha cambiado desde la simulacion. No se ha escrito nada; vuelve a simular.'), 409);
        }
        $result = SEO_Semantic_Catalog_Transfer::apply_portable_document($document, 'mirror', true, (string) ($saved['plan_fingerprint'] ?? ''));
        if (is_wp_error($result)) wp_send_json_error(array('message' => $result->get_error_message()), 500);
        delete_transient(self::preview_key());
        self::invalidate_comparator_layers();
        wp_send_json_success(array(
            'summary' => (array) ($result['summary'] ?? array()),
            'verified' => !empty($result['verified']),
            'verification' => (array) ($result['verification']['summary'] ?? array()),
            'message' => !empty($result['verified'])
                ? 'Catalogo semantico de STAGING alineado y verificado contra PRO.'
                : 'La escritura termino, pero la verificacion posterior aun detecta diferencias. Revisa antes de continuar.',
        ));
    }
}

SEO_Semantic_Catalog_Direct_Sync::init();
