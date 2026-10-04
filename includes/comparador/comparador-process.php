<?php
defined('ABSPATH') || exit;

final class SEO_Comparador_Process implements SEO_Managed_Service_Process {
    const LOCK = 'seo_comparador_process_manager_lock';

    public static function init() {
        add_filter('seo_process_supervisor_has_pending_work', array(__CLASS__,'filter_pending_work'), 41, 1);
        add_filter('seo_process_supervisor_manager_targets', array(__CLASS__,'filter_manager_targets'), 41, 3);
        add_filter('seo_processes_monitor_items', array(__CLASS__,'filter_monitor_items'), 41, 1);
    }

    private static function state() {
        $state = get_option(SEO_Comparador_Engine::AUTO_STATE_OPTION,array());
        return is_array($state) ? $state : array();
    }

    private static function category_ids() {
        $ids = get_terms(array(
            'taxonomy'=>'product_cat',
            'hide_empty'=>false,
            'fields'=>'ids',
            'number'=>0,
            'orderby'=>'term_id',
            'order'=>'ASC',
        ));
        if (is_wp_error($ids)) return array();
        return array_values(array_unique(array_filter(array_map('absint',(array)$ids))));
    }

    private static function fresh_state() {
        return array(
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

    private static function enabled() {
        if (!function_exists('seo_process_supervisor_settings')) return false;
        $settings=(array)seo_process_supervisor_settings();
        return !empty($settings['enabled']) && !empty($settings['comparador']);
    }

    public static function has_pending() {
        if (get_option('seo_comparador_process_paused')) return false;
        $state=self::state();
        if (!$state) return true;
        if (empty($state['complete'])) return true;
        $completed=!empty($state['completed_at']) ? strtotime((string)$state['completed_at']) : 0;
        return !$completed || (time()-$completed) >= 6*HOUR_IN_SECONDS;
    }

    public static function process_slice($budget, $source='process_manager') {
        $budget=max(5,min(55,absint($budget)));
        if (!self::has_pending()) return false;
        if (get_transient(self::LOCK) || get_transient('seo_comparador_auto_refresh_lock')) return false;

        set_transient(self::LOCK,1,$budget+30);
        set_transient('seo_comparador_auto_refresh_lock',1,$budget+30);
        $started=microtime(true);
        $worked=false;
        try {
            SEO_Comparador_DB::maybe_install();
            SEO_Comparador_Engine::ensure_all_category_profiles();
            $state=self::state();
            if (!$state) $state=self::fresh_state();

            if (!empty($state['complete'])) {
                $completed=!empty($state['completed_at']) ? strtotime((string)$state['completed_at']) : 0;
                if ($completed && (time()-$completed) < 6*HOUR_IN_SECONDS) return false;
                $state=self::fresh_state();
            }

            $ids=self::category_ids();
            if (!$ids) {
                $state['complete']=true;
                $state['completed_at']=current_time('mysql');
                $state['updated_at']=current_time('mysql');
                update_option(SEO_Comparador_Engine::AUTO_STATE_OPTION,$state,false);
                return true;
            }

            if (empty($state['coverage_rebuilt']) && class_exists('SEO_Editorial_Coverage')) {
                SEO_Editorial_Coverage::rebuild_index(7000);
                $state['coverage_rebuilt']=true;
            }

            $cursor=max(0,absint($state['cursor'] ?? 0));
            while ($cursor < count($ids) && (microtime(true)-$started) < max(2,$budget-2)) {
                $term_id=absint($ids[$cursor]);
                $profile=SEO_Comparador_Engine::build_profile($term_id);
                $state['processed']=absint($state['processed'] ?? 0)+1;
                if (is_wp_error($profile) || empty($profile['id'])) {
                    $state['errors']=absint($state['errors'] ?? 0)+1;
                } else {
                    $decision=SEO_Comparador_Engine::evaluate_editorial_decision(absint($profile['id']));
                    if (is_wp_error($decision)) $state['errors']=absint($state['errors'] ?? 0)+1;
                }
                $cursor++;
                $state['cursor']=$cursor;
                $state['updated_at']=current_time('mysql');
                $worked=true;
            }

            if ($cursor >= count($ids)) {
                $state['complete']=true;
                $state['completed_at']=current_time('mysql');
            }
            update_option(SEO_Comparador_Engine::AUTO_STATE_OPTION,$state,false);
            self::managed_update($worked?'processed':'waiting','');
            return $worked;
        } catch (Throwable $e) {
            self::managed_update('error',sanitize_text_field($e->getMessage()));
            return false;
        } finally {
            delete_transient('seo_comparador_auto_refresh_lock');
            delete_transient(self::LOCK);
        }
    }

    public static function progress() {
        $state=self::state();
        $ids=self::category_ids();
        $total=count($ids);
        $cursor=min($total,absint($state['cursor'] ?? 0));
        return array(
            'processed'=>absint($state['processed'] ?? 0),
            'cursor'=>$cursor,
            'total'=>$total,
            'pending'=>max(0,$total-$cursor),
            'percentage'=>$total?round(($cursor/$total)*100,1):0,
            'errors'=>absint($state['errors'] ?? 0),
            'updated_at'=>(string)($state['updated_at'] ?? ''),
            'complete'=>!empty($state['complete']) && !self::has_pending(),
        );
    }

    public static function health() {
        $p=self::progress();
        $updated=$p['updated_at'] ? strtotime($p['updated_at']) : 0;
        return array(
            'healthy'=>$p['errors'] < 10,
            'stalled'=>self::has_pending() && $updated && (time()-$updated) > 15*MINUTE_IN_SECONDS,
            'errors'=>$p['errors'],
            'last_activity'=>$updated,
        );
    }

    public static function pause() {
        update_option('seo_comparador_process_paused',1,false);
        return true;
    }

    public static function resume() {
        delete_option('seo_comparador_process_paused');
        if (function_exists('seo_process_supervisor_nudge')) seo_process_supervisor_nudge(0,'comparador');
        return true;
    }

    public static function filter_pending_work($pending) {
        return $pending || (self::enabled() && self::has_pending());
    }

    public static function filter_manager_targets($targets,$settings,$source) {
        if (empty($settings['comparador']) || !self::has_pending()) return $targets;
        $targets[]=array('type'=>'comparador','data'=>array(),'callback'=>array(__CLASS__,'manager_target_callback'));
        return $targets;
    }

    public static function manager_target_callback($budget,$source,$target) {
        return self::process_slice($budget,$source);
    }

    private static function managed_update($result,$error='') {
        if (!function_exists('seo_process_supervisor_managed_update')) return;
        $p=self::progress();
        seo_process_supervisor_managed_update('comparador',array(
            'name'=>'Comparador',
            'pending'=>self::has_pending()?1:0,
            'healthy'=>$error===''?1:0,
            'last_checked'=>time(),
            'last_attempt_at'=>time(),
            'last_result'=>$result,
            'last_error'=>$error,
            'detail'=>number_format_i18n($p['cursor']).'/'.number_format_i18n($p['total']).' categorías · '.number_format_i18n($p['errors']).' errores.',
        ));
    }

    public static function filter_monitor_items($items) {
        $p=self::progress();
        $h=self::health();
        if (get_option('seo_comparador_process_paused')) {
            $state=function_exists('seo_processes_state')?seo_processes_state('stopped','Pausado','stopped'):array('tone'=>'stopped','label'=>'Pausado');
        } elseif (!empty($h['stalled'])) {
            $state=function_exists('seo_processes_state')?seo_processes_state('warning','Sin avance','warning'):array('tone'=>'warning','label'=>'Sin avance');
        } elseif (!empty($p['complete'])) {
            $state=function_exists('seo_processes_state')?seo_processes_state('completed','Completado','completed'):array('tone'=>'completed','label'=>'Completado');
        } else {
            $state=function_exists('seo_processes_state')?seo_processes_state('running','En ejecución','running'):array('tone'=>'running','label'=>'En ejecución');
        }

        $items[]=array(
            'id'=>'comparador',
            'name'=>'Comparador',
            'kind'=>'Contenidos · perfiles comparativos por categoría',
            'state'=>$state,
            'speed'=>'por presupuesto del Gestor',
            'response'=>number_format_i18n($p['cursor']).' categorías recorridas',
            'load'=>'Catálogo + snapshots Ojeador · sin API externa directa',
            'activity'=>$p['updated_at'] ?: 'Sin actividad',
            'activity_age'=>null,
            'progress'=>$p['total']?$p['percentage']:null,
            'progress_text'=>number_format_i18n($p['cursor']).' / '.number_format_i18n($p['total']).' categorías',
            'detail'=>number_format_i18n($p['errors']).' errores · progreso operativo, no calidad editorial.',
            'url'=>add_query_arg(array('page'=>'seo-comparador'),admin_url('admin.php')),
            'can_start'=>false,
        );
        return $items;
    }
}

SEO_Comparador_Process::init();
