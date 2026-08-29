<?php
/**
 * SEO System - Server Status
 *
 * Panel de diagnóstico de solo lectura para servidor, PHP, MySQL,
 * WordPress, seguridad, WooCommerce y logs.
 * V2.0: resumen ejecutivo por impacto, persistencia, tendencia y API para informes.
 * V2.1-MYSQL-GRAFICOS-20260825: recursos observables y perfil de tiempos de consultas MySQL.
 */
defined('ABSPATH') || exit;

// Monitor externo: GitHub Actions mide la portada y WordPress recibe/almacena los resultados firmados.
add_action('rest_api_init', 'seo_server_status_register_external_monitor_route');

/**
 * Renderiza la pantalla principal Server Status.
 */
function seo_server_status() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para acceder a esta página.', 'seo-system'));
    }

    $active_tab = seo_server_status_get_active_tab();
    $snapshot = null;

    if (
        isset($_POST['seo_server_status_monitor_rotate_secret']) &&
        check_admin_referer('seo_server_status_monitor_rotate_secret', 'seo_server_status_monitor_rotate_secret_nonce')
    ) {
        seo_server_status_monitor_rotate_secret();
    }

    if (
        isset($_POST['seo_server_status_run']) &&
        check_admin_referer('seo_server_status_run', 'seo_server_status_nonce')
    ) {
        seo_server_status_store_snapshot(seo_server_status_collect_snapshot(true));
        $snapshot = seo_server_status_load_snapshot();
        $snapshot['persisted'] = true;
    }

    if (!is_array($snapshot)) {
        $snapshot = seo_server_status_load_snapshot();
        if (!empty($snapshot['checks'])) {
            $snapshot['persisted'] = true;
        }
    }
    if (!empty($snapshot) && (int) ($snapshot['schema_version'] ?? 0) < 4) {
        $snapshot = array();
    }
    if (empty($snapshot['checks'])) {
        $snapshot = seo_server_status_collect_snapshot(false);
        $snapshot['persisted'] = false;
    }
    $GLOBALS['seo_server_status_current_snapshot'] = $snapshot;

    echo '<div class="wrap seo-server-status-wrap">';
    echo '<h1>Server Status</h1>';
    echo '<p>Panel de diagnóstico de solo lectura del servidor, WordPress, seguridad, base de datos, WooCommerce, rendimiento y logs.</p>';

    seo_server_status_render_styles();
    seo_server_status_render_response_monitor($snapshot);
    seo_server_status_render_run_button($snapshot);
    seo_server_status_render_tabs($active_tab);

    echo '<div class="seo-status-panel">';
    seo_server_status_render_compact_health($snapshot);

    switch ($active_tab) {
        case 'php':
            seo_server_status_render_php_tab();
            break;
        case 'mysql':
            seo_server_status_render_mysql_tab();
            break;
        case 'wordpress':
            seo_server_status_render_wordpress_tab();
            break;
        case 'security':
            seo_server_status_render_security_tab();
            break;
        case 'server':
            seo_server_status_render_server_tab();
            break;
        case 'woocommerce':
            seo_server_status_render_woocommerce_tab();
            break;
        case 'performance':
            seo_server_status_render_performance_tab();
            break;
        case 'logs':
            seo_server_status_render_logs_tab();
            break;
        default:
            seo_server_status_render_summary_tab();
            break;
    }

    echo '</div>';
    echo '</div>';
}

/**
 * Devuelve la pestaña activa solicitada por URL.
 */
function seo_server_status_get_active_tab() {
    $allowed_tabs = array('summary', 'php', 'mysql', 'wordpress', 'security', 'server', 'woocommerce', 'performance', 'logs');
    $tab = isset($_GET['seo_status_tab']) ? sanitize_key(wp_unslash($_GET['seo_status_tab'])) : 'summary';
    return in_array($tab, $allowed_tabs, true) ? $tab : 'summary';
}

/**
 * Renderiza las pestañas superiores usando enlaces HTML válidos.
 */
function seo_server_status_render_run_button($snapshot) {
    $generated = !empty($snapshot['persisted']) && !empty($snapshot['generated_at']) ? (int) $snapshot['generated_at'] : 0;
    echo '<div style="margin:16px 0 12px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">';
    echo '<form method="post" style="margin:0;">';
    wp_nonce_field('seo_server_status_run', 'seo_server_status_nonce');
    echo '<input type="hidden" name="seo_server_status_run" value="1">';
    submit_button('Ejecutar chequeo completo', 'primary', 'submit', false);
    echo '</form>';
    if ($generated > 0) {
        echo '<span class="description">Último chequeo guardado: ' . esc_html(date_i18n('Y-m-d H:i', $generated)) . '</span>';
    } else {
        echo '<span class="description">Vista rápida sin guardar. Ejecuta el chequeo completo para crear una referencia y calcular tendencia.</span>';
    }
    echo '</div>';
}

function seo_server_status_render_tabs($active_tab) {
    $tabs = array(
        'summary' => 'Resumen',
        'php' => 'PHP',
        'mysql' => 'MySQL',
        'wordpress' => 'WordPress',
        'security' => 'Seguridad',
        'server' => 'Servidor',
        'woocommerce' => 'WooCommerce',
        'performance' => 'Rendimiento',
        'logs' => 'Logs',
    );

    echo '<nav class="nav-tab-wrapper seo-status-tabs">';

    foreach ($tabs as $tab_id => $label) {
        $class = ($active_tab === $tab_id) ? ' nav-tab-active' : '';
        $url = add_query_arg('seo_status_tab', $tab_id);
        echo '<a href="' . esc_url($url) . '" class="nav-tab' . esc_attr($class) . '">' . esc_html($label) . '</a>';
    }

    echo '</nav>';
}

/**
 * Imprime CSS interno para no depender de archivos externos.
 */
function seo_server_status_render_styles() {
    echo '<style>
        .seo-server-status-wrap .seo-status-panel{background:#fff;border:1px solid #ccd0d4;border-top:none;padding:20px;max-width:1320px;}
        .seo-status-tabs{margin-top:18px;}
        .seo-status-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin:20px 0;}
        .seo-status-card{border:1px solid #dcdcde;border-radius:6px;padding:16px;background:#fff;margin:0 0 18px;max-width:none;}
        .seo-status-card h2{margin-top:0;font-size:18px;}
        .seo-status-kpi{font-size:26px;font-weight:600;line-height:1.2;}
        .seo-status-table{width:100%;border-collapse:collapse;margin-top:15px;}
        .seo-status-table th,.seo-status-table td{border-bottom:1px solid #e5e5e5;padding:10px;text-align:left;vertical-align:top;}
        .seo-status-table th{background:#f6f7f7;font-weight:600;}
        .seo-status-badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap;}
        .seo-status-ok{background:#d1e7dd;color:#0f5132;}
        .seo-status-warning{background:#fff3cd;color:#664d03;}
        .seo-status-error{background:#f8d7da;color:#842029;}
        .seo-status-info{background:#cff4fc;color:#055160;}
        .seo-log-box{background:#111;color:#eee;padding:15px;overflow:auto;max-height:520px;white-space:pre-wrap;font-family:Consolas,Monaco,monospace;font-size:12px;border-radius:4px;}
        .seo-muted{color:#646970;}
        .seo-status-note{border-left:4px solid #72aee6;background:#f0f6fc;padding:12px 14px;margin:16px 0;}
        .seo-status-important{background:#ffedd5;color:#9a3412;}
        .seo-server-health-strip{display:grid;grid-template-columns:minmax(190px,1.35fr) repeat(4,minmax(115px,.75fr));gap:10px;margin:0 0 20px;}
        .seo-server-health-box{border:1px solid #dcdcde;border-left-width:5px;border-radius:7px;padding:12px 14px;background:#fff;min-width:0;}
        .seo-server-health-box strong{display:block;font-size:11px;letter-spacing:.04em;text-transform:uppercase;color:#50575e;margin-bottom:4px;}
        .seo-server-health-value{font-size:22px;font-weight:750;line-height:1.15;}
        .seo-server-health-error{border-left-color:#d63638;background:#fff5f5;}
        .seo-server-health-important{border-left-color:#d97706;background:#fff8ed;}
        .seo-server-health-warning{border-left-color:#dba617;background:#fffbea;}
        .seo-server-health-ok{border-left-color:#00a32a;background:#f3fbf5;}
        .seo-server-health-info{border-left-color:#2271b1;background:#f0f6fc;}
        .seo-server-priority-list{display:grid;gap:8px;margin:14px 0 22px;}
        .seo-server-priority-item{display:grid;grid-template-columns:105px minmax(190px,1fr) 2fr;gap:12px;align-items:start;border:1px solid #dcdcde;border-radius:6px;padding:10px 12px;background:#fff;}
        .seo-server-category-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:16px 0 24px;}
        .seo-server-category-card{border:1px solid #dcdcde;border-radius:7px;padding:14px;background:#fff;}
        .seo-server-category-card h3{margin:0 0 8px;font-size:15px;}
        .seo-server-category-score{font-size:24px;font-weight:750;}
        .seo-response-monitor{border:1px solid #c3c4c7;border-radius:8px;background:#fff;padding:18px;margin:18px 0 16px;max-width:1284px;}
        .seo-response-monitor-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;margin-bottom:14px;}
        .seo-response-monitor-head h2{margin:0 0 4px;font-size:19px;}
        .seo-response-monitor-controls{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
        .seo-response-monitor-ranges{display:flex;gap:4px;padding:3px;background:#f0f0f1;border-radius:6px;}
        .seo-response-monitor-ranges a{display:inline-block;padding:5px 9px;border-radius:4px;text-decoration:none;color:#2c3338;font-weight:600;}
        .seo-response-monitor-ranges a.is-active{background:#2271b1;color:#fff;}
        .seo-response-monitor-kpis{display:grid;grid-template-columns:repeat(4,minmax(145px,1fr));gap:10px;margin:12px 0 16px;}
        .seo-response-monitor-kpi{border:1px solid #dcdcde;border-radius:7px;padding:11px 12px;background:#fdfdfd;}
        .seo-response-monitor-kpi strong{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#646970;margin-bottom:4px;}
        .seo-response-monitor-kpi-value{font-size:22px;font-weight:750;line-height:1.15;}
        .seo-response-monitor-chart{border:1px solid #e2e4e7;border-radius:7px;background:#fff;padding:8px 8px 4px;overflow:hidden;}
        .seo-response-monitor-chart svg{display:block;width:100%;height:auto;min-height:220px;}
        .seo-monitor-grid{stroke:#dcdcde;stroke-width:1;}
        .seo-monitor-axis{fill:#646970;font-size:12px;}
        .seo-monitor-line{fill:none;stroke:#2271b1;stroke-width:3;stroke-linejoin:round;stroke-linecap:round;}
        .seo-monitor-dot{stroke:#fff;stroke-width:2;}
        .seo-monitor-dot-ok{fill:#00a32a;}
        .seo-monitor-dot-warning{fill:#dba617;}
        .seo-monitor-dot-important{fill:#d97706;}
        .seo-monitor-dot-error{fill:#d63638;}
        .seo-monitor-dot-info{fill:#2271b1;}
        .seo-response-monitor-legend{display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-top:9px;font-size:12px;color:#646970;}
        .seo-response-monitor-legend i{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:4px;vertical-align:middle;}
        .seo-response-monitor-empty{padding:32px 16px;text-align:center;color:#646970;}
        .seo-mysql-live-grid{display:grid;grid-template-columns:repeat(4,minmax(180px,1fr));gap:12px;margin:14px 0 18px;}
        .seo-mysql-live-card{border:1px solid #dcdcde;border-radius:7px;background:#fff;padding:14px;min-width:0;}
        .seo-mysql-live-card strong{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#646970;margin-bottom:5px;}
        .seo-mysql-live-value{font-size:23px;font-weight:750;line-height:1.2;margin-bottom:8px;}
        .seo-mysql-meter{height:10px;background:#f0f0f1;border-radius:999px;overflow:hidden;margin:7px 0 6px;}
        .seo-mysql-meter-fill{display:block;height:100%;background:#2271b1;border-radius:999px;min-width:2px;}
        .seo-mysql-meter-fill.is-warning{background:#dba617;}
        .seo-mysql-meter-fill.is-important{background:#d97706;}
        .seo-mysql-meter-fill.is-error{background:#d63638;}
        .seo-mysql-query-kpis{display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:10px;margin:12px 0 16px;}
        .seo-mysql-query-kpi{border:1px solid #dcdcde;border-radius:7px;padding:12px;background:#fdfdfd;}
        .seo-mysql-query-kpi strong{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#646970;margin-bottom:4px;}
        .seo-mysql-query-kpi-value{font-size:22px;font-weight:750;line-height:1.2;}
        .seo-mysql-query-chart{border:1px solid #e2e4e7;border-radius:7px;background:#fff;padding:14px;margin-top:12px;}
        .seo-mysql-query-row{display:grid;grid-template-columns:minmax(155px,1fr) minmax(180px,4fr) 84px;gap:10px;align-items:center;margin:9px 0;}
        .seo-mysql-query-label{font-size:12px;font-weight:600;color:#2c3338;}
        .seo-mysql-query-track{height:14px;background:#f0f0f1;border-radius:999px;overflow:hidden;}
        .seo-mysql-query-bar{height:100%;background:#2271b1;border-radius:999px;min-width:2px;}
        .seo-mysql-query-time{text-align:right;font-variant-numeric:tabular-nums;font-size:12px;color:#50575e;}
        @media(max-width:900px){.seo-server-health-strip{grid-template-columns:repeat(2,minmax(140px,1fr));}.seo-server-health-box:first-child{grid-column:1/-1}.seo-server-priority-item{grid-template-columns:1fr;gap:5px;}.seo-response-monitor-kpis,.seo-mysql-live-grid,.seo-mysql-query-kpis{grid-template-columns:repeat(2,minmax(140px,1fr));}.seo-mysql-query-row{grid-template-columns:140px minmax(140px,1fr) 76px;}}
        @media(max-width:520px){.seo-response-monitor-kpis,.seo-mysql-live-grid,.seo-mysql-query-kpis{grid-template-columns:1fr;}.seo-mysql-query-row{grid-template-columns:1fr;gap:5px;}.seo-mysql-query-time{text-align:left;}.seo-response-monitor-controls{width:100%;}.seo-response-monitor-ranges{width:100%;}.seo-response-monitor-ranges a{flex:1;text-align:center;}}
    </style>';
}

/**
 * Devuelve una etiqueta visual de estado.
 */
function seo_server_status_badge($status) {
    $map = array(
        'ok' => array('class' => 'seo-status-ok', 'label' => 'Correcto'),
        'warning' => array('class' => 'seo-status-warning', 'label' => 'Aviso'),
        'important' => array('class' => 'seo-status-important', 'label' => 'Importante'),
        'error' => array('class' => 'seo-status-error', 'label' => 'Crítico'),
        'info' => array('class' => 'seo-status-info', 'label' => 'Info'),
    );

    if (!isset($map[$status])) {
        $status = 'info';
    }

    return '<span class="seo-status-badge ' . esc_attr($map[$status]['class']) . '">' . esc_html($map[$status]['label']) . '</span>';
}

/**
 * Crea una fila estándar de resultado.
 */
function seo_server_status_row($label, $value, $status = 'info', $help = '') {
    echo '<tr>';
    echo '<td><strong>' . esc_html($label) . '</strong></td>';
    echo '<td>' . wp_kses_post($value) . '</td>';
    echo '<td>' . seo_server_status_badge($status) . '</td>';
    echo '<td class="seo-muted">' . esc_html($help) . '</td>';
    echo '</tr>';
}

/**
 * Abre una tabla de diagnóstico.
 */
function seo_server_status_open_table() {
    echo '<table class="seo-status-table"><thead><tr><th>Chequeo</th><th>Valor</th><th>Estado</th><th>Observación</th></tr></thead><tbody>';
}

/**
 * Cierra una tabla de diagnóstico.
 */
function seo_server_status_close_table() {
    echo '</tbody></table>';
}



/**
 * Convierte valores tipo 256M, 1G o 512K en bytes.
 */
function seo_server_status_ini_to_bytes($value) {
    $value = trim((string) $value);

    if ($value === '' || $value === '-1') {
        return $value === '-1' ? PHP_INT_MAX : 0;
    }

    $last = strtolower($value[strlen($value) - 1]);
    $num = (float) $value;

    switch ($last) {
        case 'g':
            $num *= 1024;
        case 'm':
            $num *= 1024;
        case 'k':
            $num *= 1024;
            break;
    }

    return (int) $num;
}

/**
 * Formatea bytes en KB, MB o GB.
 */
function seo_server_status_format_bytes($bytes) {
    $bytes = (float) $bytes;

    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 2) . ' GB';
    }

    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    }

    if ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    }

    return round($bytes, 2) . ' B';
}

/**
 * Devuelve Sí o No de forma legible.
 */
function seo_server_status_yes_no($value) {
    return $value ? 'Sí' : 'No';
}

/**
 * Comprueba si una tabla existe.
 */
function seo_server_status_table_exists($table_name) {
    global $wpdb;
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
    return $found === $table_name;
}

/**
 * Devuelve el tamaño de una ruta local si PHP puede acceder a ella.
 */
function seo_server_status_dir_size($path, $max_files = 8000) {
    if (!$path || !is_dir($path) || !is_readable($path)) {
        return false;
    }

    $size = 0;
    $count = 0;

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($count >= $max_files) {
                break;
            }

            if ($file->isFile()) {
                $size += (int) $file->getSize();
                $count++;
            }
        }
    } catch (Exception $e) {
        return false;
    }

    return array('size' => $size, 'files' => $count, 'limited' => $count >= $max_files);
}

/**
 * Busca archivos grandes en rutas concretas sin recorrer todo el hosting.
 */
function seo_server_status_find_large_files($paths, $min_bytes = 10485760, $max_files = 35) {
    $found = array();

    foreach ($paths as $path) {
        if (!$path || !file_exists($path) || !is_readable($path)) {
            continue;
        }

        if (is_file($path)) {
            $size = filesize($path);
            if ($size >= $min_bytes) {
                $found[] = array('path' => $path, 'size' => $size, 'modified' => filemtime($path));
            }
            continue;
        }

        if (!is_dir($path)) {
            continue;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if (count($found) >= $max_files) {
                    break 2;
                }

                if (!$file->isFile()) {
                    continue;
                }

                $file_path = $file->getPathname();
                $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                $interesting = in_array($extension, array('log', 'sql', 'zip', 'gz', 'tar', 'tgz', 'bak'), true);

                if ($interesting && (int) $file->getSize() >= $min_bytes) {
                    $found[] = array('path' => $file_path, 'size' => (int) $file->getSize(), 'modified' => (int) $file->getMTime());
                }
            }
        } catch (Exception $e) {
            continue;
        }
    }

    usort($found, function ($a, $b) {
        return $b['size'] <=> $a['size'];
    });

    return array_slice($found, 0, $max_files);
}

/**
 * Obtiene la versión de MySQL o MariaDB.
 */
function seo_server_status_get_mysql_version() {
    global $wpdb;
    return $wpdb->get_var('SELECT VERSION()');
}

/**
 * Cuenta eventos cron pendientes y atrasados.
 */
function seo_server_status_get_cron_stats() {
    $cron = _get_cron_array();
    $total = 0;
    $overdue = 0;
    $now = time();

    if (!is_array($cron)) {
        return array('total' => 0, 'overdue' => 0);
    }

    foreach ($cron as $timestamp => $hooks) {
        foreach ($hooks as $hook_events) {
            $total += count($hook_events);
        }

        if ((int) $timestamp < ($now - 900)) {
            foreach ($hooks as $hook_events) {
                $overdue += count($hook_events);
            }
        }
    }

    return array('total' => $total, 'overdue' => $overdue);
}

/**
 * Devuelve estadísticas de wp_options y autoload.
 */
function seo_server_status_get_options_stats() {
    global $wpdb;

    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}");
    $autoload_size = (float) $wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes', 'on', 'auto-on', 'auto')");
    $autoload_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload IN ('yes', 'on', 'auto-on', 'auto')");
    $largest = $wpdb->get_row("SELECT option_name, LENGTH(option_value) AS option_size FROM {$wpdb->options} ORDER BY option_size DESC LIMIT 1");

    return array(
        'total' => $total,
        'autoload_size' => $autoload_size,
        'autoload_count' => $autoload_count,
        'largest_name' => $largest ? $largest->option_name : '',
        'largest_size' => $largest ? (float) $largest->option_size : 0,
    );
}

/**
 * Devuelve el top de opciones autoload más pesadas.
 */
function seo_server_status_get_top_autoload_options($limit = 20) {
    global $wpdb;

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT option_name, LENGTH(option_value) AS option_size
             FROM {$wpdb->options}
             WHERE autoload IN ('yes', 'on', 'auto-on', 'auto')
             ORDER BY option_size DESC
             LIMIT %d",
            $limit
        )
    );
}

/**
 * Devuelve tablas más grandes de la base de datos actual.
 */
function seo_server_status_get_largest_tables($limit = 15) {
    global $wpdb;

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT TABLE_NAME AS table_name, TABLE_ROWS AS table_rows, DATA_LENGTH AS data_length, INDEX_LENGTH AS index_length, ENGINE AS engine
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = %s
             ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC
             LIMIT %d",
            DB_NAME,
            $limit
        )
    );
}

/**
 * Monitor externo de disponibilidad y tiempo de respuesta.
 * GitHub Actions realiza la petición desde fuera y envía un histórico firmado.
 */
function seo_server_status_monitor_option_name() {
    return 'seo_server_status_external_response_history_v2';
}

function seo_server_status_monitor_secret_option_name() {
    return 'seo_server_status_external_monitor_secret_v1';
}

function seo_server_status_monitor_endpoint_url() {
    return rest_url('seo-system/v1/external-monitor');
}

function seo_server_status_monitor_get_secret($create = false) {
    $secret = get_option(seo_server_status_monitor_secret_option_name(), '');
    if (is_string($secret) && strlen($secret) >= 32) {
        return $secret;
    }

    if (!$create || !current_user_can('manage_options')) {
        return '';
    }

    try {
        $secret = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $secret = wp_generate_password(64, false, false);
    }

    update_option(seo_server_status_monitor_secret_option_name(), $secret, false);
    return $secret;
}

function seo_server_status_monitor_rotate_secret() {
    if (!current_user_can('manage_options')) {
        return false;
    }

    delete_option(seo_server_status_monitor_secret_option_name());
    return seo_server_status_monitor_get_secret(true);
}

function seo_server_status_register_external_monitor_route() {
    register_rest_route('seo-system/v1', '/external-monitor', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'seo_server_status_external_monitor_receive',
        'permission_callback' => '__return_true',
    ));
}

function seo_server_status_monitor_load_history() {
    $history = get_option(seo_server_status_monitor_option_name(), array());
    return is_array($history) ? $history : array();
}

function seo_server_status_monitor_status($http_code, $response_ms, $has_error = false) {
    $http_code = (int) $http_code;
    $response_ms = (float) $response_ms;

    if ($has_error || $http_code === 0 || $http_code >= 500) {
        return 'error';
    }
    if ($http_code >= 400) {
        return 'important';
    }
    if ($http_code >= 300 || $response_ms >= 3000) {
        return 'important';
    }
    if ($response_ms >= 1500) {
        return 'warning';
    }
    return 'ok';
}

function seo_server_status_external_monitor_receive($request) {
    $secret = get_option(seo_server_status_monitor_secret_option_name(), '');
    if (!is_string($secret) || strlen($secret) < 32) {
        return new WP_Error('seo_monitor_not_configured', 'El monitor externo todavía no está configurado.', array('status' => 503));
    }

    $body = (string) $request->get_body();
    if ($body === '' || strlen($body) > 1048576) {
        return new WP_Error('seo_monitor_bad_payload', 'Payload vacío o demasiado grande.', array('status' => 413));
    }

    $provided_signature = strtolower(trim((string) $request->get_header('x-seo-monitor-signature')));
    if (strpos($provided_signature, 'sha256=') === 0) {
        $provided_signature = substr($provided_signature, 7);
    }
    $expected_signature = hash_hmac('sha256', $body, $secret);
    if ($provided_signature === '' || !hash_equals($expected_signature, $provided_signature)) {
        return new WP_Error('seo_monitor_invalid_signature', 'Firma del monitor no válida.', array('status' => 401));
    }

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        return new WP_Error('seo_monitor_invalid_json', 'JSON no válido.', array('status' => 400));
    }

    $sent_at = isset($payload['sent_at']) ? (int) $payload['sent_at'] : 0;
    if ($sent_at <= 0 || abs(time() - $sent_at) > (15 * MINUTE_IN_SECONDS)) {
        return new WP_Error('seo_monitor_stale_envelope', 'La fecha del envío no es válida o es demasiado antigua.', array('status' => 401));
    }

    $samples = isset($payload['samples']) && is_array($payload['samples']) ? $payload['samples'] : array();
    if (empty($samples) || count($samples) > 1200) {
        return new WP_Error('seo_monitor_invalid_samples', 'Número de muestras no válido.', array('status' => 400));
    }

    $history = seo_server_status_monitor_load_history();
    $known_ids = array();
    foreach ($history as $existing) {
        if (!empty($existing['sample_id'])) {
            $known_ids[(string) $existing['sample_id']] = true;
        }
    }

    $now = time();
    $oldest_allowed = $now - (36 * DAY_IN_SECONDS);
    $inserted = 0;
    $rejected = 0;

    foreach ($samples as $sample) {
        if (!is_array($sample)) {
            $rejected++;
            continue;
        }

        $timestamp = isset($sample['timestamp']) ? (int) $sample['timestamp'] : 0;
        if ($timestamp < $oldest_allowed || $timestamp > ($now + 10 * MINUTE_IN_SECONDS)) {
            $rejected++;
            continue;
        }

        $http_code = max(0, min(599, (int) ($sample['http_code'] ?? 0)));
        $curl_exit = max(0, (int) ($sample['curl_exit'] ?? 0));
        $response_ms = max(0, min(120000, (float) ($sample['response_ms'] ?? 0)));
        $ttfb_ms = max(0, min(120000, (float) ($sample['ttfb_ms'] ?? 0)));
        $connect_ms = max(0, min(120000, (float) ($sample['connect_ms'] ?? 0)));
        $dns_ms = max(0, min(120000, (float) ($sample['dns_ms'] ?? 0)));
        $tls_ms = max(0, min(120000, (float) ($sample['tls_ms'] ?? 0)));
        $sample_id = sanitize_key((string) ($sample['sample_id'] ?? ''));

        if ($sample_id === '') {
            $sample_id = sha1($timestamp . '|' . $http_code . '|' . $response_ms . '|' . (string) ($sample['runner'] ?? 'github'));
        }
        if (isset($known_ids[$sample_id])) {
            continue;
        }

        $error = sanitize_text_field((string) ($sample['error'] ?? ''));
        if (strlen($error) > 500) {
            $error = substr($error, 0, 500);
        }

        $history[] = array(
            'sample_id'      => $sample_id,
            'timestamp'      => $timestamp,
            'response_ms'    => round($response_ms, 1),
            'ttfb_ms'        => round($ttfb_ms, 1),
            'connect_ms'     => round($connect_ms, 1),
            'dns_ms'         => round($dns_ms, 1),
            'tls_ms'         => round($tls_ms, 1),
            'http_code'      => $http_code,
            'curl_exit'      => $curl_exit,
            'status'         => seo_server_status_monitor_status($http_code, $response_ms, $curl_exit !== 0),
            'error'          => $error,
            'source'         => 'github',
            'runner'         => sanitize_text_field((string) ($sample['runner'] ?? 'GitHub Actions')),
            'remote_ip'      => sanitize_text_field((string) ($sample['remote_ip'] ?? '')),
            'redirects'      => max(0, (int) ($sample['redirects'] ?? 0)),
            'size_download'  => max(0, (int) ($sample['size_download'] ?? 0)),
            'run_id'         => sanitize_text_field((string) ($sample['run_id'] ?? '')),
            'run_attempt'    => max(1, (int) ($sample['run_attempt'] ?? 1)),
            'target_url'     => esc_url_raw((string) ($sample['target_url'] ?? '')),
        );
        $known_ids[$sample_id] = true;
        $inserted++;
    }

    usort($history, static function ($a, $b) {
        return ((int) ($a['timestamp'] ?? 0)) <=> ((int) ($b['timestamp'] ?? 0));
    });

    $cutoff = $now - (35 * DAY_IN_SECONDS);
    $history = array_values(array_filter($history, static function ($row) use ($cutoff) {
        return isset($row['timestamp']) && (int) $row['timestamp'] >= $cutoff;
    }));

    if (count($history) > 2500) {
        $history = array_slice($history, -2500);
    }

    update_option(seo_server_status_monitor_option_name(), $history, false);

    return new WP_REST_Response(array(
        'ok'       => true,
        'inserted' => $inserted,
        'rejected' => $rejected,
        'stored'   => count($history),
    ), 200);
}

function seo_server_status_monitor_range() {
    $range = isset($_GET['seo_monitor_range']) ? sanitize_key(wp_unslash($_GET['seo_monitor_range'])) : 'day';
    return in_array($range, array('day', 'week', 'month'), true) ? $range : 'day';
}

function seo_server_status_monitor_range_config($range) {
    $configs = array(
        'day' => array('seconds' => DAY_IN_SECONDS, 'bucket' => HOUR_IN_SECONDS, 'label' => '24 horas'),
        'week' => array('seconds' => 7 * DAY_IN_SECONDS, 'bucket' => 6 * HOUR_IN_SECONDS, 'label' => '7 días'),
        'month' => array('seconds' => 30 * DAY_IN_SECONDS, 'bucket' => DAY_IN_SECONDS, 'label' => '30 días'),
    );
    return $configs[$range] ?? $configs['day'];
}

function seo_server_status_monitor_worst_status($statuses) {
    $rank = array('info' => 0, 'ok' => 1, 'warning' => 2, 'important' => 3, 'error' => 4);
    $worst = 'info';
    foreach ((array) $statuses as $status) {
        $status = isset($rank[$status]) ? $status : 'info';
        if ($rank[$status] > $rank[$worst]) {
            $worst = $status;
        }
    }
    return $worst;
}

function seo_server_status_monitor_raw_for_range($range) {
    $config = seo_server_status_monitor_range_config($range);
    $cutoff = time() - $config['seconds'];
    return array_values(array_filter(seo_server_status_monitor_load_history(), static function ($row) use ($cutoff) {
        return isset($row['timestamp']) && (int) $row['timestamp'] >= $cutoff && ($row['source'] ?? '') === 'github';
    }));
}

function seo_server_status_monitor_points_for_range($range) {
    $config = seo_server_status_monitor_range_config($range);
    $bucket_size = max(1, (int) $config['bucket']);
    $history = seo_server_status_monitor_raw_for_range($range);
    $buckets = array();

    foreach ($history as $row) {
        $timestamp = (int) ($row['timestamp'] ?? 0);
        $bucket = (int) floor($timestamp / $bucket_size) * $bucket_size;
        if (!isset($buckets[$bucket])) {
            $buckets[$bucket] = array(
                'timestamp' => $bucket,
                'response_total' => 0.0,
                'ttfb_total' => 0.0,
                'samples' => 0,
                'statuses' => array(),
                'http_code' => 0,
            );
        }

        $buckets[$bucket]['response_total'] += (float) ($row['response_ms'] ?? 0);
        $buckets[$bucket]['ttfb_total'] += (float) ($row['ttfb_ms'] ?? 0);
        $buckets[$bucket]['samples']++;
        $buckets[$bucket]['statuses'][] = $row['status'] ?? 'info';
        $buckets[$bucket]['http_code'] = (int) ($row['http_code'] ?? 0);
    }

    ksort($buckets);
    $points = array();
    foreach ($buckets as $bucket) {
        $samples = max(1, (int) $bucket['samples']);
        $points[] = array(
            'timestamp' => (int) $bucket['timestamp'],
            'response_ms' => round($bucket['response_total'] / $samples, 1),
            'ttfb_ms' => round($bucket['ttfb_total'] / $samples, 1),
            'samples' => (int) $bucket['samples'],
            'status' => seo_server_status_monitor_worst_status($bucket['statuses']),
            'http_code' => (int) $bucket['http_code'],
        );
    }

    return $points;
}

function seo_server_status_monitor_percentile($values, $percentile) {
    $values = array_values(array_filter(array_map('floatval', (array) $values), static function ($value) {
        return $value >= 0;
    }));
    if (empty($values)) {
        return 0;
    }
    sort($values, SORT_NUMERIC);
    $index = (int) ceil(($percentile / 100) * count($values)) - 1;
    $index = max(0, min(count($values) - 1, $index));
    return round($values[$index], 1);
}

function seo_server_status_monitor_svg($points, $range) {
    if (empty($points)) {
        return '<div class="seo-response-monitor-empty">Esperando la primera muestra externa de GitHub Actions.</div>';
    }

    $config = seo_server_status_monitor_range_config($range);
    $width = 1000;
    $height = 280;
    $left = 58;
    $right = 20;
    $top = 22;
    $bottom = 58;
    $plot_width = $width - $left - $right;
    $plot_height = $height - $top - $bottom;
    $start_time = time() - $config['seconds'];
    $end_time = time();

    $values = array_map(static function ($point) {
        return (float) ($point['response_ms'] ?? 0);
    }, $points);
    $max_value = max($values);
    $y_max = max(500, (int) ceil($max_value / 500) * 500);

    $coords = array();
    $circles = array();
    $status_marks = array();
    foreach ($points as $point) {
        $timestamp = (int) $point['timestamp'];
        $ratio_x = ($timestamp - $start_time) / max(1, ($end_time - $start_time));
        $x = $left + max(0, min(1, $ratio_x)) * $plot_width;
        $ratio_y = min(1, max(0, ((float) $point['response_ms']) / $y_max));
        $y = $top + (1 - $ratio_y) * $plot_height;
        $status = in_array($point['status'], array('ok', 'warning', 'important', 'error', 'info'), true) ? $point['status'] : 'info';
        $coords[] = round($x, 1) . ',' . round($y, 1);
        $title = esc_attr(round((float) $point['response_ms'], 1) . ' ms · TTFB ' . round((float) ($point['ttfb_ms'] ?? 0), 1) . ' ms · HTTP ' . (int) $point['http_code'] . ' · ' . date_i18n('Y-m-d H:i', $timestamp));
        $circles[] = '<circle class="seo-monitor-dot seo-monitor-dot-' . esc_attr($status) . '" cx="' . esc_attr(round($x, 1)) . '" cy="' . esc_attr(round($y, 1)) . '" r="4"><title>' . $title . '</title></circle>';
        $status_marks[] = '<circle class="seo-monitor-dot seo-monitor-dot-' . esc_attr($status) . '" cx="' . esc_attr(round($x, 1)) . '" cy="' . esc_attr($height - 24) . '" r="4"><title>' . $title . '</title></circle>';
    }

    $svg = '<svg viewBox="0 0 ' . esc_attr($width) . ' ' . esc_attr($height) . '" role="img" aria-label="Tiempo de respuesta externo de la portada y estado HTTP">';
    for ($i = 0; $i <= 4; $i++) {
        $y = $top + ($plot_height / 4) * $i;
        $label = (int) round($y_max - (($y_max / 4) * $i));
        $svg .= '<line class="seo-monitor-grid" x1="' . esc_attr($left) . '" y1="' . esc_attr(round($y, 1)) . '" x2="' . esc_attr($width - $right) . '" y2="' . esc_attr(round($y, 1)) . '"></line>';
        $svg .= '<text class="seo-monitor-axis" x="' . esc_attr($left - 8) . '" y="' . esc_attr(round($y + 4, 1)) . '" text-anchor="end">' . esc_html($label . ' ms') . '</text>';
    }

    for ($i = 0; $i <= 4; $i++) {
        $timestamp = $start_time + (($config['seconds'] / 4) * $i);
        $x = $left + ($plot_width / 4) * $i;
        if ($range === 'day') {
            $label = date_i18n('H:i', (int) $timestamp);
        } elseif ($range === 'week') {
            $label = date_i18n('D H:i', (int) $timestamp);
        } else {
            $label = date_i18n('d M', (int) $timestamp);
        }
        $svg .= '<text class="seo-monitor-axis" x="' . esc_attr(round($x, 1)) . '" y="' . esc_attr($height - 42) . '" text-anchor="middle">' . esc_html($label) . '</text>';
    }

    if (count($coords) > 1) {
        $svg .= '<polyline class="seo-monitor-line" points="' . esc_attr(implode(' ', $coords)) . '"></polyline>';
    }
    $svg .= implode('', $circles);
    $svg .= '<text class="seo-monitor-axis" x="' . esc_attr($left) . '" y="' . esc_attr($height - 20) . '">Estado HTTP externo</text>';
    $svg .= implode('', $status_marks);
    $svg .= '</svg>';

    return $svg;
}

function seo_server_status_monitor_setup_box() {
    $secret = seo_server_status_monitor_get_secret(true);
    $endpoint = seo_server_status_monitor_endpoint_url();

    echo '<details class="seo-status-card" style="margin:12px 0 16px;">';
    echo '<summary style="cursor:pointer;font-weight:600;">Configuración del monitor externo de GitHub</summary>';
    echo '<p class="seo-muted">Guarda estos tres valores como Secrets del repositorio. El secreto solo se muestra a administradores de WordPress.</p>';
    echo '<table class="seo-status-table"><tbody>';
    echo '<tr><td><strong>SEO_MONITOR_TARGET_URL</strong></td><td><code>' . esc_html(home_url('/')) . '</code></td></tr>';
    echo '<tr><td><strong>SEO_MONITOR_INGEST_URL</strong></td><td><code>' . esc_html($endpoint) . '</code></td></tr>';
    echo '<tr><td><strong>SEO_MONITOR_SECRET</strong></td><td><code style="word-break:break-all;">' . esc_html($secret) . '</code></td></tr>';
    echo '</tbody></table>';
    echo '<form method="post" style="margin-top:12px;">';
    wp_nonce_field('seo_server_status_monitor_rotate_secret', 'seo_server_status_monitor_rotate_secret_nonce');
    submit_button('Rotar secreto', 'secondary', 'seo_server_status_monitor_rotate_secret', false, array('onclick' => "return confirm('¿Rotar el secreto? Tendrás que actualizar SEO_MONITOR_SECRET en GitHub.');"));
    echo '</form>';
    echo '</details>';
}

function seo_server_status_render_response_monitor($snapshot) {
    $range = seo_server_status_monitor_range();
    $config = seo_server_status_monitor_range_config($range);
    $points = seo_server_status_monitor_points_for_range($range);
    $raw = seo_server_status_monitor_raw_for_range($range);
    $history = seo_server_status_monitor_load_history();
    $latest = !empty($history) ? end($history) : array();

    $response_values = array_map(static function ($row) {
        return (float) ($row['response_ms'] ?? 0);
    }, $raw);
    $average = !empty($response_values) ? round(array_sum($response_values) / count($response_values), 1) : 0;
    $p95 = seo_server_status_monitor_percentile($response_values, 95);
    $up = 0;
    foreach ($raw as $row) {
        $code = (int) ($row['http_code'] ?? 0);
        if ($code >= 200 && $code < 400 && ($row['status'] ?? 'error') !== 'error') {
            $up++;
        }
    }
    $availability = !empty($raw) ? round(($up / count($raw)) * 100, 2) : 0;
    $expected_samples = max(1, (int) floor($config['seconds'] / HOUR_IN_SECONDS));
    $coverage = min(100, round((count($raw) / $expected_samples) * 100, 1));

    $latest_timestamp = isset($latest['timestamp']) ? (int) $latest['timestamp'] : 0;
    $is_stale = $latest_timestamp === 0 || $latest_timestamp < (time() - (150 * MINUTE_IN_SECONDS));
    $latest_status = $is_stale ? 'warning' : (isset($latest['status']) ? $latest['status'] : 'info');
    $latest_code = isset($latest['http_code']) ? (int) $latest['http_code'] : 0;
    $latest_ms = isset($latest['response_ms']) ? (float) $latest['response_ms'] : 0;
    $latest_ttfb = isset($latest['ttfb_ms']) ? (float) $latest['ttfb_ms'] : 0;
    $status_text = $is_stale ? 'Sin muestra externa reciente' : ($latest_code > 0 ? 'HTTP ' . $latest_code : 'Sin respuesta');

    echo '<section class="seo-response-monitor">';
    echo '<div class="seo-response-monitor-head">';
    echo '<div><h2>Disponibilidad y tiempo de respuesta externo</h2><div class="seo-muted">Medido desde un runner de GitHub Actions, fuera del hosting. Histórico: ' . esc_html($config['label']) . '.</div></div>';
    echo '<div class="seo-response-monitor-controls">';
    echo '<div class="seo-response-monitor-ranges">';
    $base_url = remove_query_arg('seo_monitor_range');
    foreach (array('day' => 'Día', 'week' => 'Semana', 'month' => 'Mes') as $key => $label) {
        $class = $range === $key ? ' class="is-active"' : '';
        echo '<a' . $class . ' href="' . esc_url(add_query_arg('seo_monitor_range', $key, $base_url)) . '">' . esc_html($label) . '</a>';
    }
    echo '</div>';
    echo '<span class="seo-status-badge seo-status-info">GitHub externo</span>';
    echo '</div></div>';

    echo '<div class="seo-response-monitor-kpis">';
    echo '<div class="seo-response-monitor-kpi"><strong>Estado actual</strong><div class="seo-response-monitor-kpi-value">' . seo_server_status_badge($latest_status) . '</div><span class="seo-muted">' . esc_html($status_text) . '</span></div>';
    echo '<div class="seo-response-monitor-kpi"><strong>Última respuesta</strong><div class="seo-response-monitor-kpi-value">' . esc_html(number_format_i18n($latest_ms, 1)) . ' ms</div><span class="seo-muted">TTFB: ' . esc_html(number_format_i18n($latest_ttfb, 1)) . ' ms · ' . ($latest_timestamp ? esc_html(date_i18n('Y-m-d H:i', $latest_timestamp)) : 'Sin muestra') . '</span></div>';
    echo '<div class="seo-response-monitor-kpi"><strong>Media / P95</strong><div class="seo-response-monitor-kpi-value">' . esc_html(number_format_i18n($average, 1)) . ' ms</div><span class="seo-muted">P95: ' . esc_html(number_format_i18n($p95, 1)) . ' ms</span></div>';
    echo '<div class="seo-response-monitor-kpi"><strong>Disponibilidad</strong><div class="seo-response-monitor-kpi-value">' . esc_html(number_format_i18n($availability, 2)) . '%</div><span class="seo-muted">' . esc_html(count($raw)) . ' muestras · cobertura ' . esc_html(number_format_i18n($coverage, 1)) . '%</span></div>';
    echo '</div>';

    echo '<div class="seo-response-monitor-chart">' . seo_server_status_monitor_svg($points, $range) . '</div>'; // SVG generado internamente con valores escapados.
    echo '<div class="seo-response-monitor-legend">';
    echo '<span><i style="background:#00a32a"></i>Correcto</span>';
    echo '<span><i style="background:#dba617"></i>Lento</span>';
    echo '<span><i style="background:#d97706"></i>Importante</span>';
    echo '<span><i style="background:#d63638"></i>Error</span>';
    echo '<span>GitHub conserva 35 días en la rama <code>monitor-data</code> y reenvía el histórico cuando WordPress vuelve a responder.</span>';
    echo '</div>';
    echo '</section>';

    seo_server_status_monitor_setup_box();
}

/**
 * Renderiza el resumen general.
 */
function seo_server_status_snapshot_option_name() {
    return 'seo_server_status_v2_snapshot';
}

function seo_server_status_make_check($code, $category, $label, $value, $status, $detail = '') {
    return array(
        'code'     => (string) $code,
        'category' => (string) $category,
        'label'    => (string) $label,
        'value'    => (string) $value,
        'status'   => in_array($status, array('ok', 'warning', 'important', 'error', 'info'), true) ? $status : 'info',
        'detail'   => (string) $detail,
    );
}



/**
 * Devuelve un resumen de actualizaciones e inventario de plugins/themes.
 * Usa los transients de WordPress y no fuerza una consulta externa.
 */
function seo_server_status_security_update_stats() {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $plugins = function_exists('get_plugins') ? get_plugins() : array();
    $active = array_fill_keys((array) get_option('active_plugins', array()), true);
    if (is_multisite()) {
        foreach (array_keys((array) get_site_option('active_sitewide_plugins', array())) as $plugin_file) {
            $active[$plugin_file] = true;
        }
    }

    $inactive = 0;
    foreach (array_keys($plugins) as $plugin_file) {
        if (!isset($active[$plugin_file])) {
            $inactive++;
        }
    }

    $plugin_updates = get_site_transient('update_plugins');
    $theme_updates = get_site_transient('update_themes');
    $core_updates = get_site_transient('update_core');

    $plugin_update_count = is_object($plugin_updates) && !empty($plugin_updates->response) && is_array($plugin_updates->response)
        ? count($plugin_updates->response)
        : 0;
    $theme_update_count = is_object($theme_updates) && !empty($theme_updates->response) && is_array($theme_updates->response)
        ? count($theme_updates->response)
        : 0;

    $core_current = get_bloginfo('version');
    $core_newest = '';
    if (is_object($core_updates) && !empty($core_updates->updates) && is_array($core_updates->updates)) {
        foreach ($core_updates->updates as $offer) {
            if (!is_object($offer) || empty($offer->current)) {
                continue;
            }
            if (version_compare((string) $offer->current, (string) $core_current, '>')) {
                if ($core_newest === '' || version_compare((string) $offer->current, $core_newest, '>')) {
                    $core_newest = (string) $offer->current;
                }
            }
        }
    }

    $last_checked = 0;
    foreach (array($plugin_updates, $theme_updates, $core_updates) as $transient) {
        if (is_object($transient) && !empty($transient->last_checked)) {
            $last_checked = max($last_checked, (int) $transient->last_checked);
        }
    }

    return array(
        'plugins_total' => count($plugins),
        'plugins_inactive' => $inactive,
        'plugin_updates' => $plugin_update_count,
        'theme_updates' => $theme_update_count,
        'core_newest' => $core_newest,
        'last_checked' => $last_checked,
    );
}

/**
 * Comprueba que las claves y salts de WordPress existen y no usan placeholders.
 * Nunca devuelve ni muestra el contenido de las claves.
 */
function seo_server_status_security_salts_status() {
    $names = array(
        'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
        'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
    );
    $missing_effective = array();
    $constants_ok = 0;
    $database_fallback_ok = 0;

    foreach ($names as $name) {
        $constant_value = defined($name) ? (string) constant($name) : '';
        $constant_strong = $constant_value !== ''
            && strlen($constant_value) >= 32
            && stripos($constant_value, 'put your unique phrase here') === false;

        if ($constant_strong) {
            $constants_ok++;
            continue;
        }

        // WordPress usa opciones de sitio como fallback cuando estas constantes
        // no estan definidas o contienen valores duplicados/de ejemplo. Solo leemos;
        // no llamamos a wp_salt() para mantener este panel sin efectos laterales.
        $option_name = strtolower($name);
        $fallback = get_site_option($option_name, '');
        $fallback_strong = is_string($fallback) && strlen($fallback) >= 32;

        if ($fallback_strong) {
            $database_fallback_ok++;
            continue;
        }

        $missing_effective[] = $name;
    }

    return array(
        'ok' => empty($missing_effective) && $constants_ok === count($names),
        'effective_ok' => empty($missing_effective),
        'missing' => $missing_effective,
        'constants_ok' => $constants_ok,
        'database_fallback_ok' => $database_fallback_ok,
        'total' => count($names),
    );
}

/**
 * Localiza wp-config.php sin asumir una unica ubicacion.
 */
function seo_server_status_security_wp_config_path() {
    $candidates = array(
        ABSPATH . 'wp-config.php',
        dirname(rtrim(ABSPATH, '/\\')) . '/wp-config.php',
    );
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return wp_normalize_path($candidate);
        }
    }
    return '';
}

/**
 * Resume permisos POSIX de un archivo sin modificarlos.
 */
function seo_server_status_security_file_permissions($path) {
    if (!$path || !is_file($path)) {
        return array('available' => false, 'mode' => '', 'world_writable' => false, 'group_writable' => false);
    }

    $perms = @fileperms($path);
    if ($perms === false) {
        return array('available' => false, 'mode' => '', 'world_writable' => false, 'group_writable' => false);
    }

    return array(
        'available' => true,
        'mode' => substr(sprintf('%o', $perms), -4),
        'world_writable' => (bool) ($perms & 0002),
        'group_writable' => (bool) ($perms & 0020),
    );
}

/**
 * Peticion HTTP limitada a la propia web para comprobar superficie publica.
 */
function seo_server_status_security_http_get($url, $limit = 262144, $timeout = 8) {
    return wp_remote_get($url, array(
        'timeout' => $timeout,
        'redirection' => 0,
        'sslverify' => true,
        'limit_response_size' => max(1024, (int) $limit),
        'headers' => array(
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
        ),
        'user-agent' => 'SEO-System-Security-Audit/1.0; ' . home_url('/'),
    ));
}

/**
 * Comprueba un archivo sensible sin descargar mas que unos KB.
 * Un 200 HTML se trata como no concluyente para evitar falsos positivos por soft-404.
 */
function seo_server_status_security_probe_sensitive_path($relative_path) {
    $url = home_url('/' . ltrim($relative_path, '/'));
    $response = seo_server_status_security_http_get($url, 8192, 6);

    if (is_wp_error($response)) {
        return array('path' => $relative_path, 'code' => 0, 'exposed' => false, 'conclusive' => false, 'error' => $response->get_error_message());
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $content_type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
    $body = (string) wp_remote_retrieve_body($response);
    $looks_html = strpos($content_type, 'text/html') !== false || preg_match('/<html|<!doctype/i', $body);
    $exposed = $code >= 200 && $code < 300 && !$looks_html;
    $conclusive = in_array($code, array(401, 403, 404, 410), true) || $exposed;

    return array(
        'path' => $relative_path,
        'code' => $code,
        'exposed' => $exposed,
        'conclusive' => $conclusive,
        'error' => '',
    );
}

/**
 * Analiza HTML y cabeceras publicas de la portada con descarga limitada.
 */
function seo_server_status_security_public_surface() {
    $response = seo_server_status_security_http_get(home_url('/'), 524288, 10);
    if (is_wp_error($response)) {
        return array('ok' => false, 'error' => $response->get_error_message());
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $plugins = array();
    $plugin_versions = array();
    $themes = array();
    $core_versions = array();

    if (preg_match_all('#wp-content/plugins/([^/\'"?]+)/#i', $body, $matches)) {
        foreach ($matches[1] as $slug) {
            $plugins[sanitize_key($slug)] = true;
        }
    }
    if (preg_match_all('#wp-content/plugins/([^/\'"?]+)/[^\'"<>]*[?&]ver=([^&\'"#<>[:space:]]+)#i', $body, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $plugin_versions[sanitize_key($match[1])] = sanitize_text_field(rawurldecode($match[2]));
        }
    }
    if (preg_match_all('#wp-content/themes/([^/\'"?]+)/#i', $body, $matches)) {
        foreach ($matches[1] as $slug) {
            $themes[sanitize_key($slug)] = true;
        }
    }
    if (preg_match_all('#wp-(?:includes|admin)/[^\'"<>]*[?&]ver=([0-9][^&\'"#<>[:space:]]*)#i', $body, $matches)) {
        foreach ($matches[1] as $version) {
            $core_versions[sanitize_text_field(rawurldecode($version))] = true;
        }
    }

    $generator = '';
    if (preg_match('#<meta[^>]+name=["\']generator["\'][^>]+content=["\']WordPress[[:space:]]*([^"\']*)["\']#i', $body, $match)) {
        $generator = sanitize_text_field(trim($match[1]));
    }

    return array(
        'ok' => $code >= 200 && $code < 400,
        'code' => $code,
        'error' => '',
        'plugins' => array_keys($plugins),
        'plugin_versions' => $plugin_versions,
        'themes' => array_keys($themes),
        'core_versions' => array_keys($core_versions),
        'generator' => $generator,
        'hsts' => (string) wp_remote_retrieve_header($response, 'strict-transport-security'),
        'nosniff' => (string) wp_remote_retrieve_header($response, 'x-content-type-options'),
        'x_frame_options' => (string) wp_remote_retrieve_header($response, 'x-frame-options'),
        'csp' => (string) wp_remote_retrieve_header($response, 'content-security-policy'),
        'referrer_policy' => (string) wp_remote_retrieve_header($response, 'referrer-policy'),
        'x_powered_by' => (string) wp_remote_retrieve_header($response, 'x-powered-by'),
        'server' => (string) wp_remote_retrieve_header($response, 'server'),
    );
}

/**
 * Detecta directory listing real buscando la firma "Index of".
 */
function seo_server_status_security_probe_directory_listing($url) {
    $response = seo_server_status_security_http_get($url, 16384, 6);
    if (is_wp_error($response)) {
        return array('conclusive' => false, 'listed' => false, 'code' => 0, 'error' => $response->get_error_message());
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $listed = $code >= 200 && $code < 300 && (stripos($body, '<title>Index of ') !== false || stripos($body, '<h1>Index of ') !== false);
    return array('conclusive' => true, 'listed' => $listed, 'code' => $code, 'error' => '');
}

/**
 * Comprueba si la API REST permite enumerar usuarios/autores sin autenticacion.
 */
function seo_server_status_security_probe_rest_users() {
    $url = add_query_arg(array('per_page' => 1, '_fields' => 'id,slug'), rest_url('wp/v2/users'));
    $response = seo_server_status_security_http_get($url, 32768, 6);
    if (is_wp_error($response)) {
        return array('conclusive' => false, 'exposed' => false, 'code' => 0, 'error' => $response->get_error_message());
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    $exposed = $code === 200 && is_array($data) && !empty($data) && isset($data[0]['slug']);
    return array('conclusive' => true, 'exposed' => $exposed, 'code' => $code, 'error' => '');
}

/**
 * Comprueba la exposicion publica de xmlrpc.php.
 */
function seo_server_status_security_probe_xmlrpc() {
    $response = seo_server_status_security_http_get(home_url('/xmlrpc.php'), 4096, 6);
    if (is_wp_error($response)) {
        return array('conclusive' => false, 'enabled' => false, 'code' => 0, 'error' => $response->get_error_message());
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $body = strtolower((string) wp_remote_retrieve_body($response));
    $enabled = ($code === 405 && strpos($body, 'xml-rpc') !== false) || ($code >= 200 && $code < 300 && strpos($body, 'xml-rpc') !== false);
    if (in_array($code, array(401, 403, 404, 410), true)) {
        $enabled = false;
    }
    return array('conclusive' => true, 'enabled' => $enabled, 'code' => $code, 'error' => '');
}

/**
 * Busca PHP potencialmente ejecutable dentro de uploads. Solo lectura y con limite.
 */
function seo_server_status_security_scan_upload_php($max_files = 8000, $max_hits = 25) {
    $upload_dir = wp_get_upload_dir();
    $base = isset($upload_dir['basedir']) ? (string) $upload_dir['basedir'] : '';
    if (!$base || !is_dir($base) || !is_readable($base)) {
        return array('available' => false, 'hits' => array(), 'files' => 0, 'limited' => false);
    }

    $hits = array();
    $count = 0;
    $limited = false;
    $extensions = array('php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar');

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            if ($count >= $max_files) {
                $limited = true;
                break;
            }
            if (!$file->isFile()) {
                continue;
            }
            $count++;
            $extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
            if (!in_array($extension, $extensions, true)) {
                continue;
            }

            $path = $file->getPathname();
            if (strtolower($file->getFilename()) === 'index.php' && $file->getSize() <= 512) {
                $sample = @file_get_contents($path, false, null, 0, 512);
                if (is_string($sample) && stripos($sample, 'silence is golden') !== false) {
                    continue;
                }
            }

            $relative = ltrim(str_replace(wp_normalize_path($base), '', wp_normalize_path($path)), '/');
            $hits[] = $relative;
            if (count($hits) >= $max_hits) {
                break;
            }
        }
    } catch (Exception $e) {
        return array('available' => false, 'hits' => $hits, 'files' => $count, 'limited' => $limited);
    }

    return array('available' => true, 'hits' => $hits, 'files' => $count, 'limited' => $limited);
}

/**
 * Verifica checksums del core con la API oficial de WordPress cuando esta disponible.
 */
function seo_server_status_security_core_checksums() {
    global $wp_version, $wp_local_package;

    if (!function_exists('get_core_checksums')) {
        $update_file = ABSPATH . 'wp-admin/includes/update.php';
        if (is_file($update_file)) {
            require_once $update_file;
        }
    }
    if (!function_exists('get_core_checksums')) {
        return array('available' => false, 'mismatch' => array(), 'missing' => array(), 'error' => 'Funcion get_core_checksums no disponible.', 'locale' => '');
    }

    // Reproduce el criterio del propio Core_Upgrader de WordPress: usa el
    // locale del paquete instalado cuando existe y no considera wp-content
    // parte verificable del core, ya que traducciones/themes se actualizan
    // de forma independiente.
    $version = !empty($wp_version) ? (string) $wp_version : (string) get_bloginfo('version');
    $locale = !empty($wp_local_package) ? (string) $wp_local_package : 'en_US';
    $checksums = get_core_checksums($version, $locale);
    if (!is_array($checksums) || empty($checksums)) {
        return array('available' => false, 'mismatch' => array(), 'missing' => array(), 'error' => 'No se pudieron obtener checksums oficiales.', 'locale' => $locale);
    }

    $mismatch = array();
    $missing = array();
    $optional_missing = array('readme.html', 'license.txt', 'wp-config-sample.php');

    foreach ($checksums as $relative => $expected) {
        $relative = ltrim((string) $relative, '/');

        // WordPress Core_Upgrader::check_files() tambien omite wp-content.
        if (strpos($relative, 'wp-content/') === 0 || $relative === 'wp-content') {
            continue;
        }

        $path = ABSPATH . $relative;
        if (!is_file($path)) {
            if (!in_array($relative, $optional_missing, true)) {
                $missing[] = $relative;
            }
            continue;
        }
        $actual = @md5_file($path);
        if (!$actual || !hash_equals(strtolower((string) $expected), strtolower((string) $actual))) {
            $mismatch[] = $relative;
        }
    }

    return array('available' => true, 'mismatch' => $mismatch, 'missing' => $missing, 'error' => '', 'locale' => $locale);
}


/**
 * Carga defensivamente la integracion Cloudflare para que Server Status no
 * dependa del orden en que se hayan cargado los modulos de proveedores.
 */
function seo_server_status_load_cloudflare_module() {
    if (function_exists('seo_cloudflare_connection_state') && function_exists('seo_cloudflare_security_audit')) {
        return true;
    }
    $file = __DIR__ . '/import-export/suppliers/cloudflare.php';
    if (is_readable($file)) {
        require_once $file;
    }
    return function_exists('seo_cloudflare_connection_state') && function_exists('seo_cloudflare_security_audit');
}

/**
 * Traduce el snapshot Cloudflare de solo lectura a chequeos de Seguridad.
 * La ausencia de integracion o permisos se informa sin penalizar la salud.
 */
function seo_server_status_collect_cloudflare_security_checks($deep = false) {
    $checks = array();

    if (!seo_server_status_load_cloudflare_module()) {
        $checks[] = seo_server_status_make_check(
            'SEC-CF-CONNECTION', 'Seguridad', 'Conexion Cloudflare', 'Modulo no disponible', 'info',
            'La auditoria local continua funcionando; Cloudflare no se incluye en la puntuacion.'
        );
        return $checks;
    }

    $state = seo_cloudflare_connection_state();
    if (empty($state['configured'])) {
        $checks[] = seo_server_status_make_check(
            'SEC-CF-CONNECTION', 'Seguridad', 'Conexion Cloudflare', 'No configurada', 'info',
            'Configura Cloudflare en Importar / Exportar > Conexiones e integraciones para ampliar la auditoria perimetral.'
        );
        return $checks;
    }
    if (empty($state['enabled'])) {
        $checks[] = seo_server_status_make_check(
            'SEC-CF-CONNECTION', 'Seguridad', 'Conexion Cloudflare', 'Configurada, desactivada', 'info',
            'La credencial existe, pero la integracion Cloudflare esta desactivada y no se consulta.'
        );
        return $checks;
    }

    $last_verified = !empty($state['last_verified_at']) ? human_time_diff((int) $state['last_verified_at'], time()) . ' desde la ultima verificacion' : 'sin verificacion registrada';
    $checks[] = seo_server_status_make_check(
        'SEC-CF-CONNECTION', 'Seguridad', 'Conexion Cloudflare', 'Configurada', 'info',
        'Zona: ' . ($state['zone_name'] !== '' ? $state['zone_name'] : 'no identificada') . '; ' . $last_verified . '. El token nunca se incluye en este informe.'
    );

    if (!$deep) {
        return $checks;
    }

    $audit = seo_cloudflare_security_audit(true);
    if (!is_array($audit) || !empty($audit['error'])) {
        $checks[] = seo_server_status_make_check(
            'SEC-CF-AUDIT', 'Seguridad', 'Auditoria Cloudflare', 'No concluyente', 'info',
            !empty($audit['error']) ? (string) $audit['error'] : 'No se obtuvo un snapshot interpretable de Cloudflare.'
        );
        return $checks;
    }

    $zone = is_array($audit['zone'] ?? null) ? $audit['zone'] : array();
    $zone_status = sanitize_key((string) ($zone['status'] ?? ''));
    $checks[] = seo_server_status_make_check(
        'SEC-CF-ZONE', 'Seguridad', 'Zona Cloudflare',
        ($zone['name'] ?? $state['zone_name']) . ($zone_status !== '' ? ' (' . $zone_status . ')' : ''),
        ($zone_status === '' || $zone_status === 'active') ? 'info' : 'warning',
        $zone_status === 'active' ? 'La zona esta activa en Cloudflare.' : 'Estado de zona distinto de active; revisa la configuracion en Cloudflare.'
    );

    $settings = is_array($audit['settings'] ?? null) ? $audit['settings'] : array();
    $setting = static function ($id) use ($settings) {
        return is_array($settings[$id] ?? null) ? $settings[$id] : array('available' => false, 'error' => 'Sin dato');
    };
    $add_unavailable = static function (&$checks, $code, $label, $row) {
        $checks[] = seo_server_status_make_check(
            $code, 'Seguridad', $label, 'No evaluable', 'info',
            !empty($row['error']) ? (string) $row['error'] : 'El token o el plan no permiten consultar este ajuste.'
        );
    };

    $ssl = $setting('ssl');
    if (empty($ssl['available'])) {
        $add_unavailable($checks, 'SEC-CF-SSL', 'Cloudflare SSL/TLS', $ssl);
    } else {
        $value = strtolower((string) ($ssl['value'] ?? ''));
        $status = 'info';
        if ($value === 'strict') {
            $status = 'ok';
        } elseif ($value === 'full') {
            $status = 'warning';
        } elseif ($value === 'flexible') {
            $status = 'important';
        } elseif ($value === 'off') {
            $status = 'error';
        }
        $checks[] = seo_server_status_make_check(
            'SEC-CF-SSL', 'Seguridad', 'Cloudflare SSL/TLS', $value !== '' ? $value : 'desconocido', $status,
            $value === 'strict' ? 'Full (strict) valida tambien el certificado del servidor de origen.' : 'Se evalua el modo SSL entre visitante, Cloudflare y origen; Full (strict) es la referencia recomendada.'
        );
    }

    $https = $setting('always_use_https');
    if (empty($https['available'])) {
        $add_unavailable($checks, 'SEC-CF-ALWAYS-HTTPS', 'Cloudflare Always Use HTTPS', $https);
    } else {
        $value = strtolower((string) ($https['value'] ?? ''));
        $checks[] = seo_server_status_make_check(
            'SEC-CF-ALWAYS-HTTPS', 'Seguridad', 'Cloudflare Always Use HTTPS', $value === 'on' ? 'Activo' : 'Desactivado',
            $value === 'on' ? 'ok' : 'info',
            $value === 'on' ? 'Cloudflare redirige HTTP a HTTPS en el borde.' : 'No se penaliza: el sitio puede redirigir HTTP a HTTPS por otra capa. Se informa como defensa adicional.'
        );
    }

    $min_tls = $setting('min_tls_version');
    if (empty($min_tls['available'])) {
        $add_unavailable($checks, 'SEC-CF-MIN-TLS', 'Cloudflare TLS minimo', $min_tls);
    } else {
        $value = (string) ($min_tls['value'] ?? '');
        $status = in_array($value, array('1.2', '1.3'), true) ? 'ok' : ($value === '1.1' ? 'warning' : ($value === '1.0' ? 'important' : 'info'));
        $checks[] = seo_server_status_make_check(
            'SEC-CF-MIN-TLS', 'Seguridad', 'Cloudflare TLS minimo', $value !== '' ? $value : 'desconocido', $status,
            'TLS 1.2 o superior es una base moderna para conexiones publicas.'
        );
    }

    $tls13 = $setting('tls_1_3');
    if (empty($tls13['available'])) {
        $add_unavailable($checks, 'SEC-CF-TLS13', 'Cloudflare TLS 1.3', $tls13);
    } else {
        $value = strtolower((string) ($tls13['value'] ?? ''));
        $checks[] = seo_server_status_make_check(
            'SEC-CF-TLS13', 'Seguridad', 'Cloudflare TLS 1.3', in_array($value, array('on', 'zrt'), true) ? 'Activo' : 'Desactivado', 'info',
            'Informativo: TLS 1.3 mejora protocolo y rendimiento, pero su ausencia no implica por si sola una configuracion insegura si TLS 1.2 sigue permitido.'
        );
    }

    $browser = $setting('browser_check');
    if (empty($browser['available'])) {
        $add_unavailable($checks, 'SEC-CF-BROWSER-CHECK', 'Cloudflare Browser Integrity Check', $browser);
    } else {
        $value = strtolower((string) ($browser['value'] ?? ''));
        $checks[] = seo_server_status_make_check(
            'SEC-CF-BROWSER-CHECK', 'Seguridad', 'Cloudflare Browser Integrity Check', $value === 'on' ? 'Activo' : 'Desactivado', 'info',
            'Control complementario de Cloudflare; se informa sin modificar la puntuacion.'
        );
    }

    $security_level = $setting('security_level');
    if (empty($security_level['available'])) {
        $add_unavailable($checks, 'SEC-CF-SECURITY-LEVEL', 'Cloudflare Security Level', $security_level);
    } else {
        $value = sanitize_key((string) ($security_level['value'] ?? ''));
        $checks[] = seo_server_status_make_check(
            'SEC-CF-SECURITY-LEVEL', 'Seguridad', 'Cloudflare Security Level', $value !== '' ? $value : 'desconocido', 'info',
            'Informativo: este nivel interactua con otras reglas y no se valora de forma aislada.'
        );
    }

    $dnssec = is_array($audit['dnssec'] ?? null) ? $audit['dnssec'] : array('available' => false);
    if (empty($dnssec['available'])) {
        $add_unavailable($checks, 'SEC-CF-DNSSEC', 'Cloudflare DNSSEC', $dnssec);
    } else {
        $value = sanitize_key((string) ($dnssec['status'] ?? ''));
        $checks[] = seo_server_status_make_check(
            'SEC-CF-DNSSEC', 'Seguridad', 'Cloudflare DNSSEC', $value !== '' ? $value : 'desconocido',
            $value === 'active' ? 'ok' : 'warning',
            $value === 'active' ? 'DNSSEC esta activo para la zona.' : 'DNSSEC no figura como active; revisa la cadena de delegacion antes de activarlo o cambiarlo.'
        );
    }

    $rulesets = is_array($audit['rulesets'] ?? null) ? $audit['rulesets'] : array('available' => false);
    if (empty($rulesets['available'])) {
        $add_unavailable($checks, 'SEC-CF-WAF', 'Cloudflare WAF / rulesets', $rulesets);
    } else {
        $managed = (int) ($rulesets['managed_waf'] ?? 0);
        $custom = (int) ($rulesets['custom_firewall'] ?? 0);
        $rate = (int) ($rulesets['rate_limit'] ?? 0);
        $checks[] = seo_server_status_make_check(
            'SEC-CF-WAF', 'Seguridad', 'Cloudflare WAF / rulesets',
            'Managed: ' . $managed . '; custom: ' . $custom . '; rate limit: ' . $rate,
            'info',
            'Inventario de rulesets visibles con el token. No se interpreta como vulnerabilidad si el plan o la politica del sitio usan otra combinacion.'
        );
    }

    return $checks;
}

/**
 * Genera los chequeos especificos de seguridad.
 * Los sondeos HTTP, uploads y checksums solo se ejecutan en chequeo completo.
 */
function seo_server_status_collect_security_checks($deep = false) {
    $checks = array();

    $allow_url_include = filter_var(ini_get('allow_url_include'), FILTER_VALIDATE_BOOLEAN);
    $checks[] = seo_server_status_make_check(
        'SEC-PHP-ALLOW-URL-INCLUDE', 'Seguridad', 'allow_url_include', seo_server_status_yes_no($allow_url_include),
        $allow_url_include ? 'error' : 'ok',
        'Debe permanecer desactivado en produccion.'
    );

    $expose_php = filter_var(ini_get('expose_php'), FILTER_VALIDATE_BOOLEAN);
    $checks[] = seo_server_status_make_check(
        'SEC-PHP-EXPOSE', 'Seguridad', 'expose_php', seo_server_status_yes_no($expose_php),
        $expose_php ? 'warning' : 'ok',
        'Desactivarlo reduce informacion de fingerprinting del servidor.'
    );

    $salts = seo_server_status_security_salts_status();
    $salts_effective = (int) $salts['constants_ok'] + (int) $salts['database_fallback_ok'];
    if (!empty($salts['ok'])) {
        $salts_status = 'ok';
        $salts_detail = '8/8 definidas con valores fuertes en wp-config.php. No se muestran los secretos.';
    } elseif (!empty($salts['effective_ok'])) {
        $salts_status = 'warning';
        $salts_detail = (int) $salts['constants_ok'] . '/8 en wp-config.php y ' . (int) $salts['database_fallback_ok'] . '/8 mediante fallback privado de WordPress en base de datos. Es funcional y seguro, aunque conviene definir claves fuertes en wp-config.php para defensa adicional.';
    } else {
        $salts_status = 'important';
        $salts_detail = 'Sin material persistente detectable para: ' . implode(', ', $salts['missing']) . '. WordPress puede generarlo al necesitarlo; conviene definir claves fuertes en wp-config.php.';
    }
    $checks[] = seo_server_status_make_check(
        'SEC-WP-SALTS', 'Seguridad', 'Claves y salts de WordPress',
        $salts_effective . '/8 efectivas; ' . (int) $salts['constants_ok'] . '/8 en wp-config',
        $salts_status,
        $salts_detail
    );

    $admin_user = get_user_by('login', 'admin');
    $admin_login_risk = $admin_user && in_array('administrator', (array) $admin_user->roles, true);
    $checks[] = seo_server_status_make_check(
        'SEC-WP-ADMIN-LOGIN', 'Seguridad', 'Administrador con login admin', seo_server_status_yes_no($admin_login_risk),
        $admin_login_risk ? 'warning' : 'ok',
        'Evitar un nombre de acceso administrador predecible reduce ruido de fuerza bruta.'
    );

    $admin_ids = get_users(array('role' => 'administrator', 'fields' => 'ID', 'number' => 200));
    $checks[] = seo_server_status_make_check(
        'SEC-WP-ADMIN-COUNT', 'Seguridad', 'Usuarios administradores', (string) count($admin_ids), 'info',
        'Revisa periodicamente que todos los administradores sigan siendo necesarios.'
    );

    $registration = (bool) get_option('users_can_register');
    $checks[] = seo_server_status_make_check(
        'SEC-WP-REGISTRATION', 'Seguridad', 'Registro publico de usuarios', seo_server_status_yes_no($registration),
        $registration ? 'warning' : 'ok',
        'Si no necesitas altas publicas, conviene mantenerlo desactivado.'
    );

    $updates = seo_server_status_security_update_stats();
    $update_age = $updates['last_checked'] ? human_time_diff($updates['last_checked'], time()) . ' desde el ultimo chequeo de WordPress' : 'sin fecha de comprobacion disponible';
    $checks[] = seo_server_status_make_check(
        'SEC-UPDATES-CORE', 'Seguridad', 'Actualizacion de WordPress pendiente',
        $updates['core_newest'] !== '' ? get_bloginfo('version') . ' -> ' . $updates['core_newest'] : 'No detectada',
        $updates['core_newest'] !== '' ? 'important' : 'ok',
        'Basado en el cache de actualizaciones; ' . $update_age . '.'
    );
    $checks[] = seo_server_status_make_check(
        'SEC-UPDATES-PLUGINS', 'Seguridad', 'Plugins con actualizacion pendiente', (string) $updates['plugin_updates'],
        $updates['plugin_updates'] > 0 ? 'warning' : 'ok',
        'No implica por si solo una vulnerabilidad conocida; ' . $update_age . '.'
    );
    $checks[] = seo_server_status_make_check(
        'SEC-UPDATES-THEMES', 'Seguridad', 'Themes con actualizacion pendiente', (string) $updates['theme_updates'],
        $updates['theme_updates'] > 0 ? 'warning' : 'ok',
        'No implica por si solo una vulnerabilidad conocida; ' . $update_age . '.'
    );
    $checks[] = seo_server_status_make_check(
        'SEC-PLUGINS-INACTIVE', 'Seguridad', 'Plugins instalados inactivos', (string) $updates['plugins_inactive'],
        $updates['plugins_inactive'] > 0 ? 'warning' : 'ok',
        'Plugins inactivos siguen siendo archivos instalados y conviene retirar los que no se usan.'
    );

    $config_path = seo_server_status_security_wp_config_path();
    $config_perms = seo_server_status_security_file_permissions($config_path);
    if (!$config_path) {
        $checks[] = seo_server_status_make_check('SEC-WP-CONFIG-PERMS', 'Seguridad', 'Permisos wp-config.php', 'No localizado', 'info', 'El hosting puede usar una ubicacion no visible desde este proceso.');
    } elseif (!$config_perms['available']) {
        $checks[] = seo_server_status_make_check('SEC-WP-CONFIG-PERMS', 'Seguridad', 'Permisos wp-config.php', 'No legibles', 'info', 'No se pudieron leer los permisos POSIX.');
    } else {
        $perm_status = $config_perms['world_writable'] ? 'error' : ($config_perms['group_writable'] ? 'warning' : 'ok');
        $checks[] = seo_server_status_make_check('SEC-WP-CONFIG-PERMS', 'Seguridad', 'Permisos wp-config.php', $config_perms['mode'], $perm_status, 'Nunca debe ser escribible por cualquier usuario del sistema.');
    }

    $php_error_log = trim((string) ini_get('error_log'));
    $doc_root = !empty($_SERVER['DOCUMENT_ROOT']) ? wp_normalize_path((string) $_SERVER['DOCUMENT_ROOT']) : '';
    $php_log_public = false;
    if ($php_error_log !== '' && $doc_root !== '' && strpos(wp_normalize_path($php_error_log), trailingslashit(untrailingslashit($doc_root))) === 0) {
        $php_log_public = true;
    }
    $checks[] = seo_server_status_make_check(
        'SEC-PHP-ERROR-LOG-PATH', 'Seguridad', 'PHP error_log dentro del document root', seo_server_status_yes_no($php_log_public),
        $php_log_public ? 'important' : 'ok',
        $php_error_log === '' ? 'PHP no expone una ruta de error_log.' : 'Solo se evalua la ubicacion, no el contenido del log.'
    );

    $monitor_secret = (string) get_option(seo_server_status_monitor_secret_option_name(), '');
    $checks[] = seo_server_status_make_check(
        'SEC-MONITOR-HMAC', 'Seguridad', 'Monitor externo protegido con HMAC', strlen($monitor_secret) >= 32 ? 'Si' : 'No configurado',
        strlen($monitor_secret) >= 32 ? 'ok' : 'info',
        'La ruta REST del monitor solo acepta payloads con firma valida y ventana temporal.'
    );

    $checks = array_merge($checks, seo_server_status_collect_cloudflare_security_checks($deep));

    if (!$deep) {
        return $checks;
    }

    $sensitive_paths = array(
        'wp-content/debug.log',
        'wp-content/seo-menu-debug.log',
        'wp-content/cat_image_log.txt',
        '.env',
        'wp-config.php.bak',
        'wp-config.php.old',
        'wp-config.php~',
    );
    $exposed = array();
    $unknown = array();
    foreach ($sensitive_paths as $relative_path) {
        $probe = seo_server_status_security_probe_sensitive_path($relative_path);
        if (!empty($probe['exposed'])) {
            $exposed[] = $relative_path . ' (HTTP ' . (int) $probe['code'] . ')';
        } elseif (empty($probe['conclusive'])) {
            $unknown[] = $relative_path;
        }
    }
    $sensitive_status = !empty($exposed) ? 'error' : (!empty($unknown) ? 'warning' : 'ok');
    $sensitive_value = !empty($exposed) ? implode(', ', $exposed) : 'Ninguno expuesto';
    $sensitive_detail = !empty($unknown) ? 'No concluyentes: ' . implode(', ', $unknown) : 'Se probaron rutas sensibles con descarga limitada.';
    $checks[] = seo_server_status_make_check('SEC-PUBLIC-SENSITIVE', 'Seguridad', 'Archivos sensibles accesibles por HTTP', $sensitive_value, $sensitive_status, $sensitive_detail);

    $upload_dir = wp_get_upload_dir();
    $uploads_url = isset($upload_dir['baseurl']) ? trailingslashit((string) $upload_dir['baseurl']) : '';
    if ($uploads_url !== '') {
        $listing = seo_server_status_security_probe_directory_listing($uploads_url);
        $listing_status = empty($listing['conclusive']) ? 'info' : (!empty($listing['listed']) ? 'important' : 'ok');
        $checks[] = seo_server_status_make_check('SEC-PUBLIC-DIR-LISTING', 'Seguridad', 'Listado de directorios en uploads', !empty($listing['listed']) ? 'Expuesto' : 'No detectado', $listing_status, empty($listing['conclusive']) ? 'La sonda HTTP no fue concluyente.' : 'Busca una respuesta real tipo Index of.');
    }

    $surface = seo_server_status_security_public_surface();
    if (empty($surface['ok'])) {
        $checks[] = seo_server_status_make_check('SEC-PUBLIC-HOME', 'Seguridad', 'Sonda publica de portada', 'No concluyente', 'info', !empty($surface['error']) ? $surface['error'] : 'HTTP ' . (int) ($surface['code'] ?? 0));
    } else {
        $https_home = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_SCHEME)) === 'https';
        $checks[] = seo_server_status_make_check('SEC-PUBLIC-HSTS', 'Seguridad', 'HSTS', $surface['hsts'] !== '' ? 'Presente' : 'Ausente', ($https_home && $surface['hsts'] === '') ? 'warning' : 'ok', 'Strict-Transport-Security protege contra downgrade cuando todo el sitio usa HTTPS.');
        $checks[] = seo_server_status_make_check('SEC-PUBLIC-NOSNIFF', 'Seguridad', 'X-Content-Type-Options', $surface['nosniff'] !== '' ? $surface['nosniff'] : 'Ausente', strtolower($surface['nosniff']) === 'nosniff' ? 'ok' : 'warning', 'Reduce interpretacion MIME inesperada en navegadores.');
        $frame_protected = $surface['x_frame_options'] !== '' || stripos($surface['csp'], 'frame-ancestors') !== false;
        $checks[] = seo_server_status_make_check('SEC-PUBLIC-FRAME', 'Seguridad', 'Proteccion frente a clickjacking', $frame_protected ? 'Presente' : 'Ausente', $frame_protected ? 'ok' : 'warning', 'Se acepta X-Frame-Options o CSP frame-ancestors.');
        $checks[] = seo_server_status_make_check('SEC-PUBLIC-REFERRER', 'Seguridad', 'Referrer-Policy', $surface['referrer_policy'] !== '' ? $surface['referrer_policy'] : 'Ausente', $surface['referrer_policy'] !== '' ? 'ok' : 'info', 'Cabecera recomendable para controlar informacion enviada en Referer.');
        $checks[] = seo_server_status_make_check('SEC-PUBLIC-CSP', 'Seguridad', 'Content-Security-Policy', $surface['csp'] !== '' ? 'Presente' : 'Ausente', $surface['csp'] !== '' ? 'ok' : 'info', 'CSP es una defensa adicional; no se penaliza su ausencia porque requiere adaptacion al sitio.');
        $checks[] = seo_server_status_make_check('SEC-PUBLIC-X-POWERED-BY', 'Seguridad', 'X-Powered-By publico', $surface['x_powered_by'] !== '' ? $surface['x_powered_by'] : 'No expuesto', $surface['x_powered_by'] !== '' ? 'warning' : 'ok', 'Ocultarlo reduce fingerprinting de la tecnologia del servidor.');

        $plugin_fp = count($surface['plugins']);
        $plugin_ver_fp = count($surface['plugin_versions']);
        $plugin_value = $plugin_fp . ' plugins visibles; ' . $plugin_ver_fp . ' con version en assets';
        $plugin_detail = $plugin_fp > 0 ? 'Slugs visibles: ' . implode(', ', array_slice($surface['plugins'], 0, 12)) : 'No se detectaron slugs de plugins en la muestra de portada.';
        $checks[] = seo_server_status_make_check('SEC-PUBLIC-PLUGIN-FINGERPRINT', 'Seguridad', 'Fingerprint publico de plugins', $plugin_value, 'info', $plugin_detail);

        $theme_value = count($surface['themes']) > 0 ? implode(', ', array_slice($surface['themes'], 0, 6)) : 'No detectado';
        $checks[] = seo_server_status_make_check('SEC-PUBLIC-THEME-FINGERPRINT', 'Seguridad', 'Fingerprint publico de theme', $theme_value, 'info', 'Informativo: permite saber que tecnologia es visible sin autenticacion.');

        $core_values = $surface['core_versions'];
        if ($surface['generator'] !== '') {
            $core_values[] = $surface['generator'];
        }
        $core_values = array_values(array_unique(array_filter($core_values)));
        $checks[] = seo_server_status_make_check('SEC-PUBLIC-CORE-FINGERPRINT', 'Seguridad', 'Version WordPress visible publicamente', !empty($core_values) ? implode(', ', array_slice($core_values, 0, 6)) : 'No detectada', 'info', 'La visibilidad de version es fingerprinting; no significa por si sola una vulnerabilidad.');
    }

    $rest_users = seo_server_status_security_probe_rest_users();
    $rest_status = empty($rest_users['conclusive']) ? 'info' : (!empty($rest_users['exposed']) ? 'warning' : 'ok');
    $checks[] = seo_server_status_make_check('SEC-REST-USERS', 'Seguridad', 'Enumeracion REST de usuarios', !empty($rest_users['exposed']) ? 'Visible' : 'No detectada', $rest_status, empty($rest_users['conclusive']) ? 'La sonda REST no fue concluyente.' : 'Puede exponer slugs de autores; no revela contrasenas.');

    $xmlrpc = seo_server_status_security_probe_xmlrpc();
    $xmlrpc_status = empty($xmlrpc['conclusive']) ? 'info' : (!empty($xmlrpc['enabled']) ? 'warning' : 'ok');
    $checks[] = seo_server_status_make_check('SEC-XMLRPC-PUBLIC', 'Seguridad', 'XML-RPC accesible publicamente', !empty($xmlrpc['enabled']) ? 'Si' : 'No detectado', $xmlrpc_status, empty($xmlrpc['conclusive']) ? 'La sonda no fue concluyente.' : 'Si no utilizas XML-RPC, reducir esta superficie es recomendable.');

    $upload_php = seo_server_status_security_scan_upload_php();
    if (empty($upload_php['available'])) {
        $checks[] = seo_server_status_make_check('SEC-UPLOAD-PHP', 'Seguridad', 'PHP dentro de uploads', 'No comprobable', 'info', 'No se pudo recorrer el directorio de uploads.');
    } else {
        $upload_php_count = count($upload_php['hits']);
        $only_small_indexes = $upload_php_count > 0;
        $upload_dir_check = wp_get_upload_dir();
        $upload_base_check = isset($upload_dir_check['basedir']) ? (string) $upload_dir_check['basedir'] : '';
        foreach ($upload_php['hits'] as $relative_php) {
            $candidate = $upload_base_check ? trailingslashit($upload_base_check) . ltrim($relative_php, "/\\") : '';
            if (strtolower(basename($relative_php)) !== 'index.php' || !$candidate || !is_file($candidate) || filesize($candidate) > 2048) {
                $only_small_indexes = false;
                break;
            }
        }

        $upload_php_detail = $upload_php_count > 0 ? 'Muestras: ' . implode(', ', array_slice($upload_php['hits'], 0, 8)) : 'No se detectaron archivos PHP en la muestra recorrida.';
        if ($only_small_indexes) {
            $upload_php_status = 'info';
            $upload_php_detail .= ' Todos los detectados son index.php pequenos (<=2 KB), patron habitual de archivos de proteccion. Conviene revisar su contenido si aparecen otros PHP.';
        } else {
            $upload_php_status = $upload_php_count > 0 ? 'important' : 'ok';
        }
        if (!empty($upload_php['limited'])) {
            $upload_php_detail .= ' El recorrido alcanzo el limite configurado.';
        }
        $checks[] = seo_server_status_make_check('SEC-UPLOAD-PHP', 'Seguridad', 'PHP dentro de uploads', (string) $upload_php_count, $upload_php_status, $upload_php_detail);
    }

    $core_integrity = seo_server_status_security_core_checksums();
    if (empty($core_integrity['available'])) {
        $checks[] = seo_server_status_make_check('SEC-CORE-CHECKSUMS', 'Seguridad', 'Integridad del core WordPress', 'No comprobable', 'info', $core_integrity['error']);
    } else {
        $mismatch_count = count($core_integrity['mismatch']);
        $missing_count = count($core_integrity['missing']);
        $core_status = ($mismatch_count > 0 || $missing_count > 0) ? 'error' : 'ok';
        $core_detail = 'Solo core real (se excluye wp-content). Locale de paquete: ' . ($core_integrity['locale'] !== '' ? $core_integrity['locale'] : 'no disponible') . '. Checksums alterados: ' . $mismatch_count . '; archivos core ausentes: ' . $missing_count . '.';
        if ($mismatch_count > 0) {
            $core_detail .= ' Alterados: ' . implode(', ', array_slice($core_integrity['mismatch'], 0, 8)) . '.';
        }
        if ($missing_count > 0) {
            $core_detail .= ' Ausentes: ' . implode(', ', array_slice($core_integrity['missing'], 0, 8)) . '.';
        }
        $checks[] = seo_server_status_make_check('SEC-CORE-CHECKSUMS', 'Seguridad', 'Integridad del core WordPress', $mismatch_count . ' alterados / ' . $missing_count . ' ausentes', $core_status, $core_detail);
    }

    return $checks;
}

/**
 * Clasifica un chequeo de seguridad para presentarlo en la pestaña.
 */
function seo_server_status_security_group($code) {
    $code = (string) $code;
    if (strpos($code, 'SEC-CF-') === 0) {
        return 'Cloudflare / seguridad perimetral';
    }
    if (strpos($code, 'SEC-UPDATES-') === 0 || $code === 'SEC-PLUGINS-INACTIVE') {
        return 'Actualizaciones y superficie instalada';
    }
    if (strpos($code, 'SEC-PUBLIC-') === 0 || strpos($code, 'SEC-REST-') === 0 || strpos($code, 'SEC-XMLRPC-') === 0) {
        return 'Superficie publica';
    }
    if (strpos($code, 'SEC-CORE-') === 0 || strpos($code, 'SEC-UPLOAD-') === 0 || strpos($code, 'SEC-WP-CONFIG-') === 0 || strpos($code, 'SEC-LOG-') === 0) {
        return 'Integridad y archivos';
    }
    return 'Configuracion y endurecimiento';
}

/**
 * Renderiza la pestaña Seguridad a partir del snapshot actual.
 */
function seo_server_status_render_security_tab() {
    $snapshot = seo_server_status_get_current_snapshot();
    $checks = array_values(array_filter((array) ($snapshot['checks'] ?? array()), static function ($check) {
        return ($check['category'] ?? '') === 'Seguridad';
    }));

    echo '<h2>Seguridad</h2>';
    echo '<p>Chequeos de endurecimiento local, actualizaciones, integridad de archivos y superficie publica. Todo es de solo lectura.</p>';

    if (empty($checks)) {
        echo '<div class="seo-status-note"><strong>Sin datos de seguridad:</strong> ejecuta el chequeo completo para generar la nueva seccion.</div>';
        return;
    }

    $health = seo_server_status_health_summary($checks);
    echo '<div class="seo-server-health-strip">';
    echo '<div class="seo-server-health-box seo-server-health-' . esc_attr($health['status']) . '"><strong>Salud de seguridad</strong><div class="seo-server-health-value">' . esc_html($health['score']) . '%</div><span class="seo-muted">' . esc_html(count($checks)) . ' chequeos</span></div>';
    foreach (array('error' => 'Criticos', 'important' => 'Importantes', 'warning' => 'Avisos', 'ok' => 'Correctos') as $key => $label) {
        echo '<div class="seo-server-health-box seo-server-health-' . esc_attr($key) . '"><strong>' . esc_html($label) . '</strong><div class="seo-server-health-value">' . esc_html(number_format_i18n($health[$key] ?? 0)) . '</div></div>';
    }
    echo '</div>';

    if (empty($snapshot['deep'])) {
        echo '<div class="seo-status-note"><strong>Vista rapida:</strong> los sondeos HTTP publicos, checksums del core y recorrido de uploads solo se ejecutan con <em>Ejecutar chequeo completo</em>.</div>';
    }

    $order = array('error' => 0, 'important' => 1, 'warning' => 2, 'ok' => 3, 'info' => 4);
    usort($checks, static function ($a, $b) use ($order) {
        return ($order[$a['status'] ?? 'info'] ?? 9) <=> ($order[$b['status'] ?? 'info'] ?? 9);
    });

    $groups = array();
    foreach ($checks as $check) {
        $group = seo_server_status_security_group($check['code'] ?? '');
        if (!isset($groups[$group])) {
            $groups[$group] = array();
        }
        $groups[$group][] = $check;
    }

    foreach ($groups as $group => $rows) {
        echo '<div class="seo-status-card">';
        echo '<h2>' . esc_html($group) . '</h2>';
        echo '<table class="seo-status-table"><thead><tr><th>Chequeo</th><th>Valor</th><th>Estado</th><th>Observacion</th></tr></thead><tbody>';
        foreach ($rows as $check) {
            echo '<tr>';
            echo '<td><strong>' . esc_html($check['label'] ?? '') . '</strong><br><code>' . esc_html($check['code'] ?? '') . '</code></td>';
            echo '<td>' . esc_html($check['value'] ?? '') . '</td>';
            echo '<td>' . seo_server_status_badge($check['status'] ?? 'info') . '</td>';
            echo '<td class="seo-muted">' . esc_html($check['detail'] ?? '') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    $cf_scope = 'Cloudflare no esta conectado o no esta activo; cuando se configura se consulta en modo de solo lectura.';
    if (seo_server_status_load_cloudflare_module()) {
        $cf_state = seo_cloudflare_connection_state();
        if (!empty($cf_state['configured']) && !empty($cf_state['enabled'])) {
            $cf_scope = 'Cloudflare esta integrado en modo de solo lectura para zona, SSL/TLS, DNSSEC y controles perimetrales disponibles para el token.';
        }
    }
    echo '<div class="seo-status-note"><strong>Alcance actual:</strong> esta version no consulta una base externa de CVE. ' . esc_html($cf_scope) . ' Las actualizaciones pendientes se siguen marcando como higiene y no como vulnerabilidad conocida salvo que exista una fuente externa que lo confirme.</div>';
}

function seo_server_status_collect_snapshot($deep = false) {
    global $wpdb;
    $checks = array();

    $php_version = phpversion();
    $checks[] = seo_server_status_make_check('SRV-PHP-VERSION', 'PHP', 'Versión PHP', $php_version, version_compare($php_version, '8.0', '>=') ? 'ok' : 'important', 'Se espera PHP 8.0 o superior para este entorno.');

    $memory_limit = ini_get('memory_limit');
    $memory_bytes = seo_server_status_ini_to_bytes($memory_limit);
    $memory_status = $memory_bytes === PHP_INT_MAX || $memory_bytes >= 268435456 ? 'ok' : ($memory_bytes >= 134217728 ? 'warning' : 'important');
    $checks[] = seo_server_status_make_check('SRV-PHP-MEMORY', 'PHP', 'memory_limit', (string) $memory_limit, $memory_status, 'Objetivo operativo: 256 MB o más.');

    $execution = (int) ini_get('max_execution_time');
    $execution_status = $execution === 0 || $execution >= 60 ? 'ok' : ($execution >= 30 ? 'warning' : 'important');
    $checks[] = seo_server_status_make_check('SRV-PHP-EXECUTION', 'PHP', 'max_execution_time', (string) $execution, $execution_status, '0 significa sin límite impuesto por PHP.');

    foreach (array('curl', 'json', 'openssl', 'xml', 'mbstring') as $extension) {
        $loaded = extension_loaded($extension);
        $checks[] = seo_server_status_make_check('SRV-PHP-EXT-' . strtoupper($extension), 'PHP', 'Extensión ' . $extension, seo_server_status_yes_no($loaded), $loaded ? 'ok' : 'important', 'Extensión necesaria o muy recomendable para WordPress, WooCommerce e integraciones.');
    }

    $display_errors = filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN);
    $checks[] = seo_server_status_make_check('SRV-PHP-DISPLAY-ERRORS', 'Seguridad', 'display_errors', seo_server_status_yes_no($display_errors), $display_errors ? 'warning' : 'ok', 'En producción no conviene mostrar errores al visitante.');
    $opcache = function_exists('opcache_get_status') && @opcache_get_status(false);
    $checks[] = seo_server_status_make_check('SRV-PHP-OPCACHE', 'PHP', 'OPcache', seo_server_status_yes_no((bool) $opcache), $opcache ? 'ok' : 'warning', 'Su ausencia no rompe el sitio, pero reduce el rendimiento PHP.');

    $mysql_version = seo_server_status_get_mysql_version();
    $checks[] = seo_server_status_make_check('SRV-DB-CONNECTION', 'Base de datos', 'Conexión MySQL/MariaDB', $mysql_version ?: 'No disponible', $mysql_version ? 'ok' : 'error', 'Versión devuelta por SELECT VERSION().');
    $charset = $wpdb->get_var("SELECT @@character_set_database");
    $collation = $wpdb->get_var("SELECT @@collation_database");
    $checks[] = seo_server_status_make_check('SRV-DB-CHARSET', 'Base de datos', 'Juego de caracteres', (string) $charset, $charset === 'utf8mb4' ? 'ok' : 'warning', 'utf8mb4 evita limitaciones con Unicode.');
    $checks[] = seo_server_status_make_check('SRV-DB-COLLATION', 'Base de datos', 'Collation', (string) $collation, strpos((string) $collation, 'utf8mb4') !== false ? 'ok' : 'warning', 'Se recomienda una collation utf8mb4 coherente.');

    $options = seo_server_status_get_options_stats();
    $autoload_status = $options['autoload_size'] > 20971520 ? 'important' : ($options['autoload_size'] > 10485760 ? 'warning' : 'ok');
    $checks[] = seo_server_status_make_check('SRV-WP-AUTOLOAD', 'WordPress', 'Tamaño autoload', seo_server_status_format_bytes($options['autoload_size']), $autoload_status, number_format_i18n($options['autoload_count']) . ' opciones cargadas automáticamente.');

    $cron = seo_server_status_get_cron_stats();
    $cron_status = $cron['overdue'] > 20 ? 'important' : ($cron['overdue'] > 0 ? 'warning' : 'ok');
    $checks[] = seo_server_status_make_check('SRV-WP-CRON', 'WordPress', 'Eventos WP-Cron atrasados', (string) $cron['overdue'], $cron_status, 'Eventos con más de 15 minutos de retraso.');
    $debug = defined('WP_DEBUG') && WP_DEBUG;
    $checks[] = seo_server_status_make_check('SRV-WP-DEBUG', 'Seguridad', 'WP_DEBUG', seo_server_status_yes_no($debug), $debug ? 'warning' : 'ok', 'En producción debería permanecer controlado.');
    $file_edit = defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT;
    $checks[] = seo_server_status_make_check('SRV-WP-FILE-EDIT', 'Seguridad', 'Editor de archivos desactivado', seo_server_status_yes_no($file_edit), $file_edit ? 'ok' : 'warning', 'Medida de endurecimiento recomendada.');
    $checks[] = seo_server_status_make_check('SRV-WP-HTTPS', 'Seguridad', 'HTTPS activo', seo_server_status_yes_no(is_ssl()), is_ssl() ? 'ok' : 'error', 'El panel se está sirviendo bajo HTTPS.');

    foreach (seo_server_status_collect_security_checks($deep) as $security_check) {
        $checks[] = $security_check;
    }

    $object_cache = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
    $checks[] = seo_server_status_make_check('SRV-WP-OBJECT-CACHE', 'Rendimiento', 'Caché de objetos externa', seo_server_status_yes_no($object_cache), $object_cache ? 'ok' : 'info', 'Es una mejora opcional, no un requisito funcional.');

    $wc_active = class_exists('WooCommerce');
    $checks[] = seo_server_status_make_check('SRV-WC-ACTIVE', 'WooCommerce', 'WooCommerce activo', seo_server_status_yes_no($wc_active), $wc_active ? 'ok' : 'important', 'SEO System espera WooCommerce en esta instalación.');
    $pending = seo_server_status_get_action_scheduler_count('pending');
    $failed = seo_server_status_get_action_scheduler_count('failed');
    $pending_status = $pending > 5000 ? 'important' : ($pending > 1000 ? 'warning' : 'ok');
    $failed_status = $failed > 100 ? 'important' : ($failed > 0 ? 'warning' : 'ok');
    $checks[] = seo_server_status_make_check('SRV-WC-ACTIONS-PENDING', 'WooCommerce', 'Action Scheduler pendientes', (string) $pending, $pending_status, 'Una cola grande puede indicar procesos atrasados.');
    $checks[] = seo_server_status_make_check('SRV-WC-ACTIONS-FAILED', 'WooCommerce', 'Action Scheduler fallidas', (string) $failed, $failed_status, 'Conviene investigar cualquier crecimiento sostenido.');

    $disk_free = function_exists('disk_free_space') ? @disk_free_space(ABSPATH) : false;
    $disk_total = function_exists('disk_total_space') ? @disk_total_space(ABSPATH) : false;
    if ($disk_free !== false && $disk_total !== false && $disk_total > 0) {
        $used = round((($disk_total - $disk_free) / $disk_total) * 100, 2);
        $disk_status = $used >= 95 ? 'error' : ($used >= 90 ? 'important' : ($used >= 80 ? 'warning' : 'ok'));
        $checks[] = seo_server_status_make_check('SRV-DISK-USAGE', 'Almacenamiento', 'Uso de disco', $used . '%', $disk_status, 'Libre: ' . seo_server_status_format_bytes($disk_free));
    } else {
        $checks[] = seo_server_status_make_check('SRV-DISK-USAGE', 'Almacenamiento', 'Uso de disco', 'No disponible', 'info', 'El hosting no permite leer el espacio global.');
    }

    if ($deep) {
        $db_size = $wpdb->get_var($wpdb->prepare('SELECT SUM(DATA_LENGTH + INDEX_LENGTH) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s', DB_NAME));
        $checks[] = seo_server_status_make_check('SRV-DB-SIZE', 'Base de datos', 'Tamaño base de datos', seo_server_status_format_bytes((float) $db_size), 'info', 'Dato agregado; no contiene filas ni contenido de tablas.');
        $tables = seo_server_status_get_largest_tables(5);
        foreach ((array) $tables as $table) {
            $total = (float) $table->data_length + (float) $table->index_length;
            $status = $total > 1073741824 ? 'warning' : 'info';
            $checks[] = seo_server_status_make_check('SRV-DB-TABLE-' . strtoupper(substr(sha1($table->table_name), 0, 8)), 'Base de datos', 'Tabla grande', (string) $table->table_name . ' · ' . seo_server_status_format_bytes($total), $status, 'Solo se registra nombre técnico y tamaño agregado.');
        }
    }

    return array(
        'schema_version' => 4,
        'generated_at'   => time(),
        'deep'           => (bool) $deep,
        'checks'         => $checks,
        'health'         => seo_server_status_health_summary($checks),
    );
}

function seo_server_status_health_summary($checks) {
    $counts = array('error' => 0, 'important' => 0, 'warning' => 0, 'ok' => 0, 'info' => 0);
    foreach ((array) $checks as $check) {
        $status = isset($check['status']) && isset($counts[$check['status']]) ? $check['status'] : 'info';
        $counts[$status]++;
    }
    $total = $counts['error'] + $counts['important'] + $counts['warning'] + $counts['ok'];
    $penalty = ($counts['error'] * 5) + ($counts['important'] * 3) + $counts['warning'];
    $score = $total > 0 ? max(0, (int) round(100 - (($penalty / ($total * 5)) * 100))) : 0;
    $status = $counts['error'] > 0 ? 'error' : ($counts['important'] > 0 ? 'important' : ($counts['warning'] > 0 ? 'warning' : ($counts['ok'] > 0 ? 'ok' : 'info')));
    return array_merge($counts, array('total' => $total, 'score' => $score, 'status' => $status));
}

function seo_server_status_check_ids($snapshot) {
    $ids = array();
    foreach ((array) ($snapshot['checks'] ?? array()) as $check) {
        if (in_array($check['status'] ?? 'info', array('error', 'important', 'warning'), true)) {
            $ids[] = ($check['code'] ?? '') . '|' . ($check['status'] ?? '');
        }
    }
    return array_values(array_unique($ids));
}

function seo_server_status_store_snapshot($snapshot) {
    if (!is_array($snapshot) || empty($snapshot['checks'])) {
        return false;
    }
    $old = seo_server_status_load_snapshot();
    $old_ids = seo_server_status_check_ids($old);
    $new_ids = seo_server_status_check_ids($snapshot);
    $snapshot['trend'] = array(
        'new'       => count(array_diff($new_ids, $old_ids)),
        'resolved'  => count(array_diff($old_ids, $new_ids)),
        'changed_at'=> time(),
    );
    $history = isset($old['history']) && is_array($old['history']) ? $old['history'] : array();
    $health = $snapshot['health'];
    $history[] = array(
        'generated_at' => $snapshot['generated_at'],
        'score' => $health['score'],
        'status' => $health['status'],
        'error' => $health['error'],
        'important' => $health['important'],
        'warning' => $health['warning'],
    );
    $snapshot['history'] = array_slice($history, -20);
    return update_option(seo_server_status_snapshot_option_name(), $snapshot, false);
}

function seo_server_status_load_snapshot() {
    $snapshot = get_option(seo_server_status_snapshot_option_name(), array());
    return is_array($snapshot) ? $snapshot : array();
}

function seo_server_status_get_current_snapshot() {
    if (!empty($GLOBALS['seo_server_status_current_snapshot']) && is_array($GLOBALS['seo_server_status_current_snapshot'])) {
        return $GLOBALS['seo_server_status_current_snapshot'];
    }
    $snapshot = seo_server_status_load_snapshot();
    if (empty($snapshot['checks'])) {
        $snapshot = seo_server_status_collect_snapshot(false);
    }
    return $snapshot;
}

function seo_server_status_status_label($status) {
    $labels = array('error' => 'Crítico', 'important' => 'Importante', 'warning' => 'Avisos', 'ok' => 'Correcto', 'info' => 'Informativo');
    return $labels[$status] ?? 'Informativo';
}

function seo_server_status_render_compact_health($snapshot) {
    $health = isset($snapshot['health']) && is_array($snapshot['health']) ? $snapshot['health'] : seo_server_status_health_summary($snapshot['checks'] ?? array());
    $trend = isset($snapshot['trend']) && is_array($snapshot['trend']) ? $snapshot['trend'] : array('new' => 0, 'resolved' => 0);
    $trend_text = ((int) ($trend['new'] ?? 0) > 0 ? '+' . (int) $trend['new'] . ' nuevas' : 'sin nuevas') . ' / ' . ((int) ($trend['resolved'] ?? 0) > 0 ? (int) $trend['resolved'] . ' resueltas' : 'sin resueltas');
    echo '<div class="seo-server-health-strip">';
    echo '<div class="seo-server-health-box seo-server-health-' . esc_attr($health['status']) . '"><strong>Salud del servidor</strong><div class="seo-server-health-value">' . esc_html(seo_server_status_status_label($health['status'])) . ' · ' . esc_html($health['score']) . '%</div><span class="seo-muted">' . esc_html($trend_text) . '</span></div>';
    foreach (array('error' => 'Mal', 'important' => 'Revisar pronto', 'warning' => 'Avisos', 'ok' => 'Bien') as $key => $label) {
        echo '<div class="seo-server-health-box seo-server-health-' . esc_attr($key) . '"><strong>' . esc_html($label) . '</strong><div class="seo-server-health-value">' . esc_html(number_format_i18n($health[$key] ?? 0)) . '</div></div>';
    }
    echo '</div>';
}

function seo_server_status_render_priority_issues($snapshot, $limit = 12) {
    $checks = array_values(array_filter((array) ($snapshot['checks'] ?? array()), static function ($check) {
        return in_array($check['status'] ?? 'info', array('error', 'important', 'warning'), true);
    }));
    $order = array('error' => 0, 'important' => 1, 'warning' => 2);
    usort($checks, static function ($a, $b) use ($order) {
        return ($order[$a['status']] ?? 9) <=> ($order[$b['status']] ?? 9);
    });
    $checks = array_slice($checks, 0, $limit);
    if (empty($checks)) {
        echo '<div class="seo-status-note"><strong>Sin incidencias prioritarias:</strong> el chequeo actual no contiene errores críticos, importantes ni avisos.</div>';
        return;
    }
    echo '<h3>Qué requiere atención</h3><div class="seo-server-priority-list">';
    foreach ($checks as $check) {
        echo '<div class="seo-server-priority-item">';
        echo seo_server_status_badge($check['status']);
        echo '<div><strong>' . esc_html($check['label']) . '</strong><br><code>' . esc_html($check['code']) . '</code></div>';
        echo '<div class="seo-muted">' . esc_html($check['value'] . ($check['detail'] !== '' ? ' · ' . $check['detail'] : '')) . '</div>';
        echo '</div>';
    }
    echo '</div>';
}

function seo_server_status_render_category_overview($snapshot) {
    $categories = array('PHP', 'Base de datos', 'WordPress', 'Seguridad', 'WooCommerce', 'Rendimiento', 'Almacenamiento', 'Logs');
    echo '<h3>Salud por área</h3><div class="seo-server-category-grid">';
    foreach ($categories as $category) {
        $rows = array_values(array_filter((array) ($snapshot['checks'] ?? array()), static function ($check) use ($category) {
            return ($check['category'] ?? '') === $category;
        }));
        if (empty($rows)) {
            continue;
        }
        $health = seo_server_status_health_summary($rows);
        echo '<div class="seo-server-category-card"><h3>' . esc_html($category) . '</h3><div class="seo-server-category-score">' . esc_html($health['score']) . '%</div>' . seo_server_status_badge($health['status']) . '<p class="seo-muted">' . esc_html($health['error']) . ' críticos · ' . esc_html($health['important']) . ' importantes · ' . esc_html($health['warning']) . ' avisos</p></div>';
    }
    echo '</div>';
}

function seo_server_status_get_reporting_snapshot($refresh = false) {
    $snapshot = $refresh ? array() : seo_server_status_load_snapshot();
    if (!empty($snapshot) && (int) ($snapshot['schema_version'] ?? 0) < 4) {
        $snapshot = array();
    }
    if (empty($snapshot['checks'])) {
        $snapshot = seo_server_status_collect_snapshot(true);
        seo_server_status_store_snapshot($snapshot);
        $snapshot = seo_server_status_load_snapshot();
    }
    return array(
        'schema_version' => 1,
        'generated_at'   => (int) ($snapshot['generated_at'] ?? 0),
        'health'         => $snapshot['health'] ?? seo_server_status_health_summary($snapshot['checks'] ?? array()),
        'trend'          => $snapshot['trend'] ?? array('new' => 0, 'resolved' => 0),
        'checks'         => array_values(array_filter((array) ($snapshot['checks'] ?? array()), static function ($check) {
            return ($check['status'] ?? 'info') !== 'ok';
        })),
    );
}

function seo_server_status_render_summary_tab() {
    echo '<h2>Resumen general</h2>';
    echo '<p>Vista rápida de los puntos más importantes del entorno. Los errores y avisos aparecen antes del detalle técnico.</p>';

    $snapshot = seo_server_status_get_current_snapshot();
    seo_server_status_render_priority_issues($snapshot, 12);
    seo_server_status_render_category_overview($snapshot);

    $php_version = phpversion();
    $memory_limit = ini_get('memory_limit');
    $memory_bytes = seo_server_status_ini_to_bytes($memory_limit);
    $mysql_version = seo_server_status_get_mysql_version();
    $wc_active = class_exists('WooCommerce');
    $debug_active = defined('WP_DEBUG') && WP_DEBUG;
    $cron_stats = seo_server_status_get_cron_stats();
    $options_stats = seo_server_status_get_options_stats();
    $failed_actions = seo_server_status_get_action_scheduler_count('failed');
    $pending_actions = seo_server_status_get_action_scheduler_count('pending');

    echo '<div class="seo-status-grid">';
    seo_server_status_summary_card('PHP', 'Versión ' . esc_html($php_version) . '<br>Memoria: ' . esc_html($memory_limit), version_compare($php_version, '8.0', '>=') && $memory_bytes >= 268435456 ? 'ok' : 'warning');
    seo_server_status_summary_card('MySQL', $mysql_version ? 'Versión ' . esc_html($mysql_version) : 'No detectado', $mysql_version ? 'ok' : 'warning');
    seo_server_status_summary_card('WordPress', 'Versión ' . esc_html(get_bloginfo('version')) . '<br>Cron atrasados: ' . esc_html(number_format_i18n($cron_stats['overdue'])), $cron_stats['overdue'] > 0 ? 'warning' : 'ok');
    seo_server_status_summary_card('WooCommerce', $wc_active ? 'Activo<br>Pendientes: ' . esc_html(number_format_i18n($pending_actions)) . '<br>Fallidas: ' . esc_html(number_format_i18n($failed_actions)) : 'No activo', $failed_actions > 0 || $pending_actions > 1000 ? 'warning' : ($wc_active ? 'ok' : 'warning'));
    seo_server_status_summary_card('Autoload', seo_server_status_format_bytes($options_stats['autoload_size']) . '<br>' . esc_html(number_format_i18n($options_stats['autoload_count'])) . ' opciones', $options_stats['autoload_size'] > 10485760 ? 'warning' : 'ok');
    $security_rows = array_values(array_filter((array) ($snapshot['checks'] ?? array()), static function ($check) { return ($check['category'] ?? '') === 'Seguridad'; }));
    $security_health = seo_server_status_health_summary($security_rows);
    seo_server_status_summary_card('Seguridad', esc_html($security_health['score']) . '%<br>' . esc_html($security_health['error']) . ' criticos · ' . esc_html($security_health['important']) . ' importantes · ' . esc_html($security_health['warning']) . ' avisos', $security_health['status']);

    $disk_free = function_exists('disk_free_space') ? @disk_free_space(ABSPATH) : false;
    $disk_total = function_exists('disk_total_space') ? @disk_total_space(ABSPATH) : false;

    if ($disk_free !== false && $disk_total !== false && $disk_total > 0) {
        $used_percent = round((($disk_total - $disk_free) / $disk_total) * 100, 2);
        seo_server_status_summary_card('Disco', 'Usado: ' . esc_html($used_percent) . '%<br>Libre: ' . esc_html(seo_server_status_format_bytes($disk_free)), $used_percent >= 90 ? 'error' : ($used_percent >= 80 ? 'warning' : 'ok'));
    } else {
        seo_server_status_summary_card('Disco', 'No disponible en este hosting', 'info');
    }

    echo '</div>';

    echo '<div class="seo-status-note"><strong>Sugerencia:</strong> esta pantalla es solo lectura. Las limpiezas reales deberían seguir en Clean Database para evitar mezclar diagnóstico con acciones destructivas.</div>';
}

/**
 * Renderiza una tarjeta del resumen.
 */
function seo_server_status_summary_card($title, $content, $status = 'info') {
    echo '<div class="seo-status-card"><h2>' . esc_html($title) . '</h2><p>' . wp_kses_post($content) . '</p>' . seo_server_status_badge($status) . '</div>';
}

/**
 * Renderiza la pestaña PHP con parámetros importantes.
 */
function seo_server_status_render_php_tab() {
    echo '<h2>PHP</h2>';
    echo '<p>Parámetros PHP relevantes para WordPress, WooCommerce, importaciones y llamadas API.</p>';
    seo_server_status_open_table();

    $php_version = phpversion();
    seo_server_status_row('Versión PHP', $php_version, version_compare($php_version, '8.0', '>=') ? 'ok' : 'warning', 'Recomendable PHP 8.0 o superior.');

    $ini_checks = array(
        'memory_limit' => array('min' => 268435456, 'help' => 'Para catálogos grandes conviene 256M o más.'),
        'max_execution_time' => array('min' => 60, 'help' => 'Para importaciones y procesos largos conviene 60 segundos o más.'),
        'max_input_vars' => array('min' => 3000, 'help' => 'Para formularios grandes conviene 3000 o más.'),
        'upload_max_filesize' => array('min' => 33554432, 'help' => 'Para subidas conviene 32M o más.'),
        'post_max_size' => array('min' => 33554432, 'help' => 'Debe ser igual o superior a upload_max_filesize.'),
    );

    foreach ($ini_checks as $key => $rule) {
        $value = ini_get($key);
        if ($value === false) {
            continue;
        }
        if (in_array($key, array('memory_limit', 'upload_max_filesize', 'post_max_size'), true)) {
            $numeric = seo_server_status_ini_to_bytes($value);
        } else {
            $numeric = is_numeric($value) ? (int) $value : seo_server_status_ini_to_bytes($value);
        }
        $unlimited_execution = $key === 'max_execution_time' && $numeric === 0;
        $status = $rule['min'] > 0 && !$unlimited_execution && $numeric < $rule['min'] ? 'warning' : 'ok';
        seo_server_status_row($key, esc_html((string) $value), $status, $rule['help']);
    }

    $extra_ini = array('default_socket_timeout', 'max_file_uploads', 'realpath_cache_size', 'realpath_cache_ttl', 'display_errors', 'log_errors', 'error_log', 'date.timezone');
    foreach ($extra_ini as $key) {
        $value = ini_get($key);
        seo_server_status_row($key, $value !== false && $value !== '' ? esc_html((string) $value) : 'No definido', 'info', 'Parámetro PHP informativo.');
    }

    $extensions = array(
        'curl' => 'Necesario para llamadas HTTP y APIs.',
        'zip' => 'Útil para importaciones, exportaciones y paquetes.',
        'mbstring' => 'Recomendable para textos UTF-8.',
        'xml' => 'Necesario para XML e integraciones.',
        'json' => 'Necesario para APIs y WordPress moderno.',
        'gd' => 'Procesado básico de imágenes.',
        'imagick' => 'Procesado avanzado de imágenes.',
        'intl' => 'Útil para internacionalización y formatos locales.',
        'openssl' => 'Necesario para conexiones seguras.',
    );

    foreach ($extensions as $extension => $help) {
        $loaded = extension_loaded($extension);
        seo_server_status_row('Extensión ' . $extension, seo_server_status_yes_no($loaded), $loaded ? 'ok' : 'warning', $help);
    }

    $opcache = function_exists('opcache_get_status') && @opcache_get_status(false);
    seo_server_status_row('OPcache', seo_server_status_yes_no((bool) $opcache), $opcache ? 'ok' : 'warning', 'Mejora mucho el rendimiento PHP en producción.');

    seo_server_status_close_table();
}

/**
 * Métricas locales que WordPress puede observar sin depender del panel del hosting.
 * La carga de CPU es una estimación basada en load average / núcleos visibles al proceso.
 */
function seo_server_status_runtime_resource_metrics() {
    $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;
    $cores = function_exists('seo_server_status_detect_cpu_cores') ? seo_server_status_detect_cpu_cores() : 0;
    $cpu_percent = null;
    if (is_array($load) && isset($load[0]) && $cores > 0) {
        $cpu_percent = max(0.0, ((float) $load[0] / max(1, $cores)) * 100.0);
    }

    $memory_limit_raw = (string) ini_get('memory_limit');
    $memory_limit = seo_server_status_ini_to_bytes($memory_limit_raw);
    $memory_current = function_exists('memory_get_usage') ? (float) memory_get_usage(true) : 0.0;
    $memory_peak = function_exists('memory_get_peak_usage') ? (float) memory_get_peak_usage(true) : $memory_current;
    $memory_bounded = $memory_limit > 0 && $memory_limit !== PHP_INT_MAX;
    $memory_current_percent = $memory_bounded ? (($memory_current / $memory_limit) * 100.0) : null;
    $memory_peak_percent = $memory_bounded ? (($memory_peak / $memory_limit) * 100.0) : null;

    $disk_free = function_exists('disk_free_space') ? @disk_free_space(ABSPATH) : false;
    $disk_total = function_exists('disk_total_space') ? @disk_total_space(ABSPATH) : false;
    $disk_used_percent = null;
    if ($disk_free !== false && $disk_total !== false && $disk_total > 0) {
        $disk_used_percent = (($disk_total - $disk_free) / $disk_total) * 100.0;
    }

    return array(
        'cpu' => array(
            'available' => $cpu_percent !== null,
            'percent'   => $cpu_percent,
            'load'      => is_array($load) ? array_values(array_slice($load, 0, 3)) : array(),
            'cores'     => (int) $cores,
        ),
        'memory' => array(
            'current_bytes'   => $memory_current,
            'peak_bytes'      => $memory_peak,
            'limit_bytes'     => $memory_limit,
            'limit_raw'       => $memory_limit_raw,
            'current_percent' => $memory_current_percent,
            'peak_percent'    => $memory_peak_percent,
        ),
        'disk' => array(
            'available'   => $disk_used_percent !== null,
            'used_percent'=> $disk_used_percent,
            'free_bytes'  => $disk_free !== false ? (float) $disk_free : 0.0,
            'total_bytes' => $disk_total !== false ? (float) $disk_total : 0.0,
        ),
    );
}

/**
 * Ejecuta una micro-batería de SELECTs de solo lectura para medir la latencia
 * de la conexión usada por WordPress. No activa SAVEQUERIES ni guarda SQL.
 */
function seo_server_status_mysql_query_benchmark() {
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    global $wpdb;

    $tests = array(
        array('key' => 'ping', 'label' => 'Respuesta DB', 'type' => 'var', 'sql' => 'SELECT 1'),
        array('key' => 'options_count', 'label' => 'Conteo wp_options', 'type' => 'var', 'sql' => "SELECT COUNT(*) FROM {$wpdb->options}"),
        array('key' => 'latest_post', 'label' => 'Último contenido', 'type' => 'var', 'sql' => "SELECT ID FROM {$wpdb->posts} ORDER BY ID DESC LIMIT 1"),
        array('key' => 'products_sample', 'label' => 'Muestra de productos', 'type' => 'var', 'sql' => "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' ORDER BY ID DESC LIMIT 1"),
        array('key' => 'table_count', 'label' => 'Inventario de tablas', 'type' => 'var', 'sql' => $wpdb->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s', DB_NAME)),
        array('key' => 'db_size', 'label' => 'Tamaño de la BBDD', 'type' => 'var', 'sql' => $wpdb->prepare('SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s', DB_NAME)),
    );

    $samples = array();
    foreach ($tests as $test) {
        $wpdb->last_error = '';
        $started = microtime(true);
        $wpdb->get_var($test['sql']);
        $elapsed_ms = max(0.0, (microtime(true) - $started) * 1000.0);
        $samples[] = array(
            'key'   => $test['key'],
            'label' => $test['label'],
            'ms'    => $elapsed_ms,
            'ok'    => $wpdb->last_error === '',
            'error' => $wpdb->last_error === '' ? '' : (string) $wpdb->last_error,
        );
    }

    $valid = array_values(array_filter($samples, static function ($row) {
        return !empty($row['ok']);
    }));
    $times = array_map(static function ($row) {
        return (float) $row['ms'];
    }, $valid);

    $total = !empty($times) ? array_sum($times) : 0.0;
    $average = !empty($times) ? $total / count($times) : 0.0;
    $fastest = null;
    $slowest = null;
    foreach ($valid as $row) {
        if ($fastest === null || $row['ms'] < $fastest['ms']) {
            $fastest = $row;
        }
        if ($slowest === null || $row['ms'] > $slowest['ms']) {
            $slowest = $row;
        }
    }

    $ping = null;
    foreach ($samples as $row) {
        if ($row['key'] === 'ping') {
            $ping = $row;
            break;
        }
    }

    $cached = array(
        'samples'      => $samples,
        'sample_count' => count($samples),
        'error_count'  => count($samples) - count($valid),
        'total_ms'     => $total,
        'average_ms'   => $average,
        'fastest'      => $fastest,
        'slowest'      => $slowest,
        'response_ms'  => is_array($ping) && !empty($ping['ok']) ? (float) $ping['ms'] : null,
    );

    return $cached;
}

function seo_server_status_mysql_metric_status($percent, $warning = 70, $important = 85, $error = 95) {
    if ($percent === null) {
        return 'info';
    }
    $percent = (float) $percent;
    if ($percent >= $error) {
        return 'error';
    }
    if ($percent >= $important) {
        return 'important';
    }
    if ($percent >= $warning) {
        return 'warning';
    }
    return 'ok';
}

function seo_server_status_render_mysql_meter_card($title, $percent, $value, $detail, $status = 'info') {
    $meter_percent = $percent === null ? 0.0 : max(0.0, min(100.0, (float) $percent));
    $class = in_array($status, array('warning', 'important', 'error'), true) ? ' is-' . $status : '';
    echo '<div class="seo-mysql-live-card">';
    echo '<strong>' . esc_html($title) . '</strong>';
    echo '<div class="seo-mysql-live-value">' . esc_html($value) . '</div>';
    echo '<div class="seo-mysql-meter" aria-hidden="true"><span class="seo-mysql-meter-fill' . esc_attr($class) . '" style="width:' . esc_attr(number_format($meter_percent, 2, '.', '')) . '%"></span></div>';
    echo '<span class="seo-muted">' . esc_html($detail) . '</span>';
    echo '</div>';
}

function seo_server_status_render_mysql_resource_charts($metrics) {
    $cpu = isset($metrics['cpu']) && is_array($metrics['cpu']) ? $metrics['cpu'] : array();
    $memory = isset($metrics['memory']) && is_array($metrics['memory']) ? $metrics['memory'] : array();
    $disk = isset($metrics['disk']) && is_array($metrics['disk']) ? $metrics['disk'] : array();

    $cpu_percent = !empty($cpu['available']) ? (float) $cpu['percent'] : null;
    $cpu_status = seo_server_status_mysql_metric_status($cpu_percent, 70, 90, 110);
    $cpu_value = $cpu_percent === null ? 'No disponible' : number_format_i18n($cpu_percent, 1) . '%';
    $load_detail = !empty($cpu['load']) ? 'Load: ' . implode(' / ', array_map(static function ($v) { return number_format_i18n((float) $v, 2); }, $cpu['load'])) : 'Load average no disponible';
    if (!empty($cpu['cores'])) {
        $load_detail .= ' · ' . (int) $cpu['cores'] . ' núcleos visibles';
    }

    $current_percent = isset($memory['current_percent']) && $memory['current_percent'] !== null ? (float) $memory['current_percent'] : null;
    $peak_percent = isset($memory['peak_percent']) && $memory['peak_percent'] !== null ? (float) $memory['peak_percent'] : null;
    $memory_status = seo_server_status_mysql_metric_status($current_percent, 70, 85, 95);
    $peak_status = seo_server_status_mysql_metric_status($peak_percent, 70, 85, 95);
    $memory_limit_text = !empty($memory['limit_raw']) ? (string) $memory['limit_raw'] : 'sin límite';

    $disk_percent = !empty($disk['available']) ? (float) $disk['used_percent'] : null;
    $disk_status = seo_server_status_mysql_metric_status($disk_percent, 80, 90, 95);

    echo '<div class="seo-status-card">';
    echo '<h2>Recursos observables desde WordPress</h2>';
    echo '<p>Gráficos instantáneos del proceso PHP y del sistema accesible. En hosting compartido la CPU se estima con <em>load average / núcleos visibles</em>; no sustituye las métricas físicas de WePanel.</p>';
    echo '<div class="seo-mysql-live-grid">';
    seo_server_status_render_mysql_meter_card('Carga CPU estimada', $cpu_percent, $cpu_value, $load_detail, $cpu_status);
    seo_server_status_render_mysql_meter_card(
        'Memoria PHP actual',
        $current_percent,
        seo_server_status_format_bytes((float) ($memory['current_bytes'] ?? 0)),
        $current_percent === null ? 'Límite PHP: ' . $memory_limit_text : number_format_i18n($current_percent, 1) . '% de ' . $memory_limit_text,
        $memory_status
    );
    seo_server_status_render_mysql_meter_card(
        'Pico de memoria PHP',
        $peak_percent,
        seo_server_status_format_bytes((float) ($memory['peak_bytes'] ?? 0)),
        $peak_percent === null ? 'Límite PHP: ' . $memory_limit_text : number_format_i18n($peak_percent, 1) . '% de ' . $memory_limit_text,
        $peak_status
    );
    seo_server_status_render_mysql_meter_card(
        'Uso de disco',
        $disk_percent,
        $disk_percent === null ? 'No disponible' : number_format_i18n($disk_percent, 1) . '%',
        $disk_percent === null ? 'El hosting no expone esta métrica.' : 'Libre: ' . seo_server_status_format_bytes((float) ($disk['free_bytes'] ?? 0)),
        $disk_status
    );
    echo '</div>';
    echo '</div>';
}

function seo_server_status_render_mysql_query_profile($profile) {
    $response_ms = isset($profile['response_ms']) && $profile['response_ms'] !== null ? (float) $profile['response_ms'] : null;
    $average_ms = (float) ($profile['average_ms'] ?? 0);
    $total_ms = (float) ($profile['total_ms'] ?? 0);
    $slowest = isset($profile['slowest']) && is_array($profile['slowest']) ? $profile['slowest'] : null;
    $samples = isset($profile['samples']) && is_array($profile['samples']) ? $profile['samples'] : array();
    $max_ms = 0.0;
    foreach ($samples as $row) {
        if (!empty($row['ok'])) {
            $max_ms = max($max_ms, (float) $row['ms']);
        }
    }

    echo '<div class="seo-status-card">';
    echo '<h2>Rendimiento de consultas MySQL</h2>';
    echo '<p>Micro-batería de SELECTs de solo lectura ejecutada con la misma conexión que usa WordPress. Mide la latencia de este momento; no activa <code>SAVEQUERIES</code>, no guarda el SQL y no modifica datos.</p>';
    echo '<div class="seo-mysql-query-kpis">';
    echo '<div class="seo-mysql-query-kpi"><strong>Tiempo de respuesta</strong><div class="seo-mysql-query-kpi-value">' . ($response_ms === null ? 'N/D' : esc_html(number_format_i18n($response_ms, 2)) . ' ms') . '</div><span class="seo-muted">SELECT 1</span></div>';
    echo '<div class="seo-mysql-query-kpi"><strong>Tiempo medio</strong><div class="seo-mysql-query-kpi-value">' . esc_html(number_format_i18n($average_ms, 2)) . ' ms</div><span class="seo-muted">' . esc_html(number_format_i18n((int) ($profile['sample_count'] ?? 0))) . ' muestras</span></div>';
    echo '<div class="seo-mysql-query-kpi"><strong>Consulta más lenta</strong><div class="seo-mysql-query-kpi-value">' . ($slowest ? esc_html(number_format_i18n((float) $slowest['ms'], 2)) . ' ms' : 'N/D') . '</div><span class="seo-muted">' . ($slowest ? esc_html((string) $slowest['label']) : 'Sin muestra válida') . '</span></div>';
    echo '<div class="seo-mysql-query-kpi"><strong>Tiempo total</strong><div class="seo-mysql-query-kpi-value">' . esc_html(number_format_i18n($total_ms, 2)) . ' ms</div><span class="seo-muted">Errores: ' . esc_html(number_format_i18n((int) ($profile['error_count'] ?? 0))) . '</span></div>';
    echo '</div>';

    if (!empty($samples)) {
        echo '<div class="seo-mysql-query-chart" role="img" aria-label="Comparación de tiempos de consultas diagnósticas MySQL">';
        foreach ($samples as $row) {
            $ok = !empty($row['ok']);
            $ms = (float) ($row['ms'] ?? 0);
            $width = $ok && $max_ms > 0 ? max(2.0, min(100.0, ($ms / $max_ms) * 100.0)) : 2.0;
            echo '<div class="seo-mysql-query-row">';
            echo '<div class="seo-mysql-query-label">' . esc_html((string) ($row['label'] ?? 'Consulta')) . '</div>';
            echo '<div class="seo-mysql-query-track"><div class="seo-mysql-query-bar" style="width:' . esc_attr(number_format($width, 2, '.', '')) . '%"></div></div>';
            echo '<div class="seo-mysql-query-time">' . ($ok ? esc_html(number_format_i18n($ms, 2)) . ' ms' : 'Error') . '</div>';
            echo '</div>';
        }
        echo '</div>';
    }

    if (!empty($profile['error_count'])) {
        echo '<div class="seo-status-note"><strong>Lectura parcial:</strong> alguna consulta diagnóstica no pudo completarse con los permisos disponibles. El resto de métricas sigue siendo válido.</div>';
    }
    echo '</div>';
}

/**
 * Renderiza la pestaña MySQL con variables útiles y tablas grandes.
 */
function seo_server_status_render_mysql_tab() {
    global $wpdb;

    echo '<h2>MySQL / MariaDB</h2>';
    echo '<p>Configuracion, estadisticas, recursos observables y rendimiento de consultas de la base de datos actual.</p>';

    $resource_metrics = seo_server_status_runtime_resource_metrics();
    $query_profile = seo_server_status_mysql_query_benchmark();
    seo_server_status_render_mysql_resource_charts($resource_metrics);
    seo_server_status_render_mysql_query_profile($query_profile);

    $db_name = DB_NAME;
    $mysql_version = seo_server_status_get_mysql_version();

    echo '<div class="seo-status-card">';
    echo '<h2>Configuracion MySQL / MariaDB</h2>';
    seo_server_status_open_table();
    seo_server_status_row('Version', $mysql_version ? esc_html($mysql_version) : 'No disponible', $mysql_version ? 'ok' : 'warning', 'Version devuelta por SELECT VERSION().');

    $variables = array('version_comment', 'character_set_database', 'collation_database', 'max_allowed_packet', 'innodb_buffer_pool_size', 'wait_timeout', 'interactive_timeout', 'sql_mode');
    foreach ($variables as $var) {
        $value = $wpdb->get_var($wpdb->prepare('SHOW VARIABLES LIKE %s', $var), 1);
        $status = 'info';
        if ($var === 'character_set_database') {
            $status = $value === 'utf8mb4' ? 'ok' : 'warning';
        }
        if ($var === 'collation_database') {
            $status = strpos((string) $value, 'utf8mb4') !== false ? 'ok' : 'warning';
        }
        if (in_array($var, array('max_allowed_packet', 'innodb_buffer_pool_size'), true) && is_numeric($value)) {
            $value = seo_server_status_format_bytes((float) $value);
        }
        seo_server_status_row($var, $value !== null && $value !== '' ? esc_html((string) $value) : 'No disponible', $status, 'Variable MySQL informativa.');
    }
    seo_server_status_close_table();
    echo '</div>';

    $aggregate = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT COUNT(*) AS table_count,
                    COALESCE(SUM(TABLE_ROWS), 0) AS estimated_rows,
                    COALESCE(SUM(DATA_LENGTH), 0) AS data_size,
                    COALESCE(SUM(INDEX_LENGTH), 0) AS index_size
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = %s",
            $db_name
        ),
        ARRAY_A
    );

    $table_count = is_array($aggregate) ? (int) ($aggregate['table_count'] ?? 0) : 0;
    $estimated_rows = is_array($aggregate) ? (float) ($aggregate['estimated_rows'] ?? 0) : 0;
    $data_size = is_array($aggregate) ? (float) ($aggregate['data_size'] ?? 0) : 0;
    $index_size = is_array($aggregate) ? (float) ($aggregate['index_size'] ?? 0) : 0;
    $db_size = $data_size + $index_size;

    $options_size = $wpdb->get_var(
        $wpdb->prepare(
            'SELECT DATA_LENGTH + INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
            $db_name,
            $wpdb->options
        )
    );
    $autoload_size = $wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes', 'on', 'auto-on', 'auto')");
    $innodb_tables = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND ENGINE = 'InnoDB'", $db_name));
    $myisam_tables = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND ENGINE = 'MyISAM'", $db_name));

    echo '<div class="seo-status-card">';
    echo '<h2>Estadisticas de MySQL / MariaDB</h2>';
    echo '<p>Datos agregados de la base de datos usada por WordPress. No se muestra el contenido de las filas.</p>';
    seo_server_status_open_table();
    seo_server_status_row('Tamano base de datos', seo_server_status_format_bytes($db_size), 'info', 'Suma aproximada de datos e indices de la base actual.');
    seo_server_status_row('Datos', seo_server_status_format_bytes($data_size), 'info', 'Espacio aproximado ocupado por los datos de las tablas.');
    seo_server_status_row('Indices', seo_server_status_format_bytes($index_size), 'info', 'Espacio aproximado ocupado por indices.');
    seo_server_status_row('Numero de tablas', esc_html(number_format_i18n($table_count)), 'info', 'Total de tablas de la base de datos actual.');
    seo_server_status_row('Filas aproximadas', esc_html(number_format_i18n($estimated_rows)), 'info', 'Estimacion de information_schema; en InnoDB puede no ser exacta.');
    seo_server_status_row('Tablas InnoDB', esc_html(number_format_i18n((int) $innodb_tables)), 'info', 'Motor habitual y recomendado en WordPress moderno.');
    seo_server_status_row('Tablas MyISAM', esc_html(number_format_i18n((int) $myisam_tables)), ((int) $myisam_tables > 0) ? 'warning' : 'ok', 'En WordPress moderno suele ser preferible InnoDB.');
    seo_server_status_row('Tamano tabla options', seo_server_status_format_bytes((float) $options_size), ((float) $options_size > 52428800) ? 'warning' : 'ok', 'Si wp_options crece demasiado puede afectar al rendimiento.');
    seo_server_status_row('Tamano autoload', seo_server_status_format_bytes((float) $autoload_size), ((float) $autoload_size > 10485760) ? 'warning' : 'ok', 'Mas de 10 MB en autoload suele ser una senal a revisar.');
    seo_server_status_close_table();
    echo '</div>';

    seo_server_status_render_largest_tables_section();
}

/**
 * Renderiza tabla con las tablas más grandes de la base de datos.
 */
function seo_server_status_render_largest_tables_section() {
    $tables = seo_server_status_get_largest_tables(15);

    echo '<div class="seo-status-card">';
    echo '<h2>Tablas más grandes</h2>';
    echo '<p>Ayuda a detectar crecimiento anormal en options, postmeta, actionscheduler o tablas de plugins.</p>';

    if (empty($tables)) {
        echo '<p>No se pudieron leer las tablas grandes.</p>';
        echo '</div>';
        return;
    }

    echo '<table class="seo-status-table"><thead><tr><th>Tabla</th><th>Filas aprox.</th><th>Datos</th><th>Índices</th><th>Total</th><th>Motor</th></tr></thead><tbody>';
    foreach ($tables as $table) {
        $total = (float) $table->data_length + (float) $table->index_length;
        echo '<tr>';
        echo '<td><code>' . esc_html($table->table_name) . '</code></td>';
        echo '<td>' . esc_html(number_format_i18n((float) $table->table_rows)) . '</td>';
        echo '<td>' . esc_html(seo_server_status_format_bytes($table->data_length)) . '</td>';
        echo '<td>' . esc_html(seo_server_status_format_bytes($table->index_length)) . '</td>';
        echo '<td><strong>' . esc_html(seo_server_status_format_bytes($total)) . '</strong></td>';
        echo '<td>' . esc_html((string) $table->engine) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
}

/**
 * Renderiza la pestaña WordPress.
 */
function seo_server_status_render_wordpress_tab() {
    global $wpdb;

    echo '<h2>WordPress</h2><p>Información general de WordPress, constantes relevantes, cron y residuos core.</p>';
    seo_server_status_open_table();
    seo_server_status_row('Versión WordPress', esc_html(get_bloginfo('version')), 'ok', 'Versión instalada.');
    seo_server_status_row('URL del sitio', esc_html(home_url()), 'info', 'URL pública configurada.');
    seo_server_status_row('URL de WordPress', esc_html(site_url()), 'info', 'URL de instalación de WordPress.');
    seo_server_status_row('Idioma', esc_html(get_locale()), 'info', 'Locale activo.');
    seo_server_status_row('Multisite', seo_server_status_yes_no(is_multisite()), is_multisite() ? 'info' : 'ok', 'Indica si WordPress está en modo multisite.');
    seo_server_status_row('WP_DEBUG', seo_server_status_yes_no(defined('WP_DEBUG') && WP_DEBUG), defined('WP_DEBUG') && WP_DEBUG ? 'warning' : 'ok', 'En producción normalmente debería estar desactivado.');
    seo_server_status_row('WP_DEBUG_LOG', seo_server_status_yes_no(defined('WP_DEBUG_LOG') && WP_DEBUG_LOG), defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? 'info' : 'ok', 'Puede ser útil para diagnóstico.');
    seo_server_status_row('DISALLOW_FILE_EDIT', seo_server_status_yes_no(defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT), defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT ? 'ok' : 'warning', 'Por seguridad se recomienda desactivar el editor de archivos.');
    seo_server_status_row('DISABLE_WP_CRON', seo_server_status_yes_no(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON), defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'info' : 'ok', 'Si está activo, debería existir un cron real.');
    seo_server_status_row('WP_MEMORY_LIMIT', defined('WP_MEMORY_LIMIT') ? esc_html(WP_MEMORY_LIMIT) : 'No definida', 'info', 'Límite de memoria de WordPress.');
    seo_server_status_row('WP_MAX_MEMORY_LIMIT', defined('WP_MAX_MEMORY_LIMIT') ? esc_html(WP_MAX_MEMORY_LIMIT) : 'No definida', 'info', 'Límite de memoria en administración.');

    $cron_stats = seo_server_status_get_cron_stats();
    seo_server_status_row('Eventos WP-Cron', esc_html(number_format_i18n($cron_stats['total'])), 'info', 'Eventos registrados en la cola interna de WordPress.');
    seo_server_status_row('Eventos WP-Cron atrasados', esc_html(number_format_i18n($cron_stats['overdue'])), $cron_stats['overdue'] > 0 ? 'warning' : 'ok', 'Eventos con más de 15 minutos de retraso.');

    $revision_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'");
    $auto_drafts = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'");
    $trash_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'");
    $attachments = wp_count_posts('attachment');

    seo_server_status_row('Revisiones', esc_html(number_format_i18n($revision_count)), $revision_count > 5000 ? 'warning' : 'info', 'Candidato a limpieza en Clean Database.');
    seo_server_status_row('Auto-drafts', esc_html(number_format_i18n($auto_drafts)), $auto_drafts > 500 ? 'warning' : 'info', 'Borradores automáticos acumulados.');
    seo_server_status_row('Contenido en papelera', esc_html(number_format_i18n($trash_count)), $trash_count > 500 ? 'warning' : 'info', 'Entradas, páginas, productos u otros contenidos en papelera.');
    seo_server_status_row('Adjuntos publicados', esc_html(number_format_i18n((int) ($attachments->inherit ?? 0))), 'info', 'Elementos de la biblioteca de medios.');

    seo_server_status_close_table();

    seo_server_status_render_options_autoload_section();
}

/**
 * Renderiza análisis de options y autoload.
 */
function seo_server_status_render_options_autoload_section() {
    $stats = seo_server_status_get_options_stats();
    $top = seo_server_status_get_top_autoload_options(20);

    echo '<div class="seo-status-card">';
    echo '<h2>wp_options y autoload</h2>';
    echo '<p>El autoload excesivo suele afectar a todas las cargas de WordPress porque esas opciones se cargan al inicio.</p>';

    seo_server_status_open_table();
    seo_server_status_row('Opciones totales', esc_html(number_format_i18n($stats['total'])), 'info', 'Número de filas en wp_options.');
    seo_server_status_row('Opciones autoload', esc_html(number_format_i18n($stats['autoload_count'])), 'info', 'Opciones cargadas automáticamente.');
    seo_server_status_row('Tamaño autoload', esc_html(seo_server_status_format_bytes($stats['autoload_size'])), $stats['autoload_size'] > 10485760 ? 'warning' : 'ok', 'Más de 10 MB conviene revisarlo.');
    seo_server_status_row('Opción más grande', esc_html($stats['largest_name'] . ' (' . seo_server_status_format_bytes($stats['largest_size']) . ')'), $stats['largest_size'] > 5242880 ? 'warning' : 'info', 'Fila más pesada de wp_options.');
    seo_server_status_close_table();

    if (!empty($top)) {
        echo '<h3>Top opciones autoload más pesadas</h3>';
        echo '<table class="seo-status-table"><thead><tr><th>Opción</th><th>Tamaño</th><th>Estado</th></tr></thead><tbody>';
        foreach ($top as $row) {
            $size = (float) $row->option_size;
            echo '<tr><td><code>' . esc_html($row->option_name) . '</code></td><td>' . esc_html(seo_server_status_format_bytes($size)) . '</td><td>' . seo_server_status_badge($size > 1048576 ? 'warning' : 'info') . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    echo '</div>';
}

/**
 * Renderiza la pestaña Servidor.
 */
function seo_server_status_render_server_tab() {
    echo '<h2>Servidor</h2><p>Información básica del entorno de alojamiento, carga y uso de directorios clave.</p>';
    seo_server_status_open_table();

    $server_software = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : 'No disponible';
    seo_server_status_row('Software servidor', esc_html($server_software), 'info', 'Apache, Nginx, LiteSpeed u otro servidor web.');
    seo_server_status_row('Sistema operativo', esc_html(PHP_OS), 'info', 'Sistema operativo reportado por PHP.');
    seo_server_status_row('Hostname', function_exists('gethostname') && gethostname() ? esc_html(gethostname()) : 'No disponible', 'info', 'Nombre interno de la máquina si el hosting lo permite.');
    seo_server_status_row('SSL activo', seo_server_status_yes_no(is_ssl()), is_ssl() ? 'ok' : 'warning', 'El sitio debería funcionar bajo HTTPS.');

    $disk_free = function_exists('disk_free_space') ? @disk_free_space(ABSPATH) : false;
    $disk_total = function_exists('disk_total_space') ? @disk_total_space(ABSPATH) : false;
    if ($disk_free !== false) {
        seo_server_status_row('Espacio libre', seo_server_status_format_bytes($disk_free), 'info', 'Espacio libre visible desde PHP.');
    }
    if ($disk_total !== false) {
        seo_server_status_row('Espacio total', seo_server_status_format_bytes($disk_total), 'info', 'Espacio total visible desde PHP.');
    }
    if ($disk_free !== false && $disk_total !== false && $disk_total > 0) {
        $used_percent = round((($disk_total - $disk_free) / $disk_total) * 100, 2);
        seo_server_status_row('Uso de disco', esc_html($used_percent . '%'), $used_percent >= 90 ? 'error' : ($used_percent >= 80 ? 'warning' : 'ok'), 'Más del 80% conviene vigilarlo.');
    }

    $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;
    seo_server_status_row('Load average', is_array($load) ? esc_html(implode(' / ', $load)) : 'No disponible', 'info', 'Carga media del sistema si el servidor lo permite.');

    $cpu_cores = seo_server_status_detect_cpu_cores();
    seo_server_status_row('CPU cores detectados', $cpu_cores ? esc_html((string) $cpu_cores) : 'No disponible', $cpu_cores ? 'info' : 'warning', 'Se usa para interpretar el load average.');

    if (is_array($load) && $cpu_cores) {
        $ratio = round(((float) $load[0] / max(1, $cpu_cores)) * 100, 2);
        seo_server_status_row('Ratio carga/núcleo', esc_html($ratio . '%'), $ratio > 100 ? 'warning' : 'ok', 'Si supera el 100%, la carga reciente puede ser alta para los núcleos disponibles.');
    }

    seo_server_status_close_table();

    seo_server_status_render_directory_sizes_section();
    seo_server_status_render_large_files_section();
}

/**
 * Intenta detectar núcleos CPU sin romper hostings restringidos.
 */
function seo_server_status_detect_cpu_cores() {
    if (function_exists('shell_exec')) {
        $nproc = @shell_exec('nproc 2>/dev/null');
        $nproc = is_string($nproc) ? trim($nproc) : '';
        if (ctype_digit($nproc) && (int) $nproc > 0) {
            return (int) $nproc;
        }
    }

    if (is_readable('/proc/cpuinfo')) {
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if (is_string($cpuinfo)) {
            $count = substr_count($cpuinfo, 'processor');
            if ($count > 0) {
                return $count;
            }
        }
    }

    return 0;
}

/**
 * Renderiza tamaños de directorios relevantes.
 */
function seo_server_status_render_directory_sizes_section() {
    $upload_dir = wp_get_upload_dir();
    $paths = array(
        'wp-content' => defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '',
        'uploads' => isset($upload_dir['basedir']) ? $upload_dir['basedir'] : '',
        'plugins' => defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : '',
        'themes' => get_theme_root(),
        'cache' => defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/cache' : '',
        'wc-logs' => isset($upload_dir['basedir']) ? trailingslashit($upload_dir['basedir']) . 'wc-logs' : '',
    );

    echo '<div class="seo-status-card">';
    echo '<h2>Uso de espacio por directorios</h2>';
    echo '<p>Lectura aproximada con límite de archivos para no bloquear hostings compartidos.</p>';
    echo '<table class="seo-status-table"><thead><tr><th>Directorio</th><th>Tamaño</th><th>Archivos revisados</th><th>Estado</th></tr></thead><tbody>';

    foreach ($paths as $label => $path) {
        $data = seo_server_status_dir_size($path);
        if ($data === false) {
            echo '<tr><td>' . esc_html($label) . '</td><td>No disponible</td><td>-</td><td>' . seo_server_status_badge('info') . '</td></tr>';
            continue;
        }

        $status = $data['size'] > 5368709120 ? 'warning' : 'info';
        echo '<tr><td><code>' . esc_html($label) . '</code><br><span class="seo-muted">' . esc_html($path) . '</span></td><td>' . esc_html(seo_server_status_format_bytes($data['size'])) . '</td><td>' . esc_html(number_format_i18n($data['files'])) . ($data['limited'] ? ' +' : '') . '</td><td>' . seo_server_status_badge($status) . '</td></tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}

/**
 * Renderiza archivos grandes del sistema WordPress.
 */
function seo_server_status_render_large_files_section() {
    $upload_dir = wp_get_upload_dir();
    $paths = array(
        defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '',
        isset($upload_dir['basedir']) ? $upload_dir['basedir'] : '',
    );

    $files = seo_server_status_find_large_files($paths, 10485760, 35);

    echo '<div class="seo-status-card">';
    echo '<h2>Archivos grandes detectados</h2>';
    echo '<p>Busca logs, backups y paquetes grandes dentro de rutas de WordPress. No elimina nada.</p>';

    if (empty($files)) {
        echo '<p>No se han detectado logs, SQL, ZIP, GZ o backups mayores de 10 MB en las rutas revisadas.</p>';
        echo '</div>';
        return;
    }

    echo '<table class="seo-status-table"><thead><tr><th>Archivo</th><th>Tamaño</th><th>Modificado</th><th>Estado</th></tr></thead><tbody>';
    foreach ($files as $file) {
        $status = $file['size'] > 104857600 ? 'warning' : 'info';
        echo '<tr><td><code>' . esc_html($file['path']) . '</code></td><td>' . esc_html(seo_server_status_format_bytes($file['size'])) . '</td><td>' . esc_html(date_i18n('Y-m-d H:i', $file['modified'])) . '</td><td>' . seo_server_status_badge($status) . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
}

/**
 * Renderiza la pestaña WooCommerce.
 */
function seo_server_status_render_woocommerce_tab() {
    global $wpdb;

    echo '<h2>WooCommerce</h2><p>Chequeos básicos del catálogo, calidad de producto y colas internas.</p>';
    seo_server_status_open_table();

    $wc_active = class_exists('WooCommerce');
    seo_server_status_row('WooCommerce activo', seo_server_status_yes_no($wc_active), $wc_active ? 'ok' : 'warning', 'Comprueba si WooCommerce está cargado.');

    if (!$wc_active) {
        seo_server_status_close_table();
        return;
    }

    $products = wp_count_posts('product');
    seo_server_status_row('Productos publicados', esc_html(number_format_i18n((int) ($products->publish ?? 0))), 'info', 'Productos con estado publish.');
    seo_server_status_row('Productos borrador', esc_html(number_format_i18n((int) ($products->draft ?? 0))), ((int) ($products->draft ?? 0)) > 500 ? 'warning' : 'info', 'Productos en estado draft.');

    $variations = wp_count_posts('product_variation');
    seo_server_status_row('Variaciones publicadas', esc_html(number_format_i18n((int) ($variations->publish ?? 0))), 'info', 'Variaciones publicadas.');

    $product_cats = wp_count_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
    seo_server_status_row('Categorías de producto', is_wp_error($product_cats) ? 'Error al contar' : esc_html(number_format_i18n((int) $product_cats)), is_wp_error($product_cats) ? 'warning' : 'info', 'Total de categorías product_cat.');

    $product_attrs = function_exists('wc_get_attribute_taxonomies') ? wc_get_attribute_taxonomies() : array();
    seo_server_status_row('Atributos globales', esc_html(number_format_i18n(count($product_attrs))), count($product_attrs) === 0 ? 'warning' : 'info', 'Atributos registrados en WooCommerce.');

    $uncat = seo_server_status_count_products_without_category();
    seo_server_status_row('Productos sin categoría', esc_html(number_format_i18n($uncat)), $uncat > 0 ? 'warning' : 'ok', 'Productos publicados sin product_cat asignada.');

    $without_image = seo_server_status_count_products_without_image();
    seo_server_status_row('Productos sin imagen destacada', esc_html(number_format_i18n($without_image)), $without_image > 0 ? 'warning' : 'ok', 'Puede afectar a conversión y calidad de catálogo.');

    $empty_long = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND TRIM(post_content) = ''");
    $empty_short = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND TRIM(post_excerpt) = ''");
    seo_server_status_row('Productos sin descripción larga', esc_html(number_format_i18n($empty_long)), $empty_long > 0 ? 'warning' : 'ok', 'Relevante para SEO y venta.');
    seo_server_status_row('Productos sin descripción corta', esc_html(number_format_i18n($empty_short)), $empty_short > 0 ? 'warning' : 'ok', 'Relevante para ficha de producto y snippets internos.');

    seo_server_status_action_scheduler_rows();
    seo_server_status_close_table();

    seo_server_status_render_action_scheduler_detail_section();
}

/**
 * Cuenta productos publicados sin categoría.
 */
function seo_server_status_count_products_without_category() {
    global $wpdb;

    return (int) $wpdb->get_var(
        "SELECT COUNT(*)
         FROM {$wpdb->posts} p
         WHERE p.post_type = 'product'
           AND p.post_status = 'publish'
           AND NOT EXISTS (
                SELECT 1
                FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt
                    ON tt.term_taxonomy_id = tr.term_taxonomy_id
                   AND tt.taxonomy = 'product_cat'
                WHERE tr.object_id = p.ID
           )"
    );
}

/**
 * Cuenta productos publicados sin imagen destacada.
 */
function seo_server_status_count_products_without_image() {
    global $wpdb;

    return (int) $wpdb->get_var(
        "SELECT COUNT(*)
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id'
         WHERE p.post_type = 'product'
           AND p.post_status = 'publish'
           AND (pm.meta_value IS NULL OR pm.meta_value = '' OR pm.meta_value = '0')"
    );
}

/**
 * Devuelve conteo de Action Scheduler por estado.
 */
function seo_server_status_get_action_scheduler_count($status) {
    global $wpdb;

    $actions_table = $wpdb->prefix . 'actionscheduler_actions';

    if (!seo_server_status_table_exists($actions_table)) {
        return 0;
    }

    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$actions_table} WHERE status = %s", $status));
}

/**
 * Añade filas de Action Scheduler si existe.
 */
function seo_server_status_action_scheduler_rows() {
    $pending = seo_server_status_get_action_scheduler_count('pending');
    $failed = seo_server_status_get_action_scheduler_count('failed');
    $complete = seo_server_status_get_action_scheduler_count('complete');
    $canceled = seo_server_status_get_action_scheduler_count('canceled');

    if ($pending === 0 && $failed === 0 && $complete === 0 && $canceled === 0) {
        global $wpdb;
        if (!seo_server_status_table_exists($wpdb->prefix . 'actionscheduler_actions')) {
            seo_server_status_row('Action Scheduler', 'Tabla no encontrada', 'info', 'Puede no existir si WooCommerce no la ha creado todavía.');
            return;
        }
    }

    seo_server_status_row('Acciones pendientes', esc_html(number_format_i18n($pending)), $pending > 1000 ? 'warning' : 'ok', 'Muchas acciones pendientes pueden indicar cola atascada.');
    seo_server_status_row('Acciones fallidas', esc_html(number_format_i18n($failed)), $failed > 0 ? 'warning' : 'ok', 'Conviene revisar cualquier acción fallida.');
    seo_server_status_row('Acciones completadas', esc_html(number_format_i18n($complete)), 'info', 'Histórico de acciones completadas.');
    seo_server_status_row('Acciones canceladas', esc_html(number_format_i18n($canceled)), $canceled > 1000 ? 'warning' : 'info', 'Histórico de acciones canceladas.');
}

/**
 * Renderiza detalle adicional de Action Scheduler.
 */
function seo_server_status_render_action_scheduler_detail_section() {
    global $wpdb;

    $actions_table = $wpdb->prefix . 'actionscheduler_actions';

    if (!seo_server_status_table_exists($actions_table)) {
        return;
    }

    $rows = $wpdb->get_results(
        "SELECT hook, status, COUNT(*) AS total, MIN(scheduled_date_gmt) AS oldest_date
         FROM {$actions_table}
         WHERE status IN ('pending', 'failed')
         GROUP BY hook, status
         ORDER BY total DESC
         LIMIT 25"
    );

    echo '<div class="seo-status-card">';
    echo '<h2>Detalle Action Scheduler</h2>';
    echo '<p>Muestra los hooks con más acciones pendientes o fallidas para identificar colas atascadas.</p>';

    if (empty($rows)) {
        echo '<p>No hay acciones pendientes o fallidas relevantes.</p>';
        echo '</div>';
        return;
    }

    echo '<table class="seo-status-table"><thead><tr><th>Hook</th><th>Estado</th><th>Total</th><th>Más antigua</th><th>Diagnóstico</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $status = $row->status === 'failed' ? 'warning' : (((int) $row->total > 1000) ? 'warning' : 'info');
        echo '<tr><td><code>' . esc_html($row->hook) . '</code></td><td>' . esc_html($row->status) . '</td><td>' . esc_html(number_format_i18n((int) $row->total)) . '</td><td>' . esc_html($row->oldest_date) . '</td><td>' . seo_server_status_badge($status) . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
}

/**
 * Renderiza la pestaña Rendimiento.
 */
function seo_server_status_render_performance_tab() {
    echo '<h2>Rendimiento</h2>';
    echo '<p>Lectura agrupada de los indicadores que suelen explicar lentitud en WordPress y WooCommerce.</p>';

    seo_server_status_render_options_autoload_section();
    seo_server_status_render_largest_tables_section();
    seo_server_status_render_action_scheduler_detail_section();
}

/**
 * Renderiza la pestaña Logs con el error_log de PHP/sistema en modo solo lectura.
 */
function seo_server_status_render_logs_tab() {

    echo '<h2>Logs</h2>';
    echo '<p>Cuando el hosting lo permite, SEO System puede mostrar el <code>error_log</code> de PHP. El archivo no necesita ser publico por HTTP para poder leerse desde WordPress.</p>';

    $php_log = seo_server_status_get_php_error_log_info();
    $show_php_log = isset($_GET['seo_show_php_log']) && sanitize_key(wp_unslash($_GET['seo_show_php_log'])) === '1';

    echo '<div class="seo-status-card">';
    echo '<h2>Log PHP / sistema</h2>';
    echo '<p class="seo-muted">Este es el destino configurado por PHP para errores del servidor. SEO System no lo configura ni lo modifica automaticamente: cada hosting puede usar una ruta, permisos o incluso syslog distintos. Si el archivo es legible por el mismo proceso PHP que ejecuta WordPress, puede mostrarse aqui aunque este fuera del directorio publico.</p>';

    seo_server_status_open_table();

    seo_server_status_row(
        'Registro de errores PHP',
        seo_server_status_yes_no($php_log['log_errors']),
        $php_log['log_errors'] ? 'ok' : 'warning',
        $php_log['log_errors'] ? 'PHP tiene activado log_errors.' : 'PHP no tiene activado log_errors; no se registraran errores en este destino.'
    );

    seo_server_status_row(
        'Destino PHP error_log',
        $php_log['display_target'],
        $php_log['configured'] ? 'info' : 'warning',
        $php_log['target_help']
    );

    if ($php_log['type'] === 'file') {
        seo_server_status_row(
            'Fuera del directorio publico',
            $php_log['private_known'] ? seo_server_status_yes_no($php_log['private']) : 'No concluyente',
            $php_log['private_known'] ? ($php_log['private'] ? 'ok' : 'important') : 'info',
            $php_log['private_known'] ? 'El log del sistema debe permanecer fuera del document root.' : 'No se ha podido determinar con seguridad la relacion entre la ruta y el document root.'
        );

        seo_server_status_row(
            'Archivo existente',
            seo_server_status_yes_no($php_log['exists']),
            $php_log['exists'] ? 'ok' : 'info',
            $php_log['exists'] ? 'El archivo ya existe.' : 'Puede crearse automaticamente cuando PHP registre el primer error si el directorio es escribible.'
        );

        seo_server_status_row(
            'Legible desde WordPress',
            seo_server_status_yes_no($php_log['readable']),
            $php_log['readable'] ? 'ok' : 'info',
            $php_log['readable'] ? 'SEO System puede mostrar sus ultimas lineas a un administrador.' : 'El hosting puede registrar errores sin permitir que WordPress lea el archivo.'
        );

        seo_server_status_row(
            'Escribible por PHP',
            seo_server_status_yes_no($php_log['writable']),
            $php_log['writable'] ? 'ok' : 'info',
            'Se informa para diagnostico. SEO System no vacia ni modifica el log PHP del hosting.'
        );
    }

    seo_server_status_close_table();

    echo '<details class="seo-status-note" style="margin-top:14px;">';
    echo '<summary style="cursor:pointer;font-weight:600;">Como activar o cambiar el log PHP / sistema</summary>';
    echo '<p>Es opcional y no requiere <code>WP_DEBUG=true</code>. Para capturar errores fatales en produccion basta con mantener <code>log_errors</code> activo y apuntar <code>error_log</code> a una ruta privada y escribible fuera del document root.</p>';
    echo '<pre class="seo-log-box" style="max-height:none;">' . esc_html("@ini_set('log_errors', '1');\n@ini_set('error_log', '/RUTA/PRIVADA/FUERA/DEL/DOCUMENT_ROOT/wp-private-error.log');") . '</pre>';
    echo '<p class="seo-muted">Estas lineas deben ir en <code>wp-config.php</code> antes de cargar <code>wp-settings.php</code>. La ruta exacta depende del hosting; SEO System no debe inventarla ni forzarla durante la instalacion.</p>';
    echo '</details>';

    if (!$php_log['log_errors'] || !$php_log['configured']) {
        echo '<div class="seo-status-note">';
        echo '<strong>El log PHP es opcional, pero recomendable para diagnosticar errores fatales.</strong> ';
        echo 'No requiere activar <code>WP_DEBUG</code>. Si el cliente quiere usarlo, debe configurarlo en <code>wp-config.php</code> antes de cargar <code>wp-settings.php</code>, usando una ruta privada y escribible fuera del document root.';
        echo '<pre class="seo-log-box" style="max-height:none;margin-top:10px;">' . esc_html("@ini_set('log_errors', '1');\n@ini_set('error_log', '/RUTA/PRIVADA/FUERA/DEL/DOCUMENT_ROOT/wp-private-error.log');") . '</pre>';
        echo '<p class="seo-muted">La ruta exacta depende del hosting. SEO System no la inventa ni la fuerza durante la instalacion.</p>';
        echo '</div>';
    } elseif ($php_log['type'] === 'file' && $php_log['private_known'] && !$php_log['private']) {
        echo '<div class="notice notice-warning"><p><strong>Recomendacion:</strong> el error_log de PHP esta dentro del directorio publico. Muevelo a una ruta privada del hosting y vuelve a ejecutar Seguridad.</p></div>';
    } elseif ($php_log['type'] === 'file' && !$php_log['private_known']) {
        echo '<div class="seo-status-note"><strong>Privacidad de la ruta no concluyente:</strong> SEO System no puede determinar si el destino queda dentro o fuera del document root. El cliente debe confirmarlo en el panel del hosting.</div>';
    } elseif ($php_log['type'] === 'file' && !$php_log['exists']) {
        echo '<div class="seo-status-note"><strong>Log PHP configurado pero aun sin archivo:</strong> esto puede ser normal si no se ha producido ningun error desde que se cambio la ruta. PHP suele crear el archivo al escribir la primera entrada.</div>';
    } elseif ($php_log['type'] === 'file' && !$php_log['readable']) {
        echo '<div class="seo-status-note"><strong>Log PHP activo pero no legible desde WordPress:</strong> SEO System no intentara cambiar permisos. El cliente debe consultarlo desde el panel del hosting o pedir acceso al proveedor.</div>';
    } elseif ($php_log['type'] === 'syslog' || $php_log['type'] === 'stream') {
        echo '<div class="seo-status-note"><strong>Destino gestionado por el sistema:</strong> PHP no esta escribiendo en un archivo local convencional accesible desde WordPress. SEO System lo deja en modo informativo.</div>';
    }

    if ($php_log['type'] === 'file' && $php_log['exists'] && $php_log['readable']) {
        $toggle_url = $show_php_log
            ? remove_query_arg('seo_show_php_log')
            : add_query_arg('seo_show_php_log', '1');

        echo '<p><a class="button button-secondary" href="' . esc_url($toggle_url) . '">' . esc_html($show_php_log ? 'Ocultar log PHP / sistema' : 'Mostrar ultimas 120 lineas del log PHP / sistema') . '</a></p>';
        echo '<p class="seo-muted">La lectura es voluntaria y solo para administradores. Antes de mostrarlo se enmascaran patrones comunes de contrasenas, tokens, claves API y cabeceras Authorization.</p>';

        if ($show_php_log) {
            $php_tail = seo_server_status_tail_file($php_log['path'], 120);
            echo '<h3>Ultimas 120 lineas del log PHP / sistema</h3>';
            echo '<div class="seo-log-box">' . esc_html(seo_server_status_redact_log_text($php_tail)) . '</div>';
        }
    }

    echo '</div>';

    seo_server_status_render_large_files_section();
}

/**
 * Resume la configuracion efectiva del error_log de PHP sin modificarla.
 */
function seo_server_status_get_php_error_log_info() {
    $raw_target = trim((string) ini_get('error_log'));
    $log_errors = filter_var(ini_get('log_errors'), FILTER_VALIDATE_BOOLEAN);

    $info = array(
        'configured'    => $raw_target !== '',
        'log_errors'    => (bool) $log_errors,
        'type'          => 'none',
        'path'          => '',
        'display_target'=> $raw_target !== '' ? esc_html($raw_target) : 'No definido',
        'target_help'   => 'PHP no expone un destino de error_log.',
        'exists'        => false,
        'readable'      => false,
        'writable'      => false,
        'private'       => false,
        'private_known' => false,
    );

    if ($raw_target === '') {
        return $info;
    }

    $lower = strtolower($raw_target);
    if ($lower === 'syslog') {
        $info['type'] = 'syslog';
        $info['target_help'] = 'PHP envia los errores al syslog del sistema; WordPress normalmente no puede leerlo como archivo.';
        return $info;
    }

    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw_target)) {
        $info['type'] = 'stream';
        $info['target_help'] = 'PHP usa un stream o destino especial. SEO System no intenta abrirlo como archivo local.';
        return $info;
    }

    $is_absolute = function_exists('path_is_absolute') ? path_is_absolute($raw_target) : (isset($raw_target[0]) && ($raw_target[0] === '/' || $raw_target[0] === '\\'));

    if (!$is_absolute) {
        $info['type'] = 'relative';
        $info['target_help'] = 'La ruta de error_log es relativa. Su ubicacion efectiva depende de la configuracion del servidor y no se considera segura para lectura automatica.';
        return $info;
    }

    $path = wp_normalize_path($raw_target);
    $info['type'] = 'file';
    $info['path'] = $path;
    $info['display_target'] = esc_html($path);
    $info['target_help'] = 'Ruta local configurada por PHP. SEO System solo la inspecciona; no la cambia.';
    $info['exists'] = is_file($path);
    $info['readable'] = $info['exists'] && is_readable($path);

    if ($info['exists']) {
        $info['writable'] = is_writable($path);
    } else {
        $parent = dirname($path);
        $info['writable'] = is_dir($parent) && is_writable($parent);
    }

    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $document_root = realpath((string) $_SERVER['DOCUMENT_ROOT']);
        if ($document_root !== false) {
            $normalized_root = trailingslashit(untrailingslashit(wp_normalize_path($document_root)));
            $info['private_known'] = true;
            $info['private'] = strpos($path, $normalized_root) !== 0;
        }
    }

    return $info;
}

/**
 * Enmascara patrones frecuentes de secretos antes de mostrar un log en wp-admin.
 * No pretende ser un DLP completo; reduce exposiciones accidentales comunes.
 */
function seo_server_status_redact_log_text($text) {
    $text = (string) $text;
    if ($text === '') {
        return '';
    }

    $patterns = array(
        '/(Authorization:\s*(?:Bearer|Basic)\s+)[^\s]+/i' => '$1[REDACTED]',
        '/((?:password|passwd|pwd|token|secret|api[_-]?key|access[_-]?token)\s*[=:]\s*)[^\s&,;]+/i' => '$1[REDACTED]',
        '/([?&](?:password|passwd|pwd|token|secret|api[_-]?key|access[_-]?token)=)[^&\s]+/i' => '$1[REDACTED]',
        '/(Cookie:\s*).*/i' => '$1[REDACTED]',
        '/(Set-Cookie:\s*).*/i' => '$1[REDACTED]',
    );

    return preg_replace(array_keys($patterns), array_values($patterns), $text);
}

/**
 * Lee las ultimas lineas de un archivo sin cargarlo completo.
 */
function seo_server_status_tail_file($file_path, $lines = 120) {
    if (!file_exists($file_path) || !is_readable($file_path)) {
        return '';
    }

    $max_bytes = 300000;
    $file_size = filesize($file_path);
    $handle = fopen($file_path, 'rb');

    if (!$handle) {
        return '';
    }

    if ($file_size > $max_bytes) {
        fseek($handle, -$max_bytes, SEEK_END);
        fgets($handle);
    }

    $content = stream_get_contents($handle);
    fclose($handle);

    if ($content === false) {
        return '';
    }

    $rows = preg_split("/\r\n|\n|\r/", $content);
    $rows = array_slice($rows, -absint($lines));

    return implode("\n", $rows);
}
