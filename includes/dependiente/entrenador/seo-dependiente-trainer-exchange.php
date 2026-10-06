<?php

defined('ABSPATH') || exit;

/**
 * Import/export CSV de preguntas de Academia/Entrenador.
 *
 * El intercambio separa dos conceptos:
 * - valor editorial de la pregunta;
 * - resultado del aprendizaje de Dependiente.
 *
 * Una pregunta puede ser editorialmente útil aunque el último run haya fallado
 * o todavía no exista.
 */
final class SEO_Dependiente_Trainer_Exchange {
    const NOTICE_PREFIX = 'seo_dependiente_trainer_exchange_notice_';

    public static function init() {
        add_action('admin_post_seo_dependiente_trainer_questions_export', array(__CLASS__, 'export_handler'));
        add_action('admin_post_seo_dependiente_trainer_questions_import', array(__CLASS__, 'import_handler'));
    }

    public static function render_card() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $export_url = wp_nonce_url(
            admin_url('admin-post.php?action=seo_dependiente_trainer_questions_export'),
            'seo_dependiente_trainer_questions_export'
        );
        self::render_notice();
        ?>
        <section class="postbox seo-dependiente-admin__box">
            <div class="seo-dependiente-trainer__section-head">
                <div>
                    <h2 class="seo-dependiente-admin__box-title">Preguntas del Entrenador · Importar / Exportar CSV</h2>
                    <p class="description">
                        Exporta todas las preguntas activas de Entrenador: currículo, Laboratorio y preguntas manuales/importadas, hayan sido respondidas, suspendidas o estén todavía pendientes.
                        El valor editorial de la pregunta se conserva separado del resultado de aprendizaje de Dependiente.
                    </p>
                </div>
                <a class="button button-secondary" href="<?php echo esc_url($export_url); ?>">Exportar preguntas · CSV</a>
            </div>

            <p style="margin:10px 0;color:#50575e;">
                El CSV incluye la última respuesta observada, estado del run, evaluación, puntuación de calidad y
                <strong>aprendida = sí / no / pendiente</strong>. Una pregunta útil no desaparece porque Dependiente la suspenda.
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="action" value="seo_dependiente_trainer_questions_import">
                <?php wp_nonce_field('seo_dependiente_trainer_questions_import', 'seo_dependiente_trainer_questions_nonce'); ?>
                <input type="file" name="seo_dependiente_trainer_questions_csv" accept=".csv,text/csv" required>
                <button type="submit" class="button button-primary">Importar preguntas · CSV</button>
            </form>
            <p class="description" style="margin-top:8px;">
                La importación es aditiva e idempotente por <code>question_hash</code>. No importa respuestas, runs, evaluaciones ni puntuaciones:
                esas columnas son diagnóstico de solo lectura y sólo pueden generarlas las ejecuciones reales de Dependiente.
            </p>
        </section>
        <?php
    }

    private static function notice_key() {
        return self::NOTICE_PREFIX . get_current_user_id();
    }

    private static function set_notice($type, $message, $stats = array(), $details = array()) {
        set_transient(
            self::notice_key(),
            array(
                'type' => sanitize_key((string) $type),
                'message' => sanitize_text_field((string) $message),
                'stats' => array_map('absint', (array) $stats),
                'details' => array_slice(array_map('sanitize_text_field', (array) $details), 0, 30),
            ),
            10 * MINUTE_IN_SECONDS
        );
    }

    private static function render_notice() {
        $notice = get_transient(self::notice_key());
        if (!is_array($notice)) {
            return;
        }
        delete_transient(self::notice_key());

        $type = in_array(($notice['type'] ?? ''), array('success','warning','error','info'), true)
            ? $notice['type']
            : 'info';

        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p><strong>'
            . esc_html((string) ($notice['message'] ?? '')) . '</strong>';

        $parts = array();
        foreach ((array) ($notice['stats'] ?? array()) as $label => $value) {
            $parts[] = sanitize_text_field((string) $label) . ': ' . absint($value);
        }
        if ($parts) {
            echo '<br>' . esc_html(implode(' · ', $parts));
        }
        echo '</p>';

        if (!empty($notice['details'])) {
            echo '<details style="margin:0 0 10px 12px;"><summary>Ver incidencias</summary><ul style="list-style:disc;margin-left:20px;">';
            foreach ((array) $notice['details'] as $detail) {
                echo '<li>' . esc_html((string) $detail) . '</li>';
            }
            echo '</ul></details>';
        }
        echo '</div>';
    }

    private static function redirect() {
        wp_safe_redirect(admin_url('admin.php?page=seo-dependiente&tab=trainer'));
        exit;
    }

    private static function table_exists($table) {
        global $wpdb;
        return (string) $table === (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like((string) $table))
        );
    }

    private static function questions_table() {
        global $wpdb;
        return class_exists('SEO_Dependiente_Entrenador')
            ? SEO_Dependiente_Entrenador::questions_table()
            : $wpdb->prefix . 'seo_dependiente_trainer_questions';
    }

    private static function runs_table() {
        global $wpdb;
        return class_exists('SEO_Dependiente_Entrenador')
            ? SEO_Dependiente_Entrenador::runs_table()
            : $wpdb->prefix . 'seo_dependiente_trainer_runs';
    }

    private static function lessons_table() {
        global $wpdb;
        return class_exists('SEO_Dependiente_Entrenador')
            ? SEO_Dependiente_Entrenador::lessons_table()
            : $wpdb->prefix . 'seo_dependiente_trainer_lessons';
    }

    private static function decode($value) {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : array();
    }

    private static function normalize_question($text) {
        if (class_exists('SEO_Dependiente_Index') && is_callable(array('SEO_Dependiente_Index', 'normalize'))) {
            return (string) SEO_Dependiente_Index::normalize((string) $text);
        }
        $text = strtolower(remove_accents(wp_strip_all_tags((string) $text)));
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }

    private static function editorial_value(array $row) {
        $question = self::normalize_question($row['question'] ?? '');
        $type = sanitize_key((string) ($row['question_type'] ?? ''));

        if ($question === '') {
            return array('value'=>'not_useful', 'reason'=>'empty_question');
        }

        if (in_array($type, array(
            'category_identity','category_identity_fallback','category_inventory',
            'category_listing','category_membership','product_identity',
            'product_identity_fallback','product_listing'
        ), true)) {
            return array('value'=>'not_useful', 'reason'=>'training_identity_or_catalog');
        }

        foreach (array(
            '/^que es (esta |esa |la |una |un )?categoria\b/',
            '/^que define (esta |esa |la )?categoria\b/',
            '/^como se define (esta |esa |la )?categoria\b/',
            '/^que productos( del catalogo)? (pertenecen|estan asignados|forman parte|hay|incluye|incluyen|contiene|contienen)\b.*\bcategoria\b/',
            '/^que productos (incluye|contiene|hay en|forman parte de)\b/',
            '/^cuales son los productos( del catalogo)? (de|en|pertenecientes a)\b.*\bcategoria\b/',
            '/^que articulos( del catalogo)? (pertenecen|hay|incluye|contiene)\b.*\bcategoria\b/',
            '/^que contiene (esta |esa |la )?categoria\b/',
            '/^lista(r)? (los )?(productos|articulos)\b.*\bcategoria\b/'
        ) as $pattern) {
            if (preg_match($pattern, $question)) {
                return array('value'=>'not_useful', 'reason'=>'catalog_or_definition_question');
            }
        }

        return array('value'=>'useful', 'reason'=>'practical_customer_value');
    }

    private static function collect_ids_recursive($value, &$category_ids, &$product_ids) {
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            $key = sanitize_key((string) $key);
            if (in_array($key, array('category_id','product_cat_id','owner_category_id'), true)) {
                $category_ids[] = absint($item);
            } elseif (in_array($key, array('category_ids','product_cat_ids','acceptable_category_ids'), true) && is_array($item)) {
                $category_ids = array_merge($category_ids, array_map('absint', $item));
            } elseif (in_array($key, array('product_id','source_product_id','owner_product_id'), true)) {
                $product_ids[] = absint($item);
            } elseif ($key === 'product_ids' && is_array($item)) {
                $product_ids = array_merge($product_ids, array_map('absint', $item));
            }
            if (is_array($item)) {
                self::collect_ids_recursive($item, $category_ids, $product_ids);
            }
        }
    }

    private static function product_category_ids($product_id) {
        $product_id = absint($product_id);
        if (!$product_id || get_post_type($product_id) !== 'product') {
            return array();
        }
        $ids = wp_get_post_terms($product_id, 'product_cat', array('fields'=>'ids'));
        return is_wp_error($ids) ? array() : array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
    }

    private static function category_context(array $row) {
        global $wpdb;

        $category_ids = array();
        $product_ids = array();
        $expected = self::decode($row['expected_json'] ?? '');
        self::collect_ids_recursive($expected, $category_ids, $product_ids);

        $source_type = sanitize_key((string) ($row['source_type'] ?? ''));
        $source_id = absint($row['source_id'] ?? 0);

        if ($source_type === 'category' && $source_id) {
            $category_ids[] = $source_id;
        } elseif (in_array($source_type, array('product','features'), true) && $source_id) {
            $product_ids[] = $source_id;
        } elseif ($source_type === 'faq' && $source_id) {
            $faq_table = $wpdb->prefix . 'seo_faq';
            if (self::table_exists($faq_table)) {
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- tabla interna; ID enlazado mediante prepare().
                $faq = $wpdb->get_row($wpdb->prepare(
                    "SELECT object_type,object_id FROM {$faq_table} WHERE id=%d LIMIT 1",
                    $source_id
                ), ARRAY_A);
                if ($faq) {
                    $owner_type = absint($faq['object_type'] ?? 0);
                    $owner_id = absint($faq['object_id'] ?? 0);
                    if ($owner_type === 2) {
                        $category_ids[] = $owner_id;
                    } elseif ($owner_type === 3) {
                        $product_ids[] = $owner_id;
                    }
                }
            }
        } elseif ($source_type === 'vocabulary' && $source_id) {
            $ov = $wpdb->prefix . 'seo_object_vocabulary';
            if (self::table_exists($ov)) {
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- tabla interna; vocabulary_id enlazado mediante prepare().
                $ids = (array) $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT object_id
                     FROM {$ov}
                     WHERE object_type='product_cat' AND status=1 AND vocabulary_id=%d",
                    $source_id
                ));
                $category_ids = array_merge($category_ids, array_map('absint', $ids));
            }
        }

        foreach (array_values(array_unique(array_filter(array_map('absint', $product_ids)))) as $product_id) {
            $category_ids = array_merge($category_ids, self::product_category_ids($product_id));
        }

        $valid = array();
        $names = array();
        foreach (array_values(array_unique(array_filter(array_map('absint', $category_ids)))) as $term_id) {
            $term = get_term($term_id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $valid[] = $term_id;
                $names[] = (string) $term->name;
            }
        }

        $product_id = absint($expected['product_id'] ?? $expected['source_product_id'] ?? 0);
        if (!$product_id && in_array($source_type, array('product','features'), true)) {
            $product_id = $source_id;
        }

        return array(
            'category_ids' => $valid,
            'category_names' => $names,
            'product_id' => $product_id ?: 0,
        );
    }

    private static function answer_text(array $row) {
        if (empty($row['run_id'])) {
            return '';
        }

        $parts = array();
        foreach (array_slice(self::decode($row['top_results'] ?? ''), 0, 3) as $result) {
            if (!is_array($result)) {
                continue;
            }
            $title = sanitize_text_field((string) ($result['title'] ?? $result['name'] ?? $result['label'] ?? ''));
            if ($title === '') {
                continue;
            }
            $reasons = array_values(array_filter(array_map('sanitize_text_field', (array) ($result['reasons'] ?? array()))));
            $parts[] = $title . ($reasons ? ' — ' . implode('; ', array_slice($reasons, 0, 3)) : '');
        }
        if ($parts) {
            return implode(' | ', $parts);
        }

        $meta = self::decode($row['response_meta'] ?? '');
        $related = array();
        foreach (array_slice((array) ($meta['related_results'] ?? $meta['related_all'] ?? array()), 0, 3) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = sanitize_text_field((string) ($item['title'] ?? ''));
            if ($title !== '') {
                $related[] = $title;
            }
        }
        if ($related) {
            return 'Contenido relacionado: ' . implode(' | ', $related);
        }

        $evaluation = self::decode($row['evaluation_json'] ?? '');
        $reason = sanitize_text_field((string) ($evaluation['reason'] ?? ''));
        return $reason;
    }

    private static function learned_state(array $row) {
        if (empty($row['run_id']) || trim((string) ($row['evaluation_status'] ?? '')) === '') {
            return 'pendiente';
        }
        return strpos(sanitize_key((string) $row['evaluation_status']), 'pass_') === 0 ? 'si' : 'no';
    }

    private static function question_scope(array $row) {
        $lesson_key = sanitize_key((string) ($row['lesson_key'] ?? ''));
        $source_type = sanitize_key((string) ($row['source_type'] ?? ''));

        if ($lesson_key !== '' && strpos($lesson_key, 'lab_') === 0) {
            return 'laboratory';
        }

        if ($lesson_key !== '' && self::lesson_exists($lesson_key)) {
            return 'curriculum';
        }

        if (in_array($source_type, array('manual_training','imported','manual'), true)) {
            return 'manual';
        }

        return 'manual';
    }

    private static function export_rows() {
        global $wpdb;
        $questions = self::questions_table();
        $runs = self::runs_table();

        if (!self::table_exists($questions) || !self::table_exists($runs)) {
            return new WP_Error('trainer_exchange_tables', 'No están disponibles las tablas de preguntas/runs de Academia.');
        }

        $sql = "SELECT q.id AS question_id,q.question_hash,q.lesson_key,q.lesson_order,q.module_no,q.sequence_no,
                       q.source_type,q.source_id,q.source_key,q.question_type,q.mode,q.question,q.expected_json,
                       q.enabled,q.created_at AS question_created_at,q.updated_at AS question_updated_at,
                       r.id AS run_id,r.status AS run_status,r.evaluation_status,r.evaluation_score,
                       r.evaluation_json,r.top_results,r.response_meta,r.error_message,r.created_at AS run_created_at
                FROM {$questions} q
                LEFT JOIN (
                    SELECT rr.*
                    FROM {$runs} rr
                    INNER JOIN (
                        SELECT question_id,MAX(id) AS max_id
                        FROM {$runs}
                        WHERE question_id IS NOT NULL
                        GROUP BY question_id
                    ) latest ON latest.max_id=rr.id
                ) r ON r.question_id=q.id
                WHERE q.enabled=1
                  AND q.lesson_key<>''
                ORDER BY q.lesson_order ASC,q.lesson_key ASC,q.module_no ASC,q.sequence_no ASC,q.id ASC";

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- consulta fija sobre tablas internas; no contiene entrada externa.
        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    public static function export_handler() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para exportar las preguntas del Entrenador.', 'seo-taxonomy'));
        }
        check_admin_referer('seo_dependiente_trainer_questions_export');

        if (class_exists('SEO_Dependiente_Entrenador') && !SEO_Dependiente_Entrenador::ensure_ready()) {
            wp_die(esc_html__('Academia no está disponible.', 'seo-taxonomy'));
        }

        $rows = self::export_rows();
        if (is_wp_error($rows)) {
            wp_die(esc_html($rows->get_error_message()));
        }

        $filename = 'dependiente-entrenador-preguntas-' . current_time('Ymd-His') . '.csv';
        while (ob_get_level()) {
            @ob_end_clean();
        }
        nocache_headers();
        status_header(200);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('X-Content-Type-Options: nosniff');

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- flujo de salida HTTP requerido por fputcsv.
        $out = fopen('php://output', 'w');
        if (!$out) {
            exit;
        }
        fwrite($out, "\xEF\xBB\xBF");

        $header = array(
            'question_id','question_hash','question_scope','category_id','category_ids','categoria','product_id',
            'question','respuesta_dependiente','question_type','lesson_key','lesson_order','module_no','sequence_no',
            'run_id','run_status','evaluation_status','evaluation_score','evaluation_score_pct','aprendida',
            'editorial_value','discard_reason','source_type','source_id','source_key','mode','enabled',
            'expected_json','question_created_at','question_updated_at','run_created_at','error_message'
        );
        fputcsv($out, $header, ';', '"', '');

        foreach ((array) $rows as $row) {
            $context = self::category_context($row);
            $editorial = self::editorial_value($row);
            $score = isset($row['evaluation_score']) && $row['evaluation_score'] !== null
                ? max(0, min(1, (float) $row['evaluation_score']))
                : null;

            fputcsv($out, array(
                absint($row['question_id'] ?? 0),
                (string) ($row['question_hash'] ?? ''),
                self::question_scope($row),
                absint($context['category_ids'][0] ?? 0) ?: '',
                implode('|', array_map('absint', (array) $context['category_ids'])),
                implode(' | ', (array) $context['category_names']),
                absint($context['product_id'] ?? 0) ?: '',
                (string) ($row['question'] ?? ''),
                self::answer_text($row),
                (string) ($row['question_type'] ?? ''),
                (string) ($row['lesson_key'] ?? ''),
                absint($row['lesson_order'] ?? 0),
                absint($row['module_no'] ?? 0),
                absint($row['sequence_no'] ?? 0),
                absint($row['run_id'] ?? 0) ?: '',
                (string) ($row['run_status'] ?? ''),
                (string) ($row['evaluation_status'] ?? ''),
                null === $score ? '' : number_format($score, 4, '.', ''),
                null === $score ? '' : number_format($score * 100, 1, '.', ''),
                self::learned_state($row),
                (string) $editorial['value'],
                'useful' === (string) $editorial['value'] ? '' : (string) $editorial['reason'],
                (string) ($row['source_type'] ?? ''),
                absint($row['source_id'] ?? 0) ?: '',
                (string) ($row['source_key'] ?? ''),
                (string) ($row['mode'] ?? ''),
                absint($row['enabled'] ?? 0),
                (string) ($row['expected_json'] ?? ''),
                (string) ($row['question_created_at'] ?? ''),
                (string) ($row['question_updated_at'] ?? ''),
                (string) ($row['run_created_at'] ?? ''),
                (string) ($row['error_message'] ?? ''),
            ), ';', '"', '');
        }

        fclose($out);
        exit;
    }

    private static function read_csv($path) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- fgetcsv necesita stream sobre el temporal validado.
        $fh = fopen((string) $path, 'r');
        if (!$fh) {
            return new WP_Error('trainer_exchange_open', 'No se pudo abrir el CSV.');
        }

        $header = fgetcsv($fh, 0, ';', '"', '');
        if (!is_array($header)) {
            fclose($fh);
            return new WP_Error('trainer_exchange_header', 'El CSV no contiene una cabecera válida.');
        }
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        }
        $header = array_map('sanitize_key', $header);

        foreach (array('question_hash','lesson_key','question') as $required) {
            if (!in_array($required, $header, true)) {
                fclose($fh);
                return new WP_Error('trainer_exchange_columns', 'El CSV debe contener question_hash, lesson_key y question.');
            }
        }

        $rows = array();
        $line = 1;
        while (($values = fgetcsv($fh, 0, ';', '"', '')) !== false) {
            $line++;
            if (!$values || !array_filter($values, static function ($value) {
                return trim((string) $value) !== '';
            })) {
                continue;
            }
            $values = array_pad($values, count($header), '');
            $row = array_combine($header, array_slice($values, 0, count($header)));
            if (!is_array($row)) {
                fclose($fh);
                return new WP_Error('trainer_exchange_row', sprintf('No se pudo interpretar la fila %d.', $line));
            }
            $row['_line'] = $line;
            $rows[] = $row;
        }
        fclose($fh);
        return $rows;
    }

    private static function lesson_exists($lesson_key) {
        static $cache = array();

        global $wpdb;
        $lesson_key = sanitize_key((string) $lesson_key);
        if ($lesson_key === '') {
            return false;
        }
        if (array_key_exists($lesson_key, $cache)) {
            return $cache[$lesson_key];
        }

        $lessons = self::lessons_table();
        if (!self::table_exists($lessons)) {
            $cache[$lesson_key] = false;
            return false;
        }

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- tabla interna; clave enlazada mediante prepare().
        $cache[$lesson_key] = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$lessons} WHERE lesson_key=%s LIMIT 1",
            $lesson_key
        ));
        return $cache[$lesson_key];
    }

    private static function max_sequence($lesson_key) {
        global $wpdb;
        $questions = self::questions_table();
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- tabla interna; clave enlazada mediante prepare().
        return absint($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(sequence_no),0) FROM {$questions} WHERE lesson_key=%s",
            sanitize_key((string) $lesson_key)
        )));
    }

    private static function import_row(array $row) {
        global $wpdb;
        $line = absint($row['_line'] ?? 0);
        unset($row['_line']);

        $lesson_key = sanitize_key((string) ($row['lesson_key'] ?? ''));
        $question = sanitize_text_field((string) ($row['question'] ?? ''));
        $question = function_exists('mb_substr') ? mb_substr($question, 0, 500, 'UTF-8') : substr($question, 0, 500);

        $question_scope = sanitize_key((string) ($row['question_scope'] ?? ''));
        if (!in_array($question_scope, array('curriculum','laboratory','manual'), true)) {
            $question_scope = strpos($lesson_key, 'lab_') === 0
                ? 'laboratory'
                : (self::lesson_exists($lesson_key) ? 'curriculum' : 'manual');
        }

        if ($lesson_key === '') {
            return new WP_Error('trainer_exchange_lesson', sprintf('Fila %d: lesson_key vacío.', $line));
        }
        if ($question_scope === 'curriculum' && !self::lesson_exists($lesson_key)) {
            return new WP_Error('trainer_exchange_lesson', sprintf('Fila %d: la lección curricular no existe en este sitio.', $line));
        }
        if ($question_scope === 'laboratory' && strpos($lesson_key, 'lab_') !== 0) {
            return new WP_Error('trainer_exchange_lesson', sprintf('Fila %d: una pregunta de laboratorio debe conservar un lesson_key lab_*.', $line));
        }
        if ($question === '') {
            return new WP_Error('trainer_exchange_question', sprintf('Fila %d: pregunta vacía.', $line));
        }

        $expected_raw = trim((string) ($row['expected_json'] ?? ''));
        if ($expected_raw !== '') {
            json_decode($expected_raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new WP_Error('trainer_exchange_json', sprintf('Fila %d: expected_json no es JSON válido.', $line));
            }
        }

        $source_key = sanitize_text_field((string) ($row['source_key'] ?? ''));
        $hash = strtolower(preg_replace('/[^a-f0-9]/i', '', (string) ($row['question_hash'] ?? '')));
        if (strlen($hash) !== 64) {
            $hash = hash('sha256', $lesson_key . '|' . $source_key . '|' . self::normalize_question($question));
        }

        $questions = self::questions_table();
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- tabla interna; hash enlazado mediante prepare().
        $exists = absint($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$questions} WHERE question_hash=%s LIMIT 1",
            $hash
        )));
        if ($exists) {
            return 'skipped';
        }

        $sequence = absint($row['sequence_no'] ?? 0);
        if ($sequence < 1) {
            $sequence = self::max_sequence($lesson_key) + 1;
        }
        $module_no = max(1, absint($row['module_no'] ?? 0));
        $lesson_order = absint($row['lesson_order'] ?? 0);

        $inserted = $wpdb->insert($questions, array(
            'question_hash' => $hash,
            'lesson_key' => $lesson_key,
            'lesson_order' => $lesson_order,
            'module_no' => $module_no,
            'sequence_no' => $sequence,
            'source_type' => sanitize_key((string) ($row['source_type'] ?? 'imported')),
            'source_id' => absint($row['source_id'] ?? 0) ?: null,
            'source_key' => function_exists('mb_substr')
                ? mb_substr($source_key, 0, 191, 'UTF-8')
                : substr($source_key, 0, 191),
            'question_type' => sanitize_key((string) ($row['question_type'] ?? 'other')) ?: 'other',
            'mode' => in_array(sanitize_key((string) ($row['mode'] ?? 'need')), array('need','product','tool','compare'), true)
                ? sanitize_key((string) ($row['mode'] ?? 'need'))
                : 'need',
            'question' => $question,
            'expected_json' => $expected_raw !== '' ? $expected_raw : wp_json_encode(array()),
            'enabled' => 1,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ));

        return false === $inserted
            ? new WP_Error('trainer_exchange_insert', sprintf('Fila %d: no se pudo insertar la pregunta.', $line))
            : 'inserted';
    }

    private static function refresh_touched_lessons(array $lesson_keys) {
        global $wpdb;
        $questions = self::questions_table();
        $lessons = self::lessons_table();

        foreach (array_values(array_unique(array_filter(array_map('sanitize_key', $lesson_keys)))) as $lesson_key) {
            if (!self::lesson_exists($lesson_key)) {
                continue;
            }

            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- tablas internas; clave enlazada mediante prepare().
            $stats = (array) $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) AS item_count,COALESCE(MAX(module_no),0) AS module_count
                 FROM {$questions}
                 WHERE lesson_key=%s AND enabled=1",
                $lesson_key
            ), ARRAY_A);

            // Si entran preguntas nuevas en una lección ya completada, vuelve a
            // necesitar entrenamiento. No se importan runs ni puntuaciones.
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- tabla interna; clave enlazada mediante prepare().
            $status = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$lessons} WHERE lesson_key=%s LIMIT 1",
                $lesson_key
            ));

            $data = array(
                'item_count' => absint($stats['item_count'] ?? 0),
                'module_count' => absint($stats['module_count'] ?? 0),
                'updated_at' => current_time('mysql'),
            );
            if ($status === 'completed') {
                $data['status'] = 'needs_training';
                $data['completed_at'] = null;
                $data['snapshot_after'] = 0;
            }
            $wpdb->update($lessons, $data, array('lesson_key'=>$lesson_key));
        }
    }

    public static function import_handler() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para importar preguntas del Entrenador.', 'seo-taxonomy'));
        }
        check_admin_referer('seo_dependiente_trainer_questions_import', 'seo_dependiente_trainer_questions_nonce');

        if (class_exists('SEO_Dependiente_Entrenador') && !SEO_Dependiente_Entrenador::ensure_ready()) {
            self::set_notice('error', 'Academia no está disponible.');
            self::redirect();
        }

        if (empty($_FILES['seo_dependiente_trainer_questions_csv']) || !is_array($_FILES['seo_dependiente_trainer_questions_csv'])) {
            self::set_notice('error', 'No se ha recibido ningún CSV.');
            self::redirect();
        }

        $file = $_FILES['seo_dependiente_trainer_questions_csv'];
        $name = sanitize_file_name((string) ($file['name'] ?? ''));
        if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
            self::set_notice('error', 'El archivo debe ser CSV.');
            self::redirect();
        }

        $tmp_path = (string) ($file['tmp_name'] ?? '');
        if ($tmp_path === '' || !is_readable($tmp_path)) {
            self::set_notice('error', 'No se puede leer el archivo temporal.');
            self::redirect();
        }

        // Prevalidación antes de mover el archivo.
        $precheck = self::read_csv($tmp_path);
        if (is_wp_error($precheck)) {
            self::set_notice('error', $precheck->get_error_message());
            self::redirect();
        }

        if (!function_exists('seo_taxonomy_store_uploaded_file')) {
            $upload_helper = dirname(__DIR__, 2) . '/seo-file-uploads.php';
            if (is_readable($upload_helper)) {
                require_once $upload_helper;
            }
        }

        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error']) || empty($uploads['basedir']) || !function_exists('seo_taxonomy_store_uploaded_file')) {
            self::set_notice('error', 'No está disponible el almacenamiento seguro de importaciones.');
            self::redirect();
        }

        $destination = trailingslashit((string) $uploads['basedir']) . 'seo-taxonomy/entrenador-imports';
        $stored = seo_taxonomy_store_uploaded_file(
            $file,
            $destination,
            array('csv'=>'text/csv'),
            $name,
            false,
            true
        );
        if (is_wp_error($stored)) {
            self::set_notice('error', $stored->get_error_message());
            self::redirect();
        }

        $path = (string) ($stored['path'] ?? '');
        $rows = self::read_csv($path);
        wp_delete_file($path);

        if (is_wp_error($rows)) {
            self::set_notice('error', $rows->get_error_message());
            self::redirect();
        }

        $stats = array('procesadas'=>0,'insertadas'=>0,'existentes'=>0,'errores'=>0);
        $details = array();
        $touched = array();

        foreach ((array) $rows as $row) {
            $stats['procesadas']++;
            $lesson_key = sanitize_key((string) ($row['lesson_key'] ?? ''));
            $result = self::import_row($row);

            if (is_wp_error($result)) {
                $stats['errores']++;
                $details[] = $result->get_error_message();
                continue;
            }
            if ($result === 'skipped') {
                $stats['existentes']++;
                continue;
            }

            $stats['insertadas']++;
            if ($lesson_key !== '') {
                $touched[] = $lesson_key;
            }
        }

        if ($touched) {
            self::refresh_touched_lessons($touched);
        }

        if ($stats['errores'] > 0) {
            self::set_notice(
                $stats['insertadas'] > 0 ? 'warning' : 'error',
                $stats['insertadas'] > 0
                    ? 'Importación terminada con incidencias.'
                    : 'No se ha insertado ninguna pregunta.',
                $stats,
                $details
            );
        } else {
            self::set_notice(
                'success',
                'Importación completada. No se han importado respuestas ni puntuaciones.',
                $stats
            );
        }

        self::redirect();
    }
}

SEO_Dependiente_Trainer_Exchange::init();
