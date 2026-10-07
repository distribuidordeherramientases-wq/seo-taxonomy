<?php
defined('ABSPATH') || exit;

final class SEO_Ingeniero_Process implements SEO_Managed_Service_Process {
    const LOCK_OPTION = 'seo_ingeniero_process_lock';
    const CATEGORIES_PER_CYCLE = 1;
    const EDITORIAL_STATE_OPTION = 'seo_ingeniero_editorial_refresh_state';
    const EDITORIAL_REFRESH_HOOK = 'seo_ingeniero_editorial_refresh';
    const EDITORIAL_REFRESH_STEP_HOOK = 'seo_ingeniero_editorial_refresh_step';
    public static function init() {
        add_filter('seo_process_supervisor_has_pending_work', array(__CLASS__, 'filter_pending_work'), 30, 1);
        add_filter('seo_process_supervisor_manager_targets', array(__CLASS__, 'filter_manager_targets'), 30, 3);
        add_filter('seo_processes_monitor_items', array(__CLASS__, 'filter_monitor_items'), 30, 1);
        add_action('seo_ingeniero_worker_tick', array(__CLASS__, 'fallback_tick'));
        add_action('init', array(__CLASS__, 'schedule_editorial_refresh'), 22);
        add_action(self::EDITORIAL_REFRESH_HOOK, array(__CLASS__, 'editorial_refresh'));
        add_action(self::EDITORIAL_REFRESH_STEP_HOOK, array(__CLASS__, 'editorial_refresh'));
    }

    public static function schedule_editorial_refresh() {
        if (!wp_next_scheduled(self::EDITORIAL_REFRESH_HOOK)) {
            wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::EDITORIAL_REFRESH_HOOK);
        }
    }

    public static function kick_editorial_refresh() {
        if (!wp_next_scheduled(self::EDITORIAL_REFRESH_STEP_HOOK)) {
            wp_schedule_single_event(time() + 10, self::EDITORIAL_REFRESH_STEP_HOOK);
        }
    }

    private static function fresh_editorial_state() {
        return array(
            'contract_version'=>'full-category-dossier-v1',
            'cursor'=>0,
            'processed'=>0,
            'errors'=>0,
            'coverage_rebuilt'=>false,
            'complete'=>false,
            'started_at'=>current_time('mysql'),
            'updated_at'=>current_time('mysql'),
            'completed_at'=>'',
        );
    }

    private static function editorial_term_ids() {
        $stats = SEO_Ingeniero_DB::category_stats_map(SEO_Ingeniero::LESSON_TECHNICAL);
        $ids = array();
        foreach ((array) $stats as $term_id=>$row) {
            if (absint($row['active'] ?? 0) > 0) $ids[] = absint($term_id);
        }
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    public static function editorial_refresh() {
        if (function_exists('seo_process_supervisor_settings')) {
            $manager=(array)seo_process_supervisor_settings();
            if (!empty($manager['enabled']) && !empty($manager['ingeniero'])) {
                if (function_exists('seo_process_supervisor_nudge')) seo_process_supervisor_nudge(0,'ingeniero_editorial');
                if (function_exists('seo_process_supervisor_schedule_backup')) seo_process_supervisor_schedule_backup();
                return;
            }
        }

        $lock_key='seo_ingeniero_editorial_refresh_lock';
        if (get_transient($lock_key)) return;
        set_transient($lock_key,1,2*MINUTE_IN_SECONDS);

        try {
            SEO_Ingeniero_DB::maybe_install();
            $state=get_option(self::EDITORIAL_STATE_OPTION,array());
            if (!is_array($state)
                || !$state
                || (string)($state['contract_version'] ?? '') !== 'full-category-dossier-v1') {
                $state=self::fresh_editorial_state();
            }

            if (!empty($state['complete'])) {
                $completed=!empty($state['completed_at']) ? strtotime((string)$state['completed_at']) : 0;
                if ($completed && (time()-$completed) < 6*HOUR_IN_SECONDS) return;
                $state=self::fresh_editorial_state();
            }

            $ids=self::editorial_term_ids();
            if (!$ids) {
                $state['complete']=true;
                $state['completed_at']=current_time('mysql');
                $state['updated_at']=current_time('mysql');
                update_option(self::EDITORIAL_STATE_OPTION,$state,false);
                return;
            }

            if (empty($state['coverage_rebuilt']) && class_exists('SEO_Editorial_Coverage')) {
                SEO_Editorial_Coverage::rebuild_index(7000);
                $state['coverage_rebuilt']=true;
            }

            $cursor=max(0,absint($state['cursor'] ?? 0));
            $slice=array_slice($ids,$cursor,15);
            foreach ($slice as $term_id) {
                $result=SEO_Ingeniero::refresh_editorial_category($term_id,false);
                $state['processed']=absint($state['processed'] ?? 0)+1;
                if (is_wp_error($result)) {
                    $state['errors']=absint($state['errors'] ?? 0)+1;
                }
            }

            $state['cursor']=$cursor+count($slice);
            $state['updated_at']=current_time('mysql');
            if ($state['cursor'] >= count($ids)) {
                $state['complete']=true;
                $state['completed_at']=current_time('mysql');
            }
            update_option(self::EDITORIAL_STATE_OPTION,$state,false);

            if (empty($state['complete']) && !wp_next_scheduled(self::EDITORIAL_REFRESH_STEP_HOOK)) {
                wp_schedule_single_event(time()+20,self::EDITORIAL_REFRESH_STEP_HOOK);
            }
        } finally {
            delete_transient($lock_key);
        }
    }

    public static function filter_pending_work($pending) {
        $settings = function_exists('seo_process_supervisor_settings') ? (array) seo_process_supervisor_settings() : array('ingeniero'=>1);
        return $pending || (!empty($settings['ingeniero']) && self::has_pending());
    }

    public static function filter_manager_targets($targets, $settings, $source) {
        if (empty($settings['ingeniero'])) return $targets;

        if (SEO_Ingeniero::is_pending()) {
            $targets[] = array(
                'type'=>'ingeniero',
                'data'=>array(),
                'callback'=>array(__CLASS__, 'manager_target_callback'),
            );
        }
        if (self::editorial_has_pending()) {
            $targets[] = array(
                'type'=>'ingeniero_editorial',
                'data'=>array(),
                'callback'=>array(__CLASS__, 'editorial_manager_target_callback'),
            );
        }
        return $targets;
    }

    public static function manager_target_callback($budget, $source, $target) {
        return self::process_slice(max(5, absint($budget)), sanitize_key((string) $source));
    }

    private static function editorial_has_pending() {
        if (get_option('seo_ingeniero_editorial_process_paused')) return false;
        $ids=self::editorial_term_ids();
        if (!$ids) return false;
        $state=get_option(self::EDITORIAL_STATE_OPTION,array());
        if (!is_array($state) || !$state) return true;
        if ((string)($state['contract_version'] ?? '') !== 'full-category-dossier-v1') return true;
        if (empty($state['complete'])) return true;
        $completed=!empty($state['completed_at']) ? strtotime((string)$state['completed_at']) : 0;
        return !$completed || (time()-$completed) >= 6*HOUR_IN_SECONDS;
    }

    public static function has_pending() {
        return SEO_Ingeniero::is_pending() || self::editorial_has_pending();
    }

    public static function progress() {
        $research=SEO_Ingeniero::progress();
        $ids=self::editorial_term_ids();
        $estate=get_option(self::EDITORIAL_STATE_OPTION,array());
        if (!is_array($estate)) $estate=array();
        return array(
            'processed'=>absint($research['processed'] ?? 0),
            'total'=>absint($research['total'] ?? 0),
            'percentage'=>(float)($research['percentage'] ?? 0),
            'pending'=>absint($research['pending'] ?? 0),
            'editorial_processed'=>absint($estate['processed'] ?? 0),
            'editorial_total'=>count($ids),
            'editorial_cursor'=>absint($estate['cursor'] ?? 0),
            'editorial_errors'=>absint($estate['errors'] ?? 0),
            'editorial_updated_at'=>(string)($estate['updated_at'] ?? ''),
        );
    }

    public static function health() {
        $state=SEO_Ingeniero::state();
        $p=self::progress();
        return array(
            'healthy'=>empty($state['last_error']) && $p['editorial_errors'] < 10,
            'last_error'=>(string)($state['last_error'] ?? ''),
            'errors'=>absint($state['errors'] ?? 0)+$p['editorial_errors'],
        );
    }

    public static function pause() {
        update_option('seo_ingeniero_editorial_process_paused',1,false);
        SEO_Ingeniero::save_state(array('enabled'=>0,'status'=>'stopped','last_message'=>'Ingeniero pausado desde el Gestor de procesos.'));
        return true;
    }

    public static function resume() {
        delete_option('seo_ingeniero_editorial_process_paused');
        if (SEO_Ingeniero::is_pending()) SEO_Ingeniero::dispatch(0);
        if (function_exists('seo_process_supervisor_nudge')) seo_process_supervisor_nudge(0,'ingeniero');
        return true;
    }

    public static function editorial_manager_target_callback($budget,$source,$target) {
        return self::process_editorial_slice($budget,$source);
    }

    private static function process_editorial_slice($budget,$source='process_manager') {
        $budget=max(5,min(55,absint($budget)));
        if (!self::editorial_has_pending()) return false;
        $lock_key='seo_ingeniero_editorial_refresh_lock';
        if (get_transient($lock_key)) return false;
        set_transient($lock_key,1,$budget+30);
        $started=microtime(true);
        $worked=false;
        try {
            SEO_Ingeniero_DB::maybe_install();
            $state=get_option(self::EDITORIAL_STATE_OPTION,array());
            if (!is_array($state)
                || !$state
                || (string)($state['contract_version'] ?? '') !== 'full-category-dossier-v1') {
                $state=self::fresh_editorial_state();
            }

            if (!empty($state['complete'])) {
                $completed=!empty($state['completed_at']) ? strtotime((string)$state['completed_at']) : 0;
                if ($completed && (time()-$completed)<6*HOUR_IN_SECONDS) return false;
                $state=self::fresh_editorial_state();
            }

            $ids=self::editorial_term_ids();
            if (!$ids) return false;
            if (empty($state['coverage_rebuilt']) && class_exists('SEO_Editorial_Coverage')) {
                SEO_Editorial_Coverage::rebuild_index(7000);
                $state['coverage_rebuilt']=true;
            }

            $cursor=max(0,absint($state['cursor'] ?? 0));
            while ($cursor<count($ids) && (microtime(true)-$started)<max(2,$budget-2)) {
                $result=SEO_Ingeniero::refresh_editorial_category(absint($ids[$cursor]),false);
                $state['processed']=absint($state['processed'] ?? 0)+1;
                if (is_wp_error($result)) $state['errors']=absint($state['errors'] ?? 0)+1;
                $cursor++;
                $state['cursor']=$cursor;
                $state['updated_at']=current_time('mysql');
                $worked=true;
            }
            if ($cursor>=count($ids)) {
                $state['complete']=true;
                $state['completed_at']=current_time('mysql');
            }
            update_option(self::EDITORIAL_STATE_OPTION,$state,false);
            return $worked;
        } finally {
            delete_transient($lock_key);
        }
    }

    public static function fallback_tick() {
        if (!SEO_Ingeniero::is_pending()) return;
        self::process_slice(20, 'scheduler_fallback');
        if (SEO_Ingeniero::is_pending()) SEO_Ingeniero::schedule_fallback(20);
    }

    public static function process_slice($budget = 20, $source = 'process_manager') {
        if (!SEO_Ingeniero::is_pending()) return false;
        if (!self::acquire_lock()) return false;

        $budget = max(5, min(55, absint($budget)));
        $started = microtime(true);
        $state = SEO_Ingeniero::state();
        // Requisito editorial: una categoría por ciclo. Evita cargar conocimiento
        // activo de varias categorías en memoria dentro de la misma ejecución.
        $batch_size = self::CATEGORIES_PER_CYCLE;
        $processed_now = 0;
        $learned_now = 0;
        $review_now = 0;
        $errors_now = 0;

        while (SEO_Ingeniero::is_pending() && $processed_now < $batch_size) {
            if ((microtime(true) - $started) >= max(3, $budget - 2)) break;

            $state = SEO_Ingeniero::state();
            $cursor = absint($state['cursor'] ?? 0);
            $term_id = SEO_Ingeniero::next_term_id();
            if (!$term_id) break;

            $term = get_term($term_id, 'product_cat');
            $label = $term && !is_wp_error($term) ? (string) $term->name : ('#' . $term_id);
            SEO_Ingeniero::set_category_state($term_id, 'investigando', array('last_error'=>''));
            SEO_Ingeniero::save_state(array(
                'last_term_id'=>$term_id,
                'last_activity_at'=>time(),
                'last_message'=>'Investigando ' . $label . '…',
                'last_error'=>'',
            ));

            $result = SEO_Ingeniero::research_category($term_id);
            $processed_now++;
            if (is_wp_error($result)) {
                $errors_now++;
                $blocking_error = in_array($result->get_error_code(), array('ingeniero_budget','ingeniero_serpapi_key','ingeniero_serpapi_api','ingeniero_serpapi_rate_limit'), true);
                SEO_Ingeniero::set_category_state($term_id, 'error', array(
                    'last_error'=>$result->get_error_message(),
                ));

                if ($blocking_error) {
                    // No avanzar cursor: tras corregir cuota/configuración se reintenta la misma categoría.
                    SEO_Ingeniero::save_state(array(
                        'enabled'=>0,
                        'status'=>'stopped',
                        'errors'=>absint($state['errors'] ?? 0)+1,
                        'last_error'=>$result->get_error_message(),
                        'last_message'=>'Investigación pausada por límite/cuota de SerpApi. No se avanzará a la siguiente categoría hasta reanudar.',
                        'last_activity_at'=>time(),
                    ));
                    break;
                }

                SEO_Ingeniero::save_state(array(
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

            SEO_Ingeniero::set_category_state($term_id, $needs_review ? 'revisar' : 'aprendido', array(
                'last_error'=>'',
                'sources'=>absint($result['sources'] ?? 0),
                'knowledge'=>absint($result['knowledge'] ?? 0),
                'confidence'=>SEO_Ingeniero::category_confidence($term_id),
                'last_research_at'=>time(),
            ));

            // El dossier se recalcula categoria a categoria y solo persiste
            // cambios cuando varia su source_hash.
            SEO_Ingeniero::refresh_editorial_category($term_id, false);

            SEO_Ingeniero::save_state(array(
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
        $state = SEO_Ingeniero::state();

        $changes = array(
            'batch_size'=>self::CATEGORIES_PER_CYCLE,
            'last_duration'=>round($duration,3),
            'last_activity_at'=>time(),
        );

        if (!SEO_Ingeniero::is_pending()) {
            $fresh = SEO_Ingeniero::state();
            if (absint($fresh['cursor'] ?? 0) >= count((array) $fresh['queue'])) {
                $changes['enabled'] = 0;
                $changes['status'] = 'completed';
                $changes['completed_at'] = time();
                $changes['last_message'] = 'L1 completada para las categorías preparadas.';
                SEO_Ingeniero::clear_fallback();
            }
        }
        SEO_Ingeniero::save_state($changes);

        if (function_exists('seo_process_supervisor_managed_update')) {
            $progress = SEO_Ingeniero::progress();
            seo_process_supervisor_managed_update('ingeniero', array(
                'name'=>'Ingeniero',
                'pending'=>SEO_Ingeniero::is_pending()?1:0,
                'healthy'=>1,
                'last_checked'=>time(),
                'last_attempt_at'=>time(),
                'last_result'=>$processed_now?'processed':'waiting',
                'last_error'=>(string) (SEO_Ingeniero::state()['last_error'] ?? ''),
                'detail'=>number_format_i18n($progress['processed']) . '/' . number_format_i18n($progress['total']) . ' categorías · 1 categoría por ciclo.',
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
        $state = SEO_Ingeniero::state();
        $progress = SEO_Ingeniero::progress();
        $status = sanitize_key((string) ($state['status'] ?? 'stopped'));

        if ('running' === $status && SEO_Ingeniero::is_pending()) {
            $view_state = function_exists('seo_processes_state') ? seo_processes_state('running','En ejecución','running') : array('tone'=>'running','label'=>'En ejecución');
        } elseif ('completed' === $status) {
            $view_state = function_exists('seo_processes_state') ? seo_processes_state('completed','Completado','completed') : array('tone'=>'completed','label'=>'Completado');
        } elseif (!empty($state['last_error'])) {
            $view_state = function_exists('seo_processes_state') ? seo_processes_state('error','Error','error') : array('tone'=>'error','label'=>'Error');
        } else {
            $view_state = function_exists('seo_processes_state') ? seo_processes_state('stopped','Parado','stopped') : array('tone'=>'stopped','label'=>'Parado');
        }

        $duration = max(0, (float) ($state['last_duration'] ?? 0));
        $speed = ($duration > 0) ? number_format_i18n((1/$duration)*60, 1) . ' categorías/min aprox.' : 'Sin ciclo medido';
        $usage = SEO_Ingeniero_SerpApi_Provider::usage_month();

        $items[] = array(
            'id'=>'ingeniero',
            'name'=>'Ingeniero',
            'kind'=>'Contenidos · conocimiento técnico y editorial',
            'state'=>$view_state,
            'speed'=>$speed,
            'response'=>$duration > 0 ? number_format_i18n($duration,2) . ' s último lote' : 'Sin lote medido',
            'load'=>'L1 técnica · Google SerpApi→ScraperAPI · ' . number_format_i18n($usage['used']) . '/' . number_format_i18n($usage['limit']) . ' búsquedas',
            'activity'=>!empty($state['last_activity_at']) ? gmdate('Y-m-d H:i:s', absint($state['last_activity_at'])) . ' UTC' : 'Sin actividad',
            'activity_age'=>null,
            'progress'=>$progress['total'] ? $progress['percentage'] : null,
            'progress_text'=>number_format_i18n($progress['processed']) . ' / ' . number_format_i18n($progress['total']) . ' categorías',
            'detail'=>(string) ($state['last_message'] ?? ''),
            'url'=>add_query_arg(array('page'=>'seo-ingeniero','tab'=>'research'), admin_url('admin.php')),
            'can_start'=>!SEO_Ingeniero::is_pending() && $progress['pending'] > 0,
            'start_label'=>'Iniciar / continuar',
        );

        return $items;
    }
}

SEO_Ingeniero_Process::init();
