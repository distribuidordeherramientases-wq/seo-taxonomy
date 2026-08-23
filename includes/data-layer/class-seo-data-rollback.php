<?php
/**
 * Simulación y ejecución segura de rollback.
 */

defined('ABSPATH') || exit;

final class SEO_Data_Rollback
{
    /**
     * @return array<string,mixed>
     */
    public static function preview(int $operation_id): array
    {
        global $wpdb;

        $operation = self::get_operation($operation_id);
        $changes = self::get_changes($operation_id);

        $result = [
            'operation_id' => $operation_id,
            'allowed'      => true,
            'reversible'   => 0,
            'conflicts'    => 0,
            'items'        => [],
            'errors'       => [],
        ];

        if ((int) $operation['rollbackable'] !== 1) {
            $result['allowed'] = false;
            $result['errors'][] = 'La operación no fue marcada como reversible.';
        }

        if (!in_array($operation['status'], ['completed', 'rolled_back'], true)) {
            $result['allowed'] = false;
            $result['errors'][] = 'La operación no está completada.';
        }

        if ($operation['status'] === 'rolled_back') {
            $result['allowed'] = false;
            $result['errors'][] = 'La operación ya fue revertida.';
        }

        foreach ($changes as $change) {
            $item = self::inspect_change($change);
            $result['items'][] = $item;

            if ($item['status'] === 'reversible') {
                $result['reversible']++;
            } else {
                $result['conflicts']++;
                $result['allowed'] = false;
            }
        }

        if (empty($changes)) {
            $result['allowed'] = false;
            $result['errors'][] = 'La operación no contiene cambios registrados.';
        }

        return $result;
    }

    /**
     * Ejecuta el rollback completo. No permite rollback parcial silencioso.
     *
     * @return array<string,mixed>
     */
    public static function execute(int $operation_id): array
    {
        global $wpdb;

        $preview = self::preview($operation_id);

        if (empty($preview['allowed'])) {
            throw new RuntimeException(
                'El rollback está bloqueado por conflictos o por el estado de la operación.'
            );
        }

        $wpdb->query('START TRANSACTION');

        try {
            $operation = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT * FROM `' . SEO_Data_Layer::operations_table() . '` WHERE id = %d FOR UPDATE',
                    $operation_id
                ),
                ARRAY_A
            );

            if (!is_array($operation)) {
                throw new RuntimeException('La operación ha desaparecido.');
            }

            $wpdb->update(
                SEO_Data_Layer::operations_table(),
                ['rollback_status' => 'running'],
                ['id' => $operation_id]
            );

            $changes = self::get_changes($operation_id, true);
            $restored = 0;

            foreach ($changes as $change) {
                self::rollback_change($change);

                $updated = $wpdb->update(
                    SEO_Data_Layer::changes_table(),
                    [
                        'rollback_status' => 'rolled_back',
                        'rollback_error'  => null,
                    ],
                    ['id' => (int) $change['id']]
                );

                if ($updated === false) {
                    throw new RuntimeException(
                        'No se pudo actualizar el estado del cambio revertido: ' . $wpdb->last_error
                    );
                }

                $restored++;
            }

            $updated = $wpdb->update(
                SEO_Data_Layer::operations_table(),
                [
                    'status'          => 'rolled_back',
                    'rollback_status' => 'completed',
                    'rolled_back_at'  => current_time('mysql', true),
                    'rolled_back_by'  => get_current_user_id(),
                    'error_message'   => null,
                ],
                ['id' => $operation_id]
            );

            if ($updated === false) {
                throw new RuntimeException(
                    'No se pudo finalizar el rollback: ' . $wpdb->last_error
                );
            }

            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException('MariaDB no pudo confirmar el rollback.');
            }

            return [
                'operation_id' => $operation_id,
                'restored'     => $restored,
                'status'       => 'rolled_back',
            ];
        } catch (Throwable $exception) {
            $wpdb->query('ROLLBACK');

            $wpdb->update(
                SEO_Data_Layer::operations_table(),
                [
                    'rollback_status' => 'failed',
                    'error_message'   => $exception->getMessage(),
                ],
                ['id' => $operation_id]
            );

            error_log(
                sprintf(
                    '[SEO Data Layer] Rollback de operación %d fallido: %s',
                    $operation_id,
                    $exception->getMessage()
                )
            );

            throw $exception;
        }
    }

    /**
     * @param array<string,mixed> $change
     * @return array<string,mixed>
     */
    private static function inspect_change(array $change): array
    {
        $identity = self::decode_object($change['record_identity']);
        $table = (string) $change['table_name'];
        $action = (string) $change['action_type'];

        SEO_Data_Layer::table_by_physical_name($table);

        $current = SEO_Data_Layer::fetch_row($table, $identity, false);
        $status = 'reversible';
        $reason = '';

        if ($action === 'delete') {
            if ($current !== null) {
                $status = 'conflict';
                $reason = 'La fila eliminada vuelve a existir.';
            }
        } elseif ($action === 'insert' || $action === 'update') {
            if ($current === null) {
                $status = 'conflict';
                $reason = 'La fila actual ya no existe.';
            } elseif (
                empty($change['after_hash']) ||
                !hash_equals((string) $change['after_hash'], SEO_Data_Layer::hash_record($current))
            ) {
                $status = 'conflict';
                $reason = 'La fila fue modificada después de la operación.';
            }
        } else {
            $status = 'conflict';
            $reason = 'Tipo de cambio no soportado: ' . $action;
        }

        return [
            'change_id' => (int) $change['id'],
            'table'     => $table,
            'action'    => $action,
            'identity'  => $identity,
            'status'    => $status,
            'reason'    => $reason,
        ];
    }

    /**
     * @param array<string,mixed> $change
     */
    private static function rollback_change(array $change): void
    {
        global $wpdb;

        $table = (string) $change['table_name'];
        $action = (string) $change['action_type'];
        $identity = self::decode_object($change['record_identity']);
        $before = self::decode_nullable_object($change['before_data']);
        $after = self::decode_nullable_object($change['after_data']);

        $config = SEO_Data_Layer::table_by_physical_name($table);
        $current = SEO_Data_Layer::fetch_row($table, $identity, true);

        if ($action === 'insert') {
            self::assert_current_matches($current, $change['after_hash']);

            $deleted = $wpdb->delete($table, $identity);

            if ($deleted === false || (int) $deleted !== 1) {
                throw new RuntimeException('No se pudo revertir la inserción en ' . $table . '.');
            }

            return;
        }

        if ($action === 'delete') {
            if ($current !== null) {
                throw new RuntimeException('Conflicto: la fila que debe restaurarse ya existe.');
            }

            if ($before === null) {
                throw new RuntimeException('El snapshot anterior de la eliminación está vacío.');
            }

            SEO_Data_Layer::assert_valid_columns($table, $before);

            if ($wpdb->insert($table, $before) === false) {
                throw new RuntimeException(
                    'No se pudo restaurar la fila eliminada en ' . $table . ': ' . $wpdb->last_error
                );
            }

            return;
        }

        if ($action === 'update') {
            self::assert_current_matches($current, $change['after_hash']);

            if ($before === null || $after === null) {
                throw new RuntimeException('El snapshot de la actualización está incompleto.');
            }

            $restore = $before;

            foreach ((array) $config['primary_key'] as $primary_column) {
                unset($restore[$primary_column]);
            }

            SEO_Data_Layer::assert_valid_columns($table, $restore);

            $updated = $wpdb->update($table, $restore, $identity);

            if ($updated === false) {
                throw new RuntimeException(
                    'No se pudo restaurar la actualización en ' . $table . ': ' . $wpdb->last_error
                );
            }

            return;
        }

        throw new RuntimeException('Tipo de rollback no soportado: ' . $action);
    }

    /**
     * @param array<string,mixed>|null $current
     * @param mixed $expected_hash
     */
    private static function assert_current_matches(?array $current, $expected_hash): void
    {
        if ($current === null) {
            throw new RuntimeException('Conflicto: la fila actual ya no existe.');
        }

        if (!is_string($expected_hash) || $expected_hash === '') {
            throw new RuntimeException('El cambio no tiene hash posterior válido.');
        }

        if (!hash_equals($expected_hash, SEO_Data_Layer::hash_record($current))) {
            throw new RuntimeException('Conflicto: la fila cambió después de la operación original.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function get_operation(int $operation_id): array
    {
        global $wpdb;

        $operation = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM `' . SEO_Data_Layer::operations_table() . '` WHERE id = %d',
                $operation_id
            ),
            ARRAY_A
        );

        if (!is_array($operation)) {
            throw new RuntimeException('No existe la operación solicitada.');
        }

        return $operation;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function get_changes(int $operation_id, bool $for_update = false): array
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            'SELECT * FROM `' . SEO_Data_Layer::changes_table() . '` WHERE operation_id = %d ORDER BY sequence_number DESC, id DESC',
            $operation_id
        );

        if ($for_update) {
            $sql .= ' FOR UPDATE';
        }

        $changes = $wpdb->get_results($sql, ARRAY_A);

        return is_array($changes) ? $changes : [];
    }

    /**
     * @param mixed $json
     * @return array<string,mixed>
     */
    private static function decode_object($json): array
    {
        if (!is_string($json) || $json === '') {
            throw new RuntimeException('Información JSON de auditoría ausente.');
        }

        $value = json_decode($json, true);

        if (!is_array($value) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Información JSON de auditoría dañada.');
        }

        return $value;
    }

    /**
     * @param mixed $json
     * @return array<string,mixed>|null
     */
    private static function decode_nullable_object($json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }

        return self::decode_object($json);
    }
}
