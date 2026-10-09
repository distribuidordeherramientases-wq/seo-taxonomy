<?php
/**
 * Comparador - interfaz editorial simple.
 *
 * Flujo:
 * Ojeador -> Comparador agrupa por categoria -> propuesta -> aceptar -> post draft.
 */

defined('ABSPATH') || exit;

final class SEO_Comparador_Admin {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_page'), 31);
        add_filter('seo_content_items', array(__CLASS__, 'content_card'), 31, 1);
        add_filter('parent_file', array(__CLASS__, 'parent_file'), 31, 1);
        add_filter('submenu_file', array(__CLASS__, 'submenu_file'), 31, 1);
        add_action('admin_post_seo_comparador_action', array(__CLASS__, 'handle_action'));
        add_action('admin_post_seo_comparador_accept_all', array(__CLASS__, 'handle_accept_all'));
    }

    public static function register_page() {
        add_submenu_page(
            null,
            'Comparador',
            'Comparador',
            'manage_options',
            'seo-comparador',
            array(__CLASS__, 'render')
        );
    }

    public static function content_card($items) {
        $items = is_array($items) ? $items : array();
        $items[] = array(
            'title'=>'Comparador',
            'icon'=>'dashicons-chart-bar',
            'page'=>'seo-comparador',
            'desc'=>'Recoge por categoria los datos ya obtenidos por Ojeador, prepara una propuesta y la convierte en borrador cuando la Editora la acepta.',
        );
        return $items;
    }

    public static function parent_file($parent_file) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return $page === 'seo-comparador' ? 'seo-system' : $parent_file;
    }

    public static function submenu_file($submenu_file) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return $page === 'seo-comparador' ? 'seo-content' : $submenu_file;
    }

    private static function url($args=array()) {
        return add_query_arg(array_merge(array('page'=>'seo-comparador'),(array)$args),admin_url('admin.php'));
    }

    private static function redirect($args=array()) {
        wp_safe_redirect(self::url($args));
        exit;
    }

    private static function notice($message,$type='success') {
        set_transient(
            'seo_comparador_notice_' . get_current_user_id(),
            array(
                'message'=>sanitize_text_field((string)$message),
                'type'=>sanitize_key((string)$type),
            ),
            90
        );
    }

    private static function render_notice() {
        $key='seo_comparador_notice_' . get_current_user_id();
        $notice=get_transient($key);
        delete_transient($key);
        if (!is_array($notice) || empty($notice['message'])) return;
        $type=in_array($notice['type'] ?? '',array('success','warning','error','info'),true)?$notice['type']:'info';
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
    }

    public static function handle_action() {
        if (!current_user_can('manage_options')) wp_die('No tienes permisos.');

        $profile_id=isset($_POST['profile_id']) ? absint(wp_unslash($_POST['profile_id'])) : 0;
        check_admin_referer('seo_comparador_action_' . $profile_id);

        $action=isset($_POST['profile_action']) ? sanitize_key(wp_unslash($_POST['profile_action'])) : '';
        $reason=isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '';
        $profile=SEO_Comparador_DB::get_profile($profile_id);

        if (!$profile) {
            self::notice('Perfil de Comparador no encontrado.','error');
            self::redirect();
        }

        if ($action==='refresh') {
            $result=SEO_Comparador_Engine::build_profile(absint($profile['primary_category_id']));
        } elseif ($action==='accept') {
            $result=SEO_Comparador_Engine::accept_proposal($profile_id);
        } elseif ($action==='reject') {
            $result=SEO_Comparador_Engine::reject_proposal($profile_id,$reason);
        } else {
            $result=new WP_Error('comparador_action','Accion no valida.');
        }

        if (is_wp_error($result)) {
            self::notice($result->get_error_message(),'error');
        } elseif ($action==='accept') {
            self::notice('Propuesta aceptada. Se ha creado el post en modo borrador.');
        } elseif ($action==='reject') {
            self::notice('Propuesta descartada.');
        } else {
            self::notice('Informacion de Ojeador releida para la categoria.');
        }

        self::redirect(array('profile_id'=>$profile_id));
    }

    public static function handle_accept_all() {
        if (!current_user_can('manage_options')) wp_die('No tienes permisos.');
        check_admin_referer('seo_comparador_accept_all');

        global $wpdb;
        SEO_Comparador_DB::maybe_install();
        $table=esc_sql(SEO_Comparador_DB::table('profiles'));

        $rows=(array)$wpdb->get_results(
            "SELECT id FROM `{$table}`
             WHERE status='candidate'
               AND recommended_action='CREATE_POST'
               AND external_products_comparable>0
             ORDER BY id ASC",
            ARRAY_A
        );

        $created=0;
        $skipped=0;
        $errors=0;

        foreach ($rows as $row) {
            $profile_id=absint($row['id'] ?? 0);
            if (!$profile_id) {
                $skipped++;
                continue;
            }
            $map=SEO_Comparador_DB::post_map($profile_id);
            if (!empty($map['post_id']) && get_post(absint($map['post_id']))) {
                $skipped++;
                continue;
            }
            $result=SEO_Comparador_Engine::accept_proposal($profile_id);
            if (is_wp_error($result)) $errors++;
            else $created++;
        }

        self::notice(
            'Aceptar todo: ' . $created . ' borradores creados, ' . $skipped . ' omitidos y ' . $errors . ' errores.',
            $errors ? 'warning' : 'success'
        );
        self::redirect();
    }

    private static function status_counts() {
        return SEO_Comparador_DB::profile_status_counts();
    }

    private static function query_profiles($status,$search,$page,$per_page) {
        global $wpdb;
        $table=esc_sql(SEO_Comparador_DB::table('profiles'));
        $where=array('1=1');
        $params=array();

        if ($status!=='') {
            if ($status==='draft') {
                $where[]="status='post_draft'";
            } elseif ($status==='published') {
                $where[]="status IN ('published','monitoring')";
            } else {
                $where[]='status=%s';
                $params[]=sanitize_key($status);
            }
        }

        if ($search!=='') {
            $where[]='canonical_name LIKE %s';
            $params[]='%' . $wpdb->esc_like($search) . '%';
        }

        $where_sql=implode(' AND ',$where);
        $count_sql="SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}";
        $list_sql="SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY updated_at DESC,id DESC LIMIT %d OFFSET %d";

        $offset=max(0,($page-1)*$per_page);
        $list_params=array_merge($params,array($per_page,$offset));

        if ($params) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table and WHERE fragments are internal; values use placeholders.
            $total=absint($wpdb->get_var($wpdb->prepare($count_sql,$params)));
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- internal fixed query.
            $total=absint($wpdb->get_var($count_sql));
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table and WHERE fragments are internal; values use placeholders.
        $rows=(array)$wpdb->get_results($wpdb->prepare($list_sql,$list_params),ARRAY_A);
        return array($rows,$total);
    }

    private static function action_form($profile_id,$action,$label,$class='secondary',$reason='') {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 5px 5px 0">';
        echo '<input type="hidden" name="action" value="seo_comparador_action">';
        echo '<input type="hidden" name="profile_id" value="' . esc_attr($profile_id) . '">';
        echo '<input type="hidden" name="profile_action" value="' . esc_attr($action) . '">';
        if ($reason!=='') echo '<input type="hidden" name="reason" value="' . esc_attr($reason) . '">';
        wp_nonce_field('seo_comparador_action_' . $profile_id);
        echo '<button type="submit" class="button ' . ($class==='primary'?'button-primary':'') . '">' . esc_html($label) . '</button>';
        echo '</form>';
    }

    private static function badge($status) {
        $labels=array(
            'waiting_ojeador'=>'Esperando Ojeador',
            'candidate'=>'Propuesta',
            'approved'=>'Aceptado',
            'post_draft'=>'Borrador',
            'published'=>'Publicado',
            'monitoring'=>'Publicado',
            'needs_update'=>'Con novedades',
            'rejected'=>'Descartado',
            'blocked'=>'Bloqueado',
        );
        $status=sanitize_key((string)$status);
        return $labels[$status] ?? str_replace('_',' ',$status ?: 'sin estado');
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;

        SEO_Comparador_DB::maybe_install();
        SEO_Comparador_Engine::ensure_all_category_profiles();
        SEO_Comparador_Engine::kick_automatic_refresh();

        $status=isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        $search=isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $page=max(1,isset($_GET['cmp_paged']) ? absint(wp_unslash($_GET['cmp_paged'])) : 1);
        $per_page=100;
        list($profiles,$total)=self::query_profiles($status,$search,$page,$per_page);
        $counts=self::status_counts();

        echo '<div class="wrap seo-comparador-simple">';
        echo '<h1>Comparador <small style="font-weight:400;color:#646970">v' . esc_html(SEO_COMPARADOR_VERSION) . '</small></h1>';
        echo '<p><strong>Flujo simple:</strong> Ojeador recopila el mercado; Comparador agrupa esos datos por categoria y propone un post. Al aceptar una propuesta se crea directamente un <strong>post en draft</strong> para editarlo.</p>';
        self::render_notice();

        echo '<div class="seo-cmp-summary">';
        echo '<div><strong>' . esc_html(number_format_i18n(absint($counts['candidate'] ?? 0))) . '</strong><span>Propuestas</span></div>';
        echo '<div><strong>' . esc_html(number_format_i18n(absint($counts['waiting_ojeador'] ?? 0))) . '</strong><span>Esperando Ojeador</span></div>';
        echo '<div><strong>' . esc_html(number_format_i18n(absint($counts['post_draft'] ?? 0))) . '</strong><span>Borradores</span></div>';
        echo '<div><strong>' . esc_html(number_format_i18n(absint($counts['published'] ?? 0)+absint($counts['monitoring'] ?? 0))) . '</strong><span>Publicados</span></div>';
        echo '<div><strong>' . esc_html(number_format_i18n(absint($counts['needs_update'] ?? 0))) . '</strong><span>Con novedades</span></div>';
        echo '</div>';

        echo '<div class="seo-cmp-toolbar">';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">';
        echo '<input type="hidden" name="page" value="seo-comparador">';
        echo '<select name="status">';
        $filters=array(
            ''=>'Todos',
            'candidate'=>'Propuestas',
            'waiting_ojeador'=>'Esperando Ojeador',
            'draft'=>'Borradores',
            'published'=>'Publicados',
            'needs_update'=>'Con novedades',
            'rejected'=>'Descartados',
        );
        foreach ($filters as $key=>$label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($status,$key,false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select> ';
        echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="Buscar categoria"> ';
        echo '<button class="button">Filtrar</button>';
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="seo_comparador_accept_all">';
        wp_nonce_field('seo_comparador_accept_all');
        echo '<button type="submit" class="button button-primary" onclick="return confirm(\'Se creara un borrador por cada propuesta de Comparador que ya tenga datos de Ojeador. ¿Continuar?\');">Aceptar todo</button>';
        echo '</form>';
        echo '</div>';

        echo '<div class="postbox seo-cmp-box"><table class="widefat striped">';
        echo '<thead><tr><th>Categoria</th><th>Ojeador</th><th>Nuestro catalogo</th><th>Propuesta</th><th>Estado</th><th>Post</th><th>Acciones</th></tr></thead><tbody>';

        if (!$profiles) {
            echo '<tr><td colspan="7">No hay categorias para este filtro.</td></tr>';
        }

        foreach ($profiles as $p) {
            $profile_id=absint($p['id']);
            $editorial=SEO_Comparador_DB::editorial($profile_id);
            $map=SEO_Comparador_DB::post_map($profile_id);
            $post_id=absint($map['post_id'] ?? 0);

            echo '<tr>';
            echo '<td><strong>' . esc_html((string)$p['canonical_name']) . '</strong><br><code>#' . esc_html(absint($p['primary_category_id'])) . '</code></td>';
            echo '<td><strong>' . esc_html(number_format_i18n(absint($p['external_products_comparable']))) . '</strong> referencias<br><small>' . esc_html((string)($p['source_snapshot_at'] ?: 'sin snapshot')) . '</small></td>';
            echo '<td>' . esc_html(number_format_i18n(absint($p['own_products_count']))) . ' productos</td>';

            $excerpt=trim((string)($editorial['market_overview'] ?? ''));
            if ($excerpt==='') $excerpt=trim((string)($editorial['excerpt'] ?? ''));
            echo '<td style="max-width:520px">' . esc_html($excerpt!=='' ? wp_html_excerpt($excerpt,420,'…') : 'Pendiente de datos de Ojeador.') . '</td>';

            echo '<td><span class="seo-cmp-badge seo-cmp-' . esc_attr(sanitize_key((string)$p['status'])) . '">' . esc_html(self::badge((string)$p['status'])) . '</span></td>';

            echo '<td>';
            if ($post_id && get_post($post_id)) {
                $edit=get_edit_post_link($post_id,'');
                echo '<a href="' . esc_url($edit) . '"><strong>#' . esc_html($post_id) . '</strong></a><br>' . esc_html(get_post_status($post_id));
            } else {
                echo '—';
            }
            echo '</td>';

            echo '<td style="min-width:260px">';
            self::action_form($profile_id,'refresh','Releer Ojeador');
            if (!$post_id && absint($p['external_products_comparable'])>0 && in_array((string)$p['status'],array('candidate','needs_update','rejected'),true)) {
                self::action_form($profile_id,'accept','Aceptar y crear draft','primary');
            }
            if (!$post_id && !in_array((string)$p['status'],array('rejected','waiting_ojeador'),true)) {
                self::action_form($profile_id,'reject','Descartar','secondary','Descartado por la Editora.');
            }
            if ($post_id && get_post($post_id)) {
                echo '<a class="button" href="' . esc_url(get_edit_post_link($post_id,'')) . '">Editar borrador</a>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        $pages=max(1,(int)ceil($total/$per_page));
        if ($pages>1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo wp_kses_post(paginate_links(array(
                'base'=>add_query_arg(array(
                    'page'=>'seo-comparador',
                    'status'=>$status,
                    's'=>$search,
                    'cmp_paged'=>'%#%',
                ),admin_url('admin.php')),
                'format'=>'',
                'current'=>$page,
                'total'=>$pages,
                'type'=>'plain',
            )));
            echo '</div></div>';
        }

        echo '<style>
        .seo-cmp-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:18px 0}
        .seo-cmp-summary div{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px}
        .seo-cmp-summary strong{display:block;font-size:24px}.seo-cmp-summary span{color:#646970}
        .seo-cmp-toolbar{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:14px 0}
        .seo-cmp-toolbar form{display:flex;gap:8px;align-items:center}
        .seo-cmp-box{overflow:auto}.seo-cmp-box table{min-width:1100px}
        .seo-cmp-badge{display:inline-block;border-radius:999px;padding:4px 8px;background:#f0f0f1}
        .seo-cmp-candidate,.seo-cmp-approved,.seo-cmp-post_draft,.seo-cmp-published,.seo-cmp-monitoring{background:#edfaef;color:#0a6b25}
        .seo-cmp-waiting_ojeador,.seo-cmp-needs_update{background:#fff8e5;color:#8a5a00}
        .seo-cmp-rejected,.seo-cmp-blocked{background:#fce8e8;color:#a61b1b}
        </style>';

        echo '</div>';
    }
}
