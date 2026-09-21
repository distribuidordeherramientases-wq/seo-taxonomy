<?php

defined('ABSPATH') || exit;

/**
 * Academia · Lección 10 — Consolidación, deuda y regresión.
 *
 * L10 no inventa conocimiento nuevo. Reexamina la deuda real que quedó abierta
 * en L1-L9 usando la verdad esperada original, pero antes descarta ejercicios
 * cuya fuente ya no existe o cuya evidencia concreta ha cambiado. Así una
 * corrección de catálogo no se convierte en una contradicción histórica.
 *
 * Cada pregunta se ejecuta contra el mismo runtime con el que fue concebida:
 * L9 se revalida contra Dependiente V3; L1-L8 conservan el runtime académico
 * legacy porque sus contratos incluyen FAQ/editorial/relaciones que V3 público
 * no devuelve en el endpoint de productos.
 */
final class SEO_Dependiente_V3_Lesson10 {
    const VERSION = '2026-09-21.1';
    const LESSON_KEY = 'v2_l10_consolidation_debt';

    private static $items = null;
    private static $stats = null;
    private static $index_cache = array();
    private static $vocabulary_map = null;
    private static $vocabulary_label_map = array();
    private static $faq_cache = array();
    private static $post_cache = array();
    private static $object_vocabulary_cache = array();

    public static function source_total() {
        return count(self::debt_items());
    }

    public static function source_batch($offset, $limit) {
        return array_slice(
            self::debt_items(),
            max(0, absint($offset)),
            min(250, max(1, absint($limit)))
        );
    }

    public static function stats() {
        self::debt_items();
        return is_array(self::$stats) ? self::$stats : array();
    }

    private static function debt_items() {
        if (null !== self::$items) {
            return self::$items;
        }

        self::$items = array();
        self::$stats = array(
            'historical_failed_rows'       => 0,
            'excluded_curriculum_invalid'  => 0,
            'excluded_stale_source'        => 0,
            'duplicates_removed'           => 0,
            'eligible_debt'                => 0,
            'by_origin_lesson'             => array(),
        );

        if (!class_exists('SEO_Dependiente_Entrenador')) {
            return self::$items;
        }

        global $wpdb;
        $questions = SEO_Dependiente_Entrenador::questions_table();
        $runs = SEO_Dependiente_Entrenador::runs_table();
        $lesson_keys = array(
            'v2_l1_categories',
            'v2_l2_inventory',
            'v2_l3_type_role',
            'v2_l4_features',
            'v2_l5_editorial',
            'v2_l6_faq',
            'v2_l7_cross',
            'v2_l8_exam',
            'v2_l9_product_language',
        );
        $quoted = implode(',', array_fill(0, count($lesson_keys), '%s'));

        // Una sola deuda por pregunta: se toma exclusivamente la ejecución más
        // reciente registrada para esa pregunta. Si posteriormente se aprobó,
        // deja de formar parte de L10.
        $sql = "SELECT q.id AS origin_question_id,
                       q.lesson_key AS origin_lesson,
                       q.lesson_order AS origin_lesson_order,
                       q.source_type AS origin_source_type,
                       q.source_id AS origin_source_id,
                       q.source_key AS origin_source_key,
                       q.question_type AS origin_question_type,
                       q.mode,
                       q.question,
                       q.expected_json,
                       r.id AS origin_run_id,
                       r.evaluation_json,
                       r.created_at AS origin_run_created_at
                  FROM {$questions} q
                  INNER JOIN (
                        SELECT question_id, MAX(id) AS latest_run_id
                          FROM {$runs}
                         WHERE lesson_key IN ({$quoted})
                         GROUP BY question_id
                  ) latest ON latest.question_id=q.id
                  INNER JOIN {$runs} r ON r.id=latest.latest_run_id
                 WHERE q.lesson_key IN ({$quoted})
                   AND q.enabled=1
                   AND r.status='answered'
                   AND r.evaluation_status='fail'
                 ORDER BY q.lesson_order ASC,q.id ASC";
        $params = array_merge($lesson_keys, $lesson_keys);
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        self::$stats['historical_failed_rows'] = count($rows);

        $prepared_rows = array();
        foreach ($rows as $row) {
            $evaluation = self::decode_json($row['evaluation_json'] ?? '');
            $diagnostic = sanitize_key((string) ($evaluation['diagnostic_type'] ?? ''));
            if ('curriculum_invalid' === $diagnostic) {
                self::$stats['excluded_curriculum_invalid']++;
                continue;
            }
            $expected = self::decode_json($row['expected_json'] ?? '');
            if (!$expected) {
                self::$stats['excluded_stale_source']++;
                continue;
            }
            $row['_l10_expected'] = $expected;
            $row['_l10_diagnostic'] = $diagnostic;
            $prepared_rows[] = $row;
        }

        self::prime_caches($prepared_rows);

        $seen = array();
        foreach ($prepared_rows as $row) {
            $expected = (array) ($row['_l10_expected'] ?? array());
            $diagnostic = sanitize_key((string) ($row['_l10_diagnostic'] ?? ''));
            if (!self::expected_is_current($expected)) {
                self::$stats['excluded_stale_source']++;
                continue;
            }

            $normalized_question = self::normalize((string) ($row['question'] ?? ''));
            $dedupe_key = hash('sha256', $normalized_question . '|' . wp_json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (isset($seen[$dedupe_key])) {
                self::$stats['duplicates_removed']++;
                continue;
            }
            $seen[$dedupe_key] = true;

            $origin_lesson = sanitize_key((string) ($row['origin_lesson'] ?? ''));
            $academy = is_array($expected['academy'] ?? null) ? $expected['academy'] : array();
            $expected['academy'] = array_merge($academy, array(
                'lesson'                => self::LESSON_KEY,
                'historical_debt'       => true,
                'origin_lesson'         => $origin_lesson,
                'origin_question_id'    => absint($row['origin_question_id'] ?? 0),
                'origin_run_id'         => absint($row['origin_run_id'] ?? 0),
                'origin_diagnostic'     => $diagnostic,
                'origin_run_created_at' => sanitize_text_field((string) ($row['origin_run_created_at'] ?? '')),
            ));

            $origin_question_id = absint($row['origin_question_id'] ?? 0);
            $origin_type = sanitize_key((string) ($row['origin_question_type'] ?? 'other'));
            self::$items[] = array(
                'source_type'   => 'historical_debt',
                'source_id'     => $origin_question_id,
                'source_key'    => 'debt:' . $origin_lesson . ':' . $origin_question_id,
                'question_type' => self::short_key('debt_' . $origin_type, 40),
                'mode'          => self::sanitize_mode($row['mode'] ?? 'need'),
                'question'      => (string) ($row['question'] ?? ''),
                'expected'      => $expected,
                // L10 revalida conocimiento ya aprendido. Reinsertar las mismas
                // reglas con otra lesson_key solo duplicaría conocimiento.
                'rules'         => array(),
            );

            if (!isset(self::$stats['by_origin_lesson'][$origin_lesson])) {
                self::$stats['by_origin_lesson'][$origin_lesson] = 0;
            }
            self::$stats['by_origin_lesson'][$origin_lesson]++;
        }

        self::$stats['eligible_debt'] = count(self::$items);
        return self::$items;
    }

    /**
     * Verifica que la verdad original siga respaldada por las fuentes actuales.
     * Si un atributo, FAQ, producto o relación fue corregido/eliminado, la vieja
     * pregunta no debe entrenar una contradicción.
     */
    private static function expected_is_current($expected) {
        $kind = sanitize_key((string) ($expected['kind'] ?? ''));
        if (!$kind) {
            return false;
        }

        if ('product' === $kind) {
            return (bool) self::index_row(absint($expected['product_id'] ?? 0));
        }

        if ('features' === $kind) {
            $product_id = absint($expected['source_product_id'] ?? 0);
            $row = self::index_row($product_id);
            if (!$row) {
                return false;
            }
            $features = (array) ($expected['features'] ?? array());
            if (!$features) {
                return false;
            }
            foreach ($features as $feature) {
                if (!self::row_matches_feature($row, (array) $feature)) {
                    return false;
                }
            }
            return true;
        }

        if ('category' === $kind) {
            $category_id = absint($expected['category_id'] ?? 0);
            if (!$category_id) {
                return false;
            }
            $term = get_term($category_id, 'product_cat');
            return $term && !is_wp_error($term);
        }

        if ('vocabulary' === $kind) {
            return self::vocabulary_conditions_exist((array) ($expected['conditions'] ?? array()));
        }

        if ('faq' === $kind) {
            $faq_id = absint($expected['faq_id'] ?? 0);
            $row = $faq_id && isset(self::$faq_cache[$faq_id]) ? (array) self::$faq_cache[$faq_id] : array();
            if (!$row || empty($row['active'])) {
                return false;
            }
            $owner_type = absint($expected['owner_type'] ?? 0);
            $owner_id = absint($expected['owner_id'] ?? 0);
            return (!$owner_type || $owner_type === absint($row['object_type'] ?? 0))
                && (!$owner_id || $owner_id === absint($row['object_id'] ?? 0));
        }

        if ('content' === $kind) {
            return self::content_relation_is_current(
                (array) ($expected['acceptable_related'] ?? array()),
                (array) ($expected['labels'] ?? array())
            );
        }

        if ('cross' === $kind) {
            $conditions = (array) ($expected['conditions'] ?? array());
            return self::vocabulary_conditions_exist($conditions)
                && self::cross_relation_is_current((array) ($expected['acceptable_related'] ?? array()), $conditions);
        }

        return false;
    }

    private static function prime_caches($rows) {
        global $wpdb;
        $product_ids = array();
        $faq_ids = array();
        $post_ids = array();

        foreach ((array) $rows as $row) {
            $expected = (array) ($row['_l10_expected'] ?? array());
            $kind = sanitize_key((string) ($expected['kind'] ?? ''));
            if ('product' === $kind) {
                $product_ids[] = absint($expected['product_id'] ?? 0);
            } elseif ('features' === $kind) {
                $product_ids[] = absint($expected['source_product_id'] ?? 0);
            } elseif ('faq' === $kind) {
                $faq_ids[] = absint($expected['faq_id'] ?? 0);
            } elseif (in_array($kind, array('content', 'cross'), true)) {
                foreach ((array) ($expected['acceptable_related'] ?? array()) as $item) {
                    if (is_array($item)) {
                        $post_ids[] = absint($item['id'] ?? 0);
                    }
                }
            }
        }

        $product_ids = array_values(array_unique(array_filter($product_ids)));
        if ($product_ids && class_exists('SEO_Dependiente_Index') && SEO_Dependiente_Index::table_exists()) {
            foreach (array_chunk($product_ids, 1000) as $chunk) {
                foreach ((array) SEO_Dependiente_Index::get_rows_by_ids($chunk, count($chunk)) as $raw) {
                    $decoded = SEO_Dependiente_Index::decode_row($raw);
                    $id = absint($decoded['product_id'] ?? 0);
                    if ($id) {
                        self::$index_cache[$id] = $decoded;
                    }
                }
            }
        }

        self::$vocabulary_map = array();
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        if (self::table_exists($vocabulary)) {
            $terms = (array) $wpdb->get_results(
                "SELECT semantic_group,slug FROM {$vocabulary} WHERE active=1",
                ARRAY_A
            );
            foreach ($terms as $term) {
                $group = sanitize_key((string) ($term['semantic_group'] ?? ''));
                $slug = sanitize_title((string) ($term['slug'] ?? ''));
                if ($group && $slug) {
                    self::$vocabulary_map[$group . '|' . $slug] = true;
                }
            }
            $term_labels = (array) $wpdb->get_results(
                "SELECT semantic_group,slug,label FROM {$vocabulary} WHERE active=1",
                ARRAY_A
            );
            foreach ($term_labels as $term) {
                $group = sanitize_key((string) ($term['semantic_group'] ?? ''));
                $slug = sanitize_title((string) ($term['slug'] ?? ''));
                $label = self::normalize((string) ($term['label'] ?? ''));
                if ($group && $slug && $label) {
                    if (!isset(self::$vocabulary_label_map[$label])) {
                        self::$vocabulary_label_map[$label] = array();
                    }
                    self::$vocabulary_label_map[$label][$group . '|' . $slug] = true;
                }
            }
        }

        $faq_ids = array_values(array_unique(array_filter($faq_ids)));
        $faq_table = $wpdb->prefix . 'seo_faq';
        if ($faq_ids && self::table_exists($faq_table)) {
            foreach (array_chunk($faq_ids, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
                $sql = $wpdb->prepare(
                    "SELECT id,object_type,object_id,active FROM {$faq_table} WHERE id IN ({$placeholders})",
                    $chunk
                );
                foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $faq) {
                    self::$faq_cache[absint($faq['id'] ?? 0)] = $faq;
                }
            }
        }

        $post_ids = array_values(array_unique(array_filter($post_ids)));
        if ($post_ids) {
            foreach (array_chunk($post_ids, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
                $sql = $wpdb->prepare(
                    "SELECT ID,post_type,post_status FROM {$wpdb->posts} WHERE ID IN ({$placeholders})",
                    $chunk
                );
                foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $post) {
                    self::$post_cache[absint($post['ID'] ?? 0)] = $post;
                }
            }

            $objects = $wpdb->prefix . 'seo_object_vocabulary';
            if (self::table_exists($objects) && self::table_exists($vocabulary)) {
                foreach (array_chunk($post_ids, 500) as $chunk) {
                    $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
                    $sql = $wpdb->prepare(
                        "SELECT ov.object_id,v.semantic_group,v.slug
                           FROM {$objects} ov
                           INNER JOIN {$vocabulary} v ON v.id=ov.vocabulary_id AND v.active=1
                          WHERE ov.status=1
                            AND ov.object_type IN ('post','page')
                            AND ov.object_id IN ({$placeholders})",
                        $chunk
                    );
                    foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $assignment) {
                        $id = absint($assignment['object_id'] ?? 0);
                        $group = sanitize_key((string) ($assignment['semantic_group'] ?? ''));
                        $slug = sanitize_title((string) ($assignment['slug'] ?? ''));
                        if ($id && $group && $slug) {
                            if (!isset(self::$object_vocabulary_cache[$id])) {
                                self::$object_vocabulary_cache[$id] = array();
                            }
                            self::$object_vocabulary_cache[$id][$group . '|' . $slug] = true;
                        }
                    }
                }
            }
        }
    }

    private static function index_row($product_id) {
        $product_id = absint($product_id);
        return $product_id && isset(self::$index_cache[$product_id])
            ? (array) self::$index_cache[$product_id]
            : array();
    }

    private static function row_matches_feature($row, $feature) {
        $kind = sanitize_key((string) ($feature['kind'] ?? ''));
        if ('vocabulary' === $kind) {
            $group = sanitize_key((string) ($feature['group'] ?? ''));
            $slug = sanitize_title((string) ($feature['slug'] ?? ''));
            if (!$group || !$slug || !self::vocabulary_conditions_exist(array($group => array($slug)))) {
                return false;
            }
            // ROL puede derivarse del bridge TIPO→ROL y no estar materializado en
            // vocabulary_json. La existencia canónica del término basta aquí.
            if ('rol' === $group) {
                return true;
            }
            foreach ((array) ($row['vocabulary'][$group] ?? array()) as $term) {
                $actual = sanitize_title((string) ($term['slug'] ?? $term['label'] ?? ''));
                if ($actual === $slug) {
                    return true;
                }
            }
            return false;
        }

        if ('tag' === $kind) {
            $wanted = sanitize_title((string) ($feature['slug'] ?? $feature['label'] ?? ''));
            foreach ((array) ($row['tags'] ?? array()) as $tag) {
                $actual = is_array($tag)
                    ? sanitize_title((string) ($tag['slug'] ?? $tag['name'] ?? ''))
                    : sanitize_title((string) $tag);
                if ($wanted && $actual === $wanted) {
                    return true;
                }
            }
            return false;
        }

        if ('attribute' === $kind) {
            $wanted_key = self::normalize((string) ($feature['key'] ?? $feature['label'] ?? ''));
            $wanted_label = self::normalize((string) ($feature['label'] ?? ''));
            $wanted_value = self::normalize((string) ($feature['value'] ?? ''));
            foreach ((array) ($row['attributes'] ?? array()) as $attribute) {
                $actual_key = self::normalize((string) ($attribute['key'] ?? ''));
                $actual_label = self::normalize((string) ($attribute['label'] ?? ''));
                if ($wanted_key !== $actual_key && $wanted_label !== $actual_label) {
                    continue;
                }
                foreach ((array) ($attribute['values'] ?? array()) as $value) {
                    if ($wanted_value && self::normalize((string) $value) === $wanted_value) {
                        return true;
                    }
                }
            }
            return false;
        }

        return false;
    }

    private static function vocabulary_conditions_exist($conditions) {
        if (!is_array(self::$vocabulary_map) || !$conditions) {
            return false;
        }
        foreach ($conditions as $group => $slugs) {
            $group = sanitize_key((string) $group);
            $wanted = array_values(array_unique(array_filter(array_map('sanitize_title', (array) $slugs))));
            if (!$group || !$wanted) {
                return false;
            }
            $found = false;
            foreach ($wanted as $slug) {
                if (!empty(self::$vocabulary_map[$group . '|' . $slug])) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }
        return true;
    }

    private static function content_relation_is_current($items, $labels) {
        $labels = array_values(array_unique(array_filter(array_map(array(__CLASS__, 'normalize'), (array) $labels))));
        if (!$labels) {
            return self::has_live_related($items);
        }
        foreach ((array) $items as $item) {
            if (!self::related_item_is_live($item)) {
                continue;
            }
            $id = absint($item['id'] ?? 0);
            $assigned = (array) (self::$object_vocabulary_cache[$id] ?? array());
            if (!$assigned) {
                continue;
            }
            $all = true;
            foreach ($labels as $label) {
                $candidates = (array) (self::$vocabulary_label_map[$label] ?? array());
                if (!$candidates || !array_intersect_key($candidates, $assigned)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true;
            }
        }
        return false;
    }

    private static function cross_relation_is_current($items, $conditions) {
        foreach ((array) $items as $item) {
            if (!self::related_item_is_live($item)) {
                continue;
            }
            $id = absint($item['id'] ?? 0);
            $assigned = (array) (self::$object_vocabulary_cache[$id] ?? array());
            if (!$assigned) {
                continue;
            }
            $all = true;
            foreach ((array) $conditions as $group => $slugs) {
                $group = sanitize_key((string) $group);
                $matched = false;
                foreach ((array) $slugs as $slug) {
                    $key = $group . '|' . sanitize_title((string) $slug);
                    if (!empty($assigned[$key])) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true;
            }
        }
        return false;
    }

    private static function related_item_is_live($item) {
        if (!is_array($item)) {
            return false;
        }
        $id = absint($item['id'] ?? 0);
        $type = sanitize_key((string) ($item['type'] ?? ''));
        $post = $id && isset(self::$post_cache[$id]) ? (array) self::$post_cache[$id] : array();
        if (!$post || 'publish' !== (string) ($post['post_status'] ?? '')) {
            return false;
        }
        return ('post' === $type && 'post' === (string) ($post['post_type'] ?? ''))
            || ('landing' === $type && 'page' === (string) ($post['post_type'] ?? ''));
    }

    private static function has_live_related($items) {
        foreach ((array) $items as $item) {
            if (self::related_item_is_live($item)) {
                return true;
            }
        }
        return false;
    }

    private static function table_exists($table) {
        global $wpdb;
        return (string) $table === (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like((string) $table))
        );
    }

    private static function decode_json($value) {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : array();
    }

    private static function normalize($value) {
        if (class_exists('SEO_Dependiente_Index')) {
            return SEO_Dependiente_Index::normalize((string) $value);
        }
        return sanitize_title((string) $value);
    }

    private static function sanitize_mode($mode) {
        $mode = sanitize_key((string) $mode);
        return in_array($mode, array('need', 'product', 'tool', 'compare'), true) ? $mode : 'need';
    }

    private static function short_key($value, $length) {
        $value = sanitize_key((string) $value);
        return substr($value, 0, max(1, absint($length)));
    }
}
