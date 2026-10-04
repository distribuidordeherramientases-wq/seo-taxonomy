<?php
defined('ABSPATH') || exit;

final class SEO_Ingeniero_Admin {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_page'), 32);
        add_filter('seo_content_items', array(__CLASS__, 'content_card'), 32, 1);
        add_filter('parent_file', array(__CLASS__, 'parent_file'), 32, 1);
        add_filter('submenu_file', array(__CLASS__, 'submenu_file'), 32, 1);
        add_action('admin_init', array(__CLASS__, 'redirect_legacy_dependiente_tab'));
        add_action('admin_post_seo_ingeniero_prepare', array(__CLASS__, 'handle_prepare'));
        add_action('admin_post_seo_ingeniero_control', array(__CLASS__, 'handle_control'));
        add_action('admin_post_seo_ingeniero_research_category', array(__CLASS__, 'handle_research_category'));
        add_action('admin_post_seo_ingeniero_review', array(__CLASS__, 'handle_review'));
        add_action('admin_post_seo_ingeniero_approve_category', array(__CLASS__, 'handle_approve_category'));
        add_action('admin_post_seo_ingeniero_approve_all', array(__CLASS__, 'handle_approve_all'));
        add_action('admin_post_seo_ingeniero_export', array(__CLASS__, 'handle_export'));
        add_action('admin_post_seo_ingeniero_settings', array(__CLASS__, 'handle_settings'));
        add_action('admin_post_seo_ingeniero_editorial_action', array(__CLASS__, 'handle_editorial_action'));
        add_action('admin_post_seo_ingeniero_editorial_accept_all', array(__CLASS__, 'handle_editorial_accept_all'));
    }

    public static function register_page() {
        add_submenu_page(
            null,
            'Ingeniero',
            'Ingeniero',
            'manage_options',
            'seo-ingeniero',
            array(__CLASS__, 'render')
        );
    }

    public static function content_card($items) {
        $items = is_array($items) ? $items : array();
        $items[] = array(
            'title' => 'Ingeniero',
            'icon'  => 'dashicons-hammer',
            'page'  => 'seo-ingeniero',
            'desc'  => 'Investiga conocimiento técnico trazable por categoría y lo convierte en propuestas editoriales propias para la Editora.',
        );
        return $items;
    }

    public static function parent_file($parent_file) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return 'seo-ingeniero' === $page ? 'seo-system' : $parent_file;
    }

    public static function submenu_file($submenu_file) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return 'seo-ingeniero' === $page ? 'seo-content' : $submenu_file;
    }

    public static function page_url($tab = 'research', $extra = array()) {
        return add_query_arg(array_merge(array(
            'page' => 'seo-ingeniero',
            'tab'  => sanitize_key((string) $tab),
        ), (array) $extra), admin_url('admin.php'));
    }

    public static function redirect_legacy_dependiente_tab() {
        if (!is_admin() || !current_user_can('manage_options')) return;
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        if ('seo-dependiente' !== $page || 'engineer' !== $tab) return;

        $extra = array();
        if (!empty($_GET['term_id'])) $extra['term_id'] = absint($_GET['term_id']);
        wp_safe_redirect(self::page_url('research', $extra));
        exit;
    }

    private static function guard($action) {
        if (!current_user_can('manage_options')) wp_die(esc_html__('No tienes permisos para usar Ingeniero.', 'seo-taxonomy'));
        check_admin_referer($action);
    }

    private static function redirect($args = array(), $tab = 'research') {
        wp_safe_redirect(self::page_url($tab, (array) $args));
        exit;
    }

    private static function refresh_category_state($term_id) {
        $term_id = absint($term_id);
        if (!$term_id) return array('active'=>0,'review'=>0);

        $rows = SEO_Ingeniero_DB::knowledge_for_category($term_id, false);
        $review_count = 0;
        $active_count = 0;
        foreach ($rows as $row) {
            if ('review' === ($row['status'] ?? '')) $review_count++;
            if ('active' === ($row['status'] ?? '')) $active_count++;
        }

        SEO_Ingeniero::set_category_state(
            $term_id,
            $review_count > 0 ? 'revisar' : ($active_count > 0 ? 'aprendido' : 'pendiente'),
            array('last_error'=>'')
        );

        return array('active'=>$active_count,'review'=>$review_count);
    }

    public static function handle_prepare() {
        self::guard('seo_ingeniero_prepare');
        $limit = max(1, min(100, absint($_POST['limit'] ?? 20)));
        $state = SEO_Ingeniero::prepare_lesson($limit, true);
        self::redirect(array('ingeniero_notice'=>'prepared','prepared'=>count((array) ($state['queue'] ?? array()))));
    }

    public static function handle_control() {
        self::guard('seo_ingeniero_control');
        $command = sanitize_key((string) ($_POST['command'] ?? ''));
        if ('start' === $command) {
            $result = SEO_Ingeniero::start();
            if (is_wp_error($result)) self::redirect(array('ingeniero_error'=>rawurlencode($result->get_error_message())));
            self::redirect(array('ingeniero_notice'=>'started'));
        }
        if ('stop' === $command) {
            SEO_Ingeniero::stop();
            self::redirect(array('ingeniero_notice'=>'stopped'));
        }
        self::redirect();
    }

    public static function handle_research_category() {
        self::guard('seo_ingeniero_research_category');
        $term_id = absint($_POST['term_id'] ?? 0);
        $result = SEO_Ingeniero::reinvestigate_category($term_id);
        if (is_wp_error($result)) self::redirect(array('term_id'=>$term_id,'ingeniero_error'=>rawurlencode($result->get_error_message())));
        self::redirect(array('term_id'=>$term_id,'ingeniero_notice'=>'researched'));
    }

    public static function handle_review() {
        self::guard('seo_ingeniero_review');
        $id = absint($_POST['knowledge_id'] ?? 0);
        $term_id = absint($_POST['term_id'] ?? 0);
        $decision = sanitize_key((string) ($_POST['decision'] ?? 'review'));
        $status = 'approve' === $decision ? 'active' : ('reject' === $decision ? 'rejected' : 'review');
        $result = SEO_Ingeniero_DB::review_knowledge($id, $status);
        if (is_wp_error($result)) self::redirect(array('term_id'=>$term_id,'ingeniero_error'=>rawurlencode($result->get_error_message())));

        self::refresh_category_state($term_id);
        SEO_Ingeniero::refresh_editorial_category($term_id, true);
        self::redirect(array('term_id'=>$term_id,'ingeniero_notice'=>'reviewed'));
    }

    public static function handle_approve_category() {
        self::guard('seo_ingeniero_approve_category');
        $term_id = absint($_POST['term_id'] ?? 0);
        if (!$term_id) {
            self::redirect(array('ingeniero_error'=>rawurlencode('La categoría no es válida.')));
        }

        $updated = SEO_Ingeniero_DB::approve_review_for_category($term_id, SEO_Ingeniero::LESSON_TECHNICAL);
        if (is_wp_error($updated)) {
            self::redirect(array('term_id'=>$term_id,'ingeniero_error'=>rawurlencode($updated->get_error_message())));
        }

        self::refresh_category_state($term_id);
        SEO_Ingeniero::refresh_editorial_category($term_id, true);
        self::redirect(array(
            'term_id'=>$term_id,
            'ingeniero_notice'=>'category_approved',
            'ingeniero_approved_knowledge'=>absint($updated),
        ));
    }

    public static function handle_approve_all() {
        self::guard('seo_ingeniero_approve_all');
        $result = SEO_Ingeniero_DB::approve_all_review(SEO_Ingeniero::LESSON_TECHNICAL);
        if (is_wp_error($result)) {
            self::redirect(array('ingeniero_error'=>rawurlencode($result->get_error_message())));
        }

        foreach ((array) ($result['term_ids'] ?? array()) as $term_id) {
            $term_id = absint($term_id);
            self::refresh_category_state($term_id);
            SEO_Ingeniero::refresh_editorial_category($term_id, true);
        }

        self::redirect(array(
            'ingeniero_notice'=>'all_approved',
            'ingeniero_approved_categories'=>absint($result['categories'] ?? 0),
            'ingeniero_approved_knowledge'=>absint($result['knowledge'] ?? 0),
        ));
    }

    public static function handle_export() {
        self::guard('seo_ingeniero_export');
        $term_id = absint($_POST['term_id'] ?? 0);
        $payload = SEO_Ingeniero_DB::export_payload($term_id);
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name('seo-ingeniero-' . ($term_id ? 'categoria-'.$term_id.'-' : '') . gmdate('Ymd-His') . '.json') . '"');
        echo wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    public static function handle_settings() {
        self::guard('seo_ingeniero_settings');
        SEO_Ingeniero_SerpApi_Provider::save_settings(wp_unslash($_POST['ingeniero'] ?? array()));
        self::redirect(array('ingeniero_notice'=>'settings'));
    }

    public static function handle_editorial_accept_all() {
        self::guard('seo_ingeniero_editorial_accept_all');

        global $wpdb;
        SEO_Ingeniero_DB::maybe_install();
        $table = esc_sql(SEO_Ingeniero_DB::table('editorial'));

        $after_id = absint($_REQUEST['after_id'] ?? 0);
        $created = absint($_REQUEST['created'] ?? 0);
        $skipped = absint($_REQUEST['skipped'] ?? 0);
        $errors = absint($_REQUEST['errors'] ?? 0);
        $batch_size = 50;

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id,status,post_id
                 FROM `{$table}`
                 WHERE id>%d
                   AND recommended_action='CREATE_POST'
                   AND status IN ('candidate','review','approved','needs_update')
                 ORDER BY id ASC
                 LIMIT %d",
                $after_id,
                $batch_size
            ),
            ARRAY_A
        );

        $last_id = $after_id;
        foreach ($rows as $row) {
            $editorial_id = absint($row['id'] ?? 0);
            $last_id = max($last_id, $editorial_id);
            if (!$editorial_id) {
                $skipped++;
                continue;
            }

            $post_id = absint($row['post_id'] ?? 0);
            if ($post_id && 'trash' !== get_post_status($post_id)) {
                $skipped++;
                continue;
            }

            if ('approved' !== sanitize_key((string) ($row['status'] ?? ''))) {
                SEO_Ingeniero_DB::update_editorial($editorial_id, array('status'=>'approved'));
            }

            $result = SEO_Ingeniero_Posts::create_draft($editorial_id);
            if (is_wp_error($result)) {
                $errors++;
                continue;
            }
            $created++;
        }

        if (count($rows) === $batch_size && $last_id > $after_id) {
            $next = wp_nonce_url(
                add_query_arg(array(
                    'action'=>'seo_ingeniero_editorial_accept_all',
                    'after_id'=>$last_id,
                    'created'=>$created,
                    'skipped'=>$skipped,
                    'errors'=>$errors,
                ), admin_url('admin-post.php')),
                'seo_ingeniero_editorial_accept_all'
            );
            wp_safe_redirect($next);
            exit;
        }

        self::redirect(array(
            'ingeniero_notice'=>'editorial_all_accepted',
            'editorial_created'=>$created,
            'editorial_skipped'=>$skipped,
            'editorial_errors'=>$errors,
        ), 'editorial');
    }

    public static function handle_editorial_action() {
        self::guard('seo_ingeniero_editorial_action');
        $editorial_id = absint($_POST['editorial_id'] ?? 0);
        $command = sanitize_key((string) ($_POST['editorial_command'] ?? ''));
        $dossier = SEO_Ingeniero_DB::editorial_get($editorial_id);
        if (!$dossier) {
            self::redirect(array('ingeniero_error'=>rawurlencode('No existe la propuesta editorial.')), 'editorial');
        }

        if ('reanalyze' === $command) {
            $result = SEO_Ingeniero::refresh_editorial_category(absint($dossier['term_id'] ?? 0), true);
            if (is_wp_error($result)) {
                self::redirect(array('ingeniero_error'=>rawurlencode($result->get_error_message()),'editorial_id'=>$editorial_id), 'editorial');
            }
            self::redirect(array('ingeniero_notice'=>'editorial_reanalyzed','editorial_id'=>$editorial_id), 'editorial');
        }

        if ('approve' === $command) {
            SEO_Ingeniero_DB::update_editorial($editorial_id, array('status'=>'approved'));
            self::redirect(array('ingeniero_notice'=>'editorial_approved','editorial_id'=>$editorial_id), 'editorial');
        }

        if ('review' === $command) {
            SEO_Ingeniero_DB::update_editorial($editorial_id, array('status'=>'review'));
            self::redirect(array('ingeniero_notice'=>'editorial_review','editorial_id'=>$editorial_id), 'editorial');
        }

        if ('close' === $command) {
            SEO_Ingeniero_DB::update_editorial($editorial_id, array('status'=>'closed'));
            self::redirect(array('ingeniero_notice'=>'editorial_closed'), 'editorial');
        }

        if ('create_draft' === $command) {
            $post_id = SEO_Ingeniero_Posts::create_draft($editorial_id);
            if (is_wp_error($post_id)) {
                self::redirect(array('ingeniero_error'=>rawurlencode($post_id->get_error_message()),'editorial_id'=>$editorial_id), 'editorial');
            }
            self::redirect(array('ingeniero_notice'=>'editorial_draft','editorial_id'=>$editorial_id,'post_id'=>absint($post_id)), 'editorial');
        }

        self::redirect(array('ingeniero_error'=>rawurlencode('Acción editorial no válida.'),'editorial_id'=>$editorial_id), 'editorial');
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para usar Ingeniero.', 'seo-taxonomy'));
        }

        SEO_Ingeniero_DB::maybe_install();
        if (class_exists('SEO_Ingeniero_Process')) {
            SEO_Ingeniero_Process::kick_editorial_refresh();
        }
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'research';
        if (!in_array($tab, array('research', 'editorial', 'data', 'tests'), true)) {
            $tab = 'research';
        }

        echo '<div class="wrap seo-ingeniero-admin">';
        echo '<h1>Ingeniero <small style="font-weight:400;color:#646970">v' . esc_html(SEO_INGENIERO_VERSION) . '</small></h1>';
        echo '<p><strong>Servicio técnico y editorial independiente.</strong> Investiga por categoría, conserva trazabilidad de fuentes y prepara dossiers técnicos para la Editora. No depende de Solucionador para decidir sus posts.</p>';
        echo '<nav class="nav-tab-wrapper" aria-label="Secciones de Ingeniero">';
        foreach (array('research'=>'Investigación', 'editorial'=>'Editorial', 'data'=>'Datos y fuentes', 'tests'=>'Pruebas') as $key=>$label) {
            echo '<a class="nav-tab ' . ($tab === $key ? 'nav-tab-active' : '') . '" href="' . esc_url(self::page_url($key)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';

        if ('editorial' === $tab) {
            self::render_editorial_tab();
        } elseif ('data' === $tab) {
            self::render_data_tab();
        } elseif ('tests' === $tab) {
            self::render_tests_tab();
        } else {
            self::render_tab();
        }

        echo '</div>';
    }

    public static function render_tab() {
        if (!current_user_can('manage_options')) return;
        SEO_Ingeniero_DB::maybe_install();

        $state = SEO_Ingeniero::state();
        $progress = SEO_Ingeniero::progress();
        $totals = SEO_Ingeniero_DB::totals();
        $stats_map = SEO_Ingeniero_DB::category_stats_map();
        $category_states = SEO_Ingeniero::category_states();
        $all_candidates = SEO_Ingeniero::category_candidates(0);
        $table_candidates = array_slice($all_candidates, 0, 100);
        $usage = SEO_Ingeniero_SerpApi_Provider::usage_month();
        $settings = SEO_Ingeniero_SerpApi_Provider::settings();
        $detail_term_id = absint($_GET['term_id'] ?? 0);

        self::render_notices();

        echo '<section class="seo-ingeniero">';
        echo '<div class="postbox seo-dependiente-admin__box" style="padding:18px">';
        echo '<h2 style="margin-top:0">Ingeniero</h2>';
        echo '<p>Investigación técnica por categoría. Ingeniero consulta fuentes externas, crea una síntesis propia y conserva siempre la referencia al origen. La salida editorial se gestiona aparte en la pestaña <strong>Editorial</strong> y nunca autopublica.</p>';
        echo '<p class="description">Separación deliberada: <code>seo_dependiente_index</code> sigue representando nuestro catálogo; <code>seo_ingeniero_knowledge</code> conserva teoría externa trazable por fuentes.</p>';
        echo '</div>';

        self::render_kpis($all_candidates, $stats_map, $category_states, $totals, $state);

        echo '<div class="postbox seo-dependiente-admin__box" style="padding:18px">';
        echo '<h3 style="margin-top:0">Lección 1 · Documentación técnica</h3>';
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="seo_ingeniero_prepare">';
        wp_nonce_field('seo_ingeniero_prepare');
        echo '<label><strong>Categorías por lote</strong><br><input type="number" name="limit" min="1" max="100" value="20" class="small-text"><br><small class="description">Se seleccionan entre las pendientes.</small></label> ';
        submit_button('Preparar lección', 'secondary', 'submit', false);
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="seo_ingeniero_control"><input type="hidden" name="command" value="start">';
        wp_nonce_field('seo_ingeniero_control');
        submit_button(SEO_Ingeniero::is_pending() ? 'Continuar investigación' : 'Iniciar / continuar investigación', 'primary', 'submit', false);
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="seo_ingeniero_control"><input type="hidden" name="command" value="stop">';
        wp_nonce_field('seo_ingeniero_control');
        submit_button('Detener', 'secondary', 'submit', false);
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="seo_ingeniero_export">';
        wp_nonce_field('seo_ingeniero_export');
        submit_button('Exportar JSON', 'secondary', 'submit', false);
        echo '</form>';
        echo '</div>';

        echo '<p style="margin:15px 0 4px"><strong>Estado:</strong> ' . esc_html((string) ($state['status'] ?? 'stopped')) . ' · ';
        echo '<strong>Progreso:</strong> ' . esc_html(number_format_i18n($progress['processed'])) . '/' . esc_html(number_format_i18n($progress['total'])) . ' categorías · <strong>1 categoría por ciclo</strong>.</p>';
        if (!empty($state['last_message'])) echo '<p class="description">' . esc_html((string) $state['last_message']) . '</p>';
        if (!empty($state['last_error'])) echo '<p style="color:#b32d2e"><strong>Último error:</strong> ' . esc_html((string) $state['last_error']) . '</p>';
        echo '</div>';

        echo '<div class="postbox seo-dependiente-admin__box" style="padding:18px">';
        echo '<h3 style="margin-top:0">Fuente de búsqueda y presupuesto</h3>';
        echo '<p>Provider: <strong>SerpApi · Google web</strong>. Reutiliza la API key de Ojeador, pero Ingeniero mantiene un <strong>presupuesto local independiente</strong>. Las consultas web usan <code>engine=google</code> y resultados orgánicos estructurados.</p>';
        echo '<p><strong>Uso Ingeniero:</strong> ' . esc_html(number_format_i18n($usage['used'])) . ' / ' . esc_html(number_format_i18n($usage['limit'])) . ' consultas este mes.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="seo_ingeniero_settings">';
        wp_nonce_field('seo_ingeniero_settings');
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Límite mensual local</th><td><input type="number" name="ingeniero[monthly_query_limit]" min="1" max="100000" value="' . esc_attr(absint($settings['monthly_query_limit'])) . '"></td></tr>';
        echo '<tr><th>Resultados por consulta</th><td><input type="number" name="ingeniero[results_per_query]" min="3" max="10" value="' . esc_attr(absint($settings['results_per_query'])) . '"></td></tr>';
        echo '<tr><th>Consultas por categoría</th><td><input type="number" name="ingeniero[queries_per_category]" min="1" max="4" value="' . esc_attr(absint($settings['queries_per_category'])) . '"></td></tr>';
        echo '<tr><th>Páginas HTML a leer</th><td><input type="number" name="ingeniero[fetch_pages]" min="0" max="8" value="' . esc_attr(absint($settings['fetch_pages'])) . '"><p class="description">Los PDFs solo se detectan y quedan <code>pdf_pending</code>; no se añade parser pesado en v1.</p></td></tr>';
        echo '</tbody></table>';
        submit_button('Guardar configuración');
        echo '</form>';
        echo '</div>';

        self::render_category_table($table_candidates, $stats_map, $category_states, count($all_candidates));

        if ($detail_term_id) self::render_category_detail($detail_term_id);

        echo '<div class="postbox seo-dependiente-admin__box" style="padding:18px">';
        echo '<h3 style="margin-top:0">Lección 2 · Experiencia práctica</h3>';
        echo '<p><strong>Desactivada en v1.</strong> Queda reservada para foros, comunidades profesionales y experiencia de uso. Sus evidencias se marcarán como experiencia/opinión y nunca se elevarán automáticamente a hecho técnico.</p>';
        echo '</div>';

        echo '</section>';
    }

    private static function render_editorial_tab() {
        self::render_notices();

        $status = sanitize_key((string) ($_GET['editorial_status'] ?? ''));
        $action = strtoupper(sanitize_key((string) ($_GET['editorial_action'] ?? '')));
        $page = max(1, absint($_GET['paged'] ?? 1));
        $list = SEO_Ingeniero_DB::editorial_rows(array(
            'status'=>$status,
            'action'=>$action,
            'page'=>$page,
            'per_page'=>30,
        ));
        $counts = SEO_Ingeniero_DB::editorial_counts();
        $detail_id = absint($_GET['editorial_id'] ?? 0);

        echo '<section class="seo-ingeniero-editorial">';
        echo '<div class="postbox" style="padding:18px;margin-top:16px">';
        echo '<h2 style="margin-top:0">Editorial técnico</h2>';
        echo '<p>Convierte únicamente <strong>conocimiento active y trazable</strong> en dossiers técnicos. Ingeniero decide <code>CREATE_POST</code>, <code>IMPROVE_POST</code>, <code>MERGE_CONTENT</code>, <code>NO_ACTION</code> o <code>NEEDS_REVIEW</code> sin depender de Solucionador.</p>';
        echo '<p class="description">Los dossiers guardan referencias a knowledge/sources y un <code>source_hash</code>; no duplican el contenido de investigación. Los borradores se crean sólo tras aprobación humana y nunca se publican automáticamente.</p>';
        echo '<p><strong>Preparación automática:</strong> todas las categorías con conocimiento activo se convierten en dossiers editoriales en segundo plano. Aquí sólo tienes que revisar, aprobar, devolver a revisión o cerrar cada propuesta.</p>';
        echo '</div>';

        $cards = array(
            'Dossiers'=>$counts['total'] ?? 0,
            'Crear post'=>$counts['CREATE_POST'] ?? 0,
            'Mejorar'=>$counts['IMPROVE_POST'] ?? 0,
            'Fusionar'=>$counts['MERGE_CONTENT'] ?? 0,
            'Sin acción'=>$counts['NO_ACTION'] ?? 0,
            'Revisión'=>$counts['NEEDS_REVIEW'] ?? 0,
            'Borradores'=>$counts['draft'] ?? 0,
            'Publicados'=>$counts['published'] ?? 0,
            'Necesitan actualizar'=>$counts['needs_update'] ?? 0,
        );
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px;margin:16px 0">';
        foreach ($cards as $label=>$value) {
            echo '<div class="postbox" style="padding:13px;margin:0"><strong style="display:block;font-size:20px">' . esc_html(number_format_i18n(absint($value))) . '</strong><span>' . esc_html($label) . '</span></div>';
        }
        echo '</div>';

        echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0 16px">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0">';
        echo '<input type="hidden" name="action" value="seo_ingeniero_editorial_accept_all">';
        wp_nonce_field('seo_ingeniero_editorial_accept_all');
        echo '<button type="submit" class="button button-primary" onclick="return confirm(\'Se aprobarán y convertirán en borrador todas las propuestas CREATE_POST elegibles de Ingeniero. No se publicará nada automáticamente. ¿Continuar?\');">Aceptar todo</button>';
        echo '</form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0">';
        echo '<input type="hidden" name="action" value="seo_ingeniero_export">';
        wp_nonce_field('seo_ingeniero_export');
        echo '<button type="submit" class="button">Descargar JSON</button>';
        echo '</form>';
        echo '</div>';

        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin:12px 0">';
        echo '<input type="hidden" name="page" value="seo-ingeniero"><input type="hidden" name="tab" value="editorial">';
        echo '<label>Estado<br><select name="editorial_status"><option value="">Todos</option>';
        foreach (array('candidate'=>'Candidato','review'=>'Revisión','approved'=>'Aprobado','draft'=>'Borrador','published'=>'Publicado','needs_update'=>'Necesita actualizar','closed'=>'Cerrado') as $key=>$label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($status,$key,false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Acción<br><select name="editorial_action"><option value="">Todas</option>';
        foreach (array('CREATE_POST','IMPROVE_POST','MERGE_CONTENT','NO_ACTION','NEEDS_REVIEW') as $key) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($action,$key,false) . '>' . esc_html($key) . '</option>';
        }
        echo '</select></label>';
        submit_button('Filtrar', 'secondary', 'submit', false);
        echo '</form>';

        echo '<div class="postbox" style="padding:0;margin-top:12px;overflow:auto">';
        echo '<table class="widefat striped"><thead><tr><th>Categoría / tema</th><th>Acción</th><th>Cobertura</th><th>Estado</th><th>Base</th><th>Post</th><th>Actualizado</th><th>Acciones</th></tr></thead><tbody>';
        foreach ((array) ($list['rows'] ?? array()) as $row) {
            $term_id = absint($row['term_id'] ?? 0);
            $term = $term_id ? get_term($term_id, 'product_cat') : null;
            $category = $term && !is_wp_error($term) ? (string) $term->name : ('Categoría #' . $term_id);
            $id = absint($row['id'] ?? 0);
            $post_id = absint($row['post_id'] ?? 0);
            echo '<tr>';
            echo '<td><strong>' . esc_html($category) . '</strong><br><span>' . esc_html((string) ($row['suggested_title'] ?? '')) . '</span><br><code>' . esc_html((string) ($row['topic_key'] ?? '')) . '</code></td>';
            echo '<td><code>' . esc_html((string) ($row['recommended_action'] ?? '')) . '</code></td>';
            echo '<td><code>' . esc_html((string) ($row['coverage_status'] ?? '')) . '</code></td>';
            echo '<td><code>' . esc_html((string) ($row['status'] ?? '')) . '</code></td>';
            echo '<td>' . esc_html(number_format_i18n(count((array) ($row['knowledge_ids'] ?? array())))) . ' knowledge<br>' . esc_html(number_format_i18n(count((array) ($row['source_ids'] ?? array())))) . ' fuentes</td>';
            echo '<td>';
            if ($post_id && 'post' === get_post_type($post_id)) {
                echo '<a href="' . esc_url(SEO_Ingeniero_Posts::edit_url($post_id)) . '">#' . esc_html($post_id) . ' · ' . esc_html((string) get_post_status($post_id)) . '</a>';
                if (
                    (string) ($row['status'] ?? '') === 'needs_update'
                    && method_exists('SEO_Ingeniero_Posts','sync_pending_update')
                ) {
                    SEO_Ingeniero_Posts::sync_pending_update($id);
                }
                $pending_updates = method_exists('SEO_Ingeniero_Posts','pending_count')
                    ? SEO_Ingeniero_Posts::pending_count($post_id)
                    : 0;
                if ($pending_updates > 0) {
                    echo '<br><a class="button button-small button-primary" style="margin-top:5px" href="' . esc_url(SEO_Ingeniero_Posts::edit_url($post_id)) . '">Revisar novedades (' . esc_html(number_format_i18n($pending_updates)) . ')</a>';
                }
            } else {
                echo '—';
            }
            echo '</td>';
            echo '<td>' . esc_html((string) ($row['updated_at'] ?? '')) . '</td>';
            echo '<td style="min-width:250px"><a class="button button-small" href="' . esc_url(self::page_url('editorial', array('editorial_id'=>$id))) . '">Ver brief</a> ';
            self::render_editorial_action_button($id, 'reanalyze', 'Reanalizar', 'secondary');
            if (in_array((string) ($row['status'] ?? ''), array('candidate','review','needs_update'), true)) {
                self::render_editorial_action_button($id, 'approve', 'Aprobar', 'primary');
            }
            if ('approved' === (string) ($row['status'] ?? '') && 'CREATE_POST' === strtoupper((string) ($row['recommended_action'] ?? '')) && !$post_id) {
                self::render_editorial_action_button($id, 'create_draft', 'Crear borrador', 'primary');
            }
            echo '</td></tr>';
        }
        if (empty($list['rows'])) echo '<tr><td colspan="8">Todavía no hay dossiers que coincidan con los filtros.</td></tr>';
        echo '</tbody></table></div>';

        if (absint($list['pages'] ?? 1) > 1) {
            $links = paginate_links(array(
                'base'=>add_query_arg(array('page'=>'seo-ingeniero','tab'=>'editorial','editorial_status'=>$status,'editorial_action'=>$action,'paged'=>'%#%'), admin_url('admin.php')),
                'format'=>'',
                'current'=>absint($list['page'] ?? 1),
                'total'=>absint($list['pages'] ?? 1),
                'type'=>'list',
            ));
            if ($links) echo '<div class="tablenav"><div class="tablenav-pages" style="float:none;margin:12px 0">' . wp_kses_post($links) . '</div></div>';
        }

        if ($detail_id) self::render_editorial_brief($detail_id);
        echo '</section>';
    }

    private static function render_editorial_action_button($editorial_id, $command, $label, $class = 'secondary') {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:2px">';
        echo '<input type="hidden" name="action" value="seo_ingeniero_editorial_action"><input type="hidden" name="editorial_id" value="' . esc_attr(absint($editorial_id)) . '"><input type="hidden" name="editorial_command" value="' . esc_attr(sanitize_key($command)) . '">';
        wp_nonce_field('seo_ingeniero_editorial_action');
        echo '<button class="button button-small ' . ('primary' === $class ? 'button-primary' : '') . '" type="submit">' . esc_html($label) . '</button>';
        echo '</form>';
    }

    private static function render_editorial_brief($editorial_id) {
        $brief = SEO_Ingeniero::editorial_brief($editorial_id);
        if (is_wp_error($brief)) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($brief->get_error_message()) . '</p></div>';
            return;
        }

        $dossier = (array) ($brief['dossier'] ?? array());
        $category = (array) ($brief['category'] ?? array());
        echo '<div id="ingeniero-editorial-brief" class="postbox" style="padding:18px;margin-top:18px">';
        echo '<h2 style="margin-top:0">Brief técnico para Editora</h2>';
        echo '<p><strong>' . esc_html((string) ($dossier['suggested_title'] ?? '')) . '</strong></p>';
        echo '<p>Categoría: <strong>' . esc_html((string) ($category['name'] ?? '')) . '</strong> · Tema: <code>' . esc_html((string) ($dossier['topic_key'] ?? '')) . '</code> · Acción: <code>' . esc_html((string) ($dossier['recommended_action'] ?? '')) . '</code>.</p>';

        echo '<h3>Must cover</h3><ul>';
        foreach ((array) ($brief['must_cover'] ?? array()) as $item) echo '<li>' . esc_html((string) $item) . '</li>';
        echo '</ul>';

        echo '<h3>Knowledge incluido</h3><table class="widefat striped"><thead><tr><th>Tipo / concepto</th><th>Síntesis</th><th>Confianza</th><th>Evidencias</th></tr></thead><tbody>';
        foreach ((array) ($brief['knowledge'] ?? array()) as $row) {
            echo '<tr><td><code>' . esc_html((string) ($row['knowledge_type'] ?? '')) . '</code><br>' . esc_html((string) ($row['concept'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['summary'] ?? '')) . '</td>';
            echo '<td>' . esc_html(number_format_i18n(((float) ($row['confidence'] ?? 0))*100,1)) . '%</td>';
            echo '<td>' . esc_html(number_format_i18n(count((array) ($row['facts'] ?? array())))) . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h3 style="margin-top:18px">Fuentes trazables</h3><table class="widefat striped"><thead><tr><th>Fuente</th><th>Tipo</th><th>Confianza</th><th>Recuperada</th></tr></thead><tbody>';
        foreach ((array) ($brief['sources'] ?? array()) as $source) {
            echo '<tr><td><a href="' . esc_url((string) ($source['url'] ?? '')) . '" target="_blank" rel="noopener">' . esc_html((string) (($source['title'] ?? '') ?: ($source['url'] ?? ''))) . '</a></td>';
            echo '<td><code>' . esc_html((string) ($source['source_type'] ?? '')) . '</code></td>';
            echo '<td>' . esc_html((string) ($source['trust_level'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($source['retrieved_at'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';

        $internal_links = (array) ($brief['internal_links'] ?? array());
        echo '<h3 style="margin-top:18px">Enlaces internos sugeridos</h3>';
        echo '<p class="description">Referencias propias para contexto editorial; no convierten la pieza técnica en publicidad.</p>';
        if (!empty($internal_links['categories'])) {
            echo '<p><strong>Categorías:</strong> ';
            $links = array();
            foreach ((array) $internal_links['categories'] as $category_row) {
                $name = (string) ($category_row['name'] ?? '');
                $url = (string) ($category_row['url'] ?? '');
                if ($name === '' || $url === '') continue;
                $links[] = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($name) . '</a>';
            }
            echo wp_kses_post(implode(' · ', $links));
            echo '</p>';
        }
        if (!empty($internal_links['products'])) {
            echo '<ul>';
            foreach ((array) $internal_links['products'] as $product_row) {
                echo '<li><a href="' . esc_url((string) ($product_row['url'] ?? '')) . '" target="_blank" rel="noopener">' . esc_html((string) ($product_row['title'] ?? '')) . '</a></li>';
            }
            echo '</ul>';
        }

        $coverage = (array) ($brief['coverage'] ?? array());
        echo '<h3 style="margin-top:18px">Cobertura existente</h3>';
        echo '<p>Estado: <code>' . esc_html((string) ($coverage['status'] ?? 'uncovered')) . '</code> · score ' . esc_html(number_format_i18n(((float) ($coverage['score'] ?? 0))*100,1)) . '%.</p>';
        if (!empty($coverage['matches'])) {
            echo '<ul>';
            foreach (array_slice((array) $coverage['matches'], 0, 5) as $match) {
                echo '<li>' . esc_html((string) ($match['title'] ?? '')) . ' · ' . esc_html(number_format_i18n(((float) ($match['score'] ?? 0))*100,1)) . '%</li>';
            }
            echo '</ul>';
        }

        echo '<div class="notice notice-warning inline"><p><strong>Verificación editorial:</strong> ' . esc_html((string) ($brief['verification_warning'] ?? '')) . '</p></div>';

        $metrics = (array) ($brief['metrics_28d'] ?? array());
        if (!empty($metrics['has_snapshot'])) {
            echo '<h3 style="margin-top:18px">Rendimiento · Analista · 28 días</h3>';
            echo '<p><strong>Impresiones:</strong> ' . esc_html(number_format_i18n(absint($metrics['impressions'] ?? 0)))
                . ' · <strong>Clics:</strong> ' . esc_html(number_format_i18n(absint($metrics['clicks'] ?? 0)))
                . ' · <strong>Vistas:</strong> ' . esc_html(number_format_i18n(absint($metrics['pageviews'] ?? 0)))
                . ' · <strong>Score:</strong> ' . esc_html(number_format_i18n(absint($metrics['score'] ?? 0))) . '/100</p>';
        }
        if (!empty($brief['analista_url'])) {
            echo '<p><a class="button" href="' . esc_url((string) $brief['analista_url']) . '">Ver métricas globales en Analista</a></p>';
        }

        $status = sanitize_key((string) ($dossier['status'] ?? 'candidate'));
        $post_id = absint($dossier['post_id'] ?? 0);
        echo '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:14px">';
        self::render_editorial_action_button($editorial_id, 'reanalyze', 'Reanalizar cobertura', 'secondary');
        if (in_array($status,array('candidate','review','needs_update'),true)) self::render_editorial_action_button($editorial_id,'approve','Aprobar propuesta','primary');
        if ('approved' === $status && 'CREATE_POST' === strtoupper((string) ($dossier['recommended_action'] ?? '')) && !$post_id) self::render_editorial_action_button($editorial_id,'create_draft','Crear borrador','primary');
        if (!$post_id && !in_array($status,array('closed','published'),true)) self::render_editorial_action_button($editorial_id,'close','Cerrar / no actuar','secondary');
        if ($post_id && 'post' === get_post_type($post_id)) echo '<a class="button button-primary" href="' . esc_url(SEO_Ingeniero_Posts::edit_url($post_id)) . '">Abrir post #' . esc_html($post_id) . '</a>';
        echo '</div>';
        echo '</div>';
    }

    private static function render_data_tab() {
        echo '<section class="seo-ingeniero-data">';
        echo '<div class="postbox" style="padding:18px;margin-top:16px">';
        echo '<h2 style="margin-top:0">Datos y fuentes</h2>';
        echo '<p>Fuentes, conocimiento e intercambio de datos de Ingeniero.</p>';
        if (class_exists('SEO_Ingeniero_Exchange')) {
            SEO_Ingeniero_Exchange::render_panel();
        }
        echo '</div>';
        echo '</section>';
    }

    private static function render_tests_tab() {
        echo '<section class="seo-ingeniero-tests">';
        echo '<div class="postbox" style="padding:18px;margin-top:16px">';
        echo '<h2 style="margin-top:0">Pruebas del proceso editorial</h2>';
        echo '<p>Comprobaciones deterministas de agrupación, hash, cobertura, aprobación humana, unicidad y rol público. No crean posts ni llaman a servicios externos.</p>';
        if (!class_exists('SEO_Ingeniero_Tests')) {
            echo '<div class="notice notice-error inline"><p>No está disponible la batería de pruebas.</p></div></div></section>';
            return;
        }
        $tests = SEO_Ingeniero_Tests::run();
        $passed = count(array_filter($tests, static function($row){ return !empty($row['pass']); }));
        echo '<p><strong>' . esc_html(number_format_i18n($passed)) . '/' . esc_html(number_format_i18n(count($tests))) . '</strong> pruebas correctas.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Prueba</th><th>Estado</th><th>Detalle</th></tr></thead><tbody>';
        foreach ($tests as $row) {
            echo '<tr><td><code>' . esc_html((string) ($row['code'] ?? '')) . '</code></td>';
            echo '<td><strong>' . (!empty($row['pass']) ? 'OK' : 'FALLO') . '</strong></td>';
            echo '<td>' . esc_html((string) ($row['detail'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    private static function render_kpis($candidates, $stats_map, $category_states, $totals, $state) {
        $total = count((array) $candidates);
        $investigated = 0;
        $pending = 0;
        $approved = 0;
        $review = 0;
        $errors = 0;
        $last = 0;

        foreach ((array) $candidates as $row) {
            $tid = absint($row['term_id'] ?? 0);
            if (!$tid) continue;

            $stat = (array) ($stats_map[$tid] ?? array());
            $knowledge = absint($stat['knowledge'] ?? 0);
            $active = absint($stat['active'] ?? 0);
            $review_count = absint($stat['review'] ?? 0);
            $status = sanitize_key((string) ($category_states[$tid]['status'] ?? 'pendiente'));

            if ($knowledge > 0) $investigated++;
            if ($active > 0 && $review_count < 1) $approved++;
            if ($review_count > 0) $review++;
            if ('error' === $status) $errors++;
            if ($knowledge < 1 && 'error' !== $status) $pending++;

            $last = max(
                $last,
                absint($category_states[$tid]['last_research_at'] ?? $category_states[$tid]['updated_at'] ?? 0)
            );
        }

        $cards = array(
            'Total a investigar'=>$total,
            'Investigadas'=>$investigated,
            'Pendientes'=>$pending,
            'Aprobadas'=>$approved,
            'En revisión'=>$review,
            'Errores'=>$errors,
            'Fuentes'=>$totals['sources'],
            'Conocimientos'=>$totals['knowledge'],
            'Confianza media'=>number_format_i18n(((float)$totals['avg_confidence'])*100,1) . '%',
            'Última investigación'=>$last ? wp_date('d/m/Y H:i', $last) : '—',
        );
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:16px 0">';
        foreach ($cards as $label=>$value) {
            echo '<div class="postbox" style="padding:14px;margin:0"><strong style="display:block;font-size:20px">' . esc_html((string) $value) . '</strong><span>' . esc_html($label) . '</span></div>';
        }
        echo '</div>';
        echo '<p class="description" style="margin-top:-6px"><strong>Investigadas</strong> significa que Ingeniero ya ha obtenido conocimiento de la categoría. <strong>Aprobadas</strong> son las investigadas que ya no tienen bloques pendientes de revisión.</p>';
    }

    private static function render_category_table($candidates, $stats_map, $category_states, $catalog_total = 0) {
        echo '<div class="postbox seo-dependiente-admin__box" style="padding:18px">';
        echo '<h3 style="margin-top:0">Categorías</h3>';
        $catalog_total = absint($catalog_total);
        if ($catalog_total > count((array) $candidates)) {
            echo '<p class="description">La tabla muestra las primeras ' . esc_html(number_format_i18n(count((array) $candidates))) . ' categorías de ' . esc_html(number_format_i18n($catalog_total)) . ' del catálogo, incluidas las que aún tienen 0 productos, ordenadas por número de productos. Los KPI superiores sí usan el catálogo completo.</p>';
        }
        $review_total = 0;
        foreach ((array) $stats_map as $stat_row) $review_total += absint($stat_row['review'] ?? 0);
        if ($review_total > 0) {
            echo '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 14px">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0">';
            echo '<input type="hidden" name="action" value="seo_ingeniero_approve_all">';
            wp_nonce_field('seo_ingeniero_approve_all');
            echo '<button class="button button-primary" type="submit" onclick="return confirm(\'Se aprobarán todos los conocimientos actualmente en revisión de Ingeniero. Los rechazados no se modificarán. ¿Continuar?\')">Aprobar todas las categorías en revisión (' . esc_html(number_format_i18n($review_total)) . ')</button>';
            echo '</form>';
            echo '<span class="description">Convierte únicamente <code>review</code> en <code>active</code>; no toca rechazados ni conocimiento sustituido.</span>';
            echo '</div>';
        }
        echo '<table class="widefat striped"><thead><tr><th>Categoría</th><th>Productos</th><th>Estado</th><th>Fuentes</th><th>Conocimiento</th><th>Confianza</th><th>Última</th><th>Acciones</th></tr></thead><tbody>';
        foreach ((array) $candidates as $row) {
            $tid = absint($row['term_id'] ?? 0);
            $stat = (array) ($stats_map[$tid] ?? array());
            $cat_state = (array) ($category_states[$tid] ?? array('status'=>'pendiente'));
            $status = sanitize_key((string) ($cat_state['status'] ?? 'pendiente'));
            $last = absint($cat_state['last_research_at'] ?? $cat_state['updated_at'] ?? 0);

            echo '<tr>';
            echo '<td><strong>' . esc_html((string) ($row['name'] ?? '')) . '</strong><div class="description">#' . esc_html($tid) . '</div></td>';
            echo '<td>' . esc_html(number_format_i18n(absint($row['count'] ?? 0))) . '</td>';
            echo '<td><code>' . esc_html($status) . '</code>';
            if (!empty($cat_state['last_error'])) echo '<div style="color:#b32d2e;max-width:260px">' . esc_html(SEO_Ingeniero::limit_text((string)$cat_state['last_error'],160)) . '</div>';
            echo '</td>';
            echo '<td>' . esc_html(number_format_i18n(absint($stat['sources'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n(absint($stat['knowledge'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n(((float)($stat['avg_confidence'] ?? 0))*100,1)) . '%</td>';
            echo '<td>' . ($last ? esc_html(wp_date('d/m/Y H:i',$last)) : '—') . '</td>';
            echo '<td>';
            if (absint($stat['review'] ?? 0) > 0) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
                echo '<input type="hidden" name="action" value="seo_ingeniero_approve_category"><input type="hidden" name="term_id" value="' . esc_attr($tid) . '">';
                wp_nonce_field('seo_ingeniero_approve_category');
                echo '<button class="button button-small button-primary" type="submit" onclick="return confirm(\'Se aprobará todo el conocimiento en revisión de esta categoría. ¿Continuar?\')">Aprobar categoría</button></form> ';
            }
            echo '<a class="button button-small" href="' . esc_url(self::page_url('research', array('term_id'=>$tid))) . '">Revisar conocimiento</a> ';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
            echo '<input type="hidden" name="action" value="seo_ingeniero_research_category"><input type="hidden" name="term_id" value="' . esc_attr($tid) . '">';
            wp_nonce_field('seo_ingeniero_research_category');
            echo '<button class="button button-small" type="submit">Reinvestigar categoría</button></form></td>';
            echo '</tr>';
        }
        if (!$candidates) echo '<tr><td colspan="8">No hay categorías de producto.</td></tr>';
        echo '</tbody></table>';
        echo '</div>';
    }

    private static function render_category_detail($term_id) {
        $term = get_term($term_id, 'product_cat');
        if (!$term || is_wp_error($term)) return;
        $sources = SEO_Ingeniero_DB::sources_for_category($term_id);
        $knowledge = SEO_Ingeniero_DB::knowledge_for_category($term_id, false);

        echo '<div id="ingeniero-review" class="postbox seo-dependiente-admin__box" style="padding:18px">';
        echo '<h3 style="margin-top:0">Revisión · ' . esc_html((string) $term->name) . '</h3>';
        echo '<p>El conocimiento activo alimenta el Clasificador y el proceso editorial propio de Ingeniero. La publicación pública sólo aparece cuando la Editora publica un post con rol <code>ingeniero_qa_specialized</code>.</p>';

        echo '<h4>Conocimiento consolidado</h4>';
        echo '<table class="widefat striped"><thead><tr><th>Tipo</th><th>Resumen/evidencia</th><th>Confianza</th><th>Estado</th><th>Revisión</th></tr></thead><tbody>';
        foreach ($knowledge as $row) {
            echo '<tr><td><code>' . esc_html((string) ($row['knowledge_type'] ?? '')) . '</code></td>';
            echo '<td><strong>Síntesis propia:</strong> ' . esc_html((string) ($row['summary'] ?? ''));
            $facts = isset($row['facts']) && is_array($row['facts']) ? $row['facts'] : array();
            if ($facts) {
                echo '<details><summary>Ver evidencias y fuentes</summary><ul style="margin:8px 0 0 18px">';
                foreach ($facts as $fact) {
                    $url = esc_url((string) ($fact['source_url'] ?? ''));
                    $title = (string) ($fact['source_title'] ?? '');
                    $evidence = (string) ($fact['evidence'] ?? '');
                    echo '<li style="margin-bottom:8px">' . esc_html($evidence);
                    if ($url) {
                        echo '<br><a href="' . esc_url($url) . '" target="_blank" rel="noopener">Fuente: ' . esc_html($title ?: $url) . '</a>';
                    }
                    echo '</li>';
                }
                echo '</ul></details>';
            }
            echo '</td>';
            echo '<td>' . esc_html(number_format_i18n(((float)($row['confidence'] ?? 0))*100,1)) . '%</td>';
            echo '<td><code>' . esc_html((string) ($row['status'] ?? '')) . '</code></td>';
            echo '<td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:5px;flex-wrap:wrap">';
            echo '<input type="hidden" name="action" value="seo_ingeniero_review"><input type="hidden" name="knowledge_id" value="' . esc_attr(absint($row['id'] ?? 0)) . '"><input type="hidden" name="term_id" value="' . esc_attr($term_id) . '">';
            wp_nonce_field('seo_ingeniero_review');
            echo '<button class="button button-small button-primary" name="decision" value="approve">Aprobar</button>';
            echo '<button class="button button-small" name="decision" value="review">Mantener revisión</button>';
            echo '<button class="button button-small" name="decision" value="reject">Rechazar</button>';
            echo '</form></td></tr>';
        }
        if (!$knowledge) echo '<tr><td colspan="5">Todavía no hay conocimiento consolidado.</td></tr>';
        echo '</tbody></table>';

        echo '<h4 style="margin-top:20px">Fuentes originales consultadas</h4><p class="description">Estas referencias permiten comprobar el origen. El texto de Ingeniero no se publica copiando estas fuentes: se conserva evidencia breve y se genera una síntesis separada.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Fuente</th><th>Tipo</th><th>Confianza</th><th>Estado</th></tr></thead><tbody>';
        foreach ($sources as $source) {
            echo '<tr><td><a href="' . esc_url((string) $source['url']) . '" target="_blank" rel="noopener">' . esc_html((string) ($source['title'] ?: $source['domain'])) . '</a><div class="description">' . esc_html((string) $source['domain']) . '</div></td>';
            echo '<td><code>' . esc_html((string) $source['source_type']) . '</code></td>';
            echo '<td>' . esc_html((string) $source['trust_level']) . '</td>';
            echo '<td><code>' . esc_html((string) $source['status']) . '</code></td></tr>';
        }
        if (!$sources) echo '<tr><td colspan="4">No hay fuentes guardadas.</td></tr>';
        echo '</tbody></table>';

        echo '<p style="margin-top:15px"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="seo_ingeniero_export"><input type="hidden" name="term_id" value="' . esc_attr($term_id) . '">';
        wp_nonce_field('seo_ingeniero_export');
        submit_button('Exportar JSON de esta categoría', 'secondary', 'submit', false);
        echo '</form></p>';
        echo '</div>';
    }

    private static function render_notices() {
        if (!empty($_GET['ingeniero_error'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(rawurldecode((string) $_GET['ingeniero_error'])) . '</p></div>';
        }
        $notice = sanitize_key((string) ($_GET['ingeniero_notice'] ?? ''));
        $messages = array(
            'prepared'=>'Lección preparada.',
            'started'=>'Investigación iniciada/continuada.',
            'stopped'=>'Investigación detenida.',
            'researched'=>'Categoría reinvestigada.',
            'reviewed'=>'Revisión guardada.',
            'category_approved'=>'Categoría aprobada.',
            'all_approved'=>'Conocimiento en revisión aprobado en bloque.',
            'settings'=>'Configuración guardada.',
            'exchange_imported'=>'Conocimiento externo importado para revisión.',
            'editorial_reanalyzed'=>'Dossier editorial reanalizado.',
            'editorial_approved'=>'Propuesta editorial aprobada.',
            'editorial_review'=>'Propuesta devuelta a revisión.',
            'editorial_closed'=>'Propuesta editorial cerrada.',
            'editorial_draft'=>'Borrador técnico creado para la Editora.',
            'editorial_refreshed'=>'Lote de dossiers actualizado.',
            'editorial_all_accepted'=>'Aceptar todo completado.',
        );
        if ($notice && isset($messages[$notice])) {
            $suffix = '';
            if ('exchange_imported' === $notice) {
                $suffix = ' Categorías: ' . absint($_GET['ingeniero_import_categories'] ?? 0)
                    . ' · Fuentes: ' . absint($_GET['ingeniero_import_sources'] ?? 0)
                    . ' · Conocimientos: ' . absint($_GET['ingeniero_import_knowledge'] ?? 0)
                    . ' · Omitidos: ' . absint($_GET['ingeniero_import_skipped'] ?? 0)
                    . ' · Errores: ' . absint($_GET['ingeniero_import_errors'] ?? 0) . '.';
            } elseif ('category_approved' === $notice) {
                $suffix = ' Conocimientos aprobados: ' . absint($_GET['ingeniero_approved_knowledge'] ?? 0) . '.';
            } elseif ('all_approved' === $notice) {
                $suffix = ' Categorías: ' . absint($_GET['ingeniero_approved_categories'] ?? 0)
                    . ' · Conocimientos aprobados: ' . absint($_GET['ingeniero_approved_knowledge'] ?? 0) . '.';
            } elseif ('editorial_refreshed' === $notice) {
                $suffix = ' Procesadas: ' . absint($_GET['editorial_processed'] ?? 0)
                    . ' · Errores: ' . absint($_GET['editorial_errors'] ?? 0) . '.';
            } elseif ('editorial_draft' === $notice) {
                $suffix = ' Post #' . absint($_GET['post_id'] ?? 0) . '.';
            } elseif ('editorial_all_accepted' === $notice) {
                $suffix = ' Borradores creados: ' . absint($_GET['editorial_created'] ?? 0)
                    . ' · Omitidos: ' . absint($_GET['editorial_skipped'] ?? 0)
                    . ' · Errores: ' . absint($_GET['editorial_errors'] ?? 0) . '.';
            }
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$notice] . $suffix) . '</p></div>';
        }

        if (!empty($_GET['ingeniero_exchange_error'])) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Importar / Exportar conocimiento:</strong> ' . esc_html(rawurldecode((string) $_GET['ingeniero_exchange_error'])) . '</p></div>';
        }
    }
}

SEO_Ingeniero_Admin::init();
