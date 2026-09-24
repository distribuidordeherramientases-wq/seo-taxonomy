<?php
/**
 * Comentarista - integracion con Importar / Exportar.
 *
 * Los controles se muestran exclusivamente en la pantalla general de
 * Importar / Exportar. La pantalla de Comentarista no duplica estos botones.
 */

defined('ABSPATH') || exit;

add_action('admin_post_seo_comentarista_export_csv', 'seo_comentarista_export_csv');
add_action('admin_post_seo_comentarista_import_csv', 'seo_comentarista_import_csv');
add_action('admin_init', 'seo_comentarista_maybe_handle_legacy_csv_post');
add_action('admin_notices', 'seo_comentarista_import_admin_notice');

/**
 * Compatibilidad con formularios antiguos que enviaban el POST a la propia
 * pantalla en lugar de admin-post.php.
 */
function seo_comentarista_maybe_handle_legacy_csv_post()
{
    if (!empty($_POST['action'])) {
        return;
    }

    if (isset($_POST['seo_export_comentarista'])) {
        seo_comentarista_export_csv();
    }

    if (isset($_POST['seo_import_comentarista'])) {
        seo_comentarista_import_csv();
    }
}

function seo_comentarista_import_notice_key()
{
    return 'seo_comentarista_import_notice_' . get_current_user_id();
}

function seo_comentarista_import_last_result_key()
{
    return 'seo_comentarista_last_import_result';
}

function seo_comentarista_import_counts()
{
    global $wpdb;

    if (!seo_comentarista_table_exists()) {
        return array('records' => 0, 'comments' => 0);
    }

    $row = $wpdb->get_row(
        "SELECT COUNT(*) AS records, SUM(CASE WHEN content_type = 'comment' THEN 1 ELSE 0 END) AS comments FROM " . seo_comentarista_table_name(),
        ARRAY_A
    );

    return array(
        'records'  => (int) ($row['records'] ?? 0),
        'comments' => (int) ($row['comments'] ?? 0),
    );
}

function seo_comentarista_import_add_detail(&$log, $message)
{
    if (function_exists('seo_ie_add_log_detail')) {
        seo_ie_add_log_detail($log, $message);
        return;
    }

    if (!isset($log['detalles']) || !is_array($log['detalles'])) {
        $log['detalles'] = array();
    }

    if (count($log['detalles']) < 50) {
        $log['detalles'][] = sanitize_text_field((string) $message);
    }
}

function seo_comentarista_import_store_result($result)
{
    $result = is_array($result) ? $result : array();
    $result['stored_at'] = current_time('mysql');

    set_transient(seo_comentarista_import_notice_key(), $result, 15 * MINUTE_IN_SECONDS);
    update_user_meta(get_current_user_id(), seo_comentarista_import_last_result_key(), $result);
}

function seo_comentarista_import_get_return_url($return_to_comentarista)
{
    if ($return_to_comentarista) {
        return add_query_arg(
            array(
                'page'                           => 'seo-comentarista',
                'view'                           => 'import-export',
                'seo_comentarista_import_result' => '1',
            ),
            admin_url('admin.php')
        );
    }

    return add_query_arg(
        array(
            'page'                           => 'seo-import-export',
            'seo_ie_tab'                     => 'wordpress',
            'seo_ie_imported'                => 'comentarista',
            'seo_comentarista_import_result' => '1',
        ),
        admin_url('admin.php')
    );
}

function seo_comentarista_import_finish($log, $return_to_comentarista, $status = 'success', $message = '')
{
    if (function_exists('seo_ie_store_log')) {
        seo_ie_store_log($log);
    }

    $result = array(
        'status'             => sanitize_key($status),
        'message'            => sanitize_text_field((string) $message),
        'archivo'            => sanitize_file_name((string) ($log['archivo'] ?? '')),
        'procesados'         => (int) ($log['procesados'] ?? 0),
        'correctos'          => (int) ($log['correctos'] ?? 0),
        'creados'            => (int) ($log['creados'] ?? 0),
        'actualizados'       => (int) ($log['actualizados'] ?? 0),
        'errores'            => (int) ($log['errores'] ?? 0),
        'registros_antes'    => (int) ($log['registros_antes'] ?? 0),
        'registros_despues'  => (int) ($log['registros_despues'] ?? 0),
        'comentarios_antes'  => (int) ($log['comentarios_antes'] ?? 0),
        'comentarios_despues'=> (int) ($log['comentarios_despues'] ?? 0),
        'detalles'           => array_values(array_slice((array) ($log['detalles'] ?? array()), 0, 10)),
    );

    seo_comentarista_import_store_result($result);
    wp_safe_redirect(seo_comentarista_import_get_return_url($return_to_comentarista));
    exit;
}

function seo_comentarista_import_admin_notice()
{
    if (empty($_GET['seo_comentarista_import_result'])) {
        return;
    }

    $result = get_transient(seo_comentarista_import_notice_key());
    delete_transient(seo_comentarista_import_notice_key());

    if (!is_array($result)) {
        return;
    }

    $status = sanitize_key((string) ($result['status'] ?? 'success'));
    $class = $status === 'error' ? 'notice-error' : ($status === 'warning' ? 'notice-warning' : 'notice-success');
    $message = trim((string) ($result['message'] ?? ''));

    if ($message === '') {
        $message = sprintf(
            'Importación de Comentarista: %d procesados, %d creados, %d actualizados, %d errores. Comentarios: %d → %d.',
            (int) ($result['procesados'] ?? 0),
            (int) ($result['creados'] ?? 0),
            (int) ($result['actualizados'] ?? 0),
            (int) ($result['errores'] ?? 0),
            (int) ($result['comentarios_antes'] ?? 0),
            (int) ($result['comentarios_despues'] ?? 0)
        );
    }

    echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p><strong>Comentarista:</strong> ' . esc_html($message) . '</p></div>';
}

/**
 * Resuelve el producto de una fila importada. El ID tiene prioridad si es válido;
 * el SKU permite transportar el CSV entre instalaciones donde cambien los IDs.
 *
 * @param array $row
 * @return int
 */
function seo_comentarista_import_resolve_product_id($row)
{
    $product_id = absint($row['product_id'] ?? 0);
    if ($product_id && seo_comentarista_validate_product($product_id)) {
        return $product_id;
    }

    $sku = trim((string) ($row['product_sku'] ?? ''));
    if ($sku !== '' && function_exists('wc_get_product_id_by_sku')) {
        $by_sku = absint(wc_get_product_id_by_sku($sku));
        if ($by_sku && seo_comentarista_validate_product($by_sku)) {
            return $by_sku;
        }
    }

    return 0;
}

/**
 * Exporta la tabla de Comentarista a CSV.
 */
function seo_comentarista_export_csv()
{
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
        'product_sku',
        'product_name',
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
        $product = function_exists('wc_get_product') ? wc_get_product((int) $row['product_id']) : null;
        $product_sku = $product ? (string) $product->get_sku() : '';
        $product_name = $product ? (string) $product->get_name() : (string) get_the_title((int) $row['product_id']);

        seo_ie_write_csv_row($output, array(
            (int) $row['id'],
            (int) $row['product_id'],
            $product_sku,
            $product_name,
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
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para importar Comentarista.', 'seo-system'));
    }

    check_admin_referer('seo_import_comentarista_csv', 'seo_import_comentarista_nonce');

    $return_to_comentarista = !empty($_POST['seo_comentarista_return'])
        && sanitize_key(wp_unslash($_POST['seo_comentarista_return'])) === 'comentarista';

    $log = array(
        'operacion'          => 'Importación de Comentarista',
        'archivo'            => sanitize_file_name($_FILES['comentarista_csv']['name'] ?? ''),
        'procesados'         => 0,
        'correctos'          => 0,
        'creados'            => 0,
        'actualizados'       => 0,
        'errores'            => 0,
        'registros_antes'    => 0,
        'registros_despues'  => 0,
        'comentarios_antes'  => 0,
        'comentarios_despues'=> 0,
        'detalles'           => array(),
    );

    if (
        empty($_FILES['comentarista_csv']['tmp_name'])
        || !is_uploaded_file($_FILES['comentarista_csv']['tmp_name'])
    ) {
        $log['errores']++;
        seo_comentarista_import_add_detail($log, 'No se recibió un archivo CSV válido.');
        seo_comentarista_import_finish(
            $log,
            $return_to_comentarista,
            'error',
            'No se ha recibido un CSV válido de Comentarista.'
        );
    }

    if (!function_exists('seo_ie_read_csv_row') || !function_exists('seo_ie_build_csv_row')) {
        $log['errores']++;
        seo_comentarista_import_add_detail($log, 'El motor Importar / Exportar no está disponible.');
        seo_comentarista_import_finish(
            $log,
            $return_to_comentarista,
            'error',
            'El motor Importar / Exportar no está disponible.'
        );
    }

    $install = seo_comentarista_maybe_install_schema();
    if (is_wp_error($install)) {
        $log['errores']++;
        seo_comentarista_import_add_detail($log, $install->get_error_message());
        seo_comentarista_import_finish($log, $return_to_comentarista, 'error', $install->get_error_message());
    }

    $before = seo_comentarista_import_counts();
    $log['registros_antes'] = $before['records'];
    $log['comentarios_antes'] = $before['comments'];

    $handle = fopen($_FILES['comentarista_csv']['tmp_name'], 'r');
    if ($handle === false) {
        $log['errores']++;
        seo_comentarista_import_add_detail($log, 'No se pudo abrir el CSV.');
        seo_comentarista_import_finish($log, $return_to_comentarista, 'error', 'No se pudo abrir el CSV de Comentarista.');
    }

    $header = seo_ie_read_csv_row($handle);
    if ($header === false) {
        fclose($handle);
        $log['errores']++;
        seo_comentarista_import_add_detail($log, 'El CSV está vacío.');
        seo_comentarista_import_finish($log, $return_to_comentarista, 'error', 'El CSV de Comentarista está vacío.');
    }

    $header = array_map(
        static function ($value) {
            $value = function_exists('seo_ie_csv_to_utf8') ? seo_ie_csv_to_utf8((string) $value) : (string) $value;
            return sanitize_key(trim($value));
        },
        $header
    );

    if (count($header) === 1 && strpos((string) $header[0], ',') !== false) {
        fclose($handle);
        $log['errores']++;
        seo_comentarista_import_add_detail($log, 'Separador incorrecto: el importador espera punto y coma (;).');
        seo_comentarista_import_finish(
            $log,
            $return_to_comentarista,
            'error',
            'El CSV parece usar coma como separador. Comentarista espera punto y coma (;).'
        );
    }

    $non_empty_headers = array_values(array_filter($header, static function ($value) {
        return $value !== '';
    }));
    if (count($non_empty_headers) !== count(array_unique($non_empty_headers))) {
        fclose($handle);
        $log['errores']++;
        seo_comentarista_import_add_detail($log, 'La cabecera contiene nombres de columna duplicados.');
        seo_comentarista_import_finish(
            $log,
            $return_to_comentarista,
            'error',
            'La cabecera del CSV contiene columnas duplicadas.'
        );
    }

    if (!in_array('content_type', $header, true)) {
        fclose($handle);
        $log['errores']++;
        seo_comentarista_import_add_detail($log, 'Falta la columna obligatoria content_type.');
        seo_comentarista_import_finish($log, $return_to_comentarista, 'error', 'Falta la columna obligatoria content_type.');
    }
    if (!in_array('product_id', $header, true) && !in_array('product_sku', $header, true)) {
        fclose($handle);
        $log['errores']++;
        seo_comentarista_import_add_detail($log, 'Falta product_id o product_sku.');
        seo_comentarista_import_finish($log, $return_to_comentarista, 'error', 'El CSV debe incluir product_id o product_sku.');
    }

    seo_comentarista_import_add_detail($log, sprintf('Cabecera válida: %d columnas.', count($header)));

    $line = 1;
    while (false !== ($csv_row = seo_ie_read_csv_row($handle))) {
        $line++;

        if (empty(array_filter($csv_row, static function ($value) {
            return trim((string) $value) !== '';
        }))) {
            continue;
        }

        $log['procesados']++;

        if (count($csv_row) !== count($header)) {
            $log['errores']++;
            seo_comentarista_import_add_detail(
                $log,
                sprintf(
                    'Fila %d: número de columnas incorrecto (%d recibidas, %d esperadas).',
                    $line,
                    count($csv_row),
                    count($header)
                )
            );
            continue;
        }

        try {
            $row = seo_ie_build_csv_row($header, $csv_row);
            $resolved_product_id = seo_comentarista_import_resolve_product_id($row);

            if (!$resolved_product_id) {
                $log['errores']++;
                seo_comentarista_import_add_detail(
                    $log,
                    sprintf('Fila %d: no se pudo resolver el producto por product_id/product_sku.', $line)
                );
                continue;
            }

            $data = array(
                'product_id'          => $resolved_product_id,
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
            $match_reason = $existing ? 'comentarista_id' : '';

            if (!$existing && !empty($data['external_id']) && function_exists('seo_comentarista_get_by_external_id')) {
                $existing = seo_comentarista_get_by_external_id(
                    $data['product_id'],
                    $data['source_platform'],
                    $data['external_id']
                );
                if ($existing) {
                    $id = (int) $existing['id'];
                    $match_reason = 'external_id';
                }
            }

            if ($existing) {
                $result = seo_comentarista_update($id, $data);
                if (is_wp_error($result)) {
                    $log['errores']++;
                    seo_comentarista_import_add_detail(
                        $log,
                        sprintf('Fila %d, registro %d: %s', $line, $id, $result->get_error_message())
                    );
                    continue;
                }

                $log['actualizados']++;
                $log['correctos']++;

                if ($log['actualizados'] <= 5) {
                    seo_comentarista_import_add_detail(
                        $log,
                        sprintf('Fila %d: actualizado registro %d por coincidencia de %s.', $line, $id, $match_reason ?: 'identidad')
                    );
                }
                continue;
            }

            $result = seo_comentarista_insert($data);
            if (is_wp_error($result)) {
                $log['errores']++;
                seo_comentarista_import_add_detail($log, sprintf('Fila %d: %s', $line, $result->get_error_message()));
                continue;
            }

            $log['creados']++;
            $log['correctos']++;
        } catch (Throwable $e) {
            $log['errores']++;
            seo_comentarista_import_add_detail(
                $log,
                sprintf('Fila %d: error inesperado: %s', $line, $e->getMessage())
            );
        }
    }

    fclose($handle);

    $after = seo_comentarista_import_counts();
    $log['registros_despues'] = $after['records'];
    $log['comentarios_despues'] = $after['comments'];

    $status = $log['errores'] > 0
        ? ($log['correctos'] > 0 ? 'warning' : 'error')
        : 'success';

    $message = sprintf(
        'Importación completada: %d procesados, %d creados, %d actualizados y %d errores. Comentarios: %d → %d.',
        $log['procesados'],
        $log['creados'],
        $log['actualizados'],
        $log['errores'],
        $log['comentarios_antes'],
        $log['comentarios_despues']
    );

    if ($log['creados'] === 0 && $log['actualizados'] > 0 && $log['errores'] === 0) {
        $message .= ' El recuento no aumenta porque todas las filas coincidieron con registros ya existentes y se actualizaron.';
    } elseif ($log['errores'] > 0 && !empty($log['detalles'])) {
        foreach ($log['detalles'] as $detail) {
            if (stripos((string) $detail, 'Fila ') === 0 && stripos((string) $detail, 'actualizado') === false) {
                $message .= ' Primer error: ' . $detail;
                break;
            }
        }
    }

    seo_comentarista_import_add_detail(
        $log,
        sprintf(
            'Resultado: registros %d → %d; comentarios %d → %d.',
            $log['registros_antes'],
            $log['registros_despues'],
            $log['comentarios_antes'],
            $log['comentarios_despues']
        )
    );

    seo_comentarista_import_finish($log, $return_to_comentarista, $status, $message);
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
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="seo_comentarista_export_csv">
            <?php wp_nonce_field('seo_export_comentarista_csv', 'seo_export_comentarista_nonce'); ?>
            <button type="submit" name="seo_export_comentarista" value="1" class="button button-primary">Exportar Comentarista</button>
        </form>
    </div>

    <div class="card" style="max-width:none;padding:20px;">
        <h2>Importar Comentarista</h2>
        <p>Actualiza por <code>comentarista_id</code>; si no existe y hay <code>external_id</code>, intenta localizar la misma evidencia por producto + plataforma + ID externo. Si no encuentra coincidencia, crea un registro nuevo.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="seo_comentarista_import_csv">
            <?php wp_nonce_field('seo_import_comentarista_csv', 'seo_import_comentarista_nonce'); ?>
            <?php if (!empty($_GET['page']) && sanitize_key(wp_unslash($_GET['page'])) === 'seo-comentarista') : ?>
                <input type="hidden" name="seo_comentarista_return" value="comentarista">
            <?php endif; ?>
            <input type="file" name="comentarista_csv" accept=".csv,text/csv" required>
            <p class="description">Obligatoria: <code>content_type</code> y al menos uno de <code>product_id</code> o <code>product_sku</code>. Si existe <code>external_id</code>, se usa con producto + plataforma para evitar duplicados en importaciones repetidas. Para vídeos/social/artículos/enlaces también se requiere <code>source_url</code>.</p>
            <p><button type="submit" name="seo_import_comentarista" value="1" class="button button-primary">Importar Comentarista</button></p>
        </form>
        <?php $seo_comentarista_last_import = get_user_meta(get_current_user_id(), seo_comentarista_import_last_result_key(), true); ?>
        <?php if (is_array($seo_comentarista_last_import) && !empty($seo_comentarista_last_import['stored_at'])) : ?>
            <p class="description" style="margin-top:12px;">
                <strong>Última importación:</strong>
                <?php echo esc_html(sprintf(
                    '%s · %d procesados · %d creados · %d actualizados · %d errores · comentarios %d → %d',
                    (string) $seo_comentarista_last_import['stored_at'],
                    (int) ($seo_comentarista_last_import['procesados'] ?? 0),
                    (int) ($seo_comentarista_last_import['creados'] ?? 0),
                    (int) ($seo_comentarista_last_import['actualizados'] ?? 0),
                    (int) ($seo_comentarista_last_import['errores'] ?? 0),
                    (int) ($seo_comentarista_last_import['comentarios_antes'] ?? 0),
                    (int) ($seo_comentarista_last_import['comentarios_despues'] ?? 0)
                )); ?>
            </p>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Pantalla propia de Importar / Exportar dentro de Comentarista.
 * Mantiene la compatibilidad con la pantalla global del sistema, pero evita
 * depender de ella para administrar los comentarios de productos.
 */
function seo_comentarista_import_export_admin_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para acceder a esta página.', 'seo-system'));
    }

    ?>
    <div class="wrap">
        <h1>Comentarista</h1>
        <?php if (function_exists('seo_comentarista_admin_tabs')) : ?>
            <?php seo_comentarista_admin_tabs('import-export'); ?>
        <?php endif; ?>
        <p>Importa o exporta evidencias externas asociadas a productos. El CSV incluye ID, SKU y nombre del producto para facilitar su traslado entre entornos.</p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:20px;max-width:1100px;">
            <?php seo_comentarista_render_import_export_cards(); ?>
        </div>
    </div>
    <?php
}

