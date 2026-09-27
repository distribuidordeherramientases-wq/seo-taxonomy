<?php
defined('ABSPATH') || exit;

final class SEO_Investigador_Process {
    const LOCK_OPTION = 'seo_investigador_process_lock';
    public static function init() {
        add_filter('seo_process_supervisor_has_pending_work', array(__CLASS__, 'filter_pending_work'), 30, 1);
        add_filter('seo_process_supervisor_manager_targets', array(__CLASS__, 'filter_manager_targets'), 30, 3);
        add_filter('seo_processes_monitor_items', array(__CLASS__, 'filter_monitor_items'), 30, 1);
        add_action('seo_investigador_worker_tick', array(__CLASS__, 'fallback_tick'));
    }

    public static function filter_pending_work($pending) {
        $settings = function_exists('seo_process_supervisor_settings') ? (array) seo_process_supervisor_settings() : array('investigador'=>1);
        return $pending || (!empty($settings['investigador']) && SEO_Investigador::is_pending());
    }

    public static function filter_manager_targets($targets, $settings, $source) {
        if (empty($settings['investigador']) || !SEO_Investigador::is_pending()) return $targets;
        $targets[] = array(
            'type'=>'investigador',
            'data'=>array(),
            'callback'=>array(__CLASS__, 'manager_target_callback'),
        );
        return $targets;
    }

    public static function manager_target_callback($budget, $source, $target) {
        return self::process_slice(max(5, absint($budget)), sanitize_key((string) $source));
    }

    public static function fallback_tick() {
        if (!SEO_Investigador::is_pending()) return;
        self::process_slice(20, 'scheduler_fallback');
        if (SEO_Investigador::is_pending()) SEO_Investigador::schedule_fallback(20);
    }

    public static function process_slice($budget = 20, $source = 'process_manager') {
        if (!SEO_Investigador::is_pending()) return false;
        if (!self::acquire_lock()) return false;

        $budget = max(5, min(55, absint($budget)));
        $started = microtime(true);
        $state = SEO_Investigador::state();
        $batch_size = max(1, min(3, absint($state['batch_size'] ?? 1)));
        $processed_now = 0;
        $learned_now = 0;
        $review_now = 0;
        $errors_now = 0;

        while (SEO_Investigador::is_pending() && $processed_now < $batch_size) {
            if ((microtime(true) - $started) >= max(3, $budget - 2)) break;

            $state = SEO_Investigador::state();
            $cursor = absint($state['cursor'] ?? 0);
            $term_id = SEO_Investigador::next_term_id();
            if (!$term_id) break;

            $term = get_term($term_id, 'product_cat');
            $label = $term && !is_wp_error($term) ? (string) $term->name : ('#' . $term_id);
            SEO_Investigador::set_category_state($term_id, 'investigando', array('last_error'=>''));
            SEO_Investigador::save_state(array(
                'last_term_id'=>$term_id,
                'last_activity_at'=>time(),
                'last_message'=>'Investigando ' . $label . '…',
                'last_error'=>'',
            ));

            $result = SEO_Investigador::research_category($term_id);
            $processed_now++;
            if (is_wp_error($result)) {
                $errors_now++;
                $blocking_error = in_array($result->get_error_code(), array('investigador_budget','investigador_serpapi_key','investigador_serpapi_api'), true);
                SEO_Investigador::set_category_state($term_id, 'error', array(
                    'last_error'=>$result->get_error_message(),
                ));

                if ($blocking_error) {
                    // No avanzar cursor: tras corregir cuota/configuración se reintenta la misma categoría.
                    SEO_Investigador::save_state(array(
                        'enabled'=>0,
                        'status'=>'stopped',
                        'errors'=>absint($state['errors'] ?? 0)+1,
                        'last_error'=>$result->get_error_message(),
                        'last_message'=>'Investigación pausada por configuración/cuota externa. Corrige la conexión y pulsa Iniciar/continuar.',
                        'last_activity_at'=>time(),
                    ));
                    break;
                }

                SEO_Investigador::save_state(array(
                    'cursor'=>$cursor+1,
                    'processed'=>absint($state['processed'] ?? 0)+1,
                    'errors'=>absint($state['errors'] ?? 0)+1,
                    'last_error'=>$result->get_error_message(),
                    'last_message'=>'Error en ' . $label . ': ' . $result->get_error_message(),
                    'last_activity_at'=>time(),
                ));
                continue;
            }

            $needs_review = absint($result['review'] ?? 0) > 0;
            if ($needs_review) $review_now++;
            else $learned_now++;

            SEO_Investigador::set_category_state($term_id, $needs_review ? 'revisar' : 'aprendido', array(
                'last_error'=>'',
                'sources'=>absint($result['sources'] ?? 0),
                'knowledge'=>absint($result['knowledge'] ?? 0),
                'confidence'=>SEO_Investigador::category_confidence($term_id),
                'last_research_at'=>time(),
            ));

            SEO_Investigador::save_state(array(
                'cursor'=>$cursor+1,
                'processed'=>absint($state['processed'] ?? 0)+1,
                'learned'=>absint($state['learned'] ?? 0)+($needs_review?0:1),
                'review'=>absint($state['review'] ?? 0)+($needs_review?1:0),
                'last_error'=>'',
                'last_message'=>$label . ': ' . absint($result['sources'] ?? 0) . ' fuentes, ' . absint($result['knowledge'] ?? 0) . ' bloques de conocimiento.',
                'last_activity_at'=>time(),
            ));
        }

        $duration = max(0.001, microtime(true)-$started);
        $state = SEO_Investigador::state();
        $next_batch = max(1, min(3, absint($state['batch_size'] ?? 1)));
        if ($processed_now > 0) {
            $per_category = $duration / $processed_now;
            if ($per_category < 8 && $next_batch < 3) $next_batch++;
            elseif ($per_category > 20 && $next_batch > 1) $next_batch--;
        }

        $changes = array(
            'batch_size'=>$next_batch,
            'last_duration'=>round($duration,3),
            'last_activity_at'=>time(),
        );

        if (!SEO_Investigador::is_pending()) {
            $fresh = SEO_Investigador::state();
            if (absint($fresh['cursor'] ?? 0) >= count((array) $fresh['queue'])) {
                $changes['enabled'] = 0;
                $changes['status'] = 'completed';
                $changes['completed_at'] = time();
                $changes['last_message'] = 'L1 completada para las categorías preparadas.';
                SEO_Investigador::clear_fallback();
            }
        }
        SEO_Investigador::save_state($changes);

        if (function_exists('seo_process_supervisor_managed_update')) {
            $progress = SEO_Investigador::progress();
            seo_process_supervisor_managed_update('investigador', array(
                'name'=>'Investigador',
                'pending'=>SEO_Investigador::is_pending()?1:0,
                'healthy'=>1,
                'last_checked'=>time(),
                'last_attempt_at'=>time(),
                'last_result'=>$processed_now?'processed':'waiting',
                'last_error'=>(string) (SEO_Investigador::state()['last_error'] ?? ''),
                'detail'=>number_format_i18n($progress['processed']) . '/' . number_format_i18n($progress['total']) . ' categorías · lote adaptativo ' . $next_batch . '.',
            ));
        }

        self::release_lock();
        return $processed_now > 0;
    }

    private static function acquire_lock() {
        $lock = get_option(self::LOCK_OPTION, array());
        if (is_array($lock) && !empty($lock)) {
            $at = absint($lock['at'] ?? 0);
            if ($at && (time()-$at) > 120) delete_option(self::LOCK_OPTION);
        }
        return add_option(self::LOCK_OPTION, array('at'=>time(),'token'=>wp_generate_password(10,false,false)), '', false);
    }

    private static function release_lock() {
        delete_option(self::LOCK_OPTION);
    }

    public static function filter_monitor_items($items) {
        $state = SEO_Investigador::state();
        $progress = SEO_Investigador::progress();
        $status = sanitize_key((string) ($state['status'] ?? 'stopped'));

        if ('running' === $status && SEO_Investigador::is_pending()) {
            $view_state = function_exists('seo_processes_state') ? seo_processes_state('running','En ejecución','running') : array('tone'=>'running','label'=>'En ejecución');
        } elseif ('completed' === $status) {
            $view_state = function_exists('seo_processes_state') ? seo_processes_state('completed','Completado','completed') : array('tone'=>'completed','label'=>'Completado');
        } elseif (!empty($state['last_error'])) {
            $view_state = function_exists('seo_processes_state') ? seo_processes_state('error','Error','error') : array('tone'=>'error','label'=>'Error');
        } else {
            $view_state = function_exists('seo_processes_state') ? seo_processes_state('stopped','Parado','stopped') : array('tone'=>'stopped','label'=>'Parado');
        }

        $duration = max(0, (float) ($state['last_duration'] ?? 0));
        $batch = max(1, absint($state['batch_size'] ?? 1));
        $speed = ($duration > 0) ? number_format_i18n(($batch/$duration)*60, 1) . ' categorías/min aprox.' : 'Sin lote medido';
        $usage = SEO_Investigador_SerpApi_Provider::usage_month();

        $items[] = array(
            'id'=>'investigador',
            'name'=>'Investigador',
            'kind'=>'Dependiente · conocimiento técnico externo',
            'state'=>$view_state,
            'speed'=>$speed,
            'response'=>$duration > 0 ? number_format_i18n($duration,2) . ' s último lote' : 'Sin lote medido',
            'load'=>'L1 técnica · SerpApi ' . number_format_i18n($usage['used']) . '/' . number_format_i18n($usage['limit']),
            'activity'=>!empty($state['last_activity_at']) ? gmdate('Y-m-d H:i:s', absint($state['last_activity_at'])) . ' UTC' : 'Sin actividad',
            'activity_age'=>null,
            'progress'=>$progress['total'] ? $progress['percentage'] : null,
            'progress_text'=>number_format_i18n($progress['processed']) . ' / ' . number_format_i18n($progress['total']) . ' categorías',
            'detail'=>(string) ($state['last_message'] ?? ''),
            'url'=>add_query_arg(array('page'=>'seo-dependiente','tab'=>'researcher'), admin_url('admin.php')),
            'can_start'=>!SEO_Investigador::is_pending() && $progress['pending'] > 0,
            'start_label'=>'Iniciar / continuar',
        );

        return $items;
    }
}

SEO_Investigador_Process::init();
