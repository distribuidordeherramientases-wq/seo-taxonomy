<?php
/**
 * Import / export y resumen operativo para Google esquema.
 *
 * El fichero exportado contiene la identidad real de WordPress (tipo, ID,
 * slug, nombre y ruta) junto a las columnas editables de correspondencia.
 * Al importar, nombre y ruta se consideran informativos: la identidad se
 * resuelve por object_type + object_id y, como respaldo, por type + slug.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_classifier_google_schema_export_fields')) {
    function seo_classifier_google_schema_export_fields() {
        return [
            'object_type',
            'object_id',
            'slug',
            'current_name',
            'current_path',
            'google_taxonomy_id',
            'google_name_en',
            'google_path_en',
            'google_alias_es',
            'suggested_wp_name',
            'shopping_query',
            'status',
            'confidence',
            'notes',
        ];
    }
}

if (!function_exists('seo_classifier_google_schema_mapping_has_relation')) {
    function seo_classifier_google_schema_mapping_has_relation(array $row) {
        foreach ([
            'google_taxonomy_id',
            'google_name_en',
            'google_path_en',
            'google_alias_es',
            'suggested_wp_name',
            'shopping_query',
        ] as $field) {
            if (trim((string)($row[$field] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('seo_classifier_google_schema_export_rows')) {
    function seo_classifier_google_schema_export_rows($nodes = null, $mapping = null) {
        if (!is_array($nodes)) {
            $nodes = seo_classifier_google_schema_collect_nodes();
        }
        if (!is_array($mapping)) {
            $mapping = seo_classifier_google_schema_mapping_index();
        }

        $rows = [];
        foreach ($nodes as $node) {
            $type = sanitize_key((string)($node['object_type'] ?? ''));
            $id = absint($node['object_id'] ?? 0);
            if ($id < 1 || $type === '') {
                continue;
            }
            $key = $type . ':' . $id;
            $saved = (array)($mapping[$key] ?? []);
            $rows[] = [
                'object_type' => $type,
                'object_id' => $id,
                'slug' => (string)($node['slug'] ?? ''),
                'current_name' => (string)($node['name'] ?? ''),
                'current_path' => (string)($node['path'] ?? ''),
                'google_taxonomy_id' => (string)($saved['google_taxonomy_id'] ?? ''),
                'google_name_en' => (string)($saved['google_name_en'] ?? ''),
                'google_path_en' => (string)($saved['google_path_en'] ?? ''),
                'google_alias_es' => (string)($saved['google_alias_es'] ?? ''),
                'suggested_wp_name' => (string)($saved['suggested_wp_name'] ?? ''),
                'shopping_query' => (string)($saved['shopping_query'] ?? ''),
                'status' => (string)($saved['status'] ?? 'pending'),
                'confidence' => isset($saved['confidence']) ? (float)$saved['confidence'] : 0.0,
                'notes' => (string)($saved['notes'] ?? ''),
            ];
        }
        return $rows;
    }
}

if (!function_exists('seo_classifier_google_schema_kpis')) {
    function seo_classifier_google_schema_kpis($nodes = null, $mapping = null) {
        if (!is_array($nodes)) {
            $nodes = seo_classifier_google_schema_collect_nodes();
        }
        if (!is_array($mapping)) {
            $mapping = seo_classifier_google_schema_mapping_index();
        }

        $total = count($nodes);
        $related = 0;
        $approved = 0;
        $review = 0;
        $rejected = 0;
        $pending = 0;
        $ready_ojeador = 0;

        foreach ($nodes as $node) {
            $type = sanitize_key((string)($node['object_type'] ?? ''));
            $id = absint($node['object_id'] ?? 0);
            $row = (array)($mapping[$type . ':' . $id] ?? []);
            $status = sanitize_key((string)($row['status'] ?? 'pending'));
            if (!isset(seo_classifier_google_schema_statuses()[$status])) {
                $status = 'pending';
            }

            if (seo_classifier_google_schema_mapping_has_relation($row)) {
                $related++;
            }
            if ($status === 'approved') {
                $approved++;
                foreach (['shopping_query', 'google_alias_es', 'google_name_en'] as $search_field) {
                    if (trim((string)($row[$search_field] ?? '')) !== '') {
                        $ready_ojeador++;
                        break;
                    }
                }
            } elseif ($status === 'review') {
                $review++;
            } elseif ($status === 'rejected') {
                $rejected++;
            } else {
                $pending++;
            }
        }

        return [
            'total' => $total,
            'related' => $related,
            'approved' => $approved,
            'review' => $review,
            'rejected' => $rejected,
            'pending' => $pending,
            'coverage_pct' => $total > 0 ? round(($related / $total) * 100, 1) : 0.0,
            'ready_ojeador' => $ready_ojeador,
        ];
    }
}

if (!function_exists('seo_classifier_google_schema_export_payload')) {
    function seo_classifier_google_schema_export_payload() {
        $nodes = seo_classifier_google_schema_collect_nodes();
        $mapping = seo_classifier_google_schema_mapping_index();
        return [
            'schema' => 'seo_google_schema_map',
            'version' => seo_classifier_google_schema_version(),
            'generated_at' => current_time('c'),
            'site' => home_url('/'),
            'kpis' => seo_classifier_google_schema_kpis($nodes, $mapping),
            'items' => seo_classifier_google_schema_export_rows($nodes, $mapping),
        ];
    }
}

if (!function_exists('seo_classifier_google_schema_admin_post_export')) {
    function seo_classifier_google_schema_admin_post_export() {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.', 403);
        }
        check_admin_referer('seo_classifier_google_schema_export');

        $format = sanitize_key((string)($_GET['format'] ?? 'csv'));
        if (!in_array($format, ['csv', 'json'], true)) {
            $format = 'csv';
        }
        $stamp = current_time('Ymd-His');
        nocache_headers();

        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . sanitize_file_name('seo-google-esquema-' . $stamp . '.json') . '"');
            echo wp_json_encode(
                seo_classifier_google_schema_export_payload(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            exit;
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name('seo-google-esquema-' . $stamp . '.csv') . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        if (!$out) {
            wp_die('No se pudo generar el CSV.');
        }
        // BOM UTF-8 para Excel en Windows.
        fwrite($out, "\xEF\xBB\xBF");
        $fields = seo_classifier_google_schema_export_fields();
        fputcsv($out, $fields, ';', '"', '');
        foreach (seo_classifier_google_schema_export_rows() as $row) {
            $line = [];
            foreach ($fields as $field) {
                $line[] = $row[$field] ?? '';
            }
            fputcsv($out, $line, ';', '"', '');
        }
        fclose($out);
        exit;
    }
    add_action('admin_post_seo_classifier_google_schema_export', 'seo_classifier_google_schema_admin_post_export');
}

if (!function_exists('seo_classifier_google_schema_csv_delimiter')) {
    function seo_classifier_google_schema_csv_delimiter($line) {
        $scores = [
            ';' => substr_count((string)$line, ';'),
            ',' => substr_count((string)$line, ','),
            "\t" => substr_count((string)$line, "\t"),
        ];
        arsort($scores);
        $delimiter = (string)key($scores);
        return max($scores) > 0 ? $delimiter : ';';
    }
}

if (!function_exists('seo_classifier_google_schema_parse_csv')) {
    function seo_classifier_google_schema_parse_csv($path) {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return new WP_Error('seo_google_schema_csv_open', 'No se pudo abrir el CSV.');
        }

        $first = fgets($handle);
        if ($first === false) {
            fclose($handle);
            return new WP_Error('seo_google_schema_csv_empty', 'El CSV está vacío.');
        }
        $delimiter = seo_classifier_google_schema_csv_delimiter($first);
        rewind($handle);

        $headers = fgetcsv($handle, 0, $delimiter, '"', '');
        if (!is_array($headers) || !$headers) {
            fclose($handle);
            return new WP_Error('seo_google_schema_csv_headers', 'No se pudieron leer las cabeceras del CSV.');
        }

        $headers = array_map(static function($header) {
            $header = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header);
            return sanitize_key(trim($header));
        }, $headers);

        if (!in_array('object_type', $headers, true)) {
            fclose($handle);
            return new WP_Error('seo_google_schema_csv_identity', 'El CSV debe contener la columna object_type.');
        }
        if (!in_array('object_id', $headers, true) && !in_array('slug', $headers, true)) {
            fclose($handle);
            return new WP_Error('seo_google_schema_csv_identity', 'El CSV debe contener object_id o slug.');
        }

        $rows = [];
        while (($values = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($values === [null] || $values === []) {
                continue;
            }
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = isset($values[$index]) ? (string)$values[$index] : '';
            }
            if (trim(implode('', array_map('strval', $row))) !== '') {
                $rows[] = $row;
            }
        }
        fclose($handle);
        return $rows;
    }
}

if (!function_exists('seo_classifier_google_schema_parse_json')) {
    function seo_classifier_google_schema_parse_json($path) {
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return new WP_Error('seo_google_schema_json_empty', 'El JSON está vacío o no se pudo leer.');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return new WP_Error('seo_google_schema_json_invalid', 'El JSON no es válido.');
        }
        $items = isset($decoded['items']) && is_array($decoded['items']) ? $decoded['items'] : $decoded;
        if (!is_array($items)) {
            return new WP_Error('seo_google_schema_json_items', 'El JSON no contiene una lista de items válida.');
        }
        return array_values(array_filter($items, 'is_array'));
    }
}

if (!function_exists('seo_classifier_google_schema_slug_index')) {
    function seo_classifier_google_schema_slug_index() {
        $index = [];
        foreach (seo_classifier_google_schema_collect_nodes() as $node) {
            $type = sanitize_key((string)($node['object_type'] ?? ''));
            $slug = sanitize_title((string)($node['slug'] ?? ''));
            $id = absint($node['object_id'] ?? 0);
            if ($type === '' || $slug === '' || $id < 1) {
                continue;
            }
            $key = $type . ':' . $slug;
            if (!isset($index[$key])) {
                $index[$key] = $id;
            } else {
                // Un slug duplicado no es un respaldo seguro.
                $index[$key] = 0;
            }
        }
        return $index;
    }
}

if (!function_exists('seo_classifier_google_schema_import_row_has_data')) {
    function seo_classifier_google_schema_import_row_has_data(array $row) {
        foreach ([
            'google_taxonomy_id',
            'google_name_en',
            'google_path_en',
            'google_alias_es',
            'suggested_wp_name',
            'shopping_query',
            'notes',
        ] as $field) {
            if (trim((string)($row[$field] ?? '')) !== '') {
                return true;
            }
        }
        $status = sanitize_key((string)($row['status'] ?? 'pending'));
        if ($status !== '' && $status !== 'pending') {
            return true;
        }
        return isset($row['confidence']) && (float)$row['confidence'] > 0;
    }
}

if (!function_exists('seo_classifier_google_schema_import_rows')) {
    function seo_classifier_google_schema_import_rows(array $rows, $source = 'import') {
        $report = [
            'processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];
        $slug_index = seo_classifier_google_schema_slug_index();
        $valid_types = seo_classifier_google_schema_node_types();
        $valid_statuses = seo_classifier_google_schema_statuses();

        foreach ($rows as $offset => $row) {
            if (!is_array($row)) {
                $report['skipped']++;
                continue;
            }
            $report['processed']++;
            $type = sanitize_key((string)($row['object_type'] ?? ''));
            $id = absint($row['object_id'] ?? 0);
            $slug = sanitize_title((string)($row['slug'] ?? ''));

            if (!isset($valid_types[$type])) {
                $report['errors'][] = 'Fila ' . ($offset + 2) . ': object_type no válido.';
                continue;
            }
            if ($id < 1 || !seo_classifier_google_schema_node_exists($type, $id)) {
                $fallback_id = $slug !== '' ? absint($slug_index[$type . ':' . $slug] ?? 0) : 0;
                if ($fallback_id > 0 && seo_classifier_google_schema_node_exists($type, $fallback_id)) {
                    $id = $fallback_id;
                } else {
                    $report['errors'][] = 'Fila ' . ($offset + 2) . ': no se encuentra ' . $type . ' #' . absint($row['object_id'] ?? 0) . ($slug !== '' ? ' (' . $slug . ')' : '') . '.';
                    continue;
                }
            }

            // Las filas totalmente vacías se dejan sin tocar; permiten usar el export como plantilla.
            if (!seo_classifier_google_schema_import_row_has_data($row)) {
                $report['skipped']++;
                continue;
            }

            $status = sanitize_key((string)($row['status'] ?? 'pending'));
            if (!isset($valid_statuses[$status])) {
                $status = 'pending';
            }
            $existing = (array)(seo_classifier_google_schema_get_mapping($type, $id) ?: []);
            $pick = static function($field, $default = '') use ($row, $existing) {
                if (array_key_exists($field, $row)) {
                    return $row[$field];
                }
                return array_key_exists($field, $existing) ? $existing[$field] : $default;
            };
            if (!array_key_exists('status', $row) && !empty($existing['status'])) {
                $status = sanitize_key((string)$existing['status']);
                if (!isset($valid_statuses[$status])) {
                    $status = 'pending';
                }
            }
            $result = seo_classifier_google_schema_save_mapping($type, $id, [
                'google_taxonomy_id' => (string)$pick('google_taxonomy_id'),
                'google_name_en' => (string)$pick('google_name_en'),
                'google_path_en' => (string)$pick('google_path_en'),
                'google_alias_es' => (string)$pick('google_alias_es'),
                'suggested_wp_name' => (string)$pick('suggested_wp_name'),
                'shopping_query' => (string)$pick('shopping_query'),
                'status' => $status,
                'confidence' => (float)$pick('confidence', 0.0),
                'source' => $source,
                'notes' => (string)$pick('notes'),
            ]);
            if (is_wp_error($result)) {
                $report['errors'][] = 'Fila ' . ($offset + 2) . ': ' . $result->get_error_message();
                continue;
            }
            if (!empty($existing)) {
                $report['updated']++;
            } else {
                $report['inserted']++;
            }
        }

        $report['errors_total'] = count($report['errors']);
        if (count($report['errors']) > 12) {
            $report['errors'] = array_slice($report['errors'], 0, 12);
        }
        return $report;
    }
}

if (!function_exists('seo_classifier_google_schema_import_report_key')) {
    function seo_classifier_google_schema_import_report_key() {
        return 'seo_google_schema_import_' . get_current_user_id();
    }
}

if (!function_exists('seo_classifier_google_schema_admin_post_import')) {
    function seo_classifier_google_schema_admin_post_import() {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.', 403);
        }
        check_admin_referer('seo_classifier_google_schema_import');

        $referer = wp_get_referer();
        if (!$referer) {
            $referer = admin_url('admin.php?page=seo-tags-vocabulary&domain=google_schema');
        }

        $file = $_FILES['seo_google_schema_file'] ?? null;
        if (!is_array($file) || empty($file['tmp_name'])) {
            set_transient(seo_classifier_google_schema_import_report_key(), [
                'processed' => 0,
                'inserted' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors_total' => 1,
                'errors' => ['No se ha recibido ningún archivo.'],
            ], 120);
            wp_safe_redirect(add_query_arg('seo_google_schema_imported', '1', $referer));
            exit;
        }
        if (!empty($file['error'])) {
            set_transient(seo_classifier_google_schema_import_report_key(), [
                'processed' => 0,
                'inserted' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors_total' => 1,
                'errors' => ['Error de subida: código ' . absint($file['error']) . '.'],
            ], 120);
            wp_safe_redirect(add_query_arg('seo_google_schema_imported', '1', $referer));
            exit;
        }
        if (!empty($file['size']) && (int)$file['size'] > 8 * MB_IN_BYTES) {
            set_transient(seo_classifier_google_schema_import_report_key(), [
                'processed' => 0,
                'inserted' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors_total' => 1,
                'errors' => ['El archivo supera el límite de 8 MB.'],
            ], 120);
            wp_safe_redirect(add_query_arg('seo_google_schema_imported', '1', $referer));
            exit;
        }

        $name = sanitize_file_name((string)($file['name'] ?? ''));
        $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        $tmp = (string)$file['tmp_name'];
        if (!is_uploaded_file($tmp) && !is_readable($tmp)) {
            wp_die('El archivo temporal no es legible.');
        }

        if ($extension === 'json') {
            $rows = seo_classifier_google_schema_parse_json($tmp);
            $source = 'import_json';
        } elseif (in_array($extension, ['csv', 'txt'], true)) {
            $rows = seo_classifier_google_schema_parse_csv($tmp);
            $source = 'import_csv';
        } else {
            $rows = new WP_Error('seo_google_schema_import_type', 'Formato no permitido. Usa CSV o JSON.');
            $source = 'import';
        }

        if (is_wp_error($rows)) {
            $report = [
                'processed' => 0,
                'inserted' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors_total' => 1,
                'errors' => [$rows->get_error_message()],
            ];
        } else {
            $report = seo_classifier_google_schema_import_rows($rows, $source);
        }

        set_transient(seo_classifier_google_schema_import_report_key(), $report, 120);
        wp_safe_redirect(add_query_arg('seo_google_schema_imported', '1', $referer));
        exit;
    }
    add_action('admin_post_seo_classifier_google_schema_import', 'seo_classifier_google_schema_admin_post_import');
}

if (!function_exists('seo_classifier_google_schema_get_import_report')) {
    function seo_classifier_google_schema_get_import_report() {
        $key = seo_classifier_google_schema_import_report_key();
        $report = get_transient($key);
        if ($report !== false) {
            delete_transient($key);
        }
        return is_array($report) ? $report : null;
    }
}
