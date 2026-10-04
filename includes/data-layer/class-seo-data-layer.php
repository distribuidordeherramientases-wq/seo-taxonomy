<?php
/**
 * Fachada y registro seguro del Data Layer de SEO Taxonomy.
 */

defined('ABSPATH') || exit;

final class SEO_Data_Layer
{
    /** @var array<string,array<string,mixed>>|null */
    private static $tables = null;

    /** @var array<string,array<int,string>> */
    private static $column_cache = [];

    /**
     * Crea una operación auditable. La operación todavía no modifica datos.
     *
     * @param array<string,mixed> $args
     */
    public static function operation(array $args): SEO_Data_Operation
    {
        self::assert_ready();

        return new SEO_Data_Operation($args);
    }

    /**
     * Registro de tablas que pueden modificarse mediante el Data Layer.
     *
     * Las tablas de auditoría no se exponen aquí para evitar recursión o
     * modificaciones accidentales del historial.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function tables(): array
    {
        global $wpdb;

        if (self::$tables !== null) {
            return self::$tables;
        }

        $tables = [
            'relations' => [
                'table'       => $wpdb->prefix . 'seo_relations',
                'primary_key' => ['id'],
                'entity_type' => 'relation',
            ],
            'nodes' => [
                'table'       => $wpdb->prefix . 'seo_nodes',
                'primary_key' => ['id'],
                'entity_type' => 'node',
            ],
        ];

        /**
         * Permite registrar nuevas tablas cuando cada módulo sea migrado.
         * Cada entrada debe tener table, primary_key y entity_type.
         */
        $tables = apply_filters('seo_data_layer_tables', $tables);

        foreach ($tables as $key => $config) {
            if (!is_string($key) || !is_array($config)) {
                throw new RuntimeException('Registro de tablas del Data Layer inválido.');
            }

            if (empty($config['table']) || empty($config['primary_key']) || empty($config['entity_type'])) {
                throw new RuntimeException('Configuración incompleta para la tabla ' . esc_html((string) $key) . '.');
            }

            self::assert_identifier((string) $config['table']);

            foreach ((array) $config['primary_key'] as $column) {
                self::assert_identifier((string) $column);
            }
        }

        self::$tables = $tables;

        return self::$tables;
    }

    /**
     * @return array<string,mixed>
     */
    public static function table(string $key): array
    {
        $tables = self::tables();

        if (!isset($tables[$key])) {
            throw new InvalidArgumentException(
                sprintf('La tabla lógica "%s" no está registrada en el Data Layer.', esc_html($key))
            );
        }

        return $tables[$key];
    }

    /**
     * @return array<string,mixed>
     */
    public static function table_by_physical_name(string $table_name): array
    {
        foreach (self::tables() as $key => $config) {
            if ($config['table'] === $table_name) {
                $config['key'] = $key;
                return $config;
            }
        }

        throw new InvalidArgumentException(
            sprintf('La tabla "%s" no está registrada en el Data Layer.', esc_html($table_name))
        );
    }

    public static function operations_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'seo_operations';
    }

    public static function changes_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'seo_operation_changes';
    }

    /**
     * @return array<int,string>
     */
    public static function columns(string $table_name): array
    {
        global $wpdb;

        self::assert_identifier($table_name);

        if (isset(self::$column_cache[$table_name])) {
            return self::$column_cache[$table_name];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name se valida mediante assert_identifier() antes de construir el identificador.
        $columns = $wpdb->get_col('SHOW COLUMNS FROM `' . $table_name . '`', 0);

        if (!is_array($columns) || empty($columns)) {
            throw new RuntimeException(
                sprintf('No se pudieron leer las columnas de %s: %s', esc_html($table_name), esc_html($wpdb->last_error))
            );
        }

        self::$column_cache[$table_name] = array_map('strval', $columns);

        return self::$column_cache[$table_name];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function assert_valid_columns(string $table_name, array $data): void
    {
        $allowed = self::columns($table_name);

        foreach (array_keys($data) as $column) {
            self::assert_identifier((string) $column);

            if (!in_array($column, $allowed, true)) {
                throw new InvalidArgumentException(
                    sprintf('La columna %s no existe en la tabla %s.', esc_html((string) $column), esc_html($table_name))
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $identity
     * @return array{0:string,1:array<int,mixed>}
     */
    public static function build_where(array $identity): array
    {
        if (empty($identity)) {
            throw new InvalidArgumentException('La identidad del registro no puede estar vacía.');
        }

        $parts = [];
        $values = [];

        foreach ($identity as $column => $value) {
            self::assert_identifier((string) $column);
            $parts[] = '`' . $column . '` = ' . self::placeholder($value);
            $values[] = $value;
        }

        return [implode(' AND ', $parts), $values];
    }

    /**
     * @param array<int,mixed> $values
     */
    public static function prepare(string $sql, array $values): string
    {
        global $wpdb;

        if (empty($values)) {
            return $sql;
        }

        $args = array_merge([$sql], $values);
        $prepared = call_user_func_array([$wpdb, 'prepare'], $args);

        if (!is_string($prepared) || $prepared === '') {
            throw new RuntimeException('No se pudo preparar una consulta del Data Layer.');
        }

        return $prepared;
    }

    /**
     * @param array<string,mixed> $identity
     * @return array<string,mixed>|null
     */
    public static function fetch_row(
        string $table_name,
        array $identity,
        bool $for_update = false
    ): ?array {
        global $wpdb;

        self::assert_identifier($table_name);
        self::assert_valid_columns($table_name, $identity);

        [$where, $values] = self::build_where($identity);

        $sql = 'SELECT * FROM `' . $table_name . '` WHERE ' . $where . ' LIMIT 1';

        if ($for_update) {
            $sql .= ' FOR UPDATE';
        }

        $prepared_sql = self::prepare($sql, $values);
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- $prepared_sql procede del helper interno que delega en $wpdb->prepare(); tabla y columnas han sido validadas.
        $row = $wpdb->get_row($prepared_sql, ARRAY_A);

        if ($row === null) {
            return null;
        }

        if (!is_array($row)) {
            throw new RuntimeException('No se pudo recuperar el registro solicitado.');
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $record
     */
    public static function hash_record(array $record): string
    {
        $normalized = self::normalize_value($record);
        $json = wp_json_encode(
            $normalized,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (!is_string($json)) {
            throw new RuntimeException('No se pudo serializar un registro para calcular su hash.');
        }

        return hash('sha256', $json);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public static function normalize_value($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if (self::is_associative($value)) {
            ksort($value);
        }

        foreach ($value as $key => $child) {
            $value[$key] = self::normalize_value($child);
        }

        return $value;
    }

    /**
     * Comprueba que las tablas de auditoría existen y usan InnoDB.
     *
     * @return array<string,mixed>
     */
    public static function health_check(): array
    {
        global $wpdb;

        $required = array_merge(
            [self::operations_table(), self::changes_table()],
            array_map(
                static function (array $config): string {
                    return (string) $config['table'];
                },
                self::tables()
            )
        );

        $result = [
            'ready'  => true,
            'tables' => [],
            'errors' => [],
        ];

        foreach (array_unique($required) as $table) {
            self::assert_identifier($table);

            $status_sql = $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $table);
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- $status_sql es el resultado directo de $wpdb->prepare() con una tabla interna validada.
            $status = $wpdb->get_row($status_sql, ARRAY_A);

            $exists = is_array($status) && !empty($status['Name']);
            $engine = $exists ? (string) $status['Engine'] : '';
            $valid_engine = strcasecmp($engine, 'InnoDB') === 0;

            $result['tables'][$table] = [
                'exists'    => $exists,
                'engine'    => $engine,
                'collation' => $exists ? (string) ($status['Collation'] ?? '') : '',
                'ready'     => $exists && $valid_engine,
            ];

            if (!$exists) {
                $result['ready'] = false;
                $result['errors'][] = 'No existe la tabla ' . $table . '.';
            } elseif (!$valid_engine) {
                $result['ready'] = false;
                $result['errors'][] = $table . ' no utiliza InnoDB.';
            }
        }

        return $result;
    }

    public static function assert_ready(): void
    {
        $health = self::health_check();

        if (empty($health['ready'])) {
            throw new RuntimeException(
                'El Data Layer no está preparado: ' . esc_html(implode(' ', (array) $health['errors']))
            );
        }
    }

    public static function assert_identifier(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Identificador SQL no válido: ' . esc_html($identifier));
        }
    }

    /**
     * @param mixed $value
     */
    private static function placeholder($value): string
    {
        if (is_int($value)) {
            return '%d';
        }

        if (is_float($value)) {
            return '%f';
        }

        return '%s';
    }

    private static function is_associative(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }
}
