<?php
/**
 * Comparador - interfaz administrativa.
 */

defined('ABSPATH') || exit;

final class SEO_Comparador_Admin {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_page'), 31);
        add_filter('seo_content_items', array(__CLASS__, 'content_card'), 31, 1);
        add_filter('parent_file', array(__CLASS__, 'parent_file'), 31, 1);
        add_filter('submenu_file', array(__CLASS__, 'submenu_file'), 31, 1);
        add_action('admin_post_seo_comparador_settings', array(__CLASS__, 'handle_settings'));
        add_action('admin_post_seo_comparador_build', array(__CLASS__, 'handle_build'));
        add_action('admin_post_seo_comparador_axes', array(__CLASS__, 'handle_axes'));
        add_action('admin_post_seo_comparador_action', array(__CLASS__, 'handle_action'));
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
            'desc'=>'Inteligencia comparativa por categoría: catálogo propio + mercado observado, perfiles persistentes y entrega a Solucionador.',
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

    private static function url($tab='comparisons',$extra=array()) {
        return add_query_arg(array_merge(array(
            'page'=>'seo-comparador',
            'tab'=>sanitize_key((string)$tab),
        ),(array)$extra),admin_url('admin.php'));
    }

    private static function redirect($tab='comparisons',$args=array()) {
        wp_safe_redirect(self::url($tab,$args));
        exit;
    }

    private static function notice($message,$type='success') {
        set_transient('seo_comparador_notice_' . get_current_user_id(),array(
            'message'=>sanitize_text_field((string)$message),
            'type'=>sanitize_key((string)$type),
        ),90);
    }

    private static function render_notice() {
        $key='seo_comparador_notice_' . get_current_user_id();
        $notice=get_transient($key);
        delete_transient($key);
        if (!is_array($notice) || empty($notice['message'])) return;
        $type=in_array($notice['type'] ?? '',array('success','warning','error','info'),true)?$notice['type']:'info';
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
    }

    public static function handle_settings() {
        if (!current_user_can('manage_options')) wp_die('No tienes permisos.');
        check_admin_referer('seo_comparador_settings');
        SEO_Comparador_Engine::save_settings((array)($_POST['settings'] ?? array()));
        self::notice('Configuración de Comparador guardada.');
        self::redirect('settings');
    }

    public static function handle_build() {
        if (!current_user_can('manage_options')) wp_die('No tienes permisos.');
        check_admin_referer('seo_comparador_build');
        $term_id=absint($_POST['term_id'] ?? 0);
        $result=SEO_Comparador_Engine::build_profile($term_id);
        if (is_wp_error($result)) {
            self::notice($result->get_error_message(),'error');
            self::redirect('settings',array('term_id'=>$term_id));
        }
        self::notice('Perfil comparativo recalculado.');
        self::redirect('comparisons',array('profile_id'=>absint($result['id'] ?? 0)));
    }

    public static function handle_axes() {
        if (!current_user_can('manage_options')) wp_die('No tienes permisos.');
        $profile_id=absint($_POST['profile_id'] ?? 0);
        check_admin_referer('seo_comparador_axes_' . $profile_id);
        $result=SEO_Comparador_Engine::save_axes($profile_id,(array)($_POST['axes'] ?? array()));
        if (is_wp_error($result)) self::notice($result->get_error_message(),'error');
        else self::notice('Ejes comparativos guardados; el perfil vuelve a revisión.');
        self::redirect('comparisons',array('profile_id'=>$profile_id));
    }

    public static function handle_action() {
        if (!current_user_can('manage_options')) wp_die('No tienes permisos.');
        $profile_id=absint($_POST['profile_id'] ?? 0);
        check_admin_referer('seo_comparador_action_' . $profile_id);
        $action=sanitize_key((string)($_POST['profile_action'] ?? ''));
        $reason=sanitize_textarea_field(wp_unslash($_POST['reason'] ?? ''));
        if ($action==='send_solucionador') {
            $result=SEO_Comparador_Engine::send_to_solucionador($profile_id,$reason);
        } elseif ($action==='link_post') {
            $result=SEO_Comparador_Engine::link_post($profile_id,absint($_POST['post_id'] ?? 0));
        } elseif ($action==='archive') {
            $result=SEO_Comparador_Engine::mark_not_comparable($profile_id,$reason);
        } elseif ($action==='recalculate') {
            $profile=SEO_Comparador_DB::get_profile($profile_id);
            $result=$profile ? SEO_Comparador_Engine::build_profile(absint($profile['primary_category_id'])) : new WP_Error('comparador_profile','Perfil no encontrado.');
        } elseif ($action==='regenerate_editorial') {
            $result=SEO_Comparador_Engine::generate_editorial($profile_id);
        } else {
            $result=new WP_Error('comparador_action','Acción no válida.');
        }
        if (is_wp_error($result)) self::notice($result->get_error_message(),'error');
        else self::notice('Acción de Comparador completada.');
        self::redirect('comparisons',array('profile_id'=>$profile_id));
    }

    private static function tabs($current) {
        $tabs=array(
            'settings'=>'Configuración',
            'comparisons'=>'Comparativas',
            'performance'=>'Rendimiento',
        );
        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $key=>$label) {
            echo '<a class="nav-tab ' . ($current===$key?'nav-tab-active':'') . '" href="' . esc_url(self::url($key)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        SEO_Comparador_DB::maybe_install();
        $tab=isset($_GET['tab'])?sanitize_key(wp_unslash($_GET['tab'])):'comparisons';
        if (!in_array($tab,array('settings','comparisons','performance'),true)) $tab='comparisons';

        echo '<div class="wrap seo-comparador">';
        echo '<h1>Comparador <small style="font-weight:400;color:#646970">v' . esc_html(SEO_COMPARADOR_VERSION) . '</small></h1>';
        echo '<p><strong>Inteligencia comparativa de producto.</strong> Cruza catálogo propio con mercado ya observado por Ojeador, conserva datos y confianza, genera un perfil comparativo y lo entrega a Solucionador. <strong>No publica ni consulta fuentes externas por su cuenta.</strong></p>';
        self::render_notice();
        self::tabs($tab);
        if ($tab==='settings') self::render_settings();
        elseif ($tab==='performance') self::render_performance();
        else self::render_comparisons();
        self::styles();
        echo '</div>';
    }

    private static function render_settings() {
        $settings=SEO_Comparador_Engine::settings();
        echo '<div class="seo-cmp-grid">';
        self::card('Esquema BD',get_option(SEO_Comparador_DB::VERSION_OPTION,'—'),'Tablas seo_comparador_*');
        self::card('Comparador tienda','2–6 productos','Se conserva sin cambios.');
        self::card('Fuente mercado',class_exists('SEO_Ojeador_DB')?'Ojeador disponible':'Ojeador no disponible','Sólo datos persistidos.');
        self::card('Salida editorial','Solucionador','Comparador no decide URL.');
        echo '</div>';

        echo '<div class="postbox seo-cmp-box"><h2>Parámetros generales</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_comparador_settings">';
        wp_nonce_field('seo_comparador_settings');
        echo '<div class="seo-cmp-form-grid">';
        self::number_field('Máx. productos en comparador de tienda','settings[store_compare_max]',$settings['store_compare_max'],2,6,1);
        self::number_field('Máx. productos propios por perfil','settings[max_own_products]',$settings['max_own_products'],20,1000,1);
        self::number_field('Máx. referencias Ojeador','settings[max_external_products]',$settings['max_external_products'],20,2000,1);
        self::number_field('Máx. ejes por perfil','settings[max_axes]',$settings['max_axes'],3,40,1);
        self::number_field('Mín. productos comparables','settings[min_products]',$settings['min_products'],2,20,1);
        self::number_field('Cobertura mínima eje','settings[min_axis_coverage]',$settings['min_axis_coverage'],0.1,1,0.05);
        self::number_field('Confianza mínima eje','settings[min_axis_confidence]',$settings['min_axis_confidence'],0.1,1,0.05);
        self::number_field('Referencias externas representativas','settings[max_representative_external]',$settings['max_representative_external'],0,20,1);
        echo '</div>';
        submit_button('Guardar configuración');
        echo '</form></div>';

        echo '<div class="postbox seo-cmp-box"><h2>Construir o recalcular una categoría</h2>';
        echo '<p>Usa WooCommerce + snapshots ya almacenados por Ojeador. No ejecuta Google Shopping.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_comparador_build">';
        wp_nonce_field('seo_comparador_build');
        echo '<label><strong>Categoría</strong><br><select name="term_id" required style="min-width:420px"><option value="">Selecciona…</option>';
        $terms=get_terms(array('taxonomy'=>'product_cat','hide_empty'=>true,'number'=>1000,'orderby'=>'name','order'=>'ASC'));
        foreach ((array)$terms as $term) {
            if (!is_object($term)) continue;
            echo '<option value="' . esc_attr($term->term_id) . '">' . esc_html($term->name . ' · ' . number_format_i18n($term->count) . ' productos') . '</option>';
        }
        echo '</select></label> ';
        submit_button('Construir perfil','primary','submit',false);
        echo '</form></div>';

        if (class_exists('SEO_Comparador_Tests')) {
            $report=SEO_Comparador_Tests::run();
            echo '<div class="postbox seo-cmp-box"><h2>Pruebas RF v1.0</h2>';
            echo '<p><strong>' . esc_html(absint($report['passed'])) . '/' . esc_html(absint($report['total'])) . '</strong> pruebas CMP superadas.</p>';
            echo '<div class="seo-cmp-table"><table class="widefat striped"><thead><tr><th>Test</th><th>Resultado</th><th>Regla</th></tr></thead><tbody>';
            foreach ((array)$report['tests'] as $row) {
                echo '<tr><td><strong>' . esc_html($row['id']) . '</strong></td><td>' . (!empty($row['pass'])?'<strong class="seo-cmp-ok">OK</strong>':'<strong class="seo-cmp-bad">FALLO</strong>') . '</td><td>' . esc_html($row['detail']) . '</td></tr>';
            }
            echo '</tbody></table></div></div>';
        }
    }

    private static function render_comparisons() {
        $profiles=SEO_Comparador_DB::list_profiles(1000);
        echo '<div class="seo-cmp-grid">';
        self::card('Perfiles',count($profiles),'Alcances comparables persistidos.');
        self::card('Listos para Solucionador',count(array_filter($profiles,static function($p){return ($p['status']??'')==='ready_for_solucionador';})),'Validados y pendientes de decisión editorial.');
        self::card('Publicados',count(array_filter($profiles,static function($p){return in_array(($p['status']??''),array('published','monitoring'),true);})),'Con post canónico vinculado.');
        self::card('Necesitan actualización',count(array_filter($profiles,static function($p){return ($p['status']??'')==='needs_update';})),'Las fuentes han cambiado materialmente.');
        echo '</div>';

        echo '<div class="postbox seo-cmp-box"><h2>Perfiles comparativos</h2>';
        echo '<div class="seo-cmp-table"><table class="widefat striped"><thead><tr><th>Categoría</th><th>Estado</th><th>Propios</th><th>Mercado</th><th>Ejes</th><th>Confianza</th><th>Snapshot</th><th></th></tr></thead><tbody>';
        if (!$profiles) echo '<tr><td colspan="8">Todavía no hay perfiles. Construye uno desde Configuración.</td></tr>';
        foreach ($profiles as $p) {
            echo '<tr><td><strong>' . esc_html((string)$p['canonical_name']) . '</strong><br><code>#' . esc_html(absint($p['primary_category_id'])) . '</code></td>';
            echo '<td>' . self::status_badge((string)$p['status']) . '</td>';
            echo '<td>' . esc_html(number_format_i18n(absint($p['own_products_count']))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n(absint($p['external_products_comparable']))) . ' / ' . esc_html(number_format_i18n(absint($p['external_products_seen']))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n(absint($p['comparison_axes_count']))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float)$p['confidence']*100,0)) . '%</td>';
            echo '<td>' . esc_html((string)$p['source_snapshot_at']) . '</td>';
            echo '<td><a class="button" href="' . esc_url(self::url('comparisons',array('profile_id'=>absint($p['id'])))) . '">Abrir</a></td></tr>';
        }
        echo '</tbody></table></div></div>';

        $profile_id=absint($_GET['profile_id'] ?? 0);
        if ($profile_id) self::render_profile($profile_id);
    }

    private static function render_profile($profile_id) {
        $profile=SEO_Comparador_DB::get_profile($profile_id);
        if (!$profile) return;
        $axes=SEO_Comparador_DB::axes($profile_id);
        $editorial=SEO_Comparador_DB::editorial($profile_id);
        $post_map=SEO_Comparador_DB::post_map($profile_id);
        $products=SEO_Comparador_DB::products($profile_id);
        $own=count(array_filter($products,static function($p){return ($p['source_type']??'')==='own';}));
        $external=count($products)-$own;

        echo '<div class="postbox seo-cmp-box"><h2>' . esc_html((string)$profile['canonical_name']) . ' · perfil #' . esc_html($profile_id) . '</h2>';
        echo '<p><strong>Estado:</strong> ' . self::status_badge((string)$profile['status']) . ' · <strong>Productos:</strong> ' . esc_html($own) . ' propios / ' . esc_html($external) . ' externos deduplicados.</p>';

        echo '<div class="seo-cmp-actions">';
        self::action_form($profile_id,'recalculate','Recalcular','','');
        self::action_form($profile_id,'send_solucionador','Enviar a Solucionador','El perfil dispone de datos y ejes revisados.','primary');
        self::action_form($profile_id,'regenerate_editorial','Regenerar texto base','','');
        self::action_form($profile_id,'archive','Marcar no comparable','No existe un alcance comparativo útil.','');
        echo '</div>';

        echo '<h3>Texto editorial base</h3>';
        echo '<p>' . esc_html(wp_strip_all_tags((string)($editorial['summary'] ?? 'Todavía no generado.'))) . '</p>';
        echo '<p><strong>Extracto:</strong> ' . esc_html((string)($editorial['excerpt'] ?? '—')) . '</p>';

        echo '<h3>Ejes comparativos</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_comparador_axes"><input type="hidden" name="profile_id" value="' . esc_attr($profile_id) . '">';
        wp_nonce_field('seo_comparador_axes_' . $profile_id);
        echo '<div class="seo-cmp-table"><table class="widefat striped"><thead><tr><th>Publicar</th><th>Eje</th><th>Unidad</th><th>Prioridad</th><th>Cobertura</th><th>Confianza mínima</th><th>Origen</th></tr></thead><tbody>';
        foreach ($axes as $axis) {
            $k=(string)$axis['axis_key'];
            echo '<tr><td><input type="checkbox" name="axes[' . esc_attr($k) . '][publishable]" value="1" ' . checked(!empty($axis['publishable']),true,false) . '></td>';
            echo '<td><input type="text" name="axes[' . esc_attr($k) . '][label]" value="' . esc_attr((string)$axis['label']) . '"><br><code>' . esc_html($k) . '</code></td>';
            echo '<td><input type="text" name="axes[' . esc_attr($k) . '][unit]" value="' . esc_attr((string)$axis['unit']) . '" size="8"></td>';
            echo '<td><input type="number" min="0" max="100" name="axes[' . esc_attr($k) . '][priority]" value="' . esc_attr(absint($axis['priority'])) . '"></td>';
            echo '<td>' . esc_html(number_format_i18n((float)$axis['coverage']*100,0)) . '%</td>';
            echo '<td><input type="number" min="0.1" max="1" step="0.05" name="axes[' . esc_attr($k) . '][min_confidence]" value="' . esc_attr((float)$axis['min_confidence']) . '"></td>';
            echo '<td>' . esc_html((string)$axis['source']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        submit_button('Guardar ejes');
        echo '</form>';

        echo '<h3>Post canónico</h3>';
        if (!empty($post_map['post_id'])) {
            echo '<p>Vinculado a <a href="' . esc_url(get_edit_post_link(absint($post_map['post_id']))) . '"><strong>' . esc_html((string)$post_map['post_title']) . '</strong></a> · ' . esc_html((string)$post_map['post_status']) . '.</p>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="seo-cmp-inline"><input type="hidden" name="action" value="seo_comparador_action"><input type="hidden" name="profile_id" value="' . esc_attr($profile_id) . '"><input type="hidden" name="profile_action" value="link_post">';
        wp_nonce_field('seo_comparador_action_' . $profile_id);
        echo '<label>Post ID <input type="number" min="1" name="post_id" value="' . esc_attr(absint($post_map['post_id'] ?? 0) ?: '') . '" required></label> ';
        submit_button('Vincular post y etiqueta comparativas','secondary','submit',false);
        echo '</form>';

        echo '<h3>Muestra de referencias</h3><div class="seo-cmp-table"><table class="widefat striped"><thead><tr><th>Fuente</th><th>Producto</th><th>Marca</th><th>Modelo</th><th>Representativo</th></tr></thead><tbody>';
        foreach (array_slice($products,0,80) as $p) {
            echo '<tr><td>' . esc_html((string)$p['source_type']) . '</td><td>' . esc_html((string)$p['title']) . '</td><td>' . esc_html((string)$p['brand']) . '</td><td>' . esc_html((string)$p['model']) . '</td><td>' . (!empty($p['representative'])?'Sí':'—') . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function render_performance() {
        $days=isset($_GET['days']) && absint($_GET['days'])===90 ? 90 : 28;
        $rows=SEO_Comparador_Engine::performance_rows($days);
        echo '<div class="postbox seo-cmp-box"><h2>Rendimiento de comparativas</h2>';
        echo '<p>Datos consumidos desde Analista; Comparador no realiza llamadas propias a Search Console, Analytics o Bing.</p>';
        echo '<p><a class="button ' . ($days===28?'button-primary':'') . '" href="' . esc_url(self::url('performance',array('days'=>28))) . '">28 días</a> <a class="button ' . ($days===90?'button-primary':'') . '" href="' . esc_url(self::url('performance',array('days'=>90))) . '">90 días</a></p>';
        echo '<div class="seo-cmp-table"><table class="widefat striped"><thead><tr><th>Comparativa</th><th>Impresiones</th><th>Clics</th><th>CTR</th><th>Posición</th><th>Sesiones</th><th>Vistas</th><th>Estado</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="8">No hay posts de comparativa vinculados o Analista todavía no dispone de métricas.</td></tr>';
        foreach ($rows as $row) {
            echo '<tr><td><strong>' . esc_html((string)$row['post_title']) . '</strong><br><a href="' . esc_url((string)$row['url']) . '" target="_blank" rel="noopener">Abrir</a></td>';
            echo '<td>' . esc_html(number_format_i18n(absint($row['impressions']))) . '</td><td>' . esc_html(number_format_i18n(absint($row['clicks']))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float)$row['ctr']*100,2)) . '%</td><td>' . esc_html(number_format_i18n((float)$row['position'],1)) . '</td>';
            echo '<td>' . esc_html(number_format_i18n(absint($row['sessions']))) . '</td><td>' . esc_html(number_format_i18n(absint($row['pageviews']))) . '</td><td>' . self::status_badge((string)$row['status']) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function action_form($profile_id,$action,$label,$reason='',$class='') {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_comparador_action"><input type="hidden" name="profile_id" value="' . esc_attr($profile_id) . '"><input type="hidden" name="profile_action" value="' . esc_attr($action) . '"><input type="hidden" name="reason" value="' . esc_attr($reason) . '">';
        wp_nonce_field('seo_comparador_action_' . $profile_id);
        submit_button($label,$class==='primary'?'primary':'secondary','submit',false);
        echo '</form>';
    }

    private static function number_field($label,$name,$value,$min,$max,$step) {
        echo '<label><strong>' . esc_html($label) . '</strong><input type="number" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" min="' . esc_attr($min) . '" max="' . esc_attr($max) . '" step="' . esc_attr($step) . '"></label>';
    }

    private static function card($label,$value,$desc) {
        echo '<div class="seo-cmp-card"><span>' . esc_html($label) . '</span><strong>' . esc_html((string)$value) . '</strong><small>' . esc_html($desc) . '</small></div>';
    }

    private static function status_badge($status) {
        $status=sanitize_key((string)$status);
        return '<span class="seo-cmp-status seo-cmp-status-' . esc_attr($status) . '">' . esc_html(str_replace('_',' ',$status ?: 'unknown')) . '</span>';
    }

    private static function styles() {
        echo '<style>
        .seo-cmp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin:18px 0}
        .seo-cmp-card,.seo-cmp-box{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px}
        .seo-cmp-card span,.seo-cmp-card small{display:block;color:#646970}.seo-cmp-card strong{display:block;font-size:24px;margin:6px 0}
        .seo-cmp-box{margin-top:18px}.seo-cmp-box h2{margin-top:0}.seo-cmp-form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}
        .seo-cmp-form-grid label{display:grid;gap:5px}.seo-cmp-form-grid input{width:100%}.seo-cmp-table{overflow:auto}.seo-cmp-table table{min-width:900px}
        .seo-cmp-actions{display:flex;gap:8px;flex-wrap:wrap}.seo-cmp-actions form{margin:0}.seo-cmp-inline{display:flex;gap:8px;align-items:end;flex-wrap:wrap}
        .seo-cmp-status{display:inline-block;padding:3px 8px;border-radius:999px;background:#f0f0f1}.seo-cmp-status-ready_for_solucionador,.seo-cmp-status-published,.seo-cmp-status-monitoring{background:#edfaef;color:#0a6b25}
        .seo-cmp-status-needs_update,.seo-cmp-status-needs_review{background:#fff8e5;color:#8a5a00}.seo-cmp-status-blocked,.seo-cmp-status-archived{background:#fce8e8;color:#a61b1b}
        .seo-cmp-ok{color:#008a20}.seo-cmp-bad{color:#b32d2e}
        </style>';
    }
}
