<?php
/**
 * SEO Taxonomy - Monitor central de procesos en segundo plano.
 *
 * Reune en una sola pantalla el estado observable de los workers que pueden
 * ejercer carga sostenida sobre WordPress o sobre el servidor publico:
 * - Import / Export por lotes.
 * - Academia automatica del Dependiente.
 * - Auditorias de salud de paginas, posts e imagenes.
 *
 * La pantalla centraliza monitorizacion, limites de velocidad y el arranque
 * explicito de los controladores propios. Import/Export y Academia pueden
 * reanudarse desde aqui sin entregar el camino critico a WP-Cron/Action Scheduler.
 *
 * @package SEOSystem
 * @subpackage Admin
 * @since 2.3.2
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_processes_control_defaults')) {
    /**
     * Valores conservadores equivalentes a los que el plugin usaba antes de
     * exponer el panel. Mantenerlos aqui permite restaurar el comportamiento
     * original con un solo clic.
     */
    function seo_processes_control_defaults() {
        return array(
            'import-export' => array(
                'min_rows' => 2,
                'initial_rows' => 10,
                'max_rows' => 2000,
                'target_seconds' => 35.0,
                'hard_seconds' => 100.0,
                'growth_factor' => 1.60,
                'memory_soft_percent' => 72.0,
                'memory_hard_percent' => 84.0,
                'heavy_delay_seconds' => 5,
                'critical_delay_seconds' => 15,
            ),
            'academy' => array(
                'min_batch' => 1,
                'initial_batch' => 1,
                'max_batch' => 4,
                'fast_seconds' => 2.5,
                'slow_seconds' => 7.0,
                'very_slow_seconds' => 14.0,
                'growth_factor' => 1.34,
                'slowdown_factor' => 0.50,
                'fast_streak_required' => 2,
                'normal_delay_seconds' => 1,
                'slow_delay_seconds' => 2,
                'critical_delay_seconds' => 5,
            ),
            'health-page' => array(
                'batch' => 250,
                'load_batch' => 60,
                'initial_workers' => 2,
                'max_workers' => 8,
                'fast_p95_ms' => 800,
                'slow_p95_ms' => 1500,
                'very_slow_p95_ms' => 2500,
                'min_interval_ms' => 120,
                'initial_interval_ms' => 300,
                'max_interval_ms' => 3000,
            ),
            'health-post' => array(
                'batch' => 250,
                'load_batch' => 60,
                'initial_workers' => 2,
                'max_workers' => 8,
                'fast_p95_ms' => 800,
                'slow_p95_ms' => 1500,
                'very_slow_p95_ms' => 2500,
                'min_interval_ms' => 120,
                'initial_interval_ms' => 300,
                'max_interval_ms' => 3000,
            ),
            'health-image' => array(
                'batch' => 500,
                'load_batch' => 300,
                'initial_workers' => 2,
                'max_workers' => 8,
                'fast_p95_ms' => 800,
                'slow_p95_ms' => 1500,
                'very_slow_p95_ms' => 2500,
                'min_interval_ms' => 120,
                'initial_interval_ms' => 300,
                'max_interval_ms' => 3000,
            ),
        );
    }
}

if (!function_exists('seo_processes_clamp_float')) {
    function seo_processes_clamp_float($value, $min, $max) {
        return max((float) $min, min((float) $max, (float) $value));
    }
}

if (!function_exists('seo_processes_sanitize_controls')) {
    function seo_processes_sanitize_controls($raw) {
        $raw = is_array($raw) ? $raw : array();
        $defaults = seo_processes_control_defaults();
        $out = array();

        $import = wp_parse_args(isset($raw['import-export']) && is_array($raw['import-export']) ? $raw['import-export'] : array(), $defaults['import-export']);
        $import['min_rows'] = max(1, min(500, absint($import['min_rows'])));
        $import['initial_rows'] = max($import['min_rows'], min(2000, absint($import['initial_rows'])));
        $import['max_rows'] = max($import['initial_rows'], min(5000, absint($import['max_rows'])));
        $import['target_seconds'] = seo_processes_clamp_float($import['target_seconds'], 5.0, 300.0);
        $import['hard_seconds'] = max($import['target_seconds'] + 5.0, seo_processes_clamp_float($import['hard_seconds'], 10.0, 600.0));
        $import['growth_factor'] = seo_processes_clamp_float($import['growth_factor'], 1.10, 2.00);
        $import['memory_soft_percent'] = seo_processes_clamp_float($import['memory_soft_percent'], 20.0, 93.0);
        $import['memory_hard_percent'] = max($import['memory_soft_percent'] + 5.0, seo_processes_clamp_float($import['memory_hard_percent'], 25.0, 98.0));
        $import['heavy_delay_seconds'] = min(120, absint($import['heavy_delay_seconds']));
        $import['critical_delay_seconds'] = max($import['heavy_delay_seconds'], min(300, absint($import['critical_delay_seconds'])));
        $out['import-export'] = $import;

        $academy = wp_parse_args(isset($raw['academy']) && is_array($raw['academy']) ? $raw['academy'] : array(), $defaults['academy']);
        $academy['min_batch'] = max(1, min(20, absint($academy['min_batch'])));
        $academy['initial_batch'] = max($academy['min_batch'], min(30, absint($academy['initial_batch'])));
        $academy['max_batch'] = max($academy['initial_batch'], min(50, absint($academy['max_batch'])));
        $academy['fast_seconds'] = seo_processes_clamp_float($academy['fast_seconds'], 0.5, 30.0);
        $academy['slow_seconds'] = max($academy['fast_seconds'] + 0.5, seo_processes_clamp_float($academy['slow_seconds'], 1.0, 120.0));
        $academy['very_slow_seconds'] = max($academy['slow_seconds'] + 1.0, seo_processes_clamp_float($academy['very_slow_seconds'], 2.0, 300.0));
        $academy['growth_factor'] = seo_processes_clamp_float($academy['growth_factor'], 1.05, 2.00);
        $academy['slowdown_factor'] = seo_processes_clamp_float($academy['slowdown_factor'], 0.20, 0.95);
        $academy['fast_streak_required'] = max(1, min(10, absint($academy['fast_streak_required'])));
        $academy['normal_delay_seconds'] = max(1, min(30, absint($academy['normal_delay_seconds'])));
        $academy['slow_delay_seconds'] = max($academy['normal_delay_seconds'], min(120, absint($academy['slow_delay_seconds'])));
        $academy['critical_delay_seconds'] = max($academy['slow_delay_seconds'], min(300, absint($academy['critical_delay_seconds'])));
        $out['academy'] = $academy;

        foreach (array('page', 'post', 'image') as $scope) {
            $key = 'health-' . $scope;
            $health = wp_parse_args(isset($raw[$key]) && is_array($raw[$key]) ? $raw[$key] : array(), $defaults[$key]);
            $health['batch'] = max(10, min(2000, absint($health['batch'])));
            $health['load_batch'] = max(5, min($health['batch'], absint($health['load_batch'])));
            $health['max_workers'] = max(1, min(16, absint($health['max_workers'])));
            $health['initial_workers'] = max(1, min($health['max_workers'], absint($health['initial_workers'])));
            $health['fast_p95_ms'] = max(100, min(10000, absint($health['fast_p95_ms'])));
            $health['slow_p95_ms'] = max($health['fast_p95_ms'] + 100, min(20000, absint($health['slow_p95_ms'])));
            $health['very_slow_p95_ms'] = max($health['slow_p95_ms'] + 100, min(60000, absint($health['very_slow_p95_ms'])));
            $health['min_interval_ms'] = max(10, min(5000, absint($health['min_interval_ms'])));
            $health['initial_interval_ms'] = max($health['min_interval_ms'], min(10000, absint($health['initial_interval_ms'])));
            $health['max_interval_ms'] = max($health['initial_interval_ms'], min(30000, absint($health['max_interval_ms'])));
            $out[$key] = $health;
        }

        return $out;
    }
}

if (!function_exists('seo_processes_control_settings')) {
    function seo_processes_control_settings() {
        $stored = get_option('seo_processes_control_settings', array());
        return seo_processes_sanitize_controls(is_array($stored) ? $stored : array());
    }
}

if (!function_exists('seo_processes_control_for')) {
    function seo_processes_control_for($process_id) {
        $settings = seo_processes_control_settings();
        $process_id = sanitize_key((string) $process_id);
        return isset($settings[$process_id]) && is_array($settings[$process_id]) ? $settings[$process_id] : array();
    }
}

if (!function_exists('seo_processes_filter_import_adaptive_config')) {
    function seo_processes_filter_import_adaptive_config($config) {
        $control = seo_processes_control_for('import-export');
        if (!$control) {
            return $config;
        }
        return array_merge((array) $config, array(
            'min_rows' => $control['min_rows'],
            'initial_rows' => $control['initial_rows'],
            'max_rows' => $control['max_rows'],
            'target_seconds' => $control['target_seconds'],
            'hard_seconds' => $control['hard_seconds'],
            'memory_soft_ratio' => $control['memory_soft_percent'] / 100,
            'memory_hard_ratio' => $control['memory_hard_percent'] / 100,
            'growth_factor' => $control['growth_factor'],
            'heavy_delay_seconds' => $control['heavy_delay_seconds'],
            'critical_delay_seconds' => $control['critical_delay_seconds'],
        ));
    }
}
add_filter('seo_ie_product_import_adaptive_config', 'seo_processes_filter_import_adaptive_config', 50);

if (!function_exists('seo_processes_filter_health_scope_config')) {
    function seo_processes_filter_health_scope_config($config, $scope) {
        if (!is_array($config)) {
            return $config;
        }
        $control = seo_processes_control_for('health-' . sanitize_key((string) $scope));
        if ($control) {
            $config['batch'] = absint($control['batch']);
            $config['load_batch'] = absint($control['load_batch']);
        }
        return $config;
    }
}
add_filter('seo_health_scan_scope_config', 'seo_processes_filter_health_scope_config', 50, 2);

if (!function_exists('seo_processes_health_runner_control')) {
    /**
     * Parametros enviados dentro del JSON del lote a los runners externos.
     * Runners antiguos ignoran la clave `control` sin romper compatibilidad.
     */
    function seo_processes_health_runner_control($scope) {
        $control = seo_processes_control_for('health-' . sanitize_key((string) $scope));
        if (!$control) {
            return array();
        }
        return array(
            'initial_workers' => absint($control['initial_workers']),
            'max_workers' => absint($control['max_workers']),
            'fast_p95_ms' => absint($control['fast_p95_ms']),
            'slow_p95_ms' => absint($control['slow_p95_ms']),
            'very_slow_p95_ms' => absint($control['very_slow_p95_ms']),
            'min_interval_ms' => absint($control['min_interval_ms']),
            'initial_interval_ms' => absint($control['initial_interval_ms']),
            'max_interval_ms' => absint($control['max_interval_ms']),
        );
    }
}

if (!function_exists('seo_processes_save_controls')) {
    function seo_processes_save_controls() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No autorizado.', 'seo-taxonomy'));
        }
        check_admin_referer('seo_processes_save_controls');

        if (!empty($_POST['reset_controls'])) {
            delete_option('seo_processes_control_settings');
            $message = 'restored';
        } else {
            $raw = isset($_POST['controls']) && is_array($_POST['controls']) ? wp_unslash($_POST['controls']) : array();
            update_option('seo_processes_control_settings', seo_processes_sanitize_controls($raw), false);
            $message = 'saved';
        }

        wp_safe_redirect(add_query_arg(array('page' => 'seo-processes', 'process_controls' => $message), admin_url('admin.php')));
        exit;
    }
}
add_action('admin_post_seo_processes_save_controls', 'seo_processes_save_controls');

if (!function_exists('seo_processes_admin_url')) {
    function seo_processes_admin_url($page, $args = array()) {
        return add_query_arg(array_merge(array('page' => $page), (array) $args), admin_url('admin.php'));
    }
}

if (!function_exists('seo_processes_mysql_utc_timestamp')) {
    function seo_processes_mysql_utc_timestamp($value) {
        $value = trim((string) $value);
        if ('' === $value || '0000-00-00 00:00:00' === $value) {
            return 0;
        }

        $timestamp = strtotime($value . ' UTC');
        return false === $timestamp ? 0 : (int) $timestamp;
    }
}

if (!function_exists('seo_processes_mysql_local_timestamp')) {
    function seo_processes_mysql_local_timestamp($value) {
        $value = trim((string) $value);
        if ('' === $value || '0000-00-00 00:00:00' === $value) {
            return 0;
        }

        $timezone = function_exists('wp_timezone') ? wp_timezone() : null;
        if ($timezone instanceof DateTimeZone) {
            $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $timezone);
            if ($date instanceof DateTimeImmutable) {
                return $date->getTimestamp();
            }
        }

        $timestamp = strtotime($value);
        return false === $timestamp ? 0 : (int) $timestamp;
    }
}

if (!function_exists('seo_processes_age_seconds')) {
    function seo_processes_age_seconds($timestamp) {
        $timestamp = absint($timestamp);
        return $timestamp > 0 ? max(0, time() - $timestamp) : null;
    }
}

if (!function_exists('seo_processes_format_age')) {
    function seo_processes_format_age($seconds) {
        if (null === $seconds) {
            return 'Sin actividad registrada';
        }

        $seconds = max(0, absint($seconds));
        if ($seconds < 5) {
            return 'Ahora mismo';
        }
        if ($seconds < 60) {
            return 'Hace ' . number_format_i18n($seconds) . ' s';
        }
        if ($seconds < HOUR_IN_SECONDS) {
            return 'Hace ' . number_format_i18n((int) floor($seconds / MINUTE_IN_SECONDS)) . ' min';
        }
        if ($seconds < DAY_IN_SECONDS) {
            return 'Hace ' . number_format_i18n((int) floor($seconds / HOUR_IN_SECONDS)) . ' h';
        }

        return 'Hace ' . number_format_i18n((int) floor($seconds / DAY_IN_SECONDS)) . ' d';
    }
}

if (!function_exists('seo_processes_format_rate')) {
    function seo_processes_format_rate($per_minute, $unit = 'tareas') {
        $per_minute = max(0.0, (float) $per_minute);
        if ($per_minute <= 0.0) {
            return 'Sin ritmo medible';
        }

        if ($per_minute >= 1000) {
            return number_format_i18n($per_minute, 0) . ' ' . $unit . '/min';
        }
        if ($per_minute >= 10) {
            return number_format_i18n($per_minute, 1) . ' ' . $unit . '/min';
        }

        return number_format_i18n($per_minute, 2) . ' ' . $unit . '/min';
    }
}

if (!function_exists('seo_processes_state')) {
    function seo_processes_state($code, $label, $tone) {
        return array(
            'code'  => sanitize_key($code),
            'label' => sanitize_text_field($label),
            'tone'  => sanitize_key($tone),
        );
    }
}

if (!function_exists('seo_processes_import_export_state')) {
    function seo_processes_import_export_state($queue_status, $diagnostics) {
        $raw = sanitize_key((string) ($queue_status['status'] ?? ($diagnostics['status'] ?? 'idle')));
        $enabled = !empty($queue_status['enabled']);
        $idle = absint($diagnostics['idle_seconds'] ?? 0);
        $delay = absint($diagnostics['adaptive_next_delay'] ?? 0);

        if (!empty($diagnostics['controller_active'])) {
            return seo_processes_state('running', 'En ejecución · control propio', 'running');
        }
        if (in_array($raw, array('failed', 'error'), true)) {
            return seo_processes_state('error', 'Error', 'error');
        }
        if (in_array($raw, array('paused', 'stopped', 'stopping', 'cancelled'), true)) {
            return seo_processes_state('stopped', 'Parado', 'stopped');
        }
        if (!empty($diagnostics['lock_active']) && array_key_exists('worker_pid_alive', $diagnostics) && false === $diagnostics['worker_pid_alive']) {
            return seo_processes_state('error', 'Sin avance · PID desaparecido', 'error');
        }
        if (!empty($diagnostics['lock_active'])) {
            return seo_processes_state('running', 'En ejecución', 'running');
        }
        if (!empty($diagnostics['controller_stale'])) {
            return seo_processes_state('error', 'Parado · controlador perdido', 'error');
        }
        if (!empty($diagnostics['direct_worker_stale'])) {
            return seo_processes_state('error', 'Sin avance · proceso no arrancó', 'error');
        }
        if (!empty($diagnostics['direct_worker_pending'])) {
            return seo_processes_state('waiting', 'Arranque propio preparado', 'waiting');
        }
        if (in_array($raw, array('processing', 'starting', 'retrying'), true)) {
            $stale_after = max(90, $delay + 60);
            if ($idle > $stale_after) {
                return seo_processes_state('error', 'Parado · sin proceso', 'error');
            }
            return seo_processes_state('waiting', 'Preparando siguiente lote', 'waiting');
        }
        if ('waiting_next' === $raw || ($enabled && !empty($diagnostics))) {
            return seo_processes_state('waiting', 'En espera controlada', 'waiting');
        }
        if ('completed' === $raw) {
            return seo_processes_state('completed', 'Parado · completado', 'completed');
        }

        return seo_processes_state('stopped', 'Parado', 'stopped');
    }
}

if (!function_exists('seo_processes_collect_import_export')) {
    function seo_processes_collect_import_export() {
        $queue_status = function_exists('seo_ie_batch_status') ? (array) seo_ie_batch_status() : array();
        $user_id = absint($queue_status['user_id'] ?? 0);
        $active = array();

        if (function_exists('seo_ie_product_import_get_active')) {
            if ($user_id > 0) {
                $active = (array) seo_ie_product_import_get_active($user_id);
            }
            if (empty($active)) {
                $active = (array) seo_ie_product_import_get_active(get_current_user_id());
            }
        }

        $diag = isset($active['diagnostics']) && is_array($active['diagnostics']) ? $active['diagnostics'] : array();
        $state = seo_processes_import_export_state($queue_status, $diag);
        $duration = max(0.0, (float) ($diag['last_batch_duration'] ?? 0));
        $rows = absint($diag['last_batch_rows'] ?? 0);
        $rate = ($duration > 0.0 && $rows > 0) ? (($rows / $duration) * 60.0) : 0.0;
        $next_batch = absint($diag['adaptive_next_batch_size'] ?? 0);
        $delay = absint($diag['adaptive_next_delay'] ?? 0);
        $pressure = sanitize_key((string) ($diag['adaptive_pressure'] ?? ''));
        $activity_ts = absint($diag['last_activity_at'] ?? 0);
        if (!$activity_ts && !empty($queue_status['updated_at'])) {
            $activity_ts = seo_processes_mysql_local_timestamp($queue_status['updated_at']);
        }
        $age = seo_processes_age_seconds($activity_ts);
        $entity = sanitize_key((string) ($queue_status['entity'] ?? ''));
        $file = sanitize_file_name((string) ($queue_status['current_file'] ?? $queue_status['last_file'] ?? $active['archivo'] ?? ''));
        $progress = isset($active['progreso']) ? max(0, min(100, absint($active['progreso']))) : null;

        $backend = sanitize_key((string) ($diag['last_dispatch_backend'] ?? $diag['last_worker_backend'] ?? ''));
        $backend_labels = array(
            'direct_cli'       => 'PHP CLI propio',
            'direct_http'      => 'loopback propio',
            'direct_unavailable' => 'motor propio no disponible',
            'legacy_scheduler' => 'scheduler heredado',
            'action_scheduler' => 'Action Scheduler heredado',
            'wp_cron'          => 'WP-Cron heredado',
            'manager_cron'     => 'gestor periódico',
            'server_cron'      => 'gestor periódico · cron servidor',
            'wp_cron_manager'  => 'gestor periódico · WP-Cron',
            'manual_manager'   => 'gestor periódico · arranque manual',
            'request_pulse'    => 'gestor periódico · pulso web',
            'process_manager'  => 'gestor periódico · pendiente',
        );
        $backend_label = isset($backend_labels[$backend]) ? $backend_labels[$backend] : ($backend ? $backend : 'motor propio pendiente de detectar');
        $controller_active = !empty($diag['controller_active']);
        $controller_stale = !empty($diag['controller_stale']);
        $controller_pid = absint($diag['controller_pid'] ?? 0);

        $load_bits = array();
        $load_bits[] = 'Motor: ' . $backend_label;
        if ($next_batch > 0) {
            $load_bits[] = 'siguiente lote ' . number_format_i18n($next_batch);
        }
        if ($delay > 0) {
            $load_bits[] = 'pausa propia ' . number_format_i18n($delay) . ' s';
        }
        if ($pressure) {
            $load_bits[] = 'presión ' . $pressure;
        }
        if ($controller_active) {
            $worker_label = 'controlador continuo activo';
            if ($controller_pid) {
                $worker_label .= ' · PID ' . $controller_pid;
            }
            $load_bits[] = $worker_label;
        } elseif (!empty($diag['lock_active'])) {
            $worker_label = 'lote PHP activo';
            if (!empty($diag['php_pid'])) {
                $worker_label .= ' · PID ' . absint($diag['php_pid']);
            }
            $load_bits[] = $worker_label;
        } elseif ($controller_stale) {
            $load_bits[] = 'ALERTA: controlador propio desaparecido';
        } elseif (!empty($diag['direct_worker_stale'])) {
            $load_bits[] = 'ALERTA: despacho propio sin arrancar';
        } elseif (!empty($diag['direct_worker_pending'])) {
            $due_in = absint($diag['direct_worker_due_in'] ?? 0);
            $worker_label = $due_in > 0 ? 'arranque propio en ' . number_format_i18n($due_in) . ' s' : 'arranque propio enviado';
            if (!empty($diag['last_dispatch_pid'])) {
                $worker_label .= ' · PID ' . absint($diag['last_dispatch_pid']);
            }
            $load_bits[] = $worker_label;
        } elseif ('processing' === sanitize_key((string) ($diag['status'] ?? ''))) {
            $load_bits[] = 'sin proceso activo';
        }
        if (empty($load_bits) && $entity) {
            $load_bits[] = 'Tipo: ' . $entity;
        }

        $response = 'Sin lote medido';
        if ($duration > 0.0) {
            $response = number_format_i18n($duration, 2) . ' s el último lote';
            if (!empty($diag['last_batch_seconds_per_row'])) {
                $response .= ' · ' . number_format_i18n((float) $diag['last_batch_seconds_per_row'], 3) . ' s/fila';
            }
        }

        $detail = sanitize_text_field((string) ($queue_status['message'] ?? ''));
        if ($file) {
            $detail = ($detail ? $detail . ' ' : '') . 'Archivo: ' . $file . '.';
        }
        if (!empty($diag['last_dispatch_error'])) {
            $detail .= ($detail ? ' ' : '') . 'Motor propio: ' . sanitize_text_field((string) $diag['last_dispatch_error']);
        }
        if (!empty($diag['legacy_batch_scheduled'])) {
            $detail .= ($detail ? ' ' : '') . 'Existe una acción heredada del scheduler; el motor propio la elimina al encadenar el siguiente lote.';
        }

        $live = $controller_active || !empty($diag['lock_active']) || (!empty($diag['direct_worker_pending']) && empty($diag['direct_worker_stale']));
        $speed_text = seo_processes_format_rate($rate, 'filas');
        if (!$live && in_array($state['code'], array('stopped', 'error'), true)) {
            if ($rate > 0) {
                $response .= ' · último ritmo ' . seo_processes_format_rate($rate, 'filas');
            }
            $speed_text = '0 filas/min';
        }
        $can_start = !empty($active['token']) && !$live && in_array($state['code'], array('stopped', 'error', 'waiting'), true);

        return array(
            'id'            => 'import-export',
            'name'          => 'Import / Export',
            'kind'          => 'Controlador propio · scheduler solo respaldo',
            'state'         => $state,
            'speed'         => $speed_text,
            'response'      => $response,
            'load'          => !empty($load_bits) ? implode(' · ', $load_bits) : 'Regulador sin datos todavía',
            'activity'      => seo_processes_format_age($age),
            'activity_age'  => $age,
            'progress'      => $progress,
            'progress_text' => null !== $progress ? number_format_i18n($progress) . '%' : '—',
            'detail'        => $detail ?: 'Cola de importación sin actividad registrada.',
            'url'           => seo_processes_admin_url('seo-import-export', array('seo_ie_tab' => 'import-batch')),
            'can_start'     => $can_start,
            'start_label'   => 'Arrancar / reanudar',
        );
    }
}

if (!function_exists('seo_processes_collect_academy')) {
    function seo_processes_collect_academy() {
        $payload = array();
        if (class_exists('SEO_Dependiente_Entrenador') && is_callable(array('SEO_Dependiente_Entrenador', 'process_monitor_payload'))) {
            $payload = (array) SEO_Dependiente_Entrenador::process_monitor_payload();
        }

        $state_data = isset($payload['state']) && is_array($payload['state']) ? $payload['state'] : (array) get_option('seo_dependiente_academy_auto_state', array());
        $engine = isset($payload['scheduler']) && is_array($payload['scheduler']) ? $payload['scheduler'] : array();
        $running = !empty($payload['running']) || (
            !empty($state_data['enabled'])
            && 'auto' === (string) ($state_data['mode'] ?? '')
            && 'running' === (string) ($state_data['status'] ?? '')
        );
        $raw = sanitize_key((string) ($state_data['status'] ?? 'manual'));
        $heartbeat = absint($state_data['worker_heartbeat_ts'] ?? 0);
        $controller_heartbeat = absint($engine['controller_heartbeat_ts'] ?? $state_data['controller_heartbeat_ts'] ?? 0);
        $activity_heartbeat = max($heartbeat, $controller_heartbeat);
        $age = seo_processes_age_seconds($activity_heartbeat);
        $worker_flag = !empty($state_data['worker_active']) || !empty($engine['worker_active']);
        $batch_worker_active = $worker_flag && (null === seo_processes_age_seconds($heartbeat) || seo_processes_age_seconds($heartbeat) <= 90);
        $controller_active = !empty($engine['controller_active']);
        $controller_stale = !empty($engine['controller_stale']);
        $worker_active = $controller_active || $batch_worker_active;
        $worker_stale = !$controller_active && $worker_flag && null !== seo_processes_age_seconds($heartbeat) && seo_processes_age_seconds($heartbeat) > 90;
        $direct_pending = !empty($engine['direct_pending']) || !empty($state_data['direct_worker_pending']);
        $direct_stale = !empty($engine['direct_stale']);

        if ('error' === $raw) {
            $state = seo_processes_state('error', 'Error / pausado', 'error');
        } elseif ($running && $worker_active) {
            $state = seo_processes_state('running', 'En ejecución', 'running');
        } elseif ($running && $controller_stale) {
            $state = seo_processes_state('error', 'Parado · controlador perdido', 'error');
        } elseif ($running && $worker_stale) {
            $state = seo_processes_state('error', 'Sin avance · heartbeat perdido', 'error');
        } elseif ($running && $direct_stale) {
            $state = seo_processes_state('error', 'Sin avance · proceso no arrancó', 'error');
        } elseif ($running && $direct_pending) {
            $state = seo_processes_state('waiting', 'Arranque propio preparado', 'waiting');
        } elseif ($running && null !== $age && $age > 90) {
            $state = seo_processes_state('error', 'Parado · sin proceso', 'error');
        } elseif ($running) {
            $state = seo_processes_state('waiting', 'Preparando siguiente lote', 'waiting');
        } elseif ('completed' === $raw) {
            $state = seo_processes_state('completed', 'Parado · completado', 'completed');
        } else {
            $state = seo_processes_state('stopped', 'Parado', 'stopped');
        }

        $duration = max(0.0, (float) ($state_data['last_duration'] ?? 0));
        $processed = absint($state_data['last_processed'] ?? 0);
        $batch_size = max(1, absint($state_data['batch_size'] ?? 1));
        $rate_rows = $processed > 0 ? $processed : ($duration > 0.0 ? $batch_size : 0);
        $rate = ($duration > 0.0 && $rate_rows > 0) ? (($rate_rows / $duration) * 60.0) : 0.0;
        $delay = absint($state_data['next_delay'] ?? 0);
        $speed_control = seo_processes_control_for('academy');
        $pressure = 'baja';
        if ($duration >= (float) ($speed_control['very_slow_seconds'] ?? 14.0)) {
            $pressure = 'alta';
        } elseif ($duration >= (float) ($speed_control['slow_seconds'] ?? 7.0)) {
            $pressure = 'media';
        }

        $current = isset($payload['current']) && is_array($payload['current']) ? $payload['current'] : array();
        $lesson = '';
        if (!empty($current['lesson_order']) || !empty($current['title'])) {
            $lesson = 'Lección ' . absint($current['lesson_order']) . ' · ' . sanitize_text_field((string) ($current['title'] ?? ''));
        } elseif (!empty($state_data['current_lesson'])) {
            $lesson = sanitize_text_field((string) $state_data['current_lesson']);
        }
        $module = absint($state_data['current_module'] ?? ($current['next_module'] ?? 0));

        $backend = sanitize_key((string) ($engine['controller_backend'] ?? $engine['direct_backend'] ?? $state_data['controller_backend'] ?? $state_data['last_dispatch_backend'] ?? ''));
        $backend_labels = array(
            'direct_cli'         => 'PHP CLI propio',
            'direct_http'        => 'loopback propio',
            'direct_unavailable' => 'motor propio no disponible',
            'legacy_scheduler'   => 'scheduler heredado',
            'manager_cron'       => 'gestor periódico',
            'server_cron'        => 'gestor periódico · cron servidor',
            'wp_cron_manager'    => 'gestor periódico · WP-Cron',
            'manual_manager'     => 'gestor periódico · arranque manual',
            'request_pulse'      => 'gestor periódico · pulso web',
            'process_manager'    => 'gestor periódico · pendiente',
        );
        $backend_label = isset($backend_labels[$backend]) ? $backend_labels[$backend] : ($backend ? $backend : 'motor propio pendiente de detectar');

        $load_bits = array('Motor: ' . $backend_label, 'lote objetivo ' . number_format_i18n($batch_size));
        if (!empty($speed_control['max_batch'])) {
            $load_bits[] = 'máx. ' . number_format_i18n(absint($speed_control['max_batch']));
        }
        if ($delay > 0) {
            $load_bits[] = 'pausa propia ' . number_format_i18n($delay) . ' s';
        }
        $load_bits[] = 'presión ' . $pressure;
        if ($controller_active) {
            $pid = absint($engine['controller_pid'] ?? $state_data['controller_pid'] ?? 0);
            $label = 'controlador continuo activo';
            if ($pid) {
                $label .= ' · PID ' . $pid;
            }
            $load_bits[] = $label;
        } elseif ($worker_active) {
            $pid = absint($engine['worker_pid'] ?? $state_data['worker_pid'] ?? 0);
            $load_bits[] = 'lote PHP activo' . ($pid ? ' · PID ' . $pid : '');
        } elseif ($direct_stale) {
            $load_bits[] = 'ALERTA: despacho propio sin arrancar';
        } elseif ($direct_pending) {
            $due_in = absint($engine['direct_due_in'] ?? 0);
            $pid = absint($engine['direct_pid'] ?? $state_data['last_dispatch_pid'] ?? 0);
            $load_bits[] = ($due_in > 0 ? 'arranque propio en ' . number_format_i18n($due_in) . ' s' : 'arranque propio enviado') . ($pid ? ' · PID ' . $pid : '');
        }

        $response = $duration > 0.0
            ? number_format_i18n($duration, 2) . ' s el último lote'
            : 'Sin lote medido';
        if ($processed > 0) {
            $response .= ' · ' . number_format_i18n($processed) . ' tareas';
        }

        $detail = sanitize_text_field((string) ($state_data['last_error'] ?? ''));
        if (!$detail) {
            $detail = sanitize_text_field((string) ($state_data['last_message'] ?? ''));
        }
        if (!empty($engine['direct_error'])) {
            $detail .= ($detail ? ' ' : '') . 'Motor propio: ' . sanitize_text_field((string) $engine['direct_error']);
        }
        if ($lesson) {
            $detail = ($detail ? $detail . ' ' : '') . $lesson . ($module > 0 ? ', módulo ' . $module : '') . '.';
        }

        $progress = null;
        if (!empty($current['summary']) && is_array($current['summary'])) {
            $total = absint($current['summary']['total'] ?? 0);
            $answered = absint($current['summary']['answered'] ?? 0);
            if ($total > 0) {
                $progress = max(0, min(100, (int) round(($answered / $total) * 100)));
            }
        }

        $live = $worker_active || ($direct_pending && !$direct_stale);
        $speed_text = seo_processes_format_rate($rate, 'tareas');
        if (!$live && in_array($state['code'], array('stopped', 'error'), true)) {
            if ($rate > 0) {
                $response .= ' · último ritmo ' . seo_processes_format_rate($rate, 'tareas');
            }
            $speed_text = '0 tareas/min';
        }
        $can_start = 'completed' !== $raw
            && !$live
            && in_array($state['code'], array('stopped', 'error', 'waiting'), true);

        return array(
            'id'            => 'academy',
            'name'          => 'Academia',
            'kind'          => 'Controlador propio · scheduler solo respaldo',
            'state'         => $state,
            'speed'         => $speed_text,
            'response'      => $response,
            'load'          => implode(' · ', $load_bits),
            'activity'      => seo_processes_format_age($age),
            'activity_age'  => $age,
            'progress'      => $progress,
            'progress_text' => null !== $progress ? number_format_i18n($progress) . '%' : '—',
            'detail'        => $detail ?: 'Academia detenida, sin controlador propio activo.',
            'url'           => seo_processes_admin_url('seo-dependiente', array('tab' => 'trainer')),
            'can_start'     => $can_start,
            'start_label'   => 'Arrancar / reanudar',
        );
    }
}

if (!function_exists('seo_processes_health_metrics')) {
    function seo_processes_health_metrics($run) {
        $metrics = array(
            'processed'       => absint($run['processed_items'] ?? 0),
            'avg_response_ms' => absint($run['avg_response_ms'] ?? 0),
            'p95_response_ms' => absint($run['p95_response_ms'] ?? 0),
            'status_429'      => absint($run['status_429'] ?? 0),
            'status_5xx'      => absint($run['status_5xx'] ?? 0),
        );

        if (empty($run['id']) || !function_exists('seo_health_scan_tables')) {
            return $metrics;
        }

        $run_status = sanitize_key((string) ($run['status'] ?? ''));
        $needs_live_metrics = in_array($run_status, array('queued', 'running'), true)
            || ($metrics['processed'] > 0 && (0 === $metrics['avg_response_ms'] || 0 === $metrics['p95_response_ms']));
        if (!$needs_live_metrics) {
            return $metrics;
        }

        global $wpdb;
        $table = seo_health_scan_tables()['items'];
        if (function_exists('seo_health_scan_table_exists') && !seo_health_scan_table_exists($table)) {
            return $metrics;
        }

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT response_ms FROM {$table} WHERE last_scan_id=%d AND response_ms>0 ORDER BY response_ms ASC",
                absint($run['id'])
            )
        );

        if (!empty($rows)) {
            $rows = array_values(array_map('absint', $rows));
            $metrics['avg_response_ms'] = (int) round(array_sum($rows) / count($rows));
            $p95_index = max(0, min(count($rows) - 1, (int) ceil(count($rows) * 0.95) - 1));
            $metrics['p95_response_ms'] = absint($rows[$p95_index]);
        }

        $live = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) processed,
                    SUM(final_status=429 OR http_status=429) status_429,
                    SUM((final_status BETWEEN 500 AND 599) OR (http_status BETWEEN 500 AND 599)) status_5xx
                 FROM {$table} WHERE last_scan_id=%d",
                absint($run['id'])
            ),
            ARRAY_A
        );
        if (is_array($live)) {
            $metrics['processed'] = max($metrics['processed'], absint($live['processed'] ?? 0));
            $metrics['status_429'] = max($metrics['status_429'], absint($live['status_429'] ?? 0));
            $metrics['status_5xx'] = max($metrics['status_5xx'], absint($live['status_5xx'] ?? 0));
        }

        return $metrics;
    }
}

if (!function_exists('seo_processes_health_state')) {
    function seo_processes_health_state($run, $active) {
        if (!$run) {
            return seo_processes_state('stopped', 'Parado', 'stopped');
        }

        $raw = sanitize_key((string) ($run['status'] ?? ''));
        if ($active && 'running' === $raw) {
            return seo_processes_state('running', 'En ejecución', 'running');
        }
        if ($active && 'queued' === $raw) {
            return seo_processes_state('waiting', 'En cola', 'waiting');
        }
        if (in_array($raw, array('failed', 'error'), true)) {
            return seo_processes_state('error', 'Error', 'error');
        }
        if ('cancelled' === $raw) {
            return seo_processes_state('stopped', 'Parado', 'stopped');
        }
        if ('completed' === $raw) {
            return seo_processes_state('completed', 'Parado · último lote completado', 'completed');
        }

        return seo_processes_state('stopped', 'Parado', 'stopped');
    }
}

if (!function_exists('seo_processes_collect_health')) {
    function seo_processes_collect_health($scope) {
        $config = function_exists('seo_health_scan_scope_config') ? seo_health_scan_scope_config($scope) : null;
        $label = is_array($config) ? (string) ($config['label'] ?? ucfirst($scope)) : ucfirst($scope);
        $active = function_exists('seo_health_scan_active_run') ? seo_health_scan_active_run($scope) : null;
        $latest = function_exists('seo_health_scan_latest_run') ? seo_health_scan_latest_run($scope) : null;
        $run = is_array($active) ? $active : (is_array($latest) ? $latest : array());
        $state = seo_processes_health_state($run, is_array($active));
        $metrics = seo_processes_health_metrics($run);
        $processed = absint($metrics['processed']);
        $total = absint($run['total_items'] ?? 0);

        $elapsed = 0.0;
        if (!empty($run)) {
            if (!empty($run['duration_ms'])) {
                $elapsed = max(0.001, ((float) $run['duration_ms']) / 1000.0);
            } else {
                $started = seo_processes_mysql_utc_timestamp($run['started_at'] ?? $run['created_at'] ?? '');
                if ($started > 0) {
                    $elapsed = max(0.001, (float) (time() - $started));
                }
            }
        }
        $rate = ($elapsed > 0.0 && $processed > 0) ? (($processed / $elapsed) * 60.0) : 0.0;

        $activity_ts = seo_processes_mysql_utc_timestamp($run['updated_at'] ?? '');
        $age = seo_processes_age_seconds($activity_ts);
        $avg = absint($metrics['avg_response_ms']);
        $p95 = absint($metrics['p95_response_ms']);
        $pressure_events = absint($run['pressure_events'] ?? 0);
        $pressure = 'baja';
        if ($pressure_events > 0 || $metrics['status_429'] > 0 || $metrics['status_5xx'] > 0 || $p95 >= 2500) {
            $pressure = 'alta';
        } elseif ($p95 >= 1500 || $avg >= 1200) {
            $pressure = 'media';
        }

        $response_bits = array();
        if ($avg > 0) {
            $response_bits[] = 'media ' . number_format_i18n($avg) . ' ms';
        }
        if ($p95 > 0) {
            $response_bits[] = 'p95 ' . number_format_i18n($p95) . ' ms';
        }
        if (empty($response_bits)) {
            $response_bits[] = 'Sin respuesta medida';
        }

        $load_bits = array();
        if (is_array($config) && !empty($config['batch'])) {
            $load_bits[] = 'Lote máx.: ' . number_format_i18n(absint($config['batch']));
        }
        $runner_control = seo_processes_health_runner_control($scope);
        if (!empty($runner_control['max_workers'])) {
            $load_bits[] = 'objetivo remoto: ' . number_format_i18n(absint($runner_control['initial_workers'])) . '–' . number_format_i18n(absint($runner_control['max_workers'])) . ' workers';
        }
        $load_bits[] = 'presión ' . $pressure;
        if ($metrics['status_429'] > 0) {
            $load_bits[] = '429: ' . number_format_i18n($metrics['status_429']);
        }
        if ($metrics['status_5xx'] > 0) {
            $load_bits[] = '5xx: ' . number_format_i18n($metrics['status_5xx']);
        }

        $progress = null;
        if ($total > 0) {
            $progress = max(0, min(100, (int) round(($processed / $total) * 100)));
        }

        $detail = '';
        if (!empty($run)) {
            $mode = 'load_test' === (string) ($run['mode'] ?? '') ? 'test de carga' : 'escaneo';
            $detail = ucfirst($mode) . ': ' . number_format_i18n($processed) . '/' . number_format_i18n($total) . ' elementos.';
            if (!empty($run['error_message'])) {
                $detail .= ' ' . sanitize_text_field((string) $run['error_message']);
            }
        } else {
            $detail = 'Todavía no hay ejecuciones registradas para este ámbito.';
        }

        $url = function_exists('seo_health_scan_admin_url')
            ? seo_health_scan_admin_url($scope)
            : admin_url('admin.php');

        return array(
            'id'            => 'health-' . sanitize_key($scope),
            'name'          => 'Chequeo · ' . $label,
            'kind'          => 'Worker GitHub',
            'state'         => $state,
            'speed'         => seo_processes_format_rate($rate, 'peticiones'),
            'response'      => implode(' · ', $response_bits),
            'load'          => implode(' · ', $load_bits),
            'activity'      => seo_processes_format_age($age),
            'activity_age'  => $age,
            'progress'      => $progress,
            'progress_text' => null !== $progress ? number_format_i18n($progress) . '%' : '—',
            'detail'        => $detail,
            'url'           => $url,
        );
    }
}

if (!function_exists('seo_processes_collect')) {
    function seo_processes_collect() {
        $items = array(
            seo_processes_collect_import_export(),
            seo_processes_collect_academy(),
            seo_processes_collect_health('page'),
            seo_processes_collect_health('post'),
            seo_processes_collect_health('image'),
        );

        /**
         * Permite que otros modulos añadan procesos sin modificar esta pantalla.
         * Cada fila debe conservar la estructura devuelta por los collectors.
         */
        $items = apply_filters('seo_processes_monitor_items', $items);
        return is_array($items) ? array_values($items) : array();
    }
}

if (!function_exists('seo_processes_summary')) {
    function seo_processes_summary($items) {
        $summary = array(
            'total'     => count((array) $items),
            'running'   => 0,
            'waiting'   => 0,
            'stopped'   => 0,
            'completed' => 0,
            'error'     => 0,
        );

        foreach ((array) $items as $item) {
            $code = sanitize_key((string) ($item['state']['code'] ?? 'stopped'));
            if (isset($summary[$code])) {
                $summary[$code]++;
            } else {
                $summary['stopped']++;
            }
        }

        return $summary;
    }
}

if (!function_exists('seo_processes_render_rows')) {
    function seo_processes_render_rows($items) {
        ob_start();
        foreach ((array) $items as $item) {
            $state = isset($item['state']) && is_array($item['state']) ? $item['state'] : seo_processes_state('stopped', 'Parado', 'stopped');
            $progress = $item['progress'];
            ?>
            <tr data-process-id="<?php echo esc_attr($item['id']); ?>">
                <td class="seo-process-name">
                    <strong><?php echo esc_html($item['name']); ?></strong>
                    <span><?php echo esc_html($item['kind']); ?></span>
                </td>
                <td>
                    <span class="seo-process-status is-<?php echo esc_attr($state['tone']); ?>">
                        <i aria-hidden="true"></i><?php echo esc_html($state['label']); ?>
                    </span>
                    <span class="seo-process-activity"><?php echo esc_html($item['activity']); ?></span>
                </td>
                <td>
                    <strong class="seo-process-primary-value"><?php echo esc_html($item['speed']); ?></strong>
                    <span><?php echo esc_html($item['response']); ?></span>
                </td>
                <td>
                    <strong><?php echo esc_html($item['load']); ?></strong>
                    <?php if (null !== $progress) : ?>
                        <div class="seo-process-progress" aria-label="Progreso <?php echo esc_attr($item['progress_text']); ?>">
                            <span style="width:<?php echo esc_attr((string) $progress); ?>%"></span>
                        </div>
                        <span><?php echo esc_html($item['progress_text']); ?> completado</span>
                    <?php else : ?>
                        <span>Progreso no aplicable</span>
                    <?php endif; ?>
                </td>
                <td class="seo-process-detail">
                    <span><?php echo esc_html($item['detail']); ?></span>
                    <a class="button button-small" href="<?php echo esc_url($item['url']); ?>">Abrir proceso</a>
                    <?php if (!empty($item['can_start'])) : ?>
                        <button type="button" class="button button-primary button-small seo-process-start" data-process="<?php echo esc_attr($item['id']); ?>"><?php echo esc_html($item['start_label'] ?? 'Arrancar'); ?></button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php
        }
        return ob_get_clean();
    }
}

if (!function_exists('seo_processes_number_input')) {
    function seo_processes_number_input($process, $key, $value, $min, $max, $step = '1') {
        printf(
            '<input type="number" name="controls[%1$s][%2$s]" value="%3$s" min="%4$s" max="%5$s" step="%6$s" class="small-text">',
            esc_attr($process), esc_attr($key), esc_attr((string) $value), esc_attr((string) $min), esc_attr((string) $max), esc_attr((string) $step)
        );
    }
}

if (!function_exists('seo_processes_render_control_panel')) {
    function seo_processes_render_control_panel() {
        $settings = seo_processes_control_settings();
        $import = $settings['import-export'];
        $academy = $settings['academy'];
        ?>
        <form class="seo-process-controls" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="seo_processes_save_controls">
            <?php wp_nonce_field('seo_processes_save_controls'); ?>
            <div class="seo-process-controls__head">
                <div>
                    <h2>Control de velocidad</h2>
                    <p>Los cambios se aplican al <strong>siguiente lote</strong>; no reinician ni despiertan procesos. Los límites están acotados para evitar configuraciones extremas.</p>
                </div>
                <div class="seo-process-controls__actions">
                    <button type="submit" class="button button-primary">Guardar velocidades</button>
                    <button type="submit" class="button" name="reset_controls" value="1" onclick="return confirm('¿Restaurar los límites originales de todos los procesos?');">Restaurar valores originales</button>
                </div>
            </div>

            <details class="seo-process-control-card" open>
                <summary><strong>Import / Export</strong><span>Lotes PHP adaptativos</span></summary>
                <div class="seo-process-control-grid">
                    <label>Lote mínimo<?php seo_processes_number_input('import-export','min_rows',$import['min_rows'],1,500); ?><small>Filas.</small></label>
                    <label>Lote inicial<?php seo_processes_number_input('import-export','initial_rows',$import['initial_rows'],1,2000); ?><small>Filas al arrancar.</small></label>
                    <label>Lote máximo<?php seo_processes_number_input('import-export','max_rows',$import['max_rows'],1,5000); ?><small>Techo absoluto.</small></label>
                    <label>Tiempo objetivo<?php seo_processes_number_input('import-export','target_seconds',$import['target_seconds'],5,300,'0.5'); ?><small>Segundos por lote.</small></label>
                    <label>Tiempo crítico<?php seo_processes_number_input('import-export','hard_seconds',$import['hard_seconds'],10,600,'0.5'); ?><small>A partir de aquí recorta fuerte.</small></label>
                    <label>Multiplicador subida<?php seo_processes_number_input('import-export','growth_factor',$import['growth_factor'],1.10,2.00,'0.01'); ?><small>Máximo crecimiento entre lotes.</small></label>
                    <label>Memoria preventiva<?php seo_processes_number_input('import-export','memory_soft_percent',$import['memory_soft_percent'],20,93,'0.5'); ?><small>% del memory_limit.</small></label>
                    <label>Memoria crítica<?php seo_processes_number_input('import-export','memory_hard_percent',$import['memory_hard_percent'],25,98,'0.5'); ?><small>% del memory_limit.</small></label>
                    <label>Pausa presión media<?php seo_processes_number_input('import-export','heavy_delay_seconds',$import['heavy_delay_seconds'],0,120); ?><small>Segundos.</small></label>
                    <label>Pausa presión alta<?php seo_processes_number_input('import-export','critical_delay_seconds',$import['critical_delay_seconds'],0,300); ?><small>Segundos.</small></label>
                </div>
            </details>

            <details class="seo-process-control-card" open>
                <summary><strong>Academia</strong><span>Lote según duración real</span></summary>
                <div class="seo-process-control-grid">
                    <label>Lote mínimo<?php seo_processes_number_input('academy','min_batch',$academy['min_batch'],1,20); ?><small>Tareas.</small></label>
                    <label>Lote inicial<?php seo_processes_number_input('academy','initial_batch',$academy['initial_batch'],1,30); ?><small>Tareas al arrancar.</small></label>
                    <label>Lote máximo<?php seo_processes_number_input('academy','max_batch',$academy['max_batch'],1,50); ?><small>Techo absoluto.</small></label>
                    <label>Rápido ≤<?php seo_processes_number_input('academy','fast_seconds',$academy['fast_seconds'],0.5,30,'0.1'); ?><small>Segundos por lote.</small></label>
                    <label>Lento ≥<?php seo_processes_number_input('academy','slow_seconds',$academy['slow_seconds'],1,120,'0.1'); ?><small>Empieza a reducir.</small></label>
                    <label>Crítico ≥<?php seo_processes_number_input('academy','very_slow_seconds',$academy['very_slow_seconds'],2,300,'0.1'); ?><small>Vuelve al lote mínimo.</small></label>
                    <label>Multiplicador subida<?php seo_processes_number_input('academy','growth_factor',$academy['growth_factor'],1.05,2.00,'0.01'); ?><small>Tras respuestas rápidas consecutivas.</small></label>
                    <label>Multiplicador bajada<?php seo_processes_number_input('academy','slowdown_factor',$academy['slowdown_factor'],0.20,0.95,'0.01'); ?><small>Se aplica en zona lenta.</small></label>
                    <label>Rachas rápidas<?php seo_processes_number_input('academy','fast_streak_required',$academy['fast_streak_required'],1,10); ?><small>Lotes antes de acelerar.</small></label>
                    <label>Pausa normal<?php seo_processes_number_input('academy','normal_delay_seconds',$academy['normal_delay_seconds'],1,30); ?><small>Segundos.</small></label>
                    <label>Pausa lenta<?php seo_processes_number_input('academy','slow_delay_seconds',$academy['slow_delay_seconds'],1,120); ?><small>Segundos.</small></label>
                    <label>Pausa crítica<?php seo_processes_number_input('academy','critical_delay_seconds',$academy['critical_delay_seconds'],1,300); ?><small>Segundos.</small></label>
                </div>
            </details>

            <?php foreach (array('page' => 'Chequeo de páginas', 'post' => 'Chequeo de posts', 'image' => 'Chequeo de imágenes') as $scope => $title) :
                $key = 'health-' . $scope;
                $health = $settings[$key];
            ?>
            <details class="seo-process-control-card">
                <summary><strong><?php echo esc_html($title); ?></strong><span>Runner GitHub</span></summary>
                <div class="seo-process-control-grid">
                    <label>Tamaño de lote<?php seo_processes_number_input($key,'batch',$health['batch'],10,2000); ?><small>Este límite sí lo aplica WordPress.</small></label>
                    <label>Lote test carga<?php seo_processes_number_input($key,'load_batch',$health['load_batch'],5,2000); ?><small>URLs en modo test.</small></label>
                    <label>Workers iniciales<?php seo_processes_number_input($key,'initial_workers',$health['initial_workers'],1,16); ?><small>Se envía al runner.</small></label>
                    <label>Workers máximos<?php seo_processes_number_input($key,'max_workers',$health['max_workers'],1,16); ?><small>Se envía al runner.</small></label>
                    <label>p95 rápido<?php seo_processes_number_input($key,'fast_p95_ms',$health['fast_p95_ms'],100,10000); ?><small>ms.</small></label>
                    <label>p95 lento<?php seo_processes_number_input($key,'slow_p95_ms',$health['slow_p95_ms'],200,20000); ?><small>ms.</small></label>
                    <label>p95 crítico<?php seo_processes_number_input($key,'very_slow_p95_ms',$health['very_slow_p95_ms'],300,60000); ?><small>ms.</small></label>
                    <label>Intervalo mínimo<?php seo_processes_number_input($key,'min_interval_ms',$health['min_interval_ms'],10,5000); ?><small>ms entre peticiones.</small></label>
                    <label>Intervalo inicial<?php seo_processes_number_input($key,'initial_interval_ms',$health['initial_interval_ms'],10,10000); ?><small>ms entre peticiones.</small></label>
                    <label>Intervalo máximo<?php seo_processes_number_input($key,'max_interval_ms',$health['max_interval_ms'],10,30000); ?><small>ms entre peticiones.</small></label>
                </div>
                <p class="seo-process-control-warning"><strong>Importante:</strong> WordPress aplica el tamaño del lote y devuelve los demás límites en <code>control</code> dentro del JSON del lote. El workflow remoto debe ser compatible con esa clave para que cambien sus workers/intervalos.</p>
            </details>
            <?php endforeach; ?>

            <div class="seo-process-controls__actions seo-process-controls__actions--bottom">
                <button type="submit" class="button button-primary">Guardar velocidades</button>
            </div>
        </form>
        <?php
    }
}

if (!function_exists('seo_processes_ajax_status')) {
    function seo_processes_ajax_status() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'No autorizado.'), 403);
        }
        check_ajax_referer('seo_processes_status', 'nonce');

        $items = seo_processes_collect();
        wp_send_json_success(array(
            'rows'      => seo_processes_render_rows($items),
            'summary'   => seo_processes_summary($items),
            'refreshed' => current_time('H:i:s'),
        ));
    }
}
add_action('wp_ajax_seo_processes_status', 'seo_processes_ajax_status');

if (!function_exists('seo_processes_worker_control')) {
    function seo_processes_worker_control() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'No autorizado.'), 403);
        }
        check_ajax_referer('seo_processes_worker_control', 'nonce');
        $process_id = sanitize_key(wp_unslash($_POST['process_id'] ?? ''));
        $result = null;

        if ('import-export' === $process_id) {
            if (!function_exists('seo_ie_product_import_control_start')) {
                wp_send_json_error(array('message' => 'El motor propio de Import / Export no está disponible.'), 500);
            }
            $queue = function_exists('seo_ie_batch_status') ? (array) seo_ie_batch_status() : array();
            $user_id = absint($queue['user_id'] ?? get_current_user_id());
            if (!$user_id) {
                $user_id = get_current_user_id();
            }
            $result = seo_ie_product_import_control_start($user_id);
        } elseif ('academy' === $process_id) {
            if (!class_exists('SEO_Dependiente_Entrenador') || !is_callable(array('SEO_Dependiente_Entrenador', 'process_control_start'))) {
                wp_send_json_error(array('message' => 'El motor propio de Academia no está disponible.'), 500);
            }
            $result = SEO_Dependiente_Entrenador::process_control_start();
        } else {
            wp_send_json_error(array('message' => 'Este proceso no se arranca desde este servidor porque su ejecución es externa.'), 400);
        }

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 500);
        }

        // Damos unas décimas al proceso desacoplado para reclamar el despacho.
        usleep(250000);
        $items = seo_processes_collect();
        wp_send_json_success(array(
            'message'   => sanitize_text_field((string) ($result['message'] ?? 'Proceso arrancado.')),
            'rows'      => seo_processes_render_rows($items),
            'summary'   => seo_processes_summary($items),
            'refreshed' => current_time('H:i:s'),
        ));
    }
}
add_action('wp_ajax_seo_processes_worker_control', 'seo_processes_worker_control');

if (!function_exists('seo_processes_page')) {
    function seo_processes_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para acceder a esta página.', 'seo-taxonomy'));
        }

        $tab = sanitize_key(wp_unslash($_GET['tab'] ?? 'processes'));
        if ('workers' === $tab && function_exists('seo_process_supervisor_render_page')) {
            seo_process_supervisor_render_page();
            return;
        }

        $items = seo_processes_collect();
        $summary = seo_processes_summary($items);
        $nonce = wp_create_nonce('seo_processes_status');
        $worker_nonce = wp_create_nonce('seo_processes_worker_control');
        ?>
        <div class="wrap seo-processes-wrap">
            <div class="seo-processes-heading">
                <div>
                    <h1>Procesos</h1>
                    <p>Monitor, velocidad y arranque de los procesos automáticos del plugin. Import/Export y Academia usan un controlador propio continuo y el Gestor de workers los vuelve a arrancar automáticamente si detecta trabajo pendiente sin proceso.</p>
                </div>
                <div class="seo-processes-refresh">
                    <button type="button" class="button" id="seo-processes-refresh">Actualizar ahora</button>
                    <span>Última lectura: <strong id="seo-processes-refreshed"><?php echo esc_html(current_time('H:i:s')); ?></strong></span>
                </div>
            </div>
            <h2 class="nav-tab-wrapper" style="margin-bottom:18px">
                <a class="nav-tab nav-tab-active" href="<?php echo esc_url(add_query_arg(array('page' => 'seo-processes'), admin_url('admin.php'))); ?>">Procesos</a>
                <a class="nav-tab" href="<?php echo esc_url(add_query_arg(array('page' => 'seo-processes', 'tab' => 'workers'), admin_url('admin.php'))); ?>">Gestor de workers</a>
            </h2>

            <?php if (isset($_GET['process_controls']) && in_array(sanitize_key(wp_unslash($_GET['process_controls'])), array('saved','restored'), true)) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo 'restored' === sanitize_key(wp_unslash($_GET['process_controls'])) ? 'Se han restaurado los límites originales.' : 'Velocidades guardadas. Se aplicarán en el siguiente lote de cada proceso.'; ?></p></div>
            <?php endif; ?>

            <div id="seo-processes-action-message" class="notice inline" style="display:none"><p></p></div>

            <?php seo_processes_render_control_panel(); ?>

            <h2 class="seo-processes-monitor-title">Monitor en tiempo real</h2>
            <div class="seo-processes-summary" id="seo-processes-summary">
                <div><strong data-summary="running"><?php echo esc_html(number_format_i18n($summary['running'])); ?></strong><span>En ejecución</span></div>
                <div><strong data-summary="waiting"><?php echo esc_html(number_format_i18n($summary['waiting'])); ?></strong><span>En espera</span></div>
                <div><strong data-summary="stopped"><?php echo esc_html(number_format_i18n($summary['stopped'])); ?></strong><span>Parados</span></div>
                <div><strong data-summary="completed"><?php echo esc_html(number_format_i18n($summary['completed'])); ?></strong><span>Completados</span></div>
                <div><strong data-summary="error"><?php echo esc_html(number_format_i18n($summary['error'])); ?></strong><span>Con error</span></div>
            </div>

            <div class="seo-processes-table-wrap">
                <table class="widefat striped seo-processes-table">
                    <thead>
                        <tr>
                            <th>Proceso</th>
                            <th>Estado</th>
                            <th>Velocidad / respuesta</th>
                            <th>Regulador / carga</th>
                            <th>Detalle</th>
                        </tr>
                    </thead>
                    <tbody id="seo-processes-body">
                        <?php echo seo_processes_render_rows($items); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </tbody>
                </table>
            </div>

            <p class="description seo-processes-note">
                “Velocidad” usa el trabajo realmente completado por minuto. Import/Export y Academia reciben pulsos del Gestor de workers: cron real/WP-Cron como respaldo y, si el hosting no ofrece PHP CLI o loopback, las peticiones reales mantienen la cola viva sin crear procesos hijo. Mientras hay trabajo pendiente el gestor vuelve a quedar elegible en pocos segundos y respeta la pausa de cada regulador. Los chequeos externos se ejecutan en GitHub.
            </p>
        </div>

        <style id="seo-processes-styles">

            .seo-process-controls{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;margin:0 0 24px}.seo-process-controls__head{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin-bottom:12px}.seo-process-controls__head h2{margin:0 0 4px}.seo-process-controls__head p{margin:0;color:#50575e;max-width:850px}.seo-process-controls__actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.seo-process-control-card{border-top:1px solid #e2e4e7;padding:0}.seo-process-control-card summary{cursor:pointer;padding:13px 0;display:flex;gap:10px;align-items:baseline}.seo-process-control-card summary span{font-size:12px;color:#646970;font-weight:400}.seo-process-control-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:12px 16px;padding:2px 0 16px}.seo-process-control-grid label{font-weight:600}.seo-process-control-grid input{display:block;width:100%;max-width:150px;margin-top:5px}.seo-process-control-grid small{display:block;color:#646970;font-weight:400;margin-top:3px}.seo-process-control-warning{background:#fff8e5;border-left:4px solid #dba617;padding:9px 11px;margin:0 0 16px}.seo-process-controls__actions--bottom{border-top:1px solid #e2e4e7;padding-top:14px}.seo-processes-monitor-title{margin-top:0}
            .seo-processes-wrap{max-width:1500px}.seo-processes-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:24px;margin-bottom:18px}.seo-processes-heading h1{margin-bottom:4px}.seo-processes-heading p{max-width:880px;margin-top:0;color:#50575e}.seo-processes-refresh{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:10px}.seo-processes-refresh span{color:#646970;font-size:12px}.seo-processes-summary{display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:10px;margin:0 0 16px}.seo-processes-summary>div{background:#fff;border:1px solid #dcdcde;border-radius:7px;padding:14px 16px}.seo-processes-summary strong{display:block;font-size:25px;line-height:1.15}.seo-processes-summary span{display:block;margin-top:3px;color:#646970}.seo-processes-table-wrap{background:#fff;border:1px solid #dcdcde;border-radius:8px;overflow:auto}.seo-processes-table{border:0;min-width:1120px}.seo-processes-table th{padding:12px 14px}.seo-processes-table td{padding:14px;vertical-align:top}.seo-processes-table td>span:not(.seo-process-status),.seo-processes-table td>strong+span{display:block;margin-top:5px;color:#646970;font-size:12px}.seo-process-name strong{display:block;font-size:14px}.seo-process-name span{display:block;margin-top:4px;color:#646970}.seo-process-status{display:inline-flex;align-items:center;gap:7px;border-radius:999px;padding:4px 9px;font-size:12px;font-weight:700;background:#f0f0f1;color:#50575e}.seo-process-status i{display:block;width:8px;height:8px;border-radius:50%;background:currentColor}.seo-process-status.is-running{background:#edfaef;color:#116329}.seo-process-status.is-waiting{background:#f0f6fc;color:#135e96}.seo-process-status.is-error{background:#fcf0f1;color:#8a2424}.seo-process-status.is-completed{background:#f0f6fc;color:#0a4b78}.seo-process-status.is-stopped{background:#f0f0f1;color:#646970}.seo-process-activity{display:block;margin-top:7px;color:#646970;font-size:12px}.seo-process-primary-value{display:block;font-size:16px}.seo-process-progress{height:6px;margin-top:9px;background:#e5e5e5;border-radius:6px;overflow:hidden}.seo-process-progress span{display:block;height:100%;background:#2271b1}.seo-process-detail{min-width:300px}.seo-process-detail .button{margin-top:10px;margin-right:6px}.seo-process-start.is-starting{opacity:.65;pointer-events:none}.seo-processes-note{margin-top:12px;max-width:1200px}.seo-processes-wrap.is-refreshing #seo-processes-refresh{opacity:.65;pointer-events:none}.seo-processes-wrap.is-refreshing #seo-processes-refreshed:after{content:' · actualizando…';font-weight:400}@media(max-width:900px){.seo-process-controls__head{display:block}.seo-process-controls__actions{justify-content:flex-start;margin-top:10px}.seo-processes-heading{display:block}.seo-processes-refresh{justify-content:flex-start}.seo-processes-summary{grid-template-columns:repeat(2,minmax(120px,1fr))}}@media(max-width:520px){.seo-processes-summary{grid-template-columns:1fr}}
        </style>

        <script>
        (function(){
            var root=document.querySelector('.seo-processes-wrap');
            var body=document.getElementById('seo-processes-body');
            var refreshButton=document.getElementById('seo-processes-refresh');
            var refreshed=document.getElementById('seo-processes-refreshed');
            var actionMessage=document.getElementById('seo-processes-action-message');
            var timer=null;
            if(!root||!body||!refreshButton){return;}

            function updateSummary(summary){
                if(!summary){return;}
                Object.keys(summary).forEach(function(key){
                    var el=document.querySelector('[data-summary="'+key+'"]');
                    if(el){el.textContent=String(summary[key]);}
                });
            }

            function load(){
                if(root.classList.contains('is-refreshing')){return;}
                root.classList.add('is-refreshing');
                var data=new URLSearchParams();
                data.append('action','seo_processes_status');
                data.append('nonce',<?php echo wp_json_encode($nonce); ?>);
                fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,{
                    method:'POST',
                    credentials:'same-origin',
                    headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                    body:data.toString()
                }).then(function(response){return response.json();}).then(function(payload){
                    if(payload&&payload.success&&payload.data){
                        if(typeof payload.data.rows==='string'){body.innerHTML=payload.data.rows;}
                        updateSummary(payload.data.summary||{});
                        if(refreshed&&payload.data.refreshed){refreshed.textContent=payload.data.refreshed;}
                    }
                }).catch(function(){
                    if(refreshed){refreshed.textContent='Error de lectura';}
                }).finally(function(){
                    root.classList.remove('is-refreshing');
                });
            }

            function showActionMessage(text,isError){
                if(!actionMessage){return;}
                actionMessage.className='notice inline '+(isError?'notice-error':'notice-success');
                var p=actionMessage.querySelector('p');
                if(p){p.textContent=text||'';}
                actionMessage.style.display='block';
            }

            body.addEventListener('click',function(event){
                var button=event.target.closest('.seo-process-start');
                if(!button){return;}
                event.preventDefault();
                var processId=button.getAttribute('data-process')||'';
                if(!processId){return;}
                button.classList.add('is-starting');
                button.disabled=true;
                button.textContent='Arrancando…';
                var data=new URLSearchParams();
                data.append('action','seo_processes_worker_control');
                data.append('nonce',<?php echo wp_json_encode($worker_nonce); ?>);
                data.append('process_id',processId);
                fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,{
                    method:'POST',credentials:'same-origin',
                    headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                    body:data.toString()
                }).then(function(response){return response.json();}).then(function(payload){
                    if(payload&&payload.success&&payload.data){
                        if(typeof payload.data.rows==='string'){body.innerHTML=payload.data.rows;}
                        updateSummary(payload.data.summary||{});
                        if(refreshed&&payload.data.refreshed){refreshed.textContent=payload.data.refreshed;}
                        showActionMessage(payload.data.message||'Proceso arrancado.',false);
                        window.setTimeout(load,1200);
                    }else{
                        var msg=payload&&payload.data&&payload.data.message?payload.data.message:'No se pudo arrancar el proceso.';
                        showActionMessage(msg,true);
                        button.classList.remove('is-starting');button.disabled=false;button.textContent='Arrancar / reanudar';
                    }
                }).catch(function(){
                    showActionMessage('Error de comunicación al arrancar el proceso.',true);
                    button.classList.remove('is-starting');button.disabled=false;button.textContent='Arrancar / reanudar';
                });
            });

            refreshButton.addEventListener('click',load);
            timer=window.setInterval(load,5000);
            document.addEventListener('visibilitychange',function(){
                if(document.hidden){
                    if(timer){window.clearInterval(timer);timer=null;}
                }else{
                    load();
                    if(!timer){timer=window.setInterval(load,5000);}
                }
            });
        }());
        </script>
        <?php
    }
}
