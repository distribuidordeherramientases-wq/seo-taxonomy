<?php
/**
 * Strictly append-only CSV imports for existing TaxoCommerce-family tables.
 *
 * No SQL execution endpoint, no UPDATE/REPLACE/TRUNCATE/DELETE imports, and
 * An InnoDB transaction ensures a failed INSERT batch does not partially apply.
 * A completed import is not automatically reversible.
 */
defined( 'ABSPATH' ) || exit;

final class TCDB_Import {
    const MAX_BYTES = 1048576; // 1 MiB, bounded web request.
    const MAX_ROWS = 250;
    const MAX_COLUMNS = 80;
    const TTL = 15 * MINUTE_IN_SECONDS;

    public static function pending_key( $token ) {
        return 'tcdb_preview_' . (int) get_current_user_id() . '_' . $token;
    }

    public static function validate_column_set( $headers, $columns ) {
        $available = array();
        foreach ( $columns as $column ) {
            $field = (string) ( $column['Field'] ?? '' );
            if ( TCDB_Storage::identifier( $field ) ) {
                $available[ $field ] = (string) ( $column['Extra'] ?? '' );
            }
        }
        if ( ! $headers || count( $headers ) > self::MAX_COLUMNS || count( $headers ) !== count( array_unique( $headers ) ) ) {
            return new WP_Error( 'tcdb_headers', 'La cabecera CSV contiene columnas vacías, duplicadas o demasiadas columnas.' );
        }
        foreach ( $headers as $header ) {
            if ( ! TCDB_Storage::identifier( $header ) || ! isset( $available[ $header ] ) ) {
                return new WP_Error( 'tcdb_column', 'El CSV contiene una columna que no existe en esta tabla.' );
            }
            if ( false !== stripos( $available[ $header ], 'GENERATED' ) ) {
                return new WP_Error( 'tcdb_generated', 'No se puede importar una columna SQL generada.' );
            }
        }
        return true;
    }

    /** Public for deterministic CLI regression tests. */
    public static function read_csv( $path ) {
        $size = @filesize( $path );
        if ( false === $size || $size < 1 || $size > self::MAX_BYTES ) {
            return new WP_Error( 'tcdb_size', 'El CSV debe tener entre 1 byte y 1 MB.' );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Authenticated <=1 MiB uploaded CSV is parsed from the temporary PHP stream in bounded rows.
        $file = @fopen( $path, 'rb' );
        if ( ! $file ) {
            return new WP_Error( 'tcdb_open', 'No se pudo abrir el CSV.' );
        }
        $headers = fgetcsv( $file, 0, ',', '"', '\\' );
        if ( ! is_array( $headers ) || ! $headers ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Authenticated <=1 MiB uploaded CSV is parsed from the temporary PHP stream in bounded rows.
            fclose( $file );
            return new WP_Error( 'tcdb_header', 'El CSV debe tener una primera fila con nombres de columnas.' );
        }
        $headers[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $headers[0] );
        $headers = array_map( 'trim', $headers );
        if ( in_array( '', $headers, true ) || count( $headers ) > self::MAX_COLUMNS || count( array_unique( $headers ) ) !== count( $headers ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Authenticated <=1 MiB uploaded CSV is parsed from the temporary PHP stream in bounded rows.
            fclose( $file );
            return new WP_Error( 'tcdb_header_columns', 'Nombres de columna repetidos o vacíos.' );
        }
        $rows = array();
        while ( false !== ( $values = fgetcsv( $file, 0, ',', '"', '\\' ) ) ) {
            if ( array( null ) === $values ) {
                continue;
            }
            if ( count( $values ) !== count( $headers ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Authenticated <=1 MiB uploaded CSV is parsed from the temporary PHP stream in bounded rows.
                fclose( $file );
                return new WP_Error( 'tcdb_csv_width', 'Una fila no tiene el mismo número de columnas que la cabecera.' );
            }
            $row = array();
            foreach ( $headers as $i => $column ) {
                $value = (string) $values[ $i ];
                if ( strlen( $value ) > 8192 || false !== strpos( $value, "\0" ) ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Authenticated <=1 MiB uploaded CSV is parsed from the temporary PHP stream in bounded rows.
                    fclose( $file );
                    return new WP_Error( 'tcdb_cell', 'Una celda supera el límite de 8 KB o incluye datos binarios.' );
                }
                if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $value, 'UTF-8' ) ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Authenticated <=1 MiB uploaded CSV is parsed from the temporary PHP stream in bounded rows.
                    fclose( $file );
                    return new WP_Error( 'tcdb_encoding', 'El CSV debe estar codificado en UTF-8.' );
                }
                $row[ $column ] = $value;
            }
            $rows[] = $row;
            if ( count( $rows ) > self::MAX_ROWS ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Authenticated <=1 MiB uploaded CSV is parsed from the temporary PHP stream in bounded rows.
                fclose( $file );
                return new WP_Error( 'tcdb_rows', 'Importa 250 registros o menos en cada lote.' );
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Authenticated <=1 MiB uploaded CSV is parsed from the temporary PHP stream in bounded rows.
        fclose( $file );
        if ( ! $rows ) {
            return new WP_Error( 'tcdb_empty', 'El archivo no contiene registros de datos.' );
        }
        return array( 'headers' => $headers, 'rows' => $rows );
    }

    public static function preview() {
        TCDB_Storage::authorize( 'tcdb_import_preview' );
        $table = isset( $_POST['table'] ) && is_string( $_POST['table'] )
            ? sanitize_text_field( wp_unslash( $_POST['table'] ) ) : '';
        $tables = TCDB_Storage::tables();
        if ( ! isset( $tables[ $table ] ) || ! $tables[ $table ]['family'] ) {
            wp_die( 'Solo se admiten tablas existentes de la familia TaxoCommerce.' );
        }
        if ( 'InnoDB' !== $tables[ $table ]['engine'] ) {
            wp_die( 'Por seguridad, la importación requiere una tabla InnoDB.' );
        }

        if ( ! isset( $_FILES['tcdb_csv'] ) || ! is_array( $_FILES['tcdb_csv'] ) ) {
            wp_die( 'No se ha recibido ningún CSV.' );
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP upload metadata is validated below; only the client filename is normalised, while tmp_name must remain the original uploaded path for is_uploaded_file().
        $upload = $_FILES['tcdb_csv'];
        $error = isset( $upload['error'] ) ? (int) $upload['error'] : -1;
        $name = isset( $upload['name'] ) && is_string( $upload['name'] )
            ? sanitize_file_name( wp_unslash( $upload['name'] ) ) : '';
        $path = isset( $upload['tmp_name'] ) && is_string( $upload['tmp_name'] ) ? $upload['tmp_name'] : '';
        if ( UPLOAD_ERR_OK !== $error || 'csv' !== strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) )
            || ! $path || ! is_uploaded_file( $path ) || (int) ( $upload['size'] ?? 0 ) > self::MAX_BYTES ) {
            wp_die( 'Sube un archivo .csv válido de 1 MB como máximo.' );
        }
        $parsed = self::read_csv( $path );
        if ( is_wp_error( $parsed ) ) {
            wp_die( esc_html( $parsed->get_error_message() ) );
        }
        $columns = TCDB_Storage::columns( $table );
        $result = self::validate_column_set( $parsed['headers'], $columns );
        if ( is_wp_error( $result ) ) {
            wp_die( esc_html( $result->get_error_message() ) );
        }

        $token = bin2hex( random_bytes( 16 ) );
        $payload = array(
            'table' => $table, 'headers' => $parsed['headers'], 'rows' => $parsed['rows'],
            'column_hash' => hash( 'sha256', wp_json_encode( $columns ) ),
            'created' => time(),
        );
        set_transient( self::pending_key( $token ), $payload, self::TTL );
        TCDB_Storage::redirect( 'import', 'preview_' . $token );
    }

    public static function get_preview( $token ) {
        if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{32}$/D', $token ) ) {
            return false;
        }
        $data = get_transient( self::pending_key( $token ) );
        return is_array( $data ) && (int) ( $data['created'] ?? 0 ) >= time() - self::TTL ? $data : false;
    }

    public static function apply() {
        TCDB_Storage::authorize( 'tcdb_import_apply' );
        $token = isset( $_POST['tcdb_token'] ) && is_string( $_POST['tcdb_token'] )
            ? sanitize_text_field( wp_unslash( $_POST['tcdb_token'] ) ) : '';
        $backup = isset( $_POST['tcdb_backup'] ) && is_string( $_POST['tcdb_backup'] )
            && '1' === sanitize_text_field( wp_unslash( $_POST['tcdb_backup'] ) );
        $data = self::get_preview( $token );
        if ( ! $data || ! $backup ) {
            wp_die( 'Comprueba que dispones de un backup válido y vuelve a realizar la vista previa si ha caducado.' );
        }
        $table = $data['table'];
        $tables = TCDB_Storage::tables();
        if ( ! isset( $tables[ $table ] ) || ! $tables[ $table ]['family'] || 'InnoDB' !== $tables[ $table ]['engine'] ) {
            wp_die( 'La tabla ya no es válida para esta importación.' );
        }
        $columns = TCDB_Storage::columns( $table );
        if ( hash( 'sha256', wp_json_encode( $columns ) ) !== $data['column_hash']
            || is_wp_error( self::validate_column_set( $data['headers'], $columns ) ) ) {
            wp_die( 'La estructura de la tabla ha cambiado; vuelve a analizar el archivo.' );
        }

        global $wpdb;
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
            wp_die( 'No se pudo iniciar una transacción InnoDB segura.' );
        }
        $inserted = 0;
        $failed = false;
        foreach ( $data['rows'] as $row ) {
            $affected = $wpdb->insert( $table, $row );
            if ( false === $affected || 1 !== (int) $affected ) {
                $failed = true;
                break;
            }
            ++$inserted;
        }
        if ( $failed || false === $wpdb->query( 'COMMIT' ) ) {
            $wpdb->query( 'ROLLBACK' );
            TCDB_Storage::log( 'import_rejected', array( 'table' => $table, 'attempted' => count( $data['rows'] ) ) );
            wp_die( 'No se ha aplicado el CSV: hay columnas inválidas, restricciones o filas duplicadas. No se sobrescribió ninguna fila existente.' );
        }

        delete_transient( self::pending_key( $token ) );
        TCDB_Storage::log( 'import_insert', array( 'table' => $table, 'rows' => $inserted ) );
        TCDB_Storage::redirect( 'import', 'import_ok' );
    }
}
