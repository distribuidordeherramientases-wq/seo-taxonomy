<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Comprueba si una clave de metadato suele almacenar una imagen o un medio.
 *
 * Se usa como filtro conservador para localizar IDs guardados por plugins,
 * constructores visuales, campos personalizados y opciones del tema.
 */
if (!function_exists('seo_pictures_anomalies_key_looks_like_image')) {
    function seo_pictures_anomalies_key_looks_like_image($key) {
        return (bool) preg_match(
            '/image|images|img|imagen|imagenes|photo|picture|foto|fotos|media|attachment|thumbnail|miniatura|gallery|galeria|logo|logotipo|icon|icono|banner|cabecera|fondo|avatar|featured|destacada/i',
            (string) $key
        );
    }
}


/**
 * Normaliza un nombre de archivo para poder compararlo aunque aparezca
 * codificado en una URL o contenga caracteres internacionales.
 */
if (!function_exists('seo_pictures_anomalies_normalize_filename')) {
    function seo_pictures_anomalies_normalize_filename($filename) {
        $filename = rawurldecode(basename((string) $filename));

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($filename, 'UTF-8');
        }

        return strtolower($filename);
    }
}


/**
 * Obtiene una raíz estable del archivo para reconocer tamaños antiguos que ya
 * no figuren en la metadata, por ejemplo foto-768x512.jpg o foto-scaled.jpg.
 */
if (!function_exists('seo_pictures_anomalies_get_filename_stem')) {
    function seo_pictures_anomalies_get_filename_stem($filename) {
        $filename = seo_pictures_anomalies_normalize_filename($filename);
        $stem = pathinfo($filename, PATHINFO_FILENAME);

        // WordPress añade estos sufijos al generar tamaños o escalar/rotar imágenes.
        $stem = preg_replace('/(?:-\d+x\d+|-scaled|-rotated)+$/i', '', $stem);

        return (string) $stem;
    }
}

/**
 * Añade un ID al resultado únicamente cuando pertenece a una imagen del
 * inventario que se está analizando.
 */
if (!function_exists('seo_pictures_anomalies_add_found_id')) {
    function seo_pictures_anomalies_add_found_id($candidate, $id_lookup, &$found) {
        $candidate = absint($candidate);

        if ($candidate > 0 && isset($id_lookup[$candidate])) {
            $found[$candidate] = true;
        }
    }
}


/**
 * Decide si un valor numérico puede interpretarse como ID de imagen según
 * la clave que lo contiene. Distingue, por ejemplo, image.id (válido) de
 * image.width o image.height (medidas que no son IDs de attachments).
 */
if (!function_exists('seo_pictures_anomalies_context_allows_numeric_id')) {
    function seo_pictures_anomalies_context_allows_numeric_id($context_key) {
        $context_key = strtolower(trim((string) $context_key));

        if ($context_key === '') {
            return false;
        }

        $parts = preg_split('/\s+/', $context_key);
        $leaf_key = (string) end($parts);
        $parent_key = count($parts) > 1 ? (string) $parts[count($parts) - 2] : '';

        // Campos directos como hero_image, custom_logo o gallery_ids.
        if (seo_pictures_anomalies_key_looks_like_image($leaf_key)) {
            return true;
        }

        // Estructuras del tipo image => ['id' => 123].
        if (
            preg_match('/^(?:id|ids|attachment_id|attachment_ids)$/i', $leaf_key) &&
            seo_pictures_anomalies_key_looks_like_image($context_key)
        ) {
            return true;
        }

        // Listas del tipo gallery_ids => [123, 456] o images => [123, 456].
        if (
            ctype_digit($leaf_key) &&
            preg_match('/images|imagenes|gallery|galeria|media_ids|attachment_ids|image_ids|imagen_ids/i', $parent_key)
        ) {
            return true;
        }

        return false;
    }
}

/**
 * Busca referencias de imágenes dentro de texto HTML, JSON, shortcodes y CSS.
 *
 * Se reconocen, entre otros:
 * - clases de WordPress como wp-image-123;
 * - atributos data-attachment-id y claves image_id / gallery_ids;
 * - bloques Gutenberg de imagen y galería;
 * - nombres de archivos originales y de miniaturas generadas por WordPress.
 */
if (!function_exists('seo_pictures_anomalies_extract_ids_from_text')) {
    function seo_pictures_anomalies_extract_ids_from_text($text, $id_lookup, $filename_lookup, &$found) {
        if (!is_scalar($text) || $text === '') {
            return;
        }

        $text = (string) $text;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Los constructores visuales suelen guardar las URLs escapadas como https:\/\/...
        $text = str_replace(['\\/', '\\u002F', '\\u002f'], '/', $text);

        $single_id_patterns = [
            '/\bwp-image-(\d+)\b/i',
            '/\battachment[-_](\d+)\b/i',
            '/\bdata-(?:attachment-)?id\s*=\s*["\']?(\d+)/i',
            '/\b(?:image|img|imagen|foto|media|attachment|thumbnail|miniatura|logo|logotipo|icon|icono|banner|cabecera|fondo|avatar)[_-]?id\b[^0-9]{0,20}(\d+)/i',
        ];

        foreach ($single_id_patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[1] as $candidate) {
                    seo_pictures_anomalies_add_found_id($candidate, $id_lookup, $found);
                }
            }
        }

        // IDs agrupados en shortcodes, galerías o atributos serializados.
        if (preg_match_all('/\b(?:ids|include|image_ids|imagen_ids|gallery_ids|galeria_ids|attachment_ids)\s*[=:]\s*["\']([0-9,\s]+)["\']/i', $text, $matches)) {
            foreach ($matches[1] as $id_list) {
                foreach (preg_split('/\s*,\s*/', trim($id_list)) as $candidate) {
                    seo_pictures_anomalies_add_found_id($candidate, $id_lookup, $found);
                }
            }
        }

        // Listas JSON usadas por galerías y constructores visuales.
        if (preg_match_all('/"(?:ids|image_ids|gallery_ids|attachment_ids)"\s*:\s*\[([^\]]+)\]/i', $text, $json_ids_matches)) {
            foreach ($json_ids_matches[1] as $id_list) {
                if (preg_match_all('/\d+/', $id_list, $number_matches)) {
                    foreach ($number_matches[0] as $candidate) {
                        seo_pictures_anomalies_add_found_id($candidate, $id_lookup, $found);
                    }
                }
            }
        }

        // Atributos JSON de bloques Gutenberg de imagen, galería o media-text.
        if (preg_match_all('/<!--\s*wp:(?:image|gallery|media-text)\b(.*?)-->/is', $text, $block_matches)) {
            foreach ($block_matches[1] as $block_attributes) {
                if (preg_match_all('/"id"\s*:\s*"?(\d+)"?/i', $block_attributes, $id_matches)) {
                    foreach ($id_matches[1] as $candidate) {
                        seo_pictures_anomalies_add_found_id($candidate, $id_lookup, $found);
                    }
                }

                if (preg_match_all('/"ids"\s*:\s*\[([^\]]+)\]/i', $block_attributes, $ids_matches)) {
                    foreach ($ids_matches[1] as $id_list) {
                        if (preg_match_all('/\d+/', $id_list, $number_matches)) {
                            foreach ($number_matches[0] as $candidate) {
                                seo_pictures_anomalies_add_found_id($candidate, $id_lookup, $found);
                            }
                        }
                    }
                }
            }
        }

        // El nombre de archivo permite detectar URLs absolutas, relativas, srcset y CSS url(...).
        if (preg_match_all('/[\p{L}\p{N}%_.~+@\-]+\.(?:jpe?g|png|gif|webp|avif|svg|bmp|tiff?)/iu', $text, $file_matches)) {
            foreach ($file_matches[0] as $matched_file) {
                $filename = seo_pictures_anomalies_normalize_filename($matched_file);

                $matching_ids = [];

                if (isset($filename_lookup[$filename])) {
                    $matching_ids += $filename_lookup[$filename];
                }

                // Si el tamaño ya no figura en la metadata, se compara también la raíz.
                $stem_key = 'stem:' . seo_pictures_anomalies_get_filename_stem($filename);

                if (isset($filename_lookup[$stem_key])) {
                    $matching_ids += $filename_lookup[$stem_key];
                }

                // Un mismo nombre puede existir en carpetas de años distintos. En caso de duda,
                // se protegen todas las coincidencias para evitar un falso "sin uso".
                foreach ($matching_ids as $attachment_id) {
                    seo_pictures_anomalies_add_found_id($attachment_id, $id_lookup, $found);
                }
            }
        }
    }
}

/**
 * Recorre datos deserializados o JSON conservando el nombre de las claves.
 * Solo toma valores numéricos como IDs cuando la clave parece relacionada
 * con imágenes; así evitamos confundir cualquier número con un attachment.
 */
if (!function_exists('seo_pictures_anomalies_collect_structured_ids')) {
    function seo_pictures_anomalies_collect_structured_ids($value, $id_lookup, $filename_lookup, &$found, $context_key = '') {
        if (is_object($value)) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            foreach ($value as $key => $child_value) {
                $child_context = trim($context_key . ' ' . (string) $key);
                seo_pictures_anomalies_collect_structured_ids(
                    $child_value,
                    $id_lookup,
                    $filename_lookup,
                    $found,
                    $child_context
                );
            }

            return;
        }

        if (!is_scalar($value) || $value === '') {
            return;
        }

        $scalar_value = (string) $value;

        // Los campos de imagen pueden guardar un ID único o una lista de IDs.
        if (seo_pictures_anomalies_context_allows_numeric_id($context_key)) {
            if (ctype_digit(trim($scalar_value))) {
                seo_pictures_anomalies_add_found_id($scalar_value, $id_lookup, $found);
            } elseif (preg_match_all('/\b\d+\b/', $scalar_value, $number_matches)) {
                foreach ($number_matches[0] as $candidate) {
                    seo_pictures_anomalies_add_found_id($candidate, $id_lookup, $found);
                }
            }
        }

        seo_pictures_anomalies_extract_ids_from_text(
            $scalar_value,
            $id_lookup,
            $filename_lookup,
            $found
        );
    }
}

/**
 * Devuelve todos los IDs de imágenes localizados dentro de un valor.
 * Admite texto normal, HTML, JSON y datos serializados por WordPress.
 */
if (!function_exists('seo_pictures_anomalies_extract_ids')) {
    function seo_pictures_anomalies_extract_ids($value, $id_lookup, $filename_lookup, $context_key = '') {
        $found = [];

        if (is_array($value) || is_object($value)) {
            seo_pictures_anomalies_collect_structured_ids(
                $value,
                $id_lookup,
                $filename_lookup,
                $found,
                $context_key
            );

            return array_map('intval', array_keys($found));
        }

        if (!is_scalar($value) || $value === '') {
            return [];
        }

        $raw_value = (string) $value;

        // Búsqueda directa en el texto sin depender de que JSON/serialized sea válido.
        seo_pictures_anomalies_extract_ids_from_text(
            $raw_value,
            $id_lookup,
            $filename_lookup,
            $found
        );

        // Un metadato de imagen sencillo puede contener solamente el ID numérico.
        if (
            seo_pictures_anomalies_context_allows_numeric_id($context_key) &&
            ctype_digit(trim($raw_value))
        ) {
            seo_pictures_anomalies_add_found_id($raw_value, $id_lookup, $found);
        }

        // Muchos plugins guardan arrays serializados mediante maybe_serialize().
        if (is_serialized($raw_value)) {
            $unserialized = maybe_unserialize($raw_value);

            seo_pictures_anomalies_collect_structured_ids(
                $unserialized,
                $id_lookup,
                $filename_lookup,
                $found,
                $context_key
            );
        }

        // Elementor, Gutenberg y otros constructores suelen guardar estructuras JSON.
        $json_value = json_decode($raw_value, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($json_value)) {
            seo_pictures_anomalies_collect_structured_ids(
                $json_value,
                $id_lookup,
                $filename_lookup,
                $found,
                $context_key
            );
        }

        return array_map('intval', array_keys($found));
    }
}

/**
 * Extrae de los metadatos todos los nombres de archivo asociados al attachment:
 * original, imagen escalada y tamaños derivados.
 */
if (!function_exists('seo_pictures_anomalies_collect_metadata_filenames')) {
    function seo_pictures_anomalies_collect_metadata_filenames($metadata, &$filenames) {
        if (is_object($metadata)) {
            $metadata = (array) $metadata;
        }

        if (!is_array($metadata)) {
            return;
        }

        foreach ($metadata as $key => $value) {
            if (
                in_array((string) $key, ['file', 'original_image'], true) &&
                is_string($value) &&
                $value !== ''
            ) {
                $filenames[] = $value;
            }

            if (is_array($value) || is_object($value)) {
                seo_pictures_anomalies_collect_metadata_filenames($value, $filenames);
            }
        }
    }
}

/**
 * Nombres de archivo que pueden aparecer en contenidos o metadatos.
 */
if (!function_exists('seo_pictures_anomalies_get_reference_filenames')) {
    function seo_pictures_anomalies_get_reference_filenames($attachment_id) {
        static $cache = [];

        $attachment_id = absint($attachment_id);

        if (isset($cache[$attachment_id])) {
            return $cache[$attachment_id];
        }

        $filenames = [];
        $attached_file = get_attached_file($attachment_id, true);
        $attachment_url = wp_get_attachment_url($attachment_id);

        if ($attached_file) {
            $filenames[] = basename($attached_file);
        }

        if ($attachment_url) {
            $filenames[] = basename((string) parse_url($attachment_url, PHP_URL_PATH));
        }

        seo_pictures_anomalies_collect_metadata_filenames(
            wp_get_attachment_metadata($attachment_id),
            $filenames
        );

        seo_pictures_anomalies_collect_metadata_filenames(
            get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true),
            $filenames
        );

        $filenames = array_filter(array_map('basename', $filenames));
        $filenames = array_values(array_unique(array_map('seo_pictures_anomalies_normalize_filename', $filenames)));
        $filenames = array_values(array_filter($filenames));

        $cache[$attachment_id] = $filenames;

        return $cache[$attachment_id];
    }
}

/**
 * Obtiene los archivos físicos que WordPress eliminará junto al attachment.
 * El cálculo incluye el original, la imagen escalada y las miniaturas generadas.
 */
if (!function_exists('seo_pictures_anomalies_get_attachment_files')) {
    function seo_pictures_anomalies_get_attachment_files($attachment_id) {
        static $cache = [];

        $attachment_id = absint($attachment_id);

        if (isset($cache[$attachment_id])) {
            return $cache[$attachment_id];
        }

        $files = [];
        $metadata_filenames = [];
        $attached_file = get_attached_file($attachment_id, true);
        $uploads = wp_get_upload_dir();
        $upload_basedir = isset($uploads['basedir']) ? wp_normalize_path($uploads['basedir']) : '';
        $attached_dir = $attached_file ? wp_normalize_path(dirname($attached_file)) : '';

        if ($attached_file && is_file($attached_file)) {
            $files[] = wp_normalize_path($attached_file);
        }

        seo_pictures_anomalies_collect_metadata_filenames(
            wp_get_attachment_metadata($attachment_id),
            $metadata_filenames
        );

        seo_pictures_anomalies_collect_metadata_filenames(
            get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true),
            $metadata_filenames
        );

        foreach ($metadata_filenames as $metadata_file) {
            $metadata_file = wp_normalize_path((string) $metadata_file);
            $candidates = [];

            // Algunas entradas de metadata contienen una ruta relativa completa y
            // otras solamente el nombre del archivo dentro de la carpeta del original.
            if ($upload_basedir !== '') {
                $candidates[] = trailingslashit($upload_basedir) . ltrim($metadata_file, '/');
            }

            if ($attached_dir !== '') {
                $candidates[] = trailingslashit($attached_dir) . basename($metadata_file);
            }

            if (is_file($metadata_file)) {
                $candidates[] = $metadata_file;
            }

            foreach ($candidates as $candidate) {
                $candidate = wp_normalize_path($candidate);

                if (is_file($candidate)) {
                    $files[] = $candidate;
                }
            }
        }

        $cache[$attachment_id] = array_values(array_unique($files));

        return $cache[$attachment_id];
    }
}

/**
 * Calcula el espacio total ocupado por una imagen y todos sus tamaños derivados.
 */
if (!function_exists('seo_pictures_anomalies_get_attachment_size')) {
    function seo_pictures_anomalies_get_attachment_size($attachment_id) {
        static $cache = [];

        $attachment_id = absint($attachment_id);

        if (isset($cache[$attachment_id])) {
            return $cache[$attachment_id];
        }

        $total = 0;

        foreach (seo_pictures_anomalies_get_attachment_files($attachment_id) as $file) {
            $size = @filesize($file);

            if ($size !== false) {
                $total += (int) $size;
            }
        }

        $cache[$attachment_id] = $total;

        return $cache[$attachment_id];
    }
}

/**
 * Construye el mapa de usos para las imágenes recibidas.
 *
 * El análisis es deliberadamente conservador: ante una referencia posible se
 * considera que la imagen está usada. Esto reduce falsos negativos y evita que
 * una imagen válida aparezca por error en la lista de borrado.
 */
if (!function_exists('seo_pictures_anomalies_build_usage_report')) {
    function seo_pictures_anomalies_build_usage_report($attachments) {
        global $wpdb;

        $usage = [];
        $id_lookup = [];
        $filename_lookup = [];
        $attachment_parents = [];

        foreach ($attachments as $attachment) {
            $attachment_id = is_object($attachment) ? absint($attachment->ID) : absint($attachment);

            if ($attachment_id === 0) {
                continue;
            }

            $usage[$attachment_id] = 0;
            $id_lookup[$attachment_id] = true;
            $attachment_parents[$attachment_id] = (
                is_object($attachment) && isset($attachment->post_parent)
            ) ? absint($attachment->post_parent) : 0;

            foreach (seo_pictures_anomalies_get_reference_filenames($attachment_id) as $filename) {
                $lookup_keys = [
                    $filename,
                    'stem:' . seo_pictures_anomalies_get_filename_stem($filename),
                ];

                foreach (array_unique($lookup_keys) as $lookup_key) {
                    if (!isset($filename_lookup[$lookup_key])) {
                        $filename_lookup[$lookup_key] = [];
                    }

                    $filename_lookup[$lookup_key][$attachment_id] = $attachment_id;
                }
            }
        }

        if (empty($id_lookup)) {
            return $usage;
        }

        // Registra una o varias referencias sin aceptar IDs ajenos al análisis actual.
        $register_usage = static function($attachment_ids, $amount = 1) use (&$usage, $id_lookup) {
            foreach ((array) $attachment_ids as $attachment_id) {
                $attachment_id = absint($attachment_id);

                if (isset($id_lookup[$attachment_id])) {
                    $usage[$attachment_id] += max(1, (int) $amount);
                }
            }
        };

        /*
         * 1) Imágenes destacadas de cualquier tipo de contenido.
         * Incluye posts, páginas, productos y variaciones de WooCommerce.
         */
        $thumbnail_rows = $wpdb->get_results("\n            SELECT pm.meta_value AS attachment_id, COUNT(DISTINCT pm.post_id) AS total\n            FROM {$wpdb->postmeta} pm\n            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id\n            WHERE pm.meta_key = '_thumbnail_id'\n              AND p.post_status NOT IN ('trash', 'auto-draft')\n            GROUP BY pm.meta_value\n        ");

        foreach ($thumbnail_rows as $row) {
            $register_usage($row->attachment_id, (int) $row->total);
        }

        /*
         * 2) Imágenes de categorías y otras taxonomías.
         */
        $term_thumbnail_rows = $wpdb->get_results("\n            SELECT meta_value AS attachment_id, COUNT(DISTINCT term_id) AS total\n            FROM {$wpdb->termmeta}\n            WHERE meta_key = 'thumbnail_id'\n            GROUP BY meta_value\n        ");

        foreach ($term_thumbnail_rows as $row) {
            $register_usage($row->attachment_id, (int) $row->total);
        }

        /*
         * 3) Galerías de producto WooCommerce.
         * Se separa el CSV para evitar el falso positivo clásico de LIKE '%12%',
         * que también encontraba IDs como 112 o 120.
         */
        $gallery_rows = $wpdb->get_results("\n            SELECT pm.post_id, pm.meta_value\n            FROM {$wpdb->postmeta} pm\n            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id\n            WHERE pm.meta_key = '_product_image_gallery'\n              AND p.post_status NOT IN ('trash', 'auto-draft')\n        ");

        foreach ($gallery_rows as $row) {
            $gallery_ids = array_unique(array_filter(array_map(
                'absint',
                preg_split('/\s*,\s*/', (string) $row->meta_value)
            )));

            $register_usage($gallery_ids);
        }

        /*
         * 4) Contenido y extractos de todos los tipos de contenido activos.
         * Aquí se detectan enlaces, etiquetas <img>, srcset, Gutenberg y URLs.
         */
        $content_rows = $wpdb->get_results("\n            SELECT ID, post_content, post_excerpt, post_content_filtered\n            FROM {$wpdb->posts}\n            WHERE post_type NOT IN ('attachment', 'revision')\n              AND post_status NOT IN ('trash', 'auto-draft')\n              AND (post_content <> '' OR post_excerpt <> '' OR post_content_filtered <> '')\n        ");

        $implicit_gallery_parent_ids = [];

        foreach ($content_rows as $row) {
            $combined_content = (string) $row->post_content . "\n" .
                (string) $row->post_excerpt . "\n" .
                (string) $row->post_content_filtered;

            $content_ids = seo_pictures_anomalies_extract_ids(
                $combined_content,
                $id_lookup,
                $filename_lookup,
                'post_content post_excerpt post_content_filtered'
            );

            // Una misma imagen solo suma una vez por contenido, aunque aparezca repetida.
            $register_usage(array_unique($content_ids));

            /*
             * Una galería clásica [gallery] sin ids/include muestra los attachments
             * cuyo post_parent es la entrada. Solo en ese caso la asociación padre
             * cuenta como uso; estar simplemente "subida a" una entrada no basta.
             */
            if (preg_match_all('/\[gallery\b([^\]]*)\]/i', $combined_content, $gallery_shortcode_matches)) {
                foreach ($gallery_shortcode_matches[1] as $gallery_attributes) {
                    if (!preg_match('/\b(?:ids|include)\s*=/i', $gallery_attributes)) {
                        $implicit_gallery_parent_ids[absint($row->ID)] = true;
                    }
                }
            }
        }

        if (!empty($implicit_gallery_parent_ids)) {
            foreach ($attachment_parents as $attachment_id => $parent_id) {
                if ($parent_id > 0 && isset($implicit_gallery_parent_ids[$parent_id])) {
                    $register_usage($attachment_id);
                }
            }
        }

        /*
         * 5) Metadatos de posts, páginas, productos y constructores visuales.
         * Se revisan claves relacionadas con imágenes y valores que contienen URLs
         * o marcas habituales de WordPress. Se excluye la metadata del propio attachment.
         */
        $meta_key_regex = 'image|images|img|imagen|imagenes|photo|picture|foto|fotos|media|attachment|thumbnail|miniatura|gallery|galeria|logo|logotipo|icon|icono|banner|cabecera|fondo|avatar|featured|destacada';
        $image_value_regex = '\\.(jpe?g|png|gif|webp|avif|svg|bmp|tiff?)([^a-z0-9]|$)';
        $postmeta_rows = $wpdb->get_results($wpdb->prepare("\n            SELECT pm.post_id, pm.meta_key, pm.meta_value\n            FROM {$wpdb->postmeta} pm\n            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id\n            WHERE p.post_type <> 'attachment'\n              AND p.post_status NOT IN ('trash', 'auto-draft')\n              AND pm.meta_key NOT IN (\n                  '_thumbnail_id',\n                  '_product_image_gallery',\n                  '_wp_attached_file',\n                  '_wp_attachment_metadata',\n                  '_wp_attachment_backup_sizes'\n              )\n              AND (\n                  pm.meta_key REGEXP %s\n                  OR pm.meta_value REGEXP %s\n                  OR pm.meta_value LIKE %s\n                  OR pm.meta_value LIKE %s\n                  OR pm.meta_value LIKE %s\n                  OR pm.meta_value LIKE %s\n                  OR pm.meta_value LIKE %s\n                  OR pm.meta_value LIKE %s\n                  OR pm.meta_value LIKE %s\n              )\n        ",
            $meta_key_regex,
            $image_value_regex,
            '%' . $wpdb->esc_like('/uploads/') . '%',
            '%' . $wpdb->esc_like('wp-image-') . '%',
            '%' . $wpdb->esc_like('wp:image') . '%',
            '%' . $wpdb->esc_like('attachment_id') . '%',
            '%' . $wpdb->esc_like('image_id') . '%',
            '%' . $wpdb->esc_like('"image') . '%',
            '%' . $wpdb->esc_like('"gallery') . '%'
        ));

        foreach ($postmeta_rows as $row) {
            $meta_ids = seo_pictures_anomalies_extract_ids(
                $row->meta_value,
                $id_lookup,
                $filename_lookup,
                $row->meta_key
            );

            $register_usage(array_unique($meta_ids));
        }

        /*
         * 6) Descripciones de categorías, etiquetas y taxonomías personalizadas.
         */
        $term_description_rows = $wpdb->get_results("\n            SELECT term_taxonomy_id, description\n            FROM {$wpdb->term_taxonomy}\n            WHERE description <> ''\n        ");

        foreach ($term_description_rows as $row) {
            $term_ids = seo_pictures_anomalies_extract_ids(
                $row->description,
                $id_lookup,
                $filename_lookup,
                'term_description'
            );

            $register_usage(array_unique($term_ids));
        }

        /*
         * 7) Metadatos de términos usados por temas y plugins.
         */
        $termmeta_rows = $wpdb->get_results($wpdb->prepare("\n            SELECT term_id, meta_key, meta_value\n            FROM {$wpdb->termmeta}\n            WHERE meta_key <> 'thumbnail_id'\n              AND (\n                  meta_key REGEXP %s\n                  OR meta_value REGEXP %s\n                  OR meta_value LIKE %s\n                  OR meta_value LIKE %s\n                  OR meta_value LIKE %s\n                  OR meta_value LIKE %s\n                  OR meta_value LIKE %s\n              )\n        ",
            $meta_key_regex,
            $image_value_regex,
            '%' . $wpdb->esc_like('/uploads/') . '%',
            '%' . $wpdb->esc_like('wp-image-') . '%',
            '%' . $wpdb->esc_like('attachment_id') . '%',
            '%' . $wpdb->esc_like('"image') . '%',
            '%' . $wpdb->esc_like('"gallery') . '%'
        ));

        foreach ($termmeta_rows as $row) {
            $termmeta_ids = seo_pictures_anomalies_extract_ids(
                $row->meta_value,
                $id_lookup,
                $filename_lookup,
                $row->meta_key
            );

            $register_usage(array_unique($termmeta_ids));
        }

        /*
         * 8) Ajustes del sitio y del tema: logo, icono, widgets, cabeceras,
         * fondos y opciones de plugins que almacenan un ID o una URL.
         */
        $option_rows = $wpdb->get_results($wpdb->prepare("\n            SELECT option_name, option_value\n            FROM {$wpdb->options}\n            WHERE option_name NOT LIKE '\\_transient\\_%%'\n              AND option_name NOT LIKE '\\_site\\_transient\\_%%'\n              AND (\n                  option_name REGEXP %s\n                  OR option_value REGEXP %s\n                  OR option_value LIKE %s\n                  OR option_value LIKE %s\n                  OR option_value LIKE %s\n                  OR option_value LIKE %s\n                  OR option_value LIKE %s\n                  OR option_value LIKE %s\n              )\n        ",
            $meta_key_regex,
            $image_value_regex,
            '%' . $wpdb->esc_like('/uploads/') . '%',
            '%' . $wpdb->esc_like('wp-image-') . '%',
            '%' . $wpdb->esc_like('attachment_id') . '%',
            '%' . $wpdb->esc_like('image_id') . '%',
            '%' . $wpdb->esc_like('"image') . '%',
            '%' . $wpdb->esc_like('"gallery') . '%'
        ));

        foreach ($option_rows as $row) {
            $option_ids = seo_pictures_anomalies_extract_ids(
                $row->option_value,
                $id_lookup,
                $filename_lookup,
                $row->option_name
            );

            $register_usage(array_unique($option_ids));
        }

        /*
         * 9) Metadatos de usuarios, por ejemplo avatares o imágenes de perfil.
         */
        $usermeta_rows = $wpdb->get_results($wpdb->prepare("\n            SELECT umeta_id, meta_key, meta_value\n            FROM {$wpdb->usermeta}\n            WHERE meta_key REGEXP %s\n               OR meta_value REGEXP %s\n               OR meta_value LIKE %s\n               OR meta_value LIKE %s\n               OR meta_value LIKE %s\n               OR meta_value LIKE %s\n               OR meta_value LIKE %s\n        ",
            $meta_key_regex,
            $image_value_regex,
            '%' . $wpdb->esc_like('/uploads/') . '%',
            '%' . $wpdb->esc_like('attachment_id') . '%',
            '%' . $wpdb->esc_like('image_id') . '%',
            '%' . $wpdb->esc_like('"image') . '%',
            '%' . $wpdb->esc_like('"gallery') . '%'
        ));

        foreach ($usermeta_rows as $row) {
            $user_ids = seo_pictures_anomalies_extract_ids(
                $row->meta_value,
                $id_lookup,
                $filename_lookup,
                $row->meta_key
            );

            $register_usage(array_unique($user_ids));
        }

        /*
         * 10) Comentarios que contienen una URL o etiqueta de imagen.
         */
        $comment_rows = $wpdb->get_results($wpdb->prepare("\n            SELECT comment_ID, comment_content\n            FROM {$wpdb->comments}\n            WHERE comment_approved NOT IN ('trash', 'spam')\n              AND (\n                  comment_content LIKE %s\n                  OR comment_content LIKE %s\n                  OR comment_content LIKE %s\n              )\n        ",
            '%' . $wpdb->esc_like('/uploads/') . '%',
            '%' . $wpdb->esc_like('wp-image-') . '%',
            '%' . $wpdb->esc_like('attachment_id') . '%'
        ));

        foreach ($comment_rows as $row) {
            $comment_ids = seo_pictures_anomalies_extract_ids(
                $row->comment_content,
                $id_lookup,
                $filename_lookup,
                'comment_content'
            );

            $register_usage(array_unique($comment_ids));
        }

        /*
         * La relación post_parent, por sí sola, no se considera uso. WordPress
         * mantiene esa asociación aunque una imagen se quite posteriormente del
         * producto o la página. Ya se protege arriba cuando existe una galería
         * clásica [gallery] que depende realmente de esos attachments.
         */

        return $usage;
    }
}
