<?php
/**
 * Operación transaccional y auditable del Data Layer.
 */

defined('ABSPATH') || exit;

final class SEO_Data_Operation
{
    /** @var int */
    private $id = 0;

    /** @var string */
    private $uuid = '';

    /** @var array<string,mixed> */
    private $args = [];

    /** @var array<string,mixed> */
    private $metadata = [];

    /** @var int */
    private $sequence = 0;

    /** @var int */
    private $affected_rows = 0;

    /** @var bool */
    private $running = false;

    /** @var bool */
    private $finished = false;

    /**
     * @param array<string,mixed> $args
     */
    public function __construct(array $args)
    {
        $defaults = [
            'type'          => '',
            'label'         => '',
            'source_module' => '',
            'rollbackable'  => true,
            'risk_level'    => 'medium',
            'audit_level'   => 'full',
            'metadata'      => [],
        ];

        $args = wp_parse_args($args, $defaults);

        if ($args['type'] === '' || $args['label'] === '') {
            throw new InvalidArgumentException('La operación necesita type y label.');
        }

        $this->args = $args;
        $this->metadata = is_array($args['metadata']) ? $args['metadata'] : [];
        $this->uuid = wp_generate_uuid4();

        $this->create_audit_row();
    }

    public function id(): int
    {
        return $this->id;
    }

    public function uuid(): string
    {
        return $this->uuid;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function mark_validated(array $metadata = []): self
    {
        $this->assert_not_finished();
        $this->merge_metadata($metadata);

        $this->update_operation([
            'status'       => 'validated',
            'validated_at' => current_time('mysql', true),
            'metadata'     => $this->encode($this->metadata),
        ]);

        return $this;
    }

    /**
     * Registra la simulación previa sin modificar las tablas de negocio.
     *
     * @param array<string,mixed> $metadata
     */
    public function mark_previewed(int $expected_changes, array $metadata = []): self
    {
        $this->assert_not_finished();

        $this->merge_metadata(
            array_merge(
                ['preview_expected_changes' => max(0, $expected_changes)],
                $metadata
            )
        );

        $this->update_operation([
            'status'       => 'previewed',
            'previewed_at' => current_time('mysql', true),
            'metadata'     => $this->encode($this->metadata),
        ]);

        return $this;
    }

    /**
     * Ejecuta la operación dentro de una transacción SQL real.
     *
     * @param callable $callback function (SEO_Data_Operation $operation): mixed
     * @return mixed
     */
    public function execute(callable $callback)
    {
        global $wpdb;

        $this->assert_not_finished();

        if ($this->running) {
            throw new LogicException('La operación ya está en ejecución.');
        }

        $this->running = true;
        $wpdb->query('START TRANSACTION');

        try {
            $this->update_operation([
                'status'     => 'running',
                'started_at' => current_time('mysql', true),
            ]);

            $result = $callback($this);

            $rollback_status = (
                !empty($this->args['rollbackable']) && $this->affected_rows > 0
            ) ? 'available' : 'not_available';

            $this->update_operation([
                'status'          => 'completed',
                'rollback_status' => $rollback_status,
                'affected_rows'   => $this->affected_rows,
                'completed_at'    => current_time('mysql', true),
                'metadata'        => $this->encode($this->metadata),
                'error_message'   => null,
            ]);

            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException('MariaDB no pudo confirmar la transacción.');
            }

            $this->finished = true;
            $this->running = false;

            return $result;
        } catch (Throwable $exception) {
            $wpdb->query('ROLLBACK');
            $this->running = false;
            $this->finished = true;

            $this->mark_failed_outside_transaction($exception);

            throw $exception;
        }
    }

    /**
     * Inserta una fila en una tabla registrada.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function insert(string $table_key, array $data, array $context = []): array
    {
        global $wpdb;

        $this->assert_running();

        $config = SEO_Data_Layer::table($table_key);
        $table = (string) $config['table'];

        SEO_Data_Layer::assert_valid_columns($table, $data);

        if ($wpdb->insert($table, $data) === false) {
            throw new RuntimeException(
                sprintf('No se pudo insertar en %s: %s', $table, $wpdb->last_error)
            );
        }

        $identity = $this->resolve_insert_identity($config, $data, (int) $wpdb->insert_id);
        $after = SEO_Data_Layer::fetch_row($table, $identity, true);

        if ($after === null) {
            throw new RuntimeException('La fila insertada no se pudo recuperar para auditarla.');
        }

        $this->record_change(
            $config,
            'insert',
            $identity,
            null,
            $after,
            $context
        );

        return $after;
    }

    /**
     * Actualiza una fila identificada de forma inequívoca.
     *
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $changes
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function update(
        string $table_key,
        array $identity,
        array $changes,
        array $context = []
    ): array {
        global $wpdb;

        $this->assert_running();

        if (empty($changes)) {
            throw new InvalidArgumentException('No hay cambios que aplicar.');
        }

        $config = SEO_Data_Layer::table($table_key);
        $table = (string) $config['table'];

        $this->assert_primary_identity($config, $identity);
        SEO_Data_Layer::assert_valid_columns($table, $changes);

        foreach ((array) $config['primary_key'] as $primary_column) {
            if (array_key_exists($primary_column, $changes)) {
                throw new InvalidArgumentException('No se permite modificar la clave primaria.');
            }
        }

        $before = SEO_Data_Layer::fetch_row($table, $identity, true);

        if ($before === null) {
            throw new RuntimeException('No existe la fila que se intenta actualizar.');
        }

        $result = $wpdb->update($table, $changes, $identity);

        if ($result === false) {
            throw new RuntimeException(
                sprintf('No se pudo actualizar %s: %s', $table, $wpdb->last_error)
            );
        }

        $after = SEO_Data_Layer::fetch_row($table, $identity, true);

        if ($after === null) {
            throw new RuntimeException('La fila actualizada ha desaparecido durante la operación.');
        }

        if (SEO_Data_Layer::hash_record($before) !== SEO_Data_Layer::hash_record($after)) {
            $this->record_change(
                $config,
                'update',
                $identity,
                $before,
                $after,
                $context
            );
        }

        return $after;
    }

    /**
     * Elimina una fila y conserva una instantánea completa para restaurarla.
     *
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function delete(
        string $table_key,
        array $identity,
        array $context = []
    ): array {
        global $wpdb;

        $this->assert_running();

        $config = SEO_Data_Layer::table($table_key);
        $table = (string) $config['table'];

        $this->assert_primary_identity($config, $identity);

        $before = SEO_Data_Layer::fetch_row($table, $identity, true);

        if ($before === null) {
            throw new RuntimeException('No existe la fila que se intenta eliminar.');
        }

        $deleted = $wpdb->delete($table, $identity);

        if ($deleted === false) {
            throw new RuntimeException(
                sprintf('No se pudo eliminar de %s: %s', $table, $wpdb->last_error)
            );
        }

        if ((int) $deleted !== 1) {
            throw new RuntimeException(
                sprintf('La eliminación en %s afectó %d filas; se esperaba exactamente una.', $table, $deleted)
            );
        }

        $this->record_change(
            $config,
            'delete',
            $identity,
            $before,
            null,
            $context
        );

        return $before;
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $identity
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     * @param array<string,mixed> $context
     */
    private function record_change(
        array $config,
        string $action,
        array $identity,
        ?array $before,
        ?array $after,
        array $context
    ): void {
        global $wpdb;

        $this->sequence++;

        $entity_id = '';
        $primary_key = (array) $config['primary_key'];

        if (count($primary_key) === 1 && isset($identity[$primary_key[0]])) {
            $entity_id = (string) $identity[$primary_key[0]];
        } else {
            $entity_id = $this->encode($identity);
        }

        $inserted = $wpdb->insert(
            SEO_Data_Layer::changes_table(),
            [
                'operation_id'       => $this->id,
                'sequence_number'    => $this->sequence,
                'entity_type'        => (string) $config['entity_type'],
                'entity_id'          => $entity_id,
                'related_object_type'=> (string) ($context['related_object_type'] ?? ''),
                'related_object_id'  => (int) ($context['related_object_id'] ?? 0),
                'table_name'         => (string) $config['table'],
                'action_type'        => $action,
                'record_identity'    => $this->encode($identity),
                'before_data'        => $before === null ? null : $this->encode($before),
                'after_data'         => $after === null ? null : $this->encode($after),
                'before_hash'        => $before === null ? null : SEO_Data_Layer::hash_record($before),
                'after_hash'         => $after === null ? null : SEO_Data_Layer::hash_record($after),
                'rollback_status'    => 'pending',
                'rollback_error'     => null,
                'created_at'         => current_time('mysql', true),
            ]
        );

        if ($inserted === false) {
            throw new RuntimeException(
                'No se pudo registrar el cambio de la operación: ' . $wpdb->last_error
            );
        }

        $this->affected_rows++;
    }

    private function create_audit_row(): void
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            SEO_Data_Layer::operations_table(),
            [
                'operation_uuid' => $this->uuid,
                'operation_type' => sanitize_key((string) $this->args['type']),
                'operation_label'=> sanitize_text_field((string) $this->args['label']),
                'source_module'  => sanitize_key((string) $this->args['source_module']),
                'status'         => 'pending',
                'rollback_status'=> 'not_available',
                'rollbackable'   => !empty($this->args['rollbackable']) ? 1 : 0,
                'risk_level'     => sanitize_key((string) $this->args['risk_level']),
                'audit_level'    => sanitize_key((string) $this->args['audit_level']),
                'user_id'        => get_current_user_id(),
                'plugin_version' => defined('SEO_SYSTEM_VERSION') ? SEO_SYSTEM_VERSION : '',
                'created_at'     => current_time('mysql', true),
                'metadata'       => $this->encode($this->metadata),
            ]
        );

        if ($inserted === false || (int) $wpdb->insert_id < 1) {
            throw new RuntimeException(
                'No se pudo crear la operación de auditoría: ' . $wpdb->last_error
            );
        }

        $this->id = (int) $wpdb->insert_id;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function update_operation(array $data): void
    {
        global $wpdb;

        $updated = $wpdb->update(
            SEO_Data_Layer::operations_table(),
            $data,
            ['id' => $this->id]
        );

        if ($updated === false) {
            throw new RuntimeException(
                'No se pudo actualizar el estado de la operación: ' . $wpdb->last_error
            );
        }
    }

    private function mark_failed_outside_transaction(Throwable $exception): void
    {
        global $wpdb;

        $wpdb->update(
            SEO_Data_Layer::operations_table(),
            [
                'status'          => 'failed',
                'rollback_status' => 'not_available',
                'failed_at'       => current_time('mysql', true),
                'affected_rows'   => 0,
                'error_message'   => $exception->getMessage(),
                'metadata'        => $this->encode($this->metadata),
            ],
            ['id' => $this->id]
        );

        $is_controlled_self_test = (
            isset($this->args['source_module'], $this->args['type'])
            && (string) $this->args['source_module'] === 'plugin_validation'
            && strpos((string) $this->args['type'], 'data_layer_self_test_') === 0
        );

        if (!$is_controlled_self_test) {
            error_log(
                sprintf(
                    '[SEO Data Layer] Operación %s fallida: %s',
                    $this->uuid,
                    $exception->getMessage()
                )
            );
        }
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function resolve_insert_identity(array $config, array $data, int $insert_id): array
    {
        $identity = [];
        $primary_key = (array) $config['primary_key'];

        foreach ($primary_key as $column) {
            if (array_key_exists($column, $data)) {
                $identity[$column] = $data[$column];
                continue;
            }

            if (count($primary_key) === 1 && $insert_id > 0) {
                $identity[$column] = $insert_id;
                continue;
            }

            throw new RuntimeException('No se pudo determinar la identidad de la fila insertada.');
        }

        return $identity;
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $identity
     */
    private function assert_primary_identity(array $config, array $identity): void
    {
        $required = (array) $config['primary_key'];
        $provided = array_keys($identity);

        sort($required);
        sort($provided);

        if ($required !== $provided) {
            throw new InvalidArgumentException(
                'La operación debe identificar la fila usando exactamente su clave primaria.'
            );
        }

        SEO_Data_Layer::assert_valid_columns((string) $config['table'], $identity);
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function merge_metadata(array $metadata): void
    {
        $this->metadata = array_replace_recursive($this->metadata, $metadata);
    }

    /**
     * @param mixed $value
     */
    private function encode($value): string
    {
        $json = wp_json_encode(
            SEO_Data_Layer::normalize_value($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (!is_string($json)) {
            throw new RuntimeException('No se pudo serializar la información de auditoría.');
        }

        return $json;
    }

    private function assert_running(): void
    {
        if (!$this->running || $this->finished) {
            throw new LogicException(
                'Las escrituras solo pueden ejecutarse dentro del callback de execute().'
            );
        }
    }

    private function assert_not_finished(): void
    {
        if ($this->finished) {
            throw new LogicException('La operación ya ha finalizado.');
        }
    }
}
