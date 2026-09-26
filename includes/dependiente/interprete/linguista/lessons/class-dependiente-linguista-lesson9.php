<?php

defined('ABSPATH') || exit;

/**
 * Lingüista · L9 — Consolidación desde Dependiente.
 *
 * Objetivo:
 * - repasar la deuda propia que dejó L8, pero evaluándola con el Intérprete V3
 *   que realmente usa Dependiente;
 * - usar la deuda L1-L9 de Academia como selector de consultas naturales;
 * - aprender ruido conversacional únicamente cuando aparece como evidencia
 *   independiente en varios objetivos semánticos distintos;
 * - no aprender catálogo ni respuestas de Dependiente: catálogo/Vocabulary
 *   actuales siguen siendo la verdad y el Intérprete sólo aprende CÓMO habla
 *   el cliente.
 *
 * La lección tiene tres fases:
 *  1) review_l8: comprueba hipótesis débiles/ambiguas de L8 contra V3;
 *  2) learn_dependiente: analiza la deuda reconstruida de Dependiente y crea
 *     hipótesis lingüísticas conservadoras;
 *  3) regression_dependiente: repite esas consultas con la memoria ya
 *     consolidada para medir si Intérprete está preparado para Dependiente L10.
 */
final class SEO_Dependiente_Linguista_Lesson9 {
    const VERSION = '0.1.0';
    const LESSON_KEY = 'ling_l9_dependiente_bridge';
    const PROMOTE_EVIDENCE = 3;

    private static $vocabulary_labels = array();

    public static function total() {
        if (!self::ensure_dependencies()) {
            return 0;
        }
        $review = self::review_total();
        $debt = self::debt_total();
        return $review + ($debt * 2);
    }

    public static function run_batch($state) {
        if (!self::ensure_dependencies()) {
            return array(
                'done' => true,
                'processed' => 0,
                'learned' => 0,
                'rejected' => 1,
                'message' => 'L9 no puede ejecutarse: faltan dependencias de Intérprete V3 o de la deuda de Dependiente.',
            );
        }

        $phase = sanitize_key((string) ($state['lesson_phase'] ?? '')) ?: 'review_l8';
        if ('review_l8' === $phase) {
            return self::run_review_batch($state);
        }
        if ('learn_dependiente' === $phase) {
            return self::run_dependiente_batch($state, true);
        }
        if ('regression_dependiente' === $phase) {
            return self::run_dependiente_batch($state, false);
        }

        return array(
            'done' => true,
            'processed' => 0,
            'learned' => 0,
            'rejected' => 0,
            'message' => 'L9 completada.',
        );
    }

    private static function ensure_dependencies() {
        $dependiente_dir = dirname(__DIR__, 3);

        if (!class_exists('SEO_Dependiente_V3_Interpreter')) {
            $file = $dependiente_dir . '/v3/class-dependiente-v3-interpreter.php';
            if (is_readable($file)) {
                require_once $file;
            }
        }
        if (!class_exists('SEO_Dependiente_Entrenador')) {
            $file = $dependiente_dir . '/entrenador/seo-dependiente-entrenador.php';
            if (is_readable($file)) {
                require_once $file;
            }
        }
        if (!class_exists('SEO_Dependiente_V3_Lesson10')) {
            $file = $dependiente_dir . '/v3/training/class-dependiente-v3-lesson10.php';
            if (is_readable($file)) {
                require_once $file;
            }
        }

        return class_exists('SEO_Dependiente_Interprete_DB')
            && class_exists('SEO_Dependiente_V3_Interpreter')
            && class_exists('SEO_Dependiente_V3_Lesson10');
    }

    private static function batch_size($state) {
        return min(80, max(5, absint($state['batch_size'] ?? 40)));
    }

    /**
     * Repaso concentrado: L8 dejó 13k+ reglas provisionales, pero no tiene sentido
     * repetirlas todas. Revisamos las que siguen débiles/contradictorias y los
     * candidatos que L8 conservó sin activar.
     */
    private static function review_total() {
        global $wpdb;
        $table = SEO_Dependiente_Interprete_DB::table();
        if (!SEO_Dependiente_Interprete_DB::table_exists()) {
            return 0;
        }

        return absint($wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
              WHERE language='es'
                AND (
                    (source='linguista_l8_auto' AND active=1
                     AND (confidence<=0.7000 OR contradiction_count>0 OR context_required=1))
                    OR
                    (active=0
                     AND source IN ('linguista_catalog_actions','linguista_explicit_synonym')
                     AND relation_type IN ('synonym','catalog_variant','verb_to_tool','phrase_to_tool'))
                )"
        ));
    }

    private static function debt_total() {
        try {
            return absint(SEO_Dependiente_V3_Lesson10::source_total());
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function run_review_batch($state) {
        global $wpdb;
        $table = SEO_Dependiente_Interprete_DB::table();
        $cursor = absint($state['cursor'] ?? 0);
        $batch = self::batch_size($state);

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id,expression,normalized_expression,canonical_term,target_search,relation_type,
                    semantic_group,vocabulary_id,context_terms,context_required,confidence,priority,
                    source,evidence_count,usage_count,contradiction_count,validated,active
               FROM {$table}
              WHERE id>%d AND language='es'
                AND (
                    (source='linguista_l8_auto' AND active=1
                     AND (confidence<=0.7000 OR contradiction_count>0 OR context_required=1))
                    OR
                    (active=0
                     AND source IN ('linguista_catalog_actions','linguista_explicit_synonym')
                     AND relation_type IN ('synonym','catalog_variant','verb_to_tool','phrase_to_tool'))
                )
              ORDER BY id ASC
              LIMIT %d",
            $cursor,
            $batch
        ), ARRAY_A);

        if (!$rows) {
            return array(
                'done' => false,
                'processed' => 0,
                'learned' => 0,
                'rejected' => 0,
                'message' => 'L9 · repaso L8 terminado. Empezando deuda lingüística procedente de Dependiente L1-L9.',
                'state_changes' => array(
                    'lesson_phase' => 'learn_dependiente',
                    'cursor' => 0,
                ),
            );
        }

        $pass = 0;
        $fail = 0;
        $last_id = $cursor;

        foreach ($rows as $row) {
            $row_id = absint($row['id'] ?? 0);
            $last_id = max($last_id, $row_id);
            $expression = self::normalize((string) ($row['normalized_expression'] ?? $row['expression'] ?? ''));
            if (!$row_id || '' === $expression) {
                $fail++;
                continue;
            }

            if (empty($row['active'])) {
                // L8 dejó estos candidatos sin activar de forma deliberada.
                // L9 no convierte una ambigüedad propia en verdad por repetición.
                $fail++;
                continue;
            }

            $contexts = json_decode((string) ($row['context_terms'] ?? ''), true);
            $contexts = is_array($contexts) ? array_values(array_filter($contexts)) : array();
            $query = $expression;
            if ($contexts) {
                $query .= ' ' . (string) reset($contexts);
            }

            $interpretation = SEO_Dependiente_V3_Interpreter::interpret($query);
            $matched = false;
            foreach ((array) ($interpretation['lexicon_hits'] ?? array()) as $hit) {
                if (absint($hit['id'] ?? 0) === $row_id) {
                    $matched = true;
                    break;
                }
            }

            if ($matched) {
                $pass++;
            } else {
                $fail++;
            }
        }

        return array(
            'done' => false,
            'processed' => count($rows),
            'learned' => 0,
            'rejected' => $fail,
            'message' => 'L9 · repaso L8: ' . number_format_i18n($pass) . ' reglas utilizables por V3 · ' . number_format_i18n($fail) . ' siguen en deuda.',
            'state_changes' => array(
                'cursor' => $last_id,
                'l9_review_pass' => absint($state['l9_review_pass'] ?? 0) + $pass,
                'l9_review_fail' => absint($state['l9_review_fail'] ?? 0) + $fail,
            ),
        );
    }

    private static function run_dependiente_batch($state, $learning) {
        $cursor = absint($state['cursor'] ?? 0);
        $batch = self::batch_size($state);
        $items = (array) SEO_Dependiente_V3_Lesson10::source_batch($cursor, $batch);

        if (!$items) {
            if ($learning) {
                return array(
                    'done' => false,
                    'processed' => 0,
                    'learned' => 0,
                    'rejected' => 0,
                    'message' => 'L9 · aprendizaje desde Dependiente terminado. Iniciando regresión final con la memoria lingüística consolidada.',
                    'state_changes' => array(
                        'lesson_phase' => 'regression_dependiente',
                        'cursor' => 0,
                    ),
                );
            }

            $pass = absint($state['l9_regression_pass'] ?? 0);
            $fail = absint($state['l9_regression_fail'] ?? 0);
            $total = $pass + $fail;
            $rate = $total ? round(($pass / $total) * 100, 2) : 0;

            return array(
                'done' => true,
                'processed' => 0,
                'learned' => 0,
                'rejected' => 0,
                'message' => 'L9 terminada: ' . number_format_i18n($rate, 2) . '% de regresión Intérprete→Dependiente válida · '
                    . number_format_i18n(absint($state['l9_noise_promoted'] ?? 0)) . ' patrones de ruido consolidados.',
                'state_changes' => array('l9_regression_rate' => $rate),
            );
        }

        $processed = 0;
        $learned = 0;
        $rejected = 0;
        $semantic_pass = 0;
        $semantic_fail = 0;
        $noise_evidence = 0;
        $noise_promoted = 0;
        $regression_pass = 0;
        $regression_fail = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $question = trim((string) ($item['question'] ?? ''));
            $expected = is_array($item['expected'] ?? null) ? $item['expected'] : array();
            if ('' === $question || !$expected) {
                $processed++;
                if (!$learning) {
                    $regression_fail++;
                    $rejected++;
                }
                continue;
            }

            $interpretation = SEO_Dependiente_V3_Interpreter::interpret($question);
            $semantic_ok = self::expected_is_preserved($expected, $interpretation);
            $residual = self::residual_literal_terms($interpretation);
            $problem_noise = self::matching_noise_patterns($question, $residual);

            if ($semantic_ok) {
                $semantic_pass++;
            } else {
                $semantic_fail++;
            }

            if ($learning) {
                $source_id = self::evidence_source_id($item);

                foreach ($problem_noise as $phrase) {
                    $result = self::stage_noise($phrase, $source_id, $question, true);
                    if (!empty($result['evidence'])) {
                        $noise_evidence++;
                    }
                    if (!empty($result['promoted'])) {
                        $noise_promoted++;
                        $learned++;
                    }
                }

                // Las palabras residuales se conservan como hipótesis inactivas.
                // No se activan automáticamente: una palabra aislada puede tener
                // valor comercial en otro contexto.
                $expected_tokens = self::expected_tokens($expected);
                foreach (array_slice($residual, 0, 8) as $term) {
                    $term = self::normalize($term);
                    if (strlen($term) < 3 || isset($expected_tokens[$term]) || preg_match('/^\d+(?:[.,]\d+)?$/', $term)) {
                        continue;
                    }
                    $result = self::stage_noise($term, $source_id, $question, false);
                    if (!empty($result['evidence'])) {
                        $noise_evidence++;
                    }
                }
            } else {
                $clean = empty($problem_noise);
                if ($semantic_ok && $clean) {
                    $regression_pass++;
                } else {
                    $regression_fail++;
                    $rejected++;
                }
            }

            $processed++;
        }

        $changes = array('cursor' => $cursor + count($items));
        if ($learning) {
            $changes['l9_debt_seen'] = absint($state['l9_debt_seen'] ?? 0) + $processed;
            $changes['l9_semantic_pass'] = absint($state['l9_semantic_pass'] ?? 0) + $semantic_pass;
            $changes['l9_semantic_fail'] = absint($state['l9_semantic_fail'] ?? 0) + $semantic_fail;
            $changes['l9_noise_evidence'] = absint($state['l9_noise_evidence'] ?? 0) + $noise_evidence;
            $changes['l9_noise_promoted'] = absint($state['l9_noise_promoted'] ?? 0) + $noise_promoted;
            $message = 'L9 · deuda Dependiente: ' . number_format_i18n($processed)
                . ' consultas · ' . number_format_i18n($semantic_pass) . ' semánticamente conservadas · '
                . number_format_i18n($noise_promoted) . ' patrones de ruido consolidados.';
        } else {
            $changes['l9_regression_pass'] = absint($state['l9_regression_pass'] ?? 0) + $regression_pass;
            $changes['l9_regression_fail'] = absint($state['l9_regression_fail'] ?? 0) + $regression_fail;
            $message = 'L9 · regresión final: ' . number_format_i18n($regression_pass)
                . ' correctas · ' . number_format_i18n($regression_fail) . ' todavía en deuda.';
        }

        return array(
            'done' => false,
            'processed' => $processed,
            'learned' => $learned,
            'rejected' => $rejected,
            'message' => $message,
            'state_changes' => $changes,
        );
    }

    private static function expected_is_preserved($expected, $interpretation) {
        $targets = self::expected_targets($expected);
        if (!$targets) {
            return false;
        }
        foreach ($targets as $target) {
            if (!self::target_is_preserved($target, $interpretation)) {
                return false;
            }
        }
        return true;
    }

    private static function expected_targets($expected) {
        $kind = sanitize_key((string) ($expected['kind'] ?? ''));
        $targets = array();

        if ('features' === $kind) {
            foreach ((array) ($expected['features'] ?? array()) as $feature) {
                if (!is_array($feature) || 'vocabulary' !== sanitize_key((string) ($feature['kind'] ?? ''))) {
                    continue;
                }
                $group = sanitize_key((string) ($feature['group'] ?? ''));
                $label = trim((string) ($feature['label'] ?? ''));
                $slug = sanitize_title((string) ($feature['slug'] ?? $label));
                if ($group && ($label || $slug)) {
                    $targets[] = array('group' => $group, 'label' => $label, 'slug' => $slug, 'strict_group' => true);
                }
            }
            return $targets;
        }

        if ('category' === $kind) {
            $label = trim((string) ($expected['category_name'] ?? ''));
            if ($label) {
                $targets[] = array('group' => 'category', 'label' => $label, 'slug' => sanitize_title($label), 'strict_group' => false);
            }
            return $targets;
        }

        if ('vocabulary' === $kind) {
            foreach ((array) ($expected['conditions'] ?? array()) as $group => $slugs) {
                $group = sanitize_key((string) $group);
                foreach ((array) $slugs as $slug) {
                    $slug = sanitize_title((string) $slug);
                    if (!$group || !$slug) {
                        continue;
                    }
                    $targets[] = array(
                        'group' => $group,
                        'label' => self::vocabulary_label($group, $slug),
                        'slug' => $slug,
                        'strict_group' => true,
                    );
                }
            }
        }

        return $targets;
    }

    private static function vocabulary_label($group, $slug) {
        global $wpdb;
        $group = sanitize_key((string) $group);
        $slug = sanitize_title((string) $slug);
        $cache_key = $group . '|' . $slug;
        if (array_key_exists($cache_key, self::$vocabulary_labels)) {
            return self::$vocabulary_labels[$cache_key];
        }

        $table = $wpdb->prefix . 'seo_vocabulary';
        $label = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT label FROM {$table} WHERE active=1 AND semantic_group=%s AND slug=%s ORDER BY id ASC LIMIT 1",
            $group,
            $slug
        ));
        self::$vocabulary_labels[$cache_key] = trim($label);
        return self::$vocabulary_labels[$cache_key];
    }

    private static function target_is_preserved($target, $interpretation) {
        $group = sanitize_key((string) ($target['group'] ?? ''));
        $label = self::normalize((string) ($target['label'] ?? ''));
        $slug = self::normalize(str_replace(array('-', '_'), ' ', (string) ($target['slug'] ?? '')));
        $wanted = array_values(array_unique(array_filter(array($label, $slug))));

        foreach ((array) (($interpretation['concepts'][$group] ?? array())) as $value) {
            $value = self::normalize((string) $value);
            if (in_array($value, $wanted, true)) {
                return true;
            }
        }

        foreach ((array) ($interpretation['groups'] ?? array()) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $role = sanitize_key((string) ($row['role'] ?? ''));
            if (!empty($target['strict_group']) && $role && $role !== $group) {
                continue;
            }
            $values = array_merge(
                array((string) ($row['canonical'] ?? '')),
                (array) ($row['variants'] ?? array())
            );
            foreach ($values as $value) {
                if (in_array(self::normalize((string) $value), $wanted, true)) {
                    return true;
                }
            }
        }

        // Categorías y nombres compuestos pueden descomponerse en varios grupos
        // válidos. Aceptamos cobertura léxica alta sólo para objetivos no estrictos.
        if (empty($target['strict_group']) && $label) {
            $tokens = self::significant_tokens($label);
            if ($tokens) {
                $available = self::semantic_token_map($interpretation);
                $hits = 0;
                foreach ($tokens as $token) {
                    if (isset($available[$token])) {
                        $hits++;
                    }
                }
                return ($hits / count($tokens)) >= 0.65;
            }
        }

        return false;
    }

    private static function expected_tokens($expected) {
        $out = array();
        foreach (self::expected_targets($expected) as $target) {
            foreach (self::significant_tokens((string) ($target['label'] ?? '')) as $token) {
                $out[$token] = true;
            }
            foreach (self::significant_tokens(str_replace(array('-', '_'), ' ', (string) ($target['slug'] ?? ''))) as $token) {
                $out[$token] = true;
            }
        }
        return $out;
    }

    private static function semantic_token_map($interpretation) {
        $out = array();
        foreach ((array) ($interpretation['groups'] ?? array()) as $group) {
            if (!is_array($group) || 'literal' === sanitize_key((string) ($group['source'] ?? ''))) {
                continue;
            }
            $values = array_merge(array((string) ($group['canonical'] ?? '')), (array) ($group['variants'] ?? array()));
            foreach ($values as $value) {
                foreach (self::significant_tokens((string) $value) as $token) {
                    $out[$token] = true;
                }
            }
        }
        foreach ((array) ($interpretation['concepts'] ?? array()) as $values) {
            foreach ((array) $values as $value) {
                foreach (self::significant_tokens((string) $value) as $token) {
                    $out[$token] = true;
                }
            }
        }
        return $out;
    }

    private static function residual_literal_terms($interpretation) {
        $out = array();
        foreach ((array) ($interpretation['groups'] ?? array()) as $group) {
            if (!is_array($group)) {
                continue;
            }
            if ('literal' !== sanitize_key((string) ($group['source'] ?? ''))
                || 'term' !== sanitize_key((string) ($group['role'] ?? 'term'))) {
                continue;
            }
            $term = self::normalize((string) ($group['canonical'] ?? ''));
            if ($term) {
                $out[] = $term;
            }
        }
        return array_values(array_unique($out));
    }

    private static function matching_noise_patterns($question, $residual) {
        if (!$residual) {
            return array();
        }
        $question = self::normalize($question);
        $residual_map = array_fill_keys($residual, true);
        $out = array();

        foreach (self::noise_patterns() as $pattern) {
            $pattern = self::normalize($pattern);
            if (!$pattern || !self::contains_phrase($question, $pattern)) {
                continue;
            }
            $touches_residual = false;
            foreach (explode(' ', $pattern) as $token) {
                if (isset($residual_map[$token])) {
                    $touches_residual = true;
                    break;
                }
            }
            if ($touches_residual) {
                $out[] = $pattern;
            }
        }
        return array_values(array_unique($out));
    }

    private static function noise_patterns() {
        // Patrones generales de duda, petición o metadiscurso. Todos caben en
        // los n-gramas <=5 que usa Intérprete V3; no contienen nombres de catálogo.
        return array(
            'creo que',
            'aunque no se como',
            'no se como se llama',
            'se llama exactamente',
            'seguramente sea',
            'que opciones hay',
            'quiero resolver una necesidad',
            'me puede servir',
            'que me puede servir',
            'estoy buscando una solucion',
            'necesito una solucion',
            'que opciones me pueden servir',
            'que opciones tengo',
            'quiero ver productos',
            'no necesito una referencia',
            'referencia concreta',
            'ensename opciones',
            'opciones que encajen',
            'estoy buscando algo dentro',
            'estoy buscando algo del tipo',
            'si puede ser',
            'necesito una opcion que funcione',
            'estoy buscando algo relacionado',
            'que me sirva como',
            'para usar con',
        );
    }

    private static function stage_noise($expression, $source_id, $question, $allow_promotion) {
        global $wpdb;
        $expression = self::normalize($expression);
        if ('' === $expression || strlen($expression) > 191) {
            return array('evidence' => false, 'promoted' => false);
        }

        $table = SEO_Dependiente_Interprete_DB::table();
        $before = $wpdb->get_row($wpdb->prepare(
            "SELECT id,active,evidence_count FROM {$table}
              WHERE normalized_expression=%s AND canonical_term=%s AND relation_type='noise'
              LIMIT 1",
            $expression,
            $expression
        ), ARRAY_A);
        $was_active = !empty($before['active']);

        $id = SEO_Dependiente_Interprete_DB::upsert_row(array(
            'expression' => $expression,
            'canonical_term' => $expression,
            'target_search' => $expression,
            'relation_type' => 'noise',
            'semantic_group' => '',
            'context_terms' => array(),
            'context_required' => $allow_promotion ? 0 : 1,
            'confidence' => $allow_promotion ? 0.72 : 0.60,
            'priority' => 1,
            'source' => 'linguista_l9_dependiente',
            'lesson_key' => self::LESSON_KEY,
            'validated' => 0,
            'active' => 0,
        ));
        if (!$id) {
            return array('evidence' => false, 'promoted' => false);
        }

        $evidence = SEO_Dependiente_Interprete_DB::add_evidence(
            $id,
            $allow_promotion ? 'dependiente_noise' : 'dependiente_residual',
            absint($source_id),
            hash('sha256', $question . '|' . $expression),
            self::LESSON_KEY,
            $allow_promotion ? 0.82 : 0.60
        );

        $promoted = false;
        if ($allow_promotion && !$was_active) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT evidence_count,active FROM {$table} WHERE id=%d",
                $id
            ), ARRAY_A);
            $evidence_count = absint($row['evidence_count'] ?? 0);
            if ($evidence_count >= self::PROMOTE_EVIDENCE && !self::has_conflicting_active_meaning($expression, $id)) {
                SEO_Dependiente_Interprete_DB::update_row($id, array(
                    'active' => 1,
                    'validated' => 1,
                    'context_required' => 0,
                    'confidence' => min(0.94, 0.84 + min(0.10, ($evidence_count - self::PROMOTE_EVIDENCE) * 0.02)),
                    'source' => 'linguista_l9_dependiente',
                ));
                $promoted = true;
            }
        }

        return array('evidence' => (bool) $evidence, 'promoted' => $promoted);
    }

    private static function has_conflicting_active_meaning($expression, $noise_id) {
        global $wpdb;
        $table = SEO_Dependiente_Interprete_DB::table();
        return absint($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE normalized_expression=%s
                AND id<>%d
                AND active=1
                AND relation_type NOT IN ('noise','ignore','stopword')",
            self::normalize($expression),
            absint($noise_id)
        ))) > 0;
    }

    private static function evidence_source_id($item) {
        $id = absint($item['source_id'] ?? 0);
        if ($id) {
            return $id;
        }
        $key = (string) ($item['source_key'] ?? $item['question'] ?? wp_json_encode($item));
        return max(1, (int) hexdec(substr(hash('sha256', $key), 0, 7)));
    }

    private static function significant_tokens($value) {
        $value = self::normalize($value);
        $out = array();
        foreach (explode(' ', $value) as $token) {
            if (strlen($token) < 3) {
                continue;
            }
            $out[] = $token;
        }
        return array_values(array_unique($out));
    }

    private static function contains_phrase($haystack, $needle) {
        $haystack = self::normalize($haystack);
        $needle = self::normalize($needle);
        return '' !== $needle && false !== strpos(' ' . $haystack . ' ', ' ' . $needle . ' ');
    }

    private static function normalize($value) {
        if (class_exists('SEO_Dependiente_V3_DB')) {
            return SEO_Dependiente_V3_DB::normalize((string) $value);
        }
        return SEO_Dependiente_Interprete_DB::normalize((string) $value);
    }
}
