<?php
/**
 * Manual, streamed SQL/CSV logical exports. No files or dumps are left inside
 * the publicly accessible uploads directory. No SQL restore endpoints.
 */
defined( 'ABSPATH' ) || exit;

final class TCDB_Export {
    const BATCH = 100;

    public static function handle() {
        TCDB_Storage::authorize( 'tcdb_export' );
        $mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
        $format = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : '';
        $table = isset( $_POST['table'] ) ? sanitize_text_field( wp_unslash( $_POST['table'] ) ) : '';
        $tables = TCDB_Storage::tables();

        if ( 'table' === $mode ) {
            if ( ! isset( $tables[ $table ] ) || ! $tables[ $table ]['family'] || ! in_array( $format, array( 'csv', 'sql' ), true ) ) {
                wp_die( 'Solo pueden exportarse tablas propias de la familia TaxoCommerce en CSV o SQL.' );
            }
            $selected = array( $table );
        } elseif ( in_array( $mode, array( 'family', 'all' ), true ) && 'sql' === $format ) {
            $selected = array();
            foreach ( $tables as $meta ) {
                if ( 'all' === $mode || $meta['family'] ) {
                    $selected[] = $meta['name'];
                }
            }
        } else {
            wp_die( 'Tipo de exportación no permitido.' );
        }

        if ( ! $selected ) {
            wp_die( 'No se han encontrado tablas para exportar.' );
        }
        $name = 'taxocommerce_' . $mode . '_' . gmdate( 'Ymd_His' );
        if ( 'table' === $mode ) {
            $name = sanitize_file_name( $table . '_' . gmdate( 'Ymd_His' ) );
        }
        $filename = $name . '.' . $format;
        TCDB_Storage::log( 'export', array( 'mode' => $mode, 'format' => $format, 'tables' => count( $selected ) ) );

        if ( headers_sent() ) {
            wp_die( 'No se pueden enviar los datos: ya se han emitido cabeceras HTTP.' );
        }
        nocache_headers();
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Type: ' . ( 'csv' === $format ? 'text/csv' : 'application/sql' ) . '; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'X-Robots-Tag: noindex, nofollow' );

        if ( 'csv' === $format ) {
            self::stream_csv( $selected[0] );
        } else {
            self::stream_sql( $selected, $mode );
        }
        exit;
    }

    /**
     * Cursor pagination uses a single-column primary key when possible.
     * For composite/no-PK tables it falls back to bounded OFFSET pages.
     */
    private static function rows( $table, $emit ) {
        global $wpdb;
        if ( ! TCDB_Storage::table_allowed( $table, false ) ) {
            return false;
        }
        $columns = TCDB_Storage::columns( $table );
        if ( ! $columns ) {
            return false;
        }
        $primary = '';
        $primary_count = 0;
        foreach ( $columns as $column ) {
            if ( 'PRI' === (string) ( $column['Key'] ?? '' ) ) {
                $primary = (string) $column['Field'];
                ++$primary_count;
            }
        }
        $keyset = 1 === $primary_count && TCDB_Storage::identifier( $primary );
        $cursor = null;
        $offset = 0;
        do {
            if ( $keyset && null === $cursor ) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        'SELECT * FROM %i ORDER BY %i ASC LIMIT %d',
                        $table,
                        $primary,
                        self::BATCH
                    ),
                    ARRAY_A
                );
            } elseif ( $keyset ) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        'SELECT * FROM %i WHERE %i > %s ORDER BY %i ASC LIMIT %d',
                        $table,
                        $primary,
                        (string) $cursor,
                        $primary,
                        self::BATCH
                    ),
                    ARRAY_A
                );
            } else {
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        'SELECT * FROM %i LIMIT %d OFFSET %d',
                        $table,
                        self::BATCH,
                        $offset
                    ),
                    ARRAY_A
                );
            }
            if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
                return false;
            }
            foreach ( $rows as $row ) {
                call_user_func( $emit, $row, $columns );
                if ( $keyset ) {
                    $cursor = $row[ $primary ];
                }
            }
            $offset += count( $rows );
            if ( count( $rows ) === self::BATCH && 0 === $offset % 1000 ) {
                flush();
            }
        } while ( count( $rows ) === self::BATCH && ! connection_aborted() );
        return ! connection_aborted();
    }

    public static function sql_literal( $value ) {
        if ( null === $value || ! is_scalar( $value ) ) {
            return 'NULL';
        }
        $value = (string) $value;
        if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $value, 'UTF-8' ) ) {
            return '0x' . bin2hex( $value );
        }
        global $wpdb;
        return $wpdb->prepare( '%s', $value );
    }

    private static function stream_sql( $tables, $mode ) {
        global $wpdb;
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authenticated application/sql backup must retain raw SQL; HTML escaping corrupts the dump.
        echo "-- TaxoCommerce Database v" . TAXOCOMMERCE_DATABASE_VERSION . "\n";
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authenticated application/sql backup must retain raw SQL; HTML escaping corrupts the dump.
        echo "-- Exportación lógica: " . gmdate( 'c' ) . "\n";
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authenticated application/sql backup must retain raw SQL; HTML escaping corrupts the dump.
        echo "-- Ámbito: " . ( 'all' === $mode ? 'tablas WordPress del sitio' : 'tablas propias TaxoCommerce' ) . "\n";
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authenticated application/sql backup must retain raw SQL; HTML escaping corrupts the dump.
        echo "-- IMPORTANTE: las exportaciones realizadas mientras la tienda recibe escrituras no son snapshots transaccionales.\n";
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authenticated application/sql backup must retain raw SQL; HTML escaping corrupts the dump.
        echo "-- Para restauración total usar una base vacía y la herramienta del hosting; este plugin NO ejecuta SQL importado.\n";
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authenticated application/sql backup must retain raw SQL; HTML escaping corrupts the dump.
        echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

        $completed = 0;
        foreach ( $tables as $table ) {
            if ( ! TCDB_Storage::table_allowed( $table, false ) ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authenticated application/sql backup must retain raw SQL; HTML escaping corrupts the dump.
                echo "-- ERROR: tabla no autorizada; exportación INCOMPLETA.\n";
                return;
            }
            $ddl = $wpdb->get_row(
                $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ),
                ARRAY_N
            );
            if ( ! is_array( $ddl ) || ! isset( $ddl[1] ) ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authenticated application/sql backup must retain raw SQL; HTML escaping corrupts the dump.
                echo "-- ERROR: no se ha podido leer el esquema de " . $table . "; exportación INCOMPLETA.\n";
                return;
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authenticated application/sql backup must retain raw SQL; HTML escaping corrupts the dump.
            echo "-- Tabla: " . $table . "\n" . $ddl[1] . ";\n";
            $ok = self::rows( $table, static function ( $row ) use ( $table ) {
                $column_sql = array();
                $value_sql = array();
                foreach ( $row as $column => $value ) {
                    if ( ! TCDB_Storage::identifier( (string) $column ) ) {
                        continue;
                    }
                    $column_sql[] = chr(96) . $column . chr(96);
                    $value_sql[] = TCDB_Export::sql_literal( $value );
                }
                if ( $column_sql ) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authenticated application/sql backup must retain raw SQL; HTML escaping corrupts the dump.
                    echo 'INSERT INTO ' . chr(96) . $table . chr(96) . ' (' . implode( ',', $column_sql ) . ') VALUES (' . implode( ',', $value_sql ) . ");\n";
                }
            } );
            if ( ! $ok ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authenticated application/sql backup must retain raw SQL; HTML escaping corrupts the dump.
                echo "-- ERROR: fallo al exportar " . $table . "; exportación INCOMPLETA.\n";
                return;
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authenticated application/sql backup must retain raw SQL; HTML escaping corrupts the dump.
            echo "-- Fin de tabla: " . $table . "\n\n";
            ++$completed;
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authenticated application/sql backup must retain raw SQL; HTML escaping corrupts the dump.
        echo "SET FOREIGN_KEY_CHECKS=1;\n-- EXPORTACIÓN COMPLETA: {$completed} tablas.\n";
    }

    private static function stream_csv( $table ) {
        $columns = TCDB_Storage::columns( $table );
        if ( ! $columns ) {
            echo 'ERROR: tabla sin columnas';
            return;
        }
        $stream = fopen( 'php://output', 'w' );
        if ( ! $stream ) {
            return;
        }
        fputcsv( $stream, array_column( $columns, 'Field' ) );
        // CSV is an exact raw-data export, not a spreadsheet-formula-safe report.
        $ok = self::rows( $table, static function ( $row ) use ( $stream, $columns ) {
            $values = array();
            foreach ( $columns as $column ) {
                $values[] = $row[ $column['Field'] ] ?? '';
            }
            fputcsv( $stream, $values );
        } );
        if ( ! $ok ) {
            fputcsv( $stream, array( '__TCDB_EXPORT_INCOMPLETO__' ) );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CSV is streamed to php://output, not saved to WordPress filesystem.
        fclose( $stream );
    }
}
