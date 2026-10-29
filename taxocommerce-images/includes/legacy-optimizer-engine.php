<?php
/**
 * TaxoCommerce Images: legacy non-destructive local Media optimizer.
 *
 * Re-comprime los ficheros existentes manteniendo exactamente sus nombres,
 * URLs y dimensiones. El original solo se sustituye cuando el resultado es
 * valido y ocupa menos bytes. No convierte formatos.
 *
 * Copied from the original production image checks; isolated function prefix.
 */

defined('ABSPATH') || exit;

if (!defined('TCI_IMAGES_OPTIMIZER_VERSION')) {
    define('TCI_IMAGES_OPTIMIZER_VERSION', '1');
}

if (!function_exists('taxocommerce_images_optimizer_read_head')) {
    /**
     * Lee un prefijo binario de un archivo local de uploads.
     *
     * @param string $path Ruta del archivo.
     * @param int    $length Bytes máximos.
     * @return string|false
     */
    function taxocommerce_images_optimizer_read_head($path, $length) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Lectura binaria parcial; se limita a uploads y evita cargar archivos completos.
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Lectura binaria parcial del mismo recurso local.
        $head = @fread($handle, max(0, (int) $length));
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cierre explícito del recurso abierto arriba.
        @fclose($handle);
        return $head;
    }
}

if (!function_exists('taxocommerce_images_optimizer_file_is_writable')) {
    /**
     * Comprueba si el archivo puede sustituirse sin cambiar la semántica actual.
     *
     * @param string $path Ruta del archivo.
     * @return bool
     */
    function taxocommerce_images_optimizer_file_is_writable($path) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- La comprobación aplica al archivo concreto de uploads antes de una sustitución atómica.
        return is_writable($path);
    }
}

if (!function_exists('taxocommerce_images_optimizer_rename')) {
    /**
     * Renombra un archivo local conservando la operación atómica usada por el optimizador.
     *
     * @param string $source Origen.
     * @param string $target Destino.
     * @return bool
     */
    function taxocommerce_images_optimizer_rename($source, $target) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- El optimizador necesita renombrado atómico para backup/restauración dentro de uploads.
        return @rename($source, $target);
    }
}

if (!function_exists('taxocommerce_images_optimizer_chmod')) {
    /**
     * Restaura permisos del archivo reemplazado.
     *
     * @param string $path Ruta.
     * @param int    $mode Permisos.
     * @return bool
     */
    function taxocommerce_images_optimizer_chmod($path, $mode) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Se restauran exactamente los permisos previos del archivo local optimizado.
        return @chmod($path, $mode);
    }
}

if (!function_exists('taxocommerce_images_optimizer_supported_mimes')) {
    function taxocommerce_images_optimizer_supported_mimes() {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Preserve older custom MIME hooks without removing them.
        $legacy_mimes = apply_filters('tci_optimizer_supported_mimes', array('image/jpeg', 'image/png', 'image/webp', 'image/avif'));
        return (array) apply_filters('taxocommerce_images_optimizer_supported_mimes', $legacy_mimes);
    }
}

if (!function_exists('taxocommerce_images_optimizer_quality')) {
    function taxocommerce_images_optimizer_quality($mime_type) {
        $qualities = array(
            'image/jpeg' => 80,
            'image/png'  => 50,
            'image/webp' => 80,
            'image/avif' => 65,
        );

        $quality = isset($qualities[$mime_type]) ? $qualities[$mime_type] : 80;
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Existing quality filters are retained for compatibility.
        $legacy_quality = apply_filters('tci_optimizer_quality', $quality, $mime_type);
        return max(1, min(100, (int) apply_filters('taxocommerce_images_optimizer_quality', $legacy_quality, $mime_type)));
    }
}

if (!function_exists('taxocommerce_images_optimizer_is_safe_upload_path')) {
    function taxocommerce_images_optimizer_is_safe_upload_path($path) {
        $uploads = wp_get_upload_dir();
        if (empty($uploads['basedir'])) {
            return false;
        }

        $file = realpath($path);
        $base = realpath($uploads['basedir']);
        if (!$file || !$base) {
            return false;
        }

        $file = wp_normalize_path($file);
        $base = trailingslashit(wp_normalize_path($base));
        return strpos($file, $base) === 0;
    }
}

if (!function_exists('taxocommerce_images_optimizer_file_mime')) {
    function taxocommerce_images_optimizer_file_mime($path) {
        if (function_exists('wp_get_image_mime')) {
            $mime = wp_get_image_mime($path);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        $info = @getimagesize($path);
        return is_array($info) && !empty($info['mime']) ? (string) $info['mime'] : '';
    }
}

if (!function_exists('taxocommerce_images_optimizer_has_unsafe_orientation')) {
    function taxocommerce_images_optimizer_has_unsafe_orientation($path, $mime_type) {
        if ($mime_type !== 'image/jpeg') {
            return false;
        }

        if (!function_exists('wp_read_image_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        if (!function_exists('wp_read_image_metadata')) {
            return false;
        }

        $metadata = wp_read_image_metadata($path);
        if (!is_array($metadata) || empty($metadata['orientation'])) {
            return false;
        }

        return (int) $metadata['orientation'] > 1;
    }
}

if (!function_exists('taxocommerce_images_optimizer_is_animated_file')) {
    function taxocommerce_images_optimizer_is_animated_file($path, $mime_type) {
        if (!in_array($mime_type, array('image/webp', 'image/png'), true)) {
            return false;
        }

        $size = @filesize($path);
        if (!$size || $size < 16) {
            return false;
        }

        $read_length = min((int) $size, 2 * MB_IN_BYTES);
        $head = taxocommerce_images_optimizer_read_head($path, $read_length);
        if (!is_string($head) || $head === '') {
            return false;
        }

        if ($mime_type === 'image/webp') {
            return strpos($head, 'ANIM') !== false || strpos($head, 'ANMF') !== false;
        }

        // APNG incluye el chunk de control de animacion acTL.
        return strpos($head, 'acTL') !== false;
    }
}

if (!function_exists('taxocommerce_images_optimizer_attachment_files')) {
    function taxocommerce_images_optimizer_attachment_files($attachment_id) {
        $attachment_id = absint($attachment_id);
        $attached_file = get_attached_file($attachment_id, true);
        if (!$attached_file) {
            return array();
        }

        $files = array();
        $files[] = array(
            'path' => $attached_file,
            'type' => 'full',
            'size_key' => '',
        );

        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!is_array($metadata)) {
            $metadata = array();
        }

        $directory = dirname($attached_file);

        if (!empty($metadata['original_image'])) {
            $files[] = array(
                'path' => path_join($directory, wp_basename($metadata['original_image'])),
                'type' => 'original_image',
                'size_key' => '',
            );
        }

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_key => $size_data) {
                if (!is_array($size_data) || empty($size_data['file'])) {
                    continue;
                }
                $files[] = array(
                    'path' => path_join($directory, wp_basename($size_data['file'])),
                    'type' => 'size',
                    'size_key' => sanitize_key($size_key),
                );
            }
        }

        $unique = array();
        foreach ($files as $file) {
            $real = realpath($file['path']);
            if (!$real || !is_file($real) || !is_readable($real)) {
                continue;
            }
            $normalized = wp_normalize_path($real);
            if (isset($unique[$normalized])) {
                continue;
            }
            if (!taxocommerce_images_optimizer_is_safe_upload_path($real)) {
                continue;
            }
            $file['path'] = $real;
            $unique[$normalized] = $file;
        }

        return array_values($unique);
    }
}

if (!function_exists('taxocommerce_images_optimizer_signature')) {
    function taxocommerce_images_optimizer_signature($files) {
        $parts = array();
        foreach ((array) $files as $file) {
            if (empty($file['path']) || !is_file($file['path'])) {
                continue;
            }
            clearstatcache(true, $file['path']);
            $parts[] = wp_normalize_path($file['path']) . ':' . (int) @filesize($file['path']) . ':' . (int) @filemtime($file['path']);
        }
        sort($parts, SORT_STRING);
        return TCI_IMAGES_OPTIMIZER_VERSION . ':' . sha1(implode('|', $parts));
    }
}

if (!function_exists('taxocommerce_images_optimizer_recompress_file')) {
    function taxocommerce_images_optimizer_recompress_file($path) {
        $result = array(
            'status' => 'skipped',
            'before' => 0,
            'after'  => 0,
            'saved'  => 0,
            'error'  => '',
        );

        if (!is_file($path) || !is_readable($path) || !taxocommerce_images_optimizer_file_is_writable($path)) {
            $result['error'] = 'El archivo no es legible o escribible.';
            return $result;
        }
        if (!taxocommerce_images_optimizer_is_safe_upload_path($path)) {
            $result['error'] = 'El archivo esta fuera de uploads.';
            return $result;
        }

        clearstatcache(true, $path);
        $before = (int) @filesize($path);
        $result['before'] = $before;
        $result['after']  = $before;
        if ($before < 1) {
            $result['error'] = 'El archivo esta vacio.';
            return $result;
        }

        $mime_type = taxocommerce_images_optimizer_file_mime($path);
        if (!in_array($mime_type, taxocommerce_images_optimizer_supported_mimes(), true)) {
            return $result;
        }
        if (taxocommerce_images_optimizer_is_animated_file($path, $mime_type)) {
            $result['error'] = 'Imagen animada omitida para no perder fotogramas.';
            return $result;
        }
        if (taxocommerce_images_optimizer_has_unsafe_orientation($path, $mime_type)) {
            $result['error'] = 'JPEG con orientacion EXIF pendiente; se omite para no alterar su visualizacion.';
            return $result;
        }

        $before_dimensions = @getimagesize($path);
        if (!is_array($before_dimensions) || empty($before_dimensions[0]) || empty($before_dimensions[1])) {
            $result['error'] = 'No se pudieron validar las dimensiones.';
            return $result;
        }

        if (!function_exists('wp_get_image_editor')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            $result['error'] = $editor->get_error_message();
            return $result;
        }

        $editor->set_quality(taxocommerce_images_optimizer_quality($mime_type));

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $suffix = wp_generate_password(10, false, false);
        $temp_path = dirname($path) . '/.' . wp_basename($path) . '.seo-opt-' . $suffix . ($extension ? '.' . $extension : '');

        $saved = $editor->save($temp_path, $mime_type);
        if (is_wp_error($saved)) {
            wp_delete_file($temp_path);
            $result['error'] = $saved->get_error_message();
            return $result;
        }

        $candidate = !empty($saved['path']) ? $saved['path'] : $temp_path;
        if (!is_file($candidate)) {
            wp_delete_file($temp_path);
            $result['error'] = 'El editor no genero el archivo temporal.';
            return $result;
        }

        $after_dimensions = @getimagesize($candidate);
        if (
            !is_array($after_dimensions)
            || (int) $after_dimensions[0] !== (int) $before_dimensions[0]
            || (int) $after_dimensions[1] !== (int) $before_dimensions[1]
        ) {
            wp_delete_file($candidate);
            if ($candidate !== $temp_path) {
                wp_delete_file($temp_path);
            }
            $result['error'] = 'La recompresion cambio las dimensiones y se descarto.';
            return $result;
        }

        $candidate_mime = taxocommerce_images_optimizer_file_mime($candidate);
        if ($candidate_mime !== $mime_type) {
            wp_delete_file($candidate);
            if ($candidate !== $temp_path) {
                wp_delete_file($temp_path);
            }
            $result['error'] = 'El formato generado no coincide con el original y se descarto.';
            return $result;
        }

        clearstatcache(true, $candidate);
        $after = (int) @filesize($candidate);
        if ($after < 1 || $after >= $before) {
            wp_delete_file($candidate);
            if ($candidate !== $temp_path) {
                wp_delete_file($temp_path);
            }
            return $result;
        }

        $permissions = @fileperms($path);
        $backup = $path . '.seo-opt-backup-' . $suffix;

        if (!taxocommerce_images_optimizer_rename($path, $backup)) {
            wp_delete_file($candidate);
            if ($candidate !== $temp_path) {
                wp_delete_file($temp_path);
            }
            $result['error'] = 'No se pudo preparar el reemplazo seguro del original.';
            return $result;
        }

        if (!taxocommerce_images_optimizer_rename($candidate, $path)) {
            taxocommerce_images_optimizer_rename($backup, $path);
            wp_delete_file($candidate);
            if ($candidate !== $temp_path) {
                wp_delete_file($temp_path);
            }
            $result['error'] = 'No se pudo sustituir el archivo; se restauro el original.';
            return $result;
        }

        if ($permissions !== false) {
            taxocommerce_images_optimizer_chmod($path, $permissions & 0777);
        }
        if ($candidate !== $temp_path) {
            wp_delete_file($temp_path);
        }

        clearstatcache(true, $path);
        $final_size = (int) @filesize($path);
        $final_dimensions = @getimagesize($path);
        $final_mime = taxocommerce_images_optimizer_file_mime($path);
        if (
            $final_size < 1
            || !is_array($final_dimensions)
            || (int) $final_dimensions[0] !== (int) $before_dimensions[0]
            || (int) $final_dimensions[1] !== (int) $before_dimensions[1]
            || $final_mime !== $mime_type
        ) {
            wp_delete_file($path);
            taxocommerce_images_optimizer_rename($backup, $path);
            $result['error'] = 'El archivo optimizado no pudo validarse; se restauro el original.';
            return $result;
        }

        wp_delete_file($backup);
        $result['status'] = 'optimized';
        $result['after']  = $final_size;
        $result['saved']  = max(0, $before - $final_size);
        return $result;
    }
}

if (!function_exists('taxocommerce_images_optimizer_update_filesizes_metadata')) {
    function taxocommerce_images_optimizer_update_filesizes_metadata($attachment_id, $files) {
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!is_array($metadata)) {
            return;
        }

        $changed = false;
        foreach ((array) $files as $file) {
            if (empty($file['path']) || !is_file($file['path'])) {
                continue;
            }
            clearstatcache(true, $file['path']);
            $filesize = (int) @filesize($file['path']);
            if ($filesize < 1) {
                continue;
            }

            if ($file['type'] === 'full') {
                if (!isset($metadata['filesize']) || (int) $metadata['filesize'] !== $filesize) {
                    $metadata['filesize'] = $filesize;
                    $changed = true;
                }
            } elseif ($file['type'] === 'size' && !empty($file['size_key']) && isset($metadata['sizes'][$file['size_key']])) {
                if (!isset($metadata['sizes'][$file['size_key']]['filesize']) || (int) $metadata['sizes'][$file['size_key']]['filesize'] !== $filesize) {
                    $metadata['sizes'][$file['size_key']]['filesize'] = $filesize;
                    $changed = true;
                }
            }
        }

        if ($changed) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }
    }
}

if (!function_exists('taxocommerce_images_optimizer_process_attachment')) {
    function taxocommerce_images_optimizer_process_attachment($attachment_id) {
        $attachment_id = absint($attachment_id);
        $result = array(
            'attachment_id' => $attachment_id,
            'files'         => 0,
            'optimized'     => 0,
            'skipped'       => 0,
            'before'        => 0,
            'after'         => 0,
            'saved'         => 0,
            'errors'        => array(),
            'unchanged'     => false,
        );

        $files = taxocommerce_images_optimizer_attachment_files($attachment_id);
        $result['files'] = count($files);
        if (empty($files)) {
            $result['errors'][] = 'Attachment #' . $attachment_id . ': no se encontraron archivos locales.';
            return $result;
        }

        $current_signature = taxocommerce_images_optimizer_signature($files);
        $stored_signature = (string) get_post_meta($attachment_id, '_tci_optimizer_signature', true);
        if ($stored_signature !== '' && hash_equals($stored_signature, $current_signature)) {
            foreach ($files as $file) {
                clearstatcache(true, $file['path']);
                $size = (int) @filesize($file['path']);
                $result['before'] += $size;
                $result['after'] += $size;
                $result['skipped']++;
            }
            $result['unchanged'] = true;
            return $result;
        }

        foreach ($files as $file) {
            $file_result = taxocommerce_images_optimizer_recompress_file($file['path']);
            $result['before'] += (int) $file_result['before'];
            $result['after'] += (int) $file_result['after'];
            $result['saved'] += (int) $file_result['saved'];

            if ($file_result['status'] === 'optimized') {
                $result['optimized']++;
            } else {
                $result['skipped']++;
            }

            if (!empty($file_result['error'])) {
                $result['errors'][] = 'Attachment #' . $attachment_id . ' (' . wp_basename($file['path']) . '): ' . $file_result['error'];
            }
        }

        taxocommerce_images_optimizer_update_filesizes_metadata($attachment_id, $files);
        $files_after = taxocommerce_images_optimizer_attachment_files($attachment_id);
        update_post_meta($attachment_id, '_tci_optimizer_signature', taxocommerce_images_optimizer_signature($files_after));
        update_post_meta($attachment_id, '_tci_optimizer_last_run', current_time('mysql'));
        update_post_meta($attachment_id, '_tci_optimizer_saved_bytes', max(0, (int) $result['saved']));

        return $result;
    }
}

