<?php
/**
 * Proceso desacoplado del Clonador para Academia.
 *
 * Ejecuta la clonacion destructiva fuera de la peticion del navegador. Prioriza
 * PHP CLI y usa loopback HTTP firmado como fallback. El Gestor de procesos lo
 * vigila y puede volver a despachar jobs que se quedaron solo en cola.
 *
 * @package SEOSystem
 * @subpackage Processes_Clonador
 * @since 2.5.3
 */

defined('ABSPATH') || exit;

final class SEO_Clonador_Process {
    const OPTION = 'seo_clonador_process_job';
    const DISPATCH_STALE = 120;

    public static function init() {
        add_action('admin_post_seo_clonador_direct_worker', array(__CLASS__, 'direct_http_worker'));
        add_action('admin_post_nopriv_seo_clonador_direct_worker', array(__CLASS__, 'direct_http_worker'));
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
            'backend' => '',
            'pid' => 0,
            'dispatch_pending' => 0,
            'dispatch_id' => '',
            'dispatch_at' => 0,
            'last_error' => '',
            'preview' => array(),
            'stats' => array(),
            'result' => array(),
        ));
    }

    private static function save($changes) {
        $state = self::state();
        foreach ((array) $changes as $key => $value) {
            $state[$key] = $value;
        }
        update_option(self::OPTION, $state, false);
        self::sync_supervisor($state);
        return $state;
    }

    private static function sync_supervisor($state = null) {
        if (!function_exists('seo_process_supervisor_managed_update')) {
            return;
        }
        $state = is_array($state) ? $state : self::state();
        $status = sanitize_key((string) ($state['status'] ?? 'idle'));
        $pending = in_array($status, array('queued', 'dispatching', 'running'), true);
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
            'backend' => sanitize_key((string) $state['backend']),
            'pid' => absint($state['pid']),
            'last_error' => sanitize_text_field((string) $state['last_error']),
            'stats' => is_array($state['stats']) ? $state['stats'] : array(),
            'result' => is_array($state['result']) ? $state['result'] : array(),
        );
    }

    public static function is_active() {
        $status = sanitize_key((string) (self::state()['status'] ?? 'idle'));
        return in_array($status, array('queued', 'dispatching', 'running'), true);
    }

    public static function queue($preview) {
        if (!class_exists('SEO_Clonador_Engine')) {
            return new WP_Error('clonador_process_engine', 'No esta cargado el motor del Clonador.');
        }
        $current = self::state();
        if (in_array((string) ($current['status'] ?? ''), array('queued', 'dispatching', 'running'), true)) {
            return new WP_Error('clonador_process_busy', 'Ya hay una clonacion entregada al worker o en ejecucion.');
        }
        $preview = is_array($preview) ? $preview : array();
        if (empty($preview['source_marker']) || empty($preview['target_marker'])) {
            return new WP_Error('clonador_process_preview', 'El plan previo no contiene las huellas necesarias para ejecutar en background.');
        }
        $job_id = sanitize_key('clone-' . strtolower(wp_generate_password(18, false, false)));
        self::save(array(
            'job_id' => $job_id,
            'status' => 'queued',
            'phase' => 'queued',
            'message' => 'Clonacion en cola. Preparando worker independiente del navegador.',
            'created_at' => time(),
            'started_at' => 0,
            'heartbeat_at' => time(),
            'completed_at' => 0,
            'requested_by' => get_current_user_id(),
            'backend' => '',
            'pid' => 0,
            'dispatch_pending' => 0,
            'dispatch_id' => '',
            'dispatch_at' => 0,
            'last_error' => '',
            'preview' => array(
                'source_marker' => (string) $preview['source_marker'],
                'target_marker' => (string) $preview['target_marker'],
                'identity' => isset($preview['identity']) && is_array($preview['identity']) ? $preview['identity'] : array(),
                'previewed_at' => absint($preview['previewed_at'] ?? 0),
            ),
            'stats' => array(),
            'result' => array(),
        ));

        $spawn = self::dispatch();
        if (is_wp_error($spawn)) {
            self::save(array(
                'status' => 'failed',
                'phase' => 'dispatch',
                'message' => 'No se pudo arrancar el worker del Clonador.',
                'last_error' => $spawn->get_error_message(),
                'completed_at' => time(),
            ));
            return $spawn;
        }

        if (function_exists('seo_process_supervisor_nudge')) {
            seo_process_supervisor_nudge(0, 'clonador');
        }
        if (function_exists('seo_process_supervisor_schedule_backup')) {
            seo_process_supervisor_schedule_backup();
        }
        return self::public_state();
    }

    private static function signature($dispatch_id, $dispatch_at, $job_id) {
        return hash_hmac(
            'sha256',
            sanitize_key((string) $dispatch_id) . '|' . absint($dispatch_at) . '|' . sanitize_key((string) $job_id) . '|clonador-academia',
            wp_salt('auth')
        );
    }

    private static function request_valid($dispatch_id, $dispatch_at, $job_id, $signature) {
        $state = self::state();
        $dispatch_id = sanitize_key((string) $dispatch_id);
        $dispatch_at = absint($dispatch_at);
        $job_id = sanitize_key((string) $job_id);
        $signature = strtolower(preg_replace('/[^a-f0-9]/i', '', (string) $signature));
        if (
            empty($state['dispatch_pending'])
            || sanitize_key((string) $state['dispatch_id']) !== $dispatch_id
            || absint($state['dispatch_at']) !== $dispatch_at
            || sanitize_key((string) $state['job_id']) !== $job_id
            || !in_array((string) $state['status'], array('queued', 'dispatching'), true)
        ) {
            return false;
        }
        return hash_equals(self::signature($dispatch_id, $dispatch_at, $job_id), $signature);
    }

    private static function claim($dispatch_id, $dispatch_at, $job_id, $signature, $backend) {
        if (!self::request_valid($dispatch_id, $dispatch_at, $job_id, $signature)) {
            return false;
        }
        self::save(array(
            'status' => 'running',
            'phase' => 'starting',
            'message' => 'Worker iniciado. Prevalidando PRO y STAGING antes de escribir.',
            'started_at' => time(),
            'heartbeat_at' => time(),
            'backend' => sanitize_key((string) $backend),
            'pid' => function_exists('getmypid') ? absint(getmypid()) : 0,
            'dispatch_pending' => 0,
            'dispatch_id' => '',
            'last_error' => '',
        ));
        return true;
    }

    private static function php_cli() {
        if (function_exists('seo_process_supervisor_find_php_cli')) {
            $php = (string) seo_process_supervisor_find_php_cli();
            if ('' !== $php) {
                return $php;
            }
        }
        if (!function_exists('exec') || '/' !== DIRECTORY_SEPARATOR) {
            return '';
        }
        $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
        if (in_array('exec', $disabled, true)) {
            return '';
        }
        $candidates = array();
        if (defined('PHP_BINARY') && PHP_BINARY) $candidates[] = PHP_BINARY;
        $candidates[] = '/usr/local/bin/php';
        $candidates[] = '/usr/bin/php';
        foreach (array_unique(array_filter($candidates)) as $candidate) {
            if (@is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    private static function spawn_cli($dispatch_id, $dispatch_at, $job_id, $signature) {
        $php = self::php_cli();
        $worker = __DIR__ . '/worker.php';
        $wp_load = trailingslashit(ABSPATH) . 'wp-load.php';
        if ('' === $php || !is_readable($worker) || !is_readable($wp_load)) {
            return new WP_Error('clonador_worker_cli_missing', 'PHP CLI no esta disponible para el Clonador.');
        }
        $inner = 'exec '
            . escapeshellarg($php) . ' '
            . escapeshellarg($worker) . ' '
            . escapeshellarg($wp_load) . ' '
            . escapeshellarg(sanitize_key((string) $dispatch_id)) . ' '
            . escapeshellarg((string) absint($dispatch_at)) . ' '
            . escapeshellarg(sanitize_key((string) $job_id)) . ' '
            . escapeshellarg((string) $signature);
        $command = 'sh -c ' . escapeshellarg($inner) . ' > /dev/null 2>&1 & echo $!';
        $output = array();
        $code = 1;
        @exec($command, $output, $code);
        $pid = !empty($output[0]) ? absint(trim((string) $output[0])) : 0;
        if (0 !== $code || !$pid) {
            return new WP_Error('clonador_worker_cli_spawn', 'No se pudo desacoplar el worker PHP CLI del Clonador.');
        }
        return array('backend' => 'direct_cli', 'pid' => $pid);
    }

    private static function spawn_http($dispatch_id, $dispatch_at, $job_id, $signature) {
        $response = wp_remote_post(admin_url('admin-post.php'), array(
            'timeout' => 6,
            'redirection' => 0,
            'blocking' => true,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'headers' => array('Connection' => 'close', 'X-SEO-Direct-Worker' => 'clonador'),
            'body' => array(
                'action' => 'seo_clonador_direct_worker',
                'dispatch_id' => sanitize_key((string) $dispatch_id),
                'dispatch_at' => absint($dispatch_at),
                'job_id' => sanitize_key((string) $job_id),
                'signature' => (string) $signature,
            ),
        ));
        if (is_wp_error($response)) return $response;
        $code = absint(wp_remote_retrieve_response_code($response));
        $body = trim((string) wp_remote_retrieve_body($response));
        if (202 !== $code || 'accepted' !== $body) {
            return new WP_Error('clonador_worker_http', sprintf('El loopback del Clonador no fue aceptado (HTTP %d%s).', $code, '' !== $body ? ': ' . sanitize_text_field(substr($body, 0, 160)) : ''));
        }
        return array('backend' => 'direct_http', 'pid' => 0);
    }

    public static function dispatch() {
        $state = self::state();
        if (!in_array((string) $state['status'], array('queued', 'dispatching'), true)) {
            return new WP_Error('clonador_worker_state', 'El Clonador no tiene un job en cola para despachar.');
        }
        $dispatch_id = sanitize_key(strtolower(wp_generate_password(24, false, false)));
        $dispatch_at = time();
        $job_id = sanitize_key((string) $state['job_id']);
        $signature = self::signature($dispatch_id, $dispatch_at, $job_id);
        self::save(array(
            'status' => 'dispatching',
            'phase' => 'dispatch',
            'message' => 'Entregando la clonacion a un worker independiente del navegador.',
            'heartbeat_at' => time(),
            'dispatch_pending' => 1,
            'dispatch_id' => $dispatch_id,
            'dispatch_at' => $dispatch_at,
            'last_error' => '',
        ));

        $spawn = self::spawn_cli($dispatch_id, $dispatch_at, $job_id, $signature);
        if (is_wp_error($spawn)) {
            $cli_error = $spawn->get_error_message();
            $spawn = self::spawn_http($dispatch_id, $dispatch_at, $job_id, $signature);
            if (is_wp_error($spawn)) {
                self::save(array('dispatch_pending' => 0, 'last_error' => sanitize_text_field($cli_error . ' ' . $spawn->get_error_message())));
                return new WP_Error('clonador_worker_unavailable', $cli_error . ' ' . $spawn->get_error_message());
            }
        }
        self::save(array(
            'backend' => sanitize_key((string) ($spawn['backend'] ?? '')),
            'pid' => absint($spawn['pid'] ?? 0),
            'message' => 'Worker arrancado. Puedes salir de esta pantalla; la clonacion continuara en segundo plano.',
        ));
        return $spawn;
    }

    public static function direct_cli_run($dispatch_id, $dispatch_at, $job_id, $signature) {
        if (!self::claim($dispatch_id, $dispatch_at, $job_id, $signature, 'direct_cli')) {
            return;
        }
        self::run_claimed();
    }

    public static function direct_http_worker() {
        $dispatch_id = sanitize_key(wp_unslash($_POST['dispatch_id'] ?? ''));
        $dispatch_at = absint($_POST['dispatch_at'] ?? 0);
        $job_id = sanitize_key(wp_unslash($_POST['job_id'] ?? ''));
        $signature = sanitize_text_field(wp_unslash($_POST['signature'] ?? ''));
        if (!self::request_valid($dispatch_id, $dispatch_at, $job_id, $signature)) {
            status_header(403);
            echo 'forbidden';
            exit;
        }
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) @set_time_limit(0);
        status_header(202);
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        echo 'accepted';
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            @ob_end_flush();
            @flush();
        }
        if (!self::claim($dispatch_id, $dispatch_at, $job_id, $signature, 'direct_http')) {
            exit;
        }
        self::run_claimed();
        exit;
    }

    private static function run_claimed() {
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) @set_time_limit(0);
        if (!class_exists('SEO_Clonador_Engine') || !is_callable(array('SEO_Clonador_Engine', 'run_background_clone'))) {
            self::save(array('status' => 'failed', 'phase' => 'bootstrap', 'message' => 'Motor del Clonador no disponible.', 'last_error' => 'SEO_Clonador_Engine::run_background_clone no esta disponible.', 'completed_at' => time()));
            return;
        }
        $state = self::state();
        $preview = is_array($state['preview']) ? $state['preview'] : array();
        if (function_exists('seo_process_supervisor_log')) {
            seo_process_supervisor_log('info', 'clone_worker_started', 'Clonacion PRO → STAGING iniciada en worker desacoplado.', 'Clonador para Academia', array('backend' => $state['backend']));
        }
        try {
            $result = SEO_Clonador_Engine::run_background_clone($preview, array(__CLASS__, 'progress'));
            if (is_wp_error($result)) {
                throw new RuntimeException($result->get_error_message());
            }
            self::save(array(
                'status' => 'completed',
                'phase' => 'completed',
                'message' => 'Clonacion PRO → STAGING terminada y verificada.',
                'heartbeat_at' => time(),
                'completed_at' => time(),
                'last_error' => '',
                'stats' => isset($result['stats']) && is_array($result['stats']) ? $result['stats'] : array(),
                'result' => array(
                    'generation' => (string) ($result['generation'] ?? ''),
                    'duration_seconds' => (float) ($result['duration_seconds'] ?? 0),
                    'completed_at' => absint($result['completed_at'] ?? time()),
                ),
            ));
            if (function_exists('seo_process_supervisor_log')) {
                seo_process_supervisor_log('success', 'clone_worker_completed', 'Clonacion PRO → STAGING completada.', 'Clonador para Academia', array('seconds' => (string) ($result['duration_seconds'] ?? '0')));
            }
        } catch (Throwable $e) {
            self::save(array(
                'status' => 'failed',
                'phase' => 'failed',
                'message' => 'Clonacion fallida/revertida. STAGING no ha quedado a medias.',
                'heartbeat_at' => time(),
                'completed_at' => time(),
                'last_error' => sanitize_text_field($e->getMessage()),
            ));
            if (function_exists('seo_process_supervisor_log')) {
                seo_process_supervisor_log('error', 'clone_worker_failed', $e->getMessage(), 'Clonador para Academia');
            }
        }
    }

    public static function progress($phase, $message = '', $extra = array()) {
        $changes = array(
            'status' => 'running',
            'phase' => sanitize_key((string) $phase),
            'message' => sanitize_text_field((string) $message),
            'heartbeat_at' => time(),
        );
        if (isset($extra['stats']) && is_array($extra['stats'])) {
            $changes['stats'] = $extra['stats'];
        }
        self::save($changes);
    }

    public static function filter_pending_work($pending) {
        return $pending || self::is_active();
    }

    public static function filter_manager_targets($targets, $settings, $source) {
        unset($settings, $source);
        $state = self::state();
        $status = sanitize_key((string) $state['status']);
        $dispatch_at = absint($state['dispatch_at']);
        $dispatch_stale = 'dispatching' === $status && $dispatch_at && (time() - $dispatch_at) > self::DISPATCH_STALE;
        if ('queued' === $status || $dispatch_stale) {
            $targets[] = array(
                'type' => 'clonador',
                'data' => array(),
                'callback' => array(__CLASS__, 'manager_callback'),
            );
        }
        self::sync_supervisor($state);
        return $targets;
    }

    public static function manager_callback($budget, $source, $target) {
        unset($budget, $source, $target);
        $state = self::state();
        if ('dispatching' === (string) $state['status'] && absint($state['dispatch_at']) && (time() - absint($state['dispatch_at'])) <= self::DISPATCH_STALE) {
            return true;
        }
        if ('dispatching' === (string) $state['status']) {
            self::save(array('status' => 'queued', 'dispatch_pending' => 0, 'dispatch_id' => '', 'message' => 'Despacho anterior no confirmado; el Gestor de procesos lo reintentara.'));
        }
        if ('queued' !== (string) self::state()['status']) {
            return true;
        }
        $result = self::dispatch();
        if (is_wp_error($result)) {
            self::save(array('status' => 'failed', 'phase' => 'dispatch', 'message' => 'No se pudo arrancar el worker.', 'last_error' => $result->get_error_message(), 'completed_at' => time()));
            return false;
        }
        return true;
    }
}
