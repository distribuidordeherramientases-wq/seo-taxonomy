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
        if (!$id) self::redirect(array('sol_error'=>'missing_topic'));

        if ($action === 'create_draft') {
            $result = SEO_Solucionador_Posts::create_draft($id);
            if (is_wp_error($result)) {
                set_transient('seo_solucionador_notice_' . get_current_user_id(), $result->get_error_message(), 90);
                self::redirect(array('sol_error'=>'create_draft'));
            }
            self::redirect(array('sol_msg'=>'draft_created','post_id'=>absint($result)));
        }

        if (in_array($action, array('dismissed','observe','candidate','approved'), true)) {
            SEO_Solucionador_DB::update_topic($id, array('status'=>$action));
            self::redirect(array('sol_msg'=>'saved'));
        }

        self::redirect(array('sol_error'=>'invalid_action'));
    }

    private static function tabs($current) {
        $tabs = array(
            'summary' => 'Resumen',
            'diagnostics' => 'Diagnóstico editorial',
            'proposals' => 'Propuestas editoriales',
            'coverage' => 'Cobertura editorial',
            'sources' => 'Fuentes y servicios',
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
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'candidate' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status IN ('candidate','approved') AND recommended_action IN ('create_post','create_landing')"),
            'drafts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='draft_created'"),
            'covered' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE coverage_status LIKE 'covered_%'"),
            'uncovered' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE coverage_status='uncovered'"),
            'expand' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE recommended_action IN ('expand_existing_post','create_section')"),
            'observe' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE recommended_action IN ('observe','no_action')"),
        );
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        SEO_Solucionador_DB::maybe_install();
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'summary';
        if (!in_array($tab, array('summary','diagnostics','proposals','coverage','sources','data'), true)) $tab = 'summary';

        echo '<div class="wrap seo-solucionador"><h1>Solucionador <small style="font-weight:400;color:#646970">v' . esc_html(SEO_SOLUCIONADOR_VERSION) . '</small></h1>';
        echo '<p><strong>Capa de decision editorial.</strong> Solucionador unifica conclusiones de Auditor, Analista, Clasificador, Dependiente/Interprete, Ojeador e Ingeniero; detecta carencias, oportunidades, duplicidades y canibalizacion; prioriza la actuacion y prepara el brief. <strong>No investiga, interpreta ni mide por su cuenta y no publica contenido.</strong></p>';
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
        else self::render_data();
        self::styles();
        echo '</div>';
    }

    private static function render_summary() {
        $counts = self::counts();
        $last = get_option('seo_solucionador_last_scan', array());
        echo '<div class="seo-sol-grid">';
        self::card('Temas detectados', $counts['total'] ?? 0, 'Problemas y procedimientos canonicos observados.');
        self::card('Sin cobertura', $counts['uncovered'] ?? 0, 'No se ha encontrado un post equivalente.');
        self::card('Crear contenido', $counts['candidate'] ?? 0, 'Propuestas que requieren una nueva pieza editorial.');
        self::card('Borradores creados', $counts['drafts'] ?? 0, 'Propuestas aprobadas pendientes de contenido/publicacion.');
        self::card('Ampliar existentes', $counts['expand'] ?? 0, 'Conviene ampliar un post o crear una seccion.');
        self::card('Cubiertos', $counts['covered'] ?? 0, 'Existe cobertura editorial identificada.');
        self::card('Observar / no actuar', $counts['observe'] ?? 0, 'Decisiones conservadas sin ejecucion editorial inmediata.');
        echo '</div>';

        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Analizar ahora</h2>';
        echo '<p>Reconstruye el mapa de decision usando las conclusiones ya disponibles de los servicios. Dependiente/Interprete y gaps editoriales explicitos pueden originar temas; Analista, Auditor, Ojeador, Ingeniero y Clasificador aportan contexto, prioridad y evidencia sin sustituir a sus servicios de origen.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_solucionador_scan">';
        wp_nonce_field('seo_solucionador_scan');
        echo '<label><strong>Ventana:</strong> <select name="days"><option value="90">90 dias</option><option value="180" selected>180 dias</option><option value="365">365 dias</option></select></label> ';
        submit_button('Reanalizar fuentes', 'primary', 'submit', false);
        echo '</form>';
        if ($last) {
            echo '<p class="description" style="margin-top:12px">Ultimo analisis: <strong>' . esc_html(wp_date('d/m/Y H:i', absint($last['at'] ?? 0))) . '</strong> · senales: ' . esc_html(number_format_i18n(absint($last['sources_seen'] ?? 0))) . ' · aceptadas: ' . esc_html(number_format_i18n(absint($last['accepted'] ?? 0))) . ' · descartadas/refuerzo sin origen: ' . esc_html(number_format_i18n(absint($last['discarded'] ?? 0))) . ' · temas obsoletos limpiados: ' . esc_html(number_format_i18n(absint($last['pruned_topics'] ?? 0))) . ' · posts indexados: ' . esc_html(number_format_i18n(absint($last['posts_indexed'] ?? 0))) . ' · temas de post: ' . esc_html(number_format_i18n(absint($last['post_topics_indexed'] ?? 0))) . '</p>';
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
        $items['Dependiente / Intérprete'] = array('available'=>$searches > 0,'metric'=>$searches,'unit'=>'consultas registradas','detail'=>'Demanda e interpretación ya registrada; Solucionador no reinterpreta las consultas.');

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
        $labels = array(
            'create_post'=>'Crear post',
            'create_landing'=>'Crear landing',
            'expand_existing_post'=>'Mejorar contenido existente',
            'create_section'=>'Añadir sección a contenido existente',
            'observe'=>'Observar / esperar evidencia',
            'no_action'=>'No hacer nada',
        );
        return $labels[$action] ?? str_replace('_',' ',(string)$action);
    }

    private static function editorial_brief(array $topic) {
        $topic_id = absint($topic['id'] ?? 0);
        $evidence = SEO_Solucionador_DB::get_evidence_rows($topic_id);
        $categories = SEO_Solucionador_DB::proposed_categories($topic);
        $vocabulary = SEO_Solucionador_DB::proposed_vocabulary($topic);
        $existing_post_id = absint($topic['existing_post_id'] ?? 0);
        $draft_post_id = absint($topic['draft_post_id'] ?? 0);
        $target_post_id = $existing_post_id ?: $draft_post_id;

        $sources = array();
        foreach ($evidence as $row) {
            $type = sanitize_key((string) ($row['source_type'] ?? ''));
            if ($type !== '') $sources[$type] = ($sources[$type] ?? 0) + max(1, absint($row['occurrences'] ?? 1));
        }

        $concepts = array();
        foreach ($categories as $category) {
            $term_id = absint($category['term_id'] ?? $category['id'] ?? 0);
            if (!$term_id || !function_exists('seo_classifier_engineer_vocab_report')) continue;
            $report = seo_classifier_engineer_vocab_report($term_id);
            if (is_wp_error($report)) continue;
            foreach (array_merge((array) ($report['attributes'] ?? array()), (array) ($report['labels'] ?? array())) as $item) {
                if (!in_array((string) ($item['status'] ?? ''), array('new','possible'), true)) continue;
                $label = trim((string) (($item['name'] ?? '') ?: ($item['label'] ?? '')));
                if ($label !== '') $concepts[$label] = true;
            }
        }

        $knowledge = array();
        foreach ($categories as $category) {
            $term_id = absint($category['term_id'] ?? $category['id'] ?? 0);
            if (!$term_id || !class_exists('SEO_Ingeniero')) continue;
            foreach (array_slice((array) SEO_Ingeniero::active_knowledge($term_id), 0, 4) as $row) {
                $text = trim((string) (($row['summary'] ?? '') ?: ($row['concept'] ?? '')));
                if ($text !== '') $knowledge[$text] = true;
            }
        }

        $products = array();
        $cat_ids = array_values(array_filter(array_map(static function($row){
            return absint($row['term_id'] ?? $row['id'] ?? 0);
        }, (array) $categories)));
        if ($cat_ids) {
            $ids = get_posts(array(
                'post_type'=>'product',
                'post_status'=>'publish',
                'posts_per_page'=>8,
                'fields'=>'ids',
                'tax_query'=>array(array(
                    'taxonomy'=>'product_cat',
                    'field'=>'term_id',
                    'terms'=>$cat_ids,
                    'operator'=>'IN',
                )),
            ));
            foreach ((array) $ids as $product_id) {
                $products[] = array('id'=>absint($product_id),'title'=>get_the_title($product_id),'url'=>get_permalink($product_id));
            }
        }

        $preserve = array();
        if ($target_post_id && get_post_type($target_post_id) === 'post') {
            $post = get_post($target_post_id);
            if ($post) {
                $preserve[] = 'Título actual: ' . (string) $post->post_title;
                if (preg_match_all('/<h[23][^>]*>(.*?)<\/h[23]>/isu', (string) $post->post_content, $matches)) {
                    foreach (array_slice((array) ($matches[1] ?? array()), 0, 8) as $heading) {
                        $heading = trim(wp_strip_all_tags((string) $heading));
                        if ($heading !== '') $preserve[] = 'Sección existente: ' . $heading;
                    }
                }
            }
        }

        $links = array();
        foreach ($categories as $category) {
            $term_id = absint($category['term_id'] ?? $category['id'] ?? 0);
            if (!$term_id) continue;
            $term = get_term($term_id, 'product_cat');
            if (!$term || is_wp_error($term)) continue;
            $url = get_term_link($term);
            if (!is_wp_error($url)) $links[] = array('label'=>(string)$term->name,'url'=>(string)$url);
        }
        if ($target_post_id && get_permalink($target_post_id)) {
            $links[] = array('label'=>get_the_title($target_post_id),'url'=>get_permalink($target_post_id));
        }

        $tracking = array();
        if ($target_post_id && get_post_status($target_post_id) === 'publish' && function_exists('seo_post_reports_get_summary')) {
            $tracking = seo_post_reports_get_summary($target_post_id, 28);
        }

        return array(
            'topic'=>(string) (($topic['suggested_title'] ?? '') ?: ($topic['canonical_question'] ?? '')),
            'intent'=>(string) ($topic['intent'] ?? ''),
            'output'=>self::action_label((string) ($topic['recommended_action'] ?? 'observe')),
            'reason'=>'Prioridad ' . number_format_i18n((float) ($topic['priority_score'] ?? 0), 0) . '/100 · cobertura ' . str_replace('_',' ',(string)($topic['coverage_status'] ?? '')),
            'sources'=>$sources,
            'categories'=>$categories,
            'vocabulary'=>$vocabulary,
            'concepts'=>array_slice(array_keys($concepts),0,20),
            'knowledge'=>array_slice(array_keys($knowledge),0,8),
            'products'=>$products,
            'preserve'=>$preserve,
            'links'=>$links,
            'tracking'=>$tracking,
            'target_post_id'=>$target_post_id,
        );
    }

    private static function render_brief(array $topic) {
        $brief = self::editorial_brief($topic);
        echo '<div id="brief" class="postbox" style="padding:18px;margin-top:18px;border-left:4px solid #2271b1">';
        echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap"><div><h2 style="margin:0 0 6px">Brief editorial</h2><strong>' . esc_html($brief['topic']) . '</strong></div><a class="button" href="' . esc_url(self::url('proposals')) . '">Cerrar brief</a></div>';
        echo '<div class="seo-sol-grid" style="margin-top:14px">';
        self::card('Salida recomendada', $brief['output'], $brief['reason']);
        self::card('Intención', $brief['intent'] !== '' ? $brief['intent'] : '—', 'Intención canónica del tema.');
        self::card('Fuentes', count($brief['sources']), 'Servicios/evidencias que justifican la decisión.');
        self::card('Conceptos faltantes', count($brief['concepts']), 'Huecos de Clasificador/Ingeniero relacionados.');
        echo '</div>';

        echo '<div class="seo-sol-brief-grid">';
        echo '<section><h3>Fuentes y evidencia</h3>';
        if (!$brief['sources']) echo '<p>Sin evidencias registradas.</p>'; else {
            echo '<ul>'; foreach ($brief['sources'] as $source=>$count) echo '<li><strong>' . esc_html($source) . ':</strong> ' . esc_html(number_format_i18n($count)) . '</li>'; echo '</ul>';
        }
        echo '<h3>Categorías y productos relacionados</h3>';
        echo '<p>' . self::category_chips($topic) . '</p>';
        if ($brief['products']) { echo '<ul>'; foreach ($brief['products'] as $product) echo '<li><a href="' . esc_url(get_edit_post_link($product['id'],'raw')) . '">#' . esc_html($product['id']) . ' ' . esc_html($product['title']) . '</a></li>'; echo '</ul>'; }
        echo '</section>';

        echo '<section><h3>Conceptos que conviene cubrir</h3>';
        if ($brief['concepts']) echo '<p>' . esc_html(implode(' · ', $brief['concepts'])) . '</p>'; else echo '<p class="description">No hay huecos nuevos/posibles de Clasificador asociados a estas categorías.</p>';
        if ($brief['knowledge']) { echo '<h4>Conocimiento técnico disponible</h4><ul>'; foreach ($brief['knowledge'] as $item) echo '<li>' . esc_html($item) . '</li>'; echo '</ul>'; }
        echo '<h3>Contenido previo que debe conservarse</h3>';
        if ($brief['preserve']) { echo '<ul>'; foreach ($brief['preserve'] as $item) echo '<li>' . esc_html($item) . '</li>'; echo '</ul>'; } else echo '<p class="description">No se ha identificado contenido existente que condicione la redacción.</p>';
        echo '</section>';

        echo '<section><h3>Enlaces internos sugeridos</h3>';
        if ($brief['links']) { echo '<ul>'; foreach ($brief['links'] as $link) echo '<li><a href="' . esc_url($link['url']) . '" target="_blank" rel="noopener">' . esc_html($link['label']) . '</a></li>'; echo '</ul>'; } else echo '<p class="description">Sin destinos internos identificados.</p>';
        echo '<h3>Seguimiento</h3>';
        if (!empty($brief['tracking']['has_snapshot'])) {
            echo '<p>Últimos 28 días: <strong>' . esc_html(number_format_i18n(absint($brief['tracking']['impressions'] ?? 0))) . '</strong> impresiones · <strong>' . esc_html(number_format_i18n(absint($brief['tracking']['clicks'] ?? 0))) . '</strong> clics · <strong>' . esc_html(number_format_i18n(absint($brief['tracking']['pageviews'] ?? 0))) . '</strong> vistas.</p>';
        } else {
            echo '<p class="description">El seguimiento aparecerá cuando exista una pieza publicada con snapshot de rendimiento.</p>';
        }
        echo '</section></div>';
        echo '<p class="description" style="margin-bottom:0"><strong>Entrega a Editora:</strong> este brief justifica qué hacer y con qué información. La redacción y edición final se realiza en el editor correspondiente.</p>';
        echo '</div>';
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

    private static function render_proposals() {
        global $wpdb;
        $table = SEO_Solucionador_DB::topics_table();
        $rows = (array) $wpdb->get_results(
            "SELECT * FROM {$table}
             WHERE status<>'dismissed'
             ORDER BY CASE WHEN status='draft_created' THEN 1 ELSE 0 END ASC,priority_score DESC,evidence_total DESC,id DESC
             LIMIT 250",
            ARRAY_A
        );

        $evidence_map = array();
        $topic_ids = array_values(array_filter(array_map(static function($row){ return absint($row['id'] ?? 0); }, $rows)));
        if ($topic_ids) {
            $evidence_table = SEO_Solucionador_DB::evidence_table();
            $placeholders = implode(',', array_fill(0, count($topic_ids), '%d'));
            $sql = "SELECT topic_id,source_type,SUM(occurrences) total
                    FROM {$evidence_table}
                    WHERE topic_id IN ({$placeholders})
                    GROUP BY topic_id,source_type";
            foreach ((array) $wpdb->get_results($wpdb->prepare($sql, $topic_ids), ARRAY_A) as $evidence_row) {
                $tid = absint($evidence_row['topic_id'] ?? 0);
                $type = sanitize_key((string) ($evidence_row['source_type'] ?? ''));
                if ($tid && $type !== '') $evidence_map[$tid][$type] = absint($evidence_row['total'] ?? 0);
            }
        }

        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Propuestas editoriales</h2>';
        $brief_topic_id = absint($_GET['brief_topic_id'] ?? 0);
        if ($brief_topic_id) {
            $brief_topic = SEO_Solucionador_DB::get_topic($brief_topic_id);
            if ($brief_topic) self::render_brief($brief_topic);
        }
        echo '<p class="description">Cada fila conserva una decisión editorial y su estado. Solucionador decide y prepara el brief; Entradas/Páginas/Categorías son el lugar de ejecución. Crear un post sigue requiriendo aprobación explícita y genera únicamente un borrador.</p>';
        echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>Prioridad</th><th>Pregunta / titulo</th><th>Clasificacion propuesta</th><th>Evidencias</th><th>Cobertura</th><th>Accion</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="6">Todavia no hay propuestas. Ejecuta el analisis desde Resumen.</td></tr>';

        foreach ($rows as $row) {
            $existing_post_id = absint($row['existing_post_id'] ?? 0);
            $draft_post_id = absint($row['draft_post_id'] ?? 0);
            echo '<tr>';
            echo '<td><strong class="seo-sol-score">' . esc_html(number_format_i18n((float) ($row['priority_score'] ?? 0), 0)) . '</strong><br><span class="description">' . esc_html((string) ($row['status'] ?? 'candidate')) . '</span></td>';
            echo '<td><strong>' . esc_html((string) ($row['suggested_title'] ?? '')) . '</strong>';
            echo '<div class="description" style="margin-top:5px">Pregunta observada: ' . esc_html((string) ($row['canonical_question'] ?? '')) . '</div>';
            echo '<code>' . esc_html((string) ($row['canonical_key'] ?? '')) . '</code></td>';

            echo '<td><div class="seo-sol-meta"><strong>Categorias:</strong> ' . self::category_chips($row) . '</div>' . self::vocab_chips($row) . '</td>';
            $topic_evidence = (array) ($evidence_map[absint($row['id'] ?? 0)] ?? array());
            $source_labels = array(
                'dependiente'=>'Dependiente/Intérprete',
                'analista'=>'Analista',
                'auditor'=>'Auditor',
                'comentarista'=>'Comentarista',
                'ojeador'=>'Ojeador',
                'ingeniero'=>'Ingeniero',
                'clasificador'=>'Clasificador',
            );
            echo '<td>';
            foreach ($source_labels as $source_key=>$source_label) {
                $count = absint($topic_evidence[$source_key] ?? 0);
                if ($count > 0) echo '<div><strong>' . esc_html($source_label) . ':</strong> ' . esc_html(number_format_i18n($count)) . '</div>';
            }
            if (!$topic_evidence) echo '—';
            echo '</td>';

            echo '<td><strong>' . esc_html(str_replace('_', ' ', (string) ($row['coverage_status'] ?? ''))) . '</strong>';
            if ($existing_post_id) echo '<br><a href="' . esc_url(SEO_Solucionador_Posts::edit_url($existing_post_id)) . '">Abrir post #' . esc_html($existing_post_id) . '</a>';
            if ($draft_post_id) echo '<br><a href="' . esc_url(SEO_Solucionador_Posts::edit_url($draft_post_id)) . '"><strong>Abrir borrador #' . esc_html($draft_post_id) . '</strong></a>';
            echo '</td>';

            echo '<td><strong>' . esc_html(self::action_label((string) ($row['recommended_action'] ?? ''))) . '</strong>';
            echo '<p><a class="button button-small" href="' . esc_url(self::url('proposals', array('brief_topic_id'=>absint($row['id'])))) . '#brief">Ver brief</a></p>';
            if ($draft_post_id && get_post_type($draft_post_id) === 'post' && get_post_status($draft_post_id) !== 'trash') {
                echo '<p><a class="button button-primary" href="' . esc_url(SEO_Solucionador_Posts::edit_url($draft_post_id)) . '">Rellenar contenido</a></p>';
            } elseif ((string) ($row['recommended_action'] ?? '') === 'create_post') {
                $proposal = array(
                    'categories' => SEO_Solucionador_DB::proposed_categories($row),
                    'vocabulary' => SEO_Solucionador_DB::proposed_vocabulary($row),
                );
                if (SEO_Solucionador_Catalog::proposal_ready($proposal)) {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px">';
                    echo '<input type="hidden" name="action" value="seo_solucionador_topic"><input type="hidden" name="topic_id" value="' . esc_attr(absint($row['id'])) . '"><input type="hidden" name="topic_action" value="create_draft">';
                    wp_nonce_field('seo_solucionador_topic_' . absint($row['id']));
                    submit_button('Aprobar y crear borrador', 'primary small', 'submit', false);
                    echo '</form>';
                } else {
                    echo '<p class="seo-sol-warning">Reanalizar: falta clasificacion suficiente para crear un borrador homogeneo.</p>';
                }
            } elseif ($existing_post_id) {
                echo '<p><a class="button" href="' . esc_url(SEO_Solucionador_Posts::edit_url($existing_post_id)) . '">Revisar post existente</a></p>';
            }

            if (!$draft_post_id) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px">';
                echo '<input type="hidden" name="action" value="seo_solucionador_topic"><input type="hidden" name="topic_id" value="' . esc_attr(absint($row['id'])) . '">';
                wp_nonce_field('seo_solucionador_topic_' . absint($row['id']));
                echo '<select name="topic_action"><option value="observe">Observar</option><option value="candidate">Mantener candidato</option><option value="dismissed">Descartar</option></select> ';
                submit_button('Guardar', 'secondary small', 'submit', false);
                echo '</form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function render_coverage() {
        global $wpdb;
        $table = SEO_Solucionador_DB::post_topics_table();
        $rows = (array) $wpdb->get_results(
            "SELECT pt.*,p.post_title,p.post_status FROM {$table} pt
             INNER JOIN {$wpdb->posts} p ON p.ID=pt.post_id
             ORDER BY pt.post_id DESC,pt.scope ASC LIMIT 600",
            ARRAY_A
        );
        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Cobertura editorial existente</h2>';
        echo '<p class="description">Titulos y H2/H3 publicados/programados se traducen a una huella canonica. Vocabulary y categorias relacionadas se usan como contexto para evitar proponer dos veces la misma solucion.</p>';
        echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>Post</th><th>Ambito</th><th>Texto detectado</th><th>Huella canonica</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="4">No hay indice editorial. Ejecuta el analisis.</td></tr>';
        foreach ($rows as $row) {
            echo '<tr><td><a href="' . esc_url(SEO_Solucionador_Posts::edit_url(absint($row['post_id']))) . '"><strong>' . esc_html((string) ($row['post_title'] ?? '')) . '</strong></a><br><code>#' . esc_html(absint($row['post_id'])) . '</code></td>';
            echo '<td>' . esc_html((string) ($row['scope'] ?? '')) . '</td><td>' . esc_html((string) ($row['source_text'] ?? '')) . '</td><td><code>' . esc_html((string) ($row['canonical_key'] ?? '')) . '</code></td></tr>';
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
        echo '<p><strong>Dependiente / Interprete:</strong> fuente principal. Preguntas reales, intent, objeto, contexto, estado, resultados y feedback.</p>';
        echo '<p><strong>Analista:</strong> las busquedas internas pueden originar propuestas. El plan de decision normalmente solo refuerza; MEJORAR_PRODUCTO/IMPULSAR_CATEGORIA no se convierten en preguntas de cliente.</p>';
        echo '<p><strong>Auditor:</strong> aporta diagnóstico, carencias y cobertura; Solucionador decide si esos hallazgos requieren actuación editorial.</p>';
        echo '<p><strong>Comentarista:</strong> aporta problemas o preguntas observadas en experiencias externas almacenadas.</p>';
        echo '<p><strong>Ojeador:</strong> aporta conclusiones de mercado y oportunidad ya calculadas; Solucionador no consulta Google Shopping por su cuenta.</p>';
        echo '<p><strong>Ingeniero:</strong> aporta conocimiento técnico por categoría ya investigado y aprobado.</p>';
        echo '<p><strong>Clasificador:</strong> aporta huecos y estructura semántica detectados a partir del catálogo y de Ingeniero.</p>';
        echo '<p><strong>Entradas/Páginas/Categorías:</strong> aportan inventario, rendimiento y cobertura; sus editores quedan como lugares de ejecución.</p>';
        self::render_service_snapshot();
        if ($counts) {
            echo '<h3>Evidencias acumuladas en propuestas</h3><ul>';
            foreach ($counts as $row) echo '<li><strong>' . esc_html((string) $row['source_type']) . ':</strong> ' . esc_html(number_format_i18n(absint($row['evidence'] ?? 0))) . '</li>';
            echo '</ul>';
        }
        echo '</div>';
    }

    private static function render_data() {
        global $wpdb;

        $topics = SEO_Solucionador_DB::topics_table();
        $evidence = SEO_Solucionador_DB::evidence_table();
        $post_topics = SEO_Solucionador_DB::post_topics_table();

        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Datos internos de Solucionador</h2>';
        echo '<p class="description">Vista de solo lectura de las tablas que sustentan las propuestas. Sirve para comprobar que el analisis tiene datos, que posts propone crear o ampliar y que evidencias y cobertura utiliza.</p>';

        echo '<h3>' . esc_html($topics) . ' · temas y propuestas</h3>';
        if (!SEO_Solucionador_DB::table_exists($topics)) {
            echo '<p class="seo-sol-warning">La tabla no existe.</p>';
        } else {
            $rows = (array) $wpdb->get_results(
                "SELECT id,suggested_title,canonical_question,canonical_key,status,coverage_status,recommended_action,evidence_total,existing_post_id,draft_post_id,priority_score,last_analyzed_at
                 FROM {$topics}
                 ORDER BY CASE WHEN recommended_action='create_post' THEN 0 WHEN recommended_action IN ('expand_existing_post','create_section') THEN 1 ELSE 2 END,
                          priority_score DESC,id DESC
                 LIMIT 500",
                ARRAY_A
            );
            echo '<p><strong>Filas mostradas:</strong> ' . esc_html(number_format_i18n(count($rows))) . '</p>';
            echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>ID</th><th>Post / pregunta</th><th>Huella</th><th>Estado</th><th>Cobertura</th><th>Accion</th><th>Evidencias</th><th>Post existente</th><th>Borrador</th><th>Prioridad</th><th>Analizado</th></tr></thead><tbody>';
            if (!$rows) echo '<tr><td colspan="11">Tabla vacia. Ejecuta Reanalizar fuentes desde Resumen.</td></tr>';
            foreach ($rows as $row) {
                echo '<tr><td>' . esc_html(absint($row['id'] ?? 0)) . '</td>';
                echo '<td><strong>' . esc_html((string) ($row['suggested_title'] ?? '')) . '</strong><br><span class="description">' . esc_html((string) ($row['canonical_question'] ?? '')) . '</span></td>';
                echo '<td><code>' . esc_html((string) ($row['canonical_key'] ?? '')) . '</code></td>';
                echo '<td>' . esc_html((string) ($row['status'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['coverage_status'] ?? '')) . '</td>';
                echo '<td><strong>' . esc_html((string) ($row['recommended_action'] ?? '')) . '</strong></td>';
                echo '<td>' . esc_html(number_format_i18n(absint($row['evidence_total'] ?? 0))) . '</td>';
                echo '<td>' . esc_html(absint($row['existing_post_id'] ?? 0) ?: '-') . '</td>';
                echo '<td>' . esc_html(absint($row['draft_post_id'] ?? 0) ?: '-') . '</td>';
                echo '<td>' . esc_html(number_format_i18n((float) ($row['priority_score'] ?? 0), 0)) . '</td>';
                echo '<td>' . esc_html((string) ($row['last_analyzed_at'] ?? '')) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        echo '<h3 style="margin-top:24px">' . esc_html($evidence) . ' · evidencias</h3>';
        if (!SEO_Solucionador_DB::table_exists($evidence)) {
            echo '<p class="seo-sol-warning">La tabla no existe.</p>';
        } else {
            $rows = (array) $wpdb->get_results(
                "SELECT id,topic_id,source_type,source_id,source_text,occurrences,evidence_score,observed_at
                 FROM {$evidence}
                 ORDER BY topic_id DESC,evidence_score DESC,occurrences DESC,id DESC
                 LIMIT 500",
                ARRAY_A
            );
            echo '<p><strong>Filas mostradas:</strong> ' . esc_html(number_format_i18n(count($rows))) . '</p>';
            echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>ID</th><th>Tema</th><th>Fuente</th><th>ID fuente</th><th>Texto</th><th>Ocurrencias</th><th>Peso</th><th>Observado</th></tr></thead><tbody>';
            if (!$rows) echo '<tr><td colspan="8">Tabla vacia. Las evidencias se reconstruyen al reanalizar.</td></tr>';
            foreach ($rows as $row) {
                echo '<tr><td>' . esc_html(absint($row['id'] ?? 0)) . '</td>';
                echo '<td>' . esc_html(absint($row['topic_id'] ?? 0)) . '</td>';
                echo '<td>' . esc_html((string) ($row['source_type'] ?? '')) . '</td>';
                echo '<td><code>' . esc_html((string) ($row['source_id'] ?? '')) . '</code></td>';
                echo '<td>' . esc_html((string) ($row['source_text'] ?? '')) . '</td>';
                echo '<td>' . esc_html(number_format_i18n(absint($row['occurrences'] ?? 0))) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((float) ($row['evidence_score'] ?? 0), 2)) . '</td>';
                echo '<td>' . esc_html((string) ($row['observed_at'] ?? '')) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        echo '<h3 style="margin-top:24px">' . esc_html($post_topics) . ' · cobertura de posts</h3>';
        if (!SEO_Solucionador_DB::table_exists($post_topics)) {
            echo '<p class="seo-sol-warning">La tabla no existe.</p>';
        } else {
            $rows = (array) $wpdb->get_results(
                "SELECT pt.id,pt.post_id,p.post_title,p.post_status,pt.scope,pt.source_text,pt.canonical_key,pt.confidence,pt.updated_at
                 FROM {$post_topics} pt
                 LEFT JOIN {$wpdb->posts} p ON p.ID=pt.post_id
                 ORDER BY pt.post_id DESC,pt.scope ASC,pt.id DESC
                 LIMIT 500",
                ARRAY_A
            );
            echo '<p><strong>Filas mostradas:</strong> ' . esc_html(number_format_i18n(count($rows))) . '</p>';
            echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>ID</th><th>Post</th><th>Estado</th><th>Ambito</th><th>Texto</th><th>Huella</th><th>Confianza</th><th>Actualizado</th></tr></thead><tbody>';
            if (!$rows) echo '<tr><td colspan="8">Tabla vacia. Ejecuta el analisis para reconstruir el indice de cobertura.</td></tr>';
            foreach ($rows as $row) {
                echo '<tr><td>' . esc_html(absint($row['id'] ?? 0)) . '</td>';
                echo '<td>#' . esc_html(absint($row['post_id'] ?? 0)) . ' · ' . esc_html((string) ($row['post_title'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['post_status'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['scope'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['source_text'] ?? '')) . '</td>';
                echo '<td><code>' . esc_html((string) ($row['canonical_key'] ?? '')) . '</code></td>';
                echo '<td>' . esc_html(number_format_i18n((float) ($row['confidence'] ?? 0), 2)) . '</td>';
                echo '<td>' . esc_html((string) ($row['updated_at'] ?? '')) . '</td></tr>';
            }
            echo '</tbody></table></div>';
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
        echo '<span class="description" style="margin-left:10px">Incluye propuestas, clasificacion, evidencias, cobertura editorial y ultimo analisis.</span>';
        echo '</div>';
    }

    private static function card($label, $value, $desc) {
        echo '<div class="seo-sol-card"><span>' . esc_html($label) . '</span><strong>' . esc_html(number_format_i18n((int) $value)) . '</strong><small>' . esc_html($desc) . '</small></div>';
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
        </style>';
    }
}
