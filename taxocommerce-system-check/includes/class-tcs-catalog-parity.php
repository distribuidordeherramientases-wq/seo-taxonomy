<?php
/**
 * Comparacion SOLO LECTURA: atributos y vocabulario propios frente al
 * almacenamiento oficial de WordPress / WooCommerce.
 *
 * La comparación recorre los productos por ID para evitar OFFSET masivos y
 * ejecuta lotes pequeños en admin-ajax, sin tocar catalogo ni cron.
 */
defined('ABSPATH') || exit;

final class TCS_Catalog_Parity {
    const STATE_KEY = '_tcs_catalog_parity_v1';
    const BATCH_SIZE = 100;
    const MAX_EXAMPLES = 200;

    public static function tables() {
        global $wpdb;
        $prefix = esc_sql((string)$wpdb->prefix);
        return array(
            'definitions' => $prefix . 'sql_atributos',
            'terms' => $prefix . 'sql_atributos_terminos',
            'aliases' => $prefix . 'sql_atributos_aliases',
            'values' => $prefix . 'sql_product_atributos',
            'vocabulary' => $prefix . 'seo_vocabulary',
            'object_vocab' => $prefix . 'seo_object_vocabulary',
            'wc_definitions' => $prefix . 'woocommerce_attribute_taxonomies'
        );
    }

    public static function exists($table) {
        global $wpdb;
        return $table === $wpdb->get_var($wpdb->prepare(
            'SHOW TABLES LIKE %s', $wpdb->esc_like($table)
        ));
    }

    private static function scalar($sql) {
        global $wpdb;
        $value = $wpdb->get_var($sql);
        return (int)$value;
    }

    public static function summary() {
        global $wpdb;
        $table = self::tables();
        $exists = array();
        foreach ($table as $key => $name) {
            $exists[$key] = self::exists($name);
        }

        $counts = array(
            'products_all' => self::scalar("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product'"),
            'products_published' => self::scalar("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"),
            'wc_product_tag_terms' => self::scalar("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'product_tag'"),
            'wc_tagged_products' => self::scalar(
                "SELECT COUNT(DISTINCT tr.object_id)
                 FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                 WHERE tt.taxonomy = 'product_tag' AND p.post_type = 'product'"
            ),
            'wc_product_attribute_taxonomies' => self::scalar("SELECT COUNT(DISTINCT taxonomy) FROM {$wpdb->term_taxonomy} WHERE LEFT(taxonomy,3) = 'pa_'"),
            'wc_product_attribute_terms' => self::scalar("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE LEFT(taxonomy,3) = 'pa_'"),
            'wc_attr_assigned_products' => self::scalar(
                "SELECT COUNT(DISTINCT tr.object_id)
                 FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                 WHERE LEFT(tt.taxonomy,3) = 'pa_' AND p.post_type = 'product'"
            ),
            'wc_attr_meta_products' => self::scalar(
                "SELECT COUNT(DISTINCT pm.post_id)
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id
                 WHERE pm.meta_key='_product_attributes' AND p.post_type='product'"
            )
        );

        foreach (array(
            'definitions' => 'custom_attribute_definitions',
            'terms' => 'custom_attribute_terms',
            'aliases' => 'custom_attribute_aliases',
            'values' => 'custom_product_attribute_rows',
            'vocabulary' => 'semantic_vocabulary_terms'
        ) as $key => $count_key) {
            $counts[$count_key] = $exists[$key] ? self::scalar("SELECT COUNT(*) FROM {$table[$key]}") : null;
        }
        $counts['wc_global_attribute_definitions'] = $exists['wc_definitions']
            ? self::scalar("SELECT COUNT(*) FROM {$table['wc_definitions']}") : null;
        $counts['custom_defs_without_matching_wc_global'] = $exists['wc_definitions'] && $exists['definitions']
            ? self::scalar(
                "SELECT COUNT(*) FROM {$table['definitions']} a
                 LEFT JOIN {$table['wc_definitions']} wc
                   ON wc.attribute_name = a.slug
                 WHERE a.activo=1 AND wc.attribute_id IS NULL"
            ) : null;
        $counts['custom_terms_without_matching_wc_term'] = $exists['definitions'] && $exists['terms']
            ? self::scalar(
                "SELECT COUNT(*) FROM {$table['terms']} source_term
                 INNER JOIN {$table['definitions']} attr ON attr.id=source_term.atributo_id
                 WHERE source_term.activo=1 AND attr.activo=1
                   AND NOT EXISTS (
                     SELECT 1 FROM {$wpdb->term_taxonomy} tt
                     INNER JOIN {$wpdb->terms} native_term ON native_term.term_id=tt.term_id
                     WHERE tt.taxonomy=CONCAT('pa_',attr.slug)
                       AND native_term.slug=source_term.slug
                   )"
            ) : null;
        $counts['custom_attr_assigned_products'] = $exists['values']
            ? self::scalar(
                "SELECT COUNT(DISTINCT a.product_id)
                 FROM {$table['values']} a
                 INNER JOIN {$wpdb->posts} p ON p.ID=a.product_id AND p.post_type='product'"
            ) : null;
        $counts['custom_attr_orphan_rows'] = $exists['values']
            ? self::scalar(
                "SELECT COUNT(*) FROM {$table['values']} a
                 LEFT JOIN {$wpdb->posts} p ON p.ID=a.product_id AND p.post_type='product'
                 WHERE p.ID IS NULL"
            ) : null;
        $counts['semantic_product_assignments'] = $exists['vocabulary'] && $exists['object_vocab']
            ? self::scalar(
                "SELECT COUNT(*) FROM {$table['object_vocab']} ov
                 INNER JOIN {$table['vocabulary']} v ON v.id=ov.vocabulary_id
                 INNER JOIN {$wpdb->posts} p ON p.ID=ov.object_id AND p.post_type='product'
                 WHERE ov.object_type='product' AND ov.status=1 AND v.active=1"
            ) : null;
        $counts['semantic_classified_products'] = $exists['vocabulary'] && $exists['object_vocab']
            ? self::scalar(
                "SELECT COUNT(DISTINCT ov.object_id) FROM {$table['object_vocab']} ov
                 INNER JOIN {$table['vocabulary']} v ON v.id=ov.vocabulary_id
                 INNER JOIN {$wpdb->posts} p ON p.ID=ov.object_id AND p.post_type='product'
                 WHERE ov.object_type='product' AND ov.status=1 AND v.active=1"
            ) : null;

        $warnings = array();
        foreach (array('definitions','terms','values') as $key) {
            if (!$exists[$key]) {
                $warnings[] = 'Falta la tabla de origen ' . $table[$key] . '.';
            }
        }
        if (!$exists['vocabulary'] || !$exists['object_vocab']) {
            $warnings[] = 'Faltan tablas de etiquetas semanticas; NO equivale a ausencia de product_tag.';
        }
        return array('tables' => $table, 'exists' => $exists, 'counts' => $counts, 'warnings' => $warnings);
    }

    public static function initial_state() {
        return array(
            'status' => 'running',
            'created_utc' => gmdate('Y-m-d H:i:s'),
            'last_id' => 0,
            'products_scanned' => 0,
            'source_rows' => 0,
            'attribute_matches' => 0,
            'attribute_missing' => 0,
            'attribute_value_mismatch' => 0,
            'attribute_terms_missing' => 0,
            'attribute_unresolvable' => 0,
            'native_extra_attributes' => 0,
            'semantic_rows' => 0,
            'native_product_tags' => 0,
            'semantic_tag_slug_overlap' => 0,
            'semantic_not_product_tag' => 0,
            'product_tag_not_semantic' => 0,
            'examples' => array(),
            'by_attribute' => array(),
            'error' => ''
        );
    }

    private static function ids($last_id) {
        global $wpdb;
        $table = self::tables();
        $sql = $wpdb->prepare(
            "SELECT DISTINCT av.product_id
             FROM {$table['values']} av
             INNER JOIN {$wpdb->posts} p ON p.ID = av.product_id AND p.post_type = 'product'
             WHERE av.product_id > %d
             ORDER BY av.product_id ASC LIMIT %d",
            absint($last_id), self::BATCH_SIZE
        );
        return array_values(array_filter(array_map('absint', (array)$wpdb->get_col($sql))));
    }

    private static function get_source($ids) {
        global $wpdb;
        $table = self::tables();
        $marks = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT av.product_id, a.slug, a.nombre, a.tipo, av.valor_original,
                    av.valor_texto, av.valor_numero, av.valor_numero_max,
                    av.unidad, av.termino_id, t.slug AS term_slug, t.nombre AS term_name
             FROM {$table['values']} av
             INNER JOIN {$table['definitions']} a ON a.id=av.atributo_id
             LEFT JOIN {$table['terms']} t ON t.id=av.termino_id
             WHERE av.product_id IN ({$marks})
             ORDER BY av.product_id, a.slug, av.id", $ids
        );
        return (array)$wpdb->get_results($sql, ARRAY_A);
    }

    private static function get_meta($ids) {
        global $wpdb;
        $marks = implode(',', array_fill(0, count($ids), '%d'));
        $rows = (array)$wpdb->get_results($wpdb->prepare(
            "SELECT pm.post_id,pm.meta_value FROM {$wpdb->postmeta} pm
             WHERE pm.meta_key='_product_attributes' AND pm.post_id IN ({$marks})
             ORDER BY pm.meta_id DESC", $ids
        ), ARRAY_A);
        $result = array();
        foreach ($rows as $row) {
            $id = (int)$row['post_id'];
            if (!isset($result[$id])) {
                $decoded = maybe_unserialize($row['meta_value']);
                $result[$id] = is_array($decoded) ? $decoded : array();
            }
        }
        return $result;
    }

    private static function get_terms($ids) {
        global $wpdb;
        $marks = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT tr.object_id, tt.taxonomy, t.slug, t.name
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
             INNER JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
             WHERE tr.object_id IN ({$marks})
               AND (LEFT(tt.taxonomy,3) = 'pa_' OR tt.taxonomy='product_tag')", $ids
        );
        $result = array();
        foreach ((array)$wpdb->get_results($sql, ARRAY_A) as $row) {
            $id = (int)$row['object_id'];
            $tax = (string)$row['taxonomy'];
            $result[$id][$tax][] = array('slug' => (string)$row['slug'], 'name' => (string)$row['name']);
        }
        return $result;
    }

    private static function get_semantics($ids) {
        global $wpdb;
        $tables = self::tables();
        if (!self::exists($tables['vocabulary']) || !self::exists($tables['object_vocab'])) {
            return array();
        }
        $marks = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT ov.object_id, v.slug FROM {$tables['object_vocab']} ov
             INNER JOIN {$tables['vocabulary']} v ON v.id=ov.vocabulary_id
             WHERE ov.object_type='product' AND ov.status=1 AND v.active=1
               AND ov.object_id IN ({$marks})", $ids
        );
        $out = array();
        foreach ((array)$wpdb->get_results($sql, ARRAY_A) as $row) {
            $out[(int)$row['object_id']][] = sanitize_title($row['slug']);
        }
        return $out;
    }

    private static function norm($value) {
        $text = trim((string)$value);
        return sanitize_title(wp_strip_all_tags($text));
    }

    private static function native_attributes($meta) {
        $attributes = array();
        foreach ((array)$meta as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            $name = isset($value['name']) ? (string)$value['name'] : (string)$key;
            $slug = preg_replace('/^pa_/', '', sanitize_title($name));
            if (!$slug) {
                continue;
            }
            // Prefer a canonical taxonomy key over the display name.
            $attributes[$slug] = array(
                'name' => $name,
                'taxonomy' => !empty($value['is_taxonomy']),
                'value' => isset($value['value']) ? (string)$value['value'] : ''
            );
        }
        return $attributes;
    }

    private static function compare($source, $native, $all_terms) {
        $slug = self::norm($source['slug']);
        $name = self::norm($source['nombre']);
        $attribute = isset($native[$slug]) ? $native[$slug]
            : (isset($native[$name]) ? $native[$name] : null);
        if (!$attribute) {
            return array('state' => 'attribute_missing', 'native' => '');
        }
        $expected = trim((string)$source['term_slug']);
        if ($expected === '') {
            $expected = trim((string)$source['valor_original']);
        }
        if ($expected === '') {
            $expected = trim((string)$source['valor_texto']);
        }
        if ($expected === '' && $source['valor_numero'] !== null) {
            $expected = (string)$source['valor_numero'] .
                (trim((string)$source['unidad']) ? ' ' . trim((string)$source['unidad']) : '');
        }
        if ($expected === '') {
            return array('state' => 'attribute_unresolvable', 'native' => '');
        }

        if ($attribute['taxonomy']) {
            $tax = $attribute['name'];
            $values = isset($all_terms[$tax]) ? $all_terms[$tax] : array();
            $value_names = array();
            foreach ($values as $term) {
                $value_names[] = $term['slug'];
                $value_names[] = $term['name'];
            }
            if (!$values) {
                return array('state' => 'attribute_terms_missing', 'native' => '');
            }
            $native_values = implode(' | ', array_unique($value_names));
        } else {
            $native_values = (string)$attribute['value'];
            $value_names = array_map('trim', explode('|', $native_values));
        }
        $candidates = array(self::norm($expected));
        if (!empty($source['term_name'])) {
            $candidates[] = self::norm($source['term_name']);
        }
        if (!empty($source['valor_original'])) {
            $candidates[] = self::norm($source['valor_original']);
        }
        $candidates = array_values(array_unique(array_filter($candidates)));
        foreach ($value_names as $value) {
            if (in_array(self::norm($value), $candidates, true)) {
                return array('state' => 'attribute_matches', 'native' => $native_values);
            }
        }
        return array('state' => 'attribute_value_mismatch', 'native' => $native_values);
    }

    private static function example(&$state, $row, $result) {
        if (count($state['examples']) >= self::MAX_EXAMPLES) {
            return;
        }
        $state['examples'][] = array(
            'product_id' => (int)$row['product_id'],
            'attribute' => (string)$row['slug'],
            'source_value' => (string)($row['valor_original'] ?: $row['term_name'] ?: $row['valor_texto'] ?: $row['valor_numero']),
            'native_value' => (string)$result['native'],
            'status' => (string)$result['state']
        );
    }

    public static function step($state) {
        global $wpdb;
        if (!is_array($state) || $state['status'] !== 'running') {
            return new WP_Error('tcs_state', 'La auditoria no esta iniciada.');
        }
        $tables = self::tables();
        foreach (array('values','definitions','terms') as $key) {
            if (!self::exists($tables[$key])) {
                return new WP_Error('tcs_table', 'Falta la tabla propia ' . $tables[$key] . '.');
            }
        }
        $ids = self::ids($state['last_id']);
        if ($wpdb->last_error) {
            return new WP_Error('tcs_sql', 'Error SQL al obtener productos: ' . $wpdb->last_error);
        }
        if (!$ids) {
            $state['status'] = 'complete';
            $state['finished_utc'] = gmdate('Y-m-d H:i:s');
            return $state;
        }

        $source = self::get_source($ids);
        $meta = self::get_meta($ids);
        $terms = self::get_terms($ids);
        $semantic = self::get_semantics($ids);
        if ($wpdb->last_error) {
            return new WP_Error('tcs_sql', 'Error SQL comparando el lote: ' . $wpdb->last_error);
        }

        $by_product = array();
        foreach ($source as $row) {
            $by_product[(int)$row['product_id']][] = $row;
        }
        foreach ($ids as $id) {
            $state['products_scanned']++;
            $native = self::native_attributes(isset($meta[$id]) ? $meta[$id] : array());
            $source_slugs = array();
            foreach (isset($by_product[$id]) ? $by_product[$id] : array() as $row) {
                $state['source_rows']++;
                $slug = self::norm($row['slug']);
                $source_slugs[$slug] = true;
                $source_slugs[self::norm($row['nombre'])] = true;
                $comparison = self::compare(
                    $row, $native, isset($terms[$id]) ? $terms[$id] : array()
                );
                $kind = $comparison['state'];
                $state[$kind]++;
                if (!isset($state['by_attribute'][$slug])) {
                    $state['by_attribute'][$slug] = array('total'=>0, 'matched'=>0, 'missing'=>0, 'mismatch'=>0);
                }
                $state['by_attribute'][$slug]['total']++;
                if ($kind === 'attribute_matches') {
                    $state['by_attribute'][$slug]['matched']++;
                } elseif ($kind === 'attribute_missing') {
                    $state['by_attribute'][$slug]['missing']++;
                } else {
                    $state['by_attribute'][$slug]['mismatch']++;
                }
                if ($kind !== 'attribute_matches') {
                    self::example($state, $row, $comparison);
                }
            }
            foreach ($native as $slug => $definition) {
                if (!isset($source_slugs[$slug])) {
                    $state['native_extra_attributes']++;
                }
            }

            // Etiquetas semanticas y product_tag son sistemas diferentes.
            // El solapamiento por slug es informativo, NO una prueba 1:1.
            $sem = array_values(array_unique(isset($semantic[$id]) ? $semantic[$id] : array()));
            $tags = array();
            foreach (isset($terms[$id]['product_tag']) ? $terms[$id]['product_tag'] : array() as $term) {
                $tags[] = self::norm($term['slug']);
            }
            $tags = array_values(array_unique($tags));
            $overlap = array_intersect($sem, $tags);
            $state['semantic_rows'] += count($sem);
            $state['native_product_tags'] += count($tags);
            $state['semantic_tag_slug_overlap'] += count($overlap);
            $state['semantic_not_product_tag'] += count(array_diff($sem, $tags));
            $state['product_tag_not_semantic'] += count(array_diff($tags, $sem));
        }
        $state['last_id'] = (int)end($ids);
        // One extra request checks whether more products remain.
        return $state;
    }
}
