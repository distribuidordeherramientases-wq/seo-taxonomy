<?php
/**
 * Exportación CSV V2 de productos en peticiones AJAX independientes.
 *
 * El exportador legacy conserva el contrato de columnas y filtros. Este módulo
 * solo controla el cursor, el archivo temporal y la descarga autenticada.
 *
 * @since 2.4.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_ajax_seo_ie_products_export_start', 'seo_ie_products_export_start' );
add_action( 'wp_ajax_seo_ie_products_export_step', 'seo_ie_products_export_step' );
add_action( 'wp_ajax_seo_ie_products_export_status', 'seo_ie_products_export_status' );
add_action( 'admin_post_seo_ie_products_export_download', 'seo_ie_products_export_download' );
add_action( 'admin_enqueue_scripts', 'seo_ie_products_export_enqueue' );

function seo_ie_products_export_state_key() {
    return 'seo_ie_products_export_' . get_current_user_id();
}

function seo_ie_products_export_authorize_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Sin permisos para exportar productos.' ], 403 );
    }
    check_ajax_referer( 'seo_ie_products_export_async', 'nonce' );
}

function seo_ie_products_export_enqueue( $hook ) {
    if ( 'admin_page_seo-import-export' !== $hook ) {
        return;
    }
    wp_enqueue_script(
        'seo-ie-products-export-async',
        SEO_SYSTEM_URL . 'includes/import-export/assets/product-export-async.js',
        [],
        SEO_SYSTEM_VERSION,
        true
    );
    wp_localize_script(
        'seo-ie-products-export-async',
        'seoIeProductsExportAsync',
        [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'seo_ie_products_export_async' ),
        ]
    );
}

/**
 * Solo se conservan los filtros conocidos por el motor V2; nunca se almacena
 * un formulario POST completo ni un nombre/ruta de archivo elegido por cliente.
 */
function seo_ie_products_export_filters_from_post() {
    $allowed = [
        'export_statuses',
        'seo_export_product_statuses',
        'export_cluster',
        'export_hub_primary',
        'export_hub_secondary',
        'export_categories',
        'seo_export_product_categories',
    ];
    $filters = [];
    foreach ( $allowed as $key ) {
        if ( isset( $_POST[ $key ] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- El motor V2 normaliza cada campo al consumirlo; se conserva el slashing original.
            $filters[ $key ] = wp_unslash( $_POST[ $key ] );
        }
    }
    return $filters;
}

function seo_ie_products_export_start() {
    seo_ie_products_export_authorize_ajax();

    $mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
    if ( ! in_array( $mode, [ 'full', 'reduced' ], true ) ) {
        wp_send_json_error( [ 'message' => 'Formato de exportación no válido.' ], 400 );
    }

    $key      = seo_ie_products_export_state_key();
    $previous = get_transient( $key );
    if ( is_array( $previous ) && ! empty( $previous['path'] ) && is_file( $previous['path'] ) ) {
        wp_delete_file( $previous['path'] );
    }

    $job_id = wp_generate_uuid4();
    $path   = trailingslashit( get_temp_dir() ) . 'taxocommerce-product-export-' .
        get_current_user_id() . '-' . sanitize_file_name( $job_id ) . '.csv';
    $file = seo_ie_stream_open( $path, 'wb' );
    if ( false === $file ) {
        wp_send_json_error( [ 'message' => 'No se pudo crear el CSV temporal. Comprueba el directorio temporal de PHP.' ], 500 );
    }
    seo_ie_stream_close( $file );

    $filename = ( 'reduced' === $mode ? 'seo_products_reduced_portable_' : 'seo_products_v2_' ) .
        wp_date( 'Ymd_His' ) . '.csv';

    $state = [
        'id'        => $job_id,
        'path'      => $path,
        'filename'  => $filename,
        'mode'      => $mode,
        'filters'   => seo_ie_products_export_filters_from_post(),
        'page'      => 1,
        'offset'    => 0,
        'processed' => 0,
        'complete'  => false,
        'created'   => time(),
    ];

    set_transient( $key, $state, 2 * HOUR_IN_SECONDS );
    wp_send_json_success( [ 'job' => $job_id, 'processed' => 0 ] );
}

function seo_ie_products_export_current_state() {
    $state = get_transient( seo_ie_products_export_state_key() );
    if ( ! is_array( $state ) || empty( $state['id'] ) ) {
        return null;
    }
    if ( empty( $state['path'] ) || ! is_file( $state['path'] ) ) {
        return null;
    }
    return $state;
}

function seo_ie_products_export_download_url( $state ) {
    return wp_nonce_url(
        add_query_arg(
            [
                'action' => 'seo_ie_products_export_download',
                'job'    => $state['id'],
            ],
            admin_url( 'admin-post.php' )
        ),
        'seo_ie_products_export_download_' . $state['id']
    );
}

function seo_ie_products_export_status() {
    seo_ie_products_export_authorize_ajax();
    $state = seo_ie_products_export_current_state();
    if ( ! $state ) {
        wp_send_json_success( [ 'active' => false ] );
    }
    wp_send_json_success( [
        'active'      => true,
        'job'         => $state['id'],
        'processed'   => (int) $state['processed'],
        'complete'    => (bool) $state['complete'],
        'downloadUrl' => $state['complete'] ? seo_ie_products_export_download_url( $state ) : '',
    ] );
}

function seo_ie_products_export_step() {
    seo_ie_products_export_authorize_ajax();
    $state = seo_ie_products_export_current_state();
    $job = isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : '';

    if ( ! $state || ! hash_equals( (string) $state['id'], $job ) ) {
        wp_send_json_error( [ 'message' => 'La exportación ha caducado o corresponde a otra sesión.' ], 409 );
    }
    if ( ! empty( $state['complete'] ) ) {
        wp_send_json_success( [
            'processed'   => (int) $state['processed'],
            'complete'    => true,
            'downloadUrl' => seo_ie_products_export_download_url( $state ),
        ] );
    }

    $lock_key = 'seo_ie_products_export_lock_' . get_current_user_id();
    $held = get_option( $lock_key );
    if ( is_array( $held ) && time() - absint( $held['created'] ?? 0 ) > 120 ) {
        delete_option( $lock_key );
    }
    if ( ! add_option( $lock_key, [ 'created' => time(), 'job' => $job ], '', false ) ) {
        wp_send_json_error( [ 'message' => 'Ya hay un lote de exportación en curso. Reintenta en unos segundos.' ], 409 );
    }

    try {
        $result = seo_export_products_csv( [
            'async'    => true,
            'mode'     => $state['mode'],
            'filters'  => $state['filters'],
            'path'     => $state['path'],
            'filename' => $state['filename'],
            'page'     => $state['page'],
            'offset'   => $state['offset'],
        ] );
    } catch ( Throwable $error ) {
        delete_option( $lock_key );
        error_log( '[TaxoCommerce CSV export] ' . $error->getMessage() );
        wp_send_json_error( [ 'message' => 'Error PHP durante la exportación. Consulta el registro de errores del servidor.' ], 500 );
    }
    delete_option( $lock_key );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
    }
    if ( ! is_array( $result ) ) {
        wp_send_json_error( [ 'message' => 'El exportador no devolvió el resultado del lote.' ], 500 );
    }

    $state['page']++;
    $state['offset']     = max( 0, (int) ( $result['offset'] ?? 0 ) );
    $state['processed'] += absint( $result['exported'] ?? 0 );
    $state['complete']   = ! empty( $result['complete'] );
    set_transient( seo_ie_products_export_state_key(), $state, 2 * HOUR_IN_SECONDS );

    if ( $state['complete'] ) {
        seo_ie_store_log( [
            'operacion'    => 'reduced' === $state['mode'] ? 'Exportación inventario reducido portable' : 'Exportación de productos V2',
            'archivo'      => $state['filename'],
            'procesados'   => $state['processed'],
            'correctos'    => $state['processed'],
            'errores'      => 0,
            'advertencias' => 0,
            'detalles'     => [ 'Generado por lotes HTTP independientes, CSV autenticado.' ],
        ] );
    }
    wp_send_json_success( [
        'processed'   => (int) $state['processed'],
        'complete'    => (bool) $state['complete'],
        'downloadUrl' => $state['complete'] ? seo_ie_products_export_download_url( $state ) : '',
    ] );
}

function seo_ie_products_export_download() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Sin permisos para descargar el inventario.', 'taxocommerce-seo' ) );
    }
    $job = isset( $_GET['job'] ) ? sanitize_text_field( wp_unslash( $_GET['job'] ) ) : '';
    check_admin_referer( 'seo_ie_products_export_download_' . $job );

    $state = seo_ie_products_export_current_state();
    if ( ! $state || ! hash_equals( (string) $state['id'], $job ) || empty( $state['complete'] ) ) {
        wp_die( esc_html__( 'El CSV no está terminado o ha caducado.', 'taxocommerce-seo' ) );
    }

    while ( ob_get_level() ) {
        ob_end_clean();
    }
    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $state['filename'] ) . '"' );
    header( 'Content-Length: ' . (int) filesize( $state['path'] ) );
    header( 'X-Content-Type-Options: nosniff' );

    $stream = seo_ie_stream_open( $state['path'], 'rb' );
    if ( false === $stream ) {
        wp_die( esc_html__( 'No se pudo abrir el CSV generado.', 'taxocommerce-seo' ) );
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fpassthru,WordPress.Security.EscapeOutput.OutputNotEscaped -- Descarga binaria autenticada; escapar el contenido corrompe el CSV.
    fpassthru( $stream );
    seo_ie_stream_close( $stream );
    exit;
}
