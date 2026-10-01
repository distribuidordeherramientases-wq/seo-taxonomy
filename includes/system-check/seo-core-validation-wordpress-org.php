<?php

defined('ABSPATH') || exit;

/**
 * WordPress.org compatibility scanner.
 *
 * Static, read-only checks inspired by the Plugin Review Team / Plugin Check.
 * These checks are intentionally conservative: conclusive violations are KO,
 * while heuristic findings are WARNING and require developer review.
 */

if (!defined('SEO_CORE_WPORG_VALIDATION_VERSION')) {
    define('SEO_CORE_WPORG_VALIDATION_VERSION', '1.2.0');
}

function seo_core_wporg_scan_root() {
    $root = defined('SEO_SYSTEM_PATH') ? SEO_SYSTEM_PATH : dirname(__DIR__, 2) . '/';
    $root = wp_normalize_path((string) $root);
    return trailingslashit((string) apply_filters('seo_core_wporg_scan_root', $root));
}

function seo_core_wporg_relative_path($path, $root = '') {
    $root = $root !== '' ? trailingslashit(wp_normalize_path($root)) : seo_core_wporg_scan_root();
    $path = wp_normalize_path((string) $path);
    return strpos($path, $root) === 0 ? ltrim(substr($path, strlen($root)), '/') : $path;
}

function seo_core_wporg_all_files() {
    $root = seo_core_wporg_scan_root();
    $files = array();

    if (!is_dir($root) || !is_readable($root)) {
        return $files;
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file_info) {
            if (!$file_info->isFile()) {
                continue;
            }

            $path = wp_normalize_path($file_info->getPathname());
            $relative = seo_core_wporg_relative_path($path, $root);

            if (
                strpos($relative, '.git/') === 0
                || strpos($relative, 'node_modules/') === 0
            ) {
                continue;
            }

            $files[] = array(
                'path' => $path,
                'file' => $relative,
                'size' => (int) $file_info->getSize(),
            );
        }
    } catch (UnexpectedValueException $exception) {
        return $files;
    }

    usort($files, static function ($left, $right) {
        return strnatcasecmp((string) $left['file'], (string) $right['file']);
    });

    return $files;
}

function seo_core_wporg_php_files() {
    $files = array();
    foreach (seo_core_wporg_all_files() as $file) {
        if (strtolower(pathinfo((string) $file['file'], PATHINFO_EXTENSION)) === 'php') {
            $files[] = $file;
        }
    }
    return $files;
}

function seo_core_wporg_file_lines($path) {
    if (!is_readable($path)) {
        return array();
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    return is_array($lines) ? $lines : array();
}

function seo_core_wporg_add_hit(&$hits, $rule, $file, $line, $detail) {
    $hits[] = array(
        'rule' => sanitize_key((string) $rule),
        'file' => (string) $file,
        'line' => max(0, (int) $line),
        'detail' => trim((string) $detail),
    );
}

function seo_core_wporg_hit_summary($hits, $empty_message = 'No se han detectado incidencias') {
    $count = count((array) $hits);
    if ($count === 0) {
        return $empty_message;
    }

    $first = $hits[0];
    $where = (string) ($first['file'] ?? '');
    if (!empty($first['line'])) {
        $where .= ':' . (int) $first['line'];
    }

    return 'Detectadas: ' . number_format_i18n($count)
        . ($where !== '' ? '. Primera: ' . $where : '')
        . (!empty($first['detail']) ? '. ' . (string) $first['detail'] : '');
}

function seo_core_wporg_result($label, $hits, $severity = 'warning', $confidence = 95, $detail_ok = 'Sin incidencias') {
    $hits = array_values((array) $hits);
    $passed = empty($hits);
    $actual_severity = $passed ? 'ok' : $severity;
    $status = $passed ? 'pass' : ($severity === 'ko' ? 'fail' : 'warning');

    return seo_core_system_test_result(
        'code_integrity',
        $label,
        $passed,
        $passed ? $detail_ok : seo_core_wporg_hit_summary($hits),
        $actual_severity,
        array(
            'area' => 'wordpress_org',
            'status' => $status,
            'confidence' => max(0, min(100, (int) $confidence)),
            'evidence' => array(
                'scanner_version' => SEO_CORE_WPORG_VALIDATION_VERSION,
                'findings' => array_slice($hits, 0, 100),
                'total_findings' => count($hits),
            ),
            'items' => $hits,
        )
    );
}

function seo_core_wporg_readme_data() {
    $path = seo_core_wporg_scan_root() . 'readme.txt';
    $content = is_readable($path) ? (string) file_get_contents($path) : '';
    $data = array(
        'path' => $path,
        'content' => $content,
        'title' => '',
        'stable_tag' => '',
        'tested_up_to' => '',
        'tags' => array(),
        'short_description' => '',
    );

    if ($content === '') {
        return $data;
    }

    if (preg_match('/^===\s*(.+?)\s*===/m', $content, $match)) {
        $data['title'] = trim($match[1]);
    }
    if (preg_match('/^Stable tag:\s*(.+)$/mi', $content, $match)) {
        $data['stable_tag'] = trim($match[1]);
    }
    if (preg_match('/^Tested up to:\s*(.+)$/mi', $content, $match)) {
        $data['tested_up_to'] = trim($match[1]);
    }
    if (preg_match('/^Tags:\s*(.+)$/mi', $content, $match)) {
        $data['tags'] = array_values(array_filter(array_map('trim', explode(',', $match[1]))));
    }

    $header_end = strpos($content, "\n\n");
    if ($header_end !== false) {
        $after_header = ltrim(substr($content, $header_end + 2));
        $first_line_end = strpos($after_header, "\n");
        $data['short_description'] = trim($first_line_end === false ? $after_header : substr($after_header, 0, $first_line_end));
    }

    return $data;
}

function seo_core_wporg_plugin_header_data() {
    $path = defined('SEO_SYSTEM_FILE') ? SEO_SYSTEM_FILE : seo_core_wporg_scan_root() . 'seo-taxonomy.php';
    $content = is_readable($path) ? (string) file_get_contents($path) : '';
    $data = array('path' => $path, 'name' => '', 'version' => '', 'text_domain' => '');

    foreach (array('Plugin Name' => 'name', 'Version' => 'version', 'Text Domain' => 'text_domain') as $header => $key) {
        if (preg_match('/^[ \t\/*#@]*' . preg_quote($header, '/') . ':\s*(.+)$/mi', $content, $match)) {
            $data[$key] = trim(preg_replace('/\s*\*\/\s*$/', '', $match[1]));
        }
    }

    return $data;
}

function seo_core_wporg_metadata_results() {
    $readme = seo_core_wporg_readme_data();
    $header = seo_core_wporg_plugin_header_data();
    $hits = array();

    if ($readme['content'] === '') {
        seo_core_wporg_add_hit($hits, 'readme_missing', 'readme.txt', 0, 'No existe o no es legible.');
    }

    if ($header['version'] === '' || $readme['stable_tag'] === '' || $header['version'] !== $readme['stable_tag']) {
        seo_core_wporg_add_hit(
            $hits,
            'version_mismatch',
            'readme.txt',
            0,
            'Version cabecera=' . ($header['version'] ?: 'vacía') . '; Stable tag=' . ($readme['stable_tag'] ?: 'vacío') . '.'
        );
    }

    if (defined('SEO_SYSTEM_VERSION') && $header['version'] !== (string) SEO_SYSTEM_VERSION) {
        seo_core_wporg_add_hit(
            $hits,
            'constant_version_mismatch',
            basename((string) $header['path']),
            0,
            'SEO_SYSTEM_VERSION=' . (string) SEO_SYSTEM_VERSION . '; cabecera=' . ($header['version'] ?: 'vacía') . '.'
        );
    }

    if ($readme['title'] !== '' && $header['name'] !== '' && strcasecmp($readme['title'], $header['name']) !== 0) {
        seo_core_wporg_add_hit($hits, 'display_name_mismatch', 'readme.txt', 1, 'Readme=' . $readme['title'] . '; cabecera=' . $header['name'] . '.');
    }

    if (count($readme['tags']) > 5) {
        seo_core_wporg_add_hit($hits, 'too_many_tags', 'readme.txt', 0, 'Tags: ' . count($readme['tags']) . '; máximo WordPress.org: 5.');
    }

    if ($readme['short_description'] !== '' && strlen($readme['short_description']) > 150) {
        seo_core_wporg_add_hit($hits, 'short_description_long', 'readme.txt', 0, 'Longitud: ' . strlen($readme['short_description']) . '; máximo: 150.');
    }

    $installed = get_bloginfo('version');
    if ($readme['tested_up_to'] !== '' && $installed !== '') {
        $installed_major = preg_replace('/^(\d+\.\d+).*$/', '$1', (string) $installed);
        if (version_compare($readme['tested_up_to'], $installed_major, '<')) {
            seo_core_wporg_add_hit(
                $hits,
                'tested_up_to_old',
                'readme.txt',
                0,
                'Tested up to=' . $readme['tested_up_to'] . '; WordPress instalado=' . $installed_major . '.'
            );
        }
    }

    return seo_core_wporg_result(
        '0.20 WordPress.org · metadatos de distribución',
        $hits,
        'ko',
        100,
        'Cabecera, versión, Stable tag, Tested up to, tags y descripción son coherentes'
    );
}

function seo_core_wporg_static_findings() {
    $groups = array(
        'package' => array(),
        'inline_assets' => array(),
        'remote_assets' => array(),
        'uploads' => array(),
        'sql' => array(),
        'input' => array(),
        'nonce' => array(),
        'rest' => array(),
        'paths' => array(),
        'direct_access' => array(),
        'prefixes' => array(),
        'i18n' => array(),
        'json' => array(),
        'filesystem' => array(),
        'prepared_sql' => array(),
        'db_parameters' => array(),
        'output_escaping' => array(),
        'translator_comments' => array(),
        'deprecated' => array(),
        'http_api' => array(),
        'datetime' => array(),
        'unslash' => array(),
        'exception_escaping' => array(),
        'alternative_functions' => array(),
        'external_services' => array(),
    );

    foreach (seo_core_wporg_all_files() as $file) {
        if (preg_match('/\.(?:bak|backup|old|orig|sql|log|zip|tar|tgz|gz|7z|rar|swp)$/i', (string) $file['file'])) {
            seo_core_wporg_add_hit($groups['package'], 'sensitive_file', $file['file'], 0, 'Archivo no recomendable en el ZIP público.');
        }
    }

    $readme_lower = strtolower((string) seo_core_wporg_readme_data()['content']);
    $own_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    $external_domains = array();

    foreach (seo_core_wporg_php_files() as $file) {
        $relative = (string) $file['file'];
        if ($relative === 'includes/system-check/seo-core-validation-wordpress-org.php') {
            continue;
        }

        $lines = seo_core_wporg_file_lines($file['path']);
        $first_chunk = implode("\n", array_slice($lines, 0, 60));

        if (
            $relative !== 'seo-taxonomy.php'
            && $relative !== 'uninstall.php'
            && strpos($first_chunk, "defined('ABSPATH')") === false
            && strpos($first_chunk, 'defined( \'ABSPATH\' )') === false
            && strpos($first_chunk, 'defined("ABSPATH")') === false
        ) {
            seo_core_wporg_add_hit($groups['direct_access'], 'missing_abspath_guard', $relative, 1, 'No se detecta guardia ABSPATH al inicio del archivo.');
        }

        foreach ($lines as $index => $line) {
            $line_no = $index + 1;
            $trimmed = trim((string) $line);

            if ($trimmed === '') {
                continue;
            }

            if (stripos($line, '<script') !== false && stripos($line, 'application/ld+json') === false) {
                seo_core_wporg_add_hit($groups['inline_assets'], 'inline_script', $relative, $line_no, 'Etiqueta <script> directa; revisar wp_enqueue_script/wp_add_inline_script.');
            }
            if (stripos($line, '<style') !== false) {
                seo_core_wporg_add_hit($groups['inline_assets'], 'inline_style', $relative, $line_no, 'Etiqueta <style> directa; revisar wp_enqueue_style/wp_add_inline_style.');
            }
            if (preg_match('/<(?:script|link)\b[^>]*(?:src|href)\s*=\s*["\']https?:\/\//i', $line)) {
                seo_core_wporg_add_hit($groups['remote_assets'], 'remote_asset', $relative, $line_no, 'Asset remoto cargado directamente.');
            }
            if (strpos($line, 'move_uploaded_file(') !== false) {
                seo_core_wporg_add_hit($groups['uploads'], 'move_uploaded_file', $relative, $line_no, 'Usar wp_handle_upload()/media_handle_upload() cuando corresponda.');
            }
            if (preg_match('/\bmysqli_(?:query|real_escape_string|fetch_assoc|insert_id|error|close)\s*\(/i', $line)) {
                seo_core_wporg_add_hit($groups['sql'], 'mysqli_direct', $relative, $line_no, 'Acceso mysqli directo; revisar prepare/abstracción WordPress.');
            }
            if (
                preg_match('/\$_(?:GET|POST|REQUEST|COOKIE|SERVER|SESSION)\s*\[/i', $line)
                && !preg_match('/(?:sanitize_[a-z_]+|absint|intval|floatval|wp_unslash|esc_url_raw|wp_kses|filter_input)\s*\(/i', $line)
            ) {
                seo_core_wporg_add_hit($groups['input'], 'raw_superglobal', $relative, $line_no, 'Entrada externa sin sanitización evidente en la misma expresión.');
            }
            if (preg_match('/wp_verify_nonce\s*\(\s*\$_(?:GET|POST|REQUEST)/i', $line)) {
                seo_core_wporg_add_hit($groups['nonce'], 'raw_nonce', $relative, $line_no, 'Nonce verificado directamente desde superglobal sin wp_unslash + sanitize_text_field.');
            }
            if (
                strpos($line, 'permission_callback') !== false
                && strpos($line, '__return_true') !== false
            ) {
                seo_core_wporg_add_hit($groups['rest'], 'public_rest', $relative, $line_no, 'Endpoint REST público: confirmar que no expone datos ni acciones sensibles.');
            }
            if (
                preg_match('/WP_PLUGIN_DIR\s*\.\s*["\'][^"\']*seo-taxonomy/i', $line)
                || preg_match('/["\']\/seo-taxonomy\//i', $line)
            ) {
                seo_core_wporg_add_hit($groups['paths'], 'hardcoded_plugin_path', $relative, $line_no, 'Ruta del plugin hardcodeada.');
            }
            if (stripos($line, 'wp-load.php') !== false && stripos($relative, 'system-check/') === false) {
                seo_core_wporg_add_hit($groups['paths'], 'wp_load_path', $relative, $line_no, 'Referencia manual a wp-load.php; revisar APIs de WordPress y rutas dinámicas.');
            }
            if (preg_match('/add_shortcode\s*\(\s*["\']([^"\']+)["\']/i', $line, $match)) {
                $tag = strtolower((string) $match[1]);
                if (!preg_match('/^(?:seo_|seota_|dht_)/', $tag)) {
                    seo_core_wporg_add_hit($groups['prefixes'], 'unprefixed_shortcode', $relative, $line_no, 'Shortcode sin prefijo distintivo: ' . $tag);
                }
            }
            if (
                preg_match('/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_x|_n)\s*\(/', $line)
                && strpos($line, "'seo-system'") !== false
            ) {
                seo_core_wporg_add_hit($groups['i18n'], 'wrong_text_domain', $relative, $line_no, 'Text domain legado seo-system; debe coincidir con el slug.');
            }
            if (
                preg_match('/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\s*\([^,;]+\)\s*;?/', $line)
                && strpos($line, "'seo-taxonomy'") === false
                && strpos($line, '"seo-taxonomy"') === false
            ) {
                seo_core_wporg_add_hit($groups['i18n'], 'missing_text_domain', $relative, $line_no, 'Gettext sin text domain literal detectable.');
            }
            if (strpos($line, 'wp_json_encode') !== false && strpos($line, 'JSON_UNESCAPED_SLASHES') !== false) {
                seo_core_wporg_add_hit($groups['json'], 'json_unescaped_slashes', $relative, $line_no, 'JSON_UNESCAPED_SLASHES puede ser inseguro dentro de <script>; revisar contexto.');
            }
            if (preg_match('/\b(?:fwrite|file_put_contents|rename|copy)\s*\(/i', $line)) {
                seo_core_wporg_add_hit($groups['filesystem'], 'filesystem_write', $relative, $line_no, 'Escritura de fichero: confirmar que el destino usa uploads/database y no carpetas del plugin/core.');
            }

            
            // Familias que tambien aparecen en WordPress Plugin Check.
            if (preg_match('/\$wpdb->(?:query|get_results|get_var|get_col|get_row)\s*\(/i', $line)) {
                $sql_window = implode("\n", array_slice($lines, $index, 16));
                if (stripos($sql_window, '->prepare(') === false && stripos($sql_window, 'prepare(') === false) {
                    seo_core_wporg_add_hit($groups['prepared_sql'], 'wpdb_query_not_prepared', $relative, $line_no, 'Consulta $wpdb sin prepare() detectable; revisar PreparedSQL.NotPrepared.');
                }
                if (preg_match('/\bLIKE\s+[\'"][^\'"]*%[^\'"]*[\'"]/i', $sql_window)) {
                    seo_core_wporg_add_hit($groups['prepared_sql'], 'like_wildcard_in_query', $relative, $line_no, 'LIKE contiene comodines dentro del SQL; pasar el patron mediante un parametro de prepare().');
                }
                if (preg_match('/(?:FROM|JOIN|INTO|UPDATE|DELETE\s+FROM)\s*[\'"]?\s*\.\s*\$[A-Za-z_]/i', $sql_window)) {
                    seo_core_wporg_add_hit($groups['db_parameters'], 'dynamic_sql_concatenation', $relative, $line_no, 'SQL con concatenacion dinamica detectable; revisar identificadores y parametros.');
                }
            }

            if (
                preg_match('/(?:\$wpdb->(?:query|get_results|get_var|get_col|get_row)|\$wpdb->prepare)\s*\(/i', $line)
                && preg_match('/\.\s*\$[A-Za-z_]/i', $line)
            ) {
                seo_core_wporg_add_hit($groups['db_parameters'], 'sql_dynamic_parameter', $relative, $line_no, 'Se detecta concatenacion de variable en una expresion SQL; revisar preparacion/identificadores.');
            }

            if (
                preg_match('/\b(?:echo|print)\s+.*\$[A-Za-z_]/i', $line)
                && !preg_match('/\b(?:esc_html|esc_attr|esc_url|esc_js|wp_kses|wp_kses_post|wp_json_encode|number_format_i18n|selected|checked|disabled)\s*\(/i', $line)
            ) {
                seo_core_wporg_add_hit($groups['output_escaping'], 'output_not_escaped', $relative, $line_no, 'Salida dinamica sin una funcion de escape reconocible; revisar OutputNotEscaped.');
            }

            if (preg_match('/\b(?:wp_die|wp_send_json_error|wp_send_json_success)\s*\(\s*\$[A-Za-z_]/i', $line)) {
                seo_core_wporg_add_hit($groups['output_escaping'], 'dynamic_error_output', $relative, $line_no, 'Mensaje dinamico enviado directamente a una salida de error; revisar el escape segun contexto.');
            }

            if (
                preg_match('/\b(?:__|_e|_n|_x|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\s*\(/i', $line)
                && preg_match('/%[0-9$+\-.\']*[sdif]/i', $line)
            ) {
                $previous = implode("\n", array_slice($lines, max(0, $index - 3), min(3, $index)));
                if (stripos($previous, 'translators:') === false) {
                    seo_core_wporg_add_hit($groups['translator_comments'], 'missing_translators_comment', $relative, $line_no, 'Placeholder traducible sin comentario translators: detectable.');
                }
            }

            if (preg_match('/\bterm_description\s*\([^,]+,\s*[\'"][^\'"]+[\'"]\s*\)/i', $line)) {
                seo_core_wporg_add_hit($groups['deprecated'], 'term_description_param2', $relative, $line_no, 'term_description() usa un segundo parametro obsoleto.');
            }

            if (preg_match('/\bcurl_[a-z0-9_]+\s*\(/i', $line)) {
                seo_core_wporg_add_hit($groups['http_api'], 'curl_direct', $relative, $line_no, 'cURL directo detectado; revisar la HTTP API de WordPress.');
            }

            if (preg_match('/\bdate\s*\(/i', $line) && !preg_match('/\b(?:gmdate|wp_date)\s*\(/i', $line)) {
                seo_core_wporg_add_hit($groups['datetime'], 'date_direct', $relative, $line_no, 'date() directo detectado; revisar wp_date()/gmdate().');
            }

            if (
                preg_match('/\$_(?:GET|POST|REQUEST|COOKIE)\s*\[/i', $line)
                && preg_match('/\b(?:sanitize_text_field|sanitize_textarea_field|sanitize_key|sanitize_title|esc_url_raw)\s*\(/i', $line)
                && stripos($line, 'wp_unslash(') === false
            ) {
                seo_core_wporg_add_hit(
                    $groups['unslash'],
                    'missing_unslash',
                    $relative,
                    $line_no,
                    'Superglobal sanitizada sin wp_unslash() detectable; revisar WordPress.Security.ValidatedSanitizedInput.MissingUnslash.'
                );
            }

            if (
                preg_match('/(?:->getMessage\(\)|->get_error_message\(\))/i', $line)
                && preg_match('/\b(?:echo|print|wp_die|wp_send_json_error|wp_send_json_success)\b/i', $line)
                && !preg_match('/\b(?:esc_html|esc_attr|wp_kses|wp_kses_post|sanitize_text_field)\s*\(/i', $line)
            ) {
                seo_core_wporg_add_hit(
                    $groups['exception_escaping'],
                    'exception_not_escaped',
                    $relative,
                    $line_no,
                    'Mensaje de excepción/error enviado a salida sin escape detectable; revisar ExceptionNotEscaped.'
                );
            }

            if (preg_match('/\bparse_url\s*\(/i', $line)) {
                seo_core_wporg_add_hit(
                    $groups['alternative_functions'],
                    'parse_url_direct',
                    $relative,
                    $line_no,
                    'parse_url() directo detectado; usar wp_parse_url().'
                );
            }

            if (preg_match('/\bstrip_tags\s*\(/i', $line)) {
                seo_core_wporg_add_hit(
                    $groups['alternative_functions'],
                    'strip_tags_direct',
                    $relative,
                    $line_no,
                    'strip_tags() directo detectado; revisar wp_strip_all_tags().'
                );
            }

            if (preg_match('/\bmysqli_(?:query|real_escape_string|fetch_assoc|free_result|insert_id|affected_rows|error|close)\s*\(/i', $line)) {
                seo_core_wporg_add_hit(
                    $groups['alternative_functions'],
                    'mysqli_restricted',
                    $relative,
                    $line_no,
                    'Función mysqli directa detectada; Plugin Check exige usar la abstracción $wpdb.'
                );
            }

            if (preg_match('/\b(?:fclose|fwrite|fopen|unlink|rename|chmod|is_writable|readfile|rmdir|fread)\s*\(/i', $line)) {
                seo_core_wporg_add_hit($groups['filesystem'], 'filesystem_direct_api', $relative, $line_no, 'Operacion directa de filesystem; revisar WP_Filesystem.');
            }

if (preg_match_all('/https?:\/\/([a-z0-9.-]+)/i', $line, $urls)) {
                foreach ((array) $urls[1] as $host) {
                    $host = strtolower((string) $host);
                    if (
                        $host === ''
                        || $host === $own_host
                        || preg_match('/(?:^|\.)wordpress\.org$/', $host)
                        || preg_match('/(?:^|\.)w\.org$/', $host)
                        || preg_match('/(?:^|\.)opensource\.org$/', $host)
                    ) {
                        continue;
                    }
                    $external_domains[$host][] = array('file' => $relative, 'line' => $line_no);
                }
            }
        }
    }

    foreach ($external_domains as $host => $locations) {
        if (strpos($readme_lower, strtolower($host)) !== false) {
            continue;
        }
        $first = $locations[0];
        seo_core_wporg_add_hit(
            $groups['external_services'],
            'external_service_undocumented',
            $first['file'],
            $first['line'],
            'Dominio externo no localizado en readme.txt: ' . $host
        );
    }

    return $groups;
}

function seo_core_system_test_wordpress_org_results() {
    $groups = seo_core_wporg_static_findings();

    return array(
        seo_core_wporg_metadata_results(),
        seo_core_wporg_result('0.21 WordPress.org · higiene del paquete', $groups['package'], 'ko', 100, 'No hay backups, dumps, logs ni archivos comprimidos internos no deseados'),
        seo_core_wporg_result('0.22 WordPress.org · JS/CSS mediante APIs de enqueue', $groups['inline_assets'], 'warning', 95, 'No se detectan etiquetas script/style directas fuera de JSON-LD'),
        seo_core_wporg_result('0.23 WordPress.org · assets remotos', $groups['remote_assets'], 'ko', 100, 'No se detectan scripts/estilos remotos cargados directamente'),
        seo_core_wporg_result('0.24 WordPress.org · subida de archivos', $groups['uploads'], 'ko', 100, 'No se detecta move_uploaded_file()'),
        seo_core_wporg_result('0.25 WordPress.org · acceso SQL', $groups['sql'], 'warning', 95, 'No se detecta mysqli directo que requiera revisión'),
        seo_core_wporg_result('0.26 WordPress.org · sanitización de entradas', $groups['input'], 'warning', 70, 'No se detectan superglobales sin sanitización evidente'),
        seo_core_wporg_result('0.27 WordPress.org · nonces sanitizados', $groups['nonce'], 'ko', 100, 'No se detectan wp_verify_nonce() alimentados directamente desde superglobales'),
        seo_core_wporg_result('0.28 WordPress.org · rutas REST públicas', $groups['rest'], 'warning', 90, 'No hay permission_callback público que requiera revisión'),
        seo_core_wporg_result('0.29 WordPress.org · rutas portables', $groups['paths'], 'warning', 95, 'No se detectan rutas de plugin/wp-load hardcodeadas'),
        seo_core_wporg_result('0.30 WordPress.org · acceso directo a PHP', $groups['direct_access'], 'warning', 85, 'Los PHP analizados incluyen guardia de acceso directo o son entrypoints permitidos'),
        seo_core_wporg_result('0.31 WordPress.org · prefijos globales', $groups['prefixes'], 'warning', 95, 'No se detectan shortcodes globales sin prefijo propio'),
        seo_core_wporg_result('0.32 WordPress.org · internacionalización', $groups['i18n'], 'warning', 80, 'No se detectan text domains legados/ausentes en los patrones analizados'),
        seo_core_wporg_result('0.33 WordPress.org · JSON embebido', $groups['json'], 'warning', 95, 'No se detecta JSON_UNESCAPED_SLASHES en wp_json_encode'),
        seo_core_wporg_result('0.34 WordPress.org · escritura de archivos', $groups['filesystem'], 'warning', 75, 'No se detectan operaciones directas de filesystem que requieran revisión'),
        seo_core_wporg_result('0.35 WordPress.org · SQL preparado', $groups['prepared_sql'], 'warning', 85, 'Las consultas $wpdb analizadas usan prepare() y no contienen comodines LIKE directos'),
        seo_core_wporg_result('0.36 WordPress.org · parámetros SQL', $groups['db_parameters'], 'warning', 85, 'No se detectan concatenaciones SQL dinámicas que requieran revisión'),
        seo_core_wporg_result('0.37 WordPress.org · escapado de salida', $groups['output_escaping'], 'warning', 80, 'No se detectan salidas dinámicas sin escape reconocible'),
        seo_core_wporg_result('0.38 WordPress.org · comentarios translators', $groups['translator_comments'], 'warning', 90, 'Los placeholders traducibles analizados incluyen comentario translators'),
        seo_core_wporg_result('0.39 WordPress.org · APIs obsoletas', $groups['deprecated'], 'warning', 95, 'No se detectan llamadas con parámetros obsoletos conocidos'),
        seo_core_wporg_result('0.40 WordPress.org · HTTP API', $groups['http_api'], 'warning', 95, 'No se detectan llamadas cURL directas'),
        seo_core_wporg_result('0.41 WordPress.org · fecha/hora', $groups['datetime'], 'warning', 95, 'No se detectan llamadas date() directas sin sustituto WordPress'),
        seo_core_wporg_result('0.42 WordPress.org · wp_unslash en entradas', $groups['unslash'], 'warning', 85, 'Las entradas sanitizadas analizadas incluyen wp_unslash() cuando corresponde'),
        seo_core_wporg_result('0.43 WordPress.org · excepciones y errores escapados', $groups['exception_escaping'], 'warning', 85, 'No se detectan mensajes de excepción enviados a salida sin escape'),
        seo_core_wporg_result('0.44 WordPress.org · funciones alternativas', $groups['alternative_functions'], 'warning', 95, 'No se detectan parse_url(), strip_tags() ni mysqli directos en los patrones analizados'),
        seo_core_wporg_result('0.45 WordPress.org · servicios externos documentados', $groups['external_services'], 'warning', 75, 'Los dominios externos detectados aparecen documentados en readme.txt')
    );
}

function seo_core_wordpress_org_findings_flat() {
    $groups = seo_core_wporg_static_findings();
    $rows = array();

    foreach ($groups as $group => $findings) {
        foreach ((array) $findings as $finding) {
            $finding['group'] = $group;
            $rows[] = $finding;
        }
    }

    usort($rows, static function ($left, $right) {
        $file_cmp = strnatcasecmp((string) ($left['file'] ?? ''), (string) ($right['file'] ?? ''));
        if ($file_cmp !== 0) {
            return $file_cmp;
        }
        return ((int) ($left['line'] ?? 0)) <=> ((int) ($right['line'] ?? 0));
    });

    return $rows;
}

function seo_core_wordpress_org_render_details() {
    $rows = seo_core_wordpress_org_findings_flat();

    seo_core_system_test_render_inventory_table(
        'Compatibilidad WordPress.org · detalle de hallazgos',
        $rows,
        array(
            'group' => 'Área',
            'rule' => 'Regla',
            'file' => 'Archivo',
            'line' => 'Línea',
            'detail' => 'Detalle',
        )
    );
}
