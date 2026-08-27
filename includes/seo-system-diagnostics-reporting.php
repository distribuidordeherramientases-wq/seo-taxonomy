<?php
/**
 * SEO System - Diagnostic Reporting
 *
 * Diagnóstico, correo resumido y exportación completa en PDF/JSON.
 * El correo prioriza incidencias; las descargas reúnen el estado técnico,
 * funcional y SEO sin contenidos personales.
 *
 * El envio automatico es opt-in: permanece desactivado hasta que un
 * administrador con manage_options lo habilita expresamente.
 */
defined('ABSPATH') || exit;

if (!defined('SEO_SYSTEM_DIAGNOSTICS_REPORTING_VERSION')) {
    define('SEO_SYSTEM_DIAGNOSTICS_REPORTING_VERSION', '4.3.0');
}

if (!defined('SEO_SYSTEM_DIAGNOSTICS_RECIPIENT')) {
    define('SEO_SYSTEM_DIAGNOSTICS_RECIPIENT', 'davidperezmartorell@gmail.com');
}

add_filter('cron_schedules', 'seo_system_diagnostics_cron_schedules');
add_action('seo_system_diagnostics_scheduled_report', 'seo_system_diagnostics_handle_scheduled_report');
add_action('seo_system_diagnostics_after_update_report', 'seo_system_diagnostics_handle_after_update_report');
add_action('admin_init', 'seo_system_diagnostics_admin_init');
add_action('init', 'seo_system_diagnostics_maybe_sync_schedule');
add_action('admin_notices', 'seo_system_diagnostics_maybe_render_export_panel');
add_action('seo_system_diagnostics_reporting_actions', 'seo_system_diagnostics_render_export_panel', 10, 0);
add_action('seo_system_reports_actions', 'seo_system_diagnostics_render_export_panel', 10, 0);
add_action('seo_core_system_test_after_summary', 'seo_system_diagnostics_render_export_panel', 10, 0);
add_action('admin_post_seo_system_diagnostics_refresh_report', 'seo_system_diagnostics_handle_refresh_report');
add_action('admin_post_seo_system_diagnostics_download_pdf', 'seo_system_diagnostics_handle_download_pdf');
add_action('admin_post_seo_system_diagnostics_download_json', 'seo_system_diagnostics_handle_download_json');
add_action('admin_post_seo_system_diagnostics_download_server_json', 'seo_system_diagnostics_handle_download_server_json');
add_action('admin_post_seo_system_diagnostics_download_validation_json', 'seo_system_diagnostics_handle_download_validation_json');
add_action('seo_core_system_test_completed', 'seo_system_diagnostics_handle_core_test_completed', 20, 1);


function seo_system_diagnostics_settings_option_name() {
    return 'seo_system_diagnostics_reporting_settings';
}

function seo_system_diagnostics_state_option_name() {
    return 'seo_system_diagnostics_reporting_state';
}

function seo_system_diagnostics_default_settings() {
    return array(
        'enabled'              => 0,
        'frequency'            => 'weekly',
        'send_on_change'       => 1,
        'send_recovery'        => 1,
        'send_after_update'    => 1,
        'send_after_every_run' => 1,
        'include_site_url'     => 1,
        'include_site_quality' => 1,
        'copy_to_admin'        => 1,
        'max_incidents'        => 80,
    );
}

function seo_system_diagnostics_get_settings() {
    $settings = get_option(seo_system_diagnostics_settings_option_name(), array());
    return array_merge(seo_system_diagnostics_default_settings(), is_array($settings) ? $settings : array());
}


function seo_system_diagnostics_get_state() {
    $state = get_option(seo_system_diagnostics_state_option_name(), array());
    return is_array($state) ? $state : array();
}

function seo_system_diagnostics_recipient() {
    return sanitize_email((string) apply_filters('seo_system_diagnostics_recipient', SEO_SYSTEM_DIAGNOSTICS_RECIPIENT));
}


/**
 * El consentimiento se conserva en Plugin Validation. Esta función mantiene
 * compatibilidad con instalaciones que todavía utilizan el ajuste anterior.
 */
function seo_system_diagnostics_auto_send_authorized() {
    if (function_exists('seo_core_system_test_diagnostics_consent_enabled')) {
        return seo_core_system_test_diagnostics_consent_enabled();
    }

    return (bool) get_option('seo_system_diagnostics_email_consent', false);
}

/**
 * Clave estable de una ejecución para impedir correos duplicados.
 *
 * @param array $run
 * @return string
 */
function seo_system_diagnostics_completed_run_key($run) {
    $run = is_array($run) ? $run : array();
    $metadata = isset($run['metadata']) && is_array($run['metadata'])
        ? $run['metadata']
        : array();

    $parts = array(
        sanitize_key((string) ($run['scope'] ?? 'general')),
        sanitize_key((string) ($run['origin'] ?? ($metadata['origin'] ?? 'manual'))),
        (string) ((int) ($metadata['started_at'] ?? 0)),
        (string) ((int) ($metadata['completed_at'] ?? 0)),
        (string) ($metadata['duration_seconds'] ?? ''),
        (string) ((int) ($run['result_count'] ?? 0)),
    );

    return hash('sha256', implode('|', $parts));
}

/**
 * Actualiza solo las capas complementarias. No vuelve a ejecutar Plugin
 * Validation y, por tanto, no puede provocar un bucle de envíos.
 *
 * @param string $reason
 */
function seo_system_diagnostics_refresh_supplemental_checks($reason = 'completed_run') {
    if (function_exists('seo_server_status_collect_snapshot') && function_exists('seo_server_status_store_snapshot')) {
        seo_server_status_store_snapshot(seo_server_status_collect_snapshot(true));
    }
}

/**
 * Envía el estado actualmente guardado sin volver a ejecutar la suite.
 * Puede ser utilizado por el botón manual y por el envío tras cada ejecución.
 *
 * @param string $reason
 * @param bool   $refresh_supplemental
 * @return array
 */
function seo_system_diagnostics_send_current_report($reason = 'manual_send', $refresh_supplemental = true) {
    if (!seo_system_diagnostics_auto_send_authorized()) {
        return array('sent' => false, 'message' => 'El envío de diagnósticos no está autorizado.');
    }

    if ($refresh_supplemental) {
        seo_system_diagnostics_refresh_supplemental_checks($reason);
    }

    return seo_system_diagnostics_generate_and_send($reason, false, true);
}

/**
 * Envía automáticamente un resumen después de cada ejecución completa que
 * haya sido autorizada. La clave de ejecución evita duplicados.
 *
 * @param array $run
 */
function seo_system_diagnostics_handle_core_test_completed($run) {
    if (!seo_system_diagnostics_auto_send_authorized()) {
        return;
    }

    $settings = seo_system_diagnostics_get_settings();
    if (isset($settings['send_after_every_run']) && empty($settings['send_after_every_run'])) {
        return;
    }

    if (!empty($GLOBALS['seo_system_diagnostics_internal_refresh'])) {
        return;
    }

    static $handled = array();
    $run = is_array($run) ? $run : array();
    $run_key = seo_system_diagnostics_completed_run_key($run);
    if ($run_key === '' || isset($handled[$run_key])) {
        return;
    }
    $handled[$run_key] = true;

    $state = seo_system_diagnostics_get_state();
    if (!empty($state['last_auto_run_key']) && hash_equals((string) $state['last_auto_run_key'], $run_key)) {
        return;
    }

    $origin = sanitize_key((string) ($run['origin'] ?? 'completed_run'));
    $refresh_supplemental = strpos($origin, 'manual_links_') !== 0;
    $result = seo_system_diagnostics_send_current_report('completed_' . $origin, $refresh_supplemental);

    $state = seo_system_diagnostics_get_state();
    $state['last_auto_run_key'] = $run_key;
    $state['last_auto_run_at'] = time();
    $state['last_auto_run_origin'] = $origin;
    $state['last_auto_run_sent'] = !empty($result['sent']);
    $state['last_auto_run_message'] = sanitize_text_field((string) ($result['message'] ?? ''));
    update_option(seo_system_diagnostics_state_option_name(), $state, false);
}

function seo_system_diagnostics_cron_schedules($schedules) {
    if (!isset($schedules['weekly'])) {
        $schedules['weekly'] = array('interval' => WEEK_IN_SECONDS, 'display' => 'Una vez por semana');
    }
    $schedules['monthly'] = array('interval' => 30 * DAY_IN_SECONDS, 'display' => 'Cada 30 días');
    return $schedules;
}

function seo_system_diagnostics_maybe_sync_schedule() {
    $settings = seo_system_diagnostics_get_settings();
    $stored_frequency = get_option('seo_system_diagnostics_scheduled_frequency', '');
    if (!$settings['enabled']) {
        if (wp_next_scheduled('seo_system_diagnostics_scheduled_report')) {
            wp_clear_scheduled_hook('seo_system_diagnostics_scheduled_report');
        }
        delete_option('seo_system_diagnostics_scheduled_frequency');
        return;
    }
    if ($stored_frequency !== $settings['frequency'] || !wp_next_scheduled('seo_system_diagnostics_scheduled_report')) {
        seo_system_diagnostics_sync_schedule($settings, true);
    }
}

function seo_system_diagnostics_sync_schedule($settings = null, $force = false) {
    if (!is_array($settings)) {
        $settings = seo_system_diagnostics_get_settings();
    }
    if ($force || !$settings['enabled']) {
        wp_clear_scheduled_hook('seo_system_diagnostics_scheduled_report');
    }
    if (!$settings['enabled']) {
        delete_option('seo_system_diagnostics_scheduled_frequency');
        return;
    }
    if (!wp_next_scheduled('seo_system_diagnostics_scheduled_report')) {
        wp_schedule_event(time() + 300, $settings['frequency'], 'seo_system_diagnostics_scheduled_report');
    }
    update_option('seo_system_diagnostics_scheduled_frequency', $settings['frequency'], false);
}

function seo_system_diagnostics_admin_init() {
    seo_system_diagnostics_add_privacy_policy_content();
    seo_system_diagnostics_detect_version_change();
}

function seo_system_diagnostics_add_privacy_policy_content() {
    if (!function_exists('wp_add_privacy_policy_content')) {
        return;
    }

    $text = '<p>SEO System puede enviar informes técnicos al fabricante únicamente cuando un administrador activa expresamente esta función. El informe puede incluir un identificador aleatorio de instalación, el dominio si se autoriza, versiones técnicas, estado agregado del servidor y descripciones de incidencias funcionales, comerciales o SEO. No se envían pedidos, clientes, usuarios, contraseñas, cookies, contenidos completos de tablas ni contenidos completos de logs. El destinatario configurado por esta versión es <code>' . esc_html(seo_system_diagnostics_recipient()) . '</code>. Una vez aceptado el envío automático, cada ejecución completada del diagnóstico puede enviar un resumen de incidencias; la función puede desactivarse desde el propio panel de diagnóstico.</p>';
    wp_add_privacy_policy_content('SEO System', wp_kses_post($text));
}

function seo_system_diagnostics_detect_version_change() {
    $version = seo_system_diagnostics_get_plugin_version();
    $previous = get_option('seo_system_diagnostics_observed_plugin_version', '');
    if ($version === '') {
        return;
    }
    if ($previous !== '' && version_compare($version, $previous, '!=') ) {
        $settings = seo_system_diagnostics_get_settings();
        if ($settings['enabled'] && $settings['send_after_update'] && !wp_next_scheduled('seo_system_diagnostics_after_update_report')) {
            wp_schedule_single_event(time() + 300, 'seo_system_diagnostics_after_update_report');
        }
    }
    if ($previous !== $version) {
        update_option('seo_system_diagnostics_observed_plugin_version', $version, false);
    }
}

function seo_system_diagnostics_handle_scheduled_report() {
    seo_system_diagnostics_generate_and_send('scheduled', true, false);
}

function seo_system_diagnostics_handle_after_update_report() {
    seo_system_diagnostics_generate_and_send('plugin_update', true, false);
}

function seo_system_diagnostics_generate_and_send($reason = 'scheduled', $refresh = true, $force = false) {
    $settings = seo_system_diagnostics_get_settings();
    if (!$force && !$settings['enabled']) {
        return array('sent' => false, 'message' => 'El envío automático no está autorizado.');
    }

    if ($refresh) {
        seo_system_diagnostics_refresh_checks($reason);
    }
    $payload = seo_system_diagnostics_build_payload($reason, false);
    $hash = seo_system_diagnostics_payload_hash($payload);
    $state = seo_system_diagnostics_get_state();

    if (!$force && !seo_system_diagnostics_should_send($payload, $hash, $state, $settings, $reason)) {
        $state['last_checked_at'] = time();
        $state['last_message'] = 'Sin cambios que requieran correo.';
        update_option(seo_system_diagnostics_state_option_name(), $state, false);
        return array('sent' => false, 'skipped' => true, 'message' => 'Sin cambios que requieran correo.');
    }

    $recipients = array(seo_system_diagnostics_recipient());
    if ($settings['copy_to_admin']) {
        $admin_email = sanitize_email((string) get_option('admin_email'));
        if ($admin_email !== '') {
            $recipients[] = $admin_email;
        }
    }
    $recipients = array_values(array_unique(array_filter($recipients, 'is_email')));
    if (empty($recipients)) {
        return array('sent' => false, 'message' => 'No hay destinatarios válidos.');
    }

    $subject = seo_system_diagnostics_build_subject($payload);
    $body = seo_system_diagnostics_build_email_body($payload);
    $headers = array('Content-Type: text/plain; charset=UTF-8');
    $sent = wp_mail($recipients, $subject, $body, $headers);

    $state['last_checked_at'] = time();
    $state['last_attempt_at'] = time();
    $state['last_reason'] = $reason;
    $state['last_message'] = $sent ? 'Informe enviado.' : 'wp_mail() devolvió false.';
    if ($sent) {
        $state['last_sent_at'] = time();
        $state['last_hash'] = $hash;
        $state['last_incident_count'] = (int) ($payload['summary']['total_incidents'] ?? 0);
        $state['last_overall'] = (string) ($payload['summary']['overall'] ?? 'info');
        $history = isset($state['history']) && is_array($state['history']) ? $state['history'] : array();
        $history[] = array(
            'sent_at' => time(),
            'reason' => $reason,
            'overall' => $state['last_overall'],
            'incidents' => $state['last_incident_count'],
            'hash' => $hash,
        );
        $state['history'] = array_slice($history, -30);
    }
    update_option(seo_system_diagnostics_state_option_name(), $state, false);

    return array('sent' => (bool) $sent, 'message' => $state['last_message'], 'payload' => $payload);
}

function seo_system_diagnostics_refresh_checks($reason) {
    if (function_exists('seo_server_status_collect_snapshot') && function_exists('seo_server_status_store_snapshot')) {
        seo_server_status_store_snapshot(seo_server_status_collect_snapshot(true));
    }

    if (function_exists('seo_core_system_test_run_telemetry_suite')) {
        $previous_internal_refresh = !empty($GLOBALS['seo_system_diagnostics_internal_refresh']);
        $GLOBALS['seo_system_diagnostics_internal_refresh'] = true;
        try {
            seo_core_system_test_run_telemetry_suite($reason === 'plugin_update');
        } finally {
            $GLOBALS['seo_system_diagnostics_internal_refresh'] = $previous_internal_refresh;
        }
    }

    if (
        function_exists('seo_core_system_test_run_link_audit_phase') &&
        function_exists('seo_core_system_test_next_link_audit_phase')
    ) {
        if (in_array($reason, array('manual', 'plugin_update'), true)) {
            foreach (array('links', 'seo', 'resources') as $phase) {
                seo_core_system_test_run_link_audit_phase($phase);
            }
        } else {
            $phase = seo_core_system_test_next_link_audit_phase();
            seo_core_system_test_run_link_audit_phase($phase);
        }
    }
}

/**
 * Combina la salud del servidor y la del plugin sin contar dos veces la
 * capa semántica, porque esta ya forma parte del snapshot de SEO Core.
 *
 * @param array $server_health
 * @param array $core_health
 * @return array
 */
function seo_system_diagnostics_merge_health_summaries($server_health, $core_health) {
    $server_health = is_array($server_health) ? $server_health : array();
    $core_health = is_array($core_health) ? $core_health : array();

    $counts = array(
        'error'     => (int) ($server_health['error'] ?? 0) + (int) ($core_health['critical'] ?? 0),
        'important' => (int) ($server_health['important'] ?? 0) + (int) ($core_health['important'] ?? 0),
        'warning'   => (int) ($server_health['warning'] ?? 0) + (int) ($core_health['warning'] ?? 0),
        'ok'        => (int) ($server_health['ok'] ?? 0) + (int) ($core_health['ok'] ?? 0),
        'info'      => (int) ($server_health['info'] ?? 0) + (int) ($core_health['info'] ?? 0),
    );

    $total = $counts['error'] + $counts['important'] + $counts['warning'] + $counts['ok'];
    $penalty = ($counts['error'] * 5) + ($counts['important'] * 3) + $counts['warning'];
    $score = $total > 0
        ? max(0, (int) round(100 - (($penalty / ($total * 5)) * 100)))
        : null;
    $status = $counts['error'] > 0
        ? 'error'
        : ($counts['important'] > 0
            ? 'important'
            : ($counts['warning'] > 0
                ? 'warning'
                : ($counts['ok'] > 0 ? 'ok' : 'info')));

    return array_merge($counts, array(
        'total'  => $total,
        'score'  => $score,
        'status' => $status,
    ));
}

/**
 * Suma la tendencia de ambos orígenes. Los IDs pertenecen a espacios de
 * nombres distintos (SERVER y CORE), por lo que no se pisan entre sí.
 *
 * @param array $server_trend
 * @param array $core_trend
 * @return array
 */
function seo_system_diagnostics_merge_trends($server_trend, $core_trend) {
    $server_trend = is_array($server_trend) ? $server_trend : array();
    $core_trend = is_array($core_trend) ? $core_trend : array();

    return array(
        'new'      => (int) ($server_trend['new'] ?? 0) + (int) ($core_trend['new'] ?? 0),
        'resolved' => (int) ($server_trend['resolved'] ?? 0) + (int) ($core_trend['resolved'] ?? 0),
    );
}

function seo_system_diagnostics_build_payload($reason = 'preview', $refresh = false) {
    $settings = seo_system_diagnostics_get_settings();
    $server = function_exists('seo_server_status_get_reporting_snapshot')
        ? seo_server_status_get_reporting_snapshot($refresh)
        : array('health' => array(), 'checks' => array(), 'generated_at' => 0);
    $core = function_exists('seo_core_system_test_get_reporting_snapshot')
        ? seo_core_system_test_get_reporting_snapshot()
        : array('health' => array(), 'incidents' => array(), 'generated_at' => 0);

    $incidents = array();
    foreach ((array) ($server['checks'] ?? array()) as $check) {
        $status = $check['status'] ?? 'info';
        if (!in_array($status, array('error', 'important', 'warning'), true)) {
            continue;
        }
        $incidents[] = array(
            'id' => (string) ($check['code'] ?? 'SERVER'),
            'source' => 'server',
            'impact' => $status,
            'label' => (string) ($check['label'] ?? ''),
            'detail' => trim((string) ($check['value'] ?? '') . ((string) ($check['detail'] ?? '') !== '' ? ' · ' . (string) $check['detail'] : '')),
            'url' => '',
            'origin' => '',
        );
    }

    foreach ((array) ($core['incidents'] ?? array()) as $incident) {
        if (!$settings['include_site_quality'] && seo_system_diagnostics_is_site_quality_incident($incident)) {
            continue;
        }
        $incidents[] = array(
            'id' => (string) ($incident['id'] ?? 'CORE'),
            'source' => 'core',
            'impact' => (string) ($incident['impact'] ?? 'warning'),
            'label' => (string) ($incident['label'] ?? ''),
            'detail' => (string) ($incident['detail'] ?? ''),
            'url' => (string) ($incident['url'] ?? ''),
            'origin' => (string) ($incident['origin'] ?? ''),
        );
    }

    $incidents = seo_system_diagnostics_compact_email_incidents($incidents);
    $incidents = array_slice(array_map('seo_system_diagnostics_sanitize_incident', $incidents), 0, (int) $settings['max_incidents']);
    $counts = array('error' => 0, 'important' => 0, 'warning' => 0);
    foreach ($incidents as $incident) {
        $impact = $incident['impact'];
        if ($impact === 'critical') {
            $impact = 'error';
        }
        if (isset($counts[$impact])) {
            $counts[$impact]++;
        }
    }
    $overall = $counts['error'] > 0 ? 'error' : ($counts['important'] > 0 ? 'important' : ($counts['warning'] > 0 ? 'warning' : 'ok'));
    $global_health = seo_system_diagnostics_merge_health_summaries(
        (array) ($server['health'] ?? array()),
        (array) ($core['health'] ?? array())
    );
    $global_trend = seo_system_diagnostics_merge_trends(
        (array) ($server['trend'] ?? array()),
        (array) ($core['trend'] ?? array())
    );

    $domain = wp_parse_url(home_url('/'), PHP_URL_HOST);
    $context = array(
        'installation_id' => seo_system_diagnostics_get_installation_id(),
        'site_domain' => $settings['include_site_url'] ? (string) $domain : '',
        'plugin_version' => seo_system_diagnostics_get_plugin_version(),
        'reporting_version' => SEO_SYSTEM_DIAGNOSTICS_REPORTING_VERSION,
        'wordpress_version' => get_bloginfo('version'),
        'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : '',
        'php_version' => PHP_VERSION,
        'locale' => get_locale(),
        'multisite' => is_multisite(),
    );

    return array(
        'schema_version' => 1,
        'report_id' => wp_generate_uuid4(),
        'generated_at' => time(),
        'reason' => sanitize_key($reason),
        'context' => $context,
        'summary' => array(
            'overall' => $overall,
            'incidents' => $counts,
            'total_incidents' => array_sum($counts),
            'global_health' => $global_health,
            'global_trend' => $global_trend,
            'server_health' => $server['health'] ?? array(),
            'core_health' => $core['health'] ?? array(),
            'server_generated_at' => (int) ($server['generated_at'] ?? 0),
            'core_generated_at' => (int) ($core['generated_at'] ?? 0),
        ),
        'incidents' => $incidents,
        'privacy' => array(
            'site_url_authorized' => (bool) $settings['include_site_url'],
            'site_quality_authorized' => (bool) $settings['include_site_quality'],
            'excluded' => 'usuarios, clientes, pedidos, direcciones, contraseñas, cookies, filas de tablas y logs completos',
        ),
    );
}

function seo_system_diagnostics_is_site_quality_incident($incident) {
    $group = (string) ($incident['group'] ?? '');
    $label = (string) ($incident['label'] ?? '');
    if ($group === 'links_404' || $group === 'catalog') {
        return true;
    }
    if ($group === 'functional' && preg_match('/^8\.(1[4-9]|2[0-8])\s/', $label)) {
        return true;
    }
    return false;
}

function seo_system_diagnostics_compact_email_incidents($incidents) {
    $unique = array();

    foreach ((array) $incidents as $incident) {
        if (!is_array($incident)) {
            continue;
        }

        $source = sanitize_key((string) ($incident['source'] ?? 'core'));
        $id = sanitize_text_field((string) ($incident['id'] ?? 'INCIDENT'));
        $impact = sanitize_key((string) ($incident['impact'] ?? 'warning'));
        $key = $source . '|' . $id . '|' . $impact;

        if (!isset($unique[$key])) {
            $unique[$key] = $incident;
            continue;
        }

        // Si llega la misma incidencia dos veces, conserva la evidencia más útil.
        $current_detail = (string) ($unique[$key]['detail'] ?? '');
        $candidate_detail = (string) ($incident['detail'] ?? '');
        if (strlen($candidate_detail) > strlen($current_detail)) {
            $unique[$key] = array_merge($unique[$key], $incident);
        }
    }

    return array_values($unique);
}

function seo_system_diagnostics_sanitize_incident($incident) {
    $impact = (string) ($incident['impact'] ?? 'warning');
    if ($impact === 'critical') $impact = 'error';
    if (!in_array($impact, array('error', 'important', 'warning'), true)) $impact = 'warning';
    return array(
        'id' => sanitize_text_field((string) ($incident['id'] ?? 'INCIDENT')),
        'source' => sanitize_key((string) ($incident['source'] ?? 'core')),
        'area' => sanitize_key((string) ($incident['area'] ?? $incident['source'] ?? 'core')),
        'impact' => $impact,
        'label' => seo_system_diagnostics_sanitize_text((string) ($incident['label'] ?? ''), 180),
        'detail' => seo_system_diagnostics_sanitize_text((string) ($incident['detail'] ?? ''), 700),
        'url' => seo_system_diagnostics_sanitize_url((string) ($incident['url'] ?? '')),
        'origin' => seo_system_diagnostics_sanitize_text((string) ($incident['origin'] ?? ''), 350),
        'owner' => seo_system_diagnostics_owner_for_incident($incident),
        'root_cause_id' => sanitize_key((string) ($incident['root_cause_id'] ?? '')),
        'coverage' => max(0, min(100, (int) ($incident['coverage'] ?? 100))),
        'confidence' => max(0, min(100, (int) ($incident['confidence'] ?? 90))),
    );
}


function seo_system_diagnostics_sanitize_text($text, $limit = 700) {
    $text = wp_strip_all_tags((string) $text);
    $replacements = array();
    if (defined('ABSPATH')) {
        $replacements[wp_normalize_path(ABSPATH)] = '[ABSPATH]/';
        $replacements[ABSPATH] = '[ABSPATH]/';
    }
    if (defined('WP_CONTENT_DIR')) {
        $replacements[wp_normalize_path(WP_CONTENT_DIR)] = '[WP_CONTENT]/';
        $replacements[WP_CONTENT_DIR] = '[WP_CONTENT]/';
    }
    if (defined('WP_PLUGIN_DIR')) {
        $replacements[wp_normalize_path(WP_PLUGIN_DIR)] = '[PLUGINS]/';
        $replacements[WP_PLUGIN_DIR] = '[PLUGINS]/';
    }
    $text = strtr($text, $replacements);
    $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email oculto]', $text);
    $text = preg_replace_callback('#https?://[^\s|,)]+#i', static function ($matches) {
        return seo_system_diagnostics_sanitize_url($matches[0]);
    }, $text);
    $text = preg_replace('/\s+/', ' ', trim($text));
    if (function_exists('mb_strlen') && mb_strlen($text) > $limit) {
        return mb_substr($text, 0, $limit - 3) . '...';
    }
    if (strlen($text) > $limit) {
        return substr($text, 0, $limit - 3) . '...';
    }
    return $text;
}

function seo_system_diagnostics_sanitize_url($url) {
    $url = esc_url_raw((string) $url);
    if ($url === '') {
        return '';
    }
    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }
    $clean = (!empty($parts['scheme']) ? $parts['scheme'] : 'https') . '://' . $parts['host'];
    if (!empty($parts['port'])) {
        $clean .= ':' . (int) $parts['port'];
    }
    $clean .= !empty($parts['path']) ? $parts['path'] : '/';
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
        $safe = array();
        foreach (array('page_id', 'p', 'post_type') as $key) {
            if (isset($query[$key]) && is_scalar($query[$key])) {
                $safe[$key] = sanitize_text_field((string) $query[$key]);
            }
        }
        if (!empty($safe)) {
            $clean = add_query_arg($safe, $clean);
        }
    }
    return $clean;
}

function seo_system_diagnostics_get_installation_id() {
    $id = get_option('seo_system_diagnostics_installation_id', '');
    if (!is_string($id) || $id === '') {
        $id = wp_generate_uuid4();
        update_option('seo_system_diagnostics_installation_id', $id, false);
    }
    return $id;
}

function seo_system_diagnostics_get_plugin_version() {
    foreach (array('SEO_SYSTEM_VERSION', 'SEO_PLUGIN_VERSION', 'SEO_SYSTEM_PLUGIN_VERSION') as $constant) {
        if (defined($constant) && constant($constant) !== '') {
            return (string) constant($constant);
        }
    }

    foreach (array('SEO_SYSTEM_PLUGIN_FILE', 'SEO_SYSTEM_FILE', 'SEO_PLUGIN_FILE') as $constant) {
        if (!defined($constant) || !is_file(constant($constant))) {
            continue;
        }
        if (!function_exists('get_file_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $data = get_file_data(constant($constant), array('Version' => 'Version'), 'plugin');
        if (!empty($data['Version'])) {
            return (string) $data['Version'];
        }
    }

    $root = function_exists('seo_core_system_test_get_plugin_root')
        ? seo_core_system_test_get_plugin_root()
        : dirname(__FILE__);
    foreach ((array) glob(trailingslashit($root) . '*.php') as $candidate) {
        if (!is_readable($candidate)) {
            continue;
        }
        $header = file_get_contents($candidate, false, null, 0, 8192);
        if (!is_string($header) || stripos($header, 'Plugin Name:') === false) {
            continue;
        }
        if (!preg_match('/^[ \t\/*#@]*Version:\s*(.+)$/mi', $header, $match)) {
            continue;
        }
        $version = trim(preg_replace('/\s*(?:\*\/|\*).*$/', '', $match[1]));
        if ($version !== '') {
            return $version;
        }
    }

    return (string) apply_filters('seo_system_diagnostics_plugin_version', 'desconocida');
}

function seo_system_diagnostics_payload_hash($payload) {
    $fingerprint = array(
        'context' => array(
            'plugin_version' => $payload['context']['plugin_version'] ?? '',
            'wordpress_version' => $payload['context']['wordpress_version'] ?? '',
            'woocommerce_version' => $payload['context']['woocommerce_version'] ?? '',
            'php_version' => $payload['context']['php_version'] ?? '',
        ),
        'overall' => $payload['summary']['overall'] ?? 'info',
        'incidents' => array_map(static function ($incident) {
            return array($incident['id'], $incident['impact'], $incident['label'], $incident['url']);
        }, (array) ($payload['incidents'] ?? array())),
    );
    return hash('sha256', wp_json_encode($fingerprint));
}

function seo_system_diagnostics_should_send($payload, $hash, $state, $settings, $reason) {
    if ($reason === 'plugin_update' && $settings['send_after_update']) {
        return true;
    }
    if (empty($state['last_hash'])) {
        return true;
    }
    $current_count = (int) ($payload['summary']['total_incidents'] ?? 0);
    $previous_count = (int) ($state['last_incident_count'] ?? 0);
    if ($current_count === 0 && $previous_count > 0) {
        return (bool) $settings['send_recovery'];
    }
    if (!$settings['send_on_change']) {
        return true;
    }
    return !hash_equals((string) $state['last_hash'], (string) $hash);
}

function seo_system_diagnostics_build_subject($payload) {
    $labels = array('error' => 'CRÍTICO', 'important' => 'IMPORTANTE', 'warning' => 'AVISOS', 'ok' => 'OK', 'info' => 'PENDIENTE');
    $overall = $payload['summary']['overall'] ?? 'info';
    $site = $payload['context']['site_domain'] ?: substr($payload['context']['installation_id'], 0, 8);
    $count = (int) ($payload['summary']['total_incidents'] ?? 0);
    return '[SEO System][' . ($labels[$overall] ?? 'INFO') . '] ' . $site . ' · ' . $count . ' incidencias · v' . $payload['context']['plugin_version'];
}

function seo_system_diagnostics_build_email_body($payload) {
    $context = $payload['context'];
    $summary = $payload['summary'];
    $lines = array();
    $lines[] = 'SEO SYSTEM - INFORME TÉCNICO';
    $lines[] = str_repeat('=', 52);
    $lines[] = 'Informe: ' . $payload['report_id'];
    $lines[] = 'Fecha: ' . date_i18n('Y-m-d H:i:s', (int) $payload['generated_at']);
    $lines[] = 'Motivo: ' . $payload['reason'];
    $lines[] = 'Instalación: ' . $context['installation_id'];
    if ($context['site_domain'] !== '') {
        $lines[] = 'Dominio: ' . $context['site_domain'];
    }
    $lines[] = '';
    $lines[] = 'ENTORNO';
    $lines[] = '- SEO System: ' . $context['plugin_version'];
    $lines[] = '- Módulo de informes: ' . $context['reporting_version'];
    $lines[] = '- WordPress: ' . $context['wordpress_version'];
    $lines[] = '- WooCommerce: ' . ($context['woocommerce_version'] !== '' ? $context['woocommerce_version'] : 'no detectado');
    $lines[] = '- PHP: ' . $context['php_version'];
    $lines[] = '- Locale: ' . $context['locale'];
    $lines[] = '- Multisite: ' . ($context['multisite'] ? 'sí' : 'no');
    $lines[] = '';
    $lines[] = 'RESUMEN';
    $lines[] = '- Estado: ' . strtoupper((string) $summary['overall']);
    $lines[] = '- Críticos: ' . (int) ($summary['incidents']['error'] ?? 0);
    $lines[] = '- Importantes: ' . (int) ($summary['incidents']['important'] ?? 0);
    $lines[] = '- Avisos: ' . (int) ($summary['incidents']['warning'] ?? 0);
    if (!empty($summary['server_health'])) {
        $lines[] = '- Salud servidor: ' . (int) ($summary['server_health']['score'] ?? 0) . '%';
    }
    if (!empty($summary['core_health'])) {
        $lines[] = '- Salud funcional: ' . (int) ($summary['core_health']['score'] ?? 0) . '%';
    }
    $lines[] = '';
    $lines[] = 'INCIDENCIAS';
    if (empty($payload['incidents'])) {
        $lines[] = '- No hay incidencias ni avisos en los chequeos disponibles.';
    } else {
        foreach ($payload['incidents'] as $incident) {
            $line = '[' . strtoupper($incident['impact']) . '] ' . $incident['id'] . ' · ' . $incident['label'];
            if ($incident['detail'] !== '') {
                $line .= ' · ' . $incident['detail'];
            }
            if ($incident['url'] !== '') {
                $line .= ' · URL: ' . $incident['url'];
            }
            if ($incident['origin'] !== '') {
                $line .= ' · Origen: ' . $incident['origin'];
            }
            $lines[] = '- ' . $line;
        }
    }
    $lines[] = '';
    $lines[] = 'PRIVACIDAD';
    $lines[] = '- No contiene usuarios, clientes, pedidos, direcciones, contraseñas, cookies, filas de tablas ni logs completos.';
    $lines[] = '- El dominio solo aparece cuando el administrador lo ha autorizado.';
    $lines[] = '- Las URLs conservan únicamente parámetros técnicos permitidos como page_id o p.';
    return implode("\n", $lines);
}

/**
 * -------------------------------------------------------------------------
 * Informe descargable: panel, PDF y JSON.
 * -------------------------------------------------------------------------
 */
function seo_system_diagnostics_current_reporting_url($args = array()) {
    global $pagenow;

    $base = admin_url($pagenow ? $pagenow : 'admin.php');
    $preserve = array();
    foreach (array('page', 'tab', 'section', 'view', 'seo_core_test_tab') as $key) {
        if (isset($_GET[$key]) && is_scalar($_GET[$key])) {
            $preserve[$key] = sanitize_text_field(wp_unslash($_GET[$key]));
        }
    }

    return add_query_arg(
        array_merge($preserve, is_array($args) ? $args : array()),
        $base
    );
}

function seo_system_diagnostics_report_admin_url($args = array()) {
    $default = seo_system_diagnostics_current_reporting_url();
    $url = (string) apply_filters('seo_system_diagnostics_reporting_admin_url', $default);
    if ($url === '') {
        $url = $default;
    }
    return add_query_arg(is_array($args) ? $args : array(), $url);
}

function seo_system_diagnostics_is_reporting_screen() {
    if (!is_admin() || !current_user_can('manage_options')) {
        return false;
    }

    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    $known = (array) apply_filters(
        'seo_system_diagnostics_reporting_screen_slugs',
        array(
            'seo-system-diagnostics',
            'seo-system-reporting',
            'seo-system-reports',
            'seo-system-status',
            'seo-system-telemetry',
            'seo-menu-diagnostics',
            'seo-menu-system-test',
            'seo-menu-reports',
        )
    );

    if ($page !== '' && in_array($page, $known, true)) {
        return true;
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $screen_id = is_object($screen) && isset($screen->id) ? (string) $screen->id : '';
    $screen_base = is_object($screen) && isset($screen->base) ? (string) $screen->base : '';
    $haystack = strtolower($page . ' ' . $screen_id . ' ' . $screen_base);

    if (strpos($haystack, 'seo') === false) {
        return false;
    }

    return (bool) preg_match('/report|diagnostic|telemetr|system[-_ ]?test|health|status|informe|calidad/', $haystack);
}

function seo_system_diagnostics_is_core_validation_screen() {
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    if (isset($_GET['seo_core_test_tab'])) {
        return true;
    }

    return in_array($page, array('seo-menu-system-test', 'seo-core-validation', 'seo-plugin-validation', 'seo-system-test'), true)
        || (bool) preg_match('/core.*(?:validation|test)|plugin.*validation|system[-_]?test/', $page);
}

function seo_system_diagnostics_core_active_tab() {
    $tab = isset($_GET['seo_core_test_tab']) ? sanitize_key(wp_unslash($_GET['seo_core_test_tab'])) : 'summary';
    return $tab !== '' ? $tab : 'summary';
}

function seo_system_diagnostics_maybe_render_export_panel() {
    if (!seo_system_diagnostics_is_reporting_screen()) {
        return;
    }

    if (seo_system_diagnostics_is_core_validation_screen()) {
        return;
    }

    seo_system_diagnostics_render_export_panel();
}

function seo_system_diagnostics_get_return_url() {
    $fallback = seo_system_diagnostics_report_admin_url();
    if (!empty($_REQUEST['redirect_to']) && is_scalar($_REQUEST['redirect_to'])) {
        $requested = esc_url_raw(wp_unslash($_REQUEST['redirect_to']));
        return wp_validate_redirect($requested, $fallback);
    }
    $referer = wp_get_referer();
    return $referer ? wp_validate_redirect($referer, $fallback) : $fallback;
}



function seo_system_diagnostics_export_recursive($value, $depth = 0, $key = '') {
    if ($depth > 7) {
        return '[profundidad limitada]';
    }

    $key = strtolower((string) $key);
    if ($key !== '' && preg_match('/password|passwd|secret|token|nonce|cookie|session|authorization|api[_-]?key|private[_-]?key|customer|billing|shipping|order[_-]?data|user[_-]?(?:email|login|pass)/', $key)) {
        return '[dato excluido]';
    }

    if (is_object($value)) {
        $value = (array) $value;
    }
    if (is_array($value)) {
        $clean = array();
        $count = 0;
        foreach ($value as $child_key => $child_value) {
            if ($count >= 300) {
                $clean['_truncated'] = true;
                break;
            }
            $safe_key = is_int($child_key) ? $child_key : sanitize_key((string) $child_key);
            $clean[$safe_key] = seo_system_diagnostics_export_recursive($child_value, $depth + 1, (string) $child_key);
            $count++;
        }
        return $clean;
    }
    if (is_bool($value) || is_int($value) || is_float($value) || null === $value) {
        return $value;
    }

    $text = (string) $value;
    if (preg_match('#^https?://#i', $text)) {
        return seo_system_diagnostics_sanitize_url($text);
    }
    return seo_system_diagnostics_sanitize_text($text, 1400);
}

function seo_system_diagnostics_collect_site_inventory() {
    $inventory = array('content' => array(), 'taxonomies' => array());
    foreach (array('product', 'page', 'post', 'attachment') as $post_type) {
        if (!post_type_exists($post_type)) {
            continue;
        }
        $counts = wp_count_posts($post_type);
        $row = array();
        foreach (array('publish', 'draft', 'pending', 'private', 'trash') as $status) {
            $row[$status] = isset($counts->$status) ? (int) $counts->$status : 0;
        }
        $row['total'] = array_sum($row);
        $inventory['content'][$post_type] = $row;
    }

    foreach (array('product_cat', 'product_tag', 'category', 'post_tag') as $taxonomy) {
        if (!taxonomy_exists($taxonomy)) {
            continue;
        }
        $count = wp_count_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false));
        $inventory['taxonomies'][$taxonomy] = is_wp_error($count) ? null : (int) $count;
    }

    $theme = wp_get_theme();
    $inventory['theme'] = array(
        'name'    => sanitize_text_field((string) $theme->get('Name')),
        'version' => sanitize_text_field((string) $theme->get('Version')),
        'parent'  => $theme->parent() ? sanitize_text_field((string) $theme->parent()->get('Name')) : '',
    );
    $inventory['permalink_structure'] = sanitize_text_field((string) get_option('permalink_structure', ''));
    $inventory['blog_public'] = (int) get_option('blog_public', 1);
    return $inventory;
}

function seo_system_diagnostics_collect_components() {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $plugins = get_plugins();
    $active = (array) get_option('active_plugins', array());
    if (is_multisite()) {
        $active = array_values(array_unique(array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', array())))));
    }
    $items = array();
    foreach ($active as $plugin_file) {
        if (!isset($plugins[$plugin_file])) {
            continue;
        }
        $items[] = array(
            'file'    => sanitize_text_field((string) $plugin_file),
            'name'    => sanitize_text_field((string) ($plugins[$plugin_file]['Name'] ?? $plugin_file)),
            'version' => sanitize_text_field((string) ($plugins[$plugin_file]['Version'] ?? '')),
        );
    }
    usort($items, static function ($a, $b) {
        return strcasecmp((string) $a['name'], (string) $b['name']);
    });
    return array('active_count' => count($items), 'active_plugins' => $items);
}

function seo_system_diagnostics_collect_scheduler_snapshot() {
    global $wpdb;
    $actions = $wpdb->prefix . 'actionscheduler_actions';
    $logs = $wpdb->prefix . 'actionscheduler_logs';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($actions)));
    if ((string) $exists !== $actions) {
        return array('available' => false);
    }

    $status_rows = (array) $wpdb->get_results(
        "SELECT status, COUNT(*) AS total, MIN(scheduled_date_gmt) AS first_date, MAX(scheduled_date_gmt) AS last_date
         FROM {$actions}
         GROUP BY status
         ORDER BY status ASC",
        ARRAY_A
    );
    $statuses = array();
    foreach ($status_rows as $row) {
        $statuses[sanitize_key((string) $row['status'])] = array(
            'total'      => (int) $row['total'],
            'first_date' => sanitize_text_field((string) $row['first_date']),
            'last_date'  => sanitize_text_field((string) $row['last_date']),
        );
    }

    $overdue = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$actions}
         WHERE status = 'pending' AND scheduled_date_gmt <= UTC_TIMESTAMP()"
    );
    $hooks = (array) $wpdb->get_results(
        "SELECT hook, status, COUNT(*) AS total,
                MIN(scheduled_date_gmt) AS first_date,
                MAX(scheduled_date_gmt) AS last_date
         FROM {$actions}
         WHERE status IN ('pending', 'failed')
         GROUP BY hook, status
         ORDER BY status DESC, total DESC
         LIMIT 30",
        ARRAY_A
    );
    $top_hooks = array();
    foreach ($hooks as $row) {
        $top_hooks[] = array(
            'hook'       => sanitize_text_field((string) $row['hook']),
            'status'     => sanitize_key((string) $row['status']),
            'total'      => (int) $row['total'],
            'first_date' => sanitize_text_field((string) $row['first_date']),
            'last_date'  => sanitize_text_field((string) $row['last_date']),
        );
    }

    $log_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($logs)));
    $log_count = (string) $log_exists === $logs ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$logs}") : null;

    return array(
        'available'       => true,
        'statuses'        => $statuses,
        'overdue_pending' => $overdue,
        'top_hooks'       => $top_hooks,
        'log_rows'        => $log_count,
    );
}

function seo_system_diagnostics_collect_sitemap_snapshot() {
    $cache_key = 'seo_system_diagnostics_sitemap_snapshot_v2';
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }

    $urls = (array) apply_filters(
        'seo_system_diagnostics_sitemap_urls',
        array(home_url('/sitemap.xml'), home_url('/wp-sitemap.xml'))
    );
    $result = array();
    foreach (array_values(array_unique(array_filter(array_map('esc_url_raw', $urls)))) as $url) {
        $response = wp_safe_remote_get($url, array(
            'timeout'             => 5,
            'redirection'         => 3,
            'limit_response_size' => 180000,
            'headers'             => array(
                'Accept'     => 'application/xml,text/xml,*/*;q=0.2',
                'User-Agent' => 'SEO-System-Sitemap-Check/' . SEO_SYSTEM_DIAGNOSTICS_REPORTING_VERSION,
            ),
        ));
        if (is_wp_error($response)) {
            $result[] = array('url' => $url, 'http_code' => 0, 'valid_xml_root' => false, 'error' => $response->get_error_message());
            continue;
        }
        $body = (string) wp_remote_retrieve_body($response);
        $code = (int) wp_remote_retrieve_response_code($response);
        $content_type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        $root = '';
        if (preg_match('/<(sitemapindex|urlset)\b/i', $body, $match)) {
            $root = strtolower($match[1]);
        }
        $result[] = array(
            'url'            => $url,
            'http_code'      => $code,
            'content_type'   => sanitize_text_field($content_type),
            'root'           => $root,
            'valid_xml_root' => $code >= 200 && $code < 400 && $root !== '',
            'entries'        => substr_count($body, '<url>') + substr_count($body, '<sitemap>'),
        );
    }
    set_transient($cache_key, $result, 15 * MINUTE_IN_SECONDS);
    return $result;
}


function seo_system_diagnostics_owner_for_incident($incident) {
    $owner = (string) ($incident['owner'] ?? '');
    if (in_array($owner, array('hosting', 'WP', 'Woo', 'SEO', 'contenido'), true)) {
        return $owner;
    }

    $text = strtolower(remove_accents((string) ($incident['label'] ?? '') . ' ' . (string) ($incident['detail'] ?? '') . ' ' . (string) ($incident['source'] ?? '')));
    if (preg_match('/http 5|503|servidor|ssl|dns|timeout|memoria php/', $text)) return 'hosting';
    if (preg_match('/carrito|checkout|comprable|vendible|stock|precio|pago|woocommerce/', $text)) return 'Woo';
    if (preg_match('/sitemap|canonical|robots|json-ld|indexa|enlace|redirec|cluster|hub|etiqueta/', $text)) return 'SEO';
    if (preg_match('/faq|descripcion|contenido|categoria sin/', $text)) return 'contenido';
    return 'WP';
}

function seo_system_diagnostics_score_or_null($value) {
    return $value === null ? null : max(0, min(100, (int) $value));
}

function seo_system_diagnostics_build_root_causes(&$incidents, $sitemaps) {
    $causes = array();

    $invalid_sitemaps = array_values(array_filter((array) $sitemaps, static function ($row) {
        return empty($row['valid_xml_root']) && (int) ($row['http_code'] ?? 0) === 200;
    }));
    if (!empty($invalid_sitemaps)) {
        $causes[] = array(
            'id' => 'root_sitemap_invalid_xml',
            'severity' => 'important',
            'title' => 'Los sitemaps responden, pero no contienen XML válido',
            'owner' => 'SEO',
            'business_impact' => array('Los buscadores pueden no descubrir o actualizar correctamente las URLs.'),
            'evidence' => array('affected_total' => count($invalid_sitemaps), 'examples' => array_slice($invalid_sitemaps, 0, 5)),
            'verification' => 'Validar la raíz sitemapindex o urlset y comprobar los sub-sitemaps.',
        );
    }

    $unavailable_sitemaps = array_values(array_filter((array) $sitemaps, static function ($row) {
        $code = (int) ($row['http_code'] ?? 0);
        return $code === 0 || $code >= 400;
    }));
    if (!empty($unavailable_sitemaps)) {
        $causes[] = array(
            'id' => 'root_sitemap_unavailable',
            'severity' => 'important',
            'title' => 'Hay sitemaps que no responden correctamente',
            'owner' => 'SEO',
            'business_impact' => array('Los buscadores pueden encontrar dificultades para descubrir o actualizar URLs.'),
            'evidence' => array('affected_total' => count($unavailable_sitemaps), 'examples' => array_slice($unavailable_sitemaps, 0, 5)),
            'verification' => 'Comprobar que los sitemaps públicos responden correctamente y repetir el diagnóstico.',
        );
    }

    $debug = array_values(array_filter($incidents, static function ($incident) {
        return stripos((string) ($incident['label'] ?? ''), 'WP_DEBUG') !== false;
    }));
    if (!empty($debug)) {
        $causes[] = array(
            'id' => 'root_wp_debug_production',
            'severity' => 'warning',
            'title' => 'Depuración activa en producción',
            'owner' => 'WP',
            'business_impact' => array('Puede exponer avisos y aumentar ruido o consumo.'),
            'evidence' => array('incidents' => count($debug)),
            'verification' => 'Comprobar WP_DEBUG=false y ejecutar de nuevo el diagnóstico.',
        );
    }

    return $causes;
}

function seo_system_diagnostics_build_executive_summary($payload) {
    $root_causes = (array) ($payload['root_causes'] ?? array());
    $top = array();
    foreach (array_slice($root_causes, 0, 4) as $cause) {
        $top[] = (string) ($cause['title'] ?? '');
    }
    if (empty($top)) {
        foreach (array_slice((array) ($payload['incidents'] ?? array()), 0, 4) as $incident) {
            $top[] = (string) ($incident['label'] ?? '');
        }
    }

    $headline = !empty($root_causes)
        ? (string) ($root_causes[0]['title'] ?? 'Existen problemas prioritarios.')
        : 'No se han detectado causas raíz críticas en los chequeos disponibles.';

    return array(
        'overall_status' => (string) ($payload['summary']['overall'] ?? 'info'),
        'headline' => $headline,
        'report_confidence' => 'high',
        'confidence_reason' => 'El resumen se basa en los chequeos técnicos, funcionales, comerciales, SEO y semánticos disponibles.',
        'root_causes' => count($root_causes),
        'symptoms_grouped' => max(0, count((array) ($payload['incidents'] ?? array())) - count($root_causes)),
        'top_priorities' => array_values(array_filter($top)),
        'sellability_status' => (string) ($payload['sellability']['status'] ?? 'unknown'),
        'indexation_status' => (string) ($payload['indexation']['status'] ?? 'unknown'),
        'semantic_status' => (string) ($payload['semantic_health']['status'] ?? 'unknown'),
    );
}

function seo_system_diagnostics_extract_area_checks($core, $area) {
    return array_values(array_filter((array) ($core['checks'] ?? array()), static function ($check) use ($area) { return (string) ($check['area'] ?? '') === $area; }));
}

function seo_system_diagnostics_area_status($checks) {
    if (empty($checks)) return 'not_evaluable';
    $has_fail = false; $has_warning = false; $has_pass = false; $all_blocked = true;
    foreach ($checks as $check) {
        $status = (string) ($check['status'] ?? 'unknown');
        if (!in_array($status, array('not_evaluable', 'not_applicable', 'unknown'), true)) $all_blocked = false;
        if (in_array($status, array('fail', 'critical'), true)) $has_fail = true;
        elseif ($status === 'warning') $has_warning = true;
        elseif ($status === 'pass') $has_pass = true;
    }
    if ($all_blocked) return 'not_evaluable';
    if ($has_fail) return 'error';
    if ($has_warning) return 'warning';
    return $has_pass ? 'ok' : 'info';
}

function seo_system_diagnostics_build_recommendation_groups($payload) {
    $groups = array();
    foreach ((array) ($payload['root_causes'] ?? array()) as $cause) {
        $groups[] = array(
            'problem_id' => (string) ($cause['id'] ?? ''),
            'priority' => (string) ($cause['severity'] ?? 'warning'),
            'title' => (string) ($cause['title'] ?? ''),
            'owner' => (string) ($cause['owner'] ?? 'WP'),
            'why' => (array) ($cause['business_impact'] ?? array()),
            'actions' => array(
                array('order' => 1, 'action' => 'Resolver la causa raíz antes de corregir síntomas dependientes.', 'owner' => (string) ($cause['owner'] ?? 'WP')),
                array('order' => 2, 'action' => (string) ($cause['verification'] ?? 'Repetir la prueba después del cambio.'), 'owner' => (string) ($cause['owner'] ?? 'WP')),
            ),
            'evidence' => (array) ($cause['evidence'] ?? array()),
        );
    }
    $seen = array();
    foreach ((array) ($payload['incidents'] ?? array()) as $incident) {
        if (!empty($incident['root_cause_id'])) continue;
        $key = sanitize_key((string) ($incident['owner'] ?? 'WP') . '_' . (string) ($incident['label'] ?? 'incidencia'));
        if (isset($seen[$key])) continue; $seen[$key] = true;
        $groups[] = array('problem_id' => 'problem_' . substr(sha1($key), 0, 10), 'priority' => (string) ($incident['impact'] ?? 'warning'), 'title' => (string) ($incident['label'] ?? 'Revisar incidencia'), 'owner' => (string) ($incident['owner'] ?? 'WP'), 'why' => array((string) ($incident['detail'] ?? '')), 'actions' => array(array('order' => 1, 'action' => 'Revisar la evidencia y corregir la configuración o contenido afectado.', 'owner' => (string) ($incident['owner'] ?? 'WP'))), 'evidence' => array('url' => (string) ($incident['url'] ?? ''), 'incident_id' => (string) ($incident['id'] ?? '')));
        if (count($groups) >= 20) break;
    }
    return $groups;
}

function seo_system_diagnostics_responsibility_summary($payload) {
    $summary = array('hosting' => 0, 'WP' => 0, 'Woo' => 0, 'SEO' => 0, 'contenido' => 0);
    foreach ((array) ($payload['recommendation_groups'] ?? array()) as $group) {
        $owner = (string) ($group['owner'] ?? 'WP');
        if (!isset($summary[$owner])) {
            $summary[$owner] = 0;
        }
        $summary[$owner]++;
    }
    return $summary;
}

function seo_system_diagnostics_apply_export_profile($payload, $profile = 'ai') {
    $profile = in_array($profile, array('internal', 'external', 'ai'), true) ? $profile : 'ai';
    $payload['privacy']['export_profile'] = $profile;
    $payload['privacy']['shareable'] = $profile !== 'internal';
    if ($profile !== 'internal') {
        if (isset($payload['context']['installation_id'])) $payload['context']['installation_id'] = '[oculto]';
        if (isset($payload['functional_snapshot']['run_metadata']['user_id'])) unset($payload['functional_snapshot']['run_metadata']['user_id']);
    }
    if ($profile === 'external') {
        unset($payload['components']['active_plugins']);
        $payload['components']['active_plugins_hidden'] = true;
    }
    return $payload;
}

function seo_system_diagnostics_collect_full_incidents($server, $core) {
    $settings = seo_system_diagnostics_get_settings();
    $incidents = array();

    foreach ((array) ($server['checks'] ?? array()) as $check) {
        $status = (string) ($check['status'] ?? 'info');
        if (!in_array($status, array('error', 'important', 'warning', 'critical'), true)) {
            continue;
        }
        $incidents[] = array(
            'id' => (string) ($check['code'] ?? 'SERVER'),
            'source' => 'server',
            'area' => 'technical',
            'impact' => $status,
            'label' => (string) ($check['label'] ?? ''),
            'detail' => trim((string) ($check['value'] ?? '') . ((string) ($check['detail'] ?? '') !== '' ? ' - ' . (string) $check['detail'] : '')),
            'url' => '',
            'origin' => '',
            'owner' => seo_system_diagnostics_owner_for_incident($check),
        );
    }

    foreach ((array) ($core['incidents'] ?? array()) as $incident) {
        if (empty($settings['include_site_quality']) && seo_system_diagnostics_is_site_quality_incident($incident)) {
            continue;
        }
        $incidents[] = array_merge($incident, array('source' => 'core'));
    }

    $incidents = seo_system_diagnostics_compact_email_incidents($incidents);
    return array_map('seo_system_diagnostics_sanitize_incident', array_slice($incidents, 0, 1000));
}


function seo_system_diagnostics_build_recommendations($payload) {
    return seo_system_diagnostics_build_recommendation_groups($payload);
}


function seo_system_diagnostics_build_export_payload($profile = 'ai') {
    $payload = seo_system_diagnostics_build_payload('manual_export', false);
    $payload['schema'] = array('name' => 'seo-system-diagnostic-report', 'version' => '4.0.0');
    $payload['report_format_version'] = 4;
    $payload['report_scope'] = 'technical_functional_sellability_indexation_semantic';

    $server = function_exists('seo_server_status_get_reporting_snapshot')
        ? seo_server_status_get_reporting_snapshot(false)
        : array();
    $core = function_exists('seo_core_system_test_get_reporting_snapshot')
        ? seo_core_system_test_get_reporting_snapshot()
        : array();

    $payload['incidents'] = seo_system_diagnostics_collect_full_incidents($server, $core);
    $counts = array('error' => 0, 'important' => 0, 'warning' => 0);
    foreach ($payload['incidents'] as $incident) {
        $impact = (string) ($incident['impact'] ?? 'warning');
        if (isset($counts[$impact])) {
            $counts[$impact]++;
        }
    }
    $payload['summary']['incidents'] = $counts;
    $payload['summary']['total_incidents'] = array_sum($counts);
    $payload['summary']['overall'] = $counts['error'] > 0 ? 'error' : ($counts['important'] > 0 ? 'important' : ($counts['warning'] > 0 ? 'warning' : 'ok'));

    $payload['technical_snapshot'] = seo_system_diagnostics_export_recursive($server, 0, 'technical_snapshot');
    $payload['functional_snapshot'] = seo_system_diagnostics_export_recursive($core, 0, 'functional_snapshot');
    $payload['site_inventory'] = seo_system_diagnostics_collect_site_inventory();
    $payload['components'] = seo_system_diagnostics_collect_components();
    $payload['action_scheduler'] = seo_system_diagnostics_collect_scheduler_snapshot();
    $payload['sitemaps'] = seo_system_diagnostics_collect_sitemap_snapshot();

    $sellability_checks = seo_system_diagnostics_extract_area_checks($core, 'sellability');
    $indexation_checks = seo_system_diagnostics_extract_area_checks($core, 'indexation');
    $payload['sellability'] = array(
        'status' => seo_system_diagnostics_area_status($sellability_checks),
        'checks' => $sellability_checks,
        'safe_mode' => 'No crea pedidos ni ejecuta cobros.',
    );
    $payload['indexation'] = array(
        'status' => seo_system_diagnostics_area_status($indexation_checks),
        'checks' => $indexation_checks,
        'sitemaps' => $payload['sitemaps'],
        'blog_public' => (int) get_option('blog_public', 1),
    );
    $payload['semantic_health'] = seo_system_diagnostics_export_recursive(
        (array) ($core['semantic'] ?? (function_exists('seo_core_system_test_get_semantic_snapshot') ? seo_core_system_test_get_semantic_snapshot() : array())),
        0,
        'semantic_health'
    );

    $payload['root_causes'] = seo_system_diagnostics_build_root_causes($payload['incidents'], $payload['sitemaps']);
    $payload['recommendation_groups'] = seo_system_diagnostics_build_recommendation_groups($payload);
    $payload['recommendations'] = $payload['recommendation_groups'];
    $payload['responsibility_summary'] = seo_system_diagnostics_responsibility_summary($payload);
    $payload['executive_summary'] = seo_system_diagnostics_build_executive_summary($payload);
    $payload['methodology'] = array(
        'scope' => 'Diagnóstico técnico, funcional, comercial, SEO y semántico.',
        'score_rule' => 'Los estados no evaluables se conservan como tales y no se convierten automáticamente en aprobados o suspensos.',
        'limitations' => array(
            'No sustituye pruebas de compra reales ni ejecuta cobros.',
            'La salud semántica detecta señales estructurales, pero requiere revisión editorial para confirmar intención y canibalización.',
        ),
    );
    $payload['privacy'] = array_merge(
        (array) ($payload['privacy'] ?? array()),
        array(
            'private_by_default' => true,
            'excluded' => 'usuarios, clientes, pedidos, direcciones, contraseñas, cookies, tokens, filas de tablas y logs completos',
        )
    );

    return seo_system_diagnostics_apply_export_profile($payload, $profile);
}


function seo_system_diagnostics_export_incident_counts($incidents) {
    $counts = array('error' => 0, 'important' => 0, 'warning' => 0);
    foreach ((array) $incidents as $incident) {
        $impact = (string) ($incident['impact'] ?? 'warning');
        if ($impact === 'critical') {
            $impact = 'error';
        }
        if (isset($counts[$impact])) {
            $counts[$impact]++;
        }
    }
    return $counts;
}

function seo_system_diagnostics_export_overall($counts) {
    $counts = is_array($counts) ? $counts : array();
    return (int) ($counts['error'] ?? 0) > 0
        ? 'error'
        : ((int) ($counts['important'] ?? 0) > 0
            ? 'important'
            : ((int) ($counts['warning'] ?? 0) > 0 ? 'warning' : 'ok'));
}

/**
 * Exporta exclusivamente el estado tecnico del servidor.
 * No incluye los resultados de Plugin Validation.
 */
function seo_system_diagnostics_build_server_export_payload($profile = 'ai') {
    $base = seo_system_diagnostics_build_payload('manual_export_server', false);
    $server = function_exists('seo_server_status_get_reporting_snapshot')
        ? seo_server_status_get_reporting_snapshot(false)
        : array('health' => array(), 'checks' => array(), 'generated_at' => 0);

    $incidents = seo_system_diagnostics_collect_full_incidents($server, array());
    $counts = seo_system_diagnostics_export_incident_counts($incidents);

    $payload = array(
        'schema_version' => 1,
        'report_id' => wp_generate_uuid4(),
        'generated_at' => time(),
        'reason' => 'manual_export_server',
        'context' => (array) ($base['context'] ?? array()),
        'summary' => array(
            'overall' => seo_system_diagnostics_export_overall($counts),
            'incidents' => $counts,
            'total_incidents' => array_sum($counts),
            'server_health' => (array) ($server['health'] ?? array()),
            'server_generated_at' => (int) ($server['generated_at'] ?? 0),
        ),
        'incidents' => $incidents,
        'privacy' => array_merge(
            (array) ($base['privacy'] ?? array()),
            array(
                'private_by_default' => true,
                'excluded' => 'usuarios, clientes, pedidos, direcciones, contrasenas, cookies, tokens, filas de tablas y logs completos',
            )
        ),
        'schema' => array('name' => 'seo-system-server-status-report', 'version' => '1.0.0'),
        'report_format_version' => 1,
        'report_scope' => 'technical_server_security',
        'technical_snapshot' => seo_system_diagnostics_export_recursive($server, 0, 'technical_snapshot'),
        'components' => seo_system_diagnostics_collect_components(),
        'action_scheduler' => seo_system_diagnostics_collect_scheduler_snapshot(),
        'methodology' => array(
            'scope' => 'Estado del servidor, PHP, MySQL, WordPress, seguridad, WooCommerce, rendimiento, almacenamiento y logs.',
            'limitations' => array(
                'No incluye los chequeos de Plugin Validation.',
                'Las sondas HTTP locales pueden quedar no concluyentes si el hosting bloquea peticiones contra si mismo.',
            ),
        ),
    );

    return seo_system_diagnostics_apply_export_profile($payload, $profile);
}

/**
 * Exporta exclusivamente Plugin Validation y sus areas funcionales/SEO.
 * No incluye el snapshot tecnico de Server Status.
 */
function seo_system_diagnostics_build_validation_export_payload($profile = 'ai') {
    $base = seo_system_diagnostics_build_payload('manual_export_validation', false);
    $core = function_exists('seo_core_system_test_get_reporting_snapshot')
        ? seo_core_system_test_get_reporting_snapshot()
        : array('health' => array(), 'incidents' => array(), 'generated_at' => 0);

    $incidents = seo_system_diagnostics_collect_full_incidents(array(), $core);
    $counts = seo_system_diagnostics_export_incident_counts($incidents);
    $sitemaps = seo_system_diagnostics_collect_sitemap_snapshot();
    $sellability_checks = seo_system_diagnostics_extract_area_checks($core, 'sellability');
    $indexation_checks = seo_system_diagnostics_extract_area_checks($core, 'indexation');

    $payload = array(
        'schema_version' => 1,
        'report_id' => wp_generate_uuid4(),
        'generated_at' => time(),
        'reason' => 'manual_export_validation',
        'context' => (array) ($base['context'] ?? array()),
        'summary' => array(
            'overall' => seo_system_diagnostics_export_overall($counts),
            'incidents' => $counts,
            'total_incidents' => array_sum($counts),
            'core_health' => (array) ($core['health'] ?? array()),
            'core_generated_at' => (int) ($core['generated_at'] ?? 0),
        ),
        'incidents' => $incidents,
        'privacy' => array_merge(
            (array) ($base['privacy'] ?? array()),
            array(
                'private_by_default' => true,
                'excluded' => 'usuarios, clientes, pedidos, direcciones, contrasenas, cookies, tokens, filas de tablas y logs completos',
            )
        ),
        'schema' => array('name' => 'seo-system-plugin-validation-report', 'version' => '1.0.0'),
        'report_format_version' => 1,
        'report_scope' => 'plugin_validation_functional_sellability_indexation_semantic',
        'functional_snapshot' => seo_system_diagnostics_export_recursive($core, 0, 'functional_snapshot'),
        'components' => seo_system_diagnostics_collect_components(),
        'action_scheduler' => seo_system_diagnostics_collect_scheduler_snapshot(),
        'sitemaps' => $sitemaps,
        'sellability' => array(
            'status' => seo_system_diagnostics_area_status($sellability_checks),
            'checks' => $sellability_checks,
            'safe_mode' => 'No crea pedidos ni ejecuta cobros.',
        ),
        'indexation' => array(
            'status' => seo_system_diagnostics_area_status($indexation_checks),
            'checks' => $indexation_checks,
            'sitemaps' => $sitemaps,
            'blog_public' => (int) get_option('blog_public', 1),
        ),
        'semantic_health' => seo_system_diagnostics_export_recursive(
            (array) ($core['semantic'] ?? (function_exists('seo_core_system_test_get_semantic_snapshot') ? seo_core_system_test_get_semantic_snapshot() : array())),
            0,
            'semantic_health'
        ),
        'methodology' => array(
            'scope' => 'Integridad del plugin, sistema, plantillas, funcionalidad, indexacion, sellability y semantica/contenido.',
            'limitations' => array(
                'No incluye el snapshot tecnico de Estado del servidor.',
                'Las senales semanticas requieren revision editorial antes de aplicar cambios.',
            ),
        ),
    );

    $payload['root_causes'] = seo_system_diagnostics_build_root_causes($payload['incidents'], $sitemaps);
    $payload['recommendation_groups'] = seo_system_diagnostics_build_recommendation_groups($payload);
    $payload['recommendations'] = $payload['recommendation_groups'];
    $payload['responsibility_summary'] = seo_system_diagnostics_responsibility_summary($payload);

    return seo_system_diagnostics_apply_export_profile($payload, $profile);
}


function seo_system_diagnostics_report_status_label($status) {
    $labels = array(
        'error'     => 'Crítico',
        'important' => 'Importante',
        'warning'   => 'Con avisos',
        'ok'        => 'Correcto',
        'info'      => 'Pendiente',
        'not_evaluable' => 'No evaluable',
        'not_applicable' => 'No aplica',
        'unknown' => 'Sin confirmar',
    );
    return $labels[$status] ?? 'Pendiente';
}

function seo_system_diagnostics_format_score($score) {
    return $score === null ? 'Sin datos' : max(0, min(100, (int) $score)) . '%';
}

function seo_system_diagnostics_report_score_label($score) {
    $score = (int) $score;
    if ($score >= 95) {
        return 'Excelente';
    }
    if ($score >= 85) {
        return 'Muy sólido';
    }
    if ($score >= 75) {
        return 'Buena base';
    }
    if ($score >= 60) {
        return 'Mejorable';
    }
    return 'Prioridad alta';
}

function seo_system_diagnostics_render_score_card($label, $score, $detail = '') {
    $is_evaluable = $score !== null;
    $value = $is_evaluable ? max(0, min(100, (int) $score)) : null;
    echo '<div class="seo-report-score">';
    echo '<strong>' . esc_html($label) . '</strong>';
    echo '<span>' . esc_html($is_evaluable ? (string) $value . '%' : 'Sin datos') . '</span>';
    echo '<small>' . esc_html($detail !== '' ? $detail : ($is_evaluable ? seo_system_diagnostics_report_score_label($value) : 'No evaluable')) . '</small>';
    echo '</div>';
}



function seo_system_diagnostics_export_scope_for_context($context = 'auto') {
    $context = is_scalar($context) ? sanitize_key((string) $context) : 'auto';
    if (in_array($context, array('core_summary', 'validation', 'plugin_validation'), true)) {
        return 'validation';
    }
    if ($context === 'server') {
        return 'server';
    }

    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    if (in_array($page, array('seo_server_status', 'seo-system-status'), true)) {
        return 'server';
    }
    if (seo_system_diagnostics_is_core_validation_screen()) {
        return 'validation';
    }
    return 'complete';
}

function seo_system_diagnostics_render_scoped_export_panel($scope) {
    $scope = $scope === 'server' ? 'server' : 'validation';
    if ($scope === 'server') {
        $payload = seo_system_diagnostics_build_server_export_payload();
        $health = (array) ($payload['summary']['server_health'] ?? array());
        $generated = (int) ($payload['summary']['server_generated_at'] ?? 0);
        $action = 'seo_system_diagnostics_download_server_json';
        $nonce = 'seo_system_diagnostics_download_server_json';
        $title = 'Informe de Estado del servidor';
        $description = 'Exporta solo servidor, PHP, MySQL, WordPress, seguridad, WooCommerce, rendimiento y logs. No incluye Plugin Validation.';
        $button = 'Descargar JSON del servidor';
    } else {
        $payload = seo_system_diagnostics_build_validation_export_payload();
        $health = (array) ($payload['summary']['core_health'] ?? array());
        $generated = (int) ($payload['summary']['core_generated_at'] ?? 0);
        $action = 'seo_system_diagnostics_download_validation_json';
        $nonce = 'seo_system_diagnostics_download_validation_json';
        $title = 'Informe de Plugin Validation';
        $description = 'Exporta solo los chequeos de validacion del plugin, funcionales, SEO, indexacion y semantica. No incluye Server Status.';
        $button = 'Descargar JSON de Plugin Validation';
    }

    $url = wp_nonce_url(add_query_arg(array(
        'action' => $action,
        'redirect_to' => seo_system_diagnostics_current_reporting_url(),
    ), admin_url('admin-post.php')), $nonce);
    $score = array_key_exists('score', $health) ? $health['score'] : null;

    echo '<div class="notice notice-info seo-system-diagnostics-export-panel" style="padding:16px 18px;margin-top:18px;border-left-width:4px;">';
    echo '<div style="display:flex;gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap;">';
    echo '<div><h2 style="margin:0 0 6px;">' . esc_html($title) . '</h2>';
    echo '<p style="margin:0;max-width:900px;">' . esc_html($description) . '</p>';
    echo '<p style="margin:7px 0 0;color:#646970;">Snapshot: ' . esc_html($generated > 0 ? date_i18n('Y-m-d H:i:s', $generated) : 'sin ejecucion guardada') . ' · Salud: ' . esc_html(seo_system_diagnostics_format_score($score)) . '. Ejecuta el chequeo de esta pantalla antes de exportar si quieres datos recien actualizados.</p></div>';
    echo '<div><a class="button button-primary" href="' . esc_url($url) . '">' . esc_html($button) . '</a></div>';
    echo '</div></div>';
}


function seo_system_diagnostics_render_export_panel($context = 'auto') {
    static $rendered = false;
    if ($rendered || !current_user_can('manage_options')) {
        return;
    }
    $rendered = true;
    $context = is_scalar($context) ? sanitize_key((string) $context) : 'auto';
    $scope = seo_system_diagnostics_export_scope_for_context($context);
    if ($scope !== 'complete') {
        seo_system_diagnostics_render_scoped_export_panel($scope);
        return;
    }

    $payload = seo_system_diagnostics_build_export_payload();
    $summary = (array) ($payload['summary'] ?? array());
    $semantic = (array) ($payload['semantic_health'] ?? array());
    $global = (array) ($summary['global_health'] ?? array());
    $trend = (array) ($summary['global_trend'] ?? array());
    $redirect = seo_system_diagnostics_current_reporting_url();

    $refresh_url = wp_nonce_url(add_query_arg(array(
        'action'      => 'seo_system_diagnostics_refresh_report',
        'redirect_to' => $redirect,
    ), admin_url('admin-post.php')), 'seo_system_diagnostics_refresh_report');
    $pdf_url = wp_nonce_url(add_query_arg(array(
        'action'      => 'seo_system_diagnostics_download_pdf',
        'redirect_to' => $redirect,
    ), admin_url('admin-post.php')), 'seo_system_diagnostics_download_pdf');
    $json_url = wp_nonce_url(add_query_arg(array(
        'action'      => 'seo_system_diagnostics_download_json',
        'redirect_to' => $redirect,
    ), admin_url('admin-post.php')), 'seo_system_diagnostics_download_json');
    $server_json_url = wp_nonce_url(add_query_arg(array(
        'action'      => 'seo_system_diagnostics_download_server_json',
        'redirect_to' => $redirect,
    ), admin_url('admin-post.php')), 'seo_system_diagnostics_download_server_json');
    $validation_json_url = wp_nonce_url(add_query_arg(array(
        'action'      => 'seo_system_diagnostics_download_validation_json',
        'redirect_to' => $redirect,
    ), admin_url('admin-post.php')), 'seo_system_diagnostics_download_validation_json');

    echo '<div class="notice notice-info seo-system-diagnostics-export-panel" style="padding:18px 20px;margin-top:18px;border-left-width:4px;">';
    $message = isset($_GET['seo_report_msg']) ? sanitize_key(wp_unslash($_GET['seo_report_msg'])) : '';
    echo '<style>
        .seo-diag-export-head{display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start;justify-content:space-between}
        .seo-diag-export-actions{display:flex;flex-wrap:wrap;gap:8px}
        .seo-diag-score-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:10px;margin:16px 0 10px}
        .seo-diag-score{background:#fff;border:1px solid #dcdcde;border-radius:7px;padding:12px;position:relative}
        .seo-diag-score.is-global{border-left:5px solid #2271b1;background:#f0f6fc}
        .seo-diag-score.is-subset{border-style:dashed;background:#fcfcfc}
        .seo-diag-scope-tag{display:inline-block;font-size:10px;line-height:1;padding:4px 6px;border-radius:999px;background:#f0f0f1;color:#50575e;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}
        .seo-diag-score strong,.seo-diag-score small{display:block}.seo-diag-score span{display:block;font-size:25px;font-weight:800;line-height:1.15;margin:5px 0}
        .seo-diag-count-grid{display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:8px;margin:10px 0 0}
        .seo-diag-count{background:#f6f7f7;border:1px solid #dcdcde;border-radius:7px;padding:10px 12px}
        .seo-diag-count strong{display:block}.seo-diag-count span{display:block;font-size:22px;font-weight:800;margin-top:3px}
        .seo-diag-context{margin:12px 0 0;color:#50575e}
        .seo-diag-explain{border:1px solid #dcdcde;border-radius:7px;background:#fff;margin:14px 0 0}
        .seo-diag-explain>summary{cursor:pointer;padding:11px 13px;font-weight:600}
        .seo-diag-explain-body{padding:0 13px 13px;color:#50575e}
        .seo-diag-explain-body ul{margin:8px 0 0 20px}
        @media(max-width:782px){.seo-diag-export-head{display:block}.seo-diag-export-actions{margin-top:12px}.seo-diag-count-grid{grid-template-columns:repeat(2,minmax(120px,1fr))}}
    </style>';
    if ('updated_sent' === $message) {
        echo '<p style="margin:0 0 12px;color:#1d6b43;font-weight:700;">Análisis actualizado y resumen enviado automáticamente.</p>';
    } elseif ('updated' === $message) {
        echo '<p style="margin:0 0 12px;color:#1d6b43;font-weight:700;">Análisis actualizado correctamente.</p>';
    } elseif ('refresh_error' === $message) {
        echo '<p style="margin:0 0 12px;color:#b32d2e;font-weight:700;">No se pudo completar la actualización. Revisa el registro de errores.</p>';
    }

    echo '<div class="seo-diag-export-head"><div>';
    echo '<p style="margin:0 0 5px;"><span class="seo-diag-scope-tag">Global · todos los ámbitos</span></p>';
    echo '<h2 style="margin:0 0 6px;">Resumen global de todos los chequeos</h2>';
    echo '<p style="margin:0;max-width:900px;">Esta es la única puntuación consolidada. Reúne el diagnóstico general del servidor y todas las áreas de SEO Core. El detalle se concentra en Integridad del código y Chequeos avanzados.</p>';
    echo '</div><div class="seo-diag-export-actions">';
    echo '<a class="button button-primary" href="' . esc_url($refresh_url) . '">Actualizar análisis</a>';
    echo '<a class="button" href="' . esc_url($pdf_url) . '">Descargar PDF</a>';
    echo '<a class="button" href="' . esc_url($server_json_url) . '">JSON servidor</a>';
    echo '<a class="button" href="' . esc_url($validation_json_url) . '">JSON Plugin Validation</a>';
    echo '<a class="button" href="' . esc_url($json_url) . '">JSON completo</a>';
    echo '</div></div>';

    $global_score = array_key_exists('score', $global) ? $global['score'] : null;
    $cards = array(
        array('Estado global', $global_score, seo_system_diagnostics_report_status_label((string) ($global['status'] ?? ($summary['overall'] ?? 'info'))), 'is-global', 'Servidor + SEO Core'),
        array('Servidor', $summary['server_health']['score'] ?? null, 'Entorno, PHP, MySQL y recursos', '', 'Ámbito general seo-system-*'),
        array('SEO Core', $summary['core_health']['score'] ?? null, 'Todas las áreas de validación del plugin', '', 'Ámbito del plugin seo-core-*'),
        array('Contenido y FAQs', array_key_exists('score', $semantic) ? $semantic['score'] : null, 'Ya incluido dentro de SEO Core', 'is-subset', 'Subconjunto de Datos, SEO y contenido'),
    );

    echo '<div class="seo-diag-score-grid">';
    foreach ($cards as $card) {
        $card_score = $card[1];
        $card_detail = $card_score === null ? 'No evaluable' : $card[2];
        if ($card_score !== null && $card[0] !== 'Estado global' && $card[0] !== 'Contenido y FAQs') {
            $card_detail .= ' · ' . seo_system_diagnostics_report_score_label($card_score);
        }
        echo '<div class="seo-diag-score ' . esc_attr($card[3]) . '"><div class="seo-diag-scope-tag">' . esc_html($card[4]) . '</div><strong>' . esc_html($card[0]) . '</strong><span>' . esc_html(seo_system_diagnostics_format_score($card_score)) . '</span><small>' . esc_html($card_detail) . '</small></div>';
    }
    echo '</div>';

    $incident_counts = (array) ($summary['incidents'] ?? array());
    $global_counts = array(
        'Críticos'    => (int) ($incident_counts['error'] ?? 0),
        'Importantes' => (int) ($incident_counts['important'] ?? 0),
        'Avisos'      => (int) ($incident_counts['warning'] ?? 0),
        'Correctos'   => (int) ($global['ok'] ?? 0),
    );
    echo '<div class="seo-diag-count-grid">';
    foreach ($global_counts as $label => $value) {
        echo '<div class="seo-diag-count"><strong>' . esc_html($label) . '</strong><span>' . esc_html(number_format_i18n($value)) . '</span></div>';
    }
    echo '</div>';

    $trend_text = ((int) ($trend['new'] ?? 0) > 0 ? '+' . (int) $trend['new'] . ' nuevas' : 'sin nuevas')
        . ' / '
        . ((int) ($trend['resolved'] ?? 0) > 0 ? (int) $trend['resolved'] . ' resueltas' : 'sin resueltas');
    echo '<p class="seo-diag-context"><strong>Cambios desde la ejecución anterior:</strong> ' . esc_html($trend_text) . '. Los contadores son globales y cada incidencia se cuenta una sola vez por origen, código e impacto.</p>';

    echo '<details class="seo-diag-explain">';
    echo '<summary>Cómo se compone este resumen y qué no se suma dos veces</summary>';
    echo '<div class="seo-diag-explain-body"><ul>';
    echo '<li><strong>Estado global</strong>: consolidación ponderada de Servidor y SEO Core.</li>';
    echo '<li><strong>Servidor</strong>: diagnóstico general de alojamiento, PHP, MySQL, WordPress y recursos.</li>';
    echo '<li><strong>SEO Core</strong>: suma de todos los tests del plugin, aunque la interfaz los muestre concentrados en dos vistas.</li>';
    echo '<li><strong>Contenido y FAQs</strong>: detalle informativo del bloque Datos, SEO y contenido; ya está incluido en SEO Core y no vuelve a sumarse.</li>';
    echo '</ul></div></details>';

    if (($context === 'core_summary' || seo_system_diagnostics_is_core_validation_screen()) && function_exists('seo_core_system_test_render_diagnostics_controls')) {
        seo_core_system_test_render_diagnostics_controls(null, true);
    }

    echo '</div>';
}



/**
 * Renderiza la auditoría conjunta de productos, categorías y FAQs.
 */
function seo_system_diagnostics_render_semantic_alignment($semantic) {
    $semantic = is_array($semantic) ? $semantic : array();

    echo '<div class="seo-report-card">';
    echo '<h2>Contenido, categorías y FAQs</h2>';

    if (empty($semantic)) {
        echo '<p>No existe todavía una auditoría semántica. Pulsa <strong>Actualizar auditoría</strong>.</p>';
        echo '</div>';
        return;
    }

    $categories = (array) ($semantic['categories'] ?? array());
    $products = (array) ($semantic['products'] ?? array());
    $faqs = (array) ($semantic['faqs'] ?? array());
    $actions = (array) ($semantic['actions'] ?? array());
    $score = array_key_exists('score', $semantic) ? $semantic['score'] : null;

    echo '<p><strong>Preparación editorial:</strong> ' . esc_html(seo_system_diagnostics_format_score($score)) . '. Esta cifra resume señales observables; no declara que el contenido sea perfecto.</p>';

    echo '<table class="seo-report-table"><thead><tr><th>Área</th><th>Control</th><th>Resultado</th></tr></thead><tbody>';

    $rows = array(
        array('Categorías', 'Ámbitos inválidos', (int) ($categories['invalid_scope'] ?? 0)),
        array('Categorías', 'Excerpt desincronizado con la plantilla', (int) ($categories['excerpt_storage_mismatch'] ?? 0)),
        array('Categorías', 'Descripciones con patrón de plantilla', (int) ($categories['template_description'] ?? 0)),
        array('Categorías', 'Filas afectadas por descripción duplicada', (int) ($categories['duplicate_descriptions']['affected_rows'] ?? 0)),
        array('Productos', 'Atributos sospechosos', (int) ($products['suspicious_attribute_products'] ?? 0)),
        array('Productos', 'Filas afectadas por excerpt duplicado', (int) ($products['duplicate_excerpts']['affected_rows'] ?? 0)),
        array('Productos', 'Filas afectadas por descripción duplicada', (int) ($products['duplicate_descriptions']['affected_rows'] ?? 0)),
        array('Productos', 'Categoría interna a revisar', (int) ($products['supplier_category_review'] ?? 0)),
        array('FAQs', 'Copias activas sobrantes dentro del mismo objeto', (int) ($faqs['duplicate_questions_same_object']['active_extra_rows'] ?? 0)),
        array('FAQs', 'Preguntas con patrón repetido', (int) ($faqs['template_questions'] ?? 0)),
        array('FAQs', 'Respuestas con patrón repetido', (int) ($faqs['template_answers'] ?? 0)),
        array('FAQs', 'Ámbito distinto al objeto', (int) ($faqs['scope_mismatch'] ?? 0)),
        array('FAQs', 'Huérfanas', (int) ($faqs['orphan_rows'] ?? 0)),
    );

    foreach ($rows as $row) {
        $count = (int) $row[2];
        echo '<tr>';
        echo '<td><strong>' . esc_html($row[0]) . '</strong></td>';
        echo '<td>' . esc_html($row[1]) . '</td>';
        echo '<td class="num"><span class="seo-report-badge">' . esc_html(number_format_i18n($count)) . '</span></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    echo '<h3>Qué modificar</h3>';
    if (empty($actions)) {
        echo '<p>No se han generado acciones editoriales prioritarias.</p>';
    } else {
        echo '<table class="seo-report-table"><thead><tr><th>Prioridad</th><th>Entidad / campo</th><th>Registros</th><th>Acción recomendada</th></tr></thead><tbody>';
        foreach (array_slice($actions, 0, 20) as $action) {
            echo '<tr>';
            echo '<td><span class="seo-report-badge">' . esc_html(strtoupper((string) ($action['priority'] ?? ''))) . '</span></td>';
            echo '<td><strong>' . esc_html((string) ($action['entity'] ?? '')) . '</strong> · ' . esc_html((string) ($action['field'] ?? '')) . '</td>';
            echo '<td class="num">' . esc_html(number_format_i18n((int) ($action['count'] ?? 0))) . '</td>';
            echo '<td>' . esc_html((string) ($action['recommendation'] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    $example_sections = array(
        'Atributos de producto a revisar' => (array) ($products['attribute_examples'] ?? array()),
        'Posibles desalineamientos producto-categoría' => (array) ($products['alignment_examples'] ?? array()),
        'FAQs con ámbito distinto' => (array) ($faqs['scope_examples'] ?? array()),
        'Categorías con fuentes desincronizadas' => (array) ($categories['examples'] ?? array()),
    );

    foreach ($example_sections as $label => $examples) {
        if (empty($examples)) {
            continue;
        }
        echo '<details style="margin:12px 0;">';
        echo '<summary><strong>' . esc_html($label) . '</strong> (' . esc_html(number_format_i18n(count($examples))) . ' ejemplos)</summary>';
        echo '<pre style="white-space:pre-wrap;max-height:360px;overflow:auto;background:#f6f7f7;padding:12px;border:1px solid #dcdcde;">' . esc_html(wp_json_encode(array_slice($examples, 0, 20), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
        echo '</details>';
    }

    echo '<p class="seo-report-muted">El módulo es de solo lectura. Las alertas semánticas indican qué revisar; no cambian categorías, productos, atributos, etiquetas ni FAQs.</p>';
    echo '</div>';
}

function seo_system_diagnostics_render_report_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $payload = seo_system_diagnostics_build_export_payload();
    $summary = (array) ($payload['summary'] ?? array());
    $semantic = (array) ($payload['semantic_health'] ?? array());
    $incidents = (array) ($payload['incidents'] ?? array());
    $message = isset($_GET['seo_report_msg']) ? sanitize_key(wp_unslash($_GET['seo_report_msg'])) : '';

    $refresh_url = wp_nonce_url(
        admin_url('admin-post.php?action=seo_system_diagnostics_refresh_report'),
        'seo_system_diagnostics_refresh_report'
    );
    $pdf_url = wp_nonce_url(
        admin_url('admin-post.php?action=seo_system_diagnostics_download_pdf'),
        'seo_system_diagnostics_download_pdf'
    );
    $json_url = wp_nonce_url(
        admin_url('admin-post.php?action=seo_system_diagnostics_download_json'),
        'seo_system_diagnostics_download_json'
    );
    $server_json_url = wp_nonce_url(
        admin_url('admin-post.php?action=seo_system_diagnostics_download_server_json'),
        'seo_system_diagnostics_download_server_json'
    );
    $validation_json_url = wp_nonce_url(
        admin_url('admin-post.php?action=seo_system_diagnostics_download_validation_json'),
        'seo_system_diagnostics_download_validation_json'
    );

    echo '<div class="wrap seo-system-quality-report">';
    echo '<h1>Informe completo SEO System</h1>';
    echo '<p>Auditoría técnica, funcional, comercial, SEO y semántica preparada para revisión interna, un analista externo o una IA.</p>';

    if ('updated_sent' === $message) {
        echo '<div class="notice notice-success is-dismissible"><p>Auditoría actualizada y resumen enviado automáticamente.</p></div>';
    } elseif ('updated' === $message) {
        echo '<div class="notice notice-success is-dismissible"><p>Auditoría actualizada correctamente.</p></div>';
    } elseif ('refresh_error' === $message) {
        echo '<div class="notice notice-error is-dismissible"><p>No se pudo completar la actualización. Revisa el registro de errores.</p></div>';
    }

    echo '<style>
        .seo-report-actions{display:flex;flex-wrap:wrap;gap:10px;margin:18px 0 22px}
        .seo-report-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin:16px 0 22px}
        .seo-report-score{background:#fff;border:1px solid #dcdcde;border-radius:9px;padding:17px;box-shadow:0 3px 12px rgba(0,0,0,.035)}
        .seo-report-score strong,.seo-report-score small{display:block}.seo-report-score span{display:block;font-size:32px;font-weight:800;line-height:1.2;margin:8px 0 3px}.seo-report-score small{color:#646970}
        .seo-report-card{background:#fff;border:1px solid #dcdcde;border-radius:9px;padding:20px;margin:0 0 18px}
        .seo-report-card h2{margin-top:0}.seo-report-table{width:100%;border-collapse:collapse}.seo-report-table th,.seo-report-table td{padding:10px 9px;border-bottom:1px solid #e7e7e7;text-align:left;vertical-align:top}.seo-report-table th{background:#f6f7f7}.seo-report-table td.num{text-align:center;white-space:nowrap}.seo-report-muted{color:#646970}.seo-report-badge{display:inline-block;padding:3px 8px;border-radius:999px;background:#f0f0f1;font-weight:700;font-size:12px}.seo-report-incident{padding:12px 0;border-bottom:1px solid #eee}.seo-report-incident:last-child{border-bottom:0}.seo-report-incident code{font-size:11px}
        @media(max-width:780px){.seo-report-table{display:block;overflow:auto;white-space:nowrap}}
    </style>';

    echo '<div class="seo-report-actions">';
    echo '<a class="button button-primary" href="' . esc_url($refresh_url) . '">Actualizar auditoría</a>';
    echo '<a class="button" href="' . esc_url($pdf_url) . '">Descargar PDF completo</a>';
    echo '<a class="button" href="' . esc_url($server_json_url) . '">JSON servidor</a>';
    echo '<a class="button" href="' . esc_url($validation_json_url) . '">JSON Plugin Validation</a>';
    echo '<a class="button" href="' . esc_url($json_url) . '">JSON completo para IA</a>';
    echo '</div>';

    echo '<div class="seo-report-card">';
    echo '<h2>Resumen ejecutivo</h2>';
    echo '<p><strong>Estado general:</strong> <span class="seo-report-badge">' . esc_html(seo_system_diagnostics_report_status_label((string) ($summary['overall'] ?? 'info'))) . '</span></p>';
    echo '<div class="seo-report-grid">';
    seo_system_diagnostics_render_score_card('Servidor', (int) ($summary['server_health']['score'] ?? 0));
    seo_system_diagnostics_render_score_card('Funcionamiento', (int) ($summary['core_health']['score'] ?? 0));
    seo_system_diagnostics_render_score_card('Contenido y FAQs', array_key_exists('score', $semantic) ? $semantic['score'] : null, 'Productos, categorías y FAQs');
    echo '</div>';
    echo '<p class="seo-report-muted">Incidencias: ' . esc_html((string) ($summary['incidents']['error'] ?? 0)) . ' críticas, ' . esc_html((string) ($summary['incidents']['important'] ?? 0)) . ' importantes y ' . esc_html((string) ($summary['incidents']['warning'] ?? 0)) . ' avisos.</p>';
    echo '</div>';

    seo_system_diagnostics_render_semantic_alignment($semantic);

    echo '<div class="seo-report-card">';
    echo '<h2>Incidencias principales</h2>';
    if (empty($incidents)) {
        echo '<p>No hay incidencias en los chequeos disponibles.</p>';
    } else {
        foreach (array_slice($incidents, 0, 30) as $incident) {
            echo '<div class="seo-report-incident">';
            echo '<p><code>' . esc_html((string) ($incident['id'] ?? '')) . '</code> <strong>' . esc_html((string) ($incident['label'] ?? '')) . '</strong></p>';
            echo '<p>' . esc_html((string) ($incident['detail'] ?? '')) . '</p>';
            if (!empty($incident['url'])) {
                echo '<p><a href="' . esc_url((string) $incident['url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html((string) $incident['url']) . '</a></p>';
            }
            echo '</div>';
        }
    }
    echo '</div>';

    echo '<div class="seo-report-card"><h2>Interpretación</h2>';
    echo '<p>Las puntuaciones resumen señales técnicas, funcionales y semánticas. Sirven para localizar incidencias y ordenar el trabajo, pero requieren interpretación antes de aplicar cambios.</p>';
    echo '<p>El PDF es adecuado para lectura y comunicación. El JSON conserva la estructura completa y es el formato recomendado para facilitar una segunda revisión por una IA.</p>';
    echo '</div>';
    echo '</div>';
}

function seo_system_diagnostics_handle_refresh_report() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para actualizar este informe.', 'seo-system'));
    }
    check_admin_referer('seo_system_diagnostics_refresh_report');

    try {
        seo_system_diagnostics_refresh_checks('manual');
        if (seo_system_diagnostics_auto_send_authorized()) {
            $send_result = seo_system_diagnostics_generate_and_send('manual_refresh', false, true);
            $message = !empty($send_result['sent']) ? 'updated_sent' : 'updated';
        } else {
            $message = 'updated';
        }
    } catch (Throwable $exception) {
        if (function_exists('seo_system_private_log')) {
            seo_system_private_log(
                'error',
                'Error al actualizar informe',
                array('error' => $exception->getMessage())
            );
        } else {
            error_log('[SEO System] Error al actualizar informe: ' . $exception->getMessage());
        }
        $message = 'refresh_error';
    }

    $return_url = seo_system_diagnostics_get_return_url();
    wp_safe_redirect(add_query_arg('seo_report_msg', $message, $return_url));
    exit;
}


function seo_system_diagnostics_send_json_download($payload, $filename_prefix) {
    $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        $json = '{}';
    }

    $filename_prefix = sanitize_file_name((string) $filename_prefix);
    nocache_headers();
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename_prefix . '-' . gmdate('Ymd-His') . '.json"');
    header('X-Content-Type-Options: nosniff');
    echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    exit;
}

function seo_system_diagnostics_handle_download_server_json() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para descargar este informe.', 'seo-system'));
    }
    check_admin_referer('seo_system_diagnostics_download_server_json');
    $profile = isset($_GET['profile']) ? sanitize_key(wp_unslash($_GET['profile'])) : 'ai';
    seo_system_diagnostics_send_json_download(
        seo_system_diagnostics_build_server_export_payload($profile),
        'estado-servidor-seo-system'
    );
}

function seo_system_diagnostics_handle_download_validation_json() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para descargar este informe.', 'seo-system'));
    }
    check_admin_referer('seo_system_diagnostics_download_validation_json');
    $profile = isset($_GET['profile']) ? sanitize_key(wp_unslash($_GET['profile'])) : 'ai';
    seo_system_diagnostics_send_json_download(
        seo_system_diagnostics_build_validation_export_payload($profile),
        'plugin-validation-seo-system'
    );
}

function seo_system_diagnostics_handle_download_json() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para descargar este informe.', 'seo-system'));
    }
    check_admin_referer('seo_system_diagnostics_download_json');

    $profile = isset($_GET['profile']) ? sanitize_key(wp_unslash($_GET['profile'])) : 'ai';
    seo_system_diagnostics_send_json_download(
        seo_system_diagnostics_build_export_payload($profile),
        'informe-completo-seo-system'
    );
}

function seo_system_diagnostics_handle_download_pdf() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para descargar este informe.', 'seo-system'));
    }
    check_admin_referer('seo_system_diagnostics_download_pdf');

    $payload = seo_system_diagnostics_build_export_payload('external');
    $pdf = seo_system_diagnostics_pdf_build($payload);

    nocache_headers();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="informe-completo-seo-system-' . gmdate('Ymd-His') . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('X-Content-Type-Options: nosniff');
    echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    exit;
}

function seo_system_diagnostics_pdf_color($hex) {
    $hex = ltrim((string) $hex, '#');
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        $hex = '222222';
    }
    return array(
        hexdec(substr($hex, 0, 2)) / 255,
        hexdec(substr($hex, 2, 2)) / 255,
        hexdec(substr($hex, 4, 2)) / 255,
    );
}

function seo_system_diagnostics_pdf_encode($text) {
    $text = (string) $text;
    $text = str_replace(array("\r\n", "\r", "\t"), array("\n", "\n", ' '), $text);
    $text = str_replace(array('–', '—', '“', '”', '’', '•', '→', '·'), array('-', '-', '"', '"', "'", '-', '->', '-'), $text);
    if (function_exists('iconv')) {
        $encoded = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if (is_string($encoded)) {
            return $encoded;
        }
    }
    return preg_replace('/[^\x20-\x7E\x80-\xFF\n]/', '?', $text);
}

function seo_system_diagnostics_pdf_escape($text) {
    $text = seo_system_diagnostics_pdf_encode($text);
    return str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), $text);
}

function seo_system_diagnostics_pdf_strlen($text) {
    return function_exists('mb_strlen') ? mb_strlen((string) $text, 'UTF-8') : strlen((string) $text);
}

function seo_system_diagnostics_pdf_substr($text, $start, $length = null) {
    if (function_exists('mb_substr')) {
        return null === $length ? mb_substr((string) $text, $start, null, 'UTF-8') : mb_substr((string) $text, $start, $length, 'UTF-8');
    }
    return null === $length ? substr((string) $text, $start) : substr((string) $text, $start, $length);
}

function seo_system_diagnostics_pdf_wrap($text, $max_chars) {
    $text = trim(preg_replace('/\s+/', ' ', (string) $text));
    if ($text === '') {
        return array('');
    }

    $max_chars = max(12, (int) $max_chars);
    $words = preg_split('/\s+/', $text);
    $lines = array();
    $line = '';

    foreach ((array) $words as $word) {
        while (seo_system_diagnostics_pdf_strlen($word) > $max_chars) {
            if ($line !== '') {
                $lines[] = $line;
                $line = '';
            }
            $lines[] = seo_system_diagnostics_pdf_substr($word, 0, $max_chars - 1) . '-';
            $word = seo_system_diagnostics_pdf_substr($word, $max_chars - 1);
        }
        $candidate = $line === '' ? $word : $line . ' ' . $word;
        if (seo_system_diagnostics_pdf_strlen($candidate) > $max_chars && $line !== '') {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $candidate;
        }
    }

    if ($line !== '') {
        $lines[] = $line;
    }
    return $lines;
}

function seo_system_diagnostics_pdf_add_command(&$doc, $command) {
    $index = count($doc['pages']) - 1;
    $doc['pages'][$index][] = $command;
}

function seo_system_diagnostics_pdf_rect(&$doc, $x, $top, $width, $height, $fill) {
    $rgb = seo_system_diagnostics_pdf_color($fill);
    $pdf_y = $doc['height'] - $top - $height;
    seo_system_diagnostics_pdf_add_command(
        $doc,
        sprintf('q %.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f Q', $rgb[0], $rgb[1], $rgb[2], $x, $pdf_y, $width, $height)
    );
}

function seo_system_diagnostics_pdf_line(&$doc, $x1, $top1, $x2, $top2, $stroke, $line_width = 0.7) {
    $rgb = seo_system_diagnostics_pdf_color($stroke);
    $y1 = $doc['height'] - $top1;
    $y2 = $doc['height'] - $top2;
    seo_system_diagnostics_pdf_add_command(
        $doc,
        sprintf('q %.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S Q', $rgb[0], $rgb[1], $rgb[2], $line_width, $x1, $y1, $x2, $y2)
    );
}

function seo_system_diagnostics_pdf_text(&$doc, $x, $top, $text, $size = 10, $bold = false, $color = '#222222') {
    $rgb = seo_system_diagnostics_pdf_color($color);
    $pdf_y = $doc['height'] - $top - $size;
    $font = $bold ? 'F2' : 'F1';
    $escaped = seo_system_diagnostics_pdf_escape($text);
    seo_system_diagnostics_pdf_add_command(
        $doc,
        sprintf('BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET', $font, $size, $rgb[0], $rgb[1], $rgb[2], $x, $pdf_y, $escaped)
    );
}

function seo_system_diagnostics_pdf_new_page(&$doc, $section = '') {
    $doc['pages'][] = array();
    $doc['y'] = 48;
    seo_system_diagnostics_pdf_rect($doc, 0, 0, $doc['width'], 54, '#123B5D');
    seo_system_diagnostics_pdf_text($doc, $doc['margin'], 17, 'SEO System - Informe completo', 15, true, '#FFFFFF');
    if ($section !== '') {
        seo_system_diagnostics_pdf_text($doc, $doc['width'] - $doc['margin'] - 170, 20, $section, 9, false, '#DCEAF4');
    }
    $doc['y'] = 76;
}

function seo_system_diagnostics_pdf_ensure_space(&$doc, $height, $section = '') {
    if ($doc['y'] + $height > $doc['height'] - $doc['bottom']) {
        seo_system_diagnostics_pdf_new_page($doc, $section);
    }
}

function seo_system_diagnostics_pdf_heading(&$doc, $text, $level = 2) {
    $size = 2 === $level ? 16 : 12;
    $gap = 2 === $level ? 30 : 24;
    seo_system_diagnostics_pdf_ensure_space($doc, 2 === $level ? 92 : 72, $text);
    seo_system_diagnostics_pdf_text($doc, $doc['margin'], $doc['y'], $text, $size, true, 2 === $level ? '#123B5D' : '#1D5D82');
    $doc['y'] += $gap;
}

function seo_system_diagnostics_pdf_paragraph(&$doc, $text, $size = 9.5, $color = '#333333', $indent = 0, $after = 8) {
    $available = $doc['width'] - ($doc['margin'] * 2) - $indent;
    $max_chars = (int) floor($available / max(4.5, $size * 0.52));
    $lines = seo_system_diagnostics_pdf_wrap($text, $max_chars);
    $line_height = $size * 1.38;
    seo_system_diagnostics_pdf_ensure_space($doc, count($lines) * $line_height + $after, 'Continuacion');
    foreach ($lines as $line) {
        seo_system_diagnostics_pdf_text($doc, $doc['margin'] + $indent, $doc['y'], $line, $size, false, $color);
        $doc['y'] += $line_height;
    }
    $doc['y'] += $after;
}

function seo_system_diagnostics_pdf_key_value(&$doc, $key, $value) {
    seo_system_diagnostics_pdf_ensure_space($doc, 18, 'Datos del informe');
    seo_system_diagnostics_pdf_text($doc, $doc['margin'], $doc['y'], $key, 9.5, true, '#334E68');
    seo_system_diagnostics_pdf_text($doc, $doc['margin'] + 135, $doc['y'], (string) $value, 9.5, false, '#222222');
    $doc['y'] += 17;
}

function seo_system_diagnostics_pdf_score_boxes(&$doc, $scores) {
    $gap = 10; $columns = 3; $box_width = (($doc['width'] - ($doc['margin'] * 2)) - ($gap * ($columns - 1))) / $columns; $box_height = 72;
    seo_system_diagnostics_pdf_ensure_space($doc, $box_height + 14, 'Puntuaciones'); $start_y = $doc['y'];
    foreach (array_values($scores) as $index => $score) {
        $column = $index % $columns;
        if ($index > 0 && 0 === $column) { $start_y += $box_height + $gap; seo_system_diagnostics_pdf_ensure_space($doc, $box_height + 14, 'Puntuaciones'); }
        $x = $doc['margin'] + ($column * ($box_width + $gap)); $raw = $score['score'] ?? null;
        $value = $raw === null ? null : max(0, min(100, (int) $raw));
        $fill = $value === null ? '#F0F0F1' : ($value >= 85 ? '#E8F5EC' : ($value >= 70 ? '#FFF6D9' : '#FDECEC'));
        seo_system_diagnostics_pdf_rect($doc, $x, $start_y, $box_width, $box_height, $fill);
        seo_system_diagnostics_pdf_text($doc, $x + 12, $start_y + 11, (string) ($score['label'] ?? ''), 9, true, '#334E68');
        seo_system_diagnostics_pdf_text($doc, $x + 12, $start_y + 29, $value === null ? 'Sin datos' : $value . '%', 21, true, '#123B5D');
        seo_system_diagnostics_pdf_text($doc, $x + 12, $start_y + 55, $value === null ? 'No evaluable' : seo_system_diagnostics_report_score_label($value), 8.5, false, '#52606D');
    }
    $rows = (int) ceil(count($scores) / $columns); $doc['y'] = $start_y + ($rows * $box_height) + (($rows - 1) * $gap) + 16;
}


function seo_system_diagnostics_pdf_incident(&$doc, $incident) {
    $impact = (string) ($incident['impact'] ?? 'warning');
    $fill = 'error' === $impact ? '#FDECEC' : ('important' === $impact ? '#FFF0E0' : '#FFF8DE');
    $label = trim((string) ($incident['label'] ?? 'Incidencia'));
    $detail = trim((string) ($incident['detail'] ?? ''));
    $url = trim((string) ($incident['url'] ?? ''));
    $id = trim((string) ($incident['id'] ?? ''));
    $source = trim((string) ($incident['source'] ?? ''));

    $label_lines = seo_system_diagnostics_pdf_wrap($label, 75);
    $detail_lines = seo_system_diagnostics_pdf_wrap($detail, 94);
    $url_lines = $url !== '' ? seo_system_diagnostics_pdf_wrap($url, 92) : array();
    $height = 30 + (count($label_lines) * 11) + (count($detail_lines) * 10) + (count($url_lines) * 9);
    seo_system_diagnostics_pdf_ensure_space($doc, $height + 8, 'Incidencias');

    seo_system_diagnostics_pdf_rect($doc, $doc['margin'], $doc['y'], $doc['width'] - ($doc['margin'] * 2), $height, $fill);
    seo_system_diagnostics_pdf_text($doc, $doc['margin'] + 10, $doc['y'] + 8, strtoupper($impact) . ' - ' . $id . ' - ' . strtoupper($source), 7.8, true, '#52606D');
    $top = $doc['y'] + 22;
    foreach ($label_lines as $line) {
        seo_system_diagnostics_pdf_text($doc, $doc['margin'] + 10, $top, $line, 10, true, '#1F2933');
        $top += 11;
    }
    foreach ($detail_lines as $line) {
        seo_system_diagnostics_pdf_text($doc, $doc['margin'] + 10, $top, $line, 8.5, false, '#333333');
        $top += 10;
    }
    foreach ($url_lines as $line) {
        seo_system_diagnostics_pdf_text($doc, $doc['margin'] + 10, $top, $line, 7.5, false, '#1D5D82');
        $top += 9;
    }
    $doc['y'] += $height + 8;
}

function seo_system_diagnostics_pdf_snapshot_checks(&$doc, $title, $snapshot, $maximum = 60) {
    seo_system_diagnostics_pdf_heading($doc, $title, 2);
    $snapshot = is_array($snapshot) ? $snapshot : array();
    $health = (array) ($snapshot['health'] ?? array());
    if (array_key_exists('score', $health)) {
        seo_system_diagnostics_pdf_key_value($doc, 'Puntuación', seo_system_diagnostics_format_score($health['score']));
    }

    $checks = (array) ($snapshot['checks'] ?? array());
    if (empty($checks)) {
        $printed = 0;
        foreach ($health as $key => $value) {
            if ('score' === (string) $key || !is_scalar($value) || $printed >= 18) {
                continue;
            }
            seo_system_diagnostics_pdf_key_value($doc, ucwords(str_replace('_', ' ', (string) $key)), (string) $value);
            $printed++;
        }
        if (0 === $printed && !isset($health['score'])) {
            seo_system_diagnostics_pdf_paragraph($doc, 'No hay una lista detallada de comprobaciones disponible en este módulo.', 8.8, '#52606D');
        }
        return;
    }

    $count = 0;
    foreach ($checks as $check) {
        if ($count >= $maximum) {
            seo_system_diagnostics_pdf_paragraph($doc, '- La lista se ha limitado a ' . $maximum . ' comprobaciones en el PDF. El JSON conserva más detalle.', 8.5, '#52606D', 8, 3);
            break;
        }
        $status = strtoupper((string) ($check['status'] ?? 'INFO'));
        $label = (string) ($check['label'] ?? ($check['code'] ?? 'Comprobación'));
        $value = trim((string) ($check['value'] ?? ''));
        $detail = trim((string) ($check['detail'] ?? ''));
        $line = '[' . $status . '] ' . $label;
        if ($value !== '') {
            $line .= ': ' . $value;
        }
        if ($detail !== '') {
            $line .= ' - ' . $detail;
        }
        seo_system_diagnostics_pdf_paragraph($doc, '- ' . $line, 8.5, '#333333', 8, 3);
        $count++;
    }
}

function seo_system_diagnostics_pdf_components(&$doc, $components) {
    seo_system_diagnostics_pdf_heading($doc, 'Componentes activos', 2);
    $items = (array) ($components['active_plugins'] ?? array());
    if (empty($items)) {
        seo_system_diagnostics_pdf_paragraph($doc, 'No se ha podido obtener el listado de plugins activos.', 8.8, '#52606D');
        return;
    }
    foreach (array_slice($items, 0, 60) as $plugin) {
        $line = (string) ($plugin['name'] ?? $plugin['file'] ?? 'Plugin');
        if (!empty($plugin['version'])) {
            $line .= ' ' . (string) $plugin['version'];
        }
        seo_system_diagnostics_pdf_paragraph($doc, '- ' . $line, 8.5, '#333333', 8, 2);
    }
    if (count($items) > 60) {
        seo_system_diagnostics_pdf_paragraph($doc, '- El PDF muestra 60 componentes. El JSON contiene el listado completo.', 8.5, '#52606D', 8, 2);
    }
}

function seo_system_diagnostics_pdf_build($payload) {
    $doc = array(
        'width'  => 595.28,
        'height' => 841.89,
        'margin' => 44,
        'bottom' => 42,
        'pages'  => array(),
        'y'      => 0,
    );

    seo_system_diagnostics_pdf_new_page($doc, 'Resumen ejecutivo');
    $summary = (array) ($payload['summary'] ?? array());
    $context = (array) ($payload['context'] ?? array());
    $semantic = (array) ($payload['semantic_health'] ?? array());

    seo_system_diagnostics_pdf_text($doc, $doc['margin'], $doc['y'], 'Estado general del sitio', 23, true, '#123B5D');
    $doc['y'] += 35;
    seo_system_diagnostics_pdf_paragraph(
        $doc,
        'Informe automatizado para conocer el estado técnico, funcional, comercial, SEO y semántico del sitio. Está preparado para compartir con un analista externo y conserva una versión JSON para análisis adicional.',
        10.5,
        '#52606D',
        0,
        14
    );

    seo_system_diagnostics_pdf_key_value($doc, 'Dominio', (string) ($context['site_domain'] ?? 'No incluido'));
    seo_system_diagnostics_pdf_key_value($doc, 'Fecha', date_i18n('Y-m-d H:i:s', (int) ($payload['generated_at'] ?? time())));
    seo_system_diagnostics_pdf_key_value($doc, 'ID del informe', (string) ($payload['report_id'] ?? ''));
    seo_system_diagnostics_pdf_key_value($doc, 'Estado', seo_system_diagnostics_report_status_label((string) ($summary['overall'] ?? 'info')));
    $doc['y'] += 9;

    seo_system_diagnostics_pdf_heading($doc, 'Puntuaciones', 2);
    seo_system_diagnostics_pdf_score_boxes($doc, array(
        array('label' => 'Servidor', 'score' => (int) ($summary['server_health']['score'] ?? 0)),
        array('label' => 'Funcionamiento', 'score' => (int) ($summary['core_health']['score'] ?? 0)),
        array('label' => 'Contenido y FAQs', 'score' => array_key_exists('score', $semantic) ? $semantic['score'] : null),
    ));

    seo_system_diagnostics_pdf_heading($doc, 'Inventario y entorno', 2);
    $inventory = (array) ($payload['site_inventory'] ?? array());
    foreach ((array) ($inventory['content'] ?? array()) as $content_type => $counts) {
        seo_system_diagnostics_pdf_key_value(
            $doc,
            ucfirst((string) $content_type),
            'Publicados: ' . (int) ($counts['publish'] ?? 0) . ' | Borradores: ' . (int) ($counts['draft'] ?? 0) . ' | Total: ' . (int) ($counts['total'] ?? 0)
        );
    }
    if (!empty($inventory['theme'])) {
        seo_system_diagnostics_pdf_key_value($doc, 'Tema', (string) ($inventory['theme']['name'] ?? '') . ' ' . (string) ($inventory['theme']['version'] ?? ''));
    }
    $components = (array) ($payload['components'] ?? array());
    seo_system_diagnostics_pdf_key_value($doc, 'Plugins activos', (string) ((int) ($components['active_count'] ?? 0)));

    seo_system_diagnostics_pdf_components($doc, $components);
    seo_system_diagnostics_pdf_snapshot_checks($doc, 'Comprobaciones del servidor', (array) ($payload['technical_snapshot'] ?? array()), 60);
    seo_system_diagnostics_pdf_snapshot_checks($doc, 'Comprobaciones funcionales', (array) ($payload['functional_snapshot'] ?? array()), 60);

    seo_system_diagnostics_pdf_heading($doc, 'Action Scheduler', 2);
    $scheduler = (array) ($payload['action_scheduler'] ?? array());
    if (empty($scheduler['available'])) {
        seo_system_diagnostics_pdf_paragraph($doc, 'Action Scheduler no está disponible o no se encontraron sus tablas.');
    } else {
        foreach ((array) ($scheduler['statuses'] ?? array()) as $status => $row) {
            seo_system_diagnostics_pdf_key_value($doc, ucfirst((string) $status), (int) ($row['total'] ?? 0) . ' | ' . (string) ($row['first_date'] ?? '') . ' - ' . (string) ($row['last_date'] ?? ''));
        }
        seo_system_diagnostics_pdf_key_value($doc, 'Pendientes vencidas', (string) ((int) ($scheduler['overdue_pending'] ?? 0)));
        foreach (array_slice((array) ($scheduler['top_hooks'] ?? array()), 0, 8) as $hook) {
            seo_system_diagnostics_pdf_paragraph($doc, '- ' . (string) ($hook['hook'] ?? '') . ' [' . (string) ($hook['status'] ?? '') . ']: ' . (int) ($hook['total'] ?? 0), 8.5, '#52606D', 8, 2);
        }
    }

    seo_system_diagnostics_pdf_heading($doc, 'Sitemaps', 2);
    foreach ((array) ($payload['sitemaps'] ?? array()) as $sitemap) {
        $status = !empty($sitemap['valid_xml_root']) ? 'XML válido' : 'Revisar';
        seo_system_diagnostics_pdf_paragraph(
            $doc,
            $status . ' | HTTP ' . (int) ($sitemap['http_code'] ?? 0) . ' | ' . (string) ($sitemap['url'] ?? ''),
            8.8,
            !empty($sitemap['valid_xml_root']) ? '#1D6B43' : '#A13A2A',
            0,
            4
        );
    }

    seo_system_diagnostics_pdf_heading($doc, 'Prioridades recomendadas', 2);
    $recommendations = (array) ($payload['recommendations'] ?? array());
    if (empty($recommendations)) {
        seo_system_diagnostics_pdf_paragraph($doc, 'No se han generado prioridades automáticas.');
    } else {
        foreach ($recommendations as $recommendation) {
            seo_system_diagnostics_pdf_paragraph(
                $doc,
                '[' . strtoupper((string) ($recommendation['priority'] ?? 'warning')) . '] ' . (string) ($recommendation['title'] ?? ($recommendation['action'] ?? '')) . ' - Responsable: ' . (string) ($recommendation['owner'] ?? 'WP'),
                8.8,
                '#333333',
                8,
                3
            );
        }
    }

    seo_system_diagnostics_pdf_heading($doc, 'Incidencias por gravedad', 2);
    seo_system_diagnostics_pdf_paragraph(
        $doc,
        'Críticas: ' . (int) ($summary['incidents']['error'] ?? 0) . '  |  Importantes: ' . (int) ($summary['incidents']['important'] ?? 0) . '  |  Avisos: ' . (int) ($summary['incidents']['warning'] ?? 0) . '.',
        10,
        '#333333'
    );

    seo_system_diagnostics_pdf_heading($doc, 'Incidencias detalladas', 2);
    $incidents = (array) ($payload['incidents'] ?? array());
    if (empty($incidents)) {
        seo_system_diagnostics_pdf_paragraph($doc, 'No se han registrado incidencias en los chequeos disponibles.');
    } else {
        $order = array('error' => 0, 'important' => 1, 'warning' => 2);
        usort($incidents, static function ($a, $b) use ($order) {
            $left = $order[$a['impact'] ?? 'warning'] ?? 3;
            $right = $order[$b['impact'] ?? 'warning'] ?? 3;
            return $left <=> $right;
        });
        foreach ($incidents as $incident) {
            seo_system_diagnostics_pdf_incident($doc, $incident);
        }
    }

    seo_system_diagnostics_pdf_heading($doc, 'Metodología y límites', 2);
    if (!empty($payload['methodology']['scope'])) {
        seo_system_diagnostics_pdf_paragraph($doc, (string) $payload['methodology']['scope'], 9, '#333333');
    }
    foreach ((array) ($payload['methodology']['limitations'] ?? array()) as $limitation) {
        seo_system_diagnostics_pdf_paragraph($doc, '- ' . $limitation, 9, '#52606D', 8, 3);
    }

    seo_system_diagnostics_pdf_heading($doc, 'Privacidad', 2);
    seo_system_diagnostics_pdf_paragraph($doc, 'El informe excluye usuarios, clientes, pedidos, direcciones, contraseñas, cookies, filas de tablas y logs completos.');
    seo_system_diagnostics_pdf_paragraph($doc, 'Para una revisión automatizada detallada se recomienda adjuntar también el archivo JSON descargable desde el mismo panel.', 9, '#52606D');

    $page_count = count($doc['pages']);
    foreach ($doc['pages'] as $index => &$commands) {
        $commands[] = sprintf('BT /F1 7.5 Tf 0.35 0.40 0.45 rg %.2F %.2F Td (%s) Tj ET', $doc['margin'], 24, seo_system_diagnostics_pdf_escape('Informe completo automatizado - requiere interpretación profesional'));
        $page_label = 'Página ' . ($index + 1) . ' de ' . $page_count;
        $commands[] = sprintf('BT /F1 7.5 Tf 0.35 0.40 0.45 rg %.2F %.2F Td (%s) Tj ET', $doc['width'] - $doc['margin'] - 68, 24, seo_system_diagnostics_pdf_escape($page_label));
    }
    unset($commands);

    return seo_system_diagnostics_pdf_compile($doc['pages'], $payload);
}

function seo_system_diagnostics_pdf_compile($pages, $payload) {
    $objects = array();
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

    $kids = array();
    $object_id = 5;
    foreach ((array) $pages as $commands) {
        $page_id = $object_id++;
        $content_id = $object_id++;
        $kids[] = $page_id . ' 0 R';
        $stream = implode("\n", (array) $commands);
        $objects[$page_id] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $content_id . ' 0 R >>';
        $objects[$content_id] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';

    $info_id = $object_id++;
    $context = (array) ($payload['context'] ?? array());
    $title = 'Informe completo SEO System - ' . (string) ($context['site_domain'] ?? 'SEO System');
    $objects[$info_id] = '<< /Title (' . seo_system_diagnostics_pdf_escape($title) . ') /Author (SEO System) /Subject (Auditoria tecnica, funcional, comercial, SEO y semantica) /Creator (SEO System Diagnostics) /CreationDate (D:' . gmdate('YmdHis') . 'Z) >>';

    ksort($objects, SORT_NUMERIC);
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = array(0 => 0);
    $max_object = max(array_keys($objects));
    for ($i = 1; $i <= $max_object; $i++) {
        if (!isset($objects[$i])) {
            $objects[$i] = '<< >>';
        }
        $offsets[$i] = strlen($pdf);
        $pdf .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . ($max_object + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $max_object; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= 'trailer << /Size ' . ($max_object + 1) . ' /Root 1 0 R /Info ' . $info_id . " 0 R >>\n";
    $pdf .= "startxref\n" . $xref . "\n%%EOF";
    return $pdf;
}

