<?php
namespace TCDB_Legacy;
use \RuntimeException;
use \InvalidArgumentException;
use \LogicException;
use \Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * TaxoCommerce SEO - Clean Database
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

    $active_tab = seo_clean_db_get_active_tab();
    $notice = seo_clean_db_handle_post_actions();

    echo '<div class="wrap seo-clean-db-wrap">';
    echo '<h1>Clean Database</h1>';
    echo '<p>Auditoría, reparación y limpieza controlada de datos del plugin, WordPress y WooCommerce.</p>';


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
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only cleanup tab selector.
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
 * Carga CSS con la API de estilos de WordPress, solo en la página Clean Database.
 */
function seo_clean_db_enqueue_styles() {
    if (!current_user_can('manage_options')) {
        return;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page selector used solely to enqueue admin CSS.
    $page = isset($_GET['page']) && is_string($_GET['page'])
        ? sanitize_key(wp_unslash($_GET['page']))
        : '';
    if ('taxocommerce-database' !== $page) {
        return;
    }
    wp_enqueue_style(
        'taxocommerce-clean-db',
        TAXOCOMMERCE_DATABASE_URL . 'assets/seo-clean-db.css',
        array(),
        TAXOCOMMERCE_DATABASE_VERSION
    );
}
add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\seo_clean_db_enqueue_styles');

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

    if ($confirm_message !== '') {
        $confirm_json = wp_json_encode($confirm_message);
        if (false === $confirm_json) {
            $confirm_json = '""';
        }
        echo '<button type="submit" class="' . esc_attr($button_class) . '" onclick="return confirm(' . esc_attr($confirm_json) . ');">' . esc_html($label) . '</button>';
    } else {
        echo '<button type="submit" class="' . esc_attr($button_class) . '">' . esc_html($label) . '</button>';
    }
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
    if (!isset($_GET['seo_clean_db_export']) || !isset($_GET['page']) || 'taxocommerce-database' !== sanitize_key(wp_unslash($_GET['page']))) {
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para exportar la base de datos.', 'taxocommerce-database'));
    }

    $type = sanitize_key(wp_unslash($_GET['seo_clean_db_export']));
    check_admin_referer('seo_clean_db_export_' . $type, 'seo_clean_db_export_nonce');

    if (!in_array($type, array('full', 'seo'), true)) {
        wp_die(esc_html__('Tipo de exportación no válido.', 'taxocommerce-database'));
    }

    seo_clean_db_stream_sql_export($type);
    exit;
}

/**
 * Genera un SQL descargable de tablas completas o solo tablas del plugin.
 */
add_action('admin_init', __NAMESPACE__ . '\\seo_clean_db_maybe_export_sql');

function seo_clean_db_stream_sql_export($type) {
    global $wpdb;

    // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Long-running administrator/worker operation with explicit bounds or recovery controls.
    @set_time_limit(0);nocache_headers();

    $tables = seo_clean_db_get_export_tables($type);
    $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
    $site_host = $site_host ? sanitize_file_name($site_host) : 'wordpress';
    $filename = $site_host . '-' . $type . '-backup-' . gmdate('Ymd-His') . '.sql';

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "-- TaxoCommerce SEO database export\n";
    echo "-- Type: " . esc_html($type) . "\n";
    echo "-- Site: " . esc_url(home_url()) . "\n";
    echo "-- Generated: " . esc_html(gmdate('Y-m-d H:i:s')) . " UTC\n\n";
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
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SQL backup stream must remain raw SQL; values and identifiers are escaped for SQL above.
    echo "-- Table structure for `" . $table . "`\n";
    echo "-- --------------------------------------------------------\n\n";
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SQL backup stream must remain raw SQL; values and identifiers are escaped for SQL above.
    echo "DROP TABLE IF EXISTS `" . $table . "`;\n";
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SQL backup stream must remain raw SQL; values and identifiers are escaped for SQL above.
    echo $create[1] . ";\n\n";

    $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . esc_sql($table) . '`');

    if ($count <= 0) {
        return;
    }

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SQL backup stream must remain raw SQL; values and identifiers are escaped for SQL above.
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

            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SQL backup stream must remain raw SQL; values and identifiers are escaped for SQL above.
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

        case 'unlock_bulk_accept_lock':
            $posted_token = isset($_POST['bulk_accept_lock_token']) && is_string($_POST['bulk_accept_lock_token'])
                ? sanitize_text_field(wp_unslash($_POST['bulk_accept_lock_token']))
                : '';
            return seo_clean_db_handle_unlock_bulk_accept_lock($posted_token);

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
        case 'clean_orphan_usermeta':
        case 'clean_as_orphan_logs':
        case 'clean_as_old_completed_logs':
        case 'clean_as_orphan_claims':
        case 'clean_wc_expired_reserved_stock':
        case 'clean_wc_orphan_order_itemmeta':
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
 *
 * @param string $posted_token Token recibido por el controlador tras verificar el nonce.
 */
function seo_clean_db_handle_unlock_bulk_accept_lock($posted_token) {
    $option_name = 'seo_proveedores_bulk_accept_lock';
    $lock = get_option($option_name, false);

    if ($lock === false) {
        return array(
            'type' => 'info',
            'message' => 'El bloqueo de aceptación masiva ya no existe.',
        );
    }

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

    $nodes_table = esc_sql( $nodes_table );

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

    $relations_table = esc_sql( $relations_table );

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

    $relations_table = esc_sql( $relations_table );

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

    $nodes_table = esc_sql( $nodes_table );

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

    $nodes_table = esc_sql( $nodes_table );

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

/**
 * Standalone delete without a cross-plugin Data Layer. A backup is the recovery
 * method. Limits are per SQL statement only: all eligible IDs are processed.
 */
function seo_clean_db_transactional_delete_ids($table_key, $ids, $operation_args = array(), $context_callback = null) {
    global $wpdb;
    $table = 'nodes' === $table_key ? $wpdb->prefix . 'seo_nodes' : ('relations' === $table_key ? $wpdb->prefix . 'seo_relations' : '');
    if ('' === $table) {
        throw new RuntimeException('Tabla de limpieza no autorizada.');
    }
    $ids = array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
    if (!$ids) {
        return array('deleted' => 0);
    }
    if (false === $wpdb->query('START TRANSACTION')) {
        throw new RuntimeException('No se pudo iniciar la transacción.');
    }
    try {
        $count = seo_clean_db_delete_ids($table, 'id', $ids);
        if (false === $count) {
            throw new RuntimeException('Fallo al eliminar registros SEO.');
        }
        if (false === $wpdb->query('COMMIT')) {
            throw new RuntimeException('No se pudo confirmar la limpieza.');
        }
        return array('deleted' => (int) $count);
    } catch (Throwable $error) {
        $wpdb->query('ROLLBACK');
        throw $error;
    }
}

/** Delete both node and relation IDs in one local transaction. */
function seo_clean_db_delete_relation_node_ids($relation_ids, $node_ids) {
    global $wpdb;
    if (!$relation_ids && !$node_ids) {
        return array('relations' => 0, 'nodes' => 0);
    }
    if (false === $wpdb->query('START TRANSACTION')) {
        throw new RuntimeException('No se pudo iniciar la transacción.');
    }
    try {
        $relations = seo_clean_db_delete_ids($wpdb->prefix . 'seo_relations', 'id', $relation_ids);
        $nodes = seo_clean_db_delete_ids($wpdb->prefix . 'seo_nodes', 'id', $node_ids);
        if (false === $relations || false === $nodes) {
            throw new RuntimeException('No se pudieron eliminar los registros SEO.');
        }
        if (false === $wpdb->query('COMMIT')) {
            throw new RuntimeException('No se pudo confirmar la limpieza.');
        }
        return array('relations' => (int) $relations, 'nodes' => (int) $nodes);
    } catch (Throwable $error) {
        $wpdb->query('ROLLBACK');
        throw $error;
    }
}


function seo_clean_db_get_relation_ids_for_objects($relations_table, $objects_by_role) {
    global $wpdb;

    $relations_table = esc_sql( $relations_table );

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
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Internal relations table plus generated %d placeholders; all role/ID values are bound through prepare().
        $found = $wpdb->get_col($wpdb->prepare($sql, $args));

        foreach ((array) $found as $id) {
            $ids[] = (int) $id;
        }
    }

    return array_values(array_unique($ids));
}

/**
 * Devuelve operaciones recientes de Clean Database.
 */

/**
 * Direct SEO table repairs replace only the absent cross-plugin Data Layer.
 */
function seo_clean_db_delete_ids($table, $column, $ids) {
    global $wpdb;
    if (!in_array($table, array($wpdb->prefix . 'seo_nodes', $wpdb->prefix . 'seo_relations'), true) || 'id' !== $column) {
        throw new RuntimeException('Tabla o columna de limpieza no permitida.');
    }
    $ids = array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
    $total = 0;
    foreach (array_chunk($ids, 500) as $chunk) {
        $holders = implode(', ', array_fill(0, count($chunk), '%d'));
        $values = array_merge(array($table), $chunk);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table is strictly allowlisted, IDs are absint, %i/%d placeholders are generated by count and passed to prepare().
        $sql = $wpdb->prepare('DELETE FROM %i WHERE id IN (' . $holders . ')', $values);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is the already prepared statement above.
        $affected = $wpdb->query($sql);
        if (false === $affected) {
            return false;
        }
        $total += (int) $affected;
    }
    return $total;
}


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
    return seo_clean_db_delete_relation_node_ids($relation_ids, $node_ids);
}


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
        $roles = array_values(array_unique(array_intersect(array_filter(array_map('trim', explode(',', (string) $object->roles))), $node_roles)));
        if ($object_id <= 0 || !$roles) {
            continue;
        }
        foreach ($roles as $role) {
            $objects_by_role[$role][] = $object_id;
        }
        $holders = implode(', ', array_fill(0, count($roles), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Role placeholders are generated by count; table, object ID and roles are bound through prepare().
        $sql = $wpdb->prepare('SELECT id FROM %i WHERE object_id=%d AND seo_role IN (' . $holders . ')', array_merge(array($nodes_table, $object_id), $roles));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is fully prepared on the preceding line.
        $node_ids = array_merge($node_ids, array_map('absint', (array) $wpdb->get_col($sql)));
    }
    $relation_ids = seo_clean_db_get_relation_ids_for_objects($relations_table, $objects_by_role);
    return seo_clean_db_delete_relation_node_ids($relation_ids, $node_ids);
}

function seo_clean_db_reset_registered_object($relations_table, $nodes_table, $seo_role, $object_id) {
    global $wpdb;
    $object_id = absint($object_id);
    $relation_ids = seo_clean_db_get_relation_ids_for_objects($relations_table, array($seo_role => array($object_id)));
    $node_ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE object_id=%d AND seo_role=%s', $nodes_table, $object_id, $seo_role));
    return seo_clean_db_delete_relation_node_ids($relation_ids, array_map('absint', (array) $node_ids));
}


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
    $nodes = seo_clean_db_get_nodes($nodes_table);
    try {
        switch ($action) {
            case 'clean_invalid_relations':
                $analysis = seo_clean_db_analyze_relations($relations_table, seo_clean_db_build_node_index($nodes), $config);
                // Unknown relation types remain warnings; only validated invalid IDs are deleted.
                $result = seo_clean_db_delete_invalid_relations($relations_table, $analysis);
                return array('type' => 'success', 'message' => 'Relaciones inválidas eliminadas: <strong>' . (int) $result['deleted'] . '</strong>.');
            case 'clean_stale_nodes':
                $result = seo_clean_db_delete_stale_nodes($relations_table, $nodes_table, seo_clean_db_get_stale_nodes($nodes));
                return array('type' => 'success', 'message' => 'Nodos eliminados: <strong>' . (int) $result['nodes'] . '</strong>. Relaciones asociadas eliminadas: <strong>' . (int) $result['relations'] . '</strong>.');
            case 'clean_duplicate_relations':
                $result = seo_clean_db_delete_duplicate_relations($relations_table);
                return array('type' => 'success', 'message' => 'Relaciones duplicadas eliminadas: <strong>' . (int) $result['deleted'] . '</strong>.');
            case 'clean_duplicate_node_rows':
                $result = seo_clean_db_delete_duplicate_node_rows($nodes_table);
                return array('type' => 'success', 'message' => 'Nodos duplicados eliminados: <strong>' . (int) $result['deleted'] . '</strong>.');
            case 'fix_category_hierarchy':
                $fixed = seo_clean_db_fix_category_hierarchy();
                return array('type' => false === $fixed ? 'error' : 'success', 'message' => false === $fixed ? 'No se pudo reparar la jerarquía.' : 'Categorías movidas a la raíz: <strong>' . (int) $fixed . '</strong>.');
            case 'reset_registered_object':
                $object_id = isset($_POST['object_id']) ? absint(wp_unslash($_POST['object_id'])) : 0;
                $role = isset($_POST['seo_role']) && is_string($_POST['seo_role']) ? sanitize_key(wp_unslash($_POST['seo_role'])) : '';
                if ($object_id <= 0 || !in_array($role, $config['node_roles'], true)) {
                    return array('type' => 'error', 'message' => 'ID y rol SEO inválidos.');
                }
                $result = seo_clean_db_reset_registered_object($relations_table, $nodes_table, $role, $object_id);
                return array('type' => 'success', 'message' => 'Objeto SEO restablecido. Nodos eliminados: <strong>' . (int) $result['nodes'] . '</strong>. Relaciones eliminadas: <strong>' . (int) $result['relations'] . '</strong>. El contenido WordPress permanece intacto.');
        }
    } catch (Throwable $error) {
        return array('type' => 'error', 'message' => 'Error al reparar los datos SEO: ' . esc_html($error->getMessage()));
    }
    return array('type' => 'warning', 'message' => 'Acción no reconocida.');
}


function seo_clean_db_render_data_errors_tab() {
    global $wpdb;

    $relations_table = esc_sql( $wpdb->prefix . 'seo_relations' );
    $nodes_table = esc_sql( $wpdb->prefix . 'seo_nodes' );
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
    $stats['orphan_usermeta'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON u.ID = um.user_id WHERE u.ID IS NULL");
    $stats['as_orphan_logs'] = seo_clean_db_count_as_orphan_logs();
    $stats['as_old_completed_logs'] = seo_clean_db_count_as_old_completed_logs();
    $stats['as_orphan_claims'] = seo_clean_db_count_as_orphan_claims();
    $stats['wc_expired_reserved_stock'] = seo_clean_db_count_wc_expired_reserved_stock();
    $stats['wc_orphan_order_itemmeta'] = seo_clean_db_count_wc_orphan_order_itemmeta();

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

    $table = esc_sql( $wpdb->prefix . 'woocommerce_sessions' );

    if (!seo_clean_db_table_exists($table)) {
        return 0;
    }

    return (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE session_expiry < %d", time())
    );
}


/**
 * Cuenta logs de Action Scheduler cuyo action_id ya no existe.
 */
function seo_clean_db_count_as_orphan_logs() {
    global $wpdb;

    $logs = esc_sql( $wpdb->prefix . 'actionscheduler_logs' );
    $actions = esc_sql( $wpdb->prefix . 'actionscheduler_actions' );

    if (!seo_clean_db_table_exists($logs) || !seo_clean_db_table_exists($actions)) {
        return 0;
    }

    return (int) $wpdb->get_var(
        "SELECT COUNT(*)
         FROM {$logs} l
         LEFT JOIN {$actions} a ON a.action_id = l.action_id
         WHERE a.action_id IS NULL"
    );
}

/**
 * Cuenta logs antiguos de acciones ya finalizadas/canceladas.
 * Conserva 30 días de historial para diagnóstico.
 */
function seo_clean_db_count_as_old_completed_logs() {
    global $wpdb;

    $logs = esc_sql( $wpdb->prefix . 'actionscheduler_logs' );
    $actions = esc_sql( $wpdb->prefix . 'actionscheduler_actions' );

    if (!seo_clean_db_table_exists($logs) || !seo_clean_db_table_exists($actions)) {
        return 0;
    }

    return (int) $wpdb->get_var(
        "SELECT COUNT(*)
         FROM {$logs} l
         INNER JOIN {$actions} a ON a.action_id = l.action_id
         WHERE a.status IN ('complete', 'canceled')
           AND l.log_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)"
    );
}

/**
 * Cuenta claims de Action Scheduler sin acciones asociadas y con más de 24 h.
 */
function seo_clean_db_count_as_orphan_claims() {
    global $wpdb;

    $claims = esc_sql( $wpdb->prefix . 'actionscheduler_claims' );
    $actions = esc_sql( $wpdb->prefix . 'actionscheduler_actions' );

    if (!seo_clean_db_table_exists($claims) || !seo_clean_db_table_exists($actions)) {
        return 0;
    }

    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Internal Action Scheduler table names; query has no external values.
    return (int) $wpdb->get_var(
        "SELECT COUNT(*)
         FROM {$claims} c
         LEFT JOIN {$actions} a ON a.claim_id = c.claim_id
         WHERE a.action_id IS NULL
           AND c.date_created_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)"
    );
}

/**
 * Cuenta reservas de stock WooCommerce ya expiradas.
 */
function seo_clean_db_count_wc_expired_reserved_stock() {
    global $wpdb;

    $table = esc_sql( $wpdb->prefix . 'wc_reserved_stock' );

    if (!seo_clean_db_table_exists($table)) {
        return 0;
    }

    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Internal WooCommerce table name; query has no external values.
    return (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$table} WHERE expires < UTC_TIMESTAMP()"
    );
}

/**
 * Cuenta metadatos de items de pedido cuyo item padre ya no existe.
 */
function seo_clean_db_count_wc_orphan_order_itemmeta() {
    global $wpdb;

    $items = esc_sql( $wpdb->prefix . 'woocommerce_order_items' );
    $meta = esc_sql( $wpdb->prefix . 'woocommerce_order_itemmeta' );

    if (!seo_clean_db_table_exists($items) || !seo_clean_db_table_exists($meta)) {
        return 0;
    }

    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Internal WooCommerce table names; query has no external values.
    return (int) $wpdb->get_var(
        "SELECT COUNT(*)
         FROM {$meta} m
         LEFT JOIN {$items} i ON i.order_item_id = m.order_item_id
         WHERE i.order_item_id IS NULL"
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

        case 'clean_orphan_usermeta':
            $deleted = $wpdb->query("DELETE um FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON u.ID = um.user_id WHERE u.ID IS NULL");
            return seo_clean_db_cleanup_result($deleted, 'Metadatos de usuarios huérfanos eliminados');

        case 'clean_as_orphan_logs':
            $deleted = seo_clean_db_delete_as_orphan_logs();
            return seo_clean_db_cleanup_result($deleted, 'Logs huérfanos de Action Scheduler eliminados');

        case 'clean_as_old_completed_logs':
            $deleted = seo_clean_db_delete_as_old_completed_logs();
            return seo_clean_db_cleanup_result($deleted, 'Logs antiguos de Action Scheduler eliminados');

        case 'clean_as_orphan_claims':
            $deleted = seo_clean_db_delete_as_orphan_claims();
            return seo_clean_db_cleanup_result($deleted, 'Claims huérfanos de Action Scheduler eliminados');

        case 'clean_wc_expired_reserved_stock':
            $deleted = seo_clean_db_delete_wc_expired_reserved_stock();
            return seo_clean_db_cleanup_result($deleted, 'Reservas de stock WooCommerce expiradas eliminadas');

        case 'clean_wc_orphan_order_itemmeta':
            $deleted = seo_clean_db_delete_wc_orphan_order_itemmeta();
            return seo_clean_db_cleanup_result($deleted, 'Metadatos huérfanos de items de pedido eliminados');
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

    $table = esc_sql( $wpdb->prefix . 'woocommerce_sessions' );

    if (!seo_clean_db_table_exists($table)) {
        return 0;
    }

    return $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE session_expiry < %d", time()));
}


/**
 * Elimina logs de Action Scheduler sin acción asociada.
 */
function seo_clean_db_delete_as_orphan_logs() {
    global $wpdb;

    $logs = esc_sql( $wpdb->prefix . 'actionscheduler_logs' );
    $actions = esc_sql( $wpdb->prefix . 'actionscheduler_actions' );

    if (!seo_clean_db_table_exists($logs) || !seo_clean_db_table_exists($actions)) {
        return 0;
    }

    return $wpdb->query(
        "DELETE l
         FROM {$logs} l
         LEFT JOIN {$actions} a ON a.action_id = l.action_id
         WHERE a.action_id IS NULL"
    );
}

/**
 * Elimina únicamente logs de más de 30 días de acciones completas/canceladas.
 * No elimina acciones pendientes, fallidas ni en ejecución.
 */
function seo_clean_db_delete_as_old_completed_logs() {
    global $wpdb;

    $logs = esc_sql( $wpdb->prefix . 'actionscheduler_logs' );
    $actions = esc_sql( $wpdb->prefix . 'actionscheduler_actions' );

    if (!seo_clean_db_table_exists($logs) || !seo_clean_db_table_exists($actions)) {
        return 0;
    }

    return $wpdb->query(
        "DELETE l
         FROM {$logs} l
         INNER JOIN {$actions} a ON a.action_id = l.action_id
         WHERE a.status IN ('complete', 'canceled')
           AND l.log_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)"
    );
}

/**
 * Elimina claims de Action Scheduler sin acciones asociadas y antiguos (>24 h).
 */
function seo_clean_db_delete_as_orphan_claims() {
    global $wpdb;

    $claims = esc_sql( $wpdb->prefix . 'actionscheduler_claims' );
    $actions = esc_sql( $wpdb->prefix . 'actionscheduler_actions' );

    if (!seo_clean_db_table_exists($claims) || !seo_clean_db_table_exists($actions)) {
        return 0;
    }

    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Internal Action Scheduler table names; query has no external values.
    return $wpdb->query(
        "DELETE c
         FROM {$claims} c
         LEFT JOIN {$actions} a ON a.claim_id = c.claim_id
         WHERE a.action_id IS NULL
           AND c.date_created_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)"
    );
}

/**
 * Elimina reservas de stock WooCommerce ya vencidas.
 */
function seo_clean_db_delete_wc_expired_reserved_stock() {
    global $wpdb;

    $table = esc_sql( $wpdb->prefix . 'wc_reserved_stock' );

    if (!seo_clean_db_table_exists($table)) {
        return 0;
    }

    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Internal WooCommerce table name; query has no external values.
    return $wpdb->query("DELETE FROM {$table} WHERE expires < UTC_TIMESTAMP()");
}

/**
 * Elimina metadatos de items de pedido cuyo item padre ya no existe.
 */
function seo_clean_db_delete_wc_orphan_order_itemmeta() {
    global $wpdb;

    $items = esc_sql( $wpdb->prefix . 'woocommerce_order_items' );
    $meta = esc_sql( $wpdb->prefix . 'woocommerce_order_itemmeta' );

    if (!seo_clean_db_table_exists($items) || !seo_clean_db_table_exists($meta)) {
        return 0;
    }

    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Internal WooCommerce table names; query has no external values.
    return $wpdb->query(
        "DELETE m
         FROM {$meta} m
         LEFT JOIN {$items} i ON i.order_item_id = m.order_item_id
         WHERE i.order_item_id IS NULL"
    );
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
    echo '<a class="button" href="' . esc_url(seo_clean_db_export_url('seo')) . '">Descargar solo tablas TaxoCommerce SEO</a></p>';

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
    seo_clean_db_cleanup_row('Usermeta huérfano', $stats['orphan_usermeta'], 'Bajo', 'clean_orphan_usermeta', 'Eliminar usermeta huérfano', 'Se eliminarán metadatos asociados a usuarios que ya no existen. ¿Continuar?');
    seo_clean_db_cleanup_row('Action Scheduler: logs huérfanos', $stats['as_orphan_logs'], 'Bajo', 'clean_as_orphan_logs', 'Eliminar logs huérfanos', 'Se eliminarán únicamente logs cuyo action_id ya no existe. ¿Continuar?');
    seo_clean_db_cleanup_row('Action Scheduler: logs antiguos completados/cancelados (>30 días)', $stats['as_old_completed_logs'], 'Bajo', 'clean_as_old_completed_logs', 'Purgar logs antiguos', 'Se eliminará solo historial de más de 30 días perteneciente a acciones ya completas o canceladas. No se tocarán acciones pendientes, fallidas ni en ejecución. ¿Continuar?');
    seo_clean_db_cleanup_row('Action Scheduler: claims huérfanos (>24 h)', $stats['as_orphan_claims'], 'Bajo', 'clean_as_orphan_claims', 'Eliminar claims huérfanos', 'Se eliminarán únicamente claims sin ninguna acción asociada y con más de 24 horas. ¿Continuar?');
    seo_clean_db_cleanup_row('WooCommerce: reservas de stock expiradas', $stats['wc_expired_reserved_stock'], 'Bajo', 'clean_wc_expired_reserved_stock', 'Eliminar reservas expiradas', 'Se eliminarán únicamente reservas de stock cuya fecha de expiración ya ha pasado. ¿Continuar?');
    seo_clean_db_cleanup_row('WooCommerce: order item meta huérfano', $stats['wc_orphan_order_itemmeta'], 'Bajo', 'clean_wc_orphan_order_itemmeta', 'Eliminar meta huérfano', 'Se eliminarán metadatos de líneas de pedido cuyo item padre ya no existe. No se eliminan pedidos ni líneas de pedido. ¿Continuar?');

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
        'orphan_usermeta' => 'Usermeta huérfano',
        'as_orphan_logs' => 'AS logs huérfanos',
        'as_old_completed_logs' => 'AS logs antiguos >30 días',
        'as_orphan_claims' => 'AS claims huérfanos >24 h',
        'wc_expired_reserved_stock' => 'Reservas stock WC expiradas',
        'wc_orphan_order_itemmeta' => 'Order item meta huérfano',
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
    echo '<td>' . wp_kses_post(seo_clean_db_badge($risk_status, $risk)) . '</td>';
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
    echo '<h2>Backup solo TaxoCommerce SEO</h2>';
    echo '<p>Incluye únicamente tablas internas cuyo nombre empieza por <code>' . esc_html($GLOBALS['wpdb']->prefix . 'seo_') . '</code>.</p>';
    echo '<p>Tablas detectadas: <strong>' . esc_html(number_format_i18n(count($seo_tables))) . '</strong></p>';
    echo '<p><a class="button" href="' . esc_url(seo_clean_db_export_url('seo')) . '">Descargar solo tablas TaxoCommerce SEO</a></p>';
    echo '</div>';

    if (!empty($seo_tables)) {
        echo '<div class="seo-clean-card"><h2>Tablas SEO detectadas</h2><ul>';
        foreach ($seo_tables as $table) {
            echo '<li><code>' . esc_html($table) . '</code></li>';
        }
        echo '</ul></div>';
    }
}
