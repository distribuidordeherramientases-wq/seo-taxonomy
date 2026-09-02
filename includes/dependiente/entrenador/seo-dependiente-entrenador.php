<?php

defined('ABSPATH') || exit;

/**
 * Academia guiada de Dependiente.
 *
 * Sustituye el antiguo banco libre de preguntas por un curriculo secuencial.
 * Cada leccion se prepara desde fuentes canonicas del catalogo, se divide en
 * modulos y se evalua contra el mismo motor que usa el cliente. Las consultas
 * de Academia nunca se escriben en el log de clientes ni alimentan el
 * aprendizaje observacional.
 */
final class SEO_Dependiente_Entrenador {
    const DB_VERSION = '2026-09-02.2';
    const PREPARE_BATCH_LIMIT = 100;
    const AJAX_BATCH_MIN = 1;
    const AJAX_BATCH_INITIAL = 1;
    const AJAX_BATCH_LIMIT = 4;
    const RECENT_RUN_LIMIT = 120;
    const KNOWLEDGE_SNAPSHOT_OPTION = 'seo_dependiente_knowledge_snapshot';

    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'), 20);
        add_action('wp_ajax_seo_dependiente_entrenador_prepare_lesson', array(__CLASS__, 'ajax_prepare_lesson'));
        add_action('wp_ajax_seo_dependiente_entrenador_run_module', array(__CLASS__, 'ajax_run_module'));
        add_action('wp_ajax_seo_dependiente_entrenador_export_lesson', array(__CLASS__, 'ajax_export_lesson'));
    }

    public static function lessons_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_dependiente_trainer_lessons';
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
        $lessons = self::lessons_table();
        $questions = self::questions_table();
        $runs = self::runs_table();

        $lessons_sql = "CREATE TABLE {$lessons} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lesson_key VARCHAR(60) NOT NULL,
            lesson_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            title VARCHAR(191) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'locked',
            module_size SMALLINT UNSIGNED NOT NULL DEFAULT 25,
            module_count INT UNSIGNED NOT NULL DEFAULT 0,
            item_count INT UNSIGNED NOT NULL DEFAULT 0,
            completed_items INT UNSIGNED NOT NULL DEFAULT 0,
            prepare_offset INT UNSIGNED NOT NULL DEFAULT 0,
            prepare_total INT UNSIGNED NOT NULL DEFAULT 0,
            snapshot_before INT UNSIGNED NOT NULL DEFAULT 0,
            snapshot_after INT UNSIGNED NOT NULL DEFAULT 0,
            source_signature CHAR(64) NULL,
            metadata LONGTEXT NULL,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY lesson_key (lesson_key),
            KEY idx_order_status (lesson_order, status)
        ) {$charset_collate};";

        $questions_sql = "CREATE TABLE {$questions} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            question_hash CHAR(64) NOT NULL,
            lesson_key VARCHAR(60) NOT NULL DEFAULT '',
            lesson_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            module_no INT UNSIGNED NOT NULL DEFAULT 0,
            sequence_no INT UNSIGNED NOT NULL DEFAULT 0,
            source_type VARCHAR(40) NOT NULL DEFAULT '',
            source_id BIGINT UNSIGNED NULL,
            source_key VARCHAR(191) NOT NULL DEFAULT '',
            question_type VARCHAR(40) NOT NULL DEFAULT 'other',
            mode VARCHAR(20) NOT NULL DEFAULT 'need',
            question VARCHAR(500) NOT NULL,
            expected_json LONGTEXT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY question_hash (question_hash),
            KEY idx_lesson_module (lesson_key, module_no, sequence_no),
            KEY idx_source (source_type, source_id),
            KEY idx_enabled_id (enabled, id)
        ) {$charset_collate};";

        $runs_sql = "CREATE TABLE {$runs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_uuid CHAR(36) NOT NULL,
            lesson_key VARCHAR(60) NOT NULL DEFAULT '',
            module_no INT UNSIGNED NOT NULL DEFAULT 0,
            question_id BIGINT UNSIGNED NULL,
            source_type VARCHAR(40) NOT NULL DEFAULT '',
            source_id BIGINT UNSIGNED NULL,
            question_type VARCHAR(40) NOT NULL DEFAULT 'other',
            mode VARCHAR(20) NOT NULL DEFAULT 'need',
            question VARCHAR(500) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'answered',
            result_count INT UNSIGNED NOT NULL DEFAULT 0,
            returned_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            search_uuid CHAR(36) NULL,
            search_strategy VARCHAR(40) NULL,
            execution_ms DECIMAL(10,3) NULL,
            evaluation_status VARCHAR(24) NULL,
            evaluation_score DECIMAL(6,4) NULL,
            evaluation_json LONGTEXT NULL,
            top_results LONGTEXT NULL,
            response_meta LONGTEXT NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_lesson_question (lesson_key, question_id),
            KEY idx_lesson_module (lesson_key, module_no, id),
            KEY idx_batch_id (batch_uuid, id),
            KEY idx_status_created (status, created_at),
            KEY idx_created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($lessons_sql);
        dbDelta($questions_sql);
        dbDelta($runs_sql);

        // Las preguntas del banco libre anterior quedan archivadas. La Academia
        // solo usa filas con lesson_key, por lo que no pueden volver a lanzarse.
        if (self::table_exists($questions)) {
            $wpdb->query("UPDATE {$questions} SET enabled = 0 WHERE lesson_key = '' OR lesson_key IS NULL");
        }

        update_option('seo_dependiente_entrenador_db_version', self::DB_VERSION, false);
        self::sync_lessons();
    }

    public static function ensure_ready() {
        $version = (string) get_option('seo_dependiente_entrenador_db_version', '');
        if (
            self::DB_VERSION !== $version
            || !self::table_exists(self::lessons_table())
            || !self::table_exists(self::questions_table())
            || !self::table_exists(self::runs_table())
        ) {
            self::install();
        }

        $ready = self::table_exists(self::lessons_table())
            && self::table_exists(self::questions_table())
            && self::table_exists(self::runs_table());
        if ($ready) {
            self::sync_lessons();
        }
        return $ready;
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
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('seo_dependiente_entrenador'),
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
            echo '<div class="notice notice-error"><p>No se han podido preparar las tablas de la Academia.</p></div>';
            return;
        }

        $definitions = self::lesson_definitions();
        $lessons = self::lessons_by_key();
        $preflight = self::catalog_preflight();
        $current_key = self::current_lesson_key($lessons);
        $current = $current_key && isset($lessons[$current_key]) ? $lessons[$current_key] : null;
        $summary = $current ? self::lesson_summary($current_key) : self::empty_summary();
        $modules = $current ? self::module_progress($current_key) : array();
        $next_module = $current ? self::next_pending_module($current_key) : 0;
        $runs = $current ? self::recent_runs($current_key, self::RECENT_RUN_LIMIT) : array();
        $snapshot = absint(get_option(self::KNOWLEDGE_SNAPSHOT_OPTION, 0));
        ?>
        <div class="seo-dependiente-trainer" data-trainer-root data-current-lesson="<?php echo esc_attr($current_key); ?>" data-current-module="<?php echo esc_attr($next_module); ?>">
            <div class="seo-dependiente-trainer__intro">
                <div>
                    <h2>Academia del Dependiente</h2>
                    <p>Formación guiada y secuencial. La Academia toma el catálogo como fuente de verdad, prepara una lección, mide cómo responde el snapshot actual y, al completarla, incorpora el conocimiento canónico que usará la siguiente lección.</p>
                </div>
                <div class="seo-dependiente-trainer__intro-badges">
                    <span class="seo-dependiente-trainer__isolation">Consultas aisladas del aprendizaje de clientes</span>
                    <span class="seo-dependiente-trainer__snapshot">Snapshot <?php echo esc_html(number_format_i18n($snapshot)); ?></span>
                </div>
            </div>

            <section class="postbox seo-dependiente-admin__box seo-dependiente-trainer__preflight <?php echo $preflight['ready'] ? 'is-ready' : 'is-blocked'; ?>">
                <div>
                    <h2 class="seo-dependiente-admin__box-title">Antes de formar al Dependiente</h2>
                    <p><?php echo esc_html($preflight['message']); ?></p>
                </div>
                <div class="seo-dependiente-trainer__preflight-metrics">
                    <span><strong><?php echo esc_html(number_format_i18n($preflight['indexed'])); ?></strong> indexados</span>
                    <span><strong><?php echo esc_html(number_format_i18n($preflight['published'])); ?></strong> publicados</span>
                </div>
            </section>

            <div class="seo-dependiente-trainer__roadmap">
                <?php foreach ($definitions as $lesson_key => $definition) :
                    $row = isset($lessons[$lesson_key]) ? $lessons[$lesson_key] : array();
                    self::render_lesson_card($lesson_key, $definition, $row, $current_key);
                endforeach; ?>
                <article class="seo-dependiente-trainer__lesson is-future">
                    <div class="seo-dependiente-trainer__lesson-number">5+</div>
                    <div>
                        <h3>Comprensión de descripciones y lenguaje avanzado</h3>
                        <p>Se diseñará después de validar las cuatro lecciones básicas. Usará extractos y descripciones para crear preguntas por inferencia, no por copia literal.</p>
                        <span class="seo-dependiente-trainer__lesson-status">Próximamente</span>
                    </div>
                </article>
            </div>

            <?php if ($current && isset($definitions[$current_key])) :
                self::render_current_lesson($current_key, $definitions[$current_key], $current, $preflight, $modules, $next_module, $summary);
            endif; ?>

            <?php if ($current && absint($current['item_count'] ?? 0) > 0) : ?>
                <section class="seo-dependiente-trainer__results" data-trainer-results-section>
                    <div class="seo-dependiente-trainer__section-head">
                        <div>
                            <h2>Resultados de la lección actual</h2>
                            <p class="description">El acierto se calcula contra la verdad esperada del catálogo. Estos resultados miden el snapshot de entrada; al completar todos los módulos se incorpora el conocimiento canónico de la lección y se crea el siguiente snapshot.</p>
                        </div>
                        <button type="button" class="button" data-trainer-export-lesson>Descargar JSON de la lección</button>
                    </div>
                    <?php self::render_kpis($summary); ?>
                    <div class="seo-dependiente-trainer__table-wrap" data-trainer-run-table>
                        <?php self::render_runs_table($runs); ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="postbox seo-dependiente-admin__box seo-dependiente-trainer__manual-locked">
                <h2 class="seo-dependiente-admin__box-title">Entrenamiento libre bloqueado</h2>
                <p>El antiguo banco para pegar y lanzar preguntas arbitrarias está desactivado. Se recuperará más adelante como laboratorio avanzado, cuando el Dependiente haya terminado la formación básica y tengamos reglas de regresión suficientes.</p>
            </section>
        </div>
        <?php
    }

    public static function ajax_prepare_lesson() {
        self::guard_ajax();
        if (class_exists('SEO_Dependiente_Reset') && SEO_Dependiente_Reset::is_locked()) {
            wp_send_json_error(array('message' => 'El conocimiento se está reiniciando. Espera a que termine.'), 423);
        }
        if (!self::ensure_ready()) {
            wp_send_json_error(array('message' => 'Academia no disponible.'), 500);
        }

        $lesson_key = sanitize_key((string) wp_unslash($_POST['lesson_key'] ?? ''));
        $definition = self::lesson_definition($lesson_key);
        if (!$definition) {
            wp_send_json_error(array('message' => 'Lección no reconocida.'), 400);
        }

        $preflight = self::catalog_preflight();
        if (!$preflight['ready']) {
            wp_send_json_error(array('message' => $preflight['message']), 409);
        }

        $lessons = self::lessons_by_key();
        $current_key = self::current_lesson_key($lessons);
        if ($lesson_key !== $current_key) {
            wp_send_json_error(array('message' => 'Solo se puede preparar la siguiente lección disponible.'), 409);
        }

        if (!self::acquire_db_lock('prepare')) {
            wp_send_json_error(array('message' => 'La Academia ya está preparando contenido en otra pestaña.'), 423);
        }

        try {
            $lesson = self::lesson_row($lesson_key);
            if (!$lesson) {
                throw new RuntimeException('No se ha encontrado el estado de la lección.');
            }

            $status = sanitize_key((string) ($lesson['status'] ?? 'ready'));
            if (in_array($status, array('prepared', 'in_progress', 'completed'), true)) {
                wp_send_json_success(self::prepare_response($lesson_key, true));
            }

            if ('preparing' !== $status) {
                self::clear_lesson_data($lesson_key);
                self::clear_staged_academy_rules($lesson_key);
                $total = self::lesson_source_total($lesson_key);
                self::update_lesson($lesson_key, array(
                    'status'          => 'preparing',
                    'prepare_offset'  => 0,
                    'prepare_total'   => $total,
                    'item_count'      => 0,
                    'module_count'    => 0,
                    'completed_items' => 0,
                    'snapshot_before' => absint(get_option(self::KNOWLEDGE_SNAPSHOT_OPTION, 0)),
                    'snapshot_after'  => 0,
                    'source_signature'=> null,
                    'started_at'      => null,
                    'completed_at'    => null,
                ));
                $lesson = self::lesson_row($lesson_key);
            }

            $offset = absint($lesson['prepare_offset'] ?? 0);
            $total = absint($lesson['prepare_total'] ?? 0);
            $source_items = self::lesson_source_batch($lesson_key, $offset, self::PREPARE_BATCH_LIMIT);
            $existing_count = self::question_count($lesson_key);
            $inserted = 0;

            foreach ($source_items as $item) {
                $sequence = $existing_count + $inserted + 1;
                if (self::insert_curriculum_question($lesson_key, $definition, $item, $sequence)) {
                    $inserted++;
                }
                self::stage_item_rules($lesson_key, $item);
            }

            $new_offset = $offset + count($source_items);
            $source_done = $new_offset >= $total || empty($source_items);
            self::update_lesson($lesson_key, array('prepare_offset' => min($total, $new_offset)));

            if ($source_done) {
                $item_count = self::question_count($lesson_key);
                $module_count = self::question_module_count($lesson_key);
                if ($item_count < 1) {
                    self::clear_staged_academy_rules($lesson_key);
                    self::update_lesson($lesson_key, array(
                        'status'           => 'ready',
                        'prepare_offset'   => 0,
                        'item_count'       => 0,
                        'module_count'     => 0,
                        'snapshot_after'   => 0,
                        'source_signature' => self::lesson_source_signature($lesson_key),
                        'metadata'         => array('prepare_error' => 'no_curriculum_items'),
                    ));
                    throw new RuntimeException('Esta lección no ha podido generar ejercicios con los datos actuales del catálogo. Revisa la clasificación/etiquetas y vuelve a indexar antes de continuar.');
                }

                // Durante toda la lección se conserva el snapshot de entrada.
                // Las reglas canónicas preparadas quedan inactivas hasta que se
                // hayan evaluado todos los módulos; solo entonces se crea el
                // siguiente snapshot y se desbloquea la siguiente lección.
                self::update_lesson($lesson_key, array(
                    'status'           => 'prepared',
                    'prepare_offset'   => $total,
                    'item_count'       => $item_count,
                    'module_count'     => $module_count,
                    'snapshot_after'   => 0,
                    'source_signature' => self::lesson_source_signature($lesson_key),
                    'metadata'         => array('prepared_rules_source' => 'academy_stage'),
                ));
            }

            wp_send_json_success(self::prepare_response($lesson_key, $source_done));
        } catch (Throwable $error) {
            wp_send_json_error(array('message' => $error->getMessage()), 500);
        } finally {
            self::release_db_lock('prepare');
        }
    }

    public static function ajax_run_module() {
        self::guard_ajax();
        if (class_exists('SEO_Dependiente_Reset') && SEO_Dependiente_Reset::is_locked()) {
            wp_send_json_error(array('message' => 'El conocimiento se está reiniciando. Espera a que termine.'), 423);
        }
        if (!self::ensure_ready() || !class_exists('SEO_Dependiente_API')) {
            wp_send_json_error(array('message' => 'El motor del Dependiente no está disponible.'), 500);
        }

        $lesson_key = sanitize_key((string) wp_unslash($_POST['lesson_key'] ?? ''));
        $module_no = max(1, absint($_POST['module_no'] ?? 0));
        $batch_size = self::sanitize_batch_size($_POST['batch_size'] ?? self::AJAX_BATCH_INITIAL);
        $batch_uuid = self::sanitize_uuid($_POST['batch_uuid'] ?? '');
        if (!$batch_uuid) {
            $batch_uuid = wp_generate_uuid4();
        }

        $lesson = self::lesson_row($lesson_key);
        if (!$lesson || !in_array((string) ($lesson['status'] ?? ''), array('prepared', 'in_progress'), true)) {
            wp_send_json_error(array('message' => 'La lección todavía no está preparada para ejecutarse.'), 409);
        }

        $prepared_signature = trim((string) ($lesson['source_signature'] ?? ''));
        $current_signature = self::lesson_source_signature($lesson_key);
        if ($prepared_signature && $current_signature && !hash_equals($prepared_signature, $current_signature)) {
            wp_send_json_error(array(
                'message' => 'El catálogo ha cambiado desde que se preparó esta lección. Reinicia la formación o vuelve a preparar el temario para no mezclar snapshots.'
            ), 409);
        }

        $lessons = self::lessons_by_key();
        if ($lesson_key !== self::current_lesson_key($lessons)) {
            wp_send_json_error(array('message' => 'Esta lección no es la lección activa.'), 409);
        }

        $next_module = self::next_pending_module($lesson_key);
        if ($next_module < 1) {
            self::maybe_complete_lesson($lesson_key);
            wp_send_json_success(array(
                'batch_uuid'   => $batch_uuid,
                'processed'    => 0,
                'module_done'  => true,
                'lesson_done'  => true,
                'summary'      => self::lesson_summary($lesson_key),
                'rows'         => array(),
            ));
        }
        if ($module_no !== $next_module) {
            wp_send_json_error(array('message' => 'Debes completar primero el módulo ' . $next_module . '.'), 409);
        }

        if (!self::acquire_db_lock('run')) {
            wp_send_json_error(array('message' => 'Ya se está ejecutando un módulo de la Academia en otra pestaña.'), 423);
        }

        try {
            self::update_lesson($lesson_key, array(
                'status'     => 'in_progress',
                'started_at' => !empty($lesson['started_at']) ? $lesson['started_at'] : current_time('mysql'),
            ));

            $questions = self::pending_module_questions($lesson_key, $module_no, $batch_size);
            $rows = array();
            foreach ($questions as $question) {
                $run_id = self::run_question($question, $batch_uuid);
                if ($run_id) {
                    $run = self::run_by_id($run_id);
                    if ($run) {
                        $rows[] = self::present_run($run);
                    }
                }
            }

            $module = self::single_module_progress($lesson_key, $module_no);
            $module_done = $module && absint($module['answered']) >= absint($module['total']);
            $lesson_done = self::maybe_complete_lesson($lesson_key);
            $summary = self::lesson_summary($lesson_key);

            wp_send_json_success(array(
                'batch_uuid'      => $batch_uuid,
                'processed'       => count($questions),
                'module_no'       => $module_no,
                'module_total'    => absint($module['total'] ?? 0),
                'module_answered' => absint($module['answered'] ?? 0),
                'module_pending'  => max(0, absint($module['total'] ?? 0) - absint($module['answered'] ?? 0)),
                'module_done'     => (bool) $module_done,
                'lesson_done'     => (bool) $lesson_done,
                'summary'         => $summary,
                'rows'            => $rows,
            ));
        } catch (Throwable $error) {
            wp_send_json_error(array('message' => $error->getMessage()), 500);
        } finally {
            self::release_db_lock('run');
        }
    }

    public static function ajax_export_lesson() {
        self::guard_ajax();
        $lesson_key = sanitize_key((string) wp_unslash($_POST['lesson_key'] ?? ''));
        $lesson = self::lesson_row($lesson_key);
        $definition = self::lesson_definition($lesson_key);
        if (!$lesson || !$definition) {
            wp_send_json_error(array('message' => 'Lección no encontrada.'), 404);
        }

        global $wpdb;
        $questions = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT q.*, r.id AS run_id, r.batch_uuid, r.status AS run_status,
                    r.result_count, r.returned_count, r.search_uuid, r.search_strategy,
                    r.execution_ms, r.evaluation_status, r.evaluation_score,
                    r.evaluation_json, r.top_results, r.response_meta, r.error_message,
                    r.created_at AS run_created_at
             FROM " . self::questions_table() . " q
             LEFT JOIN " . self::runs_table() . " r ON r.question_id = q.id AND r.lesson_key = q.lesson_key
             WHERE q.lesson_key = %s AND q.enabled = 1
             ORDER BY q.sequence_no ASC, q.id ASC",
            $lesson_key
        ), ARRAY_A);

        $items = array();
        foreach ($questions as $row) {
            $items[] = array(
                'question_id'       => absint($row['id'] ?? 0),
                'module_no'         => absint($row['module_no'] ?? 0),
                'source_type'       => (string) ($row['source_type'] ?? ''),
                'source_id'         => absint($row['source_id'] ?? 0) ?: null,
                'source_key'        => (string) ($row['source_key'] ?? ''),
                'question_type'     => (string) ($row['question_type'] ?? ''),
                'mode'              => (string) ($row['mode'] ?? ''),
                'question'          => (string) ($row['question'] ?? ''),
                'expected'          => self::decode_json($row['expected_json'] ?? ''),
                'run'               => empty($row['run_id']) ? null : array(
                    'status'            => (string) ($row['run_status'] ?? ''),
                    'result_count'      => absint($row['result_count'] ?? 0),
                    'returned_count'    => absint($row['returned_count'] ?? 0),
                    'search_uuid'       => (string) ($row['search_uuid'] ?? ''),
                    'search_strategy'   => (string) ($row['search_strategy'] ?? ''),
                    'execution_ms'      => isset($row['execution_ms']) ? (float) $row['execution_ms'] : null,
                    'evaluation_status' => (string) ($row['evaluation_status'] ?? ''),
                    'evaluation_score'  => isset($row['evaluation_score']) ? (float) $row['evaluation_score'] : null,
                    'evaluation'        => self::decode_json($row['evaluation_json'] ?? ''),
                    'top_results'       => self::decode_json($row['top_results'] ?? ''),
                    'response_meta'     => self::decode_json($row['response_meta'] ?? ''),
                    'error_message'     => (string) ($row['error_message'] ?? ''),
                    'created_at'        => (string) ($row['run_created_at'] ?? ''),
                ),
            );
        }

        $document = array(
            'schema' => array(
                'name'    => 'seo_dependiente_academy_lesson',
                'version' => 1,
            ),
            'generated_at' => current_time('c'),
            'site' => array(
                'home_url'              => home_url('/'),
                'dependiente_version'   => defined('SEO_DEPENDIENTE_VERSION') ? SEO_DEPENDIENTE_VERSION : '',
                'trainer_db_version'    => self::DB_VERSION,
            ),
            'lesson' => array(
                'key'              => $lesson_key,
                'order'            => absint($definition['order']),
                'title'            => (string) $definition['title'],
                'status'           => (string) ($lesson['status'] ?? ''),
                'snapshot_before'  => absint($lesson['snapshot_before'] ?? 0),
                'snapshot_after'   => absint($lesson['snapshot_after'] ?? 0),
                'source_signature' => (string) ($lesson['source_signature'] ?? ''),
                'module_count'     => absint($lesson['module_count'] ?? 0),
                'item_count'       => absint($lesson['item_count'] ?? 0),
            ),
            'summary' => self::lesson_summary($lesson_key),
            'modules' => self::module_progress($lesson_key),
            'notes' => array(
                'curriculum_guided'           => true,
                'free_question_bank_enabled'  => false,
                'customer_search_log_written' => false,
                'observational_learning_used' => false,
                'ground_truth_source'         => 'catalog_index_and_canonical_taxonomy',
                'lesson_queries_use_snapshot_before' => true,
                'canonical_knowledge_promoted_on_completion' => true,
            ),
            'items' => $items,
        );

        wp_send_json_success(array(
            'filename' => 'dependiente-academia-' . sanitize_file_name($lesson_key) . '-' . current_time('Ymd-His') . '.json',
            'document' => $document,
        ));
    }

    /**
     * Las consultas de Academia usan el motor completo pero nunca entran en el
     * log de clientes. Este filtro se instala solo durante la llamada REST.
     */
    public static function skip_customer_search_log($should_log) {
        return false;
    }

    private static function lesson_definitions() {
        return array(
            'l1_categories' => array(
                'order'       => 1,
                'title'       => 'Conocer las categorías',
                'description' => 'Aprende el mapa básico de la tienda: qué familias existen y cómo se llaman.',
                'module_size' => 25,
                'source'      => 'Categorías WooCommerce con productos',
            ),
            'l2_inventory' => array(
                'order'       => 2,
                'title'       => 'Conocer el inventario',
                'description' => 'Recorre los productos indexados para comprobar que cada referencia puede ser reconocida dentro del catálogo.',
                'module_size' => 40,
                'source'      => 'Productos del índice de Dependiente',
            ),
            'l3_type_role' => array(
                'order'       => 3,
                'title'       => 'Entender TIPO y ROL',
                'description' => 'Aprende la identidad semántica básica de los productos y las combinaciones TIPO → ROL de esta tienda.',
                'module_size' => 25,
                'source'      => 'Vocabulario canónico TIPO / ROL',
            ),
            'l4_features' => array(
                'order'       => 4,
                'title'       => 'Etiquetas y atributos',
                'description' => 'Busca por aplicaciones, plataformas, subtipos, etiquetas y atributos sin depender del nombre exacto del producto.',
                'module_size' => 30,
                'source'      => 'Índice semántico, etiquetas y atributos',
            ),
        );
    }

    private static function lesson_definition($lesson_key) {
        $definitions = self::lesson_definitions();
        return isset($definitions[$lesson_key]) ? $definitions[$lesson_key] : null;
    }

    private static function sync_lessons() {
        global $wpdb;
        if (!self::table_exists(self::lessons_table())) {
            return;
        }

        $definitions = self::lesson_definitions();
        foreach ($definitions as $key => $definition) {
            $existing = absint($wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . self::lessons_table() . ' WHERE lesson_key = %s LIMIT 1',
                $key
            )));
            $data = array(
                'lesson_order' => absint($definition['order']),
                'title'        => sanitize_text_field((string) $definition['title']),
                'module_size'  => absint($definition['module_size']),
                'updated_at'   => current_time('mysql'),
            );
            if ($existing) {
                $wpdb->update(self::lessons_table(), $data, array('id' => $existing));
            } else {
                $data['lesson_key'] = $key;
                $data['status'] = 1 === absint($definition['order']) ? 'ready' : 'locked';
                $data['created_at'] = current_time('mysql');
                $wpdb->insert(self::lessons_table(), $data);
            }
        }

        $rows = self::lessons_by_key(false);
        $found_current = false;
        foreach ($definitions as $key => $definition) {
            if (!isset($rows[$key])) {
                continue;
            }
            $status = sanitize_key((string) ($rows[$key]['status'] ?? 'locked'));
            if ('completed' === $status) {
                continue;
            }
            if (!$found_current) {
                $found_current = true;
                if ('locked' === $status) {
                    $wpdb->update(self::lessons_table(), array('status' => 'ready'), array('lesson_key' => $key));
                }
            } elseif (!in_array($status, array('locked'), true)) {
                // No se permite tener dos lecciones activas simultáneas.
                $wpdb->update(self::lessons_table(), array('status' => 'locked'), array('lesson_key' => $key));
            }
        }
    }

    private static function lessons_by_key($sync = true) {
        global $wpdb;
        if ($sync) {
            // Evita recursión desde sync_lessons().
            static $syncing = false;
            if (!$syncing) {
                $syncing = true;
                self::sync_lessons();
                $syncing = false;
            }
        }
        $rows = (array) $wpdb->get_results(
            'SELECT * FROM ' . self::lessons_table() . ' ORDER BY lesson_order ASC, id ASC',
            ARRAY_A
        );
        $out = array();
        foreach ($rows as $row) {
            $out[(string) $row['lesson_key']] = $row;
        }
        return $out;
    }

    private static function lesson_row($lesson_key) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::lessons_table() . ' WHERE lesson_key = %s LIMIT 1',
            $lesson_key
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function current_lesson_key($lessons) {
        $definitions = self::lesson_definitions();
        foreach ($definitions as $key => $definition) {
            $status = isset($lessons[$key]['status']) ? (string) $lessons[$key]['status'] : 'locked';
            if ('completed' !== $status) {
                return $key;
            }
        }
        return '';
    }

    private static function catalog_preflight() {
        $status = class_exists('SEO_Dependiente_Index') ? SEO_Dependiente_Index::status() : array();
        $indexed = absint($status['indexed'] ?? 0);
        $published = absint($status['published'] ?? 0);
        $last_full = trim((string) ($status['last_full'] ?? ''));
        $ready = class_exists('WooCommerce') && $indexed > 0 && '' !== $last_full;

        if (!class_exists('WooCommerce')) {
            $message = 'WooCommerce no está disponible. La Academia necesita un catálogo de productos.';
        } elseif ($published < 1) {
            $message = 'No hay productos publicados que puedan formar parte del temario.';
            $ready = false;
        } elseif ($indexed < 1 || '' === $last_full) {
            $message = 'Reindexa el catálogo completo antes de comenzar. Las lecciones deben trabajar con un snapshot de inventario cerrado.';
        } elseif ($indexed < $published) {
            $message = 'Hay ' . number_format_i18n($indexed) . ' productos indexados de ' . number_format_i18n($published) . ' publicados. La reindexación terminó, pero conviene revisar si los restantes están ocultos o excluidos del catálogo.';
        } else {
            $message = 'Catálogo preparado. La Academia puede generar las lecciones desde el índice actual.';
        }

        return array(
            'ready'     => $ready,
            'indexed'   => $indexed,
            'published' => $published,
            'last_full' => $last_full,
            'message'   => $message,
        );
    }

    private static function render_lesson_card($lesson_key, $definition, $row, $current_key) {
        $status = sanitize_key((string) ($row['status'] ?? 'locked'));
        $labels = array(
            'locked'      => 'Bloqueada',
            'ready'       => 'Disponible',
            'preparing'   => 'Preparando',
            'prepared'    => 'Preparada',
            'in_progress' => 'En curso',
            'completed'   => 'Completada',
        );
        $class = 'is-' . $status;
        if ($lesson_key === $current_key) {
            $class .= ' is-current';
        }
        ?>
        <article class="seo-dependiente-trainer__lesson <?php echo esc_attr($class); ?>">
            <div class="seo-dependiente-trainer__lesson-number"><?php echo esc_html(number_format_i18n(absint($definition['order']))); ?></div>
            <div>
                <h3><?php echo esc_html((string) $definition['title']); ?></h3>
                <p><?php echo esc_html((string) $definition['description']); ?></p>
                <div class="seo-dependiente-trainer__lesson-meta">
                    <span><?php echo esc_html((string) $definition['source']); ?></span>
                    <?php if (absint($row['item_count'] ?? 0) > 0) : ?>
                        <span><?php echo esc_html(number_format_i18n(absint($row['item_count']))); ?> ejercicios</span>
                        <span><?php echo esc_html(number_format_i18n(absint($row['module_count']))); ?> módulos</span>
                    <?php endif; ?>
                </div>
                <span class="seo-dependiente-trainer__lesson-status"><?php echo esc_html($labels[$status] ?? ucfirst($status)); ?></span>
            </div>
        </article>
        <?php
    }

    private static function render_current_lesson($lesson_key, $definition, $lesson, $preflight, $modules, $next_module, $summary) {
        $status = sanitize_key((string) ($lesson['status'] ?? 'ready'));
        $preparing = 'preparing' === $status;
        $prepared = in_array($status, array('prepared', 'in_progress'), true);
        ?>
        <section class="postbox seo-dependiente-admin__box seo-dependiente-trainer__current">
            <div class="seo-dependiente-trainer__section-head">
                <div>
                    <span class="seo-dependiente-trainer__eyebrow">Lección <?php echo esc_html(number_format_i18n(absint($definition['order']))); ?></span>
                    <h2><?php echo esc_html((string) $definition['title']); ?></h2>
                    <p><?php echo esc_html((string) $definition['description']); ?></p>
                </div>
                <?php if (in_array($status, array('ready', 'preparing'), true)) : ?>
                    <button type="button" class="button button-primary button-hero" data-trainer-prepare-lesson <?php disabled(!$preflight['ready']); ?>>
                        <?php echo $preparing ? 'Continuar preparación' : 'Preparar lección'; ?>
                    </button>
                <?php elseif ($prepared && $next_module > 0) : ?>
                    <button type="button" class="button button-primary button-hero" data-trainer-run-module>
                        <?php echo esc_html(self::module_has_answers($lesson_key, $next_module) ? 'Continuar módulo ' . $next_module : 'Comenzar módulo ' . $next_module); ?>
                    </button>
                <?php endif; ?>
            </div>

            <?php if ($preparing) :
                $done = absint($lesson['prepare_offset'] ?? 0);
                $total = absint($lesson['prepare_total'] ?? 0);
                self::render_progress_bar($done, $total, 'Preparando temario');
            endif; ?>

            <?php if ($prepared) : ?>
                <div class="seo-dependiente-trainer__lesson-snapshots">
                    <span>Snapshot usado durante la lección <strong><?php echo esc_html(number_format_i18n(absint($lesson['snapshot_before'] ?? 0))); ?></strong></span>
                    <span>El siguiente snapshot se crea al completar todos los módulos</span>
                </div>
                <div class="seo-dependiente-trainer__module-progress" data-trainer-module-progress hidden>
                    <div class="seo-dependiente-trainer__progress"><div class="seo-dependiente-trainer__progress-bar" data-trainer-progress-bar></div></div>
                    <p data-trainer-run-status aria-live="polite"></p>
                </div>
                <?php self::render_modules($modules, $next_module); ?>
                <?php if (0 === absint($lesson['item_count'] ?? 0)) : ?>
                    <div class="notice notice-warning inline"><p>Esta lección no ha encontrado contenido suficiente en el catálogo. Revisa la clasificación antes de continuar.</p></div>
                <?php endif; ?>
            <?php else : ?>
                <div class="seo-dependiente-trainer__prepare-progress" data-trainer-prepare-progress <?php echo $preparing ? '' : 'hidden'; ?>>
                    <div class="seo-dependiente-trainer__progress"><div class="seo-dependiente-trainer__progress-bar" data-trainer-prepare-bar></div></div>
                    <p data-trainer-prepare-status aria-live="polite"></p>
                </div>
            <?php endif; ?>

            <?php if ($prepared) : ?>
                <div class="seo-dependiente-trainer__current-summary">
                    <span><strong data-trainer-summary="answered"><?php echo esc_html(number_format_i18n(absint($summary['answered']))); ?></strong> / <?php echo esc_html(number_format_i18n(absint($summary['total']))); ?> evaluados</span>
                    <span><strong data-trainer-summary="pass_top3"><?php echo esc_html(number_format_i18n(absint($summary['pass_top3']))); ?></strong> aciertos Top 3</span>
                    <span><strong data-trainer-summary="failed"><?php echo esc_html(number_format_i18n(absint($summary['failed']))); ?></strong> fallos</span>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }

    private static function render_modules($modules, $next_module) {
        if (!$modules) {
            return;
        }
        ?>
        <div class="seo-dependiente-trainer__modules">
            <?php foreach ($modules as $module) :
                $number = absint($module['module_no']);
                $total = absint($module['total']);
                $answered = absint($module['answered']);
                $done = $total > 0 && $answered >= $total;
                $active = !$done && $number === absint($next_module);
                $class = $done ? 'is-complete' : ($active ? 'is-active' : 'is-locked');
                ?>
                <div class="seo-dependiente-trainer__module <?php echo esc_attr($class); ?>">
                    <strong>Módulo <?php echo esc_html(number_format_i18n($number)); ?></strong>
                    <span><?php echo esc_html(number_format_i18n($answered)); ?> / <?php echo esc_html(number_format_i18n($total)); ?></span>
                    <small><?php echo $done ? 'Completado' : ($active ? 'Siguiente' : 'Bloqueado'); ?></small>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private static function render_progress_bar($done, $total, $label) {
        $percent = $total > 0 ? min(100, round(($done / $total) * 100)) : 0;
        ?>
        <div class="seo-dependiente-trainer__static-progress">
            <div class="seo-dependiente-trainer__progress"><div class="seo-dependiente-trainer__progress-bar" style="width:<?php echo esc_attr($percent); ?>%"></div></div>
            <p><?php echo esc_html($label); ?>: <?php echo esc_html(number_format_i18n($done)); ?> / <?php echo esc_html(number_format_i18n($total)); ?>.</p>
        </div>
        <?php
    }

    private static function render_kpis($summary) {
        $summary = wp_parse_args((array) $summary, self::empty_summary());
        ?>
        <div class="seo-dependiente-trainer__kpis" data-trainer-kpis>
            <?php self::kpi('Ejercicios', $summary['total'], 'total'); ?>
            <?php self::kpi('Evaluados', $summary['answered'], 'answered'); ?>
            <?php self::kpi('Acierto Top 1', $summary['pass_top1'], 'pass_top1'); ?>
            <?php self::kpi('Acierto Top 3', $summary['pass_top3'], 'pass_top3'); ?>
            <?php self::kpi('Fallos', $summary['failed'], 'failed'); ?>
            <?php self::kpi('Errores técnicos', $summary['errors'], 'errors'); ?>
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

    private static function render_runs_table($runs) {
        ?>
        <table class="widefat striped seo-dependiente-trainer__runs">
            <thead><tr><th>Módulo</th><th>Pregunta</th><th>Evaluación</th><th>Respuesta</th></tr></thead>
            <tbody data-trainer-run-body>
            <?php if (!$runs) : ?>
                <tr data-trainer-run-empty><td colspan="4">Todavía no se ha ejecutado ningún ejercicio de esta lección.</td></tr>
            <?php else : ?>
                <?php foreach ($runs as $row) : self::render_run_row(self::present_run($row)); endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private static function render_run_row($row) {
        $status = (string) ($row['evaluation_status'] ?? '');
        $labels = array(
            'pass_top1' => 'Correcto · Top 1',
            'pass_top3' => 'Correcto · Top 3',
            'pass_top8' => 'Correcto · Top 8',
            'fail'      => 'No superado',
            'error'     => 'Error técnico',
        );
        $class = 0 === strpos($status, 'pass_') ? 'is-ok' : ('error' === $status ? 'is-error' : 'is-empty');
        $results = (array) ($row['top_results'] ?? array());
        ?>
        <tr>
            <td><strong><?php echo esc_html(number_format_i18n(absint($row['module_no'] ?? 0))); ?></strong></td>
            <td><strong><?php echo esc_html((string) ($row['question'] ?? '')); ?></strong><?php if (!empty($row['search_strategy'])) : ?><div class="description">Estrategia: <code><?php echo esc_html((string) $row['search_strategy']); ?></code></div><?php endif; ?></td>
            <td><span class="seo-dependiente-trainer__status <?php echo esc_attr($class); ?>"><?php echo esc_html($labels[$status] ?? ucfirst($status)); ?></span><?php if (!empty($row['error_message'])) : ?><div class="description"><?php echo esc_html((string) $row['error_message']); ?></div><?php endif; ?></td>
            <td>
                <?php if ($results) : ?>
                    <ol class="seo-dependiente-trainer__answer-list">
                    <?php foreach (array_slice($results, 0, 5) as $result) : ?>
                        <li><strong><?php echo esc_html((string) ($result['title'] ?? '')); ?></strong><?php if (!empty($result['reasons'])) : ?><span><?php echo esc_html(implode(' · ', (array) $result['reasons'])); ?></span><?php endif; ?></li>
                    <?php endforeach; ?>
                    </ol>
                <?php else : ?>
                    <span class="description">Sin productos devueltos.</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private static function lesson_source_total($lesson_key) {
        global $wpdb;
        if ('l1_categories' === $lesson_key) {
            $count = wp_count_terms(array('taxonomy' => 'product_cat', 'hide_empty' => true));
            return is_wp_error($count) ? 0 : absint($count);
        }
        if (in_array($lesson_key, array('l2_inventory', 'l4_features'), true)) {
            if (!class_exists('SEO_Dependiente_Index') || !SEO_Dependiente_Index::table_exists()) {
                return 0;
            }
            return absint($wpdb->get_var('SELECT COUNT(*) FROM ' . SEO_Dependiente_Index::table()));
        }
        if ('l3_type_role' === $lesson_key) {
            return count(self::lesson3_sources());
        }
        return 0;
    }

    private static function lesson_source_batch($lesson_key, $offset, $limit) {
        if ('l1_categories' === $lesson_key) {
            return self::lesson1_batch($offset, $limit);
        }
        if ('l2_inventory' === $lesson_key) {
            return self::lesson2_batch($offset, $limit);
        }
        if ('l3_type_role' === $lesson_key) {
            return array_slice(self::lesson3_sources(), $offset, $limit);
        }
        if ('l4_features' === $lesson_key) {
            return self::lesson4_batch($offset, $limit);
        }
        return array();
    }

    private static function lesson1_batch($offset, $limit) {
        $terms = get_terms(array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'orderby'    => 'term_id',
            'order'      => 'ASC',
            'number'     => $limit,
            'offset'     => $offset,
        ));
        if (is_wp_error($terms)) {
            throw new RuntimeException($terms->get_error_message());
        }

        $items = array();
        foreach ((array) $terms as $term) {
            $name = trim((string) $term->name);
            if ('' === $name) {
                continue;
            }
            $children = get_term_children(absint($term->term_id), 'product_cat');
            if (is_wp_error($children)) {
                $children = array();
            }
            $acceptable = array_values(array_unique(array_merge(array(absint($term->term_id)), array_map('absint', (array) $children))));
            $items[] = array(
                'source_type' => 'category',
                'source_id'   => absint($term->term_id),
                'source_key'  => 'category:' . absint($term->term_id),
                'question_type' => 'category_identity',
                'mode'        => 'product',
                'question'    => '¿Qué es la categoría de productos "' . $name . '"?',
                'expected'    => array(
                    'kind'                    => 'category',
                    'category_id'             => absint($term->term_id),
                    'category_name'           => $name,
                    'category_path'           => self::category_path($term),
                    'acceptable_category_ids' => $acceptable,
                ),
                'rules' => array(
                    array('kind' => 'category_alias', 'id' => absint($term->term_id), 'label' => $name),
                ),
            );
        }
        return $items;
    }

    private static function lesson2_batch($offset, $limit) {
        global $wpdb;
        if (!class_exists('SEO_Dependiente_Index') || !SEO_Dependiente_Index::table_exists()) {
            return array();
        }
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT product_id, title, categories_json FROM ' . SEO_Dependiente_Index::table() . ' ORDER BY product_id ASC LIMIT %d OFFSET %d',
            $limit,
            $offset
        ), ARRAY_A);

        $items = array();
        foreach ($rows as $row) {
            $product_id = absint($row['product_id'] ?? 0);
            $title = trim((string) ($row['title'] ?? ''));
            if (!$product_id || '' === $title) {
                continue;
            }
            $items[] = array(
                'source_type' => 'product',
                'source_id'   => $product_id,
                'source_key'  => 'product:' . $product_id,
                'question_type' => 'product_identity',
                'mode'        => 'product',
                'question'    => '¿Qué producto es "' . self::shorten($title, 300) . '"?',
                'expected'    => array(
                    'kind'       => 'product',
                    'product_id' => $product_id,
                    'title'      => $title,
                    'categories' => self::decode_json($row['categories_json'] ?? ''),
                ),
                'rules' => array(),
            );
        }
        return $items;
    }

    private static function lesson3_sources() {
        static $cache = null;
        if (null !== $cache) {
            return $cache;
        }
        global $wpdb;
        $cache = array();
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects = $wpdb->prefix . 'seo_object_vocabulary';
        if (!self::table_exists($vocabulary) || !self::table_exists($objects)) {
            return $cache;
        }

        // TIPO se lee directamente de las asignaciones canónicas de producto.
        // ROL se resuelve mediante el bridge TIPO -> ROL cuando está disponible,
        // que es la fuente canónica del plugin; solo se usa ROL materializado
        // como fallback cuando el bridge no está cargado.
        $type_rows = (array) $wpdb->get_results(
            "SELECT ot.object_id, tv.id, tv.slug, tv.label
             FROM {$objects} ot
             INNER JOIN {$vocabulary} tv
               ON tv.id = ot.vocabulary_id
              AND tv.active = 1
              AND tv.semantic_group = 'tipo'
             WHERE ot.object_type = 'product'
               AND ot.status = 1
             ORDER BY tv.label ASC, tv.id ASC, ot.object_id ASC",
            ARRAY_A
        );

        $types = array();
        $product_types = array();
        foreach ($type_rows as $row) {
            $product_id = absint($row['object_id'] ?? 0);
            $type_id = absint($row['id'] ?? 0);
            $slug = sanitize_title((string) ($row['slug'] ?? ''));
            $label = trim((string) ($row['label'] ?? ''));
            if (!$product_id || !$type_id || !$slug || !$label) {
                continue;
            }
            if (!isset($types[$type_id])) {
                $types[$type_id] = array(
                    'id' => $type_id,
                    'slug' => $slug,
                    'label' => $label,
                    'products' => array(),
                );
            }
            $types[$type_id]['products'][$product_id] = true;
            if (!isset($product_types[$product_id])) {
                $product_types[$product_id] = array();
            }
            $product_types[$product_id][$type_id] = true;
        }

        foreach ($types as $type) {
            $cache[] = array(
                'source_type' => 'vocabulary',
                'source_id'   => absint($type['id']),
                'source_key'  => 'tipo:' . absint($type['id']),
                'question_type' => 'tipo',
                'mode'        => 'need',
                'question'    => '¿Qué productos del tipo "' . $type['label'] . '" tienes en el catálogo?',
                'expected'    => array(
                    'kind'          => 'vocabulary',
                    'conditions'    => array('tipo' => array($type['slug'])),
                    'labels'        => array('tipo' => array($type['label'])),
                    'product_count' => count($type['products']),
                ),
                'rules' => array(
                    array('kind' => 'vocabulary_route', 'id' => absint($type['id']), 'group' => 'tipo', 'slug' => $type['slug'], 'label' => $type['label']),
                ),
            );
        }

        $product_roles = array();
        if (function_exists('seo_catalog_get_product_roles')) {
            $product_roles = (array) seo_catalog_get_product_roles();
        } else {
            $role_rows = (array) $wpdb->get_results(
                "SELECT ro.object_id, rv.slug
                 FROM {$objects} ro
                 INNER JOIN {$vocabulary} rv
                   ON rv.id = ro.vocabulary_id
                  AND rv.active = 1
                  AND rv.semantic_group = 'rol'
                 WHERE ro.object_type = 'product'
                   AND ro.status = 1",
                ARRAY_A
            );
            foreach ($role_rows as $row) {
                $product_id = absint($row['object_id'] ?? 0);
                $slug = sanitize_title((string) ($row['slug'] ?? ''));
                if ($product_id && $slug && !isset($product_roles[$product_id])) {
                    $product_roles[$product_id] = $slug;
                }
            }
        }

        $role_vocabulary_rows = (array) $wpdb->get_results(
            "SELECT id, slug, label
             FROM {$vocabulary}
             WHERE active = 1 AND semantic_group = 'rol'
             ORDER BY label ASC, id ASC",
            ARRAY_A
        );
        $roles_by_slug = array();
        foreach ($role_vocabulary_rows as $row) {
            $slug = sanitize_title((string) ($row['slug'] ?? ''));
            if ($slug) {
                $roles_by_slug[$slug] = array(
                    'id' => absint($row['id'] ?? 0),
                    'slug' => $slug,
                    'label' => trim((string) ($row['label'] ?? '')),
                    'products' => array(),
                );
            }
        }
        foreach ($product_roles as $product_id => $role_slug) {
            $product_id = absint($product_id);
            $role_slug = sanitize_title((string) $role_slug);
            if ($product_id && isset($roles_by_slug[$role_slug])) {
                $roles_by_slug[$role_slug]['products'][$product_id] = true;
            }
        }

        foreach ($roles_by_slug as $role) {
            if (empty($role['products']) || empty($role['id']) || empty($role['label'])) {
                continue;
            }
            $cache[] = array(
                'source_type' => 'vocabulary',
                'source_id'   => absint($role['id']),
                'source_key'  => 'rol:' . absint($role['id']),
                'question_type' => 'rol',
                'mode'        => 'need',
                'question'    => '¿Qué productos cumplen el rol "' . $role['label'] . '" en el catálogo?',
                'expected'    => array(
                    'kind'          => 'vocabulary',
                    'conditions'    => array('rol' => array($role['slug'])),
                    'labels'        => array('rol' => array($role['label'])),
                    'product_count' => count($role['products']),
                ),
                'rules' => array(
                    array('kind' => 'vocabulary_route', 'id' => absint($role['id']), 'group' => 'rol', 'slug' => $role['slug'], 'label' => $role['label']),
                ),
            );
        }

        $pair_counts = array();
        foreach ($product_types as $product_id => $type_ids) {
            $role_slug = sanitize_title((string) ($product_roles[$product_id] ?? ''));
            if (!$role_slug || !isset($roles_by_slug[$role_slug])) {
                continue;
            }
            foreach (array_keys($type_ids) as $type_id) {
                $type_id = absint($type_id);
                if (!$type_id || !isset($types[$type_id])) {
                    continue;
                }
                $pair_key = $type_id . '|' . $role_slug;
                if (!isset($pair_counts[$pair_key])) {
                    $pair_counts[$pair_key] = array(
                        'type_id' => $type_id,
                        'role_slug' => $role_slug,
                        'products' => array(),
                    );
                }
                $pair_counts[$pair_key]['products'][absint($product_id)] = true;
            }
        }

        foreach ($pair_counts as $pair) {
            $type = $types[absint($pair['type_id'])] ?? null;
            $role = $roles_by_slug[(string) $pair['role_slug']] ?? null;
            if (!$type || !$role || empty($pair['products'])) {
                continue;
            }
            $cache[] = array(
                'source_type' => 'type_role',
                'source_id'   => null,
                'source_key'  => 'pair:' . absint($type['id']) . ':' . absint($role['id']),
                'question_type' => 'type_role',
                'mode'        => 'need',
                'question'    => '¿Qué productos del tipo "' . $type['label'] . '" cumplen el rol "' . $role['label'] . '"?',
                'expected'    => array(
                    'kind'          => 'vocabulary',
                    'conditions'    => array('tipo' => array($type['slug']), 'rol' => array($role['slug'])),
                    'labels'        => array('tipo' => array($type['label']), 'rol' => array($role['label'])),
                    'product_count' => count($pair['products']),
                ),
                'rules' => array(
                    array('kind' => 'vocabulary_route', 'id' => absint($type['id']), 'group' => 'tipo', 'slug' => $type['slug'], 'label' => $type['label']),
                    array('kind' => 'vocabulary_route', 'id' => absint($role['id']), 'group' => 'rol', 'slug' => $role['slug'], 'label' => $role['label']),
                ),
            );
        }

        return $cache;
    }

    private static function lesson4_batch($offset, $limit) {
        global $wpdb;
        if (!class_exists('SEO_Dependiente_Index') || !SEO_Dependiente_Index::table_exists()) {
            return array();
        }
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT product_id, vocabulary_json, tags_json, attributes_json FROM ' . SEO_Dependiente_Index::table() . ' ORDER BY product_id ASC LIMIT %d OFFSET %d',
            $limit,
            $offset
        ), ARRAY_A);

        $items = array();
        foreach ($rows as $row) {
            $product_id = absint($row['product_id'] ?? 0);
            $vocabulary = self::decode_json($row['vocabulary_json'] ?? '');
            $tags = self::decode_json($row['tags_json'] ?? '');
            $attributes = self::decode_json($row['attributes_json'] ?? '');
            $features = array();
            $rules = array();

            foreach (array('aplicacion', 'plataforma', 'subtipo') as $group) {
                foreach (array_slice((array) ($vocabulary[$group] ?? array()), 0, 1) as $term) {
                    $label = trim((string) ($term['label'] ?? ''));
                    $slug = sanitize_title((string) ($term['slug'] ?? ''));
                    $id = absint($term['id'] ?? 0);
                    if (!$label || !$slug) {
                        continue;
                    }
                    $features[] = array('kind' => 'vocabulary', 'group' => $group, 'slug' => $slug, 'label' => $label);
                    if ($id) {
                        $rules[] = array('kind' => 'vocabulary_route', 'id' => $id, 'group' => $group, 'slug' => $slug, 'label' => $label);
                    }
                    if (count($features) >= 2) {
                        break 2;
                    }
                }
            }

            if (count($features) < 3) {
                foreach ((array) $attributes as $attribute) {
                    $label = trim((string) ($attribute['label'] ?? $attribute['key'] ?? ''));
                    $values = array_values(array_filter(array_map('strval', (array) ($attribute['values'] ?? array()))));
                    $value = $values ? trim((string) $values[0]) : '';
                    if (!$label || !$value) {
                        continue;
                    }
                    $features[] = array(
                        'kind'  => 'attribute',
                        'key'   => sanitize_title((string) ($attribute['key'] ?? $label)),
                        'label' => $label,
                        'value' => $value,
                    );
                    if (count($features) >= 3) {
                        break;
                    }
                }
            }

            if (count($features) < 3) {
                foreach ((array) $tags as $tag) {
                    $name = trim((string) ($tag['name'] ?? ''));
                    $slug = sanitize_title((string) ($tag['slug'] ?? $name));
                    if (!$name || !$slug) {
                        continue;
                    }
                    $features[] = array('kind' => 'tag', 'slug' => $slug, 'label' => $name);
                    break;
                }
            }

            if (!$features) {
                continue;
            }

            $signature = hash('sha256', wp_json_encode($features, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $clauses = array();
            foreach ($features as $feature) {
                if ('vocabulary' === $feature['kind']) {
                    $group_label = array('aplicacion' => 'aplicación', 'plataforma' => 'plataforma', 'subtipo' => 'subtipo');
                    $clauses[] = ($group_label[$feature['group']] ?? 'etiqueta') . ' "' . $feature['label'] . '"';
                } elseif ('attribute' === $feature['kind']) {
                    $clauses[] = '"' . $feature['label'] . '" = "' . $feature['value'] . '"';
                } elseif ('tag' === $feature['kind']) {
                    $clauses[] = 'etiqueta "' . $feature['label'] . '"';
                }
            }

            $items[] = array(
                'source_type' => 'features',
                'source_id'   => $product_id,
                'source_key'  => 'features:' . substr($signature, 0, 40),
                'question_type' => 'features',
                'mode'        => 'need',
                'question'    => 'Busco productos con ' . implode(', ', $clauses) . '. ¿Qué opciones del catálogo encajan?',
                'expected'    => array(
                    'kind'       => 'features',
                    'features'   => $features,
                    'source_product_id' => $product_id,
                ),
                'rules' => $rules,
            );
        }
        return $items;
    }

    private static function insert_curriculum_question($lesson_key, $definition, $item, $sequence) {
        global $wpdb;
        $question = sanitize_text_field((string) ($item['question'] ?? ''));
        $question = self::shorten($question, 490);
        if ('' === $question) {
            return false;
        }
        $source_key = sanitize_text_field((string) ($item['source_key'] ?? ''));
        $normalized = class_exists('SEO_Dependiente_Index') ? SEO_Dependiente_Index::normalize($question) : strtolower($question);
        $hash = hash('sha256', $lesson_key . '|' . $source_key . '|' . $normalized);
        $exists = absint($wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::questions_table() . ' WHERE question_hash = %s LIMIT 1',
            $hash
        )));
        if ($exists) {
            return false;
        }

        $module_size = max(1, absint($definition['module_size'] ?? 25));
        $module_no = (int) ceil(max(1, $sequence) / $module_size);
        $inserted = $wpdb->insert(self::questions_table(), array(
            'question_hash' => $hash,
            'lesson_key'    => $lesson_key,
            'lesson_order'  => absint($definition['order'] ?? 0),
            'module_no'     => $module_no,
            'sequence_no'   => absint($sequence),
            'source_type'   => sanitize_key((string) ($item['source_type'] ?? '')),
            'source_id'     => absint($item['source_id'] ?? 0) ?: null,
            'source_key'    => self::shorten($source_key, 190),
            'question_type' => sanitize_key((string) ($item['question_type'] ?? 'other')),
            'mode'          => self::sanitize_mode($item['mode'] ?? 'need'),
            'question'      => $question,
            'expected_json' => wp_json_encode((array) ($item['expected'] ?? array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'enabled'       => 1,
            'created_at'    => current_time('mysql'),
            'updated_at'    => current_time('mysql'),
        ));
        return false !== $inserted;
    }

    private static function stage_item_rules($lesson_key, $item) {
        foreach ((array) ($item['rules'] ?? array()) as $rule) {
            $kind = sanitize_key((string) ($rule['kind'] ?? ''));
            if ('category_alias' === $kind) {
                self::stage_category_alias($lesson_key, $rule);
            } elseif ('vocabulary_route' === $kind) {
                self::stage_vocabulary_route($lesson_key, $rule);
            }
        }
    }

    private static function stage_category_alias($lesson_key, $rule) {
        $label = trim((string) ($rule['label'] ?? ''));
        $id = absint($rule['id'] ?? 0);
        if (!$id || !$label) {
            return;
        }
        $normalized = SEO_Dependiente_Index::normalize($label);
        if (!$normalized) {
            return;
        }
        self::upsert_academy_rule(array(
            'rule_key'              => 'academy-' . $lesson_key . '-category-' . $id,
            'rule_type'             => 'alias',
            'expression'            => $label,
            'normalized_expression' => $normalized,
            'canonical_expression'  => $normalized,
            'match_type'            => false !== strpos($normalized, ' ') ? 'phrase' : 'token',
            'semantic_role'         => 'object',
            'relation_type'         => 'synonym',
            'result_role'           => 'context',
            'weight'                => 92,
            'priority'              => 4,
            'confidence'            => 1.0,
            'source'                => 'academy_stage',
            'metadata'              => self::json(array('academy' => array('lesson' => $lesson_key, 'source_type' => 'category', 'source_id' => $id))),
            'active'                => 0,
        ));
    }

    private static function stage_vocabulary_route($lesson_key, $rule) {
        $id = absint($rule['id'] ?? 0);
        $group = sanitize_key((string) ($rule['group'] ?? ''));
        $slug = sanitize_title((string) ($rule['slug'] ?? ''));
        $label = trim((string) ($rule['label'] ?? ''));
        if (!$id || !$group || !$slug || !$label || !in_array($group, array('tipo','rol','aplicacion','plataforma','subtipo'), true)) {
            return;
        }
        $canonical = SEO_Dependiente_Index::normalize($label);
        if (!$canonical) {
            return;
        }
        $role = 'tipo' === $group ? 'object' : 'context';
        $base_key = 'academy-' . $lesson_key . '-' . $group . '-' . $id;

        self::upsert_academy_rule(array(
            'rule_key'              => $base_key . '-alias',
            'rule_type'             => 'alias',
            'expression'            => $label,
            'normalized_expression' => $canonical,
            'canonical_expression'  => $canonical,
            'match_type'            => false !== strpos($canonical, ' ') ? 'phrase' : 'token',
            'semantic_role'         => $role,
            'target_vocabulary_id'  => $id,
            'target_group'          => $group,
            'target_slug'           => $slug,
            'relation_type'         => 'synonym',
            'result_role'           => 'context',
            'weight'                => 94,
            'priority'              => 4,
            'confidence'            => 1.0,
            'source'                => 'academy_stage',
            'metadata'              => self::json(array('academy' => array('lesson' => $lesson_key, 'source_type' => 'vocabulary', 'source_id' => $id))),
            'active'                => 0,
        ));
        self::upsert_academy_rule(array(
            'rule_key'              => $base_key . '-route',
            'rule_type'             => 'route',
            'expression'            => null,
            'normalized_expression' => null,
            'canonical_expression'  => null,
            'match_type'            => 'token',
            'semantic_role'         => null,
            'source_group'          => $role,
            'source_slug'           => $canonical,
            'target_vocabulary_id'  => $id,
            'target_group'          => $group,
            'target_slug'           => $slug,
            'relation_type'         => 'routes_to',
            'result_role'           => 'context',
            'weight'                => 120,
            'priority'              => 4,
            'confidence'            => 1.0,
            'source'                => 'academy_stage',
            'metadata'              => self::json(array('academy' => array('lesson' => $lesson_key, 'source_type' => 'vocabulary', 'source_id' => $id))),
            'active'                => 0,
        ));
    }

    private static function upsert_academy_rule($data) {
        global $wpdb;
        if (!class_exists('SEO_Dependiente_Semantics')) {
            return false;
        }
        SEO_Dependiente_Semantics::ensure_ready();
        $table = SEO_Dependiente_Semantics::table();
        if (!self::table_exists($table)) {
            return false;
        }
        $rule_key = sanitize_key((string) ($data['rule_key'] ?? ''));
        if (!$rule_key) {
            return false;
        }
        $existing = absint($wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE rule_key = %s LIMIT 1", $rule_key)));
        $row = array(
            'rule_key'              => $rule_key,
            'rule_type'             => sanitize_key((string) ($data['rule_type'] ?? 'alias')),
            'expression'            => isset($data['expression']) ? (string) $data['expression'] : null,
            'normalized_expression' => isset($data['normalized_expression']) ? (string) $data['normalized_expression'] : null,
            'canonical_expression'  => isset($data['canonical_expression']) ? (string) $data['canonical_expression'] : null,
            'match_type'            => sanitize_key((string) ($data['match_type'] ?? 'token')),
            'semantic_role'         => !empty($data['semantic_role']) ? sanitize_key((string) $data['semantic_role']) : null,
            'source_vocabulary_id'  => absint($data['source_vocabulary_id'] ?? 0) ?: null,
            'source_group'          => !empty($data['source_group']) ? sanitize_key((string) $data['source_group']) : null,
            'source_slug'           => !empty($data['source_slug']) ? SEO_Dependiente_Index::normalize((string) $data['source_slug']) : null,
            'context_vocabulary_id' => absint($data['context_vocabulary_id'] ?? 0) ?: null,
            'context_group'         => !empty($data['context_group']) ? sanitize_key((string) $data['context_group']) : null,
            'context_slug'          => !empty($data['context_slug']) ? SEO_Dependiente_Index::normalize((string) $data['context_slug']) : null,
            'target_vocabulary_id'  => absint($data['target_vocabulary_id'] ?? 0) ?: null,
            'target_group'          => !empty($data['target_group']) ? sanitize_key((string) $data['target_group']) : null,
            'target_slug'           => !empty($data['target_slug']) ? sanitize_title((string) $data['target_slug']) : null,
            'relation_type'         => !empty($data['relation_type']) ? sanitize_key((string) $data['relation_type']) : null,
            'result_role'           => !empty($data['result_role']) ? sanitize_key((string) $data['result_role']) : null,
            'weight'                => min(1000, max(0, absint($data['weight'] ?? 100))),
            'priority'              => min(100, max(0, absint($data['priority'] ?? 4))),
            'confidence'            => isset($data['confidence']) ? min(1, max(0, (float) $data['confidence'])) : null,
            'language'              => 'es',
            'source'                => sanitize_key((string) ($data['source'] ?? 'academy_stage')),
            'metadata'              => isset($data['metadata']) ? (string) $data['metadata'] : null,
            'active'                => empty($data['active']) ? 0 : 1,
            'updated_at'            => current_time('mysql'),
        );
        if ($existing) {
            return false !== $wpdb->update($table, $row, array('id' => $existing));
        }
        $row['created_at'] = current_time('mysql');
        return false !== $wpdb->insert($table, $row);
    }

    private static function clear_staged_academy_rules($lesson_key) {
        global $wpdb;
        if (!class_exists('SEO_Dependiente_Semantics')) {
            return;
        }
        $table = SEO_Dependiente_Semantics::table();
        if (!self::table_exists($table)) {
            return;
        }
        $prefix = $wpdb->esc_like('academy-' . $lesson_key . '-') . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE source = 'academy_stage' AND rule_key LIKE %s", $prefix));
    }

    private static function activate_staged_academy_rules($lesson_key) {
        global $wpdb;
        if (!class_exists('SEO_Dependiente_Semantics')) {
            return 0;
        }
        $table = SEO_Dependiente_Semantics::table();
        if (!self::table_exists($table)) {
            return 0;
        }
        $prefix = $wpdb->esc_like('academy-' . $lesson_key . '-') . '%';
        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET source = 'academy', active = 1, updated_at = %s WHERE source = 'academy_stage' AND rule_key LIKE %s",
            current_time('mysql'),
            $prefix
        ));
    }

    private static function clear_lesson_data($lesson_key) {
        global $wpdb;
        $question_ids = (array) $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . self::questions_table() . ' WHERE lesson_key = %s',
            $lesson_key
        ));
        if ($question_ids) {
            $ids = array_values(array_filter(array_map('absint', $question_ids)));
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                $sql = $wpdb->prepare('DELETE FROM ' . self::runs_table() . " WHERE question_id IN ({$placeholders})", $ids);
                $wpdb->query($sql);
            }
        }
        $wpdb->delete(self::runs_table(), array('lesson_key' => $lesson_key));
        $wpdb->delete(self::questions_table(), array('lesson_key' => $lesson_key));
    }

    private static function prepare_response($lesson_key, $done) {
        $lesson = self::lesson_row($lesson_key);
        return array(
            'lesson_key'      => $lesson_key,
            'done'            => (bool) $done,
            'status'          => (string) ($lesson['status'] ?? ''),
            'prepare_offset'  => absint($lesson['prepare_offset'] ?? 0),
            'prepare_total'   => absint($lesson['prepare_total'] ?? 0),
            'item_count'      => absint($lesson['item_count'] ?? self::question_count($lesson_key)),
            'module_count'    => absint($lesson['module_count'] ?? self::question_module_count($lesson_key)),
            'snapshot_before' => absint($lesson['snapshot_before'] ?? 0),
            'snapshot_after'  => absint($lesson['snapshot_after'] ?? 0),
        );
    }

    private static function pending_module_questions($lesson_key, $module_no, $limit) {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT q.*
             FROM " . self::questions_table() . " q
             LEFT JOIN " . self::runs_table() . " r
               ON r.question_id = q.id
              AND r.lesson_key = q.lesson_key
              AND r.status = 'answered'
             WHERE q.lesson_key = %s
               AND q.module_no = %d
               AND q.enabled = 1
               AND r.id IS NULL
             ORDER BY q.sequence_no ASC, q.id ASC
             LIMIT %d",
            $lesson_key,
            $module_no,
            $limit
        ), ARRAY_A);
    }

    private static function run_question($question, $batch_uuid) {
        global $wpdb;
        $question_id = absint($question['id'] ?? 0);
        if (!$question_id) {
            return 0;
        }

        $existing_answered = absint($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::runs_table() . " WHERE question_id = %d AND lesson_key = %s AND status = 'answered' ORDER BY id DESC LIMIT 1",
            $question_id,
            (string) $question['lesson_key']
        )));
        if ($existing_answered) {
            return $existing_answered;
        }
        $wpdb->query($wpdb->prepare(
            "DELETE FROM " . self::runs_table() . " WHERE question_id = %d AND lesson_key = %s AND status = 'error'",
            $question_id,
            (string) $question['lesson_key']
        ));

        $request = new WP_REST_Request('POST', '/seo-taxonomy/v1/search');
        $request->set_body_params(array(
            'q'          => (string) ($question['question'] ?? ''),
            'mode'       => self::sanitize_mode($question['mode'] ?? 'need'),
            'page'       => 1,
            'orderby'    => 'relevance',
            'session_id' => 'academy:' . (string) $question['lesson_key'] . ':' . $question_id,
        ));

        $started_at = microtime(true);
        add_filter('seo_dependiente_should_log_search', array(__CLASS__, 'skip_customer_search_log'), 999, 4);
        try {
            $response = SEO_Dependiente_API::search($request);
        } catch (Throwable $error) {
            $response = new WP_Error('seo_dependiente_academy_exception', $error->getMessage());
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
        $compact_results = array();
        $result_ids = array();
        foreach (array_slice($all_results, 0, 8) as $position => $result) {
            if (!is_array($result)) {
                continue;
            }
            $product_id = absint($result['id'] ?? 0);
            if ($product_id) {
                $result_ids[] = $product_id;
            }
            $compact_results[] = array(
                'id'       => $product_id,
                'title'    => sanitize_text_field((string) ($result['title'] ?? '')),
                'score'    => isset($result['score']) ? round((float) $result['score'], 4) : null,
                'position' => $position + 1,
                'reasons'  => array_values(array_slice(array_map('sanitize_text_field', (array) ($result['reasons'] ?? array())), 0, 4)),
            );
        }

        $evaluation = self::evaluate_question($question, $result_ids, $status);
        $semantic = is_array($data['semantic'] ?? null) ? $data['semantic'] : array();
        $meta = array(
            'clarification' => is_array($data['clarification'] ?? null) ? $data['clarification'] : null,
            'semantic' => array(
                'normalized' => sanitize_text_field((string) ($semantic['normalized'] ?? '')),
                'concepts'   => is_array($semantic['concepts'] ?? null) ? $semantic['concepts'] : array(),
                'groups'     => array_values(array_slice((array) ($semantic['groups'] ?? array()), 0, 16)),
                'routes'     => array_values(array_slice((array) ($semantic['routes'] ?? array()), 0, 16)),
            ),
        );

        $inserted = $wpdb->insert(self::runs_table(), array(
            'batch_uuid'        => $batch_uuid,
            'lesson_key'        => sanitize_key((string) ($question['lesson_key'] ?? '')),
            'module_no'         => absint($question['module_no'] ?? 0),
            'question_id'       => $question_id,
            'source_type'       => sanitize_key((string) ($question['source_type'] ?? '')),
            'source_id'         => absint($question['source_id'] ?? 0) ?: null,
            'question_type'     => sanitize_key((string) ($question['question_type'] ?? 'other')),
            'mode'              => self::sanitize_mode($question['mode'] ?? 'need'),
            'question'          => sanitize_text_field((string) ($question['question'] ?? '')),
            'status'            => $status,
            'result_count'      => max(0, absint($data['total'] ?? 0)),
            'returned_count'    => count($all_results),
            'search_uuid'       => self::sanitize_uuid($data['search_id'] ?? '') ?: null,
            'search_strategy'   => sanitize_key((string) ($data['search_strategy'] ?? '')) ?: null,
            'execution_ms'      => round(max(0, (microtime(true) - $started_at) * 1000), 3),
            'evaluation_status' => (string) ($evaluation['status'] ?? 'error'),
            'evaluation_score'  => isset($evaluation['score']) ? round((float) $evaluation['score'], 4) : null,
            'evaluation_json'   => wp_json_encode($evaluation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'top_results'       => wp_json_encode($compact_results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'response_meta'     => wp_json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_message'     => $error_message ? sanitize_text_field($error_message) : null,
            'created_at'        => current_time('mysql'),
        ));

        return false === $inserted ? 0 : absint($wpdb->insert_id);
    }

    private static function evaluate_question($question, $result_ids, $run_status) {
        if ('error' === $run_status) {
            return array('status' => 'error', 'score' => 0, 'matched_product_id' => null, 'matched_position' => null);
        }
        $expected = self::decode_json($question['expected_json'] ?? '');
        if (!$expected) {
            return array('status' => 'fail', 'score' => 0, 'reason' => 'No hay verdad esperada para evaluar.');
        }
        if (!$result_ids) {
            return array('status' => 'fail', 'score' => 0, 'matched_product_id' => null, 'matched_position' => null, 'expected' => $expected);
        }

        $rows = class_exists('SEO_Dependiente_Index') ? SEO_Dependiente_Index::get_rows_by_ids($result_ids, 8) : array();
        $by_id = array();
        foreach ($rows as $row) {
            $decoded = SEO_Dependiente_Index::decode_row($row);
            $by_id[absint($decoded['product_id'] ?? 0)] = $decoded;
        }

        foreach ($result_ids as $index => $product_id) {
            if (empty($by_id[$product_id])) {
                continue;
            }
            if (self::row_matches_expected($by_id[$product_id], $expected)) {
                $position = $index + 1;
                if (1 === $position) {
                    $status = 'pass_top1';
                    $score = 1.0;
                } elseif ($position <= 3) {
                    $status = 'pass_top3';
                    $score = 0.85;
                } else {
                    $status = 'pass_top8';
                    $score = 0.55;
                }
                return array(
                    'status'             => $status,
                    'score'              => $score,
                    'matched_product_id' => $product_id,
                    'matched_position'   => $position,
                    'expected'           => $expected,
                );
            }
        }

        return array(
            'status'             => 'fail',
            'score'              => 0,
            'matched_product_id' => null,
            'matched_position'   => null,
            'expected'           => $expected,
        );
    }

    private static function row_matches_expected($row, $expected) {
        $kind = sanitize_key((string) ($expected['kind'] ?? ''));
        if ('product' === $kind) {
            return absint($row['product_id'] ?? 0) === absint($expected['product_id'] ?? 0);
        }
        if ('category' === $kind) {
            $acceptable = array_fill_keys(array_map('absint', (array) ($expected['acceptable_category_ids'] ?? array())), true);
            foreach ((array) ($row['categories'] ?? array()) as $category) {
                if (!empty($acceptable[absint($category['id'] ?? 0)])) {
                    return true;
                }
            }
            return false;
        }
        if ('vocabulary' === $kind) {
            return self::row_matches_vocabulary($row, (array) ($expected['conditions'] ?? array()));
        }
        if ('features' === $kind) {
            foreach ((array) ($expected['features'] ?? array()) as $feature) {
                if (!self::row_matches_feature($row, $feature)) {
                    return false;
                }
            }
            return !empty($expected['features']);
        }
        return false;
    }

    private static function row_matches_vocabulary($row, $conditions) {
        foreach ($conditions as $group => $expected_slugs) {
            $group = sanitize_key((string) $group);
            $actual = array();
            foreach ((array) ($row['vocabulary'][$group] ?? array()) as $item) {
                $actual[] = sanitize_title((string) ($item['slug'] ?? $item['label'] ?? ''));
            }

            // En el modelo canónico actual, ROL puede derivarse de TIPO mediante
            // seo_type_role_map sin estar materializado en vocabulary_json. La
            // evaluación de Academia debe usar la misma fuente de verdad.
            if ('rol' === $group) {
                $canonical_roles = self::canonical_roles_for_index_row($row);
                if ($canonical_roles) {
                    $actual = $canonical_roles;
                }
            }

            $actual = array_values(array_unique(array_filter($actual)));
            $wanted = array_values(array_unique(array_filter(array_map('sanitize_title', (array) $expected_slugs))));
            if (!$wanted || !array_intersect($wanted, $actual)) {
                return false;
            }
        }
        return !empty($conditions);
    }

    private static function canonical_roles_for_index_row($row) {
        static $type_to_role = null;
        if (null === $type_to_role) {
            global $wpdb;
            $type_to_role = array();
            $map = $wpdb->prefix . 'seo_type_role_map';
            $vocabulary = $wpdb->prefix . 'seo_vocabulary';
            if (self::table_exists($map) && self::table_exists($vocabulary)) {
                $rows = (array) $wpdb->get_results(
                    "SELECT tv.slug AS type_slug, rv.slug AS role_slug
                     FROM {$map} trm
                     INNER JOIN {$vocabulary} tv
                       ON tv.id = trm.type_vocabulary_id
                      AND tv.active = 1
                      AND tv.semantic_group = 'tipo'
                     INNER JOIN {$vocabulary} rv
                       ON rv.id = trm.role_vocabulary_id
                      AND rv.active = 1
                      AND rv.semantic_group = 'rol'
                     WHERE trm.active = 1",
                    ARRAY_A
                );
                foreach ($rows as $map_row) {
                    $type_slug = sanitize_title((string) ($map_row['type_slug'] ?? ''));
                    $role_slug = sanitize_title((string) ($map_row['role_slug'] ?? ''));
                    if ($type_slug && $role_slug) {
                        $type_to_role[$type_slug] = $role_slug;
                    }
                }
            }
        }

        $roles = array();
        foreach ((array) ($row['vocabulary']['tipo'] ?? array()) as $type) {
            $type_slug = sanitize_title((string) ($type['slug'] ?? $type['label'] ?? ''));
            if ($type_slug && !empty($type_to_role[$type_slug])) {
                $roles[] = (string) $type_to_role[$type_slug];
            }
        }
        return array_values(array_unique(array_filter($roles)));
    }

    private static function row_matches_feature($row, $feature) {
        $kind = sanitize_key((string) ($feature['kind'] ?? ''));
        if ('vocabulary' === $kind) {
            return self::row_matches_vocabulary($row, array(
                sanitize_key((string) ($feature['group'] ?? '')) => array((string) ($feature['slug'] ?? '')),
            ));
        }
        if ('tag' === $kind) {
            $wanted = sanitize_title((string) ($feature['slug'] ?? $feature['label'] ?? ''));
            foreach ((array) ($row['tags'] ?? array()) as $tag) {
                $actual = sanitize_title((string) ($tag['slug'] ?? $tag['name'] ?? ''));
                if ($wanted && $actual === $wanted) {
                    return true;
                }
            }
            return false;
        }
        if ('attribute' === $kind) {
            $wanted_key = SEO_Dependiente_Index::normalize((string) ($feature['key'] ?? $feature['label'] ?? ''));
            $wanted_label = SEO_Dependiente_Index::normalize((string) ($feature['label'] ?? ''));
            $wanted_value = SEO_Dependiente_Index::normalize((string) ($feature['value'] ?? ''));
            foreach ((array) ($row['attributes'] ?? array()) as $attribute) {
                $actual_key = SEO_Dependiente_Index::normalize((string) ($attribute['key'] ?? ''));
                $actual_label = SEO_Dependiente_Index::normalize((string) ($attribute['label'] ?? ''));
                if ($wanted_key !== $actual_key && $wanted_label !== $actual_label) {
                    continue;
                }
                foreach ((array) ($attribute['values'] ?? array()) as $value) {
                    if ($wanted_value && SEO_Dependiente_Index::normalize((string) $value) === $wanted_value) {
                        return true;
                    }
                }
            }
            return false;
        }
        return false;
    }

    private static function lesson_summary($lesson_key) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(q.id) AS total,
                    COALESCE(SUM(r.status = 'answered'), 0) AS answered,
                    COALESCE(SUM(r.evaluation_status = 'pass_top1'), 0) AS pass_top1,
                    COALESCE(SUM(r.evaluation_status IN ('pass_top1','pass_top3')), 0) AS pass_top3,
                    COALESCE(SUM(r.evaluation_status IN ('pass_top1','pass_top3','pass_top8')), 0) AS pass_any,
                    COALESCE(SUM(r.evaluation_status = 'fail'), 0) AS failed,
                    COALESCE(SUM(r.status = 'error'), 0) AS errors
             FROM " . self::questions_table() . " q
             LEFT JOIN " . self::runs_table() . " r ON r.question_id = q.id AND r.lesson_key = q.lesson_key
             WHERE q.lesson_key = %s AND q.enabled = 1",
            $lesson_key
        ), ARRAY_A);
        return wp_parse_args(is_array($row) ? array_map('absint', $row) : array(), self::empty_summary());
    }

    private static function empty_summary() {
        return array(
            'total'     => 0,
            'answered'  => 0,
            'pass_top1' => 0,
            'pass_top3' => 0,
            'pass_any'  => 0,
            'failed'    => 0,
            'errors'    => 0,
        );
    }

    private static function module_progress($lesson_key) {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT q.module_no,
                    COUNT(q.id) AS total,
                    COALESCE(SUM(r.status = 'answered'), 0) AS answered,
                    COALESCE(SUM(r.evaluation_status IN ('pass_top1','pass_top3','pass_top8')), 0) AS passed,
                    COALESCE(SUM(r.evaluation_status = 'fail'), 0) AS failed,
                    COALESCE(SUM(r.status = 'error'), 0) AS errors
             FROM " . self::questions_table() . " q
             LEFT JOIN " . self::runs_table() . " r ON r.question_id = q.id AND r.lesson_key = q.lesson_key
             WHERE q.lesson_key = %s AND q.enabled = 1
             GROUP BY q.module_no
             ORDER BY q.module_no ASC",
            $lesson_key
        ), ARRAY_A);
    }

    private static function single_module_progress($lesson_key, $module_no) {
        foreach (self::module_progress($lesson_key) as $row) {
            if (absint($row['module_no'] ?? 0) === absint($module_no)) {
                return $row;
            }
        }
        return null;
    }

    private static function next_pending_module($lesson_key) {
        foreach (self::module_progress($lesson_key) as $module) {
            if (absint($module['answered'] ?? 0) < absint($module['total'] ?? 0)) {
                return absint($module['module_no'] ?? 0);
            }
        }
        return 0;
    }

    private static function module_has_answers($lesson_key, $module_no) {
        $module = self::single_module_progress($lesson_key, $module_no);
        return $module && absint($module['answered'] ?? 0) > 0;
    }

    private static function maybe_complete_lesson($lesson_key) {
        $summary = self::lesson_summary($lesson_key);
        if (absint($summary['total']) < 1 || absint($summary['answered']) < absint($summary['total'])) {
            self::update_lesson($lesson_key, array('completed_items' => absint($summary['answered'])));
            return false;
        }

        $lesson = self::lesson_row($lesson_key);
        if (!$lesson) {
            return false;
        }

        // Promoción curricular atómica: la lección completa estudia siempre el
        // mismo snapshot. Solo al terminar se activan las reglas derivadas de
        // fuentes canónicas y se crea el snapshot que usará la siguiente.
        $activated_rules = self::activate_staged_academy_rules($lesson_key);
        $before = absint($lesson['snapshot_before'] ?? 0);
        $after = max(absint(get_option(self::KNOWLEDGE_SNAPSHOT_OPTION, 0)), $before) + 1;
        update_option(self::KNOWLEDGE_SNAPSHOT_OPTION, $after, false);

        self::update_lesson($lesson_key, array(
            'status'          => 'completed',
            'completed_items' => absint($summary['answered']),
            'snapshot_after'  => $after,
            'metadata'        => array(
                'activated_rules' => max(0, (int) $activated_rules),
                'summary'         => $summary,
            ),
            'completed_at'    => current_time('mysql'),
        ));
        self::sync_lessons();
        return true;
    }

    private static function recent_runs($lesson_key, $limit) {
        global $wpdb;
        $limit = min(500, max(1, absint($limit)));
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::runs_table() . ' WHERE lesson_key = %s ORDER BY id DESC LIMIT %d',
            $lesson_key,
            $limit
        ), ARRAY_A);
    }

    private static function run_by_id($run_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::runs_table() . ' WHERE id = %d LIMIT 1',
            absint($run_id)
        ), ARRAY_A);
    }

    private static function present_run($row) {
        return array(
            'id'                => absint($row['id'] ?? 0),
            'lesson_key'        => (string) ($row['lesson_key'] ?? ''),
            'module_no'         => absint($row['module_no'] ?? 0),
            'question_id'       => absint($row['question_id'] ?? 0),
            'question_type'     => (string) ($row['question_type'] ?? ''),
            'mode'              => (string) ($row['mode'] ?? ''),
            'question'          => (string) ($row['question'] ?? ''),
            'status'            => (string) ($row['status'] ?? ''),
            'result_count'      => absint($row['result_count'] ?? 0),
            'returned_count'    => absint($row['returned_count'] ?? 0),
            'search_strategy'   => (string) ($row['search_strategy'] ?? ''),
            'execution_ms'      => isset($row['execution_ms']) ? (float) $row['execution_ms'] : null,
            'evaluation_status' => (string) ($row['evaluation_status'] ?? ''),
            'evaluation_score'  => isset($row['evaluation_score']) ? (float) $row['evaluation_score'] : null,
            'evaluation'        => self::decode_json($row['evaluation_json'] ?? ''),
            'top_results'       => self::decode_json($row['top_results'] ?? ''),
            'response_meta'     => self::decode_json($row['response_meta'] ?? ''),
            'error_message'     => (string) ($row['error_message'] ?? ''),
            'created_at'        => (string) ($row['created_at'] ?? ''),
        );
    }

    private static function update_lesson($lesson_key, $data) {
        global $wpdb;
        $allowed = array(
            'status','module_count','item_count','completed_items','prepare_offset','prepare_total',
            'snapshot_before','snapshot_after','source_signature','metadata','started_at','completed_at'
        );
        $clean = array();
        foreach ((array) $data as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            if (in_array($key, array('module_count','item_count','completed_items','prepare_offset','prepare_total','snapshot_before','snapshot_after'), true)) {
                $clean[$key] = absint($value);
            } elseif ('status' === $key) {
                $clean[$key] = sanitize_key((string) $value);
            } elseif ('source_signature' === $key) {
                $clean[$key] = $value ? substr(preg_replace('/[^a-f0-9]/i', '', (string) $value), 0, 64) : null;
            } elseif (in_array($key, array('started_at','completed_at'), true)) {
                $clean[$key] = $value ? (string) $value : null;
            } else {
                $clean[$key] = is_string($value) ? $value : self::json($value);
            }
        }
        if (!$clean) {
            return false;
        }
        $clean['updated_at'] = current_time('mysql');
        return false !== $wpdb->update(self::lessons_table(), $clean, array('lesson_key' => $lesson_key));
    }

    private static function question_count($lesson_key) {
        global $wpdb;
        return absint($wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::questions_table() . ' WHERE lesson_key = %s AND enabled = 1',
            $lesson_key
        )));
    }

    private static function question_module_count($lesson_key) {
        global $wpdb;
        return absint($wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(MAX(module_no),0) FROM ' . self::questions_table() . ' WHERE lesson_key = %s AND enabled = 1',
            $lesson_key
        )));
    }

    private static function lesson_source_signature($lesson_key) {
        $preflight = self::catalog_preflight();
        $parts = array(
            $lesson_key,
            (string) ($preflight['indexed'] ?? 0),
            (string) ($preflight['published'] ?? 0),
            (string) ($preflight['last_full'] ?? ''),
            (string) self::lesson_source_total($lesson_key),
        );
        return hash('sha256', implode('|', $parts));
    }

    private static function category_path($term) {
        $names = array();
        foreach (array_reverse(get_ancestors(absint($term->term_id), 'product_cat', 'taxonomy')) as $ancestor_id) {
            $ancestor = get_term($ancestor_id, 'product_cat');
            if ($ancestor && !is_wp_error($ancestor)) {
                $names[] = (string) $ancestor->name;
            }
        }
        $names[] = (string) $term->name;
        return implode(' > ', array_filter($names));
    }

    private static function sanitize_batch_size($value) {
        $value = absint($value);
        if ($value < self::AJAX_BATCH_MIN) {
            $value = self::AJAX_BATCH_INITIAL;
        }
        return min(self::AJAX_BATCH_LIMIT, max(self::AJAX_BATCH_MIN, $value));
    }

    private static function sanitize_mode($mode) {
        $mode = sanitize_key((string) $mode);
        return in_array($mode, array('need', 'product', 'tool', 'compare'), true) ? $mode : 'need';
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

    private static function json($value) {
        return wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function shorten($value, $length) {
        $value = trim((string) $value);
        $length = max(1, absint($length));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }
        return substr($value, 0, $length);
    }

    private static function table_exists($table) {
        if (class_exists('SEO_Dependiente_Index')) {
            return SEO_Dependiente_Index::table_exists($table);
        }
        global $wpdb;
        return (string) $table === (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like((string) $table)));
    }

    private static function acquire_db_lock($scope) {
        global $wpdb;
        $name = 'seo_dep_academy_' . sanitize_key((string) $scope);
        return 1 === (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name));
    }

    private static function release_db_lock($scope) {
        global $wpdb;
        $name = 'seo_dep_academy_' . sanitize_key((string) $scope);
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }

    private static function guard_ajax() {
        check_ajax_referer('seo_dependiente_entrenador', 'nonce');
        if (!current_user_can(self::capability())) {
            wp_send_json_error(array('message' => 'No tienes permisos para usar la Academia.'), 403);
        }
    }

    private static function capability() {
        return class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options';
    }
}

SEO_Dependiente_Entrenador::init();
