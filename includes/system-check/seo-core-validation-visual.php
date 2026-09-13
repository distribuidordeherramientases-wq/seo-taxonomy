<?php
/**
 * SEO Core - auditoria visual responsive mediante navegador remoto.
 *
 * WordPress conserva la configuracion y los resultados. El renderizado real se
 * ejecuta en GitHub Actions con Playwright para no lanzar Chromium en el hosting.
 *
 * @package SEOSystem
 * @subpackage PluginValidation
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

function seo_core_visual_workflow_id() {
    if (defined('SEO_CORE_VISUAL_WORKFLOW_ID') && SEO_CORE_VISUAL_WORKFLOW_ID !== '') {
        return sanitize_file_name((string) SEO_CORE_VISUAL_WORKFLOW_ID);
    }
    return sanitize_file_name((string) apply_filters('seo_core_visual_workflow_id', 'visual-responsive-check.yml'));
}

function seo_core_visual_github_settings() {
    if (function_exists('seo_github_python_runner_settings')) {
        $saved = seo_github_python_runner_settings();
    } else {
        $saved = get_option('seo_github_python_runner_settings', array());
    }
    $saved = is_array($saved) ? $saved : array();

    return array(
        'owner'       => trim((string) ($saved['owner'] ?? '')),
        'repo'        => trim((string) ($saved['repo'] ?? '')),
        'ref'         => trim((string) ($saved['ref'] ?? 'main')),
        'token'       => trim((string) ($saved['token'] ?? '')),
        'workflow_id' => seo_core_visual_workflow_id(),
    );
}

function seo_core_visual_is_configured() {
    $settings = seo_core_visual_github_settings();
    foreach (array('owner', 'repo', 'ref', 'token', 'workflow_id') as $key) {
        if ($settings[$key] === '') {
            return false;
        }
    }
    return true;
}

function seo_core_visual_state_option_name() {
    return 'seo_core_visual_responsive_state_v1';
}

function seo_core_visual_get_state() {
    $state = get_option(seo_core_visual_state_option_name(), array());
    return is_array($state) ? $state : array();
}

function seo_core_visual_store_state($state) {
    $state = is_array($state) ? $state : array();
    $state['updated_at'] = time();
    update_option(seo_core_visual_state_option_name(), $state, false);
    return $state;
}

function seo_core_visual_target_urls() {
    $urls = array();
    $home = home_url('/');
    if ($home !== '') {
        $urls['portada'] = $home;
    }

    if (function_exists('wc_get_page_id')) {
        foreach (array('shop' => 'tienda', 'cart' => 'carrito', 'checkout' => 'checkout') as $page_key => $label) {
            $page_id = (int) wc_get_page_id($page_key);
            if ($page_id > 0 && get_post_status($page_id)) {
                $url = get_permalink($page_id);
                if (is_string($url) && $url !== '') {
                    $urls[$label] = $url;
                }
            }
        }
    }

    $product = function_exists('seo_core_system_test_get_representative_product')
        ? seo_core_system_test_get_representative_product()
        : array();
    if (!empty($product['url'])) {
        $urls['producto'] = esc_url_raw((string) $product['url']);
    }

    $category = function_exists('seo_core_system_test_get_representative_category_for_product')
        ? seo_core_system_test_get_representative_category_for_product($product)
        : array();
    if (!empty($category['url'])) {
        $urls['categoria'] = esc_url_raw((string) $category['url']);
    }

    if (!empty($product['title'])) {
        $urls['busqueda'] = add_query_arg(
            array(
                's'         => wp_strip_all_tags((string) $product['title']),
                'post_type' => 'product',
            ),
            $home
        );
    }

    $urls = array_filter(array_map('esc_url_raw', $urls));
    $urls = array_slice($urls, 0, 7, true);
    return (array) apply_filters('seo_core_visual_target_urls', $urls, $product, $category);
}

function seo_core_visual_dispatch($origin = 'manual_visual', $force = false) {
    if (!seo_core_visual_is_configured()) {
        return new WP_Error(
            'seo_core_visual_not_configured',
            'El chequeo visual necesita la conexion GitHub Actions configurada (usuario, repositorio, rama y token).'
        );
    }

    $current = seo_core_visual_get_state();
    if (!$force && in_array((string) ($current['status'] ?? ''), array('queued', 'running'), true)) {
        $updated = (int) ($current['updated_at'] ?? 0);
        if ($updated > 0 && (time() - $updated) < 15 * MINUTE_IN_SECONDS) {
            return array('ok' => true, 'already_running' => true, 'run_id' => (string) ($current['run_id'] ?? ''));
        }
    }

    $urls = seo_core_visual_target_urls();
    if (empty($urls)) {
        return new WP_Error('seo_core_visual_no_urls', 'No se han podido resolver URLs publicas para el chequeo visual.');
    }

    $settings = seo_core_visual_github_settings();
    $run_id = wp_generate_uuid4();
    $callback_token = wp_generate_password(48, false, false);
    $callback_url = rest_url('seo-taxonomy/v1/visual-check/callback');
    $urls_json = wp_json_encode($urls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($urls_json) || $urls_json === '') {
        return new WP_Error('seo_core_visual_urls_encode', 'No se ha podido preparar la lista de URLs del chequeo visual.');
    }

    $api_url = sprintf(
        'https://api.github.com/repos/%s/%s/actions/workflows/%s/dispatches',
        rawurlencode($settings['owner']),
        rawurlencode($settings['repo']),
        rawurlencode($settings['workflow_id'])
    );
    $body = array(
        'ref' => $settings['ref'],
        'inputs' => array(
            'callback_url'   => $callback_url,
            'callback_token' => $callback_token,
            'run_id'         => $run_id,
            'urls_b64'       => base64_encode($urls_json),
        ),
    );

    $response = wp_remote_post(
        esc_url_raw($api_url),
        array(
            'timeout' => 25,
            'headers' => array(
                'Authorization'        => 'Bearer ' . $settings['token'],
                'Accept'               => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent'           => 'SEO-System-Visual-Validation',
                'Content-Type'         => 'application/json; charset=utf-8',
            ),
            'body' => wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        )
    );

    if (is_wp_error($response)) {
        seo_core_visual_store_state(array(
            'status'  => 'error',
            'origin'  => sanitize_key((string) $origin),
            'message' => $response->get_error_message(),
        ));
        return $response;
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        $message = 'GitHub Actions ha respondido HTTP ' . $status . '.';
        if (is_array($decoded) && !empty($decoded['message'])) {
            $message .= ' ' . sanitize_text_field((string) $decoded['message']);
        }
        $error = new WP_Error('seo_core_visual_github_error', $message, array('status' => $status));
        seo_core_visual_store_state(array(
            'status'  => 'error',
            'origin'  => sanitize_key((string) $origin),
            'message' => $message,
        ));
        return $error;
    }

    set_transient(
        'seo_core_visual_run_' . md5($callback_token),
        array(
            'run_id'         => $run_id,
            'callback_token' => $callback_token,
            'started_at'     => time(),
            'urls'           => $urls,
        ),
        6 * HOUR_IN_SECONDS
    );

    seo_core_visual_store_state(array(
        'status'      => 'queued',
        'run_id'      => $run_id,
        'origin'      => sanitize_key((string) $origin),
        'started_at'  => time(),
        'message'     => 'Chequeo visual enviado a GitHub Actions.',
        'urls'        => $urls,
        'workflow_id' => $settings['workflow_id'],
    ));

    return array('ok' => true, 'run_id' => $run_id);
}

function seo_core_visual_auto_dispatch_after_validation($event) {
    if (!is_array($event) || ($event['scope'] ?? '') !== 'general') {
        return;
    }
    $origin = sanitize_key((string) ($event['origin'] ?? ''));
    $origins = (array) apply_filters('seo_core_visual_auto_run_origins', array('manual', 'plugin_update'));
    if (!in_array($origin, $origins, true) || !seo_core_visual_is_configured()) {
        return;
    }
    seo_core_visual_dispatch('auto_' . $origin, false);
}
add_action('seo_core_system_test_completed', 'seo_core_visual_auto_dispatch_after_validation', 30, 1);

function seo_core_visual_admin_run() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para ejecutar este chequeo.', 'seo-system'));
    }
    check_admin_referer('seo_core_visual_run', 'seo_core_visual_nonce');

    $result = seo_core_visual_dispatch('manual_visual', true);
    $message = is_wp_error($result) ? 'error' : 'started';
    if (is_wp_error($result)) {
        set_transient('seo_core_visual_notice_' . get_current_user_id(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS);
    }

    $url = add_query_arg(
        array(
            'page'                  => 'seo-core-system-test',
            'seo_core_test_tab'     => 'advanced',
            'seo_core_test_section' => 'visual',
            'seo_visual_msg'        => $message,
        ),
        admin_url('admin.php')
    );
    wp_safe_redirect($url);
    exit;
}
add_action('admin_post_seo_core_visual_run', 'seo_core_visual_admin_run');

function seo_core_visual_register_rest_route() {
    register_rest_route(
        'seo-taxonomy/v1',
        '/visual-check/callback',
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'seo_core_visual_callback',
            'permission_callback' => '__return_true',
        )
    );
}
add_action('rest_api_init', 'seo_core_visual_register_rest_route');

function seo_core_visual_authorize_callback(WP_REST_Request $request) {
    $authorization = trim((string) $request->get_header('authorization'));
    $token = '';
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match)) {
        $token = trim((string) $match[1]);
    }
    if ($token === '') {
        $token = trim((string) $request->get_param('callback_token'));
    }
    if ($token === '') {
        return new WP_Error('seo_core_visual_auth_missing', 'Token de callback ausente.', array('status' => 401));
    }

    $run = get_transient('seo_core_visual_run_' . md5($token));
    if (!is_array($run) || empty($run['callback_token']) || !hash_equals((string) $run['callback_token'], $token)) {
        return new WP_Error('seo_core_visual_auth_invalid', 'Token de callback no valido o caducado.', array('status' => 403));
    }
    return $run;
}

function seo_core_visual_sanitize_report($report) {
    if (!is_array($report)) {
        return array();
    }
    $clean = array(
        'score'          => max(0, min(100, (int) ($report['score'] ?? 0))),
        'pages_expected' => max(0, (int) ($report['pages_expected'] ?? 0)),
        'viewport_runs'  => max(0, (int) ($report['viewport_runs'] ?? 0)),
        'expected_runs'  => max(0, (int) ($report['expected_runs'] ?? 0)),
        'github_run_url' => esc_url_raw((string) ($report['github_run_url'] ?? '')),
        'summary'        => array(),
        'issues'         => array(),
    );
    $summary = is_array($report['summary'] ?? null) ? $report['summary'] : array();
    foreach (array(
        'navigation_errors',
        'viewport_meta_failures',
        'horizontal_overflow',
        'essential_offscreen',
        'clipped_text',
        'overlaps',
        'broken_images',
        'console_errors'
    ) as $key) {
        $clean['summary'][$key] = max(0, (int) ($summary[$key] ?? 0));
    }

    $issues = is_array($report['issues'] ?? null) ? array_slice($report['issues'], 0, 120) : array();
    foreach ($issues as $issue) {
        if (!is_array($issue)) {
            continue;
        }
        $clean['issues'][] = array(
            'page'     => sanitize_key((string) ($issue['page'] ?? '')),
            'viewport' => sanitize_key((string) ($issue['viewport'] ?? '')),
            'type'     => sanitize_key((string) ($issue['type'] ?? '')),
            'detail'   => sanitize_text_field((string) ($issue['detail'] ?? '')),
        );
    }
    return $clean;
}

function seo_core_visual_callback(WP_REST_Request $request) {
    $run = seo_core_visual_authorize_callback($request);
    if (is_wp_error($run)) {
        return $run;
    }

    $params = $request->get_json_params();
    $params = is_array($params) ? $params : array();
    $run_id = sanitize_text_field((string) ($params['run_id'] ?? $request->get_param('run_id')));
    if ($run_id === '' || !hash_equals((string) ($run['run_id'] ?? ''), $run_id)) {
        return new WP_Error('seo_core_visual_run_mismatch', 'La ejecucion no coincide con el token temporal.', array('status' => 409));
    }

    $status = sanitize_key((string) ($params['status'] ?? $request->get_param('status')));
    if (!in_array($status, array('started', 'progress', 'completed', 'error'), true)) {
        $status = 'progress';
    }
    $message = sanitize_text_field((string) ($params['message'] ?? $request->get_param('message')));

    if ($status === 'error') {
        seo_core_visual_store_state(array(
            'status'       => 'error',
            'run_id'       => $run_id,
            'started_at'   => (int) ($run['started_at'] ?? time()),
            'completed_at' => time(),
            'message'      => $message !== '' ? $message : 'El navegador remoto no pudo completar la auditoria.',
            'urls'         => isset($run['urls']) && is_array($run['urls']) ? $run['urls'] : array(),
        ));
        delete_transient('seo_core_visual_run_' . md5((string) $run['callback_token']));
        return rest_ensure_response(array('ok' => true, 'accepted' => 'error'));
    }

    if ($status !== 'completed') {
        $current = seo_core_visual_get_state();
        $current['status'] = $status === 'started' ? 'running' : 'running';
        $current['run_id'] = $run_id;
        $current['started_at'] = (int) ($run['started_at'] ?? time());
        $current['message'] = $message !== '' ? $message : 'Navegador remoto en ejecucion.';
        seo_core_visual_store_state($current);
        return rest_ensure_response(array('ok' => true, 'accepted' => $status));
    }

    $report = seo_core_visual_sanitize_report($params['report'] ?? array());
    if (empty($report)) {
        return new WP_Error('seo_core_visual_report_missing', 'El runner no ha enviado un informe visual valido.', array('status' => 400));
    }

    seo_core_visual_store_state(array(
        'status'       => 'completed',
        'run_id'       => $run_id,
        'started_at'   => (int) ($run['started_at'] ?? time()),
        'completed_at' => time(),
        'message'      => $message !== '' ? $message : 'Auditoria visual completada.',
        'urls'         => isset($run['urls']) && is_array($run['urls']) ? $run['urls'] : array(),
        'report'       => $report,
    ));
    delete_transient('seo_core_visual_run_' . md5((string) $run['callback_token']));

    return rest_ensure_response(array('ok' => true, 'accepted' => 'completed'));
}

function seo_core_visual_issue_examples($report, $types, $limit = 4) {
    $types = (array) $types;
    $examples = array();
    foreach ((array) ($report['issues'] ?? array()) as $issue) {
        if (!is_array($issue) || !in_array((string) ($issue['type'] ?? ''), $types, true)) {
            continue;
        }
        $label = trim((string) ($issue['page'] ?? ''));
        if (!empty($issue['viewport'])) {
            $label .= '/' . (string) $issue['viewport'];
        }
        if (!empty($issue['detail'])) {
            $label .= ': ' . (string) $issue['detail'];
        }
        if ($label !== '') {
            $examples[] = $label;
        }
        if (count($examples) >= $limit) {
            break;
        }
    }
    return $examples;
}

function seo_core_system_test_visual_results() {
    $results = array();
    $configured = seo_core_visual_is_configured();
    $settings = seo_core_visual_github_settings();
    $state = seo_core_visual_get_state();

    $results[] = seo_core_system_test_result(
        'visual',
        '10.1 Navegador remoto configurado',
        $configured,
        $configured
            ? 'GitHub Actions disponible para el workflow ' . $settings['workflow_id'] . '.'
            : 'Pendiente de configurar GitHub Actions. El chequeo visual no se ejecuta dentro del hosting.',
        $configured ? 'ok' : 'info',
        array('status' => $configured ? 'pass' : 'not_evaluable', 'coverage' => $configured ? 100 : 0, 'confidence' => 100)
    );

    $status = (string) ($state['status'] ?? '');
    if ($status === '' || $status === 'never') {
        $results[] = seo_core_system_test_result(
            'visual',
            '10.2 Ultima auditoria visual',
            false,
            'Todavia no se ha ejecutado una auditoria visual responsive.',
            'info',
            array('status' => 'not_evaluable', 'coverage' => 0, 'confidence' => 0)
        );
        return $results;
    }

    if (in_array($status, array('queued', 'running'), true)) {
        $results[] = seo_core_system_test_result(
            'visual',
            '10.2 Ultima auditoria visual',
            true,
            $status === 'queued' ? 'Auditoria enviada a GitHub Actions; pendiente de iniciar.' : 'Auditoria visual en ejecucion.',
            'info',
            array('status' => 'info', 'coverage' => 0, 'confidence' => 100)
        );
        return $results;
    }

    if ($status === 'error') {
        $results[] = seo_core_system_test_result(
            'visual',
            '10.2 Ultima auditoria visual',
            false,
            'El runner visual no pudo finalizar: ' . (string) ($state['message'] ?? 'error remoto sin detalle'),
            'warning',
            array('confidence' => 100)
        );
        return $results;
    }

    $report = isset($state['report']) && is_array($state['report']) ? $state['report'] : array();
    $summary = isset($report['summary']) && is_array($report['summary']) ? $report['summary'] : array();
    if (empty($report)) {
        $results[] = seo_core_system_test_result('visual', '10.2 Ultima auditoria visual', false, 'Existe estado de finalizacion pero falta el informe remoto.', 'warning');
        return $results;
    }

    $completed = (int) ($state['completed_at'] ?? 0);
    $date = $completed > 0 ? wp_date('d/m/Y H:i:s', $completed, wp_timezone()) : 'fecha no disponible';
    $run_url = (string) ($report['github_run_url'] ?? '');
    $detail = 'Completada ' . $date . '. Puntuacion visual: ' . (int) ($report['score'] ?? 0) . '%.';
    if ($run_url !== '') {
        $detail .= ' Capturas y artefactos: ' . $run_url;
    }
    $results[] = seo_core_system_test_result(
        'visual',
        '10.2 Ultima auditoria visual',
        true,
        $detail,
        'info',
        array('evidence' => array('github_run_url' => $run_url, 'run_id' => (string) ($state['run_id'] ?? '')), 'confidence' => 100)
    );

    $expected = max(1, (int) ($report['expected_runs'] ?? 0));
    $executed = max(0, (int) ($report['viewport_runs'] ?? 0));
    $coverage = (int) min(100, round(($executed / $expected) * 100));
    $nav_errors = (int) ($summary['navigation_errors'] ?? 0);
    $coverage_ok = $executed >= $expected && $nav_errors === 0;
    $results[] = seo_core_system_test_result(
        'visual',
        '10.3 Cobertura movil, tablet y escritorio',
        $coverage_ok,
        'Renderizados: ' . $executed . '/' . $expected . '; errores de navegacion: ' . $nav_errors . '.',
        $coverage_ok ? 'ok' : 'warning',
        array('coverage' => $coverage, 'confidence' => 100, 'evidence' => array('issues' => seo_core_visual_issue_examples($report, array('navigation_error'))))
    );

    $checks = array(
        array('10.4 Viewport movil correctamente declarado', 'viewport_meta_failures', array('viewport_meta'), 'warning', 'fallos'),
        array('10.5 Sin desbordamiento horizontal', 'horizontal_overflow', array('horizontal_overflow'), 'ko', 'desbordamientos'),
        array('10.6 Elementos esenciales dentro de pantalla', 'essential_offscreen', array('essential_offscreen'), 'ko', 'elementos fuera de pantalla'),
        array('10.7 Texto visible sin recortes anormales', 'clipped_text', array('clipped_text'), 'warning', 'recortes'),
        array('10.8 Sin solapamientos anormales', 'overlaps', array('overlap'), 'warning', 'solapamientos'),
        array('10.9 Imagenes visibles renderizadas', 'broken_images', array('broken_image'), 'ko', 'imagenes rotas'),
        array('10.10 Consola del navegador sin errores relevantes', 'console_errors', array('console_error', 'page_error'), 'warning', 'errores'),
    );

    $hard_fail = false;
    foreach ($checks as $definition) {
        list($label, $key, $types, $failure_severity, $noun) = $definition;
        $count = max(0, (int) ($summary[$key] ?? 0));
        $passed = $count === 0;
        if (!$passed && $failure_severity === 'ko') {
            $hard_fail = true;
        }
        $examples = seo_core_visual_issue_examples($report, $types);
        $check_detail = $passed
            ? 'No se han detectado incidencias en los viewports comprobados.'
            : number_format_i18n($count) . ' ' . $noun . ' detectados.' . (!empty($examples) ? ' Ejemplos: ' . implode(' | ', $examples) : '');
        $results[] = seo_core_system_test_result(
            'visual',
            $label,
            $passed,
            $check_detail,
            $passed ? 'ok' : $failure_severity,
            array('confidence' => 90, 'evidence' => array('count' => $count, 'examples' => $examples, 'github_run_url' => $run_url))
        );
    }

    $score = max(0, min(100, (int) ($report['score'] ?? 0)));
    $aggregate_passed = !$hard_fail && $score >= 90;
    $aggregate_severity = $aggregate_passed ? 'ok' : ($score >= 80 && !$hard_fail ? 'warning' : 'ko');
    $results[] = seo_core_system_test_result(
        'visual',
        '10.11 Salud visual responsive',
        $aggregate_passed,
        'Puntuacion: ' . $score . '%. Se han comprobado geometria, overflow, recortes, solapamientos, imagenes y errores del navegador en varios tamanos de pantalla.',
        $aggregate_severity,
        array('confidence' => 88, 'evidence' => array('summary' => $summary, 'github_run_url' => $run_url))
    );

    return $results;
}

function seo_core_visual_render_controls() {
    $settings = seo_core_visual_github_settings();
    $state = seo_core_visual_get_state();
    $message = isset($_GET['seo_visual_msg']) ? sanitize_key(wp_unslash($_GET['seo_visual_msg'])) : '';

    if ($message === 'started') {
        echo '<div class="notice notice-success inline"><p>Chequeo visual enviado a GitHub Actions. El resultado llegara automaticamente a esta seccion.</p></div>';
    } elseif ($message === 'error') {
        $notice = get_transient('seo_core_visual_notice_' . get_current_user_id());
        delete_transient('seo_core_visual_notice_' . get_current_user_id());
        echo '<div class="notice notice-error inline"><p>' . esc_html(is_string($notice) && $notice !== '' ? $notice : 'No se pudo iniciar el chequeo visual.') . '</p></div>';
    }

    echo '<div class="seo-core-test-note">';
    echo '<strong>Renderizado real:</strong> este bloque usa Chromium/Playwright en GitHub Actions. WordPress no ejecuta un navegador dentro del hosting. '; 
    echo 'Workflow: <code>' . esc_html($settings['workflow_id']) . '</code>. '; 
    echo 'Estado: <strong>' . esc_html((string) ($state['status'] ?? 'sin ejecutar')) . '</strong>.';
    echo '</div>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:12px 0 18px;">';
    echo '<input type="hidden" name="action" value="seo_core_visual_run">';
    wp_nonce_field('seo_core_visual_run', 'seo_core_visual_nonce');
    $button_attributes = seo_core_visual_is_configured() ? array() : array('disabled' => 'disabled');
    submit_button('Ejecutar chequeo responsive ahora', 'secondary', 'submit', false, $button_attributes);
    if (!seo_core_visual_is_configured()) {
        echo '<p class="description">Configura primero la conexion GitHub Actions que ya utiliza SEO System. El workflow visual debe existir en el mismo repositorio.</p>';
    } else {
        echo '<p class="description">Se prueban hasta 7 URLs representativas en movil, tablet y escritorio. Las capturas completas quedan como artefacto del workflow.</p>';
    }
    echo '</form>';
}
