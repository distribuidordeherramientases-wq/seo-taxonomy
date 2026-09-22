<?php

defined('ABSPATH') || exit;

/**
 * Academia · Lección 10 — Consolidación semántica y regresión.
 *
 * L10 v2 usa la deuda histórica L1-L9 únicamente para localizar zonas débiles.
 * No repite literalmente las preguntas fallidas ni sus contratos antiguos.
 * Reconstruye una pregunta natural desde la fuente canónica ACTUAL y la ejecuta
 * siempre contra Dependiente V3, que a su vez pasa por el Intérprete.
 *
 * El objetivo es comprobar que el lenguaje del cliente puede perder ruido,
 * conservar intención/conceptos y desembocar en una familia/producto válido.
 * La evaluación usa TIPO/APLICACIÓN/SUBTIPO/PLATAFORMA/ROL o categoría. Si una
 * deuda no tiene un objetivo semántico utilizable, se excluye en vez de exigir
 * identidad literal de producto.
 * L10 no crea ni duplica reglas de conocimiento.
 */
final class SEO_Dependiente_V3_Lesson10 {
    const VERSION = '2026-09-22.2';
    const LESSON_KEY = 'v2_l10_consolidation_debt';
    const CURRICULUM = 'semantic-v2';

    private static $items = null;
    private static $stats = null;
    private static $index_cache = array();
    private static $vocabulary_map = null;
    private static $vocabulary_label_by_key = array();
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
            'curriculum'                    => self::CURRICULUM,
            'historical_failed_rows'        => 0,
            'excluded_curriculum_invalid'   => 0,
            'excluded_stale_source'         => 0,
            'excluded_non_semantic'         => 0,
            'duplicates_removed'            => 0,
            'eligible_debt'                 => 0,
            'rebuilt_product_targets'       => 0,
            'rebuilt_category_targets'      => 0,
            'rebuilt_vocabulary_targets'    => 0,
            'by_origin_lesson'              => array(),
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

        // La deuda sirve como selector de zonas débiles. Solo cuenta la última
        // ejecución de cada pregunta; si ya se aprobó después, no reaparece.
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

            $rebuilt = self::build_semantic_item($row, $expected, $diagnostic);
            if (!$rebuilt) {
                self::$stats['excluded_non_semantic']++;
                continue;
            }

            $target_key = (string) ($rebuilt['_l10_target_key'] ?? '');
            unset($rebuilt['_l10_target_key']);
            if (!$target_key) {
                self::$stats['excluded_non_semantic']++;
                continue;
            }
            if (isset($seen[$target_key])) {
                self::$stats['duplicates_removed']++;
                continue;
            }
            $seen[$target_key] = true;

            $origin_lesson = sanitize_key((string) ($row['origin_lesson'] ?? ''));
            $rebuilt_expected = (array) ($rebuilt['expected'] ?? array());
            $academy = is_array($rebuilt_expected['academy'] ?? null) ? $rebuilt_expected['academy'] : array();
            $rebuilt_expected['academy'] = array_merge($academy, array(
                'lesson'                => self::LESSON_KEY,
                'curriculum'            => self::CURRICULUM,
                'historical_debt'       => true,
                'origin_lesson'         => $origin_lesson,
                'origin_question_id'    => absint($row['origin_question_id'] ?? 0),
                'origin_run_id'         => absint($row['origin_run_id'] ?? 0),
                'origin_diagnostic'     => $diagnostic,
                'origin_run_created_at' => sanitize_text_field((string) ($row['origin_run_created_at'] ?? '')),
                'evaluation_policy'     => 'semantic_v3_top8',
            ));
            $rebuilt['expected'] = $rebuilt_expected;
            $rebuilt['rules'] = array();
            self::$items[] = $rebuilt;

            if (!isset(self::$stats['by_origin_lesson'][$origin_lesson])) {
                self::$stats['by_origin_lesson'][$origin_lesson] = 0;
            }
            self::$stats['by_origin_lesson'][$origin_lesson]++;
        }

        self::$stats['eligible_debt'] = count(self::$items);
        return self::$items;
    }

    /**
     * Convierte una deuda antigua en un objetivo semántico actual.
     * FAQ/editorial no se vuelven a examinar como FAQ/editorial: cuando existe
     * owner de producto/categoría se prueba el owner; contenido sin destino de
     * catálogo queda fuera de esta L10 y continúa como deuda de fuente/auditoría.
     */
    private static function build_semantic_item($row, $expected, $diagnostic) {
        $kind = sanitize_key((string) ($expected['kind'] ?? ''));

        if ('product' === $kind) {
            return self::build_product_item(absint($expected['product_id'] ?? 0), $row, $diagnostic);
        }
        if ('features' === $kind) {
            return self::build_product_item(absint($expected['source_product_id'] ?? 0), $row, $diagnostic);
        }
        if ('category' === $kind) {
            return self::build_category_item(absint($expected['category_id'] ?? 0), $row, $diagnostic);
        }
        if ('vocabulary' === $kind) {
            return self::build_vocabulary_item((array) ($expected['conditions'] ?? array()), $row, $diagnostic);
        }
        if ('faq' === $kind) {
            $owner_type = absint($expected['owner_type'] ?? 0);
            $owner_id = absint($expected['owner_id'] ?? 0);
            if (3 === $owner_type) {
                return self::build_product_item($owner_id, $row, $diagnostic);
            }
            if (2 === $owner_type) {
                return self::build_category_item($owner_id, $row, $diagnostic);
            }
            return array();
        }
        if ('cross' === $kind) {
            return self::build_vocabulary_item((array) ($expected['conditions'] ?? array()), $row, $diagnostic);
        }

        // content/editorial no tiene necesariamente un destino de producto o
        // categoría y por tanto no debe falsear el examen público de Dependiente.
        return array();
    }

    private static function build_product_item($product_id, $origin, $diagnostic) {
        $product_id = absint($product_id);
        $row = self::index_row($product_id);
        if (!$product_id || !$row) {
            return array();
        }

        $tipo = self::first_vocabulary_feature($row, 'tipo');
        $aplicacion = self::first_vocabulary_feature($row, 'aplicacion');
        $subtipo = self::first_vocabulary_feature($row, 'subtipo');
        $plataforma = self::first_vocabulary_feature($row, 'plataforma');
        $rol = self::first_vocabulary_feature($row, 'rol');
        $features = array();
        $question = '';
        $question_type = 'debt_semantic_product';

        if ($tipo && $aplicacion) {
            $features = array($tipo, $aplicacion);
            $question = self::natural_type_application_question($tipo['label'], $aplicacion['label'], 'product:' . $product_id);
            $question_type = 'debt_type_application';
        } elseif ($tipo && $subtipo) {
            $features = array($tipo, $subtipo);
            $question = 'Estoy buscando ' . $tipo['label'] . '; si puede ser ' . $subtipo['label'] . ', mejor.';
            $question_type = 'debt_type_subtype';
        } elseif ($tipo && $plataforma) {
            $features = array($tipo, $plataforma);
            $question = 'Necesito ' . $tipo['label'] . ' para usar con ' . $plataforma['label'] . '. ¿Qué opciones tengo?';
            $question_type = 'debt_type_platform';
        } elseif ($tipo && $rol) {
            $features = array($tipo, $rol);
            $question = 'Busco ' . $tipo['label'] . '; necesito una opción que funcione como ' . $rol['label'] . '.';
            $question_type = 'debt_type_role';
        } elseif ($tipo) {
            $features = array($tipo);
            $question = 'Estoy buscando algo del tipo ' . $tipo['label'] . '. ¿Qué opciones me pueden servir?';
            $question_type = 'debt_type';
        } elseif ($aplicacion && $rol) {
            $features = array($aplicacion, $rol);
            $question = 'Necesito ' . $rol['label'] . ' para ' . $aplicacion['label'] . '. ¿Qué me puede servir?';
            $question_type = 'debt_role_application';
        }

        if ($features) {
            self::$stats['rebuilt_product_targets']++;
            return array(
                '_l10_target_key' => 'product-semantic:' . $product_id . ':' . self::feature_signature($features),
                'source_type'     => 'historical_debt_semantic',
                'source_id'       => $product_id,
                'source_key'      => 'l10-product:' . $product_id,
                'question_type'   => $question_type,
                'mode'            => 'need',
                'question'        => self::shorten($question, 490),
                'expected'        => array(
                    'kind'              => 'features',
                    'features'          => array_values($features),
                    'source_product_id' => $product_id,
                    'academy'           => array('rebuilt_from' => 'current_product_semantics', 'origin_diagnostic' => $diagnostic),
                ),
            );
        }

        $category = self::first_category($row);
        if ($category) {
            return self::build_category_item(absint($category['id'] ?? 0), $origin, $diagnostic, $product_id);
        }

        // Sin semántica ni categoría no fabricamos un examen de identidad exacta:
        // sería volver al rigor literal que L10 v2 pretende evitar.
        return array();
    }

    private static function build_category_item($category_id, $origin, $diagnostic, $source_product_id = 0) {
        $category_id = absint($category_id);
        if (!$category_id) {
            return array();
        }
        $term = get_term($category_id, 'product_cat');
        if (!$term || is_wp_error($term)) {
            return array();
        }
        $name = trim((string) $term->name);
        if (!$name) {
            return array();
        }
        $children = get_term_children($category_id, 'product_cat');
        if (is_wp_error($children)) {
            $children = array();
        }
        $acceptable = array_values(array_unique(array_merge(array($category_id), array_map('absint', (array) $children))));
        $variant = abs(crc32('category:' . $category_id)) % 3;
        if (0 === $variant) {
            $question = 'Estoy buscando algo dentro de ' . $name . '. ¿Qué opciones me pueden servir?';
        } elseif (1 === $variant) {
            $question = 'Quiero ver productos de ' . $name . '; no necesito una referencia concreta.';
        } else {
            $question = 'Necesito una solución de ' . $name . '. Enséñame opciones que encajen.';
        }

        self::$stats['rebuilt_category_targets']++;
        return array(
            '_l10_target_key' => 'category:' . $category_id,
            'source_type'     => 'historical_debt_semantic',
            'source_id'       => $source_product_id ? absint($source_product_id) : $category_id,
            'source_key'      => 'l10-category:' . $category_id,
            'question_type'   => 'debt_category_semantic',
            'mode'            => 'need',
            'question'        => self::shorten($question, 490),
            'expected'        => array(
                'kind'                    => 'category',
                'category_id'             => $category_id,
                'category_name'           => $name,
                'acceptable_category_ids' => $acceptable,
                'academy'                 => array('rebuilt_from' => 'current_category', 'origin_diagnostic' => $diagnostic),
            ),
        );
    }

    private static function build_vocabulary_item($conditions, $origin, $diagnostic) {
        $selected = array();
        $labels = array();
        foreach (array('tipo','aplicacion','subtipo','plataforma','rol') as $group) {
            $slugs = array_values(array_unique(array_filter(array_map('sanitize_title', (array) ($conditions[$group] ?? array())))));
            foreach ($slugs as $slug) {
                $key = $group . '|' . $slug;
                if (empty(self::$vocabulary_map[$key])) {
                    continue;
                }
                $label = trim((string) (self::$vocabulary_label_by_key[$key] ?? ''));
                if (!$label) {
                    continue;
                }
                $selected[$group] = array($slug);
                $labels[$group] = $label;
                break;
            }
            if (count($selected) >= 2) {
                break;
            }
        }
        if (!$selected) {
            return array();
        }

        if (!empty($labels['tipo']) && !empty($labels['aplicacion'])) {
            $question = self::natural_type_application_question($labels['tipo'], $labels['aplicacion'], 'vocab:' . wp_json_encode($selected));
        } elseif (!empty($labels['tipo']) && !empty($labels['subtipo'])) {
            $question = 'Busco ' . $labels['tipo'] . '; si puede ser ' . $labels['subtipo'] . ', mejor.';
        } elseif (!empty($labels['tipo']) && !empty($labels['plataforma'])) {
            $question = 'Necesito ' . $labels['tipo'] . ' para ' . $labels['plataforma'] . '.';
        } elseif (!empty($labels['tipo']) && !empty($labels['rol'])) {
            $question = 'Estoy buscando ' . $labels['tipo'] . ' que me sirva como ' . $labels['rol'] . '.';
        } elseif (!empty($labels['aplicacion'])) {
            $question = 'Necesito una solución para ' . $labels['aplicacion'] . '. ¿Qué me puede servir?';
        } else {
            $question = 'Estoy buscando algo relacionado con ' . reset($labels) . '. ¿Qué opciones tengo?';
        }

        ksort($selected);
        self::$stats['rebuilt_vocabulary_targets']++;
        return array(
            '_l10_target_key' => 'vocabulary:' . hash('sha256', wp_json_encode($selected)),
            'source_type'     => 'historical_debt_semantic',
            'source_id'       => absint($origin['origin_question_id'] ?? 0),
            'source_key'      => 'l10-vocabulary:' . substr(hash('sha256', wp_json_encode($selected)), 0, 20),
            'question_type'   => 'debt_vocabulary_semantic',
            'mode'            => 'need',
            'question'        => self::shorten($question, 490),
            'expected'        => array(
                'kind'       => 'vocabulary',
                'conditions' => $selected,
                'academy'    => array('rebuilt_from' => 'current_vocabulary', 'origin_diagnostic' => $diagnostic),
            ),
        );
    }

    private static function natural_type_application_question($tipo, $aplicacion, $seed) {
        $tipo = trim((string) $tipo);
        $aplicacion = trim((string) $aplicacion);
        $variant = abs(crc32((string) $seed)) % 3;
        if (0 === $variant) {
            return 'Necesito algo para ' . $aplicacion . '; creo que busco ' . $tipo . ', aunque no sé cómo se llama exactamente.';
        }
        if (1 === $variant) {
            return 'Quiero resolver una necesidad de ' . $aplicacion . '. ¿Qué ' . $tipo . ' me puede servir?';
        }
        return 'Estoy buscando una solución para ' . $aplicacion . '; seguramente sea ' . $tipo . '. ¿Qué opciones hay?';
    }

    private static function feature_signature($features) {
        $parts = array();
        foreach ((array) $features as $feature) {
            $parts[] = sanitize_key((string) ($feature['kind'] ?? '')) . ':'
                . sanitize_key((string) ($feature['group'] ?? '')) . ':'
                . sanitize_title((string) ($feature['slug'] ?? $feature['label'] ?? ''));
        }
        return substr(hash('sha256', implode('|', $parts)), 0, 16);
    }

    /** Verifica que la verdad histórica aún esté respaldada antes de usarla como selector. */
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
            $term = $category_id ? get_term($category_id, 'product_cat') : null;
            return $term && !is_wp_error($term);
        }
        if ('vocabulary' === $kind) {
            return self::vocabulary_conditions_exist((array) ($expected['conditions'] ?? array()));
        }
        if ('faq' === $kind) {
            $faq_id = absint($expected['faq_id'] ?? 0);
            $faq = $faq_id && isset(self::$faq_cache[$faq_id]) ? (array) self::$faq_cache[$faq_id] : array();
            if (!$faq || empty($faq['active'])) {
                return false;
            }
            $owner_type = absint($expected['owner_type'] ?? 0);
            $owner_id = absint($expected['owner_id'] ?? 0);
            return (!$owner_type || $owner_type === absint($faq['object_type'] ?? 0))
                && (!$owner_id || $owner_id === absint($faq['object_id'] ?? 0));
        }
        if ('content' === $kind) {
            return self::has_live_related((array) ($expected['acceptable_related'] ?? array()));
        }
        if ('cross' === $kind) {
            return self::vocabulary_conditions_exist((array) ($expected['conditions'] ?? array()));
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
                if (3 === absint($expected['owner_type'] ?? 0)) {
                    $product_ids[] = absint($expected['owner_id'] ?? 0);
                }
            } elseif ('content' === $kind) {
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
        self::$vocabulary_label_by_key = array();
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        if (self::table_exists($vocabulary)) {
            $terms = (array) $wpdb->get_results(
                "SELECT semantic_group,slug,label FROM {$vocabulary} WHERE active=1",
                ARRAY_A
            );
            foreach ($terms as $term) {
                $group = sanitize_key((string) ($term['semantic_group'] ?? ''));
                $slug = sanitize_title((string) ($term['slug'] ?? ''));
                $label = trim((string) ($term['label'] ?? ''));
                if ($group && $slug) {
                    $key = $group . '|' . $slug;
                    self::$vocabulary_map[$key] = true;
                    if ($label) {
                        self::$vocabulary_label_by_key[$key] = $label;
                    }
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
        }
    }

    private static function index_row($product_id) {
        $product_id = absint($product_id);
        return $product_id && isset(self::$index_cache[$product_id]) ? (array) self::$index_cache[$product_id] : array();
    }

    private static function first_vocabulary_feature($row, $group) {
        foreach ((array) ($row['vocabulary'][$group] ?? array()) as $term) {
            if (!is_array($term)) {
                continue;
            }
            $label = trim((string) ($term['label'] ?? $term['name'] ?? ''));
            $slug = sanitize_title((string) ($term['slug'] ?? $label));
            if ($label && $slug) {
                return array('kind'=>'vocabulary','group'=>sanitize_key($group),'slug'=>$slug,'label'=>$label);
            }
        }
        return array();
    }

    private static function first_category($row) {
        foreach ((array) ($row['categories'] ?? array()) as $category) {
            if (!is_array($category)) {
                continue;
            }
            $id = absint($category['id'] ?? 0);
            $name = trim((string) ($category['name'] ?? $category['label'] ?? ''));
            if ($id && $name) {
                return array('id'=>$id,'name'=>$name);
            }
        }
        return array();
    }

    private static function row_matches_feature($row, $feature) {
        $kind = sanitize_key((string) ($feature['kind'] ?? ''));
        if ('vocabulary' === $kind) {
            $group = sanitize_key((string) ($feature['group'] ?? ''));
            $slug = sanitize_title((string) ($feature['slug'] ?? ''));
            if (!$group || !$slug || !self::vocabulary_conditions_exist(array($group => array($slug)))) {
                return false;
            }
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
        foreach ((array) $conditions as $group => $slugs) {
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

    private static function shorten($value, $length) {
        $value = trim((string) $value);
        $length = max(1, absint($length));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }
        return substr($value, 0, $length);
    }
}
