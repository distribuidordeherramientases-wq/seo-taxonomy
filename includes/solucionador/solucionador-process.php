<?php
defined('ABSPATH') || exit;

final class SEO_Solucionador_Process implements SEO_Managed_Service_Process {
    const LOCK = 'seo_solucionador_process_manager_lock';

    public static function init() {
        add_filter('seo_process_supervisor_has_pending_work', array(__CLASS__,'filter_pending_work'), 40, 1);
        add_filter('seo_process_supervisor_manager_targets', array(__CLASS__,'filter_manager_targets'), 40, 3);
        add_filter('seo_processes_monitor_items', array(__CLASS__,'filter_monitor_items'), 40, 1);
    }

    private static function enabled() {
        if (!function_exists('seo_process_supervisor_settings')) return false;
        $settings = (array) seo_process_supervisor_settings();
        return !empty($settings['enabled']) && !empty($settings['solucionador']);
    }

    public static function has_pending() {
        if (!class_exists('SEO_Solucionador_Dossiers')) return false;
        $snapshot = SEO_Solucionador_Dossiers::snapshot();
        if (empty($snapshot['available'])) return false;

        $has_material = absint($snapshot['faqs_total'] ?? 0) > 0
            || absint($snapshot['dependiente_inventory_total'] ?? 0) > 0;
        if (!$has_material) return false;

        if (empty($snapshot['scan_complete']) || !empty($snapshot['source_changed'])) return true;

        $last = get_option('seo_solucionador_last_scan',array());
        return !is_array($last) || empty($last['complete']);
    }

    public static function process_slice($budget, $source = 'process_manager') {
        $budget = max(5, min(55, absint($budget)));
        if (!self::has_pending()) return false;
        if (get_transient(self::LOCK) || get_transient('seo_solucionador_auto_refresh_lock')) return false;

        set_transient(self::LOCK, 1, $budget + 30);
        $started = microtime(true);
        $processed = false;
        try {
            do {
                $result = SEO_Solucionador_Engine::scan(180, 250);
                if (is_wp_error($result)) {
                    self::managed_update('error', $result->get_error_message());
                    return false;
                }
                $processed = true;
                if (!empty($result['complete'])) break;
                if ((microtime(true) - $started) >= max(2, $budget - 2)) break;
            } while (self::has_pending());

            self::managed_update($processed ? 'processed' : 'waiting', '');
            return $processed;
        } finally {
            delete_transient(self::LOCK);
        }
    }

    public static function progress() {
        $s = SEO_Solucionador_Dossiers::snapshot();

        $faq_total = absint($s['faqs_total'] ?? 0);
        $dep_total = absint($s['dependiente_inventory_total'] ?? 0);
        $faq_done = min($faq_total,absint($s['faq_processed'] ?? 0));
        $dep_done = min($dep_total,absint($s['dependiente_processed'] ?? 0));
        $total = $faq_total + $dep_total;
        $done = $faq_done + $dep_done;

        return array(
            'processed'=>$done,
            'total'=>$total,
            'pending'=>max(0,$total-$done),
            'percentage'=>$total ? round(($done/$total)*100,1) : 0,
            // Compatibilidad: cursor numérico histórico = trainer question cursor.
            'cursor'=>absint($s['dependiente_cursor'] ?? $s['cursor'] ?? 0),
            'dependiente_cursor'=>absint($s['dependiente_cursor'] ?? 0),
            'dependiente_run_cursor'=>absint($s['dependiente_run_cursor'] ?? 0),
            'dependiente_semantic_cursor'=>absint($s['dependiente_semantic_cursor'] ?? 0),
            'faq_cursor'=>absint($s['faq_cursor'] ?? 0),
            'faq_processed'=>$faq_done,
            'faq_total'=>$faq_total,
            'dependiente_processed'=>$dep_done,
            'dependiente_total'=>$dep_total,
            'errors'=>absint($s['errors'] ?? 0),
            'updated_at'=>(string)($s['updated_at'] ?? ''),
            'categories_with_knowledge'=>absint($s['categories_with_knowledge'] ?? 0),
            'categories_total'=>absint($s['categories_total'] ?? 0),
            'learned'=>absint($s['dependiente_learned'] ?? $s['learned'] ?? 0),
            'eligible'=>absint($s['dependiente_editorial_eligible'] ?? $s['editorial_eligible'] ?? 0),
            'with_category'=>absint($s['dependiente_with_category'] ?? $s['learned_with_category'] ?? 0),
            'complete'=>!empty($s['scan_complete']) && empty($s['source_changed']) && !self::has_pending(),
        );
    }

    public static function health() {
        $p = self::progress();
        $updated = !empty($p['updated_at']) ? strtotime((string)$p['updated_at']) : 0;
        return array(
            'healthy'=>empty($p['errors']) || $p['errors'] < 10,
            'stalled'=>self::has_pending() && $updated && (time()-$updated) > 15*MINUTE_IN_SECONDS,
            'errors'=>$p['errors'],
            'last_activity'=>$updated,
        );
    }

    public static function pause() {
        update_option('seo_solucionador_process_paused',1,false);
        return true;
    }

    public static function resume() {
        delete_option('seo_solucionador_process_paused');
        if (function_exists('seo_process_supervisor_nudge')) seo_process_supervisor_nudge(0,'solucionador');
        return true;
    }

    public static function filter_pending_work($pending) {
        return $pending || (self::enabled() && empty(get_option('seo_solucionador_process_paused')) && self::has_pending());
    }

    public static function filter_manager_targets($targets, $settings, $source) {
        if (empty($settings['solucionador']) || get_option('seo_solucionador_process_paused') || !self::has_pending()) return $targets;
        $targets[] = array(
            'type'=>'solucionador',
            'data'=>array(),
            'callback'=>array(__CLASS__,'manager_target_callback'),
        );
        return $targets;
    }

    public static function manager_target_callback($budget, $source, $target) {
        return self::process_slice($budget, $source);
    }

    private static function managed_update($result, $error = '') {
        if (!function_exists('seo_process_supervisor_managed_update')) return;
        $p = self::progress();
        seo_process_supervisor_managed_update('solucionador', array(
            'name'=>'Solucionador',
            'pending'=>self::has_pending()?1:0,
            'healthy'=>$error===''?1:0,
            'last_checked'=>time(),
            'last_attempt_at'=>time(),
            'last_result'=>$result,
            'last_error'=>$error,
            'detail'=>number_format_i18n($p['processed']).'/'.number_format_i18n($p['total']).' preguntas · cursor '.number_format_i18n($p['cursor']).'.',
        ));
    }

    public static function filter_monitor_items($items) {
        $p = self::progress();
        $h = self::health();
        if (get_option('seo_solucionador_process_paused')) {
            $state = function_exists('seo_processes_state') ? seo_processes_state('stopped','Pausado','stopped') : array('tone'=>'stopped','label'=>'Pausado');
        } elseif (!empty($h['stalled'])) {
            $state = function_exists('seo_processes_state') ? seo_processes_state('warning','Sin avance','warning') : array('tone'=>'warning','label'=>'Sin avance');
        } elseif (!empty($p['complete'])) {
            $state = function_exists('seo_processes_state') ? seo_processes_state('completed','Completado','completed') : array('tone'=>'completed','label'=>'Completado');
        } else {
            $state = function_exists('seo_processes_state') ? seo_processes_state('running','En ejecución','running') : array('tone'=>'running','label'=>'En ejecución');
        }

        $items[] = array(
            'id'=>'solucionador',
            'name'=>'Solucionador',
            'kind'=>'Contenidos · dossiers desde Academia/Dependiente',
            'state'=>$state,
            'speed'=>'por ventanas del Gestor',
            'response'=>number_format_i18n($p['processed']).' preguntas procesadas',
            'load'=>'PHP/MySQL local · sin API externa',
            'activity'=>$p['updated_at'] ?: 'Sin actividad',
            'activity_age'=>null,
            'progress'=>$p['total'] ? $p['percentage'] : null,
            'progress_text'=>number_format_i18n($p['processed']).' / '.number_format_i18n($p['total']).' preguntas',
            'detail'=>'Cursor '.number_format_i18n($p['cursor']).' · '.number_format_i18n($p['categories_with_knowledge']).'/'.number_format_i18n($p['categories_total']).' categorías · '.number_format_i18n($p['errors']).' errores.',
            'url'=>add_query_arg(array('page'=>'seo-solucionador'),admin_url('admin.php')),
            'can_start'=>false,
        );
        return $items;
    }
}

SEO_Solucionador_Process::init();
