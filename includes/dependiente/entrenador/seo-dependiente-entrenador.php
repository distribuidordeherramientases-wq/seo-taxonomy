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
    const DB_VERSION = '2026-09-09.1';
    const CURRICULUM_VERSION = '2.1';
    const PROGRESS_REPORT_VERSION = 1;
    const MAX_INVENTORY_SOURCES = 3000;
    const MAX_FEATURE_SOURCES = 12000;
    const MAX_FAQ_SOURCES = 3000;
    const MAX_CROSS_SOURCES = 1200;
    const MAX_EXAM_SOURCES = 800;
    const PREPARE_BATCH_LIMIT = 100;
    const AJAX_BATCH_MIN = 1;
    const AJAX_BATCH_INITIAL = 1;
    const AJAX_BATCH_LIMIT = 4;
    const RECENT_RUN_LIMIT = 120;
    const KNOWLEDGE_SNAPSHOT_OPTION = 'seo_dependiente_knowledge_snapshot';
    const AUTO_STATE_OPTION = 'seo_dependiente_academy_auto_state';
    const AUTO_WORKER_HOOK = 'seo_dependiente_academy_auto_worker';
    const AUTO_WATCHDOG_HOOK = 'seo_dependiente_academy_watchdog';
    const AUTO_ACTION_GROUP = 'seo-dependiente-academy';
    const AUTO_DIRECT_STALE_SECONDS = 90;
    const AUTO_MAX_NO_PROGRESS = 6;
    const AUTO_BROWSER_WATCHDOG_SECONDS = 8;
    const AUTO_CRON_FALLBACK_SECONDS = 6;
    const LAB_PREFIX = 'lab_';
    const LAB_MODULE_SIZE = 25;
    const LAB_IMPORT_LIMIT = 5000;
    const LAB_UPLOAD_MAX_BYTES = 2097152;

    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'), 20);
        add_action('wp_ajax_seo_dependiente_entrenador_prepare_lesson', array(__CLASS__, 'ajax_prepare_lesson'));
        add_action('wp_ajax_seo_dependiente_entrenador_run_module', array(__CLASS__, 'ajax_run_module'));
        add_action('wp_ajax_seo_dependiente_entrenador_export_lesson', array(__CLASS__, 'ajax_export_lesson'));
        add_action('wp_ajax_seo_dependiente_entrenador_export_progress', array(__CLASS__, 'ajax_export_progress'));
        add_action('wp_ajax_seo_dependiente_entrenador_export_course', array(__CLASS__, 'ajax_export_course'));
        add_action('wp_ajax_seo_dependiente_entrenador_set_mode', array(__CLASS__, 'ajax_set_mode'));
        add_action('wp_ajax_seo_dependiente_entrenador_auto_status', array(__CLASS__, 'ajax_auto_status'));
        add_action('wp_ajax_seo_dependiente_entrenador_lab_import', array(__CLASS__, 'ajax_lab_import'));
        add_action('wp_ajax_seo_dependiente_entrenador_lab_run', array(__CLASS__, 'ajax_lab_run'));
        add_action('wp_ajax_seo_dependiente_entrenador_lab_export', array(__CLASS__, 'ajax_lab_export'));
        add_action(self::AUTO_WORKER_HOOK, array(__CLASS__, 'auto_worker'));
        add_action(self::AUTO_WATCHDOG_HOOK, array(__CLASS__, 'auto_watchdog'));
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
        $speed = self::auto_speed_config();
        wp_localize_script('seo-dependiente-entrenador', 'SEODependienteEntrenador', array(
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('seo_dependiente_entrenador'),
            'batchSize'    => $speed['initial_batch'],
            'batchMin'     => $speed['min_batch'],
            'batchMax'     => $speed['max_batch'],
            'fastSeconds'  => $speed['fast_seconds'],
            'slowSeconds'  => $speed['slow_seconds'],
            'hardSeconds'  => $speed['very_slow_seconds'],
            'growthFactor' => $speed['growth_factor'],
            'slowdownFactor' => $speed['slowdown_factor'],
            'fastStreakRequired' => $speed['fast_streak_required'],
            'maxRetries'   => 6,
            'autoPollMs'   => 5000,
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
        $auto_state = self::auto_state();
        $auto_running = self::is_auto_running($auto_state);
        $basic_complete = self::basic_curriculum_completed($lessons);
        $lab_batch = $basic_complete ? self::latest_lab_batch() : null;
        $has_exportable_lessons = false;
        foreach ((array) $lessons as $lesson_row) {
            if (absint($lesson_row['item_count'] ?? 0) > 0) {
                $has_exportable_lessons = true;
                break;
            }
        }
        ?>
        <div class="seo-dependiente-trainer" data-trainer-root data-current-lesson="<?php echo esc_attr($current_key); ?>" data-current-module="<?php echo esc_attr($next_module); ?>" data-auto-running="<?php echo $auto_running ? '1' : '0'; ?>">
            <div class="seo-dependiente-trainer__intro">
                <div>
                    <h2>Academia del Dependiente v2.1</h2>
                    <p>Formación guiada sobre el catálogo real de PRO. L1–L7 trabajan en un aula aislada con conocimiento activo + reglas academy_stage de la lección; los clientes siguen usando solo conocimiento activo. L8 examina sin ayuda del aula.</p>
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

            <?php self::render_training_mode($auto_state, $preflight, $current_key); ?>

            <div class="seo-dependiente-trainer__roadmap">
                <?php foreach ($definitions as $lesson_key => $definition) :
                    $row = isset($lessons[$lesson_key]) ? $lessons[$lesson_key] : array();
                    self::render_lesson_card($lesson_key, $definition, $row, $current_key);
                endforeach; ?>
            </div>

            <section class="postbox seo-dependiente-admin__box seo-dependiente-trainer__reports">
                <div class="seo-dependiente-trainer__section-head">
                    <div>
                        <h2>Informes de Academia</h2>
                        <p class="description">Cada lección preparada conserva su informe completo y un informe ligero de progreso. El progreso se reconstruye desde el historial de ejecuciones, por lo que también incluye lo ya respondido antes de instalar esta versión.</p>
                    </div>
                    <button type="button" class="button" data-trainer-export-course <?php disabled(!$has_exportable_lessons); ?>>Descargar curso completo (JSON)</button>
                </div>
            </section>

            <?php if ($current && isset($definitions[$current_key])) :
                self::render_current_lesson($current_key, $definitions[$current_key], $current, $preflight, $modules, $next_module, $summary, $auto_running);
            endif; ?>

            <?php if ($current && absint($current['item_count'] ?? 0) > 0) : ?>
                <section class="seo-dependiente-trainer__results" data-trainer-results-section>
                    <div class="seo-dependiente-trainer__section-head">
                        <div>
                            <h2>Resultados de la lección actual</h2>
                            <p class="description">El acierto se calcula contra la verdad esperada del catálogo. Estos resultados muestran cómo responde el Dependiente durante la formación. En L1–L7 el aula puede usar reglas academy_stage preparadas desde la verdad canónica; esas reglas no llegan a clientes hasta superar el control de calidad. L8 usa solo conocimiento activo.</p>
                        </div>
                        <div>
                            <button type="button" class="button" data-trainer-export-progress>Descargar progreso</button>
                            <button type="button" class="button" data-trainer-export-lesson>Descargar informe completo</button>
                        </div>
                    </div>
                    <?php self::render_kpis($summary); ?>
                    <div class="seo-dependiente-trainer__table-wrap" data-trainer-run-table>
                        <?php self::render_runs_table($runs); ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php self::render_question_lab($basic_complete, $lab_batch, $auto_running); ?>
        </div>
        <?php
    }

    public static function ajax_prepare_lesson() {
        self::guard_ajax();
        if (self::is_auto_running()) {
            wp_send_json_error(array('message' => 'La formación automática está activa. Cámbiala a modo manual antes de lanzar acciones individuales.'), 409);
        }
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
        if (self::is_auto_running()) {
            wp_send_json_error(array('message' => 'La formación automática está activa. Cámbiala a modo manual antes de lanzar acciones individuales.'), 409);
        }
        if (class_exists('SEO_Dependiente_Reset') && SEO_Dependiente_Reset::is_locked()) {
            wp_send_json_error(array('message' => 'El conocimiento se está reiniciando. Espera a que termine.'), 423);
        }
        if (!self::ensure_ready() || !class_exists('SEO_Dependiente_API')) {
            wp_send_json_error(array('message' => 'El motor del Dependiente no está disponible.'), 500);
        }

        $lesson_key = sanitize_key((string) wp_unslash($_POST['lesson_key'] ?? ''));
        $module_no = max(1, absint($_POST['module_no'] ?? 0));
        $speed = self::auto_speed_config();
        $batch_size = self::sanitize_batch_size($_POST['batch_size'] ?? $speed['initial_batch']);
        $batch_uuid = self::sanitize_uuid($_POST['batch_uuid'] ?? '');
        if (!$batch_uuid) {
            $batch_uuid = wp_generate_uuid4();
        }

        $lesson = self::lesson_row($lesson_key);
        if (!$lesson || !in_array((string) ($lesson['status'] ?? ''), array('prepared', 'in_progress'), true)) {
            wp_send_json_error(array('message' => 'La lección todavía no está preparada para ejecutarse.'), 409);
        }

        // La lección preparada es el snapshot docente. Sus preguntas y expected_json
        // ya quedaron materializados en las tablas del Entrenador al terminar PREPARAR.
        // source_signature se conserva únicamente como huella de auditoría del origen.
        // No se vuelve a calcular contra el catálogo vivo durante la ejecución: hacerlo
        // mezclaría el concepto de snapshot con cambios normales de PRO (productos,
        // Vocabulary, FAQs o sus métricas) y además obligaría a recorrer de nuevo las
        // fuentes en cada lote de Academia.

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

    public static function ajax_set_mode() {
        self::guard_ajax();
        if (!self::ensure_ready()) {
            wp_send_json_error(array('message' => 'Academia no disponible.'), 500);
        }

        $mode = sanitize_key((string) wp_unslash($_POST['mode'] ?? 'manual'));
        if ('manual' === $mode) {
            self::save_auto_state(array(
                'enabled'      => false,
                'mode'         => 'manual',
                'status'       => 'manual',
                'last_message' => 'Modo manual activado. El progreso ya realizado se conserva.',
                'last_error'   => '',
                'updated_at'   => current_time('mysql'),
            ));
            self::clear_auto_schedule();
            wp_send_json_success(self::automation_payload());
        }

        if ('auto' !== $mode) {
            wp_send_json_error(array('message' => 'Modo de formación no reconocido.'), 400);
        }
        if (class_exists('SEO_Dependiente_Reset') && SEO_Dependiente_Reset::is_locked()) {
            wp_send_json_error(array('message' => 'El conocimiento se está reiniciando. Espera a que termine.'), 423);
        }
        $preflight = self::catalog_preflight();
        if (!$preflight['ready']) {
            wp_send_json_error(array('message' => $preflight['message']), 409);
        }

        $lessons = self::lessons_by_key();
        $current_key = self::current_lesson_key($lessons);
        if (!$current_key) {
            self::save_auto_state(array(
                'enabled'      => false,
                'mode'         => 'auto',
                'status'       => 'completed',
                'last_message' => 'Todas las lecciones disponibles ya están completadas.',
                'last_error'   => '',
                'updated_at'   => current_time('mysql'),
            ));
            wp_send_json_success(self::automation_payload());
        }

        self::save_auto_state(array(
            'enabled'             => true,
            'mode'                => 'auto',
            'status'              => 'running',
            'started_at'          => current_time('mysql'),
            'updated_at'          => current_time('mysql'),
            'current_lesson'      => $current_key,
            'current_module'      => self::next_pending_module($current_key),
            'batch_size'          => self::auto_speed_config()['initial_batch'],
            'last_processed'      => 0,
            'next_delay'          => 0,
            'fast_streak'         => 0,
            'no_progress_cycles'  => 0,
            'worker_heartbeat_at' => '',
            'worker_heartbeat_ts' => 0,
            'worker_runs'         => 0,
            'worker_source'       => '',
            'worker_active'       => 0,
            'worker_pid'          => 0,
            'worker_finished_ts'  => 0,
            'controller_active'       => 0,
            'controller_pid'          => 0,
            'controller_backend'      => '',
            'controller_started_ts'   => 0,
            'controller_heartbeat_ts' => 0,
            'controller_next_ts'      => 0,
            'direct_worker_pending' => 0,
            'direct_worker_dispatch_id' => '',
            'direct_worker_not_before' => 0,
            'last_dispatch_at'    => 0,
            'last_dispatch_backend' => '',
            'last_dispatch_pid'   => 0,
            'last_dispatch_result'=> '',
            'last_dispatch_error' => '',
            'last_error'          => '',
            'last_message'        => 'Formación automática iniciada. Arrancando el primer lote…',
        ));

        // El navegador solo da la orden de arranque. El trabajo se entrega al
        // Gestor de procesos compartido y no queda atado a esta petición.
        if (!self::schedule_auto_worker(0, true)) {
            self::save_auto_state(array(
                'enabled'      => false,
                'status'       => 'error',
                'last_error'   => 'No se pudo entregar la Academia al Gestor de procesos.',
                'last_message' => 'No se pudo iniciar la formación automática.',
            ));
            wp_send_json_error(array('message' => 'No se pudo programar la Academia en el Gestor de procesos.'), 500);
        }
        self::schedule_auto_watchdog(60);
        wp_send_json_success(self::automation_payload());
    }

    public static function ajax_auto_status() {
        self::guard_ajax();
        if (!self::ensure_ready()) {
            wp_send_json_error(array('message' => 'Academia no disponible.'), 500);
        }

        // El navegador solo actúa como watchdog adicional. Los lotes los ejecuta
        // el Gestor de procesos (o el scheduler seguro de fallback).
        self::maybe_watchdog_auto_worker();
        wp_send_json_success(self::automation_payload());
    }

    public static function auto_worker($source = 'legacy_scheduler') {
        $state = self::auto_state();
        $worker_pid = function_exists('getmypid') ? absint(getmypid()) : 0;
        if (!self::is_auto_running($state)) {
            return;
        }

        if (class_exists('SEO_Dependiente_Reset') && SEO_Dependiente_Reset::is_locked()) {
            self::save_auto_state(array(
                'last_message' => 'El reinicio de conocimiento está activo. La Academia esperará antes de continuar.',
                'updated_at'   => current_time('mysql'),
            ));
            self::schedule_auto_worker(10);
            return;
        }

        if (!self::acquire_db_lock('auto')) {
            self::schedule_auto_worker(5);
            return;
        }

        $state = self::auto_state();
        self::save_auto_state(array(
            'worker_heartbeat_at' => current_time('mysql'),
            'worker_heartbeat_ts' => time(),
            'worker_runs'         => absint($state['worker_runs'] ?? 0) + 1,
            'worker_source'       => sanitize_key((string) $source),
            'worker_active'       => 1,
            'worker_pid'          => $worker_pid,
            'direct_worker_pending' => 0,
            'updated_at'          => current_time('mysql'),
        ));

        try {
            if (!self::ensure_ready() || !class_exists('SEO_Dependiente_API')) {
                throw new RuntimeException('El motor del Dependiente no está disponible.');
            }
            $preflight = self::catalog_preflight();
            if (!$preflight['ready']) {
                throw new RuntimeException($preflight['message']);
            }

            $lessons = self::lessons_by_key();
            $lesson_key = self::current_lesson_key($lessons);
            if (!$lesson_key) {
                self::save_auto_state(array(
                    'enabled'      => false,
                    'mode'         => 'auto',
                    'status'       => 'completed',
                    'current_lesson' => '',
                    'current_module' => 0,
                    'last_message' => 'Formación automática completada. Todas las lecciones disponibles han terminado.',
                    'last_error'   => '',
                    'updated_at'   => current_time('mysql'),
                ));
                self::clear_auto_schedule();
                return;
            }

            $lesson = self::lesson_row($lesson_key);
            if (!$lesson) {
                throw new RuntimeException('No se ha encontrado la lección activa.');
            }
            $definition = self::lesson_definition($lesson_key);
            $lesson_label = $definition ? 'Lección ' . absint($definition['order']) . ' · ' . (string) $definition['title'] : $lesson_key;
            $status = sanitize_key((string) ($lesson['status'] ?? 'ready'));

            self::save_auto_state(array(
                'current_lesson' => $lesson_key,
                'current_module' => self::next_pending_module($lesson_key),
                'updated_at'     => current_time('mysql'),
            ));

            if (in_array($status, array('ready', 'preparing'), true)) {
                $result = self::auto_prepare_lesson_batch($lesson_key);
                $message = !empty($result['done'])
                    ? $lesson_label . ' preparada: ' . absint($result['item_count'] ?? 0) . ' ejercicios en ' . absint($result['module_count'] ?? 0) . ' módulos.'
                    : 'Preparando ' . $lesson_label . ': ' . absint($result['prepare_offset'] ?? 0) . ' de ' . absint($result['prepare_total'] ?? 0) . ' fuentes.';
                self::save_auto_state(array(
                    'last_message'       => $message,
                    'last_error'         => '',
                    'last_processed'     => 0,
                    'next_delay'         => 1,
                    'no_progress_cycles' => 0,
                    'updated_at'         => current_time('mysql'),
                ));
                self::schedule_auto_worker(1);
                return;
            }

            if (in_array($status, array('prepared', 'in_progress'), true)) {
                $module_no = self::next_pending_module($lesson_key);
                if ($module_no < 1) {
                    self::maybe_complete_lesson($lesson_key);
                    self::save_auto_state(array(
                        'current_module' => 0,
                        'last_message'   => $lesson_label . ' completada. La Academia continuará con la siguiente lección.',
                        'last_error'     => '',
                        'updated_at'     => current_time('mysql'),
                    ));
                    self::schedule_auto_worker(1);
                    return;
                }

                $state = self::auto_state();
                $speed = self::auto_speed_config();
                $batch_size = self::sanitize_batch_size($state['batch_size'] ?? $speed['initial_batch']);
                $started = microtime(true);
                $result = self::auto_run_module_batch($lesson_key, $module_no, $batch_size);
                $duration = max(0, microtime(true) - $started);
                $answered_rows = 0;
                foreach ((array) ($result['rows'] ?? array()) as $row) {
                    if ('answered' === (string) ($row['status'] ?? '')) {
                        $answered_rows++;
                    }
                }

                $no_progress = absint($state['no_progress_cycles'] ?? 0);
                if (absint($result['processed'] ?? 0) > 0 && $answered_rows < 1) {
                    $no_progress++;
                } else {
                    $no_progress = 0;
                }
                if ($no_progress >= self::AUTO_MAX_NO_PROGRESS) {
                    throw new RuntimeException('Se han producido errores técnicos repetidos sin avanzar en el módulo ' . $module_no . '. Se ha pausado para evitar un bucle de reintentos.');
                }

                $fast_streak = absint($state['fast_streak'] ?? 0);
                if ($duration >= $speed['very_slow_seconds']) {
                    $batch_size = $speed['min_batch'];
                    $fast_streak = 0;
                    $delay = $speed['critical_delay_seconds'];
                } elseif ($duration >= $speed['slow_seconds']) {
                    $batch_size = max($speed['min_batch'], (int) floor($batch_size * $speed['slowdown_factor']));
                    $fast_streak = 0;
                    $delay = $speed['slow_delay_seconds'];
                } elseif ($duration <= $speed['fast_seconds']) {
                    $fast_streak++;
                    if ($fast_streak >= $speed['fast_streak_required'] && $batch_size < $speed['max_batch']) {
                        $grown = max($batch_size + 1, (int) ceil($batch_size * $speed['growth_factor']));
                        $batch_size = min($speed['max_batch'], $grown);
                        $fast_streak = 0;
                    }
                    $delay = $speed['normal_delay_seconds'];
                } else {
                    $fast_streak = 0;
                    $delay = $speed['normal_delay_seconds'];
                }

                if (!empty($result['lesson_done'])) {
                    $message = $lesson_label . ' completada. Nuevo snapshot creado; la siguiente lección queda desbloqueada.';
                } elseif (!empty($result['module_done'])) {
                    $message = $lesson_label . ': módulo ' . $module_no . ' completado. Continuando con el siguiente módulo.';
                } else {
                    $message = $lesson_label . ': módulo ' . $module_no . ', ' . absint($result['module_answered'] ?? 0) . ' de ' . absint($result['module_total'] ?? 0) . ' evaluados. Siguiente lote: ' . $batch_size . '.';
                }

                if (absint($result['processed'] ?? 0) < 1 && empty($result['module_done']) && empty($result['lesson_done'])) {
                    throw new RuntimeException('No quedan ejercicios procesables, pero el módulo no se ha podido cerrar.');
                }

                self::save_auto_state(array(
                    'current_module'     => !empty($result['lesson_done']) ? 0 : $module_no,
                    'batch_size'         => $batch_size,
                    'fast_streak'        => $fast_streak,
                    'no_progress_cycles' => $no_progress,
                    'last_duration'      => round($duration, 3),
                    'last_processed'     => absint($result['processed'] ?? 0),
                    'next_delay'         => absint($delay),
                    'last_message'       => $message,
                    'last_error'         => '',
                    'updated_at'         => current_time('mysql'),
                ));
                self::schedule_auto_worker($delay);
                return;
            }

            if ('completed' === $status) {
                self::sync_lessons();
                self::schedule_auto_worker(1);
                return;
            }

            throw new RuntimeException('La lección activa está en un estado no ejecutable: ' . $status . '.');
        } catch (Throwable $error) {
            self::save_auto_state(array(
                'enabled'      => false,
                'mode'         => 'auto',
                'status'       => 'error',
                'last_error'   => sanitize_text_field($error->getMessage()),
                'last_message' => 'La formación automática se ha pausado. El progreso ya guardado no se pierde.',
                'updated_at'   => current_time('mysql'),
            ));
            self::clear_auto_schedule();
        } finally {
            self::release_db_lock('auto');
            $fresh = self::auto_state();
            if (absint($fresh['worker_pid'] ?? 0) === $worker_pid) {
                self::save_auto_state(array(
                    'worker_active'      => 0,
                    'worker_finished_ts' => time(),
                ));
            }
        }
    }

    public static function reset_automation_state() {
        self::clear_auto_schedule();
        delete_option(self::AUTO_STATE_OPTION);
    }

    /**
     * Instantanea de solo lectura para el monitor central de procesos.
     *
     * No ejecuta el watchdog ni programa workers: abrir Herramientas > Procesos
     * nunca debe alterar el ritmo de la Academia.
     *
     * @return array
     */
    public static function process_monitor_payload() {
        $state = self::auto_state();
        $lessons = self::lessons_by_key(false);
        $current_key = self::current_lesson_key($lessons);
        $definition = $current_key ? self::lesson_definition($current_key) : null;
        $lesson = $current_key ? self::lesson_row($current_key) : null;

        return array(
            'state' => $state,
            'running' => self::is_auto_running($state),
            'scheduler' => self::automation_scheduler_status(),
            'current' => $current_key ? array(
                'lesson_key'   => $current_key,
                'lesson_order' => absint($definition['order'] ?? 0),
                'title'        => (string) ($definition['title'] ?? ''),
                'status'       => (string) ($lesson['status'] ?? ''),
                'next_module'  => self::next_pending_module($current_key),
                'module_count' => absint($lesson['module_count'] ?? 0),
                'summary'      => self::lesson_summary($current_key),
            ) : null,
        );
    }

    private static function auto_prepare_lesson_batch($lesson_key) {
        $definition = self::lesson_definition($lesson_key);
        if (!$definition) {
            throw new RuntimeException('Lección no reconocida.');
        }
        $lessons = self::lessons_by_key();
        if ($lesson_key !== self::current_lesson_key($lessons)) {
            throw new RuntimeException('Solo se puede preparar la siguiente lección disponible.');
        }
        if (!self::acquire_db_lock('prepare')) {
            throw new RuntimeException('La Academia ya está preparando contenido en otro proceso.');
        }

        try {
            $lesson = self::lesson_row($lesson_key);
            if (!$lesson) {
                throw new RuntimeException('No se ha encontrado el estado de la lección.');
            }
            $status = sanitize_key((string) ($lesson['status'] ?? 'ready'));
            if (in_array($status, array('prepared', 'in_progress', 'completed'), true)) {
                return self::prepare_response($lesson_key, true);
            }

            if ('preparing' !== $status) {
                self::clear_lesson_data($lesson_key);
                self::clear_staged_academy_rules($lesson_key);
                $total = self::lesson_source_total($lesson_key);
                self::update_lesson($lesson_key, array(
                    'status'           => 'preparing',
                    'prepare_offset'   => 0,
                    'prepare_total'    => $total,
                    'item_count'       => 0,
                    'module_count'     => 0,
                    'completed_items'  => 0,
                    'snapshot_before'  => absint(get_option(self::KNOWLEDGE_SNAPSHOT_OPTION, 0)),
                    'snapshot_after'   => 0,
                    'source_signature' => null,
                    'started_at'       => null,
                    'completed_at'     => null,
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
                self::update_lesson($lesson_key, array(
                    'status'           => 'prepared',
                    'item_count'       => $item_count,
                    'module_count'     => $module_count,
                    'snapshot_after'   => 0,
                    'source_signature' => self::lesson_source_signature($lesson_key),
                    'metadata'         => array('prepared_rules_source' => 'academy_stage'),
                ));
            }

            return self::prepare_response($lesson_key, $source_done);
        } finally {
            self::release_db_lock('prepare');
        }
    }

    private static function auto_run_module_batch($lesson_key, $module_no, $batch_size) {
        $lesson = self::lesson_row($lesson_key);
        if (!$lesson || !in_array((string) ($lesson['status'] ?? ''), array('prepared', 'in_progress'), true)) {
            throw new RuntimeException('La lección todavía no está preparada para ejecutarse.');
        }
        // Igual que en modo manual: una vez preparada, la lección se ejecuta sobre
        // el snapshot docente ya persistido. La huella source_signature es informativa
        // y no debe convertirse en un bloqueo por cambios posteriores del catálogo vivo.
        $lessons = self::lessons_by_key();
        if ($lesson_key !== self::current_lesson_key($lessons)) {
            throw new RuntimeException('Esta lección no es la lección activa.');
        }

        $next_module = self::next_pending_module($lesson_key);
        $batch_uuid = wp_generate_uuid4();
        if ($next_module < 1) {
            $done = self::maybe_complete_lesson($lesson_key);
            return array(
                'batch_uuid'   => $batch_uuid,
                'processed'    => 0,
                'module_done'  => true,
                'lesson_done'  => (bool) $done,
                'summary'      => self::lesson_summary($lesson_key),
                'rows'         => array(),
            );
        }
        if (absint($module_no) !== absint($next_module)) {
            throw new RuntimeException('Debes completar primero el módulo ' . $next_module . '.');
        }
        if (!self::acquire_db_lock('run')) {
            throw new RuntimeException('Ya se está ejecutando un módulo de la Academia en otro proceso.');
        }

        try {
            self::update_lesson($lesson_key, array(
                'status'     => 'in_progress',
                'started_at' => !empty($lesson['started_at']) ? $lesson['started_at'] : current_time('mysql'),
            ));
            $questions = self::pending_module_questions($lesson_key, $module_no, self::sanitize_batch_size($batch_size));
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
            return array(
                'batch_uuid'      => $batch_uuid,
                'processed'       => count($questions),
                'module_no'       => absint($module_no),
                'module_total'    => absint($module['total'] ?? 0),
                'module_answered' => absint($module['answered'] ?? 0),
                'module_pending'  => max(0, absint($module['total'] ?? 0) - absint($module['answered'] ?? 0)),
                'module_done'     => (bool) $module_done,
                'lesson_done'     => (bool) $lesson_done,
                'summary'         => self::lesson_summary($lesson_key),
                'rows'            => $rows,
            );
        } finally {
            self::release_db_lock('run');
        }
    }

    private static function default_auto_state() {
        return array(
            'enabled'            => false,
            'mode'               => 'manual',
            'status'             => 'manual',
            'started_at'         => '',
            'updated_at'         => '',
            'current_lesson'     => '',
            'current_module'     => 0,
            'batch_size'         => self::auto_speed_config()['initial_batch'],
            'fast_streak'        => 0,
            'no_progress_cycles' => 0,
            'last_duration'      => 0,
            'last_processed'     => 0,
            'next_delay'         => 0,
            'worker_heartbeat_at'=> '',
            'worker_heartbeat_ts'=> 0,
            'worker_runs'        => 0,
            'worker_source'      => '',
            'worker_active'      => 0,
            'worker_pid'         => 0,
            'worker_finished_ts' => 0,
            'controller_active'       => 0,
            'controller_pid'          => 0,
            'controller_backend'      => '',
            'controller_started_ts'   => 0,
            'controller_heartbeat_ts' => 0,
            'controller_next_ts'      => 0,
            'direct_worker_pending' => 0,
            'direct_worker_dispatch_id' => '',
            'direct_worker_not_before' => 0,
            'last_dispatch_at'   => 0,
            'last_dispatch_backend' => '',
            'last_dispatch_pid'  => 0,
            'last_dispatch_result' => '',
            'last_dispatch_error'=> '',
            'last_message'       => '',
            'last_error'         => '',
        );
    }

    private static function auto_state() {
        $state = get_option(self::AUTO_STATE_OPTION, array());
        return wp_parse_args(is_array($state) ? $state : array(), self::default_auto_state());
    }

    private static function is_auto_running($state = null) {
        $state = is_array($state) ? $state : self::auto_state();
        return !empty($state['enabled']) && 'auto' === (string) ($state['mode'] ?? '') && 'running' === (string) ($state['status'] ?? '');
    }

    private static function save_auto_state($changes) {
        $state = self::auto_state();
        foreach ((array) $changes as $key => $value) {
            if (array_key_exists($key, $state)) {
                $state[$key] = $value;
            }
        }
        update_option(self::AUTO_STATE_OPTION, $state, false);
        return $state;
    }

    private static function automation_payload() {
        $state = self::auto_state();
        $lessons = self::lessons_by_key();
        $current_key = self::current_lesson_key($lessons);
        $definition = $current_key ? self::lesson_definition($current_key) : null;
        $lesson = $current_key ? self::lesson_row($current_key) : null;
        return array(
            'state' => $state,
            'running' => self::is_auto_running($state),
            'scheduler' => self::automation_scheduler_status(),
            'current' => $current_key ? array(
                'lesson_key'    => $current_key,
                'lesson_order'  => absint($definition['order'] ?? 0),
                'title'         => (string) ($definition['title'] ?? ''),
                'status'        => (string) ($lesson['status'] ?? ''),
                'next_module'   => self::next_pending_module($current_key),
                'module_count'  => absint($lesson['module_count'] ?? 0),
                'summary'       => self::lesson_summary($current_key),
            ) : null,
        );
    }

    private static function direct_loop_context() {
        return defined('SEO_ACADEMY_DIRECT_WORKER_LOOP') && SEO_ACADEMY_DIRECT_WORKER_LOOP;
    }

    private static function controller_is_active($state = null) {
        $state = is_array($state) ? $state : self::auto_state();
        if (empty($state['controller_active'])) {
            return false;
        }
        $heartbeat = absint($state['controller_heartbeat_ts'] ?? 0);
        if (!$heartbeat || (time() - $heartbeat) > 120) {
            return false;
        }
        $pid = absint($state['controller_pid'] ?? 0);
        if ($pid && function_exists('seo_ie_product_import_pid_is_alive')) {
            $alive = seo_ie_product_import_pid_is_alive($pid);
            if (false === $alive) {
                return false;
            }
        }
        return true;
    }

    private static function clear_legacy_auto_worker_schedule() {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::AUTO_WORKER_HOOK, array(), self::AUTO_ACTION_GROUP);
        }
        wp_clear_scheduled_hook(self::AUTO_WORKER_HOOK, array());
    }

    private static function schedule_auto_worker($delay = 0, $force = false) {
        $state = self::auto_state();
        if (!self::is_auto_running($state)) {
            return false;
        }
        $delay = max(0, absint($delay));
        if (self::direct_loop_context()) {
            self::save_auto_state(array('next_delay'=>$delay,'controller_next_ts'=>time()+$delay));
            return true;
        }

        // Academia v2 usa el Gestor de procesos compartido como backend normal.
        // No intenta crear PHP CLI ni loopbacks HTTP propios.
        if (function_exists('seo_process_supervisor_settings')) {
            $manager_settings = seo_process_supervisor_settings();
            if (!empty($manager_settings['enabled']) && !empty($manager_settings['academy'])) {
                self::clear_legacy_auto_worker_schedule();
                self::save_auto_state(array(
                    'direct_worker_pending'=>1,'direct_worker_dispatch_id'=>'','direct_worker_not_before'=>time()+$delay,
                    'last_dispatch_at'=>time(),'last_dispatch_backend'=>'process_manager','last_dispatch_pid'=>0,
                    'last_dispatch_result'=>'queued','last_dispatch_error'=>'',
                ));
                if (function_exists('seo_process_supervisor_nudge')) seo_process_supervisor_nudge($delay,'academy');
                if (function_exists('seo_process_supervisor_schedule_backup')) seo_process_supervisor_schedule_backup();
                return true;
            }
        }

        // Fallback seguro: Action Scheduler / WP-Cron. Sigue siendo por lotes y
        // nunca depende de exec(), PHP CLI ni peticiones loopback.
        self::clear_legacy_auto_worker_schedule();
        $when=time()+max(1,$delay);
        $scheduled=false;
        if(function_exists('as_schedule_single_action')){
            $id=as_schedule_single_action($when,self::AUTO_WORKER_HOOK,array(),self::AUTO_ACTION_GROUP,true,20);
            $scheduled=absint($id)>0;
        }
        if(!$scheduled){
            if($force) wp_clear_scheduled_hook(self::AUTO_WORKER_HOOK,array());
            if(false===wp_next_scheduled(self::AUTO_WORKER_HOOK,array())){
                $scheduled=(bool)wp_schedule_single_event($when,self::AUTO_WORKER_HOOK,array(),true);
            } else {
                $scheduled=true;
            }
        }
        self::save_auto_state(array(
            'direct_worker_pending'=>$scheduled?1:0,'direct_worker_dispatch_id'=>'','direct_worker_not_before'=>$when,
            'last_dispatch_at'=>time(),'last_dispatch_backend'=>$scheduled?'scheduler_fallback':'unavailable',
            'last_dispatch_pid'=>0,'last_dispatch_result'=>$scheduled?'queued':'error',
            'last_dispatch_error'=>$scheduled?'':'No se pudo programar el siguiente lote de Academia.',
        ));
        self::schedule_auto_watchdog(60);
        return $scheduled;
    }

    /**
     * Mantiene la Academia avanzando dentro del mismo proceso PHP. El regulador
     * conserva el control de la pausa entre lotes, pero no se agenda un PHP
     * nuevo en cada ciclo.
     */
    private static function run_direct_loop($backend, $max_runtime = 3600) {
        $backend = sanitize_key((string) $backend);
        $manager_slice = in_array($backend, array('manager_cron', 'server_cron', 'wp_cron_manager', 'manual_manager', 'request_pulse'), true);
        $max_runtime = $manager_slice ? max(5, absint($max_runtime)) : max(120, absint($max_runtime));
        $pid = function_exists('getmypid') ? absint(getmypid()) : 0;
        $started = time();

        if ($manager_slice && !defined('SEO_ACADEMY_DIRECT_WORKER_LOOP')) {
            define('SEO_ACADEMY_DIRECT_WORKER_LOOP', true);
        }

        self::save_auto_state(array(
            'controller_active'       => 1,
            'controller_pid'          => $pid,
            'controller_backend'      => $backend,
            'controller_started_ts'   => $started,
            'controller_heartbeat_ts' => $started,
            'controller_next_ts'      => 0,
            'direct_worker_pending'   => 0,
        ));

        try {
            while (self::is_auto_running()) {
                self::save_auto_state(array(
                    'controller_active'       => 1,
                    'controller_pid'          => $pid,
                    'controller_backend'      => $backend,
                    'controller_heartbeat_ts' => time(),
                    'controller_next_ts'      => 0,
                ));

                self::auto_worker($backend);
                $state = self::auto_state();
                if (!self::is_auto_running($state)) {
                    break;
                }

                $delay = max(0, absint($state['next_delay'] ?? 0));
                self::save_auto_state(array(
                    'controller_heartbeat_ts' => time(),
                    'controller_next_ts'      => time() + $delay,
                ));

                if ((time() - $started) >= $max_runtime) {
                    if ($manager_slice) {
                        break;
                    }
                    self::save_auto_state(array(
                        'controller_active'  => 0,
                        'controller_next_ts' => 0,
                    ));
                    if (self::schedule_auto_worker($delay, true)) {
                        return;
                    }
                    self::save_auto_state(array(
                        'controller_active'       => 1,
                        'controller_pid'          => $pid,
                        'controller_backend'      => $backend,
                        'controller_started_ts'   => $started,
                        'controller_heartbeat_ts' => time(),
                    ));
                }

                if ($manager_slice && $delay > 0 && ((time() - $started) + $delay) >= $max_runtime) {
                    self::save_auto_state(array(
                        'direct_worker_pending'     => 1,
                        'direct_worker_dispatch_id' => '',
                        'direct_worker_not_before'  => time() + $delay,
                    ));
                    break;
                }

                $remaining = $delay;
                while ($remaining > 0) {
                    $chunk = min(5, $remaining);
                    sleep($chunk);
                    $remaining -= $chunk;
                    $state = self::auto_state();
                    if (!self::is_auto_running($state)) {
                        break 2;
                    }
                    self::save_auto_state(array('controller_heartbeat_ts' => time()));
                }
            }
        } finally {
            $state = self::auto_state();
            if (absint($state['controller_pid'] ?? 0) === $pid) {
                self::save_auto_state(array(
                    'controller_active'       => 0,
                    'controller_heartbeat_ts' => time(),
                    'controller_next_ts'      => 0,
                ));
            }
        }
    }

    /**
     * Ejecuta una ventana limitada de Academia desde el gestor periódico.
     * El trabajo ocurre en el mismo proceso del gestor y no crea procesos hijo.
     *
     * @param int    $seconds Presupuesto máximo aproximado.
     * @param string $backend Identificador del gestor.
     * @return bool
     */
    public static function process_manager_slice($seconds = 20, $backend = 'manager_cron') {
        $state = self::auto_state();
        if (!self::is_auto_running($state)) {
            return false;
        }
        if (!empty($state['worker_active'])) {
            $heartbeat = absint($state['worker_heartbeat_ts'] ?? 0);
            if ($heartbeat && (time() - $heartbeat) <= 90) {
                return false;
            }
        }
        if (self::controller_is_active($state)) {
            return false;
        }

        self::save_auto_state(array(
            'direct_worker_pending'     => 0,
            'direct_worker_dispatch_id' => '',
            'last_dispatch_backend'     => sanitize_key((string) $backend),
            'last_dispatch_result'      => 'manager_slice',
            'last_dispatch_error'       => '',
        ));
        self::run_direct_loop(sanitize_key((string) $backend), max(5, min(55, absint($seconds))));
        return true;
    }

    /**
     * Arranque explícito desde Herramientas > Procesos.
     *
     * @return array|WP_Error
     */
    public static function process_control_start() {
        $state = self::auto_state();
        // El clic en Herramientas > Procesos es una orden explícita del
        // administrador: si estaba en manual, lo pasamos a automático aquí.
        if ('completed' === (string) ($state['status'] ?? '')) {
            return new WP_Error('academy_completed', 'La Academia ya ha completado el trabajo pendiente.');
        }
        if (self::controller_is_active($state) || !empty($state['worker_active'])) {
            return array('started' => false, 'message' => 'La Academia ya tiene un proceso propio activo.');
        }

        self::save_auto_state(array(
            'enabled'               => true,
            'mode'                  => 'auto',
            'status'                => 'running',
            'last_error'            => '',
            'direct_worker_pending' => 0,
            'direct_worker_dispatch_id' => '',
            'direct_worker_not_before' => 0,
            'last_dispatch_backend' => 'process_manager',
            'last_dispatch_result'  => 'queued',
            'last_dispatch_error'   => '',
            'controller_active'     => 0,
            'controller_heartbeat_ts' => 0,
            'last_message'          => 'Arranque manual solicitado desde Herramientas > Procesos.',
            'updated_at'            => current_time('mysql'),
        ));

        if (function_exists('seo_process_supervisor_schedule_backup')) {
            seo_process_supervisor_schedule_backup();
        }
        if (function_exists('seo_process_supervisor_nudge')) {
            seo_process_supervisor_nudge(0, 'academy');
        }
        self::process_manager_slice(15, 'manual_manager');

        return array('started' => true, 'message' => 'Academia entregada al gestor periódico.');
    }

    private static function schedule_auto_watchdog($delay = 60, $force = false) {
        if (!self::is_auto_running()) {
            return false;
        }
        if (function_exists('seo_process_supervisor_settings')) {
            $manager_settings = seo_process_supervisor_settings();
            if (!empty($manager_settings['enabled']) && !empty($manager_settings['academy'])) {
                if (function_exists('seo_process_supervisor_schedule_backup')) {
                    seo_process_supervisor_schedule_backup();
                }
                return true;
            }
        }
        $delay = max(30, absint($delay));
        $hook = self::AUTO_WATCHDOG_HOOK;
        $args = array();
        $group = self::AUTO_ACTION_GROUP;
        if (!$force && function_exists('as_has_scheduled_action') && as_has_scheduled_action($hook, $args, $group)) {
            return true;
        }
        if (function_exists('as_schedule_single_action')) {
            $id = as_schedule_single_action(time() + $delay, $hook, $args, $group, !$force, 20);
            if (absint($id) > 0) {
                return true;
            }
        }
        if ($force || false === wp_next_scheduled($hook, $args)) {
            if ($force) {
                wp_clear_scheduled_hook($hook, $args);
            }
            $scheduled = wp_schedule_single_event(time() + $delay, $hook, $args, true);
            return !is_wp_error($scheduled) && true === $scheduled;
        }
        return true;
    }

    public static function auto_watchdog() {
        $state = self::auto_state();
        if (!self::is_auto_running($state)) {
            return;
        }
        $now = time();
        $heartbeat = absint($state['worker_heartbeat_ts'] ?? 0);
        $heartbeat_stale = !$heartbeat || ($now - $heartbeat) > self::AUTO_DIRECT_STALE_SECONDS;
        $active = (!empty($state['worker_active']) && !$heartbeat_stale) || self::controller_is_active($state);
        $pending = !empty($state['direct_worker_pending']);
        $due = absint($state['direct_worker_not_before'] ?? 0);
        $pending_stale = $pending && $due > 0 && ($now - $due) > self::AUTO_DIRECT_STALE_SECONDS;
        if (!$active && (!$pending || $pending_stale) && $heartbeat_stale) {
            self::schedule_auto_worker(1, true);
        }
        self::schedule_auto_watchdog(60, true);
    }

    private static function maybe_watchdog_auto_worker() {
        $state = self::auto_state();
        if (!self::is_auto_running($state)) {
            return;
        }
        $now = time();
        $heartbeat = absint($state['worker_heartbeat_ts'] ?? 0);
        $heartbeat_stale = !$heartbeat || ($now - $heartbeat) >= self::AUTO_BROWSER_WATCHDOG_SECONDS;
        $active = (!empty($state['worker_active']) && !$heartbeat_stale) || self::controller_is_active($state);
        $pending = !empty($state['direct_worker_pending']);
        $due = absint($state['direct_worker_not_before'] ?? 0);
        $pending_stale = $pending && $due > 0 && ($now - $due) > self::AUTO_DIRECT_STALE_SECONDS;
        if (!$active && (!$pending || $pending_stale) && $heartbeat_stale) {
            self::schedule_auto_worker(1, true);
        }
    }

    private static function automation_scheduler_status() {
        $state = self::auto_state();
        $watchdog_as = false;
        if (function_exists('as_has_scheduled_action')) {
            $watchdog_as = (bool) as_has_scheduled_action(self::AUTO_WATCHDOG_HOOK, array(), self::AUTO_ACTION_GROUP);
        }
        $watchdog_cron = wp_next_scheduled(self::AUTO_WATCHDOG_HOOK, array());
        $due = absint($state['direct_worker_not_before'] ?? 0);
        $pending = !empty($state['direct_worker_pending']);
        return array(
            'engine'                     => 'direct',
            'direct_pending'             => $pending,
            'direct_not_before'          => $due,
            'direct_due_in'              => $due > 0 ? max(0, $due - time()) : 0,
            'direct_stale'               => $pending && $due > 0 && (time() - $due) > self::AUTO_DIRECT_STALE_SECONDS,
            'direct_backend'             => sanitize_key((string) ($state['last_dispatch_backend'] ?? '')),
            'direct_pid'                 => absint($state['last_dispatch_pid'] ?? 0),
            'direct_error'               => sanitize_text_field((string) ($state['last_dispatch_error'] ?? '')),
            'worker_active'              => !empty($state['worker_active']),
            'worker_pid'                 => absint($state['worker_pid'] ?? 0),
            'controller_active'          => self::controller_is_active($state),
            'controller_stale'           => !empty($state['controller_active']) && !self::controller_is_active($state),
            'controller_pid'             => absint($state['controller_pid'] ?? 0),
            'controller_backend'         => sanitize_key((string) ($state['controller_backend'] ?? '')),
            'controller_heartbeat_ts'    => absint($state['controller_heartbeat_ts'] ?? 0),
            'controller_next_ts'         => absint($state['controller_next_ts'] ?? 0),
            'watchdog_action_scheduler'  => $watchdog_as,
            'watchdog_wp_cron_next'      => $watchdog_cron ? absint($watchdog_cron) : 0,
            // Compatibilidad con la UI anterior.
            'action_scheduler_available' => function_exists('as_schedule_single_action') || function_exists('as_enqueue_async_action'),
            'action_scheduler_pending'   => $watchdog_as,
            'wp_cron_next'               => $watchdog_cron ? absint($watchdog_cron) : 0,
            'wp_cron_disabled'           => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        );
    }

    private static function clear_auto_schedule() {
        self::clear_legacy_auto_worker_schedule();
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::AUTO_WATCHDOG_HOOK, array(), self::AUTO_ACTION_GROUP);
        }
        wp_clear_scheduled_hook(self::AUTO_WATCHDOG_HOOK, array());
        self::save_auto_state(array(
            'direct_worker_pending'     => 0,
            'direct_worker_dispatch_id' => '',
            'direct_worker_not_before'  => 0,
            'worker_active'             => 0,
            'controller_active'         => 0,
            'controller_pid'            => 0,
            'controller_heartbeat_ts'   => 0,
            'controller_next_ts'        => 0,
        ));
    }

    public static function ajax_lab_import() {
        self::guard_ajax();
        if (!self::ensure_ready()) {
            wp_send_json_error(array('message' => 'Academia no disponible.'), 500);
        }
        if (!self::basic_curriculum_completed()) {
            wp_send_json_error(array('message' => 'Completa primero las cuatro lecciones básicas de la Academia.'), 409);
        }
        if (self::is_auto_running()) {
            wp_send_json_error(array('message' => 'La formación automática todavía está activa. Espera a que termine antes de usar el Laboratorio.'), 409);
        }
        if (class_exists('SEO_Dependiente_Reset') && SEO_Dependiente_Reset::is_locked()) {
            wp_send_json_error(array('message' => 'El conocimiento se está reiniciando. Espera a que termine.'), 423);
        }

        $default_mode = self::sanitize_mode($_POST['mode'] ?? 'need');
        $source = 'text';
        $filename = '';
        $items = array();

        try {
            if (!empty($_FILES['lab_file']) && is_array($_FILES['lab_file']) && UPLOAD_ERR_NO_FILE !== absint($_FILES['lab_file']['error'] ?? UPLOAD_ERR_NO_FILE)) {
                $file = $_FILES['lab_file'];
                $error = absint($file['error'] ?? UPLOAD_ERR_NO_FILE);
                if (UPLOAD_ERR_OK !== $error) {
                    throw new RuntimeException('No se pudo recibir el archivo de preguntas (código ' . $error . ').');
                }
                $size = absint($file['size'] ?? 0);
                if ($size < 1 || $size > self::LAB_UPLOAD_MAX_BYTES) {
                    throw new RuntimeException('El archivo debe ocupar entre 1 byte y 2 MB.');
                }
                $filename = sanitize_file_name((string) ($file['name'] ?? 'preguntas'));
                $tmp = (string) ($file['tmp_name'] ?? '');
                if (!$tmp || !is_file($tmp) || !is_readable($tmp)) {
                    throw new RuntimeException('El archivo temporal no se puede leer.');
                }
                $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
                if (!in_array($ext, array('txt', 'csv', 'json'), true)) {
                    throw new RuntimeException('Formato no admitido. Usa TXT, CSV o JSON.');
                }
                $source = 'upload_' . $ext;
                $items = self::parse_lab_file($tmp, $ext, $default_mode);
            } else {
                $text = trim((string) wp_unslash($_POST['questions_text'] ?? ''));
                if ('' === $text) {
                    throw new RuntimeException('Pega al menos una pregunta o selecciona un archivo TXT, CSV o JSON.');
                }
                $items = self::parse_lab_text($text, $default_mode);
            }

            $items = self::normalize_lab_items($items, $default_mode);
            if (!$items) {
                throw new RuntimeException('No se ha encontrado ninguna pregunta válida.');
            }
            if (count($items) > self::LAB_IMPORT_LIMIT) {
                throw new RuntimeException('El lote contiene ' . count($items) . ' preguntas. El máximo por lote es ' . self::LAB_IMPORT_LIMIT . '.');
            }

            $batch_key = self::new_lab_batch_key();
            $snapshot = absint(get_option(self::KNOWLEDGE_SNAPSHOT_OPTION, 0));
            $created = self::insert_lab_questions($batch_key, $items, array(
                'source'      => $source,
                'filename'    => $filename,
                'snapshot'    => $snapshot,
                'imported_at' => current_time('c'),
            ));
            if ($created < 1) {
                throw new RuntimeException('No se pudo guardar ninguna pregunta del lote.');
            }

            wp_send_json_success(array(
                'batch_key' => $batch_key,
                'created'   => $created,
                'snapshot'  => $snapshot,
                'summary'   => self::lab_summary($batch_key),
                'message'   => 'Lote preparado con ' . number_format_i18n($created) . ' preguntas. Todavía no se ha ejecutado ninguna.',
            ));
        } catch (Throwable $error) {
            wp_send_json_error(array('message' => $error->getMessage()), 400);
        }
    }

    public static function ajax_lab_run() {
        self::guard_ajax();
        if (!self::ensure_ready() || !class_exists('SEO_Dependiente_API')) {
            wp_send_json_error(array('message' => 'El motor del Dependiente no está disponible.'), 500);
        }
        if (!self::basic_curriculum_completed()) {
            wp_send_json_error(array('message' => 'El Laboratorio se desbloquea al completar la formación básica.'), 409);
        }
        if (self::is_auto_running()) {
            wp_send_json_error(array('message' => 'La formación automática todavía está activa.'), 409);
        }
        if (class_exists('SEO_Dependiente_Reset') && SEO_Dependiente_Reset::is_locked()) {
            wp_send_json_error(array('message' => 'El conocimiento se está reiniciando. Espera a que termine.'), 423);
        }

        $batch_key = self::sanitize_lab_batch_key($_POST['batch_key'] ?? '');
        if (!$batch_key || !self::lab_batch_exists($batch_key)) {
            wp_send_json_error(array('message' => 'Lote de Laboratorio no encontrado.'), 404);
        }
        $speed = self::auto_speed_config();
        $batch_size = self::sanitize_batch_size($_POST['batch_size'] ?? $speed['initial_batch']);
        $batch_uuid = self::sanitize_uuid($_POST['batch_uuid'] ?? '');
        if (!$batch_uuid) {
            $batch_uuid = wp_generate_uuid4();
        }

        if (!self::acquire_db_lock('lab_run')) {
            wp_send_json_error(array('message' => 'Ya se está ejecutando otro lote del Laboratorio.'), 423);
        }

        try {
            $questions = self::pending_lab_questions($batch_key, $batch_size);
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
            $summary = self::lab_summary($batch_key);
            $done = absint($summary['total'] ?? 0) > 0 && absint($summary['answered'] ?? 0) >= absint($summary['total'] ?? 0);

            wp_send_json_success(array(
                'batch_key'  => $batch_key,
                'batch_uuid' => $batch_uuid,
                'processed'  => count($questions),
                'done'       => $done,
                'summary'    => $summary,
                'rows'       => $rows,
            ));
        } catch (Throwable $error) {
            wp_send_json_error(array('message' => $error->getMessage()), 500);
        } finally {
            self::release_db_lock('lab_run');
        }
    }

    public static function ajax_lab_export() {
        self::guard_ajax();
        $batch_key = self::sanitize_lab_batch_key($_POST['batch_key'] ?? '');
        if (!$batch_key || !self::lab_batch_exists($batch_key)) {
            wp_send_json_error(array('message' => 'Lote de Laboratorio no encontrado.'), 404);
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
            $batch_key
        ), ARRAY_A);

        $items = array();
        $batch_meta = array();
        foreach ($questions as $row) {
            $meta = self::decode_json($row['expected_json'] ?? '');
            if (!$batch_meta && is_array($meta)) {
                $batch_meta = $meta;
            }
            $items[] = array(
                'question_id' => absint($row['id'] ?? 0),
                'sequence_no' => absint($row['sequence_no'] ?? 0),
                'mode'        => (string) ($row['mode'] ?? ''),
                'question'    => (string) ($row['question'] ?? ''),
                'run'         => empty($row['run_id']) ? null : array(
                    'status'            => (string) ($row['run_status'] ?? ''),
                    'result_count'      => absint($row['result_count'] ?? 0),
                    'returned_count'    => absint($row['returned_count'] ?? 0),
                    'search_uuid'       => (string) ($row['search_uuid'] ?? ''),
                    'search_strategy'   => (string) ($row['search_strategy'] ?? ''),
                    'execution_ms'      => isset($row['execution_ms']) ? (float) $row['execution_ms'] : null,
                    'evaluation_status' => (string) ($row['evaluation_status'] ?? ''),
                    'top_results'       => self::decode_json($row['top_results'] ?? ''),
                    'response_meta'     => self::decode_json($row['response_meta'] ?? ''),
                    'error_message'     => (string) ($row['error_message'] ?? ''),
                    'created_at'        => (string) ($row['run_created_at'] ?? ''),
                ),
            );
        }

        $document = array(
            'schema' => array('name' => 'seo_dependiente_question_lab', 'version' => 1),
            'generated_at' => current_time('c'),
            'site' => array(
                'home_url'            => home_url('/'),
                'dependiente_version' => defined('SEO_DEPENDIENTE_VERSION') ? SEO_DEPENDIENTE_VERSION : '',
                'trainer_db_version'  => self::DB_VERSION,
            ),
            'batch' => array(
                'key'         => $batch_key,
                'snapshot'    => absint($batch_meta['snapshot'] ?? 0),
                'source'      => (string) ($batch_meta['source'] ?? ''),
                'filename'    => (string) ($batch_meta['filename'] ?? ''),
                'imported_at' => (string) ($batch_meta['imported_at'] ?? ''),
            ),
            'summary' => self::lab_summary($batch_key),
            'notes' => array(
                'diagnostic_only'             => true,
                'customer_search_log_written' => false,
                'observational_learning_used' => false,
                'knowledge_modified'          => false,
            ),
            'items' => $items,
        );

        wp_send_json_success(array(
            'filename' => 'dependiente-laboratorio-' . sanitize_file_name($batch_key) . '-' . current_time('Ymd-His') . '.json',
            'document' => $document,
        ));
    }

    public static function ajax_export_lesson() {
        self::guard_ajax();
        $lesson_key = sanitize_key((string) wp_unslash($_POST['lesson_key'] ?? ''));
        $document = self::build_lesson_export_document($lesson_key);
        if (!$document) {
            wp_send_json_error(array('message' => 'Lección no encontrada.'), 404);
        }

        wp_send_json_success(array(
            'filename' => 'dependiente-academia-' . sanitize_file_name($lesson_key) . '-' . current_time('Ymd-His') . '.json',
            'document' => $document,
        ));
    }

    public static function ajax_export_progress() {
        self::guard_ajax();
        $lesson_key = sanitize_key((string) wp_unslash($_POST['lesson_key'] ?? ''));
        $document = self::build_progress_export_document($lesson_key);
        if (!$document) {
            wp_send_json_error(array('message' => 'Lección no encontrada.'), 404);
        }

        wp_send_json_success(array(
            'filename' => 'dependiente-academia-progreso-' . sanitize_file_name($lesson_key) . '-' . current_time('Ymd-His') . '.json',
            'document' => $document,
        ));
    }

    public static function ajax_export_course() {
        self::guard_ajax();
        if (!self::ensure_ready()) {
            wp_send_json_error(array('message' => 'Academia no disponible.'), 500);
        }

        $definitions = self::lesson_definitions();
        $lessons = self::lessons_by_key();
        $exportable = array();
        foreach ($definitions as $lesson_key => $definition) {
            $row = isset($lessons[$lesson_key]) ? $lessons[$lesson_key] : null;
            if ($row && absint($row['item_count'] ?? 0) > 0) {
                $exportable[] = $lesson_key;
            }
        }
        if (!$exportable) {
            wp_send_json_error(array('message' => 'Todavía no hay lecciones preparadas para exportar.'), 404);
        }

        $filename = 'dependiente-academia-curso-completo-' . current_time('Ymd-His') . '.json';
        @set_time_limit(0);
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level()) {
            @ob_end_clean();
        }

        nocache_headers();
        status_header(200);
        header('Content-Type: application/json; charset=' . get_option('blog_charset', 'UTF-8'));
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('X-Content-Type-Options: nosniff');

        $course_summary = self::course_export_summary($lessons);
        $lesson_index = self::course_export_lesson_index($definitions, $lessons);
        $prefix = array(
            'schema' => array(
                'name'    => 'seo_dependiente_academy_course',
                'version' => 1,
            ),
            'generated_at' => current_time('c'),
            'site' => array(
                'home_url'            => home_url('/'),
                'dependiente_version' => defined('SEO_DEPENDIENTE_VERSION') ? SEO_DEPENDIENTE_VERSION : '',
                'trainer_db_version'  => self::DB_VERSION,
            ),
            'course' => array(
                'curriculum_version' => self::CURRICULUM_VERSION,
                'knowledge_snapshot' => absint(get_option(self::KNOWLEDGE_SNAPSHOT_OPTION, 0)),
                'lesson_count'       => count($definitions),
                'exported_lessons'   => count($exportable),
            ),
            'summary'      => $course_summary,
            'lesson_index' => $lesson_index,
        );

        echo '{';
        $first = true;
        foreach ($prefix as $key => $value) {
            if (!$first) {
                echo ',';
            }
            echo wp_json_encode((string) $key) . ':' . wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $first = false;
        }
        echo ',"lessons":[';

        $first_lesson = true;
        foreach ($exportable as $lesson_key) {
            $document = self::build_lesson_export_document($lesson_key);
            if (!$document) {
                continue;
            }
            if (!$first_lesson) {
                echo ',';
            }
            echo wp_json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $first_lesson = false;
            if (function_exists('flush')) {
                @flush();
            }
        }
        echo ']}';
        exit;
    }

    private static function build_progress_export_document($lesson_key) {
        $lesson_key = sanitize_key((string) $lesson_key);
        $lesson = self::lesson_row($lesson_key);
        $definition = self::lesson_definition($lesson_key);
        if (!$lesson || !$definition) {
            return null;
        }

        return array(
            'schema' => array(
                'name'    => 'seo_dependiente_academy_progress',
                'version' => self::PROGRESS_REPORT_VERSION,
            ),
            'generated_at' => current_time('c'),
            'site' => array(
                'home_url'            => home_url('/'),
                'dependiente_version' => defined('SEO_DEPENDIENTE_VERSION') ? SEO_DEPENDIENTE_VERSION : '',
                'trainer_db_version'  => self::DB_VERSION,
            ),
            'lesson' => array(
                'key'                => $lesson_key,
                'order'              => absint($definition['order'] ?? 0),
                'title'              => (string) ($definition['title'] ?? ''),
                'status'             => (string) ($lesson['status'] ?? ''),
                'snapshot_before'    => absint($lesson['snapshot_before'] ?? 0),
                'snapshot_after'     => absint($lesson['snapshot_after'] ?? 0),
                'source_signature'   => (string) ($lesson['source_signature'] ?? ''),
                'module_count'       => absint($lesson['module_count'] ?? 0),
                'item_count'         => absint($lesson['item_count'] ?? 0),
                'curriculum_version' => self::CURRICULUM_VERSION,
                'started_at'         => (string) ($lesson['started_at'] ?? ''),
                'completed_at'       => (string) ($lesson['completed_at'] ?? ''),
            ),
            'summary' => self::lesson_summary($lesson_key),
            'learning_progress' => self::lesson_learning_progress($lesson_key),
            'notes' => array(
                'source'                         => 'trainer_run_history',
                'retroactive_from_existing_runs' => true,
                'checkpoint_unit'                => 'module',
                'accuracy_denominator'           => 'answered_questions',
                'causal_learning_attribution'    => false,
                'interpretation'                 => 'La curva muestra evolucion observada durante la leccion. Los cambios de dificultad o de tipo de pregunta tambien pueden mover la tasa de acierto.',
                'customer_search_log_written'    => false,
                'classroom_isolated_from_customers' => true,
            ),
        );
    }

    private static function lesson_learning_progress($lesson_key) {
        global $wpdb;
        $lesson_key = sanitize_key((string) $lesson_key);
        if (!$lesson_key) {
            return array();
        }

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT q.module_no,
                    q.question_type,
                    COUNT(q.id) AS total,
                    COALESCE(SUM(CASE WHEN r.status = 'answered' THEN 1 ELSE 0 END), 0) AS answered,
                    COALESCE(SUM(CASE WHEN r.evaluation_status = 'pass_top1' THEN 1 ELSE 0 END), 0) AS pass_top1,
                    COALESCE(SUM(CASE WHEN r.evaluation_status IN ('pass_top1','pass_top3') THEN 1 ELSE 0 END), 0) AS pass_top3,
                    COALESCE(SUM(CASE WHEN r.evaluation_status IN ('pass_top1','pass_top3','pass_top8') THEN 1 ELSE 0 END), 0) AS pass_any,
                    COALESCE(SUM(CASE WHEN r.evaluation_status = 'fail' THEN 1 ELSE 0 END), 0) AS failed,
                    COALESCE(SUM(CASE WHEN r.status = 'error' THEN 1 ELSE 0 END), 0) AS errors,
                    COALESCE(SUM(CASE WHEN r.status = 'answered' AND r.result_count > 0 THEN 1 ELSE 0 END), 0) AS with_results,
                    COALESCE(SUM(CASE WHEN r.status = 'answered' AND r.result_count = 0 THEN 1 ELSE 0 END), 0) AS zero_results,
                    COALESCE(SUM(CASE WHEN r.execution_ms IS NOT NULL THEN r.execution_ms ELSE 0 END), 0) AS execution_ms_total,
                    COALESCE(SUM(CASE WHEN r.execution_ms IS NOT NULL THEN 1 ELSE 0 END), 0) AS execution_count,
                    MIN(r.created_at) AS first_run_at,
                    MAX(r.created_at) AS last_run_at
             FROM " . self::questions_table() . " q
             LEFT JOIN " . self::runs_table() . " r
               ON r.question_id = q.id
              AND r.lesson_key = q.lesson_key
             WHERE q.lesson_key = %s
               AND q.enabled = 1
             GROUP BY q.module_no, q.question_type
             ORDER BY q.module_no ASC, q.question_type ASC",
            $lesson_key
        ), ARRAY_A);

        $modules = array();
        $question_types = array();
        $execution_ms_total = 0.0;
        $execution_count = 0;
        $first_run_at = '';
        $last_run_at = '';

        foreach ($rows as $row) {
            $module_no = absint($row['module_no'] ?? 0);
            $question_type = sanitize_key((string) ($row['question_type'] ?? 'other')) ?: 'other';
            if (!isset($modules[$module_no])) {
                $modules[$module_no] = array(
                    'module_no'          => $module_no,
                    'total'              => 0,
                    'answered'           => 0,
                    'pass_top1'          => 0,
                    'pass_top3'          => 0,
                    'pass_any'           => 0,
                    'failed'             => 0,
                    'errors'             => 0,
                    'with_results'       => 0,
                    'zero_results'       => 0,
                    'execution_ms_total' => 0.0,
                    'execution_count'    => 0,
                    'first_run_at'       => '',
                    'last_run_at'        => '',
                    'question_types'     => array(),
                );
            }
            if (!isset($question_types[$question_type])) {
                $question_types[$question_type] = array(
                    'question_type'       => $question_type,
                    'total'               => 0,
                    'answered'            => 0,
                    'pass_top1'           => 0,
                    'pass_top3'           => 0,
                    'pass_any'            => 0,
                    'failed'              => 0,
                    'errors'              => 0,
                    'with_results'        => 0,
                    'zero_results'        => 0,
                    'execution_ms_total'  => 0.0,
                    'execution_count'     => 0,
                    'modules'             => array(),
                );
            }

            $metrics = array(
                'total'              => absint($row['total'] ?? 0),
                'answered'           => absint($row['answered'] ?? 0),
                'pass_top1'          => absint($row['pass_top1'] ?? 0),
                'pass_top3'          => absint($row['pass_top3'] ?? 0),
                'pass_any'           => absint($row['pass_any'] ?? 0),
                'failed'             => absint($row['failed'] ?? 0),
                'errors'             => absint($row['errors'] ?? 0),
                'with_results'       => absint($row['with_results'] ?? 0),
                'zero_results'       => absint($row['zero_results'] ?? 0),
                'execution_ms_total' => (float) ($row['execution_ms_total'] ?? 0),
                'execution_count'    => absint($row['execution_count'] ?? 0),
                'first_run_at'       => (string) ($row['first_run_at'] ?? ''),
                'last_run_at'        => (string) ($row['last_run_at'] ?? ''),
            );

            foreach (array('total','answered','pass_top1','pass_top3','pass_any','failed','errors','with_results','zero_results','execution_count') as $key) {
                $modules[$module_no][$key] += absint($metrics[$key]);
                $question_types[$question_type][$key] += absint($metrics[$key]);
            }
            $modules[$module_no]['execution_ms_total'] += (float) $metrics['execution_ms_total'];
            $question_types[$question_type]['execution_ms_total'] += (float) $metrics['execution_ms_total'];
            $modules[$module_no]['first_run_at'] = self::progress_min_datetime($modules[$module_no]['first_run_at'], $metrics['first_run_at']);
            $modules[$module_no]['last_run_at'] = self::progress_max_datetime($modules[$module_no]['last_run_at'], $metrics['last_run_at']);
            $modules[$module_no]['question_types'][$question_type] = array(
                'total'          => $metrics['total'],
                'answered'       => $metrics['answered'],
                'pass_any'       => $metrics['pass_any'],
                'pass_any_ratio' => $metrics['answered'] > 0 ? round($metrics['pass_any'] / $metrics['answered'], 4) : null,
            );
            $question_types[$question_type]['modules'][$module_no] = array(
                'module_no'      => $module_no,
                'answered'       => $metrics['answered'],
                'pass_any'       => $metrics['pass_any'],
                'pass_top1'      => $metrics['pass_top1'],
                'pass_top3'      => $metrics['pass_top3'],
                'failed'         => $metrics['failed'],
                'errors'         => $metrics['errors'],
                'with_results'   => $metrics['with_results'],
                'zero_results'   => $metrics['zero_results'],
                'first_run_at'   => $metrics['first_run_at'],
                'last_run_at'    => $metrics['last_run_at'],
            );

            $execution_ms_total += (float) $metrics['execution_ms_total'];
            $execution_count += absint($metrics['execution_count']);
            $first_run_at = self::progress_min_datetime($first_run_at, $metrics['first_run_at']);
            $last_run_at = self::progress_max_datetime($last_run_at, $metrics['last_run_at']);
        }

        ksort($modules, SORT_NUMERIC);
        $checkpoints = array();
        $cumulative = array(
            'answered' => 0,
            'pass_top1' => 0,
            'pass_top3' => 0,
            'pass_any' => 0,
            'failed' => 0,
            'errors' => 0,
            'with_results' => 0,
            'zero_results' => 0,
        );
        foreach ($modules as $module) {
            foreach ($cumulative as $key => $value) {
                $cumulative[$key] += absint($module[$key] ?? 0);
            }
            $answered = absint($module['answered'] ?? 0);
            $total = absint($module['total'] ?? 0);
            $checkpoint = array(
                'module_no'                 => absint($module['module_no'] ?? 0),
                'total'                     => $total,
                'answered'                  => $answered,
                'completion_ratio'          => $total > 0 ? round($answered / $total, 4) : 0,
                'pass_top1'                 => absint($module['pass_top1'] ?? 0),
                'pass_top3'                 => absint($module['pass_top3'] ?? 0),
                'pass_any'                  => absint($module['pass_any'] ?? 0),
                'pass_any_ratio'            => $answered > 0 ? round(absint($module['pass_any'] ?? 0) / $answered, 4) : null,
                'failed'                    => absint($module['failed'] ?? 0),
                'errors'                    => absint($module['errors'] ?? 0),
                'with_results'              => absint($module['with_results'] ?? 0),
                'zero_results'              => absint($module['zero_results'] ?? 0),
                'result_presence_ratio'     => $answered > 0 ? round(absint($module['with_results'] ?? 0) / $answered, 4) : null,
                'avg_execution_ms'          => absint($module['execution_count'] ?? 0) > 0 ? round(((float) $module['execution_ms_total']) / absint($module['execution_count']), 3) : null,
                'first_run_at'              => (string) ($module['first_run_at'] ?? ''),
                'last_run_at'               => (string) ($module['last_run_at'] ?? ''),
                'question_types'            => (array) ($module['question_types'] ?? array()),
                'cumulative_answered'       => $cumulative['answered'],
                'cumulative_pass_any'       => $cumulative['pass_any'],
                'cumulative_pass_any_ratio' => $cumulative['answered'] > 0 ? round($cumulative['pass_any'] / $cumulative['answered'], 4) : null,
                'cumulative_failed'         => $cumulative['failed'],
                'cumulative_errors'         => $cumulative['errors'],
            );
            $checkpoints[] = $checkpoint;
        }

        $question_type_progress = array();
        ksort($question_types);
        foreach ($question_types as $question_type => $metrics) {
            ksort($metrics['modules'], SORT_NUMERIC);
            $series = array();
            foreach ($metrics['modules'] as $module_metrics) {
                $answered = absint($module_metrics['answered'] ?? 0);
                $series[] = array(
                    'module_no'      => absint($module_metrics['module_no'] ?? 0),
                    'answered'       => $answered,
                    'pass_any'       => absint($module_metrics['pass_any'] ?? 0),
                    'pass_any_ratio' => $answered > 0 ? round(absint($module_metrics['pass_any'] ?? 0) / $answered, 4) : null,
                    'failed'         => absint($module_metrics['failed'] ?? 0),
                    'errors'         => absint($module_metrics['errors'] ?? 0),
                    'with_results'   => absint($module_metrics['with_results'] ?? 0),
                    'zero_results'   => absint($module_metrics['zero_results'] ?? 0),
                    'first_run_at'   => (string) ($module_metrics['first_run_at'] ?? ''),
                    'last_run_at'    => (string) ($module_metrics['last_run_at'] ?? ''),
                );
            }
            $answered = absint($metrics['answered'] ?? 0);
            $question_type_progress[$question_type] = array(
                'total'              => absint($metrics['total'] ?? 0),
                'answered'           => $answered,
                'pass_top1'          => absint($metrics['pass_top1'] ?? 0),
                'pass_top3'          => absint($metrics['pass_top3'] ?? 0),
                'pass_any'           => absint($metrics['pass_any'] ?? 0),
                'pass_any_ratio'     => $answered > 0 ? round(absint($metrics['pass_any'] ?? 0) / $answered, 4) : null,
                'failed'             => absint($metrics['failed'] ?? 0),
                'errors'             => absint($metrics['errors'] ?? 0),
                'with_results'       => absint($metrics['with_results'] ?? 0),
                'zero_results'       => absint($metrics['zero_results'] ?? 0),
                'avg_execution_ms'   => absint($metrics['execution_count'] ?? 0) > 0 ? round(((float) $metrics['execution_ms_total']) / absint($metrics['execution_count']), 3) : null,
                'trend'              => self::progress_trend_from_checkpoints($series),
                'module_series'      => $series,
            );
        }

        $summary = self::lesson_summary($lesson_key);
        $answered_total = absint($summary['answered'] ?? 0);
        $wall_seconds = self::progress_elapsed_seconds($first_run_at, $last_run_at);
        $active_seconds = $execution_ms_total > 0 ? $execution_ms_total / 1000 : 0;

        return array(
            'version' => self::PROGRESS_REPORT_VERSION,
            'measurement' => 'observational_run_history',
            'retroactive' => true,
            'checkpoint_unit' => 'module',
            'summary' => array(
                'modules_total'             => count($modules),
                'modules_with_activity'     => count(array_filter($checkpoints, static function ($row) { return absint($row['answered'] ?? 0) > 0; })),
                'answered'                  => $answered_total,
                'current_pass_any_ratio'    => $answered_total > 0 ? round(absint($summary['pass_any'] ?? 0) / $answered_total, 4) : null,
                'first_run_at'              => $first_run_at,
                'last_run_at'               => $last_run_at,
                'wall_elapsed_seconds'      => $wall_seconds,
                'active_execution_seconds'  => round($active_seconds, 3),
                'answered_per_wall_minute'   => $wall_seconds > 0 ? round($answered_total / ($wall_seconds / 60), 2) : null,
                'questions_per_active_execution_minute' => $active_seconds > 0 ? round($answered_total / ($active_seconds / 60), 2) : null,
                'avg_execution_ms'          => $execution_count > 0 ? round($execution_ms_total / $execution_count, 3) : null,
                'trend'                     => self::progress_trend_from_checkpoints($checkpoints),
            ),
            'search_strategies' => self::progress_search_strategy_summary($lesson_key),
            'question_types' => $question_type_progress,
            'checkpoints' => $checkpoints,
        );
    }

    private static function progress_search_strategy_summary($lesson_key) {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT COALESCE(NULLIF(search_strategy, ''), 'unknown') AS strategy,
                    COUNT(*) AS uses,
                    COALESCE(SUM(CASE WHEN result_count > 0 THEN 1 ELSE 0 END), 0) AS with_results,
                    COALESCE(SUM(CASE WHEN result_count = 0 THEN 1 ELSE 0 END), 0) AS zero_results,
                    AVG(execution_ms) AS avg_execution_ms
             FROM " . self::runs_table() . "
             WHERE lesson_key = %s
               AND status = 'answered'
             GROUP BY COALESCE(NULLIF(search_strategy, ''), 'unknown')
             ORDER BY uses DESC, strategy ASC",
            $lesson_key
        ), ARRAY_A);
        $out = array();
        foreach ($rows as $row) {
            $strategy = sanitize_key((string) ($row['strategy'] ?? 'unknown')) ?: 'unknown';
            $out[$strategy] = array(
                'uses'             => absint($row['uses'] ?? 0),
                'with_results'     => absint($row['with_results'] ?? 0),
                'zero_results'     => absint($row['zero_results'] ?? 0),
                'avg_execution_ms' => isset($row['avg_execution_ms']) ? round((float) $row['avg_execution_ms'], 3) : null,
            );
        }
        return $out;
    }

    private static function progress_trend_from_checkpoints($checkpoints) {
        $active = array_values(array_filter((array) $checkpoints, static function ($row) {
            return absint($row['answered'] ?? 0) > 0;
        }));
        $count = count($active);
        if ($count < 1) {
            return array(
                'signal' => 'insufficient_data',
                'baseline_pass_any_ratio' => null,
                'recent_pass_any_ratio' => null,
                'delta_pass_any_pp' => null,
                'baseline_modules' => array(),
                'recent_modules' => array(),
            );
        }

        $width = min(3, max(1, (int) floor($count / 3)));
        $baseline_rows = array_slice($active, 0, $width);
        $recent_rows = array_slice($active, -$width);
        $baseline = self::progress_weighted_ratio($baseline_rows);
        $recent = self::progress_weighted_ratio($recent_rows);
        $delta_pp = (null !== $baseline && null !== $recent) ? round(($recent - $baseline) * 100, 2) : null;
        $signal = 'insufficient_data';
        if ($count >= 2 && null !== $delta_pp) {
            if ($delta_pp >= 2.0) {
                $signal = 'improving';
            } elseif ($delta_pp <= -2.0) {
                $signal = 'declining';
            } else {
                $signal = 'stable';
            }
        }

        return array(
            'signal'                  => $signal,
            'baseline_pass_any_ratio' => null === $baseline ? null : round($baseline, 4),
            'recent_pass_any_ratio'   => null === $recent ? null : round($recent, 4),
            'delta_pass_any_pp'       => $delta_pp,
            'baseline_modules'        => array_values(array_map(static function ($row) { return absint($row['module_no'] ?? 0); }, $baseline_rows)),
            'recent_modules'          => array_values(array_map(static function ($row) { return absint($row['module_no'] ?? 0); }, $recent_rows)),
            'window_size_modules'     => $width,
            'active_module_count'     => $count,
            'causal_attribution'      => false,
        );
    }

    private static function progress_weighted_ratio($rows) {
        $answered = 0;
        $passed = 0;
        foreach ((array) $rows as $row) {
            $answered += absint($row['answered'] ?? 0);
            $passed += absint($row['pass_any'] ?? 0);
        }
        return $answered > 0 ? $passed / $answered : null;
    }

    private static function progress_min_datetime($a, $b) {
        $a = (string) $a;
        $b = (string) $b;
        if ('' === $a) return $b;
        if ('' === $b) return $a;
        return strcmp($a, $b) <= 0 ? $a : $b;
    }

    private static function progress_max_datetime($a, $b) {
        $a = (string) $a;
        $b = (string) $b;
        if ('' === $a) return $b;
        if ('' === $b) return $a;
        return strcmp($a, $b) >= 0 ? $a : $b;
    }

    private static function progress_elapsed_seconds($first, $last) {
        $first_ts = $first ? strtotime((string) $first) : false;
        $last_ts = $last ? strtotime((string) $last) : false;
        if (false === $first_ts || false === $last_ts || $last_ts < $first_ts) {
            return 0;
        }
        return (int) ($last_ts - $first_ts);
    }

    private static function build_lesson_export_document($lesson_key) {
        $lesson_key = sanitize_key((string) $lesson_key);
        $lesson = self::lesson_row($lesson_key);
        $definition = self::lesson_definition($lesson_key);
        if (!$lesson || !$definition) {
            return null;
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

        return array(
            'schema' => array(
                'name'    => 'seo_dependiente_academy_lesson',
                'version' => 2,
            ),
            'generated_at' => current_time('c'),
            'site' => array(
                'home_url'              => home_url('/'),
                'dependiente_version'   => defined('SEO_DEPENDIENTE_VERSION') ? SEO_DEPENDIENTE_VERSION : '',
                'trainer_db_version'    => self::DB_VERSION,
            ),
            'lesson' => array(
                'key'               => $lesson_key,
                'order'             => absint($definition['order']),
                'title'             => (string) $definition['title'],
                'status'            => (string) ($lesson['status'] ?? ''),
                'snapshot_before'   => absint($lesson['snapshot_before'] ?? 0),
                'snapshot_after'    => absint($lesson['snapshot_after'] ?? 0),
                'source_signature'  => (string) ($lesson['source_signature'] ?? ''),
                'module_count'      => absint($lesson['module_count'] ?? 0),
                'item_count'        => absint($lesson['item_count'] ?? 0),
                'curriculum_version'=> self::CURRICULUM_VERSION,
            ),
            'summary' => self::lesson_summary($lesson_key),
            'learning_progress' => self::lesson_learning_progress($lesson_key),
            'curriculum_audit' => self::lesson_curriculum_audit($items),
            'modules' => self::module_progress($lesson_key),
            'notes' => array(
                'curriculum_guided'           => true,
                'free_question_bank_enabled'  => false,
                'customer_search_log_written' => false,
                'observational_learning_used' => false,
                'ground_truth_source'         => 'catalog_index_and_canonical_taxonomy',
                'lesson_query_knowledge_scope'=> self::lesson_uses_classroom_stage($lesson_key) ? 'active_plus_current_academy_stage' : 'active_only',
                'classroom_isolated_from_customers' => true,
                'lesson_queries_use_snapshot_before' => !self::lesson_uses_classroom_stage($lesson_key),
                'canonical_knowledge_promoted_on_completion' => true,
                'l1_to_l7_are_training'       => true,
                'l8_is_closed_exam'           => true,
                'progress_reconstructed_from_runs' => true,
                'progress_is_observational_not_causal' => true,
            ),
            'items' => $items,
        );
    }

    private static function course_export_summary($lessons) {
        $summary = self::empty_summary();
        $summary['lessons_prepared'] = 0;
        $summary['lessons_completed'] = 0;
        foreach ((array) $lessons as $lesson_key => $lesson) {
            if (absint($lesson['item_count'] ?? 0) < 1) {
                continue;
            }
            $summary['lessons_prepared']++;
            if ('completed' === sanitize_key((string) ($lesson['status'] ?? ''))) {
                $summary['lessons_completed']++;
            }
            $lesson_summary = self::lesson_summary($lesson_key);
            foreach (array('total', 'answered', 'pass_top1', 'pass_top3', 'pass_any', 'failed', 'errors') as $key) {
                $summary[$key] = absint($summary[$key] ?? 0) + absint($lesson_summary[$key] ?? 0);
            }
        }
        return $summary;
    }

    private static function course_export_lesson_index($definitions, $lessons) {
        $index = array();
        foreach ((array) $definitions as $lesson_key => $definition) {
            $lesson = isset($lessons[$lesson_key]) ? $lessons[$lesson_key] : array();
            $index[] = array(
                'key'              => (string) $lesson_key,
                'order'            => absint($definition['order'] ?? 0),
                'title'            => (string) ($definition['title'] ?? ''),
                'status'           => (string) ($lesson['status'] ?? 'locked'),
                'snapshot_before'  => absint($lesson['snapshot_before'] ?? 0),
                'snapshot_after'   => absint($lesson['snapshot_after'] ?? 0),
                'source_signature' => (string) ($lesson['source_signature'] ?? ''),
                'module_count'     => absint($lesson['module_count'] ?? 0),
                'item_count'       => absint($lesson['item_count'] ?? 0),
                'summary'          => self::lesson_summary($lesson_key),
            );
        }
        return $index;
    }

    private static function lesson_curriculum_audit($items) {
        $audit = array(
            'questions'        => count((array) $items),
            'question_types'   => array(),
            'difficulty'       => array(),
            'feature_kinds'    => array(),
            'diagnostic_types' => array(),
        );
        foreach ((array) $items as $item) {
            $question_type = sanitize_key((string) ($item['question_type'] ?? 'other')) ?: 'other';
            $audit['question_types'][$question_type] = 1 + absint($audit['question_types'][$question_type] ?? 0);

            $expected = is_array($item['expected'] ?? null) ? $item['expected'] : array();
            $difficulty = sanitize_key((string) ($expected['academy']['difficulty'] ?? ''));
            if ($difficulty) {
                $audit['difficulty'][$difficulty] = 1 + absint($audit['difficulty'][$difficulty] ?? 0);
            }
            foreach ((array) ($expected['features'] ?? array()) as $feature) {
                $kind = sanitize_key((string) ($feature['kind'] ?? 'other')) ?: 'other';
                $audit['feature_kinds'][$kind] = 1 + absint($audit['feature_kinds'][$kind] ?? 0);
            }

            $run = is_array($item['run'] ?? null) ? $item['run'] : array();
            $evaluation = is_array($run['evaluation'] ?? null) ? $run['evaluation'] : array();
            $diagnostic = sanitize_key((string) ($evaluation['diagnostic_type'] ?? ''));
            if ($diagnostic) {
                $audit['diagnostic_types'][$diagnostic] = 1 + absint($audit['diagnostic_types'][$diagnostic] ?? 0);
            }
        }
        foreach (array('question_types', 'difficulty', 'feature_kinds', 'diagnostic_types') as $key) {
            ksort($audit[$key]);
        }
        return $audit;
    }

    /**
     * Las consultas de Academia usan el motor completo pero nunca entran en el
     * log de clientes. Este filtro se instala solo durante la llamada REST.
     */
    public static function skip_customer_search_log($should_log) {
        return false;
    }

    /**
     * Academia puede pedir al API un diagnóstico interno de recuperación. El
     * filtro solo existe durante sus propias llamadas REST, por lo que esos datos
     * no se exponen en las búsquedas normales de clientes.
     */
    public static function expose_search_diagnostic($expose = false) {
        return true;
    }

    /**
     * El aula de L1-L7 puede usar las reglas preparadas de la lección actual.
     * Esta bandera solo se instala alrededor de la petición REST de Academia,
     * por lo que nunca altera las búsquedas normales de clientes.
     */
    public static function include_academy_stage_rules($include = false) {
        return true;
    }

    /**
     * En L6 una FAQ solo cuenta como aprendida si Dependiente llega a ella
     * siguiendo el producto/categoría propietario. La coincidencia textual global
     * queda desactivada durante estas preguntas de Academia.
     */
    public static function disable_faq_text_fallback($allow = true) {
        return false;
    }

    private static function lesson_uses_classroom_stage($lesson_key) {
        $lesson_key = sanitize_key((string) $lesson_key);
        return '' !== $lesson_key
            && 'v2_l8_exam' !== $lesson_key
            && 0 !== strpos($lesson_key, self::LAB_PREFIX);
    }

    private static function lesson_definitions() {
        return array(
            'v2_l1_categories' => array(
                'order'       => 1,
                'title'       => 'Mapa del catálogo',
                'description' => 'Categorías, jerarquía y lenguaje básico con el que el cliente entiende las familias de producto.',
                'module_size' => 25,
                'source'      => 'Categorías WooCommerce con productos',
                'min_pass_any'=> 0.50,
            ),
            'v2_l2_inventory' => array(
                'order'       => 2,
                'title'       => 'Inventario representativo',
                'description' => 'Comprueba reconocimiento del catálogo con una muestra diversa por categoría, TIPO/ROL y marca, evitando repetir miles de productos equivalentes.',
                'module_size' => 35,
                'source'      => 'Muestra semánticamente diversa del índice de productos',
                'min_pass_any'=> 0.45,
            ),
            'v2_l3_type_role' => array(
                'order'       => 3,
                'title'       => 'TIPO y ROL',
                'description' => 'Aprende las rutas canónicas TIPO → ROL y la identidad semántica principal del catálogo.',
                'module_size' => 25,
                'source'      => 'Vocabulary canónico y bridge TIPO → ROL',
                'min_pass_any'=> 0.50,
            ),
            'v2_l4_features' => array(
                'order'       => 4,
                'title'       => 'Características y necesidades',
                'description' => 'Aplicaciones, plataformas, subtipos, atributos y etiquetas se combinan para resolver necesidades sin depender del nombre exacto.',
                'module_size' => 30,
                'source'      => 'Índice semántico, atributos y etiquetas',
                'min_pass_any'=> 0.45,
            ),
            'v2_l5_editorial' => array(
                'order'       => 5,
                'title'       => 'Posts y páginas con Vocabulary común',
                'description' => 'Relaciona contenido editorial por su vocabulario canónico, tanto para posts como para páginas.',
                'module_size' => 25,
                'source'      => 'Posts/páginas publicados + seo_object_vocabulary',
                'min_pass_any'=> 0.35,
            ),
            'v2_l6_faq' => array(
                'order'       => 6,
                'title'       => 'FAQs contextualizadas',
                'description' => 'Cada FAQ se aprende junto a su propietario: página/hub, categoría o producto. El propietario aporta el contexto semántico.',
                'module_size' => 30,
                'source'      => 'seo_faq + propietario + Vocabulary del propietario',
                'min_pass_any'=> 0.45,
            ),
            'v2_l7_cross' => array(
                'order'       => 7,
                'title'       => 'Relaciones cruzadas',
                'description' => 'Comprueba que un mismo concepto del Vocabulary conecta productos con posts y páginas relacionados.',
                'module_size' => 25,
                'source'      => 'Conceptos usados simultáneamente por catálogo y contenido',
                'min_pass_any'=> 0.30,
            ),
            'v2_l8_exam' => array(
                'order'       => 8,
                'title'       => 'Examen integrado y regresión',
                'description' => 'Muestra equilibrada de las siete lecciones anteriores para detectar regresiones antes de dar por terminada la formación.',
                'module_size' => 30,
                'source'      => 'Muestra de preguntas ya preparadas en L1–L7',
                'min_pass_any'=> 0.40,
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
        global $wpdb;
        $status=class_exists('SEO_Dependiente_Index')?SEO_Dependiente_Index::status():array();
        $indexed=absint($status['indexed']??0);$published=absint($status['published']??0);$last_full=trim((string)($status['last_full']??''));
        $posts=absint($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish'"));
        $pages=absint($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish'"));
        $faq_table=$wpdb->prefix.'seo_faq';$faqs=self::table_exists($faq_table)?absint($wpdb->get_var("SELECT COUNT(*) FROM {$faq_table} WHERE active=1 AND object_type IN (1,2,3)")):0;
        $ready=class_exists('WooCommerce')&&$indexed>0&&''!==$last_full;
        if(!class_exists('WooCommerce')){$message='WooCommerce no está disponible. La Academia necesita el catálogo.';}
        elseif($published<1){$message='No hay productos publicados que puedan formar parte del temario.';$ready=false;}
        elseif($indexed<1||''===$last_full){$message='Reindexa el catálogo completo antes de comenzar. Academia v2.1 necesita un snapshot de productos cerrado.';}
        elseif($indexed<$published){$message='Hay '.number_format_i18n($indexed).' productos indexados de '.number_format_i18n($published).' publicados. Revisa las exclusiones antes de formar.';}
        else{$message='Base preparada: '.number_format_i18n($indexed).' productos, '.number_format_i18n($posts).' posts, '.number_format_i18n($pages).' páginas y '.number_format_i18n($faqs).' FAQs activas.';}
        return array('ready'=>$ready,'indexed'=>$indexed,'published'=>$published,'last_full'=>$last_full,'posts'=>$posts,'pages'=>$pages,'faqs'=>$faqs,'fingerprint'=>self::catalog_fingerprint(),'message'=>$message);
    }

    private static function basic_curriculum_completed($lessons = null) {
        if (null === $lessons) {
            $lessons = self::lessons_by_key();
        }
        foreach (self::lesson_definitions() as $key => $definition) {
            if ('completed' !== (string) ($lessons[$key]['status'] ?? '')) {
                return false;
            }
        }
        return true;
    }

    private static function render_question_lab($unlocked, $batch, $auto_running) {
        if (!$unlocked) {
            ?>
            <section class="postbox seo-dependiente-admin__box seo-dependiente-trainer__manual-locked">
                <h2 class="seo-dependiente-admin__box-title">Laboratorio de preguntas bloqueado</h2>
                <p>Cuando termine la formación básica se desbloqueará un laboratorio para probar al Dependiente con preguntas libres. Podrás pegarlas en un campo de texto o cargar lotes desde TXT, CSV o JSON.</p>
                <p class="description">El Laboratorio será diagnóstico: sus consultas estarán aisladas del tráfico de clientes y no modificarán el conocimiento por sí solas.</p>
            </section>
            <?php
            return;
        }

        $batch_key = is_array($batch) ? (string) ($batch['batch_key'] ?? '') : '';
        $summary = $batch_key ? self::lab_summary($batch_key) : self::empty_lab_summary();
        $runs = $batch_key ? self::recent_runs($batch_key, self::RECENT_RUN_LIMIT) : array();
        $done = absint($summary['total']) > 0 && absint($summary['answered']) >= absint($summary['total']);
        ?>
        <section class="postbox seo-dependiente-admin__box seo-dependiente-trainer__lab" data-trainer-lab data-lab-batch-key="<?php echo esc_attr($batch_key); ?>">
            <div class="seo-dependiente-trainer__section-head">
                <div>
                    <h2 class="seo-dependiente-admin__box-title">Laboratorio de preguntas</h2>
                    <p>Prueba el conocimiento ya formado sin contaminar el aprendizaje. Puedes lanzar una pregunta, pegar varias líneas o importar un archivo completo.</p>
                </div>
                <span class="seo-dependiente-trainer__isolation">Solo diagnóstico · no aprende</span>
            </div>

            <div class="seo-dependiente-trainer__lab-grid">
                <div>
                    <label for="seo-dependiente-lab-questions"><strong>Preguntas</strong></label>
                    <textarea id="seo-dependiente-lab-questions" class="large-text" rows="7" data-trainer-lab-text placeholder="Una pregunta por línea. También puedes escribir una sola pregunta."></textarea>
                    <p class="description">Las líneas vacías se ignoran. Las preguntas repetidas dentro del mismo lote se eliminan.</p>
                </div>
                <div class="seo-dependiente-trainer__lab-upload">
                    <label for="seo-dependiente-lab-file"><strong>O cargar archivo</strong></label>
                    <input id="seo-dependiente-lab-file" type="file" data-trainer-lab-file accept=".txt,.csv,.json,text/plain,text/csv,application/json">
                    <p class="description"><strong>TXT:</strong> una pregunta por línea. <strong>CSV:</strong> columnas <code>question</code> y opcional <code>mode</code>. <strong>JSON:</strong> array de textos u objetos con <code>question</code> y <code>mode</code>. Máximo 5.000 preguntas / 2 MB.</p>
                    <label for="seo-dependiente-lab-mode"><strong>Modo por defecto</strong></label>
                    <select id="seo-dependiente-lab-mode" data-trainer-lab-mode>
                        <option value="need">Necesidad</option>
                        <option value="product">Producto</option>
                        <option value="tool">Herramienta</option>
                        <option value="compare">Comparar</option>
                    </select>
                </div>
            </div>

            <div class="seo-dependiente-trainer__lab-actions">
                <button type="button" class="button" data-trainer-lab-import <?php disabled($auto_running); ?>>Preparar nuevo lote</button>
                <?php if ($batch_key) : ?>
                    <button type="button" class="button button-primary" data-trainer-lab-run <?php disabled($auto_running || $done); ?>><?php echo $done ? 'Lote completado' : 'Lanzar lote completo'; ?></button>
                    <button type="button" class="button" data-trainer-lab-export>Descargar resultados JSON</button>
                <?php endif; ?>
            </div>
            <p class="description" data-trainer-lab-status aria-live="polite"><?php echo $batch_key ? esc_html('Último lote: ' . number_format_i18n(absint($summary['answered'])) . ' de ' . number_format_i18n(absint($summary['total'])) . ' preguntas ejecutadas.') : 'Prepara un lote para empezar.'; ?></p>

            <?php if ($batch_key) : ?>
                <div class="seo-dependiente-trainer__lab-progress">
                    <div class="seo-dependiente-trainer__progress"><div class="seo-dependiente-trainer__progress-bar" data-trainer-lab-progress-bar style="width:<?php echo esc_attr(absint($summary['total']) ? min(100, round((absint($summary['answered']) / absint($summary['total'])) * 100)) : 0); ?>%"></div></div>
                    <div class="seo-dependiente-trainer__current-summary">
                        <span><strong data-trainer-lab-summary="answered"><?php echo esc_html(number_format_i18n(absint($summary['answered']))); ?></strong> / <strong data-trainer-lab-summary="total"><?php echo esc_html(number_format_i18n(absint($summary['total']))); ?></strong> ejecutadas</span>
                        <span><strong data-trainer-lab-summary="with_results"><?php echo esc_html(number_format_i18n(absint($summary['with_results']))); ?></strong> con resultados</span>
                        <span><strong data-trainer-lab-summary="zero_results"><?php echo esc_html(number_format_i18n(absint($summary['zero_results']))); ?></strong> sin resultados</span>
                        <span><strong data-trainer-lab-summary="errors"><?php echo esc_html(number_format_i18n(absint($summary['errors']))); ?></strong> errores técnicos</span>
                    </div>
                </div>
                <div class="seo-dependiente-trainer__table-wrap seo-dependiente-trainer__lab-results">
                    <?php self::render_lab_runs_table($runs); ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }

    private static function render_lab_runs_table($runs) {
        ?>
        <table class="widefat striped seo-dependiente-trainer__runs">
            <thead><tr><th>Pregunta</th><th>Estado</th><th>Respuesta</th></tr></thead>
            <tbody data-trainer-lab-run-body>
            <?php if (!$runs) : ?>
                <tr data-trainer-lab-run-empty><td colspan="3">Este lote todavía no se ha ejecutado.</td></tr>
            <?php else : ?>
                <?php foreach ($runs as $row) : self::render_lab_run_row(self::present_run($row)); endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private static function render_lab_run_row($row) {
        $status = (string) ($row['status'] ?? '');
        $results = (array) ($row['top_results'] ?? array());
        ?>
        <tr>
            <td><strong><?php echo esc_html((string) ($row['question'] ?? '')); ?></strong><?php if (!empty($row['search_strategy'])) : ?><div class="description">Estrategia: <code><?php echo esc_html((string) $row['search_strategy']); ?></code></div><?php endif; ?></td>
            <td><span class="seo-dependiente-trainer__status <?php echo 'error' === $status ? 'is-error' : 'is-neutral'; ?>"><?php echo 'error' === $status ? 'Error técnico' : 'Observada'; ?></span><?php if (!empty($row['error_message'])) : ?><div class="description"><?php echo esc_html((string) $row['error_message']); ?></div><?php endif; ?></td>
            <td>
                <?php if ($results) : ?>
                    <ol class="seo-dependiente-trainer__answer-list">
                    <?php foreach (array_slice($results, 0, 5) as $result) : ?>
                        <li><strong><?php echo esc_html((string) ($result['title'] ?? '')); ?></strong><?php if (!empty($result['reasons'])) : ?><span><?php echo esc_html(implode(' · ', (array) $result['reasons'])); ?></span><?php endif; ?></li>
                    <?php endforeach; ?>
                    </ol>
                <?php else : ?>
                    <span class="description">Sin resultados devueltos.</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private static function render_training_mode($state, $preflight, $current_key) {
        $state = wp_parse_args((array) $state, self::default_auto_state());
        $running = self::is_auto_running($state);
        $status = sanitize_key((string) ($state['status'] ?? 'manual'));
        $last_message = trim((string) ($state['last_message'] ?? ''));
        $last_error = trim((string) ($state['last_error'] ?? ''));
        if ($last_error) {
            $message = 'Automático detenido: ' . $last_error;
        } elseif ($last_message) {
            $message = $last_message;
        } elseif ($running) {
            $message = 'La Academia continuará lección por lección y módulo por módulo sin necesitar esta pantalla abierta.';
        } else {
            $message = 'Modo manual: tú decides cuándo preparar y ejecutar cada módulo.';
        }
        $badge_label = $running ? 'Automático activo' : ('error' === $status ? 'Automático pausado' : ('completed' === $status ? 'Completado' : 'Manual'));
        ?>
        <section class="postbox seo-dependiente-admin__box seo-dependiente-trainer__automation <?php echo $running ? 'is-running' : 'is-manual'; ?>" data-trainer-automation>
            <div class="seo-dependiente-trainer__section-head">
                <div>
                    <h2 class="seo-dependiente-admin__box-title">Modo de formación</h2>
                    <p>Elige si quieres supervisar cada paso o dejar que la Academia complete todas las lecciones disponibles de forma secuencial.</p>
                </div>
                <span class="seo-dependiente-trainer__automation-badge <?php echo $running ? 'is-running' : ''; ?>" data-trainer-auto-badge><?php echo esc_html($badge_label); ?></span>
            </div>
            <div class="seo-dependiente-trainer__automation-actions">
                <button type="button" class="button button-primary" data-trainer-mode-auto <?php disabled($running || !$preflight['ready'] || !$current_key); ?>>
                    Ejecutar todas las lecciones automáticamente
                </button>
                <button type="button" class="button" data-trainer-mode-manual <?php disabled(!$running); ?>>
                    Pasar a modo manual
                </button>
            </div>
            <p class="description" data-trainer-auto-status aria-live="polite"><?php echo esc_html($message); ?></p>
            <?php if ('error' === $status && $last_error) : ?>
                <div class="notice notice-error inline"><p>La ejecución automática se ha pausado para no saltarse ninguna lección. Corrige el problema y vuelve a pulsar «Ejecutar todas las lecciones automáticamente» para continuar desde el punto guardado.</p></div>
            <?php endif; ?>
        </section>
        <?php
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
                <?php if (absint($row['item_count'] ?? 0) > 0) : ?>
                    <div class="seo-dependiente-trainer__lesson-report">
                        <button type="button" class="button button-small" data-trainer-export-progress-key="<?php echo esc_attr($lesson_key); ?>">Progreso</button>
                        <button type="button" class="button button-small" data-trainer-export-lesson-key="<?php echo esc_attr($lesson_key); ?>">Informe completo</button>
                    </div>
                <?php endif; ?>
            </div>
        </article>
        <?php
    }

    private static function render_current_lesson($lesson_key, $definition, $lesson, $preflight, $modules, $next_module, $summary, $auto_running = false) {
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
                    <button type="button" class="button button-primary button-hero" data-trainer-prepare-lesson <?php disabled($auto_running || !$preflight['ready']); ?>>
                        <?php echo $preparing ? 'Continuar preparación' : 'Preparar lección'; ?>
                    </button>
                <?php elseif ($prepared && $next_module > 0) : ?>
                    <button type="button" class="button button-primary button-hero" data-trainer-run-module <?php disabled($auto_running); ?>>
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
                    <span>Base de entrada <strong>snapshot <?php echo esc_html(number_format_i18n(absint($lesson['snapshot_before'] ?? 0))); ?></strong></span>
                    <span><?php echo 'v2_l8_exam' === $lesson_key ? 'Examen cerrado: solo conocimiento activo' : 'Aula aislada: conocimiento activo + academy_stage de esta lección'; ?></span>
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
        $evaluation = is_array($row['evaluation'] ?? null) ? $row['evaluation'] : array();
        $diagnostic = sanitize_key((string) ($evaluation['diagnostic_type'] ?? ''));
        $diagnostic_labels = array(
            'mastered'          => 'Conocimiento resuelto',
            'parser_gap'        => 'Fallo de interpretación',
            'retrieval_gap'              => 'Fallo de recuperación',
            'semantic_expansion_skipped' => 'Expansión semántica omitida',
            'semantic_candidates_filtered'=> 'Candidatos semánticos filtrados',
            'semantic_route_unresolved'   => 'Ruta semántica sin candidatos',
            'ranking_gap'                => 'Fallo de ranking/filtro',
            'clarification_gap' => 'Aclaración innecesaria',
            'curriculum_invalid'=> 'Pregunta a revisar',
            'technical_error'   => 'Error técnico',
            'observed'          => 'Observación',
        );
        $results = (array) ($row['top_results'] ?? array());
        ?>
        <tr>
            <td><strong><?php echo esc_html(number_format_i18n(absint($row['module_no'] ?? 0))); ?></strong></td>
            <td><strong><?php echo esc_html((string) ($row['question'] ?? '')); ?></strong><?php if (!empty($row['search_strategy'])) : ?><div class="description">Estrategia: <code><?php echo esc_html((string) $row['search_strategy']); ?></code></div><?php endif; ?></td>
            <td><span class="seo-dependiente-trainer__status <?php echo esc_attr($class); ?>"><?php echo esc_html($labels[$status] ?? ucfirst($status)); ?></span><?php if ($diagnostic) : ?><div class="description"><?php echo esc_html($diagnostic_labels[$diagnostic] ?? $diagnostic); ?></div><?php endif; ?><?php if (!empty($row['error_message'])) : ?><div class="description"><?php echo esc_html((string) $row['error_message']); ?></div><?php endif; ?></td>
            <td>
                <?php if ($results) : ?>
                    <ol class="seo-dependiente-trainer__answer-list">
                    <?php foreach (array_slice($results, 0, 5) as $result) : ?>
                        <li><strong><?php echo esc_html((string) ($result['title'] ?? '')); ?></strong><?php if (!empty($result['reasons'])) : ?><span><?php echo esc_html(implode(' · ', (array) $result['reasons'])); ?></span><?php endif; ?></li>
                    <?php endforeach; ?>
                    </ol>
                <?php else : ?>
                    <span class="description">Sin resultados devueltos.</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private static function lesson_source_total($lesson_key) {
        if ('v2_l1_categories' === $lesson_key) {
            $count = wp_count_terms(array('taxonomy' => 'product_cat', 'hide_empty' => true));
            return is_wp_error($count) ? 0 : absint($count);
        }
        if ('v2_l2_inventory' === $lesson_key) {
            return count(self::lesson2_sources());
        }
        if ('v2_l3_type_role' === $lesson_key) {
            return count(self::lesson3_sources());
        }
        if ('v2_l4_features' === $lesson_key) {
            return count(self::lesson4_sources());
        }
        if ('v2_l5_editorial' === $lesson_key) {
            return count(self::lesson5_sources());
        }
        if ('v2_l6_faq' === $lesson_key) {
            return count(self::lesson6_sources());
        }
        if ('v2_l7_cross' === $lesson_key) {
            return count(self::lesson7_sources());
        }
        if ('v2_l8_exam' === $lesson_key) {
            return count(self::lesson8_sources());
        }
        return 0;
    }

    private static function lesson_source_batch($lesson_key, $offset, $limit) {
        if ('v2_l1_categories' === $lesson_key) {
            return self::lesson1_batch($offset, $limit);
        }
        $map = array(
            'v2_l2_inventory' => 'lesson2_sources',
            'v2_l3_type_role' => 'lesson3_sources',
            'v2_l4_features'  => 'lesson4_sources',
            'v2_l5_editorial' => 'lesson5_sources',
            'v2_l6_faq'       => 'lesson6_sources',
            'v2_l7_cross'     => 'lesson7_sources',
            'v2_l8_exam'      => 'lesson8_sources',
        );
        if (isset($map[$lesson_key])) {
            $items = call_user_func(array(__CLASS__, $map[$lesson_key]));
            return array_slice((array) $items, max(0, absint($offset)), max(1, absint($limit)));
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
            $category_vocabulary = self::category_vocabulary_terms(absint($term->term_id));
            $rules = array(
                array('kind' => 'category_alias', 'id' => absint($term->term_id), 'label' => $name),
            );
            foreach ($category_vocabulary as $vocabulary_term) {
                $rules[] = array(
                    'kind'  => 'vocabulary_route',
                    'id'    => absint($vocabulary_term['id'] ?? 0),
                    'group' => sanitize_key((string) ($vocabulary_term['group'] ?? '')),
                    'slug'  => sanitize_title((string) ($vocabulary_term['slug'] ?? '')),
                    'label' => (string) ($vocabulary_term['label'] ?? ''),
                );
            }
            $items[] = array(
                'source_type' => 'category',
                'source_id'   => absint($term->term_id),
                'source_key'  => 'category:' . absint($term->term_id),
                'question_type' => 'category_identity',
                'mode'        => 'product',
                'question'    => '¿Qué productos del catálogo pertenecen a la categoría "' . $name . '"?',
                'expected'    => array(
                    'kind'                    => 'category',
                    'category_id'             => absint($term->term_id),
                    'category_name'           => $name,
                    'category_path'           => self::category_path($term),
                    'acceptable_category_ids' => $acceptable,
                    'category_vocabulary'     => $category_vocabulary,
                    'academy'                 => array('difficulty' => 'foundation', 'teaching_goal' => 'Aprender categoría, jerarquía y Vocabulary asociado.'),
                ),
                'rules' => $rules,
            );
        }
        return $items;
    }

    private static function category_vocabulary_terms($term_id) {
        static $map = null;
        global $wpdb;
        $term_id = absint($term_id);
        if (!$term_id) {
            return array();
        }
        if (null === $map) {
            $map = array();
            $objects = $wpdb->prefix . 'seo_object_vocabulary';
            $vocabulary = $wpdb->prefix . 'seo_vocabulary';
            if (self::table_exists($objects) && self::table_exists($vocabulary)) {
                $rows = (array) $wpdb->get_results(
                    "SELECT ov.object_id,v.id,v.semantic_group,v.slug,v.label
                     FROM {$objects} ov
                     INNER JOIN {$vocabulary} v ON v.id=ov.vocabulary_id AND v.active=1
                     WHERE ov.object_type='product_cat' AND ov.status=1
                       AND v.semantic_group IN ('tipo','rol','aplicacion','plataforma','subtipo')
                     ORDER BY ov.object_id,FIELD(v.semantic_group,'tipo','rol','aplicacion','plataforma','subtipo'),v.id",
                    ARRAY_A
                );
                foreach ($rows as $row) {
                    $object_id = absint($row['object_id'] ?? 0);
                    $id = absint($row['id'] ?? 0);
                    $group = sanitize_key((string) ($row['semantic_group'] ?? ''));
                    $slug = sanitize_title((string) ($row['slug'] ?? ''));
                    $label = trim((string) ($row['label'] ?? ''));
                    if (!$object_id || !$id || !$group || !$slug || !$label) {
                        continue;
                    }
                    if (!isset($map[$object_id])) {
                        $map[$object_id] = array();
                    }
                    $map[$object_id][] = array('id' => $id, 'group' => $group, 'slug' => $slug, 'label' => $label);
                }
            }
        }
        return array_slice((array) ($map[$term_id] ?? array()), 0, 4);
    }

    private static function lesson2_sources() {
        static $cache = null;
        if (null !== $cache) {
            return $cache;
        }
        global $wpdb;
        $cache = array();
        if (!class_exists('SEO_Dependiente_Index') || !SEO_Dependiente_Index::table_exists()) {
            return $cache;
        }
        $rows = (array) $wpdb->get_results(
            'SELECT product_id,title,brand_slug,categories_json,vocabulary_json FROM ' . SEO_Dependiente_Index::table() . ' ORDER BY product_id ASC',
            ARRAY_A
        );
        $seen = array();
        foreach ($rows as $row) {
            $product_id = absint($row['product_id'] ?? 0);
            $title = trim((string) ($row['title'] ?? ''));
            if (!$product_id || '' === $title) {
                continue;
            }
            $categories = self::decode_json($row['categories_json'] ?? '');
            $vocabulary = self::decode_json($row['vocabulary_json'] ?? '');
            $category_id = absint($categories[0]['id'] ?? 0);
            $tipo = sanitize_title((string) ($vocabulary['tipo'][0]['slug'] ?? ''));
            $rol = sanitize_title((string) ($vocabulary['rol'][0]['slug'] ?? ''));
            $brand = sanitize_title((string) ($row['brand_slug'] ?? ''));
            $signature = implode('|', array($category_id, $tipo, $rol, $brand));
            if ('0|||' === $signature) {
                $signature = 'product:' . $product_id;
            } elseif (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;
            $cache[] = array(
                'source_type'   => 'product',
                'source_id'     => $product_id,
                'source_key'    => 'product:' . $product_id,
                'question_type' => 'product_identity',
                'mode'          => 'product',
                'question'      => '¿Qué producto es "' . self::shorten($title, 300) . '"?',
                'expected'      => array('kind'=>'product','product_id'=>$product_id,'title'=>$title,'categories'=>$categories),
                'rules'         => array(),
            );
            if (count($cache) >= self::MAX_INVENTORY_SOURCES) {
                break;
            }
        }
        return $cache;
    }

    private static function lesson2_batch($offset, $limit) {
        return array_slice(self::lesson2_sources(), $offset, $limit);
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

    private static function lesson4_sources() {
        static $cache = null;
        if (null !== $cache) {
            return $cache;
        }
        global $wpdb;
        $cache = array();
        if (!class_exists('SEO_Dependiente_Index') || !SEO_Dependiente_Index::table_exists()) {
            return $cache;
        }

        $rows = (array) $wpdb->get_results(
            'SELECT product_id,vocabulary_json,tags_json,attributes_json FROM ' . SEO_Dependiente_Index::table() . ' ORDER BY product_id ASC',
            ARRAY_A
        );
        $tiers = array(
            'foundation' => array(),
            'combined'   => array(),
            'deep'       => array(),
        );
        $seen = array();

        foreach ($rows as $row) {
            foreach (self::feature_items_from_index_row($row) as $item) {
                $key = (string) ($item['source_key'] ?? '');
                if (!$key || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $difficulty = sanitize_key((string) ($item['expected']['academy']['difficulty'] ?? 'combined'));
                if (!isset($tiers[$difficulty])) {
                    $difficulty = 'combined';
                }
                $item['_academy_rank'] = sprintf('%u', crc32($key));
                $tiers[$difficulty][] = $item;
            }
        }

        $quota = max(1, (int) floor(self::MAX_FEATURE_SOURCES / 3));
        foreach ($tiers as $difficulty => $items) {
            usort($items, static function ($left, $right) {
                $a = (int) ($left['_academy_rank'] ?? 0);
                $b = (int) ($right['_academy_rank'] ?? 0);
                if ($a === $b) {
                    return strcmp((string) ($left['source_key'] ?? ''), (string) ($right['source_key'] ?? ''));
                }
                return $a <=> $b;
            });
            foreach (array_slice($items, 0, $quota) as $item) {
                unset($item['_academy_rank']);
                $cache[] = $item;
            }
        }

        return array_slice($cache, 0, self::MAX_FEATURE_SOURCES);
    }

    private static function lesson4_batch($offset, $limit) {
        return array_slice(self::lesson4_sources(), $offset, $limit);
    }

    /**
     * Construye un pequeño itinerario por producto: primero una relación simple,
     * después una combinación y, cuando existe una etiqueta útil, un ejercicio
     * profundo. Se evita usar como conocimiento etiquetas numéricas o redundantes.
     */
    private static function feature_items_from_index_row($row) {
        $product_id = absint($row['product_id'] ?? 0);
        if (!$product_id) {
            return array();
        }

        $vocabulary = self::decode_json($row['vocabulary_json'] ?? '');
        $tags = self::decode_json($row['tags_json'] ?? '');
        $attributes = self::decode_json($row['attributes_json'] ?? '');

        $vocabulary_features = array();
        $vocabulary_rules = array();
        foreach (array('tipo', 'aplicacion', 'plataforma', 'subtipo', 'rol') as $group) {
            foreach (array_slice((array) ($vocabulary[$group] ?? array()), 0, 1) as $term) {
                $label = trim((string) ($term['label'] ?? ''));
                $slug = sanitize_title((string) ($term['slug'] ?? ''));
                $id = absint($term['id'] ?? 0);
                if (!$label || !$slug) {
                    continue;
                }
                $vocabulary_features[] = array(
                    'kind'  => 'vocabulary',
                    'group' => $group,
                    'slug'  => $slug,
                    'label' => $label,
                );
                if ($id) {
                    $vocabulary_rules[$group . ':' . $slug] = array(
                        'kind'  => 'vocabulary_route',
                        'id'    => $id,
                        'group' => $group,
                        'slug'  => $slug,
                        'label' => $label,
                    );
                }
            }
            if (count($vocabulary_features) >= 2) {
                break;
            }
        }

        $attribute_features = array();
        $occupied = array();
        foreach ($vocabulary_features as $feature) {
            $occupied[] = SEO_Dependiente_Index::normalize((string) ($feature['label'] ?? ''));
            $occupied[] = SEO_Dependiente_Index::normalize((string) ($feature['slug'] ?? ''));
        }
        foreach ((array) $attributes as $attribute) {
            $label = trim((string) ($attribute['label'] ?? $attribute['key'] ?? ''));
            $values = array_values(array_filter(array_map('strval', (array) ($attribute['values'] ?? array()))));
            $value = $values ? trim((string) $values[0]) : '';
            if (!$label || !$value || !self::is_teachable_attribute($label, $value)) {
                continue;
            }
            $feature = array(
                'kind'  => 'attribute',
                'key'   => sanitize_title((string) ($attribute['key'] ?? $label)),
                'label' => $label,
                'value' => $value,
            );
            $signature = SEO_Dependiente_Index::normalize($feature['key'] . ' ' . $value);
            if (!$signature || in_array($signature, $occupied, true)) {
                continue;
            }
            $attribute_features[] = $feature;
            $occupied[] = SEO_Dependiente_Index::normalize($label);
            $occupied[] = SEO_Dependiente_Index::normalize($value);
            if (count($attribute_features) >= 4) {
                break;
            }
        }

        $tag_features = array();
        foreach ((array) $tags as $tag) {
            if (!self::is_teachable_tag($tag, $occupied)) {
                continue;
            }
            $name = trim((string) ($tag['name'] ?? ''));
            $slug = sanitize_title((string) ($tag['slug'] ?? $name));
            $tag_features[] = array('kind' => 'tag', 'slug' => $slug, 'label' => $name);
            if (count($tag_features) >= 2) {
                break;
            }
        }

        $items = array();
        $anchor = $vocabulary_features[0] ?? null;
        $second_vocabulary = $vocabulary_features[1] ?? null;
        $first_attribute = $attribute_features[0] ?? null;
        $second_attribute = $attribute_features[1] ?? null;

        $foundation = array_values(array_filter(array($anchor, $first_attribute)));
        if (count($foundation) < 2 && count($attribute_features) >= 2) {
            $foundation = array($attribute_features[0], $attribute_features[1]);
        }
        if ($foundation) {
            $items[] = self::make_feature_curriculum_item(
                $product_id,
                'foundation',
                $foundation,
                self::rules_for_feature_set($foundation, $vocabulary_rules),
                'Reconocer una relación semántica y una característica canónica.'
            );
        }

        $combined = array_values(array_filter(array($anchor, $first_attribute, $second_attribute)));
        if (count($combined) < 3 && $second_vocabulary && $first_attribute) {
            $combined = array($anchor ?: $second_vocabulary, $second_vocabulary, $first_attribute);
        }
        if (count($combined) >= 2) {
            $items[] = self::make_feature_curriculum_item(
                $product_id,
                'combined',
                array_slice($combined, 0, 3),
                self::rules_for_feature_set($combined, $vocabulary_rules),
                'Combinar varias restricciones sin depender del nombre exacto del producto.'
            );
        }

        if (!empty($tag_features[0])) {
            $deep = array_values(array_filter(array($anchor, $first_attribute, $tag_features[0])));
            if (count($deep) < 2 && $second_attribute) {
                $deep[] = $second_attribute;
            }
            if (count($deep) >= 2) {
                $items[] = self::make_feature_curriculum_item(
                    $product_id,
                    'deep',
                    array_slice($deep, 0, 3),
                    self::rules_for_feature_set($deep, $vocabulary_rules),
                    'Relacionar una necesidad con una etiqueta semánticamente útil y datos de producto.'
                );
            }
        } elseif ($anchor && $second_vocabulary && $first_attribute) {
            $deep = array($anchor, $second_vocabulary, $first_attribute);
            $items[] = self::make_feature_curriculum_item(
                $product_id,
                'deep',
                $deep,
                self::rules_for_feature_set($deep, $vocabulary_rules),
                'Cruzar dos conceptos del Vocabulary con una característica de producto.'
            );
        }

        return array_values(array_filter($items));
    }

    private static function make_feature_curriculum_item($product_id, $difficulty, $features, $rules, $teaching_goal) {
        $features = array_values(array_filter((array) $features));
        if (!$product_id || !$features) {
            return null;
        }
        $signature = hash('sha256', $difficulty . '|' . wp_json_encode($features, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return array(
            'source_type'   => 'features',
            'source_id'     => absint($product_id),
            'source_key'    => 'features:' . $difficulty . ':' . substr($signature, 0, 32),
            'question_type' => 'features_' . sanitize_key($difficulty),
            'mode'          => 'need',
            'question'      => self::feature_question($features, $difficulty, $product_id),
            'expected'      => array(
                'kind'              => 'features',
                'features'          => $features,
                'source_product_id' => absint($product_id),
                'academy'           => array(
                    'difficulty'    => sanitize_key($difficulty),
                    'teaching_goal' => sanitize_text_field($teaching_goal),
                    'feature_kinds' => array_values(array_unique(array_map(static function ($feature) {
                        return sanitize_key((string) ($feature['kind'] ?? ''));
                    }, $features))),
                ),
            ),
            'rules' => array_values((array) $rules),
        );
    }

    private static function feature_question($features, $difficulty, $product_id) {
        $clauses = array();
        foreach ((array) $features as $feature) {
            $kind = sanitize_key((string) ($feature['kind'] ?? ''));
            if ('vocabulary' === $kind) {
                $labels = array(
                    'tipo'       => 'tipo',
                    'rol'        => 'rol',
                    'aplicacion' => 'aplicación',
                    'plataforma' => 'plataforma',
                    'subtipo'    => 'subtipo',
                );
                $clauses[] = ($labels[sanitize_key((string) ($feature['group'] ?? ''))] ?? 'concepto') . ' "' . (string) ($feature['label'] ?? '') . '"';
            } elseif ('attribute' === $kind) {
                $clauses[] = '"' . (string) ($feature['label'] ?? '') . '" = "' . (string) ($feature['value'] ?? '') . '"';
            } elseif ('tag' === $kind) {
                $clauses[] = 'etiqueta "' . (string) ($feature['label'] ?? '') . '"';
            }
        }
        $clauses = array_values(array_filter($clauses));
        if (!$clauses) {
            return '';
        }

        $variant = absint($product_id) % 3;
        if ('foundation' === $difficulty) {
            $prefixes = array('Estoy buscando productos con ', 'Necesito una opción con ', 'Muéstrame productos que tengan ');
        } elseif ('deep' === $difficulty) {
            $prefixes = array('Para afinar la elección necesito ', 'Busco una opción que combine ', '¿Qué productos encajan si necesito ');
        } else {
            $prefixes = array('Busco productos con ', 'Necesito encontrar productos con ', '¿Qué opciones del catálogo cumplen ');
        }
        $prefix = $prefixes[$variant] ?? $prefixes[0];
        return self::shorten($prefix . implode(', ', $clauses) . '. ¿Qué opciones encajan?', 490);
    }

    private static function rules_for_feature_set($features, $vocabulary_rules) {
        $rules = array();
        foreach ((array) $features as $feature) {
            if ('vocabulary' !== sanitize_key((string) ($feature['kind'] ?? ''))) {
                continue;
            }
            $key = sanitize_key((string) ($feature['group'] ?? '')) . ':' . sanitize_title((string) ($feature['slug'] ?? ''));
            if (isset($vocabulary_rules[$key])) {
                $rules[$key] = $vocabulary_rules[$key];
            }
        }
        return array_values($rules);
    }

    private static function is_teachable_attribute($label, $value) {
        $label = trim(wp_strip_all_tags((string) $label));
        $value = trim(wp_strip_all_tags((string) $value));
        if ('' === $label || '' === $value) {
            return false;
        }
        $normalized = SEO_Dependiente_Index::normalize($value);
        if ('' === $normalized || strlen($normalized) > 180) {
            return false;
        }
        return true;
    }

    private static function is_teachable_tag($tag, $occupied = array()) {
        $name = trim(wp_strip_all_tags((string) ($tag['name'] ?? '')));
        $slug = sanitize_title((string) ($tag['slug'] ?? $name));
        if ('' === $name || '' === $slug) {
            return false;
        }
        $normalized = SEO_Dependiente_Index::normalize($name);
        if (strlen($normalized) < 3 || strlen($normalized) > 120) {
            return false;
        }
        if (preg_match('/^[0-9]+(?:[\.,][0-9]+)?$/u', $normalized)) {
            return false;
        }
        if (preg_match('/^[0-9]+(?:[\.,][0-9]+)?\s*(?:mm|cm|m|kg|g|w|kw|v|a|ah|hz|nm|bar|psi|l|ml|t)$/ui', $normalized)) {
            return false;
        }
        if (preg_match('/^[\W_]+$/u', $name)) {
            return false;
        }
        $occupied = array_values(array_unique(array_filter(array_map(array('SEO_Dependiente_Index', 'normalize'), (array) $occupied))));
        if (in_array($normalized, $occupied, true)) {
            return false;
        }
        return true;
    }

    private static function lesson5_sources() {
        static $cache = null;
        if (null !== $cache) return $cache;
        global $wpdb;
        $cache = array();
        $objects = $wpdb->prefix . 'seo_object_vocabulary';
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        if (!self::table_exists($objects) || !self::table_exists($vocabulary)) return $cache;
        $rows = (array) $wpdb->get_results(
            "SELECT p.ID,p.post_type,p.post_title,v.id vocabulary_id,v.semantic_group,v.slug,v.label
             FROM {$wpdb->posts} p
             INNER JOIN {$objects} ov ON ov.object_id=p.ID AND ov.object_type=p.post_type AND ov.status=1
             INNER JOIN {$vocabulary} v ON v.id=ov.vocabulary_id AND v.active=1
             WHERE p.post_status='publish' AND p.post_type IN ('post','page')
               AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
             ORDER BY p.ID, FIELD(v.semantic_group,'tipo','rol','aplicacion','plataforma','subtipo'),v.id",
            ARRAY_A
        );
        $objects_by_id = array();
        foreach ($rows as $row) {
            $id = absint($row['ID'] ?? 0);
            if (!$id) continue;
            if (!isset($objects_by_id[$id])) {
                $objects_by_id[$id] = array('id'=>$id,'post_type'=>(string)$row['post_type'],'title'=>(string)$row['post_title'],'terms'=>array());
            }
            $objects_by_id[$id]['terms'][] = array('id'=>absint($row['vocabulary_id']),'group'=>sanitize_key((string)$row['semantic_group']),'slug'=>sanitize_title((string)$row['slug']),'label'=>(string)$row['label']);
        }
        $groups = array();
        foreach ($objects_by_id as $object) {
            $terms = array_slice((array)$object['terms'],0,2);
            if (!$terms) continue;
            $sig_parts = array(); $labels=array(); $rules=array();
            foreach ($terms as $term) {
                if (!$term['slug']) continue;
                $sig_parts[]=$term['group'].':'.$term['slug'];
                $labels[]=$term['label'];
                $rules[]=array('kind'=>'vocabulary_route','id'=>$term['id'],'group'=>$term['group'],'slug'=>$term['slug'],'label'=>$term['label']);
            }
            if (!$sig_parts) continue;
            $sig=implode('|',$sig_parts);
            if (!isset($groups[$sig])) $groups[$sig]=array('labels'=>$labels,'rules'=>$rules,'objects'=>array());
            $groups[$sig]['objects'][]=array('type'=>'page'===$object['post_type']?'landing':'post','id'=>$object['id'],'title'=>$object['title']);
        }
        foreach ($groups as $sig=>$group) {
            $labels=array_values(array_filter(array_unique($group['labels'])));
            if (!$labels) continue;
            $cache[]=array(
                'source_type'=>'editorial','source_id'=>null,'source_key'=>'editorial:'.substr(hash('sha256',$sig),0,40),
                'question_type'=>'editorial_semantic','mode'=>'need',
                'question'=>'¿Qué contenido de la web tienes relacionado con "'.implode('" y "',$labels).'"?',
                'expected'=>array('kind'=>'content','acceptable_related'=>$group['objects'],'labels'=>$labels),
                'rules'=>$group['rules'],
            );
        }
        return $cache;
    }

    private static function lesson6_sources() {
        static $cache = null;
        if (null !== $cache) return $cache;
        global $wpdb;
        $cache=array();
        $faq=$wpdb->prefix.'seo_faq';
        if (!self::table_exists($faq)) return $cache;
        $limit=self::MAX_FAQ_SOURCES;

        // Una FAQ por owner para que L6 mida la asociación estructural sin hacer
        // un curso lineal de decenas de miles de filas. Solo productos/categorías:
        // object_type 3 / 2. El texto sigue siendo la materia de la FAQ, pero la
        // ruta de recuperación válida debe ser siempre owner-first.
        $rows=(array)$wpdb->get_results(
            "SELECT f.id,f.object_type,f.object_id,f.ambito,f.question,f.answer,
                    CASE WHEN f.object_type=3 THEN p.post_title ELSE t.name END owner_title
             FROM {$faq} f
             INNER JOIN (
                 SELECT object_type,object_id,MIN(id) id
                 FROM {$faq}
                 WHERE active=1 AND object_type IN (2,3)
                 GROUP BY object_type,object_id
             ) x ON x.id=f.id
             LEFT JOIN {$wpdb->posts} p
                    ON f.object_type=3 AND p.ID=f.object_id
                   AND p.post_type='product' AND p.post_status='publish'
             LEFT JOIN {$wpdb->term_taxonomy} tt
                    ON f.object_type=2 AND tt.term_id=f.object_id
                   AND tt.taxonomy='product_cat'
             LEFT JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
             WHERE f.active=1
               AND f.object_type IN (2,3)
               AND ((f.object_type=3 AND p.ID IS NOT NULL) OR (f.object_type=2 AND tt.term_id IS NOT NULL))
             ORDER BY f.object_type,f.object_id,f.id
             LIMIT {$limit}", ARRAY_A
        );
        foreach($rows as $row){
            $id=absint($row['id']??0);
            $oid=absint($row['object_id']??0);
            $ot=absint($row['object_type']??0);
            $q=trim(wp_strip_all_tags((string)($row['question']??'')));
            $owner_title=trim(wp_strip_all_tags((string)($row['owner_title']??'')));
            $ambito=trim(wp_strip_all_tags((string)($row['ambito']??'')));
            if(!$id||!$oid||!$q||!$owner_title) continue;
            $owner_label=2===$ot?'categoría':'producto';
            $training_question='Sobre el '.$owner_label.' "'.$owner_title.'": '.$q;
            $cache[]=array(
                'source_type'=>'faq',
                'source_id'=>$id,
                'source_key'=>'faq:'.$ot.':'.$oid.':'.$id,
                'question_type'=>'faq_owner_context',
                'mode'=>'need',
                'question'=>self::shorten($training_question,490),
                'expected'=>array(
                    'kind'=>'faq',
                    'faq_id'=>$id,
                    'owner_type'=>$ot,
                    'owner_id'=>$oid,
                    'owner_title'=>$owner_title,
                    'ambito'=>$ambito,
                    'faq_route_required'=>'owner',
                ),
                'rules'=>array(),
            );
        }
        return $cache;
    }

    private static function lesson7_sources() {
        static $cache=null;
        if(null!==$cache) return $cache;
        global $wpdb;
        $cache=array(); $objects=$wpdb->prefix.'seo_object_vocabulary'; $vocabulary=$wpdb->prefix.'seo_vocabulary';
        if(!self::table_exists($objects)||!self::table_exists($vocabulary)) return $cache;
        $limit=self::MAX_CROSS_SOURCES;
        $rows=(array)$wpdb->get_results(
            "SELECT v.id,v.semantic_group,v.slug,v.label,
                    SUM(ov.object_type='product') product_count,
                    SUM(ov.object_type IN ('post','page')) editorial_count
             FROM {$vocabulary} v INNER JOIN {$objects} ov ON ov.vocabulary_id=v.id AND ov.status=1
             WHERE v.active=1 AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
             GROUP BY v.id,v.semantic_group,v.slug,v.label
             HAVING product_count>0 AND editorial_count>0
             ORDER BY (product_count+editorial_count) DESC,v.id ASC LIMIT {$limit}", ARRAY_A
        );
        if(!$rows) return $cache;
        $ids=array_values(array_filter(array_map('absint',wp_list_pluck($rows,'id'))));
        $related=array();
        if($ids){
            $id_sql=implode(',',array_map('absint',$ids));
            $assign=(array)$wpdb->get_results("SELECT vocabulary_id,object_type,object_id FROM {$objects} WHERE status=1 AND object_type IN ('post','page') AND vocabulary_id IN ({$id_sql}) ORDER BY vocabulary_id,object_id",ARRAY_A);
            foreach($assign as $a){$vid=absint($a['vocabulary_id']??0);if(!$vid)continue;$related[$vid][]=array('type'=>'page'===(string)$a['object_type']?'landing':'post','id'=>absint($a['object_id']??0));}
        }
        foreach($rows as $row){
            $id=absint($row['id']??0);$group=sanitize_key((string)$row['semantic_group']);$slug=sanitize_title((string)$row['slug']);$label=trim((string)$row['label']);
            if(!$id||!$group||!$slug||!$label||empty($related[$id]))continue;
            $cache[]=array(
                'source_type'=>'cross','source_id'=>$id,'source_key'=>'cross:'.$id,'question_type'=>'cross_semantic','mode'=>'need',
                'question'=>'Sobre "'.$label.'", ¿qué productos y contenidos relacionados tienes?',
                'expected'=>array('kind'=>'cross','conditions'=>array($group=>array($slug)),'acceptable_related'=>array_slice($related[$id],0,80),'label'=>$label),
                'rules'=>array(array('kind'=>'vocabulary_route','id'=>$id,'group'=>$group,'slug'=>$slug,'label'=>$label)),
            );
        }
        return $cache;
    }

    private static function lesson8_sources() {
        static $cache=null;
        if(null!==$cache)return $cache;
        global $wpdb;
        $cache=array();
        $previous=array('v2_l1_categories','v2_l2_inventory','v2_l3_type_role','v2_l4_features','v2_l5_editorial','v2_l6_faq','v2_l7_cross');
        $per=max(20,(int)floor(self::MAX_EXAM_SOURCES/count($previous)));
        foreach($previous as $lesson_key){
            $rows=(array)$wpdb->get_results($wpdb->prepare(
                'SELECT id,source_type,source_id,source_key,question_type,mode,question,expected_json FROM '.self::questions_table().' WHERE lesson_key=%s AND enabled=1 ORDER BY id ASC LIMIT %d',
                $lesson_key,$per
            ),ARRAY_A);
            foreach($rows as $row){
                $cache[]=array(
                    'source_type'=>'regression','source_id'=>absint($row['id']??0),'source_key'=>'regression:'.absint($row['id']??0),
                    'question_type'=>'regression_'.sanitize_key((string)($row['question_type']??'other')),'mode'=>self::sanitize_mode($row['mode']??'need'),
                    'question'=>(string)$row['question'],'expected'=>self::decode_json($row['expected_json']??''),'rules'=>array(),
                );
                if(count($cache)>=self::MAX_EXAM_SOURCES) break 2;
            }
        }
        return $cache;
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
            $ok = false !== $wpdb->update($table, $row, array('id' => $existing));
        } else {
            $row['created_at'] = current_time('mysql');
            $ok = false !== $wpdb->insert($table, $row);
        }
        if ($ok && method_exists('SEO_Dependiente_Semantics', 'flush_runtime_cache')) {
            SEO_Dependiente_Semantics::flush_runtime_cache();
        }
        return $ok;
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
        if (method_exists('SEO_Dependiente_Semantics', 'flush_runtime_cache')) {
            SEO_Dependiente_Semantics::flush_runtime_cache();
        }
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
        $updated = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET source = 'academy', active = 1, updated_at = %s WHERE source = 'academy_stage' AND rule_key LIKE %s",
            current_time('mysql'),
            $prefix
        ));
        if ($updated > 0 && method_exists('SEO_Dependiente_Semantics', 'flush_runtime_cache')) {
            SEO_Dependiente_Semantics::flush_runtime_cache();
        }
        return $updated;
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
        $lesson_key = sanitize_key((string) ($question['lesson_key'] ?? ''));
        $use_classroom_stage = self::lesson_uses_classroom_stage($lesson_key);
        $faq_owner_only = 'v2_l6_faq' === $lesson_key;
        add_filter('seo_dependiente_should_log_search', array(__CLASS__, 'skip_customer_search_log'), 999, 4);
        add_filter('seo_dependiente_expose_search_diagnostic', array(__CLASS__, 'expose_search_diagnostic'), 999, 3);
        if ($use_classroom_stage) {
            add_filter('seo_dependiente_include_academy_stage', array(__CLASS__, 'include_academy_stage_rules'), 999, 1);
        }
        if ($faq_owner_only) {
            add_filter('seo_dependiente_faq_allow_text_fallback', array(__CLASS__, 'disable_faq_text_fallback'), 999, 1);
        }
        try {
            $response = SEO_Dependiente_API::search($request);
        } catch (Throwable $error) {
            $response = new WP_Error('seo_dependiente_academy_exception', $error->getMessage());
        } finally {
            remove_filter('seo_dependiente_should_log_search', array(__CLASS__, 'skip_customer_search_log'), 999);
            remove_filter('seo_dependiente_expose_search_diagnostic', array(__CLASS__, 'expose_search_diagnostic'), 999);
            if ($use_classroom_stage) {
                remove_filter('seo_dependiente_include_academy_stage', array(__CLASS__, 'include_academy_stage_rules'), 999);
            }
            if ($faq_owner_only) {
                remove_filter('seo_dependiente_faq_allow_text_fallback', array(__CLASS__, 'disable_faq_text_fallback'), 999);
            }
        }

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

        $related_results = array();
        foreach (array_slice((array) ($data['related'] ?? array()), 0, 12) as $related) {
            if (!is_array($related)) continue;
            $related_results[] = array(
                'id' => absint($related['id'] ?? 0),
                'type' => sanitize_key((string) ($related['type'] ?? '')),
                'title' => sanitize_text_field((string) ($related['title'] ?? '')),
                'owner_type' => sanitize_key((string) ($related['owner_type'] ?? '')),
                'owner_id' => absint($related['owner_id'] ?? 0),
                'faq_route' => sanitize_key((string) ($related['faq_route'] ?? '')),
            );
        }
        $evaluation = self::evaluate_question($question, $result_ids, $status, $related_results);
        $evaluation['diagnostic_type'] = self::diagnose_evaluation($question, $evaluation, $data, $result_ids, $related_results);
        $evaluation['classroom_stage_used'] = (bool) $use_classroom_stage;
        $semantic = is_array($data['semantic'] ?? null) ? $data['semantic'] : array();
        $search_diagnostic = self::sanitize_search_diagnostic($data['search_diagnostic'] ?? array());
        $meta = array(
            'clarification' => is_array($data['clarification'] ?? null) ? $data['clarification'] : null,
            'semantic' => array(
                'normalized' => sanitize_text_field((string) ($semantic['normalized'] ?? '')),
                'concepts'   => is_array($semantic['concepts'] ?? null) ? $semantic['concepts'] : array(),
                'groups'     => array_values(array_slice((array) ($semantic['groups'] ?? array()), 0, 16)),
                'routes'     => array_values(array_slice((array) ($semantic['routes'] ?? array()), 0, 16)),
            ),
            'search_diagnostic' => $search_diagnostic,
            'related_results' => $related_results,
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
            'top_results'       => wp_json_encode($compact_results ?: array_map(static function ($item) { return array('id'=>$item['id'],'title'=>$item['title'],'score'=>null,'position'=>null,'reasons'=>array('Contenido relacionado · '.$item['type'])); }, $related_results), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'response_meta'     => wp_json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_message'     => $error_message ? sanitize_text_field($error_message) : null,
            'created_at'        => current_time('mysql'),
        ));

        return false === $inserted ? 0 : absint($wpdb->insert_id);
    }

    private static function sanitize_search_diagnostic($diagnostic) {
        if (!is_array($diagnostic)) {
            return array();
        }
        $out = array();
        foreach (array('strategy', 'primary_strategy', 'extended_search', 'solution_role') as $key) {
            if (isset($diagnostic[$key])) {
                $out[$key] = sanitize_key((string) $diagnostic[$key]);
            }
        }
        foreach (array(
            'primary_rows', 'primary_group_count', 'primary_product_count',
            'direct_knowledge_count', 'strict_count', 'semantic_product_ids',
            'semantic_route_rows', 'object_anchor_rows', 'broad_fallback_rows',
            'semantic_catalog_route', 'semantic_rules_active'
        ) as $key) {
            if (isset($diagnostic[$key])) {
                $out[$key] = absint($diagnostic[$key]);
            }
        }
        $out['extended_reasons'] = array_values(array_slice(array_filter(array_map(
            'sanitize_key',
            (array) ($diagnostic['extended_reasons'] ?? array())
        )), 0, 8));
        return $out;
    }

    private static function evaluate_question($question, $result_ids, $run_status, $related_results = array()) {
        if ('error' === $run_status) {
            return array('status'=>'error','score'=>0,'matched_product_id'=>null,'matched_position'=>null);
        }
        $expected = self::decode_json($question['expected_json'] ?? '');
        if ('lab' === sanitize_key((string) ($question['source_type'] ?? '')) || 'lab' === sanitize_key((string) ($expected['kind'] ?? ''))) {
            return array('status'=>'observed','score'=>null,'result_count'=>count((array)$result_ids),'related_count'=>count((array)$related_results),'snapshot'=>absint($expected['snapshot']??0));
        }
        if (!$expected) return array('status'=>'fail','score'=>0,'reason'=>'No hay verdad esperada para evaluar.');
        $kind=sanitize_key((string)($expected['kind']??''));
        if(in_array($kind,array('content','faq'),true)){
            $match=self::match_related_expected($related_results,$expected);
            return $match ?: array('status'=>'fail','score'=>0,'expected'=>$expected);
        }
        if('cross'===$kind){
            $product_match=false;$product_position=null;
            if($result_ids){
                $rows=class_exists('SEO_Dependiente_Index')?SEO_Dependiente_Index::get_rows_by_ids($result_ids,8):array();
                $by_id=array();foreach($rows as $row){$decoded=SEO_Dependiente_Index::decode_row($row);$by_id[absint($decoded['product_id']??0)]=$decoded;}
                foreach($result_ids as $index=>$pid){if(!empty($by_id[$pid])&&self::row_matches_vocabulary($by_id[$pid],(array)($expected['conditions']??array()))){$product_match=true;$product_position=$index+1;break;}}
            }
            $related_match=self::match_related_expected($related_results,array('kind'=>'content','acceptable_related'=>$expected['acceptable_related']??array()));
            if($product_match&&$related_match){$position=max(1,(int)$product_position);return array('status'=>1===$position?'pass_top1':($position<=3?'pass_top3':'pass_top8'),'score'=>$position===1?1.0:($position<=3?0.85:0.55),'matched_product_id'=>absint($result_ids[$position-1]??0),'matched_position'=>$position,'related_match'=>$related_match,'expected'=>$expected);}
            return array('status'=>'fail','score'=>0,'product_match'=>$product_match,'related_match'=>(bool)$related_match,'expected'=>$expected);
        }
        if (!$result_ids) return array('status'=>'fail','score'=>0,'matched_product_id'=>null,'matched_position'=>null,'expected'=>$expected);
        $rows=class_exists('SEO_Dependiente_Index')?SEO_Dependiente_Index::get_rows_by_ids($result_ids,8):array();
        $by_id=array();foreach($rows as $row){$decoded=SEO_Dependiente_Index::decode_row($row);$by_id[absint($decoded['product_id']??0)]=$decoded;}
        foreach($result_ids as $index=>$product_id){
            if(empty($by_id[$product_id]))continue;
            if(self::row_matches_expected($by_id[$product_id],$expected)){
                $position=$index+1;$status=1===$position?'pass_top1':($position<=3?'pass_top3':'pass_top8');$score=1===$position?1.0:($position<=3?0.85:0.55);
                return array('status'=>$status,'score'=>$score,'matched_product_id'=>$product_id,'matched_position'=>$position,'expected'=>$expected);
            }
        }
        return array('status'=>'fail','score'=>0,'matched_product_id'=>null,'matched_position'=>null,'expected'=>$expected);
    }

    private static function diagnose_evaluation($question, $evaluation, $response_data, $result_ids, $related_results) {
        $status = sanitize_key((string) ($evaluation['status'] ?? ''));
        if ('error' === $status) {
            return 'technical_error';
        }

        $expected = self::decode_json($question['expected_json'] ?? '');
        if (!$expected) {
            return 'curriculum_invalid';
        }

        if (0 === strpos($status, 'pass_')) {
            $clarification = is_array($response_data['clarification'] ?? null) ? $response_data['clarification'] : array();
            if (!empty($clarification['should_ask'])) {
                return 'clarification_gap';
            }
            return 'mastered';
        }
        if ('observed' === $status) {
            return 'observed';
        }

        if ('features' === sanitize_key((string) ($expected['kind'] ?? ''))) {
            foreach ((array) ($expected['features'] ?? array()) as $feature) {
                if ('tag' !== sanitize_key((string) ($feature['kind'] ?? ''))) {
                    continue;
                }
                if (!self::is_teachable_tag(array(
                    'name' => (string) ($feature['label'] ?? ''),
                    'slug' => (string) ($feature['slug'] ?? ''),
                ))) {
                    return 'curriculum_invalid';
                }
            }
        }

        $semantic = is_array($response_data['semantic'] ?? null) ? $response_data['semantic'] : array();
        if (empty($semantic['groups']) && empty($semantic['routes'])) {
            return 'parser_gap';
        }
        if (!$result_ids) {
            $diagnostic = is_array($response_data['search_diagnostic'] ?? null)
                ? $response_data['search_diagnostic']
                : array();
            if ('skipped' === sanitize_key((string) ($diagnostic['extended_search'] ?? ''))
                && !empty($semantic['routes'])) {
                return 'semantic_expansion_skipped';
            }
            if (absint($diagnostic['semantic_product_ids'] ?? 0) > 0) {
                return 'semantic_candidates_filtered';
            }
            if ('executed' === sanitize_key((string) ($diagnostic['extended_search'] ?? ''))
                && !empty($semantic['routes'])
                && 0 === absint($diagnostic['semantic_product_ids'] ?? 0)
                && 0 === absint($diagnostic['semantic_route_rows'] ?? 0)) {
                return 'semantic_route_unresolved';
            }
            return 'retrieval_gap';
        }
        if ($related_results && in_array(sanitize_key((string) ($expected['kind'] ?? '')), array('content', 'faq', 'cross'), true)) {
            return 'retrieval_gap';
        }
        return 'ranking_gap';
    }

    private static function match_related_expected($related_results, $expected) {
        $kind=sanitize_key((string)($expected['kind']??''));
        $acceptable=array();
        if('faq'===$kind){$fid=absint($expected['faq_id']??0);if($fid)$acceptable['faq:'.$fid]=true;}
        foreach((array)($expected['acceptable_related']??array()) as $item){$type=sanitize_key((string)($item['type']??''));$id=absint($item['id']??0);if($type&&$id)$acceptable[$type.':'.$id]=true;}
        if(!$acceptable)return false;
        foreach((array)$related_results as $index=>$item){
            $key=sanitize_key((string)($item['type']??'')).':'.absint($item['id']??0);
            if(!isset($acceptable[$key]))continue;

            if('faq'===$kind){
                $expected_owner_id=absint($expected['owner_id']??0);
                $expected_owner_type=absint($expected['owner_type']??0);
                $owner_type_map=array(2=>'product_cat',3=>'product');
                $actual_owner_type=sanitize_key((string)($item['owner_type']??''));
                $actual_owner_id=absint($item['owner_id']??0);
                $route=sanitize_key((string)($item['faq_route']??''));
                $required_route=sanitize_key((string)($expected['faq_route_required']??''));
                $owner_match=(!$expected_owner_id||$actual_owner_id===$expected_owner_id)
                    &&(!$expected_owner_type||($owner_type_map[$expected_owner_type]??'')===$actual_owner_type);
                $route_match=(''===$required_route||$required_route===$route);
                if(!$owner_match||!$route_match){
                    continue;
                }
                $position=$index+1;
                return array(
                    'status'=>1===$position?'pass_top1':($position<=3?'pass_top3':'pass_top8'),
                    'score'=>1===$position?1.0:($position<=3?0.85:0.55),
                    'matched_related'=>$key,
                    'matched_position'=>$position,
                    'owner_match'=>true,
                    'faq_route'=>$route,
                    'faq_fallback_used'=>'text_fallback'===$route,
                );
            }

            $position=$index+1;
            return array('status'=>1===$position?'pass_top1':($position<=3?'pass_top3':'pass_top8'),'score'=>1===$position?1.0:($position<=3?0.85:0.55),'matched_related'=>$key,'matched_position'=>$position);
        }
        return false;
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

    private static function parse_lab_file($path, $ext, $default_mode) {
        if ('json' === $ext) {
            $raw = file_get_contents($path);
            if (false === $raw) {
                throw new RuntimeException('No se pudo leer el JSON.');
            }
            $decoded = json_decode(self::strip_utf8_bom((string) $raw), true);
            if (!is_array($decoded)) {
                throw new RuntimeException('El JSON no contiene una estructura válida.');
            }
            if (isset($decoded['questions']) && is_array($decoded['questions'])) {
                $decoded = $decoded['questions'];
            }
            $items = array();
            foreach ($decoded as $row) {
                if (is_string($row) || is_numeric($row)) {
                    $items[] = array('question' => (string) $row, 'mode' => $default_mode);
                    continue;
                }
                if (!is_array($row)) {
                    continue;
                }
                $question = (string) ($row['question'] ?? $row['pregunta'] ?? $row['q'] ?? '');
                $mode = (string) ($row['mode'] ?? $row['modo'] ?? $default_mode);
                $items[] = array('question' => $question, 'mode' => $mode);
            }
            return $items;
        }

        if ('csv' === $ext) {
            $handle = fopen($path, 'rb');
            if (!$handle) {
                throw new RuntimeException('No se pudo abrir el CSV.');
            }
            try {
                $first_line = fgets($handle);
                if (false === $first_line) {
                    return array();
                }
                $first_line = self::strip_utf8_bom((string) $first_line);
                $delimiters = array(',' => substr_count($first_line, ','), ';' => substr_count($first_line, ';'), "\t" => substr_count($first_line, "\t"));
                arsort($delimiters);
                $delimiter = (string) key($delimiters);
                if ('' === $delimiter || 0 === (int) current($delimiters)) {
                    $delimiter = ',';
                }
                rewind($handle);
                $rows = array();
                while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                    if (!$row) {
                        continue;
                    }
                    $rows[] = array_map(function ($value) { return trim(self::strip_utf8_bom((string) $value)); }, $row);
                    if (count($rows) > self::LAB_IMPORT_LIMIT + 1) {
                        break;
                    }
                }
            } finally {
                fclose($handle);
            }
            if (!$rows) {
                return array();
            }
            $header = array_map(function ($value) { return strtolower(remove_accents(trim((string) $value))); }, $rows[0]);
            $q_index = null;
            $mode_index = null;
            foreach ($header as $index => $name) {
                if (in_array($name, array('question', 'pregunta', 'q'), true)) {
                    $q_index = $index;
                }
                if (in_array($name, array('mode', 'modo'), true)) {
                    $mode_index = $index;
                }
            }
            $has_header = null !== $q_index;
            if (!$has_header) {
                $q_index = 0;
                $mode_index = isset($rows[0][1]) ? 1 : null;
            }
            $items = array();
            foreach (array_slice($rows, $has_header ? 1 : 0) as $row) {
                $items[] = array(
                    'question' => (string) ($row[$q_index] ?? ''),
                    'mode'     => null !== $mode_index ? (string) ($row[$mode_index] ?? $default_mode) : $default_mode,
                );
            }
            return $items;
        }

        $raw = file_get_contents($path);
        if (false === $raw) {
            throw new RuntimeException('No se pudo leer el TXT.');
        }
        return self::parse_lab_text(self::strip_utf8_bom((string) $raw), $default_mode);
    }

    private static function parse_lab_text($text, $default_mode) {
        $lines = preg_split('/\R/u', (string) $text);
        $items = array();
        foreach ((array) $lines as $line) {
            $line = trim((string) $line);
            if ('' !== $line) {
                $items[] = array('question' => $line, 'mode' => $default_mode);
            }
        }
        return $items;
    }

    private static function normalize_lab_items($items, $default_mode) {
        $out = array();
        $seen = array();
        foreach ((array) $items as $item) {
            if (is_string($item)) {
                $item = array('question' => $item, 'mode' => $default_mode);
            }
            if (!is_array($item)) {
                continue;
            }
            $question = sanitize_text_field((string) ($item['question'] ?? ''));
            $question = self::shorten($question, 490);
            if ('' === $question) {
                continue;
            }
            $normalized = class_exists('SEO_Dependiente_Index') ? SEO_Dependiente_Index::normalize($question) : strtolower(remove_accents($question));
            $key = hash('sha256', $normalized);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = array(
                'question' => $question,
                'mode'     => self::sanitize_mode($item['mode'] ?? $default_mode),
            );
        }
        return $out;
    }

    private static function insert_lab_questions($batch_key, $items, $meta) {
        global $wpdb;
        $sequence = 0;
        $created = 0;
        foreach ((array) $items as $item) {
            $sequence++;
            $question = (string) ($item['question'] ?? '');
            $normalized = class_exists('SEO_Dependiente_Index') ? SEO_Dependiente_Index::normalize($question) : strtolower(remove_accents($question));
            $hash = hash('sha256', $batch_key . '|' . $normalized);
            $module_no = (int) ceil($sequence / self::LAB_MODULE_SIZE);
            $expected = array_merge(array('kind' => 'lab'), (array) $meta);
            $inserted = $wpdb->insert(self::questions_table(), array(
                'question_hash' => $hash,
                'lesson_key'    => $batch_key,
                'lesson_order'  => 999,
                'module_no'     => $module_no,
                'sequence_no'   => $sequence,
                'source_type'   => 'lab',
                'source_id'     => null,
                'source_key'    => 'lab:' . $sequence,
                'question_type' => 'lab_free',
                'mode'          => self::sanitize_mode($item['mode'] ?? 'need'),
                'question'      => $question,
                'expected_json' => self::json($expected),
                'enabled'       => 1,
                'created_at'    => current_time('mysql'),
                'updated_at'    => current_time('mysql'),
            ));
            if (false !== $inserted) {
                $created++;
            }
        }
        return $created;
    }

    private static function new_lab_batch_key() {
        return sanitize_key(self::LAB_PREFIX . current_time('Ymd_His') . '_' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8));
    }

    private static function sanitize_lab_batch_key($value) {
        $value = sanitize_key((string) wp_unslash($value));
        return 0 === strpos($value, self::LAB_PREFIX) ? $value : '';
    }

    private static function lab_batch_exists($batch_key) {
        return self::question_count($batch_key) > 0;
    }

    private static function latest_lab_batch() {
        global $wpdb;
        $pattern = $wpdb->esc_like(self::LAB_PREFIX) . '%';
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT lesson_key AS batch_key, COUNT(*) AS total, MIN(created_at) AS created_at, MAX(id) AS max_id FROM ' . self::questions_table() . ' WHERE lesson_key LIKE %s AND enabled = 1 GROUP BY lesson_key ORDER BY max_id DESC LIMIT 1',
            $pattern
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function pending_lab_questions($batch_key, $limit) {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT q.*
             FROM " . self::questions_table() . " q
             LEFT JOIN " . self::runs_table() . " r
               ON r.question_id = q.id
              AND r.lesson_key = q.lesson_key
              AND r.status = 'answered'
             WHERE q.lesson_key = %s
               AND q.enabled = 1
               AND r.id IS NULL
             ORDER BY q.sequence_no ASC, q.id ASC
             LIMIT %d",
            $batch_key,
            max(1, absint($limit))
        ), ARRAY_A);
    }

    private static function lab_summary($batch_key) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(q.id) AS total,
                    COALESCE(SUM(r.status = 'answered'), 0) AS answered,
                    COALESCE(SUM(r.status = 'answered' AND r.returned_count > 0), 0) AS with_results,
                    COALESCE(SUM(r.status = 'answered' AND r.returned_count = 0), 0) AS zero_results,
                    COALESCE(SUM(r.status = 'error'), 0) AS errors
             FROM " . self::questions_table() . " q
             LEFT JOIN " . self::runs_table() . " r ON r.question_id = q.id AND r.lesson_key = q.lesson_key
             WHERE q.lesson_key = %s AND q.enabled = 1",
            $batch_key
        ), ARRAY_A);
        return wp_parse_args(is_array($row) ? array_map('absint', $row) : array(), self::empty_lab_summary());
    }

    private static function empty_lab_summary() {
        return array('total' => 0, 'answered' => 0, 'with_results' => 0, 'zero_results' => 0, 'errors' => 0);
    }

    private static function strip_utf8_bom($value) {
        $value = (string) $value;
        return 0 === strncmp($value, "\xEF\xBB\xBF", 3) ? substr($value, 3) : $value;
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
            self::update_lesson($lesson_key, array('completed_items'=>absint($summary['answered'])));
            return false;
        }
        $lesson=self::lesson_row($lesson_key);if(!$lesson)return false;
        $definition=self::lesson_definition($lesson_key);
        $total=max(1,absint($summary['total']));
        $pass_ratio=absint($summary['pass_any'])/$total;
        $min_pass=isset($definition['min_pass_any'])?(float)$definition['min_pass_any']:0.40;
        $quality_ok=0===absint($summary['errors']) && $pass_ratio >= $min_pass;
        $activated_rules=0;
        if($quality_ok){
            $activated_rules=self::activate_staged_academy_rules($lesson_key);
        } else {
            // La lección puede terminar para no bloquear todo el currículo, pero
            // no convierte resultados pobres en conocimiento operativo.
            self::clear_staged_academy_rules($lesson_key);
        }
        $before=absint($lesson['snapshot_before']??0);
        $after=max(absint(get_option(self::KNOWLEDGE_SNAPSHOT_OPTION,0)),$before)+1;
        update_option(self::KNOWLEDGE_SNAPSHOT_OPTION,$after,false);
        self::update_lesson($lesson_key,array(
            'status'=>'completed','completed_items'=>absint($summary['answered']),'snapshot_after'=>$after,
            'metadata'=>array('curriculum_version'=>self::CURRICULUM_VERSION,'activated_rules'=>max(0,(int)$activated_rules),'quality_gate'=>array('passed'=>$quality_ok,'pass_any_ratio'=>round($pass_ratio,4),'min_pass_any'=>$min_pass,'technical_errors'=>absint($summary['errors'])),'summary'=>$summary),
            'completed_at'=>current_time('mysql'),
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
        $preflight=self::catalog_preflight();
        $parts=array($lesson_key,self::CURRICULUM_VERSION,(string)($preflight['indexed']??0),(string)($preflight['published']??0),(string)($preflight['last_full']??''),(string)self::lesson_source_total($lesson_key),self::catalog_fingerprint());
        return hash('sha256',implode('|',$parts));
    }

    private static function catalog_fingerprint() {
        static $cache='';
        if($cache)return $cache;
        global $wpdb;
        $parts=array();
        $parts[]=(string)$wpdb->get_var("SELECT CONCAT(COUNT(*),'|',COALESCE(MAX(post_modified_gmt),''),'|',COALESCE(BIT_XOR(CRC32(CONCAT(ID,'|',post_modified_gmt,'|',post_status))),0)) FROM {$wpdb->posts} WHERE post_type IN ('product','post','page') AND post_status='publish'");
        $v=$wpdb->prefix.'seo_vocabulary';$ov=$wpdb->prefix.'seo_object_vocabulary';$faq=$wpdb->prefix.'seo_faq';
        if(self::table_exists($v))$parts[]=(string)$wpdb->get_var("SELECT CONCAT(COUNT(*),'|',COALESCE(MAX(updated_at),''),'|',COALESCE(BIT_XOR(CRC32(CONCAT(id,'|',semantic_group,'|',slug,'|',active))),0)) FROM {$v}");
        if(self::table_exists($ov))$parts[]=(string)$wpdb->get_var("SELECT CONCAT(COUNT(*),'|',COALESCE(MAX(updated_at),''),'|',COALESCE(BIT_XOR(CRC32(CONCAT(object_type,'|',object_id,'|',vocabulary_id,'|',status))),0)) FROM {$ov} WHERE object_type IN ('product','product_cat','post','page')");
        if(self::table_exists($faq))$parts[]=(string)$wpdb->get_var("SELECT CONCAT(COUNT(*),'|',COALESCE(MAX(updated_at),''),'|',COALESCE(BIT_XOR(CRC32(CONCAT(id,'|',object_type,'|',object_id,'|',active))),0)) FROM {$faq} WHERE object_type IN (1,2,3)");
        $cache=hash('sha256',implode('||',$parts));
        return $cache;
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

    private static function auto_speed_config() {
        $config = array(
            'min_batch' => self::AJAX_BATCH_MIN,
            'initial_batch' => self::AJAX_BATCH_INITIAL,
            'max_batch' => self::AJAX_BATCH_LIMIT,
            'fast_seconds' => 2.5,
            'slow_seconds' => 7.0,
            'very_slow_seconds' => 14.0,
            'growth_factor' => 1.34,
            'slowdown_factor' => 0.50,
            'fast_streak_required' => 2,
            'normal_delay_seconds' => 1,
            'slow_delay_seconds' => 2,
            'critical_delay_seconds' => 5,
        );
        if (function_exists('seo_processes_control_for')) {
            $stored = seo_processes_control_for('academy');
            if (is_array($stored) && $stored) {
                $config = array_merge($config, $stored);
            }
        }
        $config['min_batch'] = max(1, absint($config['min_batch']));
        $config['initial_batch'] = max($config['min_batch'], absint($config['initial_batch']));
        $config['max_batch'] = max($config['initial_batch'], absint($config['max_batch']));
        return $config;
    }

    private static function sanitize_batch_size($value) {
        $speed = self::auto_speed_config();
        $value = absint($value);
        if ($value < $speed['min_batch']) {
            $value = $speed['initial_batch'];
        }
        return min($speed['max_batch'], max($speed['min_batch'], $value));
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
