<?php
if (!defined('ABSPATH')) exit;

/**
 * Operaciones de persistencia propias del editor de categorías.
 * Las tablas SEO modificadas se escriben siempre mediante SEO_Data_Layer.
 */
if (!function_exists('seo_category_redirect_insert_and_unlink_with_data_layer')) {
    function seo_category_redirect_insert_and_unlink_with_data_layer($term_id, $origin_url, $target_url, $source_module) {
        global $wpdb;

        $term_id = absint($term_id);
        if ($term_id < 1) {
            throw new InvalidArgumentException('Categoría no válida.');
        }
        seo_category_vocabulary_assert_data_layer('redirects');
        seo_category_vocabulary_assert_data_layer('relations');

        $relations_table = $wpdb->prefix . 'seo_relations';
        $relation_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM {$relations_table} WHERE target_id = %d AND target_type = 'product_cat' ORDER BY id ASC",
                $term_id
            ),
            ARRAY_A
        );

        $operation = SEO_Data_Layer::operation([
            'type'          => 'category_redirect_and_unlink',
            'label'         => 'Crear redirección y desvincular categoría',
            'source_module' => substr(sanitize_key((string) $source_module), 0, 50),
            'rollbackable'  => true,
            'risk_level'    => 'high',
            'audit_level'   => 'full',
            'metadata'      => [
                'category_id'   => $term_id,
                'origin_url'    => $origin_url,
                'target_url'    => $target_url,
                'relations'     => count((array) $relation_rows),
            ],
        ]);
        $operation->mark_validated(['category_id' => $term_id]);
        $operation->mark_previewed(1 + count((array) $relation_rows));

        $operation->execute(
            static function (SEO_Data_Operation $op) use ($term_id, $origin_url, $target_url, $relation_rows) {
                $op->insert('redirects', [
                    'origin_url'  => $origin_url,
                    'target_url'  => $target_url,
                    'status_code' => 301,
                    'hits'        => 0,
                    'last_hit'    => null,
                ], [
                    'related_object_type' => 'product_cat',
                    'related_object_id'   => $term_id,
                    'reason'              => 'category_redirect',
                ]);

                foreach ((array) $relation_rows as $row) {
                    $relation_id = absint($row['id'] ?? 0);
                    if ($relation_id < 1) {
                        continue;
                    }
                    $op->delete('relations', ['id' => $relation_id], [
                        'related_object_type' => 'product_cat',
                        'related_object_id'   => $term_id,
                        'reason'              => 'category_unlinked',
                    ]);
                }
            }
        );

        return ['operation_id' => $operation->id(), 'operation_uuid' => $operation->uuid()];
    }
}

if (!function_exists('seo_category_redirect_upsert_with_data_layer')) {
    function seo_category_redirect_upsert_with_data_layer($origin_url, $target_url, $term_id, $source_module) {
        global $wpdb;

        seo_category_vocabulary_assert_data_layer('redirects');
        $redirects_table = $wpdb->prefix . 'seo_redirects';
        $existing_id = absint($wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$redirects_table} WHERE origin_url = %s LIMIT 1", $origin_url)
        ));

        $operation = SEO_Data_Layer::operation([
            'type'          => 'category_redirect_upsert',
            'label'         => 'Guardar redirección de categoría',
            'source_module' => substr(sanitize_key((string) $source_module), 0, 50),
            'rollbackable'  => true,
            'risk_level'    => 'medium',
            'audit_level'   => 'full',
            'metadata'      => ['category_id' => absint($term_id), 'origin_url' => $origin_url, 'target_url' => $target_url],
        ]);
        $operation->mark_validated(['category_id' => absint($term_id)]);
        $operation->mark_previewed(1);

        $operation->execute(
            static function (SEO_Data_Operation $op) use ($existing_id, $origin_url, $target_url, $term_id) {
                if ($existing_id > 0) {
                    $op->update('redirects', ['id' => $existing_id], [
                        'target_url'  => $target_url,
                        'status_code' => 301,
                        'updated_at'  => current_time('mysql'),
                    ], [
                        'related_object_type' => 'product_cat',
                        'related_object_id'   => absint($term_id),
                        'reason'              => 'category_slug_redirect_updated',
                    ]);
                } else {
                    $op->insert('redirects', [
                        'origin_url'  => $origin_url,
                        'target_url'  => $target_url,
                        'status_code' => 301,
                        'hits'        => 0,
                        'last_hit'    => null,
                    ], [
                        'related_object_type' => 'product_cat',
                        'related_object_id'   => absint($term_id),
                        'reason'              => 'category_slug_redirect_created',
                    ]);
                }
            }
        );

        return ['operation_id' => $operation->id(), 'operation_uuid' => $operation->uuid()];
    }
}




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

    try {
        $operation = seo_category_redirect_insert_and_unlink_with_data_layer(
            $term_id,
            $ruta_origen,
            $ruta_destino,
            'category_admin_unlink'
        );
    } catch (Throwable $exception) {
        wp_send_json_error(['message' => 'No se pudo completar la operación mediante Data Layer: ' . $exception->getMessage()]);
    }

    wp_send_json_success([
        'message' => 'Desvinculado con éxito del mapa relacional.',
        'operation_id' => intval($operation['operation_id'] ?? 0),
    ]);
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

    try {
        $operation = seo_category_redirect_insert_and_unlink_with_data_layer(
            $term_id,
            $ruta_origen,
            $ruta_destino,
            'category_admin_delete'
        );
    } catch (Throwable $exception) {
        wp_send_json_error(['message' => 'No se pudo crear la redirección/desvinculación mediante Data Layer: ' . $exception->getMessage()]);
    }

    // Borrado físico total del término se delega a la API de WordPress/WooCommerce.
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
 * Catálogo activo del Vocabulary, agrupado para el editor visual de categorías.
 */
if (!function_exists('seo_category_editor_vocabulary_catalog')) {
    function seo_category_editor_vocabulary_catalog() {
        $catalog = [];
        foreach (seo_category_vocabulary_groups() as $group) {
            $catalog[$group] = [];
        }

        if (!function_exists('seo_category_vocabulary_active_index')) {
            return $catalog;
        }

        $index = seo_category_vocabulary_active_index();
        foreach ((array) ($index['by_id'] ?? []) as $row) {
            $group = seo_category_vocabulary_group_key($row['semantic_group'] ?? '');
            $id = absint($row['id'] ?? 0);
            if ($group === '' || $id < 1 || !isset($catalog[$group])) {
                continue;
            }
            $catalog[$group][$id] = [
                'id'    => $id,
                'label' => (string) ($row['label'] ?? $row['slug'] ?? ''),
                'slug'  => (string) ($row['slug'] ?? ''),
            ];
        }

        foreach ($catalog as &$rows) {
            uasort($rows, static function ($a, $b) {
                return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
            });
        }
        unset($rows);

        return $catalog;
    }
}

/**
 * Convierte filas de seo_object_vocabulary en IDs seleccionados por grupo.
 */
if (!function_exists('seo_category_editor_selected_vocabulary')) {
    function seo_category_editor_selected_vocabulary($rows) {
        $selected = [];
        foreach (seo_category_vocabulary_groups() as $group) {
            $selected[$group] = [];
        }
        foreach ((array) $rows as $row) {
            $group = seo_category_vocabulary_group_key($row['semantic_group'] ?? '');
            $id = absint($row['vocabulary_id'] ?? 0);
            if ($group !== '' && $id > 0 && isset($selected[$group])) {
                $selected[$group][] = $id;
            }
        }
        foreach ($selected as $group => $ids) {
            $selected[$group] = array_values(array_unique(array_filter(array_map('absint', $ids))));
        }
        return $selected;
    }
}

/**
 * Vocabulary usado por los productos asociados directamente a cada categoría.
 * Se usa únicamente como sugerencia visual; nunca se asigna automáticamente.
 */
if (!function_exists('seo_category_editor_product_vocabulary_suggestions')) {
    function seo_category_editor_product_vocabulary_suggestions($category_ids) {
        global $wpdb;

        $category_ids = array_values(array_unique(array_filter(array_map('absint', (array) $category_ids))));
        if (!$category_ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($category_ids), '%d'));
        $object_table = $wpdb->prefix . 'seo_object_vocabulary';
        $vocab_table = $wpdb->prefix . 'seo_vocabulary';

        $sql = "SELECT tt.term_id AS category_id, v.semantic_group, v.id AS vocabulary_id, v.label,\n"
            . "       COUNT(DISTINCT p.ID) AS product_count\n"
            . "FROM {$wpdb->term_relationships} tr\n"
            . "JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'\n"
            . "JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = 'product'\n"
            . "  AND p.post_status IN ('publish','draft','pending','private')\n"
            . "JOIN {$object_table} ov ON ov.object_type = 'product' AND ov.object_id = p.ID AND ov.status = 1\n"
            . "JOIN {$vocab_table} v ON v.id = ov.vocabulary_id AND v.active = 1\n"
            . "WHERE tt.term_id IN ({$placeholders})\n"
            . "  AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')\n"
            . "GROUP BY tt.term_id, v.semantic_group, v.id, v.label\n"
            . "ORDER BY tt.term_id ASC, v.semantic_group ASC, product_count DESC, v.label ASC";

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$category_ids), ARRAY_A);
        $map = [];
        foreach ((array) $rows as $row) {
            $category_id = absint($row['category_id'] ?? 0);
            $group = seo_category_vocabulary_group_key($row['semantic_group'] ?? '');
            $vocabulary_id = absint($row['vocabulary_id'] ?? 0);
            if ($category_id < 1 || $group === '' || $vocabulary_id < 1) {
                continue;
            }
            $map[$category_id][$group][$vocabulary_id] = [
                'id'            => $vocabulary_id,
                'label'         => (string) ($row['label'] ?? ''),
                'product_count' => max(0, absint($row['product_count'] ?? 0)),
            ];
        }
        return $map;
    }
}

/**
 * Selector visual de Vocabulary: checkboxes buscables y sugerencias de productos.
 */
if (!function_exists('seo_category_editor_render_vocabulary_picker')) {
    function seo_category_editor_render_vocabulary_picker($field_prefix, array $selected = [], array $suggestions = [], $instance = '') {
        $catalog = seo_category_editor_vocabulary_catalog();
        $instance = sanitize_html_class((string) $instance);
        if ($instance === '') {
            $instance = 'new';
        }

        echo '<div class="seo-category-vocab-picker" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin-top:7px;">';
        foreach (seo_category_vocabulary_groups() as $group) {
            $label = seo_category_vocabulary_group_label($group);
            $group_selected = array_values(array_unique(array_filter(array_map('absint', (array) ($selected[$group] ?? [])))));
            $group_suggestions = (array) ($suggestions[$group] ?? []);
            $target_id = 'seo-cat-vocab-' . $instance . '-' . $group;

            echo '<div style="border:1px solid #dcdcde;border-radius:7px;padding:10px;background:#fff;">';
            echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;">';
            echo '<strong>' . esc_html($label) . '</strong>';
            if ($group_suggestions) {
                echo '<span style="font-size:11px;color:#646970;">sugerencias de productos</span>';
            }
            echo '</div>';

            if ($group_suggestions) {
                echo '<div style="display:flex;gap:5px;flex-wrap:wrap;margin:0 0 8px;">';
                $shown = 0;
                foreach ($group_suggestions as $suggestion) {
                    if ($shown >= 6) {
                        break;
                    }
                    $sid = absint($suggestion['id'] ?? 0);
                    if ($sid < 1) {
                        continue;
                    }
                    echo '<button type="button" class="button button-small seo-category-vocab-suggestion" data-target="' . esc_attr($target_id) . '" data-vocabulary-id="' . esc_attr($sid) . '" title="Marcar esta etiqueta">'
                        . esc_html((string) ($suggestion['label'] ?? ''))
                        . ' <small>(' . esc_html((string) absint($suggestion['product_count'] ?? 0)) . ')</small></button>';
                    $shown++;
                }
                echo '</div>';
            }

            echo '<input type="search" class="seo-category-vocab-filter" data-target="' . esc_attr($target_id) . '" placeholder="Buscar ' . esc_attr($label) . '…" style="width:100%;margin-bottom:6px;">';
            echo '<div id="' . esc_attr($target_id) . '" style="max-height:170px;overflow:auto;border:1px solid #dcdcde;border-radius:5px;padding:5px;background:#f9f9f9;">';

            if (empty($catalog[$group])) {
                echo '<span style="display:block;padding:6px;color:#646970;">No hay términos activos.</span>';
            } else {
                foreach ($catalog[$group] as $term) {
                    $term_id = absint($term['id'] ?? 0);
                    $term_label = (string) ($term['label'] ?? $term['slug'] ?? '');
                    $count = absint($group_suggestions[$term_id]['product_count'] ?? 0);
                    echo '<label class="seo-category-vocab-option" data-search="' . esc_attr(remove_accents(mb_strtolower($term_label, 'UTF-8'))) . '" style="display:flex;align-items:center;gap:6px;padding:4px 5px;border-radius:4px;">';
                    echo '<input type="checkbox" name="' . esc_attr($field_prefix . '[' . $group . '][]') . '" value="' . esc_attr($term_id) . '" ' . checked(in_array($term_id, $group_selected, true), true, false) . '>';
                    echo '<span>' . esc_html($term_label) . '</span>';
                    if ($count > 0) {
                        echo '<small style="margin-left:auto;color:#2271b1;white-space:nowrap;">' . esc_html($count . ' prod.') . '</small>';
                    }
                    echo '</label>';
                }
            }
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
        echo '<p style="margin:7px 0 0;color:#646970;font-size:12px;">Solo se muestran términos activos del Vocabulary canónico. Las sugerencias indican cuántos productos asociados usan cada etiqueta; tú decides cuáles asignar a la categoría.</p>';
    }
}

/**
 * AJAX: imágenes sugeridas para una product_cat usando el sistema SEO Images.
 */
if (!function_exists('seo_category_editor_image_suggestions_ajax')) {
    function seo_category_editor_image_suggestions_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'No tienes permisos suficientes.'], 403);
        }
        check_ajax_referer('seo_category_image_suggestions', 'nonce');

        $term_id = isset($_POST['term_id']) ? absint($_POST['term_id']) : 0;
        $term = $term_id ? get_term($term_id, 'product_cat') : null;
        if (!$term_id || !$term || is_wp_error($term)) {
            wp_send_json_error(['message' => 'Categoría no válida.'], 400);
        }
        if (!function_exists('seo_images_assignment_find_candidates')) {
            wp_send_json_error(['message' => 'El módulo de sugerencias de imágenes no está disponible.'], 500);
        }

        $items = [];
        foreach ((array) seo_images_assignment_find_candidates('product_cat', $term_id, 12) as $candidate) {
            $url = esc_url_raw((string) ($candidate['url'] ?? ''));
            $key = sanitize_text_field((string) ($candidate['key'] ?? ''));
            if ($url === '' || $key === '') {
                continue;
            }
            $items[] = [
                'key'           => $key,
                'url'           => $url,
                'source_label'  => sanitize_text_field((string) ($candidate['source_label'] ?? 'Producto asociado')),
                'attachment_id' => absint($candidate['attachment_id'] ?? 0),
            ];
        }

        wp_send_json_success(['candidates' => $items]);
    }
    add_action('wp_ajax_seo_category_image_suggestions', 'seo_category_editor_image_suggestions_ajax');
}

/**
 * Guarda los campos editables de una categoría.
 *
 * Puede llamarse desde otros procesos del plugin.
 *
 * @param int   $term_id ID de product_cat.
 * @param array $data    name, excerpt, description, vocabulary_groups e imagen.
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

    $update_data = [];
    if (isset($data['name']) && trim((string) $data['name']) !== '') {
        $update_data['name'] = sanitize_text_field($data['name']);
    }

    if ($update_data) {
        $updated = wp_update_term($term_id, 'product_cat', $update_data);
        if (is_wp_error($updated)) {
            return $updated;
        }
    }

    try {
        if (array_key_exists('excerpt', $data)) {
            $result = seo_category_node_upsert_with_data_layer(
                $term_id,
                'excerpt',
                wp_kses_post($data['excerpt']),
                'category_admin'
            );
            if (is_wp_error($result)) {
                return $result;
            }
        }

        if (array_key_exists('description', $data)) {
            $result = seo_category_node_upsert_with_data_layer(
                $term_id,
                'description',
                wp_kses_post($data['description']),
                'category_admin'
            );
            if (is_wp_error($result)) {
                return $result;
            }
        }

        if (array_key_exists('vocabulary_groups', $data)) {
            $result = seo_category_vocabulary_replace(
                $term_id,
                is_array($data['vocabulary_groups']) ? $data['vocabulary_groups'] : [],
                'category_admin'
            );
            if (is_wp_error($result)) {
                return $result;
            }
        } else {
            // Compatibilidad con llamadas antiguas que todavía envíen texto GRUPO: Etiqueta.
            $vocabulary_key = array_key_exists('vocabulary', $data)
                ? 'vocabulary'
                : (array_key_exists('keywords', $data) ? 'keywords' : '');

            if ($vocabulary_key !== '') {
                $result = seo_category_vocabulary_replace_from_editor(
                    $term_id,
                    sanitize_textarea_field($data[$vocabulary_key]),
                    'category_admin'
                );
                if (is_wp_error($result)) {
                    return $result;
                }
            }
        }

        $candidate_key = array_key_exists('image_candidate_key', $data)
            ? sanitize_text_field((string) $data['image_candidate_key'])
            : '';

        if ($candidate_key !== '') {
            if (!function_exists('seo_images_assignment_find_candidate_by_key') || !function_exists('seo_images_assignment_apply_candidate')) {
                return new WP_Error('seo_category_image_module_missing', 'No está disponible el módulo de asignación de imágenes.');
            }
            $candidate = seo_images_assignment_find_candidate_by_key('product_cat', $term_id, $candidate_key);
            if (!$candidate) {
                return new WP_Error('seo_category_image_candidate_missing', 'La imagen sugerida ya no está disponible.');
            }
            $image_result = seo_images_assignment_apply_candidate('product_cat', $term_id, $candidate);
            if (is_wp_error($image_result)) {
                return $image_result;
            }
        } elseif (array_key_exists('image_id', $data)) {
            $image_id = absint($data['image_id']);
            $current_image_id = absint(get_term_meta($term_id, 'thumbnail_id', true));
            if ($image_id > 0 && $image_id !== $current_image_id) {
                if (function_exists('seo_images_assign_attachment_to_object')) {
                    $image_result = seo_images_assign_attachment_to_object('product_cat', $term_id, $image_id);
                    if (is_wp_error($image_result)) {
                        return $image_result;
                    }
                } else {
                    if (get_post_type($image_id) !== 'attachment') {
                        return new WP_Error('seo_category_invalid_image', 'La imagen seleccionada no es un attachment válido.');
                    }
                    update_term_meta($term_id, 'thumbnail_id', $image_id);
                }
            } elseif ($image_id === 0 && $current_image_id > 0) {
                delete_term_meta($term_id, 'thumbnail_id');
            }
        }
    } catch (Throwable $exception) {
        return new WP_Error('seo_category_database_error', $exception->getMessage());
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

wp_enqueue_media();

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

    echo '<a href="' . esc_url($url_reasignar_categorias) . '" class="nav-tab ' . ($active_tab === 'reasignar_categorias' ? 'nav-tab-active' : '') . '">Reasignación de Categorías</a>';
    
    echo '<a href="' . esc_url($url_category_anomaly) . '" class="nav-tab ' . ($active_tab === 'category-anomaly' ? 'nav-tab-active' : '') . '">Anomalías</a>';
    
    echo '<a href="' . esc_url($url_schema) . '" class="nav-tab ' . ($active_tab === 'schema' ? 'nav-tab-active' : '') . '">Schema</a>';

    echo '<a href="' . esc_url($url_informes) . '" class="nav-tab ' . ($active_tab === 'informes' ? 'nav-tab-active' : '') . '">Informes Google</a>';



echo '</h2>';
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

        $cat_names              = $_POST['cat_name'] ?? [];
        $cat_excerpts           = $_POST['cat_excerpt'] ?? [];
        $cat_descriptions       = $_POST['cat_description'] ?? [];
        $cat_vocabulary         = $_POST['cat_vocabulary'] ?? [];
        $cat_vocabulary_present = $_POST['cat_vocabulary_present'] ?? [];
        $cat_images             = $_POST['cat_image_id'] ?? [];
        $cat_image_candidates   = $_POST['cat_image_candidate'] ?? [];
        $save_only_term         = isset($_POST['save_category_id']) ? absint($_POST['save_category_id']) : 0;
        $save_errors            = [];
        $saved_count            = 0;

        foreach ($cat_names as $term_id => $name) {
            $term_id = absint($term_id);

            if (($single_category_mode && $term_id !== $requested_term_id) || ($save_only_term > 0 && $term_id !== $save_only_term)) {
                continue;
            }

            $payload = ['name' => $name];
            if (array_key_exists($term_id, $cat_excerpts)) {
                $payload['excerpt'] = $cat_excerpts[$term_id];
            }
            if (array_key_exists($term_id, $cat_descriptions)) {
                $payload['description'] = $cat_descriptions[$term_id];
            }
            if (array_key_exists($term_id, $cat_vocabulary_present)) {
                $payload['vocabulary_groups'] = isset($cat_vocabulary[$term_id]) && is_array($cat_vocabulary[$term_id])
                    ? $cat_vocabulary[$term_id]
                    : [];
            }
            if (array_key_exists($term_id, $cat_images)) {
                $payload['image_id'] = absint($cat_images[$term_id]);
            }
            if (!empty($cat_image_candidates[$term_id])) {
                $payload['image_candidate_key'] = sanitize_text_field(wp_unslash($cat_image_candidates[$term_id]));
            }

            $result = seo_save_category_editor_data($term_id, $payload);

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
    $category_ids_for_vocab = [];
    if (!is_wp_error($categories_list)) {
        $category_ids_for_vocab = array_values(array_filter(array_map(static function ($category) {
            return absint($category->term_id ?? 0);
        }, (array) $categories_list)));
    }
    $category_vocab_rows_cache = function_exists('seo_category_vocabulary_rows_map')
        ? seo_category_vocabulary_rows_map($category_ids_for_vocab)
        : [];
    $category_vocab_product_suggestions = seo_category_editor_product_vocabulary_suggestions($category_ids_for_vocab);
    

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
        if (
            empty($_POST['seo_create_category_nonce']) ||
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['seo_create_category_nonce'])),
                'seo_create_category'
            )
        ) {
            echo '<div class="notice notice-error"><p>La sesión ha caducado. Recarga la página antes de crear la categoría.</p></div>';
        } else {
            $nombre          = sanitize_text_field($_POST['new_cat_name'] ?? '');
            $excerpt         = wp_kses_post($_POST['new_cat_excerpt'] ?? '');
            $descripcion     = wp_kses_post($_POST['new_cat_description'] ?? '');
            $vocabulary      = isset($_POST['new_cat_vocabulary']) && is_array($_POST['new_cat_vocabulary'])
                ? wp_unslash($_POST['new_cat_vocabulary'])
                : [];
            $new_cat_image_id = isset($_POST['new_cat_image_id']) ? absint($_POST['new_cat_image_id']) : 0;

            if (empty($nombre)) {
                echo '<div class="notice notice-error"><p>El nombre es obligatorio.</p></div>';
            } else {
                $parent_id = isset($_POST['new_cat_parent']) ? absint($_POST['new_cat_parent']) : 0;
                $nueva_cat = wp_insert_term($nombre, 'product_cat', ['parent' => $parent_id]);

                if (is_wp_error($nueva_cat)) {
                    echo '<div class="notice notice-error"><p>Error: ' . esc_html($nueva_cat->get_error_message()) . '</p></div>';
                } else {
                    $term_id = absint($nueva_cat['term_id']);
                    $save_result = seo_save_category_editor_data($term_id, [
                        'excerpt'           => $excerpt,
                        'description'       => $descripcion,
                        'vocabulary_groups' => $vocabulary,
                        'image_id'          => $new_cat_image_id,
                    ]);

                    if (is_wp_error($save_result)) {
                        echo '<div class="notice notice-error"><p>La categoría se creó, pero sus datos SEO no se pudieron guardar mediante Data Layer: ' . esc_html($save_result->get_error_message()) . '</p></div>';
                    } else {
                        echo '<div class="notice notice-success"><p>Categoría creada correctamente con semántica canónica.</p></div>';
                    }
                }
            }
        }
    }
    ?>

    <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:8px; margin-bottom:30px;">
        <h2 style="margin-top:0;">➕ Crear nueva categoría</h2>

        <form method="post">
            <input type="hidden" name="action" value="create_seo_category">
            <?php wp_nonce_field('seo_create_category', 'seo_create_category_nonce'); ?>

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

            <div style="margin-bottom:15px;">
                <strong>Vocabulary canónico / etiquetas semánticas:</strong>
                <?php seo_category_editor_render_vocabulary_picker('new_cat_vocabulary', [], [], 'new-category'); ?>
            </div>

            <div style="margin-bottom:15px;border:1px solid #dcdcde;border-radius:7px;padding:12px;">
                <strong>Imagen de categoría:</strong>
                <input type="hidden" id="new_cat_image_id" name="new_cat_image_id" value="0">
                <div id="new_cat_image_preview" style="margin:8px 0;color:#646970;">Sin imagen seleccionada.</div>
                <button type="button" class="button seo-category-image-media" data-term="new">Elegir de Medios</button>
                <button type="button" class="button seo-category-image-remove" data-term="new">Quitar</button>
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
                // Semántica canónica de la categoría: Vocabulary compartido.
                $seo_category_vocab_selected = seo_category_editor_selected_vocabulary($category_vocab_rows_cache[$term_id] ?? []);
                $seo_category_vocab_suggestions = (array) ($category_vocab_product_suggestions[$term_id] ?? []);
                $seo_category_image_id = absint(get_term_meta($term_id, 'thumbnail_id', true));
                $seo_category_image_url = $seo_category_image_id ? wp_get_attachment_image_url($seo_category_image_id, 'medium') : '';
                
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

                        <button type="submit" name="save_category_id" value="<?php echo absint($term_id); ?>" style="padding:6px 14px; background:#2271b1; color:#fff; border:none; border-radius:4px; font-weight:bold; font-size:12px; cursor:pointer;">
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
                    
                    <?php /* VOCABULARY CANÓNICO DE LA CATEGORÍA */ ?>
                    <div style="margin-bottom:14px; font-size:12px;">
                        <strong>Vocabulary canónico / etiquetas semánticas:</strong>
                        <input type="hidden" name="cat_vocabulary_present[<?php echo absint($term_id); ?>]" value="1">
                        <?php
                        seo_category_editor_render_vocabulary_picker(
                            'cat_vocabulary[' . absint($term_id) . ']',
                            $seo_category_vocab_selected,
                            $seo_category_vocab_suggestions,
                            'category-' . absint($term_id)
                        );
                        ?>
                    </div>

                    <?php /* IMAGEN DE CATEGORÍA + SUGERENCIAS DE PRODUCTOS */ ?>
                    <div class="seo-category-image-editor" data-term="<?php echo absint($term_id); ?>" style="margin-bottom:14px;border:1px solid #dcdcde;border-radius:8px;padding:12px;background:#f9f9f9;">
                        <strong>Imagen de categoría:</strong>
                        <input type="hidden" id="cat_image_id_<?php echo absint($term_id); ?>" name="cat_image_id[<?php echo absint($term_id); ?>]" value="<?php echo absint($seo_category_image_id); ?>">
                        <input type="hidden" id="cat_image_candidate_<?php echo absint($term_id); ?>" name="cat_image_candidate[<?php echo absint($term_id); ?>]" value="">
                        <div id="cat_image_preview_<?php echo absint($term_id); ?>" style="margin:8px 0;min-height:60px;display:flex;align-items:center;gap:10px;">
                            <?php if ($seo_category_image_url): ?>
                                <img src="<?php echo esc_url($seo_category_image_url); ?>" alt="" style="width:90px;height:90px;object-fit:contain;background:#fff;border:1px solid #dcdcde;border-radius:6px;">
                                <span style="color:#50575e;">Imagen actual de la categoría.</span>
                            <?php else: ?>
                                <span style="color:#b32d2e;">Sin imagen asignada.</span>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                            <button type="button" class="button button-small seo-category-image-media" data-term="<?php echo absint($term_id); ?>">Elegir de Medios</button>
                            <button type="button" class="button button-small seo-category-image-load-suggestions" data-term="<?php echo absint($term_id); ?>">Sugerir desde productos</button>
                            <button type="button" class="button button-small seo-category-image-remove" data-term="<?php echo absint($term_id); ?>">Quitar imagen</button>
                        </div>
                        <div id="cat_image_suggestions_<?php echo absint($term_id); ?>" style="display:none;grid-template-columns:repeat(auto-fill,minmax(125px,1fr));gap:8px;margin-top:10px;"></div>
                        <p style="margin:7px 0 0;color:#646970;font-size:11px;">Las sugerencias proceden de productos asociados y del sistema SEO Images. No se asigna ninguna hasta que la seleccionas y guardas la categoría.</p>
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
var seoCategoryImageSuggestionsNonce = <?php echo wp_json_encode(wp_create_nonce('seo_category_image_suggestions')); ?>;

(function($) {
    $(document).on('input', '.seo-category-vocab-filter', function() {
        var target = $('#' + $(this).data('target'));
        var needle = ($(this).val() || '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        target.find('.seo-category-vocab-option').each(function() {
            var haystack = ($(this).data('search') || '').toString().toLowerCase();
            $(this).toggle(!needle || haystack.indexOf(needle) !== -1);
        });
    });

    $(document).on('click', '.seo-category-vocab-suggestion', function() {
        var target = $('#' + $(this).data('target'));
        var id = String($(this).data('vocabulary-id'));
        var checkbox = target.find('input[type="checkbox"][value="' + id.replace(/"/g, '\\"') + '"]');
        if (checkbox.length) {
            checkbox.prop('checked', true);
            checkbox.closest('label').css('background', '#e7f1ff');
        }
    });

    function setImagePreview(termId, url, text) {
        var preview = termId === 'new' ? $('#new_cat_image_preview') : $('#cat_image_preview_' + termId);
        preview.empty();
        if (url) {
            $('<img>', {src:url, alt:''}).css({width:'90px',height:'90px',objectFit:'contain',background:'#fff',border:'1px solid #dcdcde',borderRadius:'6px'}).appendTo(preview);
        }
        $('<span>').text(text || (url ? 'Imagen seleccionada.' : 'Sin imagen asignada.')).appendTo(preview);
    }

    $(document).on('click', '.seo-category-image-media', function() {
        var termId = String($(this).data('term'));
        var frame = wp.media({title:'Elegir imagen de categoría', button:{text:'Usar esta imagen'}, multiple:false, library:{type:'image'}});
        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            if (termId === 'new') {
                $('#new_cat_image_id').val(attachment.id || 0);
            } else {
                $('#cat_image_id_' + termId).val(attachment.id || 0);
                $('#cat_image_candidate_' + termId).val('');
            }
            setImagePreview(termId, attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url, 'Seleccionada desde Medios.');
        });
        frame.open();
    });

    $(document).on('click', '.seo-category-image-remove', function() {
        var termId = String($(this).data('term'));
        if (termId === 'new') {
            $('#new_cat_image_id').val(0);
        } else {
            $('#cat_image_id_' + termId).val(0);
            $('#cat_image_candidate_' + termId).val('');
        }
        setImagePreview(termId, '', 'Sin imagen asignada.');
    });

    $(document).on('click', '.seo-category-image-load-suggestions', function() {
        var button = $(this);
        var termId = parseInt(button.data('term'), 10) || 0;
        var box = $('#cat_image_suggestions_' + termId);
        if (!termId) return;
        button.prop('disabled', true).text('Cargando…');
        $.post(ajaxurl, {
            action: 'seo_category_image_suggestions',
            term_id: termId,
            nonce: seoCategoryImageSuggestionsNonce
        }).done(function(response) {
            box.empty().css('display', 'grid');
            if (!response || !response.success || !response.data || !response.data.candidates || !response.data.candidates.length) {
                $('<div>').css({gridColumn:'1/-1',color:'#646970'}).text(response && response.data && response.data.message ? response.data.message : 'No hay imágenes sugeridas para esta categoría.').appendTo(box);
                return;
            }
            response.data.candidates.forEach(function(item) {
                var card = $('<button>', {type:'button', class:'seo-category-image-suggestion'}).attr({'data-term':termId,'data-key':item.key,'data-url':item.url}).css({padding:'6px',border:'1px solid #dcdcde',borderRadius:'6px',background:'#fff',cursor:'pointer',textAlign:'left'});
                $('<img>', {src:item.url, alt:''}).css({width:'100%',height:'90px',objectFit:'contain',display:'block',marginBottom:'5px'}).appendTo(card);
                $('<span>').css({display:'block',fontSize:'10px',lineHeight:'1.25',color:'#50575e'}).text(item.source_label || 'Producto asociado').appendTo(card);
                card.appendTo(box);
            });
        }).fail(function() {
            box.empty().css('display', 'grid');
            $('<div>').css({gridColumn:'1/-1',color:'#b32d2e'}).text('No se pudieron cargar las sugerencias.').appendTo(box);
        }).always(function() {
            button.prop('disabled', false).text('Sugerir desde productos');
        });
    });

    $(document).on('click', '.seo-category-image-suggestion', function() {
        var termId = String($(this).data('term'));
        var key = String($(this).data('key') || '');
        var url = String($(this).data('url') || '');
        $('#cat_image_candidate_' + termId).val(key);
        $('#cat_image_id_' + termId).val(0);
        $('.seo-category-image-suggestion[data-term="' + termId + '"]').css({outline:'none'});
        $(this).css({outline:'3px solid #2271b1'});
        setImagePreview(termId, url, 'Sugerencia seleccionada; se asignará al guardar.');
    });
})(jQuery);


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
    
        try {
            seo_category_redirect_upsert_with_data_layer(
                $origin_url,
                $target_url,
                $term_id,
                'category_admin_slug'
            );
        } catch (Throwable $exception) {
            wp_die(
                'El slug se modificó correctamente, pero no se pudo guardar la redirección mediante Data Layer.<br><br>' .
                '<strong>Origen:</strong> ' . esc_html($origin_url) . '<br>' .
                '<strong>Destino:</strong> ' . esc_html($target_url) . '<br><br>' .
                '<strong>Error:</strong> ' . esc_html($exception->getMessage())
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