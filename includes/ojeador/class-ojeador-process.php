<?php
/**
 * Ojeador integration with SEO Taxonomy > Procesos.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @since 0.5.0
 */

defined('ABSPATH') || exit;

final class SEO_Ojeador_Process {
    public static function init() {
        add_filter('seo_process_supervisor_has_pending_work', array(__CLASS__, 'filter_pending_work'));
        add_filter('seo_process_supervisor_manager_targets', array(__CLASS__, 'filter_manager_targets'), 20, 3);
        add_filter('seo_processes_monitor_items', array(__CLASS__, 'filter_monitor_items'));
    }

    public static function filter_pending_work($pending) {
        return $pending || SEO_Ojeador_Worker::is_pending();
    }

    public static function filter_manager_targets($targets, $settings, $source) {
        if (!SEO_Ojeador_Worker::is_pending()) {
            return $targets;
        }
        $targets[] = array(
            'type' => 'ojeador_market',
            'data' => array(),
            'callback' => array(__CLASS__, 'manager_target_callback'),
        );
        return $targets;
    }

    public static function manager_target_callback($budget, $source, $target) {
        return SEO_Ojeador_Worker::process_batch('process_manager');
    }

    public static function filter_monitor_items($items) {
        $run = SEO_Ojeador_DB::active_run();
        if (!$run) {
            $run = SEO_Ojeador_DB::latest_run();
        }
        $status = sanitize_key((string) ($run['status'] ?? 'stopped'));
        if (in_array($status, array('running','pending'), true)) {
            $state = function_exists('seo_processes_state') ? seo_processes_state('running', 'En ejecución', 'running') : array('tone'=>'running','label'=>'En ejecución');
        } elseif ($status === 'completed') {
            $state = function_exists('seo_processes_state') ? seo_processes_state('completed', 'Completado', 'completed') : array('tone'=>'completed','label'=>'Completado');
        } elseif ($status === 'failed') {
            $state = function_exists('seo_processes_state') ? seo_processes_state('error', 'Fallido', 'failed') : array('tone'=>'error','label'=>'Fallido');
        } else {
            $state = function_exists('seo_processes_state') ? seo_processes_state('stopped', 'Parado', 'stopped') : array('tone'=>'stopped','label'=>'Parado');
        }

        $processed = absint($run['processed_products'] ?? 0);
        $total = absint($run['total_candidates'] ?? 0);
        $compared = absint($run['compared_products'] ?? 0);
        $offers = absint($run['offers_seen'] ?? 0);
        $errors = absint($run['errors_count'] ?? 0);
        $progress = $total > 0 ? min(100, round(($processed / $total) * 100, 1)) : null;

        $items[] = array(
            'id' => 'ojeador_market',
            'name' => 'Ojeador',
            'kind' => 'Google Shopping · comparativa de ofertas',
            'state' => $state,
            'speed' => 'automático por productos',
            'response' => number_format_i18n($compared) . ' productos comparados',
            'load' => 'API Google Shopping',
            'activity' => !empty($run['heartbeat_at']) ? (string) $run['heartbeat_at'] : 'Sin actividad',
            'activity_age' => null,
            'progress' => $progress,
            'progress_text' => number_format_i18n($processed) . ($total ? ' / ' . number_format_i18n($total) : '') . ' productos',
            'detail' => number_format_i18n($offers) . ' ofertas recogidas · ' . number_format_i18n($errors) . ' errores.',
            'url' => add_query_arg(array('page'=>'seo-ojeador'), admin_url('admin.php')),
            'can_start' => false,
        );
        return $items;
    }
}
