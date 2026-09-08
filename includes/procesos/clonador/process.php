<?php
/**
 * Clonador para Academia integrado con el Gestor de procesos nativo.
 *
 * No crea PHP CLI ni loopbacks HTTP. Cada ciclo del supervisor entrega una
 * pequena ventana de trabajo al motor del Clonador, que guarda cursor y mapas
 * para continuar en el siguiente pulso.
 *
 * @package SEOSystem
 * @subpackage Processes_Clonador
 * @since 2.5.5
 */

defined('ABSPATH') || exit;

final class SEO_Clonador_Process {
    const OPTION = 'seo_clonador_process_job';

    public static function init() {
        add_filter('seo_process_supervisor_has_pending_work', array(__CLASS__, 'filter_pending_work'));
        add_filter('seo_process_supervisor_manager_targets', array(__CLASS__, 'filter_manager_targets'), 20, 3);
    }

    private static function fresh_option($name, $default = array()) {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete((string) $name, 'options');
        }
        $value = get_option((string) $name, $default);
        return is_array($value) ? $value : $default;
    }

    public static function state() {
        return wp_parse_args(self::fresh_option(self::OPTION, array()), array(
            'job_id' => '',
            'status' => 'idle',
            'phase' => '',
            'message' => '',
            'created_at' => 0,
            'started_at' => 0,
            'heartbeat_at' => 0,
            'completed_at' => 0,
            'requested_by' => 0,
            'backend' => 'process_manager',
            'last_error' => '',
            'preview' => array(),
            'stats' => array(),
            'warnings' => array(),
            'progress' => array(),
            'result' => array(),
        ));
    }

    public static function save($changes) {
        $state = self::state();
        foreach ((array) $changes as $key => $value) {
            $state[$key] = $value;
        }
        update_option(self::OPTION, $state, false);
        self::sync_supervisor($state);
        return $state;
    }

    private static function sync_supervisor($state = null) {
        if (!function_exists('seo_process_supervisor_managed_update')) return;
        $state = is_array($state) ? $state : self::state();
        $status = sanitize_key((string) ($state['status'] ?? 'idle'));
        $pending = in_array($status, array('queued', 'running'), true);
        $healthy = 'failed' !== $status;
        $detail = (string) ($state['message'] ?? '');
        if ('' === $detail) {
            $detail = $pending ? 'Clonacion pendiente o en curso.' : ('completed' === $status ? 'Clonacion completada.' : 'Sin clonacion pendiente.');
        }
        seo_process_supervisor_managed_update('clonador-academia', array(
            'name' => 'Clonador para Academia',
            'pending' => $pending ? 1 : 0,
            'healthy' => $healthy ? 1 : 0,
            'last_checked' => time(),
            'last_result' => $status,
            'last_error' => sanitize_text_field((string) ($state['last_error'] ?? '')),
            'detail' => sanitize_text_field($detail),
        ));
    }

    public static function public_state() {
        $state = self::state();
        return array(
            'job_id' => sanitize_key((string) $state['job_id']),
            'status' => sanitize_key((string) $state['status']),
            'phase' => sanitize_key((string) $state['phase']),
            'message' => sanitize_text_field((string) $state['message']),
            'created_at' => absint($state['created_at']),
            'started_at' => absint($state['started_at']),
            'heartbeat_at' => absint($state['heartbeat_at']),
            'completed_at' => absint($state['completed_at']),
            'backend' => 'process_manager',
            'last_error' => sanitize_text_field((string) $state['last_error']),
            'stats' => is_array($state['stats']) ? $state['stats'] : array(),
            'warnings' => is_array($state['warnings']) ? $state['warnings'] : array(),
            'progress' => is_array($state['progress']) ? $state['progress'] : array(),
            'result' => is_array($state['result']) ? $state['result'] : array(),
        );
    }

    public static function is_active() {
        $status = sanitize_key((string) (self::state()['status'] ?? 'idle'));
        return in_array($status, array('queued', 'running'), true);
    }

    public static function queue($preview) {
        if (!class_exists('SEO_Clonador_Engine') || !is_callable(array('SEO_Clonador_Engine', 'initialize_manager_job'))) {
            return new WP_Error('clonador_process_engine', 'No esta cargado el motor por lotes del Clonador.');
        }
        if (function_exists('seo_process_supervisor_settings')) {
            $supervisor = (array) seo_process_supervisor_settings();
            if (empty($supervisor['enabled'])) {
                return new WP_Error('clonador_supervisor_disabled', 'El Gestor de procesos esta desactivado. Activalo antes de iniciar la clonacion.');
            }
        }
        $current = self::state();
        if (in_array((string) ($current['status'] ?? ''), array('queued', 'running'), true)) {
            return new WP_Error('clonador_process_busy', 'Ya hay una clonacion en cola o en ejecucion.');
        }
        $preview = is_array($preview) ? $preview : array();
        if (empty($preview['source_marker']) || empty($preview['target_marker'])) {
            return new WP_Error('clonador_process_preview', 'El plan previo no contiene las huellas necesarias.');
        }
        $job_id = sanitize_key('clone-' . strtolower(wp_generate_password(18, false, false)));
        $remote = SEO_Clonador_Engine::initialize_manager_job($job_id, $preview);
        if (is_wp_error($remote)) return $remote;

        self::save(array(
            'job_id' => $job_id,
            'status' => 'queued',
            'phase' => 'preflight',
            'message' => 'Clonacion en cola del Gestor de procesos. Se ejecutara por lotes sin depender de esta pantalla.',
            'created_at' => time(),
            'started_at' => 0,
            'heartbeat_at' => time(),
            'completed_at' => 0,
            'requested_by' => get_current_user_id(),
            'backend' => 'process_manager',
            'last_error' => '',
            'preview' => array(
                'source_marker' => (string) $preview['source_marker'],
                'target_marker' => (string) $preview['target_marker'],
                'identity' => isset($preview['identity']) && is_array($preview['identity']) ? $preview['identity'] : array(),
                'previewed_at' => absint($preview['previewed_at'] ?? 0),
            ),
            'stats' => array(),
            'warnings' => array(),
            'progress' => isset($remote['progress']) && is_array($remote['progress']) ? $remote['progress'] : array(),
            'result' => array(),
        ));

        if (function_exists('seo_process_supervisor_nudge')) seo_process_supervisor_nudge(0, 'clonador');
        if (function_exists('seo_process_supervisor_schedule_backup')) seo_process_supervisor_schedule_backup();
        return self::public_state();
    }

    public static function filter_pending_work($pending) {
        return $pending || self::is_active();
    }

    public static function filter_manager_targets($targets, $settings, $source) {
        unset($settings, $source);
        if (self::is_active()) {
            $targets[] = array(
                'type' => 'clonador',
                'data' => array('job_id' => sanitize_key((string) (self::state()['job_id'] ?? ''))),
                'callback' => array(__CLASS__, 'manager_callback'),
            );
        }
        self::sync_supervisor();
        return $targets;
    }

    public static function manager_callback($budget, $source, $target) {
        unset($target);
        $state = self::state();
        if (!in_array((string) ($state['status'] ?? ''), array('queued', 'running'), true)) return true;
        if (!class_exists('SEO_Clonador_Engine') || !is_callable(array('SEO_Clonador_Engine', 'process_manager_slice'))) {
            self::save(array('status'=>'failed','phase'=>'bootstrap','last_error'=>'No esta disponible SEO_Clonador_Engine::process_manager_slice.','message'=>'Motor por lotes no disponible.','completed_at'=>time()));
            return false;
        }
        if ('queued' === (string) $state['status']) {
            self::save(array(
                'status' => 'running',
                'started_at' => time(),
                'heartbeat_at' => time(),
                'backend' => 'process_manager',
                'message' => 'El Gestor de procesos ha iniciado la clonacion por lotes.',
            ));
        }
        $job_id = sanitize_key((string) (self::state()['job_id'] ?? ''));
        $result = SEO_Clonador_Engine::process_manager_slice($job_id, max(5, absint($budget)), sanitize_key((string) $source));
        if (is_wp_error($result)) {
            $error_data = $result->get_error_data();
            $remote_state = (is_array($error_data) && isset($error_data['state']) && is_array($error_data['state'])) ? $error_data['state'] : array();
            self::save(array(
                'status' => 'failed',
                'phase' => sanitize_key((string) ($remote_state['phase'] ?? 'failed')),
                'message' => sanitize_text_field((string) ($remote_state['message'] ?? 'Clonacion detenida. STAGING queda marcado como incompleto y puede reiniciarse desde cero.')),
                'heartbeat_at' => time(),
                'completed_at' => time(),
                'last_error' => $result->get_error_message(),
                'stats' => isset($remote_state['stats']) && is_array($remote_state['stats']) ? $remote_state['stats'] : array(),
                'warnings' => isset($remote_state['warnings']) && is_array($remote_state['warnings']) ? $remote_state['warnings'] : array(),
                'progress' => isset($remote_state['progress']) && is_array($remote_state['progress']) ? $remote_state['progress'] : array(),
                'result' => isset($remote_state['result']) && is_array($remote_state['result']) ? $remote_state['result'] : array(),
            ));
            if (function_exists('seo_process_supervisor_log')) {
                seo_process_supervisor_log('error', 'clone_slice_failed', $result->get_error_message(), 'Clonador para Academia');
            }
            return false;
        }

        $remote_status = sanitize_key((string) ($result['status'] ?? 'running'));
        $changes = array(
            'status' => in_array($remote_status, array('completed','failed'), true) ? $remote_status : 'running',
            'phase' => sanitize_key((string) ($result['phase'] ?? 'running')),
            'message' => sanitize_text_field((string) ($result['message'] ?? 'Clonando por lotes.')),
            'heartbeat_at' => time(),
            'last_error' => sanitize_text_field((string) ($result['last_error'] ?? '')),
            'stats' => isset($result['stats']) && is_array($result['stats']) ? $result['stats'] : array(),
            'warnings' => isset($result['warnings']) && is_array($result['warnings']) ? $result['warnings'] : array(),
            'progress' => isset($result['progress']) && is_array($result['progress']) ? $result['progress'] : array(),
            'result' => isset($result['result']) && is_array($result['result']) ? $result['result'] : array(),
        );
        if ('completed' === $remote_status || 'failed' === $remote_status) $changes['completed_at'] = time();
        self::save($changes);

        if ('running' === $changes['status'] && function_exists('seo_process_supervisor_nudge')) {
            seo_process_supervisor_nudge(2, 'clonador');
        }
        return true;
    }
}
