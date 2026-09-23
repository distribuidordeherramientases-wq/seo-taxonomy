<?php
/**
 * Ojeador admin: category-first Google Shopping market view.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @since 0.6.0
 */

defined('ABSPATH') || exit;

final class SEO_Ojeador_Admin {
    const PAGE = 'seo-ojeador';
    private static $tools_filter_used = false;

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_page'), 90);
        add_filter('seo_tools_cards', array(__CLASS__, 'register_tool_card'));
        add_action('admin_footer', array(__CLASS__, 'tools_fallback_card'), 99);
        add_action('admin_post_seo_ojeador_settings_save', array(__CLASS__, 'save_settings'));
        add_action('admin_post_seo_ojeador_start', array(__CLASS__, 'start'));
        add_action('admin_post_seo_ojeador_stop', array(__CLASS__, 'stop'));
        add_action('admin_post_seo_ojeador_export_json', array(__CLASS__, 'export_json'));
        add_action('admin_post_seo_ojeador_export_log_json', array(__CLASS__, 'export_log_json'));
    }

    public static function register_page() {
        add_submenu_page(null, 'Ojeador', 'Ojeador', 'manage_options', self::PAGE, array(__CLASS__, 'render'));
    }

    public static function register_tool_card($tools) {
        self::$tools_filter_used = true;
        $tools = is_array($tools) ? $tools : array();
        foreach ($tools as $tool) {
            if (($tool['page'] ?? '') === self::PAGE) {
                return $tools;
            }
        }
        $tools[] = array(
            'title' => 'Ojeador',
            'icon' => 'dashicons-chart-line',
            'page' => self::PAGE,
            'desc' => 'Consulta categorías en Google Shopping y conserva todo el mercado devuelto por la API.',
        );
        return $tools;
    }

    public static function tools_fallback_card() {
        if (self::$tools_filter_used || !current_user_can('manage_options')) {
            return;
        }
        if (sanitize_key((string) ($_GET['page'] ?? '')) !== 'seo-tools') {
            return;
        }
        $url = admin_url('admin.php?page=' . self::PAGE);
        ?>
        <script id="seo-ojeador-tools-card-fallback">
        (function(){
            var grid=document.querySelector('.seo-tools-grid'); if(!grid) return;
            var href=<?php echo wp_json_encode($url); ?>;
            var links=grid.querySelectorAll('a[href]');
            for(var i=0;i<links.length;i++){if(links[i].href===href||links[i].getAttribute('href')===href)return;}
            var a=document.createElement('a');a.className='seo-tool-card-link';a.href=href;
            a.innerHTML='<div class="seo-tool-card"><span class="dashicons dashicons-chart-line"></span><h2>Ojeador</h2><p>Mercado por categorías en Google Shopping.</p><span class="button button-primary">Abrir</span></div>';
            grid.appendChild(a);
        })();
        </script>
        <?php
    }

    private static function guard($action) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No autorizado.', 'seo-taxonomy'));
        }
        check_admin_referer($action);
    }

    private static function redirect($args = array()) {
        wp_safe_redirect(add_query_arg(array_merge(array('page'=>self::PAGE), $args), admin_url('admin.php')));
        exit;
    }

    public static function save_settings() {
        self::guard('seo_ojeador_settings_save');
        $raw = isset($_POST['ojeador']) && is_array($_POST['ojeador']) ? wp_unslash($_POST['ojeador']) : array();
        $raw['auto_enabled'] = empty($raw['auto_enabled']) ? 0 : 1;
        SEO_Ojeador_Shopping::save_settings($raw);
        self::redirect(array('ojeador_notice'=>'settings_saved'));
    }

    public static function start() {
        self::guard('seo_ojeador_start');
        $result = SEO_Ojeador_Worker::start_run('admin');
        if (is_wp_error($result)) {
            self::redirect(array('ojeador_error'=>rawurlencode($result->get_error_message())));
        }
        self::redirect(array('ojeador_notice'=>'scan_started'));
    }

    public static function stop() {
        self::guard('seo_ojeador_stop');
        SEO_Ojeador_Worker::stop_run();
        self::redirect(array('ojeador_notice'=>'scan_stopped'));
    }

    private static function status_label($status) {
        switch (sanitize_key((string) $status)) {
            case 'ok': return array('Con datos', '#008a20');
            case 'no_results': return array('Sin resultados', '#996800');
            case 'error': return array('Error', '#b32d2e');
            default: return array('Pendiente', '#646970');
        }
    }

    private static function query_log_label($status) {
        switch (sanitize_key((string) $status)) {
            case 'ok': return array('OK', '#008a20');
            case 'no_results': return array('Sin resultados', '#996800');
            case 'reused': return array('Reutilizada', '#2271b1');
            case 'response_ok':
            case 'parsed': return array('Respuesta recibida', '#2271b1');
            case 'requesting': return array('Consultando', '#996800');
            case 'prepared': return array('Preparada', '#646970');
            case 'budget_blocked': return array('Límite', '#996800');
            case 'query_error': return array('Consulta inválida', '#b32d2e');
            case 'network_error':
            case 'http_error':
            case 'api_error':
            case 'parse_error':
            case 'db_error': return array('Error', '#b32d2e');
            default: return array($status !== '' ? $status : '—', '#646970');
        }
    }

    private static function json_headers($filename) {
        while (ob_get_level()) {
            ob_end_clean();
        }
        nocache_headers();
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('X-Content-Type-Options: nosniff');
    }

    private static function stream_table_json($table, $order_by = 'id ASC', $where = '1=1') {
        global $wpdb;
        $offset = 0;
        $chunk = 500;
        $first = true;
        echo '[';
        do {
            $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE {$where} ORDER BY {$order_by} LIMIT {$chunk} OFFSET {$offset}", ARRAY_A);
            foreach ((array) $rows as $row) {
                if (!$first) {
                    echo ',';
                }
                echo wp_json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $first = false;
            }
            $count = count((array) $rows);
            $offset += $count;
        } while ($count === $chunk);
        echo ']';
    }

    public static function export_json() {
        self::guard('seo_ojeador_export_json');
        global $wpdb;

        $settings = SEO_Ojeador_Shopping::settings();
        $safe_settings = $settings;
        $safe_settings['api_key_configured'] = !empty($settings['api_key']);
        unset($safe_settings['api_key']);
        $usage = SEO_Ojeador_Shopping::usage_month();
        $summary = SEO_Ojeador_DB::category_summary();
        $inventory = SEO_Ojeador_DB::list_market_categories(array('limit'=>2000, 'search'=>''));
        $latest_run = SEO_Ojeador_DB::latest_run();
        $schema_table = $wpdb->prefix . 'seo_google_schema_map';
        $schema_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $schema_table)) === $schema_table;

        self::json_headers('ojeador-inventario-completo-' . gmdate('Ymd-His') . '.json');
        echo '{';
        echo '"meta":' . wp_json_encode(array(
            'schema' => 'seo_ojeador_export',
            'schema_version' => '1.0.0',
            'generated_at_utc' => gmdate('c'),
            'site_url' => home_url('/'),
            'ojeador_version' => defined('SEO_OJEADOR_VERSION') ? SEO_OJEADOR_VERSION : '',
            'db_version' => get_option(SEO_Ojeador_DB::OPTION_DB_VERSION, ''),
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo ',"settings":' . wp_json_encode($safe_settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo ',"usage":' . wp_json_encode($usage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo ',"summary":' . wp_json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo ',"latest_run":' . wp_json_encode($latest_run, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo ',"category_inventory":' . wp_json_encode($inventory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo ',"google_schema_product_cat":';
        if ($schema_exists) {
            self::stream_table_json($schema_table, 'object_id ASC', "object_type='product_cat'");
        } else {
            echo '[]';
        }
        echo ',"market_categories":';
        self::stream_table_json(SEO_Ojeador_DB::table('categories'), 'term_id ASC');
        echo ',"market_category_results":';
        self::stream_table_json(SEO_Ojeador_DB::table('category_results'), 'id ASC');
        echo ',"query_log":';
        self::stream_table_json(SEO_Ojeador_DB::table('query_log'), 'id ASC');
        echo ',"runs":';
        self::stream_table_json(SEO_Ojeador_DB::table('runs'), 'id ASC');
        echo ',"legacy_market_products":';
        self::stream_table_json(SEO_Ojeador_DB::table('products'), 'object_id ASC');
        echo ',"legacy_market_offers":';
        self::stream_table_json(SEO_Ojeador_DB::table('offers'), 'id ASC');
        echo '}';
        exit;
    }

    public static function export_log_json() {
        self::guard('seo_ojeador_export_log_json');
        self::json_headers('ojeador-log-consultas-' . gmdate('Ymd-His') . '.json');
        echo '{';
        echo '"meta":' . wp_json_encode(array(
            'schema' => 'seo_ojeador_query_log',
            'schema_version' => '1.0.0',
            'generated_at_utc' => gmdate('c'),
            'ojeador_version' => defined('SEO_OJEADOR_VERSION') ? SEO_OJEADOR_VERSION : '',
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo ',"usage":' . wp_json_encode(SEO_Ojeador_Shopping::usage_month(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo ',"latest_run":' . wp_json_encode(SEO_Ojeador_DB::latest_run(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo ',"query_log":';
        self::stream_table_json(SEO_Ojeador_DB::table('query_log'), 'id ASC');
        echo '}';
        exit;
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $summary = SEO_Ojeador_DB::category_summary();
        $settings = SEO_Ojeador_Shopping::settings();
        $usage = SEO_Ojeador_Shopping::usage_month();
        $ready = SEO_Ojeador_Shopping::readiness();
        $run = SEO_Ojeador_DB::active_run();
        if (!$run) {
            $run = SEO_Ojeador_DB::latest_run();
        }
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $rows = SEO_Ojeador_DB::list_market_categories(array('limit'=>1000, 'search'=>$search));
        $logs = SEO_Ojeador_DB::list_query_logs(100);
        $latest_log = SEO_Ojeador_DB::latest_query_log();
        $notice = sanitize_key((string) ($_GET['ojeador_notice'] ?? ''));
        $error = isset($_GET['ojeador_error']) ? sanitize_text_field(rawurldecode((string) $_GET['ojeador_error'])) : '';
        ?>
        <div class="wrap seo-ojeador-v060">
            <style>
                .seo-ojeador-v060{max-width:1450px}.seo-ojeador-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap}
                .seo-ojeador-sub{color:#646970;margin-top:5px;max-width:980px}.seo-ojeador-actions{display:flex;gap:8px;flex-wrap:wrap}.seo-ojeador-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px;margin:18px 0}
                .seo-ojeador-card,.seo-ojeador-box{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:15px}.seo-ojeador-card strong{display:block;font-size:24px;line-height:1.1}.seo-ojeador-card span{color:#646970}
                .seo-ojeador-table{overflow:auto;background:#fff;border:1px solid #dcdcde;border-radius:8px}.seo-ojeador-table table{border:0;margin:0}.seo-ojeador-muted{color:#646970}.seo-ojeador-pill{font-weight:600;white-space:nowrap}
                .seo-ojeador-run{margin:12px 0 0;padding:10px 12px;background:#f6f7f7;border-radius:6px}.seo-ojeador-config{margin-top:18px}.seo-ojeador-config summary{cursor:pointer;font-weight:600;font-size:15px}
                .seo-ojeador-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;margin-top:14px}.seo-ojeador-grid label{display:block}.seo-ojeador-grid input{width:100%;margin-top:5px}.seo-ojeador-grid small{display:block;color:#646970;margin-top:4px}
            </style>

            <div class="seo-ojeador-head">
                <div>
                    <h1 style="margin-bottom:0">Ojeador <small style="font-size:14px;color:#646970">v<?php echo esc_html(SEO_OJEADOR_VERSION); ?></small></h1>
                    <p class="seo-ojeador-sub">Consulta Google Shopping sólo para categorías con vocabulario aprobado y <code>shopping_query</code> explícita. Primero completa categorías nunca consultadas con su consulta aprobada; dentro de ese grupo prioriza tráfico de Analista (28 días) y después número de productos.</p>
                </div>
                <div class="seo-ojeador-actions">
                    <?php if (SEO_Ojeador_Worker::is_pending()) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('seo_ojeador_stop'); ?><input type="hidden" name="action" value="seo_ojeador_stop"><button class="button">Detener</button></form>
                    <?php else : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('seo_ojeador_start'); ?><input type="hidden" name="action" value="seo_ojeador_start"><button class="button button-primary" <?php disabled(is_wp_error($ready)); ?>>Continuar / actualizar mercado</button></form>
                    <?php endif; ?>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=seo-processes')); ?>">Procesos</a>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('seo_ojeador_export_json'); ?><input type="hidden" name="action" value="seo_ojeador_export_json"><button class="button" type="submit">Exportar JSON completo</button></form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('seo_ojeador_export_log_json'); ?><input type="hidden" name="action" value="seo_ojeador_export_log_json"><button class="button" type="submit">Exportar log JSON</button></form>
                </div>
            </div>

            <?php if ($notice === 'settings_saved') : ?><div class="notice notice-success inline"><p>Configuración guardada.</p></div><?php endif; ?>
            <?php if ($notice === 'scan_started') : ?><div class="notice notice-success inline"><p>Ojeador está consultando categorías aptas. No repite categorías mientras quede alguna sin una primera instantánea con su <code>shopping_query</code> aprobada; después prioriza Analista, productos y antigüedad.</p></div><?php endif; ?>
            <?php if ($notice === 'scan_stopped') : ?><div class="notice notice-info inline"><p>Proceso detenido.</p></div><?php endif; ?>
            <?php if ($error !== '') : ?><div class="notice notice-error inline"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
            <?php if (is_wp_error($ready)) : ?><div class="notice notice-warning inline"><p><strong>Google Shopping:</strong> <?php echo esc_html($ready->get_error_message()); ?></p></div><?php endif; ?>

            <div class="seo-ojeador-cards">
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n($summary['target']); ?></strong><span>Categorías aptas Ojeador</span></div>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n($summary['excluded'] ?? 0); ?></strong><span>Excluidas por vocabulario</span></div>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n($summary['consulted']); ?></strong><span>Con consulta aprobada realizada</span></div>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n($summary['pending']); ?></strong><span>Sin primera consulta válida</span></div>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n($usage['used']); ?> / <?php echo number_format_i18n($usage['limit']); ?></strong><span><?php echo ($usage['source'] ?? '') === 'serpapi_account' ? 'Uso SerpApi real' : 'Uso local (fallback)'; ?></span></div>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n(absint($usage['local_requests'] ?? 0)); ?></strong><span>Peticiones HTTP Ojeador</span></div>
                <?php if (($usage['source'] ?? '') === 'serpapi_account') : ?>
                    <div class="seo-ojeador-card"><strong><?php $usage_diff = (int) ($usage['difference_local_minus_provider'] ?? 0); echo esc_html(($usage_diff > 0 ? '+' : '') . number_format_i18n($usage_diff)); ?></strong><span>Diferencia local − SerpApi</span></div>
                <?php endif; ?>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n($summary['with_results']); ?></strong><span>Categorías con resultados</span></div>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n($summary['without_results']); ?></strong><span>Sin resultados</span></div>
                <div class="seo-ojeador-card"><strong><?php echo esc_html(number_format_i18n($summary['coverage'], 1)); ?>%</strong><span>Cobertura válida</span></div>
            </div>

            <?php if ($run) : ?>
                <div class="seo-ojeador-run">
                    <strong>Último proceso:</strong>
                    <?php echo esc_html((string) ($run['status'] ?? '')); ?> ·
                    <?php echo number_format_i18n(absint($run['processed_categories'] ?? 0)); ?> categorías ·
                    <?php echo number_format_i18n(absint($run['api_queries'] ?? 0)); ?> peticiones HTTP ·
                    <?php echo number_format_i18n(absint($run['results_seen'] ?? 0)); ?> resultados guardados ·
                    <?php echo number_format_i18n(absint($run['errors_count'] ?? 0)); ?> errores.
                    <?php if (($usage['source'] ?? '') === 'serpapi_account') : ?>
                        <br><small>SerpApi informa de <?php echo number_format_i18n(absint($usage['provider_used'] ?? 0)); ?> búsquedas usadas este mes; Ojeador registra <?php echo number_format_i18n(absint($usage['local_requests'] ?? 0)); ?> peticiones HTTP. La diferencia puede incluir respuestas servidas desde caché gratuita, peticiones fallidas u otras llamadas que compartan la misma API key.</small>
                    <?php elseif (!empty($usage['provider_error'])) : ?>
                        <br><small>No se pudo leer Account API: <?php echo esc_html((string) $usage['provider_error']); ?></small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div style="display:flex;justify-content:space-between;gap:12px;align-items:end;flex-wrap:wrap;margin:22px 0 8px;">
                <div><h2 style="margin:0">Mercado por categorías</h2><p class="seo-ojeador-muted" style="margin:4px 0 0">Cada fila representa una categoría WooCommerce y su última consulta a Google Shopping.</p></div>
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>"><input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>"><input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Categoría o consulta"><button class="button">Buscar</button></form>
            </div>

            <div class="seo-ojeador-table">
                <table class="widefat striped">
                    <thead><tr><th>Categoría</th><th>Consulta Google</th><th>Prioridad</th><th>Productos en catálogo</th><th>Resultados Google</th><th>Estado</th><th>Última consulta</th><th>Próxima</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row) :
                        $eligible = !empty($row['ojeador_eligible']);
                        $first_scan = !empty($row['needs_trusted_scan']);
                        $due = !empty($row['ojeador_due']);
                        if (!$eligible) {
                            $label = 'No apta';
                            $color = '#b32d2e';
                        } elseif ($first_scan) {
                            $label = 'Pendiente';
                            $color = '#996800';
                        } else {
                            list($label, $color) = self::status_label($row['status'] ?? '');
                        }
                        $category_name = (string) ($row['category_name'] ?: $row['woo_category']);
                        if ($eligible) {
                            $query_text = $first_scan
                                ? (string) ($row['trusted_query'] ?? '')
                                : (string) (($row['query_text'] ?? '') ?: ($row['trusted_query'] ?? ''));
                        } else {
                            $query_text = '';
                        }
                        $priority_label = !$eligible ? 'Excluida' : ($first_scan ? 'Primera consulta' : ($due ? 'Actualizar' : 'Al día'));
                        $clicks = (float) ($row['analista_clicks'] ?? 0);
                        $impressions = (float) ($row['analista_impressions'] ?? 0);
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($category_name); ?></strong></td>
                            <td><?php echo $query_text !== '' ? esc_html($query_text) : '<span class="seo-ojeador-muted">—</span>'; ?></td>
                            <td><strong><?php echo esc_html($priority_label); ?></strong><?php if ($eligible) : ?><br><small class="seo-ojeador-muted">Analista 28 d: <?php echo number_format_i18n($clicks, 0); ?> clics · <?php echo number_format_i18n($impressions, 0); ?> imp.</small><?php endif; ?></td>
                            <td><?php echo number_format_i18n(absint($row['woo_product_count'] ?? 0)); ?></td>
                            <td><?php echo number_format_i18n(absint($row['result_count'] ?? 0)); ?></td>
                            <td><span class="seo-ojeador-pill" style="color:<?php echo esc_attr($color); ?>"><?php echo esc_html($label); ?></span><?php if (!empty($row['last_error'])) : ?><br><small><?php echo esc_html(wp_trim_words((string) $row['last_error'], 14)); ?></small><?php endif; ?></td>
                            <td><?php echo !empty($row['last_scan_at']) ? esc_html((string) $row['last_scan_at']) : '—'; ?></td>
                            <td><?php echo !empty($row['next_scan_at']) ? esc_html((string) $row['next_scan_at']) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows) : ?><tr><td colspan="8">No hay categorías de producto con contenido.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div style="display:flex;justify-content:space-between;gap:12px;align-items:end;flex-wrap:wrap;margin:22px 0 8px;">
                <div><h2 style="margin:0">Log de consultas Google Shopping</h2><p class="seo-ojeador-muted" style="margin:4px 0 0">Trazabilidad por petición: consulta, HTTP, respuesta de SerpApi, resultados normalizados, resultados guardados y errores de persistencia.</p></div>
                <?php if ($latest_log) : ?><small class="seo-ojeador-muted">Último evento: <?php echo esc_html((string) ($latest_log['created_at'] ?? '')); ?></small><?php endif; ?>
            </div>
            <div class="seo-ojeador-table">
                <table class="widefat striped">
                    <thead><tr><th>Fecha UTC</th><th>Run</th><th>Categoría</th><th>Consulta</th><th>Resultado</th><th>HTTP</th><th>Recibidos</th><th>Normalizados</th><th>Guardados</th><th>Tiempo</th><th>Error</th></tr></thead>
                    <tbody>
                    <?php foreach ($logs as $log) :
                        list($log_label, $log_color) = self::query_log_label($log['event_status'] ?? '');
                    ?>
                        <tr>
                            <td><?php echo esc_html((string) ($log['created_at'] ?? '')); ?></td>
                            <td><?php echo absint($log['run_id'] ?? 0) ?: '—'; ?></td>
                            <td><?php echo !empty($log['category_name']) ? esc_html((string) $log['category_name']) : (!empty($log['term_id']) ? '#' . absint($log['term_id']) : '—'); ?></td>
                            <td><?php echo !empty($log['query_text']) ? esc_html((string) $log['query_text']) : '—'; ?></td>
                            <td><span class="seo-ojeador-pill" style="color:<?php echo esc_attr($log_color); ?>"><?php echo esc_html($log_label); ?></span><?php if (!empty($log['provider_search_id'])) : ?><br><small class="seo-ojeador-muted">SerpApi: <?php echo esc_html((string) $log['provider_search_id']); ?></small><?php endif; ?></td>
                            <td><?php echo !empty($log['request_attempted']) ? absint($log['http_code'] ?? 0) : '—'; ?></td>
                            <td><?php echo number_format_i18n(absint($log['raw_result_count'] ?? 0)); ?></td>
                            <td><?php echo number_format_i18n(absint($log['normalized_result_count'] ?? 0)); ?></td>
                            <td><?php echo number_format_i18n(absint($log['saved_result_count'] ?? 0)); ?></td>
                            <td><?php echo !empty($log['duration_ms']) ? number_format_i18n(absint($log['duration_ms'])) . ' ms' : '—'; ?></td>
                            <td><?php echo !empty($log['error_message']) ? esc_html(wp_trim_words((string) $log['error_message'], 18)) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$logs) : ?><tr><td colspan="11">Todavía no hay consultas registradas con el nuevo log.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>

            <details class="seo-ojeador-box seo-ojeador-config">
                <summary>Conexión Google Shopping y ritmo</summary>
                <p class="seo-ojeador-muted">Ojeador sólo consulta categorías con Google Esquema en <code>approved</code> y <code>shopping_query</code> no vacía. Mientras falte cobertura inicial no repite categorías ya cubiertas. El orden dentro de cada grupo es: clics de Analista, impresiones, número de productos y antigüedad.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('seo_ojeador_settings_save'); ?>
                    <input type="hidden" name="action" value="seo_ojeador_settings_save">
                    <div class="seo-ojeador-grid">
                        <label><strong>SerpApi API key</strong><input type="password" name="ojeador[api_key]" value="" placeholder="<?php echo esc_attr($settings['api_key'] !== '' ? 'Configurada · dejar vacío para conservar' : 'API key'); ?>" autocomplete="new-password"></label>
                        <label><strong>Actualizar cada</strong><input type="number" min="6" max="2160" name="ojeador[interval_hours]" value="<?php echo absint($settings['interval_hours']); ?>"><small>Horas por categoría. 720 = aproximadamente mensual.</small></label>
                        <label><strong>Categorías por paso del worker</strong><input type="number" min="1" max="20" name="ojeador[batch_size]" value="<?php echo absint($settings['batch_size']); ?>"><small>Controla cuántas categorías procesa cada pulso. Las consultas idénticas pueden reutilizarse.</small></label>
                        <label><strong>Reutilizar consulta durante</strong><input type="number" min="1" max="168" name="ojeador[query_reuse_hours]" value="<?php echo absint($settings['query_reuse_hours']); ?>"><small>Horas. 24 evita repetir en el mismo día una consulta idéntica ya guardada.</small></label>
                        <label><strong>Límite mensual local</strong><input type="number" min="1" max="1000000" name="ojeador[monthly_query_limit]" value="<?php echo absint($settings['monthly_query_limit']); ?>"><small>Techo de seguridad de Ojeador. El uso real se contrasta con SerpApi Account API; las respuestas de caché no se contabilizan como búsquedas del proveedor.</small></label>
                    </div>
                    <p><label><input type="checkbox" name="ojeador[auto_enabled]" value="1" <?php checked(!empty($settings['auto_enabled'])); ?>> Mantener el mercado de categorías actualizado automáticamente</label></p>
                    <p><button class="button button-primary" type="submit">Guardar</button></p>
                </form>
                <p><strong>Resultados por consulta:</strong> sin límite interno. Se combinan y deduplican los resultados principales y los bloques categorizados devueltos por Google en la misma respuesta.</p>
                <p><code>define('SEO_OJEADOR_SERPAPI_KEY', 'tu_api_key');</code></p>
            </details>
        </div>
        <?php
    }
}
