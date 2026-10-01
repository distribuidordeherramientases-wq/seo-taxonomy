<?php
/**
 * Solucionador - motor de decision editorial RF v1.0.
 *
 * Principio: servicios especialistas -> evidencia -> tema canonico -> cobertura
 * -> decision minima -> brief. No consulta Internet, no redacta y no publica.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Engine {
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

        $decision_language = (bool) preg_match('/\b(elegir|eleccion|comprar|compra|diferencia|comparar|comparativa|que .* necesito|cual .* necesito|potencia|cable|bateria|par)\b/u',$text);
        $is_comparison_profile = sanitize_key((string) ($source['source_type'] ?? '')) === 'comparador'
            && $intent === 'comparison';
        $is_decision = $key_intent === 'decision'
            || in_array($intent,array('decision','choice','comparison','buying_guide','eleccion'),true)
            || $decision_language;

        if ($category_id && $is_comparison_profile && empty($profile['condition'])) {
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

    private static function evidence_gate(array $stats) {
        $dependiente = absint($stats['dependiente'] ?? 0);
        $support = absint($stats['analista'] ?? 0)
            + absint($stats['auditor'] ?? 0)
            + absint($stats['comentarista'] ?? 0)
            + absint($stats['ojeador'] ?? 0)
            + absint($stats['comparador'] ?? 0)
            + absint($stats['ingeniero'] ?? 0)
            + absint($stats['clasificador'] ?? 0);
        $editorial_origins = absint($stats['analista'] ?? 0) + absint($stats['auditor'] ?? 0);
        $comparison_profiles = absint($stats['comparador'] ?? 0);

        return $dependiente >= 2
            || ($dependiente >= 1 && $support >= 1)
            || ($editorial_origins >= 2 && absint($stats['total'] ?? 0) >= 2)
            || ($comparison_profiles >= 1 && absint($stats['total'] ?? 0) >= 1);
    }

    private static function category_product_count($term_id) {
        $term = get_term(absint($term_id),'product_cat');
        return ($term && !is_wp_error($term)) ? max(0,(int) $term->count) : 0;
    }

    private static function priority_components(array $stats, array $coverage, array $knowledge, array $proposal, $primary_category_id, array $risks) {
        $d = absint($stats['dependiente'] ?? 0);
        $a = absint($stats['analista'] ?? 0);
        $o = absint($stats['ojeador'] ?? 0);
        $comp = absint($stats['comparador'] ?? 0);
        $m = absint($stats['marketing'] ?? 0);
        $zero = absint($stats['zero_results'] ?? 0);
        $neg = absint($stats['negative_feedback'] ?? 0);
        $products = self::category_product_count($primary_category_id);
        $coverage_status = sanitize_key((string) ($coverage['status'] ?? 'uncovered'));

        $scores = array(
            'user_need'=>min(18,($d * 4.5) + ($zero * 2.5) + ($neg * 2.5)),
            'search_demand'=>min(16,$a * 5.0),
            'visibility_opportunity'=>min(10,$a * 2.5),
            'technical_knowledge'=>($knowledge['status'] ?? '') === 'sufficient' ? min(14,8 + ((float) ($knowledge['confidence'] ?? 0) * 6)) : 0,
            'catalog_fit'=>min(14,($primary_category_id ? 5 : 0) + min(6,log(1 + $products) * 1.4) + min(3,count((array) ($proposal['categories'] ?? array())))),
            'market_breadth'=>min(8,($o * 2.5) + ($comp * 3.0)),
            'coverage_gap'=>array(
                'uncovered'=>10,
                'weak_coverage'=>7,
                'partial_coverage'=>4,
                'covered'=>0,
                'duplicate'=>0,
                'conflict'=>0,
            )[$coverage_status] ?? 0,
            'business_relevance'=>min(10,($m * 3.0) + ($products > 0 ? min(7,log(1 + $products) * 1.5) : 0)),
        );
        $max = array(
            'user_need'=>18,'search_demand'=>16,'visibility_opportunity'=>10,'technical_knowledge'=>14,
            'catalog_fit'=>14,'market_breadth'=>8,'coverage_gap'=>10,'business_relevance'=>10,
        );
        $labels = array(
            'user_need'=>'Necesidad de usuario','search_demand'=>'Demanda/busqueda','visibility_opportunity'=>'Oportunidad de visibilidad',
            'technical_knowledge'=>'Conocimiento tecnico','catalog_fit'=>'Encaje con catalogo','market_breadth'=>'Amplitud de mercado',
            'coverage_gap'=>'Hueco de cobertura','business_relevance'=>'Relevancia comercial',
        );
        $out = array();
        $total = 0.0;
        foreach ($scores as $key=>$score) {
            $score = round(max(0,(float) $score),2);
            $total += $score;
            $out[$key] = array('label'=>$labels[$key],'score'=>$score,'max'=>$max[$key]);
        }
        $penalty = round(min(20,(float) ($risks['duplication_risk'] ?? 0) * 0.20),2);
        $out['duplication_penalty'] = array('label'=>'Riesgo de duplicacion','score'=>-$penalty,'max'=>0);
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

    private static function requirements(array $stats,array $coverage,array $knowledge,array $risks,$primary_category_id,array $landing) {
        $coverage_status = sanitize_key((string) ($coverage['status'] ?? 'uncovered'));
        $real_evidence = self::evidence_gate($stats);
        $coverage_allows_new = in_array($coverage_status,array('uncovered','weak_coverage'),true);
        $risk_ok = (float) ($risks['duplication_risk'] ?? 0) < 70
            && (float) ($risks['cannibalization_risk'] ?? 0) < 70;
        $knowledge_ok = (string) ($knowledge['status'] ?? '') === 'sufficient';

        return array(
            'real_evidence'=>array('pass'=>$real_evidence,'detail'=>'La necesidad debe tener repeticion o cruce independiente de fuentes.'),
            'category_identified'=>array('pass'=>absint($primary_category_id)>0,'detail'=>'La oportunidad debe intentar asociarse primero a product_cat.'),
            'coverage_allows_new'=>array('pass'=>$coverage_allows_new,'detail'=>'Crear URL solo con cobertura inexistente o debil.'),
            'knowledge_sufficient'=>array('pass'=>$knowledge_ok,'detail'=>'Ingeniero debe aportar conocimiento validado suficiente antes de redactar un tema tecnico.'),
            'duplication_below_threshold'=>array('pass'=>$risk_ok,'detail'=>'Riesgo de duplicacion/canibalizacion por debajo del umbral de bloqueo.'),
            'landing_requirements'=>array('pass'=>!empty($landing['requirements_pass']),'detail'=>'CREATE_LANDING exige candidata valida, estable, comercial y diferenciada.'),
            'landing_candidate'=>array('pass'=>!empty($landing),'detail'=>'Debe existir una candidata de landing previamente evaluada.'),
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

        if ($coverage_status === 'conflict') {
            return array('action'=>'INVESTIGATE','reason'=>'Hay contenidos potencialmente contradictorios. Primero debe resolverse el conflicto antes de editar o crear.');
        }
        if ($coverage_status === 'duplicate') {
            return array('action'=>'MERGE_CONTENT','reason'=>'Varias URLs cubren practicamente la misma intencion; consolidar antes de crear contenido.');
        }
        if ($coverage_status === 'covered') {
            return array('action'=>'NO_ACTION','reason'=>'La intencion ya esta suficientemente cubierta por una URL existente.');
        }
        if (in_array($coverage_status,array('partial_coverage','weak_coverage'),true)) {
            $action = self::improvement_action($coverage);
            return array('action'=>$action,'reason'=>'Existe cobertura relacionada pero insuficiente; se prioriza mejorar la URL existente.');
        }

        if (($knowledge['status'] ?? '') !== 'sufficient') {
            return array('action'=>'INVESTIGATE','reason'=>'No hay conocimiento tecnico validado suficiente para entregar el tema a Editora.');
        }

        // Una intencion comercial amplia que coincide con una familia existente
        // debe mejorar primero product_cat, no fabricar una landing competidora.
        if (self::broad_category_intent($profile,$primary_category_id)) {
            return array('action'=>'IMPROVE_CATEGORY','reason'=>'La necesidad coincide con una familia de producto existente; la categoria es el destino principal antes que una nueva landing.');
        }

        $risk_ok = !empty($requirements['duplication_below_threshold']['pass']);
        $evidence_ok = !empty($requirements['real_evidence']['pass']);
        $coverage_ok = !empty($requirements['coverage_allows_new']['pass']);

        if (!empty($landing)
            && !empty($requirements['landing_requirements']['pass'])
            && $risk_ok && $evidence_ok && $coverage_ok) {
            return array('action'=>'CREATE_LANDING','reason'=>'La intencion es transversal/comercial, la candidata de landing cumple requisitos y no existe una URL equivalente.');
        }

        if ($risk_ok && $evidence_ok && $coverage_ok) {
            return array('action'=>'CREATE_POST','reason'=>'Tema informativo concreto, con evidencia real, conocimiento suficiente y sin cobertura equivalente.');
        }

        return array('action'=>'DEFER','reason'=>'La evidencia o las condiciones obligatorias todavia no justifican una actuacion editorial.');
    }

    private static function content_type_for_action($action,array $coverage) {
        $action = strtoupper((string) $action);
        if ($action === 'CREATE_POST' || $action === 'IMPROVE_POST') return 'post';
        if ($action === 'CREATE_LANDING' || $action === 'IMPROVE_LANDING') return 'landing';
        if ($action === 'IMPROVE_CATEGORY') return 'category';
        if ($action === 'IMPROVE_PAGE') return 'page';
        if ($action === 'MERGE_CONTENT') return 'merge';
        if ($action === 'INVESTIGATE') return 'research';
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
            'total'=>0,'dependiente'=>0,'comentarista'=>0,'analista'=>0,'auditor'=>0,
            'ojeador'=>0,'comparador'=>0,'ingeniero'=>0,'clasificador'=>0,'marketing'=>0,
            'zero_results'=>0,'negative_feedback'=>0,
        ));
        $coverage = wp_parse_args((array) ($scenario['coverage'] ?? array()),array(
            'status'=>'uncovered','entity_type'=>'','entity_id'=>0,'seo_role'=>'','matches'=>array(),'score'=>0,
        ));
        $knowledge = wp_parse_args((array) ($scenario['knowledge'] ?? array()),array(
            'status'=>'insufficient','count'=>0,'confidence'=>0,
        ));
        $risks = wp_parse_args((array) ($scenario['risks'] ?? array()),array(
            'duplication_risk'=>0,'cannibalization_risk'=>0,
        ));
        $primary_category_id = absint($scenario['primary_category_id'] ?? $profile['category_id'] ?? 0);
        $landing = (array) ($scenario['landing'] ?? array());
        $requirements = self::requirements($stats,$coverage,$knowledge,$risks,$primary_category_id,$landing);
        $decision = self::decision($profile,$stats,$coverage,$knowledge,$risks,$primary_category_id,$landing,$requirements);
        return array(
            'decision'=>$decision,
            'requirements'=>$requirements,
        );
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

    public static function scan($days = 180) {
        global $wpdb;
        SEO_Solucionador_DB::maybe_install();

        SEO_Solucionador_DB::begin_scan();
        $coverage_index = SEO_Solucionador_Coverage::rebuild_index(7000);
        $sources = SEO_Solucionador_Sources::all($days);

        $accepted = 0;
        $discarded = 0;
        $reinforcement_skipped = 0;
        $seen_by_source = array();
        $accepted_by_source = array();
        $discarded_by_source = array();
        $origins = array();
        $origin_by_key = array();
        $origin_by_category = array();

        foreach ($sources as $source) {
            $source_type = sanitize_key((string) ($source['source_type'] ?? '')) ?: 'unknown';
            self::bump($seen_by_source,$source_type);

            $profile = SEO_Solucionador_Normalizer::profile(
                (string) ($source['source_text'] ?? ''),
                (array) ($source['hints'] ?? array())
            );
            $profile = self::canonicalize_profile((array) $profile,$source);
            if (!$profile || SEO_Solucionador_Normalizer::is_weak_profile($profile)) {
                $discarded++;
                self::bump($discarded_by_source,$source_type);
                continue;
            }

            $key = (string) ($profile['canonical_key'] ?? '');
            $role = sanitize_key((string) ($source['proposal_role'] ?? 'origin')) ?: 'origin';
            $topic_id = 0;

            if ($role === 'reinforcement') {
                $topic_id = self::origin_match($profile,$source,$origins,$origin_by_key,$origin_by_category);
                if (!$topic_id) {
                    $reinforcement_skipped++;
                    $discarded++;
                    self::bump($discarded_by_source,$source_type);
                    continue;
                }
            } else {
                $topic_id = SEO_Solucionador_DB::upsert_topic($profile,(string) ($source['source_text'] ?? ''));
                if ($topic_id) {
                    $origins[$topic_id] = $profile;
                    $origin_by_key[$key] = $topic_id;
                    $cat = absint($source['category_id'] ?? $profile['category_id'] ?? 0);
                    if ($cat) $origin_by_category[$cat][] = $topic_id;
                }
            }

            if (!$topic_id) {
                $discarded++;
                self::bump($discarded_by_source,$source_type);
                continue;
            }

            SEO_Solucionador_DB::add_evidence($topic_id,$source);
            $accepted++;
            self::bump($accepted_by_source,$source_type);
        }

        $pruned_topics = SEO_Solucionador_DB::prune_orphan_topics();
        $topics_table = SEO_Solucionador_DB::topics_table();
        $topics = (array) $wpdb->get_results("SELECT * FROM {$topics_table} ORDER BY id ASC",ARRAY_A);

        foreach ($topics as $topic) {
            $topic_id = absint($topic['id'] ?? 0);
            if (!$topic_id) continue;

            $profile = array(
                'intent'=>(string) ($topic['intent'] ?? ''),
                'key_intent'=>preg_match('/compar|choice|decision|eleg|compra/i',(string) ($topic['intent'] ?? '')) ? 'decision' : ((string) ($topic['intent'] ?? '') === 'problem' ? 'problem' : 'task'),
                'action'=>(string) ($topic['action_term'] ?? ''),
                'object'=>(string) ($topic['object_term'] ?? ''),
                'condition'=>(string) ($topic['condition_term'] ?? ''),
                'context'=>(string) ($topic['context_term'] ?? ''),
                'canonical_key'=>(string) ($topic['canonical_key'] ?? ''),
                'category_id'=>absint($topic['primary_category_id'] ?? 0),
            );
            $stats = SEO_Solucionador_DB::topic_stats($topic_id);
            $question = self::representative_question($topic_id,(string) ($topic['canonical_question'] ?? ''));

            $proposal = SEO_Solucionador_Catalog::build_proposal($topic_id,$profile);
            $primary_category_id = self::primary_category_id($topic_id,$proposal,$profile);
            if ($primary_category_id) $profile['category_id'] = $primary_category_id;
            $hierarchy = self::hierarchy($primary_category_id);

            $coverage = SEO_Solucionador_Coverage::find($profile);
            $coverage_status = sanitize_key((string) ($coverage['status'] ?? 'uncovered')) ?: 'uncovered';
            $knowledge = self::knowledge_status($primary_category_id,$stats);
            $risks = self::risks($coverage,$primary_category_id);
            $suggested_title = SEO_Solucionador_Normalizer::suggested_title($profile);
            $landing = self::landing_candidate_for_profile($profile,$suggested_title);
            $requirements = self::requirements($stats,$coverage,$knowledge,$risks,$primary_category_id,$landing);
            $decision = self::decision($profile,$stats,$coverage,$knowledge,$risks,$primary_category_id,$landing,$requirements);
            $priority = self::priority_components($stats,$coverage,$knowledge,$proposal,$primary_category_id,$risks);

            $draft_id = absint($topic['draft_post_id'] ?? 0);
            $draft_status = $draft_id ? get_post_status($draft_id) : false;
            if ($draft_id && $draft_status && $draft_status !== 'trash') {
                $coverage_status = $draft_status === 'publish' ? 'covered' : $coverage_status;
                $decision = array('action'=>'NO_ACTION','reason'=>$draft_status === 'publish'
                    ? 'La propuesta ya se publico; pasa a seguimiento.'
                    : 'Existe un borrador de trabajo asociado; no se crea otra pieza.');
            } elseif ($draft_id) {
                SEO_Solucionador_DB::update_topic($topic_id,array('draft_post_id'=>null));
                $draft_id = 0;
            }

            $legacy_status = (string) ($topic['status'] ?? 'candidate');
            $workflow_state = self::automatic_workflow_state(
                $decision['action'],
                $legacy_status,
                (string) ($topic['workflow_state'] ?? 'detected')
            );
            if ($draft_id && $draft_status === 'publish') $workflow_state = 'monitoring';
            elseif ($draft_id && $draft_status && $draft_status !== 'trash') $workflow_state = 'in_editing';

            $legacy_status_out = $legacy_status === 'dismissed' ? 'dismissed' : (
                $workflow_state === 'rejected' ? 'dismissed' : (
                    in_array($workflow_state,array('in_editing','scheduled'),true) ? 'draft_created' : (
                        in_array($workflow_state,array('published','monitoring','closed'),true) ? 'covered' : (
                            $workflow_state === 'deferred' ? 'observe' : 'candidate'
                        )
                    )
                )
            );

            $existing_entity_type = sanitize_key((string) ($coverage['entity_type'] ?? ''));
            $existing_entity_id = absint($coverage['entity_id'] ?? 0);
            $existing_post_id = $existing_entity_type === 'post' ? $existing_entity_id : 0;
            if ($draft_id && $draft_status === 'publish') {
                $existing_entity_type = 'post';
                $existing_entity_id = $draft_id;
                $existing_post_id = $draft_id;
            }

            $wpdb->update($topics_table,array(
                'canonical_question'=>$question,
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
                'knowledge_status'=>(string) ($knowledge['status'] ?? 'unknown'),
                'workflow_state'=>$workflow_state,
                'content_type'=>self::content_type_for_action($decision['action'],$coverage),
                'status'=>$legacy_status_out,
                'priority_score'=>(float) $priority['total'],
                'evidence_total'=>absint($stats['total'] ?? 0),
                'interpreter_evidence'=>absint($stats['dependiente'] ?? 0),
                'comentarista_evidence'=>absint($stats['comentarista'] ?? 0),
                'analyst_evidence'=>absint($stats['analista'] ?? 0),
                'auditor_evidence'=>absint($stats['auditor'] ?? 0),
                'zero_result_evidence'=>absint($stats['zero_results'] ?? 0),
                'negative_feedback_evidence'=>absint($stats['negative_feedback'] ?? 0),
                'last_analyzed_at'=>current_time('mysql'),
                'updated_at'=>current_time('mysql'),
            ),array('id'=>$topic_id));

            if ($existing_entity_type && $existing_entity_id) {
                self::maybe_track($topic_id,$existing_entity_type,$existing_entity_id);
            }
        }

        update_option('seo_solucionador_last_scan',array(
            'at'=>time(),
            'days'=>absint($days),
            'sources_seen'=>count($sources),
            'accepted'=>$accepted,
            'discarded'=>$discarded,
            'reinforcement_skipped'=>$reinforcement_skipped,
            'pruned_topics'=>$pruned_topics,
            'seen_by_source'=>$seen_by_source,
            'accepted_by_source'=>$accepted_by_source,
            'discarded_by_source'=>$discarded_by_source,
            'posts_indexed'=>absint($coverage_index['posts'] ?? 0),
            'pages_indexed'=>absint($coverage_index['pages'] ?? 0),
            'categories_indexed'=>absint($coverage_index['categories'] ?? 0),
            'post_topics_indexed'=>absint($coverage_index['topics'] ?? 0),
        ),false);

        return get_option('seo_solucionador_last_scan',array());
    }
}
