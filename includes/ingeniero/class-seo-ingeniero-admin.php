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

    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para usar Ingeniero.', 'seo-taxonomy'));
        }

        SEO_Ingeniero_DB::maybe_install();
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'research';
        if (!in_array($tab, array('research', 'editorial', 'data'), true)) {
            $tab = 'research';
        }

        echo '<div class="wrap seo-ingeniero-admin">';
        echo '<h1>Ingeniero <small style="font-weight:400;color:#646970">v' . esc_html(SEO_INGENIERO_VERSION) . '</small></h1>';
        echo '<p><strong>Servicio técnico y editorial independiente.</strong> Investiga por categoría, conserva trazabilidad de fuentes y prepara dossiers técnicos para la Editora. No depende de Solucionador para decidir sus posts.</p>';
        echo '<nav class="nav-tab-wrapper" aria-label="Secciones de Ingeniero">';
        foreach (array('research'=>'Investigación', 'editorial'=>'Editorial', 'data'=>'Datos y fuentes') as $key=>$label) {
            echo '<a class="nav-tab ' . ($tab === $key ? 'nav-tab-active' : '') . '" href="' . esc_url(self::page_url($key)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';

        if ('editorial' === $tab) {
            self::render_editorial_tab();
        } elseif ('data' === $tab) {
            self::render_data_tab();
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
        echo '<p>Conocimiento externo técnico por categoría para complementar el catálogo. <strong>V1 no publica contenido ni modifica las respuestas públicas de Dependiente.</strong> Ingeniero consulta fuentes externas, crea una síntesis propia y conserva siempre la referencia al origen.</p>';
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
        echo '<strong>Lote actual:</strong> ' . esc_html(number_format_i18n($progress['processed'])) . '/' . esc_html(number_format_i18n($progress['total'])) . ' categorías · lote adaptativo ' . esc_html(absint($state['batch_size'] ?? 1)) . '.</p>';
        if (!empty($state['last_message'])) echo '<p class="description">' . esc_html((string) $state['last_message']) . '</p>';
        if (!empty($state['last_error'])) echo '<p style="color:#b32d2e"><strong>Último error:</strong> ' . esc_html((string) $state['last_error']) . '</p>';
        echo '</div>';

        if (class_exists('SEO_Ingeniero_Exchange')) {
            SEO_Ingeniero_Exchange::render_panel();
        }

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
        echo '<section class="seo-ingeniero-editorial">';
        echo '<div class="postbox" style="padding:18px;margin-top:16px">';
        echo '<h2 style="margin-top:0">Editorial</h2>';
        echo '<p>Esta pestaña prepara dossiers técnicos trazables a partir del conocimiento activo de Ingeniero. La persistencia editorial y el workflow se activarán con el esquema 0.2.0.</p>';
        echo '</div>';
        echo '</section>';
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
            echo '<p class="description">La tabla muestra las primeras ' . esc_html(number_format_i18n(count((array) $candidates))) . ' categorías de ' . esc_html(number_format_i18n($catalog_total)) . ' con productos, ordenadas por número de productos. Los KPI superiores sí usan el catálogo completo.</p>';
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
        if (!$candidates) echo '<tr><td colspan="8">No hay categorías con productos.</td></tr>';
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
        echo '<p>El conocimiento activo puede ser consultado por otros módulos mediante <code>SEO_Ingeniero::active_knowledge(' . esc_html($term_id) . ')</code>. V1 todavía no lo inyecta en las respuestas públicas.</p>';

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
            }
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$notice] . $suffix) . '</p></div>';
        }

        if (!empty($_GET['ingeniero_exchange_error'])) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Importar / Exportar conocimiento:</strong> ' . esc_html(rawurldecode((string) $_GET['ingeniero_exchange_error'])) . '</p></div>';
        }
    }
}

SEO_Ingeniero_Admin::init();
