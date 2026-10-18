<?php
/**
 * VEVOR: commercial approval requires a saved WooCommerce draft.
 * This operates inside the requested administrator action, not a cron job.
 */
defined( 'ABSPATH' ) || exit;

function seo_vevor_accept_all_directly( $where, $params ) {
    global $wpdb;
    $table = $wpdb->prefix . 'seo_proveedores_productos';
    $cursor = 0;
    $done = 0;
    $errors = 0;
    do {
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where}
             AND proveedor = %s AND pais_origen IN ('US','DE','NL','FR')
             AND id > %d ORDER BY id ASC LIMIT 100",
            array_merge( (array) $params, array( 'VEVOR', $cursor ) )
        );
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( ! is_array( $rows ) ) {
            break;
        }
        foreach ( $rows as $row ) {
            $cursor = absint( $row['id'] );
            $out = seo_supplier_sync_apply_action( $row, 'aceptar', 20, 45, true );
            if ( 'error' === ( $out['result'] ?? '' ) ) {
                ++$errors;
            } else {
                ++$done;
            }
        }
    } while ( 100 === count( $rows ) );
    return array( 'processed' => $done, 'errors' => $errors );
}
