<?php

defined('ABSPATH') || exit;

/**
 * Entrenador de Dependiente.
 *
 * Banco de preguntas + ejecuciones por lotes contra el mismo motor de busqueda
 * que usa la pagina publica. Las ejecuciones se guardan solo en las tablas
 * propias del Entrenador y quedan fuera del log y aprendizaje de clientes.
 */
final class SEO_Dependiente_Entrenador {
    const DB_VERSION = '2026-09-01.1';
    // Lotes deliberadamente pequenos. El navegador ajusta entre 1 y 4 segun
    // el tiempo real de respuesta del servidor.
    const AJAX_BATCH_MIN = 1;
    const AJAX_BATCH_INITIAL = 1;
    const AJAX_BATCH_LIMIT = 4;
    const QUESTION_LIST_LIMIT = 1000;
    const RECENT_RUN_LIMIT = 1000;

    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'), 20);
        add_action('wp_ajax_seo_dependiente_entrenador_add_questions', array(__CLASS__, 'ajax_add_questions'));
        add_action('wp_ajax_seo_dependiente_entrenador_delete_questions', array(__CLASS__, 'ajax_delete_questions'));
        add_action('wp_ajax_seo_dependiente_entrenador_run_batch', array(__CLASS__, 'ajax_run_batch'));
        add_action('wp_ajax_seo_dependiente_entrenador_export_json', array(__CLASS__, 'ajax_export_json'));
        add_action('wp_ajax_seo_dependiente_entrenador_clear_runs', array(__CLASS__, 'ajax_clear_runs'));
    }

    public static function questions_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_dependiente_trainer_questions';
    }

    public static function runs_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_dependiente_trainer_runs';
    }

    public static function install() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $questions = self::questions_table();
        $runs = self::runs_table();

        $questions_sql = "CREATE TABLE {$questions} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            question_hash CHAR(64) NOT NULL,
            question_type VARCHAR(40) NOT NULL DEFAULT 'other',
            mode VARCHAR(20) NOT NULL DEFAULT 'need',
            question VARCHAR(500) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY question_hash (question_hash),
            KEY idx_type_enabled (question_type, enabled),
            KEY idx_enabled_id (enabled, id)
        ) {$charset_collate};";

        $runs_sql = "CREATE TABLE {$runs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_uuid CHAR(36) NOT NULL,
            question_id BIGINT UNSIGNED NULL,
            question_type VARCHAR(40) NOT NULL DEFAULT 'other',
            mode VARCHAR(20) NOT NULL DEFAULT 'need',
            question VARCHAR(500) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'answered',
            result_count INT UNSIGNED NOT NULL DEFAULT 0,
            returned_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            search_uuid CHAR(36) NULL,
            search_strategy VARCHAR(40) NULL,
            execution_ms DECIMAL(10,3) NULL,
            top_results LONGTEXT NULL,
            response_meta LONGTEXT NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_batch_id (batch_uuid, id),
            KEY idx_type_created (question_type, created_at),
            KEY idx_status_created (status, created_at),
            KEY idx_created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($questions_sql);
        dbDelta($runs_sql);
        update_option('seo_dependiente_entrenador_db_version', self::DB_VERSION, false);
    }

    public static function ensure_ready() {
        $version = (string) get_option('seo_dependiente_entrenador_db_version', '');
        if (self::DB_VERSION !== $version || !self::table_exists(self::questions_table()) || !self::table_exists(self::runs_table())) {
            self::install();
        }
        return self::table_exists(self::questions_table()) && self::table_exists(self::runs_table());
    }

    public static function enqueue($hook) {
        if (false === strpos((string) $hook, 'seo-dependiente')) {
            return;
        }
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : 'settings';
        if ('trainer' !== $tab) {
            return;
        }

        wp_enqueue_style(
            'seo-dependiente-entrenador',
            SEO_DEPENDIENTE_URL . 'entrenador/assets/css/seo-dependiente-entrenador.css',
            array('seo-dependiente'),
            SEO_DEPENDIENTE_VERSION
        );
        wp_enqueue_script(
            'seo-dependiente-entrenador',
            SEO_DEPENDIENTE_URL . 'entrenador/assets/js/seo-dependiente-entrenador.js',
            array(),
            SEO_DEPENDIENTE_VERSION,
            true
        );
        wp_localize_script('seo-dependiente-entrenador', 'SEODependienteEntrenador', array(
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('seo_dependiente_entrenador'),
            'batchSize'    => self::AJAX_BATCH_INITIAL,
            'batchMin'     => self::AJAX_BATCH_MIN,
            'batchMax'     => self::AJAX_BATCH_LIMIT,
            'fastSeconds'  => 2.5,
            'slowSeconds'  => 7.0,
            'hardSeconds'  => 14.0,
            'maxRetries'   => 6,
        ));
    }

    public static function render_tab() {
        if (!self::ensure_ready()) {
            echo '<div class="notice notice-error"><p>No se han podido preparar las tablas del Entrenador.</p></div>';
            return;
        }

        $types = self::question_types();
        $questions = self::questions('', self::QUESTION_LIST_LIMIT);
        $question_count = self::question_count();
        $last_batch = self::last_batch_uuid();
        $summary = $last_batch ? self::batch_summary($last_batch) : self::empty_summary();
        $breakdown = $last_batch ? self::batch_breakdown($last_batch) : array();
        $runs = $last_batch ? self::batch_runs($last_batch, self::RECENT_RUN_LIMIT) : array();
        $history = self::batch_history(8);
        ?>
        <div class="seo-dependiente-trainer" data-trainer-root data-trainer-current-batch="<?php echo esc_attr($last_batch); ?>">
            <div class="seo-dependiente-trainer__intro">
                <div>
                    <h2>Entrenador del Dependiente</h2>
                    <p>Lanza preguntas por lotes contra el mismo buscador que usa el cliente. Este módulo mide respuestas y resultados; no crea reglas ni candidatos de aprendizaje y no escribe en el log de clientes.</p>
                </div>
                <span class="seo-dependiente-trainer__isolation">Aprendizaje desconectado</span>
            </div>

            <section class="postbox seo-dependiente-admin__box seo-dependiente-trainer__composer">
                <h2 class="seo-dependiente-admin__box-title">Añadir preguntas al banco</h2>
                <p class="description">Una pregunta por línea. Puedes usar <code>tipo | pregunta</code> o <code>tipo | modo | pregunta</code>. Si no indicas prefijos se aplican los valores seleccionados. Los lotes prueban el motor principal y no realizan llamadas masivas a proveedores externos como Amazon.</p>
                <div class="seo-dependiente-trainer__defaults">
                    <label>Tipo por defecto
                        <select data-trainer-default-type>
                            <?php foreach ($types as $slug => $label) : ?>
                                <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Modo de entrada por defecto
                        <select data-trainer-default-mode>
                            <option value="need">Necesidad</option>
                            <option value="product">Producto</option>
                            <option value="tool">Compatibilidad / herramienta</option>
                            <option value="compare">Comparar</option>
                        </select>
                    </label>
                </div>
                <textarea class="large-text code seo-dependiente-trainer__textarea" rows="9" data-trainer-questions placeholder="síntoma | se me ha bajado la rueda&#10;necesidad | necesito cortar una chapa fina&#10;producto | product | disco de corte 125 mm&#10;compatibilidad | tool | batería compatible con mi herramienta 18 V"></textarea>
                <div class="seo-dependiente-trainer__actions">
                    <button type="button" class="button button-primary" data-trainer-add>Añadir lista</button>
                    <span class="description" data-trainer-add-status></span>
                </div>
            </section>

            <section class="postbox seo-dependiente-admin__box seo-dependiente-trainer__bank">
                <div class="seo-dependiente-trainer__section-head">
                    <div>
                        <h2 class="seo-dependiente-admin__box-title">Banco de preguntas</h2>
                        <p class="description"><strong><?php echo esc_html(number_format_i18n($question_count)); ?></strong> preguntas guardadas. La tabla muestra hasta <?php echo esc_html(number_format_i18n(self::QUESTION_LIST_LIMIT)); ?>.</p>
                    </div>
                    <div class="seo-dependiente-trainer__filters">
                        <label>Tipo
                            <select data-trainer-filter-type>
                                <option value="">Todos</option>
                                <?php foreach ($types as $slug => $label) : ?>
                                    <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                </div>

                <div class="seo-dependiente-trainer__runbar">
                    <button type="button" class="button button-primary" data-trainer-run-list>Lanzar lista</button>
                    <button type="button" class="button" data-trainer-run-selected>Lanzar seleccionadas</button>
                    <button type="button" class="button-link-delete" data-trainer-delete>Eliminar seleccionadas</button>
                    <span data-trainer-run-status aria-live="polite"></span>
                </div>
                <div class="seo-dependiente-trainer__progress" data-trainer-progress hidden>
                    <div class="seo-dependiente-trainer__progress-bar" data-trainer-progress-bar></div>
                </div>

                <div class="seo-dependiente-trainer__table-wrap">
                    <table class="widefat striped seo-dependiente-trainer__questions">
                        <thead>
                            <tr>
                                <td class="check-column"><input type="checkbox" data-trainer-select-all aria-label="Seleccionar preguntas visibles"></td>
                                <th>Tipo</th>
                                <th>Modo</th>
                                <th>Pregunta</th>
                            </tr>
                        </thead>
                        <tbody data-trainer-question-body>
                        <?php if (!$questions) : ?>
                            <tr data-trainer-empty><td colspan="4">Aún no hay preguntas. Pega una lista arriba para empezar.</td></tr>
                        <?php else : ?>
                            <?php foreach ($questions as $question) : ?>
                                <tr data-trainer-question-row data-question-id="<?php echo esc_attr(absint($question['id'])); ?>" data-question-type="<?php echo esc_attr((string) $question['question_type']); ?>">
                                    <th class="check-column"><input type="checkbox" value="<?php echo esc_attr(absint($question['id'])); ?>" data-trainer-question-check></th>
                                    <td><span class="seo-dependiente-trainer__type"><?php echo esc_html(self::type_label((string) $question['question_type'])); ?></span></td>
                                    <td><code><?php echo esc_html((string) $question['mode']); ?></code></td>
                                    <td><?php echo esc_html((string) $question['question']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="seo-dependiente-trainer__results" data-trainer-results-section>
                <div class="seo-dependiente-trainer__section-head">
                    <div>
                        <h2>Resultados de la última ejecución</h2>
                        <p class="description" data-trainer-batch-label><?php echo $last_batch ? 'Lote ' . esc_html(substr($last_batch, 0, 8)) : 'Todavía no se ha ejecutado ningún lote.'; ?></p>
                    </div>
                    <div class="seo-dependiente-trainer__actions">
                        <button type="button" class="button" data-trainer-export-json <?php disabled(!$last_batch); ?>>Descargar JSON</button>
                        <?php if ($last_batch) : ?>
                            <button type="button" class="button-link-delete" data-trainer-clear-runs>Borrar historial del entrenador</button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php self::render_kpis($summary); ?>
                <p class="description seo-dependiente-trainer__kpi-note">“Contestada” significa que el motor respondió sin error. “Sin resultados” significa que la respuesta no devolvió ningún producto.</p>
                <div data-trainer-breakdown><?php self::render_breakdown($breakdown); ?></div>
                <div class="seo-dependiente-trainer__table-wrap" data-trainer-run-table>
                    <?php self::render_runs_table($runs); ?>
                </div>
            </section>

            <?php if ($history) : ?>
                <section class="postbox seo-dependiente-admin__box seo-dependiente-trainer__history">
                    <h2 class="seo-dependiente-admin__box-title">Últimos lotes</h2>
                    <table class="widefat striped">
                        <thead><tr><th>Fecha</th><th>Lote</th><th>Lanzadas</th><th>Contestadas</th><th>Con resultados</th><th>Productos devueltos</th></tr></thead>
                        <tbody>
                        <?php foreach ($history as $item) : ?>
                            <tr>
                                <td><?php echo esc_html((string) $item['created_at']); ?></td>
                                <td><code><?php echo esc_html(substr((string) $item['batch_uuid'], 0, 8)); ?></code></td>
                                <td><?php echo esc_html(number_format_i18n(absint($item['launched']))); ?></td>
                                <td><?php echo esc_html(number_format_i18n(absint($item['answered']))); ?></td>
                                <td><?php echo esc_html(number_format_i18n(absint($item['with_results']))); ?></td>
                                <td><?php echo esc_html(number_format_i18n(absint($item['returned_results']))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function ajax_add_questions() {
        self::guard_ajax();
        if (!self::ensure_ready()) {
            wp_send_json_error(array('message' => 'No se han podido preparar las tablas del Entrenador.'), 500);
        }

        $raw = isset($_POST['raw']) ? wp_unslash((string) $_POST['raw']) : '';
        $default_type = self::sanitize_type($_POST['default_type'] ?? 'other');
        $default_mode = self::sanitize_mode($_POST['default_mode'] ?? 'need');
        $parsed = self::parse_questions($raw, $default_type, $default_mode);
        if (!$parsed) {
            wp_send_json_error(array('message' => 'No hay preguntas válidas que añadir.'), 400);
        }

        global $wpdb;
        $table = self::questions_table();
        $added = 0;
        $existing = 0;
        foreach ($parsed as $item) {
            $hash = self::question_hash($item['question_type'], $item['mode'], $item['question']);
            $found = absint($wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE question_hash = %s LIMIT 1", $hash)));
            if ($found) {
                $wpdb->update($table, array('enabled' => 1, 'updated_at' => current_time('mysql')), array('id' => $found));
                $existing++;
                continue;
            }
            $ok = $wpdb->insert($table, array(
                'question_hash' => $hash,
                'question_type' => $item['question_type'],
                'mode'          => $item['mode'],
                'question'      => $item['question'],
                'enabled'       => 1,
                'created_at'    => current_time('mysql'),
                'updated_at'    => current_time('mysql'),
            ));
            if (false !== $ok) {
                $added++;
            }
        }

        wp_send_json_success(array(
            'added'    => $added,
            'existing' => $existing,
            'total'    => self::question_count(),
            'message'  => sprintf('%d nuevas · %d ya existentes.', $added, $existing),
        ));
    }

    public static function ajax_delete_questions() {
        self::guard_ajax();
        if (!self::ensure_ready()) {
            wp_send_json_error(array('message' => 'Entrenador no disponible.'), 500);
        }
        $ids = self::sanitize_ids($_POST['ids'] ?? array(), 1000);
        if (!$ids) {
            wp_send_json_error(array('message' => 'Selecciona al menos una pregunta.'), 400);
        }
        global $wpdb;
        $table = self::questions_table();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids));
        wp_send_json_success(array(
            'deleted' => max(0, (int) $deleted),
            'total'   => self::question_count(),
        ));
    }

    public static function ajax_clear_runs() {
        self::guard_ajax();
        if (!self::ensure_ready()) {
            wp_send_json_error(array('message' => 'Entrenador no disponible.'), 500);
        }
        global $wpdb;
        $wpdb->query('DELETE FROM ' . self::runs_table());
        wp_send_json_success(array('message' => 'Historial del Entrenador borrado.'));
    }

    public static function ajax_export_json() {
        self::guard_ajax();
        if (!self::ensure_ready()) {
            wp_send_json_error(array('message' => 'Entrenador no disponible.'), 500);
        }

        $batch_uuid = self::sanitize_uuid($_POST['batch_uuid'] ?? '');
        if (!$batch_uuid) {
            $batch_uuid = self::last_batch_uuid();
        }
        if (!$batch_uuid) {
            wp_send_json_error(array('message' => 'Todavía no hay ninguna ejecución para exportar.'), 404);
        }

        $raw_runs = self::batch_runs_for_export($batch_uuid);
        if (!$raw_runs) {
            wp_send_json_error(array('message' => 'No se ha encontrado el lote solicitado.'), 404);
        }

        $runs = array();
        foreach ($raw_runs as $raw_run) {
            $run = self::present_run($raw_run);
            $run['question_type_label'] = self::type_label((string) $run['question_type']);
            $run['status_label'] = 'error' === $run['status']
                ? 'Error'
                : (0 === absint($run['returned_count']) ? 'Contestada · sin resultados' : 'Contestada');
            $runs[] = $run;
        }

        $breakdown = array();
        foreach (self::batch_breakdown($batch_uuid) as $item) {
            $item = (array) $item;
            $item['question_type_label'] = self::type_label((string) ($item['question_type'] ?? 'other'));
            foreach (array('launched', 'answered', 'with_results', 'without_results', 'returned_results') as $key) {
                $item[$key] = absint($item[$key] ?? 0);
            }
            $breakdown[] = $item;
        }

        $document = array(
            'schema'             => 'seo-dependiente-entrenador-export',
            'schema_version'     => 1,
            'generated_at'       => current_time('mysql'),
            'dependiente_version'=> defined('SEO_DEPENDIENTE_VERSION') ? SEO_DEPENDIENTE_VERSION : '',
            'batch_uuid'         => $batch_uuid,
            'summary'            => self::batch_summary($batch_uuid),
            'breakdown'          => $breakdown,
            'notes'              => array(
                'answered_definition'          => 'El motor respondió sin error, aunque no devolviera productos.',
                'with_results_definition'       => 'La respuesta devolvió al menos un producto.',
                'returned_results_definition'   => 'Suma de productos incluidos realmente en las respuestas del API.',
                'result_count_definition'        => 'Coincidencias totales informadas por el buscador para cada pregunta.',
                'top_results_stored_per_question'=> 8,
                'learning_isolated'              => true,
                'customer_search_log_written'    => false,
                'external_provider_calls_executed'=> false,
            ),
            'runs'               => $runs,
        );

        wp_send_json_success(array(
            'filename' => 'dependiente-entrenador-' . current_time('Ymd-His') . '-' . substr($batch_uuid, 0, 8) . '.json',
            'document' => $document,
        ));
    }

    public static function ajax_run_batch() {
        self::guard_ajax();
        if (!self::ensure_ready()) {
            wp_send_json_error(array('message' => 'Entrenador no disponible.'), 500);
        }
        if (!class_exists('SEO_Dependiente_API')) {
            wp_send_json_error(array('message' => 'El API de Dependiente no está disponible.'), 500);
        }

        $batch_uuid = self::sanitize_uuid($_POST['batch_uuid'] ?? '');
        if (!$batch_uuid) {
            $batch_uuid = wp_generate_uuid4();
        }
        $scope = sanitize_key((string) ($_POST['scope'] ?? 'all'));
        if (!in_array($scope, array('all', 'type', 'ids'), true)) {
            $scope = 'all';
        }
        $type = self::sanitize_type($_POST['type'] ?? '');
        $offset = max(0, absint($_POST['offset'] ?? 0));
        $batch_limit = self::sanitize_batch_size($_POST['batch_size'] ?? self::AJAX_BATCH_INITIAL);
        $ids = self::sanitize_ids($_POST['ids'] ?? array(), $batch_limit);

        $total_scope = self::scope_count($scope, $type, $ids);
        $questions = self::scope_questions($scope, $type, $offset, $ids, $batch_limit);
        if (!$questions) {
            wp_send_json_success(array(
                'batch_uuid'  => $batch_uuid,
                'processed'   => 0,
                'batch_size'  => $batch_limit,
                'next_offset' => $offset,
                'total_scope' => $total_scope,
                'done'        => true,
                'summary'     => self::batch_summary($batch_uuid),
                'breakdown'   => self::batch_breakdown($batch_uuid),
                'rows'        => array(),
            ));
        }

        $run_rows = array();
        foreach ($questions as $question) {
            $run_id = self::run_question($question, $batch_uuid);
            if ($run_id) {
                $row = self::run_by_id($run_id);
                if ($row) {
                    $run_rows[] = self::present_run($row);
                }
            }
        }

        $processed = count($questions);
        $next_offset = $offset + $processed;
        $done = 'ids' === $scope ? true : ($next_offset >= $total_scope || $processed < $batch_limit);

        wp_send_json_success(array(
            'batch_uuid'  => $batch_uuid,
            'processed'   => $processed,
            'batch_size'  => $batch_limit,
            'next_offset' => $next_offset,
            'total_scope' => $total_scope,
            'done'        => $done,
            'summary'     => self::batch_summary($batch_uuid),
            'breakdown'   => self::batch_breakdown($batch_uuid),
            'rows'        => $run_rows,
        ));
    }

    /**
     * Filtro temporal usado solo mientras el Entrenador llama al API.
     * La busqueda se ejecuta completa, pero no se escribe en el log de clientes.
     */
    public static function skip_customer_search_log($should_log) {
        return false;
    }

    private static function run_question($question, $batch_uuid) {
        global $wpdb;

        // Una peticion HTTP puede terminar en el servidor aunque el navegador no
        // reciba la respuesta (Failed to fetch, 502, timeout...). Al reintentar el
        // mismo lote no ejecutamos dos veces una pregunta ya guardada.
        $question_id = absint($question['id'] ?? 0);
        if ($question_id && $batch_uuid) {
            $existing_run_id = absint($wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . self::runs_table() . ' WHERE batch_uuid = %s AND question_id = %d ORDER BY id ASC LIMIT 1',
                $batch_uuid,
                $question_id
            )));
            if ($existing_run_id) {
                return $existing_run_id;
            }
        }

        $request = new WP_REST_Request('POST', '/seo-taxonomy/v1/search');
        $request->set_body_params(array(
            'q'          => (string) ($question['question'] ?? ''),
            'mode'       => self::sanitize_mode($question['mode'] ?? 'need'),
            'page'       => 1,
            'orderby'    => 'relevance',
            'session_id' => 'trainer:' . $batch_uuid,
        ));

        $trainer_started_at = microtime(true);
        add_filter('seo_dependiente_should_log_search', array(__CLASS__, 'skip_customer_search_log'), 999, 4);
        try {
            $response = SEO_Dependiente_API::search($request);
        } catch (Throwable $error) {
            $response = new WP_Error('seo_dependiente_trainer_exception', $error->getMessage());
        }
        remove_filter('seo_dependiente_should_log_search', array(__CLASS__, 'skip_customer_search_log'), 999);

        $data = array();
        $status = 'answered';
        $error_message = null;
        if (is_wp_error($response)) {
            $status = 'error';
            $error_message = $response->get_error_message();
        } elseif ($response instanceof WP_REST_Response) {
            $data = (array) $response->get_data();
        } elseif (is_array($response)) {
            $data = $response;
        } else {
            $status = 'error';
            $error_message = 'Respuesta no reconocida del API de Dependiente.';
        }

        $all_results = array_values((array) ($data['results'] ?? array()));
        $results = array_slice($all_results, 0, 8);
        $compact_results = array();
        foreach ($results as $position => $result) {
            if (!is_array($result)) {
                continue;
            }
            $compact_results[] = array(
                'id'       => absint($result['id'] ?? 0),
                'title'    => sanitize_text_field((string) ($result['title'] ?? '')),
                'score'    => isset($result['score']) ? round((float) $result['score'], 4) : null,
                'position' => $position + 1,
                'reasons'  => array_values(array_slice(array_map('sanitize_text_field', (array) ($result['reasons'] ?? array())), 0, 3)),
            );
        }

        $semantic = is_array($data['semantic'] ?? null) ? $data['semantic'] : array();
        $meta = array(
            'clarification' => is_array($data['clarification'] ?? null) ? $data['clarification'] : null,
            'semantic'      => array(
                'normalized' => sanitize_text_field((string) ($semantic['normalized'] ?? '')),
                'concepts'   => is_array($semantic['concepts'] ?? null) ? $semantic['concepts'] : array(),
                'groups'     => array_values(array_slice((array) ($semantic['groups'] ?? array()), 0, 12)),
            ),
            'external'      => is_array($data['external_fallback'] ?? null) ? array(
                'provider'    => sanitize_key((string) ($data['external_fallback']['provider'] ?? '')),
                'should_load' => !empty($data['external_fallback']['should_load']),
                'reason'      => sanitize_text_field((string) ($data['external_fallback']['reason'] ?? '')),
            ) : array(),
        );

        $inserted = $wpdb->insert(self::runs_table(), array(
            'batch_uuid'      => $batch_uuid,
            'question_id'     => absint($question['id'] ?? 0) ?: null,
            'question_type'   => self::sanitize_type($question['question_type'] ?? 'other'),
            'mode'            => self::sanitize_mode($question['mode'] ?? 'need'),
            'question'        => sanitize_text_field((string) ($question['question'] ?? '')),
            'status'          => $status,
            'result_count'    => max(0, absint($data['total'] ?? 0)),
            'returned_count'  => count($all_results),
            'search_uuid'     => self::sanitize_uuid($data['search_id'] ?? '') ?: null,
            'search_strategy' => sanitize_key((string) ($data['search_strategy'] ?? '')) ?: null,
            'execution_ms'    => round(max(0, (microtime(true) - $trainer_started_at) * 1000), 3),
            'top_results'     => wp_json_encode($compact_results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'response_meta'   => wp_json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_message'   => $error_message ? sanitize_text_field($error_message) : null,
            'created_at'      => current_time('mysql'),
        ));

        return false === $inserted ? 0 : absint($wpdb->insert_id);
    }

    private static function render_kpis($summary) {
        $summary = wp_parse_args((array) $summary, self::empty_summary());
        ?>
        <div class="seo-dependiente-trainer__kpis" data-trainer-kpis>
            <?php self::kpi('Preguntas lanzadas', $summary['launched'], 'launched'); ?>
            <?php self::kpi('Contestadas', $summary['answered'], 'answered'); ?>
            <?php self::kpi('Con resultados', $summary['with_results'], 'with_results'); ?>
            <?php self::kpi('Sin resultados', $summary['without_results'], 'without_results'); ?>
            <?php self::kpi('Resultados devueltos', $summary['returned_results'], 'returned_results'); ?>
        </div>
        <?php
    }

    private static function kpi($label, $value, $key) {
        ?>
        <div class="seo-dependiente-trainer__kpi">
            <span><?php echo esc_html($label); ?></span>
            <strong data-trainer-kpi="<?php echo esc_attr($key); ?>"><?php echo esc_html(number_format_i18n(absint($value))); ?></strong>
        </div>
        <?php
    }

    private static function render_breakdown($items) {
        if (!$items) {
            echo '<p class="description">El desglose por tipo aparecerá después de ejecutar preguntas.</p>';
            return;
        }
        ?>
        <div class="seo-dependiente-trainer__breakdown">
            <h3>Por tipo de pregunta</h3>
            <table class="widefat striped">
                <thead><tr><th>Tipo</th><th>Lanzadas</th><th>Contestadas</th><th>Con resultados</th><th>Sin resultados</th><th>Resultados devueltos</th></tr></thead>
                <tbody>
                <?php foreach ($items as $item) : ?>
                    <tr>
                        <td><?php echo esc_html(self::type_label((string) $item['question_type'])); ?></td>
                        <td><?php echo esc_html(number_format_i18n(absint($item['launched']))); ?></td>
                        <td><?php echo esc_html(number_format_i18n(absint($item['answered']))); ?></td>
                        <td><?php echo esc_html(number_format_i18n(absint($item['with_results']))); ?></td>
                        <td><?php echo esc_html(number_format_i18n(absint($item['without_results']))); ?></td>
                        <td><?php echo esc_html(number_format_i18n(absint($item['returned_results']))); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function render_runs_table($rows) {
        ?>
        <table class="widefat striped seo-dependiente-trainer__runs">
            <thead><tr><th>Tipo</th><th>Pregunta</th><th>Estado</th><th>Resultados</th><th>Respuesta del Dependiente</th></tr></thead>
            <tbody data-trainer-run-body>
                <?php if (!$rows) : ?>
                    <tr data-trainer-run-empty><td colspan="5">No hay respuestas del Entrenador todavía.</td></tr>
                <?php else : ?>
                    <?php foreach ($rows as $row) : self::render_run_row(self::present_run($row)); endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private static function render_run_row($row) {
        $results = (array) ($row['top_results'] ?? array());
        $meta = (array) ($row['response_meta'] ?? array());
        $clarification = is_array($meta['clarification'] ?? null) ? $meta['clarification'] : array();
        ?>
        <tr>
            <td><span class="seo-dependiente-trainer__type"><?php echo esc_html(self::type_label((string) $row['question_type'])); ?></span><br><code><?php echo esc_html((string) $row['mode']); ?></code></td>
            <td><strong><?php echo esc_html((string) $row['question']); ?></strong><?php if (!empty($row['search_strategy'])) : ?><div class="description">Estrategia: <code><?php echo esc_html((string) $row['search_strategy']); ?></code></div><?php endif; ?></td>
            <td>
                <?php if ('error' === $row['status']) : ?>
                    <span class="seo-dependiente-trainer__status is-error">Error</span>
                    <?php if (!empty($row['error_message'])) : ?><div class="description"><?php echo esc_html((string) $row['error_message']); ?></div><?php endif; ?>
                <?php elseif (0 === absint($row['returned_count'])) : ?>
                    <span class="seo-dependiente-trainer__status is-empty">Contestada · sin resultados</span>
                <?php else : ?>
                    <span class="seo-dependiente-trainer__status is-ok">Contestada</span>
                <?php endif; ?>
            </td>
            <td><strong><?php echo esc_html(number_format_i18n(absint($row['returned_count']))); ?></strong> devueltos<br><span class="description"><?php echo esc_html(number_format_i18n(absint($row['result_count']))); ?> coincidencias</span></td>
            <td>
                <?php if ($results) : ?>
                    <ol class="seo-dependiente-trainer__answer-list">
                    <?php foreach ($results as $result) : ?>
                        <li>
                            <strong><?php echo esc_html((string) ($result['title'] ?? '')); ?></strong>
                            <?php if (!empty($result['reasons'])) : ?><span><?php echo esc_html(implode(' · ', (array) $result['reasons'])); ?></span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    </ol>
                <?php elseif ('error' !== $row['status']) : ?>
                    <span class="description">No devolvió productos.</span>
                <?php endif; ?>
                <?php if (!empty($clarification['question'])) : ?>
                    <div class="seo-dependiente-trainer__clarification"><strong>Aclaración:</strong> <?php echo esc_html((string) $clarification['question']); ?></div>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    public static function run_rows_html($rows) {
        ob_start();
        foreach ((array) $rows as $row) {
            self::render_run_row($row);
        }
        return (string) ob_get_clean();
    }

    public static function breakdown_html($items) {
        ob_start();
        self::render_breakdown($items);
        return (string) ob_get_clean();
    }

    private static function questions($type = '', $limit = 1000) {
        global $wpdb;
        $table = self::questions_table();
        $limit = min(5000, max(1, absint($limit)));
        if ($type) {
            return (array) $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE enabled = 1 AND question_type = %s ORDER BY id ASC LIMIT %d",
                self::sanitize_type($type),
                $limit
            ), ARRAY_A);
        }
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE enabled = 1 ORDER BY id ASC LIMIT %d",
            $limit
        ), ARRAY_A);
    }

    private static function question_count($type = '') {
        global $wpdb;
        $table = self::questions_table();
        if ($type) {
            return absint($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE enabled = 1 AND question_type = %s", self::sanitize_type($type))));
        }
        return absint($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE enabled = 1"));
    }

    private static function scope_count($scope, $type, $ids) {
        if ('ids' === $scope) {
            return count($ids);
        }
        return self::question_count('type' === $scope ? $type : '');
    }

    private static function scope_questions($scope, $type, $offset, $ids, $limit) {
        global $wpdb;
        $table = self::questions_table();
        $limit = min(self::AJAX_BATCH_LIMIT, max(1, absint($limit)));
        if ('ids' === $scope) {
            if (!$ids) {
                return array();
            }
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            return (array) $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE enabled = 1 AND id IN ({$placeholders}) ORDER BY id ASC",
                $ids
            ), ARRAY_A);
        }
        if ('type' === $scope) {
            return (array) $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE enabled = 1 AND question_type = %s ORDER BY id ASC LIMIT %d OFFSET %d",
                self::sanitize_type($type),
                $limit,
                $offset
            ), ARRAY_A);
        }
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE enabled = 1 ORDER BY id ASC LIMIT %d OFFSET %d",
            $limit,
            $offset
        ), ARRAY_A);
    }

    private static function run_by_id($run_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::runs_table() . ' WHERE id = %d LIMIT 1', absint($run_id)), ARRAY_A);
    }

    private static function batch_runs($batch_uuid, $limit = 250) {
        global $wpdb;
        $limit = min(1000, max(1, absint($limit)));
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::runs_table() . ' WHERE batch_uuid = %s ORDER BY id ASC LIMIT %d',
            $batch_uuid,
            $limit
        ), ARRAY_A);
    }

    private static function batch_runs_for_export($batch_uuid) {
        global $wpdb;
        if (!$batch_uuid) {
            return array();
        }
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::runs_table() . ' WHERE batch_uuid = %s ORDER BY id ASC',
            $batch_uuid
        ), ARRAY_A);
    }

    private static function batch_summary($batch_uuid) {
        global $wpdb;
        if (!$batch_uuid) {
            return self::empty_summary();
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) AS launched,
                SUM(status = 'answered') AS answered,
                SUM(status = 'answered' AND returned_count > 0) AS with_results,
                SUM(status = 'answered' AND returned_count = 0) AS without_results,
                SUM(status = 'error') AS errors,
                COALESCE(SUM(returned_count), 0) AS returned_results
             FROM " . self::runs_table() . " WHERE batch_uuid = %s",
            $batch_uuid
        ), ARRAY_A);
        return wp_parse_args(is_array($row) ? array_map('absint', $row) : array(), self::empty_summary());
    }

    private static function batch_breakdown($batch_uuid) {
        global $wpdb;
        if (!$batch_uuid) {
            return array();
        }
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT question_type,
                COUNT(*) AS launched,
                SUM(status = 'answered') AS answered,
                SUM(status = 'answered' AND returned_count > 0) AS with_results,
                SUM(status = 'answered' AND returned_count = 0) AS without_results,
                COALESCE(SUM(returned_count), 0) AS returned_results
             FROM " . self::runs_table() . "
             WHERE batch_uuid = %s
             GROUP BY question_type
             ORDER BY launched DESC, question_type ASC",
            $batch_uuid
        ), ARRAY_A);
    }

    private static function batch_history($limit = 8) {
        global $wpdb;
        $limit = min(30, max(1, absint($limit)));
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT batch_uuid, MAX(created_at) AS created_at,
                COUNT(*) AS launched,
                SUM(status = 'answered') AS answered,
                SUM(status = 'answered' AND returned_count > 0) AS with_results,
                COALESCE(SUM(returned_count), 0) AS returned_results
             FROM " . self::runs_table() . "
             GROUP BY batch_uuid
             ORDER BY MAX(id) DESC
             LIMIT %d",
            $limit
        ), ARRAY_A);
    }

    private static function last_batch_uuid() {
        global $wpdb;
        return (string) $wpdb->get_var('SELECT batch_uuid FROM ' . self::runs_table() . ' ORDER BY id DESC LIMIT 1');
    }

    private static function present_run($row) {
        return array(
            'id'              => absint($row['id'] ?? 0),
            'question_type'   => self::sanitize_type($row['question_type'] ?? 'other'),
            'mode'            => self::sanitize_mode($row['mode'] ?? 'need'),
            'question'        => (string) ($row['question'] ?? ''),
            'status'          => sanitize_key((string) ($row['status'] ?? 'answered')),
            'result_count'    => absint($row['result_count'] ?? 0),
            'returned_count'  => absint($row['returned_count'] ?? 0),
            'search_uuid'     => self::sanitize_uuid($row['search_uuid'] ?? ''),
            'search_strategy' => sanitize_key((string) ($row['search_strategy'] ?? '')),
            'execution_ms'    => isset($row['execution_ms']) ? round((float) $row['execution_ms'], 3) : null,
            'top_results'     => self::decode_json($row['top_results'] ?? ''),
            'response_meta'   => self::decode_json($row['response_meta'] ?? ''),
            'error_message'   => (string) ($row['error_message'] ?? ''),
            'created_at'      => (string) ($row['created_at'] ?? ''),
        );
    }

    private static function parse_questions($raw, $default_type, $default_mode) {
        $raw = str_replace(array("\r\n", "\r"), "\n", (string) $raw);
        $lines = array_slice(explode("\n", $raw), 0, 2000);
        $out = array();
        foreach ($lines as $line) {
            $line = trim(wp_strip_all_tags((string) $line));
            if ('' === $line) {
                continue;
            }
            $parts = preg_split('/\s*(?:\||\t)\s*/u', $line, 3);
            $type = $default_type;
            $mode = $default_mode;
            $question = $line;

            if (is_array($parts) && count($parts) >= 2 && self::is_known_type($parts[0])) {
                $type = self::sanitize_type($parts[0]);
                if (3 === count($parts) && self::is_known_mode($parts[1])) {
                    $mode = self::sanitize_mode($parts[1]);
                    $question = $parts[2];
                } else {
                    $question = implode(' | ', array_slice($parts, 1));
                }
            }

            $question = sanitize_text_field((string) $question);
            if (function_exists('mb_substr')) {
                $question = mb_substr($question, 0, 180, 'UTF-8');
            } else {
                $question = substr($question, 0, 180);
            }
            if ('' === trim($question)) {
                continue;
            }
            $key = self::question_hash($type, $mode, $question);
            $out[$key] = array('question_type' => $type, 'mode' => $mode, 'question' => $question);
        }
        return array_values($out);
    }

    private static function question_hash($type, $mode, $question) {
        $normalized = remove_accents(wp_strip_all_tags((string) $question));
        $normalized = strtolower(preg_replace('/\s+/u', ' ', trim($normalized)));
        return hash('sha256', self::sanitize_type($type) . '|' . self::sanitize_mode($mode) . '|' . $normalized);
    }

    private static function question_types() {
        return array(
            'need'          => 'Necesidad',
            'product'       => 'Producto concreto',
            'compatibility' => 'Compatibilidad',
            'symptom'       => 'Síntoma',
            'use_case'      => 'Uso / tarea',
            'comparison'    => 'Comparación',
            'colloquial'    => 'Lenguaje coloquial',
            'typo'          => 'Error ortográfico',
            'ambiguous'     => 'Ambigua',
            'other'         => 'Otra',
        );
    }

    private static function type_label($type) {
        $types = self::question_types();
        $type = self::sanitize_type($type);
        return (string) ($types[$type] ?? $types['other']);
    }

    private static function sanitize_type($type) {
        $type = sanitize_title(remove_accents((string) $type));
        $aliases = array(
            'necesidad' => 'need',
            'need' => 'need',
            'use_case' => 'use_case',
            'error_ortografico' => 'typo',
            'producto' => 'product',
            'producto-concreto' => 'product',
            'product' => 'product',
            'compatibilidad' => 'compatibility',
            'compatibility' => 'compatibility',
            'sintoma' => 'symptom',
            'symptom' => 'symptom',
            'uso' => 'use_case',
            'tarea' => 'use_case',
            'uso-tarea' => 'use_case',
            'use-case' => 'use_case',
            'comparacion' => 'comparison',
            'comparison' => 'comparison',
            'coloquial' => 'colloquial',
            'lenguaje-coloquial' => 'colloquial',
            'colloquial' => 'colloquial',
            'error-ortografico' => 'typo',
            'ortografia' => 'typo',
            'typo' => 'typo',
            'ambigua' => 'ambiguous',
            'ambiguo' => 'ambiguous',
            'ambiguous' => 'ambiguous',
            'otra' => 'other',
            'otro' => 'other',
            'other' => 'other',
        );
        return (string) ($aliases[$type] ?? (isset(self::question_types()[$type]) ? $type : 'other'));
    }

    private static function is_known_type($type) {
        $slug = sanitize_title(remove_accents((string) $type));
        $normalized = self::sanitize_type($type);
        return 'other' !== $normalized || in_array($slug, array('other', 'otra', 'otro'), true);
    }

    private static function sanitize_mode($mode) {
        $mode = sanitize_key((string) $mode);
        return in_array($mode, array('need', 'product', 'tool', 'compare'), true) ? $mode : 'need';
    }

    private static function is_known_mode($mode) {
        return in_array(sanitize_key((string) $mode), array('need', 'product', 'tool', 'compare'), true);
    }

    private static function sanitize_batch_size($value) {
        $value = absint($value);
        if ($value < self::AJAX_BATCH_MIN) {
            $value = self::AJAX_BATCH_INITIAL;
        }
        return min(self::AJAX_BATCH_LIMIT, max(self::AJAX_BATCH_MIN, $value));
    }

    private static function sanitize_ids($raw, $limit) {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw);
        }
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $raw))));
        return array_slice($ids, 0, max(1, absint($limit)));
    }

    private static function sanitize_uuid($value) {
        $value = sanitize_text_field((string) $value);
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $value) ? strtolower($value) : '';
    }

    private static function decode_json($value) {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : array();
    }

    private static function empty_summary() {
        return array(
            'launched' => 0,
            'answered' => 0,
            'with_results' => 0,
            'without_results' => 0,
            'errors' => 0,
            'returned_results' => 0,
        );
    }

    private static function table_exists($table) {
        if (class_exists('SEO_Dependiente_Index')) {
            return SEO_Dependiente_Index::table_exists($table);
        }
        global $wpdb;
        return $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    }

    private static function guard_ajax() {
        check_ajax_referer('seo_dependiente_entrenador', 'nonce');
        if (!current_user_can(self::capability())) {
            wp_send_json_error(array('message' => 'No tienes permisos para usar el Entrenador.'), 403);
        }
    }

    private static function capability() {
        return class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options';
    }
}

SEO_Dependiente_Entrenador::init();
