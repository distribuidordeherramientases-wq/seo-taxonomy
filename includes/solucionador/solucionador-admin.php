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
        add_action('admin_post_seo_solucionador_accept_all', array(__CLASS__, 'handle_accept_all'));
        add_action('admin_post_seo_solucionador_post_action', array(__CLASS__, 'handle_post_action'));
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
            'desc' => 'Prepara dossiers editoriales desde FAQs y conocimiento aprendido por Dependiente, crea borradores y muestra su rendimiento en Google.',
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
            'tab'=>'diagnostics',
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

        if ($proposal_action === 'save_item_states') {
            $raw_states = isset($_POST['item_states']) && is_array($_POST['item_states'])
                ? wp_unslash($_POST['item_states'])
                : array();
            $states = array();
            foreach ($raw_states as $key=>$state) {
                $states[sanitize_text_field((string)$key)] = sanitize_key((string)$state);
            }
            SEO_Solucionador_Dossiers::save_item_editorial_states($category_id,$states);
            self::redirect(array('sol_category'=>$category_id,'sol_msg'=>'item_states_saved'));
        }

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

        if ($proposal_action === 'save_title') {
            $title = sanitize_text_field(wp_unslash($_POST['proposal_title'] ?? ''));
            if ($title === '') {
                set_transient('seo_solucionador_notice_' . get_current_user_id(), 'El título propuesto no puede quedar vacío.', 90);
                self::redirect(array('sol_error'=>'title_required'));
            }
            SEO_Solucionador_DB::update_topic($topic_id,array('suggested_title'=>$title));
            self::redirect(array('sol_msg'=>'title_saved'));
        }

        if ($proposal_action === 'mark_reviewed') {
            SEO_Solucionador_Dossiers::mark_reviewed($category_id);
            self::redirect(array('sol_msg'=>'reviewed'));
        }

        if ($proposal_action === 'create_draft') {
            SEO_Solucionador_DB::update_topic($topic_id, array(
                'status'=>'approved',
                'workflow_state'=>'approved',
            ));
            SEO_Solucionador_DB::record_workflow(
                $topic_id,
                'approved',
                'La Editora acepta preparar un borrador. Los indicadores automáticos se conservan como contexto, no como bloqueos.',
                'CREATE_POST'
            );
            $result = SEO_Solucionador_Posts::create_draft($topic_id, true);
            if (is_wp_error($result)) {
                set_transient('seo_solucionador_notice_' . get_current_user_id(), $result->get_error_message(), 90);
                self::redirect(array('sol_error'=>'create_draft'));
            }
            self::redirect(array('sol_msg'=>'draft_created','post_id'=>absint($result)));
        }

        if ($proposal_action === 'reject') {
            if (class_exists('SEO_Solucionador_Dossiers')) {
                SEO_Solucionador_Dossiers::mark_rejected($category_id);
            }
            SEO_Solucionador_DB::update_topic($topic_id,array(
                'status'=>'dismissed',
                'workflow_state'=>'rejected',
            ));
            SEO_Solucionador_DB::record_workflow(
                $topic_id,
                'rejected',
                'La Editora rechaza esta propuesta para el source_hash actual. Reaparecerá si cambia el material fuente.',
                'NO_ACTION'
            );
            self::redirect(array('sol_msg'=>'rejected'));
        }

        if ($proposal_action === 'delete_proposal') {
            if (class_exists('SEO_Solucionador_Dossiers')) {
                SEO_Solucionador_Dossiers::clear_rejection($category_id);
            }
            SEO_Solucionador_DB::delete_topic($topic_id);
            self::redirect(array('sol_msg'=>'proposal_deleted'));
        }

        if ($proposal_action === 'restore') {
            if (class_exists('SEO_Solucionador_Dossiers')) SEO_Solucionador_Dossiers::clear_rejection($category_id);
            SEO_Solucionador_DB::update_topic($topic_id, array(
                'status'=>'candidate',
                'workflow_state'=>'candidate',
            ));
            SEO_Solucionador_DB::record_workflow(
                $topic_id,
                'candidate',
                'Propuesta recuperada para volver a evaluarse con las reglas actuales.',
                (string) ($topic['recommended_action'] ?? 'DEFER')
            );
            self::redirect(array('sol_msg'=>'restored'));
        }

        set_transient('seo_solucionador_notice_' . get_current_user_id(), 'Acción no reconocida.', 90);
        self::redirect(array('sol_error'=>'invalid_action'));
    }

    public static function handle_accept_all() {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para gestionar Solucionador.');
        }
        check_admin_referer('seo_solucionador_accept_all');
        set_transient(
            'seo_solucionador_notice_' . get_current_user_id(),
            'Aceptar todo está desactivado en Solucionador 0.7.1: cada dossier debe pasar por revisión editorial antes de crear un borrador.',
            90
        );
        self::redirect(array('sol_error'=>'bulk_review_required'));
    }

    public static function handle_post_action() {
        if (!current_user_can('manage_options')) wp_die('No tienes permisos para gestionar Solucionador.');

        $post_id = absint($_POST['post_id'] ?? 0);
        $post_action = sanitize_key((string) ($_POST['post_action'] ?? ''));
        if (!$post_id) {
            set_transient('seo_solucionador_notice_' . get_current_user_id(), 'No se ha indicado el post.', 90);
            self::redirect(array('sol_error'=>'missing_post'));
        }

        check_admin_referer('seo_solucionador_post_action_' . $post_id);

        $post = get_post($post_id);
        $category_id = absint(get_post_meta($post_id, SEO_Solucionador_Posts::META_DOSSIER_CATEGORY_ID, true));
        if (!$post instanceof WP_Post || $post->post_type !== 'post' || !$category_id) {
            set_transient('seo_solucionador_notice_' . get_current_user_id(), 'El post no pertenece a Solucionador.', 90);
            self::redirect(array('sol_error'=>'invalid_post'));
        }

        $return_to = sanitize_key((string) ($_POST['return_to'] ?? ''));
        $return_editor = static function($post_id, $state = '') {
            $url = SEO_Solucionador_Posts::edit_url($post_id);
            if ($state !== '') $url = add_query_arg('sol_update', sanitize_key((string)$state), $url);
            wp_safe_redirect($url);
            exit;
        };

        if ($post_action === 'rescan') {
            $result = SEO_Solucionador_Posts::refresh_pending_for_post($post_id);
            if (is_wp_error($result)) {
                set_transient('seo_solucionador_notice_' . get_current_user_id(), $result->get_error_message(), 90);
                if ($return_to === 'editor') $return_editor($post_id,'error');
                self::redirect(array('sol_error'=>'post_rescan'));
            }
            if ($return_to === 'editor') $return_editor($post_id,'rescanned');
            self::redirect(array('sol_msg'=>'post_rescanned','post_id'=>$post_id,'pending'=>absint($result)));
        }

        if ($post_action === 'save_selection') {
            $selected_keys = isset($_POST['selected_keys']) && is_array($_POST['selected_keys'])
                ? array_map('sanitize_text_field', wp_unslash($_POST['selected_keys']))
                : array();

            // Compatibilidad con formularios 0.6 que todavía envíen qids.
            if (!$selected_keys && isset($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
                foreach (array_map('absint',wp_unslash($_POST['selected_ids'])) as $question_id) {
                    if ($question_id) $selected_keys[] = 'dependiente:' . $question_id;
                }
            }

            $pending_keys = SEO_Solucionador_Posts::pending_item_keys($post_id);
            $selection_mode = sanitize_key((string)($_POST['selection_mode'] ?? ''));
            $reviewed_keys = $selection_mode === 'discard_unselected'
                ? array_values(array_diff($pending_keys, $selected_keys))
                : array();

            $result = SEO_Solucionador_Posts::apply_pending_selection($post_id,$selected_keys,$reviewed_keys);
            if (is_wp_error($result)) {
                set_transient('seo_solucionador_notice_' . get_current_user_id(), $result->get_error_message(), 90);
                if ($return_to === 'editor') $return_editor($post_id,'error');
                self::redirect(array('sol_error'=>'post_selection'));
            }
            if ($return_to === 'editor') $return_editor($post_id,'selection_saved');
            self::redirect(array('sol_msg'=>'selection_saved','post_id'=>$post_id));
        }

        if ($post_action === 'mark_reviewed') {
            $result = SEO_Solucionador_Posts::mark_pending_reviewed($post_id);
            if (is_wp_error($result)) {
                set_transient('seo_solucionador_notice_' . get_current_user_id(), $result->get_error_message(), 90);
                if ($return_to === 'editor') $return_editor($post_id,'error');
                self::redirect(array('sol_error'=>'post_review'));
            }
            if ($return_to === 'editor') $return_editor($post_id,'reviewed');
            self::redirect(array('sol_msg'=>'post_reviewed','post_id'=>$post_id));
        }

        if ($post_action === 'to_draft') {
            $result = wp_update_post(array(
                'ID'=>$post_id,
                'post_status'=>'draft',
            ), true);
            if (is_wp_error($result)) {
                set_transient('seo_solucionador_notice_' . get_current_user_id(), $result->get_error_message(), 90);
                self::redirect(array('sol_error'=>'post_to_draft'));
            }
            self::redirect(array('sol_msg'=>'post_to_draft','post_id'=>$post_id));
        }

        if ($post_action === 'delete') {
            $deleted = wp_delete_post($post_id, true);
            if (!$deleted) {
                set_transient('seo_solucionador_notice_' . get_current_user_id(), 'No se pudo borrar el post.', 90);
                self::redirect(array('sol_error'=>'post_delete'));
            }
            self::redirect(array('sol_msg'=>'post_deleted'));
        }

        set_transient('seo_solucionador_notice_' . get_current_user_id(), 'Acción de post no reconocida.', 90);
        self::redirect(array('sol_error'=>'invalid_post_action'));
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
            'google' => 'Visitas Google',
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
        if (class_exists('SEO_Solucionador_Engine')) {
            // Si Dependiente/Academia tiene conocimiento pero Solucionador aún
            // no ha construido ningún dossier, procesa un primer lote ahora.
            // Así PRO no queda bloqueado en 0 esperando exclusivamente a WP-Cron.
            $bootstrap = SEO_Solucionador_Engine::bootstrap_if_empty(500);
            if (is_wp_error($bootstrap)) {
                set_transient(
                    'seo_solucionador_notice_' . get_current_user_id(),
                    $bootstrap->get_error_message(),
                    90
                );
            }
            SEO_Solucionador_Engine::kick_automatic_refresh();
        }

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'summary';
        if (!in_array($tab, array('summary','diagnostics','google'), true)) $tab = 'summary';

        echo '<div class="wrap seo-solucionador"><h1>Solucionador</h1>';
        echo '<p>Organiza dos fuentes editoriales independientes —FAQ humana y conocimiento real de Dependiente— por product_cat y las entrega a Editora. Nunca publica automáticamente.</p>';

        if (!empty($_GET['sol_msg']) && $_GET['sol_msg'] === 'draft_created') {
            $post_id = absint($_GET['post_id'] ?? 0);
            echo '<div class="notice notice-success is-dismissible"><p>Post convertido en borrador.';
            if ($post_id) {
                echo ' <a href="' . esc_url(SEO_Solucionador_Posts::edit_url($post_id)) . '"><strong>Abrir borrador #' . esc_html($post_id) . '</strong></a>';
            }
            echo '</p></div>';
        }
        if (!empty($_GET['sol_msg']) && $_GET['sol_msg'] === 'bulk_done') {
            $created = absint($_GET['created'] ?? 0);
            $skipped = absint($_GET['skipped'] ?? 0);
            $errors = absint($_GET['errors'] ?? 0);
            $class = $errors > 0 ? 'notice notice-warning is-dismissible' : 'notice notice-success is-dismissible';
            echo '<div class="' . esc_attr($class) . '"><p><strong>Aceptar todo completado.</strong> '
                . esc_html(number_format_i18n($created)) . ' borradores creados, '
                . esc_html(number_format_i18n($skipped)) . ' omitidos y '
                . esc_html(number_format_i18n($errors)) . ' errores.</p></div>';
        }
        if (!empty($_GET['sol_error'])) {
            $msg = get_transient('seo_solucionador_notice_' . get_current_user_id());
            delete_transient('seo_solucionador_notice_' . get_current_user_id());
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg ?: 'No se pudo completar la acción.') . '</p></div>';
        }

        self::tabs($tab);
        if ($tab === 'diagnostics') self::render_diagnostics_simple();
        elseif ($tab === 'google') self::render_google_simple();
        else self::render_summary_simple();

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
        return $name !== '' ? 'Guía práctica de ' . $name . ': elección, uso y errores habituales' : 'Post propuesto';
    }

    private static function simple_proposal_state(array $topic) {
        $post_id = absint($topic['draft_post_id'] ?? 0);
        if ($post_id) {
            $status = get_post_status($post_id);
            $pending = method_exists('SEO_Solucionador_Posts','pending_question_count')
                ? SEO_Solucionador_Posts::pending_question_count($post_id)
                : 0;
            if ($status === 'publish') {
                return array(
                    'label'=>$pending > 0 ? 'Publicado · novedades (' . number_format_i18n($pending) . ')' : 'Publicado',
                    'post_id'=>$post_id,
                    'status'=>'publish',
                    'pending'=>$pending,
                );
            }
            if ($status && $status !== 'trash') {
                return array(
                    'label'=>$pending > 0 ? 'Borrador · novedades (' . number_format_i18n($pending) . ')' : 'Borrador',
                    'post_id'=>$post_id,
                    'status'=>'draft',
                    'pending'=>$pending,
                );
            }
        }
        return array('label'=>'Pendiente','post_id'=>0,'status'=>'pending','pending'=>0);
    }

    private static function simple_counts() {
        global $wpdb;

        $dossiers = SEO_Solucionador_DB::dossiers_table();
        $total = SEO_Solucionador_DB::table_exists($dossiers)
            ? absint($wpdb->get_var("SELECT COUNT(*) FROM {$dossiers} WHERE (rejected_source_hash='' OR rejected_source_hash<>source_hash)"))
            : 0;
        $with_material = SEO_Solucionador_DB::table_exists($dossiers)
            ? absint($wpdb->get_var("SELECT COUNT(*) FROM {$dossiers} WHERE question_count>0 AND (rejected_source_hash='' OR rejected_source_hash<>source_hash)"))
            : 0;

        $category_meta = SEO_Solucionador_Posts::META_DOSSIER_CATEGORY_ID;
        $drafts = absint($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
             WHERE p.post_type='post' AND p.post_status<>'publish' AND p.post_status<>'trash' AND pm.meta_key=%s",
            $category_meta
        )));
        $published = absint($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
             WHERE p.post_type='post' AND p.post_status='publish' AND pm.meta_key=%s",
            $category_meta
        )));
        $converted_categories = absint($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT CAST(pm.meta_value AS UNSIGNED))
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
             WHERE p.post_type='post' AND p.post_status<>'trash' AND pm.meta_key=%s",
            $category_meta
        )));

        $needs_update = SEO_Solucionador_DB::table_exists($dossiers)
            ? absint($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$dossiers} WHERE editorial_status=%s",
                SEO_Editorial_Service_Contract::NEEDS_UPDATE
            )))
            : 0;

        return array(
            'proposed'=>max(0,$with_material-min($converted_categories,$with_material)),
            'with_material'=>$with_material,
            'drafts'=>$drafts,
            'needs_update'=>$needs_update,
            'published'=>$published,
            'total'=>$total,
        );
    }

    private static function render_global_actions() {
        echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:14px 0 4px">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block">';
        echo '<input type="hidden" name="action" value="seo_solucionador_export_json">';
        wp_nonce_field('seo_solucionador_export_json');
        echo '<button type="submit" class="button">Descargar JSON</button>';
        echo '</form>';
        echo '<span class="description">Cada dossier requiere revisión editorial individual antes de crear un borrador.</span>';
        echo '</div>';
    }

    private static function render_summary_simple() {
        $counts = self::simple_counts();
        $academy = class_exists('SEO_Solucionador_Dossiers') ? SEO_Solucionador_Dossiers::snapshot() : array();

        echo '<div class="seo-sol-grid">';
        self::card('FAQs totales',absint($academy['faqs_all_total'] ?? $academy['faqs_total'] ?? 0),'Inventario histórico de seo_faq.');
        self::card('FAQs activas',absint($academy['faqs_total'] ?? 0),'Pregunta + respuesta humana; entrada editorial directa.');
        self::card('FAQs procesadas',absint($academy['faq_processed'] ?? 0),'Carril FAQ.');
        self::card('FAQs con categoría',absint($academy['faq_with_category'] ?? 0),'Asociación demostrable a product_cat.');
        self::card('FAQs sin categoría',absint($academy['faq_without_category'] ?? 0),'No generan propuesta hasta resolver product_cat.');
        self::card('Dependiente procesado',absint($academy['dependiente_processed'] ?? 0),'Preguntas de Entrenador revisadas contra su último run.');
        self::card('Dependiente útil',absint($academy['dependiente_editorial_eligible'] ?? 0),'Material candidato editorial.');
        self::card('Dependiente descartado',absint($academy['dependiente_editorial_discarded'] ?? 0),'Ruido de entrenamiento; no se borra de Dependiente.');
        self::card('Dependiente con categoría',absint($academy['dependiente_with_category'] ?? 0),'Asociación canónica demostrable.');
        self::card('Dependiente sin categoría',absint($academy['dependiente_without_category'] ?? 0),'No genera propuesta.');
        self::card('Sólo FAQ',absint($academy['categories_only_faq'] ?? 0),'Categorías con FAQ y sin material Dependiente.');
        self::card('Sólo Dependiente',absint($academy['categories_only_dependiente'] ?? 0),'Categorías sin FAQ y con material Dependiente.');
        self::card('FAQ + Dependiente',absint($academy['categories_faq_dependiente'] ?? 0),'Ambas fuentes conviven sin deduplicación destructiva.');
        self::card('Sin información',absint($academy['categories_without_information'] ?? 0),'Sin material editorial asociado.');
        self::card('Propuestas',$counts['proposed'],'Dossiers todavía no convertidos en post.');
        self::card('Borradores',$counts['drafts'],'Pendientes de edición/publicación humana.');
        self::card('NEEDS_UPDATE',$counts['needs_update'],'Fuentes cambiaron desde la última revisión.');
        self::card('Publicados',$counts['published'],'El contenido público sólo cambia tras revisión humana.');
        echo '</div>';
        self::render_global_actions();

        echo '<div class="postbox" style="padding:18px;margin-top:18px">';
        echo '<h2 style="margin-top:0">Estado</h2>';
        $categories_total = absint($academy['categories_total'] ?? 0);
        $with_knowledge = absint($academy['categories_with_knowledge'] ?? 0);
        $without_knowledge = max(0, $categories_total - $with_knowledge);
        echo '<p><strong>' . esc_html(number_format_i18n($categories_total)) . '</strong> categorías de producto están inventariadas en Solucionador. '
            . '<strong>' . esc_html(number_format_i18n($with_knowledge)) . '</strong> tienen preguntas editoriales útiles y '
            . '<strong>' . esc_html(number_format_i18n($without_knowledge)) . '</strong> están esperando conocimiento útil.</p>';
        echo '<p class="description">El escaneo puede seguir avanzando mientras la Editora revisa propuestas ya disponibles. No existe un umbral de masa que bloquee la revisión.</p>';
        echo '<p class="description">Dependiente útil: <strong>' . esc_html(number_format_i18n(absint($academy['dependiente_editorial_eligible'] ?? 0))) . '</strong> · '
            . 'ruido de entrenamiento no candidato: <strong>' . esc_html(number_format_i18n(absint($academy['dependiente_editorial_discarded'] ?? 0))) . '</strong>. '
            . 'Las FAQs activas correctamente relacionadas son material editorial válido por defecto.</p>';
        if (!empty($academy['updated_at'])) {
            echo '<p class="description">Conocimiento sincronizado automáticamente. Última actualización interna: ' . esc_html((string) $academy['updated_at']) . '.</p>';
        } else {
            echo '<p class="description">La sincronización de FAQ y Dependiente se ejecuta automáticamente en segundo plano.</p>';
        }
        echo '<p><a class="button button-primary" href="' . esc_url(self::url('diagnostics')) . '">Ver diagnóstico editorial</a> ';
        echo '<a class="button" href="' . esc_url(self::url('google')) . '">Ver visitas Google</a></p>';
        echo '</div>';
    }

    private static function proposal_action_form($category_id, $title = '') {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">';
        echo '<input type="hidden" name="action" value="seo_solucionador_proposal">';
        echo '<input type="hidden" name="category_id" value="' . esc_attr(absint($category_id)) . '">';
        wp_nonce_field('seo_solucionador_proposal_' . absint($category_id));
        echo '<input type="text" name="proposal_title" value="' . esc_attr((string)$title) . '" style="min-width:280px" aria-label="Título propuesto">';
        echo '<button type="submit" class="button" name="proposal_action" value="save_title">Guardar título</button>';
        echo '<button type="submit" class="button button-primary" name="proposal_action" value="create_draft">Crear borrador</button>';
        echo '<button type="submit" class="button" name="proposal_action" value="mark_reviewed">Marcar revisado</button>';
        echo '<button type="submit" class="button" name="proposal_action" value="reject">Rechazar</button>';
        echo '<button type="submit" class="button button-link-delete" name="proposal_action" value="delete_proposal" onclick="return confirm(\'¿Borrar esta propuesta preparada? El dossier fuente seguirá existiendo y podrá regenerarse.\');">Borrar propuesta</button>';
        echo '</form>';
    }

    private static function managed_post_action_form($post_id, $status) {
        $post_id = absint($post_id);
        if (!$post_id) return;

        $pending = method_exists('SEO_Solucionador_Posts','pending_question_count')
            ? SEO_Solucionador_Posts::pending_question_count($post_id)
            : 0;

        if ($pending > 0) {
            echo '<a class="button button-small button-primary" style="margin-left:6px" href="' . esc_url(SEO_Solucionador_Posts::edit_url($post_id)) . '">Revisar novedades (' . esc_html(number_format_i18n($pending)) . ')</a>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-flex;gap:6px;align-items:center;margin-left:6px">';
        echo '<input type="hidden" name="action" value="seo_solucionador_post_action">';
        echo '<input type="hidden" name="post_id" value="' . esc_attr($post_id) . '">';
        wp_nonce_field('seo_solucionador_post_action_' . $post_id);

        echo '<button type="submit" class="button button-small" name="post_action" value="rescan">Reescanear</button>';

        echo '<button type="submit" class="button button-small button-link-delete" name="post_action" value="delete" onclick="return confirm(\'¿Borrar definitivamente este post? Esta acción no equivale a rechazar la propuesta editorial.\');">Borrar</button>';
        echo '</form>';
    }

    private static function render_dossier_proposal_simple($category_id) {
        $category_id = absint($category_id);
        $dossier = SEO_Solucionador_Dossiers::get_by_category($category_id);
        if (!$dossier) {
            echo '<div class="notice notice-error inline"><p>No existe el dossier solicitado.</p></div>';
            return;
        }

        $topic = SEO_Solucionador_Engine::prepare_category_topic($category_id);
        if (is_wp_error($topic)) $topic = array();

        $term = get_term($category_id,'product_cat');
        $category_name = ($term && !is_wp_error($term))
            ? (string)$term->name
            : (string)($dossier['category_name'] ?? ('Categoría #' . $category_id));
        $title = self::simple_proposal_title($dossier,$topic);
        $details = SEO_Solucionador_Dossiers::question_details($category_id);
        $states = SEO_Solucionador_Dossiers::item_editorial_states($category_id);
        $changes = SEO_Solucionador_Dossiers::item_changes($category_id);

        $groups = array('faq'=>array(),'dependiente'=>array());
        foreach ($details as $item) {
            $origin = sanitize_key((string)($item['origin'] ?? 'dependiente'));
            if (isset($groups[$origin])) $groups[$origin][] = $item;
        }

        echo '<p><a class="button" href="' . esc_url(self::url('diagnostics')) . '">← Volver a propuestas</a></p>';
        echo '<div class="postbox" style="padding:18px;margin-top:12px">';
        echo '<h2 style="margin-top:0">Guía propuesta · ' . esc_html($category_name) . '</h2>';
        echo '<p><strong>Categoría:</strong> ' . esc_html($category_name) . ' <code>#' . esc_html($category_id) . '</code></p>';
        echo '<p><strong>Título propuesto:</strong> ' . esc_html($title) . '</p>';
        if ($topic) {
            echo '<p><strong>Recomendación:</strong> ' . esc_html(self::action_label((string)($topic['recommended_action'] ?? ''))) . ' · ' . esc_html((string)($topic['decision_reason'] ?? '')) . '</p>';
        }
        echo '<p><strong>Material:</strong> FAQ ' . esc_html(number_format_i18n(absint($dossier['faq_count'] ?? 0)))
            . ' · Dependiente ' . esc_html(number_format_i18n(absint($dossier['dependiente_count'] ?? 0)))
            . ' · <strong>Cambios:</strong> NUEVO ' . esc_html(number_format_i18n(count((array)$changes['new'])))
            . ' · MODIFICADO ' . esc_html(number_format_i18n(count((array)$changes['modified'])))
            . ' · RETIRADO ' . esc_html(number_format_i18n(count((array)$changes['retired']))) . '.</p>';
        echo '<p class="description">FAQ y Dependiente se mantienen separados. No se deduplican automáticamente; Editora decide qué usar.</p>';
        echo '</div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="seo_solucionador_proposal">';
        echo '<input type="hidden" name="category_id" value="' . esc_attr($category_id) . '">';
        echo '<input type="hidden" name="proposal_action" value="save_item_states">';
        wp_nonce_field('seo_solucionador_proposal_' . $category_id);

        $section_labels = array('faq'=>'FAQs','dependiente'=>'DEPENDIENTE');
        foreach ($section_labels as $origin=>$label) {
            echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">' . esc_html($label) . '</h2>';
            if (!$groups[$origin]) {
                echo '<p class="description">Sin elementos de esta fuente.</p></div>';
                continue;
            }
            echo '<table class="widefat striped"><thead><tr><th style="width:120px">Decisión</th><th style="width:105px">Estado</th><th>Pregunta / conocimiento</th><th>Respuesta</th><th style="width:220px">Trazabilidad</th></tr></thead><tbody>';
            foreach ($groups[$origin] as $item) {
                $key = sanitize_text_field((string)($item['item_key'] ?? ''));
                if ($key === '') continue;
                $choice = sanitize_key((string)($states[$key] ?? 'pending'));
                $change = sanitize_key((string)($item['editorial_state'] ?? 'unchanged'));
                $answer = SEO_Solucionador_Dossiers::answer_text((array)$item);
                $trace = array();
                if ($origin === 'faq') {
                    $trace[] = 'FAQ #' . absint($item['faq_id'] ?? 0);
                    if (!empty($item['product_id'])) $trace[] = 'producto #' . absint($item['product_id']);
                } else {
                    $trace[] = 'Dependiente';
                    if (!empty($item['dependiente_source'])) $trace[] = sanitize_key((string)$item['dependiente_source']);
                    if (!empty($item['lesson_key'])) $trace[] = 'lección ' . sanitize_key((string)$item['lesson_key']);
                    if (!empty($item['evaluation_status'])) $trace[] = sanitize_key((string)$item['evaluation_status']);
                    if (isset($item['evaluation_score'])) $trace[] = 'score ' . number_format_i18n((float)$item['evaluation_score']*100,0) . '%';
                }

                echo '<tr>';
                echo '<td><select name="item_states[' . esc_attr($key) . ']">';
                foreach (array('pending'=>'Pendiente','use'=>'Usar','discard'=>'Descartar') as $value=>$text) {
                    echo '<option value="' . esc_attr($value) . '" ' . selected($choice,$value,false) . '>' . esc_html($text) . '</option>';
                }
                echo '</select></td>';
                echo '<td><strong>' . esc_html(strtoupper($change)) . '</strong></td>';
                echo '<td><strong>' . esc_html((string)($item['question'] ?? '')) . '</strong><br><code>' . esc_html($key) . '</code></td>';
                echo '<td>' . esc_html($answer !== '' ? $answer : 'Sin respuesta legible almacenada.') . '</td>';
                echo '<td>' . esc_html(implode(' · ',$trace)) . '<br><span class="description">' . esc_html((string)($item['observed_at'] ?? '')) . '</span></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }

        if (!empty($changes['retired'])) {
            $retired_details = method_exists('SEO_Solucionador_Dossiers','retired_item_details')
                ? SEO_Solucionador_Dossiers::retired_item_details($category_id)
                : array();
            echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">RETIRADOS desde la última revisión</h2>';
            if ($retired_details) {
                echo '<table class="widefat striped"><thead><tr><th style="width:120px">Decisión</th><th>Origen</th><th>Elemento retirado</th><th>Última respuesta conocida</th></tr></thead><tbody>';
                foreach ($retired_details as $item) {
                    $key = sanitize_text_field((string)($item['item_key'] ?? ''));
                    if ($key === '') continue;
                    $choice = sanitize_key((string)($item['editorial_choice'] ?? ($states[$key] ?? 'pending')));
                    if (!in_array($choice,array('pending','use','discard'),true)) $choice = 'pending';

                    echo '<tr><td><select name="item_states[' . esc_attr($key) . ']">';
                    foreach (array('pending'=>'Pendiente','use'=>'Usar','discard'=>'Descartar') as $value=>$text) {
                        echo '<option value="' . esc_attr($value) . '" ' . selected($choice,$value,false) . '>' . esc_html($text) . '</option>';
                    }
                    echo '</select></td>';
                    echo '<td><strong>' . esc_html(strtoupper((string)($item['origin'] ?? ''))) . '</strong></td>';
                    echo '<td><strong>' . esc_html((string)($item['question'] ?? 'Elemento retirado')) . '</strong><br><code>' . esc_html($key) . '</code></td>';
                    echo '<td>' . esc_html((string)($item['answer'] ?? '')) . '</td></tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<ul>';
                foreach ((array)$changes['retired'] as $key) echo '<li><code>' . esc_html((string)$key) . '</code></li>';
                echo '</ul>';
            }
            echo '<p class="description">La decisión sólo se guarda en Solucionador. No restaura la fuente ni modifica el contenido publicado; Editora decide después si debe retirar o mantener esa información.</p></div>';
        }

        submit_button('Guardar decisiones editoriales','primary');
        echo '</form>';

        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Acciones de propuesta</h2>';
        self::proposal_action_form($category_id,$title);
        echo '</div>';
    }

    private static function render_diagnostics_simple() {
        global $wpdb;
        $table = SEO_Solucionador_DB::dossiers_table();

        $detail_category = isset($_GET['sol_category']) ? absint(wp_unslash($_GET['sol_category'])) : 0;
        if ($detail_category) {
            self::render_dossier_proposal_simple($detail_category);
            return;
        }
        if (!SEO_Solucionador_DB::table_exists($table)) {
            echo '<div class="postbox" style="padding:18px;margin-top:18px"><p>No hay propuestas todavía. Solucionador está sincronizando el conocimiento automáticamente.</p></div>';
            return;
        }

        $per_page = 50;
        $page = max(1, absint($_GET['sol_page'] ?? 1));
        $total = absint($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE (rejected_source_hash='' OR rejected_source_hash<>source_hash)"));
        $pages = max(1, (int) ceil($total / $per_page));
        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * $per_page;

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE (rejected_source_hash='' OR rejected_source_hash<>source_hash) ORDER BY category_name ASC,id ASC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        echo '<div class="postbox" style="padding:18px;margin-top:18px">';
        echo '<h2 style="margin-top:0">Diagnóstico editorial</h2>';
        echo '<p>Solucionador inventaría las categorías y muestra cualquier dossier con material útil y categoría demostrable. Calidad, cobertura y duplicación son indicadores; la decisión de crear o actualizar contenido corresponde a la Editora.</p>';
        $academy = class_exists('SEO_Solucionador_Dossiers') ? SEO_Solucionador_Dossiers::snapshot() : array();
        self::render_global_actions();
        echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>Categoría / título</th><th>Material</th><th>Validación</th><th>Novedades</th><th>Estado</th><th>Acción</th></tr></thead><tbody>';

        if (!$rows) {
            echo '<tr><td colspan="6">No hay propuestas disponibles.</td></tr>';
        }

        foreach ($rows as $dossier) {
            $category_id = absint($dossier['category_id'] ?? 0);
            if (!$category_id) continue;
            $topic = self::simple_topic_for_category($category_id);
            $state = self::simple_proposal_state($topic);
            $question_count = absint($dossier['question_count'] ?? 0);
            if ($state['status'] === 'pending' && $question_count <= 0) {
                $state = array('label'=>'Sin material útil','post_id'=>0,'status'=>'empty');
            }
            $title = self::simple_proposal_title($dossier,$topic);

            $changes = method_exists('SEO_Solucionador_Dossiers','item_changes')
                ? SEO_Solucionador_Dossiers::item_changes($category_id)
                : array('new'=>array(),'modified'=>array(),'retired'=>array());
            $changed = count((array)$changes['new']) + count((array)$changes['modified']) + count((array)$changes['retired']);
            $editorial_status = (string)($dossier['editorial_status'] ?? SEO_Editorial_Service_Contract::READY_FOR_REVIEW);
            if ($state['status'] === 'pending' && $editorial_status === SEO_Editorial_Service_Contract::REJECTED) {
                $state['label'] = 'Rechazado';
            }

            echo '<tr>';
            echo '<td><strong>' . esc_html((string) ($dossier['category_name'] ?? '')) . '</strong><br><span class="description">' . esc_html($title) . '</span></td>';
            $faq_count=absint($dossier['faq_count'] ?? 0);
            $dependiente_count=absint($dossier['dependiente_count'] ?? 0);
            echo '<td><strong>' . esc_html(number_format_i18n($question_count)) . '</strong> elementos<br><span class="description">FAQ ' . esc_html(number_format_i18n($faq_count)) . ' · Dependiente ' . esc_html(number_format_i18n($dependiente_count)) . '</span></td>';
            echo '<td>' . esc_html(number_format_i18n(round((float)($dossier['score_avg'] ?? 0) * 100))) . '%<br><span class="description">' . esc_html((string)($dossier['last_validated_at'] ?? '')) . '</span></td>';
            echo '<td><strong>' . esc_html(number_format_i18n($changed)) . '</strong><br><span class="description">N ' . esc_html(number_format_i18n(count((array)$changes['new']))) . ' · M ' . esc_html(number_format_i18n(count((array)$changes['modified']))) . ' · R ' . esc_html(number_format_i18n(count((array)$changes['retired']))) . '</span></td>';
            echo '<td><strong>' . esc_html($editorial_status) . '</strong><br><span class="description">' . esc_html($state['label']) . '</span></td>';
            echo '<td>';
            echo '<a class="button button-small" href="' . esc_url(self::url('diagnostics',array('sol_category'=>$category_id))) . '">Ver propuesta</a> ';
            if ($state['status'] === 'pending') {
                self::proposal_action_form($category_id,$title);
            } elseif ($state['status'] === 'empty') {
                echo '<span class="description">No se propone contenido hasta disponer de material útil asociado a esta categoría.</span>';
            } elseif (!empty($state['post_id'])) {
                echo '<a class="button button-small" href="' . esc_url(SEO_Solucionador_Posts::edit_url($state['post_id'])) . '">Abrir</a>';
                self::managed_post_action_form($state['post_id'], $state['status']);
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div></div>';

        if ($pages > 1) {
            echo '<div style="display:flex;justify-content:space-between;align-items:center;margin:18px 0">';
            echo '<span>Página ' . esc_html(number_format_i18n($page)) . ' de ' . esc_html(number_format_i18n($pages)) . '</span><span>';
            if ($page > 1) echo '<a class="button" href="' . esc_url(self::url('diagnostics',array('sol_page'=>$page-1))) . '">Anterior</a> ';
            if ($page < $pages) echo '<a class="button button-primary" href="' . esc_url(self::url('diagnostics',array('sol_page'=>$page+1))) . '">Siguiente</a>';
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

        $snapshot = function_exists('seo_post_reports_catalog_snapshot')
            ? (array) seo_post_reports_catalog_snapshot(28,false)
            : array();

        $meta_key = SEO_Solucionador_Posts::META_TOPIC_ID;
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT p.ID,p.post_title,p.post_status
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
                 WHERE p.post_type='post' AND p.post_status='publish' AND pm.meta_key=%s
                 ORDER BY p.post_date DESC,p.ID DESC
                 LIMIT 1000",
                $meta_key
            ),
            ARRAY_A
        );

        $totals = array('impressions'=>0,'clicks'=>0,'pageviews'=>0);
        foreach ($rows as $row) {
            $metrics = self::google_metrics_for_post(absint($row['ID'] ?? 0));
            $totals['impressions'] += absint($metrics['impressions'] ?? 0);
            $totals['clicks'] += absint($metrics['clicks'] ?? 0);
            $totals['pageviews'] += absint($metrics['pageviews'] ?? 0);
        }

        echo '<div class="seo-sol-grid">';
        self::card('Impresiones Google', $totals['impressions'], 'Search Console · últimos 28 días.');
        self::card('Clics desde Google', $totals['clicks'], 'Search Console · últimos 28 días.');
        self::card('Vistas', $totals['pageviews'], 'Google Analytics · últimos 28 días.');
        echo '</div>';

        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Visitas de los posts creados por Solucionador</h2>';
        if (!empty($snapshot['generated'])) {
            echo '<p class="description">Datos Google actualizados: ' . esc_html(wp_date('Y-m-d H:i:s', absint($snapshot['generated']))) . '.</p>';
        }
        if (!empty($snapshot['errors'])) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html(implode(' · ', array_map('sanitize_text_field',(array)$snapshot['errors']))) . '</p></div>';
        }

        echo '<table class="widefat striped"><thead><tr><th>Post</th><th>Estado</th><th>Impresiones</th><th>Clics Google</th><th>Vistas</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="5">Todavía no hay posts publicados creados por Solucionador.</td></tr>';
        foreach ($rows as $row) {
            $post_id = absint($row['ID'] ?? 0);
            $metrics = self::google_metrics_for_post($post_id);
            $has = !empty($metrics['has_snapshot']);
            $available = $has || !empty($snapshot['available']);
            echo '<tr><td><a href="' . esc_url(SEO_Solucionador_Posts::edit_url($post_id)) . '"><strong>' . esc_html((string) ($row['post_title'] ?? '')) . '</strong></a></td>';
            echo '<td>Publicado</td>';
            echo '<td>' . ($available ? esc_html(number_format_i18n(absint($metrics['impressions'] ?? 0))) : '—') . '</td>';
            echo '<td>' . ($available ? esc_html(number_format_i18n(absint($metrics['clicks'] ?? 0))) : '—') . '</td>';
            echo '<td>' . ($available ? esc_html(number_format_i18n(absint($metrics['pageviews'] ?? 0))) : '—') . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<div style="margin-top:16px"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="seo_solucionador_export_report_json">';
        wp_nonce_field('seo_solucionador_export_report_json');
        submit_button('Descargar informe JSON','secondary','submit',false);
        echo '</form></div></div>';
    }

    private static function render_summary() {
        $counts = self::counts();
        $last = get_option('seo_solucionador_last_scan', array());
        $academy = class_exists('SEO_Solucionador_Dossiers') ? SEO_Solucionador_Dossiers::snapshot() : array();

        echo '<div class="seo-sol-grid">';
        self::card('Dossiers con material',absint($academy['categories_with_knowledge'] ?? 0),'Un dossier por product_cat combinando FAQ + Dependiente.');
        self::card('FAQs activas',absint($academy['faqs_total'] ?? 0),'Fuente editorial directa; no depende del aprendizaje de Dependiente.');
        self::card('FAQs en dossiers',absint($academy['faq_in_dossiers'] ?? 0),'FAQs activas con product_cat demostrable.');
        self::card('Preguntas aprendidas',absint($academy['learned'] ?? 0),'Dependiente: último run answered con evaluation_status pass_*.');
        self::card('Dependiente en dossiers',absint($academy['dependiente_in_dossiers'] ?? 0),'Preguntas pass_* con valor editorial y product_cat.');
        self::card('Aprendidas sin categoría',absint($academy['learned_without_category'] ?? 0),'No crean dossier ni URL hasta disponer de product_cat demostrable.');
        self::card('CREATE_POST',$counts['create_post'] ?? 0,'Dossiers sin cobertura equivalente; la densidad de material es informativa.');
        self::card('IMPROVE_POST', $counts['improve'] ?? 0, 'Existe un post equivalente con cobertura débil/parcial.');
        self::card('MERGE_CONTENT', $counts['merge'] ?? 0, 'Existen piezas solapadas que conviene consolidar.');
        self::card('NO_ACTION', $counts['no_action'] ?? 0, 'La intención básica ya está suficientemente cubierta.');
        self::card('DEFER / REVIEW',$counts['deferred'] ?? 0,'Falta categoría demostrable o existe una situación que requiere revisión.');
        self::card('Borradores', $counts['drafts'] ?? 0, 'Posts de trabajo; Solucionador nunca publica automáticamente.');
        echo '</div>';

        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Arquitectura separada</h2>';
        echo '<p><strong>FAQ manual + Academia/Entrenador → dossier único por product_cat → Solucionador → Editora → post WordPress.</strong> Las dos entradas son independientes. Ingeniero y Comparador mantienen sus procesos propios. Solucionador no investiga, no compara mercado y no publica automáticamente.</p>';
        echo '<p><a class="button button-primary" href="' . esc_url(self::url('proposals')) . '">Abrir dossiers/propuestas</a> <a class="button" href="' . esc_url(self::url('coverage')) . '">Revisar cobertura compartida</a></p>';
        echo '</div>';

        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Procesar fuentes</h2>';
        echo '<p>FAQ y Academia usan cursores persistentes independientes. Cada ejecución continúa donde terminó la anterior; las propuestas ya disponibles pueden revisarse mientras el inventario sigue avanzando.</p>';
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
                . ' · aprendidas con categoría ' . esc_html(number_format_i18n(absint($academy['learned_with_category'] ?? 0)))
                . ' · <strong>FAQ:</strong> cursor ' . esc_html(number_format_i18n(absint($academy['faq_cursor'] ?? 0)))
                . ' · procesadas ' . esc_html(number_format_i18n(absint($academy['faq_processed'] ?? 0)))
                . ' · con categoría ' . esc_html(number_format_i18n(absint($academy['faq_with_category'] ?? 0)))
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

        $coverage_table = SEO_Solucionador_DB::coverage_table();
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Tabla interna controlada; consulta fija sin entrada de usuario.
        $coverage = SEO_Solucionador_DB::table_exists($coverage_table)
            ? absint($wpdb->get_var("SELECT COUNT(*) FROM {$coverage_table}"))
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
        echo '<h2 style="margin-top:0">Diagnóstico FAQ + Dependiente → Solucionador</h2>';
        echo '<p>Comprueba las dos entradas independientes, su resolución de product_cat, el dossier conjunto, cobertura y decisión editorial. Ingeniero y Comparador se diagnostican en sus propios procesos.</p>';
        echo '</div>';

        echo '<div class="seo-sol-grid">';
        self::card('FAQs activas',absint($academy['faqs_total']??0),'Fuente editorial directa.');
        self::card('FAQs en dossiers',absint($academy['faq_in_dossiers']??0),'Con product_cat demostrable.');
        self::card('Preguntas currículo',absint($academy['questions_total']??0),'Fuente de evaluación/aprendizaje de Dependiente.');
        self::card('Aprendidas pass_*',absint($academy['learned']??0),'Última ejecución respondida y validada.');
        self::card('Con product_cat',absint($academy['learned_with_category']??0),'Con asociación demostrable.');
        self::card('Sin product_cat',absint($academy['learned_without_category']??0),'No crean dossier ni URL.');
        self::card('Dossiers',absint($academy['categories_with_knowledge']??0),'Categorías con FAQ y/o conocimiento aprendido.');
        self::card('Sin material',absint($academy['categories_without_knowledge']??0),'Categorías WooCommerce sin material editorial.');
        self::card('Media / categoría',(float)($academy['avg_questions_per_category']??0),'Elementos FAQ + Dependiente por dossier.');
        self::card('Errores aislados',absint($academy['errors']??0),'No detienen el resto del lote.');
        echo '</div>';

        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h3 style="margin-top:0">Dossiers por categoría</h3>';
        if (!SEO_Solucionador_DB::table_exists($dossiers_table)) {
            echo '<p>La tabla de dossiers todavía no está disponible.</p></div>';
            return;
        }
        $rows=(array)$wpdb->get_results("SELECT id,category_id,category_name,question_count,dependiente_count,faq_count,score_avg,last_validated_at FROM {$dossiers_table} ORDER BY question_count DESC,category_name ASC LIMIT 500",ARRAY_A);
        echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>Categoría</th><th>Total</th><th>FAQ</th><th>Dependiente</th><th>Score Dependiente</th><th>Última actualización</th><th>Dossier</th></tr></thead><tbody>';
        if(!$rows) echo '<tr><td colspan="7">Aún no hay dossiers. Continúa el procesamiento de fuentes desde Resumen.</td></tr>';
        foreach($rows as $row){
            $term_id=absint($row['category_id']??0);
            echo '<tr><td><strong>' . esc_html((string)$row['category_name']) . '</strong><br><code>#' . esc_html($term_id) . '</code></td>';
            echo '<td>' . esc_html(number_format_i18n(absint($row['question_count']??0))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n(absint($row['faq_count']??0))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n(absint($row['dependiente_count']??0))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float)($row['score_avg']??0)*100,0)) . '%</td>';
            echo '<td>' . esc_html((string)($row['last_validated_at']??'')) . '</td>';
            echo '<td><a class="button button-small" href="' . esc_url(self::url('proposals',array('sol_category'=>$term_id))) . '">Ver propuesta</a></td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="description">Una categoría puede recibir CREATE_POST, IMPROVE_POST, NO_ACTION o DEFER como recomendación. La cobertura nunca oculta el dossier ni sustituye la decisión de Editora.</p>';
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
        $evidence = SEO_Solucionador_DB::get_evidence_rows($topic_id);

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

        $faq_items=array_values(array_filter((array)$brief['question_details'],static function($row){
            return sanitize_key((string)($row['origin'] ?? ''))==='faq';
        }));
        $dependiente_items=array_values(array_filter((array)$brief['question_details'],static function($row){
            return sanitize_key((string)($row['origin'] ?? 'dependiente'))==='dependiente';
        }));

        echo '<div class="seo-sol-grid" style="margin-top:14px">';
        self::card('Material',count($brief['question_details']),'FAQ ' . count($faq_items) . ' · Dependiente ' . count($dependiente_items) . '.');
        self::card('Acción',$brief['output'],$brief['decision_reason']);
        self::card('Cobertura',self::coverage_label((string)($brief['coverage']['status'] ?? 'uncovered')),'Similitud ' . number_format_i18n((float)($brief['coverage']['score'] ?? 0)*100,0) . '%.');
        self::card('Workflow',self::workflow_label($brief['workflow_state']),'La publicación sigue siendo humana.');
        echo '</div>';

        echo '<div class="seo-sol-opportunity-sections">';
        echo '<section><h3>1. Entrevista editorial: FAQ + Dependiente</h3>';
        echo '<p>Las FAQs son una fuente directa y no necesitan haber sido aprendidas por Dependiente. Las respuestas de Dependiente sólo aparecen cuando su run está validado <code>pass_*</code>.</p>';
        if (!$brief['question_details']) {
            echo '<p>No se ha recuperado material editorial para este dossier.</p>';
        } else {
            echo '<div class="seo-sol-table"><table class="widefat striped"><thead><tr><th>Origen</th><th>Pregunta</th><th>Respuesta / evidencia</th><th>Validación</th><th>Fecha</th></tr></thead><tbody>';
            foreach ($brief['question_details'] as $item) {
                $origin=sanitize_key((string)($item['origin'] ?? 'dependiente'));
                $origin_label=$origin==='faq' ? 'FAQ' : 'Dependiente';
                $answer=SEO_Solucionador_Dossiers::answer_text((array)$item);
                $evidence=array();
                if ($origin!=='faq') {
                    foreach (array_slice((array)($item['top_results'] ?? array()),0,3) as $result) {
                        if (!is_array($result)) continue;
                        $label=trim((string)($result['title'] ?? $result['name'] ?? $result['label'] ?? ''));
                        if ($label!=='') $evidence[]=$label;
                    }
                }
                echo '<tr>';
                echo '<td><strong>' . esc_html($origin_label) . '</strong>';
                if ($origin==='faq' && !empty($item['faq_id'])) echo '<br><code>#' . esc_html(absint($item['faq_id'])) . '</code>';
                echo '</td>';
                echo '<td><strong>' . esc_html((string)$item['question']) . '</strong></td>';
                echo '<td>' . esc_html($answer !== '' ? $answer : ($evidence ? wp_trim_words(implode(' · ',$evidence),40,'…') : 'Resultado interno disponible')) . '</td>';
                echo '<td>' . esc_html((string)($item['evaluation_status'] ?? '')) . ($origin==='dependiente' ? '<br>score ' . esc_html(number_format_i18n((float)($item['evaluation_score'] ?? 0)*100,0)) . '%' : '') . '</td>';
                echo '<td>' . esc_html((string)($item['observed_at'] ?? '')) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            echo '<p class="description"><strong>Regla:</strong> todo este material es fuente interna para Editora. El borrador debe revisarse y sintetizarse; Solucionador no publica automáticamente ninguna pregunta-respuesta.</p>';
        }
        echo '</section>';

        echo '<section><h3>2. Categoría y Vocabulary</h3>';
        $h=(array)$brief['hierarchy']; $cat=(array)($h['category'] ?? array());
        echo '<p><strong>product_cat principal:</strong> ' . (!empty($cat['id']) ? '#' . esc_html(absint($cat['id'])) . ' · ' . esc_html((string)($cat['name'] ?? '')) : '—') . '</p>';
        echo '<p><strong>Contrato:</strong> <code>post_to_category</code> · rol <code>dependiente_qa_basic</code>.</p>';
        echo '<p><strong>Vocabulary:</strong></p>' . wp_kses_post(self::vocab_chips($topic));
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
            echo '<td><strong>' . esc_html((string)(($row['suggested_title'] ?? '') ?: ($row['canonical_question'] ?? ''))) . '</strong><br><span class="description">' . esc_html($category_name) . ($term_id ? ' (#' . esc_html((string) $term_id) . ')' : '') . '</span><br><code>' . esc_html((string)($row['canonical_key'] ?? '')) . '</code></td>';
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
        if ($params) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Condiciones generadas internamente; valores externos enlazados mediante placeholders.
            $sql = $wpdb->prepare($sql,$params);
        }
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Sin parámetros es SQL interno fijo; con parámetros, $sql ya está preparado.
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
        echo '<p><strong>Orígenes editoriales:</strong> <code>FAQ</code> manual activa + <code>Dependiente/Academia</code>. Son independientes; las FAQs no necesitan superar ningún run de Dependiente.</p>';
        echo '<p><strong>Demanda real:</strong> <code>seo_dependiente_search_log</code> queda separada y no origina temas.</p>';
        echo '<p><strong>Cobertura:</strong> <code>SEO_Editorial_Coverage</code> es la API neutral compartida.</p>';
        echo '<p><strong>Analista:</strong> mide resultados posteriores; no genera temas ni decisiones.</p>';
        echo '<p><strong>Ingeniero, Ojeador y Comparador:</strong> procesos editoriales independientes.</p>';
        self::render_service_snapshot();

        $academy=class_exists('SEO_Solucionador_Dossiers')?SEO_Solucionador_Dossiers::snapshot():array();
        echo '<h3>FAQ + Dependiente · cobertura del material</h3><div class="seo-sol-grid">';
        self::card('FAQs activas',absint($academy['faqs_total']??0),'Fuente editorial directa.');
        self::card('FAQs procesadas',absint($academy['faq_processed']??0),'Recorridas por el cursor FAQ.');
        self::card('FAQs en dossier',absint($academy['faq_in_dossiers']??0),'Con product_cat demostrable.');
        self::card('Elementos Dependiente',absint($academy['questions_total']??0),'Preguntas activas del currículo.');
        self::card('Procesadas',absint($academy['processed']??0),'Preguntas recorridas por el cursor.');
        self::card('Aprendidas',absint($academy['learned']??0),'Último run pass_*.');
        self::card('No aprendidas',absint($academy['not_learned']??0),'Sin pass_*.');
        self::card('Con categoría',absint($academy['learned_with_category']??0),'Pueden formar dossier.');
        self::card('Sin categoría',absint($academy['learned_without_category']??0),'No generan dossier ni URL.');
        self::card('Categorías con conocimiento',absint($academy['categories_with_knowledge']??0),'Un dossier por product_cat.');
        self::card('Categorías sin conocimiento',absint($academy['categories_without_knowledge']??0),'Sin dossier todavía.');
        self::card('Media elementos / categoría',(float)($academy['avg_questions_per_category']??0),'FAQ + Dependiente por dossier.');
        echo '</div><p class="description">Escaneo mixto ' . (!empty($academy['scan_complete'])?'<strong>completo</strong>':'<strong>en curso</strong>') . ' · cursor Academia ' . esc_html(number_format_i18n(absint($academy['cursor']??0))) . ' · cursor FAQ ' . esc_html(number_format_i18n(absint($academy['faq_cursor']??0))) . ' · errores aislados ' . esc_html(number_format_i18n(absint($academy['errors']??0))) . '.</p>';

        if($counts){ echo '<h3>Evidencia persistida</h3><ul>'; foreach($counts as $row) echo '<li><strong>' . esc_html((string)$row['source_type']) . ':</strong> ' . esc_html(number_format_i18n(absint($row['evidence']??0))) . '</li>'; echo '</ul>'; }
        echo '</div>';
    }

    private static function render_tests() {
        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Tests · Arquitectura separada</h2>';
        echo '<p class="description">Regresiones sin publicación automática para comprobar dossier mixto FAQ + Dependiente, cobertura, decisiones, trazabilidad y rol <code>dependiente_qa_basic</code>.</p>';
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
            'Dossiers FAQ + Dependiente'=>SEO_Solucionador_DB::dossiers_table(),
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
