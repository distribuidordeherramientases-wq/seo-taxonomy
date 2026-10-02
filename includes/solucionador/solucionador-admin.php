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
        add_action('admin_post_seo_solucionador_proposal', array(__CLASS__, 'handle_proposal'));
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
            'desc' => 'Propone posts desde preguntas aprendidas, crea borradores y muestra su rendimiento en Google.',
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
            'tab'=>'posts',
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

    public static function handle_proposal() {
        if (!current_user_can('manage_options')) wp_die('No tienes permisos para gestionar Solucionador.');

        $category_id = absint($_POST['category_id'] ?? 0);
        $proposal_action = sanitize_key((string) ($_POST['proposal_action'] ?? ''));
        if (!$category_id) {
            set_transient('seo_solucionador_notice_' . get_current_user_id(), 'La propuesta no tiene categoría.', 90);
            self::redirect(array('sol_error'=>'missing_category'));
        }
        check_admin_referer('seo_solucionador_proposal_' . $category_id);

        $topic = SEO_Solucionador_Engine::prepare_category_topic($category_id);
        if (is_wp_error($topic)) {
            set_transient('seo_solucionador_notice_' . get_current_user_id(), $topic->get_error_message(), 90);
            self::redirect(array('sol_error'=>'prepare_proposal'));
        }
        $topic_id = absint($topic['id'] ?? 0);
        if (!$topic_id) {
            set_transient('seo_solucionador_notice_' . get_current_user_id(), 'No se pudo preparar la propuesta.', 90);
            self::redirect(array('sol_error'=>'prepare_proposal'));
        }

        if ($proposal_action === 'create_draft') {
            SEO_Solucionador_DB::update_topic($topic_id, array(
                'status'=>'approved',
                'workflow_state'=>'approved',
                'recommended_action'=>'CREATE_POST',
                'decision_reason'=>'Creación aprobada desde la interfaz simplificada de Solucionador.',
            ));
            SEO_Solucionador_DB::record_workflow(
                $topic_id,
                'approved',
                'Usuario acepta la propuesta y solicita crear el borrador.',
                'CREATE_POST'
            );
            $result = SEO_Solucionador_Posts::create_draft($topic_id, true);
            if (is_wp_error($result)) {
                set_transient('seo_solucionador_notice_' . get_current_user_id(), $result->get_error_message(), 90);
                self::redirect(array('sol_error'=>'create_draft'));
            }
            self::redirect(array('sol_msg'=>'draft_created','post_id'=>absint($result)));
        }

        if ($proposal_action === 'discard') {
            SEO_Solucionador_DB::update_topic($topic_id, array(
                'status'=>'dismissed',
                'workflow_state'=>'rejected',
            ));
            SEO_Solucionador_DB::record_workflow(
                $topic_id,
                'rejected',
                'Propuesta descartada desde la interfaz simplificada; se conserva para poder recuperarla más adelante.',
                (string) ($topic['recommended_action'] ?? 'CREATE_POST')
            );
            self::redirect(array('sol_msg'=>'discarded'));
        }

        if ($proposal_action === 'restore') {
            SEO_Solucionador_DB::update_topic($topic_id, array(
                'status'=>'candidate',
                'workflow_state'=>'candidate',
                'recommended_action'=>'CREATE_POST',
                'decision_reason'=>'Propuesta recuperada para revisión.',
            ));
            SEO_Solucionador_DB::record_workflow(
                $topic_id,
                'candidate',
                'Propuesta recuperada desde la interfaz simplificada.',
                'CREATE_POST'
            );
            self::redirect(array('sol_msg'=>'restored'));
        }

        set_transient('seo_solucionador_notice_' . get_current_user_id(), 'Acción no reconocida.', 90);
        self::redirect(array('sol_error'=>'invalid_action'));
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
            'posts' => 'Posts propuestos',
            'google' => 'Visitas Google',
            'reports' => 'Informes JSON',
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
            'total'       => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE workflow_state<>'rejected'"),
            'active'      => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE workflow_state IN ('detected','validated','candidate','approved','brief_ready','in_editing','scheduled')"),
            'candidate'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE workflow_state='candidate'"),
            'approved'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE workflow_state IN ('approved','brief_ready')"),
            'improve'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE recommended_action='IMPROVE_POST' AND workflow_state<>'rejected'"),
            'merge'       => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE recommended_action='MERGE_CONTENT' AND workflow_state<>'rejected'"),
            'no_action'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE recommended_action='NO_ACTION' AND workflow_state<>'rejected'"),
            'deferred'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE recommended_action='DEFER' OR workflow_state='deferred'"),
            'create_post' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE recommended_action='CREATE_POST' AND workflow_state<>'rejected'"),
            'drafts'      => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE draft_post_id IS NOT NULL AND draft_post_id>0"),
        );
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        SEO_Solucionador_DB::maybe_install();
        $initialization = SEO_Solucionador_Engine::ensure_initialized(180);

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'posts';
        if (!in_array($tab, array('posts','google','reports'), true)) $tab = 'posts';

        echo '<div class="wrap seo-solucionador"><h1>Solucionador</h1>';
        echo '<p>Propuestas de posts creadas desde las preguntas que Dependiente ya ha aprendido.</p>';

        if (is_wp_error($initialization)) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html($initialization->get_error_message()) . '</p></div>';
        }

        if (!empty($_GET['sol_msg']) && $_GET['sol_msg'] === 'draft_created') {
            $post_id = absint($_GET['post_id'] ?? 0);
            echo '<div class="notice notice-success is-dismissible"><p>Borrador creado.';
            if ($post_id) echo ' <a href="' . esc_url(SEO_Solucionador_Posts::edit_url($post_id)) . '"><strong>Abrir borrador #' . esc_html($post_id) . '</strong></a>';
            echo '</p></div>';
        } elseif (!empty($_GET['sol_msg']) && $_GET['sol_msg'] === 'discarded') {
            echo '<div class="notice notice-success is-dismissible"><p>Propuesta descartada. Se conserva y puede recuperarse más adelante.</p></div>';
        } elseif (!empty($_GET['sol_msg']) && $_GET['sol_msg'] === 'restored') {
            echo '<div class="notice notice-success is-dismissible"><p>Propuesta recuperada.</p></div>';
        }
        if (!empty($_GET['sol_error'])) {
            $msg = get_transient('seo_solucionador_notice_' . get_current_user_id());
            delete_transient('seo_solucionador_notice_' . get_current_user_id());
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg ?: 'No se pudo completar la acción.') . '</p></div>';
        }

        self::tabs($tab);
        if ($tab === 'google') self::render_google_simple();
        elseif ($tab === 'reports') self::render_reports_simple();
        else self::render_posts_simple();

        self::styles();
        echo '</div>';
    }

    private static function simple_topic_for_category($category_id) {
        $key = 'dependiente-qa-basic|resolver|category-' . absint($category_id) . '|general|general';
        $topic_id = SEO_Solucionador_DB::get_topic_id_by_key($key);
        return $topic_id ? SEO_Solucionador_DB::get_topic($topic_id) : array();
    }

    private static function simple_proposal_title(array $dossier, array $topic = array()) {
        $title = trim((string) ($topic['suggested_title'] ?? ''));
        if ($title !== '') return $title;
        $name = trim((string) ($dossier['category_name'] ?? ''));
        return $name !== '' ? $name . ': preguntas habituales, elección y uso' : 'Post propuesto';
    }

    private static function simple_proposal_state(array $topic) {
        $post_id = absint($topic['draft_post_id'] ?? 0);
        if ($post_id) {
            $status = get_post_status($post_id);
            if ($status === 'publish') return array('label'=>'Publicado','post_id'=>$post_id,'status'=>$status);
            if ($status && $status !== 'trash') return array('label'=>'Borrador','post_id'=>$post_id,'status'=>$status);
        }
        if (sanitize_key((string) ($topic['workflow_state'] ?? '')) === 'rejected') {
            return array('label'=>'Descartado','post_id'=>0,'status'=>'rejected');
        }
        return array('label'=>'Propuesto','post_id'=>0,'status'=>'proposal');
    }

    private static function proposal_action_form($category_id, $action, $label, $class = 'secondary') {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:6px">';
        echo '<input type="hidden" name="action" value="seo_solucionador_proposal">';
        echo '<input type="hidden" name="category_id" value="' . esc_attr(absint($category_id)) . '">';
        echo '<input type="hidden" name="proposal_action" value="' . esc_attr($action) . '">';
        wp_nonce_field('seo_solucionador_proposal_' . absint($category_id));
        submit_button($label, $class, 'submit', false);
        echo '</form>';
    }

    private static function render_posts_simple() {
        global $wpdb;
        $table = SEO_Solucionador_DB::dossiers_table();
        if (!SEO_Solucionador_DB::table_exists($table)) {
            echo '<div class="postbox" style="padding:18px;margin-top:18px"><p>No hay propuestas todavía.</p></div>';
            return;
        }

        $per_page = 25;
        $page = max(1, absint($_GET['sol_page'] ?? 1));
        $total = absint($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE question_count>0"));
        $pages = max(1, (int) ceil($total / $per_page));
        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * $per_page;

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE question_count>0 ORDER BY category_name ASC,id ASC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        echo '<div class="postbox" style="padding:18px;margin-top:18px">';
        echo '<h2 style="margin-top:0">Posts propuestos</h2>';
        echo '<p><strong>' . esc_html(number_format_i18n($total)) . '</strong> propuestas. Al crear un borrador se copiarán dentro del contenido las preguntas y respuestas mostradas aquí.</p>';
        echo '</div>';

        foreach ($rows as $dossier) {
            $category_id = absint($dossier['category_id'] ?? 0);
            if (!$category_id) continue;
            $topic = self::simple_topic_for_category($category_id);
            $state = self::simple_proposal_state($topic);
            $title = self::simple_proposal_title($dossier,$topic);
            $details = SEO_Solucionador_Dossiers::question_details($category_id);

            echo '<div class="postbox" style="padding:18px;margin-top:14px">';
            echo '<div style="display:flex;justify-content:space-between;gap:18px;align-items:flex-start;flex-wrap:wrap">';
            echo '<div style="min-width:300px;flex:1"><h2 style="margin:0 0 6px">' . esc_html($title) . '</h2>';
            echo '<p class="description" style="margin:0">' . esc_html((string) ($dossier['category_name'] ?? '')) . ' · ' . esc_html(number_format_i18n(count($details))) . ' preguntas/respuestas · <strong>' . esc_html($state['label']) . '</strong></p></div>';
            echo '<div style="white-space:nowrap">';
            if (!empty($state['post_id'])) {
                echo '<a class="button button-primary" href="' . esc_url(SEO_Solucionador_Posts::edit_url($state['post_id'])) . '">Abrir ' . ($state['status']==='publish' ? 'post' : 'borrador') . '</a>';
            } elseif ($state['status'] === 'rejected') {
                self::proposal_action_form($category_id,'restore','Recuperar','secondary');
            } else {
                self::proposal_action_form($category_id,'create_draft','Convertir en borrador','primary');
                self::proposal_action_form($category_id,'discard','Descartar','secondary');
            }
            echo '</div></div>';

            echo '<div style="margin-top:16px">';
            if (!$details) {
                echo '<p class="description">No se han podido recuperar las preguntas aprendidas de esta propuesta.</p>';
            } else {
                foreach ($details as $index=>$detail) {
                    $question = trim((string) ($detail['question'] ?? ''));
                    if ($question === '') continue;
                    $answer = SEO_Solucionador_Dossiers::answer_text((array) $detail);
                    echo '<div style="border-top:1px solid #dcdcde;padding:12px 0">';
                    echo '<p style="margin:0 0 6px"><strong>' . esc_html(($index + 1) . '. ' . $question) . '</strong></p>';
                    echo '<p style="margin:0"><strong>Respuesta:</strong> ' . esc_html($answer) . '</p>';
                    echo '</div>';
                }
            }
            echo '</div></div>';
        }

        if ($pages > 1) {
            echo '<div style="display:flex;justify-content:space-between;align-items:center;margin:18px 0">';
            echo '<span>Página ' . esc_html(number_format_i18n($page)) . ' de ' . esc_html(number_format_i18n($pages)) . '</span><span>';
            if ($page > 1) echo '<a class="button" href="' . esc_url(self::url('posts',array('sol_page'=>$page-1))) . '">Anterior</a> ';
            if ($page < $pages) echo '<a class="button button-primary" href="' . esc_url(self::url('posts',array('sol_page'=>$page+1))) . '">Siguiente</a>';
            echo '</span></div>';
        }
    }

    private static function google_metrics_for_post($post_id) {
        $post_id = absint($post_id);
        if (!$post_id || !function_exists('seo_post_reports_get_summary')) return array();
        $row = seo_post_reports_get_summary($post_id,28);
        return is_array($row) ? $row : array();
    }

    private static function render_google_simple() {
        global $wpdb;
        $meta_key = SEO_Solucionador_Posts::META_TOPIC_ID;
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT p.ID,p.post_title,p.post_status
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
                 WHERE p.post_type='post' AND p.post_status<>'trash' AND pm.meta_key=%s
                 ORDER BY p.post_date DESC,p.ID DESC
                 LIMIT 1000",
                $meta_key
            ),
            ARRAY_A
        );

        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Visitas Google</h2>';
        echo '<p>Rendimiento de los posts creados por Solucionador durante los últimos 28 días, usando los datos ya capturados por los informes del sitio.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Post</th><th>Estado</th><th>Impresiones Google</th><th>Clics Google</th><th>Vistas</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="5">Todavía no hay posts creados por Solucionador.</td></tr>';
        foreach ($rows as $row) {
            $post_id = absint($row['ID'] ?? 0);
            $metrics = self::google_metrics_for_post($post_id);
            $has = !empty($metrics['has_snapshot']);
            echo '<tr><td><a href="' . esc_url(SEO_Solucionador_Posts::edit_url($post_id)) . '"><strong>' . esc_html((string) ($row['post_title'] ?? '')) . '</strong></a></td>';
            echo '<td>' . esc_html((string) ($row['post_status'] ?? '')) . '</td>';
            echo '<td>' . ($has ? esc_html(number_format_i18n(absint($metrics['impressions'] ?? 0))) : '—') . '</td>';
            echo '<td>' . ($has ? esc_html(number_format_i18n(absint($metrics['clicks'] ?? 0))) : '—') . '</td>';
            echo '<td>' . ($has ? esc_html(number_format_i18n(absint($metrics['pageviews'] ?? 0))) : '—') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function render_reports_simple() {
        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Informe JSON</h2>';
        echo '<p>Descarga un único informe con las propuestas, sus preguntas/respuestas, el estado de cada post y las métricas de Google disponibles.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="seo_solucionador_export_report_json">';
        wp_nonce_field('seo_solucionador_export_report_json');
        submit_button('Descargar informe JSON','primary','submit',false);
        echo '</form></div>';
    }

    private static function render_summary() {
        $counts = self::counts();
        $last = get_option('seo_solucionador_last_scan', array());
        $academy = class_exists('SEO_Solucionador_Dossiers') ? SEO_Solucionador_Dossiers::snapshot() : array();

        echo '<div class="seo-sol-grid">';
        self::card('Dossiers con conocimiento', absint($academy['categories_with_knowledge'] ?? 0), 'Un dossier por product_cat con preguntas aprendidas.');
        self::card('Preguntas aprendidas', absint($academy['learned'] ?? 0), 'Último run answered con evaluation_status pass_*.');
        self::card('Aprendidas sin categoría', absint($academy['learned_without_category'] ?? 0), 'No crean dossier ni URL hasta disponer de product_cat demostrable.');
        self::card('CREATE_POST', $counts['create_post'] ?? 0, 'Dossiers sin cobertura equivalente y con masa crítica.');
        self::card('IMPROVE_POST', $counts['improve'] ?? 0, 'Existe un post equivalente con cobertura débil/parcial.');
        self::card('MERGE_CONTENT', $counts['merge'] ?? 0, 'Existen piezas solapadas que conviene consolidar.');
        self::card('NO_ACTION', $counts['no_action'] ?? 0, 'La intención básica ya está suficientemente cubierta.');
        self::card('DEFER / REVIEW', $counts['deferred'] ?? 0, 'Falta categoría, masa crítica o existe una situación que requiere revisión.');
        self::card('Borradores', $counts['drafts'] ?? 0, 'Posts de trabajo; Solucionador nunca publica automáticamente.');
        echo '</div>';

        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Arquitectura separada</h2>';
        echo '<p><strong>Academia/Entrenador → Solucionador → brief básico → Editora → post WordPress.</strong> Ingeniero y Comparador tienen sus propios procesos editoriales. Solucionador no investiga, no compara mercado y no redacta respuestas públicas.</p>';
        echo '<p><a class="button button-primary" href="' . esc_url(self::url('proposals')) . '">Abrir dossiers/propuestas</a> <a class="button" href="' . esc_url(self::url('coverage')) . '">Revisar cobertura compartida</a></p>';
        echo '</div>';

        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Procesar Academia</h2>';
        echo '<p>El proceso usa lotes pequeños y cursores persistentes. Cada ejecución continúa donde terminó la anterior; no carga todas las preguntas en memoria.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_solucionador_scan">';
        wp_nonce_field('seo_solucionador_scan');
        echo '<input type="hidden" name="days" value="180">';
        submit_button(!empty($last['complete']) ? 'Iniciar nuevo análisis' : 'Continuar análisis', 'primary', 'submit', false);
        echo '</form>';

        if ($last) {
            $phase = sanitize_key((string)($last['phase'] ?? ''));
            echo '<p class="description" style="margin-top:12px"><strong>Estado:</strong> ' . (!empty($last['complete']) ? 'completo' : 'en curso');
            if ($phase !== '') echo ' · fase: ' . esc_html(str_replace('_',' ',$phase));
            echo ' · dossiers procesados: ' . esc_html(number_format_i18n(absint($last['sources_seen'] ?? 0)));
            echo ' · aceptados: ' . esc_html(number_format_i18n(absint($last['accepted'] ?? 0)));
            echo ' · descartados: ' . esc_html(number_format_i18n(absint($last['discarded'] ?? 0))) . '</p>';
        }
        if ($academy) {
            echo '<p class="description"><strong>Academia:</strong> cursor ' . esc_html(number_format_i18n(absint($academy['cursor'] ?? 0)))
                . ' · procesadas ' . esc_html(number_format_i18n(absint($academy['processed'] ?? 0)))
                . ' · con categoría ' . esc_html(number_format_i18n(absint($academy['learned_with_category'] ?? 0)))
                . ' · sin categoría ' . esc_html(number_format_i18n(absint($academy['learned_without_category'] ?? 0)))
                . ' · errores aislados ' . esc_html(number_format_i18n(absint($academy['errors'] ?? 0))) . '</p>';
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
        $academy = class_exists('SEO_Solucionador_Dossiers') ? SEO_Solucionador_Dossiers::snapshot() : array();

        $items['Dependiente · Academia'] = array(
            'available'=>!empty($academy['available']),
            'metric'=>absint($academy['categories_with_knowledge'] ?? 0),
            'unit'=>'dossiers por categoría',
            'detail'=>number_format_i18n(absint($academy['learned'] ?? 0)) . ' preguntas pass_* · '
                . number_format_i18n(absint($academy['learned_without_category'] ?? 0)) . ' sin categoría',
        );

        $search_table = $wpdb->prefix . 'seo_dependiente_search_log';
        $searches = SEO_Solucionador_DB::table_exists($search_table)
            ? absint($wpdb->get_var("SELECT COUNT(*) FROM {$search_table}"))
            : 0;
        $items['Dependiente · demanda real'] = array(
            'available'=>$searches > 0,
            'metric'=>$searches,
            'unit'=>'consultas registradas',
            'detail'=>'Señal separada de demanda. No origina dossiers ni URLs en Solucionador v0.5.',
        );

        $coverage = SEO_Solucionador_DB::table_exists(SEO_Solucionador_DB::coverage_table())
            ? absint($wpdb->get_var('SELECT COUNT(*) FROM ' . SEO_Solucionador_DB::coverage_table()))
            : 0;
        $items['Cobertura editorial compartida'] = array(
            'available'=>$coverage > 0,
            'metric'=>$coverage,
            'unit'=>'huellas indexadas',
            'detail'=>'API neutral SEO_Editorial_Coverage; evita duplicación y canibalización.',
        );

        $items['Analista'] = array(
            'available'=>function_exists('seo_analista_get_data') || function_exists('seo_post_reports_get_summary'),
            'metric'=>0,
            'unit'=>'medición',
            'detail'=>'Fuente de KPIs posteriores; no genera temas ni decisiones de Solucionador.',
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
        global $wpdb;
        $academy = class_exists('SEO_Solucionador_Dossiers') ? SEO_Solucionador_Dossiers::snapshot() : array();
        $dossiers_table = SEO_Solucionador_DB::dossiers_table();

        echo '<div class="postbox" style="padding:18px;margin-top:18px">';
        echo '<h2 style="margin-top:0">Diagnóstico Academia → Solucionador</h2>';
        echo '<p>Este diagnóstico comprueba únicamente el circuito de contenido básico: preguntas aprendidas, resolución de product_cat, dossiers, cobertura y decisión editorial. Ingeniero y Comparador se diagnostican en sus propios procesos.</p>';
        echo '</div>';

        echo '<div class="seo-sol-grid">';
        self::card('Preguntas currículo',absint($academy['questions_total']??0),'Activas y elegibles para el recorrido.');
        self::card('Aprendidas pass_*',absint($academy['learned']??0),'Última ejecución respondida y validada.');
        self::card('Con product_cat',absint($academy['learned_with_category']??0),'Con asociación demostrable.');
        self::card('Sin product_cat',absint($academy['learned_without_category']??0),'No crean dossier ni URL.');
        self::card('Dossiers',absint($academy['categories_with_knowledge']??0),'Categorías con conocimiento aprendido.');
        self::card('Sin conocimiento',absint($academy['categories_without_knowledge']??0),'Categorías WooCommerce sin dossier.');
        self::card('Media / categoría',(float)($academy['avg_questions_per_category']??0),'Preguntas aprendidas por dossier.');
        self::card('Errores aislados',absint($academy['errors']??0),'No detienen el resto del lote.');
        echo '</div>';

        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h3 style="margin-top:0">Dossiers por categoría</h3>';
        if (!SEO_Solucionador_DB::table_exists($dossiers_table)) {
            echo '<p>La tabla de dossiers todavía no está disponible.</p></div>';
            return;
        }
        $rows=(array)$wpdb->get_results("SELECT id,category_id,category_name,question_count,score_avg,last_validated_at FROM {$dossiers_table} ORDER BY question_count DESC,category_name ASC LIMIT 500",ARRAY_A);
        echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>Categoría</th><th>Preguntas</th><th>Score medio</th><th>Última validación</th><th>Dossier</th></tr></thead><tbody>';
        if(!$rows) echo '<tr><td colspan="5">Aún no hay dossiers. Continúa el procesamiento de Academia desde Resumen.</td></tr>';
        foreach($rows as $row){
            $term_id=absint($row['category_id']??0);
            echo '<tr><td><strong>' . esc_html((string)$row['category_name']) . '</strong><br><code>#' . esc_html($term_id) . '</code></td>';
            echo '<td>' . esc_html(number_format_i18n(absint($row['question_count']??0))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float)($row['score_avg']??0)*100,0)) . '%</td>';
            echo '<td>' . esc_html((string)($row['last_validated_at']??'')) . '</td>';
            echo '<td><a class="button button-small" href="' . esc_url(self::url('proposals',array('sol_category'=>$term_id))) . '">Ver propuesta</a></td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="description">Una categoría con conocimiento puede terminar en CREATE_POST, IMPROVE_POST, MERGE_CONTENT, NO_ACTION o DEFER según su cobertura. El número de dossiers no equivale al número de URLs nuevas.</p>';
        echo '</div>';
    }
    private static function action_label($action) {
        $action = strtoupper((string) $action);
        $labels = array(
            'NO_ACTION'=>'No actuar',
            'IMPROVE_POST'=>'Mejorar post',
            'MERGE_CONTENT'=>'Fusionar / consolidar contenido',
            'CREATE_POST'=>'Crear post',
            'DEFER'=>'Aplazar / revisar',
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
            'intent'=>(string)($topic['intent'] ?? ''),
            'action'=>(string)($topic['action_term'] ?? ''),
            'object'=>(string)($topic['object_term'] ?? ''),
            'condition'=>(string)($topic['condition_term'] ?? ''),
            'context'=>(string)($topic['context_term'] ?? ''),
            'canonical_key'=>(string)($topic['canonical_key'] ?? ''),
            'category_id'=>$primary_category_id,
        );
        $coverage = SEO_Editorial_Coverage::find($profile);

        $question_details = ($primary_category_id && class_exists('SEO_Solucionador_Dossiers'))
            ? SEO_Solucionador_Dossiers::question_details($primary_category_id)
            : array();
        $questions = array();
        foreach ($question_details as $item) {
            $q = trim((string)($item['question'] ?? ''));
            if ($q !== '') $questions[] = $q;
        }

        $products = array();
        if ($primary_category_id) {
            $ids = get_posts(array(
                'post_type'=>'product','post_status'=>'publish','posts_per_page'=>12,'fields'=>'ids',
                'tax_query'=>array(array('taxonomy'=>'product_cat','field'=>'term_id','terms'=>array($primary_category_id),'operator'=>'IN')),
            ));
            foreach ((array)$ids as $product_id) {
                $products[] = array('id'=>absint($product_id),'title'=>get_the_title($product_id),'url'=>get_permalink($product_id));
            }
        }

        $preserve = array();
        $links = array();
        foreach ((array)($coverage['matches'] ?? array()) as $match) {
            $label = trim((string)($match['title'] ?? ''));
            $text = trim((string)($match['source_text'] ?? ''));
            if ($label !== '') $preserve[] = $label . ($text !== '' && $text !== $label ? ' — ' . wp_trim_words($text,22,'…') : '');
            $url = trim((string)($match['url'] ?? ''));
            if ($url !== '') $links[$url] = array('label'=>$label !== '' ? $label : $url,'url'=>$url);
        }
        if ($primary_category_id) {
            $term = get_term($primary_category_id,'product_cat');
            if ($term && !is_wp_error($term)) {
                $term_url = get_term_link($term);
                if (!is_wp_error($term_url)) $links[(string)$term_url] = array('label'=>(string)$term->name,'url'=>(string)$term_url);
            }
        }
        foreach ($products as $product) {
            if (!empty($product['url'])) $links[$product['url']] = array('label'=>$product['title'],'url'=>$product['url']);
        }

        $workflow = SEO_Solucionador_DB::workflow_history($topic_id,100);
        $analista = array();
        $entity_type = (string)($topic['existing_entity_type'] ?? '');
        $entity_id = absint($topic['existing_entity_id'] ?? 0);
        $draft_id = absint($topic['draft_post_id'] ?? 0);
        $post_id = $entity_type === 'post' ? $entity_id : ($draft_id && get_post_status($draft_id)==='publish' ? $draft_id : 0);
        if ($post_id && function_exists('seo_post_reports_get_summary')) {
            $analista = (array)seo_post_reports_get_summary($post_id,28);
        }

        return array(
            'topic_id'=>$topic_id,
            'topic'=>(string)(($topic['suggested_title'] ?? '') ?: ($topic['canonical_question'] ?? '')),
            'question'=>(string)($topic['canonical_question'] ?? ''),
            'intent'=>(string)($topic['intent'] ?? ''),
            'output'=>self::action_label((string)($topic['recommended_action'] ?? 'DEFER')),
            'action'=>(string)($topic['recommended_action'] ?? 'DEFER'),
            'decision_reason'=>(string)($topic['decision_reason'] ?? ''),
            'priority'=>(float)($topic['priority_score'] ?? 0),
            'priority_components'=>$priority_components,
            'requirements'=>$requirements,
            'evidence'=>$evidence,
            'categories'=>$categories,
            'primary_category_id'=>$primary_category_id,
            'hierarchy'=>$hierarchy,
            'vocabulary'=>$vocabulary,
            'products'=>$products,
            'questions'=>array_values(array_unique($questions)),
            'question_details'=>$question_details,
            'preserve'=>array_slice(array_values(array_unique($preserve)),0,12),
            'links'=>array_values($links),
            'coverage'=>$coverage,
            'duplication_risk'=>(float)($topic['duplication_risk'] ?? 0),
            'cannibalization_risk'=>(float)($topic['cannibalization_risk'] ?? 0),
            'workflow_state'=>(string)($topic['workflow_state'] ?? 'detected'),
            'workflow'=>$workflow,
            'analista'=>$analista,
            'existing_entity_type'=>$entity_type,
            'existing_entity_id'=>$entity_id,
            'draft_post_id'=>$draft_id,
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
        echo '<div><h2 style="margin:0 0 6px">Dossier editorial #' . esc_html($brief['topic_id']) . '</h2><strong style="font-size:18px">' . esc_html($brief['topic']) . '</strong><br><code>' . esc_html((string)($topic['canonical_key'] ?? '')) . '</code></div>';
        echo '<a class="button" href="' . esc_url(self::url('proposals')) . '">Cerrar dossier</a></div>';

        echo '<div class="seo-sol-grid" style="margin-top:14px">';
        self::card('Preguntas aprendidas', count($brief['question_details']), 'Detalles cargados bajo demanda desde Academia.');
        self::card('Acción', $brief['output'], $brief['decision_reason']);
        self::card('Cobertura', self::coverage_label((string)($brief['coverage']['status'] ?? 'uncovered')), 'Similitud ' . number_format_i18n((float)($brief['coverage']['score'] ?? 0)*100,0) . '%.');
        self::card('Workflow', self::workflow_label($brief['workflow_state']), 'La publicación sigue siendo humana.');
        echo '</div>';

        echo '<div class="seo-sol-opportunity-sections">';
        echo '<section><h3>1. Academia / preguntas aprendidas</h3>';
        echo '<p>Solucionador organiza conocimiento ya aprendido; no vuelve a entrenar Dependiente.</p>';
        if (!$brief['question_details']) echo '<p>No se han recuperado detalles pass_* para este dossier.</p>';
        else {
            echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>Pregunta</th><th>Lección / tipo</th><th>Validación</th><th>Evidencia interna</th><th>Fecha</th></tr></thead><tbody>';
            foreach ($brief['question_details'] as $item) {
                $labels=array();
                foreach (array_slice((array)($item['top_results'] ?? array()),0,3) as $result) {
                    if (!is_array($result)) continue;
                    $label=trim((string)($result['title'] ?? $result['name'] ?? $result['label'] ?? ''));
                    if ($label!=='') $labels[]=$label;
                }
                echo '<tr><td><strong>' . esc_html((string)$item['question']) . '</strong></td>';
                echo '<td><code>' . esc_html((string)$item['lesson_key']) . '</code><br>' . esc_html((string)$item['question_type']) . '</td>';
                echo '<td><strong>' . esc_html((string)$item['evaluation_status']) . '</strong><br>score ' . esc_html(number_format_i18n((float)$item['evaluation_score']*100,0)) . '%</td>';
                echo '<td>' . esc_html($labels ? wp_trim_words(implode(' · ',$labels),30,'…') : 'Resultado interno disponible') . '</td>';
                echo '<td>' . esc_html((string)$item['observed_at']) . '</td></tr>';
            }
            echo '</tbody></table></div>';
            echo '<p class="description"><strong>Regla:</strong> estos resultados son evidencia de trabajo. Editora redacta el contenido público; no se publican literalmente respuestas internas de Academia.</p>';
        }
        echo '</section>';

        echo '<section><h3>2. Categoría y Vocabulary</h3>';
        $h=(array)$brief['hierarchy']; $cat=(array)($h['category'] ?? array());
        echo '<p><strong>product_cat principal:</strong> ' . (!empty($cat['id']) ? '#' . esc_html(absint($cat['id'])) . ' · ' . esc_html((string)($cat['name'] ?? '')) : '—') . '</p>';
        echo '<p><strong>Contrato:</strong> <code>post_to_category</code> · rol <code>dependiente_qa_basic</code>.</p>';
        echo '<p><strong>Vocabulary:</strong></p>' . self::vocab_chips($topic);
        echo '</section>';

        echo '<section><h3>3. Cobertura editorial compartida</h3>';
        echo '<p><strong>Estado:</strong> ' . esc_html(self::coverage_label((string)($brief['coverage']['status'] ?? 'uncovered'))) . ' · <strong>score:</strong> ' . esc_html(number_format_i18n((float)($brief['coverage']['score'] ?? 0)*100,0)) . '%</p>';
        echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>Destino</th><th>Tipo</th><th>Ámbito</th><th>Qué cubre</th><th>Similitud</th></tr></thead><tbody>';
        if (empty($brief['coverage']['matches'])) echo '<tr><td colspan="5">No existe una URL equivalente con cobertura suficiente.</td></tr>';
        foreach ((array)($brief['coverage']['matches'] ?? array()) as $match) {
            $url=(string)($match['url'] ?? '');
            echo '<tr><td>' . ($url!=='' ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html((string)($match['title'] ?? $url)) . '</a>' : esc_html((string)($match['title'] ?? ''))) . '</td>';
            echo '<td>' . esc_html((string)($match['entity_type'] ?? '')) . '</td><td>' . esc_html((string)($match['scope'] ?? '')) . '</td>';
            echo '<td>' . esc_html(wp_trim_words((string)($match['source_text'] ?? ''),30,'…')) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float)($match['score'] ?? 0)*100,0)) . '%</td></tr>';
        }
        echo '</tbody></table></div></section>';

        echo '<section><h3>4. Decisión editorial</h3>';
        echo '<p><strong>' . esc_html($brief['output']) . '</strong></p><p>' . esc_html($brief['decision_reason']) . '</p>';
        echo '<h4>Condiciones</h4>'; self::render_requirements($brief);
        self::render_workflow_form($topic);
        if ($entity_edit) echo '<p><a class="button" href="' . esc_url($entity_edit) . '">Abrir post existente</a></p>';
        if ($brief['action']==='CREATE_POST' && in_array($brief['workflow_state'],array('approved','brief_ready'),true) && !$brief['draft_post_id']) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_solucionador_topic"><input type="hidden" name="topic_id" value="' . esc_attr($brief['topic_id']) . '"><input type="hidden" name="topic_action" value="create_draft">';
            wp_nonce_field('seo_solucionador_topic_' . $brief['topic_id']);
            submit_button('Preparar borrador de post','primary','submit',false); echo '</form>';
        }
        echo '</section>';

        echo '<section><h3>5. Brief para Editora</h3>';
        echo '<p><strong>Título propuesto:</strong> ' . esc_html($brief['topic']) . '</p><p><strong>Preguntas que debe resolver:</strong></p><ul>';
        foreach ($brief['questions'] as $q) echo '<li>' . esc_html($q) . '</li>';
        echo '</ul>';
        if ($brief['preserve']) { echo '<h4>Contenido existente que no debe duplicarse</h4><ul>'; foreach ($brief['preserve'] as $item) echo '<li>' . esc_html($item) . '</li>'; echo '</ul>'; }
        if ($brief['links']) { echo '<h4>Enlaces internos recomendados</h4><ul>'; foreach ($brief['links'] as $link) echo '<li><a href="' . esc_url($link['url']) . '" target="_blank" rel="noopener">' . esc_html($link['label']) . '</a></li>'; echo '</ul>'; }
        echo '<p class="description"><strong>Editora redacta.</strong> Solucionador no fija el texto final ni publica automáticamente.</p></section>';

        echo '<section><h3>6. Workflow y medición</h3><p>Los KPIs globales proceden de Analista.</p>';
        if ($brief['analista']) echo '<p><strong>Analista · 28 días:</strong> impresiones ' . esc_html(number_format_i18n(absint($brief['analista']['impressions'] ?? 0))) . ' · clics ' . esc_html(number_format_i18n(absint($brief['analista']['clicks'] ?? 0))) . ' · vistas ' . esc_html(number_format_i18n(absint($brief['analista']['pageviews'] ?? 0))) . '.</p>';
        echo '<h4>Historial de workflow</h4><ul>';
        if (!$brief['workflow']) echo '<li>Sin cambios manuales registrados todavía.</li>';
        foreach ($brief['workflow'] as $row) echo '<li><strong>' . esc_html(self::workflow_label((string)($row['to_state'] ?? ''))) . '</strong> · ' . esc_html((string)($row['created_at'] ?? '')) . ' · ' . esc_html((string)($row['reason'] ?? '')) . '</li>';
        echo '</ul></section></div></div>';
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

        $actions = array('NO_ACTION','IMPROVE_POST','MERGE_CONTENT','CREATE_POST','DEFER');
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
        $e=SEO_Solucionador_DB::evidence_table();
        $counts=SEO_Solucionador_DB::table_exists($e)
            ? (array)$wpdb->get_results("SELECT source_type,SUM(occurrences) evidence FROM {$e} GROUP BY source_type ORDER BY evidence DESC",ARRAY_A)
            : array();

        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Fuentes y contratos</h2>';
        echo '<p><strong>Origen editorial único:</strong> Dependiente / Academia. Sólo preguntas activas cuyo último run está <code>answered</code> y <code>evaluation_status=pass_*</code>.</p>';
        echo '<p><strong>Demanda real:</strong> <code>seo_dependiente_search_log</code> queda separada y no origina temas.</p>';
        echo '<p><strong>Cobertura:</strong> <code>SEO_Editorial_Coverage</code> es la API neutral compartida.</p>';
        echo '<p><strong>Analista:</strong> mide resultados posteriores; no genera temas ni decisiones.</p>';
        echo '<p><strong>Ingeniero, Ojeador y Comparador:</strong> procesos editoriales independientes.</p>';
        self::render_service_snapshot();

        $academy=class_exists('SEO_Solucionador_Dossiers')?SEO_Solucionador_Dossiers::snapshot():array();
        echo '<h3>Academia · cobertura del conocimiento</h3><div class="seo-sol-grid">';
        self::card('Preguntas Academia',absint($academy['questions_total']??0),'Preguntas activas del currículo.');
        self::card('Procesadas',absint($academy['processed']??0),'Preguntas recorridas por el cursor.');
        self::card('Aprendidas',absint($academy['learned']??0),'Último run pass_*.');
        self::card('No aprendidas',absint($academy['not_learned']??0),'Sin pass_*.');
        self::card('Con categoría',absint($academy['learned_with_category']??0),'Pueden formar dossier.');
        self::card('Sin categoría',absint($academy['learned_without_category']??0),'No generan dossier ni URL.');
        self::card('Categorías con conocimiento',absint($academy['categories_with_knowledge']??0),'Un dossier por product_cat.');
        self::card('Categorías sin conocimiento',absint($academy['categories_without_knowledge']??0),'Sin dossier todavía.');
        self::card('Media preguntas / categoría',(float)($academy['avg_questions_per_category']??0),'Referencias por dossier.');
        echo '</div><p class="description">Escaneo ' . (!empty($academy['scan_complete'])?'<strong>completo</strong>':'<strong>en curso</strong>') . ' · cursor ' . esc_html(number_format_i18n(absint($academy['cursor']??0))) . ' · última ejecución Academia ' . esc_html((string)(($academy['last_run_at']??'') ?: '—')) . ' · errores aislados ' . esc_html(number_format_i18n(absint($academy['errors']??0))) . '.</p>';

        if($counts){ echo '<h3>Evidencia persistida</h3><ul>'; foreach($counts as $row) echo '<li><strong>' . esc_html((string)$row['source_type']) . ':</strong> ' . esc_html(number_format_i18n(absint($row['evidence']??0))) . '</li>'; echo '</ul>'; }
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
            'Dossiers Academia'=>SEO_Solucionador_DB::dossiers_table(),
            'Seguimiento histórico (compatibilidad)'=>SEO_Solucionador_DB::tracking_table(),
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

            if ($table === SEO_Solucionador_DB::dossiers_table()) {
                $rows = (array)$wpdb->get_results("SELECT id,category_id,category_name,question_count,score_avg,last_validated_at,source_hash,updated_at FROM {$table} ORDER BY question_count DESC,id DESC LIMIT 300",ARRAY_A);
                echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>ID</th><th>Categoría</th><th>Preguntas</th><th>Score</th><th>Última validación</th><th>Hash</th><th>Actualizado</th></tr></thead><tbody>';
                foreach ($rows as $row) echo '<tr><td>' . esc_html(absint($row['id'])) . '</td><td>#' . esc_html(absint($row['category_id'])) . ' · ' . esc_html((string)$row['category_name']) . '</td><td>' . esc_html(number_format_i18n(absint($row['question_count']))) . '</td><td>' . esc_html(number_format_i18n((float)$row['score_avg']*100,0)) . '%</td><td>' . esc_html((string)$row['last_validated_at']) . '</td><td><code>' . esc_html(substr((string)$row['source_hash'],0,16)) . '…</code></td><td>' . esc_html((string)$row['updated_at']) . '</td></tr>';
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
        echo '<span class="description" style="margin-left:10px">Incluye dossiers ligeros, decisiones, evidencias, cobertura y workflow. Los KPIs globales pertenecen a Analista.</span>';
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
