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
        add_action('admin_post_seo_ojeador_export_analysis_json', array(__CLASS__, 'export_analysis_json'));
        add_action('admin_post_seo_ojeador_export_stars_json', array(__CLASS__, 'export_stars_json'));
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
        $analysis_export = SEO_Ojeador_Analysis::dashboard(200);
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
        echo ',"analysis":' . wp_json_encode($analysis_export, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

    private static function stream_analysis_products_json() {
        global $wpdb;
        $table = SEO_Ojeador_DB::table('category_results');
        $categories = SEO_Ojeador_DB::table('categories');
        $offset = 0;
        $chunk = 500;
        $first = true;
        echo '[';
        do {
            $rows = $wpdb->get_results(
                "SELECT r.id,r.term_id,COALESCE(NULLIF(c.category_name,''),t.name) AS category_name,
                        c.query_text,r.google_product_id,r.gtin,r.mpn,r.brand,r.model,r.title,
                        r.merchant,r.price,r.old_price,r.currency,
                        CASE WHEN r.old_price>r.price AND r.old_price>0 THEN ROUND(((r.old_price-r.price)/r.old_price)*100,2) ELSE NULL END AS discount_pct,
                        r.delivery,r.rating,r.reviews,r.result_position,r.product_url,r.image_url,r.observed_at
                 FROM {$table} r
                 LEFT JOIN {$categories} c ON c.term_id=r.term_id
                 LEFT JOIN {$wpdb->terms} t ON t.term_id=r.term_id
                 WHERE r.active=1 AND c.status='ok'
                 ORDER BY r.term_id ASC,r.result_position ASC,r.id ASC
                 LIMIT {$chunk} OFFSET {$offset}",
                ARRAY_A
            );
            foreach ((array) $rows as $row) {
                if (!$first) echo ',';
                echo wp_json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $first = false;
            }
            $count = count((array) $rows);
            $offset += $count;
        } while ($count === $chunk);
        echo ']';
    }

    public static function export_analysis_json() {
        self::guard('seo_ojeador_export_analysis_json');
        $analysis = SEO_Ojeador_Analysis::dashboard(500);

        self::json_headers('ojeador-analisis-kpis-' . gmdate('Ymd-His') . '.json');
        echo '{';
        echo '"meta":' . wp_json_encode(array(
            'schema' => 'seo_ojeador_analysis_export',
            'schema_version' => '2.1.0',
            'generated_at_utc' => gmdate('c'),
            'site_url' => home_url('/'),
            'ojeador_version' => defined('SEO_OJEADOR_VERSION') ? SEO_OJEADOR_VERSION : '',
            'scope' => 'analysis_kpis_recommendations_comparison_and_star_products',
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo ',"kpis":' . wp_json_encode((array) ($analysis['kpis'] ?? array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo ',"thresholds":' . wp_json_encode((array) ($analysis['thresholds'] ?? array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo ',"operational_recommendations":' . wp_json_encode((array) ($analysis['operational_recommendations'] ?? array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo ',"recommendations":' . wp_json_encode((array) ($analysis['recommendations'] ?? array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo ',"categories":' . wp_json_encode((array) ($analysis['categories'] ?? array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo ',"merchants":' . wp_json_encode((array) ($analysis['merchants'] ?? array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo ',"products_comparison":';
        self::stream_analysis_products_json();
        $stars = SEO_Ojeador_Stars::export_data(false);
        echo ',"star_kpis":' . wp_json_encode((array) ($stars['kpis'] ?? array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo ',"star_products":' . wp_json_encode(array_slice((array) ($stars['products'] ?? array()), 0, 500), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo ',"notes":' . wp_json_encode((array) ($analysis['notes'] ?? array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo '}';
        exit;
    }

    public static function export_stars_json() {
        self::guard('seo_ojeador_export_stars_json');
        $stars = SEO_Ojeador_Stars::export_data(true);
        self::json_headers('ojeador-productos-estrella-' . gmdate('Ymd-His') . '.json');
        echo wp_json_encode(array(
            'meta' => array(
                'schema' => 'seo_ojeador_star_products',
                'schema_version' => '1.0.0',
                'generated_at_utc' => gmdate('c'),
                'site_url' => home_url('/'),
                'ojeador_version' => defined('SEO_OJEADOR_VERSION') ? SEO_OJEADOR_VERSION : '',
                'markup_rule_pct' => 20,
            ),
            'kpis' => (array) ($stars['kpis'] ?? array()),
            'thresholds' => (array) ($stars['thresholds'] ?? array()),
            'products' => (array) ($stars['products'] ?? array()),
            'notes' => (array) ($stars['notes'] ?? array()),
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

    private static function analysis_tone_color($tone) {
        switch (sanitize_key((string) $tone)) {
            case 'danger': return '#b32d2e';
            case 'warning': return '#996800';
            case 'opportunity': return '#008a20';
            case 'info': return '#2271b1';
            case 'ok': return '#008a20';
            default: return '#646970';
        }
    }

    private static function render_analysis($analysis) {
        $analysis = is_array($analysis) ? $analysis : array();
        $sum = (array) ($analysis['kpis'] ?? array());
        $operational = (array) ($analysis['operational_recommendations'] ?? array());
        $recommendations = (array) ($analysis['recommendations'] ?? array());
        $categories = (array) ($analysis['categories'] ?? array());
        $merchants = (array) ($analysis['merchants'] ?? array());
        ?>
        <section class="seo-ojeador-analysis">
            <div style="margin:20px 0 8px">
                <h2 style="margin:0">Qué está diciendo el mercado</h2>
                <p class="seo-ojeador-muted" style="margin:4px 0 0">KPIs orientados a decisión. Los resultados de Google Shopping son una muestra por consulta, no una estimación del tamaño absoluto del mercado.</p>
            </div>

            <div class="seo-ojeador-cards seo-ojeador-analysis-cards">
                <div class="seo-ojeador-card"><strong><?php echo esc_html(number_format_i18n((float) ($sum['valid_market_coverage_pct'] ?? 0), 1)); ?>%</strong><span>Cobertura de mercado válida<br><small><?php echo number_format_i18n(absint($sum['categories_with_market'] ?? 0)); ?> / <?php echo number_format_i18n(absint($sum['categories_eligible'] ?? 0)); ?> categorías aptas</small></span></div>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n(absint($sum['categories_pending_first_scan'] ?? 0)); ?></strong><span>Pendientes de primera instantánea</span></div>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n(absint($sum['categories_error'] ?? 0)); ?></strong><span>Consultas con error</span></div>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n(absint($sum['provider_remaining'] ?? 0)); ?> / <?php echo number_format_i18n(absint($sum['provider_limit'] ?? 0)); ?></strong><span>Consultas disponibles</span></div>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n(absint($sum['active_results'] ?? 0)); ?></strong><span>Productos de mercado observados</span></div>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n(absint($sum['unique_merchants'] ?? 0)); ?></strong><span>Comercios distintos</span></div>
                <div class="seo-ojeador-card"><strong><?php echo esc_html(number_format_i18n((float) ($sum['median_merchants_per_category'] ?? 0), 1)); ?></strong><span>Mediana de comercios / categoría</span></div>
                <div class="seo-ojeador-card seo-ojeador-kpi-action"><strong><?php echo number_format_i18n(absint($sum['catalog_gap_candidates'] ?? 0)); ?></strong><span>Huecos de catálogo a revisar</span></div>
                <div class="seo-ojeador-card seo-ojeador-kpi-action"><strong><?php echo number_format_i18n(absint($sum['high_visibility_categories'] ?? 0)); ?></strong><span>Categorías con visibilidad interna alta</span></div>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n(absint($sum['high_promo_categories'] ?? 0)); ?></strong><span>Presión promocional alta</span></div>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n(absint($sum['concentrated_categories'] ?? 0)); ?></strong><span>Oferta concentrada</span></div>
                <div class="seo-ojeador-card"><strong><?php echo esc_html(number_format_i18n((float) ($sum['avg_price_data_coverage_pct'] ?? 0), 1)); ?>%</strong><span>Cobertura media de precio</span></div>
            </div>

            <?php if ($operational) : ?>
                <div style="margin:22px 0 8px"><h3 style="margin:0">Bloqueos operativos</h3><p class="seo-ojeador-muted" style="margin:4px 0 0">Antes de interpretar categorías pendientes, resuelve estos puntos.</p></div>
                <div class="seo-ojeador-action-grid">
                    <?php foreach ($operational as $rec) : $tone = self::analysis_tone_color($rec['tone'] ?? ''); ?>
                        <div class="seo-ojeador-box" style="border-left:4px solid <?php echo esc_attr($tone); ?>">
                            <strong><?php echo esc_html((string) ($rec['signal'] ?? '')); ?></strong>
                            <p style="margin:7px 0"><strong>Acción:</strong> <?php echo esc_html((string) ($rec['action'] ?? '')); ?></p>
                            <small class="seo-ojeador-muted"><?php echo esc_html((string) ($rec['reason'] ?? '')); ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div style="display:flex;justify-content:space-between;gap:12px;align-items:end;flex-wrap:wrap;margin:24px 0 8px">
                <div><h3 style="margin:0">Recomendaciones comerciales</h3><p class="seo-ojeador-muted" style="margin:4px 0 0">Sólo aparecen categorías que activan una regla concreta. Una categoría puede generar varias recomendaciones.</p></div>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE . '&ojeador_tab=products')); ?>">Abrir comparación de productos</a>
            </div>
            <div class="seo-ojeador-table">
                <table class="widefat striped">
                    <thead><tr><th>Prioridad</th><th>Categoría</th><th>Índice oportunidad</th><th>Señal</th><th>Qué hacer</th><th>Por qué</th><th>Datos clave</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($recommendations, 0, 50) as $rec) : $tone = self::analysis_tone_color($rec['tone'] ?? ''); ?>
                        <tr>
                            <td><strong style="color:<?php echo esc_attr($tone); ?>"><?php echo number_format_i18n(absint($rec['priority'] ?? 0)); ?></strong></td>
                            <td><strong><?php echo esc_html((string) ($rec['category_name'] ?? '')); ?></strong><br><small class="seo-ojeador-muted"><?php echo esc_html((string) ($rec['query_text'] ?? '')); ?></small></td>
                            <td><strong><?php echo esc_html(number_format_i18n((float) ($rec['opportunity_index'] ?? 0), 1)); ?></strong><br><small>gap <?php echo esc_html(number_format_i18n((float) ($rec['catalog_gap_index'] ?? 0), 0)); ?> · comp. <?php echo esc_html(number_format_i18n((float) ($rec['competition_index'] ?? 0), 0)); ?> · vis. <?php echo esc_html(number_format_i18n((float) ($rec['visibility_index'] ?? 0), 0)); ?></small></td>
                            <td><span class="seo-ojeador-pill" style="color:<?php echo esc_attr($tone); ?>"><?php echo esc_html((string) ($rec['signal'] ?? '')); ?></span></td>
                            <td><strong><?php echo esc_html((string) ($rec['action'] ?? '')); ?></strong></td>
                            <td><?php echo esc_html((string) ($rec['reason'] ?? '')); ?></td>
                            <td><small><?php echo number_format_i18n(absint($rec['woo_product_count'] ?? 0)); ?> prod. propios · <?php echo number_format_i18n(absint($rec['merchant_count'] ?? 0)); ?> comercios · <?php echo number_format_i18n((float) ($rec['analista_impressions'] ?? 0), 0); ?> imp.</small></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recommendations) : ?><tr><td colspan="7">No hay recomendaciones comerciales prioritarias con los datos actuales.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>

            <details class="seo-ojeador-box seo-ojeador-analysis-detail" style="margin-top:18px">
                <summary>Índices por categoría</summary>
                <p class="seo-ojeador-muted">El índice de oportunidad es una prioridad interna, no una previsión de ventas. La profundidad de catálogo se compara con nuestras propias categorías; competencia usa comercios observados; visibilidad usa impresiones de Analista; reseñas usa volumen de reviews.</p>
                <div class="seo-ojeador-table" style="margin-top:10px">
                    <table class="widefat striped">
                        <thead><tr><th>Categoría</th><th>Productos propios</th><th>Comercios</th><th>Analista 28 d</th><th>Oportunidad</th><th>Gap catálogo</th><th>Competencia</th><th>Visibilidad</th><th>Reviews</th><th>Promo</th><th>Concentración</th><th>Acción principal</th></tr></thead>
                        <tbody>
                        <?php foreach ($categories as $row) : if (empty($row['ojeador_eligible']) || (string) ($row['market_status'] ?? '') !== 'ok') continue; $tone = self::analysis_tone_color($row['analysis_tone'] ?? ''); ?>
                            <tr>
                                <td><strong><?php echo esc_html((string) (($row['category_name'] ?? '') ?: ($row['woo_category'] ?? ''))); ?></strong></td>
                                <td><?php echo number_format_i18n(absint($row['woo_product_count'] ?? 0)); ?></td>
                                <td><?php echo number_format_i18n(absint($row['merchant_count'] ?? 0)); ?></td>
                                <td><?php echo number_format_i18n((float) ($row['analista_impressions'] ?? 0), 0); ?> imp.</td>
                                <td><strong><?php echo esc_html(number_format_i18n((float) ($row['opportunity_index'] ?? 0), 1)); ?></strong></td>
                                <td><?php echo esc_html(number_format_i18n((float) ($row['catalog_gap_index'] ?? 0), 0)); ?></td>
                                <td><?php echo esc_html(number_format_i18n((float) ($row['competition_index'] ?? 0), 0)); ?></td>
                                <td><?php echo esc_html(number_format_i18n((float) ($row['visibility_index'] ?? 0), 0)); ?></td>
                                <td><?php echo esc_html(number_format_i18n((float) ($row['review_index'] ?? 0), 0)); ?></td>
                                <td><?php echo esc_html(number_format_i18n((float) ($row['promo_index'] ?? 0), 0)); ?></td>
                                <td><?php echo esc_html(number_format_i18n((float) ($row['concentration_index'] ?? 0), 0)); ?></td>
                                <td><span class="seo-ojeador-pill" style="color:<?php echo esc_attr($tone); ?>"><?php echo esc_html((string) ($row['analysis_signal'] ?? '')); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>

            <details class="seo-ojeador-box seo-ojeador-analysis-detail" style="margin-top:12px">
                <summary>Comercios más visibles en la muestra</summary>
                <div class="seo-ojeador-table" style="margin-top:10px">
                    <table class="widefat striped">
                        <thead><tr><th>Comercio</th><th>Apariciones</th><th>Categorías</th><th>Con precio</th><th>Con descuento</th></tr></thead>
                        <tbody>
                        <?php foreach ($merchants as $merchant) : ?>
                            <tr><td><strong><?php echo esc_html((string) ($merchant['merchant'] ?? '')); ?></strong></td><td><?php echo number_format_i18n(absint($merchant['appearances'] ?? 0)); ?></td><td><?php echo number_format_i18n(absint($merchant['categories'] ?? 0)); ?></td><td><?php echo number_format_i18n(absint($merchant['rows_with_price'] ?? 0)); ?></td><td><?php echo number_format_i18n(absint($merchant['discounted_rows'] ?? 0)); ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        </section>
        <?php
    }

    private static function render_stars($stars) {
        $stars = is_array($stars) ? $stars : array();
        $rows = (array) ($stars['rows'] ?? array());
        $kpis = (array) ($stars['kpis'] ?? array());
        $filters = (array) ($stars['filters'] ?? array());
        $providers = (array) ($stars['provider_options'] ?? array());
        $categories = (array) ($stars['category_options'] ?? array());
        $classes = (array) ($stars['class_options'] ?? array());
        $page = absint($stars['page'] ?? 1);
        $pages = absint($stars['pages'] ?? 1);
        $total = absint($stars['total'] ?? 0);

        $base_args = array(
            'page' => self::PAGE,
            'ojeador_tab' => 'stars',
            'sq' => (string) ($filters['q'] ?? ''),
            'sprovider' => (string) ($filters['provider'] ?? ''),
            'scat' => absint($filters['term_id'] ?? 0),
            'sclass' => (string) ($filters['class'] ?? ''),
            'sminscore' => (string) ($filters['min_score'] ?? ''),
            'sminimp' => (string) ($filters['min_impressions'] ?? ''),
            'smaxdisc' => (string) ($filters['max_supplier_discount'] ?? ''),
            'ssort' => (string) ($filters['sort'] ?? 'score'),
            'sper' => absint($filters['per_page'] ?? 100),
        );
        ?>
        <section class="seo-ojeador-stars">
            <div style="display:flex;justify-content:space-between;gap:12px;align-items:end;flex-wrap:wrap;margin:20px 0 8px">
                <div>
                    <h2 style="margin:0">Productos estrella</h2>
                    <p class="seo-ojeador-muted" style="margin:4px 0 0">Cruza coste de proveedor, precio objetivo con +20%, comparables de Google Shopping y visibilidad de Analista. No modifica precios automáticamente.</p>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <a class="button" href="<?php echo esc_url(add_query_arg(array('page'=>self::PAGE,'ojeador_tab'=>'stars','stars_refresh'=>1), admin_url('admin.php'))); ?>">Recalcular</a>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('seo_ojeador_export_stars_json'); ?><input type="hidden" name="action" value="seo_ojeador_export_stars_json"><button class="button" type="submit">Exportar JSON estrellas</button></form>
                </div>
            </div>

            <div class="seo-ojeador-cards">
                <div class="seo-ojeador-card seo-ojeador-star-strong"><strong><?php echo number_format_i18n(absint($kpis['stars'] ?? 0)); ?></strong><span>Estrella<br><small>Precio claramente competitivo</small></span></div>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n(absint($kpis['good_price'] ?? 0)); ?></strong><span>Buen precio<br><small>Compite sin descuento extra</small></span></div>
                <div class="seo-ojeador-card seo-ojeador-kpi-action"><strong><?php echo number_format_i18n(absint($kpis['offer_recommended'] ?? 0)); ?></strong><span>Oferta recomendada<br><small>Visibilidad + descuento asumible</small></span></div>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n(absint($kpis['discount_potential'] ?? 0)); ?></strong><span>Potencial con descuento</span></div>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n(absint($kpis['high_visibility'] ?? 0)); ?></strong><span>Alta visibilidad<br><small>Revisar oferta manualmente</small></span></div>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n(absint($kpis['vevor_candidates'] ?? 0)); ?></strong><span>Candidatos VEVOR</span></div>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n(absint($kpis['candidates_total'] ?? 0)); ?></strong><span>Candidatos totales</span></div>
            </div>

            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="seo-ojeador-box">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>">
                <input type="hidden" name="ojeador_tab" value="stars">
                <div class="seo-ojeador-filter-grid">
                    <label><strong>Producto</strong><input type="search" name="sq" value="<?php echo esc_attr((string) ($filters['q'] ?? '')); ?>" placeholder="Nombre, SKU o comparable"></label>
                    <label><strong>Proveedor</strong><select name="sprovider"><option value="">Todos</option><?php foreach ($providers as $opt) : ?><option value="<?php echo esc_attr((string) ($opt['provider'] ?? '')); ?>" <?php selected((string) ($filters['provider'] ?? ''), (string) ($opt['provider'] ?? '')); ?>><?php echo esc_html((string) ($opt['provider'] ?? '')); ?> (<?php echo number_format_i18n(absint($opt['count'] ?? 0)); ?>)</option><?php endforeach; ?></select></label>
                    <label><strong>Categoría</strong><select name="scat"><option value="0">Todas</option><?php foreach ($categories as $opt) : ?><option value="<?php echo absint($opt['term_id'] ?? 0); ?>" <?php selected(absint($filters['term_id'] ?? 0), absint($opt['term_id'] ?? 0)); ?>><?php echo esc_html((string) ($opt['category_name'] ?? '')); ?> (<?php echo number_format_i18n(absint($opt['count'] ?? 0)); ?>)</option><?php endforeach; ?></select></label>
                    <label><strong>Clasificación</strong><select name="sclass"><option value="">Todas</option><?php foreach ($classes as $key=>$label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected((string) ($filters['class'] ?? ''), $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
                    <label><strong>Score mínimo</strong><input type="number" step="1" min="0" max="100" name="sminscore" value="<?php echo esc_attr((string) ($filters['min_score'] ?? '')); ?>"></label>
                    <label><strong>Impresiones mín.</strong><input type="number" step="1" min="0" name="sminimp" value="<?php echo esc_attr((string) ($filters['min_impressions'] ?? '')); ?>"></label>
                    <label><strong>Descuento proveedor máx.</strong><input type="number" step="0.1" min="0" max="100" name="smaxdisc" value="<?php echo esc_attr((string) ($filters['max_supplier_discount'] ?? '')); ?>" placeholder="Ej. 15"></label>
                    <label><strong>Orden</strong><select name="ssort"><?php $sorts=array('score'=>'Score estrella','advantage'=>'Ventaja de precio','impressions'=>'Visibilidad','reviews'=>'Reviews mercado','discount_needed'=>'Menor descuento necesario','provider'=>'Proveedor'); foreach ($sorts as $key=>$label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected((string) ($filters['sort'] ?? 'score'), $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
                    <label><strong>Filas</strong><select name="sper"><?php foreach (array(50,100,200) as $n) : ?><option value="<?php echo $n; ?>" <?php selected(absint($filters['per_page'] ?? 100), $n); ?>><?php echo $n; ?></option><?php endforeach; ?></select></label>
                </div>
                <p style="margin-bottom:0"><button class="button button-primary">Aplicar filtros</button> <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE . '&ojeador_tab=stars')); ?>">Limpiar</a> <span class="seo-ojeador-muted" style="margin-left:8px"><?php echo number_format_i18n($total); ?> candidatos</span></p>
            </form>

            <div class="seo-ojeador-table" style="margin-top:14px">
                <table class="widefat striped">
                    <thead><tr><th>Clasificación</th><th>Nuestro producto</th><th>Categoría</th><th>Coste / precio</th><th>Comparable mercado</th><th>Ventaja</th><th>Visibilidad</th><th>Evidencia</th><th>Acción</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row) :
                        $class = sanitize_key((string) ($row['star_class'] ?? ''));
                        $tone = $class === 'estrella' ? '#008a20' : ($class === 'oferta_recomendada' ? '#2271b1' : ($class === 'alta_visibilidad' ? '#996800' : '#50575e'));
                        $cost = isset($row['supplier_cost']) && is_numeric($row['supplier_cost']) ? (float) $row['supplier_cost'] : null;
                        $target = isset($row['target_price_20']) && is_numeric($row['target_price_20']) ? (float) $row['target_price_20'] : null;
                        $current = isset($row['current_price']) && is_numeric($row['current_price']) ? (float) $row['current_price'] : null;
                        $benchmark = isset($row['market_benchmark_price']) && is_numeric($row['market_benchmark_price']) ? (float) $row['market_benchmark_price'] : null;
                        $adv = isset($row['price_advantage_target_pct']) && is_numeric($row['price_advantage_target_pct']) ? (float) $row['price_advantage_target_pct'] : null;
                        $disc = isset($row['supplier_discount_to_5pct_below_pct']) && is_numeric($row['supplier_discount_to_5pct_below_pct']) ? (float) $row['supplier_discount_to_5pct_below_pct'] : null;
                        $rating = isset($row['market_rating']) && is_numeric($row['market_rating']) ? (float) $row['market_rating'] : null;
                    ?>
                        <tr>
                            <td><strong style="color:<?php echo esc_attr($tone); ?>"><?php echo esc_html((string) ($row['star_label'] ?? '')); ?></strong><br><small>Score <?php echo esc_html(number_format_i18n((float) ($row['star_score'] ?? 0), 1)); ?>/100</small></td>
                            <td><a href="<?php echo esc_url(admin_url('post.php?post=' . absint($row['object_id'] ?? 0) . '&action=edit')); ?>"><strong><?php echo esc_html((string) ($row['product_name'] ?? '')); ?></strong></a><br><small class="seo-ojeador-muted"><?php echo esc_html((string) ($row['provider'] ?? '—')); ?><?php if (!empty($row['supplier_sku'])) : ?> · SKU <?php echo esc_html((string) $row['supplier_sku']); ?><?php endif; ?></small></td>
                            <td><?php echo esc_html((string) ($row['category_name'] ?? '')); ?></td>
                            <td><?php if ($cost !== null) : ?>Coste: <strong><?php echo esc_html(number_format_i18n($cost,2)); ?> €</strong><?php endif; ?><br><?php if ($target !== null) : ?>+20%: <strong><?php echo esc_html(number_format_i18n($target,2)); ?> €</strong><?php endif; ?><?php if ($current !== null) : ?><br><small>Actual: <?php echo esc_html(number_format_i18n($current,2)); ?> €</small><?php endif; ?><?php if (stripos((string) ($row['provider'] ?? ''), 'vevor') !== false && !empty($row['supplier_discount_scenarios'])) : $sc=(array) $row['supplier_discount_scenarios']; ?><br><small class="seo-ojeador-muted">VEVOR: -10% → <?php echo esc_html(number_format_i18n((float) ($sc['10_pct'] ?? 0),2)); ?> € · -15% → <?php echo esc_html(number_format_i18n((float) ($sc['15_pct'] ?? 0),2)); ?> €</small><?php endif; ?></td>
                            <td><?php if ($benchmark !== null) : ?><strong><?php echo esc_html(number_format_i18n($benchmark,2)); ?> €</strong><br><small><?php echo esc_html((string) ($row['benchmark_source'] ?? '')); ?> · <?php echo number_format_i18n(absint($row['comparable_count'] ?? 0)); ?> comparables</small><?php else : ?>—<?php endif; ?><?php if (!empty($row['market_title'])) : ?><br><small class="seo-ojeador-muted"><?php echo esc_html(wp_trim_words((string) $row['market_title'], 14, '…')); ?></small><?php endif; ?></td>
                            <td><?php if ($adv !== null) : ?><strong><?php echo $adv >= 0 ? '+' : ''; ?><?php echo esc_html(number_format_i18n($adv,1)); ?>%</strong><br><small>vs. comparable</small><?php else : ?>—<?php endif; ?><?php if ($disc !== null && $disc > 0) : ?><br><small>Compra necesaria: -<?php echo esc_html(number_format_i18n($disc,1)); ?>%</small><?php endif; ?></td>
                            <td><strong><?php echo number_format_i18n((float) ($row['product_impressions'] ?? 0),0); ?></strong> imp. producto<br><small><?php echo number_format_i18n((float) ($row['category_impressions'] ?? 0),0); ?> imp. categoría</small></td>
                            <td><?php echo $rating !== null ? esc_html(number_format_i18n($rating,1)) . '/5' : '—'; ?><br><small><?php echo number_format_i18n(absint($row['market_reviews'] ?? 0)); ?> reviews · posición <?php echo number_format_i18n(absint($row['market_position'] ?? 0)); ?></small><br><small>Match <?php echo esc_html(number_format_i18n((float) ($row['match_confidence_pct'] ?? 0),0)); ?>%</small></td>
                            <td><strong><?php echo esc_html((string) ($row['recommendation'] ?? '')); ?></strong><br><small class="seo-ojeador-muted"><?php echo esc_html((string) ($row['reason'] ?? '')); ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows) : ?><tr><td colspan="9">No hay candidatos que cumplan estos filtros. Si acabas de actualizar precios o mercado, pulsa «Recalcular».</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pages > 1) : ?>
                <div class="tablenav"><div class="tablenav-pages"><span class="displaying-num"><?php echo number_format_i18n($total); ?> candidatos</span>
                    <?php if ($page > 1) : $prev=$base_args; $prev['spage']=$page-1; ?><a class="button" href="<?php echo esc_url(add_query_arg($prev, admin_url('admin.php'))); ?>">‹ Anterior</a><?php endif; ?>
                    <span class="paging-input"><?php echo number_format_i18n($page); ?> de <span class="total-pages"><?php echo number_format_i18n($pages); ?></span></span>
                    <?php if ($page < $pages) : $next=$base_args; $next['spage']=$page+1; ?><a class="button" href="<?php echo esc_url(add_query_arg($next, admin_url('admin.php'))); ?>">Siguiente ›</a><?php endif; ?>
                </div></div>
            <?php endif; ?>
        </section>
        <?php
    }

    private static function render_products($comparison, $category_options) {
        $comparison = is_array($comparison) ? $comparison : array();
        $rows = (array) ($comparison['rows'] ?? array());
        $filters = (array) ($comparison['filters'] ?? array());
        $page = absint($comparison['page'] ?? 1);
        $pages = absint($comparison['pages'] ?? 1);
        $total = absint($comparison['total'] ?? 0);
        $base_args = array(
            'page' => self::PAGE,
            'ojeador_tab' => 'products',
            'pcat' => absint($filters['term_id'] ?? 0),
            'pq' => (string) ($filters['q'] ?? ''),
            'pmerchant' => (string) ($filters['merchant'] ?? ''),
            'pmin' => (string) ($filters['min_price'] ?? ''),
            'pmax' => (string) ($filters['max_price'] ?? ''),
            'prating' => (string) ($filters['min_rating'] ?? ''),
            'previews' => (string) ($filters['min_reviews'] ?? ''),
            'pdiscount' => !empty($filters['discounted']) ? 1 : 0,
            'psort' => (string) ($filters['sort'] ?? 'position'),
            'pper' => absint($filters['per_page'] ?? 100),
        );
        ?>
        <section class="seo-ojeador-products">
            <div style="margin:20px 0 8px"><h2 style="margin:0">Comparador de productos encontrados</h2><p class="seo-ojeador-muted" style="margin:4px 0 0">Explora los productos devueltos por Google Shopping. Úsalo para comparar productos equivalentes antes de tomar decisiones de surtido, precio o promoción.</p></div>

            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="seo-ojeador-box seo-ojeador-filterbox">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>">
                <input type="hidden" name="ojeador_tab" value="products">
                <div class="seo-ojeador-filter-grid">
                    <label><strong>Categoría</strong><select name="pcat"><option value="0">Todas</option><?php foreach ((array) $category_options as $opt) : ?><option value="<?php echo absint($opt['term_id'] ?? 0); ?>" <?php selected(absint($filters['term_id'] ?? 0), absint($opt['term_id'] ?? 0)); ?>><?php echo esc_html((string) ($opt['category_name'] ?? '')); ?> (<?php echo number_format_i18n(absint($opt['products_found'] ?? 0)); ?>)</option><?php endforeach; ?></select></label>
                    <label><strong>Producto / marca / modelo</strong><input type="search" name="pq" value="<?php echo esc_attr((string) ($filters['q'] ?? '')); ?>" placeholder="Buscar producto"></label>
                    <label><strong>Comercio</strong><input type="search" name="pmerchant" value="<?php echo esc_attr((string) ($filters['merchant'] ?? '')); ?>" placeholder="Leroy Merlin, Amazon..."></label>
                    <label><strong>Precio mín.</strong><input type="number" step="0.01" min="0" name="pmin" value="<?php echo esc_attr((string) ($filters['min_price'] ?? '')); ?>"></label>
                    <label><strong>Precio máx.</strong><input type="number" step="0.01" min="0" name="pmax" value="<?php echo esc_attr((string) ($filters['max_price'] ?? '')); ?>"></label>
                    <label><strong>Rating mínimo</strong><input type="number" step="0.1" min="0" max="5" name="prating" value="<?php echo esc_attr((string) ($filters['min_rating'] ?? '')); ?>"></label>
                    <label><strong>Reviews mínimas</strong><input type="number" step="1" min="0" name="previews" value="<?php echo esc_attr((string) ($filters['min_reviews'] ?? '')); ?>"></label>
                    <label><strong>Orden</strong><select name="psort">
                        <?php $sorts = array('position'=>'Posición Google','price_asc'=>'Precio ↑','price_desc'=>'Precio ↓','rating_desc'=>'Rating ↓','reviews_desc'=>'Reviews ↓','discount_desc'=>'Descuento ↓','merchant'=>'Comercio','title'=>'Producto','recent'=>'Más reciente'); foreach ($sorts as $key=>$label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected((string) ($filters['sort'] ?? 'position'), $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                    </select></label>
                    <label><strong>Filas</strong><select name="pper"><?php foreach (array(50,100,200) as $n) : ?><option value="<?php echo $n; ?>" <?php selected(absint($filters['per_page'] ?? 100), $n); ?>><?php echo $n; ?></option><?php endforeach; ?></select></label>
                    <label class="seo-ojeador-check"><input type="checkbox" name="pdiscount" value="1" <?php checked(!empty($filters['discounted'])); ?>> Sólo con descuento</label>
                </div>
                <p style="margin-bottom:0"><button class="button button-primary">Aplicar filtros</button> <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE . '&ojeador_tab=products')); ?>">Limpiar</a> <span class="seo-ojeador-muted" style="margin-left:8px"><?php echo number_format_i18n($total); ?> resultados</span></p>
            </form>

            <div class="seo-ojeador-table" style="margin-top:14px">
                <table class="widefat striped">
                    <thead><tr><th>Categoría</th><th>Producto</th><th>Comercio</th><th>Precio</th><th>Descuento</th><th>Rating / reviews</th><th>Posición</th><th>Observado</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row) :
                        $price = ($row['price'] !== null && $row['price'] !== '') ? (float) $row['price'] : null;
                        $old = ($row['old_price'] !== null && $row['old_price'] !== '') ? (float) $row['old_price'] : null;
                        $discount = ($row['discount_pct'] !== null && $row['discount_pct'] !== '') ? (float) $row['discount_pct'] : null;
                        $rating = ($row['rating'] !== null && $row['rating'] !== '') ? (float) $row['rating'] : null;
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html((string) ($row['category_name'] ?? '')); ?></strong></td>
                            <td><?php if (!empty($row['product_url'])) : ?><a href="<?php echo esc_url((string) $row['product_url']); ?>" target="_blank" rel="noopener noreferrer"><strong><?php echo esc_html((string) ($row['title'] ?? '')); ?></strong></a><?php else : ?><strong><?php echo esc_html((string) ($row['title'] ?? '')); ?></strong><?php endif; ?><?php if (!empty($row['brand']) || !empty($row['model'])) : ?><br><small class="seo-ojeador-muted"><?php echo esc_html(trim((string) ($row['brand'] ?? '') . ' ' . (string) ($row['model'] ?? ''))); ?></small><?php endif; ?></td>
                            <td><?php echo esc_html((string) ($row['merchant'] ?? '—')); ?></td>
                            <td><?php echo $price !== null ? esc_html(number_format_i18n($price, 2)) . ' ' . esc_html((string) ($row['currency'] ?? 'EUR')) : '—'; ?><?php if ($old !== null && $old > 0) : ?><br><small class="seo-ojeador-muted"><del><?php echo esc_html(number_format_i18n($old, 2)); ?></del></small><?php endif; ?></td>
                            <td><?php echo $discount !== null ? '<strong>' . esc_html(number_format_i18n($discount, 1)) . '%</strong>' : '—'; ?></td>
                            <td><?php echo $rating !== null ? esc_html(number_format_i18n($rating, 1)) . ' / 5' : '—'; ?><br><small><?php echo number_format_i18n(absint($row['reviews'] ?? 0)); ?> reviews</small></td>
                            <td><?php echo number_format_i18n(absint($row['result_position'] ?? 0)); ?></td>
                            <td><?php echo esc_html((string) ($row['observed_at'] ?? '—')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows) : ?><tr><td colspan="8">No hay productos que cumplan los filtros.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pages > 1) : ?>
                <div class="tablenav"><div class="tablenav-pages"><span class="displaying-num"><?php echo number_format_i18n($total); ?> elementos</span>
                    <?php if ($page > 1) : $prev = $base_args; $prev['ppage']=$page-1; ?><a class="button" href="<?php echo esc_url(add_query_arg($prev, admin_url('admin.php'))); ?>">‹ Anterior</a><?php endif; ?>
                    <span class="paging-input"><?php echo number_format_i18n($page); ?> de <span class="total-pages"><?php echo number_format_i18n($pages); ?></span></span>
                    <?php if ($page < $pages) : $next = $base_args; $next['ppage']=$page+1; ?><a class="button" href="<?php echo esc_url(add_query_arg($next, admin_url('admin.php'))); ?>">Siguiente ›</a><?php endif; ?>
                </div></div>
            <?php endif; ?>
        </section>
        <?php
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $tab = sanitize_key((string) ($_GET['ojeador_tab'] ?? 'analysis'));
        if (!in_array($tab, array('analysis','stars','products','operation'), true)) {
            $tab = 'analysis';
        }

        $settings = SEO_Ojeador_Shopping::settings();
        $usage = SEO_Ojeador_Shopping::usage_month();
        $ready = SEO_Ojeador_Shopping::readiness();
        $run = SEO_Ojeador_DB::active_run();
        if (!$run) $run = SEO_Ojeador_DB::latest_run();
        $notice = sanitize_key((string) ($_GET['ojeador_notice'] ?? ''));
        $error = isset($_GET['ojeador_error']) ? sanitize_text_field(rawurldecode((string) $_GET['ojeador_error'])) : '';

        $analysis = array();
        $stars = array();
        $comparison = array();
        $category_options = array();
        $summary = array();
        $rows = array();
        $logs = array();
        $latest_log = array();
        $search = '';

        if ($tab === 'analysis') {
            $analysis = SEO_Ojeador_Analysis::dashboard(100);
        } elseif ($tab === 'stars') {
            $stars = SEO_Ojeador_Stars::dashboard(array(
                'q' => sanitize_text_field(wp_unslash($_GET['sq'] ?? '')),
                'provider' => sanitize_text_field(wp_unslash($_GET['sprovider'] ?? '')),
                'term_id' => absint($_GET['scat'] ?? 0),
                'class' => sanitize_key((string) ($_GET['sclass'] ?? '')),
                'min_score' => isset($_GET['sminscore']) ? sanitize_text_field(wp_unslash($_GET['sminscore'])) : '',
                'min_impressions' => isset($_GET['sminimp']) ? sanitize_text_field(wp_unslash($_GET['sminimp'])) : '',
                'max_supplier_discount' => isset($_GET['smaxdisc']) ? sanitize_text_field(wp_unslash($_GET['smaxdisc'])) : '',
                'sort' => sanitize_key((string) ($_GET['ssort'] ?? 'score')),
                'page' => absint($_GET['spage'] ?? 1),
                'per_page' => absint($_GET['sper'] ?? 100),
                'refresh' => !empty($_GET['stars_refresh']) ? 1 : 0,
            ));
        } elseif ($tab === 'products') {
            $comparison = SEO_Ojeador_Analysis::comparison_products(array(
                'term_id' => absint($_GET['pcat'] ?? 0),
                'q' => sanitize_text_field(wp_unslash($_GET['pq'] ?? '')),
                'merchant' => sanitize_text_field(wp_unslash($_GET['pmerchant'] ?? '')),
                'min_price' => isset($_GET['pmin']) ? sanitize_text_field(wp_unslash($_GET['pmin'])) : '',
                'max_price' => isset($_GET['pmax']) ? sanitize_text_field(wp_unslash($_GET['pmax'])) : '',
                'min_rating' => isset($_GET['prating']) ? sanitize_text_field(wp_unslash($_GET['prating'])) : '',
                'min_reviews' => isset($_GET['previews']) ? sanitize_text_field(wp_unslash($_GET['previews'])) : '',
                'discounted' => !empty($_GET['pdiscount']) ? 1 : 0,
                'sort' => sanitize_key((string) ($_GET['psort'] ?? 'position')),
                'page' => absint($_GET['ppage'] ?? 1),
                'per_page' => absint($_GET['pper'] ?? 100),
            ));
            $category_options = SEO_Ojeador_Analysis::comparison_category_options();
        } else {
            $summary = SEO_Ojeador_DB::category_summary();
            $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
            $rows = SEO_Ojeador_DB::list_market_categories(array('limit'=>1000, 'search'=>$search));
            $logs = SEO_Ojeador_DB::list_query_logs(100);
            $latest_log = SEO_Ojeador_DB::latest_query_log();
        }
        ?>
        <div class="wrap seo-ojeador-v090">
            <style>
                .seo-ojeador-v090{max-width:1600px}.seo-ojeador-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap}
                .seo-ojeador-sub{color:#646970;margin-top:5px;max-width:1000px}.seo-ojeador-actions{display:flex;gap:8px;flex-wrap:wrap}.seo-ojeador-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:10px;margin:18px 0}
                .seo-ojeador-card,.seo-ojeador-box{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:15px}.seo-ojeador-card strong{display:block;font-size:24px;line-height:1.1}.seo-ojeador-card span{color:#646970}.seo-ojeador-card small{font-size:11px}.seo-ojeador-kpi-action{border-left:4px solid #008a20}
                .seo-ojeador-table{overflow:auto;background:#fff;border:1px solid #dcdcde;border-radius:8px}.seo-ojeador-table table{border:0;margin:0}.seo-ojeador-table td{vertical-align:top}.seo-ojeador-muted{color:#646970}.seo-ojeador-pill{font-weight:600;white-space:nowrap}
                .seo-ojeador-run{margin:12px 0 0;padding:10px 12px;background:#f6f7f7;border-radius:6px}.seo-ojeador-config{margin-top:18px}.seo-ojeador-config summary{cursor:pointer;font-weight:600;font-size:15px}
                .seo-ojeador-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;margin-top:14px}.seo-ojeador-grid label{display:block}.seo-ojeador-grid input{width:100%;margin-top:5px}.seo-ojeador-grid small{display:block;color:#646970;margin-top:4px}.seo-ojeador-analysis-detail summary{cursor:pointer;font-weight:600;font-size:15px}.seo-ojeador-analysis-cards{margin-top:10px}
                .seo-ojeador-action-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px}.seo-ojeador-filter-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:12px}.seo-ojeador-filter-grid label{display:block}.seo-ojeador-filter-grid input,.seo-ojeador-filter-grid select{width:100%;margin-top:5px}.seo-ojeador-filter-grid .seo-ojeador-check{display:flex;gap:7px;align-items:center;padding-top:24px}.seo-ojeador-filter-grid .seo-ojeador-check input{width:auto;margin:0}
                .nav-tab-wrapper{margin-top:18px}.seo-ojeador-products .tablenav-pages,.seo-ojeador-stars .tablenav-pages{display:flex;gap:8px;align-items:center}.seo-ojeador-star-strong{border-left:4px solid #008a20;background:#f0f8f1}
            </style>

            <div class="seo-ojeador-head">
                <div>
                    <h1 style="margin-bottom:0">Ojeador <small style="font-size:14px;color:#646970">v<?php echo esc_html(SEO_OJEADOR_VERSION); ?></small></h1>
                    <p class="seo-ojeador-sub">Google Shopping por categorías, análisis accionable, productos estrella y comparación de mercado. Las recomendaciones sirven para decidir qué revisar; no ejecutan cambios automáticos.</p>
                </div>
                <div class="seo-ojeador-actions">
                    <?php if (SEO_Ojeador_Worker::is_pending()) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('seo_ojeador_stop'); ?><input type="hidden" name="action" value="seo_ojeador_stop"><button class="button">Detener</button></form>
                    <?php else : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('seo_ojeador_start'); ?><input type="hidden" name="action" value="seo_ojeador_start"><button class="button button-primary" <?php disabled(is_wp_error($ready)); ?>>Continuar / actualizar mercado</button></form>
                    <?php endif; ?>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=seo-processes')); ?>">Procesos</a>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('seo_ojeador_export_analysis_json'); ?><input type="hidden" name="action" value="seo_ojeador_export_analysis_json"><button class="button" type="submit">Exportar JSON análisis</button></form>
                </div>
            </div>

            <?php if ($notice === 'settings_saved') : ?><div class="notice notice-success inline"><p>Configuración guardada.</p></div><?php endif; ?>
            <?php if ($notice === 'scan_started') : ?><div class="notice notice-success inline"><p>Ojeador está consultando categorías aptas.</p></div><?php endif; ?>
            <?php if ($notice === 'scan_stopped') : ?><div class="notice notice-info inline"><p>Proceso detenido.</p></div><?php endif; ?>
            <?php if ($error !== '') : ?><div class="notice notice-error inline"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
            <?php if (is_wp_error($ready)) : ?><div class="notice notice-warning inline"><p><strong>Google Shopping:</strong> <?php echo esc_html($ready->get_error_message()); ?></p></div><?php endif; ?>

            <nav class="nav-tab-wrapper">
                <a class="nav-tab <?php echo $tab === 'analysis' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE . '&ojeador_tab=analysis')); ?>">Análisis y recomendaciones</a>
                <a class="nav-tab <?php echo $tab === 'stars' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE . '&ojeador_tab=stars')); ?>">Productos estrella</a>
                <a class="nav-tab <?php echo $tab === 'products' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE . '&ojeador_tab=products')); ?>">Comparador de productos</a>
                <a class="nav-tab <?php echo $tab === 'operation' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE . '&ojeador_tab=operation')); ?>">Operación y consultas</a>
            </nav>

            <?php if ($tab === 'analysis') : ?>
                <?php self::render_analysis($analysis); ?>
            <?php elseif ($tab === 'stars') : ?>
                <?php self::render_stars($stars); ?>
            <?php elseif ($tab === 'products') : ?>
                <?php self::render_products($comparison, $category_options); ?>
            <?php else : ?>
                <div class="seo-ojeador-cards">
                    <div class="seo-ojeador-card"><strong><?php echo number_format_i18n($summary['target']); ?></strong><span>Categorías aptas Ojeador</span></div>
                    <div class="seo-ojeador-card"><strong><?php echo number_format_i18n($summary['excluded'] ?? 0); ?></strong><span>Excluidas por vocabulario</span></div>
                    <div class="seo-ojeador-card"><strong><?php echo number_format_i18n($summary['consulted']); ?></strong><span>Consultadas</span></div>
                    <div class="seo-ojeador-card"><strong><?php echo number_format_i18n($summary['pending']); ?></strong><span>Pendientes</span></div>
                    <div class="seo-ojeador-card"><strong><?php echo number_format_i18n($usage['used']); ?> / <?php echo number_format_i18n($usage['limit']); ?></strong><span>Uso SerpApi</span></div>
                    <div class="seo-ojeador-card"><strong><?php echo number_format_i18n($summary['with_results']); ?></strong><span>Con resultados</span></div>
                    <div class="seo-ojeador-card"><strong><?php echo number_format_i18n($summary['errors'] ?? 0); ?></strong><span>Errores</span></div>
                    <div class="seo-ojeador-card"><strong><?php echo esc_html(number_format_i18n($summary['coverage'], 1)); ?>%</strong><span>Cobertura consultada</span></div>
                </div>

                <?php if ($run) : ?>
                    <div class="seo-ojeador-run"><strong>Último proceso:</strong> <?php echo esc_html((string) ($run['status'] ?? '')); ?> · <?php echo number_format_i18n(absint($run['processed_categories'] ?? 0)); ?> categorías · <?php echo number_format_i18n(absint($run['api_queries'] ?? 0)); ?> peticiones HTTP · <?php echo number_format_i18n(absint($run['results_seen'] ?? 0)); ?> resultados · <?php echo number_format_i18n(absint($run['errors_count'] ?? 0)); ?> errores.</div>
                <?php endif; ?>

                <div style="display:flex;justify-content:space-between;gap:12px;align-items:end;flex-wrap:wrap;margin:22px 0 8px;">
                    <div><h2 style="margin:0">Mercado por categorías</h2><p class="seo-ojeador-muted" style="margin:4px 0 0">Estado operativo de cada shopping_query.</p></div>
                    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>"><input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>"><input type="hidden" name="ojeador_tab" value="operation"><input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Categoría o consulta"><button class="button">Buscar</button></form>
                </div>
                <div class="seo-ojeador-table"><table class="widefat striped"><thead><tr><th>Categoría</th><th>Consulta Google</th><th>Prioridad</th><th>Productos catálogo</th><th>Resultados</th><th>Estado</th><th>Última</th><th>Próxima</th></tr></thead><tbody>
                    <?php foreach ($rows as $row) :
                        $eligible = !empty($row['ojeador_eligible']); $first_scan = !empty($row['needs_trusted_scan']); $due = !empty($row['ojeador_due']);
                        if (!$eligible) { $label='No apta'; $color='#b32d2e'; } elseif ($first_scan) { $label='Pendiente'; $color='#996800'; } else { list($label,$color)=self::status_label($row['status'] ?? ''); }
                        $category_name=(string)($row['category_name'] ?: $row['woo_category']);
                        $query_text=$eligible ? (string)($first_scan ? ($row['trusted_query'] ?? '') : (($row['query_text'] ?? '') ?: ($row['trusted_query'] ?? ''))) : '';
                        $priority_label=!$eligible?'Excluida':($first_scan?'Primera consulta':($due?'Actualizar':'Al día'));
                    ?>
                        <tr><td><strong><?php echo esc_html($category_name); ?></strong></td><td><?php echo $query_text!==''?esc_html($query_text):'—'; ?></td><td><?php echo esc_html($priority_label); ?></td><td><?php echo number_format_i18n(absint($row['woo_product_count'] ?? 0)); ?></td><td><?php echo number_format_i18n(absint($row['result_count'] ?? 0)); ?></td><td><span class="seo-ojeador-pill" style="color:<?php echo esc_attr($color); ?>"><?php echo esc_html($label); ?></span><?php if (!empty($row['last_error'])) : ?><br><small><?php echo esc_html(wp_trim_words((string)$row['last_error'],14)); ?></small><?php endif; ?></td><td><?php echo !empty($row['last_scan_at'])?esc_html((string)$row['last_scan_at']):'—'; ?></td><td><?php echo !empty($row['next_scan_at'])?esc_html((string)$row['next_scan_at']):'—'; ?></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>

                <div style="display:flex;justify-content:space-between;gap:12px;align-items:end;flex-wrap:wrap;margin:22px 0 8px;"><div><h2 style="margin:0">Log de consultas Google Shopping</h2><p class="seo-ojeador-muted" style="margin:4px 0 0">Trazabilidad por petición.</p></div><?php if ($latest_log) : ?><small class="seo-ojeador-muted">Último evento: <?php echo esc_html((string) ($latest_log['created_at'] ?? '')); ?></small><?php endif; ?></div>
                <div class="seo-ojeador-table"><table class="widefat striped"><thead><tr><th>Fecha UTC</th><th>Run</th><th>Categoría</th><th>Consulta</th><th>Resultado</th><th>HTTP</th><th>Recibidos</th><th>Guardados</th><th>Tiempo</th><th>Error</th></tr></thead><tbody>
                    <?php foreach ($logs as $log) : list($log_label,$log_color)=self::query_log_label($log['event_status'] ?? ''); ?>
                        <tr><td><?php echo esc_html((string)($log['created_at'] ?? '')); ?></td><td><?php echo absint($log['run_id'] ?? 0) ?: '—'; ?></td><td><?php echo !empty($log['category_name'])?esc_html((string)$log['category_name']):'—'; ?></td><td><?php echo !empty($log['query_text'])?esc_html((string)$log['query_text']):'—'; ?></td><td><span class="seo-ojeador-pill" style="color:<?php echo esc_attr($log_color); ?>"><?php echo esc_html($log_label); ?></span></td><td><?php echo !empty($log['request_attempted'])?absint($log['http_code'] ?? 0):'—'; ?></td><td><?php echo number_format_i18n(absint($log['raw_result_count'] ?? 0)); ?></td><td><?php echo number_format_i18n(absint($log['saved_result_count'] ?? 0)); ?></td><td><?php echo !empty($log['duration_ms'])?number_format_i18n(absint($log['duration_ms'])).' ms':'—'; ?></td><td><?php echo !empty($log['error_message'])?esc_html(wp_trim_words((string)$log['error_message'],18)):'—'; ?></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>

                <details class="seo-ojeador-box seo-ojeador-config"><summary>Conexión Google Shopping y ritmo</summary>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('seo_ojeador_settings_save'); ?><input type="hidden" name="action" value="seo_ojeador_settings_save">
                        <div class="seo-ojeador-grid">
                            <label><strong>SerpApi API key</strong><input type="password" name="ojeador[api_key]" value="" placeholder="<?php echo esc_attr($settings['api_key'] !== '' ? 'Configurada · dejar vacío para conservar' : 'API key'); ?>" autocomplete="new-password"></label>
                            <label><strong>Actualizar cada</strong><input type="number" min="6" max="2160" name="ojeador[interval_hours]" value="<?php echo absint($settings['interval_hours']); ?>"><small>Horas por categoría.</small></label>
                            <label><strong>Categorías por paso</strong><input type="number" min="1" max="20" name="ojeador[batch_size]" value="<?php echo absint($settings['batch_size']); ?>"></label>
                            <label><strong>Reutilizar consulta durante</strong><input type="number" min="1" max="168" name="ojeador[query_reuse_hours]" value="<?php echo absint($settings['query_reuse_hours']); ?>"><small>Horas.</small></label>
                            <label><strong>Límite mensual local</strong><input type="number" min="1" max="1000000" name="ojeador[monthly_query_limit]" value="<?php echo absint($settings['monthly_query_limit']); ?>"></label>
                        </div>
                        <p><label><input type="checkbox" name="ojeador[auto_enabled]" value="1" <?php checked(!empty($settings['auto_enabled'])); ?>> Mantener el mercado actualizado automáticamente</label></p><p><button class="button button-primary" type="submit">Guardar</button></p>
                    </form>
                </details>

                <div class="seo-ojeador-actions" style="margin-top:18px">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('seo_ojeador_export_json'); ?><input type="hidden" name="action" value="seo_ojeador_export_json"><button class="button" type="submit">Exportar JSON completo (auditoría)</button></form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('seo_ojeador_export_log_json'); ?><input type="hidden" name="action" value="seo_ojeador_export_log_json"><button class="button" type="submit">Exportar log JSON</button></form>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

}
