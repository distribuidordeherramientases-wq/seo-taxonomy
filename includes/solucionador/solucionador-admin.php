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
            'proposals' => 'Dossiers / propuestas',
            'coverage' => 'Cobertura editorial',
            'sources' => 'Academia / fuentes',
            'tests' => 'Pruebas arquitectura',
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

        $base = "intent='dependiente_qa_basic'";
        return array(
            'total'       => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$base} AND workflow_state<>'rejected'"),
            'candidate'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$base} AND workflow_state='candidate'"),
            'approved'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$base} AND workflow_state IN ('approved','brief_ready')"),
            'create_post' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$base} AND recommended_action='CREATE_POST' AND workflow_state<>'rejected'"),
            'improve'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$base} AND recommended_action='IMPROVE_POST' AND workflow_state<>'rejected'"),
            'merge'       => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$base} AND recommended_action='MERGE_CONTENT' AND workflow_state<>'rejected'"),
            'no_action'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$base} AND recommended_action='NO_ACTION' AND workflow_state<>'rejected'"),
            'deferred'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$base} AND (workflow_state='deferred' OR recommended_action='DEFER')"),
            'monitoring'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$base} AND workflow_state='monitoring'"),
        );
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        SEO_Solucionador_DB::maybe_install();
        $initialization = SEO_Solucionador_Engine::ensure_initialized(180);
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'summary';
        if ($tab === 'diagnostics') $tab = 'summary'; // compatibilidad con enlaces antiguos.
        if (!in_array($tab, array('summary','proposals','coverage','sources','tests','data'), true)) $tab = 'summary';

        echo '<div class="wrap seo-solucionador"><h1>Solucionador <small style="font-weight:400;color:#646970">v' . esc_html(SEO_SOLUCIONADOR_VERSION) . '</small></h1>';
        echo '<p><strong>Academia/Entrenador → contenido básico por categoría.</strong> Solucionador organiza preguntas que Dependiente ya ha aprendido, comprueba la cobertura existente y prepara un brief para Editora. <strong>No investiga, no consulta mercado, no depende de Ingeniero/Comparador y no publica automáticamente.</strong></p>';

        if (is_wp_error($initialization)) {
            echo '<div class="notice notice-warning inline"><p><strong>Inicialización pendiente:</strong> ' . esc_html($initialization->get_error_message()) . '</p></div>';
        }
        self::render_export_button();

        if (!empty($_GET['scan'])) {
            $last = get_option('seo_solucionador_last_scan',array());
            if (is_array($last) && array_key_exists('complete',$last) && empty($last['complete'])) {
                echo '<div class="notice notice-info is-dismissible"><p>Lote de Academia procesado. El escaneo sigue pendiente y puede reanudarse desde el cursor #' . esc_html(absint($last['cursor'] ?? 0)) . '.</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>Análisis de Academia completado.</p></div>';
            }
        }
        if (!empty($_GET['sol_msg']) && sanitize_key(wp_unslash($_GET['sol_msg'])) === 'draft_created') {
            $post_id = isset($_GET['post_id']) ? absint(wp_unslash($_GET['post_id'])) : 0;
            echo '<div class="notice notice-success is-dismissible"><p>Borrador creado y clasificado como <code>dependiente_qa_basic</code>.';
            if ($post_id) echo ' <a href="' . esc_url(SEO_Solucionador_Posts::edit_url($post_id)) . '"><strong>Abrir borrador #' . esc_html($post_id) . '</strong></a>';
            echo '</p></div>';
        } elseif (!empty($_GET['sol_msg'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Cambio guardado.</p></div>';
        }
        if (!empty($_GET['sol_error'])) {
            $msg = get_transient('seo_solucionador_notice_' . get_current_user_id());
            delete_transient('seo_solucionador_notice_' . get_current_user_id());
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg ?: 'No se pudo completar la acción.') . '</p></div>';
        }

        self::tabs($tab);
        if ($tab === 'summary') self::render_summary();
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
        $academy = SEO_Solucionador_Sources::dependiente_academia_snapshot();

        echo '<div class="seo-sol-grid">';
        self::card('Dossiers', $counts['total'] ?? 0, 'Un dossier por product_cat con conocimiento aprendido.');
        self::card('Crear post', $counts['create_post'] ?? 0, 'Sin cobertura equivalente y con masa crítica suficiente.');
        self::card('Mejorar post', $counts['improve'] ?? 0, 'Existe un post de la misma intención con cobertura parcial o débil.');
        self::card('Fusionar', $counts['merge'] ?? 0, 'Hay piezas solapadas que conviene consolidar.');
        self::card('Ya cubiertos', $counts['no_action'] ?? 0, 'La intención está suficientemente cubierta.');
        self::card('Revisión / aplazados', $counts['deferred'] ?? 0, 'Falta masa crítica, categoría o existe una condición de revisión.');
        self::card('Preguntas aprendidas', absint($academy['learned'] ?? 0), 'Último run answered con evaluation_status pass_*.');
        self::card('Con categoría', absint($academy['learned_with_category'] ?? 0), 'Preguntas aprendidas con product_cat demostrable.');
        self::card('Sin categoría', absint($academy['learned_without_category'] ?? 0), 'No crean dossier ni URL.');
        self::card('Categorías con conocimiento', absint($academy['categories_with_knowledge'] ?? 0), 'product_cat que ya disponen de dossier.');
        echo '</div>';

        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Flujo editorial</h2>';
        echo '<p><strong>Academia/Entrenador → dossier por categoría → cobertura → CREATE_POST / IMPROVE_POST / MERGE_CONTENT / NO_ACTION / DEFER → brief → Editora.</strong></p>';
        echo '<p>Una pregunta aprendida no genera una URL. Las preguntas de la misma categoría se agrupan y Editora redacta el contenido público; los resultados internos de Academia sólo sirven como evidencia.</p>';
        echo '<p><a class="button button-primary" href="' . esc_url(self::url('proposals')) . '">Abrir dossiers</a> <a class="button" href="' . esc_url(self::url('coverage')) . '">Revisar cobertura</a></p>';
        echo '</div>';

        $in_progress = is_array($last) && array_key_exists('complete',$last) && empty($last['complete']);
        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">' . ($in_progress ? 'Continuar análisis por lotes' : 'Reanalizar Academia') . '</h2>';
        echo '<p>Lee únicamente conocimiento ya aprendido por Dependiente. Procesa lotes pequeños con cursor persistente y puede reanudarse sin cargar todas las preguntas en memoria.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_solucionador_scan">';
        wp_nonce_field('seo_solucionador_scan');
        echo '<input type="hidden" name="days" value="180">';
        submit_button($in_progress ? 'Continuar siguiente lote' : 'Reanalizar Academia', 'primary', 'submit', false);
        echo '</form>';
        if ($last) {
            echo '<p class="description" style="margin-top:12px">Última ejecución: <strong>' . esc_html(wp_date('d/m/Y H:i', absint($last['at'] ?? 0))) . '</strong>';
            echo ' · cursor: ' . esc_html(number_format_i18n(absint($last['cursor'] ?? 0)));
            echo ' · preguntas vistas: ' . esc_html(number_format_i18n(absint($last['sources_seen'] ?? 0)));
            echo ' · evidencias aceptadas: ' . esc_html(number_format_i18n(absint($last['accepted'] ?? 0)));
            if (isset($last['academy_categories'])) echo ' · dossiers: ' . esc_html(number_format_i18n(absint($last['academy_categories'])));
            echo ' · estado: <strong>' . ($in_progress ? 'en curso' : 'completo') . '</strong></p>';
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
        global $wpdb;
        $items = array();
        $academy = SEO_Solucionador_Sources::dependiente_academia_snapshot();

        $items['Academia · conocimiento aprendido'] = array(
            'available'=>!empty($academy['available']),
            'metric'=>absint($academy['categories_with_knowledge'] ?? 0),
            'unit'=>'dossiers por categoría',
            'detail'=>number_format_i18n(absint($academy['learned'] ?? 0)) . ' preguntas pass_* · '
                . number_format_i18n(absint($academy['learned_without_category'] ?? 0)) . ' sin categoría'
        );

        $search_table = $wpdb->prefix . 'seo_dependiente_search_log';
        $searches = SEO_Solucionador_DB::table_exists($search_table)
            ? absint($wpdb->get_var("SELECT COUNT(*) FROM {$search_table} WHERE request_kind='search'"))
            : 0;
        $items['Dependiente · demanda real'] = array(
            'available'=>$searches > 0,
            'metric'=>$searches,
            'unit'=>'consultas registradas',
            'detail'=>'Sólo refuerza prioridad de dossiers de Academia existentes; no origina temas.'
        );

        $items['Analista · medición'] = array(
            'available'=>function_exists('seo_analista_get_data') || function_exists('seo_post_reports_get_summary'),
            'metric'=>0,
            'unit'=>'fuente externa de KPIs',
            'detail'=>'Los resultados globales se consultan en Analista; Solucionador no abre conexiones GSC/GA4/Bing.'
        );
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
        echo '<div class="postbox" style="padding:18px;margin-top:18px">';
        echo '<h2 style="margin-top:0">Diagnóstico editorial migrado</h2>';
        echo '<p>Desde Solucionador 0.5.0 el servicio ya no centraliza Auditor, Analista, Ingeniero, Ojeador o Comparador. Esta ruta se conserva sólo para enlaces antiguos.</p>';
        echo '<p><a class="button button-primary" href="' . esc_url(self::url('proposals')) . '">Abrir dossiers</a> <a class="button" href="' . esc_url(self::url('coverage')) . '">Cobertura editorial</a> <a class="button" href="' . esc_url(self::url('sources')) . '">Academia / fuentes</a></p>';
        echo '</div>';
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
        $categories = SEO_Solucionador_DB::proposed_categories($topic);
        $vocabulary = SEO_Solucionador_DB::proposed_vocabulary($topic);
        $hierarchy = SEO_Solucionador_DB::hierarchy($topic);
        $requirements = SEO_Solucionador_DB::decision_requirements($topic);
        $priority_components = SEO_Solucionador_DB::priority_components($topic);
        $primary_category_id = absint($topic['primary_category_id'] ?? 0);

        $question_ids = SEO_Solucionador_DB::decode_json($topic['dossier_question_ids'] ?? '',array());
        $question_ids = array_values(array_unique(array_filter(array_map('absint',(array)$question_ids))));
        if (!$question_ids) {
            foreach (SEO_Solucionador_DB::get_evidence_rows($topic_id) as $row) {
                $meta = (array) ($row['source_meta_decoded'] ?? array());
                if (sanitize_key((string)($meta['dependiente_channel'] ?? '')) !== 'academy_learned') continue;
                $qid = absint($meta['question_id'] ?? 0);
                if ($qid) $question_ids[] = $qid;
            }
            $question_ids = array_values(array_unique($question_ids));
        }
        $question_details = SEO_Solucionador_Sources::academia_question_details($question_ids);
        $questions = array_values(array_filter(array_map(static function($row){
            return trim((string)($row['question'] ?? ''));
        },$question_details)));

        $profile = array(
            'intent'=>'dependiente_qa_basic',
            'action'=>'resolver',
            'object'=>($primary_category_id && ($term=get_term($primary_category_id,'product_cat')) && !is_wp_error($term)) ? (string)$term->name : (string)($topic['object_term'] ?? ''),
            'condition'=>'',
            'context'=>'',
            'canonical_key'=>(string)($topic['canonical_key'] ?? ''),
            'category_id'=>$primary_category_id,
        );
        $coverage = class_exists('SEO_Editorial_Coverage')
            ? SEO_Editorial_Coverage::find($profile)
            : SEO_Solucionador_Coverage::find($profile);

        $products = array();
        if ($primary_category_id) {
            foreach ((array)get_posts(array(
                'post_type'=>'product','post_status'=>'publish','posts_per_page'=>12,'fields'=>'ids',
                'tax_query'=>array(array('taxonomy'=>'product_cat','field'=>'term_id','terms'=>array($primary_category_id)))
            )) as $product_id) {
                $products[] = array('id'=>absint($product_id),'title'=>get_the_title($product_id),'url'=>get_permalink($product_id));
            }
        }

        $preserve = array();
        $links = array();
        foreach ((array)($coverage['matches'] ?? array()) as $match) {
            $title = trim((string)($match['title'] ?? ''));
            $text = trim((string)($match['source_text'] ?? ''));
            if ($title !== '') $preserve[] = $title . ($text !== '' && $text !== $title ? ' — ' . wp_trim_words($text,22,'…') : '');
            $url = trim((string)($match['url'] ?? ''));
            if ($url !== '') $links[$url] = array('label'=>$title ?: $url,'url'=>$url);
        }
        if ($primary_category_id) {
            $term = get_term($primary_category_id,'product_cat');
            if ($term && !is_wp_error($term)) {
                $url = get_term_link($term);
                if (!is_wp_error($url)) $links[(string)$url] = array('label'=>(string)$term->name,'url'=>(string)$url);
            }
        }

        return array(
            'topic_id'=>$topic_id,
            'topic'=>(string)(($topic['suggested_title'] ?? '') ?: ($topic['canonical_question'] ?? '')),
            'question'=>(string)($topic['canonical_question'] ?? ''),
            'intent'=>'dependiente_qa_basic',
            'output'=>self::action_label((string)($topic['recommended_action'] ?? 'DEFER')),
            'action'=>(string)($topic['recommended_action'] ?? 'DEFER'),
            'decision_reason'=>(string)($topic['decision_reason'] ?? ''),
            'priority'=>(float)($topic['priority_score'] ?? 0),
            'priority_components'=>$priority_components,
            'requirements'=>$requirements,
            'categories'=>$categories,
            'primary_category_id'=>$primary_category_id,
            'hierarchy'=>$hierarchy,
            'vocabulary'=>$vocabulary,
            'products'=>$products,
            'questions'=>$questions,
            'question_details'=>$question_details,
            'question_count'=>count($question_ids),
            'dossier_score'=>(float)($topic['dossier_score'] ?? 0),
            'dossier_hash'=>(string)($topic['dossier_hash'] ?? ''),
            'dossier_last_validated_at'=>(string)($topic['dossier_last_validated_at'] ?? ''),
            'preserve'=>array_slice(array_values(array_unique($preserve)),0,12),
            'links'=>array_values($links),
            'coverage'=>$coverage,
            'duplication_risk'=>(float)($topic['duplication_risk'] ?? 0),
            'cannibalization_risk'=>(float)($topic['cannibalization_risk'] ?? 0),
            'workflow_state'=>(string)($topic['workflow_state'] ?? 'detected'),
            'workflow'=>SEO_Solucionador_DB::workflow_history($topic_id,100),
            'existing_entity_type'=>(string)($topic['existing_entity_type'] ?? ''),
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
        echo '<div><h2 style="margin:0 0 6px">Dossier #' . esc_html($brief['topic_id']) . '</h2><strong style="font-size:18px">' . esc_html($brief['topic']) . '</strong><br><code>' . esc_html((string)($topic['canonical_key'] ?? '')) . '</code></div>';
        echo '<a class="button" href="' . esc_url(self::url('proposals')) . '">Cerrar dossier</a></div>';

        echo '<div class="seo-sol-grid" style="margin-top:14px">';
        self::card('Preguntas', $brief['question_count'], 'Referencias aprendidas pass_* asociadas al dossier.');
        self::card('Score Academia', number_format_i18n($brief['dossier_score']*100,0) . '%', 'Media del último resultado validado.');
        self::card('Cobertura', self::coverage_label((string)($brief['coverage']['status'] ?? 'uncovered')), 'Cobertura editorial existente.');
        self::card('Acción', $brief['output'], $brief['decision_reason']);
        self::card('Workflow', self::workflow_label($brief['workflow_state']), 'Estado de revisión humana.');
        echo '</div>';

        echo '<div class="seo-sol-opportunity-sections">';
        echo '<section><h3>1. Dossier de Academia</h3>';
        echo '<p><strong>Categoría principal:</strong> #' . esc_html($brief['primary_category_id'] ?: '—') . '</p>';
        echo '<p><strong>Última validación:</strong> ' . esc_html($brief['dossier_last_validated_at'] ?: '—') . '</p>';
        echo '<p><strong>Hash:</strong> <code>' . esc_html($brief['dossier_hash'] ?: '—') . '</code></p>';
        echo '<h4>Prioridad</h4>'; self::render_priority_components($brief);
        echo '</section>';

        echo '<section><h3>2. Preguntas aprendidas</h3>';
        echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>Pregunta</th><th>Lección / tipo</th><th>Validación</th><th>Resultado interno</th><th>Fecha</th></tr></thead><tbody>';
        if (!$brief['question_details']) echo '<tr><td colspan="5">No se han podido cargar detalles del dossier.</td></tr>';
        foreach ($brief['question_details'] as $item) {
            $top = (array)($item['top_results'] ?? array());
            $labels = array();
            foreach (array_slice($top,0,3) as $result) {
                if (!is_array($result)) continue;
                $label = trim((string)($result['title'] ?? $result['name'] ?? $result['label'] ?? ''));
                if ($label !== '') $labels[] = $label;
            }
            $result_text = $labels ? implode(' · ',$labels) : 'Evidencia interna disponible';
            echo '<tr><td><strong>' . esc_html((string)($item['question'] ?? '')) . '</strong></td>';
            echo '<td><code>' . esc_html((string)($item['lesson_key'] ?? '')) . '</code><br>' . esc_html((string)($item['question_type'] ?? '')) . '</td>';
            echo '<td><strong>' . esc_html((string)($item['evaluation_status'] ?? '')) . '</strong><br>' . esc_html(number_format_i18n((float)($item['evaluation_score'] ?? 0)*100,0)) . '%</td>';
            echo '<td>' . esc_html(wp_trim_words($result_text,30,'…')) . '</td>';
            echo '<td>' . esc_html((string)($item['observed_at'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="description"><strong>No publicar literalmente:</strong> estos resultados son evidencia interna. Editora redacta la respuesta pública.</p>';
        echo '</section>';

        echo '<section><h3>3. Cobertura existente</h3>';
        echo '<p><strong>Estado:</strong> ' . esc_html(self::coverage_label((string)($brief['coverage']['status'] ?? 'uncovered'))) . ' · <strong>score:</strong> ' . esc_html(number_format_i18n((float)($brief['coverage']['score'] ?? 0)*100,0)) . '%</p>';
        echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>Destino</th><th>Tipo</th><th>Qué cubre</th><th>Similitud</th></tr></thead><tbody>';
        if (empty($brief['coverage']['matches'])) echo '<tr><td colspan="4">No existe una URL relacionada con cobertura suficiente.</td></tr>';
        foreach ((array)($brief['coverage']['matches'] ?? array()) as $match) {
            $url=(string)($match['url'] ?? '');
            echo '<tr><td>' . ($url!==''?'<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html((string)($match['title'] ?? $url)) . '</a>':esc_html((string)($match['title'] ?? ''))) . '</td>';
            echo '<td>' . esc_html((string)($match['entity_type'] ?? '')) . '</td>';
            echo '<td>' . esc_html(wp_trim_words((string)($match['source_text'] ?? ''),30,'…')) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float)($match['score'] ?? 0)*100,0)) . '%</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '</section>';

        echo '<section><h3>4. Decisión editorial</h3>';
        echo '<p><strong>' . esc_html($brief['output']) . '</strong> — ' . esc_html($brief['decision_reason']) . '</p>';
        echo '<h4>Condiciones</h4>'; self::render_requirements($brief);
        self::render_workflow_form($topic);
        if ($entity_edit) echo '<p><a class="button" href="' . esc_url($entity_edit) . '">Abrir post existente</a></p>';
        if ($brief['action']==='CREATE_POST' && in_array($brief['workflow_state'],array('approved','brief_ready'),true) && !$brief['draft_post_id']) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_solucionador_topic"><input type="hidden" name="topic_id" value="' . esc_attr($brief['topic_id']) . '"><input type="hidden" name="topic_action" value="create_draft">';
            wp_nonce_field('seo_solucionador_topic_' . $brief['topic_id']);
            submit_button('Preparar borrador de post','primary','submit',false);
            echo '</form>';
        }
        echo '</section>';

        echo '<section><h3>5. Brief para Editora</h3>';
        echo '<p><strong>Título propuesto:</strong> ' . esc_html($brief['topic']) . '</p>';
        echo '<p><strong>Product_cat:</strong> #' . esc_html($brief['primary_category_id'] ?: '—') . '</p>';
        if ($brief['questions']) { echo '<h4>Preguntas que debe resolver el post</h4><ul>'; foreach ($brief['questions'] as $q) echo '<li>' . esc_html($q) . '</li>'; echo '</ul>'; }
        if ($brief['preserve']) { echo '<h4>Contenido existente que no debe duplicarse</h4><ul>'; foreach ($brief['preserve'] as $item) echo '<li>' . esc_html($item) . '</li>'; echo '</ul>'; }
        if ($brief['links']) { echo '<h4>Enlaces internos recomendados</h4><ul>'; foreach ($brief['links'] as $link) echo '<li><a href="' . esc_url($link['url']) . '" target="_blank" rel="noopener">' . esc_html($link['label']) . '</a></li>'; echo '</ul>'; }
        echo '<p><strong>Vocabulary:</strong></p>' . self::vocab_chips($topic);
        echo '<p class="description"><strong>Editora redacta.</strong> Solucionador no publica literalmente respuestas internas de Academia ni fija el texto final.</p>';
        echo '</section>';

        echo '<section><h3>6. Workflow</h3><ul>';
        if (!$brief['workflow']) echo '<li>Sin cambios humanos registrados todavía.</li>';
        foreach ($brief['workflow'] as $row) echo '<li><strong>' . esc_html(self::workflow_label((string)($row['to_state'] ?? ''))) . '</strong> · ' . esc_html((string)($row['created_at'] ?? '')) . ' · ' . esc_html((string)($row['reason'] ?? '')) . '</li>';
        echo '</ul><p class="description">Los KPIs globales posteriores a publicación pertenecen a Analista.</p></section>';
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
        $rows = (array) $wpdb->get_results(
            "SELECT * FROM {$topics_table}
             WHERE intent='dependiente_qa_basic'
             ORDER BY priority_score DESC,dossier_question_count DESC,id DESC
             LIMIT 1500",
            ARRAY_A
        );

        $category_filter = isset($_GET['sol_category']) ? absint(wp_unslash($_GET['sol_category'])) : 0;
        $action_filter = isset($_GET['sol_action']) ? strtoupper(sanitize_text_field(wp_unslash($_GET['sol_action']))) : '';
        $coverage_filter = isset($_GET['sol_coverage']) ? sanitize_key(wp_unslash($_GET['sol_coverage'])) : '';
        $state_filter = isset($_GET['sol_state']) ? sanitize_key(wp_unslash($_GET['sol_state'])) : '';

        $categories = array();
        foreach ($rows as $row) {
            $term_id=absint($row['primary_category_id'] ?? 0);
            if ($term_id && !isset($categories[$term_id])) {
                $term=get_term($term_id,'product_cat');
                $categories[$term_id]=($term&&!is_wp_error($term))?(string)$term->name:('#'.$term_id);
            }
        }
        asort($categories,SORT_NATURAL|SORT_FLAG_CASE);

        $rows=array_values(array_filter($rows,static function($row) use($category_filter,$action_filter,$coverage_filter,$state_filter){
            if($category_filter && absint($row['primary_category_id'] ?? 0)!==$category_filter)return false;
            if($action_filter!=='' && strtoupper((string)($row['recommended_action'] ?? ''))!==$action_filter)return false;
            if($coverage_filter!=='' && sanitize_key((string)($row['coverage_status'] ?? ''))!==$coverage_filter)return false;
            if($state_filter!=='' && sanitize_key((string)($row['workflow_state'] ?? ''))!==$state_filter)return false;
            return true;
        }));

        $detail_id=isset($_GET['topic_id'])?absint(wp_unslash($_GET['topic_id'])):(isset($_GET['brief_topic_id'])?absint(wp_unslash($_GET['brief_topic_id'])):0);
        if($detail_id){$detail=SEO_Solucionador_DB::get_topic($detail_id);if($detail)self::render_opportunity_detail($detail);}

        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Dossiers y propuestas editoriales</h2>';
        echo '<p class="description">Una fila representa un dossier de <strong>Academia por product_cat</strong>, no una pregunta aislada. Las únicas salidas son crear post, mejorar post, fusionar, no actuar o aplazar/revisar.</p>';
        echo '<form method="get" class="seo-sol-filters"><input type="hidden" name="page" value="seo-solucionador"><input type="hidden" name="tab" value="proposals">';
        echo '<label>Categoría<select name="sol_category"><option value="0">Todas</option>';foreach($categories as $id=>$name)echo '<option value="' . esc_attr($id) . '" ' . selected($category_filter,$id,false) . '>' . esc_html($name) . ' (#' . esc_html($id) . ')</option>';echo '</select></label>';
        echo '<label>Acción<select name="sol_action"><option value="">Todas</option>';foreach(array('CREATE_POST','IMPROVE_POST','MERGE_CONTENT','NO_ACTION','DEFER') as $action)echo '<option value="' . esc_attr($action) . '" ' . selected($action_filter,$action,false) . '>' . esc_html(self::action_label($action)) . '</option>';echo '</select></label>';
        echo '<label>Cobertura<select name="sol_coverage"><option value="">Todas</option>';foreach(array('uncovered','weak_coverage','partial_coverage','covered','duplicate','conflict') as $status)echo '<option value="' . esc_attr($status) . '" ' . selected($coverage_filter,$status,false) . '>' . esc_html(self::coverage_label($status)) . '</option>';echo '</select></label>';
        echo '<label>Estado<select name="sol_state"><option value="">Todos</option>';foreach(SEO_Solucionador_DB::workflow_states() as $state)echo '<option value="' . esc_attr($state) . '" ' . selected($state_filter,$state,false) . '>' . esc_html(self::workflow_label($state)) . '</option>';echo '</select></label>';
        echo '<div><button class="button button-primary" type="submit">Filtrar</button> <a class="button" href="' . esc_url(self::url('proposals')) . '">Limpiar</a></div></form>';

        echo '<p><strong>' . esc_html(number_format_i18n(count($rows))) . '</strong> dossiers con los filtros actuales.</p>';
        echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>Prioridad</th><th>Categoría / dossier</th><th>Preguntas</th><th>Cobertura</th><th>Decisión</th><th>Workflow</th><th></th></tr></thead><tbody>';
        if(!$rows)echo '<tr><td colspan="7">No hay dossiers con estos filtros.</td></tr>';
        foreach(array_slice($rows,0,700) as $row){
            $topic_id=absint($row['id'] ?? 0);$term_id=absint($row['primary_category_id'] ?? 0);$category_name=$term_id?($categories[$term_id] ?? ('#'.$term_id)):'Sin categoría';
            echo '<tr><td><strong class="seo-sol-score">' . esc_html(number_format_i18n((float)($row['priority_score'] ?? 0),0)) . '</strong>/100</td>';
            echo '<td><strong>' . esc_html($category_name) . '</strong><br><span class="description">' . esc_html((string)($row['suggested_title'] ?? '')) . '</span><br><code>' . esc_html((string)($row['canonical_key'] ?? '')) . '</code></td>';
            echo '<td><strong>' . esc_html(number_format_i18n(absint($row['dossier_question_count'] ?? 0))) . '</strong><br><small>score ' . esc_html(number_format_i18n((float)($row['dossier_score'] ?? 0)*100,0)) . '%</small></td>';
            echo '<td><strong>' . esc_html(self::coverage_label((string)($row['coverage_status'] ?? 'uncovered'))) . '</strong><br><small>dup. ' . esc_html(number_format_i18n((float)($row['duplication_risk'] ?? 0),0)) . '/100</small></td>';
            echo '<td><strong>' . esc_html(self::action_label((string)($row['recommended_action'] ?? 'DEFER'))) . '</strong><br><small>' . esc_html(wp_trim_words((string)($row['decision_reason'] ?? ''),22,'…')) . '</small></td>';
            echo '<td>' . esc_html(self::workflow_label((string)($row['workflow_state'] ?? 'detected'))) . '</td>';
            echo '<td><a class="button button-primary button-small" href="' . esc_url(self::url('proposals',array('topic_id'=>$topic_id))) . '#opportunity">Abrir dossier</a></td></tr>';
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
        $academy = SEO_Solucionador_Sources::dependiente_academia_snapshot();
        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Academia / Entrenador</h2>';
        echo '<p><strong>Único origen editorial:</strong> preguntas activas cuyo último run está <code>answered</code> y <code>evaluation_status = pass_*</code>. Solucionador no modifica Dependiente ni vuelve a entrenarlo.</p>';
        echo '<p>La categoría se acepta sólo cuando puede demostrarse mediante <code>expected_json</code>, producto o propietario de FAQ. Nunca se infiere por parecido textual.</p>';
        echo '<div class="seo-sol-grid">';
        self::card('Preguntas activas',absint($academy['questions_total'] ?? 0),'Currículo de Academia.');
        self::card('Aprendidas',absint($academy['learned'] ?? 0),'Último run validado pass_*.');
        self::card('No aprendidas',absint($academy['not_learned'] ?? 0),'Todavía sin pass_*.');
        self::card('Con categoría',absint($academy['learned_with_category'] ?? 0),'Pueden alimentar un dossier.');
        self::card('Sin categoría',absint($academy['learned_without_category'] ?? 0),'No generan dossier ni URL.');
        self::card('Categorías con conocimiento',absint($academy['categories_with_knowledge'] ?? 0),'Dossiers detectados.');
        self::card('Categorías sin conocimiento',absint($academy['categories_without_knowledge'] ?? 0),'product_cat sin dossier aprendido.');
        self::card('Media preguntas / categoría',(float)($academy['avg_questions_per_category'] ?? 0),'Asignaciones por dossier.');
        echo '</div>';
        echo '<p class="description">Último run de Academia: <strong>' . esc_html((string)(($academy['last_run_at'] ?? '') ?: '—')) . '</strong>.</p>';

        echo '<h3>Demanda real de Dependiente</h3>';
        echo '<p><code>seo_dependiente_search_log</code> se conserva únicamente como refuerzo ligero de prioridad para categorías que ya tienen dossier. No origina temas independientes y no carga <code>top_results</code> completos.</p>';
        self::render_service_snapshot();

        echo '<h3>Servicios fuera de Solucionador</h3>';
        echo '<p>Ingeniero, Ojeador/Comparador, Clasificador, Marketing, Comentarista, Auditor y Analista mantienen procesos independientes. <strong>Analista</strong> es la fuente de KPIs globales posteriores a publicación.</p>';
        echo '</div>';
    }

    private static function render_tests() {
        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Tests · Arquitectura separada</h2>';
        echo '<p class="description">Regresiones sin publicación automática para comprobar dossiers de Academia, cobertura, decisiones básicas, lotes y rol <code>dependiente_qa_basic</code>.</p>';
        $report = SEO_Solucionador_Tests::run();
        echo '<p><strong>' . esc_html(absint($report['passed'] ?? 0)) . '/' . esc_html(absint($report['total'] ?? 0)) . '</strong> pruebas superadas.</p>';
        echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>Test</th><th>Resultado</th><th>Esperado</th><th>Actual</th><th>Regla</th></tr></thead><tbody>';
        foreach((array)($report['tests'] ?? array()) as $row){
            echo '<tr><td><strong>' . esc_html((string)($row['name'] ?? '')) . '</strong></td><td>' . (!empty($row['pass'])?'<strong style="color:#008a20">OK</strong>':'<strong style="color:#b32d2e">FALLO</strong>') . '</td><td>' . esc_html((string)($row['expected'] ?? '')) . '</td><td>' . esc_html((string)($row['actual'] ?? '')) . '</td><td>' . esc_html((string)($row['detail'] ?? '')) . '</td></tr>';
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
