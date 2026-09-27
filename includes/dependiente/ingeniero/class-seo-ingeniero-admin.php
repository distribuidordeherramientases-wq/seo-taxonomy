<?php
defined('ABSPATH') || exit;

final class SEO_Ingeniero_Admin {
    public static function init() {
        add_action('admin_post_seo_ingeniero_prepare', array(__CLASS__, 'handle_prepare'));
        add_action('admin_post_seo_ingeniero_control', array(__CLASS__, 'handle_control'));
        add_action('admin_post_seo_ingeniero_research_category', array(__CLASS__, 'handle_research_category'));
        add_action('admin_post_seo_ingeniero_review', array(__CLASS__, 'handle_review'));
        add_action('admin_post_seo_ingeniero_export', array(__CLASS__, 'handle_export'));
        add_action('admin_post_seo_ingeniero_settings', array(__CLASS__, 'handle_settings'));
    }

    private static function guard($action) {
        if (!current_user_can('manage_options')) wp_die(esc_html__('No tienes permisos para usar Ingeniero.', 'seo-taxonomy'));
        check_admin_referer($action);
    }

    private static function redirect($args = array()) {
        $base = add_query_arg(array('page'=>'seo-dependiente','tab'=>'engineer'), admin_url('admin.php'));
        wp_safe_redirect(add_query_arg((array) $args, $base));
        exit;
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
        self::redirect(array('term_id'=>$term_id,'ingeniero_notice'=>'reviewed'));
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

    public static function render_tab() {
        if (!current_user_can('manage_options')) return;
        SEO_Ingeniero_DB::maybe_install();

        $state = SEO_Ingeniero::state();
        $progress = SEO_Ingeniero::progress();
        $totals = SEO_Ingeniero_DB::totals();
        $stats_map = SEO_Ingeniero_DB::category_stats_map();
        $category_states = SEO_Ingeniero::category_states();
        $candidates = SEO_Ingeniero::category_candidates(100);
        $usage = SEO_Ingeniero_SerpApi_Provider::usage_month();
        $settings = SEO_Ingeniero_SerpApi_Provider::settings();
        $detail_term_id = absint($_GET['term_id'] ?? 0);

        self::render_notices();

        echo '<section class="seo-ingeniero">';
        echo '<div class="postbox seo-dependiente-admin__box" style="padding:18px">';
        echo '<h2 style="margin-top:0">Ingeniero</h2>';
        echo '<p>Conocimiento externo técnico por categoría para complementar el catálogo. <strong>V1 no publica contenido ni modifica las respuestas públicas de Dependiente.</strong> L1 usa documentación técnica; L2 de experiencia práctica está preparada pero desactivada.</p>';
        echo '<p class="description">Separación deliberada: <code>seo_dependiente_index</code> sigue representando nuestro catálogo; <code>seo_ingeniero_knowledge</code> conserva teoría externa trazable por fuentes.</p>';
        echo '</div>';

        self::render_kpis($candidates, $category_states, $totals, $state);

        echo '<div class="postbox seo-dependiente-admin__box" style="padding:18px">';
        echo '<h3 style="margin-top:0">Lección 1 · Documentación técnica</h3>';
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="seo_ingeniero_prepare">';
        wp_nonce_field('seo_ingeniero_prepare');
        echo '<label><strong>Categorías piloto</strong><br><input type="number" name="limit" min="1" max="100" value="20" class="small-text"></label> ';
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
        echo esc_html(number_format_i18n($progress['processed'])) . '/' . esc_html(number_format_i18n($progress['total'])) . ' categorías · lote adaptativo ' . esc_html(absint($state['batch_size'] ?? 1)) . '.</p>';
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

        self::render_category_table($candidates, $stats_map, $category_states);

        if ($detail_term_id) self::render_category_detail($detail_term_id);

        echo '<div class="postbox seo-dependiente-admin__box" style="padding:18px">';
        echo '<h3 style="margin-top:0">Lección 2 · Experiencia práctica</h3>';
        echo '<p><strong>Desactivada en v1.</strong> Queda reservada para foros, comunidades profesionales y experiencia de uso. Sus evidencias se marcarán como experiencia/opinión y nunca se elevarán automáticamente a hecho técnico.</p>';
        echo '</div>';

        echo '</section>';
    }

    private static function render_kpis($candidates, $category_states, $totals, $state) {
        $total = count((array) $candidates);
        $investigated = 0; $pending = 0; $review = 0; $errors = 0;
        $last = 0;
        foreach ((array) $candidates as $row) {
            $tid = absint($row['term_id'] ?? 0);
            $status = sanitize_key((string) ($category_states[$tid]['status'] ?? 'pendiente'));
            if (in_array($status, array('aprendido','revisar'), true)) $investigated++;
            if ('pendiente' === $status) $pending++;
            if ('revisar' === $status) $review++;
            if ('error' === $status) $errors++;
            $last = max($last, absint($category_states[$tid]['last_research_at'] ?? $category_states[$tid]['updated_at'] ?? 0));
        }
        $cards = array(
            'Categorías con productos'=>$total,
            'Investigadas'=>$investigated,
            'Pendientes'=>$pending,
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
    }

    private static function render_category_table($candidates, $stats_map, $category_states) {
        echo '<div class="postbox seo-dependiente-admin__box" style="padding:18px">';
        echo '<h3 style="margin-top:0">Categorías</h3>';
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
            echo '<td><a class="button button-small" href="' . esc_url(add_query_arg(array('page'=>'seo-dependiente','tab'=>'engineer','term_id'=>$tid), admin_url('admin.php'))) . '">Revisar conocimiento</a> ';
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
            echo '<td>' . esc_html((string) ($row['summary'] ?? '')) . '<details><summary>Ver evidencias</summary><pre style="white-space:pre-wrap">' . esc_html(wp_json_encode($row['facts'] ?? array(), JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) . '</pre></details></td>';
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

        echo '<h4 style="margin-top:20px">Fuentes</h4>';
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
            'settings'=>'Configuración guardada.',
        );
        if ($notice && isset($messages[$notice])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$notice]) . '</p></div>';
        }
    }
}

SEO_Ingeniero_Admin::init();
