<?php
/**
 * SEO Images - selección y asignación de imágenes representativas.
 *
 * Una categoría/hub/landing puede reutilizar un attachment local relacionado o
 * materializar una única imagen externa de un producto de su rama.
 *
 * Version: 2026-08-26
 * Build: 005-posts
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('seo_images_assignment_scope_labels')) {
    function seo_images_assignment_scope_labels() {
        return array(
            'product_cat'   => 'Categorías',
            'landing'       => 'Landing pages',
            'hub_secondary' => 'Hubs secundarios',
            'hub_primary'   => 'Hubs primarios',
            'cluster'       => 'Clusters',
            'post'          => 'Posts',
        );
    }
}

if (!function_exists('seo_images_assignment_object_has_image')) {
    /**
     * Indica si el objeto YA TIENE una imagen asociada en WordPress.
     *
     * Importante: esta comprobación se usa para decidir qué objetos aparecen
     * como "pendientes" en la pestaña Asignación. Aquí no debemos exigir que
     * el attachment pase una validación adicional: si existe thumbnail_id /
     * _thumbnail_id, el objeto ya no está pendiente de asignación.
     *
     * La validez física del attachment (borrado, MIME incorrecto, etc.) se
     * controla en Anomalías y al reutilizar candidatos, no en este listado.
     */
    function seo_images_assignment_object_has_image($scope_type, $object_id) {
        $scope_type = sanitize_key($scope_type);
        $object_id  = absint($object_id);

        if ($scope_type === 'product_cat') {
            return absint(get_term_meta($object_id, 'thumbnail_id', true)) > 0;
        }

        return absint(get_post_thumbnail_id($object_id)) > 0;
    }
}

/**
 * Devuelve objetos SEO de un tipo. Para páginas usa seo_nodes cuando existe;
 * así no dependemos de que el objeto tenga ya relaciones descendentes.
 */
if (!function_exists('seo_images_assignment_get_objects')) {
    function seo_images_assignment_get_objects($scope_type, $only_missing = true, $limit = 100, $offset = 0) {
        global $wpdb;

        $scope_type   = sanitize_key($scope_type);
        $limit        = max(1, min(500, absint($limit)));
        $offset       = max(0, absint($offset));
        $only_missing = (bool) $only_missing;
        $items        = array();

        if ($scope_type === 'product_cat') {
            $terms = get_terms(array(
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            ));

            if (is_wp_error($terms)) {
                return array();
            }

            foreach ((array) $terms as $term) {
                if ($only_missing && seo_images_assignment_object_has_image('product_cat', $term->term_id)) {
                    continue;
                }
                $items[] = array(
                    'id'    => absint($term->term_id),
                    'title' => (string) $term->name,
                    'url'   => get_term_link($term),
                );
            }

            return array_slice($items, $offset, $limit);
        }

        /*
         * Posts editoriales sin imagen destacada. Se incluyen publicados y
         * programados, igual que el flujo editorial del plugin.
         */
        if ($scope_type === 'post') {
            $post_ids = get_posts(array(
                'post_type'              => 'post',
                'post_status'            => array('publish', 'future'),
                'posts_per_page'         => -1,
                'fields'                 => 'ids',
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'no_found_rows'          => true,
                'ignore_sticky_posts'    => true,
                'update_post_meta_cache' => true,
                'update_post_term_cache' => false,
            ));

            foreach ((array) $post_ids as $post_id) {
                $post_id = absint($post_id);
                if (!$post_id || get_post_type($post_id) !== 'post') {
                    continue;
                }
                if ($only_missing && seo_images_assignment_object_has_image('post', $post_id)) {
                    continue;
                }

                $items[] = array(
                    'id'    => $post_id,
                    'title' => get_the_title($post_id),
                    'url'   => get_permalink($post_id),
                );
            }

            return array_slice($items, $offset, $limit);
        }

        if (!in_array($scope_type, array('landing', 'hub_secondary', 'hub_primary', 'cluster'), true)) {
            return array();
        }

        $ids = array();
        $nodes_table = seo_images_table_nodes();

        if (seo_images_table_exists($nodes_table)) {
            $ids = (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT object_id
                     FROM {$nodes_table}
                     WHERE object_type = 'page'
                       AND seo_role = %s
                       AND status = 1
                     ORDER BY object_id ASC",
                    $scope_type
                )
            );
        } else {
            $relations_table = seo_images_table_relations();
            if (seo_images_table_exists($relations_table)) {
                $ids = (array) $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT DISTINCT source_id
                         FROM {$relations_table}
                         WHERE source_type = %s
                         ORDER BY source_id ASC",
                        $scope_type
                    )
                );
            }
        }

        foreach ($ids as $page_id) {
            $page_id = absint($page_id);
            if (!$page_id || get_post_type($page_id) !== 'page') {
                continue;
            }
            if ($only_missing && seo_images_assignment_object_has_image($scope_type, $page_id)) {
                continue;
            }

            $items[] = array(
                'id'    => $page_id,
                'title' => get_the_title($page_id),
                'url'   => get_permalink($page_id),
            );
        }

        return array_slice($items, $offset, $limit);
    }
}

/**
 * Categorías estructurales descendientes de un objeto.
 */
if (!function_exists('seo_images_assignment_get_category_ids')) {
    function seo_images_assignment_get_category_ids($scope_type, $object_id) {
        global $wpdb;

        $scope_type = sanitize_key($scope_type);
        $object_id  = absint($object_id);
        $table      = seo_images_table_relations();

        if ($object_id < 1) {
            return array();
        }

        if ($scope_type === 'product_cat') {
            return array($object_id);
        }

        if (!seo_images_table_exists($table)) {
            return array();
        }

        if ($scope_type === 'post') {
            $sql = $wpdb->prepare(
                "SELECT DISTINCT target_id
                 FROM {$table}
                 WHERE source_type = 'post'
                   AND source_id = %d
                   AND target_type = 'product_cat'
                   AND relation_type = 'post_to_category'
                 ORDER BY target_id ASC",
                $object_id
            );

            return array_values(array_unique(array_filter(array_map('absint', (array) $wpdb->get_col($sql)))));
        }

        if ($scope_type === 'landing') {
            $sql = $wpdb->prepare(
                "SELECT DISTINCT target_id
                 FROM {$table}
                 WHERE source_type = 'landing'
                   AND source_id = %d
                   AND target_type = 'product_cat'
                   AND relation_type = 'landing_to_category'",
                $object_id
            );
        } elseif ($scope_type === 'hub_secondary') {
            $sql = $wpdb->prepare(
                "SELECT DISTINCT target_id
                 FROM {$table}
                 WHERE source_type = 'hub_secondary'
                   AND source_id = %d
                   AND target_type = 'product_cat'
                   AND relation_type = 'hub_secondary_to_category'",
                $object_id
            );
        } elseif ($scope_type === 'hub_primary') {
            $sql = $wpdb->prepare(
                "SELECT DISTINCT c.target_id
                 FROM {$table} ps
                 INNER JOIN {$table} c
                    ON c.source_type = 'hub_secondary'
                   AND c.source_id = ps.target_id
                   AND c.target_type = 'product_cat'
                   AND c.relation_type = 'hub_secondary_to_category'
                 WHERE ps.source_type = 'hub_primary'
                   AND ps.source_id = %d
                   AND ps.target_type = 'hub_secondary'
                   AND ps.relation_type = 'hub_primary_to_hub_secondary'",
                $object_id
            );
        } elseif ($scope_type === 'cluster') {
            $sql = $wpdb->prepare(
                "SELECT DISTINCT c.target_id
                 FROM {$table} cp
                 INNER JOIN {$table} ps
                    ON ps.source_type = 'hub_primary'
                   AND ps.source_id = cp.target_id
                   AND ps.target_type = 'hub_secondary'
                   AND ps.relation_type = 'hub_primary_to_hub_secondary'
                 INNER JOIN {$table} c
                    ON c.source_type = 'hub_secondary'
                   AND c.source_id = ps.target_id
                   AND c.target_type = 'product_cat'
                   AND c.relation_type = 'hub_secondary_to_category'
                 WHERE cp.source_type = 'cluster'
                   AND cp.source_id = %d
                   AND cp.target_type = 'hub_primary'
                   AND cp.relation_type = 'cluster_to_primary'",
                $object_id
            );
        } else {
            return array();
        }

        return array_values(array_unique(array_filter(array_map('absint', (array) $wpdb->get_col($sql)))));
    }
}

/**
 * Hijos inmediatos que ya pueden tener una imagen representativa reutilizable.
 */
if (!function_exists('seo_images_assignment_get_child_objects')) {
    function seo_images_assignment_get_child_objects($scope_type, $object_id) {
        global $wpdb;

        $scope_type = sanitize_key($scope_type);
        $object_id  = absint($object_id);
        $table      = seo_images_table_relations();

        if ($object_id < 1 || !seo_images_table_exists($table)) {
            return array();
        }

        if ($scope_type === 'landing' || $scope_type === 'hub_secondary' || $scope_type === 'post') {
            $category_ids = seo_images_assignment_get_category_ids($scope_type, $object_id);
            $out = array();
            foreach ($category_ids as $term_id) {
                $out[] = array('type' => 'product_cat', 'id' => $term_id);
            }
            return $out;
        }

        if ($scope_type === 'hub_primary') {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT target_id FROM {$table}
                 WHERE source_type = 'hub_primary' AND source_id = %d
                   AND target_type = 'hub_secondary'
                   AND relation_type = 'hub_primary_to_hub_secondary'",
                $object_id
            ));
            return array_map(static function($id) {
                return array('type' => 'hub_secondary', 'id' => absint($id));
            }, (array) $ids);
        }

        if ($scope_type === 'cluster') {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT target_id FROM {$table}
                 WHERE source_type = 'cluster' AND source_id = %d
                   AND target_type = 'hub_primary'
                   AND relation_type = 'cluster_to_primary'",
                $object_id
            ));
            return array_map(static function($id) {
                return array('type' => 'hub_primary', 'id' => absint($id));
            }, (array) $ids);
        }

        return array();
    }
}

if (!function_exists('seo_images_assignment_get_product_ids')) {
    function seo_images_assignment_get_product_ids($category_ids, $limit = 80, $include_children = false) {
        $category_ids     = array_values(array_unique(array_filter(array_map('absint', (array) $category_ids))));
        $limit            = max(1, min(300, absint($limit)));
        $include_children = (bool) $include_children;

        if (empty($category_ids)) {
            return array();
        }

        return array_map('absint', get_posts(array(
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => $limit,
            'fields'                 => 'ids',
            'orderby'                => 'date',
            'order'                  => 'DESC',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'tax_query'              => array(
                array(
                    'taxonomy'         => 'product_cat',
                    'field'            => 'term_id',
                    'terms'            => $category_ids,
                    'operator'         => 'IN',
                    'include_children' => $include_children,
                ),
            ),
        )));
    }
}

/**
 * Candidatos relacionados ordenados de lo más estructural a lo más específico.
 */
if (!function_exists('seo_images_assignment_find_candidates')) {
    function seo_images_assignment_find_candidates($scope_type, $object_id, $limit = 8) {
        $scope_type = sanitize_key($scope_type);
        $object_id  = absint($object_id);
        $limit      = max(1, min(24, absint($limit)));
        $candidates = array();

        /*
         * Posts: la fuente canónica es la imagen de la product_cat relacionada
         * mediante post_to_category. No se usan productos aleatorios como fallback.
         */
        if ($scope_type === 'post') {
            foreach (seo_images_assignment_get_category_ids('post', $object_id) as $term_id) {
                $attachment_id = absint(get_term_meta($term_id, 'thumbnail_id', true));
                if (!$attachment_id || !seo_images_is_valid_attachment($attachment_id)) {
                    continue;
                }

                seo_images_candidate_add($candidates, array(
                    'attachment_id' => $attachment_id,
                    'url'           => wp_get_attachment_image_url($attachment_id, 'large') ?: wp_get_attachment_url($attachment_id),
                    'provider'      => '',
                    'product_id'    => 0,
                    'source'        => 'category',
                    'source_label'  => 'Categoría asociada: ' . (string) get_term_field('name', $term_id, 'product_cat'),
                ));

                if (count($candidates) >= $limit) {
                    break;
                }
            }

            return array_values($candidates);
        }

        // 1) Reutilizar imágenes ya elegidas en hijos inmediatos.
        foreach (seo_images_assignment_get_child_objects($scope_type, $object_id) as $child) {
            $child_type = sanitize_key($child['type'] ?? '');
            $child_id   = absint($child['id'] ?? 0);
            if (!$child_id) {
                continue;
            }

            if ($child_type === 'product_cat') {
                $attachment_id = absint(get_term_meta($child_id, 'thumbnail_id', true));
                $label = 'Categoría: ' . (string) get_term_field('name', $child_id, 'product_cat');
            } else {
                $attachment_id = absint(get_post_thumbnail_id($child_id));
                $label = get_the_title($child_id);
            }

            if ($attachment_id && seo_images_is_valid_attachment($attachment_id)) {
                seo_images_candidate_add($candidates, array(
                    'attachment_id' => $attachment_id,
                    'url'           => wp_get_attachment_image_url($attachment_id, 'large') ?: wp_get_attachment_url($attachment_id),
                    'provider'      => '',
                    'product_id'    => 0,
                    'source'        => 'child_object',
                    'source_label'  => $label,
                ));
            }

            if (count($candidates) >= $limit) {
                return array_values($candidates);
            }
        }

        $category_ids = seo_images_assignment_get_category_ids($scope_type, $object_id);

        // 2) Imágenes de categorías de la rama.
        foreach ($category_ids as $term_id) {
            $attachment_id = absint(get_term_meta($term_id, 'thumbnail_id', true));
            if ($attachment_id && seo_images_is_valid_attachment($attachment_id)) {
                seo_images_candidate_add($candidates, array(
                    'attachment_id' => $attachment_id,
                    'url'           => wp_get_attachment_image_url($attachment_id, 'large') ?: wp_get_attachment_url($attachment_id),
                    'provider'      => '',
                    'product_id'    => 0,
                    'source'        => 'category',
                    'source_label'  => 'Categoría: ' . (string) get_term_field('name', $term_id, 'product_cat'),
                ));
            }

            if (count($candidates) >= $limit) {
                return array_values($candidates);
            }
        }

        // 3) Imagenes de productos dentro de las categorias de la rama.
        //
        // Cada producto puede aportar A LA VEZ attachments de Media e imagenes
        // externas de seo_supplier_images. No se consideran fuentes excluyentes.
        // Para product_cat incluimos descendientes porque WooCommerce puede mostrar
        // productos heredados de subcategorias aunque no esten ligados directamente.
        $product_ids = seo_images_assignment_get_product_ids(
            $category_ids,
            150,
            $scope_type === 'product_cat'
        );

        $product_candidate_sets = array();
        foreach ($product_ids as $product_id) {
            $set = seo_images_get_product_candidates($product_id, 8);
            if (empty($set)) {
                continue;
            }

            foreach ($set as &$candidate) {
                $candidate['source_label'] = get_the_title($product_id) . ' · ' .
                    (!empty($candidate['attachment_id']) ? 'Media' : ($candidate['provider'] ?: 'Externa'));
            }
            unset($candidate);

            $product_candidate_sets[] = array_values($set);
        }

        // Reparto round-robin: primero una imagen por producto y despues sus
        // alternativas. Asi un producto con muchas fotos no ocupa todo el listado.
        for ($depth = 0; $depth < 8; $depth++) {
            foreach ($product_candidate_sets as $set) {
                if (!isset($set[$depth])) {
                    continue;
                }

                seo_images_candidate_add($candidates, $set[$depth]);
                if (count($candidates) >= $limit) {
                    return array_values($candidates);
                }
            }
        }

        return array_values($candidates);
    }
}

if (!function_exists('seo_images_assignment_find_candidate_by_key')) {
    function seo_images_assignment_find_candidate_by_key($scope_type, $object_id, $candidate_key) {
        $candidate_key = sanitize_text_field($candidate_key);
        foreach (seo_images_assignment_find_candidates($scope_type, $object_id, 24) as $candidate) {
            if (hash_equals((string) ($candidate['key'] ?? ''), $candidate_key)) {
                return $candidate;
            }
        }
        return null;
    }
}

/**
 * Asigna un candidato relacionado. Las imágenes externas se descargan solo en
 * este momento porque WordPress necesita un attachment para thumbnail_id.
 */
if (!function_exists('seo_images_assignment_apply_candidate')) {
    function seo_images_assignment_apply_candidate($scope_type, $object_id, $candidate) {
        $attachment_id = seo_images_materialize_candidate($candidate, $object_id, $scope_type);
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        return seo_images_assign_attachment_to_object($scope_type, $object_id, $attachment_id);
    }
}
