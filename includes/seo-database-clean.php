<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * SEO System - Clean Database
 *
 * Herramienta de integridad y mantenimiento de base de datos.
 *
 * Principios:
 * - Nunca elimina productos, páginas ni términos de WordPress desde la pestaña de errores del plugin.
 * - Las acciones de limpieza BBDD sí eliminan residuos técnicos habituales de WordPress, siempre por acción manual.
 * - Antes de cualquier limpieza se ofrece export SQL completo o parcial.
 * - Todas las acciones destructivas usan nonce y capability manage_options.
 */

/**
 * Configuración principal de roles y relaciones SEO admitidas.
 */
function seo_clean_db_get_config() {
    $node_roles = array(
        'cluster',
        'hub_primary',
        'hub_secondary',
        'landing',
    );

    $relation_schemas = array(
        'cluster_to_primary' => array(
            'source_type' => 'cluster',
            'target_type' => 'hub_primary',
            'scope' => 'structure',
        ),
        'cluster_to_hub_primary' => array(
            'source_type' => 'cluster',
            'target_type' => 'hub_primary',
            'scope' => 'structure',
        ),
        'hub_primary_to_hub_secondary' => array(
            'source_type' => 'hub_primary',
            'target_type' => 'hub_secondary',
            'scope' => 'structure',
        ),
        'hub_secondary_to_category' => array(
            'source_type' => 'hub_secondary',
            'target_type' => 'product_cat',
            'scope' => 'structure',
        ),
        'cluster_to_category' => array(
            'source_type' => 'cluster',
            'target_type' => 'product_cat',
            'scope' => 'marketing',
        ),
        'hub_primary_to_category' => array(
            'source_type' => 'hub_primary',
            'target_type' => 'product_cat',
            'scope' => 'marketing',
        ),
        'landing_to_category' => array(
            'source_type' => 'landing',
            'target_type' => 'product_cat',
            'scope' => 'marketing',
        ),
    );

    return array(
        'node_roles' => apply_filters('seo_clean_db_node_roles', $node_roles),
        'relation_schemas' => apply_filters('seo_clean_db_relation_schemas', $relation_schemas),
        'report_limit' => (int) apply_filters('seo_clean_db_report_limit', 250),
    );
}

/**
 * Página principal con router de pestañas y gestión de acciones POST/GET.
 */
function seo_clean_db_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    seo_clean_db_maybe_export_sql();

    $active_tab = seo_clean_db_get_active_tab();
    $notice = seo_clean_db_handle_post_actions();

    echo '<div class="wrap seo-clean-db-wrap">';
    echo '<h1>Clean Database</h1>';
    echo '<p>Auditoría, reparación y limpieza controlada de datos del plugin, WordPress y WooCommerce.</p>';

    seo_clean_db_render_styles();
    seo_clean_db_render_tabs($active_tab);

    if ($notice) {
        seo_clean_db_render_notice($notice['type'], $notice['message']);
    }

    echo '<div class="seo-clean-db-panel">';

    switch ($active_tab) {
        case 'database_cleanup':
            seo_clean_db_render_database_cleanup_tab();
            break;
        case 'export':
            seo_clean_db_render_export_tab();
            break;
        case 'data_errors':
        default:
            seo_clean_db_render_data_errors_tab();
            break;
    }

    echo '</div>';
    echo '</div>';
}

/**
 * Devuelve la pestaña activa.
 */
function seo_clean_db_get_active_tab() {
    $allowed_tabs = array('data_errors', 'database_cleanup', 'export');
    $tab = isset($_GET['seo_clean_db_tab']) ? sanitize_key(wp_unslash($_GET['seo_clean_db_tab'])) : 'data_errors';
    return in_array($tab, $allowed_tabs, true) ? $tab : 'data_errors';
}

/**
 * Renderiza las pestañas superiores.
 */
function seo_clean_db_render_tabs($active_tab) {
    $tabs = array(
        'data_errors' => 'Errores en datos',
        'database_cleanup' => 'Limpieza BBDD',
        'export' => 'Exportar BBDD',
    );

    echo '<nav class="nav-tab-wrapper seo-clean-db-tabs">';

    foreach ($tabs as $tab_id => $label) {
        $class = ($active_tab === $tab_id) ? ' nav-tab-active' : '';
        $url = add_query_arg('seo_clean_db_tab', $tab_id);
        echo '<a href="' . esc_url($url) . '" class="nav-tab' . esc_attr($class) . '">' . esc_html($label) . '</a>';
    }

    echo '</nav>';
}

/**
 * Estilos internos para mantener el archivo independiente.
 */
function seo_clean_db_render_styles() {
    echo '<style>
        .seo-clean-db-panel{background:#fff;border:1px solid #ccd0d4;border-top:none;padding:20px;max-width:1280px;}
        .seo-clean-db-tabs{margin-top:18px;}
        .seo-clean-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin:20px 0;}
        .seo-clean-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px;margin:0 0 18px;max-width:none;}
        .seo-clean-kpi{font-size:26px;font-weight:600;line-height:1.2;}
        .seo-clean-muted{color:#646970;}
        .seo-clean-warning-box{border-left:4px solid #dba617;background:#fff8e5;padding:12px 14px;margin:16px 0;}
        .seo-clean-danger-box{border-left:4px solid #d63638;background:#fcf0f1;padding:12px 14px;margin:16px 0;}
        .seo-clean-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:10px;}
        .seo-clean-table td,.seo-clean-table th{vertical-align:top;}
        .seo-clean-badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap;}
        .seo-clean-ok{background:#d1e7dd;color:#0f5132;}
        .seo-clean-warning{background:#fff3cd;color:#664d03;}
        .seo-clean-error{background:#f8d7da;color:#842029;}
        .seo-clean-info{background:#cff4fc;color:#055160;}
    </style>';
}

/**
 * Comprueba si una tabla existe.
 */
function seo_clean_db_table_exists($table_name) {
    global $wpdb;

    $found = $wpdb->get_var(
        $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name))
    );

    return $found === $table_name;
}

/**
 * Renderiza un aviso de WordPress.
 */
function seo_clean_db_render_notice($type, $message) {
    $allowed = array('success', 'warning', 'error', 'info');

    if (!in_array($type, $allowed, true)) {
        $type = 'info';
    }

    echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . wp_kses_post($message) . '</p></div>';
}

/**
 * Renderiza un badge visual sencillo.
 */
function seo_clean_db_badge($status, $label) {
    $classes = array(
        'ok' => 'seo-clean-ok',
        'warning' => 'seo-clean-warning',
        'error' => 'seo-clean-error',
        'info' => 'seo-clean-info',
    );

    $class = isset($classes[$status]) ? $classes[$status] : $classes['info'];
    return '<span class="seo-clean-badge ' . esc_attr($class) . '">' . esc_html($label) . '</span>';
}

/**
 * Crea un formulario de acción POST con nonce.
 */
function seo_clean_db_render_action_form($action, $label, $button_class, $confirm_message = '', $extra_fields = array()) {
    echo '<form method="post" style="display:inline-block;margin:0;">';
    wp_nonce_field('seo_clean_db_action', 'seo_clean_db_nonce');
    echo '<input type="hidden" name="seo_clean_db_action" value="' . esc_attr($action) . '">';

    foreach ($extra_fields as $name => $value) {
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
    }

    $confirm_attribute = '';

    if ($confirm_message !== '') {
        $confirm_attribute = ' onclick="return confirm(' . esc_attr(wp_json_encode($confirm_message)) . ');"';
    }

    echo '<button type="submit" class="' . esc_attr($button_class) . '"' . $confirm_attribute . '>' . esc_html($label) . '</button>';
    echo '</form>';
}

/**
 * Crea un enlace de exportación GET con nonce.
 */
function seo_clean_db_export_url($type) {
    return wp_nonce_url(
        add_query_arg(
            array(
                'seo_clean_db_export' => $type,
                'seo_clean_db_tab' => 'export',
            )
        ),
        'seo_clean_db_export_' . $type,
        'seo_clean_db_export_nonce'
    );
}

/**
 * Intenta lanzar un export SQL antes de renderizar la página.
 */
function seo_clean_db_maybe_export_sql() {
    if (!isset($_GET['seo_clean_db_export'])) {
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para exportar la base de datos.', 'seo-system'));
    }

    $type = sanitize_key(wp_unslash($_GET['seo_clean_db_export']));
    check_admin_referer('seo_clean_db_export_' . $type, 'seo_clean_db_export_nonce');

    if (!in_array($type, array('full', 'seo'), true)) {
        wp_die(esc_html__('Tipo de exportación no válido.', 'seo-system'));
    }

    seo_clean_db_stream_sql_export($type);
    exit;
}

/**
 * Genera un SQL descargable de tablas completas o solo tablas del plugin.
 */
function seo_clean_db_stream_sql_export($type) {
    global $wpdb;

    @set_time_limit(0);
    nocache_headers();

    $tables = seo_clean_db_get_export_tables($type);
    $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
    $site_host = $site_host ? sanitize_file_name($site_host) : 'wordpress';
    $filename = $site_host . '-' . $type . '-backup-' . gmdate('Ymd-His') . '.sql';

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "-- SEO System database export\n";
    echo "-- Type: " . $type . "\n";
    echo "-- Site: " . home_url() . "\n";
    echo "-- Generated: " . gmdate('Y-m-d H:i:s') . " UTC\n\n";
    echo "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    echo "SET time_zone = \"+00:00\";\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $table) {
        seo_clean_db_stream_table_sql($table);
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
}

/**
 * Devuelve las tablas exportables según el tipo elegido.
 */
function seo_clean_db_get_export_tables($type) {
    global $wpdb;

    $all_tables = $wpdb->get_col('SHOW TABLES');

    if ($type === 'full') {
        return array_values(array_filter($all_tables, function ($table) use ($wpdb) {
            return strpos($table, $wpdb->prefix) === 0;
        }));
    }

    return array_values(array_filter($all_tables, function ($table) use ($wpdb) {
        return strpos($table, $wpdb->prefix . 'seo_') === 0;
    }));
}

/**
 * Vuelca una tabla en SQL con estructura e inserts por lotes.
 */
function seo_clean_db_stream_table_sql($table) {
    global $wpdb;

    $create = $wpdb->get_row('SHOW CREATE TABLE `' . esc_sql($table) . '`', ARRAY_N);

    if (!$create || empty($create[1])) {
        return;
    }

    echo "\n-- --------------------------------------------------------\n";
    echo "-- Table structure for `" . $table . "`\n";
    echo "-- --------------------------------------------------------\n\n";
    echo "DROP TABLE IF EXISTS `" . $table . "`;\n";
    echo $create[1] . ";\n\n";

    $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . esc_sql($table) . '`');

    if ($count <= 0) {
        return;
    }

    echo "-- Data for `" . $table . "`\n\n";

    $limit = 500;
    $offset = 0;

    while ($offset < $count) {
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM `' . esc_sql($table) . '` LIMIT %d OFFSET %d', $limit, $offset),
            ARRAY_A
        );

        if (empty($rows)) {
            break;
        }

        foreach ($rows as $row) {
            $columns = array_map(function ($column) {
                return '`' . str_replace('`', '``', $column) . '`';
            }, array_keys($row));

            $values = array_map(function ($value) use ($wpdb) {
                if ($value === null) {
                    return 'NULL';
                }

                return "'" . $wpdb->_real_escape((string) $value) . "'";
            }, array_values($row));

            echo 'INSERT INTO `' . $table . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n";
        }

        $offset += $limit;
        flush();
    }

    echo "\n";
}

/**
 * Gestiona acciones POST de todas las pestañas.
 */
function seo_clean_db_handle_post_actions() {
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['seo_clean_db_action'])) {
        return null;
    }

    if (!current_user_can('manage_options')) {
        return array('type' => 'error', 'message' => 'No tienes permisos para ejecutar esta acción.');
    }

    check_admin_referer('seo_clean_db_action', 'seo_clean_db_nonce');

    $action = sanitize_key(wp_unslash($_POST['seo_clean_db_action']));

    switch ($action) {
        case 'clean_invalid_relations':
        case 'clean_stale_nodes':
        case 'clean_duplicate_relations':
        case 'clean_duplicate_node_rows':
        case 'fix_category_hierarchy':
        case 'reset_registered_object':
            return seo_clean_db_handle_data_error_action($action);

        case 'rollback_operation':
            return seo_clean_db_handle_rollback_action();

        case 'unlock_bulk_accept_lock':
            return seo_clean_db_handle_unlock_bulk_accept_lock();

        case 'clean_revisions':
        case 'clean_auto_drafts':
        case 'clean_trashed_posts':
        case 'clean_spam_comments':
        case 'clean_trashed_comments':
        case 'clean_expired_transients':
        case 'clean_orphan_postmeta':
        case 'clean_orphan_commentmeta':
        case 'clean_orphan_termmeta':
        case 'clean_orphan_term_relationships':
        case 'clean_wc_sessions':
            return seo_clean_db_handle_database_cleanup_action($action);
    }

    return array('type' => 'warning', 'message' => 'Acción no reconocida.');
}

/**
 * Devuelve el bloqueo actual de la aceptación masiva de proveedores.
 *
 * @return array|false
 */
function seo_clean_db_get_bulk_accept_lock() {
    return get_option('seo_proveedores_bulk_accept_lock', false);
}

/**
 * Elimina manualmente el bloqueo de aceptación masiva.
 *
 * La acción compara el token mostrado en pantalla con el token actual para
 * evitar eliminar por accidente un bloqueo creado por un proceso más reciente.
 */
function seo_clean_db_handle_unlock_bulk_accept_lock() {
    $option_name = 'seo_proveedores_bulk_accept_lock';
    $lock = get_option($option_name, false);

    if ($lock === false) {
        return array(
            'type' => 'info',
            'message' => 'El bloqueo de aceptación masiva ya no existe.',
        );
    }

    $posted_token = isset($_POST['bulk_accept_lock_token'])
        ? sanitize_text_field(wp_unslash($_POST['bulk_accept_lock_token']))
        : '';

    $current_token = is_array($lock) && isset($lock['token'])
        ? (string) $lock['token']
        : '';

    if ($current_token !== '' && ($posted_token === '' || !hash_equals($current_token, $posted_token))) {
        return array(
            'type' => 'error',
            'message' => 'El bloqueo ha cambiado desde que se cargó la pantalla. Recarga la página antes de intentar desbloquearlo.',
        );
    }

    $deleted = delete_option($option_name);

    if (!$deleted) {
        return array(
            'type' => 'error',
            'message' => 'No se pudo eliminar el bloqueo de aceptación masiva.',
        );
    }

    return array(
        'type' => 'success',
        'message' => 'Bloqueo de aceptación masiva eliminado. Ya puedes volver a iniciar el proceso.',
    );
}

/**
 * Muestra el estado del bloqueo de aceptación masiva y permite eliminarlo.
 */
function seo_clean_db_render_bulk_accept_lock_section() {
    $option_name = 'seo_proveedores_bulk_accept_lock';
    $lock = seo_clean_db_get_bulk_accept_lock();

    echo '<div class="seo-clean-card">';
    echo '<h2>Bloqueo de aceptación masiva de proveedores</h2>';
    echo '<p>Este bloqueo evita que dos aceptaciones masivas procesen los mismos productos al mismo tiempo.</p>';

    if ($lock === false || $lock === null || $lock === '') {
        seo_clean_db_render_notice('success', 'No existe ningún bloqueo activo de aceptación masiva.');
        echo '</div>';
        return;
    }

    $token = is_array($lock) && isset($lock['token']) ? (string) $lock['token'] : '';
    $user_id = is_array($lock) && isset($lock['user_id']) ? absint($lock['user_id']) : 0;
    $started = is_array($lock) && isset($lock['started']) ? absint($lock['started']) : 0;
    $user = $user_id > 0 ? get_userdata($user_id) : false;

    $user_label = $user
        ? $user->display_name . ' (ID ' . $user_id . ')'
        : ($user_id > 0 ? 'Usuario ID ' . $user_id : 'No disponible');

    $started_label = $started > 0
        ? wp_date('d/m/Y H:i:s', $started)
        : 'No disponible';

    $age_label = ($started > 0 && $started <= time())
        ? human_time_diff($started, time())
        : 'No disponible';

    $token_label = $token;
    if (strlen($token_label) > 16) {
        $token_label = substr($token_label, 0, 8) . '…' . substr($token_label, -4);
    }

    seo_clean_db_render_notice(
        'warning',
        '<strong>Existe un bloqueo activo.</strong> Comprueba que no haya otra aceptación masiva ejecutándose antes de eliminarlo.'
    );

    echo '<table class="widefat striped seo-clean-table" style="max-width:850px;margin:15px 0;">';
    echo '<tbody>';
    echo '<tr><th style="width:230px;">Opción de WordPress</th><td><code>' . esc_html($option_name) . '</code></td></tr>';
    echo '<tr><th>Usuario que inició el proceso</th><td>' . esc_html($user_label) . '</td></tr>';
    echo '<tr><th>Fecha de inicio</th><td>' . esc_html($started_label) . '</td></tr>';
    echo '<tr><th>Antigüedad</th><td>' . esc_html($age_label) . '</td></tr>';
    echo '<tr><th>Token</th><td><code>' . esc_html($token_label !== '' ? $token_label : 'No disponible') . '</code></td></tr>';
    echo '</tbody>';
    echo '</table>';

    echo '<div class="seo-clean-actions">';
    seo_clean_db_render_action_form(
        'unlock_bulk_accept_lock',
        'Desbloquear aceptación masiva',
        'button button-secondary',
        'Elimina el bloqueo únicamente si has comprobado que no hay otra aceptación masiva ejecutándose. ¿Continuar?',
        array('bulk_accept_lock_token' => $token)
    );
    echo '</div>';
    echo '</div>';
}

/**
 * Devuelve el estado de un post/página/producto.
 */
function seo_clean_db_get_post_state($post_id) {
    static $cache = array();

    $post_id = (int) $post_id;

    if (isset($cache[$post_id])) {
        return $cache[$post_id];
    }

    $post = get_post($post_id);

    if (!$post) {
        $cache[$post_id] = array('valid' => false, 'reason' => 'missing_wordpress_object', 'title' => '', 'status' => '');
        return $cache[$post_id];
    }

    if ($post->post_status === 'trash') {
        $cache[$post_id] = array('valid' => false, 'reason' => 'trashed_wordpress_object', 'title' => $post->post_title, 'status' => $post->post_status);
        return $cache[$post_id];
    }

    $cache[$post_id] = array('valid' => true, 'reason' => '', 'title' => $post->post_title, 'status' => $post->post_status);
    return $cache[$post_id];
}

/**
 * Devuelve el estado de un término.
 */
function seo_clean_db_get_term_state($term_id, $taxonomy) {
    static $cache = array();

    $term_id = (int) $term_id;
    $cache_key = $taxonomy . ':' . $term_id;

    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    $term = get_term($term_id, $taxonomy);

    if (!$term || is_wp_error($term)) {
        $cache[$cache_key] = array('valid' => false, 'reason' => 'missing_term', 'title' => '');
        return $cache[$cache_key];
    }

    $cache[$cache_key] = array('valid' => true, 'reason' => '', 'title' => $term->name);
    return $cache[$cache_key];
}

/**
 * Obtiene todos los nodos SEO.
 */
function seo_clean_db_get_nodes($nodes_table) {
    global $wpdb;

    return $wpdb->get_results(
        "SELECT id, object_type, object_id, seo_role, status
         FROM {$nodes_table}
         ORDER BY id ASC"
    );
}

/**
 * Construye un índice role:id para validar relaciones.
 */
function seo_clean_db_build_node_index($nodes) {
    $index = array();

    foreach ($nodes as $node) {
        $key = $node->seo_role . ':' . (int) $node->object_id;

        if (!isset($index[$key])) {
            $index[$key] = $node;
        }
    }

    return $index;
}

/**
 * Valida si el objeto WordPress asociado a un nodo sigue existiendo.
 */
function seo_clean_db_validate_registered_node_object($node) {
    $object_type = isset($node->object_type) ? sanitize_key((string) $node->object_type) : '';
    $object_id = isset($node->object_id) ? (int) $node->object_id : 0;
    $seo_role = isset($node->seo_role) ? sanitize_key((string) $node->seo_role) : '';

    if ($object_id <= 0) {
        return array('valid' => false, 'reason' => 'invalid_id', 'title' => '');
    }

    /*
     * Algunas instalaciones historicas guardan nodos de categorias con
     * object_type distintos de product_cat (por ejemplo category o term).
     * Esos IDs pertenecen a wp_terms, no a wp_posts.
     */
    $term_object_types = array(
        'product_cat',
        'category',
        'term',
        'product_category',
        'woocommerce_category',
    );

    if (in_array($object_type, $term_object_types, true)) {
        return seo_clean_db_get_term_state($object_id, 'product_cat');
    }

    $post_state = seo_clean_db_get_post_state($object_id);

    if ($post_state['valid']) {
        return $post_state;
    }

    /*
     * Compatibilidad con registros antiguos donde object_type no identifica
     * correctamente que el objeto era una categoria. Solo aplicamos el
     * fallback a roles auxiliares usados para contenido de categorias.
     */
    $category_aux_roles = array('category', 'excerpt', 'description', 'ambito');

    if (in_array($seo_role, $category_aux_roles, true)) {
        $term_state = seo_clean_db_get_term_state($object_id, 'product_cat');

        if ($term_state['valid']) {
            return $term_state;
        }
    }

    return $post_state;
}

/**
 * Valida un extremo de una relación SEO.
 */
function seo_clean_db_validate_endpoint($type, $id, $node_index, $node_roles) {
    $type = (string) $type;
    $id = (int) $id;

    if ($id <= 0) {
        return array('valid' => false, 'severity' => 'error', 'code' => 'invalid_id', 'title' => '');
    }

    if (in_array($type, $node_roles, true)) {
        $key = $type . ':' . $id;

        if (!isset($node_index[$key])) {
            /*
             * Un cluster, hub o landing puede seguir siendo perfectamente
             * valido aunque su fila auxiliar de seo_nodes falte o aun no se
             * haya sincronizado. Validamos primero el objeto WordPress real.
             * Si existe, conservamos la relacion y emitimos solo un aviso.
             */
            $post_state = seo_clean_db_get_post_state($id);

            if ($post_state['valid']) {
                return array(
                    'valid' => true,
                    'severity' => 'warning',
                    'code' => 'missing_seo_node',
                    'title' => isset($post_state['title']) ? $post_state['title'] : '',
                );
            }

            return array(
                'valid' => false,
                'severity' => 'error',
                'code' => $post_state['reason'],
                'title' => isset($post_state['title']) ? $post_state['title'] : '',
            );
        }

        $object_state = seo_clean_db_validate_registered_node_object($node_index[$key]);

        if (!$object_state['valid']) {
            return array('valid' => false, 'severity' => 'error', 'code' => $object_state['reason'], 'title' => isset($object_state['title']) ? $object_state['title'] : '');
        }

        return array('valid' => true, 'severity' => '', 'code' => '', 'title' => isset($object_state['title']) ? $object_state['title'] : '');
    }

    if ($type === 'product_cat') {
        $term_state = seo_clean_db_get_term_state($id, 'product_cat');
        return array('valid' => $term_state['valid'], 'severity' => $term_state['valid'] ? '' : 'error', 'code' => $term_state['reason'], 'title' => $term_state['title']);
    }

    return array('valid' => false, 'severity' => 'warning', 'code' => 'unknown_endpoint_type', 'title' => '');
}

/**
 * Analiza relaciones inválidas o sospechosas.
 */
function seo_clean_db_analyze_relations($relations_table, $node_index, $config) {
    global $wpdb;

    $relations = $wpdb->get_results(
        "SELECT id, source_type, source_id, target_type, target_id, relation_type, created_at
         FROM {$relations_table}
         ORDER BY id ASC"
    );

    $invalid = array();
    $warnings = array();

    foreach ($relations as $relation) {
        $issues = array();
        $warning_issues = array();

        $source_state = seo_clean_db_validate_endpoint($relation->source_type, $relation->source_id, $node_index, $config['node_roles']);
        $target_state = seo_clean_db_validate_endpoint($relation->target_type, $relation->target_id, $node_index, $config['node_roles']);

        if (!$source_state['valid']) {
            $issue = array('side' => 'source', 'code' => $source_state['code']);
            $source_state['severity'] === 'warning' ? $warning_issues[] = $issue : $issues[] = $issue;
        } elseif ($source_state['severity'] === 'warning' && !empty($source_state['code'])) {
            $warning_issues[] = array('side' => 'source', 'code' => $source_state['code']);
        }

        if (!$target_state['valid']) {
            $issue = array('side' => 'target', 'code' => $target_state['code']);
            $target_state['severity'] === 'warning' ? $warning_issues[] = $issue : $issues[] = $issue;
        } elseif ($target_state['severity'] === 'warning' && !empty($target_state['code'])) {
            $warning_issues[] = array('side' => 'target', 'code' => $target_state['code']);
        }

        if (empty($relation->relation_type)) {
            $issues[] = array('side' => 'relation', 'code' => 'missing_relation_type');
        } elseif (isset($config['relation_schemas'][$relation->relation_type])) {
            $schema = $config['relation_schemas'][$relation->relation_type];

            if ($relation->source_type !== $schema['source_type'] || $relation->target_type !== $schema['target_type']) {
                $issues[] = array('side' => 'relation', 'code' => 'relation_schema_mismatch');
            }
        } else {
            $warning_issues[] = array('side' => 'relation', 'code' => 'unknown_relation_type');
        }

        $record = array(
            'relation' => $relation,
            'source_title' => $source_state['title'],
            'target_title' => $target_state['title'],
        );

        if (!empty($issues)) {
            $record['issues'] = $issues;
            $invalid[] = $record;
        }

        if (!empty($warning_issues)) {
            $record['issues'] = $warning_issues;
            $warnings[] = $record;
        }
    }

    return array('invalid' => $invalid, 'warnings' => $warnings, 'total' => count($relations));
}

/**
 * Detecta nodos cuyo objeto WordPress ya no existe o está en papelera.
 */
function seo_clean_db_get_stale_nodes($nodes) {
    $stale = array();

    foreach ($nodes as $node) {
        $state = seo_clean_db_validate_registered_node_object($node);

        if (!$state['valid']) {
            $stale[] = array('node' => $node, 'reason' => $state['reason'], 'title' => isset($state['title']) ? $state['title'] : '');
        }
    }

    return $stale;
}

/**
 * Detecta relaciones exactas duplicadas.
 */
function seo_clean_db_get_duplicate_relations($relations_table) {
    global $wpdb;

    return $wpdb->get_results(
        "SELECT MIN(id) AS keep_id, GROUP_CONCAT(id ORDER BY id ASC SEPARATOR ',') AS relation_ids,
            source_type, source_id, target_type, target_id, relation_type, COUNT(*) AS total
         FROM {$relations_table}
         GROUP BY source_type, source_id, target_type, target_id, relation_type
         HAVING COUNT(*) > 1
         ORDER BY total DESC, keep_id ASC"
    );
}

/**
 * Detecta nodos duplicados con el mismo tipo de objeto, objeto y rol.
 *
 * object_type forma parte de la identidad lógica del nodo. Un producto y una
 * categoría pueden compartir el mismo ID numérico sin ser el mismo objeto.
 */
function seo_clean_db_get_duplicate_node_rows($nodes_table) {
    global $wpdb;

    return $wpdb->get_results(
        "SELECT MAX(id) AS keep_id, GROUP_CONCAT(id ORDER BY id ASC SEPARATOR ',') AS node_ids,
            object_type, object_id, seo_role, COUNT(*) AS total
         FROM {$nodes_table}
         GROUP BY object_type, object_id, seo_role
         HAVING COUNT(*) > 1
         ORDER BY total DESC, object_type ASC, object_id ASC"
    );
}

/**
 * Detecta productos con más de un ámbito registrado.
 *
 * En seo_nodes es legal que un producto tenga filas auxiliares como product,
 * ambito, description y excerpt. Lo anómalo es que el mismo producto tenga
 * más de una fila con seo_role = ambito.
 */
function seo_clean_db_get_multiple_ambito_products($nodes_table) {
    global $wpdb;

    return $wpdb->get_results(
        "SELECT object_type, object_id,
            COUNT(*) AS total_ambitos,
            GROUP_CONCAT(id ORDER BY id ASC SEPARATOR ',') AS node_ids,
            GROUP_CONCAT(COALESCE(keywords, '') ORDER BY id ASC SEPARATOR ' | ') AS ambitos
         FROM {$nodes_table}
         WHERE object_type = 'product'
           AND seo_role = 'ambito'
         GROUP BY object_type, object_id
         HAVING COUNT(*) > 1
         ORDER BY total_ambitos DESC, object_id ASC"
    );
}

/**
 * Detecta categorías de producto cuyo padre ya no existe.
 */
function seo_clean_db_get_broken_categories() {
    global $wpdb;

    return $wpdb->get_results(
        "SELECT t.term_id, t.name, t.slug, tt.parent
         FROM {$wpdb->term_taxonomy} tt
         INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
         LEFT JOIN {$wpdb->term_taxonomy} parent ON parent.term_id = tt.parent AND parent.taxonomy = tt.taxonomy
         WHERE tt.taxonomy = 'product_cat'
           AND tt.parent <> 0
           AND parent.term_id IS NULL
         ORDER BY t.name ASC"
    );
}

/**
 * Comprueba si la capa de datos transaccional está disponible.
 */
function seo_clean_db_data_layer_available() {
    return class_exists('SEO_Data_Layer')
        && class_exists('SEO_Data_Operation')
        && class_exists('SEO_Data_Rollback');
}

/**
 * Crea una operación auditable de Clean Database.
 */
function seo_clean_db_begin_operation($type, $label, $risk_level, $expected_changes, $metadata = array()) {
    if (!seo_clean_db_data_layer_available()) {
        throw new RuntimeException('La capa de datos transaccional no está disponible.');
    }

    $operation = SEO_Data_Layer::operation(array(
        'type' => sanitize_key($type),
        'label' => sanitize_text_field($label),
        'source_module' => 'clean_database',
        'rollbackable' => true,
        'risk_level' => sanitize_key($risk_level),
        'audit_level' => 'full',
        'metadata' => is_array($metadata) ? $metadata : array(),
    ));

    $operation->mark_validated(array(
        'validated_by' => get_current_user_id(),
        'validated_from' => 'clean_database',
    ));

    $operation->mark_previewed(
        max(0, (int) $expected_changes),
        array(
            'preview_generated_at' => current_time('mysql', true),
        )
    );

    return $operation;
}

/**
 * Ejecuta una eliminación reversible por IDs en una tabla registrada.
 *
 * @return array{deleted:int,operation_id:int}
 */
function seo_clean_db_transactional_delete_ids($table_key, $ids, $operation_args = array(), $context_callback = null) {
    $ids = array_values(array_unique(array_filter(array_map('absint', (array) $ids))));

    if (empty($ids)) {
        return array('deleted' => 0, 'operation_id' => 0);
    }

    $defaults = array(
        'type' => 'clean_database_delete',
        'label' => 'Limpieza controlada de datos',
        'risk_level' => 'high',
        'metadata' => array(),
    );

    $operation_args = wp_parse_args($operation_args, $defaults);
    $operation = seo_clean_db_begin_operation(
        $operation_args['type'],
        $operation_args['label'],
        $operation_args['risk_level'],
        count($ids),
        $operation_args['metadata']
    );

    $deleted = $operation->execute(
        function ($transaction) use ($table_key, $ids, $context_callback) {
            $count = 0;

            foreach ($ids as $id) {
                $context = is_callable($context_callback)
                    ? (array) call_user_func($context_callback, $id)
                    : array();

                $transaction->delete(
                    $table_key,
                    array('id' => (int) $id),
                    $context
                );

                $count++;
            }

            return $count;
        }
    );

    return array(
        'deleted' => (int) $deleted,
        'operation_id' => (int) $operation->id(),
    );
}

/**
 * Devuelve los IDs de relaciones vinculadas a objetos y roles concretos.
 */
function seo_clean_db_get_relation_ids_for_objects($relations_table, $objects_by_role) {
    global $wpdb;

    $ids = array();

    foreach ($objects_by_role as $role => $object_ids) {
        $object_ids = array_values(array_unique(array_filter(array_map('absint', (array) $object_ids))));

        if (empty($object_ids)) {
            continue;
        }

        $placeholders = implode(', ', array_fill(0, count($object_ids), '%d'));
        $sql = "SELECT id
                FROM {$relations_table}
                WHERE (source_type = %s AND source_id IN ({$placeholders}))
                   OR (target_type = %s AND target_id IN ({$placeholders}))";

        $args = array_merge(array($role), $object_ids, array($role), $object_ids);
        $prepared = call_user_func_array(array($wpdb, 'prepare'), array_merge(array($sql), $args));
        $found = $wpdb->get_col($prepared);

        foreach ((array) $found as $id) {
            $ids[] = (int) $id;
        }
    }

    return array_values(array_unique($ids));
}

/**
 * Devuelve operaciones recientes de Clean Database.
 */
function seo_clean_db_get_recent_operations($limit = 20) {
    global $wpdb;

    if (!seo_clean_db_data_layer_available()) {
        return array();
    }

    $limit = max(1, min(100, (int) $limit));

    return $wpdb->get_results(
        $wpdb->prepare(
            'SELECT id, operation_uuid, operation_type, operation_label, status,
                    rollback_status, rollbackable, risk_level, user_id,
                    created_at, completed_at, rolled_back_at, rolled_back_by,
                    affected_rows, error_message
             FROM `' . SEO_Data_Layer::operations_table() . '`
             WHERE source_module = %s
             ORDER BY id DESC
             LIMIT %d',
            'clean_database',
            $limit
        )
    );
}

/**
 * Ejecuta un rollback solicitado por el administrador.
 */
function seo_clean_db_handle_rollback_action() {
    if (!seo_clean_db_data_layer_available()) {
        return array('type' => 'error', 'message' => 'La capa de rollback no está disponible.');
    }

    $operation_id = isset($_POST['operation_id']) ? absint($_POST['operation_id']) : 0;

    if ($operation_id <= 0) {
        return array('type' => 'error', 'message' => 'Operación de rollback no válida.');
    }

    global $wpdb;

    $source_module = $wpdb->get_var(
        $wpdb->prepare(
            'SELECT source_module
             FROM `' . SEO_Data_Layer::operations_table() . '`
             WHERE id = %d',
            $operation_id
        )
    );

    if ($source_module !== 'clean_database') {
        return array('type' => 'error', 'message' => 'La operación no pertenece a Clean Database.');
    }

    try {
        $preview = SEO_Data_Rollback::preview($operation_id);

        if (empty($preview['allowed'])) {
            $messages = !empty($preview['errors']) ? implode(' ', $preview['errors']) : 'Se han detectado conflictos.';
            return array(
                'type' => 'error',
                'message' => 'Rollback bloqueado: ' . esc_html($messages)
                    . ' Conflictos detectados: <strong>' . (int) $preview['conflicts'] . '</strong>.',
            );
        }

        $result = SEO_Data_Rollback::execute($operation_id);

        return array(
            'type' => 'success',
            'message' => 'Rollback completado para la operación <strong>#' . (int) $operation_id
                . '</strong>. Cambios restaurados: <strong>' . (int) ($result['rolled_back'] ?? 0) . '</strong>.',
        );
    } catch (Throwable $exception) {
        return array(
            'type' => 'error',
            'message' => 'No se pudo completar el rollback: ' . esc_html($exception->getMessage()),
        );
    }
}

/**
 * Renderiza el historial de operaciones reversibles de Clean Database.
 */
function seo_clean_db_render_operations_section() {
    $operations = seo_clean_db_get_recent_operations(20);

    echo '<div class="seo-clean-card">';
    echo '<h2>Historial de operaciones y rollback</h2>';
    echo '<p>Las limpiezas migradas al Data Layer registran cada fila modificada y pueden revertirse si no existen conflictos posteriores.</p>';

    if (!seo_clean_db_data_layer_available()) {
        seo_clean_db_render_notice('error', 'El Data Layer no está disponible.');
        echo '</div>';
        return;
    }

    if (empty($operations)) {
        echo '<p class="seo-clean-muted">Todavía no hay operaciones auditadas de Clean Database.</p>';
        echo '</div>';
        return;
    }

    echo '<table class="widefat striped seo-clean-table">';
    echo '<thead><tr><th>Operación</th><th>Fecha</th><th>Estado</th><th>Cambios</th><th>Rollback</th><th>Acción</th></tr></thead><tbody>';

    foreach ($operations as $operation) {
        $rollback_label = (string) $operation->rollback_status;
        $preview = null;

        if (
            (int) $operation->rollbackable === 1
            && $operation->status === 'completed'
            && $operation->rollback_status === 'available'
        ) {
            try {
                $preview = SEO_Data_Rollback::preview((int) $operation->id);
            } catch (Throwable $exception) {
                $preview = array(
                    'allowed' => false,
                    'reversible' => 0,
                    'conflicts' => 1,
                    'errors' => array($exception->getMessage()),
                );
            }
        }

        echo '<tr>';
        echo '<td><strong>#' . (int) $operation->id . ' · ' . esc_html($operation->operation_label) . '</strong><br><code>' . esc_html($operation->operation_type) . '</code></td>';
        echo '<td>' . esc_html($operation->completed_at ? $operation->completed_at . ' UTC' : $operation->created_at . ' UTC') . '</td>';
        echo '<td>' . esc_html($operation->status) . '</td>';
        echo '<td>' . (int) $operation->affected_rows . '</td>';

        if (is_array($preview)) {
            $rollback_label = !empty($preview['allowed'])
                ? 'Disponible · ' . (int) $preview['reversible'] . ' reversibles'
                : 'Bloqueado · ' . (int) $preview['conflicts'] . ' conflictos';
        }

        echo '<td>' . esc_html($rollback_label) . '</td>';
        echo '<td>';

        if (is_array($preview) && !empty($preview['allowed'])) {
            seo_clean_db_render_action_form(
                'rollback_operation',
                'Revertir operación',
                'button button-secondary',
                'Se restaurarán todos los cambios de esta operación. ¿Continuar?',
                array('operation_id' => (int) $operation->id)
            );
        } elseif (!empty($operation->error_message)) {
            echo '<span class="seo-clean-error">' . esc_html($operation->error_message) . '</span>';
        } else {
            echo '<span class="seo-clean-muted">No disponible</span>';
        }

        echo '</td></tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}

/**
 * Elimina por lista de IDs.
 */
function seo_clean_db_delete_ids($table, $column, $ids) {
    global $wpdb;

    $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));

    if (empty($ids)) {
        return 0;
    }

    $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
    $sql = "DELETE FROM {$table} WHERE {$column} IN ({$placeholders})";
    $prepared = call_user_func_array(array($wpdb, 'prepare'), array_merge(array($sql), $ids));

    return $wpdb->query($prepared);
}

/**
 * Elimina relaciones inválidas.
 */
function seo_clean_db_delete_invalid_relations($relations_table, $analysis) {
    $ids = array();

    foreach ($analysis['invalid'] as $item) {
        $ids[] = (int) $item['relation']->id;
    }

    return seo_clean_db_transactional_delete_ids(
        'relations',
        $ids,
        array(
            'type' => 'clean_invalid_relations',
            'label' => 'Eliminar relaciones SEO inválidas',
            'risk_level' => 'high',
            'metadata' => array(
                'analysis_total' => (int) ($analysis['total'] ?? 0),
                'invalid_count' => count($ids),
            ),
        )
    );
}

/**
 * Elimina nodos obsoletos y sus relaciones SEO internas.
 */
function seo_clean_db_delete_stale_nodes($relations_table, $nodes_table, $stale_nodes) {
    $node_ids = array();
    $objects_by_role = array();

    foreach ($stale_nodes as $item) {
        $node = $item['node'];
        $node_ids[] = (int) $node->id;
        $role = (string) $node->seo_role;

        if (!isset($objects_by_role[$role])) {
            $objects_by_role[$role] = array();
        }

        $objects_by_role[$role][] = (int) $node->object_id;
    }

    $relation_ids = seo_clean_db_get_relation_ids_for_objects($relations_table, $objects_by_role);
    $expected = count($relation_ids) + count($node_ids);

    if ($expected === 0) {
        return array('relations' => 0, 'nodes' => 0, 'operation_id' => 0);
    }

    $operation = seo_clean_db_begin_operation(
        'clean_stale_nodes',
        'Eliminar nodos SEO obsoletos y sus relaciones',
        'critical',
        $expected,
        array(
            'node_count' => count($node_ids),
            'relation_count' => count($relation_ids),
        )
    );

    $result = $operation->execute(
        function ($transaction) use ($relation_ids, $node_ids) {
            $deleted_relations = 0;
            $deleted_nodes = 0;

            foreach ($relation_ids as $relation_id) {
                $transaction->delete('relations', array('id' => (int) $relation_id));
                $deleted_relations++;
            }

            foreach ($node_ids as $node_id) {
                $transaction->delete('nodes', array('id' => (int) $node_id));
                $deleted_nodes++;
            }

            return array(
                'relations' => $deleted_relations,
                'nodes' => $deleted_nodes,
            );
        }
    );

    $result['operation_id'] = (int) $operation->id();

    return $result;
}

/**
 * Elimina duplicados exactos de relaciones conservando la primera fila.
 */
function seo_clean_db_delete_duplicate_relations($relations_table) {
    $groups = seo_clean_db_get_duplicate_relations($relations_table);
    $delete_ids = array();

    foreach ($groups as $group) {
        $ids = array_values(array_filter(array_map('absint', explode(',', (string) $group->relation_ids))));

        foreach ($ids as $id) {
            if ((int) $id !== (int) $group->keep_id) {
                $delete_ids[] = (int) $id;
            }
        }
    }

    return seo_clean_db_transactional_delete_ids(
        'relations',
        $delete_ids,
        array(
            'type' => 'clean_duplicate_relations',
            'label' => 'Eliminar relaciones SEO duplicadas',
            'risk_level' => 'high',
            'metadata' => array(
                'duplicate_groups' => count($groups),
            ),
        )
    );
}

/**
 * Elimina duplicados de seo_nodes conservando el registro más reciente.
 */
function seo_clean_db_delete_duplicate_node_rows($nodes_table) {
    $groups = seo_clean_db_get_duplicate_node_rows($nodes_table);
    $delete_ids = array();

    foreach ($groups as $group) {
        $ids = array_values(array_filter(array_map('absint', explode(',', (string) $group->node_ids))));

        foreach ($ids as $id) {
            if ((int) $id !== (int) $group->keep_id) {
                $delete_ids[] = (int) $id;
            }
        }
    }

    return seo_clean_db_transactional_delete_ids(
        'nodes',
        $delete_ids,
        array(
            'type' => 'clean_duplicate_nodes',
            'label' => 'Eliminar filas duplicadas de nodos SEO',
            'risk_level' => 'high',
            'metadata' => array(
                'duplicate_groups' => count($groups),
            ),
        )
    );
}

/**
 * Restablece objetos que tienen varios roles SEO simultáneamente.
 */
function seo_clean_db_reset_multiple_role_objects($relations_table, $nodes_table, $objects, $node_roles) {
    global $wpdb;

    $node_ids = array();
    $objects_by_role = array();

    foreach ($objects as $object) {
        $object_id = (int) $object->object_id;
        $roles = array_filter(array_map('trim', explode(',', (string) $object->roles)));
        $roles = array_values(array_unique(array_intersect($roles, $node_roles)));

        if ($object_id <= 0 || empty($roles)) {
            continue;
        }

        foreach ($roles as $role) {
            if (!isset($objects_by_role[$role])) {
                $objects_by_role[$role] = array();
            }

            $objects_by_role[$role][] = $object_id;
        }

        $role_placeholders = implode(', ', array_fill(0, count($roles), '%s'));
        $sql = "SELECT id
                FROM {$nodes_table}
                WHERE object_id = %d
                  AND seo_role IN ({$role_placeholders})";
        $args = array_merge(array($object_id), $roles);
        $prepared = call_user_func_array(array($wpdb, 'prepare'), array_merge(array($sql), $args));

        foreach ((array) $wpdb->get_col($prepared) as $node_id) {
            $node_ids[] = (int) $node_id;
        }
    }

    $relation_ids = seo_clean_db_get_relation_ids_for_objects($relations_table, $objects_by_role);
    $expected = count($relation_ids) + count($node_ids);

    if ($expected === 0) {
        return array('relations' => 0, 'nodes' => 0, 'operation_id' => 0);
    }

    $operation = seo_clean_db_begin_operation(
        'reset_multiple_role_objects',
        'Restablecer objetos con múltiples roles SEO',
        'critical',
        $expected,
        array(
            'objects_count' => count($objects),
            'node_count' => count($node_ids),
            'relation_count' => count($relation_ids),
        )
    );

    $result = $operation->execute(
        function ($transaction) use ($relation_ids, $node_ids) {
            $deleted_relations = 0;
            $deleted_nodes = 0;

            foreach ($relation_ids as $relation_id) {
                $transaction->delete('relations', array('id' => (int) $relation_id));
                $deleted_relations++;
            }

            foreach ($node_ids as $node_id) {
                $transaction->delete('nodes', array('id' => (int) $node_id));
                $deleted_nodes++;
            }

            return array(
                'relations' => $deleted_relations,
                'nodes' => $deleted_nodes,
            );
        }
    );

    $result['operation_id'] = (int) $operation->id();

    return $result;
}

/**
 * Restablece manualmente un objeto SEO.
 */
function seo_clean_db_reset_registered_object($relations_table, $nodes_table, $seo_role, $object_id) {
    global $wpdb;

    $object_id = (int) $object_id;

    $relation_ids = seo_clean_db_get_relation_ids_for_objects(
        $relations_table,
        array($seo_role => array($object_id))
    );

    $node_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT id
             FROM {$nodes_table}
             WHERE object_id = %d
               AND seo_role = %s",
            $object_id,
            $seo_role
        )
    );

    $node_ids = array_values(array_unique(array_filter(array_map('absint', (array) $node_ids))));
    $expected = count($relation_ids) + count($node_ids);

    if ($expected === 0) {
        return array('relations' => 0, 'nodes' => 0, 'operation_id' => 0);
    }

    $operation = seo_clean_db_begin_operation(
        'reset_registered_object',
        'Restablecer objeto SEO ' . $seo_role . ' #' . $object_id,
        'critical',
        $expected,
        array(
            'seo_role' => $seo_role,
            'object_id' => $object_id,
            'node_count' => count($node_ids),
            'relation_count' => count($relation_ids),
        )
    );

    $result = $operation->execute(
        function ($transaction) use ($relation_ids, $node_ids, $seo_role, $object_id) {
            $deleted_relations = 0;
            $deleted_nodes = 0;

            foreach ($relation_ids as $relation_id) {
                $transaction->delete(
                    'relations',
                    array('id' => (int) $relation_id),
                    array(
                        'related_object_type' => $seo_role,
                        'related_object_id' => $object_id,
                    )
                );
                $deleted_relations++;
            }

            foreach ($node_ids as $node_id) {
                $transaction->delete(
                    'nodes',
                    array('id' => (int) $node_id),
                    array(
                        'related_object_type' => $seo_role,
                        'related_object_id' => $object_id,
                    )
                );
                $deleted_nodes++;
            }

            return array(
                'relations' => $deleted_relations,
                'nodes' => $deleted_nodes,
            );
        }
    );

    $result['operation_id'] = (int) $operation->id();

    return $result;
}

/**
 * Mueve a raíz categorías de producto cuyo padre ya no existe.
 */
function seo_clean_db_fix_category_hierarchy() {
    global $wpdb;

    return $wpdb->query(
        "UPDATE {$wpdb->term_taxonomy} child
         LEFT JOIN {$wpdb->term_taxonomy} parent
            ON parent.term_id = child.parent
            AND parent.taxonomy = child.taxonomy
         SET child.parent = 0
         WHERE child.taxonomy = 'product_cat'
           AND child.parent <> 0
           AND parent.term_id IS NULL"
    );
}

/**
 * Etiqueta legible para incidencias.
 */
function seo_clean_db_issue_label($issue) {
    $labels = array(
        'invalid_id' => 'ID no válido',
        'missing_seo_node' => 'no existe en seo_nodes con ese rol',
        'missing_wordpress_object' => 'el objeto de WordPress ya no existe',
        'trashed_wordpress_object' => 'el objeto de WordPress está en la papelera',
        'missing_term' => 'la categoría de producto no existe',
        'unknown_endpoint_type' => 'tipo de extremo desconocido',
        'missing_relation_type' => 'relation_type está vacío',
        'relation_schema_mismatch' => 'los tipos no coinciden con relation_type',
        'unknown_relation_type' => 'relation_type no está registrado en el validador',
    );

    $side_labels = array('source' => 'Origen', 'target' => 'Destino', 'relation' => 'Relación');
    $side = isset($side_labels[$issue['side']]) ? $side_labels[$issue['side']] : 'Dato';
    $message = isset($labels[$issue['code']]) ? $labels[$issue['code']] : $issue['code'];

    return $side . ': ' . $message;
}

/**
 * Etiqueta legible para objetos.
 */
function seo_clean_db_object_label($type, $id, $title) {
    $label = '[' . (int) $id . '] ' . $type;

    if ($title !== '') {
        $label .= ' - ' . $title;
    }

    return $label;
}

/**
 * Ejecuta acciones de la pestaña Errores en datos.
 */
function seo_clean_db_handle_data_error_action($action) {
    global $wpdb;

    $relations_table = $wpdb->prefix . 'seo_relations';
    $nodes_table = $wpdb->prefix . 'seo_nodes';
    $config = seo_clean_db_get_config();

    if (!seo_clean_db_table_exists($relations_table) || !seo_clean_db_table_exists($nodes_table)) {
        return array('type' => 'error', 'message' => 'No se encuentran las tablas internas necesarias del plugin.');
    }

    $nodes_before = seo_clean_db_get_nodes($nodes_table);
    $node_index_before = seo_clean_db_build_node_index($nodes_before);

    switch ($action) {
        case 'clean_invalid_relations':
            $analysis_before = seo_clean_db_analyze_relations($relations_table, $node_index_before, $config);
            try {
                $result = seo_clean_db_delete_invalid_relations($relations_table, $analysis_before);
                return array(
                    'type' => 'success',
                    'message' => 'Relaciones inválidas eliminadas: <strong>' . (int) $result['deleted']
                        . '</strong>. Operación auditable: <strong>#' . (int) $result['operation_id']
                        . '</strong>. Rollback disponible en el historial.'
                );
            } catch (Throwable $exception) {
                return array('type' => 'error', 'message' => 'No se pudieron eliminar las relaciones inválidas: ' . esc_html($exception->getMessage()));
            }

        case 'clean_stale_nodes':
            $stale_before = seo_clean_db_get_stale_nodes($nodes_before);
            try {
                $result = seo_clean_db_delete_stale_nodes($relations_table, $nodes_table, $stale_before);
                return array(
                    'type' => 'success',
                    'message' => 'Nodos internos eliminados: <strong>' . (int) $result['nodes']
                        . '</strong>. Relaciones asociadas eliminadas: <strong>' . (int) $result['relations']
                        . '</strong>. Operación auditable: <strong>#' . (int) $result['operation_id'] . '</strong>.'
                );
            } catch (Throwable $exception) {
                return array('type' => 'error', 'message' => 'No se pudieron eliminar los nodos obsoletos: ' . esc_html($exception->getMessage()));
            }

        case 'clean_duplicate_relations':
            try {
                $result = seo_clean_db_delete_duplicate_relations($relations_table);
                return array(
                    'type' => 'success',
                    'message' => 'Relaciones duplicadas eliminadas: <strong>' . (int) $result['deleted']
                        . '</strong>. Operación auditable: <strong>#' . (int) $result['operation_id'] . '</strong>.'
                );
            } catch (Throwable $exception) {
                return array('type' => 'error', 'message' => 'No se pudieron eliminar las relaciones duplicadas: ' . esc_html($exception->getMessage()));
            }

        case 'clean_duplicate_node_rows':
            try {
                $result = seo_clean_db_delete_duplicate_node_rows($nodes_table);
                return array(
                    'type' => 'success',
                    'message' => 'Registros duplicados de seo_nodes eliminados: <strong>' . (int) $result['deleted']
                        . '</strong>. Operación auditable: <strong>#' . (int) $result['operation_id'] . '</strong>.'
                );
            } catch (Throwable $exception) {
                return array('type' => 'error', 'message' => 'No se pudieron eliminar los registros duplicados: ' . esc_html($exception->getMessage()));
            }

        case 'fix_category_hierarchy':
            $fixed = seo_clean_db_fix_category_hierarchy();
            return array('type' => $fixed === false ? 'error' : 'success', 'message' => $fixed === false ? 'No se pudo reparar la jerarquía de categorías.' : 'Categorías movidas a la raíz: <strong>' . (int) $fixed . '</strong>.');

        case 'reset_registered_object':
            $object_id = isset($_POST['object_id']) ? absint($_POST['object_id']) : 0;
            $seo_role = isset($_POST['seo_role']) ? sanitize_key(wp_unslash($_POST['seo_role'])) : '';

            if ($object_id <= 0 || !in_array($seo_role, $config['node_roles'], true)) {
                return array('type' => 'error', 'message' => 'Debes indicar un ID y un rol SEO válidos.');
            }

            try {
                $result = seo_clean_db_reset_registered_object($relations_table, $nodes_table, $seo_role, $object_id);
                return array(
                    'type' => 'success',
                    'message' => 'Objeto SEO restablecido. Nodos eliminados: <strong>' . (int) $result['nodes']
                        . '</strong>. Relaciones eliminadas: <strong>' . (int) $result['relations']
                        . '</strong>. Operación auditable: <strong>#' . (int) $result['operation_id']
                        . '</strong>. El contenido de WordPress no se ha modificado.'
                );
            } catch (Throwable $exception) {
                return array('type' => 'error', 'message' => 'No se pudo restablecer el objeto SEO: ' . esc_html($exception->getMessage()));
            }
    }

    return array('type' => 'warning', 'message' => 'Acción de datos no reconocida.');
}

/**
 * Renderiza la pestaña de errores del plugin.
 */
function seo_clean_db_render_data_errors_tab() {
    global $wpdb;

    $relations_table = $wpdb->prefix . 'seo_relations';
    $nodes_table = $wpdb->prefix . 'seo_nodes';
    $config = seo_clean_db_get_config();

    echo '<h2>Errores en datos del plugin</h2>';
    echo '<p>Audita y repara las tablas internas del plugin sin eliminar productos, páginas ni términos de WordPress.</p>';

    seo_clean_db_render_bulk_accept_lock_section();

    if (!seo_clean_db_table_exists($relations_table) || !seo_clean_db_table_exists($nodes_table)) {
        seo_clean_db_render_notice('error', 'No se encuentran las tablas <code>' . esc_html($relations_table) . '</code> y/o <code>' . esc_html($nodes_table) . '</code>.');
        return;
    }

    $nodes = seo_clean_db_get_nodes($nodes_table);
    $node_index = seo_clean_db_build_node_index($nodes);
    $relation_analysis = seo_clean_db_analyze_relations($relations_table, $node_index, $config);
    $stale_nodes = seo_clean_db_get_stale_nodes($nodes);
    $duplicate_relations = seo_clean_db_get_duplicate_relations($relations_table);
    $duplicate_node_rows = seo_clean_db_get_duplicate_node_rows($nodes_table);
    $multiple_ambito_products = seo_clean_db_get_multiple_ambito_products($nodes_table);
    $broken_categories = seo_clean_db_get_broken_categories();

    echo '<div class="seo-clean-grid">';
    $summary = array(
        'Relaciones analizadas' => $relation_analysis['total'],
        'Relaciones inválidas' => count($relation_analysis['invalid']),
        'Avisos de configuración' => count($relation_analysis['warnings']),
        'Nodos sin contenido activo' => count($stale_nodes),
        'Grupos de relaciones duplicadas' => count($duplicate_relations),
        'Productos con varios ámbitos' => count($multiple_ambito_products),
    );

    foreach ($summary as $label => $value) {
        echo '<div class="seo-clean-card"><div class="seo-clean-kpi">' . esc_html(number_format_i18n($value)) . '</div><div>' . esc_html($label) . '</div></div>';
    }
    echo '</div>';

    seo_clean_db_render_operations_section();
    seo_clean_db_render_relations_section($relation_analysis, $config);
    seo_clean_db_render_stale_nodes_section($stale_nodes, $config);
    seo_clean_db_render_duplicates_section($duplicate_relations, $duplicate_node_rows);
    seo_clean_db_render_multiple_ambitos_section($multiple_ambito_products, $config);
    seo_clean_db_render_broken_categories_section($broken_categories);
    seo_clean_db_render_manual_reset_section($config);
}

/**
 * Renderiza sección de relaciones SEO.
 */
function seo_clean_db_render_relations_section($relation_analysis, $config) {
    echo '<div class="seo-clean-card">';
    echo '<h2>1. Integridad de relaciones SEO</h2>';
    echo '<p>Cada extremo se valida según su tipo: los roles SEO deben existir en <code>seo_nodes</code> y conservar su objeto WordPress; <code>product_cat</code> debe existir como categoría de WooCommerce.</p>';

    if (empty($relation_analysis['invalid'])) {
        seo_clean_db_render_notice('success', 'No se han encontrado relaciones inválidas.');
    } else {
        seo_clean_db_render_notice('warning', 'Se han encontrado <strong>' . count($relation_analysis['invalid']) . '</strong> relaciones inválidas. La acción elimina únicamente esas filas de <code>seo_relations</code>.');
        echo '<table class="widefat striped seo-clean-table"><thead><tr><th>ID</th><th>Tipo de relación</th><th>Origen</th><th>Destino</th><th>Problema</th></tr></thead><tbody>';

        foreach (array_slice($relation_analysis['invalid'], 0, $config['report_limit']) as $item) {
            $relation = $item['relation'];
            $issue_labels = array();

            foreach ($item['issues'] as $issue) {
                $issue_labels[] = seo_clean_db_issue_label($issue);
            }

            echo '<tr><td>' . (int) $relation->id . '</td><td><code>' . esc_html($relation->relation_type) . '</code></td><td>' . esc_html(seo_clean_db_object_label($relation->source_type, $relation->source_id, $item['source_title'])) . '</td><td>' . esc_html(seo_clean_db_object_label($relation->target_type, $relation->target_id, $item['target_title'])) . '</td><td>' . esc_html(implode('; ', $issue_labels)) . '</td></tr>';
        }

        echo '</tbody></table>';
        echo '<div class="seo-clean-actions">';
        seo_clean_db_render_action_form('clean_invalid_relations', 'Eliminar relaciones inválidas', 'button button-primary', 'Se eliminarán únicamente las relaciones listadas como inválidas. ¿Continuar?');
        echo '</div>';
    }

    if (!empty($relation_analysis['warnings'])) {
        echo '<h3>Avisos de tipos no registrados</h3>';
        echo '<p>Estos registros no se eliminan automáticamente. Revisa los filtros <code>seo_clean_db_node_roles</code> y <code>seo_clean_db_relation_schemas</code> si el plugin admite más tipos.</p>';
    }

    echo '</div>';
}

/**
 * Renderiza sección de nodos obsoletos.
 */
function seo_clean_db_render_stale_nodes_section($stale_nodes, $config) {
    echo '<div class="seo-clean-card">';
    echo '<h2>2. Nodos SEO sin objeto WordPress activo</h2>';
    echo '<p>Detecta registros de <code>seo_nodes</code> cuyo contenido fue eliminado o enviado a la papelera.</p>';

    if (empty($stale_nodes)) {
        seo_clean_db_render_notice('success', 'Todos los nodos SEO conservan un objeto WordPress activo.');
    } else {
        echo '<table class="widefat striped seo-clean-table"><thead><tr><th>Registro</th><th>Objeto</th><th>Rol</th><th>Estado</th></tr></thead><tbody>';

        foreach (array_slice($stale_nodes, 0, $config['report_limit']) as $item) {
            $node = $item['node'];
            $reason = seo_clean_db_issue_label(array('side' => 'relation', 'code' => $item['reason']));
            echo '<tr><td>' . (int) $node->id . '</td><td>[' . (int) $node->object_id . '] ' . esc_html($item['title']) . '</td><td><code>' . esc_html($node->seo_role) . '</code></td><td>' . esc_html($reason) . '</td></tr>';
        }

        echo '</tbody></table>';
        echo '<div class="seo-clean-actions">';
        seo_clean_db_render_action_form('clean_stale_nodes', 'Eliminar nodos internos obsoletos', 'button button-primary', 'Se eliminarán los registros internos obsoletos y sus relaciones. No se eliminará contenido de WordPress. ¿Continuar?');
        echo '</div>';
    }

    echo '</div>';
}

/**
 * Renderiza sección de duplicados.
 */
function seo_clean_db_render_duplicates_section($duplicate_relations, $duplicate_node_rows) {
    echo '<div class="seo-clean-card">';
    echo '<h2>3. Duplicados exactos</h2>';

    echo '<h3>Relaciones duplicadas</h3>';
    if (empty($duplicate_relations)) {
        echo '<p>✔ No hay relaciones duplicadas.</p>';
    } else {
        echo '<table class="widefat striped seo-clean-table"><thead><tr><th>Conservar</th><th>Origen</th><th>Destino</th><th>Relación</th><th>Copias</th></tr></thead><tbody>';
        foreach ($duplicate_relations as $row) {
            echo '<tr><td>#' . (int) $row->keep_id . '</td><td>[' . (int) $row->source_id . '] ' . esc_html($row->source_type) . '</td><td>[' . (int) $row->target_id . '] ' . esc_html($row->target_type) . '</td><td><code>' . esc_html($row->relation_type) . '</code></td><td>' . (int) $row->total . '</td></tr>';
        }
        echo '</tbody></table><div class="seo-clean-actions">';
        seo_clean_db_render_action_form('clean_duplicate_relations', 'Eliminar copias de relaciones', 'button button-secondary', 'Se conservará la fila más antigua de cada relación exacta. ¿Continuar?');
        echo '</div>';
    }

    echo '<h3>Registros duplicados en seo_nodes</h3>';
    if (empty($duplicate_node_rows)) {
        echo '<p>✔ No hay registros de nodo duplicados con el mismo tipo de objeto, objeto y rol.</p>';
    } else {
        echo '<table class="widefat striped seo-clean-table"><thead><tr><th>Tipo</th><th>Objeto</th><th>Rol</th><th>Conservar</th><th>Copias</th></tr></thead><tbody>';
        foreach ($duplicate_node_rows as $row) {
            echo '<tr><td><code>' . esc_html($row->object_type) . '</code></td><td>' . (int) $row->object_id . '</td><td><code>' . esc_html($row->seo_role) . '</code></td><td>#' . (int) $row->keep_id . '</td><td>' . (int) $row->total . '</td></tr>';
        }
        echo '</tbody></table><div class="seo-clean-actions">';
        seo_clean_db_render_action_form('clean_duplicate_node_rows', 'Eliminar copias de nodos', 'button button-secondary', 'Se conservará el registro más reciente de cada tipo de objeto, objeto y rol. ¿Continuar?');
        echo '</div>';
    }

    echo '</div>';
}

/**
 * Renderiza productos con más de un ámbito.
 */
function seo_clean_db_render_multiple_ambitos_section($multiple_ambito_products, $config) {
    echo '<div class="seo-clean-card">';
    echo '<h2>4. Productos con múltiples ámbitos</h2>';
    echo '<p>Las filas <code>product</code>, <code>ambito</code>, <code>description</code> y <code>excerpt</code> pueden coexistir legalmente. Esta comprobación solo marca productos que tienen más de una fila <code>ambito</code>.</p>';

    if (empty($multiple_ambito_products)) {
        seo_clean_db_render_notice('success', 'No hay productos con múltiples ámbitos.');
    } else {
        seo_clean_db_render_notice('warning', 'Se han encontrado <strong>' . count($multiple_ambito_products) . '</strong> productos con más de un ámbito. El informe es diagnóstico y no elimina datos automáticamente.');
        echo '<table class="widefat striped seo-clean-table"><thead><tr><th>Producto</th><th>Título</th><th>Ámbitos</th><th>Valores registrados</th><th>IDs de nodo</th></tr></thead><tbody>';

        foreach (array_slice($multiple_ambito_products, 0, $config['report_limit']) as $row) {
            $title = get_the_title((int) $row->object_id);
            echo '<tr>';
            echo '<td>' . (int) $row->object_id . '</td>';
            echo '<td>' . esc_html($title ? $title : 'Sin título o contenido inexistente') . '</td>';
            echo '<td>' . (int) $row->total_ambitos . '</td>';
            echo '<td>' . esc_html((string) $row->ambitos) . '</td>';
            echo '<td><code>' . esc_html((string) $row->node_ids) . '</code></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    echo '</div>';
}

/**
 * Renderiza sección de jerarquía rota de categorías.
 */
function seo_clean_db_render_broken_categories_section($broken_categories) {
    echo '<div class="seo-clean-card">';
    echo '<h2>5. Jerarquía de categorías de producto</h2>';
    echo '<p>Detecta categorías cuyo padre ya no existe. La reparación las mueve al nivel raíz.</p>';

    if (empty($broken_categories)) {
        seo_clean_db_render_notice('success', 'No hay categorías con jerarquía rota.');
    } else {
        echo '<table class="widefat striped seo-clean-table"><thead><tr><th>ID</th><th>Nombre</th><th>Slug</th><th>Padre inexistente</th></tr></thead><tbody>';
        foreach ($broken_categories as $category) {
            echo '<tr><td>' . (int) $category->term_id . '</td><td>' . esc_html($category->name) . '</td><td><code>' . esc_html($category->slug) . '</code></td><td>' . (int) $category->parent . '</td></tr>';
        }
        echo '</tbody></table><div class="seo-clean-actions">';
        seo_clean_db_render_action_form('fix_category_hierarchy', 'Mover categorías afectadas a la raíz', 'button button-secondary', 'Las categorías con padre inexistente se moverán al nivel raíz. ¿Continuar?');
        echo '</div>';
    }

    echo '</div>';
}

/**
 * Renderiza restablecimiento manual.
 */
function seo_clean_db_render_manual_reset_section($config) {
    echo '<div class="seo-clean-card">';
    echo '<h2>6. Restablecimiento manual de un objeto SEO</h2>';
    echo '<p>Elimina de las tablas internas un objeto y sus relaciones para permitir una asignación limpia.</p>';
    echo '<form method="post">';
    wp_nonce_field('seo_clean_db_action', 'seo_clean_db_nonce');
    echo '<input type="hidden" name="seo_clean_db_action" value="reset_registered_object">';
    echo '<p><label for="seo-clean-db-object-id"><strong>ID del objeto</strong></label><br>';
    echo '<input id="seo-clean-db-object-id" type="number" min="1" name="object_id" required style="width:180px;"></p>';
    echo '<p><label for="seo-clean-db-role"><strong>Rol SEO</strong></label><br>';
    echo '<select id="seo-clean-db-role" name="seo_role" required style="min-width:220px;">';
    echo '<option value="">Selecciona un rol</option>';
    foreach ($config['node_roles'] as $role) {
        echo '<option value="' . esc_attr($role) . '">' . esc_html($role) . '</option>';
    }
    echo '</select></p>';
    echo '<button type="submit" class="button button-secondary" onclick="return confirm(' . esc_attr(wp_json_encode('Se eliminarán el registro SEO y sus relaciones internas. El contenido de WordPress se conservará. ¿Continuar?')) . ');">Restablecer objeto SEO</button>';
    echo '</form>';
    echo '</div>';
}

/**
 * Calcula recuentos de limpieza de WordPress/WooCommerce.
 */
function seo_clean_db_get_cleanup_stats() {
    global $wpdb;

    $stats = array();
    $stats['revisions'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'");
    $stats['auto_drafts'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'");
    $stats['trashed_posts'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'");
    $stats['spam_comments'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
    $stats['trashed_comments'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'");
    $stats['expired_transients'] = seo_clean_db_count_expired_transients();
    $stats['orphan_postmeta'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL");
    $stats['orphan_commentmeta'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL");
    $stats['orphan_termmeta'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id WHERE t.term_id IS NULL");
    $stats['orphan_term_relationships'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_relationships} tr LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE p.ID IS NULL");
    $stats['wc_sessions'] = seo_clean_db_count_wc_expired_sessions();

    return $stats;
}

/**
 * Cuenta transients expirados.
 */
function seo_clean_db_count_expired_transients() {
    global $wpdb;

    $now = time();

    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->options}
             WHERE option_name LIKE %s
               AND CAST(option_value AS UNSIGNED) < %d",
            $wpdb->esc_like('_transient_timeout_') . '%',
            $now
        )
    );
}

/**
 * Cuenta sesiones WooCommerce expiradas si la tabla existe.
 */
function seo_clean_db_count_wc_expired_sessions() {
    global $wpdb;

    $table = $wpdb->prefix . 'woocommerce_sessions';

    if (!seo_clean_db_table_exists($table)) {
        return 0;
    }

    return (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE session_expiry < %d", time())
    );
}

/**
 * Ejecuta limpiezas genéricas de WordPress/WooCommerce.
 */
function seo_clean_db_handle_database_cleanup_action($action) {
    global $wpdb;

    switch ($action) {
        case 'clean_revisions':
            $deleted = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'");
            return seo_clean_db_cleanup_result($deleted, 'Revisiones eliminadas');

        case 'clean_auto_drafts':
            $deleted = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft'");
            return seo_clean_db_cleanup_result($deleted, 'Autoguardados y borradores automáticos eliminados');

        case 'clean_trashed_posts':
            $deleted = seo_clean_db_delete_trashed_posts();
            return seo_clean_db_cleanup_result($deleted, 'Entradas, páginas y productos en papelera eliminados');

        case 'clean_spam_comments':
            $deleted = $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
            return seo_clean_db_cleanup_result($deleted, 'Comentarios spam eliminados');

        case 'clean_trashed_comments':
            $deleted = $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash'");
            return seo_clean_db_cleanup_result($deleted, 'Comentarios en papelera eliminados');

        case 'clean_expired_transients':
            $deleted = seo_clean_db_delete_expired_transients();
            return seo_clean_db_cleanup_result($deleted, 'Transients expirados eliminados');

        case 'clean_orphan_postmeta':
            $deleted = $wpdb->query("DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL");
            return seo_clean_db_cleanup_result($deleted, 'Metadatos de posts huérfanos eliminados');

        case 'clean_orphan_commentmeta':
            $deleted = $wpdb->query("DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL");
            return seo_clean_db_cleanup_result($deleted, 'Metadatos de comentarios huérfanos eliminados');

        case 'clean_orphan_termmeta':
            $deleted = $wpdb->query("DELETE tm FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id WHERE t.term_id IS NULL");
            return seo_clean_db_cleanup_result($deleted, 'Metadatos de términos huérfanos eliminados');

        case 'clean_orphan_term_relationships':
            $deleted = $wpdb->query("DELETE tr FROM {$wpdb->term_relationships} tr LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE p.ID IS NULL");
            return seo_clean_db_cleanup_result($deleted, 'Relaciones de términos huérfanas eliminadas');

        case 'clean_wc_sessions':
            $deleted = seo_clean_db_delete_wc_expired_sessions();
            return seo_clean_db_cleanup_result($deleted, 'Sesiones WooCommerce expiradas eliminadas');
    }

    return array('type' => 'warning', 'message' => 'Acción de limpieza no reconocida.');
}

/**
 * Devuelve aviso normalizado tras una limpieza.
 */
function seo_clean_db_cleanup_result($deleted, $label) {
    return array(
        'type' => $deleted === false ? 'error' : 'success',
        'message' => $deleted === false ? 'No se pudo ejecutar la limpieza.' : esc_html($label) . ': <strong>' . (int) $deleted . '</strong>.'
    );
}

/**
 * Elimina posts en papelera usando wp_delete_post para limpiar relaciones asociadas.
 */
function seo_clean_db_delete_trashed_posts() {
    $ids = get_posts(array(
        'post_type' => 'any',
        'post_status' => 'trash',
        'fields' => 'ids',
        'posts_per_page' => -1,
        'no_found_rows' => true,
    ));

    $deleted = 0;

    foreach ($ids as $post_id) {
        $result = wp_delete_post((int) $post_id, true);
        if ($result) {
            $deleted++;
        }
    }

    return $deleted;
}

/**
 * Elimina transients expirados junto a sus timeout.
 */
function seo_clean_db_delete_expired_transients() {
    global $wpdb;

    $now = time();
    $timeouts = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name
             FROM {$wpdb->options}
             WHERE option_name LIKE %s
               AND CAST(option_value AS UNSIGNED) < %d",
            $wpdb->esc_like('_transient_timeout_') . '%',
            $now
        )
    );

    $deleted = 0;

    foreach ($timeouts as $timeout_name) {
        $transient_name = str_replace('_transient_timeout_', '_transient_', $timeout_name);
        $deleted += (int) $wpdb->delete($wpdb->options, array('option_name' => $timeout_name), array('%s'));
        $deleted += (int) $wpdb->delete($wpdb->options, array('option_name' => $transient_name), array('%s'));
    }

    return $deleted;
}

/**
 * Elimina sesiones WooCommerce expiradas.
 */
function seo_clean_db_delete_wc_expired_sessions() {
    global $wpdb;

    $table = $wpdb->prefix . 'woocommerce_sessions';

    if (!seo_clean_db_table_exists($table)) {
        return 0;
    }

    return $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE session_expiry < %d", time()));
}

/**
 * Renderiza la pestaña de limpieza BBDD.
 */
function seo_clean_db_render_database_cleanup_tab() {
    $stats = seo_clean_db_get_cleanup_stats();

    echo '<h2>Limpieza BBDD</h2>';
    echo '<p>Limpiezas manuales de residuos técnicos habituales de WordPress y WooCommerce.</p>';
    echo '<div class="seo-clean-warning-box"><strong>Recomendación:</strong> antes de ejecutar acciones destructivas, descarga un backup SQL. No es obligatorio, pero está a un clic para evitar sustos.</div>';
    echo '<p><a class="button button-primary" href="' . esc_url(seo_clean_db_export_url('full')) . '">Descargar backup SQL completo</a> ';
    echo '<a class="button" href="' . esc_url(seo_clean_db_export_url('seo')) . '">Descargar solo tablas SEO System</a></p>';

    echo '<div class="seo-clean-grid">';
    foreach ($stats as $key => $value) {
        echo '<div class="seo-clean-card"><div class="seo-clean-kpi">' . esc_html(number_format_i18n($value)) . '</div><div>' . esc_html(seo_clean_db_cleanup_label($key)) . '</div></div>';
    }
    echo '</div>';

    echo '<div class="seo-clean-card">';
    echo '<h2>Acciones disponibles</h2>';
    echo '<table class="widefat striped seo-clean-table"><thead><tr><th>Limpieza</th><th>Encontrados</th><th>Riesgo</th><th>Acción</th></tr></thead><tbody>';

    seo_clean_db_cleanup_row('Revisiones', $stats['revisions'], 'Bajo', 'clean_revisions', 'Eliminar revisiones', 'Se eliminarán revisiones de entradas, páginas y productos. ¿Continuar?');
    seo_clean_db_cleanup_row('Autoguardados / auto-drafts', $stats['auto_drafts'], 'Bajo', 'clean_auto_drafts', 'Eliminar autoguardados', 'Se eliminarán borradores automáticos. ¿Continuar?');
    seo_clean_db_cleanup_row('Contenido en papelera', $stats['trashed_posts'], 'Medio', 'clean_trashed_posts', 'Vaciar papelera', 'Se eliminará definitivamente el contenido en papelera. ¿Continuar?');
    seo_clean_db_cleanup_row('Comentarios spam', $stats['spam_comments'], 'Bajo', 'clean_spam_comments', 'Eliminar spam', 'Se eliminarán comentarios marcados como spam. ¿Continuar?');
    seo_clean_db_cleanup_row('Comentarios en papelera', $stats['trashed_comments'], 'Bajo', 'clean_trashed_comments', 'Eliminar comentarios papelera', 'Se eliminarán comentarios en papelera. ¿Continuar?');
    seo_clean_db_cleanup_row('Transients expirados', $stats['expired_transients'], 'Bajo', 'clean_expired_transients', 'Eliminar transients expirados', 'Se eliminarán transients expirados. ¿Continuar?');
    seo_clean_db_cleanup_row('Postmeta huérfano', $stats['orphan_postmeta'], 'Medio', 'clean_orphan_postmeta', 'Eliminar postmeta huérfano', 'Se eliminarán metadatos sin post asociado. Recomendado backup previo. ¿Continuar?');
    seo_clean_db_cleanup_row('Commentmeta huérfano', $stats['orphan_commentmeta'], 'Bajo', 'clean_orphan_commentmeta', 'Eliminar commentmeta huérfano', 'Se eliminarán metadatos sin comentario asociado. ¿Continuar?');
    seo_clean_db_cleanup_row('Termmeta huérfano', $stats['orphan_termmeta'], 'Bajo', 'clean_orphan_termmeta', 'Eliminar termmeta huérfano', 'Se eliminarán metadatos sin término asociado. ¿Continuar?');
    seo_clean_db_cleanup_row('Relaciones de términos huérfanas', $stats['orphan_term_relationships'], 'Medio', 'clean_orphan_term_relationships', 'Eliminar relaciones huérfanas', 'Se eliminarán relaciones de términos sin objeto asociado. Recomendado backup previo. ¿Continuar?');
    seo_clean_db_cleanup_row('Sesiones WooCommerce expiradas', $stats['wc_sessions'], 'Bajo', 'clean_wc_sessions', 'Eliminar sesiones expiradas', 'Se eliminarán sesiones WooCommerce expiradas. ¿Continuar?');

    echo '</tbody></table>';
    echo '</div>';
}

/**
 * Etiqueta legible para métricas de limpieza.
 */
function seo_clean_db_cleanup_label($key) {
    $labels = array(
        'revisions' => 'Revisiones',
        'auto_drafts' => 'Autoguardados',
        'trashed_posts' => 'Contenido en papelera',
        'spam_comments' => 'Comentarios spam',
        'trashed_comments' => 'Comentarios en papelera',
        'expired_transients' => 'Transients expirados',
        'orphan_postmeta' => 'Postmeta huérfano',
        'orphan_commentmeta' => 'Commentmeta huérfano',
        'orphan_termmeta' => 'Termmeta huérfano',
        'orphan_term_relationships' => 'Relaciones términos huérfanas',
        'wc_sessions' => 'Sesiones WC expiradas',
    );

    return isset($labels[$key]) ? $labels[$key] : $key;
}

/**
 * Renderiza una fila de acción de limpieza.
 */
function seo_clean_db_cleanup_row($label, $count, $risk, $action, $button_label, $confirm) {
    $risk_status = $risk === 'Bajo' ? 'ok' : 'warning';

    echo '<tr>';
    echo '<td><strong>' . esc_html($label) . '</strong></td>';
    echo '<td>' . esc_html(number_format_i18n($count)) . '</td>';
    echo '<td>' . seo_clean_db_badge($risk_status, $risk) . '</td>';
    echo '<td>';

    if ((int) $count > 0) {
        seo_clean_db_render_action_form($action, $button_label, 'button button-secondary', $confirm);
    } else {
        echo '<span class="seo-clean-muted">Nada que limpiar</span>';
    }

    echo '</td></tr>';
}

/**
 * Renderiza la pestaña de exportación.
 */
function seo_clean_db_render_export_tab() {
    $full_tables = seo_clean_db_get_export_tables('full');
    $seo_tables = seo_clean_db_get_export_tables('seo');

    echo '<h2>Exportar BBDD</h2>';
    echo '<p>Descarga un SQL antes de ejecutar limpiezas. En instalaciones grandes, el backup completo puede depender de límites del hosting.</p>';

    echo '<div class="seo-clean-card">';
    echo '<h2>Backup completo de WordPress</h2>';
    echo '<p>Incluye tablas con el prefijo activo de WordPress: <code>' . esc_html($GLOBALS['wpdb']->prefix) . '</code>.</p>';
    echo '<p>Tablas detectadas: <strong>' . esc_html(number_format_i18n(count($full_tables))) . '</strong></p>';
    echo '<p><a class="button button-primary" href="' . esc_url(seo_clean_db_export_url('full')) . '">Descargar backup SQL completo</a></p>';
    echo '</div>';

    echo '<div class="seo-clean-card">';
    echo '<h2>Backup solo SEO System</h2>';
    echo '<p>Incluye únicamente tablas internas cuyo nombre empieza por <code>' . esc_html($GLOBALS['wpdb']->prefix . 'seo_') . '</code>.</p>';
    echo '<p>Tablas detectadas: <strong>' . esc_html(number_format_i18n(count($seo_tables))) . '</strong></p>';
    echo '<p><a class="button" href="' . esc_url(seo_clean_db_export_url('seo')) . '">Descargar solo tablas SEO System</a></p>';
    echo '</div>';

    if (!empty($seo_tables)) {
        echo '<div class="seo-clean-card"><h2>Tablas SEO detectadas</h2><ul>';
        foreach ($seo_tables as $table) {
            echo '<li><code>' . esc_html($table) . '</code></li>';
        }
        echo '</ul></div>';
    }
}
