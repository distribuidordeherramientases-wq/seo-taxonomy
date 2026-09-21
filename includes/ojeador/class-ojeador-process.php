<?php
/**
 * Ojeador integration with SEO System process manager.
 *
 * Keeps server pressure controls in Procesos while all Ojeador data/business
 * management stays in Herramientas > Ojeador.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @since 0.2.1
 */

defined('ABSPATH') || exit;

final class SEO_Ojeador_Process {
    const OPTION_ADAPTIVE = 'seo_ojeador_process_adaptive_state';

    public static function init() {
        add_filter('seo_process_supervisor_has_pending_work', array(__CLASS__, 'filter_pending_work'));
        add_filter('seo_process_supervisor_manager_targets', array(__CLASS__, 'filter_manager_targets'), 20, 3);
        add_filter('seo_processes_monitor_items', array(__CLASS__, 'filter_monitor_items'));

        // These two hooks are added to seo-processes.php by the supplied tiny patch.
        add_filter('seo_processes_sanitize_controls_result', array(__CLASS__, 'sanitize_controls_result'), 20, 3);
        add_action('seo_processes_render_control_cards', array(__CLASS__, 'render_control_card'), 20, 1);
    }

    public static function defaults() {
        return array(
            'min_batch' => 1,
            'initial_batch' => 3,
            'max_batch' => 8,
            'target_seconds' => 20,
            'hard_seconds' => 45,
            'growth_factor' => 1.50,
            'slowdown_factor' => 0.60,
            'memory_soft_percent' => 80,
            'memory_hard_percent' => 90,
            'normal_delay_seconds' => 2,
            'slow_delay_seconds' => 8,
            'critical_delay_seconds' => 20,
        );
    }

    public static function sanitize_control($raw) {
        $raw = wp_parse_args(is_array($raw) ? $raw : array(), self::defaults());
        $out = array();
        $out['min_batch'] = max(1, min(25, absint($raw['min_batch'])));
        $out['initial_batch'] = max($out['min_batch'], min(50, absint($raw['initial_batch'])));
        $out['max_batch'] = max($out['initial_batch'], min(100, absint($raw['max_batch'])));
        $out['target_seconds'] = max(2.0, min(50.0, (float) $raw['target_seconds']));
        $out['hard_seconds'] = max($out['target_seconds'] + 2.0, min(55.0, (float) $raw['hard_seconds']));
        $out['growth_factor'] = max(1.05, min(2.00, (float) $raw['growth_factor']));
        $out['slowdown_factor'] = max(0.20, min(0.95, (float) $raw['slowdown_factor']));
        $out['memory_soft_percent'] = max(30.0, min(92.0, (float) $raw['memory_soft_percent']));
        $out['memory_hard_percent'] = max($out['memory_soft_percent'] + 3.0, min(98.0, (float) $raw['memory_hard_percent']));
        $out['normal_delay_seconds'] = max(0, min(60, absint($raw['normal_delay_seconds'])));
        $out['slow_delay_seconds'] = max($out['normal_delay_seconds'], min(180, absint($raw['slow_delay_seconds'])));
        $out['critical_delay_seconds'] = max($out['slow_delay_seconds'], min(600, absint($raw['critical_delay_seconds'])));
        return $out;
    }

    public static function sanitize_controls_result($out, $raw, $defaults) {
        if (!is_array($out)) {
            $out = array();
        }
        $incoming = isset($raw['ojeador']) && is_array($raw['ojeador']) ? $raw['ojeador'] : array();
        $out['ojeador'] = self::sanitize_control($incoming);
        return $out;
    }

    public static function control() {
        if (function_exists('seo_processes_control_for')) {
            $stored = seo_processes_control_for('ojeador');
            if (is_array($stored) && $stored) {
                return self::sanitize_control($stored);
            }
        }
        return self::defaults();
    }

    public static function adaptive_state() {
        $state = get_option(self::OPTION_ADAPTIVE, array());
        return is_array($state) ? $state : array();
    }

    public static function current_batch_size($control = null) {
        $control = is_array($control) ? self::sanitize_control($control) : self::control();
        $state = self::adaptive_state();
        $batch = absint($state['next_batch'] ?? 0);
        if ($batch < 1) {
            $batch = absint($control['initial_batch']);
        }
        return max(absint($control['min_batch']), min(absint($control['max_batch']), $batch));
    }

    public static function next_eligible_at() {
        $state = self::adaptive_state();
        return absint($state['next_eligible_at'] ?? 0);
    }

    public static function filter_pending_work($pending) {
        return $pending || (class_exists('SEO_Ojeador_Worker') && SEO_Ojeador_Worker::is_pending());
    }

    public static function filter_manager_targets($targets, $settings, $source) {
        if (!class_exists('SEO_Ojeador_Worker') || !SEO_Ojeador_Worker::is_pending()) {
            return $targets;
        }

        $due = self::next_eligible_at();
        if ($due > time()) {
            if (function_exists('seo_process_supervisor_managed_update')) {
                seo_process_supervisor_managed_update('ojeador', array(
                    'name' => 'Ojeador',
                    'pending' => 1,
                    'healthy' => 1,
                    'last_checked' => time(),
                    'last_result' => 'waiting',
                    'last_error' => '',
                    'detail' => 'Ojeador en pausa adaptativa hasta ' . wp_date('H:i:s', $due) . '.',
                ));
            }
            return $targets;
        }

        $targets[] = array(
            'type' => 'ojeador',
            'data' => array(),
            'callback' => array(__CLASS__, 'manager_target_callback'),
        );
        return $targets;
    }

    public static function manager_target_callback($budget, $source, $target) {
        $control = self::control();
        $budget = max(5, min(absint($budget), (int) ceil($control['hard_seconds'])));
        $before = SEO_Ojeador_DB::active_run();
        $before_processed = absint($before['processed_products'] ?? 0);
        $started = microtime(true);

        if (function_exists('seo_process_supervisor_managed_update')) {
            seo_process_supervisor_managed_update('ojeador', array(
                'name' => 'Ojeador',
                'pending' => 1,
                'healthy' => 1,
                'last_checked' => time(),
                'last_attempt_at' => time(),
                'last_result' => 'running',
                'last_error' => '',
                'detail' => 'El gestor ejecuta una ventana de Ojeador con lote adaptativo.',
            ));
        }

        if (function_exists('seo_process_supervisor_log')) {
            seo_process_supervisor_log(
                'info',
                'process_window_started',
                'Ojeador entra en una ventana del gestor.',
                'Ojeador',
                array('seconds' => $budget, 'batch' => self::current_batch_size($control))
            );
        }

        $ok = SEO_Ojeador_Worker::process_manager_slice($budget, $source, $control);
        $elapsed = max(0.001, microtime(true) - $started);
        $after = SEO_Ojeador_DB::active_run();
        if (!$after) {
            $after = SEO_Ojeador_DB::latest_run();
        }
        $after_processed = absint($after['processed_products'] ?? $before_processed);
        $processed = max(0, $after_processed - $before_processed);
        $memory_ratio = self::memory_ratio();
        self::update_adaptive_state($ok, $processed, $elapsed, $memory_ratio, $control);

        $still = SEO_Ojeador_Worker::is_pending();
        $latest = SEO_Ojeador_DB::latest_run();
        if (function_exists('seo_process_supervisor_managed_update')) {
            seo_process_supervisor_managed_update('ojeador', array(
                'name' => 'Ojeador',
                'pending' => $still ? 1 : 0,
                'healthy' => $ok ? 1 : 0,
                'last_checked' => time(),
                'last_result' => $still ? ($ok ? 'processed' : 'waiting') : sanitize_key((string) ($latest['status'] ?? 'idle')),
                'last_error' => sanitize_text_field((string) ($latest['last_error'] ?? '')),
                'detail' => $still
                    ? 'Ventana completada; el regulador decidirá lote y pausa de la siguiente.'
                    : 'Ojeador terminado o sin trabajo pendiente.',
            ));
        }
        return $ok;
    }

    private static function update_adaptive_state($ok, $processed, $elapsed, $memory_ratio, $control) {
        $current = self::current_batch_size($control);
        $mode = 'normal';
        $delay = absint($control['normal_delay_seconds']);
        $next = $current;

        if (!$ok || $memory_ratio >= ((float) $control['memory_hard_percent'] / 100.0) || $elapsed >= (float) $control['hard_seconds']) {
            $mode = 'critical';
            $next = max(absint($control['min_batch']), (int) floor($current * 0.5));
            $delay = absint($control['critical_delay_seconds']);
        } elseif ($memory_ratio >= ((float) $control['memory_soft_percent'] / 100.0) || $elapsed >= (float) $control['target_seconds']) {
            $mode = 'slow';
            $next = max(absint($control['min_batch']), (int) floor($current * (float) $control['slowdown_factor']));
            $delay = absint($control['slow_delay_seconds']);
        } elseif ($processed > 0) {
            $mode = 'fast';
            $next = min(absint($control['max_batch']), max($current + 1, (int) ceil($current * (float) $control['growth_factor'])));
        }

        update_option(self::OPTION_ADAPTIVE, array(
            'next_batch' => $next,
            'next_eligible_at' => time() + $delay,
            'last_batch' => $current,
            'last_processed' => absint($processed),
            'last_elapsed' => round((float) $elapsed, 4),
            'last_memory_ratio' => round((float) $memory_ratio, 4),
            'mode' => $mode,
            'updated_at' => time(),
        ), false);
    }

    private static function memory_ratio() {
        $limit = ini_get('memory_limit');
        if (!$limit || '-1' === trim((string) $limit)) {
            return 0.0;
        }
        $bytes = self::bytes_from_ini($limit);
        if ($bytes <= 0) {
            return 0.0;
        }
        return min(1.0, memory_get_peak_usage(true) / $bytes);
    }

    private static function bytes_from_ini($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        if ('g' === $unit) {
            return (int) ($number * 1024 * 1024 * 1024);
        }
        if ('m' === $unit) {
            return (int) ($number * 1024 * 1024);
        }
        if ('k' === $unit) {
            return (int) ($number * 1024);
        }
        return (int) $number;
    }

    public static function render_control_card($settings) {
        $control = isset($settings['ojeador']) && is_array($settings['ojeador'])
            ? self::sanitize_control($settings['ojeador'])
            : self::control();
        $state = self::adaptive_state();
        ?>
        <details class="seo-process-control-card" open>
            <summary><strong>Ojeador</strong><span>Ofertas externas · peticiones HTTP adaptativas</span></summary>
            <div class="seo-process-control-grid">
                <label>Lote mínimo<?php seo_processes_number_input('ojeador','min_batch',$control['min_batch'],1,25); ?><small>Productos por ventana.</small></label>
                <label>Lote inicial<?php seo_processes_number_input('ojeador','initial_batch',$control['initial_batch'],1,50); ?><small>Productos al arrancar.</small></label>
                <label>Lote máximo<?php seo_processes_number_input('ojeador','max_batch',$control['max_batch'],1,100); ?><small>Techo absoluto por ventana.</small></label>
                <label>Tiempo objetivo<?php seo_processes_number_input('ojeador','target_seconds',$control['target_seconds'],2,50,'0.5'); ?><small>Segundos por ventana.</small></label>
                <label>Tiempo crítico<?php seo_processes_number_input('ojeador','hard_seconds',$control['hard_seconds'],4,55,'0.5'); ?><small>Recorta fuerte al alcanzarlo.</small></label>
                <label>Multiplicador subida<?php seo_processes_number_input('ojeador','growth_factor',$control['growth_factor'],1.05,2.00,'0.01'); ?><small>Aumenta lote si va holgado.</small></label>
                <label>Multiplicador bajada<?php seo_processes_number_input('ojeador','slowdown_factor',$control['slowdown_factor'],0.20,0.95,'0.01'); ?><small>Reduce lote con presión.</small></label>
                <label>Memoria preventiva<?php seo_processes_number_input('ojeador','memory_soft_percent',$control['memory_soft_percent'],30,92,'0.5'); ?><small>% de memory_limit.</small></label>
                <label>Memoria crítica<?php seo_processes_number_input('ojeador','memory_hard_percent',$control['memory_hard_percent'],33,98,'0.5'); ?><small>% de memory_limit.</small></label>
                <label>Pausa normal<?php seo_processes_number_input('ojeador','normal_delay_seconds',$control['normal_delay_seconds'],0,60); ?><small>Segundos.</small></label>
                <label>Pausa lenta<?php seo_processes_number_input('ojeador','slow_delay_seconds',$control['slow_delay_seconds'],0,180); ?><small>Segundos.</small></label>
                <label>Pausa crítica<?php seo_processes_number_input('ojeador','critical_delay_seconds',$control['critical_delay_seconds'],0,600); ?><small>Segundos.</small></label>
            </div>
            <p class="description">Ojeador se inicia desde Herramientas &gt; Ojeador. Aquí solo regulas la presión que puede ejercer sobre el servidor. Estado adaptativo actual: <strong><?php echo esc_html((string) ($state['mode'] ?? 'inicial')); ?></strong> · siguiente lote <strong><?php echo esc_html((string) self::current_batch_size($control)); ?></strong>.</p>
        </details>
        <?php
    }

    public static function filter_monitor_items($items) {
        $run = SEO_Ojeador_DB::active_run();
        if (!$run) {
            $run = SEO_Ojeador_DB::latest_run();
        }
        $status = sanitize_key((string) ($run['status'] ?? 'stopped'));
        $processed = absint($run['processed_products'] ?? 0);
        $total = absint($run['total_candidates'] ?? 0);
        $progress = $total > 0 ? min(100, round(($processed / $total) * 100, 1)) : null;
        $heartbeat = !empty($run['heartbeat_at']) ? strtotime((string) $run['heartbeat_at'] . ' UTC') : 0;
        $age = $heartbeat ? max(0, time() - $heartbeat) : null;

        if ($status === 'running' || $status === 'pending') {
            $state = function_exists('seo_processes_state') ? seo_processes_state('running', 'En ejecución', 'running') : array('tone' => 'running', 'label' => 'En ejecución');
        } elseif ($status === 'completed') {
            $state = function_exists('seo_processes_state') ? seo_processes_state('completed', 'Parado · completado', 'completed') : array('tone' => 'completed', 'label' => 'Completado');
        } elseif ($status === 'failed') {
            $state = function_exists('seo_processes_state') ? seo_processes_state('error', 'Fallido', 'failed') : array('tone' => 'error', 'label' => 'Fallido');
        } else {
            $state = function_exists('seo_processes_state') ? seo_processes_state('stopped', 'Parado', 'stopped') : array('tone' => 'stopped', 'label' => 'Parado');
        }

        $elapsed = 0;
        if (!empty($run['started_at'])) {
            $start_ts = strtotime((string) $run['started_at'] . ' UTC');
            if ($start_ts) {
                $end_ts = !empty($run['completed_at']) ? strtotime((string) $run['completed_at'] . ' UTC') : time();
                $elapsed = max(1, $end_ts - $start_ts);
            }
        }
        $rate = ($elapsed > 0 && $processed > 0) ? round(($processed / $elapsed) * 60, 1) : 0;
        $activity = null === $age ? 'Sin actividad registrada' : ($age < 60 ? 'Hace ' . $age . ' s' : 'Hace ' . floor($age / 60) . ' min');
        $adaptive = self::adaptive_state();
        $batch = self::current_batch_size();

        $items[] = array(
            'id' => 'ojeador',
            'name' => 'Ojeador',
            'kind' => 'Observación de precios externos',
            'state' => $state,
            'speed' => $rate > 0 ? number_format_i18n($rate, 1) . ' productos/min' : '0 productos/min',
            'response' => $run ? number_format_i18n(absint($run['offers_seen'] ?? 0)) . ' ofertas observadas' : 'Sin barridos',
            'load' => 'lote ' . number_format_i18n($batch) . ' · ' . sanitize_text_field((string) ($adaptive['mode'] ?? 'inicial')),
            'activity' => $activity,
            'activity_age' => $age,
            'progress' => $progress,
            'progress_text' => null !== $progress ? number_format_i18n($progress) . '%' : '—',
            'detail' => $run
                ? number_format_i18n($processed) . '/' . number_format_i18n($total) . ' productos · ' . number_format_i18n(absint($run['errors_count'] ?? 0)) . ' errores.'
                : 'Todavía no hay ejecuciones de Ojeador.',
            'url' => add_query_arg(array('page' => 'seo-ojeador', 'tab' => 'barridos'), admin_url('admin.php')),
            'can_start' => false,
        );
        return $items;
    }
}
