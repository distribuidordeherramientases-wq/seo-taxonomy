<?php
/**
 * Comparador - Import/Export JSON editorial y JSON de visitas.
 */

defined('ABSPATH') || exit;

final class SEO_Comparador_IO {
    const CONTENT_SCHEMA = 'seo-comparador-editorial-v1';
    const VISITS_SCHEMA = 'seo-comparador-visitas-v1';
    const MAX_IMPORT_BYTES = 8388608;

    public static function init() {
        add_action('admin_post_seo_comparador_export_content', array(__CLASS__, 'handle_export_content'));
        add_action('admin_post_seo_comparador_import_editorial', array(__CLASS__, 'handle_import_editorial'));
        add_action('admin_post_seo_comparador_export_visits', array(__CLASS__, 'handle_export_visits'));
    }

    private static function notice($message, $type = 'success') {
        set_transient(
            'seo_comparador_notice_' . get_current_user_id(),
            array(
                'message' => sanitize_text_field((string) $message),
                'type' => sanitize_key((string) $type),
            ),
            90
        );
    }

    private static function admin_url($tab, $args = array()) {
        return add_query_arg(
            array_merge(
                array(
                    'page' => 'seo-comparador',
                    'tab' => sanitize_key((string) $tab),
                ),
                (array) $args
            ),
            admin_url('admin.php')
        );
    }

    private static function redirect($tab, $args = array()) {
        wp_safe_redirect(self::admin_url($tab, $args));
        exit;
    }

    private static function download_json($payload, $filename) {
        $json = wp_json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($json)) {
            wp_die('No se pudo codificar el JSON.');
        }

        $filename = sanitize_file_name((string) $filename);
        nocache_headers();
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON de descarga generado con wp_json_encode().
        exit;
    }

    private static function mysql_datetime($value) {
        $value = trim((string) $value);
        return preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/', $value) ? $value : '';
    }

    private static function text_excerpt($text, $length = 2400) {
        $text = trim(wp_strip_all_tags((string) $text));
        if ($text === '') return '';
        if (function_exists('mb_substr')) return mb_substr($text, 0, absint($length), 'UTF-8');
        return substr($text, 0, absint($length));
    }

    private static function sanitize_rich_value($value) {
        if (is_bool($value) || is_int($value) || is_float($value)) return $value;
        if (is_string($value)) return wp_kses_post($value);
        if (!is_array($value)) return null;

        $out = array();
        foreach ($value as $key => $item) {
            $safe_key = is_int($key) ? $key : sanitize_key((string) $key);
            $out[$safe_key] = self::sanitize_rich_value($item);
        }
        return $out;
    }

    private static function editorial_payload($row) {
        $row = is_array($row) ? $row : array();
        return array(
            'suggested_title' => (string) ($row['suggested_title'] ?? ''),
            'excerpt' => (string) ($row['excerpt'] ?? ''),
            'comparison_text' => (string) ($row['comparison_text'] ?? ''),
            'product_types' => SEO_Comparador_DB::decode_json($row['product_types'] ?? '[]'),
            'main_differences' => SEO_Comparador_DB::decode_json($row['main_differences'] ?? '[]'),
            'buying_criteria' => SEO_Comparador_DB::decode_json($row['buying_criteria'] ?? '[]'),
            'use_cases' => SEO_Comparador_DB::decode_json($row['use_cases'] ?? '[]'),
            'market_overview' => (string) ($row['market_overview'] ?? ''),
            'own_catalog_position' => (string) ($row['own_catalog_position'] ?? ''),
            'limitations' => SEO_Comparador_DB::decode_json($row['limits'] ?? '[]'),
            'conclusion' => (string) ($row['conclusion'] ?? ''),
        );
    }

    private static function values_by_product($profile_id) {
        $grouped = array();
        foreach (SEO_Comparador_DB::values_for_profile(absint($profile_id)) as $row) {
            $product_id = absint($row['comparison_product_id'] ?? 0);
            $axis_key = sanitize_key((string) ($row['axis_key'] ?? ''));
            if (!$product_id || $axis_key === '') continue;
            if (!isset($grouped[$product_id])) $grouped[$product_id] = array();

            $normalized = trim((string) ($row['normalized_value'] ?? ''));
            $unit = trim((string) ($row['unit'] ?? ''));
            $grouped[$product_id][$axis_key] = array(
                'value' => $normalized !== '' ? trim($normalized . ($unit !== '' ? ' ' . $unit : '')) : 'unknown',
                'raw_value' => (string) ($row['raw_value'] ?? ''),
                'normalized_value' => $normalized,
                'unit' => $unit,
                'confidence' => (float) ($row['confidence'] ?? 0),
                'verification_status' => sanitize_key((string) ($row['verification_status'] ?? 'unknown')) ?: 'unknown',
                'source' => sanitize_key((string) ($row['source'] ?? 'unknown')) ?: 'unknown',
                'observed_at' => (string) ($row['observed_at'] ?? ''),
            );
        }
        return $grouped;
    }

    private static function export_product(array $product, array $values) {
        $source_type = sanitize_key((string) ($product['source_type'] ?? ''));
        $own_id = absint($product['own_product_id'] ?? 0);
        $raw_meta = SEO_Comparador_DB::decode_json($product['raw_meta'] ?? '{}');

        $row = array(
            'source' => $source_type === 'external' ? 'ojeador' : 'woocommerce',
            'source_id' => (string) ($product['source_id'] ?? ''),
            'brand' => (string) ($product['brand'] ?? ''),
            'model' => (string) ($product['model'] ?? ''),
            'title' => (string) ($product['title'] ?? ''),
            'representative' => !empty($product['representative']),
            'source_snapshot_at' => (string) ($product['source_snapshot_at'] ?? ''),
            'values' => $values,
        );

        if ($source_type === 'own') {
            $wc_product = $own_id && function_exists('wc_get_product') ? wc_get_product($own_id) : false;
            $row = array_merge(
                array(
                    'product_id' => $own_id,
                    'sku' => $wc_product ? (string) $wc_product->get_sku() : (string) ($raw_meta['sku'] ?? ''),
                    'url' => $own_id ? (string) get_permalink($own_id) : '',
                ),
                $row
            );
        }
        return $row;
    }

    public static function build_content_package($profile_id) {
        SEO_Comparador_DB::maybe_install();
        $profile = SEO_Comparador_DB::get_profile(absint($profile_id));
        if (!$profile) return new WP_Error('comparador_export_profile', 'Perfil comparativo no encontrado.');

        $term_id = absint($profile['primary_category_id'] ?? 0);
        $term = get_term($term_id, 'product_cat');
        if (!$term || is_wp_error($term)) {
            return new WP_Error('comparador_export_category', 'La categoría asociada al perfil no existe.');
        }

        $term_url = get_term_link($term);
        if (is_wp_error($term_url)) $term_url = '';

        $axes = array();
        $axis_overrides = array();
        foreach (SEO_Comparador_DB::axes(absint($profile['id'])) as $axis) {
            $axis_key = sanitize_key((string) ($axis['axis_key'] ?? ''));
            if ($axis_key === '') continue;
            $axis_row = array(
                'key' => $axis_key,
                'label' => (string) ($axis['label'] ?? ''),
                'unit' => (string) ($axis['unit'] ?? ''),
                'priority' => absint($axis['priority'] ?? 0),
                'coverage' => (float) ($axis['coverage'] ?? 0),
                'confidence' => SEO_Comparador_Engine::measured_axis_confidence(absint($profile['id']), $axis_key),
                'min_confidence' => (float) ($axis['min_confidence'] ?? 0),
                'publishable' => !empty($axis['publishable']),
                'source' => (string) ($axis['source'] ?? ''),
            );
            $axes[] = $axis_row;
            if (!empty($axis['manual_override'])) {
                $axis_overrides[] = array(
                    'key' => $axis_key,
                    'label' => $axis_row['label'],
                    'unit' => $axis_row['unit'],
                    'priority' => $axis_row['priority'],
                    'min_confidence' => $axis_row['min_confidence'],
                    'publishable' => $axis_row['publishable'],
                );
            }
        }

        $values = self::values_by_product(absint($profile['id']));
        $own_products = array();
        $external_products = array();
        foreach (SEO_Comparador_DB::products(absint($profile['id'])) as $product) {
            $comparison_product_id = absint($product['id'] ?? 0);
            $row = self::export_product($product, (array) ($values[$comparison_product_id] ?? array()));
            if (($product['source_type'] ?? '') === 'own') {
                $own_products[] = $row;
            } elseif (!empty($product['representative'])) {
                $external_products[] = $row;
            }
        }

        $editorial = SEO_Comparador_DB::editorial(absint($profile['id']));
        $post_map = SEO_Comparador_DB::post_map(absint($profile['id']));
        $post_context = array();
        if (!empty($post_map['post_id'])) {
            $post = get_post(absint($post_map['post_id']));
            if ($post instanceof WP_Post) {
                $post_context = array(
                    'post_id' => absint($post->ID),
                    'status' => (string) $post->post_status,
                    'title' => (string) $post->post_title,
                    'url' => (string) get_permalink($post->ID),
                    'modified_at' => (string) $post->post_modified,
                    'excerpt' => (string) $post->post_excerpt,
                    'content' => (string) $post->post_content,
                );
            }
        }

        return array(
            'schema' => self::CONTENT_SCHEMA,
            'schema_version' => 1,
            'exported_at' => current_time('mysql'),
            'source_policy' => array(
                'inventory_read_only' => true,
                'ojeador_read_only' => true,
                'automatic_values_read_only' => true,
                'editable_sections' => array('editorial', 'manual_axis_overrides'),
            ),
            'category' => array(
                'term_id' => $term_id,
                'slug' => (string) $term->slug,
                'name' => (string) $term->name,
                'url' => (string) $term_url,
            ),
            'profile' => array(
                'profile_id' => absint($profile['id']),
                'status' => (string) ($profile['status'] ?? ''),
                'confidence' => (float) ($profile['confidence'] ?? 0),
                'source_snapshot_at' => (string) ($profile['source_snapshot_at'] ?? ''),
                'source_hash' => (string) ($profile['source_hash'] ?? ''),
                'editorial_version' => absint($editorial['version'] ?? 0),
                'editorial_origin' => (string) ($editorial['origin'] ?? 'generated'),
            ),
            'axes' => $axes,
            'own_products' => $own_products,
            'external_products' => $external_products,
            'external_products_summary' => array(
                'seen' => absint($profile['external_products_seen'] ?? 0),
                'comparable' => absint($profile['external_products_comparable'] ?? 0),
                'representatives_exported' => count($external_products),
            ),
            'canonical_post' => $post_context,
            'generated_context' => array(
                'summary' => (string) ($editorial['summary'] ?? ''),
            ),
            'editorial' => self::editorial_payload($editorial),
            'manual_axis_overrides' => $axis_overrides,
        );
    }

    private static function resolve_import_profile(array $payload) {
        $category = (array) ($payload['category'] ?? array());
        $profile_meta = (array) ($payload['profile'] ?? array());

        $term = false;
        $slug = sanitize_title((string) ($category['slug'] ?? ''));
        if ($slug !== '') $term = get_term_by('slug', $slug, 'product_cat');

        $term_id = absint($category['term_id'] ?? 0);
        if ($term && $term_id && absint($term->term_id) !== $term_id) {
            return new WP_Error('comparador_import_category_mismatch', 'El slug y el term_id del JSON apuntan a categorías distintas.');
        }
        if (!$term && $term_id) $term = get_term($term_id, 'product_cat');
        if (!$term || is_wp_error($term)) {
            return new WP_Error('comparador_import_category', 'La categoría indicada en el JSON no existe.');
        }
        $term_id = absint($term->term_id);

        $profile_id = absint($profile_meta['profile_id'] ?? 0);
        if ($profile_id) {
            $profile = SEO_Comparador_DB::get_profile($profile_id);
            if ($profile) {
                $ids = array_map('absint', SEO_Comparador_DB::decode_json($profile['category_ids'] ?? '[]'));
                if (absint($profile['primary_category_id'] ?? 0) === $term_id || in_array($term_id, $ids, true)) {
                    return array('term' => $term, 'profile' => $profile);
                }
            }
        }

        $profile = SEO_Comparador_DB::profile_for_category($term_id);
        if (!$profile) {
            return new WP_Error('comparador_import_profile', 'No existe un perfil Comparador para la categoría del JSON.');
        }
        return array('term' => $term, 'profile' => $profile);
    }

    private static function import_axis_overrides($profile_id, $rows) {
        if (!is_array($rows) || !$rows) return array('blocked_axes' => array());

        $mapped = array();
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $key = sanitize_key((string) ($row['key'] ?? $row['axis_key'] ?? ''));
            if ($key === '') continue;
            $mapped[$key] = array(
                'label' => sanitize_text_field((string) ($row['label'] ?? $key)),
                'unit' => sanitize_text_field((string) ($row['unit'] ?? '')),
                'priority' => absint($row['priority'] ?? 50),
                'min_confidence' => (float) ($row['min_confidence'] ?? SEO_Comparador_Engine::settings()['min_axis_confidence']),
                'publishable' => !empty($row['publishable']) ? 1 : 0,
            );
        }
        if (!$mapped) return array('blocked_axes' => array());
        return SEO_Comparador_Engine::save_axes(absint($profile_id), $mapped);
    }

    public static function import_editorial_payload(array $payload, $filename = '', $expected_profile_id = 0) {
        global $wpdb;
        SEO_Comparador_DB::maybe_install();

        if (($payload['schema'] ?? '') !== self::CONTENT_SCHEMA) {
            return new WP_Error('comparador_import_schema', 'Schema JSON no válido. Se esperaba ' . self::CONTENT_SCHEMA . '.');
        }

        $resolved = self::resolve_import_profile($payload);
        if (is_wp_error($resolved)) return $resolved;

        $profile = (array) $resolved['profile'];
        $profile_id = absint($profile['id'] ?? 0);
        if (absint($expected_profile_id) && $profile_id !== absint($expected_profile_id)) {
            return new WP_Error('comparador_import_wrong_profile', 'El JSON pertenece a otra comparativa/categoría. Ábrela antes de importarlo.');
        }
        $editorial_input = (array) ($payload['editorial'] ?? array());
        if (!$editorial_input) {
            return new WP_Error('comparador_import_editorial', 'El JSON no contiene la sección editorial.');
        }

        $override_result = self::import_axis_overrides(
            $profile_id,
            isset($payload['manual_axis_overrides']) ? (array) $payload['manual_axis_overrides'] : array()
        );
        if (is_wp_error($override_result)) return $override_result;

        // Los overrides pueden recalcular el texto base. Refrescar antes de guardar
        // la capa editorial importada para que nunca la pise generate_editorial().
        $profile = SEO_Comparador_DB::get_profile($profile_id);
        $existing = SEO_Comparador_DB::editorial($profile_id);

        $export_profile = (array) ($payload['profile'] ?? array());
        $export_hash = sanitize_text_field((string) ($export_profile['source_hash'] ?? ''));
        $export_snapshot = self::mysql_datetime($export_profile['source_snapshot_at'] ?? '');
        $current_hash = sanitize_text_field((string) ($profile['source_hash'] ?? ''));
        $current_snapshot = self::mysql_datetime($profile['source_snapshot_at'] ?? '');

        $stale_hash = $export_hash !== '' && $current_hash !== '' && !hash_equals($current_hash, $export_hash);
        $stale_snapshot = $export_snapshot !== '' && $current_snapshot !== '' && $export_snapshot !== $current_snapshot;
        $stale = $stale_hash || $stale_snapshot;

        $product_types = self::sanitize_rich_value((array) ($editorial_input['product_types'] ?? array()));
        $main_differences = self::sanitize_rich_value((array) ($editorial_input['main_differences'] ?? array()));
        $buying_criteria = self::sanitize_rich_value((array) ($editorial_input['buying_criteria'] ?? array()));
        $use_cases = self::sanitize_rich_value((array) ($editorial_input['use_cases'] ?? array()));
        $limitations = self::sanitize_rich_value((array) ($editorial_input['limitations'] ?? array()));

        $comparison_text = wp_kses_post((string) ($editorial_input['comparison_text'] ?? ''));
        $suggested_title = sanitize_text_field((string) ($editorial_input['suggested_title'] ?? ''));
        $excerpt = sanitize_textarea_field((string) ($editorial_input['excerpt'] ?? ''));
        $market_overview = wp_kses_post((string) ($editorial_input['market_overview'] ?? ''));
        $own_catalog_position = wp_kses_post((string) ($editorial_input['own_catalog_position'] ?? ''));
        $conclusion = wp_kses_post((string) ($editorial_input['conclusion'] ?? ''));

        $version = max(1, absint($existing['version'] ?? 0) + 1);
        $now = current_time('mysql');
        $row = array(
            'suggested_title' => $suggested_title,
            'excerpt' => $excerpt,
            'comparison_text' => $comparison_text,
            'product_types' => wp_json_encode($product_types, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'main_differences' => wp_json_encode($main_differences, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'buying_criteria' => wp_json_encode($buying_criteria, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'use_cases' => wp_json_encode($use_cases, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'market_overview' => $market_overview,
            'own_catalog_position' => $own_catalog_position,
            'limits' => wp_json_encode($limitations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'conclusion' => $conclusion,
            'origin' => 'manual_import',
            'source_hash_at_edit' => $export_hash,
            'source_snapshot_at_edit' => $export_snapshot !== '' ? $export_snapshot : null,
            'import_meta' => wp_json_encode(
                array(
                    'schema' => self::CONTENT_SCHEMA,
                    'filename' => sanitize_file_name((string) $filename),
                    'stale_sources' => $stale,
                    'stale_hash' => $stale_hash,
                    'stale_snapshot' => $stale_snapshot,
                    'export_profile_id' => absint($export_profile['profile_id'] ?? 0),
                    'current_source_hash' => $current_hash,
                    'current_source_snapshot_at' => $current_snapshot,
                ),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'version' => $version,
            'imported_at' => $now,
            'imported_by' => get_current_user_id(),
            'reviewed_at' => null,
            'updated_at' => $now,
        );

        if ($existing) {
            $ok = $wpdb->update(SEO_Comparador_DB::table('editorial'), $row, array('profile_id' => $profile_id));
        } else {
            $row['profile_id'] = $profile_id;
            $row['summary'] = '';
            $row['generated_at'] = null;
            $ok = $wpdb->insert(SEO_Comparador_DB::table('editorial'), $row);
        }
        if ($ok === false) {
            return new WP_Error('comparador_import_save', 'No se pudo guardar la capa editorial importada.');
        }

        $reason = $stale
            ? 'JSON editorial importado sobre un snapshot anterior. Requiere revisión frente a las fuentes actuales.'
            : 'JSON editorial importado y pendiente de revisión.';
        SEO_Comparador_DB::update_status($profile_id, 'needs_review', 'manual_import', $reason, 'manual_import');

        return array(
            'profile_id' => $profile_id,
            'version' => $version,
            'stale_sources' => $stale,
            'blocked_axes' => (array) ($override_result['blocked_axes'] ?? array()),
        );
    }

    private static function read_uploaded_json($field) {
        if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
            return new WP_Error('comparador_import_file', 'No se ha recibido ningún archivo JSON.');
        }

        $file = $_FILES[$field]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Se valida cada campo antes de usarlo.
        $error = isset($file['error']) ? absint($file['error']) : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            return new WP_Error('comparador_import_upload', 'Error de subida del JSON: código ' . $error . '.');
        }

        $size = isset($file['size']) ? absint($file['size']) : 0;
        if ($size < 1 || $size > self::MAX_IMPORT_BYTES) {
            return new WP_Error('comparador_import_size', 'El JSON debe ocupar entre 1 byte y 8 MB.');
        }

        $name = isset($file['name']) ? sanitize_file_name((string) $file['name']) : '';
        if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'json') {
            return new WP_Error('comparador_import_extension', 'El archivo debe tener extensión .json.');
        }

        $tmp_name = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
            return new WP_Error('comparador_import_tmp', 'El archivo temporal de importación no es válido.');
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        if (!WP_Filesystem()) {
            return new WP_Error('comparador_import_fs', 'No se pudo inicializar el sistema de archivos de WordPress.');
        }
        global $wp_filesystem;
        $contents = $wp_filesystem->get_contents($tmp_name);
        if (!is_string($contents) || $contents === '') {
            return new WP_Error('comparador_import_read', 'No se pudo leer el JSON importado.');
        }

        $payload = json_decode($contents, true);
        if (!is_array($payload)) {
            return new WP_Error('comparador_import_json', 'JSON inválido: ' . json_last_error_msg());
        }
        return array('payload' => $payload, 'filename' => $name);
    }

    public static function handle_export_content() {
        if (!current_user_can('manage_options')) wp_die('No tienes permisos.');
        $profile_id = isset($_GET['profile_id']) ? absint(wp_unslash($_GET['profile_id'])) : 0;
        check_admin_referer('seo_comparador_export_content_' . $profile_id);

        $payload = self::build_content_package($profile_id);
        if (is_wp_error($payload)) wp_die(esc_html($payload->get_error_message()));

        $slug = sanitize_title((string) ($payload['category']['slug'] ?? 'comparativa'));
        self::download_json($payload, 'comparador-contenido-' . $slug . '-' . gmdate('Ymd-His') . '.json');
    }

    public static function handle_import_editorial() {
        if (!current_user_can('manage_options')) wp_die('No tienes permisos.');
        $profile_id = isset($_POST['profile_id']) ? absint(wp_unslash($_POST['profile_id'])) : 0;
        check_admin_referer('seo_comparador_import_editorial_' . $profile_id);

        $uploaded = self::read_uploaded_json('comparador_json');
        if (is_wp_error($uploaded)) {
            self::notice($uploaded->get_error_message(), 'error');
            self::redirect('comparisons', array('profile_id' => $profile_id));
        }

        $result = self::import_editorial_payload((array) $uploaded['payload'], (string) $uploaded['filename'], $profile_id);
        if (is_wp_error($result)) {
            self::notice($result->get_error_message(), 'error');
            self::redirect('comparisons', array('profile_id' => $profile_id));
        }

        $message = 'Comparativa editorial importada. Versión ' . absint($result['version']) . '; queda en revisión.';
        $type = 'success';
        if (!empty($result['stale_sources'])) {
            $message .= ' AVISO: las fuentes actuales difieren del snapshot usado para redactarla.';
            $type = 'warning';
        }
        if (!empty($result['blocked_axes'])) {
            $message .= ' Algunos overrides de ejes se bloquearon por cobertura/confianza insuficiente.';
            $type = 'warning';
        }
        self::notice($message, $type);
        self::redirect('comparisons', array('profile_id' => absint($result['profile_id'])));
    }

    private static function page_map($rows) {
        $map = array();
        foreach ((array) $rows as $row) {
            if (!is_array($row)) continue;
            $path = untrailingslashit((string) wp_parse_url((string) ($row['page_url'] ?? ''), PHP_URL_PATH));
            if ($path === '') continue;
            $map[$path] = $row;
        }
        return $map;
    }

    private static function metric_row($row) {
        $row = is_array($row) ? $row : array();
        return array(
            'impressions' => (float) ($row['impressions'] ?? 0),
            'clicks' => (float) ($row['clicks'] ?? 0),
            'ctr' => (float) ($row['ctr'] ?? 0),
            'position' => (float) ($row['position'] ?? 0),
            'queries' => absint($row['queries'] ?? 0),
            'sessions' => absint($row['sessions'] ?? 0),
            'pageviews' => absint($row['pageviews'] ?? $row['views'] ?? 0),
        );
    }

    private static function metric_delta(array $current, array $previous) {
        $out = array();
        foreach (array('impressions','clicks','ctr','position','queries','sessions','pageviews') as $key) {
            $now = (float) ($current[$key] ?? 0);
            $before = (float) ($previous[$key] ?? 0);
            $out[$key] = array(
                'absolute' => $now - $before,
                'percent' => $before != 0.0 ? (($now - $before) / abs($before)) * 100 : null,
            );
        }
        return $out;
    }

    public static function build_visits_package($days = 28, $profile_id = 0) {
        $days = absint($days) === 90 ? 90 : 28;
        $payload = array(
            'schema' => self::VISITS_SCHEMA,
            'schema_version' => 1,
            'exported_at' => current_time('mysql'),
            'days' => $days,
            'source' => 'analista',
            'analista_ready' => false,
            'period' => array(),
            'comparisons' => array(),
        );

        if (!function_exists('seo_analista_get_data')) return $payload;
        $data = seo_analista_get_data($days);
        if (!is_array($data) || empty($data['ready'])) return $payload;

        $payload['analista_ready'] = true;
        $payload['period'] = (array) ($data['period'] ?? array());
        $current_map = self::page_map((array) ($data['pages'] ?? array()));
        $previous_map = self::page_map((array) ($data['previous_pages'] ?? array()));

        foreach (SEO_Comparador_DB::list_profiles(2000) as $profile) {
            if ($profile_id && absint($profile['id'] ?? 0) !== absint($profile_id)) continue;

            $map = SEO_Comparador_DB::post_map(absint($profile['id']));
            $post_id = absint($map['post_id'] ?? 0);
            if (!$post_id) continue;

            $url = (string) get_permalink($post_id);
            $path = untrailingslashit((string) wp_parse_url($url, PHP_URL_PATH));
            $current = self::metric_row((array) ($current_map[$path] ?? array()));
            $previous = self::metric_row((array) ($previous_map[$path] ?? array()));
            $term = get_term(absint($profile['primary_category_id'] ?? 0), 'product_cat');

            $payload['comparisons'][] = array(
                'profile' => array(
                    'profile_id' => absint($profile['id']),
                    'status' => (string) ($profile['status'] ?? ''),
                    'source_snapshot_at' => (string) ($profile['source_snapshot_at'] ?? ''),
                    'source_hash' => (string) ($profile['source_hash'] ?? ''),
                ),
                'category' => array(
                    'term_id' => absint($profile['primary_category_id'] ?? 0),
                    'slug' => $term && !is_wp_error($term) ? (string) $term->slug : '',
                    'name' => $term && !is_wp_error($term) ? (string) $term->name : (string) ($profile['canonical_name'] ?? ''),
                ),
                'post' => array(
                    'post_id' => $post_id,
                    'title' => (string) ($map['post_title'] ?? get_the_title($post_id)),
                    'status' => (string) ($map['post_status'] ?? get_post_status($post_id)),
                    'url' => $url,
                ),
                'current' => $current,
                'previous' => $previous,
                'delta' => self::metric_delta($current, $previous),
            );
        }
        return $payload;
    }

    public static function handle_export_visits() {
        if (!current_user_can('manage_options')) wp_die('No tienes permisos.');
        $days = isset($_GET['days']) && absint(wp_unslash($_GET['days'])) === 90 ? 90 : 28;
        $profile_id = isset($_GET['profile_id']) ? absint(wp_unslash($_GET['profile_id'])) : 0;
        check_admin_referer('seo_comparador_export_visits_' . $days . '_' . $profile_id);

        $payload = self::build_visits_package($days, $profile_id);
        self::download_json($payload, 'comparador-visitas-' . $days . 'd-' . gmdate('Ymd-His') . '.json');
    }
}
