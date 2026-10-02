<?php
/**
 * Solucionador - interfaz administrativa.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Admin {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_page'), 30);
        add_filter('seo_content_items', array(__CLASS__, 'content_card'), 30, 1);
        add_filter('parent_file', array(__CLASS__, 'parent_file'), 30, 1);
        add_filter('submenu_file', array(__CLASS__, 'submenu_file'), 30, 1);
        add_action('admin_post_seo_solucionador_scan', array(__CLASS__, 'handle_scan'));
        add_action('admin_post_seo_solucionador_topic', array(__CLASS__, 'handle_topic'));
    }

    public static function register_page() {
        add_submenu_page(
            null,
            'Solucionador',
            'Solucionador',
            'manage_options',
            'seo-solucionador',
            array(__CLASS__, 'render')
        );
    }

    public static function content_card($items) {
        $items = is_array($items) ? $items : array();
        $items[] = array(
            'title' => 'Solucionador',
            'icon' => 'dashicons-edit-page',
            'page' => 'seo-solucionador',
            'desc' => 'Centro único de diagnóstico y decisión editorial: unifica señales, prioriza actuaciones y entrega briefs a los editores.',
        );
        return $items;
    }

    public static function parent_file($parent_file) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return $page === 'seo-solucionador' ? 'seo-system' : $parent_file;
    }

    public static function submenu_file($submenu_file) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return $page === 'seo-solucionador' ? 'seo-content' : $submenu_file;
    }

    public static function url($tab = 'summary', $extra = array()) {
        return add_query_arg(array_merge(array(
            'page'=>'seo-solucionador',
            'tab'=>sanitize_key((string) $tab),
        ), (array) $extra), admin_url('admin.php'));
    }

    public static function diagnostics_url($scope = 'overview', $view = '', $extra = array()) {
        $args = array(
            'diag_scope'=>sanitize_key((string) $scope),
        );
        if ($view !== '') $args['diag_view'] = sanitize_key((string) $view);
        return self::url('diagnostics', array_merge($args, (array) $extra));
    }

    private static function redirect($args = array()) {
        wp_safe_redirect(add_query_arg(array_merge(array(
            'page'=>'seo-solucionador',
            'tab'=>'proposals',
        ), $args), admin_url('admin.php')));
        exit;
    }

    public static function handle_scan() {
        if (!current_user_can('manage_options')) wp_die('No tienes permisos para ejecutar Solucionador.');
        check_admin_referer('seo_solucionador_scan');
        $days = isset($_POST['days']) ? max(30, min(365, absint($_POST['days']))) : 180;
        SEO_Solucionador_Engine::scan($days);
        wp_safe_redirect(add_query_arg(array('page'=>'seo-solucionador','scan'=>'ok'), admin_url('admin.php')));
        exit;
    }

    public static function handle_topic() {
        if (!current_user_can('manage_options')) wp_die('No tienes permisos para gestionar Solucionador.');
        $id = absint($_POST['topic_id'] ?? 0);
        check_admin_referer('seo_solucionador_topic_' . $id);
        $action = sanitize_key((string) ($_POST['topic_action'] ?? ''));
        $reason = sanitize_textarea_field(wp_unslash($_POST['workflow_reason'] ?? ''));
        if (!$id) self::redirect(array('sol_error'=>'missing_topic'));

        $topic = SEO_Solucionador_DB::get_topic($id);
        if (!$topic) self::redirect(array('sol_error'=>'missing_topic'));

        if ($action === 'create_draft') {
            $result = SEO_Solucionador_Posts::create_draft($id);
            if (is_wp_error($result)) {
                set_transient('seo_solucionador_notice_' . get_current_user_id(), $result->get_error_message(), 90);
                self::redirect(array('sol_error'=>'create_draft','topic_id'=>$id));
            }
            self::redirect(array('sol_msg'=>'draft_created','post_id'=>absint($result),'topic_id'=>$id));
        }

        $workflow_map = array(
            'candidate'   => array('state'=>'candidate','legacy'=>'candidate'),
            'approved'    => array('state'=>'approved','legacy'=>'approved'),
            'brief_ready' => array('state'=>'brief_ready','legacy'=>'approved'),
            'deferred'    => array('state'=>'deferred','legacy'=>'observe'),
            'rejected'    => array('state'=>'rejected','legacy'=>'dismissed'),
            'closed'      => array('state'=>'closed','legacy'=>'covered'),
        );

        if (isset($workflow_map[$action])) {
            if ($reason === '') {
                set_transient('seo_solucionador_notice_' . get_current_user_id(), 'Indica el motivo del cambio de estado. La trazabilidad exige usuario, fecha y motivo.', 90);
                self::redirect(array('sol_error'=>'reason_required','topic_id'=>$id));
            }
            $target = $workflow_map[$action];
            SEO_Solucionador_DB::update_topic($id, array('status'=>$target['legacy']));
            SEO_Solucionador_DB::record_workflow(
                $id,
                $target['state'],
                $reason,
                (string) ($topic['recommended_action'] ?? '')
            );
            self::redirect(array('sol_msg'=>'saved','topic_id'=>$id));
        }

        self::redirect(array('sol_error'=>'invalid_action','topic_id'=>$id));
    }

    private static function tabs($current) {
        $tabs = array(
            'summary' => 'Resumen',
            'diagnostics' => 'Diagnóstico editorial',
            'proposals' => 'Propuestas editoriales',
            'coverage' => 'Cobertura editorial',
            'sources' => 'Fuentes y servicios',
            'tests' => 'Pruebas RF v1.0',
            'data' => 'Datos internos',
        );
        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $key => $label) {
            $url = add_query_arg(array('page'=>'seo-solucionador','tab'=>$key), admin_url('admin.php'));
            echo '<a class="nav-tab ' . ($current === $key ? 'nav-tab-active' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    private static function counts() {
        global $wpdb;
        $table = SEO_Solucionador_DB::topics_table();
        if (!SEO_Solucionador_DB::table_exists($table)) return array();

        return array(
            'total'        => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE workflow_state<>'rejected'"),
            'active'       => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE workflow_state IN ('detected','validated','candidate','approved','brief_ready','in_editing','scheduled','monitoring')"),
            'candidate'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE workflow_state='candidate'"),
            'approved'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE workflow_state IN ('approved','brief_ready')"),
            'rejected'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE workflow_state='rejected'"),
            'improve'      => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE recommended_action IN ('IMPROVE_POST','IMPROVE_LANDING','IMPROVE_CATEGORY','IMPROVE_PAGE') AND workflow_state<>'rejected'"),
            'duplicates'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE recommended_action='MERGE_CONTENT' OR coverage_status IN ('duplicate','conflict')"),
            'investigate'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE recommended_action='INVESTIGATE' AND workflow_state<>'rejected'"),
            'deferred'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE workflow_state='deferred' OR recommended_action='DEFER'"),
            'create_post'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE recommended_action='CREATE_POST' AND workflow_state<>'rejected'"),
            'create_landing'=> (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE recommended_action='CREATE_LANDING' AND workflow_state<>'rejected'"),
            'monitoring'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE workflow_state='monitoring'"),
        );
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        SEO_Solucionador_DB::maybe_install();
        $initialization = SEO_Solucionador_Engine::ensure_initialized(180);
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'summary';
        if (!in_array($tab, array('summary','diagnostics','proposals','coverage','sources','tests','data'), true)) $tab = 'summary';

        echo '<div class="wrap seo-solucionador"><h1>Solucionador <small style="font-weight:400;color:#646970">v' . esc_html(SEO_SOLUCIONADOR_VERSION) . '</small></h1>';
        echo '<p><strong>Capa de decision editorial.</strong> Solucionador unifica conclusiones de Auditor, Analista, Clasificador, Dependiente/Interprete, Ojeador e Ingeniero; detecta carencias, oportunidades, duplicidades y canibalizacion; prioriza la actuacion y prepara el brief. <strong>No investiga, interpreta ni mide por su cuenta y no publica contenido.</strong></p>';
        if (is_wp_error($initialization)) {
            echo '<div class="notice notice-warning inline"><p><strong>Inicialización pendiente:</strong> ' . esc_html($initialization->get_error_message()) . '</p></div>';
        }
        self::render_export_button();

        if (!empty($_GET['scan'])) echo '<div class="notice notice-success is-dismissible"><p>Analisis de Solucionador completado.</p></div>';
        if (!empty($_GET['sol_msg']) && $_GET['sol_msg'] === 'draft_created') {
            $post_id = absint($_GET['post_id'] ?? 0);
            echo '<div class="notice notice-success is-dismissible"><p>Borrador creado y clasificado.';
            if ($post_id) echo ' <a href="' . esc_url(SEO_Solucionador_Posts::edit_url($post_id)) . '"><strong>Abrir borrador #' . esc_html($post_id) . '</strong></a>';
            echo '</p></div>';
        } elseif (!empty($_GET['sol_msg'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Cambio guardado.</p></div>';
        }
        if (!empty($_GET['sol_error'])) {
            $msg = get_transient('seo_solucionador_notice_' . get_current_user_id());
            delete_transient('seo_solucionador_notice_' . get_current_user_id());
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg ?: 'No se pudo completar la accion.') . '</p></div>';
        }

        self::tabs($tab);
        if ($tab === 'summary') self::render_summary();
        elseif ($tab === 'diagnostics') self::render_diagnostics();
        elseif ($tab === 'proposals') self::render_proposals();
        elseif ($tab === 'coverage') self::render_coverage();
        elseif ($tab === 'sources') self::render_sources();
        elseif ($tab === 'tests') self::render_tests();
        else self::render_data();
        self::styles();
        echo '</div>';
    }

    private static function render_summary() {
        $counts = self::counts();
        $last = get_option('seo_solucionador_last_scan', array());

        echo '<div class="seo-sol-grid">';
        self::card('Oportunidades activas', $counts['active'] ?? 0, 'Necesidades que siguen dentro del circuito editorial.');
        self::card('Candidatos', $counts['candidate'] ?? 0, 'Decisiones candidatas pendientes de validación humana.');
        self::card('Aprobados / brief', $counts['approved'] ?? 0, 'Actuaciones aprobadas o con brief listo.');
        self::card('Mejorar existente', $counts['improve'] ?? 0, 'Posts, landings, categorías o páginas que deben reforzarse.');
        self::card('Duplicados / conflictos', $counts['duplicates'] ?? 0, 'Casos que requieren consolidar, fusionar o investigar.');
        self::card('Pendientes de investigación', $counts['investigate'] ?? 0, 'Falta conocimiento fiable o existe conflicto.');
        self::card('Aplazados', $counts['deferred'] ?? 0, 'Todavía no cumplen las condiciones para actuar.');
        self::card('En seguimiento', $counts['monitoring'] ?? 0, 'Intervenciones publicadas esperando resultados de Analista.');
        if (class_exists('SEO_Solucionador_Sources') && method_exists('SEO_Solucionador_Sources','dependiente_academia_snapshot')) {
            $academy = SEO_Solucionador_Sources::dependiente_academia_snapshot();
            self::card('Dependiente aprendido', absint($academy['learned'] ?? 0), 'Preguntas de Academia cuyo último run está validado pass_*.');
            self::card('Categorías con conocimiento', absint($academy['categories_with_knowledge'] ?? 0), 'Dossiers category-first disponibles para decisión editorial.');
        }
        echo '</div>';

        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Decisión editorial</h2>';
        echo '<p>Solucionador responde cinco preguntas: <strong>dónde existe una oportunidad, por qué existe, qué contenido ya la cubre, qué acción mínima conviene y qué necesita la Editora para ejecutarla</strong>. Crear una URL nueva es la última opción.</p>';
        echo '<p><a class="button button-primary" href="' . esc_url(self::url('proposals')) . '">Abrir oportunidades</a> <a class="button" href="' . esc_url(self::url('coverage')) . '">Revisar cobertura</a></p>';
        echo '</div>';

        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Reanalizar fuentes</h2>';
        echo '<p>Reconstruye evidencia, temas canónicos, cobertura multientidad y decisiones usando únicamente datos ya producidos por los servicios especialistas. No lanza investigación técnica, Google Shopping ni búsquedas externas nuevas.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_solucionador_scan">';
        wp_nonce_field('seo_solucionador_scan');
        echo '<label><strong>Ventana:</strong> <select name="days"><option value="90">90 días</option><option value="180" selected>180 días</option><option value="365">365 días</option></select></label> ';
        submit_button('Reanalizar fuentes', 'primary', 'submit', false);
        echo '</form>';
        if ($last) {
            echo '<p class="description" style="margin-top:12px">Último análisis: <strong>' . esc_html(wp_date('d/m/Y H:i', absint($last['at'] ?? 0))) . '</strong>';
            echo ' · señales: ' . esc_html(number_format_i18n(absint($last['sources_seen'] ?? 0)));
            echo ' · aceptadas: ' . esc_html(number_format_i18n(absint($last['accepted'] ?? 0)));
            echo ' · descartadas/refuerzos sin origen: ' . esc_html(number_format_i18n(absint($last['discarded'] ?? 0)));
            echo ' · posts: ' . esc_html(number_format_i18n(absint($last['posts_indexed'] ?? 0)));
            echo ' · páginas: ' . esc_html(number_format_i18n(absint($last['pages_indexed'] ?? 0)));
            echo ' · categorías: ' . esc_html(number_format_i18n(absint($last['categories_indexed'] ?? 0)));
            echo ' · huellas de cobertura: ' . esc_html(number_format_i18n(absint($last['post_topics_indexed'] ?? 0))) . '</p>';
        }
        echo '</div>';
    }

    private static function diagnostics_nav($scope, $view) {
        $items = array(
            'overview'=>array('label'=>'Visión general','view'=>''),
            'posts'=>array('label'=>'Entradas','view'=>'opportunities'),
            'pages'=>array('label'=>'Páginas / landings','view'=>'landings'),
            'categories'=>array('label'=>'Categorías','view'=>'google'),
            'content'=>array('label'=>'Cobertura de contenido','view'=>''),
            'services'=>array('label'=>'Servicios fuente','view'=>''),
        );
        echo '<div class="seo-sol-diagnostic-nav" style="display:flex;gap:7px;flex-wrap:wrap;margin:18px 0">';
        foreach ($items as $key=>$item) {
            $active = $scope === $key;
            echo '<a class="button ' . ($active ? 'button-primary' : '') . '" href="' . esc_url(self::diagnostics_url($key, $item['view'])) . '">' . esc_html($item['label']) . '</a>';
        }
        echo '</div>';

        if ($scope === 'posts') {
            self::diagnostics_subnav($scope, $view, array('opportunities'=>'Rendimiento y oportunidades','health'=>'Errores técnicos'));
        } elseif ($scope === 'pages') {
            self::diagnostics_subnav($scope, $view, array('landings'=>'Landings y oportunidades','health'=>'Errores técnicos'));
        } elseif ($scope === 'categories') {
            self::diagnostics_subnav($scope, $view, array('google'=>'Rendimiento Google','structure'=>'Estructura y cobertura'));
        }
    }

    private static function diagnostics_subnav($scope, $view, $items) {
        echo '<div style="display:flex;gap:7px;flex-wrap:wrap;margin:-8px 0 18px">';
        foreach ((array) $items as $key=>$label) {
            echo '<a class="button button-small ' . ($view === $key ? 'button-primary' : '') . '" href="' . esc_url(self::diagnostics_url($scope, $key)) . '">' . esc_html($label) . '</a>';
        }
        echo '</div>';
    }

    private static function service_snapshot() {
        $items = array();

        if (class_exists('SEO_Auditor') && method_exists('SEO_Auditor','last_catalog_report')) {
            $report = SEO_Auditor::last_catalog_report();
            $items['Auditor'] = array(
                'available'=>!empty($report),
                'metric'=>absint($report['summary']['findings'] ?? count((array) ($report['findings'] ?? array()))),
                'unit'=>'hallazgos',
                'detail'=>!empty($report['generated_at']) ? 'Último informe: ' . (string) $report['generated_at'] : 'Sin informe guardado',
            );
        } else {
            $items['Auditor'] = array('available'=>false,'metric'=>0,'unit'=>'','detail'=>'Servicio no disponible');
        }

        if (function_exists('seo_analista_decision_plan')) {
            $plan = (array) seo_analista_decision_plan(90, 100);
            $items['Analista'] = array('available'=>true,'metric'=>count($plan),'unit'=>'directrices','detail'=>'Plan de decisión almacenado / calculable con datos existentes');
        } else {
            $items['Analista'] = array('available'=>false,'metric'=>0,'unit'=>'','detail'=>'Servicio no disponible');
        }

        global $wpdb;
        $search_table = $wpdb->prefix . 'seo_dependiente_search_log';
        $searches = 0;
        if (class_exists('SEO_Solucionador_DB') && SEO_Solucionador_DB::table_exists($search_table)) {
            $searches = absint($wpdb->get_var("SELECT COUNT(*) FROM {$search_table}"));
        }
        $items['Dependiente · demanda real'] = array(
            'available'=>$searches > 0,
            'metric'=>$searches,
            'unit'=>'consultas registradas',
            'detail'=>'Search log de visitantes; se conserva separado del conocimiento aprendido.'
        );

        if (class_exists('SEO_Solucionador_Sources') && method_exists('SEO_Solucionador_Sources','dependiente_academia_snapshot')) {
            $academy = SEO_Solucionador_Sources::dependiente_academia_snapshot();
            $items['Dependiente · Academia'] = array(
                'available'=>!empty($academy['available']),
                'metric'=>absint($academy['categories_with_knowledge'] ?? 0),
                'unit'=>'categorías con conocimiento',
                'detail'=>number_format_i18n(absint($academy['learned'] ?? 0)) . ' preguntas aprendidas · '
                    . number_format_i18n(absint($academy['learned_without_category'] ?? 0)) . ' sin categoría'
            );
        } else {
            $items['Dependiente · Academia'] = array('available'=>false,'metric'=>0,'unit'=>'','detail'=>'Integración no disponible');
        }

        if (class_exists('SEO_Ojeador_Analysis') && method_exists('SEO_Ojeador_Analysis','dashboard')) {
            $dash = SEO_Ojeador_Analysis::dashboard(40);
            $items['Ojeador'] = array(
                'available'=>true,
                'metric'=>count((array) ($dash['recommendations'] ?? array())),
                'unit'=>'recomendaciones',
                'detail'=>'Cobertura mercado: ' . number_format_i18n((float) ($dash['kpis']['valid_market_coverage_pct'] ?? 0), 1) . '%',
            );
        } else {
            $items['Ojeador'] = array('available'=>false,'metric'=>0,'unit'=>'','detail'=>'Servicio no disponible');
        }

        if (class_exists('SEO_Ingeniero_DB')) {
            $totals = SEO_Ingeniero_DB::totals();
            $stats = SEO_Ingeniero_DB::category_stats_map();
            $items['Ingeniero'] = array(
                'available'=>true,
                'metric'=>count((array) $stats),
                'unit'=>'categorías con conocimiento',
                'detail'=>number_format_i18n(absint($totals['knowledge'] ?? 0)) . ' bloques de conocimiento',
            );
        } else {
            $items['Ingeniero'] = array('available'=>false,'metric'=>0,'unit'=>'','detail'=>'Servicio no disponible');
        }

        if (function_exists('seo_classifier_engineer_vocab_bulk_reports') && function_exists('seo_classifier_engineer_vocab_flat_rows')) {
            $reports = seo_classifier_engineer_vocab_bulk_reports();
            $rows = is_wp_error($reports) ? array() : seo_classifier_engineer_vocab_flat_rows((array) $reports);
            $new = 0; $possible = 0;
            foreach ((array) $rows as $row) {
                if (($row['status'] ?? '') === 'new') $new++;
                elseif (($row['status'] ?? '') === 'possible') $possible++;
            }
            $items['Clasificador'] = array(
                'available'=>!is_wp_error($reports),
                'metric'=>$new,
                'unit'=>'conceptos nuevos detectados',
                'detail'=>number_format_i18n($possible) . ' posibles equivalentes pendientes de revisión',
            );
        } else {
            $items['Clasificador'] = array('available'=>false,'metric'=>0,'unit'=>'','detail'=>'Integración no disponible');
        }

        if (class_exists('SEO_Solucionador_Sources')) {
            $comparador = SEO_Solucionador_Sources::comparador(200);
            $items['Comparador'] = array(
                'available'=>!empty($comparador),
                'metric'=>count($comparador),
                'unit'=>'señales normalizadas',
                'detail'=>!empty($comparador) ? 'Resultado publicado por Comparador; Solucionador no recalcula comparativas.' : 'Sin contrato/señales disponibles todavía',
            );
            $marketing = SEO_Solucionador_Sources::marketing(120);
            $items['Marketing'] = array(
                'available'=>!empty($marketing),
                'metric'=>count($marketing),
                'unit'=>'prioridades comerciales',
                'detail'=>'Refuerza prioridad; nunca justifica por sí solo una URL nueva.',
            );
        }

        return $items;
    }

    private static function render_service_snapshot() {
        $items = self::service_snapshot();
        echo '<div class="seo-sol-grid">';
        foreach ($items as $name=>$item) {
            $value = !empty($item['available']) ? number_format_i18n((float) ($item['metric'] ?? 0)) : '—';
            self::card($name, $value, trim((string) ($item['unit'] ?? '') . '. ' . (string) ($item['detail'] ?? '')));
        }
        echo '</div>';
        echo '<p class="description">Estos indicadores se leen de los datos y conclusiones ya generados por cada servicio. Abrir Solucionador no lanza búsquedas web, Google Shopping ni investigación técnica.</p>';
    }

    private static function render_diagnostics() {
        $scope = isset($_GET['diag_scope']) ? sanitize_key(wp_unslash($_GET['diag_scope'])) : 'overview';
        $view = isset($_GET['diag_view']) ? sanitize_key(wp_unslash($_GET['diag_view'])) : '';
        $allowed = array('overview','posts','pages','categories','content','services');
        if (!in_array($scope, $allowed, true)) $scope = 'overview';

        if ($scope === 'posts' && !in_array($view, array('opportunities','health'), true)) $view = 'opportunities';
        if ($scope === 'pages' && !in_array($view, array('landings','health'), true)) $view = 'landings';
        if ($scope === 'categories' && !in_array($view, array('google','structure'), true)) $view = 'google';

        echo '<div class="postbox" style="padding:18px;margin-top:18px">';
        echo '<h2 style="margin-top:0">Diagnóstico editorial centralizado</h2>';
        echo '<p>Este es el <strong>único punto de análisis editorial visible</strong>. Entradas, Páginas y Categorías conservan los datos y los editores de ejecución; sus informes se reutilizan aquí para evitar paneles paralelos.</p>';
        echo '</div>';
        self::diagnostics_nav($scope, $view);

        if ($scope === 'overview' || $scope === 'services') {
            self::render_service_snapshot();
            if ($scope === 'overview') {
                echo '<div class="postbox" style="padding:18px;margin-top:18px"><h3 style="margin-top:0">Cómo leer el flujo</h3>';
                echo '<p><strong>Servicios fuente</strong> producen hechos, conocimiento, señales y métricas. <strong>Solucionador</strong> cruza esas conclusiones, prioriza y decide la salida editorial. <strong>Editora / editores</strong> ejecutan la modificación en Entradas, Páginas, Categorías o Imágenes.</p>';
                echo '<p style="margin-bottom:0"><strong>Solucionador no sustituye a Auditor, Analista, Clasificador, Dependiente, Intérprete, Ojeador ni Ingeniero:</strong> consume su resultado.</p></div>';
            }
            return;
        }

        if ($scope === 'posts') {
            if ($view === 'health') {
                if (function_exists('seo_health_render_scope_tab')) seo_health_render_scope_tab('post');
                else echo '<div class="notice notice-error inline"><p>No está disponible el diagnóstico técnico de entradas.</p></div>';
                return;
            }
            if (function_exists('seo_post_opportunities_render_page')) {
                seo_post_opportunities_render_page(array(
                    'page'=>'seo-solucionador',
                    'tab'=>'diagnostics',
                    'diag_scope'=>'posts',
                    'diag_view'=>'opportunities',
                ));
            } else {
                echo '<div class="notice notice-error inline"><p>No está disponible el informe de rendimiento y oportunidades de entradas.</p></div>';
            }
            return;
        }

        if ($scope === 'pages') {
            if ($view === 'health') {
                if (function_exists('seo_health_render_scope_tab')) seo_health_render_scope_tab('page');
                else echo '<div class="notice notice-error inline"><p>No está disponible el diagnóstico técnico de páginas.</p></div>';
                return;
            }
            if (function_exists('seo_landing_render_admin_tab')) {
                seo_landing_render_admin_tab(array(
                    'page'=>'seo-solucionador',
                    'tab'=>'diagnostics',
                    'diag_scope'=>'pages',
                    'diag_view'=>'landings',
                ));
            } else {
                echo '<div class="notice notice-error inline"><p>No está disponible el informe de landings.</p></div>';
            }
            return;
        }

        if ($scope === 'categories') {
            if ($view === 'structure') {
                if (function_exists('seo_render_total_structure_report')) seo_render_total_structure_report();
                else echo '<div class="notice notice-error inline"><p>No está disponible el informe estructural de categorías.</p></div>';
                return;
            }
            if (function_exists('seo_category_reports_page')) {
                seo_category_reports_page('seo-solucionador');
            } else {
                echo '<div class="notice notice-error inline"><p>No está disponible el informe Google de categorías.</p></div>';
            }
            return;
        }

        if ($scope === 'content') {
            if (function_exists('seo_report_contents_render_page')) seo_report_contents_render_page();
            else echo '<div class="notice notice-error inline"><p>No está disponible el informe objetivo de cobertura de contenido.</p></div>';
        }
    }

    private static function action_label($action) {
        $action = strtoupper((string) $action);
        $labels = array(
            'NO_ACTION'=>'No actuar',
            'IMPROVE_POST'=>'Mejorar post',
            'IMPROVE_LANDING'=>'Mejorar landing',
            'IMPROVE_CATEGORY'=>'Mejorar categoría',
            'IMPROVE_PAGE'=>'Mejorar página',
            'MERGE_CONTENT'=>'Fusionar / consolidar contenido',
            'CREATE_POST'=>'Crear post',
            'CREATE_LANDING'=>'Crear landing',
            'INVESTIGATE'=>'Investigar antes de editar',
            'DEFER'=>'Aplazar',
            'UPDATE_PRODUCT'=>'Actualizar producto',
        );
        return $labels[$action] ?? str_replace('_',' ',(string)$action);
    }

    private static function coverage_label($status) {
        $labels = array(
            'uncovered'=>'Sin cobertura',
            'weak_coverage'=>'Cobertura débil',
            'partial_coverage'=>'Cobertura parcial',
            'covered'=>'Cubierto',
            'duplicate'=>'Duplicado',
            'conflict'=>'Conflicto',
        );
        $status = sanitize_key((string) $status);
        return $labels[$status] ?? str_replace('_',' ',$status);
    }

    private static function workflow_label($state) {
        $labels = array(
            'detected'=>'Detectado','validated'=>'Validado','candidate'=>'Candidato','approved'=>'Aprobado',
            'brief_ready'=>'Brief listo','in_editing'=>'En edición','scheduled'=>'Programado','published'=>'Publicado',
            'monitoring'=>'En seguimiento','closed'=>'Cerrado','rejected'=>'Rechazado','deferred'=>'Aplazado',
        );
        $state = sanitize_key((string) $state);
        return $labels[$state] ?? str_replace('_',' ',$state);
    }

    private static function entity_edit_url($type,$id) {
        $type = sanitize_key((string) $type);
        $id = absint($id);
        if (!$id) return '';
        if ($type === 'post') return SEO_Solucionador_Posts::edit_url($id);
        if ($type === 'page') return get_edit_post_link($id,'raw') ?: '';
        if ($type === 'product_cat' && function_exists('seo_get_category_editor_url')) return seo_get_category_editor_url($id);
        if ($type === 'product') return get_edit_post_link($id,'raw') ?: '';
        return '';
    }

    private static function editorial_brief(array $topic) {
        $topic_id = absint($topic['id'] ?? 0);
        $evidence = SEO_Solucionador_DB::get_evidence_rows($topic_id);
        $categories = SEO_Solucionador_DB::proposed_categories($topic);
        $vocabulary = SEO_Solucionador_DB::proposed_vocabulary($topic);
        $hierarchy = SEO_Solucionador_DB::hierarchy($topic);
        $requirements = SEO_Solucionador_DB::decision_requirements($topic);
        $priority_components = SEO_Solucionador_DB::priority_components($topic);
        $primary_category_id = absint($topic['primary_category_id'] ?? 0);

        $profile = array(
            'intent'=>(string) ($topic['intent'] ?? ''),
            'action'=>(string) ($topic['action_term'] ?? ''),
            'object'=>(string) ($topic['object_term'] ?? ''),
            'condition'=>(string) ($topic['condition_term'] ?? ''),
            'context'=>(string) ($topic['context_term'] ?? ''),
            'canonical_key'=>(string) ($topic['canonical_key'] ?? ''),
            'category_id'=>$primary_category_id,
        );
        $coverage = SEO_Solucionador_Coverage::find($profile);

        $sources = array();
        $questions = array();
        $question_details = array();
        $market = array();
        foreach ($evidence as $row) {
            $type = sanitize_key((string) ($row['source_type'] ?? ''));
            if ($type !== '') $sources[$type] = ($sources[$type] ?? 0) + max(1,absint($row['occurrences'] ?? 1));
            if ($type === 'dependiente') {
                $meta = (array) ($row['source_meta_decoded'] ?? array());
                $academy_questions = (array) ($meta['academy_questions'] ?? array());
                if ($academy_questions) {
                    foreach ($academy_questions as $item) {
                        if (!is_array($item)) continue;
                        $q = trim((string) ($item['question'] ?? ''));
                        if ($q === '') continue;
                        $questions[$q] = true;
                        $key = absint($item['question_id'] ?? 0) ?: md5($q);
                        $question_details[$key] = array(
                            'question'=>$q,
                            'question_type'=>(string) ($item['question_type'] ?? ''),
                            'lesson_key'=>(string) ($item['lesson_key'] ?? ''),
                            'evaluation_status'=>(string) ($item['evaluation_status'] ?? ''),
                            'evaluation_score'=>(float) ($item['evaluation_score'] ?? 0),
                            'top_results'=>(array) ($item['top_results'] ?? array()),
                            'response_meta'=>(array) ($item['response_meta'] ?? array()),
                            'observed_at'=>(string) ($item['observed_at'] ?? ''),
                        );
                    }
                } else {
                    $q = trim((string) ($row['source_text'] ?? ''));
                    if ($q !== '') $questions[$q] = true;
                }
            }
            if (in_array($type,array('ojeador','comparador'),true)) {
                $meta = (array) ($row['source_meta_decoded'] ?? array());
                $market[] = array(
                    'source'=>$type,
                    'text'=>(string) ($row['source_text'] ?? ''),
                    'meta'=>$meta,
                );
            }
        }

        $concepts = array();
        foreach ($categories as $category) {
            $term_id = absint($category['term_id'] ?? $category['id'] ?? 0);
            if (!$term_id || !function_exists('seo_classifier_engineer_vocab_report')) continue;
            $report = seo_classifier_engineer_vocab_report($term_id);
            if (is_wp_error($report)) continue;
            foreach (array_merge((array) ($report['attributes'] ?? array()),(array) ($report['labels'] ?? array())) as $item) {
                if (!in_array((string) ($item['status'] ?? ''),array('new','possible'),true)) continue;
                $label = trim((string) (($item['name'] ?? '') ?: ($item['label'] ?? $item['value'] ?? '')));
                if ($label !== '') $concepts[$label] = true;
            }
        }

        $knowledge = array();
        $technical_sources = array();
        if ($primary_category_id && class_exists('SEO_Ingeniero')) {
            $source_map = array();
            if (class_exists('SEO_Ingeniero_DB')) {
                foreach ((array) SEO_Ingeniero_DB::sources_for_category($primary_category_id) as $source) {
                    $source_map[absint($source['id'] ?? 0)] = $source;
                }
            }
            foreach ((array) SEO_Ingeniero::active_knowledge($primary_category_id) as $row) {
                $item = array(
                    'type'=>(string) ($row['knowledge_type'] ?? ''),
                    'concept'=>(string) ($row['concept'] ?? ''),
                    'summary'=>(string) ($row['summary'] ?? ''),
                    'confidence'=>(float) ($row['confidence'] ?? 0),
                    'facts'=>(array) ($row['facts'] ?? array()),
                    'sources'=>array(),
                );
                foreach ((array) ($row['source_ids'] ?? array()) as $source_id) {
                    $source_id = absint($source_id);
                    if (!$source_id || empty($source_map[$source_id])) continue;
                    $src = $source_map[$source_id];
                    $normalized = array(
                        'id'=>$source_id,
                        'title'=>(string) ($src['title'] ?? ''),
                        'url'=>(string) ($src['url'] ?? ''),
                        'source_type'=>(string) ($src['source_type'] ?? ''),
                        'trust_level'=>(string) ($src['trust_level'] ?? ''),
                        'retrieved_at'=>(string) ($src['retrieved_at'] ?? ''),
                    );
                    $item['sources'][] = $normalized;
                    $technical_sources[$source_id] = $normalized;
                }
                $knowledge[] = $item;
            }
        }

        $products = array();
        $cat_ids = array_values(array_unique(array_filter(array_map(static function($row){
            return absint($row['term_id'] ?? $row['id'] ?? 0);
        },(array) $categories))));
        if ($primary_category_id && !in_array($primary_category_id,$cat_ids,true)) array_unshift($cat_ids,$primary_category_id);
        if ($cat_ids) {
            $ids = get_posts(array(
                'post_type'=>'product','post_status'=>'publish','posts_per_page'=>12,'fields'=>'ids',
                'tax_query'=>array(array('taxonomy'=>'product_cat','field'=>'term_id','terms'=>$cat_ids,'operator'=>'IN')),
            ));
            foreach ((array) $ids as $product_id) {
                $products[] = array('id'=>absint($product_id),'title'=>get_the_title($product_id),'url'=>get_permalink($product_id));
            }
        }

        $preserve = array();
        foreach ((array) ($coverage['matches'] ?? array()) as $match) {
            $label = trim((string) ($match['title'] ?? ''));
            $text = trim((string) ($match['source_text'] ?? ''));
            if ($label !== '') $preserve[] = $label . ($text !== '' && $text !== $label ? ' — ' . wp_trim_words($text,22,'…') : '');
        }
        $preserve = array_slice(array_values(array_unique($preserve)),0,12);

        $links = array();
        foreach ($categories as $category) {
            $term_id = absint($category['term_id'] ?? $category['id'] ?? 0);
            if (!$term_id) continue;
            $term = get_term($term_id,'product_cat');
            if (!$term || is_wp_error($term)) continue;
            $url = get_term_link($term);
            if (!is_wp_error($url)) $links[(string)$url] = array('label'=>(string)$term->name,'url'=>(string)$url);
        }
        foreach ((array) ($coverage['matches'] ?? array()) as $match) {
            $url = trim((string) ($match['url'] ?? ''));
            if ($url !== '') $links[$url] = array('label'=>(string) (($match['title'] ?? '') ?: $url),'url'=>$url);
        }

        $audience = array();
        foreach ((array) ($vocabulary['rol'] ?? array()) as $row) {
            if (!empty($row['label'])) $audience[] = (string) $row['label'];
        }

        $must_cover = array();
        foreach ($knowledge as $item) {
            $label = trim((string) (($item['concept'] ?? '') ?: ($item['summary'] ?? '')));
            if ($label !== '') $must_cover[$label] = true;
            foreach ((array) ($item['facts'] ?? array()) as $fact) {
                if (is_scalar($fact)) {
                    $fact = trim((string) $fact);
                    if ($fact !== '') $must_cover[$fact] = true;
                }
            }
        }
        foreach (array_keys($concepts) as $concept) $must_cover[$concept] = true;

        $tracking = SEO_Solucionador_DB::tracking_history($topic_id,24);
        $workflow = SEO_Solucionador_DB::workflow_history($topic_id,100);

        return array(
            'topic_id'=>$topic_id,
            'topic'=>(string) (($topic['suggested_title'] ?? '') ?: ($topic['canonical_question'] ?? '')),
            'question'=>(string) ($topic['canonical_question'] ?? ''),
            'intent'=>(string) ($topic['intent'] ?? ''),
            'audience'=>$audience,
            'output'=>self::action_label((string) ($topic['recommended_action'] ?? 'DEFER')),
            'action'=>(string) ($topic['recommended_action'] ?? 'DEFER'),
            'decision_reason'=>(string) ($topic['decision_reason'] ?? ''),
            'priority'=>(float) ($topic['priority_score'] ?? 0),
            'priority_components'=>$priority_components,
            'requirements'=>$requirements,
            'sources'=>$sources,
            'evidence'=>$evidence,
            'categories'=>$categories,
            'primary_category_id'=>$primary_category_id,
            'hierarchy'=>$hierarchy,
            'vocabulary'=>$vocabulary,
            'concepts'=>array_slice(array_keys($concepts),0,30),
            'knowledge'=>$knowledge,
            'technical_sources'=>array_values($technical_sources),
            'products'=>$products,
            'questions'=>array_values(array_keys($questions)),
            'question_details'=>array_values($question_details),
            'must_cover'=>array_slice(array_keys($must_cover),0,40),
            'preserve'=>$preserve,
            'links'=>array_values($links),
            'coverage'=>$coverage,
            'market'=>$market,
            'duplication_risk'=>(float) ($topic['duplication_risk'] ?? 0),
            'cannibalization_risk'=>(float) ($topic['cannibalization_risk'] ?? 0),
            'knowledge_status'=>(string) ($topic['knowledge_status'] ?? 'unknown'),
            'workflow_state'=>(string) ($topic['workflow_state'] ?? 'detected'),
            'workflow'=>$workflow,
            'tracking'=>$tracking,
            'existing_entity_type'=>(string) ($topic['existing_entity_type'] ?? ''),
            'existing_entity_id'=>absint($topic['existing_entity_id'] ?? 0),
            'draft_post_id'=>absint($topic['draft_post_id'] ?? 0),
        );
    }

    private static function render_priority_components(array $brief) {
        $components = (array) ($brief['priority_components'] ?? array());
        echo '<table class="widefat striped"><thead><tr><th>Componente</th><th>Puntos</th><th>Máximo</th></tr></thead><tbody>';
        if (!$components) echo '<tr><td colspan="3">Sin desglose todavía.</td></tr>';
        foreach ($components as $row) {
            echo '<tr><td>' . esc_html((string) ($row['label'] ?? '')) . '</td><td><strong>' . esc_html(number_format_i18n((float) ($row['score'] ?? 0),2)) . '</strong></td><td>' . esc_html(number_format_i18n((float) ($row['max'] ?? 0),0)) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_requirements(array $brief) {
        $requirements = (array) ($brief['requirements'] ?? array());
        echo '<ul class="seo-sol-requirements">';
        if (!$requirements) echo '<li>Sin condiciones calculadas todavía.</li>';
        foreach ($requirements as $key=>$row) {
            $pass = !empty($row['pass']);
            echo '<li><strong>' . ($pass ? 'OK' : 'PENDIENTE') . ' · ' . esc_html(str_replace('_',' ',(string)$key)) . '</strong><br><span class="description">' . esc_html((string) ($row['detail'] ?? '')) . '</span></li>';
        }
        echo '</ul>';
    }

    private static function render_workflow_form(array $topic) {
        $topic_id = absint($topic['id'] ?? 0);
        if (!$topic_id) return;
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="seo-sol-workflow-form">';
        echo '<input type="hidden" name="action" value="seo_solucionador_topic"><input type="hidden" name="topic_id" value="' . esc_attr($topic_id) . '">';
        wp_nonce_field('seo_solucionador_topic_' . $topic_id);
        echo '<select name="topic_action">';
        foreach (array(
            'candidate'=>'Mantener candidato',
            'approved'=>'Aprobar actuación',
            'brief_ready'=>'Marcar brief listo',
            'deferred'=>'Aplazar',
            'rejected'=>'Rechazar',
            'closed'=>'Cerrar',
        ) as $value=>$label) {
            echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
        }
        echo '</select> ';
        echo '<input type="text" name="workflow_reason" required placeholder="Motivo obligatorio" style="min-width:340px"> ';
        submit_button('Guardar estado','secondary small','submit',false);
        echo '</form>';
    }

    private static function render_opportunity_detail(array $topic) {
        $brief = self::editorial_brief($topic);
        $entity_edit = self::entity_edit_url($brief['existing_entity_type'],$brief['existing_entity_id']);

        echo '<div id="opportunity" class="postbox" style="padding:20px;margin-top:18px;border-left:4px solid #2271b1">';
        echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap">';
        echo '<div><h2 style="margin:0 0 6px">Ficha de oportunidad #' . esc_html($brief['topic_id']) . '</h2><strong style="font-size:18px">' . esc_html($brief['topic']) . '</strong><br><code>' . esc_html((string) ($topic['canonical_key'] ?? '')) . '</code></div>';
        echo '<a class="button" href="' . esc_url(self::url('proposals')) . '">Cerrar ficha</a></div>';

        echo '<div class="seo-sol-grid" style="margin-top:14px">';
        self::card('Acción', $brief['output'], $brief['decision_reason']);
        self::card('Prioridad', number_format_i18n($brief['priority'],0) . '/100', 'Desglose visible, no decisión única.');
        self::card('Cobertura', self::coverage_label((string) ($brief['coverage']['status'] ?? 'uncovered')), 'Similitud ' . number_format_i18n((float)($brief['coverage']['score'] ?? 0)*100,0) . '%.');
        self::card('Workflow', self::workflow_label($brief['workflow_state']), 'Estado humano de la actuación.');
        self::card('Duplicación', number_format_i18n($brief['duplication_risk'],0) . '/100', 'Si el riesgo es alto no se crea URL nueva.');
        self::card('Canibalización', number_format_i18n($brief['cannibalization_risk'],0) . '/100', 'Evalúa competencia entre destinos existentes.');
        echo '</div>';

        echo '<div class="seo-sol-opportunity-sections">';

        echo '<section><h3>1. Resumen</h3>';
        echo '<p><strong>Qué se ha detectado:</strong> ' . esc_html($brief['question'] ?: $brief['topic']) . '</p>';
        echo '<p><strong>Intención:</strong> ' . esc_html($brief['intent'] ?: '—') . '</p>';
        if ($brief['audience']) echo '<p><strong>Audiencia:</strong> ' . esc_html(implode(' · ',$brief['audience'])) . '</p>';
        echo '<h4>Prioridad explicada</h4>'; self::render_priority_components($brief);
        echo '</section>';

        echo '<section><h3>2. Evidencias</h3>';
        echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>Fuente</th><th>Señal</th><th>Entidad</th><th>Categoría</th><th>Texto</th><th>Confianza</th><th>Fecha</th></tr></thead><tbody>';
        if (!$brief['evidence']) echo '<tr><td colspan="7">Sin evidencias.</td></tr>';
        foreach ($brief['evidence'] as $row) {
            echo '<tr><td><strong>' . esc_html((string)($row['source_type'] ?? '')) . '</strong><br><code>' . esc_html((string)($row['source_id'] ?? '')) . '</code></td>';
            echo '<td>' . esc_html((string)($row['signal_type'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string)($row['entity_type'] ?? '')) . ' #' . esc_html(absint($row['entity_id'] ?? 0) ?: '—') . '</td>';
            echo '<td>' . esc_html(absint($row['category_id'] ?? 0) ?: '—') . '</td>';
            echo '<td>' . esc_html(wp_trim_words((string)($row['source_text'] ?? ''),28,'…')) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float)($row['confidence'] ?? 0)*100,0)) . '%</td>';
            echo '<td>' . esc_html((string)($row['observed_at'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></div></section>';

        echo '<section><h3>3. Categoría</h3>';
        $h = (array) $brief['hierarchy'];
        foreach (array('cluster'=>'Cluster','hub_primary'=>'Hub primario','hub_secondary'=>'Hub secundario','category'=>'Categoría principal') as $key=>$label) {
            $row = (array) ($h[$key] ?? array());
            echo '<p><strong>' . esc_html($label) . ':</strong> ' . (!empty($row['id']) ? '#' . esc_html(absint($row['id'])) . ' · ' . esc_html((string)($row['name'] ?? '')) : '—') . '</p>';
        }
        echo '<p><strong>Categorías relacionadas:</strong> ' . self::category_chips($topic) . '</p>';
        echo '<p><strong>Vocabulary:</strong></p>' . self::vocab_chips($topic);
        echo '<p><strong>Productos de referencia propios:</strong></p><ul>';
        if (!$brief['products']) echo '<li>Sin productos relacionados recuperados.</li>';
        foreach ($brief['products'] as $product) echo '<li><a href="' . esc_url(get_edit_post_link($product['id'],'raw')) . '">#' . esc_html($product['id']) . ' ' . esc_html($product['title']) . '</a></li>';
        echo '</ul></section>';

        echo '<section><h3>4. Cobertura</h3>';
        echo '<p><strong>Estado:</strong> ' . esc_html(self::coverage_label((string)($brief['coverage']['status'] ?? 'uncovered'))) . ' · <strong>score:</strong> ' . esc_html(number_format_i18n((float)($brief['coverage']['score'] ?? 0)*100,0)) . '%</p>';
        echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>Destino</th><th>Tipo</th><th>Ámbito</th><th>Qué cubre</th><th>Similitud</th></tr></thead><tbody>';
        if (empty($brief['coverage']['matches'])) echo '<tr><td colspan="5">No existe una URL relacionada con cobertura suficiente.</td></tr>';
        foreach ((array)($brief['coverage']['matches'] ?? array()) as $match) {
            $url = (string)($match['url'] ?? '');
            echo '<tr><td>' . ($url !== '' ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html((string)($match['title'] ?? $url)) . '</a>' : esc_html((string)($match['title'] ?? ''))) . '</td>';
            echo '<td>' . esc_html((string)($match['entity_type'] ?? '')) . ' / ' . esc_html((string)($match['seo_role'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string)($match['scope'] ?? '')) . '</td>';
            echo '<td>' . esc_html(wp_trim_words((string)($match['source_text'] ?? ''),30,'…')) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float)($match['score'] ?? 0)*100,0)) . '%</td></tr>';
        }
        echo '</tbody></table></div>';
        if ($brief['preserve']) echo '<p><strong>No repetir / conservar:</strong> ' . esc_html(implode(' · ',$brief['preserve'])) . '</p>';
        echo '</section>';

        echo '<section><h3>5. Conocimiento</h3>';
        echo '<p><strong>Estado:</strong> ' . esc_html($brief['knowledge_status']) . '</p>';
        if (!$brief['knowledge']) echo '<p>No existe conocimiento técnico activo suficiente de Ingeniero.</p>';
        foreach ($brief['knowledge'] as $item) {
            echo '<div class="seo-sol-knowledge"><strong>' . esc_html((string)($item['type'] ?? '')) . '</strong>';
            if (!empty($item['concept'])) echo ' · ' . esc_html((string)$item['concept']);
            echo '<p>' . esc_html((string)($item['summary'] ?? '')) . '</p>';
            echo '<small>Confianza ' . esc_html(number_format_i18n((float)($item['confidence'] ?? 0)*100,0)) . '%</small>';
            if (!empty($item['sources'])) {
                echo '<ul>'; foreach ($item['sources'] as $src) {
                    echo '<li><a href="' . esc_url((string)$src['url']) . '" target="_blank" rel="noopener">' . esc_html((string)(($src['title'] ?? '') ?: $src['url'])) . '</a> · ' . esc_html((string)$src['source_type']) . ' · confianza fuente ' . esc_html((string)$src['trust_level']) . '</li>';
                } echo '</ul>';
            }
            echo '</div>';
        }
        if ($brief['concepts']) echo '<p><strong>Conceptos pendientes de Clasificador:</strong> ' . esc_html(implode(' · ',$brief['concepts'])) . '</p>';
        echo '</section>';

        echo '<section><h3>6. Mercado</h3>';
        if (!$brief['market']) echo '<p>No hay evidencia de Ojeador/Comparador asociada a este tema.</p>';
        foreach ($brief['market'] as $row) {
            echo '<div class="seo-sol-market"><strong>' . esc_html(ucfirst((string)$row['source'])) . '</strong> · ' . esc_html(wp_trim_words((string)$row['text'],28,'…'));
            $meta = (array)$row['meta'];
            if (!empty($meta['signal'])) echo '<br><span>' . esc_html((string)$meta['signal']) . '</span>';
            if (!empty($meta['reason'])) echo '<br><small>' . esc_html((string)$meta['reason']) . '</small>';
            if (!empty($meta['decisive_features'])) echo '<br><small>Factores de compra: ' . esc_html(implode(' · ',array_map('strval',(array)$meta['decisive_features']))) . '</small>';
            echo '</div>';
        }
        echo '</section>';

        echo '<section><h3>7. Decisión</h3>';
        echo '<p><strong>' . esc_html($brief['output']) . '</strong></p>';
        echo '<p>' . esc_html($brief['decision_reason']) . '</p>';
        echo '<p><strong>Riesgo de duplicación:</strong> ' . esc_html(number_format_i18n($brief['duplication_risk'],0)) . '/100 · <strong>canibalización:</strong> ' . esc_html(number_format_i18n($brief['cannibalization_risk'],0)) . '/100.</p>';
        echo '<h4>Condiciones obligatorias</h4>'; self::render_requirements($brief);
        self::render_workflow_form($topic);
        if ($entity_edit) echo '<p><a class="button" href="' . esc_url($entity_edit) . '">Abrir contenido existente para ejecutar</a></p>';
        if ($brief['action'] === 'CREATE_POST' && in_array($brief['workflow_state'],array('approved','brief_ready'),true) && !$brief['draft_post_id']) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_solucionador_topic"><input type="hidden" name="topic_id" value="' . esc_attr($brief['topic_id']) . '"><input type="hidden" name="topic_action" value="create_draft">';
            wp_nonce_field('seo_solucionador_topic_' . $brief['topic_id']);
            submit_button('Preparar borrador de post','primary','submit',false);
            echo '</form>';
        }
        if ($brief['action'] === 'CREATE_LANDING') echo '<p><a class="button button-primary" href="' . esc_url(self::diagnostics_url('pages','landings')) . '">Abrir gestión de landings</a></p>';
        echo '</section>';

        echo '<section><h3>8. Brief para Editora</h3>';
        echo '<p><strong>Objetivo:</strong> ' . esc_html($brief['question'] ?: $brief['topic']) . '</p>';
        if (!empty($brief['question_details'])) {
            echo '<h4>Preguntas aprendidas por Dependiente</h4>';
            echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>Pregunta</th><th>Lección / tipo</th><th>Validación</th><th>Resultado validado</th><th>Fecha</th></tr></thead><tbody>';
            foreach ($brief['question_details'] as $item) {
                $top = (array) ($item['top_results'] ?? array());
                $result_labels = array();
                foreach (array_slice($top,0,3) as $result) {
                    if (!is_array($result)) continue;
                    $label = trim((string)($result['title'] ?? $result['name'] ?? $result['label'] ?? ''));
                    if ($label !== '') $result_labels[] = $label;
                }
                $result_text = $result_labels ? implode(' · ',$result_labels) : 'Resultado interno conservado en la evidencia';
                echo '<tr><td><strong>' . esc_html((string)$item['question']) . '</strong></td>';
                echo '<td><code>' . esc_html((string)$item['lesson_key']) . '</code><br>' . esc_html((string)$item['question_type']) . '</td>';
                echo '<td><strong>' . esc_html((string)$item['evaluation_status']) . '</strong><br>score ' . esc_html(number_format_i18n((float)$item['evaluation_score']*100,0)) . '%</td>';
                echo '<td>' . esc_html(wp_trim_words($result_text,30,'…')) . '</td>';
                echo '<td>' . esc_html((string)$item['observed_at']) . '</td></tr>';
            }
            echo '</tbody></table></div>';
            echo '<p class="description">Estos resultados son evidencia interna de Academia. Editora debe redactar la respuesta pública; no se publican literalmente las respuestas internas.</p>';
        } elseif ($brief['questions']) {
            echo '<h4>Preguntas reales a responder</h4><ul>';
            foreach ($brief['questions'] as $q) echo '<li>' . esc_html($q) . '</li>';
            echo '</ul>';
        }
        if ($brief['must_cover']) { echo '<h4>Temas / conceptos obligatorios disponibles</h4><ul>'; foreach ($brief['must_cover'] as $item) echo '<li>' . esc_html(wp_trim_words($item,35,'…')) . '</li>'; echo '</ul>'; }
        if ($brief['preserve']) { echo '<h4>Contenido previo que no debe duplicarse</h4><ul>'; foreach ($brief['preserve'] as $item) echo '<li>' . esc_html($item) . '</li>'; echo '</ul>'; }
        if ($brief['links']) { echo '<h4>Enlaces internos recomendados</h4><ul>'; foreach ($brief['links'] as $link) echo '<li><a href="' . esc_url($link['url']) . '" target="_blank" rel="noopener">' . esc_html($link['label']) . '</a></li>'; echo '</ul>'; }
        if ($brief['technical_sources']) { echo '<h4>Fuentes técnicas verificadas</h4><ul>'; foreach ($brief['technical_sources'] as $src) echo '<li><a href="' . esc_url($src['url']) . '" target="_blank" rel="noopener">' . esc_html(($src['title'] ?? '') ?: $src['url']) . '</a> · ' . esc_html($src['source_type']) . ' · ' . esc_html($src['trust_level']) . '</li>'; echo '</ul>'; }
        echo '<p class="description"><strong>La Editora decide la redacción:</strong> Solucionador no fija extensión, estilo, frases, introducción ni texto final.</p>';
        echo '</section>';

        echo '<section><h3>9. Seguimiento</h3>';
        echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>Fecha</th><th>Entidad</th><th>Impresiones</th><th>Clics</th><th>Posición</th><th>Sesiones</th><th>Vistas</th><th>Resultado</th></tr></thead><tbody>';
        if (!$brief['tracking']) echo '<tr><td colspan="8">Aún no hay snapshots posteriores asociados.</td></tr>';
        foreach ($brief['tracking'] as $row) {
            echo '<tr><td>' . esc_html((string)($row['snapshot_at'] ?? '')) . '</td><td>' . esc_html((string)($row['entity_type'] ?? '')) . ' #' . esc_html(absint($row['entity_id'] ?? 0)) . '</td><td>' . esc_html(number_format_i18n(absint($row['impressions'] ?? 0))) . '</td><td>' . esc_html(number_format_i18n(absint($row['clicks'] ?? 0))) . '</td><td>' . esc_html(number_format_i18n((float)($row['position'] ?? 0),1)) . '</td><td>' . esc_html(number_format_i18n(absint($row['sessions'] ?? 0))) . '</td><td>' . esc_html(number_format_i18n(absint($row['pageviews'] ?? 0))) . '</td><td>' . esc_html((string)($row['outcome_state'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<h4>Historial de workflow</h4><ul>';
        if (!$brief['workflow']) echo '<li>Sin cambios manuales registrados todavía.</li>';
        foreach ($brief['workflow'] as $row) echo '<li><strong>' . esc_html(self::workflow_label((string)($row['to_state'] ?? ''))) . '</strong> · ' . esc_html((string)($row['created_at'] ?? '')) . ' · usuario #' . esc_html(absint($row['user_id'] ?? 0) ?: 'sistema') . ' · ' . esc_html((string)($row['reason'] ?? '')) . '</li>';
        echo '</ul></section>';

        echo '</div></div>';
    }

    // Compatibilidad con el enlace historico "brief_topic_id".
    private static function render_brief(array $topic) {
        self::render_opportunity_detail($topic);
    }

    private static function vocab_chips(array $topic) {
        $proposal = SEO_Solucionador_DB::proposed_vocabulary($topic);
        $labels = array('rol'=>'ROL','tipo'=>'TIPO','aplicacion'=>'APLICACION','plataforma'=>'PLATAFORMA','subtipo'=>'SUBTIPO');
        $html = '';
        foreach ($labels as $group => $label) {
            $items = (array) ($proposal[$group] ?? array());
            if (!$items) continue;
            $names = array();
            foreach ($items as $item) {
                if (is_array($item) && !empty($item['label'])) $names[] = (string) $item['label'];
            }
            if (!$names) continue;
            $html .= '<div class="seo-sol-meta"><strong>' . esc_html($label) . ':</strong> ' . esc_html(implode(' · ', $names)) . '</div>';
        }
        return $html ?: '<span class="seo-sol-warning">Sin Vocabulary suficiente</span>';
    }

    private static function category_chips(array $topic) {
        $rows = SEO_Solucionador_DB::proposed_categories($topic);
        if (!$rows) return '<span class="seo-sol-warning">Sin categoria suficiente</span>';
        $names = array();
        foreach ($rows as $row) if (is_array($row) && !empty($row['name'])) $names[] = (string) $row['name'];
        return $names ? esc_html(implode(' · ', $names)) : '<span class="seo-sol-warning">Sin categoria suficiente</span>';
    }

    private static function landing_decision_label($status) {
        $status = sanitize_key((string) $status);
        $labels = array(
            'detected'=>'Revisar si crear landing',
            'candidate'=>'Revisar si crear landing',
            'review'=>'Revisar si crear landing',
            'approved'=>'Crear landing',
            'created'=>'Landing creada',
            'published'=>'Landing publicada',
            'paused'=>'No actuar ahora',
            'rejected_requirements'=>'No crear',
        );
        return $labels[$status] ?? 'Revisar';
    }

    private static function render_landing_decisions() {
        if (!function_exists('seo_landing_get_candidates')) return;
        $rows = (array) seo_landing_get_candidates(250);

        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Decisiones de landing</h2>';
        echo '<p class="description">Las candidatas y su scoring siguen usando el motor de landings existente, pero el diagnóstico visible y la decisión editorial se concentran aquí. La edición/creación final de la página continúa en Páginas.</p>';

        if (!$rows) {
            echo '<p>No hay candidatas de landing registradas.</p></div>';
            return;
        }

        $types = function_exists('seo_landing_types') ? (array) seo_landing_types() : array();
        $statuses = function_exists('seo_landing_statuses') ? (array) seo_landing_statuses() : array();

        echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>Prioridad</th><th>Tema / intención</th><th>Tipo y fuente</th><th>Requisitos</th><th>Diagnóstico</th><th>Estado / decisión</th><th>Acción</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $status = sanitize_key((string) ($row->status ?? 'detected'));
            $score = (float) ($row->total_score ?? 0);
            $requirements = function_exists('seo_landing_decode_json') ? seo_landing_decode_json($row->requirements_json ?? '') : array();
            $requirements_label = function_exists('seo_landing_requirements_summary')
                ? seo_landing_requirements_summary($requirements)
                : '—';
            $type_key = sanitize_key((string) ($row->landing_type ?? ''));
            $type = (string) ($types[$type_key] ?? ($type_key !== '' ? $type_key : 'Pendiente'));
            $status_label = (string) ($statuses[$status] ?? $status);
            $diagnostic = trim((string) ($row->existing_destination ?? ''));
            $reason = trim((string) ($row->differentiation_reason ?? ''));
            if ($reason !== '') $diagnostic .= ($diagnostic !== '' ? ' — ' : '') . $reason;
            if ($diagnostic === '') $diagnostic = 'Pendiente de diagnóstico editorial.';

            echo '<tr>';
            echo '<td><strong class="seo-sol-score">' . esc_html(number_format_i18n($score, 0)) . '</strong><span class="description">/100</span></td>';
            echo '<td><strong>' . esc_html((string) ($row->title ?? '')) . '</strong>';
            if (!empty($row->intent)) echo '<div class="description" style="margin-top:5px">' . esc_html(wp_trim_words((string) $row->intent, 30)) . '</div>';
            echo '</td>';
            echo '<td><strong>' . esc_html($type) . '</strong><br><small>' . esc_html((string) ($row->source ?? '')) . '</small></td>';
            echo '<td>' . esc_html($requirements_label) . '</td>';
            echo '<td>' . esc_html(wp_trim_words($diagnostic, 32)) . '</td>';
            echo '<td><strong>' . esc_html(self::landing_decision_label($status)) . '</strong><br><small>' . esc_html($status_label) . '</small></td>';
            echo '<td><a class="button button-small" href="' . esc_url(self::diagnostics_url('pages','landings', array('candidate_id'=>absint($row->id ?? 0)))) . '">Revisar / editar decisión</a>';
            $page_id = absint($row->page_id ?? 0);
            if ($page_id > 0 && get_post_type($page_id) === 'page') {
                $edit = get_edit_post_link($page_id, 'raw');
                if ($edit) echo '<br><a href="' . esc_url($edit) . '">Abrir página #' . esc_html($page_id) . '</a>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function render_proposals() {
        global $wpdb;
        $topics_table = SEO_Solucionador_DB::topics_table();
        $evidence_table = SEO_Solucionador_DB::evidence_table();

        $rows = (array) $wpdb->get_results(
            "SELECT * FROM {$topics_table}
             ORDER BY priority_score DESC,evidence_total DESC,id DESC
             LIMIT 1200",
            ARRAY_A
        );

        $category_filter = absint($_GET['sol_category'] ?? 0);
        $cluster_filter = absint($_GET['sol_cluster'] ?? 0);
        $source_filter = sanitize_key(wp_unslash($_GET['sol_source'] ?? ''));
        $action_filter = strtoupper(sanitize_text_field(wp_unslash($_GET['sol_action'] ?? '')));
        $coverage_filter = sanitize_key(wp_unslash($_GET['sol_coverage'] ?? ''));
        $priority_filter = sanitize_key(wp_unslash($_GET['sol_priority'] ?? ''));
        $state_filter = sanitize_key(wp_unslash($_GET['sol_state'] ?? ''));

        $source_topic_ids = array();
        if ($source_filter !== '' && SEO_Solucionador_DB::table_exists($evidence_table)) {
            $source_topic_ids = array_flip(array_map('absint',(array) $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT topic_id FROM {$evidence_table} WHERE source_type=%s",
                $source_filter
            ))));
        }

        $clusters = array();
        $categories = array();
        foreach ($rows as $row) {
            $hierarchy = SEO_Solucionador_DB::hierarchy($row);
            $cluster = (array) ($hierarchy['cluster'] ?? array());
            if (!empty($cluster['id'])) $clusters[absint($cluster['id'])] = (string) ($cluster['name'] ?? ('#' . absint($cluster['id'])));
            $term_id = absint($row['primary_category_id'] ?? 0);
            if ($term_id && !isset($categories[$term_id])) {
                $term = get_term($term_id,'product_cat');
                $categories[$term_id] = ($term && !is_wp_error($term)) ? (string) $term->name : ('#' . $term_id);
            }
        }
        asort($clusters,SORT_NATURAL|SORT_FLAG_CASE);
        asort($categories,SORT_NATURAL|SORT_FLAG_CASE);

        $rows = array_values(array_filter($rows,static function($row) use ($category_filter,$cluster_filter,$source_filter,$source_topic_ids,$action_filter,$coverage_filter,$priority_filter,$state_filter) {
            if ($category_filter && absint($row['primary_category_id'] ?? 0) !== $category_filter) return false;
            if ($source_filter !== '' && empty($source_topic_ids[absint($row['id'] ?? 0)])) return false;
            if ($action_filter !== '' && strtoupper((string)($row['recommended_action'] ?? '')) !== $action_filter) return false;
            if ($coverage_filter !== '' && sanitize_key((string)($row['coverage_status'] ?? '')) !== $coverage_filter) return false;
            if ($state_filter !== '' && sanitize_key((string)($row['workflow_state'] ?? '')) !== $state_filter) return false;
            if ($cluster_filter) {
                $hier = SEO_Solucionador_DB::hierarchy($row);
                if (absint($hier['cluster']['id'] ?? 0) !== $cluster_filter) return false;
            }
            $priority = (float) ($row['priority_score'] ?? 0);
            if ($priority_filter === 'high' && $priority < 70) return false;
            if ($priority_filter === 'medium' && ($priority < 40 || $priority >= 70)) return false;
            if ($priority_filter === 'low' && $priority >= 40) return false;
            return true;
        }));

        $source_options = SEO_Solucionador_DB::table_exists($evidence_table)
            ? (array) $wpdb->get_col("SELECT DISTINCT source_type FROM {$evidence_table} ORDER BY source_type ASC")
            : array();

        $detail_id = absint($_GET['topic_id'] ?? $_GET['brief_topic_id'] ?? 0);
        if ($detail_id) {
            $detail = SEO_Solucionador_DB::get_topic($detail_id);
            if ($detail) self::render_opportunity_detail($detail);
        }

        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Oportunidades y decisiones editoriales</h2>';
        echo '<p class="description">Una fila representa un <strong>tema canónico</strong>, no una pregunta aislada. La decisión sigue el orden: comprobar cobertura → mejorar/fusionar → investigar si falta conocimiento → crear URL solo como última opción.</p>';

        echo '<form method="get" class="seo-sol-filters">';
        echo '<input type="hidden" name="page" value="seo-solucionador"><input type="hidden" name="tab" value="proposals">';

        echo '<label>Categoría<select name="sol_category"><option value="0">Todas</option>';
        foreach ($categories as $id=>$name) echo '<option value="' . esc_attr($id) . '" ' . selected($category_filter,$id,false) . '>' . esc_html($name) . ' (#' . esc_html($id) . ')</option>';
        echo '</select></label>';

        echo '<label>Cluster<select name="sol_cluster"><option value="0">Todos</option>';
        foreach ($clusters as $id=>$name) echo '<option value="' . esc_attr($id) . '" ' . selected($cluster_filter,$id,false) . '>' . esc_html($name) . '</option>';
        echo '</select></label>';

        echo '<label>Fuente<select name="sol_source"><option value="">Todas</option>';
        foreach ($source_options as $source) echo '<option value="' . esc_attr($source) . '" ' . selected($source_filter,$source,false) . '>' . esc_html($source) . '</option>';
        echo '</select></label>';

        $actions = array('NO_ACTION','IMPROVE_POST','IMPROVE_LANDING','IMPROVE_CATEGORY','IMPROVE_PAGE','MERGE_CONTENT','CREATE_POST','CREATE_LANDING','INVESTIGATE','DEFER');
        echo '<label>Acción<select name="sol_action"><option value="">Todas</option>';
        foreach ($actions as $action) echo '<option value="' . esc_attr($action) . '" ' . selected($action_filter,$action,false) . '>' . esc_html(self::action_label($action)) . '</option>';
        echo '</select></label>';

        echo '<label>Cobertura<select name="sol_coverage"><option value="">Todas</option>';
        foreach (array('uncovered','weak_coverage','partial_coverage','covered','duplicate','conflict') as $status) echo '<option value="' . esc_attr($status) . '" ' . selected($coverage_filter,$status,false) . '>' . esc_html(self::coverage_label($status)) . '</option>';
        echo '</select></label>';

        echo '<label>Prioridad<select name="sol_priority"><option value="">Todas</option><option value="high" ' . selected($priority_filter,'high',false) . '>70–100</option><option value="medium" ' . selected($priority_filter,'medium',false) . '>40–69</option><option value="low" ' . selected($priority_filter,'low',false) . '>0–39</option></select></label>';

        echo '<label>Estado<select name="sol_state"><option value="">Todos</option>';
        foreach (SEO_Solucionador_DB::workflow_states() as $state) echo '<option value="' . esc_attr($state) . '" ' . selected($state_filter,$state,false) . '>' . esc_html(self::workflow_label($state)) . '</option>';
        echo '</select></label>';

        echo '<div><button class="button button-primary" type="submit">Filtrar</button> <a class="button" href="' . esc_url(self::url('proposals')) . '">Limpiar</a></div>';
        echo '</form>';

        echo '<p><strong>' . esc_html(number_format_i18n(count($rows))) . '</strong> oportunidades con los filtros actuales.</p>';
        echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>Prioridad</th><th>Tema / categoría</th><th>Evidencias</th><th>Cobertura / riesgos</th><th>Decisión</th><th>Workflow</th><th>Acción</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="7">No hay oportunidades con estos filtros.</td></tr>';

        foreach (array_slice($rows,0,500) as $row) {
            $topic_id = absint($row['id'] ?? 0);
            $term_id = absint($row['primary_category_id'] ?? 0);
            $category_name = $term_id ? ($categories[$term_id] ?? ('#' . $term_id)) : 'Sin categoría principal';
            $source_counts = array();
            if ($topic_id && SEO_Solucionador_DB::table_exists($evidence_table)) {
                foreach ((array) $wpdb->get_results($wpdb->prepare(
                    "SELECT source_type,SUM(occurrences) total FROM {$evidence_table} WHERE topic_id=%d GROUP BY source_type ORDER BY total DESC",
                    $topic_id
                ),ARRAY_A) as $src) {
                    $source_counts[] = (string)$src['source_type'] . ' ' . number_format_i18n(absint($src['total'] ?? 0));
                }
            }

            echo '<tr>';
            echo '<td><strong class="seo-sol-score">' . esc_html(number_format_i18n((float)($row['priority_score'] ?? 0),0)) . '</strong>/100</td>';
            echo '<td><strong>' . esc_html((string)(($row['suggested_title'] ?? '') ?: ($row['canonical_question'] ?? ''))) . '</strong><br><span class="description">' . esc_html($category_name) . ($term_id ? ' (#' . $term_id . ')' : '') . '</span><br><code>' . esc_html((string)($row['canonical_key'] ?? '')) . '</code></td>';
            echo '<td>' . ($source_counts ? esc_html(implode(' · ',$source_counts)) : '—') . '</td>';
            echo '<td><strong>' . esc_html(self::coverage_label((string)($row['coverage_status'] ?? 'uncovered'))) . '</strong><br><small>dup. ' . esc_html(number_format_i18n((float)($row['duplication_risk'] ?? 0),0)) . '/100 · canib. ' . esc_html(number_format_i18n((float)($row['cannibalization_risk'] ?? 0),0)) . '/100</small></td>';
            echo '<td><strong>' . esc_html(self::action_label((string)($row['recommended_action'] ?? 'DEFER'))) . '</strong><br><small>' . esc_html(wp_trim_words((string)($row['decision_reason'] ?? ''),24,'…')) . '</small></td>';
            echo '<td><strong>' . esc_html(self::workflow_label((string)($row['workflow_state'] ?? 'detected'))) . '</strong><br><small>' . esc_html((string)($row['knowledge_status'] ?? '')) . '</small></td>';

            echo '<td><a class="button button-primary button-small" href="' . esc_url(self::url('proposals',array('topic_id'=>$topic_id))) . '#opportunity">Abrir ficha</a>';
            $edit = self::entity_edit_url((string)($row['existing_entity_type'] ?? ''),absint($row['existing_entity_id'] ?? 0));
            if ($edit) echo '<br><a class="button button-small" style="margin-top:5px" href="' . esc_url($edit) . '">Editar existente</a>';
            $draft = absint($row['draft_post_id'] ?? 0);
            if ($draft && get_post_type($draft)==='post' && get_post_status($draft)!=='trash') echo '<br><a style="margin-top:5px;display:inline-block" href="' . esc_url(SEO_Solucionador_Posts::edit_url($draft)) . '">Abrir borrador #' . esc_html($draft) . '</a>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function render_coverage() {
        global $wpdb;
        $table = SEO_Solucionador_DB::coverage_table();

        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Cobertura editorial existente</h2>';
        echo '<p class="description">Índice multientidad usado para evitar duplicación y canibalización. Incluye posts, páginas, landings, hubs y categorías; analiza título, H1/H2/H3, contenido, categorías asociadas y Vocabulary.</p>';

        if (!SEO_Solucionador_DB::table_exists($table)) {
            echo '<p>El índice todavía no existe. Ejecuta Reanalizar fuentes desde Resumen.</p></div>';
            return;
        }

        $entity_filter = sanitize_key(wp_unslash($_GET['coverage_entity'] ?? ''));
        $category_filter = absint($_GET['coverage_category'] ?? 0);
        $where = array('1=1');
        $params = array();
        if ($entity_filter !== '') { $where[]='entity_type=%s'; $params[]=$entity_filter; }
        if ($category_filter) { $where[]='category_id=%d'; $params[]=$category_filter; }

        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ',$where) . " ORDER BY entity_type,entity_id,scope,id LIMIT 1200";
        if ($params) $sql = $wpdb->prepare($sql,$params);
        $rows = (array) $wpdb->get_results($sql,ARRAY_A);

        $counts = (array) $wpdb->get_results("SELECT entity_type,COUNT(DISTINCT entity_id) entities,COUNT(*) fingerprints FROM {$table} GROUP BY entity_type ORDER BY entity_type",ARRAY_A);
        echo '<div class="seo-sol-grid">';
        foreach ($counts as $count) self::card((string)$count['entity_type'],absint($count['entities'] ?? 0),number_format_i18n(absint($count['fingerprints'] ?? 0)) . ' huellas semánticas');
        echo '</div>';

        echo '<form method="get" class="seo-sol-filters"><input type="hidden" name="page" value="seo-solucionador"><input type="hidden" name="tab" value="coverage">';
        echo '<label>Entidad<select name="coverage_entity"><option value="">Todas</option>';
        foreach (array('post'=>'Posts','page'=>'Páginas / landings / hubs','product_cat'=>'Categorías') as $value=>$label) echo '<option value="' . esc_attr($value) . '" ' . selected($entity_filter,$value,false) . '>' . esc_html($label) . '</option>';
        echo '</select></label>';
        echo '<label>Categoría ID<input type="number" min="0" name="coverage_category" value="' . esc_attr($category_filter ?: '') . '" placeholder="term_id"></label>';
        echo '<div><button class="button button-primary">Filtrar</button> <a class="button" href="' . esc_url(self::url('coverage')) . '">Limpiar</a></div></form>';

        echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>Entidad</th><th>Rol</th><th>Categoría</th><th>Ámbito</th><th>Texto detectado</th><th>Tema canónico</th><th>Confianza</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="7">Sin huellas con estos filtros.</td></tr>';
        foreach ($rows as $row) {
            $edit = self::entity_edit_url((string)($row['entity_type'] ?? ''),absint($row['entity_id'] ?? 0));
            echo '<tr><td><strong>' . esc_html((string)($row['entity_type'] ?? '')) . ' #' . esc_html(absint($row['entity_id'] ?? 0)) . '</strong>';
            if (!empty($row['title'])) echo '<br>' . esc_html((string)$row['title']);
            if ($edit) echo '<br><a href="' . esc_url($edit) . '">Editar</a>';
            echo '</td><td>' . esc_html((string)($row['seo_role'] ?? '')) . '</td><td>' . esc_html(absint($row['category_id'] ?? 0) ?: '—') . '</td><td>' . esc_html((string)($row['scope'] ?? '')) . '</td><td>' . esc_html(wp_trim_words((string)($row['source_text'] ?? ''),32,'…')) . '</td><td><code>' . esc_html((string)($row['canonical_key'] ?? '')) . '</code></td><td>' . esc_html(number_format_i18n((float)($row['confidence'] ?? 0)*100,0)) . '%</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function render_sources() {
        global $wpdb;
        $e = SEO_Solucionador_DB::evidence_table();
        $counts = SEO_Solucionador_DB::table_exists($e)
            ? (array) $wpdb->get_results("SELECT source_type,SUM(occurrences) evidence FROM {$e} GROUP BY source_type ORDER BY evidence DESC", ARRAY_A)
            : array();
        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Fuentes del Solucionador</h2>';
        echo '<p><strong>Dependiente / Intérprete · demanda real:</strong> conserva las consultas de visitantes, intent, objeto, contexto, resultados y feedback.</p>';
        echo '<p><strong>Dependiente / Academia · conocimiento aprendido:</strong> Solucionador lee las preguntas cuyo último run está validado <code>pass_*</code>, conserva su resultado y las agrupa por <code>product_cat</code> en dossiers editoriales. Dependiente no se modifica ni se vuelve a entrenar desde aquí.</p>';
        echo '<p><strong>Analista:</strong> las busquedas internas pueden originar propuestas. El plan de decision normalmente solo refuerza; MEJORAR_PRODUCTO/IMPULSAR_CATEGORIA no se convierten en preguntas de cliente.</p>';
        echo '<p><strong>Auditor:</strong> aporta diagnóstico, carencias y cobertura; Solucionador decide si esos hallazgos requieren actuación editorial.</p>';
        echo '<p><strong>Comentarista:</strong> aporta problemas o preguntas observadas en experiencias externas almacenadas.</p>';
        echo '<p><strong>Ojeador:</strong> aporta conclusiones de mercado y oportunidad ya calculadas; Solucionador no consulta Google Shopping por su cuenta.</p>';
        echo '<p><strong>Comparador:</strong> cuando publique su análisis ampliado, aporta tipologías, configuraciones, factores decisivos, ventajas/limitaciones y referencias representativas mediante un contrato normalizado. Solucionador no repite su cálculo.</p>';
        echo '<p><strong>Ingeniero:</strong> aporta conocimiento técnico validado por categoría, con fuentes, URL, tipo, confianza y fecha.</p>';
        echo '<p><strong>Clasificador:</strong> aporta huecos y estructura semántica detectados a partir del catálogo y de Ingeniero.</p>';
        echo '<p><strong>Marketing:</strong> puede reforzar prioridad comercial, campaña o estacionalidad; nunca origina por sí solo una URL nueva.</p>';
        echo '<p><strong>Entradas/Páginas/Categorías:</strong> aportan inventario, rendimiento y cobertura; sus editores quedan como lugares de ejecución.</p>';
        self::render_service_snapshot();

        if (class_exists('SEO_Solucionador_Sources') && method_exists('SEO_Solucionador_Sources','dependiente_academia_snapshot')) {
            $academy = SEO_Solucionador_Sources::dependiente_academia_snapshot();
            echo '<h3>Dependiente / Academia · cobertura del conocimiento aprendido</h3>';
            echo '<div class="seo-sol-grid">';
            self::card('Preguntas Academia', absint($academy['questions_total'] ?? 0), 'Preguntas activas del currículo con seguimiento.');
            self::card('Aprendidas', absint($academy['learned'] ?? 0), 'Último run respondido con evaluation_status pass_*.');
            self::card('No aprendidas', absint($academy['not_learned'] ?? 0), 'Sin pass_* en su última ejecución o todavía sin ejecución.');
            self::card('Aprendidas con categoría', absint($academy['learned_with_category'] ?? 0), 'Pueden formar parte de un dossier editorial.');
            self::card('Aprendidas sin categoría', absint($academy['learned_without_category'] ?? 0), 'No se fuerza una asociación si product_cat no puede demostrarse.');
            self::card('Categorías con conocimiento', absint($academy['categories_with_knowledge'] ?? 0), 'Dossiers que Solucionador puede analizar.');
            self::card('Categorías sin conocimiento', absint($academy['categories_without_knowledge'] ?? 0), 'product_cat existentes todavía sin preguntas aprendidas asociables.');
            self::card('Media preguntas / categoría', (float)($academy['avg_questions_per_category'] ?? 0), 'Asignaciones aprendidas entre categorías con conocimiento.');
            echo '</div>';
            echo '<p class="description">Última ejecución de Academia: <strong>' . esc_html((string)(($academy['last_run_at'] ?? '') ?: '—')) . '</strong>. Un dossier no implica automáticamente CREATE_POST: después se comprueban cobertura, conocimiento, duplicación y canibalización.</p>';
        }

        if ($counts) {
            echo '<h3>Evidencias acumuladas en propuestas</h3><ul>';
            foreach ($counts as $row) echo '<li><strong>' . esc_html((string) $row['source_type']) . ':</strong> ' . esc_html(number_format_i18n(absint($row['evidence'] ?? 0))) . '</li>';
            echo '</ul>';
        }
        echo '</div>';
    }

    private static function render_tests() {
        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Tests funcionales obligatorios · RF v1.0</h2>';
        echo '<p class="description">Regresiones deterministas definidas por Dirección/Editora/Marketing. No crean, editan ni publican contenido.</p>';

        if (!class_exists('SEO_Solucionador_Tests')) {
            echo '<div class="notice notice-error inline"><p>No está cargado el módulo de tests.</p></div></div>';
            return;
        }

        $report = SEO_Solucionador_Tests::run();
        $ok = !empty($report['ok']);
        echo '<div class="notice notice-' . ($ok ? 'success' : 'error') . ' inline"><p><strong>' . esc_html(number_format_i18n(absint($report['passed'] ?? 0))) . '/' . esc_html(number_format_i18n(absint($report['total'] ?? 0))) . ' tests superados.</strong>';
        if (!$ok) echo ' No debe promocionarse a producción hasta revisar los fallos.';
        echo '</p></div>';

        echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>Test</th><th>Resultado</th><th>Esperado</th><th>Obtenido</th><th>Regla</th></tr></thead><tbody>';
        foreach ((array)($report['tests'] ?? array()) as $row) {
            echo '<tr><td><strong>#' . esc_html(absint($row['id'] ?? 0)) . ' · ' . esc_html((string)($row['name'] ?? '')) . '</strong></td>';
            echo '<td><strong style="color:' . (!empty($row['pass']) ? '#008a20' : '#b32d2e') . '">' . (!empty($row['pass']) ? 'OK' : 'FALLO') . '</strong></td>';
            echo '<td>' . esc_html((string)($row['expected'] ?? '')) . '</td>';
            echo '<td><code>' . esc_html((string)($row['actual'] ?? '')) . '</code></td>';
            echo '<td>' . esc_html((string)($row['detail'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function render_data() {
        global $wpdb;

        $tables = array(
            'Temas / decisiones'=>SEO_Solucionador_DB::topics_table(),
            'Evidencias'=>SEO_Solucionador_DB::evidence_table(),
            'Cobertura multientidad'=>SEO_Solucionador_DB::coverage_table(),
            'Workflow'=>SEO_Solucionador_DB::workflow_table(),
            'Seguimiento'=>SEO_Solucionador_DB::tracking_table(),
            'Cobertura posts (compatibilidad)'=>SEO_Solucionador_DB::post_topics_table(),
        );

        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Datos internos de Solucionador</h2>';
        echo '<p class="description">Vista de solo lectura para comprobar trazabilidad. La interfaz editorial normal está en Propuestas y Cobertura; esta pestaña muestra la persistencia que las sustenta.</p>';

        foreach ($tables as $label=>$table) {
            echo '<h3 style="margin-top:24px">' . esc_html($label) . ' · <code>' . esc_html($table) . '</code></h3>';
            if (!SEO_Solucionador_DB::table_exists($table)) {
                echo '<p class="seo-sol-warning">La tabla no existe todavía.</p>';
                continue;
            }

            $count = absint($wpdb->get_var("SELECT COUNT(*) FROM {$table}"));
            echo '<p><strong>' . esc_html(number_format_i18n($count)) . '</strong> filas totales.</p>';

            if ($table === SEO_Solucionador_DB::topics_table()) {
                $rows = (array)$wpdb->get_results("SELECT id,suggested_title,primary_category_id,coverage_status,recommended_action,workflow_state,knowledge_status,duplication_risk,cannibalization_risk,priority_score,evidence_total,last_analyzed_at FROM {$table} ORDER BY priority_score DESC,id DESC LIMIT 300",ARRAY_A);
                echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>ID</th><th>Tema</th><th>Categoría</th><th>Cobertura</th><th>Decisión</th><th>Workflow</th><th>Conocimiento</th><th>Riesgos</th><th>Prioridad</th><th>Evidencias</th><th>Analizado</th></tr></thead><tbody>';
                foreach ($rows as $row) echo '<tr><td>' . esc_html(absint($row['id'])) . '</td><td>' . esc_html((string)$row['suggested_title']) . '</td><td>' . esc_html(absint($row['primary_category_id']) ?: '—') . '</td><td>' . esc_html((string)$row['coverage_status']) . '</td><td><strong>' . esc_html((string)$row['recommended_action']) . '</strong></td><td>' . esc_html((string)$row['workflow_state']) . '</td><td>' . esc_html((string)$row['knowledge_status']) . '</td><td>dup ' . esc_html(number_format_i18n((float)$row['duplication_risk'],0)) . ' / canib ' . esc_html(number_format_i18n((float)$row['cannibalization_risk'],0)) . '</td><td>' . esc_html(number_format_i18n((float)$row['priority_score'],0)) . '</td><td>' . esc_html(number_format_i18n(absint($row['evidence_total']))) . '</td><td>' . esc_html((string)$row['last_analyzed_at']) . '</td></tr>';
                echo '</tbody></table></div>';
                continue;
            }

            if ($table === SEO_Solucionador_DB::evidence_table()) {
                $rows = (array)$wpdb->get_results("SELECT id,topic_id,source_type,source_id,signal_type,entity_type,entity_id,category_id,confidence,evidence_score,occurrences,observed_at FROM {$table} ORDER BY topic_id DESC,evidence_score DESC,id DESC LIMIT 300",ARRAY_A);
                echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>ID</th><th>Tema</th><th>Fuente</th><th>Señal</th><th>Entidad</th><th>Categoría</th><th>Confianza</th><th>Peso</th><th>Ocurrencias</th><th>Observado</th></tr></thead><tbody>';
                foreach ($rows as $row) echo '<tr><td>' . esc_html(absint($row['id'])) . '</td><td>' . esc_html(absint($row['topic_id'])) . '</td><td>' . esc_html((string)$row['source_type']) . '<br><code>' . esc_html((string)$row['source_id']) . '</code></td><td>' . esc_html((string)$row['signal_type']) . '</td><td>' . esc_html((string)$row['entity_type']) . ' #' . esc_html(absint($row['entity_id']) ?: '—') . '</td><td>' . esc_html(absint($row['category_id']) ?: '—') . '</td><td>' . esc_html(number_format_i18n((float)$row['confidence']*100,0)) . '%</td><td>' . esc_html(number_format_i18n((float)$row['evidence_score'],2)) . '</td><td>' . esc_html(number_format_i18n(absint($row['occurrences']))) . '</td><td>' . esc_html((string)$row['observed_at']) . '</td></tr>';
                echo '</tbody></table></div>';
                continue;
            }

            if ($table === SEO_Solucionador_DB::coverage_table()) {
                $rows = (array)$wpdb->get_results("SELECT id,entity_type,entity_id,seo_role,category_id,scope,title,canonical_key,confidence,updated_at FROM {$table} ORDER BY id DESC LIMIT 300",ARRAY_A);
                echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>ID</th><th>Entidad</th><th>Rol</th><th>Categoría</th><th>Ámbito</th><th>Título</th><th>Huella</th><th>Confianza</th><th>Actualizado</th></tr></thead><tbody>';
                foreach ($rows as $row) echo '<tr><td>' . esc_html(absint($row['id'])) . '</td><td>' . esc_html((string)$row['entity_type']) . ' #' . esc_html(absint($row['entity_id'])) . '</td><td>' . esc_html((string)$row['seo_role']) . '</td><td>' . esc_html(absint($row['category_id']) ?: '—') . '</td><td>' . esc_html((string)$row['scope']) . '</td><td>' . esc_html((string)$row['title']) . '</td><td><code>' . esc_html((string)$row['canonical_key']) . '</code></td><td>' . esc_html(number_format_i18n((float)$row['confidence']*100,0)) . '%</td><td>' . esc_html((string)$row['updated_at']) . '</td></tr>';
                echo '</tbody></table></div>';
                continue;
            }

            if ($table === SEO_Solucionador_DB::workflow_table()) {
                $rows = (array)$wpdb->get_results("SELECT id,topic_id,from_state,to_state,action_code,reason,user_id,created_at FROM {$table} ORDER BY id DESC LIMIT 300",ARRAY_A);
                echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>ID</th><th>Tema</th><th>De</th><th>A</th><th>Acción</th><th>Motivo</th><th>Usuario</th><th>Fecha</th></tr></thead><tbody>';
                foreach ($rows as $row) echo '<tr><td>' . esc_html(absint($row['id'])) . '</td><td>' . esc_html(absint($row['topic_id'])) . '</td><td>' . esc_html((string)$row['from_state']) . '</td><td><strong>' . esc_html((string)$row['to_state']) . '</strong></td><td>' . esc_html((string)$row['action_code']) . '</td><td>' . esc_html((string)$row['reason']) . '</td><td>' . esc_html(absint($row['user_id']) ?: 'sistema') . '</td><td>' . esc_html((string)$row['created_at']) . '</td></tr>';
                echo '</tbody></table></div>';
                continue;
            }

            if ($table === SEO_Solucionador_DB::tracking_table()) {
                $rows = (array)$wpdb->get_results("SELECT id,topic_id,entity_type,entity_id,period_days,impressions,clicks,position,sessions,pageviews,outcome_state,snapshot_at FROM {$table} ORDER BY snapshot_at DESC,id DESC LIMIT 300",ARRAY_A);
                echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>ID</th><th>Tema</th><th>Entidad</th><th>Periodo</th><th>Impresiones</th><th>Clics</th><th>Posición</th><th>Sesiones</th><th>Vistas</th><th>Resultado</th><th>Fecha</th></tr></thead><tbody>';
                foreach ($rows as $row) echo '<tr><td>' . esc_html(absint($row['id'])) . '</td><td>' . esc_html(absint($row['topic_id'])) . '</td><td>' . esc_html((string)$row['entity_type']) . ' #' . esc_html(absint($row['entity_id'])) . '</td><td>' . esc_html(absint($row['period_days'])) . 'd</td><td>' . esc_html(number_format_i18n(absint($row['impressions']))) . '</td><td>' . esc_html(number_format_i18n(absint($row['clicks']))) . '</td><td>' . esc_html(number_format_i18n((float)$row['position'],1)) . '</td><td>' . esc_html(number_format_i18n(absint($row['sessions']))) . '</td><td>' . esc_html(number_format_i18n(absint($row['pageviews']))) . '</td><td>' . esc_html((string)$row['outcome_state']) . '</td><td>' . esc_html((string)$row['snapshot_at']) . '</td></tr>';
                echo '</tbody></table></div>';
                continue;
            }

            echo '<p class="description">Tabla conservada por compatibilidad. La cobertura operativa actual está en ' . esc_html(SEO_Solucionador_DB::coverage_table()) . '.</p>';
        }
        echo '</div>';
    }

    private static function render_export_button() {
        echo '<div style="margin:12px 0 16px">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block">';
        echo '<input type="hidden" name="action" value="seo_solucionador_export_json">';
        wp_nonce_field('seo_solucionador_export_json');
        submit_button('Descargar resultados JSON', 'secondary', 'submit', false);
        echo '</form>';
        echo '<span class="description" style="margin-left:10px">Incluye decisiones, evidencias, cobertura multientidad, workflow, seguimiento y último análisis.</span>';
        echo '</div>';
    }

    private static function card($label, $value, $desc) {
        $display = is_numeric($value) ? number_format_i18n((float) $value, floor((float)$value)==(float)$value ? 0 : 1) : (string) $value;
        echo '<div class="seo-sol-card"><span>' . esc_html($label) . '</span><strong>' . esc_html($display) . '</strong><small>' . esc_html($desc) . '</small></div>';
    }

    private static function styles() {
        echo '<style>
            .seo-sol-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin-top:18px}
            .seo-sol-card{background:#fff;border:1px solid #dcdcde;border-radius:7px;padding:16px}
            .seo-sol-card span,.seo-sol-card small{display:block;color:#646970}
            .seo-sol-card strong{display:block;font-size:28px;line-height:1.2;margin:6px 0}
            .seo-sol-table{overflow:auto}.seo-sol-table table{min-width:1280px}
            .seo-sol-table code{font-size:11px;word-break:break-all}.seo-sol-meta{margin:0 0 5px}
            .seo-sol-warning{color:#996800;font-size:12px}.seo-sol-score{font-size:20px}
            .seo-sol-brief-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;margin-top:16px}
            .seo-sol-brief-grid section{background:#fff;border:1px solid #dcdcde;border-radius:7px;padding:14px}
            .seo-sol-filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;align-items:end;background:#f6f7f7;border:1px solid #dcdcde;border-radius:7px;padding:14px;margin:14px 0}
            .seo-sol-filters label{font-weight:600}.seo-sol-filters select,.seo-sol-filters input{width:100%;margin-top:4px}
            .seo-sol-opportunity-sections{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:18px}
            .seo-sol-opportunity-sections>section{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;min-width:0}
            .seo-sol-opportunity-sections>section:nth-child(8),.seo-sol-opportunity-sections>section:nth-child(9){grid-column:1/-1}
            .seo-sol-requirements{display:grid;gap:8px;padding-left:18px}.seo-sol-workflow-form{display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin:14px 0}
            .seo-sol-knowledge,.seo-sol-market{border-left:3px solid #dcdcde;padding:8px 12px;margin:10px 0;background:#f6f7f7}
            @media(max-width:1100px){.seo-sol-brief-grid,.seo-sol-opportunity-sections{grid-template-columns:1fr}.seo-sol-opportunity-sections>section:nth-child(8),.seo-sol-opportunity-sections>section:nth-child(9){grid-column:auto}}
        </style>';
    }
}
