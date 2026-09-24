<?php
/**
 * SEO System - Canales de catalogos comerciales.
 *
 * Los canales comparten el mismo registro canonico de producto y solo cambian
 * el formato de serializacion. El registro es extensible mediante el filtro
 * seo_ie_commercial_feed_channels.
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
function seo_ie_cf_channels() {
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
            'label'       => 'Catalogo universal',
            'filename'    => 'catalogo-universal.csv',
            'format'      => 'universal_csv',
            'content_type'=> 'text/csv; charset=UTF-8',
            'description' => 'CSV neutro para revisiones, integraciones o nuevos receptores.',
        ],
    ];

    /**
     * Permite registrar nuevos receptores sin modificar el nucleo.
     *
     * Cada canal debe declarar label, filename, format y content_type.
     */
    return (array) apply_filters( 'seo_ie_commercial_feed_channels', $channels );
}

/**
 * Columnas por canal. Evita enviar campos desconocidos a receptores estrictos.
 *
 * @return string[]
 */
function seo_ie_cf_channel_columns( $channel ) {
    if ( 'microsoft' === $channel ) {
        return [
            'id', 'title', 'description', 'link', 'image_link', 'additional_image_link',
            'price', 'sale_price', 'availability', 'condition', 'brand', 'gtin', 'mpn',
            'identifier_exists', 'google_product_category', 'product_type', 'item_group_id',
        ];
    }

    if ( 'pinterest' === $channel ) {
        return [
            'id', 'title', 'description', 'link', 'image_link', 'price', 'availability',
            'item_group_id', 'product_type', 'additional_image_link', 'sale_price', 'brand',
            'GTIN', 'mpn', 'condition', 'google_product_category',
        ];
    }

    return [
        'id', 'title', 'description', 'link', 'image_link', 'additional_image_link',
        'price', 'sale_price', 'availability', 'condition', 'brand', 'gtin', 'mpn',
        'identifier_exists', 'google_product_category', 'product_type', 'item_group_id',
        'sku', 'supplier', 'shipping_weight',
    ];
}

/**
 * Mapea disponibilidad WooCommerce a cada receptor.
 */
function seo_ie_cf_channel_availability( $record, $channel ) {
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
function seo_ie_cf_record_for_channel( array $record, $channel ) {
    $row = $record;
    $row['availability'] = seo_ie_cf_channel_availability( $record, $channel );
    if ( 'pinterest' === $channel ) {
        $row['GTIN'] = (string) ( $record['gtin'] ?? '' );
    }

    $out = [];
    foreach ( seo_ie_cf_channel_columns( $channel ) as $column ) {
        $out[ $column ] = isset( $row[ $column ] ) ? (string) $row[ $column ] : '';
    }

    return $out;
}

/**
 * Escribe la cabecera inicial de un canal.
 */
function seo_ie_cf_channel_write_header( $channel, $path ) {
    $channels = seo_ie_cf_channels();
    if ( empty( $channels[ $channel ] ) ) {
        return new WP_Error( 'seo_ie_cf_unknown_channel', 'Canal comercial no reconocido.' );
    }

    $format = (string) $channels[ $channel ]['format'];
    $handle = @fopen( $path, 'wb' );
    if ( ! $handle ) {
        return new WP_Error( 'seo_ie_cf_open_failed', 'No se pudo crear el archivo temporal del feed.' );
    }

    if ( 'google_xml' === $format ) {
        $site_name = get_bloginfo( 'name' );
        $home      = home_url( '/' );
        fwrite( $handle, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" );
        fwrite( $handle, "<rss version=\"2.0\" xmlns:g=\"http://base.google.com/ns/1.0\">\n<channel>\n" );
        fwrite( $handle, '<title>' . seo_ie_cf_xml( $site_name ) . "</title>\n" );
        fwrite( $handle, '<link>' . seo_ie_cf_xml( $home ) . "</link>\n" );
        fwrite( $handle, '<description>' . seo_ie_cf_xml( 'Catalogo comercial de ' . $site_name ) . "</description>\n" );
    } else {
        $delimiter = 'microsoft_tsv' === $format ? "\t" : ',';
        fputcsv( $handle, seo_ie_cf_channel_columns( $channel ), $delimiter, '"', '\\' );
    }

    fclose( $handle );
    return true;
}

/**
 * Anade un producto al archivo de un canal.
 */
function seo_ie_cf_channel_append_record( $channel, $path, array $record ) {
    $channels = seo_ie_cf_channels();
    if ( empty( $channels[ $channel ] ) ) {
        return new WP_Error( 'seo_ie_cf_unknown_channel', 'Canal comercial no reconocido.' );
    }

    $format = (string) $channels[ $channel ]['format'];
    $handle = @fopen( $path, 'ab' );
    if ( ! $handle ) {
        return new WP_Error( 'seo_ie_cf_append_failed', 'No se pudo escribir en el feed temporal.' );
    }

    if ( 'google_xml' === $format ) {
        $fields = [
            'id', 'title', 'description', 'link', 'image_link', 'additional_image_link',
            'price', 'sale_price', 'availability', 'condition', 'brand', 'gtin', 'mpn',
            'identifier_exists', 'google_product_category', 'product_type', 'item_group_id',
            'shipping_weight',
        ];

        fwrite( $handle, "<item>\n" );
        foreach ( $fields as $field ) {
            if ( 'additional_image_link' === $field ) {
                foreach ( (array) ( $record['additional_images'] ?? [] ) as $additional_url ) {
                    $additional_url = trim( (string) $additional_url );
                    if ( '' !== $additional_url ) {
                        fwrite( $handle, '<g:additional_image_link>' . seo_ie_cf_xml( $additional_url ) . "</g:additional_image_link>\n" );
                    }
                }
                continue;
            }

            $value = isset( $record[ $field ] ) ? trim( (string) $record[ $field ] ) : '';
            if ( '' === $value ) {
                continue;
            }
            fwrite( $handle, '<g:' . $field . '>' . seo_ie_cf_xml( $value ) . '</g:' . $field . ">\n" );
        }
        fwrite( $handle, "</item>\n" );
    } else {
        $row       = seo_ie_cf_record_for_channel( $record, $channel );
        $delimiter = 'microsoft_tsv' === $format ? "\t" : ',';
        fputcsv( $handle, array_values( $row ), $delimiter, '"', '\\' );
    }

    fclose( $handle );
    return true;
}

/**
 * Cierra un canal al finalizar la generacion.
 */
function seo_ie_cf_channel_finalize( $channel, $path ) {
    $channels = seo_ie_cf_channels();
    if ( empty( $channels[ $channel ] ) ) {
        return new WP_Error( 'seo_ie_cf_unknown_channel', 'Canal comercial no reconocido.' );
    }

    if ( 'google_xml' === (string) $channels[ $channel ]['format'] ) {
        $handle = @fopen( $path, 'ab' );
        if ( ! $handle ) {
            return new WP_Error( 'seo_ie_cf_finalize_failed', 'No se pudo cerrar el feed XML.' );
        }
        fwrite( $handle, "</channel>\n</rss>\n" );
        fclose( $handle );
    }

    return true;
}
