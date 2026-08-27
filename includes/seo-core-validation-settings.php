<?php
/**
 * SEO Core - Validation settings and assisted remediation.
 *
 * Stores test tolerances and presents actionable suggestions. This module never
 * edits catalogue, SEO or FAQ data automatically.
 */
defined('ABSPATH') || exit;

if (!defined('SEO_CORE_VALIDATION_SETTINGS_VERSION')) {
    define('SEO_CORE_VALIDATION_SETTINGS_VERSION', '1.0.0');
}

function seo_core_validation_settings_option_name() {
    return 'seo_core_validation_settings';
}

function seo_core_validation_settings_defaults() {
    return array(
        'representative_product_id' => 0,
        'representative_scan_limit' => 250,
        'representative_require_visible' => 1,
        'representative_require_purchasable' => 1,
        'representative_require_in_stock' => 1,
        'search_mode' => 'adaptive',
        'search_max_words' => 7,
        'search_max_length' => 90,
        'content_match_percent' => 70,
        'h1_match_percent' => 60,
        'http_timeout' => 6,
        'http_response_limit_kb' => 1024,
        'require_canonical_category' => 1,
        'require_schema_product' => 1,
        'require_schema_breadcrumb' => 1,
        'require_schema_site' => 1,
        'semantic_category_excerpt_mismatch_limit' => 0,
        'semantic_category_without_excerpt_limit' => 0,
        'semantic_product_without_excerpt_limit' => 0,
        'semantic_suspicious_attribute_limit' => 0,
        'semantic_title_like_tag_limit' => 0,
        'semantic_without_attributes_limit' => 0,
        'semantic_faq_scope_mismatch_limit' => 0,
        'semantic_duplicate_excerpt_percent_limit' => 0,
        'semantic_duplicate_description_percent_limit' => 0,
        'semantic_template_description_percent_limit' => 0,
        'semantic_category_alignment_limit' => 0,
        'debug_mode' => 0,
    );
}

function seo_core_validation_get_settings() {
    $stored = get_option(seo_core_validation_settings_option_name(), array());
    $stored = is_array($stored) ? $stored : array();
    return array_merge(seo_core_validation_settings_defaults(), $stored);
}

function seo_core_validation_get_setting($key, $default = null) {
    $settings = seo_core_validation_get_settings();
    if (array_key_exists($key, $settings)) {
        return $settings[$key];
    }
    return $default;
}

function seo_core_validation_debug_enabled() {
    return (bool) seo_core_validation_get_setting('debug_mode', 0);
}

function seo_core_validation_sanitize_settings($input) {
    $input = is_array($input) ? $input : array();
    $defaults = seo_core_validation_settings_defaults();
    $clean = $defaults;

    $clean['representative_product_id'] = absint($input['representative_product_id'] ?? 0);
    $clean['representative_scan_limit'] = max(20, min(2000, absint($input['representative_scan_limit'] ?? $defaults['representative_scan_limit'])));

    foreach (array(
        'representative_require_visible',
        'representative_require_purchasable',
        'representative_require_in_stock',
        'require_canonical_category',
        'require_schema_product',
        'require_schema_breadcrumb',
        'require_schema_site',
        'debug_mode',
    ) as $checkbox) {
        $clean[$checkbox] = empty($input[$checkbox]) ? 0 : 1;
    }

    $search_mode = sanitize_key((string) ($input['search_mode'] ?? $defaults['search_mode']));
    $clean['search_mode'] = in_array($search_mode, array('adaptive', 'full', 'compact'), true) ? $search_mode : 'adaptive';
    $clean['search_max_words'] = max(2, min(15, absint($input['search_max_words'] ?? $defaults['search_max_words'])));
    $clean['search_max_length'] = max(20, min(180, absint($input['search_max_length'] ?? $defaults['search_max_length'])));
    $clean['content_match_percent'] = max(40, min(100, absint($input['content_match_percent'] ?? $defaults['content_match_percent'])));
    $clean['h1_match_percent'] = max(40, min(100, absint($input['h1_match_percent'] ?? $defaults['h1_match_percent'])));
    $clean['http_timeout'] = max(2, min(30, absint($input['http_timeout'] ?? $defaults['http_timeout'])));
    $clean['http_response_limit_kb'] = max(128, min(8192, absint($input['http_response_limit_kb'] ?? $defaults['http_response_limit_kb'])));

    foreach (array(
        'semantic_category_excerpt_mismatch_limit',
        'semantic_category_without_excerpt_limit',
        'semantic_product_without_excerpt_limit',
        'semantic_suspicious_attribute_limit',
        'semantic_title_like_tag_limit',
        'semantic_without_attributes_limit',
        'semantic_faq_scope_mismatch_limit',
        'semantic_category_alignment_limit',
    ) as $count_key) {
        $clean[$count_key] = max(0, min(1000000, absint($input[$count_key] ?? $defaults[$count_key])));
    }

    foreach (array(
        'semantic_duplicate_excerpt_percent_limit',
        'semantic_duplicate_description_percent_limit',
        'semantic_template_description_percent_limit',
    ) as $percent_key) {
        $clean[$percent_key] = max(0, min(100, (float) ($input[$percent_key] ?? $defaults[$percent_key])));
    }

    return $clean;
}

function seo_core_validation_handle_settings_actions() {
    if (empty($_POST['seo_core_validation_settings_action'])) {
        return null;
    }
    if (!current_user_can('manage_options')) {
        return array('type' => 'error', 'message' => 'No tienes permisos para modificar la configuración de validación.');
    }
    if (!check_admin_referer('seo_core_validation_settings', 'seo_core_validation_settings_nonce')) {
        return array('type' => 'error', 'message' => 'La verificación de seguridad de la configuración ha fallado.');
    }

    $action = sanitize_key(wp_unslash($_POST['seo_core_validation_settings_action']));
    if ($action === 'reset') {
        delete_option(seo_core_validation_settings_option_name());
        return array('type' => 'success', 'message' => 'Se han restaurado los valores recomendados de Plugin Validation.');
    }

    $raw = isset($_POST['seo_core_validation_settings']) && is_array($_POST['seo_core_validation_settings'])
        ? wp_unslash($_POST['seo_core_validation_settings'])
        : array();
    $clean = seo_core_validation_sanitize_settings($raw);
    update_option(seo_core_validation_settings_option_name(), $clean, false);
    return array('type' => 'success', 'message' => 'Configuración guardada. Ejecuta de nuevo la validación para recalcular los resultados.');
}

function seo_core_validation_render_settings_notice($notice) {
    if (!is_array($notice) || empty($notice['message'])) {
        return;
    }
    $class = !empty($notice['type']) && $notice['type'] === 'success' ? 'notice-success' : 'notice-error';
    echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
}

function seo_core_validation_remediation_catalog() {
    return array(
        '8.5 Buscador interno localiza un producto publicado' => array(
            'kind' => 'Configuración y debug',
            'summary' => 'Probar un producto visible y una consulta adaptativa antes de declarar que el buscador está roto.',
            'steps' => array(
                'Selecciona un ID de producto representativo o aumenta el límite de candidatos.',
                'Usa el modo de búsqueda adaptativo para probar el título completo y una versión compacta.',
                'Si sigue fallando, activa el modo debug y revisa términos probados, IDs devueltos y la consulta SQL.',
                'Comprueba filtros posts_search, motores de búsqueda externos y la visibilidad de catálogo del producto.',
            ),
            'settings' => array('representative_product_id', 'representative_scan_limit', 'search_mode', 'search_max_words', 'search_max_length', 'debug_mode'),
        ),
        '8.7 Plantilla de producto renderiza contenido' => array(
            'kind' => 'Configuración o edición de plantilla',
            'summary' => 'Distinguir diferencias tipográficas del título de una plantilla que realmente no imprime el producto.',
            'steps' => array(
                'Verifica que el producto de referencia sea visible y que su URL abra una ficha individual.',
                'Ajusta el porcentaje mínimo de coincidencia solo si la plantilla abrevia o transforma el título.',
                'Si la coincidencia sigue siendo baja, revisa single-product.php y los hooks de woocommerce_single_product_summary.',
            ),
            'settings' => array('representative_product_id', 'content_match_percent', 'debug_mode'),
        ),
        '8.17 Canonical coherente' => array(
            'kind' => 'Configuración o edición SEO',
            'summary' => 'La categoría necesita una canonical propia, salvo que deliberadamente no deba evaluarse.',
            'steps' => array(
                'Comprueba si el plugin SEO elimina canonical en taxonomías de producto.',
                'Revisa wp_head y la plantilla de categoría para evitar que se suprima rel="canonical".',
                'Desactiva la exigencia en categorías únicamente cuando esa ausencia sea una decisión consciente.',
            ),
            'settings' => array('require_canonical_category', 'debug_mode'),
        ),
        '8.19 Encabezados H1 de plantillas' => array(
            'kind' => 'Configuración o edición de plantilla',
            'summary' => 'El H1 debe identificar la ficha; la tolerancia solo cubre prefijos, sufijos o pequeñas transformaciones.',
            'steps' => array(
                'Comprueba el producto de referencia y el texto exacto de los H1 en modo debug.',
                'Ajusta el porcentaje de coincidencia si el H1 añade marca o referencia.',
                'Si el H1 es genérico, corrige la plantilla o el hook que imprime el título del producto.',
            ),
            'settings' => array('representative_product_id', 'h1_match_percent', 'debug_mode'),
        ),
        '8.20 Datos estructurados JSON-LD' => array(
            'kind' => 'Edición de schema o configuración',
            'summary' => 'Validar los tipos por página: Product en ficha, BreadcrumbList en navegación y Organization/WebSite en portada.',
            'steps' => array(
                'Confirma que la URL representativa es una ficha y no una colección o redirección interna.',
                'Revisa el generador JSON-LD del tema o plugin SEO y evita que una ficha emita solo CollectionPage/ItemList.',
                'Desactiva un tipo recomendado únicamente cuando otro sistema equivalente lo aporte fuera del HTML inspeccionado.',
            ),
            'settings' => array('representative_product_id', 'require_schema_product', 'require_schema_breadcrumb', 'require_schema_site', 'debug_mode'),
        ),
        '8.24 Producto representativo vendible' => array(
            'kind' => 'Configuración de muestra o catálogo',
            'summary' => 'No usar como muestra automática un producto oculto o agotado cuando existen candidatos vendibles.',
            'steps' => array(
                'Deja el ID a cero para selección automática y aumenta el número de candidatos.',
                'O fija el ID de un producto estable, visible, con precio y stock.',
                'Si el producto debe venderse, corrige visibilidad de catálogo y estado de inventario en WooCommerce.',
            ),
            'settings' => array('representative_product_id', 'representative_scan_limit', 'representative_require_visible', 'representative_require_purchasable', 'representative_require_in_stock'),
        ),
        '8.24B Tienda preparada para vender' => array(
            'kind' => 'Configuración de muestra y WooCommerce',
            'summary' => 'Separar un catálogo realmente bloqueado de una mala elección del producto de referencia.',
            'steps' => array(
                'Usa un producto representativo visible y en stock.',
                'Verifica páginas de tienda, carrito y checkout y al menos un método de pago habilitado.',
                'Mantén el chequeo sin crear pedidos ni ejecutar cobros.',
            ),
            'settings' => array('representative_product_id', 'representative_scan_limit', 'debug_mode'),
        ),
        '10.1 Integridad de fuentes de categorías' => array(
            'kind' => 'Datos o unificación de fuentes',
            'summary' => 'Sincronizar el excerpt activo de seo_nodes con termmeta seo_excerpt o hacer que la plantilla lea una única fuente.',
            'steps' => array(
                'Exporta primero los ejemplos en modo debug y confirma cuál es la fuente canónica.',
                'Corrige el proceso que guarda categorías para escribir ambas fuentes en la misma operación.',
                'Migra los registros existentes con una operación reversible y vuelve a ejecutar la auditoría.',
                'Usa la tolerancia solo durante una migración controlada; no como solución permanente.',
            ),
            'settings' => array('semantic_category_excerpt_mismatch_limit', 'semantic_category_without_excerpt_limit', 'debug_mode'),
        ),
        '10.3 Singularidad del contenido de producto' => array(
            'kind' => 'Contenido y rangos',
            'summary' => 'Priorizar duplicados de mayor impacto y permitir un porcentaje transitorio mientras se reescribe el catálogo.',
            'steps' => array(
                'Revisa primero los grupos con más productos y las descripciones idénticas completas.',
                'Regenera textos con datos verificables del producto, no solo cambiando el nombre dentro de una plantilla.',
                'Configura porcentajes tolerados únicamente para reflejar un plan de reducción gradual.',
            ),
            'settings' => array('semantic_duplicate_excerpt_percent_limit', 'semantic_duplicate_description_percent_limit', 'semantic_template_description_percent_limit', 'debug_mode'),
        ),
        '10.3A Cobertura de descripción corta de producto' => array(
            'kind' => 'Cobertura de contenido de producto',
            'summary' => 'Completar el post_excerpt nativo de WooCommerce en los productos publicados que lo tengan vacío.',
            'steps' => array(
                'Revisa los ejemplos para identificar qué proceso de importación o actualización dejó post_excerpt vacío.',
                'Corrige el flujo de producto para escribir la descripción corta en wp_posts.post_excerpt.',
                'Rellena los productos históricos por lotes y únicamente cuando post_excerpt esté vacío.',
                'No crees un segundo excerpt para productos: la fuente canónica sigue siendo WordPress/WooCommerce.',
            ),
            'settings' => array('semantic_product_without_excerpt_limit', 'debug_mode'),
        ),
        '10.4 Atributos, etiquetas y datos de producto' => array(
            'kind' => 'Datos, reglas y rangos',
            'summary' => 'Revisar ejemplos de atributos sospechosos antes de regenerar contenido dependiente.',
            'steps' => array(
                'Activa debug para ver producto, tipo, valor y motivo de cada ejemplo.',
                'Corrige valores truncados, valores iguales al SKU y modelos interpretados como medidas.',
                'Limpia etiquetas que copian el título completo o superan una longitud razonable.',
                'Ajusta los límites solo si existe una excepción de negocio conocida y documentada.',
            ),
            'settings' => array('semantic_suspicious_attribute_limit', 'semantic_title_like_tag_limit', 'semantic_without_attributes_limit', 'debug_mode'),
        ),
        '10.5 Alineación producto-categoría' => array(
            'kind' => 'Revisión editorial y rango',
            'summary' => 'Es una señal conservadora: revisar muestras sin reasignar categorías automáticamente.',
            'steps' => array(
                'Compara título, categorías internas y categoría del proveedor en los ejemplos.',
                'Corrige primero el mapeo del proveedor si contiene nombres demasiado genéricos.',
                'Establece un límite operativo para que la alerta represente el volumen pendiente aceptado.',
            ),
            'settings' => array('semantic_category_alignment_limit', 'debug_mode'),
        ),
        '10.6 Integridad de FAQs' => array(
            'kind' => 'Datos y clasificación',
            'summary' => 'Alinear el ámbito de cada FAQ con el objeto después de validar la clasificación del producto o categoría.',
            'steps' => array(
                'Revisa los ejemplos de FAQ y confirma primero que el ámbito del objeto sea correcto.',
                'Actualiza las FAQs mediante el flujo reversible del módulo, evitando SQL destructivo directo.',
                'Usa el límite de desalineaciones como tolerancia temporal durante la limpieza.',
            ),
            'settings' => array('semantic_faq_scope_mismatch_limit', 'debug_mode'),
        ),
    );
}

function seo_core_validation_remediation_for_label($label) {
    $catalog = seo_core_validation_remediation_catalog();
    return isset($catalog[$label]) ? $catalog[$label] : array();
}

function seo_core_validation_setting_labels() {
    return array(
        'representative_product_id' => 'ID de producto representativo',
        'representative_scan_limit' => 'Productos candidatos a revisar',
        'representative_require_visible' => 'Exigir visibilidad',
        'representative_require_purchasable' => 'Exigir compra',
        'representative_require_in_stock' => 'Exigir stock',
        'search_mode' => 'Modo de búsqueda',
        'search_max_words' => 'Palabras de búsqueda',
        'search_max_length' => 'Longitud de búsqueda',
        'content_match_percent' => 'Coincidencia de contenido',
        'h1_match_percent' => 'Coincidencia del H1',
        'http_timeout' => 'Timeout HTTP',
        'http_response_limit_kb' => 'Límite de respuesta HTML',
        'require_canonical_category' => 'Canonical en categoría',
        'require_schema_product' => 'Schema Product',
        'require_schema_breadcrumb' => 'Schema BreadcrumbList',
        'require_schema_site' => 'Schema Organization/WebSite',
        'semantic_category_excerpt_mismatch_limit' => 'Tolerancia de excerpts desincronizados',
        'semantic_category_without_excerpt_limit' => 'Tolerancia de categorías sin excerpt',
        'semantic_product_without_excerpt_limit' => 'Tolerancia de productos sin descripción corta',
        'semantic_suspicious_attribute_limit' => 'Tolerancia de productos con atributos sospechosos',
        'semantic_title_like_tag_limit' => 'Tolerancia de etiquetas tipo título',
        'semantic_without_attributes_limit' => 'Tolerancia de productos sin atributos SEO',
        'semantic_faq_scope_mismatch_limit' => 'Tolerancia de FAQs desalineadas',
        'semantic_duplicate_excerpt_percent_limit' => 'Porcentaje tolerado de excerpts duplicados',
        'semantic_duplicate_description_percent_limit' => 'Porcentaje tolerado de descripciones duplicadas',
        'semantic_template_description_percent_limit' => 'Porcentaje tolerado de descripciones de plantilla',
        'semantic_category_alignment_limit' => 'Tolerancia de productos pendientes de categoría',
        'debug_mode' => 'Modo debug',
    );
}

function seo_core_validation_render_remediation_details($label, $evidence = array()) {
    $remediation = seo_core_validation_remediation_for_label($label);
    if (empty($remediation)) {
        return;
    }

    echo '<details class="seo-core-remediation-details"><summary>Sugerencia de corrección</summary><div class="seo-core-remediation-body">';
    echo '<p><strong>' . esc_html($remediation['kind']) . ':</strong> ' . esc_html($remediation['summary']) . '</p>';
    if (!empty($remediation['steps'])) {
        echo '<ol>';
        foreach ($remediation['steps'] as $step) {
            echo '<li>' . esc_html($step) . '</li>';
        }
        echo '</ol>';
    }
    if (!empty($remediation['settings'])) {
        $labels = seo_core_validation_setting_labels();
        $names = array();
        foreach ($remediation['settings'] as $key) {
            $names[] = $labels[$key] ?? $key;
        }
        echo '<p><strong>Ajustes relacionados:</strong> ' . esc_html(implode(', ', $names)) . '.</p>';
    }
    if (seo_core_validation_debug_enabled() && !empty($evidence)) {
        $json = wp_json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo '<details><summary>Evidencia de debug</summary><pre class="seo-core-debug-json">' . esc_html((string) $json) . '</pre></details>';
    }
    echo '</div></details>';
}

function seo_core_validation_render_number_field($settings, $key, $label, $description, $min, $max, $step = 1) {
    echo '<tr><th scope="row"><label for="seo-core-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
    echo '<input type="number" class="small-text" id="seo-core-' . esc_attr($key) . '" name="seo_core_validation_settings[' . esc_attr($key) . ']" value="' . esc_attr($settings[$key]) . '" min="' . esc_attr($min) . '" max="' . esc_attr($max) . '" step="' . esc_attr($step) . '">';
    echo '<p class="description">' . esc_html($description) . '</p></td></tr>';
}

function seo_core_validation_render_checkbox_field($settings, $key, $label, $description) {
    echo '<tr><th scope="row">' . esc_html($label) . '</th><td><label>';
    echo '<input type="checkbox" name="seo_core_validation_settings[' . esc_attr($key) . ']" value="1" ' . checked(!empty($settings[$key]), true, false) . '> ' . esc_html($description);
    echo '</label></td></tr>';
}

function seo_core_validation_render_remediation_center($results) {
    $incidents = function_exists('seo_core_system_test_extract_incidents')
        ? seo_core_system_test_extract_incidents((array) $results, 100)
        : array();
    $catalog = seo_core_validation_remediation_catalog();

    echo '<section class="seo-core-remediation-center"><h2>Centro de corrección asistida</h2>';
    echo '<p>Las sugerencias distinguen entre configuración del test, diagnóstico, edición de archivos y corrección de datos. No se aplican cambios automáticos al catálogo ni a las tablas SEO.</p>';

    $shown = 0;
    foreach ($incidents as $incident) {
        $label = (string) ($incident['label'] ?? '');
        if (!isset($catalog[$label])) {
            continue;
        }
        $shown++;
        echo '<article class="seo-core-remediation-card">';
        echo '<header><span class="seo-core-test-badge seo-core-health-' . esc_attr($incident['impact']) . '">' . esc_html(function_exists('seo_core_system_test_impact_label') ? seo_core_system_test_impact_label($incident['impact']) : ucfirst($incident['impact'])) . '</span> ';
        echo '<strong>' . esc_html($label) . '</strong> <code>' . esc_html($incident['id']) . '</code></header>';
        echo '<p class="seo-core-test-muted">' . esc_html((string) ($incident['detail'] ?? '')) . '</p>';
        seo_core_validation_render_remediation_details($label, $incident['evidence'] ?? array());
        echo '</article>';
    }

    if ($shown === 0) {
        echo '<div class="seo-core-test-note">No hay incidencias con una guía específica en el último resultado. Ejecuta la validación completa después de guardar los ajustes.</div>';
    }

    if (function_exists('seo_core_system_test_get_semantic_snapshot')) {
        $snapshot = seo_core_system_test_get_semantic_snapshot();
        $actions = isset($snapshot['actions']) && is_array($snapshot['actions']) ? $snapshot['actions'] : array();
        if (!empty($actions)) {
            echo '<h3>Plan semántico sugerido</h3><table class="widefat striped"><thead><tr><th>Prioridad</th><th>Acción</th><th>Afectados</th><th>Sugerencia</th></tr></thead><tbody>';
            foreach (array_slice($actions, 0, 20) as $action) {
                echo '<tr><td>' . esc_html((string) ($action['priority'] ?? '')) . '</td><td><code>' . esc_html((string) ($action['code'] ?? '')) . '</code></td><td>' . esc_html(number_format_i18n((int) ($action['count'] ?? 0))) . '</td><td>' . esc_html((string) ($action['recommendation'] ?? '')) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
    }
    echo '</section>';
}

function seo_core_validation_render_settings_page($results = array()) {
    $settings = seo_core_validation_get_settings();
    echo '<h2>Configuración de Plugin Validation</h2>';
    echo '<div class="seo-core-test-note"><strong>Criterio:</strong> los límites son tolerancias operativas. No corrigen datos ni convierten un fallo real en correcto; permiten reflejar migraciones, excepciones conocidas y el volumen pendiente aceptado.</div>';

    echo '<form method="post" class="seo-core-settings-form">';
    wp_nonce_field('seo_core_validation_settings', 'seo_core_validation_settings_nonce');

    echo '<h3>Producto y búsqueda representativos</h3><table class="form-table" role="presentation">';
    seo_core_validation_render_number_field($settings, 'representative_product_id', 'ID de producto fijo', '0 selecciona automáticamente el mejor candidato. Un ID fijo permite repetir siempre la misma ficha.', 0, PHP_INT_MAX);
    seo_core_validation_render_number_field($settings, 'representative_scan_limit', 'Candidatos a revisar', 'Número máximo de productos publicados que se inspeccionan para encontrar uno visible, comprable, con precio y stock.', 20, 2000);
    seo_core_validation_render_checkbox_field($settings, 'representative_require_visible', 'Selección automática', 'Exigir que el producto sea visible en el catálogo.');
    seo_core_validation_render_checkbox_field($settings, 'representative_require_purchasable', 'Compra', 'Exigir que WooCommerce lo considere comprable.');
    seo_core_validation_render_checkbox_field($settings, 'representative_require_in_stock', 'Inventario', 'Excluir productos agotados de la muestra automática.');
    echo '<tr><th scope="row"><label for="seo-core-search-mode">Modo de búsqueda</label></th><td><select id="seo-core-search-mode" name="seo_core_validation_settings[search_mode]">';
    foreach (array('adaptive' => 'Adaptativo: título completo y compacto', 'full' => 'Solo título completo', 'compact' => 'Solo término compacto') as $value => $label) {
        echo '<option value="' . esc_attr($value) . '" ' . selected($settings['search_mode'], $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select><p class="description">El modo adaptativo evita falsos fallos con títulos largos, signos y motores de búsqueda que exigen demasiados términos.</p></td></tr>';
    seo_core_validation_render_number_field($settings, 'search_max_words', 'Máximo de palabras', 'Palabras usadas en la variante compacta.', 2, 15);
    seo_core_validation_render_number_field($settings, 'search_max_length', 'Máximo de caracteres', 'Longitud máxima de la variante compacta.', 20, 180);
    echo '</table>';

    echo '<h3>HTTP, plantillas y SEO técnico</h3><table class="form-table" role="presentation">';
    seo_core_validation_render_number_field($settings, 'content_match_percent', 'Coincidencia de contenido (%)', 'Porcentaje mínimo de palabras significativas del título que debe aparecer en la ficha.', 40, 100);
    seo_core_validation_render_number_field($settings, 'h1_match_percent', 'Coincidencia del H1 (%)', 'Permite prefijos de marca o sufijos de referencia sin aceptar un H1 genérico.', 40, 100);
    seo_core_validation_render_number_field($settings, 'http_timeout', 'Timeout HTTP (segundos)', 'Rango aplicado a las comprobaciones internas de páginas.', 2, 30);
    seo_core_validation_render_number_field($settings, 'http_response_limit_kb', 'Límite HTML (KB)', 'Tamaño máximo descargado por página. Auméntalo si el JSON-LD o el título quedan fuera de una respuesta muy grande.', 128, 8192);
    seo_core_validation_render_checkbox_field($settings, 'require_canonical_category', 'Canonical', 'Exigir canonical propia en la categoría representativa.');
    seo_core_validation_render_checkbox_field($settings, 'require_schema_product', 'Schema de producto', 'Exigir Product en la ficha representativa.');
    seo_core_validation_render_checkbox_field($settings, 'require_schema_breadcrumb', 'Migas estructuradas', 'Exigir BreadcrumbList en alguna página representativa.');
    seo_core_validation_render_checkbox_field($settings, 'require_schema_site', 'Identidad del sitio', 'Exigir Organization o WebSite en la portada.');
    echo '</table>';

    echo '<h3>Rangos de salud semántica</h3><table class="form-table" role="presentation">';
    seo_core_validation_render_number_field($settings, 'semantic_category_excerpt_mismatch_limit', 'Excerpts de categoría desincronizados', 'Cantidad tolerada temporalmente antes de marcar incidencia.', 0, 1000000);
    seo_core_validation_render_number_field($settings, 'semantic_category_without_excerpt_limit', 'Categorías sin excerpt visible', 'Cantidad tolerada temporalmente.', 0, 1000000);
    seo_core_validation_render_number_field($settings, 'semantic_product_without_excerpt_limit', 'Productos sin descripción corta', 'Cantidad de productos publicados con wp_posts.post_excerpt vacío tolerada temporalmente. Recomendado: 0.', 0, 1000000);
    seo_core_validation_render_number_field($settings, 'semantic_suspicious_attribute_limit', 'Productos con atributos sospechosos', 'Cantidad tolerada antes de marcar fallo importante.', 0, 1000000);
    seo_core_validation_render_number_field($settings, 'semantic_title_like_tag_limit', 'Etiquetas que parecen títulos', 'Cantidad tolerada antes de mostrar aviso.', 0, 1000000);
    seo_core_validation_render_number_field($settings, 'semantic_without_attributes_limit', 'Productos sin atributos SEO', 'Cantidad tolerada antes de mostrar aviso.', 0, 1000000);
    seo_core_validation_render_number_field($settings, 'semantic_faq_scope_mismatch_limit', 'FAQs desalineadas', 'Cantidad tolerada durante una reclasificación controlada.', 0, 1000000);
    seo_core_validation_render_number_field($settings, 'semantic_duplicate_excerpt_percent_limit', 'Excerpts duplicados (%)', 'Porcentaje máximo tolerado sobre productos publicados.', 0, 100, 0.1);
    seo_core_validation_render_number_field($settings, 'semantic_duplicate_description_percent_limit', 'Descripciones duplicadas (%)', 'Porcentaje máximo tolerado sobre productos publicados.', 0, 100, 0.1);
    seo_core_validation_render_number_field($settings, 'semantic_template_description_percent_limit', 'Descripciones de plantilla (%)', 'Porcentaje máximo tolerado sobre productos publicados.', 0, 100, 0.1);
    seo_core_validation_render_number_field($settings, 'semantic_category_alignment_limit', 'Productos pendientes de categoría', 'Volumen operativo aceptado antes de mostrar aviso.', 0, 1000000);
    echo '</table>';

    echo '<h3>Diagnóstico</h3><table class="form-table" role="presentation">';
    seo_core_validation_render_checkbox_field($settings, 'debug_mode', 'Modo debug', 'Mostrar evidencias, términos probados, porcentajes de coincidencia y ejemplos. No activa WP_DEBUG ni modifica archivos.');
    echo '</table>';

    echo '<p class="submit"><button type="submit" class="button button-primary" name="seo_core_validation_settings_action" value="save">Guardar configuración</button> ';
    echo '<button type="submit" class="button" name="seo_core_validation_settings_action" value="reset" onclick="return confirm(\'¿Restaurar los valores recomendados?\');">Restaurar valores</button></p>';
    echo '</form>';

    if (function_exists('seo_core_system_test_get_representative_product')) {
        $product = seo_core_system_test_get_representative_product();
        echo '<div class="seo-core-test-card"><h3>Muestra que se usará</h3>';
        if (!empty($product['id'])) {
            echo '<p><strong>ID ' . esc_html((int) $product['id']) . ':</strong> ' . esc_html((string) ($product['title'] ?? '')) . '</p>';
            echo '<p class="seo-core-test-muted">Selección: ' . esc_html((string) ($product['selection_reason'] ?? 'automática')) . '.</p>';
        } else {
            echo '<p>No se ha podido resolver un producto publicado representativo.</p>';
        }
        echo '</div>';
    }

    seo_core_validation_render_remediation_center($results);
}
