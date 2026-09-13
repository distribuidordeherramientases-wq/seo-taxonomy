<?php
/**
 * SEO Images - conversion segura de JPG/PNG a WebP.
 *
 * La conversion conserva el attachment_id de WordPress. Cada attachment se
 * procesa de forma atomica: primero se generan y validan todos los WebP, luego
 * se actualizan referencias conocidas y metadatos de WordPress, y solo al
 * final se eliminan los JPG/PNG sustituidos.
 *
 * Version: 2026-09-03
 * Build: 002
 */

defined('ABSPATH') || exit;

if (!defined('SEO_IMAGES_WEBP_VERSION')) {
    define('SEO_IMAGES_WEBP_VERSION', '1');
}

if (!function_exists('seo_images_webp_quality')) {
    function seo_images_webp_quality() {
        return max(1, min(100, (int) apply_filters('seo_images_webp_quality', 80)));
    }
}

if (!function_exists('seo_images_webp_supported')) {
    function seo_images_webp_supported() {
        if (!function_exists('wp_image_editor_supports')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        return function_exists('wp_image_editor_supports')
            && wp_image_editor_supports(array('mime_type' => 'image/webp'));
    }
}

if (!function_exists('seo_images_webp_source_mimes')) {
    function seo_images_webp_source_mimes() {
        return (array) apply_filters('seo_images_webp_source_mimes', array('image/jpeg', 'image/png'));
    }
}

if (!function_exists('seo_images_webp_relative_upload_path')) {
    function seo_images_webp_relative_upload_path($path) {
        $uploads = wp_get_upload_dir();
        if (empty($uploads['basedir'])) {
            return '';
        }

        $base = trailingslashit(wp_normalize_path($uploads['basedir']));
        $path = wp_normalize_path($path);
        if (strpos($path, $base) !== 0) {
            return '';
        }

        return ltrim(substr($path, strlen($base)), '/');
    }
}

if (!function_exists('seo_images_webp_add_file_entry')) {
    function seo_images_webp_add_file_entry(&$entries, $path, $reference) {
        if (!$path || !is_file($path) || !is_readable($path)) {
            return;
        }

        $real = realpath($path);
        if (!$real || !seo_images_optimizer_is_safe_upload_path($real)) {
            return;
        }

        $normalized = wp_normalize_path($real);
        if (!isset($entries[$normalized])) {
            $entries[$normalized] = array(
                'old_path' => $real,
                'refs'     => array(),
            );
        }
        $entries[$normalized]['refs'][] = $reference;
    }
}

if (!function_exists('seo_images_webp_attachment_entries')) {
    function seo_images_webp_attachment_entries($attachment_id) {
        $attachment_id = absint($attachment_id);
        $attached_file = get_attached_file($attachment_id, true);
        if (!$attached_file || !is_file($attached_file)) {
            return new WP_Error('missing_attached_file', 'No se encuentra el archivo principal del attachment.');
        }

        $source_mime = seo_images_optimizer_file_mime($attached_file);
        if (!in_array($source_mime, seo_images_webp_source_mimes(), true)) {
            return new WP_Error('unsupported_source', 'El archivo principal no es JPG/JPEG ni PNG.');
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!is_array($metadata)) {
            $metadata = array();
        }

        $backup_sizes = get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);
        if (!is_array($backup_sizes)) {
            $backup_sizes = array();
        }

        $directory = dirname($attached_file);
        $entries   = array();

        seo_images_webp_add_file_entry(
            $entries,
            $attached_file,
            array('kind' => 'full', 'key' => '')
        );

        if (!empty($metadata['original_image'])) {
            seo_images_webp_add_file_entry(
                $entries,
                path_join($directory, wp_basename($metadata['original_image'])),
                array('kind' => 'original_image', 'key' => '')
            );
        }

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_key => $size_data) {
                if (!is_array($size_data) || empty($size_data['file'])) {
                    continue;
                }
                seo_images_webp_add_file_entry(
                    $entries,
                    path_join($directory, wp_basename($size_data['file'])),
                    array('kind' => 'size', 'key' => (string) $size_key)
                );
            }
        }

        foreach ($backup_sizes as $backup_key => $backup_data) {
            if (!is_array($backup_data) || empty($backup_data['file'])) {
                continue;
            }
            seo_images_webp_add_file_entry(
                $entries,
                path_join($directory, wp_basename($backup_data['file'])),
                array('kind' => 'backup', 'key' => (string) $backup_key)
            );
        }

        if (empty($entries)) {
            return new WP_Error('no_local_files', 'No hay archivos locales convertibles para este attachment.');
        }

        return array_values($entries);
    }
}

if (!function_exists('seo_images_webp_is_ultrahdr_jpeg')) {
    function seo_images_webp_is_ultrahdr_jpeg($path, $mime_type) {
        if ($mime_type !== 'image/jpeg' || !is_file($path)) {
            return false;
        }

        $size = (int) @filesize($path);
        if ($size < 1) {
            return false;
        }

        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return false;
        }
        $head = @fread($handle, min($size, 2 * MB_IN_BYTES));
        @fclose($handle);
        if (!is_string($head) || $head === '') {
            return false;
        }

        $markers = array(
            'http://ns.adobe.com/hdr-gain-map/1.0/',
            'hdrgm:Version',
            'urn:iso:std:iso:ts:21496:-1',
        );
        foreach ($markers as $marker) {
            if (strpos($head, $marker) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('seo_images_webp_unique_target')) {
    function seo_images_webp_unique_target($old_path, $reserved = array()) {
        $directory = dirname($old_path);
        $stem      = (string) pathinfo(wp_basename($old_path), PATHINFO_FILENAME);
        $filename  = sanitize_file_name($stem . '.webp');
        if ($filename === '.webp' || $filename === '') {
            $filename = 'image.webp';
        }

        $candidate = path_join($directory, $filename);
        $reserved  = array_fill_keys(array_map('wp_normalize_path', (array) $reserved), true);
        if (!file_exists($candidate) && !isset($reserved[wp_normalize_path($candidate)])) {
            return $candidate;
        }

        $filename = wp_unique_filename($directory, $filename);
        $candidate = path_join($directory, $filename);
        $counter = 2;
        while (isset($reserved[wp_normalize_path($candidate)])) {
            $filename = sanitize_file_name($stem . '-webp-' . $counter . '.webp');
            $candidate = path_join($directory, $filename);
            $counter++;
        }

        return $candidate;
    }
}

if (!function_exists('seo_images_webp_convert_file')) {
    function seo_images_webp_convert_file($old_path, $reserved = array()) {
        if (!is_file($old_path) || !is_readable($old_path) || !is_writable($old_path)) {
            return new WP_Error('file_permissions', 'El archivo no es legible o escribible.');
        }
        if (!seo_images_optimizer_is_safe_upload_path($old_path)) {
            return new WP_Error('unsafe_path', 'El archivo esta fuera del directorio uploads.');
        }

        $source_mime = seo_images_optimizer_file_mime($old_path);
        if (!in_array($source_mime, seo_images_webp_source_mimes(), true)) {
            return new WP_Error('unsupported_source_file', 'Uno de los derivados no es JPG/JPEG ni PNG.');
        }
        if (seo_images_optimizer_is_animated_file($old_path, $source_mime)) {
            return new WP_Error('animated_image', 'Se ha detectado una imagen animada; no se convierte para no perder fotogramas.');
        }
        if (seo_images_optimizer_has_unsafe_orientation($old_path, $source_mime)) {
            return new WP_Error('unsafe_orientation', 'JPEG con orientacion EXIF pendiente; se omite para no girar la imagen.');
        }

        if (seo_images_webp_is_ultrahdr_jpeg($old_path, $source_mime)) {
            return new WP_Error('ultrahdr_jpeg', 'JPEG UltraHDR con gain map detectado; WordPress 7.1 evita convertirlo porque WebP perderia la informacion HDR.');
        }

        $before_dimensions = @getimagesize($old_path);
        if (!is_array($before_dimensions) || empty($before_dimensions[0]) || empty($before_dimensions[1])) {
            return new WP_Error('invalid_dimensions', 'No se pudieron validar las dimensiones originales.');
        }

        if (!function_exists('wp_get_image_editor')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $editor = wp_get_image_editor($old_path);
        if (is_wp_error($editor)) {
            return $editor;
        }
        $editor->set_quality(seo_images_webp_quality());

        $target = seo_images_webp_unique_target($old_path, $reserved);
        $suffix = wp_generate_password(12, false, false);
        $temp   = dirname($old_path) . '/.' . wp_basename($target) . '.seo-webp-' . $suffix . '.webp';

        $saved = $editor->save($temp, 'image/webp');
        if (is_wp_error($saved)) {
            @unlink($temp);
            return $saved;
        }

        $candidate = !empty($saved['path']) ? $saved['path'] : $temp;
        if (!is_file($candidate)) {
            @unlink($temp);
            return new WP_Error('webp_not_created', 'El editor de WordPress no genero el WebP temporal.');
        }

        $after_dimensions = @getimagesize($candidate);
        $candidate_mime   = seo_images_optimizer_file_mime($candidate);
        if (
            !is_array($after_dimensions)
            || (int) $after_dimensions[0] !== (int) $before_dimensions[0]
            || (int) $after_dimensions[1] !== (int) $before_dimensions[1]
            || $candidate_mime !== 'image/webp'
        ) {
            @unlink($candidate);
            if ($candidate !== $temp) {
                @unlink($temp);
            }
            return new WP_Error('webp_validation', 'El WebP generado no conserva formato o dimensiones y se ha descartado.');
        }

        clearstatcache(true, $old_path);
        clearstatcache(true, $candidate);
        $before = (int) @filesize($old_path);
        $after  = (int) @filesize($candidate);
        if ($before < 1 || $after < 1) {
            @unlink($candidate);
            if ($candidate !== $temp) {
                @unlink($temp);
            }
            return new WP_Error('invalid_filesize', 'No se pudo validar el peso del archivo convertido.');
        }

        if (file_exists($target)) {
            @unlink($candidate);
            if ($candidate !== $temp) {
                @unlink($temp);
            }
            return new WP_Error('target_collision', 'El nombre WebP de destino ya existe y no se sobrescribira.');
        }

        if (!@rename($candidate, $target)) {
            @unlink($candidate);
            if ($candidate !== $temp) {
                @unlink($temp);
            }
            return new WP_Error('target_rename', 'No se pudo mover el WebP temporal a su nombre definitivo.');
        }
        if ($candidate !== $temp) {
            @unlink($temp);
        }

        $permissions = @fileperms($old_path);
        if ($permissions !== false) {
            @chmod($target, $permissions & 0777);
        }

        clearstatcache(true, $target);
        $final_mime = seo_images_optimizer_file_mime($target);
        $final_size = (int) @filesize($target);
        $final_dims = @getimagesize($target);
        if (
            $final_mime !== 'image/webp'
            || $final_size < 1
            || !is_array($final_dims)
            || (int) $final_dims[0] !== (int) $before_dimensions[0]
            || (int) $final_dims[1] !== (int) $before_dimensions[1]
        ) {
            @unlink($target);
            return new WP_Error('final_validation', 'La validacion final del WebP ha fallado.');
        }

        return array(
            'old_path' => $old_path,
            'new_path' => $target,
            'before'   => $before,
            'after'    => $final_size,
        );
    }
}

if (!function_exists('seo_images_webp_delete_candidates')) {
    function seo_images_webp_delete_candidates($maps) {
        foreach ((array) $maps as $map) {
            if (!empty($map['new_path']) && is_file($map['new_path'])) {
                wp_delete_file($map['new_path']);
                if (is_file($map['new_path'])) {
                    @unlink($map['new_path']);
                }
            }
        }
    }
}

if (!function_exists('seo_images_webp_add_pair')) {
    function seo_images_webp_add_pair(&$pairs, $old, $new) {
        $old = (string) $old;
        $new = (string) $new;
        if ($old === '' || $new === '' || $old === $new) {
            return;
        }
        $pairs[$old] = $new;

        if (strpos($old, '/') !== false) {
            $escaped_old = str_replace('/', '\\/', $old);
            $escaped_new = str_replace('/', '\\/', $new);
            if ($escaped_old !== $old) {
                $pairs[$escaped_old] = $escaped_new;
            }
        }
    }
}

if (!function_exists('seo_images_webp_reference_pairs')) {
    function seo_images_webp_reference_pairs($maps) {
        $uploads = wp_get_upload_dir();
        $pairs   = array();
        $base_dir = !empty($uploads['basedir']) ? trailingslashit(wp_normalize_path($uploads['basedir'])) : '';
        $base_url = !empty($uploads['baseurl']) ? trailingslashit($uploads['baseurl']) : '';

        foreach ((array) $maps as $map) {
            if (empty($map['old_path']) || empty($map['new_path'])) {
                continue;
            }

            $old_path = wp_normalize_path($map['old_path']);
            $new_path = wp_normalize_path($map['new_path']);
            $old_rel  = seo_images_webp_relative_upload_path($old_path);
            $new_rel  = seo_images_webp_relative_upload_path($new_path);

            seo_images_webp_add_pair($pairs, $old_path, $new_path);

            if ($old_rel !== '' && $new_rel !== '') {
                if (strpos($old_rel, '/') !== false) {
                    seo_images_webp_add_pair($pairs, $old_rel, $new_rel);
                }
                if ($base_dir !== '') {
                    seo_images_webp_add_pair($pairs, $base_dir . $old_rel, $base_dir . $new_rel);
                }
                if ($base_url !== '') {
                    $old_url = $base_url . str_replace('%2F', '/', rawurlencode($old_rel));
                    $new_url = $base_url . str_replace('%2F', '/', rawurlencode($new_rel));
                    // WordPress suele devolver nombres ya saneados; mantenemos tambien la variante sin rawurlencode.
                    seo_images_webp_add_pair($pairs, $base_url . $old_rel, $base_url . $new_rel);
                    seo_images_webp_add_pair($pairs, $old_url, $new_url);

                    $old_url_path = wp_parse_url($base_url . $old_rel, PHP_URL_PATH);
                    $new_url_path = wp_parse_url($base_url . $new_rel, PHP_URL_PATH);
                    if (is_string($old_url_path) && is_string($new_url_path)) {
                        seo_images_webp_add_pair($pairs, $old_url_path, $new_url_path);
                    }
                }
            }
        }

        uksort($pairs, static function ($a, $b) {
            return strlen($b) <=> strlen($a);
        });

        return $pairs;
    }
}

if (!function_exists('seo_images_webp_reference_terms')) {
    function seo_images_webp_reference_terms($maps) {
        $terms = array();
        foreach ((array) $maps as $map) {
            if (empty($map['old_path'])) {
                continue;
            }
            $terms[] = wp_basename($map['old_path']);
        }
        return array_values(array_unique(array_filter($terms)));
    }
}

if (!function_exists('seo_images_webp_replace_typed')) {
    function seo_images_webp_replace_typed($value, $pairs, &$changed, &$unsupported_object) {
        if (is_string($value)) {
            $new = str_replace(array_keys($pairs), array_values($pairs), $value, $count);
            if ($count > 0) {
                $changed = true;
            }
            return $new;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = seo_images_webp_replace_typed($item, $pairs, $changed, $unsupported_object);
            }
            return $value;
        }

        if (is_object($value)) {
            $unsupported_object = true;
        }

        return $value;
    }
}

if (!function_exists('seo_images_webp_prepare_value_update')) {
    function seo_images_webp_prepare_value_update($raw_value, $pairs) {
        $result = array(
            'changed'     => false,
            'unsupported' => false,
            'old_value'   => $raw_value,
            'new_value'   => $raw_value,
        );

        if (!is_string($raw_value) || $raw_value === '') {
            return $result;
        }

        if (is_serialized($raw_value)) {
            $typed = maybe_unserialize($raw_value);
            $unsupported = false;
            $changed = false;
            $new_typed = seo_images_webp_replace_typed($typed, $pairs, $changed, $unsupported);
            $result['unsupported'] = $unsupported;
            if ($unsupported) {
                return $result;
            }
            if ($changed) {
                $result['changed']   = true;
                $result['old_value'] = $typed;
                $result['new_value'] = $new_typed;
            }
            return $result;
        }

        $new = str_replace(array_keys($pairs), array_values($pairs), $raw_value, $count);
        if ($count > 0) {
            $result['changed']   = true;
            $result['new_value'] = $new;
        }
        return $result;
    }
}

if (!function_exists('seo_images_webp_like_clause')) {
    function seo_images_webp_like_clause($column, $terms, &$args) {
        global $wpdb;
        $parts = array();
        foreach ((array) $terms as $term) {
            $parts[] = $column . ' LIKE %s';
            $args[] = '%' . $wpdb->esc_like($term) . '%';
        }
        return $parts ? '(' . implode(' OR ', $parts) . ')' : '(1=0)';
    }
}

if (!function_exists('seo_images_webp_apply_reference_updates')) {
    function seo_images_webp_apply_reference_updates($attachment_id, $pairs, $terms) {
        global $wpdb;

        $changes = array();
        $updated = 0;
        $unsupported = array();
        $limit = 250;

        // Entradas, paginas, productos y attachments: HTML/contenido y extractos. GUID se excluye expresamente.
        $after = 0;
        do {
            $args = array();
            $where_content = seo_images_webp_like_clause('post_content', $terms, $args);
            $where_excerpt = seo_images_webp_like_clause('post_excerpt', $terms, $args);
            $where_filtered = seo_images_webp_like_clause('post_content_filtered', $terms, $args);
            array_unshift($args, $after);
            $args[] = $limit;
            $sql = "SELECT ID, post_content, post_excerpt, post_content_filtered FROM {$wpdb->posts}
                    WHERE ID > %d AND ({$where_content} OR {$where_excerpt} OR {$where_filtered})
                    ORDER BY ID ASC LIMIT %d";
            $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
            foreach ((array) $rows as $row) {
                $after = max($after, (int) $row['ID']);
                $content = str_replace(array_keys($pairs), array_values($pairs), (string) $row['post_content'], $count_content);
                $excerpt = str_replace(array_keys($pairs), array_values($pairs), (string) $row['post_excerpt'], $count_excerpt);
                $filtered = str_replace(array_keys($pairs), array_values($pairs), (string) $row['post_content_filtered'], $count_filtered);
                if ($count_content < 1 && $count_excerpt < 1 && $count_filtered < 1) {
                    continue;
                }
                $changes[] = array('kind' => 'post', 'id' => (int) $row['ID'], 'content' => $row['post_content'], 'excerpt' => $row['post_excerpt'], 'filtered' => $row['post_content_filtered']);
                $ok = $wpdb->update(
                    $wpdb->posts,
                    array('post_content' => $content, 'post_excerpt' => $excerpt, 'post_content_filtered' => $filtered),
                    array('ID' => (int) $row['ID']),
                    array('%s', '%s', '%s'),
                    array('%d')
                );
                if ($ok === false) {
                    return new WP_Error('reference_update_posts', 'No se pudo actualizar una referencia guardada en wp_posts.', array('changes' => $changes));
                }
                clean_post_cache((int) $row['ID']);
                $updated++;
            }
        } while (count((array) $rows) === $limit);

        // Metadatos WordPress. Se excluyen los metadatos internos de archivos de attachments;
        // esos se actualizan de forma controlada durante el commit del attachment actual.
        $meta_sets = array(
            array('table' => $wpdb->postmeta, 'type' => 'post', 'id_col' => 'meta_id', 'key_col' => 'meta_key', 'value_col' => 'meta_value', 'exclude_attachment_meta' => true),
            array('table' => $wpdb->termmeta, 'type' => 'term', 'id_col' => 'meta_id', 'key_col' => 'meta_key', 'value_col' => 'meta_value', 'exclude_attachment_meta' => false),
            array('table' => $wpdb->usermeta, 'type' => 'user', 'id_col' => 'umeta_id', 'key_col' => 'meta_key', 'value_col' => 'meta_value', 'exclude_attachment_meta' => false),
            array('table' => $wpdb->commentmeta, 'type' => 'comment', 'id_col' => 'meta_id', 'key_col' => 'meta_key', 'value_col' => 'meta_value', 'exclude_attachment_meta' => false),
        );

        foreach ($meta_sets as $set) {
            $after = 0;
            do {
                $args = array($after);
                $like = seo_images_webp_like_clause($set['value_col'], $terms, $args);
                $exclude = '';
                if ($set['exclude_attachment_meta']) {
                    $exclude = " AND {$set['key_col']} NOT IN ('_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes')";
                }
                $args[] = $limit;
                $sql = "SELECT {$set['id_col']} AS row_id, {$set['key_col']} AS meta_key, {$set['value_col']} AS meta_value
                        FROM {$set['table']}
                        WHERE {$set['id_col']} > %d AND {$like}{$exclude}
                        ORDER BY {$set['id_col']} ASC LIMIT %d";
                $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
                foreach ((array) $rows as $row) {
                    $after = max($after, (int) $row['row_id']);
                    $prepared = seo_images_webp_prepare_value_update($row['meta_value'], $pairs);
                    if (!empty($prepared['unsupported'])) {
                        $unsupported[] = $set['table'] . '#' . (int) $row['row_id'];
                        continue;
                    }
                    if (empty($prepared['changed'])) {
                        continue;
                    }
                    $changes[] = array(
                        'kind'      => 'meta',
                        'meta_type' => $set['type'],
                        'meta_id'   => (int) $row['row_id'],
                        'meta_key'  => (string) $row['meta_key'],
                        'old_value' => $prepared['old_value'],
                    );
                    $ok = update_metadata_by_mid($set['type'], (int) $row['row_id'], $prepared['new_value'], (string) $row['meta_key']);
                    if (!$ok) {
                        return new WP_Error('reference_update_meta', 'No se pudo actualizar una referencia guardada en metadatos de WordPress.', array('changes' => $changes));
                    }
                    $updated++;
                }
            } while (count((array) $rows) === $limit);
        }

        // Opciones del sitio, incluidos datos serializados de temas/widgets/plugins. Se omiten caches transitorias.
        $after = 0;
        do {
            $args = array($after);
            $like = seo_images_webp_like_clause('option_value', $terms, $args);
            $args[] = $limit;
            $sql = "SELECT option_id, option_name, option_value FROM {$wpdb->options}
                    WHERE option_id > %d AND {$like}
                      AND option_name NOT LIKE '\\_transient\\_%'
                      AND option_name NOT LIKE '\\_site\\_transient\\_%'
                    ORDER BY option_id ASC LIMIT %d";
            $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
            foreach ((array) $rows as $row) {
                $after = max($after, (int) $row['option_id']);
                $prepared = seo_images_webp_prepare_value_update($row['option_value'], $pairs);
                if (!empty($prepared['unsupported'])) {
                    $unsupported[] = $wpdb->options . '#' . (int) $row['option_id'];
                    continue;
                }
                if (empty($prepared['changed'])) {
                    continue;
                }
                $changes[] = array('kind' => 'option', 'name' => (string) $row['option_name'], 'old_value' => $prepared['old_value']);
                if (!update_option((string) $row['option_name'], $prepared['new_value'])) {
                    return new WP_Error('reference_update_option', 'No se pudo actualizar una referencia guardada en wp_options.', array('changes' => $changes));
                }
                $updated++;
            }
        } while (count((array) $rows) === $limit);

        // Descripciones de taxonomias (categorias, etiquetas, taxonomias WooCommerce, etc.).
        $after = 0;
        do {
            $args = array($after);
            $like = seo_images_webp_like_clause('description', $terms, $args);
            $args[] = $limit;
            $sql = "SELECT term_taxonomy_id, term_id, taxonomy, description FROM {$wpdb->term_taxonomy}
                    WHERE term_taxonomy_id > %d AND {$like}
                    ORDER BY term_taxonomy_id ASC LIMIT %d";
            $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
            foreach ((array) $rows as $row) {
                $after = max($after, (int) $row['term_taxonomy_id']);
                $new = str_replace(array_keys($pairs), array_values($pairs), (string) $row['description'], $count);
                if ($count < 1) {
                    continue;
                }
                $changes[] = array(
                    'kind' => 'term_description',
                    'tt_id' => (int) $row['term_taxonomy_id'],
                    'term_id' => (int) $row['term_id'],
                    'taxonomy' => (string) $row['taxonomy'],
                    'old_value' => (string) $row['description'],
                );
                $ok = $wpdb->update($wpdb->term_taxonomy, array('description' => $new), array('term_taxonomy_id' => (int) $row['term_taxonomy_id']), array('%s'), array('%d'));
                if ($ok === false) {
                    return new WP_Error('reference_update_term', 'No se pudo actualizar una referencia en una descripcion de taxonomia.', array('changes' => $changes));
                }
                clean_term_cache((int) $row['term_id'], (string) $row['taxonomy']);
                $updated++;
            }
        } while (count((array) $rows) === $limit);

        // Comentarios pueden contener HTML con imagenes antiguas.
        $after = 0;
        do {
            $args = array($after);
            $like = seo_images_webp_like_clause('comment_content', $terms, $args);
            $args[] = $limit;
            $sql = "SELECT comment_ID, comment_content FROM {$wpdb->comments}
                    WHERE comment_ID > %d AND {$like}
                    ORDER BY comment_ID ASC LIMIT %d";
            $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
            foreach ((array) $rows as $row) {
                $after = max($after, (int) $row['comment_ID']);
                $new = str_replace(array_keys($pairs), array_values($pairs), (string) $row['comment_content'], $count);
                if ($count < 1) {
                    continue;
                }
                $changes[] = array('kind' => 'comment', 'id' => (int) $row['comment_ID'], 'old_value' => (string) $row['comment_content']);
                $ok = $wpdb->update($wpdb->comments, array('comment_content' => $new), array('comment_ID' => (int) $row['comment_ID']), array('%s'), array('%d'));
                if ($ok === false) {
                    return new WP_Error('reference_update_comment', 'No se pudo actualizar una referencia en un comentario.', array('changes' => $changes));
                }
                clean_comment_cache((int) $row['comment_ID']);
                $updated++;
            }
        } while (count((array) $rows) === $limit);

        return array(
            'changes'     => $changes,
            'updated'     => $updated,
            'unsupported' => array_values(array_unique($unsupported)),
        );
    }
}

if (!function_exists('seo_images_webp_rollback_reference_updates')) {
    function seo_images_webp_rollback_reference_updates($changes) {
        global $wpdb;
        foreach (array_reverse((array) $changes) as $change) {
            if (empty($change['kind'])) {
                continue;
            }
            switch ($change['kind']) {
                case 'post':
                    $wpdb->update(
                        $wpdb->posts,
                        array('post_content' => $change['content'], 'post_excerpt' => $change['excerpt'], 'post_content_filtered' => isset($change['filtered']) ? $change['filtered'] : ''),
                        array('ID' => (int) $change['id']),
                        array('%s', '%s', '%s'),
                        array('%d')
                    );
                    clean_post_cache((int) $change['id']);
                    break;
                case 'meta':
                    update_metadata_by_mid($change['meta_type'], (int) $change['meta_id'], $change['old_value'], (string) $change['meta_key']);
                    break;
                case 'option':
                    update_option((string) $change['name'], $change['old_value']);
                    break;
                case 'term_description':
                    $wpdb->update($wpdb->term_taxonomy, array('description' => $change['old_value']), array('term_taxonomy_id' => (int) $change['tt_id']), array('%s'), array('%d'));
                    clean_term_cache((int) $change['term_id'], (string) $change['taxonomy']);
                    break;
                case 'comment':
                    $wpdb->update($wpdb->comments, array('comment_content' => $change['old_value']), array('comment_ID' => (int) $change['id']), array('%s'), array('%d'));
                    clean_comment_cache((int) $change['id']);
                    break;
            }
        }
    }
}

if (!function_exists('seo_images_webp_raw_contains_old_reference')) {
    function seo_images_webp_raw_contains_old_reference($raw, $pairs) {
        if (!is_string($raw) || $raw === '') {
            return false;
        }
        foreach (array_keys($pairs) as $old) {
            if ($old !== '' && strpos($raw, $old) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('seo_images_webp_remaining_references')) {
    function seo_images_webp_remaining_references($pairs, $terms) {
        global $wpdb;
        $remaining = array();
        $limit = 250;

        $checks = array(
            array('table' => $wpdb->posts, 'id' => 'ID', 'cols' => array('post_content', 'post_excerpt', 'post_content_filtered'), 'extra' => ''),
            array('table' => $wpdb->postmeta, 'id' => 'meta_id', 'cols' => array('meta_value'), 'extra' => " AND meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes')"),
            array('table' => $wpdb->termmeta, 'id' => 'meta_id', 'cols' => array('meta_value'), 'extra' => ''),
            array('table' => $wpdb->usermeta, 'id' => 'umeta_id', 'cols' => array('meta_value'), 'extra' => ''),
            array('table' => $wpdb->commentmeta, 'id' => 'meta_id', 'cols' => array('meta_value'), 'extra' => ''),
            array('table' => $wpdb->options, 'id' => 'option_id', 'cols' => array('option_value'), 'extra' => " AND option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'"),
            array('table' => $wpdb->term_taxonomy, 'id' => 'term_taxonomy_id', 'cols' => array('description'), 'extra' => ''),
            array('table' => $wpdb->comments, 'id' => 'comment_ID', 'cols' => array('comment_content'), 'extra' => ''),
        );

        foreach ($checks as $check) {
            $after = 0;
            do {
                $args = array($after);
                $likes = array();
                foreach ($check['cols'] as $column) {
                    $likes[] = seo_images_webp_like_clause($column, $terms, $args);
                }
                $args[] = $limit;
                $sql = 'SELECT ' . $check['id'] . ' AS row_id, ' . implode(', ', $check['cols']) . ' FROM ' . $check['table']
                    . ' WHERE ' . $check['id'] . ' > %d AND (' . implode(' OR ', $likes) . ')' . $check['extra']
                    . ' ORDER BY ' . $check['id'] . ' ASC LIMIT %d';
                $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
                foreach ((array) $rows as $row) {
                    $after = max($after, (int) $row['row_id']);
                    foreach ($check['cols'] as $column) {
                        if (seo_images_webp_raw_contains_old_reference(isset($row[$column]) ? (string) $row[$column] : '', $pairs)) {
                            $remaining[] = $check['table'] . '#' . (int) $row['row_id'];
                            break;
                        }
                    }
                    if (count($remaining) >= 10) {
                        break 2;
                    }
                }
            } while (count((array) $rows) === $limit);
        }

        return array_values(array_unique($remaining));
    }
}

if (!function_exists('seo_images_webp_prepare_attachment')) {
    function seo_images_webp_prepare_attachment($attachment_id) {
        $attachment_id = absint($attachment_id);
        $post = get_post($attachment_id);
        if (!$post || $post->post_type !== 'attachment') {
            return new WP_Error('invalid_attachment', 'El ID no corresponde a un attachment.');
        }
        if (!in_array((string) $post->post_mime_type, seo_images_webp_source_mimes(), true)) {
            return new WP_Error('already_converted', 'El attachment ya no es JPG/PNG.');
        }

        $old_attached_rel = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
        $old_metadata = wp_get_attachment_metadata($attachment_id);
        if (!is_array($old_metadata)) {
            $old_metadata = array();
        }
        $old_backup_sizes = get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);
        $had_backup_sizes = is_array($old_backup_sizes);
        if (!$had_backup_sizes) {
            $old_backup_sizes = array();
        }

        $entries = seo_images_webp_attachment_entries($attachment_id);
        if (is_wp_error($entries)) {
            return $entries;
        }

        $maps = array();
        $reserved = array();
        $before_total = 0;
        $after_total = 0;
        foreach ($entries as $entry) {
            $converted = seo_images_webp_convert_file($entry['old_path'], $reserved);
            if (is_wp_error($converted)) {
                seo_images_webp_delete_candidates($maps);
                return $converted;
            }
            $converted['refs'] = $entry['refs'];
            $maps[] = $converted;
            $reserved[] = $converted['new_path'];
            $before_total += (int) $converted['before'];
            $after_total += (int) $converted['after'];
        }

        if ($after_total >= $before_total) {
            seo_images_webp_delete_candidates($maps);
            return new WP_Error('no_disk_saving', 'El conjunto WebP no ocupa menos que los JPG/PNG actuales; no se modifica el attachment.');
        }

        $new_metadata = $old_metadata;
        $new_backup_sizes = $old_backup_sizes;
        $new_attached_rel = '';
        $new_full_path = '';

        foreach ($maps as $map) {
            $new_rel = seo_images_webp_relative_upload_path($map['new_path']);
            if ($new_rel === '') {
                seo_images_webp_delete_candidates($maps);
                return new WP_Error('new_path_outside_uploads', 'No se pudo obtener la ruta relativa del WebP.');
            }

            foreach ((array) $map['refs'] as $ref) {
                switch ($ref['kind']) {
                    case 'full':
                        $new_attached_rel = $new_rel;
                        $new_full_path = $map['new_path'];
                        $new_metadata['file'] = $new_rel;
                        $new_metadata['filesize'] = (int) $map['after'];
                        break;
                    case 'original_image':
                        $new_metadata['original_image'] = wp_basename($map['new_path']);
                        break;
                    case 'size':
                        $key = $ref['key'];
                        if (isset($new_metadata['sizes'][$key]) && is_array($new_metadata['sizes'][$key])) {
                            $new_metadata['sizes'][$key]['file'] = wp_basename($map['new_path']);
                            $new_metadata['sizes'][$key]['mime-type'] = 'image/webp';
                            $new_metadata['sizes'][$key]['filesize'] = (int) $map['after'];
                        }
                        break;
                    case 'backup':
                        $key = $ref['key'];
                        if (isset($new_backup_sizes[$key]) && is_array($new_backup_sizes[$key])) {
                            $new_backup_sizes[$key]['file'] = wp_basename($map['new_path']);
                            $new_backup_sizes[$key]['mime-type'] = 'image/webp';
                            $new_backup_sizes[$key]['filesize'] = (int) $map['after'];
                        }
                        break;
                }
            }
        }

        if ($new_attached_rel === '' || $new_full_path === '' || !is_file($new_full_path)) {
            seo_images_webp_delete_candidates($maps);
            return new WP_Error('missing_new_full', 'No se pudo identificar el WebP principal generado.');
        }

        return array(
            'attachment_id'       => $attachment_id,
            'old_mime'            => (string) $post->post_mime_type,
            'old_attached_rel'    => $old_attached_rel,
            'old_metadata'        => $old_metadata,
            'old_backup_sizes'    => $old_backup_sizes,
            'had_backup_sizes'    => $had_backup_sizes,
            'new_attached_rel'    => $new_attached_rel,
            'new_full_path'       => $new_full_path,
            'new_metadata'        => $new_metadata,
            'new_backup_sizes'    => $new_backup_sizes,
            'maps'                => $maps,
            'pairs'               => seo_images_webp_reference_pairs($maps),
            'terms'               => seo_images_webp_reference_terms($maps),
            'before'              => $before_total,
            'after'               => $after_total,
            'saved'               => max(0, $before_total - $after_total),
        );
    }
}

if (!function_exists('seo_images_webp_commit_attachment')) {
    function seo_images_webp_commit_attachment($plan) {
        $attachment_id = absint($plan['attachment_id']);

        update_attached_file($attachment_id, $plan['new_attached_rel']);
        wp_update_attachment_metadata($attachment_id, $plan['new_metadata']);

        if (!empty($plan['had_backup_sizes']) || !empty($plan['new_backup_sizes'])) {
            update_post_meta($attachment_id, '_wp_attachment_backup_sizes', $plan['new_backup_sizes']);
        }

        $updated = wp_update_post(
            array(
                'ID'             => $attachment_id,
                'post_mime_type' => 'image/webp',
            ),
            true
        );
        if (is_wp_error($updated)) {
            return $updated;
        }

        clean_post_cache($attachment_id);

        $current_rel  = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
        $current_meta = wp_get_attachment_metadata($attachment_id);
        $current_post = get_post($attachment_id);
        $current_file = get_attached_file($attachment_id, true);

        if (
            $current_rel !== (string) $plan['new_attached_rel']
            || !is_array($current_meta)
            || empty($current_meta['file'])
            || (string) $current_meta['file'] !== (string) $plan['new_attached_rel']
            || !$current_post
            || (string) $current_post->post_mime_type !== 'image/webp'
            || !$current_file
            || !is_file($current_file)
            || seo_images_optimizer_file_mime($current_file) !== 'image/webp'
        ) {
            return new WP_Error('attachment_commit_validation', 'WordPress no confirmo correctamente el nuevo archivo WebP.');
        }

        return true;
    }
}

if (!function_exists('seo_images_webp_rollback_attachment')) {
    function seo_images_webp_rollback_attachment($plan) {
        $attachment_id = absint($plan['attachment_id']);
        update_attached_file($attachment_id, $plan['old_attached_rel']);

        if (!empty($plan['old_metadata'])) {
            wp_update_attachment_metadata($attachment_id, $plan['old_metadata']);
        } else {
            delete_post_meta($attachment_id, '_wp_attachment_metadata');
        }

        if (!empty($plan['had_backup_sizes'])) {
            update_post_meta($attachment_id, '_wp_attachment_backup_sizes', $plan['old_backup_sizes']);
        } else {
            delete_post_meta($attachment_id, '_wp_attachment_backup_sizes');
        }

        wp_update_post(array('ID' => $attachment_id, 'post_mime_type' => $plan['old_mime']));
        clean_post_cache($attachment_id);
    }
}

if (!function_exists('seo_images_webp_retire_originals')) {
    function seo_images_webp_retire_originals($maps) {
        $token = wp_generate_password(12, false, false);
        $renamed = array();

        foreach ((array) $maps as $map) {
            $old = isset($map['old_path']) ? $map['old_path'] : '';
            if (!$old || !is_file($old)) {
                foreach (array_reverse($renamed) as $item) {
                    @rename($item['backup'], $item['old']);
                }
                return new WP_Error('old_file_missing', 'Un original desaparecio antes de poder retirarlo; se cancela el attachment.');
            }

            $backup = dirname($old) . '/.' . wp_basename($old) . '.seo-webp-delete-' . $token;
            if (file_exists($backup) || !@rename($old, $backup)) {
                foreach (array_reverse($renamed) as $item) {
                    @rename($item['backup'], $item['old']);
                }
                return new WP_Error('old_file_rename', 'No se pudo retirar uno de los JPG/PNG originales; se restaura el attachment.');
            }
            $renamed[] = array('old' => $old, 'backup' => $backup);
        }

        $deleted = 0;
        $leftovers = array();
        foreach ($renamed as $item) {
            wp_delete_file($item['backup']);
            if (is_file($item['backup'])) {
                @unlink($item['backup']);
            }
            if (is_file($item['backup'])) {
                $leftovers[] = $item['backup'];
            } else {
                $deleted++;
            }
        }

        return array('deleted' => $deleted, 'leftovers' => $leftovers);
    }
}

if (!function_exists('seo_images_webp_store_legacy_redirects')) {
    function seo_images_webp_store_legacy_redirects($maps) {
        $redirects = get_option('seo_images_webp_legacy_redirects', array());
        if (!is_array($redirects)) {
            $redirects = array();
        }

        foreach ((array) $maps as $map) {
            if (empty($map['old_path']) || empty($map['new_path'])) {
                continue;
            }
            $old_rel = seo_images_webp_relative_upload_path($map['old_path']);
            $new_rel = seo_images_webp_relative_upload_path($map['new_path']);
            if ($old_rel === '' || $new_rel === '' || $old_rel === $new_rel) {
                continue;
            }
            $redirects[$old_rel] = $new_rel;
        }

        if (get_option('seo_images_webp_legacy_redirects', null) === null) {
            add_option('seo_images_webp_legacy_redirects', $redirects, '', 'no');
        } else {
            update_option('seo_images_webp_legacy_redirects', $redirects, false);
        }
    }
}

if (!function_exists('seo_images_webp_maybe_redirect_legacy_request')) {
    function seo_images_webp_maybe_redirect_legacy_request() {
        if (is_admin() || wp_doing_ajax() || empty($_SERVER['REQUEST_URI'])) {
            return;
        }

        $uploads = wp_get_upload_dir();
        if (empty($uploads['baseurl']) || empty($uploads['basedir'])) {
            return;
        }

        $request_path = wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
        $uploads_path = wp_parse_url($uploads['baseurl'], PHP_URL_PATH);
        if (!is_string($request_path) || !is_string($uploads_path) || $uploads_path === '') {
            return;
        }

        $uploads_path = trailingslashit($uploads_path);
        if (strpos($request_path, $uploads_path) !== 0) {
            return;
        }

        $relative = ltrim(rawurldecode(substr($request_path, strlen($uploads_path))), '/');
        if ($relative === '') {
            return;
        }

        $redirects = get_option('seo_images_webp_legacy_redirects', array());
        if (!is_array($redirects) || empty($redirects[$relative])) {
            return;
        }

        $new_rel = ltrim((string) $redirects[$relative], '/');
        $target_file = trailingslashit($uploads['basedir']) . $new_rel;
        if (!is_file($target_file)) {
            return;
        }

        $target = trailingslashit($uploads['baseurl']) . str_replace('%2F', '/', rawurlencode($new_rel));
        $query = wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $target .= '?' . $query;
        }

        wp_redirect($target, 301, 'SEO Images WebP');
        exit;
    }
}
add_action('template_redirect', 'seo_images_webp_maybe_redirect_legacy_request', 0);

if (!function_exists('seo_images_webp_update_plugin_index')) {
    function seo_images_webp_update_plugin_index($attachment_id, $new_full_path) {
        global $wpdb;
        if (!function_exists('seo_images_table_images')) {
            return;
        }
        $table = seo_images_table_images();
        if (function_exists('seo_images_table_exists') && !seo_images_table_exists($table)) {
            return;
        }
        $wpdb->update(
            $table,
            array('nombre_archivo' => wp_basename($new_full_path)),
            array('attachment_id' => absint($attachment_id)),
            array('%s'),
            array('%d')
        );
    }
}

if (!function_exists('seo_images_webp_process_attachment')) {
    function seo_images_webp_process_attachment($attachment_id) {
        $result = array(
            'attachment_id' => absint($attachment_id),
            'converted'     => 0,
            'skipped'       => 0,
            'files_deleted' => 0,
            'refs_updated'  => 0,
            'before'        => 0,
            'after'         => 0,
            'saved'         => 0,
            'errors'        => array(),
        );

        $plan = seo_images_webp_prepare_attachment($attachment_id);
        if (is_wp_error($plan)) {
            $result['skipped'] = 1;
            if ($plan->get_error_code() !== 'no_disk_saving' && $plan->get_error_code() !== 'already_converted') {
                $result['errors'][] = 'Attachment #' . absint($attachment_id) . ': ' . $plan->get_error_message();
            }
            return $result;
        }

        $result['before'] = (int) $plan['before'];
        $result['after']  = (int) $plan['before'];

        $reference_result = seo_images_webp_apply_reference_updates($attachment_id, $plan['pairs'], $plan['terms']);
        if (is_wp_error($reference_result)) {
            $data = $reference_result->get_error_data();
            if (is_array($data) && !empty($data['changes'])) {
                seo_images_webp_rollback_reference_updates($data['changes']);
            }
            seo_images_webp_delete_candidates($plan['maps']);
            $result['skipped'] = 1;
            $result['errors'][] = 'Attachment #' . absint($attachment_id) . ': ' . $reference_result->get_error_message();
            return $result;
        }

        $changes = isset($reference_result['changes']) ? $reference_result['changes'] : array();
        $result['refs_updated'] = isset($reference_result['updated']) ? (int) $reference_result['updated'] : 0;
        if (!empty($reference_result['unsupported'])) {
            seo_images_webp_rollback_reference_updates($changes);
            seo_images_webp_delete_candidates($plan['maps']);
            $result['skipped'] = 1;
            $result['errors'][] = 'Attachment #' . absint($attachment_id) . ': hay referencias serializadas con objetos que no se modifican automaticamente (' . implode(', ', array_slice($reference_result['unsupported'], 0, 3)) . ').';
            return $result;
        }

        $remaining = seo_images_webp_remaining_references($plan['pairs'], $plan['terms']);
        if (!empty($remaining)) {
            seo_images_webp_rollback_reference_updates($changes);
            seo_images_webp_delete_candidates($plan['maps']);
            $result['skipped'] = 1;
            $result['errors'][] = 'Attachment #' . absint($attachment_id) . ': quedan referencias internas al JPG/PNG y no se borrara el original (' . implode(', ', array_slice($remaining, 0, 3)) . ').';
            return $result;
        }

        $commit = seo_images_webp_commit_attachment($plan);
        if (is_wp_error($commit)) {
            seo_images_webp_rollback_attachment($plan);
            seo_images_webp_rollback_reference_updates($changes);
            seo_images_webp_delete_candidates($plan['maps']);
            $result['skipped'] = 1;
            $result['errors'][] = 'Attachment #' . absint($attachment_id) . ': ' . $commit->get_error_message();
            return $result;
        }

        $retired = seo_images_webp_retire_originals($plan['maps']);
        if (is_wp_error($retired)) {
            seo_images_webp_rollback_attachment($plan);
            seo_images_webp_rollback_reference_updates($changes);
            seo_images_webp_delete_candidates($plan['maps']);
            $result['skipped'] = 1;
            $result['errors'][] = 'Attachment #' . absint($attachment_id) . ': ' . $retired->get_error_message();
            return $result;
        }

        seo_images_webp_update_plugin_index($attachment_id, $plan['new_full_path']);
        seo_images_webp_store_legacy_redirects($plan['maps']);
        delete_post_meta($attachment_id, '_seo_images_optimizer_signature');
        update_post_meta($attachment_id, '_seo_images_webp_converted_at', current_time('mysql'));
        update_post_meta($attachment_id, '_seo_images_webp_source_mime', $plan['old_mime']);
        update_post_meta($attachment_id, '_seo_images_webp_saved_bytes', (int) $plan['saved']);

        if (!empty($retired['leftovers'])) {
            update_post_meta($attachment_id, '_seo_images_webp_leftover_backups', array_values($retired['leftovers']));
            $result['errors'][] = 'Attachment #' . absint($attachment_id) . ': quedan ' . count($retired['leftovers']) . ' copias temporales ocultas que el servidor no permitio borrar.';
        } else {
            delete_post_meta($attachment_id, '_seo_images_webp_leftover_backups');
        }

        $result['converted']     = 1;
        $result['files_deleted'] = isset($retired['deleted']) ? (int) $retired['deleted'] : 0;
        $result['after']         = (int) $plan['after'];
        $result['saved']         = (int) $plan['saved'];
        return $result;
    }
}

if (!function_exists('seo_images_webp_attachment_batch')) {
    function seo_images_webp_attachment_batch($after_id, $limit) {
        global $wpdb;
        $after_id = max(0, absint($after_id));
        $limit    = max(1, min(3, absint($limit)));

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_status = 'inherit'
               AND post_mime_type IN ('image/jpeg','image/png')"
        );

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'attachment'
                   AND post_status = 'inherit'
                   AND post_mime_type IN ('image/jpeg','image/png')
                   AND ID > %d
                 ORDER BY ID ASC
                 LIMIT %d",
                $after_id,
                $limit
            )
        );

        return array(
            'ids'   => array_map('absint', (array) $ids),
            'total' => $total,
        );
    }
}

if (!function_exists('seo_images_webp_ajax_batch')) {
    function seo_images_webp_ajax_batch() {
        if (!current_user_can('manage_options') || !current_user_can('upload_files')) {
            wp_send_json_error(array('message' => 'No tienes permisos para convertir Media.'), 403);
        }

        check_ajax_referer('seo_images_webp_run', 'nonce');

        if (!seo_images_webp_supported()) {
            wp_send_json_error(array('message' => 'El editor de imagenes de este servidor no puede generar WebP.'), 409);
        }

        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('image');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(90);
        }
        @ignore_user_abort(true);

        $after_id = isset($_POST['after_id']) ? absint($_POST['after_id']) : 0;
        $limit    = isset($_POST['limit']) ? absint($_POST['limit']) : 1;
        $batch    = seo_images_webp_attachment_batch($after_id, $limit);

        $response = array(
            'processed'      => 0,
            'total'          => (int) $batch['total'],
            'next_after_id'  => $after_id,
            'converted'      => 0,
            'skipped'        => 0,
            'files_deleted'  => 0,
            'refs_updated'   => 0,
            'bytes_before'   => 0,
            'bytes_after'    => 0,
            'bytes_saved'    => 0,
            'errors'         => array(),
            'done'           => false,
        );

        foreach ($batch['ids'] as $attachment_id) {
            $response['next_after_id'] = max($response['next_after_id'], (int) $attachment_id);
            try {
                $item = seo_images_webp_process_attachment($attachment_id);
                $response['processed']++;
                $response['converted'] += (int) $item['converted'];
                $response['skipped'] += (int) $item['skipped'];
                $response['files_deleted'] += (int) $item['files_deleted'];
                $response['refs_updated'] += (int) $item['refs_updated'];
                $response['bytes_before'] += (int) $item['before'];
                $response['bytes_after'] += (int) $item['after'];
                $response['bytes_saved'] += (int) $item['saved'];
                if (!empty($item['errors'])) {
                    $response['errors'] = array_merge($response['errors'], array_slice($item['errors'], 0, 4));
                }
            } catch (Throwable $exception) {
                $response['processed']++;
                $response['skipped']++;
                $response['errors'][] = 'Attachment #' . $attachment_id . ': ' . sanitize_text_field($exception->getMessage());
            }
        }

        $response['done'] = count($batch['ids']) < max(1, min(3, $limit));
        $response['errors'] = array_slice(array_values(array_unique($response['errors'])), 0, 10);

        // Devuelve el inventario actual para que los contadores JPG/PNG/WebP
        // cambien en pantalla sin esperar a una recarga del panel.
        if (function_exists('seo_images_inventory_format_counts')) {
            $response['format_counts'] = seo_images_inventory_format_counts();
        }

        wp_send_json_success($response);
    }
}
add_action('wp_ajax_seo_images_webp_batch', 'seo_images_webp_ajax_batch');

if (!function_exists('seo_images_webp_enqueue_settings')) {
    function seo_images_webp_enqueue_settings() {
        if (!is_admin()) {
            return;
        }
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== 'seo-pictures-admin') {
            return;
        }

        wp_localize_script(
            'seo-images-optimizer',
            'seoImagesWebpSettings',
            array(
                'ajaxUrl'   => admin_url('admin-ajax.php'),
                'nonce'     => wp_create_nonce('seo_images_webp_run'),
                'batchSize' => 1,
                'supported' => seo_images_webp_supported() ? 1 : 0,
                'strings'   => array(
                    'confirm' => 'Se convertiran los JPG y PNG de Media local a WebP. Se mantiene el mismo attachment ID, se actualizan los metadatos y referencias internas conocidas y, tras validar cada attachment, se eliminan sus JPG/PNG originales. Esta operacion es destructiva. Haz copia de wp-content/uploads antes de continuar. Continuar?',
                    'running' => 'Convirtiendo JPG/PNG a WebP...',
                    'done'    => 'Conversion WebP completada.',
                    'error'   => 'La conversion WebP se detuvo por un error del servidor.',
                    'unsupported' => 'Este servidor no permite generar WebP con el editor de WordPress.',
                    'button'      => 'Convertir %d JPG/PNG a WebP',
                    'none'        => 'No hay JPG/PNG pendientes',
                ),
            )
        );
    }
}
add_action('admin_enqueue_scripts', 'seo_images_webp_enqueue_settings', 20);
