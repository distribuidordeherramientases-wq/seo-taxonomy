<?php
/**
 * SEO System — Sincronizacion integrada de catalogos de proveedores.
 *
 * Separa la decision comercial (estado_seleccion) de la situacion tecnica
 * detectada en cada nueva importacion (estado_sincronizacion). El CSV siempre
 * actualiza la copia intermedia; WooCommerce solo se modifica cuando el usuario
 * ejecuta una accion explicita desde Catalogo de proveedores.
 *
 * @package SEOSystem
 * @subpackage ImportExport
 * @since 2.3.0
 * @version 2026-08-23
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_init', 'seo_supplier_sync_ensure_schema', 5 );
add_action( 'admin_init', 'seo_supplier_sync_migrate_legacy_states', 6 );
add_action( 'admin_init', 'seo_supplier_sync_handle_single_action', 20 );
add_action( 'admin_init', 'seo_supplier_sync_handle_bulk_action', 21 );

/**
 * Campos cuya autoridad pertenece al proveedor una vez creado el producto.
 *
 * Descripcion, excerpt, categorias, atributos, etiquetas, taxonomias SEO y
 * slug NO forman parte de esta instantanea y nunca disparan una actualizacion.
 *
 * @return array<string,string>
 */
function seo_supplier_sync_fields() {
    return [
        'nombre'              => 'Titulo',
        'sku'                 => 'SKU',
        'mpn'                 => 'MPN',
        'marca'               => 'Marca',
        'precio_sin_iva'      => 'Precio sin IVA',
        'precio_con_iva'      => 'Precio con IVA',
        'iva_porcentaje'      => 'IVA',
        'moneda'              => 'Moneda',
        'stock_estado'        => 'Stock',
        'stock_cantidad'      => 'Cantidad',
        'stock_texto'         => 'Texto de stock',
        'imagenes'            => 'Imagenes',
        'url_origen'          => 'URL proveedor',
        'url_canonica'        => 'URL canonica',
    ];
}

/**
 * Situaciones tecnicas mostradas en Catalogo de proveedores.
 *
 * @return array<string,string>
 */
function seo_supplier_sync_situations() {
    return [
        'nuevo'          => 'Nuevo',
        'modificado'     => 'Modificado',
        'sin_cambios'    => 'Sin cambios',
        'baja_pendiente' => 'Baja',
        'baja_aplicada'  => 'Baja aplicada',
        'reactivado'     => 'Reactivado',
        'conflicto'      => 'Conflicto',
        'error'          => 'Error',
        'ignorado'       => 'Ignorado',
        'legacy'         => 'Sin clasificar',
    ];
}

/**
 * Crea columnas auxiliares de forma aditiva. Nunca borra ni renombra datos.
 *
 * @return void
 */
function seo_supplier_sync_ensure_schema() {
    global $wpdb;

    $table = $wpdb->prefix . 'seo_proveedores_productos';
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

    if ( $exists !== $table ) {
        return;
    }

    $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
    $columns = array_map( 'strval', (array) $columns );

    $definitions = [
        'estado_sincronizacion' => "VARCHAR(30) NOT NULL DEFAULT 'legacy'",
        'hash_aplicado'         => 'CHAR(64) DEFAULT NULL',
        'snapshot_aplicado'     => 'LONGTEXT DEFAULT NULL',
        'last_seen_run_id'      => 'BIGINT(20) UNSIGNED DEFAULT NULL',
        'last_applied_run_id'   => 'BIGINT(20) UNSIGNED DEFAULT NULL',
        'missing_since_run_id'  => 'BIGINT(20) UNSIGNED DEFAULT NULL',
        'ultima_sincronizacion' => 'DATETIME DEFAULT NULL',
        'ultimo_error_sync'     => 'TEXT DEFAULT NULL',
        'cambios_detectados'    => 'TEXT DEFAULT NULL',
    ];

    foreach ( $definitions as $column => $definition ) {
        if ( in_array( $column, $columns, true ) ) {
            continue;
        }

        $safe_column = preg_replace( '/[^A-Za-z0-9_]/', '', $column );
        if ( '' === $safe_column ) {
            continue;
        }

        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `{$safe_column}` {$definition}" );
    }

    $indexes = $wpdb->get_col( "SHOW INDEX FROM {$table}", 2 );
    if ( ! in_array( 'estado_sincronizacion', (array) $indexes, true ) ) {
        $wpdb->query( "ALTER TABLE {$table} ADD KEY `estado_sincronizacion` (`estado_sincronizacion`)" );
    }
}

/**
 * Convierte estados historicos que mezclaban seleccion y sincronizacion.
 * Se ejecuta una sola vez por instalacion.
 *
 * @return void
 */
function seo_supplier_sync_migrate_legacy_states() {
    $version = (string) get_option( 'seo_supplier_sync_integrated_version', '' );
    if ( '2026-08-23-1' === $version ) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'seo_proveedores_productos';
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

    if ( $exists !== $table ) {
        return;
    }

    seo_supplier_sync_ensure_schema();

    // "Actualizar" era una situacion tecnica, no una decision comercial.
    $wpdb->query(
        "UPDATE {$table}
         SET estado_seleccion = 'aceptado',
             estado_sincronizacion = 'modificado'
         WHERE estado_seleccion = 'actualizar'"
    );

    // "Publicado" equivale a un producto ya aceptado/comercializado.
    $wpdb->query(
        "UPDATE {$table}
         SET estado_seleccion = 'aceptado',
             estado_sincronizacion = IF(estado_sincronizacion IN ('', 'legacy'), 'sin_cambios', estado_sincronizacion)
         WHERE estado_seleccion = 'publicado'"
    );

    // Un duplicado historico requiere revision de identidad, no una seleccion.
    $wpdb->query(
        "UPDATE {$table}
         SET estado_seleccion = 'revisar',
             estado_sincronizacion = 'conflicto'
         WHERE estado_seleccion = 'duplicado'"
    );

    $wpdb->query(
        "UPDATE {$table}
         SET estado_sincronizacion = 'nuevo'
         WHERE estado_seleccion IN ('pendiente','revisar')
           AND (object_id IS NULL OR object_id = 0)
           AND estado_sincronizacion IN ('', 'legacy')"
    );

    $wpdb->query(
        "UPDATE {$table}
         SET estado_sincronizacion = 'sin_cambios'
         WHERE estado_seleccion = 'aceptado'
           AND object_id > 0
           AND estado_sincronizacion IN ('', 'legacy')"
    );

    $wpdb->query(
        "UPDATE {$table}
         SET estado_sincronizacion = 'ignorado'
         WHERE estado_seleccion = 'descartado'
           AND (object_id IS NULL OR object_id = 0)
           AND estado_sincronizacion IN ('', 'legacy')"
    );

    update_option( 'seo_supplier_sync_integrated_version', '2026-08-23-1', false );
}

/**
 * Normaliza un valor para comparaciones estables.
 *
 * @param string $field Campo.
 * @param mixed  $value Valor.
 * @return string
 */
function seo_supplier_sync_normalize_value( $field, $value ) {
    if ( null === $value ) {
        return '';
    }

    $value = trim( (string) $value );

    if ( in_array( $field, [ 'precio_sin_iva', 'precio_con_iva', 'iva_porcentaje', 'stock_cantidad' ], true ) ) {
        if ( '' === $value || ! is_numeric( str_replace( ',', '.', $value ) ) ) {
            return $value;
        }
        return rtrim( rtrim( number_format( (float) str_replace( ',', '.', $value ), 4, '.', '' ), '0' ), '.' );
    }

    if ( 'imagenes' === $field ) {
        $parts = preg_split( '/[|\n\r]+/', $value );
        $parts = array_values(
            array_filter(
                array_map( 'trim', (array) $parts ),
                static function ( $item ) {
                    return '' !== $item;
                }
            )
        );
        return implode( '|', $parts );
    }

    return $value;
}

/**
 * Instantanea de los campos controlados por proveedor.
 *
 * @param array $row Fila CSV/tabla.
 * @return array<string,string>
 */
function seo_supplier_sync_snapshot( array $row ) {
    $snapshot = [];

    foreach ( seo_supplier_sync_fields() as $field => $label ) {
        $snapshot[ $field ] = seo_supplier_sync_normalize_value( $field, $row[ $field ] ?? '' );
    }

    return $snapshot;
}

/**
 * JSON ASCII seguro para tablas historicas latin1.
 *
 * @param array $snapshot Instantanea.
 * @return string
 */
function seo_supplier_sync_snapshot_json( array $snapshot ) {
    return (string) wp_json_encode( $snapshot );
}

/**
 * Hash comercial estable.
 *
 * @param array $snapshot Instantanea.
 * @return string
 */
function seo_supplier_sync_snapshot_hash( array $snapshot ) {
    return hash( 'sha256', seo_supplier_sync_snapshot_json( $snapshot ) );
}

/**
 * Lee una instantanea aplicada, tolerando datos historicos vacios.
 *
 * @param mixed $json JSON.
 * @return array<string,string>
 */
function seo_supplier_sync_decode_snapshot( $json ) {
    if ( ! is_string( $json ) || '' === trim( $json ) ) {
        return [];
    }

    $decoded = json_decode( $json, true );
    if ( ! is_array( $decoded ) ) {
        return [];
    }

    $snapshot = [];
    foreach ( seo_supplier_sync_fields() as $field => $label ) {
        if ( array_key_exists( $field, $decoded ) ) {
            $snapshot[ $field ] = seo_supplier_sync_normalize_value( $field, $decoded[ $field ] );
        }
    }

    return $snapshot;
}

/**
 * Diferencias entre lo ultimo aplicado y lo que entrega ahora el proveedor.
 *
 * @param array $before Snapshot aplicado.
 * @param array $after  Snapshot actual.
 * @return array<string,array{before:string,after:string}>
 */
function seo_supplier_sync_diff( array $before, array $after ) {
    $diff = [];

    foreach ( seo_supplier_sync_fields() as $field => $label ) {
        $old = seo_supplier_sync_normalize_value( $field, $before[ $field ] ?? '' );
        $new = seo_supplier_sync_normalize_value( $field, $after[ $field ] ?? '' );

        if ( $old !== $new ) {
            $diff[ $field ] = [
                'before' => $old,
                'after'  => $new,
            ];
        }
    }

    return $diff;
}

/**
 * Cadena indexable con los campos cambiados: |precio_con_iva|stock_estado|.
 *
 * @param array $diff Diferencias.
 * @return string
 */
function seo_supplier_sync_changes_token( array $diff ) {
    $keys = array_values( array_intersect( array_keys( seo_supplier_sync_fields() ), array_keys( $diff ) ) );
    return empty( $keys ) ? '' : '|' . implode( '|', $keys ) . '|';
}

/**
 * Devuelve las etiquetas de cambios detectados.
 *
 * @param string $token Cadena de cambios.
 * @return string[]
 */
function seo_supplier_sync_change_labels_from_token( $token ) {
    $labels = [];
    $fields = seo_supplier_sync_fields();

    foreach ( $fields as $field => $label ) {
        if ( false !== strpos( (string) $token, '|' . $field . '|' ) ) {
            $labels[] = $label;
        }
    }

    return $labels;
}



/**
 * Formatea un valor de cambio para la tabla administrativa.
 *
 * @param string $field Campo.
 * @param string $value Valor.
 * @return string
 */
function seo_supplier_sync_display_value( $field, $value ) {
    $value = (string) $value;
    if ( 'imagenes' === $field ) {
        if ( '' === trim( $value ) ) {
            return '0 imagenes';
        }
        $count = count( array_filter( array_map( 'trim', explode( '|', $value ) ) ) );
        return $count . ( 1 === $count ? ' imagen' : ' imagenes' );
    }
    if ( in_array( $field, [ 'precio_sin_iva', 'precio_con_iva' ], true ) && '' !== $value && is_numeric( $value ) ) {
        return number_format_i18n( (float) $value, 2 ) . ' EUR';
    }
    if ( 'stock_cantidad' === $field && '' !== $value && is_numeric( $value ) ) {
        return number_format_i18n( (float) $value, 2 );
    }
    return mb_strimwidth( wp_strip_all_tags( $value ), 0, 90, '...' );
}

/**
 * ID monotono de ejecucion sin crear una tabla adicional.
 *
 * @return int
 */
function seo_supplier_sync_next_run_id() {
    $option = 'seo_supplier_sync_run_sequence';
    $last = (int) get_option( $option, 0 );
    $candidate = max( $last + 1, (int) floor( microtime( true ) * 1000 ) );
    update_option( $option, (string) $candidate, false );
    return $candidate;
}

/**
 * Inicia una importacion logica.
 *
 * @param array  $state Estado del importador.
 * @param string $now   Fecha.
 * @return int
 */
function seo_supplier_sync_start_run( array $state, $now = '' ) {
    seo_supplier_sync_ensure_schema();
    return seo_supplier_sync_next_run_id();
}

/**
 * Calcula la situacion al ver una fila en una nueva importacion.
 *
 * @param array  $existing Fila anterior, vacia si es nueva.
 * @param string $selection Estado seleccion.
 * @param int    $object_id Producto WP.
 * @param string $new_hash Hash actual.
 * @param array  $diff Diferencias con ultimo aplicado.
 * @return string
 */
function seo_supplier_sync_determine_situation( array $existing, $selection, $object_id, $new_hash, array $diff ) {
    $previous_sync = sanitize_key( (string) ( $existing['estado_sincronizacion'] ?? '' ) );
    $selection     = sanitize_key( (string) $selection );
    $object_id     = absint( $object_id );

    if ( $object_id && ( 'baja_aplicada' === $previous_sync || ( 'descartado' === $selection && ! empty( $existing['missing_since_run_id'] ) ) ) ) {
        return 'reactivado';
    }

    if ( 'descartado' === $selection && ! $object_id ) {
        return 'ignorado';
    }

    if ( $object_id || 'aceptado' === $selection ) {
        return empty( $diff ) ? 'sin_cambios' : 'modificado';
    }

    return 'nuevo';
}

/**
 * Marca un conflicto de identidad sin sobrescribir la fila consolidada.
 *
 * @param array $row Fila existente.
 * @param array $incoming Datos entrantes.
 * @param int   $run_id Ejecucion.
 * @param string $message Motivo.
 * @return void
 */
function seo_supplier_sync_mark_conflict( array $row, array $incoming, $run_id, $message ) {
    global $wpdb;

    $table = $wpdb->prefix . 'seo_proveedores_productos';
    $id    = absint( $row['id'] ?? 0 );
    if ( ! $id ) {
        return;
    }

    $payload = [
        'incoming' => $incoming,
        'reason'   => (string) $message,
    ];

    $wpdb->update(
        $table,
        [
            'estado_sincronizacion' => 'conflicto',
            'cambios_detectados'    => '|sku|proveedor_id_externo|',
            'last_seen_run_id'      => absint( $run_id ),
            'raw_json'              => wp_json_encode( $payload ),
            'ultimo_error_sync'     => (string) $message,
            'actualizado'           => current_time( 'mysql' ),
        ],
        [ 'id' => $id ],
        [ '%s', '%s', '%d', '%s', '%s', '%s' ],
        [ '%d' ]
    );
}

/**
 * Cierra una importacion y detecta bajas solo si era un catalogo completo y
 * no hubo errores ni filas omitidas.
 *
 * @param int   $run_id Ejecucion.
 * @param array $state Estado.
 * @param array $log Log.
 * @param string $now Fecha.
 * @return array
 */
function seo_supplier_sync_finalize_import( $run_id, array $state, array $log, $now = '' ) {
    global $wpdb;

    $run_id   = absint( $run_id );
    $provider = sanitize_text_field( (string) ( $state['proveedor'] ?? '' ) );
    $now      = '' !== $now ? $now : current_time( 'mysql' );

    if ( ! $run_id || '' === $provider ) {
        return $log;
    }

    if ( empty( $state['v2_catalog_complete'] ) ) {
        return $log;
    }

    $errors  = absint( $log['errores'] ?? 0 );
    $omitted = absint( $log['filas_omitidas'] ?? 0 );

    if ( $errors > 0 || $omitted > 0 ) {
        if ( function_exists( 'seo_ie_add_log_detail' ) ) {
            seo_ie_add_log_detail( $log, 'No se detectaron bajas: el catalogo completo termino con errores u omisiones.' );
        }
        return $log;
    }

    $table = $wpdb->prefix . 'seo_proveedores_productos';

    $affected = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$table}
             SET estado_sincronizacion = 'baja_pendiente',
                 cambios_detectados = '|baja|',
                 missing_since_run_id = COALESCE(missing_since_run_id, %d),
                 actualizado = %s
             WHERE proveedor = %s
               AND estado_seleccion = 'aceptado'
               AND object_id IS NOT NULL
               AND object_id > 0
               AND (last_seen_run_id IS NULL OR last_seen_run_id <> %d)",
            $run_id,
            $now,
            $provider,
            $run_id
        )
    );

    $log['bajas_detectadas'] = max( 0, (int) $affected );

    if ( function_exists( 'seo_ie_add_log_detail' ) ) {
        seo_ie_add_log_detail( $log, 'Bajas pendientes detectadas: ' . number_format_i18n( $log['bajas_detectadas'] ) . '.' );
    }

    return $log;
}

/**
 * Categoria Nuevos productos, sin crear taxonomias silenciosamente.
 *
 * @return int
 */
function seo_supplier_sync_new_products_category_id() {
    $term = get_term_by( 'slug', 'nuevos-productos', 'product_cat' );
    if ( ! $term || is_wp_error( $term ) ) {
        $term = get_term_by( 'name', 'Nuevos productos', 'product_cat' );
    }
    return ( $term && ! is_wp_error( $term ) ) ? absint( $term->term_id ) : 0;
}

/**
 * Marca una fila como aplicada y establece la nueva linea base.
 *
 * @param array $row Fila actual.
 * @param int   $product_id Producto WP.
 * @return bool
 */
function seo_supplier_sync_finalize_applied_row( array $row, $product_id ) {
    global $wpdb;

    $table       = $wpdb->prefix . 'seo_proveedores_productos';
    $row_id      = absint( $row['id'] ?? 0 );
    $product_id  = absint( $product_id );
    $snapshot    = seo_supplier_sync_snapshot( $row );
    $hash        = seo_supplier_sync_snapshot_hash( $snapshot );
    $last_seen   = absint( $row['last_seen_run_id'] ?? 0 );

    if ( ! $row_id || ! $product_id ) {
        return false;
    }

    $data = [
        'estado_seleccion'      => 'aceptado',
        'estado_sincronizacion' => 'sin_cambios',
        'hash_producto'         => $hash,
        'hash_aplicado'         => $hash,
        'snapshot_aplicado'     => seo_supplier_sync_snapshot_json( $snapshot ),
        'cambios_detectados'    => '',
        'object_id'             => $product_id,
        'missing_since_run_id'  => null,
        'ultima_sincronizacion' => current_time( 'mysql' ),
        'ultimo_error_sync'     => null,
        'actualizado'           => current_time( 'mysql' ),
    ];
    if ( $last_seen ) {
        $data['last_applied_run_id'] = $last_seen;
    }

    return false !== $wpdb->update( $table, $data, [ 'id' => $row_id ] );
}

/**
 * Registra un error de aplicacion sin perder la situacion visible.
 *
 * @param int    $row_id Fila.
 * @param string $message Error.
 * @return void
 */
function seo_supplier_sync_mark_error( $row_id, $message ) {
    global $wpdb;
    $table = $wpdb->prefix . 'seo_proveedores_productos';

    $wpdb->update(
        $table,
        [
            'estado_sincronizacion' => 'error',
            'ultimo_error_sync'     => sanitize_textarea_field( (string) $message ),
            'actualizado'           => current_time( 'mysql' ),
        ],
        [ 'id' => absint( $row_id ) ],
        [ '%s', '%s', '%s' ],
        [ '%d' ]
    );
}

/**
 * Aplica una baja conservando el producto recuperable.
 *
 * @param array $row Fila.
 * @return array{result:string,product_id:int,message:string}
 */
function seo_supplier_sync_apply_baja( array $row ) {
    $product_id = function_exists( 'seo_proveedores_buscar_producto_existente' )
        ? absint( seo_proveedores_buscar_producto_existente( $row ) )
        : absint( $row['object_id'] ?? 0 );

    if ( ! $product_id ) {
        return [ 'result' => 'error', 'product_id' => 0, 'message' => 'No existe producto WordPress vinculado.' ];
    }

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        return [ 'result' => 'error', 'product_id' => 0, 'message' => 'No se pudo cargar el producto WordPress.' ];
    }

    $product->set_stock_status( 'outofstock' );
    $product->set_catalog_visibility( 'hidden' );
    $product->set_status( 'draft' );
    $product->save();

    global $wpdb;
    $table = $wpdb->prefix . 'seo_proveedores_productos';
    $wpdb->update(
        $table,
        [
            'estado_seleccion'      => 'descartado',
            'estado_sincronizacion' => 'baja_aplicada',
            'ultima_sincronizacion' => current_time( 'mysql' ),
            'ultimo_error_sync'     => null,
            'actualizado'           => current_time( 'mysql' ),
        ],
        [ 'id' => absint( $row['id'] ?? 0 ) ]
    );

    clean_post_cache( $product_id );
    wc_delete_product_transients( $product_id );

    return [ 'result' => 'baja', 'product_id' => $product_id, 'message' => 'Producto puesto en borrador, oculto y sin stock.' ];
}

/**
 * Reactiva un producto dado de baja: conserva su contenido editorial, aplica
 * los datos comerciales actuales y lo devuelve a Nuevos productos en draft.
 *
 * @param array $row Fila.
 * @param float $commission_sale Comision descuento.
 * @param float $commission_regular Comision original.
 * @param bool  $external_images Imagen externa.
 * @return array
 */
function seo_supplier_sync_apply_reactivation( array $row, $commission_sale, $commission_regular, $external_images ) {
    if ( ! function_exists( 'seo_proveedores_crear_borrador_desde_fila' ) ) {
        return [ 'result' => 'error', 'product_id' => 0, 'message' => 'El motor de proveedores no esta cargado.', 'warnings' => [] ];
    }

    $result = seo_proveedores_crear_borrador_desde_fila( $row, $commission_sale, $commission_regular, $external_images );
    if ( 'error' === ( $result['result'] ?? '' ) ) {
        return $result;
    }

    $product_id = absint( $result['product_id'] ?? 0 );
    $product    = $product_id ? wc_get_product( $product_id ) : null;

    if ( ! $product ) {
        return [ 'result' => 'error', 'product_id' => 0, 'message' => 'No se pudo reactivar el producto.', 'warnings' => [] ];
    }

    $product->set_status( 'draft' );
    $product->set_catalog_visibility( 'hidden' );

    $new_category = seo_supplier_sync_new_products_category_id();
    if ( $new_category ) {
        $product->set_category_ids( [ $new_category ] );
    }

    $product->save();
    seo_supplier_sync_finalize_applied_row( $row, $product_id );

    $result['result']  = 'reactivated';
    $result['message'] = 'Producto reactivado como borrador en Nuevos productos.';
    return $result;
}

/**
 * Ejecuta una accion coherente sobre una fila.
 *
 * @param array  $row Fila.
 * @param string $action Accion.
 * @param float  $commission_sale Comision descuento.
 * @param float  $commission_regular Comision original.
 * @param bool   $external_images Imagen externa solicitada.
 * @return array
 */
function seo_supplier_sync_apply_action( array $row, $action, $commission_sale = 20, $commission_regular = 45, $external_images = false ) {
    $action    = sanitize_key( (string) $action );
    $situation = sanitize_key( (string) ( $row['estado_sincronizacion'] ?? '' ) );

    if ( 'descartar' === $action ) {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_proveedores_productos';
        $ok = $wpdb->update(
            $table,
            [
                'estado_seleccion'      => 'descartado',
                'estado_sincronizacion' => absint( $row['object_id'] ?? 0 ) ? $situation : 'ignorado',
                'actualizado'           => current_time( 'mysql' ),
            ],
            [ 'id' => absint( $row['id'] ?? 0 ) ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );
        return [
            'result'     => false === $ok ? 'error' : 'discarded',
            'product_id' => absint( $row['object_id'] ?? 0 ),
            'message'    => false === $ok ? 'No se pudo descartar.' : 'Producto descartado en el catalogo intermedio.',
            'warnings'   => [],
        ];
    }

    if ( 'baja' === $action ) {
        return seo_supplier_sync_apply_baja( $row );
    }

    if ( 'reactivar' === $action ) {
        return seo_supplier_sync_apply_reactivation( $row, $commission_sale, $commission_regular, $external_images );
    }

    if ( ! in_array( $action, [ 'aceptar', 'actualizar' ], true ) ) {
        return [ 'result' => 'error', 'product_id' => 0, 'message' => 'Accion no valida.', 'warnings' => [] ];
    }

    if ( 'actualizar' === $action && 'conflicto' === $situation ) {
        return [ 'result' => 'error', 'product_id' => 0, 'message' => 'El conflicto debe revisarse antes de actualizar.', 'warnings' => [] ];
    }

    if ( ! function_exists( 'seo_proveedores_crear_borrador_desde_fila' ) ) {
        return [ 'result' => 'error', 'product_id' => 0, 'message' => 'El motor de proveedores no esta cargado.', 'warnings' => [] ];
    }

    $result = seo_proveedores_crear_borrador_desde_fila( $row, $commission_sale, $commission_regular, $external_images );

    if ( 'error' !== ( $result['result'] ?? '' ) ) {
        $image_issue = '';
        foreach ( (array) ( $result['warnings'] ?? [] ) as $warning ) {
            if ( 0 === stripos( (string) $warning, 'Imagen' ) ) {
                $image_issue = (string) $warning;
                break;
            }
        }

        $applied_snapshot = seo_supplier_sync_decode_snapshot( (string) ( $row['snapshot_aplicado'] ?? '' ) );
        $old_images = trim( (string) ( $applied_snapshot['imagenes'] ?? '' ) );
        $new_images = trim( (string) ( $row['imagenes'] ?? '' ) );
        if ( '' === $image_issue && '' !== $old_images && '' === $new_images ) {
            $image_issue = 'Imagenes: el proveedor no ha entregado imagenes; se conservan las anteriores y la actualizacion queda pendiente.';
        }

        if ( '' !== $image_issue ) {
            seo_supplier_sync_mark_error( absint( $row['id'] ?? 0 ), $image_issue );
            $result['result'] = 'error';
            $result['message'] = $image_issue;
        } else {
            seo_supplier_sync_finalize_applied_row( $row, absint( $result['product_id'] ?? 0 ) );
        }
    } else {
        seo_supplier_sync_mark_error( absint( $row['id'] ?? 0 ), (string) ( $result['message'] ?? 'Error desconocido.' ) );
    }

    return $result;
}

/**
 * Handler de acciones individuales de la columna Acciones.
 *
 * @return void
 */
function seo_supplier_sync_handle_single_action() {
    if ( ! isset( $_POST['seo_supplier_sync_action'] ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permisos para sincronizar productos.' );
    }

    check_admin_referer( 'seo_supplier_sync_action', 'seo_supplier_sync_nonce' );

    global $wpdb;
    $table  = $wpdb->prefix . 'seo_proveedores_productos';
    $row_id = absint( $_POST['producto_proveedor_id'] ?? 0 );
    $action = sanitize_key( $_POST['sync_action'] ?? '' );

    $row = $row_id
        ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $row_id ), ARRAY_A )
        : null;

    if ( ! is_array( $row ) ) {
        wp_die( 'No se ha encontrado el producto del proveedor.' );
    }

    $commission_sale = function_exists( 'seo_proveedores_normalizar_comision' )
        ? seo_proveedores_normalizar_comision( $_POST['comision_descuento'] ?? 20, 20 )
        : 20;
    $commission_regular = function_exists( 'seo_proveedores_normalizar_comision' )
        ? seo_proveedores_normalizar_comision( $_POST['comision_original'] ?? 45, 45 )
        : 45;

    $external_images = function_exists( 'seo_proveedores_resolver_modo_imagenes_externas' )
        ? seo_proveedores_resolver_modo_imagenes_externas( $row, ! empty( $_POST['no_importar_imagenes'] ) )
        : ! empty( $_POST['no_importar_imagenes'] );

    $result = seo_supplier_sync_apply_action( $row, $action, $commission_sale, $commission_regular, $external_images );

    $redirect = wp_get_referer() ?: add_query_arg(
        [ 'page' => 'seo-import-export', 'seo_ie_tab' => 'catalogo-proveedores' ],
        admin_url( 'admin.php' )
    );

    $redirect = add_query_arg(
        [
            'seo_sync_action_result' => sanitize_key( (string) ( $result['result'] ?? 'error' ) ),
            'seo_sync_action_id'     => absint( $result['product_id'] ?? 0 ),
            'seo_sync_action_msg'    => rawurlencode( (string) ( $result['message'] ?? '' ) ),
        ],
        $redirect
    );

    wp_safe_redirect( $redirect );
    exit;
}

/**
 * Acciones masivas sobre todos los resultados filtrados. Cada accion añade
 * ademas su propia condicion de seguridad para impedir operaciones incoherentes.
 *
 * @return void
 */
function seo_supplier_sync_handle_bulk_action() {
    if ( ! isset( $_POST['seo_supplier_sync_bulk_action'] ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permisos para sincronizar productos.' );
    }

    check_admin_referer( 'seo_supplier_sync_bulk_action', 'seo_supplier_sync_bulk_nonce' );

    $action = sanitize_key( $_POST['accion_masiva'] ?? '' );
    if ( ! in_array( $action, [ 'aceptar', 'actualizar', 'baja', 'reactivar', 'descartar' ], true ) ) {
        wp_die( 'La accion masiva no es valida.' );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'seo_proveedores_productos';

    $provider  = sanitize_text_field( wp_unslash( $_POST['f_proveedor'] ?? '' ) );
    $selection = sanitize_key( $_POST['f_estado'] ?? '' );
    $situation = sanitize_key( $_POST['f_situacion'] ?? '' );
    $change    = sanitize_key( $_POST['f_cambio'] ?? '' );
    $search    = sanitize_text_field( wp_unslash( $_POST['f_buscar'] ?? '' ) );
    $sku       = sanitize_text_field( wp_unslash( $_POST['f_sku'] ?? '' ) );
    $category  = sanitize_text_field( wp_unslash( $_POST['f_categoria'] ?? '' ) );
    $suggested = sanitize_text_field( wp_unslash( $_POST['f_categoria_sugerida'] ?? '' ) );

    $price_min = isset( $_POST['f_precio_min'] ) && '' !== (string) $_POST['f_precio_min']
        ? (float) str_replace( ',', '.', wp_unslash( $_POST['f_precio_min'] ) )
        : null;
    $price_max = isset( $_POST['f_precio_max'] ) && '' !== (string) $_POST['f_precio_max']
        ? (float) str_replace( ',', '.', wp_unslash( $_POST['f_precio_max'] ) )
        : null;

    $where  = [ '1=1' ];
    $params = [];

    if ( '' !== $provider ) {
        $where[] = 'proveedor = %s';
        $params[] = $provider;
    }
    if ( '' !== $selection && function_exists( 'seo_proveedores_estados_catalogo' ) && isset( seo_proveedores_estados_catalogo()[ $selection ] ) ) {
        $where[] = 'estado_seleccion = %s';
        $params[] = $selection;
    }
    if ( '' !== $situation && isset( seo_supplier_sync_situations()[ $situation ] ) ) {
        $where[] = 'estado_sincronizacion = %s';
        $params[] = $situation;
    }
    if ( '' !== $change && isset( seo_supplier_sync_fields()[ $change ] ) ) {
        $where[] = 'cambios_detectados LIKE %s';
        $params[] = '%|' . $wpdb->esc_like( $change ) . '|%';
    }
    if ( '' !== $search ) {
        $like = '%' . $wpdb->esc_like( $search ) . '%';
        $where[] = '(nombre LIKE %s OR descripcion LIKE %s OR marca LIKE %s)';
        array_push( $params, $like, $like, $like );
    }
    if ( '' !== $sku ) {
        $like = '%' . $wpdb->esc_like( $sku ) . '%';
        $where[] = '(sku LIKE %s OR mpn LIKE %s OR proveedor_id_externo LIKE %s)';
        array_push( $params, $like, $like, $like );
    }
    if ( '' !== $category ) {
        $where[] = 'categoria_proveedor LIKE %s';
        $params[] = '%' . $wpdb->esc_like( $category ) . '%';
    }
    if ( null !== $price_min ) {
        $where[] = 'COALESCE(precio_con_iva, precio_sin_iva) >= %f';
        $params[] = $price_min;
    }
    if ( null !== $price_max ) {
        $where[] = 'COALESCE(precio_con_iva, precio_sin_iva) <= %f';
        $params[] = $price_max;
    }

    if ( '' !== $suggested && function_exists( 'seo_proveedores_pares_categoria_proveedor_distintos' ) && function_exists( 'seo_proveedores_mapa_sugerencias_categorias' ) && function_exists( 'seo_proveedores_aplicar_filtro_categoria_sugerida' ) ) {
        $pairs = seo_proveedores_pares_categoria_proveedor_distintos( $table );
        $map = seo_proveedores_mapa_sugerencias_categorias( $pairs );
        seo_proveedores_aplicar_filtro_categoria_sugerida( $suggested, $map, $where, $params );
    }

    // Barreras de seguridad independientes del filtro elegido por el usuario.
    if ( 'aceptar' === $action ) {
        $where[] = "estado_sincronizacion = 'nuevo'";
        $where[] = "estado_seleccion IN ('pendiente','revisar')";
        $where[] = '(object_id IS NULL OR object_id = 0)';
    } elseif ( 'actualizar' === $action ) {
        $where[] = "estado_sincronizacion IN ('modificado','error')";
        $where[] = "estado_seleccion = 'aceptado'";
        $where[] = 'object_id IS NOT NULL AND object_id > 0';
    } elseif ( 'baja' === $action ) {
        $where[] = "estado_sincronizacion = 'baja_pendiente'";
        $where[] = "estado_seleccion = 'aceptado'";
        $where[] = 'object_id IS NOT NULL AND object_id > 0';
    } elseif ( 'reactivar' === $action ) {
        $where[] = "estado_sincronizacion = 'reactivado'";
        $where[] = 'object_id IS NOT NULL AND object_id > 0';
    } elseif ( 'descartar' === $action ) {
        $where[] = "estado_sincronizacion = 'nuevo'";
        $where[] = "estado_seleccion IN ('pendiente','revisar')";
    }

    $where_sql = implode( ' AND ', $where );
    $sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id ASC";
    $rows = $params
        ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A )
        : $wpdb->get_results( $sql, ARRAY_A );

    $commission_sale = function_exists( 'seo_proveedores_normalizar_comision' )
        ? seo_proveedores_normalizar_comision( $_POST['comision_descuento'] ?? 20, 20 )
        : 20;
    $commission_regular = function_exists( 'seo_proveedores_normalizar_comision' )
        ? seo_proveedores_normalizar_comision( $_POST['comision_original'] ?? 45, 45 )
        : 45;
    $requested_external = ! empty( $_POST['no_importar_imagenes'] );

    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 0 );
    }

    $processed = 0;
    $errors    = 0;

    foreach ( (array) $rows as $row ) {
        $external = function_exists( 'seo_proveedores_resolver_modo_imagenes_externas' )
            ? seo_proveedores_resolver_modo_imagenes_externas( $row, $requested_external )
            : $requested_external;

        $result = seo_supplier_sync_apply_action( $row, $action, $commission_sale, $commission_regular, $external );
        if ( 'error' === sanitize_key( (string) ( $result['result'] ?? '' ) ) ) {
            $errors++;
        } else {
            $processed++;
        }
    }

    $redirect = esc_url_raw( wp_unslash( $_POST['return_url'] ?? '' ) );
    if ( ! $redirect ) {
        $redirect = add_query_arg(
            [ 'page' => 'seo-import-export', 'seo_ie_tab' => 'catalogo-proveedores' ],
            admin_url( 'admin.php' )
        );
    }

    $redirect = add_query_arg(
        [
            'seo_sync_bulk_action'    => $action,
            'seo_sync_bulk_processed' => absint( $processed ),
            'seo_sync_bulk_errors'    => absint( $errors ),
        ],
        $redirect
    );

    wp_safe_redirect( $redirect );
    exit;
}

