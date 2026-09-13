<?php
/**
 * Eliminación segura de attachments detectados como duplicados de una fuente
 * externa. La eliminación real siempre pasa por WordPress.
 *
 * @package SEOSystem
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_images_cleanup_safe_decisions')) {
    function seo_images_cleanup_safe_decisions() {
        return array(
            'BORRAR_URL_ORIGEN_EXACTA',
            'BORRAR_RUTA_EXACTA',
            'BORRAR_MISMO_PRODUCTO',
        );
    }
}

if (!function_exists('seo_images_cleanup_pending_delete_count')) {
    function seo_images_cleanup_pending_delete_count() {
        global $wpdb;

        seo_images_cleanup_install_tables();
        $candidates = seo_images_cleanup_table_candidates();
        $log        = seo_images_cleanup_table_log();

        return max(0, (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$candidates} c
             WHERE c.safe_to_delete = 1
               AND NOT EXISTS (
                   SELECT 1
                   FROM {$log} l
                   WHERE l.attachment_id = c.attachment_id
                     AND l.processed_at >= c.analyzed_at
               )"
        ));
    }
}

if (!function_exists('seo_images_cleanup_delete_stats')) {
    function seo_images_cleanup_delete_stats() {
        global $wpdb;

        seo_images_cleanup_install_tables();
        $table = seo_images_cleanup_table_log();

        $stats = array(
            'deleted'         => 0,
            'already_missing' => 0,
            'error'           => 0,
            'processed'       => 0,
            'pending'         => seo_images_cleanup_pending_delete_count(),
        );

        $candidates = seo_images_cleanup_table_candidates();
        $rows = (array) $wpdb->get_results(
            "SELECT l.status, COUNT(*) AS total
             FROM {$table} l
             INNER JOIN {$candidates} c
                 ON c.attachment_id = l.attachment_id
                AND l.processed_at >= c.analyzed_at
             GROUP BY l.status",
            ARRAY_A
        );

        foreach ($rows as $row) {
            $status = sanitize_key($row['status']);
            $count  = absint($row['total']);
            if (array_key_exists($status, $stats)) {
                $stats[$status] = $count;
            }
            $stats['processed'] += $count;
        }

        return $stats;
    }
}

if (!function_exists('seo_images_cleanup_pending_candidates')) {
    function seo_images_cleanup_pending_candidates($limit = 10) {
        global $wpdb;

        $limit = max(1, min(25, absint($limit)));
        $candidates = seo_images_cleanup_table_candidates();
        $log        = seo_images_cleanup_table_log();

        return (array) $wpdb->get_results(
            "SELECT c.*
             FROM {$candidates} c
             WHERE c.safe_to_delete = 1
               AND NOT EXISTS (
                   SELECT 1
                   FROM {$log} l
                   WHERE l.attachment_id = c.attachment_id
                     AND l.processed_at >= c.analyzed_at
               )
             ORDER BY
                 CASE c.decision
                   WHEN 'BORRAR_URL_ORIGEN_EXACTA' THEN 1
                   WHEN 'BORRAR_RUTA_EXACTA' THEN 2
                   WHEN 'BORRAR_MISMO_PRODUCTO' THEN 3
                   ELSE 9
                 END,
                 c.attachment_id ASC
             LIMIT {$limit}",
            ARRAY_A
        );
    }
}

if (!function_exists('seo_images_cleanup_clean_wc_gallery_refs')) {
    /**
     * Limpia referencias WooCommerce a attachments ya eliminados.
     *
     * Se hace por lote para no recorrer todas las galerías una vez por imagen.
     * Las escrituras se realizan con WooCommerce o update_post_meta(), nunca
     * mediante UPDATE SQL directo.
     *
     * @param array $attachment_ids IDs eliminados.
     * @return array<int,int> Número de galerías limpiadas por attachment.
     */
    function seo_images_cleanup_clean_wc_gallery_refs(array $attachment_ids) {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('absint', $attachment_ids))));
        $counts = array_fill_keys($ids, 0);

        if (empty($ids)) {
            return $counts;
        }

        $rows = (array) $wpdb->get_results(
            "SELECT post_id, meta_value
             FROM {$wpdb->postmeta}
             WHERE meta_key = '_product_image_gallery'
               AND meta_value <> ''",
            ARRAY_A
        );

        $delete_lookup = array_fill_keys($ids, true);

        foreach ($rows as $row) {
            $product_id = absint($row['post_id']);
            $gallery_ids = array_values(array_unique(array_filter(array_map('absint', explode(',', (string) $row['meta_value'])))));
            if (empty($gallery_ids)) {
                continue;
            }

            $removed = array();
            $new_ids = array();

            foreach ($gallery_ids as $gallery_id) {
                if (isset($delete_lookup[$gallery_id])) {
                    $removed[$gallery_id] = true;
                    continue;
                }
                $new_ids[] = $gallery_id;
            }

            if (empty($removed)) {
                continue;
            }

            $saved = false;
            if (function_exists('wc_get_product')) {
                $product = wc_get_product($product_id);
                if ($product) {
                    $product->set_gallery_image_ids($new_ids);
                    $product->save();
                    $saved = true;
                }
            }

            if (!$saved) {
                update_post_meta($product_id, '_product_image_gallery', implode(',', $new_ids));
                clean_post_cache($product_id);
            }

            foreach (array_keys($removed) as $removed_id) {
                $counts[$removed_id] = isset($counts[$removed_id]) ? $counts[$removed_id] + 1 : 1;
            }
        }

        return $counts;
    }
}

if (!function_exists('seo_images_cleanup_clean_term_refs')) {
    /**
     * Limpia thumbnails de términos que apunten a attachments eliminados.
     *
     * @param array $attachment_ids IDs.
     * @return array<int,int>
     */
    function seo_images_cleanup_clean_term_refs(array $attachment_ids) {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('absint', $attachment_ids))));
        $counts = array_fill_keys($ids, 0);

        foreach ($ids as $attachment_id) {
            $term_ids = (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT term_id
                     FROM {$wpdb->termmeta}
                     WHERE meta_key = 'thumbnail_id'
                       AND meta_value = %s",
                    (string) $attachment_id
                )
            );

            foreach ($term_ids as $term_id) {
                if (delete_term_meta(absint($term_id), 'thumbnail_id', $attachment_id)) {
                    $counts[$attachment_id]++;
                }
            }
        }

        return $counts;
    }
}

if (!function_exists('seo_images_cleanup_clean_seo_indexes')) {
    /**
     * Limpia únicamente los índices propios del plugin después de que WordPress
     * haya eliminado el attachment.
     *
     * @param array $attachment_ids IDs.
     * @return array<int,int>
     */
    function seo_images_cleanup_clean_seo_indexes(array $attachment_ids) {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('absint', $attachment_ids))));
        $counts = array_fill_keys($ids, 0);

        if (empty($ids)) {
            return $counts;
        }

        $images_table = function_exists('seo_images_table_images') ? seo_images_table_images() : '';
        $usages_table = function_exists('seo_images_table_usages') ? seo_images_table_usages() : '';
        $has_images_table = $images_table !== '' && function_exists('seo_images_table_exists') && seo_images_table_exists($images_table);
        $has_usages_table = $usages_table !== '' && function_exists('seo_images_table_exists') && seo_images_table_exists($usages_table);

        foreach ($ids as $attachment_id) {
            $total = 0;

            if ($has_usages_table) {
                $deleted = $wpdb->delete($usages_table, array('attachment_id' => $attachment_id), array('%d'));
                if ($deleted !== false) {
                    $total += (int) $deleted;
                }
            }

            if ($has_images_table) {
                $deleted = $wpdb->delete($images_table, array('attachment_id' => $attachment_id), array('%d'));
                if ($deleted !== false) {
                    $total += (int) $deleted;
                }
            }

            $counts[$attachment_id] = $total;
        }

        return $counts;
    }
}

if (!function_exists('seo_images_cleanup_write_log')) {
    function seo_images_cleanup_write_log(array $row) {
        global $wpdb;

        $table = seo_images_cleanup_table_log();

        return $wpdb->insert(
            $table,
            array(
                'attachment_id'        => absint($row['attachment_id'] ?? 0),
                'decision'             => sanitize_text_field($row['decision'] ?? ''),
                'media_filename'       => sanitize_file_name($row['media_filename'] ?? ''),
                'status'               => sanitize_key($row['status'] ?? 'error'),
                'gallery_refs_cleaned' => absint($row['gallery_refs_cleaned'] ?? 0),
                'term_refs_cleaned'    => absint($row['term_refs_cleaned'] ?? 0),
                'seo_rows_cleaned'     => absint($row['seo_rows_cleaned'] ?? 0),
                'message'              => sanitize_textarea_field($row['message'] ?? ''),
                'processed_at'         => current_time('mysql'),
            ),
            array('%d','%s','%s','%s','%d','%d','%d','%s','%s')
        );
    }
}

if (!function_exists('seo_images_cleanup_delete_batch')) {
    /**
     * Elimina el siguiente lote de candidatos seguros.
     *
     * @param int $limit Lote.
     * @return array
     */
    function seo_images_cleanup_delete_batch($limit = 10) {
        $state = seo_images_cleanup_get_state();
        if (($state['status'] ?? '') !== 'complete') {
            return array(
                'items' => array(),
                'stats' => seo_images_cleanup_delete_stats(),
                'error' => 'La auditoría debe estar completada antes de borrar.',
            );
        }

        $rows = seo_images_cleanup_pending_candidates($limit);
        if (empty($rows)) {
            return array(
                'items' => array(),
                'stats' => seo_images_cleanup_delete_stats(),
                'error' => '',
            );
        }

        $safe_decisions = seo_images_cleanup_safe_decisions();
        $results = array();
        $cleanable_ids = array();
        $result_by_id = array();

        foreach ($rows as $row) {
            $attachment_id = absint($row['attachment_id']);
            $decision      = (string) $row['decision'];
            $filename      = (string) $row['media_filename'];

            $result = array(
                'attachment_id' => $attachment_id,
                'decision'      => $decision,
                'filename'      => $filename,
                'status'        => 'error',
                'message'       => '',
            );

            if (empty($row['safe_to_delete']) || !in_array($decision, $safe_decisions, true)) {
                $result['message'] = 'La clasificación ya no está permitida para borrado automático.';
                seo_images_cleanup_write_log(array_merge($result, array('status' => 'error')));
                $results[] = $result;
                continue;
            }

            $post = get_post($attachment_id);

            if (!$post) {
                $result['status']  = 'already_missing';
                $result['message'] = 'El attachment ya no existía en WordPress.';
                $cleanable_ids[] = $attachment_id;
                $result_by_id[$attachment_id] = count($results);
                $results[] = $result;
                continue;
            }

            if ($post->post_type !== 'attachment' || strpos((string) get_post_mime_type($attachment_id), 'image/') !== 0) {
                $result['message'] = 'El ID existe pero ya no es un attachment de imagen.';
                seo_images_cleanup_write_log(array_merge($result, array('status' => 'error')));
                $results[] = $result;
                continue;
            }

            if (!current_user_can('delete_post', $attachment_id)) {
                $result['message'] = 'El usuario actual no puede eliminar este attachment.';
                seo_images_cleanup_write_log(array_merge($result, array('status' => 'error')));
                $results[] = $result;
                continue;
            }

            // Punto crítico: WordPress elimina el post attachment, sus metadatos
            // y los ficheros/tamaños que tiene registrados.
            $deleted = wp_delete_attachment($attachment_id, true);

            if (!$deleted) {
                $result['message'] = 'wp_delete_attachment() devolvió false.';
                seo_images_cleanup_write_log(array_merge($result, array('status' => 'error')));
                $results[] = $result;
                continue;
            }

            $result['status']  = 'deleted';
            $result['message'] = 'Attachment eliminado mediante wp_delete_attachment().';
            $cleanable_ids[] = $attachment_id;
            $result_by_id[$attachment_id] = count($results);
            $results[] = $result;
        }

        $gallery_counts = seo_images_cleanup_clean_wc_gallery_refs($cleanable_ids);
        $term_counts    = seo_images_cleanup_clean_term_refs($cleanable_ids);
        $seo_counts     = seo_images_cleanup_clean_seo_indexes($cleanable_ids);

        foreach ($cleanable_ids as $attachment_id) {
            if (!isset($result_by_id[$attachment_id])) {
                continue;
            }

            $index = $result_by_id[$attachment_id];
            $results[$index]['gallery_refs_cleaned'] = absint($gallery_counts[$attachment_id] ?? 0);
            $results[$index]['term_refs_cleaned']    = absint($term_counts[$attachment_id] ?? 0);
            $results[$index]['seo_rows_cleaned']     = absint($seo_counts[$attachment_id] ?? 0);

            seo_images_cleanup_write_log($results[$index]);
        }

        return array(
            'items' => $results,
            'stats' => seo_images_cleanup_delete_stats(),
            'error' => '',
        );
    }
}

if (!function_exists('seo_images_cleanup_retry_errors')) {
    /**
     * Borra únicamente los logs de error posteriores a la auditoría vigente,
     * permitiendo reintentar esos candidatos sin alterar los logs exitosos.
     *
     * @return int
     */
    function seo_images_cleanup_retry_errors() {
        global $wpdb;

        $candidates = seo_images_cleanup_table_candidates();
        $log        = seo_images_cleanup_table_log();

        $deleted = $wpdb->query(
            "DELETE l
             FROM {$log} l
             INNER JOIN {$candidates} c
                 ON c.attachment_id = l.attachment_id
             WHERE l.status = 'error'
               AND l.processed_at >= c.analyzed_at"
        );

        return $deleted === false ? 0 : (int) $deleted;
    }
}
