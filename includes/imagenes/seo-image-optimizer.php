<?php
/**
 * SEO Images - optimizador no destructivo de Media local.
 *
 * Re-comprime los ficheros existentes manteniendo exactamente sus nombres,
 * URLs y dimensiones. El original solo se sustituye cuando el resultado es
 * valido y ocupa menos bytes. No convierte formatos.
 *
 * Version: 2026-09-02
 * Build: 001
 */

defined('ABSPATH') || exit;

if (!defined('SEO_IMAGES_OPTIMIZER_VERSION')) {
    define('SEO_IMAGES_OPTIMIZER_VERSION', '1');
}

if (!function_exists('seo_images_optimizer_supported_mimes')) {
    function seo_images_optimizer_supported_mimes() {
        return (array) apply_filters(
            'seo_images_optimizer_supported_mimes',
            array('image/jpeg', 'image/png', 'image/webp', 'image/avif')
        );
    }
}

if (!function_exists('seo_images_optimizer_quality')) {
    function seo_images_optimizer_quality($mime_type) {
        $qualities = array(
            'image/jpeg' => 80,
            'image/png'  => 50,
            'image/webp' => 80,
            'image/avif' => 65,
        );

        $quality = isset($qualities[$mime_type]) ? $qualities[$mime_type] : 80;
        return max(1, min(100, (int) apply_filters('seo_images_optimizer_quality', $quality, $mime_type)));
    }
}

if (!function_exists('seo_images_optimizer_is_safe_upload_path')) {
    function seo_images_optimizer_is_safe_upload_path($path) {
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

if (!function_exists('seo_images_optimizer_file_mime')) {
    function seo_images_optimizer_file_mime($path) {
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

if (!function_exists('seo_images_optimizer_has_unsafe_orientation')) {
    function seo_images_optimizer_has_unsafe_orientation($path, $mime_type) {
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

if (!function_exists('seo_images_optimizer_is_animated_file')) {
    function seo_images_optimizer_is_animated_file($path, $mime_type) {
        if (!in_array($mime_type, array('image/webp', 'image/png'), true)) {
            return false;
        }

        $size = @filesize($path);
        if (!$size || $size < 16) {
            return false;
        }

        $read_length = min((int) $size, 2 * MB_IN_BYTES);
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return false;
        }

        $head = @fread($handle, $read_length);
        @fclose($handle);
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

if (!function_exists('seo_images_optimizer_attachment_files')) {
    function seo_images_optimizer_attachment_files($attachment_id) {
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
            if (!seo_images_optimizer_is_safe_upload_path($real)) {
                continue;
            }
            $file['path'] = $real;
            $unique[$normalized] = $file;
        }

        return array_values($unique);
    }
}

if (!function_exists('seo_images_optimizer_signature')) {
    function seo_images_optimizer_signature($files) {
        $parts = array();
        foreach ((array) $files as $file) {
            if (empty($file['path']) || !is_file($file['path'])) {
                continue;
            }
            clearstatcache(true, $file['path']);
            $parts[] = wp_normalize_path($file['path']) . ':' . (int) @filesize($file['path']) . ':' . (int) @filemtime($file['path']);
        }
        sort($parts, SORT_STRING);
        return SEO_IMAGES_OPTIMIZER_VERSION . ':' . sha1(implode('|', $parts));
    }
}

if (!function_exists('seo_images_optimizer_recompress_file')) {
    function seo_images_optimizer_recompress_file($path) {
        $result = array(
            'status' => 'skipped',
            'before' => 0,
            'after'  => 0,
            'saved'  => 0,
            'error'  => '',
        );

        if (!is_file($path) || !is_readable($path) || !is_writable($path)) {
            $result['error'] = 'El archivo no es legible o escribible.';
            return $result;
        }
        if (!seo_images_optimizer_is_safe_upload_path($path)) {
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

        $mime_type = seo_images_optimizer_file_mime($path);
        if (!in_array($mime_type, seo_images_optimizer_supported_mimes(), true)) {
            return $result;
        }
        if (seo_images_optimizer_is_animated_file($path, $mime_type)) {
            $result['error'] = 'Imagen animada omitida para no perder fotogramas.';
            return $result;
        }
        if (seo_images_optimizer_has_unsafe_orientation($path, $mime_type)) {
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

        $editor->set_quality(seo_images_optimizer_quality($mime_type));

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $suffix = wp_generate_password(10, false, false);
        $temp_path = dirname($path) . '/.' . wp_basename($path) . '.seo-opt-' . $suffix . ($extension ? '.' . $extension : '');

        $saved = $editor->save($temp_path, $mime_type);
        if (is_wp_error($saved)) {
            @unlink($temp_path);
            $result['error'] = $saved->get_error_message();
            return $result;
        }

        $candidate = !empty($saved['path']) ? $saved['path'] : $temp_path;
        if (!is_file($candidate)) {
            @unlink($temp_path);
            $result['error'] = 'El editor no genero el archivo temporal.';
            return $result;
        }

        $after_dimensions = @getimagesize($candidate);
        if (
            !is_array($after_dimensions)
            || (int) $after_dimensions[0] !== (int) $before_dimensions[0]
            || (int) $after_dimensions[1] !== (int) $before_dimensions[1]
        ) {
            @unlink($candidate);
            if ($candidate !== $temp_path) {
                @unlink($temp_path);
            }
            $result['error'] = 'La recompresion cambio las dimensiones y se descarto.';
            return $result;
        }

        $candidate_mime = seo_images_optimizer_file_mime($candidate);
        if ($candidate_mime !== $mime_type) {
            @unlink($candidate);
            if ($candidate !== $temp_path) {
                @unlink($temp_path);
            }
            $result['error'] = 'El formato generado no coincide con el original y se descarto.';
            return $result;
        }

        clearstatcache(true, $candidate);
        $after = (int) @filesize($candidate);
        if ($after < 1 || $after >= $before) {
            @unlink($candidate);
            if ($candidate !== $temp_path) {
                @unlink($temp_path);
            }
            return $result;
        }

        $permissions = @fileperms($path);
        $backup = $path . '.seo-opt-backup-' . $suffix;

        if (!@rename($path, $backup)) {
            @unlink($candidate);
            if ($candidate !== $temp_path) {
                @unlink($temp_path);
            }
            $result['error'] = 'No se pudo preparar el reemplazo seguro del original.';
            return $result;
        }

        if (!@rename($candidate, $path)) {
            @rename($backup, $path);
            @unlink($candidate);
            if ($candidate !== $temp_path) {
                @unlink($temp_path);
            }
            $result['error'] = 'No se pudo sustituir el archivo; se restauro el original.';
            return $result;
        }

        if ($permissions !== false) {
            @chmod($path, $permissions & 0777);
        }
        if ($candidate !== $temp_path) {
            @unlink($temp_path);
        }

        clearstatcache(true, $path);
        $final_size = (int) @filesize($path);
        $final_dimensions = @getimagesize($path);
        $final_mime = seo_images_optimizer_file_mime($path);
        if (
            $final_size < 1
            || !is_array($final_dimensions)
            || (int) $final_dimensions[0] !== (int) $before_dimensions[0]
            || (int) $final_dimensions[1] !== (int) $before_dimensions[1]
            || $final_mime !== $mime_type
        ) {
            @unlink($path);
            @rename($backup, $path);
            $result['error'] = 'El archivo optimizado no pudo validarse; se restauro el original.';
            return $result;
        }

        @unlink($backup);
        $result['status'] = 'optimized';
        $result['after']  = $final_size;
        $result['saved']  = max(0, $before - $final_size);
        return $result;
    }
}

if (!function_exists('seo_images_optimizer_update_filesizes_metadata')) {
    function seo_images_optimizer_update_filesizes_metadata($attachment_id, $files) {
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

if (!function_exists('seo_images_optimizer_process_attachment')) {
    function seo_images_optimizer_process_attachment($attachment_id) {
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

        $files = seo_images_optimizer_attachment_files($attachment_id);
        $result['files'] = count($files);
        if (empty($files)) {
            $result['errors'][] = 'Attachment #' . $attachment_id . ': no se encontraron archivos locales.';
            return $result;
        }

        $current_signature = seo_images_optimizer_signature($files);
        $stored_signature = (string) get_post_meta($attachment_id, '_seo_images_optimizer_signature', true);
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
            $file_result = seo_images_optimizer_recompress_file($file['path']);
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

        seo_images_optimizer_update_filesizes_metadata($attachment_id, $files);
        $files_after = seo_images_optimizer_attachment_files($attachment_id);
        update_post_meta($attachment_id, '_seo_images_optimizer_signature', seo_images_optimizer_signature($files_after));
        update_post_meta($attachment_id, '_seo_images_optimizer_last_run', current_time('mysql'));
        update_post_meta($attachment_id, '_seo_images_optimizer_saved_bytes', max(0, (int) $result['saved']));

        return $result;
    }
}

if (!function_exists('seo_images_optimizer_attachment_batch')) {
    function seo_images_optimizer_attachment_batch($offset, $limit) {
        $offset = max(0, absint($offset));
        $limit  = max(1, min(5, absint($limit)));

        $query = new WP_Query(array(
            'post_type'              => 'attachment',
            'post_mime_type'         => 'image',
            'post_status'            => 'inherit',
            'posts_per_page'         => $limit,
            'offset'                 => $offset,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'fields'                 => 'ids',
            'no_found_rows'          => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ));

        return array(
            'ids'   => array_map('absint', (array) $query->posts),
            'total' => (int) $query->found_posts,
        );
    }
}

if (!function_exists('seo_images_optimizer_ajax_batch')) {
    function seo_images_optimizer_ajax_batch() {
        if (!current_user_can('manage_options') || !current_user_can('upload_files')) {
            wp_send_json_error(array('message' => 'No tienes permisos para optimizar Media.'), 403);
        }

        check_ajax_referer('seo_images_optimizer_run', 'nonce');

        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('image');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(60);
        }
        @ignore_user_abort(true);

        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        $limit  = isset($_POST['limit']) ? absint($_POST['limit']) : 2;
        $batch  = seo_images_optimizer_attachment_batch($offset, $limit);

        $response = array(
            'processed'         => 0,
            'total'             => (int) $batch['total'],
            'next_offset'       => $offset,
            'optimized_files'   => 0,
            'skipped_files'     => 0,
            'optimized_items'   => 0,
            'unchanged_items'   => 0,
            'bytes_before'      => 0,
            'bytes_after'       => 0,
            'bytes_saved'       => 0,
            'errors'            => array(),
            'done'              => false,
        );

        foreach ($batch['ids'] as $attachment_id) {
            try {
                $item = seo_images_optimizer_process_attachment($attachment_id);
                $response['processed']++;
                $response['optimized_files'] += (int) $item['optimized'];
                $response['skipped_files'] += (int) $item['skipped'];
                $response['bytes_before'] += (int) $item['before'];
                $response['bytes_after'] += (int) $item['after'];
                $response['bytes_saved'] += (int) $item['saved'];
                if (!empty($item['optimized'])) {
                    $response['optimized_items']++;
                }
                if (!empty($item['unchanged'])) {
                    $response['unchanged_items']++;
                }
                if (!empty($item['errors'])) {
                    $response['errors'] = array_merge($response['errors'], array_slice($item['errors'], 0, 4));
                }
            } catch (Throwable $exception) {
                $response['processed']++;
                $response['errors'][] = 'Attachment #' . $attachment_id . ': ' . sanitize_text_field($exception->getMessage());
            }
        }

        $response['next_offset'] = $offset + $response['processed'];
        $response['done'] = $response['next_offset'] >= $response['total'] || empty($batch['ids']);
        $response['errors'] = array_slice(array_values(array_unique($response['errors'])), 0, 10);

        wp_send_json_success($response);
    }
}
add_action('wp_ajax_seo_images_optimize_batch', 'seo_images_optimizer_ajax_batch');

if (!function_exists('seo_images_optimizer_enqueue_assets')) {
    function seo_images_optimizer_enqueue_assets() {
        if (!is_admin()) {
            return;
        }
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== 'seo-pictures-admin') {
            return;
        }

        $asset_path = __DIR__ . '/assets/seo-image-optimizer.js';
        $asset_url  = SEO_SYSTEM_URL . 'includes/imagenes/assets/seo-image-optimizer.js';
        $version    = is_file($asset_path) ? (string) filemtime($asset_path) : SEO_SYSTEM_VERSION;

        wp_enqueue_script('seo-images-optimizer', $asset_url, array(), $version, true);
        wp_localize_script(
            'seo-images-optimizer',
            'seoImagesOptimizerSettings',
            array(
                'ajaxUrl'   => admin_url('admin-ajax.php'),
                'nonce'     => wp_create_nonce('seo_images_optimizer_run'),
                'batchSize' => 2,
                'strings'   => array(
                    'confirm' => 'Se recomprimiran los originales y miniaturas de Media local. No se cambiaran nombres, URLs ni dimensiones. Solo se sustituira un archivo si el resultado pesa menos. Continuar?',
                    'running' => 'Optimizando Media local...',
                    'done'    => 'Optimizacion completada.',
                    'error'   => 'La optimizacion se detuvo por un error del servidor.',
                ),
            )
        );
    }
}
add_action('admin_enqueue_scripts', 'seo_images_optimizer_enqueue_assets');

if (!function_exists('seo_images_optimizer_render_controls')) {
    function seo_images_optimizer_render_controls($summary = array()) {
        $local_total = !empty($summary['local_total']) ? absint($summary['local_total']) : 0;
        ?>
        <section class="seo-images-card seo-images-optimizer-card" style="margin-top:16px">
            <div class="seo-images-optimizer-head">
                <div>
                    <h2>Optimización de Media local</h2>
                    <p class="seo-images-muted">
                        Re-comprime los archivos existentes <strong>sin cambiar el nombre, la URL ni las dimensiones</strong>.
                        También revisa las miniaturas generadas por WordPress y solo reemplaza un archivo cuando la nueva versión ocupa menos.
                    </p>
                </div>
                <span class="seo-images-status local"><?php echo esc_html(number_format_i18n($local_total)); ?> attachments</span>
            </div>

            <div class="seo-images-optimizer-actions">
                <button type="button" class="button button-primary" id="seo-images-optimize-local">
                    Reducir tamaño de todas las imágenes
                </button>
                <button type="button" class="button" id="seo-images-convert-webp">
                    Convertir JPG/PNG a WebP
                </button>
            </div>

            <div id="seo-images-optimizer-progress" class="seo-images-optimizer-progress" hidden>
                <div class="seo-images-optimizer-progress-track" aria-hidden="true"><span></span></div>
                <p id="seo-images-optimizer-status" class="seo-images-muted" aria-live="polite"></p>
                <div id="seo-images-optimizer-stats" class="seo-images-optimizer-stats"></div>
                <div id="seo-images-optimizer-errors" class="seo-images-optimizer-errors" hidden></div>
            </div>

            <p class="seo-images-optimizer-note">
                <strong>Reducir tamaño</strong> mantiene nombres y URLs. <strong>Convertir a WebP</strong> conserva el mismo attachment ID, actualiza los metadatos y referencias internas conocidas y elimina los JPG/PNG solo después de validar cada attachment.
                GIF, SVG y animaciones se omiten. La conversión WebP solo se aplica cuando el conjunto resultante ocupa menos espacio.
            </p>
        </section>
        <style>
            .seo-images-optimizer-head{display:flex;align-items:flex-start;justify-content:space-between;gap:20px}
            .seo-images-optimizer-head h2{margin-top:0}.seo-images-optimizer-head p{max-width:920px;margin-bottom:0}
            .seo-images-optimizer-actions{display:flex;gap:10px;flex-wrap:wrap;margin:18px 0 8px}
            .seo-images-optimizer-progress{margin-top:16px;max-width:1000px}.seo-images-optimizer-progress-track{height:10px;background:#dcdcde;border-radius:999px;overflow:hidden}
            .seo-images-optimizer-progress-track span{display:block;width:0;height:100%;background:#2271b1;transition:width .2s ease}
            .seo-images-optimizer-stats{display:flex;gap:18px;flex-wrap:wrap;margin-top:8px;font-size:12px}.seo-images-optimizer-stats strong{font-size:13px}
            .seo-images-optimizer-errors{margin-top:10px;padding:10px 12px;border-left:4px solid #dba617;background:#fff8e5;font-size:12px;white-space:pre-line}
            .seo-images-optimizer-note{margin:12px 0 0;color:#50575e;font-size:12px}
            @media(max-width:782px){.seo-images-optimizer-head{display:block}.seo-images-optimizer-head .seo-images-status{margin-top:10px}}
        </style>
        <?php
    }
}
