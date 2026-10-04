<?php
/**
 * Plugin Validation - salud de servicios y conexiones externas.
 *
 * Cada servicio produce UNA fila agregada con puntuacion 0-100. Esa fila forma
 * parte del mismo array de resultados de Plugin Validation y, por tanto, entra
 * en el calculo global.
 *
 * Las pruebas internas son de solo lectura. Google/GitHub solo hacen una
 * lectura real minima en ejecuciones manuales; telemetria programada no llama
 * APIs externas.
 */

defined('ABSPATH') || exit;

function seo_core_service_health_table_probe($suffix) {
    global $wpdb;

    $suffix = preg_replace('/[^a-z0-9_]/i', '', (string) $suffix);
    $table = $wpdb->prefix . $suffix;
    $exists = function_exists('seo_core_system_test_table_exists')
        ? seo_core_system_test_table_exists($table)
        : ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table);

    if (!$exists) {
        return array('name' => $suffix, 'exists' => false, 'count' => null, 'error' => '');
    }

    $sql = $wpdb->prepare('SELECT COUNT(*) FROM %i', $table);
    $count = is_string($sql) && $sql !== '' ? $wpdb->get_var($sql) : null;
    $error = sanitize_text_field((string) $wpdb->last_error);

    return array(
        'name' => $suffix,
        'exists' => true,
        'count' => $error === '' ? (int) $count : null,
        'error' => $error,
    );
}

function seo_core_service_health_component_probe($type, $name) {
    $type = sanitize_key((string) $type);
    $name = (string) $name;

    if ($type === 'class') {
        return class_exists($name);
    }
    if ($type === 'function') {
        return function_exists($name);
    }
    if ($type === 'constant') {
        return defined($name);
    }
    return false;
}

function seo_core_service_health_registry() {
    $registry = array(
        'solucionador' => array(
            'label' => 'Solucionador',
            'components' => array(
                array('class', 'SEO_Solucionador_Catalog'),
                array('class', 'SEO_Solucionador_Coverage'),
            ),
            'tables' => array('seo_solucionador_topics', 'seo_solucionador_dossiers'),
        ),
        'ingeniero' => array(
            'label' => 'Ingeniero',
            'components' => array(
                array('class', 'SEO_Ingeniero_DB'),
                array('class', 'SEO_Ingeniero_Process'),
            ),
            'tables' => array('seo_ingeniero_sources', 'seo_ingeniero_knowledge', 'seo_ingeniero_editorial'),
        ),
        'comparador' => array(
            'label' => 'Comparador',
            'components' => array(
                array('class', 'SEO_Comparador_DB'),
                array('class', 'SEO_Comparador_Engine'),
            ),
            'tables' => array('seo_comparador_profiles', 'seo_comparador_products', 'seo_comparador_editorial'),
        ),
        'dependiente' => array(
            'label' => 'Dependiente',
            'components' => array(
                array('class', 'SEO_Dependiente_V3_DB'),
                array('class', 'SEO_Dependiente_V3_App'),
            ),
            'tables' => array('seo_dependiente_index', 'seo_dependiente_semantics'),
        ),
        'academia' => array(
            'label' => 'Academia',
            'components' => array(
                array('class', 'SEO_Dependiente_Entrenador'),
            ),
            'tables' => array('seo_dependiente_trainer_questions', 'seo_dependiente_trainer_runs', 'seo_dependiente_trainer_lessons'),
        ),
        'interprete' => array(
            'label' => 'Intérprete',
            'components' => array(
                array('class', 'SEO_Dependiente_Interprete'),
                array('class', 'SEO_Dependiente_Interprete_DB'),
            ),
            'tables' => array('seo_interprete_lexicon', 'seo_interprete_lexicon_evidence'),
        ),
        'ojeador' => array(
            'label' => 'Ojeador',
            'components' => array(
                array('class', 'SEO_Ojeador_DB'),
                array('class', 'SEO_Ojeador_Worker'),
            ),
            'tables' => array('seo_ojeador_market_categories', 'seo_ojeador_market_category_results'),
        ),
        'comentarista' => array(
            'label' => 'Comentarista',
            'components' => array(
                array('function', 'seo_comentarista_table_name'),
                array('function', 'seo_comentarista_get_indicators'),
            ),
            'tables' => array('seo_comentarista'),
        ),
    );

    return (array) apply_filters('seo_core_service_health_registry', $registry);
}

function seo_core_service_health_result($index, $key, $service) {
    $component_checks = array();
    $table_checks = array();
    $passed_units = 0;
    $total_units = 0;

    foreach ((array) ($service['components'] ?? array()) as $component) {
        $type = (string) ($component[0] ?? '');
        $name = (string) ($component[1] ?? '');
        if ($name === '') {
            continue;
        }
        $ok = seo_core_service_health_component_probe($type, $name);
        $component_checks[] = array('type' => $type, 'name' => $name, 'ok' => $ok);
        $total_units++;
        if ($ok) {
            $passed_units++;
        }
    }

    foreach ((array) ($service['tables'] ?? array()) as $suffix) {
        $probe = seo_core_service_health_table_probe($suffix);
        $ok = !empty($probe['exists']) && empty($probe['error']);
        $probe['ok'] = $ok;
        $table_checks[] = $probe;
        $total_units++;
        if ($ok) {
            $passed_units++;
        }
    }

    $score = $total_units > 0 ? (int) round(($passed_units / $total_units) * 100) : 0;
    $severity = $score >= 90 ? 'ok' : ($score >= 60 ? 'warning' : 'ko');
    $passed = $severity === 'ok';

    $details = array();
    foreach ($component_checks as $row) {
        if (empty($row['ok'])) {
            $details[] = 'falta ' . $row['name'];
        }
    }
    foreach ($table_checks as $row) {
        if (empty($row['exists'])) {
            $details[] = 'falta tabla ' . $row['name'];
        } elseif (!empty($row['error'])) {
            $details[] = 'error leyendo ' . $row['name'];
        }
    }

    if (empty($details)) {
        $counts = array();
        foreach ($table_checks as $row) {
            if ($row['count'] !== null) {
                $counts[] = $row['name'] . ': ' . number_format_i18n((int) $row['count']);
            }
        }
        $detail = 'Operativo. ' . ($counts ? 'Registros: ' . implode(' · ', $counts) . '.' : 'Runtime y almacenamiento disponibles.');
    } else {
        $detail = 'Revisar: ' . implode(' · ', $details) . '.';
    }

    return seo_core_system_test_result(
        'services',
        '11.' . absint($index) . ' ' . sanitize_text_field((string) ($service['label'] ?? $key)),
        $passed,
        $detail,
        $severity,
        array(
            'area' => sanitize_key((string) $key),
            'owner' => 'WP',
            'confidence' => 98,
            'service_score' => $score,
            'evidence' => array(
                'service' => sanitize_key((string) $key),
                'score' => $score,
                'components' => $component_checks,
                'tables' => $table_checks,
            ),
        )
    );
}

function seo_core_system_test_internal_services() {
    $results = array();
    $index = 1;
    foreach (seo_core_service_health_registry() as $key => $service) {
        $results[] = seo_core_service_health_result($index, $key, $service);
        $index++;
    }

    // Smoke test de lectura pura del interprete.
    if (class_exists('SEO_Dependiente_Interprete') && is_callable(array('SEO_Dependiente_Interprete', 'interpret'))) {
        $sample = SEO_Dependiente_Interprete::interpret('necesito inflar las ruedas del coche');
        $query = is_array($sample) ? trim((string) ($sample['dependiente_query'] ?? $sample['search_query'] ?? '')) : '';
        foreach ($results as &$row) {
            if (($row['area'] ?? '') !== 'interprete') {
                continue;
            }
            $row['evidence']['smoke_test'] = array('input' => 'necesito inflar las ruedas del coche', 'output' => $query);
            if ($query === '') {
                $row['passed'] = false;
                $row['severity'] = 'warning';
                $row['status'] = 'warning';
                $row['detail'] .= ' El smoke test lingüístico no devolvió consulta.';
                $row['evidence']['score'] = min(70, (int) ($row['evidence']['score'] ?? 0));
            }
            break;
        }
        unset($row);
    }

    return (array) apply_filters('seo_core_service_health_results', $results);
}

function seo_core_external_connection_result($number, $label, $configured, $live, $callback) {
    if (!$configured) {
        return seo_core_system_test_result(
            'connections',
            $number . ' ' . $label,
            false,
            'No configurado.',
            'warning',
            array(
                'area' => sanitize_key($label),
                'owner' => 'API',
                'confidence' => 98,
                'service_score' => 50,
                'evidence' => array('score' => 50, 'configured' => false),
            )
        );
    }

    if (!$live) {
        return seo_core_system_test_result(
            'connections',
            $number . ' ' . $label,
            true,
            'Configurado. La lectura real se reserva para una validación manual.',
            'ok',
            array(
                'status' => 'info',
                'health_impact' => 0,
                'area' => sanitize_key($label),
                'owner' => 'API',
                'confidence' => 95,
                'service_score' => 100,
                'evidence' => array('score' => 100, 'configured' => true, 'live_test' => false),
            )
        );
    }

    $result = is_callable($callback) ? call_user_func($callback) : new WP_Error('seo_service_callback_missing', 'Prueba no disponible.');
    if (is_wp_error($result)) {
        return seo_core_system_test_result(
            'connections',
            $number . ' ' . $label,
            false,
            $result->get_error_message(),
            'ko',
            array(
                'area' => sanitize_key($label),
                'owner' => 'API',
                'confidence' => 99,
                'service_score' => 0,
                'evidence' => array('score' => 0, 'configured' => true, 'live_test' => true),
            )
        );
    }

    $detail = is_array($result) && !empty($result['detail']) ? (string) $result['detail'] : 'Conexión y lectura real correctas.';
    $evidence = is_array($result) && isset($result['evidence']) && is_array($result['evidence']) ? $result['evidence'] : array();
    $evidence = array_merge(array('score' => 100, 'configured' => true, 'live_test' => true), $evidence);

    return seo_core_system_test_result(
        'connections',
        $number . ' ' . $label,
        true,
        $detail,
        'ok',
        array(
            'area' => sanitize_key($label),
            'owner' => 'API',
            'confidence' => 99,
            'service_score' => 100,
            'evidence' => $evidence,
        )
    );
}

function seo_core_external_google_oauth_test() {
    if (!function_exists('seo_google_get_search_console_properties')) {
        return new WP_Error('seo_google_test_unavailable', 'Falta la función de lectura OAuth de Search Console.');
    }
    $rows = seo_google_get_search_console_properties();
    if (is_wp_error($rows)) {
        return $rows;
    }
    return array(
        'detail' => 'Google OAuth responde; propiedades accesibles: ' . number_format_i18n(count((array) $rows)) . '.',
        'evidence' => array('properties' => count((array) $rows)),
    );
}

function seo_core_external_google_reporting_test() {
    if (!function_exists('seo_google_search_console_query')) {
        return new WP_Error('seo_google_reporting_unavailable', 'Falta la función de consulta de Search Console.');
    }
    $dates = function_exists('seo_google_reporting_dates')
        ? seo_google_reporting_dates(7)
        : array('startDate' => gmdate('Y-m-d', strtotime('-7 days')), 'endDate' => gmdate('Y-m-d'));
    $rows = seo_google_search_console_query(array(
        'startDate' => (string) ($dates['startDate'] ?? ''),
        'endDate' => (string) ($dates['endDate'] ?? ''),
        'dimensions' => array('page'),
        'rowLimit' => 1,
    ));
    if (is_wp_error($rows)) {
        return $rows;
    }
    return array(
        'detail' => 'Search Console responde y permite recuperar contenido.',
        'evidence' => array('rows' => count((array) ($rows['rows'] ?? array()))),
    );
}

function seo_core_external_google_analytics_test() {
    if (!function_exists('seo_google_analytics_run_report')) {
        return new WP_Error('seo_google_analytics_unavailable', 'Falta la función de consulta de Analytics.');
    }
    $rows = seo_google_analytics_run_report(array(
        'dateRanges' => array(array('startDate' => '7daysAgo', 'endDate' => 'today')),
        'metrics' => array(array('name' => 'sessions')),
        'limit' => 1,
    ));
    if (is_wp_error($rows)) {
        return $rows;
    }
    return array(
        'detail' => 'Google Analytics Data API responde y permite recuperar contenido.',
        'evidence' => array('rows' => count((array) ($rows['rows'] ?? array()))),
    );
}

function seo_core_external_google_trends_test() {
    if (!function_exists('seo_google_trends_fetch_rss')) {
        return new WP_Error('seo_google_trends_unavailable', 'Falta el lector de Google Trends.');
    }
    $rows = seo_google_trends_fetch_rss('ES');
    if (is_wp_error($rows)) {
        return $rows;
    }
    $items = (array) ($rows['rows'] ?? array());
    if (!$items) {
        return new WP_Error('seo_google_trends_empty', 'Google Trends respondió sin tendencias interpretables.');
    }
    return array(
        'detail' => 'Google Trends responde; tendencias recibidas: ' . number_format_i18n(count($items)) . '.',
        'evidence' => array('rows' => count($items), 'bytes' => (int) ($rows['bytes'] ?? 0)),
    );
}

function seo_core_external_github_test() {
    if (!function_exists('seo_core_visual_github_settings')) {
        return new WP_Error('seo_github_settings_unavailable', 'Falta la configuración común de GitHub Actions.');
    }

    $settings = (array) seo_core_visual_github_settings();
    $path = '.github/workflows/' . sanitize_file_name((string) ($settings['workflow_id'] ?? ''));
    $url = sprintf(
        'https://api.github.com/repos/%s/%s/contents/%s?ref=%s',
        rawurlencode((string) ($settings['owner'] ?? '')),
        rawurlencode((string) ($settings['repo'] ?? '')),
        str_replace('%2F', '/', rawurlencode($path)),
        rawurlencode((string) ($settings['ref'] ?? ''))
    );

    $response = wp_remote_get(esc_url_raw($url), array(
        'timeout' => 20,
        'headers' => array(
            'Authorization' => 'Bearer ' . (string) ($settings['token'] ?? ''),
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'SEO-System-Plugin-Validation',
        ),
    ));

    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($code < 200 || $code >= 300 || !is_array($body) || empty($body['content'])) {
        $message = is_array($body) && !empty($body['message']) ? (string) $body['message'] : 'HTTP ' . $code;
        return new WP_Error('seo_github_read_failed', 'GitHub no permite leer el workflow: ' . sanitize_text_field($message));
    }

    return array(
        'detail' => 'GitHub responde; repositorio, rama y workflow son legibles.',
        'evidence' => array(
            'repository' => sanitize_text_field((string) $settings['owner'] . '/' . (string) $settings['repo']),
            'ref' => sanitize_text_field((string) $settings['ref']),
            'workflow' => sanitize_file_name((string) $settings['workflow_id']),
            'size' => (int) ($body['size'] ?? 0),
        ),
    );
}

function seo_core_system_test_external_connections($live = true) {
    $results = array();

    $google_oauth_configured = function_exists('seo_google_connection_status')
        && seo_google_connection_status() === 'connected';
    $results[] = seo_core_external_connection_result(
        '12.1',
        'Google Intelligence / OAuth',
        $google_oauth_configured,
        $live,
        'seo_core_external_google_oauth_test'
    );

    $google_settings = function_exists('seo_google_search_settings') ? (array) seo_google_search_settings() : array();

    $gsc_configured = !empty($google_settings['search_console_site_url']) && !empty($google_settings['service_account_json']);
    $results[] = seo_core_external_connection_result(
        '12.2',
        'Google Search Console',
        $gsc_configured,
        $live,
        'seo_core_external_google_reporting_test'
    );

    $ga4_configured = !empty($google_settings['analytics_property_id']) && !empty($google_settings['service_account_json']);
    $results[] = seo_core_external_connection_result(
        '12.3',
        'Google Analytics 4',
        $ga4_configured,
        $live,
        'seo_core_external_google_analytics_test'
    );

    $trends_configured = function_exists('seo_google_trends_fetch_rss');
    $results[] = seo_core_external_connection_result(
        '12.4',
        'Google Trends',
        $trends_configured,
        $live,
        'seo_core_external_google_trends_test'
    );

    $github_settings = function_exists('seo_core_visual_github_settings') ? (array) seo_core_visual_github_settings() : array();
    $github_configured = true;
    foreach (array('owner', 'repo', 'ref', 'token', 'workflow_id') as $key) {
        if (trim((string) ($github_settings[$key] ?? '')) === '') {
            $github_configured = false;
            break;
        }
    }
    $results[] = seo_core_external_connection_result(
        '12.5',
        'GitHub',
        $github_configured,
        $live,
        'seo_core_external_github_test'
    );

    return (array) apply_filters('seo_core_external_connection_health_results', $results, (bool) $live);
}

function seo_core_system_test_services_connections($live_external = true) {
    return array_merge(
        seo_core_system_test_internal_services(),
        seo_core_system_test_external_connections((bool) $live_external)
    );
}


/**
 * Estado persistente del chequeo automático posterior a una release.
 */
function seo_core_service_release_state_option_name() {
    return 'seo_core_service_release_health_v1';
}

function seo_core_service_release_get_state() {
    $state = get_option(seo_core_service_release_state_option_name(), array());
    return is_array($state) ? $state : array();
}

function seo_core_service_release_current_version() {
    return defined('SEO_SYSTEM_VERSION') ? (string) SEO_SYSTEM_VERSION : '';
}

/**
 * Detecta una nueva versión instalada y agenda una aceptación post-release.
 *
 * Se ejecuta con independencia del envío de diagnósticos por correo.
 */
function seo_core_service_release_schedule_if_needed() {
    $version = seo_core_service_release_current_version();
    if ($version === '') {
        return;
    }

    $state = seo_core_service_release_get_state();
    $observed = (string) ($state['observed_version'] ?? '');
    $checked = (string) ($state['checked_version'] ?? '');

    if ($observed === '') {
        $state['observed_version'] = $version;
        $state['detected_at'] = time();
        update_option(seo_core_service_release_state_option_name(), $state, false);
        return;
    }

    if ($observed !== $version) {
        $state['previous_version'] = $observed;
        $state['observed_version'] = $version;
        $state['detected_at'] = time();
        $state['status'] = 'pending';
        update_option(seo_core_service_release_state_option_name(), $state, false);
    }

    if ($checked === $version) {
        return;
    }

    if (!wp_next_scheduled('seo_core_service_health_after_release')) {
        wp_schedule_single_event(time() + 120, 'seo_core_service_health_after_release');
    }
}
add_action('admin_init', 'seo_core_service_release_schedule_if_needed', 35);

/**
 * Ejecuta la suite completa de aceptación tras cambio de versión.
 */
function seo_core_service_release_run_check() {
    $version = seo_core_service_release_current_version();
    if ($version === '' || !function_exists('seo_core_system_test_run_telemetry_suite')) {
        return;
    }

    $started_at = time();
    $state = seo_core_service_release_get_state();
    $state['status'] = 'running';
    $state['started_at'] = $started_at;
    $state['observed_version'] = $version;
    update_option(seo_core_service_release_state_option_name(), $state, false);

    $results = seo_core_system_test_run_telemetry_suite(true, true);
    $service_rows = array_values(array_filter((array) $results, static function ($row) {
        return is_array($row) && isset($row['group']) && in_array($row['group'], array('services', 'connections'), true);
    }));

    $health = function_exists('seo_core_system_test_health_summary')
        ? seo_core_system_test_health_summary($service_rows)
        : array('score' => null, 'status' => 'unknown');

    $failed = array();
    foreach ($service_rows as $row) {
        $score = isset($row['service_score'])
            ? (int) $row['service_score']
            : (isset($row['evidence']['score']) ? (int) $row['evidence']['score'] : 0);
        if ($score >= 90 && in_array((string) ($row['severity'] ?? ''), array('ok', 'info'), true)) {
            continue;
        }
        $failed[] = array(
            'label' => (string) ($row['label'] ?? ''),
            'group' => (string) ($row['group'] ?? ''),
            'score' => max(0, min(100, $score)),
            'severity' => (string) ($row['severity'] ?? ''),
            'detail' => (string) ($row['detail'] ?? ''),
        );
    }

    $state = array(
        'observed_version' => $version,
        'checked_version' => $version,
        'previous_version' => (string) ($state['previous_version'] ?? ''),
        'detected_at' => (int) ($state['detected_at'] ?? $started_at),
        'started_at' => $started_at,
        'checked_at' => time(),
        'status' => empty($failed) && (($health['score'] ?? 0) >= 90) ? 'ok' : 'warning',
        'score' => isset($health['score']) ? $health['score'] : null,
        'health_status' => (string) ($health['status'] ?? 'unknown'),
        'checks' => count($service_rows),
        'failed' => $failed,
    );
    update_option(seo_core_service_release_state_option_name(), $state, false);
}
add_action('seo_core_service_health_after_release', 'seo_core_service_release_run_check');

/**
 * Datos compactos para la UI de Plugin Validation.
 */
function seo_core_service_release_summary() {
    $state = seo_core_service_release_get_state();
    $version = seo_core_service_release_current_version();
    $checked_version = (string) ($state['checked_version'] ?? '');
    $pending = $version !== '' && $checked_version !== $version;

    return array(
        'current_version' => $version,
        'checked_version' => $checked_version,
        'previous_version' => (string) ($state['previous_version'] ?? ''),
        'checked_at' => (int) ($state['checked_at'] ?? 0),
        'status' => $pending ? 'pending' : (string) ($state['status'] ?? 'unknown'),
        'score' => isset($state['score']) ? $state['score'] : null,
        'failed' => isset($state['failed']) && is_array($state['failed']) ? $state['failed'] : array(),
    );
}
