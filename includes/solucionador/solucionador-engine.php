<?php
/**
 * Solucionador - motor de analisis y propuesta.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Engine {
    /**
     * Conserva como pregunta representativa la evidencia mas cercana al cliente.
     * Dependiente/Interprete prevalece sobre fuentes editoriales o sinteticas.
     */
    private static function representative_question($topic_id, $fallback = '') {
        $rows = SEO_Solucionador_DB::get_evidence_rows($topic_id);
        foreach (array('dependiente','analista','auditor','comentarista') as $source_type) {
            $best = '';
            $best_score = -1;
            foreach ($rows as $row) {
                if (sanitize_key((string) ($row['source_type'] ?? '')) !== $source_type) continue;
                $text = trim(wp_strip_all_tags((string) ($row['source_text'] ?? '')));
                if ($text === '') continue;
                $score = max(1, absint($row['occurrences'] ?? 1)) * max(0.1, (float) ($row['evidence_score'] ?? 1));
                if ($score > $best_score) {
                    $best = $text;
                    $best_score = $score;
                }
            }
            if ($best !== '') return sanitize_text_field($best);
        }
        return sanitize_text_field((string) $fallback);
    }

    private static function bump(array &$map, $key, $amount = 1) {
        $key = sanitize_key((string) $key) ?: 'unknown';
        $map[$key] = ($map[$key] ?? 0) + $amount;
    }

    public static function scan($days = 180) {
        global $wpdb;
        SEO_Solucionador_DB::maybe_install();

        // Evidence y cobertura son runtime derivado: se reconstruyen para que las
        // reglas nuevas eliminen propuestas antiguas que ya no son validas.
        SEO_Solucionador_DB::begin_scan();
        $coverage = SEO_Solucionador_Coverage::rebuild_post_index(5000);
        $sources = SEO_Solucionador_Sources::all($days);

        $accepted = 0;
        $discarded = 0;
        $reinforcement_skipped = 0;
        $seen_by_source = array();
        $accepted_by_source = array();
        $discarded_by_source = array();
        $origin_topics_by_key = array();

        foreach ($sources as $source) {
            $source_type = sanitize_key((string) ($source['source_type'] ?? '')) ?: 'unknown';
            self::bump($seen_by_source, $source_type);

            $profile = SEO_Solucionador_Normalizer::profile(
                (string) ($source['source_text'] ?? ''),
                (array) ($source['hints'] ?? array())
            );
            if (!$profile || SEO_Solucionador_Normalizer::is_weak_profile($profile)) {
                $discarded++;
                self::bump($discarded_by_source, $source_type);
                continue;
            }

            $key = (string) ($profile['canonical_key'] ?? '');
            $role = sanitize_key((string) ($source['proposal_role'] ?? 'origin')) ?: 'origin';
            $topic_id = 0;

            if ($role === 'reinforcement') {
                // Una senal de mercado/estructura no crea por si sola una pregunta.
                // Solo refuerza un tema originado en este mismo escaneo por una
                // consulta, comentario, gap editorial explicito o prueba conductual.
                $topic_id = absint($origin_topics_by_key[$key] ?? 0);
                if (!$topic_id) {
                    $reinforcement_skipped++;
                    $discarded++;
                    self::bump($discarded_by_source, $source_type);
                    continue;
                }
            } else {
                $topic_id = SEO_Solucionador_DB::upsert_topic(
                    $profile,
                    (string) ($source['source_text'] ?? '')
                );
                if ($topic_id) $origin_topics_by_key[$key] = $topic_id;
            }

            if (!$topic_id) {
                $discarded++;
                self::bump($discarded_by_source, $source_type);
                continue;
            }

            SEO_Solucionador_DB::add_evidence($topic_id, $source);
            $accepted++;
            self::bump($accepted_by_source, $source_type);
        }

        // Borra temas de ciclos anteriores que ya no tienen ninguna evidencia
        // vigente. Los temas con borrador asociado se conservan.
        $pruned_topics = SEO_Solucionador_DB::prune_orphan_topics();

        $topics_table = SEO_Solucionador_DB::topics_table();
        $topics = (array) $wpdb->get_results("SELECT * FROM {$topics_table} ORDER BY id ASC", ARRAY_A);
        foreach ($topics as $topic) {
            $topic_id = absint($topic['id'] ?? 0);
            if (!$topic_id) continue;

            $profile = array(
                'intent' => (string) ($topic['intent'] ?? ''),
                'action' => (string) ($topic['action_term'] ?? ''),
                'object' => (string) ($topic['object_term'] ?? ''),
                'condition' => (string) ($topic['condition_term'] ?? ''),
                'context' => (string) ($topic['context_term'] ?? ''),
                'canonical_key' => (string) ($topic['canonical_key'] ?? ''),
            );
            $stats = SEO_Solucionador_DB::topic_stats($topic_id);
            $representative_question = self::representative_question(
                $topic_id,
                (string) ($topic['canonical_question'] ?? '')
            );

            $draft_id = absint($topic['draft_post_id'] ?? 0);
            $draft_status = $draft_id ? get_post_status($draft_id) : false;
            if ($draft_id && $draft_status && $draft_status !== 'trash') {
                if ($draft_status === 'publish') {
                    SEO_Solucionador_DB::update_topic($topic_id, array(
                        'status' => 'covered',
                        'canonical_question' => $representative_question,
                        'coverage_status' => 'covered_exact',
                        'existing_post_id' => $draft_id,
                        'recommended_action' => 'no_action',
                        'evidence_total' => absint($stats['total'] ?? 0),
                        'interpreter_evidence' => absint($stats['dependiente'] ?? 0),
                        'comentarista_evidence' => absint($stats['comentarista'] ?? 0),
                        'analyst_evidence' => absint($stats['analista'] ?? 0),
                        'auditor_evidence' => absint($stats['auditor'] ?? 0),
                        'zero_result_evidence' => absint($stats['zero_results'] ?? 0),
                        'negative_feedback_evidence' => absint($stats['negative_feedback'] ?? 0),
                        'last_analyzed_at' => current_time('mysql'),
                    ));
                } else {
                    SEO_Solucionador_DB::update_topic($topic_id, array(
                        'status' => 'draft_created',
                        'canonical_question' => $representative_question,
                        'coverage_status' => 'draft_pending',
                        'recommended_action' => 'no_action',
                        'evidence_total' => absint($stats['total'] ?? 0),
                        'interpreter_evidence' => absint($stats['dependiente'] ?? 0),
                        'comentarista_evidence' => absint($stats['comentarista'] ?? 0),
                        'analyst_evidence' => absint($stats['analista'] ?? 0),
                        'auditor_evidence' => absint($stats['auditor'] ?? 0),
                        'zero_result_evidence' => absint($stats['zero_results'] ?? 0),
                        'negative_feedback_evidence' => absint($stats['negative_feedback'] ?? 0),
                        'last_analyzed_at' => current_time('mysql'),
                    ));
                }
                continue;
            }
            if ($draft_id && (!$draft_status || $draft_status === 'trash')) {
                SEO_Solucionador_DB::update_topic($topic_id, array('draft_post_id' => null));
            }

            $coverage_row = SEO_Solucionador_Coverage::find($profile);
            $coverage_status = sanitize_key((string) ($coverage_row['status'] ?? 'uncovered')) ?: 'uncovered';
            $existing_post_id = absint($coverage_row['post_id'] ?? 0);
            $priority = self::priority($stats, $coverage_status);
            $action = self::recommended_action($stats, $coverage_status);

            $status = (string) ($topic['status'] ?? 'candidate');
            if ($status !== 'dismissed') {
                if ($coverage_status === 'covered_exact') $status = 'covered';
                elseif ($action === 'observe') $status = 'observe';
                else $status = 'candidate';
            }

            $proposal = SEO_Solucionador_Catalog::build_proposal($topic_id, $profile);
            $wpdb->update($topics_table, array(
                'canonical_question' => $representative_question,
                'suggested_title' => SEO_Solucionador_Normalizer::suggested_title($profile),
                'proposed_vocabulary' => wp_json_encode(
                    (array) ($proposal['vocabulary'] ?? array()),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                'proposed_categories' => wp_json_encode(
                    (array) ($proposal['categories'] ?? array()),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                'coverage_status' => $coverage_status,
                'existing_post_id' => $existing_post_id ?: null,
                'recommended_action' => $action,
                'status' => $status,
                'priority_score' => $priority,
                'evidence_total' => absint($stats['total'] ?? 0),
                'interpreter_evidence' => absint($stats['dependiente'] ?? 0),
                'comentarista_evidence' => absint($stats['comentarista'] ?? 0),
                'analyst_evidence' => absint($stats['analista'] ?? 0),
                'auditor_evidence' => absint($stats['auditor'] ?? 0),
                'zero_result_evidence' => absint($stats['zero_results'] ?? 0),
                'negative_feedback_evidence' => absint($stats['negative_feedback'] ?? 0),
                'last_analyzed_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ), array('id' => $topic_id));
        }

        update_option('seo_solucionador_last_scan', array(
            'at' => time(),
            'days' => absint($days),
            'sources_seen' => count($sources),
            'accepted' => $accepted,
            'discarded' => $discarded,
            'reinforcement_skipped' => $reinforcement_skipped,
            'pruned_topics' => $pruned_topics,
            'seen_by_source' => $seen_by_source,
            'accepted_by_source' => $accepted_by_source,
            'discarded_by_source' => $discarded_by_source,
            'posts_indexed' => absint($coverage['posts'] ?? 0),
            'post_topics_indexed' => absint($coverage['topics'] ?? 0),
        ), false);

        return get_option('seo_solucionador_last_scan', array());
    }

    private static function priority(array $stats, $coverage_status) {
        $d = max(0, (int) ($stats['dependiente'] ?? 0));
        $c = max(0, (int) ($stats['comentarista'] ?? 0));
        $a = max(0, (int) ($stats['analista'] ?? 0));
        $u = max(0, (int) ($stats['auditor'] ?? 0));
        $z = max(0, (int) ($stats['zero_results'] ?? 0));
        $n = max(0, (int) ($stats['negative_feedback'] ?? 0));
        $score = 10
            + (14 * log(1 + $d))
            + (7 * log(1 + $c))
            + (6 * log(1 + $a))
            + (3 * log(1 + $u))
            + (5 * log(1 + $z))
            + (7 * log(1 + $n));
        if ($coverage_status === 'uncovered') $score += 12;
        elseif ($coverage_status === 'needs_expansion') $score += 8;
        elseif ($coverage_status === 'covered_parent') $score += 4;
        elseif ($coverage_status === 'covered_exact') $score -= 24;
        return round(max(0, min(100, $score)), 2);
    }

    private static function recommended_action(array $stats, $coverage_status) {
        $evidence = absint($stats['total'] ?? 0);
        $dependiente = absint($stats['dependiente'] ?? 0);
        $comentarista = absint($stats['comentarista'] ?? 0);
        $analista = absint($stats['analista'] ?? 0);
        $auditor = absint($stats['auditor'] ?? 0);

        if ($coverage_status === 'covered_exact') return 'no_action';
        if ($coverage_status === 'covered_parent') return 'create_section';
        if (in_array($coverage_status, array('covered_partial','needs_expansion'), true)) return 'expand_existing_post';

        if ($coverage_status === 'uncovered') {
            // Una unica consulta real con cero resultados/feedback puede quedar en
            // observacion; la propuesta pasa a crear post con repeticion o cruce
            // independiente de fuentes.
            if ($dependiente >= 2) return 'create_post';
            if ($dependiente >= 1 && ($analista >= 1 || $comentarista >= 1 || $auditor >= 1)) return 'create_post';
            // Comentarista es evidencia secundaria. Varias reviews del mismo o
            // de distintos productos no justifican por si solas un nuevo post.
            // Hace falta una pregunta real o un gap editorial independiente.
            if (($analista + $auditor) >= 2 && $evidence >= 2) return 'create_post';
        }
        return 'observe';
    }
}
