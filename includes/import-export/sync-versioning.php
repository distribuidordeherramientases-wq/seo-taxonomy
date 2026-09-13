<?php
/**
 * SEO System - Versionado temporal para sincronizacion entre entornos.
 *
 * Evita que una importacion sobreescriba una entidad existente cuando el CSV
 * contiene una version mas antigua. Cuando el CSV aporta una fecha valida,
 * tambien restaura esa fecha tras la escritura para que una importacion no
 * convierta artificialmente el destino en la version "mas nueva".
 *
 * Regla de compatibilidad: si el CSV antiguo no contiene fecha de modificacion,
 * se conserva el comportamiento historico y no se bloquea la actualizacion.
 *
 * @package SEOSystem
 * @subpackage ImportExport
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'seo_ie_sync_datetime_to_timestamp' ) ) {
    /**
     * Convierte una fecha MySQL local/GMT a timestamp UTC.
     *
     * @param mixed $value Fecha Y-m-d H:i:s.
     * @param bool  $is_gmt Si la fecha ya esta en UTC.
     * @return int
     */
    function seo_ie_sync_datetime_to_timestamp( $value, $is_gmt = false ) {
        $value = trim( (string) $value );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
            return 0;
        }

        try {
            $timezone = $is_gmt ? new DateTimeZone( 'UTC' ) : wp_timezone();
            $date     = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, $timezone );
            $errors   = DateTimeImmutable::getLastErrors();

            if ( false === $date || ( is_array( $errors ) && ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) ) ) {
                return 0;
            }

            return (int) $date->getTimestamp();
        } catch ( Exception $e ) {
            return 0;
        }
    }
}

if ( ! function_exists( 'seo_ie_sync_row_timestamp' ) ) {
    /**
     * Lee la fecha de una fila. Prefiere GMT cuando esta disponible.
     *
     * @param array  $row Fila normalizada.
     * @param string $local_key Columna local.
     * @param string $gmt_key Columna GMT.
     * @return int
     */
    function seo_ie_sync_row_timestamp( array $row, $local_key, $gmt_key = '' ) {
        if ( '' !== $gmt_key && array_key_exists( $gmt_key, $row ) ) {
            $timestamp = seo_ie_sync_datetime_to_timestamp( $row[ $gmt_key ], true );
            if ( $timestamp > 0 ) {
                return $timestamp;
            }
        }

        if ( array_key_exists( $local_key, $row ) ) {
            return seo_ie_sync_datetime_to_timestamp( $row[ $local_key ], false );
        }

        return 0;
    }
}

if ( ! function_exists( 'seo_ie_sync_post_modified_timestamp' ) ) {
    /**
     * Fecha de modificacion real de un post/producto existente.
     *
     * @param int $post_id ID.
     * @return int
     */
    function seo_ie_sync_post_modified_timestamp( $post_id ) {
        $post = get_post( absint( $post_id ) );
        if ( ! $post instanceof WP_Post ) {
            return 0;
        }

        $timestamp = seo_ie_sync_datetime_to_timestamp( $post->post_modified_gmt, true );
        if ( $timestamp > 0 ) {
            return $timestamp;
        }

        return seo_ie_sync_datetime_to_timestamp( $post->post_modified, false );
    }
}

if ( ! function_exists( 'seo_ie_sync_post_update_decision' ) ) {
    /**
     * Decide si una fila debe omitirse porque el destino es mas reciente.
     *
     * @return array{skip:bool,source:int,destination:int}
     */
    function seo_ie_sync_post_update_decision( $post_id, array $row, $local_key, $gmt_key = '' ) {
        $source      = seo_ie_sync_row_timestamp( $row, $local_key, $gmt_key );
        $destination = seo_ie_sync_post_modified_timestamp( $post_id );

        return [
            'skip'        => $source > 0 && $destination > 0 && $source < $destination,
            'source'      => $source,
            'destination' => $destination,
        ];
    }
}

if ( ! function_exists( 'seo_ie_sync_format_timestamp' ) ) {
    function seo_ie_sync_format_timestamp( $timestamp, $gmt = false ) {
        $timestamp = absint( $timestamp );
        if ( 0 === $timestamp ) {
            return '';
        }

        if ( $gmt ) {
            return gmdate( 'Y-m-d H:i:s', $timestamp );
        }

        return wp_date( 'Y-m-d H:i:s', $timestamp, wp_timezone() );
    }
}

if ( ! function_exists( 'seo_ie_sync_restore_post_modified' ) ) {
    /**
     * Restaura post_modified/post_modified_gmt desde el CSV despues de guardar.
     */
    function seo_ie_sync_restore_post_modified( $post_id, array $row, $local_key, $gmt_key = '' ) {
        global $wpdb;

        $timestamp = seo_ie_sync_row_timestamp( $row, $local_key, $gmt_key );
        if ( $timestamp <= 0 ) {
            return false;
        }

        $updated = $wpdb->update(
            $wpdb->posts,
            [
                'post_modified'     => seo_ie_sync_format_timestamp( $timestamp, false ),
                'post_modified_gmt' => seo_ie_sync_format_timestamp( $timestamp, true ),
            ],
            [ 'ID' => absint( $post_id ) ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        if ( false !== $updated ) {
            clean_post_cache( absint( $post_id ) );
            return true;
        }

        return false;
    }
}

if ( ! function_exists( 'seo_ie_sync_restore_post_created' ) ) {
    /**
     * Conserva la fecha original al crear productos desde otro entorno.
     */
    function seo_ie_sync_restore_post_created( $post_id, array $row, $local_key, $gmt_key = '' ) {
        global $wpdb;

        $timestamp = seo_ie_sync_row_timestamp( $row, $local_key, $gmt_key );
        if ( $timestamp <= 0 ) {
            return false;
        }

        $updated = $wpdb->update(
            $wpdb->posts,
            [
                'post_date'     => seo_ie_sync_format_timestamp( $timestamp, false ),
                'post_date_gmt' => seo_ie_sync_format_timestamp( $timestamp, true ),
            ],
            [ 'ID' => absint( $post_id ) ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        if ( false !== $updated ) {
            clean_post_cache( absint( $post_id ) );
            return true;
        }

        return false;
    }
}

if ( ! function_exists( 'seo_ie_sync_category_meta_key' ) ) {
    function seo_ie_sync_category_meta_key() {
        return '_seo_sync_modified_gmt';
    }
}

if ( ! function_exists( 'seo_ie_sync_category_modified_timestamp' ) ) {
    function seo_ie_sync_category_modified_timestamp( $term_id ) {
        global $wpdb;

        $term_id   = absint( $term_id );
        $meta      = (string) get_term_meta( $term_id, seo_ie_sync_category_meta_key(), true );
        $timestamp = seo_ie_sync_datetime_to_timestamp( $meta, true );

        // Las descripciones/ambito de categoria viven en seo_nodes y pueden
        // cambiar sin ejecutar edited_product_cat. Tomamos tambien su maxima
        // fecha para no permitir que un CSV antiguo pise un cambio local.
        $nodes_updated = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(updated_at) FROM {$wpdb->prefix}seo_nodes WHERE object_type = 'category' AND object_id = %d AND status = 1",
                $term_id
            )
        );
        $nodes_timestamp = seo_ie_sync_datetime_to_timestamp( (string) $nodes_updated, false );

        return max( $timestamp, $nodes_timestamp );
    }
}

if ( ! function_exists( 'seo_ie_sync_touch_category' ) ) {
    /**
     * Registra cambios de product_cat a partir de esta version del plugin.
     */
    function seo_ie_sync_touch_category( $term_id ) {
        if ( function_exists( 'seo_ie_batch_is_internal' ) && seo_ie_batch_is_internal( 'category' ) ) {
            return;
        }

        $term_id = absint( $term_id );
        if ( $term_id > 0 ) {
            update_term_meta( $term_id, seo_ie_sync_category_meta_key(), current_time( 'mysql', true ) );
        }
    }
}
add_action( 'created_product_cat', 'seo_ie_sync_touch_category', 20, 1 );
add_action( 'edited_product_cat', 'seo_ie_sync_touch_category', 20, 1 );

if ( ! function_exists( 'seo_ie_sync_category_update_decision' ) ) {
    function seo_ie_sync_category_update_decision( $term_id, array $row ) {
        $source = seo_ie_sync_row_timestamp( $row, 'fecha_modificada', 'fecha_modificada_gmt' );
        $local  = seo_ie_sync_category_modified_timestamp( $term_id );

        return [
            'skip'        => $source > 0 && $local > 0 && $source < $local,
            'source'      => $source,
            'destination' => $local,
        ];
    }
}

if ( ! function_exists( 'seo_ie_sync_restore_category_modified' ) ) {
    function seo_ie_sync_restore_category_modified( $term_id, array $row ) {
        global $wpdb;

        $term_id   = absint( $term_id );
        $timestamp = seo_ie_sync_row_timestamp( $row, 'fecha_modificada', 'fecha_modificada_gmt' );
        if ( $timestamp <= 0 ) {
            return false;
        }

        $meta_ok = false !== update_term_meta(
            $term_id,
            seo_ie_sync_category_meta_key(),
            seo_ie_sync_format_timestamp( $timestamp, true )
        );

        // seo_ie_upsert_node_value() usa la hora del destino. La devolvemos a
        // la fecha del origen para que el siguiente ciclo PRO/STAGING no crea
        // que el destino es mas nuevo solo por haber sido importado.
        $nodes_ok = $wpdb->update(
            $wpdb->prefix . 'seo_nodes',
            [ 'updated_at' => seo_ie_sync_format_timestamp( $timestamp, false ) ],
            [
                'object_type' => 'category',
                'object_id'   => $term_id,
                'status'      => 1,
            ],
            [ '%s' ],
            [ '%s', '%d', '%d' ]
        );

        return $meta_ok && false !== $nodes_ok;
    }
}

if ( ! function_exists( 'seo_ie_sync_faq_update_decision' ) ) {
    function seo_ie_sync_faq_update_decision( $destination_updated_at, $source_updated_at ) {
        $source      = seo_ie_sync_datetime_to_timestamp( $source_updated_at, false );
        $destination = seo_ie_sync_datetime_to_timestamp( $destination_updated_at, false );

        return [
            'skip'        => $source > 0 && $destination > 0 && $source < $destination,
            'source'      => $source,
            'destination' => $destination,
        ];
    }
}
