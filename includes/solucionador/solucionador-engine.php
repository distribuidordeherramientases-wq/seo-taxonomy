<?php
/**
 * Solucionador - motor de decision editorial RF v1.0.
 *
 * Principio: servicios especialistas -> evidencia -> tema canonico -> cobertura
 * -> decision minima -> brief. No consulta Internet, no redacta y no publica.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Engine {
    const SCAN_STATE_OPTION = 'seo_solucionador_scan_state';
    const BATCH_SIZE = 200;
    const MAX_BATCHES_PER_REQUEST = 20;
    private static function representative_question($topic_id, $fallback = '') {
        $rows = SEO_Solucionador_DB::get_evidence_rows($topic_id);
        foreach (array('dependiente','analista','auditor','comentarista','ojeador','comparador','ingeniero','clasificador') as $source_type) {
            $best = '';
            $best_score = -1;
            foreach ($rows as $row) {
                if (sanitize_key((string) ($row['source_type'] ?? '')) !== $source_type) continue;
                $text = trim(wp_strip_all_tags((string) ($row['source_text'] ?? '')));
                if ($text === '') continue;
                $score = max(1,absint($row['occurrences'] ?? 1))
                    * max(0.1,(float) ($row['evidence_score'] ?? 1))
                    * max(0.2,(float) ($row['confidence'] ?? 0.6));
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

    private static function category_name($term_id) {
        $term = get_term(absint($term_id),'product_cat');
        return ($term && !is_wp_error($term)) ? (string) $term->name : '';
    }

    /**
     * Agrupa preguntas de compra/eleccion de una misma categoria en un tema
     * canonico. Problemas/procedimientos conservan accion/condicion para no
     * mezclar necesidades tecnicas diferentes.
     */
    private static function canonicalize_profile(array $profile, array $source = array()) {
        $category_id = absint($source['category_id'] ?? $profile['category_id'] ?? 0);
        if ($category_id) $profile['category_id'] = $category_id;

        $intent = sanitize_key((string) ($profile['intent'] ?? ''));
        $key_intent = sanitize_key((string) ($profile['key_intent'] ?? ''));
        $text = SEO_Solucionador_Normalizer::normalize((string) ($source['source_text'] ?? ''));

        $source_meta = is_array($source['source_meta'] ?? null) ? $source['source_meta'] : array();
        $dependiente_channel = sanitize_key((string) ($source_meta['dependiente_channel'] ?? ''));
        $is_academy_dossier = sanitize_key((string) ($source['source_type'] ?? '')) === 'dependiente'
            && $dependiente_channel === 'academy_learned_dossier';

        $decision_language = (bool) preg_match('/\b(elegir|eleccion|comprar|compra|diferencia|comparar|comparativa|que .* necesito|cual .* necesito|potencia|cable|bateria|par)\b/u',$text);
        $is_comparison_profile = sanitize_key((string) ($source['source_type'] ?? '')) === 'comparador'
            && $intent === 'comparison';
        $is_decision = $key_intent === 'decision'
            || in_array($intent,array('decision','choice','comparison','buying_guide','eleccion'),true)
            || $decision_language;

        if ($category_id && $is_academy_dossier) {
            $name = trim((string) ($source['category_name'] ?? $source_meta['category_name'] ?? ''));
            if ($name === '') $name = self::category_name($category_id);
            if ($name !== '') $profile['object'] = SEO_Solucionador_Normalizer::normalize($name);
            $profile['intent'] = 'dependiente_qa_basic';
            $profile['key_intent'] = 'dependiente_qa_basic';
            $profile['action'] = 'resolver';
            $profile['condition'] = '';
            $profile['context'] = '';
            $profile['category_id'] = $category_id;
            $profile['confidence'] = max(0.90,(float) ($profile['confidence'] ?? 0));
            $profile['canonical_key'] = 'dependiente-qa-basic|resolver|category-' . $category_id . '|general|general';
        } elseif ($category_id && $is_comparison_profile && empty($profile['condition'])) {
            $name = trim((string) ($source['category_name'] ?? ''));
            if ($name === '') $name = self::category_name($category_id);
            if ($name !== '') $profile['object'] = SEO_Solucionador_Normalizer::normalize($name);
            $profile['intent'] = 'comparison';
            $profile['key_intent'] = 'comparison';
            $profile['action'] = 'comparar';
            $profile['condition'] = '';
            $profile['context'] = '';
            $profile['canonical_key'] = 'comparison|comparar|category-' . $category_id . '|general|general';
        } elseif ($category_id && $is_decision && empty($profile['condition'])) {
            $name = trim((string) ($source['category_name'] ?? ''));
            if ($name === '') $name = self::category_name($category_id);
            if ($name !== '') $profile['object'] = SEO_Solucionador_Normalizer::normalize($name);
            $profile['intent'] = 'decision';
            $profile['key_intent'] = 'decision';
            $profile['action'] = 'elegir';
            $profile['condition'] = '';
            $profile['context'] = '';
            $profile['canonical_key'] = 'decision|elegir|category-' . $category_id . '|general|general';
        }
        return $profile;
    }

    private static function suggested_title(array $profile) {
        if (
            sanitize_key((string) ($profile['intent'] ?? '')) === 'dependiente_qa_basic'
            && !empty($profile['category_id'])
        ) {
            $name = self::category_name(absint($profile['category_id']));
            if ($name === '') $name = trim((string) ($profile['object'] ?? ''));
            if ($name !== '') {
                return $name . ': preguntas habituales sobre elección, uso y compatibilidad';
            }
        }
        return SEO_Solucionador_Normalizer::suggested_title($profile);
    }

    private static function origin_match(array $profile, array $source, array $origins, array $by_key, array $by_category) {
        $key = (string) ($profile['canonical_key'] ?? '');
        if ($key !== '' && !empty($by_key[$key])) return absint($by_key[$key]);

        $category_id = absint($source['category_id'] ?? $profile['category_id'] ?? 0);
        $candidate_ids = $category_id && !empty($by_category[$category_id])
            ? (array) $by_category[$category_id]
            : array_keys($origins);

        $best_id = 0;
        $best = 0.0;
        foreach ($candidate_ids as $topic_id) {
            $origin = $origins[$topic_id] ?? array();
            if (!$origin) continue;
            $score = 0.0;
            $origin_cat = absint($origin['category_id'] ?? 0);
            if ($category_id && $origin_cat === $category_id) $score += 0.52;
            $score += 0.28 * SEO_Solucionador_Normalizer::similarity(
                (string) ($profile['object'] ?? ''),
                (string) ($origin['object'] ?? '')
            );
            if ((string) ($profile['action'] ?? '') !== '' && (string) ($profile['action'] ?? '') === (string) ($origin['action'] ?? '')) $score += 0.12;
            if ((string) ($profile['key_intent'] ?? '') !== '' && (string) ($profile['key_intent'] ?? '') === (string) ($origin['key_intent'] ?? '')) $score += 0.08;
            if ($score > $best) {
                $best = $score;
                $best_id = absint($topic_id);
            }
        }
        return $best >= 0.56 ? $best_id : 0;
    }

    private static function primary_category_id($topic_id, array $proposal, array $profile) {
        $scores = array();
        $profile_category = absint($profile['category_id'] ?? 0);
        if ($profile_category) $scores[$profile_category] = 20.0;

        foreach (SEO_Solucionador_DB::get_evidence_rows($topic_id) as $row) {
            $term_id = absint($row['category_id'] ?? 0);
            if (!$term_id) continue;
            $scores[$term_id] = ($scores[$term_id] ?? 0)
                + max(0.2,(float) ($row['evidence_score'] ?? 1))
                * max(1,absint($row['occurrences'] ?? 1));
        }
        foreach ((array) ($proposal['categories'] ?? array()) as $index=>$row) {
            $term_id = absint($row['id'] ?? $row['term_id'] ?? 0);
            if (!$term_id) continue;
            $scores[$term_id] = ($scores[$term_id] ?? 0)
                + max(0.5,(float) ($row['score'] ?? (4 - $index)));
        }
        if (!$scores) return 0;
        arsort($scores,SORT_NUMERIC);
        return absint(array_key_first($scores));
    }

    private static function hierarchy($term_id) {
        global $wpdb;
        $term_id = absint($term_id);
        $out = array(
            'category'=>array('id'=>$term_id,'name'=>self::category_name($term_id)),
            'hub_secondary'=>array(),
            'hub_primary'=>array(),
            'cluster'=>array(),
        );
        if (!$term_id) return $out;

        $relations = $wpdb->prefix . 'seo_relations';
        if (!SEO_Solucionador_DB::table_exists($relations)) return $out;

        $secondary = $wpdb->get_row($wpdb->prepare(
            "SELECT r.source_id id,p.post_title name
             FROM {$relations} r
             LEFT JOIN {$wpdb->posts} p ON p.ID=r.source_id
             WHERE r.target_type='product_cat' AND r.target_id=%d
               AND r.relation_type='hub_secondary_to_category'
             ORDER BY r.id ASC LIMIT 1",
            $term_id
        ),ARRAY_A);
        if (!$secondary) return $out;
        $out['hub_secondary'] = array('id'=>absint($secondary['id'] ?? 0),'name'=>(string) ($secondary['name'] ?? ''));

        $primary = $wpdb->get_row($wpdb->prepare(
            "SELECT r.source_id id,p.post_title name
             FROM {$relations} r
             LEFT JOIN {$wpdb->posts} p ON p.ID=r.source_id
             WHERE r.target_id=%d
               AND r.relation_type IN ('hub_primary_to_hub_secondary','hub_primary_to_secondary')
             ORDER BY r.id ASC LIMIT 1",
            absint($secondary['id'] ?? 0)
        ),ARRAY_A);
        if (!$primary) return $out;
        $out['hub_primary'] = array('id'=>absint($primary['id'] ?? 0),'name'=>(string) ($primary['name'] ?? ''));

        $cluster = $wpdb->get_row($wpdb->prepare(
            "SELECT r.source_id id,p.post_title name
             FROM {$relations} r
             LEFT JOIN {$wpdb->posts} p ON p.ID=r.source_id
             WHERE r.target_id=%d
               AND r.relation_type IN ('cluster_to_primary','cluster_to_hub_primary')
             ORDER BY r.id ASC LIMIT 1",
            absint($primary['id'] ?? 0)
        ),ARRAY_A);
        if ($cluster) $out['cluster'] = array('id'=>absint($cluster['id'] ?? 0),'name'=>(string) ($cluster['name'] ?? ''));
        return $out;
    }

    private static function knowledge_status($term_id, array $stats) {
        $term_id = absint($term_id);
        $best_confidence = 0.0;
        $active_count = 0;

        if ($term_id && class_exists('SEO_Ingeniero') && method_exists('SEO_Ingeniero','active_knowledge')) {
            foreach ((array) SEO_Ingeniero::active_knowledge($term_id) as $row) {
                $active_count++;
                $best_confidence = max($best_confidence,(float) ($row['confidence'] ?? 0));
            }
        }
        if ($active_count > 0 && $best_confidence >= 0.55) {
            return array('status'=>'sufficient','count'=>$active_count,'confidence'=>round($best_confidence,3));
        }
        if (absint($stats['ingeniero'] ?? 0) > 0) {
            return array('status'=>'sufficient','count'=>absint($stats['ingeniero']),'confidence'=>0.60);
        }
        return array('status'=>'insufficient','count'=>$active_count,'confidence'=>round($best_confidence,3));
    }

    private static function risks(array $coverage, $primary_category_id) {
        $status = sanitize_key((string) ($coverage['status'] ?? 'uncovered'));
        $dup = 0.0;
        if ($status === 'conflict') $dup = 100;
        elseif ($status === 'duplicate') $dup = 95;
        elseif ($status === 'covered') $dup = 82;
        elseif ($status === 'partial_coverage') $dup = 58;
        elseif ($status === 'weak_coverage') $dup = 28;

        $same_category_urls = array();
        foreach ((array) ($coverage['matches'] ?? array()) as $row) {
            if (absint($primary_category_id) && absint($row['category_id'] ?? 0) === absint($primary_category_id)) {
                $same_category_urls[(string) ($row['entity_type'] ?? '') . ':' . absint($row['entity_id'] ?? 0)] = true;
            }
        }
        $cann = $dup;
        if (count($same_category_urls) >= 2) $cann = max($cann,88);
        elseif (count($same_category_urls) === 1 && $status !== 'uncovered') $cann = max($cann,62);

        return array(
            'duplication_risk'=>round(min(100,$dup),2),
            'cannibalization_risk'=>round(min(100,$cann),2),
        );
    }

    private static function evidence_gate(array $stats, array $profile = array()) {
        if (sanitize_key((string) ($profile['intent'] ?? '')) === 'dependiente_qa_basic') {
            $minimum = max(1,absint(apply_filters(
                'seo_solucionador_min_academy_questions',
                3,
                absint($profile['category_id'] ?? 0)
            )));
            return absint($stats['academy_questions'] ?? $stats['dependiente'] ?? 0) >= $minimum;
        }
        return false;
    }

    private static function category_product_count($term_id) {
        $term = get_term(absint($term_id),'product_cat');
        return ($term && !is_wp_error($term)) ? max(0,(int) $term->count) : 0;
    }

    private static function priority_components(array $stats, array $coverage, array $knowledge, array $proposal, $primary_category_id, array $risks) {
        $questions = absint($stats['academy_questions'] ?? 0);
        $demand = absint($stats['search_demand'] ?? 0);
        $products = self::category_product_count($primary_category_id);
        $coverage_status = sanitize_key((string) ($coverage['status'] ?? 'uncovered'));

        $scores = array(
            'learned_questions'=>min(45,$questions * 5),
            'real_demand'=>min(15,log(1 + max(0,$demand)) * 4),
            'catalog_fit'=>min(15,($primary_category_id ? 6 : 0) + min(9,log(1 + $products) * 2)),
            'coverage_gap'=>array(
                'uncovered'=>20,
                'weak_coverage'=>12,
                'partial_coverage'=>6,
                'covered'=>0,
                'duplicate'=>0,
                'conflict'=>0,
            )[$coverage_status] ?? 0,
        );
        $max = array('learned_questions'=>45,'real_demand'=>15,'catalog_fit'=>15,'coverage_gap'=>20);
        $labels = array(
            'learned_questions'=>'Preguntas aprendidas',
            'real_demand'=>'Demanda real',
            'catalog_fit'=>'Encaje con catálogo',
            'coverage_gap'=>'Hueco de cobertura',
        );
        $out = array();
        $total = 0.0;
        foreach ($scores as $key=>$score) {
            $score = round(max(0,(float) $score),2);
            $total += $score;
            $out[$key] = array('label'=>$labels[$key],'score'=>$score,'max'=>$max[$key]);
        }
        $penalty = round(min(20,(float) ($risks['duplication_risk'] ?? 0) * 0.20),2);
        $out['duplication_penalty'] = array('label'=>'Riesgo de duplicación','score'=>-$penalty,'max'=>0);
        $total -= $penalty;
        return array('total'=>round(max(0,min(100,$total)),2),'components'=>$out);
    }

    private static function broad_category_intent(array $profile, $primary_category_id) {
        if (!absint($primary_category_id)) return false;
        $intent = sanitize_key((string) ($profile['intent'] ?? ''));
        $key_intent = sanitize_key((string) ($profile['key_intent'] ?? ''));
        $action = sanitize_key((string) ($profile['action'] ?? ''));
        $condition = trim((string) ($profile['condition'] ?? ''));
        return $condition === '' && (
            $key_intent === 'decision'
            || in_array($intent,array('decision','choice','buying_guide','eleccion'),true)
            || $action === 'elegir'
        );
    }

    private static function landing_candidate_for_profile(array $profile, $suggested_title = '') {
        if (!function_exists('seo_landing_get_candidates')) return array();
        static $candidates = null;
        if ($candidates === null) $candidates = (array) seo_landing_get_candidates(500);

        $topic_text = trim(implode(' ',array_filter(array(
            (string) $suggested_title,
            (string) ($profile['action'] ?? ''),
            (string) ($profile['object'] ?? ''),
            (string) ($profile['context'] ?? ''),
            (string) ($profile['condition'] ?? ''),
        ))));
        if ($topic_text === '') return array();

        $best = array();
        $best_score = 0.0;
        foreach ($candidates as $candidate) {
            $status = sanitize_key((string) ($candidate->status ?? ''));
            if (!in_array($status,array('candidate','review','approved'),true)) continue;
            $candidate_text = trim((string) ($candidate->title ?? '') . ' ' . (string) ($candidate->intent ?? ''));
            if ($candidate_text === '') continue;

            $similarity = SEO_Solucionador_Normalizer::similarity($topic_text,$candidate_text);
            if ($similarity < 0.62 || $similarity <= $best_score) continue;

            $requirements = function_exists('seo_landing_decode_json')
                ? seo_landing_decode_json($candidate->requirements_json ?? '')
                : array();
            $pass = function_exists('seo_landing_requirements_pass')
                ? seo_landing_requirements_pass($requirements)
                : ($status === 'approved');
            $score = (float) ($candidate->total_score ?? 0);
            if ($status !== 'approved' && (!$pass || $score < 60)) continue;

            $best_score = $similarity;
            $best = array(
                'id'=>absint($candidate->id ?? 0),
                'page_id'=>absint($candidate->page_id ?? 0),
                'status'=>$status,
                'score'=>$score,
                'similarity'=>round($similarity,4),
                'requirements'=>$requirements,
                'requirements_pass'=>(bool) $pass,
                'title'=>(string) ($candidate->title ?? ''),
                'intent'=>(string) ($candidate->intent ?? ''),
            );
        }
        return $best;
    }

    private static function requirements(array $stats,array $coverage,array $knowledge,array $risks,$primary_category_id,array $landing = array(),array $profile = array()) {
        $coverage_status = sanitize_key((string) ($coverage['status'] ?? 'uncovered'));
        $real_evidence = self::evidence_gate($stats,$profile);
        $coverage_allows_new = in_array($coverage_status,array('uncovered','weak_coverage'),true);
        $risk_ok = (float) ($risks['duplication_risk'] ?? 0) < 70
            && (float) ($risks['cannibalization_risk'] ?? 0) < 70;

        return array(
            'academy_mass'=>array(
                'pass'=>$real_evidence,
                'detail'=>'El dossier necesita una masa crítica de preguntas aprendidas pass_* antes de crear una URL.'
            ),
            'category_identified'=>array(
                'pass'=>absint($primary_category_id)>0,
                'detail'=>'La categoría debe estar demostrada por Academia/producto/FAQ; nunca por parecido textual.'
            ),
            'coverage_allows_new'=>array(
                'pass'=>$coverage_allows_new,
                'detail'=>'Crear post sólo con cobertura inexistente o débil.'
            ),
            'duplication_below_threshold'=>array(
                'pass'=>$risk_ok,
                'detail'=>'Riesgo de duplicación/canibalización por debajo del umbral de bloqueo.'
            ),
        );
    }

    private static function improvement_action(array $coverage) {
        $type = sanitize_key((string) ($coverage['entity_type'] ?? ''));
        $role = sanitize_key((string) ($coverage['seo_role'] ?? ''));
        if ($type === 'product_cat') return 'IMPROVE_CATEGORY';
        if ($type === 'post') return 'IMPROVE_POST';
        if ($type === 'page' && $role === 'landing') return 'IMPROVE_LANDING';
        if ($type === 'page') return 'IMPROVE_PAGE';
        return 'DEFER';
    }

    private static function decision(array $profile,array $stats,array $coverage,array $knowledge,array $risks,$primary_category_id,array $landing,array $requirements) {
        $coverage_status = sanitize_key((string) ($coverage['status'] ?? 'uncovered'));
        $entity_type = sanitize_key((string) ($coverage['entity_type'] ?? ''));

        if ($coverage_status === 'duplicate' || $coverage_status === 'conflict') {
            return array('action'=>'MERGE_CONTENT','reason'=>'Existen varias piezas solapadas; consolidar antes de crear otro post.');
        }
        if ($coverage_status === 'covered') {
            return array('action'=>'NO_ACTION','reason'=>'La intención básica de preguntas habituales ya está suficientemente cubierta.');
        }
        if (in_array($coverage_status,array('partial_coverage','weak_coverage'),true)) {
            if ($entity_type === 'post') {
                return array('action'=>'IMPROVE_POST','reason'=>'Existe un post de la misma intención con cobertura parcial o débil; se amplía en lugar de crear otro.');
            }
            return array('action'=>'DEFER','reason'=>'Existe cobertura parcial en otra entidad; requiere revisión editorial antes de crear un post.');
        }

        if (empty($requirements['category_identified']['pass'])) {
            return array('action'=>'DEFER','reason'=>'No hay product_cat demostrable para el dossier.');
        }
        if (empty($requirements['academy_mass']['pass'])) {
            return array('action'=>'DEFER','reason'=>'Todavía no existe masa crítica suficiente de preguntas aprendidas.');
        }
        if (empty($requirements['duplication_below_threshold']['pass'])) {
            return array('action'=>'DEFER','reason'=>'El riesgo de duplicación/canibalización exige revisión antes de crear contenido.');
        }
        if (!empty($requirements['coverage_allows_new']['pass'])) {
            return array('action'=>'CREATE_POST','reason'=>'Dossier de Academia con preguntas aprendidas suficientes, categoría resuelta y sin cobertura equivalente.');
        }

        return array('action'=>'DEFER','reason'=>'El dossier requiere revisión editorial antes de actuar.');
    }

    private static function content_type_for_action($action,array $coverage) {
        $action = strtoupper((string) $action);
        if (in_array($action,array('CREATE_POST','IMPROVE_POST'),true)) return 'post';
        if ($action === 'MERGE_CONTENT') return 'merge';
        return sanitize_key((string) ($coverage['entity_type'] ?? 'none')) ?: 'none';
    }

    private static function automatic_workflow_state($action,$legacy_status,$current_state) {
        $current_state = sanitize_key((string) $current_state);
        if (in_array($current_state,array('approved','brief_ready','in_editing','scheduled','published','monitoring','closed','rejected'),true)) {
            return $current_state;
        }
        if ((string) $legacy_status === 'dismissed') return 'rejected';
        $action = strtoupper((string) $action);
        if ($action === 'NO_ACTION') return 'validated';
        if (in_array($action,array('DEFER','INVESTIGATE'),true)) return 'deferred';
        return 'candidate';
    }

    private static function maybe_track($topic_id,$entity_type,$entity_id) {
        $topic_id = absint($topic_id);
        $entity_type = sanitize_key((string) $entity_type);
        $entity_id = absint($entity_id);
        if (!$topic_id || !$entity_id) return;

        $row = array();
        if ($entity_type === 'post' && function_exists('seo_post_reports_get_summary')) {
            $s = seo_post_reports_get_summary($entity_id,28);
            if (!empty($s['has_snapshot'])) {
                $row = array(
                    'entity_type'=>'post','entity_id'=>$entity_id,'source'=>'analista','period_days'=>28,
                    'impressions'=>absint($s['impressions'] ?? 0),'clicks'=>absint($s['clicks'] ?? 0),
                    'pageviews'=>absint($s['pageviews'] ?? 0),'snapshot_at'=>current_time('mysql'),
                );
            }
        } elseif ($entity_type === 'product_cat' && function_exists('seo_category_reports_get_summary')) {
            $s = seo_category_reports_get_summary($entity_id,28);
            if (!empty($s['has_snapshot'])) {
                $row = array(
                    'entity_type'=>'product_cat','entity_id'=>$entity_id,'source'=>'analista','period_days'=>28,
                    'impressions'=>absint($s['impressions'] ?? 0),'clicks'=>absint($s['clicks'] ?? 0),
                    'ctr'=>(float) ($s['ctr'] ?? 0),'position'=>(float) ($s['position'] ?? 0),
                    'sessions'=>absint($s['sessions'] ?? 0),'pageviews'=>absint($s['pageviews'] ?? 0),
                    'snapshot_at'=>current_time('mysql'),
                );
            }
        } elseif ($entity_type === 'page' && function_exists('seo_landing_google_metrics_for_page')) {
            $s = (array) seo_landing_google_metrics_for_page($entity_id);
            $gsc = (array) ($s['gsc'] ?? array());
            $ga4 = (array) ($s['ga4'] ?? array());
            if ($gsc || $ga4) {
                $row = array(
                    'entity_type'=>'page','entity_id'=>$entity_id,'source'=>'analista','period_days'=>28,
                    'impressions'=>absint($gsc['impressions'] ?? 0),'clicks'=>absint($gsc['clicks'] ?? 0),
                    'ctr'=>(float) ($gsc['ctr'] ?? 0),'position'=>(float) ($gsc['position'] ?? 0),
                    'sessions'=>absint($ga4['sessions'] ?? 0),'pageviews'=>absint($ga4['pageviews'] ?? 0),
                    'snapshot_at'=>current_time('mysql'),
                );
            }
        }
        if (!$row) return;

        $history = SEO_Solucionador_DB::tracking_history($topic_id,1);
        $prev = $history ? $history[0] : array();
        $now_value = absint($row['clicks'] ?? 0) + absint($row['pageviews'] ?? 0);
        $prev_value = absint($prev['clicks'] ?? 0) + absint($prev['pageviews'] ?? 0);
        if (!$prev) $outcome = 'baseline';
        elseif ($prev_value <= 0 && $now_value <= 0) $outcome = 'insufficient_data';
        elseif ($prev_value <= 0 && $now_value > 0) $outcome = 'improved';
        else {
            $ratio = $now_value / max(1,$prev_value);
            $outcome = $ratio >= 1.10 ? 'improved' : ($ratio <= 0.90 ? 'declined' : 'flat');
        }
        $row['outcome_state'] = $outcome;
        SEO_Solucionador_DB::add_tracking_snapshot($topic_id,$row);
    }

    /**
     * API de regresion RF v1.0. No escribe datos: permite validar la
     * normalizacion/category-first con escenarios deterministas.
     */
    public static function normalize_topic_for_test($text, array $source = array()) {
        $profile = SEO_Solucionador_Normalizer::profile(
            (string) $text,
            (array) ($source['hints'] ?? array())
        );
        return self::canonicalize_profile((array) $profile,$source);
    }

    /**
     * Evalua solo reglas de decision. No consulta servicios ni modifica BD.
     * Se usa para los tests funcionales obligatorios de RF v1.0.
     */
    public static function evaluate_scenario_for_test(array $scenario) {
        $profile = (array) ($scenario['profile'] ?? array());
        $stats = wp_parse_args((array) ($scenario['stats'] ?? array()),array(
            'total'=>0,'dependiente'=>0,'academy_questions'=>0,'search_demand'=>0,
        ));
        $coverage = wp_parse_args((array) ($scenario['coverage'] ?? array()),array(
            'status'=>'uncovered','entity_type'=>'','entity_id'=>0,'seo_role'=>'','matches'=>array(),'score'=>0,
        ));
        $knowledge = array('status'=>'not_required','count'=>0,'confidence'=>1);
        $risks = wp_parse_args((array) ($scenario['risks'] ?? array()),array(
            'duplication_risk'=>0,'cannibalization_risk'=>0,
        ));
        $primary_category_id = absint($scenario['primary_category_id'] ?? $profile['category_id'] ?? 0);
        $requirements = self::requirements($stats,$coverage,$knowledge,$risks,$primary_category_id,array(),$profile);
        $decision = self::decision($profile,$stats,$coverage,$knowledge,$risks,$primary_category_id,array(),$requirements);
        return array('decision'=>$decision,'requirements'=>$requirements);
    }

    /**
     * Garantiza que una instalación nueva disponga de un primer análisis.
     *
     * El análisis inicial se ejecuta una sola vez, cuando todavía no existe
     * seo_solucionador_last_scan. Un lock evita ejecuciones simultáneas desde
     * dos pestañas o desde pantalla + exportación.
     *
     * @return array|WP_Error
     */
    public static function ensure_initialized($days = 180) {
        $last = get_option('seo_solucionador_last_scan', array());
        if (is_array($last) && !empty($last['at'])) {
            return $last;
        }

        $lock_key = 'seo_solucionador_initial_scan_lock';
        if (get_transient($lock_key)) {
            return new WP_Error(
                'seo_solucionador_initializing',
                'Solucionador está preparando su primer análisis. Vuelve a cargar la pantalla en unos instantes.'
            );
        }

        set_transient($lock_key, 1, 5 * MINUTE_IN_SECONDS);

        try {
            $result = self::scan($days);
            delete_transient($lock_key);
            delete_option('seo_solucionador_init_error');
            return is_array($result) ? $result : array();
        } catch (Throwable $e) {
            delete_transient($lock_key);
            update_option('seo_solucionador_init_error', array(
                'at' => time(),
                'message' => sanitize_text_field($e->getMessage()),
            ), false);

            return new WP_Error(
                'seo_solucionador_initial_scan_failed',
                'No se pudo completar el análisis inicial de Solucionador: ' . $e->getMessage()
            );
        }
    }


    private static function dossier_summary($topic_id) {
        $ids = array();
        $scores = array();
        $last = '';
        $category_id = 0;
        $search_demand = 0;

        foreach (SEO_Solucionador_DB::get_evidence_rows(absint($topic_id)) as $row) {
            $meta = (array) ($row['source_meta_decoded'] ?? array());
            $channel = sanitize_key((string) ($meta['dependiente_channel'] ?? ''));
            if ($channel === 'academy_learned') {
                $question_id = absint($meta['question_id'] ?? 0);
                if ($question_id) $ids[$question_id] = true;
                $scores[] = (float) ($meta['evaluation_score'] ?? $row['confidence'] ?? 0);
                $category_id = $category_id ?: absint($row['category_id'] ?? $meta['category_id'] ?? 0);
                $observed = (string) ($row['observed_at'] ?? '');
                if ($observed > $last) $last = $observed;
            } elseif ($channel === 'search_log_demand') {
                $search_demand += absint($row['occurrences'] ?? 0);
            }
        }

        $question_ids = array_map('absint',array_keys($ids));
        sort($question_ids,SORT_NUMERIC);
        $score = $scores ? array_sum($scores)/count($scores) : 0.0;
        return array(
            'category_id'=>$category_id,
            'question_ids'=>$question_ids,
            'question_count'=>count($question_ids),
            'score'=>round(max(0,min(1,$score)),4),
            'last_validated_at'=>$last,
            'hash'=>hash('sha256',implode(',', $question_ids) . '|' . $category_id . '|' . $last),
            'search_demand'=>$search_demand,
        );
    }

    private static function process_academy_source(array $source) {
        $profile = SEO_Solucionador_Normalizer::profile(
            (string) ($source['source_text'] ?? ''),
            (array) ($source['hints'] ?? array())
        );
        $profile = self::canonicalize_profile((array) $profile,$source);
        if (!$profile || SEO_Solucionador_Normalizer::is_weak_profile($profile)) return 0;

        $topic_id = SEO_Solucionador_DB::upsert_topic($profile,(string) ($source['source_text'] ?? ''));
        if (!$topic_id) return 0;
        SEO_Solucionador_DB::add_evidence($topic_id,$source);
        return $topic_id;
    }

    public static function scan($days = 180) {
        global $wpdb;
        SEO_Solucionador_DB::maybe_install();

        $state = get_option(self::SCAN_STATE_OPTION,array());
        $resume = is_array($state) && !empty($state['in_progress']);
        if (!$resume) {
            SEO_Solucionador_DB::begin_scan();
            $coverage_index = class_exists('SEO_Editorial_Coverage')
                ? SEO_Editorial_Coverage::rebuild_index(7000)
                : SEO_Solucionador_Coverage::rebuild_index(7000);
            $state = array(
                'in_progress'=>true,
                'cursor'=>0,
                'days'=>absint($days),
                'started_at'=>time(),
                'accepted'=>0,
                'discarded'=>0,
                'seen'=>0,
                'learned_with_category'=>0,
                'learned_without_category'=>0,
                'coverage_index'=>$coverage_index,
            );
            update_option(self::SCAN_STATE_OPTION,$state,false);
        }

        $started = microtime(true);
        $complete = false;
        $batches = 0;
        while ($batches < self::MAX_BATCHES_PER_REQUEST && (microtime(true)-$started) < 20) {
            $batch = SEO_Solucionador_Sources::academia_batch(
                absint($state['cursor'] ?? 0),
                self::BATCH_SIZE
            );
            $batches++;
            $state['seen'] += absint($batch['seen'] ?? 0);
            $state['learned_with_category'] += absint($batch['with_category'] ?? 0);
            $state['learned_without_category'] += absint($batch['without_category'] ?? 0);

            foreach ((array) ($batch['sources'] ?? array()) as $source) {
                if (self::process_academy_source((array) $source)) $state['accepted']++;
                else $state['discarded']++;
            }

            $state['cursor'] = absint($batch['next_cursor'] ?? $state['cursor']);
            $state['updated_at'] = time();
            update_option(self::SCAN_STATE_OPTION,$state,false);

            if (!empty($batch['complete'])) {
                $complete = true;
                break;
            }
            if (absint($batch['seen'] ?? 0) < 1) {
                $complete = true;
                break;
            }
        }

        if (!$complete) {
            $last = array(
                'at'=>time(),
                'days'=>absint($state['days'] ?? $days),
                'complete'=>false,
                'in_progress'=>true,
                'cursor'=>absint($state['cursor'] ?? 0),
                'sources_seen'=>absint($state['seen'] ?? 0),
                'accepted'=>absint($state['accepted'] ?? 0),
                'discarded'=>absint($state['discarded'] ?? 0),
                'message'=>'Escaneo por lotes en curso. Puede reanudarse sin perder el trabajo ya procesado.',
            );
            update_option('seo_solucionador_last_scan',$last,false);
            return $last;
        }

        // Search log: solo refuerza dossiers que Academia ya ha originado.
        $evidence_table = SEO_Solucionador_DB::evidence_table();
        $category_ids = array_values(array_unique(array_filter(array_map('absint',(array) $wpdb->get_col(
            "SELECT DISTINCT category_id FROM {$evidence_table}
             WHERE source_type='dependiente' AND source_id LIKE 'academy-question:%' AND category_id IS NOT NULL"
        )))));
        foreach (SEO_Solucionador_Sources::search_demand_reinforcements($category_ids,absint($state['days'] ?? $days),400) as $source) {
            $term_id = absint($source['category_id'] ?? 0);
            if (!$term_id) continue;
            $topic_id = SEO_Solucionador_DB::get_topic_id_by_key(
                'dependiente-qa-basic|resolver|category-' . $term_id . '|general|general'
            );
            if ($topic_id) SEO_Solucionador_DB::add_evidence($topic_id,$source);
        }

        $pruned_topics = SEO_Solucionador_DB::prune_orphan_topics();
        $topics_table = SEO_Solucionador_DB::topics_table();
        $topics = (array) $wpdb->get_results(
            "SELECT * FROM {$topics_table}
             WHERE intent='dependiente_qa_basic' OR COALESCE(draft_post_id,0)>0
             ORDER BY id ASC",
            ARRAY_A
        );

        $categories_with = array();
        $question_assignments = 0;
        foreach ($topics as $topic) {
            $topic_id = absint($topic['id'] ?? 0);
            if (!$topic_id) continue;

            $dossier = self::dossier_summary($topic_id);
            if (!$dossier['question_count'] && !absint($topic['draft_post_id'] ?? 0)) continue;

            $primary_category_id = absint($dossier['category_id'] ?? $topic['primary_category_id'] ?? 0);
            if ($primary_category_id) {
                $categories_with[$primary_category_id] = true;
                $question_assignments += absint($dossier['question_count'] ?? 0);
            }

            $profile = array(
                'intent'=>'dependiente_qa_basic',
                'key_intent'=>'dependiente_qa_basic',
                'action'=>'resolver',
                'object'=>self::category_name($primary_category_id),
                'condition'=>'',
                'context'=>'',
                'canonical_key'=>(string) ($topic['canonical_key'] ?? ''),
                'category_id'=>$primary_category_id,
            );
            $stats = SEO_Solucionador_DB::topic_stats($topic_id);
            $stats['academy_questions'] = absint($dossier['question_count'] ?? 0);
            $stats['search_demand'] = absint($dossier['search_demand'] ?? 0);

            $proposal = SEO_Solucionador_Catalog::build_proposal($topic_id,$profile);
            $hierarchy = self::hierarchy($primary_category_id);
            $coverage = class_exists('SEO_Editorial_Coverage')
                ? SEO_Editorial_Coverage::find($profile)
                : SEO_Solucionador_Coverage::find($profile);
            $coverage_status = sanitize_key((string) ($coverage['status'] ?? 'uncovered')) ?: 'uncovered';
            $knowledge = array('status'=>'not_required','count'=>0,'confidence'=>1);
            $risks = self::risks($coverage,$primary_category_id);
            $suggested_title = self::suggested_title($profile);
            $requirements = self::requirements($stats,$coverage,$knowledge,$risks,$primary_category_id,array(),$profile);
            $decision = self::decision($profile,$stats,$coverage,$knowledge,$risks,$primary_category_id,array(),$requirements);
            $priority = self::priority_components($stats,$coverage,$knowledge,$proposal,$primary_category_id,$risks);

            $draft_id = absint($topic['draft_post_id'] ?? 0);
            $draft_status = $draft_id ? get_post_status($draft_id) : false;
            if ($draft_id && $draft_status && $draft_status !== 'trash') {
                $coverage_status = $draft_status === 'publish' ? 'covered' : $coverage_status;
                $decision = array('action'=>'NO_ACTION','reason'=>$draft_status === 'publish'
                    ? 'El post básico ya está publicado; la medición global corresponde a Analista.'
                    : 'Existe un borrador asociado; no se crea otra pieza.');
            } elseif ($draft_id) {
                SEO_Solucionador_DB::update_topic($topic_id,array('draft_post_id'=>null));
                $draft_id = 0;
            }

            $workflow_state = self::automatic_workflow_state(
                $decision['action'],
                (string) ($topic['status'] ?? 'candidate'),
                (string) ($topic['workflow_state'] ?? 'detected')
            );
            if ($draft_id && $draft_status === 'publish') $workflow_state = 'monitoring';
            elseif ($draft_id && $draft_status && $draft_status !== 'trash') $workflow_state = 'in_editing';

            $existing_entity_type = sanitize_key((string) ($coverage['entity_type'] ?? ''));
            $existing_entity_id = absint($coverage['entity_id'] ?? 0);
            $existing_post_id = $existing_entity_type === 'post' ? $existing_entity_id : 0;

            $wpdb->update($topics_table,array(
                'canonical_question'=>'Preguntas habituales sobre ' . self::category_name($primary_category_id),
                'suggested_title'=>$suggested_title,
                'proposed_vocabulary'=>wp_json_encode((array) ($proposal['vocabulary'] ?? array()),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'proposed_categories'=>wp_json_encode((array) ($proposal['categories'] ?? array()),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'primary_category_id'=>$primary_category_id ?: null,
                'hierarchy_json'=>wp_json_encode($hierarchy,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'coverage_status'=>$coverage_status,
                'coverage_score'=>(float) ($coverage['score'] ?? 0),
                'existing_entity_type'=>$existing_entity_type ?: null,
                'existing_entity_id'=>$existing_entity_id ?: null,
                'existing_post_id'=>$existing_post_id ?: null,
                'recommended_action'=>(string) $decision['action'],
                'decision_reason'=>(string) $decision['reason'],
                'decision_requirements'=>wp_json_encode($requirements,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'priority_components'=>wp_json_encode($priority['components'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'duplication_risk'=>(float) ($risks['duplication_risk'] ?? 0),
                'cannibalization_risk'=>(float) ($risks['cannibalization_risk'] ?? 0),
                'knowledge_status'=>'not_required',
                'workflow_state'=>$workflow_state,
                'content_type'=>self::content_type_for_action($decision['action'],$coverage),
                'status'=>$workflow_state === 'rejected' ? 'dismissed' : ($workflow_state === 'deferred' ? 'observe' : ($draft_id ? 'draft_created' : 'candidate')),
                'priority_score'=>(float) $priority['total'],
                'evidence_total'=>absint($stats['total'] ?? 0),
                'dossier_question_count'=>absint($dossier['question_count'] ?? 0),
                'dossier_question_ids'=>wp_json_encode((array) ($dossier['question_ids'] ?? array())),
                'dossier_score'=>(float) ($dossier['score'] ?? 0),
                'dossier_last_validated_at'=>$dossier['last_validated_at'] ?: null,
                'dossier_hash'=>(string) ($dossier['hash'] ?? ''),
                'interpreter_evidence'=>absint($dossier['question_count'] ?? 0),
                'comentarista_evidence'=>0,
                'analyst_evidence'=>0,
                'auditor_evidence'=>0,
                'zero_result_evidence'=>0,
                'negative_feedback_evidence'=>0,
                'last_analyzed_at'=>current_time('mysql'),
                'updated_at'=>current_time('mysql'),
            ),array('id'=>$topic_id));
        }

        $categories_with_count = count($categories_with);
        SEO_Solucionador_Sources::save_academia_snapshot(array(
            'learned_with_category'=>absint($state['learned_with_category'] ?? 0),
            'learned_without_category'=>absint($state['learned_without_category'] ?? 0),
            'categories_with_knowledge'=>$categories_with_count,
            'avg_questions_per_category'=>$categories_with_count ? round($question_assignments/$categories_with_count,2) : 0,
        ));

        $coverage_index = (array) ($state['coverage_index'] ?? array());
        $last = array(
            'at'=>time(),
            'days'=>absint($state['days'] ?? $days),
            'complete'=>true,
            'in_progress'=>false,
            'cursor'=>absint($state['cursor'] ?? 0),
            'sources_seen'=>absint($state['seen'] ?? 0),
            'accepted'=>absint($state['accepted'] ?? 0),
            'discarded'=>absint($state['discarded'] ?? 0),
            'pruned_topics'=>$pruned_topics,
            'academy_categories'=>$categories_with_count,
            'posts_indexed'=>absint($coverage_index['posts'] ?? 0),
            'pages_indexed'=>absint($coverage_index['pages'] ?? 0),
            'categories_indexed'=>absint($coverage_index['categories'] ?? 0),
            'post_topics_indexed'=>absint($coverage_index['topics'] ?? 0),
        );
        update_option('seo_solucionador_last_scan',$last,false);
        delete_option(self::SCAN_STATE_OPTION);
        return $last;
    }
}
