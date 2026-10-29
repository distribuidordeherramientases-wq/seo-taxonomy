<?php
defined('ABSPATH') || exit;

/**
 * Compatibility with the original Linguist L9 curriculum.
 * The historic L9 used monolithic V3 Academy snapshots. This adapter reads
 * the independent TCD Academy L9 snapshot; it never reads product answers
 * from WooCommerce or inserts a product ID into a linguistic test.
 */
final class TCD_Original_L9_Sources {
    public static function source_total() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter adapter queries plugin-managed tables for live runtime analysis; caching could reuse stale learning state.
        return absint($wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE lesson=%d AND status=%s',
            TCD_Academy::knowledge_table(), 9, 'learned'
        )));
    }

    public static function source_batch($offset, $limit) {
        global $wpdb;
        $offset = max(0, absint($offset));
        $limit = min(80, max(1, absint($limit)));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter adapter queries plugin-managed tables for live runtime analysis; caching could reuse stale learning state.
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT id,source_id,title,context_json FROM %i WHERE lesson=%d AND status=%s ORDER BY id ASC LIMIT %d OFFSET %d',
            TCD_Academy::knowledge_table(), 9, 'learned', $limit, $offset
        ), ARRAY_A);
        $out = array();
        foreach ($rows as $row) {
            $facts = json_decode((string) ($row['context_json'] ?? ''), true);
            $facts = is_array($facts) ? $facts : array();
            $title = (string) ($row['title'] ?? '');
            $need = TCD_Sources::customer_need_from_facts($facts, $title);
            $expected = array();
            $conditions = array();
            foreach (array('tipo', 'subtipo', 'aplicacion', 'plataforma', 'rol') as $group) {
                foreach (array_slice((array) ($facts['vocabulary'][$group] ?? array()), 0, 1) as $concept) {
                    if (!is_array($concept)) continue;
                    $value = (string) ($concept['slug'] ?? $concept['label'] ?? $concept['name'] ?? '');
                    $slug = sanitize_title($value);
                    if ($slug) $conditions[$group] = array($slug);
                }
            }
            if ($conditions) {
                $expected = array('kind' => 'vocabulary', 'conditions' => $conditions);
            } else {
                $categories = (array) ($facts['categories_detail'] ?? array());
                foreach ($categories as $term) {
                    if (!is_array($term)) continue;
                    $name = (string) ($term['name'] ?? '');
                    if ($name && 'nuevos-productos' !== sanitize_title($name)) {
                        $expected = array('kind' => 'category', 'category_name' => $name);
                        break;
                    }
                }
            }
            $out[] = array(
                'source_id' => absint($row['source_id'] ?? 0),
                'source_key' => 'tcd-l9-' . absint($row['id'] ?? 0),
                'question' => (string) ($need['query'] ?? ''),
                'expected' => $expected,
            );
        }
        return $out;
    }
}

/**
 * Adapt the original Interpreter's own linguistic output to the ORIGINAL L9
 * regression contract. This adapter does not search for products.
 */
final class TCD_Original_L9_Interpreter {
    public static function interpret($query) {
        $result = TCD_Original_Interprete::interpret($query);
        $groups = array();
        $concepts = array();
        $semantic = (array) ($result['structured']['semantic_groups'] ?? array());
        foreach ($semantic as $role => $rows) {
            $role = sanitize_key((string) $role);
            foreach ((array) $rows as $row) {
                if (!is_array($row)) continue;
                $value = (string) ($row['target'] ?? $row['term'] ?? '');
                if (!$value) continue;
                $concepts[$role][] = $value;
                $groups[] = array(
                    'source' => 'linguista', 'role' => $role,
                    'canonical' => $value, 'variants' => array((string) ($row['term'] ?? '')),
                );
            }
        }
        foreach ((array) ($result['structured']['context'] ?? array()) as $token) {
            $token = sanitize_text_field((string) $token);
            if (!$token) continue;
            $groups[] = array(
                'source' => 'literal', 'role' => 'term',
                'canonical' => $token, 'variants' => array(),
            );
        }
        $result['groups'] = $groups;
        $result['concepts'] = $concepts;
        return $result;
    }
}
