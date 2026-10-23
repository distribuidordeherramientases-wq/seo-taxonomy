<?php

defined( 'ABSPATH' ) || exit;

/**
 * Google Trends: official Trending Now RSS, fetched only by explicit POST.
 *
 * Google Trends' general-purpose official API remains restricted (alpha).
 * This module does not scrape Explore, call undocumented endpoints, schedule
 * jobs, or use the old SEO Google Trends worker.
 */
final class TaxoCommerce_Google_Bing_Trends {
    const OPTION_PREFIX = 'tgb_trends_rss_snapshot_v1_';

    public static function register() {
        add_action( 'admin_post_tgb_trends_download', array( __CLASS__, 'download' ) );
        add_action( 'admin_post_tgb_trends_export', array( __CLASS__, 'export_json' ) );
    }

    public static function countries() {
        return array(
            'ES' => 'España',
            'DE' => 'Alemania',
            'FR' => 'Francia',
            'NL' => 'Países Bajos',
            'US' => 'Estados Unidos',
        );
    }

    private static function valid_geo( $geo ) {
        $geo = strtoupper( trim( (string) $geo ) );
        return isset( self::countries()[ $geo ] ) ? $geo : 'ES';
    }

    public static function saved_snapshot( $geo = 'ES' ) {
        $data = get_option( self::OPTION_PREFIX . self::valid_geo( $geo ), array() );
        return is_array( $data ) && ! empty( $data['items'] ) ? $data : array();
    }

    private static function dashboard_url( $geo ) {
        return add_query_arg(
            array(
                'page'       => 'taxocommerce-google-bing',
                'tgb_tab'    => 'google_trends',
                'trends_geo' => self::valid_geo( $geo ),
            ),
            admin_url( 'admin.php' )
        );
    }

    private static function trending_url( $geo ) {
        return add_query_arg(
            array( 'geo' => self::valid_geo( $geo ) ),
            'https://trends.google.com/trending'
        );
    }

    private static function rss_url( $geo ) {
        return add_query_arg(
            array( 'geo' => self::valid_geo( $geo ) ),
            'https://trends.google.com/trending/rss'
        );
    }

    private static function explore_url( $term, $geo ) {
        return add_query_arg(
            array(
                'q'   => (string) $term,
                'geo' => self::valid_geo( $geo ),
            ),
            'https://trends.google.com/trends/explore'
        );
    }

    public static function render() {
        // Filtering and opening a saved report are strictly local operations.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
        $geo = isset( $_GET['trends_geo'] ) ? self::valid_geo( wp_unslash( $_GET['trends_geo'] ) ) : 'ES';
        $snapshot = self::saved_snapshot( $geo );
        $countries = self::countries();

        echo '<div class="tgb-trends">';
        echo '<h2>Google Trends</h2>';
        echo '<p>Tendencias actuales del buscador mediante el <strong>RSS oficial de Google</strong>. Se descargan únicamente cuando pulsas el botón; cambiar de país o volver a esta pantalla no consulta Google.</p>';
        echo '<p class="description">Esta fuente muestra búsquedas que han repuntado recientemente en el país seleccionado; no son las consultas recibidas por tu tienda ni el historial de interés de todas las palabras clave.</p>';

        echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="tgb-trends-controls">';
        echo '<input type="hidden" name="page" value="taxocommerce-google-bing">';
        echo '<input type="hidden" name="tgb_tab" value="google_trends">';
        echo '<label for="tgb-trends-geo"><strong>País:</strong></label>';
        echo '<select id="tgb-trends-geo" name="trends_geo">';
        foreach ( $countries as $code => $label ) {
            echo '<option value="' . esc_attr( $code ) . '"' . ( $code === $geo ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<button class="button" type="submit">Ver datos guardados</button>';
        echo '</form>';

        echo '<div class="tgb-trends-controls">';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tgb_trends_download">';
        echo '<input type="hidden" name="trends_geo" value="' . esc_attr( $geo ) . '">';
        wp_nonce_field( 'tgb_trends_download_' . $geo );
        echo '<button type="submit" class="button button-primary">Descargar tendencias actuales</button>';
        echo '</form>';
        echo '<a class="button" href="' . esc_url( self::trending_url( $geo ) ) . '" target="_blank" rel="noopener noreferrer">Abrir Google Trends</a>';
        echo '</div>';

        $notice_key = 'tgb_trends_notice_' . get_current_user_id() . '_' . $geo;
        $notice = get_transient( $notice_key );
        if ( is_array( $notice ) ) {
            echo '<div class="notice ' . ( ! empty( $notice['error'] ) ? 'notice-error' : 'notice-success' ) . ' inline"><p>' . esc_html( (string) ( $notice['message'] ?? '' ) ) . '</p></div>';
            delete_transient( $notice_key );
        }

        if ( ! $snapshot ) {
            echo '<div class="notice notice-info inline"><p>Sin datos descargados para ' . esc_html( $countries[ $geo ] ) . '. Pulsa «Descargar tendencias actuales» para obtener una copia.</p></div>';
        } else {
            echo '<p class="description"><strong>Copia local:</strong> ' . esc_html( wp_date( 'd/m/Y H:i', (int) $snapshot['downloaded_at'] ) );
            echo ' · ' . esc_html( count( (array) $snapshot['items'] ) ) . ' tendencias · ' . esc_html( $countries[ $geo ] ) . '.</p>';
            $export_url = wp_nonce_url(
                add_query_arg(
                    array( 'action' => 'tgb_trends_export', 'trends_geo' => $geo ),
                    admin_url( 'admin-post.php' )
                ),
                'tgb_trends_export_' . $geo
            );
            echo '<p><a class="button" href="' . esc_url( $export_url ) . '">Exportar JSON guardado</a></p>';
            echo '<div class="tgb-trends-table"><table class="widefat striped">';
            echo '<thead><tr><th>Búsqueda en tendencia</th><th>Volumen aproximado indicado por Google</th><th>Fecha publicada</th><th>Explorar</th></tr></thead><tbody>';
            foreach ( (array) $snapshot['items'] as $item ) {
                $title = (string) ( $item['title'] ?? '' );
                if ( '' === $title ) {
                    continue;
                }
                $pub = (int) ( $item['published_at'] ?? 0 );
                echo '<tr><td><strong>' . esc_html( $title ) . '</strong></td>';
                echo '<td>' . esc_html( (string) ( $item['traffic_label'] ?? 'No disponible' ) ) . '</td>';
                echo '<td>' . esc_html( $pub ? wp_date( 'd/m/Y H:i', $pub ) : 'Sin fecha' ) . '</td>';
                echo '<td><a href="' . esc_url( self::explore_url( $title, $geo ) ) . '" target="_blank" rel="noopener noreferrer">Explorar</a></td></tr>';
            }
            echo '</tbody></table></div>';
        }

        echo '<p class="description tgb-trends-api">La <a href="https://developers.google.com/search/apis/trends" target="_blank" rel="noopener noreferrer">API oficial de Google Trends</a> sigue teniendo acceso alfa restringido. Este informe usa sólo el RSS oficial de tendencias actuales: no realiza consultas automáticas, investigación de mercado ni análisis editorial.</p>';
        echo '<style>
          .tgb-trends{margin-top:18px;max-width:1200px}
          .tgb-trends-controls{display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin:14px 0}
          .tgb-trends-controls form{margin:0}
          .tgb-trends-controls select{max-width:230px}
          .tgb-trends-table{overflow-x:auto;margin:14px 0}
          .tgb-trends-table table{min-width:600px}
          .tgb-trends-table th,.tgb-trends-table td{vertical-align:middle}
          .tgb-trends-api{margin-top:18px}
        </style>';
        echo '</div>';
    }

    /**
     * Parses the documented Trending Now RSS; this is not an Explore API.
     * The function is network-free and may be regression tested independently.
     *
     * @return array|WP_Error
     */
    public static function parse_rss( $body ) {
        if ( ! function_exists( 'simplexml_load_string' ) ) {
            return new WP_Error( 'tgb_trends_simplexml', 'SimpleXML no está habilitado en PHP.' );
        }
        if ( '' === trim( (string) $body ) ) {
            return new WP_Error( 'tgb_trends_empty', 'Google Trends ha devuelto un RSS vacío.' );
        }

        $previous = libxml_use_internal_errors( true );
        $xml = simplexml_load_string( (string) $body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( false === $xml || ! isset( $xml->channel->item ) ) {
            return new WP_Error( 'tgb_trends_xml', 'El RSS no contiene una lista de tendencias válida.' );
        }

        $items = array();
        $seen = array();
        foreach ( $xml->channel->item as $item ) {
            $title = sanitize_text_field( (string) $item->title );
            if ( '' === $title || isset( $seen[ $title ] ) ) {
                continue;
            }
            $seen[ $title ] = true;

            $namespaces = $item->getNamespaces( true );
            $ht_namespace = isset( $namespaces['ht'] ) ? $namespaces['ht'] : 'https://trends.google.com/trending/rss';
            $ht = $item->children( $ht_namespace );
            $traffic = sanitize_text_field( (string) $ht->approx_traffic );
            $published_at = strtotime( (string) $item->pubDate );

            $items[] = array(
                'title'        => $title,
                'traffic_label' => '' !== $traffic ? $traffic : 'No disponible',
                'published_at' => $published_at ? (int) $published_at : 0,
            );

            if ( count( $items ) >= 100 ) {
                break;
            }
        }
        if ( ! $items ) {
            return new WP_Error( 'tgb_trends_no_items', 'El RSS de Google Trends no contiene tendencias reconocibles.' );
        }
        return $items;
    }

    /**
     * Only called from download(), never from render(), plugin boot or cron.
     */
    public static function fetch_rss( $geo ) {
        $geo = self::valid_geo( $geo );
        $url = self::rss_url( $geo );
        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'             => 20,
                'redirection'         => 0,
                'limit_response_size' => 1024 * 1024,
                'headers' => array(
                    'Accept'          => 'application/rss+xml,application/xml,text/xml;q=0.9',
                    'Accept-Language' => 'es-ES,es;q=0.9',
                    'User-Agent'      => 'TaxoCommerce-Google-Bing/' . TAXOCOMMERCE_GOOGLE_BING_VERSION,
                ),
            )
        );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'tgb_trends_connection', 'No se ha podido descargar el RSS de Google Trends: ' . $response->get_error_message() );
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'tgb_trends_http', 'Google Trends ha respondido HTTP ' . $code . '. No se ha modificado la copia guardada.' );
        }
        $items = self::parse_rss( wp_remote_retrieve_body( $response ) );
        if ( is_wp_error( $items ) ) {
            return $items;
        }
        return array( 'source_url' => $url, 'items' => $items );
    }

    public static function download() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para descargar informes.', 'taxocommerce-google-bing' ) );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified with source-specific nonce below.
        $geo = isset( $_POST['trends_geo'] ) ? self::valid_geo( wp_unslash( $_POST['trends_geo'] ) ) : 'ES';
        check_admin_referer( 'tgb_trends_download_' . $geo );

        $lock = 'tgb_trends_download_lock_' . get_current_blog_id() . '_' . $geo;
        if ( get_transient( $lock ) ) {
            self::finish( $geo, 'Ya hay una descarga de Google Trends en curso para este país.', true );
        }
        set_transient( $lock, 1, 90 );
        $result = self::fetch_rss( $geo );
        delete_transient( $lock );

        if ( is_wp_error( $result ) ) {
            self::finish( $geo, $result->get_error_message(), true );
        }
        $snapshot = array(
            'schema'        => 'tgb-google-trends-rss-v1',
            'source'        => 'Google Trends - Trending Now RSS',
            'country'       => $geo,
            'source_url'    => $result['source_url'],
            'downloaded_at' => time(),
            'items'         => $result['items'],
        );
        update_option( self::OPTION_PREFIX . $geo, $snapshot, false );
        do_action( 'tgb_trends_snapshot_saved', $geo, $snapshot );
        self::finish( $geo, 'Tendencias de ' . self::countries()[ $geo ] . ' descargadas y guardadas.', false );
    }

    public static function export_json() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para exportar informes.', 'taxocommerce-google-bing' ) );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified immediately below.
        $geo = isset( $_GET['trends_geo'] ) ? self::valid_geo( wp_unslash( $_GET['trends_geo'] ) ) : 'ES';
        check_admin_referer( 'tgb_trends_export_' . $geo );

        $snapshot = self::saved_snapshot( $geo );
        if ( ! $snapshot ) {
            wp_die( esc_html__( 'No hay datos guardados para exportar.', 'taxocommerce-google-bing' ) );
        }
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="google_trends_' . $geo . '.json"' );
        echo wp_json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON export endpoint.
        exit;
    }

    private static function finish( $geo, $message, $error ) {
        set_transient(
            'tgb_trends_notice_' . get_current_user_id() . '_' . $geo,
            array( 'error' => (bool) $error, 'message' => sanitize_text_field( $message ) ),
            2 * MINUTE_IN_SECONDS
        );
        wp_safe_redirect( self::dashboard_url( $geo ) );
        exit;
    }
}
