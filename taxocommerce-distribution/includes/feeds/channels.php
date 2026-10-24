<?php
/**
 * TaxoCommerce SEO - Canales de catalogos comerciales.
 *
 * Los canales comparten el mismo registro canonico de producto y solo cambian
 * el formato de serializacion. El registro es extensible mediante el filtro
 * tcdist_commercial_feed_channels.
 *
 * @package SEOSystem
 * @subpackage ImportExport\CommercialFeeds
 * @since 2.3.7
 */

defined( 'ABSPATH' ) || exit;

/**
 * Canales conocidos.
 *
 * @return array<string,array<string,string>>
 */
function tcdist_feed_channels() {
    $channels = [
        'google' => [
            'label'       => 'Google Merchant Center',
            'filename'    => 'google-merchant.xml',
            'format'      => 'google_xml',
            'content_type'=> 'application/xml; charset=UTF-8',
            'description' => 'Feed XML RSS compatible con Google Merchant Center.',
        ],
        'microsoft' => [
            'label'       => 'Microsoft Merchant Center / Bing Shopping',
            'filename'    => 'microsoft-merchant.txt',
            'format'      => 'microsoft_tsv',
            'content_type'=> 'text/plain; charset=UTF-8',
            'description' => 'Feed de texto tabulado para Microsoft Merchant Center.',
        ],
        'pinterest' => [
            'label'       => 'Pinterest Catalogs',
            'filename'    => 'pinterest-catalog.csv',
            'format'      => 'pinterest_csv',
            'content_type'=> 'text/csv; charset=UTF-8',
            'description' => 'Feed CSV para catalogos de Pinterest.',
        ],
        'universal' => [
            'label'       => 'Catalogo universal CSV',
            'filename'    => 'catalogo-universal.csv',
            'format'      => 'universal_csv',
            'content_type'=> 'text/csv; charset=UTF-8',
            'description' => 'CSV neutro para revisiones, integraciones o nuevos receptores.',
        ],
        'json' => [
            'label'       => 'Catalogo universal JSON',
            'filename'    => 'catalogo-universal.json',
            'format'      => 'universal_json',
            'content_type'=> 'application/json; charset=UTF-8',
            'description' => 'JSON estructurado del inventario comercial para integraciones, APIs o analisis externo.',
        ],
    ];

    /**
     * Permite registrar nuevos receptores sin modificar el nucleo.
     *
     * Cada canal debe declarar label, filename, format y content_type.
     */
    return (array) apply_filters( 'tcdist_commercial_feed_channels', $channels );
}

/**
 * Columnas por canal. Evita enviar campos desconocidos a receptores estrictos.
 *
 * @return string[]
 */
function tcdist_feed_channel_columns( $channel ) {
    if ( 'microsoft' === $channel ) {
        return [
            'id', 'title', 'description', 'link', 'image_link', 'additional_image_link',
            'price', 'sale_price', 'sale_price_effective_date', 'availability', 'condition', 'brand', 'gtin', 'mpn',
            'identifier_exists', 'google_product_category', 'product_type', 'item_group_id',
        ];
    }

    if ( 'pinterest' === $channel ) {
        return [
            'id', 'title', 'description', 'link', 'image_link', 'price', 'availability',
            'item_group_id', 'product_type', 'additional_image_link', 'sale_price',
            'sale_price_effective_date_attribute', 'brand', 'GTIN', 'mpn', 'condition',
            'google_product_category',
        ];
    }

    return [
        'id', 'title', 'description', 'link', 'image_link', 'additional_image_link',
        'price', 'sale_price', 'sale_price_effective_date', 'availability', 'condition', 'brand', 'gtin', 'mpn',
        'identifier_exists', 'google_product_category', 'product_type', 'item_group_id',
        'sku', 'supplier', 'shipping_weight',
    ];
}

/**
 * Mapea disponibilidad WooCommerce a cada receptor.
 */
function tcdist_feed_channel_availability( $record, $channel ) {
    $canonical = sanitize_key( (string) ( $record['availability'] ?? 'out_of_stock' ) );

    if ( in_array( $channel, [ 'microsoft', 'pinterest' ], true ) ) {
        if ( 'in_stock' === $canonical ) {
            return 'in stock';
        }
        if ( in_array( $canonical, [ 'preorder', 'backorder' ], true ) ) {
            return 'preorder';
        }
        return 'out of stock';
    }

    return $canonical;
}

/**
 * Convierte el registro canonico a una fila tabular.
 *
 * @return array<string,string>
 */
function tcdist_feed_record_for_channel( array $record, $channel ) {
    $row = $record;
    $row['availability'] = tcdist_feed_channel_availability( $record, $channel );
    if ( 'pinterest' === $channel ) {
        $row['GTIN'] = (string) ( $record['gtin'] ?? '' );
        // Pinterest denomina este campo con sufijo _attribute en su CSV.
        $row['sale_price_effective_date_attribute'] = (string) ( $record['sale_price_effective_date'] ?? '' );
    }

    $out = [];
    foreach ( tcdist_feed_channel_columns( $channel ) as $column ) {
        $out[ $column ] = isset( $row[ $column ] ) ? (string) $row[ $column ] : '';
    }

    return $out;
}

/**
 * Abre un stream local para generación incremental de feeds comerciales.
 *
 * WP_Filesystem no expone recursos compatibles con fputcsv(), fseek(),
 * ftell() y fgetc(); por eso el recurso PHP queda encapsulado aquí.
 *
 * @param string $path Ruta local.
 * @param string $mode Modo de apertura.
 * @return resource|false
 */
function tcdist_feed_stream_open( $path, $mode ) {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Incremental feed streaming requires a native PHP resource.
    return @fopen( $path, $mode );
}

/**
 * Escribe bytes en un stream comercial abierto.
 *
 * @param resource $stream Recurso abierto.
 * @param string   $data   Datos a escribir.
 * @return int|false
 */
function tcdist_feed_stream_write( $stream, $data ) {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Required for incremental XML/JSON feed streaming.
    return fwrite( $stream, $data );
}

/**
 * Cierra un stream comercial abierto.
 *
 * @param resource $stream Recurso abierto.
 * @return bool
 */
function tcdist_feed_stream_close( $stream ) {
    if ( ! is_resource( $stream ) ) {
        return false;
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with tcdist_feed_stream_open() for resource-based streaming.
    return fclose( $stream );
}

/**
 * Escribe la cabecera inicial de un canal.
 */
function tcdist_feed_channel_write_header( $channel, $path ) {
    $channels = tcdist_feed_channels();
    if ( empty( $channels[ $channel ] ) ) {
        return new WP_Error( 'tcdist_feed_unknown_channel', 'Canal comercial no reconocido.' );
    }

    $format = (string) $channels[ $channel ]['format'];
    $handle = tcdist_feed_stream_open( $path, 'wb' );
    if ( ! $handle ) {
        return new WP_Error( 'tcdist_feed_open_failed', 'No se pudo crear el archivo temporal del feed.' );
    }

    if ( 'google_xml' === $format ) {
        $site_name = get_bloginfo( 'name' );
        $home      = home_url( '/' );
        tcdist_feed_stream_write( $handle, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" );
        tcdist_feed_stream_write( $handle, "<rss version=\"2.0\" xmlns:g=\"http://base.google.com/ns/1.0\">\n<channel>\n" );
        tcdist_feed_stream_write( $handle, '<title>' . tcdist_feed_xml( $site_name ) . "</title>\n" );
        tcdist_feed_stream_write( $handle, '<link>' . tcdist_feed_xml( $home ) . "</link>\n" );
        tcdist_feed_stream_write( $handle, '<description>' . tcdist_feed_xml( 'Catalogo comercial de ' . $site_name ) . "</description>\n" );
    } elseif ( 'universal_json' === $format ) {
        $header = [
            'schema_version' => '1.0',
            'generated_at'   => gmdate( 'c' ),
            'source'         => home_url( '/' ),
        ];
        tcdist_feed_stream_write( $handle, "{\n" );
        tcdist_feed_stream_write( $handle, '  "schema_version": ' . wp_json_encode( $header['schema_version'] ) . ",\n" );
        tcdist_feed_stream_write( $handle, '  "generated_at": ' . wp_json_encode( $header['generated_at'] ) . ",\n" );
        tcdist_feed_stream_write( $handle, '  "source": ' . wp_json_encode( $header['source'], JSON_UNESCAPED_SLASHES ) . ",\n" );
        tcdist_feed_stream_write( $handle, "  \"products\": [\n" );
    } else {
        $delimiter = 'microsoft_tsv' === $format ? "\t" : ',';
        fputcsv( $handle, tcdist_feed_channel_columns( $channel ), $delimiter, '"', '\\' );
    }

    tcdist_feed_stream_close( $handle );
    return true;
}

/**
 * Anade un producto al archivo de un canal.
 */
function tcdist_feed_channel_append_record( $channel, $path, array $record ) {
    $channels = tcdist_feed_channels();
    if ( empty( $channels[ $channel ] ) ) {
        return new WP_Error( 'tcdist_feed_unknown_channel', 'Canal comercial no reconocido.' );
    }

    $format = (string) $channels[ $channel ]['format'];
    $handle = tcdist_feed_stream_open( $path, 'ab' );
    if ( ! $handle ) {
        return new WP_Error( 'tcdist_feed_append_failed', 'No se pudo escribir en el feed temporal.' );
    }

    if ( 'google_xml' === $format ) {
        $fields = [
            'id', 'title', 'description', 'link', 'image_link', 'additional_image_link',
            'price', 'sale_price', 'sale_price_effective_date', 'availability', 'condition', 'brand', 'gtin', 'mpn',
            'identifier_exists', 'google_product_category', 'product_type', 'item_group_id',
            'shipping_weight',
        ];

        tcdist_feed_stream_write( $handle, "<item>\n" );
        foreach ( $fields as $field ) {
            if ( 'additional_image_link' === $field ) {
                foreach ( (array) ( $record['additional_images'] ?? [] ) as $additional_url ) {
                    $additional_url = trim( (string) $additional_url );
                    if ( '' !== $additional_url ) {
                        tcdist_feed_stream_write( $handle, '<g:additional_image_link>' . tcdist_feed_xml( $additional_url ) . "</g:additional_image_link>\n" );
                    }
                }
                continue;
            }

            $value = isset( $record[ $field ] ) ? trim( (string) $record[ $field ] ) : '';
            if ( '' === $value ) {
                continue;
            }
            tcdist_feed_stream_write( $handle, '<g:' . $field . '>' . tcdist_feed_xml( $value ) . '</g:' . $field . ">\n" );
        }
        tcdist_feed_stream_write( $handle, "</item>\n" );
    } elseif ( 'universal_json' === $format ) {
        if ( tcdist_feed_json_has_records( $path ) ) {
            tcdist_feed_stream_write( $handle, ",\n" );
        }
        $json = wp_json_encode(
            $record,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ( false === $json ) {
            tcdist_feed_stream_close( $handle );
            return new WP_Error( 'tcdist_feed_json_encode', 'No se pudo serializar un producto al catalogo JSON.' );
        }
        tcdist_feed_stream_write( $handle, '    ' . $json );
    } else {
        $row       = tcdist_feed_record_for_channel( $record, $channel );
        $delimiter = 'microsoft_tsv' === $format ? "\t" : ',';
        fputcsv( $handle, array_values( $row ), $delimiter, '"', '\\' );
    }

    tcdist_feed_stream_close( $handle );
    return true;
}

/**
 * Indica si el array products del JSON ya contiene al menos un registro.
 *
 * Se inspecciona el ultimo caracter no blanco del archivo porque cada lote se
 * ejecuta en una peticion distinta y no puede depender de estado estatico PHP.
 */
function tcdist_feed_json_has_records( $path ) {
    $handle = tcdist_feed_stream_open( $path, 'rb' );
    if ( ! $handle ) {
        return false;
    }

    if ( 0 !== fseek( $handle, 0, SEEK_END ) ) {
        tcdist_feed_stream_close( $handle );
        return false;
    }

    $position = ftell( $handle );
    while ( $position > 0 ) {
        $position--;
        if ( 0 !== fseek( $handle, $position, SEEK_SET ) ) {
            break;
        }
        $char = fgetc( $handle );
        if ( false === $char || preg_match( '/\s/u', $char ) ) {
            continue;
        }
        tcdist_feed_stream_close( $handle );
        return '[' !== $char;
    }

    tcdist_feed_stream_close( $handle );
    return false;
}

/**
 * Cierra un canal al finalizar la generacion.
 */
function tcdist_feed_channel_finalize( $channel, $path ) {
    $channels = tcdist_feed_channels();
    if ( empty( $channels[ $channel ] ) ) {
        return new WP_Error( 'tcdist_feed_unknown_channel', 'Canal comercial no reconocido.' );
    }

    $format = (string) $channels[ $channel ]['format'];

    if ( 'google_xml' === $format ) {
        $handle = tcdist_feed_stream_open( $path, 'ab' );
        if ( ! $handle ) {
            return new WP_Error( 'tcdist_feed_finalize_failed', 'No se pudo cerrar el feed XML.' );
        }
        tcdist_feed_stream_write( $handle, "</channel>\n</rss>\n" );
        tcdist_feed_stream_close( $handle );
    } elseif ( 'universal_json' === $format ) {
        $handle = tcdist_feed_stream_open( $path, 'ab' );
        if ( ! $handle ) {
            return new WP_Error( 'tcdist_feed_finalize_failed', 'No se pudo cerrar el catalogo JSON.' );
        }
        tcdist_feed_stream_write( $handle, "\n  ]\n}\n" );
        tcdist_feed_stream_close( $handle );
    }

    return true;
}
