<?php
/**
 * SEO System — Motor comun de importacion de proveedores.
 *
 * Responsabilidad:
 * Descubrir recetas instaladas, conservar los archivos originales, transformar
 * feeds externos al CSV normalizado de SEO System y administrar el catalogo
 * intermedio de proveedores.
 *
 * Las recetas no escriben directamente en WooCommerce. Solo normalizan datos;
 * el alta o actualizacion final debe reutilizar el motor comun de productos.
 *
 * Recetas incluidas:
 * - suppliers/recipes/import_vevor.php
 * - suppliers/recipes/import_satkit.php
 *
 * @package SEOSystem
 * @subpackage ImportExport
 * @author David Perez Martorell
 * @license GPL-2.0-or-later
 * @since 2.0.0
 * @version 2026-08-18
 * Build: 033
 */

defined( 'ABSPATH' ) || exit;

// Lector ligero autocontenido para Excel XLS antiguo (BIFF8/OLE).
// No depende de PhpSpreadsheet, exec ni LibreOffice.
$seo_supplier_xls_reader_file = __DIR__ . '/xls-reader.php';
if ( is_readable( $seo_supplier_xls_reader_file ) ) {
    require_once $seo_supplier_xls_reader_file;
}
unset( $seo_supplier_xls_reader_file );

// Helpers comunes de conexiones/enriquecimiento web. Se cargan antes de las recetas
// para que cualquier proveedor pueda reutilizar HTTP, JSON-LD e imagenes remotas.
$seo_supplier_connections_file = __DIR__ . '/connections.php';
if ( is_readable( $seo_supplier_connections_file ) ) {
    require_once $seo_supplier_connections_file;
}
unset( $seo_supplier_connections_file );

// Cola generica para descubrir catalogos web de proveedores a baja frecuencia.
// Se carga antes de las recetas para que import_*.php pueda registrar recetas
// remotas mediante el filtro seo_supplier_crawl_recipes.
$seo_supplier_crawler_file = __DIR__ . '/crawler-queue.php';
if ( is_readable( $seo_supplier_crawler_file ) ) {
    require_once $seo_supplier_crawler_file;
}
unset( $seo_supplier_crawler_file );

// Carga las recetas instaladas. Se aceptan ambas capitalizaciones por
// compatibilidad con servidores Linux y estructuras ya desplegadas.
$seo_supplier_recipe_files = [];
foreach ( [ __DIR__ . '/recipes/import_*.php' ] as $seo_supplier_recipe_pattern ) {
    $seo_supplier_recipe_files = array_merge(
        $seo_supplier_recipe_files,
        (array) glob( $seo_supplier_recipe_pattern )
    );
}

foreach ( array_unique( $seo_supplier_recipe_files ) as $seo_supplier_recipe_file ) {
    if ( is_readable( $seo_supplier_recipe_file ) ) {
        require_once $seo_supplier_recipe_file;
    }
}
unset( $seo_supplier_recipe_files, $seo_supplier_recipe_pattern, $seo_supplier_recipe_file );

// Operaciones del importador y del catálogo de proveedores.
add_action( 'admin_init', 'seo_proveedores_analizar_archivo' );
add_action( 'admin_init', 'seo_proveedores_importar_catalogo' );
add_action( 'admin_init', 'seo_proveedores_actualizar_estado_catalogo' );
add_action( 'admin_init', 'seo_proveedores_actualizar_estado_masivo' );
add_action( 'admin_init', 'seo_proveedores_exportar_productos_csv', 22 );
add_action( 'admin_init', 'seo_proveedores_descartar_importacion_al_salir', 99 );

/**
 * Devuelve las recetas de importacion instaladas mediante import_*.php.
 *
 * Cada receta prepara un CSV estandar. El motor de base de datos nunca
 * necesita conocer las columnas ni reglas particulares del proveedor.
 *
 * @return array
 */
function seo_proveedores_recetas_importacion() {
    static $recipes = null;

    if ( null !== $recipes ) {
        return $recipes;
    }

    $registered = apply_filters( 'seo_proveedores_import_recipes', [] );
    $recipes    = [];

    foreach ( (array) $registered as $recipe_id => $recipe ) {
        if ( ! is_array( $recipe ) ) {
            continue;
        }

        $recipe_id = sanitize_key( $recipe['id'] ?? $recipe_id );
        $provider  = sanitize_text_field( trim( (string) ( $recipe['provider'] ?? '' ) ) );
        $label     = sanitize_text_field( trim( (string) ( $recipe['label'] ?? $provider ) ) );
        $mode      = sanitize_key( $recipe['mode'] ?? 'mapping' );

        if ( '' === $recipe_id || '' === $provider || '' === $label ) {
            continue;
        }

        if ( ! in_array( $mode, [ 'mapping', 'transform' ], true ) ) {
            $mode = 'mapping';
        }

        $recipe['id']       = $recipe_id;
        $recipe['provider'] = $provider;
        $recipe['label']    = $label;
        $recipe['mode']     = $mode;
        $recipes[ $recipe_id ] = $recipe;
    }

    uasort(
        $recipes,
        static function ( $left, $right ) {
            return strcasecmp( (string) $left['label'], (string) $right['label'] );
        }
    );

    return $recipes;
}

/**
 * Obtiene una receta concreta.
 *
 * @param string $recipe_id ID.
 * @return array|null
 */
function seo_proveedores_obtener_receta( $recipe_id ) {
    $recipes   = seo_proveedores_recetas_importacion();
    $recipe_id = sanitize_key( $recipe_id );

    return isset( $recipes[ $recipe_id ] ) ? $recipes[ $recipe_id ] : null;
}

/**
 * Directorio permanente de originales y CSV preparados.
 *
 * @param string $kind original o prepared.
 * @param string $recipe_id Receta.
 * @return array|WP_Error
 */
function seo_proveedores_storage_receta( $kind, $recipe_id ) {
    $uploads = wp_upload_dir();

    if ( ! empty( $uploads['error'] ) ) {
        return new WP_Error( 'supplier_upload_dir_error', (string) $uploads['error'] );
    }

    $kind      = 'prepared' === $kind ? 'preparados' : 'originales';
    $recipe_id = sanitize_key( $recipe_id );
    $relative  = 'seo-proveedores-importaciones/' . $kind . '/' . $recipe_id;
    $dir       = trailingslashit( $uploads['basedir'] ) . $relative;
    $url       = trailingslashit( $uploads['baseurl'] ) . $relative;

    wp_mkdir_p( $dir );

    if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
        return new WP_Error( 'supplier_storage_unwritable', 'No se puede escribir en la carpeta de importaciones de proveedores.' );
    }

    return [
        'dir' => wp_normalize_path( $dir ),
        'url' => untrailingslashit( $url ),
    ];
}

/**
 * Plantilla comun producida por todas las recetas.
 *
 * @return string[]
 */
function seo_proveedores_cabecera_estandar() {
    return array_keys( seo_proveedores_campos_importacion() );
}

/**
 * Convierte una fila indexada en asociativa usando la cabecera original.
 *
 * @param array $header Cabecera.
 * @param array $row Fila.
 * @return array
 */
function seo_proveedores_fila_asociativa( $header, $row ) {
    $result = [];

    foreach ( (array) $header as $index => $column ) {
        $result[ (string) $column ] = seo_ie_csv_to_utf8( $row[ $index ] ?? '' );
    }

    return $result;
}

/**
 * Prepara y conserva el CSV estandar de una receta.
 *
 * @param array $state Estado del archivo original.
 * @param array $recipe Receta.
 * @param array $mapping Mapeo para recetas visuales.
 * @return array|WP_Error
 */
function seo_proveedores_preparar_csv_estandar( $state, $recipe, $mapping = [] ) {
    $fields   = seo_proveedores_campos_importacion();
    $standard = seo_proveedores_cabecera_estandar();
    $mode     = sanitize_key( $recipe['mode'] ?? 'mapping' );

    if ( 'transform' === $mode ) {
        $callback = $recipe['transform_callback'] ?? '';

        if ( ! is_callable( $callback ) ) {
            return new WP_Error( 'supplier_recipe_callback', 'La receta no tiene un transformador valido.' );
        }

        $missing_headers = array_values(
            array_diff( (array) ( $recipe['required_headers'] ?? [] ), (array) $state['header'] )
        );

        if ( ! empty( $missing_headers ) ) {
            return new WP_Error(
                'supplier_recipe_headers',
                'Faltan columnas requeridas por la receta: ' . implode( ', ', $missing_headers )
            );
        }
    } else {
        $mapping = array_map( 'sanitize_key', (array) $mapping );
        $targets = array_values( array_filter( $mapping ) );

        foreach ( $fields as $field_key => $definition ) {
            if ( ! empty( $definition['required'] ) && ! in_array( $field_key, $targets, true ) ) {
                return new WP_Error(
                    'supplier_required_mapping',
                    sprintf( 'Debes enlazar el campo obligatorio "%s".', $definition['label'] )
                );
            }
        }

        if ( count( $targets ) !== count( array_unique( $targets ) ) ) {
            return new WP_Error( 'supplier_duplicate_mapping', 'Cada campo interno solo puede enlazarse una vez.' );
        }
    }

    $storage = seo_proveedores_storage_receta( 'prepared', $recipe['id'] );

    if ( is_wp_error( $storage ) ) {
        return $storage;
    }

    $filename = wp_unique_filename(
        $storage['dir'],
        'import_' . sanitize_key( $recipe['id'] ) . '_' . wp_date( 'Ymd_His' ) . '.csv'
    );
    $path = trailingslashit( $storage['dir'] ) . $filename;
    $out  = fopen( $path, 'w' );

    if ( false === $out ) {
        return new WP_Error( 'supplier_prepared_open', 'No se pudo crear el CSV preparado.' );
    }

    fwrite( $out, "\xEF\xBB\xBF" );
    fputcsv( $out, $standard, ';', '"', '' );

    $log = [
        'procesados' => 0,
        'preparados' => 0,
        'omitidos'   => 0,
        'errores'    => 0,
        'detalles'   => [],
    ];

    $iterator = seo_proveedores_iterar_filas( $state );
    $line     = 1;

    foreach ( $iterator as $source_row ) {
        $line++;

        if ( empty( array_filter( (array) $source_row, static fn( $value ) => '' !== trim( (string) $value ) ) ) ) {
            continue;
        }

        $log['procesados']++;
        $normalized = [];

        if ( 'transform' === $mode ) {
            $source_assoc = ! empty( $recipe['indexed_rows'] )
                ? array_values( (array) $source_row )
                : seo_proveedores_fila_asociativa( $state['header'], $source_row );

            try {
                $normalized = call_user_func( $callback, $source_assoc, $state, $recipe );
            } catch ( Throwable $exception ) {
                $log['errores']++;
                seo_ie_add_log_detail( $log, sprintf( 'Fila %d: %s', $line, $exception->getMessage() ) );
                continue;
            }

            if ( ! is_array( $normalized ) ) {
                $log['omitidos']++;
                seo_ie_add_log_detail( $log, sprintf( 'Fila %d omitida por la receta.', $line ) );
                continue;
            }
        } else {
            foreach ( $mapping as $column_index => $target_field ) {
                if ( '' === $target_field || ! isset( $fields[ $target_field ] ) ) {
                    continue;
                }

                $normalized[ $target_field ] = $source_row[ absint( $column_index ) ] ?? '';
            }
        }

        $clean = [];
        foreach ( $standard as $field_key ) {
            $value = $normalized[ $field_key ] ?? '';
            $clean[ $field_key ] = seo_proveedores_limpiar_valor( $value, $fields[ $field_key ]['type'] );
        }

        if ( '' === trim( (string) $clean['proveedor_id_externo'] ) || '' === trim( (string) $clean['nombre'] ) ) {
            $log['errores']++;
            seo_ie_add_log_detail( $log, sprintf( 'Fila %d: faltan ID externo o nombre tras aplicar la receta.', $line ) );
            continue;
        }

        fputcsv(
            $out,
            array_map(
                static function ( $field_key ) use ( $clean ) {
                    $value = $clean[ $field_key ] ?? '';
                    return null === $value ? '' : $value;
                },
                $standard
            ),
            ';',
            '"',
            ''
        );
        $log['preparados']++;
    }

    fclose( $out );

    if ( 0 === $log['preparados'] ) {
        @unlink( $path );
        return new WP_Error( 'supplier_no_prepared_rows', 'La receta no produjo ninguna fila valida.' );
    }

    $analysis = seo_proveedores_analizar_csv( $path );

    if ( is_wp_error( $analysis ) ) {
        return $analysis;
    }

    return [
        'state' => array_merge(
            $analysis,
            [
                'recipe_id'         => $recipe['id'],
                'recipe_label'      => $recipe['label'],
                'recipe_version'    => (string) ( $recipe['version'] ?? '' ),
                'proveedor'         => $recipe['provider'],
                'filename'          => $filename,
                'path'              => wp_normalize_path( $path ),
                'url'               => trailingslashit( $storage['url'] ) . rawurlencode( $filename ),
                'original_filename' => $state['filename'] ?? '',
                'original_path'     => $state['path'] ?? '',
                'created'           => time(),
            ]
        ),
        'log' => $log,
    ];
}

/**
 * Importa un CSV ya normalizado por una receta.
 *
 * @param array $state Estado del CSV preparado.
 * @param array $preparation_log Resultado de preparacion.
 * @return array|WP_Error
 */
function seo_proveedores_importar_csv_estandar( $state, $preparation_log = [] ) {
    global $wpdb;

    $fields = seo_proveedores_campos_importacion();
    $table  = seo_proveedores_tabla_productos();
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

    if ( $exists !== $table ) {
        return new WP_Error( 'supplier_table_missing', 'No existe la tabla de productos de proveedores.' );
    }

    if ( function_exists( 'seo_supplier_sync_ensure_schema' ) ) {
        seo_supplier_sync_ensure_schema();
    }

    $header_map = [];
    foreach ( (array) $state['header'] as $index => $column ) {
        $column = sanitize_key( $column );
        if ( isset( $fields[ $column ] ) ) {
            $header_map[ $index ] = $column;
        }
    }

    foreach ( $fields as $field_key => $definition ) {
        if ( ! empty( $definition['required'] ) && ! in_array( $field_key, $header_map, true ) ) {
            return new WP_Error( 'supplier_standard_header', 'El CSV preparado no contiene todos los campos obligatorios.' );
        }
    }

    $log = [
        'operacion'          => 'Preparacion e importacion de proveedor',
        'receta'             => $state['recipe_label'] ?? $state['recipe_id'] ?? '',
        'version_receta'     => $state['recipe_version'] ?? '',
        'archivo_original'   => $state['original_filename'] ?? '',
        'archivo'            => $state['filename'],
        'archivo_preparado'  => $state['filename'],
        'archivo_preparado_url' => $state['url'] ?? '',
        'proveedor'          => $state['proveedor'],
        'filas_preparadas'   => absint( $preparation_log['preparados'] ?? 0 ),
        'filas_omitidas'     => absint( $preparation_log['omitidos'] ?? 0 ),
        'procesados'         => 0,
        'correctos'          => 0,
        'creados'            => 0,
        'actualizados'       => 0,
        'pendientes_actualizacion' => 0,
        'sin_cambios'        => 0,
        'conflictos'         => 0,
        'errores'            => absint( $preparation_log['errores'] ?? 0 ),
        'detalles'           => (array) ( $preparation_log['detalles'] ?? [] ),
    ];

    seo_ie_add_log_detail( $log, 'CSV preparado conservado: ' . $state['filename'] );

    $iterator = seo_proveedores_iterar_filas( $state );
    $line     = 1;
    $now      = current_time( 'mysql' );
    $run_id   = function_exists( 'seo_supplier_sync_start_run' )
        ? seo_supplier_sync_start_run( $state, $now )
        : 0;

    foreach ( $iterator as $csv_row ) {
        $line++;

        if ( empty( array_filter( (array) $csv_row, static fn( $value ) => '' !== trim( (string) $value ) ) ) ) {
            continue;
        }

        $log['procesados']++;
        $data = [];

        foreach ( $header_map as $column_index => $field_key ) {
            $data[ $field_key ] = seo_proveedores_limpiar_valor(
                $csv_row[ $column_index ] ?? '',
                $fields[ $field_key ]['type']
            );
        }

        $external_id = trim( (string) ( $data['proveedor_id_externo'] ?? '' ) );
        $name        = trim( (string) ( $data['nombre'] ?? '' ) );
        $incoming_sku = trim( (string) ( $data['sku'] ?? '' ) );

        if ( '' === $external_id || '' === $name ) {
            $log['errores']++;
            seo_ie_add_log_detail( $log, sprintf( 'Fila preparada %d: faltan ID externo o nombre.', $line ) );
            continue;
        }

        $current_snapshot = function_exists( 'seo_supplier_sync_snapshot' )
            ? seo_supplier_sync_snapshot( $data )
            : $data;
        $current_hash = function_exists( 'seo_supplier_sync_snapshot_hash' )
            ? seo_supplier_sync_snapshot_hash( $current_snapshot )
            : hash( 'sha256', wp_json_encode( $current_snapshot ) );

        $existing_by_sku = null;
        $existing_by_external = null;

        if ( '' !== $incoming_sku ) {
            $existing_by_sku = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE proveedor = %s AND sku = %s ORDER BY id ASC LIMIT 1",
                    $state['proveedor'],
                    $incoming_sku
                ),
                ARRAY_A
            );
        }

        $existing_by_external = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE proveedor = %s AND proveedor_id_externo = %s ORDER BY id ASC LIMIT 1",
                $state['proveedor'],
                $external_id
            ),
            ARRAY_A
        );

        /*
         * Un SKU y un codigo externo que resuelven a filas diferentes indican
         * una identidad ambigua. Nunca se pisa silenciosamente una de ellas.
         */
        if (
            $existing_by_sku
            && $existing_by_external
            && absint( $existing_by_sku['id'] ?? 0 ) !== absint( $existing_by_external['id'] ?? 0 )
        ) {
            $message = sprintf(
                'Conflicto de identidad: SKU %s y codigo %s apuntan a registros distintos.',
                $incoming_sku,
                $external_id
            );
            if ( function_exists( 'seo_supplier_sync_mark_conflict' ) ) {
                seo_supplier_sync_mark_conflict( $existing_by_sku, $data, $run_id, $message );
                seo_supplier_sync_mark_conflict( $existing_by_external, $data, $run_id, $message );
            }
            $log['conflictos']++;
            $log['errores']++;
            seo_ie_add_log_detail( $log, sprintf( 'Fila preparada %d: %s', $line, $message ) );
            continue;
        }

        /*
         * El mismo codigo de proveedor no puede cambiar de SKU de forma
         * automatica: podria representar otra variante/producto.
         */
        if (
            $existing_by_external
            && '' !== $incoming_sku
            && '' !== trim( (string) ( $existing_by_external['sku'] ?? '' ) )
            && $incoming_sku !== trim( (string) $existing_by_external['sku'] )
        ) {
            $message = sprintf(
                'Conflicto de identidad: el codigo %s tenia SKU %s y ahora llega como %s.',
                $external_id,
                (string) $existing_by_external['sku'],
                $incoming_sku
            );
            if ( function_exists( 'seo_supplier_sync_mark_conflict' ) ) {
                seo_supplier_sync_mark_conflict( $existing_by_external, $data, $run_id, $message );
            }
            $log['conflictos']++;
            $log['errores']++;
            seo_ie_add_log_detail( $log, sprintf( 'Fila preparada %d: %s', $line, $message ) );
            continue;
        }

        $existing = $existing_by_sku ?: $existing_by_external;

        /*
         * Enlace de respaldo con WooCommerce por SKU para catalogos historicos.
         * Si el SKU ya pertenece explicitamente a otro proveedor, se bloquea.
         */
        $woo_product_id = 0;
        if ( '' !== $incoming_sku && function_exists( 'wc_get_product_id_by_sku' ) ) {
            $woo_product_id = absint( wc_get_product_id_by_sku( $incoming_sku ) );
            if ( $woo_product_id && ( 'product' !== get_post_type( $woo_product_id ) || 'trash' === get_post_status( $woo_product_id ) ) ) {
                $woo_product_id = 0;
            }

            if ( $woo_product_id ) {
                $linked_provider = sanitize_text_field( (string) get_post_meta( $woo_product_id, '_seo_proveedor', true ) );
                if ( '' !== $linked_provider && 0 !== strcasecmp( $linked_provider, (string) $state['proveedor'] ) ) {
                    $message = sprintf( 'Conflicto: el SKU %s ya esta vinculado en WordPress al proveedor %s.', $incoming_sku, $linked_provider );

                    if ( $existing && function_exists( 'seo_supplier_sync_mark_conflict' ) ) {
                        seo_supplier_sync_mark_conflict( $existing, $data, $run_id, $message );
                    } elseif ( ! $existing ) {
                        $conflict_row = $data;
                        $conflict_row['proveedor'] = $state['proveedor'];
                        $conflict_row['hash_producto'] = $current_hash;
                        $conflict_row['estado_seleccion'] = 'revisar';
                        $conflict_row['estado_sincronizacion'] = 'conflicto';
                        $conflict_row['cambios_detectados'] = '|sku|proveedor_id_externo|';
                        $conflict_row['last_seen_run_id'] = $run_id;
                        $conflict_row['raw_json'] = wp_json_encode( [ 'incoming' => $data, 'reason' => $message ] );
                        $conflict_row['primera_importacion'] = $now;
                        $conflict_row['ultima_importacion'] = $now;
                        $conflict_row['creado'] = $now;
                        $conflict_row['actualizado'] = $now;
                        $wpdb->insert( $table, $conflict_row );
                    }

                    $log['conflictos']++;
                    $log['errores']++;
                    seo_ie_add_log_detail( $log, sprintf( 'Fila preparada %d: %s', $line, $message ) );
                    continue;
                }
            }
        }

        if ( $existing ) {
            $selection = sanitize_key( (string) ( $existing['estado_seleccion'] ?? '' ) );
            if ( in_array( $selection, [ 'actualizar', 'publicado' ], true ) ) {
                $selection = 'aceptado';
            } elseif ( 'duplicado' === $selection ) {
                $selection = 'revisar';
            }

            $object_id = absint( $existing['object_id'] ?? 0 );
            if ( ! $object_id && $woo_product_id ) {
                $object_id = $woo_product_id;
                if ( in_array( $selection, [ 'pendiente', 'revisar', '' ], true ) ) {
                    $selection = 'aceptado';
                }
            }

            /*
             * Para filas historicas sin linea base se toma como referencia la
             * fotografia que habia justo antes de esta primera importacion con
             * el sistema integrado. Asi no aparecen cientos de falsos cambios.
             */
            $applied_snapshot = function_exists( 'seo_supplier_sync_decode_snapshot' )
                ? seo_supplier_sync_decode_snapshot( $existing['snapshot_aplicado'] ?? '' )
                : [];
            $applied_hash = trim( (string) ( $existing['hash_aplicado'] ?? '' ) );

            if ( empty( $applied_snapshot ) && ( $object_id || 'aceptado' === $selection ) ) {
                $applied_snapshot = function_exists( 'seo_supplier_sync_snapshot' )
                    ? seo_supplier_sync_snapshot( $existing )
                    : $existing;
                $applied_hash = function_exists( 'seo_supplier_sync_snapshot_hash' )
                    ? seo_supplier_sync_snapshot_hash( $applied_snapshot )
                    : hash( 'sha256', wp_json_encode( $applied_snapshot ) );
            }

            $diff = function_exists( 'seo_supplier_sync_diff' )
                ? seo_supplier_sync_diff( $applied_snapshot, $current_snapshot )
                : [];

            $situation = function_exists( 'seo_supplier_sync_determine_situation' )
                ? seo_supplier_sync_determine_situation( $existing, $selection, $object_id, $current_hash, $diff )
                : ( empty( $diff ) ? 'sin_cambios' : 'modificado' );

            $data['proveedor']               = $state['proveedor'];
            $data['hash_producto']           = $current_hash;
            $data['hash_aplicado']           = $applied_hash;
            $data['snapshot_aplicado']       = function_exists( 'seo_supplier_sync_snapshot_json' ) && ! empty( $applied_snapshot )
                ? seo_supplier_sync_snapshot_json( $applied_snapshot )
                : (string) ( $existing['snapshot_aplicado'] ?? '' );
            $data['cambios_detectados']      = function_exists( 'seo_supplier_sync_changes_token' )
                ? seo_supplier_sync_changes_token( $diff )
                : '';
            $data['estado_seleccion']        = $selection ?: 'pendiente';
            $data['estado_sincronizacion']   = $situation;
            $data['object_id']               = $object_id ?: null;
            $data['last_seen_run_id']        = $run_id ?: null;
            $data['missing_since_run_id']    = null;
            $data['ultimo_error_sync']       = null;
            $data['ultima_importacion']      = $now;
            $data['actualizado']             = $now;

            $current_mode = sanitize_key( (string) ( $existing['modo_imagenes'] ?? 'inherit' ) );
            if ( ! empty( $state['v2_force_image_mode'] ) || ! in_array( $current_mode, [ 'external', 'local' ], true ) ) {
                $data['modo_imagenes'] = in_array( sanitize_key( (string) ( $state['v2_image_mode'] ?? '' ) ), [ 'external', 'local' ], true )
                    ? sanitize_key( (string) $state['v2_image_mode'] )
                    : $current_mode;
            } else {
                $data['modo_imagenes'] = $current_mode;
            }

            $updated = $wpdb->update( $table, $data, [ 'id' => absint( $existing['id'] ) ] );

            if ( false === $updated ) {
                $log['errores']++;
                seo_ie_add_log_detail( $log, sprintf( 'Fila preparada %d: %s', $line, $wpdb->last_error ?: 'Error SQL al actualizar.' ) );
                continue;
            }

            if ( 'modificado' === $situation || 'reactivado' === $situation ) {
                $log['pendientes_actualizacion']++;
            } elseif ( 'sin_cambios' === $situation ) {
                $log['sin_cambios']++;
            }

            $log['actualizados']++;
            $log['correctos']++;
            continue;
        }

        /* Fila realmente nueva en el catalogo intermedio. */
        $data['proveedor']             = $state['proveedor'];
        $data['hash_producto']         = $current_hash;
        $data['last_seen_run_id']      = $run_id ?: null;
        $data['ultima_importacion']    = $now;
        $data['primera_importacion']   = $now;
        $data['creado']                = $now;
        $data['actualizado']           = $now;
        $data['modo_imagenes']         = in_array( sanitize_key( (string) ( $state['v2_image_mode'] ?? '' ) ), [ 'external', 'local' ], true )
            ? sanitize_key( (string) $state['v2_image_mode'] )
            : 'inherit';

        if ( $woo_product_id ) {
            $data['object_id']               = $woo_product_id;
            $data['estado_seleccion']        = 'aceptado';
            $data['estado_sincronizacion']   = 'modificado';
            $data['cambios_detectados']      = function_exists( 'seo_supplier_sync_changes_token' )
                ? seo_supplier_sync_changes_token( seo_supplier_sync_diff( [], $current_snapshot ) )
                : '';
            $log['pendientes_actualizacion']++;
        } else {
            $data['estado_seleccion']      = 'pendiente';
            $data['estado_sincronizacion'] = 'nuevo';
            $data['cambios_detectados']    = '';
        }

        if ( false === $wpdb->insert( $table, $data ) ) {
            $log['errores']++;
            seo_ie_add_log_detail( $log, sprintf( 'Fila preparada %d: %s', $line, $wpdb->last_error ?: 'Error SQL al insertar.' ) );
            continue;
        }

        $log['creados']++;
        $log['correctos']++;
    }

    if ( $run_id && function_exists( 'seo_supplier_sync_finalize_import' ) ) {
        $log = seo_supplier_sync_finalize_import( $run_id, $state, $log, $now );
    }

    seo_ie_store_log( $log );

    $user_id = get_current_user_id();
    if ( $user_id > 0 ) {
        update_user_meta(
            $user_id,
            'seo_proveedores_ultimo_csv_preparado',
            [
                'recipe'    => $state['recipe_label'] ?? '',
                'provider'  => $state['proveedor'],
                'filename'  => $state['filename'],
                'url'       => $state['url'] ?? '',
                'completed' => current_time( 'mysql' ),
                'log'       => $log,
            ]
        );
    }

    return $log;
}

/**
 * Campos universales admitidos por el catálogo de proveedores.
 *
 * @return array
 */
function seo_proveedores_campos_importacion() {

    return [
        'proveedor_id_externo' => [ 'label' => 'Código del proveedor', 'required' => true, 'type' => 'text' ],
        'sku'                   => [ 'label' => 'SKU del proveedor', 'type' => 'text' ],
        'mpn'                   => [ 'label' => 'MPN', 'type' => 'text' ],
        'url_origen'            => [ 'label' => 'Enlace del producto', 'type' => 'url' ],
        'url_canonica'          => [ 'label' => 'URL canónica', 'type' => 'url' ],
        'nombre'                => [ 'label' => 'Nombre', 'required' => true, 'type' => 'text' ],
        'descripcion'           => [ 'label' => 'Descripción', 'type' => 'html' ],
        'marca'                 => [ 'label' => 'Marca', 'type' => 'text' ],
        'categoria_proveedor'   => [ 'label' => 'Categoría del proveedor', 'type' => 'text' ],
        'precio_sin_iva'        => [ 'label' => 'Precio sin IVA', 'type' => 'decimal' ],
        'precio_con_iva'        => [ 'label' => 'Precio con IVA', 'type' => 'decimal' ],
        'iva_porcentaje'        => [ 'label' => 'IVA (%)', 'type' => 'decimal' ],
        'moneda'                => [ 'label' => 'Moneda', 'type' => 'currency' ],
        'stock_estado'          => [ 'label' => 'Estado de stock', 'type' => 'text' ],
        'stock_cantidad'        => [ 'label' => 'Cantidad de stock', 'type' => 'decimal' ],
        'stock_texto'           => [ 'label' => 'Texto de stock', 'type' => 'text' ],
        'imagenes'              => [ 'label' => 'Imágenes', 'type' => 'longtext' ],
    ];
}

/**
 * Nombre real de la tabla de productos de proveedores.
 *
 * @return string
 */
function seo_proveedores_tabla_productos() {
    global $wpdb;
    return $wpdb->prefix . 'seo_proveedores_productos';
}

/**
 * Clave del estado temporal del importador para el usuario actual.
 *
 * @return string
 */
function seo_proveedores_importacion_transient_key() {
    return 'seo_proveedores_import_' . get_current_user_id();
}


/**
 * Descarta solo el estado de pantalla al abandonar la importacion.
 * Los archivos originales y preparados se conservan para auditoria.
 */
function seo_proveedores_descartar_importacion_al_salir() {
    if ( ! is_admin() || ! is_user_logged_in() ) {
        return;
    }

    if ( isset( $_POST['seo_proveedores_analizar'] ) || isset( $_POST['seo_proveedores_importar'] ) ) {
        return;
    }

    $page = sanitize_key( $_GET['page'] ?? '' );
    $tab  = sanitize_key( $_GET['seo_ie_tab'] ?? '' );

    if ( 'seo-import-export' === $page && 'importar-proveedor' === $tab ) {
        return;
    }

    delete_transient( seo_proveedores_importacion_transient_key() );
}

/**
 * Detecta el separador CSV más probable.
 *
 * @param string $line Primera línea del archivo.
 * @return string
 */
function seo_proveedores_detectar_separador( $line ) {

    $candidates = [ ';', ',', "\t", '|' ];
    $best       = ';';
    $max        = -1;

    foreach ( $candidates as $candidate ) {
        $count = substr_count( (string) $line, $candidate );
        if ( $count > $max ) {
            $max  = $count;
            $best = $candidate;
        }
    }

    return $best;
}

/**
 * Normaliza una cabecera sin imponer nombres internos.
 *
 * @param string $value Cabecera.
 * @return string
 */
function seo_proveedores_normalizar_cabecera( $value ) {
    $value = seo_ie_csv_to_utf8( (string) $value );
    $value = preg_replace( '/^\xEF\xBB\xBF/', '', $value );
    return trim( $value );
}


/**
 * Lee cabecera, muestra y numero de filas de un CSV.
 *
 * @param string $path Ruta local.
 * @return array|WP_Error
 */
function seo_proveedores_analizar_csv( $path ) {
    $handle = fopen( $path, 'r' );

    if ( false === $handle ) {
        return new WP_Error( 'open_failed', 'No se pudo abrir el archivo CSV.' );
    }

    $first_line = fgets( $handle );
    if ( false === $first_line ) {
        fclose( $handle );
        return new WP_Error( 'empty_file', 'El archivo esta vacio.' );
    }

    $separator = seo_proveedores_detectar_separador( $first_line );
    rewind( $handle );
    $header = fgetcsv( $handle, 0, $separator, '"', '' );

    if ( false === $header ) {
        fclose( $handle );
        return new WP_Error( 'header_failed', 'No se pudo leer la cabecera del CSV.' );
    }

    $header     = array_map( 'seo_proveedores_normalizar_cabecera', $header );
    $sample     = [];
    $rows_total = 0;

    while ( false !== ( $row = fgetcsv( $handle, 0, $separator, '"', '' ) ) ) {
        if ( empty( array_filter( $row, static fn( $value ) => '' !== trim( (string) $value ) ) ) ) {
            continue;
        }

        $rows_total++;
        if ( count( $sample ) < 5 ) {
            $sample[] = array_map( 'seo_ie_csv_to_utf8', $row );
        }
    }

    fclose( $handle );

    return [
        'format'     => 'csv',
        'separator'  => $separator,
        'header'     => $header,
        'sample'     => $sample,
        'rows_total' => $rows_total,
    ];
}


/**
 * Lee un XLSX sin depender de PhpSpreadsheet.
 *
 * Usa ZipArchive + SimpleXML, disponibles habitualmente en WordPress/PHP.
 * Conserva además los destinos reales de los hipervínculos de las celdas.
 *
 * @param string $path Ruta local.
 * @return array|WP_Error
 */
function seo_proveedores_xlsx_filas_ligeras( $path ) {
    if ( ! class_exists( 'ZipArchive' ) || ! function_exists( 'simplexml_load_string' ) ) {
        return new WP_Error( 'xlsx_native_missing', 'El servidor necesita ZipArchive y SimpleXML para leer XLSX sin PhpSpreadsheet.' );
    }

    $zip = new ZipArchive();
    if ( true !== $zip->open( $path ) ) {
        return new WP_Error( 'xlsx_open_failed', 'No se pudo abrir el archivo XLSX.' );
    }

    $shared = [];
    $shared_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
    if ( false !== $shared_xml ) {
        $xml = @simplexml_load_string( $shared_xml );
        if ( $xml ) {
            foreach ( $xml->si as $si ) {
                $parts = [];
                if ( isset( $si->t ) ) {
                    $parts[] = (string) $si->t;
                }
                if ( isset( $si->r ) ) {
                    foreach ( $si->r as $run ) {
                        $parts[] = (string) $run->t;
                    }
                }
                $shared[] = implode( '', $parts );
            }
        }
    }

    $sheet_path = 'xl/worksheets/sheet1.xml';
    $workbook_xml = $zip->getFromName( 'xl/workbook.xml' );
    $workbook_rels = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
    if ( false !== $workbook_xml && false !== $workbook_rels ) {
        $wb = @simplexml_load_string( $workbook_xml );
        $rels = @simplexml_load_string( $workbook_rels );
        if ( $wb && $rels ) {
            $wb->registerXPathNamespace( 'm', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
            $wb->registerXPathNamespace( 'r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );
            $sheets = $wb->xpath( '//m:sheets/m:sheet' );
            if ( ! empty( $sheets ) ) {
                $attrs = $sheets[0]->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );
                $rid = (string) ( $attrs['id'] ?? '' );
                foreach ( $rels->Relationship as $rel ) {
                    if ( (string) $rel['Id'] === $rid ) {
                        $target = ltrim( (string) $rel['Target'], '/' );
                        $sheet_path = 0 === strpos( $target, 'xl/' ) ? $target : 'xl/' . $target;
                        break;
                    }
                }
            }
        }
    }

    $sheet_xml = $zip->getFromName( $sheet_path );
    if ( false === $sheet_xml ) {
        $zip->close();
        return new WP_Error( 'xlsx_sheet_missing', 'No se encontró la primera hoja del XLSX.' );
    }

    $hyperlinks = [];
    $rels_path = dirname( $sheet_path ) . '/_rels/' . basename( $sheet_path ) . '.rels';
    $sheet_rels_xml = $zip->getFromName( $rels_path );
    $rel_targets = [];
    if ( false !== $sheet_rels_xml ) {
        $rels = @simplexml_load_string( $sheet_rels_xml );
        if ( $rels ) {
            foreach ( $rels->Relationship as $rel ) {
                $rel_targets[ (string) $rel['Id'] ] = (string) $rel['Target'];
            }
        }
    }

    $sheet = @simplexml_load_string( $sheet_xml );
    if ( ! $sheet ) {
        $zip->close();
        return new WP_Error( 'xlsx_xml_failed', 'No se pudo interpretar la hoja XLSX.' );
    }
    $sheet->registerXPathNamespace( 'm', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
    $sheet->registerXPathNamespace( 'r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );

    foreach ( $sheet->xpath( '//m:hyperlinks/m:hyperlink' ) as $link ) {
        $ref = (string) $link['ref'];
        $attrs = $link->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );
        $rid = (string) ( $attrs['id'] ?? '' );
        if ( '' !== $ref && '' !== $rid && isset( $rel_targets[ $rid ] ) ) {
            $hyperlinks[ $ref ] = $rel_targets[ $rid ];
        }
    }

    $rows = [];
    foreach ( $sheet->xpath( '//m:sheetData/m:row' ) as $row_node ) {
        $row = [];
        foreach ( $row_node->c as $cell ) {
            $ref = (string) $cell['r'];
            if ( ! preg_match( '/^([A-Z]+)(\d+)$/', $ref, $match ) ) {
                continue;
            }
            $letters = $match[1];
            $index = 0;
            for ( $i = 0, $len = strlen( $letters ); $i < $len; $i++ ) {
                $index = $index * 26 + ( ord( $letters[$i] ) - 64 );
            }
            $index--;
            $type = (string) $cell['t'];
            $value = '';
            if ( 's' === $type ) {
                $shared_index = (int) $cell->v;
                $value = $shared[ $shared_index ] ?? '';
            } elseif ( 'inlineStr' === $type ) {
                if ( isset( $cell->is->t ) ) {
                    $value = (string) $cell->is->t;
                } elseif ( isset( $cell->is->r ) ) {
                    foreach ( $cell->is->r as $run ) {
                        $value .= (string) $run->t;
                    }
                }
            } else {
                $value = isset( $cell->v ) ? (string) $cell->v : '';
            }
            if ( isset( $hyperlinks[ $ref ] ) && preg_match( '#^https?://#i', $hyperlinks[ $ref ] ) ) {
                $value = $hyperlinks[ $ref ];
            }
            $row[ $index ] = seo_ie_csv_to_utf8( $value );
        }
        if ( ! empty( $row ) ) {
            $max = max( array_keys( $row ) );
            $dense = array_fill( 0, $max + 1, '' );
            foreach ( $row as $index => $value ) {
                $dense[ $index ] = $value;
            }
            $rows[] = $dense;
        } else {
            $rows[] = [];
        }
    }
    $zip->close();
    return $rows;
}

/**
 * Devuelve las filas de un Excel antiguo XLS (BIFF/OLE).
 *
 * Estrategia:
 * 1. PhpSpreadsheet, si ya está cargado en WordPress.
 * 2. Conversión temporal a XLSX mediante LibreOffice/soffice, si el servidor
 *    dispone del ejecutable, y lectura posterior con el lector XLSX común.
 *
 * No se trata un XLS como CSV ni como XLSX renombrado.
 *
 * @param string $path Ruta local del XLS.
 * @return array|WP_Error
 */
function seo_proveedores_xls_filas( $path ) {
    // Primera opción: lector ligero incluido con el módulo. Funciona en
    // hostings compartidos sin Composer y con exec desactivado.
    if ( function_exists( 'seo_xls_biff_rows' ) ) {
        $rows = seo_xls_biff_rows( $path );
        if ( ! is_wp_error( $rows ) ) {
            return $rows;
        }
        // Si el fichero no es BIFF/OLE compatible, todavía se prueban los
        // motores opcionales existentes para mantener retrocompatibilidad.
    }

    if ( class_exists( '\\PhpOffice\\PhpSpreadsheet\\IOFactory' ) ) {
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load( $path );
            $sheet       = $spreadsheet->getActiveSheet();
            $rows        = $sheet->toArray( '', true, true, false );

            // Sustituye el texto visible de celdas enlazadas por la URL real.
            foreach ( $sheet->getHyperlinkCollection() as $coordinate => $hyperlink ) {
                $url = (string) $hyperlink->getUrl();

                if ( preg_match( '#^https?://#i', $url ) && preg_match( '/^([A-Z]+)(\d+)$/', $coordinate, $m ) ) {
                    $col = 0;
                    for ( $i = 0; $i < strlen( $m[1] ); $i++ ) {
                        $col = $col * 26 + ( ord( $m[1][ $i ] ) - 64 );
                    }

                    $row_index = (int) $m[2] - 1;
                    if ( isset( $rows[ $row_index ] ) ) {
                        $rows[ $row_index ][ $col - 1 ] = $url;
                    }
                }
            }

            return $rows;
        } catch ( Throwable $exception ) {
            return new WP_Error( 'xls_read_failed', 'No se pudo leer el XLS con PhpSpreadsheet: ' . $exception->getMessage() );
        }
    }

    // Respaldo: LibreOffice / soffice convierte el XLS original a XLSX.
    $disabled = array_filter( array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ) );
    if ( in_array( 'exec', $disabled, true ) ) {
        return new WP_Error(
            'xls_reader_missing',
            'El archivo es XLS antiguo. El servidor no tiene PhpSpreadsheet disponible y la función exec está desactivada, por lo que no puede convertir XLS a XLSX automáticamente.'
        );
    }

    $candidates = [ 'libreoffice', 'soffice' ];
    $binary     = '';

    foreach ( $candidates as $candidate ) {
        $output = [];
        $status = 1;
        @exec( 'command -v ' . escapeshellarg( $candidate ) . ' 2>/dev/null', $output, $status );
        if ( 0 === $status && ! empty( $output[0] ) ) {
            $binary = trim( (string) $output[0] );
            break;
        }
    }

    if ( '' === $binary ) {
        return new WP_Error(
            'xls_reader_missing',
            'El archivo es XLS antiguo. Para leerlo directamente el servidor necesita PhpSpreadsheet o LibreOffice/soffice. Ninguno está disponible.'
        );
    }

    $tmp_root = function_exists( 'wp_tempnam' ) ? dirname( wp_tempnam( 'seo-xls-' ) ) : sys_get_temp_dir();
    $tmp_dir  = trailingslashit( $tmp_root ) . 'seo-xls-' . wp_generate_password( 10, false, false );

    if ( ! wp_mkdir_p( $tmp_dir ) ) {
        return new WP_Error( 'xls_tmp_failed', 'No se pudo crear el directorio temporal para convertir el XLS.' );
    }

    $command = escapeshellarg( $binary )
        . ' --headless --convert-to xlsx --outdir '
        . escapeshellarg( $tmp_dir ) . ' '
        . escapeshellarg( $path ) . ' 2>&1';

    $output = [];
    $status = 1;
    @exec( $command, $output, $status );

    $converted = '';
    foreach ( (array) glob( trailingslashit( $tmp_dir ) . '*.xlsx' ) as $candidate ) {
        if ( is_readable( $candidate ) ) {
            $converted = $candidate;
            break;
        }
    }

    if ( 0 !== $status || '' === $converted ) {
        foreach ( (array) glob( trailingslashit( $tmp_dir ) . '*' ) as $file ) {
            @unlink( $file );
        }
        @rmdir( $tmp_dir );

        return new WP_Error(
            'xls_convert_failed',
            'LibreOffice no pudo convertir el XLS a XLSX. ' . implode( ' ', array_slice( $output, -3 ) )
        );
    }

    $rows = seo_proveedores_xlsx_filas_ligeras( $converted );

    foreach ( (array) glob( trailingslashit( $tmp_dir ) . '*' ) as $file ) {
        @unlink( $file );
    }
    @rmdir( $tmp_dir );

    return $rows;
}

/**
 * Lee cabecera, muestra y numero de filas de un XLS antiguo.
 *
 * @param string $path Ruta local.
 * @return array|WP_Error
 */
function seo_proveedores_analizar_xls( $path ) {
    $rows = seo_proveedores_xls_filas( $path );
    if ( is_wp_error( $rows ) ) {
        return $rows;
    }

    if ( empty( $rows ) ) {
        return new WP_Error( 'empty_file', 'El archivo XLS esta vacio.' );
    }

    $header_row  = array_shift( $rows );
    $max_columns = count( (array) $header_row );

    foreach ( $rows as $row ) {
        $max_columns = max( $max_columns, count( (array) $row ) );
    }

    $header = [];
    for ( $i = 0; $i < $max_columns; $i++ ) {
        $name     = seo_proveedores_normalizar_cabecera( $header_row[ $i ] ?? '' );
        $header[] = '' !== $name ? $name : 'columna_' . ( $i + 1 );
    }

    $rows = array_values(
        array_filter(
            $rows,
            static fn( $row ) => ! empty(
                array_filter(
                    (array) $row,
                    static fn( $value ) => '' !== trim( (string) $value )
                )
            )
        )
    );

    return [
        'format'     => 'xls',
        'separator'  => '',
        'header'     => $header,
        'sample'     => array_slice( $rows, 0, 25 ),
        'rows_total' => count( $rows ),
    ];
}

/**
 * Lee cabecera, muestra y numero de filas de un XLSX.
 * Usa PhpSpreadsheet cuando existe y el lector ligero como respaldo.
 *
 * @param string $path Ruta local.
 * @return array|WP_Error
 */
function seo_proveedores_analizar_xlsx( $path ) {
    if ( class_exists( '\\PhpOffice\\PhpSpreadsheet\\IOFactory' ) ) {
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load( $path );
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray( '', true, true, false );
            // Sustituye textos de celdas enlazadas por la URL real.
            foreach ( $sheet->getHyperlinkCollection() as $coordinate => $hyperlink ) {
                $url = (string) $hyperlink->getUrl();
                if ( preg_match( '#^https?://#i', $url ) && preg_match( '/^([A-Z]+)(\d+)$/', $coordinate, $m ) ) {
                    $col = 0;
                    for ( $i = 0; $i < strlen( $m[1] ); $i++ ) { $col = $col * 26 + ( ord( $m[1][$i] ) - 64 ); }
                    $row_index = (int) $m[2] - 1;
                    if ( isset( $rows[ $row_index ] ) ) { $rows[ $row_index ][ $col - 1 ] = $url; }
                }
            }
        } catch ( Throwable $exception ) {
            return new WP_Error( 'xlsx_read_failed', $exception->getMessage() );
        }
    } else {
        $rows = seo_proveedores_xlsx_filas_ligeras( $path );
        if ( is_wp_error( $rows ) ) { return $rows; }
    }

    if ( empty( $rows ) ) { return new WP_Error( 'empty_file', 'El archivo esta vacio.' ); }

    $header_row = array_shift( $rows );
    $max_columns = count( (array) $header_row );
    foreach ( $rows as $row ) { $max_columns = max( $max_columns, count( (array) $row ) ); }
    $header = [];
    for ( $i = 0; $i < $max_columns; $i++ ) {
        $name = seo_proveedores_normalizar_cabecera( $header_row[ $i ] ?? '' );
        $header[] = '' !== $name ? $name : 'columna_' . ( $i + 1 );
    }
    $rows = array_values( array_filter( $rows, static fn( $row ) => ! empty( array_filter( (array) $row, static fn( $value ) => '' !== trim( (string) $value ) ) ) ) );

    return [
        'format' => 'xlsx', 'separator' => '', 'header' => $header,
        'sample' => array_slice( $rows, 0, 25 ), 'rows_total' => count( $rows ),
    ];
}

/**
 * Paso 1 y 2: selecciona receta, guarda el original y analiza su estructura.
 *
 * @return void
 */
function seo_proveedores_analizar_archivo() {
    if ( isset( $_GET['seo_prov_reset'] ) ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No tienes permisos para importar catalogos.' );
        }

        check_admin_referer( 'seo_prov_reset' );
        delete_transient( seo_proveedores_importacion_transient_key() );
        wp_safe_redirect(
            add_query_arg(
                [ 'page' => 'seo-import-export', 'seo_ie_tab' => 'importar-proveedor' ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    if ( ! isset( $_POST['seo_proveedores_analizar'] ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permisos para importar catalogos.' );
    }

    check_admin_referer( 'seo_proveedores_analizar', 'seo_proveedores_nonce' );

    $recipe_id = sanitize_key( $_POST['receta_importacion'] ?? '' );
    $recipe    = seo_proveedores_obtener_receta( $recipe_id );

    if ( ! is_array( $recipe ) ) {
        wp_die( 'Selecciona una receta de importacion valida.' );
    }

    if ( empty( $_FILES['proveedores_archivo']['tmp_name'] ) || ! is_uploaded_file( $_FILES['proveedores_archivo']['tmp_name'] ) ) {
        wp_die( 'No se ha recibido un archivo valido.' );
    }

    $filename  = sanitize_file_name( $_FILES['proveedores_archivo']['name'] );
    $extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

    $recipe_extensions = array_values( array_filter( array_map( 'sanitize_key', (array) ( $recipe['accepted_extensions'] ?? [ 'csv', 'xls', 'xlsx' ] ) ) ) );
    if ( empty( $recipe_extensions ) ) {
        $recipe_extensions = [ 'csv', 'xls', 'xlsx' ];
    }

    if ( ! in_array( $extension, [ 'csv', 'xls', 'xlsx' ], true ) ) {
        wp_die( 'Solo se admiten archivos CSV, XLS o XLSX.' );
    }

    if ( ! in_array( $extension, $recipe_extensions, true ) ) {
        $message = sprintf(
            'La receta %s espera archivos %s. %s',
            (string) ( $recipe['label'] ?? $recipe_id ),
            strtoupper( implode( ', ', $recipe_extensions ) ),
            (string) ( $recipe['input_note'] ?? '' )
        );
        wp_die( esc_html( trim( $message ) ) );
    }

    $storage = seo_proveedores_storage_receta( 'original', $recipe_id );

    if ( is_wp_error( $storage ) ) {
        wp_die( esc_html( $storage->get_error_message() ) );
    }

    $stored_name = wp_unique_filename( $storage['dir'], $filename );
    $stored_path = trailingslashit( $storage['dir'] ) . $stored_name;

    if ( ! move_uploaded_file( $_FILES['proveedores_archivo']['tmp_name'], $stored_path ) ) {
        wp_die( 'No se pudo guardar el archivo original.' );
    }

    $analysis = 'xlsx' === $extension
        ? seo_proveedores_analizar_xlsx( $stored_path )
        : ( 'xls' === $extension
            ? seo_proveedores_analizar_xls( $stored_path )
            : seo_proveedores_analizar_csv( $stored_path )
        );

    if ( is_wp_error( $analysis ) ) {
        wp_die( esc_html( $analysis->get_error_message() ) );
    }

    $state = array_merge(
        $analysis,
        [
            'recipe_id'      => $recipe_id,
            'recipe_label'   => $recipe['label'],
            'recipe_version' => (string) ( $recipe['version'] ?? '' ),
            'proveedor'      => $recipe['provider'],
            'filename'       => $stored_name,
            'source_name'    => $filename,
            'path'           => wp_normalize_path( $stored_path ),
            'url'            => trailingslashit( $storage['url'] ) . rawurlencode( $stored_name ),
            'created'        => time(),
        ]
    );

    set_transient( seo_proveedores_importacion_transient_key(), $state, 6 * HOUR_IN_SECONDS );

    wp_safe_redirect(
        add_query_arg(
            [
                'page'             => 'seo-import-export',
                'seo_ie_tab'       => 'importar-proveedor',
                'seo_prov_mapping' => '1',
            ],
            admin_url( 'admin.php' )
        )
    );
    exit;
}

/**
 * Convierte un decimal de CSV a formato MySQL.
 *
 * @param mixed $value Valor original.
 * @return string|null
 */
function seo_proveedores_decimal( $value ) {

    $value = trim( (string) $value );
    if ( '' === $value ) {
        return null;
    }

    $value = str_replace( [ "\xC2\xA0", ' ', '€' ], '', $value );

    if ( false !== strpos( $value, ',' ) && false !== strpos( $value, '.' ) ) {
        if ( strrpos( $value, ',' ) > strrpos( $value, '.' ) ) {
            $value = str_replace( '.', '', $value );
            $value = str_replace( ',', '.', $value );
        } else {
            $value = str_replace( ',', '', $value );
        }
    } elseif ( false !== strpos( $value, ',' ) ) {
        $value = str_replace( ',', '.', $value );
    }

    $value = preg_replace( '/[^0-9.\-]/', '', $value );

    return is_numeric( $value ) ? $value : null;
}

/**
 * Normaliza una fecha para MySQL.
 *
 * @param mixed $value Valor original.
 * @return string|null
 */
function seo_proveedores_fecha_mysql( $value ) {

    $value = trim( (string) $value );
    if ( '' === $value ) {
        return null;
    }

    $timestamp = strtotime( $value );
    return false === $timestamp ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
}

/**
 * Limpia un campo según su tipo interno.
 *
 * @param mixed  $value Valor.
 * @param string $type Tipo.
 * @return mixed
 */
function seo_proveedores_limpiar_valor( $value, $type ) {

    $value = seo_ie_csv_to_utf8( $value );

    switch ( $type ) {
        case 'decimal':
            return seo_proveedores_decimal( $value );
        case 'integer':
            return '' === trim( (string) $value ) ? null : absint( $value );
        case 'url':
            return esc_url_raw( trim( (string) $value ) );
        case 'html':
            return wp_kses_post( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        case 'currency':
            return strtoupper( substr( sanitize_text_field( trim( (string) $value ) ), 0, 3 ) );
        case 'datetime':
            return seo_proveedores_fecha_mysql( $value );
        case 'json':
            $decoded = json_decode( (string) $value, true );
            return JSON_ERROR_NONE === json_last_error()
                ? wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
                : (string) $value;
        case 'hash':
            return preg_replace( '/[^a-fA-F0-9]/', '', (string) $value );
        case 'longtext':
            return (string) $value;
        default:
            return sanitize_text_field( trim( (string) $value ) );
    }
}

/**
 * Devuelve todas las filas del archivo como iterable.
 *
 * @param array $state Estado temporal.
 * @return Generator|WP_Error
 */
function seo_proveedores_iterar_filas( $state ) {

    if ( 'xls' === ( $state['format'] ?? '' ) ) {
        $rows = seo_proveedores_xls_filas( $state['path'] );
        if ( is_wp_error( $rows ) ) {
            return $rows;
        }

        array_shift( $rows );
        foreach ( $rows as $row ) {
            yield $row;
        }
        return;
    }

    if ( 'xlsx' === $state['format'] ) {
        if ( class_exists( '\\PhpOffice\\PhpSpreadsheet\\IOFactory' ) ) {
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load( $state['path'] );
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray( '', true, true, false );
                foreach ( $sheet->getHyperlinkCollection() as $coordinate => $hyperlink ) {
                    $url = (string) $hyperlink->getUrl();
                    if ( preg_match( '#^https?://#i', $url ) && preg_match( '/^([A-Z]+)(\d+)$/', $coordinate, $m ) ) {
                        $col = 0;
                        for ( $i = 0; $i < strlen( $m[1] ); $i++ ) { $col = $col * 26 + ( ord( $m[1][$i] ) - 64 ); }
                        $ri = (int) $m[2] - 1;
                        if ( isset( $rows[ $ri ] ) ) { $rows[ $ri ][ $col - 1 ] = $url; }
                    }
                }
            } catch ( Throwable $exception ) {
                return new WP_Error( 'xlsx_read_failed', $exception->getMessage() );
            }
        } else {
            $rows = seo_proveedores_xlsx_filas_ligeras( $state['path'] );
            if ( is_wp_error( $rows ) ) { return $rows; }
        }
        array_shift( $rows );
        foreach ( $rows as $row ) { yield $row; }
        return;
    }

    $handle = fopen( $state['path'], 'r' );
    if ( false === $handle ) {
        return new WP_Error( 'open_failed', 'No se pudo abrir el CSV.' );
    }

    fgetcsv( $handle, 0, $state['separator'], '"', '' );

    while ( false !== ( $row = fgetcsv( $handle, 0, $state['separator'], '"', '' ) ) ) {
        yield $row;
    }

    fclose( $handle );
}


/**
 * Indica si una receta necesita procesar el CSV preparado fuera de WordPress
 * antes de entregarlo al importador comun.
 *
 * VEVOR usa este punto para que GitHub/Python valide F/M y devuelva el CSV
 * enriquecido. Ningun archivo de Media se borra en este paso.
 *
 * @param array $recipe Receta activa.
 * @param array $prepared_state Estado del CSV preparado.
 * @return bool
 */
function seo_proveedores_debe_procesar_preparado_en_github( array $recipe, array $prepared_state ) {
    if ( 'github_python' !== sanitize_key( (string) ( $recipe['prepared_processor'] ?? '' ) ) ) {
        return false;
    }

    if ( ! empty( $recipe['prepared_processor_external_only'] ) ) {
        return 'external' === sanitize_key( (string) ( $prepared_state['v2_image_mode'] ?? '' ) );
    }

    return true;
}

/**
 * Envia un CSV preparado al runner GitHub/Python.
 *
 * @param array $prepared_state Estado del CSV preparado.
 * @param array $preparation_log Log de preparacion.
 * @return array|WP_Error
 */
function seo_proveedores_enviar_preparado_a_github( array $prepared_state, array $preparation_log = [] ) {
    if ( ! function_exists( 'seo_github_python_runner_start_prepared' ) ) {
        $runner_file = __DIR__ . '/github-python-runner.php';
        if ( is_readable( $runner_file ) ) {
            require_once $runner_file;
        }
    }

    if ( ! function_exists( 'seo_github_python_runner_start_prepared' ) ) {
        return new WP_Error(
            'supplier_github_runner_missing',
            'La receta necesita GitHub/Python, pero github-python-runner.php no esta disponible.'
        );
    }

    return seo_github_python_runner_start_prepared( $prepared_state, $preparation_log );
}

/**
 * Pasos 4 y 5: crea el CSV estandar, lo conserva e importa su contenido.
 *
 * @return void
 */
function seo_proveedores_importar_catalogo() {
    if ( ! isset( $_POST['seo_proveedores_importar'] ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permisos para importar catalogos.' );
    }

    check_admin_referer( 'seo_proveedores_importar', 'seo_proveedores_importar_nonce' );

    $state = get_transient( seo_proveedores_importacion_transient_key() );

    if ( ! is_array( $state ) || empty( $state['path'] ) || ! file_exists( $state['path'] ) ) {
        wp_die( 'La sesion de importacion ha caducado. Vuelve a cargar el archivo.' );
    }

    $recipe = seo_proveedores_obtener_receta( $state['recipe_id'] ?? '' );

    if ( ! is_array( $recipe ) ) {
        wp_die( 'La receta seleccionada ya no esta disponible.' );
    }

    $mapping = isset( $_POST['mapeo'] ) && is_array( $_POST['mapeo'] )
        ? array_map( 'sanitize_key', wp_unslash( $_POST['mapeo'] ) )
        : [];

    $prepared = seo_proveedores_preparar_csv_estandar( $state, $recipe, $mapping );

    if ( is_wp_error( $prepared ) ) {
        wp_die( esc_html( $prepared->get_error_message() ) );
    }

    $prepared_state = $prepared['state'];
    $prepared_state['v2_source'] = 'manual';
    $prepared_state['v2_catalog_complete'] = ! empty( $_POST['catalogo_completo'] );
    $prepared_state['v2_auto_apply'] = ! empty( $_POST['aplicar_actualizaciones_automaticamente'] );
    $prepared_state['v2_auto_bajas'] = ! empty( $_POST['aplicar_bajas_automaticamente'] );
    $prepared_state['v2_image_mode'] = in_array( sanitize_key( $_POST['modo_imagenes_v2'] ?? 'external' ), [ 'external', 'local' ], true )
        ? sanitize_key( $_POST['modo_imagenes_v2'] ?? 'external' )
        : 'external';
    $prepared_state['v2_force_image_mode'] = ! empty( $_POST['forzar_modo_imagenes_v2'] );

    /*
     * Algunas recetas necesitan enriquecer el CSV preparado antes de tocar el
     * catalogo intermedio. VEVOR lo envia a GitHub/Python para comprobar las
     * imagenes F/M. Cuando GitHub devuelve el CSV, github-python-runner.php lo
     * entrega a seo_proveedores_importar_csv_estandar() de forma asincrona.
     */
    if ( seo_proveedores_debe_procesar_preparado_en_github( $recipe, $prepared_state ) ) {
        $remote = seo_proveedores_enviar_preparado_a_github( $prepared_state, $prepared['log'] );

        if ( is_wp_error( $remote ) ) {
            wp_die( esc_html( $remote->get_error_message() ) );
        }

        delete_transient( seo_proveedores_importacion_transient_key() );

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'                    => 'seo-import-export',
                    'seo_ie_tab'              => 'importar-proveedor',
                    'seo_prov_github_started' => '1',
                    'seo_remote_run_id'       => sanitize_text_field( (string) ( $remote['remote_run_id'] ?? '' ) ),
                ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    $log = seo_proveedores_importar_csv_estandar( $prepared_state, $prepared['log'] );

    if ( is_wp_error( $log ) ) {
        wp_die( esc_html( $log->get_error_message() ) );
    }

    delete_transient( seo_proveedores_importacion_transient_key() );

    wp_safe_redirect(
        add_query_arg(
            [
                'page'          => 'seo-import-export',
                'seo_ie_tab'    => 'importar-proveedor',
                'seo_prov_done' => '1',
            ],
            admin_url( 'admin.php' )
        )
    );
    exit;
}

/**
 * Sugiere automáticamente un campo interno según la cabecera del proveedor.
 *
 * @param string $header Cabecera original.
 * @return string
 */
function seo_proveedores_sugerir_campo( $header ) {

    $key = sanitize_key( remove_accents( mb_strtolower( trim( (string) $header ) ) ) );

    $aliases = [
        'id_satkit'           => 'proveedor_id_externo',
        'id_producto'         => 'proveedor_id_externo',
        'product_id'          => 'proveedor_id_externo',
        'productid'           => 'proveedor_id_externo',
        'id'                  => 'proveedor_id_externo',
        'codigo'              => 'proveedor_id_externo',
        'referencia'          => 'proveedor_id_externo',
        'sku'                 => 'sku',
        'mpn'                 => 'mpn',
        'url_origen'          => 'url_origen',
        'url_to_product'      => 'url_origen',
        'product_url'         => 'url_origen',
        'enlace'              => 'url_origen',
        'url_canonica'        => 'url_canonica',
        'nombre'              => 'nombre',
        'name'                => 'nombre',
        'titulo'              => 'nombre',
        'title'               => 'nombre',
        'descripcion'         => 'descripcion',
        'description'         => 'descripcion',
        'marca_proveedor'     => 'marca',
        'marca'               => 'marca',
        'brand'               => 'marca',
        'categoria_proveedor' => 'categoria_proveedor',
        'categoria'           => 'categoria_proveedor',
        'category'            => 'categoria_proveedor',
        'categorypath'        => 'categoria_proveedor',
        'precio_sin_iva'      => 'precio_sin_iva',
        'cost'                => 'precio_sin_iva',
        'precio_iva'          => 'precio_con_iva',
        'precio_con_iva'      => 'precio_con_iva',
        'precio'              => 'precio_con_iva',
        'price'               => 'precio_con_iva',
        'sales'               => 'precio_con_iva',
        'iva_porcentaje'      => 'iva_porcentaje',
        'moneda'              => 'moneda',
        'currency'            => 'moneda',
        'stock_estado'        => 'stock_estado',
        'stock_cantidad'      => 'stock_cantidad',
        'stock_texto'         => 'stock_texto',
        'imagenes'            => 'imagenes',
        'imagen'              => 'imagenes',
        'url_to_image'        => 'imagenes',
        'image_url'           => 'imagenes',
    ];

    return $aliases[ $key ] ?? '';
}


/**
 * Genera una vista previa de la salida de una receta transformadora.
 *
 * La vista previa usa exactamente el mismo callback y los mismos limpiadores
 * que se aplicarán al crear el CSV estándar. De este modo se pueden comprobar
 * el SKU, el identificador externo y la URL limpia antes de importar.
 *
 * @param array $state Estado del archivo analizado.
 * @param array $recipe Receta seleccionada.
 * @param int   $limit Número máximo de filas.
 * @return array{rows:array,errors:array,fields:array}
 */
function seo_proveedores_previsualizar_transformacion( $state, $recipe, $limit = 3 ) {
    $result = [
        'rows'   => [],
        'errors' => [],
        'fields' => [],
    ];

    if ( ! is_array( $recipe ) || 'transform' !== ( $recipe['mode'] ?? 'mapping' ) ) {
        return $result;
    }

    $callback = $recipe['transform_callback'] ?? '';
    if ( ! is_callable( $callback ) ) {
        $result['errors'][] = 'La receta no tiene un transformador válido.';
        return $result;
    }

    $fields   = seo_proveedores_campos_importacion();
    $standard = seo_proveedores_cabecera_estandar();
    $targets  = [];

    foreach ( (array) ( $recipe['relations'] ?? [] ) as $relation ) {
        $target = sanitize_key( $relation['target'] ?? '' );
        if ( '' !== $target && isset( $fields[ $target ] ) ) {
            $targets[] = $target;
        }
    }

    // El identificador y el SKU deben verse siempre al principio.
    $targets = array_values(
        array_unique(
            array_merge(
                [ 'proveedor_id_externo', 'sku' ],
                $targets
            )
        )
    );
    $result['fields'] = $targets;

    $preview_iterator = seo_proveedores_iterar_filas( $state );
    $sample_index = 0;
    $checked = 0;
    if ( is_wp_error( $preview_iterator ) ) {
        $result['errors'][] = $preview_iterator->get_error_message();
        return $result;
    }
    foreach ( $preview_iterator as $sample_row ) {
        $sample_index++;
        $checked++;
        if ( $checked > 80 || count( $result['rows'] ) >= max( 1, absint( $limit ) ) ) { break; }
        $source_assoc = ! empty( $recipe['indexed_rows'] )
            ? array_values( (array) $sample_row )
            : seo_proveedores_fila_asociativa( (array) ( $state['header'] ?? [] ), (array) $sample_row );

        try {
            $normalized = call_user_func( $callback, $source_assoc, $state, $recipe );
        } catch ( Throwable $exception ) {
            $result['errors'][] = sprintf(
                'Ejemplo %d: %s',
                $sample_index + 1,
                $exception->getMessage()
            );
            continue;
        }

        if ( ! is_array( $normalized ) ) {
            $result['errors'][] = sprintf( 'Ejemplo %d omitido por la receta.', $sample_index + 1 );
            continue;
        }

        $clean = [];
        foreach ( $standard as $field_key ) {
            $value = $normalized[ $field_key ] ?? '';
            $clean[ $field_key ] = seo_proveedores_limpiar_valor( $value, $fields[ $field_key ]['type'] );
        }

        $result['rows'][] = $clean;
    }
    return $result;
}

/**
 * Renderiza el flujo común del módulo independiente de proveedores.
 *
 * 1. Escoger receta.
 * 2. Escoger archivo.
 * 3. Revisar entrada y relaciones.
 * 4. Crear CSV estandar.
 * 5. Importar el CSV preparado.
 *
 * @return void
 */
function seo_proveedores_render_importador() {
    $state   = get_transient( seo_proveedores_importacion_transient_key() );
    $fields  = seo_proveedores_campos_importacion();
    $recipes = seo_proveedores_recetas_importacion();
    $last    = get_user_meta( get_current_user_id(), 'seo_proveedores_ultimo_csv_preparado', true );
    $last    = is_array( $last ) ? $last : [];
    ?>
    <div class="card" style="max-width:none;padding:20px;margin-top:20px;">
        <h2><?php echo esc_html__( 'Importar catalogo de proveedor', 'seo-system' ); ?></h2>
        <p><code>Modulo de proveedores Build 032</code></p>

        <?php if ( ! empty( $_GET['seo_prov_github_started'] ) ) : ?>
            <?php $seo_remote_run_id = sanitize_text_field( wp_unslash( $_GET['seo_remote_run_id'] ?? '' ) ); ?>
            <div class="notice notice-success inline">
                <p><strong>VEVOR enviado a GitHub/Python.</strong> El CSV preparado se enriquecera fuera de WordPress y volvera automaticamente al importador comun. Puedes cerrar esta pagina.<?php echo '' !== $seo_remote_run_id ? ' ID remoto: ' . esc_html( $seo_remote_run_id ) . '.' : ''; ?></p>
            </div>
        <?php endif; ?>

        <?php if ( ! is_array( $state ) ) : ?>
            <p><strong>Dos vias, un mismo proceso:</strong> puedes subir un CSV/XLS del proveedor o dejar que una receta web lo construya automaticamente. En ambos casos el siguiente paso es siempre el mismo CSV estandar y el mismo motor de importacion.</p>

            <?php if ( empty( $recipes ) ) : ?>
                <div class="notice notice-error inline"><p>No se ha encontrado ninguna receta <code>Imports/import_*.php</code>.</p></div>
            <?php else : ?>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'seo_proveedores_analizar', 'seo_proveedores_nonce' ); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="seo-proveedor-receta">1. Receta</label></th>
                            <td>
                                <select id="seo-proveedor-receta" name="receta_importacion" required style="min-width:320px;">
                                    <option value="">Selecciona una receta</option>
                                    <?php foreach ( $recipes as $recipe ) : ?>
                                        <option value="<?php echo esc_attr( $recipe['id'] ); ?>">
                                            <?php echo esc_html( $recipe['label'] ); ?><?php echo ! empty( $recipe['version'] ) ? ' · v' . esc_html( $recipe['version'] ) : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Las recetas instaladas se detectan automaticamente desde la carpeta Imports.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="seo-proveedores-archivo">2. Archivo original</label></th>
                            <td>
                                <input id="seo-proveedores-archivo" type="file" name="proveedores_archivo" accept=".csv,.xls,.xlsx,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                                <p class="description">CSV, XLS o XLSX. XLSX dispone de lector ligero propio. Los XLS antiguos solo pueden leerse si el servidor dispone de PhpSpreadsheet o LibreOffice/soffice; las recetas pueden exigir un formato preparado alternativo.</p>
                            </td>
                        </tr>
                    </table>
                    <p><button type="submit" name="seo_proveedores_analizar" value="1" class="button button-primary">Leer archivo y mostrar relaciones</button></p>
                </form>
            <?php endif; ?>

            <?php if ( function_exists( 'seo_supplier_crawler_render_inline' ) ) : ?>
                <?php seo_supplier_crawler_render_inline(); ?>
            <?php endif; ?>
        <?php else : ?>
            <?php $recipe = seo_proveedores_obtener_receta( $state['recipe_id'] ?? '' ); ?>
            <p>
                <strong>Receta:</strong> <?php echo esc_html( $state['recipe_label'] ?? '' ); ?>
                <?php if ( ! empty( $state['recipe_version'] ) ) : ?>· v<?php echo esc_html( $state['recipe_version'] ); ?><?php endif; ?>
                · <strong>Proveedor:</strong> <?php echo esc_html( $state['proveedor'] ); ?>
            </p>
            <p>
                <strong>Archivo original:</strong> <?php echo esc_html( $state['source_name'] ?? $state['filename'] ); ?>
                · <strong>Filas detectadas:</strong> <?php echo number_format_i18n( absint( $state['rows_total'] ?? 0 ) ); ?>
                · <strong>Columnas:</strong> <?php echo number_format_i18n( count( (array) $state['header'] ) ); ?>
            </p>
            <p><a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'page' => 'seo-import-export', 'seo_ie_tab' => 'importar-proveedor', 'seo_prov_reset' => '1' ], admin_url( 'admin.php' ) ), 'seo_prov_reset' ) ); ?>">← Nueva importacion</a></p>

            <form method="post">
                <?php wp_nonce_field( 'seo_proveedores_importar', 'seo_proveedores_importar_nonce' ); ?>

                <h3>3. Informacion de entrada y relaciones</h3>

                <?php if ( is_array( $recipe ) && 'mapping' === ( $recipe['mode'] ?? 'mapping' ) ) : ?>
                    <p><?php echo esc_html( $recipe['description'] ?? '' ); ?></p>
                    <div style="overflow:auto;max-width:100%;">
                        <table class="widefat striped">
                            <thead><tr><th>Columna original</th><th>Campo del CSV estandar</th><th>Ejemplos</th></tr></thead>
                            <tbody>
                                <?php foreach ( $state['header'] as $index => $header ) : ?>
                                    <?php $suggested = seo_proveedores_sugerir_campo( $header ); ?>
                                    <tr>
                                        <td><strong><?php echo esc_html( $header ); ?></strong></td>
                                        <td>
                                            <select name="mapeo[<?php echo absint( $index ); ?>]" style="min-width:270px;">
                                                <option value="">No importar</option>
                                                <?php foreach ( $fields as $field_key => $definition ) : ?>
                                                    <option value="<?php echo esc_attr( $field_key ); ?>" <?php selected( $suggested, $field_key ); ?>>
                                                        <?php echo esc_html( $definition['label'] ); ?><?php echo ! empty( $definition['required'] ) ? ' *' : ''; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <?php
                                            $examples = [];
                                            foreach ( (array) $state['sample'] as $sample_row ) {
                                                $value = trim( (string) ( $sample_row[ $index ] ?? '' ) );
                                                if ( '' !== $value ) {
                                                    $examples[] = mb_strimwidth( wp_strip_all_tags( $value ), 0, 100, '...' );
                                                }
                                            }
                                            echo esc_html( implode( ' | ', array_slice( array_unique( $examples ), 0, 3 ) ) );
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else : ?>
                    <p><?php echo esc_html( is_array( $recipe ) ? ( $recipe['description'] ?? '' ) : '' ); ?></p>
                    <div style="overflow:auto;max-width:100%;">
                        <table class="widefat striped">
                            <thead><tr><th>Dato original</th><th>Campo preparado</th><th>Proceso de la receta</th></tr></thead>
                            <tbody>
                                <?php foreach ( (array) ( $recipe['relations'] ?? [] ) as $relation ) : ?>
                                    <?php $target = sanitize_key( $relation['target'] ?? '' ); ?>
                                    <tr>
                                        <td><strong><?php echo esc_html( $relation['source'] ?? '' ); ?></strong></td>
                                        <td><?php echo esc_html( $fields[ $target ]['label'] ?? $target ); ?></td>
                                        <td><?php echo esc_html( $relation['operation'] ?? '' ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php $prepared_preview = seo_proveedores_previsualizar_transformacion( $state, $recipe, 3 ); ?>
                    <h4>Ejemplos preparados: SKU calculado</h4>
                    <p><strong>Esta tabla ya no muestra solo el CSV original:</strong> contiene los valores transformados que se escribirán en el CSV preparado. Comprueba especialmente las columnas Código del proveedor y SKU del proveedor.</p>

                    <?php if ( ! empty( $prepared_preview['errors'] ) ) : ?>
                        <div class="notice notice-warning inline"><p>
                            <?php echo esc_html( implode( ' ', (array) $prepared_preview['errors'] ) ); ?>
                        </p></div>
                    <?php endif; ?>

                    <?php if ( ! empty( $prepared_preview['rows'] ) ) : ?>
                        <div style="overflow:auto;max-width:100%;">
                            <table class="widefat striped">
                                <thead><tr>
                                    <?php foreach ( (array) $prepared_preview['fields'] as $preview_field ) : ?>
                                        <th><?php echo esc_html( $fields[ $preview_field ]['label'] ?? $preview_field ); ?></th>
                                    <?php endforeach; ?>
                                </tr></thead>
                                <tbody>
                                    <?php foreach ( (array) $prepared_preview['rows'] as $prepared_row ) : ?>
                                        <tr>
                                            <?php foreach ( (array) $prepared_preview['fields'] as $preview_field ) : ?>
                                                <?php
                                                $preview_value = (string) ( $prepared_row[ $preview_field ] ?? '' );
                                                $is_identifier = in_array( $preview_field, [ 'proveedor_id_externo', 'sku', 'moneda', 'precio_con_iva', 'precio_sin_iva' ], true );
                                                $shown_value   = $is_identifier
                                                    ? $preview_value
                                                    : mb_strimwidth( wp_strip_all_tags( $preview_value ), 0, 85, '...' );
                                                ?>
                                                <td<?php echo in_array( $preview_field, [ 'proveedor_id_externo', 'sku' ], true ) ? ' style="font-family:monospace;font-weight:600;white-space:nowrap;"' : ''; ?>>
                                                    <?php echo '' !== $shown_value ? esc_html( $shown_value ) : '<span aria-label="Vacío">—</span>'; ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else : ?>
                        <p><em>No se pudo generar una vista previa de salida con las filas de ejemplo.</em></p>
                    <?php endif; ?>

                    <details style="margin-top:18px;">
                        <summary style="cursor:pointer;font-weight:600;">Ver ejemplos originales de entrada</summary>
                        <div style="overflow:auto;max-width:100%;margin-top:10px;">
                            <table class="widefat striped">
                                <thead><tr>
                                    <?php foreach ( (array) $state['header'] as $header ) : ?><th><?php echo esc_html( $header ); ?></th><?php endforeach; ?>
                                </tr></thead>
                                <tbody>
                                    <?php foreach ( array_slice( (array) $state['sample'], 0, 3 ) as $sample_row ) : ?>
                                        <tr>
                                            <?php foreach ( (array) $state['header'] as $index => $header ) : ?>
                                                <td><?php echo esc_html( mb_strimwidth( wp_strip_all_tags( (string) ( $sample_row[ $index ] ?? '' ) ), 0, 85, '...' ) ); ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                <?php endif; ?>

                <h3 style="margin-top:24px;">4 y 5. Preparar CSV e importar</h3>
                <p>El sistema creara un CSV estandar, lo guardara en <code>uploads/seo-proveedores-importaciones/preparados/<?php echo esc_html( $state['recipe_id'] ?? '' ); ?>/</code>, actualizara la tabla intermedia y clasificara cada fila como Nuevo, Modificado, Sin cambios, Baja, Reactivado o Conflicto.</p>

                <div style="padding:16px;border:1px solid #ccd0d4;background:#fff;margin:16px 0;">
                    <h4 style="margin-top:0;">Politica de importacion recurrente</h4>
                    <p class="description">
                        Importar no modifica automaticamente WooCommerce. Primero actualiza el catalogo intermedio y muestra la situacion y los cambios. Despues puedes filtrar y actuar por bloques desde <strong>Catalogo de proveedores</strong>.
                    </p>
                    <p>
                        <label><strong>Modo de imagenes para productos nuevos:</strong>
                            <select name="modo_imagenes_v2">
                                <option value="external" selected>Externas - usar hosting del proveedor</option>
                                <option value="local">Locales - descargar a WordPress</option>
                            </select>
                        </label>
                    </p>
                    <p><label><input type="checkbox" name="forzar_modo_imagenes_v2" value="1"> Usar tambien este modo de imagenes al actualizar productos existentes</label></p>
                    <hr>
                    <p><label><input type="checkbox" name="catalogo_completo" value="1"> <strong>Este archivo contiene el catalogo completo del proveedor</strong></label><br>
                    <span class="description">Solo con esta opcion se detectan bajas. Si hay errores u omisiones, la deteccion de bajas se cancela automaticamente.</span></p>
                </div>

                <p><button type="submit" name="seo_proveedores_importar" value="1" class="button button-primary button-large">Crear CSV e importar al catalogo intermedio</button></p>
            </form>
        <?php endif; ?>
    </div>

    <?php if ( ! empty( $last['url'] ) ) : ?>
        <div class="card" style="max-width:none;padding:20px;margin-top:20px;">
            <h2>Ultimo CSV preparado</h2>
            <p>
                <strong><?php echo esc_html( $last['recipe'] ?? '' ); ?></strong>
                · <?php echo esc_html( $last['provider'] ?? '' ); ?>
                · <?php echo esc_html( $last['completed'] ?? '' ); ?>
            </p>
            <?php $last_log = is_array( $last['log'] ?? null ) ? $last['log'] : []; ?>
            <?php if ( ! empty( $last_log ) ) : ?>
                <p>
                    Procesados: <strong><?php echo esc_html( number_format_i18n( absint( $last_log['procesados'] ?? 0 ) ) ); ?></strong>
                    · Correctos: <strong><?php echo esc_html( number_format_i18n( absint( $last_log['correctos'] ?? 0 ) ) ); ?></strong>
                    · Creados: <strong><?php echo esc_html( number_format_i18n( absint( $last_log['creados'] ?? 0 ) ) ); ?></strong>
                    · Actualizados en catálogo: <strong><?php echo esc_html( number_format_i18n( absint( $last_log['actualizados'] ?? 0 ) ) ); ?></strong>
                    · Pendientes de aplicar: <strong><?php echo esc_html( number_format_i18n( absint( $last_log['pendientes_actualizacion'] ?? 0 ) ) ); ?></strong>
                    · Sin cambios: <strong><?php echo esc_html( number_format_i18n( absint( $last_log['sin_cambios'] ?? 0 ) ) ); ?></strong>
                    · Errores: <strong><?php echo esc_html( number_format_i18n( absint( $last_log['errores'] ?? 0 ) ) ); ?></strong>
                </p>
            <?php endif; ?>
            <p><a class="button" href="<?php echo esc_url( $last['url'] ); ?>" download>Descargar <?php echo esc_html( $last['filename'] ?? 'CSV preparado' ); ?></a></p>
        </div>
    <?php endif; ?>
    <?php
}

/**
 * Muestra el último log en la misma página de Import / Export.
 *
 * @since 2.0.0
 *
 * @param array $log Log almacenado.
 * @return void
 */
function seo_ie_render_log( $log ) {

    if ( empty( $log ) ) {
        return;
    }

    ?>
    <div class="card" style="max-width:none;padding:20px;margin-top:20px;">
        <h2><?php echo esc_html__( 'Último proceso', 'seo-system' ); ?></h2>

        <table class="widefat striped" style="max-width:900px;">
            <tbody>
                <tr>
                    <th><?php echo esc_html__( 'Operación', 'seo-system' ); ?></th>
                    <td><?php echo esc_html( $log['operacion'] ?? '' ); ?></td>
                </tr>
                <tr>
                    <th><?php echo esc_html__( 'Fecha', 'seo-system' ); ?></th>
                    <td><?php echo esc_html( $log['fecha'] ?? '' ); ?></td>
                </tr>
                <tr>
                    <th><?php echo esc_html__( 'Archivo', 'seo-system' ); ?></th>
                    <td><?php echo esc_html( $log['archivo'] ?? '' ); ?></td>
                </tr>
                <tr>
                    <th><?php echo esc_html__( 'Procesados', 'seo-system' ); ?></th>
                    <td><?php echo absint( $log['procesados'] ?? 0 ); ?></td>
                </tr>
                <tr>
                    <th><?php echo esc_html__( 'Correctos', 'seo-system' ); ?></th>
                    <td><?php echo absint( $log['correctos'] ?? 0 ); ?></td>
                </tr>
                <?php if ( isset( $log['creados'] ) ) : ?>
                    <tr>
                        <th><?php echo esc_html__( 'Creados', 'seo-system' ); ?></th>
                        <td><?php echo absint( $log['creados'] ); ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ( isset( $log['actualizados'] ) ) : ?>
                    <tr>
                        <th><?php echo esc_html__( 'Actualizados en catálogo', 'seo-system' ); ?></th>
                        <td><?php echo absint( $log['actualizados'] ); ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ( isset( $log['pendientes_actualizacion'] ) ) : ?>
                    <tr>
                        <th><?php echo esc_html__( 'Pendientes de aplicar', 'seo-system' ); ?></th>
                        <td><?php echo absint( $log['pendientes_actualizacion'] ); ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ( isset( $log['omitidos'] ) ) : ?>
                    <tr>
                        <th><?php echo esc_html__( 'Omitidos', 'seo-system' ); ?></th>
                        <td><?php echo absint( $log['omitidos'] ); ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ( isset( $log['advertencias'] ) ) : ?>
                    <tr>
                        <th><?php echo esc_html__( 'Advertencias', 'seo-system' ); ?></th>
                        <td><?php echo absint( $log['advertencias'] ); ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ( ! empty( $log['simulacion'] ) ) : ?>
                    <tr>
                        <th><?php echo esc_html__( 'Modo', 'seo-system' ); ?></th>
                        <td><?php echo esc_html__( 'Simulación: sin escritura', 'seo-system' ); ?></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <th><?php echo esc_html__( 'Errores', 'seo-system' ); ?></th>
                    <td><?php echo absint( $log['errores'] ?? 0 ); ?></td>
                </tr>
            </tbody>
        </table>

        <?php if ( ! empty( $log['detalles'] ) ) : ?>
            <h3><?php echo esc_html__( 'Detalle', 'seo-system' ); ?></h3>

            <div style="max-height:350px;overflow:auto;border:1px solid #ccd0d4;background:#fff;padding:10px;">
                <ul style="margin:0 0 0 20px;">
                    <?php foreach ( $log['detalles'] as $detail ) : ?>
                        <li><?php echo esc_html( $detail ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ( ! empty( $log['truncado'] ) ) : ?>
            <p>
                <?php echo esc_html__( 'El detalle se ha limitado a 200 mensajes.', 'seo-system' ); ?>
            </p>
        <?php endif; ?>
    </div>
    <?php
}


/**
 * Columnas disponibles en el catálogo de proveedores.
 *
 * @return array
 */
function seo_proveedores_columnas_catalogo() {
    return [
        'id'                    => 'ID',
        'proveedor'             => 'Proveedor',
        'proveedor_id_externo'  => 'Código proveedor',
        'sku'                   => 'SKU',
        'mpn'                   => 'MPN',
        'nombre'                => 'Nombre',
        'marca'                 => 'Marca',
        'categoria_proveedor'   => 'Categoría proveedor',
        'categoria_sugerida'    => 'Categoría propuesta',
        'confianza_categoria'   => 'Confianza propuesta',
        'precio_sin_iva'        => 'Precio sin IVA',
        'precio_con_iva'        => 'Precio con IVA',
        'moneda'                => 'Moneda',
        'stock_estado'          => 'Stock',
        'stock_cantidad'        => 'Cantidad',
        'estado_seleccion'      => 'Seleccion',
        'estado_sincronizacion' => 'Situacion',
        'cambios'                => 'Cambios',
        'acciones_sync'          => 'Acciones',
        'modo_imagenes'         => 'Modo imagenes',
        'url_origen'            => 'Enlace',
        'imagenes'              => 'Imágenes',
        'primera_importacion'   => 'Primera importación',
        'ultima_importacion'    => 'Última importación',
        'object_id'             => 'Producto WordPress',
    ];
}

/**
 * Estados de revisión del catálogo.
 *
 * @return array
 */
function seo_proveedores_estados_catalogo() {
    return [
        'pendiente' => 'Pendiente',
        'revisar'   => 'Revisar',
        'aceptado'  => 'Aceptado',
        'descartado'=> 'Descartado',
    ];
}

/**
 * Situaciones detectadas por la importacion recurrente.
 *
 * @return array
 */
function seo_proveedores_situaciones_catalogo() {
    return function_exists( 'seo_supplier_sync_situations' )
        ? seo_supplier_sync_situations()
        : [
            'nuevo' => 'Nuevo',
            'modificado' => 'Modificado',
            'sin_cambios' => 'Sin cambios',
            'baja_pendiente' => 'Baja',
            'reactivado' => 'Reactivado',
            'conflicto' => 'Conflicto',
            'error' => 'Error',
        ];
}


/**
 * Columnas calculadas que se muestran en el catálogo, pero que no existen
 * físicamente en wp_seo_proveedores_productos.
 *
 * Mantenerlas separadas evita intentar incluirlas en consultas SELECT u
 * ordenaciones SQL.
 *
 * @return string[]
 */
function seo_proveedores_columnas_virtuales_catalogo() {
    return [
        'categoria_sugerida',
        'confianza_categoria',
        'cambios',
        'acciones_sync',
    ];
}


/**
 * Columnas del export de productos de proveedores preparado para clasificación.
 *
 * Combina la fotografía del catálogo intermedio con la clasificación vigente
 * del producto WooCommerce cuando la fila ya está enlazada mediante object_id.
 * No modifica ni recalcula categorías: es una operación de solo lectura.
 *
 * @return string[]
 */
function seo_proveedores_exportar_productos_columnas() {
    return [
        'catalogo_proveedor_id',
        'proveedor',
        'proveedor_id_externo',
        'sku',
        'mpn',
        'nombre',
        'marca',
        'categoria_proveedor',
        'descripcion_proveedor',
        'precio_sin_iva',
        'precio_con_iva',
        'iva_porcentaje',
        'moneda',
        'stock_estado',
        'stock_cantidad',
        'stock_texto',
        'estado_seleccion',
        'estado_sincronizacion',
        'cambios_detectados',
        'ultimo_error_sync',
        'modo_imagenes',
        'url_origen',
        'url_canonica',
        'imagenes',
        'primera_importacion',
        'ultima_importacion',
        'ultima_sincronizacion',
        'product_id',
        'wp_estado',
        'wp_titulo',
        'wp_slug',
        'wp_url',
        'categorias_wc_ids',
        'categorias_wc',
        'etiquetas_wc_ids',
        'etiquetas_wc',
        'tipo',
        'rol',
        'aplicacion',
        'plataforma',
        'subtipo',
        'atributos_seo_json',
        'atributos_seo',
        'clasificacion_estado',
        'clasificacion_faltantes',
    ];
}

/**
 * Obtiene en bloque el contexto WooCommerce/SEO de productos ya enlazados.
 *
 * Se evita deliberadamente un SELECT por producto para que el export siga
 * siendo utilizable con catálogos grandes de proveedores.
 *
 * @param int[] $product_ids IDs de producto.
 * @return array<int,array<string,mixed>>
 */
function seo_proveedores_exportar_productos_contexto_wp( $product_ids ) {
    global $wpdb;

    $product_ids = array_values(
        array_unique(
            array_filter(
                array_map( 'absint', (array) $product_ids )
            )
        )
    );

    if ( empty( $product_ids ) ) {
        return [];
    }

    $context = [];
    foreach ( $product_ids as $product_id ) {
        $context[ $product_id ] = [
            'wp_estado' => '',
            'wp_titulo' => '',
            'wp_slug' => '',
            'wp_url' => '',
            'categorias_wc_ids' => [],
            'categorias_wc' => [],
            'etiquetas_wc_ids' => [],
            'etiquetas_wc' => [],
            'semantics' => [
                'tipo' => [],
                'rol' => [],
                'aplicacion' => [],
                'plataforma' => [],
                'subtipo' => [],
            ],
            'attributes' => [],
        ];
    }

    $placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

    $post_sql = $wpdb->prepare(
        "SELECT ID, post_title, post_name, post_status
         FROM {$wpdb->posts}
         WHERE post_type = 'product'
           AND ID IN ({$placeholders})",
        $product_ids
    );
    $post_rows = $wpdb->get_results( $post_sql, ARRAY_A );

    foreach ( (array) $post_rows as $post_row ) {
        $product_id = absint( $post_row['ID'] ?? 0 );
        if ( ! isset( $context[ $product_id ] ) ) {
            continue;
        }

        $context[ $product_id ]['wp_estado'] = (string) ( $post_row['post_status'] ?? '' );
        $context[ $product_id ]['wp_titulo'] = (string) ( $post_row['post_title'] ?? '' );
        $context[ $product_id ]['wp_slug']   = (string) ( $post_row['post_name'] ?? '' );
        $context[ $product_id ]['wp_url']    = get_permalink( $product_id ) ?: '';
    }

    $term_sql = $wpdb->prepare(
        "SELECT tr.object_id, tt.taxonomy, t.term_id, t.name
         FROM {$wpdb->term_relationships} tr
         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
         INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
         WHERE tr.object_id IN ({$placeholders})
           AND tt.taxonomy IN ('product_cat','product_tag')
         ORDER BY tr.object_id ASC, tt.taxonomy ASC, t.name ASC",
        $product_ids
    );
    $term_rows = $wpdb->get_results( $term_sql, ARRAY_A );

    foreach ( (array) $term_rows as $term_row ) {
        $product_id = absint( $term_row['object_id'] ?? 0 );
        if ( ! isset( $context[ $product_id ] ) ) {
            continue;
        }

        $taxonomy = (string) ( $term_row['taxonomy'] ?? '' );
        if ( 'product_cat' === $taxonomy ) {
            $context[ $product_id ]['categorias_wc_ids'][] = absint( $term_row['term_id'] ?? 0 );
            $context[ $product_id ]['categorias_wc'][]     = (string) ( $term_row['name'] ?? '' );
        } elseif ( 'product_tag' === $taxonomy ) {
            $context[ $product_id ]['etiquetas_wc_ids'][] = absint( $term_row['term_id'] ?? 0 );
            $context[ $product_id ]['etiquetas_wc'][]     = (string) ( $term_row['name'] ?? '' );
        }
    }

    $objects    = $wpdb->prefix . 'seo_object_vocabulary';
    $vocabulary = $wpdb->prefix . 'seo_vocabulary';
    $objects_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $objects ) );
    $vocab_exists   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $vocabulary ) );

    if ( $objects_exists === $objects && $vocab_exists === $vocabulary ) {
        $semantic_sql = $wpdb->prepare(
            "SELECT ov.object_id, v.semantic_group, v.label
             FROM {$objects} ov
             INNER JOIN {$vocabulary} v
                ON v.id = ov.vocabulary_id
               AND v.active = 1
             WHERE ov.object_type = 'product'
               AND ov.object_id IN ({$placeholders})
               AND ov.status = 1
               AND v.semantic_group IN ('tipo','rol','aplicacion','plataforma','subtipo')
             ORDER BY ov.object_id ASC,
                      FIELD(v.semantic_group,'tipo','rol','aplicacion','plataforma','subtipo'),
                      v.label ASC",
            $product_ids
        );
        $semantic_rows = $wpdb->get_results( $semantic_sql, ARRAY_A );

        foreach ( (array) $semantic_rows as $semantic_row ) {
            $product_id = absint( $semantic_row['object_id'] ?? 0 );
            $group      = sanitize_key( $semantic_row['semantic_group'] ?? '' );
            $label      = trim( (string) ( $semantic_row['label'] ?? '' ) );

            if (
                isset( $context[ $product_id ]['semantics'][ $group ] )
                && '' !== $label
            ) {
                $context[ $product_id ]['semantics'][ $group ][] = $label;
            }
        }
    }

    /*
     * ROL canónico: TIPO -> seo_type_role_map -> ROL. Se exporta la etiqueta
     * humana del ROL, no el slug interno. Si el mapa no existe se conserva el
     * ROL materializado leído arriba como fallback.
     */
    $type_role_map = $wpdb->prefix . 'seo_type_role_map';
    $type_role_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $type_role_map ) );

    if (
        $objects_exists === $objects
        && $vocab_exists === $vocabulary
        && $type_role_exists === $type_role_map
    ) {
        $canonical_role_sql = $wpdb->prepare(
            "SELECT ot.object_id, rv.label
             FROM {$objects} ot
             INNER JOIN {$vocabulary} tv
                ON tv.id = ot.vocabulary_id
               AND tv.semantic_group = 'tipo'
               AND tv.active = 1
             INNER JOIN {$type_role_map} trm
                ON trm.type_vocabulary_id = tv.id
               AND trm.active = 1
             INNER JOIN {$vocabulary} rv
                ON rv.id = trm.role_vocabulary_id
               AND rv.semantic_group = 'rol'
               AND rv.active = 1
             WHERE ot.object_type = 'product'
               AND ot.object_id IN ({$placeholders})
               AND ot.status = 1
             ORDER BY ot.object_id ASC, rv.label ASC",
            $product_ids
        );
        $canonical_role_rows = $wpdb->get_results( $canonical_role_sql, ARRAY_A );
        $canonical_roles = [];

        foreach ( (array) $canonical_role_rows as $canonical_role_row ) {
            $product_id = absint( $canonical_role_row['object_id'] ?? 0 );
            $label      = trim( (string) ( $canonical_role_row['label'] ?? '' ) );
            if ( $product_id > 0 && '' !== $label ) {
                $canonical_roles[ $product_id ][] = $label;
            }
        }

        foreach ( $canonical_roles as $product_id => $role_labels ) {
            if ( isset( $context[ $product_id ] ) ) {
                $context[ $product_id ]['semantics']['rol'] = array_values( array_unique( $role_labels ) );
            }
        }
    }

    if ( function_exists( 'seo_attributes_get_rows_for_products' ) ) {
        $attribute_rows = seo_attributes_get_rows_for_products( $product_ids );
        foreach ( (array) $attribute_rows as $attribute_row ) {
            $product_id = absint( $attribute_row->product_id ?? 0 );
            if ( isset( $context[ $product_id ] ) ) {
                $context[ $product_id ]['attributes'][] = $attribute_row;
            }
        }
    }

    foreach ( $context as $product_id => &$item ) {
        foreach ( [ 'categorias_wc_ids', 'categorias_wc', 'etiquetas_wc_ids', 'etiquetas_wc' ] as $key ) {
            $item[ $key ] = array_values( array_unique( array_filter( (array) $item[ $key ], static function ( $value ) {
                return '' !== trim( (string) $value ) && '0' !== (string) $value;
            } ) ) );
        }

        foreach ( $item['semantics'] as $group => $labels ) {
            $item['semantics'][ $group ] = array_values(
                array_unique(
                    array_filter(
                        array_map( 'trim', (array) $labels )
                    )
                )
            );
        }
    }
    unset( $item );

    return $context;
}

/**
 * Exporta el catálogo intermedio de proveedores con columnas de clasificación.
 *
 * El proceso es estrictamente de lectura y pagina por ID para no cargar todo el
 * catálogo en memoria. Las propuestas de categoría del catálogo no se
 * recalculan aquí porque pueden ser costosas en catálogos con miles de rutas.
 *
 * @return void
 */
function seo_proveedores_exportar_productos_csv() {
    if ( ! isset( $_POST['seo_export_supplier_products'] ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos para exportar productos de proveedores.', 'seo-system' ) );
    }

    check_admin_referer( 'seo_export_supplier_products_csv', 'seo_export_supplier_products_nonce' );

    global $wpdb;

    $table = seo_proveedores_tabla_productos();
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( $exists !== $table ) {
        wp_die( esc_html__( 'No existe el catálogo de productos de proveedores.', 'seo-system' ) );
    }

    if ( function_exists( 'seo_supplier_sync_ensure_schema' ) ) {
        seo_supplier_sync_ensure_schema();
    }

    $provider  = sanitize_text_field( wp_unslash( $_POST['supplier_export_provider'] ?? '' ) );
    $selection = sanitize_key( $_POST['supplier_export_selection'] ?? '' );
    $situation = sanitize_key( $_POST['supplier_export_situation'] ?? '' );
    $linkage   = sanitize_key( $_POST['supplier_export_linkage'] ?? 'all' );

    $where  = [ '1=1' ];
    $params = [];

    if ( '' !== $provider ) {
        $where[]  = 'proveedor = %s';
        $params[] = $provider;
    }

    if ( '' !== $selection && isset( seo_proveedores_estados_catalogo()[ $selection ] ) ) {
        $where[]  = 'estado_seleccion = %s';
        $params[] = $selection;
    }

    if ( '' !== $situation && isset( seo_proveedores_situaciones_catalogo()[ $situation ] ) ) {
        $where[]  = 'estado_sincronizacion = %s';
        $params[] = $situation;
    }

    if ( 'linked' === $linkage ) {
        $where[] = 'object_id > 0';
    } elseif ( 'unlinked' === $linkage ) {
        $where[] = '(object_id IS NULL OR object_id = 0)';
    } else {
        $linkage = 'all';
    }

    $where_sql = implode( ' AND ', $where );
    $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
    if ( ! empty( $params ) ) {
        $count_sql = $wpdb->prepare( $count_sql, $params );
    }
    $total = absint( $wpdb->get_var( $count_sql ) );

    $filename_parts = [ 'seo_supplier_products_classified' ];
    if ( '' !== $provider ) {
        $filename_parts[] = sanitize_title( $provider );
    }
    $filename_parts[] = wp_date( 'Ymd_His' );
    $filename = implode( '_', array_filter( $filename_parts ) ) . '.csv';

    seo_ie_store_log(
        [
            'operacion'    => 'Exportación de productos de proveedores clasificados',
            'archivo'      => $filename,
            'procesados'   => $total,
            'correctos'    => $total,
            'errores'      => 0,
            'advertencias' => 0,
            'detalles'     => [
                'Export de solo lectura del catálogo intermedio de proveedores.',
                'Cuando object_id existe se añaden categoría WooCommerce, etiquetas, TIPO, ROL, APLICACIÓN, PLATAFORMA, SUBTIPO y atributos SEO actuales.',
                'No se recalculan propuestas de categoría durante la descarga para evitar carga innecesaria en catálogos grandes.',
            ],
        ]
    );

    $output  = seo_ie_open_csv_download( $filename );
    $columns = seo_proveedores_exportar_productos_columnas();
    seo_ie_write_csv_row( $output, $columns );

    $last_id    = 0;
    $batch_size = 500;

    do {
        $batch_where  = $where;
        $batch_params = $params;
        $batch_where[]  = 'id > %d';
        $batch_params[] = $last_id;

        $sql = "SELECT
                    id, proveedor, proveedor_id_externo, sku, mpn, nombre, marca,
                    categoria_proveedor, descripcion, precio_sin_iva, precio_con_iva,
                    iva_porcentaje, moneda, stock_estado, stock_cantidad, stock_texto,
                    estado_seleccion, estado_sincronizacion, cambios_detectados,
                    ultimo_error_sync, modo_imagenes, url_origen, url_canonica, imagenes,
                    primera_importacion, ultima_importacion, ultima_sincronizacion, object_id
                FROM {$table}
                WHERE " . implode( ' AND ', $batch_where ) . "
                ORDER BY id ASC
                LIMIT {$batch_size}";
        $sql = $wpdb->prepare( $sql, $batch_params );
        $rows = $wpdb->get_results( $sql, ARRAY_A );

        if ( empty( $rows ) ) {
            break;
        }

        $product_ids = [];
        foreach ( $rows as $source_row ) {
            $product_id = absint( $source_row['object_id'] ?? 0 );
            if ( $product_id > 0 ) {
                $product_ids[] = $product_id;
            }
        }

        $wp_context = seo_proveedores_exportar_productos_contexto_wp( $product_ids );

        foreach ( $rows as $source_row ) {
            $last_id    = max( $last_id, absint( $source_row['id'] ?? 0 ) );
            $product_id = absint( $source_row['object_id'] ?? 0 );
            $wp_data    = $wp_context[ $product_id ] ?? null;

            $semantics = is_array( $wp_data['semantics'] ?? null )
                ? $wp_data['semantics']
                : [ 'tipo' => [], 'rol' => [], 'aplicacion' => [], 'plataforma' => [], 'subtipo' => [] ];

            $semantic_text = [];
            foreach ( [ 'tipo', 'rol', 'aplicacion', 'plataforma', 'subtipo' ] as $group ) {
                $semantic_text[ $group ] = implode( ' | ', array_values( array_unique( (array) ( $semantics[ $group ] ?? [] ) ) ) );
            }

            if ( $product_id <= 0 ) {
                $classification_status  = 'sin_producto_wp';
                $classification_missing = 'tipo | rol | aplicacion';
            } else {
                $missing = [];
                foreach ( [ 'tipo', 'rol', 'aplicacion' ] as $required_group ) {
                    if ( '' === trim( (string) $semantic_text[ $required_group ] ) ) {
                        $missing[] = $required_group;
                    }
                }

                if ( empty( $missing ) ) {
                    $classification_status = 'clasificado';
                } elseif ( 3 === count( $missing ) && empty( $wp_data['attributes'] ) ) {
                    $classification_status = 'sin_clasificar';
                } else {
                    $classification_status = 'parcial';
                }

                $classification_missing = implode( ' | ', $missing );
            }

            $role_scope = $semantic_text['rol'];
            $attribute_rows = (array) ( $wp_data['attributes'] ?? [] );
            $attributes_json = function_exists( 'seo_ie_product_v2_seo_attributes_json' )
                ? seo_ie_product_v2_seo_attributes_json( $attribute_rows, $role_scope )
                : wp_json_encode( [] );
            $attributes_text = function_exists( 'seo_ie_serialize_attributes' )
                ? seo_ie_serialize_attributes( $attribute_rows, $role_scope )
                : '';

            $row = [
                'catalogo_proveedor_id'  => absint( $source_row['id'] ?? 0 ),
                'proveedor'              => (string) ( $source_row['proveedor'] ?? '' ),
                'proveedor_id_externo'   => (string) ( $source_row['proveedor_id_externo'] ?? '' ),
                'sku'                    => (string) ( $source_row['sku'] ?? '' ),
                'mpn'                    => (string) ( $source_row['mpn'] ?? '' ),
                'nombre'                 => (string) ( $source_row['nombre'] ?? '' ),
                'marca'                  => (string) ( $source_row['marca'] ?? '' ),
                'categoria_proveedor'    => (string) ( $source_row['categoria_proveedor'] ?? '' ),
                'descripcion_proveedor'  => (string) ( $source_row['descripcion'] ?? '' ),
                'precio_sin_iva'         => (string) ( $source_row['precio_sin_iva'] ?? '' ),
                'precio_con_iva'         => (string) ( $source_row['precio_con_iva'] ?? '' ),
                'iva_porcentaje'         => (string) ( $source_row['iva_porcentaje'] ?? '' ),
                'moneda'                 => (string) ( $source_row['moneda'] ?? '' ),
                'stock_estado'           => (string) ( $source_row['stock_estado'] ?? '' ),
                'stock_cantidad'         => (string) ( $source_row['stock_cantidad'] ?? '' ),
                'stock_texto'            => (string) ( $source_row['stock_texto'] ?? '' ),
                'estado_seleccion'       => (string) ( $source_row['estado_seleccion'] ?? '' ),
                'estado_sincronizacion'  => (string) ( $source_row['estado_sincronizacion'] ?? '' ),
                'cambios_detectados'     => (string) ( $source_row['cambios_detectados'] ?? '' ),
                'ultimo_error_sync'      => (string) ( $source_row['ultimo_error_sync'] ?? '' ),
                'modo_imagenes'          => (string) ( $source_row['modo_imagenes'] ?? '' ),
                'url_origen'             => (string) ( $source_row['url_origen'] ?? '' ),
                'url_canonica'           => (string) ( $source_row['url_canonica'] ?? '' ),
                'imagenes'               => (string) ( $source_row['imagenes'] ?? '' ),
                'primera_importacion'    => (string) ( $source_row['primera_importacion'] ?? '' ),
                'ultima_importacion'     => (string) ( $source_row['ultima_importacion'] ?? '' ),
                'ultima_sincronizacion'  => (string) ( $source_row['ultima_sincronizacion'] ?? '' ),
                'product_id'             => $product_id ?: '',
                'wp_estado'              => (string) ( $wp_data['wp_estado'] ?? '' ),
                'wp_titulo'              => (string) ( $wp_data['wp_titulo'] ?? '' ),
                'wp_slug'                => (string) ( $wp_data['wp_slug'] ?? '' ),
                'wp_url'                 => (string) ( $wp_data['wp_url'] ?? '' ),
                'categorias_wc_ids'      => implode( ',', (array) ( $wp_data['categorias_wc_ids'] ?? [] ) ),
                'categorias_wc'          => implode( ' | ', (array) ( $wp_data['categorias_wc'] ?? [] ) ),
                'etiquetas_wc_ids'       => implode( ',', (array) ( $wp_data['etiquetas_wc_ids'] ?? [] ) ),
                'etiquetas_wc'           => implode( ' | ', (array) ( $wp_data['etiquetas_wc'] ?? [] ) ),
                'tipo'                   => $semantic_text['tipo'],
                'rol'                    => $semantic_text['rol'],
                'aplicacion'             => $semantic_text['aplicacion'],
                'plataforma'             => $semantic_text['plataforma'],
                'subtipo'                => $semantic_text['subtipo'],
                'atributos_seo_json'     => $attributes_json,
                'atributos_seo'          => $attributes_text,
                'clasificacion_estado'   => $classification_status,
                'clasificacion_faltantes'=> $classification_missing,
            ];

            seo_ie_write_csv_row(
                $output,
                array_map(
                    static function ( $column ) use ( $row ) {
                        return $row[ $column ] ?? '';
                    },
                    $columns
                )
            );
        }

        if ( function_exists( 'wp_cache_flush_runtime' ) ) {
            wp_cache_flush_runtime();
        }
    } while ( count( $rows ) === $batch_size );

    fclose( $output );
    exit;
}

/**
 * Tarjeta del export de proveedores en la pestaña general Importar / Exportar.
 *
 * @return void
 */
function seo_proveedores_render_export_productos_card() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    global $wpdb;
    $table = seo_proveedores_tabla_productos();
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    ?>
    <div class="card" style="max-width:none;padding:20px;">
        <h2>Exportar productos de proveedores</h2>
        <p>
            Exporta el catálogo intermedio de proveedores en un CSV preparado para revisar y clasificar.
            Cuando una fila ya está enlazada a WooCommerce, añade su clasificación actual en columnas separadas:
            <strong>TIPO, ROL, APLICACIÓN, PLATAFORMA, SUBTIPO, categorías, etiquetas y atributos SEO</strong>.
        </p>

        <?php if ( $exists !== $table ) : ?>
            <div class="notice notice-warning inline"><p>No existe todavía el catálogo intermedio de proveedores.</p></div>
        <?php else : ?>
            <?php
            $providers = $wpdb->get_results(
                "SELECT proveedor, COUNT(*) AS total
                 FROM {$table}
                 GROUP BY proveedor
                 ORDER BY proveedor ASC",
                ARRAY_A
            );
            ?>
            <form method="post">
                <?php wp_nonce_field( 'seo_export_supplier_products_csv', 'seo_export_supplier_products_nonce' ); ?>

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin:16px 0;">
                    <label>
                        <strong>Proveedor</strong><br>
                        <select name="supplier_export_provider" style="width:100%;">
                            <option value="">Todos los proveedores</option>
                            <?php foreach ( (array) $providers as $provider_row ) : ?>
                                <?php $provider_name = (string) ( $provider_row['proveedor'] ?? '' ); ?>
                                <option value="<?php echo esc_attr( $provider_name ); ?>">
                                    <?php echo esc_html( $provider_name . ' (' . number_format_i18n( absint( $provider_row['total'] ?? 0 ) ) . ')' ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <strong>Selección comercial</strong><br>
                        <select name="supplier_export_selection" style="width:100%;">
                            <option value="">Todas</option>
                            <?php foreach ( seo_proveedores_estados_catalogo() as $state_key => $state_label ) : ?>
                                <option value="<?php echo esc_attr( $state_key ); ?>"><?php echo esc_html( $state_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <strong>Situación de sincronización</strong><br>
                        <select name="supplier_export_situation" style="width:100%;">
                            <option value="">Todas</option>
                            <?php foreach ( seo_proveedores_situaciones_catalogo() as $state_key => $state_label ) : ?>
                                <option value="<?php echo esc_attr( $state_key ); ?>"><?php echo esc_html( $state_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <strong>Relación con WooCommerce</strong><br>
                        <select name="supplier_export_linkage" style="width:100%;">
                            <option value="all">Todos</option>
                            <option value="linked">Solo enlazados a producto</option>
                            <option value="unlinked">Solo sin enlazar</option>
                        </select>
                    </label>
                </div>

                <details style="margin:12px 0 16px;">
                    <summary style="cursor:pointer;"><strong>Qué columnas incluye</strong></summary>
                    <p class="description" style="max-width:1000px;">
                        Identificación del proveedor, SKU/MPN, nombre, marca, categoría y descripción de origen; precio, IVA y stock;
                        estados de selección/sincronización; URLs e imágenes; producto WordPress enlazado; categorías y etiquetas WooCommerce;
                        TIPO, ROL, APLICACIÓN, PLATAFORMA, SUBTIPO, atributos SEO; y dos columnas de control:
                        <code>clasificacion_estado</code> y <code>clasificacion_faltantes</code>.
                    </p>
                </details>

                <button type="submit" name="seo_export_supplier_products" value="1" class="button button-primary">
                    Exportar productos de proveedores
                </button>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Obtiene la categoría de producto que WooCommerce utiliza como respaldo.
 *
 * El asistente nunca crea categorías. Cuando no existe una coincidencia clara,
 * utiliza esta categoría para que el borrador no quede sin clasificar.
 *
 * El filtro seo_proveedores_categoria_respaldo_id permite cambiar el ID desde
 * otro módulo sin modificar este archivo.
 *
 * @return array{term_id:int,name:string,valid:bool}
 */
function seo_proveedores_categoria_respaldo() {
    static $result = null;

    if ( null !== $result ) {
        return $result;
    }

    $term_id = absint(
        apply_filters(
            'seo_proveedores_categoria_respaldo_id',
            get_option( 'default_product_cat', 0 )
        )
    );

    $term = $term_id ? get_term( $term_id, 'product_cat' ) : null;

    /*
     * Algunos sitios antiguos no conservan correctamente default_product_cat.
     * Se prueban únicamente slugs habituales; nunca se crea una categoría ni
     * se elige una categoría arbitraria.
     */
    if ( ! $term || is_wp_error( $term ) ) {
        foreach ( [ 'sin-categoria', 'uncategorized' ] as $slug ) {
            $candidate = get_term_by( 'slug', $slug, 'product_cat' );

            if ( $candidate && ! is_wp_error( $candidate ) ) {
                $term = $candidate;
                break;
            }
        }
    }

    if ( ! $term || is_wp_error( $term ) ) {
        $result = [
            'term_id' => 0,
            'name'    => '',
            'valid'   => false,
        ];

        return $result;
    }

    $result = [
        'term_id' => absint( $term->term_id ),
        'name'    => (string) $term->name,
        'valid'   => true,
    ];

    return $result;
}

/**
 * Nombre de la opción que conserva equivalencias validadas al aceptar.
 *
 * Se utiliza una opción porque normalmente existen pocas decenas de
 * categorías por proveedor y no hace falta añadir otra tabla a la base de
 * datos. La opción no se carga automáticamente en todas las peticiones.
 *
 * @return string
 */
function seo_proveedores_opcion_mapeos_categoria() {
    return 'seo_proveedores_category_mappings';
}

/**
 * Construye una clave estable para proveedor + categoría original.
 *
 * @param string $provider          Nombre del proveedor.
 * @param string $supplier_category Categoría del proveedor.
 * @return string
 */
function seo_proveedores_clave_mapeo_categoria( $provider, $supplier_category ) {
    $provider_key = sanitize_key(
        remove_accents(
            mb_strtolower(
                trim( seo_ie_csv_to_utf8( (string) $provider ) )
            )
        )
    );

    $category_key = seo_proveedores_normalizar_nombre_categoria(
        $supplier_category
    );

    if ( '' === $provider_key || '' === $category_key ) {
        return '';
    }

    return hash( 'sha256', $provider_key . "\0" . $category_key );
}

/**
 * Lee las equivalencias que ya fueron confirmadas mediante una aceptación.
 *
 * @param bool $refresh Fuerza una nueva lectura después de guardar.
 * @return array<string,array>
 */
function seo_proveedores_mapeos_categoria_guardados( $refresh = false ) {
    static $mappings = null;

    if ( $refresh || null === $mappings ) {
        $stored   = get_option( seo_proveedores_opcion_mapeos_categoria(), [] );
        $mappings = is_array( $stored ) ? $stored : [];
    }

    return $mappings;
}

/**
 * Recupera una equivalencia validada y comprueba que la categoría siga viva.
 *
 * @param string $provider          Nombre del proveedor.
 * @param string $supplier_category Categoría original.
 * @return array|null
 */
function seo_proveedores_obtener_mapeo_categoria( $provider, $supplier_category ) {
    $key = seo_proveedores_clave_mapeo_categoria(
        $provider,
        $supplier_category
    );

    if ( '' === $key ) {
        return null;
    }

    $mappings = seo_proveedores_mapeos_categoria_guardados();
    $mapping  = $mappings[ $key ] ?? null;
    $term_id  = absint( $mapping['term_id'] ?? 0 );

    if ( ! $term_id ) {
        return null;
    }

    $fallback = seo_proveedores_categoria_respaldo();

    if ( $fallback['valid'] && $term_id === absint( $fallback['term_id'] ) ) {
        return null;
    }

    $term = get_term( $term_id, 'product_cat' );

    if ( ! $term || is_wp_error( $term ) ) {
        return null;
    }

    return [
        'term_id' => $term_id,
        'name'    => (string) $term->name,
    ];
}

/**
 * Guarda la equivalencia confirmada por una aceptación individual o masiva.
 *
 * No memoriza la categoría de respaldo: un producto que cae en el cajón de
 * sastre sigue siendo un caso pendiente de clasificación, no una equivalencia
 * semántica validada.
 *
 * @param string $provider          Nombre del proveedor.
 * @param string $supplier_category Categoría original.
 * @param int    $term_id           Categoría WooCommerce confirmada.
 * @return bool
 */
function seo_proveedores_guardar_mapeo_categoria( $provider, $supplier_category, $term_id ) {
    $key     = seo_proveedores_clave_mapeo_categoria( $provider, $supplier_category );
    $term_id = absint( $term_id );

    if ( '' === $key || ! $term_id ) {
        return false;
    }

    $term = get_term( $term_id, 'product_cat' );

    if ( ! $term || is_wp_error( $term ) ) {
        return false;
    }

    $mappings = seo_proveedores_mapeos_categoria_guardados();

    if (
        isset( $mappings[ $key ]['term_id'] )
        && absint( $mappings[ $key ]['term_id'] ) === $term_id
    ) {
        return true;
    }

    $mappings[ $key ] = [
        'provider'          => sanitize_text_field( (string) $provider ),
        'supplier_category' => sanitize_text_field( (string) $supplier_category ),
        'term_id'           => $term_id,
        'updated_at'        => current_time( 'mysql' ),
    ];

    $updated = update_option(
        seo_proveedores_opcion_mapeos_categoria(),
        $mappings,
        false
    );

    /* Sincroniza la caché local aunque update_option devuelva false sin cambios. */
    seo_proveedores_mapeos_categoria_guardados( true );

    return false !== $updated;
}

/**
 * Clave interna para acceder a una propuesta calculada por proveedor y título.
 *
 * @param string $provider          Proveedor.
 * @param string $supplier_category Categoría original.
 * @return string
 */
function seo_proveedores_clave_mapa_sugerencia( $provider, $supplier_category ) {
    return hash(
        'sha256',
        (string) $provider . "\0" . (string) $supplier_category
    );
}


/**
 * Normaliza el texto de una categoría para compararlo por su título.
 *
 * Se eliminan HTML, acentos, mayúsculas, signos y palabras funcionales que no
 * aportan significado. No se consultan excerpt ni descripción: el cálculo se
 * basa exclusivamente en el nombre de la categoría, tal como se acordó.
 *
 * @param mixed $value Nombre recibido.
 * @return string
 */
function seo_proveedores_normalizar_nombre_categoria( $value ) {
    $value = seo_ie_csv_to_utf8( (string) $value );
    $value = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $value = remove_accents( mb_strtolower( trim( $value ) ) );

    /* Los separadores de rutas del proveedor se convierten en espacios. */
    $value = preg_replace( '/(?:\s*::\s*|[>\/|»]+)+/u', ' ', $value );
    $value = preg_replace( '/[^a-z0-9]+/u', ' ', $value );
    $value = trim( preg_replace( '/\s+/u', ' ', $value ) );

    if ( '' === $value ) {
        return '';
    }

    $stop_words = [
        'a',
        'al',
        'de',
        'del',
        'e',
        'el',
        'en',
        'la',
        'las',
        'los',
        'para',
        'por',
        'un',
        'una',
        'unas',
        'unos',
        'y',
    ];

    $tokens = array_values(
        array_filter(
            explode( ' ', $value ),
            static function ( $token ) use ( $stop_words ) {
                return '' !== $token && ! in_array( $token, $stop_words, true );
            }
        )
    );

    return implode( ' ', $tokens );
}

/**
 * Genera variantes razonables de un token español para tratar singulares y
 * plurales sin utilizar un diccionario lingüístico pesado.
 *
 * Ejemplos:
 * - taladros -> taladros, taladro
 * - destornilladores -> destornilladores, destornilladore, destornillador
 * - luces -> luces, luce, luz
 *
 * @param string $token Token ya normalizado.
 * @return string[]
 */
function seo_proveedores_variantes_token_categoria( $token ) {
    $token    = trim( (string) $token );
    $variants = [ $token ];
    $length   = strlen( $token );

    if ( $length > 4 && 's' === substr( $token, -1 ) ) {
        $variants[] = substr( $token, 0, -1 );
    }

    if ( $length > 5 && 'es' === substr( $token, -2 ) ) {
        $variants[] = substr( $token, 0, -2 );
    }

    if ( $length > 4 && 'ces' === substr( $token, -3 ) ) {
        $variants[] = substr( $token, 0, -3 ) . 'z';
    }

    return array_values(
        array_unique(
            array_filter(
                $variants,
                static function ( $variant ) {
                    return '' !== $variant;
                }
            )
        )
    );
}

/**
 * Comprueba si dos palabras pueden considerarse equivalentes después de
 * normalizar singular y plural.
 *
 * @param string $left  Primer token.
 * @param string $right Segundo token.
 * @return bool
 */
function seo_proveedores_tokens_categoria_equivalentes( $left, $right ) {
    if ( $left === $right ) {
        return true;
    }

    return ! empty(
        array_intersect(
            seo_proveedores_variantes_token_categoria( $left ),
            seo_proveedores_variantes_token_categoria( $right )
        )
    );
}

/**
 * Cuenta palabras equivalentes sin reutilizar un mismo token dos veces.
 *
 * @param string[] $source_tokens    Tokens de la categoría del proveedor.
 * @param string[] $candidate_tokens Tokens de la categoría WooCommerce.
 * @return int
 */
function seo_proveedores_contar_tokens_categoria_coincidentes( $source_tokens, $candidate_tokens ) {
    $remaining = array_values( (array) $candidate_tokens );
    $matches   = 0;

    foreach ( (array) $source_tokens as $source_token ) {
        foreach ( $remaining as $index => $candidate_token ) {
            if ( seo_proveedores_tokens_categoria_equivalentes( $source_token, $candidate_token ) ) {
                $matches++;
                unset( $remaining[ $index ] );
                break;
            }
        }
    }

    return $matches;
}

/**
 * Calcula una puntuación de similitud entre dos nombres ya normalizados.
 *
 * Combina:
 * - coincidencia exacta;
 * - equivalencia singular/plural;
 * - cobertura de palabras;
 * - coincidencias parciales;
 * - distancia y similitud de caracteres para tolerar pequeños errores.
 *
 * @param string $source    Categoría normalizada del proveedor.
 * @param string $candidate Categoría normalizada de WooCommerce.
 * @return float Puntuación entre 0 y 100.
 */
function seo_proveedores_puntuacion_categoria( $source, $candidate ) {
    $source    = trim( (string) $source );
    $candidate = trim( (string) $candidate );

    if ( '' === $source || '' === $candidate ) {
        return 0.0;
    }

    if ( $source === $candidate ) {
        return 100.0;
    }

    $source_tokens    = array_values( array_filter( explode( ' ', $source ) ) );
    $candidate_tokens = array_values( array_filter( explode( ' ', $candidate ) ) );

    if ( empty( $source_tokens ) || empty( $candidate_tokens ) ) {
        return 0.0;
    }

    $matched       = seo_proveedores_contar_tokens_categoria_coincidentes( $source_tokens, $candidate_tokens );
    $source_count  = count( $source_tokens );
    $target_count  = count( $candidate_tokens );
    $union_count   = max( 1, $source_count + $target_count - $matched );
    $jaccard       = $matched / $union_count;
    $source_cover  = $matched / $source_count;
    $target_cover  = $matched / $target_count;

    /* Todos los tokens coinciden: solo cambia singular/plural u orden. */
    if (
        $source_count === $target_count
        && $matched === $source_count
    ) {
        return 100.0;
    }

    $similar_percent = 0.0;
    similar_text( $source, $candidate, $similar_percent );

    $max_length = max( strlen( $source ), strlen( $candidate ) );
    $levenshtein_percent = $max_length > 0
        ? max( 0, 100 - ( levenshtein( $source, $candidate ) / $max_length * 100 ) )
        : 0;

    $character_score = max( $similar_percent, $levenshtein_percent );

    /* Puntuación general: palabras compartidas + parecido de caracteres. */
    $score =
        ( 48 * $jaccard )
        + ( 12 * max( $source_cover, $target_cover ) )
        + ( 40 * ( $character_score / 100 ) );

    /*
     * Cuando todos los tokens de uno de los nombres están contenidos en el
     * otro, se trata como una coincidencia parcial fuerte.
     * Ejemplo: "brocas sds" frente a "brocas sds plus".
     */
    if ( $matched > 0 && $matched === min( $source_count, $target_count ) ) {
        $length_ratio = min( $source_count, $target_count ) / max( $source_count, $target_count );
        $score        = max( $score, 82 + ( 14 * $length_ratio ) );
    }

    /* Los errores tipográficos conservan normalmente un parecido alto. */
    $score = max(
        $score,
        ( 0.82 * $character_score ) + ( 18 * $jaccard )
    );

    if ( 1 === $source_count && 1 === $target_count ) {
        $score = max( $score, $character_score );
    }

    return round( min( 100, max( 0, $score ) ), 2 );
}

/**
 * Devuelve variantes del nombre del proveedor cuando llega como ruta.
 *
 * Se compara el texto completo y también cada nivel, dando prioridad natural
 * al nivel más específico porque suele obtener la puntuación más alta.
 *
 * @param string $category_name Categoría original del proveedor.
 * @return string[]
 */
function seo_proveedores_variantes_nombre_categoria_proveedor( $category_name ) {
    $category_name = seo_ie_csv_to_utf8( (string) $category_name );
    $parts         = preg_split( '/\s*(?:>|\/|\||»|::)\s*/u', $category_name );
    $variants      = [ seo_proveedores_normalizar_nombre_categoria( $category_name ) ];

    foreach ( (array) $parts as $part ) {
        $normalized = seo_proveedores_normalizar_nombre_categoria( $part );

        if ( '' !== $normalized ) {
            $variants[] = $normalized;
        }
    }

    return array_values( array_unique( array_filter( $variants ) ) );
}

/**
 * Carga una sola vez las categorías product_cat disponibles para sugerencias.
 *
 * La categoría de respaldo se excluye para impedir que aparezca como una
 * coincidencia normal. Su función es únicamente recibir casos sin propuesta.
 *
 * @return array<int,array{term_id:int,name:string,normalized:string}>
 */
function seo_proveedores_indice_categorias_woocommerce() {
    static $index = null;

    if ( null !== $index ) {
        return $index;
    }

    $index    = [];
    $fallback = seo_proveedores_categoria_respaldo();
    $terms    = get_terms(
        [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]
    );

    if ( is_wp_error( $terms ) ) {
        return $index;
    }

    foreach ( $terms as $term ) {
        $term_id = absint( $term->term_id );

        if ( $fallback['valid'] && $term_id === $fallback['term_id'] ) {
            continue;
        }

        $normalized = seo_proveedores_normalizar_nombre_categoria( $term->name );

        if ( '' === $normalized ) {
            continue;
        }

        $index[] = [
            'term_id'    => $term_id,
            'name'       => (string) $term->name,
            'normalized' => $normalized,
        ];
    }

    return $index;
}

/**
 * Busca la categoría WooCommerce más parecida al título del proveedor.
 *
 * Una propuesta solo se acepta cuando:
 * - supera el umbral mínimo; y
 * - no existe otra alternativa prácticamente empatada.
 *
 * De esta forma el sistema automatiza coincidencias claras, pero deja en la
 * categoría de respaldo los casos ambiguos. No crea categorías nuevas.
 *
 * Los filtros permiten ajustar umbral y margen sin editar este archivo:
 * - seo_proveedores_categoria_sugerida_umbral (72 por defecto)
 * - seo_proveedores_categoria_sugerida_margen (5 puntos por defecto)
 *
 * @param string     $category_name Nombre de la categoría del proveedor.
 * @param array|null $index         Índice opcional para pruebas o reutilización.
 * @param string     $provider      Proveedor para consultar equivalencias recordadas.
 * @return array{
 *     term_id:int,
 *     name:string,
 *     score:float,
 *     accepted:bool,
 *     reason:string,
 *     best_term_id:int,
 *     best_name:string
 * }
 */
function seo_proveedores_sugerir_categoria( $category_name, $index = null, $provider = '' ) {
    static $cache = [];

    $category_name = trim( seo_ie_csv_to_utf8( (string) $category_name ) );
    $provider      = trim( seo_ie_csv_to_utf8( (string) $provider ) );
    $cache_key     = md5( $provider . "\0" . $category_name );
    $use_cache     = null === $index;

    if ( $use_cache && isset( $cache[ $cache_key ] ) ) {
        return $cache[ $cache_key ];
    }

    $empty_result = [
        'term_id'      => 0,
        'name'         => '',
        'score'        => 0.0,
        'accepted'     => false,
        'reason'       => 'empty',
        'best_term_id' => 0,
        'best_name'    => '',
    ];

    /*
     * Una equivalencia validada tiene prioridad sobre el cálculo. Esto evita
     * que una futura categoría parecida cambie una decisión ya confirmada.
     */
    if ( '' !== $provider ) {
        $remembered = seo_proveedores_obtener_mapeo_categoria(
            $provider,
            $category_name
        );

        if ( $remembered ) {
            $remembered_result = [
                'term_id'      => absint( $remembered['term_id'] ),
                'name'         => (string) $remembered['name'],
                'score'        => 100.0,
                'accepted'     => true,
                'reason'       => 'remembered',
                'best_term_id' => absint( $remembered['term_id'] ),
                'best_name'    => (string) $remembered['name'],
            ];

            if ( $use_cache ) {
                $cache[ $cache_key ] = $remembered_result;
            }

            return $remembered_result;
        }
    }

    $variants = seo_proveedores_variantes_nombre_categoria_proveedor( $category_name );

    if ( empty( $variants ) ) {
        if ( $use_cache ) {
            $cache[ $cache_key ] = $empty_result;
        }
        return $empty_result;
    }

    if ( $use_cache ) {
        $index = seo_proveedores_indice_categorias_woocommerce();
    }

    if ( empty( $index ) ) {
        $empty_result['reason'] = 'no_categories';
        if ( $use_cache ) {
            $cache[ $cache_key ] = $empty_result;
        }
        return $empty_result;
    }

    $ranked = [];

    foreach ( $index as $candidate ) {
        $candidate_score = 0.0;

        foreach ( $variants as $variant ) {
            $candidate_score = max(
                $candidate_score,
                seo_proveedores_puntuacion_categoria(
                    $variant,
                    (string) $candidate['normalized']
                )
            );
        }

        $ranked[] = [
            'term_id' => absint( $candidate['term_id'] ),
            'name'    => (string) $candidate['name'],
            'score'   => $candidate_score,
        ];
    }

    usort(
        $ranked,
        static function ( $left, $right ) {
            if ( $left['score'] === $right['score'] ) {
                /* En empate se ordena de forma estable y comprensible. */
                $length_compare = strlen( $left['name'] ) <=> strlen( $right['name'] );
                return 0 !== $length_compare
                    ? $length_compare
                    : strcasecmp( $left['name'], $right['name'] );
            }

            return $right['score'] <=> $left['score'];
        }
    );

    $best   = $ranked[0];
    $second = $ranked[1] ?? null;

    $minimum_score = (float) apply_filters(
        'seo_proveedores_categoria_sugerida_umbral',
        72.0,
        $category_name,
        $best
    );

    $ambiguity_margin = (float) apply_filters(
        'seo_proveedores_categoria_sugerida_margen',
        5.0,
        $category_name,
        $best,
        $second
    );

    $result = [
        'term_id'      => 0,
        'name'         => '',
        'score'        => round( (float) $best['score'], 2 ),
        'accepted'     => false,
        'reason'       => 'low_confidence',
        'best_term_id' => absint( $best['term_id'] ),
        'best_name'    => (string) $best['name'],
    ];

    if ( $best['score'] >= $minimum_score ) {
        $difference = $second
            ? (float) $best['score'] - (float) $second['score']
            : 100.0;

        /*
         * Dos coincidencias exactas con el mismo título son ambiguas porque el
         * título, por sí solo, no permite saber qué rama jerárquica elegir.
         */
        $ambiguous = $second && (
            $difference < 0.5
            || ( $best['score'] < 98 && $difference < $ambiguity_margin )
        );

        if ( $ambiguous ) {
            $result['reason'] = 'ambiguous';
        } else {
            $result['term_id']  = absint( $best['term_id'] );
            $result['name']     = (string) $best['name'];
            $result['accepted'] = true;
            $result['reason']   = 'matched';
        }
    }

    if ( $use_cache ) {
        $cache[ $cache_key ] = $result;
    }

    return $result;
}

/**
 * Resuelve la categoría final que recibirá el producto al aceptarlo.
 *
 * @param string $supplier_category Categoría del proveedor.
 * @param string $provider          Nombre del proveedor.
 * @return array{term_id:int,name:string,source:string,score:float,reason:string,valid:bool}
 */
function seo_proveedores_resolver_categoria_destino( $supplier_category, $provider = '' ) {
    $suggestion = seo_proveedores_sugerir_categoria(
        $supplier_category,
        null,
        $provider
    );

    if ( ! empty( $suggestion['accepted'] ) && ! empty( $suggestion['term_id'] ) ) {
        return [
            'term_id' => absint( $suggestion['term_id'] ),
            'name'    => (string) $suggestion['name'],
            'source'  => 'remembered' === ( $suggestion['reason'] ?? '' )
                ? 'memoria'
                : 'sugerida',
            'score'   => (float) $suggestion['score'],
            'reason'  => (string) $suggestion['reason'],
            'valid'   => true,
        ];
    }

    $fallback = seo_proveedores_categoria_respaldo();

    return [
        'term_id' => absint( $fallback['term_id'] ),
        'name'    => (string) $fallback['name'],
        'source'  => 'respaldo',
        'score'   => (float) $suggestion['score'],
        'reason'  => (string) $suggestion['reason'],
        'valid'   => ! empty( $fallback['valid'] ),
    ];
}

/**
 * Devuelve las parejas distintas proveedor + categoría del catálogo.
 *
 * Una misma etiqueta puede significar cosas distintas para dos proveedores,
 * por lo que la memoria y las propuestas se mantienen separadas por proveedor.
 *
 * @param string $table Tabla de catálogo ya validada.
 * @return array<int,array{provider:string,supplier_category:string}>
 */
function seo_proveedores_pares_categoria_proveedor_distintos( $table ) {
    global $wpdb;

    $rows = $wpdb->get_results(
        "
        SELECT DISTINCT
            COALESCE(proveedor, '') AS provider,
            COALESCE(categoria_proveedor, '') AS supplier_category
        FROM {$table}
        ORDER BY proveedor ASC, categoria_proveedor ASC
        ",
        ARRAY_A
    );

    $pairs = [];

    foreach ( (array) $rows as $row ) {
        $pairs[] = [
            'provider'          => (string) ( $row['provider'] ?? '' ),
            'supplier_category' => (string) ( $row['supplier_category'] ?? '' ),
        ];
    }

    return $pairs;
}

/**
 * Extrae una lista única de categorías originales para el datalist del filtro.
 *
 * @param array $pairs Parejas proveedor/categoría.
 * @return string[]
 */
function seo_proveedores_categorias_proveedor_desde_pares( $pairs ) {
    $categories = [];

    foreach ( (array) $pairs as $pair ) {
        $category = (string) ( $pair['supplier_category'] ?? '' );

        if ( '' !== $category ) {
            $categories[ $category ] = $category;
        }
    }

    natcasesort( $categories );

    return array_values( $categories );
}

/**
 * Calcula un mapa de propuestas separado por proveedor y categoría original.
 *
 * @param array $pairs Parejas distintas del catálogo.
 * @return array<string,array>
 */
function seo_proveedores_mapa_sugerencias_categorias( $pairs ) {
    $map   = [];
    $index = seo_proveedores_indice_categorias_woocommerce();

    foreach ( (array) $pairs as $pair ) {
        $provider          = (string) ( $pair['provider'] ?? '' );
        $supplier_category = (string) ( $pair['supplier_category'] ?? '' );
        $key               = seo_proveedores_clave_mapa_sugerencia(
            $provider,
            $supplier_category
        );

        $suggestion = seo_proveedores_sugerir_categoria(
            $supplier_category,
            $index,
            $provider
        );

        $suggestion['provider']          = $provider;
        $suggestion['supplier_category'] = $supplier_category;
        $map[ $key ]                     = $suggestion;
    }

    return $map;
}

/**
 * Recupera de forma segura la propuesta de una fila concreta.
 *
 * @param array  $map               Mapa calculado.
 * @param string $provider          Proveedor de la fila.
 * @param string $supplier_category Categoría original.
 * @return array
 */
function seo_proveedores_obtener_sugerencia_mapa( $map, $provider, $supplier_category ) {
    $key = seo_proveedores_clave_mapa_sugerencia(
        $provider,
        $supplier_category
    );

    if ( isset( $map[ $key ] ) ) {
        return $map[ $key ];
    }

    return seo_proveedores_sugerir_categoria(
        $supplier_category,
        null,
        $provider
    );
}

/**
 * Añade al WHERE SQL el filtro de categoría propuesta.
 *
 * Las propuestas son datos calculados. Se traducen a parejas exactas de
 * proveedor + categoría original para que el filtro masivo afecte exactamente
 * a las mismas filas que se muestran en pantalla.
 *
 * @param string $filter Valor: ID de product_cat, "none" o vacío.
 * @param array  $map    Mapa de sugerencias.
 * @param array  $where  Condiciones SQL por referencia.
 * @param array  $params Parámetros de prepare por referencia.
 * @return void
 */
function seo_proveedores_aplicar_filtro_categoria_sugerida( $filter, $map, &$where, &$params ) {
    $filter = sanitize_text_field( (string) $filter );

    if ( '' === $filter ) {
        return;
    }

    $matching_pairs = [];

    foreach ( (array) $map as $suggestion ) {
        $term_id = absint( $suggestion['term_id'] ?? 0 );

        if (
            ( 'none' === $filter && 0 === $term_id )
            || ( 1 === preg_match( '/^\d+$/', $filter ) && absint( $filter ) === $term_id )
        ) {
            $provider          = (string) ( $suggestion['provider'] ?? '' );
            $supplier_category = (string) ( $suggestion['supplier_category'] ?? '' );
            $pair_key          = seo_proveedores_clave_mapa_sugerencia(
                $provider,
                $supplier_category
            );

            $matching_pairs[ $pair_key ] = [
                'provider'          => $provider,
                'supplier_category' => $supplier_category,
            ];
        }
    }

    if ( empty( $matching_pairs ) ) {
        $where[] = '1=0';
        return;
    }

    $clauses = [];

    foreach ( $matching_pairs as $pair ) {
        $clauses[] = "
            (
                COALESCE(proveedor, '') = %s
                AND COALESCE(categoria_proveedor, '') = %s
            )
        ";

        $params[] = $pair['provider'];
        $params[] = $pair['supplier_category'];
    }

    $where[] = '(' . implode( ' OR ', $clauses ) . ')';
}


/**
 * Etiqueta breve para interpretar el porcentaje de similitud.
 *
 * @param float $score Puntuación.
 * @return string
 */
function seo_proveedores_etiqueta_confianza_categoria( $score ) {
    $score = (float) $score;

    if ( $score >= 98 ) {
        return 'Exacta';
    }

    if ( $score >= 90 ) {
        return 'Muy alta';
    }

    if ( $score >= 80 ) {
        return 'Alta';
    }

    if ( $score >= 72 ) {
        return 'Revisar';
    }

    return 'Insuficiente';
}


/**
 * Normaliza una comisión porcentual para los precios del proveedor.
 *
 * @param mixed $value   Valor recibido.
 * @param float $default Valor predeterminado.
 * @return float
 */
function seo_proveedores_normalizar_comision( $value, $default ) {
    $value = is_scalar( $value )
        ? str_replace( ',', '.', sanitize_text_field( wp_unslash( (string) $value ) ) )
        : '';

    if ( '' === $value || ! is_numeric( $value ) ) {
        return (float) $default;
    }

    return max( 0, min( 1000, (float) $value ) );
}

/**
 * Localiza la taxonomía de marcas utilizada por WooCommerce o por un plugin.
 *
 * @return string
 */
function seo_proveedores_taxonomia_marca() {
    $candidates = [
        'product_brand',
        'pwb-brand',
        'yith_product_brand',
        'pa_marca',
        'pa_brand',
    ];

    foreach ( $candidates as $taxonomy ) {
        if ( taxonomy_exists( $taxonomy ) ) {
            return $taxonomy;
        }
    }

    $taxonomies = get_object_taxonomies( 'product', 'objects' );

    foreach ( $taxonomies as $taxonomy => $object ) {
        $label = remove_accents(
            mb_strtolower(
                trim(
                    (string) ( $object->labels->singular_name ?? $object->label ?? '' )
                )
            )
        );

        if ( in_array( $label, [ 'marca', 'brand' ], true ) ) {
            return (string) $taxonomy;
        }
    }

    return '';
}

/**
 * Asigna la marca del proveedor sin modificar las categorías manuales.
 *
 * @param int    $product_id ID del producto.
 * @param string $brand      Nombre de la marca.
 * @return true|WP_Error
 */
function seo_proveedores_asignar_marca( $product_id, $brand ) {
    $product_id = absint( $product_id );
    $brand      = sanitize_text_field( trim( (string) $brand ) );

    if ( $product_id < 1 || '' === $brand ) {
        return true;
    }

    $taxonomy = seo_proveedores_taxonomia_marca();

    if ( '' === $taxonomy ) {
        return new WP_Error(
            'seo_proveedores_brand_taxonomy_missing',
            'No se encontró una taxonomía de marcas para productos.'
        );
    }

    $term = term_exists( $brand, $taxonomy );

    if ( ! $term ) {
        $term = wp_insert_term( $brand, $taxonomy );
    }

    if ( is_wp_error( $term ) ) {
        return $term;
    }

    $term_id = is_array( $term )
        ? absint( $term['term_id'] )
        : absint( $term );

    $assigned = wp_set_object_terms(
        $product_id,
        [ $term_id ],
        $taxonomy,
        false
    );

    if ( is_wp_error( $assigned ) ) {
        return $assigned;
    }

    update_post_meta( $product_id, '_seo_marca_proveedor', $brand );
    update_post_meta( $product_id, '_seo_taxonomia_marca', $taxonomy );

    return true;
}

/**
 * Carga el gestor centralizado de imágenes del plugin.
 *
 * @return true|WP_Error
 */
function seo_proveedores_cargar_gestor_imagenes() {
    if ( function_exists( 'seo_images_sync_product_images' ) ) {
        return true;
    }

    $candidates = [
        dirname( __DIR__, 2 ) . '/seo-images.php',
    ];

    foreach ( array_unique( $candidates ) as $file ) {
        if ( is_readable( $file ) ) {
            require_once $file;
            break;
        }
    }

    if ( ! function_exists( 'seo_images_sync_product_images' ) ) {
        return new WP_Error(
            'seo_proveedores_images_module_missing',
            'No se pudo cargar seo-images.php.'
        );
    }

    return true;
}

/**
 * Construye una identidad estable para bloquear el alta de un producto.
 *
 * Se prioriza el SKU porque el flujo actual ya lo considera identificador de
 * producto. Cuando no existe, se utiliza proveedor + referencia externa y,
 * como último recurso, el ID de la fila intermedia.
 *
 * @param array $row Fila de wp_seo_proveedores_productos.
 * @return string
 */
function seo_proveedores_identidad_alta( array $row ) {

    $sku = mb_strtolower( trim( sanitize_text_field( (string) ( $row['sku'] ?? '' ) ) ) );

    if ( '' !== $sku ) {
        return 'sku|' . $sku;
    }

    $provider    = mb_strtolower( trim( sanitize_text_field( (string) ( $row['proveedor'] ?? '' ) ) ) );
    $external_id = mb_strtolower( trim( sanitize_text_field( (string) ( $row['proveedor_id_externo'] ?? '' ) ) ) );

    if ( '' !== $provider || '' !== $external_id ) {
        return 'provider|' . $provider . '|external|' . $external_id;
    }

    return 'catalog|' . absint( $row['id'] ?? 0 );
}

/**
 * Adquiere un bloqueo MySQL exclusivo para una operación de alta.
 *
 * GET_LOCK es atómico y funciona entre peticiones PHP distintas. Esto evita
 * que dos clics, dos pestañas o dos peticiones lentas creen simultáneamente el
 * mismo producto.
 *
 * @param array $row     Fila del catálogo intermedio.
 * @param int   $timeout Segundos máximos de espera.
 * @return string|WP_Error Nombre del bloqueo o error.
 */
function seo_proveedores_adquirir_bloqueo_alta( array $row, $timeout = 10 ) {

    global $wpdb;

    $identity  = seo_proveedores_identidad_alta( $row );
    $lock_name = 'seo_prov_' . md5( $wpdb->prefix . '|' . $identity );
    $acquired  = $wpdb->get_var(
        $wpdb->prepare(
            'SELECT GET_LOCK(%s, %d)',
            $lock_name,
            max( 0, absint( $timeout ) )
        )
    );

    if ( '1' !== (string) $acquired ) {
        return new WP_Error(
            'seo_proveedor_alta_bloqueada',
            'Este producto ya está siendo procesado por otra petición. No se ha creado ninguna copia.'
        );
    }

    return $lock_name;
}

/**
 * Libera un bloqueo de alta adquirido con GET_LOCK.
 *
 * @param string $lock_name Nombre del bloqueo.
 * @return void
 */
function seo_proveedores_liberar_bloqueo_alta( $lock_name ) {

    global $wpdb;

    if ( '' === (string) $lock_name ) {
        return;
    }

    $wpdb->get_var(
        $wpdb->prepare(
            'SELECT RELEASE_LOCK(%s)',
            (string) $lock_name
        )
    );
}

/**
 * Busca un producto ya creado para una fila del proveedor.
 *
 * La búsqueda no depende únicamente del SKU. Se comprueban, por este orden:
 * object_id, ID de la fila intermedia, proveedor + referencia externa y SKU.
 *
 * @param array $row Fila del catálogo intermedio.
 * @return int ID del producto o cero.
 */
function seo_proveedores_buscar_producto_existente( array $row ) {

    global $wpdb;

    $product_id = absint( $row['object_id'] ?? 0 );

    if ( $product_id && 'product' === get_post_type( $product_id ) && 'trash' !== get_post_status( $product_id ) ) {
        return $product_id;
    }

    $catalog_id = absint( $row['id'] ?? 0 );

    if ( $catalog_id ) {
        $product_id = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT p.ID
                     FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm
                        ON pm.post_id = p.ID
                       AND pm.meta_key = '_seo_proveedor_catalogo_id'
                     WHERE p.post_type = 'product'
                       AND p.post_status <> 'trash'
                       AND pm.meta_value = %s
                     ORDER BY p.ID ASC
                     LIMIT 1",
                    (string) $catalog_id
                )
            )
        );

        if ( $product_id ) {
            return $product_id;
        }
    }

    $provider    = sanitize_text_field( (string) ( $row['proveedor'] ?? '' ) );
    $external_id = sanitize_text_field( (string) ( $row['proveedor_id_externo'] ?? '' ) );

    if ( '' !== $provider && '' !== $external_id ) {
        $product_id = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT p.ID
                     FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} provider_meta
                        ON provider_meta.post_id = p.ID
                       AND provider_meta.meta_key = '_seo_proveedor'
                     INNER JOIN {$wpdb->postmeta} external_meta
                        ON external_meta.post_id = p.ID
                       AND external_meta.meta_key = '_seo_proveedor_id_externo'
                     WHERE p.post_type = 'product'
                       AND p.post_status <> 'trash'
                       AND provider_meta.meta_value = %s
                       AND external_meta.meta_value = %s
                     ORDER BY p.ID ASC
                     LIMIT 1",
                    $provider,
                    $external_id
                )
            )
        );

        if ( $product_id ) {
            return $product_id;
        }
    }

    $sku = sanitize_text_field( (string) ( $row['sku'] ?? '' ) );

    if ( '' !== $sku ) {
        $product_id = absint( wc_get_product_id_by_sku( $sku ) );

        if ( $product_id && 'product' === get_post_type( $product_id ) && 'trash' !== get_post_status( $product_id ) ) {
            return $product_id;
        }

        // Consulta directa de respaldo por si la caché de WooCommerce todavía
        // no refleja un producto creado unos instantes antes.
        $product_id = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT p.ID
                     FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} sku_meta
                        ON sku_meta.post_id = p.ID
                       AND sku_meta.meta_key = '_sku'
                     WHERE p.post_type = 'product'
                       AND p.post_status <> 'trash'
                       AND sku_meta.meta_value = %s
                     ORDER BY p.ID ASC
                     LIMIT 1",
                    $sku
                )
            )
        );
    }

    return $product_id;
}

/**
 * Nombre de la tabla de imagenes externas de proveedores.
 *
 * @return string
 */
function seo_proveedores_tabla_imagenes_externas() {
    global $wpdb;
    return $wpdb->prefix . 'seo_supplier_images';
}

/**
 * Crea la tabla de imagenes externas si todavia no existe.
 *
 * La tabla se mantiene separada del catalogo temporal y de los attachments de
 * WordPress. Una fila representa una imagen remota de un producto de proveedor.
 *
 * @return true|WP_Error
 */
function seo_proveedores_asegurar_tabla_imagenes_externas() {
    global $wpdb;

    $table = seo_proveedores_tabla_imagenes_externas();

    /*
     * IMPORTANTE: dbDelta debe ejecutarse tambien cuando la tabla ya existe.
     * De ese modo actua como migracion incremental y agrega columnas/indices
     * nuevos sin borrar las filas existentes.
     */
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        supplier VARCHAR(190) NOT NULL,
        supplier_product_id VARCHAR(190) DEFAULT NULL,
        supplier_sku VARCHAR(190) NOT NULL,
        product_id BIGINT UNSIGNED DEFAULT NULL,
        catalog_row_id BIGINT UNSIGNED DEFAULT NULL,
        source_url TEXT DEFAULT NULL,
        source_file VARCHAR(255) DEFAULT NULL,
        image_url TEXT NOT NULL,
        image_hash CHAR(64) NOT NULL,
        position INT UNSIGNED NOT NULL DEFAULT 0,
        is_primary TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        last_checked DATETIME DEFAULT NULL,
        http_status SMALLINT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_supplier_image (supplier,supplier_sku,image_hash),
        KEY idx_supplier_sku (supplier,supplier_sku),
        KEY idx_product_id (product_id),
        KEY idx_catalog_row_id (catalog_row_id),
        KEY idx_status (status)
    ) {$charset_collate};";

    dbDelta( $sql );

    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

    if ( $exists !== $table ) {
        return new WP_Error( 'seo_supplier_images_table', 'No se pudo crear la tabla de imagenes externas de proveedores.' );
    }

    /*
     * Migración defensiva de índices antiguos. Una imagen externa se identifica
     * por proveedor + SKU + hash de URL; por tanto la tabla DEBE permitir varias
     * filas para el mismo SKU. Versiones anteriores o instalaciones reparadas a
     * mano pueden conservar un UNIQUE sobre supplier_sku/product_id que bloquearía
     * la segunda imagen. Se eliminan únicamente esos índices únicos incompatibles
     * de esta tabla propia y se garantiza la clave correcta.
     */
    $index_rows = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
    $index_map  = [];

    foreach ( (array) $index_rows as $index_row ) {
        $index_name = (string) ( $index_row['Key_name'] ?? '' );
        if ( '' === $index_name || 'PRIMARY' === $index_name ) {
            continue;
        }

        if ( ! isset( $index_map[ $index_name ] ) ) {
            $index_map[ $index_name ] = [
                'unique'  => 0 === (int) ( $index_row['Non_unique'] ?? 1 ),
                'columns' => [],
            ];
        }

        $seq = max( 1, (int) ( $index_row['Seq_in_index'] ?? 1 ) );
        $index_map[ $index_name ]['columns'][ $seq ] = (string) ( $index_row['Column_name'] ?? '' );
    }

    $expected_columns = [ 'supplier', 'supplier_sku', 'image_hash' ];
    $expected_ok      = false;

    foreach ( $index_map as $index_name => $index_info ) {
        ksort( $index_info['columns'] );
        $columns = array_values( array_filter( $index_info['columns'] ) );

        if ( 'uniq_supplier_image' === $index_name && $index_info['unique'] && $columns === $expected_columns ) {
            $expected_ok = true;
            continue;
        }

        if ( ! $index_info['unique'] ) {
            continue;
        }

        /*
         * Esta tabla representa UNA FILA POR IMAGEN. Cualquier UNIQUE secundario
         * distinto de supplier + supplier_sku + image_hash puede impedir que un
         * mismo producto tenga varias imagenes (por ejemplo UNIQUE(product_id),
         * UNIQUE(supplier,supplier_sku) o UNIQUE(supplier_product_id)).
         * Por tanto se elimina todo UNIQUE secundario incompatible y se conserva
         * exclusivamente la identidad por imagen.
         */
        $is_expected_unique = $index_info['unique'] && $columns === $expected_columns;

        if ( $index_info['unique'] && ! $is_expected_unique ) {
            $safe_index_name = preg_replace( '/[^A-Za-z0-9_]/', '', $index_name );
            if ( '' !== $safe_index_name ) {
                $drop_index = $wpdb->query( "ALTER TABLE {$table} DROP INDEX `{$safe_index_name}`" );
                if ( false === $drop_index ) {
                    return new WP_Error(
                        'seo_supplier_images_drop_index',
                        sprintf(
                            'No se pudo eliminar el indice UNIQUE incompatible %s: %s',
                            $safe_index_name,
                            $wpdb->last_error ?: 'error SQL desconocido'
                        )
                    );
                }
            }
        }
    }

    if ( ! $expected_ok ) {
        $add_index = $wpdb->query(
            "ALTER TABLE {$table} ADD UNIQUE KEY uniq_supplier_image (supplier, supplier_sku, image_hash)"
        );

        if ( false === $add_index ) {
            return new WP_Error(
                'seo_supplier_images_index',
                'No se pudo garantizar el índice que permite varias imágenes por SKU: ' . ( $wpdb->last_error ?: 'error SQL desconocido' )
            );
        }
    }

    /* Verificacion explicita de la migracion para no fallar silenciosamente. */
    $required_columns = [ 'catalog_row_id', 'source_url', 'source_file' ];
    $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );

    foreach ( $required_columns as $required_column ) {
        if ( ! in_array( $required_column, (array) $columns, true ) ) {
            return new WP_Error(
                'seo_supplier_images_schema',
                sprintf( 'La tabla de imagenes externas no pudo agregar la columna requerida %s.', $required_column )
            );
        }
    }

    return true;
}

/**
 * Convierte el campo imagenes de la tabla intermedia en una lista de URLs.
 * Admite JSON, saltos de linea y separadores habituales.
 *
 * @param mixed $value Valor original.
 * @return string[]
 */
function seo_proveedores_extraer_urls_imagenes( $value ) {
    $value = trim( (string) $value );

    if ( '' === $value ) {
        return [];
    }

    $urls = [];
    $decoded = json_decode( $value, true );

    if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
        $stack = $decoded;
        while ( $stack ) {
            $item = array_shift( $stack );
            if ( is_array( $item ) ) {
                foreach ( $item as $nested ) {
                    $stack[] = $nested;
                }
                continue;
            }
            if ( is_scalar( $item ) && preg_match( '#^https?://#i', trim( (string) $item ) ) ) {
                $urls[] = trim( (string) $item );
            }
        }
    }

    if ( empty( $urls ) ) {
        preg_match_all( '#https?://[^\\s<>"\']+#iu', $value, $matches );
        $urls = (array) ( $matches[0] ?? [] );
    }

    $clean = [];
    foreach ( $urls as $url ) {
        $url = rtrim( trim( html_entity_decode( (string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), ",;|" );
        $url = esc_url_raw( $url );
        if ( '' !== $url ) {
            $clean[ $url ] = $url;
        }
    }

    return array_values( $clean );
}

/**
 * Guarda o sincroniza las imagenes remotas de una fila del catalogo.
 *
 * @param array $row        Fila de wp_seo_proveedores_productos.
 * @param int   $product_id Producto WooCommerce ya creado.
 * @return array|WP_Error
 */
/**
 * Desactiva las imagenes externas relacionadas con una fila/producto.
 *
 * Se usa cuando el usuario vuelve al modo normal de WordPress para impedir
 * que la plantilla mezcle recursos externos antiguos con la galeria local.
 * No crea la tabla si esta no existe.
 *
 * @param array $row        Fila de wp_seo_proveedores_productos.
 * @param int   $product_id ID de producto WooCommerce, si existe.
 * @return int Numero de filas actualizadas.
 */
function seo_proveedores_desactivar_imagenes_externas( array $row, $product_id = 0 ) {
    global $wpdb;

    $table = seo_proveedores_tabla_imagenes_externas();
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

    if ( $exists !== $table ) {
        return 0;
    }

    $supplier    = sanitize_text_field( (string) ( $row['proveedor'] ?? '' ) );
    $external_id = sanitize_text_field( (string) ( $row['proveedor_id_externo'] ?? '' ) );
    $sku         = sanitize_text_field( (string) ( $row['sku'] ?? '' ) );
    $product_id  = absint( $product_id );

    if ( '' === $sku ) {
        $sku = $external_id;
    }

    if ( '' === $supplier || '' === $sku ) {
        return 0;
    }

    $updated = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$table}
             SET status = 'disabled', updated_at = %s
             WHERE supplier = %s AND supplier_sku = %s",
            current_time( 'mysql' ),
            $supplier,
            $sku
        )
    );

    if ( $product_id ) {
        delete_post_meta( $product_id, '_seo_imagenes_externas' );
        delete_post_meta( $product_id, '_seo_imagenes_externas_total' );
    }

    return false === $updated ? 0 : (int) $updated;
}

function seo_proveedores_guardar_imagenes_externas( array $row, $product_id ) {
    global $wpdb;

    $ready = seo_proveedores_asegurar_tabla_imagenes_externas();
    if ( is_wp_error( $ready ) ) {
        return $ready;
    }

    $table       = seo_proveedores_tabla_imagenes_externas();
    $supplier    = sanitize_text_field( (string) ( $row['proveedor'] ?? '' ) );
    $external_id = sanitize_text_field( (string) ( $row['proveedor_id_externo'] ?? '' ) );
    $sku         = sanitize_text_field( (string) ( $row['sku'] ?? '' ) );
    $catalog_id  = absint( $row['id'] ?? 0 );
    $source_url  = esc_url_raw( (string) ( $row['url_origen'] ?? '' ) );
    $product_id  = absint( $product_id );
    $urls        = seo_proveedores_extraer_urls_imagenes( $row['imagenes'] ?? '' );
    $now         = current_time( 'mysql' );

    /*
     * Un scrape temporalmente vacío no debe destruir una galería externa que ya
     * funcionaba. En ese caso se conserva lo anterior y se informa al llamador.
     */
    if ( empty( $urls ) ) {
        return [
            'count'      => 0,
            'stored'     => 0,
            'product_id' => $product_id,
            'table'      => $table,
            'preserved'  => true,
            'message'    => 'El proveedor no ha entregado URLs de imagen; se conservan las relaciones externas existentes.',
        ];
    }

    if ( '' === $sku ) {
        $sku = $external_id;
    }

    if ( '' === $supplier || '' === $sku ) {
        return new WP_Error(
            'seo_supplier_images_identity',
            'Faltan proveedor o SKU para relacionar las imágenes externas.'
        );
    }

    /*
     * Sincronización autoritativa por proveedor + SKU.
     *
     * wp_seo_proveedores_productos.imagenes representa la fotografía actual de
     * la galería del proveedor. Para evitar estados parciales o upserts antiguos
     * que dejen solo la primera fila, reconstruimos todas las relaciones de ese
     * SKU dentro de una transacción: borrar -> insertar todas -> verificar.
     */
    $transaction_started = false !== $wpdb->query( 'START TRANSACTION' );

    try {
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE supplier = %s AND supplier_sku = %s",
                $supplier,
                $sku
            )
        );

        if ( false === $deleted ) {
            throw new RuntimeException(
                'No se pudieron limpiar las relaciones anteriores: ' . ( $wpdb->last_error ?: 'error SQL desconocido' )
            );
        }

        $inserted = 0;

        foreach ( $urls as $position => $url ) {
            $hash = hash( 'sha256', $url );

            $ok = $wpdb->insert(
                $table,
                [
                    'supplier'            => $supplier,
                    'supplier_product_id' => $external_id,
                    'supplier_sku'        => $sku,
                    'product_id'          => $product_id ?: null,
                    'image_url'           => $url,
                    'image_hash'          => $hash,
                    'position'            => absint( $position ),
                    'is_primary'          => 0 === (int) $position ? 1 : 0,
                    'status'              => 'active',
                    'source_file'         => 'catalogo-proveedores',
                    'created_at'          => $now,
                    'updated_at'          => $now,
                    'catalog_row_id'      => $catalog_id ?: null,
                    'source_url'          => $source_url ?: null,
                ],
                [
                    '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s',
                    '%s', '%s', '%s', '%d', '%s',
                ]
            );

            if ( false === $ok ) {
                throw new RuntimeException(
                    sprintf(
                        'No se pudo insertar la imagen %d de %s / %s: %s',
                        (int) $position + 1,
                        $supplier,
                        $sku,
                        $wpdb->last_error ?: 'error SQL desconocido'
                    )
                );
            }

            $inserted++;
        }

        $stored_active = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table}
                     WHERE supplier = %s
                       AND supplier_sku = %s
                       AND product_id = %d
                       AND status = 'active'",
                    $supplier,
                    $sku,
                    $product_id
                )
            )
        );

        if ( $inserted !== count( $urls ) || $stored_active !== count( $urls ) ) {
            throw new RuntimeException(
                sprintf(
                    'Sincronización incompleta para %s / %s: proveedor=%d, insertadas=%d, verificadas=%d.',
                    $supplier,
                    $sku,
                    count( $urls ),
                    $inserted,
                    $stored_active
                )
            );
        }

        if ( $transaction_started ) {
            $wpdb->query( 'COMMIT' );
        }

        return [
            'count'      => count( $urls ),
            'stored'     => $stored_active,
            'product_id' => $product_id,
            'table'      => $table,
            'rebuilt'    => true,
        ];
    } catch ( Throwable $e ) {
        if ( $transaction_started ) {
            $wpdb->query( 'ROLLBACK' );
        }

        return new WP_Error(
            'seo_supplier_images_rebuild',
            $e->getMessage()
        );
    }
}


/**
 * Decide el modo de imágenes al actualizar un producto existente.
 *
 * La casilla del catálogo puede activar explícitamente el modo externo. En
 * actualizaciones posteriores se conserva automáticamente si el producto ya
 * tiene la marca correspondiente o relaciones externas activas. De este modo
 * Satkit, Alphalium, Camelion u otros proveedores no dependen de volver a
 * marcar manualmente la casilla en cada CSV.
 *
 * @param array $row Fila del catálogo de proveedores.
 * @param bool  $requested_external Selección explícita de la pantalla.
 * @return bool
 */
function seo_proveedores_resolver_modo_imagenes_externas( array $row, $requested_external = false ) {
    if ( $requested_external ) {
        return true;
    }

    $stored_mode = sanitize_key( (string) ( $row['modo_imagenes'] ?? '' ) );
    if ( 'external' === $stored_mode ) {
        return true;
    }
    if ( 'local' === $stored_mode ) {
        return false;
    }

    $product_id = absint( $row['object_id'] ?? 0 );

    if ( ! $product_id ) {
        $product_id = absint( seo_proveedores_buscar_producto_existente( $row ) );
    }

    if ( $product_id && get_post_meta( $product_id, '_seo_imagenes_externas', true ) ) {
        return true;
    }

    global $wpdb;

    $table = seo_proveedores_tabla_imagenes_externas();
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

    if ( $exists !== $table ) {
        return false;
    }

    $supplier    = sanitize_text_field( (string) ( $row['proveedor'] ?? '' ) );
    $external_id = sanitize_text_field( (string) ( $row['proveedor_id_externo'] ?? '' ) );
    $sku         = sanitize_text_field( (string) ( $row['sku'] ?? '' ) );

    if ( '' === $sku ) {
        $sku = $external_id;
    }

    if ( '' === $supplier || '' === $sku ) {
        return false;
    }

    $active_id = absint(
        $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id
                 FROM {$table}
                 WHERE supplier = %s
                   AND supplier_sku = %s
                   AND status = 'active'
                 ORDER BY is_primary DESC, position ASC, id ASC
                 LIMIT 1",
                $supplier,
                $sku
            )
        )
    );

    return $active_id > 0;
}

/**
 * Crea o actualiza un producto WooCommerce desde una fila del proveedor.
 *
 * En un alta nueva el producto entra como draft/hidden en la categoría
 * Nuevos productos. En actualizaciones posteriores conserva la categoría y el
 * contenido editorial definitivo; solo se refrescan datos controlados por el
 * proveedor (título, identidad comercial, precio, stock, marca, URLs e imágenes).
 *
 * @param array $row                 Fila de wp_seo_proveedores_productos.
 * @param float $comision_descuento  Porcentaje para el precio rebajado.
 * @param float $comision_original   Porcentaje para el precio original.
 * @param bool  $imagenes_externas   Si es true no crea attachments y guarda las URLs en la tabla externa.
 * @return array{
 *     result:string,
 *     product_id:int,
 *     message:string,
 *     warnings:array,
 *     category?:array
 * }
 */
function seo_proveedores_crear_borrador_desde_fila( array $row, $comision_descuento = 20, $comision_original = 45, $imagenes_externas = false ) {
    if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Product_Simple' ) ) {
        return [
            'result'     => 'error',
            'product_id' => 0,
            'message'    => 'WooCommerce no está activo.',
            'warnings'   => [],
        ];
    }

    $lock_name = seo_proveedores_adquirir_bloqueo_alta( $row, 10 );

    if ( is_wp_error( $lock_name ) ) {
        return [
            'result'     => 'error',
            'product_id' => 0,
            'message'    => $lock_name->get_error_message(),
            'warnings'   => [],
        ];
    }

    try {
        global $wpdb;

        $providers_table = seo_proveedores_tabla_productos();
        $catalog_row_id  = absint( $row['id'] ?? 0 );

        /*
         * Otra petición puede haber terminado mientras esta esperaba el
         * bloqueo. Se vuelve a leer la fila para recuperar su object_id real.
         */
        if ( $catalog_row_id ) {
            $fresh_row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$providers_table} WHERE id = %d LIMIT 1",
                    $catalog_row_id
                ),
                ARRAY_A
            );

            if ( is_array( $fresh_row ) ) {
                $row = $fresh_row;
            }
        }

    $comision_descuento = seo_proveedores_normalizar_comision( $comision_descuento, 20 );
    $comision_original  = seo_proveedores_normalizar_comision( $comision_original, 45 );

    if ( $comision_original < $comision_descuento ) {
        $comision_original = $comision_descuento;
    }

    $sku        = sanitize_text_field( (string) ( $row['sku'] ?? '' ) );
    $product_id = absint( $row['object_id'] ?? 0 );
    $is_update  = false;
    $warnings   = [];

    /*
     * La misma resolución se usa en aceptación individual y masiva. Así no
     * puede existir una diferencia entre lo mostrado y lo finalmente asignado.
     */
    $category_destination = seo_proveedores_resolver_categoria_destino(
        (string) ( $row['categoria_proveedor'] ?? '' ),
        (string) ( $row['proveedor'] ?? '' )
    );

    if ( empty( $category_destination['valid'] ) || empty( $category_destination['term_id'] ) ) {
        return [
            'result'     => 'error',
            'product_id' => 0,
            'message'    => 'No existe una categoría de producto predeterminada válida para los casos sin propuesta.',
            'warnings'   => [],
        ];
    }

    $category_term = get_term(
        absint( $category_destination['term_id'] ),
        'product_cat'
    );

    if ( ! $category_term || is_wp_error( $category_term ) ) {
        return [
            'result'     => 'error',
            'product_id' => 0,
            'message'    => 'La categoría calculada ya no existe en WooCommerce.',
            'warnings'   => [],
        ];
    }

    /*
     * La búsqueda se repite dentro del bloqueo. Así, si otra petición creó el
     * producto mientras esta esperaba, se reutiliza en vez de crear una copia.
     */
    $product_id = seo_proveedores_buscar_producto_existente( $row );

    try {
        if ( $product_id ) {
            $product = wc_get_product( $product_id );

            if ( ! $product ) {
                return [
                    'result'     => 'error',
                    'product_id' => 0,
                    'message'    => 'No se pudo cargar el producto existente.',
                    'warnings'   => [],
                ];
            }

            $is_update = true;
        } else {
            $product = new WC_Product_Simple();
            $product->set_status( 'draft' );
            $product->set_catalog_visibility( 'hidden' );
        }

        $name = sanitize_text_field( (string) ( $row['nombre'] ?? '' ) );

        if ( '' !== $name ) {
            $product->set_name( $name );
        }

        // La descripcion editorial se toma del proveedor solo en el alta inicial.
        if ( ! $is_update ) {
            $product->set_description(
                wp_kses_post( (string) ( $row['descripcion'] ?? '' ) )
            );
        }

        if ( '' !== $sku ) {
            $current_sku = (string) $product->get_sku();
            if ( ! $is_update || '' === $current_sku || $current_sku === $sku ) {
                $product->set_sku( $sku );
            } elseif ( function_exists( 'wc_get_product_id_by_sku' ) ) {
                $sku_owner = absint( wc_get_product_id_by_sku( $sku ) );
                if ( ! $sku_owner || $sku_owner === absint( $product_id ) ) {
                    $product->set_sku( $sku );
                } else {
                    return [
                        'result'     => 'error',
                        'product_id' => absint( $product_id ),
                        'message'    => 'El SKU nuevo ya pertenece a otro producto. Se requiere revision manual.',
                        'warnings'   => [],
                    ];
                }
            }
        }

        $base_price = $row['precio_con_iva'] ?? '';

        if ( '' === (string) $base_price || null === $base_price ) {
            $base_price = $row['precio_sin_iva'] ?? '';
        }

        if ( '' !== (string) $base_price && null !== $base_price && is_numeric( $base_price ) ) {
            $base_price    = (float) $base_price;
            $sale_price    = round( $base_price * ( 1 + ( $comision_descuento / 100 ) ), wc_get_price_decimals() );
            $regular_price = round( $base_price * ( 1 + ( $comision_original / 100 ) ), wc_get_price_decimals() );

            $product->set_regular_price( wc_format_decimal( $regular_price ) );
            $product->set_sale_price( wc_format_decimal( $sale_price ) );
            $product->set_price( wc_format_decimal( $sale_price ) );
        }

        $stock_status = sanitize_key( (string) ( $row['stock_estado'] ?? '' ) );
        $stock_qty    = $row['stock_cantidad'] ?? null;

        if ( null !== $stock_qty && '' !== (string) $stock_qty && is_numeric( $stock_qty ) ) {
            $product->set_manage_stock( true );
            $product->set_stock_quantity( max( 0, (float) $stock_qty ) );
            $product->set_stock_status( (float) $stock_qty > 0 ? 'instock' : 'outofstock' );
        } else {
            $product->set_manage_stock( false );
            $product->set_stock_status(
                in_array( $stock_status, [ 'out_of_stock', 'outofstock', 'agotado', 'sin_stock' ], true )
                    ? 'outofstock'
                    : 'instock'
            );
        }

        /* Los productos nuevos entran en Nuevos productos. En actualizaciones
         * posteriores la categoria editorial definitiva nunca se modifica. */
        if ( ! $is_update && function_exists( 'seo_supplier_sync_new_products_category_id' ) ) {
            $new_category = seo_supplier_sync_new_products_category_id();
            if ( $new_category ) {
                $new_category_name = get_term_field( 'name', $new_category, 'product_cat' );
                if ( is_wp_error( $new_category_name ) || '' === (string) $new_category_name ) {
                    $new_category_name = 'Nuevos productos';
                }
                $category_destination = [
                    'term_id' => $new_category,
                    'name'    => (string) $new_category_name,
                    'source'  => 'nuevos_productos',
                    'score'   => 1,
                    'reason'  => 'alta_inicial',
                    'valid'   => true,
                ];
            }
        }

        if ( ! $is_update ) {
            $product->set_category_ids( [ absint( $category_destination['term_id'] ) ] );
        }

        $product_id = absint( $product->save() );

        if ( ! $product_id ) {
            return [
                'result'     => 'error',
                'product_id' => 0,
                'message'    => 'WooCommerce no devolvió un ID de producto.',
                'warnings'   => [],
            ];
        }

        /*
         * Guardado crítico inmediato. Se realiza antes de marcas, imágenes y
         * otras operaciones lentas para que cualquier reintento encuentre ya
         * el producto y nunca vuelva a darlo de alta.
         */
        update_post_meta( $product_id, '_seo_proveedor', sanitize_text_field( (string) ( $row['proveedor'] ?? '' ) ) );
        update_post_meta( $product_id, '_seo_proveedor_id_externo', sanitize_text_field( (string) ( $row['proveedor_id_externo'] ?? '' ) ) );
        update_post_meta( $product_id, '_seo_proveedor_catalogo_id', $catalog_row_id );

        if ( $catalog_row_id ) {
            $linked = $wpdb->update(
                $providers_table,
                [
                    'object_id'   => $product_id,
                    'actualizado' => current_time( 'mysql' ),
                ],
                [ 'id' => $catalog_row_id ],
                [ '%d', '%s' ],
                [ '%d' ]
            );

            if ( false === $linked ) {
                $warnings[] = 'El producto se creó, pero no se pudo guardar inmediatamente object_id en el catálogo intermedio.';
            }
        }

        update_post_meta( $product_id, '_seo_categoria_proveedor', sanitize_text_field( (string) ( $row['categoria_proveedor'] ?? '' ) ) );
        update_post_meta( $product_id, '_seo_precio_proveedor', wc_format_decimal( $base_price ) );
        update_post_meta( $product_id, '_seo_comision_descuento', wc_format_decimal( $comision_descuento ) );
        update_post_meta( $product_id, '_seo_comision_original', wc_format_decimal( $comision_original ) );

        /*
         * Instantánea completa de los datos del proveedor. Además de los campos
         * que tienen traducción directa a WooCommerce (nombre, descripción,
         * precio, stock, marca, categoría e imágenes), se conserva el resto de
         * la fila para que cambios como EAN/MPN, mínimos, moneda, IVA o URL no
         * se pierdan durante una actualización.
         */
        $supplier_snapshot_meta = [
            '_seo_proveedor_mpn'              => (string) ( $row['mpn'] ?? '' ),
            '_seo_proveedor_url_origen'       => (string) ( $row['url_origen'] ?? '' ),
            '_seo_proveedor_url_canonica'     => (string) ( $row['url_canonica'] ?? '' ),
            '_seo_proveedor_precio_sin_iva'   => (string) ( $row['precio_sin_iva'] ?? '' ),
            '_seo_proveedor_precio_con_iva'   => (string) ( $row['precio_con_iva'] ?? '' ),
            '_seo_proveedor_iva_porcentaje'   => (string) ( $row['iva_porcentaje'] ?? '' ),
            '_seo_proveedor_moneda'           => (string) ( $row['moneda'] ?? '' ),
            '_seo_proveedor_stock_estado'     => (string) ( $row['stock_estado'] ?? '' ),
            '_seo_proveedor_stock_cantidad'   => (string) ( $row['stock_cantidad'] ?? '' ),
            '_seo_proveedor_stock_texto'      => (string) ( $row['stock_texto'] ?? '' ),
            '_seo_proveedor_hash_producto'    => (string) ( $row['hash_producto'] ?? '' ),
            '_seo_proveedor_ultima_importacion' => (string) ( $row['ultima_importacion'] ?? '' ),
        ];

        foreach ( $supplier_snapshot_meta as $meta_key => $meta_value ) {
            update_post_meta( $product_id, $meta_key, $meta_value );
        }

        if ( ! $is_update ) {
            /* Datos de auditoria de la categoria aplicada en el alta inicial. */
            update_post_meta( $product_id, '_seo_categoria_asignada_id', absint( $category_destination['term_id'] ) );
            update_post_meta( $product_id, '_seo_categoria_asignada_nombre', sanitize_text_field( $category_destination['name'] ) );
            update_post_meta( $product_id, '_seo_categoria_asignada_origen', sanitize_key( $category_destination['source'] ) );
            update_post_meta( $product_id, '_seo_categoria_sugerida_confianza', (float) $category_destination['score'] );
            update_post_meta( $product_id, '_seo_categoria_sugerida_motivo', sanitize_key( $category_destination['reason'] ) );

            if ( 'sugerida' === $category_destination['source'] ) {
                seo_proveedores_guardar_mapeo_categoria(
                    (string) ( $row['proveedor'] ?? '' ),
                    (string) ( $row['categoria_proveedor'] ?? '' ),
                    absint( $category_destination['term_id'] )
                );
            }
        }

        $brand_result = seo_proveedores_asignar_marca(
            $product_id,
            (string) ( $row['marca'] ?? '' )
        );

        if ( is_wp_error( $brand_result ) ) {
            $warnings[] = 'Marca: ' . $brand_result->get_error_message();
            update_post_meta( $product_id, '_seo_marca_import_error', $brand_result->get_error_message() );
        } else {
            delete_post_meta( $product_id, '_seo_marca_import_error' );
        }

        if ( $imagenes_externas ) {
            /*
             * Modo externo: el producto WordPress queda sin imagen destacada ni
             * galeria local. Las URLs del proveedor se conservan en una tabla
             * independiente para que la plantilla pueda resolverlas despues.
             */
            $product->set_image_id( 0 );
            $product->set_gallery_image_ids( [] );
            $product->save();

            $external_result = seo_proveedores_guardar_imagenes_externas( $row, $product_id );

            if ( is_wp_error( $external_result ) ) {
                $warnings[] = 'Imágenes externas: ' . $external_result->get_error_message();
                update_post_meta( $product_id, '_seo_imagen_import_error', $external_result->get_error_message() );
            } else {
                delete_post_meta( $product_id, '_seo_imagen_import_error' );
                update_post_meta( $product_id, '_seo_imagenes_externas', 1 );
                update_post_meta( $product_id, '_seo_imagenes_externas_total', absint( $external_result['count'] ?? 0 ) );
            }
        } elseif ( '' !== trim( (string) ( $row['imagenes'] ?? '' ) ) ) {
            /*
             * Comportamiento original: descarga y sincroniza en WordPress.
             * Si anteriormente este producto usaba imagenes externas, se
             * desactivan para que la plantilla no mezcle ambos sistemas.
             */
            seo_proveedores_desactivar_imagenes_externas( $row, $product_id );

            $loader = seo_proveedores_cargar_gestor_imagenes();

            if ( is_wp_error( $loader ) ) {
                $warnings[] = 'Imágenes: ' . $loader->get_error_message();
                update_post_meta( $product_id, '_seo_imagen_import_error', $loader->get_error_message() );
            } else {
                $image_result = seo_images_sync_product_images(
                    (string) ( $row['proveedor'] ?? '' ),
                    (string) $row['imagenes'],
                    $product_id
                );

                if ( is_wp_error( $image_result ) ) {
                    $warnings[] = 'Imágenes: ' . $image_result->get_error_message();
                    update_post_meta( $product_id, '_seo_imagen_import_error', $image_result->get_error_message() );
                } elseif ( ! empty( $image_result['errors'] ) ) {
                    $warnings[] = 'Imágenes: ' . implode( ' | ', $image_result['errors'] );
                }
            }
        }

        // Un feed sin imagenes no elimina ni desactiva la galeria ya existente.

        clean_post_cache( $product_id );
        wc_delete_product_transients( $product_id );

        return [
            'result'     => $is_update ? 'updated' : 'created',
            'product_id' => $product_id,
            'message'    => $is_update
                ? 'Producto existente actualizado.'
                : 'Producto creado como borrador.',
            'warnings'   => $warnings,
            'category'   => $category_destination,
        ];
    } catch ( Throwable $e ) {
        return [
            'result'     => 'error',
            'product_id' => 0,
            'message'    => $e->getMessage(),
            'warnings'   => [],
        ];
    }
    } finally {
        seo_proveedores_liberar_bloqueo_alta( $lock_name );
    }
}


/**
 * Actualiza manualmente el estado de un producto del proveedor.
 *
 * Cuando se selecciona "Aceptado", crea o actualiza el borrador y aplica la
 * misma categoría propuesta que se muestra en el catálogo.
 *
 * @return void
 */
function seo_proveedores_actualizar_estado_catalogo() {
    if ( ! isset( $_POST['seo_prov_actualizar_estado'] ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permisos para modificar el catálogo.' );
    }

    check_admin_referer( 'seo_prov_actualizar_estado', 'seo_prov_estado_nonce' );

    $id     = absint( $_POST['producto_proveedor_id'] ?? 0 );
    $estado = sanitize_key( $_POST['estado_seleccion'] ?? '' );

    if ( ! $id || ! isset( seo_proveedores_estados_catalogo()[ $estado ] ) ) {
        wp_die( 'Estado o producto no válido.' );
    }

    global $wpdb;

    $table = seo_proveedores_tabla_productos();
    $row   = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $id
        ),
        ARRAY_A
    );

    if ( ! $row ) {
        wp_die( 'No se ha encontrado el producto del proveedor.' );
    }

    $notice = [];

    if ( 'aceptado' === $estado && empty( $row['object_id'] ) ) {
        /*
         * Aceptar una fila que todavía no tiene producto sí es una acción de
         * alta. Para productos ya enlazados, Selección es solo una decisión
         * comercial y nunca vuelve a ejecutar una actualización de WooCommerce.
         */
        $comision_descuento = seo_proveedores_normalizar_comision(
            $_POST['comision_descuento'] ?? 20,
            20
        );
        $comision_original = seo_proveedores_normalizar_comision(
            $_POST['comision_original'] ?? 45,
            45
        );
        $imagenes_externas = seo_proveedores_resolver_modo_imagenes_externas(
            $row,
            ! empty( $_POST['no_importar_imagenes'] )
        );

        $result = function_exists( 'seo_supplier_sync_apply_action' )
            ? seo_supplier_sync_apply_action( $row, 'aceptar', $comision_descuento, $comision_original, $imagenes_externas )
            : seo_proveedores_crear_borrador_desde_fila( $row, $comision_descuento, $comision_original, $imagenes_externas );

        if ( 'error' === sanitize_key( (string) ( $result['result'] ?? '' ) ) ) {
            wp_die( 'No se pudo aceptar el producto: ' . esc_html( (string) ( $result['message'] ?? 'Error desconocido.' ) ) );
        }

        $category = $result['category'] ?? [];
        $notice   = [
            'seo_single_result'          => sanitize_key( (string) ( $result['result'] ?? 'created' ) ),
            'seo_single_id'              => absint( $result['product_id'] ?? 0 ),
            'seo_single_category_id'     => absint( $category['term_id'] ?? 0 ),
            'seo_single_category_source' => sanitize_key( $category['source'] ?? '' ),
        ];
    } else {
        $updated = $wpdb->update(
            $table,
            [
                'estado_seleccion' => $estado,
                'actualizado'      => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        if ( false === $updated ) {
            wp_die( 'No se pudo actualizar el estado del producto.' );
        }
    }

    $redirect = wp_get_referer() ?: add_query_arg(
        [
            'page'       => 'seo-import-export',
            'seo_ie_tab' => 'catalogo-proveedores',
        ],
        admin_url( 'admin.php' )
    );

    if ( $notice ) {
        $redirect = add_query_arg( $notice, $redirect );
    }

    wp_safe_redirect( $redirect );
    exit;
}

/**
 * Cambia el estado de todos los productos que coinciden con los filtros.
 *
 * El filtro de categoría propuesta se traduce a las categorías originales del
 * proveedor antes de construir el SELECT, porque la propuesta es un valor
 * calculado y no una columna física de la tabla temporal.
 *
 * @return void
 */
function seo_proveedores_actualizar_estado_masivo() {
    if ( ! isset( $_POST['seo_prov_actualizar_estado_masivo'] ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permisos para modificar el catálogo.' );
    }

    check_admin_referer(
        'seo_prov_actualizar_estado_masivo',
        'seo_prov_estado_masivo_nonce'
    );

    $estados = seo_proveedores_estados_catalogo();
    $estado  = sanitize_key( $_POST['estado_masivo'] ?? '' );

    if ( ! isset( $estados[ $estado ] ) ) {
        wp_die( 'El estado seleccionado no es válido.' );
    }

    global $wpdb;

    $table = seo_proveedores_tabla_productos();

    $proveedor          = sanitize_text_field( wp_unslash( $_POST['f_proveedor'] ?? '' ) );
    $estado_filtro      = sanitize_key( $_POST['f_estado'] ?? '' );
    $buscar             = sanitize_text_field( wp_unslash( $_POST['f_buscar'] ?? '' ) );
    $categoria          = sanitize_text_field( wp_unslash( $_POST['f_categoria'] ?? '' ) );
    $categoria_sugerida = sanitize_text_field( wp_unslash( $_POST['f_categoria_sugerida'] ?? '' ) );
    $sku                 = sanitize_text_field( wp_unslash( $_POST['f_sku'] ?? '' ) );

    $precio_min = isset( $_POST['f_precio_min'] ) && '' !== (string) $_POST['f_precio_min']
        ? (float) str_replace( ',', '.', wp_unslash( $_POST['f_precio_min'] ) )
        : null;

    $precio_max = isset( $_POST['f_precio_max'] ) && '' !== (string) $_POST['f_precio_max']
        ? (float) str_replace( ',', '.', wp_unslash( $_POST['f_precio_max'] ) )
        : null;

    $comision_descuento = seo_proveedores_normalizar_comision(
        $_POST['comision_descuento'] ?? 20,
        20
    );

    $comision_original = seo_proveedores_normalizar_comision(
        $_POST['comision_original'] ?? 45,
        45
    );

    $imagenes_externas = ! empty( $_POST['no_importar_imagenes'] );

    $where  = [ '1=1' ];
    $params = [];

    if ( '' !== $proveedor ) {
        $where[]  = 'proveedor = %s';
        $params[] = $proveedor;
    }

    if ( '' !== $estado_filtro && isset( $estados[ $estado_filtro ] ) ) {
        $where[]  = 'estado_seleccion = %s';
        $params[] = $estado_filtro;
    }

    if ( '' !== $buscar ) {
        $like    = '%' . $wpdb->esc_like( $buscar ) . '%';
        $where[] = '(nombre LIKE %s OR descripcion LIKE %s OR marca LIKE %s)';
        array_push( $params, $like, $like, $like );
    }

    if ( '' !== $categoria ) {
        $where[]  = 'categoria_proveedor LIKE %s';
        $params[] = '%' . $wpdb->esc_like( $categoria ) . '%';
    }

    if ( '' !== $sku ) {
        $like    = '%' . $wpdb->esc_like( $sku ) . '%';
        $where[] = '(sku LIKE %s OR mpn LIKE %s OR proveedor_id_externo LIKE %s)';
        array_push( $params, $like, $like, $like );
    }

    if ( null !== $precio_min ) {
        $where[]  = 'COALESCE(precio_con_iva, precio_sin_iva) >= %f';
        $params[] = $precio_min;
    }

    if ( null !== $precio_max ) {
        $where[]  = 'COALESCE(precio_con_iva, precio_sin_iva) <= %f';
        $params[] = $precio_max;
    }

    /*
     * Calcular propuestas para todas las categorias puede ser costoso en
     * catalogos grandes. Solo se construye el mapa completo cuando el usuario
     * ha solicitado expresamente filtrar por categoria propuesta.
     */
    $suggestion_map = [];

    if ( '' !== $categoria_sugerida ) {
        $supplier_pairs = seo_proveedores_pares_categoria_proveedor_distintos( $table );
        $suggestion_map = seo_proveedores_mapa_sugerencias_categorias( $supplier_pairs );

        seo_proveedores_aplicar_filtro_categoria_sugerida(
            $categoria_sugerida,
            $suggestion_map,
            $where,
            $params
        );
    }

    /*
     * Si el usuario no ha filtrado expresamente por estado, una aceptación
     * masiva solo procesa filas pendientes o marcadas para revisión. De este
     * modo, refrescar o reenviar el formulario no vuelve a recorrer todo el
     * catálogo ya aceptado.
     */
    if ( 'aceptado' === $estado && '' === $estado_filtro ) {
        $where[] = "estado_seleccion IN ('pendiente', 'revisar')";
    }

    /*
     * La acción Actualizar solo trabaja con filas que la última importación
     * haya marcado expresamente como actualización pendiente. Así no se
     * reprocesan productos aceptados sin cambios por accidente.
     */
    if ( 'actualizar' === $estado ) {
        /*
         * Sin filtro de estado, Actualizar procesa únicamente las diferencias
         * detectadas por la última importación. Si el administrador ha elegido
         * explícitamente otro estado (por ejemplo Aceptado + CAMELION), se
         * interpreta como una actualización forzada de ese conjunto filtrado.
         * Esto permite recuperar catálogos importados antes de esta mejora sin
         * tocar estados manualmente ni ejecutar SQL.
         */
        /*
         * Si hay cualquier filtro explícito (proveedor, búsqueda, categoría,
         * SKU o precio), Actualizar procesa exactamente ese conjunto filtrado,
         * aunque sus filas sigan en estado Aceptado. Esto permite una
         * resincronización forzada de un proveedor completo (por ejemplo
         * CAMELION) sin tener que cambiar estados manualmente.
         *
         * Solo cuando NO hay ningún filtro se mantiene la protección anterior:
         * se procesan exclusivamente las filas que la importación haya marcado
         * como 'actualizar', evitando actualizar accidentalmente todo el catálogo.
         */
        $has_explicit_filter = (
            '' !== $proveedor
            || '' !== $estado_filtro
            || '' !== $buscar
            || '' !== $categoria
            || '' !== $categoria_sugerida
            || '' !== $sku
            || null !== $precio_min
            || null !== $precio_max
        );

        if ( ! $has_explicit_filter ) {
            $where[] = "estado_seleccion = 'actualizar'";
        }

        $where[] = 'object_id IS NOT NULL AND object_id > 0';
    }

    $where_sql = implode( ' AND ', $where );

    $created            = 0;
    $existing           = 0;
    $errors             = 0;
    $updated            = 0;
    $assigned_suggested = 0;
    $assigned_fallback  = 0;
    $external_synced_products = 0;
    $external_synced_urls     = 0;
    $external_without_source  = 0;
    $external_sync_errors     = 0;

    if ( in_array( $estado, [ 'aceptado', 'actualizar' ], true ) ) {
        /*
         * Solo puede existir una aceptación masiva activa. Se usa add_option
         * porque su clave única hace que la reserva sea atómica y no interfiere
         * con los bloqueos MySQL individuales de cada producto.
         */
        $bulk_lock_option = 'seo_proveedores_bulk_accept_lock';
        $bulk_lock_token  = wp_generate_uuid4();
        $bulk_lock_data   = [
            'token'   => $bulk_lock_token,
            'user_id' => get_current_user_id(),
            'started' => time(),
        ];
        $bulk_acquired    = add_option(
            $bulk_lock_option,
            $bulk_lock_data,
            '',
            false
        );

        if ( ! $bulk_acquired ) {
            $existing_bulk_lock = get_option( $bulk_lock_option, [] );
            $started_at         = absint( $existing_bulk_lock['started'] ?? 0 );

            // Libera reservas abandonadas durante más de cuatro horas.
            if ( $started_at && ( time() - $started_at ) > 4 * HOUR_IN_SECONDS ) {
                delete_option( $bulk_lock_option );
                $bulk_acquired = add_option(
                    $bulk_lock_option,
                    $bulk_lock_data,
                    '',
                    false
                );
            }
        }

        if ( ! $bulk_acquired ) {
            wp_die( 'Ya existe otra aceptación masiva en ejecución. Esta petición se ha cancelado para evitar reprocesados.' );
        }

        try {
        /* Catálogos grandes pueden necesitar más tiempo que una petición normal. */
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        $select_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id ASC";
        $rows       = $params
            ? $wpdb->get_results( $wpdb->prepare( $select_sql, $params ), ARRAY_A )
            : $wpdb->get_results( $select_sql, ARRAY_A );

        foreach ( $rows as $row ) {
            $row_imagenes_externas = seo_proveedores_resolver_modo_imagenes_externas(
                $row,
                $imagenes_externas
            );

            $result = seo_proveedores_crear_borrador_desde_fila(
                $row,
                $comision_descuento,
                $comision_original,
                $row_imagenes_externas
            );

            if ( 'error' === $result['result'] ) {
                $errors++;
                continue;
            }

            /*
             * Garantia de actualizacion de imagenes externas.
             *
             * seo_proveedores_crear_borrador_desde_fila() ya sincroniza las
             * imagenes en modo externo, pero en una actualizacion masiva se
             * repite aqui de forma idempotente y se valida el resultado antes
             * de devolver la fila a Aceptado. Asi una incidencia de imagenes no
             * puede quedar oculta como simple warning mientras el catalogo
             * aparenta estar actualizado.
             */
            if ( 'actualizar' === $estado && $row_imagenes_externas ) {
                $external_sync = seo_proveedores_guardar_imagenes_externas(
                    $row,
                    absint( $result['product_id'] )
                );

                if ( is_wp_error( $external_sync ) ) {
                    $external_sync_errors++;
                    $errors++;

                    // Si venia de Aceptado por una actualizacion forzada,
                    // dejarlo visible como pendiente de actualizacion.
                    $wpdb->update(
                        $table,
                        [
                            'estado_seleccion' => 'actualizar',
                            'actualizado'      => current_time( 'mysql' ),
                        ],
                        [ 'id' => absint( $row['id'] ) ],
                        [ '%s', '%s' ],
                        [ '%d' ]
                    );
                    continue;
                }

                $synced_count = absint( $external_sync['count'] ?? 0 );

                if ( $synced_count > 0 ) {
                    $external_synced_products++;
                    $external_synced_urls += $synced_count;
                    update_post_meta( absint( $result['product_id'] ), '_seo_imagenes_externas', 1 );
                    update_post_meta( absint( $result['product_id'] ), '_seo_imagenes_externas_total', $synced_count );
                } else {
                    $external_without_source++;
                }
            }

            $ok = $wpdb->update(
                $table,
                [
                    'estado_seleccion' => 'aceptado',
                    'object_id'        => absint( $result['product_id'] ),
                    'actualizado'      => current_time( 'mysql' ),
                ],
                [ 'id' => absint( $row['id'] ) ],
                [ '%s', '%d', '%s' ],
                [ '%d' ]
            );

            if ( false === $ok ) {
                $errors++;
                continue;
            }

            if ( function_exists( 'seo_supplier_sync_finalize_applied_row' ) ) {
                $row['object_id'] = absint( $result['product_id'] );
                seo_supplier_sync_finalize_applied_row( $row, absint( $result['product_id'] ) );
            }

            $updated++;

            if ( 'created' === $result['result'] ) {
                $created++;
            } else {
                $existing++;
            }

            $category_source = sanitize_key(
                $result['category']['source'] ?? ''
            );

            if ( in_array( $category_source, [ 'sugerida', 'memoria' ], true ) ) {
                $assigned_suggested++;
            } elseif ( 'respaldo' === $category_source ) {
                $assigned_fallback++;
            }
        }
        } finally {
            $current_bulk_lock = get_option( $bulk_lock_option, [] );

            if ( isset( $current_bulk_lock['token'] ) && hash_equals( (string) $current_bulk_lock['token'], $bulk_lock_token ) ) {
                delete_option( $bulk_lock_option );
            }
        }
    } else {
        $sql          = "UPDATE {$table} SET estado_seleccion = %s, actualizado = %s WHERE {$where_sql}";
        $query_params = array_merge(
            [ $estado, current_time( 'mysql' ) ],
            $params
        );

        $updated = $wpdb->query(
            $wpdb->prepare( $sql, $query_params )
        );

        if ( false === $updated ) {
            wp_die( 'No se pudo actualizar el estado de los productos filtrados.' );
        }
    }

    $redirect = esc_url_raw( wp_unslash( $_POST['return_url'] ?? '' ) );

    if ( ! $redirect ) {
        $redirect = add_query_arg(
            [
                'page'       => 'seo-import-export',
                'seo_ie_tab' => 'catalogo-proveedores',
            ],
            admin_url( 'admin.php' )
        );
    }

    $redirect = add_query_arg(
        [
            'seo_bulk_updated'            => absint( $updated ),
            'seo_bulk_estado'             => $estado,
            'seo_bulk_created'            => absint( $created ),
            'seo_bulk_existing'           => absint( $existing ),
            'seo_bulk_errors'             => absint( $errors ),
            'seo_bulk_category_suggested' => absint( $assigned_suggested ),
            'seo_bulk_category_fallback'  => absint( $assigned_fallback ),
            'seo_bulk_external_products'   => absint( $external_synced_products ),
            'seo_bulk_external_urls'       => absint( $external_synced_urls ),
            'seo_bulk_external_empty'      => absint( $external_without_source ),
            'seo_bulk_external_errors'     => absint( $external_sync_errors ),
        ],
        $redirect
    );

    wp_safe_redirect( $redirect );
    exit;
}


/**
 * Renderiza el catálogo de productos de proveedores.
 *
 * Incluye:
 * - filtros y columnas configurables;
 * - categoría original del proveedor;
 * - categoría WooCommerce propuesta y nivel de confianza;
 * - aceptación individual o masiva usando la propuesta;
 * - categoría de respaldo para coincidencias débiles o ambiguas;
 * - comisiones y vista previa de precios.
 *
 * Las propuestas se calculan por cada categoría distinta del proveedor. Un
 * catálogo de 5.000 productos agrupado en 30 categorías necesita 30 cálculos,
 * no 5.000 búsquedas completas.
 *
 * Version: 2026-07-28
 * Build: 003
 *
 * @return void
 */
function seo_proveedores_render_catalogo() {
    global $wpdb;

    $table  = seo_proveedores_tabla_productos();
    $exists = $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $table
        )
    );

    if ( $exists !== $table ) {
        echo '<div class="notice notice-error"><p>No existe la tabla de productos de proveedores.</p></div>';
        return;
    }

    /*
     * Configuración de precios.
     */
    $comision_descuento = isset( $_GET['comision_descuento'] )
        ? (float) str_replace(
            ',',
            '.',
            sanitize_text_field( wp_unslash( $_GET['comision_descuento'] ) )
        )
        : 20.00;

    $comision_original = isset( $_GET['comision_original'] )
        ? (float) str_replace(
            ',',
            '.',
            sanitize_text_field( wp_unslash( $_GET['comision_original'] ) )
        )
        : 45.00;

    $comision_descuento = max( 0, $comision_descuento );
    $comision_original  = max( 0, $comision_original );

    /*
     * Estrategia visual del catálogo.
     * Por ahora conserva la selección en la URL/formulario. La lógica de alta
     * puede reutilizar este valor para desviar las imágenes a la tabla externa.
     */
    $no_importar_imagenes = ! empty( $_GET['no_importar_imagenes'] );

    if ( $comision_original < $comision_descuento ) {
        $comision_original = $comision_descuento;
    }

    /*
     * Índice de propuestas.
     *
     * Se construye antes de aplicar el filtro de categoría propuesta porque
     * ese filtro debe traducirse a valores reales de categoria_proveedor.
     */
    /*
     * Modo de catalogo rapido.
     *
     * VEVOR contiene miles de rutas de categoria distintas. Calcular las
     * propuestas para todo el catalogo antes de ejecutar el LIMIT de la tabla
     * puede agotar el tiempo del hosting. En la carga inicial solo obtenemos
     * las categorias para el datalist. Las propuestas se calculan mas abajo,
     * exclusivamente para las filas de la pagina visible.
     */
    $supplier_pairs      = seo_proveedores_pares_categoria_proveedor_distintos( $table );
    $supplier_categories = seo_proveedores_categorias_proveedor_desde_pares( $supplier_pairs );
    $suggestion_map      = [];
    $fallback_category   = seo_proveedores_categoria_respaldo();

    /* El selector muestra las categorias WooCommerce disponibles sin evaluar
     * de antemano las 1.000+ categorias del proveedor. */
    $suggested_filter_terms = [];

    foreach ( seo_proveedores_indice_categorias_woocommerce() as $candidate ) {
        $term_id = absint( $candidate['term_id'] ?? 0 );

        if ( $term_id > 0 ) {
            $suggested_filter_terms[ $term_id ] = (string) ( $candidate['name'] ?? '' );
        }
    }

    asort( $suggested_filter_terms, SORT_NATURAL | SORT_FLAG_CASE );

    /*
     * Columnas visibles.
     */
    $available       = seo_proveedores_columnas_catalogo();
    $virtual_columns = seo_proveedores_columnas_virtuales_catalogo();
    $database_columns = array_values(
        array_diff(
            array_keys( $available ),
            $virtual_columns
        )
    );

    $defaults = [
        'proveedor',
        'proveedor_id_externo',
        'sku',
        'nombre',
        'categoria_proveedor',
        'categoria_sugerida',
        'confianza_categoria',
        'precio_con_iva',
        'estado_seleccion',
        'estado_sincronizacion',
        'cambios',
        'acciones_sync',
        'ultima_importacion',
    ];

    $requested_columns = isset( $_GET['cols'] )
        ? array_map(
            'sanitize_key',
            (array) wp_unslash( $_GET['cols'] )
        )
        : $defaults;

    $columns = array_values(
        array_intersect(
            array_keys( $available ),
            $requested_columns
        )
    );

    if ( empty( $columns ) ) {
        $columns = $defaults;
    }

    /*
     * Filtros del catálogo.
     */
    $proveedor = sanitize_text_field(
        wp_unslash( $_GET['f_proveedor'] ?? '' )
    );

    $estado = sanitize_key(
        $_GET['f_estado'] ?? ''
    );

    $situacion = sanitize_key(
        $_GET['f_situacion'] ?? ''
    );

    $cambio = sanitize_key(
        $_GET['f_cambio'] ?? ''
    );

    $buscar = sanitize_text_field(
        wp_unslash( $_GET['f_buscar'] ?? '' )
    );

    $categoria = sanitize_text_field(
        wp_unslash( $_GET['f_categoria'] ?? '' )
    );

    $categoria_sugerida = sanitize_text_field(
        wp_unslash( $_GET['f_categoria_sugerida'] ?? '' )
    );

    $sku = sanitize_text_field(
        wp_unslash( $_GET['f_sku'] ?? '' )
    );

    $precio_min = isset( $_GET['f_precio_min'] ) && '' !== (string) $_GET['f_precio_min']
        ? (float) str_replace( ',', '.', wp_unslash( $_GET['f_precio_min'] ) )
        : null;

    $precio_max = isset( $_GET['f_precio_max'] ) && '' !== (string) $_GET['f_precio_max']
        ? (float) str_replace( ',', '.', wp_unslash( $_GET['f_precio_max'] ) )
        : null;

    $where  = [ '1=1' ];
    $params = [];

    if ( '' !== $proveedor ) {
        $where[]  = 'proveedor = %s';
        $params[] = $proveedor;
    }

    if (
        '' !== $estado
        && isset( seo_proveedores_estados_catalogo()[ $estado ] )
    ) {
        $where[]  = 'estado_seleccion = %s';
        $params[] = $estado;
    }

    if (
        '' !== $situacion
        && isset( seo_proveedores_situaciones_catalogo()[ $situacion ] )
    ) {
        $where[]  = 'estado_sincronizacion = %s';
        $params[] = $situacion;
    }

    if ( '' !== $cambio && isset( seo_supplier_sync_fields()[ $cambio ] ) ) {
        $where[]  = 'cambios_detectados LIKE %s';
        $params[] = '%|' . $wpdb->esc_like( $cambio ) . '|%';
    }

    if ( '' !== $buscar ) {
        $like = '%' . $wpdb->esc_like( $buscar ) . '%';

        $where[] = '
            (
                nombre LIKE %s
                OR descripcion LIKE %s
                OR marca LIKE %s
            )
        ';

        array_push( $params, $like, $like, $like );
    }

    if ( '' !== $categoria ) {
        $where[]  = 'categoria_proveedor LIKE %s';
        $params[] = '%' . $wpdb->esc_like( $categoria ) . '%';
    }

    if ( '' !== $sku ) {
        $like = '%' . $wpdb->esc_like( $sku ) . '%';

        $where[] = '
            (
                sku LIKE %s
                OR mpn LIKE %s
                OR proveedor_id_externo LIKE %s
            )
        ';

        array_push( $params, $like, $like, $like );
    }

    if ( null !== $precio_min ) {
        $where[]  = 'COALESCE(precio_con_iva, precio_sin_iva) >= %f';
        $params[] = $precio_min;
    }

    if ( null !== $precio_max ) {
        $where[]  = 'COALESCE(precio_con_iva, precio_sin_iva) <= %f';
        $params[] = $precio_max;
    }

    $category_filter_notice = '';

    if ( '' !== $categoria_sugerida ) {
        /*
         * Este filtro requiere conocer la propuesta de todas las categorias.
         * Para evitar otra caida del hosting, se permite automaticamente en
         * catalogos pequenos. En catalogos grandes se pide acotar primero por
         * proveedor o categoria original y se mantiene la pagina operativa.
         */
        if ( count( $supplier_pairs ) <= 300 ) {
            $suggestion_map = seo_proveedores_mapa_sugerencias_categorias( $supplier_pairs );
            seo_proveedores_aplicar_filtro_categoria_sugerida(
                $categoria_sugerida,
                $suggestion_map,
                $where,
                $params
            );
        } else {
            $category_filter_notice = 'El filtro por categoria propuesta no se ha aplicado porque el catalogo tiene demasiadas categorias distintas. Filtra primero por proveedor o categoria original.';
            $categoria_sugerida = '';
        }
    }

    /*
     * Ordenación y paginación.
     * Las columnas calculadas no pueden ordenarse mediante SQL.
     */
    $sortable = $database_columns;

    $orderby = sanitize_key(
        $_GET['orderby'] ?? 'ultima_importacion'
    );

    if ( ! in_array( $orderby, $sortable, true ) ) {
        $orderby = 'ultima_importacion';
    }

    $order = strtoupper(
        sanitize_text_field( $_GET['order'] ?? 'DESC' )
    );

    $order = 'ASC' === $order ? 'ASC' : 'DESC';

    $per_page = absint(
        $_GET['per_page'] ?? 50
    );

    if ( ! in_array( $per_page, [ 25, 50, 100, 200 ], true ) ) {
        $per_page = 50;
    }

    $page_num = max(
        1,
        absint( $_GET['paged'] ?? 1 )
    );

    $offset    = ( $page_num - 1 ) * $per_page;
    $where_sql = implode( ' AND ', $where );

    $count_sql = "
        SELECT COUNT(*)
        FROM {$table}
        WHERE {$where_sql}
    ";

    $total = (int) (
        $params
            ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
            : $wpdb->get_var( $count_sql )
    );

    /*
     * Se incluyen siempre los campos auxiliares necesarios para precios,
     * estados y sugerencias, aunque el usuario no los muestre como columnas.
     */
    $selected_database_columns = array_values(
        array_intersect(
            $columns,
            $database_columns
        )
    );

    $select = array_unique(
        array_merge(
            [
                'id',
                'proveedor',
                'estado_seleccion',
                'estado_sincronizacion',
                'cambios_detectados',
                'snapshot_aplicado',
                'hash_producto',
                'hash_aplicado',
                'object_id',
                'modo_imagenes',
                'last_seen_run_id',
                'ultimo_error_sync',
                'precio_con_iva',
                'precio_sin_iva',
                'categoria_proveedor',
                'proveedor_id_externo',
                'sku',
                'mpn',
                'nombre',
                'marca',
                'precio_sin_iva',
                'precio_con_iva',
                'iva_porcentaje',
                'moneda',
                'stock_estado',
                'stock_cantidad',
                'stock_texto',
                'imagenes',
                'url_origen',
                'url_canonica',
            ],
            $selected_database_columns
        )
    );

    $select_sql = implode(
        ', ',
        array_map(
            static function ( $column ) {
                return '`' . esc_sql( $column ) . '`';
            },
            $select
        )
    );

    $query = "
        SELECT {$select_sql}
        FROM {$table}
        WHERE {$where_sql}
        ORDER BY `{$orderby}` {$order}
        LIMIT %d
        OFFSET %d
    ";

    $query_params = array_merge(
        $params,
        [ $per_page, $offset ]
    );

    $rows = $wpdb->get_results(
        $wpdb->prepare( $query, $query_params ),
        ARRAY_A
    );

    $catalog_sql_error = (string) $wpdb->last_error;

    $providers = $wpdb->get_col(
        "
        SELECT DISTINCT proveedor
        FROM {$table}
        WHERE proveedor <> ''
        ORDER BY proveedor
        "
    );

    /*
     * Calcula propuestas solo para las categorias presentes en la pagina
     * actual. Con 50 filas por defecto, el coste queda acotado aunque el
     * catalogo completo tenga miles de categorias.
     */
    if ( empty( $suggestion_map ) && ! empty( $rows ) ) {
        $page_pairs = [];

        foreach ( $rows as $catalog_row ) {
            $provider_key = (string) ( $catalog_row['proveedor'] ?? '' );
            $category_key = (string) ( $catalog_row['categoria_proveedor'] ?? '' );
            $pair_key     = seo_proveedores_clave_mapa_sugerencia( $provider_key, $category_key );

            $page_pairs[ $pair_key ] = [
                'provider'          => $provider_key,
                'supplier_category' => $category_key,
            ];
        }

        $suggestion_map = seo_proveedores_mapa_sugerencias_categorias( array_values( $page_pairs ) );
    }

    $total_pages = max(
        1,
        (int) ceil( $total / $per_page )
    );

    $notice_query_args = [
        'seo_bulk_updated',
        'seo_bulk_estado',
        'seo_bulk_created',
        'seo_bulk_existing',
        'seo_bulk_errors',
        'seo_bulk_category_suggested',
        'seo_bulk_category_fallback',
        'seo_single_result',
        'seo_single_id',
        'seo_single_category_id',
        'seo_single_category_source',
        'seo_sync_action_result',
        'seo_sync_action_id',
        'seo_sync_action_msg',
        'seo_sync_bulk_action',
        'seo_sync_bulk_processed',
        'seo_sync_bulk_errors',
    ];
    ?>

    <div class="card" style="max-width:none;padding:20px;margin-top:20px;">

        <h2>Catálogo de proveedores</h2>
        <p><code>Catalogo rapido Build 005 · actualizador de proveedores</code></p>

        <?php if ( '' !== $catalog_sql_error ) : ?>
            <div class="notice notice-error inline">
                <p><strong>Error SQL al cargar el catalogo:</strong> <?php echo esc_html( $catalog_sql_error ); ?></p>
            </div>
        <?php endif; ?>

        <?php if ( '' !== $category_filter_notice ) : ?>
            <div class="notice notice-warning inline">
                <p><?php echo esc_html( $category_filter_notice ); ?></p>
            </div>
        <?php endif; ?>

        <div class="notice notice-info inline" style="margin:15px 0;padding:14px;">
            <p style="margin-top:0;">
                <strong>Asistente de categorías</strong>
            </p>

            <p>
                La categoría propuesta se calcula comparando únicamente el título
                que trae el proveedor con los títulos de las categorías de
                WooCommerce. Se ignoran mayúsculas, acentos, artículos y variantes
                habituales de singular y plural; también se admiten coincidencias
                parciales.
            </p>

            <p>
                No se crean categorías nuevas. Si la coincidencia es débil o hay
                varias alternativas prácticamente empatadas, se muestra
                <strong>Sin propuesta clara</strong>.
            </p>

            <p>
                Cuando aceptas una propuesta, la equivalencia se recuerda para
                ese proveedor y esa categoría. Las siguientes importaciones usan
                primero la decisión ya validada y solo calculan similitud cuando
                todavía no existe una equivalencia.
            </p>

            <p style="margin-bottom:0;">
                Categoría utilizada como respaldo:
                <strong>
                    <?php echo $fallback_category['valid']
                        ? esc_html( $fallback_category['name'] )
                        : 'No configurada'; ?>
                </strong>.
                Los productos aceptados continúan siendo borradores ocultos.
            </p>
        </div>

        <?php if ( ! $fallback_category['valid'] ) : ?>
            <div class="notice notice-error inline">
                <p>
                    No se ha encontrado una categoría predeterminada válida de
                    WooCommerce. Configúrala antes de aceptar productos sin una
                    propuesta clara.
                </p>
            </div>
        <?php endif; ?>

        <div class="notice notice-info inline" style="margin:15px 0;padding:14px;">
            <p style="margin-top:0;"><strong>Importaciones recurrentes de proveedores</strong></p>
            <p style="margin-bottom:0;">
                Cada CSV actualiza primero el catálogo intermedio y calcula la <strong>Situación</strong>:
                Nuevo, Modificado, Sin cambios, Baja, Reactivado o Conflicto. La <strong>Selección</strong>
                comercial se conserva por separado. Usa los filtros y las acciones por bloques para decidir
                qué aplicar a WooCommerce. Al actualizar un producto existente se conservan descripción,
                excerpt, categoría definitiva, atributos, etiquetas, taxonomías SEO y slug.
            </p>
        </div>

        <div class="notice notice-info inline" style="margin:15px 0;padding:14px;">
            <p style="margin-top:0;">
                <strong>Configuración de precios al aceptar productos</strong>
            </p>

            <p>
                Se utiliza primero el precio con IVA y, si está vacío, el precio
                sin IVA.
            </p>

            <p style="margin-bottom:0;">
                <strong>Precio con descuento:</strong>
                precio proveedor + comisión de descuento.
                <br>
                <strong>Precio original:</strong>
                precio proveedor + comisión original.
            </p>
        </div>

        <?php if ( isset( $_GET['seo_single_result'] ) ) : ?>
            <?php
            $single_product_id = absint( $_GET['seo_single_id'] ?? 0 );
            $single_category_id = absint( $_GET['seo_single_category_id'] ?? 0 );
            $single_source = sanitize_key( $_GET['seo_single_category_source'] ?? '' );
            $single_category = $single_category_id
                ? get_term( $single_category_id, 'product_cat' )
                : null;
            ?>

            <div class="notice notice-success inline">
                <p>
                    Producto
                    <strong>#<?php echo esc_html( $single_product_id ); ?></strong>
                    creado o actualizado como borrador.

                    <?php if ( $single_category && ! is_wp_error( $single_category ) ) : ?>
                        Categoría asignada:
                        <strong><?php echo esc_html( $single_category->name ); ?></strong>
                        (<?php
                        echo 'memoria' === $single_source
                            ? 'equivalencia recordada'
                            : ( 'sugerida' === $single_source ? 'propuesta automática' : 'respaldo' );
                        ?>).
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if ( isset( $_GET['seo_bulk_updated'] ) ) : ?>
            <?php
            $bulk_updated = absint( $_GET['seo_bulk_updated'] );
            $bulk_estado  = sanitize_key( $_GET['seo_bulk_estado'] ?? '' );
            $bulk_label   = seo_proveedores_estados_catalogo()[ $bulk_estado ] ?? $bulk_estado;
            ?>

            <div class="notice notice-success inline">
                <p>
                    <strong><?php echo esc_html( number_format_i18n( $bulk_updated ) ); ?></strong>
                    registros procesados como
                    <strong><?php echo esc_html( $bulk_label ); ?></strong>.

                    <?php if ( in_array( $bulk_estado, [ 'aceptado', 'actualizar' ], true ) ) : ?>
                        Creados como borrador:
                        <strong><?php echo esc_html( number_format_i18n( absint( $_GET['seo_bulk_created'] ?? 0 ) ) ); ?></strong>.

                        Actualizados o ya existentes:
                        <strong><?php echo esc_html( number_format_i18n( absint( $_GET['seo_bulk_existing'] ?? 0 ) ) ); ?></strong>.

                        Con categoría propuesta:
                        <strong><?php echo esc_html( number_format_i18n( absint( $_GET['seo_bulk_category_suggested'] ?? 0 ) ) ); ?></strong>.

                        En categoría de respaldo:
                        <strong><?php echo esc_html( number_format_i18n( absint( $_GET['seo_bulk_category_fallback'] ?? 0 ) ) ); ?></strong>.

                        Errores:
                        <strong><?php echo esc_html( number_format_i18n( absint( $_GET['seo_bulk_errors'] ?? 0 ) ) ); ?></strong>.

                        <?php if ( 'actualizar' === $bulk_estado ) : ?>
                            Imágenes externas sincronizadas en:
                            <strong><?php echo esc_html( number_format_i18n( absint( $_GET['seo_bulk_external_products'] ?? 0 ) ) ); ?></strong>
                            productos / <strong><?php echo esc_html( number_format_i18n( absint( $_GET['seo_bulk_external_urls'] ?? 0 ) ) ); ?></strong> URLs.
                            Sin URLs nuevas en proveedor:
                            <strong><?php echo esc_html( number_format_i18n( absint( $_GET['seo_bulk_external_empty'] ?? 0 ) ) ); ?></strong>.
                            Errores de sincronización externa:
                            <strong><?php echo esc_html( number_format_i18n( absint( $_GET['seo_bulk_external_errors'] ?? 0 ) ) ); ?></strong>.
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if ( isset( $_GET['seo_sync_action_result'] ) ) : ?>
            <?php
            $sync_result = sanitize_key( $_GET['seo_sync_action_result'] );
            $sync_id = absint( $_GET['seo_sync_action_id'] ?? 0 );
            $sync_msg = sanitize_text_field( rawurldecode( (string) ( $_GET['seo_sync_action_msg'] ?? '' ) ) );
            ?>
            <div class="notice <?php echo 'error' === $sync_result ? 'notice-error' : 'notice-success'; ?> inline">
                <p>
                    <?php if ( $sync_id ) : ?><strong>Producto #<?php echo esc_html( $sync_id ); ?>.</strong> <?php endif; ?>
                    <?php echo esc_html( $sync_msg ); ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if ( isset( $_GET['seo_sync_bulk_action'] ) ) : ?>
            <div class="notice notice-success inline">
                <p>
                    Accion <strong><?php echo esc_html( sanitize_key( $_GET['seo_sync_bulk_action'] ) ); ?></strong>:
                    <strong><?php echo esc_html( number_format_i18n( absint( $_GET['seo_sync_bulk_processed'] ?? 0 ) ) ); ?></strong> procesados.
                    Errores: <strong><?php echo esc_html( number_format_i18n( absint( $_GET['seo_sync_bulk_errors'] ?? 0 ) ) ); ?></strong>.
                </p>
            </div>
        <?php endif; ?>

        <p>
            <strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong>
            productos coinciden con los filtros.
        </p>

        <form method="get">
            <input type="hidden" name="page" value="seo-import-export">
            <input type="hidden" name="seo_ie_tab" value="catalogo-proveedores">

            <div style="padding:15px;margin-bottom:20px;border:1px solid #c3c4c7;background:#f6f7f7;">
                <label for="seo-no-importar-imagenes" style="display:flex;align-items:center;gap:8px;font-weight:600;">
                    <input
                        id="seo-no-importar-imagenes"
                        type="checkbox"
                        name="no_importar_imagenes"
                        value="1"
                        <?php checked( $no_importar_imagenes ); ?>
                    >
                    Usar imágenes externas (no descargarlas a WordPress)
                </label>

                <details style="margin-top:8px;">
                    <summary style="cursor:pointer;">Más información</summary>
                    <p class="description" style="margin:8px 0 0;max-width:900px;">
                        Actualizar sincroniza solo los datos controlados por el proveedor: titulo, SKU/MPN, marca, precios, stock, imagenes y URLs de origen. Conserva descripcion, excerpt, categoria definitiva, atributos, etiquetas, taxonomias SEO y slug. Esta casilla decide si las imagenes se mantienen como externas o se descargan a WordPress.
                    </p>
                </details>
            </div>

            <h3>Comisiones de venta</h3>

            <div style="display:flex;flex-wrap:wrap;gap:18px;align-items:flex-end;padding:15px;margin-bottom:20px;border:1px solid #c3c4c7;background:#f6f7f7;">
                <label>
                    <strong>Precio con descuento</strong>
                    <br>
                    <input
                        id="seo-comision-descuento"
                        type="number"
                        name="comision_descuento"
                        min="0"
                        max="1000"
                        step="0.01"
                        value="<?php echo esc_attr( $comision_descuento ); ?>"
                        style="width:110px;"
                    > %
                </label>

                <label>
                    <strong>Precio original</strong>
                    <br>
                    <input
                        id="seo-comision-original"
                        type="number"
                        name="comision_original"
                        min="0"
                        max="1000"
                        step="0.01"
                        value="<?php echo esc_attr( $comision_original ); ?>"
                        style="width:110px;"
                    > %
                </label>

                <div>
                    <strong>Ejemplo con precio proveedor de 100,00 €</strong>
                    <br>
                    Precio rebajado:
                    <strong id="seo-ejemplo-precio-descuento">
                        <?php echo esc_html( number_format_i18n( 100 * ( 1 + ( $comision_descuento / 100 ) ), 2 ) ); ?> €
                    </strong>
                    · Precio original:
                    <strong id="seo-ejemplo-precio-original">
                        <?php echo esc_html( number_format_i18n( 100 * ( 1 + ( $comision_original / 100 ) ), 2 ) ); ?> €
                    </strong>
                </div>
            </div>

            <h3>Columnas visibles</h3>

            <div style="display:flex;flex-wrap:wrap;gap:8px 18px;margin-bottom:16px;">
                <?php foreach ( $available as $key => $label ) : ?>
                    <label>
                        <input
                            type="checkbox"
                            name="cols[]"
                            value="<?php echo esc_attr( $key ); ?>"
                            <?php checked( in_array( $key, $columns, true ) ); ?>
                        >
                        <?php echo esc_html( $label ); ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <h3>Filtros</h3>

            <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
                <label>
                    Proveedor
                    <br>
                    <select name="f_proveedor">
                        <option value="">Todos</option>
                        <?php foreach ( $providers as $item ) : ?>
                            <option value="<?php echo esc_attr( $item ); ?>" <?php selected( $proveedor, $item ); ?>>
                                <?php echo esc_html( $item ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Seleccion
                    <br>
                    <select name="f_estado">
                        <option value="">Todos</option>
                        <?php foreach ( seo_proveedores_estados_catalogo() as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $estado, $key ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Situacion
                    <br>
                    <select name="f_situacion">
                        <option value="">Todas</option>
                        <?php foreach ( seo_proveedores_situaciones_catalogo() as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $situacion, $key ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Cambio detectado
                    <br>
                    <select name="f_cambio">
                        <option value="">Cualquier campo</option>
                        <?php foreach ( seo_supplier_sync_fields() as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $cambio, $key ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Nombre, descripción o marca
                    <br>
                    <input type="search" name="f_buscar" value="<?php echo esc_attr( $buscar ); ?>">
                </label>

                <label>
                    SKU, MPN o código
                    <br>
                    <input type="search" name="f_sku" value="<?php echo esc_attr( $sku ); ?>">
                </label>

                <label>
                    Categoría del proveedor contiene
                    <br>
                    <input
                        type="search"
                        name="f_categoria"
                        list="seo-categorias-proveedor"
                        value="<?php echo esc_attr( $categoria ); ?>"
                    >
                    <datalist id="seo-categorias-proveedor">
                        <?php foreach ( $supplier_categories as $supplier_category ) : ?>
                            <?php if ( '' !== trim( $supplier_category ) ) : ?>
                                <option value="<?php echo esc_attr( $supplier_category ); ?>"></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </datalist>
                </label>

                <label>
                    Categoría propuesta
                    <br>
                    <select name="f_categoria_sugerida">
                        <option value="">Todas</option>
                        <option value="none" <?php selected( $categoria_sugerida, 'none' ); ?>>
                            Sin propuesta clara
                        </option>
                        <?php foreach ( $suggested_filter_terms as $term_id => $term_name ) : ?>
                            <option value="<?php echo esc_attr( $term_id ); ?>" <?php selected( $categoria_sugerida, (string) $term_id ); ?>>
                                <?php echo esc_html( $term_name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Precio mínimo
                    <br>
                    <input
                        type="number"
                        step="0.01"
                        name="f_precio_min"
                        value="<?php echo esc_attr( null === $precio_min ? '' : $precio_min ); ?>"
                        style="width:110px;"
                    >
                </label>

                <label>
                    Precio máximo
                    <br>
                    <input
                        type="number"
                        step="0.01"
                        name="f_precio_max"
                        value="<?php echo esc_attr( null === $precio_max ? '' : $precio_max ); ?>"
                        style="width:110px;"
                    >
                </label>

                <label>
                    Por página
                    <br>
                    <select name="per_page">
                        <?php foreach ( [ 25, 50, 100, 200 ] as $size ) : ?>
                            <option value="<?php echo esc_attr( $size ); ?>" <?php selected( $per_page, $size ); ?>>
                                <?php echo esc_html( $size ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <button class="button button-primary">Aplicar</button>

                <a
                    class="button"
                    href="<?php echo esc_url( add_query_arg( [ 'page' => 'seo-import-export', 'seo_ie_tab' => 'catalogo-proveedores' ], admin_url( 'admin.php' ) ) ); ?>"
                >
                    Limpiar
                </a>
            </div>
        </form>

        <?php if ( $total > 0 ) : ?>
            <hr style="margin:22px 0 18px;">

            <h3 style="margin-bottom:6px;">Acción sobre todos los resultados filtrados</h3>

            <p style="margin-top:0;">
                El cambio afectará a los
                <strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong>
                productos que coinciden con los filtros, no solo a los de esta pagina.
                Cada accion aplica ademas sus propias reglas de seguridad: Aceptar solo
                actua sobre Nuevos, Actualizar sobre Modificados, Baja sobre Bajas y
                Reactivar sobre Reactivados.
            </p>

            <form
                method="post"
                style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;"
                onsubmit="return confirm('Se procesarán <?php echo esc_js( number_format_i18n( $total ) ); ?> productos. ¿Continuar?');"
            >
                <?php wp_nonce_field( 'seo_supplier_sync_bulk_action', 'seo_supplier_sync_bulk_nonce' ); ?>

                <input type="hidden" name="f_proveedor" value="<?php echo esc_attr( $proveedor ); ?>">
                <input type="hidden" name="f_estado" value="<?php echo esc_attr( $estado ); ?>">
                <input type="hidden" name="f_situacion" value="<?php echo esc_attr( $situacion ); ?>">
                <input type="hidden" name="f_cambio" value="<?php echo esc_attr( $cambio ); ?>">
                <input type="hidden" name="f_buscar" value="<?php echo esc_attr( $buscar ); ?>">
                <input type="hidden" name="f_sku" value="<?php echo esc_attr( $sku ); ?>">
                <input type="hidden" name="f_categoria" value="<?php echo esc_attr( $categoria ); ?>">
                <input type="hidden" name="f_categoria_sugerida" value="<?php echo esc_attr( $categoria_sugerida ); ?>">
                <input type="hidden" name="f_precio_min" value="<?php echo esc_attr( null === $precio_min ? '' : $precio_min ); ?>">
                <input type="hidden" name="f_precio_max" value="<?php echo esc_attr( null === $precio_max ? '' : $precio_max ); ?>">
                <input type="hidden" name="comision_descuento" value="<?php echo esc_attr( $comision_descuento ); ?>">
                <input type="hidden" name="comision_original" value="<?php echo esc_attr( $comision_original ); ?>">
                <label style="display:flex;align-items:center;gap:6px;">
                    <input
                        type="checkbox"
                        name="no_importar_imagenes"
                        value="1"
                        <?php checked( $no_importar_imagenes ); ?>
                    >
                    <strong>Sincronizar como imágenes externas</strong>
                </label>
                <input
                    type="hidden"
                    name="return_url"
                    value="<?php echo esc_url( remove_query_arg( $notice_query_args ) ); ?>"
                >

                <label for="seo-accion-masiva"><strong>Accion:</strong></label>

                <select id="seo-accion-masiva" name="accion_masiva">
                    <option value="actualizar">Actualizar productos modificados</option>
                    <option value="aceptar">Aceptar productos nuevos</option>
                    <option value="descartar">Descartar productos nuevos</option>
                    <option value="baja">Aplicar bajas</option>
                    <option value="reactivar">Reactivar productos</option>
                </select>

                <button
                    type="submit"
                    class="button button-primary"
                    name="seo_supplier_sync_bulk_action"
                    value="1"
                >
                    Aplicar a resultados compatibles
                </button>
            </form>
        <?php endif; ?>
    </div>

    <div style="overflow:auto;margin-top:16px;">
        <table class="widefat striped">
            <thead>
                <tr>
                    <?php foreach ( $columns as $column ) : ?>
                        <th>
                            <?php if ( in_array( $column, $sortable, true ) ) : ?>
                                <?php
                                $next_order = $orderby === $column && 'ASC' === $order
                                    ? 'DESC'
                                    : 'ASC';

                                $sort_url = add_query_arg(
                                    [
                                        'orderby'            => $column,
                                        'order'              => $next_order,
                                        'paged'              => 1,
                                        'comision_descuento' => $comision_descuento,
                                        'comision_original'  => $comision_original,
                                    'no_importar_imagenes' => $no_importar_imagenes ? 1 : 0,
                                    ]
                                );
                                ?>
                                <a href="<?php echo esc_url( $sort_url ); ?>">
                                    <?php echo esc_html( $available[ $column ] ); ?>
                                    <?php echo $orderby === $column ? ( 'ASC' === $order ? ' ↑' : ' ↓' ) : ''; ?>
                                </a>
                            <?php else : ?>
                                <?php echo esc_html( $available[ $column ] ); ?>
                            <?php endif; ?>
                        </th>
                    <?php endforeach; ?>

                    <th>
                        Precio con descuento
                        <br>
                        <small>+<?php echo esc_html( number_format_i18n( $comision_descuento, 2 ) ); ?> %</small>
                    </th>

                    <th>
                        Precio original
                        <br>
                        <small>+<?php echo esc_html( number_format_i18n( $comision_original, 2 ) ); ?> %</small>
                    </th>
                </tr>
            </thead>

            <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr>
                        <td colspan="<?php echo esc_attr( count( $columns ) + 2 ); ?>">
                            No hay productos con estos filtros.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ( $rows as $row ) : ?>
                    <?php
                    $row_provider      = (string) ( $row['proveedor'] ?? '' );
                    $supplier_category = (string) ( $row['categoria_proveedor'] ?? '' );
                    $suggestion        = seo_proveedores_obtener_sugerencia_mapa(
                        $suggestion_map,
                        $row_provider,
                        $supplier_category
                    );

                    $precio_proveedor = null;

                    if (
                        isset( $row['precio_con_iva'] )
                        && '' !== (string) $row['precio_con_iva']
                        && null !== $row['precio_con_iva']
                    ) {
                        $precio_proveedor = (float) $row['precio_con_iva'];
                    } elseif (
                        isset( $row['precio_sin_iva'] )
                        && '' !== (string) $row['precio_sin_iva']
                        && null !== $row['precio_sin_iva']
                    ) {
                        $precio_proveedor = (float) $row['precio_sin_iva'];
                    }

                    $precio_descuento = null !== $precio_proveedor
                        ? round( $precio_proveedor * ( 1 + ( $comision_descuento / 100 ) ), wc_get_price_decimals() )
                        : null;

                    $precio_original = null !== $precio_proveedor
                        ? round( $precio_proveedor * ( 1 + ( $comision_original / 100 ) ), wc_get_price_decimals() )
                        : null;
                    ?>

                    <tr>
                        <?php foreach ( $columns as $column ) : ?>
                            <td style="max-width:420px;vertical-align:top;">
                                <?php if ( 'estado_seleccion' === $column ) : ?>
                                    <?php
                                    $selection_key = sanitize_key( (string) ( $row['estado_seleccion'] ?? 'pendiente' ) );
                                    $selection_labels = seo_proveedores_estados_catalogo();
                                    ?>
                                    <?php if ( ! empty( $row['object_id'] ) ) : ?>
                                        <strong><?php echo esc_html( $selection_labels[ $selection_key ] ?? $selection_key ); ?></strong>
                                        <br><small style="color:#646970;">La situación controla las acciones sobre WooCommerce.</small>
                                    <?php else : ?>
                                        <?php $state_form_id = 'seo-prov-state-' . absint( $row['id'] ); ?>
                                        <form
                                            id="<?php echo esc_attr( $state_form_id ); ?>"
                                            method="post"
                                            style="display:flex;gap:5px;"
                                        >
                                            <?php wp_nonce_field( 'seo_prov_actualizar_estado', 'seo_prov_estado_nonce' ); ?>
                                            <input type="hidden" name="producto_proveedor_id" value="<?php echo esc_attr( absint( $row['id'] ) ); ?>">
                                            <input type="hidden" name="comision_descuento" value="<?php echo esc_attr( $comision_descuento ); ?>">
                                            <input type="hidden" name="comision_original" value="<?php echo esc_attr( $comision_original ); ?>">
                                            <input type="hidden" name="no_importar_imagenes" value="<?php echo $no_importar_imagenes ? '1' : '0'; ?>">

                                            <select name="estado_seleccion">
                                                <?php foreach ( $selection_labels as $key => $label ) : ?>
                                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $selection_key, $key ); ?>>
                                                        <?php echo esc_html( $label ); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>

                                            <button class="button button-small" name="seo_prov_actualizar_estado" value="1">
                                                Guardar
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                <?php elseif ( 'estado_sincronizacion' === $column ) : ?>
                                    <?php
                                    $sync_key = sanitize_key( (string) ( $row['estado_sincronizacion'] ?? 'legacy' ) );
                                    $sync_labels = seo_proveedores_situaciones_catalogo();
                                    ?>
                                    <strong><?php echo esc_html( $sync_labels[ $sync_key ] ?? $sync_key ); ?></strong>
                                    <?php if ( 'conflicto' === $sync_key && ! empty( $row['ultimo_error_sync'] ) ) : ?>
                                        <br><small style="color:#b32d2e;"><?php echo esc_html( mb_strimwidth( (string) $row['ultimo_error_sync'], 0, 130, '...' ) ); ?></small>
                                    <?php endif; ?>

                                <?php elseif ( 'cambios' === $column ) : ?>
                                    <?php
                                    $sync_key = sanitize_key( (string) ( $row['estado_sincronizacion'] ?? '' ) );
                                    $change_labels = function_exists( 'seo_supplier_sync_change_labels_from_token' )
                                        ? seo_supplier_sync_change_labels_from_token( (string) ( $row['cambios_detectados'] ?? '' ) )
                                        : [];
                                    $applied_snapshot = function_exists( 'seo_supplier_sync_decode_snapshot' )
                                        ? seo_supplier_sync_decode_snapshot( (string) ( $row['snapshot_aplicado'] ?? '' ) )
                                        : [];
                                    $current_snapshot = function_exists( 'seo_supplier_sync_snapshot' )
                                        ? seo_supplier_sync_snapshot( $row )
                                        : [];
                                    $row_diff = function_exists( 'seo_supplier_sync_diff' )
                                        ? seo_supplier_sync_diff( $applied_snapshot, $current_snapshot )
                                        : [];
                                    ?>
                                    <?php if ( 'nuevo' === $sync_key ) : ?>
                                        <strong>Alta nueva</strong>
                                    <?php elseif ( 'baja_pendiente' === $sync_key ) : ?>
                                        <strong>No aparece en el ultimo catalogo completo</strong>
                                    <?php elseif ( 'reactivado' === $sync_key ) : ?>
                                        <strong>Vuelve a aparecer en el proveedor</strong>
                                    <?php elseif ( 'conflicto' === $sync_key ) : ?>
                                        <strong style="color:#b32d2e;">Identidad en conflicto</strong>
                                    <?php elseif ( 'error' === $sync_key ) : ?>
                                        <strong style="color:#b32d2e;">Error al aplicar</strong>
                                        <?php if ( ! empty( $row['ultimo_error_sync'] ) ) : ?>
                                            <br><small><?php echo esc_html( mb_strimwidth( (string) $row['ultimo_error_sync'], 0, 130, '...' ) ); ?></small>
                                        <?php endif; ?>
                                    <?php elseif ( empty( $change_labels ) ) : ?>
                                        <span style="color:#646970;">Sin cambios</span>
                                    <?php else : ?>
                                        <strong><?php echo esc_html( implode( ' · ', $change_labels ) ); ?></strong>
                                        <?php if ( ! empty( $row_diff ) ) : ?>
                                            <details style="margin-top:5px;">
                                                <summary style="cursor:pointer;">Ver detalle</summary>
                                                <?php foreach ( $row_diff as $field => $change ) : ?>
                                                    <?php if ( ! isset( seo_supplier_sync_fields()[ $field ] ) ) { continue; } ?>
                                                    <div style="margin-top:4px;">
                                                        <small><strong><?php echo esc_html( seo_supplier_sync_fields()[ $field ] ); ?>:</strong>
                                                        <?php echo esc_html( seo_supplier_sync_display_value( $field, $change['before'] ?? '' ) ); ?>
                                                        → <?php echo esc_html( seo_supplier_sync_display_value( $field, $change['after'] ?? '' ) ); ?></small>
                                                    </div>
                                                <?php endforeach; ?>
                                            </details>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                <?php elseif ( 'acciones_sync' === $column ) : ?>
                                    <?php $sync_key = sanitize_key( (string) ( $row['estado_sincronizacion'] ?? '' ) ); ?>
                                    <?php if ( in_array( $sync_key, [ 'nuevo', 'modificado', 'baja_pendiente', 'reactivado', 'error' ], true ) ) : ?>
                                        <form method="post" style="display:flex;gap:5px;flex-wrap:wrap;min-width:150px;">
                                            <?php wp_nonce_field( 'seo_supplier_sync_action', 'seo_supplier_sync_nonce' ); ?>
                                            <input type="hidden" name="seo_supplier_sync_action" value="1">
                                            <input type="hidden" name="producto_proveedor_id" value="<?php echo esc_attr( absint( $row['id'] ) ); ?>">
                                            <input type="hidden" name="comision_descuento" value="<?php echo esc_attr( $comision_descuento ); ?>">
                                            <input type="hidden" name="comision_original" value="<?php echo esc_attr( $comision_original ); ?>">
                                            <input type="hidden" name="no_importar_imagenes" value="<?php echo $no_importar_imagenes ? '1' : '0'; ?>">
                                            <?php if ( 'nuevo' === $sync_key ) : ?>
                                                <button class="button button-primary button-small" name="sync_action" value="aceptar">Aceptar</button>
                                                <button class="button button-small" name="sync_action" value="descartar">Descartar</button>
                                            <?php elseif ( 'modificado' === $sync_key ) : ?>
                                                <button class="button button-primary button-small" name="sync_action" value="actualizar">Actualizar</button>
                                            <?php elseif ( 'baja_pendiente' === $sync_key ) : ?>
                                                <button class="button button-small" name="sync_action" value="baja" onclick="return confirm('El producto quedara en borrador, oculto y sin stock. ¿Continuar?');">Aplicar baja</button>
                                            <?php elseif ( 'reactivado' === $sync_key ) : ?>
                                                <button class="button button-primary button-small" name="sync_action" value="reactivar">Reactivar</button>
                                            <?php elseif ( 'error' === $sync_key ) : ?>
                                                <button class="button button-primary button-small" name="sync_action" value="actualizar">Reintentar actualización</button>
                                            <?php endif; ?>
                                        </form>
                                    <?php elseif ( 'conflicto' === $sync_key ) : ?>
                                        <strong style="color:#b32d2e;">Revisar uno a uno</strong>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>

                                <?php elseif ( 'categoria_sugerida' === $column ) : ?>
                                    <?php if ( ! empty( $suggestion['accepted'] ) && ! empty( $suggestion['term_id'] ) ) : ?>
                                        <?php
                                        $edit_term_url = get_edit_term_link(
                                            absint( $suggestion['term_id'] ),
                                            'product_cat'
                                        );
                                        ?>

                                        <?php if ( $edit_term_url && ! is_wp_error( $edit_term_url ) ) : ?>
                                            <a href="<?php echo esc_url( $edit_term_url ); ?>">
                                                <strong><?php echo esc_html( $suggestion['name'] ); ?></strong>
                                            </a>
                                        <?php else : ?>
                                            <strong><?php echo esc_html( $suggestion['name'] ); ?></strong>
                                        <?php endif; ?>

                                        <br>
                                        <small>
                                            <?php echo 'remembered' === ( $suggestion['reason'] ?? '' )
                                                ? 'Equivalencia recordada de una aceptación anterior.'
                                                : 'Se asignará al aceptar.'; ?>
                                        </small>
                                    <?php else : ?>
                                        <strong style="color:#646970;">Sin propuesta clara</strong>
                                        <br>
                                        <small>
                                            <?php if ( 'ambiguous' === ( $suggestion['reason'] ?? '' ) ) : ?>
                                                Hay varias coincidencias muy parecidas.
                                            <?php elseif ( 'empty' === ( $suggestion['reason'] ?? '' ) ) : ?>
                                                El proveedor no indicó categoría.
                                            <?php elseif ( 'no_categories' === ( $suggestion['reason'] ?? '' ) ) : ?>
                                                No hay categorías disponibles para comparar.
                                            <?php else : ?>
                                                La similitud no supera el umbral mínimo.
                                            <?php endif; ?>

                                            <?php if ( $fallback_category['valid'] ) : ?>
                                                Al aceptar: <?php echo esc_html( $fallback_category['name'] ); ?>.
                                            <?php endif; ?>
                                        </small>
                                    <?php endif; ?>

                                <?php elseif ( 'confianza_categoria' === $column ) : ?>
                                    <?php if ( (float) ( $suggestion['score'] ?? 0 ) > 0 ) : ?>
                                        <strong><?php echo esc_html( number_format_i18n( (float) $suggestion['score'], 1 ) ); ?> %</strong>
                                        <br>
                                        <small>
                                            <?php echo esc_html( seo_proveedores_etiqueta_confianza_categoria( (float) $suggestion['score'] ) ); ?>
                                            <?php if ( empty( $suggestion['accepted'] ) ) : ?>
                                                · no aplicada
                                            <?php endif; ?>
                                        </small>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>

                                <?php elseif (
                                    in_array( $column, [ 'url_origen', 'imagenes' ], true )
                                    && ! empty( $row[ $column ] )
                                ) : ?>
                                    <?php
                                    $urls      = preg_split( '/[|,\n]/', (string) $row[ $column ] );
                                    $first_url = isset( $urls[0] ) ? trim( $urls[0] ) : '';
                                    ?>
                                    <?php if ( '' !== $first_url ) : ?>
                                        <a href="<?php echo esc_url( $first_url ); ?>" target="_blank" rel="noopener">Abrir</a>
                                    <?php endif; ?>

                                <?php elseif (
                                    in_array( $column, [ 'precio_sin_iva', 'precio_con_iva' ], true )
                                    && '' !== (string) ( $row[ $column ] ?? '' )
                                ) : ?>
                                    <?php echo esc_html( number_format_i18n( (float) $row[ $column ], 2 ) ); ?> €

                                <?php else : ?>
                                    <?php
                                    echo esc_html(
                                        mb_strimwidth(
                                            wp_strip_all_tags( (string) ( $row[ $column ] ?? '' ) ),
                                            0,
                                            180,
                                            '…'
                                        )
                                    );
                                    ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>

                        <td style="vertical-align:top;white-space:nowrap;">
                            <?php if ( null !== $precio_descuento ) : ?>
                                <strong><?php echo esc_html( number_format_i18n( $precio_descuento, wc_get_price_decimals() ) ); ?> €</strong>
                                <br>
                                <small>Base: <?php echo esc_html( number_format_i18n( $precio_proveedor, wc_get_price_decimals() ) ); ?> €</small>
                            <?php else : ?>
                                <span style="color:#a00;">Sin precio</span>
                            <?php endif; ?>
                        </td>

                        <td style="vertical-align:top;white-space:nowrap;">
                            <?php if ( null !== $precio_original ) : ?>
                                <strong><?php echo esc_html( number_format_i18n( $precio_original, wc_get_price_decimals() ) ); ?> €</strong>
                                <br>
                                <small>Base: <?php echo esc_html( number_format_i18n( $precio_proveedor, wc_get_price_decimals() ) ); ?> €</small>
                            <?php else : ?>
                                <span style="color:#a00;">Sin precio</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ( $total_pages > 1 ) : ?>
        <div class="tablenav">
            <div class="tablenav-pages">
                <?php
                echo wp_kses_post(
                    paginate_links(
                        [
                            'base'    => add_query_arg(
                                [
                                    'paged'              => '%#%',
                                    'comision_descuento' => $comision_descuento,
                                    'comision_original'  => $comision_original,
                                    'no_importar_imagenes' => $no_importar_imagenes ? 1 : 0,
                                ]
                            ),
                            'format'  => '',
                            'current' => $page_num,
                            'total'   => $total_pages,
                        ]
                    )
                );
                ?>
            </div>
        </div>
    <?php endif; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const descuentoInput = document.getElementById('seo-comision-descuento');
            const originalInput = document.getElementById('seo-comision-original');
            const descuentoEjemplo = document.getElementById('seo-ejemplo-precio-descuento');
            const originalEjemplo = document.getElementById('seo-ejemplo-precio-original');

            function actualizarEjemplo() {
                const descuento = parseFloat(String(descuentoInput.value).replace(',', '.')) || 0;
                const original = parseFloat(String(originalInput.value).replace(',', '.')) || 0;
                const precioDescuento = 100 * (1 + (descuento / 100));
                const precioOriginal = 100 * (1 + (original / 100));

                descuentoEjemplo.textContent = precioDescuento.toLocaleString(
                    'es-ES',
                    { minimumFractionDigits: 2, maximumFractionDigits: 2 }
                ) + ' €';

                originalEjemplo.textContent = precioOriginal.toLocaleString(
                    'es-ES',
                    { minimumFractionDigits: 2, maximumFractionDigits: 2 }
                ) + ' €';
            }

            if ( descuentoInput && originalInput && descuentoEjemplo && originalEjemplo ) {
                descuentoInput.addEventListener('input', actualizarEjemplo);
                originalInput.addEventListener('input', actualizarEjemplo);
            }
        });
    </script>

    <?php
}
