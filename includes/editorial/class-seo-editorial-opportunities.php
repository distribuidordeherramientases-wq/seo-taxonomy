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

    private static function analyst_rows() {
        $history = get_option('seo_analista_task_history_381', array());
        $out = array();
        if (!is_array($history)) return $out;

        foreach ($history as $id => $entry) {
            if (!is_array($entry) || in_array(sanitize_key((string) ($entry['status'] ?? '')), array('executed','dismissed'), true)) continue;
            $task = (array) ($entry['last_task'] ?? array());
            $action = strtoupper(sanitize_text_field((string) ($task['action_type'] ?? '')));
            $bucket = strtoupper(sanitize_text_field((string) ($task['final_bucket'] ?? '')));
            $kind = '';
            if (in_array($action, array('ESTUDIAR_LANDING','POTENCIAR_LANDING'), true)) $kind = 'landing';
            if (in_array($action, array('CREAR_POST','CREAR_CONTENIDO','MEJORAR_POST','ACTUALIZAR_POST','MEJORAR_COBERTURA'), true)) $kind = 'post';
            if ($kind === '' || in_array($bucket, array('SIN_ACCION','VIGILAR','ESPERAR_DATOS'), true)) continue;
            $query = sanitize_text_field((string) ($task['query'] ?? ''));
            if ($query === '') continue;
            $entity_type = sanitize_key((string) ($task['entity_type'] ?? ''));
            $entity_id = absint($task['entity_id'] ?? 0);
            $categories = self::categories_for($entity_type, $entity_id);
            $out[] = array(
                'key'=>'analista:' . sanitize_key((string) ($task['task_id'] ?? $id)),
                'source'=>'Analista',
                'kind'=>$kind,
                'action'=>$action,
                'topic'=>$query,
                'intent'=>$query,
                'entity_type'=>$entity_type,
                'entity_id'=>$entity_id,
                'category_ids'=>$categories,
                'priority'=>absint($task['priority_score'] ?? 0),
                'bucket'=>$bucket,
                'reason'=>sanitize_text_field((string) ($task['task_decision']['decision_reason'] ?? $task['unlock_condition'] ?? '')),
                'observed_at'=>sanitize_text_field((string) ($entry['last_seen'] ?? '')),
                'confidence'=>sanitize_key((string) ($task['confidence_level'] ?? 'unknown')),
            );
        }
        return $out;
    }

    private static function auditor_rows() {
        $report = get_option('seo_auditor_last_catalog_report', array());
        if (!is_array($report)) return array();
        $out = array();
        foreach (array_slice((array) ($report['priority_queue'] ?? array()), 0, 2000) as $task) {
            if (!is_array($task) || ($task['status'] ?? '') === 'RESOLVED') continue;
            $entity_type = sanitize_key((string) ($task['entity_type'] ?? ''));
            $entity_id = absint($task['entity_id'] ?? 0);
            $code = sanitize_key((string) ($task['code'] ?? ''));
            $problem = (string) ($task['problem'] ?? '');
            $recommendation = (string) ($task['recommendation'] ?? '');
            $kind = '';
            if (preg_match('/landing/i', $code . ' ' . $problem . ' ' . $recommendation) && in_array($entity_type, array('page','landing','category'), true)) {
                $kind = 'landing';
            } elseif (in_array((string) ($task['action_class'] ?? ''), array('MIGRAR_A_SOLUCIONADOR'), true)
                || ($entity_type === 'post' && preg_match('/post|contenido|gu.a/i', $problem . ' ' . $recommendation))) {
                $kind = 'post';
            }
            if ($kind === '') continue;
            $categories = self::categories_for($entity_type, $entity_id);
            $out[] = array(
                'key'=>'auditor:' . sanitize_key((string) ($task['task_id'] ?? '')),
                'source'=>'Auditor',
                'kind'=>$kind,
                'action'=>sanitize_key((string) ($task['action_class'] ?? 'REVISAR')),
                'topic'=>sanitize_text_field((string) ($task['title'] ?? '')),
                'intent'=>'', // Auditor diagnoses structure, never proves a buyer intent.
                'entity_type'=>$entity_type,
                'entity_id'=>$entity_id,
                'category_ids'=>$categories,
                'priority'=>absint($task['priority_score'] ?? 0),
                'bucket'=>sanitize_key((string) ($task['status'] ?? '')),
                'reason'=>sanitize_text_field($problem . ' ' . $recommendation),
                'observed_at'=>sanitize_text_field((string) ($report['generated_at'] ?? '')),
                'confidence'=>'diagnostic',
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
        $rows = array_merge(self::analyst_rows(), self::auditor_rows());
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
        set_transient('seo_editorial_opportunities_sync_lock', 1, 8 * MINUTE_IN_SECONDS);
        $result = array('landings'=>0,'post_signals'=>0,'no_knowledge'=>0,'errors'=>0,'last_run'=>current_time('mysql'));
        try {
            foreach (array_slice(self::records('landing'), 0, 60) as $signal) {
                // A recommendation to improve an existing landing must not create a new URL.
                if ($signal['source'] !== 'Analista' || $signal['action'] !== 'ESTUDIAR_LANDING') continue;
                foreach (array('solucionador','ingeniero') as $service) {
                    $res = SEO_Editorial_Landings::absorb_signal($signal, $service);
                    if (is_wp_error($res)) $result['errors']++;
                    elseif ($res === 'no_knowledge') $result['no_knowledge']++;
                    elseif ($res) $result['landings']++;
                }
            }
            foreach (array_slice(self::records('post'), 0, 60) as $signal) {
                if ($signal['source'] !== 'Analista') continue;
                foreach ((array) ($signal['category_ids'] ?? array()) as $id) {
                    $result['post_signals']++;
                    if (class_exists('SEO_Solucionador_Engine') && class_exists('SEO_Solucionador_Dossiers') &&
                        SEO_Solucionador_Dossiers::get_by_category($id)) {
                        SEO_Solucionador_Engine::prepare_category_topic($id);
                    }
                    if (class_exists('SEO_Ingeniero') && SEO_Ingeniero::editorial_knowledge($id)) {
                        SEO_Ingeniero::refresh_editorial_category($id, false);
                    }
                }
            }
            $health = self::source_health();
            $pending = SEO_Editorial_Landings::count_pending();
            $unresolved = count(self::records('landing')) + count(self::records('post'));
            $result['source_health'] = $health;
            $result['unresolved_signals'] = $unresolved;
            $result['pending_landing_proposals'] = $pending;
            $result['coverage_status'] = (!$health['analista']['fresh'] || !$health['auditor']['fresh'])
                ? 'needs_fresh_diagnostics'
                : (($unresolved || $pending) ? 'open_gaps' : 'no_open_gaps_in_snapshots');
            update_option(self::STATE_OPTION, $result, false);
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
