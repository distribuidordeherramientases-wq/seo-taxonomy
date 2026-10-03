<?php
/**
 * Intercambio de conocimiento de Ingeniero.
 *
 * Permite sacar y cargar conocimiento técnico sin depender del provider de
 * descubrimiento (SerpApi u otros). Los paquetes conservan siempre las
 * referencias a las fuentes originales.
 */
defined('ABSPATH') || exit;

final class SEO_Ingeniero_Exchange {
    const SCHEMA_NAME = 'seo_ingeniero_knowledge_exchange';
    const SCHEMA_VERSION = '1.0';

    public static function init() {
        add_action('admin_post_seo_ingeniero_exchange_export_json', array(__CLASS__, 'handle_export_json'));
        add_action('admin_post_seo_ingeniero_exchange_export_csv', array(__CLASS__, 'handle_export_csv'));
        add_action('admin_post_seo_ingeniero_exchange_template_csv', array(__CLASS__, 'handle_template_csv'));
        add_action('admin_post_seo_ingeniero_exchange_import', array(__CLASS__, 'handle_import'));
    }

    /**
     * Abre un stream CSV nativo compatible con fputcsv/fgetcsv.
     *
     * @param string $path Ruta o stream PHP.
     * @param string $mode Modo.
     * @return resource|false
     */
    private static function csv_stream_open($path, $mode) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Las funciones CSV de PHP requieren un recurso nativo y permiten streaming sin cargar el archivo completo.
        return fopen($path, $mode);
    }

    /**
     * Escribe bytes en el stream CSV.
     *
     * @param resource $handle Recurso.
     * @param string   $data Datos.
     * @return int|false
     */
    private static function csv_stream_write($handle, $data) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Escritura incremental sobre el recurso usado por fputcsv().
        return fwrite($handle, $data);
    }

    /**
     * Cierra el stream CSV.
     *
     * @param resource $handle Recurso.
     * @return bool
     */
    private static function csv_stream_close($handle) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cierre explícito del recurso nativo usado por fputcsv/fgetcsv.
        return fclose($handle);
    }

    private static function guard($action) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para importar o exportar conocimiento de Ingeniero.', 'seo-taxonomy'));
        }
        check_admin_referer($action);
    }

    private static function redirect($args = array()) {
        $base = add_query_arg(array('page'=>'seo-ingeniero','tab'=>'data'), admin_url('admin.php'));
        wp_safe_redirect(add_query_arg((array) $args, $base));
        exit;
    }

    public static function render_panel() {
        echo '<div class="postbox seo-dependiente-admin__box" style="padding:18px">';
        echo '<h3 style="margin-top:0">Importar / Exportar conocimiento</h3>';
        echo '<p>Permite incorporar investigación realizada fuera de SerpApi. <strong>Todo conocimiento importado entra en estado <code>review</code></strong> y debe conservar al menos una fuente original con URL.</p>';
        echo '<p class="description">Formato canónico: JSON. CSV sirve para trabajo manual o investigación externa por filas. La importación no publica contenido ni modifica el índice comercial de Dependiente.</p>';

        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin:12px 0 18px">';
        self::render_action_form('seo_ingeniero_exchange_export_json', 'Exportar conocimiento JSON', 'secondary');
        self::render_action_form('seo_ingeniero_exchange_export_csv', 'Exportar conocimiento CSV', 'secondary');
        self::render_action_form('seo_ingeniero_exchange_template_csv', 'Descargar plantilla CSV', 'secondary');
        echo '</div>';

        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="seo_ingeniero_exchange_import">';
        wp_nonce_field('seo_ingeniero_exchange_import');

        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row"><label for="seo-ingeniero-import-file">Archivo</label></th><td>';
        echo '<input id="seo-ingeniero-import-file" type="file" name="ingeniero_file" accept=".json,.csv,application/json,text/csv" required>';
        echo '<p class="description">Máximo 10 MB. JSON o CSV UTF-8.</p></td></tr>';

        echo '<tr><th scope="row">Modo</th><td><select name="import_mode">';
        echo '<option value="add_only">Añadir solo conocimiento que no exista</option>';
        echo '<option value="update_review">Actualizar existentes y dejar en revisión</option>';
        echo '</select><p class="description">El modo seguro es “Añadir solo”. Nunca activa automáticamente conocimiento importado.</p></td></tr>';

        echo '<tr><th scope="row">Categoría</th><td>';
        echo '<label><input type="checkbox" name="strict_category" value="1" checked> Exigir que cada fila/objeto pueda resolverse a una categoría WooCommerce existente</label>';
        echo '<p class="description">Se intenta resolver por term_id y, si no coincide, por slug y nombre.</p></td></tr>';
        echo '</tbody></table>';

        submit_button('Importar conocimiento', 'primary', 'submit', false);
        echo '</form>';

        echo '<details style="margin-top:18px"><summary><strong>Columnas CSV</strong></summary>';
        echo '<p><code>term_id, category_slug, category_name, lesson, knowledge_type, concept, summary, confidence, tags, source_url, source_title, source_type, trust_level, evidence</code></p>';
        echo '<p class="description">Una fila representa una relación conocimiento ↔ fuente. Para aportar varias fuentes al mismo conocimiento, repite term_id + lesson + knowledge_type con otra source_url.</p>';
        echo '</details>';

        echo '</div>';
    }

    private static function render_action_form($action, $label, $type = 'secondary') {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        wp_nonce_field($action);
        submit_button($label, $type, 'submit', false);
        echo '</form>';
    }

    public static function handle_export_json() {
        self::guard('seo_ingeniero_exchange_export_json');
        $payload = self::build_payload();

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name('seo-ingeniero-conocimiento-' . gmdate('Ymd-His') . '.json') . '"');
        echo wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    public static function handle_export_csv() {
        self::guard('seo_ingeniero_exchange_export_csv');
        $payload = self::build_payload();

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name('seo-ingeniero-conocimiento-' . gmdate('Ymd-His') . '.csv') . '"');

        $out = self::csv_stream_open('php://output', 'w');
        if (!$out) exit;

        // BOM UTF-8 para Excel/LibreOffice.
        self::csv_stream_write($out, "\xEF\xBB\xBF");
        fputcsv($out, self::csv_headers(), ',', '"', '');

        foreach ((array) ($payload['categories'] ?? array()) as $category) {
            $source_map = array();
            foreach ((array) ($category['sources'] ?? array()) as $source) {
                $source_map[absint($source['id'] ?? 0)] = $source;
            }

            foreach ((array) ($category['knowledge'] ?? array()) as $knowledge) {
                $facts = (array) ($knowledge['facts'] ?? array());
                if (!$facts) {
                    $facts[] = array();
                }

                foreach ($facts as $fact) {
                    $source = array();
                    $source_id = absint($fact['source_id'] ?? 0);
                    if ($source_id && isset($source_map[$source_id])) {
                        $source = $source_map[$source_id];
                    }

                    fputcsv($out, array(
                        absint($category['term_id'] ?? 0),
                        (string) ($category['category_slug'] ?? ''),
                        (string) ($category['category_name'] ?? ''),
                        (string) ($knowledge['lesson'] ?? SEO_Ingeniero::LESSON_TECHNICAL),
                        (string) ($knowledge['knowledge_type'] ?? ''),
                        (string) ($knowledge['concept'] ?? ''),
                        (string) ($knowledge['summary'] ?? ''),
                        (float) ($knowledge['confidence'] ?? 0),
                        implode('|', (array) ($knowledge['tags'] ?? array())),
                        (string) ($fact['source_url'] ?? ($source['url'] ?? '')),
                        (string) ($fact['source_title'] ?? ($source['title'] ?? '')),
                        (string) ($fact['source_type'] ?? ($source['source_type'] ?? '')),
                        (string) ($fact['trust_level'] ?? ($source['trust_level'] ?? '')),
                        (string) ($fact['evidence'] ?? ''),
                    ), ',', '"', '');
                }
            }
        }

        self::csv_stream_close($out);
        exit;
    }

    public static function handle_template_csv() {
        self::guard('seo_ingeniero_exchange_template_csv');

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="seo-ingeniero-plantilla-conocimiento.csv"');

        $out = self::csv_stream_open('php://output', 'w');
        if (!$out) exit;
        self::csv_stream_write($out, "\xEF\xBB\xBF");
        fputcsv($out, self::csv_headers(), ',', '"', '');
        self::csv_stream_close($out);
        exit;
    }

    private static function csv_headers() {
        return array(
            'term_id',
            'category_slug',
            'category_name',
            'lesson',
            'knowledge_type',
            'concept',
            'summary',
            'confidence',
            'tags',
            'source_url',
            'source_title',
            'source_type',
            'trust_level',
            'evidence',
        );
    }

    public static function handle_import() {
        self::guard('seo_ingeniero_exchange_import');

        if (empty($_FILES['ingeniero_file']) || !is_array($_FILES['ingeniero_file'])) {
            self::redirect(array('ingeniero_exchange_error'=>rawurlencode('No se ha recibido ningún archivo.')));
        }

        $file = $_FILES['ingeniero_file'];
        if (!empty($file['error'])) {
            self::redirect(array('ingeniero_exchange_error'=>rawurlencode('Error de subida: ' . absint($file['error']))));
        }
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            self::redirect(array('ingeniero_exchange_error'=>rawurlencode('El archivo temporal no es válido.')));
        }
        if (absint($file['size'] ?? 0) > 10 * 1024 * 1024) {
            self::redirect(array('ingeniero_exchange_error'=>rawurlencode('El archivo supera el límite de 10 MB.')));
        }

        $mode = sanitize_key((string) ($_POST['import_mode'] ?? 'add_only'));
        if (!in_array($mode, array('add_only','update_review'), true)) $mode = 'add_only';
        $strict_category = !empty($_POST['strict_category']);

        $name = sanitize_file_name((string) ($file['name'] ?? ''));
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        if ('json' === $ext) {
            $payload = self::read_json_file($file['tmp_name']);
        } elseif ('csv' === $ext) {
            $payload = self::read_csv_file($file['tmp_name']);
        } else {
            $payload = new WP_Error('ingeniero_exchange_type', 'Formato no compatible. Usa JSON o CSV.');
        }

        if (is_wp_error($payload)) {
            self::redirect(array('ingeniero_exchange_error'=>rawurlencode($payload->get_error_message())));
        }

        $result = self::import_payload($payload, $mode, $strict_category);
        if (is_wp_error($result)) {
            self::redirect(array('ingeniero_exchange_error'=>rawurlencode($result->get_error_message())));
        }

        self::redirect(array(
            'ingeniero_notice'=>'exchange_imported',
            'ingeniero_import_categories'=>absint($result['categories'] ?? 0),
            'ingeniero_import_sources'=>absint($result['sources'] ?? 0),
            'ingeniero_import_knowledge'=>absint($result['knowledge'] ?? 0),
            'ingeniero_import_skipped'=>absint($result['skipped'] ?? 0),
            'ingeniero_import_errors'=>absint($result['errors'] ?? 0),
        ));
    }

    private static function read_json_file($path) {
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return new WP_Error('ingeniero_exchange_json_empty', 'El JSON está vacío.');
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return new WP_Error('ingeniero_exchange_json_invalid', 'El JSON no es válido.');
        }

        // Admite el formato canónico y el export JSON anterior de Ingeniero.
        if (!empty($payload['categories']) && is_array($payload['categories'])) {
            return $payload;
        }
        if (isset($payload['sources']) || isset($payload['knowledge'])) {
            return self::legacy_payload_to_categories($payload);
        }
        return new WP_Error('ingeniero_exchange_json_schema', 'El JSON no contiene categories ni el formato exportable anterior de Ingeniero.');
    }

    private static function read_csv_file($path) {
        $handle = self::csv_stream_open($path, 'r');
        if (!$handle) return new WP_Error('ingeniero_exchange_csv_open', 'No se pudo abrir el CSV.');

        $headers = fgetcsv($handle, 0, ',', '"', '');
        if (!$headers) {
            self::csv_stream_close($handle);
            return new WP_Error('ingeniero_exchange_csv_empty', 'El CSV no contiene cabecera.');
        }
        $headers = array_map(static function($value) {
            $value = preg_replace('/^\xEF\xBB\xBF/', '', (string) $value);
            return sanitize_key(trim($value));
        }, $headers);

        $required = array('knowledge_type','summary','source_url');
        foreach ($required as $field) {
            if (!in_array($field, $headers, true)) {
                self::csv_stream_close($handle);
                return new WP_Error('ingeniero_exchange_csv_columns', 'Falta la columna obligatoria: ' . $field);
            }
        }

        $groups = array();
        $line = 1;
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;
            if (!array_filter($row, static function($v){ return trim((string)$v) !== ''; })) continue;
            $row = array_pad($row, count($headers), '');
            $data = array_combine($headers, array_slice($row, 0, count($headers)));
            if (!is_array($data)) continue;

            $term_id = absint($data['term_id'] ?? 0);
            $slug = sanitize_title((string) ($data['category_slug'] ?? ''));
            $name = sanitize_text_field((string) ($data['category_name'] ?? ''));
            $lesson = sanitize_key((string) ($data['lesson'] ?? SEO_Ingeniero::LESSON_TECHNICAL));
            if ($lesson === '') $lesson = SEO_Ingeniero::LESSON_TECHNICAL;
            $type = sanitize_key((string) ($data['knowledge_type'] ?? ''));
            $summary = sanitize_textarea_field((string) ($data['summary'] ?? ''));
            $source_url = esc_url_raw((string) ($data['source_url'] ?? ''));

            if ($type === '' || $summary === '' || $source_url === '') {
                $groups['__errors'][] = 'Línea ' . $line . ': knowledge_type, summary y source_url son obligatorios.';
                continue;
            }

            $category_key = $term_id ? 'id:' . $term_id : ($slug ? 'slug:' . $slug : 'name:' . strtolower($name));
            $group_key = $category_key . '|' . $lesson . '|' . $type;

            if (!isset($groups[$group_key])) {
                $groups[$group_key] = array(
                    'term_id'=>$term_id,
                    'category_slug'=>$slug,
                    'category_name'=>$name,
                    'lesson'=>$lesson,
                    'knowledge_type'=>$type,
                    'concept'=>sanitize_text_field((string) ($data['concept'] ?? $name)),
                    'summary'=>$summary,
                    'confidence'=>self::normalize_confidence($data['confidence'] ?? 0.5),
                    'tags'=>self::parse_tags($data['tags'] ?? ''),
                    'sources'=>array(),
                );
            }

            $classification = class_exists('SEO_Ingeniero')
                ? SEO_Ingeniero::classify_source($source_url, (string) ($data['source_title'] ?? ''))
                : array('domain'=>(string) wp_parse_url($source_url, PHP_URL_HOST),'source_type'=>'manual_import','trust_level'=>'medium');

            $source_type = sanitize_key((string) ($data['source_type'] ?? ''));
            $trust = sanitize_key((string) ($data['trust_level'] ?? ''));
            if ($source_type === '') $source_type = sanitize_key((string) ($classification['source_type'] ?? 'manual_import'));
            if (!in_array($trust, array('high','medium_high','medium','low'), true)) {
                $trust = sanitize_key((string) ($classification['trust_level'] ?? 'medium'));
            }

            $groups[$group_key]['sources'][] = array(
                'url'=>$source_url,
                'title'=>sanitize_text_field((string) ($data['source_title'] ?? '')),
                'source_type'=>$source_type,
                'trust_level'=>$trust,
                'evidence'=>SEO_Ingeniero::limit_words((string) ($data['evidence'] ?? ''), 24),
                'metadata'=>array('import_origin'=>'csv','import_line'=>$line),
            );
        }
        self::csv_stream_close($handle);

        $errors = isset($groups['__errors']) ? $groups['__errors'] : array();
        unset($groups['__errors']);

        $categories = array();
        foreach ($groups as $group) {
            $category_key = $group['term_id'] ? 'id:' . $group['term_id'] : ($group['category_slug'] ? 'slug:' . $group['category_slug'] : 'name:' . strtolower($group['category_name']));
            if (!isset($categories[$category_key])) {
                $categories[$category_key] = array(
                    'term_id'=>$group['term_id'],
                    'category_slug'=>$group['category_slug'],
                    'category_name'=>$group['category_name'],
                    'sources'=>array(),
                    'knowledge'=>array(),
                );
            }

            $source_indexes = array();
            foreach ($group['sources'] as $source) {
                $key = strtolower($source['url']);
                if (!isset($source_indexes[$key])) {
                    $source_indexes[$key] = count($categories[$category_key]['sources']);
                    $categories[$category_key]['sources'][] = $source;
                }
            }

            $facts = array();
            foreach ($group['sources'] as $source) {
                $facts[] = array(
                    'source_url'=>$source['url'],
                    'source_title'=>$source['title'],
                    'source_type'=>$source['source_type'],
                    'trust_level'=>$source['trust_level'],
                    'evidence'=>$source['evidence'],
                );
            }

            $categories[$category_key]['knowledge'][] = array(
                'lesson'=>$group['lesson'],
                'knowledge_type'=>$group['knowledge_type'],
                'concept'=>$group['concept'],
                'summary'=>$group['summary'],
                'confidence'=>$group['confidence'],
                'tags'=>$group['tags'],
                'facts'=>$facts,
                'status'=>'review',
            );
        }

        return array(
            'schema'=>array('name'=>self::SCHEMA_NAME,'version'=>self::SCHEMA_VERSION),
            'categories'=>array_values($categories),
            'import_warnings'=>$errors,
        );
    }

    private static function build_payload() {
        $flat = SEO_Ingeniero_DB::export_payload(0);
        $source_groups = array();
        $knowledge_groups = array();

        foreach ((array) ($flat['sources'] ?? array()) as $source) {
            $term_id = absint($source['term_id'] ?? 0);
            if ($term_id) $source_groups[$term_id][] = $source;
        }
        foreach ((array) ($flat['knowledge'] ?? array()) as $knowledge) {
            $term_id = absint($knowledge['term_id'] ?? 0);
            if ($term_id) $knowledge_groups[$term_id][] = $knowledge;
        }

        $term_ids = array_values(array_unique(array_merge(array_keys($source_groups), array_keys($knowledge_groups))));
        sort($term_ids, SORT_NUMERIC);

        $categories = array();
        foreach ($term_ids as $term_id) {
            $term = get_term($term_id, 'product_cat');
            $categories[] = array(
                'term_id'=>$term_id,
                'category_slug'=>($term && !is_wp_error($term)) ? (string) $term->slug : '',
                'category_name'=>($term && !is_wp_error($term)) ? (string) $term->name : '',
                'sources'=>array_values((array) ($source_groups[$term_id] ?? array())),
                'knowledge'=>array_values((array) ($knowledge_groups[$term_id] ?? array())),
            );
        }

        return array(
            'schema'=>array(
                'name'=>self::SCHEMA_NAME,
                'version'=>self::SCHEMA_VERSION,
                'source'=>'SEO Taxonomy · Ingeniero',
                'policy'=>'Knowledge is a synthesis. Sources are retained for attribution and verification.',
            ),
            'generated_at_gmt'=>gmdate('Y-m-d H:i:s'),
            'categories'=>$categories,
        );
    }

    private static function legacy_payload_to_categories($payload) {
        $sources = array();
        foreach ((array) ($payload['sources'] ?? array()) as $source) {
            $term_id = absint($source['term_id'] ?? 0);
            if ($term_id) $sources[$term_id][] = $source;
        }
        $knowledge = array();
        foreach ((array) ($payload['knowledge'] ?? array()) as $item) {
            $term_id = absint($item['term_id'] ?? 0);
            if ($term_id) $knowledge[$term_id][] = $item;
        }

        $ids = array_unique(array_merge(array_keys($sources), array_keys($knowledge)));
        $categories = array();
        foreach ($ids as $term_id) {
            $term = get_term($term_id, 'product_cat');
            $categories[] = array(
                'term_id'=>absint($term_id),
                'category_slug'=>($term && !is_wp_error($term)) ? (string)$term->slug : '',
                'category_name'=>($term && !is_wp_error($term)) ? (string)$term->name : '',
                'sources'=>array_values((array)($sources[$term_id] ?? array())),
                'knowledge'=>array_values((array)($knowledge[$term_id] ?? array())),
            );
        }

        return array(
            'schema'=>array('name'=>self::SCHEMA_NAME,'version'=>self::SCHEMA_VERSION),
            'categories'=>$categories,
        );
    }

    private static function import_payload($payload, $mode, $strict_category) {
        $categories = isset($payload['categories']) && is_array($payload['categories']) ? $payload['categories'] : array();
        if (!$categories) return new WP_Error('ingeniero_exchange_no_categories', 'El archivo no contiene categorías importables.');

        $result = array(
            'categories'=>0,
            'sources'=>0,
            'knowledge'=>0,
            'skipped'=>0,
            'errors'=>count((array) ($payload['import_warnings'] ?? array())),
        );

        foreach ($categories as $category) {
            if (!is_array($category)) { $result['errors']++; continue; }

            $term = self::resolve_category($category);
            if (!$term || is_wp_error($term)) {
                if ($strict_category) {
                    $result['errors']++;
                    continue;
                }
                $result['skipped']++;
                continue;
            }

            $term_id = absint($term->term_id);
            $result['categories']++;
            $category_imported_knowledge = 0;
            $incoming_to_local = array();
            $url_to_local = array();

            foreach ((array) ($category['sources'] ?? array()) as $source) {
                if (!is_array($source)) continue;
                $url = esc_url_raw((string) ($source['url'] ?? ''));
                if ($url === '') { $result['errors']++; continue; }

                $classification = SEO_Ingeniero::classify_source($url, (string) ($source['title'] ?? ''));
                $trust = sanitize_key((string) ($source['trust_level'] ?? ''));
                if (!in_array($trust, array('high','medium_high','medium','low'), true)) {
                    $trust = sanitize_key((string) ($classification['trust_level'] ?? 'medium'));
                }
                $source_type = sanitize_key((string) ($source['source_type'] ?? ''));
                if ($source_type === '') $source_type = sanitize_key((string) ($classification['source_type'] ?? 'manual_import'));

                $metadata = isset($source['metadata']) && is_array($source['metadata']) ? $source['metadata'] : array();
                $metadata['import_origin'] = $metadata['import_origin'] ?? 'knowledge_exchange';

                $local_id = SEO_Ingeniero_DB::upsert_source(array(
                    'term_id'=>$term_id,
                    'lesson'=>sanitize_key((string) ($source['lesson'] ?? SEO_Ingeniero::LESSON_TECHNICAL)),
                    'url'=>$url,
                    'domain'=>(string) wp_parse_url($url, PHP_URL_HOST),
                    'title'=>sanitize_text_field((string) ($source['title'] ?? '')),
                    'source_type'=>$source_type,
                    'trust_level'=>$trust,
                    'published_at'=>$source['published_at'] ?? '',
                    'retrieved_at'=>gmdate('Y-m-d H:i:s'),
                    'http_status'=>absint($source['http_status'] ?? 0),
                    'content_hash'=>sanitize_text_field((string) ($source['content_hash'] ?? '')),
                    'status'=>'imported',
                    'metadata'=>$metadata,
                ));

                if (is_wp_error($local_id)) {
                    $result['errors']++;
                    continue;
                }

                $result['sources']++;
                $incoming_id = absint($source['id'] ?? 0);
                if ($incoming_id) $incoming_to_local[$incoming_id] = absint($local_id);
                $url_to_local[strtolower($url)] = absint($local_id);
            }

            foreach ((array) ($category['knowledge'] ?? array()) as $knowledge) {
                if (!is_array($knowledge)) continue;
                $lesson = sanitize_key((string) ($knowledge['lesson'] ?? SEO_Ingeniero::LESSON_TECHNICAL));
                if ($lesson === '') $lesson = SEO_Ingeniero::LESSON_TECHNICAL;
                $type = sanitize_key((string) ($knowledge['knowledge_type'] ?? ''));
                $summary = sanitize_textarea_field((string) ($knowledge['summary'] ?? ''));

                if ($type === '' || $summary === '') {
                    $result['errors']++;
                    continue;
                }

                if ('add_only' === $mode && self::knowledge_exists($term_id, $lesson, $type)) {
                    $result['skipped']++;
                    continue;
                }

                $facts = array();
                $source_ids = array();

                foreach ((array) ($knowledge['source_ids'] ?? array()) as $incoming_id) {
                    $incoming_id = absint($incoming_id);
                    if ($incoming_id && isset($incoming_to_local[$incoming_id])) {
                        $source_ids[] = $incoming_to_local[$incoming_id];
                    }
                }

                foreach ((array) ($knowledge['facts'] ?? array()) as $fact) {
                    if (!is_array($fact)) continue;
                    $url = esc_url_raw((string) ($fact['source_url'] ?? ''));
                    $local_id = 0;

                    if ($url !== '') {
                        $key = strtolower($url);
                        if (isset($url_to_local[$key])) {
                            $local_id = $url_to_local[$key];
                        } else {
                            $classification = SEO_Ingeniero::classify_source($url, (string) ($fact['source_title'] ?? ''));
                            $local_id = SEO_Ingeniero_DB::upsert_source(array(
                                'term_id'=>$term_id,
                                'lesson'=>$lesson,
                                'url'=>$url,
                                'domain'=>(string) wp_parse_url($url, PHP_URL_HOST),
                                'title'=>sanitize_text_field((string) ($fact['source_title'] ?? '')),
                                'source_type'=>sanitize_key((string) ($fact['source_type'] ?? ($classification['source_type'] ?? 'manual_import'))),
                                'trust_level'=>self::safe_trust($fact['trust_level'] ?? ($classification['trust_level'] ?? 'medium')),
                                'retrieved_at'=>gmdate('Y-m-d H:i:s'),
                                'status'=>'imported',
                                'metadata'=>array('import_origin'=>'knowledge_fact'),
                            ));
                            if (!is_wp_error($local_id)) {
                                $local_id = absint($local_id);
                                $url_to_local[$key] = $local_id;
                                $result['sources']++;
                            } else {
                                $local_id = 0;
                            }
                        }
                    }

                    if (!$local_id) {
                        $incoming_id = absint($fact['source_id'] ?? 0);
                        if ($incoming_id && isset($incoming_to_local[$incoming_id])) {
                            $local_id = $incoming_to_local[$incoming_id];
                        }
                    }

                    if ($local_id) $source_ids[] = $local_id;
                    $facts[] = array(
                        'source_id'=>$local_id,
                        'source_url'=>$url,
                        'source_title'=>sanitize_text_field((string) ($fact['source_title'] ?? '')),
                        'source_type'=>sanitize_key((string) ($fact['source_type'] ?? '')),
                        'trust_level'=>self::safe_trust($fact['trust_level'] ?? 'medium'),
                        'evidence'=>SEO_Ingeniero::limit_words((string) ($fact['evidence'] ?? ''), 24),
                    );
                }

                $source_ids = array_values(array_unique(array_filter(array_map('absint', $source_ids))));
                if (!$source_ids) {
                    $result['errors']++;
                    continue;
                }

                $saved = SEO_Ingeniero_DB::upsert_knowledge(array(
                    'term_id'=>$term_id,
                    'lesson'=>$lesson,
                    'knowledge_type'=>$type,
                    'concept'=>sanitize_text_field((string) ($knowledge['concept'] ?? $term->name)),
                    'summary'=>$summary,
                    'facts'=>$facts,
                    'tags'=>array_values(array_filter(array_map('sanitize_text_field', (array) ($knowledge['tags'] ?? array())))),
                    'source_ids'=>$source_ids,
                    'confidence'=>self::normalize_confidence($knowledge['confidence'] ?? 0.5),
                    // Importado siempre exige revisión humana.
                    'status'=>'review',
                ));

                if (is_wp_error($saved)) {
                    $result['errors']++;
                } else {
                    $result['knowledge']++;
                    $category_imported_knowledge++;
                }
            }

            if ($category_imported_knowledge > 0) {
                SEO_Ingeniero::set_category_state($term_id, 'revisar', array(
                    'last_error'=>'',
                    'last_import_at'=>time(),
                ));
            }
        }

        return $result;
    }

    private static function resolve_category($category) {
        $term_id = absint($category['term_id'] ?? 0);
        if ($term_id) {
            $term = get_term($term_id, 'product_cat');
            if ($term && !is_wp_error($term)) return $term;
        }

        $slug = sanitize_title((string) ($category['category_slug'] ?? ''));
        if ($slug !== '') {
            $term = get_term_by('slug', $slug, 'product_cat');
            if ($term && !is_wp_error($term)) return $term;
        }

        $name = sanitize_text_field((string) ($category['category_name'] ?? ''));
        if ($name !== '') {
            $term = get_term_by('name', $name, 'product_cat');
            if ($term && !is_wp_error($term)) return $term;
        }

        return null;
    }

    private static function knowledge_exists($term_id, $lesson, $type) {
        global $wpdb;
        $table = SEO_Ingeniero_DB::table('knowledge');
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE term_id=%d AND lesson=%s AND knowledge_type=%s AND status<>'superseded' LIMIT 1",
            absint($term_id),
            sanitize_key($lesson),
            sanitize_key($type)
        ));
    }

    private static function safe_trust($value) {
        $value = sanitize_key((string) $value);
        return in_array($value, array('high','medium_high','medium','low'), true) ? $value : 'medium';
    }

    private static function normalize_confidence($value) {
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
            $value = rtrim($value, "% ");
        }
        $value = (float) $value;
        if ($value > 1 && $value <= 100) $value = $value / 100;
        return max(0, min(1, $value));
    }

    private static function parse_tags($value) {
        if (is_array($value)) {
            return array_values(array_filter(array_map('sanitize_text_field', $value)));
        }
        $parts = preg_split('/[|,;]/u', (string) $value);
        return array_values(array_filter(array_map('sanitize_text_field', (array) $parts)));
    }
}

SEO_Ingeniero_Exchange::init();
