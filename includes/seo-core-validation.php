<?php

defined('ABSPATH') || exit;

if (!defined('SEO_CORE_SYSTEM_TEST_VERSION')) {
    define('SEO_CORE_SYSTEM_TEST_VERSION', '8.9.0');
}

$seo_core_settings_module = __DIR__ . '/seo-core-validation-settings.php';
if (is_readable($seo_core_settings_module)) {
    require_once $seo_core_settings_module;
}

$seo_core_data_layer_test_module = __DIR__ . '/seo-core-validation-data-layer.php';
if (is_readable($seo_core_data_layer_test_module)) {
    require_once $seo_core_data_layer_test_module;
}

$seo_core_semantic_test_module = __DIR__ . '/seo-core-validation-semantic.php';
if (is_readable($seo_core_semantic_test_module)) {
    require_once $seo_core_semantic_test_module;
}

$seo_core_visual_test_module = __DIR__ . '/seo-core-validation-visual.php';
if (is_readable($seo_core_visual_test_module)) {
    require_once $seo_core_visual_test_module;
}

$seo_core_billing_test_module = __DIR__ . '/seo-core-validation-billing.php';
if (is_readable($seo_core_billing_test_module)) {
    require_once $seo_core_billing_test_module;
}

/**
 * Returns every published WooCommerce product whose native short description
 * (wp_posts.post_excerpt) is empty. This is intentionally queried on demand so
 * exports do not bloat the stored validation snapshot with thousands of rows.
 */
function seo_core_system_test_missing_product_excerpt_rows() {
    global $wpdb;

    $sql = "
        SELECT
            p.ID AS product_id,
            COALESCE(sku.sku, '') AS sku,
            p.post_title AS title,
            p.post_status AS status,
            p.post_date AS created_at,
            p.post_modified AS modified_at,
            CHAR_LENGTH(TRIM(COALESCE(p.post_content, ''))) AS description_length,
            CHAR_LENGTH(TRIM(COALESCE(p.post_excerpt, ''))) AS excerpt_length
        FROM {$wpdb->posts} p
        LEFT JOIN (
            SELECT post_id, MAX(meta_value) AS sku
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_sku'
            GROUP BY post_id
        ) sku ON sku.post_id = p.ID
        WHERE p.post_type = 'product'
          AND p.post_status = 'publish'
          AND TRIM(COALESCE(p.post_excerpt, '')) = ''
        ORDER BY p.ID ASC
    ";

    $rows = $wpdb->get_results($sql, ARRAY_A);
    return is_array($rows) ? $rows : array();
}

/**
 * Builds a protected admin-post URL for the missing product excerpt export.
 */
function seo_core_system_test_product_excerpt_export_url($format) {
    $format = sanitize_key((string) $format);
    if (!in_array($format, array('json', 'csv'), true)) {
        $format = 'json';
    }

    $url = add_query_arg(
        array(
            'action' => 'seo_core_export_missing_product_excerpts',
            'format' => $format,
        ),
        admin_url('admin-post.php')
    );

    return wp_nonce_url($url, 'seo_core_export_missing_product_excerpts');
}

/**
 * Renders JSON/CSV download buttons next to check 10.3A.
 */
function seo_core_system_test_render_product_excerpt_exports($result = array()) {
    $evidence = isset($result['evidence']) && is_array($result['evidence']) ? $result['evidence'] : array();
    $count = isset($evidence['without_excerpt']) ? (int) $evidence['without_excerpt'] : 0;

    echo '<div style="margin-top:10px;padding-top:8px;border-top:1px solid #dcdcde;">';
    echo '<strong>Descarga de afectados</strong>';
    echo '<p class="description" style="margin:4px 0 8px;">Lista completa actual de productos publicados con <code>wp_posts.post_excerpt</code> vacio. El ultimo chequeo detecto ' . esc_html(number_format_i18n($count)) . '.</p>';
    echo '<a class="button button-secondary button-small" href="' . esc_url(seo_core_system_test_product_excerpt_export_url('json')) . '">Descargar JSON</a> ';
    echo '<a class="button button-secondary button-small" href="' . esc_url(seo_core_system_test_product_excerpt_export_url('csv')) . '">Descargar CSV</a>';
    echo '</div>';
}

/**
 * Prevents spreadsheet formula injection in exported CSV text cells.
 */
function seo_core_system_test_csv_safe_value($value) {
    $value = (string) $value;
    if ($value !== '' && preg_match('/^[=+\\-@]/', $value)) {
        return "'" . $value;
    }
    return $value;
}

/**
 * Downloads all products affected by check 10.3A as JSON or semicolon CSV.
 */
function seo_core_system_test_export_missing_product_excerpts() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para exportar estos datos.', 'seo-system'), '', array('response' => 403));
    }

    check_admin_referer('seo_core_export_missing_product_excerpts');

    $format = isset($_GET['format']) ? sanitize_key(wp_unslash($_GET['format'])) : 'json';
    if (!in_array($format, array('json', 'csv'), true)) {
        wp_die(esc_html__('Formato de exportacion no valido.', 'seo-system'), '', array('response' => 400));
    }

    $rows = seo_core_system_test_missing_product_excerpt_rows();
    $generated_at = wp_date(DATE_ATOM);
    $stamp = wp_date('Ymd-His');
    $filename = sanitize_file_name('seo-products-sin-excerpt-' . $stamp . '.' . $format);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    nocache_headers();
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        $payload = array(
            'schema_version' => 1,
            'generated_at' => $generated_at,
            'site' => home_url('/'),
            'check' => '10.3A Cobertura de descripcion corta de producto',
            'source' => 'wp_posts.post_excerpt',
            'filter' => array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'post_excerpt' => 'empty',
            ),
            'total' => count($rows),
            'results' => $rows,
        );
        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        wp_die(esc_html__('No se pudo abrir la salida CSV.', 'seo-system'));
    }

    // UTF-8 BOM improves Excel compatibility; semicolon matches common es-ES CSV imports.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv(
        $out,
        array('product_id', 'sku', 'title', 'status', 'created_at', 'modified_at', 'description_length', 'excerpt_length', 'source'),
        ';',
        '"',
        ''
    );

    foreach ($rows as $row) {
        fputcsv(
            $out,
            array(
                (int) ($row['product_id'] ?? 0),
                seo_core_system_test_csv_safe_value($row['sku'] ?? ''),
                seo_core_system_test_csv_safe_value($row['title'] ?? ''),
                seo_core_system_test_csv_safe_value($row['status'] ?? ''),
                seo_core_system_test_csv_safe_value($row['created_at'] ?? ''),
                seo_core_system_test_csv_safe_value($row['modified_at'] ?? ''),
                (int) ($row['description_length'] ?? 0),
                (int) ($row['excerpt_length'] ?? 0),
                'wp_posts.post_excerpt',
            ),
            ';',
            '"',
            ''
        );
    }

    fclose($out);
    exit;
}
add_action('admin_post_seo_core_export_missing_product_excerpts', 'seo_core_system_test_export_missing_product_excerpts');

$seo_core_persistence_test_module = __DIR__ . '/seo-core-validation-persistence.php';
$seo_core_persistence_test_legacy_module = __DIR__ . '/seo-core-system-test-persistence.php';
if (is_readable($seo_core_persistence_test_module)) {
    require_once $seo_core_persistence_test_module;
} elseif (is_readable($seo_core_persistence_test_legacy_module)) {
    require_once $seo_core_persistence_test_legacy_module;
}

// SEO Core - Plugin Validation
// Sistema de validación interna orientado a continuidad de negocio.
// La validación automática es de solo lectura. Las pruebas activas del Data Layer
// requieren una acción manual, usan filas __seo_test__ y limpian sus artefactos.
// Objetivo: responder rápido a la pregunta: ¿la última subida ha roto algo?
// V8.6: añade auditoria visual responsive remota con Chromium/Playwright sin cargar el hosting.


function seo_core_system_test() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para acceder a esta página.', 'seo-system'));
    }

    $active_tab = seo_core_system_test_get_active_tab();
    $results = null;
    $settings_notice = function_exists('seo_core_validation_handle_settings_actions') ? seo_core_validation_handle_settings_actions() : null;
    $diagnostics_notice = seo_core_system_test_handle_diagnostics_actions();

    if (
        isset($_POST['seo_core_system_test_run_data_layer']) &&
        check_admin_referer('seo_core_system_test_run_data_layer', 'seo_core_system_test_data_layer_nonce')
    ) {
        $run_started_at = time();
        $run_started_microtime = microtime(true);

        if (function_exists('seo_core_system_test_data_layer_run_active_tests')) {
            seo_core_system_test_data_layer_run_active_tests();
        }
        $results = seo_core_system_test_run_all(
            'manual_data_layer',
            $run_started_at,
            $run_started_microtime
        );
        $active_tab = 'advanced';
    } elseif (
        isset($_POST['seo_core_system_test_run']) &&
        check_admin_referer('seo_core_system_test_run', 'seo_core_system_test_nonce')
    ) {
        $results = seo_core_system_test_run_all('manual');
    } elseif (
        isset($_POST['seo_core_system_test_run_404']) &&
        check_admin_referer('seo_core_system_test_run_404', 'seo_core_system_test_404_nonce')
    ) {
        $audit_action = isset($_POST['seo_core_system_test_404_action'])
            ? sanitize_key(wp_unslash($_POST['seo_core_system_test_404_action']))
            : 'next';

        if ($audit_action === 'reset') {
            seo_core_system_test_reset_link_audit_state();
            $link_results = seo_core_system_test_links_404_not_run_results();
            seo_core_system_test_store_results_bundle('links', $link_results);
        } else {
            $phase = in_array($audit_action, array('links', 'seo', 'resources'), true)
                ? $audit_action
                : seo_core_system_test_next_link_audit_phase();
            seo_core_system_test_run_link_audit_phase($phase);
        }
        $results = seo_core_system_test_get_reporting_results();
        $active_tab = 'advanced';
    } else {
        $stored_results = seo_core_system_test_get_reporting_results();
        if (!empty($stored_results)) {
            $results = $stored_results;
        }
    }

    if ($active_tab === 'advanced' && function_exists('seo_core_system_test_data_layer_active_results')) {
        $active_results = seo_core_system_test_data_layer_active_results();
        if (!empty($active_results)) {
            $results = is_array($results) ? array_merge($results, $active_results) : $active_results;
        }
    }

    echo '<div class="wrap seo-core-system-test-wrap">';
    echo '<h1>Plugin Validation</h1>';
    echo '<p>Chequeo interno de SEO System orientado a confirmar si el negocio sigue funcionando tras una subida.</p>';

    seo_core_system_test_render_styles();
    if (function_exists('seo_core_validation_render_settings_notice')) {
        seo_core_validation_render_settings_notice($settings_notice);
    }
    seo_core_system_test_render_diagnostics_notice($diagnostics_notice);
    if ($active_tab === 'summary') {
        if (function_exists('seo_system_diagnostics_render_export_panel')) {
            seo_system_diagnostics_render_export_panel('core_summary');
        }
        // Consentimiento visible y reversible para el envio de diagnosticos al desarrollador.
        // La funcion ya existia, pero habia quedado sin llamada desde la interfaz.
        seo_core_system_test_render_diagnostics_controls($results, false);
    }
    seo_core_system_test_render_run_button($active_tab);
    if ($active_tab !== 'settings') {
        seo_core_system_test_render_run_metadata($active_tab);
    }
    seo_core_system_test_render_tabs($active_tab);

    echo '<div class="seo-core-test-panel">';

    if ($active_tab === 'settings' && function_exists('seo_core_validation_render_settings_page')) {
        $settings_results = is_array($results) ? $results : seo_core_system_test_get_reporting_results();
        seo_core_validation_render_settings_page($settings_results);
    } elseif ($results === null) {
        if (in_array($active_tab, array('code_integrity', 'advanced'), true)) {
            seo_core_system_test_render_compact_health(array(), $active_tab);
        }
        seo_core_system_test_render_intro();
    } else {
        seo_core_system_test_render_results($results, $active_tab);
    }

    if ($active_tab === 'code_integrity') {
        echo '<h2>Inventario de funciones y archivos del plugin</h2>';
        echo '<p class="seo-core-test-muted">Este inventario pertenece únicamente a Integridad del código.</p>';
        seo_core_system_test_render_function_inventory();
    }

    echo '</div>';
    echo '</div>';
}



function seo_core_system_test_get_active_tab() {
    $tab = isset($_GET['seo_core_test_tab'])
        ? sanitize_key(wp_unslash($_GET['seo_core_test_tab']))
        : 'summary';

    if (in_array($tab, array('summary', 'code_integrity', 'advanced', 'settings'), true)) {
        return $tab;
    }

    if ($tab === 'final_report') {
        return 'summary';
    }

    $legacy_sections = array(
        'functional',
        'visual',
        'links_404',
        'system',
        'templates',
        'catalog',
        'checkout',
        'emails',
        'seo_system',
        'technical',
        'data_layer',
        'semantic',
    );

    return in_array($tab, $legacy_sections, true) ? 'advanced' : 'summary';
}

function seo_core_system_test_get_requested_section() {
    if (isset($_POST['seo_core_system_test_run_404'])) {
        return 'links_404';
    }
    if (isset($_POST['seo_core_system_test_run_data_layer'])) {
        return 'data_layer';
    }

    $section = isset($_GET['seo_core_test_section'])
        ? sanitize_key(wp_unslash($_GET['seo_core_test_section']))
        : '';

    $legacy_tab = isset($_GET['seo_core_test_tab'])
        ? sanitize_key(wp_unslash($_GET['seo_core_test_tab']))
        : '';

    $allowed = array(
        'functional',
        'visual',
        'links_404',
        'system',
        'templates',
        'catalog',
        'checkout',
        'emails',
        'seo_system',
        'technical',
        'data_layer',
        'semantic',
    );

    if (in_array($section, $allowed, true)) {
        return $section;
    }
    return in_array($legacy_tab, $allowed, true) ? $legacy_tab : '';
}



function seo_core_system_test_render_run_button($active_tab = 'summary') {
    if ($active_tab === 'settings') {
        return;
    }

    echo '<form method="post" style="margin:16px 0 20px;">';
    wp_nonce_field('seo_core_system_test_run', 'seo_core_system_test_nonce');
    echo '<input type="hidden" name="seo_core_system_test_run" value="1">';
    submit_button('Ejecutar validación completa', 'primary', 'submit', false);
    if ($active_tab === 'advanced') {
        echo '<p class="description" style="margin-top:8px;">Actualiza todos los chequeos pasivos. La auditoría 404 y la prueba transaccional del Data Layer conservan sus controles propios dentro de Chequeos avanzados.</p>';
    } else {
        echo '<p class="description" style="margin-top:8px;">Los tests se ejecutan en una sola pasada; cada vista solo organiza y filtra los resultados.</p>';
    }
    echo '</form>';
}

function seo_core_system_test_render_link_audit_controls() {
    $state = seo_core_system_test_load_link_audit_state();
    $completed = isset($state['completed']) && is_array($state['completed']) ? $state['completed'] : array();
    $next_phase = seo_core_system_test_next_link_audit_phase($state);
    $labels = array(
        'links' => 'Enlaces prioritarios',
        'seo' => 'Sitemap y redirecciones',
        'resources' => 'Imágenes y recursos',
    );
    $next_label = isset($labels[$next_phase]) ? $labels[$next_phase] : 'Enlaces prioritarios';

    echo '<div class="seo-core-inline-actions">';
    echo '<form method="post">';
    wp_nonce_field('seo_core_system_test_run_404', 'seo_core_system_test_404_nonce');
    echo '<input type="hidden" name="seo_core_system_test_run_404" value="1">';
    echo '<input type="hidden" name="seo_core_system_test_404_action" value="next">';
    submit_button('Ejecutar siguiente bloque: ' . $next_label, 'primary', 'submit', false);
    echo '</form>';

    foreach ($labels as $phase => $label) {
        echo '<form method="post">';
        wp_nonce_field('seo_core_system_test_run_404', 'seo_core_system_test_404_nonce');
        echo '<input type="hidden" name="seo_core_system_test_run_404" value="1">';
        echo '<input type="hidden" name="seo_core_system_test_404_action" value="' . esc_attr($phase) . '">';
        submit_button((in_array($phase, $completed, true) ? 'Repetir: ' : '') . $label, 'secondary', 'submit', false);
        echo '</form>';
    }

    echo '<form method="post">';
    wp_nonce_field('seo_core_system_test_run_404', 'seo_core_system_test_404_nonce');
    echo '<input type="hidden" name="seo_core_system_test_run_404" value="1">';
    echo '<input type="hidden" name="seo_core_system_test_404_action" value="reset">';
    submit_button('Reiniciar auditoría', 'delete', 'submit', false);
    echo '</form>';
    echo '</div>';
    echo '<p class="description">La auditoría se divide en tres peticiones independientes. Los resultados se acumulan y cada bloque puede repetirse sin volver a ejecutar los demás.</p>';
}

function seo_core_system_test_render_data_layer_controls() {
    echo '<div class="seo-core-inline-actions">';
    echo '<form method="post">';
    wp_nonce_field('seo_core_system_test_run', 'seo_core_system_test_nonce');
    echo '<input type="hidden" name="seo_core_system_test_run" value="1">';
    submit_button('Actualizar chequeos pasivos', 'secondary', 'submit', false);
    echo '</form>';

    echo '<form method="post" onsubmit="return confirm(\'La prueba creará y eliminará relaciones temporales __seo_test__. ¿Continuar?\');">';
    wp_nonce_field('seo_core_system_test_run_data_layer', 'seo_core_system_test_data_layer_nonce');
    echo '<input type="hidden" name="seo_core_system_test_run_data_layer" value="1">';
    submit_button('Ejecutar prueba transaccional controlada', 'primary', 'submit', false);
    echo '</form>';
    echo '</div>';
}



function seo_core_system_test_tab_label($tab_id) {
    $labels = array(
        'summary'        => 'Resumen',
        'code_integrity' => 'Integridad del código',
        'advanced'       => 'Chequeos avanzados',
        'settings'       => 'Configuración',
        'functional'     => 'Funcionamiento público',
        'visual'         => 'Responsive y calidad visual',
        'links_404'      => 'Enlaces y 404',
        'system'         => 'Entorno del plugin',
        'templates'      => 'Plantillas',
        'catalog'        => 'Catálogo',
        'checkout'       => 'Compra',
        'emails'         => 'Correos',
        'seo_system'     => 'Datos internos SEO Core',
        'technical'      => 'Técnico',
        'data_layer'     => 'Data Layer',
        'semantic'       => 'Contenido y semántica',
        'final_report'   => 'Informe técnico',
    );

    return isset($labels[$tab_id]) ? $labels[$tab_id] : (string) $tab_id;
}



function seo_core_system_test_tab_description($tab_id) {
    $descriptions = array(
        'summary'        => 'Indicadores globales, exportación PDF/JSON e informe técnico plegado.',
        'code_integrity' => 'La vista principal para revisar archivos, sintaxis, funciones, tipos, hooks y duplicados.',
        'advanced'       => 'Todos los demás chequeos, agrupados en secciones plegables sin perder ninguna prueba.',
        'settings'       => 'Muestras, tolerancias, debug y sugerencias de corrección asistida.',
        'functional'     => 'Navegación pública, respuesta HTML, búsqueda, encabezados, canonical y datos estructurados.',
        'visual'         => 'Renderizado real en móvil, tablet y escritorio: overflow, recortes, solapamientos, imágenes y errores del navegador.',
        'links_404'      => 'Enlaces prioritarios, sitemap, redirecciones, imágenes y recursos.',
        'system'         => 'Rutas, dependencias y requisitos mínimos que necesita el plugin para cargarse.',
        'templates'      => 'Disponibilidad y renderizado de las plantillas propias del plugin.',
        'catalog'        => 'Productos, categorías, visibilidad, precio, stock y datos del catálogo.',
        'checkout'       => 'Carrito, checkout, facturas, proformas y presupuestos sin crear pedidos de prueba ni ejecutar cobros.',
        'emails'         => 'Plantillas y callbacks de correo relacionados con la operativa del plugin.',
        'seo_system'     => 'Tablas, registros y estructuras internas administradas por SEO Core.',
        'technical'      => 'Cron, caché, índices, configuración técnica y compatibilidad operativa.',
        'data_layer'     => 'Persistencia, operaciones, rollback, conflictos y Action Scheduler.',
        'semantic'       => 'Contenido de productos y categorías, atributos, etiquetas, relaciones y FAQs.',
        'final_report'   => 'Salida consolidada en texto para revisión o envío al administrador.',
    );

    return isset($descriptions[$tab_id]) ? $descriptions[$tab_id] : '';
}



function seo_core_system_test_render_tabs($active_tab) {
    $tab_ids = array('summary', 'code_integrity', 'advanced', 'settings');

    echo '<nav class="nav-tab-wrapper seo-core-test-tabs">';
    foreach ($tab_ids as $tab_id) {
        $class = ($active_tab === $tab_id) ? ' nav-tab-active' : '';
        $url = add_query_arg('seo_core_test_tab', $tab_id);
        echo '<a href="' . esc_url($url) . '" class="nav-tab' . esc_attr($class) . '">' . esc_html(seo_core_system_test_tab_label($tab_id)) . '</a>';
    }
    echo '</nav>';
}



function seo_core_system_test_render_styles() {
    echo '<style>
        .seo-core-test-panel{background:#fff;border:1px solid #ccd0d4;border-top:none;padding:20px;max-width:1320px;}
        .seo-core-test-tabs{margin-top:15px;}
        .seo-core-test-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;margin:20px 0;}
        .seo-core-test-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px;margin:0 0 18px;}
        .seo-core-test-card h2{margin-top:0;font-size:18px;}
        .seo-core-test-kpi{font-size:28px;font-weight:700;line-height:1.2;}
        .seo-core-test-table{width:100%;border-collapse:collapse;margin-top:15px;}
        .seo-core-test-table th,.seo-core-test-table td{border-bottom:1px solid #e5e5e5;padding:10px;text-align:left;vertical-align:top;}
        .seo-core-test-table th{background:#f6f7f7;font-weight:600;}
        .seo-core-test-badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap;}
        .seo-core-test-ok{background:#d1e7dd;color:#0f5132;}
        .seo-core-test-warning{background:#fff3cd;color:#664d03;}
        .seo-core-test-ko{background:#f8d7da;color:#842029;}
        .seo-core-test-info{background:#cff4fc;color:#055160;}
        .seo-core-test-muted{color:#646970;}
        .seo-core-test-note{border-left:4px solid #72aee6;background:#f0f6fc;padding:12px 14px;margin:16px 0;}
        .seo-core-test-report{width:100%;min-height:300px;font-family:Consolas,Monaco,monospace;font-size:13px;white-space:pre;}
        .seo-core-test-section{margin:0 0 28px;}
        .seo-core-test-section h2{border-bottom:1px solid #dcdcde;padding-bottom:8px;margin-top:8px;}
        .seo-core-test-details{border:1px solid #dcdcde;border-radius:6px;background:#fff;margin:14px 0;}
        .seo-core-test-details>summary{cursor:pointer;padding:12px 14px;font-weight:600;background:#f6f7f7;}
        .seo-core-test-details-content{padding:0 14px 14px;overflow:auto;}
        .seo-core-test-code{font-family:Consolas,Monaco,monospace;font-size:12px;}
        .seo-core-test-path{word-break:break-word;}
        .seo-core-test-search{width:100%;max-width:520px;margin:10px 0 4px;}
        .seo-core-test-empty{padding:12px 0;color:#646970;}
        .seo-core-test-table-wrap{overflow:auto;max-height:620px;}
        .seo-core-health-strip{display:grid;grid-template-columns:minmax(190px,1.35fr) repeat(4,minmax(115px,.75fr));gap:10px;margin:0 0 20px;}
        .seo-core-health-box{border:1px solid #dcdcde;border-left-width:5px;border-radius:7px;padding:12px 14px;background:#fff;min-width:0;}
        .seo-core-health-box strong{display:block;font-size:11px;letter-spacing:.04em;text-transform:uppercase;color:#50575e;margin-bottom:4px;}
        .seo-core-health-box .seo-core-health-value{font-size:22px;font-weight:750;line-height:1.15;}
        .seo-core-health-critical{border-left-color:#d63638;background:#fff5f5;}
        .seo-core-health-important{border-left-color:#d97706;background:#fff8ed;}
        .seo-core-health-warning{border-left-color:#dba617;background:#fffbea;}
        .seo-core-health-ok{border-left-color:#00a32a;background:#f3fbf5;}
        .seo-core-health-info{border-left-color:#2271b1;background:#f0f6fc;}
        .seo-core-priority-list{display:grid;gap:8px;margin:14px 0 22px;}
        .seo-core-priority-item{display:grid;grid-template-columns:110px minmax(180px,1fr) 2fr;gap:12px;align-items:start;border:1px solid #dcdcde;border-radius:6px;padding:10px 12px;background:#fff;}
        .seo-core-priority-item code{word-break:break-word;}
        .seo-core-module-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:16px 0 24px;}
        .seo-core-module-card{border:1px solid #dcdcde;border-radius:7px;padding:14px;background:#fff;}
        .seo-core-module-card h3{margin:0 0 8px;font-size:15px;}
        .seo-core-module-score{font-size:24px;font-weight:750;}
        .seo-core-diagnostics-consent{border:1px solid #c3c4c7;border-left:5px solid #2271b1;background:#f6f7f7;border-radius:7px;padding:16px 18px;margin:16px 0 20px;max-width:920px;}
        .seo-core-diagnostics-consent h2{margin:0 0 8px;font-size:18px;}
        .seo-core-diagnostics-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px;}
        .seo-core-diagnostics-privacy{margin:10px 0 0;color:#50575e;}
        .seo-core-diagnostics-notice{max-width:920px;margin:12px 0;}
        .seo-core-run-meta{display:flex;gap:10px;align-items:stretch;flex-wrap:wrap;margin:12px 0 18px;padding:12px;background:#fff;border:1px solid #dcdcde;border-left:4px solid #2271b1;border-radius:4px;}
        .seo-core-run-meta.is-stale{border-left-color:#dba617;}
        .seo-core-run-meta.is-very-stale{border-left-color:#d63638;}
        .seo-core-run-meta-item{min-width:145px;padding:2px 10px 2px 0;}
        .seo-core-run-meta-item strong{display:block;margin-bottom:3px;}
        .seo-core-run-meta-main{min-width:230px;}
        .seo-core-remediation-details{margin-top:8px;}
        .seo-core-remediation-details>summary{cursor:pointer;color:#2271b1;font-weight:600;}
        .seo-core-remediation-body{border-left:3px solid #72aee6;padding:8px 12px;margin-top:8px;background:#f6f7f7;}
        .seo-core-remediation-body ol{margin:8px 0 8px 20px;}
        .seo-core-debug-json{max-height:360px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:12px;border-radius:4px;white-space:pre-wrap;word-break:break-word;}
        .seo-core-settings-form{max-width:1050px;}
        .seo-core-remediation-center{margin-top:28px;max-width:1100px;}
        .seo-core-remediation-card{border:1px solid #dcdcde;border-radius:7px;padding:14px 16px;margin:12px 0;background:#fff;}
        .seo-core-remediation-card header{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
        .seo-core-scope-note{border:1px solid #9ec5e5;border-left:5px solid #2271b1;background:#f0f6fc;border-radius:7px;padding:14px 16px;margin:0 0 20px;}
        .seo-core-scope-note strong{display:block;margin-bottom:4px;}
        .seo-core-nav-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px;margin:18px 0;}
        .seo-core-nav-card{display:block;text-decoration:none;background:#fff;border:1px solid #dcdcde;border-radius:7px;padding:14px 16px;color:#1d2327;}
        .seo-core-nav-card:hover{border-color:#2271b1;box-shadow:0 2px 8px rgba(0,0,0,.06);color:#135e96;}
        .seo-core-nav-card strong{display:block;font-size:15px;margin-bottom:5px;}
        .seo-core-nav-card span{display:block;color:#646970;line-height:1.45;}
        .seo-core-diagnostics-compact{border:1px solid #dcdcde;border-radius:7px;background:#fff;margin:14px 0 0;}
        .seo-core-diagnostics-compact>summary{cursor:pointer;padding:11px 13px;font-weight:600;}
        .seo-core-diagnostics-compact-body{padding:0 13px 13px;}
        .seo-core-inline-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:12px 0;}
        .seo-core-inline-actions form{margin:0;}
        .seo-core-advanced-bundle{border-top:1px solid #dcdcde;padding-top:18px;margin-top:24px;}
        .seo-core-advanced-heading{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:12px;}
        .seo-core-advanced-heading h3{margin:0 0 4px;font-size:18px;}
        .seo-core-advanced-heading p{margin:0;color:#646970;}
        .seo-core-advanced-heading>div:last-child{text-align:right;white-space:nowrap;}
        .seo-core-advanced-heading>div:last-child strong{display:block;font-size:22px;}
        .seo-core-advanced-heading>div:last-child span{color:#646970;}
        .seo-core-advanced-group>summary span{display:block;}
        .seo-core-advanced-group>summary small{display:block;margin-top:4px;color:#646970;font-weight:400;}
        .seo-core-nav-card.is-primary{border-color:#2271b1;border-left-width:5px;background:#f0f6fc;}
        .seo-core-summary-report{margin-top:22px;}
        @media(max-width:900px){.seo-core-health-strip{grid-template-columns:repeat(2,minmax(140px,1fr));}.seo-core-health-box:first-child{grid-column:1/-1}.seo-core-priority-item{grid-template-columns:1fr;gap:5px;}.seo-core-run-meta-item{min-width:calc(50% - 10px);}}
    </style>';
}

function seo_core_system_test_render_intro() {
    echo '<h2>Validación pendiente</h2>';
    echo '<p>Pulsa <strong>Ejecutar validación completa</strong> para lanzar los chequeos básicos de continuidad del sistema.</p>';
    echo '<div class="seo-core-test-note">';
    echo '<strong>V7.0:</strong> guarda el último diagnóstico, muestra primero lo crítico y expone un resumen seguro para el módulo de informes. La auditoría de Enlaces y 404 continúa dividida en tres bloques acumulativos.';
    echo '</div>';
}


/**
 * Notifica a los módulos de informes que una ejecución ha finalizado.
 * El hook no realiza el envío por sí mismo y mantiene desacoplada la suite.
 *
 * @param string $scope
 * @param string $origin
 * @param array  $results
 * @param array  $metadata
 */
function seo_core_system_test_dispatch_completed_run($scope, $origin, $results, $metadata) {
    $results = is_array($results) ? $results : array();
    $metadata = is_array($metadata) ? $metadata : array();

    do_action(
        'seo_core_system_test_completed',
        array(
            'scope'        => sanitize_key((string) $scope),
            'origin'       => sanitize_key((string) $origin),
            'metadata'     => $metadata,
            'result_count' => count($results),
            'summary'      => seo_core_system_test_get_summary($results),
        )
    );
}

function seo_core_system_test_run_all(
    $origin = 'manual',
    $started_at = null,
    $started_microtime = null
) {
    $started_at = is_int($started_at) ? $started_at : time();
    $started_microtime = is_numeric($started_microtime) ? (float) $started_microtime : microtime(true);
    $results = array();

    $results = array_merge($results, seo_core_system_test_code_integrity());
    if (function_exists('seo_core_system_test_persistence_results')) {
        $results = array_merge($results, seo_core_system_test_persistence_results());
    }
    $results = array_merge($results, seo_core_system_test_system_general());
    $results = array_merge($results, seo_core_system_test_template_loading());
    $results = array_merge($results, seo_core_system_test_catalog());
    $results = array_merge($results, seo_core_system_test_checkout());
    $results = array_merge($results, seo_core_system_test_emails());
    $results = array_merge($results, seo_core_system_test_seo_system());
    $results = array_merge($results, seo_core_system_test_functional_business());
    if (function_exists('seo_core_system_test_visual_results')) {
        $results = array_merge($results, seo_core_system_test_visual_results());
    }
    if (function_exists('seo_core_system_test_data_layer_passive_checks')) {
        $results = array_merge($results, seo_core_system_test_data_layer_passive_checks());
    }
    if (function_exists('seo_core_system_test_semantic_checks')) {
        $results = array_merge($results, seo_core_system_test_semantic_checks());
    }
    $results = array_merge($results, seo_core_system_test_technical());

    $metadata = seo_core_system_test_build_run_metadata(
        $origin,
        $started_at,
        $started_microtime
    );
    seo_core_system_test_store_results_bundle('general', $results, $metadata);
    $reporting_results = seo_core_system_test_get_reporting_results();
    seo_core_system_test_dispatch_completed_run('general', $origin, $reporting_results, $metadata);
    return $reporting_results;
}

/**
 * Ejecuta la parte funcional adecuada para tareas programadas.
 * El inventario estatico completo se reserva por defecto para actualizaciones.
 */
function seo_core_system_test_run_telemetry_suite($include_code_integrity = false) {
    $started_at = time();
    $started_microtime = microtime(true);
    $origin = $include_code_integrity ? 'plugin_update' : 'scheduled';
    $results = array();

    if ($include_code_integrity) {
        $results = array_merge($results, seo_core_system_test_code_integrity());
        if (function_exists('seo_core_system_test_persistence_results')) {
            $results = array_merge($results, seo_core_system_test_persistence_results());
        }
    }

    $results = array_merge($results, seo_core_system_test_system_general());
    $results = array_merge($results, seo_core_system_test_template_loading());
    $results = array_merge($results, seo_core_system_test_catalog());
    $results = array_merge($results, seo_core_system_test_checkout());
    $results = array_merge($results, seo_core_system_test_emails());
    $results = array_merge($results, seo_core_system_test_seo_system());
    $results = array_merge($results, seo_core_system_test_functional_business());
    if (function_exists('seo_core_system_test_visual_results')) {
        $results = array_merge($results, seo_core_system_test_visual_results());
    }
    if (function_exists('seo_core_system_test_data_layer_passive_checks')) {
        $results = array_merge($results, seo_core_system_test_data_layer_passive_checks());
    }
    if (function_exists('seo_core_system_test_semantic_checks')) {
        $results = array_merge($results, seo_core_system_test_semantic_checks());
    }
    $results = array_merge($results, seo_core_system_test_technical());

    $metadata = seo_core_system_test_build_run_metadata(
        $origin,
        $started_at,
        $started_microtime
    );
    seo_core_system_test_store_results_bundle('general', $results, $metadata);
    $reporting_results = seo_core_system_test_get_reporting_results();
    seo_core_system_test_dispatch_completed_run('general', $origin, $reporting_results, $metadata);
    return $reporting_results;
}

function seo_core_system_test_code_integrity() {
    $inventory = seo_core_system_test_get_code_inventory();

    $duplicate_function_count = count($inventory['duplicate_functions']);
    $duplicate_type_count = count($inventory['duplicate_types']);
    $duplicate_method_count = count($inventory['duplicate_methods']);
    $unresolved_callback_count = count($inventory['unresolved_callbacks']);
    $syntax_error_count = count($inventory['syntax_errors']);
    $short_tag_count = count($inventory['short_open_tags']);
    $unreadable_count = count($inventory['unreadable_files']);
    $skipped_count = count($inventory['skipped_files']);
    $unreferenced_function_count = count($inventory['unreferenced_functions']);
    $entry_point_count = count($inventory['entry_points']);
    $manual_entry_point_count = count($inventory['manual_entry_points']);
    $rest_route_count = count($inventory['rest_routes']);
    $cron_registration_count = count($inventory['cron_registrations']);

    return array(
        seo_core_system_test_result(
            'code_integrity',
            '0.1 Ruta del código detectada',
            is_dir($inventory['root']) && is_readable($inventory['root']),
            $inventory['root']
        ),
        seo_core_system_test_result(
            'code_integrity',
            '0.2 Archivos PHP inventariados',
            $inventory['file_count'] > 0,
            'Detectados: ' . number_format_i18n($inventory['file_count']) . '; analizados: ' . number_format_i18n($inventory['analyzed_file_count']),
            $inventory['file_count'] > 0 ? 'info' : 'ko'
        ),
        seo_core_system_test_result(
            'code_integrity',
            '0.3 Archivos PHP legibles',
            $unreadable_count === 0,
            $unreadable_count === 0 ? 'Todos los archivos inventariados son legibles' : 'No legibles: ' . number_format_i18n($unreadable_count),
            $unreadable_count === 0 ? 'ok' : 'ko'
        ),
        seo_core_system_test_result(
            'code_integrity',
            '0.4 Sintaxis PHP analizable',
            $syntax_error_count === 0,
            $syntax_error_count === 0 ? 'No se han detectado errores de sintaxis' : 'Errores detectados: ' . number_format_i18n($syntax_error_count),
            $syntax_error_count === 0 ? 'ok' : 'ko'
        ),
        seo_core_system_test_result(
            'code_integrity',
            '0.5 Etiquetas PHP cortas',
            $short_tag_count === 0,
            $short_tag_count === 0 ? 'No detectadas' : 'Archivos afectados: ' . number_format_i18n($short_tag_count),
            $short_tag_count === 0 ? 'ok' : 'ko'
        ),
        seo_core_system_test_result(
            'code_integrity',
            '0.6 Funciones globales inventariadas',
            true,
            'Funciones: ' . number_format_i18n(count($inventory['functions'])),
            'info'
        ),
        seo_core_system_test_result(
            'code_integrity',
            '0.7 Funciones globales duplicadas',
            $duplicate_function_count === 0,
            $duplicate_function_count === 0
                ? 'No se han detectado funciones duplicadas'
                : seo_core_system_test_duplicate_summary($inventory['duplicate_functions'], 'función'),
            $duplicate_function_count === 0 ? 'ok' : 'ko'
        ),
        seo_core_system_test_result(
            'code_integrity',
            '0.8 Tipos inventariados',
            true,
            seo_core_system_test_type_count_summary($inventory['types']),
            'info'
        ),
        seo_core_system_test_result(
            'code_integrity',
            '0.9 Clases, interfaces, traits o enums duplicados',
            $duplicate_type_count === 0,
            $duplicate_type_count === 0
                ? 'No se han detectado tipos duplicados'
                : seo_core_system_test_duplicate_summary($inventory['duplicate_types'], 'tipo'),
            $duplicate_type_count === 0 ? 'ok' : 'ko'
        ),
        seo_core_system_test_result(
            'code_integrity',
            '0.10 Métodos inventariados',
            true,
            'Métodos: ' . number_format_i18n(count($inventory['methods'])),
            'info'
        ),
        seo_core_system_test_result(
            'code_integrity',
            '0.11 Métodos duplicados dentro del mismo tipo',
            $duplicate_method_count === 0,
            $duplicate_method_count === 0
                ? 'No se han detectado métodos duplicados dentro de una misma clase, interfaz o trait'
                : seo_core_system_test_duplicate_summary($inventory['duplicate_methods'], 'método'),
            $duplicate_method_count === 0 ? 'ok' : 'ko'
        ),
        seo_core_system_test_result(
            'code_integrity',
            '0.12 Hooks literales inventariados',
            true,
            'Hooks: ' . number_format_i18n(count($inventory['hooks'])),
            'info'
        ),
        seo_core_system_test_result(
            'code_integrity',
            '0.13 Callbacks literales resolubles',
            $unresolved_callback_count === 0,
            $unresolved_callback_count === 0
                ? 'Todos los callbacks literales analizados tienen una declaración local o están disponibles en tiempo de ejecución'
                : 'Callbacks no resueltos: ' . number_format_i18n($unresolved_callback_count),
            $unresolved_callback_count === 0 ? 'ok' : 'warning'
        ),
        seo_core_system_test_result(
            'code_integrity',
            '0.14 Acciones AJAX literales inventariadas',
            true,
            'Registros AJAX: ' . number_format_i18n(count($inventory['ajax_hooks'])),
            'info'
        ),
        seo_core_system_test_result(
            'code_integrity',
            '0.15 Archivos omitidos durante el análisis',
            $skipped_count === 0,
            $skipped_count === 0 ? 'Ningún archivo PHP omitido' : 'Omitidos: ' . number_format_i18n($skipped_count),
            $skipped_count === 0 ? 'ok' : 'warning'
        ),
        seo_core_system_test_result(
            'code_integrity',
            '0.16 Llamadas directas a funciones inventariadas',
            true,
            'Detectadas: ' . number_format_i18n(count($inventory['function_calls']))
                . '; locales: ' . number_format_i18n($inventory['local_function_call_count'])
                . '; externas o de WordPress/PHP: ' . number_format_i18n(count($inventory['external_function_calls'])),
            'info'
        ),
        seo_core_system_test_result(
            'code_integrity',
            '0.17 Referencias de callback inventariadas',
            true,
            'Callbacks locales detectados: ' . number_format_i18n($inventory['local_callback_reference_count'])
                . '; referencias literales adicionales: ' . number_format_i18n(count($inventory['callable_references'])),
            'info'
        ),
        seo_core_system_test_result(
            'code_integrity',
            '0.18 Funciones sin referencias entrantes',
            $unreferenced_function_count === 0,
            $unreferenced_function_count === 0
                ? 'Todas las funciones globales tienen alguna referencia estática entrante o un punto de entrada reconocido'
                : 'Candidatas a revisión: ' . number_format_i18n($unreferenced_function_count) . '. No implica necesariamente código muerto: pueden existir llamadas dinámicas.',
            $unreferenced_function_count === 0 ? 'ok' : 'info'
        ),
        seo_core_system_test_result(
            'code_integrity',
            '0.19 Puntos de entrada inventariados',
            true,
            'Actions, filters, AJAX, shortcodes y hooks de ciclo de vida: ' . number_format_i18n($entry_point_count),
            'info'
        ),
        seo_core_system_test_result(
            'code_integrity',
            '0.20 Excepciones manuales de puntos de entrada',
            true,
            $manual_entry_point_count === 0
                ? 'No hay excepciones manuales activas'
                : 'Excepciones activas: ' . number_format_i18n($manual_entry_point_count) . '. Incluye seo_taxonomy_install en includes/seo-install.php.',
            'info'
        ),
        seo_core_system_test_result(
            'code_integrity',
            '0.21 Rutas REST literales inventariadas',
            true,
            'Rutas detectadas: ' . number_format_i18n($rest_route_count),
            'info'
        ),
        seo_core_system_test_result(
            'code_integrity',
            '0.22 Registros Cron literales inventariados',
            true,
            'Programaciones detectadas: ' . number_format_i18n($cron_registration_count),
            'info'
        ),
    );
}

function seo_core_system_test_get_code_inventory() {
    static $inventory = null;

    if ($inventory === null) {
        $inventory = seo_core_system_test_scan_codebase(seo_core_system_test_get_plugin_root());
    }

    return $inventory;
}

function seo_core_system_test_scan_codebase($plugin_root) {
    $plugin_root = trailingslashit(wp_normalize_path($plugin_root));
    $collection = seo_core_system_test_collect_php_files($plugin_root);

    $inventory = array(
        'root'                 => $plugin_root,
        'files'                => $collection['files'],
        'analyzed_files'       => $collection['analyzable_files'],
        'file_count'           => count($collection['files']),
        'analyzed_file_count'  => count($collection['analyzable_files']),
        'unreadable_files'     => $collection['unreadable_files'],
        'skipped_files'        => $collection['skipped_files'],
        'syntax_errors'        => array(),
        'short_open_tags'      => array(),
        'functions'            => array(),
        'methods'              => array(),
        'types'                => array(),
        'hooks'                => array(),
        'entry_points'         => array(),
        'manual_entry_points'  => array(),
        'rest_routes'          => array(),
        'cron_registrations'   => array(),
        'ajax_hooks'           => array(),
        'function_calls'       => array(),
        'callable_references'  => array(),
        'function_dependencies'=> array(),
        'unreferenced_functions' => array(),
        'external_function_calls' => array(),
        'local_function_call_count' => 0,
        'local_callback_reference_count' => 0,
        'duplicate_functions'  => array(),
        'duplicate_types'      => array(),
        'duplicate_methods'    => array(),
        'unresolved_callbacks' => array(),
    );

    foreach ($collection['analyzable_files'] as $relative_file) {
        $absolute_file = $plugin_root . ltrim($relative_file, '/');
        $content = @file_get_contents($absolute_file);

        if ($content === false) {
            if (!in_array($relative_file, $inventory['unreadable_files'], true)) {
                $inventory['unreadable_files'][] = $relative_file;
            }
            continue;
        }

        if (preg_match('/<\?(?!php\b|=|xml\b)/i', $content)) {
            $inventory['short_open_tags'][] = $relative_file;
        }

        try {
            $tokens = token_get_all($content, TOKEN_PARSE);
        } catch (Throwable $throwable) {
            $inventory['syntax_errors'][] = array(
                'file'    => $relative_file,
                'line'    => seo_core_system_test_parse_error_line($throwable->getMessage(), $throwable->getLine()),
                'message' => $throwable->getMessage(),
            );
            $tokens = token_get_all($content);
        }

        $parsed = seo_core_system_test_parse_php_tokens($tokens, $relative_file);
        $inventory['functions'] = array_merge($inventory['functions'], $parsed['functions']);
        $inventory['methods'] = array_merge($inventory['methods'], $parsed['methods']);
        $inventory['types'] = array_merge($inventory['types'], $parsed['types']);
        $inventory['hooks'] = array_merge($inventory['hooks'], $parsed['hooks']);
        $inventory['entry_points'] = array_merge($inventory['entry_points'], $parsed['entry_points']);
        $inventory['rest_routes'] = array_merge($inventory['rest_routes'], $parsed['rest_routes']);
        $inventory['cron_registrations'] = array_merge($inventory['cron_registrations'], $parsed['cron_registrations']);
        $inventory['function_calls'] = array_merge($inventory['function_calls'], $parsed['function_calls']);
        $inventory['callable_references'] = array_merge($inventory['callable_references'], $parsed['callable_references']);
    }

    $inventory['manual_entry_points'] = seo_core_system_test_get_manual_entry_points($inventory['functions'], $inventory['entry_points']);
    $inventory['entry_points'] = array_merge($inventory['entry_points'], $inventory['manual_entry_points']);
    $inventory['hooks'] = array_merge($inventory['hooks'], $inventory['manual_entry_points']);

    $inventory['duplicate_functions'] = seo_core_system_test_find_duplicate_declarations($inventory['functions']);
    $inventory['duplicate_types'] = seo_core_system_test_find_duplicate_declarations($inventory['types']);
    $inventory['duplicate_methods'] = seo_core_system_test_find_duplicate_declarations($inventory['methods']);
    $inventory['ajax_hooks'] = array_values(array_filter(
        $inventory['hooks'],
        static function ($hook) {
            return !empty($hook['is_ajax']);
        }
    ));
    $inventory['unresolved_callbacks'] = seo_core_system_test_find_unresolved_callbacks($inventory);

    $dependency_data = seo_core_system_test_build_function_dependencies($inventory);
    $inventory['function_dependencies'] = $dependency_data['function_dependencies'];
    $inventory['unreferenced_functions'] = $dependency_data['unreferenced_functions'];
    $inventory['external_function_calls'] = $dependency_data['external_function_calls'];
    $inventory['local_function_call_count'] = $dependency_data['local_function_call_count'];
    $inventory['local_callback_reference_count'] = $dependency_data['local_callback_reference_count'];

    foreach (array('files', 'analyzed_files', 'unreadable_files', 'skipped_files', 'short_open_tags') as $list_key) {
        sort($inventory[$list_key], SORT_NATURAL | SORT_FLAG_CASE);
    }

    foreach (array('functions', 'methods', 'types', 'hooks', 'entry_points', 'manual_entry_points', 'rest_routes', 'cron_registrations', 'ajax_hooks', 'unresolved_callbacks', 'function_calls', 'callable_references', 'function_dependencies', 'unreferenced_functions', 'external_function_calls') as $entry_key) {
        usort($inventory[$entry_key], 'seo_core_system_test_sort_inventory_entries');
    }

    return $inventory;
}

function seo_core_system_test_collect_php_files($plugin_root) {
    $excluded_directories = array(
        '.git',
        '.github',
        '.svn',
        'node_modules',
        'vendor',
        'cache',
        'tmp',
        'logs',
        'backups',
    );
    $excluded_directories = apply_filters('seo_core_system_test_excluded_directories', $excluded_directories);
    $excluded_directories = array_map('strtolower', array_map('strval', (array) $excluded_directories));

    $maximum_file_size = (int) apply_filters('seo_core_system_test_max_php_file_size', 5 * 1024 * 1024);
    $files = array();
    $analyzable_files = array();
    $unreadable_files = array();
    $skipped_files = array();

    if (!is_dir($plugin_root) || !is_readable($plugin_root)) {
        return array(
            'files'            => array(),
            'analyzable_files' => array(),
            'unreadable_files' => array($plugin_root),
            'skipped_files'    => array(),
        );
    }

    try {
        $directory = new RecursiveDirectoryIterator($plugin_root, FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator(
            $directory,
            static function ($current) use ($excluded_directories) {
                if ($current->isLink()) {
                    return false;
                }

                if ($current->isDir()) {
                    return !in_array(strtolower($current->getFilename()), $excluded_directories, true);
                }

                return true;
            }
        );
        $iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY);

        foreach ($iterator as $file_info) {
            if (!$file_info->isFile() || strtolower($file_info->getExtension()) !== 'php') {
                continue;
            }

            $absolute_file = wp_normalize_path($file_info->getPathname());
            $relative_file = seo_core_system_test_relative_path($absolute_file, $plugin_root);

            $files[] = $relative_file;

            if (!$file_info->isReadable()) {
                $unreadable_files[] = $relative_file;
                continue;
            }

            if ($maximum_file_size > 0 && $file_info->getSize() > $maximum_file_size) {
                $skipped_files[] = $relative_file . ' (supera ' . seo_core_system_test_format_bytes($maximum_file_size) . ')';
                continue;
            }

            $analyzable_files[] = $relative_file;
        }
    } catch (Throwable $throwable) {
        $skipped_files[] = 'No se pudo completar el recorrido: ' . $throwable->getMessage();
    }

    $files = array_values(array_unique($files));
    $analyzable_files = array_values(array_unique($analyzable_files));
    $unreadable_files = array_values(array_unique($unreadable_files));
    $skipped_files = array_values(array_unique($skipped_files));

    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    sort($analyzable_files, SORT_NATURAL | SORT_FLAG_CASE);

    return array(
        'files'            => $files,
        'analyzable_files' => $analyzable_files,
        'unreadable_files' => $unreadable_files,
        'skipped_files'    => $skipped_files,
    );
}

function seo_core_system_test_relative_path($absolute_path, $plugin_root) {
    $absolute_path = wp_normalize_path($absolute_path);
    $plugin_root = trailingslashit(wp_normalize_path($plugin_root));

    if (strpos($absolute_path, $plugin_root) === 0) {
        return ltrim(substr($absolute_path, strlen($plugin_root)), '/');
    }

    return basename($absolute_path);
}

function seo_core_system_test_parse_error_line($message, $fallback_line = 0) {
    if (preg_match('/\bon line\s+(\d+)\b/i', (string) $message, $matches)) {
        return (int) $matches[1];
    }

    return max(0, (int) $fallback_line);
}

function seo_core_system_test_parse_php_tokens($tokens, $relative_file) {
    $parsed = array(
        'functions'           => array(),
        'methods'             => array(),
        'types'               => array(),
        'hooks'               => array(),
        'entry_points'        => array(),
        'rest_routes'         => array(),
        'cron_registrations'  => array(),
        'function_calls'      => array(),
        'callable_references' => array(),
    );

    $namespace = '';
    $brace_depth = 0;
    $type_stack = array();
    $pending_type = null;
    $callable_stack = array();
    $pending_callable = null;
    $token_count = count($tokens);

    for ($index = 0; $index < $token_count; $index++) {
        $token = $tokens[$index];

        if (is_string($token)) {
            if ($token === '{') {
                $brace_depth++;

                if ($pending_type !== null) {
                    $pending_type['brace_depth'] = $brace_depth;
                    $type_stack[] = $pending_type;
                    $pending_type = null;
                }

                if ($pending_callable !== null) {
                    $pending_callable['brace_depth'] = $brace_depth;
                    $callable_stack[] = $pending_callable;
                    $pending_callable = null;
                }
            } elseif ($token === '}') {
                if (!empty($callable_stack)) {
                    $last_callable = end($callable_stack);
                    if ((int) $last_callable['brace_depth'] === $brace_depth) {
                        array_pop($callable_stack);
                    }
                }

                if (!empty($type_stack)) {
                    $last_type = end($type_stack);
                    if ((int) $last_type['brace_depth'] === $brace_depth) {
                        array_pop($type_stack);
                    }
                }

                $brace_depth = max(0, $brace_depth - 1);
            } elseif ($token === ';' && $pending_callable !== null) {
                $pending_callable = null;
            }

            continue;
        }

        $token_id = $token[0];
        $token_text = $token[1];
        $token_line = (int) $token[2];

        if ($token_id === T_NAMESPACE) {
            $namespace_data = seo_core_system_test_read_namespace($tokens, $index);
            $namespace = $namespace_data['namespace'];
            $index = $namespace_data['end_index'];
            continue;
        }

        if (seo_core_system_test_is_type_token($token_id)) {
            $previous_id = seo_core_system_test_previous_significant_token_id($tokens, $index);

            if ($token_id === T_CLASS && $previous_id === T_DOUBLE_COLON) {
                continue;
            }

            if ($token_id === T_CLASS && $previous_id === T_NEW) {
                $pending_type = array(
                    'key'       => '',
                    'name'      => '{anonymous class}@' . $relative_file . ':' . $token_line,
                    'type'      => 'anonymous',
                    'file'      => $relative_file,
                    'line'      => $token_line,
                    'anonymous' => true,
                );
                continue;
            }

            $name_data = seo_core_system_test_next_named_token($tokens, $index);
            if ($name_data === null) {
                continue;
            }

            $type_name = seo_core_system_test_qualify_name($namespace, $name_data['name']);
            $type_label = seo_core_system_test_type_token_label($token_id);
            $entry = array(
                'key'  => strtolower(ltrim($type_name, '\\')),
                'name' => ltrim($type_name, '\\'),
                'type' => $type_label,
                'file' => $relative_file,
                'line' => (int) $name_data['line'],
            );
            $parsed['types'][] = $entry;
            $pending_type = $entry;
            continue;
        }

        if ($token_id === T_FUNCTION) {
            $name_data = seo_core_system_test_read_function_name($tokens, $index);
            if ($name_data === null) {
                continue;
            }

            if (!empty($type_stack)) {
                $current_type = end($type_stack);
                if (!empty($current_type['anonymous'])) {
                    continue;
                }

                $qualified_method = $current_type['name'] . '::' . $name_data['name'];
                $entry = array(
                    'key'    => strtolower($qualified_method),
                    'name'   => $qualified_method,
                    'class'  => $current_type['name'],
                    'method' => $name_data['name'],
                    'type'   => 'method',
                    'file'   => $relative_file,
                    'line'   => (int) $name_data['line'],
                );
                $parsed['methods'][] = $entry;
                $pending_callable = array(
                    'key'  => $entry['key'],
                    'name' => $entry['name'],
                    'type' => 'method',
                );
            } else {
                $function_name = seo_core_system_test_qualify_name($namespace, $name_data['name']);
                $entry = array(
                    'key'  => strtolower(ltrim($function_name, '\\')),
                    'name' => ltrim($function_name, '\\'),
                    'type' => 'function',
                    'file' => $relative_file,
                    'line' => (int) $name_data['line'],
                );
                $parsed['functions'][] = $entry;
                $pending_callable = array(
                    'key'  => $entry['key'],
                    'name' => $entry['name'],
                    'type' => 'function',
                );
            }

            continue;
        }

        if (!seo_core_system_test_is_function_call_token($token_id)) {
            continue;
        }

        $lower_name = strtolower(ltrim($token_text, '\\'));

        if ($token_id === T_STRING && in_array($lower_name, array('add_action', 'add_filter', 'add_shortcode'), true)) {
            $previous_id = seo_core_system_test_previous_significant_token_id($tokens, $index);
            if ($previous_id !== T_OBJECT_OPERATOR && $previous_id !== T_DOUBLE_COLON) {
                $arguments = seo_core_system_test_read_literal_call_arguments($tokens, $index, 2);
                if ($arguments !== null && !empty($arguments[0])) {
                    $hook_name = $arguments[0];
                    $callback = isset($arguments[1]) ? $arguments[1] : '';
                    $kind = $lower_name;
                    $entry = array(
                        'key'       => strtolower($kind . ':' . $hook_name . ':' . $callback . ':' . $relative_file . ':' . $token_line),
                        'name'      => $hook_name,
                        'kind'      => $kind,
                        'hook'      => $hook_name,
                        'callback'  => $callback,
                        'namespace' => $namespace,
                        'is_ajax'   => strpos($hook_name, 'wp_ajax_') === 0 || strpos($hook_name, 'wp_ajax_nopriv_') === 0,
                        'entry_type'=> seo_core_system_test_entry_point_label($kind, $hook_name),
                        'file'      => $relative_file,
                        'line'      => $token_line,
                    );
                    $parsed['hooks'][] = $entry;
                    $parsed['entry_points'][] = $entry;
                }
            }
        }

        if ($token_id === T_STRING && in_array($lower_name, array('register_activation_hook', 'register_deactivation_hook', 'register_uninstall_hook'), true)) {
            $previous_id = seo_core_system_test_previous_significant_token_id($tokens, $index);
            if ($previous_id !== T_OBJECT_OPERATOR && $previous_id !== T_DOUBLE_COLON) {
                $arguments = seo_core_system_test_read_literal_call_arguments($tokens, $index, 2);
                $callback = is_array($arguments) && isset($arguments[1]) ? trim((string) $arguments[1]) : '';
                if ($callback !== '') {
                    $entry = array(
                        'key'       => strtolower($lower_name . ':' . $callback . ':' . $relative_file . ':' . $token_line),
                        'name'      => $callback,
                        'kind'      => $lower_name,
                        'hook'      => seo_core_system_test_entry_point_label($lower_name, ''),
                        'callback'  => $callback,
                        'namespace' => $namespace,
                        'is_ajax'   => false,
                        'entry_type'=> seo_core_system_test_entry_point_label($lower_name, ''),
                        'file'      => $relative_file,
                        'line'      => $token_line,
                    );
                    $parsed['hooks'][] = $entry;
                    $parsed['entry_points'][] = $entry;
                }
            }
        }

        if ($token_id === T_STRING && $lower_name === 'register_rest_route') {
            $arguments = seo_core_system_test_read_literal_call_arguments($tokens, $index, 2);
            if (is_array($arguments) && !empty($arguments[0]) && !empty($arguments[1])) {
                $route = trailingslashit($arguments[0]) . ltrim($arguments[1], '/');
                $parsed['rest_routes'][] = array(
                    'key'   => strtolower('rest:' . $route . ':' . $relative_file . ':' . $token_line),
                    'name'  => $route,
                    'kind'  => 'register_rest_route',
                    'route' => $route,
                    'file'  => $relative_file,
                    'line'  => $token_line,
                );
            }
        }

        if ($token_id === T_STRING && in_array($lower_name, array('wp_schedule_event', 'wp_schedule_single_event'), true)) {
            $argument_index = $lower_name === 'wp_schedule_event' ? 2 : 1;
            $arguments = seo_core_system_test_read_literal_call_arguments($tokens, $index, $argument_index + 1);
            $cron_hook = is_array($arguments) && isset($arguments[$argument_index]) ? trim((string) $arguments[$argument_index]) : '';
            if ($cron_hook !== '') {
                $parsed['cron_registrations'][] = array(
                    'key'  => strtolower($lower_name . ':' . $cron_hook . ':' . $relative_file . ':' . $token_line),
                    'name' => $cron_hook,
                    'kind' => $lower_name,
                    'hook' => $cron_hook,
                    'file' => $relative_file,
                    'line' => $token_line,
                );
            }
        }

        $callback_argument_index = seo_core_system_test_callback_argument_index($lower_name);
        if ($callback_argument_index !== null) {
            $arguments = seo_core_system_test_read_literal_call_arguments($tokens, $index, $callback_argument_index + 1);
            $callback = is_array($arguments) && isset($arguments[$callback_argument_index])
                ? trim((string) $arguments[$callback_argument_index])
                : '';

            if ($callback !== '') {
                $parsed['callable_references'][] = array(
                    'key'       => strtolower($lower_name . ':' . $callback . ':' . $relative_file . ':' . $token_line),
                    'name'      => $callback,
                    'kind'      => 'callable',
                    'source'    => $lower_name,
                    'callback'  => $callback,
                    'namespace' => $namespace,
                    'file'      => $relative_file,
                    'line'      => $token_line,
                );
            }
        }

        if (!seo_core_system_test_is_direct_function_call($tokens, $index, $token_id)) {
            continue;
        }

        $function_name = seo_core_system_test_normalize_function_call_name($namespace, $token_text, $token_id);
        if ($function_name === '') {
            continue;
        }

        $caller = !empty($callable_stack) ? end($callable_stack) : null;
        $parsed['function_calls'][] = array(
            'key'         => strtolower(ltrim($function_name, '\\')),
            'name'        => ltrim($function_name, '\\'),
            'type'        => 'function_call',
            'file'        => $relative_file,
            'line'        => $token_line,
            'caller_key'  => $caller ? $caller['key'] : '',
            'caller_name' => $caller ? $caller['name'] : '{nivel global}',
            'caller_type' => $caller ? $caller['type'] : 'global',
        );
    }

    return $parsed;
}

function seo_core_system_test_is_function_call_token($token_id) {
    $function_tokens = array(T_STRING);

    if (defined('T_NAME_QUALIFIED')) {
        $function_tokens[] = T_NAME_QUALIFIED;
    }
    if (defined('T_NAME_FULLY_QUALIFIED')) {
        $function_tokens[] = T_NAME_FULLY_QUALIFIED;
    }

    return in_array($token_id, $function_tokens, true);
}

function seo_core_system_test_is_direct_function_call($tokens, $index, $token_id) {
    if (!seo_core_system_test_is_function_call_token($token_id)) {
        return false;
    }

    if (seo_core_system_test_is_function_declaration_name($tokens, $index)) {
        return false;
    }

    $next = seo_core_system_test_next_significant_token_value($tokens, $index);
    if ($next !== '(') {
        return false;
    }

    $previous_id = seo_core_system_test_previous_significant_token_id($tokens, $index);
    $blocked = array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_FUNCTION);

    if (defined('T_NULLSAFE_OBJECT_OPERATOR')) {
        $blocked[] = T_NULLSAFE_OBJECT_OPERATOR;
    }
    if (defined('T_FN')) {
        $blocked[] = T_FN;
    }
    if (defined('T_ATTRIBUTE')) {
        $blocked[] = T_ATTRIBUTE;
    }

    return !in_array($previous_id, $blocked, true);
}

function seo_core_system_test_is_function_declaration_name($tokens, $index) {
    for ($position = $index - 1; $position >= 0; $position--) {
        $token = $tokens[$position];

        if (is_array($token)) {
            if (in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                continue;
            }

            if (
                (defined('T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG') && $token[0] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG)
                || (defined('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG') && $token[0] === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG)
            ) {
                continue;
            }

            return $token[0] === T_FUNCTION;
        }

        if ($token === '&' || trim($token) === '') {
            continue;
        }

        return false;
    }

    return false;
}

function seo_core_system_test_next_significant_token_value($tokens, $index) {
    $token_count = count($tokens);

    for ($position = $index + 1; $position < $token_count; $position++) {
        $token = $tokens[$position];

        if (is_array($token)) {
            if (in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                continue;
            }

            return $token[0];
        }

        if (trim($token) !== '') {
            return $token;
        }
    }

    return null;
}

function seo_core_system_test_normalize_function_call_name($namespace, $token_text, $token_id) {
    $namespace = trim((string) $namespace, '\\');
    $name = trim((string) $token_text);

    if ($name === '') {
        return '';
    }

    if (defined('T_NAME_FULLY_QUALIFIED') && $token_id === T_NAME_FULLY_QUALIFIED) {
        return ltrim($name, '\\');
    }

    if (stripos($name, 'namespace\\') === 0) {
        $name = substr($name, strlen('namespace\\'));
        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    $name = ltrim($name, '\\');

    return $namespace === '' ? $name : $namespace . '\\' . $name;
}

function seo_core_system_test_entry_point_label($kind, $hook = '') {
    $kind = strtolower((string) $kind);
    $hook = (string) $hook;

    if ($kind === 'register_activation_hook') {
        return 'Activation Hook';
    }
    if ($kind === 'register_deactivation_hook') {
        return 'Deactivation Hook';
    }
    if ($kind === 'register_uninstall_hook') {
        return 'Uninstall Hook';
    }
    if ($kind === 'add_shortcode') {
        return 'Shortcode';
    }
    if ($kind === 'add_filter') {
        return 'Filter';
    }
    if ($kind === 'add_action') {
        if (strpos($hook, 'wp_ajax_nopriv_') === 0) {
            return 'AJAX público';
        }
        if (strpos($hook, 'wp_ajax_') === 0) {
            return 'AJAX privado';
        }
        return 'Action';
    }

    return 'Callback';
}

function seo_core_system_test_get_manual_entry_points($functions, $existing_entry_points = array()) {
    $defaults = array(
        array(
            'callback'        => 'seo_taxonomy_install',
            'definition_file' => 'includes/seo-install.php',
            'kind'            => 'register_activation_hook',
            'hook'            => 'plugin_activation',
            'reason'          => 'Instalador ejecutado por WordPress al activar el plugin.',
        ),
    );

    $configured = apply_filters('seo_core_system_test_manual_entry_points', $defaults);
    $definitions = array();

    foreach ((array) $functions as $function) {
        if (empty($function['key'])) {
            continue;
        }
        $definitions[strtolower((string) $function['key'])] = $function;
    }

    $already_registered = array();
    foreach ((array) $existing_entry_points as $entry) {
        $callback = isset($entry['callback']) ? strtolower(ltrim((string) $entry['callback'], '\\')) : '';
        $kind = isset($entry['kind']) ? strtolower((string) $entry['kind']) : '';
        if ($callback !== '' && $kind !== '') {
            $already_registered[$kind . ':' . $callback] = true;
        }
    }

    $entries = array();

    foreach ((array) $configured as $manual) {
        $callback = isset($manual['callback']) ? ltrim(trim((string) $manual['callback']), '\\') : '';
        $kind = isset($manual['kind']) ? strtolower(trim((string) $manual['kind'])) : '';
        $definition_file = isset($manual['definition_file']) ? ltrim(wp_normalize_path((string) $manual['definition_file']), '/') : '';
        $key = strtolower($callback);

        if ($callback === '' || $kind === '' || !isset($definitions[$key])) {
            continue;
        }

        $definition = $definitions[$key];
        $actual_file = isset($definition['file']) ? ltrim(wp_normalize_path((string) $definition['file']), '/') : '';

        if ($definition_file !== '' && strcasecmp($actual_file, $definition_file) !== 0) {
            continue;
        }

        if (isset($already_registered[$kind . ':' . $key])) {
            continue;
        }

        $hook = isset($manual['hook']) ? (string) $manual['hook'] : $kind;
        $entries[] = array(
            'key'        => strtolower('manual:' . $kind . ':' . $callback . ':' . $actual_file),
            'name'       => $callback,
            'kind'       => $kind,
            'hook'       => $hook,
            'callback'   => $callback,
            'namespace'  => '',
            'is_ajax'    => false,
            'entry_type' => seo_core_system_test_entry_point_label($kind, $hook),
            'manual'     => true,
            'reason'     => isset($manual['reason']) ? (string) $manual['reason'] : 'Excepción manual configurada.',
            'file'       => $actual_file,
            'line'       => isset($definition['line']) ? (int) $definition['line'] : 0,
        );
    }

    return $entries;
}

function seo_core_system_test_callback_argument_index($function_name) {
    $map = array(
        'array_map'                    => 0,
        'array_filter'                 => 1,
        'array_reduce'                 => 1,
        'array_walk'                   => 1,
        'array_walk_recursive'         => 1,
        'usort'                        => 1,
        'uasort'                       => 1,
        'uksort'                       => 1,
        'call_user_func'               => 0,
        'call_user_func_array'         => 0,
        'register_shutdown_function'   => 0,
        'register_tick_function'       => 0,
        'set_error_handler'            => 0,
        'set_exception_handler'        => 0,
        'spl_autoload_register'        => 0,
        'preg_replace_callback'        => 1,
        'add_menu_page'                => 4,
        'add_submenu_page'             => 5,
        'add_options_page'             => 4,
        'add_management_page'          => 4,
        'add_dashboard_page'           => 4,
        'add_posts_page'               => 4,
        'add_pages_page'               => 4,
        'add_media_page'               => 4,
        'add_links_page'               => 4,
        'add_comments_page'            => 4,
        'add_theme_page'               => 4,
        'add_plugins_page'             => 4,
        'add_users_page'               => 4,
        'add_meta_box'                 => 2,
        'add_settings_section'         => 2,
        'add_settings_field'           => 2,
        'wp_add_dashboard_widget'      => 2,
        'wp_register_sidebar_widget'   => 2,
        'register_sidebar_widget'      => 2,
    );

    return array_key_exists($function_name, $map) ? $map[$function_name] : null;
}

function seo_core_system_test_read_namespace($tokens, $start_index) {
    $namespace = '';
    $end_index = $start_index;
    $token_count = count($tokens);

    for ($index = $start_index + 1; $index < $token_count; $index++) {
        $token = $tokens[$index];

        if (is_string($token)) {
            if ($token === ';' || $token === '{') {
                $end_index = $token === '{' ? $index - 1 : $index;
                break;
            }
            continue;
        }

        $allowed = array(T_STRING, T_NS_SEPARATOR);
        if (defined('T_NAME_QUALIFIED')) {
            $allowed[] = T_NAME_QUALIFIED;
        }
        if (defined('T_NAME_FULLY_QUALIFIED')) {
            $allowed[] = T_NAME_FULLY_QUALIFIED;
        }

        if (in_array($token[0], $allowed, true)) {
            $namespace .= $token[1];
        }

        $end_index = $index;
    }

    return array(
        'namespace' => trim($namespace, '\\'),
        'end_index' => $end_index,
    );
}

function seo_core_system_test_is_type_token($token_id) {
    $type_tokens = array(T_CLASS, T_INTERFACE, T_TRAIT);

    if (defined('T_ENUM')) {
        $type_tokens[] = T_ENUM;
    }

    return in_array($token_id, $type_tokens, true);
}

function seo_core_system_test_type_token_label($token_id) {
    if ($token_id === T_INTERFACE) {
        return 'interface';
    }

    if ($token_id === T_TRAIT) {
        return 'trait';
    }

    if (defined('T_ENUM') && $token_id === T_ENUM) {
        return 'enum';
    }

    return 'class';
}

function seo_core_system_test_previous_significant_token_id($tokens, $index) {
    for ($position = $index - 1; $position >= 0; $position--) {
        $token = $tokens[$position];

        if (is_array($token)) {
            if (in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                continue;
            }

            return $token[0];
        }

        if (trim($token) !== '') {
            return $token;
        }
    }

    return null;
}

function seo_core_system_test_next_named_token($tokens, $index) {
    $token_count = count($tokens);

    for ($position = $index + 1; $position < $token_count; $position++) {
        $token = $tokens[$position];

        if (is_array($token)) {
            if (in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_FINAL, T_ABSTRACT), true)) {
                continue;
            }

            if ($token[0] === T_STRING) {
                return array('name' => $token[1], 'line' => (int) $token[2]);
            }

            return null;
        }

        if (trim($token) !== '') {
            return null;
        }
    }

    return null;
}

function seo_core_system_test_read_function_name($tokens, $index) {
    $token_count = count($tokens);

    for ($position = $index + 1; $position < $token_count; $position++) {
        $token = $tokens[$position];

        if (is_array($token)) {
            if (in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                continue;
            }

            if ($token[0] === T_STRING) {
                return array('name' => $token[1], 'line' => (int) $token[2]);
            }

            if (defined('T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG') && $token[0] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG) {
                continue;
            }
            if (defined('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG') && $token[0] === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) {
                continue;
            }

            return null;
        }

        if ($token === '&') {
            continue;
        }

        if ($token === '(') {
            return null;
        }

        if (trim($token) !== '') {
            return null;
        }
    }

    return null;
}

function seo_core_system_test_qualify_name($namespace, $name) {
    $namespace = trim((string) $namespace, '\\');
    $name = ltrim((string) $name, '\\');

    return $namespace === '' ? $name : $namespace . '\\' . $name;
}

function seo_core_system_test_read_literal_call_arguments($tokens, $function_index, $maximum_arguments) {
    $token_count = count($tokens);
    $open_parenthesis = null;

    for ($position = $function_index + 1; $position < $token_count; $position++) {
        $token = $tokens[$position];

        if (is_array($token) && in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            continue;
        }

        if ($token === '(') {
            $open_parenthesis = $position;
        }
        break;
    }

    if ($open_parenthesis === null) {
        return null;
    }

    $argument_tokens = array(array());
    $argument_index = 0;
    $depth = 1;

    for ($position = $open_parenthesis + 1; $position < $token_count; $position++) {
        $token = $tokens[$position];

        if (is_string($token)) {
            if (in_array($token, array('(', '[', '{'), true)) {
                $depth++;
            } elseif (in_array($token, array(')', ']', '}'), true)) {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($token === ',' && $depth === 1) {
                $argument_index++;
                if ($argument_index >= $maximum_arguments) {
                    break;
                }
                $argument_tokens[$argument_index] = array();
                continue;
            }
        }

        if ($argument_index < $maximum_arguments) {
            $argument_tokens[$argument_index][] = $token;
        }
    }

    $arguments = array();
    foreach ($argument_tokens as $tokens_for_argument) {
        $arguments[] = seo_core_system_test_decode_literal_argument($tokens_for_argument);
    }

    return $arguments;
}

function seo_core_system_test_decode_literal_argument($tokens) {
    $significant = array();

    foreach ($tokens as $token) {
        if (is_array($token) && in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            continue;
        }
        if (is_string($token) && trim($token) === '') {
            continue;
        }
        $significant[] = $token;
    }

    if (count($significant) !== 1 || !is_array($significant[0]) || $significant[0][0] !== T_CONSTANT_ENCAPSED_STRING) {
        return '';
    }

    $literal = $significant[0][1];
    $quote = substr($literal, 0, 1);
    $value = substr($literal, 1, -1);

    if ($quote === "'") {
        return str_replace(array('\\\\', "\\'"), array('\\', "'"), $value);
    }

    return stripcslashes($value);
}

function seo_core_system_test_find_duplicate_declarations($entries) {
    $grouped = array();

    foreach ($entries as $entry) {
        if (empty($entry['key'])) {
            continue;
        }
        $grouped[$entry['key']][] = $entry;
    }

    $duplicates = array();
    foreach ($grouped as $key => $occurrences) {
        if (count($occurrences) > 1) {
            $duplicates[$key] = $occurrences;
        }
    }

    ksort($duplicates, SORT_NATURAL | SORT_FLAG_CASE);

    return $duplicates;
}

function seo_core_system_test_find_unresolved_callbacks($inventory) {
    $function_keys = array();
    foreach ($inventory['functions'] as $function) {
        $function_keys[$function['key']] = true;
    }

    $method_keys = array();
    foreach ($inventory['methods'] as $method) {
        $method_keys[$method['key']] = true;
    }

    $unresolved = array();

    foreach ($inventory['hooks'] as $hook) {
        $callback = trim((string) $hook['callback']);
        if ($callback === '') {
            continue;
        }

        $resolved = false;
        if (strpos($callback, '::') !== false) {
            $method_key = strtolower(ltrim($callback, '\\'));
            $resolved = isset($method_keys[$method_key]) || is_callable($callback);
        } else {
            $function_key = strtolower(ltrim($callback, '\\'));
            $resolved = isset($function_keys[$function_key]) || function_exists($callback);
        }

        if (!$resolved) {
            $hook['key'] = strtolower($hook['kind'] . ':' . $hook['hook'] . ':' . $callback . ':' . $hook['file'] . ':' . $hook['line']);
            $hook['name'] = $callback;
            $unresolved[] = $hook;
        }
    }

    return $unresolved;
}

function seo_core_system_test_dependency_reference_types($direct_calls, $callback_references) {
    $types = array();

    if (!empty($direct_calls)) {
        $types[] = 'Llamada directa';
    }

    foreach ((array) $callback_references as $reference) {
        if (!empty($reference['entry_type'])) {
            $label = (string) $reference['entry_type'];
        } elseif (!empty($reference['kind']) && $reference['kind'] !== 'callable') {
            $label = seo_core_system_test_entry_point_label(
                $reference['kind'],
                isset($reference['hook']) ? $reference['hook'] : ''
            );
        } elseif (!empty($reference['source'])) {
            $label = 'Callback ' . $reference['source'];
        } else {
            $label = 'Callback';
        }

        if (!empty($reference['manual'])) {
            $label .= ' (excepción)';
        }

        $types[] = $label;
    }

    $types = array_values(array_unique(array_filter($types)));
    sort($types, SORT_NATURAL | SORT_FLAG_CASE);

    return $types;
}

function seo_core_system_test_dependency_status($direct_calls, $callback_references, $self_calls) {
    $types = seo_core_system_test_dependency_reference_types($direct_calls, $callback_references);

    if (!empty($types)) {
        return implode(' + ', $types);
    }

    if (!empty($self_calls)) {
        return 'Sin referencias externas';
    }

    return 'Sin referencias';
}

function seo_core_system_test_build_function_dependencies($inventory) {
    $definitions = array();

    foreach ($inventory['functions'] as $function) {
        if (empty($function['key']) || isset($definitions[$function['key']])) {
            continue;
        }
        $definitions[$function['key']] = $function;
    }

    $incoming_calls = array();
    $self_calls = array();
    $callback_references = array();
    $external_calls = array();
    $local_function_call_count = 0;

    foreach ($inventory['function_calls'] as $call) {
        $key = isset($call['key']) ? strtolower((string) $call['key']) : '';

        if ($key === '' || !isset($definitions[$key])) {
            $external_calls[] = $call;
            continue;
        }

        $local_function_call_count++;

        if (!empty($call['caller_key']) && strtolower((string) $call['caller_key']) === $key) {
            $self_calls[$key][] = $call;
        } else {
            $incoming_calls[$key][] = $call;
        }
    }

    $literal_references = array_merge($inventory['hooks'], $inventory['callable_references']);

    foreach ($literal_references as $reference) {
        $callback = isset($reference['callback']) ? trim((string) $reference['callback']) : '';

        if ($callback === '' || strpos($callback, '::') !== false) {
            continue;
        }

        $key = seo_core_system_test_resolve_callback_function_key(
            $callback,
            isset($reference['namespace']) ? $reference['namespace'] : '',
            $definitions
        );

        if ($key === '') {
            continue;
        }

        $callback_references[$key][] = $reference;
    }

    $dependencies = array();
    $unreferenced = array();
    $local_callback_reference_count = 0;

    foreach ($definitions as $key => $definition) {
        $direct = isset($incoming_calls[$key]) ? $incoming_calls[$key] : array();
        $recursive = isset($self_calls[$key]) ? $self_calls[$key] : array();
        $callbacks = isset($callback_references[$key]) ? $callback_references[$key] : array();
        $incoming_count = count($direct) + count($callbacks);
        $local_callback_reference_count += count($callbacks);

        $reference_types = seo_core_system_test_dependency_reference_types($direct, $callbacks);
        $manual_exception_count = count(array_filter(
            $callbacks,
            static function ($reference) {
                return !empty($reference['manual']);
            }
        ));

        $dependency = array(
            'key'                 => $key,
            'name'                => $definition['name'],
            'file'                => $definition['file'],
            'line'                => $definition['line'],
            'direct_calls'        => count($direct) + count($recursive),
            'callback_references' => count($callbacks),
            'self_calls'          => count($recursive),
            'incoming_references' => $incoming_count,
            'reference_types'     => empty($reference_types) ? '—' : implode(', ', $reference_types),
            'manual_exceptions'   => $manual_exception_count,
            'references'          => seo_core_system_test_dependency_locations_summary($direct, $callbacks, $recursive),
            'status'              => seo_core_system_test_dependency_status($direct, $callbacks, $recursive),
        );

        $dependencies[] = $dependency;

        if ($incoming_count === 0) {
            $unreferenced[] = $dependency;
        }
    }

    usort($dependencies, 'seo_core_system_test_sort_inventory_entries');
    usort($unreferenced, 'seo_core_system_test_sort_inventory_entries');
    usort($external_calls, 'seo_core_system_test_sort_inventory_entries');

    return array(
        'function_dependencies'          => $dependencies,
        'unreferenced_functions'         => $unreferenced,
        'external_function_calls'        => $external_calls,
        'local_function_call_count'      => $local_function_call_count,
        'local_callback_reference_count' => $local_callback_reference_count,
    );
}

function seo_core_system_test_resolve_callback_function_key($callback, $namespace, $definitions) {
    $callback = ltrim(trim((string) $callback), '\\');
    $namespace = trim((string) $namespace, '\\');

    if ($callback === '') {
        return '';
    }

    $direct_key = strtolower($callback);
    if (isset($definitions[$direct_key])) {
        return $direct_key;
    }

    if ($namespace !== '' && strpos($callback, '\\') === false) {
        $namespaced_key = strtolower($namespace . '\\' . $callback);
        if (isset($definitions[$namespaced_key])) {
            return $namespaced_key;
        }
    }

    return '';
}

function seo_core_system_test_dependency_locations_summary($direct_calls, $callback_references, $self_calls, $limit = 25) {
    $locations = array();

    foreach ($direct_calls as $call) {
        $caller = !empty($call['caller_name']) ? $call['caller_name'] : '{nivel global}';
        $locations[] = 'Directa: ' . $call['file'] . ':' . (int) $call['line'] . ' desde ' . $caller;
    }

    foreach ($callback_references as $reference) {
        if (!empty($reference['hook'])) {
            $source = $reference['kind'] . ' ' . $reference['hook'];
        } else {
            $source = !empty($reference['source']) ? $reference['source'] : 'callback';
        }

        $prefix = !empty($reference['manual']) ? 'Excepción: ' : 'Callback: ';
        $reason = !empty($reference['reason']) ? ' (' . $reference['reason'] . ')' : '';
        $locations[] = $prefix . $reference['file'] . ':' . (int) $reference['line'] . ' mediante ' . $source . $reason;
    }

    foreach ($self_calls as $call) {
        $locations[] = 'Recursiva: ' . $call['file'] . ':' . (int) $call['line'];
    }

    $total = count($locations);
    if ($total === 0) {
        return '—';
    }

    $visible = array_slice($locations, 0, max(1, (int) $limit));
    $summary = implode(' | ', $visible);

    if ($total > count($visible)) {
        $summary .= ' | … +' . ($total - count($visible)) . ' referencias';
    }

    return $summary;
}

function seo_core_system_test_sort_inventory_entries($left, $right) {
    $left_name = isset($left['name']) ? strtolower((string) $left['name']) : '';
    $right_name = isset($right['name']) ? strtolower((string) $right['name']) : '';

    if ($left_name !== $right_name) {
        return strnatcasecmp($left_name, $right_name);
    }

    $left_file = isset($left['file']) ? strtolower((string) $left['file']) : '';
    $right_file = isset($right['file']) ? strtolower((string) $right['file']) : '';

    if ($left_file !== $right_file) {
        return strnatcasecmp($left_file, $right_file);
    }

    return (int) (isset($left['line']) ? $left['line'] : 0) <=> (int) (isset($right['line']) ? $right['line'] : 0);
}

function seo_core_system_test_duplicate_summary($duplicates, $singular_label) {
    $names = array();

    foreach ($duplicates as $occurrences) {
        if (!empty($occurrences[0]['name'])) {
            $names[] = $occurrences[0]['name'];
        }
        if (count($names) >= 3) {
            break;
        }
    }

    $detail = number_format_i18n(count($duplicates)) . ' ' . $singular_label;
    $detail .= count($duplicates) === 1 ? ' duplicada' : ' duplicadas';

    if (!empty($names)) {
        $detail .= ': ' . implode(', ', $names);
    }

    if (count($duplicates) > count($names)) {
        $detail .= '…';
    }

    $detail .= '. Consulta la pestaña Integridad del código.';

    return $detail;
}

function seo_core_system_test_type_count_summary($types) {
    $counts = array(
        'class'     => 0,
        'interface' => 0,
        'trait'     => 0,
        'enum'      => 0,
    );

    foreach ($types as $type) {
        if (isset($counts[$type['type']])) {
            $counts[$type['type']]++;
        }
    }

    return 'Clases: ' . number_format_i18n($counts['class'])
        . '; interfaces: ' . number_format_i18n($counts['interface'])
        . '; traits: ' . number_format_i18n($counts['trait'])
        . '; enums: ' . number_format_i18n($counts['enum']);
}

function seo_core_system_test_import_export_health($plugin_root) {
    $plugin_root = trailingslashit($plugin_root);
    $engine_path = $plugin_root . 'includes/import-export/suppliers/engine.php';
    $sync_path = $plugin_root . 'includes/import-export/suppliers/sync.php';

    $supplier_dependencies = array(
        'includes/seo-import-suppliers.php',
        'includes/import-export/suppliers/engine.php',
        'includes/import-export/suppliers/sync.php',
        'includes/import-export/suppliers/xls-reader.php',
        'includes/import-export/suppliers/connections.php',
        'includes/import-export/suppliers/crawler-queue.php',
        'includes/import-export/suppliers/recipes',
    );
    $missing_dependencies = array();
    foreach ($supplier_dependencies as $relative) {
        $path = $plugin_root . $relative;
        $available = is_dir($path) ? is_readable($path) : (is_file($path) && is_readable($path));
        if (!$available) {
            $missing_dependencies[] = $relative;
        }
    }

    $supplier_handlers = array(
        'seo_proveedores_analizar_archivo',
        'seo_proveedores_importar_catalogo',
        'seo_proveedores_actualizar_estado_catalogo',
        'seo_proveedores_actualizar_estado_masivo',
        'seo_proveedores_descartar_importacion_al_salir',
    );
    $handler_ok = 0;
    $missing_handlers = array();
    foreach ($supplier_handlers as $callback) {
        $registered = function_exists($callback);
        if ($registered && function_exists('has_action')) {
            $registered = false !== has_action('admin_init', $callback);
        }
        if ($registered) {
            $handler_ok++;
        } else {
            $missing_handlers[] = $callback;
        }
    }

    $engine_ok = is_readable($engine_path) && function_exists('seo_proveedores_render_importador');
    $catalog_ok = is_readable($engine_path) && function_exists('seo_proveedores_render_catalogo');
    $batch_ok = function_exists('seo_ie_batch_render_page');
    $amazon_ok = function_exists('seo_supplier_recipe_amazon_render_explorer');
    $connections_ok = function_exists('seo_proveedores_render_conexiones');
    $sync_ok = is_readable($sync_path) && function_exists('seo_supplier_sync_situations') && function_exists('seo_supplier_sync_apply_action');

    return array(
        seo_core_system_test_result(
            'system',
            '1.7 Subsistema Importar/Exportar cargado',
            defined('SEO_IE_BUILD') && function_exists('seo_import_export_page'),
            defined('SEO_IE_BUILD')
                ? 'Build ' . (string) SEO_IE_BUILD . '; pantalla administrativa resoluble: ' . (function_exists('seo_import_export_page') ? 'si' : 'no')
                : 'SEO_IE_BUILD no definido; el bootstrap de Importar/Exportar no ha terminado de cargar.',
            defined('SEO_IE_BUILD') && function_exists('seo_import_export_page') ? 'ok' : 'ko'
        ),
        seo_core_system_test_result(
            'system',
            '1.8 Importador de proveedores disponible',
            $engine_ok,
            $engine_ok
                ? 'Motor canonico cargado: includes/import-export/suppliers/engine.php; renderizador seo_proveedores_render_importador disponible.'
                : 'No se puede resolver el motor de proveedores o falta seo_proveedores_render_importador().',
            $engine_ok ? 'ok' : 'ko'
        ),
        seo_core_system_test_result(
            'system',
            '1.9 Catalogo de proveedores disponible',
            $catalog_ok,
            $catalog_ok
                ? 'Renderizador seo_proveedores_render_catalogo disponible.'
                : 'La pestaña Catalogo de proveedores no tiene un renderizador cargado.',
            $catalog_ok ? 'ok' : 'ko'
        ),
        seo_core_system_test_result(
            'system',
            '1.10 Acciones administrativas de proveedores registradas',
            $handler_ok === count($supplier_handlers),
            $handler_ok . '/' . count($supplier_handlers) . ' handlers disponibles'
                . (!empty($missing_handlers) ? '. Faltan: ' . implode(', ', $missing_handlers) : ''),
            $handler_ok === count($supplier_handlers) ? 'ok' : 'ko'
        ),
        seo_core_system_test_result(
            'system',
            '1.11 Importacion por lotes disponible',
            $batch_ok,
            $batch_ok ? 'seo_ie_batch_render_page disponible.' : 'No se ha cargado el renderizador de queue/batch.php.',
            $batch_ok ? 'ok' : 'ko'
        ),
        seo_core_system_test_result(
            'system',
            '1.12 Importador Amazon disponible',
            $amazon_ok,
            $amazon_ok ? 'Receta y explorador Amazon cargados.' : 'No se ha cargado seo_supplier_recipe_amazon_render_explorer().',
            $amazon_ok ? 'ok' : 'ko'
        ),
        seo_core_system_test_result(
            'system',
            '1.13 Conexiones con proveedores disponibles',
            $connections_ok,
            $connections_ok ? 'seo_proveedores_render_conexiones disponible.' : 'No se ha cargado el modulo de conexiones con proveedores.',
            $connections_ok ? 'ok' : 'ko'
        ),
        seo_core_system_test_result(
            'system',
            '1.14 Sincronizacion de proveedores integrada',
            $sync_ok,
            $sync_ok
                ? 'suppliers/sync.php disponible; estados, comparacion y acciones integradas en Catalogo de proveedores.'
                : 'No se puede cargar includes/import-export/suppliers/sync.php o faltan sus funciones principales.',
            $sync_ok ? 'ok' : 'ko'
        ),
        seo_core_system_test_result(
            'system',
            '1.15 Dependencias del motor de proveedores disponibles',
            empty($missing_dependencies),
            empty($missing_dependencies)
                ? count($supplier_dependencies) . '/' . count($supplier_dependencies) . ' dependencias disponibles.'
                : 'Faltan: ' . implode(', ', $missing_dependencies),
            empty($missing_dependencies) ? 'ok' : 'ko'
        ),
    );
}

function seo_core_system_test_system_general() {
    $plugin_root = seo_core_system_test_get_plugin_root();
    $includes_dir = trailingslashit($plugin_root) . 'includes';
    $templates_dir = trailingslashit($plugin_root) . 'seo-system/templates';

    $function_files = seo_core_system_test_get_function_files();
    $template_files = seo_core_system_test_get_template_files();

    $function_status = seo_core_system_test_count_available_files($plugin_root, $function_files);
    $template_status = seo_core_system_test_count_available_files($plugin_root, $template_files);

    $results = array(
        seo_core_system_test_result('system', '1.1 Ruta base del plugin detectada', is_dir($plugin_root), $plugin_root),
        seo_core_system_test_result('system', '1.2 Directorio includes disponible', is_dir($includes_dir) && is_readable($includes_dir), $includes_dir),
        seo_core_system_test_result('system', '1.3 Directorio de plantillas disponible', is_dir($templates_dir) && is_readable($templates_dir), $templates_dir),
        seo_core_system_test_result('system', '1.4 Archivos de funciones disponibles', $function_status['missing'] === 0, $function_status['found'] . '/' . $function_status['total'] . ' encontrados' . seo_core_system_test_missing_suffix($function_status), $function_status['missing'] === 0 ? 'ok' : 'ko'),
        seo_core_system_test_result('system', '1.5 Plantillas disponibles', $template_status['missing'] === 0, $template_status['found'] . '/' . $template_status['total'] . ' encontradas' . seo_core_system_test_missing_suffix($template_status), $template_status['missing'] === 0 ? 'ok' : 'ko'),
        seo_core_system_test_result('system', '1.6 WooCommerce activo', class_exists('WooCommerce'), class_exists('WooCommerce') ? 'Clase WooCommerce disponible' : 'WooCommerce no activo', class_exists('WooCommerce') ? 'ok' : 'warning'),
    );

    return array_merge($results, seo_core_system_test_import_export_health($plugin_root));
}

function seo_core_system_test_template_loading() {
    $plugin_root = seo_core_system_test_get_plugin_root();
    $checks = array(
        array('2.1 Plantilla cluster disponible', 'seo-system/templates/template-cluster.php', true),
        array('2.2 Plantilla hub primario disponible', 'seo-system/templates/template-hub-primary.php', true),
        array('2.3 Plantilla hub secundario disponible', 'seo-system/templates/template-hub-secondary.php', true),
        array('2.4 Plantilla categoría disponible', 'seo-system/templates/template-category.php', true),
        array('2.5 Plantilla producto disponible', 'seo-system/templates/template-product.php', true),
        array('2.6 Plantilla búsqueda disponible', 'seo-system/templates/template-search.php', true),
        array('2.7 Plantilla carrito disponible', 'seo-system/templates/template-cart.php', true),
        array('2.8 Plantilla checkout disponible', 'seo-system/templates/template-checkout.php', true),
        array('2.9 Plantilla 404 disponible', 'seo-system/templates/template-404.php', true),
        array('2.10 CSS de plantillas disponible', 'seo-system/templates/styles_template.css', true),
    );

    $results = array();

    foreach ($checks as $check) {
        $path = trailingslashit($plugin_root) . $check[1];
        $exists = file_exists($path);
        $readable = $exists && is_readable($path);
        $passed = $exists && $readable;

        $results[] = seo_core_system_test_result(
            'templates',
            $check[0],
            $passed,
            $passed ? $path : 'No encontrado o no legible: ' . $check[1],
            $passed ? 'ok' : ($check[2] ? 'ko' : 'warning')
        );
    }

    return $results;
}

function seo_core_system_test_catalog() {
    global $wpdb;

    $product_count = post_type_exists('product')
        ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'")
        : 0;

    $category_count = taxonomy_exists('product_cat')
        ? (int) wp_count_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false))
        : 0;

    $page_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish'");

    $cluster_count = seo_core_system_test_count_seo_nodes_by_role('cluster');
    $hub_primary_count = seo_core_system_test_count_seo_nodes_by_role('hub_primary');
    $hub_secondary_count = seo_core_system_test_count_seo_nodes_by_role('hub_secondary');

    return array(
        seo_core_system_test_result('catalog', '3.1 Productos publicados disponibles', $product_count > 0, 'Productos publicados: ' . number_format_i18n($product_count)),
        seo_core_system_test_result('catalog', '3.2 Categorías de producto disponibles', $category_count > 0, 'Categorías: ' . number_format_i18n($category_count)),
        seo_core_system_test_result('catalog', '3.3 Páginas publicadas disponibles', $page_count > 0, 'Páginas publicadas: ' . number_format_i18n($page_count)),
        seo_core_system_test_result('catalog', '3.4 Clusters registrados', $cluster_count > 0, 'Clusters: ' . number_format_i18n($cluster_count), $cluster_count > 0 ? 'ok' : 'warning'),
        seo_core_system_test_result('catalog', '3.5 Hubs primarios registrados', $hub_primary_count > 0, 'Hubs primarios: ' . number_format_i18n($hub_primary_count), $hub_primary_count > 0 ? 'ok' : 'warning'),
        seo_core_system_test_result('catalog', '3.6 Hubs secundarios registrados', $hub_secondary_count > 0, 'Hubs secundarios: ' . number_format_i18n($hub_secondary_count), $hub_secondary_count > 0 ? 'ok' : 'warning'),
    );
}

function seo_core_system_test_checkout() {
    $results = array();

    $cart_id = function_exists('wc_get_page_id') ? wc_get_page_id('cart') : 0;
    $checkout_id = function_exists('wc_get_page_id') ? wc_get_page_id('checkout') : 0;
    $shop_id = function_exists('wc_get_page_id') ? wc_get_page_id('shop') : 0;

    $results[] = seo_core_system_test_result('checkout', '4.1 Página de tienda disponible', seo_core_system_test_valid_page_id($shop_id), 'Shop page ID: ' . (int) $shop_id, seo_core_system_test_valid_page_id($shop_id) ? 'ok' : 'warning');
    $results[] = seo_core_system_test_result('checkout', '4.2 Carrito disponible', seo_core_system_test_valid_page_id($cart_id), 'Cart page ID: ' . (int) $cart_id, seo_core_system_test_valid_page_id($cart_id) ? 'ok' : 'warning');
    $results[] = seo_core_system_test_result('checkout', '4.3 Checkout disponible', seo_core_system_test_valid_page_id($checkout_id), 'Checkout page ID: ' . (int) $checkout_id, seo_core_system_test_valid_page_id($checkout_id) ? 'ok' : 'warning');
    $results[] = seo_core_system_test_result('checkout', '4.4 Métodos de pago activos', seo_core_system_test_payment_gateways_count() > 0, 'Métodos activos: ' . number_format_i18n(seo_core_system_test_payment_gateways_count()), seo_core_system_test_payment_gateways_count() > 0 ? 'ok' : 'warning');
    $results[] = seo_core_system_test_result('checkout', '4.5 Métodos de envío detectados', seo_core_system_test_shipping_methods_count() > 0, 'Métodos detectados: ' . number_format_i18n(seo_core_system_test_shipping_methods_count()), seo_core_system_test_shipping_methods_count() > 0 ? 'ok' : 'warning');
    $results[] = seo_core_system_test_result('checkout', '4.6 Prueba real de compra', false, 'No se crea un pedido real: la V7.0 mantiene el chequeo en modo solo lectura y valida el recorrido público hasta checkout.', 'info');

    if (function_exists('seo_core_system_test_billing')) {
        $results = array_merge($results, seo_core_system_test_billing());
    }

    return $results;
}

function seo_core_system_test_emails() {
    $plugin_root = seo_core_system_test_get_plugin_root();
    $email_templates = array(
        array('5.1 Email processing disponible', 'seo-system/templates/email-processing.php'),
        array('5.2 Email completed disponible', 'seo-system/templates/email-completed.php'),
        array('5.3 Email cancelled disponible', 'seo-system/templates/email-cancelled.php'),
        array('5.4 Email refunded disponible', 'seo-system/templates/email-refunded.php'),
    );

    $results = array();

    foreach ($email_templates as $check) {
        $path = trailingslashit($plugin_root) . $check[1];
        $passed = file_exists($path) && is_readable($path);

        $results[] = seo_core_system_test_result(
            'emails',
            $check[0],
            $passed,
            $passed ? $path : 'No encontrado o no legible: ' . $check[1],
            $passed ? 'ok' : 'warning'
        );
    }

    return $results;
}

function seo_core_system_test_seo_system() {
    global $wpdb;

    $results = array();
    $tables = array(
        $wpdb->prefix . 'seo_nodes'                 => '6.1 Tabla seo_nodes',
        $wpdb->prefix . 'seo_relations'             => '6.2 Tabla seo_relations',
        $wpdb->prefix . 'seo_faq'                   => '6.3 Tabla seo_faq',
        $wpdb->prefix . 'seo_redirects'             => '6.4 Tabla seo_redirects',
        $wpdb->prefix . 'seo_proveedores_productos' => '6.5 Tabla seo_proveedores_productos',
    );

    foreach ($tables as $table => $label) {
        $exists = seo_core_system_test_table_exists($table);
        $required = in_array($table, array($wpdb->prefix . 'seo_nodes', $wpdb->prefix . 'seo_relations'), true);

        $results[] = seo_core_system_test_result(
            'seo_system',
            $label,
            $exists,
            $exists ? $table : $table . ' no detectada',
            $exists ? 'ok' : ($required ? 'ko' : 'warning')
        );
    }

    return $results;
}

function seo_core_system_test_functional_business() {
    $results = array();
    $http_enabled = (bool) apply_filters('seo_core_system_test_enable_functional_http_checks', true);
    $product = seo_core_system_test_get_representative_product();
    $category = seo_core_system_test_get_representative_category_for_product($product);

    $home_url = home_url('/');
    $shop_id = function_exists('wc_get_page_id') ? (int) wc_get_page_id('shop') : 0;
    $shop_url = seo_core_system_test_valid_page_id($shop_id) ? get_permalink($shop_id) : '';
    $product_url = !empty($product['url']) ? $product['url'] : '';
    $category_url = !empty($category['url']) ? $category['url'] : '';
    $cart_id = function_exists('wc_get_page_id') ? (int) wc_get_page_id('cart') : 0;
    $cart_url = seo_core_system_test_valid_page_id($cart_id) ? get_permalink($cart_id) : '';
    $checkout_id = function_exists('wc_get_page_id') ? (int) wc_get_page_id('checkout') : 0;
    $checkout_url = seo_core_system_test_valid_page_id($checkout_id) ? get_permalink($checkout_id) : '';

    $results[] = seo_core_system_test_functional_http_result('8.1 Portada navegable', $home_url, '', true, $http_enabled);
    $results[] = seo_core_system_test_functional_http_result('8.2 Tienda navegable', $shop_url, '', true, $http_enabled);
    $results[] = seo_core_system_test_functional_http_result('8.3 Categoría representativa navegable', $category_url, '', false, $http_enabled);
    $results[] = seo_core_system_test_functional_http_result('8.4 Producto representativo navegable', $product_url, '', true, $http_enabled);

    $search_check = seo_core_system_test_search_query_check($product);
    $results[] = seo_core_system_test_result(
        'functional',
        '8.5 Buscador interno localiza un producto publicado',
        $search_check['passed'],
        $search_check['detail'],
        $search_check['severity'],
        array(
            'owner' => 'WP',
            'area' => 'search',
            'evidence' => isset($search_check['evidence']) && is_array($search_check['evidence']) ? $search_check['evidence'] : array(),
            'confidence' => 95,
        )
    );

    $search_url = '';
    if (!empty($search_check['term'])) {
        $search_url = add_query_arg(array('s' => $search_check['term'], 'post_type' => 'product'), $home_url);
    }

    $results[] = seo_core_system_test_functional_http_result('8.6 Página de resultados de búsqueda navegable', $search_url, '', false, $http_enabled);
    $results[] = seo_core_system_test_functional_http_result(
        '8.7 Plantilla de producto renderiza contenido',
        $product_url,
        !empty($product['title']) ? $product['title'] : '',
        true,
        $http_enabled
    );
    $results[] = seo_core_system_test_functional_http_result(
        '8.8 Plantilla de categoría renderiza contenido',
        $category_url,
        !empty($category['name']) ? $category['name'] : '',
        false,
        $http_enabled
    );
    $results[] = seo_core_system_test_functional_http_result('8.9 Carrito navegable', $cart_url, '', true, $http_enabled);
    $results[] = seo_core_system_test_functional_http_result('8.10 Checkout navegable', $checkout_url, '', true, $http_enabled);

    $email_check = seo_core_system_test_email_registry_check();
    $results[] = seo_core_system_test_result('functional', '8.11 Correos WooCommerce registrados', $email_check['passed'], $email_check['detail'], $email_check['severity']);

    $email_template_check = seo_core_system_test_email_template_resolution_check();
    $results[] = seo_core_system_test_result('functional', '8.12 Plantillas de correo resolubles', $email_template_check['passed'], $email_template_check['detail'], $email_template_check['severity']);

    $basic_summary = seo_core_system_test_functional_summary($results, 12);
    $results[] = seo_core_system_test_result(
        'functional',
        '8.13 Recorrido funcional básico del cliente',
        $basic_summary['passed'],
        $basic_summary['detail'],
        $basic_summary['severity']
    );

    $page_urls = array(
        'portada'   => $home_url,
        'tienda'    => $shop_url,
        'categoria' => $category_url,
        'producto'  => $product_url,
        'busqueda'  => $search_url,
        'carrito'   => $cart_url,
        'checkout'  => $checkout_url,
    );

    $quality_checks = array(
        '8.14 Página 404 real' => seo_core_system_test_404_check($http_enabled),
        '8.15 Búsqueda sin resultados' => seo_core_system_test_empty_search_check($http_enabled),
        '8.16 Títulos HTML válidos' => seo_core_system_test_html_titles_check($page_urls, $http_enabled),
        '8.17 Canonical coherente' => seo_core_system_test_canonical_check($page_urls, $http_enabled),
        '8.18 Política meta robots' => seo_core_system_test_robots_check($page_urls, $http_enabled),
        '8.19 Encabezados H1 de plantillas' => seo_core_system_test_h1_check($page_urls, $product, $category, $http_enabled),
        '8.20 Datos estructurados JSON-LD' => seo_core_system_test_structured_data_check($page_urls, $http_enabled),
        '8.21 Sitemap público' => seo_core_system_test_sitemap_check($http_enabled),
        '8.21B Indexación pública coherente' => seo_core_system_test_indexation_readiness_check($page_urls, $http_enabled),
        '8.22 Imagen principal del producto' => seo_core_system_test_product_image_check($product, $http_enabled),
        '8.23 Recursos CSS y JavaScript esenciales' => seo_core_system_test_essential_resources_check($page_urls, $http_enabled),
        '8.24 Producto representativo vendible' => seo_core_system_test_product_sellability_check($product),
        '8.24B Tienda preparada para vender' => seo_core_system_test_store_readiness_check($product, $page_urls, $http_enabled),
        '8.25 Enlaces internos esenciales' => seo_core_system_test_internal_links_check($page_urls, $http_enabled),
        '8.26 Redirecciones registradas' => seo_core_system_test_redirects_check($http_enabled),
        '8.27 Rendimiento HTTP básico' => seo_core_system_test_performance_check($page_urls, $http_enabled),
        '8.28 Respuestas sin errores PHP o SQL visibles' => seo_core_system_test_visible_errors_check($page_urls, $http_enabled),
    );

    foreach ($quality_checks as $label => $check) {
        $meta = is_array($check) ? $check : array();
        unset($meta['passed'], $meta['detail'], $meta['severity']);
        $results[] = seo_core_system_test_result('functional', $label, $check['passed'], $check['detail'], $check['severity'], $meta);
    }

    $expanded_summary = seo_core_system_test_functional_summary($results);
    $results[] = seo_core_system_test_result(
        'functional',
        '8.29 Salud funcional ampliada',
        $expanded_summary['passed'],
        $expanded_summary['detail'],
        $expanded_summary['severity']
    );

    return $results;
}


function seo_core_system_test_link_audit_begin_budget($seconds) {
    $seconds = max(5, min(25, (int) $seconds));
    $GLOBALS['seo_core_system_test_link_audit_started'] = microtime(true);
    $GLOBALS['seo_core_system_test_link_audit_deadline'] = microtime(true) + $seconds;
    $GLOBALS['seo_core_system_test_link_audit_budget'] = $seconds;
}

function seo_core_system_test_link_audit_budget_active() {
    return !empty($GLOBALS['seo_core_system_test_link_audit_deadline']);
}

function seo_core_system_test_link_audit_time_remaining() {
    if (!seo_core_system_test_link_audit_budget_active()) {
        return 9999.0;
    }
    return max(0.0, (float) $GLOBALS['seo_core_system_test_link_audit_deadline'] - microtime(true));
}

function seo_core_system_test_link_audit_can_continue($reserve = 0.35) {
    return !seo_core_system_test_link_audit_budget_active() || seo_core_system_test_link_audit_time_remaining() > (float) $reserve;
}

function seo_core_system_test_link_audit_elapsed() {
    if (empty($GLOBALS['seo_core_system_test_link_audit_started'])) {
        return 0.0;
    }
    return max(0.0, microtime(true) - (float) $GLOBALS['seo_core_system_test_link_audit_started']);
}

function seo_core_system_test_link_audit_cache_key() {
    return 'seo_core_system_test_404_v7';
}

function seo_core_system_test_store_link_audit_results($results) {
    set_transient(seo_core_system_test_link_audit_cache_key(), array(
        'saved_at' => time(),
        'results' => is_array($results) ? $results : array(),
    ), 180 * DAY_IN_SECONDS);
}

function seo_core_system_test_empty_redirect_audit() {
    return array('missing_table' => false, 'records' => 0, 'checked' => 0, 'broken' => array(), 'loops' => array(), 'invalid' => array(), 'warnings' => array(), 'skipped' => true);
}

function seo_core_system_test_empty_resource_audit($discovered = 0) {
    return array('discovered' => (int) $discovered, 'checked' => 0, 'broken' => array(), 'warnings' => array(), 'skipped' => true);
}


function seo_core_system_test_link_audit_state_cache_key() {
    return 'seo_core_system_test_404_state_v7';
}

function seo_core_system_test_load_link_audit_state() {
    $state = get_transient(seo_core_system_test_link_audit_state_cache_key());
    if (!is_array($state)) {
        return array('completed' => array(), 'results' => array(), 'saved_at' => 0);
    }
    $state['completed'] = isset($state['completed']) && is_array($state['completed']) ? array_values(array_unique($state['completed'])) : array();
    $state['results'] = isset($state['results']) && is_array($state['results']) ? $state['results'] : array();
    return $state;
}

function seo_core_system_test_store_link_audit_state($state) {
    $state = is_array($state) ? $state : array();
    $state['saved_at'] = time();
    set_transient(seo_core_system_test_link_audit_state_cache_key(), $state, 180 * DAY_IN_SECONDS);
    if (!empty($state['results'])) {
        seo_core_system_test_store_link_audit_results($state['results']);
    }
}

function seo_core_system_test_reset_link_audit_state() {
    delete_transient(seo_core_system_test_link_audit_state_cache_key());
    delete_transient(seo_core_system_test_link_audit_cache_key());
}

function seo_core_system_test_next_link_audit_phase($state = null) {
    if (!is_array($state)) {
        $state = seo_core_system_test_load_link_audit_state();
    }
    $completed = isset($state['completed']) && is_array($state['completed']) ? $state['completed'] : array();
    foreach (array('links', 'seo', 'resources') as $phase) {
        if (!in_array($phase, $completed, true)) {
            return $phase;
        }
    }

    $rotation = array('links' => 'seo', 'seo' => 'resources', 'resources' => 'links');
    $last_phase = isset($state['last_phase']) ? $state['last_phase'] : 'resources';
    return isset($rotation[$last_phase]) ? $rotation[$last_phase] : 'links';
}

function seo_core_system_test_run_link_audit_phase($phase) {
    $started_at = time();
    $started_microtime = microtime(true);
    $phase = in_array($phase, array('links', 'seo', 'resources'), true) ? $phase : 'links';
    $budget = $phase === 'links' ? 7 : 6;
    seo_core_system_test_link_audit_begin_budget($budget);

    if ($phase === 'links') {
        $phase_results = seo_core_system_test_link_audit_phase_links();
    } elseif ($phase === 'seo') {
        $phase_results = seo_core_system_test_link_audit_phase_seo();
    } else {
        $phase_results = seo_core_system_test_link_audit_phase_resources();
    }

    $state = seo_core_system_test_load_link_audit_state();
    $existing = array();
    foreach ((array) $state['results'] as $result) {
        if (empty($result['label']) || strpos((string) $result['label'], '9.1 ') === 0 || strpos((string) $result['label'], '9.11 ') === 0 || strpos((string) $result['label'], '9.12 ') === 0) {
            continue;
        }
        if (!empty($result['pending'])) {
            continue;
        }
        $existing[(string) $result['label']] = $result;
    }
    foreach ($phase_results as $result) {
        if (!empty($result['label'])) {
            $existing[(string) $result['label']] = $result;
        }
    }
    if (!in_array($phase, $state['completed'], true)) {
        $state['completed'][] = $phase;
    }
    $state['last_phase'] = $phase;
    $state['last_elapsed'] = seo_core_system_test_link_audit_elapsed();
    $state['results'] = seo_core_system_test_compose_link_audit_results($existing, $state);
    seo_core_system_test_store_link_audit_state($state);
    $metadata = seo_core_system_test_build_run_metadata(
        'manual_links_' . $phase,
        $started_at,
        $started_microtime
    );
    seo_core_system_test_store_results_bundle('links', $state['results'], $metadata);
    seo_core_system_test_dispatch_completed_run('links', 'manual_links_' . $phase, $state['results'], $metadata);
    return $state['results'];
}

function seo_core_system_test_links_404_not_run_results() {
    return seo_core_system_test_compose_link_audit_results(array(), array('completed' => array(), 'last_elapsed' => 0));
}

function seo_core_system_test_compose_link_audit_results($stored, $state) {
    $stored = is_array($stored) ? $stored : array();
    $completed = isset($state['completed']) && is_array($state['completed']) ? $state['completed'] : array();
    $phase_labels = array('links' => 'enlaces prioritarios', 'seo' => 'sitemap y redirecciones', 'resources' => 'imágenes y recursos');
    $completed_names = array();
    foreach ($completed as $phase) {
        if (isset($phase_labels[$phase])) {
            $completed_names[] = $phase_labels[$phase];
        }
    }
    $next = seo_core_system_test_next_link_audit_phase(array('completed' => $completed));
    $status_detail = 'Bloques completados: ' . count($completed) . '/3';
    if (!empty($completed_names)) {
        $status_detail .= ' (' . implode(', ', $completed_names) . ')';
    }
    if (count($completed) < 3) {
        $status_detail .= '. Siguiente bloque recomendado: ' . $phase_labels[$next] . '.';
    } else {
        $status_detail .= '. Auditoría acumulativa completa; puedes repetir cualquier bloque para actualizarlo.';
    }
    if (!empty($state['last_elapsed'])) {
        $status_detail .= ' Última ejecución: ' . number_format_i18n((float) $state['last_elapsed'], 2) . ' s.';
    }

    $results = array(seo_core_system_test_result('links_404', '9.1 Progreso de la auditoría', true, $status_detail, 'info'));
    $expected = array(
        '9.2 Fuentes públicas rastreadas' => 'links',
        '9.3 Cobertura de enlaces internos' => 'links',
        '9.4 Enlaces internos rotos' => 'links',
        '9.5 Cadenas y bucles de redirección' => 'links',
        '9.6 Posibles soft-404' => 'links',
        '9.7 URLs publicadas en sitemap' => 'seo',
        '9.8 Redirecciones registradas' => 'seo',
        '9.9 Imágenes internas' => 'resources',
        '9.10 CSS y JavaScript locales' => 'resources',
    );
    foreach ($expected as $label => $phase) {
        if (isset($stored[$label])) {
            $results[] = $stored[$label];
        } else {
            $pending = seo_core_system_test_result('links_404', $label, true, 'Pendiente. Ejecuta el bloque «' . $phase_labels[$phase] . '».', 'info');
            $pending['pending'] = true;
            $results[] = $pending;
        }
    }

    $priority_counts = array('critica' => 0, 'alta' => 0, 'media' => 0, 'baja' => 0);
    $seen = array();
    foreach ($results as $result) {
        foreach ((array) (isset($result['items']) ? $result['items'] : array()) as $item) {
            $key = md5(strtolower((string) (isset($item['status']) ? $item['status'] : '')) . '|' . seo_core_system_test_normalize_url_for_compare(isset($item['url']) ? $item['url'] : '') . '|' . (string) (isset($item['origin']) ? $item['origin'] : ''));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $priority = isset($item['priority']) && isset($priority_counts[$item['priority']]) ? $item['priority'] : 'baja';
            $priority_counts[$priority]++;
        }
    }
    $priority_detail = 'Incidencias únicas: ' . number_format_i18n(array_sum($priority_counts))
        . '; críticas: ' . number_format_i18n($priority_counts['critica'])
        . '; altas: ' . number_format_i18n($priority_counts['alta'])
        . '; medias: ' . number_format_i18n($priority_counts['media'])
        . '; bajas: ' . number_format_i18n($priority_counts['baja']) . '.';
    $results[] = seo_core_system_test_result('links_404', '9.11 Prioridad de reparación', true, $priority_detail, 'info');

    $critical = 0;
    $warnings = 0;
    foreach ($results as $result) {
        if (!empty($result['pending']) || strpos((string) $result['label'], '9.1 ') === 0 || strpos((string) $result['label'], '9.11 ') === 0) {
            continue;
        }
        if ($result['severity'] === 'ko') {
            $critical++;
        } elseif ($result['severity'] === 'warning') {
            $warnings++;
        }
    }
    if ($critical > 0) {
        $summary = seo_core_system_test_result('links_404', '9.12 Salud de enlaces y 404', false, 'Se han detectado ' . number_format_i18n($critical) . ' bloques con errores críticos y ' . number_format_i18n($warnings) . ' con avisos. El KO de este resumen no es una incidencia adicional: resume los bloques anteriores.', 'ko');
    } elseif ($warnings > 0) {
        $summary = seo_core_system_test_result('links_404', '9.12 Salud de enlaces y 404', false, 'No hay errores críticos en los bloques ejecutados, pero existen ' . number_format_i18n($warnings) . ' bloques con avisos.', 'warning');
    } elseif (count($completed) < 3) {
        $summary = seo_core_system_test_result('links_404', '9.12 Salud de enlaces y 404', true, 'Auditoría parcial sin errores en los bloques ejecutados. Faltan ' . number_format_i18n(3 - count($completed)) . ' bloques.', 'info');
    } else {
        $summary = seo_core_system_test_result('links_404', '9.12 Salud de enlaces y 404', true, 'Los tres bloques han finalizado sin enlaces rotos, destinos inválidos ni recursos ausentes dentro de las muestras configuradas.', 'ok');
    }
    $results[] = $summary;
    return $results;
}

function seo_core_system_test_link_audit_phase_links() {
    $limits = array('sources' => 4, 'links' => 9, 'content_records' => 80);
    $seed_data = seo_core_system_test_link_audit_seed_urls($limits['sources']);
    $link_map = $seed_data['links'];
    $source_failures = array();
    $source_successes = 0;
    seo_core_system_test_collect_database_link_references($link_map, $limits['content_records']);

    foreach ($seed_data['sources'] as $source) {
        if (!seo_core_system_test_link_audit_can_continue(1.5)) {
            break;
        }
        $trace = seo_core_system_test_http_trace($source['url']);
        if (!seo_core_system_test_trace_has_usable_html($trace)) {
            $source_failures[] = seo_core_system_test_link_issue_item($source['priority'], seo_core_system_test_trace_status_label($trace), $source['url'], $source['label'], seo_core_system_test_trace_problem_detail($trace));
            continue;
        }
        $source_successes++;
        $base_url = !empty($trace['final_url']) ? $trace['final_url'] : $source['url'];
        foreach (seo_core_system_test_extract_anchor_urls($trace['body'], $base_url) as $url) {
            seo_core_system_test_link_map_add($link_map, $url, $source['label'], $source['priority']);
        }
    }

    $sorted_links = seo_core_system_test_sort_link_map($link_map);
    $checked = 0;
    $broken = array();
    $redirecting = array();
    $loops = array();
    $soft = array();
    foreach ($sorted_links as $entry) {
        if ($checked >= $limits['links'] || !seo_core_system_test_link_audit_can_continue(0.45)) {
            break;
        }
        $checked++;
        $trace = seo_core_system_test_http_trace($entry['url']);
        $origin = implode(' | ', array_slice($entry['origins'], 0, 4));
        $priority = seo_core_system_test_priority_label($entry['priority']);
        if (!empty($trace['loop']) || !empty($trace['too_many_redirects'])) {
            $loops[] = seo_core_system_test_link_issue_item($priority, 'Bucle', $entry['url'], $origin, seo_core_system_test_trace_problem_detail($trace));
        } elseif (!empty($trace['transport_error']) || !empty($trace['security_challenge']) || in_array((int) $trace['code'], array(401, 403, 429), true)) {
            continue;
        } elseif (!empty($trace['external_redirect'])) {
            $redirecting[] = seo_core_system_test_link_issue_item($priority, 'Redirección externa', $entry['url'], $origin, seo_core_system_test_trace_problem_detail($trace));
        } elseif (seo_core_system_test_trace_is_broken($trace)) {
            $detail = seo_core_system_test_trace_problem_detail($trace) . seo_core_system_test_local_broken_url_diagnosis($entry['url'], $origin);
            $broken[] = seo_core_system_test_link_issue_item($priority, seo_core_system_test_trace_status_label($trace), $entry['url'], $origin, $detail);
        } else {
            if (!empty($trace['redirect_count'])) {
                $redirecting[] = seo_core_system_test_link_issue_item($priority, $trace['redirect_count'] > 1 ? 'Cadena ' . (int) $trace['redirect_count'] : 'Redirección', $entry['url'], $origin, seo_core_system_test_trace_chain_detail($trace));
            }
            if (seo_core_system_test_trace_is_soft_404($trace, $entry['url'])) {
                $soft[] = seo_core_system_test_link_issue_item($priority, 'Soft-404', $entry['url'], $origin, 'HTTP 200 o redirección válida, pero el HTML o el destino muestran señales de página no encontrada.');
            }
        }
    }

    $results = array();
    $source_severity = empty($source_failures) ? 'ok' : 'warning';
    $source = seo_core_system_test_result('links_404', '9.2 Fuentes públicas rastreadas', empty($source_failures), 'Fuentes solicitadas: ' . count($seed_data['sources']) . '; HTML rastreable: ' . $source_successes . '; no concluyentes: ' . count($source_failures) . '.', $source_severity);
    $source['items'] = $source_failures;
    $results[] = $source;
    $coverage = count($sorted_links) > 0 ? round(($checked / count($sorted_links)) * 100, 1) : 0;
    $results[] = seo_core_system_test_result('links_404', '9.3 Cobertura de enlaces internos', true, 'Enlaces internos únicos detectados: ' . count($sorted_links) . '; comprobados por HTTP: ' . $checked . ' (' . number_format_i18n($coverage, 1) . '%). La muestra prioriza menús y URLs enlazadas desde varios orígenes.', 'info');
    $broken = seo_core_system_test_unique_link_items($broken);
    $r = seo_core_system_test_result('links_404', '9.4 Enlaces internos rotos', empty($broken), empty($broken) ? 'No se han encontrado enlaces rotos en la muestra prioritaria.' : 'Enlaces internos rotos: ' . count($broken) . '. ' . seo_core_system_test_link_items_inline($broken), empty($broken) ? 'ok' : 'ko');
    $r['items'] = $broken;
    $results[] = $r;
    $loops = seo_core_system_test_unique_link_items($loops);
    $redirecting = seo_core_system_test_unique_link_items($redirecting);
    $severity = !empty($loops) ? 'ko' : (!empty($redirecting) ? 'warning' : 'ok');
    $detail = !empty($loops) ? 'Bucles detectados: ' . count($loops) . '.' : (!empty($redirecting) ? 'Enlaces que pasan por redirección: ' . count($redirecting) . '. Conviene enlazar directamente al destino final.' : 'Los enlaces comprobados apuntan directamente a su destino y no forman bucles.');
    $r = seo_core_system_test_result('links_404', '9.5 Cadenas y bucles de redirección', $severity === 'ok', $detail, $severity);
    $r['items'] = array_merge($loops, $redirecting);
    $results[] = $r;
    $soft = seo_core_system_test_unique_link_items($soft);
    $r = seo_core_system_test_result('links_404', '9.6 Posibles soft-404', empty($soft), empty($soft) ? 'No se han detectado señales fiables de soft-404.' : 'Posibles soft-404: ' . count($soft) . '. Requieren revisión manual.', empty($soft) ? 'ok' : 'warning');
    $r['items'] = $soft;
    $results[] = $r;
    return $results;
}

function seo_core_system_test_link_audit_phase_seo() {
    $overall_deadline = isset($GLOBALS['seo_core_system_test_link_audit_deadline']) ? (float) $GLOBALS['seo_core_system_test_link_audit_deadline'] : microtime(true) + 6;
    $GLOBALS['seo_core_system_test_link_audit_deadline'] = min($overall_deadline, microtime(true) + 3.0);
    $sitemap = seo_core_system_test_sitemap_link_audit(4, 2);
    if (!seo_core_system_test_link_audit_can_continue(0.05)) {
        $sitemap['skipped'] = true;
    }
    $GLOBALS['seo_core_system_test_link_audit_deadline'] = $overall_deadline;
    $redirects = seo_core_system_test_link_audit_can_continue(0.8) ? seo_core_system_test_registered_redirect_audit(5) : seo_core_system_test_empty_redirect_audit();
    if (!seo_core_system_test_link_audit_can_continue(0.05)) {
        $redirects['skipped'] = true;
    }

    $results = array();
    $severity = !empty($sitemap['broken']) ? 'ko' : (!empty($sitemap['warnings']) || !empty($sitemap['skipped']) ? 'warning' : 'ok');
    $detail = 'Sitemaps leídos: ' . $sitemap['sitemaps'] . '; URLs localizadas: ' . $sitemap['discovered'] . '; comprobadas: ' . $sitemap['checked'] . '; rotas: ' . count($sitemap['broken']) . '.';
    if (!empty($sitemap['skipped'])) {
        $detail .= ' El bloque terminó de forma parcial dentro de su límite seguro.';
    }
    $r = seo_core_system_test_result('links_404', '9.7 URLs publicadas en sitemap', $severity === 'ok', $detail, $severity);
    $r['items'] = array_merge($sitemap['broken'], $sitemap['warnings']);
    $results[] = $r;

    $severity = (!empty($redirects['broken']) || !empty($redirects['loops']) || !empty($redirects['invalid'])) ? 'ko' : (!empty($redirects['warnings']) || !empty($redirects['skipped']) || !empty($redirects['missing_table']) ? 'warning' : 'ok');
    $detail = $redirects['missing_table'] ? 'No existe la tabla seo_redirects.' : 'Registros analizados: ' . $redirects['records'] . '; destinos comprobados: ' . $redirects['checked'] . '; rotos: ' . count($redirects['broken']) . '; bucles: ' . count($redirects['loops']) . '; configuración inválida: ' . count($redirects['invalid']) . '.';
    if (!empty($redirects['skipped'])) {
        $detail .= ' El bloque terminó de forma parcial dentro de su límite seguro.';
    }
    $r = seo_core_system_test_result('links_404', '9.8 Redirecciones registradas', $severity === 'ok', $detail, $severity);
    $r['items'] = array_merge($redirects['invalid'], $redirects['loops'], $redirects['broken'], $redirects['warnings']);
    $results[] = $r;
    return $results;
}

function seo_core_system_test_link_audit_phase_resources() {
    $seed = seo_core_system_test_link_audit_seed_urls(3);
    $images = array();
    $assets = array();
    foreach ($seed['sources'] as $source) {
        if (!seo_core_system_test_link_audit_can_continue(2.6)) {
            break;
        }
        $trace = seo_core_system_test_http_trace($source['url']);
        if (!seo_core_system_test_trace_has_usable_html($trace)) {
            continue;
        }
        $base = !empty($trace['final_url']) ? $trace['final_url'] : $source['url'];
        foreach (seo_core_system_test_extract_image_urls($trace['body'], $base) as $url) {
            seo_core_system_test_resource_map_add($images, $url, $source['label'], $source['priority']);
        }
        foreach (seo_core_system_test_extract_asset_urls($trace['body'], $base) as $url) {
            seo_core_system_test_resource_map_add($assets, $url, $source['label'], $source['priority']);
        }
    }
    $overall_deadline = isset($GLOBALS['seo_core_system_test_link_audit_deadline']) ? (float) $GLOBALS['seo_core_system_test_link_audit_deadline'] : microtime(true) + 3;
    $GLOBALS['seo_core_system_test_link_audit_deadline'] = min($overall_deadline, microtime(true) + max(1.2, seo_core_system_test_link_audit_time_remaining() / 2));
    $image_audit = seo_core_system_test_resource_map_audit($images, 4, 'image');
    $GLOBALS['seo_core_system_test_link_audit_deadline'] = $overall_deadline;
    $asset_audit = seo_core_system_test_link_audit_can_continue(0.5) ? seo_core_system_test_resource_map_audit($assets, 4, 'asset') : seo_core_system_test_empty_resource_audit(count($assets));
    if (!seo_core_system_test_link_audit_can_continue(0.05)) {
        $asset_audit['skipped'] = true;
    }

    $results = array();
    $severity = !empty($image_audit['broken']) ? 'ko' : (!empty($image_audit['warnings']) || !empty($image_audit['skipped']) ? 'warning' : 'ok');
    $r = seo_core_system_test_result('links_404', '9.9 Imágenes internas', $severity === 'ok', 'Imágenes detectadas: ' . $image_audit['discovered'] . '; comprobadas: ' . $image_audit['checked'] . '; rotas: ' . count($image_audit['broken']) . '.', $severity);
    $r['items'] = array_merge($image_audit['broken'], $image_audit['warnings']);
    $results[] = $r;
    $severity = !empty($asset_audit['broken']) ? 'ko' : (!empty($asset_audit['warnings']) || !empty($asset_audit['skipped']) ? 'warning' : 'ok');
    $detail = 'Recursos CSS/JS detectados: ' . $asset_audit['discovered'] . '; comprobados: ' . $asset_audit['checked'] . '; rotos: ' . count($asset_audit['broken']) . '.';
    if (!empty($asset_audit['skipped'])) {
        $detail .= ' Comprobación parcial dentro del límite seguro.';
    }
    $r = seo_core_system_test_result('links_404', '9.10 CSS y JavaScript locales', $severity === 'ok', $detail, $severity);
    $r['items'] = array_merge($asset_audit['broken'], $asset_audit['warnings']);
    $results[] = $r;
    return $results;
}

function seo_core_system_test_local_broken_url_diagnosis($url, $origin) {
    $parts = wp_parse_url((string) $url);
    $extra = '';
    if (is_array($parts) && !empty($parts['query'])) {
        parse_str((string) $parts['query'], $query);
        $post_id = isset($query['page_id']) ? (int) $query['page_id'] : (isset($query['p']) ? (int) $query['p'] : 0);
        if ($post_id > 0) {
            $post = get_post($post_id);
            if (!$post) {
                $extra .= ' Diagnóstico local: el ID ' . $post_id . ' no existe en wp_posts.';
            } else {
                $extra .= ' Diagnóstico local: ID ' . $post_id . ', tipo ' . $post->post_type . ', estado ' . $post->post_status . '.';
                if ($post->post_status === 'publish') {
                    $permalink = get_permalink($post_id);
                    if ($permalink) {
                        $extra .= ' Enlace permanente actual: ' . $permalink . '.';
                    }
                }
            }
        }
    }
    if (stripos((string) $origin, 'Menú') !== false) {
        $extra .= ' Reparación sugerida: edita o elimina el elemento de menú que conserva esta URL.';
    } else {
        $extra .= ' Reparación sugerida: sustituye el enlace en el origen indicado o crea una redirección 301 hacia la alternativa más equivalente.';
    }
    return $extra;
}

function seo_core_system_test_link_audit_seed_urls($limit) {
    $sources = array();
    $links = array();
    $add_source = static function ($url, $label, $priority) use (&$sources, &$links, $limit) {
        $url = seo_core_system_test_crawlable_internal_url($url);
        if ($url === '') {
            return;
        }
        seo_core_system_test_link_map_add($links, $url, $label, $priority);
        $key = seo_core_system_test_normalize_url_for_compare($url);
        foreach ($sources as $source) {
            if (seo_core_system_test_normalize_url_for_compare($source['url']) === $key) {
                return;
            }
        }
        if (count($sources) < $limit) {
            $sources[] = array('url' => $url, 'label' => $label, 'priority' => $priority);
        }
    };

    $add_source(home_url('/'), 'Portada', 4);
    if (function_exists('wc_get_page_id')) {
        $shop_id = (int) wc_get_page_id('shop');
        if (seo_core_system_test_valid_page_id($shop_id)) {
            $add_source(get_permalink($shop_id), 'Tienda', 4);
        }
    }

    $product = seo_core_system_test_get_representative_product();
    if (!empty($product['url'])) {
        $add_source($product['url'], 'Producto representativo', 3);
    }
    $category = seo_core_system_test_get_representative_category_for_product($product);
    if (!empty($category['url'])) {
        $add_source($category['url'], 'Categoría representativa', 3);
    }

    if (function_exists('wp_get_nav_menus') && function_exists('wp_get_nav_menu_items')) {
        foreach ((array) wp_get_nav_menus() as $menu) {
            $items = wp_get_nav_menu_items($menu->term_id);
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                $label = 'Menú ' . (string) $menu->name . ': ' . (string) $item->title;
                $url = isset($item->url) ? (string) $item->url : '';
                seo_core_system_test_link_map_add($links, $url, $label, 4);
                if (count($sources) < $limit) {
                    $add_source($url, $label, 4);
                }
            }
        }
    }

    $post_types = array('page', 'post', 'product');
    foreach ($post_types as $post_type) {
        if (count($sources) >= $limit || !post_type_exists($post_type)) {
            continue;
        }
        $posts = get_posts(array(
            'post_type' => $post_type,
            'post_status' => 'publish',
            'posts_per_page' => min(6, $limit - count($sources)),
            'orderby' => 'modified',
            'order' => 'DESC',
            'fields' => 'ids',
            'no_found_rows' => true,
        ));
        foreach ($posts as $post_id) {
            $title = get_the_title($post_id);
            $priority = $post_type === 'page' ? 3 : 2;
            $add_source(get_permalink($post_id), ucfirst($post_type) . ' #' . (int) $post_id . ': ' . $title, $priority);
        }
    }

    return array('sources' => $sources, 'links' => $links);
}

function seo_core_system_test_collect_database_link_references(&$link_map, $limit) {
    global $wpdb;

    $limit = max(1, (int) $limit);
    $rows = $wpdb->get_results(
        "SELECT ID, post_type, post_title, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post','product') AND post_content LIKE '%href=%' ORDER BY post_modified_gmt DESC LIMIT {$limit}",
        ARRAY_A
    );
    foreach ((array) $rows as $row) {
        $post_id = isset($row['ID']) ? (int) $row['ID'] : 0;
        $base = $post_id > 0 ? get_permalink($post_id) : home_url('/');
        $origin = ucfirst((string) $row['post_type']) . ' #' . $post_id . ': ' . (string) $row['post_title'];
        $priority = ((string) $row['post_type'] === 'page') ? 3 : 2;
        foreach (seo_core_system_test_extract_anchor_urls((string) $row['post_content'], $base) as $url) {
            seo_core_system_test_link_map_add($link_map, $url, $origin, $priority);
        }
    }

    if (taxonomy_exists('product_cat')) {
        $terms = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => min(500, $limit), 'orderby' => 'term_id', 'order' => 'DESC'));
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                if (empty($term->description)) {
                    continue;
                }
                $base = get_term_link($term);
                if (is_wp_error($base)) {
                    $base = home_url('/');
                }
                foreach (seo_core_system_test_extract_anchor_urls((string) $term->description, $base) as $url) {
                    seo_core_system_test_link_map_add($link_map, $url, 'Categoría #' . (int) $term->term_id . ': ' . (string) $term->name, 3);
                }
            }
        }
    }
}

function seo_core_system_test_link_map_add(&$map, $url, $origin, $priority) {
    $url = seo_core_system_test_crawlable_internal_url($url);
    if ($url === '') {
        return;
    }
    $key = seo_core_system_test_normalize_url_for_compare($url);
    if ($key === '') {
        return;
    }
    if (!isset($map[$key])) {
        $map[$key] = array('url' => $url, 'origins' => array(), 'priority' => (int) $priority);
    }
    $map[$key]['priority'] = max((int) $map[$key]['priority'], (int) $priority);
    if ($origin !== '' && !in_array($origin, $map[$key]['origins'], true) && count($map[$key]['origins']) < 12) {
        $map[$key]['origins'][] = $origin;
    }
}

function seo_core_system_test_resource_map_add(&$map, $url, $origin, $priority) {
    $url = seo_core_system_test_internal_resource_url($url);
    if ($url === '') {
        return;
    }
    $key = seo_core_system_test_normalize_url_for_compare($url);
    if ($key === '') {
        return;
    }
    if (!isset($map[$key])) {
        $map[$key] = array('url' => $url, 'origins' => array(), 'priority' => (int) $priority);
    }
    $map[$key]['priority'] = max((int) $map[$key]['priority'], (int) $priority);
    if ($origin !== '' && !in_array($origin, $map[$key]['origins'], true) && count($map[$key]['origins']) < 8) {
        $map[$key]['origins'][] = $origin;
    }
}

function seo_core_system_test_sort_link_map($map) {
    $entries = array_values((array) $map);
    usort($entries, static function ($left, $right) {
        if ((int) $left['priority'] !== (int) $right['priority']) {
            return (int) $right['priority'] <=> (int) $left['priority'];
        }
        if (count($left['origins']) !== count($right['origins'])) {
            return count($right['origins']) <=> count($left['origins']);
        }
        return strnatcasecmp((string) $left['url'], (string) $right['url']);
    });
    return $entries;
}

function seo_core_system_test_crawlable_internal_url($url) {
    $url = seo_core_system_test_absolute_url((string) $url, home_url('/'));
    if ($url === '') {
        return '';
    }
    $parts = wp_parse_url($url);
    $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    if (!is_array($parts) || empty($parts['host']) || strtolower((string) $parts['host']) !== $home_host) {
        return '';
    }
    $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
    if (!in_array($scheme, array('http', 'https'), true)) {
        return '';
    }
    $path = isset($parts['path']) ? '/' . ltrim((string) $parts['path'], '/') : '/';
    $blocked_paths = array('/wp-admin', '/wp-login.php', '/wp-json/', '/xmlrpc.php', '/feed/', '/customer-logout', '/lost-password', '/order-pay/', '/order-received/');
    foreach ($blocked_paths as $blocked) {
        if (stripos($path, $blocked) !== false) {
            return '';
        }
    }
    if (preg_match('/\.(?:jpe?g|png|gif|webp|svg|ico|css|js|map|pdf|zip|rar|7z|xml|json|woff2?|ttf|eot|mp4|webm|mp3)(?:$|\?)/i', $url)) {
        return '';
    }
    if (!empty($parts['query'])) {
        parse_str((string) $parts['query'], $query);
        $dangerous = array('add-to-cart', 'remove_item', 'undo_item', 'wc-ajax', 'action', '_wpnonce', 'nonce', 'delete', 'empty-cart', 'download_file', 'key', 'order_id');
        foreach ($dangerous as $name) {
            if (array_key_exists($name, $query)) {
                return '';
            }
        }
        foreach (array_keys($query) as $name) {
            if (strpos((string) $name, 'utm_') === 0 || in_array($name, array('fbclid', 'gclid', 'mc_cid', 'mc_eid'), true)) {
                unset($query[$name]);
            }
        }
        $base = strtok($url, '?');
        $url = empty($query) ? $base : add_query_arg($query, $base);
    }
    return esc_url_raw($url);
}

function seo_core_system_test_internal_resource_url($url) {
    $url = seo_core_system_test_absolute_url((string) $url, home_url('/'));
    if ($url === '') {
        return '';
    }
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    return $host !== '' && $host === $home_host ? esc_url_raw($url) : '';
}

function seo_core_system_test_extract_image_urls($html, $base_url) {
    $urls = array();
    if (preg_match_all('/<(?:img|source)\b[^>]*>/i', (string) $html, $matches)) {
        foreach ($matches[0] as $tag) {
            $attrs = seo_core_system_test_parse_html_attributes($tag);
            foreach (array('src', 'data-src', 'data-lazy-src') as $name) {
                if (!empty($attrs[$name])) {
                    $absolute = seo_core_system_test_absolute_url($attrs[$name], $base_url);
                    if ($absolute !== '') {
                        $urls[$absolute] = true;
                    }
                }
            }
            foreach (array('srcset', 'data-srcset') as $name) {
                if (empty($attrs[$name])) {
                    continue;
                }
                foreach (explode(',', $attrs[$name]) as $candidate) {
                    $candidate = trim((string) preg_replace('/\s+\d+(?:\.\d+)?[wx]\s*$/i', '', trim($candidate)));
                    $absolute = seo_core_system_test_absolute_url($candidate, $base_url);
                    if ($absolute !== '') {
                        $urls[$absolute] = true;
                    }
                }
            }
        }
    }
    return array_keys($urls);
}

function seo_core_system_test_http_trace($url, $max_hops = 6) {
    static $cache = array();
    $url = esc_url_raw((string) $url);
    $max_hops = max(1, (int) $max_hops);
    $cache_key = md5($url . '|' . $max_hops);
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    $current = $url;
    $visited = array();
    $chain = array();
    $result = array(
        'code' => 0,
        'body' => '',
        'content_type' => '',
        'final_url' => $url,
        'redirect_count' => 0,
        'chain' => array(),
        'loop' => false,
        'too_many_redirects' => false,
        'external_redirect' => false,
        'security_challenge' => false,
        'transport_error' => '',
        'budget_exhausted' => false,
        'has_html' => false,
    );

    for ($hop = 0; $hop <= $max_hops; $hop++) {
        if (!seo_core_system_test_link_audit_can_continue()) {
            $result['budget_exhausted'] = true;
            $result['final_url'] = $current;
            break;
        }
        $key = seo_core_system_test_normalize_url_for_compare($current);
        if (isset($visited[$key])) {
            $result['loop'] = true;
            break;
        }
        $visited[$key] = true;

        $arguments = array(
            'timeout' => seo_core_system_test_link_audit_budget_active() ? max(1, min(3, (int) ceil(seo_core_system_test_link_audit_time_remaining()))) : max(2, (int) apply_filters('seo_core_system_test_link_audit_timeout', 6)),
            'redirection' => 0,
            'user-agent' => seo_core_system_test_http_user_agent(),
            'limit_response_size' => 384 * 1024,
            'headers' => array(
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.7',
                'Accept-Language' => 'es-ES,es;q=0.9,en;q=0.7',
                'Cache-Control' => 'no-cache',
                'Referer' => home_url('/'),
            ),
        );
        $cookies = seo_core_system_test_http_security_cookies($current);
        if (!empty($cookies)) {
            $arguments['cookies'] = $cookies;
        }
        $arguments = apply_filters('seo_core_system_test_link_audit_http_args', $arguments, $current);
        $response = wp_remote_get($current, $arguments);
        if (is_wp_error($response)) {
            $result['transport_error'] = $response->get_error_message();
            $result['final_url'] = $current;
            break;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $content_type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        $location = trim((string) wp_remote_retrieve_header($response, 'location'));
        $challenge = seo_core_system_test_detect_security_challenge($body, $response);
        $chain[] = array('url' => $current, 'code' => $code, 'location' => $location);

        $result['code'] = $code;
        $result['body'] = $body;
        $result['content_type'] = $content_type;
        $result['final_url'] = $current;
        $result['security_challenge'] = !empty($challenge['detected']);
        $result['has_html'] = $body !== '' && (strpos($content_type, 'text/html') !== false || stripos($body, '<html') !== false || stripos($body, '<!doctype html') !== false);

        if ($result['security_challenge'] || !in_array($code, array(301, 302, 303, 307, 308), true) || $location === '') {
            break;
        }

        $next = seo_core_system_test_absolute_url($location, $current);
        if ($next === '') {
            break;
        }
        $next_host = strtolower((string) wp_parse_url($next, PHP_URL_HOST));
        if ($next_host !== $home_host) {
            $result['external_redirect'] = true;
            $result['final_url'] = $next;
            break;
        }
        $result['redirect_count']++;
        $current = $next;
        if ($hop === $max_hops) {
            $result['too_many_redirects'] = true;
        }
    }

    $result['chain'] = $chain;
    $cache[$cache_key] = $result;
    return $result;
}

function seo_core_system_test_trace_has_usable_html($trace) {
    return empty($trace['transport_error'])
        && empty($trace['security_challenge'])
        && empty($trace['loop'])
        && (int) $trace['code'] >= 200
        && (int) $trace['code'] < 400
        && !empty($trace['has_html']);
}

function seo_core_system_test_trace_is_broken($trace) {
    $code = (int) $trace['code'];
    if (!empty($trace['transport_error']) || !empty($trace['security_challenge']) || !empty($trace['external_redirect'])) {
        return false;
    }
    if (in_array($code, array(401, 403, 429), true)) {
        return false;
    }
    return $code === 0 || $code === 404 || $code === 410 || $code >= 500 || ($code >= 400 && $code < 500);
}

function seo_core_system_test_trace_is_soft_404($trace, $original_url) {
    if (!seo_core_system_test_trace_has_usable_html($trace)) {
        return false;
    }
    $home = seo_core_system_test_normalize_url_for_compare(home_url('/'));
    $original = seo_core_system_test_normalize_url_for_compare($original_url);
    $final = seo_core_system_test_normalize_url_for_compare($trace['final_url']);
    if (!empty($trace['redirect_count']) && $original !== $home && $final === $home) {
        return true;
    }
    $body = (string) $trace['body'];
    if (preg_match('/<body\b[^>]*class\s*=\s*["\'][^"\']*(?:error404|error-404)[^"\']*["\']/i', $body)) {
        return true;
    }
    $signals = array_merge(seo_core_system_test_html_element_texts($body, 'title'), seo_core_system_test_html_element_texts($body, 'h1'));
    foreach ($signals as $signal) {
        $normalized = strtolower(remove_accents((string) $signal));
        if (preg_match('/\b(?:404|pagina no encontrada|page not found|contenido no encontrado|nothing found|no se ha encontrado)\b/i', $normalized)) {
            return true;
        }
    }
    return false;
}

function seo_core_system_test_trace_status_label($trace) {
    if (!empty($trace['transport_error'])) {
        return 'Transporte';
    }
    if (!empty($trace['security_challenge'])) {
        return 'Antibot';
    }
    if (!empty($trace['loop'])) {
        return 'Bucle';
    }
    if (!empty($trace['too_many_redirects'])) {
        return 'Cadena larga';
    }
    return 'HTTP ' . (int) $trace['code'];
}

function seo_core_system_test_trace_problem_detail($trace) {
    if (!empty($trace['transport_error'])) {
        return 'No se pudo completar la solicitud: ' . $trace['transport_error'];
    }
    if (!empty($trace['security_challenge'])) {
        return 'La protección perimetral o antibot impidió una comprobación concluyente.';
    }
    if (!empty($trace['loop'])) {
        return 'La cadena vuelve a una URL ya visitada. ' . seo_core_system_test_trace_chain_detail($trace);
    }
    if (!empty($trace['too_many_redirects'])) {
        return 'La cadena supera el máximo de saltos. ' . seo_core_system_test_trace_chain_detail($trace);
    }
    if (!empty($trace['external_redirect'])) {
        return 'La URL redirige fuera del dominio hacia ' . $trace['final_url'] . '.';
    }
    return 'Respuesta final ' . seo_core_system_test_trace_status_label($trace) . '. ' . seo_core_system_test_trace_chain_detail($trace);
}

function seo_core_system_test_trace_chain_detail($trace) {
    $steps = array();
    foreach ((array) $trace['chain'] as $step) {
        $part = (int) $step['code'] . ' ' . (string) $step['url'];
        if (!empty($step['location'])) {
            $part .= ' -> ' . (string) $step['location'];
        }
        $steps[] = $part;
    }
    return empty($steps) ? '' : 'Cadena: ' . implode(' | ', $steps) . '.';
}

function seo_core_system_test_sitemap_link_audit($url_limit, $file_limit) {
    $result = array('sitemaps' => 0, 'discovered' => 0, 'checked' => 0, 'broken' => array(), 'warnings' => array(), 'skipped' => false);
    $root_url = home_url('/wp-sitemap.xml');
    $root = seo_core_system_test_http_trace($root_url);
    if (!empty($root['transport_error']) || !empty($root['security_challenge'])) {
        $result['warnings'][] = seo_core_system_test_link_issue_item('alta', seo_core_system_test_trace_status_label($root), $root_url, 'Sitemap principal', seo_core_system_test_trace_problem_detail($root));
        return $result;
    }
    if ((int) $root['code'] < 200 || (int) $root['code'] >= 400 || !seo_core_system_test_xml_is_valid($root['body'])) {
        $result['broken'][] = seo_core_system_test_link_issue_item('alta', seo_core_system_test_trace_status_label($root), $root_url, 'Sitemap principal', 'El sitemap principal no responde con XML válido.');
        return $result;
    }

    $result['sitemaps'] = 1;
    $locations = seo_core_system_test_sitemap_locations($root['body']);
    $is_index = stripos((string) $root['body'], '<sitemapindex') !== false;
    $page_urls = array();

    if ($is_index) {
        usort($locations, static function ($left, $right) {
            $score = static function ($url) {
                if (stripos($url, 'product') !== false) {
                    return 0;
                }
                if (stripos($url, 'page') !== false) {
                    return 1;
                }
                return 2;
            };
            $left_score = $score($left);
            $right_score = $score($right);
            return $left_score === $right_score ? strnatcasecmp($left, $right) : $left_score <=> $right_score;
        });
        foreach (array_slice($locations, 0, $file_limit) as $sitemap_url) {
            if (!seo_core_system_test_link_audit_can_continue()) {
                break;
            }
            $trace = seo_core_system_test_http_trace($sitemap_url);
            if (!empty($trace['transport_error']) || !empty($trace['security_challenge'])) {
                $result['warnings'][] = seo_core_system_test_link_issue_item('media', seo_core_system_test_trace_status_label($trace), $sitemap_url, 'Índice de sitemap', seo_core_system_test_trace_problem_detail($trace));
                continue;
            }
            if ((int) $trace['code'] < 200 || (int) $trace['code'] >= 400 || !seo_core_system_test_xml_is_valid($trace['body'])) {
                $result['broken'][] = seo_core_system_test_link_issue_item('alta', seo_core_system_test_trace_status_label($trace), $sitemap_url, 'Índice de sitemap', 'Sub-sitemap no accesible o XML inválido.');
                continue;
            }
            $result['sitemaps']++;
            foreach (seo_core_system_test_sitemap_locations($trace['body']) as $page_url) {
                $safe = seo_core_system_test_crawlable_internal_url($page_url);
                if ($safe !== '') {
                    $page_urls[seo_core_system_test_normalize_url_for_compare($safe)] = $safe;
                }
                if (count($page_urls) >= $url_limit) {
                    break 2;
                }
            }
        }
    } else {
        foreach ($locations as $page_url) {
            $safe = seo_core_system_test_crawlable_internal_url($page_url);
            if ($safe !== '') {
                $page_urls[seo_core_system_test_normalize_url_for_compare($safe)] = $safe;
            }
            if (count($page_urls) >= $url_limit) {
                break;
            }
        }
    }

    $result['discovered'] = count($page_urls);
    foreach (array_values($page_urls) as $page_url) {
        if (!seo_core_system_test_link_audit_can_continue()) {
            break;
        }
        $result['checked']++;
        $trace = seo_core_system_test_http_trace($page_url);
        if (!empty($trace['transport_error']) || !empty($trace['security_challenge']) || in_array((int) $trace['code'], array(401, 403, 429), true)) {
            $result['warnings'][] = seo_core_system_test_link_issue_item('media', seo_core_system_test_trace_status_label($trace), $page_url, 'Sitemap', seo_core_system_test_trace_problem_detail($trace));
        } elseif (seo_core_system_test_trace_is_broken($trace) || !empty($trace['loop'])) {
            $result['broken'][] = seo_core_system_test_link_issue_item('alta', seo_core_system_test_trace_status_label($trace), $page_url, 'Sitemap', seo_core_system_test_trace_problem_detail($trace));
        } elseif (!empty($trace['redirect_count']) || seo_core_system_test_trace_is_soft_404($trace, $page_url)) {
            $result['warnings'][] = seo_core_system_test_link_issue_item('media', !empty($trace['redirect_count']) ? 'Redirección' : 'Soft-404', $page_url, 'Sitemap', !empty($trace['redirect_count']) ? seo_core_system_test_trace_chain_detail($trace) : 'La URL del sitemap muestra señales de soft-404.');
        }
    }

    $result['broken'] = seo_core_system_test_unique_link_items($result['broken']);
    $result['warnings'] = seo_core_system_test_unique_link_items($result['warnings']);
    return $result;
}

function seo_core_system_test_sitemap_locations($xml) {
    $urls = array();
    if (preg_match_all('/<loc>\s*(.*?)\s*<\/loc>/is', (string) $xml, $matches)) {
        foreach ($matches[1] as $url) {
            $url = trim(html_entity_decode(wp_strip_all_tags((string) $url), ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8'));
            if ($url !== '') {
                $urls[] = esc_url_raw($url);
            }
        }
    }
    return array_values(array_unique($urls));
}

function seo_core_system_test_registered_redirect_audit($target_limit) {
    global $wpdb;
    $result = array('missing_table' => false, 'records' => 0, 'checked' => 0, 'broken' => array(), 'loops' => array(), 'invalid' => array(), 'warnings' => array(), 'skipped' => false);
    $table = $wpdb->prefix . 'seo_redirects';
    if (!seo_core_system_test_table_exists($table)) {
        $result['missing_table'] = true;
        return $result;
    }

    $row_limit = max(500, $target_limit * 10);
    $rows = $wpdb->get_results("SELECT origin_url, target_url, status_code FROM {$table} ORDER BY id DESC LIMIT {$row_limit}", ARRAY_A);
    $result['records'] = count((array) $rows);
    $map = array();
    $targets = array();

    foreach ((array) $rows as $row) {
        $origin = trim((string) (isset($row['origin_url']) ? $row['origin_url'] : ''));
        $target = trim((string) (isset($row['target_url']) ? $row['target_url'] : ''));
        $status = (int) (isset($row['status_code']) ? $row['status_code'] : 0);
        $origin_key = seo_core_system_test_redirect_url_key($origin);
        $target_key = seo_core_system_test_redirect_url_key($target);
        if ($origin_key === '' || $target_key === '') {
            $result['invalid'][] = seo_core_system_test_link_issue_item('alta', 'Configuración', $origin ?: '(origen vacío)', 'Tabla seo_redirects', 'Origen o destino vacío.');
            continue;
        }
        if (!in_array($status, array(301, 302, 307, 308), true)) {
            $result['invalid'][] = seo_core_system_test_link_issue_item('media', 'HTTP ' . $status, $origin, 'Tabla seo_redirects', 'Código de redirección no recomendado o inválido.');
        }
        if ($origin_key === $target_key) {
            $result['loops'][] = seo_core_system_test_link_issue_item('critica', 'Bucle', $origin, 'Tabla seo_redirects', 'El origen y el destino son la misma URL.');
        }
        $map[$origin_key] = $target_key;
        $absolute_target = seo_core_system_test_absolute_url($target, home_url('/'));
        if ($absolute_target !== '' && strtolower((string) wp_parse_url($absolute_target, PHP_URL_HOST)) === strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST))) {
            $targets[seo_core_system_test_normalize_url_for_compare($absolute_target)] = array('url' => $absolute_target, 'origin' => $origin);
        }
    }

    foreach (seo_core_system_test_redirect_cycles($map) as $cycle) {
        $result['loops'][] = seo_core_system_test_link_issue_item('critica', 'Ciclo', $cycle, 'Tabla seo_redirects', 'Varias reglas forman un ciclo de redirección.');
    }

    foreach (array_slice(array_values($targets), 0, $target_limit) as $target) {
        if (!seo_core_system_test_link_audit_can_continue()) {
            break;
        }
        $result['checked']++;
        $trace = seo_core_system_test_http_trace($target['url']);
        if (!empty($trace['transport_error']) || !empty($trace['security_challenge']) || in_array((int) $trace['code'], array(401, 403, 429), true)) {
            $result['warnings'][] = seo_core_system_test_link_issue_item('media', seo_core_system_test_trace_status_label($trace), $target['url'], 'Redirección desde ' . $target['origin'], seo_core_system_test_trace_problem_detail($trace));
        } elseif (seo_core_system_test_trace_is_broken($trace) || !empty($trace['loop'])) {
            $result['broken'][] = seo_core_system_test_link_issue_item('alta', seo_core_system_test_trace_status_label($trace), $target['url'], 'Redirección desde ' . $target['origin'], seo_core_system_test_trace_problem_detail($trace));
        } elseif (!empty($trace['redirect_count'])) {
            $result['warnings'][] = seo_core_system_test_link_issue_item('media', 'Cadena', $target['url'], 'Redirección desde ' . $target['origin'], seo_core_system_test_trace_chain_detail($trace));
        }
    }

    foreach (array('broken', 'loops', 'invalid', 'warnings') as $name) {
        $result[$name] = seo_core_system_test_unique_link_items($result[$name]);
    }
    return $result;
}

function seo_core_system_test_resource_map_audit($map, $limit, $kind) {
    $entries = seo_core_system_test_sort_link_map($map);
    $result = array('discovered' => count($entries), 'checked' => 0, 'broken' => array(), 'warnings' => array(), 'skipped' => false);
    foreach (array_slice($entries, 0, $limit) as $entry) {
        if (!seo_core_system_test_link_audit_can_continue()) {
            break;
        }
        $result['checked']++;
        $accept = $kind === 'image' ? 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.5' : 'text/css,application/javascript,text/javascript,*/*;q=0.5';
        $probe = seo_core_system_test_resource_probe($entry['url'], $accept, 32768);
        $origin = implode(' | ', array_slice($entry['origins'], 0, 4));
        $priority = seo_core_system_test_priority_label($entry['priority']);
        if (!empty($probe['transport_error']) || !empty($probe['security_challenge']) || in_array((int) $probe['code'], array(401, 403, 429), true)) {
            $result['warnings'][] = seo_core_system_test_link_issue_item($priority, !empty($probe['security_challenge']) ? 'Antibot' : 'No concluyente', $entry['url'], $origin, !empty($probe['transport_error']) ? $probe['transport_error'] : 'La respuesta no pudo comprobarse de forma concluyente.');
            continue;
        }
        if ((int) $probe['code'] === 404 || (int) $probe['code'] === 410 || (int) $probe['code'] >= 500 || (int) $probe['code'] === 0) {
            $result['broken'][] = seo_core_system_test_link_issue_item($priority, 'HTTP ' . (int) $probe['code'], $entry['url'], $origin, ucfirst($kind) . ' no accesible.');
            continue;
        }
        if ((int) $probe['code'] >= 400) {
            $result['warnings'][] = seo_core_system_test_link_issue_item($priority, 'HTTP ' . (int) $probe['code'], $entry['url'], $origin, ucfirst($kind) . ' devuelve una respuesta restringida o inesperada.');
            continue;
        }
        $content_type = isset($probe['content_type']) ? (string) $probe['content_type'] : '';
        if ($kind === 'image' && $content_type !== '' && strpos($content_type, 'image/') === false) {
            $result['warnings'][] = seo_core_system_test_link_issue_item($priority, 'Tipo MIME', $entry['url'], $origin, 'La URL responde, pero su Content-Type no es de imagen: ' . $content_type . '.');
        }
    }
    $result['broken'] = seo_core_system_test_unique_link_items($result['broken']);
    $result['warnings'] = seo_core_system_test_unique_link_items($result['warnings']);
    return $result;
}

function seo_core_system_test_link_issue_item($priority, $status, $url, $origin, $detail) {
    return array(
        'priority' => is_numeric($priority) ? seo_core_system_test_priority_label((int) $priority) : (string) $priority,
        'status' => (string) $status,
        'url' => (string) $url,
        'origin' => (string) $origin,
        'detail' => trim((string) $detail),
    );
}

function seo_core_system_test_priority_label($score) {
    $score = (int) $score;
    if ($score >= 4) {
        return 'critica';
    }
    if ($score === 3) {
        return 'alta';
    }
    if ($score === 2) {
        return 'media';
    }
    return 'baja';
}

function seo_core_system_test_unique_link_items($items) {
    $unique = array();
    foreach ((array) $items as $item) {
        $key = md5(strtolower((string) (isset($item['status']) ? $item['status'] : '')) . '|' . seo_core_system_test_normalize_url_for_compare(isset($item['url']) ? $item['url'] : '') . '|' . (string) (isset($item['origin']) ? $item['origin'] : ''));
        $unique[$key] = $item;
    }
    return array_values($unique);
}

function seo_core_system_test_link_items_inline($items, $limit = 4) {
    $parts = array();
    foreach (array_slice((array) $items, 0, max(1, (int) $limit)) as $item) {
        $parts[] = ucfirst((string) $item['priority']) . ': ' . (string) $item['status'] . ' ' . (string) $item['url'] . ' (origen: ' . (string) $item['origin'] . ')';
    }
    return implode(' | ', $parts);
}

function seo_core_system_test_functional_summary($results, $limit = 0) {
    $critical = 0; $warnings = 0; $blocked = 0; $inspected = 0;
    foreach ($results as $result) {
        if (!isset($result['group']) || $result['group'] !== 'functional') continue;
        if ($limit > 0 && $inspected >= $limit) break;
        if (isset($result['label']) && strpos((string) $result['label'], '8.13 ') === 0) continue;
        $inspected++;
        if (($result['status'] ?? '') === 'not_evaluable') { $blocked++; continue; }
        if ($result['severity'] === 'ko') $critical++;
        elseif ($result['severity'] === 'warning') $warnings++;
    }
    if ($critical > 0) return array('passed' => false, 'severity' => 'ko', 'detail' => 'Recorrido incompleto. Fallos criticos: ' . number_format_i18n($critical) . '; avisos: ' . number_format_i18n($warnings) . '; no evaluables: ' . number_format_i18n($blocked) . '.');
    if ($warnings > 0) return array('passed' => false, 'severity' => 'warning', 'detail' => 'No hay fallos criticos, pero existen ' . number_format_i18n($warnings) . ' comprobaciones mejorables y ' . number_format_i18n($blocked) . ' no evaluables.');
    if ($blocked > 0) return array('passed' => true, 'severity' => 'info', 'detail' => 'Las comprobaciones ejecutadas son correctas, pero ' . number_format_i18n($blocked) . ' quedaron no evaluables por dependencias externas.');
    return array('passed' => true, 'severity' => 'ok', 'detail' => 'Todas las comprobaciones funcionales incluidas en este resumen han finalizado correctamente.');
}


function seo_core_system_test_get_representative_category_for_product($product) {
    if (!empty($product['id']) && taxonomy_exists('product_cat')) {
        $terms = wp_get_post_terms((int) $product['id'], 'product_cat', array('orderby' => 'term_order', 'order' => 'ASC'));
        if (!is_wp_error($terms) && !empty($terms)) {
            $term = reset($terms);
            $url = get_term_link($term);
            if (!is_wp_error($url)) {
                return array(
                    'id' => (int) $term->term_id,
                    'name' => (string) $term->name,
                    'url' => (string) $url,
                );
            }
        }
    }

    return seo_core_system_test_get_representative_category();
}

function seo_core_system_test_404_check($enabled) {
    if (!$enabled) {
        return seo_core_system_test_check_info('Comprobacion HTTP desactivada.');
    }

    $path = '/seo-system-test-missing-' . substr(md5(home_url('/') . wp_salt('auth')), 0, 12) . '/';
    $url = home_url($path);
    $probe = seo_core_system_test_http_probe($url);
    $unavailable = seo_core_system_test_probe_unavailable_check($probe, $url);
    if ($unavailable !== null) {
        return $unavailable;
    }

    if ((int) $probe['code'] === 404 || (int) $probe['code'] === 410) {
        if (empty($probe['has_html'])) {
            return seo_core_system_test_check_warning('La URL inexistente devuelve HTTP ' . (int) $probe['code'] . ', pero no se detecta una plantilla HTML de error. URL: ' . $url);
        }
        return seo_core_system_test_check_ok('La URL inexistente devuelve HTTP ' . (int) $probe['code'] . ' y una respuesta HTML. URL: ' . $url);
    }

    if ((int) $probe['code'] >= 500) {
        return seo_core_system_test_check_ko('La URL inexistente provoca HTTP ' . (int) $probe['code'] . ' en lugar de una pagina 404. URL: ' . $url);
    }

    return seo_core_system_test_check_warning('La URL inexistente devuelve HTTP ' . (int) $probe['code'] . ' en lugar de 404/410. Puede existir una redireccion a portada o un soft-404. URL: ' . $url);
}

function seo_core_system_test_empty_search_check($enabled) {
    $term = 'zzseoempty' . substr(md5(home_url('/') . wp_salt('nonce')), 0, 14);
    $query = new WP_Query(array(
        'post_type' => 'product',
        'post_status' => 'publish',
        's' => $term,
        'posts_per_page' => 1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'ignore_sticky_posts' => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ));
    $local_count = count((array) $query->posts);
    wp_reset_postdata();

    if ($local_count > 0) {
        return seo_core_system_test_check_warning('El termino sintetico destinado a no obtener resultados ha localizado ' . number_format_i18n($local_count) . ' productos. No se puede validar el estado vacio con fiabilidad.');
    }

    if (!$enabled) {
        return seo_core_system_test_check_info('La consulta interna no devuelve productos. La comprobacion HTTP esta desactivada.');
    }

    $url = add_query_arg(array('s' => $term, 'post_type' => 'product'), home_url('/'));
    $probe = seo_core_system_test_http_probe($url);
    $unavailable = seo_core_system_test_probe_unavailable_check($probe, $url);
    if ($unavailable !== null) {
        return $unavailable;
    }

    if ((int) $probe['code'] < 200 || (int) $probe['code'] >= 400 || empty($probe['has_html'])) {
        return seo_core_system_test_check_ko('La busqueda sin resultados no devuelve una pagina HTML valida. HTTP ' . (int) $probe['code'] . '. URL: ' . $url);
    }

    $body_lower = strtolower(wp_strip_all_tags((string) $probe['body']));
    $markers = array(
        'no se han encontrado',
        'sin resultados',
        'no hay resultados',
        'no products were found',
        'no results found',
        'woocommerce-info',
    );
    foreach ($markers as $marker) {
        if (strpos($body_lower, $marker) !== false) {
            return seo_core_system_test_check_ok('La consulta interna devuelve cero productos y la pagina publica muestra un estado sin resultados. HTTP ' . (int) $probe['code'] . '.');
        }
    }

    return seo_core_system_test_check_warning('La consulta interna devuelve cero productos y la pagina responde correctamente, pero no se ha reconocido un mensaje explicito de estado vacio. URL: ' . $url);
}

function seo_core_system_test_html_titles_check($urls, $enabled) {
    if (!$enabled) {
        return seo_core_system_test_check_info('Comprobacion HTTP desactivada.');
    }

    $required = array('portada', 'tienda', 'producto');
    $missing = array();
    $duplicates = array();
    $checked = 0;
    $blocked = array();

    foreach (array('portada', 'tienda', 'categoria', 'producto') as $name) {
        $url = isset($urls[$name]) ? $urls[$name] : '';
        if ($url === '') {
            if (in_array($name, $required, true)) {
                $missing[] = $name . ' (URL no resuelta)';
            }
            continue;
        }
        $probe = seo_core_system_test_http_probe($url);
        if (!seo_core_system_test_probe_is_usable_html($probe)) {
            $blocked[$name] = seo_core_system_test_probe_blocker($probe);
            continue;
        }
        $checked++;
        $titles = seo_core_system_test_html_element_texts($probe['body'], 'title');
        $valid_titles = array_values(array_filter(array_map('trim', $titles), 'strlen'));
        if (empty($valid_titles)) {
            $missing[] = $name;
        } elseif (count($valid_titles) > 1) {
            $duplicates[] = $name . ' (' . count($valid_titles) . ')';
        }
    }

    if ($checked === 0 && empty($missing)) {
        return seo_core_system_test_check_not_evaluable('No se recibió ninguna página con HTTP 200 y HTML válido para comprobar títulos.', 'HTTP_HTML_UNAVAILABLE', array('evidence' => $blocked));
    }
    if (!empty($missing)) {
        return seo_core_system_test_check_ko('Falta un titulo HTML utilizable en: ' . implode(', ', $missing) . '. Paginas inspeccionadas: ' . number_format_i18n($checked) . '.');
    }
    if (!empty($duplicates) || !empty($blocked)) {
        $parts = array();
        if (!empty($duplicates)) {
            $parts[] = 'titulos multiples: ' . implode(', ', $duplicates);
        }
        if (!empty($blocked)) {
            $parts[] = 'no evaluables: ' . implode(', ', array_keys($blocked));
        }
        return seo_core_system_test_check_warning('Los titulos evaluados existen, pero hay incidencias: ' . implode('; ', $parts) . '.', array('coverage' => (int) round(($checked / max(1, $checked + count($blocked))) * 100), 'evidence' => $blocked));
    }

    return seo_core_system_test_check_ok('Portada, tienda, categoria y producto contienen un unico titulo HTML no vacio.');
}


function seo_core_system_test_canonical_check($urls, $enabled) {
    if (!$enabled) {
        return seo_core_system_test_check_info('Comprobacion HTTP desactivada.');
    }

    $targets = array('portada', 'producto');
    $require_category = !function_exists('seo_core_validation_get_setting')
        || (bool) seo_core_validation_get_setting('require_canonical_category', 1);
    if ($require_category) {
        $targets[] = 'categoria';
    }

    $missing = array();
    $multiple = array();
    $mismatch = array();
    $checked = 0;
    $blocked = array();
    $evidence = array();

    foreach ($targets as $name) {
        $url = isset($urls[$name]) ? $urls[$name] : '';
        if ($url === '') {
            continue;
        }
        $probe = seo_core_system_test_http_probe($url);
        if (!seo_core_system_test_probe_is_usable_html($probe)) {
            $blocked[$name] = seo_core_system_test_probe_blocker($probe);
            continue;
        }
        $checked++;
        $canonicals = seo_core_system_test_extract_link_rel_urls($probe['body'], 'canonical', $url);
        $evidence[$name] = array('url' => $url, 'canonicals' => $canonicals);
        if (empty($canonicals)) {
            $missing[] = $name;
            continue;
        }
        if (count($canonicals) > 1) {
            $multiple[] = $name . ' (' . count($canonicals) . ')';
        }
        if (seo_core_system_test_normalize_url_for_compare($canonicals[0]) !== seo_core_system_test_normalize_url_for_compare($url)) {
            $mismatch[] = $name . ' -> ' . $canonicals[0];
        }
    }

    $meta = array(
        'owner' => 'SEO',
        'coverage' => (int) round(($checked / max(1, $checked + count($blocked))) * 100),
        'evidence' => array('pages' => $evidence, 'blocked' => $blocked, 'category_required' => $require_category),
        'confidence' => 98,
    );
    if ($checked === 0) {
        return seo_core_system_test_check_not_evaluable('No se recibió ninguna página con HTTP 200 y HTML válido para comprobar canonical.', 'HTTP_HTML_UNAVAILABLE', $meta);
    }
    if (!empty($multiple) || !empty($mismatch) || !empty($missing)) {
        $parts = array();
        if (!empty($missing)) $parts[] = 'ausente en ' . implode(', ', $missing);
        if (!empty($multiple)) $parts[] = 'múltiple en ' . implode(', ', $multiple);
        if (!empty($mismatch)) $parts[] = 'no coincide en ' . implode(' | ', $mismatch);
        return seo_core_system_test_check_warning('Canonical mejorable: ' . implode('; ', $parts) . '.', $meta);
    }
    if (!empty($blocked)) {
        return seo_core_system_test_check_warning('Las páginas evaluadas contienen canonical coherente, pero quedaron páginas no evaluables: ' . implode(', ', array_keys($blocked)) . '.', $meta);
    }

    return seo_core_system_test_check_ok('Las páginas configuradas contienen una sola canonical coherente con su URL pública.', $meta);
}

function seo_core_system_test_robots_check($urls, $enabled) {
    if ((string) get_option('blog_public', '1') === '0') {
        return seo_core_system_test_check_warning('WordPress tiene activada la opcion de disuadir a los motores de busqueda. La politica robots publica no puede considerarse correcta para produccion.', array('owner' => 'SEO'));
    }
    if (!$enabled) {
        return seo_core_system_test_check_info('Comprobacion HTTP desactivada.');
    }

    $issues_ko = array();
    $issues_warning = array();
    $blocked = array();
    $checked = 0;
    $expect_index = array('producto', 'categoria');
    $expect_noindex = array('busqueda', 'carrito', 'checkout');

    foreach (array_merge($expect_index, $expect_noindex) as $name) {
        $url = isset($urls[$name]) ? $urls[$name] : '';
        if ($url === '') continue;
        $probe = seo_core_system_test_http_probe($url);
        if (!seo_core_system_test_probe_is_usable_html($probe)) {
            $blocked[$name] = seo_core_system_test_probe_blocker($probe);
            continue;
        }
        $checked++;
        $directives = seo_core_system_test_extract_meta_content($probe['body'], 'robots');
        $combined = strtolower(implode(',', $directives));
        $has_noindex = strpos($combined, 'noindex') !== false;
        if (in_array($name, $expect_index, true) && $has_noindex) {
            if ($name === 'producto') $issues_ko[] = 'producto marcado noindex';
            else $issues_warning[] = 'categoria marcada noindex';
        }
        if (in_array($name, $expect_noindex, true) && !$has_noindex) $issues_warning[] = $name . ' sin noindex explicito';
        if (count($directives) > 1) $issues_warning[] = $name . ' contiene varias etiquetas robots';
    }

    if ($checked === 0) {
        return seo_core_system_test_check_not_evaluable('No se recibió ninguna página con HTTP 200 y HTML válido para comprobar meta robots.', 'HTTP_HTML_UNAVAILABLE', array('evidence' => $blocked));
    }
    if (!empty($issues_ko)) return seo_core_system_test_check_ko('Politica robots critica: ' . implode('; ', $issues_ko) . '.', array('owner' => 'SEO'));
    if (!empty($issues_warning) || !empty($blocked)) {
        if (!empty($blocked)) $issues_warning[] = 'páginas no evaluables: ' . implode(', ', array_keys($blocked));
        return seo_core_system_test_check_warning('Politica robots mejorable: ' . implode('; ', $issues_warning) . '.', array('owner' => 'SEO', 'coverage' => (int) round(($checked / max(1, $checked + count($blocked))) * 100), 'evidence' => $blocked));
    }
    return seo_core_system_test_check_ok('Producto y categoria son indexables; busqueda, carrito y checkout declaran noindex.', array('owner' => 'SEO'));
}


function seo_core_system_test_h1_check($urls, $product, $category, $enabled) {
    if (!$enabled) return seo_core_system_test_check_info('Comprobacion HTTP desactivada.');

    $threshold = function_exists('seo_core_validation_get_setting')
        ? (int) seo_core_validation_get_setting('h1_match_percent', 60)
        : 60;
    $targets = array(
        'producto' => array('url' => isset($urls['producto']) ? $urls['producto'] : '', 'expected' => isset($product['title']) ? $product['title'] : '', 'required' => true),
        'categoria' => array('url' => isset($urls['categoria']) ? $urls['categoria'] : '', 'expected' => isset($category['name']) ? $category['name'] : '', 'required' => false),
    );
    $missing = array();
    $multiple = array();
    $content_mismatch = array();
    $blocked = array();
    $checked = 0;
    $evidence = array();

    foreach ($targets as $name => $target) {
        if ($target['url'] === '') {
            if ($target['required']) $missing[] = $name . ' (URL no resuelta)';
            continue;
        }
        $probe = seo_core_system_test_http_probe($target['url']);
        if (!seo_core_system_test_probe_is_usable_html($probe)) {
            $blocked[$name] = seo_core_system_test_probe_blocker($probe);
            continue;
        }
        $checked++;
        $headings = seo_core_system_test_html_element_texts($probe['body'], 'h1');
        $entry = array('url' => $target['url'], 'expected' => $target['expected'], 'headings' => $headings);
        if (empty($headings)) {
            $missing[] = $name;
            $evidence[$name] = $entry;
            continue;
        }
        if (count($headings) > 1) $multiple[] = $name . ' (' . count($headings) . ')';
        $analysis = seo_core_system_test_text_match_analysis(implode(' ', $headings), $target['expected'], $threshold);
        $entry['match'] = $analysis;
        $evidence[$name] = $entry;
        if ($target['expected'] !== '' && empty($analysis['passed'])) {
            $content_mismatch[] = $name . ' (' . (int) $analysis['percent'] . '%)';
        }
    }

    $meta = array(
        'owner' => 'SEO',
        'coverage' => (int) round(($checked / max(1, $checked + count($blocked))) * 100),
        'evidence' => array('pages' => $evidence, 'blocked' => $blocked, 'threshold' => $threshold),
        'confidence' => 95,
    );
    if ($checked === 0 && empty($missing)) {
        return seo_core_system_test_check_not_evaluable('No se recibió ninguna página con HTTP 200 y HTML válido para comprobar H1.', 'HTTP_HTML_UNAVAILABLE', $meta);
    }
    if (!empty($missing)) return seo_core_system_test_check_ko('Falta H1 en: ' . implode(', ', $missing) . '.', $meta);
    if (!empty($multiple) || !empty($content_mismatch) || !empty($blocked)) {
        $parts = array();
        if (!empty($multiple)) $parts[] = 'H1 múltiples: ' . implode(', ', $multiple);
        if (!empty($content_mismatch)) $parts[] = 'H1 por debajo del ' . $threshold . '% esperado: ' . implode(', ', $content_mismatch);
        if (!empty($blocked)) $parts[] = 'no evaluables: ' . implode(', ', array_keys($blocked));
        return seo_core_system_test_check_warning(implode('; ', $parts) . '.', $meta);
    }
    return seo_core_system_test_check_ok('Producto y categoría contienen un único H1 relacionado con el contenido representativo. Umbral: ' . $threshold . '%.', $meta);
}

function seo_core_system_test_structured_data_check($urls, $enabled) {
    if (!$enabled) return seo_core_system_test_check_info('Comprobacion HTTP desactivada.');

    $invalid = 0;
    $blocks = 0;
    $types = array();
    $types_by_page = array();
    $checked = 0;
    $blocked = array();
    foreach (array('portada', 'categoria', 'producto') as $name) {
        $url = isset($urls[$name]) ? $urls[$name] : '';
        if ($url === '') continue;
        $probe = seo_core_system_test_http_probe($url);
        if (!seo_core_system_test_probe_is_usable_html($probe)) {
            $blocked[$name] = seo_core_system_test_probe_blocker($probe);
            continue;
        }
        $checked++;
        $parsed = seo_core_system_test_extract_json_ld($probe['body']);
        $blocks += $parsed['blocks'];
        $invalid += $parsed['invalid'];
        $types_by_page[$name] = array_values(array_unique((array) $parsed['types']));
        foreach ($parsed['types'] as $type) $types[$type] = true;
    }

    $meta = array(
        'owner' => 'SEO',
        'coverage' => (int) round(($checked / max(1, $checked + count($blocked))) * 100),
        'evidence' => array('types_by_page' => $types_by_page, 'blocked' => $blocked, 'blocks' => $blocks, 'invalid' => $invalid),
        'confidence' => 96,
    );
    if ($checked === 0) {
        return seo_core_system_test_check_not_evaluable('No se recibió ninguna página con HTTP 200 y HTML válido para comprobar JSON-LD.', 'HTTP_HTML_UNAVAILABLE', $meta);
    }
    if ($invalid > 0) return seo_core_system_test_check_ko('Se han detectado ' . number_format_i18n($invalid) . ' bloques JSON-LD que no son JSON válido. Bloques inspeccionados: ' . number_format_i18n($blocks) . '.', $meta);
    if ($blocks === 0) return seo_core_system_test_check_warning('No se han localizado bloques JSON-LD en las páginas que sí pudieron evaluarse.', $meta);

    $require_product = !function_exists('seo_core_validation_get_setting') || (bool) seo_core_validation_get_setting('require_schema_product', 1);
    $require_breadcrumb = !function_exists('seo_core_validation_get_setting') || (bool) seo_core_validation_get_setting('require_schema_breadcrumb', 1);
    $require_site = !function_exists('seo_core_validation_get_setting') || (bool) seo_core_validation_get_setting('require_schema_site', 1);
    $missing = array();
    if ($require_product && !in_array('Product', $types_by_page['producto'] ?? array(), true)) {
        $missing[] = 'Product en producto';
    }
    if ($require_breadcrumb && !isset($types['BreadcrumbList'])) {
        $missing[] = 'BreadcrumbList';
    }
    $home_types = $types_by_page['portada'] ?? array();
    if ($require_site && !in_array('Organization', $home_types, true) && !in_array('WebSite', $home_types, true)) {
        $missing[] = 'Organization/WebSite en portada';
    }
    $meta['evidence']['requirements'] = array(
        'product' => $require_product,
        'breadcrumb' => $require_breadcrumb,
        'site' => $require_site,
    );

    if (!empty($missing)) {
        $page_parts = array();
        foreach ($types_by_page as $page => $page_types) {
            $page_parts[] = $page . ': ' . (empty($page_types) ? 'ninguno' : implode(', ', $page_types));
        }
        return seo_core_system_test_check_warning('JSON-LD válido, pero faltan tipos configurados: ' . implode(', ', $missing) . '. Tipos por página: ' . implode(' | ', $page_parts) . '.', $meta);
    }
    if (!empty($blocked)) return seo_core_system_test_check_warning('JSON-LD correcto en las páginas evaluadas; quedaron páginas no evaluables: ' . implode(', ', array_keys($blocked)) . '.', $meta);
    return seo_core_system_test_check_ok('JSON-LD válido. Bloques: ' . number_format_i18n($blocks) . '; tipos: ' . implode(', ', array_keys($types)) . '.', $meta);
}

function seo_core_system_test_sitemap_check($enabled) {
    if (!$enabled) {
        return seo_core_system_test_check_info('Comprobacion HTTP desactivada.');
    }

    $candidates = apply_filters('seo_core_system_test_sitemap_urls', array(
        home_url('/wp-sitemap.xml'),
        home_url('/sitemap_index.xml'),
        home_url('/sitemap.xml'),
    ));
    $challenges = 0;
    $invalid = array();

    foreach ((array) $candidates as $url) {
        $probe = seo_core_system_test_resource_probe($url, 'application/xml,text/xml;q=0.9,*/*;q=0.5', 768 * 1024);
        if (!empty($probe['security_challenge']) || !empty($probe['transport_error'])) {
            $challenges++;
            continue;
        }
        if ((int) $probe['code'] !== 200) {
            continue;
        }
        $body = trim((string) $probe['body']);
        $looks_xml = strpos($body, '<?xml') === 0 || stripos($body, '<urlset') !== false || stripos($body, '<sitemapindex') !== false;
        if (!$looks_xml) {
            $invalid[] = $url . ' no parece XML';
            continue;
        }
        $xml_valid = seo_core_system_test_xml_is_valid($body);
        if (!$xml_valid) {
            $invalid[] = $url . ' contiene XML no valido';
            continue;
        }
        $loc_count = preg_match_all('/<loc\b[^>]*>.*?<\/loc>/is', $body, $matches);
        if ($loc_count < 1) {
            $invalid[] = $url . ' no contiene URLs';
            continue;
        }
        return seo_core_system_test_check_ok('Sitemap accesible y XML valido. URL: ' . $url . '; entradas visibles: ' . number_format_i18n($loc_count) . '.');
    }

    if (!empty($invalid)) {
        return seo_core_system_test_check_ko('Se ha encontrado un candidato de sitemap incorrecto: ' . implode(' | ', $invalid) . '.');
    }
    if ($challenges > 0) {
        return seo_core_system_test_check_warning('El sitemap no ha podido verificarse por bloqueo perimetral o error de transporte. Candidatos no concluyentes: ' . number_format_i18n($challenges) . '.');
    }

    return seo_core_system_test_check_warning('No se ha localizado un sitemap publico en las rutas habituales.');
}

function seo_core_system_test_product_image_check($product, $enabled) {
    if (empty($product['id']) || !function_exists('wc_get_product')) {
        return seo_core_system_test_check_warning('No hay un producto WooCommerce representativo para comprobar su imagen principal.');
    }

    $wc_product = wc_get_product((int) $product['id']);
    if (!$wc_product) {
        return seo_core_system_test_check_ko('WooCommerce no puede cargar el producto representativo ID ' . (int) $product['id'] . '.');
    }

    $image_id = (int) $wc_product->get_image_id();
    $image_url = $image_id > 0 ? wp_get_attachment_url($image_id) : '';
    if (!$image_url) {
        return seo_core_system_test_check_warning('El producto representativo no tiene una imagen destacada local resoluble. Producto ID: ' . (int) $product['id'] . '.');
    }

    if (!$enabled) {
        return seo_core_system_test_check_info('Imagen principal configurada: ' . $image_url . '. Comprobacion HTTP desactivada.');
    }

    $probe = seo_core_system_test_resource_probe($image_url, 'image/avif,image/webp,image/apng,image/*,*/*;q=0.5', 128 * 1024);
    if (!empty($probe['transport_error']) || !empty($probe['security_challenge'])) {
        return seo_core_system_test_check_warning('La imagen esta configurada, pero no se ha podido verificar por HTTP. URL: ' . $image_url . '.');
    }
    if ((int) $probe['code'] < 200 || (int) $probe['code'] >= 400) {
        return seo_core_system_test_check_ko('La imagen principal devuelve HTTP ' . (int) $probe['code'] . '. URL: ' . $image_url . '.');
    }
    if (strpos(strtolower((string) $probe['content_type']), 'image/') !== 0) {
        return seo_core_system_test_check_warning('La imagen responde con HTTP ' . (int) $probe['code'] . ', pero el Content-Type no es de imagen: ' . $probe['content_type'] . '.');
    }

    return seo_core_system_test_check_ok('Imagen principal accesible. HTTP ' . (int) $probe['code'] . '; tipo: ' . $probe['content_type'] . '; URL: ' . $image_url . '.');
}

function seo_core_system_test_essential_resources_check($urls, $enabled) {
    if (!$enabled) return seo_core_system_test_check_info('Comprobacion HTTP desactivada.');

    $resources = array(); $html_checked = 0; $blocked = array();
    foreach (array('portada', 'producto') as $name) {
        $url = isset($urls[$name]) ? $urls[$name] : '';
        if ($url === '') continue;
        $probe = seo_core_system_test_http_probe($url);
        if (!seo_core_system_test_probe_is_usable_html($probe)) {
            $blocked[$name] = seo_core_system_test_probe_blocker($probe);
            continue;
        }
        $html_checked++;
        foreach (seo_core_system_test_extract_asset_urls($probe['body'], $url) as $asset_url) $resources[$asset_url] = true;
    }
    if ($html_checked === 0) return seo_core_system_test_check_not_evaluable('No se recibió HTML válido para extraer recursos CSS o JavaScript.', 'HTTP_HTML_UNAVAILABLE', array('evidence' => $blocked));

    $maximum = max(1, (int) apply_filters('seo_core_system_test_max_asset_checks', 4));
    $resources = array_slice(array_keys($resources), 0, $maximum);
    if (empty($resources)) return seo_core_system_test_check_warning('Las páginas se evaluaron, pero no se pudieron extraer recursos CSS o JavaScript locales.', array('owner' => 'WP', 'coverage' => 100));

    $broken = array(); $warnings = array(); $ok = 0;
    foreach ($resources as $resource_url) {
        $probe = seo_core_system_test_resource_probe($resource_url, 'text/css,application/javascript,text/javascript,*/*;q=0.5', 96 * 1024);
        if (!empty($probe['security_challenge']) || !empty($probe['transport_error'])) { $warnings[] = $resource_url; continue; }
        if ((int) $probe['code'] < 200 || (int) $probe['code'] >= 400) { $broken[] = 'HTTP ' . (int) $probe['code'] . ' ' . $resource_url; continue; }
        $ok++;
    }
    if (!empty($broken)) return seo_core_system_test_check_ko('Recursos esenciales rotos: ' . implode(' | ', $broken) . '.', array('owner' => 'WP'));
    if (!empty($warnings)) return seo_core_system_test_check_warning('Recursos correctos: ' . number_format_i18n($ok) . '; no concluyentes: ' . number_format_i18n(count($warnings)) . '.', array('owner' => 'WP', 'coverage' => (int) round(($ok / max(1, count($resources))) * 100)));
    return seo_core_system_test_check_ok('Recursos CSS/JS locales comprobados: ' . number_format_i18n($ok) . '; ninguno devuelve error HTTP.', array('owner' => 'WP'));
}


function seo_core_system_test_product_sellability_check($product) {
    if (empty($product['id']) || !function_exists('wc_get_product')) {
        return seo_core_system_test_check_warning('No hay un producto WooCommerce representativo para comprobar precio, stock y compra.', array('owner' => 'Woo'));
    }

    $wc_product = wc_get_product((int) $product['id']);
    if (!$wc_product) {
        return seo_core_system_test_check_ko('WooCommerce no puede cargar el objeto de producto ID ' . (int) $product['id'] . '.', array('owner' => 'Woo'));
    }

    $price = $wc_product->get_price();
    $stock_status = $wc_product->get_stock_status();
    $purchasable = $wc_product->is_purchasable();
    $visible = $wc_product->is_visible();
    $issues = array();
    $evidence = array(
        'product_id' => (int) $product['id'],
        'title' => (string) ($product['title'] ?? ''),
        'selection_reason' => (string) ($product['selection_reason'] ?? ''),
        'price' => $price,
        'stock_status' => $stock_status,
        'visible' => (bool) $visible,
        'purchasable' => (bool) $purchasable,
    );
    $meta = array('owner' => 'Woo', 'area' => 'sellability', 'evidence' => $evidence, 'confidence' => 99);

    if ($price === '' || !is_numeric($price)) {
        $issues[] = 'precio no resoluble';
    }
    if (!in_array($stock_status, array('instock', 'outofstock', 'onbackorder'), true)) {
        $issues[] = 'estado de stock desconocido: ' . $stock_status;
    }

    if (!empty($issues)) {
        return seo_core_system_test_check_ko('Producto no utilizable comercialmente: ' . implode('; ', $issues) . '. ID: ' . (int) $product['id'] . '. Selección: ' . ($product['selection_reason'] ?? 'sin detalle') . '.', $meta);
    }
    if (!$purchasable || !$visible || $stock_status === 'outofstock') {
        return seo_core_system_test_check_warning('Producto ID ' . (int) $product['id'] . ' cargado con precio ' . wp_strip_all_tags(wc_price((float) $price)) . ', stock ' . $stock_status . ', visible: ' . ($visible ? 'sí' : 'no') . ', comprable: ' . ($purchasable ? 'sí' : 'no') . '. Selección: ' . ($product['selection_reason'] ?? 'sin detalle') . '.', $meta);
    }

    return seo_core_system_test_check_ok('Producto comprable y visible. Precio: ' . wp_strip_all_tags(wc_price((float) $price)) . '; stock: ' . $stock_status . '; ID: ' . (int) $product['id'] . '. Selección: ' . ($product['selection_reason'] ?? 'automática') . '.', $meta);
}

function seo_core_system_test_store_readiness_check($product, $urls, $enabled) {
    $evidence = array(
        'woocommerce_loaded' => class_exists('WooCommerce') || function_exists('WC'),
        'shop_page' => !empty($urls['tienda']),
        'cart_page' => !empty($urls['carrito']),
        'checkout_page' => !empty($urls['checkout']),
        'product_id' => (int) ($product['id'] ?? 0),
        'product_selection_reason' => (string) ($product['selection_reason'] ?? ''),
        'enabled_gateways' => array(),
        'http' => array(),
    );
    $critical = array(); $warnings = array();
    if (!$evidence['woocommerce_loaded']) $critical[] = 'WooCommerce no está cargado';
    foreach (array('shop_page' => 'tienda', 'cart_page' => 'carrito', 'checkout_page' => 'checkout') as $key => $label) {
        if (!$evidence[$key]) $critical[] = 'página de ' . $label . ' no resuelta';
    }

    if (!empty($product['id']) && function_exists('wc_get_product')) {
        $wc_product = wc_get_product((int) $product['id']);
        if (!$wc_product) $critical[] = 'producto representativo no cargable';
        else {
            $evidence['product'] = array(
                'status' => $wc_product->get_status(),
                'visible' => $wc_product->is_visible(),
                'purchasable' => $wc_product->is_purchasable(),
                'stock_status' => $wc_product->get_stock_status(),
                'price' => $wc_product->get_price(),
            );
            if (!$wc_product->is_visible()) $warnings[] = 'producto representativo no visible';
            if (!$wc_product->is_purchasable()) $critical[] = 'producto representativo no comprable';
            if ($wc_product->get_price() === '' || !is_numeric($wc_product->get_price())) $critical[] = 'producto sin precio resoluble';
            if ($wc_product->get_stock_status() === 'outofstock') $warnings[] = 'producto representativo agotado';
        }
    } else $critical[] = 'no hay producto representativo';

    if (function_exists('WC') && WC() && method_exists(WC(), 'payment_gateways')) {
        try {
            $gateways = WC()->payment_gateways()->payment_gateways();
            foreach ((array) $gateways as $gateway) {
                if (is_object($gateway) && isset($gateway->enabled) && $gateway->enabled === 'yes') $evidence['enabled_gateways'][] = isset($gateway->id) ? (string) $gateway->id : get_class($gateway);
            }
        } catch (Throwable $exception) {
            $warnings[] = 'no se pudieron enumerar métodos de pago';
        }
    }
    if (empty($evidence['enabled_gateways'])) $warnings[] = 'no se detectan métodos de pago habilitados';

    if ($enabled) {
        foreach (array('producto', 'carrito', 'checkout') as $name) {
            $url = isset($urls[$name]) ? (string) $urls[$name] : '';
            if ($url === '') continue;
            $probe = seo_core_system_test_http_probe($url);
            $evidence['http'][$name] = array('code' => (int) ($probe['code'] ?? 0), 'blocker' => seo_core_system_test_probe_blocker($probe));
            if (!empty($probe['transport_error']) || !empty($probe['security_challenge'])) $warnings[] = $name . ' no verificable por HTTP';
            elseif ((int) $probe['code'] !== 200) $critical[] = $name . ' responde HTTP ' . (int) $probe['code'];
        }
    }

    $detail = 'Comprobación de catálogo, producto, carrito, checkout y pagos sin crear pedidos ni ejecutar cobros.';
    $meta = array('owner' => 'Woo', 'area' => 'sellability', 'evidence' => $evidence, 'coverage' => 100, 'confidence' => $enabled ? 85 : 65);
    if (!empty($critical)) return seo_core_system_test_check_ko($detail . ' Bloqueos: ' . implode('; ', array_unique($critical)) . '. Avisos: ' . (empty($warnings) ? 'ninguno' : implode('; ', array_unique($warnings))) . '.', $meta);
    if (!empty($warnings)) return seo_core_system_test_check_warning($detail . ' Avisos: ' . implode('; ', array_unique($warnings)) . '.', $meta);
    return seo_core_system_test_check_ok($detail . ' La configuración básica permite continuar hacia una compra.', $meta);
}

function seo_core_system_test_indexation_readiness_check($urls, $enabled) {
    $evidence = array('blog_public' => (string) get_option('blog_public', '1') === '1', 'robots_txt' => array(), 'public_pages' => array());
    $critical = array(); $warnings = array();
    if (!$evidence['blog_public']) $critical[] = 'WordPress disuade a los buscadores';
    if (!$enabled) return seo_core_system_test_check_info('Comprobación HTTP de indexación desactivada.', array('owner' => 'SEO', 'area' => 'indexation', 'evidence' => $evidence));

    $robots_url = home_url('/robots.txt');
    $robots_probe = seo_core_system_test_resource_probe($robots_url, 'text/plain,*/*;q=0.5', 128 * 1024);
    $evidence['robots_txt'] = array('url' => $robots_url, 'code' => (int) ($robots_probe['code'] ?? 0), 'content_type' => (string) ($robots_probe['content_type'] ?? ''));
    if (!empty($robots_probe['transport_error']) || !empty($robots_probe['security_challenge'])) $warnings[] = 'robots.txt no verificable';
    elseif ((int) $robots_probe['code'] !== 200) $warnings[] = 'robots.txt responde HTTP ' . (int) $robots_probe['code'];
    elseif (preg_match('/Disallow:\s*\/\s*$/mi', (string) ($robots_probe['body'] ?? ''))) $critical[] = 'robots.txt bloquea todo el sitio';

    foreach (array('portada', 'tienda', 'categoria', 'producto') as $name) {
        if (empty($urls[$name])) continue;
        $probe = seo_core_system_test_http_probe($urls[$name]);
        $evidence['public_pages'][$name] = array('code' => (int) ($probe['code'] ?? 0), 'blocker' => seo_core_system_test_probe_blocker($probe));
        if ((int) ($probe['code'] ?? 0) !== 200) $critical[] = $name . ' no devuelve HTTP 200';
    }

    $sitemap = seo_core_system_test_sitemap_check(true);
    $evidence['sitemap'] = array('status' => $sitemap['status'] ?? '', 'detail' => $sitemap['detail'] ?? '');
    if (($sitemap['severity'] ?? '') === 'ko') $critical[] = 'sitemap inválido';
    elseif (($sitemap['severity'] ?? '') === 'warning') $warnings[] = 'sitemap no concluyente o mejorable';

    $meta = array('owner' => 'SEO', 'area' => 'indexation', 'evidence' => $evidence, 'coverage' => 100, 'confidence' => 90);
    if (!empty($critical)) return seo_core_system_test_check_ko('La indexación pública presenta bloqueos: ' . implode('; ', array_unique($critical)) . '.', $meta);
    if (!empty($warnings)) return seo_core_system_test_check_warning('La indexación pública es parcial: ' . implode('; ', array_unique($warnings)) . '.', $meta);
    return seo_core_system_test_check_ok('WordPress es indexable, robots.txt responde, las páginas representativas devuelven HTTP 200 y existe un sitemap válido.', $meta);
}

function seo_core_system_test_internal_links_check($urls, $enabled) {
    if (!$enabled) return seo_core_system_test_check_info('Comprobacion HTTP desactivada.');
    $issues = array(); $checks = 0; $blocked = array();

    if (!empty($urls['portada']) && !empty($urls['tienda'])) {
        $probe = seo_core_system_test_http_probe($urls['portada']);
        if (seo_core_system_test_probe_is_usable_html($probe)) {
            $checks++;
            if (!seo_core_system_test_html_links_to($probe['body'], $urls['portada'], $urls['tienda'])) $issues[] = 'la portada no enlaza de forma reconocible con la tienda';
        } else $blocked['portada'] = seo_core_system_test_probe_blocker($probe);
    }
    foreach (array('tienda', 'categoria') as $name) {
        if (empty($urls[$name])) continue;
        $probe = seo_core_system_test_http_probe($urls[$name]);
        if (seo_core_system_test_probe_is_usable_html($probe)) {
            $checks++;
            if (!seo_core_system_test_html_has_product_link($probe['body'], $urls[$name])) $issues[] = $name . ' no contiene enlaces de producto reconocibles';
        } else $blocked[$name] = seo_core_system_test_probe_blocker($probe);
    }
    if (!empty($urls['producto']) && !empty($urls['categoria'])) {
        $probe = seo_core_system_test_http_probe($urls['producto']);
        if (seo_core_system_test_probe_is_usable_html($probe)) {
            $checks++;
            if (!seo_core_system_test_html_links_to($probe['body'], $urls['producto'], $urls['categoria'])) $issues[] = 'el producto no enlaza con su categoria representativa';
        } else $blocked['producto'] = seo_core_system_test_probe_blocker($probe);
    }

    if ($checks === 0) return seo_core_system_test_check_not_evaluable('No se recibió ninguna página con HTTP 200 y HTML válido para comprobar enlazado interno.', 'HTTP_HTML_UNAVAILABLE', array('evidence' => $blocked));
    if (!empty($issues) || !empty($blocked)) {
        if (!empty($blocked)) $issues[] = 'páginas no evaluables: ' . implode(', ', array_keys($blocked));
        return seo_core_system_test_check_warning('Enlazado interno mejorable: ' . implode('; ', $issues) . '.', array('owner' => 'SEO', 'coverage' => (int) round(($checks / max(1, $checks + count($blocked))) * 100), 'evidence' => $blocked));
    }
    return seo_core_system_test_check_ok('La portada enlaza la tienda y las plantillas inspeccionadas contienen enlaces de navegacion entre catalogo, categorias y productos.', array('owner' => 'SEO'));
}


function seo_core_system_test_redirects_check($enabled) {
    global $wpdb;

    $table = $wpdb->prefix . 'seo_redirects';
    if (!seo_core_system_test_table_exists($table)) {
        return seo_core_system_test_check_warning('No existe la tabla ' . $table . '; no se pueden validar redirecciones.');
    }

    $limit = max(1, (int) apply_filters('seo_core_system_test_redirect_analysis_limit', 200));
    $rows = $wpdb->get_results("SELECT origin_url, target_url, status_code FROM {$table} ORDER BY id DESC LIMIT {$limit}", ARRAY_A);
    if (empty($rows)) {
        return seo_core_system_test_check_info('No hay redirecciones registradas.');
    }

    $map = array();
    $invalid = array();
    foreach ($rows as $row) {
        $origin = isset($row['origin_url']) ? trim((string) $row['origin_url']) : '';
        $target = isset($row['target_url']) ? trim((string) $row['target_url']) : '';
        $status = isset($row['status_code']) ? (int) $row['status_code'] : 0;
        $origin_key = seo_core_system_test_redirect_url_key($origin);
        $target_key = seo_core_system_test_redirect_url_key($target);
        if ($origin_key === '' || $target_key === '') {
            $invalid[] = 'origen o destino vacio';
            continue;
        }
        if (!in_array($status, array(301, 302, 307, 308), true)) {
            $invalid[] = $origin . ' usa estado ' . $status;
        }
        if ($origin_key === $target_key) {
            $invalid[] = 'bucle directo en ' . $origin;
        }
        $map[$origin_key] = $target_key;
    }

    $loops = seo_core_system_test_redirect_cycles($map);
    if (!empty($loops)) {
        $invalid[] = 'ciclos: ' . implode(' | ', array_slice($loops, 0, 5));
    }
    if (!empty($invalid)) {
        return seo_core_system_test_check_ko('Redirecciones invalidas: ' . implode('; ', array_slice(array_unique($invalid), 0, 10)) . '.');
    }

    if (!$enabled) {
        return seo_core_system_test_check_ok('Redirecciones analizadas sin bucles: ' . number_format_i18n(count($rows)) . '. Comprobacion HTTP de destinos desactivada.');
    }

    $target_limit = max(1, (int) apply_filters('seo_core_system_test_redirect_target_checks', 3));
    $targets = array();
    foreach ($rows as $row) {
        $target = isset($row['target_url']) ? trim((string) $row['target_url']) : '';
        $absolute = seo_core_system_test_absolute_url($target, home_url('/'));
        if ($absolute !== '') {
            $targets[$absolute] = true;
        }
        if (count($targets) >= $target_limit) {
            break;
        }
    }

    $broken = array();
    $warnings = 0;
    foreach (array_keys($targets) as $target_url) {
        $probe = seo_core_system_test_resource_probe($target_url, 'text/html,application/xhtml+xml,*/*;q=0.5', 64 * 1024);
        if (!empty($probe['transport_error']) || !empty($probe['security_challenge'])) {
            $warnings++;
            continue;
        }
        if ((int) $probe['code'] < 200 || (int) $probe['code'] >= 400) {
            $broken[] = 'HTTP ' . (int) $probe['code'] . ' ' . $target_url;
        }
    }

    if (!empty($broken)) {
        return seo_core_system_test_check_warning('No hay bucles, pero algunos destinos fallan: ' . implode(' | ', $broken) . '. La auditoría detallada aparece en Enlaces y 404.');
    }
    if ($warnings > 0) {
        return seo_core_system_test_check_warning('Redirecciones sin bucles. Destinos verificados: ' . number_format_i18n(count($targets) - $warnings) . '; no concluyentes: ' . number_format_i18n($warnings) . '.');
    }

    return seo_core_system_test_check_ok('Redirecciones sin bucles. Registros analizados: ' . number_format_i18n(count($rows)) . '; destinos HTTP validos: ' . number_format_i18n(count($targets)) . '.');
}

function seo_core_system_test_performance_check($urls, $enabled) {
    if (!$enabled) return seo_core_system_test_check_info('Comprobacion HTTP desactivada.');
    $warning_seconds = (float) apply_filters('seo_core_system_test_response_time_warning', 3.5);
    $critical_seconds = (float) apply_filters('seo_core_system_test_response_time_critical', 5.5);
    $warning_bytes = (int) apply_filters('seo_core_system_test_html_size_warning', 650 * 1024);
    $times = array(); $sizes = array(); $blocked = array();

    foreach (array('portada', 'tienda', 'categoria', 'producto') as $name) {
        if (empty($urls[$name])) continue;
        $probe = seo_core_system_test_http_probe($urls[$name]);
        if (!seo_core_system_test_probe_is_usable_html($probe)) { $blocked[$name] = seo_core_system_test_probe_blocker($probe); continue; }
        $times[$name] = isset($probe['elapsed']) ? (float) $probe['elapsed'] : 0.0;
        $body_bytes = isset($probe['response_bytes']) ? (int) $probe['response_bytes'] : strlen((string) $probe['body']);
        $header_bytes = isset($probe['content_length']) ? (int) $probe['content_length'] : 0;
        $sizes[$name] = max($body_bytes, $header_bytes);
    }
    if (empty($times)) return seo_core_system_test_check_not_evaluable('No se recibió ninguna página con HTTP 200 y HTML válido para medir rendimiento.', 'HTTP_HTML_UNAVAILABLE', array('evidence' => $blocked));

    $max_time = max($times); $average = array_sum($times) / count($times); $max_size = !empty($sizes) ? max($sizes) : 0;
    $slowest = array_search($max_time, $times, true); $largest = !empty($sizes) ? array_search($max_size, $sizes, true) : '';
    $detail = 'Media: ' . number_format_i18n($average, 2) . ' s; maxima: ' . number_format_i18n($max_time, 2) . ' s (' . $slowest . '); HTML mayor: ' . seo_core_system_test_format_bytes($max_size) . ($largest !== '' ? ' (' . $largest . ')' : '') . '. Cobertura: ' . count($times) . ' páginas.';
    $meta = array('owner' => 'hosting', 'coverage' => (int) round((count($times) / max(1, count($times) + count($blocked))) * 100), 'evidence' => array('times' => $times, 'sizes' => $sizes, 'blocked' => $blocked));
    if ($max_time >= $critical_seconds) return seo_core_system_test_check_ko('Tiempo de respuesta critico. ' . $detail, $meta);
    if ($max_time >= $warning_seconds || $max_size >= $warning_bytes || !empty($blocked)) return seo_core_system_test_check_warning('Rendimiento mejorable o incompleto. ' . $detail, $meta);
    return seo_core_system_test_check_ok('Rendimiento HTTP dentro de los umbrales configurados. ' . $detail, $meta);
}


function seo_core_system_test_visible_errors_check($urls, $enabled) {
    if (!$enabled) return seo_core_system_test_check_info('Comprobacion HTTP desactivada.');
    $critical = array(); $warnings = array(); $checked = 0; $blocked = array();
    foreach ($urls as $name => $url) {
        if ($url === '') continue;
        $probe = seo_core_system_test_http_probe($url);
        if (!seo_core_system_test_probe_is_usable_html($probe)) { $blocked[$name] = seo_core_system_test_probe_blocker($probe); continue; }
        $checked++;
        $found = seo_core_system_test_visible_error_signatures($probe['body']);
        foreach ($found['critical'] as $signature) $critical[] = $name . ': ' . $signature;
        foreach ($found['warning'] as $signature) $warnings[] = $name . ': ' . $signature;
    }
    if ($checked === 0) return seo_core_system_test_check_not_evaluable('No se recibió ninguna página con HTTP 200 y HTML válido para buscar errores visibles.', 'HTTP_HTML_UNAVAILABLE', array('evidence' => $blocked));
    $meta = array('owner' => 'WP', 'coverage' => (int) round(($checked / max(1, $checked + count($blocked))) * 100), 'evidence' => $blocked);
    if (!empty($critical)) return seo_core_system_test_check_ko('Errores criticos visibles en HTML: ' . implode(' | ', array_unique($critical)) . '.', $meta);
    if (!empty($warnings)) return seo_core_system_test_check_warning('Avisos PHP visibles en HTML: ' . implode(' | ', array_unique($warnings)) . '.', $meta);
    if (!empty($blocked)) return seo_core_system_test_check_warning('No se detectan errores en las páginas evaluadas, pero quedaron páginas no evaluables: ' . implode(', ', array_keys($blocked)) . '.', $meta);
    return seo_core_system_test_check_ok('No se detectan mensajes Fatal, Warning, Notice, Deprecated, Stack Trace ni errores SQL en las paginas inspeccionadas.', $meta);
}


function seo_core_system_test_probe_unavailable_check($probe, $url) {
    if (!empty($probe['transport_error'])) {
        return seo_core_system_test_check_warning('No se pudo completar la comprobacion HTTP: ' . $probe['transport_error'] . '. URL: ' . $url);
    }
    if (!empty($probe['security_challenge'])) {
        return seo_core_system_test_check_warning('La comprobacion fue interceptada por proteccion perimetral o antibot. URL: ' . $url . '.');
    }
    return null;
}

function seo_core_system_test_probe_is_usable_html($probe) {
    return empty($probe['transport_error'])
        && empty($probe['security_challenge'])
        && (int) $probe['code'] === 200
        && !empty($probe['has_html']);
}


function seo_core_system_test_check_ok($detail, $meta = array()) {
    return array_merge(array('passed' => true, 'severity' => 'ok', 'status' => 'pass', 'detail' => $detail), is_array($meta) ? $meta : array());
}


function seo_core_system_test_check_warning($detail, $meta = array()) {
    return array_merge(array('passed' => false, 'severity' => 'warning', 'status' => 'warning', 'detail' => $detail), is_array($meta) ? $meta : array());
}


function seo_core_system_test_check_ko($detail, $meta = array()) {
    return array_merge(array('passed' => false, 'severity' => 'ko', 'status' => 'fail', 'detail' => $detail), is_array($meta) ? $meta : array());
}


function seo_core_system_test_check_info($detail, $meta = array()) {
    return array_merge(array('passed' => true, 'severity' => 'info', 'status' => 'info', 'detail' => $detail), is_array($meta) ? $meta : array());
}



function seo_core_system_test_check_not_evaluable($detail, $blocked_by = 'DEPENDENCY_UNAVAILABLE', $meta = array()) {
    $meta = is_array($meta) ? $meta : array();
    return array_merge(array(
        'passed' => true,
        'severity' => 'info',
        'status' => 'not_evaluable',
        'detail' => $detail,
        'blocked_by' => sanitize_key((string) $blocked_by),
        'coverage' => 0,
        'confidence' => 0,
    ), $meta);
}

function seo_core_system_test_probe_blocker($probe) {
    if (!empty($probe['transport_error'])) return 'HTTP_TRANSPORT_ERROR';
    if (!empty($probe['security_challenge'])) return 'HTTP_SECURITY_CHALLENGE';
    $code = (int) ($probe['code'] ?? 0);
    if ($code !== 200) return 'HTTP_' . $code;
    if (empty($probe['has_html'])) return 'HTML_NOT_AVAILABLE';
    return '';
}

function seo_core_system_test_owner_for_result($group, $label) {
    $group = sanitize_key((string) $group);
    $label_normalized = strtolower(remove_accents((string) $label));
    if (preg_match('/sitemap|canonical|robots|json-ld|indexa|h1|enlace|redirec|semantic|categoria|etiqueta|cluster|hub/', $label_normalized)) return 'SEO';
    if (preg_match('/faq|descripcion|contenido/', $label_normalized)) return 'contenido';
    if (preg_match('/http 5|servidor|rendimiento|dns|ssl/', $label_normalized)) return 'hosting';
    if (in_array($group, array('checkout', 'catalog', 'emails'), true) || preg_match('/woocommerce|carrito|checkout|producto.*vend|tienda preparada|pago|stock/', $label_normalized)) return 'Woo';
    if ($group === 'semantic') return 'SEO';
    return 'WP';
}

function seo_core_system_test_default_status($severity, $passed) {
    if ($severity === 'ok') return 'pass';
    if ($severity === 'warning') return 'warning';
    if ($severity === 'ko') return 'fail';
    return $passed ? 'info' : 'unknown';
}

function seo_core_system_test_html_element_texts($html, $tag) {
    $texts = array();
    $tag = preg_quote((string) $tag, '/');
    if (preg_match_all('/<' . $tag . '\b[^>]*>(.*?)<\/' . $tag . '>/is', (string) $html, $matches)) {
        foreach ($matches[1] as $value) {
            $value = trim(html_entity_decode(wp_strip_all_tags((string) $value), ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8'));
            if ($value !== '') {
                $texts[] = preg_replace('/\s+/u', ' ', $value);
            }
        }
    }
    return $texts;
}

function seo_core_system_test_text_contains_normalized($haystack, $needle) {
    $threshold = function_exists('seo_core_validation_get_setting')
        ? (int) seo_core_validation_get_setting('h1_match_percent', 60)
        : 60;
    $analysis = seo_core_system_test_text_match_analysis($haystack, $needle, $threshold);
    return !empty($analysis['passed']);
}

function seo_core_system_test_extract_link_rel_urls($html, $relation, $base_url) {
    $urls = array();
    if (!preg_match_all('/<link\b[^>]*>/i', (string) $html, $matches)) {
        return $urls;
    }
    foreach ($matches[0] as $tag) {
        $attrs = seo_core_system_test_parse_html_attributes($tag);
        $rel = isset($attrs['rel']) ? strtolower($attrs['rel']) : '';
        if (!preg_match('/(?:^|\s)' . preg_quote(strtolower($relation), '/') . '(?:\s|$)/', $rel)) {
            continue;
        }
        if (!empty($attrs['href'])) {
            $absolute = seo_core_system_test_absolute_url($attrs['href'], $base_url);
            if ($absolute !== '') {
                $urls[] = $absolute;
            }
        }
    }
    return array_values(array_unique($urls));
}

function seo_core_system_test_extract_meta_content($html, $name) {
    $values = array();
    if (!preg_match_all('/<meta\b[^>]*>/i', (string) $html, $matches)) {
        return $values;
    }
    foreach ($matches[0] as $tag) {
        $attrs = seo_core_system_test_parse_html_attributes($tag);
        $meta_name = isset($attrs['name']) ? strtolower(trim($attrs['name'])) : '';
        if ($meta_name === strtolower($name) && isset($attrs['content'])) {
            $values[] = trim($attrs['content']);
        }
    }
    return $values;
}

function seo_core_system_test_parse_html_attributes($tag) {
    $attributes = array();
    if (preg_match_all('/([a-zA-Z_:][a-zA-Z0-9_:\.-]*)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/', (string) $tag, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            $value = trim($match[2]);
            if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') || (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }
            $attributes[$name] = html_entity_decode($value, ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8');
        }
    }
    return $attributes;
}

function seo_core_system_test_normalize_url_for_compare($url) {
    $url = esc_url_raw((string) $url);
    if ($url === '') {
        return '';
    }
    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return untrailingslashit(strtolower($url));
    }
    $host = strtolower($parts['host']);
    $path = isset($parts['path']) ? '/' . ltrim($parts['path'], '/') : '/';
    $path = $path === '/' ? '/' : untrailingslashit($path);
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
    return $host . $path . $query;
}

function seo_core_system_test_extract_json_ld($html) {
    $result = array('blocks' => 0, 'invalid' => 0, 'types' => array());
    if (!preg_match_all('/<script\b[^>]*type\s*=\s*["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', (string) $html, $matches)) {
        return $result;
    }
    foreach ($matches[1] as $json) {
        $json = trim(html_entity_decode((string) $json, ENT_NOQUOTES, get_bloginfo('charset') ?: 'UTF-8'));
        $json = preg_replace('/^\s*<!--|-->\s*$/', '', $json);
        $json = preg_replace('#^\s*/\*\s*<!\[CDATA\[\s*\*/|/\*\s*\]\]>\s*\*/\s*$#', '', $json);
        if ($json === '') {
            continue;
        }
        $result['blocks']++;
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $result['invalid']++;
            continue;
        }
        seo_core_system_test_collect_schema_types($decoded, $result['types']);
    }
    $result['types'] = array_values(array_unique($result['types']));
    sort($result['types'], SORT_NATURAL | SORT_FLAG_CASE);
    return $result;
}

function seo_core_system_test_collect_schema_types($value, &$types) {
    if (!is_array($value)) {
        return;
    }
    if (isset($value['@type'])) {
        foreach ((array) $value['@type'] as $type) {
            if (is_string($type) && $type !== '') {
                $types[] = $type;
            }
        }
    }
    foreach ($value as $child) {
        if (is_array($child)) {
            seo_core_system_test_collect_schema_types($child, $types);
        }
    }
}

function seo_core_system_test_xml_is_valid($xml) {
    if (!function_exists('simplexml_load_string')) {
        return true;
    }
    $previous = libxml_use_internal_errors(true);
    $loaded = simplexml_load_string((string) $xml, 'SimpleXMLElement', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return $loaded !== false;
}

function seo_core_system_test_extract_asset_urls($html, $base_url) {
    $urls = array();
    $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));

    if (preg_match_all('/<link\b[^>]*>/i', (string) $html, $link_matches)) {
        foreach ($link_matches[0] as $tag) {
            $attrs = seo_core_system_test_parse_html_attributes($tag);
            $rel = isset($attrs['rel']) ? strtolower($attrs['rel']) : '';
            if (strpos($rel, 'stylesheet') === false || empty($attrs['href'])) {
                continue;
            }
            $absolute = seo_core_system_test_absolute_url($attrs['href'], $base_url);
            if ($absolute !== '' && strtolower((string) wp_parse_url($absolute, PHP_URL_HOST)) === $home_host) {
                $urls[$absolute] = true;
            }
        }
    }

    if (preg_match_all('/<script\b[^>]*>/i', (string) $html, $script_matches)) {
        foreach ($script_matches[0] as $tag) {
            $attrs = seo_core_system_test_parse_html_attributes($tag);
            if (empty($attrs['src'])) {
                continue;
            }
            $absolute = seo_core_system_test_absolute_url($attrs['src'], $base_url);
            if ($absolute !== '' && strtolower((string) wp_parse_url($absolute, PHP_URL_HOST)) === $home_host) {
                $urls[$absolute] = true;
            }
        }
    }

    return array_keys($urls);
}

function seo_core_system_test_absolute_url($url, $base_url) {
    $url = trim(html_entity_decode((string) $url, ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8'));
    if ($url === '' || strpos($url, '#') === 0 || stripos($url, 'data:') === 0 || stripos($url, 'javascript:') === 0 || stripos($url, 'mailto:') === 0 || stripos($url, 'tel:') === 0) {
        return '';
    }
    if (strpos($url, '//') === 0) {
        $scheme = wp_parse_url($base_url, PHP_URL_SCHEME) ?: 'https';
        return esc_url_raw($scheme . ':' . $url);
    }
    if (preg_match('#^https?://#i', $url)) {
        return esc_url_raw($url);
    }
    $base = wp_parse_url($base_url);
    if (!is_array($base) || empty($base['host'])) {
        return '';
    }
    $scheme = !empty($base['scheme']) ? $base['scheme'] : 'https';
    $origin = $scheme . '://' . $base['host'] . (!empty($base['port']) ? ':' . $base['port'] : '');
    if (strpos($url, '/') === 0) {
        return esc_url_raw($origin . $url);
    }
    $path = !empty($base['path']) ? dirname($base['path']) : '/';
    return esc_url_raw($origin . trailingslashit($path) . $url);
}

function seo_core_system_test_html_links_to($html, $base_url, $target_url) {
    $target = seo_core_system_test_normalize_url_for_compare($target_url);
    foreach (seo_core_system_test_extract_anchor_urls($html, $base_url) as $url) {
        if (seo_core_system_test_normalize_url_for_compare($url) === $target) {
            return true;
        }
    }
    return false;
}

function seo_core_system_test_html_has_product_link($html, $base_url) {
    foreach (seo_core_system_test_extract_anchor_urls($html, $base_url) as $url) {
        if (strpos((string) wp_parse_url($url, PHP_URL_PATH), '/producto/') !== false) {
            return true;
        }
    }
    return false;
}

function seo_core_system_test_extract_anchor_urls($html, $base_url) {
    $urls = array();
    if (preg_match_all('/<a\b[^>]*>/i', (string) $html, $matches)) {
        foreach ($matches[0] as $tag) {
            $attrs = seo_core_system_test_parse_html_attributes($tag);
            if (empty($attrs['href'])) {
                continue;
            }
            $absolute = seo_core_system_test_absolute_url($attrs['href'], $base_url);
            if ($absolute !== '') {
                $urls[] = $absolute;
            }
        }
    }
    return array_values(array_unique($urls));
}

function seo_core_system_test_redirect_url_key($url) {
    $absolute = seo_core_system_test_absolute_url((string) $url, home_url('/'));
    return seo_core_system_test_normalize_url_for_compare($absolute);
}

function seo_core_system_test_redirect_cycles($map) {
    $cycles = array();
    foreach ($map as $start => $target) {
        $visited = array();
        $current = $start;
        for ($step = 0; $step <= count($map); $step++) {
            if (isset($visited[$current])) {
                $path = array_keys($visited);
                $path[] = $current;
                $cycles[] = implode(' -> ', $path);
                break;
            }
            $visited[$current] = true;
            if (!isset($map[$current])) {
                break;
            }
            $current = $map[$current];
        }
    }
    return array_values(array_unique($cycles));
}

function seo_core_system_test_visible_error_signatures($body) {
    $body = (string) $body;
    $critical_patterns = array(
        'Fatal error' => '/(?:PHP\s+)?Fatal error\s*:/i',
        'Uncaught error' => '/Uncaught\s+(?:Error|Exception|TypeError)/i',
        'Stack trace' => '/Stack trace\s*:/i',
        'WordPress database error' => '/WordPress database error/i',
        'SQL exception' => '/mysqli_sql_exception|PDOException/i',
        'Critical WordPress error' => '/there has been a critical error on this website|ha habido un error cr.tico en esta web/i',
    );
    $warning_patterns = array(
        'PHP Warning' => '/(?:PHP\s+)?Warning\s*:\s|<b>Warning<\/b>\s*:/i',
        'PHP Notice' => '/(?:PHP\s+)?Notice\s*:\s|<b>Notice<\/b>\s*:/i',
        'Deprecated' => '/(?:PHP\s+)?Deprecated\s*:\s|<b>Deprecated<\/b>\s*:/i',
        'Strict standards' => '/Strict Standards\s*:/i',
    );
    $found = array('critical' => array(), 'warning' => array());
    foreach ($critical_patterns as $label => $pattern) {
        if (preg_match($pattern, $body)) {
            $found['critical'][] = $label;
        }
    }
    foreach ($warning_patterns as $label => $pattern) {
        if (preg_match($pattern, $body)) {
            $found['warning'][] = $label;
        }
    }
    return $found;
}

function seo_core_system_test_resource_probe($url, $accept = '*/*', $limit = 131072) {
    static $cache = array();
    $url = esc_url_raw((string) $url);
    if (!seo_core_system_test_link_audit_can_continue()) {
        return array(
            'code' => 0,
            'body' => '',
            'content_type' => '',
            'security_challenge' => false,
            'transport_error' => 'Límite seguro de la auditoría alcanzado.',
            'budget_exhausted' => true,
            'elapsed' => 0.0,
        );
    }
    $key = md5($url . '|' . $accept . '|' . (int) $limit);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $arguments = array(
        'timeout' => seo_core_system_test_link_audit_budget_active() ? max(1, min(3, (int) ceil(seo_core_system_test_link_audit_time_remaining()))) : max(2, (int) apply_filters('seo_core_system_test_resource_timeout', 5)),
        'redirection' => 3,
        'user-agent' => seo_core_system_test_http_user_agent(),
        'limit_response_size' => max(4096, (int) $limit),
        'headers' => array(
            'Accept' => $accept,
            'Cache-Control' => 'no-cache',
            'Referer' => home_url('/'),
        ),
    );
    $cookies = seo_core_system_test_http_security_cookies($url);
    if (!empty($cookies)) {
        $arguments['cookies'] = $cookies;
    }
    $started = microtime(true);
    $response = wp_remote_get($url, $arguments);
    $elapsed = microtime(true) - $started;

    if (is_wp_error($response)) {
        $cache[$key] = array(
            'code' => 0,
            'body' => '',
            'content_type' => '',
            'security_challenge' => false,
            'transport_error' => $response->get_error_message(),
            'elapsed' => $elapsed,
        );
        return $cache[$key];
    }

    $body = (string) wp_remote_retrieve_body($response);
    $challenge = seo_core_system_test_detect_security_challenge($body, $response);
    $cache[$key] = array(
        'code' => (int) wp_remote_retrieve_response_code($response),
        'body' => $body,
        'content_type' => strtolower((string) wp_remote_retrieve_header($response, 'content-type')),
        'security_challenge' => !empty($challenge['detected']),
        'transport_error' => '',
        'elapsed' => $elapsed,
    );
    return $cache[$key];
}

function seo_core_system_test_functional_http_result($label, $url, $expected_text = '', $required = true, $enabled = true) {
    if (!$enabled) {
        return seo_core_system_test_result(
            'functional',
            $label,
            true,
            'Comprobación HTTP desactivada mediante el filtro seo_core_system_test_enable_functional_http_checks.',
            'info'
        );
    }

    $url = esc_url_raw((string) $url);

    if ($url === '') {
        return seo_core_system_test_result(
            'functional',
            $label,
            false,
            $required ? 'No se ha podido resolver una URL necesaria para esta prueba.' : 'No hay un elemento representativo disponible para ejecutar esta prueba.',
            $required ? 'ko' : 'warning'
        );
    }

    $probe = seo_core_system_test_http_probe($url);

    if (!empty($probe['transport_error'])) {
        return seo_core_system_test_result(
            'functional',
            $label,
            false,
            'No se pudo verificar por HTTP: ' . $probe['transport_error'] . ' URL: ' . $url,
            'warning'
        );
    }

    if (!empty($probe['security_challenge'])) {
        $challenge_detail = !empty($probe['challenge_reason'])
            ? ' Firma detectada: ' . $probe['challenge_reason'] . '.'
            : '';
        $server_detail = !empty($probe['server'])
            ? ' Servidor: ' . $probe['server'] . '.'
            : '';

        return seo_core_system_test_result(
            'functional',
            $label,
            false,
            'La comprobación interna ha sido interceptada por una protección perimetral o antibot. HTTP ' . (int) $probe['code'] . '.'
                . $challenge_detail . $server_detail
                . ' No se considera una caída pública; la prueba queda no concluyente. URL: ' . $url,
            'warning'
        );
    }

    if (!empty($probe['fatal_signature'])) {
        return seo_core_system_test_result(
            'functional',
            $label,
            false,
            'La respuesta contiene una señal de error crítico. HTTP ' . (int) $probe['code'] . '. URL: ' . $url,
            'ko'
        );
    }

    if ((int) $probe['code'] < 200 || (int) $probe['code'] >= 400) {
        $severity = in_array((int) $probe['code'], array(401, 403, 429), true) ? 'warning' : 'ko';
        return seo_core_system_test_result(
            'functional',
            $label,
            false,
            'Respuesta HTTP ' . (int) $probe['code'] . '. URL: ' . $url,
            $severity
        );
    }

    if (empty($probe['has_html'])) {
        return seo_core_system_test_result(
            'functional',
            $label,
            false,
            'La URL responde con HTTP ' . (int) $probe['code'] . ', pero no se detecta una página HTML utilizable. URL: ' . $url,
            'warning'
        );
    }

    $match_analysis = null;
    if ($expected_text !== '') {
        $match_analysis = seo_core_system_test_text_match_analysis($probe['body'], $expected_text);
        if (empty($match_analysis['passed'])) {
            return seo_core_system_test_result(
                'functional',
                $label,
                false,
                'La página responde con HTTP ' . (int) $probe['code'] . ', pero la coincidencia del contenido esperado es ' . (int) $match_analysis['percent'] . '% y el mínimo configurado es ' . (int) $match_analysis['threshold'] . '%. Esperado: ' . $expected_text . '. URL: ' . $url,
                'warning',
                array(
                    'owner' => 'WP',
                    'area' => 'template_render',
                    'evidence' => array(
                        'url' => $url,
                        'http_code' => (int) $probe['code'],
                        'response_bytes' => (int) ($probe['response_bytes'] ?? strlen($probe['body'])),
                        'expected_text' => $expected_text,
                        'match' => $match_analysis,
                    ),
                    'confidence' => 92,
                )
            );
        }
    }

    $detail = 'HTTP ' . (int) $probe['code'] . '; HTML recibido: ' . seo_core_system_test_format_bytes(strlen($probe['body'])) . '. URL: ' . $url;
    if ($expected_text !== '') {
        $detail .= ' Coincidencia del contenido: ' . (int) $match_analysis['percent'] . '% (mínimo ' . (int) $match_analysis['threshold'] . '%).';
    }

    return seo_core_system_test_result(
        'functional',
        $label,
        true,
        $detail,
        'ok',
        array(
            'evidence' => array(
                'url' => $url,
                'http_code' => (int) $probe['code'],
                'response_bytes' => (int) ($probe['response_bytes'] ?? strlen($probe['body'])),
                'match' => $match_analysis,
            ),
            'confidence' => 96,
        )
    );
}

function seo_core_system_test_http_probe($url) {
    static $cache = array();

    $url = esc_url_raw((string) $url);
    if (isset($cache[$url])) {
        return $cache[$url];
    }

    $configured_timeout = function_exists('seo_core_validation_get_setting')
        ? (int) seo_core_validation_get_setting('http_timeout', 6)
        : 6;
    $configured_limit_kb = function_exists('seo_core_validation_get_setting')
        ? (int) seo_core_validation_get_setting('http_response_limit_kb', 1024)
        : 1024;
    $timeout = max(2, (int) apply_filters('seo_core_system_test_http_timeout', $configured_timeout));
    $response_limit = max(128 * 1024, min(8192 * 1024, $configured_limit_kb * 1024));
    $arguments = array(
        'timeout'             => $timeout,
        'redirection'         => 3,
        'user-agent'          => seo_core_system_test_http_user_agent(),
        'limit_response_size' => $response_limit,
        'headers'             => array(
            'Cache-Control'   => 'no-cache',
            'Pragma'          => 'no-cache',
            'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'es-ES,es;q=0.9,en;q=0.7',
            'Referer'         => home_url('/'),
        ),
    );

    $security_cookies = seo_core_system_test_http_security_cookies($url);
    if (!empty($security_cookies)) {
        $arguments['cookies'] = $security_cookies;
    }

    $arguments = apply_filters('seo_core_system_test_http_request_args', $arguments, $url);
    $started = microtime(true);
    $response = wp_remote_get($url, $arguments);
    $elapsed = microtime(true) - $started;

    if (is_wp_error($response)) {
        $cache[$url] = array(
            'code'               => 0,
            'body'               => '',
            'has_html'           => false,
            'fatal_signature'    => false,
            'security_challenge' => false,
            'challenge_reason'   => '',
            'server'             => '',
            'content_type'       => '',
            'content_length'     => 0,
            'response_bytes'     => 0,
            'elapsed'            => $elapsed,
            'transport_error'    => $response->get_error_message(),
        );
        return $cache[$url];
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $content_type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
    $content_length = (int) wp_remote_retrieve_header($response, 'content-length');
    $server = trim((string) wp_remote_retrieve_header($response, 'server'));
    $body_lower = strtolower($body);
    $fatal_patterns = array(
        'fatal error',
        'uncaught error',
        'parse error',
        'there has been a critical error on this website',
        'ha habido un error crítico en esta web',
    );
    $fatal_signature = false;

    foreach ($fatal_patterns as $pattern) {
        if (strpos($body_lower, $pattern) !== false) {
            $fatal_signature = true;
            break;
        }
    }

    $challenge = seo_core_system_test_detect_security_challenge($body, $response);

    $has_html = $body !== '' && (
        strpos($content_type, 'text/html') !== false
        || stripos($body, '<html') !== false
        || stripos($body, '<!doctype html') !== false
    );

    $cache[$url] = array(
        'code'               => $code,
        'body'               => $body,
        'has_html'           => $has_html,
        'fatal_signature'    => $fatal_signature,
        'security_challenge' => !empty($challenge['detected']),
        'challenge_reason'   => !empty($challenge['reason']) ? $challenge['reason'] : '',
        'server'             => $server,
        'content_type'       => $content_type,
        'content_length'     => $content_length,
        'response_bytes'     => strlen($body),
        'elapsed'            => $elapsed,
        'transport_error'    => '',
    );

    return $cache[$url];
}

function seo_core_system_test_http_user_agent() {
    $user_agent = isset($_SERVER['HTTP_USER_AGENT'])
        ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
        : '';

    if ($user_agent === '' || stripos($user_agent, 'SEO-System-Validation/') !== false) {
        $user_agent = 'Mozilla/5.0 (compatible; SEO Plugin Validation 6.4; +' . home_url('/') . ')';
    }

    return substr($user_agent, 0, 255);
}

function seo_core_system_test_http_security_cookies($url) {
    if (empty($_COOKIE) || !class_exists('WP_Http_Cookie')) {
        return array();
    }

    $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    $target_host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));

    if ($home_host === '' || $target_host === '' || $home_host !== $target_host) {
        return array();
    }

    $exact_names = array(
        'cf_clearance',
        '__cf_bm',
        '__cfuvid',
        'ak_bmsc',
        'bm_sv',
        'bm_mi',
    );
    $prefixes = array(
        'incap_ses_',
        'visid_incap_',
        'nlbi_',
    );

    $cookies = array();

    foreach ($_COOKIE as $name => $value) {
        $name = (string) $name;
        $allowed = in_array($name, $exact_names, true);

        if (!$allowed) {
            foreach ($prefixes as $prefix) {
                if (strpos($name, $prefix) === 0) {
                    $allowed = true;
                    break;
                }
            }
        }

        if (!$allowed || !is_scalar($value)) {
            continue;
        }

        $value = (string) wp_unslash($value);
        if ($value === '' || strlen($value) > 4096) {
            continue;
        }

        $cookies[] = new WP_Http_Cookie(array(
            'name'   => $name,
            'value'  => $value,
            'domain' => $target_host,
            'path'   => '/',
        ));
    }

    return $cookies;
}

function seo_core_system_test_detect_security_challenge($body, $response = null) {
    $body_lower = strtolower((string) $body);
    $status_code = $response !== null ? (int) wp_remote_retrieve_response_code($response) : 0;

    if ($response !== null) {
        $challenge_header = strtolower((string) wp_remote_retrieve_header($response, 'cf-mitigated'));
        if ($challenge_header === 'challenge') {
            return array(
                'detected' => true,
                'reason'   => 'cabecera de desafío perimetral',
            );
        }
    }

    $strong_patterns = array(
        'please wait while your request is being verified' => 'la solicitud está siendo verificada',
        'one moment, please'                              => 'página de espera de seguridad',
        'request is being verified'                        => 'la solicitud está siendo verificada',
        'checking your browser'                            => 'comprobación del navegador',
        'verify you are human'                             => 'verificación humana',
        'verifying you are human'                          => 'verificación humana',
        'challenge-platform'                               => 'plataforma de desafío',
        'cf-chl-'                                          => 'desafío de Cloudflare',
        'cf-turnstile'                                     => 'desafío Turnstile',
        'enable javascript and cookies to continue'        => 'verificación que exige JavaScript y cookies',
    );
    $weak_patterns = array(
        'security verification' => 'verificación de seguridad',
        'just a moment'         => 'desafío automático de seguridad',
        'ddos protection by'    => 'protección DDoS',
    );

    $strong_patterns = apply_filters('seo_core_system_test_security_challenge_patterns', $strong_patterns);
    $weak_patterns = apply_filters('seo_core_system_test_security_challenge_weak_patterns', $weak_patterns);

    foreach ((array) $strong_patterns as $pattern => $reason) {
        if ($pattern !== '' && strpos($body_lower, strtolower((string) $pattern)) !== false) {
            return array(
                'detected' => true,
                'reason'   => (string) $reason,
            );
        }
    }

    if ($status_code >= 400 || ($status_code === 0 && strlen($body_lower) < 150000)) {
        foreach ((array) $weak_patterns as $pattern => $reason) {
            if ($pattern !== '' && strpos($body_lower, strtolower((string) $pattern)) !== false) {
                return array(
                    'detected' => true,
                    'reason'   => (string) $reason,
                );
            }
        }
    }

    return array(
        'detected' => false,
        'reason'   => '',
    );
}

function seo_core_system_test_compact_search_term($term, $maximum_words = 7, $maximum_length = 90) {
    $term = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $term)));
    if ($term === '') {
        return '';
    }

    $words = preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($words) || empty($words)) {
        return $term;
    }

    $selected = array();
    foreach ($words as $word) {
        $candidate = trim(implode(' ', array_merge($selected, array($word))));
        if (count($selected) >= max(1, (int) $maximum_words) || strlen($candidate) > max(20, (int) $maximum_length)) {
            break;
        }
        $selected[] = $word;
    }

    return !empty($selected) ? implode(' ', $selected) : $term;
}

function seo_core_system_test_match_normalize($text) {
    $text = html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');
    $text = remove_accents($text);
    if (function_exists('mb_strtolower')) {
        $text = mb_strtolower($text, 'UTF-8');
    } else {
        $text = strtolower($text);
    }
    $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
    return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
}

function seo_core_system_test_match_tokens($text) {
    $normalized = seo_core_system_test_match_normalize($text);
    if ($normalized === '') {
        return array();
    }
    $stopwords = array('para', 'con', 'sin', 'del', 'las', 'los', 'una', 'uno', 'unos', 'unas', 'por', 'que', 'como', 'sus', 'este', 'esta', 'estos', 'estas', 'and', 'the', 'for', 'with');
    $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
    $tokens = array_values(array_unique(array_filter((array) $tokens, static function ($token) use ($stopwords) {
        return strlen((string) $token) >= 3 && !in_array($token, $stopwords, true);
    })));
    return $tokens;
}

function seo_core_system_test_text_match_analysis($haystack, $needle, $threshold = null) {
    $threshold = $threshold === null
        ? (int) (function_exists('seo_core_validation_get_setting') ? seo_core_validation_get_setting('content_match_percent', 70) : 70)
        : (int) $threshold;
    $threshold = max(1, min(100, $threshold));
    $haystack_normalized = seo_core_system_test_match_normalize($haystack);
    $needle_normalized = seo_core_system_test_match_normalize($needle);

    if ($needle_normalized === '') {
        return array('passed' => true, 'percent' => 100, 'matched' => array(), 'missing' => array(), 'threshold' => $threshold, 'exact' => true);
    }
    if ($haystack_normalized !== '' && strpos($haystack_normalized, $needle_normalized) !== false) {
        return array('passed' => true, 'percent' => 100, 'matched' => seo_core_system_test_match_tokens($needle), 'missing' => array(), 'threshold' => $threshold, 'exact' => true);
    }

    $expected_tokens = seo_core_system_test_match_tokens($needle);
    $haystack_tokens = array_fill_keys(seo_core_system_test_match_tokens($haystack), true);
    if (empty($expected_tokens)) {
        $passed = $needle_normalized !== '' && strpos($haystack_normalized, $needle_normalized) !== false;
        return array('passed' => $passed, 'percent' => $passed ? 100 : 0, 'matched' => array(), 'missing' => array(), 'threshold' => $threshold, 'exact' => $passed);
    }

    $matched = array();
    $missing = array();
    foreach ($expected_tokens as $token) {
        if (isset($haystack_tokens[$token])) {
            $matched[] = $token;
        } else {
            $missing[] = $token;
        }
    }
    $percent = (int) round((count($matched) / max(1, count($expected_tokens))) * 100);
    return array(
        'passed' => $percent >= $threshold,
        'percent' => $percent,
        'matched' => $matched,
        'missing' => $missing,
        'threshold' => $threshold,
        'exact' => false,
    );
}

function seo_core_system_test_response_contains_text($body, $expected_text) {
    $analysis = seo_core_system_test_text_match_analysis($body, $expected_text);
    return !empty($analysis['passed']);
}

function seo_core_system_test_get_representative_product() {
    static $product = null;

    if ($product !== null) {
        return $product;
    }

    $product = array('id' => 0, 'title' => '', 'url' => '', 'selection_reason' => 'sin candidato');
    if (!post_type_exists('product')) {
        return $product;
    }

    $build = static function ($product_id, $reason) {
        $product_id = absint($product_id);
        $data = array(
            'id' => $product_id,
            'title' => $product_id > 0 ? (string) get_the_title($product_id) : '',
            'url' => $product_id > 0 ? (string) get_permalink($product_id) : '',
            'selection_reason' => (string) $reason,
            'visible' => null,
            'purchasable' => null,
            'stock_status' => '',
            'price' => '',
        );
        if ($product_id > 0 && function_exists('wc_get_product')) {
            $wc_product = wc_get_product($product_id);
            if ($wc_product) {
                $data['visible'] = (bool) $wc_product->is_visible();
                $data['purchasable'] = (bool) $wc_product->is_purchasable();
                $data['stock_status'] = (string) $wc_product->get_stock_status();
                $data['price'] = (string) $wc_product->get_price();
            }
        }
        return $data;
    };

    $configured_id = function_exists('seo_core_validation_get_setting')
        ? absint(seo_core_validation_get_setting('representative_product_id', 0))
        : 0;
    if ($configured_id > 0 && get_post_type($configured_id) === 'product' && get_post_status($configured_id) === 'publish') {
        $product = $build($configured_id, 'ID configurado manualmente');
        return $product;
    }

    $scan_limit = function_exists('seo_core_validation_get_setting')
        ? (int) seo_core_validation_get_setting('representative_scan_limit', 250)
        : 250;
    $scan_limit = max(20, min(2000, $scan_limit));
    $ids = get_posts(array(
        'post_type'              => 'product',
        'post_status'            => 'publish',
        'posts_per_page'         => $scan_limit,
        'orderby'                => 'modified',
        'order'                  => 'DESC',
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'ignore_sticky_posts'    => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ));

    if (empty($ids)) {
        return $product;
    }
    if (!function_exists('wc_get_product')) {
        $product = $build((int) reset($ids), 'primer producto publicado; WooCommerce no permite evaluar venta');
        return $product;
    }

    $require_visible = !function_exists('seo_core_validation_get_setting') || (bool) seo_core_validation_get_setting('representative_require_visible', 1);
    $require_purchasable = !function_exists('seo_core_validation_get_setting') || (bool) seo_core_validation_get_setting('representative_require_purchasable', 1);
    $require_in_stock = !function_exists('seo_core_validation_get_setting') || (bool) seo_core_validation_get_setting('representative_require_in_stock', 1);
    $best_id = 0;
    $best_score = -1;

    foreach ($ids as $candidate_id) {
        $candidate_id = absint($candidate_id);
        $candidate = wc_get_product($candidate_id);
        if (!$candidate) {
            continue;
        }
        $visible = (bool) $candidate->is_visible();
        $purchasable = (bool) $candidate->is_purchasable();
        $has_price = $candidate->get_price() !== '' && is_numeric($candidate->get_price());
        $in_stock = $candidate->get_stock_status() !== 'outofstock';
        $has_url = (string) get_permalink($candidate_id) !== '';
        $score = ($visible ? 4 : 0) + ($purchasable ? 4 : 0) + ($has_price ? 3 : 0) + ($in_stock ? 3 : 0) + ($has_url ? 1 : 0);

        if ($score > $best_score) {
            $best_score = $score;
            $best_id = $candidate_id;
        }

        $passes = $has_price && $has_url;
        if ($require_visible && !$visible) $passes = false;
        if ($require_purchasable && !$purchasable) $passes = false;
        if ($require_in_stock && !$in_stock) $passes = false;
        if ($passes) {
            $product = $build($candidate_id, 'selección automática válida entre ' . number_format_i18n(count($ids)) . ' candidatos');
            return $product;
        }
    }

    if ($best_id > 0) {
        $product = $build($best_id, 'mejor candidato disponible, pero no cumple todos los requisitos configurados');
    }
    return $product;
}

function seo_core_system_test_get_representative_category() {
    static $category = null;

    if ($category !== null) {
        return $category;
    }

    $category = array('id' => 0, 'name' => '', 'url' => '');

    if (!taxonomy_exists('product_cat')) {
        return $category;
    }

    $terms = get_terms(array(
        'taxonomy'   => 'product_cat',
        'hide_empty' => true,
        'number'     => 1,
        'orderby'    => 'count',
        'order'      => 'DESC',
    ));

    if (is_wp_error($terms) || empty($terms)) {
        return $category;
    }

    $term = reset($terms);
    $url = get_term_link($term);

    if (is_wp_error($url)) {
        $url = '';
    }

    $category = array(
        'id'   => (int) $term->term_id,
        'name' => (string) $term->name,
        'url'  => (string) $url,
    );

    return $category;
}

function seo_core_system_test_search_query_check($product) {
    if (empty($product['id']) || empty($product['title'])) {
        return array(
            'passed'   => false,
            'severity' => 'warning',
            'detail'   => 'No hay un producto publicado representativo con el que comprobar el buscador.',
            'term'     => '',
            'full_term' => '',
            'evidence' => array(),
        );
    }

    $full_term = trim((string) $product['title']);
    $mode = function_exists('seo_core_validation_get_setting')
        ? (string) seo_core_validation_get_setting('search_mode', 'adaptive')
        : 'adaptive';
    $maximum_words = function_exists('seo_core_validation_get_setting')
        ? (int) seo_core_validation_get_setting('search_max_words', 7)
        : 7;
    $maximum_length = function_exists('seo_core_validation_get_setting')
        ? (int) seo_core_validation_get_setting('search_max_length', 90)
        : 90;
    $compact_term = seo_core_system_test_compact_search_term($full_term, $maximum_words, $maximum_length);
    $terms = array();
    if ($mode === 'full') {
        $terms[] = $full_term;
    } elseif ($mode === 'compact') {
        $terms[] = $compact_term;
    } else {
        $terms[] = $full_term;
        if ($compact_term !== '' && $compact_term !== $full_term) {
            $terms[] = $compact_term;
        }
        if (function_exists('wc_get_product')) {
            $wc_product = wc_get_product((int) $product['id']);
            $sku = $wc_product ? trim((string) $wc_product->get_sku()) : '';
            if ($sku !== '') {
                $terms[] = $sku;
            }
        }
    }
    $terms = array_values(array_unique(array_filter(array_map('trim', $terms), 'strlen')));

    $tested = array();
    $any_ids = array();
    $matched_term = '';
    foreach ($terms as $term) {
        $query = new WP_Query(array(
            'post_type'              => 'product',
            'post_status'            => 'publish',
            's'                      => $term,
            'posts_per_page'         => 50,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ));
        $ids = array_values(array_unique(array_map('intval', (array) $query->posts)));
        $entry = array(
            'term' => $term,
            'result_count' => count($ids),
            'result_ids' => array_slice($ids, 0, 20),
            'contains_product' => in_array((int) $product['id'], $ids, true),
        );
        if (function_exists('seo_core_validation_debug_enabled') && seo_core_validation_debug_enabled()) {
            $entry['sql'] = (string) $query->request;
        }
        $tested[] = $entry;
        $any_ids = array_values(array_unique(array_merge($any_ids, $ids)));
        wp_reset_postdata();

        if ($entry['contains_product']) {
            $matched_term = $term;
            break;
        }
    }

    $evidence = array(
        'product_id' => (int) $product['id'],
        'selection_reason' => (string) ($product['selection_reason'] ?? ''),
        'mode' => $mode,
        'full_term' => $full_term,
        'compact_term' => $compact_term,
        'tested' => $tested,
    );

    if ($matched_term !== '') {
        return array(
            'passed'   => true,
            'severity' => 'ok',
            'detail'   => 'La búsqueda devuelve el producto de referencia. Término válido: ' . $matched_term . '; variantes probadas: ' . number_format_i18n(count($tested)) . '.',
            'term'     => $matched_term,
            'full_term' => $full_term,
            'evidence' => $evidence,
        );
    }

    if (empty($any_ids)) {
        return array(
            'passed'   => false,
            'severity' => 'ko',
            'detail'   => 'Ninguna variante de búsqueda devuelve productos. Título: ' . $full_term . '; términos probados: ' . implode(' | ', $terms) . '.',
            'term'     => !empty($terms) ? end($terms) : $full_term,
            'full_term' => $full_term,
            'evidence' => $evidence,
        );
    }

    return array(
        'passed'   => false,
        'severity' => 'warning',
        'detail'   => 'La búsqueda devuelve ' . number_format_i18n(count($any_ids)) . ' productos distintos, pero no incluye el producto de referencia ID ' . (int) $product['id'] . '. Términos probados: ' . implode(' | ', $terms) . '.',
        'term'     => !empty($terms) ? end($terms) : $full_term,
        'full_term' => $full_term,
        'evidence' => $evidence,
    );
}

function seo_core_system_test_email_registry_check() {
    if (!class_exists('WooCommerce') || !function_exists('WC')) {
        return array(
            'passed'   => false,
            'severity' => 'warning',
            'detail'   => 'WooCommerce no está disponible para inspeccionar su registro de correos.',
        );
    }

    try {
        $mailer = WC()->mailer();
        $emails = $mailer && method_exists($mailer, 'get_emails') ? $mailer->get_emails() : array();
    } catch (Throwable $throwable) {
        return array(
            'passed'   => false,
            'severity' => 'ko',
            'detail'   => 'Error al cargar los correos WooCommerce: ' . $throwable->getMessage(),
        );
    }

    $count = is_array($emails) ? count($emails) : 0;

    return array(
        'passed'   => $count > 0,
        'severity' => $count > 0 ? 'ok' : 'ko',
        'detail'   => $count > 0
            ? 'Clases de correo registradas: ' . number_format_i18n($count) . '.'
            : 'WooCommerce no ha devuelto clases de correo registradas.',
    );
}

function seo_core_system_test_email_template_resolution_check() {
    if (!class_exists('WooCommerce') || !function_exists('WC')) {
        return array(
            'passed'   => false,
            'severity' => 'warning',
            'detail'   => 'WooCommerce no está disponible para resolver las plantillas de correo.',
        );
    }

    try {
        $mailer = WC()->mailer();
        $emails = $mailer && method_exists($mailer, 'get_emails') ? $mailer->get_emails() : array();
    } catch (Throwable $throwable) {
        return array(
            'passed'   => false,
            'severity' => 'ko',
            'detail'   => 'Error al inspeccionar las plantillas de correo: ' . $throwable->getMessage(),
        );
    }

    $checked = 0;
    $missing = array();

    foreach ((array) $emails as $email) {
        if (!is_object($email) || empty($email->template_html)) {
            continue;
        }

        $template_file = (string) $email->template_html;
        $template_base = !empty($email->template_base) ? trailingslashit((string) $email->template_base) : '';
        $path = $template_base !== '' ? $template_base . ltrim($template_file, '/\\') : '';
        $checked++;

        if ($path === '' || !file_exists($path) || !is_readable($path)) {
            $email_id = !empty($email->id) ? (string) $email->id : get_class($email);
            $missing[] = $email_id . ' → ' . ($path !== '' ? $path : $template_file);
        }
    }

    if ($checked === 0) {
        return array(
            'passed'   => false,
            'severity' => 'warning',
            'detail'   => 'No se han encontrado plantillas HTML de correo para comprobar.',
        );
    }

    if (!empty($missing)) {
        $visible = array_slice($missing, 0, 4);
        $detail = 'Plantillas no resolubles: ' . number_format_i18n(count($missing)) . ' de ' . number_format_i18n($checked) . '. ' . implode(' | ', $visible);
        if (count($missing) > count($visible)) {
            $detail .= ' | … +' . (count($missing) - count($visible));
        }
        return array(
            'passed'   => false,
            'severity' => 'ko',
            'detail'   => $detail,
        );
    }

    return array(
        'passed'   => true,
        'severity' => 'ok',
        'detail'   => 'Plantillas HTML resolubles: ' . number_format_i18n($checked) . '.',
    );
}

function seo_core_system_test_technical() {
    global $wpdb;

    $results = array();
    $upload_dir = wp_get_upload_dir();
    $cron_stats = seo_core_system_test_cron_stats();
    $autoload_size = (float) $wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes', 'on', 'auto-on', 'auto')");

    $results[] = seo_core_system_test_result('technical', '7.1 WordPress cargado', function_exists('get_bloginfo') && function_exists('get_option'), get_bloginfo('version'));
    $results[] = seo_core_system_test_result('technical', '7.2 Uploads accesible', !empty($upload_dir['basedir']) && is_dir($upload_dir['basedir']) && is_readable($upload_dir['basedir']), !empty($upload_dir['basedir']) ? $upload_dir['basedir'] : 'No detectado');
    $results[] = seo_core_system_test_result('technical', '7.3 WP-Cron sin retrasos importantes', $cron_stats['overdue'] <= 20, 'Eventos atrasados: ' . number_format_i18n($cron_stats['overdue']), $cron_stats['overdue'] <= 20 ? 'ok' : 'warning');
    $results[] = seo_core_system_test_result('technical', '7.4 WP_DEBUG controlado', !(defined('WP_DEBUG') && WP_DEBUG), defined('WP_DEBUG') && WP_DEBUG ? 'WP_DEBUG activo' : 'WP_DEBUG inactivo', defined('WP_DEBUG') && WP_DEBUG ? 'warning' : 'ok');
    $results[] = seo_core_system_test_result('technical', '7.5 Autoload razonable', $autoload_size <= 10485760, seo_core_system_test_format_bytes($autoload_size), $autoload_size <= 10485760 ? 'ok' : 'warning');
    $results[] = seo_core_system_test_result('technical', '7.6 PHP compatible', version_compare(PHP_VERSION, '8.0', '>='), PHP_VERSION, version_compare(PHP_VERSION, '8.0', '>=') ? 'ok' : 'warning');


    if (function_exists('seo_core_system_test_action_scheduler_checks')) {
        $results = array_merge($results, seo_core_system_test_action_scheduler_checks());
    }

    return $results;
}

function seo_core_system_test_get_function_files() {
    return array(
        array('includes/bootstrap.php', true),
        array('includes/seo-admin.php', true),
        array('includes/seo-core.php', true),
        array('includes/seo-core-validation.php', true),
        array('includes/seo-core-validation-settings.php', true),
        array('includes/seo-core-validation-data-layer.php', true),
        array('includes/seo-core-validation-semantic.php', true),
        array('includes/seo-core-validation-visual.php', true),
        array('includes/seo-core-validation-billing.php', true),
        array('includes/data-layer/data-layer-bootstrap.php', true),
        array('includes/data-layer/class-seo-data-layer.php', true),
        array('includes/data-layer/class-seo-data-operation.php', true),
        array('includes/data-layer/class-seo-data-rollback.php', true),
        array('includes/seo-system-server-status.php', true),
        array('includes/seo-system-diagnostics-reporting.php', true),
        array('includes/seo-database-clean.php', true),
        array('includes/seo-search.php', true),
        array('includes/seo-reports.php', true),
        array('includes/seo-faq.php', true),
        array('includes/seo-export.php', true),
        array('includes/seo-import-suppliers.php', true),
        array('includes/import-export/bootstrap.php', true),
        array('includes/import-export/legacy-engine.php', true),
        array('includes/import-export/queue/batch.php', true),
        array('includes/import-export/suppliers/engine.php', true),
        array('includes/import-export/suppliers/connections.php', true),
        array('includes/import-export/suppliers/crawler-queue.php', true),
        array('includes/import-export/suppliers/xls-reader.php', true),
        array('includes/import-export/suppliers/recipes/import_amazon.php', true),
        array('includes/seo-dashboard.php', true),
        array('includes/category-admin.php', true),
        array('includes/product-page-admin.php', true),
        array('includes/pages-admin.php', true),
        array('includes/seo-images.php', true),
        array('includes/admin-redirects.php', true),
        array('includes/seo-marketing.php', true),
        array('includes/seo_aprendizaje_semantica.php', false),
        array('seo-taxonomy.php', true),
        array('functions.php', false),
    );
}

function seo_core_system_test_get_template_files() {
    return array(
        array('seo-system/templates/header.php', true),
        array('seo-system/templates/footer.php', true),
        array('seo-system/templates/styles_template.css', true),
        array('seo-system/templates/template-front.php', true),
        array('seo-system/templates/template-cluster.php', true),
        array('seo-system/templates/template-hub-primary.php', true),
        array('seo-system/templates/template-hub-secondary.php', true),
        array('seo-system/templates/template-category.php', true),
        array('seo-system/templates/template-product.php', true),
        array('seo-system/templates/template-page.php', true),
        array('seo-system/templates/template-post.php', true),
        array('seo-system/templates/template-search.php', true),
        array('seo-system/templates/template-cart.php', true),
        array('seo-system/templates/template-checkout.php', true),
        array('seo-system/templates/template-myaccount.php', true),
        array('seo-system/templates/template-thankyou.php', true),
        array('seo-system/templates/template-404.php', true),
        array('seo-system/templates/template-faq.php', false),
        array('seo-system/templates/faq-form.php', false),
        array('seo-system/templates/email-processing.php', false),
        array('seo-system/templates/email-completed.php', false),
        array('seo-system/templates/email-cancelled.php', false),
        array('seo-system/templates/email-refunded.php', false),
    );
}

function seo_core_system_test_get_plugin_root() {
    $dir = trailingslashit(dirname(__FILE__));
    $base = basename(untrailingslashit($dir));

    if ($base === 'includes') {
        return trailingslashit(dirname(untrailingslashit($dir)));
    }

    if (file_exists($dir . 'seo-taxonomy.php') || file_exists($dir . 'functions.php')) {
        return $dir;
    }

    $parent = trailingslashit(dirname(untrailingslashit($dir)));

    if (file_exists($parent . 'seo-taxonomy.php') || file_exists($parent . 'functions.php')) {
        return $parent;
    }

    return $dir;
}

function seo_core_system_test_count_available_files($plugin_root, $files) {
    $total = count($files);
    $found = 0;
    $missing = array();

    foreach ($files as $file) {
        $relative = $file[0];
        $required = !empty($file[1]);
        $path = trailingslashit($plugin_root) . ltrim($relative, '/');

        if (file_exists($path) && is_readable($path)) {
            $found++;
        } elseif ($required) {
            $missing[] = $relative;
        }
    }

    return array(
        'total'   => $total,
        'found'   => $found,
        'missing' => count($missing),
        'missing_list' => $missing,
    );
}

function seo_core_system_test_missing_suffix($status) {
    if (empty($status['missing_list'])) {
        return '';
    }

    return '. Faltan: ' . implode(', ', $status['missing_list']);
}

function seo_core_system_test_count_seo_nodes_by_role($role) {
    global $wpdb;

    $table = $wpdb->prefix . 'seo_nodes';

    if (!seo_core_system_test_table_exists($table)) {
        return 0;
    }

    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE seo_role = %s",
            $role
        )
    );
}

function seo_core_system_test_valid_page_id($page_id) {
    $page_id = (int) $page_id;

    if ($page_id <= 0) {
        return false;
    }

    $post = get_post($page_id);

    return $post && $post->post_status !== 'trash';
}

function seo_core_system_test_payment_gateways_count() {
    if (!class_exists('WooCommerce') || !function_exists('WC')) {
        return 0;
    }

    $payment_gateways = WC()->payment_gateways();

    if (!$payment_gateways || !method_exists($payment_gateways, 'get_available_payment_gateways')) {
        return 0;
    }

    return count($payment_gateways->get_available_payment_gateways());
}

function seo_core_system_test_shipping_methods_count() {
    if (!class_exists('WooCommerce') || !function_exists('WC')) {
        return 0;
    }

    $shipping = WC()->shipping();

    if (!$shipping || !method_exists($shipping, 'get_shipping_methods')) {
        return 0;
    }

    return count($shipping->get_shipping_methods());
}

/**
 * Metadatos de trazabilidad de una ejecución.
 *
 * Los timestamps se guardan en UTC. La presentación utiliza la zona horaria
 * configurada en WordPress mediante wp_date().
 */
function seo_core_system_test_build_run_metadata(
    $origin,
    $started_at,
    $started_microtime
) {
    $completed_at = time();
    $duration = max(0, microtime(true) - (float) $started_microtime);

    return array(
        'schema_version'   => 1,
        'origin'           => sanitize_key((string) $origin),
        'started_at'       => (int) $started_at,
        'completed_at'     => $completed_at,
        'duration_seconds' => round($duration, 3),
        'timezone'         => seo_core_system_test_timezone_label(),
        'plugin_version'   => seo_core_system_test_plugin_version(),
        'database_version' => (string) get_option('seo_system_db_version', ''),
        'test_version'     => defined('SEO_CORE_SYSTEM_TEST_VERSION')
            ? (string) SEO_CORE_SYSTEM_TEST_VERSION
            : 'desconocida',
        'wordpress_version' => (string) get_bloginfo('version'),
        'php_version'       => PHP_VERSION,
        'user_id'           => (int) get_current_user_id(),
    );
}

function seo_core_system_test_plugin_version() {
    foreach (array('SEO_SYSTEM_VERSION', 'SEO_PLUGIN_VERSION', 'SEO_SYSTEM_PLUGIN_VERSION') as $constant) {
        if (defined($constant) && constant($constant) !== '') {
            return (string) constant($constant);
        }
    }
    return 'desconocida';
}

function seo_core_system_test_timezone_label() {
    if (function_exists('wp_timezone_string')) {
        $timezone = wp_timezone_string();
        if ($timezone !== '') {
            return $timezone;
        }
    }

    $offset = (float) get_option('gmt_offset', 0);
    $hours = (int) $offset;
    $minutes = (int) round(abs($offset - $hours) * 60);
    return sprintf('UTC%+03d:%02d', $hours, $minutes);
}

function seo_core_system_test_origin_label($origin) {
    $labels = array(
        'manual'              => 'Validación manual',
        'manual_data_layer'   => 'Prueba manual del Data Layer',
        'manual_links_links'  => 'Auditoría manual de enlaces',
        'manual_links_seo'    => 'Auditoría manual de sitemap y redirecciones',
        'manual_links_resources' => 'Auditoría manual de recursos',
        'manual_links_reset'  => 'Reinicio manual de auditoría',
        'scheduled'           => 'Chequeo programado',
        'plugin_update'       => 'Chequeo tras actualización',
    );

    return isset($labels[$origin]) ? $labels[$origin] : ucwords(str_replace('_', ' ', (string) $origin));
}

function seo_core_system_test_format_duration($seconds) {
    $seconds = max(0, (float) $seconds);
    if ($seconds < 1) {
        return number_format_i18n($seconds * 1000, 0) . ' ms';
    }
    return number_format_i18n($seconds, 2) . ' s';
}

function seo_core_system_test_render_run_metadata($active_tab = 'summary') {
    $bundle = seo_core_system_test_get_results_bundle();
    $section = $active_tab === 'links_404' ? 'links' : 'general';
    $section_data = isset($bundle[$section]) && is_array($bundle[$section])
        ? $bundle[$section]
        : array();
    $metadata = isset($section_data['metadata']) && is_array($section_data['metadata'])
        ? $section_data['metadata']
        : array();

    $completed_at = isset($metadata['completed_at'])
        ? (int) $metadata['completed_at']
        : (isset($section_data['generated_at']) ? (int) $section_data['generated_at'] : 0);

    if ($completed_at <= 0) {
        echo '<div class="seo-core-run-meta">';
        echo '<div class="seo-core-run-meta-item seo-core-run-meta-main"><strong>Último chequeo</strong><span>No hay una ejecución guardada todavía.</span></div>';
        echo '</div>';
        return;
    }

    $age = max(0, time() - $completed_at);
    $css_class = $age > 30 * DAY_IN_SECONDS
        ? ' is-very-stale'
        : ($age > 7 * DAY_IN_SECONDS ? ' is-stale' : '');
    $date_format = trim((string) get_option('date_format'));
    $date_format = ($date_format !== '' ? $date_format : 'd/m/Y') . ' H:i:s';
    $local_date = wp_date($date_format, $completed_at, wp_timezone());
    $age_label = $age < 60
        ? 'hace menos de un minuto'
        : 'hace ' . human_time_diff($completed_at, time());

    echo '<div class="seo-core-run-meta' . esc_attr($css_class) . '">';
    echo '<div class="seo-core-run-meta-item seo-core-run-meta-main"><strong>Último chequeo</strong><span>' . esc_html($local_date) . ' · ' . esc_html($age_label) . '</span></div>';
    echo '<div class="seo-core-run-meta-item"><strong>Zona horaria</strong><span>' . esc_html($metadata['timezone'] ?? seo_core_system_test_timezone_label()) . '</span></div>';
    echo '<div class="seo-core-run-meta-item"><strong>Duración</strong><span>' . esc_html(seo_core_system_test_format_duration($metadata['duration_seconds'] ?? 0)) . '</span></div>';
    echo '<div class="seo-core-run-meta-item"><strong>Origen</strong><span>' . esc_html(seo_core_system_test_origin_label($metadata['origin'] ?? 'desconocido')) . '</span></div>';
    echo '<div class="seo-core-run-meta-item"><strong>Versiones</strong><span>Plugin ' . esc_html($metadata['plugin_version'] ?? 'desconocida') . ' · BBDD ' . esc_html($metadata['database_version'] ?? 'desconocida') . ' · Tests ' . esc_html($metadata['test_version'] ?? 'desconocida') . '</span></div>';
    echo '</div>';
}

/**
 * Nombre de la opcion donde se conservan los ultimos resultados.
 */
function seo_core_system_test_results_option_name() {
    return 'seo_core_system_test_v7_results';
}

function seo_core_system_test_get_results_bundle() {
    $bundle = get_option(seo_core_system_test_results_option_name(), array());
    return is_array($bundle) ? $bundle : array();
}


function seo_core_system_test_store_results_bundle($section, $results, $metadata = array()) {
    if (!in_array($section, array('general', 'links'), true) || !is_array($results)) {
        return false;
    }

    $bundle = seo_core_system_test_get_results_bundle();
    $before = seo_core_system_test_get_reporting_results($bundle);
    $before_ids = seo_core_system_test_incident_ids($before);

    if (!is_array($metadata) || empty($metadata)) {
        $now_microtime = microtime(true);
        $metadata = seo_core_system_test_build_run_metadata(
            $section === 'links' ? 'manual_links_reset' : 'manual',
            time(),
            $now_microtime
        );
    }

    $generated_at = isset($metadata['completed_at'])
        ? (int) $metadata['completed_at']
        : time();

    $bundle[$section] = array(
        'generated_at' => $generated_at,
        'metadata'     => $metadata,
        'results'      => array_values($results),
    );

    $after = seo_core_system_test_get_reporting_results($bundle);
    $after_ids = seo_core_system_test_incident_ids($after);
    $bundle['trend'] = array(
        'new'      => count(array_diff($after_ids, $before_ids)),
        'resolved' => count(array_diff($before_ids, $after_ids)),
        'changed_at' => time(),
    );
    $bundle['updated_at'] = time();

    $history = isset($bundle['history']) && is_array($bundle['history']) ? $bundle['history'] : array();
    $health = seo_core_system_test_health_summary($after);
    $history[] = array(
        'generated_at' => $generated_at,
        'section'      => $section,
        'origin'       => isset($metadata['origin']) ? (string) $metadata['origin'] : '',
        'duration_seconds' => isset($metadata['duration_seconds']) ? (float) $metadata['duration_seconds'] : 0,
        'plugin_version' => isset($metadata['plugin_version']) ? (string) $metadata['plugin_version'] : '',
        'database_version' => isset($metadata['database_version']) ? (string) $metadata['database_version'] : '',
        'test_version' => isset($metadata['test_version']) ? (string) $metadata['test_version'] : '',
        'score'        => $health['score'],
        'status'       => $health['status'],
        'critical'     => $health['critical'],
        'important'    => $health['important'],
        'warning'      => $health['warning'],
    );
    $bundle['history'] = array_slice($history, -20);

    return update_option(seo_core_system_test_results_option_name(), $bundle, false);
}

function seo_core_system_test_get_reporting_results($bundle = null) {
    if (!is_array($bundle)) {
        $bundle = seo_core_system_test_get_results_bundle();
    }

    $combined = array();
    foreach (array('general', 'links') as $section) {
        $rows = isset($bundle[$section]['results']) && is_array($bundle[$section]['results'])
            ? $bundle[$section]['results']
            : array();
        foreach ($rows as $result) {
            if (!is_array($result) || empty($result['label'])) {
                continue;
            }
            $key = (isset($result['group']) ? $result['group'] : '') . '|' . $result['label'];
            $combined[$key] = $result;
        }
    }

    // El chequeo visual es asíncrono: el callback remoto puede terminar después
    // de la validación PHP. Se mezcla aquí para que pantalla, JSON y PDF vean
    // siempre el último resultado recibido sin reejecutar toda la suite.
    if (!empty($combined) && function_exists('seo_core_system_test_visual_results')) {
        foreach ((array) seo_core_system_test_visual_results() as $result) {
            if (!is_array($result) || empty($result['label'])) {
                continue;
            }
            $key = (isset($result['group']) ? $result['group'] : '') . '|' . $result['label'];
            $combined[$key] = $result;
        }
    }

    return array_values($combined);
}

function seo_core_system_test_is_aggregate_result($result) {
    $label = isset($result['label']) ? (string) $result['label'] : '';
    foreach (array('8.13 ', '8.29 ', '9.1 ', '9.11 ', '9.12 ', '10.11 ') as $prefix) {
        if (strpos($label, $prefix) === 0) {
            return true;
        }
    }
    return false;
}

function seo_core_system_test_result_impact($result) {
    $status = isset($result['status']) ? (string) $result['status'] : '';
    if (in_array($status, array('not_evaluable', 'not_applicable', 'info', 'unknown'), true)) return 'info';
    if ($status === 'critical') return 'critical';
    $severity = isset($result['severity']) ? $result['severity'] : 'info';
    if ($severity === 'ok') return 'ok';
    if ($severity === 'warning') return 'warning';
    if ($severity !== 'ko') return 'info';
    if (!empty($result['items']) && is_array($result['items'])) {
        foreach ($result['items'] as $item) {
            $priority = isset($item['priority']) ? strtolower((string) $item['priority']) : '';
            if (in_array($priority, array('critica', 'crítica', 'critical'), true)) return 'critical';
        }
    }
    $group = isset($result['group']) ? $result['group'] : '';
    $label = isset($result['label']) ? (string) $result['label'] : '';
    $critical_prefixes = array('0.4 ', '0.7 ', '0.9 ', '0.11 ', '1.1 ', '1.2 ', '1.3 ', '1.4 ', '1.5 ', '2.', '4.2 ', '4.3 ', '5.', '6.1 ', '6.2 ', '8.1 ', '8.2 ', '8.4 ', '8.7 ', '8.9 ', '8.10 ', '8.11 ', '8.12 ', '8.24B ', '8.28 ', '9.4 ');
    foreach ($critical_prefixes as $prefix) if (strpos($label, $prefix) === 0) return 'critical';
    if (in_array($group, array('checkout', 'emails'), true)) return 'critical';
    return 'important';
}


function seo_core_system_test_extract_incidents($results, $limit = 100) {
    $incidents = array();
    foreach ((array) $results as $result) {
        if (!is_array($result) || seo_core_system_test_is_aggregate_result($result)) continue;
        if (in_array((string) ($result['status'] ?? ''), array('not_evaluable', 'not_applicable'), true)) continue;
        $impact = seo_core_system_test_result_impact($result);
        if (!in_array($impact, array('critical', 'important', 'warning'), true)) continue;
        $items = !empty($result['items']) && is_array($result['items']) ? $result['items'] : array(null);
        foreach ($items as $item) {
            $url = is_array($item) && isset($item['url']) ? (string) $item['url'] : '';
            $detail = is_array($item) && !empty($item['detail']) ? (string) $item['detail'] : (string) ($result['detail'] ?? '');
            $origin = is_array($item) && isset($item['origin']) ? (string) $item['origin'] : '';
            $item_status = is_array($item) && isset($item['status']) ? (string) $item['status'] : (string) ($result['status'] ?? '');
            $priority = is_array($item) && isset($item['priority']) ? (string) $item['priority'] : $impact;
            $item_impact = $impact; $normalized_priority = strtolower($priority);
            if (in_array($normalized_priority, array('critica', 'crítica', 'critical'), true)) $item_impact = 'critical';
            elseif (in_array($normalized_priority, array('alta', 'high'), true)) $item_impact = 'important';
            elseif (in_array($normalized_priority, array('media', 'baja', 'medium', 'low'), true) && $impact !== 'critical') $item_impact = 'warning';
            $seed = ($result['group'] ?? '') . '|' . ($result['label'] ?? '') . '|' . $url . '|' . $item_status;
            $incidents[] = array(
                'id' => 'CORE-' . strtoupper(substr(sha1($seed), 0, 10)),
                'group' => (string) ($result['group'] ?? ''),
                'area' => (string) ($result['area'] ?? $result['group'] ?? ''),
                'label' => (string) ($result['label'] ?? ''),
                'impact' => $item_impact,
                'priority' => $priority,
                'status' => $item_status,
                'detail' => $detail,
                'url' => $url,
                'origin' => $origin,
                'owner' => (string) ($result['owner'] ?? ''),
                'root_cause_id' => (string) ($result['root_cause_id'] ?? ''),
                'coverage' => (int) ($result['coverage'] ?? 100),
                'confidence' => (int) ($result['confidence'] ?? 90),
                'evidence' => isset($result['evidence']) && is_array($result['evidence']) ? $result['evidence'] : array(),
                'remediation' => isset($result['remediation']) && is_array($result['remediation']) ? $result['remediation'] : array(),
            );
            if (count($incidents) >= $limit) break 2;
        }
    }
    $order = array('critical' => 0, 'important' => 1, 'warning' => 2);
    usort($incidents, static function ($a, $b) use ($order) { return ($order[$a['impact']] ?? 9) <=> ($order[$b['impact']] ?? 9); });
    return $incidents;
}


function seo_core_system_test_incident_ids($results) {
    return array_values(array_unique(array_map(static function ($incident) {
        return $incident['id'];
    }, seo_core_system_test_extract_incidents($results, 500))));
}

function seo_core_system_test_health_summary($results) {
    $counts = array('critical' => 0, 'important' => 0, 'warning' => 0, 'ok' => 0, 'info' => 0, 'not_evaluable' => 0, 'not_applicable' => 0, 'unknown' => 0);
    $confidence_values = array();
    foreach ((array) $results as $result) {
        if (!is_array($result) || seo_core_system_test_is_aggregate_result($result)) continue;
        $status = (string) ($result['status'] ?? '');
        if (isset($counts[$status]) && in_array($status, array('not_evaluable', 'not_applicable', 'unknown'), true)) { $counts[$status]++; continue; }
        $impact = seo_core_system_test_result_impact($result);
        $units = 1;
        if (in_array($impact, array('critical', 'important', 'warning'), true) && !empty($result['items']) && is_array($result['items'])) $units = max(1, count($result['items']));
        $counts[$impact] = isset($counts[$impact]) ? $counts[$impact] + $units : $units;
        if (isset($result['confidence'])) $confidence_values[] = (int) $result['confidence'];
    }
    $actionable = $counts['critical'] + $counts['important'] + $counts['warning'] + $counts['ok'];
    $expected = $actionable + $counts['not_evaluable'] + $counts['unknown'];
    $penalty = ($counts['critical'] * 5) + ($counts['important'] * 3) + $counts['warning'];
    $score = $actionable > 0 ? max(0, (int) round(100 - (($penalty / ($actionable * 5)) * 100))) : null;
    $status = $counts['critical'] > 0 ? 'critical' : ($counts['important'] > 0 ? 'important' : ($counts['warning'] > 0 ? 'warning' : ($counts['ok'] > 0 ? 'ok' : ($counts['not_evaluable'] > 0 ? 'not_evaluable' : 'info'))));
    return array_merge($counts, array(
        'total' => $actionable,
        'score' => $score,
        'status' => $status,
        'coverage' => $expected > 0 ? (int) round(($actionable / $expected) * 100) : 0,
        'confidence' => !empty($confidence_values) ? (int) round(array_sum($confidence_values) / count($confidence_values)) : 0,
    ));
}


function seo_core_system_test_groups_for_tab($active_tab) {
    if ($active_tab === 'code_integrity') {
        return array('code_integrity');
    }
    if ($active_tab === 'advanced') {
        return array(
            'functional',
            'links_404',
            'system',
            'templates',
            'catalog',
            'checkout',
            'emails',
            'seo_system',
            'technical',
            'data_layer',
            'semantic',
        );
    }
    return array();
}

function seo_core_system_test_results_for_tab($results, $active_tab) {
    $groups = seo_core_system_test_groups_for_tab($active_tab);
    if (empty($groups)) {
        return $results;
    }
    return array_values(array_filter((array) $results, static function ($result) use ($groups) {
        return isset($result['group']) && in_array($result['group'], $groups, true);
    }));
}



function seo_core_system_test_impact_label($impact) {
    $labels = array(
        'critical'  => 'Crítico',
        'important' => 'Importante',
        'warning'   => 'Aviso',
        'ok'        => 'Correcto',
        'info'      => 'Pendiente',
    );
    return $labels[$impact] ?? 'Info';
}

function seo_core_system_test_render_compact_health($results, $active_tab = 'summary') {
    $health = seo_core_system_test_health_summary($results);
    $tab_label = seo_core_system_test_tab_label($active_tab);
    $score_text = $health['score'] === null ? 'Pendiente' : seo_core_system_test_impact_label($health['status']) . ' · ' . (int) $health['score'] . '%';

    $scope_text = $active_tab === 'advanced'
        ? 'Reúne todos los chequeos que antes estaban repartidos en múltiples pestañas. Cada sección continúa incluida una sola vez dentro de SEO Core.'
        : 'Esta vista filtra únicamente la integridad del código. Su estado ya está incluido dentro de SEO Core del resumen global.';

    echo '<div class="seo-core-scope-note"><strong>' . esc_html($tab_label) . '</strong>' . esc_html($scope_text) . '</div>';
    echo '<div class="seo-core-health-strip">';
    echo '<div class="seo-core-health-box seo-core-health-' . esc_attr($health['status']) . '"><strong>Estado de esta vista</strong><div class="seo-core-health-value">' . esc_html($score_text) . '</div><span class="seo-core-test-muted">' . esc_html($tab_label) . '</span></div>';
    foreach (array('critical' => 'Mal', 'important' => 'Revisar pronto', 'warning' => 'Avisos', 'ok' => 'Bien') as $key => $label) {
        echo '<div class="seo-core-health-box seo-core-health-' . esc_attr($key) . '"><strong>' . esc_html($label) . '</strong><div class="seo-core-health-value">' . esc_html(number_format_i18n($health[$key])) . '</div></div>';
    }
    echo '</div>';
}



function seo_core_system_test_render_priority_issues($results, $limit = 12) {
    $incidents = seo_core_system_test_extract_incidents($results, $limit);
    if (empty($incidents)) {
        echo '<div class="seo-core-test-note"><strong>Sin incidencias prioritarias:</strong> los chequeos ejecutados no contienen errores críticos, importantes ni avisos.</div>';
        return;
    }

    echo '<h3>Qué requiere atención</h3><div class="seo-core-priority-list">';
    foreach ($incidents as $incident) {
        $detail = wp_strip_all_tags((string) $incident['detail']);
        if (function_exists('mb_strlen') && mb_strlen($detail) > 260) {
            $detail = mb_substr($detail, 0, 257) . '...';
        } elseif (strlen($detail) > 260) {
            $detail = substr($detail, 0, 257) . '...';
        }
        echo '<div class="seo-core-priority-item">';
        echo '<span class="seo-core-test-badge seo-core-health-' . esc_attr($incident['impact']) . '">' . esc_html(seo_core_system_test_impact_label($incident['impact'])) . '</span>';
        echo '<div><strong>' . esc_html($incident['label']) . '</strong><br><code>' . esc_html($incident['id']) . '</code></div>';
        echo '<div><div class="seo-core-test-muted">' . esc_html($detail) . '</div>';
        if (function_exists('seo_core_validation_render_remediation_details')) {
            seo_core_validation_render_remediation_details($incident['label'], $incident['evidence'] ?? array());
        }
        echo '</div></div>';
    }
    echo '</div>';
}

function seo_core_system_test_render_module_overview($results) {
    $modules = array(
        'Plugin' => array('code_integrity', 'system', 'templates', 'emails', 'seo_system'),
        'Operación' => array('catalog', 'checkout', 'technical'),
        'Funcionalidad' => array('functional'),
        'Experiencia visual' => array('visual'),
        'Enlaces y SEO' => array('links_404'),
        'Data Layer' => array('data_layer'),
        'Semántica' => array('semantic'),
    );
    echo '<h3>Desglose de SEO Core por área</h3><div class="seo-core-module-grid">';
    foreach ($modules as $title => $groups) {
        $rows = array_values(array_filter((array) $results, static function ($result) use ($groups) {
            return isset($result['group']) && in_array($result['group'], $groups, true);
        }));
        if (empty($rows)) {
            echo '<div class="seo-core-module-card"><h3>' . esc_html($title) . '</h3><div class="seo-core-module-score">Pendiente</div>' . seo_core_system_test_badge('info') . '</div>';
            continue;
        }
        $health = seo_core_system_test_health_summary($rows);
        $badge_severity = $health['status'] === 'critical' ? 'ko' : ($health['status'] === 'important' || $health['status'] === 'warning' ? 'warning' : ($health['status'] === 'ok' ? 'ok' : 'info'));
        echo '<div class="seo-core-module-card"><h3>' . esc_html($title) . '</h3><div class="seo-core-module-score">' . esc_html($health['score']) . '%</div>' . seo_core_system_test_badge($badge_severity) . '<p class="seo-core-test-muted">' . esc_html($health['critical']) . ' críticos · ' . esc_html($health['important']) . ' importantes · ' . esc_html($health['warning']) . ' avisos</p></div>';
    }
    echo '</div>';
}

function seo_core_system_test_get_reporting_snapshot() {
    $bundle = seo_core_system_test_get_results_bundle();
    $results = seo_core_system_test_get_reporting_results($bundle);
    $health = seo_core_system_test_health_summary($results);
    $checks = array();
    foreach ($results as $result) {
        if (!is_array($result)) continue;
        $checks[] = array(
            'group' => (string) ($result['group'] ?? ''),
            'area' => (string) ($result['area'] ?? ''),
            'label' => (string) ($result['label'] ?? ''),
            'status' => (string) ($result['status'] ?? ''),
            'severity' => (string) ($result['severity'] ?? ''),
            'detail' => (string) ($result['detail'] ?? ''),
            'owner' => (string) ($result['owner'] ?? ''),
            'blocked_by' => (string) ($result['blocked_by'] ?? ''),
            'root_cause_id' => (string) ($result['root_cause_id'] ?? ''),
            'coverage' => (int) ($result['coverage'] ?? 100),
            'confidence' => (int) ($result['confidence'] ?? 90),
            'evidence' => isset($result['evidence']) && is_array($result['evidence']) ? $result['evidence'] : array(),
            'remediation' => isset($result['remediation']) && is_array($result['remediation']) ? $result['remediation'] : array(),
        );
    }
    return array(
        'schema_version' => 4,
        'generated_at' => isset($bundle['updated_at']) ? (int) $bundle['updated_at'] : 0,
        'run_metadata' => isset($bundle['general']['metadata']) && is_array($bundle['general']['metadata']) ? $bundle['general']['metadata'] : array(),
        'health' => $health,
        'trend' => isset($bundle['trend']) && is_array($bundle['trend']) ? $bundle['trend'] : array('new' => 0, 'resolved' => 0),
        'checks' => $checks,
        'incidents' => seo_core_system_test_extract_incidents($results, 250),
        'semantic' => function_exists('seo_core_system_test_get_semantic_snapshot') ? seo_core_system_test_get_semantic_snapshot() : array(),
        'has_general' => !empty($bundle['general']['results']),
        'has_links' => !empty($bundle['links']['results']),
    );
}


function seo_core_system_test_result($group, $label, $passed, $detail = '', $severity = '', $meta = array()) {
    if ($severity === '') $severity = $passed ? 'ok' : 'ko';
    $meta = is_array($meta) ? $meta : array();
    $status = isset($meta['status']) ? sanitize_key((string) $meta['status']) : seo_core_system_test_default_status($severity, $passed);
    $allowed_statuses = array('pass', 'warning', 'fail', 'critical', 'info', 'not_evaluable', 'not_applicable', 'unknown');
    if (!in_array($status, $allowed_statuses, true)) $status = 'unknown';
    $coverage = array_key_exists('coverage', $meta) ? max(0, min(100, (int) $meta['coverage'])) : (in_array($status, array('not_evaluable', 'not_applicable'), true) ? 0 : 100);
    $confidence = array_key_exists('confidence', $meta) ? max(0, min(100, (int) $meta['confidence'])) : (in_array($status, array('not_evaluable', 'unknown'), true) ? 0 : 90);

    $result = array(
        'group' => $group,
        'area' => isset($meta['area']) ? sanitize_key((string) $meta['area']) : sanitize_key((string) $group),
        'label' => $label,
        'passed' => (bool) $passed,
        'severity' => $severity,
        'status' => $status,
        'detail' => $detail,
        'owner' => isset($meta['owner']) ? (string) $meta['owner'] : seo_core_system_test_owner_for_result($group, $label),
        'blocked_by' => isset($meta['blocked_by']) ? sanitize_key((string) $meta['blocked_by']) : '',
        'root_cause_id' => isset($meta['root_cause_id']) ? sanitize_key((string) $meta['root_cause_id']) : '',
        'coverage' => $coverage,
        'confidence' => $confidence,
        'evidence' => isset($meta['evidence']) && is_array($meta['evidence']) ? $meta['evidence'] : array(),
        'remediation' => isset($meta['remediation']) && is_array($meta['remediation'])
            ? $meta['remediation']
            : (function_exists('seo_core_validation_remediation_for_label') ? seo_core_validation_remediation_for_label($label) : array()),
    );
    foreach (array('items', 'priority', 'status_code') as $key) {
        if (array_key_exists($key, $meta)) $result[$key] = $meta[$key];
    }
    return $result;
}


function seo_core_system_test_table_exists($table_name) {
    global $wpdb;

    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));

    return $found === $table_name;
}

function seo_core_system_test_cron_stats() {
    $cron = _get_cron_array();
    $total = 0;
    $overdue = 0;
    $now = time();

    if (!is_array($cron)) {
        return array('total' => 0, 'overdue' => 0);
    }

    foreach ($cron as $timestamp => $hooks) {
        foreach ($hooks as $events) {
            $total += count($events);
        }

        if ((int) $timestamp < ($now - 900)) {
            foreach ($hooks as $events) {
                $overdue += count($events);
            }
        }
    }

    return array('total' => $total, 'overdue' => $overdue);
}

function seo_core_system_test_format_bytes($bytes) {
    $bytes = (float) $bytes;

    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 2) . ' GB';
    }

    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    }

    if ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    }

    return round($bytes, 2) . ' B';
}

function seo_core_system_test_render_results($results, $active_tab) {
    $summary = seo_core_system_test_get_summary($results);
    $visible_results = seo_core_system_test_results_for_tab($results, $active_tab);

    if (in_array($active_tab, array('code_integrity', 'advanced'), true)) {
        seo_core_system_test_render_compact_health($visible_results, $active_tab);
    }

    if ($active_tab === 'summary') {
        seo_core_system_test_render_summary($summary, $results);
        return;
    }

    if ($active_tab === 'code_integrity') {
        seo_core_system_test_render_code_integrity($results);
        return;
    }

    if ($active_tab === 'advanced') {
        seo_core_system_test_render_advanced_checks($results);
        return;
    }

    seo_core_system_test_render_summary($summary, $results);
}

function seo_core_system_test_render_advanced_checks($results) {
    $requested = seo_core_system_test_get_requested_section();
    $bundles = array(
        'Funcionamiento, tienda y enlaces' => array(
            'functional',
            'visual',
            'system',
            'templates',
            'catalog',
            'checkout',
            'emails',
            'technical',
            'links_404',
        ),
        'Datos, SEO y contenido' => array(
            'seo_system',
            'data_layer',
            'semantic',
        ),
    );

    echo '<h2>Chequeos avanzados</h2>';
    echo '<p>Los chequeos no se han eliminado: se han concentrado en dos bloques. Abre únicamente el área que necesites revisar; PDF y JSON siguen incluyendo todos los resultados.</p>';

    foreach ($bundles as $bundle_title => $groups) {
        $bundle_rows = array_values(array_filter((array) $results, static function ($result) use ($groups) {
            return isset($result['group']) && in_array($result['group'], $groups, true);
        }));
        $bundle_health = seo_core_system_test_health_summary($bundle_rows);
        $bundle_score = $bundle_health['score'] === null ? 'Pendiente' : (int) $bundle_health['score'] . '%';
        $bundle_issues = (int) $bundle_health['critical'] + (int) $bundle_health['important'] + (int) $bundle_health['warning'];

        echo '<section class="seo-core-advanced-bundle">';
        echo '<div class="seo-core-advanced-heading"><div><h3>' . esc_html($bundle_title) . '</h3><p>Resultados agrupados; cada antiguo apartado se abre por separado.</p></div><div><strong>' . esc_html($bundle_score) . '</strong><span>' . esc_html(number_format_i18n($bundle_issues)) . ' incidencias</span></div></div>';

        foreach ($groups as $group) {
            seo_core_system_test_render_advanced_group($results, $group, $requested === $group);
        }
        echo '</section>';
    }
}

function seo_core_system_test_render_advanced_group($results, $group, $open = false) {
    $rows = array_values(array_filter((array) $results, static function ($result) use ($group) {
        return isset($result['group']) && $result['group'] === $group;
    }));
    $health = seo_core_system_test_health_summary($rows);
    $score = $health['score'] === null ? 'Pendiente' : (int) $health['score'] . '%';
    $issues = (int) $health['critical'] + (int) $health['important'] + (int) $health['warning'];
    $summary = seo_core_system_test_tab_label($group) . ' · ' . $score . ' · ' . number_format_i18n($issues) . ' incidencias';

    echo '<details class="seo-core-test-details seo-core-advanced-group"' . ($open ? ' open' : '') . '>';
    echo '<summary><span>' . esc_html($summary) . '</span><small>' . esc_html(seo_core_system_test_tab_description($group)) . '</small></summary>';
    echo '<div class="seo-core-test-details-content">';

    if ($group === 'links_404') {
        seo_core_system_test_render_link_audit_controls();
    } elseif ($group === 'visual' && function_exists('seo_core_visual_render_controls')) {
        seo_core_visual_render_controls();
    } elseif ($group === 'data_layer') {
        seo_core_system_test_render_data_layer_controls();
        if (function_exists('seo_core_system_test_render_data_layer_intro')) {
            seo_core_system_test_render_data_layer_intro();
        }
    }

    if (empty($rows)) {
        echo '<p class="seo-core-test-empty">Todavía no hay resultados para esta sección.</p>';
    } else {
        seo_core_system_test_render_group($rows, $group, false);
        if ($group === 'links_404') {
            seo_core_system_test_render_links_404_items($rows);
        }
    }

    echo '</div>';
    echo '</details>';
}

function seo_core_system_test_render_links_404_items($group_results) {
    foreach ((array) $group_results as $result) {
        if (empty($result['items']) || !is_array($result['items'])) {
            continue;
        }
        echo '<details class="seo-core-test-details">';
        echo '<summary>' . esc_html($result['label']) . ': detalle de incidencias (' . number_format_i18n(count($result['items'])) . ')</summary>';
        echo '<div class="seo-core-test-details-content"><div class="seo-core-test-table-wrap">';
        echo '<table class="seo-core-test-table"><thead><tr><th>Prioridad</th><th>Estado</th><th>URL</th><th>Origen</th><th>Detalle</th></tr></thead><tbody>';
        foreach ($result['items'] as $item) {
            echo '<tr>';
            echo '<td><strong>' . esc_html(ucfirst((string) ($item['priority'] ?? 'baja'))) . '</strong></td>';
            echo '<td>' . esc_html((string) ($item['status'] ?? '')) . '</td>';
            echo '<td class="seo-core-test-path"><code>' . esc_html((string) ($item['url'] ?? '')) . '</code></td>';
            echo '<td>' . esc_html((string) ($item['origin'] ?? '')) . '</td>';
            echo '<td class="seo-core-test-muted">' . esc_html((string) ($item['detail'] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div></details>';
    }
}



function seo_core_system_test_render_functional_business($results) {
    $functional_results = array_values(array_filter(
        $results,
        static function ($result) {
            return isset($result['group']) && $result['group'] === 'functional';
        }
    ));

    $counts = array('ok' => 0, 'warning' => 0, 'ko' => 0, 'info' => 0);
    foreach ($functional_results as $result) {
        $severity = isset($result['severity']) ? $result['severity'] : 'info';
        if (!isset($counts[$severity])) {
            $severity = 'info';
        }
        $counts[$severity]++;
    }

    echo '<h2>Funcionalidad del negocio</h2>';
    echo '<p>Simulación de navegación pública, calidad HTML/SEO, recursos, redirecciones y salud comercial sobre elementos representativos.</p>';

    echo '<div class="seo-core-test-grid">';
    seo_core_system_test_summary_card('Pruebas funcionales', count($functional_results), 'info');
    seo_core_system_test_summary_card('OK', $counts['ok'], 'ok');
    seo_core_system_test_summary_card('Avisos', $counts['warning'], 'warning');
    seo_core_system_test_summary_card('KO', $counts['ko'], 'ko');
    echo '</div>';

    echo '<div class="seo-core-test-note">';
    echo '<strong>Modo seguro:</strong> las solicitudes son GET y el test no añade productos al carrito, no crea pedidos, no procesa pagos y no modifica registros. La validación general no ejecuta la auditoría 404 completa; esa comprobación se lanza desde la sección Enlaces y 404 de Chequeos avanzados. Las páginas de verificación del firewall o antibot se clasifican como aviso no concluyente; un error HTTP real sin firma de protección continúa siendo KO.';
    echo '</div>';

    seo_core_system_test_render_group($results, 'functional', false);
}


function seo_core_system_test_render_links_404($results) {
    $group_results = array_values(array_filter(
        $results,
        static function ($result) {
            return isset($result['group']) && $result['group'] === 'links_404';
        }
    ));
    $counts = array('ok' => 0, 'warning' => 0, 'ko' => 0, 'info' => 0);
    foreach ($group_results as $result) {
        $severity = isset($result['severity']) && isset($counts[$result['severity']]) ? $result['severity'] : 'info';
        $counts[$severity]++;
    }

    echo '<h2>Enlaces y errores 404</h2>';
    echo '<p>Auditoría de enlaces internos, URLs publicadas en sitemap, reglas de redirección, imágenes y recursos locales. Cada incidencia conserva uno o varios orígenes para facilitar su reparación.</p>';
    echo '<div class="seo-core-test-grid">';
    seo_core_system_test_summary_card('Pruebas de enlaces', count($group_results), 'info');
    seo_core_system_test_summary_card('OK', $counts['ok'], 'ok');
    seo_core_system_test_summary_card('Avisos', $counts['warning'], 'warning');
    seo_core_system_test_summary_card('KO', $counts['ko'], 'ko');
    echo '</div>';
    echo '<div class="seo-core-test-note"><strong>Modo seguro:</strong> no corrige ni crea redirecciones automáticamente. Omite enlaces de cierre de sesión, acciones de carrito, nonces y otras URLs GET que podrían alterar una sesión. Cada bloque tiene un límite estricto, se guarda por separado y puede repetirse. Una comprobación pendiente se muestra como Info, no como Aviso ni como falso OK.</div>';

    seo_core_system_test_render_group($results, 'links_404', false);

    foreach ($group_results as $result) {
        if (empty($result['items']) || !is_array($result['items'])) {
            continue;
        }
        echo '<details class="seo-core-test-details">';
        echo '<summary>' . esc_html($result['label']) . ': detalle de incidencias (' . number_format_i18n(count($result['items'])) . ')</summary>';
        echo '<div class="seo-core-test-details-content"><div class="seo-core-test-table-wrap">';
        echo '<table class="seo-core-test-table"><thead><tr><th>Prioridad</th><th>Estado</th><th>URL</th><th>Origen</th><th>Detalle</th></tr></thead><tbody>';
        foreach ($result['items'] as $item) {
            echo '<tr>';
            echo '<td><strong>' . esc_html(ucfirst((string) (isset($item['priority']) ? $item['priority'] : 'baja'))) . '</strong></td>';
            echo '<td>' . esc_html((string) (isset($item['status']) ? $item['status'] : '')) . '</td>';
            echo '<td class="seo-core-test-path"><code>' . esc_html((string) (isset($item['url']) ? $item['url'] : '')) . '</code></td>';
            echo '<td>' . esc_html((string) (isset($item['origin']) ? $item['origin'] : '')) . '</td>';
            echo '<td class="seo-core-test-muted">' . esc_html((string) (isset($item['detail']) ? $item['detail'] : '')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div></details>';
    }
}

function seo_core_system_test_render_code_integrity($results) {
    $inventory = seo_core_system_test_get_code_inventory();

    echo '<h2>Integridad del código</h2>';
    echo '<p>Inventario estático de los archivos PHP propios del plugin. No ejecuta los archivos analizados: lee sus tokens para localizar declaraciones, hooks y posibles colisiones.</p>';

    echo '<div class="seo-core-test-grid">';
    seo_core_system_test_summary_card('Archivos PHP detectados', $inventory['file_count'], 'info');
    seo_core_system_test_summary_card('Funciones globales', count($inventory['functions']), 'info');
    seo_core_system_test_summary_card('Tipos', count($inventory['types']), 'info');
    seo_core_system_test_summary_card('Métodos', count($inventory['methods']), 'info');
    seo_core_system_test_summary_card('Hooks literales', count($inventory['hooks']), 'info');
    seo_core_system_test_summary_card('Puntos de entrada', count($inventory['entry_points']), 'info');
    seo_core_system_test_summary_card('Rutas REST', count($inventory['rest_routes']), 'info');
    seo_core_system_test_summary_card('Llamadas detectadas', count($inventory['function_calls']), 'info');
    seo_core_system_test_summary_card(
        'Sin referencias entrantes',
        count($inventory['unreferenced_functions']),
        empty($inventory['unreferenced_functions']) ? 'ok' : 'info'
    );
    seo_core_system_test_summary_card(
        'Duplicados críticos',
        count($inventory['duplicate_functions']) + count($inventory['duplicate_types']) + count($inventory['duplicate_methods']),
        empty($inventory['duplicate_functions']) && empty($inventory['duplicate_types']) && empty($inventory['duplicate_methods']) ? 'ok' : 'ko'
    );
    echo '</div>';

    echo '<div class="seo-core-test-note">';
    echo '<strong>Alcance V7.0:</strong> detecta llamadas directas, callbacks, hooks de WordPress, AJAX, shortcodes y hooks de activación. La excepción manual de <code>seo_taxonomy_install</code> se clasifica como Activation Hook y no se considera código huérfano.';
    echo '</div>';

    seo_core_system_test_render_group($results, 'code_integrity', false);

    seo_core_system_test_render_issue_details('Errores de sintaxis', $inventory['syntax_errors'], 'syntax');
    seo_core_system_test_render_file_list_details('Etiquetas PHP cortas', $inventory['short_open_tags']);
    seo_core_system_test_render_duplicate_details('Funciones globales duplicadas', $inventory['duplicate_functions']);
    seo_core_system_test_render_duplicate_details('Tipos duplicados', $inventory['duplicate_types']);
    seo_core_system_test_render_duplicate_details('Métodos duplicados dentro del mismo tipo', $inventory['duplicate_methods']);
    seo_core_system_test_render_callback_details($inventory['unresolved_callbacks']);
    seo_core_system_test_render_file_list_details('Archivos no legibles', $inventory['unreadable_files']);
    seo_core_system_test_render_file_list_details('Archivos omitidos', $inventory['skipped_files']);

    seo_core_system_test_render_inventory_table(
        'Funciones sin referencias entrantes (candidatas a revisión)',
        $inventory['unreferenced_functions'],
        array(
            'name' => 'Función',
            'file' => 'Archivo',
            'line' => 'Línea',
            'direct_calls' => 'Llamadas directas',
            'self_calls' => 'Recursivas',
            'status' => 'Estado'
        )
    );
    seo_core_system_test_render_inventory_table(
        'Mapa de dependencias de funciones',
        $inventory['function_dependencies'],
        array(
            'name' => 'Función',
            'file' => 'Definida en',
            'line' => 'Línea',
            'direct_calls' => 'Llamadas directas',
            'callback_references' => 'Callbacks',
            'incoming_references' => 'Referencias entrantes',
            'reference_types' => 'Tipo de referencia',
            'manual_exceptions' => 'Excepciones',
            'status' => 'Estado',
            'references' => 'Ubicaciones'
        )
    );

    seo_core_system_test_render_inventory_table(
        'Inventario de puntos de entrada',
        $inventory['entry_points'],
        array(
            'entry_type' => 'Tipo',
            'kind' => 'Registro',
            'hook' => 'Hook o evento',
            'callback' => 'Callback',
            'manual' => 'Excepción manual',
            'reason' => 'Motivo',
            'file' => 'Archivo',
            'line' => 'Línea'
        )
    );
    seo_core_system_test_render_inventory_table(
        'Inventario de rutas REST literales',
        $inventory['rest_routes'],
        array('route' => 'Ruta', 'kind' => 'Registro', 'file' => 'Archivo', 'line' => 'Línea')
    );
    seo_core_system_test_render_inventory_table(
        'Inventario de programaciones Cron',
        $inventory['cron_registrations'],
        array('hook' => 'Hook Cron', 'kind' => 'Registro', 'file' => 'Archivo', 'line' => 'Línea')
    );

    seo_core_system_test_render_inventory_table(
        'Inventario de funciones globales',
        $inventory['functions'],
        array('name' => 'Función', 'file' => 'Archivo', 'line' => 'Línea')
    );
    seo_core_system_test_render_inventory_table(
        'Inventario de clases, interfaces, traits y enums',
        $inventory['types'],
        array('type' => 'Tipo', 'name' => 'Nombre', 'file' => 'Archivo', 'line' => 'Línea')
    );
    seo_core_system_test_render_inventory_table(
        'Inventario de métodos',
        $inventory['methods'],
        array('name' => 'Método', 'file' => 'Archivo', 'line' => 'Línea')
    );
    seo_core_system_test_render_inventory_table(
        'Inventario de hooks literales',
        $inventory['hooks'],
        array('kind' => 'Registro', 'hook' => 'Hook', 'callback' => 'Callback literal', 'file' => 'Archivo', 'line' => 'Línea')
    );
    seo_core_system_test_render_inventory_table(
        'Inventario de archivos PHP',
        array_map(
            static function ($file) {
                return array('name' => $file);
            },
            $inventory['files']
        ),
        array('name' => 'Archivo')
    );


    if (function_exists('seo_core_system_test_render_persistence_inventory')) {
        seo_core_system_test_render_persistence_inventory();
    }

    seo_core_system_test_render_inventory_filter_script();
}

function seo_core_system_test_render_issue_details($title, $issues, $type) {
    if (empty($issues)) {
        return;
    }

    echo '<details class="seo-core-test-details" open>';
    echo '<summary>' . esc_html($title) . ' (' . esc_html(number_format_i18n(count($issues))) . ')</summary>';
    echo '<div class="seo-core-test-details-content"><ul>';

    foreach ($issues as $issue) {
        $line = !empty($issue['line']) ? ':' . (int) $issue['line'] : '';
        echo '<li><code>' . esc_html($issue['file'] . $line) . '</code>';
        if ($type === 'syntax' && !empty($issue['message'])) {
            echo ' — ' . esc_html($issue['message']);
        }
        echo '</li>';
    }

    echo '</ul></div></details>';
}

function seo_core_system_test_render_file_list_details($title, $files) {
    if (empty($files)) {
        return;
    }

    echo '<details class="seo-core-test-details" open>';
    echo '<summary>' . esc_html($title) . ' (' . esc_html(number_format_i18n(count($files))) . ')</summary>';
    echo '<div class="seo-core-test-details-content"><ul class="seo-core-test-code">';
    foreach ($files as $file) {
        echo '<li>' . esc_html($file) . '</li>';
    }
    echo '</ul></div></details>';
}

function seo_core_system_test_render_duplicate_details($title, $duplicates) {
    if (empty($duplicates)) {
        return;
    }

    echo '<details class="seo-core-test-details" open>';
    echo '<summary>' . esc_html($title) . ' (' . esc_html(number_format_i18n(count($duplicates))) . ')</summary>';
    echo '<div class="seo-core-test-details-content">';

    foreach ($duplicates as $occurrences) {
        if (empty($occurrences)) {
            continue;
        }

        echo '<h3><code>' . esc_html($occurrences[0]['name']) . '</code></h3>';
        echo '<ul class="seo-core-test-code">';
        foreach ($occurrences as $occurrence) {
            echo '<li>' . esc_html($occurrence['file'] . ':' . (int) $occurrence['line']);
            if (!empty($occurrence['type']) && $occurrence['type'] !== 'function' && $occurrence['type'] !== 'method') {
                echo ' — ' . esc_html($occurrence['type']);
            }
            echo '</li>';
        }
        echo '</ul>';
    }

    echo '</div></details>';
}

function seo_core_system_test_render_callback_details($callbacks) {
    if (empty($callbacks)) {
        return;
    }

    echo '<details class="seo-core-test-details" open>';
    echo '<summary>Callbacks literales no resueltos (' . esc_html(number_format_i18n(count($callbacks))) . ')</summary>';
    echo '<div class="seo-core-test-details-content"><table class="seo-core-test-table">';
    echo '<thead><tr><th>Callback</th><th>Hook</th><th>Registro</th><th>Ubicación</th></tr></thead><tbody>';

    foreach ($callbacks as $callback) {
        echo '<tr>';
        echo '<td><code>' . esc_html($callback['callback']) . '</code></td>';
        echo '<td><code>' . esc_html($callback['hook']) . '</code></td>';
        echo '<td>' . esc_html($callback['kind']) . '</td>';
        echo '<td class="seo-core-test-code">' . esc_html($callback['file'] . ':' . (int) $callback['line']) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div></details>';
}

function seo_core_system_test_render_inventory_table($title, $entries, $columns) {
    $table_id = 'seo-core-inventory-' . substr(md5($title), 0, 10);

    echo '<details class="seo-core-test-details">';
    echo '<summary>' . esc_html($title) . ' (' . esc_html(number_format_i18n(count($entries))) . ')</summary>';
    echo '<div class="seo-core-test-details-content">';

    if (empty($entries)) {
        echo '<p class="seo-core-test-empty">Sin elementos.</p>';
        echo '</div></details>';
        return;
    }

    echo '<input type="search" class="seo-core-test-search" data-seo-core-filter="' . esc_attr($table_id) . '" placeholder="Filtrar inventario...">';
    echo '<div class="seo-core-test-table-wrap">';
    echo '<table id="' . esc_attr($table_id) . '" class="seo-core-test-table seo-core-test-code">';
    echo '<thead><tr>';
    foreach ($columns as $label) {
        echo '<th>' . esc_html($label) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($entries as $entry) {
        echo '<tr>';
        foreach ($columns as $key => $label) {
            $value = isset($entry[$key]) ? $entry[$key] : '';
            if ($key === 'line' && $value !== '') {
                $value = (int) $value;
            }
            echo '<td class="seo-core-test-path">' . esc_html((string) $value) . '</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table></div></div></details>';
}

function seo_core_system_test_render_inventory_filter_script() {
    echo '<script>';
    echo '(function(){';
    echo 'document.querySelectorAll("[data-seo-core-filter]").forEach(function(input){';
    echo 'input.addEventListener("input",function(){';
    echo 'var table=document.getElementById(input.getAttribute("data-seo-core-filter"));';
    echo 'if(!table){return;}';
    echo 'var query=input.value.toLowerCase();';
    echo 'table.querySelectorAll("tbody tr").forEach(function(row){';
    echo 'row.style.display=row.textContent.toLowerCase().indexOf(query)!==-1?"":"none";';
    echo '});';
    echo '});';
    echo '});';
    echo '})();';
    echo '</script>';
}

function seo_core_system_test_get_summary($results) {
    $summary = array('total' => count($results), 'ok' => 0, 'warning' => 0, 'ko' => 0, 'info' => 0, 'not_evaluable' => 0);
    foreach ($results as $result) {
        if (($result['status'] ?? '') === 'not_evaluable') { $summary['not_evaluable']++; continue; }
        if ($result['severity'] === 'ok') $summary['ok']++;
        elseif ($result['severity'] === 'warning') $summary['warning']++;
        elseif ($result['severity'] === 'ko') $summary['ko']++;
        else $summary['info']++;
    }
    return $summary;
}


function seo_core_system_test_render_summary($summary, $results) {
    echo '<h2>Accesos principales</h2>';
    echo '<div class="seo-core-scope-note"><strong>Un resumen y tres destinos.</strong>Los indicadores superiores reúnen Servidor y SEO Core. Debajo solo eliges si quieres revisar la integridad del código, desplegar el resto de chequeos o ajustar sus tolerancias.</div>';

    $sections = array('code_integrity', 'advanced', 'settings');
    echo '<div class="seo-core-nav-grid">';
    foreach ($sections as $tab_id) {
        $url = add_query_arg('seo_core_test_tab', $tab_id);
        $extra_class = $tab_id === 'code_integrity' ? ' is-primary' : '';
        echo '<a class="seo-core-nav-card' . esc_attr($extra_class) . '" href="' . esc_url($url) . '"><strong>' . esc_html(seo_core_system_test_tab_label($tab_id)) . '</strong><span>' . esc_html(seo_core_system_test_tab_description($tab_id)) . '</span></a>';
    }
    echo '</div>';

    echo '<details class="seo-core-test-details seo-core-summary-report">';
    echo '<summary>Informe técnico en texto para desarrolladores</summary>';
    echo '<div class="seo-core-test-details-content">';
    seo_core_system_test_render_final_report($summary, $results);
    echo '</div>';
    echo '</details>';
}



function seo_core_system_test_summary_card($title, $value, $severity) {
    echo '<div class="seo-core-test-card">';
    echo '<h2>' . esc_html($title) . '</h2>';
    echo '<div class="seo-core-test-kpi">' . esc_html((string) $value) . '</div>';
    echo seo_core_system_test_badge($severity);
    echo '</div>';
}

function seo_core_system_test_render_business_report($results) {
    $structure = array(
        'code_integrity' => array(
            'title' => '0. Integridad del código',
            'items' => array(
                '0.1 Ruta del código detectada',
                '0.2 Archivos PHP inventariados',
                '0.3 Archivos PHP legibles',
                '0.4 Sintaxis PHP analizable',
                '0.5 Etiquetas PHP cortas',
                '0.6 Funciones globales inventariadas',
                '0.7 Funciones globales duplicadas',
                '0.8 Tipos inventariados',
                '0.9 Clases, interfaces, traits o enums duplicados',
                '0.10 Métodos inventariados',
                '0.11 Métodos duplicados dentro del mismo tipo',
                '0.12 Hooks literales inventariados',
                '0.13 Callbacks literales resolubles',
                '0.14 Acciones AJAX literales inventariadas',
                '0.15 Archivos omitidos durante el análisis',
                '0.16 Llamadas directas a funciones inventariadas',
                '0.17 Referencias de callback inventariadas',
                '0.18 Funciones sin referencias entrantes',
                '0.19 Puntos de entrada inventariados',
                '0.20 Excepciones manuales de puntos de entrada',
                '0.21 Rutas REST literales inventariadas',
                '0.22 Registros Cron literales inventariados',
            ),
        ),
        'system' => array(
            'title' => '1. Sistema general',
            'items' => array(
                '1.1 Ruta base del plugin detectada',
                '1.2 Directorio includes disponible',
                '1.3 Directorio de plantillas disponible',
                '1.4 Archivos de funciones disponibles',
                '1.5 Plantillas disponibles',
                '1.6 WooCommerce activo',
                '1.7 Subsistema Importar/Exportar cargado',
                '1.8 Importador de proveedores disponible',
                '1.9 Catalogo de proveedores disponible',
                '1.10 Acciones administrativas de proveedores registradas',
                '1.11 Importacion por lotes disponible',
                '1.12 Importador Amazon disponible',
                '1.13 Conexiones con proveedores disponibles',
                '1.14 Sincronizacion de proveedores integrada',
                '1.15 Dependencias del motor de proveedores disponibles',
            ),
        ),
        'templates' => array(
            'title' => '2. Carga de plantillas',
            'items' => array(
                '2.1 Plantilla cluster disponible',
                '2.2 Plantilla hub primario disponible',
                '2.3 Plantilla hub secundario disponible',
                '2.4 Plantilla categoría disponible',
                '2.5 Plantilla producto disponible',
                '2.6 Plantilla búsqueda disponible',
                '2.7 Plantilla carrito disponible',
                '2.8 Plantilla checkout disponible',
                '2.9 Plantilla 404 disponible',
                '2.10 CSS de plantillas disponible',
            ),
        ),
        'catalog' => array(
            'title' => '3. Catálogo',
            'items' => array(
                '3.1 Productos publicados disponibles',
                '3.2 Categorías de producto disponibles',
                '3.3 Páginas publicadas disponibles',
                '3.4 Clusters registrados',
                '3.5 Hubs primarios registrados',
                '3.6 Hubs secundarios registrados',
            ),
        ),
        'checkout' => array(
            'title' => '4. Compra',
            'items' => array(
                '4.1 Página de tienda disponible',
                '4.2 Carrito disponible',
                '4.3 Checkout disponible',
                '4.4 Métodos de pago activos',
                '4.5 Métodos de envío detectados',
                '4.6 Prueba real de compra',
                '4.7 Modulo de facturacion cargado',
                '4.8 Tabla documental preparada',
                '4.9 Datos comunes de empresa',
                '4.10 Series documentales coherentes',
                '4.11 Motor PDF disponible',
                '4.12 Hooks de factura y proforma',
                '4.13 Emails documentales resolubles',
                '4.14 Presupuestos de carrito preparados',
                '4.15 Configuracion documental coherente',
                '4.16 Integridad de documentos emitidos',
            ),
        ),
        'emails' => array(
            'title' => '5. Correos',
            'items' => array(
                '5.1 Email processing disponible',
                '5.2 Email completed disponible',
                '5.3 Email cancelled disponible',
                '5.4 Email refunded disponible',
            ),
        ),
        'seo_system' => array(
            'title' => '6. SEO System',
            'items' => array(
                '6.1 Tabla seo_nodes',
                '6.2 Tabla seo_relations',
                '6.3 Tabla seo_faq',
                '6.4 Tabla seo_redirects',
                '6.5 Tabla seo_proveedores_productos',
            ),
        ),
        'technical' => array(
            'title' => '7. Técnico',
            'items' => array(
                '7.1 WordPress cargado',
                '7.2 Uploads accesible',
                '7.3 WP-Cron sin retrasos importantes',
                '7.4 WP_DEBUG controlado',
                '7.5 Autoload razonable',
                '7.6 PHP compatible',
                '7.7 Action Scheduler disponible',
                '7.8 Action Scheduler pendientes controlados',
                '7.9 Action Scheduler sin fallos',
            ),
        ),
        'functional' => array(
            'title' => '8. Simulación funcional del negocio',
            'items' => array(
                '8.1 Portada navegable',
                '8.2 Tienda navegable',
                '8.3 Categoría representativa navegable',
                '8.4 Producto representativo navegable',
                '8.5 Buscador interno localiza un producto publicado',
                '8.6 Página de resultados de búsqueda navegable',
                '8.7 Plantilla de producto renderiza contenido',
                '8.8 Plantilla de categoría renderiza contenido',
                '8.9 Carrito navegable',
                '8.10 Checkout navegable',
                '8.11 Correos WooCommerce registrados',
                '8.12 Plantillas de correo resolubles',
                '8.13 Recorrido funcional básico del cliente',
                '8.14 Página 404 real',
                '8.15 Búsqueda sin resultados',
                '8.16 Títulos HTML válidos',
                '8.17 Canonical coherente',
                '8.18 Política meta robots',
                '8.19 Encabezados H1 de plantillas',
                '8.20 Datos estructurados JSON-LD',
                '8.21 Sitemap público',
                '8.22 Imagen principal del producto',
                '8.23 Recursos CSS y JavaScript esenciales',
                '8.24 Producto representativo vendible',
                '8.25 Enlaces internos esenciales',
                '8.26 Redirecciones registradas',
                '8.27 Rendimiento HTTP básico',
                '8.28 Respuestas sin errores PHP o SQL visibles',
                '8.29 Salud funcional ampliada',
            ),
        ),
        'links_404' => array(
            'title' => '9. Enlaces y errores 404',
            'items' => array(
                '9.1 Modo y límites de auditoría',
                '9.2 Fuentes públicas rastreadas',
                '9.3 Cobertura de enlaces internos',
                '9.4 Enlaces internos rotos',
                '9.5 Cadenas y bucles de redirección',
                '9.6 Posibles soft-404',
                '9.7 URLs publicadas en sitemap',
                '9.8 Redirecciones registradas',
                '9.9 Imágenes internas',
                '9.10 CSS y JavaScript locales',
                '9.11 Prioridad de reparación',
                '9.12 Salud de enlaces y 404',
            ),
        ),
        'visual' => array(
            'title' => '10. Responsive y calidad visual',
            'items' => array(
                '10.1 Navegador remoto configurado',
                '10.2 Ultima auditoria visual',
                '10.3 Cobertura movil, tablet y escritorio',
                '10.4 Viewport movil correctamente declarado',
                '10.5 Sin desbordamiento horizontal',
                '10.6 Elementos esenciales dentro de pantalla',
                '10.7 Texto visible sin recortes anormales',
                '10.8 Sin solapamientos anormales',
                '10.9 Imagenes visibles renderizadas',
                '10.10 Consola del navegador sin errores relevantes',
                '10.11 Salud visual responsive',
            ),
        ),
    );

    foreach ($structure as $group => $section) {
        echo '<div class="seo-core-test-section">';
        echo '<h2>' . esc_html($section['title']) . '</h2>';

        foreach ($section['items'] as $item_label) {
            $result = seo_core_system_test_find_result_by_label($results, $item_label);
            echo '<h3>' . esc_html($item_label) . '</h3>';

            if ($result) {
                echo '<p>' . seo_core_system_test_result_badge($result) . ' <span class="seo-core-test-muted">' . esc_html((string) $result['detail']) . '</span></p>';
            } else {
                echo '<p>' . seo_core_system_test_badge('info') . ' <span class="seo-core-test-muted">Pendiente de implementar.</span></p>';
            }
        }

        echo '</div>';
    }
}

function seo_core_system_test_find_result_by_label($results, $label) {
    foreach ($results as $result) {
        if ($result['label'] === $label) {
            return $result;
        }
    }

    return null;
}

function seo_core_system_test_render_group($results, $group, $show_heading = true) {
    $labels = array(
        'code_integrity' => 'Integridad del código',
        'system'         => 'Entorno del plugin',
        'templates'  => 'Plantillas',
        'catalog'    => 'Catálogo',
        'checkout'   => 'Compra',
        'emails'     => 'Correos',
        'seo_system' => 'Datos internos SEO Core',
        'technical'  => 'Técnico',
        'functional' => 'Funcionalidad',
        'visual' => 'Responsive y calidad visual',
        'links_404' => 'Enlaces y 404',
    );

    if ($show_heading) {
        echo '<h2>' . esc_html(isset($labels[$group]) ? $labels[$group] : $group) . '</h2>';
    }
    echo '<table class="seo-core-test-table">';
    echo '<thead><tr><th>Grupo</th><th>Test</th><th>Detalle</th><th>Resultado</th></tr></thead>';
    echo '<tbody>';

    foreach ($results as $result) {
        if ($result['group'] !== $group) {
            continue;
        }

        echo '<tr>';
        echo '<td>' . esc_html(seo_core_system_test_group_label($result['group'])) . '</td>';
        echo '<td><strong>' . esc_html($result['label']) . '</strong></td>';
        echo '<td><div class="seo-core-test-muted">' . esc_html((string) $result['detail']) . '</div>';
        if ($result['severity'] !== 'ok' && $result['severity'] !== 'info' && function_exists('seo_core_validation_render_remediation_details')) {
            seo_core_validation_render_remediation_details($result['label'], $result['evidence'] ?? array());
        } elseif (function_exists('seo_core_validation_debug_enabled') && seo_core_validation_debug_enabled() && !empty($result['evidence'])) {
            $debug_json = wp_json_encode($result['evidence'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            echo '<details class="seo-core-remediation-details"><summary>Evidencia de debug</summary><pre class="seo-core-debug-json">' . esc_html((string) $debug_json) . '</pre></details>';
        }
        if (strpos((string) $result['label'], '10.3A ') === 0 && function_exists('seo_core_system_test_render_product_excerpt_exports')) {
            seo_core_system_test_render_product_excerpt_exports($result);
        }
        echo '</td>';
        echo '<td>' . seo_core_system_test_result_badge($result) . '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
}

function seo_core_system_test_render_final_report($summary, $results) {
    $report = seo_core_system_test_build_final_report($summary, $results);

    echo '<h2>Informe final</h2>';
    echo '<p>Este bloque está pensado para el futuro envío por email al sysadmin. Prioriza KO y avisos; los OK quedan resumidos.</p>';
    echo '<textarea class="seo-core-test-report" readonly>' . esc_textarea($report) . '</textarea>';

    echo '<div class="seo-core-test-note">';
    echo '<strong>Criterio:</strong> si hay KO, el estado general será REVISAR. Si no hay KO pero sí avisos, será AVISOS. Si no hay incidencias, será CORRECTO.';
    echo '</div>';
}

function seo_core_system_test_build_final_report($summary, $results) {
    $site = home_url();
    $date = current_time('mysql');
    $status = 'CORRECTO';

    if ($summary['ko'] > 0) {
        $status = 'REVISAR';
    } elseif ($summary['warning'] > 0) {
        $status = 'AVISOS';
    }

    $lines = array();
    $lines[] = 'SEO Plugin Validation';
    $lines[] = 'Sitio: ' . $site;
    $lines[] = 'Fecha: ' . $date;
    $lines[] = 'Estado general: ' . $status;
    $lines[] = '';
    $lines[] = 'Resumen:';
    $lines[] = '- Tests ejecutados: ' . $summary['total'];
    $lines[] = '- OK: ' . $summary['ok'];
    $lines[] = '- Avisos: ' . $summary['warning'];
    $lines[] = '- KO: ' . $summary['ko'];
    $lines[] = '- Info: ' . $summary['info'];
    $lines[] = '';

    $problem_lines = array();

    foreach ($results as $result) {
        if ($result['severity'] === 'ok' || $result['severity'] === 'info') {
            continue;
        }

        $problem_lines[] = '[' . strtoupper($result['severity']) . '] ' . seo_core_system_test_group_label($result['group']) . ' - ' . $result['label'] . ' - ' . $result['detail'];
    }

    if (empty($problem_lines)) {
        $lines[] = 'Incidencias:';
        $lines[] = '- No se han detectado KO ni avisos.';
    } else {
        $lines[] = 'Incidencias y avisos:';
        foreach ($problem_lines as $problem_line) {
            $lines[] = '- ' . $problem_line;
        }
    }

    return implode("\n", $lines);
}

function seo_core_system_test_group_label($group) {
    $labels = array(
        'code_integrity' => 'Integridad del código',
        'system'         => 'Entorno del plugin',
        'templates'  => 'Plantillas',
        'catalog'    => 'Catálogo',
        'checkout'   => 'Compra',
        'emails'     => 'Correos',
        'seo_system' => 'Datos internos SEO Core',
        'technical'  => 'Técnico',
        'functional' => 'Funcionalidad',
        'visual' => 'Responsive y calidad visual',
        'links_404' => 'Enlaces y 404',
    );

    return isset($labels[$group]) ? $labels[$group] : $group;
}

function seo_core_system_test_result_badge($result) {
    $status = isset($result['status']) ? (string) $result['status'] : '';

    if ($status === 'not_evaluable') {
        return '<span class="seo-core-test-badge seo-core-test-info">No evaluable</span>';
    }
    if ($status === 'not_applicable') {
        return '<span class="seo-core-test-badge seo-core-test-info">No aplica</span>';
    }
    if ($status === 'unknown') {
        return '<span class="seo-core-test-badge seo-core-test-warning">Sin confirmar</span>';
    }

    return seo_core_system_test_badge(isset($result['severity']) ? $result['severity'] : 'info');
}

function seo_core_system_test_badge($severity) {
    $map = array(
        'ok'      => array('class' => 'seo-core-test-ok', 'label' => 'OK'),
        'warning' => array('class' => 'seo-core-test-warning', 'label' => 'Aviso'),
        'ko'      => array('class' => 'seo-core-test-ko', 'label' => 'KO'),
        'info'    => array('class' => 'seo-core-test-info', 'label' => 'Info'),
    );

    if (!isset($map[$severity])) {
        $severity = 'info';
    }

    return '<span class="seo-core-test-badge ' . esc_attr($map[$severity]['class']) . '">' . esc_html($map[$severity]['label']) . '</span>';
}

if (!defined('SEO_SYSTEM_DIAGNOSTICS_RECIPIENT')) {
    define('SEO_SYSTEM_DIAGNOSTICS_RECIPIENT', 'davidperezmartorell@gmail.com');
}

function seo_core_system_test_diagnostics_consent_option() {
    return 'seo_system_diagnostics_email_consent';
}

function seo_core_system_test_diagnostics_consent_enabled() {
    return (bool) get_option(seo_core_system_test_diagnostics_consent_option(), false);
}

function seo_core_system_test_handle_diagnostics_actions() {
    if (empty($_POST['seo_core_system_test_diagnostics_action'])) {
        return null;
    }

    if (!current_user_can('manage_options')) {
        return array('type' => 'error', 'message' => 'No tienes permisos para cambiar esta configuración.');
    }

    if (!check_admin_referer('seo_core_system_test_diagnostics', 'seo_core_system_test_diagnostics_nonce')) {
        return array('type' => 'error', 'message' => 'La comprobación de seguridad ha caducado. Recarga la página e inténtalo de nuevo.');
    }

    $action = sanitize_key(wp_unslash($_POST['seo_core_system_test_diagnostics_action']));
    $consent = !empty($_POST['seo_core_system_test_diagnostics_consent']);
    update_option(seo_core_system_test_diagnostics_consent_option(), $consent ? '1' : '0', false);

    if ($action === 'save') {
        return array(
            'type' => 'success',
            'message' => $consent
                ? 'Consentimiento guardado. A partir de ahora, cada ejecución completada del diagnóstico enviará automáticamente un resumen técnico.'
                : 'Consentimiento desactivado. No se enviarán diagnósticos al desarrollador.',
        );
    }

    if ($action !== 'send') {
        return array('type' => 'error', 'message' => 'Acción de diagnóstico no reconocida.');
    }

    if (!$consent) {
        return array('type' => 'error', 'message' => 'Debes marcar la autorización antes de enviar el diagnóstico.');
    }

    $results = seo_core_system_test_get_reporting_results();
    if (empty($results)) {
        return array('type' => 'error', 'message' => 'No hay resultados guardados. Ejecuta primero la validación completa.');
    }

    if (function_exists('seo_system_diagnostics_send_current_report')) {
        $send_result = seo_system_diagnostics_send_current_report('manual_button', true);
        $sent = !empty($send_result['sent']);
    } else {
        $summary = seo_core_system_test_get_summary($results);
        $report = seo_core_system_test_build_final_report($summary, $results);
        $subject = sprintf(
            '[SEO Plugin Validation] %s - %s',
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            $summary['ko'] > 0 ? 'REVISAR' : ($summary['warning'] > 0 ? 'AVISOS' : 'CORRECTO')
        );
        $headers = array('Content-Type: text/plain; charset=UTF-8');
        $sent = wp_mail(SEO_SYSTEM_DIAGNOSTICS_RECIPIENT, $subject, $report, $headers);
    }

    return array(
        'type' => $sent ? 'success' : 'error',
        'message' => $sent
            ? 'Diagnóstico enviado correctamente al desarrollador.'
            : 'WordPress no pudo enviar el correo. Revisa la configuración SMTP o el registro de correo del servidor.',
    );
}

function seo_core_system_test_render_diagnostics_notice($notice) {
    if (!is_array($notice) || empty($notice['message'])) {
        return;
    }

    $class = isset($notice['type']) && $notice['type'] === 'success' ? 'notice-success' : 'notice-error';
    echo '<div class="notice ' . esc_attr($class) . ' is-dismissible seo-core-diagnostics-notice"><p>' . esc_html($notice['message']) . '</p></div>';
}

function seo_core_system_test_render_diagnostics_controls($results = null, $compact = false) {
    $consent = seo_core_system_test_diagnostics_consent_enabled();
    $has_results = !empty($results) || !empty(seo_core_system_test_get_reporting_results());
    $status_text = $consent ? 'activado' : 'desactivado';
    $recipient = function_exists('seo_system_diagnostics_recipient')
        ? seo_system_diagnostics_recipient()
        : (defined('SEO_SYSTEM_DIAGNOSTICS_RECIPIENT') ? sanitize_email((string) SEO_SYSTEM_DIAGNOSTICS_RECIPIENT) : '');

    if ($compact) {
        echo '<details class="seo-core-diagnostics-compact">';
        echo '<summary>Envío opcional al desarrollador: <strong>' . esc_html($status_text) . '</strong></summary>';
        echo '<div class="seo-core-diagnostics-compact-body">';
    } else {
        echo '<section class="seo-core-diagnostics-consent" aria-labelledby="seo-core-diagnostics-title">';
        echo '<h2 id="seo-core-diagnostics-title">Envío opcional de diagnósticos al desarrollador</h2>';
    }

    echo '<p>Esta función es voluntaria y está desactivada hasta que un administrador del sitio la autoriza. Sirve únicamente para recibir información técnica que ayude a detectar errores, regresiones y problemas de compatibilidad de SEO System. Puedes desactivarla de nuevo en cualquier momento.</p>';
    echo '<form method="post">';
    wp_nonce_field('seo_core_system_test_diagnostics', 'seo_core_system_test_diagnostics_nonce');
    echo '<label><input type="checkbox" name="seo_core_system_test_diagnostics_consent" value="1" ' . checked($consent, true, false) . '> <strong>Permitir el envío automático al desarrollador tras cada ejecución completada</strong></label>';
    if ($recipient !== '') {
        echo '<p class="seo-core-diagnostics-privacy"><strong>Destinatario técnico:</strong> ' . esc_html($recipient) . '.</p>';
    }
    echo '<p class="seo-core-diagnostics-privacy">Si lo autorizas, el resumen puede incluir el dominio, un identificador aleatorio de instalación, fecha y motivo de la ejecución, versiones técnicas (SEO System, WordPress, WooCommerce y PHP), puntuaciones agregadas e incidencias detectadas. No se envían contraseñas, tokens, cookies, pedidos, clientes, direcciones, filas completas de tablas ni contenido completo de logs.</p>';
    echo '<p class="seo-core-diagnostics-privacy">El correo de administración del propio sitio puede recibir una copia del mismo resumen para que el titular conserve visibilidad del envío.</p>';
    echo '<div class="seo-core-diagnostics-actions">';
    echo '<button type="submit" class="button button-primary" name="seo_core_system_test_diagnostics_action" value="save">Guardar autorización</button>';
    echo '<button type="submit" class="button" name="seo_core_system_test_diagnostics_action" value="send" ' . disabled($has_results, false, false) . '>Enviar ahora el último diagnóstico</button>';
    if (!$has_results) {
        echo '<span class="description">Ejecuta primero la validación completa para generar el informe.</span>';
    }
    echo '</div></form>';

    if ($compact) {
        echo '</div></details>';
    } else {
        echo '</section>';
    }
}




if (!function_exists('seo_core_system_test_is_backup_file')) {
    function seo_core_system_test_is_backup_file($file_path) {

        $file_name = strtolower(basename((string) $file_path));

        $backup_patterns = array(
            ' copy ',
            ' copia ',
            'backup',
            '.bak',
            '.old',
            '.tmp',
            ' copy',
            ' copia',
            'copy ',
            'copia '
        );

        foreach ($backup_patterns as $pattern) {
            if (strpos($file_name, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('seo_core_system_test_collect_function_inventory')) {
    function seo_core_system_test_collect_function_inventory() {
        $inventory = seo_core_system_test_get_code_inventory();
        $functions = array();
        $plugin_root = seo_core_system_test_get_plugin_root();

        foreach ((array) $inventory['functions'] as $function) {
            $relative_file = isset($function['file']) ? (string) $function['file'] : '';
            $functions[] = array(
                'function'  => isset($function['name']) ? (string) $function['name'] : '',
                'file'      => $relative_file,
                'line'      => isset($function['line']) ? (int) $function['line'] : 0,
                'is_backup' => seo_core_system_test_is_backup_file(trailingslashit($plugin_root) . $relative_file),
            );
        }

        usort($functions, static function ($a, $b) {
            $function_compare = strcasecmp($a['function'], $b['function']);
            if ($function_compare !== 0) {
                return $function_compare;
            }
            return strcasecmp($a['file'], $b['file']);
        });

        return $functions;
    }
}

if (!function_exists('seo_core_system_test_render_function_inventory')) {
    function seo_core_system_test_render_function_inventory() {

        $functions = seo_core_system_test_collect_function_inventory();

        echo '<h2>Inventario de funciones globales del plugin</h2>';

        if (empty($functions)) {
            echo '<p>No se han encontrado funciones PHP en el plugin o no se ha podido leer la ruta.</p>';
            return;
        }

        $grouped = array();

        foreach ($functions as $item) {
            $name = $item['function'];

            if (!isset($grouped[$name])) {
                $grouped[$name] = array();
            }

            $grouped[$name][] = $item;
        }

        $duplicates = array();

        foreach ($grouped as $function_name => $items) {
            if (count($items) > 1) {
                $duplicates[$function_name] = $items;
            }
        }

        echo '<p><strong>Total funciones globales detectadas:</strong> ' . esc_html((string) count($functions)) . '</p>';
        echo '<p><strong>Funciones duplicadas:</strong> ' . esc_html((string) count($duplicates)) . '</p>';

        if (!empty($duplicates)) {

            echo '<h3>Funciones globales duplicadas</h3>';
            echo '<table class="widefat striped">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>Función</th>';
            echo '<th>Archivo</th>';
            echo '<th>Línea</th>';
            echo '<th>Tipo</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';

            foreach ($duplicates as $function_name => $items) {
                foreach ($items as $item) {
                    echo '<tr>';
                    echo '<td><code>' . esc_html($function_name) . '</code></td>';
                    echo '<td><code>' . esc_html($item['file']) . '</code></td>';
                    echo '<td>' . esc_html((string) $item['line']) . '</td>';
                    echo '<td>' . ($item['is_backup'] ? '<span style="color:#b32d2e;font-weight:600;">Backup/copia</span>' : 'Principal') . '</td>';
                    echo '</tr>';
                }
            }

            echo '</tbody>';
            echo '</table>';
        }

        echo '<details style="margin-top:20px;">';
        echo '<summary style="cursor:pointer;font-weight:600;">Ver inventario completo de funciones</summary>';

        echo '<table class="widefat striped" style="margin-top:12px;">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Función</th>';
        echo '<th>Archivo</th>';
        echo '<th>Línea</th>';
        echo '<th>Tipo</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ($functions as $item) {
            echo '<tr>';
            echo '<td><code>' . esc_html($item['function']) . '</code></td>';
            echo '<td><code>' . esc_html($item['file']) . '</code></td>';
            echo '<td>' . esc_html((string) $item['line']) . '</td>';
            echo '<td>' . ($item['is_backup'] ? '<span style="color:#b32d2e;font-weight:600;">Backup/copia</span>' : 'Principal') . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</details>';
    }
}