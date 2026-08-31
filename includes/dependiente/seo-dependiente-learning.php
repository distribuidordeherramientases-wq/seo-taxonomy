<?php

defined('ABSPATH') || exit;

/**
 * Aprendizaje supervisado de Dependiente.
 *
 * Principio: una consulta o un clic no se convierten directamente en una regla
 * activa. El sistema acumula evidencia y escribe candidatos inactivos en
 * wp_seo_dependiente_semantics. La activacion se hace posteriormente mediante
 * revision o una politica de promocion explicita.
 */
final class SEO_Dependiente_Learning {
    const VERSION = '2026-08-31.2';

    /**
     * Tras una busqueda, compara con la busqueda anterior de la misma sesion.
     * Si el usuario reformula y ahora aparece un concepto antes desconocido,
     * genera un candidato de sinonimo con evidencia fuerte.
     */
    public static function observe_search($log_id) {
        global $wpdb;

        $log_id = absint($log_id);
        if (!$log_id || !self::ready() || !class_exists('SEO_Dependiente_Search_Log')) {
            return array();
        }

        $current = SEO_Dependiente_Search_Log::get_search($log_id);
        if (!$current || empty($current['session_hash'])) {
            return array();
        }

        $log_table = SEO_Dependiente_Search_Log::table();
        $previous = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$log_table}
                 WHERE session_hash = %s
                   AND request_kind = 'search'
                   AND id < %d
                   AND created_at >= DATE_SUB(%s, INTERVAL 30 MINUTE)
                 ORDER BY id DESC
                 LIMIT 1",
                (string) $current['session_hash'],
                $log_id,
                current_time('mysql')
            ),
            ARRAY_A
        );
        if (!$previous) {
            return array();
        }

        $unknown = self::filter_unknown_terms(self::decode_json($previous['unresolved_terms'] ?? ''));
        if (!$unknown) {
            return array();
        }

        $created = array();
        $same_object = self::same_nonempty($previous['detected_object'] ?? '', $current['detected_object'] ?? '');
        $same_intent = self::same_nonempty($previous['detected_intent'] ?? '', $current['detected_intent'] ?? '');

        // Ejemplo: "apañar un grifo" -> "reparar un grifo".
        if (empty($previous['detected_intent']) && !empty($current['detected_intent']) && $same_object) {
            foreach (array_slice($unknown, 0, 3) as $term) {
                $candidate = self::upsert_candidate(array(
                    'expression'           => $term,
                    'canonical_expression' => (string) $current['detected_intent'],
                    'semantic_role'        => 'intent',
                    'relation_type'        => 'synonym',
                    'evidence_type'        => 'reformulation',
                    'session_hash'         => (string) $current['session_hash'],
                    'query_example'        => (string) $previous['query_original'] . ' -> ' . (string) $current['query_original'],
                    'source_log_id'        => absint($previous['id']),
                ));
                if ($candidate) {
                    $created[] = $candidate;
                }
            }
        }

        // Ejemplo: "quiero una radial" -> "quiero una amoladora" con misma intencion.
        if (empty($previous['detected_object']) && !empty($current['detected_object']) && $same_intent) {
            foreach (array_slice($unknown, 0, 3) as $term) {
                $candidate = self::upsert_candidate(array(
                    'expression'           => $term,
                    'canonical_expression' => (string) $current['detected_object'],
                    'semantic_role'        => 'object',
                    'relation_type'        => 'synonym',
                    'evidence_type'        => 'reformulation',
                    'session_hash'         => (string) $current['session_hash'],
                    'query_example'        => (string) $previous['query_original'] . ' -> ' . (string) $current['query_original'],
                    'source_log_id'        => absint($previous['id']),
                ));
                if ($candidate) {
                    $created[] = $candidate;
                }
            }
        }

        if ($created) {
            SEO_Dependiente_Search_Log::merge_learning_candidates(absint($previous['id']), $created);
        }
        return $created;
    }

    /**
     * Un clic es evidencia de interes, no una equivalencia semantica.
     * Se toma el vocabulario real del producto pulsado y se asocia a los
     * terminos de la consulta que Dependiente no entendio. Esas asociaciones
     * se guardan siempre como candidatos active=0.
     */
    public static function observe_click($search_uuid, $product_id, $position = 0) {
        $product_id = absint($product_id);
        if (!$product_id || !self::ready() || !class_exists('SEO_Dependiente_Search_Log')) {
            return array();
        }

        $log = SEO_Dependiente_Search_Log::get_search($search_uuid);
        if (!$log) {
            return array();
        }

        $unknown = self::filter_unknown_terms(self::decode_json($log['unresolved_terms'] ?? ''));
        if (!$unknown) {
            return array();
        }

        $vocabulary = self::product_vocabulary($product_id);
        if (!$vocabulary) {
            return array();
        }

        $created = array();
        foreach (array_slice($unknown, 0, 3) as $term) {
            foreach (array_slice($vocabulary, 0, 8) as $concept) {
                $role = self::role_for_group((string) ($concept['semantic_group'] ?? ''));
                if (!$role) {
                    continue;
                }

                $canonical = self::normalize((string) ($concept['slug'] ?: $concept['label']));
                if (!$canonical || $canonical === $term) {
                    continue;
                }

                $candidate = self::upsert_candidate(array(
                    'expression'           => $term,
                    'canonical_expression' => $canonical,
                    'semantic_role'        => $role,
                    'relation_type'        => 'related',
                    'evidence_type'        => 'click',
                    'session_hash'         => (string) ($log['session_hash'] ?? ''),
                    'product_id'           => $product_id,
                    'position'             => absint($position),
                    'query_example'        => (string) ($log['query_original'] ?? ''),
                    'source_log_id'        => absint($log['id'] ?? 0),
                    'target_vocabulary_id' => absint($concept['id'] ?? 0),
                    'target_group'         => sanitize_key((string) ($concept['semantic_group'] ?? '')),
                    'target_slug'          => self::normalize((string) ($concept['slug'] ?? '')),
                    'target_label'         => sanitize_text_field((string) ($concept['label'] ?? '')),
                    'catalog_confidence'   => isset($concept['confidence']) && is_numeric($concept['confidence'])
                        ? (float) $concept['confidence']
                        : null,
                ));
                if ($candidate) {
                    $created[] = $candidate;
                }
            }
        }

        if ($created) {
            SEO_Dependiente_Search_Log::merge_learning_candidates(absint($log['id']), $created);
        }
        return $created;
    }

    /**
     * Una respuesta a la pregunta de aclaracion es evidencia explicita del
     * significado de la consulta. Es mas fuerte que un clic, pero sigue
     * entrando como candidato inactivo para evitar aprendizajes irreversibles.
     *
     * Importante: la aclaracion confirma la NECESIDAD de la consulta, no que
     * cada palabra desconocida sea un sinonimo literal. Por eso las
     * confirmaciones de intencion se guardan normalmente como "implies" y las
     * reformulaciones posteriores son las que pueden elevarlas a "synonym".
     */
    public static function observe_clarification($search_uuid, $clarification) {
        if (!self::ready() || !class_exists('SEO_Dependiente_Search_Log')) {
            return array();
        }

        $log = SEO_Dependiente_Search_Log::get_search($search_uuid);
        if (!$log || !is_array($clarification)) {
            return array();
        }

        $role = sanitize_key((string) ($clarification['role'] ?? ''));
        $canonical = self::normalize((string) ($clarification['value'] ?? ''));
        $source = sanitize_key((string) ($clarification['source'] ?? 'closed_option')) ?: 'closed_option';
        if (!$canonical || !in_array($role, array('intent','object','context','state','term'), true)) {
            return array();
        }

        // Si el usuario eligio "Otro", intentamos primero interpretar el texto
        // libre. Si no conduce a un concepto conocido, queda registrado en el
        // log pero no inventamos una equivalencia semantica.
        if ('other_text' === $source && class_exists('SEO_Dependiente_Semantics')) {
            $other_analysis = SEO_Dependiente_Semantics::analyze($canonical);
            $known = array_values(array_filter((array) ($other_analysis['concepts'][$role] ?? array())));
            if ($known) {
                $canonical = self::normalize((string) $known[0]);
            } elseif ('term' !== $role) {
                return array();
            }
        }

        $unknown = self::filter_unknown_terms(self::decode_json($log['unresolved_terms'] ?? ''));
        if (!$unknown) {
            return array();
        }

        // Con varias palabras desconocidas conservamos la expresion como frase
        // en lugar de afirmar que cada token es sinonimo del concepto elegido.
        $expressions = count($unknown) === 1
            ? array($unknown[0])
            : array(implode(' ', array_slice($unknown, 0, 4)));

        $relation = 'related';
        if ('intent' === $role || 'state' === $role) {
            $relation = 'implies';
        } elseif ('object' === $role && count($unknown) === 1) {
            $relation = 'synonym';
        }

        $created = array();
        foreach ($expressions as $expression) {
            $candidate = self::upsert_candidate(array(
                'expression'            => $expression,
                'canonical_expression'  => $canonical,
                'semantic_role'         => 'state' === $role ? 'intent' : $role,
                'relation_type'         => $relation,
                'evidence_type'         => 'clarification',
                'clarification_source'  => $source,
                'session_hash'          => (string) ($log['session_hash'] ?? ''),
                'query_example'         => (string) ($log['query_original'] ?? ''),
                'source_log_id'         => absint($log['id'] ?? 0),
                'target_group'          => sanitize_key((string) ($clarification['source_group'] ?? '')),
                'target_slug'           => self::normalize((string) ($clarification['source_slug'] ?? '')),
                'target_label'          => sanitize_text_field((string) ($clarification['label'] ?? $canonical)),
            ));
            if ($candidate) {
                $created[] = $candidate;
            }
        }

        if ($created) {
            SEO_Dependiente_Search_Log::merge_learning_candidates(absint($log['id']), $created);
        }
        return $created;
    }

    /**
     * Lee el vocabulario canonico del producto pulsado. Esto es lo que permite
     * pasar de "el usuario hizo clic" a "el usuario mostro interes por un
     * producto clasificado como TIPO/SUBTIPO/APLICACION X".
     */
    public static function product_vocabulary($product_id) {
        global $wpdb;

        $product_id = absint($product_id);
        if (!$product_id) {
            return array();
        }

        $object_table = $wpdb->prefix . 'seo_object_vocabulary';
        $vocabulary_table = $wpdb->prefix . 'seo_vocabulary';
        if (!self::table_exists($object_table) || !self::table_exists($vocabulary_table)) {
            return array();
        }

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT v.id, v.semantic_group, v.slug, v.label, ov.confidence
                 FROM {$object_table} ov
                 INNER JOIN {$vocabulary_table} v ON v.id = ov.vocabulary_id
                 WHERE ov.object_type = 'product'
                   AND ov.object_id = %d
                   AND ov.status = 1
                   AND v.active = 1
                   AND v.semantic_group IN ('tipo','subtipo','aplicacion','plataforma')
                 ORDER BY
                   CASE v.semantic_group
                     WHEN 'tipo' THEN 1
                     WHEN 'subtipo' THEN 2
                     WHEN 'aplicacion' THEN 3
                     WHEN 'plataforma' THEN 4
                     ELSE 9
                   END,
                   COALESCE(ov.confidence, 0) DESC,
                   v.id ASC
                 LIMIT 12",
                $product_id
            ),
            ARRAY_A
        );

        $seen = array();
        $out = array();
        foreach ($rows as $row) {
            $key = sanitize_key((string) ($row['semantic_group'] ?? '')) . '|' . self::normalize((string) ($row['slug'] ?? ''));
            if (!$key || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Activa un candidato ya acumulado en wp_seo_dependiente_semantics.
     * No se llama automaticamente desde los clics.
     */
    public static function approve_candidate($semantic_id, $reviewer_id = 0) {
        global $wpdb;

        $semantic_id = absint($semantic_id);
        if (!$semantic_id || !self::ready()) {
            return new WP_Error('seo_dependiente_candidate_invalid', 'Candidato semantico no valido.');
        }

        $table = SEO_Dependiente_Semantics::table();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $semantic_id),
            ARRAY_A
        );
        if (!$row || 'learned_candidate' !== (string) ($row['source'] ?? '')) {
            return new WP_Error('seo_dependiente_candidate_not_found', 'No se ha encontrado un candidato pendiente.');
        }

        $metadata = self::decode_json($row['metadata'] ?? '');
        $metadata['reviewed_by'] = absint($reviewer_id);
        $metadata['reviewed_at'] = current_time('mysql');

        $ok = $wpdb->update(
            $table,
            array(
                'source'     => 'learned',
                'active'     => 1,
                'metadata'   => self::json($metadata),
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $semantic_id)
        );

        return false === $ok
            ? new WP_Error('seo_dependiente_candidate_approve_failed', 'No se pudo activar el candidato.')
            : true;
    }

    /**
     * Crea o refuerza un candidato semantico. La clave es estable para que la
     * misma hipotesis acumule evidencia de muchos logs, sesiones y productos.
     */
    private static function upsert_candidate($data) {
        global $wpdb;

        $expression = self::normalize((string) ($data['expression'] ?? ''));
        $canonical = self::normalize((string) ($data['canonical_expression'] ?? ''));
        $role = sanitize_key((string) ($data['semantic_role'] ?? ''));
        if (!$expression || !$canonical || $expression === $canonical || !$role) {
            return null;
        }

        $table = SEO_Dependiente_Semantics::table();

        // Si ya existe una regla activa equivalente, no generamos otro candidato.
        $active_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table}
                 WHERE normalized_expression = %s
                   AND canonical_expression = %s
                   AND semantic_role = %s
                   AND active = 1
                 LIMIT 1",
                $expression,
                $canonical,
                $role
            )
        );
        if ($active_id) {
            return null;
        }

        $rule_key = sanitize_key('candidate-' . $role . '-' . substr(hash('sha256', $expression . '|' . $canonical . '|' . $role), 0, 24));
        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE rule_key = %s LIMIT 1", $rule_key),
            ARRAY_A
        );

        $metadata = $existing ? self::decode_json($existing['metadata'] ?? '') : array();
        if (!isset($metadata['learning']) || !is_array($metadata['learning'])) {
            $metadata['learning'] = array();
        }
        $learning =& $metadata['learning'];

        $learning['clicks'] = absint($learning['clicks'] ?? 0);
        $learning['clarifications'] = absint($learning['clarifications'] ?? 0);
        $learning['reformulations'] = absint($learning['reformulations'] ?? 0);
        $learning['session_hashes'] = array_values(array_filter((array) ($learning['session_hashes'] ?? array())));
        $learning['product_ids'] = array_values(array_filter(array_map('absint', (array) ($learning['product_ids'] ?? array()))));
        $learning['positions'] = array_values(array_filter(array_map('absint', (array) ($learning['positions'] ?? array()))));
        $learning['examples'] = array_values(array_filter(array_map('strval', (array) ($learning['examples'] ?? array()))));
        $learning['evidence_types'] = array_values(array_filter(array_map('sanitize_key', (array) ($learning['evidence_types'] ?? array()))));

        $evidence_type = sanitize_key((string) ($data['evidence_type'] ?? 'observation')) ?: 'observation';
        if ('click' === $evidence_type) {
            $learning['clicks']++;
        }
        if ('clarification' === $evidence_type) {
            $learning['clarifications']++;
        }
        if ('reformulation' === $evidence_type) {
            $learning['reformulations']++;
        }
        $learning['evidence_types'][] = $evidence_type;
        $learning['evidence_types'] = array_values(array_unique($learning['evidence_types']));

        $session_hash = preg_replace('/[^a-f0-9]/i', '', (string) ($data['session_hash'] ?? ''));
        if ($session_hash) {
            $learning['session_hashes'][] = substr($session_hash, 0, 64);
            $learning['session_hashes'] = array_slice(array_values(array_unique($learning['session_hashes'])), -30);
        }

        $product_id = absint($data['product_id'] ?? 0);
        if ($product_id) {
            $learning['product_ids'][] = $product_id;
            $learning['product_ids'] = array_slice(array_values(array_unique($learning['product_ids'])), -30);
        }

        $position = absint($data['position'] ?? 0);
        if ($position) {
            $learning['positions'][] = $position;
            $learning['positions'] = array_slice($learning['positions'], -30);
        }

        $example = sanitize_text_field((string) ($data['query_example'] ?? ''));
        if ($example) {
            $learning['examples'][] = self::substr($example, 300);
            $learning['examples'] = array_slice(array_values(array_unique($learning['examples'])), -8);
        }

        $learning['first_seen'] = (string) ($learning['first_seen'] ?? current_time('mysql'));
        $learning['last_seen'] = current_time('mysql');
        $learning['last_log_id'] = absint($data['source_log_id'] ?? 0);
        if (!empty($data['clarification_source'])) {
            $learning['clarification_sources'] = array_values(array_unique(array_filter(array_merge(
                (array) ($learning['clarification_sources'] ?? array()),
                array(sanitize_key((string) $data['clarification_source']))
            ))));
        }
        if (!empty($data['target_label'])) {
            $learning['target_label'] = sanitize_text_field((string) $data['target_label']);
        }
        if (isset($data['catalog_confidence']) && is_numeric($data['catalog_confidence'])) {
            $learning['catalog_confidence'] = round(min(1, max(0, (float) $data['catalog_confidence'])), 4);
        }

        $confidence = self::candidate_confidence($learning);
        $relation = self::best_relation(
            (string) ($existing['relation_type'] ?? ''),
            sanitize_key((string) ($data['relation_type'] ?? 'related'))
        );
        // Una reformulacion controlada puede proponer sinonimo; un clic solo, no.
        if (absint($learning['reformulations'] ?? 0) > 0) {
            $relation = 'synonym';
        }

        $row = array(
            'rule_key'              => $rule_key,
            'rule_type'             => in_array($relation, array('synonym','variant'), true) ? 'alias' : ('implies' === $relation ? 'implication' : 'association'),
            'expression'            => $expression,
            'normalized_expression' => $expression,
            'canonical_expression'  => $canonical,
            'match_type'            => false !== strpos($expression, ' ') ? 'phrase' : 'token',
            'semantic_role'         => $role,
            'target_vocabulary_id'  => absint($data['target_vocabulary_id'] ?? ($existing['target_vocabulary_id'] ?? 0)) ?: null,
            'target_group'          => sanitize_key((string) ($data['target_group'] ?? ($existing['target_group'] ?? ''))) ?: null,
            'target_slug'           => self::normalize((string) ($data['target_slug'] ?? ($existing['target_slug'] ?? ''))) ?: null,
            'relation_type'         => $relation,
            'result_role'           => 'candidate',
            'weight'                => max(1, min(100, (int) round(30 + (50 * $confidence)))),
            'priority'              => 1,
            'confidence'            => $confidence,
            'language'              => 'es',
            'source'                => 'learned_candidate',
            'metadata'              => self::json($metadata),
            'active'                => 0,
            'updated_at'            => current_time('mysql'),
        );

        if ($existing) {
            if ('learned_candidate' !== (string) ($existing['source'] ?? '') || !empty($existing['active'])) {
                return null;
            }
            $ok = $wpdb->update($table, $row, array('id' => absint($existing['id'])));
            if (false === $ok) {
                return null;
            }
            $id = absint($existing['id']);
        } else {
            $row['created_at'] = current_time('mysql');
            if (false === $wpdb->insert($table, $row)) {
                return null;
            }
            $id = absint($wpdb->insert_id);
        }

        return array(
            'semantic_candidate_id' => $id,
            'expression'            => $expression,
            'canonical_expression'  => $canonical,
            'semantic_role'         => $role,
            'relation_type'         => $relation,
            'confidence'            => $confidence,
            'evidence'              => $evidence_type,
            'target_group'          => $row['target_group'],
            'target_slug'           => $row['target_slug'],
        );
    }

    private static function candidate_confidence($learning) {
        $clicks = absint($learning['clicks'] ?? 0);
        $clarifications = absint($learning['clarifications'] ?? 0);
        $reforms = absint($learning['reformulations'] ?? 0);
        $sessions = count(array_unique((array) ($learning['session_hashes'] ?? array())));
        $products = count(array_unique(array_map('absint', (array) ($learning['product_ids'] ?? array()))));

        // Jerarquia de evidencia:
        // clic < confirmacion explicita < reformulacion que aclara el concepto.
        // Ninguna de ellas activa automaticamente una regla.
        $score = 0.18;
        $score += min(0.18, 0.035 * $sessions);
        $score += min(0.10, 0.025 * $products);
        $score += min(0.07, 0.007 * $clicks);
        if ($clarifications > 0) {
            $score += min(0.38, 0.26 + (0.04 * $clarifications));
        }
        if ($reforms > 0) {
            $score += min(0.45, 0.30 + (0.05 * $reforms));
        }
        return round(min(0.97, max(0.10, $score)), 4);
    }

    private static function best_relation($existing, $incoming) {
        $rank = array('' => 0, 'related' => 1, 'implies' => 2, 'variant' => 3, 'synonym' => 4);
        $existing = sanitize_key($existing);
        $incoming = sanitize_key($incoming);
        return ($rank[$incoming] ?? 0) >= ($rank[$existing] ?? 0) ? ($incoming ?: 'related') : ($existing ?: 'related');
    }

    private static function filter_unknown_terms($terms) {
        $stop = array_fill_keys(array(
            'quiero','quisiera','necesito','necesitamos','busco','buscar','tengo','tener','para','con','sin',
            'una','uno','unos','unas','del','las','los','que','por','porque','como','me','se','ha','he','mi',
            'el','la','un','de','a','al','y','o','es','esta','este','esto','algo','hacer','hace'
        ), true);

        $out = array();
        foreach ((array) $terms as $term) {
            $term = self::normalize((string) $term);
            if (!$term || isset($stop[$term]) || strlen($term) < 3 || preg_match('/^\d+$/', $term)) {
                continue;
            }
            $out[$term] = true;
        }
        return array_keys($out);
    }

    private static function role_for_group($group) {
        $group = sanitize_key($group);
        if (in_array($group, array('tipo','subtipo'), true)) {
            return 'object';
        }
        if (in_array($group, array('aplicacion','plataforma'), true)) {
            return 'context';
        }
        return '';
    }

    private static function ready() {
        return class_exists('SEO_Dependiente_Semantics') && SEO_Dependiente_Semantics::table_exists();
    }

    private static function table_exists($table) {
        global $wpdb;
        return $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    }

    private static function same_nonempty($a, $b) {
        return '' !== trim((string) $a) && '' !== trim((string) $b) && (string) $a === (string) $b;
    }

    private static function normalize($value) {
        if (class_exists('SEO_Dependiente_Semantics')) {
            return SEO_Dependiente_Semantics::normalize((string) $value);
        }
        $value = remove_accents(wp_strip_all_tags((string) $value));
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9\s-]+/', ' ', $value);
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private static function decode_json($value) {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || '' === trim($value)) {
            return array();
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : array();
    }

    private static function json($value) {
        return wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function substr($value, $length) {
        $value = (string) $value;
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }
}
