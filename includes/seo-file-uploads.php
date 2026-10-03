<?php

defined('ABSPATH') || exit;

/**
 * Carga las APIs de ficheros de WordPress necesarias para subidas/sideloads.
 */
function seo_taxonomy_require_file_api() {
    if (!function_exists('wp_handle_upload') || !function_exists('wp_handle_sideload') || !function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
}

/**
 * Comprueba que un destino pertenece al directorio de uploads de WordPress.
 */
function seo_taxonomy_upload_destination_is_allowed($directory) {
    $uploads = wp_upload_dir(null, false);
    if (!empty($uploads['error']) || empty($uploads['basedir'])) {
        return false;
    }

    $base = trailingslashit(wp_normalize_path((string) $uploads['basedir']));
    $directory = trailingslashit(wp_normalize_path((string) $directory));

    return strpos($directory, $base) === 0;
}

/**
 * Mueve una subida HTTP a un subdirectorio permitido de uploads utilizando
 * las APIs de WordPress. Devuelve ruta/nombre/URL o WP_Error.
 *
 * @param array  $file            Entrada con la forma de un elemento de $_FILES.
 * @param string $destination_dir Directorio final, siempre dentro de uploads.
 * @param array  $allowed_mimes   Extensión => MIME.
 * @param string $preferred_name  Nombre final opcional.
 * @param bool   $sideload        true para ficheros recibidos por callbacks REST.
 * @param bool   $validated_csv   true solo cuando el llamador ya ha validado
 *                                estructura/cabecera de un CSV de texto.
 */
function seo_taxonomy_store_uploaded_file(array $file, $destination_dir, array $allowed_mimes, $preferred_name = '', $sideload = false, $validated_csv = false) {
    seo_taxonomy_require_file_api();

    if (empty($file['tmp_name']) || empty($file['name'])) {
        return new WP_Error('seo_upload_missing', __('No se ha recibido un archivo válido.', 'seo-taxonomy'));
    }

    $error = isset($file['error']) ? absint($file['error']) : UPLOAD_ERR_OK;
    if (UPLOAD_ERR_OK !== $error) {
        /* translators: %d: código numérico de error de subida PHP. */
        /* translators: %d: código numérico de error de subida PHP. */
        return new WP_Error('seo_upload_error', sprintf(__('La subida devolvió el código de error %d.', 'seo-taxonomy'), $error));
    }

    $destination_dir = wp_normalize_path((string) $destination_dir);
    if (!seo_taxonomy_upload_destination_is_allowed($destination_dir)) {
        return new WP_Error('seo_upload_destination', __('El destino de la subida debe estar dentro del directorio uploads de WordPress.', 'seo-taxonomy'));
    }

    if (!is_dir($destination_dir) && !wp_mkdir_p($destination_dir)) {
        return new WP_Error('seo_upload_mkdir', __('No se pudo crear el directorio de destino.', 'seo-taxonomy'));
    }

    $safe_name = sanitize_file_name($preferred_name !== '' ? $preferred_name : (string) $file['name']);
    if ($safe_name === '') {
        return new WP_Error('seo_upload_filename', __('El nombre del archivo no es válido.', 'seo-taxonomy'));
    }

    $check = wp_check_filetype_and_ext((string) $file['tmp_name'], $safe_name, $allowed_mimes);
    $extension = strtolower((string) pathinfo($safe_name, PATHINFO_EXTENSION));
    $type_check_ok = (
        !empty($check['ext'])
        && !empty($check['type'])
        && array_key_exists($extension, $allowed_mimes)
    );

    /*
     * Un CSV editorial puede contener HTML válido en columnas como description.
     * En ese caso fileinfo/WordPress puede clasificar el fichero completo como
     * text/html aunque su estructura sea CSV. Solo aceptamos esa discrepancia
     * cuando el llamador ya ha validado explícitamente la cabecera y la entidad
     * del CSV antes de entrar aquí.
     */
    $csv_type_fallback = (
        !$type_check_ok
        && $validated_csv
        && 'csv' === $extension
        && isset($allowed_mimes['csv'])
    );

    if (!$type_check_ok && !$csv_type_fallback) {
        return new WP_Error('seo_upload_type', __('El tipo de archivo no está permitido.', 'seo-taxonomy'));
    }

    if ($csv_type_fallback) {
        $check = array(
            'ext'  => 'csv',
            'type' => 'text/csv',
        );
    }

    $normalized = $file;
    $normalized['name'] = $safe_name;

    $overrides = array(
        'test_form' => false,
        'mimes' => $allowed_mimes,
    );

    /*
     * wp_handle_upload() repite la comprobación MIME. Si el CSV fue validado
     * previamente por el importador y solo falló porque contiene HTML en una
     * celda, evitamos repetir exactamente el mismo falso negativo.
     */
    if ($csv_type_fallback) {
        $overrides['test_type'] = false;
    }

    $handled = $sideload
        ? wp_handle_sideload($normalized, $overrides)
        : wp_handle_upload($normalized, $overrides);

    if (!is_array($handled) || !empty($handled['error']) || empty($handled['file'])) {
        return new WP_Error(
            'seo_upload_handle',
            !empty($handled['error']) ? sanitize_text_field((string) $handled['error']) : __('WordPress no pudo procesar la subida.', 'seo-taxonomy')
        );
    }

    $temporary_path = wp_normalize_path((string) $handled['file']);
    $final_name = wp_unique_filename($destination_dir, $safe_name);
    $final_path = trailingslashit($destination_dir) . $final_name;

    global $wp_filesystem;
    if (!WP_Filesystem() || !is_object($wp_filesystem)) {
        wp_delete_file($temporary_path);
        return new WP_Error('seo_upload_filesystem', __('No se pudo inicializar el sistema de archivos de WordPress.', 'seo-taxonomy'));
    }

    if (!$wp_filesystem->move($temporary_path, $final_path, true)) {
        wp_delete_file($temporary_path);
        return new WP_Error('seo_upload_move', __('No se pudo mover el archivo al directorio de destino.', 'seo-taxonomy'));
    }

    $uploads = wp_upload_dir(null, false);
    $relative = ltrim(str_replace(wp_normalize_path((string) $uploads['basedir']), '', wp_normalize_path($final_path)), '/');

    return array(
        'path' => wp_normalize_path($final_path),
        'name' => $final_name,
        'url' => trailingslashit((string) $uploads['baseurl']) . str_replace('%2F', '/', rawurlencode($relative)),
        'type' => (string) $check['type'],
    );
}
