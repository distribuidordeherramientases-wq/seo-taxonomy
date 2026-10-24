<?php
/**
 * Optional journal for an atomic single-row INSERT, UPDATE or DELETE.
 *
 * Participating plugins check class_exists('TCDB_Journal') and ready()
 * before calling mutate(). Without Database they use their normal writes.
 *
 * This journal owns dedicated InnoDB tables: it never reuses the legacy
 * seo_operations / seo_operation_changes tables. It supports only explicit
 * plugin-family tables with one primary key and no external side effects.
 *
 * @package TaxoCommerceDatabase
 */

defined( 'ABSPATH' ) || exit;

final class TCDB_Journal {
    const SCHEMA_VERSION = '0.2.0';
    const SCHEMA_OPTION = 'tcdb_journal_schema_version';
    const ROW_LIMIT = 65535;
    const MAX_BATCH = 250;

    public static function operations_table() {
        global $wpdb;
        return $wpdb->prefix . 'tcdb_operations';
    }

    public static function changes_table() {
        global $wpdb;
        return $wpdb->prefix . 'tcdb_changes';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $operations = self::operations_table();
        $changes = self::changes_table();

        dbDelta( "CREATE TABLE {$operations} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source varchar(80) NOT NULL,
            label varchar(191) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'completed',
            change_count int(11) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            rolled_back_at datetime NULL,
            rolled_back_by bigint(20) unsigned NULL,
            PRIMARY KEY  (id),
            KEY status_created (status,created_at),
            KEY source_created (source,created_at)
        ) ENGINE=InnoDB {$charset};" );

        dbDelta( "CREATE TABLE {$changes} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            operation_id bigint(20) unsigned NOT NULL,
            sequence_number int(11) unsigned NOT NULL,
            table_name varchar(191) NOT NULL,
            action_type varchar(10) NOT NULL,
            primary_column varchar(64) NOT NULL,
            primary_value varchar(191) NOT NULL,
            schema_hash char(64) NOT NULL,
            before_json longtext NULL,
            after_json longtext NULL,
            before_hash char(64) NULL,
            after_hash char(64) NULL,
            PRIMARY KEY  (id),
            KEY operation_order (operation_id,sequence_number)
        ) ENGINE=InnoDB {$charset};" );

        $ops_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $operations ) ) );
        $changes_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $changes ) ) );
        if ( $ops_exists !== $operations || $changes_exists !== $changes ) {
            return false;
        }
        update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
        return true;
    }

    public static function maybe_install() {
        if ( get_option( self::SCHEMA_OPTION ) !== self::SCHEMA_VERSION ) {
            self::install();
        }
    }

    /** Never use as proof a particular rollback will succeed; preview it. */
    public static function ready() {
        return get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION;
    }

    public static function supports( $table, $source = '' ) {
        $tables = TCDB_Storage::tables();
        if ( ! self::ready() || ! is_string( $table ) || ! isset( $tables[ $table ] )
            || ! $tables[ $table ]['family'] || 'InnoDB' !== $tables[ $table ]['engine']
            || in_array( $table, array( self::operations_table(), self::changes_table() ), true ) ) {
            return false;
        }
        $columns = TCDB_Storage::columns( $table );
        $primaries = array_values( array_filter( $columns, static function ( $column ) {
            return 'PRI' === (string) ( $column['Key'] ?? '' );
        } ) );
        if ( 1 !== count( $primaries )
            || ! TCDB_Storage::identifier( (string) $primaries[0]['Field'] ) ) {
            return false;
        }
        // The owner explicitly registers its own SQL table. Installing this
        // module does NOT make arbitrary WooCommerce/WordPress tables reversible.
        $registered = apply_filters( 'tcdb_rollback_supported_tables', array() );
        return is_string( $source ) && isset( $registered[ $source ] )
            && in_array( $table, (array) $registered[ $source ], true );
    }

    /**
     * Returns a registered plugin owner for an explicit physical table.
     * The generic CSV importer only journals INSERTs for these opt-in tables.
     */
    public static function owner_for_table( $table ) {
        $registered = apply_filters( 'tcdb_rollback_supported_tables', array() );
        foreach ( (array) $registered as $source => $tables ) {
            if ( is_string( $source ) && is_array( $tables )
                && in_array( $table, $tables, true ) && self::supports( $table, $source ) ) {
                return $source;
            }
        }
        return '';
    }

    public static function primary( $table, $source = '' ) {
        if ( ! self::supports( $table, $source ) ) {
            return '';
        }
        return TCDB_Storage::primary_key( $table );
    }

    private static function schema_hash( $table ) {
        return hash( 'sha256', wp_json_encode( TCDB_Storage::columns( $table ) ) );
    }

    private static function snapshot_json( $row ) {
        if ( null === $row ) {
            return null;
        }
        $value = wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $value ) || strlen( $value ) > self::ROW_LIMIT ) {
            throw new RuntimeException( 'La fila no se puede registrar con seguridad (tamaño máximo: 64 KB).' );
        }
        return $value;
    }

    public static function hash_row( $row ) {
        if ( null === $row ) {
            return null;
        }
        if ( ! is_array( $row ) ) {
            throw new RuntimeException( 'Snapshot SQL inválido.' );
        }
        ksort( $row, SORT_STRING );
        return hash( 'sha256', self::snapshot_json( $row ) );
    }

    private static function read_row( $table, $pk, $value, $lock = false ) {
        global $wpdb;
        if ( ! TCDB_Storage::identifier( $pk ) || ! TCDB_Storage::identifier( $table ) ) {
            throw new RuntimeException( 'Identificador de tabla inválido.' );
        }
        $tick = chr(96);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and PK are inventoried, key is parametrized.
        $sql = $wpdb->prepare(
            "SELECT * FROM {$tick}{$table}{$tick} WHERE {$tick}{$pk}{$tick} = %s LIMIT 1" . ( $lock ? ' FOR UPDATE' : '' ),
            (string) $value
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
        $row = $wpdb->get_row( $sql, ARRAY_A );
        if ( '' !== $wpdb->last_error ) {
            throw new RuntimeException( 'Error al comprobar el registro actual.' );
        }
        return is_array( $row ) ? $row : null;
    }

    private static function new_operation( $source, $label, $count ) {
        global $wpdb;
        if ( ! preg_match( '/^[a-z0-9_-]{2,60}$/D', $source ) ) {
            throw new RuntimeException( 'Identificador de módulo no permitido.' );
        }
        $ok = $wpdb->insert( self::operations_table(), array(
            'source'       => $source,
            'label'        => function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 190 ) : substr( $label, 0, 190 ),
            'status'       => 'completed',
            'change_count' => (int) $count,
            'user_id'      => get_current_user_id(),
            'created_at'   => current_time( 'mysql', true ),
        ) );
        if ( 1 !== (int) $ok || ! $wpdb->insert_id ) {
            throw new RuntimeException( 'No se pudo registrar la operación reversible.' );
        }
        return (int) $wpdb->insert_id;
    }

    private static function record_change( $operation_id, $sequence, $table, $action, $pk, $value, $before, $after ) {
        global $wpdb;
        $before_json = self::snapshot_json( $before );
        $after_json = self::snapshot_json( $after );
        $success = $wpdb->insert( self::changes_table(), array(
            'operation_id'    => $operation_id,
            'sequence_number' => (int) $sequence,
            'table_name'      => $table,
            'action_type'     => $action,
            'primary_column'  => $pk,
            'primary_value'   => (string) $value,
            'schema_hash'     => self::schema_hash( $table ),
            'before_json'     => $before_json,
            'after_json'      => $after_json,
            'before_hash'     => self::hash_row( $before ),
            'after_hash'      => self::hash_row( $after ),
        ) );
        if ( 1 !== (int) $success ) {
            throw new RuntimeException( 'No se ha podido guardar el snapshot reversible.' );
        }
    }

    /**
     * Never misreport a successful COMMIT as failed because a third-party
     * observer throws after the transaction was irreversibly committed.
     */
    private static function notify( $hook, $arg1, $arg2, $arg3 ) {
        try {
            do_action( $hook, $arg1, $arg2, $arg3 );
        } catch ( Throwable $ignored ) {
            // Observers are never allowed to alter the success of a COMMIT.
        }
    }

    private static function begin_transaction() {
        global $wpdb;
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
            throw new RuntimeException( 'No se ha podido iniciar la transacción.' );
        }
    }

    /**
     * Executes one write and its journal as the same InnoDB transaction.
     * The callback performs the module's OWN validated DB write, returning its
     * normal result. An insert callback must return the new PK value.
     *
     * @return mixed|WP_Error
     */
    public static function mutate( $source, $label, $table, $action, $identity, $callback ) {
        if ( ! self::supports( $table, $source ) || ! is_callable( $callback )
            || ! in_array( $action, array( 'insert', 'update', 'delete' ), true ) ) {
            return new WP_Error( 'tcdb_unsupported', 'Esta operación no admite un rollback seguro.' );
        }

        global $wpdb;
        $primary = self::primary( $table, $source );
        $value = $identity;
        try {
            self::begin_transaction();
            $before = null;
            if ( 'insert' !== $action ) {
                $before = self::read_row( $table, $primary, $value, true );
                if ( null === $before ) {
                    throw new RuntimeException( 'La fila anterior no existe.' );
                }
            }
            $result = call_user_func( $callback );
            if ( is_wp_error( $result ) || false === $result ) {
                throw new RuntimeException( is_wp_error( $result ) ? $result->get_error_message() : 'La escritura del módulo ha fallado.' );
            }
            if ( 'insert' === $action ) {
                $value = $result;
            }
            if ( ! is_scalar( $value ) || '' === (string) $value ) {
                throw new RuntimeException( 'No se pudo identificar el registro modificado.' );
            }

            $after = self::read_row( $table, $primary, $value, true );
            if ( ( 'delete' === $action && null !== $after )
                || ( 'insert' === $action && null === $after )
                || ( 'update' === $action && null === $after ) ) {
                throw new RuntimeException( 'El estado de la fila no coincide con la acción declarada.' );
            }
            if ( 'update' === $action && self::hash_row( $before ) === self::hash_row( $after ) ) {
                if ( false === $wpdb->query( 'COMMIT' ) ) {
                    throw new RuntimeException( 'No se pudo confirmar la transacción.' );
                }
                return $result;
            }
            $operation_id = self::new_operation( $source, $label, 1 );
            self::record_change( $operation_id, 1, $table, $action, $primary, $value, $before, $after );
            if ( false === $wpdb->query( 'COMMIT' ) ) {
                throw new RuntimeException( 'No se pudo confirmar la transacción reversible.' );
            }
            self::notify( 'tcdb_operation_recorded', $operation_id, $source, $action );
            return $result;
        } catch ( Throwable $exception ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'tcdb_write_failed', $exception->getMessage() );
        }
    }

    /**
     * Atomic bulk insertion with one journal operation. Used by CSV import.
     * Existing rows are not overwritten.
     */
    public static function insert_batch( $source, $label, $table, $rows ) {
        if ( ! self::supports( $table, $source ) || ! is_array( $rows )
            || ! $rows || count( $rows ) > self::MAX_BATCH ) {
            return new WP_Error( 'tcdb_unsupported_batch', 'Esta tabla o el lote no admite importación reversible.' );
        }
        global $wpdb;
        $pk = self::primary( $table, $source );
        $changed = array();
        try {
            self::begin_transaction();
            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) || 1 !== (int) $wpdb->insert( $table, $row ) ) {
                    throw new RuntimeException( 'No se ha podido insertar una fila del lote.' );
                }
                $key = isset( $row[ $pk ] ) && '' !== (string) $row[ $pk ]
                    ? (string) $row[ $pk ] : (string) $wpdb->insert_id;
                if ( '' === $key || '0' === $key ) {
                    throw new RuntimeException( 'La fila insertada carece de clave primaria.' );
                }
                $after = self::read_row( $table, $pk, $key, true );
                if ( null === $after ) {
                    throw new RuntimeException( 'No se puede verificar la fila insertada.' );
                }
                $changed[] = array( 'key' => $key, 'after' => $after );
            }
            $id = self::new_operation( $source, $label, count( $changed ) );
            foreach ( $changed as $index => $row ) {
                self::record_change( $id, $index + 1, $table, 'insert', $pk, $row['key'], null, $row['after'] );
            }
            if ( false === $wpdb->query( 'COMMIT' ) ) {
                throw new RuntimeException( 'No se ha podido confirmar la importación.' );
            }
            self::notify( 'tcdb_operation_recorded', $id, $source, 'insert' );
            return array( 'operation_id' => $id, 'rows' => count( $changed ) );
        } catch ( Throwable $exception ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'tcdb_batch_failed', $exception->getMessage() );
        }
    }

    public static function recent( $limit = 30 ) {
        global $wpdb;
        if ( ! self::ready() ) {
            return array();
        }
        $limit = max( 1, min( 100, (int) $limit ) );
        $table = self::operations_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin table name and integer LIMIT.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id,source,label,status,change_count,user_id,created_at,rolled_back_at FROM {$table} ORDER BY id DESC LIMIT %d",
            $limit
        ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    private static function operation( $id, $lock = false ) {
        global $wpdb;
        $table = self::operations_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table; id is prepared.
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id=%d" . ( $lock ? ' FOR UPDATE' : '' ), absint( $id )
        ), ARRAY_A );
    }

    private static function changes( $id, $lock = false ) {
        global $wpdb;
        $table = self::changes_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table; id is prepared.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE operation_id=%d ORDER BY sequence_number DESC,id DESC" .
            ( $lock ? ' FOR UPDATE' : '' ), absint( $id )
        ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    private static function decode( $json ) {
        if ( null === $json ) {
            return null;
        }
        $data = json_decode( (string) $json, true );
        if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
            throw new RuntimeException( 'El historial contiene un snapshot JSON inválido.' );
        }
        return $data;
    }

    private static function check_change( $change, $source, $lock = false ) {
        $table = (string) $change['table_name'];
        $action = (string) $change['action_type'];
        $pk = (string) $change['primary_column'];
        if ( ! self::supports( $table, $source ) || self::primary( $table, $source ) !== $pk
            || self::schema_hash( $table ) !== (string) $change['schema_hash'] ) {
            throw new RuntimeException( 'La tabla, su clave primaria o su esquema han cambiado.' );
        }
        $before = self::decode( $change['before_json'] );
        $after = self::decode( $change['after_json'] );
        if ( self::hash_row( $before ) !== $change['before_hash']
            || self::hash_row( $after ) !== $change['after_hash'] ) {
            throw new RuntimeException( 'La integridad del snapshot del historial ha fallado.' );
        }
        $current = self::read_row( $table, $pk, (string) $change['primary_value'], $lock );
        if ( 'insert' === $action && ( null !== $before || null === $after ) ) {
            throw new RuntimeException( 'Snapshot de inserción incompleto.' );
        }
        if ( 'delete' === $action && ( null === $before || null !== $after ) ) {
            throw new RuntimeException( 'Snapshot de eliminación incompleto.' );
        }
        if ( 'update' === $action && ( null === $before || null === $after ) ) {
            throw new RuntimeException( 'Snapshot de modificación incompleto.' );
        }
        if ( 'delete' === $action ) {
            if ( null !== $current ) {
                throw new RuntimeException( 'Conflicto: la fila eliminada ha vuelto a existir.' );
            }
        } elseif ( null === $current || null === $change['after_hash']
            || ! hash_equals( (string) $change['after_hash'], self::hash_row( $current ) ) ) {
            throw new RuntimeException( 'Conflicto: el registro ha cambiado o ha desaparecido desde la operación.' );
        }
        return array( 'table' => $table, 'pk' => $pk, 'key' => (string) $change['primary_value'],
            'action' => $action, 'before' => $before, 'after' => $after );
    }

    public static function preview( $id ) {
        if ( ! self::ready() || ! absint( $id ) ) {
            return array( 'allowed' => false, 'errors' => array( 'Historial de rollback no disponible.' ), 'items' => array() );
        }
        $operation = self::operation( $id );
        $items = array();
        $errors = array();
        if ( ! is_array( $operation ) || 'completed' !== (string) $operation['status'] ) {
            $errors[] = 'La operación ya está revertida o no existe.';
        } else {
            $changes = self::changes( $id );
            if ( count( $changes ) !== (int) $operation['change_count'] || ! $changes ) {
                $errors[] = 'El número de cambios del historial no coincide.';
            }
            foreach ( $changes as $change ) {
                try {
                    $detail = self::check_change( $change, (string) $operation['source'], false );
                    $items[] = array( 'action' => $detail['action'], 'table' => $detail['table'], 'status' => 'reversible' );
                } catch ( Throwable $exception ) {
                    $items[] = array( 'action' => (string) $change['action_type'], 'table' => (string) $change['table_name'], 'status' => 'conflict' );
                    $errors[] = $exception->getMessage();
                }
            }
        }
        return array( 'allowed' => ! $errors, 'errors' => $errors, 'items' => $items );
    }

    /**
     * Revalidates ALL changes with locks inside a single transaction.
     * No partial rollback is allowed.
     */
    public static function execute( $id ) {
        if ( ! self::ready() || ! absint( $id ) ) {
            return new WP_Error( 'tcdb_not_ready', 'No existe historial reversible para esta operación.' );
        }
        global $wpdb;
        try {
            self::begin_transaction();
            $operation = self::operation( $id, true );
            if ( ! is_array( $operation ) || 'completed' !== (string) $operation['status'] ) {
                throw new RuntimeException( 'La operación no existe o ya fue revertida.' );
            }
            $changes = self::changes( $id, true );
            if ( ! $changes || count( $changes ) !== (int) $operation['change_count'] ) {
                throw new RuntimeException( 'El historial de cambios está incompleto.' );
            }
            $actions = array();
            foreach ( $changes as $change ) {
                $actions[] = self::check_change( $change, (string) $operation['source'], true );
            }
            foreach ( $actions as $action ) {
                $table = $action['table'];
                $pk = $action['pk'];
                $key = $action['key'];
                if ( 'insert' === $action['action'] ) {
                    $result = $wpdb->delete( $table, array( $pk => $key ) );
                    if ( 1 !== (int) $result ) {
                        throw new RuntimeException( 'No se pudo deshacer la inserción.' );
                    }
                } elseif ( 'update' === $action['action'] ) {
                    $before = $action['before'];
                    unset( $before[ $pk ] );
                    if ( false === $wpdb->update( $table, $before, array( $pk => $key ) ) ) {
                        throw new RuntimeException( 'No se pudo restaurar el registro modificado.' );
                    }
                } elseif ( 'delete' === $action['action'] ) {
                    if ( 1 !== (int) $wpdb->insert( $table, $action['before'] ) ) {
                        throw new RuntimeException( 'No se pudo restaurar el registro eliminado.' );
                    }
                } else {
                    throw new RuntimeException( 'Tipo de operación desconocido.' );
                }
            }
            $ok = $wpdb->update( self::operations_table(), array(
                'status' => 'rolled_back',
                'rolled_back_at' => current_time( 'mysql', true ),
                'rolled_back_by' => get_current_user_id(),
            ), array( 'id' => absint( $id ) ) );
            if ( false === $ok || false === $wpdb->query( 'COMMIT' ) ) {
                throw new RuntimeException( 'No se pudo confirmar el rollback.' );
            }
            self::notify( 'tcdb_rollback_completed', (int) $id, (string) $operation['source'], count( $actions ) );
            TCDB_Storage::log( 'rollback', array( 'operation_id' => absint( $id ), 'source' => (string) $operation['source'], 'rows' => count( $actions ) ) );
            return array( 'operation_id' => absint( $id ), 'restored' => count( $actions ) );
        } catch ( Throwable $exception ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'tcdb_rollback_conflict', $exception->getMessage() );
        }
    }

    public static function handle_rollback() {
        TCDB_Storage::authorize( 'tcdb_rollback' );
        $id = isset( $_POST['operation_id'] ) ? absint( wp_unslash( $_POST['operation_id'] ) ) : 0;
        $confirmation = isset( $_POST['confirmation'] ) && is_string( $_POST['confirmation'] )
            ? sanitize_text_field( wp_unslash( $_POST['confirmation'] ) ) : '';
        if ( ! $id || 'REVERTIR' !== $confirmation ) {
            wp_die( 'Debes confirmar expresamente la operación con REVERTIR.' );
        }
        $result = self::execute( $id );
        $key = 'tcdb_rollback_result_' . get_current_user_id();
        set_transient( $key, is_wp_error( $result )
            ? array( 'error' => true, 'message' => $result->get_error_message() )
            : array( 'error' => false, 'message' => 'Operación #' . $id . ' revertida: ' . $result['restored'] . ' registros.' ),
            2 * MINUTE_IN_SECONDS
        );
        TCDB_Storage::redirect( 'operations' );
    }
}
