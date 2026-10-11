<?php
/**
 * Editorial opportunities supplied by Analista and Auditor.
 *
 * Neither service creates editorial material here. Only persisted, local
 * diagnostic snapshots are read; no network/API work runs on admin page load.
 */
defined('ABSPATH') || exit;

final class SEO_Editorial_Opportunities {
    const SYNC_HOOK = 'seo_editorial_opportunities_sync_v1';
    const STATE_OPTION = 'seo_editorial_opportunities_state_v1';

    public static function init() {
        add_action(self::SYNC_HOOK, array(__CLASS__, 'sync'));
        add_action('update_option_seo_analista_task_history_381', array(__CLASS__, 'queue_refresh'), 10, 2);
        add_action('update_option_seo_auditor_last_catalog_report', array(__CLASS__, 'queue_refresh'), 10, 2);
        add_action('init', array(__CLASS__, 'ensure_schedule'), 30);
    }

    public static function ensure_schedule() {
        if (!wp_next_scheduled(self::SYNC_HOOK)) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', self::SYNC_HOOK);
        }
    }

    public static function queue_refresh($old = null, $new = null) {
        // Follow the next completed diagnostic cycle without holding up Auditor.
        if (!wp_next_scheduled(self::SYNC_HOOK, array('snapshot_update'))) {
            wp_schedule_single_event(time() + 90, self::SYNC_HOOK, array('snapshot_update'));
        }
    }

    /**
     * Classifies the action, not just a label from a legacy report. A request to
     * improve an existing URL never authorises a new page.
     */
    public static function classify($action, $entity_type = '', $query_intent = '', $has_target = false) {
        $action = strtoupper(sanitize_text_field((string) $action));
        $entity_type = sanitize_key((string) $entity_type);
        $query_intent = sanitize_key((string) $query_intent);
        $mode = $has_target ? 'improve_existing' : 'investigate';
        if (in_array($action, array('ESTUDIAR_LANDING','CREAR_LANDING','POTENCIAR_LANDING','MEJORAR_LANDING'), true)) {
            if ($action === 'ESTUDIAR_LANDING' && !$has_target) $mode = 'investigate';
            if ($action === 'CREAR_LANDING' && !$has_target) $mode = 'investigate';
            if (in_array($action, array('POTENCIAR_LANDING','MEJORAR_LANDING'), true)) $mode = 'improve_existing';
            return array('kind'=>'landing','mode'=>$mode);
        }
        if (in_array($action, array('CREAR_POST','MEJORAR_POST','ACTUALIZAR_POST','IMPROVE_POST','IMPROVE_EXISTING_POST'), true)) {
            return array('kind'=>'post','mode'=>$has_target ? 'improve_existing' : 'investigate');
        }
        if ($action === 'CREAR_CONTENIDO') {
            return in_array($query_intent,array('comercial','transaccional'),true)
                ? array('kind'=>'landing','mode'=>'investigate')
                : array('kind'=>'post','mode'=>'investigate');
        }
        if (in_array($action,array('REVISAR_COBERTURA','INVESTIGAR_COBERTURA','MEJORAR_COBERTURA'),true)) {
            if (in_array($query_intent,array('comercial','transaccional'),true)) {
                return array('kind'=>'landing','mode'=>$has_target ? 'improve_existing' : 'investigate');
            }
            if (in_array($query_intent,array('informacional','informativa','informativo'),true)) {
                return array('kind'=>'post','mode'=>$has_target ? 'improve_existing' : 'investigate');
            }
        }
        if ($entity_type === 'post' && in_array($action,array('MEJORAR_CONTENIDO','ACTUALIZAR_CONTENIDO'),true)) {
            return array('kind'=>'post','mode'=>'improve_existing');
        }
        // Category, product, hub and technical fixes remain with their own editors.
        return array('kind'=>'','mode'=>'');
    }

    private static function analyst_rows() {
        $history = get_option('seo_analista_task_history_381',array());
        if (!is_array($history)) return array();
        $out = array();
        foreach ($history as $id=>$entry) {
            if (!is_array($entry) || in_array(sanitize_key((string)($entry['status']??'')),array('executed','dismissed'),true)) continue;
            $task = (array)($entry['last_task']??array());
            $decision = (array)($task['task_decision']??array());
            $action = strtoupper(sanitize_text_field((string)($decision['intervention_type']??$task['action_type']??'')));
            $bucket = strtoupper(sanitize_key((string)($task['final_bucket']??'')));
            if (in_array($bucket,array('SIN_ACCION','VIGILAR','ESPERAR_DATOS'),true)) continue;
            $target = esc_url_raw((string)($decision['target_url']??$task['target_url']??''));
            $entity_type = sanitize_key((string)($task['entity_type']??''));
            $entity_id = absint($task['entity_id']??0);
            $intent = sanitize_key((string)($task['query_intent']??''));
            $class = self::classify($action,$entity_type,$intent,$target !== '');
            if ($class['kind'] === '') continue;
            $topic = sanitize_text_field((string)($task['query']??''));
            if ($topic === '') continue;
            $cats = self::categories_for($entity_type,$entity_id);
            $out[] = array(
                'key'=>'analista:'.sanitize_key((string)($task['task_id']??$id)),
                'source'=>'Analista','kind'=>$class['kind'],'mode'=>$class['mode'],
                'action'=>$action,'topic'=>$topic,'intent'=>$topic,
                'entity_type'=>$entity_type,'entity_id'=>$entity_id,'category_ids'=>$cats,
                'priority'=>absint($task['priority_score']??0),'bucket'=>$bucket,
                'target_url'=>$target,
                'reason'=>sanitize_text_field((string)($decision['decision_reason']??$task['unlock_condition']??'')),
                'observed_at'=>sanitize_text_field((string)($entry['last_seen']??'')),
                'confidence'=>sanitize_key((string)($task['confidence_level']??'unknown')),
            );
        }
        return $out;
    }

        private static function auditor_rows() {
        $report = get_option('seo_auditor_last_catalog_report',array());
        if (!is_array($report)) return array();
        $out = array();
        foreach (array_slice((array)($report['priority_queue']??array()),0,1500) as $task) {
            if (!is_array($task) || ($task['status']??'') === 'RESOLVED') continue;
            $type = sanitize_key((string)($task['entity_type']??''));
            $id = absint($task['entity_id']??0);
            $code = sanitize_key((string)($task['code']??''));
            $text = sanitize_text_field((string)($task['problem']??'').' '.(string)($task['recommendation']??''));
            $action = strtoupper(sanitize_key((string)($task['action_class']??'REVISAR')));
            $kind = '';
            if (preg_match('/landing|página comercial|pagina comercial/i',$code.' '.$text) && in_array($type,array('page','landing','category','product_cat','hub_secondary'),true)) {
                $kind='landing';
            } elseif ($type==='post' || $action==='MIGRAR_A_SOLUCIONADOR') {
                $kind='post';
            }
            if ($kind==='') continue;
            $mode = in_array($type,array('page','landing','post'),true) ? 'improve_existing' : 'investigate';
            $out[] = array(
                'key'=>'auditor:'.sanitize_key((string)($task['task_id']??'')),
                'source'=>'Auditor','kind'=>$kind,'mode'=>$mode,
                'action'=>$action,
                'topic'=>sanitize_text_field((string)($task['title']??'')),
                'intent'=>'','entity_type'=>$type,'entity_id'=>$id,
                'category_ids'=>self::categories_for($type,$id),
                'priority'=>absint($task['priority_score']??0),
                'bucket'=>sanitize_key((string)($task['status']??'')),
                'target_url'=>$mode==='improve_existing' && $id && in_array($type,array('post','page','landing'),true)
                    ? (string)get_permalink($id) : '',
                'reason'=>$text,
                'observed_at'=>sanitize_text_field((string)($report['generated_at']??'')),
                'confidence'=>'diagnostic',
            );
        }
        return $out;
    }

    /**
     * The Pages -> Projects landing backlog is already a first-class diagnostic
     * source. Read stored candidates only: never call Google/Trends at render.
     */
    private static function landing_inventory_rows() {
        global $wpdb;
        if (!function_exists('seo_landing_candidates_table')) return array();
        $table = seo_landing_candidates_table();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table))) !== $table) return array();
        $safe_table=esc_sql($table);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal known WP table.
        $rows=(array)$wpdb->get_results("SELECT * FROM {$safe_table} WHERE status IN ('detected','candidate','review','approved') ORDER BY total_score DESC,updated_at DESC LIMIT 120",ARRAY_A);
        $out=array();
        foreach ($rows as $row) {
            $key=sanitize_text_field((string)($row['external_key']??''));
            if (strpos($key,'editorial_landing_')===0) continue;
            $title=sanitize_text_field((string)($row['title']??''));
            if ($title==='') continue;
            $target=absint($row['page_id']??0);
            $requirements=function_exists('seo_landing_decode_json')?seo_landing_decode_json($row['requirements_json']??''):array();
            $has_existing = $target > 0 || (int)($requirements['differentiated_intent']??-1) === 0;
            $proposal=function_exists('seo_landing_decode_json')?seo_landing_decode_json($row['proposal_json']??''):array();
            $source=sanitize_text_field((string)($row['source']??'Páginas / Proyectos'));
            $out[]=array(
                'key'=>'paginas:'.absint($row['id']),
                'source'=>'Páginas / Proyectos: '.$source,
                'kind'=>'landing','mode'=>$has_existing?'improve_existing':'investigate',
                'action'=>$has_existing?'POTENCIAR_LANDING':'ESTUDIAR_LANDING',
                'topic'=>$title,'intent'=>$title,
                'entity_type'=>$target?'landing':'',
                'entity_id'=>$target,
                'candidate_id'=>absint($row['id']),
                'category_ids'=>array_values(array_unique(array_filter(array_map('absint',(array)($proposal['category_ids']??array()))))),
                'priority'=>min(100,absint($row['total_score']??0)),
                'bucket'=>sanitize_key((string)($row['status']??'candidate')),
                'target_url'=>$target?(string)get_permalink($target):'',
                'reason'=>sanitize_text_field((string)($row['intent']??'')),
                'observed_at'=>sanitize_text_field((string)($row['updated_at']??'')),
                'confidence'=>'candidate_review',
            );
        }
        return $out;
    }

        public static function categories_for($type, $id) {
        $type = sanitize_key((string) $type);
        $id = absint($id);
        if (!$id) return array();
        if (in_array($type, array('category','product_cat'), true)) {
            $term = get_term($id, 'product_cat');
            return $term && !is_wp_error($term) ? array($id) : array();
        }
        if ($type === 'product') {
            $ids = wp_get_post_terms($id, 'product_cat', array('fields'=>'ids'));
            return is_wp_error($ids) ? array() : array_values(array_filter(array_map('absint', (array) $ids)));
        }
        if (in_array($type, array('page','landing'), true) && function_exists('seo_page_editor_get_landing_category_ids')) {
            return array_values(array_filter(array_map('absint', (array) seo_page_editor_get_landing_category_ids($id))));
        }
        return array();
    }

    public static function records($kind = '') {
        $rows = array_merge(self::analyst_rows(), self::auditor_rows(), self::landing_inventory_rows());
        if ($kind !== '') $rows = array_values(array_filter($rows, static function($row) use ($kind) {
            return $row['kind'] === $kind;
        }));
        usort($rows, static function($a, $b) {
            return (int) ($b['priority'] ?? 0) <=> (int) ($a['priority'] ?? 0);
        });
        return $rows;
    }

    public static function source_health() {
        $history = get_option('seo_analista_task_history_381', array());
        $audit = get_option('seo_auditor_last_catalog_report', array());
        $analyst_date = '';
        foreach ((array) $history as $row) {
            $at = (string) ($row['last_seen'] ?? '');
            if ($at > $analyst_date) $analyst_date = $at;
        }
        $auditor_date = is_array($audit) ? (string) ($audit['generated_at'] ?? '') : '';
        $fresh = static function($date) {
            if (!$date) return false;
            $timestamp = strtotime($date);
            return $timestamp && $timestamp >= time() - (14 * DAY_IN_SECONDS);
        };
        return array(
            'analista'=>array('available'=>!empty($history),'fresh'=>$fresh($analyst_date),'date'=>$analyst_date),
            'auditor'=>array('available'=>!empty($audit),'fresh'=>$fresh($auditor_date),'date'=>$auditor_date),
        );
    }

    public static function relevant_auditor_tasks(array $category_ids, $kind) {
        $category_ids = array_values(array_unique(array_filter(array_map('absint',$category_ids))));
        if (!$category_ids) return array();
        $matched = array();
        foreach (self::auditor_rows() as $row) {
            if ($row['kind'] !== $kind) continue;
            if (array_intersect($category_ids, (array) ($row['category_ids'] ?? array()))) $matched[] = $row;
        }
        return array_slice($matched, 0, 8);
    }

    /**
     * Reconcile persisted diagnostic signals with editorial proposals.
     * No external search, no automatic WordPress draft, and no publication.
     */
    public static function sync($reason = 'periodic') {
        if (!class_exists('SEO_Editorial_Landings')) return;
        if (get_transient('seo_editorial_opportunities_sync_lock')) return;
        set_transient('seo_editorial_opportunities_sync_lock',1,8*MINUTE_IN_SECONDS);
        $result=array('landings'=>0,'post_signals'=>0,'no_knowledge'=>0,'errors'=>0,'improve_existing'=>0,'investigate'=>0,'last_run'=>current_time('mysql'));
        try {
            $rows=array_slice(self::records('landing'),0,100);
            foreach ($rows as $signal) {
                if (($signal['mode']??'')==='improve_existing') {
                    $result['improve_existing']++;
                    continue; // Existing URL: never create a competitor.
                }
                foreach (array('solucionador','ingeniero') as $service) {
                    $res=SEO_Editorial_Landings::absorb_signal($signal,$service);
                    if (is_wp_error($res)) $result['errors']++;
                    elseif ($res==='no_knowledge') $result['no_knowledge']++;
                    elseif ($res) $result['landings']++;
                }
            }
            // Post proposals use their existing service-specific dossier workflows.
            // No recursive automatic scans or external providers in this bridge.
            foreach (array_slice(self::records('post'),0,100) as $signal) {
                if (($signal['mode']??'')==='improve_existing') {
                    $result['improve_existing']++;
                    continue;
                }
                $result['post_signals']++;
            }
            $health=self::source_health();
            $pending=SEO_Editorial_Landings::count_pending();
            $result['source_health']=$health;
            $result['unresolved_signals']=count(self::records());
            $result['pending_landing_proposals']=$pending;
            $result['coverage_status']=(!$health['analista']['fresh'] || !$health['auditor']['fresh'])
                ? 'needs_fresh_diagnostics'
                : (($result['unresolved_signals'] || $pending)?'open_gaps':'no_open_gaps_in_snapshots');
            update_option(self::STATE_OPTION,$result,false);
        } finally {
            delete_transient('seo_editorial_opportunities_sync_lock');
        }
    }

        public static function state() {
        return (array) get_option(self::STATE_OPTION, array());
    }

    public static function render_post_context($service) {
        $rows = array_slice(self::records('post'), 0, 15);
        $health = self::source_health();
        echo '<div class="postbox" style="padding:14px;margin:16px 0">';
        echo '<h3>Prioridades de posts de Analista y Auditor</h3>';
        echo '<p class="description">Son señales diagnósticas para orientar los dossiers de ' . esc_html(ucfirst($service)) . '. No sustituyen el conocimiento de origen ni crean posts sin aprobación.</p>';
        if (empty($rows)) {
            echo '<p>No hay oportunidades explícitas de posts en los últimos diagnósticos guardados.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Origen</th><th>Necesidad</th><th>Acción</th><th>Estado / prioridad</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                echo '<tr><td>' . esc_html($row['source']) . '</td><td>' . esc_html($row['topic']) . '</td><td>' .
                    esc_html($row['action']) . '</td><td>' . esc_html($row['bucket']) . ' · ' . esc_html((string) $row['priority']) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        if (!$health['analista']['fresh'] || !$health['auditor']['fresh']) {
            echo '<p class="description">Atención: falta un diagnóstico reciente de Analista o Auditor. No puede declararse cobertura completa.</p>';
        }
        echo '</div>';
    }
}
