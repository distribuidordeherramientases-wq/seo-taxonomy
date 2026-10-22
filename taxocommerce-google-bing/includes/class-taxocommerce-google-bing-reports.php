<?php

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Google_Bing_Reports {
    const CACHE_TTL = 900;

    public static function render() {
        $days = self::period_days();
        $data = self::data( $days );

        echo '<div class="tgb-reports">';
        echo '<div class="tgb-report-toolbar">';
        echo '<div><h2>Informes de Google</h2><p>Vista gráfica resumida de Google Analytics. El análisis detallado queda en Analista.</p></div>';
        echo '<div class="tgb-periods">';
        foreach ( array( 7 => '1 semana', 30 => '1 mes', 90 => '3 meses' ) as $value => $label ) {
            $url = add_query_arg(
                array(
                    'page'    => 'taxocommerce-google-bing',
                    'tgb_tab' => 'reports',
                    'period'  => $value,
                ),
                admin_url( 'admin.php' )
            );
            echo '<a class="button ' . ( $days === $value ? 'button-primary' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</div></div>';

        if ( is_wp_error( $data ) ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html( $data->get_error_message() ) . '</p></div>';
            echo '</div>';
            return;
        }

        echo '<p class="description tgb-report-range">Actual: ' . esc_html( $data['ranges']['current']['label'] ) . ' · Anterior: ' . esc_html( $data['ranges']['previous']['label'] ) . '</p>';

        echo '<div class="tgb-report-grid">';
        self::line_card(
            'Usuarios nuevos y recurrentes',
            'Usuarios',
            $data['users']['labels'],
            array(
                array( 'label' => 'Nuevos · actual', 'values' => $data['users']['current_new'] ),
                array( 'label' => 'Recurrentes · actual', 'values' => $data['users']['current_returning'] ),
                array( 'label' => 'Nuevos · anterior', 'values' => $data['users']['previous_new'], 'previous' => true ),
                array( 'label' => 'Recurrentes · anterior', 'values' => $data['users']['previous_returning'], 'previous' => true ),
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

        self::line_card(
            'Visitas a páginas',
            'Vistas',
            $data['pages']['labels'],
            array(
                array( 'label' => 'Actual', 'values' => $data['pages']['current'] ),
                array( 'label' => 'Anterior', 'values' => $data['pages']['previous'], 'previous' => true ),
            )
        );

        self::line_card(
            'Visitas a posts',
            'Vistas',
            $data['posts']['labels'],
            array(
                array( 'label' => 'Actual', 'values' => $data['posts']['current'] ),
                array( 'label' => 'Anterior', 'values' => $data['posts']['previous'], 'previous' => true ),
            )
        );

        self::line_card(
            'Compras realizadas',
            'Compras',
            $data['purchases']['labels'],
            array(
                array( 'label' => 'Actual', 'values' => $data['purchases']['current'] ),
                array( 'label' => 'Anterior', 'values' => $data['purchases']['previous'], 'previous' => true ),
            )
        );

        self::line_card(
            'Búsquedas realizadas',
            'Búsquedas',
            $data['searches']['labels'],
            array(
                array( 'label' => 'Actual', 'values' => $data['searches']['current'] ),
                array( 'label' => 'Anterior', 'values' => $data['searches']['previous'], 'previous' => true ),
            )
        );
        echo '</div>';

        echo '<p class="description tgb-report-note">“Búsquedas realizadas” usa el evento GA4 <code>view_search_results</code>. Páginas y posts se clasifican contrastando <code>pagePath</code> con los contenidos publicados de WordPress.</p>';
        self::styles();
        echo '</div>';
    }

    private static function period_days() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report selector.
        $days = isset( $_GET['period'] ) ? absint( wp_unslash( $_GET['period'] ) ) : 30;
        return in_array( $days, array( 7, 30, 90 ), true ) ? $days : 30;
    }

    private static function data( $days ) {
        $settings = TaxoCommerce_Google_Bing_Connections::google_settings();
        if ( empty( $settings['analytics_property_id'] ) || empty( $settings['service_account_json'] ) ) {
            return new WP_Error( 'tgb_reports_not_configured', 'Configura primero Google Analytics en la pestaña Conexiones.' );
        }

        $cache_key = 'tgb_reports_v1_' . get_current_blog_id() . '_' . $days;
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $ranges = self::ranges( $days );
        $full = array(
            'startDate' => $ranges['previous']['start'],
            'endDate'   => $ranges['current']['end'],
        );

        $users_report = TaxoCommerce_Google_Bing_Connections::google_analytics_report(
            array(
                'dateRanges' => array( $full ),
                'dimensions' => array(
                    array( 'name' => 'date' ),
                    array( 'name' => 'newVsReturning' ),
                ),
                'metrics' => array(
                    array( 'name' => 'activeUsers' ),
                ),
                'limit' => 10000,
            )
        );
        if ( is_wp_error( $users_report ) ) {
            return $users_report;
        }

        $countries_report = TaxoCommerce_Google_Bing_Connections::google_analytics_report(
            array(
                'dateRanges' => array( $full ),
                'dimensions' => array(
                    array( 'name' => 'date' ),
                    array( 'name' => 'country' ),
                ),
                'metrics' => array(
                    array( 'name' => 'activeUsers' ),
                ),
                'limit' => 50000,
            )
        );
        if ( is_wp_error( $countries_report ) ) {
            return $countries_report;
        }

        $content_report = TaxoCommerce_Google_Bing_Connections::google_analytics_report(
            array(
                'dateRanges' => array( $full ),
                'dimensions' => array(
                    array( 'name' => 'date' ),
                    array( 'name' => 'pagePath' ),
                ),
                'metrics' => array(
                    array( 'name' => 'screenPageViews' ),
                ),
                'limit' => 100000,
            )
        );
        if ( is_wp_error( $content_report ) ) {
            return $content_report;
        }

        $events_report = TaxoCommerce_Google_Bing_Connections::google_analytics_report(
            array(
                'dateRanges' => array( $full ),
                'dimensions' => array(
                    array( 'name' => 'date' ),
                    array( 'name' => 'eventName' ),
                ),
                'metrics' => array(
                    array( 'name' => 'eventCount' ),
                ),
                'dimensionFilter' => array(
                    'filter' => array(
                        'fieldName' => 'eventName',
                        'inListFilter' => array(
                            'values' => array( 'purchase', 'view_search_results' ),
                        ),
                    ),
                ),
                'limit' => 10000,
            )
        );
        if ( is_wp_error( $events_report ) ) {
            return $events_report;
        }

        $date_axis = self::axis( $ranges['current']['start'], $days );
        $previous_dates = self::axis( $ranges['previous']['start'], $days, false );
        $out = array(
            'ranges' => $ranges,
            'users' => array(
                'labels' => $date_axis['labels'],
                'current_new' => array_fill( 0, $days, 0 ),
                'current_returning' => array_fill( 0, $days, 0 ),
                'previous_new' => array_fill( 0, $days, 0 ),
                'previous_returning' => array_fill( 0, $days, 0 ),
            ),
            'countries' => array( 'labels' => array(), 'current' => array(), 'previous' => array() ),
            'pages' => array(
                'labels' => $date_axis['labels'],
                'current' => array_fill( 0, $days, 0 ),
                'previous' => array_fill( 0, $days, 0 ),
            ),
            'posts' => array(
                'labels' => $date_axis['labels'],
                'current' => array_fill( 0, $days, 0 ),
                'previous' => array_fill( 0, $days, 0 ),
            ),
            'purchases' => array(
                'labels' => $date_axis['labels'],
                'current' => array_fill( 0, $days, 0 ),
                'previous' => array_fill( 0, $days, 0 ),
            ),
            'searches' => array(
                'labels' => $date_axis['labels'],
                'current' => array_fill( 0, $days, 0 ),
                'previous' => array_fill( 0, $days, 0 ),
            ),
        );

        foreach ( (array) ( $users_report['rows'] ?? array() ) as $row ) {
            $date = self::row_dimension( $row, 0 );
            $type = strtolower( self::row_dimension( $row, 1 ) );
            $value = (int) self::row_metric( $row, 0 );
            $slot = self::slot( $date, $date_axis['dates'], $previous_dates['dates'] );
            if ( ! $slot ) {
                continue;
            }
            $key = false !== strpos( $type, 'new' ) ? 'new' : 'returning';
            $target = $slot['period'] . '_' . $key;
            $out['users'][ $target ][ $slot['index'] ] += $value;
        }

        $country_current = array();
        $country_previous = array();
        foreach ( (array) ( $countries_report['rows'] ?? array() ) as $row ) {
            $date = self::row_dimension( $row, 0 );
            $country = trim( self::row_dimension( $row, 1 ) );
            $value = (int) self::row_metric( $row, 0 );
            if ( '' === $country ) {
                $country = 'Sin identificar';
            }
            if ( isset( $date_axis['dates'][ $date ] ) ) {
                $country_current[ $country ] = ( $country_current[ $country ] ?? 0 ) + $value;
            } elseif ( isset( $previous_dates['dates'][ $date ] ) ) {
                $country_previous[ $country ] = ( $country_previous[ $country ] ?? 0 ) + $value;
            }
        }
        $country_totals = $country_current;
        foreach ( $country_previous as $country => $value ) {
            $country_totals[ $country ] = ( $country_totals[ $country ] ?? 0 ) + $value;
        }
        arsort( $country_totals );
        $countries = array_slice( array_keys( $country_totals ), 0, 10 );
        $out['countries']['labels'] = $countries;
        foreach ( $countries as $country ) {
            $out['countries']['current'][] = (int) ( $country_current[ $country ] ?? 0 );
            $out['countries']['previous'][] = (int) ( $country_previous[ $country ] ?? 0 );
        }

        $content_map = self::content_path_map();
        foreach ( (array) ( $content_report['rows'] ?? array() ) as $row ) {
            $date = self::row_dimension( $row, 0 );
            $path = self::normalize_path( self::row_dimension( $row, 1 ) );
            $value = (int) self::row_metric( $row, 0 );
            if ( ! isset( $content_map[ $path ] ) ) {
                continue;
            }
            $slot = self::slot( $date, $date_axis['dates'], $previous_dates['dates'] );
            if ( ! $slot ) {
                continue;
            }
            $kind = $content_map[ $path ];
            if ( 'page' === $kind ) {
                $out['pages'][ $slot['period'] ][ $slot['index'] ] += $value;
            } elseif ( 'post' === $kind ) {
                $out['posts'][ $slot['period'] ][ $slot['index'] ] += $value;
            }
        }

        foreach ( (array) ( $events_report['rows'] ?? array() ) as $row ) {
            $date = self::row_dimension( $row, 0 );
            $event = self::row_dimension( $row, 1 );
            $value = (int) self::row_metric( $row, 0 );
            $slot = self::slot( $date, $date_axis['dates'], $previous_dates['dates'] );
            if ( ! $slot ) {
                continue;
            }
            if ( 'purchase' === $event ) {
                $out['purchases'][ $slot['period'] ][ $slot['index'] ] += $value;
            } elseif ( 'view_search_results' === $event ) {
                $out['searches'][ $slot['period'] ][ $slot['index'] ] += $value;
            }
        }

        set_transient( $cache_key, $out, self::CACHE_TTL );
        return $out;
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

    private static function axis( $start, $days, $labels = true ) {
        $base = new DateTimeImmutable( $start );
        $dates = array();
        $out_labels = array();
        for ( $i = 0; $i < $days; $i++ ) {
            $date = $base->modify( '+' . $i . ' days' );
            $raw = $date->format( 'Ymd' );
            $dates[ $raw ] = $i;
            if ( $labels ) {
                $out_labels[] = $days <= 7 ? $date->format( 'd/m' ) : ( 0 === $i % ( $days <= 30 ? 3 : 9 ) ? $date->format( 'd/m' ) : '' );
            }
        }
        return array( 'dates' => $dates, 'labels' => $out_labels );
    }

    private static function slot( $date, $current, $previous ) {
        if ( isset( $current[ $date ] ) ) {
            return array( 'period' => 'current', 'index' => $current[ $date ] );
        }
        if ( isset( $previous[ $date ] ) ) {
            return array( 'period' => 'previous', 'index' => $previous[ $date ] );
        }
        return null;
    }

    private static function content_path_map() {
        $cache_key = 'tgb_content_path_map_v1_' . get_current_blog_id();
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $map = array();
        foreach ( array( 'page', 'post' ) as $type ) {
            $ids = get_posts(
                array(
                    'post_type'              => $type,
                    'post_status'            => 'publish',
                    'posts_per_page'         => -1,
                    'fields'                 => 'ids',
                    'orderby'                => 'ID',
                    'order'                  => 'ASC',
                    'no_found_rows'          => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                )
            );
            foreach ( $ids as $id ) {
                $url = get_permalink( $id );
                if ( ! $url ) {
                    continue;
                }
                $map[ self::normalize_path( $url ) ] = $type;
            }
        }

        set_transient( $cache_key, $map, HOUR_IN_SECONDS );
        return $map;
    }

    private static function normalize_path( $url_or_path ) {
        $path = wp_parse_url( (string) $url_or_path, PHP_URL_PATH );
        if ( ! is_string( $path ) || '' === $path ) {
            $path = (string) $url_or_path;
        }
        $path = '/' . ltrim( $path, '/' );
        return '/' === $path ? '/' : untrailingslashit( $path );
    }

    private static function row_dimension( $row, $index ) {
        return isset( $row['dimensionValues'][ $index ]['value'] ) ? (string) $row['dimensionValues'][ $index ]['value'] : '';
    }

    private static function row_metric( $row, $index ) {
        return isset( $row['metricValues'][ $index ]['value'] ) ? (float) $row['metricValues'][ $index ]['value'] : 0;
    }

    private static function line_card( $title, $unit, $labels, $series ) {
        echo '<section class="tgb-chart-card">';
        echo '<h3>' . esc_html( $title ) . '</h3>';
        echo self::svg_line_chart( $labels, $series, $unit ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated SVG is escaped internally.
        echo self::legend( $series ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Legend labels are escaped internally.
        echo '</section>';
    }

    private static function bar_card( $title, $unit, $labels, $series ) {
        echo '<section class="tgb-chart-card">';
        echo '<h3>' . esc_html( $title ) . '</h3>';
        echo self::svg_bar_chart( $labels, $series, $unit ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated SVG is escaped internally.
        echo self::legend( $series ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Legend labels are escaped internally.
        echo '</section>';
    }

    private static function svg_line_chart( $labels, $series, $unit ) {
        $width = 720;
        $height = 280;
        $left = 48;
        $right = 18;
        $top = 20;
        $bottom = 42;
        $plot_w = $width - $left - $right;
        $plot_h = $height - $top - $bottom;
        $max = 0;
        foreach ( $series as $item ) {
            foreach ( (array) $item['values'] as $value ) {
                $max = max( $max, (float) $value );
            }
        }
        $max = max( 1, $max );
        $count = max( 1, count( $labels ) );
        $palette = array( '#2271b1', '#00a32a', '#8c8f94', '#646970' );

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
            $dash = ! empty( $item['previous'] ) ? ' stroke-dasharray="7 5"' : '';
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
        $svg .= '</svg>';
        return $svg;
    }

    private static function svg_bar_chart( $labels, $series, $unit ) {
        $width = 720;
        $height = 300;
        $left = 48;
        $right = 18;
        $top = 20;
        $bottom = 78;
        $plot_w = $width - $left - $right;
        $plot_h = $height - $top - $bottom;
        $max = 0;
        foreach ( $series as $item ) {
            foreach ( (array) $item['values'] as $value ) {
                $max = max( $max, (float) $value );
            }
        }
        $max = max( 1, $max );
        $groups = max( 1, count( $labels ) );
        $series_count = max( 1, count( $series ) );
        $group_w = $plot_w / $groups;
        $bar_w = min( 24, max( 5, ( $group_w - 8 ) / $series_count ) );
        $palette = array( '#2271b1', '#8c8f94', '#00a32a', '#646970' );

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
                $h = $value > 0 ? max( 1, ( $value / $max ) * $plot_h ) : 0;
                $x = $center - ( $bar_w * $series_count / 2 ) + $bar_w * $s_index;
                $y = $top + $plot_h - $h;
                $color = $palette[ $s_index % count( $palette ) ];
                $opacity = ! empty( $item['previous'] ) ? '0.55' : '1';
                $svg .= '<rect x="' . round( $x, 2 ) . '" y="' . round( $y, 2 ) . '" width="' . round( $bar_w - 1, 2 ) . '" height="' . round( $h, 2 ) . '" fill="' . esc_attr( $color ) . '" opacity="' . esc_attr( $opacity ) . '"/>';
            }
            $svg .= '<text transform="translate(' . round( $center - 2, 2 ) . ',' . ( $height - 12 ) . ') rotate(-38)" text-anchor="end" class="tgb-axis">' . esc_html( $label ) . '</text>';
        }
        $svg .= '</svg>';
        return $svg;
    }

    private static function legend( $series ) {
        $palette = array( '#2271b1', '#00a32a', '#8c8f94', '#646970' );
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
        .tgb-periods{display:flex;gap:6px;flex-wrap:wrap}
        .tgb-report-range{margin:8px 0 18px}
        .tgb-report-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
        .tgb-chart-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;min-width:0}
        .tgb-chart-card h3{margin:0 0 10px;font-size:15px}
        .tgb-chart{display:block;width:100%;height:auto;min-height:220px}
        .tgb-gridline{stroke:#dcdcde;stroke-width:1}.tgb-axis{fill:#646970;font-size:10px}
        .tgb-chart-legend{display:flex;gap:12px;flex-wrap:wrap;margin-top:8px;font-size:12px;color:#50575e}
        .tgb-chart-legend span{display:inline-flex;align-items:center;gap:5px}.tgb-chart-legend i{display:inline-block;width:16px;height:3px;border-radius:2px}
        .tgb-report-note{margin-top:16px}
        @media(max-width:1000px){.tgb-report-grid{grid-template-columns:1fr}}
        @media(max-width:782px){.tgb-report-toolbar{align-items:flex-start;flex-direction:column}}
        </style>';
    }
}
