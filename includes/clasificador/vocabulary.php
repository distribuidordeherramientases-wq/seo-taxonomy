<?php
/**
 * Acceso de solo lectura a vocabularios canónicos para el Clasificador.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_classifier_table_exists')) {
    function seo_classifier_table_exists($table) {
        global $wpdb;
        $table = (string) $table;
        if ($table === '') return false;
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        return is_string($found) && strcasecmp($found, $table) === 0;
    }
}

if (!function_exists('seo_classifier_vocabulary_index')) {
    function seo_classifier_vocabulary_index() {
        static $cache = null;
        if (is_array($cache)) return $cache;

        global $wpdb;
        $table = $wpdb->prefix . 'seo_vocabulary';
        $cache = array_fill_keys(seo_classifier_allowed_label_groups(), []);
        if (!seo_classifier_table_exists($table)) return $cache;

        $rows = $wpdb->get_results(
            "SELECT id,semantic_group,slug,label,parent_id,source
             FROM {$table}
             WHERE active=1 AND semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
             ORDER BY semantic_group,label,slug",
            ARRAY_A
        );
        foreach ((array) $rows as $row) {
            $group = sanitize_key((string) ($row['semantic_group'] ?? ''));
            if (!isset($cache[$group])) continue;
            $row['id'] = (int) ($row['id'] ?? 0);
            $row['norm'] = seo_classifier_normalize((string) ($row['label'] ?? ''));
            $row['concepts'] = seo_classifier_concept_sequence((string) ($row['label'] ?? ''));
            $cache[$group][] = $row;
        }
        return $cache;
    }
}

if (!function_exists('seo_classifier_role_from_type')) {
    function seo_classifier_role_from_type($type_id) {
        $type_id = absint($type_id);
        if ($type_id < 1) return null;
        if (function_exists('seo_catalog_get_role_for_type_vocabulary')) {
            $row = seo_catalog_get_role_for_type_vocabulary($type_id);
            if (is_array($row) && !empty($row['id'])) {
                return [
                    'id'=>(int)$row['id'],
                    'label'=>(string)($row['label'] ?? ''),
                    'slug'=>(string)($row['slug'] ?? ''),
                ];
            }
        }
        return null;
    }
}

if (!function_exists('seo_classifier_attribute_catalog')) {
    function seo_classifier_attribute_catalog() {
        static $cache = null;
        if (is_array($cache)) return $cache;
        $cache = function_exists('seo_attributes_get_catalog') ? (array) seo_attributes_get_catalog(true) : [];
        return $cache;
    }
}

if (!function_exists('seo_classifier_attribute_alias_index')) {
    function seo_classifier_attribute_alias_index() {
        static $cache = null;
        if (is_array($cache)) return $cache;
        $cache = [];
        if (!function_exists('seo_attributes_tables')) return $cache;

        global $wpdb;
        $tables = seo_attributes_tables();
        if (empty($tables['aliases']) || !seo_classifier_table_exists($tables['aliases'])) return $cache;

        $rows = $wpdb->get_results(
            "SELECT atributo_id,termino_id,alias FROM `{$tables['aliases']}` ORDER BY atributo_id,termino_id,id",
            ARRAY_A
        );
        foreach ((array) $rows as $row) {
            $attribute_id = (int) ($row['atributo_id'] ?? 0);
            $term_id = (int) ($row['termino_id'] ?? 0);
            $alias = trim((string) ($row['alias'] ?? ''));
            if ($attribute_id > 0 && $term_id > 0 && $alias !== '') {
                $cache[$attribute_id][$term_id][] = $alias;
            }
        }
        return $cache;
    }
}
