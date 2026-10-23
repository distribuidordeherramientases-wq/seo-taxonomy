<?php

defined( 'ABSPATH' ) || exit;

/**
 * On-demand operational reports for GA4 and Bing Webmaster.
 *
 * Nothing is downloaded from the Connections tab. These requests are executed
 * only when the administrator opens the Reports tab, with a short transient
 * cache to avoid repeated API calls.
 */
final class TaxoCommerce_Google_Bing_Reports {

    const CACHE_TTL = 900;

    // Public contract for consumers (Analista, etc.): read only, no API request.
    // Individual snapshots are private WordPress options with autoload disabled.
    const STORE_PREFIX = 'tgb_manual_report_v1_';

    public static function register() {
        add_action( 'admin_post_tgb_download_report', array( __CLASS__, 'download_report' ) );
        add_action( 'admin_post_tgb_export_report', array( __CLASS__, 'export_report' ) );
    }

    public static function saved_reports( $source ) {
        if ( ! in_array( $source, array( 'analytics', 'search', 'bing' ), true ) ) {
            return array();
        }
        $value = get_option( self::STORE_PREFIX . $source, array() );
        return is_array( $value ) ? $value : array();
    }

    public static function get_saved_snapshot( $source, $start, $end ) {
        $entries = self::saved_reports( $source );
        $key = md5( $start . '|' . $end );
        return isset( $entries[ $key ] ) && is_array( $entries[ $key ] ) ? $entries[ $key ] : null;
    }

    public static function render( $source = 'analytics' ) {
        if ( ! in_array( $source, array( 'analytics', 'search', 'bing' ), true ) ) {
            return;
        }

        // GET is strictly a local read. All external API calls are in download_report().
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Filters do not mutate anything.
        $input = wp_unslash( $_GET );
        $selection = self::selection( $input );
        echo '<div class="tgb-reports">';
        if ( is_wp_error( $selection ) ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html( $selection->get_error_message() ) . '</p></div>';
            $selection = self::selection( array( 'period' => '30' ) );
        }
        $days = $selection['days'];
        $ranges = $selection['ranges'];

        $names = array(
            'analytics' => 'Google Analytics 4',
            'search'    => 'Google Search Console',
            'bing'      => 'Microsoft Bing Webmaster',
        );

        echo '<div class="tgb-report-toolbar"><div><h2>' . esc_html( $names[ $source ] ) . '</h2>';
        echo '<p>Los gráficos se generan con los últimos datos guardados. Abrir o cambiar el periodo no consulta las APIs.</p></div>';
        echo '<div class="tgb-periods">';
        foreach ( array( 7 => '7 días', 30 => '30 días', 90 => '90 días' ) as $period => $label ) {
            $url = add_query_arg(
                array( 'page' => 'taxocommerce-google-bing', 'tgb_tab' => $source, 'period' => $period ),
                admin_url( 'admin.php' )
            );
            echo '<a class="button ' . ( (string) $period === $selection['period'] ? 'button-primary' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</div></div>';
        echo '<form class="tgb-custom-range" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
        echo '<input type="hidden" name="page" value="taxocommerce-google-bing">';
        echo '<input type="hidden" name="tgb_tab" value="' . esc_attr( $source ) . '">';
        echo '<input type="hidden" name="period" value="custom">';
        echo '<label>Desde <input type="date" name="start" value="' . esc_attr( $ranges['current']['start'] ) . '" required></label>';
        echo '<label>Hasta <input type="date" name="end" value="' . esc_attr( $ranges['current']['end'] ) . '" required></label>';
        echo '<button type="submit" class="button">Aplicar fechas (sin descargar)</button>';
        echo '</form>';
        echo '<p class="description tgb-report-range">Periodo: ' . esc_html( $ranges['current']['label'] ) . ' · Comparación: ' . esc_html( $ranges['previous']['label'] ) . '. Días naturales completos; el periodo comparativo tiene la misma duración.</p>';

        echo '<form method="post" class="tgb-download-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tgb_download_report">';
        echo '<input type="hidden" name="source" value="' . esc_attr( $source ) . '">';
        echo '<input type="hidden" name="period" value="' . esc_attr( $selection['period'] ) . '">';
        if ( 'custom' === $selection['period'] ) {
            echo '<input type="hidden" name="start" value="' . esc_attr( $ranges['current']['start'] ) . '">';
            echo '<input type="hidden" name="end" value="' . esc_attr( $ranges['current']['end'] ) . '">';
        }
        wp_nonce_field( 'tgb_download_report_' . $source );
        echo '<button type="submit" class="button button-primary">Descargar informe de ' . esc_html( $names[ $source ] ) . '</button>';
        echo '</form>';

        $notice_key = 'tgb_download_notice_' . get_current_user_id();
        $notice = get_transient( $notice_key );
        if ( is_array( $notice ) && ( $notice['source'] ?? '' ) === $source ) {
            echo '<div class="notice ' . ( ! empty( $notice['error'] ) ? 'notice-error' : 'notice-success' ) . ' inline"><p>' . esc_html( (string) ( $notice['message'] ?? '' ) ) . '</p></div>';
            delete_transient( $notice_key );
        }

        $snapshot = self::get_saved_snapshot( $source, $ranges['current']['start'], $ranges['current']['end'] );
        if ( null === $snapshot ) {
            echo '<div class="notice notice-info inline"><p>Todavía no hay datos descargados para estas fechas. Pulsa «Descargar informe» para consultar la API y guardar una copia.</p></div>';
            self::styles();
            echo '</div>';
            return;
        }

        echo '<p class="description">Informe guardado el ' . esc_html( wp_date( 'd/m/Y H:i', (int) $snapshot['downloaded_at'] ) ) . '. Estos datos se muestran sin conexiones externas.</p>';
        $args = array(
            'action'  => 'tgb_export_report',
            'source'  => $source,
            'period'  => $selection['period'],
            'start'   => $ranges['current']['start'],
            'end'     => $ranges['current']['end'],
        );
        $export = wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'tgb_export_report_' . $source );
        echo '<p><a class="button" href="' . esc_url( $export ) . '">Exportar copia JSON</a></p>';

        $data = $snapshot['data'];
        if ( 'analytics' === $source ) {
            self::render_ga4( $data );
        } elseif ( 'search' === $source ) {
            self::render_gsc( $data );
        } else {
            self::render_bing( $data );
        }
        echo '<p class="description tgb-report-note">GA4 registra eventos y sesiones, no peticiones HTTP. Search Console y Bing muestran tráfico de buscadores, no visitas totales. Los periodos pueden estar incompletos por el retraso de procesamiento de cada servicio.</p>';
        self::styles();
        echo '</div>';
    }

    public static function download_report() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Acceso no autorizado.', 'taxocommerce-google-bing' ) );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below with source-specific nonce.
        $source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
        if ( ! in_array( $source, array( 'analytics', 'search', 'bing' ), true ) ) {
            wp_die( esc_html__( 'Informe no válido.', 'taxocommerce-google-bing' ) );
        }
        check_admin_referer( 'tgb_download_report_' . $source );
        $selection = self::selection( wp_unslash( $_POST ) );
        if ( is_wp_error( $selection ) ) {
            self::finish_download( $source, array( 'period' => '30' ), $selection->get_error_message(), true );
        }

        $lock = 'tgb_report_running_' . get_current_blog_id() . '_' . $source;
        if ( get_transient( $lock ) ) {
            self::finish_download( $source, $selection, 'Hay otra descarga de esta fuente en curso.', true );
        }
        set_transient( $lock, 1, 2 * MINUTE_IN_SECONDS );
        $ranges = $selection['ranges'];
        $days = $selection['days'];
        if ( 'analytics' === $source ) {
            $data = self::ga4_data( $days, $ranges );
        } elseif ( 'search' === $source ) {
            $data = TaxoCommerce_Google_Bing_GSC::download( $days, $ranges );
        } else {
            $data = self::bing_data( $days, $ranges );
        }
        delete_transient( $lock );

        if ( is_wp_error( $data ) ) {
            self::finish_download( $source, $selection, $data->get_error_message(), true );
        }
        if ( ! is_array( $data ) ) {
            self::finish_download( $source, $selection, 'La API no devolvió un informe válido.', true );
        }

        $entries = self::saved_reports( $source );
        $key = md5( $ranges['current']['start'] . '|' . $ranges['current']['end'] );
        unset( $entries[ $key ] );
        $entries = array_slice( $entries, -9, null, true );
        $entries[ $key ] = array(
            'source'        => $source,
            'schema'        => 1,
            'downloaded_at' => time(),
            'current'       => $ranges['current'],
            'previous'      => $ranges['previous'],
            'data'          => $data,
        );
        update_option( self::STORE_PREFIX . $source, $entries, false );
        do_action( 'tgb_manual_report_saved', $source, $ranges, $entries[ $key ] );
        self::finish_download( $source, $selection, 'Informe descargado y guardado correctamente.', false );
    }

    public static function export_report() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Acceso no autorizado.', 'taxocommerce-google-bing' ) );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.
        $source = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '';
        if ( ! in_array( $source, array( 'analytics', 'search', 'bing' ), true ) ) {
            wp_die( esc_html__( 'Informe no válido.', 'taxocommerce-google-bing' ) );
        }
        check_admin_referer( 'tgb_export_report_' . $source );
        $selection = self::selection( wp_unslash( $_GET ) );
        if ( is_wp_error( $selection ) ) {
            wp_die( esc_html( $selection->get_error_message() ) );
        }
        $range = $selection['ranges']['current'];
        $snapshot = self::get_saved_snapshot( $source, $range['start'], $range['end'] );
        if ( null === $snapshot ) {
            wp_die( esc_html__( 'Informe no disponible. Descárgalo primero.', 'taxocommerce-google-bing' ) );
        }
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="taxocommerce_' . $source . '_' . $range['start'] . '_' . $range['end'] . '.json"' );
        echo wp_json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Explicit JSON download.
        exit;
    }

    private static function finish_download( $source, $selection, $message, $error ) {
        set_transient(
            'tgb_download_notice_' . get_current_user_id(),
            array( 'source' => $source, 'error' => (bool) $error, 'message' => sanitize_text_field( $message ) ),
            2 * MINUTE_IN_SECONDS
        );
        $params = array(
            'page'    => 'taxocommerce-google-bing',
            'tgb_tab' => $source,
            'period'  => $selection['period'] ?? '30',
        );
        if ( 'custom' === $params['period'] ) {
            $params['start'] = $selection['ranges']['current']['start'];
            $params['end']   = $selection['ranges']['current']['end'];
        }
        wp_safe_redirect( add_query_arg( $params, admin_url( 'admin.php' ) ) );
        exit;
    }

    private static function selection( $input ) {
        $period = isset( $input['period'] ) && is_scalar( $input['period'] )
            ? sanitize_text_field( (string) $input['period'] ) : '30';

        if ( 'custom' !== $period ) {
            $days = in_array( (int) $period, array( 7, 30, 90 ), true ) ? (int) $period : 30;
            return array( 'period' => (string) $days, 'days' => $days, 'ranges' => self::ranges( $days ) );
        }

        $start = isset( $input['start'] ) && is_scalar( $input['start'] ) ? sanitize_text_field( (string) $input['start'] ) : '';
        $end = isset( $input['end'] ) && is_scalar( $input['end'] ) ? sanitize_text_field( (string) $input['end'] ) : '';
        $tz = wp_timezone();
        $from = DateTimeImmutable::createFromFormat( '!Y-m-d', $start, $tz );
        $until = DateTimeImmutable::createFromFormat( '!Y-m-d', $end, $tz );
        $max = ( new DateTimeImmutable( 'today', $tz ) )->modify( '-1 day' );

        if ( ! $from || ! $until || $from->format( 'Y-m-d' ) !== $start
            || $until->format( 'Y-m-d' ) !== $end || $until > $max || $from > $until ) {
            return new WP_Error( 'tgb_range_invalid', 'Selecciona fechas válidas, sin incluir hoy ni días futuros.' );
        }
        $days = (int) $from->diff( $until )->days + 1;
        if ( $days > 90 ) {
            return new WP_Error( 'tgb_range_large', 'El intervalo máximo por descarga es de 90 días.' );
        }
        $previous_end = $from->modify( '-1 day' );
        $previous_start = $previous_end->modify( '-' . ( $days - 1 ) . ' days' );
        return array(
            'period' => 'custom',
            'days' => $days,
            'ranges' => array(
                'current' => self::date_range( $from, $until ),
                'previous' => self::date_range( $previous_start, $previous_end ),
            ),
        );
    }

    private static function date_range( $from, $until ) {
        return array(
            'start' => $from->format( 'Y-m-d' ),
            'end' => $until->format( 'Y-m-d' ),
            'label' => $from->format( 'd/m/Y' ) . ' – ' . $until->format( 'd/m/Y' ),
        );
    }

    private static function render_gsc( $data ) {
        $current = (array) ( $data['summary']['current'] ?? array() );
        $previous = (array) ( $data['summary']['previous'] ?? array() );
        echo '<section class="tgb-report-section">';
        echo '<div class="tgb-section-head"><div><h2>Rendimiento de búsqueda en Google</h2>';
        echo '<p>Resultados web: evolución, búsquedas, páginas, países y dispositivos.</p></div><span class="tgb-source-badge">Search Console</span></div>';
        echo '<div class="tgb-kpi-grid">';
        self::kpi( 'Clics', $current['clicks'], $previous['clicks'] );
        self::kpi( 'Impresiones', $current['impressions'], $previous['impressions'] );
        self::kpi( 'CTR', $current['ctr'], $previous['ctr'], 'percent' );
        self::kpi( 'Posición media', $current['position'], $previous['position'], 'decimal', true );
        echo '</div><div class="tgb-report-grid">';
        self::line_card(
            'Evolución de clics',
            'Clics',
            $data['daily']['labels'],
            array(
                array( 'label' => 'Actual', 'values' => $data['daily']['current_clicks'] ),
                array( 'label' => 'Anterior', 'values' => $data['daily']['previous_clicks'], 'previous' => true ),
            )
        );
        self::line_card(
            'Evolución de impresiones',
            'Impresiones',
            $data['daily']['labels'],
            array(
                array( 'label' => 'Actual', 'values' => $data['daily']['current_impressions'] ),
                array( 'label' => 'Anterior', 'values' => $data['daily']['previous_impressions'], 'previous' => true ),
            )
        );
        foreach ( array( 'countries' => 'Clics por país', 'devices' => 'Clics por dispositivo' ) as $type => $title ) {
            $slice = array_slice( (array) ( $data[ $type ] ?? array() ), 0, 10 );
            $labels = array_column( $slice, 'name' );
            $values = array_column( $slice, 'clicks' );
            self::bar_card( $title, 'Clics', $labels, array( array( 'label' => 'Actual', 'values' => $values ) ) );
        }
        echo '</div>';
        self::render_rank_table( 'Principales búsquedas', (array) ( $data['queries'] ?? array() ) );
        self::render_rank_table( 'Principales páginas', (array) ( $data['pages'] ?? array() ), true );
        echo '<p class="description">' . esc_html( (string) ( $data['note'] ?? '' ) ) . '</p>';
        echo '</section>';
    }

    private static function render_rank_table( $title, $rows, $is_url = false ) {
        echo '<div class="tgb-rank-card"><h3>' . esc_html( $title ) . '</h3>';
        if ( empty( $rows ) ) {
            echo '<p>No hay registros en este periodo.</p></div>';
            return;
        }
        echo '<div class="tgb-table-scroll"><table class="widefat striped"><thead><tr>';
        echo '<th>Búsqueda / página</th><th>Clics</th><th>Impresiones</th><th>CTR</th><th>Posición media</th>';
        echo '</tr></thead><tbody>';
        foreach ( array_slice( $rows, 0, 30 ) as $item ) {
            $label = (string) ( $item['name'] ?? '' );
            $clicks = (int) ( $item['clicks'] ?? 0 );
            $impressions = (int) ( $item['impressions'] ?? 0 );
            $ctr = isset( $item['ctr'] ) ? (float) $item['ctr'] : ( $impressions > 0 ? $clicks / $impressions : 0 );
            $position = (float) ( $item['position'] ?? 0 );
            echo '<tr><td class="tgb-rank-label">';
            if ( $is_url && wp_http_validate_url( $label ) ) {
                echo '<a target="_blank" rel="noopener noreferrer" href="' . esc_url( $label ) . '">' . esc_html( $label ) . '</a>';
            } else {
                echo esc_html( $label );
            }
            echo '</td><td>' . esc_html( number_format_i18n( $clicks ) ) . '</td>';
            echo '<td>' . esc_html( number_format_i18n( $impressions ) ) . '</td>';
            echo '<td>' . esc_html( number_format_i18n( $ctr * 100, 1 ) ) . '%</td>';
            echo '<td>' . esc_html( number_format_i18n( $position, 1 ) ) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function render_ga4( $data ) {
        echo '<section class="tgb-report-section">';
        echo '<div class="tgb-section-head"><div><h2>Google Analytics 4</h2><p>Tráfico, usuarios, recurrencia, participación y procedencia geográfica.</p></div><span class="tgb-source-badge">GA4</span></div>';

        if ( is_wp_error( $data ) ) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html( $data->get_error_message() ) . '</p></div>';
            echo '</section>';
            return;
        }

        $current  = (array) $data['summary']['current'];
        $previous = (array) $data['summary']['previous'];

        echo '<div class="tgb-kpi-grid">';
        self::kpi( 'Usuarios activos', $current['activeUsers'], $previous['activeUsers'], 'number' );
        self::kpi( 'Usuarios nuevos', $current['newUsers'], $previous['newUsers'], 'number' );
        self::kpi( 'Usuarios recurrentes', $data['users']['current_returning'], $data['users']['previous_returning'], 'number' );
        self::kpi( 'Sesiones', $current['sessions'], $previous['sessions'], 'number' );
        self::kpi( 'Vistas de página', $current['screenPageViews'], $previous['screenPageViews'], 'number' );
        self::kpi( 'Tasa de interacción', $current['engagementRate'], $previous['engagementRate'], 'percent' );
        echo '</div>';

        echo '<div class="tgb-report-grid">';
        self::line_card(
            'Evolución de sesiones',
            'Sesiones',
            $data['daily']['labels'],
            array(
                array( 'label' => 'Actual', 'values' => $data['daily']['current_sessions'] ),
                array( 'label' => 'Anterior', 'values' => $data['daily']['previous_sessions'], 'previous' => true ),
            )
        );
        self::line_card(
            'Evolución de vistas',
            'Vistas',
            $data['daily']['labels'],
            array(
                array( 'label' => 'Actual', 'values' => $data['daily']['current_views'] ),
                array( 'label' => 'Anterior', 'values' => $data['daily']['previous_views'], 'previous' => true ),
            )
        );
        self::bar_card(
            'Usuarios por país',
            'Usuarios activos',
            $data['countries']['labels'],
            array(
                array( 'label' => 'Actual', 'values' => $data['countries']['current'] ),
                array( 'label' => 'Anterior', 'values' => $data['countries']['previous'], 'previous' => true ),
            )
        );
        self::bar_card(
            'Nuevos vs. recurrentes',
            'Usuarios activos',
            array( 'Nuevos', 'Recurrentes' ),
            array(
                array(
                    'label'  => 'Actual',
                    'values' => array( $data['users']['current_new'], $data['users']['current_returning'] ),
                ),
                array(
                    'label'    => 'Anterior',
                    'values'   => array( $data['users']['previous_new'], $data['users']['previous_returning'] ),
                    'previous' => true,
                ),
            )
        );
        echo '</div>';
        echo '</section>';
    }

    private static function render_bing( $data ) {
        echo '<section class="tgb-report-section">';
        echo '<div class="tgb-section-head"><div><h2>Microsoft Bing Webmaster</h2><p>Visibilidad en Bing y salud técnica de rastreo. No se muestran consultas ni páginas concretas.</p></div><span class="tgb-source-badge">Bing</span></div>';

        if ( is_wp_error( $data ) ) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html( $data->get_error_message() ) . '</p></div>';
            echo '</section>';
            return;
        }

        $current  = (array) $data['traffic']['current'];
        $previous = (array) $data['traffic']['previous'];
        $crawl_current  = (array) $data['crawl']['current'];
        $crawl_previous = (array) $data['crawl']['previous'];

        echo '<div class="tgb-kpi-grid">';
        self::kpi( 'Impresiones', $current['impressions'], $previous['impressions'], 'number' );
        self::kpi( 'Clics', $current['clicks'], $previous['clicks'], 'number' );
        self::kpi( 'CTR', $current['ctr'], $previous['ctr'], 'percent' );
        self::kpi( 'Páginas rastreadas', $crawl_current['crawled_pages'], $crawl_previous['crawled_pages'], 'number' );
        self::kpi( 'Errores 4xx + 5xx', $crawl_current['http_errors'], $crawl_previous['http_errors'], 'number', true );
        self::kpi( 'URLs en índice', $crawl_current['in_index'], $crawl_previous['in_index'], 'number' );
        echo '</div>';

        echo '<div class="tgb-report-grid">';
        self::line_card(
            'Impresiones en Bing',
            'Impresiones',
            $data['daily']['labels'],
            array(
                array( 'label' => 'Actual', 'values' => $data['daily']['current_impressions'] ),
                array( 'label' => 'Anterior', 'values' => $data['daily']['previous_impressions'], 'previous' => true ),
            )
        );
        self::line_card(
            'Clics desde Bing',
            'Clics',
            $data['daily']['labels'],
            array(
                array( 'label' => 'Actual', 'values' => $data['daily']['current_clicks'] ),
                array( 'label' => 'Anterior', 'values' => $data['daily']['previous_clicks'], 'previous' => true ),
            )
        );
        echo '</div>';

        echo '<div class="tgb-crawl-strip">';
        echo '<span><strong>2xx:</strong> ' . esc_html( number_format_i18n( $crawl_current['code2xx'] ) ) . '</span>';
        echo '<span><strong>4xx:</strong> ' . esc_html( number_format_i18n( $crawl_current['code4xx'] ) ) . '</span>';
        echo '<span><strong>5xx:</strong> ' . esc_html( number_format_i18n( $crawl_current['code5xx'] ) ) . '</span>';
        echo '<span><strong>Errores de rastreo:</strong> ' . esc_html( number_format_i18n( $crawl_current['crawl_errors'] ) ) . '</span>';
        echo '<span><strong>Bloqueadas por robots:</strong> ' . esc_html( number_format_i18n( $crawl_current['blocked_robots'] ) ) . '</span>';
        echo '<span><strong>Enlaces entrantes:</strong> ' . esc_html( number_format_i18n( $crawl_current['in_links'] ) ) . '</span>';
        echo '</div>';

        echo '</section>';
    }

    private static function ga4_data( $days, $ranges ) {
        $settings = TaxoCommerce_Google_Bing_Connections::google_settings();
        $property = preg_replace( '/\D+/', '', (string) ( $settings['analytics_property_id'] ?? '' ) );

        if ( '' === $property || empty( $settings['service_account_json'] ) ) {
            return new WP_Error( 'tgb_ga4_not_configured', 'Google Analytics no está configurado. Completa primero la conexión GA4.' );
        }

        $current_reports = self::ga4_batch( $property, $ranges['current'] );
        if ( is_wp_error( $current_reports ) ) {
            return $current_reports;
        }

        $previous_reports = self::ga4_batch( $property, $ranges['previous'] );
        if ( is_wp_error( $previous_reports ) ) {
            return $previous_reports;
        }

        if ( count( $current_reports ) < 4 || count( $previous_reports ) < 4 ) {
            return new WP_Error( 'tgb_ga4_incomplete', 'Google Analytics devolvió un lote de informes incompleto.' );
        }

        $current_summary  = self::ga4_summary( $current_reports[0] );
        $previous_summary = self::ga4_summary( $previous_reports[0] );
        $current_users    = self::ga4_user_split( $current_reports[1] );
        $previous_users   = self::ga4_user_split( $previous_reports[1] );
        $current_country  = self::ga4_country_map( $current_reports[2] );
        $previous_country = self::ga4_country_map( $previous_reports[2] );

        $axis_current  = self::axis( $ranges['current']['start'], $days );
        $axis_previous = self::axis( $ranges['previous']['start'], $days, false );
        $daily_current  = self::ga4_daily( $current_reports[3], $axis_current['dates'], $days );
        $daily_previous = self::ga4_daily( $previous_reports[3], $axis_previous['dates'], $days );

        $country_totals = $current_country;
        foreach ( $previous_country as $country => $value ) {
            $country_totals[ $country ] = ( $country_totals[ $country ] ?? 0 ) + $value;
        }
        arsort( $country_totals );
        $country_labels = array_slice( array_keys( $country_totals ), 0, 10 );

        $countries_current = array();
        $countries_previous = array();
        foreach ( $country_labels as $country ) {
            $countries_current[]  = (int) ( $current_country[ $country ] ?? 0 );
            $countries_previous[] = (int) ( $previous_country[ $country ] ?? 0 );
        }

        $out = array(
            'summary' => array(
                'current'  => $current_summary,
                'previous' => $previous_summary,
            ),
            'users' => array(
                'current_new'        => (int) $current_users['new'],
                'current_returning'  => (int) $current_users['returning'],
                'previous_new'       => (int) $previous_users['new'],
                'previous_returning' => (int) $previous_users['returning'],
            ),
            'countries' => array(
                'labels'   => $country_labels,
                'current'  => $countries_current,
                'previous' => $countries_previous,
            ),
            'daily' => array(
                'labels'              => $axis_current['labels'],
                'current_sessions'    => $daily_current['sessions'],
                'previous_sessions'   => $daily_previous['sessions'],
                'current_views'       => $daily_current['views'],
                'previous_views'      => $daily_previous['views'],
                'current_users'       => $daily_current['users'],
                'previous_users'      => $daily_previous['users'],
            ),
        );

        return $out;
    }

    private static function ga4_batch( $property, $range ) {
        $token = TaxoCommerce_Google_Bing_Connections::access_token(
            'https://www.googleapis.com/auth/analytics.readonly'
        );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $date_range = array(
            'startDate' => (string) $range['start'],
            'endDate'   => (string) $range['end'],
        );

        $requests = array(
            array(
                'dateRanges' => array( $date_range ),
                'metrics' => array(
                    array( 'name' => 'activeUsers' ),
                    array( 'name' => 'newUsers' ),
                    array( 'name' => 'sessions' ),
                    array( 'name' => 'screenPageViews' ),
                    array( 'name' => 'engagedSessions' ),
                    array( 'name' => 'engagementRate' ),
                    array( 'name' => 'eventCount' ),
                ),
                'limit' => 1,
            ),
            array(
                'dateRanges' => array( $date_range ),
                'dimensions' => array( array( 'name' => 'newVsReturning' ) ),
                'metrics'    => array( array( 'name' => 'activeUsers' ) ),
                'limit'      => 10,
            ),
            array(
                'dateRanges' => array( $date_range ),
                'dimensions' => array( array( 'name' => 'country' ) ),
                'metrics'    => array( array( 'name' => 'activeUsers' ) ),
                'orderBys'   => array(
                    array(
                        'metric' => array( 'metricName' => 'activeUsers' ),
                        'desc'   => true,
                    ),
                ),
                'limit' => 25,
            ),
            array(
                'dateRanges' => array( $date_range ),
                'dimensions' => array( array( 'name' => 'date' ) ),
                'metrics' => array(
                    array( 'name' => 'sessions' ),
                    array( 'name' => 'screenPageViews' ),
                    array( 'name' => 'activeUsers' ),
                ),
                'orderBys' => array(
                    array(
                        'dimension' => array( 'dimensionName' => 'date' ),
                        'desc'      => false,
                    ),
                ),
                'limit' => 100,
            ),
        );

        $response = wp_remote_post(
            'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode( $property ) . ':batchRunReports',
            array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ),
                'body' => wp_json_encode( array( 'requests' => $requests ) ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
            $message = 'Google Analytics respondió HTTP ' . $code . '.';
            if ( is_array( $body ) && ! empty( $body['error']['message'] ) ) {
                $message .= ' ' . sanitize_text_field( (string) $body['error']['message'] );
            }
            return new WP_Error( 'tgb_ga4_report_error', $message );
        }

        return array_values( (array) ( $body['reports'] ?? array() ) );
    }

    private static function ga4_summary( $report ) {
        $row = (array) ( $report['rows'][0] ?? array() );
        return array(
            'activeUsers'      => (int) self::row_metric( $row, 0 ),
            'newUsers'         => (int) self::row_metric( $row, 1 ),
            'sessions'         => (int) self::row_metric( $row, 2 ),
            'screenPageViews'  => (int) self::row_metric( $row, 3 ),
            'engagedSessions'  => (int) self::row_metric( $row, 4 ),
            'engagementRate'   => (float) self::row_metric( $row, 5 ),
            'eventCount'       => (int) self::row_metric( $row, 6 ),
        );
    }

    private static function ga4_user_split( $report ) {
        $out = array( 'new' => 0, 'returning' => 0 );
        foreach ( (array) ( $report['rows'] ?? array() ) as $row ) {
            $type = strtolower( trim( self::row_dimension( $row, 0 ) ) );
            $value = (int) self::row_metric( $row, 0 );
            if ( 'new' === $type ) {
                $out['new'] += $value;
            } elseif ( 'returning' === $type ) {
                $out['returning'] += $value;
            }
        }
        return $out;
    }

    private static function ga4_country_map( $report ) {
        $out = array();
        foreach ( (array) ( $report['rows'] ?? array() ) as $row ) {
            $country = trim( self::row_dimension( $row, 0 ) );
            if ( '' === $country || '(not set)' === strtolower( $country ) ) {
                $country = 'Sin identificar';
            }
            $out[ $country ] = (int) self::row_metric( $row, 0 );
        }
        return $out;
    }

    private static function ga4_daily( $report, $date_map, $days ) {
        $out = array(
            'sessions' => array_fill( 0, $days, 0 ),
            'views'    => array_fill( 0, $days, 0 ),
            'users'    => array_fill( 0, $days, 0 ),
        );

        foreach ( (array) ( $report['rows'] ?? array() ) as $row ) {
            $date = self::row_dimension( $row, 0 );
            if ( ! isset( $date_map[ $date ] ) ) {
                continue;
            }
            $index = $date_map[ $date ];
            $out['sessions'][ $index ] = (int) self::row_metric( $row, 0 );
            $out['views'][ $index ]    = (int) self::row_metric( $row, 1 );
            $out['users'][ $index ]    = (int) self::row_metric( $row, 2 );
        }

        return $out;
    }

    private static function bing_data( $days, $ranges ) {
        $settings = TaxoCommerce_Google_Bing_Connections::bing_settings();
        $key      = TaxoCommerce_Google_Bing_Connections::bing_api_key_value();

        if ( empty( $settings['enabled'] ) || '' === $key || empty( $settings['site_url'] ) ) {
            return new WP_Error( 'tgb_bing_not_configured', 'Bing Webmaster no está configurado o está desactivado.' );
        }

        $traffic_rows = self::bing_request( 'GetRankAndTrafficStats', $settings, $key );
        if ( is_wp_error( $traffic_rows ) ) {
            return $traffic_rows;
        }

        $crawl_rows = self::bing_request( 'GetCrawlStats', $settings, $key );
        if ( is_wp_error( $crawl_rows ) ) {
            return $crawl_rows;
        }

        $axis_current  = self::axis_iso( $ranges['current']['start'], $days );
        $axis_previous = self::axis_iso( $ranges['previous']['start'], $days, false );

        $daily_current_impressions  = array_fill( 0, $days, 0 );
        $daily_previous_impressions = array_fill( 0, $days, 0 );
        $daily_current_clicks       = array_fill( 0, $days, 0 );
        $daily_previous_clicks      = array_fill( 0, $days, 0 );

        $traffic = array(
            'current'  => array( 'clicks' => 0, 'impressions' => 0, 'ctr' => 0.0 ),
            'previous' => array( 'clicks' => 0, 'impressions' => 0, 'ctr' => 0.0 ),
        );

        foreach ( (array) $traffic_rows as $row ) {
            $date = self::bing_date( $row['Date'] ?? '' );
            if ( '' === $date ) {
                continue;
            }
            $clicks      = (int) ( $row['Clicks'] ?? 0 );
            $impressions = (int) ( $row['Impressions'] ?? 0 );

            if ( isset( $axis_current['dates'][ $date ] ) ) {
                $index = $axis_current['dates'][ $date ];
                $daily_current_clicks[ $index ] += $clicks;
                $daily_current_impressions[ $index ] += $impressions;
                $traffic['current']['clicks'] += $clicks;
                $traffic['current']['impressions'] += $impressions;
            } elseif ( isset( $axis_previous['dates'][ $date ] ) ) {
                $index = $axis_previous['dates'][ $date ];
                $daily_previous_clicks[ $index ] += $clicks;
                $daily_previous_impressions[ $index ] += $impressions;
                $traffic['previous']['clicks'] += $clicks;
                $traffic['previous']['impressions'] += $impressions;
            }
        }

        foreach ( array( 'current', 'previous' ) as $period ) {
            $traffic[ $period ]['ctr'] = $traffic[ $period ]['impressions'] > 0
                ? $traffic[ $period ]['clicks'] / $traffic[ $period ]['impressions']
                : 0.0;
        }

        $crawl_current  = self::bing_crawl_period( $crawl_rows, $ranges['current'] );
        $crawl_previous = self::bing_crawl_period( $crawl_rows, $ranges['previous'] );

        $out = array(
            'traffic' => $traffic,
            'daily' => array(
                'labels'               => $axis_current['labels'],
                'current_impressions'  => $daily_current_impressions,
                'previous_impressions' => $daily_previous_impressions,
                'current_clicks'       => $daily_current_clicks,
                'previous_clicks'      => $daily_previous_clicks,
            ),
            'crawl' => array(
                'current'  => $crawl_current,
                'previous' => $crawl_previous,
            ),
        );

        return $out;
    }

    private static function bing_request( $method, $settings, $key ) {
        $allowed = array( 'GetRankAndTrafficStats', 'GetCrawlStats' );
        if ( ! in_array( $method, $allowed, true ) ) {
            return new WP_Error( 'tgb_bing_method', 'Método Bing no permitido para este informe.' );
        }

        $url = add_query_arg(
            array(
                'siteUrl' => (string) $settings['site_url'],
                'apikey'  => $key,
            ),
            'https://ssl.bing.com/webmaster/api.svc/json/' . rawurlencode( $method )
        );

        $response = wp_remote_get(
            $url,
            array(
                'timeout'     => 25,
                'redirection' => 2,
                'headers'     => array( 'Accept' => 'application/json' ),
                'user-agent'  => 'TaxoCommerce-Google-Bing/' . TAXOCOMMERCE_GOOGLE_BING_VERSION,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
            return new WP_Error( 'tgb_bing_report_error', 'Bing Webmaster respondió HTTP ' . $code . '.' );
        }

        return array_values( (array) ( $body['d'] ?? array() ) );
    }

    private static function bing_crawl_period( $rows, $range ) {
        $out = array(
            'crawled_pages'  => 0,
            'code2xx'        => 0,
            'code4xx'        => 0,
            'code5xx'        => 0,
            'http_errors'    => 0,
            'crawl_errors'   => 0,
            'blocked_robots' => 0,
            'in_index'       => 0,
            'in_links'       => 0,
            'latest_date'    => '',
        );

        foreach ( (array) $rows as $row ) {
            $date = self::bing_date( $row['Date'] ?? '' );
            if ( '' === $date || $date < $range['start'] || $date > $range['end'] ) {
                continue;
            }

            $out['crawled_pages']  += (int) ( $row['CrawledPages'] ?? 0 );
            $out['code2xx']        += (int) ( $row['Code2xx'] ?? 0 );
            $out['code4xx']        += (int) ( $row['Code4xx'] ?? 0 );
            $out['code5xx']        += (int) ( $row['Code5xx'] ?? 0 );
            $out['crawl_errors']   += (int) ( $row['CrawlErrors'] ?? 0 );
            $out['blocked_robots'] += (int) ( $row['BlockedByRobotsTxt'] ?? 0 );

            if ( $date >= $out['latest_date'] ) {
                $out['latest_date'] = $date;
                $out['in_index'] = (int) ( $row['InIndex'] ?? 0 );
                $out['in_links'] = (int) ( $row['InLinks'] ?? 0 );
            }
        }

        $out['http_errors'] = $out['code4xx'] + $out['code5xx'];
        return $out;
    }

    private static function bing_date( $raw ) {
        $value = trim( (string) $raw );
        if ( '' === $value ) {
            return '';
        }

        if ( preg_match( '/\/Date\((-?[0-9]+)/', $value, $match ) ) {
            $milliseconds = (float) $match[1];
            return gmdate( 'Y-m-d', (int) floor( $milliseconds / 1000 ) );
        }

        $timestamp = strtotime( $value );
        return $timestamp ? gmdate( 'Y-m-d', $timestamp ) : '';
    }

    private static function ranges( $days ) {
        $tz = wp_timezone();
        $end = ( new DateTimeImmutable( 'today', $tz ) )->modify( '-1 day' );
        $current_start = $end->modify( '-' . ( $days - 1 ) . ' days' );
        $previous_end = $current_start->modify( '-1 day' );
        $previous_start = $previous_end->modify( '-' . ( $days - 1 ) . ' days' );

        return array(
            'current' => array(
                'start' => $current_start->format( 'Y-m-d' ),
                'end'   => $end->format( 'Y-m-d' ),
                'label' => $current_start->format( 'd/m/Y' ) . ' – ' . $end->format( 'd/m/Y' ),
            ),
            'previous' => array(
                'start' => $previous_start->format( 'Y-m-d' ),
                'end'   => $previous_end->format( 'Y-m-d' ),
                'label' => $previous_start->format( 'd/m/Y' ) . ' – ' . $previous_end->format( 'd/m/Y' ),
            ),
        );
    }

    private static function period_days() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report selector.
        $days = isset( $_GET['period'] ) ? absint( wp_unslash( $_GET['period'] ) ) : 30;
        return in_array( $days, array( 7, 30, 90 ), true ) ? $days : 30;
    }

    private static function axis( $start, $days, $labels = true ) {
        $base = new DateTimeImmutable( $start );
        $dates = array();
        $out_labels = array();

        for ( $i = 0; $i < $days; $i++ ) {
            $date = $base->modify( '+' . $i . ' days' );
            $raw  = $date->format( 'Ymd' );
            $dates[ $raw ] = $i;
            if ( $labels ) {
                $out_labels[] = self::axis_label( $date, $i, $days );
            }
        }

        return array( 'dates' => $dates, 'labels' => $out_labels );
    }

    private static function axis_iso( $start, $days, $labels = true ) {
        $base = new DateTimeImmutable( $start );
        $dates = array();
        $out_labels = array();

        for ( $i = 0; $i < $days; $i++ ) {
            $date = $base->modify( '+' . $i . ' days' );
            $raw  = $date->format( 'Y-m-d' );
            $dates[ $raw ] = $i;
            if ( $labels ) {
                $out_labels[] = self::axis_label( $date, $i, $days );
            }
        }

        return array( 'dates' => $dates, 'labels' => $out_labels );
    }

    private static function axis_label( $date, $index, $days ) {
        if ( $days <= 7 ) {
            return $date->format( 'd/m' );
        }
        $step = $days <= 30 ? 3 : 9;
        return 0 === $index % $step ? $date->format( 'd/m' ) : '';
    }

    private static function row_dimension( $row, $index ) {
        return isset( $row['dimensionValues'][ $index ]['value'] )
            ? (string) $row['dimensionValues'][ $index ]['value']
            : '';
    }

    private static function row_metric( $row, $index ) {
        return isset( $row['metricValues'][ $index ]['value'] )
            ? (float) $row['metricValues'][ $index ]['value']
            : 0.0;
    }

    private static function kpi( $label, $current, $previous, $format = 'number', $inverse = false ) {
        $delta = self::delta( $current, $previous );
        $delta_class = '';

        if ( null !== $delta ) {
            $better = $inverse ? $delta < 0 : $delta > 0;
            $worse  = $inverse ? $delta > 0 : $delta < 0;
            if ( $better ) {
                $delta_class = ' tgb-delta-good';
            } elseif ( $worse ) {
                $delta_class = ' tgb-delta-bad';
            }
        }

        echo '<div class="tgb-kpi">';
        echo '<span class="tgb-kpi-label">' . esc_html( $label ) . '</span>';
        echo '<strong>' . esc_html( self::format_value( $current, $format ) ) . '</strong>';
        echo '<span class="tgb-kpi-previous">Anterior: ' . esc_html( self::format_value( $previous, $format ) ) . '</span>';
        echo '<span class="tgb-delta' . esc_attr( $delta_class ) . '">' . esc_html( self::format_delta( $delta, $current, $previous ) ) . '</span>';
        echo '</div>';
    }

    private static function delta( $current, $previous ) {
        $current  = (float) $current;
        $previous = (float) $previous;

        if ( 0.0 === $previous ) {
            return 0.0 === $current ? 0.0 : null;
        }

        return ( ( $current - $previous ) / abs( $previous ) ) * 100;
    }

    private static function format_delta( $delta, $current, $previous ) {
        if ( null === $delta ) {
            return (float) $current > 0 && 0.0 === (float) $previous ? 'Nuevo' : '—';
        }
        $prefix = $delta > 0 ? '+' : '';
        return $prefix . number_format_i18n( $delta, 1 ) . '%';
    }

    private static function format_value( $value, $format ) {
        if ( 'percent' === $format ) {
            return number_format_i18n( (float) $value * 100, 1 ) . '%';
        }
        if ( 'decimal' === $format ) {
            return number_format_i18n( (float) $value, 1 );
        }
        return number_format_i18n( (float) $value, 0 );
    }

    private static function line_card( $title, $unit, $labels, $series ) {
        echo '<section class="tgb-chart-card">';
        echo '<h3>' . esc_html( $title ) . '</h3>';
        echo self::svg_line_chart( $labels, $series, $unit ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated SVG is escaped internally.
        echo self::legend( $series ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated legend is escaped internally.
        echo '</section>';
    }

    private static function bar_card( $title, $unit, $labels, $series ) {
        echo '<section class="tgb-chart-card">';
        echo '<h3>' . esc_html( $title ) . '</h3>';
        echo self::svg_bar_chart( $labels, $series, $unit ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated SVG is escaped internally.
        echo self::legend( $series ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated legend is escaped internally.
        echo '</section>';
    }

    private static function svg_line_chart( $labels, $series, $unit ) {
        $width  = 720;
        $height = 280;
        $left   = 48;
        $right  = 18;
        $top    = 20;
        $bottom = 42;
        $plot_w = $width - $left - $right;
        $plot_h = $height - $top - $bottom;
        $max    = 0.0;

        foreach ( $series as $item ) {
            foreach ( (array) $item['values'] as $value ) {
                $max = max( $max, (float) $value );
            }
        }
        $max = max( 1.0, $max );
        $count = max( 1, count( $labels ) );
        $palette = array( '#2271b1', '#8c8f94', '#00a32a', '#646970' );

        $svg = '<svg class="tgb-chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' . esc_attr( $unit ) . '">';
        for ( $g = 0; $g <= 4; $g++ ) {
            $y = $top + ( $plot_h * $g / 4 );
            $value = round( $max * ( 1 - $g / 4 ) );
            $svg .= '<line x1="' . $left . '" y1="' . $y . '" x2="' . ( $width - $right ) . '" y2="' . $y . '" class="tgb-gridline"/>';
            $svg .= '<text x="' . ( $left - 8 ) . '" y="' . ( $y + 4 ) . '" text-anchor="end" class="tgb-axis">' . esc_html( number_format_i18n( $value ) ) . '</text>';
        }

        foreach ( $series as $s_index => $item ) {
            $values = array_values( (array) $item['values'] );
            $points = array();
            foreach ( $values as $i => $value ) {
                $x = $left + ( $count <= 1 ? 0 : $plot_w * $i / ( $count - 1 ) );
                $y = $top + $plot_h - ( (float) $value / $max ) * $plot_h;
                $points[] = round( $x, 2 ) . ',' . round( $y, 2 );
            }

            $dash  = ! empty( $item['previous'] ) ? ' stroke-dasharray="7 5"' : '';
            $color = $palette[ $s_index % count( $palette ) ];
            $svg .= '<polyline points="' . esc_attr( implode( ' ', $points ) ) . '" fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="2.4"' . $dash . '/>';
        }

        foreach ( $labels as $i => $label ) {
            if ( '' === $label ) {
                continue;
            }
            $x = $left + ( $count <= 1 ? 0 : $plot_w * $i / ( $count - 1 ) );
            $svg .= '<text x="' . round( $x, 2 ) . '" y="' . ( $height - 14 ) . '" text-anchor="middle" class="tgb-axis">' . esc_html( $label ) . '</text>';
        }

        return $svg . '</svg>';
    }

    private static function svg_bar_chart( $labels, $series, $unit ) {
        $width  = 720;
        $height = 300;
        $left   = 48;
        $right  = 18;
        $top    = 20;
        $bottom = 78;
        $plot_w = $width - $left - $right;
        $plot_h = $height - $top - $bottom;
        $max    = 0.0;

        foreach ( $series as $item ) {
            foreach ( (array) $item['values'] as $value ) {
                $max = max( $max, (float) $value );
            }
        }

        $max          = max( 1.0, $max );
        $groups       = max( 1, count( $labels ) );
        $series_count = max( 1, count( $series ) );
        $group_w      = $plot_w / $groups;
        $bar_w        = min( 24, max( 5, ( $group_w - 8 ) / $series_count ) );
        $palette      = array( '#2271b1', '#8c8f94', '#00a32a', '#646970' );

        $svg = '<svg class="tgb-chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' . esc_attr( $unit ) . '">';
        for ( $g = 0; $g <= 4; $g++ ) {
            $y = $top + ( $plot_h * $g / 4 );
            $value = round( $max * ( 1 - $g / 4 ) );
            $svg .= '<line x1="' . $left . '" y1="' . $y . '" x2="' . ( $width - $right ) . '" y2="' . $y . '" class="tgb-gridline"/>';
            $svg .= '<text x="' . ( $left - 8 ) . '" y="' . ( $y + 4 ) . '" text-anchor="end" class="tgb-axis">' . esc_html( number_format_i18n( $value ) ) . '</text>';
        }

        foreach ( $labels as $i => $label ) {
            $center = $left + $group_w * $i + $group_w / 2;
            foreach ( $series as $s_index => $item ) {
                $value = (float) ( $item['values'][ $i ] ?? 0 );
                $h     = $value > 0 ? max( 1, ( $value / $max ) * $plot_h ) : 0;
                $x     = $center - ( $bar_w * $series_count / 2 ) + $bar_w * $s_index;
                $y     = $top + $plot_h - $h;
                $color = $palette[ $s_index % count( $palette ) ];
                $opacity = ! empty( $item['previous'] ) ? '0.55' : '1';
                $svg .= '<rect x="' . round( $x, 2 ) . '" y="' . round( $y, 2 ) . '" width="' . round( $bar_w - 1, 2 ) . '" height="' . round( $h, 2 ) . '" fill="' . esc_attr( $color ) . '" opacity="' . esc_attr( $opacity ) . '"/>';
            }
            $svg .= '<text transform="translate(' . round( $center - 2, 2 ) . ',' . ( $height - 12 ) . ') rotate(-38)" text-anchor="end" class="tgb-axis">' . esc_html( $label ) . '</text>';
        }

        return $svg . '</svg>';
    }

    private static function legend( $series ) {
        $palette = array( '#2271b1', '#8c8f94', '#00a32a', '#646970' );
        $html = '<div class="tgb-chart-legend">';

        foreach ( $series as $index => $item ) {
            $html .= '<span><i style="background:' . esc_attr( $palette[ $index % count( $palette ) ] ) . ';opacity:' . ( ! empty( $item['previous'] ) ? '0.55' : '1' ) . '"></i>' . esc_html( $item['label'] ) . '</span>';
        }

        return $html . '</div>';
    }

    private static function styles() {
        echo '<style>
        .tgb-report-toolbar{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin:20px 0 8px}
        .tgb-report-toolbar h2{margin:0 0 5px}.tgb-report-toolbar p{margin:0;color:#646970}
        .tgb-periods{display:flex;gap:6px;flex-wrap:wrap}.tgb-report-range{margin:8px 0 18px}
        .tgb-report-section{margin:18px 0 26px}.tgb-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:12px}
        .tgb-section-head h2{margin:0 0 4px}.tgb-section-head p{margin:0;color:#646970}
        .tgb-source-badge{display:inline-block;background:#f0f0f1;border-radius:999px;padding:5px 10px;font-weight:600;font-size:12px}
        .tgb-kpi-grid{display:grid;grid-template-columns:repeat(6,minmax(130px,1fr));gap:10px;margin:12px 0 16px}
        .tgb-kpi{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px;display:flex;flex-direction:column;gap:4px}
        .tgb-kpi-label{font-size:12px;color:#646970}.tgb-kpi strong{font-size:23px;line-height:1.1}.tgb-kpi-previous{font-size:11px;color:#8c8f94}
        .tgb-delta{font-size:12px;font-weight:600;color:#646970}.tgb-delta-good{color:#008a20}.tgb-delta-bad{color:#b32d2e}
        .tgb-report-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
        .tgb-chart-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;min-width:0}.tgb-chart-card h3{margin:0 0 10px;font-size:15px}
        .tgb-chart{display:block;width:100%;height:auto;min-height:220px}.tgb-gridline{stroke:#dcdcde;stroke-width:1}.tgb-axis{fill:#646970;font-size:10px}
        .tgb-chart-legend{display:flex;gap:12px;flex-wrap:wrap;margin-top:8px;font-size:12px;color:#50575e}.tgb-chart-legend span{display:inline-flex;align-items:center;gap:5px}.tgb-chart-legend i{display:inline-block;width:16px;height:3px;border-radius:2px}
        .tgb-crawl-strip{display:flex;gap:16px;flex-wrap:wrap;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;margin-top:14px;font-size:12px}
        .tgb-report-note{margin-top:16px}
        .tgb-custom-range{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:16px 0 10px}
        .tgb-custom-range label{display:inline-flex;align-items:center;gap:6px}
        .tgb-download-form{margin:14px 0}
        .tgb-rank-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin:16px 0}
        .tgb-rank-card h3{margin:0 0 10px;font-size:15px}
        .tgb-table-scroll{max-width:100%;overflow-x:auto}
        .tgb-rank-label{min-width:200px;word-break:break-word;max-width:550px}
        @media(max-width:1250px){.tgb-kpi-grid{grid-template-columns:repeat(3,minmax(130px,1fr))}}
        @media(max-width:1000px){.tgb-report-grid{grid-template-columns:1fr}}
        @media(max-width:782px){.tgb-report-toolbar,.tgb-section-head{align-items:flex-start;flex-direction:column}.tgb-kpi-grid{grid-template-columns:repeat(2,minmax(120px,1fr))}}
        @media(max-width:480px){.tgb-kpi-grid{grid-template-columns:1fr}}
        </style>';
    }
}
