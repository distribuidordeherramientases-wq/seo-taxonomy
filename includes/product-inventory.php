<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * API PÚBLICA DEL INVENTARIO DE PRODUCTOS POR CATEGORÍA
 * ==========================================================
 */

/**
 * Resuelve la jerarquía SEO a la que pertenece una categoría.
 *
 * @param int $category_id ID de product_cat.
 * @return array|WP_Error
 */
function seo_get_category_product_inventory_context($category_id) {

    $category_id = absint($category_id);
    $category = get_term($category_id, 'product_cat');

    if (!$category_id || !$category || is_wp_error($category)) {
        return new WP_Error(
            'seo_inventory_category_not_found',
            'La categoría solicitada no existe.'
        );
    }

    global $wpdb;

    $relations_table = $wpdb->prefix . 'seo_relations';

    $hub_secondary_id = absint($wpdb->get_var($wpdb->prepare(
        "SELECT source_id
         FROM {$relations_table}
         WHERE target_id = %d
           AND target_type = 'product_cat'
           AND relation_type = 'hub_secondary_to_category'
         LIMIT 1",
        $category_id
    )));

    if (!$hub_secondary_id) {
        return new WP_Error(
            'seo_inventory_hub_secondary_not_found',
            'La categoría no está vinculada a un hub secundario.'
        );
    }

    $hub_primary_id = absint($wpdb->get_var($wpdb->prepare(
        "SELECT source_id
         FROM {$relations_table}
         WHERE target_id = %d
           AND relation_type = 'hub_primary_to_hub_secondary'
         LIMIT 1",
        $hub_secondary_id
    )));

    if (!$hub_primary_id) {
        return new WP_Error(
            'seo_inventory_hub_primary_not_found',
            'No se encontró el hub primario de la categoría.'
        );
    }

    $cluster_id = absint($wpdb->get_var($wpdb->prepare(
        "SELECT source_id
         FROM {$relations_table}
         WHERE target_id = %d
           AND relation_type = 'cluster_to_primary'
         LIMIT 1",
        $hub_primary_id
    )));

    if (!$cluster_id) {
        return new WP_Error(
            'seo_inventory_cluster_not_found',
            'No se encontró el cluster de la categoría.'
        );
    }

    return [
        'cluster'        => $cluster_id,
        'hub_primario'   => $hub_primary_id,
        'hub_secundario' => $hub_secondary_id,
        'cat'            => $category_id,
    ];
}

/**
 * Genera la URL del inventario filtrado por una categoría.
 *
 * @param int    $category_id ID de product_cat.
 * @param string $page_slug   Slug de la pantalla administrativa.
 * @return string
 */
function seo_get_category_products_inventory_url(
    $category_id,
    $page_slug = 'product-page-admin'
) {
    $context = seo_get_category_product_inventory_context($category_id);

    if (is_wp_error($context)) {
        return '';
    }

    return add_query_arg(
        array_merge(
            [
                'page'          => sanitize_key($page_slug),
                'tab'           => 'inventario',
                'run_inventory' => 1,
                'show_p_id'     => 1,
            ],
            $context
        ),
        admin_url('admin.php')
    );
}


/**
 * ==========================
 * PRODUCT INVENTORY PAGE
 * ==========================
 */
function seo_product_inventory_page($requested_category_id = 0) {

    if (!current_user_can('manage_options')) return;

    global $wpdb;

    $relations_table       = $wpdb->prefix . 'seo_relations';
    $vocabulary_table      = $wpdb->prefix . 'seo_vocabulary';
    $object_vocabulary     = $wpdb->prefix . 'seo_object_vocabulary';
    $type_role_table       = $wpdb->prefix . 'seo_type_role_map';

    // =========================
    // FILTROS
    // =========================
    $cluster        = isset($_GET['cluster']) ? absint($_GET['cluster']) : 0;
    $hub_primario   = isset($_GET['hub_primario']) ? absint($_GET['hub_primario']) : 0;
    $hub_secundario = isset($_GET['hub_secundario']) ? absint($_GET['hub_secundario']) : 0;
    $cat            = isset($_GET['cat']) ? absint($_GET['cat']) : 0;

    $requested_category_id = absint($requested_category_id);

    if ($requested_category_id) {
        $requested_context = seo_get_category_product_inventory_context(
            $requested_category_id
        );

        if (is_wp_error($requested_context)) {
            echo '<div class="notice notice-error"><p>' .
                esc_html($requested_context->get_error_message()) .
                '</p></div>';
            return;
        }

        $cluster        = $requested_context['cluster'];
        $hub_primario   = $requested_context['hub_primario'];
        $hub_secundario = $requested_context['hub_secundario'];
        $cat            = $requested_context['cat'];
    }

    // =========================
    // CAMPOS DE SELECCION
    // =========================
        // CLUSTER
        $show_c_id      = isset($_GET['show_c_id']);
        $show_c_slug    = isset($_GET['show_c_slug']);
        $show_c_excerpt = isset($_GET['show_c_excerpt']);
        $show_c_content = isset($_GET['show_c_content']);
        
        // HUB PRIMARIO
        $show_hp_id      = isset($_GET['show_hp_id']);
        $show_hp_slug    = isset($_GET['show_hp_slug']);
        $show_hp_excerpt = isset($_GET['show_hp_excerpt']);
        $show_hp_content = isset($_GET['show_hp_content']);
        
        // HUB SECUNDARIO
        $show_hs_id      = isset($_GET['show_hs_id']);
        $show_hs_slug    = isset($_GET['show_hs_slug']);
        $show_hs_excerpt = isset($_GET['show_hs_excerpt']);
        $show_hs_content = isset($_GET['show_hs_content']);
        
        // CATEGORÍA
        $show_cat_id   = isset($_GET['show_cat_id']);
        $show_cat_slug = isset($_GET['show_cat_slug']);
        $show_cat_desc = isset($_GET['show_cat_desc']);
        
        // PRODUCTO
        $show_p_id         = $requested_category_id > 0 || isset($_GET['show_p_id']);
        $show_p_slug       = isset($_GET['show_p_slug']);
        $show_p_excerpt    = isset($_GET['show_p_excerpt']);
        $show_p_content    = isset($_GET['show_p_content']);
        $show_p_tags       = isset($_GET['show_p_tags']);
        $show_p_attributes = isset($_GET['show_p_attributes']);


    $run_inventory  = isset($_GET['run_inventory']) && $_GET['run_inventory'] == 1;

    // =========================
    // SELECTS DINÁMICOS
    // =========================
    $cluster_ids = $wpdb->get_col("
        SELECT DISTINCT source_id
        FROM $relations_table
        WHERE source_type = 'cluster'
        AND source_id > 0
    ");

    $hub_primarios_ids = [];
    if ($cluster > 0) {
        $hub_primarios_ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT target_id
            FROM $relations_table
            WHERE source_id = %d
            AND relation_type = 'cluster_to_primary'
        ", $cluster));
    }

    $hub_secundarios_ids = [];
    if ($hub_primario > 0) {
        $hub_secundarios_ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT target_id
            FROM $relations_table
            WHERE source_id = %d
            AND relation_type = 'hub_primary_to_hub_secondary'
        ", $hub_primario));
    }

    $category_ids_from_db = [];
    if ($hub_secundario > 0) {
        $category_ids_from_db = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT target_id
            FROM $relations_table
            WHERE source_id = %d
            AND relation_type = 'hub_secondary_to_category'
        ", $hub_secundario));
    }

    $all_cats = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => false
    ]);

    ob_start();
    ?>

    <div style="padding:20px;background:#fff;border:1px solid #ccd0d4;border-radius:8px;">
        <h2>Inventario de productos</h2>

            <form method="GET" style="margin-bottom:20px;padding:15px;background:#f6f7f7;border:1px solid #ddd;border-radius:6px;">
                <input type="hidden" name="page" value="<?php echo esc_attr($_GET['page'] ?? 'product-page-admin'); ?>">
                <input type="hidden" name="tab" value="inventario">
            
                <select name="cluster" onchange="this.form.submit()">
                    <option value="0">Cluster</option>
                    <?php foreach ($cluster_ids as $id):
                        $p = get_post($id);
                    ?>
                        <option value="<?php echo $id; ?>" <?php selected($cluster, $id); ?>>
                            <?php echo esc_html($p ? $p->post_title : "Cluster $id"); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            
                <select name="hub_primario" onchange="this.form.submit()">
                    <option value="0">Hub primario</option>
                    <?php foreach ($hub_primarios_ids as $id):
                        $p = get_post($id);
                    ?>
                        <option value="<?php echo $id; ?>" <?php selected($hub_primario, $id); ?>>
                            <?php echo esc_html($p ? $p->post_title : "HP $id"); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            
                <select name="hub_secundario" onchange="this.form.submit()">
                    <option value="0">Hub secundario</option>
                    <?php foreach ($hub_secundarios_ids as $id):
                        $p = get_post($id);
                    ?>
                        <option value="<?php echo $id; ?>" <?php selected($hub_secundario, $id); ?>>
                            <?php echo esc_html($p ? $p->post_title : "HS $id"); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            
                <select name="cat" onchange="this.form.submit()">
                    <option value="0">Categoría</option>
                    <?php foreach ($all_cats as $c): ?>
                        <?php if (empty($category_ids_from_db) || in_array($c->term_id, $category_ids_from_db)): ?>
                            <option value="<?php echo $c->term_id; ?>" <?php selected($cat, $c->term_id); ?>>
                                <?php echo esc_html($c->name); ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            
                <!-- 🔴 AQUI ESTÁ EL CAMBIO IMPORTANTE -->
                    <div style="margin-top:15px;padding:12px;background:#eef1f3;border:1px solid #ccd0d4;border-radius:6px;">
                    
                    <strong>Cluster</strong><br>
                    <input type="checkbox" name="show_c_id" <?php checked($show_c_id); ?>> ID
                    <input type="checkbox" name="show_c_slug" <?php checked($show_c_slug); ?>> Slug
                    <input type="checkbox" name="show_c_excerpt" <?php checked($show_c_excerpt); ?>> Excerpt
                    <input type="checkbox" name="show_c_content" <?php checked($show_c_content); ?>> Descripción
                    
                    <br><br>
                    
                    <strong>Hub Primario</strong><br>
                    <input type="checkbox" name="show_hp_id" <?php checked($show_hp_id); ?>> ID
                    <input type="checkbox" name="show_hp_slug" <?php checked($show_hp_slug); ?>> Slug
                    <input type="checkbox" name="show_hp_excerpt" <?php checked($show_hp_excerpt); ?>> Excerpt
                    <input type="checkbox" name="show_hp_content" <?php checked($show_hp_content); ?>> Descripción
                    
                    <br><br>
                    
                    <strong>Hub Secundario</strong><br>
                    <input type="checkbox" name="show_hs_id" <?php checked($show_hs_id); ?>> ID
                    <input type="checkbox" name="show_hs_slug" <?php checked($show_hs_slug); ?>> Slug
                    <input type="checkbox" name="show_hs_excerpt" <?php checked($show_hs_excerpt); ?>> Excerpt
                    <input type="checkbox" name="show_hs_content" <?php checked($show_hs_content); ?>> Descripción
                    
                    <br><br>

                    <strong>Categoría</strong><br>
                    <input type="checkbox" name="show_cat_id" <?php checked($show_cat_id); ?>> ID
                    <input type="checkbox" name="show_cat_slug" <?php checked($show_cat_slug); ?>> Slug
                    <input type="checkbox" name="show_cat_desc" <?php checked($show_cat_desc); ?>> Descripción
                    
                    
                    <br><br>

                    <strong>Producto</strong><br>
                    <input type="checkbox" name="show_p_id" <?php checked($show_p_id); ?>> ID
                    <input type="checkbox" name="show_p_slug" <?php checked($show_p_slug); ?>> Slug
                    <input type="checkbox" name="show_p_excerpt" <?php checked($show_p_excerpt); ?>> Excerpt
                    <input type="checkbox" name="show_p_content" <?php checked($show_p_content); ?>> Descripción
                    <input type="checkbox" name="show_p_tags" <?php checked($show_p_tags); ?>> Etiquetas semánticas
                    <input type="checkbox" name="show_p_attributes" <?php checked($show_p_attributes); ?>> Atributos
                    </div>
            
                <br>
            
                <button type="submit" name="run_inventory" value="1" class="button button-primary">
                    Inventario
                </button>
            </form>
        
        <?php

    // =========================
    // BLOQUEO DE EJECUCIÓN
    // =========================

    if ($cluster <= 0) {
        echo '<p>Selecciona un cluster.</p>';
        echo '</div>';
        echo ob_get_clean();
        return;
    }

    // =========================
    // RESOLVER LABELS
    // =========================
    $resolve = function($id, $fallback = 'Elemento') {
        $post = get_post($id);
        if ($post) return $post->post_title;

        $term = get_term($id, 'product_cat');
        if ($term && !is_wp_error($term)) return $term->name;

        return $fallback . " $id";
    };

    // =========================
    // RECORRIDO JERÁRQUICO
    // =========================
    $hub_primarios = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT target_id
        FROM $relations_table
        WHERE source_id = %d
        AND relation_type = 'cluster_to_primary'
    ", $cluster));

    foreach ($hub_primarios as $hp_id) {

        if ($hub_primario && $hp_id != $hub_primario) continue;
                /* info del cluster */
                $cluster_obj = get_post($cluster);
                
                echo '<div style="border:2px solid #1d2327;border-radius:6px;margin-bottom:20px;padding:15px;background:#f0f0f1;">';
                
                echo '<h2 style="margin:0 0 10px 0;">' . esc_html($resolve($cluster)) . '</h2>';
                
                if ($cluster_obj) {
                
                    echo '<div style="font-size:12px;color:#666;margin-bottom:10px;">';
                
                    if ($show_c_id) {
                        echo '<div><strong>ID:</strong> ' . intval($cluster_obj->ID) . '</div>';
                    }
                
                    if ($show_c_slug) {
                        echo '<div><strong>Slug:</strong> ' . esc_html($cluster_obj->post_name) . '</div>';
                    }
                
                    if ($show_c_excerpt && !empty($cluster_obj->post_excerpt)) {
                        echo '<div><strong>Excerpt:</strong> ' . esc_html($cluster_obj->post_excerpt) . '</div>';
                    }
                
                    if ($show_c_content && !empty($cluster_obj->post_content)) {
                        echo '<div><strong>Descripción:</strong> ' . wp_trim_words(strip_tags($cluster_obj->post_content), 25) . '</div>';
                    }
                
                    echo '</div>';
                }
                // cierre cluster
                echo '</div>';

        
        echo '<div style="border-left:5px solid #2271b1;margin:15px 0 15px 10px;padding:12px;background:#f6f7f7;">';
        echo '<h3 style="margin:0 0 8px 0;">' . esc_html($resolve($hp_id)) . '</h3>';
            $hp_obj = get_post($hp_id);
            
            if ($hp_obj) {
            
                echo '<div style="margin-left:10px;font-size:12px;color:#666;">';
            
                if ($show_hp_id) {
                    echo '<div>ID: ' . $hp_obj->ID . '</div>';
                }
            
                if ($show_hp_slug) {
                    echo '<div>Slug: ' . esc_html($hp_obj->post_name) . '</div>';
                }
            
                if ($show_hp_excerpt && !empty($hp_obj->post_excerpt)) {
                    echo '<div>Excerpt: ' . esc_html($hp_obj->post_excerpt) . '</div>';
                }
            
                if ($show_hp_content && !empty($hp_obj->post_content)) {
                    echo '<div>Descripción: ' . wp_trim_words(strip_tags($hp_obj->post_content), 25) . '</div>';
                }
            
                echo '</div>';
            }
        echo '</div>';
            

        $hub_secundarios = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT target_id
            FROM $relations_table
            WHERE source_id = %d
            AND relation_type = 'hub_primary_to_hub_secondary'
        ", $hp_id));

        foreach ($hub_secundarios as $hs_id) {

            if ($hub_secundario && $hs_id != $hub_secundario) continue;

            echo '<div style="border-left:4px solid #72aee6;margin:10px 0 10px 20px;padding:10px;background:#ffffff;">';
                echo '<h3 style="margin:0 0 6px 0;">' . esc_html($resolve($hs_id)) . '</h3>';
                $hs_obj = get_post($hs_id);
    
                    if ($hs_obj) {
                    
                        echo '<div style="margin-left:20px;font-size:15px;color:#666;">';
                    
                        if ($show_hs_id) {
                            echo '<div>ID: ' . $hs_obj->ID . '</div>';
                        }
                    
                        if ($show_hs_slug) {
                            echo '<div>Slug: ' . esc_html($hs_obj->post_name) . '</div>';
                        }
                    
                        if ($show_hs_excerpt && !empty($hs_obj->post_excerpt)) {
                            echo '<div>Excerpt: ' . esc_html($hs_obj->post_excerpt) . '</div>';
                        }
                    
                        if ($show_hs_content && !empty($hs_obj->post_content)) {
                            echo '<div>Descripción: ' . wp_trim_words(strip_tags($hs_obj->post_content), 25) . '</div>';
                        }
                    
                        echo '</div>';
                    }
            echo '</div>';

            $cats = $wpdb->get_col($wpdb->prepare("
                SELECT DISTINCT target_id
                FROM $relations_table
                WHERE source_id = %d
                AND relation_type = 'hub_secondary_to_category'
            ", $hs_id));

            foreach ($cats as $cat_id) {

                if ($cat && $cat_id != $cat) continue;

                $term = get_term($cat_id, 'product_cat');
                $cat_name = $term ? $term->name : "Cat $cat_id";

                $count = $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(*)
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                    INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                    WHERE p.post_type = 'product'
                    AND tt.term_id = %d
                ", $cat_id));

                echo '<div style="margin:10px 0 10px 40px;padding:10px;border:1px solid #dcdcde;border-radius:6px;background:#fcfcfc;">';
                
                    echo '<strong>' . esc_html($cat_name) . '</strong>';
                    
                    if ($show_cat_id) {
                        echo ' <span style="color:#666;">(ID: ' . intval($term->term_id) . ')</span>';
                    }
                    
                    echo ' → ' . intval($count) . ' productos';
                    
                    
                    if ($show_cat_slug || $show_cat_desc) {

                        echo '<div style="margin-top:5px;font-size:12px;color:#666;">';
                    
                        if ($show_cat_slug) {
                            echo '<div><strong>Slug:</strong> ' . esc_html($term->slug) . '</div>';
                        }
                    
                        if ($show_cat_desc && !empty($term->description)) {
                            echo '<div><strong>Descripción:</strong> ' . esc_html($term->description) . '</div>';
                        }
                    
                        echo '</div>';
                    }
                        
                        // Activar listado de productos si se marca cualquier campo del producto.
                        $show_products = (
                            $show_p_id ||
                            $show_p_slug ||
                            $show_p_excerpt ||
                            $show_p_content ||
                            $show_p_tags ||
                            $show_p_attributes
                        );
                        
                        if ($show_products) {
                        
                            // Obtener productos asociados a la categoría actual.
                            $products = $wpdb->get_results($wpdb->prepare("
                                SELECT DISTINCT p.ID, p.post_title, p.post_name, p.post_excerpt, p.post_content
                                FROM {$wpdb->posts} p
                                INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                                WHERE p.post_type = 'product'
                                AND p.post_status IN ('publish','draft','pending','private')
                                AND tt.taxonomy = 'product_cat'
                                AND tt.term_id = %d
                                ORDER BY p.post_title ASC
                                LIMIT 100
                            ", $cat_id));
                        
                            if (!empty($products)) {
                        
                                echo '<ul style="margin-top:10px;margin-left:20px;">';
                        
                                foreach ($products as $p) {
                        
                                    echo '<li style="margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid #eee;">';
                        
                                    // Título del producto.
                                    echo '<strong>' . esc_html($p->post_title) . '</strong><br>';
                        
                                    if ($show_p_id) {
                                        echo '<strong>ID:</strong> ' . intval($p->ID) . '<br>';
                                    }
                        
                                    if ($show_p_slug) {
                                        echo '<strong>Slug:</strong> ' . esc_html($p->post_name) . '<br>';
                                    }
                                // Clasificación semántica canónica del producto.
                                // Ya no se leen las antiguas etiquetas libres de seo_nodes.
                                if ($show_p_tags) {

                                    $semantic = [
                                        'tipo'       => [],
                                        'rol'        => [],
                                        'aplicacion' => [],
                                        'plataforma' => [],
                                        'subtipo'    => [],
                                    ];

                                    // Asignaciones activas guardadas en el vocabulario canónico.
                                    $semantic_rows = $wpdb->get_results($wpdb->prepare(
                                        "SELECT v.id, v.semantic_group, v.slug, v.label
                                         FROM {$object_vocabulary} ov
                                         INNER JOIN {$vocabulary_table} v
                                           ON v.id = ov.vocabulary_id
                                          AND v.active = 1
                                         WHERE ov.object_type = 'product'
                                           AND ov.object_id = %d
                                           AND ov.status = 1
                                           AND v.semantic_group IN ('tipo','rol','aplicacion','plataforma','subtipo')
                                         ORDER BY FIELD(v.semantic_group, 'tipo','rol','aplicacion','plataforma','subtipo'),
                                                  v.label ASC",
                                        $p->ID
                                    ));

                                    $type_ids = [];

                                    foreach ((array) $semantic_rows as $semantic_row) {
                                        $group = sanitize_key((string) $semantic_row->semantic_group);
                                        $label = trim((string) $semantic_row->label);

                                        if (!isset($semantic[$group]) || $label === '') {
                                            continue;
                                        }

                                        if ($group === 'tipo') {
                                            $type_ids[] = absint($semantic_row->id);
                                        }

                                        $semantic[$group][] = $label;
                                    }

                                    // El ROL canónico se deriva prioritariamente del TIPO.
                                    // Solo si no puede resolverse se conserva el ROL materializado.
                                    $derived_roles = [];

                                    if (!empty($type_ids)) {
                                        $type_placeholders = implode(',', array_fill(0, count($type_ids), '%d'));

                                        $derived_roles = $wpdb->get_col($wpdb->prepare(
                                            "SELECT DISTINCT rv.label
                                             FROM {$type_role_table} trm
                                             INNER JOIN {$vocabulary_table} rv
                                               ON rv.id = trm.role_vocabulary_id
                                              AND rv.semantic_group = 'rol'
                                              AND rv.active = 1
                                             WHERE trm.active = 1
                                               AND trm.type_vocabulary_id IN ({$type_placeholders})
                                             ORDER BY rv.label ASC",
                                            ...$type_ids
                                        ));
                                    }

                                    if (!empty($derived_roles)) {
                                        $semantic['rol'] = array_values(array_filter(array_map('trim', $derived_roles)));
                                    }

                                    foreach ($semantic as $group => $values) {
                                        $semantic[$group] = array_values(array_unique(array_filter($values)));
                                    }

                                    $semantic_labels = [
                                        'tipo'       => 'TIPO',
                                        'rol'        => 'ROL',
                                        'aplicacion' => 'APLICACIÓN',
                                        'plataforma' => 'PLATAFORMA',
                                        'subtipo'    => 'SUBTIPO',
                                    ];

                                    echo '<div style="margin:6px 0 8px;padding:8px 10px;background:#f6f7f7;border-left:3px solid #2271b1;">';

                                    foreach ($semantic_labels as $group => $label) {
                                        echo '<strong>' . esc_html($label) . ':</strong> ';

                                        if (!empty($semantic[$group])) {
                                            echo esc_html(implode(' | ', $semantic[$group]));
                                        } else {
                                            echo '<span style="color:#8c8f94;">Sin valores</span>';
                                        }

                                        echo '<br>';
                                    }

                                    echo '</div>';
                                }
                                    
                                    
                                    
                                    
                                    if ($show_p_attributes) {
                                        $product_attributes = function_exists('seo_attributes_get_product_rows')
                                            ? seo_attributes_get_product_rows($p->ID)
                                            : [];

                                        if (!empty($product_attributes)) {
                                            $formatted_attributes = [];
                                            foreach ($product_attributes as $attr) {
                                                $type = trim((string) ($attr->attribute_name ?? $attr->attribute_type ?? ''));
                                                $value = trim((string) ($attr->attribute_value ?? ''));
                                                if ($type !== '' && $value !== '') {
                                                    $formatted_attributes[] = $type . ': ' . $value;
                                                }
                                            }

                                            echo '<strong>Atributos:</strong> ' . esc_html(implode(', ', array_unique($formatted_attributes))) . '<br>';
                                        } else {
                                            echo '<strong>Atributos:</strong> Sin atributos guardados<br>';
                                        }
                                    }
                        
                                    if ($show_p_excerpt) {
                        
                                        if (!empty($p->post_excerpt)) {
                                            echo '<strong>Excerpt:</strong> ' . esc_html($p->post_excerpt) . '<br>';
                                        } else {
                                            echo '<strong>Excerpt:</strong> Vacío<br>';
                                        }
                                    }
                        
                                    if ($show_p_content) {
                        
                                        if (!empty($p->post_content)) {
                                            echo '<strong>Descripción:</strong> ' . esc_html(wp_trim_words(strip_tags($p->post_content), 25)) . '<br>';
                                        } else {
                                            echo '<strong>Descripción:</strong> Vacía<br>';
                                        }
                                    }
                        
                                    echo '</li>';
                                }
                        
                                echo '</ul>';
                        
                            } else {
                        
                                echo '<p style="margin-left:20px;color:#666;">No hay productos en esta categoría.</p>';
                            }
                        }
                        // Cerrar bloque de categoría
                        echo '</div>';
                        
                        
                        
            }
        }
    }

    echo '</div>';

    echo ob_get_clean();
}