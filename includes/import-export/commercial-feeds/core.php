<?php
/**
 * SEO System - Motor de catalogos comerciales.
 *
 * Mantiene un registro canonico de producto y genera feeds publicos por lotes
 * para evitar cargar 14k+ productos en una sola peticion PHP.
 *
 * @package SEOSystem
 * @subpackage ImportExport\CommercialFeeds
 * @since 2.3.7
 */

defined( 'ABSPATH' ) || exit;

const SEO_IE_CF_SETTINGS_OPTION = 'seo_ie_commercial_feeds_settings_v1';
const SEO_IE_CF_STATE_OPTION    = 'seo_ie_commercial_feeds_state_v1';
const SEO_IE_CF_BATCH_SIZE      = 180;
const SEO_IE_CF_DAILY_HOOK      = 'seo_ie_cf_daily_refresh';
const SEO_IE_CF_BATCH_HOOK      = 'seo_ie_cf_build_batch';

/**
 * Registra hooks y planificacion.
 */
function seo_ie_cf_register_runtime() {
    add_action( 'init', 'seo_ie_cf_maybe_schedule_daily', 30 );
    add_action( SEO_IE_CF_DAILY_HOOK, 'seo_ie_cf_daily_refresh' );
    add_action( SEO_IE_CF_BATCH_HOOK, 'seo_ie_cf_process_batch', 10, 2 );
}

/**
 * Ajustes por defecto.
 */
function seo_ie_cf_default_settings() {
    $country = 'ES';
    if ( function_exists( 'WC' ) && WC() && WC()->countries ) {
        $base = (string) WC()->countries->get_base_country();
        if ( '' !== $base ) {
            $country = strtoupper( $base );
        }
    }

    $locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
    $language = strtolower( substr( (string) $locale, 0, 2 ) );
    if ( ! preg_match( '/^[a-z]{2}$/', $language ) ) {
        $language = 'es';
    }

    return [
        'enabled_channels' => [ 'google', 'microsoft', 'pinterest', 'universal' ],
        'country'          => $country,
        'language'         => $language,
        'auto_refresh'     => 1,
        'batch_size'       => SEO_IE_CF_BATCH_SIZE,
    ];
}

/**
 * Ajustes efectivos.
 */
function seo_ie_cf_settings() {
    $saved = get_option( SEO_IE_CF_SETTINGS_OPTION, [] );
    $saved = is_array( $saved ) ? $saved : [];
    $settings = wp_parse_args( $saved, seo_ie_cf_default_settings() );

    $known = array_keys( seo_ie_cf_channels() );
    $settings['enabled_channels'] = array_values(
        array_intersect(
            $known,
            array_map( 'sanitize_key', (array) $settings['enabled_channels'] )
        )
    );
    if ( empty( $settings['enabled_channels'] ) ) {
        $settings['enabled_channels'] = [ 'google' ];
    }

    $settings['country'] = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $settings['country'] ), 0, 2 ) );
    if ( '' === $settings['country'] ) {
        $settings['country'] = 'ES';
    }

    $settings['language'] = strtolower( substr( preg_replace( '/[^A-Za-z]/', '', (string) $settings['language'] ), 0, 2 ) );
    if ( '' === $settings['language'] ) {
        $settings['language'] = 'es';
    }

    $settings['auto_refresh'] = empty( $settings['auto_refresh'] ) ? 0 : 1;
    $settings['batch_size'] = max( 50, min( 500, absint( $settings['batch_size'] ?? SEO_IE_CF_BATCH_SIZE ) ) );

    return $settings;
}

/**
 * Devuelve true solo para produccion. En staging se permite generar manualmente
 * pero nunca se activa la regeneracion automatica para evitar publicar un feed
 * de pruebas en las cuentas comerciales reales.
 */
function seo_ie_cf_is_production() {
    if ( function_exists( 'wp_get_environment_type' ) ) {
        return 'production' === wp_get_environment_type();
    }
    return true;
}

/**
 * Directorio y URL publicos de feeds.
 */
function seo_ie_cf_storage() {
    $upload = wp_upload_dir( null, false );
    if ( ! empty( $upload['error'] ) ) {
        return new WP_Error( 'seo_ie_cf_uploads', (string) $upload['error'] );
    }

    $dir = trailingslashit( $upload['basedir'] ) . 'seo-system-feeds';
    $url = trailingslashit( $upload['baseurl'] ) . 'seo-system-feeds';

    if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
        return new WP_Error( 'seo_ie_cf_mkdir', 'No se pudo crear wp-content/uploads/seo-system-feeds.' );
    }

    $index = trailingslashit( $dir ) . 'index.html';
    if ( ! file_exists( $index ) ) {
        @file_put_contents( $index, '' );
    }

    return [
        'dir' => wp_normalize_path( $dir ),
        'url' => untrailingslashit( $url ),
    ];
}

/**
 * Estado persistido de la ultima generacion.
 */
function seo_ie_cf_state() {
    $state = get_option( SEO_IE_CF_STATE_OPTION, [] );
    return is_array( $state ) ? $state : [];
}

function seo_ie_cf_save_state( array $state ) {
    $state['updated_at'] = current_time( 'mysql', true );
    update_option( SEO_IE_CF_STATE_OPTION, $state, false );
    return $state;
}

/**
 * Agenda refresco diario solo en produccion.
 */
function seo_ie_cf_maybe_schedule_daily() {
    $settings = seo_ie_cf_settings();
    $enabled  = ! empty( $settings['auto_refresh'] ) && seo_ie_cf_is_production();

    if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
        $has = as_has_scheduled_action( SEO_IE_CF_DAILY_HOOK, [], SEO_IE_CF_GROUP );
        if ( $enabled && ! $has ) {
            as_schedule_recurring_action(
                time() + 600,
                DAY_IN_SECONDS,
                SEO_IE_CF_DAILY_HOOK,
                [],
                SEO_IE_CF_GROUP
            );
        } elseif ( ! $enabled && $has && function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( SEO_IE_CF_DAILY_HOOK, [], SEO_IE_CF_GROUP );
        }
        return;
    }

    $has = wp_next_scheduled( SEO_IE_CF_DAILY_HOOK );
    if ( $enabled && ! $has ) {
        wp_schedule_event( time() + 600, 'daily', SEO_IE_CF_DAILY_HOOK );
    } elseif ( ! $enabled && $has ) {
        wp_clear_scheduled_hook( SEO_IE_CF_DAILY_HOOK );
    }
}

/**
 * Refresco automatico.
 */
function seo_ie_cf_daily_refresh() {
    if ( ! seo_ie_cf_is_production() ) {
        return;
    }
    seo_ie_cf_start_build( 'scheduled' );
}

/**
 * Encola una tanda de trabajo.
 */
function seo_ie_cf_enqueue_batch( $run_id, $cursor ) {
    $args = [ (string) $run_id, absint( $cursor ) ];

    if ( function_exists( 'as_enqueue_async_action' ) ) {
        as_enqueue_async_action( SEO_IE_CF_BATCH_HOOK, $args, SEO_IE_CF_GROUP );
        return true;
    }

    return wp_schedule_single_event( time() + 5, SEO_IE_CF_BATCH_HOOK, $args );
}

/**
 * Total de ofertas candidatas antes de aplicar reglas editoriales.
 */
function seo_ie_cf_candidate_count() {
    global $wpdb;

    return (int) $wpdb->get_var(
        "SELECT COUNT(*)
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent
         WHERE (
             (p.post_type = 'product' AND p.post_status = 'publish')
             OR
             (p.post_type = 'product_variation' AND p.post_status = 'publish' AND parent.post_status = 'publish')
         )"
    );
}

/**
 * Inicia una generacion completa. Devuelve el estado o WP_Error.
 */
function seo_ie_cf_start_build( $origin = 'manual' ) {
    $current = seo_ie_cf_state();
    $last_ts = ! empty( $current['updated_at'] ) ? strtotime( (string) $current['updated_at'] . ' UTC' ) : 0;

    if (
        'running' === ( $current['status'] ?? '' )
        && $last_ts
        && ( time() - $last_ts ) < 2 * HOUR_IN_SECONDS
    ) {
        return new WP_Error( 'seo_ie_cf_running', 'Ya hay una generacion de inventario en curso.' );
    }

    $storage = seo_ie_cf_storage();
    if ( is_wp_error( $storage ) ) {
        return $storage;
    }

    $settings = seo_ie_cf_settings();
    $channels = seo_ie_cf_channels();
    $run_id   = gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false, false );
    $files    = [];

    foreach ( $settings['enabled_channels'] as $channel ) {
        if ( empty( $channels[ $channel ] ) ) {
            continue;
        }
        $final_name = sanitize_file_name( (string) $channels[ $channel ]['filename'] );
        $final_path = trailingslashit( $storage['dir'] ) . $final_name;
        $temp_path  = $final_path . '.tmp-' . sanitize_file_name( $run_id );
        $header = seo_ie_cf_channel_write_header( $channel, $temp_path );
        if ( is_wp_error( $header ) ) {
            return $header;
        }
        $files[ $channel ] = [
            'temp'     => wp_normalize_path( $temp_path ),
            'final'    => wp_normalize_path( $final_path ),
            'url'      => trailingslashit( $storage['url'] ) . rawurlencode( $final_name ),
            'filename' => $final_name,
        ];
    }

    if ( empty( $files ) ) {
        return new WP_Error( 'seo_ie_cf_no_channels', 'No hay canales comerciales activos.' );
    }

    $state = [
        'run_id'           => $run_id,
        'status'           => 'running',
        'origin'           => sanitize_key( (string) $origin ),
        'started_at'       => current_time( 'mysql', true ),
        'updated_at'       => current_time( 'mysql', true ),
        'completed_at'     => '',
        'cursor'           => 0,
        'candidate_total'  => seo_ie_cf_candidate_count(),
        'processed'        => 0,
        'written'          => 0,
        'excluded'         => 0,
        'excluded_reasons' => [],
        'errors'           => [],
        'files'            => $files,
        'environment'      => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
    ];
    seo_ie_cf_save_state( $state );

    if ( ! seo_ie_cf_enqueue_batch( $run_id, 0 ) ) {
        $state['status'] = 'failed';
        $state['errors'][] = 'No se pudo programar el primer lote.';
        seo_ie_cf_save_state( $state );
        return new WP_Error( 'seo_ie_cf_enqueue', 'No se pudo programar el primer lote.' );
    }

    return $state;
}

/**
 * Devuelve el siguiente conjunto de IDs de producto/variacion.
 */
function seo_ie_cf_next_ids( $cursor, $limit ) {
    global $wpdb;

    $cursor = absint( $cursor );
    $limit  = max( 1, min( 500, absint( $limit ) ) );

    return array_map(
        'absint',
        (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent
                 WHERE p.ID > %d
                   AND (
                     (p.post_type = 'product' AND p.post_status = 'publish')
                     OR
                     (p.post_type = 'product_variation' AND p.post_status = 'publish' AND parent.post_status = 'publish')
                   )
                 ORDER BY p.ID ASC
                 LIMIT %d",
                $cursor,
                $limit
            )
        )
    );
}

/**
 * Ejecuta una tanda de generacion.
 */
function seo_ie_cf_process_batch( $run_id, $cursor = 0 ) {
    $state = seo_ie_cf_state();
    if ( 'running' !== ( $state['status'] ?? '' ) || ! hash_equals( (string) ( $state['run_id'] ?? '' ), (string) $run_id ) ) {
        return;
    }

    $settings = seo_ie_cf_settings();
    $ids = seo_ie_cf_next_ids( $cursor, $settings['batch_size'] );

    if ( empty( $ids ) ) {
        seo_ie_cf_finish_build( $state );
        return;
    }

    foreach ( $ids as $product_id ) {
        $state['processed']++;
        $result = seo_ie_cf_product_record( $product_id );

        if ( is_wp_error( $result ) ) {
            $state['excluded']++;
            $reason = sanitize_key( $result->get_error_code() );
            if ( '' === $reason ) {
                $reason = 'unknown';
            }
            $state['excluded_reasons'][ $reason ] = 1 + absint( $state['excluded_reasons'][ $reason ] ?? 0 );
            continue;
        }

        $written_ok = true;
        foreach ( (array) $state['files'] as $channel => $file ) {
            if ( empty( $file['temp'] ) ) {
                continue;
            }
            $write = seo_ie_cf_channel_append_record( $channel, (string) $file['temp'], $result );
            if ( is_wp_error( $write ) ) {
                $written_ok = false;
                $state['errors'][] = $channel . ': ' . $write->get_error_message();
                if ( count( $state['errors'] ) > 20 ) {
                    $state['errors'] = array_slice( $state['errors'], -20 );
                }
            }
        }

        if ( $written_ok ) {
            $state['written']++;
        } else {
            // Nunca publiques un feed parcial si falla la escritura de un canal.
            $state['status'] = 'failed';
            $state['cursor'] = (int) $product_id;
            seo_ie_cf_save_state( $state );
            seo_ie_cf_cleanup_temp_files( $state );
            return;
        }
    }

    $state['cursor'] = (int) end( $ids );
    seo_ie_cf_save_state( $state );

    if ( ! seo_ie_cf_enqueue_batch( $run_id, $state['cursor'] ) ) {
        $state['status'] = 'failed';
        $state['errors'][] = 'No se pudo programar el siguiente lote.';
        seo_ie_cf_save_state( $state );
        seo_ie_cf_cleanup_temp_files( $state );
    }
}

/**
 * Elimina temporales de una generacion fallida. Los feeds finales anteriores
 * permanecen intactos, de modo que un fallo nunca deja un inventario parcial
 * publicado en una URL estable.
 */
function seo_ie_cf_cleanup_temp_files( array $state ) {
    foreach ( (array) ( $state['files'] ?? [] ) as $file ) {
        $temp = (string) ( $file['temp'] ?? '' );
        if ( '' !== $temp && file_exists( $temp ) ) {
            @unlink( $temp );
        }
    }
}

/**
 * Cierra y publica los archivos temporales.
 */
function seo_ie_cf_finish_build( array $state ) {
    foreach ( (array) $state['files'] as $channel => $file ) {
        $temp  = (string) ( $file['temp'] ?? '' );
        $final = (string) ( $file['final'] ?? '' );
        if ( '' === $temp || '' === $final || ! file_exists( $temp ) ) {
            $state['status'] = 'failed';
            $state['errors'][] = $channel . ': falta el archivo temporal al finalizar.';
            continue;
        }

        $close = seo_ie_cf_channel_finalize( $channel, $temp );
        if ( is_wp_error( $close ) ) {
            $state['status'] = 'failed';
            $state['errors'][] = $channel . ': ' . $close->get_error_message();
            continue;
        }

        if ( ! @rename( $temp, $final ) ) {
            $state['status'] = 'failed';
            $state['errors'][] = $channel . ': no se pudo publicar el archivo final.';
            continue;
        }

        $state['files'][ $channel ]['size'] = (int) @filesize( $final );
    }

    if ( 'failed' !== ( $state['status'] ?? '' ) ) {
        $state['status'] = 'completed';
    }
    $state['completed_at'] = current_time( 'mysql', true );
    seo_ie_cf_save_state( $state );
}

/**
 * Escapado XML.
 */
function seo_ie_cf_xml( $value ) {
    return htmlspecialchars( (string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8' );
}

/**
 * Texto plano compacto para feeds.
 */
function seo_ie_cf_plain_text( $value, $max = 5000 ) {
    $value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $value = preg_replace( '/\s+/u', ' ', $value );
    $value = trim( (string) $value );

    if ( $max > 0 && function_exists( 'mb_substr' ) ) {
        return mb_substr( $value, 0, $max );
    }
    return $max > 0 ? substr( $value, 0, $max ) : $value;
}

/**
 * Categoria hoja mas profunda y ruta propia del producto.
 *
 * @return array{term_id:int,path:string}
 */
function seo_ie_cf_product_category( $product_id ) {
    $terms = get_the_terms( absint( $product_id ), 'product_cat' );
    if ( is_wp_error( $terms ) || empty( $terms ) ) {
        return [ 'term_id' => 0, 'path' => '' ];
    }

    $best = null;
    $best_ancestors = [];
    foreach ( $terms as $term ) {
        $ancestors = array_reverse( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) );
        if ( null === $best || count( $ancestors ) > count( $best_ancestors ) ) {
            $best = $term;
            $best_ancestors = $ancestors;
        }
    }

    if ( ! $best ) {
        return [ 'term_id' => 0, 'path' => '' ];
    }

    $ids = $best_ancestors;
    $ids[] = (int) $best->term_id;
    $names = [];
    foreach ( array_slice( $ids, -5 ) as $term_id ) {
        $term = get_term( $term_id, 'product_cat' );
        if ( $term && ! is_wp_error( $term ) ) {
            $names[] = seo_ie_cf_plain_text( $term->name, 180 );
        }
    }

    return [
        'term_id' => (int) $best->term_id,
        'path'    => implode( ' > ', array_filter( $names ) ),
    ];
}

/**
 * Categoria Google aprobada para una product_cat.
 */
function seo_ie_cf_google_category( $term_id ) {
    $term_id = absint( $term_id );
    if ( $term_id < 1 ) {
        return '';
    }

    if ( function_exists( 'seo_classifier_google_schema_get_mapping' ) ) {
        $mapping = seo_classifier_google_schema_get_mapping( 'product_cat', $term_id );
        if ( is_array( $mapping ) && 'approved' === (string) ( $mapping['status'] ?? '' ) ) {
            $id = trim( (string) ( $mapping['google_taxonomy_id'] ?? '' ) );
            if ( '' !== $id ) {
                return $id;
            }
            $path = trim( (string) ( $mapping['google_path_en'] ?? '' ) );
            if ( '' !== $path ) {
                return $path;
            }
        }
    }

    return '';
}

/**
 * Imagenes locales/externas. Variaciones heredan las imagenes del padre.
 */
function seo_ie_cf_product_images( $product, $limit = 6 ) {
    $ids = [ absint( $product->get_id() ) ];
    if ( method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
        $ids[] = absint( $product->get_parent_id() );
    }
    $ids = array_values( array_unique( array_filter( $ids ) ) );
    $urls = [];

    foreach ( $ids as $product_id ) {
        $wc = wc_get_product( $product_id );
        if ( $wc ) {
            $attachment_ids = array_merge( [ absint( $wc->get_image_id() ) ], array_map( 'absint', (array) $wc->get_gallery_image_ids() ) );
            foreach ( $attachment_ids as $attachment_id ) {
                if ( $attachment_id < 1 ) {
                    continue;
                }
                $url = esc_url_raw( (string) wp_get_attachment_image_url( $attachment_id, 'full' ) );
                if ( $url && ! isset( $urls[ $url ] ) ) {
                    $urls[ $url ] = $url;
                }
                if ( count( $urls ) >= $limit ) {
                    break 2;
                }
            }
        }

        if ( function_exists( 'seo_images_get_external_product_images' ) ) {
            $rows = (array) seo_images_get_external_product_images( $product_id, $limit );
            foreach ( $rows as $row ) {
                $stored_http = absint( $row['http_status'] ?? 0 );
                $last_checked = trim( (string) ( $row['last_checked'] ?? '' ) );
                if ( in_array( $stored_http, [ 404, 410 ], true ) && '' !== $last_checked ) {
                    continue;
                }

                $url = esc_url_raw( (string) ( $row['image_url'] ?? '' ) );
                if ( $url && preg_match( '#^https?://#i', $url ) && ! isset( $urls[ $url ] ) ) {
                    $urls[ $url ] = $url;
                }
                if ( count( $urls ) >= $limit ) {
                    break 2;
                }
            }
        }
    }

    return array_slice( array_values( $urls ), 0, $limit );
}

/**
 * Identidad comercial canonica.
 */
function seo_ie_cf_identity( $product ) {
    $product_id = absint( $product->get_id() );
    $base_id = $product_id;
    if ( method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
        $base_id = absint( $product->get_parent_id() );
    }

    $identity = [
        'sku'   => trim( (string) $product->get_sku() ),
        'gtin'  => '',
        'mpn'   => '',
        'brand' => '',
    ];

    if ( class_exists( 'SEO_Ojeador_Identity' ) ) {
        $candidate = SEO_Ojeador_Identity::from_product( $product_id );
        if ( ! is_wp_error( $candidate ) ) {
            foreach ( [ 'sku', 'gtin', 'mpn', 'brand' ] as $key ) {
                if ( ! empty( $candidate[ $key ] ) ) {
                    $identity[ $key ] = seo_ie_cf_plain_text( $candidate[ $key ], 190 );
                }
            }
        }
    }

    if ( '' === $identity['gtin'] && method_exists( $product, 'get_global_unique_id' ) ) {
        $identity['gtin'] = preg_replace( '/\D+/', '', (string) $product->get_global_unique_id() );
    }

    $meta_candidates = [
        'gtin' => [ '_global_unique_id', '_gtin', 'gtin', '_ean', 'ean', 'ean13', 'gtin13', '_barcode', 'barcode' ],
        'mpn'  => [ '_seo_proveedor_mpn', '_mpn', 'mpn', '_manufacturer_part_number', 'manufacturer_part_number' ],
    ];
    foreach ( $meta_candidates as $field => $keys ) {
        if ( '' !== $identity[ $field ] ) {
            continue;
        }
        foreach ( [ $product_id, $base_id ] as $id ) {
            foreach ( $keys as $key ) {
                $value = trim( (string) get_post_meta( $id, $key, true ) );
                if ( '' !== $value ) {
                    $identity[ $field ] = 'gtin' === $field ? preg_replace( '/\D+/', '', $value ) : seo_ie_cf_plain_text( $value, 190 );
                    break 2;
                }
            }
        }
    }

    if ( '' === $identity['brand'] ) {
        foreach ( [ 'product_brand', 'pa_marca', 'pa_brand', 'pa_fabricante' ] as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }
            $terms = get_the_terms( $base_id, $taxonomy );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                $identity['brand'] = seo_ie_cf_plain_text( reset( $terms )->name, 100 );
                break;
            }
        }
    }

    if ( '' === $identity['brand'] ) {
        foreach ( [ '_brand', 'brand', 'marca', 'Brand', 'Marca' ] as $key ) {
            $value = trim( (string) get_post_meta( $base_id, $key, true ) );
            if ( '' !== $value ) {
                $identity['brand'] = seo_ie_cf_plain_text( $value, 100 );
                break;
            }
        }
    }

    if ( strlen( $identity['gtin'] ) < 8 || strlen( $identity['gtin'] ) > 14 ) {
        $identity['gtin'] = '';
    }

    return $identity;
}

/**
 * Proveedor de origen si esta disponible.
 */
function seo_ie_cf_supplier( $product_id ) {
    foreach ( [ '_seo_proveedor', '_seo_supplier', 'supplier', 'proveedor' ] as $key ) {
        $value = trim( (string) get_post_meta( absint( $product_id ), $key, true ) );
        if ( '' !== $value ) {
            return seo_ie_cf_plain_text( $value, 120 );
        }
    }
    return '';
}

/**
 * Peso con unidad WooCommerce.
 */
function seo_ie_cf_shipping_weight( $product ) {
    $weight = trim( (string) $product->get_weight() );
    if ( '' === $weight || ! is_numeric( $weight ) || (float) $weight <= 0 ) {
        return '';
    }

    $unit = sanitize_key( (string) get_option( 'woocommerce_weight_unit', 'kg' ) );
    if ( ! in_array( $unit, [ 'kg', 'g', 'lbs', 'oz' ], true ) ) {
        return '';
    }
    if ( 'lbs' === $unit ) {
        $unit = 'lb';
    }

    return wc_format_decimal( $weight, 3 ) . ' ' . $unit;
}

/**
 * Registro canonico de una oferta comercial.
 *
 * @return array<string,mixed>|WP_Error
 */
function seo_ie_cf_product_record( $product_id ) {
    if ( ! function_exists( 'wc_get_product' ) ) {
        return new WP_Error( 'woocommerce_unavailable', 'WooCommerce no esta disponible.' );
    }

    $product = wc_get_product( absint( $product_id ) );
    if ( ! $product ) {
        return new WP_Error( 'invalid_product', 'Producto WooCommerce no resoluble.' );
    }

    if ( $product->is_type( 'variable' ) ) {
        return new WP_Error( 'variable_parent', 'El producto variable se representa mediante sus variaciones.' );
    }

    if ( method_exists( $product, 'get_catalog_visibility' ) && 'hidden' === $product->get_catalog_visibility() ) {
        return new WP_Error( 'catalog_hidden', 'Producto oculto del catalogo.' );
    }

    $base_id = absint( $product->get_id() );
    if ( method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
        $base_id = absint( $product->get_parent_id() );
        if ( 'publish' !== get_post_status( $base_id ) ) {
            return new WP_Error( 'parent_not_published', 'La variacion no tiene padre publicado.' );
        }
    }

    $title = seo_ie_cf_plain_text( $product->get_name(), 150 );
    if ( '' === $title ) {
        return new WP_Error( 'missing_title', 'Titulo vacio.' );
    }

    $description = seo_ie_cf_plain_text( $product->get_short_description(), 5000 );
    if ( '' === $description ) {
        $description = seo_ie_cf_plain_text( $product->get_description(), 5000 );
    }
    if ( '' === $description && $base_id !== $product->get_id() ) {
        $parent = wc_get_product( $base_id );
        if ( $parent ) {
            $description = seo_ie_cf_plain_text( $parent->get_short_description(), 5000 );
            if ( '' === $description ) {
                $description = seo_ie_cf_plain_text( $parent->get_description(), 5000 );
            }
        }
    }
    if ( '' === $description ) {
        return new WP_Error( 'missing_description', 'Descripcion vacia.' );
    }

    $link = esc_url_raw( (string) $product->get_permalink() );
    if ( '' === $link ) {
        return new WP_Error( 'missing_link', 'URL de producto vacia.' );
    }

    $images = seo_ie_cf_product_images( $product, 6 );
    if ( empty( $images ) ) {
        return new WP_Error( 'missing_image', 'Sin imagen local ni externa.' );
    }

    $current_price = function_exists( 'wc_get_price_to_display' )
        ? (float) wc_get_price_to_display( $product )
        : (float) $product->get_price();
    if ( $current_price <= 0 ) {
        return new WP_Error( 'missing_price', 'Precio no valido.' );
    }

    $currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR';
    $currency = strtoupper( sanitize_text_field( (string) $currency ) );

    $regular_raw = (float) $product->get_regular_price();
    $regular_price = $regular_raw > 0 && function_exists( 'wc_get_price_to_display' )
        ? (float) wc_get_price_to_display( $product, [ 'price' => $regular_raw ] )
        : $regular_raw;

    $price = $current_price;
    $sale_price = '';
    if ( $product->is_on_sale() && $regular_price > $current_price ) {
        $price = $regular_price;
        $sale_price = wc_format_decimal( $current_price, wc_get_price_decimals() ) . ' ' . $currency;
    }

    $stock_status = sanitize_key( (string) $product->get_stock_status() );
    if ( 'instock' === $stock_status ) {
        $availability = 'in_stock';
    } elseif ( 'onbackorder' === $stock_status ) {
        $availability = 'backorder';
    } else {
        $availability = 'out_of_stock';
    }

    $identity = seo_ie_cf_identity( $product );
    $category = seo_ie_cf_product_category( $base_id );
    $google_category = seo_ie_cf_google_category( $category['term_id'] );

    $item_group_id = '';
    if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ) {
        $item_group_id = 'wc-' . absint( $product->get_parent_id() );
    }

    $identifier_exists = ( '' !== $identity['gtin'] || ( '' !== $identity['brand'] && '' !== $identity['mpn'] ) ) ? 'yes' : 'no';

    return [
        'id'                      => 'wc-' . absint( $product->get_id() ),
        'title'                   => $title,
        'description'             => $description,
        'link'                    => $link,
        'image_link'              => (string) $images[0],
        'additional_image_link'   => implode( ', ', array_slice( $images, 1, 5 ) ),
        'additional_images'        => array_slice( $images, 1, 5 ),
        'price'                   => wc_format_decimal( $price, wc_get_price_decimals() ) . ' ' . $currency,
        'sale_price'              => $sale_price,
        'availability'            => $availability,
        'condition'               => 'new',
        'brand'                   => seo_ie_cf_plain_text( $identity['brand'], 100 ),
        'gtin'                    => preg_replace( '/\D+/', '', (string) $identity['gtin'] ),
        'mpn'                     => seo_ie_cf_plain_text( $identity['mpn'], 70 ),
        'identifier_exists'       => $identifier_exists,
        'google_product_category' => seo_ie_cf_plain_text( $google_category, 255 ),
        'product_type'            => seo_ie_cf_plain_text( $category['path'], 1000 ),
        'item_group_id'           => $item_group_id,
        'sku'                     => seo_ie_cf_plain_text( $identity['sku'], 127 ),
        'supplier'                => seo_ie_cf_supplier( $base_id ),
        'shipping_weight'         => seo_ie_cf_shipping_weight( $product ),
    ];
}
