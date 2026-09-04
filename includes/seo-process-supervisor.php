<?php
/**
 * SEO Taxonomy - Supervisor propio de procesos.
 *
 * Gestor periódico de procesos propios. Ejecuta ventanas limitadas de
 * Import/Export y Academia directamente, sin Action Scheduler ni el worker de
 * WooCommerce. Puede ser invocado por cron real del servidor; WP-Cron propio
 * queda como respaldo.
 *
 * @package SEOSystem
 * @subpackage Processes
 * @since 2.3.3
 */

defined('ABSPATH') || exit;

if (!defined('SEO_PROCESS_SUPERVISOR_OPTION')) {
    define('SEO_PROCESS_SUPERVISOR_OPTION', 'seo_process_supervisor_settings');
}
if (!defined('SEO_PROCESS_SUPERVISOR_STATE_OPTION')) {
    define('SEO_PROCESS_SUPERVISOR_STATE_OPTION', 'seo_process_supervisor_state');
}
if (!defined('SEO_PROCESS_SUPERVISOR_LOG_OPTION')) {
    define('SEO_PROCESS_SUPERVISOR_LOG_OPTION', 'seo_process_supervisor_log');
}
if (!defined('SEO_PROCESS_SUPERVISOR_LOCK_OPTION')) {
    define('SEO_PROCESS_SUPERVISOR_LOCK_OPTION', 'seo_process_supervisor_lock');
}

if (!function_exists('seo_process_supervisor_defaults')) {
    function seo_process_supervisor_defaults() {
        return array(
            'enabled'            => 1,
            'interval_seconds'   => 60,
            'runtime_seconds'    => 45,
            'restart_cooldown'   => 20,
            'import_export'      => 1,
            'academy'            => 1,
            'backup_watchdog'    => 1,
            'log_cycles'         => 0,
        );
    }
}

if (!function_exists('seo_process_supervisor_sanitize_settings')) {
    function seo_process_supervisor_sanitize_settings($raw) {
        $raw = wp_parse_args(is_array($raw) ? $raw : array(), seo_process_supervisor_defaults());
        return array(
            'enabled'          => empty($raw['enabled']) ? 0 : 1,
            'interval_seconds' => max(60, min(900, absint($raw['interval_seconds']))),
            'runtime_seconds'  => max(10, min(55, absint($raw['runtime_seconds'] ?? 45))),
            'restart_cooldown' => max(5, min(600, absint($raw['restart_cooldown']))),
            'import_export'    => empty($raw['import_export']) ? 0 : 1,
            'academy'          => empty($raw['academy']) ? 0 : 1,
            'backup_watchdog'  => empty($raw['backup_watchdog']) ? 0 : 1,
            'log_cycles'       => empty($raw['log_cycles']) ? 0 : 1,
        );
    }
}

if (!function_exists('seo_process_supervisor_settings')) {
    function seo_process_supervisor_settings() {
        return seo_process_supervisor_sanitize_settings(get_option(SEO_PROCESS_SUPERVISOR_OPTION, array()));
    }
}

if (!function_exists('seo_process_supervisor_default_state')) {
    function seo_process_supervisor_default_state() {
        return array(
            'active'            => 0,
            'status'            => 'stopped',
            'pid'               => 0,
            'backend'           => '',
            'started_at'        => 0,
            'heartbeat_at'      => 0,
            'last_cycle_at'     => 0,
            'next_cycle_at'     => 0,
            'cycle_count'       => 0,
            'launch_count'      => 0,
            'last_launch_at'    => 0,
            'last_error'        => '',
            'stop_requested'    => 0,
            'restart_requested' => 0,
            'dispatch_pending'  => 0,
            'dispatch_id'       => '',
            'dispatch_at'       => 0,
            'dispatch_backend'  => '',
            'dispatch_pid'      => 0,
            'dispatch_error'    => '',
            'managed'           => array(),
            'last_exit_reason'  => '',
        );
    }
}

if (!function_exists('seo_process_supervisor_state')) {
    function seo_process_supervisor_state() {
        $state = get_option(SEO_PROCESS_SUPERVISOR_STATE_OPTION, array());
        return wp_parse_args(is_array($state) ? $state : array(), seo_process_supervisor_default_state());
    }
}

if (!function_exists('seo_process_supervisor_save_state')) {
    function seo_process_supervisor_save_state($changes) {
        $state = seo_process_supervisor_state();
        foreach ((array) $changes as $key => $value) {
            $state[$key] = $value;
        }
        update_option(SEO_PROCESS_SUPERVISOR_STATE_OPTION, $state, false);
        return $state;
    }
}

if (!function_exists('seo_process_supervisor_log')) {
    function seo_process_supervisor_log($level, $event, $message, $process = 'supervisor', $context = array()) {
        $level   = sanitize_key((string) $level);
        $event   = sanitize_key((string) $event);
        $process = sanitize_text_field((string) $process);
        $message = sanitize_text_field((string) $message);
        $context = is_array($context) ? $context : array();

        // Nunca persistir firmas, tokens ni rutas completas en el log visible.
        foreach (array('signature', 'token', 'dispatch_id', 'path', 'wp_load') as $sensitive) {
            unset($context[$sensitive]);
        }
        $safe_context = array();
        foreach ($context as $key => $value) {
            if (is_scalar($value) || null === $value) {
                $safe_context[sanitize_key((string) $key)] = sanitize_text_field((string) $value);
            }
        }

        $rows = get_option(SEO_PROCESS_SUPERVISOR_LOG_OPTION, array());
        $rows = is_array($rows) ? $rows : array();
        $rows[] = array(
            'time'    => time(),
            'level'   => in_array($level, array('info', 'warning', 'error', 'success', 'debug'), true) ? $level : 'info',
            'event'   => $event,
            'process' => $process,
            'message' => $message,
            'context' => $safe_context,
        );
        if (count($rows) > 500) {
            $rows = array_slice($rows, -500);
        }
        update_option(SEO_PROCESS_SUPERVISOR_LOG_OPTION, $rows, false);
    }
}

if (!function_exists('seo_process_supervisor_logs')) {
    function seo_process_supervisor_logs($limit = 150) {
        $rows = get_option(SEO_PROCESS_SUPERVISOR_LOG_OPTION, array());
        $rows = is_array($rows) ? $rows : array();
        return array_reverse(array_slice($rows, -max(1, min(500, absint($limit)))));
    }
}

if (!function_exists('seo_process_supervisor_pid_alive')) {
    function seo_process_supervisor_pid_alive($pid) {
        $pid = absint($pid);
        if (!$pid) {
            return null;
        }
        if (function_exists('seo_ie_product_import_pid_is_alive')) {
            return seo_ie_product_import_pid_is_alive($pid);
        }

        // No consultar /proc: en hostings con open_basedir esa ruta está
        // prohibida. El supervisor usa el heartbeat como fuente principal de
        // vida; POSIX es únicamente una comprobación adicional si existe.
        if (function_exists('posix_kill')) {
            $alive = @posix_kill($pid, 0);
            if (true === $alive) {
                return true;
            }
            if (function_exists('posix_get_last_error')) {
                $error = absint(@posix_get_last_error());
                if (3 === $error) {
                    return false;
                }
                if (1 === $error) {
                    return true;
                }
            }
            return null;
        }
        return null;
    }
}

if (!function_exists('seo_process_supervisor_stale_seconds')) {
    function seo_process_supervisor_stale_seconds() {
        $settings = seo_process_supervisor_settings();
        return max(45, absint($settings['interval_seconds']) * 4);
    }
}

if (!function_exists('seo_process_supervisor_is_active')) {
    function seo_process_supervisor_is_active($state = null) {
        $state = is_array($state) ? $state : seo_process_supervisor_state();
        $settings = seo_process_supervisor_settings();
        if (empty($settings['enabled'])) {
            return false;
        }
        $heartbeat = absint($state['heartbeat_at'] ?? 0);
        if (!$heartbeat) {
            return false;
        }
        $grace = max(180, absint($settings['interval_seconds']) * 3);
        return (time() - $heartbeat) <= $grace;
    }
}

if (!function_exists('seo_process_supervisor_exec_available')) {
    function seo_process_supervisor_exec_available() {
        if (function_exists('seo_ie_product_import_exec_available')) {
            return seo_ie_product_import_exec_available();
        }
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
        return !in_array('exec', $disabled, true);
    }
}

if (!function_exists('seo_process_supervisor_find_php_cli')) {
    function seo_process_supervisor_find_php_cli() {
        if (function_exists('seo_ie_product_import_find_php_cli')) {
            return (string) seo_ie_product_import_find_php_cli();
        }
        if (!seo_process_supervisor_exec_available() || '/' !== DIRECTORY_SEPARATOR) {
            return '';
        }
        $candidates = array();
        if (defined('PHP_BINARY') && PHP_BINARY) {
            $candidates[] = PHP_BINARY;
        }
        if (defined('PHP_BINDIR') && PHP_BINDIR) {
            $candidates[] = trailingslashit(PHP_BINDIR) . 'php';
        }
        $php_mm = defined('PHP_MAJOR_VERSION') && defined('PHP_MINOR_VERSION')
            ? (string) PHP_MAJOR_VERSION . (string) PHP_MINOR_VERSION
            : '';
        if ('' !== $php_mm) {
            $candidates[] = '/opt/alt/php' . $php_mm . '/usr/bin/php';
            $candidates[] = '/opt/cpanel/ea-php' . $php_mm . '/root/usr/bin/php';
            $candidates[] = '/usr/local/bin/ea-php' . $php_mm;
        }
        $candidates[] = '/usr/local/bin/php';
        $candidates[] = '/usr/bin/php';
        $out = array();
        $code = 1;
        @exec('command -v php 2>/dev/null', $out, $code);
        if (0 === $code && !empty($out[0])) {
            $candidates[] = trim((string) $out[0]);
        }
        foreach (array_unique(array_filter($candidates)) as $candidate) {
            if (!@is_file($candidate) || !@is_executable($candidate)) {
                continue;
            }
            $probe = array();
            $probe_code = 1;
            @exec(escapeshellarg($candidate) . ' -r ' . escapeshellarg('echo PHP_SAPI;') . ' 2>/dev/null', $probe, $probe_code);
            if (0 === $probe_code && 'cli' === trim(implode('', $probe))) {
                return $candidate;
            }
        }
        return '';
    }
}

if (!function_exists('seo_process_supervisor_signature')) {
    function seo_process_supervisor_signature($dispatch_id, $dispatch_at) {
        return hash_hmac(
            'sha256',
            sanitize_key((string) $dispatch_id) . '|' . absint($dispatch_at) . '|seo-process-supervisor',
            wp_salt('auth')
        );
    }
}

if (!function_exists('seo_process_supervisor_dispatch_valid')) {
    function seo_process_supervisor_dispatch_valid($dispatch_id, $dispatch_at, $signature) {
        $state = seo_process_supervisor_state();
        $dispatch_id = sanitize_key((string) $dispatch_id);
        $dispatch_at = absint($dispatch_at);
        $signature = strtolower(preg_replace('/[^a-f0-9]/i', '', (string) $signature));
        if (
            empty($state['dispatch_pending'])
            || sanitize_key((string) ($state['dispatch_id'] ?? '')) !== $dispatch_id
            || absint($state['dispatch_at'] ?? 0) !== $dispatch_at
        ) {
            return false;
        }
        return hash_equals(seo_process_supervisor_signature($dispatch_id, $dispatch_at), $signature);
    }
}

if (!function_exists('seo_process_supervisor_claim_dispatch')) {
    function seo_process_supervisor_claim_dispatch($dispatch_id, $dispatch_at, $signature, $backend) {
        if (!seo_process_supervisor_dispatch_valid($dispatch_id, $dispatch_at, $signature)) {
            return false;
        }
        seo_process_supervisor_save_state(array(
            'dispatch_pending' => 0,
            'dispatch_id'      => '',
            'dispatch_backend' => sanitize_key((string) $backend),
            'dispatch_error'   => '',
        ));
        return true;
    }
}

if (!function_exists('seo_process_supervisor_spawn_cli')) {
    function seo_process_supervisor_spawn_cli($dispatch_id, $dispatch_at, $signature) {
        $php = seo_process_supervisor_find_php_cli();
        $worker = SEO_SYSTEM_PATH . 'includes/process-supervisor-worker.php';
        $wp_load = trailingslashit(ABSPATH) . 'wp-load.php';
        if (!$php) {
            return new WP_Error('supervisor_cli_missing', 'PHP CLI no está disponible para el supervisor.');
        }
        if (!is_readable($worker) || !is_readable($wp_load)) {
            return new WP_Error('supervisor_cli_files', 'No se encuentra el launcher del supervisor o wp-load.php.');
        }
        $output = array();
        $status = 1;
        $command = escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' '
            . escapeshellarg($wp_load) . ' '
            . escapeshellarg(sanitize_key((string) $dispatch_id)) . ' '
            . escapeshellarg((string) absint($dispatch_at)) . ' '
            . escapeshellarg((string) $signature)
            . ' > /dev/null 2>&1 & echo $!';
        @exec($command, $output, $status);
        $pid = !empty($output) ? absint(trim((string) end($output))) : 0;
        if (0 !== $status) {
            return new WP_Error('supervisor_cli_spawn', 'El servidor no pudo desacoplar el supervisor PHP CLI.');
        }
        return array('backend' => 'direct_cli', 'pid' => $pid);
    }
}

if (!function_exists('seo_process_supervisor_spawn_http')) {
    function seo_process_supervisor_spawn_http($dispatch_id, $dispatch_at, $signature) {
        $url = admin_url('admin-post.php');
        $response = wp_remote_post($url, array(
            // Handshake real: no damos por arrancado el supervisor hasta que
            // admin-post.php haya validado el despacho y devuelto 202.
            'timeout'     => 6,
            'redirection' => 0,
            'blocking'    => true,
            'sslverify'   => apply_filters('https_local_ssl_verify', false),
            'headers'     => array('Connection' => 'close'),
            'body'        => array(
                'action'      => 'seo_process_supervisor_direct',
                'dispatch_id' => sanitize_key((string) $dispatch_id),
                'dispatch_at' => absint($dispatch_at),
                'signature'   => $signature,
            ),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = absint(wp_remote_retrieve_response_code($response));
        $body = trim((string) wp_remote_retrieve_body($response));
        if (202 !== $code || 'accepted' !== $body) {
            return new WP_Error(
                'supervisor_http_handshake',
                sprintf('El loopback del supervisor no fue aceptado (HTTP %d%s).', $code, '' !== $body ? ': ' . sanitize_text_field(substr($body, 0, 160)) : '')
            );
        }
        return array('backend' => 'direct_http', 'pid' => 0);
    }
}

if (!function_exists('seo_process_supervisor_start')) {
    function seo_process_supervisor_start($force = false, $reason = 'automatic') {
        $settings = seo_process_supervisor_settings();
        if (empty($settings['enabled']) && !in_array($reason, array('manual', 'restart', 'settings'), true)) {
            return new WP_Error('supervisor_disabled', 'El gestor está desactivado.');
        }
        seo_process_supervisor_save_state(array(
            'active'            => 1,
            'status'            => 'waiting',
            'pid'               => 0,
            'backend'           => 'periodic_manager',
            'stop_requested'    => 0,
            'restart_requested' => 0,
            'dispatch_pending'  => 0,
            'dispatch_error'    => '',
            'last_error'        => '',
            'next_cycle_at'     => time(),
        ));
        seo_process_supervisor_schedule_backup(true);
        seo_process_supervisor_log('info', 'manager_enabled', 'Gestor periódico habilitado.', 'Supervisor', array('reason' => $reason));
        return array('started' => true, 'message' => 'Gestor periódico activado.');
    }
}

if (!function_exists('seo_process_supervisor_acquire_lock')) {
    function seo_process_supervisor_acquire_lock($pid = 0) {
        $existing = get_option(SEO_PROCESS_SUPERVISOR_LOCK_OPTION, array());
        if (is_array($existing) && !empty($existing)) {
            $at = absint($existing['at'] ?? 0);
            if (!$at || (time() - $at) > 180) {
                delete_option(SEO_PROCESS_SUPERVISOR_LOCK_OPTION);
            }
        }
        return add_option(
            SEO_PROCESS_SUPERVISOR_LOCK_OPTION,
            array('pid' => absint($pid), 'at' => time(), 'token' => wp_generate_password(12, false, false)),
            '',
            false
        );
    }
}

if (!function_exists('seo_process_supervisor_release_lock')) {
    function seo_process_supervisor_release_lock($pid) {
        $lock = get_option(SEO_PROCESS_SUPERVISOR_LOCK_OPTION, array());
        if (!is_array($lock) || absint($lock['pid'] ?? 0) === absint($pid)) {
            delete_option(SEO_PROCESS_SUPERVISOR_LOCK_OPTION);
        }
    }
}

if (!function_exists('seo_process_supervisor_managed_update')) {
    function seo_process_supervisor_managed_update($key, $changes) {
        $state = seo_process_supervisor_state();
        $managed = isset($state['managed']) && is_array($state['managed']) ? $state['managed'] : array();
        $current = isset($managed[$key]) && is_array($managed[$key]) ? $managed[$key] : array();
        $managed[$key] = array_merge($current, (array) $changes);
        seo_process_supervisor_save_state(array('managed' => $managed));
        return $managed[$key];
    }
}

if (!function_exists('seo_process_supervisor_backoff_ready')) {
    function seo_process_supervisor_backoff_ready($key, $base_cooldown) {
        $state = seo_process_supervisor_state();
        $managed = isset($state['managed'][$key]) && is_array($state['managed'][$key]) ? $state['managed'][$key] : array();
        $failures = absint($managed['failures'] ?? 0);
        $last_attempt = absint($managed['last_attempt_at'] ?? 0);
        $factor = min(16, pow(2, min(4, $failures)));
        $cooldown = min(600, max(5, absint($base_cooldown)) * $factor);
        return !$last_attempt || (time() - $last_attempt) >= $cooldown;
    }
}

if (!function_exists('seo_process_supervisor_import_users')) {
    function seo_process_supervisor_import_users() {
        $ids = get_users(array(
            'meta_key' => 'seo_ie_active_product_import',
            'fields'   => 'ID',
            'number'   => 50,
        ));
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
        if (function_exists('seo_ie_batch_status')) {
            $queue = (array) seo_ie_batch_status();
            $queue_user = absint($queue['user_id'] ?? 0);
            if ($queue_user) {
                $ids[] = $queue_user;
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }
}

if (!function_exists('seo_process_supervisor_import_pending')) {
    function seo_process_supervisor_import_pending($user_id) {
        if (!function_exists('seo_ie_product_import_get_active')) {
            return null;
        }
        $active = seo_ie_product_import_get_active(absint($user_id));
        if (empty($active['token'])) {
            return null;
        }
        $token = sanitize_key($active['token']);
        $state = get_transient(seo_ie_product_import_state_key(absint($user_id), $token));
        if (!is_array($state)) {
            return null;
        }
        $status = sanitize_key($state['status'] ?? 'processing');
        if (in_array($status, array('completed', 'stopping', 'stopped', 'failed'), true)) {
            return null;
        }
        return array('user_id' => absint($user_id), 'token' => $token, 'state' => $state);
    }
}

if (!function_exists('seo_process_supervisor_academy_pending')) {
    function seo_process_supervisor_academy_pending() {
        if (!class_exists('SEO_Dependiente_Entrenador') || !is_callable(array('SEO_Dependiente_Entrenador', 'process_monitor_payload'))) {
            return false;
        }
        $payload = SEO_Dependiente_Entrenador::process_monitor_payload();
        $state = isset($payload['state']) && is_array($payload['state']) ? $payload['state'] : array();
        return !empty($payload['current'])
            && !empty($state['enabled'])
            && 'auto' === (string) ($state['mode'] ?? '')
            && !in_array((string) ($state['status'] ?? ''), array('completed', 'failed', 'stopped'), true);
    }
}

if (!function_exists('seo_process_supervisor_run_manager_window')) {
    function seo_process_supervisor_run_manager_window($source = 'wp_cron_manager', $max_runtime = 45) {
        $settings = seo_process_supervisor_settings();
        if (empty($settings['enabled'])) {
            return false;
        }
        $source = sanitize_key((string) $source);
        $max_runtime = max(10, min(55, absint($max_runtime)));
        $pid = function_exists('getmypid') ? absint(getmypid()) : 0;
        if (!seo_process_supervisor_acquire_lock($pid)) {
            seo_process_supervisor_log('debug', 'manager_overlap_skipped', 'Ya existe un ciclo del gestor en ejecución.', 'Supervisor');
            return false;
        }

        $started = time();
        seo_process_supervisor_save_state(array(
            'active'        => 1,
            'status'        => 'running',
            'pid'           => $pid,
            'backend'       => $source,
            'started_at'    => $started,
            'heartbeat_at'  => $started,
            'last_cycle_at' => $started,
            'last_error'    => '',
        ));
        seo_process_supervisor_log('info', 'manager_cycle_started', 'Ciclo del gestor iniciado.', 'Supervisor', array('source' => $source, 'runtime' => $max_runtime));

        try {
            $targets = array();
            if (!empty($settings['import_export']) && function_exists('seo_ie_product_import_run_manager_slice')) {
                foreach (seo_process_supervisor_import_users() as $user_id) {
                    $pending = seo_process_supervisor_import_pending($user_id);
                    if ($pending) {
                        $due = absint($pending['state']['direct_worker_not_before'] ?? 0);
                        if ($due && $due > time()) {
                            seo_process_supervisor_managed_update('import-export-' . absint($user_id), array(
                                'name' => 'Import / Export', 'pending' => 1, 'healthy' => 1, 'last_checked' => time(),
                                'last_result' => 'waiting', 'last_error' => '',
                                'detail' => 'En espera de la pausa adaptativa; el gestor lo retomará automáticamente.'
                            ));
                        } else {
                            $targets[] = array('type' => 'import', 'data' => $pending);
                        }
                    }
                }
            }
            if (!empty($settings['academy']) && seo_process_supervisor_academy_pending() && is_callable(array('SEO_Dependiente_Entrenador', 'process_manager_slice'))) {
                $targets[] = array('type' => 'academy', 'data' => array());
            }

            $total = count($targets);
            if (!$total) {
                $current_state = seo_process_supervisor_state();
                $current_managed = isset($current_state['managed']) && is_array($current_state['managed']) ? $current_state['managed'] : array();
                foreach ($current_managed as $managed_key => $managed_row) {
                    if (0 === strpos((string) $managed_key, 'import-export-')) {
                        seo_process_supervisor_managed_update($managed_key, array(
                            'pending' => 0, 'healthy' => 1, 'last_checked' => time(),
                            'detail' => 'Sin importaciones pendientes.', 'last_result' => 'idle', 'last_error' => ''
                        ));
                    }
                }
                seo_process_supervisor_managed_update('import-export', array(
                    'name' => 'Import / Export', 'pending' => 0, 'healthy' => 1, 'last_checked' => time(),
                    'detail' => 'Sin importaciones pendientes.', 'last_result' => 'idle', 'last_error' => ''
                ));
                seo_process_supervisor_managed_update('academy', array(
                    'name' => 'Academia', 'pending' => 0, 'healthy' => 1, 'last_checked' => time(),
                    'detail' => 'Sin trabajo automático pendiente.', 'last_result' => 'idle', 'last_error' => ''
                ));
            }

            foreach ($targets as $index => $target) {
                $elapsed = time() - $started;
                $remaining = max(0, $max_runtime - $elapsed);
                if ($remaining < 5) {
                    break;
                }
                $left = max(1, $total - $index);
                $budget = max(5, min(55, (int) floor($remaining / $left)));

                if ('import' === $target['type']) {
                    $user_id = absint($target['data']['user_id']);
                    $token = sanitize_key($target['data']['token']);
                    $key = 'import-export-' . $user_id;
                    seo_process_supervisor_managed_update($key, array(
                        'name' => 'Import / Export', 'pending' => 1, 'healthy' => 1, 'last_checked' => time(),
                        'last_attempt_at' => time(), 'last_result' => 'running', 'last_error' => '',
                        'detail' => 'El gestor está ejecutando una ventana de importación.'
                    ));
                    seo_process_supervisor_log('info', 'process_window_started', 'Import / Export entra en una ventana del gestor.', 'Import / Export', array('user_id' => $user_id, 'seconds' => $budget));
                    $ok = seo_ie_product_import_run_manager_slice($user_id, $token, $budget, $source);
                    $fresh = seo_process_supervisor_import_pending($user_id);
                    seo_process_supervisor_managed_update($key, array(
                        'pending' => $fresh ? 1 : 0,
                        'healthy' => 1,
                        'last_checked' => time(),
                        'last_result' => $ok ? 'processed' : 'waiting',
                        'last_error' => '',
                        'detail' => $fresh ? 'Ventana completada; continuará en el siguiente ciclo.' : 'Importación terminada o sin trabajo pendiente.'
                    ));
                    if ($ok) {
                        $state = seo_process_supervisor_state();
                        seo_process_supervisor_save_state(array('launch_count' => absint($state['launch_count'] ?? 0) + 1, 'last_launch_at' => time()));
                    }
                } elseif ('academy' === $target['type']) {
                    seo_process_supervisor_managed_update('academy', array(
                        'name' => 'Academia', 'pending' => 1, 'healthy' => 1, 'last_checked' => time(),
                        'last_attempt_at' => time(), 'last_result' => 'running', 'last_error' => '',
                        'detail' => 'El gestor está ejecutando una ventana de Academia.'
                    ));
                    seo_process_supervisor_log('info', 'process_window_started', 'Academia entra en una ventana del gestor.', 'Academia', array('seconds' => $budget));
                    $ok = SEO_Dependiente_Entrenador::process_manager_slice($budget, $source);
                    $still = seo_process_supervisor_academy_pending();
                    seo_process_supervisor_managed_update('academy', array(
                        'pending' => $still ? 1 : 0,
                        'healthy' => 1,
                        'last_checked' => time(),
                        'last_result' => $ok ? 'processed' : 'waiting',
                        'last_error' => '',
                        'detail' => $still ? 'Ventana completada; continuará en el siguiente ciclo.' : 'Academia terminada o sin trabajo pendiente.'
                    ));
                    if ($ok) {
                        $state = seo_process_supervisor_state();
                        seo_process_supervisor_save_state(array('launch_count' => absint($state['launch_count'] ?? 0) + 1, 'last_launch_at' => time()));
                    }
                }
                seo_process_supervisor_save_state(array('heartbeat_at' => time()));
            }
        } catch (Throwable $e) {
            seo_process_supervisor_save_state(array('last_error' => sanitize_text_field($e->getMessage())));
            seo_process_supervisor_log('error', 'manager_cycle_error', $e->getMessage(), 'Supervisor');
        } finally {
            $state = seo_process_supervisor_state();
            seo_process_supervisor_save_state(array(
                'active'        => 1,
                'status'        => 'waiting',
                'pid'           => 0,
                'backend'       => $source,
                'heartbeat_at'  => time(),
                'last_cycle_at' => time(),
                'next_cycle_at' => time() + max(60, absint($settings['interval_seconds'])),
                'cycle_count'   => absint($state['cycle_count'] ?? 0) + 1,
            ));
            seo_process_supervisor_release_lock($pid);
        }
        seo_process_supervisor_log('success', 'manager_cycle_finished', 'Ciclo del gestor completado.', 'Supervisor', array('source' => $source, 'elapsed' => time() - $started));
        return true;
    }
}

if (!function_exists('seo_process_supervisor_check_import_export')) {
    function seo_process_supervisor_check_import_export($settings) {
        if (empty($settings['import_export']) || !function_exists('seo_ie_product_import_get_active')) {
            return;
        }
        $found = false;
        foreach (seo_process_supervisor_import_users() as $user_id) {
            $active = seo_ie_product_import_get_active($user_id);
            if (empty($active['token'])) {
                continue;
            }
            $found = true;
            $diag = isset($active['diagnostics']) && is_array($active['diagnostics']) ? $active['diagnostics'] : array();
            $status = sanitize_key((string) ($active['status'] ?? $diag['status'] ?? 'processing'));
            $key = 'import-export-' . absint($user_id);
            $lock_healthy = !empty($diag['lock_active']) && absint($diag['lock_age_seconds'] ?? 0) <= (6 * MINUTE_IN_SECONDS);
            if ($lock_healthy && array_key_exists('worker_pid_alive', $diag) && false === $diag['worker_pid_alive']) {
                $lock_healthy = false;
            }
            $running_confirmed = !empty($diag['controller_active']) || $lock_healthy;
            $healthy = $running_confirmed || (!empty($diag['direct_worker_pending']) && empty($diag['direct_worker_stale']));
            $pending = !in_array($status, array('completed', 'stopped', 'stopping'), true) && (float) ($active['progreso'] ?? 0) < 100.0;
            $managed_now = seo_process_supervisor_managed_update($key, array(
                'name'          => 'Import / Export',
                'pending'       => $pending ? 1 : 0,
                'healthy'       => $healthy ? 1 : 0,
                'last_checked'  => time(),
                'detail'        => $healthy ? 'Controlador activo o arrancando.' : ($pending ? 'Trabajo pendiente sin controlador.' : 'Sin trabajo pendiente.'),
            ));
            if ($running_confirmed && 'requested' === (string) ($managed_now['last_result'] ?? '')) {
                seo_process_supervisor_managed_update($key, array(
                    'failures'          => 0,
                    'last_error'        => '',
                    'last_result'       => 'running',
                    'last_confirmed_at' => time(),
                ));
                seo_process_supervisor_log('success', 'process_running_confirmed', 'Import / Export confirmó heartbeat; el proceso está realmente ejecutándose.', 'Import / Export', array('user_id' => $user_id));
            }
            if (!$pending || $healthy || !seo_process_supervisor_backoff_ready($key, $settings['restart_cooldown'])) {
                continue;
            }
            if ('requested' === (string) ($managed_now['last_result'] ?? '')) {
                $misses = absint($managed_now['failures'] ?? 0) + 1;
                seo_process_supervisor_managed_update($key, array('failures' => $misses, 'last_result' => 'unconfirmed'));
                seo_process_supervisor_log('error', 'process_start_unconfirmed', 'El arranque anterior de Import / Export no produjo heartbeat. Se reintentará.', 'Import / Export', array('user_id' => $user_id, 'failures' => $misses));
            }

            $current = seo_process_supervisor_managed_update($key, array('last_attempt_at' => time()));
            seo_process_supervisor_log('warning', 'process_missing', 'Hay importación pendiente y no existe controlador activo. Se intentará arrancar.', 'Import / Export', array('user_id' => $user_id));
            $result = function_exists('seo_ie_product_import_control_start') ? seo_ie_product_import_control_start($user_id) : new WP_Error('import_control_missing', 'No está disponible el control de arranque de Import / Export.');
            if (is_wp_error($result)) {
                $failures = absint($current['failures'] ?? 0) + 1;
                seo_process_supervisor_managed_update($key, array(
                    'failures'       => $failures,
                    'last_error'     => $result->get_error_message(),
                    'last_result'    => 'error',
                ));
                seo_process_supervisor_log('error', 'process_launch_failed', $result->get_error_message(), 'Import / Export', array('user_id' => $user_id, 'failures' => $failures));
            } else {
                $state = seo_process_supervisor_state();
                seo_process_supervisor_save_state(array(
                    'launch_count'   => absint($state['launch_count'] ?? 0) + 1,
                    'last_launch_at' => time(),
                ));
                seo_process_supervisor_managed_update($key, array(
                    'last_error'      => '',
                    'last_result'     => 'requested',
                    'last_started_at' => time(),
                ));
                seo_process_supervisor_log('info', 'process_launch_requested', sanitize_text_field((string) ($result['message'] ?? 'Arranque de Import / Export solicitado.')) . ' Se espera confirmación por heartbeat.', 'Import / Export', array('user_id' => $user_id));
            }
        }
        if (!$found) {
            seo_process_supervisor_managed_update('import-export', array(
                'name'         => 'Import / Export',
                'pending'      => 0,
                'healthy'      => 0,
                'last_checked' => time(),
                'detail'       => 'No hay importación activa.',
                'failures'     => 0,
            ));
        }
    }
}

if (!function_exists('seo_process_supervisor_check_academy')) {
    function seo_process_supervisor_check_academy($settings) {
        if (empty($settings['academy']) || !class_exists('SEO_Dependiente_Entrenador') || !is_callable(array('SEO_Dependiente_Entrenador', 'process_monitor_payload'))) {
            return;
        }
        $payload = SEO_Dependiente_Entrenador::process_monitor_payload();
        $state = isset($payload['state']) && is_array($payload['state']) ? $payload['state'] : array();
        $pending = !empty($payload['current']) && !empty($state['enabled']) && 'auto' === (string) ($state['mode'] ?? '') && 'completed' !== (string) ($state['status'] ?? '');
        $heartbeat = absint($state['controller_heartbeat_ts'] ?? 0);
        $controller = !empty($state['controller_active']) && $heartbeat && (time() - $heartbeat) <= 120;
        if ($controller && !empty($state['controller_pid'])) {
            $alive = seo_process_supervisor_pid_alive(absint($state['controller_pid']));
            if (false === $alive) {
                $controller = false;
            }
        }
        $worker_heartbeat = absint($state['worker_heartbeat_ts'] ?? 0);
        $worker = !empty($state['worker_active']) && $worker_heartbeat && (time() - $worker_heartbeat) <= 90;
        $direct_pending = !empty($state['direct_worker_pending']);
        $direct_due = absint($state['direct_worker_not_before'] ?? 0);
        $direct_fresh = $direct_pending && (!$direct_due || (time() - $direct_due) <= 90);
        $running_confirmed = $controller || $worker;
        $healthy = $running_confirmed || $direct_fresh;
        $key = 'academy';
        $managed_now = seo_process_supervisor_managed_update($key, array(
            'name'         => 'Academia',
            'pending'      => $pending ? 1 : 0,
            'healthy'      => $healthy ? 1 : 0,
            'last_checked' => time(),
            'detail'       => $healthy ? 'Controlador activo o arrancando.' : ($pending ? 'Formación pendiente sin controlador.' : 'Academia sin ejecución automática pendiente.'),
        ));
        if ($running_confirmed && 'requested' === (string) ($managed_now['last_result'] ?? '')) {
            seo_process_supervisor_managed_update($key, array(
                'failures'          => 0,
                'last_error'        => '',
                'last_result'       => 'running',
                'last_confirmed_at' => time(),
            ));
            seo_process_supervisor_log('success', 'process_running_confirmed', 'Academia confirmó heartbeat; el proceso está realmente ejecutándose.', 'Academia');
        }
        if (!$pending || $healthy || !seo_process_supervisor_backoff_ready($key, $settings['restart_cooldown'])) {
            return;
        }
        if ('requested' === (string) ($managed_now['last_result'] ?? '')) {
            $misses = absint($managed_now['failures'] ?? 0) + 1;
            seo_process_supervisor_managed_update($key, array('failures' => $misses, 'last_result' => 'unconfirmed'));
            seo_process_supervisor_log('error', 'process_start_unconfirmed', 'El arranque anterior de Academia no produjo heartbeat. Se reintentará.', 'Academia', array('failures' => $misses));
        }
        $current = seo_process_supervisor_managed_update($key, array('last_attempt_at' => time()));
        seo_process_supervisor_log('warning', 'process_missing', 'La Academia está activa pero no tiene controlador. Se intentará arrancar.', 'Academia');
        $result = is_callable(array('SEO_Dependiente_Entrenador', 'process_control_start'))
            ? SEO_Dependiente_Entrenador::process_control_start()
            : new WP_Error('academy_control_missing', 'No está disponible el control de arranque de Academia.');
        if (is_wp_error($result)) {
            $failures = absint($current['failures'] ?? 0) + 1;
            seo_process_supervisor_managed_update($key, array(
                'failures'    => $failures,
                'last_error'  => $result->get_error_message(),
                'last_result' => 'error',
            ));
            seo_process_supervisor_log('error', 'process_launch_failed', $result->get_error_message(), 'Academia', array('failures' => $failures));
        } else {
            $supervisor = seo_process_supervisor_state();
            seo_process_supervisor_save_state(array(
                'launch_count'   => absint($supervisor['launch_count'] ?? 0) + 1,
                'last_launch_at' => time(),
            ));
            seo_process_supervisor_managed_update($key, array(
                'last_error'      => '',
                'last_result'     => 'requested',
                'last_started_at' => time(),
            ));
            seo_process_supervisor_log('info', 'process_launch_requested', sanitize_text_field((string) ($result['message'] ?? 'Arranque de Academia solicitado.')) . ' Se espera confirmación por heartbeat.', 'Academia');
        }
    }
}

if (!function_exists('seo_process_supervisor_cycle')) {
    function seo_process_supervisor_cycle($source = 'manager') {
        $settings = seo_process_supervisor_settings();
        return seo_process_supervisor_run_manager_window($source, absint($settings['runtime_seconds'] ?? 45));
    }
}

if (!function_exists('seo_process_supervisor_run_loop')) {
    function seo_process_supervisor_run_loop($backend = 'direct_cli', $max_runtime = 21600) {
        $backend = sanitize_key((string) $backend);
        $max_runtime = max(120, absint($max_runtime));
        $pid = function_exists('getmypid') ? absint(getmypid()) : 0;
        if (!seo_process_supervisor_acquire_lock($pid)) {
            seo_process_supervisor_log('warning', 'duplicate_supervisor', 'Se rechazó un supervisor duplicado porque ya existe otro controlador.', 'Supervisor', array('pid' => $pid));
            return;
        }

        $started = time();
        $normal_exit = false;
        seo_process_supervisor_save_state(array(
            'active'           => 1,
            'status'           => 'running',
            'pid'              => $pid,
            'backend'          => $backend,
            'started_at'       => $started,
            'heartbeat_at'     => $started,
            'stop_requested'   => 0,
            'dispatch_pending' => 0,
            'last_exit_reason' => '',
            'last_error'       => '',
        ));
        seo_process_supervisor_log('success', 'supervisor_running', 'El supervisor propio está ejecutándose.', 'Supervisor', array('backend' => $backend, 'pid' => $pid));

        try {
            while (true) {
                $settings = seo_process_supervisor_settings();
                $state = seo_process_supervisor_state();
                if (empty($settings['enabled']) || !empty($state['stop_requested'])) {
                    $normal_exit = true;
                    $exit_reason = !empty($state['restart_requested']) ? 'restart' : 'stopped_by_user';
                    seo_process_supervisor_save_state(array('last_exit_reason' => $exit_reason));
                    seo_process_supervisor_log('info', 'supervisor_stopping', 'El supervisor se detiene por configuración del usuario.', 'Supervisor', array('reason' => $exit_reason));
                    break;
                }

                seo_process_supervisor_save_state(array(
                    'active'       => 1,
                    'status'       => 'running',
                    'pid'          => $pid,
                    'backend'      => $backend,
                    'heartbeat_at' => time(),
                ));
                seo_process_supervisor_cycle($backend);

                $interval = max(5, absint($settings['interval_seconds']));
                seo_process_supervisor_save_state(array('next_cycle_at' => time() + $interval));

                if ((time() - $started) >= $max_runtime) {
                    $normal_exit = true;
                    seo_process_supervisor_save_state(array('last_exit_reason' => 'handover'));
                    seo_process_supervisor_log('info', 'supervisor_handover', 'El supervisor renueva su proceso para evitar límites del hosting.', 'Supervisor', array('runtime' => time() - $started));
                    break;
                }

                $remaining = $interval;
                while ($remaining > 0) {
                    $chunk = min(5, $remaining);
                    sleep($chunk);
                    $remaining -= $chunk;
                    $state = seo_process_supervisor_state();
                    $settings = seo_process_supervisor_settings();
                    if (empty($settings['enabled']) || !empty($state['stop_requested'])) {
                        $normal_exit = true;
                        $exit_reason = !empty($state['restart_requested']) ? 'restart' : 'stopped_by_user';
                        seo_process_supervisor_save_state(array('last_exit_reason' => $exit_reason));
                        break 2;
                    }
                    seo_process_supervisor_save_state(array('heartbeat_at' => time()));
                }
            }
        } catch (Throwable $e) {
            seo_process_supervisor_save_state(array('last_error' => $e->getMessage(), 'last_exit_reason' => 'exception'));
            seo_process_supervisor_log('error', 'supervisor_exception', $e->getMessage(), 'Supervisor');
        } finally {
            seo_process_supervisor_release_lock($pid);
            $state = seo_process_supervisor_state();
            if (absint($state['pid'] ?? 0) === $pid) {
                seo_process_supervisor_save_state(array(
                    'active'        => 0,
                    'status'        => $normal_exit ? 'stopped' : 'error',
                    'heartbeat_at'  => time(),
                    'next_cycle_at' => 0,
                ));
            }
        }

        // Handover o reinicio directo. No espera al scheduler.
        $settings = seo_process_supervisor_settings();
        $state = seo_process_supervisor_state();
        $exit_reason = (string) ($state['last_exit_reason'] ?? '');
        if (!empty($settings['enabled']) && in_array($exit_reason, array('handover', 'restart'), true)) {
            seo_process_supervisor_save_state(array('stop_requested' => 0, 'restart_requested' => 0));
            $result = seo_process_supervisor_start(true, 'restart' === $exit_reason ? 'restart' : 'handover');
            if (is_wp_error($result)) {
                seo_process_supervisor_log('error', 'supervisor_handover_failed', $result->get_error_message(), 'Supervisor', array('reason' => $exit_reason));
            }
        }
    }
}

if (!function_exists('seo_process_supervisor_direct_http')) {
    function seo_process_supervisor_direct_http() {
        $dispatch_id = sanitize_key(wp_unslash($_POST['dispatch_id'] ?? ''));
        $dispatch_at = absint($_POST['dispatch_at'] ?? 0);
        $signature = sanitize_text_field(wp_unslash($_POST['signature'] ?? ''));
        if (!seo_process_supervisor_dispatch_valid($dispatch_id, $dispatch_at, $signature)) {
            status_header(403);
            echo 'forbidden';
            exit;
        }
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
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
        if (!seo_process_supervisor_claim_dispatch($dispatch_id, $dispatch_at, $signature, 'direct_http')) {
            exit;
        }
        seo_process_supervisor_run_loop('direct_http', 240);
        exit;
    }
}
// El gestor periódico ya no utiliza loopback HTTP para mantenerse vivo.

if (!function_exists('seo_process_supervisor_maybe_ensure')) {
    function seo_process_supervisor_maybe_ensure() {
        seo_process_supervisor_schedule_backup();
    }
}
add_action('init', 'seo_process_supervisor_maybe_ensure', 99);

if (!function_exists('seo_process_supervisor_cron_schedules')) {
    function seo_process_supervisor_cron_schedules($schedules) {
        $settings = seo_process_supervisor_settings();
        $interval = max(60, absint($settings['interval_seconds'] ?? 60));
        $schedules['seo_process_manager_interval'] = array(
            'interval' => $interval,
            'display'  => sprintf('Gestor SEO Taxonomy cada %d segundos', $interval),
        );
        return $schedules;
    }
}
add_filter('cron_schedules', 'seo_process_supervisor_cron_schedules');

if (!function_exists('seo_process_supervisor_backup_watchdog')) {
    function seo_process_supervisor_backup_watchdog() {
        $settings = seo_process_supervisor_settings();
        if (empty($settings['enabled']) || empty($settings['backup_watchdog'])) {
            return;
        }
        seo_process_supervisor_run_manager_window('wp_cron_manager', absint($settings['runtime_seconds'] ?? 45));
    }
}
add_action('seo_process_supervisor_backup_watchdog', 'seo_process_supervisor_backup_watchdog');

if (!function_exists('seo_process_supervisor_schedule_backup')) {
    function seo_process_supervisor_schedule_backup($force = false) {
        $settings = seo_process_supervisor_settings();
        $hook = 'seo_process_supervisor_backup_watchdog';
        if ($force) {
            wp_clear_scheduled_hook($hook);
        }
        if (!empty($settings['enabled']) && !empty($settings['backup_watchdog'])) {
            if (false === wp_next_scheduled($hook)) {
                wp_schedule_event(time() + 5, 'seo_process_manager_interval', $hook);
            }
        } else {
            wp_clear_scheduled_hook($hook);
        }
        // Limpia el antiguo watchdog de 5 minutos si quedara programado.
        wp_clear_scheduled_hook('seo_process_supervisor_backup_watchdog_old');
    }
}
add_action('init', 'seo_process_supervisor_schedule_backup', 100);

if (!function_exists('seo_process_supervisor_save_settings_action')) {
    function seo_process_supervisor_save_settings_action() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No autorizado.', 'seo-taxonomy'));
        }
        check_admin_referer('seo_process_supervisor_save_settings');
        $raw = isset($_POST['supervisor']) && is_array($_POST['supervisor']) ? wp_unslash($_POST['supervisor']) : array();
        foreach (array('enabled', 'import_export', 'academy', 'backup_watchdog', 'log_cycles') as $checkbox) {
            $raw[$checkbox] = empty($raw[$checkbox]) ? 0 : 1;
        }
        $settings = seo_process_supervisor_sanitize_settings($raw);
        update_option(SEO_PROCESS_SUPERVISOR_OPTION, $settings, false);
        seo_process_supervisor_save_state(array('stop_requested' => empty($settings['enabled']) ? 1 : 0, 'last_error' => ''));
        seo_process_supervisor_schedule_backup(true);
        seo_process_supervisor_log('info', 'settings_saved', 'Configuración del gestor periódico actualizada.', 'Supervisor', array('interval' => $settings['interval_seconds'], 'runtime' => $settings['runtime_seconds']));
        wp_safe_redirect(add_query_arg(array('page' => 'seo-processes', 'tab' => 'workers', 'supervisor_saved' => 1), admin_url('admin.php')));
        exit;
    }
}
add_action('admin_post_seo_process_supervisor_save_settings', 'seo_process_supervisor_save_settings_action');

if (!function_exists('seo_process_supervisor_control_action')) {
    function seo_process_supervisor_control_action() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No autorizado.', 'seo-taxonomy'));
        }
        check_admin_referer('seo_process_supervisor_control');
        $command = sanitize_key(wp_unslash($_POST['command'] ?? ''));
        $settings = seo_process_supervisor_settings();
        $message = '';

        if (in_array($command, array('start', 'restart'), true)) {
            $settings['enabled'] = 1;
            update_option(SEO_PROCESS_SUPERVISOR_OPTION, $settings, false);
            seo_process_supervisor_save_state(array('stop_requested' => 0, 'last_error' => '', 'status' => 'waiting'));
            seo_process_supervisor_schedule_backup(true);
            seo_process_supervisor_run_manager_window('manual_manager', min(20, absint($settings['runtime_seconds'] ?? 45)));
            $message = 'Gestor activado y ciclo de comprobación ejecutado.';
        } elseif ('cycle' === $command) {
            seo_process_supervisor_run_manager_window('manual_manager', min(20, absint($settings['runtime_seconds'] ?? 45)));
            $message = 'Comprobación ejecutada.';
        } elseif ('stop' === $command) {
            $settings['enabled'] = 0;
            update_option(SEO_PROCESS_SUPERVISOR_OPTION, $settings, false);
            wp_clear_scheduled_hook('seo_process_supervisor_backup_watchdog');
            seo_process_supervisor_save_state(array('active' => 0, 'status' => 'stopped', 'stop_requested' => 1, 'pid' => 0));
            seo_process_supervisor_log('warning', 'manual_stop', 'El usuario ha detenido el gestor automático.', 'Supervisor');
            $message = 'Gestor detenido.';
        } elseif ('clear_log' === $command) {
            delete_option(SEO_PROCESS_SUPERVISOR_LOG_OPTION);
            $message = 'Log vaciado.';
        }

        wp_safe_redirect(add_query_arg(array(
            'page' => 'seo-processes',
            'tab' => 'workers',
            'supervisor_message' => rawurlencode($message),
        ), admin_url('admin.php')));
        exit;
    }
}
add_action('admin_post_seo_process_supervisor_control', 'seo_process_supervisor_control_action');

if (!function_exists('seo_process_supervisor_format_age')) {
    function seo_process_supervisor_format_age($timestamp) {
        $timestamp = absint($timestamp);
        if (!$timestamp) {
            return 'Nunca';
        }
        $seconds = max(0, time() - $timestamp);
        if ($seconds < 5) {
            return 'Ahora';
        }
        if ($seconds < 60) {
            return 'Hace ' . number_format_i18n($seconds) . ' s';
        }
        if ($seconds < 3600) {
            return 'Hace ' . number_format_i18n((int) floor($seconds / 60)) . ' min';
        }
        return 'Hace ' . number_format_i18n((int) floor($seconds / 3600)) . ' h';
    }
}

if (!function_exists('seo_process_supervisor_backend_label')) {
    function seo_process_supervisor_backend_label($backend) {
        $labels = array(
            'direct_cli'  => 'PHP CLI propio',
            'direct_http' => 'Loopback propio',
            'periodic_manager' => 'Gestor periódico',
            'server_cron' => 'Cron del servidor',
            'wp_cron_manager' => 'WP-Cron propio',
            'manual_manager' => 'Ejecución manual',
        );
        $backend = sanitize_key((string) $backend);
        return $labels[$backend] ?? ($backend ? $backend : 'Sin motor');
    }
}

if (!function_exists('seo_process_supervisor_render_live')) {
    function seo_process_supervisor_render_live() {
        $state = seo_process_supervisor_state();
        $settings = seo_process_supervisor_settings();
        $active = seo_process_supervisor_is_active($state);
        $managed = isset($state['managed']) && is_array($state['managed']) ? $state['managed'] : array();
        $logs = seo_process_supervisor_logs(180);
        ob_start();
        ?>
        <div class="seo-worker-summary">
            <div><strong class="<?php echo $active ? 'is-ok' : 'is-bad'; ?>"><?php echo $active ? 'ACTIVO' : (empty($settings['enabled']) ? 'DESACTIVADO' : 'SIN CICLO RECIENTE'); ?></strong><span>Supervisor</span></div>
            <div><strong><?php echo esc_html(seo_process_supervisor_backend_label($state['backend'] ?? $state['dispatch_backend'] ?? '')); ?></strong><span>Motor</span></div>
            <div><strong><?php echo esc_html(absint($state['pid'] ?? 0) ? (string) absint($state['pid']) : '—'); ?></strong><span>PID del ciclo</span></div>
            <div><strong><?php echo esc_html(seo_process_supervisor_format_age($state['heartbeat_at'] ?? 0)); ?></strong><span>Heartbeat</span></div>
            <div><strong><?php echo esc_html(number_format_i18n(absint($state['launch_count'] ?? 0))); ?></strong><span>Ventanas ejecutadas</span></div>
        </div>

        <?php if (!empty($state['last_error'])) : ?>
            <div class="notice notice-error inline"><p><strong>Último error del supervisor:</strong> <?php echo esc_html($state['last_error']); ?></p></div>
        <?php endif; ?>

        <h3>Procesos vigilados</h3>
        <div class="seo-worker-table-wrap">
            <table class="widefat striped seo-worker-table">
                <thead><tr><th>Proceso</th><th>Autoarranque</th><th>Trabajo pendiente</th><th>Estado detectado</th><th>Último intento</th><th>Resultado</th></tr></thead>
                <tbody>
                    <?php
                    $visible = array();
                    foreach ($managed as $key => $row) {
                        if (0 === strpos((string) $key, 'import-export')) {
                            $visible[$key] = $row;
                        }
                    }
                    if (!isset($visible['import-export']) && !$visible) {
                        $visible['import-export'] = array('name' => 'Import / Export', 'pending' => 0, 'healthy' => 0, 'detail' => 'Todavía sin lectura.', 'last_checked' => 0);
                    }
                    $visible['academy'] = isset($managed['academy']) ? $managed['academy'] : array('name' => 'Academia', 'pending' => 0, 'healthy' => 0, 'detail' => 'Todavía sin lectura.', 'last_checked' => 0);
                    foreach ($visible as $key => $row) :
                        $is_import = 0 === strpos((string) $key, 'import-export');
                        $enabled = $is_import ? !empty($settings['import_export']) : !empty($settings['academy']);
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($row['name'] ?? ($is_import ? 'Import / Export' : 'Academia')); ?></strong></td>
                        <td><?php echo $enabled ? '<span class="seo-worker-pill is-ok">Sí</span>' : '<span class="seo-worker-pill">No</span>'; ?></td>
                        <td><?php echo !empty($row['pending']) ? '<span class="seo-worker-pill is-warn">Sí</span>' : '<span class="seo-worker-pill">No</span>'; ?></td>
                        <td><strong><?php echo !empty($row['healthy']) ? 'Gestionado' : 'Sin actividad'; ?></strong><br><small><?php echo esc_html($row['detail'] ?? ''); ?></small></td>
                        <td><?php echo esc_html(seo_process_supervisor_format_age($row['last_attempt_at'] ?? 0)); ?></td>
                        <td><?php echo esc_html($row['last_result'] ?? '—'); ?><?php if (!empty($row['last_error'])) : ?><br><small class="seo-worker-error"><?php echo esc_html($row['last_error']); ?></small><?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr><td><strong>Chequeos páginas/posts/imágenes</strong></td><td colspan="5">Runner GitHub externo. No necesita un worker PHP local persistente.</td></tr>
                </tbody>
            </table>
        </div>

        <div class="seo-worker-log-head"><h3>Log del gestor de workers</h3><span><?php echo esc_html(number_format_i18n(count($logs))); ?> eventos visibles</span></div>
        <div class="seo-worker-log-wrap">
            <table class="widefat striped seo-worker-log">
                <thead><tr><th>Hora</th><th>Nivel</th><th>Origen</th><th>Evento</th><th>Mensaje</th></tr></thead>
                <tbody>
                <?php if (!$logs) : ?>
                    <tr><td colspan="5">Todavía no hay eventos registrados.</td></tr>
                <?php else : foreach ($logs as $row) : ?>
                    <tr>
                        <td><?php echo esc_html(wp_date('d/m H:i:s', absint($row['time'] ?? 0))); ?></td>
                        <td><span class="seo-worker-pill is-<?php echo esc_attr(sanitize_key($row['level'] ?? 'info')); ?>"><?php echo esc_html(strtoupper((string) ($row['level'] ?? 'INFO'))); ?></span></td>
                        <td><?php echo esc_html($row['process'] ?? 'Supervisor'); ?></td>
                        <td><code><?php echo esc_html($row['event'] ?? ''); ?></code></td>
                        <td><?php echo esc_html($row['message'] ?? ''); ?><?php if (!empty($row['context'])) : ?><br><small><?php echo esc_html(wp_json_encode($row['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></small><?php endif; ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('seo_process_supervisor_ajax_status')) {
    function seo_process_supervisor_ajax_status() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'No autorizado.'), 403);
        }
        check_ajax_referer('seo_process_supervisor_status', 'nonce');
        wp_send_json_success(array('html' => seo_process_supervisor_render_live(), 'refreshed' => current_time('H:i:s')));
    }
}
add_action('wp_ajax_seo_process_supervisor_status', 'seo_process_supervisor_ajax_status');

if (!function_exists('seo_process_supervisor_render_page')) {
    function seo_process_supervisor_render_page() {
        $settings = seo_process_supervisor_settings();
        $nonce = wp_create_nonce('seo_process_supervisor_status');
        ?>
        <div class="wrap seo-worker-manager">
            <h1>Procesos</h1>
            <h2 class="nav-tab-wrapper">
                <a class="nav-tab" href="<?php echo esc_url(add_query_arg(array('page' => 'seo-processes'), admin_url('admin.php'))); ?>">Procesos</a>
                <a class="nav-tab nav-tab-active" href="<?php echo esc_url(add_query_arg(array('page' => 'seo-processes', 'tab' => 'workers'), admin_url('admin.php'))); ?>">Gestor de workers</a>
            </h2>
            <div class="seo-worker-heading">
                <div>
                    <h2>Gestor periódico del plugin</h2>
                    <p>El gestor revisa Import/Export y Academia y ejecuta directamente ventanas de trabajo. No usa Action Scheduler ni el worker de WooCommerce. Puede funcionar con un cron real del servidor y mantiene WP-Cron propio como respaldo.</p>
                </div>
                <span>Lectura: <strong id="seo-worker-refreshed"><?php echo esc_html(current_time('H:i:s')); ?></strong></span>
            </div>

            <?php if (!empty($_GET['supervisor_saved'])) : ?><div class="notice notice-success is-dismissible"><p>Configuración del supervisor guardada.</p></div><?php endif; ?>
            <?php if (!empty($_GET['supervisor_message'])) : ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['supervisor_message'])))); ?></p></div><?php endif; ?>

            <?php
            $cron_php = '/opt/alt/php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '/usr/bin/php';
            $cron_file = SEO_SYSTEM_PATH . 'includes/process-manager-cron.php';
            $cron_command = $cron_php . ' ' . $cron_file . ' >/dev/null 2>&1';
            ?>
            <div class="seo-worker-settings">
                <strong>Cron real del servidor recomendado: cada minuto</strong>
                <p><code style="display:block;white-space:normal;word-break:break-all;padding:8px;background:#f6f7f7"><?php echo esc_html($cron_command); ?></code></p>
                <small>Si lo configuras en el panel del hosting, este será el motor principal. El WP-Cron propio puede quedar como respaldo.</small>
            </div>

            <form class="seo-worker-settings" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="seo_process_supervisor_save_settings">
                <?php wp_nonce_field('seo_process_supervisor_save_settings'); ?>
                <div class="seo-worker-grid">
                    <label><input type="checkbox" name="supervisor[enabled]" value="1" <?php checked(!empty($settings['enabled'])); ?>> <strong>Gestor automático activo</strong><small>Ejecuta periódicamente tus procesos pendientes.</small></label>
                    <label>Frecuencia de revisión<input type="number" name="supervisor[interval_seconds]" value="<?php echo esc_attr((string) $settings['interval_seconds']); ?>" min="60" max="900" step="1"><small>Segundos entre ciclos del gestor.</small></label>
                    <label>Ventana de trabajo<input type="number" name="supervisor[runtime_seconds]" value="<?php echo esc_attr((string) ($settings['runtime_seconds'] ?? 45)); ?>" min="10" max="55" step="1"><small>Segundos máximos de trabajo por ciclo.</small></label>
                    <label>Espera antes de reintentar<input type="number" name="supervisor[restart_cooldown]" value="<?php echo esc_attr((string) $settings['restart_cooldown']); ?>" min="5" max="600" step="1"><small>Se amplía automáticamente tras fallos repetidos.</small></label>
                    <label><input type="checkbox" name="supervisor[import_export]" value="1" <?php checked(!empty($settings['import_export'])); ?>> Autoarrancar <strong>Import / Export</strong><small>Solo si existe una importación pendiente.</small></label>
                    <label><input type="checkbox" name="supervisor[academy]" value="1" <?php checked(!empty($settings['academy'])); ?>> Autoarrancar <strong>Academia</strong><small>Solo si la Academia está en modo automático y pendiente.</small></label>
                    <label><input type="checkbox" name="supervisor[backup_watchdog]" value="1" <?php checked(!empty($settings['backup_watchdog'])); ?>> WP-Cron propio de respaldo<small>Ejecuta este gestor si no has configurado un cron real del servidor.</small></label>
                    <label><input type="checkbox" name="supervisor[log_cycles]" value="1" <?php checked(!empty($settings['log_cycles'])); ?>> Registrar cada ciclo<small>Útil para diagnóstico; genera más entradas.</small></label>
                </div>
                <p><button type="submit" class="button button-primary">Guardar configuración</button></p>
            </form>

            <form class="seo-worker-actions" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="seo_process_supervisor_control">
                <?php wp_nonce_field('seo_process_supervisor_control'); ?>
                <button class="button button-primary" name="command" value="start">Activar gestor</button>
                <button class="button" name="command" value="restart">Reiniciar gestor</button>
                <button class="button" name="command" value="cycle">Comprobar procesos ahora</button>
                <button class="button" name="command" value="stop" onclick="return confirm('¿Detener el supervisor automático? Los procesos que ya estén ejecutándose no se detendrán.');">Detener gestor</button>
                <button class="button" name="command" value="clear_log" onclick="return confirm('¿Vaciar el log del gestor de workers?');">Vaciar log</button>
            </form>

            <div id="seo-worker-live"><?php echo seo_process_supervisor_render_live(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
        </div>
        <style>
            .seo-worker-manager{max-width:1500px}.seo-worker-heading{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin:18px 0}.seo-worker-heading h2{margin:0 0 5px}.seo-worker-heading p{max-width:900px;margin:0;color:#50575e}.seo-worker-heading>span{font-size:12px;color:#646970}.seo-worker-settings{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin-bottom:12px}.seo-worker-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px 20px}.seo-worker-grid label{font-weight:600}.seo-worker-grid input[type=number]{display:block;width:120px;margin-top:6px}.seo-worker-grid small{display:block;color:#646970;font-weight:400;margin-top:4px}.seo-worker-actions{display:flex;gap:8px;flex-wrap:wrap;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px;margin-bottom:18px}.seo-worker-summary{display:grid;grid-template-columns:repeat(5,minmax(130px,1fr));gap:10px;margin-bottom:18px}.seo-worker-summary>div{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px}.seo-worker-summary strong{display:block;font-size:18px}.seo-worker-summary span{display:block;color:#646970;margin-top:4px}.seo-worker-summary .is-ok{color:#116329}.seo-worker-summary .is-bad{color:#8a2424}.seo-worker-table-wrap,.seo-worker-log-wrap{overflow:auto;background:#fff;border:1px solid #dcdcde;border-radius:8px}.seo-worker-table,.seo-worker-log{min-width:900px;border:0}.seo-worker-table td,.seo-worker-table th,.seo-worker-log td,.seo-worker-log th{padding:10px 12px;vertical-align:top}.seo-worker-pill{display:inline-block;border-radius:999px;padding:3px 8px;background:#f0f0f1;font-size:11px;font-weight:700}.seo-worker-pill.is-ok,.seo-worker-pill.is-success{background:#edfaef;color:#116329}.seo-worker-pill.is-warn,.seo-worker-pill.is-warning{background:#fff8e5;color:#8a6116}.seo-worker-pill.is-error{background:#fcf0f1;color:#8a2424}.seo-worker-pill.is-debug{background:#f0f6fc;color:#135e96}.seo-worker-error{color:#b32d2e}.seo-worker-log-head{display:flex;justify-content:space-between;align-items:center;margin-top:22px}.seo-worker-log-head span{font-size:12px;color:#646970}@media(max-width:800px){.seo-worker-summary{grid-template-columns:repeat(2,minmax(120px,1fr))}.seo-worker-heading{display:block}.seo-worker-heading>span{display:block;margin-top:8px}}@media(max-width:520px){.seo-worker-summary{grid-template-columns:1fr}}
        </style>
        <script>
        (function(){
            var live=document.getElementById('seo-worker-live');
            var refreshed=document.getElementById('seo-worker-refreshed');
            var timer=null;
            if(!live){return;}
            function load(){
                var data=new URLSearchParams();
                data.append('action','seo_process_supervisor_status');
                data.append('nonce',<?php echo wp_json_encode($nonce); ?>);
                fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:data.toString()})
                    .then(function(r){return r.json();})
                    .then(function(p){if(p&&p.success&&p.data){if(typeof p.data.html==='string'){live.innerHTML=p.data.html;}if(refreshed&&p.data.refreshed){refreshed.textContent=p.data.refreshed;}}});
            }
            timer=window.setInterval(load,5000);
            document.addEventListener('visibilitychange',function(){if(document.hidden){if(timer){clearInterval(timer);timer=null;}}else{load();if(!timer){timer=setInterval(load,5000);}}});
        }());
        </script>
        <?php
    }
}
