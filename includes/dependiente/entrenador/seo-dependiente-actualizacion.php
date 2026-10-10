<?php

defined('ABSPATH') || exit;

/**
 * Actualización incremental de la Academia del Dependiente.
 *
 * No forma parte del currículo inicial. Es una lección repetible, sin número,
 * que solo se habilita después de L1-L9. Reutiliza las ocho comprobaciones
 * incrementales existentes sobre el delta; la memoria de producto de L9 se
 * mantiene desde V3 cuando los productos cambian. El módulo 9 reconcilia y
 * publica el conocimiento candidato.
 *
 * El botón solo encola trabajo. La ejecución real la hace el mismo Gestor de
 * workers que ya controla Academia.
 */
final class SEO_Dependiente_Actualizacion {
    const VERSION = '1.0.3';
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
                    <p class="description">Revisa únicamente lo nuevo o modificado desde el último aprendizaje. Pasa el delta por las comprobaciones incrementales de Academia; la memoria de necesidades y productos de L9 se mantiene desde V3 y el módulo 9 reconcilia el resultado con el conocimiento activo.</p>
                </div>
                <div class="seo-dependiente-trainer__update-actions">
                    <button type="button" class="button button-primary" data-trainer-update-start <?php disabled(!$available || $running); ?>><?php echo $running ? 'Actualización en curso…' : 'Actualizar conocimiento'; ?></button>
                    <button type="button" class="button" data-trainer-update-export <?php disabled(empty($state['run_id'])); ?>>Descargar informe JSON</button>
                    <span class="description">El informe incluye el detalle real de fallos, diagnóstico y fuente formativa para poder revisar qué necesita aprender mejor.</span>
                </div>
            </div>

            <?php if (!$available) : ?>
                <?php if (empty($initial['l8_completed'])) : ?>
                    <p class="seo-dependiente-trainer__update-note">STAGING no tiene acreditada como completada la formación L1–L8. Si esas lecciones se aprendieron en PRO, no las repitas aquí: instala esta misma versión en PRO y STAGING, exporta de nuevo el conocimiento desde PRO e impórtalo en STAGING para trasladar también el estado de las lecciones completadas. Después quedará disponible L9.</p>
                <?php else : ?>
                    <p class="seo-dependiente-trainer__update-note">L1–L8 ya están completadas. La Actualización se habilitará cuando termine L9 · Necesidades y productos.</p>
                <?php endif; ?>
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
                            <small><?php echo 'completed' === $status ? 'Completado' : ('skipped' === $status ? 'Sin novedades' : ($count ? esc_html(number_format_i18n($count)) . ' ejercicios' : esc_html(ucfirst($status)))); ?></small>
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
            if (empty($initial['l8_completed'])) {
                wp_send_json_error(array('message' => 'STAGING no tiene L1–L8 acreditadas como completadas. Reimporta desde PRO una copia de conocimiento generada con esta versión; no es necesario repetir esas lecciones.'), 409);
            }
            wp_send_json_error(array('message' => 'Completa primero L9 · Necesidades y productos.'), 409);
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
        $diagnostics = self::build_learning_diagnostics((string) ($state['lesson_key'] ?? ''));
        $document = array(
            'schema'         => 'seo-dependiente-academy-update',
            'schema_version' => 2,
            'generated_at'   => current_time('c'),
            'update_version' => self::VERSION,
            'state'          => $state,
            'learning_diagnostics' => $diagnostics,
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
            throw new RuntimeException('M' . esc_html((string) $module) . ' acumula errores técnicos sin avanzar. Se detiene antes del merge.');
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
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $index is an internal table name returned by SEO_Dependiente_Index::table(); all data values are bound below.
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
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql contains only an internal table name plus generated %d placeholders.
                $prepared = $params ? $wpdb->prepare($sql, $params) : $sql;
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Query values are already bound in $prepared; dynamic fragments are internal table/placeholders.
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
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Internal table and generated %d placeholders; all IDs are bound through prepare().
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
        $questions_table = SEO_Dependiente_Entrenador::questions_table();
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Internal questions table; lesson key and limit are bound through prepare().
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id,source_type,source_id,source_key,question_type,mode,question,expected_json
             FROM {$questions_table}
             WHERE lesson_key=%s AND enabled=1 AND module_no BETWEEN 1 AND 7
             ORDER BY id ASC LIMIT %d",
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
        $questions_table = SEO_Dependiente_Entrenador::questions_table();
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Internal questions table; hash is bound through prepare().
        $exists = absint($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$questions_table} WHERE question_hash=%s LIMIT 1",
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
        $questions_table = SEO_Dependiente_Entrenador::questions_table();
        $sql = "SELECT question,expected_json FROM {$questions_table}" .
            ' WHERE source_type=%s AND source_id=%d AND enabled=1 AND lesson_key IN (' . $placeholders . ') ORDER BY id DESC LIMIT 1';
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Internal table plus generated %s placeholders; all values are bound through prepare().
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

    /**
     * Reconstruye los fallos reales de la Actualización desde las tablas de
     * preguntas/ejecuciones. No modifica la formación ni vuelve a ejecutar
     * preguntas: por eso también sirve para el run que ya esté en curso.
     */
    private static function build_learning_diagnostics($lesson_key) {
        global $wpdb;

        $lesson_key = sanitize_key((string) $lesson_key);
        $empty = array(
            'available' => false,
            'lesson_key' => $lesson_key,
            'summary' => array(
                'total_failures' => 0,
                'learning_failures' => 0,
                'technical_errors' => 0,
                'by_module' => array(),
                'by_diagnostic_type' => array(),
                'by_source_type' => array(),
                'by_question_type' => array(),
            ),
            'failures' => array(),
            'interpretation_note' => 'Los diagnósticos indican dónde revisar la formación; no demuestran por sí solos la causa del fallo.',
        );
        if (!$lesson_key || !class_exists('SEO_Dependiente_Entrenador')) {
            return $empty;
        }

        $questions_table = SEO_Dependiente_Entrenador::questions_table();
        $runs_table = SEO_Dependiente_Entrenador::runs_table();
        if (!self::table_exists($questions_table) || !self::table_exists($runs_table)) {
            return $empty;
        }

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Tablas internas de Academia; lesson_key es el único valor externo y se enlaza con prepare().
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT
                r.id AS run_id,
                r.module_no,
                r.question_id,
                r.source_type,
                r.source_id,
                r.question_type,
                r.question,
                r.status,
                r.result_count,
                r.returned_count,
                r.search_strategy,
                r.execution_ms,
                r.evaluation_status,
                r.evaluation_score,
                r.evaluation_json,
                r.top_results,
                r.response_meta,
                r.error_message,
                r.created_at,
                q.source_key,
                q.expected_json
             FROM {$runs_table} r
             LEFT JOIN {$questions_table} q ON q.id = r.question_id
             WHERE r.lesson_key = %s
               AND (
                    r.status = 'error'
                    OR r.evaluation_status = 'error'
                    OR r.evaluation_status = 'fail'
               )
             ORDER BY r.module_no ASC, r.id ASC",
            $lesson_key
        ), ARRAY_A);

        $summary = $empty['summary'];
        $failures = array();
        $labels = self::module_labels();

        foreach ($rows as $row) {
            $module = absint($row['module_no'] ?? 0);
            $module_label = (string) ($labels[$module] ?? ('M' . $module));
            $evaluation = self::decode_json($row['evaluation_json'] ?? '');
            $expected = self::decode_json($row['expected_json'] ?? '');
            $top_results = array_values(array_slice(self::decode_json($row['top_results'] ?? ''), 0, 8));
            $response_meta = self::decode_json($row['response_meta'] ?? '');
            $diagnostic_type = sanitize_key((string) ($evaluation['diagnostic_type'] ?? ''));
            if (!$diagnostic_type) {
                $diagnostic_type = ('error' === (string) ($row['status'] ?? '') || 'error' === (string) ($row['evaluation_status'] ?? ''))
                    ? 'technical_error'
                    : 'unclassified_failure';
            }

            // expected ya se exporta por separado; evitamos duplicarlo dentro
            // de evaluation para mantener el JSON manejable en runs grandes.
            if (isset($evaluation['expected'])) {
                unset($evaluation['expected']);
            }

            $source_type = sanitize_key((string) ($row['source_type'] ?? ''));
            $source_id = absint($row['source_id'] ?? 0);
            $question_type = sanitize_key((string) ($row['question_type'] ?? 'other'));
            $technical = 'technical_error' === $diagnostic_type
                || 'error' === (string) ($row['status'] ?? '')
                || 'error' === (string) ($row['evaluation_status'] ?? '');

            $context = self::learning_source_context($source_type, $source_id, $expected);
            $review = self::learning_review_hint(
                $module,
                $diagnostic_type,
                $source_type,
                $context,
                $expected
            );

            $failure = array(
                'run_id' => absint($row['run_id'] ?? 0),
                'module' => $module,
                'module_label' => $module_label,
                'question_id' => absint($row['question_id'] ?? 0),
                'question' => (string) ($row['question'] ?? ''),
                'question_type' => $question_type,
                'source' => array(
                    'type' => $source_type,
                    'id' => $source_id,
                    'key' => (string) ($row['source_key'] ?? ''),
                ),
                'result' => array(
                    'status' => (string) ($row['status'] ?? ''),
                    'evaluation_status' => (string) ($row['evaluation_status'] ?? ''),
                    'evaluation_score' => isset($row['evaluation_score']) ? (float) $row['evaluation_score'] : null,
                    'diagnostic_type' => $diagnostic_type,
                    'result_count' => absint($row['result_count'] ?? 0),
                    'returned_count' => absint($row['returned_count'] ?? 0),
                    'search_strategy' => (string) ($row['search_strategy'] ?? ''),
                    'execution_ms' => isset($row['execution_ms']) ? (float) $row['execution_ms'] : null,
                    'technical_error' => (string) ($row['error_message'] ?? ''),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                ),
                'expected' => $expected,
                'evaluation' => $evaluation,
                'returned_top_results' => $top_results,
                'response_diagnostic' => array(
                    'clarification' => $response_meta['clarification'] ?? null,
                    'semantic' => is_array($response_meta['semantic'] ?? null) ? $response_meta['semantic'] : array(),
                    'search_diagnostic' => is_array($response_meta['search_diagnostic'] ?? null) ? $response_meta['search_diagnostic'] : array(),
                ),
                'training_source_context' => $context,
                'training_review' => $review,
            );
            $failures[] = $failure;

            $summary['total_failures']++;
            if ($technical) {
                $summary['technical_errors']++;
            } else {
                $summary['learning_failures']++;
            }

            if (!isset($summary['by_module'][$module])) {
                $summary['by_module'][$module] = array(
                    'label' => $module_label,
                    'failures' => 0,
                    'learning_failures' => 0,
                    'technical_errors' => 0,
                    'diagnostic_types' => array(),
                );
            }
            $summary['by_module'][$module]['failures']++;
            $summary['by_module'][$module][$technical ? 'technical_errors' : 'learning_failures']++;
            if (!isset($summary['by_module'][$module]['diagnostic_types'][$diagnostic_type])) {
                $summary['by_module'][$module]['diagnostic_types'][$diagnostic_type] = 0;
            }
            $summary['by_module'][$module]['diagnostic_types'][$diagnostic_type]++;

            foreach (array(
                'by_diagnostic_type' => $diagnostic_type,
                'by_source_type' => $source_type ?: 'unknown',
                'by_question_type' => $question_type ?: 'other',
            ) as $group_key => $group_value) {
                if (!isset($summary[$group_key][$group_value])) {
                    $summary[$group_key][$group_value] = 0;
                }
                $summary[$group_key][$group_value]++;
            }
        }

        return array(
            'available' => true,
            'lesson_key' => $lesson_key,
            'summary' => $summary,
            'failures' => $failures,
            'interpretation_note' => 'Un fallo es una evidencia real de que esa comprobación no se superó. training_review es una pista de investigación, no una causa demostrada ni una orden de modificar contenido.',
            'category_policy' => 'Las categorías actuales se tratan como material formativo de referencia. El informe aporta su descripción y jerarquía para contrastar el fallo, pero no propone reescribirlas automáticamente.',
        );
    }

    /**
     * Adjunta el contexto de la fuente con la que se está enseñando.
     * Mantiene el tamaño acotado: no duplica textos completos muy largos.
     */
    private static function learning_source_context($source_type, $source_id, $expected = array()) {
        $source_type = sanitize_key((string) $source_type);
        $source_id = absint($source_id);
        $expected = is_array($expected) ? $expected : array();

        if ('category' === $source_type && $source_id) {
            $term = get_term($source_id, 'product_cat');
            if ($term instanceof WP_Term && !is_wp_error($term)) {
                $description = trim(wp_strip_all_tags((string) $term->description));
                $parent = $term->parent ? get_term(absint($term->parent), 'product_cat') : null;
                $children = get_term_children($source_id, 'product_cat');
                if (is_wp_error($children)) $children = array();
                $url = get_term_link($term);
                if (is_wp_error($url)) $url = '';
                return array(
                    'kind' => 'product_category',
                    'id' => $source_id,
                    'name' => (string) $term->name,
                    'slug' => (string) $term->slug,
                    'parent_id' => absint($term->parent),
                    'parent_name' => $parent instanceof WP_Term && !is_wp_error($parent) ? (string) $parent->name : '',
                    'category_path' => (array) ($expected['category_path'] ?? array()),
                    'product_count' => absint($term->count),
                    'children_count' => count((array) $children),
                    'description_present' => '' !== $description,
                    'description_chars' => function_exists('mb_strlen') ? mb_strlen($description) : strlen($description),
                    'description_excerpt' => self::short_text($description, 1400),
                    'public_url' => (string) $url,
                    'edit_url' => admin_url('term.php?taxonomy=product_cat&tag_ID=' . $source_id . '&post_type=product'),
                );
            }
        }

        if ('product' === $source_type && $source_id) {
            $product = function_exists('wc_get_product') ? wc_get_product($source_id) : null;
            if ($product && is_a($product, 'WC_Product')) {
                $category_names = array();
                foreach ((array) $product->get_category_ids() as $term_id) {
                    $term = get_term(absint($term_id), 'product_cat');
                    if ($term instanceof WP_Term && !is_wp_error($term)) {
                        $category_names[] = (string) $term->name;
                    }
                }
                return array(
                    'kind' => 'product',
                    'id' => $source_id,
                    'title' => (string) $product->get_name(),
                    'sku' => (string) $product->get_sku(),
                    'status' => (string) $product->get_status(),
                    'stock_status' => (string) $product->get_stock_status(),
                    'categories' => array_values(array_unique($category_names)),
                    'public_url' => (string) get_permalink($source_id),
                    'edit_url' => (string) get_edit_post_link($source_id, ''),
                );
            }
        }

        return array(
            'kind' => $source_type ?: 'unknown',
            'id' => $source_id,
            'expected_owner' => array(
                'owner_type' => $expected['owner_type'] ?? null,
                'owner_id' => $expected['owner_id'] ?? null,
                'owner_title' => $expected['owner_title'] ?? '',
            ),
        );
    }

    /**
     * Traduce el diagnóstico técnico del evaluador a una pista de revisión
     * comprensible. Deliberadamente no afirma que esa sea la causa real.
     */
    private static function learning_review_hint($module, $diagnostic_type, $source_type, $context, $expected) {
        $diagnostic_type = sanitize_key((string) $diagnostic_type);
        $source_type = sanitize_key((string) $source_type);
        $review = array(
            'certainty' => 'hypothesis',
            'diagnostic_type' => $diagnostic_type,
            'what_happened' => 'La comprobación no alcanzó la respuesta esperada.',
            'review_first' => array(),
            'do_not_assume' => 'No modificar la fuente automáticamente: confirmar primero la causa con expected, resultados devueltos y diagnóstico de búsqueda.',
        );

        $map = array(
            'technical_error' => array(
                'what_happened' => 'La ejecución falló técnicamente; no puede interpretarse como desconocimiento del estudiante.',
                'review_first' => array('Error técnico de Academia/API', 'logs de ejecución'),
            ),
            'curriculum_invalid' => array(
                'what_happened' => 'La verdad esperada o el ejercicio parecen inválidos para el evaluador.',
                'review_first' => array('Definición del ejercicio', 'fuente canónica usada para generar expected'),
            ),
            'parser_gap' => array(
                'what_happened' => 'El Intérprete no consiguió estructurar suficientemente la pregunta.',
                'review_first' => array('Vocabulary y sinónimos', 'contexto de jerarquía', 'reglas del Intérprete'),
            ),
            'semantic_route_unresolved' => array(
                'what_happened' => 'Se detectó intención/ruta semántica, pero no se resolvió hasta una entidad recuperable.',
                'review_first' => array('rutas semánticas', 'jerarquía', 'alias de categoría', 'Vocabulary'),
            ),
            'semantic_expansion_skipped' => array(
                'what_happened' => 'Había una ruta semántica pero la expansión no llegó a ejecutarse.',
                'review_first' => array('motor de recuperación', 'reglas de expansión semántica'),
            ),
            'semantic_candidates_filtered' => array(
                'what_happened' => 'Existían candidatos semánticos, pero fueron filtrados antes del resultado final.',
                'review_first' => array('filtros del ranking', 'compatibilidad de candidatos', 'señales de recuperación'),
            ),
            'ranking_gap' => array(
                'what_happened' => 'Hubo resultados, pero la entidad esperada no quedó entre los candidatos aceptados.',
                'review_first' => array('fronteras entre familias', 'jerarquía', 'Vocabulary', 'señales de ranking'),
            ),
            'retrieval_gap' => array(
                'what_happened' => 'El motor no recuperó la entidad esperada.',
                'review_first' => array('indexación del conocimiento', 'rutas/alias', 'Vocabulary', 'asociación con la fuente'),
            ),
            'editorial_retrieval_gap' => array(
                'what_happened' => 'No se recuperó el contenido editorial esperado.',
                'review_first' => array('relación editorial con la entidad', 'metadatos del post', 'recuperación editorial'),
            ),
            'faq_owner_retrieval_gap' => array(
                'what_happened' => 'No se recuperó la FAQ esperada para su propietario.',
                'review_first' => array('owner de la FAQ', 'asociación FAQ-entidad', 'recuperación contextual'),
            ),
            'faq_owner_ranking_gap' => array(
                'what_happened' => 'La FAQ correcta existe, pero quedó demasiado abajo en el ranking.',
                'review_first' => array('ranking de FAQs', 'owner/contexto', 'señales semánticas'),
            ),
            'cross_retrieval_gap' => array(
                'what_happened' => 'Falló una relación cruzada esperada entre conocimiento de producto y contenido.',
                'review_first' => array('relaciones cruzadas', 'Vocabulary', 'enlaces entre entidades'),
            ),
            'unclassified_failure' => array(
                'what_happened' => 'La comprobación falló sin un diagnóstico más específico.',
                'review_first' => array('expected', 'resultados devueltos', 'diagnóstico de búsqueda'),
            ),
        );
        if (isset($map[$diagnostic_type])) {
            $review = array_merge($review, $map[$diagnostic_type]);
        }

        if (1 === absint($module) && 'category' === $source_type) {
            $review['m1_category_guidance'] = array(
                'category_is_reference' => true,
                'instruction' => 'Primero comprobar si la descripción actual de la categoría ya enseña correctamente el concepto. Si es suficiente, revisar antes las rutas/relaciones y el contexto de Hub secundario → Hub primario → Cluster. No reescribir la categoría por un fallo aislado.',
                'description_present' => !empty($context['description_present']),
                'description_chars' => absint($context['description_chars'] ?? 0),
            );
        }

        return $review;
    }

    private static function short_text($text, $limit) {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
        $limit = max(80, absint($limit));
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit - 1)) . '…' : $text;
        }
        return strlen($text) > $limit ? rtrim(substr($text, 0, $limit - 1)) . '…' : $text;
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
