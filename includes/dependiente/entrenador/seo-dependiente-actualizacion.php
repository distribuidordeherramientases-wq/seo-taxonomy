<?php

defined('ABSPATH') || exit;

/**
 * Actualización incremental de la Academia del Dependiente.
 *
 * No es una novena lección del currículo inicial. Es una lección repetible,
 * sin número, que reutiliza las ocho capacidades de Academia sobre el delta
 * del catálogo y reserva el módulo 9 para reconciliar/publicar conocimiento.
 *
 * El botón solo encola trabajo. La ejecución real la hace el mismo Gestor de
 * workers que ya controla Academia.
 */
final class SEO_Dependiente_Actualizacion {
    const VERSION = '1.0.0';
    const STATE_OPTION = 'seo_dependiente_academy_update_state';
    const HISTORY_OPTION = 'seo_dependiente_academy_update_history';
    const LAST_SUCCESS_OPTION = 'seo_dependiente_academy_update_last_success';
    const MAX_HISTORY = 16;
    const PREPARE_BATCH = 120;
    const RUN_BATCH = 3;
    const MAX_EXAM_ITEMS = 500;

    public static function init() {
        add_action('wp_ajax_seo_dependiente_actualizacion_start', array(__CLASS__, 'ajax_start'));
        add_action('wp_ajax_seo_dependiente_actualizacion_export', array(__CLASS__, 'ajax_export'));
    }

    public static function state() {
        $defaults = array(
            'version'        => self::VERSION,
            'run_id'         => '',
            'lesson_key'     => '',
            'status'         => 'idle',
            'phase'          => 'idle',
            'module'         => 0,
            'prepare_offset' => 0,
            'started_at'     => '',
            'started_gmt'    => '',
            'completed_at'   => '',
            'completed_gmt'  => '',
            'cutoff_local'   => '',
            'cutoff_gmt'     => '',
            'scan_until_local' => '',
            'scan_until_gmt'   => '',
            'delta'          => array(),
            'modules'        => array(),
            'merge'          => array(),
            'last_message'   => '',
            'last_error'     => '',
        );
        $state = get_option(self::STATE_OPTION, array());
        return wp_parse_args(is_array($state) ? $state : array(), $defaults);
    }

    private static function save_state($changes) {
        $state = self::state();
        foreach ((array) $changes as $key => $value) {
            if (array_key_exists($key, $state)) {
                $state[$key] = $value;
            }
        }
        $state['version'] = self::VERSION;
        update_option(self::STATE_OPTION, $state, false);
        return $state;
    }

    public static function is_running() {
        return in_array((string) (self::state()['status'] ?? ''), array('queued', 'running'), true);
    }

    public static function has_pending_work() {
        return self::is_running();
    }

    public static function monitor_current() {
        $state = self::state();
        if (!self::has_pending_work()) {
            return null;
        }
        return array(
            'lesson_key'   => (string) ($state['lesson_key'] ?? ''),
            'lesson_order' => 0,
            'title'        => 'Actualización',
            'status'       => (string) ($state['phase'] ?? 'running'),
            'next_module'  => absint($state['module'] ?? 0),
            'module_count' => 9,
            'summary'      => self::aggregate_summary($state),
        );
    }

    public static function render_card() {
        if (!class_exists('SEO_Dependiente_Entrenador')) {
            return;
        }
        $initial = SEO_Dependiente_Entrenador::update_initial_course_status();
        $available = !empty($initial['completed']);
        $state = self::state();
        $running = self::is_running();
        $last_success = get_option(self::LAST_SUCCESS_OPTION, array());
        $last_at = is_array($last_success) ? (string) ($last_success['local'] ?? '') : '';
        $next_recommended = '';
        if ($last_at) {
            $ts = strtotime($last_at . ' +7 days');
            if ($ts) {
                $next_recommended = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts);
            }
        }
        $modules = self::module_labels();
        $state_modules = is_array($state['modules'] ?? null) ? $state['modules'] : array();
        $delta = is_array($state['delta'] ?? null) ? $state['delta'] : array();
        ?>
        <section class="postbox seo-dependiente-admin__box seo-dependiente-trainer__update <?php echo $running ? 'is-running' : ''; ?>" data-trainer-update data-update-running="<?php echo $running ? '1' : '0'; ?>">
            <div class="seo-dependiente-trainer__section-head">
                <div>
                    <span class="seo-dependiente-trainer__eyebrow">Lección continua · sin número</span>
                    <h2>Actualización</h2>
                    <p class="description">Revisa únicamente lo nuevo o modificado desde el último aprendizaje. Pasa ese delta por los ocho filtros de Academia y el módulo 9 reconcilia el resultado con el conocimiento activo.</p>
                </div>
                <div class="seo-dependiente-trainer__update-actions">
                    <button type="button" class="button button-primary" data-trainer-update-start <?php disabled(!$available || $running); ?>><?php echo $running ? 'Actualización en curso…' : 'Actualizar conocimiento'; ?></button>
                    <button type="button" class="button" data-trainer-update-export <?php disabled(empty($state['run_id'])); ?>>Descargar informe JSON</button>
                </div>
            </div>

            <?php if (!$available) : ?>
                <p class="seo-dependiente-trainer__update-note">La Actualización se desbloquea cuando termina la formación inicial L1–L8.</p>
            <?php else : ?>
                <div class="seo-dependiente-trainer__update-meta">
                    <span><strong>Última actualización</strong><?php echo $last_at ? esc_html($last_at) : 'Todavía no ejecutada'; ?></span>
                    <span><strong>Frecuencia recomendada</strong><?php echo $next_recommended ? 'Próxima: ' . esc_html($next_recommended) : 'Una vez por semana'; ?></span>
                    <?php if (!empty($state['run_id'])) : ?><span><strong>Run</strong><code><?php echo esc_html((string) $state['run_id']); ?></code></span><?php endif; ?>
                </div>

                <?php if ($delta) : ?>
                    <div class="seo-dependiente-trainer__update-delta">
                        <span><strong><?php echo esc_html(number_format_i18n(absint($delta['products'] ?? 0))); ?></strong> productos</span>
                        <span><strong><?php echo esc_html(number_format_i18n(absint($delta['categories'] ?? 0))); ?></strong> categorías</span>
                        <span><strong><?php echo esc_html(number_format_i18n(absint($delta['editorial'] ?? 0))); ?></strong> posts/páginas</span>
                        <span><strong><?php echo esc_html(number_format_i18n(absint($delta['vocabulary'] ?? 0))); ?></strong> conceptos</span>
                        <span><strong><?php echo esc_html(number_format_i18n(absint($delta['faqs'] ?? 0))); ?></strong> FAQs</span>
                    </div>
                <?php endif; ?>

                <div class="seo-dependiente-trainer__update-modules">
                    <?php foreach ($modules as $number => $label) :
                        $module_state = is_array($state_modules[$number] ?? null) ? $state_modules[$number] : array();
                        $status = sanitize_key((string) ($module_state['status'] ?? 'pending'));
                        $count = absint($module_state['total'] ?? 0);
                        ?>
                        <div class="seo-dependiente-trainer__update-module is-<?php echo esc_attr($status); ?>">
                            <strong>M<?php echo esc_html($number); ?></strong>
                            <span><?php echo esc_html($label); ?></span>
                            <small><?php echo 'completed' === $status ? 'Completado' : ('skipped' === $status ? 'Sin novedades' : ($count ? number_format_i18n($count) . ' ejercicios' : ucfirst($status))); ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="description" data-trainer-update-status><?php echo esc_html((string) ($state['last_error'] ?: ($state['last_message'] ?: 'Lista para revisar novedades.'))); ?></p>
            <?php endif; ?>
        </section>
        <?php
    }

    public static function ajax_start() {
        self::guard_ajax();
        if (!class_exists('SEO_Dependiente_Entrenador')) {
            wp_send_json_error(array('message' => 'Academia no disponible.'), 500);
        }
        $initial = SEO_Dependiente_Entrenador::update_initial_course_status();
        if (empty($initial['completed'])) {
            wp_send_json_error(array('message' => 'Completa primero las ocho lecciones iniciales.'), 409);
        }
        if (self::is_running()) {
            wp_send_json_success(self::payload());
        }

        $last_success = get_option(self::LAST_SUCCESS_OPTION, array());
        $cutoff_local = is_array($last_success) ? trim((string) ($last_success['local'] ?? '')) : '';
        $cutoff_gmt = is_array($last_success) ? trim((string) ($last_success['gmt'] ?? '')) : '';
        if (!$cutoff_local) {
            $cutoff_local = (string) ($initial['completed_at'] ?? '');
        }
        if (!$cutoff_gmt && $cutoff_local) {
            $cutoff_gmt = get_gmt_from_date($cutoff_local, 'Y-m-d H:i:s');
        }
        if (!$cutoff_local) {
            $cutoff_local = current_time('mysql');
        }
        if (!$cutoff_gmt) {
            $cutoff_gmt = current_time('mysql', true);
        }

        $suffix = strtolower(wp_generate_password(6, false, false));
        $run_id = gmdate('YmdHis') . '-' . $suffix;
        $lesson_key = sanitize_key('update_' . gmdate('YmdHis') . '_' . $suffix);
        $modules = array();
        foreach (self::module_labels() as $number => $label) {
            $modules[$number] = array(
                'label'    => $label,
                'status'   => 'pending',
                'total'    => 0,
                'answered' => 0,
                'passed'   => 0,
                'failed'   => 0,
                'errors'   => 0,
            );
        }

        self::save_state(array(
            'run_id'         => $run_id,
            'lesson_key'     => $lesson_key,
            'status'         => 'queued',
            'phase'          => 'scan',
            'module'         => 1,
            'prepare_offset' => 0,
            'started_at'     => current_time('mysql'),
            'started_gmt'    => current_time('mysql', true),
            'completed_at'   => '',
            'completed_gmt'  => '',
            'cutoff_local'     => $cutoff_local,
            'cutoff_gmt'       => $cutoff_gmt,
            'scan_until_local' => '',
            'scan_until_gmt'   => '',
            'delta'          => array(),
            'modules'        => $modules,
            'merge'          => array(),
            'last_message'   => 'Actualización encolada. El Gestor de workers revisará las novedades.',
            'last_error'     => '',
        ));

        $queued = SEO_Dependiente_Entrenador::update_queue_worker('Actualización de conocimiento encolada.');
        if (is_wp_error($queued)) {
            self::mark_error($queued->get_error_message());
            wp_send_json_error(array('message' => $queued->get_error_message()), 500);
        }
        wp_send_json_success(self::payload());
    }

    public static function ajax_export() {
        self::guard_ajax();
        $state = self::state();
        if (empty($state['run_id'])) {
            wp_send_json_error(array('message' => 'Todavía no existe una actualización para exportar.'), 404);
        }
        $document = array(
            'schema'         => 'seo-dependiente-academy-update',
            'schema_version' => 1,
            'generated_at'   => current_time('c'),
            'update_version' => self::VERSION,
            'state'          => $state,
            'history'        => array_slice((array) get_option(self::HISTORY_OPTION, array()), 0, 10),
        );
        wp_send_json_success(array(
            'filename' => 'dependiente-actualizacion-' . sanitize_file_name((string) $state['run_id']) . '.json',
            'document' => $document,
        ));
    }

    public static function worker_step($source = 'academy_manager') {
        $state = self::state();
        if (!self::is_running()) {
            return array('done' => true, 'message' => 'No hay actualización pendiente.', 'delay' => 0);
        }
        if ('queued' === (string) $state['status']) {
            $state = self::save_state(array('status' => 'running'));
        }

        try {
            $phase = sanitize_key((string) ($state['phase'] ?? 'scan'));
            if ('scan' === $phase) {
                // Fijamos un techo de lectura. Todo cambio posterior a este instante
                // queda deliberadamente para la siguiente Actualización y no puede
                // perderse aunque este run tarde varios minutos en completar M1-M9.
                $scan_until_local = current_time('mysql');
                $scan_until_gmt = current_time('mysql', true);
                $state = self::save_state(array(
                    'scan_until_local' => $scan_until_local,
                    'scan_until_gmt'   => $scan_until_gmt,
                ));
                $delta = self::detect_delta($state);
                $counts = self::delta_counts($delta);
                $state = self::save_state(array(
                    'delta'        => $delta,
                    'phase'        => 'prepare',
                    'module'       => 1,
                    'prepare_offset' => 0,
                    'last_message' => sprintf(
                        'Novedades detectadas: %d productos, %d categorías, %d contenidos, %d conceptos y %d FAQs.',
                        $counts['products'], $counts['categories'], $counts['editorial'], $counts['vocabulary'], $counts['faqs']
                    ),
                ));
                if (array_sum($counts) < 1) {
                    return self::finish_without_changes($state);
                }
                return array('done' => false, 'delay' => 1, 'message' => $state['last_message']);
            }

            if ('prepare' === $phase) {
                return self::prepare_module_step($state);
            }
            if ('run' === $phase) {
                return self::run_module_step($state);
            }
            if ('merge' === $phase) {
                return self::merge_step($state);
            }
            if ('completed' === $phase) {
                return array('done' => true, 'delay' => 0, 'message' => (string) ($state['last_message'] ?? 'Actualización completada.'));
            }
            throw new RuntimeException('Fase de actualización no reconocida: ' . $phase);
        } catch (Throwable $error) {
            self::mark_error($error->getMessage());
            throw $error;
        }
    }

    private static function prepare_module_step($state) {
        $module = max(1, min(8, absint($state['module'] ?? 1)));
        $lesson_key = sanitize_key((string) ($state['lesson_key'] ?? ''));
        if (!$lesson_key) {
            throw new RuntimeException('La actualización no tiene lesson_key.');
        }

        $items = 8 === $module
            ? self::build_exam_items($lesson_key)
            : SEO_Dependiente_Entrenador::update_build_module_items($module, (array) ($state['delta'] ?? array()));
        $total = count($items);
        $offset = min($total, absint($state['prepare_offset'] ?? 0));
        $slice = array_slice($items, $offset, self::PREPARE_BATCH);
        $sequence = SEO_Dependiente_Entrenador::update_question_count($lesson_key) + 1;

        foreach ($slice as $item) {
            if (self::insert_question($lesson_key, $module, $item, $sequence)) {
                if ($module < 8) {
                    SEO_Dependiente_Entrenador::update_stage_item_rules($lesson_key, $item);
                }
                $sequence++;
            }
        }

        $new_offset = min($total, $offset + count($slice));
        $modules = (array) ($state['modules'] ?? array());
        $module_state = is_array($modules[$module] ?? null) ? $modules[$module] : array();
        $module_state['status'] = $new_offset >= $total ? ($total ? 'running' : 'skipped') : 'preparing';
        $module_state['total'] = $total;
        $modules[$module] = $module_state;

        if ($new_offset < $total) {
            self::save_state(array(
                'modules'        => $modules,
                'prepare_offset' => $new_offset,
                'last_message'   => 'Actualización · M' . $module . ': preparando ' . $new_offset . ' de ' . $total . ' elementos.',
            ));
            return array('done' => false, 'delay' => 1, 'message' => 'Preparando M' . $module . '.');
        }

        if ($total < 1) {
            $module_state['status'] = 'skipped';
            $modules[$module] = $module_state;
            return self::advance_module($state, $modules, $module, 'M' . $module . ' no tiene novedades aplicables.');
        }

        self::save_state(array(
            'modules'        => $modules,
            'phase'          => 'run',
            'prepare_offset' => 0,
            'last_message'   => 'Actualización · M' . $module . ' preparado con ' . $total . ' ejercicios.',
        ));
        return array('done' => false, 'delay' => 1, 'message' => 'M' . $module . ' preparado.');
    }

    private static function run_module_step($state) {
        $module = max(1, min(8, absint($state['module'] ?? 1)));
        $lesson_key = sanitize_key((string) ($state['lesson_key'] ?? ''));
        $batch_uuid = wp_generate_uuid4();
        $modules = (array) ($state['modules'] ?? array());
        $module_state = is_array($modules[$module] ?? null) ? $modules[$module] : array();
        $answered_before = absint($module_state['answered'] ?? 0);
        $questions = SEO_Dependiente_Entrenador::update_pending_questions($lesson_key, $module, self::RUN_BATCH);
        foreach ($questions as $question) {
            SEO_Dependiente_Entrenador::update_run_question($question, $batch_uuid);
        }

        $summary = SEO_Dependiente_Entrenador::update_module_summary($lesson_key, $module);
        foreach (array('total','answered','passed','failed','errors') as $key) {
            $module_state[$key] = absint($summary[$key] ?? 0);
        }
        $answered_now = absint($summary['answered'] ?? 0);
        if ($questions && $answered_now <= $answered_before) {
            $module_state['no_progress'] = absint($module_state['no_progress'] ?? 0) + 1;
        } else {
            $module_state['no_progress'] = 0;
        }
        $modules[$module] = $module_state;

        if (absint($module_state['no_progress'] ?? 0) >= 6) {
            throw new RuntimeException('M' . $module . ' acumula errores técnicos sin avanzar. Se detiene antes del merge.');
        }

        if ($answered_now < absint($summary['total'] ?? 0)) {
            self::save_state(array(
                'modules'      => $modules,
                'last_message' => 'Actualización · M' . $module . ': ' . $answered_now . ' de ' . absint($summary['total'] ?? 0) . ' evaluados.',
            ));
            return array('done' => false, 'delay' => 1, 'message' => 'Ejecutando M' . $module . '.');
        }

        $total = max(1, absint($summary['total'] ?? 0));
        $pass_ratio = absint($summary['passed'] ?? 0) / $total;
        $min_pass = SEO_Dependiente_Entrenador::update_module_min_pass($module);
        if (absint($summary['errors'] ?? 0) > 0 || $pass_ratio < $min_pass) {
            SEO_Dependiente_Entrenador::update_clear_staged_rules($lesson_key);
            $module_state['status'] = 'failed';
            $module_state['pass_ratio'] = round($pass_ratio, 4);
            $module_state['min_pass'] = $min_pass;
            $modules[$module] = $module_state;
            self::save_state(array(
                'status'       => 'error',
                'phase'        => 'error',
                'modules'      => $modules,
                'last_error'   => sprintf('M%d no supera el control de calidad (%.1f%% / mínimo %.1f%%). No se ha hecho merge.', $module, $pass_ratio * 100, $min_pass * 100),
                'last_message' => 'Actualización detenida antes del merge. El conocimiento activo anterior permanece intacto.',
            ));
            return array('done' => true, 'delay' => 0, 'error' => true, 'message' => 'Actualización detenida por quality gate.');
        }

        $module_state['status'] = 'completed';
        $module_state['pass_ratio'] = round($pass_ratio, 4);
        $module_state['min_pass'] = $min_pass;
        $modules[$module] = $module_state;
        return self::advance_module($state, $modules, $module, 'M' . $module . ' completado.');
    }

    private static function advance_module($state, $modules, $module, $message) {
        if ($module >= 8) {
            self::save_state(array(
                'modules'        => $modules,
                'phase'          => 'merge',
                'module'         => 9,
                'prepare_offset' => 0,
                'last_message'   => $message . ' Preparando M9 · merge.',
            ));
            return array('done' => false, 'delay' => 1, 'message' => 'Preparando merge.');
        }
        self::save_state(array(
            'modules'        => $modules,
            'phase'          => 'prepare',
            'module'         => $module + 1,
            'prepare_offset' => 0,
            'last_message'   => $message . ' Continúa M' . ($module + 1) . '.',
        ));
        return array('done' => false, 'delay' => 1, 'message' => $message);
    }

    private static function merge_step($state) {
        global $wpdb;
        $lesson_key = sanitize_key((string) ($state['lesson_key'] ?? ''));

        // M9 es el único punto de publicación. Cuando las tablas usan InnoDB,
        // la transacción evita dejar una actualización parcialmente mezclada.
        // Aun si el motor no ofrece transacciones, el control staged/merged hace
        // que cualquier fallo sea visible y detenga la publicación del snapshot.
        $wpdb->query('START TRANSACTION');
        try {
            $merge = SEO_Dependiente_Entrenador::update_merge_staged_rules($lesson_key);
            $staged = absint($merge['staged'] ?? 0);
            $merged = absint($merge['merged'] ?? 0);
            if ($merged < $staged) {
                throw new RuntimeException(sprintf('M9 solo pudo integrar %d de %d reglas candidatas.', $merged, $staged));
            }
            $wpdb->query('COMMIT');
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }

        $snapshot = SEO_Dependiente_Entrenador::update_publish_snapshot();
        $modules = (array) ($state['modules'] ?? array());
        $module9 = is_array($modules[9] ?? null) ? $modules[9] : array();
        $module9['status'] = 'completed';
        $module9['total'] = absint($merge['staged'] ?? 0);
        $module9['answered'] = absint($merge['merged'] ?? 0);
        $module9['passed'] = absint($merge['merged'] ?? 0);
        $modules[9] = $module9;

        $completed_local = current_time('mysql');
        $completed_gmt = current_time('mysql', true);
        $state = self::save_state(array(
            'status'        => 'completed',
            'phase'         => 'completed',
            'module'        => 9,
            'modules'       => $modules,
            'merge'         => array_merge((array) $merge, array('snapshot' => $snapshot)),
            'completed_at'  => $completed_local,
            'completed_gmt' => $completed_gmt,
            'last_message'  => 'Actualización completada. El nuevo conocimiento ya está integrado en el snapshot ' . $snapshot . '.',
            'last_error'    => '',
        ));
        update_option(self::LAST_SUCCESS_OPTION, array(
            'local'           => (string) ($state['scan_until_local'] ?: $completed_local),
            'gmt'             => (string) ($state['scan_until_gmt'] ?: $completed_gmt),
            'completed_local' => $completed_local,
            'completed_gmt'   => $completed_gmt,
            'run_id'          => $state['run_id'],
            'lesson_key'      => $state['lesson_key'],
        ), false);
        self::append_history($state);
        return array('done' => true, 'delay' => 0, 'message' => $state['last_message']);
    }

    private static function finish_without_changes($state) {
        $local = current_time('mysql');
        $gmt = current_time('mysql', true);
        $modules = (array) ($state['modules'] ?? array());
        foreach ($modules as $number => $row) {
            $row = is_array($row) ? $row : array();
            $row['status'] = 'skipped';
            $modules[$number] = $row;
        }
        $state = self::save_state(array(
            'status'        => 'completed',
            'phase'         => 'completed',
            'module'        => 9,
            'modules'       => $modules,
            'completed_at'  => $local,
            'completed_gmt' => $gmt,
            'last_message'  => 'No se han detectado novedades desde la última formación. No ha sido necesario modificar el conocimiento.',
            'last_error'    => '',
        ));
        update_option(self::LAST_SUCCESS_OPTION, array(
            'local'           => (string) ($state['scan_until_local'] ?: $local),
            'gmt'             => (string) ($state['scan_until_gmt'] ?: $gmt),
            'completed_local' => $local,
            'completed_gmt'   => $gmt,
            'run_id'          => $state['run_id'],
            'lesson_key'      => $state['lesson_key'],
        ), false);
        self::append_history($state);
        return array('done' => true, 'delay' => 0, 'message' => $state['last_message']);
    }

    public static function mark_error($message) {
        $message = sanitize_text_field((string) $message);
        $state = self::state();
        if (!empty($state['lesson_key'])) {
            SEO_Dependiente_Entrenador::update_clear_staged_rules((string) $state['lesson_key']);
        }
        self::save_state(array(
            'status'       => 'error',
            'phase'        => 'error',
            'last_error'   => $message,
            'last_message' => 'Actualización detenida. El conocimiento activo anterior no se ha sustituido.',
        ));
    }

    private static function detect_delta($state) {
        global $wpdb;
        $cutoff_local = (string) ($state['cutoff_local'] ?? '');
        $cutoff_gmt = (string) ($state['cutoff_gmt'] ?? '');
        $scan_until_local = (string) ($state['scan_until_local'] ?? current_time('mysql'));
        $scan_until_gmt = (string) ($state['scan_until_gmt'] ?? current_time('mysql', true));
        $product_ids = array();
        $category_ids = array();
        $editorial_ids = array();
        $vocabulary_ids = array();
        $faq_ids = array();

        if (class_exists('SEO_Dependiente_Index') && SEO_Dependiente_Index::table_exists()) {
            $index = SEO_Dependiente_Index::table();
            $rows = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT i.product_id,i.categories_json,i.vocabulary_json
                 FROM {$index} i
                 INNER JOIN {$wpdb->posts} p ON p.ID=i.product_id
                 WHERE p.post_type='product' AND p.post_status='publish'
                   AND (p.post_date_gmt > %s OR p.post_modified_gmt > %s)
                   AND p.post_modified_gmt <= %s
                 ORDER BY i.product_id ASC",
                $cutoff_gmt,
                $cutoff_gmt,
                $scan_until_gmt
            ), ARRAY_A);
            foreach ($rows as $row) {
                $pid = absint($row['product_id'] ?? 0);
                if (!$pid) continue;
                $product_ids[$pid] = true;
                foreach ((array) self::decode_json($row['categories_json'] ?? '') as $cat) {
                    $cid = absint($cat['id'] ?? 0);
                    if ($cid) $category_ids[$cid] = true;
                }
                $vocab = self::decode_json($row['vocabulary_json'] ?? '');
                foreach ((array) $vocab as $terms) {
                    foreach ((array) $terms as $term) {
                        $vid = absint($term['id'] ?? 0);
                        if ($vid) $vocabulary_ids[$vid] = true;
                    }
                }
            }
        }

        $post_rows = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type IN ('post','page') AND post_status='publish'
               AND (post_date_gmt > %s OR post_modified_gmt > %s)
               AND post_modified_gmt <= %s",
            $cutoff_gmt,
            $cutoff_gmt,
            $scan_until_gmt
        ));
        foreach ($post_rows as $id) {
            $id = absint($id);
            if ($id) $editorial_ids[$id] = true;
        }

        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        if (self::table_exists($vocabulary)) {
            $rows = (array) $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$vocabulary} WHERE active=1 AND updated_at > %s AND updated_at <= %s",
                $cutoff_local,
                $scan_until_local
            ));
            foreach ($rows as $id) {
                $id = absint($id);
                if ($id) $vocabulary_ids[$id] = true;
            }
        }

        $faq = $wpdb->prefix . 'seo_faq';
        if (self::table_exists($faq)) {
            $rows = (array) $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$faq} WHERE active=1 AND object_type IN (2,3) AND updated_at > %s AND updated_at <= %s",
                $cutoff_local,
                $scan_until_local
            ));
            foreach ($rows as $id) {
                $id = absint($id);
                if ($id) $faq_ids[$id] = true;
            }
        }

        // Categorías no tienen post_modified. Comparamos su fotografía docente
        // actual con la última pregunta de Academia que representó esa categoría.
        foreach (SEO_Dependiente_Entrenador::update_all_category_items() as $item) {
            $cid = absint($item['source_id'] ?? 0);
            if (!$cid) continue;
            $current = self::item_fingerprint($item);
            $previous = self::latest_source_fingerprint('category', $cid);
            if (!$previous || !hash_equals($previous, $current)) {
                $category_ids[$cid] = true;
            }
        }

        $objects = $wpdb->prefix . 'seo_object_vocabulary';
        if (self::table_exists($objects)) {
            $object_clauses = array();
            $params = array();
            if ($product_ids) {
                $ids = array_keys($product_ids);
                $object_clauses[] = "(object_type='product' AND object_id IN (" . implode(',', array_fill(0, count($ids), '%d')) . '))';
                $params = array_merge($params, $ids);
            }
            if ($category_ids) {
                $ids = array_keys($category_ids);
                $object_clauses[] = "(object_type='product_cat' AND object_id IN (" . implode(',', array_fill(0, count($ids), '%d')) . '))';
                $params = array_merge($params, $ids);
            }
            if ($editorial_ids) {
                $ids = array_keys($editorial_ids);
                $object_clauses[] = "(object_type IN ('post','page') AND object_id IN (" . implode(',', array_fill(0, count($ids), '%d')) . '))';
                $params = array_merge($params, $ids);
            }
            if ($object_clauses) {
                $sql = "SELECT DISTINCT vocabulary_id FROM {$objects} WHERE status=1 AND (" . implode(' OR ', $object_clauses) . ')';
                $prepared = $params ? $wpdb->prepare($sql, $params) : $sql;
                foreach ((array) $wpdb->get_col($prepared) as $id) {
                    $id = absint($id);
                    if ($id) $vocabulary_ids[$id] = true;
                }
            }
        }

        // Las FAQs de propietarios afectados entran también aunque el texto de
        // la FAQ no haya cambiado: el contexto comercial puede haber cambiado.
        if (self::table_exists($faq) && ($product_ids || $category_ids)) {
            $clauses = array();
            $params = array();
            if ($product_ids) {
                $ids = array_keys($product_ids);
                $clauses[] = '(object_type=3 AND object_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . '))';
                $params = array_merge($params, $ids);
            }
            if ($category_ids) {
                $ids = array_keys($category_ids);
                $clauses[] = '(object_type=2 AND object_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . '))';
                $params = array_merge($params, $ids);
            }
            $sql = "SELECT id FROM {$faq} WHERE active=1 AND (" . implode(' OR ', $clauses) . ')';
            foreach ((array) $wpdb->get_col($wpdb->prepare($sql, $params)) as $id) {
                $id = absint($id);
                if ($id) $faq_ids[$id] = true;
            }
        }

        return array(
            'product_ids'       => array_values(array_map('absint', array_keys($product_ids))),
            'category_ids'      => array_values(array_map('absint', array_keys($category_ids))),
            'editorial_ids'     => array_values(array_map('absint', array_keys($editorial_ids))),
            'vocabulary_ids'    => array_values(array_map('absint', array_keys($vocabulary_ids))),
            'faq_ids'           => array_values(array_map('absint', array_keys($faq_ids))),
            'products'          => count($product_ids),
            'categories'        => count($category_ids),
            'editorial'         => count($editorial_ids),
            'vocabulary'        => count($vocabulary_ids),
            'faqs'              => count($faq_ids),
            'detected_at'       => current_time('mysql'),
            'cutoff_local'      => $cutoff_local,
            'cutoff_gmt'        => $cutoff_gmt,
            'scan_until_local'   => $scan_until_local,
            'scan_until_gmt'     => $scan_until_gmt,
        );
    }

    private static function build_exam_items($lesson_key) {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT id,source_type,source_id,source_key,question_type,mode,question,expected_json
             FROM ' . SEO_Dependiente_Entrenador::questions_table() . '
             WHERE lesson_key=%s AND enabled=1 AND module_no BETWEEN 1 AND 7
             ORDER BY id ASC LIMIT %d',
            $lesson_key,
            self::MAX_EXAM_ITEMS
        ), ARRAY_A);
        $items = array();
        foreach ($rows as $row) {
            $items[] = array(
                'source_type'   => 'regression',
                'source_id'     => absint($row['id'] ?? 0),
                'source_key'    => 'update-regression:' . absint($row['id'] ?? 0),
                'question_type' => 'regression_' . sanitize_key((string) ($row['question_type'] ?? 'other')),
                'mode'          => sanitize_key((string) ($row['mode'] ?? 'need')),
                'question'      => (string) ($row['question'] ?? ''),
                'expected'      => self::decode_json($row['expected_json'] ?? ''),
                'rules'         => array(),
            );
        }
        return $items;
    }

    private static function insert_question($lesson_key, $module, $item, $sequence) {
        global $wpdb;
        $question = sanitize_text_field((string) ($item['question'] ?? ''));
        if (!$question) return false;
        $source_key = sanitize_text_field((string) ($item['source_key'] ?? ''));
        $normalized = class_exists('SEO_Dependiente_Index') ? SEO_Dependiente_Index::normalize($question) : strtolower($question);
        $hash = hash('sha256', $lesson_key . '|' . $module . '|' . $source_key . '|' . $normalized);
        $exists = absint($wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . SEO_Dependiente_Entrenador::questions_table() . ' WHERE question_hash=%s LIMIT 1',
            $hash
        )));
        if ($exists) return false;
        return false !== $wpdb->insert(SEO_Dependiente_Entrenador::questions_table(), array(
            'question_hash' => $hash,
            'lesson_key'    => $lesson_key,
            'lesson_order'  => 0,
            'module_no'     => absint($module),
            'sequence_no'   => absint($sequence),
            'source_type'   => sanitize_key((string) ($item['source_type'] ?? '')),
            'source_id'     => absint($item['source_id'] ?? 0) ?: null,
            'source_key'    => substr($source_key, 0, 190),
            'question_type' => sanitize_key((string) ($item['question_type'] ?? 'other')),
            'mode'          => in_array(sanitize_key((string) ($item['mode'] ?? 'need')), array('need','product','tool','compare'), true) ? sanitize_key((string) $item['mode']) : 'need',
            'question'      => function_exists('mb_substr') ? mb_substr($question, 0, 490, 'UTF-8') : substr($question, 0, 490),
            'expected_json' => wp_json_encode((array) ($item['expected'] ?? array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'enabled'       => 1,
            'created_at'    => current_time('mysql'),
            'updated_at'    => current_time('mysql'),
        ));
    }

    private static function latest_source_fingerprint($source_type, $source_id) {
        global $wpdb;
        $lesson_keys = array('v2_l1_categories');
        foreach ((array) get_option(self::HISTORY_OPTION, array()) as $history_row) {
            if (!is_array($history_row) || 'completed' !== (string) ($history_row['status'] ?? '')) continue;
            $key = sanitize_key((string) ($history_row['lesson_key'] ?? ''));
            if ($key) $lesson_keys[] = $key;
        }
        $lesson_keys = array_values(array_unique(array_filter($lesson_keys)));
        if (!$lesson_keys) return '';
        $placeholders = implode(',', array_fill(0, count($lesson_keys), '%s'));
        $args = array_merge(array(sanitize_key((string) $source_type), absint($source_id)), $lesson_keys);
        $sql = 'SELECT question,expected_json FROM ' . SEO_Dependiente_Entrenador::questions_table() .
            ' WHERE source_type=%s AND source_id=%d AND enabled=1 AND lesson_key IN (' . $placeholders . ') ORDER BY id DESC LIMIT 1';
        $row = $wpdb->get_row($wpdb->prepare($sql, $args), ARRAY_A);
        if (!$row) return '';
        return hash('sha256', (string) ($row['question'] ?? '') . '|' . wp_json_encode(self::decode_json($row['expected_json'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function item_fingerprint($item) {
        return hash('sha256', (string) ($item['question'] ?? '') . '|' . wp_json_encode((array) ($item['expected'] ?? array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function delta_counts($delta) {
        return array(
            'products'   => absint($delta['products'] ?? 0),
            'categories' => absint($delta['categories'] ?? 0),
            'editorial'  => absint($delta['editorial'] ?? 0),
            'vocabulary' => absint($delta['vocabulary'] ?? 0),
            'faqs'       => absint($delta['faqs'] ?? 0),
        );
    }

    private static function aggregate_summary($state) {
        $summary = array('total'=>0,'answered'=>0,'pass_any'=>0,'failed'=>0,'errors'=>0);
        foreach ((array) ($state['modules'] ?? array()) as $number => $row) {
            if (absint($number) > 8 || !is_array($row)) continue;
            $summary['total'] += absint($row['total'] ?? 0);
            $summary['answered'] += absint($row['answered'] ?? 0);
            $summary['pass_any'] += absint($row['passed'] ?? 0);
            $summary['failed'] += absint($row['failed'] ?? 0);
            $summary['errors'] += absint($row['errors'] ?? 0);
        }
        return $summary;
    }

    private static function append_history($state) {
        $history = (array) get_option(self::HISTORY_OPTION, array());
        array_unshift($history, array(
            'run_id'        => (string) ($state['run_id'] ?? ''),
            'lesson_key'    => (string) ($state['lesson_key'] ?? ''),
            'started_at'    => (string) ($state['started_at'] ?? ''),
            'completed_at'  => (string) ($state['completed_at'] ?? ''),
            'cutoff_local'  => (string) ($state['cutoff_local'] ?? ''),
            'delta'         => (array) ($state['delta'] ?? array()),
            'modules'       => (array) ($state['modules'] ?? array()),
            'merge'         => (array) ($state['merge'] ?? array()),
            'status'        => (string) ($state['status'] ?? ''),
        ));
        update_option(self::HISTORY_OPTION, array_slice($history, 0, self::MAX_HISTORY), false);
    }

    private static function module_labels() {
        return array(
            1 => 'Mapa y jerarquía',
            2 => 'Inventario nuevo',
            3 => 'TIPO y ROL',
            4 => 'Características',
            5 => 'Contenido editorial',
            6 => 'FAQs contextualizadas',
            7 => 'Relaciones cruzadas',
            8 => 'Examen del delta',
            9 => 'Merge con conocimiento',
        );
    }

    private static function payload() {
        $state = self::state();
        return array(
            'state'   => $state,
            'running' => self::is_running(),
            'current' => self::monitor_current(),
        );
    }

    private static function decode_json($value) {
        if (is_array($value)) return $value;
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : array();
    }

    private static function table_exists($table) {
        if (class_exists('SEO_Dependiente_Index')) {
            return SEO_Dependiente_Index::table_exists($table);
        }
        global $wpdb;
        return (string) $table === (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like((string) $table)));
    }

    private static function guard_ajax() {
        check_ajax_referer('seo_dependiente_entrenador', 'nonce');
        $capability = class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options';
        if (!current_user_can($capability)) {
            wp_send_json_error(array('message' => 'No tienes permisos para actualizar el conocimiento.'), 403);
        }
    }
}

SEO_Dependiente_Actualizacion::init();
