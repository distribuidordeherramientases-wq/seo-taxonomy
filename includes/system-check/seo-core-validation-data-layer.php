<?php
/**
 * Plugin Validation - Action Scheduler y Data Layer.
 *
 * - Los chequeos pasivos son de solo lectura y pueden ejecutarse por telemetría.
 * - Las pruebas activas solo se ejecutan mediante una acción manual protegida.
 */

defined('ABSPATH') || exit;

if (!defined('SEO_CORE_DATA_LAYER_TEST_VERSION')) {
    define('SEO_CORE_DATA_LAYER_TEST_VERSION', '1.0.0');
}

/**
 * Chequeos de Action Scheduler que distinguen acciones futuras, vencidas y fallidas recientes.
 *
 * @return array<int,array<string,mixed>>
 */
function seo_core_system_test_action_scheduler_checks() {
    global $wpdb;

    $results = array();
    $table = $wpdb->prefix . 'actionscheduler_actions';
    $groups_table = $wpdb->prefix . 'actionscheduler_groups';
    $exists = function_exists('seo_core_system_test_table_exists')
        ? seo_core_system_test_table_exists($table)
        : $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;

    $results[] = seo_core_system_test_result(
        'technical',
        '7.7 Action Scheduler disponible',
        $exists,
        $exists ? $table : 'Tabla no detectada',
        $exists ? 'ok' : (class_exists('WooCommerce') ? 'warning' : 'info')
    );

    if (!$exists) {
        return $results;
    }

    $stats = $wpdb->get_row(
        "SELECT
            SUM(status = 'pending') AS pending_total,
            SUM(status = 'pending' AND scheduled_date_gmt > UTC_TIMESTAMP()) AS pending_future,
            SUM(status = 'pending' AND scheduled_date_gmt <= UTC_TIMESTAMP()) AS pending_due,
            SUM(status = 'pending' AND scheduled_date_gmt < UTC_TIMESTAMP() - INTERVAL 1 HOUR) AS pending_over_1h,
            SUM(status = 'pending' AND scheduled_date_gmt < UTC_TIMESTAMP() - INTERVAL 1 DAY) AS pending_over_24h,
            SUM(status = 'failed') AS failed_total,
            SUM(status = 'failed' AND last_attempt_gmt >= UTC_TIMESTAMP() - INTERVAL 7 DAY) AS failed_7d,
            SUM(status = 'failed' AND last_attempt_gmt >= UTC_TIMESTAMP() - INTERVAL 30 DAY) AS failed_30d,
            MIN(CASE WHEN status = 'pending' AND scheduled_date_gmt <= UTC_TIMESTAMP() THEN scheduled_date_gmt ELSE NULL END) AS oldest_due
        FROM `{$table}`",
        ARRAY_A
    );

    if (!is_array($stats)) {
        $results[] = seo_core_system_test_result(
            'technical',
            '7.8 Estado de Action Scheduler legible',
            false,
            'No se pudo consultar la cola: ' . $wpdb->last_error,
            'warning'
        );
        return $results;
    }

    foreach ($stats as $key => $value) {
        if ($key !== 'oldest_due') {
            $stats[$key] = (int) $value;
        }
    }

    $due_severity = $stats['pending_over_24h'] > 0
        ? 'ko'
        : ($stats['pending_over_1h'] > 0 ? 'warning' : 'ok');

    $results[] = seo_core_system_test_result(
        'technical',
        '7.8 Action Scheduler pendientes clasificados',
        $stats['pending_due'] === 0,
        'Pendientes: ' . number_format_i18n($stats['pending_total'])
            . '; futuras: ' . number_format_i18n($stats['pending_future'])
            . '; vencidas: ' . number_format_i18n($stats['pending_due']),
        $stats['pending_due'] === 0 ? 'ok' : $due_severity
    );

    $results[] = seo_core_system_test_result(
        'technical',
        '7.9 Action Scheduler sin retrasos graves',
        $stats['pending_over_24h'] === 0,
        'Vencidas >1 h: ' . number_format_i18n($stats['pending_over_1h'])
            . '; vencidas >24 h: ' . number_format_i18n($stats['pending_over_24h'])
            . ($stats['oldest_due'] ? '; más antigua: ' . $stats['oldest_due'] . ' UTC' : ''),
        $due_severity
    );

    $recent_failure_severity = 'ok';
    if ($stats['failed_7d'] > 0) {
        $recent_failure_severity = $stats['failed_7d'] >= 10 ? 'ko' : 'warning';
    }

    $results[] = seo_core_system_test_result(
        'technical',
        '7.10 Action Scheduler sin fallos recientes repetidos',
        $stats['failed_7d'] === 0,
        'Fallidas históricas: ' . number_format_i18n($stats['failed_total'])
            . '; últimos 30 días: ' . number_format_i18n($stats['failed_30d'])
            . '; últimos 7 días: ' . number_format_i18n($stats['failed_7d']),
        $recent_failure_severity
    );

    $group_join = function_exists('seo_core_system_test_table_exists')
        && seo_core_system_test_table_exists($groups_table)
        ? " LEFT JOIN `{$groups_table}` g ON g.group_id = a.group_id "
        : '';
    $group_select = $group_join !== '' ? "COALESCE(g.slug, '')" : "''";

    $top_pending = $wpdb->get_row(
        "SELECT a.hook, {$group_select} AS action_group, COUNT(*) AS total,
                MIN(a.scheduled_date_gmt) AS first_date,
                MAX(a.scheduled_date_gmt) AS last_date
         FROM `{$table}` a
         {$group_join}
         WHERE a.status = 'pending'
         GROUP BY a.hook" . ($group_join !== '' ? ', g.slug' : '') . "
         ORDER BY total DESC
         LIMIT 1",
        ARRAY_A
    );

    $top_detail = 'No hay acciones pendientes.';
    if (is_array($top_pending) && !empty($top_pending['hook'])) {
        $top_detail = (string) $top_pending['hook']
            . (!empty($top_pending['action_group']) ? ' [' . $top_pending['action_group'] . ']' : '')
            . ': ' . number_format_i18n((int) $top_pending['total'])
            . '; desde ' . (string) $top_pending['first_date']
            . ' hasta ' . (string) $top_pending['last_date'] . ' UTC';
    }

    $results[] = seo_core_system_test_result(
        'technical',
        '7.11 Mayor grupo de acciones futuras',
        true,
        $top_detail,
        'info'
    );

    return $results;
}

/**
 * Chequeos pasivos del Data Layer. No insertan, actualizan ni eliminan filas.
 *
 * @return array<int,array<string,mixed>>
 */
function seo_core_system_test_data_layer_passive_checks() {
    global $wpdb;

    $results = array();
    $required_classes = array('SEO_Data_Layer', 'SEO_Data_Operation', 'SEO_Data_Rollback');
    $missing_classes = array_values(array_filter($required_classes, static function ($class_name) {
        return !class_exists($class_name);
    }));

    $results[] = seo_core_system_test_result(
        'data_layer',
        '8.1 Clases del Data Layer cargadas',
        empty($missing_classes),
        empty($missing_classes)
            ? implode(', ', $required_classes)
            : 'No cargadas: ' . implode(', ', $missing_classes),
        empty($missing_classes) ? 'ok' : 'ko'
    );

    if (!empty($missing_classes)) {
        return $results;
    }

    try {
        $health = SEO_Data_Layer::health_check();
        $table_details = array();
        foreach ((array) $health['tables'] as $table_name => $table_health) {
            $table_details[] = $table_name . ': '
                . (!empty($table_health['exists']) ? (string) $table_health['engine'] : 'ausente')
                . (!empty($table_health['collation']) ? '/' . $table_health['collation'] : '');
        }

        $results[] = seo_core_system_test_result(
            'data_layer',
            '8.2 Tablas transaccionales preparadas',
            !empty($health['ready']),
            implode('; ', $table_details)
                . (!empty($health['errors']) ? '. ' . implode(' ', $health['errors']) : ''),
            !empty($health['ready']) ? 'ok' : 'ko'
        );
    } catch (Throwable $exception) {
        $results[] = seo_core_system_test_result(
            'data_layer',
            '8.2 Tablas transaccionales preparadas',
            false,
            $exception->getMessage(),
            'ko'
        );
        return $results;
    }

    $operations = SEO_Data_Layer::operations_table();
    $changes = SEO_Data_Layer::changes_table();

    $required_columns = array(
        $operations => array(
            'id', 'operation_uuid', 'operation_type', 'operation_label', 'source_module',
            'status', 'rollback_status', 'rollbackable', 'user_id', 'plugin_version',
            'created_at', 'started_at', 'completed_at', 'failed_at', 'affected_rows',
            'metadata', 'error_message',
        ),
        $changes => array(
            'id', 'operation_id', 'sequence_number', 'entity_type', 'entity_id',
            'table_name', 'action_type', 'record_identity', 'before_data', 'after_data',
            'before_hash', 'after_hash', 'rollback_status', 'rollback_error', 'created_at',
        ),
    );

    $missing_columns = array();
    foreach ($required_columns as $table_name => $columns) {
        try {
            $actual = SEO_Data_Layer::columns($table_name);
            foreach ($columns as $column) {
                if (!in_array($column, $actual, true)) {
                    $missing_columns[] = $table_name . '.' . $column;
                }
            }
        } catch (Throwable $exception) {
            $missing_columns[] = $table_name . ': ' . $exception->getMessage();
        }
    }

    $results[] = seo_core_system_test_result(
        'data_layer',
        '8.3 Esquema de auditoría completo',
        empty($missing_columns),
        empty($missing_columns)
            ? 'Todas las columnas obligatorias están disponibles.'
            : 'Faltan: ' . implode(', ', $missing_columns),
        empty($missing_columns) ? 'ok' : 'ko'
    );

    $totals = $wpdb->get_row(
        "SELECT
            COUNT(*) AS operations_total,
            SUM(status = 'completed') AS completed_total,
            SUM(status = 'failed') AS failed_total,
            SUM(status = 'rolled_back') AS rolled_back_total,
            SUM(status = 'running' AND started_at < UTC_TIMESTAMP() - INTERVAL 1 HOUR) AS running_stale,
            SUM(status IN ('pending','validated','previewed') AND created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY) AS preparatory_stale,
            SUM(rollbackable = 0 AND rollback_status = 'available') AS invalid_rollback_flags,
            SUM(status = 'rolled_back' AND rollback_status <> 'completed') AS invalid_rolled_back_state
         FROM `{$operations}`",
        ARRAY_A
    );

    $totals = is_array($totals) ? $totals : array();
    foreach (array(
        'operations_total', 'completed_total', 'failed_total', 'rolled_back_total',
        'running_stale', 'preparatory_stale', 'invalid_rollback_flags', 'invalid_rolled_back_state',
    ) as $key) {
        $totals[$key] = isset($totals[$key]) ? (int) $totals[$key] : 0;
    }

    $results[] = seo_core_system_test_result(
        'data_layer',
        '8.4 Operaciones auditadas',
        true,
        'Total: ' . number_format_i18n($totals['operations_total'])
            . '; completadas: ' . number_format_i18n($totals['completed_total'])
            . '; fallidas: ' . number_format_i18n($totals['failed_total'])
            . '; revertidas: ' . number_format_i18n($totals['rolled_back_total']),
        'info'
    );

    $stale_count = $totals['running_stale'] + $totals['preparatory_stale'];
    $results[] = seo_core_system_test_result(
        'data_layer',
        '8.5 Sin operaciones bloqueadas',
        $stale_count === 0,
        'Running >1 h: ' . number_format_i18n($totals['running_stale'])
            . '; pending/validated/previewed >24 h: ' . number_format_i18n($totals['preparatory_stale']),
        $totals['running_stale'] > 0 ? 'ko' : ($totals['preparatory_stale'] > 0 ? 'warning' : 'ok')
    );

    $orphan_changes = (int) $wpdb->get_var(
        "SELECT COUNT(*)
         FROM `{$changes}` c
         LEFT JOIN `{$operations}` o ON o.id = c.operation_id
         WHERE o.id IS NULL"
    );

    $results[] = seo_core_system_test_result(
        'data_layer',
        '8.6 Cambios vinculados a una operación',
        $orphan_changes === 0,
        'Cambios huérfanos: ' . number_format_i18n($orphan_changes),
        $orphan_changes === 0 ? 'ok' : 'ko'
    );

    $completed_without_changes = (int) $wpdb->get_var(
        "SELECT COUNT(*)
         FROM (
             SELECT o.id
             FROM `{$operations}` o
             LEFT JOIN `{$changes}` c ON c.operation_id = o.id
             WHERE o.status = 'completed'
               AND o.affected_rows > 0
             GROUP BY o.id
             HAVING COUNT(c.id) = 0
         ) missing_snapshots"
    );

    $results[] = seo_core_system_test_result(
        'data_layer',
        '8.7 Operaciones completadas con snapshot',
        $completed_without_changes === 0,
        'Operaciones con filas afectadas pero sin cambios registrados: ' . number_format_i18n($completed_without_changes),
        $completed_without_changes === 0 ? 'ok' : 'ko'
    );

    $missing_hashes = (int) $wpdb->get_var(
        "SELECT COUNT(*)
         FROM `{$changes}`
         WHERE record_identity IS NULL OR record_identity = ''
            OR (action_type = 'insert' AND (after_hash IS NULL OR after_hash = ''))
            OR (action_type = 'delete' AND (before_hash IS NULL OR before_hash = ''))
            OR (action_type = 'update' AND (
                before_hash IS NULL OR before_hash = '' OR after_hash IS NULL OR after_hash = ''
            ))"
    );

    $results[] = seo_core_system_test_result(
        'data_layer',
        '8.8 Identidad y hashes de cambios completos',
        $missing_hashes === 0,
        'Cambios incompletos: ' . number_format_i18n($missing_hashes),
        $missing_hashes === 0 ? 'ok' : 'ko'
    );

    $invalid_state_count = $totals['invalid_rollback_flags'] + $totals['invalid_rolled_back_state'];
    $results[] = seo_core_system_test_result(
        'data_layer',
        '8.9 Estados de rollback coherentes',
        $invalid_state_count === 0,
        'Rollback disponible en operaciones no reversibles: ' . number_format_i18n($totals['invalid_rollback_flags'])
            . '; revertidas con estado incoherente: ' . number_format_i18n($totals['invalid_rolled_back_state']),
        $invalid_state_count === 0 ? 'ok' : 'ko'
    );

    $latest_active = seo_core_system_test_data_layer_get_active_test_state();
    if (!empty($latest_active['ran_at'])) {
        $results[] = seo_core_system_test_result(
            'data_layer',
            '8.10 Última prueba transaccional manual',
            !empty($latest_active['passed']),
            'Ejecutada: ' . wp_date('Y-m-d H:i:s', (int) $latest_active['ran_at'])
                . '; resultado: ' . (!empty($latest_active['passed']) ? 'correcto' : 'con incidencias'),
            !empty($latest_active['passed']) ? 'ok' : 'warning'
        );
    } else {
        $results[] = seo_core_system_test_result(
            'data_layer',
            '8.10 Prueba transaccional manual pendiente',
            false,
            'Todavía no se ha ejecutado la prueba activa. Solo se lanza desde la sección Data Layer de Chequeos avanzados.',
            'info'
        );
    }

    return $results;
}

function seo_core_system_test_data_layer_active_option_name() {
    return 'seo_core_system_test_data_layer_active_state';
}

function seo_core_system_test_data_layer_get_active_test_state() {
    $state = get_option(seo_core_system_test_data_layer_active_option_name(), array());
    return is_array($state) ? $state : array();
}

/**
 * Ejecuta pruebas reales usando únicamente filas marcadas como __seo_test__.
 * Limpia las relaciones y operaciones de prueba antes de devolver el resultado.
 *
 * @return array<int,array<string,mixed>>
 */
function seo_core_system_test_data_layer_run_active_tests() {
    global $wpdb;

    if (!current_user_can('manage_options')) {
        throw new RuntimeException('No tienes permisos para ejecutar pruebas transaccionales.');
    }

    $results = array();
    $operation_ids = array();
    $relation_ids = array();
    $relations_table = $wpdb->prefix . 'seo_relations';
    $operations_table = $wpdb->prefix . 'seo_operations';
    $changes_table = $wpdb->prefix . 'seo_operation_changes';
    $marker = '__seo_test__';

    $classes_ready = class_exists('SEO_Data_Layer')
        && class_exists('SEO_Data_Operation')
        && class_exists('SEO_Data_Rollback');

    if (!$classes_ready) {
        $results[] = seo_core_system_test_result(
            'data_layer',
            '8.20 Prueba activa: núcleo disponible',
            false,
            'Las clases del Data Layer no están cargadas.',
            'ko'
        );
        seo_core_system_test_data_layer_store_active_results($results);
        return $results;
    }

    try {
        SEO_Data_Layer::assert_ready();
        $results[] = seo_core_system_test_result(
            'data_layer',
            '8.20 Prueba activa: núcleo disponible',
            true,
            'Health check correcto antes de escribir.',
            'ok'
        );

        /* A. ROLLBACK automático al lanzar una excepción. */
        $source_a = seo_core_system_test_data_layer_test_id();
        $target_a = seo_core_system_test_data_layer_test_id();
        $row_a = array(
            'source_type'  => $marker,
            'source_id'    => $source_a,
            'target_type'  => $marker,
            'target_id'    => $target_a,
            'relation_type'=> 'data_layer_test_failure',
        );

        $operation_a = SEO_Data_Layer::operation(array(
            'type'          => 'data_layer_self_test_failure',
            'label'         => 'Data Layer self-test: rollback automático',
            'source_module' => 'plugin_validation',
            'rollbackable'  => true,
            'risk_level'    => 'low',
            'metadata'      => array('self_test' => true),
        ));
        $operation_ids[] = $operation_a->id();
        $operation_a->mark_validated()->mark_previewed(1);

        $expected_exception = false;
        try {
            $operation_a->execute(static function (SEO_Data_Operation $transaction) use ($row_a) {
                $transaction->insert('relations', $row_a, array('related_object_type' => '__seo_test__'));
                throw new RuntimeException('__seo_expected_rollback__');
            });
        } catch (Throwable $exception) {
            $expected_exception = strpos($exception->getMessage(), '__seo_expected_rollback__') !== false;
        }

        $row_a_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$relations_table}`
             WHERE source_type = %s AND source_id = %d AND target_type = %s AND target_id = %d AND relation_type = %s",
            $marker,
            $source_a,
            $marker,
            $target_a,
            'data_layer_test_failure'
        ));
        $status_a = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM `{$operations_table}` WHERE id = %d",
            $operation_a->id()
        ));
        $changes_a = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$changes_table}` WHERE operation_id = %d",
            $operation_a->id()
        ));
        $rollback_auto_ok = $expected_exception && $row_a_exists === 0 && $status_a === 'failed' && $changes_a === 0;

        $results[] = seo_core_system_test_result(
            'data_layer',
            '8.21 Prueba activa: rollback automático',
            $rollback_auto_ok,
            'Excepción esperada: ' . ($expected_exception ? 'sí' : 'no')
                . '; fila residual: ' . number_format_i18n($row_a_exists)
                . '; estado: ' . $status_a
                . '; cambios confirmados: ' . number_format_i18n($changes_a),
            $rollback_auto_ok ? 'ok' : 'ko'
        );

        /* B. COMMIT, auditoría y rollback manual. */
        $source_b = seo_core_system_test_data_layer_test_id();
        $target_b = seo_core_system_test_data_layer_test_id();
        $row_b = array(
            'source_type'  => $marker,
            'source_id'    => $source_b,
            'target_type'  => $marker,
            'target_id'    => $target_b,
            'relation_type'=> 'data_layer_test_commit',
        );

        $operation_b = SEO_Data_Layer::operation(array(
            'type'          => 'data_layer_self_test_commit',
            'label'         => 'Data Layer self-test: commit y rollback',
            'source_module' => 'plugin_validation',
            'rollbackable'  => true,
            'risk_level'    => 'low',
            'metadata'      => array('self_test' => true),
        ));
        $operation_ids[] = $operation_b->id();
        $operation_b->mark_validated()->mark_previewed(1);
        $inserted_b = $operation_b->execute(static function (SEO_Data_Operation $transaction) use ($row_b) {
            return $transaction->insert('relations', $row_b, array('related_object_type' => '__seo_test__'));
        });
        $relation_ids[] = isset($inserted_b['id']) ? (int) $inserted_b['id'] : 0;

        $operation_b_row = $wpdb->get_row($wpdb->prepare(
            "SELECT status, rollback_status, affected_rows FROM `{$operations_table}` WHERE id = %d",
            $operation_b->id()
        ), ARRAY_A);
        $change_b = $wpdb->get_row($wpdb->prepare(
            "SELECT action_type, before_hash, after_hash, record_identity FROM `{$changes_table}` WHERE operation_id = %d LIMIT 1",
            $operation_b->id()
        ), ARRAY_A);

        $commit_ok = is_array($operation_b_row)
            && $operation_b_row['status'] === 'completed'
            && $operation_b_row['rollback_status'] === 'available'
            && (int) $operation_b_row['affected_rows'] === 1
            && is_array($change_b)
            && $change_b['action_type'] === 'insert'
            && empty($change_b['before_hash'])
            && !empty($change_b['after_hash'])
            && !empty($change_b['record_identity']);

        $results[] = seo_core_system_test_result(
            'data_layer',
            '8.22 Prueba activa: commit y snapshot',
            $commit_ok,
            $commit_ok
                ? 'Operación completada, una fila afectada y snapshot posterior registrado.'
                : 'La operación o su snapshot no contienen el estado esperado.',
            $commit_ok ? 'ok' : 'ko'
        );

        $preview_b = SEO_Data_Rollback::preview($operation_b->id());
        $rollback_b = SEO_Data_Rollback::execute($operation_b->id());
        $row_b_exists = isset($inserted_b['id'])
            ? (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$relations_table}` WHERE id = %d",
                (int) $inserted_b['id']
            ))
            : 1;
        $status_b = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM `{$operations_table}` WHERE id = %d",
            $operation_b->id()
        ));
        $manual_rollback_ok = !empty($preview_b['allowed'])
            && (int) ($rollback_b['restored'] ?? 0) === 1
            && $row_b_exists === 0
            && $status_b === 'rolled_back';

        $results[] = seo_core_system_test_result(
            'data_layer',
            '8.23 Prueba activa: rollback manual',
            $manual_rollback_ok,
            'Preview permitido: ' . (!empty($preview_b['allowed']) ? 'sí' : 'no')
                . '; restaurados/revertidos: ' . number_format_i18n((int) ($rollback_b['restored'] ?? 0))
                . '; fila residual: ' . number_format_i18n($row_b_exists)
                . '; estado: ' . $status_b,
            $manual_rollback_ok ? 'ok' : 'ko'
        );

        /* C. Detección de conflicto después de una modificación externa. */
        $source_c = seo_core_system_test_data_layer_test_id();
        $target_c = seo_core_system_test_data_layer_test_id();
        $row_c = array(
            'source_type'  => $marker,
            'source_id'    => $source_c,
            'target_type'  => $marker,
            'target_id'    => $target_c,
            'relation_type'=> 'data_layer_test_conflict',
        );

        $operation_c = SEO_Data_Layer::operation(array(
            'type'          => 'data_layer_self_test_conflict',
            'label'         => 'Data Layer self-test: detección de conflicto',
            'source_module' => 'plugin_validation',
            'rollbackable'  => true,
            'risk_level'    => 'low',
            'metadata'      => array('self_test' => true),
        ));
        $operation_ids[] = $operation_c->id();
        $operation_c->mark_validated()->mark_previewed(1);
        $inserted_c = $operation_c->execute(static function (SEO_Data_Operation $transaction) use ($row_c) {
            return $transaction->insert('relations', $row_c, array('related_object_type' => '__seo_test__'));
        });
        $relation_c_id = isset($inserted_c['id']) ? (int) $inserted_c['id'] : 0;
        if ($relation_c_id > 0) {
            $relation_ids[] = $relation_c_id;
        }

        $external_update = $relation_c_id > 0
            ? $wpdb->update(
                $relations_table,
                array('relation_type' => 'data_layer_test_modified'),
                array('id' => $relation_c_id),
                array('%s'),
                array('%d')
            )
            : false;
        $preview_c = SEO_Data_Rollback::preview($operation_c->id());
        $conflict_ok = $external_update !== false
            && empty($preview_c['allowed'])
            && (int) ($preview_c['conflicts'] ?? 0) === 1;

        $results[] = seo_core_system_test_result(
            'data_layer',
            '8.24 Prueba activa: detección de conflictos',
            $conflict_ok,
            'Modificación externa aplicada: ' . ($external_update !== false ? 'sí' : 'no')
                . '; rollback permitido: ' . (!empty($preview_c['allowed']) ? 'sí' : 'no')
                . '; conflictos: ' . number_format_i18n((int) ($preview_c['conflicts'] ?? 0)),
            $conflict_ok ? 'ok' : 'ko'
        );
    } catch (Throwable $exception) {
        $results[] = seo_core_system_test_result(
            'data_layer',
            '8.29 Prueba activa interrumpida',
            false,
            $exception->getMessage(),
            'ko'
        );
    } finally {
        foreach (array_filter(array_unique(array_map('intval', $relation_ids))) as $relation_id) {
            $wpdb->delete($relations_table, array('id' => $relation_id), array('%d'));
        }

        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$relations_table}`
             WHERE source_type = %s AND target_type = %s
               AND relation_type LIKE %s",
            $marker,
            $marker,
            'data_layer_test_%'
        ));

        foreach (array_filter(array_unique(array_map('intval', $operation_ids))) as $operation_id) {
            $wpdb->delete($changes_table, array('operation_id' => $operation_id), array('%d'));
            $wpdb->delete($operations_table, array('id' => $operation_id), array('%d'));
        }
    }

    $cleanup_relations = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$relations_table}`
         WHERE source_type = %s AND target_type = %s AND relation_type LIKE %s",
        $marker,
        $marker,
        'data_layer_test_%'
    ));
    $cleanup_operations = 0;
    if (!empty($operation_ids)) {
        $ids = implode(',', array_map('intval', array_filter(array_unique($operation_ids))));
        if ($ids !== '') {
            $cleanup_operations = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$operations_table}` WHERE id IN ({$ids})"
            );
        }
    }
    $cleanup_ok = $cleanup_relations === 0 && $cleanup_operations === 0;

    $results[] = seo_core_system_test_result(
        'data_layer',
        '8.25 Prueba activa: limpieza de artefactos',
        $cleanup_ok,
        'Relaciones de prueba residuales: ' . number_format_i18n($cleanup_relations)
            . '; operaciones de prueba residuales: ' . number_format_i18n($cleanup_operations),
        $cleanup_ok ? 'ok' : 'ko'
    );

    seo_core_system_test_data_layer_store_active_results($results);

    return $results;
}

function seo_core_system_test_data_layer_test_id() {
    try {
        return 900000000000 + random_int(1, 999999999);
    } catch (Throwable $exception) {
        return 900000000000 + wp_rand(1, 999999999);
    }
}

/**
 * @param array<int,array<string,mixed>> $results
 */
function seo_core_system_test_data_layer_store_active_results($results) {
    $passed = true;
    foreach ((array) $results as $result) {
        if (isset($result['severity']) && $result['severity'] === 'ko') {
            $passed = false;
            break;
        }
    }

    update_option(
        seo_core_system_test_data_layer_active_option_name(),
        array(
            'version' => SEO_CORE_DATA_LAYER_TEST_VERSION,
            'ran_at'  => time(),
            'passed'  => $passed,
            'results' => array_values((array) $results),
        ),
        false
    );
}

/**
 * @return array<int,array<string,mixed>>
 */
function seo_core_system_test_data_layer_active_results() {
    $state = seo_core_system_test_data_layer_get_active_test_state();
    return isset($state['results']) && is_array($state['results'])
        ? $state['results']
        : array();
}

function seo_core_system_test_render_data_layer_intro() {
    $state = seo_core_system_test_data_layer_get_active_test_state();

    echo '<div class="seo-core-test-note">';
    echo '<strong>Dos niveles de prueba:</strong> los chequeos pasivos solo leen esquema y estados. La prueba transaccional manual crea relaciones marcadas como <code>__seo_test__</code>, comprueba rollback, auditoría y conflictos, y elimina sus artefactos al terminar.';
    echo '</div>';

    if (!empty($state['ran_at'])) {
        echo '<p class="description">Última prueba activa: '
            . esc_html(wp_date('Y-m-d H:i:s', (int) $state['ran_at']))
            . ' · ' . (!empty($state['passed']) ? '<strong style="color:#008a20;">Correcta</strong>' : '<strong style="color:#b32d2e;">Con incidencias</strong>')
            . '.</p>';
    }
}
