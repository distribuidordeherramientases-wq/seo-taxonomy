<?php
if (!defined('ABSPATH')) exit;



// =========================================================================
// ACCIÓN 1 AJAX: SOLO DESVINCULAR (Borrar de wp_seo_relations + Crear Redirect)
// =========================================================================
add_action('wp_ajax_seo_solo_desvincular_relacion', 'seo_solo_desvincular_relacion_callback');

function seo_solo_desvincular_relacion_callback() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' =>'No tienes permisos suficientes.']);
    }

    $term_id     = isset($_POST['term_id']) ? intval($_POST['term_id']) : 0;
    $url_origen  = isset($_POST['url_origen']) ? esc_url_raw($_POST['url_origen']) : '';
    $url_destino = isset($_POST['url_destino']) ? esc_url_raw($_POST['url_destino']) : '';

    if (!$term_id || empty($url_origen) || empty($url_destino)) {
        wp_send_json_error(['message' => 'Faltan datos requeridos para procesar la acción.']);
    }

    global $wpdb;
    $tabla_relations = $wpdb->prefix . 'seo_relations';
    $tabla_redirects = $wpdb->prefix . 'seo_redirects';

    // Formatear la URL Origen: Relativa y sin barra al final para tu motor SQL
    $ruta_origen = '/' . trim(wp_make_link_relative($url_origen), '/');
    $ruta_destino = esc_url_raw($url_destino);
    $ruta_destino_relativa = '/' . trim(wp_make_link_relative($ruta_destino), '/');

    // Control de bucles de redirección
    if ($ruta_origen === $ruta_destino_relativa) {
        wp_send_json_error(['message' => 'Error: Estás intentando redirigir una categoría hacia sí misma. Elige un destino diferente.']);
    }

    // Interceptar si ya existe una redirección previa para esta URL de origen
    $redirect_existente = $wpdb->get_row($wpdb->prepare(
        "SELECT target_url FROM $tabla_redirects WHERE origin_url = %s LIMIT 1", 
        $ruta_origen
    ));

    if ($redirect_existente) {
        wp_send_json_error([
            'message' => sprintf(
                'Conflicto de Redirección: La URL "%s" ya cuenta con una redirección activa hacia "%s". Por seguridad, el proceso se ha cancelado.',
                $ruta_origen,
                esc_url($redirect_existente->target_url)
            )
        ]);
    }

    // Insertar en tu estructura de tabla exacta de redirecciones si todo está limpio
    $insert_redirect = $wpdb->insert(
        $tabla_redirects,
        array(
            'origin_url'  => $ruta_origen,
            'target_url'  => $ruta_destino,
            'status_code' => 301,
            'hits'        => 0,
            'last_hit'    => null
        ),
        array('%s', '%s', '%d', '%d', '%s')
    );

    if ($insert_redirect === false) {
        wp_send_json_error(['message' => 'Error al insertar registro en redirecciones: ' . $wpdb->last_error]);
    }

    // Eliminar únicamente la relación del mapa SEO
    $wpdb->delete(
        $tabla_relations,
        array('target_id' => $term_id, 'target_type' => 'product_cat'),
        array('%d', '%s')
    );

    wp_send_json_success(['message' => 'Desvinculado con éxito del mapa relacional.']);
}

// =========================================================================
// ACCIÓN 2 AJAX: BORRADO COMPLETO (Relaciones + Redirect + Borrado de WooCommerce)
// =========================================================================
add_action('wp_ajax_seo_borrar_y_redirigir_categoria', 'seo_borrar_y_redirigir_categoria_callback');

function seo_borrar_y_redirigir_categoria_callback() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'No tienes permisos suficientes.']);
    }

    $term_id     = isset($_POST['term_id']) ? intval($_POST['term_id']) : 0;
    $url_origen  = isset($_POST['url_origen']) ? esc_url_raw($_POST['url_origen']) : '';
    $url_destino = isset($_POST['url_destino']) ? esc_url_raw($_POST['url_destino']) : '';

    if (!$term_id || empty($url_origen) || empty($url_destino)) {
        wp_send_json_error(['message' => 'Faltan datos requeridos para procesar la acción.']);
    }

    global $wpdb;
    $tabla_relations = $wpdb->prefix . 'seo_relations';
    $tabla_redirects = $wpdb->prefix . 'seo_redirects';

    $ruta_origen = '/' . trim(wp_make_link_relative($url_origen), '/');
    $ruta_destino = esc_url_raw($url_destino);
    $ruta_destino_relativa = '/' . trim(wp_make_link_relative($ruta_destino), '/');

    // Control de bucles de redirección
    if ($ruta_origen === $ruta_destino_relativa) {
        wp_send_json_error(['message' => 'Error: Estás intentando redirigir una categoría hacia sí misma. Elige un destino diferente.']);
    }

    // Interceptar si ya existe una redirección previa para esta URL de origen
    $redirect_existente = $wpdb->get_row($wpdb->prepare(
        "SELECT target_url FROM $tabla_redirects WHERE origin_url = %s LIMIT 1", 
        $ruta_origen
    ));

    if ($redirect_existente) {
        wp_send_json_error([
            'message' => sprintf(
                'Conflicto de Redirección: La URL "%s" ya cuenta con una redirección activa hacia "%s". Por seguridad, el proceso se ha cancelado.',
                $ruta_origen,
                esc_url($redirect_existente->target_url)
            )
        ]);
    }

    $insert_redirect = $wpdb->insert(
        $tabla_redirects,
        array(
            'origin_url'  => $ruta_origen,
            'target_url'  => $ruta_destino,
            'status_code' => 301,
            'hits'        => 0,
            'last_hit'    => null
        ),
        array('%s', '%s', '%d', '%d', '%s')
    );

    if ($insert_redirect === false) {
        wp_send_json_error(['message' => 'Error al insertar registro en redirecciones.']);
    }

    $wpdb->delete(
        $tabla_relations,
        array('target_id' => $term_id, 'target_type' => 'product_cat'),
        array('%d', '%s')
    );

    // Borrado físico total del término en WordPress/WooCommerce
    $borrado_wc = wp_delete_term($term_id, 'product_cat');

    if (is_wp_error($borrado_wc)) {
        wp_send_json_error(['message' => 'Redirección creada, pero falló el borrado en WC: ' . $borrado_wc->get_error_message()]);
    }

    wp_send_json_success(['message' => 'Categoría eliminada y redirección configurada con éxito.']);
}

// =========================================================================
// API PÚBLICA DEL EDITOR DE CATEGORÍAS
// =========================================================================

/**
 * Genera el enlace al editor individual desde cualquier informe del plugin.
 *
 * @param int    $term_id   ID de product_cat.
 * @param string $page_slug Slug de la página administrativa.
 * @return string
 */
function seo_get_category_editor_url($term_id, $page_slug = 'category-seo-admin') {

    $term_id = absint($term_id);

    if (!$term_id) {
        return '';
    }

    return add_query_arg(
        [
            'page'              => sanitize_key($page_slug),
            'tab'               => 'categorias',
            'edit_category_id'  => $term_id,
        ],
        admin_url('admin.php')
    );
}

/**
 * Guarda los campos editables de una categoría.
 *
 * Puede llamarse desde otros procesos del plugin.
 *
 * @param int   $term_id ID de product_cat.
 * @param array $data    name, excerpt, description y keywords.
 * @return true|WP_Error
 */
function seo_save_category_editor_data($term_id, array $data) {

    if (!current_user_can('manage_options')) {
        return new WP_Error('seo_category_forbidden', 'No tienes permisos para editar categorías.');
    }

    $term_id = absint($term_id);
    $term = get_term($term_id, 'product_cat');

    if (!$term_id || !$term || is_wp_error($term)) {
        return new WP_Error('seo_category_not_found', 'La categoría solicitada no existe.');
    }

    global $wpdb;

    $nodes_table = $wpdb->prefix . 'seo_nodes';

    $update_data = [];

    if (isset($data['name']) && trim((string) $data['name']) !== '') {
        $update_data['name'] = sanitize_text_field($data['name']);
    }

    // Solo actualizar nombre en WordPress
    if ($update_data) {
        $updated = wp_update_term(
            $term_id,
            'product_cat',
            $update_data
        );

        if (is_wp_error($updated)) {
            return $updated;
        }
    }


    // Excerpt en wp_seo_nodes
        if (array_key_exists('excerpt', $data)) {
        
            $excerpt = wp_kses_post($data['excerpt']);
        
            $excerpt_node_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id
                     FROM {$nodes_table}
                     WHERE object_type = 'category'
                     AND object_id = %d
                     AND seo_role = 'excerpt'
                     LIMIT 1",
                    $term_id
                )
            );
        
            if ($excerpt_node_id) {
        
                $result = $wpdb->update(
                    $nodes_table,
                    [
                        'keywords' => $excerpt
                    ],
                    [
                        'id' => absint($excerpt_node_id)
                    ],
                    ['%s'],
                    ['%d']
                );
        
            } else {
        
                $result = $wpdb->insert(
                    $nodes_table,
                    [
                        'object_type' => 'category',
                        'object_id'   => $term_id,
                        'seo_role'    => 'excerpt',
                        'keywords'    => $excerpt,
                        'status'      => 1
                    ],
                    ['%s','%d','%s','%s','%d']
                );
        
            }
        
            if ($result === false) {
                return new WP_Error(
                    'seo_category_excerpt_error',
                    $wpdb->last_error
                );
            }
        }

    // Description HTML en seo_nodes
    if (array_key_exists('description', $data)) {

        $description = wp_kses_post($data['description']);

        $description_node_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id
                 FROM {$nodes_table}
                 WHERE object_type = 'category'
                   AND object_id = %d
                   AND seo_role = 'description'
                 LIMIT 1",
                $term_id
            )
        );

        if ($description_node_id) {

            $result = $wpdb->update(
                $nodes_table,
                [
                    'keywords' => $description
                ],
                [
                    'id' => absint($description_node_id)
                ],
                ['%s'],
                ['%d']
            );

        } else {

            $result = $wpdb->insert(
                $nodes_table,
                [
                    'object_type' => 'category',
                    'object_id'   => $term_id,
                    'seo_role'    => 'description',
                    'keywords'    => $description,
                    'status'      => 1
                ],
                ['%s', '%d', '%s', '%s', '%d']
            );
        }

        if ($result === false) {
            return new WP_Error(
                'seo_category_description_error',
                $wpdb->last_error
            );
        }
    }

    // Keywords SEO
    if (array_key_exists('keywords', $data)) {

        $keywords = sanitize_textarea_field($data['keywords']);

        $node_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id
                 FROM {$nodes_table}
                 WHERE object_type = 'category'
                   AND object_id = %d
                   AND seo_role = 'category'
                 LIMIT 1",
                $term_id
            )
        );

        if ($node_id) {

            $result = $wpdb->update(
                $nodes_table,
                ['keywords' => $keywords],
                ['id' => absint($node_id)],
                ['%s'],
                ['%d']
            );

        } else {

            $result = $wpdb->insert(
                $nodes_table,
                [
                    'object_type' => 'category',
                    'object_id'   => $term_id,
                    'seo_role'    => 'category',
                    'keywords'    => $keywords,
                    'status'      => 1
                ],
                ['%s', '%d', '%s', '%s', '%d']
            );
        }

        if ($result === false) {
            return new WP_Error(
                'seo_category_database_error',
                $wpdb->last_error
            );
        }
    }

    return true;
}


// =========================================================================
// FUNCIÓN PRINCIPAL DEL PANEL DE ADMINISTRACIÓN
// =========================================================================
function seo_category_admin_callback($requested_term_id = 0) {


$page_slug  = isset($_GET['page'])
    ? sanitize_key($_GET['page'])
    : 'category-seo-admin';
$active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'categorias';
$requested_term_id = absint($requested_term_id);

if (!$requested_term_id && isset($_GET['edit_category_id'])) {
    $requested_term_id = absint($_GET['edit_category_id']);
}

$single_category_mode = $requested_term_id > 0;

if ($single_category_mode) {
    $requested_term = get_term($requested_term_id, 'product_cat');

    if (!$requested_term || is_wp_error($requested_term)) {
        wp_die('La categoría solicitada no existe.');
    }

    $active_tab = 'categorias';
}


if (!in_array($active_tab,
[
    'categorias',
    'inventario',
    'estructura',
    'reasignar_categorias',
    'category-anomaly',
    'schema',
    'informes'
], true)) {

    $active_tab = 'categorias';
}



// URL PESTAÑA CATEGORÍAS
$url_categorias = add_query_arg(
    [
        'page' => $page_slug,
        'tab'  => 'categorias',
    ],
    admin_url('admin.php')
);

// URL PESTAÑA INVENTARIO POR CATEGORIA
$url_inventario = add_query_arg(
    [
        'page' => $page_slug,
        'tab'  => 'inventario',
    ],
    admin_url('admin.php')
);

// URL PESTAÑA INFORME / ESTRUCTURA DE CATEGORÍAS
$url_estructura = add_query_arg(
    [
        'page' => $page_slug,
        'tab'  => 'estructura',
    ],
    admin_url('admin.php')
);

// URL PESTAÑA REASIGNACIÓN DE CATEGORÍAS
$url_reasignar_categorias = add_query_arg(
    [
        'page' => $page_slug,
        'tab'  => 'reasignar_categorias',
    ],
    admin_url('admin.php')
);


// URL PESTAÑA ANOMALÍAS
$url_category_anomaly = add_query_arg(
    [
        'page' => $page_slug,
        'tab'  => 'category-anomaly',
    ],
    admin_url('admin.php')
);

// URL PESTAÑA SCHEMA
$url_schema = add_query_arg(
    [
        'page' => $page_slug,
        'tab'  => 'schema',
    ],
    admin_url('admin.php')
);

// URL PESTANA INFORMES GOOGLE
$url_informes = add_query_arg(
    [
        'page' => $page_slug,
        'tab'  => 'informes',
    ],
    admin_url('admin.php')
);

echo '<div class="wrap">';

echo '<h1 style="margin-bottom:15px;">Categorías SEO</h1>';


if (!$single_category_mode) {
echo '<h2 class="nav-tab-wrapper" style="margin-bottom:20px;">';

    echo '<a href="' . esc_url($url_categorias) . '" class="nav-tab ' . ($active_tab === 'categorias' ? 'nav-tab-active' : '') . '">Categorías</a>';

    echo '<a href="' . esc_url($url_inventario) . '" class="nav-tab ' . ($active_tab === 'inventario' ? 'nav-tab-active' : '') . '">Inventario</a>';

    echo '<a href="' . esc_url($url_estructura) . '" class="nav-tab ' . ($active_tab === 'estructura' ? 'nav-tab-active' : '') . '">Informe categorías</a>';

    echo '<a href="' . esc_url($url_reasignar_categorias) . '" class="nav-tab ' . ($active_tab === 'reasignar_categorias' ? 'nav-tab-active' : '') . '">Reasignación de Categorías</a>';
    
    echo '<a href="' . esc_url($url_category_anomaly) . '" class="nav-tab ' . ($active_tab === 'category-anomaly' ? 'nav-tab-active' : '') . '">Anomalías</a>';
    
    echo '<a href="' . esc_url($url_schema) . '" class="nav-tab ' . ($active_tab === 'schema' ? 'nav-tab-active' : '') . '">Schema</a>';

    echo '<a href="' . esc_url($url_informes) . '" class="nav-tab ' . ($active_tab === 'informes' ? 'nav-tab-active' : '') . '">Informes Google</a>';



echo '</h2>';
}

// =========================
// PESTAÑA INVENTARIO POR CATEGORIA
// =========================
if ($active_tab === 'inventario') {

    // Carga diferida: el informe no se incluye al abrir la pestana normal de Categorias.
    $seo_category_inventory_file = __DIR__ . '/category-info-related.php';
    if (!function_exists('seo_render_category_info_related') && file_exists($seo_category_inventory_file)) {
        require_once $seo_category_inventory_file;
    }

    if (function_exists('seo_render_category_info_related')) {
        seo_render_category_info_related($page_slug);
    } else {
        echo '<div class="notice notice-error"><p>No esta disponible el modulo <code>category-info-related.php</code>.</p></div>';
    }

    echo '</div>';
    return;
}

// =========================
// PESTAÑA INFORME / ESTRUCTURA DE CATEGORÍAS
// =========================
if ($active_tab === 'estructura') {

    if (function_exists('seo_render_total_structure_report')) {
        seo_render_total_structure_report();
    } else {
        echo '<div class="notice notice-error"><p>No esta disponible el informe de estructura de categorias.</p></div>';
    }

    echo '</div>';
    return;
}

// =========================
// PESTANA INFORMES GOOGLE
// =========================
if ($active_tab === 'informes') {

    if (function_exists('seo_category_reports_page')) {
        seo_category_reports_page($page_slug);
    } else {
        echo '<div class="notice notice-error"><p>No esta disponible el modulo de informes Google por categoria.</p></div>';
    }

    echo '</div>';
    return;
}

if ($active_tab === 'reasignar_categorias') {

    if (function_exists('seo_render_reasignacion_ui')) {
        seo_render_reasignacion_ui([]);
    } else {
        echo '<p>Función seo_render_reasignacion_ui() no encontrada</p>';
    }

    echo '</div>';
    return;
}
// =========================
// PESTAÑA SCHEMA
// =========================
if ($active_tab === 'schema') {

    if (function_exists('seo_schema_search')) {

        seo_schema_search();

    } else {

        echo '<p>Función seo_schema_search() no encontrada</p>';

    }

    echo '</div>';
    return;
}




// =========================
// PESTAÑA ANOMALÍAS CATEGORÍAS
// =========================
if ($active_tab === 'category-anomaly') {

    // Ejecutar análisis de categorías
    if (function_exists('search_category_anomaly')) {

        search_category_anomaly();

    } else {

        echo '<p>Función search_category_anomaly() no encontrada</p>';

    }

    echo '</div>';
    return;
}


    if (!current_user_can('manage_options')) return;

    global $wpdb;
    $tabla_relations = $wpdb->prefix . 'seo_relations';

    // Guardado estándar mediante la API modular.
    if (isset($_POST['action']) && $_POST['action'] === 'update_seo_categories') {
        if (
            empty($_POST['seo_category_editor_nonce']) ||
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['seo_category_editor_nonce'])),
                'seo_save_category_editor'
            )
        ) {
            echo '<div class="notice notice-error"><p>La sesión ha caducado. Recarga la página antes de guardar.</p></div>';
            return;
        }

        $cat_names        = $_POST['cat_name'] ?? [];
        $cat_excerpts     = $_POST['cat_excerpt'] ?? [];
        $cat_descriptions = $_POST['cat_description'] ?? [];
        $cat_tags         = $_POST['cat_tags'] ?? [];
        $save_errors      = [];
        $saved_count      = 0;

        foreach ($cat_names as $term_id => $name) {
            $term_id = absint($term_id);

            if ($single_category_mode && $term_id !== $requested_term_id) {
                continue;
            }

            $result = seo_save_category_editor_data($term_id, [
                'name'        => $name,
                'excerpt'     => $cat_excerpts[$term_id] ?? '',
                'description' => $cat_descriptions[$term_id] ?? '',
                'keywords'    => $cat_tags[$term_id] ?? '',
            ]);

            if (is_wp_error($result)) {
                $save_errors[] = $result->get_error_message();
            } else {
                $saved_count++;
            }
        }

        if ($saved_count > 0) {
            echo '<div class="notice notice-success"><p>Categorías actualizadas correctamente: ' .
                intval($saved_count) .
                '</p></div>';
        }

        foreach ($save_errors as $save_error) {
            echo '<div class="notice notice-error"><p>' .
                esc_html($save_error) .
                '</p></div>';
        }
    }

    // 1. Clusters Únicos (Nivel 1)
    $clusters_sistema = $wpdb->get_results("
        SELECT DISTINCT r.source_id as id, p.post_title as nombre 
        FROM $tabla_relations r
        INNER JOIN {$wpdb->prefix}posts p ON r.source_id = p.ID
        WHERE r.source_type = 'cluster' AND p.post_status = 'publish'
        ORDER BY p.post_title ASC
    ");

    // 2. Mapeo General de Relaciones
    $mapeo_completo = $wpdb->get_results("
        SELECT r.source_id, r.source_type, r.target_id, r.target_type, r.relation_type, p.post_title as target_title
        FROM $tabla_relations r
        INNER JOIN {$wpdb->prefix}posts p ON r.target_id = p.ID
        WHERE p.post_status = 'publish'
    ");

    // 3. Relaciones Nivel 3 -> Nivel 4 (Hub Secundario a Categorías)
    $relaciones_categorias = $wpdb->get_results("
        SELECT r.source_id as hub_secundario_id, r.target_id as cat_term_id, t.name as cat_nombre
        FROM $tabla_relations r
        INNER JOIN {$wpdb->prefix}terms t ON r.target_id = t.term_id
        WHERE r.relation_type = 'hub_secondary_to_category' 
          AND r.target_type = 'product_cat'
    ");

    // 4. Precalcular URLs absolutas
    $urls_precalculadas = [];
    $post_ids_unicos = $wpdb->get_col("SELECT DISTINCT target_id FROM $tabla_relations UNION SELECT DISTINCT source_id FROM $tabla_relations");
    if (!empty($post_ids_unicos)) {
        foreach ($post_ids_unicos as $p_id) {
            $p_id = intval($p_id);
            if ($p_id > 0) {
                $urls_precalculadas['post_' . $p_id] = get_permalink($p_id);
            }
        }
    }

    $all_categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
    if (!is_wp_error($all_categories) && !empty($all_categories)) {
        foreach ($all_categories as $cat) {
            $urls_precalculadas['cat_' . $cat->term_id] = get_term_link($cat);
        }
    }

    $categories_args = [
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC'
    ];

    if ($single_category_mode) {
        $categories_args['include'] = [$requested_term_id];
    }

    $categories_list = get_terms($categories_args);
    

?>

<div style="padding:20px; max-width:100%; font-family:sans-serif;">

<div style="padding:20px; max-width:100%; font-family:sans-serif;">


<?php if ($single_category_mode): ?>
    <div style="background:#fff; border:1px solid #ccd0d4; padding:15px 18px; border-radius:8px; margin-bottom:20px;">
        <h2 style="margin:0 0 10px;">
            Editar categoría: <?php echo esc_html($requested_term->name); ?>
        </h2>
        <a class="button button-secondary" href="<?php echo esc_url($url_categorias); ?>">
            ← Volver a todas las categorías
        </a>
    </div>
<?php else: ?>
<div style="background:#e8f4fd;border-left:6px solid #0078d4;padding:15px;margin:15px 0;">

<h2>📂 Guía para crear nuevas categorías SEO</h2>
<details>
    <summary><strong>ormas para la creación de categorías y contenidos SEO.</strong></summary>

    <br>

    <p><strong>Objetivo:</strong> Cada categoría debe ayudar al usuario a entender qué productos contiene, para qué sirven, cuándo utilizarlos y qué variantes puede encontrar.</p>

    <p><strong>Excerpt:</strong> 35 a 50 palabras.</p>

    <p><strong>Descripción:</strong> 400 a 700 palabras.</p>

    <p><strong>Estructura obligatoria:</strong></p>

    <ul>
        <li>Características de la categoría</li>
        <li>Ventajas</li>
        <li>Aplicaciones</li>
        <li>Tipos de productos incluidos</li>
    </ul>
    
    1. ¿Qué es esta categoría?

Define qué engloba.

La categoría de llaves dinamométricas reúne herramientas diseñadas para aplicar un par de apriete controlado...

2. ¿Para qué sirven?

Explica la función.

Se utilizan cuando es necesario apretar tornillos con un par específico...

3. ¿Quién utiliza estos productos?

Da contexto profesional.

Son habituales en talleres mecánicos, mantenimiento industrial, aeronáutica...

4. ¿Qué tipos de productos incluye?

Explica las variantes.

Dentro de esta categoría existen modelos de disparo, digitales, de cuadradillo...

5. ¿Cómo elegir el producto adecuado?

Esta sección es muy potente para SEO.

La elección depende del rango de par, precisión, frecuencia de uso...

6. Aplicaciones habituales

Casos reales de uso.

7. Productos o categorías relacionadas

Genera contexto semántico.

8. Preguntas frecuentes

Cinco o seis FAQs.

</details>

</div>



    <?php
    // ============================================
    // CREAR NUEVA CATEGORÍA SEO
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] === 'create_seo_category') {
    
        $nombre       = sanitize_text_field($_POST['new_cat_name'] ?? '');
        $excerpt      = wp_kses_post($_POST['new_cat_excerpt'] ?? '');
        $descripcion  = wp_kses_post($_POST['new_cat_description'] ?? '');
        $tags         = sanitize_textarea_field($_POST['new_cat_tags'] ?? '');
        $ambito       = sanitize_text_field($_POST['new_cat_ambito'] ?? '');

        if (!empty($nombre)) {
    
            $parent_id = isset($_POST['new_cat_parent'])
                ? absint($_POST['new_cat_parent'])
                : 0;
            
            $nueva_cat = wp_insert_term(
                $nombre,
                'product_cat',
                [
                    'parent' => $parent_id
                ]
            );
    
            if (!is_wp_error($nueva_cat)) {
    
                $term_id = $nueva_cat['term_id'];
    
                // Guardar excerpt
                    if (!empty($excerpt)) {
                    
                        $wpdb->insert(
                            "{$wpdb->prefix}seo_nodes",
                            [
                                'object_type' => 'category',
                                'object_id'   => $term_id,
                                'seo_role'    => 'excerpt',
                                'keywords'    => $excerpt,
                                'status'      => 1,
                                'created_at'  => current_time('mysql'),
                                'updated_at'  => current_time('mysql')
                            ],
                            ['%s','%d','%s','%s','%d','%s','%s']
                        );
                    
                    }
    
                // Guardar etiquetas SEO en tabla personalizada
                global $wpdb;
    
                // Guardar description SEO en wp_seo_nodes
                if (!empty($descripcion)) {
                
                    $wpdb->insert(
                        "{$wpdb->prefix}seo_nodes",
                        [
                            'object_type' => 'category',
                            'object_id'   => $term_id,
                            'seo_role'    => 'description',
                            'keywords'    => $descripcion,
                            'status'      => 1,
                            'created_at'  => current_time('mysql'),
                            'updated_at'  => current_time('mysql')
                        ],
                        ['%s', '%d', '%s', '%s', '%d', '%s', '%s']
                    );
                
                }
                
                // Guardar ámbito
                if (!empty($ambito)) {
                
                    $wpdb->insert(
                        "{$wpdb->prefix}seo_nodes",
                        [
                            'object_type' => 'category',
                            'object_id'   => $term_id,
                            'seo_role'    => 'ambito',
                            'keywords'    => $ambito,
                            'status'      => 1,
                            'created_at'  => current_time('mysql'),
                            'updated_at'  => current_time('mysql')
                        ],
                        ['%s', '%d', '%s', '%s', '%d', '%s', '%s']
                    );
                
                }
                
                
                // Guardar etiquetas SEO en wp_seo_nodes
                if (!empty($tags)) {
                
                    $wpdb->insert(
                        "{$wpdb->prefix}seo_nodes",
                        [
                            'object_type' => 'category',
                            'object_id'   => $term_id,
                            'seo_role'    => 'category',
                            'keywords'    => $tags,
                            'status'      => 1,
                            'created_at'  => current_time('mysql'),
                            'updated_at'  => current_time('mysql')
                        ],
                        ['%s', '%d', '%s', '%s', '%d', '%s', '%s']
                    );
                
                }
    
                echo '<div class="notice notice-success"><p>Categoría creada correctamente</p></div>';
    
            } else {
                echo '<div class="notice notice-error"><p>Error: ' . $nueva_cat->get_error_message() . '</p></div>';
            }
    
        } else {
            echo '<div class="notice notice-error"><p>El nombre es obligatorio</p></div>';
        }
    }
    ?>
    
    
    <?php
    //Toma valor del ambito disponible de tabla wp_eo_nodes
    $ambitos_disponibles = $wpdb->get_col("
        SELECT DISTINCT keywords
        FROM {$wpdb->prefix}seo_nodes
        WHERE seo_role = 'ambito'
        ORDER BY keywords ASC
    ");
    ?>

    <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:8px; margin-bottom:30px;">
        
        <h2 style="margin-top:0;">➕ Crear nueva categoría</h2>
    
        <form method="post">
            <input type="hidden" name="action" value="create_seo_category">
    
            <div style="margin-bottom:10px;">
                <strong>Nombre:</strong>
                <input type="text" name="new_cat_name" style="width:100%; padding:6px;">
            </div>
    
            <div style="margin-bottom:10px;">
                <strong>Excerpt SEO:</strong>
                <textarea name="new_cat_excerpt" style="width:100%; min-height:60px;"></textarea>
            </div>
    
            <div style="margin-bottom:10px;">
                <strong>Descripción:</strong>
                <textarea name="new_cat_description" style="width:100%; min-height:100px;"></textarea>
            </div>
    
            <div style="margin-bottom:10px;">
                <strong>Etiquetas SEO:</strong>
                <textarea name="new_cat_tags" style="width:100%; min-height:60px;"></textarea>
            </div>
    
            <div style="margin-bottom:15px;">
                <strong>Ámbito:</strong>
            
                <select name="new_cat_ambito" style="width:100%; padding:6px;">
                    <option value="">-- Seleccionar ámbito --</option>
            
                    <?php foreach ($ambitos_disponibles as $ambito): ?>
            
                        <option value="<?php echo esc_attr($ambito); ?>">
                            <?php echo esc_html($ambito); ?>
                        </option>
            
                    <?php endforeach; ?>
            
                </select>
            </div>

            <button type="submit" style="background:#2271b1; color:#fff; padding:10px 20px; border:none; border-radius:4px;">
                Crear categoría
            </button>
    
        </form>
    </div>
<?php endif; ?>


    <form method="post">
        <input type="hidden" name="action" value="update_seo_categories">
        <?php wp_nonce_field('seo_save_category_editor', 'seo_category_editor_nonce'); ?>

        <?php if (empty($categories_list) || is_wp_error($categories_list)): ?>

            <p style="color:#777;">No se encontraron categorías de producto.</p>

        <?php else: ?>

            <ul style="list-style:none; padding:0; margin:15px 0 30px 0;">

            <?php

            // Preparar categorías agrupadas por jerarquía SEO
            $categorias_agrupadas = [];

            foreach ($categories_list as $category) {

                $term_id = $category->term_id;
                $term_link = get_term_link($category);
                $url_origen_completa = !is_wp_error($term_link) ? $term_link : '#';

                $id_cluster = 0;
                $id_hub_primario = 0;
                $id_hub_secundario = 0;

                $cluster_nombre = '';
                $hub_primario_nombre = '';
                $hub_secundario_nombre = '';

                // Buscar Hub Secundario asociado a esta categoría
                $hub_s_data = $wpdb->get_row($wpdb->prepare("
                    SELECT source_id 
                    FROM $tabla_relations 
                    WHERE target_id = %d 
                      AND relation_type = 'hub_secondary_to_category' 
                    LIMIT 1
                ", $term_id));

                if ($hub_s_data) {
                    $id_hub_secundario = intval($hub_s_data->source_id);

                    // Buscar Hub Primario asociado al Hub Secundario
                    $hub_p_data = $wpdb->get_row($wpdb->prepare("
                        SELECT source_id 
                        FROM $tabla_relations 
                        WHERE target_id = %d 
                          AND relation_type = 'hub_primary_to_hub_secondary' 
                        LIMIT 1
                    ", $id_hub_secundario));

                    if ($hub_p_data) {
                        $id_hub_primario = intval($hub_p_data->source_id);

                        // Buscar Cluster asociado al Hub Primario
                        $cluster_data = $wpdb->get_row($wpdb->prepare("
                            SELECT source_id 
                            FROM $tabla_relations 
                            WHERE target_id = %d 
                              AND relation_type = 'cluster_to_primary' 
                            LIMIT 1
                        ", $id_hub_primario));

                        if ($cluster_data) {
                            $id_cluster = intval($cluster_data->source_id);
                        }
                    }
                }

                // Obtener nombres visibles de la jerarquía SEO
                if ($id_cluster > 0) {
                    $obj = get_post($id_cluster);
                    $cluster_nombre = $obj ? $obj->post_title : '';
                }

                if ($id_hub_primario > 0) {
                    $obj = get_post($id_hub_primario);
                    $hub_primario_nombre = $obj ? $obj->post_title : '';
                }

                if ($id_hub_secundario > 0) {
                    $obj = get_post($id_hub_secundario);
                    $hub_secundario_nombre = $obj ? $obj->post_title : '';
                }

                // URL dinámica hacia productos de la categoría
                $url_ver_productos = add_query_arg([
                    'page'           => 'product-page-admin',
                    'cluster'        => $id_cluster,
                    'hub_primario'   => $id_hub_primario,
                    'hub_secundario' => $id_hub_secundario,
                    'cat'            => $term_id
                ], admin_url('admin.php'));

                $categorias_agrupadas[] = [
                    'category'               => $category,
                    'term_id'                => $term_id,
                    'url_origen_completa'    => $url_origen_completa,
                    'url_ver_productos'      => $url_ver_productos,
                    'id_cluster'             => $id_cluster,
                    'id_hub_primario'        => $id_hub_primario,
                    'id_hub_secundario'      => $id_hub_secundario,
                    'cluster_nombre'         => $cluster_nombre ?: 'Sin cluster',
                    'hub_primario_nombre'    => $hub_primario_nombre ?: 'Sin hub primario',
                    'hub_secundario_nombre'  => $hub_secundario_nombre ?: 'Sin hub secundario',
                ];
            }

            // Ordenar visualmente por Cluster > Hub Primario > Hub Secundario > Categoría
            usort($categorias_agrupadas, function($a, $b) {
                return [
                    $a['cluster_nombre'],
                    $a['hub_primario_nombre'],
                    $a['hub_secundario_nombre'],
                    $a['category']->name
                ] <=> [
                    $b['cluster_nombre'],
                    $b['hub_primario_nombre'],
                    $b['hub_secundario_nombre'],
                    $b['category']->name
                ];
            });

            // Control de cabeceras visuales
            $grupo_cluster_anterior = null;
            $grupo_hub_primario_anterior = null;
            $grupo_hub_secundario_anterior = null;

            foreach ($categorias_agrupadas as $item):

                $category = $item['category'];
                $term_id = $item['term_id'];
                //Info de la descripction de la ctegoria que no esta en Wrdpress, esta enmi tabla
                $seo_node_keywords = $wpdb->get_var($wpdb->prepare("
                    SELECT keywords
                    FROM {$wpdb->prefix}seo_nodes
                    WHERE object_type = 'category'
                      AND object_id = %d
                      AND seo_role = 'category'
                      AND status = 1
                    ORDER BY updated_at DESC, id DESC
                    LIMIT 1
                ", $term_id));
                
                $url_origen_completa = $item['url_origen_completa'];
                $url_ver_productos = $item['url_ver_productos'];

                $id_cluster = $item['id_cluster'];
                $id_hub_primario = $item['id_hub_primario'];
                $id_hub_secundario = $item['id_hub_secundario'];

                $cluster_nombre = $item['cluster_nombre'];
                $hub_primario_nombre = $item['hub_primario_nombre'];
                $hub_secundario_nombre = $item['hub_secundario_nombre'];
                
                // Excerpt SEO guardado en wp_seo_nodes
                    $seo_excerpt = $wpdb->get_var(
                        $wpdb->prepare("
                            SELECT keywords
                            FROM {$wpdb->prefix}seo_nodes
                            WHERE object_type = 'category'
                              AND object_id = %d
                              AND seo_role = 'excerpt'
                              AND status = 1
                            ORDER BY updated_at DESC, id DESC
                            LIMIT 1
                        ", $term_id)
                    );
                // Descripción SEO HTML guardada en wp_seo_nodes
                $seo_description = $wpdb->get_var($wpdb->prepare("
                    SELECT keywords
                    FROM {$wpdb->prefix}seo_nodes
                    WHERE object_type = 'category'
                      AND object_id = %d
                      AND seo_role = 'description'
                      AND status = 1
                    ORDER BY updated_at DESC, id DESC
                    LIMIT 1
                ", $term_id));

                // Cabecera Cluster
                if ($cluster_nombre !== $grupo_cluster_anterior) {

                    echo '<li style="list-style:none; margin:34px 0 14px 0; padding:15px 18px; background:#1d2327; color:#fff; border-radius:8px; font-size:18px; font-weight:bold;">';
                    echo 'Cluster: ' . esc_html($cluster_nombre);
                    echo '</li>';

                    $grupo_cluster_anterior = $cluster_nombre;
                    $grupo_hub_primario_anterior = null;
                    $grupo_hub_secundario_anterior = null;
                }

                // Cabecera Hub Primario
                if ($hub_primario_nombre !== $grupo_hub_primario_anterior) {

                    echo '<li style="list-style:none; margin:20px 0 10px 0; padding:11px 16px; background:#e7f1ff; border-left:5px solid #2271b1; color:#1d2327; border-radius:6px; font-size:15px; font-weight:bold;">';
                    echo 'Hub primario: ' . esc_html($hub_primario_nombre);
                    echo '</li>';

                    $grupo_hub_primario_anterior = $hub_primario_nombre;
                    $grupo_hub_secundario_anterior = null;
                }

                // Cabecera Hub Secundario
                if ($hub_secundario_nombre !== $grupo_hub_secundario_anterior) {

                    echo '<li style="list-style:none; margin:14px 0 8px 0; padding:9px 14px; background:#f6f7f7; border-left:5px solid #72aee6; color:#1d2327; border-radius:6px; font-size:13px; font-weight:bold;">';
                    echo 'Hub secundario: ' . esc_html($hub_secundario_nombre);
                    echo '</li>';

                    $grupo_hub_secundario_anterior = $hub_secundario_nombre;
                }

            ?>

                <li id="cat_row_<?php echo $term_id; ?>" style="
                    margin-bottom:20px;
                    padding:18px;
                    border:1px solid #e5e7eb;
                    border-radius:10px;
                    background:#fff;
                    box-shadow:0 1px 2px rgba(0,0,0,0.04);
                    transition: all 0.4s ease;
                ">

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px;">
                        <strong style="font-size:16px; color:#1d2327;">
                            📦 <?php echo esc_html($category->name); ?>
                        </strong>

                        <button type="submit" style="padding:6px 14px; background:#2271b1; color:#fff; border:none; border-radius:4px; font-weight:bold; font-size:12px; cursor:pointer;">
                            💾 Grabar esta categoría
                        </button>
                    </div>

                    <?php /* URL ACTUAL DE LA CATEGORÍA */ ?>
                    
                    <div style="font-size:12px; margin-bottom:4px;">
                    
                        <strong>URL Origen (A borrar):</strong>
                    
                        <a href="<?php echo esc_url($url_origen_completa); ?>"
                           target="_blank"
                           style="color:#2271b1; text-decoration:none;">
                    
                            <?php echo esc_html($url_origen_completa); ?>
                    
                        </a>
                    
                    </div>

                    <div style="font-size:12px;margin-top:8px;margin-bottom:8px;color:#555;">
                        <strong>Cluster:</strong> <?php echo esc_html($cluster_nombre); ?>
                        <br>
                        <strong>Hub primario:</strong> <?php echo esc_html($hub_primario_nombre); ?>
                        <br>
                        <strong>Hub secundario:</strong> <?php echo esc_html($hub_secundario_nombre); ?>
                    </div>
                    <!-- Edición y redirección de slug -->
                        <!-- Redirect tras modificar -->
                        <?php if (
                            isset($_GET['seo_slug_updated']) &&
                            absint($_GET['seo_slug_updated']) === 1
                        ): ?>
                        
                            <div class="notice notice-success is-dismissible">
                                <p>
                                    <strong>
                                        Slug modificado y redirección 301 creada correctamente.
                                    </strong>
                                </p>
                            </div>
                        <!-- Sistena de modificacion, redirect, etc... -->
                        <?php endif; ?>
                        <div style="
                            display:flex;
                            align-items:center;
                            flex-wrap:wrap;
                            gap:6px;
                            margin-top:6px;
                            font-size:12px;
                        ">
                        
                            <strong>ID:</strong>
                        
                            <span>
                                <?php echo intval($term_id); ?>
                            </span>
                        
                            <span style="color:#a7aaad;">|</span>
                        
                            <strong>Slug:</strong>
                        
                            <span style="color:#646970;">/</span>
                        
                            <input
                                type="text"
                                id="cat_slug_<?php echo intval($term_id); ?>"
                                name="cat_slug[<?php echo intval($term_id); ?>]"
                                value="<?php echo esc_attr($category->slug); ?>"
                                data-original-slug="<?php echo esc_attr($category->slug); ?>"
                                style="
                                    width:220px;
                                    height:28px;
                                    padding:2px 7px;
                                    font-size:12px;
                                    border:1px solid #8c8f94;
                                    border-radius:3px;
                                "
                            >
                        
                            <button
                                type="submit"
                                name="seo_update_category_slug"
                                value="<?php echo intval($term_id); ?>"
                                class="button button-small"
                                title="Cambiar un slug puede provocar errores 404 y pérdida de posicionamiento. Se creará automáticamente una redirección 301 desde la URL antigua hacia la nueva."
                                onclick="return confirm(
                                    '¿Modificar el slug de esta categoría?\n\nSe creará automáticamente una redirección 301 desde la URL anterior hacia la nueva.'
                                );"
                            >
                                Modificar slug
                            </button>
                        
                            <input
                                type="hidden"
                                name="cat_old_slug[<?php echo intval($term_id); ?>]"
                                value="<?php echo esc_attr($category->slug); ?>"
                            >
                        
                            <input
                                type="hidden"
                                name="cat_old_url[<?php echo intval($term_id); ?>]"
                                value="<?php echo esc_url($url_origen_completa); ?>"
                            >
                        
                        </div>
                        
                        <!-- Fin edición y redirección de slug -->

                    <?php /* ENLACE AL LISTADO DE PRODUCTOS DE LA CATEGORÍA */ ?>
                    
                    <div style="font-size:13px; margin-top:14px; margin-bottom:10px; color:#1d2327;">
                    
                        <strong>Productos asociados:</strong>
                        <?php echo intval($category->count); ?> productos
                    
                        <a href="<?php echo esc_url($url_ver_productos); ?>"
                           class="button button-small"
                           style="margin-left:8px; background:#f6f7f7; border:1px solid #8c8f94; color:#2271b1; padding:3px 8px; border-radius:3px; text-decoration:none; font-size:11px; font-weight:600;">
                    
                            Ver productos
                    
                        </a>
                    
                    </div>

                    <hr style="border:0; border-top:1px solid #eee; margin:15px 0;">

                    <div style="margin-top:12px; margin-bottom:12px; font-size:12px;">
                        <strong>Nombre de la Categoría:</strong>
                        <input type="text" name="cat_name[<?php echo $term_id; ?>]" value="<?php echo esc_attr($category->name); ?>" style="width:100%; font-size:13px; padding:6px 10px; border:1px solid #c3c4c7; border-radius:4px; margin-top:4px;">
                    </div>
                    

                    <?php /* EXCERPT SEO DE LA CATEGORÍA - Se lee y guarda en wp_seo_nodes (seo_role = excerpt) */ ?>
                    
                    <div style="margin-bottom:12px; font-size:12px;">
                        <strong>Excerpt SEO:</strong>
                    
                        <textarea
                            name="cat_excerpt[<?php echo $term_id; ?>]"
                            style="width:100%; min-height:80px; font-size:12px; margin-top:4px; border:1px solid #c3c4c7; border-radius:4px; padding:6px; box-sizing:border-box; font-family:sans-serif; resize:vertical;"
                        ><?php echo esc_textarea($seo_excerpt); ?></textarea>
                    </div>
                    
                    <?php /* ETIQUETAS SEO DE LA CATEGORÍA - Se leen desde wp_seo_nodes.keywords */ ?>
                    
                    <div style="margin-bottom:12px; font-size:12px;">
                        <strong>Etiquetas SEO:</strong>
                    
                        <textarea
                            name="cat_tags[<?php echo $term_id; ?>]"
                            style="width:100%; min-height:60px; font-size:12px; margin-top:4px; border:1px solid #c3c4c7; border-radius:4px; padding:6px; box-sizing:border-box; font-family:sans-serif; resize:vertical;"
                        ><?php echo esc_textarea($seo_node_keywords); ?></textarea>
                    </div>



                    <div style="margin-bottom:12px; font-size:12px;">
                        <strong>Descripción (Contenido SEO):</strong>
                        <textarea name="cat_description[<?php echo $term_id; ?>]" style="width:100%; min-height:100px; font-size:12px; margin-top:4px; border:1px solid #c3c4c7; border-radius:4px; padding:6px; box-sizing:border-box; font-family:sans-serif; resize:vertical;"><?php echo esc_textarea($seo_description); ?></textarea>
                    </div>
                    <!-- ELIMINAR CATEGORÍA Y CREAR REDIRECCIÓN -->
<div style="background:#fcfcfc; border:1px dashed #c3c4c7; padding:12px; border-radius:6px; margin-top:12px; display:flex; gap:12px; flex-direction:column;">

    <span style="font-size:11px; font-weight:bold; color:#1d2327; text-transform:uppercase; letter-spacing:0.5px;">
        Elegir Destino de Redirección:
    </span>

    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">

        <div style="display:flex; flex-direction:column; gap:4px;">
            <label style="font-size:11px; color:#646970; font-weight:600;">1. Clusters</label>
            <select id="cluster_<?php echo $term_id; ?>"
                    class="seo-select-<?php echo $term_id; ?>"
                    data-level="1"
                    onchange="seoFiltrarCascada(<?php echo $term_id; ?>, 1)"
                    style="width:160px; font-size:12px; height:28px;">

                <option value="">-- Seleccionar Cluster --</option>

                <?php foreach ($clusters_sistema as $c): ?>
                    <option value="<?php echo intval($c->id); ?>">
                        <?php echo esc_html($c->nombre); ?>
                    </option>
                <?php endforeach; ?>

            </select>
        </div>

        <div style="display:flex; flex-direction:column; gap:4px;">
            <label style="font-size:11px; color:#646970; font-weight:600;">2. Hubs Primarios</label>
            <select id="hub_p_<?php echo $term_id; ?>"
                    class="seo-select-<?php echo $term_id; ?>"
                    data-level="2"
                    disabled
                    onchange="seoFiltrarCascada(<?php echo $term_id; ?>, 2)"
                    style="width:160px; font-size:12px; height:28px;">

                <option value="">-- Esperando Cluster --</option>

            </select>
        </div>

        <div style="display:flex; flex-direction:column; gap:4px;">
            <label style="font-size:11px; color:#646970; font-weight:600;">3. Hubs Secundarios</label>
            <select id="hub_s_<?php echo $term_id; ?>"
                    class="seo-select-<?php echo $term_id; ?>"
                    data-level="3"
                    disabled
                    onchange="seoFiltrarCascada(<?php echo $term_id; ?>, 3)"
                    style="width:160px; font-size:12px; height:28px;">

                <option value="">-- Esperando Hub P. --</option>

            </select>
        </div>

        <div style="display:flex; flex-direction:column; gap:4px;">
            <label style="font-size:11px; color:#646970; font-weight:600;">4. Categoría Destino</label>
            <select id="cat_dest_<?php echo $term_id; ?>"
                    class="seo-select-<?php echo $term_id; ?>"
                    data-level="4"
                    disabled
                    onchange="seoFiltrarCascada(<?php echo $term_id; ?>, 4)"
                    style="width:160px; font-size:12px; height:28px;">

                <option value="">-- Esperando Hub S. --</option>

            </select>
        </div>

        <div style="display:flex; flex-direction:column; gap:4px; min-width:220px; max-width:320px;">
            <label style="font-size:11px; color:#1d2327; font-weight:bold;">
                🔗 Enlace Destino de Redirección:
            </label>

            <div id="url_preview_<?php echo $term_id; ?>"
                 style="font-size:11px; color:#646970; background:#f0f0f1; padding:6px 10px; border:1px solid #dcdcde; border-radius:4px; min-height:28px; word-break:break-all; display:flex; align-items:center;">

                <i>Ningún destino seleccionado</i>

            </div>
        </div>

        <div style="display:flex; flex-direction:column; gap:4px;">
            <button type="button"
                    id="btn_redirect_<?php echo $term_id; ?>"
                    disabled
                    onclick="seoProcesarAccionV2(<?php echo $term_id; ?>, '<?php echo esc_js($url_origen_completa); ?>', 'borrado_total')"
                    style="height:28px; padding:0 12px; background:#f6f7f7; border:1px solid #dcdcde; color:#a7aaad; border-radius:4px; font-weight:bold; cursor:not-allowed; transition:all 0.2s;">

                Eliminar Categoría

            </button>
        </div>

    </div>

</div>

                </li>

            <?php endforeach; ?>

            </ul>

        <?php endif; ?>

        <button type="submit" style="margin-top:20px; padding:12px 25px; background:#1d2327; color:#fff; border:none; border-radius:4px; font-weight:bold; cursor:pointer;">
            Guardar todos los cambios
        </button>

    </form>

</div>





<script type="text/javascript">
var seoDatosRelaciones = <?php echo json_encode($mapeo_completo); ?>;
var seoRelacionesCategorias = <?php echo json_encode($relaciones_categorias); ?>;
var seoUrlsPrecalculadas = <?php echo json_encode($urls_precalculadas); ?>;

var seoUrlsDestinoPorFila = {};





function seoFiltrarCascada(termId, nivelModificado) {
    var clusterSel  = jQuery('#cluster_' + termId);
    var hubPSel     = jQuery('#hub_p_' + termId);
    var hubSSel     = jQuery('#hub_s_' + termId);
    var catDestSel  = jQuery('#cat_dest_' + termId);
    var previewDiv  = jQuery('#url_preview_' + termId);
    
    var btnRelOnly  = jQuery('#btn_rel_only_' + termId);
    var btnBorrar   = jQuery('#btn_redirect_' + termId);

    if (nivelModificado === 1) {
        var clusterId = clusterSel.val();
        hubPSel.html('<option value="">-- Seleccionar Hub Primario --</option>').attr('disabled', 'disabled');
        hubSSel.html('<option value="">-- Esperando Hub P. --</option>').attr('disabled', 'disabled');
        catDestSel.html('<option value="">-- Esperando Hub S. --</option>').attr('disabled', 'disabled');

        if (clusterId !== "") {
            var primarios = seoDatosRelaciones.filter(function(r) {
                return r.source_id === clusterId && r.target_type === 'hub_primary';
            });
            if (primarios.length > 0) {
                primarios.forEach(function(item) {
                    hubPSel.append('<option value="' + item.target_id + '">' + item.target_title + '</option>');
                });
                hubPSel.removeAttr('disabled');
            } else {
                hubPSel.html('<option value="">Sin Hubs Primarios</option>');
            }
        }
    }

    if (nivelModificado === 2) {
        var hubPId = hubPSel.val();
        hubSSel.html('<option value="">-- Seleccionar Hub Secundario --</option>').attr('disabled', 'disabled');
        catDestSel.html('<option value="">-- Esperando Hub S. --</option>').attr('disabled', 'disabled');

        if (hubPId !== "") {
            var secundarios = seoDatosRelaciones.filter(function(r) {
                return r.source_id === hubPId && (r.target_type === 'hub_secondary' || r.target_type === 'hub_secundario');
            });
            if (secundarios.length > 0) {
                secundarios.forEach(function(item) {
                    hubSSel.append('<option value="' + item.target_id + '">' + item.target_title + '</option>');
                });
                hubSSel.removeAttr('disabled');
            } else {
                hubSSel.html('<option value="">Sin Hubs Secundarios</option>');
            }
        }
    }

    if (nivelModificado === 3) {
        var hubSId = hubSSel.val();
        catDestSel.html('<option value="">-- Seleccionar Categoría Destino --</option>').attr('disabled', 'disabled');

        if (hubSId !== "") {
            var categoriasFiltradas = seoRelacionesCategorias.filter(function(c) {
                return c.hub_secundario_id === hubSId;
            });

            if (categoriasFiltradas.length > 0) {
                categoriasFiltradas.forEach(function(item) {
                    if (parseInt(item.cat_term_id) !== parseInt(termId)) {
                        catDestSel.append('<option value="' + item.cat_term_id + '">' + item.cat_nombre + '</option>');
                    }
                });
                catDestSel.removeAttr('disabled');
            } else {
                catDestSel.html('<option value="">Sin Categorías vinculadas</option>');
            }
        }
    }

    var finalUrl = "";
    if (catDestSel.val() && catDestSel.val() !== "") {
        finalUrl = seoUrlsPrecalculadas['cat_' + catDestSel.val()] || "";
    } else if (hubSSel.val() && hubSSel.val() !== "") {
        finalUrl = seoUrlsPrecalculadas['post_' + hubSSel.val()] || "";
    } else if (hubPSel.val() && hubPSel.val() !== "") {
        finalUrl = seoUrlsPrecalculadas['post_' + hubPSel.val()] || "";
    } else if (clusterSel.val() && clusterSel.val() !== "") {
        finalUrl = seoUrlsPrecalculadas['post_' + clusterSel.val()] || "";
    }

    seoUrlsDestinoPorFila[termId] = finalUrl;

    if (finalUrl !== "") {
        previewDiv.html('<strong style="color:#006505;">' + finalUrl + '</strong>');
        
        btnRelOnly.removeAttr('disabled').css({
            'background': '#e2f0fd',
            'border-color': '#2271b1',
            'color': '#2271b1',
            'cursor': 'pointer'
        });
        btnBorrar.removeAttr('disabled').css({
            'background': '#bae0ba',
            'border-color': '#00a32a',
            'color': '#006505',
            'cursor': 'pointer'
        });
    } else {
        previewDiv.html('<i>Ningún destino seleccionado</i>');
        btnRelOnly.attr('disabled', 'disabled').css({
            'background': '#f6f7f7',
            'border-color': '#dcdcde',
            'color': '#a7aaad',
            'cursor': 'not-allowed'
        });
        btnBorrar.attr('disabled', 'disabled').css({
            'background': '#f6f7f7',
            'border-color': '#dcdcde',
            'color': '#a7aaad',
            'cursor': 'not-allowed'
        });
    }
}

function seoProcesarAccionV2(termId, urlOrigen) {

    var urlDestino = seoUrlsDestinoPorFila[termId] || "";

    if (!urlDestino) {
        alert("Error: No se ha detectado ninguna URL de destino válida.");
        return;
    }

    var btnBorrar = jQuery('#btn_redirect_' + termId);
    var fila      = jQuery('#cat_row_' + termId);

    btnBorrar.attr('disabled', 'disabled').css('background', '#f0f0f1');

    jQuery.ajax({
        url: ajaxurl,
        type: 'POST',

        data: {
            action: 'seo_borrar_y_redirigir_categoria',
            term_id: termId,
            url_origen: urlOrigen,
            url_destino: urlDestino
        },

        success: function(response) {

            if (!response || !response.success) {

                alert(
                    'RESPUESTA RECIBIDA: ' +
                    JSON.stringify(response)
                );

                btnBorrar.removeAttr('disabled').css({
                    'background': '#bae0ba',
                    'border-color': '#00a32a',
                    'color': '#006505',
                    'cursor': 'pointer'
                });

                return;
            }

            fila.css({
                'background': '#ffdede',
                'border-color': '#cc0000',
                'opacity': '0.3',
                'transform': 'scale(0.96)'
            });

            setTimeout(function() {

                fila.slideUp(300, function() {
                    jQuery(this).remove();
                });

            }, 300);
        },

        error: function(xhr, textStatus, errorThrown) {

            alert(
                "Error AJAX\n" +
                "Estado: " + textStatus + "\n" +
                "HTTP: " + xhr.status + "\n" +
                "Error: " + errorThrown + "\n" +
                "Respuesta: " + xhr.responseText
            );

            btnBorrar.removeAttr('disabled').css({
                'background': '#bae0ba',
                'border-color': '#00a32a',
                'color': '#006505',
                'cursor': 'pointer'
            });
        }
    });
}



</script>


<?php
// ======================================================
// MODIFICAR SLUG DE CATEGORÍA Y CREAR REDIRECCIÓN 301
// ======================================================

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['seo_update_category_slug'])
    ) {
        /*
         * Comprobar el nonce del formulario.
         */
        if (
            empty($_POST['seo_category_editor_nonce']) ||
            !wp_verify_nonce(
                sanitize_text_field(
                    wp_unslash($_POST['seo_category_editor_nonce'])
                ),
                'seo_save_category_editor'
            )
        ) {
            wp_die('Error de seguridad: nonce no válido.');
        }
    
        /*
         * Permisos para modificar categorías de productos.
         */
        if (!current_user_can('manage_product_terms')) {
            wp_die('No tienes permisos para modificar categorías de producto.');
        }
    
        $term_id = absint($_POST['seo_update_category_slug']);
        $taxonomy = 'product_cat';
    
        if ($term_id <= 0) {
            wp_die('El ID de la categoría no es válido.');
        }
    
        /*
         * Obtener la categoría antes de modificarla.
         */
        $term = get_term($term_id, $taxonomy);
    
        if (!$term || is_wp_error($term)) {
            wp_die('No se ha encontrado la categoría de producto.');
        }
    
        /*
         * Leer y limpiar el nuevo slug.
         */
        $new_slug = '';
    
        if (
            isset($_POST['cat_slug']) &&
            is_array($_POST['cat_slug']) &&
            isset($_POST['cat_slug'][$term_id])
        ) {
            $new_slug = sanitize_title(
                wp_unslash($_POST['cat_slug'][$term_id])
            );
        }
    
        if ($new_slug === '') {
            wp_die('El nuevo slug no puede estar vacío.');
        }
    
        $old_slug = $term->slug;
    
        if ($new_slug === $old_slug) {
            wp_die('El slug introducido es igual al slug actual.');
        }
    
        /*
         * Obtener la URL antigua antes de modificar WordPress.
         */
        $old_url = get_term_link($term);
    
        if (is_wp_error($old_url)) {
            wp_die(
                'No se ha podido obtener la URL antigua: ' .
                esc_html($old_url->get_error_message())
            );
        }
    
        /*
         * Modificar el slug con las herramientas nativas de WordPress.
         */
        $update_result = wp_update_term(
            $term_id,
            $taxonomy,
            [
                'slug' => $new_slug,
            ]
        );
    
        if (is_wp_error($update_result)) {
            wp_die(
                'WordPress no ha podido modificar el slug: ' .
                esc_html($update_result->get_error_message())
            );
        }
    
        /*
         * Limpiar caché y recuperar la categoría actualizada.
         */
        clean_term_cache($term_id, $taxonomy);
    
        $updated_term = get_term($term_id, $taxonomy);
    
        if (!$updated_term || is_wp_error($updated_term)) {
            wp_die(
                'El slug se ha modificado, pero no se pudo recuperar la categoría.'
            );
        }
    
        /*
         * Obtener la nueva URL completa.
         */
        $new_url = get_term_link($updated_term);
    
        if (is_wp_error($new_url)) {
            wp_die(
                'El slug se ha modificado, pero no se pudo generar la nueva URL.'
            );
        }
    
        /*
         * La URL de origen se guarda como ruta interna.
         *
         * Ejemplo:
         * /tienda/herramientas/slug-antiguo
         */
        $origin_url = wp_parse_url(
            $old_url,
            PHP_URL_PATH
        );
    
        $origin_url = '/' . ltrim(
            (string) $origin_url,
            '/'
        );
    
        /*
         * Eliminar barras duplicadas.
         */
        $origin_url = preg_replace(
            '#/+#',
            '/',
            $origin_url
        );
    
        /*
         * Adaptarlo al formato de tu tabla:
         * sin barra final, salvo que sea la raíz.
         */
        if ($origin_url !== '/') {
            $origin_url = untrailingslashit($origin_url);
        }
    
        /*
         * En tu tabla, target_url se guarda como URL completa.
         */
        $target_url = esc_url_raw($new_url);
    
        if (
            empty($origin_url) ||
            empty($target_url)
        ) {
            wp_die(
                'No se pudieron preparar las URLs de la redirección.'
            );
        }
    
        $table_redirects = $wpdb->prefix . 'seo_redirects';
    
        /*
         * Buscar una redirección anterior con el mismo origen.
         * origin_url tiene un índice único.
         */
        $existing_redirect_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id
                 FROM {$table_redirects}
                 WHERE origin_url = %s
                 LIMIT 1",
                $origin_url
            )
        );
    
        if ($existing_redirect_id) {
            /*
             * Si el origen ya existe, actualizar el destino.
             */
            $redirect_result = $wpdb->update(
                $table_redirects,
                [
                    'target_url'  => $target_url,
                    'status_code' => 301,
                ],
                [
                    'id' => absint($existing_redirect_id),
                ],
                [
                    '%s',
                    '%d',
                ],
                [
                    '%d',
                ]
            );
        } else {
            /*
             * Crear una nueva redirección.
             */
            $redirect_result = $wpdb->insert(
                $table_redirects,
                [
                    'origin_url'  => $origin_url,
                    'target_url'  => $target_url,
                    'status_code' => 301,
                    'hits'        => 0,
                    'last_hit'    => null,
                ],
                [
                    '%s',
                    '%s',
                    '%d',
                    '%d',
                    '%s',
                ]
            );
        }
    
        if ($redirect_result === false) {
            wp_die(
                'El slug se modificó correctamente, pero no se pudo guardar ' .
                'la redirección.<br><br>' .
    
                '<strong>Tabla:</strong> ' .
                esc_html($table_redirects) .
                '<br>' .
    
                '<strong>Origen:</strong> ' .
                esc_html($origin_url) .
                '<br>' .
    
                '<strong>Destino:</strong> ' .
                esc_html($target_url) .
                '<br>' .
    
                '<strong>Error SQL:</strong> ' .
                esc_html($wpdb->last_error)
            );
        }
    
        /*
         * Volver a la página sin repetir el POST.
         */
            $return_url = add_query_arg(
                [
                    'page'             => 'category-seo-admin',
                    'seo_slug_updated' => 1,
                    'seo_slug_term'    => $term_id,
                ],
                admin_url('admin.php')
            );
            
            wp_safe_redirect($return_url);
            exit;


    }  //Fin de funcion de modificar slug

} //Fin de funcion general
