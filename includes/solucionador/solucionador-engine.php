<?php
/**
 * Solucionador v0.5 - Academia/Entrenador -> dossier -> cobertura -> brief.
 *
 * Sólo organiza conocimiento ya aprendido de Dependiente. No investiga,
 * no compara mercado, no redacta y no publica.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Engine {
    const EDITORIAL_SCAN_OPTION = 'seo_solucionador_editorial_scan_state';
    const AUTO_REFRESH_HOOK = 'seo_solucionador_auto_refresh';
    const AUTO_REFRESH_STEP_HOOK = 'seo_solucionador_auto_refresh_step';

    public static function init() {
        add_action('init', array(__CLASS__, 'schedule_automatic_refresh'), 20);
        add_action(self::AUTO_REFRESH_HOOK, array(__CLASS__, 'automatic_refresh'));
        add_action(self::AUTO_REFRESH_STEP_HOOK, array(__CLASS__, 'automatic_refresh'));
    }

    public static function schedule_automatic_refresh() {
        if (!wp_next_scheduled(self::AUTO_REFRESH_HOOK)) {
            wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::AUTO_REFRESH_HOOK);
        }
    }

    public static function kick_automatic_refresh() {
        if (!wp_next_scheduled(self::AUTO_REFRESH_STEP_HOOK)) {
            wp_schedule_single_event(time() + 15, self::AUTO_REFRESH_STEP_HOOK);
        }
    }

    public static function bootstrap_if_empty($batch_size = 500) {
        if (!class_exists('SEO_Solucionador_Dossiers')) return array();

        $snapshot = SEO_Solucionador_Dossiers::snapshot();
        if (empty($snapshot['available']) || empty($snapshot['questions_total'])) {
            return $snapshot;
        }

        $state = SEO_Solucionador_Dossiers::state();
        $has_dossiers = !empty($snapshot['categories_with_knowledge']);
        $policy_current = is_array($state)
            && (string) ($state['editorial_policy'] ?? '') === SEO_Solucionador_Dossiers::EDITORIAL_POLICY_VERSION;
        $scan_complete = $policy_current
            && !empty($state['token'])
            && !empty($state['complete']);

        // Si el inventario ya terminó, abrir Solucionador no debe reiniciarlo.
        // Mientras siga incompleto, cada carga procesa otro lote aunque ya
        // existan dossiers: evita quedarse detenido tras las primeras 500
        // preguntas cuando WP-Cron no ejecuta la continuación.
        if ($has_dossiers && $scan_complete) {
            return $snapshot;
        }

        $lock_key = 'seo_solucionador_bootstrap_lock';
        if (get_transient($lock_key)) return $snapshot;
        set_transient($lock_key, 1, MINUTE_IN_SECONDS);

        try {
            $last_scan = get_option('seo_solucionador_last_scan', array());

            // Solo se invalida un cierre anterior si el estado de dossiers aún
            // no está completo o si no existe ningún dossier utilizable.
            if (
                is_array($last_scan)
                && !empty($last_scan['complete'])
                && (!$scan_complete || !$has_dossiers)
            ) {
                delete_option('seo_solucionador_last_scan');
                delete_option(self::EDITORIAL_SCAN_OPTION);
                $last_scan = array();
            }

            $state = SEO_Solucionador_Dossiers::state();
            if (
                !$state
                || empty($state['token'])
                || (string) ($state['editorial_policy'] ?? '') !== SEO_Solucionador_Dossiers::EDITORIAL_POLICY_VERSION
            ) {
                SEO_Solucionador_Dossiers::reset_scan();
                $state = SEO_Solucionador_Dossiers::state();
            } elseif (!empty($state['complete'])) {
                // Un estado completo con cero dossiers es incoherente si hay
                // preguntas de Academia: reinicia el cursor para reconstruir.
                if (!$has_dossiers) {
                    SEO_Solucionador_Dossiers::reset_scan();
                    $state = SEO_Solucionador_Dossiers::state();
                } else {
                    return $snapshot;
                }
            }

            $result = SEO_Solucionador_Dossiers::scan_batch(
                max(25, min(500, absint($batch_size))),
                false
            );
            if (is_wp_error($result)) return $result;

            if (empty($result['scan_complete']) && !wp_next_scheduled(self::AUTO_REFRESH_STEP_HOOK)) {
                wp_schedule_single_event(time() + 5, self::AUTO_REFRESH_STEP_HOOK);
            }

            return $result;
        } finally {
            delete_transient($lock_key);
        }
    }

    public static function automatic_refresh() {
        if (!class_exists('SEO_Solucionador_Dossiers')) return;

        $lock_key = 'seo_solucionador_auto_refresh_lock';
        if (get_transient($lock_key)) return;
        set_transient($lock_key, 1, 2 * MINUTE_IN_SECONDS);

        try {
            $snapshot = SEO_Solucionador_Dossiers::snapshot();
            if (empty($snapshot['available'])) return;

            $last_scan = get_option('seo_solucionador_last_scan', array());
            $state = SEO_Solucionador_Dossiers::state();
            $policy_mismatch = (string) ($state['editorial_policy'] ?? '') !== SEO_Solucionador_Dossiers::EDITORIAL_POLICY_VERSION;
            $needs_dossiers = !empty($snapshot['questions_total'])
                && (
                    empty($snapshot['scan_complete'])
                    || empty($snapshot['categories_with_knowledge'])
                    || $policy_mismatch
                );

            if ($needs_dossiers && is_array($last_scan) && !empty($last_scan['complete'])) {
                delete_option('seo_solucionador_last_scan');
                delete_option(self::EDITORIAL_SCAN_OPTION);

                $state = SEO_Solucionador_Dossiers::state();
                if (!$state || empty($state['token']) || !empty($state['complete'])) {
                    SEO_Solucionador_Dossiers::reset_scan();
                }
                $last_scan = array();
            }

            if (
                !$needs_dossiers
                && is_array($last_scan)
                && !empty($last_scan['complete'])
                && !empty($last_scan['at'])
                && (time() - absint($last_scan['at'])) < 6 * HOUR_IN_SECONDS
            ) {
                return;
            }

            // Prioridad 1: terminar Academia. Mientras esa fase siga abierta se
            // consumen varios lotes de 500 por ejecución, con presupuesto de
            // tiempo, para no tardar horas en completar decenas de miles de
            // preguntas. La fase editorial vuelve al ritmo normal después.
            $started = microtime(true);
            $result = array();
            for ($i = 0; $i < 4; $i++) {
                $result = self::scan(180, 500);
                if (is_wp_error($result)) return;
                if (!empty($result['complete'])) break;
                if ((string) ($result['phase'] ?? '') !== 'academia_dossiers') break;
                if ((microtime(true) - $started) >= 18) break;
            }

            if (empty($result['complete']) && !wp_next_scheduled(self::AUTO_REFRESH_STEP_HOOK)) {
                $delay = ((string) ($result['phase'] ?? '') === 'academia_dossiers') ? 5 : 30;
                wp_schedule_single_event(time() + $delay, self::AUTO_REFRESH_STEP_HOOK);
            }
        } finally {
            delete_transient($lock_key);
        }
    }
    private static function representative_question($topic_id, $fallback = '') {
        $rows = SEO_Solucionador_DB::get_evidence_rows($topic_id);
        foreach (array('dependiente') as $source_type) {
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
        if (!$category_id) return $profile;

        $meta = is_array($source['source_meta'] ?? null) ? $source['source_meta'] : array();
        $channel = sanitize_key((string)($meta['dependiente_channel'] ?? ''));
        $is_academy = sanitize_key((string)($source['source_type'] ?? '')) === 'dependiente'
            && $channel === 'academy_learned_dossier';
        if (!$is_academy) return $profile;

        $name = trim((string)($source['category_name'] ?? $meta['category_name'] ?? ''));
        if ($name === '') $name = self::category_name($category_id);

        $profile['object'] = $name !== '' ? SEO_Solucionador_Normalizer::normalize($name) : '';
        $profile['intent'] = 'dependiente_qa_basic';
        $profile['key_intent'] = 'dependiente_qa_basic';
        $profile['action'] = 'resolver';
        $profile['condition'] = '';
        $profile['context'] = '';
        $profile['category_id'] = $category_id;
        $profile['confidence'] = max(0.90,(float)($profile['confidence'] ?? 0));
        $profile['canonical_key'] = 'dependiente-qa-basic|resolver|category-' . $category_id . '|general|general';
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
                return 'Guía práctica de ' . $name . ': elección, uso y errores habituales';
            }
        }
        return SEO_Solucionador_Normalizer::suggested_title($profile);
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

    public static function minimum_academy_questions() {
        return max(1, absint(apply_filters('seo_solucionador_min_academy_questions', 3)));
    }

    private static function evidence_gate(array $stats) {
        return absint($stats['dependiente'] ?? 0) >= self::minimum_academy_questions();
    }

    private static function category_product_count($term_id) {
        $term = get_term(absint($term_id),'product_cat');
        return ($term && !is_wp_error($term)) ? max(0,(int) $term->count) : 0;
    }

    private static function priority_components(array $stats, array $coverage, array $knowledge, array $proposal, $primary_category_id, array $risks) {
        $learned = absint($stats['dependiente'] ?? 0);
        $products = self::category_product_count($primary_category_id);
        $coverage_status = sanitize_key((string) ($coverage['status'] ?? 'uncovered'));

        $scores = array(
            'learned_questions'=>min(45, $learned * 3.0),
            'category_fit'=>min(20, ($primary_category_id ? 8 : 0) + min(12, log(1 + $products) * 2.4)),
            'coverage_gap'=>array(
                'uncovered'=>25,
                'weak_coverage'=>16,
                'partial_coverage'=>8,
                'covered'=>0,
                'duplicate'=>0,
                'conflict'=>0,
            )[$coverage_status] ?? 0,
            'validation_confidence'=>($knowledge['status'] ?? '') === 'sufficient'
                ? min(10, 5 + ((float) ($knowledge['confidence'] ?? 0) * 5))
                : 0,
        );
        $max = array(
            'learned_questions'=>45,
            'category_fit'=>20,
            'coverage_gap'=>25,
            'validation_confidence'=>10,
        );
        $max = array('learned_questions'=>45,'real_demand'=>15,'catalog_fit'=>15,'coverage_gap'=>20);
        $labels = array(
            'learned_questions'=>'Preguntas aprendidas',
            'category_fit'=>'Encaje con product_cat',
            'coverage_gap'=>'Hueco de cobertura',
            'validation_confidence'=>'Confianza de Academia',
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

    private static function requirements(array $stats,array $coverage,array $knowledge,array $risks,$primary_category_id,array $landing) {
        $coverage_status = sanitize_key((string) ($coverage['status'] ?? 'uncovered'));
        $mass_ok = self::evidence_gate($stats);
        $risk_ok = (float) ($risks['duplication_risk'] ?? 0) < 70
            && (float) ($risks['cannibalization_risk'] ?? 0) < 70;

        return array(
            'academy_mass'=>array(
                'pass'=>$mass_ok,
                'detail'=>'El dossier debe alcanzar la masa crítica de preguntas pass_* de Academia.'
            ),
            'category_identified'=>array(
                'pass'=>absint($primary_category_id)>0,
                'detail'=>'La categoría product_cat debe ser demostrable; nunca se infiere por parecido textual.'
            ),
            'coverage_reviewed'=>array(
                'pass'=>in_array($coverage_status,array('uncovered','weak_coverage','partial_coverage','covered','duplicate','conflict'),true),
                'detail'=>'Siempre se comprueba la cobertura antes de crear o modificar contenido.'
            ),
            'duplication_below_threshold'=>array(
                'pass'=>$risk_ok,
                'detail'=>'CREATE_POST sólo puede avanzar con bajo riesgo de duplicación/canibalización.'
            ),
        );
    }

    private static function improvement_action(array $coverage) {
        $type = sanitize_key((string) ($coverage['entity_type'] ?? ''));
        return $type === 'post' ? 'IMPROVE_POST' : 'DEFER';
    }

    private static function decision(array $profile,array $stats,array $coverage,array $knowledge,array $risks,$primary_category_id,array $landing,array $requirements) {
        $coverage_status = sanitize_key((string) ($coverage['status'] ?? 'uncovered'));
        $entity_type = sanitize_key((string) ($coverage['entity_type'] ?? ''));

        if (!$primary_category_id) {
            return array('action'=>'DEFER','reason'=>'No existe una product_cat demostrable para este conocimiento de Academia.');
        }
        if (!self::evidence_gate($stats)) {
            return array('action'=>'DEFER','reason'=>'El dossier todavía no alcanza la masa crítica de preguntas aprendidas.');
        }
        if ($coverage_status === 'conflict') {
            return array('action'=>'DEFER','reason'=>'La cobertura existente es contradictoria y requiere revisión humana antes de editar.');
        }
        if ($coverage_status === 'duplicate') {
            return array('action'=>'MERGE_CONTENT','reason'=>'Existen varias piezas solapadas; consolidar antes de crear otra URL.');
        }
        if ($coverage_status === 'covered') {
            return array('action'=>'NO_ACTION','reason'=>'La intención básica de la categoría ya está suficientemente cubierta.');
        }
        if (in_array($coverage_status,array('partial_coverage','weak_coverage'),true)) {
            $action = self::improvement_action($coverage);
            if ($action === 'IMPROVE_POST') {
                return array('action'=>'IMPROVE_POST','reason'=>'Existe un post de la misma intención con cobertura parcial o débil; se amplía antes de crear otro.');
            }
            return array('action'=>'DEFER','reason'=>'Existe cobertura parcial fuera de un post editable equivalente; requiere revisión antes de crear contenido.');
        }

        $risk_ok = !empty($requirements['duplication_below_threshold']['pass']);
        if ($coverage_status === 'uncovered' && $risk_ok) {
            return array('action'=>'CREATE_POST','reason'=>'Dossier básico con preguntas aprendidas suficientes, categoría resuelta y sin cobertura equivalente.');
        }

        return array('action'=>'DEFER','reason'=>'Las condiciones del dossier todavía no justifican una actuación editorial.');
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
        if ($action === 'DEFER') return 'deferred';
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
        if (is_array($last) && !empty($last['at']) && !empty($last['complete'])) {
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

    private static function fresh_editorial_scan_state($token, array $coverage_index = array()) {
        return array(
            'token'=>sanitize_text_field((string) $token),
            'cursor'=>0,
            'processed'=>0,
            'accepted'=>0,
            'discarded'=>0,
            'complete'=>false,
            'started_at'=>current_time('mysql'),
            'updated_at'=>current_time('mysql'),
            'completed_at'=>'',
            'coverage_index'=>$coverage_index,
        );
    }

    private static function analyze_topic($topic_id) {
        global $wpdb;
        $topic = SEO_Solucionador_DB::get_topic($topic_id);
        if (!$topic) return false;

        $profile = array(
            'intent'=>(string) ($topic['intent'] ?? ''),
            'key_intent'=>(string) ($topic['intent'] ?? ''),
            'action'=>(string) ($topic['action_term'] ?? ''),
            'object'=>(string) ($topic['object_term'] ?? ''),
            'condition'=>(string) ($topic['condition_term'] ?? ''),
            'context'=>(string) ($topic['context_term'] ?? ''),
            'canonical_key'=>(string) ($topic['canonical_key'] ?? ''),
            'category_id'=>absint($topic['primary_category_id'] ?? 0),
        );

        $topic_id = absint($topic['id'] ?? 0);
        $stats = SEO_Solucionador_DB::topic_stats($topic_id);
        $question = self::representative_question($topic_id,(string) ($topic['canonical_question'] ?? ''));

        $primary_category_id = absint($profile['category_id'] ?? 0);
        $proposal = SEO_Solucionador_Catalog::build_proposal($topic_id,$profile);
        if ($primary_category_id) {
            $term = get_term($primary_category_id,'product_cat');
            if ($term && !is_wp_error($term)) {
                $proposal['categories'] = array(array(
                    'id'=>$primary_category_id,
                    'term_id'=>$primary_category_id,
                    'name'=>(string) $term->name,
                    'slug'=>(string) $term->slug,
                    'score'=>1.0,
                ));
            }
        }

        $hierarchy = self::hierarchy($primary_category_id);
        $coverage = SEO_Editorial_Coverage::find($profile);
        $coverage_status = sanitize_key((string) ($coverage['status'] ?? 'uncovered')) ?: 'uncovered';
        $knowledge = self::knowledge_status($primary_category_id,$stats);
        $risks = self::risks($coverage,$primary_category_id);
        $suggested_title = self::suggested_title($profile);
        $requirements = self::requirements($stats,$coverage,$knowledge,$risks,$primary_category_id,array());
        $decision = self::decision($profile,$stats,$coverage,$knowledge,$risks,$primary_category_id,array(),$requirements);
        $priority = self::priority_components($stats,$coverage,$knowledge,$proposal,$primary_category_id,$risks);

        $draft_id = absint($topic['draft_post_id'] ?? 0);
        $draft_status = $draft_id ? get_post_status($draft_id) : false;
        if ($draft_id && $draft_status && $draft_status !== 'trash') {
            $coverage_status = $draft_status === 'publish' ? 'covered' : $coverage_status;
            $decision = array(
                'action'=>'NO_ACTION',
                'reason'=>$draft_status === 'publish'
                    ? 'La propuesta ya se publicó; no se crea otra pieza.'
                    : 'Existe un borrador de trabajo asociado; no se crea otra pieza.'
            );
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
        if ($draft_id && $draft_status === 'publish') $workflow_state = 'published';
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

        $topics_table = SEO_Solucionador_DB::topics_table();
        return $wpdb->update($topics_table,array(
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
            'comentarista_evidence'=>0,
            'analyst_evidence'=>0,
            'auditor_evidence'=>0,
            'zero_result_evidence'=>0,
            'negative_feedback_evidence'=>0,
            'last_analyzed_at'=>current_time('mysql'),
            'updated_at'=>current_time('mysql'),
        ),array('id'=>$topic_id)) !== false;
    }

    /**
     * Materializa/actualiza el topic interno de una categoría concreta.
     * La interfaz simplificada trabaja desde dossiers y usa este método sólo
     * cuando necesita ejecutar una acción humana sobre una propuesta.
     */
    public static function prepare_category_topic($category_id) {
        $category_id = absint($category_id);
        if (!$category_id || !class_exists('SEO_Solucionador_Dossiers')) {
            return new WP_Error('solucionador_category_missing', 'La categoría propuesta no es válida.');
        }

        $dossier = SEO_Solucionador_Dossiers::get_by_category($category_id);
        if (!$dossier || absint($dossier['question_count'] ?? 0) < 1) {
            return new WP_Error('solucionador_dossier_missing', 'No existe un dossier con preguntas aprendidas para esta categoría.');
        }

        $name = trim((string) ($dossier['category_name'] ?? ''));
        if ($name === '') {
            $term = get_term($category_id, 'product_cat');
            if ($term && !is_wp_error($term)) $name = (string) $term->name;
        }
        if ($name === '') {
            return new WP_Error('solucionador_category_name_missing', 'No se pudo resolver el nombre de la categoría.');
        }

        $ids = SEO_Solucionador_DB::decode_json($dossier['question_ids'] ?? '[]', array());
        $source = array(
            'dossier_id'=>absint($dossier['id'] ?? 0),
            'source_type'=>'dependiente',
            'proposal_role'=>'origin',
            'source_id'=>'academy-category:' . $category_id,
            'signal_type'=>'learned_category_dossier',
            'entity_type'=>'product_cat',
            'entity_id'=>$category_id,
            'category_id'=>$category_id,
            'category_name'=>$name,
            'source_text'=>'Preguntas habituales sobre ' . $name . ': conocimiento aprendido por Dependiente.',
            'hints'=>array(
                'intent'=>'dependiente_qa_basic',
                'action'=>'resolver',
                'object'=>$name,
                'category_id'=>$category_id,
            ),
            'occurrences'=>max(1,absint($dossier['question_count'] ?? 1)),
            'confidence'=>max(0.60,min(1.0,(float) ($dossier['score_avg'] ?? 0.90))),
            'evidence_score'=>1.00,
            'observed_at'=>(string) ($dossier['last_validated_at'] ?? current_time('mysql')),
            'source_meta'=>array(
                'proposal_role'=>'origin',
                'dependiente_channel'=>'academy_learned_dossier',
                'editorial_family'=>'dependiente_qa_basic',
                'dossier_id'=>absint($dossier['id'] ?? 0),
                'category_id'=>$category_id,
                'category_name'=>$name,
                'question_count'=>absint($dossier['question_count'] ?? 0),
                'question_ids'=>array_values(array_filter(array_map('absint',(array) $ids))),
                'source_hash'=>(string) ($dossier['source_hash'] ?? ''),
                'last_validated_at'=>(string) ($dossier['last_validated_at'] ?? ''),
            ),
        );

        $profile = SEO_Solucionador_Normalizer::profile(
            (string) $source['source_text'],
            (array) $source['hints']
        );
        $profile = self::canonicalize_profile((array) $profile, $source);
        if (!$profile || SEO_Solucionador_Normalizer::is_weak_profile($profile)) {
            return new WP_Error('solucionador_profile_invalid', 'No se pudo preparar el perfil editorial de la categoría.');
        }

        $topic_id = SEO_Solucionador_DB::upsert_topic($profile, (string) $source['source_text']);
        if (!$topic_id) {
            return new WP_Error('solucionador_topic_write', 'No se pudo preparar la propuesta editorial.');
        }

        SEO_Solucionador_DB::add_evidence($topic_id, $source);
        self::analyze_topic($topic_id);

        $topic = SEO_Solucionador_DB::get_topic($topic_id);
        return $topic ?: new WP_Error('solucionador_topic_missing', 'La propuesta no quedó disponible tras analizarla.');
    }

    public static function scan($days = 180, $batch_size = 100) {
        SEO_Solucionador_DB::maybe_install();
        $batch_size = max(25,min(250,absint($batch_size)));

        // Si el ciclo anterior terminó, una nueva ejecución manual inicia un
        // ciclo completo nuevo. Si quedó a medias, conserva ambos cursores.
        $last_scan = get_option('seo_solucionador_last_scan', array());
        if (is_array($last_scan) && !empty($last_scan['complete'])) {
            SEO_Solucionador_Dossiers::reset_scan();
            delete_option(self::EDITORIAL_SCAN_OPTION);
        }

        // Fase 1: construir/actualizar dossiers ligeros de Academia de forma
        // reanudable. Mientras no termine, no se cargan todas las preguntas.
        $academy = SEO_Solucionador_Dossiers::scan_batch($batch_size,false);
        if (is_wp_error($academy)) return $academy;

        if (empty($academy['scan_complete'])) {
            $result = array(
                'at'=>time(),
                'days'=>absint($days),
                'complete'=>false,
                'phase'=>'academia_dossiers',
                'academy'=>$academy,
                'message'=>'Academia se está procesando por lotes. Vuelve a ejecutar para continuar.',
            );
            update_option('seo_solucionador_last_scan',$result,false);
            return $result;
        }

        $token = sanitize_text_field((string) ($academy['scan_token'] ?? ''));
        $state = get_option(self::EDITORIAL_SCAN_OPTION,array());
        if (!is_array($state)) $state = array();

        if (empty($state['token']) || (string) $state['token'] !== $token || !empty($state['complete'])) {
            SEO_Solucionador_DB::begin_scan();
            $coverage_index = SEO_Editorial_Coverage::rebuild_index(7000);
            $state = self::fresh_editorial_scan_state($token,$coverage_index);
            update_option(self::EDITORIAL_SCAN_OPTION,$state,false);
        }

        $cursor = absint($state['cursor'] ?? 0);
        $sources = SEO_Solucionador_Sources::all($days,$batch_size,$cursor);
        $topic_ids = array();
        $last_cursor = $cursor;
        $accepted = 0;
        $discarded = 0;

        foreach ($sources as $source) {
            $dossier_id = absint($source['dossier_id'] ?? 0);
            if (!$dossier_id) {
                $meta = is_array($source['source_meta'] ?? null) ? $source['source_meta'] : array();
                $dossier_id = absint($meta['dossier_id'] ?? 0);
            }
            if ($dossier_id > $last_cursor) $last_cursor = $dossier_id;

            $profile = SEO_Solucionador_Normalizer::profile(
                (string) ($source['source_text'] ?? ''),
                (array) ($source['hints'] ?? array())
            );
            $profile = self::canonicalize_profile((array) $profile,$source);
            if (!$profile || SEO_Solucionador_Normalizer::is_weak_profile($profile)) {
                $discarded++;
                continue;
            }

            $topic_id = SEO_Solucionador_DB::upsert_topic(
                $profile,
                (string) ($source['source_text'] ?? '')
            );
            if (!$topic_id) {
                $discarded++;
                continue;
            }

            SEO_Solucionador_DB::add_evidence($topic_id,$source);
            $topic_ids[$topic_id] = true;
            $accepted++;
        }

        foreach (array_keys($topic_ids) as $topic_id) {
            try {
                self::analyze_topic(absint($topic_id));
            } catch (Throwable $e) {
                $discarded++;
            }
        }

        $state['cursor'] = $last_cursor;
        $state['processed'] = absint($state['processed'] ?? 0) + count($sources);
        $state['accepted'] = absint($state['accepted'] ?? 0) + $accepted;
        $state['discarded'] = absint($state['discarded'] ?? 0) + $discarded;
        $state['updated_at'] = current_time('mysql');

        $pruned_topics = 0;
        if (count($sources) < $batch_size) {
            $state['complete'] = true;
            $state['completed_at'] = current_time('mysql');
            $pruned_topics = SEO_Solucionador_DB::prune_orphan_topics();
        }
        update_option(self::EDITORIAL_SCAN_OPTION,$state,false);

        $coverage_index = (array) ($state['coverage_index'] ?? array());
        $result = array(
            'at'=>time(),
            'days'=>absint($days),
            'complete'=>!empty($state['complete']),
            'phase'=>!empty($state['complete']) ? 'complete' : 'editorial_dossiers',
            'academy'=>$academy,
            'editorial'=>array(
                'cursor'=>absint($state['cursor'] ?? 0),
                'processed'=>absint($state['processed'] ?? 0),
                'accepted'=>absint($state['accepted'] ?? 0),
                'discarded'=>absint($state['discarded'] ?? 0),
                'batch_size'=>$batch_size,
            ),
            'sources_seen'=>absint($state['processed'] ?? 0),
            'accepted'=>absint($state['accepted'] ?? 0),
            'discarded'=>absint($state['discarded'] ?? 0),
            'reinforcement_skipped'=>0,
            'pruned_topics'=>$pruned_topics,
            'seen_by_source'=>array('dependiente'=>absint($state['processed'] ?? 0)),
            'accepted_by_source'=>array('dependiente'=>absint($state['accepted'] ?? 0)),
            'discarded_by_source'=>array('dependiente'=>absint($state['discarded'] ?? 0)),
            'posts_indexed'=>absint($coverage_index['posts'] ?? 0),
            'pages_indexed'=>absint($coverage_index['pages'] ?? 0),
            'categories_indexed'=>absint($coverage_index['categories'] ?? 0),
            'post_topics_indexed'=>absint($coverage_index['topics'] ?? 0),
            'message'=>!empty($state['complete'])
                ? 'Análisis completado.'
                : 'Dossiers editoriales procesados por lotes. Vuelve a ejecutar para continuar.',
        );
        update_option('seo_solucionador_last_scan',$result,false);
        return $result;
    }
}
