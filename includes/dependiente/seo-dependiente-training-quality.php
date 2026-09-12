<?php
/**
 * Observatorio de aprendizaje y calidad de formacion de Dependiente.
 *
 * Solo lectura: analiza la trazabilidad ya registrada por Academia y las
 * fuentes canonicas actuales para detectar deuda, concentraciones de fallos y
 * posibles fuentes que merecen revision humana. Nunca corrige contenido ni
 * altera conocimiento aprendido.
 */
defined('ABSPATH') || exit;

final class SEO_Dependiente_Training_Quality {
    const EXPORT_VERSION = 1;
    const DETAIL_LIMIT = 160;
    const REPORT_BATCH_SIZE = 250;
    const EXPORT_FAILURE_LIMIT = 1000;

    public static function render() {
        if (!self::available()) {
            echo '<div class="notice notice-info inline"><p>La telemetria de Academia todavia no esta disponible.</p></div>';
            return;
        }

        $view = sanitize_key((string) ($_GET['learning_view'] ?? 'summary'));
        if (!in_array($view, array('summary', 'evolution', 'quality', 'failures'), true)) {
            $view = 'summary';
        }
        $lesson_key = sanitize_key((string) ($_GET['learning_lesson'] ?? ''));
        $data = self::build_report($lesson_key);
        $base = add_query_arg(array('page' => 'seo-dependiente', 'tab' => 'learning'), admin_url('admin.php'));

        echo '<section class="seo-dependiente-training-quality">';
        echo '<div class="seo-dependiente-training-quality__intro">';
        echo '<div><h2>Aprendizaje de Academia</h2><p class="description">Rendimiento observado, zonas dominadas y trazabilidad de los fallos hacia sus fuentes. Un fallo no demuestra por si solo que una fuente sea incorrecta: el informe separa evidencia evaluada, contexto asociado y diagnostico del motor.</p></div>';
        echo '<div class="seo-dependiente-training-quality__legend"><span class="is-ok">Dominado</span><span class="is-debt">Deuda</span><span class="is-review">Revisar fuente</span><span class="is-engine">Motor/evaluacion</span></div>';
        echo '</div>';

        self::render_subnav($base, $view, $lesson_key);
        self::render_lesson_filter($base, $view, $lesson_key, $data['lessons']);

        if ('evolution' === $view) {
            self::render_evolution($data);
        } elseif ('quality' === $view) {
            self::render_quality($data);
        } elseif ('failures' === $view) {
            self::render_failures($data);
        } else {
            self::render_summary($data);
        }
        echo '</section>';
    }

    public static function export_lesson($lesson_key) {
        $lesson_key = sanitize_key((string) $lesson_key);
        if (!self::available() || !$lesson_key) {
            return array();
        }
        $data = self::build_report($lesson_key, true);
        return array(
            'schema' => array('name' => 'seo_dependiente_training_quality', 'version' => self::EXPORT_VERSION),
            'scope' => array('lesson_key' => $lesson_key),
            'summary' => $data['summary'],
            'lessons' => $data['lessons'],
            'direct_dimensions' => array_slice($data['direct_rankings'], 0, 100),
            'context_dimensions' => array_slice($data['context_rankings'], 0, 100),
            'source_groups' => array_slice($data['source_rankings'], 0, 150),
            'diagnostics' => $data['diagnostics'],
            'module_alerts' => $data['module_alerts'],
            'failures' => $data['export_failures'],
            'notes' => array(
                'observational_only' => true,
                'source_suspicion_is_not_proof' => true,
                'direct_evidence_is_evaluated' => true,
                'context_dimensions_are_correlational' => true,
                'current_source_is_read_from_pro_at_export_time' => true,
                'source_snapshot_available_when_present' => true,
            ),
        );
    }

    /**
     * Evidencia compacta de la fuente al preparar una pregunta nueva.
     * Se guarda dentro de expected_json y no participa en la evaluacion.
     */
    public static function capture_question_source($item) {
        $item = is_array($item) ? $item : array();
        $source_type = sanitize_key((string) ($item['source_type'] ?? ''));
        $source_id = absint($item['source_id'] ?? 0);
        $source_key = sanitize_text_field((string) ($item['source_key'] ?? ''));
        $expected = is_array($item['expected'] ?? null) ? $item['expected'] : array();
        $product_id = self::source_product_id($source_type, $source_id, $expected);

        $snapshot = array(
            'version' => 1,
            'captured_at' => current_time('mysql'),
            'source_type' => $source_type,
            'source_id' => $source_id ?: null,
            'source_key' => $source_key,
        );

        if ($product_id) {
            $source = self::current_product_source($product_id, true);
            if ($source) {
                $snapshot['product_id'] = $product_id;
                $snapshot['title'] = (string) ($source['title'] ?? '');
                $snapshot['modified_at'] = (string) ($source['modified_at'] ?? '');
                $snapshot['brand'] = (string) ($source['brand'] ?? '');
                $snapshot['categories'] = array_slice((array) ($source['categories'] ?? array()), 0, 12);
                $snapshot['tags'] = array_slice((array) ($source['tags'] ?? array()), 0, 20);
                $snapshot['vocabulary'] = (array) ($source['vocabulary'] ?? array());
                $snapshot['attributes'] = array_slice((array) ($source['attributes'] ?? array()), 0, 24);
                $snapshot['signature'] = (string) ($source['signature'] ?? '');
            }
        } elseif ('category' === $source_type && $source_id) {
            $source = self::current_category_source($source_id);
            if ($source) {
                $snapshot = array_merge($snapshot, array(
                    'title' => (string) ($source['title'] ?? ''),
                    'modified_at' => (string) ($source['modified_at'] ?? ''),
                    'signature' => (string) ($source['signature'] ?? ''),
                ));
            }
        } elseif ('vocabulary' === $source_type && $source_id) {
            $source = self::current_vocabulary_source($source_id);
            if ($source) {
                $snapshot = array_merge($snapshot, array(
                    'title' => (string) ($source['title'] ?? ''),
                    'semantic_group' => (string) ($source['semantic_group'] ?? ''),
                    'slug' => (string) ($source['slug'] ?? ''),
                    'signature' => (string) ($source['signature'] ?? ''),
                ));
            }
        }

        return $snapshot;
    }

    private static function available() {
        global $wpdb;
        if (!class_exists('SEO_Dependiente_Entrenador')) {
            return false;
        }
        foreach (array(SEO_Dependiente_Entrenador::lessons_table(), SEO_Dependiente_Entrenador::questions_table(), SEO_Dependiente_Entrenador::runs_table()) as $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ((string) $found !== (string) $table) {
                return false;
            }
        }
        return true;
    }

    private static function render_subnav($base, $view, $lesson_key) {
        $items = array(
            'summary' => 'Resumen',
            'evolution' => 'Evolucion',
            'quality' => 'Calidad de formacion',
            'failures' => 'Fallos y fuentes',
        );
        echo '<nav class="seo-dependiente-training-quality__subnav" aria-label="Analisis del aprendizaje">';
        foreach ($items as $key => $label) {
            $url = add_query_arg(array('learning_view' => $key, 'learning_lesson' => $lesson_key), $base);
            echo '<a class="button ' . ($key === $view ? 'button-primary' : 'button-secondary') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    private static function render_lesson_filter($base, $view, $lesson_key, $lessons) {
        echo '<form method="get" class="seo-dependiente-training-quality__filter">';
        echo '<input type="hidden" name="page" value="seo-dependiente"><input type="hidden" name="tab" value="learning"><input type="hidden" name="learning_view" value="' . esc_attr($view) . '">';
        echo '<label><strong>Leccion</strong> <select name="learning_lesson">';
        echo '<option value="">Curso completo</option>';
        foreach ((array) $lessons as $lesson) {
            $key = (string) ($lesson['lesson_key'] ?? '');
            $label = 'L' . absint($lesson['lesson_order'] ?? 0) . ' · ' . (string) ($lesson['title'] ?? $key);
            echo '<option value="' . esc_attr($key) . '" ' . selected($lesson_key, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label><button class="button">Aplicar</button>';
        echo '</form>';
    }

    private static function render_summary($data) {
        $s = $data['summary'];
        echo '<div class="seo-dependiente-admin__metrics seo-dependiente-training-quality__metrics">';
        self::metric('Lecciones con datos', $s['lessons_with_data']);
        self::metric('Evaluadas', $s['answered']);
        self::metric('Top 1', self::percent($s['top1'], $s['answered']));
        self::metric('Top 3', self::percent($s['top3'], $s['answered']));
        self::metric('Pass any', self::percent($s['pass_any'], $s['answered']));
        self::metric('Fallos', $s['failed']);
        self::metric('Errores tecnicos', $s['errors']);
        self::metric('Fuentes a revisar', $s['review_sources']);
        echo '</div>';

        echo '<div class="seo-dependiente-training-quality__grid">';
        echo '<section class="postbox seo-dependiente-admin__box"><h3>Evolucion por leccion</h3>';
        self::render_lessons_table($data['lessons']);
        echo '</section>';
        echo '<section class="postbox seo-dependiente-admin__box"><h3>Diagnostico de los fallos</h3>';
        self::render_diagnostics($data['diagnostics']);
        echo '</section>';
        echo '</div>';

        echo '<section class="postbox seo-dependiente-admin__box seo-dependiente-admin__wide-box"><h3>Contenido que merece revision primero</h3>';
        echo '<p class="description">Prioriza fuentes con varias evaluaciones y concentracion repetida de fallos. Una fila con una sola pregunta no se marca como fuente defectuosa aunque falle.</p>';
        self::render_ranking_table(array_slice($data['source_rankings'], 0, 20), 'source');
        echo '</section>';

        if ($data['module_alerts']) {
            echo '<section class="postbox seo-dependiente-admin__box seo-dependiente-admin__wide-box"><h3>Concentraciones por modulo</h3>';
            self::render_module_alerts($data['module_alerts']);
            echo '</section>';
        }
    }

    private static function render_evolution($data) {
        echo '<section class="postbox seo-dependiente-admin__box seo-dependiente-admin__wide-box">';
        echo '<h3>Evolucion observada L1 → L8</h3><p class="description">Mide rendimiento observado. No atribuye causalmente la mejora a la ensenanza porque la dificultad y el tipo de pregunta cambian entre lecciones.</p>';
        self::render_lessons_table($data['lessons'], true);
        echo '</section>';

        echo '<div class="seo-dependiente-training-quality__grid">';
        echo '<section class="postbox seo-dependiente-admin__box"><h3>Dominio por evidencia evaluada</h3><p class="description">Solo elementos que formaban parte directa de la verdad esperada.</p>';
        self::render_ranking_table(array_slice($data['direct_rankings'], 0, 30), 'dimension');
        echo '</section>';
        echo '<section class="postbox seo-dependiente-admin__box"><h3>Contexto correlacionado</h3><p class="description">Cluster, hubs, categorias, etiquetas, atributos y Vocabulary asociados al producto/fuente. Sirve para descubrir patrones, no para afirmar causalidad.</p>';
        self::render_ranking_table(array_slice($data['context_rankings'], 0, 30), 'dimension');
        echo '</section>';
        echo '</div>';
    }

    private static function render_quality($data) {
        echo '<section class="postbox seo-dependiente-admin__box seo-dependiente-admin__wide-box">';
        echo '<h3>Fuentes a revisar</h3><p class="description">Senales de calidad de formacion. La prioridad combina soporte y tasa de fallo; nunca modifica automaticamente PRO.</p>';
        self::render_ranking_table($data['source_rankings'], 'source');
        echo '</section>';

        echo '<div class="seo-dependiente-training-quality__grid">';
        echo '<section class="postbox seo-dependiente-admin__box"><h3>Evidencia con mas deuda</h3>';
        self::render_ranking_table(array_slice($data['direct_rankings'], 0, 50), 'dimension');
        echo '</section>';
        echo '<section class="postbox seo-dependiente-admin__box"><h3>Contextos donde se concentran fallos</h3>';
        self::render_ranking_table(array_slice($data['context_rankings'], 0, 50), 'dimension');
        echo '</section>';
        echo '</div>';
    }

    private static function render_failures($data) {
        echo '<section class="postbox seo-dependiente-admin__box seo-dependiente-admin__wide-box">';
        echo '<h3>Fallos concretos y evidencia de origen</h3><p class="description">Muestra la pregunta, lo esperado, lo que devolvio Dependiente, el diagnostico y la fuente actual. Se muestran hasta ' . esc_html(number_format_i18n(self::DETAIL_LIMIT)) . ' fallos en pantalla; el export de progreso incluye todos los fallos de la leccion seleccionada.</p>';
        if (!$data['failure_details']) {
            echo '<p>No hay fallos en el alcance seleccionado.</p></section>';
            return;
        }
        echo '<div class="seo-dependiente-training-quality__failures">';
        foreach ($data['failure_details'] as $failure) {
            self::render_failure_card($failure);
        }
        echo '</div></section>';
    }

    private static function render_failure_card($f) {
        $expected = (array) ($f['expected'] ?? array());
        $source = (array) ($f['current_source'] ?? array());
        echo '<details class="seo-dependiente-training-quality__failure">';
        echo '<summary><span class="seo-dependiente-training-quality__failure-status">' . esc_html((string) ($f['diagnostic_label'] ?? 'Fallo')) . '</span> <strong>M' . esc_html(absint($f['module_no'] ?? 0)) . ' · ' . esc_html((string) ($f['question'] ?? '')) . '</strong></summary>';
        echo '<div class="seo-dependiente-training-quality__failure-body">';
        echo '<div><h4>Evaluacion</h4>';
        echo '<p><strong>Leccion:</strong> ' . esc_html((string) ($f['lesson_key'] ?? '')) . '<br><strong>Tipo:</strong> <code>' . esc_html((string) ($f['question_type'] ?? '')) . '</code><br><strong>Estrategia:</strong> <code>' . esc_html((string) ($f['search_strategy'] ?? '')) . '</code><br><strong>Diagnostico:</strong> ' . esc_html((string) ($f['diagnostic_label'] ?? '')) . '</p>';
        echo '<p><strong>Fuente docente:</strong> <code>' . esc_html((string) ($f['source_type'] ?? '')) . ':' . esc_html((string) ($f['source_id'] ?? '')) . '</code><br><strong>source_key:</strong> <code>' . esc_html((string) ($f['source_key'] ?? '')) . '</code></p>';
        echo '<details><summary>Verdad esperada</summary><pre>' . esc_html(wp_json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre></details>';
        echo '</div>';
        echo '<div><h4>Respuesta del Dependiente</h4>';
        $results = (array) ($f['top_results'] ?? array());
        if ($results) {
            echo '<ol>';
            foreach (array_slice($results, 0, 8) as $r) {
                echo '<li><strong>' . esc_html((string) ($r['title'] ?? '')) . '</strong> <code>#' . esc_html(absint($r['id'] ?? 0)) . '</code>';
                if (!empty($r['reasons'])) {
                    echo '<div class="description">' . esc_html(implode(' · ', (array) $r['reasons'])) . '</div>';
                }
                echo '</li>';
            }
            echo '</ol>';
        } else {
            echo '<p>Sin resultados devueltos.</p>';
        }
        echo '</div>';
        echo '<div class="seo-dependiente-training-quality__source"><h4>Fuente actual en PRO</h4>';
        if (!$source) {
            echo '<p class="description">No se pudo reconstruir automaticamente la fuente actual.</p>';
        } else {
            self::render_source_snapshot($source, (array) ($f['prepared_source'] ?? array()), (string) ($f['question_created_at'] ?? ''));
        }
        echo '</div></div></details>';
    }

    private static function render_source_snapshot($source, $prepared, $question_created_at = '') {
        if (!empty($source['edit_url'])) {
            echo '<p><a class="button button-small" href="' . esc_url($source['edit_url']) . '">Abrir fuente para revisar</a></p>';
        }
        foreach (array('title' => 'Titulo', 'seo_title' => 'Titulo SEO/SERP', 'seo_description' => 'Descripcion SEO/SERP', 'brand' => 'Marca') as $key => $label) {
            if (!empty($source[$key])) {
                echo '<p><strong>' . esc_html($label) . ':</strong> ' . esc_html((string) $source[$key]) . '</p>';
            }
        }
        if (!empty($source['excerpt'])) {
            echo '<p><strong>Descripcion corta:</strong> ' . esc_html((string) $source['excerpt']) . '</p>';
        }
        if (!empty($source['description'])) {
            echo '<p><strong>Descripcion:</strong> ' . esc_html((string) $source['description']) . '</p>';
        }
        foreach (array('categories' => 'Categorias', 'tags' => 'Etiquetas', 'attributes' => 'Atributos') as $key => $label) {
            if (!empty($source[$key])) {
                echo '<p><strong>' . esc_html($label) . ':</strong> ' . esc_html(self::flatten_labels($source[$key])) . '</p>';
            }
        }
        if (!empty($source['vocabulary'])) {
            echo '<p><strong>Vocabulary:</strong> ' . esc_html(self::flatten_vocabulary($source['vocabulary'])) . '</p>';
        }
        if (!empty($source['architecture'])) {
            echo '<p><strong>Arquitectura:</strong> ' . esc_html(implode(' · ', array_filter((array) $source['architecture']))) . '</p>';
        }
        if (!empty($prepared['signature']) && !empty($source['signature'])) {
            $same = hash_equals((string) $prepared['signature'], (string) $source['signature']);
            echo '<p><strong>Comparacion con la fuente al preparar la pregunta:</strong> <span class="seo-dependiente-training-quality__pill ' . ($same ? 'is-ok' : 'is-review') . '">' . ($same ? 'Sin cambios detectados' : 'La fuente ha cambiado') . '</span></p>';
        } else {
            echo '<p class="description">No hay una firma historica comparable para esta pregunta; se muestra el contenido actual de PRO.</p>';
            if ($question_created_at !== '' && !empty($source['modified_at'])) {
                $qts = strtotime($question_created_at);
                $sts = strtotime((string) $source['modified_at']);
                if ($qts && $sts && $sts > $qts) {
                    echo '<p><span class="seo-dependiente-training-quality__pill is-review">La fuente fue modificada despues de preparar la pregunta</span></p>';
                }
            }
        }
        if (!empty($source['modified_at'])) {
            echo '<p class="description">Ultima modificacion: ' . esc_html((string) $source['modified_at']) . '</p>';
        }
    }

    private static function render_lessons_table($lessons, $show_delta = false) {
        echo '<div class="seo-dependiente-admin__table-wrap"><table class="widefat striped"><thead><tr><th>Leccion</th><th>Evaluadas</th><th>Top1</th><th>Top3</th><th>Pass any</th><th>Fallos</th><th>Estado</th>' . ($show_delta ? '<th>Δ anterior</th>' : '') . '</tr></thead><tbody>';
        $previous = null;
        foreach ((array) $lessons as $row) {
            $answered = absint($row['answered'] ?? 0);
            if (!$answered && empty($row['item_count'])) {
                continue;
            }
            $ratio = $answered ? (float) ($row['pass_any'] ?? 0) / $answered : null;
            $state = self::lesson_state($row);
            echo '<tr><td><strong>L' . esc_html(absint($row['lesson_order'] ?? 0)) . ' · ' . esc_html((string) ($row['title'] ?? '')) . '</strong><div class="description"><code>' . esc_html((string) ($row['lesson_key'] ?? '')) . '</code></div></td>';
            echo '<td>' . esc_html(number_format_i18n($answered)) . '</td><td>' . esc_html(self::percent($row['top1'] ?? 0, $answered)) . '</td><td>' . esc_html(self::percent($row['top3'] ?? 0, $answered)) . '</td><td><strong>' . esc_html(self::percent($row['pass_any'] ?? 0, $answered)) . '</strong></td><td>' . esc_html(number_format_i18n(absint($row['failed'] ?? 0))) . '</td><td><span class="seo-dependiente-training-quality__pill ' . esc_attr($state['class']) . '">' . esc_html($state['label']) . '</span></td>';
            if ($show_delta) {
                $delta = null !== $ratio && null !== $previous ? ($ratio - $previous) * 100 : null;
                echo '<td>' . (null === $delta ? '—' : esc_html(($delta >= 0 ? '+' : '') . number_format_i18n($delta, 1) . ' pp')) . '</td>';
                if (null !== $ratio) $previous = $ratio;
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function render_diagnostics($diagnostics) {
        if (!$diagnostics) {
            echo '<p>Sin fallos diagnosticados.</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Diagnostico</th><th>Casos</th><th>Destino</th></tr></thead><tbody>';
        foreach ($diagnostics as $row) {
            echo '<tr><td>' . esc_html((string) $row['label']) . '</td><td><strong>' . esc_html(number_format_i18n(absint($row['count']))) . '</strong></td><td>' . esc_html((string) $row['destination']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_ranking_table($rows, $mode) {
        if (!$rows) {
            echo '<p>Sin datos suficientes.</p>';
            return;
        }
        echo '<div class="seo-dependiente-admin__table-wrap"><table class="widefat striped"><thead><tr><th>Elemento</th><th>Tipo</th><th>Evaluadas</th><th>Fallos</th><th>Rendimiento</th><th>Revision</th></tr></thead><tbody>';
        foreach ((array) $rows as $row) {
            $total = absint($row['total'] ?? 0);
            $fail = absint($row['failed'] ?? 0);
            $passed = max(0, $total - $fail - absint($row['errors'] ?? 0));
            $review = self::review_state($row);
            echo '<tr><td><strong>' . esc_html((string) ($row['label'] ?? '')) . '</strong>';
            if ('source' === $mode && !empty($row['key'])) echo '<div class="description"><code>' . esc_html((string) $row['key']) . '</code></div>';
            echo '</td><td>' . esc_html((string) ($row['type_label'] ?? $row['type'] ?? '')) . '</td><td>' . esc_html(number_format_i18n($total)) . '</td><td><strong>' . esc_html(number_format_i18n($fail)) . '</strong></td><td>' . esc_html(self::percent($passed, $total)) . '</td><td><span class="seo-dependiente-training-quality__pill ' . esc_attr($review['class']) . '">' . esc_html($review['label']) . '</span></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function render_module_alerts($rows) {
        echo '<table class="widefat striped"><thead><tr><th>Leccion</th><th>Modulo</th><th>Evaluadas</th><th>Fallos</th><th>Rendimiento</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $total = absint($row['total']);
            $pass = max(0, $total - absint($row['failed']) - absint($row['errors']));
            echo '<tr><td>' . esc_html((string) $row['lesson_key']) . '</td><td><strong>M' . esc_html(absint($row['module_no'])) . '</strong></td><td>' . esc_html(number_format_i18n($total)) . '</td><td>' . esc_html(number_format_i18n(absint($row['failed']))) . '</td><td>' . esc_html(self::percent($pass, $total)) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function metric($label, $value) {
        echo '<div class="seo-dependiente-admin__metric"><strong>' . esc_html((string) $value) . '</strong><span>' . esc_html($label) . '</span></div>';
    }

    private static function build_report($lesson_key = '', $for_export = false) {
        $lessons = self::lesson_rows($lesson_key);
        $lesson_stats = array();
        $direct = array();
        $context = array();
        $sources = array();
        $diagnostics = array();
        $modules = array();
        $summary = array('lessons_with_data'=>0,'answered'=>0,'top1'=>0,'top3'=>0,'pass_any'=>0,'failed'=>0,'errors'=>0,'review_sources'=>0);

        /*
         * El informe puede acumular decenas de miles de preguntas. No cargamos
         * todo el histórico (ni los JSON pesados) en una sola petición PHP:
         * recorremos el último run de cada pregunta por bloques y liberamos cada
         * bloque antes de pedir el siguiente.
         */
        $last_question_id = 0;
        do {
            $rows = self::evaluated_rows_batch($lesson_key, $last_question_id, self::REPORT_BATCH_SIZE);
            if (!$rows) {
                break;
            }

            $product_ids = array();
            foreach ($rows as $row) {
                $last_question_id = max($last_question_id, absint($row['question_id'] ?? 0));
                $expected = self::decode($row['expected_json'] ?? '');
                $pid = self::source_product_id((string) ($row['source_type'] ?? ''), absint($row['source_id'] ?? 0), $expected);
                if ($pid) {
                    $product_ids[$pid] = true;
                }
            }

            $products = self::prefetch_products(array_keys($product_ids));
            $architecture = self::prefetch_architecture($products, $rows);

            foreach ($rows as $row) {
                $lesson = sanitize_key((string) ($row['lesson_key'] ?? ''));
                if (!isset($lesson_stats[$lesson])) $lesson_stats[$lesson] = self::empty_stats();
                $status = sanitize_key((string) ($row['evaluation_status'] ?? ''));
                $is_error = ('error' === $status || 'error' === sanitize_key((string) ($row['run_status'] ?? '')));
                $is_pass = 0 === strpos($status, 'pass_');
                $is_fail = !$is_error && !$is_pass && 'observed' !== $status && '' !== $status;
                $top1 = 'pass_top1' === $status;
                $top3 = in_array($status, array('pass_top1','pass_top3'), true);
                $answered = '' !== $status && 'observed' !== $status;
                if (!$answered) continue;

                self::accumulate_stats($lesson_stats[$lesson], $top1, $top3, $is_pass, $is_fail, $is_error);
                self::accumulate_stats($summary, $top1, $top3, $is_pass, $is_fail, $is_error);

                $expected = self::decode($row['expected_json'] ?? '');
                $evaluation = self::decode($row['evaluation_json'] ?? '');
                $diagnostic = sanitize_key((string) ($evaluation['diagnostic_type'] ?? ($is_error ? 'technical_error' : 'unknown')));
                if ($is_fail || $is_error) {
                    if (!isset($diagnostics[$diagnostic])) $diagnostics[$diagnostic] = 0;
                    $diagnostics[$diagnostic]++;
                }

                $module_key = $lesson . ':' . absint($row['module_no'] ?? 0);
                if (!isset($modules[$module_key])) $modules[$module_key] = array('lesson_key'=>$lesson,'module_no'=>absint($row['module_no'] ?? 0),'total'=>0,'failed'=>0,'errors'=>0);
                $modules[$module_key]['total']++;
                if ($is_fail) $modules[$module_key]['failed']++;
                if ($is_error) $modules[$module_key]['errors']++;

                foreach (self::direct_dimensions($row, $expected) as $dimension) {
                    self::accumulate_dimension($direct, $dimension, $is_fail, $is_error);
                }

                $pid = self::source_product_id((string) ($row['source_type'] ?? ''), absint($row['source_id'] ?? 0), $expected);
                if ($pid && isset($products[$pid])) {
                    foreach (self::context_dimensions($products[$pid], $architecture) as $dimension) {
                        self::accumulate_dimension($context, $dimension, $is_fail, $is_error);
                    }
                } elseif ('category' === sanitize_key((string) ($row['source_type'] ?? '')) && !empty($row['source_id'])) {
                    $cid = absint($row['source_id']);
                    self::accumulate_dimension($context, self::dimension('category', (string)$cid, self::term_name($cid)), $is_fail, $is_error);
                    foreach (self::architecture_dimensions_for_category($cid, $architecture) as $dimension) {
                        self::accumulate_dimension($context, $dimension, $is_fail, $is_error);
                    }
                }

                $source = self::source_dimension($row, $expected, $products);
                self::accumulate_dimension($sources, $source, $is_fail, $is_error);
            }

            unset($products, $architecture, $product_ids, $rows);
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        } while (true);

        foreach ($lessons as &$lesson) {
            $stats = $lesson_stats[(string) ($lesson['lesson_key'] ?? '')] ?? self::empty_stats();
            $lesson = array_merge($lesson, $stats);
        }
        unset($lesson);
        $summary['lessons_with_data'] = count(array_filter($lessons, static function($l){ return absint($l['answered'] ?? 0) > 0; }));

        $direct_rankings = self::rank_dimensions($direct);
        $context_rankings = self::rank_dimensions($context);
        $source_rankings = self::rank_dimensions($sources);
        $summary['review_sources'] = count(array_filter($source_rankings, static function($row){ return in_array(self::review_state($row)['label'], array('Alta','Media'), true); }));

        $diag_rows = array();
        arsort($diagnostics);
        foreach ($diagnostics as $key => $count) {
            $diag_rows[] = array('key'=>$key,'label'=>self::diagnostic_label($key),'count'=>$count,'destination'=>self::diagnostic_destination($key));
        }

        $module_alerts = array_values(array_filter($modules, static function($row){
            $total = absint($row['total']); $failed = absint($row['failed']);
            return $total >= 10 && $failed >= 5 && ($failed / max(1,$total)) >= 0.20;
        }));
        usort($module_alerts, static function($a,$b){
            $ra = absint($a['failed']) / max(1,absint($a['total']));
            $rb = absint($b['failed']) / max(1,absint($b['total']));
            return $rb <=> $ra ?: absint($b['failed']) <=> absint($a['failed']);
        });

        /*
         * Los JSON de trazas, resultados y respuesta son la parte más pesada.
         * Solo se cargan para los fallos que realmente se van a mostrar/exportar.
         */
        $failure_limit = $for_export ? self::EXPORT_FAILURE_LIMIT : self::DETAIL_LIMIT;
        $failures = self::latest_failure_details($lesson_key, $failure_limit);
        $export_failures = $for_export ? array_map(array(__CLASS__, 'compact_export_failure'), $failures) : array();

        return array(
            'summary'=>$summary,
            'lessons'=>$lessons,
            'direct_rankings'=>$direct_rankings,
            'context_rankings'=>$context_rankings,
            'source_rankings'=>$source_rankings,
            'diagnostics'=>$diag_rows,
            'module_alerts'=>$module_alerts,
            'failure_details'=>array_slice($failures,0,self::DETAIL_LIMIT),
            'export_failures'=>$export_failures,
        );
    }

    /**
     * Lee un bloque ligero del último run de cada pregunta.
     * No incluye top_results, response_meta ni error_message.
     */
    private static function evaluated_rows_batch($lesson_key, $after_question_id, $limit) {
        global $wpdb;
        $q = SEO_Dependiente_Entrenador::questions_table();
        $r = SEO_Dependiente_Entrenador::runs_table();
        $limit = max(25, min(1000, absint($limit)));
        $where = "q.enabled=1 AND q.lesson_key<>'' AND q.lesson_key NOT LIKE 'lab\\_%' AND q.id>%d";
        $args = array(absint($after_question_id));
        if ($lesson_key) {
            $where .= ' AND q.lesson_key=%s';
            $args[] = $lesson_key;
        }
        $args[] = $limit;
        $sql = "SELECT q.id question_id,q.lesson_key,q.lesson_order,q.module_no,q.source_type,q.source_id,q.source_key,q.question_type,q.expected_json,q.created_at question_created_at,
                       r.id run_id,r.status run_status,r.evaluation_status,r.evaluation_json,r.created_at run_created_at
                FROM {$q} q
                INNER JOIN (SELECT question_id,MAX(id) run_id FROM {$r} WHERE question_id IS NOT NULL GROUP BY question_id) lr ON lr.question_id=q.id
                INNER JOIN {$r} r ON r.id=lr.run_id
                WHERE {$where}
                ORDER BY q.id ASC
                LIMIT %d";
        return (array) $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
    }

    /**
     * Recupera únicamente los fallos recientes con sus campos pesados.
     */
    private static function latest_failure_details($lesson_key, $limit) {
        global $wpdb;
        $q = SEO_Dependiente_Entrenador::questions_table();
        $r = SEO_Dependiente_Entrenador::runs_table();
        $limit = max(1, min(self::EXPORT_FAILURE_LIMIT, absint($limit)));
        $where = "q.enabled=1 AND q.lesson_key<>'' AND q.lesson_key NOT LIKE 'lab\\_%'";
        $args = array();
        if ($lesson_key) {
            $where .= ' AND q.lesson_key=%s';
            $args[] = $lesson_key;
        }
        $where .= " AND (r.status='error' OR r.evaluation_status='error' OR (r.evaluation_status<>'' AND r.evaluation_status<>'observed' AND r.evaluation_status NOT LIKE 'pass\\_%'))";
        $args[] = $limit;
        $sql = "SELECT q.id question_id,q.lesson_key,q.lesson_order,q.module_no,q.source_type,q.source_id,q.source_key,q.question_type,q.mode,q.question,q.expected_json,q.created_at question_created_at,
                       r.id run_id,r.status run_status,r.search_strategy,r.evaluation_status,r.evaluation_score,r.evaluation_json,r.top_results,r.response_meta,r.error_message,r.created_at run_created_at
                FROM {$q} q
                INNER JOIN (SELECT question_id,MAX(id) run_id FROM {$r} WHERE question_id IS NOT NULL GROUP BY question_id) lr ON lr.question_id=q.id
                INNER JOIN {$r} r ON r.id=lr.run_id
                WHERE {$where}
                ORDER BY r.created_at DESC,r.id DESC
                LIMIT %d";
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
        if (!$rows) {
            return array();
        }

        $product_ids = array();
        foreach ($rows as $row) {
            $expected = self::decode($row['expected_json'] ?? '');
            $pid = self::source_product_id((string) ($row['source_type'] ?? ''), absint($row['source_id'] ?? 0), $expected);
            if ($pid) $product_ids[$pid] = true;
        }
        $products = self::prefetch_products(array_keys($product_ids));
        $architecture = self::prefetch_architecture($products, $rows);
        $failures = array();
        foreach ($rows as $row) {
            $expected = self::decode($row['expected_json'] ?? '');
            $evaluation = self::decode($row['evaluation_json'] ?? '');
            $failures[] = self::failure_detail($row, $expected, $evaluation, $products, $architecture);
        }
        return $failures;
    }

    private static function lesson_rows($lesson_key) {
        global $wpdb;
        $table = SEO_Dependiente_Entrenador::lessons_table();
        $sql = "SELECT lesson_key,lesson_order,title,status,module_count,item_count,snapshot_before,snapshot_after,source_signature,started_at,completed_at FROM {$table}";
        if ($lesson_key) $sql .= $wpdb->prepare(' WHERE lesson_key=%s', $lesson_key);
        $sql .= ' ORDER BY lesson_order ASC,id ASC';
        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    private static function empty_stats() {
        return array('answered'=>0,'top1'=>0,'top3'=>0,'pass_any'=>0,'failed'=>0,'errors'=>0);
    }

    private static function accumulate_stats(&$stats, $top1, $top3, $pass, $fail, $error) {
        if (!isset($stats['answered'])) $stats = array_merge(self::empty_stats(), (array)$stats);
        $stats['answered']++;
        if ($top1) $stats['top1']++;
        if ($top3) $stats['top3']++;
        if ($pass) $stats['pass_any']++;
        if ($fail) $stats['failed']++;
        if ($error) $stats['errors']++;
    }

    private static function direct_dimensions($row, $expected) {
        $out = array();
        $kind = sanitize_key((string) ($expected['kind'] ?? ''));
        if ('product' === $kind && !empty($expected['product_id'])) {
            $out[] = self::dimension('product', (string)absint($expected['product_id']), (string)($expected['title'] ?? ('Producto #' . absint($expected['product_id']))));
        }
        if ('category' === $kind && !empty($expected['category_id'])) {
            $out[] = self::dimension('category', (string)absint($expected['category_id']), (string)($expected['category_name'] ?? self::term_name(absint($expected['category_id']))));
            foreach ((array) ($expected['category_vocabulary'] ?? array()) as $v) {
                $out[] = self::dimension('vocabulary:' . sanitize_key((string)($v['group']??'')), (string)($v['slug']??$v['id']??''), (string)($v['label']??$v['slug']??''));
            }
        }
        foreach ((array) ($expected['conditions'] ?? array()) as $group => $slugs) {
            foreach ((array)$slugs as $slug) {
                $out[] = self::dimension('vocabulary:' . sanitize_key((string)$group), (string)$slug, ucfirst((string)$group) . ': ' . (string)$slug);
            }
        }
        foreach ((array) ($expected['features'] ?? array()) as $feature) {
            $fk = sanitize_key((string)($feature['kind']??''));
            if ('vocabulary' === $fk) {
                $out[] = self::dimension('vocabulary:' . sanitize_key((string)($feature['group']??'')), (string)($feature['slug']??''), (string)($feature['label']??$feature['slug']??''));
            } elseif ('attribute' === $fk) {
                $key = sanitize_key((string)($feature['key']??$feature['label']??''));
                $value = (string)($feature['value']??'');
                $out[] = self::dimension('attribute', $key . ':' . sanitize_title($value), (string)($feature['label']??$key) . ' = ' . $value);
            } elseif ('tag' === $fk) {
                $out[] = self::dimension('tag', (string)($feature['slug']??$feature['id']??''), (string)($feature['label']??$feature['slug']??''));
            }
        }
        if ('faq' === $kind && !empty($expected['owner_id'])) {
            $type = absint($expected['owner_type']??0) === 3 ? 'product' : 'category';
            $out[] = self::dimension($type, (string)absint($expected['owner_id']), (string)($expected['owner_title']??ucfirst($type).' #'.absint($expected['owner_id'])));
        }
        return self::unique_dimensions($out);
    }

    private static function context_dimensions($product, $architecture) {
        $out = array();
        $pid = absint($product['product_id'] ?? 0);
        if ($pid) $out[] = self::dimension('source_product', (string)$pid, (string)($product['title']??('Producto #'.$pid)));
        if (!empty($product['brand_name'])) $out[] = self::dimension('brand', sanitize_title((string)$product['brand_name']), (string)$product['brand_name']);
        foreach ((array)($product['categories']??array()) as $cat) {
            $cid = absint($cat['id']??0); if (!$cid) continue;
            $out[] = self::dimension('category', (string)$cid, (string)($cat['name']??self::term_name($cid)));
            $out = array_merge($out, self::architecture_dimensions_for_category($cid, $architecture));
        }
        foreach ((array)($product['tags']??array()) as $tag) {
            $slug=(string)($tag['slug']??''); $label=(string)($tag['name']??$slug); if ($slug||$label) $out[]=self::dimension('tag',$slug?:sanitize_title($label),$label);
        }
        foreach ((array)($product['vocabulary']??array()) as $group=>$values) {
            foreach ((array)$values as $v) {
                $slug=(string)($v['slug']??''); if($slug) $out[]=self::dimension('vocabulary:'.sanitize_key((string)$group),$slug,(string)($v['label']??$slug));
            }
        }
        foreach ((array)($product['attributes']??array()) as $a) {
            $label=(string)($a['label']??$a['name']??'Atributo');
            foreach ((array)($a['values']??array()) as $value) {
                $out[]=self::dimension('attribute',sanitize_title($label).':'.sanitize_title((string)$value),$label.' = '.(string)$value);
            }
        }
        return self::unique_dimensions($out);
    }

    private static function source_dimension($row, $expected, $products) {
        $type = sanitize_key((string)($row['source_type']??'unknown'));
        $id = absint($row['source_id']??0);
        $key = $type . ':' . ($id ?: (string)($row['source_key']??''));
        $label = (string)($row['source_key']??$key);
        $pid = self::source_product_id($type,$id,$expected);
        if ($pid && isset($products[$pid])) $label = (string)($products[$pid]['title']??$label);
        elseif ('category'===$type && $id) $label = self::term_name($id);
        elseif ('vocabulary'===$type && $id) {
            $v=self::current_vocabulary_source($id); if($v) $label=(string)($v['title']??$label);
        } elseif ('faq'===$type && !empty($expected['owner_title'])) $label='FAQ · '.(string)$expected['owner_title'];
        return array('type'=>'source:' . $type,'type_label'=>'Fuente · '.self::type_label($type),'id'=>(string)$id,'key'=>$key,'label'=>$label);
    }

    private static function dimension($type,$id,$label) {
        return array('type'=>sanitize_key(str_replace(':','_',$type)), 'raw_type'=>$type, 'type_label'=>self::type_label($type), 'id'=>(string)$id, 'key'=>$type.':'.$id, 'label'=>trim((string)$label) ?: $type.':'.$id);
    }

    private static function unique_dimensions($rows) {
        $out=array(); foreach($rows as $row){ $key=(string)($row['key']??''); if($key) $out[$key]=$row; } return array_values($out);
    }

    private static function accumulate_dimension(&$bucket,$dimension,$fail,$error) {
        $key=(string)($dimension['key']??''); if(!$key)return;
        if(!isset($bucket[$key])) $bucket[$key]=array_merge($dimension,array('total'=>0,'failed'=>0,'errors'=>0));
        $bucket[$key]['total']++;
        if($fail)$bucket[$key]['failed']++;
        if($error)$bucket[$key]['errors']++;
    }

    private static function rank_dimensions($bucket) {
        $rows=array_values($bucket);
        usort($rows,static function($a,$b){
            $sa=self::review_score($a);$sb=self::review_score($b);
            return $sb <=> $sa ?: absint($b['failed']) <=> absint($a['failed']) ?: absint($b['total']) <=> absint($a['total']);
        });
        return $rows;
    }

    private static function review_score($row) {
        $total=absint($row['total']??0);$failed=absint($row['failed']??0);$errors=absint($row['errors']??0);
        if($total<2||$failed<1)return 0;
        $rate=$failed/max(1,$total);
        $support=min(1,log(1+$total)/log(21));
        return ($rate*70)+($support*20)+(min(1,$failed/10)*10)-min(15,$errors*3);
    }

    private static function review_state($row) {
        $total=absint($row['total']??0);$failed=absint($row['failed']??0);$rate=$failed/max(1,$total);
        if($total>=5&&$failed>=3&&$rate>=0.45)return array('label'=>'Alta','class'=>'is-review');
        if($total>=3&&$failed>=2&&$rate>=0.25)return array('label'=>'Media','class'=>'is-debt');
        if($failed>0)return array('label'=>'Observar','class'=>'is-debt');
        return array('label'=>'Baja','class'=>'is-ok');
    }

    private static function lesson_state($row) {
        $answered=absint($row['answered']??0);$pass=absint($row['pass_any']??0);$errors=absint($row['errors']??0);
        if(!$answered)return array('label'=>'Sin evaluar','class'=>'is-empty');
        $ratio=$pass/$answered;
        if($errors>max(3,$answered*0.03))return array('label'=>'REVISAR','class'=>'is-review');
        if($ratio>=0.90)return array('label'=>absint($row['failed']??0)?'DOMINADO + DEUDA':'DOMINADO','class'=>'is-ok');
        if($ratio>=0.80)return array('label'=>'DEUDA','class'=>'is-debt');
        return array('label'=>'REVISAR','class'=>'is-review');
    }

    private static function failure_detail($row,$expected,$evaluation,$products,$architecture) {
        $pid=self::source_product_id((string)($row['source_type']??''),absint($row['source_id']??0),$expected);
        $source=array(); if($pid) $source=self::current_product_source($pid,false,$products[$pid]??array(),$architecture);
        elseif('category'===sanitize_key((string)($row['source_type']??''))) $source=self::current_category_source(absint($row['source_id']??0));
        elseif('vocabulary'===sanitize_key((string)($row['source_type']??''))) $source=self::current_vocabulary_source(absint($row['source_id']??0));
        $prepared=(array)($expected['_academy_source']??array());
        return array(
            'question_id'=>absint($row['question_id']??0),'lesson_key'=>(string)($row['lesson_key']??''),'module_no'=>absint($row['module_no']??0),
            'question_type'=>(string)($row['question_type']??''),'question'=>(string)($row['question']??''),'source_type'=>(string)($row['source_type']??''),'source_id'=>absint($row['source_id']??0)?:null,'source_key'=>(string)($row['source_key']??''),
            'expected'=>$expected,'prepared_source'=>$prepared,'evaluation_status'=>(string)($row['evaluation_status']??''),'diagnostic_type'=>sanitize_key((string)($evaluation['diagnostic_type']??'')),'diagnostic_label'=>self::diagnostic_label((string)($evaluation['diagnostic_type']??'')),'search_strategy'=>(string)($row['search_strategy']??''),'top_results'=>self::decode($row['top_results']??''),'response_meta'=>self::decode($row['response_meta']??''),'error_message'=>(string)($row['error_message']??''),'current_source'=>$source,'question_created_at'=>(string)($row['question_created_at']??''),'created_at'=>(string)($row['run_created_at']??''),
        );
    }

    public static function compact_export_failure($f) {
        $source=(array)($f['current_source']??array());
        unset($source['description']); unset($source['excerpt']);
        return array(
            'question_id'=>$f['question_id'],'lesson_key'=>$f['lesson_key'],'module_no'=>$f['module_no'],'question_type'=>$f['question_type'],'question'=>$f['question'],
            'source_type'=>$f['source_type'],'source_id'=>$f['source_id'],'source_key'=>$f['source_key'],'expected'=>$f['expected'],'prepared_source'=>$f['prepared_source'],
            'evaluation_status'=>$f['evaluation_status'],'diagnostic_type'=>$f['diagnostic_type'],'search_strategy'=>$f['search_strategy'],'top_results'=>$f['top_results'],'response_meta'=>$f['response_meta'],'error_message'=>$f['error_message'],'current_source'=>$source,'question_created_at'=>$f['question_created_at'],'created_at'=>$f['created_at'],
        );
    }

    private static function source_product_id($source_type,$source_id,$expected) {
        if(!empty($expected['source_product_id']))return absint($expected['source_product_id']);
        if('product'===sanitize_key((string)$source_type)&&$source_id)return absint($source_id);
        if('features'===sanitize_key((string)$source_type)&&$source_id)return absint($source_id);
        if('product'===sanitize_key((string)($expected['kind']??''))&&!empty($expected['product_id']))return absint($expected['product_id']);
        if('faq'===sanitize_key((string)($expected['kind']??''))&&absint($expected['owner_type']??0)===3)return absint($expected['owner_id']??0);
        return 0;
    }

    private static function prefetch_products($ids) {
        $ids=array_values(array_unique(array_filter(array_map('absint',(array)$ids))));$map=array();
        if(!$ids||!class_exists('SEO_Dependiente_Index'))return $map;
        foreach(array_chunk($ids,800) as $chunk){
            foreach((array)SEO_Dependiente_Index::get_rows_by_ids($chunk,count($chunk)) as $row){$d=SEO_Dependiente_Index::decode_row($row);$pid=absint($d['product_id']??0);if($pid)$map[$pid]=$d;}
        }
        return $map;
    }

    private static function current_product_source($product_id,$compact=false,$prefetched=array(),$architecture=array()) {
        $product_id=absint($product_id); if(!$product_id)return array();
        $row=$prefetched;
        if(!$row&&class_exists('SEO_Dependiente_Index')){$rows=SEO_Dependiente_Index::get_rows_by_ids(array($product_id),1);if($rows)$row=SEO_Dependiente_Index::decode_row($rows[0]);}
        $post=get_post($product_id); if(!$post)return array();
        $title=(string)$post->post_title;$excerpt=wp_strip_all_tags((string)$post->post_excerpt);$description=wp_strip_all_tags(strip_shortcodes((string)$post->post_content));
        $seo_title=self::first_meta($product_id,array('_yoast_wpseo_title','rank_math_title','_aioseo_title','_seo_title'));
        $seo_desc=self::first_meta($product_id,array('_yoast_wpseo_metadesc','rank_math_description','_aioseo_description','_seo_description'));
        $categories=(array)($row['categories']??array());$tags=(array)($row['tags']??array());$vocabulary=(array)($row['vocabulary']??array());$attributes=(array)($row['attributes']??array());$brand=(string)($row['brand_name']??'');
        $signature=hash('sha256',wp_json_encode(array('title'=>$title,'excerpt'=>$excerpt,'description'=>$description,'seo_title'=>$seo_title,'seo_description'=>$seo_desc,'brand'=>$brand,'categories'=>$categories,'tags'=>$tags,'vocabulary'=>$vocabulary,'attributes'=>$attributes),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $arch_labels=array();foreach($categories as $cat){$cid=absint($cat['id']??0);foreach(self::architecture_dimensions_for_category($cid,$architecture) as $dim){$arch_labels[$dim['key']]=$dim['type_label'].': '.$dim['label'];}}
        return array(
            'kind'=>'product','id'=>$product_id,'title'=>$title,'seo_title'=>$seo_title,'seo_description'=>$seo_desc,'brand'=>$brand,
            'excerpt'=>$compact?'':self::shorten($excerpt,650),'description'=>$compact?'':self::shorten($description,900),'categories'=>$categories,'tags'=>$tags,'vocabulary'=>$vocabulary,'attributes'=>$attributes,'architecture'=>array_values($arch_labels),'modified_at'=>(string)$post->post_modified,'signature'=>$signature,
            'edit_url'=>add_query_arg(array('page'=>'product-page-admin','tab'=>'editar','product_id'=>$product_id),admin_url('admin.php')),
        );
    }

    private static function current_category_source($term_id) {
        $term_id=absint($term_id);$term=get_term($term_id,'product_cat');if(!$term||is_wp_error($term))return array();
        global $wpdb;$nodes=$wpdb->prefix.'seo_nodes';$excerpt='';$description=(string)$term->description;
        if((string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$nodes))===$nodes){
            $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT seo_role,keywords,updated_at FROM {$nodes} WHERE object_type='category' AND object_id=%d AND seo_role IN ('excerpt','description') AND status=1",$term_id),ARRAY_A);
            foreach($rows as $r){if('excerpt'===($r['seo_role']??''))$excerpt=(string)($r['keywords']??'');if('description'===($r['seo_role']??''))$description=(string)($r['keywords']??'');}
        }
        $sig=hash('sha256',wp_json_encode(array('name'=>$term->name,'slug'=>$term->slug,'parent'=>$term->parent,'excerpt'=>$excerpt,'description'=>$description),JSON_UNESCAPED_UNICODE));
        return array('kind'=>'category','id'=>$term_id,'title'=>(string)$term->name,'excerpt'=>self::shorten(wp_strip_all_tags($excerpt),650),'description'=>self::shorten(wp_strip_all_tags($description),900),'modified_at'=>'','signature'=>$sig,'edit_url'=>add_query_arg(array('page'=>'category-seo-admin','tab'=>'categorias','edit_category_id'=>$term_id),admin_url('admin.php')));
    }

    private static function current_vocabulary_source($id) {
        static $cache=array();$id=absint($id);if(!$id)return array();if(isset($cache[$id]))return $cache[$id];
        global $wpdb;$table=$wpdb->prefix.'seo_vocabulary';if((string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)return array();
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1",$id),ARRAY_A);if(!$row)return array();
        $sig=hash('sha256',wp_json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $cache[$id]=array('kind'=>'vocabulary','id'=>$id,'title'=>(string)($row['label']??$row['slug']??('Vocabulary #'.$id)),'semantic_group'=>(string)($row['semantic_group']??''),'slug'=>(string)($row['slug']??''),'description'=>self::shorten((string)($row['description']??''),900),'signature'=>$sig,'edit_url'=>admin_url('admin.php?page=seo-tags-vocabulary-admin'));
    }

    private static function prefetch_architecture($products,$rows) {
        global $wpdb;$category_ids=array();foreach($products as $p)foreach((array)($p['categories']??array()) as $c){$id=absint($c['id']??0);if($id)$category_ids[$id]=true;}
        foreach($rows as $row)if('category'===sanitize_key((string)($row['source_type']??''))&&$row['source_id'])$category_ids[absint($row['source_id'])]=true;
        $result=array('category_to_secondary'=>array(),'secondary_to_primary'=>array(),'primary_to_cluster'=>array());if(!$category_ids)return $result;
        $table=$wpdb->prefix.'seo_relations';if((string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)return $result;
        $ids=array_keys($category_ids);$ph=implode(',',array_fill(0,count($ids),'%d'));
        $sql=$wpdb->prepare("SELECT source_type,source_id,target_type,target_id,relation_type FROM {$table} WHERE (source_type='hub_secondary' AND target_type='product_cat' AND target_id IN ({$ph})) OR target_type='hub_secondary' OR target_type='hub_primary'",$ids);
        $rels=(array)$wpdb->get_results($sql,ARRAY_A);
        foreach($rels as $r){$st=sanitize_key((string)($r['source_type']??''));$tt=sanitize_key((string)($r['target_type']??''));$sid=absint($r['source_id']??0);$tid=absint($r['target_id']??0);if('hub_secondary'===$st&&'product_cat'===$tt&&$sid&&$tid)$result['category_to_secondary'][$tid][$sid]=true;elseif('hub_primary'===$st&&'hub_secondary'===$tt&&$sid&&$tid)$result['secondary_to_primary'][$tid][$sid]=true;elseif('cluster'===$st&&'hub_primary'===$tt&&$sid&&$tid)$result['primary_to_cluster'][$tid][$sid]=true;}
        return $result;
    }

    private static function architecture_dimensions_for_category($category_id,$architecture) {
        $out=array();foreach(array_keys((array)($architecture['category_to_secondary'][$category_id]??array())) as $secondary){$out[]=self::dimension('hub_secondary',(string)$secondary,get_the_title($secondary)?:('Hub secundario #'.$secondary));foreach(array_keys((array)($architecture['secondary_to_primary'][$secondary]??array())) as $primary){$out[]=self::dimension('hub_primary',(string)$primary,get_the_title($primary)?:('Hub primario #'.$primary));foreach(array_keys((array)($architecture['primary_to_cluster'][$primary]??array())) as $cluster){$out[]=self::dimension('cluster',(string)$cluster,get_the_title($cluster)?:('Cluster #'.$cluster));}}}return self::unique_dimensions($out);
    }

    private static function diagnostic_label($key) {
        $map=array('mastered'=>'Conocimiento resuelto','parser_gap'=>'Fallo de interpretacion','retrieval_gap'=>'Fallo de recuperacion','editorial_retrieval_gap'=>'Fallo de recuperacion editorial','faq_owner_retrieval_gap'=>'Fallo FAQ por owner','faq_owner_ranking_gap'=>'FAQ correcta fuera de Top8','cross_retrieval_gap'=>'Fallo de relacion cruzada','semantic_expansion_skipped'=>'Expansion semantica omitida','semantic_candidates_filtered'=>'Candidatos semanticos filtrados','semantic_route_unresolved'=>'Ruta semantica sin candidatos','ranking_gap'=>'Fallo de ranking/filtro','clarification_gap'=>'Aclaracion innecesaria','curriculum_invalid'=>'Pregunta/evaluacion a revisar','technical_error'=>'Error tecnico','observed'=>'Observacion','unknown'=>'Sin diagnostico');return $map[sanitize_key((string)$key)]??(string)$key;
    }

    private static function diagnostic_destination($key) {
        $key=sanitize_key((string)$key);if(in_array($key,array('retrieval_gap','editorial_retrieval_gap','faq_owner_retrieval_gap','faq_owner_ranking_gap','cross_retrieval_gap','semantic_expansion_skipped','semantic_candidates_filtered','semantic_route_unresolved','ranking_gap','parser_gap'),true))return 'Motor';if(in_array($key,array('curriculum_invalid','clarification_gap'),true))return 'Evaluacion / Academia';if('technical_error'===$key)return 'Tecnico';return 'Aprendizaje / revisar evidencia';
    }

    private static function type_label($type) {
        $map=array('product'=>'Producto','source_product'=>'Producto fuente','category'=>'Categoria','cluster'=>'Cluster','hub_primary'=>'Hub primario','hub_secondary'=>'Hub secundario','tag'=>'Etiqueta','attribute'=>'Atributo','brand'=>'Marca','vocabulary'=>'Vocabulary','features'=>'Combinacion de caracteristicas','faq'=>'FAQ','editorial'=>'Contenido editorial','cross'=>'Relacion cruzada','type_role'=>'Tipo/Rol','source_product'=>'Producto fuente');
        if(0===strpos((string)$type,'vocabulary:'))return 'Vocabulary · '.substr((string)$type,11);
        if(0===strpos((string)$type,'source:'))return 'Fuente · '.self::type_label(substr((string)$type,7));
        return $map[(string)$type]??ucfirst(str_replace('_',' ',(string)$type));
    }

    private static function term_name($id) {$term=get_term(absint($id),'product_cat');return $term&&!is_wp_error($term)?(string)$term->name:'Categoria #'.absint($id);}
    private static function decode($json) {if(is_array($json))return $json;$d=json_decode((string)$json,true);return is_array($d)?$d:array();}
    private static function percent($part,$total) {return absint($total)>0?number_format_i18n((100*(float)$part)/(float)$total,1).'%':'—';}
    private static function shorten($text,$limit) {$text=trim(preg_replace('/\s+/u',' ',(string)$text));if(function_exists('mb_strlen')&&mb_strlen($text,'UTF-8')>$limit)return mb_substr($text,0,$limit-1,'UTF-8').'…';return strlen($text)>$limit?substr($text,0,$limit-1).'…':$text;}
    private static function first_meta($id,$keys) {foreach($keys as $key){$v=trim((string)get_post_meta($id,$key,true));if($v!=='')return $v;}return '';}
    private static function flatten_labels($items) {$out=array();foreach((array)$items as $item){if(is_array($item)){$label=(string)($item['name']??$item['label']??$item['value']??'');if(isset($item['values']))$label=((string)($item['label']??$item['name']??'')) . ': ' . implode(', ',(array)$item['values']);if($label)$out[]=$label;}elseif((string)$item!=='')$out[]=(string)$item;}return implode(' · ',array_slice($out,0,30));}
    private static function flatten_vocabulary($vocabulary) {$out=array();foreach((array)$vocabulary as $group=>$items){foreach((array)$items as $item){$label=is_array($item)?(string)($item['label']??$item['slug']??''):(string)$item;if($label)$out[]=$group.': '.$label;}}return implode(' · ',array_slice($out,0,30));}
}
