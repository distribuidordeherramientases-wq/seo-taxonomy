<?php
/**
 * Clonador para Academia integrado con el Gestor de procesos nativo.
 *
 * El administrador inicia y detiene expresamente la clonacion. El Gestor de
 * procesos nunca crea ni arranca trabajos por su cuenta: solo entrega ventanas
 * cortas a un job que el usuario haya dejado en estado RUNNING.
 *
 * @package SEOSystem
 * @subpackage Processes_Clonador
 * @since 2.5.10
 */

defined('ABSPATH') || exit;

final class SEO_Clonador_Process {
    const OPTION = 'seo_clonador_process_job';

    public static function init() {
        add_filter('seo_process_supervisor_has_pending_work', array(__CLASS__, 'filter_pending_work'));
        add_filter('seo_process_supervisor_manager_targets', array(__CLASS__, 'filter_manager_targets'), 20, 3);
        // Sin ejecutar trabajo: sincroniza el estado visible y deja jobs heredados
        // como PARADOS si no tienen la autorizacion manual run_requested.
        self::sync_supervisor();
    }

    private static function fresh_option($name, $default = array()) {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete((string) $name, 'options');
        }
        $value = get_option((string) $name, $default);
        return is_array($value) ? $value : $default;
    }

    public static function speed_profiles() {
        return array(
            1 => array('label' => 'Muy suave', 'budget' => 6,  'steps' => 6,   'delay' => 8),
            2 => array('label' => 'Suave',      'budget' => 10, 'steps' => 20,  'delay' => 3),
            3 => array('label' => 'Normal',     'budget' => 18, 'steps' => 60,  'delay' => 1),
            4 => array('label' => 'Rapida',     'budget' => 30, 'steps' => 140, 'delay' => 0),
            5 => array('label' => 'Maxima',     'budget' => 45, 'steps' => 250, 'delay' => 0),
        );
    }

    private static function normalize_speed($speed) {
        return max(1, min(5, absint($speed ?: 3)));
    }

    public static function state() {
        $raw = self::fresh_option(self::OPTION, array());
        $state = wp_parse_args($raw, array(
            'job_id' => '',
            'status' => 'idle',
            'run_requested' => 0,
            'phase' => '',
            'message' => '',
            'created_at' => 0,
            'started_at' => 0,
            'heartbeat_at' => 0,
            'completed_at' => 0,
            'requested_by' => 0,
            'stopped_by' => 0,
            'stopped_at' => 0,
            'backend' => 'process_manager',
            'last_error' => '',
            'speed' => 3,
            'next_eligible_at' => 0,
            'preview' => array(),
            'stats' => array(),
            'warnings' => array(),
            'progress' => array(),
            'result' => array(),
        ));
        $state['speed'] = self::normalize_speed($state['speed']);

        /*
         * Compatibilidad con 2.5.4/2.5.5: esos jobs no tenian el interruptor
         * manual run_requested. Un job heredado no puede auto-reanudarse al
         * cargar una pagina; queda parado hasta una accion explicita del usuario.
         */
        if (!array_key_exists('run_requested', $raw) && in_array((string) ($state['status'] ?? ''), array('queued', 'running'), true)) {
            $state['run_requested'] = 0;
            $state['status'] = 'paused';
            $state['message'] = 'Job heredado detenido por seguridad. Pulsa ARRANCAR/REANUDAR para continuar o simula de nuevo para reiniciar desde cero.';
        }
        return $state;
    }

    public static function save($changes) {
        $state = self::state();
        foreach ((array) $changes as $key => $value) {
            $state[$key] = $value;
        }
        $state['speed'] = self::normalize_speed($state['speed'] ?? 3);
        update_option(self::OPTION, $state, false);
        self::sync_supervisor($state);
        return $state;
    }

    private static function supervisor_enabled() {
        if (!function_exists('seo_process_supervisor_settings')) return true;
        $settings = (array) seo_process_supervisor_settings();
        return !empty($settings['enabled']) && !empty($settings['clonador']);
    }

    private static function sync_supervisor($state = null) {
        if (!function_exists('seo_process_supervisor_managed_update')) return;
        $state = is_array($state) ? $state : self::state();
        $status = sanitize_key((string) ($state['status'] ?? 'idle'));
        $running = !empty($state['run_requested']) && 'running' === $status;
        $healthy = 'failed' !== $status;
        $speed = self::normalize_speed($state['speed'] ?? 3);
        $profiles = self::speed_profiles();
        $profile = $profiles[$speed];
        $progress = isset($state['progress']) && is_array($state['progress']) ? $state['progress'] : array();
        $pct = max(0, min(100, (int) ($progress['percent'] ?? 0)));
        $detail = (string) ($state['message'] ?? '');
        if ('' === $detail) {
            $detail = $running ? 'Clonacion iniciada por el usuario y gestionada por lotes.' : ('completed' === $status ? 'Clonacion completada.' : 'Sin clonacion activa.');
        }
        if ($running) {
            $detail .= ' · ' . $pct . '% · velocidad ' . $speed . '/5 (' . $profile['label'] . ').';
        } elseif ('paused' === $status) {
            $detail .= ' · PARADA por el usuario · velocidad ' . $speed . '/5.';
        }
        seo_process_supervisor_managed_update('clonador-academia', array(
            'name' => 'Clonador para Academia',
            'pending' => $running ? 1 : 0,
            'healthy' => $healthy ? 1 : 0,
            'last_checked' => time(),
            'last_result' => $status,
            'last_error' => sanitize_text_field((string) ($state['last_error'] ?? '')),
            'detail' => sanitize_text_field($detail),
            'speed' => $speed,
            'speed_label' => $profile['label'],
            'progress_percent' => $pct,
            'phase' => sanitize_key((string) ($state['phase'] ?? '')),
        ));
    }

    public static function public_state() {
        $state = self::state();
        $speed = self::normalize_speed($state['speed']);
        $profiles = self::speed_profiles();
        return array(
            'job_id' => sanitize_key((string) $state['job_id']),
            'status' => sanitize_key((string) $state['status']),
            'run_requested' => !empty($state['run_requested']),
            'phase' => sanitize_key((string) $state['phase']),
            'message' => sanitize_text_field((string) $state['message']),
            'created_at' => absint($state['created_at']),
            'started_at' => absint($state['started_at']),
            'heartbeat_at' => absint($state['heartbeat_at']),
            'completed_at' => absint($state['completed_at']),
            'stopped_at' => absint($state['stopped_at']),
            'backend' => 'process_manager',
            'last_error' => sanitize_text_field((string) $state['last_error']),
            'speed' => $speed,
            'speed_label' => (string) $profiles[$speed]['label'],
            'next_eligible_at' => absint($state['next_eligible_at']),
            'stats' => is_array($state['stats']) ? $state['stats'] : array(),
            'warnings' => is_array($state['warnings']) ? $state['warnings'] : array(),
            'progress' => is_array($state['progress']) ? $state['progress'] : array(),
            'result' => is_array($state['result']) ? $state['result'] : array(),
        );
    }

    public static function is_running() {
        $state = self::state();
        return !empty($state['run_requested']) && 'running' === sanitize_key((string) ($state['status'] ?? 'idle'));
    }

    /**
     * Accion EXPLICITA del usuario: crea un job nuevo desde la ultima simulacion
     * y lo deja ya marcado como iniciado por el administrador. El worker solo
     * podra gestionar ventanas de este job; nunca llega aqui por si mismo.
     */
    public static function start_new($preview, $speed = 3) {
        if (!class_exists('SEO_Clonador_Engine') || !is_callable(array('SEO_Clonador_Engine', 'initialize_manager_job'))) {
            return new WP_Error('clonador_process_engine', 'No esta cargado el motor por lotes del Clonador.');
        }
        if (!self::supervisor_enabled()) {
            return new WP_Error('clonador_supervisor_disabled', 'El Gestor de procesos o la gestion del Clonador esta desactivada. Activalos en Procesos > Gestor de workers.');
        }
        $current = self::state();
        if (self::is_running()) {
            return new WP_Error('clonador_process_busy', 'Ya hay una clonacion iniciada. Pulsa PARAR antes de iniciar otra.');
        }
        $preview = is_array($preview) ? $preview : array();
        if (empty($preview['source_marker']) || empty($preview['target_marker'])) {
            return new WP_Error('clonador_process_preview', 'El plan previo no contiene las huellas necesarias.');
        }
        $speed = self::normalize_speed($speed);
        $job_id = sanitize_key('clone-' . strtolower(wp_generate_password(18, false, false)));
        $remote = SEO_Clonador_Engine::initialize_manager_job($job_id, $preview);
        if (is_wp_error($remote)) return $remote;

        self::save(array(
            'job_id' => $job_id,
            'status' => 'running',
            'run_requested' => 1,
            'phase' => 'preflight',
            'message' => 'Clonacion iniciada manualmente. El worker solo gestionara lotes y fases hasta que la pares o termine.',
            'created_at' => time(),
            'started_at' => time(),
            'heartbeat_at' => time(),
            'completed_at' => 0,
            'requested_by' => get_current_user_id(),
            'stopped_by' => 0,
            'stopped_at' => 0,
            'backend' => 'process_manager',
            'last_error' => '',
            'speed' => $speed,
            'next_eligible_at' => 0,
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
        if (function_exists('seo_process_supervisor_log')) {
            seo_process_supervisor_log('success', 'clone_user_started', 'El administrador inicio manualmente la clonacion. El worker solo gestionara sus lotes.', 'Clonador para Academia', array('speed' => $speed));
        }
        return self::public_state();
    }

    public static function stop() {
        $state = self::state();
        if (!in_array((string) ($state['status'] ?? ''), array('running', 'queued', 'paused'), true)) {
            return self::public_state();
        }
        self::save(array(
            'status' => 'paused',
            'run_requested' => 0,
            'stopped_by' => get_current_user_id(),
            'stopped_at' => time(),
            'next_eligible_at' => 0,
            'message' => 'Clonacion PARADA por el usuario. El worker no ejecutara mas lotes hasta una reanudacion explicita.',
        ));
        if (function_exists('seo_process_supervisor_log')) {
            seo_process_supervisor_log('warning', 'clone_user_stopped', 'El administrador paro manualmente la clonacion. No se ejecutaran mas lotes.', 'Clonador para Academia');
        }
        return self::public_state();
    }

    public static function resume() {
        $state = self::state();
        if ('paused' !== (string) ($state['status'] ?? '') || empty($state['job_id'])) {
            return new WP_Error('clonador_resume_state', 'No existe una clonacion parada que pueda reanudarse. Simula y arranca una nueva.');
        }
        if (!self::supervisor_enabled()) {
            return new WP_Error('clonador_supervisor_disabled', 'El Gestor de procesos o la gestion del Clonador esta desactivada.');
        }
        self::save(array(
            'status' => 'running',
            'run_requested' => 1,
            'stopped_by' => 0,
            'stopped_at' => 0,
            'heartbeat_at' => time(),
            'next_eligible_at' => 0,
            'message' => 'Clonacion reanudada manualmente. El worker continuara desde el ultimo lote confirmado.',
        ));
        if (function_exists('seo_process_supervisor_nudge')) seo_process_supervisor_nudge(0, 'clonador');
        if (function_exists('seo_process_supervisor_schedule_backup')) seo_process_supervisor_schedule_backup();
        if (function_exists('seo_process_supervisor_log')) {
            seo_process_supervisor_log('success', 'clone_user_resumed', 'El administrador reanudo manualmente la clonacion.', 'Clonador para Academia');
        }
        return self::public_state();
    }

    /**
     * Accion EXPLICITA del usuario. Solo reejecuta los controles finales de
     * un job fallido en verify; no crea un job nuevo ni vuelve a copiar datos.
     */
    public static function reverify() {
        $state = self::state();
        if ('failed' !== (string) ($state['status'] ?? '') || 'verify' !== (string) ($state['phase'] ?? '') || empty($state['job_id'])) {
            return new WP_Error('clonador_reverify_state', 'No existe una clonacion fallida en verificacion que pueda reverificarse.');
        }
        if (!class_exists('SEO_Clonador_Engine') || !is_callable(array('SEO_Clonador_Engine', 'reverify_manager_job'))) {
            return new WP_Error('clonador_reverify_engine', 'No esta disponible el motor de reverificacion.');
        }

        $remote = SEO_Clonador_Engine::reverify_manager_job((string) $state['job_id']);
        if (is_wp_error($remote)) {
            $data = $remote->get_error_data();
            $remote_state = (is_array($data) && isset($data['state']) && is_array($data['state'])) ? $data['state'] : array();
            if ($remote_state) {
                self::save(array(
                    'status' => 'failed',
                    'run_requested' => 0,
                    'phase' => sanitize_key((string) ($remote_state['phase'] ?? 'verify')),
                    'message' => sanitize_text_field((string) ($remote_state['message'] ?? 'Reverificacion fallida. No se ha repetido la copia.')),
                    'completed_at' => time(),
                    'last_error' => $remote->get_error_message(),
                    'stats' => isset($remote_state['stats']) && is_array($remote_state['stats']) ? $remote_state['stats'] : array(),
                    'warnings' => isset($remote_state['warnings']) && is_array($remote_state['warnings']) ? $remote_state['warnings'] : array(),
                    'progress' => isset($remote_state['progress']) && is_array($remote_state['progress']) ? $remote_state['progress'] : array(),
                    'result' => isset($remote_state['result']) && is_array($remote_state['result']) ? $remote_state['result'] : array(),
                ));
            }
            return $remote;
        }

        self::save(array(
            'status' => sanitize_key((string) ($remote['status'] ?? 'completed')),
            'run_requested' => 0,
            'phase' => sanitize_key((string) ($remote['phase'] ?? 'completed')),
            'message' => sanitize_text_field((string) ($remote['message'] ?? 'Clonacion reverificada.')),
            'heartbeat_at' => time(),
            'completed_at' => absint($remote['completed_at'] ?? time()),
            'last_error' => '',
            'stats' => isset($remote['stats']) && is_array($remote['stats']) ? $remote['stats'] : array(),
            'warnings' => isset($remote['warnings']) && is_array($remote['warnings']) ? $remote['warnings'] : array(),
            'progress' => isset($remote['progress']) && is_array($remote['progress']) ? $remote['progress'] : array(),
            'result' => isset($remote['result']) && is_array($remote['result']) ? $remote['result'] : array(),
        ));
        if (function_exists('seo_process_supervisor_log')) {
            seo_process_supervisor_log('success', 'clone_user_reverified', 'El administrador reverifico la copia existente sin repetir la clonacion.', 'Clonador para Academia');
        }
        return self::public_state();
    }

    public static function set_speed($speed) {
        $speed = self::normalize_speed($speed);
        $state = self::save(array(
            'speed' => $speed,
            'next_eligible_at' => 0,
        ));
        if (!empty($state['run_requested']) && function_exists('seo_process_supervisor_nudge')) {
            seo_process_supervisor_nudge(0, 'clonador_speed');
        }
        return self::public_state();
    }

    public static function filter_pending_work($pending) {
        if (!self::supervisor_enabled()) return $pending;
        return $pending || self::is_running();
    }

    public static function filter_manager_targets($targets, $settings, $source) {
        unset($source);
        if (empty($settings['clonador'])) {
            self::sync_supervisor();
            return $targets;
        }
        $state = self::state();
        if (self::is_running()) {
            $due = absint($state['next_eligible_at'] ?? 0);
            if (!$due || $due <= time()) {
                $targets[] = array(
                    'type' => 'clonador',
                    'data' => array('job_id' => sanitize_key((string) ($state['job_id'] ?? ''))),
                    'callback' => array(__CLASS__, 'manager_callback'),
                );
            } else {
                $remaining = max(1, $due - time());
                self::save(array('message' => 'Clonacion iniciada por el usuario. Esperando ' . $remaining . ' s antes del siguiente lote segun la velocidad elegida.'));
            }
        }
        self::sync_supervisor();
        return $targets;
    }

    public static function manager_callback($budget, $source, $target) {
        unset($target);
        $state = self::state();
        if (empty($state['run_requested']) || 'running' !== (string) ($state['status'] ?? '')) return true;
        if (!class_exists('SEO_Clonador_Engine') || !is_callable(array('SEO_Clonador_Engine', 'process_manager_slice'))) {
            self::save(array('status'=>'failed','run_requested'=>0,'phase'=>'bootstrap','last_error'=>'No esta disponible SEO_Clonador_Engine::process_manager_slice.','message'=>'Motor por lotes no disponible.','completed_at'=>time()));
            return false;
        }

        $profiles = self::speed_profiles();
        $speed = self::normalize_speed($state['speed'] ?? 3);
        $profile = $profiles[$speed];
        $effective_budget = max(5, min(absint($budget), absint($profile['budget'])));
        $max_steps = max(1, absint($profile['steps']));
        $job_id = sanitize_key((string) ($state['job_id'] ?? ''));

        self::save(array(
            'heartbeat_at' => time(),
            'message' => 'Worker gestionando un lote de la clonacion iniciada por el usuario · velocidad ' . $speed . '/5 (' . $profile['label'] . ').',
        ));

        $result = SEO_Clonador_Engine::process_manager_slice($job_id, $effective_budget, sanitize_key((string) $source), $max_steps);
        if (is_wp_error($result)) {
            $error_data = $result->get_error_data();
            $remote_state = (is_array($error_data) && isset($error_data['state']) && is_array($error_data['state'])) ? $error_data['state'] : array();
            self::save(array(
                'status' => 'failed',
                'run_requested' => 0,
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
        $control = self::state();
        $user_stopped = empty($control['run_requested']) || 'paused' === (string) ($control['status'] ?? '');
        $changes = array(
            'status' => in_array($remote_status, array('completed','failed'), true) ? $remote_status : ($user_stopped ? 'paused' : 'running'),
            'run_requested' => (!$user_stopped && 'running' === $remote_status) ? 1 : 0,
            'phase' => sanitize_key((string) ($result['phase'] ?? 'running')),
            'message' => $user_stopped
                ? 'Clonacion PARADA por el usuario al terminar el lote actual. El worker no ejecutara otro lote.'
                : sanitize_text_field((string) ($result['message'] ?? 'Clonando por lotes.')),
            'heartbeat_at' => time(),
            'last_error' => sanitize_text_field((string) ($result['last_error'] ?? '')),
            'stats' => isset($result['stats']) && is_array($result['stats']) ? $result['stats'] : array(),
            'warnings' => isset($result['warnings']) && is_array($result['warnings']) ? $result['warnings'] : array(),
            'progress' => isset($result['progress']) && is_array($result['progress']) ? $result['progress'] : array(),
            'result' => isset($result['result']) && is_array($result['result']) ? $result['result'] : array(),
            'next_eligible_at' => (!$user_stopped && 'running' === $remote_status) ? time() + absint($profile['delay']) : 0,
        );
        if ('completed' === $remote_status || 'failed' === $remote_status) {
            $changes['completed_at'] = time();
            $changes['run_requested'] = 0;
        }
        self::save($changes);

        if ('running' === $changes['status'] && !empty($changes['run_requested']) && function_exists('seo_process_supervisor_nudge')) {
            seo_process_supervisor_nudge(absint($profile['delay']), 'clonador');
        }
        return true;
    }
}
