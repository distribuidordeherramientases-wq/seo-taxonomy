<?php
/**
 * SEO Images - panel de inventario, anomalías, asignación y errores de disponibilidad.
 *
 * Reemplaza el panel monolítico anterior. No gestiona conexiones FTP/Drive.
 * Las fuentes de imagen se separan claramente:
 * - Media local: wp_posts + seo_media_imagenes / seo_media_usos.
 * - Proveedor externo: seo_supplier_images.
 *
 * Version: 2026-09-02
 * Build: 007
 */

if (!defined('ABSPATH')) {
    exit;
}

$seo_images_core = dirname(__DIR__) . '/seo-images.php';
if (is_readable($seo_images_core)) {
    require_once $seo_images_core;
}
unset($seo_images_core);

$seo_images_anomalies = dirname(__DIR__) . '/seo-image-anomalies.php';
if (is_readable($seo_images_anomalies)) {
    require_once $seo_images_anomalies;
}
unset($seo_images_anomalies);

$seo_images_assignment = dirname(__DIR__) . '/seo-image-assignment.php';
if (is_readable($seo_images_assignment)) {
    require_once $seo_images_assignment;
}
unset($seo_images_assignment);

$seo_images_scan = dirname(__DIR__) . '/seo-image-scan.php';
if (is_readable($seo_images_scan)) {
    require_once $seo_images_scan;
}
unset($seo_images_scan);

if (!function_exists('seo_images_admin_url')) {
    function seo_images_admin_url($args = array()) {
        return add_query_arg(
            array_merge(array('page' => 'seo-pictures-admin'), is_array($args) ? $args : array()),
            admin_url('admin.php')
        );
    }
}

if (!function_exists('seo_images_admin_redirect')) {
    function seo_images_admin_redirect($tab, $message, $extra = array()) {
        $args = array_merge(
            array(
                'tab'       => sanitize_key($tab),
                'image_msg' => sanitize_key($message),
            ),
            is_array($extra) ? $extra : array()
        );
        wp_safe_redirect(seo_images_admin_url($args));
        exit;
    }
}

if (!function_exists('seo_images_admin_store_error')) {
    function seo_images_admin_store_error($error) {
        if (!is_wp_error($error)) {
            return;
        }

        $user_id = get_current_user_id();
        if ($user_id < 1) {
            return;
        }

        set_transient(
            'seo_images_last_error_' . $user_id,
            array(
                'code'    => sanitize_key($error->get_error_code()),
                'message' => sanitize_text_field($error->get_error_message()),
            ),
            120
        );
    }
}

if (!function_exists('seo_images_admin_pull_error')) {
    function seo_images_admin_pull_error() {
        $user_id = get_current_user_id();
        if ($user_id < 1) {
            return null;
        }

        $key = 'seo_images_last_error_' . $user_id;
        $value = get_transient($key);
        delete_transient($key);

        return is_array($value) ? $value : null;
    }
}

if (!function_exists('seo_images_admin_notice')) {
    function seo_images_admin_notice() {
        $message = isset($_GET['image_msg']) ? sanitize_key(wp_unslash($_GET['image_msg'])) : '';
        if ($message === '') {
            return;
        }

        $count     = isset($_GET['count']) ? absint($_GET['count']) : 0;
        $remaining = isset($_GET['remaining']) ? absint($_GET['remaining']) : 0;
        $failed    = isset($_GET['failed']) ? absint($_GET['failed']) : 0;
        $bulk_type = $count > 0 ? ($failed > 0 ? 'warning' : 'success') : ($failed > 0 ? 'error' : 'info');

        $messages = array(
            'assigned'       => array('success', 'Imagen asignada correctamente.'),
            'manual_assigned'=> array('success', 'Imagen subida a Media y asignada correctamente.'),
            'assign_error'   => array('error', 'No se pudo asignar la imagen seleccionada.'),
            'no_candidate'   => array('warning', 'No se encontró una imagen relacionada para ese elemento.'),
            'bulk_assigned'  => array($bulk_type, sprintf('Asignación automática completada: %d elemento(s). Quedan %d sin imagen asociada en este grupo.', $count, $remaining)),
            'bulk_empty'     => array('info', 'No hay elementos pendientes de asignación en este grupo.'),
            'deleted'                   => array('success', sprintf('Se eliminaron %d imágenes locales que seguían sin referencias.', $count)),
            'external_unlinked_deleted' => array('success', sprintf('Se eliminaron %d imágenes externas sin producto.', $count)),
            'external_delete_error'      => array('error', 'No se pudieron eliminar las imágenes externas sin producto.'),
            'broken_media_refs_deleted'  => array('success', sprintf('Se eliminaron %d referencias a imágenes de Media que ya no existían. También se limpiaron los usos huérfanos asociados.', $count)),
            'broken_media_delete_error'  => array('error', 'No se pudieron eliminar las referencias huérfanas de Media.'),
            'delete_error'               => array('error', 'No se pudo completar la limpieza de Media.'),
            'invalid'                   => array('error', 'La solicitud no es válida.'),
        );

        if (!isset($messages[$message])) {
            return;
        }

        $notice_text = $messages[$message][1];
        if ($message === 'assign_error' || ($message === 'bulk_assigned' && $failed > 0)) {
            $detail = seo_images_admin_pull_error();
            if ($message === 'bulk_assigned' && $failed > 0) {
                $notice_text .= sprintf(' Fallaron %d elemento(s).', $failed);
            }
            if (is_array($detail) && !empty($detail['message'])) {
                $code = !empty($detail['code']) ? ' [' . $detail['code'] . ']' : '';
                $notice_text .= ($message === 'bulk_assigned' ? ' Último error:' : '') . $code . ' ' . $detail['message'];
            }
        }

        echo '<div class="notice notice-' . esc_attr($messages[$message][0]) . ' is-dismissible"><p>';
        echo esc_html($notice_text);
        echo '</p></div>';
    }
}

/**
 * INVENTARIO
 */
if (!function_exists('seo_images_inventory_format_counts')) {
    /**
     * Cuenta los formatos de imagen que WordPress tiene registrados en Media.
     *
     * Se usa post_mime_type a propósito: es el formato que WordPress considera
     * activo para cada attachment y, por tanto, el que cambia al convertir un
     * JPG/PNG a WebP manteniendo el mismo attachment ID.
     */
    function seo_images_inventory_format_counts() {
        global $wpdb;

        $counts = array(
            'jpeg'        => 0,
            'png'         => 0,
            'webp'        => 0,
            'avif'        => 0,
            'other'       => 0,
            'convertible' => 0,
            'total'       => 0,
        );

        $rows = (array) $wpdb->get_results(
            "SELECT LOWER(post_mime_type) AS mime_type, COUNT(*) AS total
             FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_status = 'inherit'
               AND post_mime_type LIKE 'image/%'
             GROUP BY LOWER(post_mime_type)",
            ARRAY_A
        );

        foreach ($rows as $row) {
            $mime  = isset($row['mime_type']) ? (string) $row['mime_type'] : '';
            $total = isset($row['total']) ? (int) $row['total'] : 0;
            $counts['total'] += $total;

            switch ($mime) {
                case 'image/jpeg':
                    $counts['jpeg'] += $total;
                    break;
                case 'image/png':
                    $counts['png'] += $total;
                    break;
                case 'image/webp':
                    $counts['webp'] += $total;
                    break;
                case 'image/avif':
                    $counts['avif'] += $total;
                    break;
                default:
                    $counts['other'] += $total;
                    break;
            }
        }

        $counts['convertible'] = (int) $counts['jpeg'] + (int) $counts['png'];

        return $counts;
    }
}

if (!function_exists('seo_images_inventory_summary')) {
    function seo_images_inventory_summary() {
        global $wpdb;

        $supplier_table = seo_images_table_supplier_images();
        $formats = seo_images_inventory_format_counts();
        $summary = array(
            'local_total'       => (int) $formats['total'],
            'external_total'    => 0,
            'external_products' => 0,
            'suppliers'         => 0,
            'published_products'=> 0,
            'formats'           => $formats,
        );

        $summary['published_products'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'product' AND post_status = 'publish'"
        );

        if (seo_images_table_exists($supplier_table)) {
            $row = $wpdb->get_row(
                "SELECT COUNT(*) AS images,
                        COUNT(DISTINCT CASE WHEN product_id IS NOT NULL THEN product_id END) AS products,
                        COUNT(DISTINCT supplier) AS suppliers
                 FROM {$supplier_table}
                 WHERE status = 'active'",
                ARRAY_A
            );

            if (is_array($row)) {
                $summary['external_total']    = (int) $row['images'];
                $summary['external_products'] = (int) $row['products'];
                $summary['suppliers']         = (int) $row['suppliers'];
            }
        }

        return $summary;
    }
}

if (!function_exists('seo_images_inventory_supplier_rows')) {
    function seo_images_inventory_supplier_rows() {
        global $wpdb;
        $table = seo_images_table_supplier_images();

        if (!seo_images_table_exists($table)) {
            return array();
        }

        return (array) $wpdb->get_results(
            "SELECT supplier,
                    COUNT(*) AS images,
                    COUNT(DISTINCT CASE WHEN product_id IS NOT NULL THEN product_id END) AS products,
                    SUM(CASE WHEN is_primary = 1 THEN 1 ELSE 0 END) AS primary_images,
                    SUM(CASE WHEN product_id IS NULL THEN 1 ELSE 0 END) AS unlinked,
                    ROUND(COUNT(*) / NULLIF(COUNT(DISTINCT CASE WHEN product_id IS NOT NULL THEN product_id END), 0), 2) AS average_images
             FROM {$table}
             WHERE status = 'active'
             GROUP BY supplier
             ORDER BY images DESC, supplier ASC",
            ARRAY_A
        );
    }
}

if (!function_exists('seo_images_inventory_local_rows')) {
    function seo_images_inventory_local_rows($page = 1, $per_page = 100) {
        $page     = max(1, absint($page));
        $per_page = max(20, min(200, absint($per_page)));

        $query = new WP_Query(array(
            'post_type'              => 'attachment',
            'post_mime_type'         => 'image',
            'post_status'            => 'inherit',
            'posts_per_page'         => $per_page,
            'paged'                  => $page,
            'orderby'                => 'ID',
            'order'                  => 'DESC',
            'no_found_rows'          => false,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ));

        return array($query->posts, (int) $query->found_posts, (int) $query->max_num_pages);
    }
}

/**
 * ANOMALÍAS EXTERNAS
 */
if (!function_exists('seo_images_anomaly_low_image_products')) {
    function seo_images_anomaly_low_image_products($threshold = 7, $supplier = '', $limit = 200) {
        global $wpdb;

        $table     = seo_images_table_supplier_images();
        $threshold = max(1, min(50, absint($threshold)));
        $limit     = max(1, min(500, absint($limit)));
        $supplier  = sanitize_text_field($supplier);

        if (!seo_images_table_exists($table)) {
            return array();
        }

        $where_supplier = '';
        $args = array($threshold, $limit);
        if ($supplier !== '') {
            $where_supplier = ' AND si.supplier = %s ';
            $args = array($supplier, $threshold, $limit);
        }

        $sql = "SELECT si.supplier, si.product_id, MAX(si.supplier_sku) AS supplier_sku,
                       COUNT(*) AS image_count, MAX(p.post_title) AS product_title
                FROM {$table} si
                LEFT JOIN {$wpdb->posts} p ON p.ID = si.product_id AND p.post_type = 'product'
                WHERE si.status = 'active'
                  AND si.product_id IS NOT NULL
                  {$where_supplier}
                GROUP BY si.supplier, si.product_id
                HAVING COUNT(*) < %d
                ORDER BY image_count ASC, si.supplier ASC, si.product_id ASC
                LIMIT %d";

        return (array) $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
    }
}

if (!function_exists('seo_images_anomaly_unlinked_external_count')) {
    function seo_images_anomaly_unlinked_external_count() {
        global $wpdb;
        $table = seo_images_table_supplier_images();

        if (!seo_images_table_exists($table)) {
            return 0;
        }

        return (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$table} si
             LEFT JOIN {$wpdb->posts} p
                ON p.ID = si.product_id
               AND p.post_type = 'product'
             WHERE si.status = 'active'
               AND (si.product_id IS NULL OR p.ID IS NULL)"
        );
    }
}

if (!function_exists('seo_images_anomaly_unlinked_external')) {
    function seo_images_anomaly_unlinked_external($limit = 200) {
        global $wpdb;
        $table = seo_images_table_supplier_images();
        $limit = max(1, min(500, absint($limit)));

        if (!seo_images_table_exists($table)) {
            return array();
        }

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT si.id, si.supplier, si.supplier_sku, si.product_id, si.image_url, si.position,
                        CASE
                            WHEN si.product_id IS NULL THEN 'sin_product_id'
                            WHEN p.ID IS NULL THEN 'producto_inexistente'
                            ELSE 'ok'
                        END AS problem
                 FROM {$table} si
                 LEFT JOIN {$wpdb->posts} p ON p.ID = si.product_id AND p.post_type = 'product'
                 WHERE si.status = 'active'
                   AND (si.product_id IS NULL OR p.ID IS NULL)
                 ORDER BY si.supplier ASC, si.id ASC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
    }
}

if (!function_exists('seo_images_anomaly_products_without_images')) {
    function seo_images_anomaly_products_without_images($limit = 200) {
        global $wpdb;
        $supplier_table = seo_images_table_supplier_images();
        $limit = max(1, min(500, absint($limit)));

        if (!seo_images_table_exists($supplier_table)) {
            return array();
        }

        // Preselección rápida. Después se valida la galería WooCommerce en PHP.
        $candidate_ids = (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 WHERE p.post_type = 'product'
                   AND p.post_status = 'publish'
                   AND NOT EXISTS (
                       SELECT 1
                       FROM {$supplier_table} si
                       WHERE si.product_id = p.ID
                         AND si.status = 'active'
                         AND si.image_url IS NOT NULL
                         AND TRIM(si.image_url) <> ''
                   )
                   AND NOT EXISTS (
                       SELECT 1
                       FROM {$wpdb->postmeta} pm
                       INNER JOIN {$wpdb->posts} a
                          ON a.ID = CAST(pm.meta_value AS UNSIGNED)
                         AND a.post_type = 'attachment'
                       WHERE pm.post_id = p.ID
                         AND pm.meta_key = '_thumbnail_id'
                   )
                 ORDER BY p.ID ASC
                 LIMIT %d",
                $limit * 2
            )
        );

        $rows = array();
        foreach ($candidate_ids as $product_id) {
            $product_id = absint($product_id);
            if (!$product_id || seo_images_product_has_usable_image($product_id)) {
                continue;
            }
            $rows[] = array(
                'product_id' => $product_id,
                'title'      => get_the_title($product_id),
                'sku'        => (string) get_post_meta($product_id, '_sku', true),
                'url'        => get_permalink($product_id),
            );
            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }
}

if (!function_exists('seo_images_anomaly_broken_media_index_count')) {
    function seo_images_anomaly_broken_media_index_count() {
        global $wpdb;
        $table = seo_images_table_images();

        if (!seo_images_table_exists($table)) {
            return 0;
        }

        return (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$table} mi
             LEFT JOIN {$wpdb->posts} p
                ON p.ID = mi.attachment_id
               AND p.post_type = 'attachment'
             WHERE mi.attachment_id IS NOT NULL
               AND mi.attachment_id > 0
               AND p.ID IS NULL"
        );
    }
}

if (!function_exists('seo_images_anomaly_broken_media_index')) {
    function seo_images_anomaly_broken_media_index($limit = 200) {
        global $wpdb;
        $table = seo_images_table_images();
        $limit = max(1, min(500, absint($limit)));

        if (!seo_images_table_exists($table)) {
            return array();
        }

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT mi.id, mi.attachment_id, mi.proveedor, mi.url_origen, mi.estado, mi.nombre_archivo
                 FROM {$table} mi
                 LEFT JOIN {$wpdb->posts} p
                    ON p.ID = mi.attachment_id
                   AND p.post_type = 'attachment'
                 WHERE mi.attachment_id IS NOT NULL
                   AND mi.attachment_id > 0
                   AND p.ID IS NULL
                 ORDER BY mi.id ASC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
    }
}

/**
 * MUTACIONES
 */
if (!function_exists('seo_images_handle_assign_one')) {
    function seo_images_handle_assign_one() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para asignar imágenes.', 'seo-system'));
        }

        $scope_type    = isset($_POST['scope_type']) ? sanitize_key(wp_unslash($_POST['scope_type'])) : '';
        $object_id     = isset($_POST['object_id']) ? absint($_POST['object_id']) : 0;
        $candidate_key = isset($_POST['candidate_key']) ? sanitize_text_field(wp_unslash($_POST['candidate_key'])) : '';

        check_admin_referer('seo_images_assign_' . $scope_type . '_' . $object_id);

        $candidate = seo_images_assignment_find_candidate_by_key($scope_type, $object_id, $candidate_key);
        if (!$candidate) {
            seo_images_admin_redirect('assignment', 'no_candidate', array('scope' => $scope_type));
        }

        $result = seo_images_assignment_apply_candidate($scope_type, $object_id, $candidate);
        if (is_wp_error($result)) {
            seo_images_admin_store_error($result);
        }

        seo_images_admin_redirect(
            'assignment',
            is_wp_error($result) ? 'assign_error' : 'assigned',
            array('scope' => $scope_type)
        );
    }
}
add_action('admin_post_seo_images_assign_one', 'seo_images_handle_assign_one');

/**
 * Sube manualmente una imagen previamente descargada por el usuario y la
 * asigna al objeto SEO actual. Este flujo evita los bloqueos HTTP 403 que
 * algunos proveedores aplican a las descargas realizadas desde el servidor.
 */
if (!function_exists('seo_images_handle_manual_upload')) {
    function seo_images_handle_manual_upload() {
        if (!current_user_can('manage_options') || !current_user_can('upload_files')) {
            wp_die(esc_html__('No tienes permisos para subir y asignar imágenes.', 'seo-system'));
        }

        $scope_type    = isset($_POST['scope_type']) ? sanitize_key(wp_unslash($_POST['scope_type'])) : '';
        $object_id     = isset($_POST['object_id']) ? absint($_POST['object_id']) : 0;
        $candidate_key = isset($_POST['candidate_key']) ? sanitize_text_field(wp_unslash($_POST['candidate_key'])) : '';

        check_admin_referer('seo_images_manual_upload_' . $scope_type . '_' . $object_id . '_' . $candidate_key);

        if (!array_key_exists($scope_type, seo_images_assignment_scope_labels()) || $object_id < 1) {
            seo_images_admin_redirect('assignment', 'invalid', array('scope' => $scope_type));
        }

        $candidate = seo_images_assignment_find_candidate_by_key($scope_type, $object_id, $candidate_key);
        if (!$candidate) {
            seo_images_admin_redirect('assignment', 'no_candidate', array('scope' => $scope_type));
        }

        if (empty($_FILES['manual_image']) || !is_array($_FILES['manual_image'])) {
            $error = new WP_Error('seo_images_manual_file_missing', 'Selecciona primero el archivo de imagen que has descargado.');
            seo_images_admin_store_error($error);
            seo_images_admin_redirect('assignment', 'assign_error', array('scope' => $scope_type));
        }

        $upload_error = isset($_FILES['manual_image']['error']) ? (int) $_FILES['manual_image']['error'] : UPLOAD_ERR_NO_FILE;
        if ($upload_error !== UPLOAD_ERR_OK) {
            $messages = array(
                UPLOAD_ERR_INI_SIZE   => 'La imagen supera el tamaño máximo permitido por el servidor.',
                UPLOAD_ERR_FORM_SIZE  => 'La imagen supera el tamaño máximo permitido por el formulario.',
                UPLOAD_ERR_PARTIAL    => 'La imagen solo se subió parcialmente. Vuelve a intentarlo.',
                UPLOAD_ERR_NO_FILE    => 'Selecciona primero el archivo de imagen que has descargado.',
                UPLOAD_ERR_NO_TMP_DIR => 'El servidor no dispone de carpeta temporal para la subida.',
                UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir el archivo subido.',
                UPLOAD_ERR_EXTENSION  => 'Una extensión de PHP bloqueó la subida del archivo.',
            );
            $error = new WP_Error('seo_images_manual_upload_failed', $messages[$upload_error] ?? 'No se pudo subir la imagen.');
            seo_images_admin_store_error($error);
            seo_images_admin_redirect('assignment', 'assign_error', array('scope' => $scope_type));
        }

        if (!function_exists('media_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $source_label = sanitize_text_field((string) ($candidate['source_label'] ?? 'Imagen de jerarquía SEO'));
        $post_data = array(
            'post_title' => $source_label !== '' ? $source_label : 'Imagen de jerarquía SEO',
        );

        $attachment_id = media_handle_upload(
            'manual_image',
            0,
            $post_data,
            array('test_form' => false)
        );

        if (is_wp_error($attachment_id)) {
            seo_images_admin_store_error($attachment_id);
            seo_images_admin_redirect('assignment', 'assign_error', array('scope' => $scope_type));
        }

        $attachment_id = absint($attachment_id);
        if (!$attachment_id || !seo_images_is_valid_attachment($attachment_id)) {
            $error = new WP_Error('seo_images_manual_invalid_attachment', 'WordPress recibió el archivo, pero no pudo crear un attachment de imagen válido.');
            seo_images_admin_store_error($error);
            seo_images_admin_redirect('assignment', 'assign_error', array('scope' => $scope_type));
        }

        // Mantener trazabilidad con la imagen externa que el usuario eligió.
        $provider     = sanitize_text_field((string) ($candidate['provider'] ?? 'MANUAL'));
        $source_url   = seo_images_normalize_url($candidate['url'] ?? '');
        $file_path    = get_attached_file($attachment_id);
        $url_hash     = ($source_url !== '' && wp_http_validate_url($source_url)) ? seo_images_url_hash($source_url) : '';
        $content_hash = (is_string($file_path) && $file_path !== '' && is_file($file_path))
            ? hash_file('sha256', $file_path)
            : '';

        update_post_meta($attachment_id, '_seo_proveedor', $provider ?: 'MANUAL');
        if ($source_url !== '' && wp_http_validate_url($source_url)) {
            update_post_meta($attachment_id, '_seo_url_origen', $source_url);
        }
        if ($url_hash !== '') {
            update_post_meta($attachment_id, '_seo_url_hash', $url_hash);
        }
        if ($content_hash !== '') {
            update_post_meta($attachment_id, '_seo_content_hash', $content_hash);
        }

        if ($source_url !== '' && wp_http_validate_url($source_url)) {
            $stored = seo_images_store_image_record(array(
                'attachment_id'  => $attachment_id,
                'proveedor'      => $provider ?: 'MANUAL',
                'url_origen'     => $source_url,
                'url_hash'       => $url_hash,
                'content_hash'   => $content_hash,
                'nombre_archivo' => (is_string($file_path) && $file_path !== '') ? wp_basename($file_path) : '',
                'estado'         => 'disponible',
            ));
            if (is_wp_error($stored)) {
                error_log('[SEO Images] La subida manual se creó, pero no se pudo guardar en el índice: ' . $stored->get_error_message());
            }
        }

        $result = seo_images_assign_attachment_to_object($scope_type, $object_id, $attachment_id);
        if (is_wp_error($result)) {
            seo_images_admin_store_error($result);
            seo_images_admin_redirect('assignment', 'assign_error', array('scope' => $scope_type));
        }

        seo_images_admin_redirect('assignment', 'manual_assigned', array('scope' => $scope_type));
    }
}
add_action('admin_post_seo_images_manual_upload', 'seo_images_handle_manual_upload');

if (!function_exists('seo_images_handle_auto_assign')) {
    function seo_images_handle_auto_assign() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para asignar imágenes.', 'seo-system'));
        }

        $scope_type = isset($_POST['scope_type']) ? sanitize_key(wp_unslash($_POST['scope_type'])) : '';
        check_admin_referer('seo_images_auto_assign_' . $scope_type);

        if (!array_key_exists($scope_type, seo_images_assignment_scope_labels())) {
            seo_images_admin_redirect('assignment', 'invalid');
        }

        // Lote deliberadamente pequeño: una asignación externa puede implicar descarga.
        $items = seo_images_assignment_get_objects($scope_type, true, 25, 0);
        if (empty($items)) {
            seo_images_admin_redirect('assignment', 'bulk_empty', array('scope' => $scope_type));
        }

        $assigned   = 0;
        $failed     = 0;
        $last_error = null;

        foreach ($items as $item) {
            $candidates = seo_images_assignment_find_candidates($scope_type, $item['id'], 8);
            if (empty($candidates)) {
                continue;
            }

            // Mantener el orden de relevancia devuelto por la jerarquia.
            // Media y proveedor son fuentes equivalentes: si un candidato externo
            // es el elegido, se materializa solo esa imagen en WordPress Media.

            $item_assigned = false;
            $item_error    = null;

            foreach ($candidates as $candidate) {
                $result = seo_images_assignment_apply_candidate($scope_type, $item['id'], $candidate);
                if (!is_wp_error($result)) {
                    $assigned++;
                    $item_assigned = true;
                    break;
                }
                $item_error = $result;
            }

            if (!$item_assigned && is_wp_error($item_error)) {
                $failed++;
                $last_error = $item_error;
            }
        }

        if (is_wp_error($last_error)) {
            seo_images_admin_store_error($last_error);
        }

        $remaining = count(seo_images_assignment_get_objects($scope_type, true, 500, 0));
        seo_images_admin_redirect('assignment', 'bulk_assigned', array(
            'scope'     => $scope_type,
            'count'     => $assigned,
            'failed'    => $failed,
            'remaining' => $remaining,
        ));
    }
}
add_action('admin_post_seo_images_auto_assign', 'seo_images_handle_auto_assign');

if (!function_exists('seo_images_handle_delete_unlinked_external')) {
    function seo_images_handle_delete_unlinked_external() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para eliminar imágenes externas.', 'seo-system'));
        }

        check_admin_referer('seo_images_delete_unlinked_external');

        global $wpdb;
        $table = seo_images_table_supplier_images();

        if (!seo_images_table_exists($table)) {
            seo_images_admin_redirect('anomalies', 'invalid');
        }

        $deleted = $wpdb->query(
            "DELETE si
             FROM {$table} si
             LEFT JOIN {$wpdb->posts} p
                ON p.ID = si.product_id
               AND p.post_type = 'product'
             WHERE si.status = 'active'
               AND (si.product_id IS NULL OR p.ID IS NULL)"
        );

        if (false === $deleted) {
            seo_images_admin_redirect('anomalies', 'external_delete_error');
        }

        seo_images_admin_redirect(
            'anomalies',
            'external_unlinked_deleted',
            array('count' => absint($deleted))
        );
    }
}
add_action('admin_post_seo_images_delete_unlinked_external', 'seo_images_handle_delete_unlinked_external');

if (!function_exists('seo_images_handle_delete_broken_media_refs')) {
    function seo_images_handle_delete_broken_media_refs() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para limpiar referencias de Media.', 'seo-system'));
        }

        check_admin_referer('seo_images_delete_broken_media_refs');

        global $wpdb;
        $images_table = seo_images_table_images();
        $usages_table = seo_images_table_usages();

        if (!seo_images_table_exists($images_table)) {
            seo_images_admin_redirect('anomalies', 'invalid');
        }

        // Limpia primero los usos SEO que apuntan a attachments que ya no existen.
        if (seo_images_table_exists($usages_table)) {
            $usage_deleted = $wpdb->query(
                "DELETE u
                 FROM {$usages_table} u
                 LEFT JOIN {$wpdb->posts} p
                    ON p.ID = u.attachment_id
                   AND p.post_type = 'attachment'
                 WHERE u.attachment_id IS NOT NULL
                   AND u.attachment_id > 0
                   AND p.ID IS NULL"
            );

            if (false === $usage_deleted) {
                seo_images_admin_redirect('anomalies', 'broken_media_delete_error');
            }
        }

        // Borra las filas del índice SEO cuyo attachment ya fue eliminado de WordPress.
        $deleted = $wpdb->query(
            "DELETE mi
             FROM {$images_table} mi
             LEFT JOIN {$wpdb->posts} p
                ON p.ID = mi.attachment_id
               AND p.post_type = 'attachment'
             WHERE mi.attachment_id IS NOT NULL
               AND mi.attachment_id > 0
               AND p.ID IS NULL"
        );

        if (false === $deleted) {
            seo_images_admin_redirect('anomalies', 'broken_media_delete_error');
        }

        seo_images_admin_redirect(
            'anomalies',
            'broken_media_refs_deleted',
            array('count' => absint($deleted))
        );
    }
}
add_action('admin_post_seo_images_delete_broken_media_refs', 'seo_images_handle_delete_broken_media_refs');

if (!function_exists('seo_images_handle_delete_unused_local')) {
    function seo_images_handle_delete_unused_local() {
        if (!current_user_can('delete_posts') || !current_user_can('upload_files')) {
            wp_die(esc_html__('No tienes permisos para eliminar imágenes.', 'seo-system'));
        }

        check_admin_referer('seo_images_delete_unused_local');

        $ids = isset($_POST['attachment_ids']) ? (array) wp_unslash($_POST['attachment_ids']) : array();
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));

        if (empty($ids)) {
            seo_images_admin_redirect('anomalies', 'invalid');
        }

        $attachments = get_posts(array(
            'post_type'              => 'attachment',
            'post_mime_type'         => 'image',
            'post_status'            => 'inherit',
            'posts_per_page'         => -1,
            'post__in'               => $ids,
            'orderby'                => 'post__in',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
        ));

        $usage = seo_pictures_anomalies_build_usage_report($attachments);
        $deleted = 0;

        foreach ($attachments as $attachment) {
            $attachment_id = absint($attachment->ID);
            if (!current_user_can('delete_post', $attachment_id)) {
                continue;
            }
            if (!empty($usage[$attachment_id])) {
                continue;
            }
            if (wp_delete_attachment($attachment_id, true)) {
                $deleted++;
            }
        }

        seo_images_admin_redirect('anomalies', 'deleted', array('count' => $deleted));
    }
}
add_action('admin_post_seo_images_delete_unused_local', 'seo_images_handle_delete_unused_local');

/**
 * RENDER
 */
if (!function_exists('seo_images_render_styles')) {
    function seo_images_render_styles() {
        echo '<style>
            .seo-images-panel{margin-top:20px;max-width:1500px}.seo-images-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin:16px 0}.seo-images-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px}.seo-images-kpi strong{display:block;font-size:27px;line-height:1.1;margin-bottom:5px}.seo-images-format-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:20px 0 0}.seo-images-format-head h2{margin:0;font-size:15px}.seo-images-format-grid{margin-top:10px}.seo-images-format-kpi{padding:14px 18px}.seo-images-format-kpi strong{font-size:24px}.seo-images-format-note{margin:-5px 0 16px}.seo-images-muted{color:#646970}.seo-images-table-thumb{width:70px;height:70px;object-fit:cover;border-radius:6px;background:#f0f0f1}.seo-images-candidates{display:flex;gap:10px;flex-wrap:wrap}.seo-images-candidate{width:118px;border:1px solid #dcdcde;border-radius:7px;padding:7px;background:#fff}.seo-images-candidate img{display:block;width:102px;height:82px;object-fit:cover;border-radius:4px;background:#f0f0f1}.seo-images-candidate small{display:block;margin:5px 0;line-height:1.25;height:32px;overflow:hidden}.seo-images-status{display:inline-block;padding:2px 7px;border-radius:999px;font-size:11px;font-weight:700}.seo-images-status.local{background:#e8f5e9;color:#1b5e20}.seo-images-status.external{background:#e3f2fd;color:#0d47a1}.seo-images-toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:15px 0}.seo-images-table-actions{white-space:nowrap}.seo-images-danger{color:#b32d2e}.seo-images-ok{color:#16732d}.seo-images-manual-help{margin:14px 0;padding:12px 14px;background:#fff8e5;border-left:4px solid #dba617;max-width:1100px}.seo-images-candidate-actions{display:grid;gap:6px;margin-top:6px}.seo-images-candidate-actions .button{text-align:center}.seo-images-manual-form{border-top:1px solid #dcdcde;margin-top:7px;padding-top:7px}.seo-images-manual-form input[type=file]{display:block;width:100%;font-size:11px;margin:5px 0}.seo-images-open-link{font-size:12px;text-decoration:none}.seo-images-candidate{width:150px}.seo-images-candidate img{width:134px}@media(max-width:800px){.seo-images-candidate{width:125px}.seo-images-candidate img{width:109px;height:70px}}
        </style>';
    }
}

if (!function_exists('seo_images_render_inventory_tab')) {
    function seo_images_render_inventory_tab() {
        $summary = seo_images_inventory_summary();
        $supplier_rows = seo_images_inventory_supplier_rows();
        $media_page = isset($_GET['media_page']) ? max(1, absint($_GET['media_page'])) : 1;
        list($local_rows, $local_total, $local_pages) = seo_images_inventory_local_rows($media_page, 100);
        ?>
        <div class="seo-images-grid">
            <div class="seo-images-card seo-images-kpi"><strong><?php echo esc_html(number_format_i18n($summary['local_total'])); ?></strong><span>Imágenes en Media local</span></div>
            <div class="seo-images-card seo-images-kpi"><strong><?php echo esc_html(number_format_i18n($summary['external_total'])); ?></strong><span>Imágenes externas activas</span></div>
            <div class="seo-images-card seo-images-kpi"><strong><?php echo esc_html(number_format_i18n($summary['external_products'])); ?></strong><span>Productos con imágenes externas</span></div>
            <div class="seo-images-card seo-images-kpi"><strong><?php echo esc_html(number_format_i18n($summary['suppliers'])); ?></strong><span>Proveedores con imágenes</span></div>
        </div>

        <?php
        $formats = isset($summary['formats']) && is_array($summary['formats'])
            ? $summary['formats']
            : seo_images_inventory_format_counts();
        ?>
        <div class="seo-images-format-head">
            <h2>Formatos en Media local</h2>
            <span class="seo-images-status local" id="seo-images-format-convertible">
                <?php echo esc_html(number_format_i18n($formats['convertible'])); ?> JPG/PNG en Media
            </span>
        </div>
        <div class="seo-images-grid seo-images-format-grid">
            <div class="seo-images-card seo-images-kpi seo-images-format-kpi"><strong id="seo-images-format-jpeg"><?php echo esc_html(number_format_i18n($formats['jpeg'])); ?></strong><span>JPG / JPEG</span></div>
            <div class="seo-images-card seo-images-kpi seo-images-format-kpi"><strong id="seo-images-format-png"><?php echo esc_html(number_format_i18n($formats['png'])); ?></strong><span>PNG</span></div>
            <div class="seo-images-card seo-images-kpi seo-images-format-kpi"><strong id="seo-images-format-webp"><?php echo esc_html(number_format_i18n($formats['webp'])); ?></strong><span>WebP</span></div>
            <div class="seo-images-card seo-images-kpi seo-images-format-kpi"><strong id="seo-images-format-avif"><?php echo esc_html(number_format_i18n($formats['avif'])); ?></strong><span>AVIF</span></div>
            <div class="seo-images-card seo-images-kpi seo-images-format-kpi"><strong id="seo-images-format-other"><?php echo esc_html(number_format_i18n($formats['other'])); ?></strong><span>Otros formatos</span></div>
        </div>
        <p class="seo-images-muted seo-images-format-note">
            Conteo por formato registrado en WordPress. Durante una conversión, JPG/PNG bajan y WebP sube en tiempo real.
        </p>

        <section class="seo-images-card">
            <h2>Imágenes externas por proveedor</h2>
            <p class="seo-images-muted">Estas imágenes no ocupan espacio en Media. La galería del producto las lee desde <code>seo_supplier_images</code>.</p>
            <table class="widefat striped">
                <thead><tr><th>Proveedor</th><th>Imágenes</th><th>Productos</th><th>Promedio</th><th>Principales</th><th>Sin product_id</th></tr></thead>
                <tbody>
                <?php if (empty($supplier_rows)) : ?>
                    <tr><td colspan="6">Todavía no hay imágenes externas registradas.</td></tr>
                <?php else : foreach ($supplier_rows as $row) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($row['supplier']); ?></strong></td>
                        <td><?php echo esc_html(number_format_i18n($row['images'])); ?></td>
                        <td><?php echo esc_html(number_format_i18n($row['products'])); ?></td>
                        <td><?php echo esc_html($row['average_images']); ?></td>
                        <td><?php echo esc_html(number_format_i18n($row['primary_images'])); ?></td>
                        <td class="<?php echo ((int) $row['unlinked'] > 0) ? 'seo-images-danger' : 'seo-images-ok'; ?>"><?php echo esc_html(number_format_i18n($row['unlinked'])); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </section>

        <?php if (function_exists('seo_images_optimizer_render_controls')) : ?>
            <?php seo_images_optimizer_render_controls($summary); ?>
        <?php endif; ?>

        <section class="seo-images-card" style="margin-top:16px">
            <h2>Media local</h2>
            <p class="seo-images-muted">Inventario paginado de attachments. El análisis de imágenes sin uso se realiza en <strong>Anomalías</strong>, donde se vuelven a comprobar las referencias antes de permitir borrar.</p>
            <table class="widefat striped">
                <thead><tr><th>ID</th><th>Vista</th><th>Nombre</th><th>Proveedor</th><th>Tamaño</th><th>URL</th></tr></thead>
                <tbody>
                <?php foreach ($local_rows as $attachment) :
                    $record = seo_images_find_record_by_attachment($attachment->ID);
                    $thumb = wp_get_attachment_image_url($attachment->ID, 'thumbnail');
                    ?>
                    <tr>
                        <td><code><?php echo esc_html((string) $attachment->ID); ?></code></td>
                        <td><?php if ($thumb) : ?><img class="seo-images-table-thumb" src="<?php echo esc_url($thumb); ?>" alt=""><?php endif; ?></td>
                        <td><?php echo esc_html($attachment->post_title); ?></td>
                        <td><?php echo esc_html($record->proveedor ?? '—'); ?></td>
                        <td><?php echo esc_html(size_format(seo_pictures_anomalies_get_attachment_size($attachment->ID), 1)); ?></td>
                        <td><a href="<?php echo esc_url(wp_get_attachment_url($attachment->ID)); ?>" target="_blank" rel="noopener">ver</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($local_pages > 1) : ?>
                <div class="tablenav"><div class="tablenav-pages">
                    <?php echo wp_kses_post(paginate_links(array(
                        'base'      => add_query_arg(array('tab' => 'inventory', 'media_page' => '%#%'), seo_images_admin_url()),
                        'format'    => '',
                        'current'   => $media_page,
                        'total'     => $local_pages,
                        'prev_text' => '‹',
                        'next_text' => '›',
                    ))); ?>
                </div></div>
            <?php endif; ?>
        </section>
        <?php
    }
}

if (!function_exists('seo_images_render_anomalies_tab')) {
    function seo_images_render_anomalies_tab() {
        $threshold = isset($_GET['min_images']) ? max(1, min(50, absint($_GET['min_images']))) : 7;
        $supplier  = isset($_GET['supplier']) ? sanitize_text_field(wp_unslash($_GET['supplier'])) : '';
        $low_rows       = seo_images_anomaly_low_image_products($threshold, $supplier, 200);
        $unlinked       = seo_images_anomaly_unlinked_external(100);
        $unlinked_total = seo_images_anomaly_unlinked_external_count();
        $no_images      = seo_images_anomaly_products_without_images(100);
        $broken_total    = seo_images_anomaly_broken_media_index_count();
        $broken          = seo_images_anomaly_broken_media_index(100);
        $scan_page = isset($_GET['scan_page']) ? max(1, absint($_GET['scan_page'])) : 1;
        $do_scan   = !empty($_GET['scan_local']);
        ?>
        <section class="seo-images-card">
            <h2>Productos con pocas imágenes externas</h2>
            <form method="get" class="seo-images-toolbar">
                <input type="hidden" name="page" value="seo-pictures-admin">
                <input type="hidden" name="tab" value="anomalies">
                <label>Proveedor <input type="text" name="supplier" value="<?php echo esc_attr($supplier); ?>" placeholder="VEVOR"></label>
                <label>Marcar si tiene menos de <input type="number" min="1" max="50" name="min_images" value="<?php echo esc_attr((string) $threshold); ?>" style="width:70px"></label>
                <button class="button">Aplicar</button>
            </form>
            <p><strong><?php echo esc_html(number_format_i18n(count($low_rows))); ?></strong> resultados mostrados (máximo 200).</p>
            <table class="widefat striped">
                <thead><tr><th>Proveedor</th><th>Producto</th><th>SKU</th><th>Imágenes</th><th>Ver</th></tr></thead>
                <tbody>
                <?php if (empty($low_rows)) : ?><tr><td colspan="5">No se encontraron productos por debajo del umbral.</td></tr>
                <?php else : foreach ($low_rows as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row['supplier']); ?></td>
                        <td><strong><?php echo esc_html($row['product_title'] ?: ('Producto #' . $row['product_id'])); ?></strong><br><code>#<?php echo esc_html((string) $row['product_id']); ?></code></td>
                        <td><?php echo esc_html($row['supplier_sku']); ?></td>
                        <td><strong class="seo-images-danger"><?php echo esc_html((string) $row['image_count']); ?></strong></td>
                        <td><a href="<?php echo esc_url(get_permalink($row['product_id'])); ?>" target="_blank" rel="noopener">producto</a></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </section>

        <div class="seo-images-grid">
            <section class="seo-images-card">
                <h2>Imágenes externas sin producto</h2>
                <p><strong><?php echo esc_html(number_format_i18n($unlinked_total)); ?></strong> imágenes externas huérfanas en total. Se muestran como máximo 100.</p>
                <?php if ($unlinked_total > 0) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:12px 0 16px">
                        <input type="hidden" name="action" value="seo_images_delete_unlinked_external">
                        <?php wp_nonce_field('seo_images_delete_unlinked_external'); ?>
                        <button
                            type="submit"
                            class="button button-secondary"
                            onclick="return confirm('Se eliminarán TODAS las imágenes externas activas que no estén asociadas a un producto WooCommerce existente. No se borrará ningún archivo de Media. ¿Continuar?');"
                        >Borrar todas las imágenes externas sin producto (<?php echo esc_html(number_format_i18n($unlinked_total)); ?>)</button>
                    </form>
                <?php endif; ?>
                <?php if (!empty($unlinked)) : ?><table class="widefat striped"><thead><tr><th>Proveedor/SKU</th><th>Problema</th></tr></thead><tbody><?php foreach ($unlinked as $row) : ?><tr><td><?php echo esc_html($row['supplier'] . ' · ' . $row['supplier_sku']); ?></td><td class="seo-images-danger"><?php echo esc_html($row['problem']); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
            </section>
            <section class="seo-images-card"><h2>Productos sin imagen utilizable</h2><p><strong><?php echo esc_html(number_format_i18n(count($no_images))); ?></strong> productos mostrados.</p>
                <?php if (!empty($no_images)) : ?><table class="widefat striped"><thead><tr><th>Producto</th><th>SKU</th></tr></thead><tbody><?php foreach ($no_images as $row) : ?><tr><td><a href="<?php echo esc_url($row['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($row['title']); ?></a></td><td><?php echo esc_html($row['sku']); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
            </section>
        </div>

        <?php if ($broken_total > 0) : ?>
            <section class="seo-images-card" style="margin-top:16px">
                <h2>Referencias a imágenes de Media que ya no existen</h2>
                <p class="seo-images-muted">
                    Hay <strong><?php echo esc_html(number_format_i18n($broken_total)); ?></strong> referencias guardadas en <code>seo_media_imagenes</code> que apuntan a attachments que ya fueron eliminados de WordPress.
                    No son imágenes reales: son restos del índice. Al limpiarlas no se elimina ningún archivo ni ningún attachment válido. Se muestran como máximo 100 referencias.
                </p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:12px 0 16px">
                    <input type="hidden" name="action" value="seo_images_delete_broken_media_refs">
                    <?php wp_nonce_field('seo_images_delete_broken_media_refs'); ?>
                    <button
                        type="submit"
                        class="button button-secondary"
                        onclick="return confirm('Se borrarán TODAS las referencias del índice SEO que apuntan a imágenes de Media que ya no existen. También se limpiarán sus usos SEO huérfanos. No se eliminará ningún archivo ni attachment válido. ¿Continuar?');"
                    >Borrar todas estas referencias (<?php echo esc_html(number_format_i18n($broken_total)); ?>)</button>
                </form>
                <?php if (!empty($broken)) : ?>
                    <table class="widefat striped">
                        <thead><tr><th>ID índice</th><th>Attachment eliminado</th><th>Proveedor</th><th>Archivo</th></tr></thead>
                        <tbody><?php foreach ($broken as $row) : ?><tr><td><?php echo esc_html((string) $row['id']); ?></td><td><?php echo esc_html((string) $row['attachment_id']); ?></td><td><?php echo esc_html($row['proveedor']); ?></td><td><?php echo esc_html($row['nombre_archivo']); ?></td></tr><?php endforeach; ?></tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="seo-images-card" style="margin-top:16px">
            <h2>Media local sin referencias</h2>
            <p>El análisis se hace por lotes de 200 attachments y es conservador: busca referencias en destacadas, galerías, contenido, metadatos, taxonomías, opciones, usuarios y comentarios.</p>
            <?php if (!$do_scan) : ?>
                <a class="button button-primary" href="<?php echo esc_url(seo_images_admin_url(array('tab' => 'anomalies', 'scan_local' => 1, 'scan_page' => 1, 'min_images' => $threshold, 'supplier' => $supplier))); ?>">Analizar primer lote de Media</a>
            <?php else :
                list($attachments, $local_total, $max_pages) = seo_images_inventory_local_rows($scan_page, 200);
                $usage = seo_pictures_anomalies_build_usage_report($attachments);
                $unused = array();
                foreach ($attachments as $attachment) {
                    if (empty($usage[$attachment->ID])) {
                        $unused[] = $attachment;
                    }
                }
                ?>
                <p>Lote <?php echo esc_html((string) $scan_page); ?> de <?php echo esc_html((string) max(1, $max_pages)); ?> · <?php echo esc_html(number_format_i18n(count($unused))); ?> posibles imágenes sin uso en este lote.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="seo_images_delete_unused_local">
                    <?php wp_nonce_field('seo_images_delete_unused_local'); ?>
                    <table class="widefat striped"><thead><tr><th style="width:40px"></th><th>Vista</th><th>ID / Nombre</th><th>Espacio</th></tr></thead><tbody>
                    <?php if (empty($unused)) : ?><tr><td colspan="4">No hay imágenes sin referencia en este lote.</td></tr>
                    <?php else : foreach ($unused as $attachment) : $thumb = wp_get_attachment_image_url($attachment->ID, 'thumbnail'); ?>
                        <tr><td><input type="checkbox" name="attachment_ids[]" value="<?php echo esc_attr((string) $attachment->ID); ?>"></td><td><?php if ($thumb) : ?><img class="seo-images-table-thumb" src="<?php echo esc_url($thumb); ?>" alt=""><?php endif; ?></td><td><code>#<?php echo esc_html((string) $attachment->ID); ?></code><br><?php echo esc_html($attachment->post_title); ?></td><td><?php echo esc_html(size_format(seo_pictures_anomalies_get_attachment_size($attachment->ID), 1)); ?></td></tr>
                    <?php endforeach; endif; ?></tbody></table>
                    <?php if (!empty($unused)) : ?><p><button class="button" onclick="return confirm('Solo se borrarán las seleccionadas que continúen sin referencias al volver a comprobarlas. ¿Continuar?');">Eliminar seleccionadas sin uso</button></p><?php endif; ?>
                </form>
                <div class="seo-images-toolbar">
                    <?php if ($scan_page > 1) : ?><a class="button" href="<?php echo esc_url(seo_images_admin_url(array('tab' => 'anomalies', 'scan_local' => 1, 'scan_page' => $scan_page - 1, 'min_images' => $threshold, 'supplier' => $supplier))); ?>">‹ Lote anterior</a><?php endif; ?>
                    <?php if ($scan_page < $max_pages) : ?><a class="button button-primary" href="<?php echo esc_url(seo_images_admin_url(array('tab' => 'anomalies', 'scan_local' => 1, 'scan_page' => $scan_page + 1, 'min_images' => $threshold, 'supplier' => $supplier))); ?>">Siguiente lote ›</a><?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }
}

if (!function_exists('seo_images_render_assignment_tab')) {
    function seo_images_render_assignment_tab() {
        $labels = seo_images_assignment_scope_labels();
        $scope  = isset($_GET['scope']) ? sanitize_key(wp_unslash($_GET['scope'])) : 'product_cat';
        if (!isset($labels[$scope])) {
            $scope = 'product_cat';
        }
        $items = seo_images_assignment_get_objects($scope, true, 50, 0);
        ?>
        <section class="seo-images-card">
            <h2>Asignación de imágenes relacionadas</h2>
            <p>La imagen se busca dentro de la misma rama SEO. Se reutiliza Media si ya existe; si el mejor candidato es externo, se intenta descargar <strong>solo esa imagen</strong> para poder usarla como thumbnail de WordPress.</p>
            <div class="seo-images-manual-help">
                <strong>Si un proveedor bloquea la descarga automática (HTTP 403):</strong> abre una de las imágenes externas, guárdala en tu ordenador y usa <strong>Subir y asignar</strong> en ese mismo candidato. WordPress la subirá a Media y la asociará directamente a esta categoría, landing, hub o cluster.
            </div>
            <div class="seo-images-toolbar">
                <?php foreach ($labels as $key => $label) : ?>
                    <a class="button <?php echo $scope === $key ? 'button-primary' : ''; ?>" href="<?php echo esc_url(seo_images_admin_url(array('tab' => 'assignment', 'scope' => $key))); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:16px 0">
                <input type="hidden" name="action" value="seo_images_auto_assign">
                <input type="hidden" name="scope_type" value="<?php echo esc_attr($scope); ?>">
                <?php wp_nonce_field('seo_images_auto_assign_' . $scope); ?>
                <button class="button button-primary">Asignar automáticamente hasta 25</button>
                <span class="seo-images-muted">El lote es pequeño porque una imagen externa puede requerir descarga.</span>
            </form>
            <p><strong>Pendientes mostrados:</strong> <?php echo esc_html(number_format_i18n(count($items))); ?> (máximo 50). Solo aparecen elementos sin <code>thumbnail_id</code> / imagen destacada asignada.</p>
        </section>

        <section class="seo-images-card" style="margin-top:16px">
            <table class="widefat striped">
                <thead><tr><th style="width:270px">Elemento</th><th>Candidatos relacionados</th></tr></thead>
                <tbody>
                <?php if (empty($items)) : ?><tr><td colspan="2">No hay elementos sin imagen en este grupo.</td></tr>
                <?php else : foreach ($items as $item) :
                    $candidates = seo_images_assignment_find_candidates($scope, $item['id'], 6);
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($item['title']); ?></strong><br><code>#<?php echo esc_html((string) $item['id']); ?></code><?php if (!is_wp_error($item['url']) && $item['url']) : ?><br><a href="<?php echo esc_url($item['url']); ?>" target="_blank" rel="noopener">ver</a><?php endif; ?></td>
                        <td>
                            <?php if (empty($candidates)) : ?><span class="seo-images-danger">No se encontró ninguna imagen relacionada.</span>
                            <?php else : ?><div class="seo-images-candidates">
                                <?php foreach ($candidates as $candidate) :
                                    $is_external = empty($candidate['attachment_id']);
                                ?>
                                    <div class="seo-images-candidate">
                                        <a href="<?php echo esc_url($candidate['url']); ?>" target="_blank" rel="noopener noreferrer" title="Abrir imagen">
                                            <img src="<?php echo esc_url($candidate['url']); ?>" alt="" loading="lazy">
                                        </a>
                                        <span class="seo-images-status <?php echo $is_external ? 'external' : 'local'; ?>"><?php echo $is_external ? 'Externa' : 'Media'; ?></span>
                                        <small title="<?php echo esc_attr($candidate['source_label']); ?>"><?php echo esc_html($candidate['source_label']); ?></small>

                                        <div class="seo-images-candidate-actions">
                                            <?php if ($is_external) : ?>
                                                <a class="seo-images-open-link" href="<?php echo esc_url($candidate['url']); ?>" target="_blank" rel="noopener noreferrer">Abrir imagen para descargar ↗</a>
                                            <?php endif; ?>

                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                <input type="hidden" name="action" value="seo_images_assign_one">
                                                <input type="hidden" name="scope_type" value="<?php echo esc_attr($scope); ?>">
                                                <input type="hidden" name="object_id" value="<?php echo esc_attr((string) $item['id']); ?>">
                                                <input type="hidden" name="candidate_key" value="<?php echo esc_attr($candidate['key']); ?>">
                                                <?php wp_nonce_field('seo_images_assign_' . $scope . '_' . $item['id']); ?>
                                                <button class="button button-small" style="width:100%"><?php echo $is_external ? 'Intentar automático' : 'Asignar'; ?></button>
                                            </form>

                                            <?php if ($is_external) : ?>
                                                <form class="seo-images-manual-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                    <input type="hidden" name="action" value="seo_images_manual_upload">
                                                    <input type="hidden" name="scope_type" value="<?php echo esc_attr($scope); ?>">
                                                    <input type="hidden" name="object_id" value="<?php echo esc_attr((string) $item['id']); ?>">
                                                    <input type="hidden" name="candidate_key" value="<?php echo esc_attr($candidate['key']); ?>">
                                                    <?php wp_nonce_field('seo_images_manual_upload_' . $scope . '_' . $item['id'] . '_' . $candidate['key']); ?>
                                                    <label><strong>Asignación manual</strong></label>
                                                    <input type="file" name="manual_image" accept="image/jpeg,image/png,image/gif,image/webp,image/avif,image/*" required>
                                                    <button class="button button-primary button-small" style="width:100%">Subir y asignar</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </section>
        <?php
    }
}

/**
 * Función pública conservada porque el menú del plugin ya apunta a ella.
 */
if (!function_exists('seo_pictures_admin_page')) {
    function seo_pictures_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'inventory';
        $aliases = array(
            'inventario'             => 'inventory',
            'anomalias'              => 'anomalies',
            'categorias_sin_imagen'  => 'assignment',
            'paginas_sin_imagen'     => 'assignment',
            'conexion'               => 'inventory',
            'escaneo'                => 'errors',
            'errores'                => 'errors',
        );
        if (isset($aliases[$tab])) {
            $tab = $aliases[$tab];
        }
        if (!in_array($tab, array('inventory', 'anomalies', 'assignment', 'errors'), true)) {
            $tab = 'inventory';
        }

        echo '<div class="wrap seo-images-panel">';
        echo '<h1>SEO Imágenes</h1>';
        seo_images_render_styles();
        seo_images_admin_notice();
        $tabs = array(
            'inventory'  => 'Inventario',
            'anomalies'  => 'Anomalías',
            'assignment' => 'Asignación',
            'errors'     => 'Errores',
        );

        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $key => $label) {
            $class = $tab === $key ? ' nav-tab-active' : '';
            echo '<a class="nav-tab' . esc_attr($class) . '" href="' . esc_url(seo_images_admin_url(array('tab' => $key))) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';

        if ($tab === 'anomalies') {
            seo_images_render_anomalies_tab();
        } elseif ($tab === 'assignment') {
            seo_images_render_assignment_tab();
        } elseif ($tab === 'errors' && function_exists('seo_health_render_scope_tab')) {
            seo_health_render_scope_tab('image');
        } else {
            seo_images_render_inventory_tab();
        }

        echo '</div>';
    }
}
