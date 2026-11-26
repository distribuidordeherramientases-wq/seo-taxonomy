<?php
/**
 * ChatGPT-attributed website traffic, based on the existing GA4 connection.
 *
 * ChatGPT Search does not publish organic query, impression or click-through
 * reports to website owners. This is referral attribution, not a Search API.
 *
 * @package TaxoCommerce_Google_Bing
 */

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Insights_ChatGPT_Search {
    const STORE = 'tgb_chatgpt_search_snapshots_v1';

    public static function register() {
        add_action( 'admin_post_tgb_chatgpt_refresh', array( __CLASS__, 'refresh' ) );
    }

    public static function periods() {
        // A conservative three-day delay avoids mixing partially processed dates.
        $end = ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '-3 days' );
        $start = $end->modify( '-27 days' );
        return array(
            'start'          => $start->format( 'Y-m-d' ),
            'end'            => $end->format( 'Y-m-d' ),
            'previous_start' => $start->modify( '-28 days' )->format( 'Y-m-d' ),
            'previous_end'   => $start->modify( '-1 day' )->format( 'Y-m-d' ),
        );
    }

    public static function snapshots() {
        $stored = get_option( self::STORE, array() );
        return is_array( $stored ) ? $stored : array();
    }

    public static function latest() {
        $stored = self::snapshots();
        return empty( $stored ) ? null : end( $stored );
    }

    private static function ga4_filter() {
        // Session-scoped attribution only. Do not guess anonymous/direct sources.
        return array(
            'filter' => array(
                'fieldName'    => 'sessionSource',
                'inListFilter' => array(
                    'values' => array( 'chatgpt.com', 'chat.openai.com' ),
                ),
            ),
        );
    }

    private static function request( $from, $to, $landing_pages = false ) {
        $request = array(
            'dateRanges'       => array( array( 'startDate' => $from, 'endDate' => $to ) ),
            'dimensionFilter'  => self::ga4_filter(),
            'metrics'          => array(
                array( 'name' => 'sessions' ),
                array( 'name' => 'totalUsers' ),
                array( 'name' => 'engagedSessions' ),
                array( 'name' => 'screenPageViews' ),
            ),
            'limit'            => $landing_pages ? 100 : 1,
            'keepEmptyRows'    => false,
        );
        if ( $landing_pages ) {
            $request['dimensions'] = array( array( 'name' => 'landingPagePlusQueryString' ) );
            $request['orderBys'] = array( array( 'metric' => array( 'metricName' => 'sessions' ), 'desc' => true ) );
        }
        return TaxoCommerce_Google_Bing_Google_Service::analytics_run_report( $request );
    }

    private static function metrics( $response ) {
        $row = isset( $response['rows'][0]['metricValues'] ) ? $response['rows'][0]['metricValues'] : array();
        $values = array();
        foreach ( array( 'sessions', 'users', 'engaged_sessions', 'pageviews' ) as $index => $name ) {
            $values[ $name ] = isset( $row[ $index ]['value'] ) ? max( 0, (int) $row[ $index ]['value'] ) : 0;
        }
        return $values;
    }

    private static function landing_pages( $response ) {
        $pages = array();
        foreach ( (array) ( $response['rows'] ?? array() ) as $row ) {
            $raw = (string) ( $row['dimensionValues'][0]['value'] ?? '' );
            $path = strtok( $raw, '?#' );
            if ( ! is_string( $path ) || '' === $path || '/' !== substr( $path, 0, 1 ) ) {
                continue;
            }
            // Strip query parameters, fragments, and untrusted control characters.
            $path = sanitize_text_field( $path );
            if ( '' === $path || strlen( $path ) > 512 ) {
                continue;
            }
            $pages[ $path ] = ( $pages[ $path ] ?? 0 ) + max( 0, (int) ( $row['metricValues'][0]['value'] ?? 0 ) );
        }
        arsort( $pages, SORT_NUMERIC );
        return array_slice( $pages, 0, 25, true );
    }

    public static function fetch() {
        $period = self::periods();
        $current = self::request( $period['start'], $period['end'] );
        if ( is_wp_error( $current ) ) {
            return $current;
        }
        $previous = self::request( $period['previous_start'], $period['previous_end'] );
        if ( is_wp_error( $previous ) ) {
            return $previous;
        }
        $landing = self::request( $period['start'], $period['end'], true );
        if ( is_wp_error( $landing ) ) {
            return $landing;
        }
        return array(
            'period'        => $period,
            'source'        => 'Google Analytics 4 / sessionSource (ChatGPT referral)',
            'downloaded_at' => time(),
            'current'       => self::metrics( $current ),
            'previous'      => self::metrics( $previous ),
            'landing_pages' => self::landing_pages( $landing ),
            'organic_search_impressions' => null,
            'organic_search_clicks'      => null,
            'organic_search_ctr'         => null,
            'search_queries'             => null,
        );
    }

    public static function refresh() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Acceso no autorizado.', 'taxocommerce-google-bing' ) );
        }
        check_admin_referer( 'tgb_chatgpt_refresh' );
        $report = self::fetch();
        if ( is_wp_error( $report ) ) {
            set_transient(
                'tgb_chatgpt_notice_' . get_current_user_id(),
                array( 'error' => true, 'message' => $report->get_error_message() ),
                MINUTE_IN_SECONDS
            );
        } else {
            $stored = self::snapshots();
            $key = $report['period']['start'] . '/' . $report['period']['end'];
            unset( $stored[ $key ] );
            $stored[ $key ] = $report;
            update_option( self::STORE, array_slice( $stored, -10, null, true ), false );
            set_transient(
                'tgb_chatgpt_notice_' . get_current_user_id(),
                array( 'error' => false, 'message' => __( 'Datos de GA4 actualizados y guardados.', 'taxocommerce-google-bing' ) ),
                MINUTE_IN_SECONDS
            );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=taxocommerce-google-bing&tgb_tab=chatgpt_search' ) );
        exit;
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $report = self::latest();
        $period = self::periods();

        echo '<div class="tgb-chatgpt-search">';
        echo '<h2>' . esc_html__( 'ChatGPT Search — tráfico atribuido', 'taxocommerce-google-bing' ) . '</h2>';
        echo '<p>' . esc_html__( 'Esta pestaña mide visitas a tu web atribuidas a ChatGPT mediante Google Analytics 4. No es una conexión a la API de ChatGPT Search.', 'taxocommerce-google-bing' ) . '</p>';
        echo '<p><strong>' . esc_html__( 'Ventana cerrada:', 'taxocommerce-google-bing' ) . '</strong> '
            . esc_html( $period['start'] . ' / ' . $period['end'] ) . ' (28 ' . esc_html__( 'días', 'taxocommerce-google-bing' ) . '). '
            . esc_html__( 'Se excluyen los tres días más recientes para evitar datos incompletos.', 'taxocommerce-google-bing' ) . '</p>';

        $notice_key = 'tgb_chatgpt_notice_' . get_current_user_id();
        $notice = get_transient( $notice_key );
        if ( is_array( $notice ) ) {
            echo '<div class="notice ' . ( ! empty( $notice['error'] ) ? 'notice-error' : 'notice-success' ) . ' inline"><p>'
                . esc_html( (string) ( $notice['message'] ?? '' ) ) . '</p></div>';
            delete_transient( $notice_key );
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tgb_chatgpt_refresh">';
        wp_nonce_field( 'tgb_chatgpt_refresh' );
        echo '<p><button class="button button-primary" type="submit">' . esc_html__( 'Consultar GA4 y guardar datos de ChatGPT', 'taxocommerce-google-bing' ) . '</button></p></form>';

        if ( null === $report ) {
            echo '<p>' . esc_html__( 'Todavía no hay datos descargados. Usa la conexión de Google Analytics 4 ya configurada en Insights.', 'taxocommerce-google-bing' ) . '</p>';
        } else {
            $dates = $report['period'];
            echo '<p>' . esc_html__( 'Origen:', 'taxocommerce-google-bing' ) . ' '
                . esc_html( $report['source'] ) . ' · '
                . esc_html__( 'Datos descargados:', 'taxocommerce-google-bing' ) . ' '
                . esc_html( wp_date( 'd/m/Y H:i', (int) $report['downloaded_at'] ) ) . '</p>';
            echo '<p><strong>' . esc_html__( 'Periodo guardado:', 'taxocommerce-google-bing' ) . '</strong> '
                . esc_html( $dates['start'] . ' / ' . $dates['end'] ) . ' · '
                . esc_html__( 'Comparación:', 'taxocommerce-google-bing' ) . ' '
                . esc_html( $dates['previous_start'] . ' / ' . $dates['previous_end'] ) . '</p>';
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Métrica de GA4', 'taxocommerce-google-bing' )
                . '</th><th>' . esc_html__( 'Actual', 'taxocommerce-google-bing' )
                . '</th><th>' . esc_html__( 'Anterior', 'taxocommerce-google-bing' ) . '</th></tr></thead><tbody>';
            $labels = array(
                'sessions'         => __( 'Sesiones', 'taxocommerce-google-bing' ),
                'users'            => __( 'Usuarios', 'taxocommerce-google-bing' ),
                'engaged_sessions' => __( 'Sesiones con interacción', 'taxocommerce-google-bing' ),
                'pageviews'        => __( 'Páginas vistas', 'taxocommerce-google-bing' ),
            );
            foreach ( $labels as $key => $label ) {
                echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>'
                    . esc_html( number_format_i18n( (int) ( $report['current'][ $key ] ?? 0 ) ) ) . '</td><td>'
                    . esc_html( number_format_i18n( (int) ( $report['previous'][ $key ] ?? 0 ) ) ) . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '<h3>' . esc_html__( 'Páginas de entrada desde ChatGPT', 'taxocommerce-google-bing' ) . '</h3>';
            echo '<p>' . esc_html__( 'Sesiones atribuidas al origen ChatGPT. No equivalen a impresiones de respuestas ni a clics medidos por OpenAI.', 'taxocommerce-google-bing' ) . '</p>';
            if ( empty( $report['landing_pages'] ) ) {
                echo '<p>' . esc_html__( 'Sin páginas de entrada atribuidas en este periodo.', 'taxocommerce-google-bing' ) . '</p>';
            } else {
                echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Ruta de entrada', 'taxocommerce-google-bing' )
                    . '</th><th>' . esc_html__( 'Sesiones', 'taxocommerce-google-bing' ) . '</th></tr></thead><tbody>';
                foreach ( $report['landing_pages'] as $path => $count ) {
                    echo '<tr><td><a href="' . esc_url( home_url( $path ) ) . '" target="_blank" rel="noopener noreferrer">'
                        . esc_html( $path ) . '</a></td><td>' . esc_html( number_format_i18n( (int) $count ) ) . '</td></tr>';
                }
                echo '</tbody></table>';
            }
        }

        echo '<h3>' . esc_html__( 'Datos no disponibles de ChatGPT Search orgánico', 'taxocommerce-google-bing' ) . '</h3>';
        echo '<p>' . esc_html__( 'OpenAI no proporciona actualmente una API pública equivalente a Search Console con consultas de usuarios, impresiones de recomendaciones, clics orgánicos o CTR. Estas métricas no se estiman a partir de sesiones GA4.', 'taxocommerce-google-bing' ) . '</p>';
        echo '<p>' . esc_html__( 'ChatGPT Ads es publicidad y utiliza métricas distintas. La publicación de feeds o sitemaps de productos tampoco habilita por sí sola estadísticas orgánicas de ChatGPT.', 'taxocommerce-google-bing' ) . '</p>';
        echo '</div>';
    }
}
