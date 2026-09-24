<?php
/**
 * Solucionador - interfaz administrativa.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Admin {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_page'), 30);
        add_filter('seo_tools_items', array(__CLASS__, 'tool_card'), 30, 1);
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

    public static function tool_card($tools) {
        $tools = is_array($tools) ? $tools : array();
        $tools[] = array(
            'title' => 'Solucionador',
            'icon' => 'dashicons-lightbulb',
            'page' => 'seo-solucionador',
            'desc' => 'Convierte preguntas y senales en propuestas concretas de posts, ya clasificadas con Vocabulary y categorias.',
        );
        return $tools;
    }

    public static function parent_file($parent_file) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return $page === 'seo-solucionador' ? 'seo-system' : $parent_file;
    }

    public static function submenu_file($submenu_file) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return $page === 'seo-solucionador' ? 'seo-tools' : $submenu_file;
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
            'proposals' => 'Propuestas de posts',
            'coverage' => 'Cobertura de posts',
            'sources' => 'Fuentes',
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
            'candidate' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status IN ('candidate','approved') AND recommended_action='create_post'"),
            'drafts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='draft_created'"),
            'covered' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE coverage_status LIKE 'covered_%'"),
            'uncovered' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE coverage_status='uncovered'"),
            'expand' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE recommended_action IN ('expand_existing_post','create_section')"),
        );
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        SEO_Solucionador_DB::maybe_install();
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'summary';
        if (!in_array($tab, array('summary','proposals','coverage','sources'), true)) $tab = 'summary';

        echo '<div class="wrap seo-solucionador"><h1>Solucionador <small style="font-weight:400;color:#646970">v' . esc_html(SEO_SOLUCIONADOR_VERSION) . '</small></h1>';
        echo '<p>Analiza preguntas y senales, comprueba si ya existe una respuesta editorial y propone el post concreto que falta. La propuesta no es un post. Solo al aprobarla se crea un <strong>borrador</strong> con categorias y Vocabulary ya asignados.</p>';
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
        elseif ($tab === 'proposals') self::render_proposals();
        elseif ($tab === 'coverage') self::render_coverage();
        else self::render_sources();
        self::styles();
        echo '</div>';
    }

    private static function render_summary() {
        $counts = self::counts();
        $last = get_option('seo_solucionador_last_scan', array());
        echo '<div class="seo-sol-grid">';
        self::card('Temas detectados', $counts['total'] ?? 0, 'Problemas y procedimientos canonicos observados.');
        self::card('Sin cobertura', $counts['uncovered'] ?? 0, 'No se ha encontrado un post equivalente.');
        self::card('Posts propuestos', $counts['candidate'] ?? 0, 'Propuestas que pueden convertirse en borrador.');
        self::card('Borradores creados', $counts['drafts'] ?? 0, 'Propuestas aprobadas pendientes de contenido/publicacion.');
        self::card('Ampliar existentes', $counts['expand'] ?? 0, 'Conviene ampliar un post o crear una seccion.');
        self::card('Cubiertos', $counts['covered'] ?? 0, 'Existe cobertura editorial identificada.');
        echo '</div>';

        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Analizar ahora</h2>';
        echo '<p>Revisa Dependiente/Interprete y Comentarista como fuentes directas de necesidades. Analista y Auditor solo originan propuestas cuando contienen una pregunta/gap editorial explicito; el resto de sus senales solo refuerza temas ya detectados.</p>';
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
             WHERE status<>'dismissed' AND (recommended_action<>'no_action' OR status='draft_created')
             ORDER BY CASE WHEN status='draft_created' THEN 1 ELSE 0 END ASC,priority_score DESC,evidence_total DESC,id DESC
             LIMIT 250",
            ARRAY_A
        );

        echo '<div class="postbox" style="padding:18px;margin-top:18px"><h2 style="margin-top:0">Propuestas editoriales</h2>';
        echo '<p class="description">Cada fila es una propuesta, no una entrada de WordPress. Si se aprueba una propuesta de nuevo post, se crea un borrador vacio con el titulo, Vocabulary canonico y relaciones post_to_category ya preparados.</p>';
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
            echo '<td>D: ' . esc_html(number_format_i18n(absint($row['interpreter_evidence'] ?? 0)))
                . '<br>C: ' . esc_html(number_format_i18n(absint($row['comentarista_evidence'] ?? 0)))
                . '<br>A: ' . esc_html(number_format_i18n(absint($row['analyst_evidence'] ?? 0)))
                . '<br>U: ' . esc_html(number_format_i18n(absint($row['auditor_evidence'] ?? 0))) . '</td>';

            echo '<td><strong>' . esc_html(str_replace('_', ' ', (string) ($row['coverage_status'] ?? ''))) . '</strong>';
            if ($existing_post_id) echo '<br><a href="' . esc_url(SEO_Solucionador_Posts::edit_url($existing_post_id)) . '">Abrir post #' . esc_html($existing_post_id) . '</a>';
            if ($draft_post_id) echo '<br><a href="' . esc_url(SEO_Solucionador_Posts::edit_url($draft_post_id)) . '"><strong>Abrir borrador #' . esc_html($draft_post_id) . '</strong></a>';
            echo '</td>';

            echo '<td><strong>' . esc_html(str_replace('_', ' ', (string) ($row['recommended_action'] ?? ''))) . '</strong>';
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
        echo '<p><strong>Auditor:</strong> solo entran probes de comportamiento y gaps editoriales de una allowlist. Hallazgos tecnicos de indice, schema, excerpt, servidor, etc. quedan fuera.</p>';
        echo '<p><strong>Comentarista:</strong> aporta problemas o preguntas observadas en experiencias externas almacenadas.</p>';
        echo '<p><strong>Posts:</strong> forman el inventario de soluciones ya cubiertas. El post nuevo se relaciona con product_cat mediante SEO Relations y con el catalogo mediante Vocabulary.</p>';
        if ($counts) {
            echo '<h3>Evidencias acumuladas</h3><ul>';
            foreach ($counts as $row) echo '<li><strong>' . esc_html((string) $row['source_type']) . ':</strong> ' . esc_html(number_format_i18n(absint($row['evidence'] ?? 0))) . '</li>';
            echo '</ul>';
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
