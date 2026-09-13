<?php
/**
 * Comentarista - integracion con Importar / Exportar.
 *
 * Los controles se muestran exclusivamente en la pantalla general de
 * Importar / Exportar. La pantalla de Comentarista no duplica estos botones.
 */

defined('ABSPATH') || exit;

add_action('admin_init', 'seo_comentarista_export_csv');
add_action('admin_init', 'seo_comentarista_import_csv');

/**
 * Exporta la tabla de Comentarista a CSV.
 */
function seo_comentarista_export_csv()
{
    if (!isset($_POST['seo_export_comentarista'])) {
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para exportar Comentarista.', 'seo-system'));
    }

    check_admin_referer('seo_export_comentarista_csv', 'seo_export_comentarista_nonce');

    if (!seo_comentarista_table_exists()) {
        wp_die(esc_html__('La tabla de Comentarista no existe.', 'seo-system'));
    }

    if (!function_exists('seo_ie_open_csv_download') || !function_exists('seo_ie_write_csv_row')) {
        wp_die(esc_html__('El motor Importar / Exportar no está disponible.', 'seo-system'));
    }

    global $wpdb;
    $rows = (array) $wpdb->get_results(
        'SELECT * FROM ' . seo_comentarista_table_name() . ' ORDER BY product_id ASC, display_order ASC, id ASC',
        ARRAY_A
    );

    $filename = 'seo_comentarista_' . wp_date('Ymd_His') . '.csv';

    if (function_exists('seo_ie_store_log')) {
        seo_ie_store_log(array(
            'operacion'  => 'Exportación de Comentarista',
            'archivo'    => $filename,
            'procesados' => count($rows),
            'correctos'  => count($rows),
            'errores'    => 0,
            'detalles'   => array('Se exportaron los contenidos externos asociados a productos.'),
        ));
    }

    $output = seo_ie_open_csv_download($filename);
    $headers = array(
        'comentarista_id',
        'product_id',
        'content_type',
        'source_platform',
        'source_name',
        'source_url',
        'external_id',
        'source_title',
        'author_name',
        'author_url',
        'source_published_at',
        'captured_at',
        'source_content',
        'editorial_summary',
        'rating_value',
        'rating_scale',
        'embed_url',
        'thumbnail_url',
        'status',
        'display_order',
    );
    seo_ie_write_csv_row($output, $headers);

    foreach ($rows as $row) {
        seo_ie_write_csv_row($output, array(
            (int) $row['id'],
            (int) $row['product_id'],
            (string) $row['content_type'],
            (string) $row['source_platform'],
            (string) ($row['source_name'] ?? ''),
            (string) ($row['source_url'] ?? ''),
            (string) ($row['external_id'] ?? ''),
            (string) ($row['source_title'] ?? ''),
            (string) ($row['author_name'] ?? ''),
            (string) ($row['author_url'] ?? ''),
            (string) ($row['source_published_at'] ?? ''),
            (string) ($row['captured_at'] ?? ''),
            (string) ($row['source_content'] ?? ''),
            (string) ($row['editorial_summary'] ?? ''),
            $row['rating_value'] === null ? '' : (string) $row['rating_value'],
            $row['rating_scale'] === null ? '' : (string) $row['rating_scale'],
            (string) ($row['embed_url'] ?? ''),
            (string) ($row['thumbnail_url'] ?? ''),
            (string) $row['status'],
            (int) $row['display_order'],
        ));
    }

    fclose($output);
    exit;
}

/**
 * Importa registros de Comentarista desde el CSV exportado por el sistema.
 * Actualiza por comentarista_id cuando existe; en caso contrario crea una fila.
 */
function seo_comentarista_import_csv()
{
    if (!isset($_POST['seo_import_comentarista'])) {
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para importar Comentarista.', 'seo-system'));
    }

    check_admin_referer('seo_import_comentarista_csv', 'seo_import_comentarista_nonce');

    if (
        empty($_FILES['comentarista_csv']['tmp_name'])
        || !is_uploaded_file($_FILES['comentarista_csv']['tmp_name'])
    ) {
        wp_die(esc_html__('No se ha recibido un CSV válido de Comentarista.', 'seo-system'));
    }

    if (!function_exists('seo_ie_read_csv_row') || !function_exists('seo_ie_build_csv_row')) {
        wp_die(esc_html__('El motor Importar / Exportar no está disponible.', 'seo-system'));
    }

    $install = seo_comentarista_maybe_install_schema();
    if (is_wp_error($install)) {
        wp_die(esc_html($install->get_error_message()));
    }

    $handle = fopen($_FILES['comentarista_csv']['tmp_name'], 'r');
    if ($handle === false) {
        wp_die(esc_html__('No se pudo abrir el CSV de Comentarista.', 'seo-system'));
    }

    $header = seo_ie_read_csv_row($handle);
    if ($header === false) {
        fclose($handle);
        wp_die(esc_html__('El CSV de Comentarista está vacío.', 'seo-system'));
    }

    $header = array_map(
        static function ($value) {
            $value = function_exists('seo_ie_csv_to_utf8') ? seo_ie_csv_to_utf8((string) $value) : (string) $value;
            return sanitize_key(trim($value));
        },
        $header
    );

    foreach (array('product_id', 'content_type') as $required) {
        if (!in_array($required, $header, true)) {
            fclose($handle);
            wp_die(sprintf(esc_html__('Falta la columna obligatoria %s.', 'seo-system'), esc_html($required)));
        }
    }

    $log = array(
        'operacion'    => 'Importación de Comentarista',
        'archivo'      => sanitize_file_name($_FILES['comentarista_csv']['name']),
        'procesados'   => 0,
        'correctos'    => 0,
        'creados'      => 0,
        'actualizados' => 0,
        'errores'      => 0,
        'detalles'     => array(),
    );

    $line = 1;
    while (false !== ($csv_row = seo_ie_read_csv_row($handle))) {
        $line++;

        if (empty(array_filter($csv_row, static function ($value) {
            return trim((string) $value) !== '';
        }))) {
            continue;
        }

        $log['procesados']++;
        $row = seo_ie_build_csv_row($header, $csv_row);

        $data = array(
            'product_id'          => absint($row['product_id'] ?? 0),
            'content_type'        => sanitize_key($row['content_type'] ?? 'comment'),
            'source_platform'     => sanitize_key($row['source_platform'] ?? 'web'),
            'source_name'         => (string) ($row['source_name'] ?? ''),
            'source_url'          => (string) ($row['source_url'] ?? ''),
            'external_id'         => (string) ($row['external_id'] ?? ''),
            'source_title'        => (string) ($row['source_title'] ?? ''),
            'author_name'         => (string) ($row['author_name'] ?? ''),
            'author_url'          => (string) ($row['author_url'] ?? ''),
            'source_published_at' => (string) ($row['source_published_at'] ?? ''),
            'captured_at'         => (string) ($row['captured_at'] ?? ''),
            'source_content'      => (string) ($row['source_content'] ?? ''),
            'editorial_summary'   => (string) ($row['editorial_summary'] ?? ''),
            'rating_value'        => (string) ($row['rating_value'] ?? ''),
            'rating_scale'        => (string) ($row['rating_scale'] ?? ''),
            'embed_url'           => (string) ($row['embed_url'] ?? ''),
            'thumbnail_url'       => (string) ($row['thumbnail_url'] ?? ''),
            'status'              => sanitize_key($row['status'] ?? 'published'),
            'display_order'       => intval($row['display_order'] ?? 0),
        );

        $data = seo_comentarista_enrich_source_data($data);
        $id = absint($row['comentarista_id'] ?? 0);
        $existing = $id ? seo_comentarista_get($id) : null;

        if ($existing) {
            $result = seo_comentarista_update($id, $data);
            if (is_wp_error($result)) {
                $log['errores']++;
                if (function_exists('seo_ie_add_log_detail')) {
                    seo_ie_add_log_detail($log, sprintf('Fila %d, registro %d: %s', $line, $id, $result->get_error_message()));
                }
                continue;
            }
            $log['actualizados']++;
            $log['correctos']++;
            continue;
        }

        $result = seo_comentarista_insert($data);
        if (is_wp_error($result)) {
            $log['errores']++;
            if (function_exists('seo_ie_add_log_detail')) {
                seo_ie_add_log_detail($log, sprintf('Fila %d: %s', $line, $result->get_error_message()));
            }
            continue;
        }

        $log['creados']++;
        $log['correctos']++;
    }

    fclose($handle);

    if (function_exists('seo_ie_store_log')) {
        seo_ie_store_log($log);
    }

    wp_safe_redirect(
        add_query_arg(
            array('page' => 'seo-import-export', 'seo_ie_tab' => 'wordpress', 'seo_ie_imported' => 'comentarista'),
            admin_url('admin.php')
        )
    );
    exit;
}

/**
 * Tarjetas de Comentarista para la pantalla general Importar / Exportar.
 */
function seo_comentarista_render_import_export_cards()
{
    ?>
    <div class="card" style="max-width:none;padding:20px;">
        <h2>Exportar Comentarista</h2>
        <p>Exporta comentarios capturados, vídeos, publicaciones sociales, artículos y enlaces asociados a productos.</p>
        <form method="post">
            <?php wp_nonce_field('seo_export_comentarista_csv', 'seo_export_comentarista_nonce'); ?>
            <button type="submit" name="seo_export_comentarista" value="1" class="button button-primary">Exportar Comentarista</button>
        </form>
    </div>

    <div class="card" style="max-width:none;padding:20px;">
        <h2>Importar Comentarista</h2>
        <p>Actualiza por <code>comentarista_id</code> cuando existe o crea un registro nuevo. El CSV puede contener distintos tipos de contenido externo.</p>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('seo_import_comentarista_csv', 'seo_import_comentarista_nonce'); ?>
            <input type="file" name="comentarista_csv" accept=".csv,text/csv" required>
            <p class="description">Obligatorias: <code>product_id</code> y <code>content_type</code>. Para vídeos/social/artículos/enlaces también se requiere <code>source_url</code>.</p>
            <p><button type="submit" name="seo_import_comentarista" value="1" class="button button-primary">Importar Comentarista</button></p>
        </form>
    </div>
    <?php
}
