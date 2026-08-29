<?php
/*
Plugin Name: SEO Menu Manager
Plugin URI: https://www.distribuidordeherramientas.es/
Description: Generador de informes con vistas Normal y Detallada
Version: 1.2.5
Requires PHP: 7.4
Requires at least: 5.8
Author: David Perez Martorell davidperezmartorell@gmail.com
Author URI: https://focazul.wordfpress.com/
License: GPL2
Text Domain: seo-menu-manager
*/

if (!defined('ABSPATH')) exit;

// Informe editorial: oportunidades de posts a partir de las conexiones Google ya existentes.
$seo_post_opportunities_file = __DIR__ . '/seo-post-opportunities.php';
if (file_exists($seo_post_opportunities_file)) {
    require_once $seo_post_opportunities_file;
}

// Informe ejecutivo de crecimiento de catalogo.
$seo_growth_executive_file = __DIR__ . '/seo-growth-executive.php';
if (file_exists($seo_growth_executive_file)) {
    require_once $seo_growth_executive_file;
}

// Informe editorial completo de contenidos por nivel.
$seo_report_contents_file = __DIR__ . '/seo-report-contents.php';
if (file_exists($seo_report_contents_file)) {
    require_once $seo_report_contents_file;
}

// Acciones seguras para limpiar FAQs huérfanas.
add_action('admin_post_seo_delete_orphan_category_faqs', 'seo_delete_orphan_category_faqs_handler');
add_action('admin_post_seo_delete_orphan_product_faqs', 'seo_delete_orphan_product_faqs_handler');

// Acción segura para limpiar categorías de producto vacías.
add_action('admin_post_seo_delete_empty_product_categories', 'seo_delete_empty_product_categories_handler');

// Acción segura para recalcular los contadores de categorías de producto.
add_action('admin_post_seo_recount_product_categories', 'seo_recount_product_categories_handler');

/**
 * Resumen reutilizado de Search Console para la portada Informes > Informes.
 *
 * No sustituye ni modifica Google Intelligence. Consume las mismas funciones
 * y los mismos datos que la vista original, pero muestra solo el bloque
 * ejecutivo solicitado: fechas, indicadores y tres gráficos de tendencia.
 */
function seo_reports_render_google_search_summary() {
    $required_functions = array(
        'seo_google_get_settings',
        'seo_google_connection_status',
        'seo_google_get_summary_metrics',
        'seo_google_get_summary_trend_data',
        'seo_google_render_summary_charts',
    );

    foreach ($required_functions as $required_function) {
        if (!function_exists($required_function)) {
            echo '<div class="notice notice-warning inline"><p><strong>Resumen de Google no disponible.</strong> Falta cargar el módulo <code>seo-google-info.php</code>.</p></div>';
            return;
        }
    }

    $settings = seo_google_get_settings();
    $status   = seo_google_connection_status();

    echo '<section class="seo-reports-google-summary" style="margin-top:18px;">';
    echo '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:12px;">';
    echo '<div>';
    echo '<h2 style="margin:0 0 5px;">Google · Visibilidad orgánica</h2>';
    echo '<p style="margin:0;color:#646970;">Resumen de Search Console reutilizado desde Inteligencia de Google.</p>';
    echo '</div>';

    if (function_exists('seo_google_admin_url')) {
        echo '<a class="button" href="' . esc_url(seo_google_admin_url('summary')) . '">Ver informe completo</a>';
    }

    echo '</div>';

    if ('connected' !== $status) {
        echo '<div class="notice notice-info inline"><p><strong>Google Search Console todavía no está conectado completamente.</strong></p></div>';
        if (function_exists('seo_google_admin_url')) {
            echo '<p><a class="button button-primary" href="' . esc_url(seo_google_admin_url('settings')) . '">Configurar conexión</a></p>';
        }
        echo '</section>';
        return;
    }

    $property_id = isset($settings['property_id']) ? (string) $settings['property_id'] : '';
    $metrics     = seo_google_get_summary_metrics($property_id, 28);

    if (!$metrics) {
        echo '<div class="notice notice-info inline"><p><strong>La conexión está preparada, pero aún no hay datos almacenados.</strong></p></div>';
        if (function_exists('seo_google_admin_url')) {
            echo '<p><a class="button button-primary" href="' . esc_url(seo_google_admin_url('sync')) . '">Ejecutar sincronización</a></p>';
        }
        echo '</section>';
        return;
    }

    $cards = array(
        'Clics'       => number_format_i18n((float) $metrics['clicks'], 0),
        'Impresiones' => number_format_i18n((float) $metrics['impressions'], 0),
        'CTR'         => number_format_i18n(((float) $metrics['ctr']) * 100, 2) . '%',
        'Posición'    => number_format_i18n((float) $metrics['position'], 1),
        'Consultas'   => number_format_i18n(absint($metrics['queries'])),
        'Páginas'     => number_format_i18n(absint($metrics['pages'])),
    );

    echo '<div style="background:#fff;border:1px solid #dcdcde;padding:20px;border-radius:6px;">';
    echo '<h3 style="margin-top:0;">Últimos 28 días disponibles</h3>';
    echo '<p><code>' . esc_html($metrics['date_from']) . '</code> → <code>' . esc_html($metrics['date_to']) . '</code></p>';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;">';

    foreach ($cards as $label => $value) {
        echo '<div style="border:1px solid #dcdcde;border-radius:6px;padding:14px;">';
        echo '<div style="color:#646970;font-size:12px;text-transform:uppercase;font-weight:700;">' . esc_html($label) . '</div>';
        echo '<div style="font-size:26px;font-weight:700;margin-top:6px;">' . esc_html($value) . '</div>';
        echo '</div>';
    }

    echo '</div>';
    echo '<p class="description" style="margin-top:14px;">La posición se pondera por impresiones. Search Console puede devolver las filas principales y no garantiza un conjunto exhaustivo de consultas.</p>';
    echo '</div>';

    $trend_rows = seo_google_get_summary_trend_data($property_id, 365);
    seo_google_render_summary_charts($trend_rows);

    echo '</section>';
}

/*******************************************************************************
 * SISTEMA DE INFORMES SEO
 ******************************************************************************/

function seo_reports_page() {
    
    if (!current_user_can('manage_options')) return;
    
    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'informes';

    $allowed_tabs = [
        'informes',
        'dashboard',
        'content',
        'estructura_total',
        'anomalias',
        'reclasificacion',
        'growth_executive',
        'google_intelligence',
        'post_opportunities',
    ];

    if (!in_array($active_tab, $allowed_tabs, true)) {
        $active_tab = 'informes';
    }

    $base_url = admin_url('admin.php?page=seo-reports');
    
    echo '<div class="wrap">';
    echo '<h1>Informes SEO</h1>';
    
    echo '<h2 class="nav-tab-wrapper">';
    echo '<a class="nav-tab ' . ($active_tab === 'informes' ? 'nav-tab-active' : '') . '" href="' . esc_url($base_url . '&tab=informes') . '">Informes</a>';
    echo '<a class="nav-tab ' . ($active_tab === 'dashboard' ? 'nav-tab-active' : '') . '" href="' . esc_url($base_url . '&tab=dashboard') . '">Panel</a>';
    echo '<a class="nav-tab ' . ($active_tab === 'content' ? 'nav-tab-active' : '') . '" href="' . esc_url($base_url . '&tab=content') . '">Contenido</a>';
    echo '<a class="nav-tab ' . ($active_tab === 'estructura_total' ? 'nav-tab-active' : '') . '" href="' . esc_url($base_url . '&tab=estructura_total') . '">Categorías</a>';
    echo '<a class="nav-tab ' . ($active_tab === 'anomalias' ? 'nav-tab-active' : '') . '" href="' . esc_url($base_url . '&tab=anomalias') . '">Anomalías</a>';
    echo '<a class="nav-tab ' . ($active_tab === 'reclasificacion' ? 'nav-tab-active' : '') . '" href="' . esc_url($base_url . '&tab=reclasificacion') . '">Reclasificación</a>';
    echo '<a class="nav-tab ' . ($active_tab === 'growth_executive' ? 'nav-tab-active' : '') . '" href="' . esc_url($base_url . '&tab=growth_executive') . '">Qué potenciar</a>';
    echo '<a class="nav-tab ' . ($active_tab === 'google_intelligence' ? 'nav-tab-active' : '') . '" href="' . esc_url($base_url . '&tab=google_intelligence') . '">Inteligencia de Google</a>';
    echo '<a class="nav-tab ' . ($active_tab === 'post_opportunities' ? 'nav-tab-active' : '') . '" href="' . esc_url($base_url . '&tab=post_opportunities') . '">Entradas · Oportunidades</a>';
    echo '</h2>';

    // Ejecución de acciones según la pestaña escogida.
    if ($active_tab === 'informes') {
        echo '<h2>Informes generales</h2>';
        echo '<p style="color:#646970;margin-top:-6px;">Vista unificada con los indicadores que quieras consultar sin recorrer los informes técnicos de origen.</p>';
        seo_reports_render_google_search_summary();
    } elseif ($active_tab === 'dashboard') {
        seo_dashboard_page();
    } elseif ($active_tab === 'content') {
        if (function_exists('seo_report_contents_render_page')) {
            seo_report_contents_render_page();
        } else {
            echo '<div class="notice notice-error inline"><p>Falta el archivo <code>seo-report-contents.php</code>.</p></div>';
        }
    } elseif ($active_tab === 'estructura_total') {
        seo_render_total_structure_report();
    } elseif ($active_tab === 'anomalias') {
        seo_render_anomalies_report();
    } elseif ($active_tab === 'reclasificacion') {
        seo_report_classification();
    } elseif ($active_tab === 'growth_executive') {
        if (function_exists('seo_render_growth_executive_report')) {
            seo_render_growth_executive_report();
        } else {
            echo '<div class="notice notice-error inline"><p>Falta el archivo <code>seo-growth-executive.php</code>.</p></div>';
        }
    } elseif ($active_tab === 'google_intelligence') {
        seo_google_intelligence_page();
    } elseif ($active_tab === 'post_opportunities') {
        if (function_exists('seo_post_opportunities_render_page')) {
            seo_post_opportunities_render_page();
        } else {
            echo '<div class="notice notice-error inline"><p>Falta el archivo <code>seo-post-opportunities.php</code>.</p></div>';
        }
    }
    echo '</div>';
}




/**
 * Renderiza una tarjeta visual del resumen ejecutivo para dirección / SteerCo.
 *
 * Compatible con el uso anterior:
 *
 * seo_render_executive_summary_card(
 *     $title,
 *     $number,
 *     $description,
 *     $status
 * );
 *
 * También admite contexto ejecutivo ampliado mediante $args.
 *
 * @param string $title
 * @param mixed  $number
 * @param string $description
 * @param string $status
 * @param array  $args
 */
function seo_render_executive_summary_card(
    $title,
    $number,
    $description,
    $status = 'gray',
    $args = array()
) {

    $defaults = array(
        'secondary'     => '',
        'trend'         => '',
        'trend_status'  => 'neutral',
        'impact'        => '',
        'decision'      => '',
        'details_title' => 'Ver lectura ejecutiva',
        'details'       => array(),
        'action_url'    => '',
        'action_label'  => '',
        'footnote'      => '',
    );

    $args = wp_parse_args($args, $defaults);

    /*
     * Normalización de estados.
     */
    $status = sanitize_key((string) $status);

    if ($status === 'ok') {
        $status = 'green';
    } elseif ($status === 'warning') {
        $status = 'yellow';
    } elseif ($status === 'risk' || $status === 'error') {
        $status = 'red';
    } elseif ($status === 'info') {
        $status = 'gray';
    }

    /*
     * Clases visuales.
     */
    $class_map = array(
        'green'  => 'seo-exec-green',
        'yellow' => 'seo-exec-yellow',
        'red'    => 'seo-exec-red',
        'gray'   => 'seo-exec-gray',
    );

    $label_map = array(
        'green'  => 'OK',
        'yellow' => 'Revisar',
        'red'    => 'Riesgo',
        'gray'   => 'Sin datos',
    );

    $class = isset($class_map[$status])
        ? $class_map[$status]
        : $class_map['gray'];

    $label = isset($label_map[$status])
        ? $label_map[$status]
        : $label_map['gray'];

    /*
     * Formateo flexible.
     * Permite porcentajes, textos o números.
     */
    if (
        is_int($number) ||
        is_float($number) ||
        (is_string($number) && is_numeric($number))
    ) {
        $display_number = number_format_i18n((float) $number, 1);
    } else {
        $display_number = (string) $number;
    }

    /*
     * Tarjeta.
     */
    echo '<div class="seo-exec-card ' . esc_attr($class) . '">';

    echo '<div class="seo-exec-card-header">';
    echo '<div class="seo-exec-label">' . esc_html($title) . '</div>';
    echo '<span class="seo-exec-status">' . esc_html($label) . '</span>';
    echo '</div>';

    echo '<div class="seo-exec-number">' . esc_html($display_number) . '</div>';

    /*
     * Tendencia.
     */
    if (!empty($args['trend'])) {

        echo '<div class="seo-exec-trend seo-exec-trend-' .
            esc_attr(sanitize_key($args['trend_status'])) .
            '">';

        echo esc_html($args['trend']);

        echo '</div>';
    }

    echo '<div class="seo-exec-description">';
    echo esc_html($description);
    echo '</div>';

    /*
     * Contexto secundario.
     */
    if (!empty($args['secondary'])) {

        echo '<div class="seo-exec-secondary">';
        echo esc_html($args['secondary']);
        echo '</div>';
    }

    /*
     * Impacto.
     */
    if (!empty($args['impact'])) {

        echo '<div class="seo-exec-impact">';
        echo '📈 ' . esc_html($args['impact']);
        echo '</div>';
    }

    /*
     * Decisión recomendada.
     */
    if (!empty($args['decision'])) {

        echo '<div class="seo-exec-decision">';
        echo '📌 ' . esc_html($args['decision']);
        echo '</div>';
    }

    /*
     * Lectura ejecutiva ampliada.
     */
    if (!empty($args['details']) && is_array($args['details'])) {

        echo '<details class="seo-exec-details">';

        echo '<summary>';
        echo esc_html($args['details_title']);
        echo '</summary>';

        echo '<div class="seo-exec-details-content">';

        foreach ($args['details'] as $detail_title => $detail_text) {

            echo '<div class="seo-exec-detail-block">';

            echo '<strong>';
            echo esc_html($detail_title);
            echo '</strong>';

            echo '<p>';
            echo wp_kses_post($detail_text);
            echo '</p>';

            echo '</div>';
        }

        echo '</div>';
        echo '</details>';
    }

    /*
     * Enlace a informe técnico.
     */
    if (!empty($args['action_url']) && !empty($args['action_label'])) {

        echo '<p class="seo-exec-action">';

        echo '' .
            esc_url($args['action_url']) .
            '';

        echo esc_html($args['action_label']);

        echo '</a>';

        echo '</p>';
    }

    /*
     * Nota al pie opcional.
     */
    if (!empty($args['footnote'])) {

        echo '<div class="seo-exec-footnote">';
        echo esc_html($args['footnote']);
        echo '</div>';
    }

    echo '</div>';
}



/**
 * Precarga la semantica canonica de categorias desde Vocabulary.
 *
 * @param int[] $category_ids IDs de product_cat.
 * @return array<int,array<string,array<int,string>>>
 */
function seo_reports_category_vocabulary_map($category_ids = array()) {
    global $wpdb;

    $category_ids = array_values(array_unique(array_filter(array_map('absint', (array) $category_ids))));
    $where_ids = '';
    if (!empty($category_ids)) {
        $where_ids = ' AND ov.object_id IN (' . implode(',', $category_ids) . ')';
    }

    $rows = $wpdb->get_results(
        "SELECT ov.object_id, v.semantic_group, v.label\n"
        . "FROM {$wpdb->prefix}seo_object_vocabulary ov\n"
        . "JOIN {$wpdb->prefix}seo_vocabulary v ON v.id = ov.vocabulary_id\n"
        . "WHERE ov.object_type = 'product_cat'\n"
        . "  AND ov.status = 1\n"
        . "  AND v.active = 1\n"
        . "  AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')\n"
        . $where_ids . "\n"
        . "ORDER BY ov.object_id, FIELD(v.semantic_group,'rol','tipo','aplicacion','plataforma','subtipo'), v.label"
    );

    $map = array();
    foreach ((array) $rows as $row) {
        $object_id = absint($row->object_id ?? 0);
        $group = sanitize_key((string) ($row->semantic_group ?? ''));
        $label = trim((string) ($row->label ?? ''));
        if ($object_id < 1 || $label === '' || !in_array($group, array('rol','tipo','aplicacion','plataforma','subtipo'), true)) {
            continue;
        }
        if (!isset($map[$object_id])) $map[$object_id] = array();
        if (!isset($map[$object_id][$group])) $map[$object_id][$group] = array();
        if (!in_array($label, $map[$object_id][$group], true)) $map[$object_id][$group][] = $label;
    }

    return $map;
}

/**
 * PESTAÑA: Categorías (Antigua Estructura Total)
 */
function seo_render_total_structure_report() {
    global $wpdb;
    
    // Título interno ajustado para coincidir con el renombrado[cite: 1]
    echo '<h2>📦 Estructura Completa de Categorías</h2>';
    echo '<p>A continuación se listan todas las categorías del sistema con sus descripciones y los productos correspondientes indexados.</p>';
    
    $categories = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC'
    ]);
    
        if (!empty($categories) && !is_wp_error($categories)) {

            $category_vocabulary = seo_reports_category_vocabulary_map(
                array_map(static function ($term) { return absint($term->term_id); }, $categories)
            );
        
            echo '<div style="margin-top: 20px;">';
        
            //MUESTRA INFORMACION DE LAS CATEGORIAS
            foreach ($categories as $cat) {
            
                echo "<div style='background:#fff;border:1px solid #ccd0d4;padding:20px;margin-bottom:25px;box-shadow:0 1px 3px rgba(0,0,0,0.05);border-left:5px solid #2e7d32;'>";
            
            
                /*
                |--------------------------------------------------------------------------
                | DATOS PRINCIPALES
                |--------------------------------------------------------------------------
                */
            
                echo "<h2 style='margin:0 0 15px 0;color:#1d2327;font-size:20px;'>
                📦 ".esc_html($cat->name)."
                </h2>";
            
            
                echo "<div style='background:#f8fafc;padding:15px;border-radius:4px;line-height:1.8;'>";
            
            
                // ID
            
                echo "<strong>ID:</strong> ".intval($cat->term_id)."<br>";
            
            
                // SLUG
            
                echo "<strong>Slug:</strong> ".esc_html($cat->slug)."<br>";
            
            
                // ENLACE
            
                $cat_url = get_term_link($cat->term_id,'product_cat');
            
                echo "<strong>Enlace:</strong> ";
            
                if (!is_wp_error($cat_url)) {
            
                    echo "<a href='".esc_url($cat_url)."' target='_blank'>"
                    .esc_html($cat_url).
                    "</a>";
            
                }
            
                echo "<br>";
            
            
            
                /*
                |--------------------------------------------------------------------------
                | JERARQUÍA
                |--------------------------------------------------------------------------
                */
            
                echo "<strong>Jerarquía:</strong><br>";
            
                $hierarchy = [];
            
                $current = $cat;
            
                while ($current && !is_wp_error($current)) {
            
                    $hierarchy[] = $current->name;
            
                    if (!$current->parent) {
                        break;
                    }
            
                    $current = get_term($current->parent,'product_cat');
                }
            
            
                echo implode(
                    " → ",
                    array_reverse($hierarchy)
                );
            
            
                echo "</div>";
            
            
            
            /*
            |--------------------------------------------------------------------------
            | VOCABULARY CANONICO
            |--------------------------------------------------------------------------
            */
            echo "<div style='margin-top:15px;padding:12px;background:#fff7ed;border-left:3px solid #ff9800;'>";
            echo "<strong>🏷 Vocabulary:</strong><br>";

            $semantic_groups = $category_vocabulary[$cat->term_id] ?? array();
            $group_labels = array(
                'rol'        => 'ROL',
                'tipo'       => 'TIPO',
                'aplicacion' => 'APLICACIÓN',
                'plataforma' => 'PLATAFORMA',
                'subtipo'    => 'SUBTIPO',
            );

            $has_semantics = false;
            foreach ($group_labels as $group_key => $group_label) {
                foreach ((array) ($semantic_groups[$group_key] ?? array()) as $label) {
                    $has_semantics = true;
                    echo "<span style='display:inline-block;background:#eee;padding:3px 8px;margin:3px;border-radius:3px;'>"
                        . '<strong>' . esc_html($group_label) . ':</strong> ' . esc_html($label)
                        . "</span>";
                }
            }

            if (!$has_semantics) {
                echo "<em>Sin Vocabulary canónico.</em>";
            }

            echo "</div>";


                /*
                |--------------------------------------------------------------------------
                | EXCERPT / DESCRIPCIÓN
                |--------------------------------------------------------------------------
                */
            
                echo "<div style='margin-top:15px;padding:12px;background:#f6f7f7;border-left:3px solid #2196f3;'>";
                //Excerpt
                $seo_excerpt = $wpdb->get_var($wpdb->prepare(
                    "
                    SELECT keywords
                    FROM {$wpdb->prefix}seo_nodes
                    WHERE object_id = %d
                    AND seo_role = 'excerpt'
                    LIMIT 1
                    ",
                    $cat->term_id
                ));
                //Description que se obtiene de tabla porque Wordpress no almacena etiquetas html
                $seo_description = $wpdb->get_var($wpdb->prepare("
                    SELECT keywords
                    FROM {$wpdb->prefix}seo_nodes
                    WHERE object_id = %d
                      AND seo_role = 'description'
                    LIMIT 1
                ", $cat->term_id));
                
                echo "Excerpt / Descripción corta:\n";
                
                echo "Excerpt:\n";
                if (!empty($seo_excerpt)) {
                    echo wp_kses_post($seo_excerpt);
                } else {
                    echo "Sin contenido.";
                }
            
            
                echo "</div>";
            
            
            
                /*
                |--------------------------------------------------------------------------
                | DESCRIPCIÓN COMPLETA
                |--------------------------------------------------------------------------
                */
            
                echo "<div style='margin-top:15px;padding:12px;background:#f9fafb;border-left:3px solid #6366f1;'>";
            
                //Info de description que se obtiene de las tablas
                    echo "Descripción:\n";
                    
                    if (!empty($seo_description)) {
                        echo wp_kses_post($seo_description);
                    } else {
                        echo "Sin contenido.";
                    }
            
            
                echo "</div>";
            
            
            
                echo "</div>";
            
            }



            
        
            echo '</div>';
        
        } else {
        
            echo '<div class="notice notice-warning">
            <p>No se encontraron categorías de producto en la base de datos de WooCommerce.</p>
            </div>';
        
        }
}
    



/**
 * Devuelve el nombre de la tabla de FAQs si existe.
 *
 * @return string|false
 */
function seo_get_faq_table_name() {
    global $wpdb;

    $faq_table = $wpdb->prefix . 'seo_faq';
    $faq_exists = $wpdb->get_var(
        $wpdb->prepare('SHOW TABLES LIKE %s', $faq_table)
    );

    return $faq_exists === $faq_table ? $faq_table : false;
}


/**
 * Borra por ID filas ya inventariadas usando exclusivamente SEO Data Layer.
 *
 * @param string $table_key Clave logica registrada.
 * @param int[]  $ids       Claves primarias id.
 * @param string $type      Tipo de operacion.
 * @param string $label     Etiqueta auditada.
 * @param array  $metadata  Contexto adicional.
 * @return int Filas eliminadas.
 */
function seo_reports_data_layer_delete_ids($table_key, $ids, $type, $label, $metadata = array()) {
    $ids = array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
    if (empty($ids)) return 0;

    if (!class_exists('SEO_Data_Layer') || !class_exists('SEO_Data_Operation')) {
        throw new RuntimeException('SEO Data Layer no está disponible. No se ha eliminado ninguna fila SEO.');
    }

    SEO_Data_Layer::table($table_key);
    $operation = SEO_Data_Layer::operation(array(
        'type'          => sanitize_key($type),
        'label'         => sanitize_text_field($label),
        'source_module' => 'seo_reports',
        'rollbackable'  => true,
        'risk_level'    => 'medium',
        'audit_level'   => 'full',
        'metadata'      => array_merge((array) $metadata, array('table_key' => $table_key, 'rows' => count($ids))),
    ));
    $operation->mark_validated(array('validated_rows' => count($ids)));
    $operation->mark_previewed(count($ids));

    return (int) $operation->execute(
        static function (SEO_Data_Operation $op) use ($table_key, $ids, $metadata) {
            $deleted = 0;
            foreach ($ids as $id) {
                $op->delete($table_key, array('id' => $id), (array) $metadata);
                $deleted++;
            }
            return $deleted;
        }
    );
}

/**
 * Limpia datos SEO propios de una product_cat ya eliminada.
 * WordPress gestiona term/term_taxonomy; nuestras tablas se limpian por Data Layer.
 */
function seo_reports_cleanup_deleted_category_data($term_id, $faq_table = false) {
    global $wpdb;

    $term_id = absint($term_id);
    $empty = array('relations' => 0, 'nodes' => 0, 'vocabulary' => 0, 'faqs' => 0);
    if ($term_id < 1) return $empty;

    if (!class_exists('SEO_Data_Layer') || !class_exists('SEO_Data_Operation')) {
        throw new RuntimeException('SEO Data Layer no está disponible.');
    }

    $ids = array(
        'relations' => $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}seo_relations\n"
            . "WHERE (source_type='product_cat' AND source_id=%d)\n"
            . "   OR (target_type='product_cat' AND target_id=%d)",
            $term_id,
            $term_id
        )),
        'nodes' => $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}seo_nodes WHERE object_type='category' AND object_id=%d",
            $term_id
        )),
        'object_vocabulary' => $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}seo_object_vocabulary WHERE object_type='product_cat' AND object_id=%d",
            $term_id
        )),
        'faqs' => array(),
    );

    if ($faq_table) {
        $ids['faqs'] = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$faq_table} WHERE object_type=2 AND object_id=%d",
            $term_id
        ));
    }

    foreach ($ids as $table_key => $row_ids) {
        $ids[$table_key] = array_values(array_unique(array_filter(array_map('absint', (array) $row_ids))));
        if (!empty($ids[$table_key])) SEO_Data_Layer::table($table_key);
    }

    $expected = array_sum(array_map('count', $ids));
    if ($expected < 1) return $empty;

    $operation = SEO_Data_Layer::operation(array(
        'type'          => 'delete_category_seo_data',
        'label'         => 'Limpiar datos SEO de categoría eliminada #' . $term_id,
        'source_module' => 'seo_reports',
        'rollbackable'  => true,
        'risk_level'    => 'medium',
        'audit_level'   => 'full',
        'metadata'      => array(
            'related_object_type' => 'product_cat',
            'related_object_id'   => $term_id,
            'relations'           => count($ids['relations']),
            'nodes'               => count($ids['nodes']),
            'vocabulary'          => count($ids['object_vocabulary']),
            'faqs'                => count($ids['faqs']),
        ),
    ));
    $operation->mark_validated(array('validated_rows' => $expected));
    $operation->mark_previewed($expected);

    return (array) $operation->execute(
        static function (SEO_Data_Operation $op) use ($ids, $term_id) {
            $counts = array('relations' => 0, 'nodes' => 0, 'vocabulary' => 0, 'faqs' => 0);
            foreach ($ids['relations'] as $id) {
                $op->delete('relations', array('id' => $id), array('related_object_type' => 'product_cat', 'related_object_id' => $term_id));
                $counts['relations']++;
            }
            foreach ($ids['nodes'] as $id) {
                $op->delete('nodes', array('id' => $id), array('related_object_type' => 'product_cat', 'related_object_id' => $term_id));
                $counts['nodes']++;
            }
            foreach ($ids['object_vocabulary'] as $id) {
                $op->delete('object_vocabulary', array('id' => $id), array('related_object_type' => 'product_cat', 'related_object_id' => $term_id));
                $counts['vocabulary']++;
            }
            foreach ($ids['faqs'] as $id) {
                $op->delete('faqs', array('id' => $id), array('related_object_type' => 'product_cat', 'related_object_id' => $term_id));
                $counts['faqs']++;
            }
            return $counts;
        }
    );
}

/**
 * Elimina únicamente FAQs de categorías product_cat que ya no existen.
 * La condición de orfandad se recalcula en el momento del borrado.
 */
function seo_delete_orphan_category_faqs_handler() {
    global $wpdb;

    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para realizar esta acción.');
    }

    check_admin_referer(
        'seo_delete_orphan_category_faqs',
        'seo_faq_orphan_nonce'
    );

    $faq_table = seo_get_faq_table_name();

    if (!$faq_table) {
        wp_die('No se encuentra la tabla de FAQs.');
    }

    $orphan_ids = $wpdb->get_col(
        "SELECT f.id
         FROM {$faq_table} f
         LEFT JOIN {$wpdb->term_taxonomy} tt
            ON tt.term_id = f.object_id
           AND tt.taxonomy = 'product_cat'
         WHERE f.object_type = 2
           AND tt.term_id IS NULL
         ORDER BY f.id ASC"
    );

    try {
        $deleted = seo_reports_data_layer_delete_ids(
            'faqs',
            $orphan_ids,
            'delete_orphan_category_faqs',
            'Eliminar FAQs huérfanas de categorías',
            array('object_type' => 2, 'reason' => 'orphan_category')
        );
    } catch (Throwable $e) {
        wp_die('No se pudieron eliminar las FAQs huérfanas de categorías: ' . esc_html($e->getMessage()));
    }

    $redirect_url = add_query_arg(
        array(
            'page'                         => 'seo-reports',
            'tab'                          => 'anomalias',
            'faq_category_orphans_deleted' => (int) $deleted,
        ),
        admin_url('admin.php')
    );

    wp_safe_redirect($redirect_url);
    exit;
}

/**
 * Elimina únicamente FAQs marcadas como producto cuyo producto ya no existe.
 * No elimina FAQs de productos que simplemente estén en draft/private/trash:
 * esos casos siguen apareciendo como incidencia separada para revisión manual.
 */
function seo_delete_orphan_product_faqs_handler() {
    global $wpdb;

    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para realizar esta acción.');
    }

    check_admin_referer(
        'seo_delete_orphan_product_faqs',
        'seo_faq_orphan_nonce'
    );

    $faq_table = seo_get_faq_table_name();

    if (!$faq_table) {
        wp_die('No se encuentra la tabla de FAQs.');
    }

    $orphan_ids = $wpdb->get_col(
        "SELECT f.id
         FROM {$faq_table} f
         LEFT JOIN {$wpdb->posts} p
            ON p.ID = f.object_id
         WHERE f.object_type = 3
           AND (p.ID IS NULL OR p.post_type <> 'product')
         ORDER BY f.id ASC"
    );

    try {
        $deleted = seo_reports_data_layer_delete_ids(
            'faqs',
            $orphan_ids,
            'delete_orphan_product_faqs',
            'Eliminar FAQs huérfanas de productos',
            array('object_type' => 3, 'reason' => 'orphan_product')
        );
    } catch (Throwable $e) {
        wp_die('No se pudieron eliminar las FAQs huérfanas de productos: ' . esc_html($e->getMessage()));
    }

    $redirect_url = add_query_arg(
        array(
            'page'                        => 'seo-reports',
            'tab'                         => 'anomalias',
            'faq_product_orphans_deleted' => (int) $deleted,
        ),
        admin_url('admin.php')
    );

    wp_safe_redirect($redirect_url);
    exit;
}

/**
 * Renderiza el chequeo de FAQs huérfanas dentro de la pestaña Anomalías.
 *
 * Este bloque pertenece al informe estructural porque detecta datos inconexos:
 * FAQs que siguen existiendo aunque su categoría, producto o página ya no exista.
 */
function seo_render_faq_orphan_anomalies_report() {
    global $wpdb;

    $faq_table = seo_get_faq_table_name();

    echo '<h3 style="color:#d63638; border-bottom:1px solid #ccd0d4; padding-bottom:5px; margin-top:40px;">🧩 FAQs sin elemento relacionado</h3>';
    echo '<p>Detecta FAQs que quedaron vivas después de borrar, mover o enviar a papelera categorías, productos o páginas/hubs.</p>';

    if (!$faq_table) {
        echo '<p style="color:#b32d2e;">No se encuentra la tabla <code>' . esc_html($wpdb->prefix . 'seo_faq') . '</code>.</p>';
        return;
    }

    if (isset($_GET['faq_category_orphans_deleted'])) {
        $deleted = absint($_GET['faq_category_orphans_deleted']);
        echo '<div class="notice notice-success inline"><p>FAQs de categorías desaparecidas eliminadas: <strong>' . esc_html(number_format_i18n($deleted)) . '</strong>.</p></div>';
    }

    if (isset($_GET['faq_product_orphans_deleted'])) {
        $deleted = absint($_GET['faq_product_orphans_deleted']);
        echo '<div class="notice notice-success inline"><p>FAQs de productos desaparecidos eliminadas: <strong>' . esc_html(number_format_i18n($deleted)) . '</strong>.</p></div>';
    }

    $category_total = (int) $wpdb->get_var(
        "SELECT COUNT(*)
         FROM {$faq_table} f
         LEFT JOIN {$wpdb->term_taxonomy} tt
            ON tt.term_id = f.object_id
           AND tt.taxonomy = 'product_cat'
         WHERE f.object_type = 2
           AND tt.term_id IS NULL"
    );

    $category_objects = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT f.object_id)
         FROM {$faq_table} f
         LEFT JOIN {$wpdb->term_taxonomy} tt
            ON tt.term_id = f.object_id
           AND tt.taxonomy = 'product_cat'
         WHERE f.object_type = 2
           AND tt.term_id IS NULL"
    );

    $product_orphans = (int) $wpdb->get_var(
        "SELECT COUNT(*)
         FROM {$faq_table} f
         LEFT JOIN {$wpdb->posts} p
            ON p.ID = f.object_id
         WHERE f.object_type = 3
           AND (p.ID IS NULL OR p.post_type <> 'product')"
    );

    $product_objects = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT f.object_id)
         FROM {$faq_table} f
         LEFT JOIN {$wpdb->posts} p
            ON p.ID = f.object_id
         WHERE f.object_type = 3
           AND (p.ID IS NULL OR p.post_type <> 'product')"
    );

    $product_not_published = (int) $wpdb->get_var(
        "SELECT COUNT(*)
         FROM {$faq_table} f
         INNER JOIN {$wpdb->posts} p
            ON p.ID = f.object_id
         WHERE f.object_type = 3
           AND p.post_type = 'product'
           AND p.post_status <> 'publish'"
    );

    $hub_orphans = (int) $wpdb->get_var(
        "SELECT COUNT(*)
         FROM {$faq_table} f
         LEFT JOIN {$wpdb->posts} p
            ON p.ID = f.object_id
         WHERE f.object_type = 1
           AND p.ID IS NULL"
    );

    $hub_trash = (int) $wpdb->get_var(
        "SELECT COUNT(*)
         FROM {$faq_table} f
         INNER JOIN {$wpdb->posts} p
            ON p.ID = f.object_id
         WHERE f.object_type = 1
           AND p.post_status = 'trash'"
    );

    $unknown_type = (int) $wpdb->get_var(
        "SELECT COUNT(*)
         FROM {$faq_table}
         WHERE object_type NOT IN (1, 2, 3)"
    );

    echo '<table class="widefat striped" style="margin:10px 0 20px;">';
    echo '<thead><tr><th>Chequeo</th><th>Resultado</th><th>Lectura</th></tr></thead><tbody>';
    seo_render_faq_orphan_anomaly_row('FAQs en categorías inexistentes', $category_total, 'Afecta a ' . $category_objects . ' object_id de categoría.');
    seo_render_faq_orphan_anomaly_row('FAQs en productos inexistentes', $product_orphans, 'Afecta a ' . $product_objects . ' object_id de producto.');
    seo_render_faq_orphan_anomaly_row('FAQs en productos no publicados', $product_not_published, 'FAQs asociadas a productos draft, private, trash u otro estado no publicado. No se borran con la limpieza de productos desaparecidos.');
    seo_render_faq_orphan_anomaly_row('FAQs en hubs/páginas inexistentes', $hub_orphans, 'FAQs asociadas a páginas/hubs eliminados.');
    seo_render_faq_orphan_anomaly_row('FAQs en hubs/páginas en papelera', $hub_trash, 'FAQs asociadas a páginas/hubs existentes pero en papelera.');
    seo_render_faq_orphan_anomaly_row('FAQs con object_type desconocido', $unknown_type, 'FAQs cuyo object_type no es 1, 2 ni 3.');
    echo '</tbody></table>';

    if ($category_total === 0 && $product_orphans === 0 && $product_not_published === 0 && $hub_orphans === 0 && $hub_trash === 0 && $unknown_type === 0) {
        echo '<p style="color:#2e7d32; font-style:italic;">No se encontraron incidencias estructurales en FAQs.</p>';
        return;
    }

    $category_summary = $wpdb->get_results(
        "SELECT
            f.object_id AS lost_object_id,
            COUNT(*) AS total_faqs,
            SUM(f.active = 1) AS active_faqs,
            SUM(f.active = 0) AS inactive_faqs,
            MIN(f.created_at) AS first_created_at,
            MAX(f.updated_at) AS last_updated_at
         FROM {$faq_table} f
         LEFT JOIN {$wpdb->term_taxonomy} tt
            ON tt.term_id = f.object_id
           AND tt.taxonomy = 'product_cat'
         WHERE f.object_type = 2
           AND tt.term_id IS NULL
         GROUP BY f.object_id
         ORDER BY total_faqs DESC, f.object_id ASC
         LIMIT 30"
    );

    if (!empty($category_summary)) {
        echo '<div style="margin-top:20px;padding:14px;background:#fff;border:1px solid #dcdcde;border-left:4px solid #d63638;">';
        echo '<h4 style="margin:0 0 10px;">Resumen de categorías perdidas con FAQs</h4>';
        echo '<p style="margin:0 0 12px;">Hay <strong>' . esc_html(number_format_i18n($category_total)) . ' FAQs</strong> ligadas a <strong>' . esc_html(number_format_i18n($category_objects)) . ' categorías desaparecidas</strong>. Se muestran hasta 30 object_id.</p>';
        echo '<table class="widefat striped" style="margin-top:10px;">';
        echo '<thead><tr><th>Object ID perdido</th><th>FAQs</th><th>Activas</th><th>Inactivas</th><th>Primera creación</th><th>Última actualización</th></tr></thead><tbody>';

        foreach ($category_summary as $row) {
            echo '<tr>';
            echo '<td><code>' . esc_html((string) $row->lost_object_id) . '</code></td>';
            echo '<td>' . esc_html((string) (int) $row->total_faqs) . '</td>';
            echo '<td>' . esc_html((string) (int) $row->active_faqs) . '</td>';
            echo '<td>' . esc_html((string) (int) $row->inactive_faqs) . '</td>';
            echo '<td>' . esc_html((string) $row->first_created_at) . '</td>';
            echo '<td>' . esc_html((string) $row->last_updated_at) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;" onsubmit="return confirm(\'Se eliminarán definitivamente todas las FAQs cuya categoría de producto ya no exista. La condición se comprobará de nuevo al ejecutar el borrado. ¿Continuar?\');">';
        echo '<input type="hidden" name="action" value="seo_delete_orphan_category_faqs">';
        wp_nonce_field('seo_delete_orphan_category_faqs', 'seo_faq_orphan_nonce');
        echo '<button type="submit" class="button button-primary" style="background:#d63638;border-color:#d63638;">Borrar FAQs de categorías desaparecidas</button>';
        echo '</form>';
        echo '</div>';
    }

    $product_summary = $wpdb->get_results(
        "SELECT
            f.object_id AS lost_object_id,
            COUNT(*) AS total_faqs,
            SUM(f.active = 1) AS active_faqs,
            SUM(f.active = 0) AS inactive_faqs,
            MIN(f.created_at) AS first_created_at,
            MAX(f.updated_at) AS last_updated_at
         FROM {$faq_table} f
         LEFT JOIN {$wpdb->posts} p
            ON p.ID = f.object_id
         WHERE f.object_type = 3
           AND (p.ID IS NULL OR p.post_type <> 'product')
         GROUP BY f.object_id
         ORDER BY total_faqs DESC, f.object_id ASC
         LIMIT 30"
    );

    if (!empty($product_summary)) {
        echo '<div style="margin-top:20px;padding:14px;background:#fff;border:1px solid #dcdcde;border-left:4px solid #d63638;">';
        echo '<h4 style="margin:0 0 10px;">Resumen de productos perdidos con FAQs</h4>';
        echo '<p style="margin:0 0 12px;">Hay <strong>' . esc_html(number_format_i18n($product_orphans)) . ' FAQs</strong> ligadas a <strong>' . esc_html(number_format_i18n($product_objects)) . ' productos desaparecidos</strong>. Se muestran hasta 30 object_id.</p>';
        echo '<table class="widefat striped" style="margin-top:10px;">';
        echo '<thead><tr><th>Object ID perdido</th><th>FAQs</th><th>Activas</th><th>Inactivas</th><th>Primera creación</th><th>Última actualización</th></tr></thead><tbody>';

        foreach ($product_summary as $row) {
            echo '<tr>';
            echo '<td><code>' . esc_html((string) $row->lost_object_id) . '</code></td>';
            echo '<td>' . esc_html((string) (int) $row->total_faqs) . '</td>';
            echo '<td>' . esc_html((string) (int) $row->active_faqs) . '</td>';
            echo '<td>' . esc_html((string) (int) $row->inactive_faqs) . '</td>';
            echo '<td>' . esc_html((string) $row->first_created_at) . '</td>';
            echo '<td>' . esc_html((string) $row->last_updated_at) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;" onsubmit="return confirm(\'Se eliminarán definitivamente todas las FAQs cuyo producto ya no exista. No se borrarán FAQs de productos que sigan existiendo en draft, private o trash. ¿Continuar?\');">';
        echo '<input type="hidden" name="action" value="seo_delete_orphan_product_faqs">';
        wp_nonce_field('seo_delete_orphan_product_faqs', 'seo_faq_orphan_nonce');
        echo '<button type="submit" class="button button-primary" style="background:#d63638;border-color:#d63638;">Borrar FAQs de productos desaparecidos</button>';
        echo '</form>';
        echo '</div>';
    }

    echo '<p style="margin-top:16px;"><strong>Nota:</strong> estas acciones limpian solo FAQs verdaderamente huérfanas. Los productos que todavía existen pero no están publicados permanecen fuera del borrado automático para revisión manual.</p>';
}

/**
 * Renderiza una fila de estado para el bloque de FAQs huérfanas.
 */
function seo_render_faq_orphan_anomaly_row($label, $count, $description) {
    $count = (int) $count;
    $is_warning = $count > 0;
    $color = $is_warning ? '#b32d2e' : '#2e7d32';
    $state = $is_warning ? 'Revisar' : 'OK';

    echo '<tr>';
    echo '<td><strong>' . esc_html($label) . '</strong></td>';
    echo '<td><strong style="color:' . esc_attr($color) . ';">' . esc_html($state) . '</strong> · ' . esc_html(number_format_i18n($count)) . '</td>';
    echo '<td>' . esc_html($description) . '</td>';
    echo '</tr>';
}


/**
 * Comprueba si una categoría product_cat puede eliminarse con seguridad.
 *
 * La categoría solo es eliminable cuando:
 * - existe como product_cat;
 * - no es la categoría predeterminada de WooCommerce;
 * - no tiene ningún producto relacionado, independientemente de su estado;
 * - no tiene subcategorías hijas.
 */
function seo_get_empty_product_category_delete_state($term_id) {

    global $wpdb;

    $term_id = absint($term_id);

    $state = array(
        'eligible'      => false,
        'reason'        => '',
        'term'          => null,
        'product_count' => 0,
        'child_count'   => 0,
    );

    if ($term_id <= 0) {
        $state['reason'] = 'ID de categoría no válido.';
        return $state;
    }

    $term = get_term($term_id, 'product_cat');

    if (!$term || is_wp_error($term)) {
        $state['reason'] = 'La categoría ya no existe.';
        return $state;
    }

    $state['term'] = $term;

    $default_product_cat = absint(get_option('default_product_cat'));

    if ($default_product_cat > 0 && $default_product_cat === $term_id) {
        $state['reason'] = 'Es la categoría predeterminada de WooCommerce.';
        return $state;
    }

    $term_taxonomy_id = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT term_taxonomy_id
             FROM {$wpdb->term_taxonomy}
             WHERE term_id = %d
               AND taxonomy = 'product_cat'
             LIMIT 1",
            $term_id
        )
    );

    if ($term_taxonomy_id <= 0) {
        $state['reason'] = 'No existe como taxonomía product_cat.';
        return $state;
    }

    // No dependemos solo de term_taxonomy.count: comprobamos relaciones reales
    // con productos en cualquier estado para evitar borrar una categoría usada.
    $state['product_count'] = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(DISTINCT tr.object_id)
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->posts} p
                ON p.ID = tr.object_id
               AND p.post_type IN ('product', 'product_variation')
             WHERE tr.term_taxonomy_id = %d",
            $term_taxonomy_id
        )
    );

    if ($state['product_count'] > 0) {
        $state['reason'] = 'Tiene productos relacionados actualmente.';
        return $state;
    }

    $state['child_count'] = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->term_taxonomy}
             WHERE taxonomy = 'product_cat'
               AND parent = %d",
            $term_id
        )
    );

    if ($state['child_count'] > 0) {
        $state['reason'] = 'Tiene subcategorías hijas.';
        return $state;
    }

    $state['eligible'] = true;
    $state['reason'] = 'Sin productos y sin subcategorías.';

    return $state;
}

/**
 * Elimina de forma irreversible las categorías vacías seleccionadas desde
 * Anomalías. Cada categoría se vuelve a validar justo antes del borrado.
 */
function seo_delete_empty_product_categories_handler() {

    global $wpdb;

    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para realizar esta acción.');
    }

    check_admin_referer(
        'seo_delete_empty_product_categories',
        'seo_empty_categories_nonce'
    );

    $category_ids = isset($_POST['category_ids'])
        ? (array) wp_unslash($_POST['category_ids'])
        : array();

    $category_ids = array_values(
        array_unique(
            array_filter(
                array_map('absint', $category_ids)
            )
        )
    );

    $deleted = 0;
    $skipped = 0;
    $relations_deleted = 0;
    $nodes_deleted = 0;
    $faqs_deleted = 0;
    $vocabulary_deleted = 0;

    $faq_table       = function_exists('seo_get_faq_table_name')
        ? seo_get_faq_table_name()
        : false;

    foreach ($category_ids as $term_id) {

        $state = seo_get_empty_product_category_delete_state($term_id);

        if (empty($state['eligible'])) {
            $skipped++;
            continue;
        }

        // WordPress se encarga de term_taxonomy, termmeta y relaciones estándar.
        $result = wp_delete_term($term_id, 'product_cat');

        if (!$result || is_wp_error($result)) {
            $skipped++;
            continue;
        }

        // Limpia nuestras tablas únicamente mediante SEO Data Layer.
        try {
            $cleanup = seo_reports_cleanup_deleted_category_data($term_id, $faq_table);
            $relations_deleted += (int) ($cleanup['relations'] ?? 0);
            $nodes_deleted += (int) ($cleanup['nodes'] ?? 0);
            $vocabulary_deleted += (int) ($cleanup['vocabulary'] ?? 0);
            $faqs_deleted += (int) ($cleanup['faqs'] ?? 0);
        } catch (Throwable $e) {
            // La categoría WordPress ya fue eliminada. Dejamos el residuo visible
            // para que la auditoría pueda detectarlo en lugar de ejecutar SQL directo.
            error_log('[SEO Reports] Limpieza Data Layer de categoría #' . $term_id . ': ' . $e->getMessage());
        }

        $deleted++;
    }

    $redirect_url = add_query_arg(
        array(
            'page'                         => 'seo-reports',
            'tab'                          => 'anomalias',
            'empty_categories_deleted'     => $deleted,
            'empty_categories_skipped'     => $skipped,
            'empty_relations_deleted'      => $relations_deleted,
            'empty_nodes_deleted'          => $nodes_deleted,
            'empty_vocabulary_deleted'     => $vocabulary_deleted,
            'empty_category_faqs_deleted'  => $faqs_deleted,
        ),
        admin_url('admin.php')
    );

    wp_safe_redirect($redirect_url);
    exit;
}


/**
 * Recalcula los contadores de todas las categorías de producto utilizando
 * la API de WordPress/WooCommerce.
 *
 * No mueve, publica, elimina ni modifica productos o categorías.
 * Solo reconstruye los contadores almacenados de product_cat.
 */
function seo_recount_product_categories_handler() {

    global $wpdb;

    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para realizar esta acción.');
    }

    check_admin_referer(
        'seo_recount_product_categories',
        'seo_recount_categories_nonce'
    );

    $term_taxonomy_ids = $wpdb->get_col(
        "SELECT term_taxonomy_id
         FROM {$wpdb->term_taxonomy}
         WHERE taxonomy = 'product_cat'"
    );

    $term_taxonomy_ids = array_values(
        array_unique(
            array_filter(
                array_map('intval', $term_taxonomy_ids)
            )
        )
    );

    if (!empty($term_taxonomy_ids)) {
        wp_update_term_count_now(
            $term_taxonomy_ids,
            'product_cat'
        );

        clean_taxonomy_cache('product_cat');
    }

    $redirect_url = add_query_arg(
        array(
            'page'                         => 'seo-reports',
            'tab'                          => 'anomalias',
            'product_categories_recounted' => count($term_taxonomy_ids),
        ),
        admin_url('admin.php')
    );

    wp_safe_redirect($redirect_url);
    exit;
}


/**
 * PESTAÑA: Anomalías
 */
function seo_render_anomalies_report() {
    global $wpdb;

    $nodes = $wpdb->get_results("SELECT object_id, seo_role FROM wp_seo_nodes WHERE status=1");
    
    $map = [];
    foreach ($nodes as $n) {
        $map[$n->object_id] = $n->seo_role;
    }
    
    $clusters = [];
    $hubs_primary = [];
    $hubs_secondary = [];
    
    foreach ($map as $id => $role) {
        if ($role === 'cluster') $clusters[] = $id;
        if ($role === 'hub_primary') $hubs_primary[] = $id;
        if ($role === 'hub_secondary') $hubs_secondary[] = $id;
    }

    echo '<h2>🚨 Reporte de Auditoría y Control de Estructura</h2>';
    echo '<p>Monitoreo global de la integridad estructural de Nodos y relaciones semánticas en la base de datos.</p>';
    
    echo "<div style='background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-top: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);'>";

    echo '<h3 style="color:#d63638; border-bottom:1px solid #ccd0d4; padding-bottom:5px;">Clusters sin hubs primarios</h3>';
    $has_clusters_anomalies = false;
    foreach ($clusters as $cluster_id) {
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM wp_seo_relations WHERE source_type='cluster' AND source_id=%d AND relation_type='cluster_to_primary'", $cluster_id));
        if ($count == 0) {
            echo "• " . esc_html(get_the_title($cluster_id)) . " <code style='font-size:12px;'>({$cluster_id})</code><br>";
            $has_clusters_anomalies = true;
        }
    }
    if (!$has_clusters_anomalies) echo '<p style="color:#2e7d32; font-style:italic;">No se encontraron incidencias en esta sección.</p>';
    
    echo '<h3 style="color:#d63638; border-bottom:1px solid #ccd0d4; padding-bottom:5px; margin-top:30px;">Hubs primarios sin cluster</h3>';
    $has_hp_anomalies = false;
    foreach ($hubs_primary as $hp_id) {
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM wp_seo_relations WHERE target_id=%d AND relation_type='cluster_to_primary'", $hp_id));
        if ($exists == 0) {
            echo "• " . esc_html(get_the_title($hp_id)) . " <code style='font-size:12px;'>({$hp_id})</code><br>";
            $has_hp_anomalies = true;
        }
    }
    if (!$has_hp_anomalies) echo '<p style="color:#2e7d32; font-style:italic;">No se encontraron incidencias en esta sección.</p>';
    
    echo '<h3 style="color:#d63638; border-bottom:1px solid #ccd0d4; padding-bottom:5px; margin-top:30px;">Hubs secundarios sin hub primary</h3>';
    $has_hs_anomalies = false;
    foreach ($hubs_secondary as $hs_id) {
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM wp_seo_relations WHERE target_id=%d AND relation_type='hub_primary_to_hub_secondary'", $hs_id));
        if ($exists == 0) {
            echo "• " . esc_html(get_the_title($hs_id)) . " <code style='font-size:12px;'>({$hs_id})</code><br>";
            $has_hs_anomalies = true;
        }
    }
    if (!$has_hs_anomalies) echo '<p style="color:#2e7d32; font-style:italic;">No se encontraron incidencias en esta sección.</p>';
    
    
    echo '<h3 style="
    color:#b57d00;
    border-bottom:1px solid #ccd0d4;
    padding-bottom:5px;
    margin-top:30px;
    ">
        Hubs secundarios sin categorías
    </h3>';
    
    $secondary_without_categories = $wpdb->get_results("
        SELECT
            n.object_id AS hub_secondary_id,
            p.post_title AS hub_secondary_title,
            p.post_status,
            parent_rel.source_id AS hub_primary_id,
            parent_post.post_title AS hub_primary_title
        FROM {$wpdb->prefix}seo_nodes n
    
        INNER JOIN {$wpdb->posts} p
            ON p.ID = n.object_id
           AND p.post_type = 'page'
    
        LEFT JOIN {$wpdb->prefix}seo_relations category_rel
            ON category_rel.source_id = n.object_id
           AND category_rel.source_type = 'hub_secondary'
           AND category_rel.target_type = 'product_cat'
           AND category_rel.relation_type = 'hub_secondary_to_category'
    
        LEFT JOIN {$wpdb->prefix}seo_relations parent_rel
            ON parent_rel.target_id = n.object_id
           AND parent_rel.source_type = 'hub_primary'
           AND parent_rel.target_type = 'hub_secondary'
           AND parent_rel.relation_type = 'hub_primary_to_hub_secondary'
    
        LEFT JOIN {$wpdb->posts} parent_post
            ON parent_post.ID = parent_rel.source_id
    
        WHERE n.object_type = 'page'
          AND n.seo_role = 'hub_secondary'
          AND n.status = 1
          AND category_rel.id IS NULL
    
        GROUP BY
            n.object_id,
            p.post_title,
            p.post_status,
            parent_rel.source_id,
            parent_post.post_title
    
        ORDER BY
            p.post_title ASC
    ");
    
    if (!empty($secondary_without_categories)) {
    
        echo '<p style="color:#646970;">';
        echo 'Estos hubs secundarios pertenecen a la estructura, pero no conducen a ninguna categoría de WooCommerce.';
        echo '</p>';
    
        foreach ($secondary_without_categories as $hub) {
    
            $hub_id       = (int) $hub->hub_secondary_id;
            $hub_title    = $hub->hub_secondary_title ?: '(Sin título)';
            $parent_id    = (int) $hub->hub_primary_id;
            $parent_title = $hub->hub_primary_title ?: 'Sin hub primario';
            $edit_url     = get_edit_post_link($hub_id, 'raw');
            $view_url     = get_permalink($hub_id);
    
            echo '<div style="
                margin:0 0 12px;
                padding:10px 12px;
                background:#fff8e5;
                border-left:4px solid #dba617;
            ">';
    
            echo '<strong>' . esc_html($hub_title) . '</strong> ';
            echo '<code>(' . esc_html($hub_id) . ')</code>';
    
            echo '<br>';
    
            echo 'Hub primario: <strong>' .
                esc_html($parent_title) .
                '</strong>';
    
            if ($parent_id > 0) {
                echo ' <code>(' . esc_html($parent_id) . ')</code>';
            }
    
            echo '<br>';
    
            echo 'Estado de la página: <strong>' .
                esc_html($hub->post_status) .
                '</strong>';
    
            echo '<br>';
    
            echo '<span style="color:#8a5a00;">';
            echo 'Revisar si debe recibir categorías, mantenerse como página informativa o eliminarse de la estructura.';
            echo '</span>';
    
            if ($view_url || $edit_url) {
                echo '<div style="margin-top:7px;">';
    
                if ($view_url) {
                    echo '<a href="' . esc_url($view_url) . '" ';
                    echo 'target="_blank" rel="noopener">Ver</a>';
                }
    
                if ($view_url && $edit_url) {
                    echo ' · ';
                }
    
                if ($edit_url) {
                    echo '<a href="' . esc_url($edit_url) . '">Editar</a>';
                }
    
                echo '</div>';
            }
    
            echo '</div>';
        }
    
    } else {
    
        echo '<p style="color:#2e7d32;font-style:italic;">';
        echo 'Todos los hubs secundarios tienen al menos una categoría vinculada.';
        echo '</p>';
    }
    
    
    
    echo '<h3 style="color:#d63638; border-bottom:1px solid #ccd0d4; padding-bottom:5px; margin-top:30px;">Hubs primarios en múltiples clusters</h3>';
    $multi = $wpdb->get_results("SELECT target_id, COUNT(*) as c FROM wp_seo_relations WHERE relation_type='cluster_to_primary' GROUP BY target_id HAVING c > 1");
    if (!empty($multi)) {
        foreach ($multi as $m) {
            echo "• " . esc_html(get_the_title($m->target_id)) . " <code style='font-size:12px;'>({$m->target_id})</code> - <strong>{$m->c} clusters</strong><br>";
        }
    } else {
        echo '<p style="color:#2e7d32; font-style:italic;">No se encontraron incidencias en esta sección.</p>';
    }
    
    echo '<h3 style="color:#d63638; border-bottom:1px solid #ccd0d4; padding-bottom:5px; margin-top:30px;">Landings sin hub secondary</h3>';
    $landings = $wpdb->get_results("SELECT DISTINCT target_id FROM wp_seo_relations WHERE target_type='landing_page'");
    $has_landings_anomalies = false;
    foreach ($landings as $l) {
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM wp_seo_relations WHERE target_id=%d AND relation_type='hub_secondary_to_landing'", $l->target_id));
        if ($exists == 0) {
            echo "• " . esc_html(get_the_title($l->target_id)) . " <code style='font-size:12px;'>({$l->target_id})</code><br>";
            $has_landings_anomalies = true;
        }
    }
    if (!$has_landings_anomalies) echo '<p style="color:#2e7d32; font-style:italic;">No se encontraron incidencias en esta sección.</p>';

    /*
     * Landings sin relación comercial directa con una categoría de producto.
     * Solo se auditan páginas con seo_role=landing: clusters, hubs y páginas
     * corporativas siguen otras reglas estructurales y no deben marcarse aquí.
     */
    echo '<h3 style="color:#d63638; border-bottom:1px solid #ccd0d4; padding-bottom:5px; margin-top:40px;">🧭 Landing pages sin categoría de producto asociada</h3>';

    $landings_without_product_category = $wpdb->get_results("
        SELECT DISTINCT
            n.object_id AS page_id,
            p.post_title,
            p.post_status
        FROM {$wpdb->prefix}seo_nodes n
        INNER JOIN {$wpdb->posts} p
            ON p.ID = n.object_id
           AND p.post_type = 'page'
        WHERE n.object_type = 'page'
          AND n.seo_role = 'landing'
          AND n.status = 1
          AND p.post_status IN ('publish', 'future')
          AND NOT EXISTS (
              SELECT 1
              FROM {$wpdb->prefix}seo_relations r
              WHERE r.source_type = 'landing'
                AND r.source_id = n.object_id
                AND r.target_type = 'product_cat'
                AND r.relation_type = 'landing_to_category'
          )
        ORDER BY p.post_status ASC, p.post_title ASC
    " );

    if (!empty($landings_without_product_category)) {
        echo '<p style="color:#646970;">';
        echo 'Estas landing pages existen en <code>wp_seo_nodes</code>, pero no tienen ninguna relación <code>landing_to_category</code> hacia <code>product_cat</code>.';
        echo '</p>';
        echo '<div style="background:#fcf0f1;border-left:4px solid #d63638;padding:10px 12px;margin-bottom:12px;">';
        echo 'Total detectadas: <strong>' . esc_html(number_format_i18n(count($landings_without_product_category))) . '</strong>';
        echo '</div>';

        foreach ($landings_without_product_category as $landing) {
            $page_id   = (int) $landing->page_id;
            $title     = $landing->post_title ?: '(Sin título)';
            $edit_url  = admin_url('admin.php?page=seo-page-admin&tab=landings&edit_page=' . $page_id);
            $wp_edit_url = get_edit_post_link($page_id, 'raw');
            $view_url  = get_permalink($page_id);

            echo '<div style="margin:0 0 10px;padding:10px 12px;background:#fff;border-left:4px solid #d63638;">';
            echo '<strong>' . esc_html($title) . '</strong> ';
            echo '<code>(' . esc_html($page_id) . ')</code><br>';
            echo 'Estado: <strong>' . esc_html($landing->post_status) . '</strong><br>';
            echo '<span style="color:#b32d2e;">Sin relación comercial landing_to_category.</span>';

            if ($view_url || $edit_url) {
                echo '<div style="margin-top:6px;">';
                if ($view_url) {
                    echo '<a href="' . esc_url($view_url) . '" target="_blank" rel="noopener">Ver</a>';
                }
                if ($view_url && $edit_url) {
                    echo ' · ';
                }
                if ($edit_url) {
                    echo '<a href="' . esc_url($edit_url) . '"><strong>Editar relación comercial</strong></a>';
                }
                if ($wp_edit_url) {
                    echo ' · <a href="' . esc_url($wp_edit_url) . '">Editor WordPress</a>';
                }
                echo '</div>';
            }

            echo '</div>';
        }
    } else {
        echo '<p style="color:#2e7d32;font-style:italic;">Todas las landing pages publicadas o programadas tienen al menos una categoría de producto asociada.</p>';
    }

    /*
     * Posts sin relación comercial con product_cat.
     * Incluye publicados y programados; excluye borradores, papelera y revisiones.
     */
    echo '<h3 style="color:#d63638; border-bottom:1px solid #ccd0d4; padding-bottom:5px; margin-top:40px;">📰 Posts sin categoría de producto asociada</h3>';

    $posts_without_product_category = $wpdb->get_results("
        SELECT
            p.ID AS post_id,
            p.post_title,
            p.post_status,
            p.post_date
        FROM {$wpdb->posts} p
        WHERE p.post_type = 'post'
          AND p.post_status IN ('publish', 'future')
          AND NOT EXISTS (
              SELECT 1
              FROM {$wpdb->prefix}seo_relations r
              WHERE r.source_type = 'post'
                AND r.source_id = p.ID
                AND r.target_type = 'product_cat'
                AND r.relation_type = 'post_to_category'
          )
        ORDER BY p.post_status ASC, p.post_date DESC, p.ID DESC
    " );

    if (!empty($posts_without_product_category)) {
        echo '<p style="color:#646970;">';
        echo 'Estas entradas están publicadas o programadas, pero no tienen ninguna relación <code>post_to_category</code> hacia <code>product_cat</code>.';
        echo '</p>';
        echo '<div style="background:#fcf0f1;border-left:4px solid #d63638;padding:10px 12px;margin-bottom:12px;">';
        echo 'Total detectados: <strong>' . esc_html(number_format_i18n(count($posts_without_product_category))) . '</strong>';
        echo '</div>';

        foreach ($posts_without_product_category as $post_row) {
            $post_id  = (int) $post_row->post_id;
            $title    = $post_row->post_title ?: '(Sin título)';
            // Abrir el editor SEO del plugin, no el editor clasico de WordPress.
            $edit_url = add_query_arg(
                array(
                    'page'    => 'seo-post-editor',
                    'post_id' => $post_id,
                ),
                admin_url('edit.php')
            );
            $view_url = get_permalink($post_id);

            echo '<div style="margin:0 0 10px;padding:10px 12px;background:#fff;border-left:4px solid #d63638;">';
            echo '<strong>' . esc_html($title) . '</strong> ';
            echo '<code>(' . esc_html($post_id) . ')</code><br>';
            echo 'Estado: <strong>' . esc_html($post_row->post_status) . '</strong>';
            if (!empty($post_row->post_date)) {
                echo ' · Fecha: <strong>' . esc_html($post_row->post_date) . '</strong>';
            }
            echo '<br><span style="color:#b32d2e;">Sin relación comercial post_to_category.</span>';

            if ($view_url || $edit_url) {
                echo '<div style="margin-top:6px;">';
                if ($view_url) {
                    echo '<a href="' . esc_url($view_url) . '" target="_blank" rel="noopener">Ver</a>';
                }
                if ($view_url && $edit_url) {
                    echo ' · ';
                }
                if ($edit_url) {
                    echo '<a href="' . esc_url($edit_url) . '">Editar</a>';
                }
                echo '</div>';
            }

            echo '</div>';
        }
    } else {
        echo '<p style="color:#2e7d32;font-style:italic;">Todos los posts publicados o programados tienen al menos una categoría de producto asociada.</p>';
    }
    
    echo '<h3 style="color:#ff9800; border-bottom:1px solid #ccd0d4; padding-bottom:5px; margin-top:40px;">⚠️ Categorías sin asignación estructural</h3>';
    $cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
    $used = $wpdb->get_col("SELECT DISTINCT target_id FROM wp_seo_relations WHERE target_type='product_cat'");
    
    $has_cats_anomalies = false;
    foreach ($cats as $c) {
        if (!in_array($c->term_id, $used)) {
            echo "• " . esc_html($c->name) . " <code style='font-size:12px;'>({$c->term_id})</code><br>";
            $has_cats_anomalies = true;
        }
    }
    if (!$has_cats_anomalies) echo '<p style="color:#2e7d32; font-style:italic;">Todas las categorías de WooCommerce están correctamente vinculadas en la tabla de relaciones SEO.</p>';

        // Categorías sin productos + limpieza segura desde el propio informe.
        echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:15px;border-bottom:1px solid #ccd0d4;margin-top:40px;margin-bottom:15px;flex-wrap:wrap;">';

        echo '<h3 style="color:#b57d00;margin:0;padding-bottom:5px;">📦 Categorías sin productos</h3>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 6px 0;" onsubmit="return confirm(\'Esta acción recalculará los contadores de TODAS las categorías de producto utilizando WordPress y WooCommerce. No se borrará, moverá ni publicará ningún producto y no se eliminará ninguna categoría. Puede tardar unos segundos. ¿Quieres continuar?\');">';
        echo '<input type="hidden" name="action" value="seo_recount_product_categories">';
        wp_nonce_field('seo_recount_product_categories', 'seo_recount_categories_nonce');
        echo '<button type="submit" class="button button-secondary">🔄 Recalcular contadores</button>';
        echo '</form>';

        echo '</div>';

        if (isset($_GET['product_categories_recounted'])) {
            $recounted_categories = absint($_GET['product_categories_recounted']);

            echo '<div class="notice notice-success inline"><p>';
            echo '<strong>Contadores recalculados.</strong> Se han procesado <strong>' . esc_html(number_format_i18n($recounted_categories)) . '</strong> categorías de producto. ';
            echo 'La acción solo reconstruye los contadores; no mueve, publica ni elimina productos o categorías.';
            echo '</p></div>';
        }

        if (isset($_GET['empty_categories_deleted'])) {
            $deleted_empty = absint($_GET['empty_categories_deleted']);
            $skipped_empty = isset($_GET['empty_categories_skipped'])
                ? absint($_GET['empty_categories_skipped'])
                : 0;
            $relations_empty = isset($_GET['empty_relations_deleted'])
                ? absint($_GET['empty_relations_deleted'])
                : 0;
            $nodes_empty = isset($_GET['empty_nodes_deleted'])
                ? absint($_GET['empty_nodes_deleted'])
                : 0;
            $faqs_empty = isset($_GET['empty_category_faqs_deleted'])
                ? absint($_GET['empty_category_faqs_deleted'])
                : 0;

            echo '<div class="notice notice-success inline"><p>';
            echo 'Limpieza de categorías vacías completada. Categorías eliminadas: <strong>' . esc_html(number_format_i18n($deleted_empty)) . '</strong>.';

            if ($skipped_empty > 0) {
                echo ' Omitidas por seguridad: <strong>' . esc_html(number_format_i18n($skipped_empty)) . '</strong>.';
            }

            echo ' Relaciones SEO eliminadas: <strong>' . esc_html(number_format_i18n($relations_empty)) . '</strong>.';

            if ($nodes_empty > 0) {
                echo ' Registros SEO asociados: <strong>' . esc_html(number_format_i18n($nodes_empty)) . '</strong>.';
            }

            if ($faqs_empty > 0) {
                echo ' FAQs asociadas: <strong>' . esc_html(number_format_i18n($faqs_empty)) . '</strong>.';
            }

            echo '</p></div>';
        }

        $cats_without_products = get_terms(array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ));

        $empty_categories = array();
        $counter_anomalies = array();
        $eligible_empty_categories = 0;

        /*
         * Obtenemos en una sola consulta dos medidas diferentes:
         *
         * - published_count: productos publicados realmente relacionados.
         *   Es la referencia adecuada para comprobar si term_taxonomy.count
         *   está desactualizado.
         *
         * - related_count: cualquier producto o variación relacionada,
         *   independientemente de su estado. Esta es la referencia segura
         *   para decidir si una categoría está realmente vacía.
         *
         * De este modo una categoría que solo tenga productos en draft no
         * se considera vacía ni se propone para borrado.
         */
        $real_count_rows = $wpdb->get_results("
            SELECT
                tt.term_id,
                COUNT(DISTINCT CASE
                    WHEN p.post_type = 'product'
                     AND p.post_status = 'publish'
                    THEN p.ID
                END) AS published_count,
                COUNT(DISTINCT CASE
                    WHEN p.post_type IN ('product', 'product_variation')
                    THEN p.ID
                END) AS related_count
            FROM {$wpdb->term_taxonomy} tt
            LEFT JOIN {$wpdb->term_relationships} tr
                ON tr.term_taxonomy_id = tt.term_taxonomy_id
            LEFT JOIN {$wpdb->posts} p
                ON p.ID = tr.object_id
            WHERE tt.taxonomy = 'product_cat'
            GROUP BY tt.term_id
        ");

        $real_counts = array();

        foreach ($real_count_rows as $row) {
            $real_counts[(int) $row->term_id] = array(
                'published' => (int) $row->published_count,
                'related'   => (int) $row->related_count,
            );
        }

        if (!is_wp_error($cats_without_products)) {

            foreach ($cats_without_products as $cat) {

                $term_id = (int) $cat->term_id;
                $stored_count = (int) $cat->count;

                $published_count = isset($real_counts[$term_id])
                    ? (int) $real_counts[$term_id]['published']
                    : 0;

                $related_count = isset($real_counts[$term_id])
                    ? (int) $real_counts[$term_id]['related']
                    : 0;

                /*
                 * El contador almacenado debe reflejar los productos publicados.
                 * Si no coincide, lo mostramos como anomalía de recuento,
                 * pero nunca como categoría vacía por ese solo motivo.
                 */
                if ($stored_count !== $published_count) {
                    $counter_anomalies[] = array(
                        'term'            => $cat,
                        'stored_count'    => $stored_count,
                        'published_count' => $published_count,
                        'related_count'   => $related_count,
                    );
                }

                /*
                 * Solo es candidata a la sección "Categorías sin productos"
                 * cuando no existe ninguna relación real con productos.
                 */
                if ($related_count !== 0) {
                    continue;
                }

                $state = seo_get_empty_product_category_delete_state($term_id);

                $empty_categories[] = array(
                    'term'  => $cat,
                    'state' => $state,
                );

                if (!empty($state['eligible'])) {
                    $eligible_empty_categories++;
                }
            }
        }

        /*
         * Las discrepancias de contador se informan aparte.
         * No se ofrece borrado porque estas categorías sí tienen productos.
         */
        if (!empty($counter_anomalies)) {

            echo '<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:12px 14px;margin:0 0 18px;line-height:1.6;">';
            echo '<strong>Contadores de categorías desactualizados</strong><br>';
            echo 'Se han detectado <strong>' . esc_html(number_format_i18n(count($counter_anomalies))) . ' categorías</strong> cuyo contador almacenado no coincide con los productos publicados realmente relacionados. ';
            echo '<strong>No se consideran categorías vacías y no se ofrece su borrado.</strong>';
            echo '</div>';

            echo '<div style="border:1px solid #c3c4c7;background:#fff;border-radius:4px;overflow:hidden;margin-bottom:20px;">';

            foreach ($counter_anomalies as $item) {

                $cat = $item['term'];

                echo '<div style="padding:9px 12px;border-bottom:1px solid #f0f0f1;">';
                echo '<strong>' . esc_html($cat->name) . '</strong> ';
                echo '<code style="font-size:12px;">(' . esc_html((int) $cat->term_id) . ')</code>';
                echo '<div style="font-size:12px;color:#50575e;margin-top:3px;">';
                echo 'Contador guardado: <strong>' . esc_html(number_format_i18n((int) $item['stored_count'])) . '</strong> · ';
                echo 'Productos publicados reales: <strong>' . esc_html(number_format_i18n((int) $item['published_count'])) . '</strong> · ';
                echo 'Productos relacionados en cualquier estado: <strong>' . esc_html(number_format_i18n((int) $item['related_count'])) . '</strong>';
                echo '</div>';
                echo '</div>';
            }

            echo '</div>';
        }

        if (empty($empty_categories)) {
            echo '<p style="color:#2e7d32; font-style:italic;">No se encontraron categorías realmente vacías.</p>';
        } else {

            echo '<div style="background:#fff8e5;border-left:4px solid #dba617;padding:12px 14px;margin:0 0 14px;line-height:1.6;">';
            echo 'Se han detectado <strong>' . esc_html(number_format_i18n(count($empty_categories))) . ' categorías sin ningún producto relacionado</strong>. ';
            echo 'El borrado vuelve a comprobar el estado real justo antes de actuar y solo permite eliminar categorías sin productos y sin subcategorías. ';
            echo '<strong>No se elimina ningún producto.</strong>';
            echo '</div>';

            echo '<form id="seo-empty-categories-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Se eliminarán definitivamente las categorías seleccionadas que sigan sin productos y sin subcategorías. También se limpiarán sus relaciones y datos SEO asociados y sus FAQs. No se eliminará ningún producto. ¿Continuar?\');">';
            echo '<input type="hidden" name="action" value="seo_delete_empty_product_categories">';
            wp_nonce_field('seo_delete_empty_product_categories', 'seo_empty_categories_nonce');

            echo '<div style="border:1px solid #dcdcde;background:#fff;border-radius:4px;overflow:hidden;">';

            foreach ($empty_categories as $item) {

                $cat = $item['term'];
                $state = $item['state'];
                $eligible = !empty($state['eligible']);

                echo '<div style="display:flex;align-items:flex-start;gap:10px;padding:9px 12px;border-bottom:1px solid #f0f0f1;">';

                echo '<div style="padding-top:1px;">';
                echo '<input class="seo-empty-category-checkbox" type="checkbox" name="category_ids[]" value="' . esc_attr((int) $cat->term_id) . '"' . ($eligible ? '' : ' disabled') . '>';
                echo '</div>';

                echo '<div style="flex:1;">';
                echo '<strong>' . esc_html($cat->name) . '</strong> ';
                echo '<code style="font-size:12px;">(' . esc_html((int) $cat->term_id) . ')</code>';

                if ($eligible) {
                    echo '<div style="font-size:12px;color:#2e7d32;margin-top:2px;">Eliminable: sin productos y sin subcategorías.</div>';
                } else {
                    echo '<div style="font-size:12px;color:#b32d2e;margin-top:2px;">Protegida: ' . esc_html($state['reason']) . '</div>';
                }

                echo '</div>';
                echo '</div>';
            }

            echo '</div>';

            if ($eligible_empty_categories > 0) {
                echo '<div style="display:flex;align-items:center;gap:10px;margin-top:12px;flex-wrap:wrap;">';
                echo '<button type="button" id="seo-toggle-empty-categories" class="button">Seleccionar todas las eliminables</button>';
                echo '<button type="submit" class="button button-primary" style="background:#d63638;border-color:#d63638;">Eliminar categorías seleccionadas</button>';
                echo '<span style="color:#646970;font-size:12px;">Eliminables ahora: <strong>' . esc_html(number_format_i18n($eligible_empty_categories)) . '</strong></span>';
                echo '</div>';
            } else {
                echo '<p style="color:#646970;margin:12px 0 0;">Ninguna de las categorías realmente vacías puede eliminarse automáticamente con seguridad.</p>';
            }

            echo '</form>';

            echo '<script>';
            echo 'document.addEventListener("DOMContentLoaded",function(){';
            echo 'var b=document.getElementById("seo-toggle-empty-categories");';
            echo 'if(!b){return;}';
            echo 'b.addEventListener("click",function(){';
            echo 'var boxes=Array.prototype.slice.call(document.querySelectorAll("#seo-empty-categories-form .seo-empty-category-checkbox:not(:disabled)"));';
            echo 'var allChecked=boxes.length>0&&boxes.every(function(box){return box.checked;});';
            echo 'boxes.forEach(function(box){box.checked=!allChecked;});';
            echo 'b.textContent=allChecked?"Seleccionar todas las eliminables":"Deseleccionar todas";';
            echo '});';
            echo '});';
            echo '</script>';
        }





        echo '<h3 style="color:#d63638; border-bottom:1px solid #ccd0d4; padding-bottom:5px; margin-top:30px;">
            Hubs secundarios en múltiples hubs primarios
        </h3>';
        
        $secondary_multiple_parents = $wpdb->get_results("
            SELECT
                target_id AS hub_secondary_id,
                COUNT(DISTINCT source_id) AS parent_count
            FROM {$wpdb->prefix}seo_relations
            WHERE relation_type = 'hub_primary_to_hub_secondary'
              AND source_type = 'hub_primary'
              AND target_type = 'hub_secondary'
            GROUP BY target_id
            HAVING COUNT(DISTINCT source_id) > 1
            ORDER BY parent_count DESC, target_id ASC
        ");
        
        if (!empty($secondary_multiple_parents)) {
        
            foreach ($secondary_multiple_parents as $duplicate) {
        
                $secondary_id    = (int) $duplicate->hub_secondary_id;
                $secondary_title = get_the_title($secondary_id);
        
                echo '<div style="margin-bottom:14px; padding:10px 12px; background:#fcf0f1; border-left:4px solid #d63638;">';
        
                echo '• El hub secundario <strong>' .
                    esc_html($secondary_title ?: '(Sin título)') .
                    '</strong> ';
        
                echo '<code style="font-size:12px;">(' .
                    esc_html($secondary_id) .
                    ')</code> ';
        
                echo 'está relacionado con <strong>' .
                    esc_html((int) $duplicate->parent_count) .
                    ' hubs primarios</strong>:';
        
                $parents = $wpdb->get_results(
                    $wpdb->prepare(
                        "
                        SELECT
                            r.id,
                            r.source_id AS hub_primary_id,
                            p.post_title AS hub_primary_title
                        FROM {$wpdb->prefix}seo_relations r
                        LEFT JOIN {$wpdb->posts} p
                            ON p.ID = r.source_id
                        WHERE r.relation_type = 'hub_primary_to_hub_secondary'
                          AND r.source_type = 'hub_primary'
                          AND r.target_type = 'hub_secondary'
                          AND r.target_id = %d
                        ORDER BY r.id ASC
                        ",
                        $secondary_id
                    )
                );
        
                echo '<ul style="margin:8px 0 0 24px; list-style:disc;">';
        
                foreach ($parents as $parent) {
        
                    echo '<li>';
        
                    echo esc_html(
                        $parent->hub_primary_title ?: '(Hub primario sin título)'
                    );
        
                    echo ' <code style="font-size:11px;">';
                    echo 'Hub ID: ' . esc_html((int) $parent->hub_primary_id);
                    echo ' · Relación ID: ' . esc_html((int) $parent->id);
                    echo '</code>';
        
                    echo '</li>';
                }
        
                echo '</ul>';
                echo '</div>';
            }
        
        } else {
        
            echo '<p style="color:#2e7d32; font-style:italic;">
                No se encontraron hubs secundarios asociados a múltiples hubs primarios.
            </p>';
        }

    echo '<h3 style="color:#b57d00; border-bottom:1px solid #ccd0d4; padding-bottom:5px; margin-top:40px;">💥 Categorías DUPLICADAS en múltiples Hubs Secundarios</h3>';
    
    $duplicate_cats = $wpdb->get_results("
        SELECT r.target_id as cat_id, COUNT(*) as repetido_veces
        FROM wp_seo_relations r 
        WHERE r.source_type = 'hub_secondary' 
          AND r.target_type = 'product_cat'
        GROUP BY r.target_id 
        HAVING repetido_veces > 1
    ");

    if (!empty($duplicate_cats)) {
        foreach ($duplicate_cats as $dup) {
            $term = get_term($dup->cat_id, 'product_cat');
            
            if ($term && !is_wp_error($term)) {
                echo "<div style='margin-bottom:12px; padding-left:5px;'>";
                echo "• 🚨 La categoría <strong>" . esc_html($term->name) . "</strong> <code style='font-size:12px;'> (Cat ID: {$dup->cat_id})</code> está asignada en <strong>{$dup->repetido_veces} Hubs Secundarios</strong> diferentes:";
                
                $associated_hubs = $wpdb->get_results($wpdb->prepare("
                    SELECT source_id FROM wp_seo_relations 
                    WHERE source_type = 'hub_secondary' 
                      AND target_type = 'product_cat' 
                      AND target_id = %d
                ", $dup->cat_id));
                
                echo "<ul style='margin: 4px 0 0 20px; list-style-type: circle; color: #50575e;'>";
                foreach ($associated_hubs as $hub) {
                    $hub_title = get_the_title($hub->source_id);
                    echo "<li>➡️ " . esc_html($hub_title) . " <code style='font-size:11px;'> (Hub ID: {$hub->source_id})</code></li>";
                }
                echo "</ul>";
                echo "</div>";
            }
        }
    } else {
        echo '<p style="color:#2e7d32; font-style:italic;">¡Excelente! No existen categorías duplicadas. Cada categoría de WooCommerce pertenece a un único Hub Secundario.</p>';
    }

    // FAQs que han perdido el enlace con su categoría, producto o página.
    seo_render_faq_orphan_anomalies_report();

    echo "</div>";
}


/****************************
EXPORT CSV SEO TABLES
***************************/
    add_action('admin_post_seo_export_csv', 'seo_export_csv_handler');
    function seo_export_csv_handler() {
    
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para exportar.');
        }
    
        if (empty($_GET['table'])) {
            wp_die('Tabla no indicada.');
        }
    
        $table_key = sanitize_key($_GET['table']);
    
        if (
            empty($_GET['_wpnonce']) ||
            !wp_verify_nonce($_GET['_wpnonce'], 'seo_export_csv_' . $table_key)
        ) {
            wp_die('Nonce inválido.');
        }
    
        global $wpdb;
    
        $allowed_tables = [
            'dictionari' => $wpdb->prefix . 'seo_dictionari',
            'nodes'     => $wpdb->prefix . 'seo_nodes',
            'redirects' => $wpdb->prefix . 'seo_redirects',
            'relations' => $wpdb->prefix . 'seo_relations',
            'templates' => $wpdb->prefix . 'seo_templates',
        ];
    
        if (!isset($allowed_tables[$table_key])) {
            wp_die('Tabla no permitida.');
        }
    
        $table_name = $allowed_tables[$table_key];
    
        $columns = $wpdb->get_col("SHOW COLUMNS FROM `$table_name`", 0);
    
        if (empty($columns)) {
            wp_die('No se pudieron leer las columnas.');
        }
    
        $rows = $wpdb->get_results("SELECT * FROM `$table_name`", ARRAY_A);
    
        $filename = $table_name . '_' . date('Ymd_His') . '.csv';
    
        nocache_headers();
    
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
    
        $output = fopen('php://output', 'w');
    
        // BOM para Excel
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
        // Cabeceras
        fputcsv($output, $columns, ';');
    
        // Filas
        foreach ($rows as $row) {
            $line = [];
    
            foreach ($columns as $col) {
                $line[] = isset($row[$col]) ? $row[$col] : '';
            }
    
            fputcsv($output, $line, ';');
        }
    
        fclose($output);
        exit;
    }