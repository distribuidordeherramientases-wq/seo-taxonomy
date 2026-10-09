<?php
/**
 * Comparador v2 - interfaz editorial simplificada.
 */

defined('ABSPATH') || exit;

final class SEO_Comparador_Admin {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_page'), 31);
        add_filter('seo_content_items', array(__CLASS__, 'content_card'), 31, 1);
        add_filter('parent_file', array(__CLASS__, 'parent_file'), 31, 1);
        add_filter('submenu_file', array(__CLASS__, 'submenu_file'), 31, 1);
        add_action('admin_post_seo_comparador_refresh', array(__CLASS__, 'handle_refresh'));
        add_action('admin_post_seo_comparador_reanalyze', array(__CLASS__, 'handle_reanalyze'));
        add_action('admin_post_seo_comparador_accept', array(__CLASS__, 'handle_accept'));
        add_action('admin_post_seo_comparador_accept_all', array(__CLASS__, 'handle_accept_all'));
        add_action('admin_post_seo_comparador_reject', array(__CLASS__, 'handle_reject'));
    }

    public static function register_page() {
        add_submenu_page(null, 'Comparador', 'Comparador', 'manage_options', 'seo-comparador', array(__CLASS__, 'render'));
    }

    public static function content_card($items) {
        $items = is_array($items) ? $items : array();
        $items[] = array(
            'title'=>'Comparador',
            'icon'=>'dashicons-chart-bar',
            'page'=>'seo-comparador',
            'desc'=>'Convierte los snapshots de Ojeador en informes breves de mercado por categoría y propuestas de post para la Editora.',
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

    private static function guard($action) {
        if (!current_user_can('manage_options')) wp_die(esc_html__('No tienes permisos para usar Comparador.', 'seo-taxonomy'));
        check_admin_referer($action);
    }

    private static function page_url($args = array()) {
        return add_query_arg(array_merge(array('page'=>'seo-comparador'), (array)$args), admin_url('admin.php'));
    }

    private static function redirect($args = array()) {
        wp_safe_redirect(self::page_url($args));
        exit;
    }

    public static function handle_refresh() {
        self::guard('seo_comparador_refresh');
        SEO_Comparador_Service::queue_refresh();
        self::redirect(array('cmp_notice'=>'refresh_started'));
    }

    public static function handle_reanalyze() {
        $profile_id = absint($_POST['profile_id'] ?? 0);
        self::guard('seo_comparador_reanalyze_' . $profile_id);
        $profile = SEO_Comparador_DB::get_profile($profile_id);
        if (!$profile) self::redirect(array('cmp_error'=>rawurlencode('Perfil no encontrado.')));
        $result = SEO_Comparador_Service::analyze_category(absint($profile['primary_category_id'] ?? 0));
        if (is_wp_error($result)) self::redirect(array('cmp_error'=>rawurlencode($result->get_error_message()),'profile_id'=>$profile_id));
        self::redirect(array('cmp_notice'=>'reanalyzed','profile_id'=>$profile_id));
    }

    public static function handle_accept() {
        $profile_id = absint($_POST['profile_id'] ?? 0);
        self::guard('seo_comparador_accept_' . $profile_id);
        $post_id = SEO_Comparador_Service::accept_and_create_draft($profile_id);        if (is_wp_error($post_id)) self::redirect(array('cmp_error'=>rawurlencode($post_id->get_error_message()),'profile_id'=>$profile_id));
        self::redirect(array('cmp_notice'=>'draft_created','profile_id'=>$profile_id,'post_id'=>absint($post_id)));
    }

    public static function handle_accept_all() {
        self::guard('seo_comparador_accept_all');

        $created = absint($_REQUEST['created'] ?? 0);
        $errors = absint($_REQUEST['errors'] ?? 0);
        $batch = array_values(array_filter(SEO_Comparador_DB::list_profiles(2000), static function($profile) {
            return sanitize_key((string)($profile['status'] ?? '')) === 'proposal';
        }));
        $batch = array_slice($batch, 0, 50);

        foreach ($batch as $profile) {
            $result = SEO_Comparador_Service::accept_and_create_draft(absint($profile['id'] ?? 0));
            if (is_wp_error($result)) $errors++;
            else $created++;
        }

        $remaining = count(array_filter(SEO_Comparador_DB::list_profiles(2000), static function($profile) {
            return sanitize_key((string)($profile['status'] ?? '')) === 'proposal';
        }));
        if ($remaining > 0 && $batch) {
            $next = add_query_arg(array(
                'action'=>'seo_comparador_accept_all',
                'created'=>$created,
                'errors'=>$errors,
                '_wpnonce'=>wp_create_nonce('seo_comparador_accept_all'),
            ), admin_url('admin-post.php'));
            wp_safe_redirect($next);
            exit;
        }

        self::redirect(array('cmp_notice'=>'all_accepted','created'=>$created,'errors'=>$errors));
    }

    public static function handle_reject() {
        $profile_id = absint($_POST['profile_id'] ?? 0);
        self::guard('seo_comparador_reject_' . $profile_id);
        SEO_Comparador_Service::reject($profile_id);
        self::redirect(array('cmp_notice'=>'rejected'));
    }

    private static function render_notice() {
        $notice = isset($_GET['cmp_notice']) ? sanitize_key(wp_unslash($_GET['cmp_notice'])) : '';
        $error = isset($_GET['cmp_error']) ? sanitize_text_field(wp_unslash($_GET['cmp_error'])) : '';
        if ($error !== '') {
            echo '<div class="notice notice-error"><p>' . esc_html(rawurldecode($error)) . '</p></div>';
            return;
        }
        $messages = array(
            'refresh_started'=>'Se ha reiniciado el análisis de Comparador. El Gestor recorrerá de nuevo las categorías usando los snapshots actuales de Ojeador.',
            'reanalyzed'=>'Categoría reanalizada.',
            'draft_created'=>'Propuesta aceptada y convertida en borrador.',
            'rejected'=>'Propuesta descartada.',
            'all_accepted'=>'Propuestas aceptadas y convertidas en borrador.',
        );
        if ($notice === '' || !isset($messages[$notice])) return;
        $message = $messages[$notice];
        if ($notice === 'all_accepted') {
            $message .= ' Creados: ' . absint($_GET['created'] ?? 0) . ' · Errores: ' . absint($_GET['errors'] ?? 0) . '.';
        }
        echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
    }

    private static function status_label($status) {
        $labels = array(
            'detected'=>'Pendiente de analizar',
            'waiting_ojeador'=>'Esperando Ojeador',
            'insufficient_market'=>'Muestra insuficiente',
            'proposal'=>'Propuesta lista',
            'accepted'=>'Aceptada',
            'post_draft'=>'Borrador',
            'published'=>'Publicado',
            'needs_update'=>'Necesita actualización',
            'closed'=>'Descartado',
        );
        return $labels[$status] ?? ($status !== '' ? $status : 'Sin estado');
    }

    private static function status_order($status) {
        $order = array('proposal'=>0,'needs_update'=>1,'waiting_ojeador'=>2,'insufficient_market'=>3,'post_draft'=>4,'published'=>5,'closed'=>6,'detected'=>7);
        return $order[$status] ?? 20;
    }

    private static function filtered_profiles() {
        $filter = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : 'all';
        $rows = SEO_Comparador_DB::list_profiles(2000);
        if ($filter !== 'all') {
            $rows = array_values(array_filter($rows, static function($row) use ($filter) {
                return sanitize_key((string)($row['status'] ?? '')) === $filter;
            }));
        }
        usort($rows, static function($a,$b) {
            $sa = sanitize_key((string)($a['status'] ?? ''));
            $sb = sanitize_key((string)($b['status'] ?? ''));
            $order = self::status_order($sa) <=> self::status_order($sb);
            if ($order !== 0) return $order;
            return strcasecmp((string)($a['canonical_name'] ?? ''), (string)($b['canonical_name'] ?? ''));
        });
        return $rows;
    }

    private static function render_kpis() {
        $c = SEO_Comparador_Service::counts();
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:16px 0">';
        foreach (array(
            'total'=>'Categorías',
            'proposal'=>'Propuestas listas',
            'waiting_ojeador'=>'Esperando Ojeador',
            'insufficient_market'=>'Muestra insuficiente',
            'post_draft'=>'Borradores',
            'published'=>'Publicados',
            'needs_update'=>'Actualizar',
        ) as $key=>$label) {
            echo '<div class="postbox" style="padding:14px;margin:0"><div style="font-size:24px;font-weight:700">' . esc_html(number_format_i18n(absint($c[$key] ?? 0))) . '</div><div>' . esc_html($label) . '</div></div>';
        }
        echo '</div>';
    }

    private static function render_detail($profile_id) {
        $profile = SEO_Comparador_DB::get_profile(absint($profile_id));
        if (!$profile) return;
        $editorial = SEO_Comparador_DB::editorial(absint($profile_id));
        $dossier = SEO_Comparador_Service::dossier(absint($profile_id));
        $market = (array)($dossier['market'] ?? array());
        $price = (array)($market['price'] ?? array());
        $brands = array_values((array)($market['brands'] ?? array()));
        $axes = array_values((array)($market['axes'] ?? array()));
        $refs = array_values((array)($dossier['representative_market'] ?? array()));
        $map = SEO_Comparador_DB::post_map(absint($profile_id));
        $status = sanitize_key((string)($profile['status'] ?? ''));

        echo '<div class="postbox" style="padding:18px;margin:18px 0">';
        echo '<h2 style="margin-top:0">' . esc_html((string)$profile['canonical_name']) . '</h2>';
        echo '<p><strong>Estado:</strong> ' . esc_html(self::status_label($status)) . ' · <strong>Ojeador:</strong> ' . esc_html(number_format_i18n(absint($market['market_count'] ?? 0))) . ' referencias deduplicadas';
        if (absint($price['count'] ?? 0)) echo ' · <strong>Precios válidos:</strong> ' . esc_html(number_format_i18n(absint($price['count']))) . ' · <strong>Marcas:</strong> ' . esc_html(number_format_i18n(absint($market['brand_count'] ?? 0)));
        echo '</p>';

        if (!empty($editorial['market_overview'])) {
            echo '<h3>Propuesta de informe público</h3><div style="max-width:1000px;padding:12px 14px;background:#f6f7f7;border-left:4px solid #2271b1"><p>' . esc_html((string)$editorial['market_overview']) . '</p></div>';
        } else {
            echo '<p><em>No hay todavía informe público porque falta muestra de mercado suficiente.</em></p>';
        }

        if (!empty($dossier['own_position']['text'])) {
            echo '<h3>Análisis interno de precios</h3><p>' . esc_html((string)$dossier['own_position']['text']) . '</p>';
        }
        if ($brands) {
            $names = array_values(array_filter(array_map(static function($row){ return sanitize_text_field((string)($row['name'] ?? '')); }, array_slice($brands,0,8))));
            if ($names) echo '<p><strong>Marcas observadas:</strong> ' . esc_html(implode(', ', $names)) . '</p>';
        }
        if ($axes) {
            $labels = array_values(array_filter(array_map(static function($row){ return sanitize_text_field((string)($row['label'] ?? '')); }, $axes)));
            if ($labels) echo '<p><strong>Diferencias documentadas:</strong> ' . esc_html(implode(', ', $labels)) . '</p>';
        }

        if ($refs) {
            echo '<details style="margin:12px 0"><summary><strong>Muestra interna de Ojeador</strong></summary>';
            echo '<table class="widefat striped" style="margin-top:10px"><thead><tr><th>Producto</th><th>Marca</th><th>Precio</th><th>Tienda</th></tr></thead><tbody>';
            foreach (array_slice($refs,0,12) as $row) {
                $price_text = is_numeric($row['price'] ?? null) ? number_format_i18n((float)$row['price'], 2) . ' ' . esc_html((string)($row['currency'] ?? 'EUR')) : '';
                echo '<tr><td>' . esc_html((string)($row['title'] ?? '')) . '</td><td>' . esc_html((string)($row['brand'] ?? '')) . '</td><td>' . esc_html($price_text) . '</td><td>' . esc_html((string)($row['merchant'] ?? '')) . '</td></tr>';
            }
            echo '</tbody></table></details>';
        }

        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_comparador_reanalyze"><input type="hidden" name="profile_id" value="' . esc_attr(absint($profile_id)) . '">';
        wp_nonce_field('seo_comparador_reanalyze_' . absint($profile_id));
        echo '<button class="button" type="submit">Reanalizar</button></form>';

        if ($status === 'proposal') {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_comparador_accept"><input type="hidden" name="profile_id" value="' . esc_attr(absint($profile_id)) . '">';
            wp_nonce_field('seo_comparador_accept_' . absint($profile_id));
            echo '<button class="button button-primary" type="submit">Aceptar y crear borrador</button></form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_comparador_reject"><input type="hidden" name="profile_id" value="' . esc_attr(absint($profile_id)) . '">';
            wp_nonce_field('seo_comparador_reject_' . absint($profile_id));
            echo '<button class="button" type="submit">Descartar</button></form>';
        }
        if (!empty($map['post_id']) && get_post(absint($map['post_id']))) {
            $url = add_query_arg(array('page'=>'seo-post-editor','post_id'=>absint($map['post_id'])), admin_url('edit.php'));
            echo '<a class="button button-primary" href="' . esc_url($url) . '">Abrir borrador / post</a>';
        }
        echo '</div></div>';
    }

    public static function render() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('No tienes permisos para usar Comparador.', 'seo-taxonomy'));
        SEO_Comparador_DB::maybe_install();
        self::render_notice();

        echo '<div class="wrap"><h1>Comparador <small style="font-weight:400;color:#646970">v' . esc_html(SEO_COMPARADOR_VERSION) . '</small></h1>';
        echo '<p><strong>Ojeador recopila el mercado; Comparador lo analiza y propone informes breves por categoría.</strong> No busca en Internet por su cuenta, no publica automáticamente y no necesita editar ejes manuales.</p>';

        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:14px 0">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_comparador_refresh">';
        wp_nonce_field('seo_comparador_refresh');
        echo '<button class="button button-primary" type="submit">Actualizar propuestas</button></form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_comparador_accept_all">';
        wp_nonce_field('seo_comparador_accept_all');
        echo '<button class="button" type="submit" onclick="return confirm(\'¿Crear borradores para todas las propuestas listas?\')">Aceptar todas las propuestas listas</button></form>';
        echo '</div>';

        self::render_kpis();

        $detail_id = absint($_GET['profile_id'] ?? 0);
        if ($detail_id) self::render_detail($detail_id);

        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : 'all';
        echo '<div style="margin:16px 0">';
        foreach (array('all'=>'Todas','proposal'=>'Propuestas','waiting_ojeador'=>'Esperando Ojeador','insufficient_market'=>'Muestra insuficiente','post_draft'=>'Borradores','published'=>'Publicados','needs_update'=>'Actualizar','closed'=>'Descartados') as $key=>$label) {
            $class = $status === $key ? 'button button-primary' : 'button';
            echo '<a class="' . esc_attr($class) . '" style="margin:0 6px 6px 0" href="' . esc_url(self::page_url(array('status'=>$key))) . '">' . esc_html($label) . '</a>';
        }
        echo '</div>';

        $rows = self::filtered_profiles();
        echo '<table class="widefat striped"><thead><tr><th>Categoría</th><th>Estado</th><th>Propios</th><th>Mercado</th><th>Acción</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="5">No hay perfiles con este filtro.</td></tr>';
        foreach ($rows as $row) {
            $profile_id = absint($row['id'] ?? 0);
            $row_status = sanitize_key((string)($row['status'] ?? ''));
            echo '<tr><td><strong>' . esc_html((string)($row['canonical_name'] ?? '')) . '</strong></td><td>' . esc_html(self::status_label($row_status)) . '</td><td>' . esc_html(number_format_i18n(absint($row['own_products_count'] ?? 0))) . '</td><td>' . esc_html(number_format_i18n(absint($row['external_products_comparable'] ?? 0))) . '</td><td><a class="button button-small" href="' . esc_url(self::page_url(array('status'=>$status,'profile_id'=>$profile_id))) . '">Ver propuesta</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
}

