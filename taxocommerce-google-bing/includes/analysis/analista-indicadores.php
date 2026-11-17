<?php
/**
 * Objective signals for the Insights Analysis report.
 *
 * The calendar periods and measurements are evidence, not an action plan.
 * This module uses Insights' existing GSC and GA4 adapters, independently of
 * other TaxoCommerce plugins. No content or product data is changed.
 *
 * @package TaxoCommerce_Google_Bing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Only closed calendar weeks or calendar months are comparable.
 *
 * @param string $mode Period selector.
 * @return string
 */
function taxocommerce_insights_indicators_mode( $mode ) {
    return 'month' === $mode ? 'month' : 'week';
}

/**
 * @param string $value YYYY-MM-DD.
 * @return DateTimeImmutable|null
 */
function taxocommerce_insights_indicators_date( $value ) {
    if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
        return null;
    }
    $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
    $date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $timezone );
    return $date && $date->format( 'Y-m-d' ) === $value ? $date : null;
}

/**
 * Calendar periods, newest first. $source_end values are metadata cutoffs,
 * not a claim that every day within the range has been imported.
 *
 * @param string $mode      'week' or 'month'.
 * @param string $gsc_end   Last actual local GSC date, if any.
 * @param string $ga4_end   Latest possible closed GA4 day (normally yesterday).
 * @param int    $count     History length.
 * @return array
 */
function taxocommerce_insights_indicators_periods( $mode, $gsc_end, $ga4_end, $count = 4 ) {
    $mode = taxocommerce_insights_indicators_mode( $mode );
    $gsc = taxocommerce_insights_indicators_date( $gsc_end );
    $ga4 = taxocommerce_insights_indicators_date( $ga4_end );
    if ( ! $gsc && ! $ga4 ) {
        return array();
    }
    $anchor = $gsc && $ga4 ? ( $gsc < $ga4 ? $gsc : $ga4 ) : ( $gsc ?: $ga4 );
    $now = new DateTimeImmutable( 'today', function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' ) );
    if ( $anchor >= $now ) {
        $anchor = $now->modify( '-1 day' );
    }

    if ( 'week' === $mode ) {
        // Monday-Sunday; on Monday select the Sunday immediately before it.
        $last_end = $anchor->modify( '-' . ( (int) $anchor->format( 'N' ) % 7 ) . ' days' );
        $first_start = $last_end->modify( '-6 days' );
    } else {
        // Starting from the first day avoids month-end overflow on 29-31.
        $month_start = $anchor->modify( 'first day of this month' );
        $month_end = $month_start->modify( '+1 month -1 day' );
        $last_end = $anchor->format( 'Y-m-d' ) === $month_end->format( 'Y-m-d' )
            ? $month_end : $month_start->modify( '-1 day' );
        $first_start = $last_end->modify( 'first day of this month' );
    }

    $result = array();
    for ( $i = 0; $i < max( 1, min( 8, absint( $count ) ) ); $i++ ) {
        if ( 'week' === $mode ) {
            $start = $first_start->modify( '-' . ( 7 * $i ) . ' days' );
            $end = $start->modify( '+6 days' );
        } else {
            $start = $first_start->modify( '-' . $i . ' months' );
            $end = $start->modify( '+1 month -1 day' );
        }
        $result[] = array(
            'date_from' => $start->format( 'Y-m-d' ),
            'date_to'   => $end->format( 'Y-m-d' ),
            'label'     => $start->format( 'd/m/Y' ) . ' – ' . $end->format( 'd/m/Y' ),
            'days'      => (int) $start->diff( $end )->days + 1,
        );
    }
    return $result;
}

/**
 * Get GSC totals only when every daily property total is available.
 * A partial GSC snapshot remains explicit and has no comparable metrics.
 *
 * @param string $property GSC property identifier.
 * @param array  $period   Calendar interval.
 * @return array
 */
function taxocommerce_insights_indicators_gsc( $property, array $period ) {
    $out = array( 'state' => 'unavailable', 'source' => 'Google Search Console', 'metrics' => null, 'coverage' => null );
    if ( '' === $property || ! function_exists( 'seo_analista_period_metrics' ) ) {
        return $out;
    }
    $row = (array) seo_analista_period_metrics( $property, $period['date_from'], $period['date_to'] );
    if ( ! $row ) {
        return $out;
    }
    $expected = (int) ( $row['days_expected'] ?? $period['days'] );
    $available = (int) ( $row['days_available'] ?? 0 );
    $source = (string) ( $row['source'] ?? '' );
    $out['coverage'] = array(
        'days_expected' => $expected,
        'days_available' => $available,
        'data_source' => $source,
    );
    if ( 'gsc_property_web_final' !== $source || $expected <= 0 || $available !== $expected ) {
        $out['state'] = 'partial';
        return $out;
    }
    $impressions = max( 0.0, (float) ( $row['impressions'] ?? 0 ) );
    $clicks = max( 0.0, (float) ( $row['clicks'] ?? 0 ) );
    $out['state'] = 'available';
    $out['metrics'] = array(
        'impressions' => $impressions,
        'clicks'      => $clicks,
        'ctr_pct'     => $impressions > 0 ? round( 100 * $clicks / $impressions, 2 ) : null,
        'position'    => isset( $row['position'] ) && $impressions > 0 ? round( (float) $row['position'], 2 ) : null,
    );
    return $out;
}

/**
 * Exact calendar-date GA4 totals via the existing connection. No 28-day
 * fallback, no inference that missing purchase events equal actual sales.
 *
 * @param array $period Calendar interval.
 * @return array
 */
function taxocommerce_insights_indicators_ga4( array $period ) {
    $empty = array(
        'state' => 'unavailable',
        'source' => 'Google Analytics 4',
        'metrics' => null,
        'events' => null,
    );
    if ( ! function_exists( 'seo_google_analytics_run_report' ) ) {
        return $empty;
    }
    $cache_key = 'tgb_indicators_ga4_v1_' . md5( $period['date_from'] . '|' . $period['date_to'] );
    if ( function_exists( 'get_transient' ) ) {
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }
    }
    $date_ranges = array( array( 'startDate' => $period['date_from'], 'endDate' => $period['date_to'] ) );
    try {
        $response = seo_google_analytics_run_report(
            array(
                'dateRanges' => $date_ranges,
                'metrics' => array(
                    array( 'name' => 'sessions' ),
                    array( 'name' => 'activeUsers' ),
                    array( 'name' => 'screenPageViews' ),
                    array( 'name' => 'ecommercePurchases' ),
                    array( 'name' => 'purchaseRevenue' ),
                ),
                'limit' => 1,
            )
        );
    } catch ( Throwable $error ) {
        return $empty;
    }
    if ( is_wp_error( $response ) || ! is_array( $response ) ) {
        return $empty;
    }
    $row = (array) ( $response['rows'][0]['metricValues'] ?? array() );
    $metric = static function ( $index ) use ( $row ) {
        return isset( $row[ $index ]['value'] ) ? (float) $row[ $index ]['value'] : 0.0;
    };
    $out = $empty;
    $out['state'] = 'available';
    $out['metrics'] = array(
        'sessions'   => (int) $metric( 0 ),
        'users'      => (int) $metric( 1 ),
        'pageviews'  => (int) $metric( 2 ),
        'purchases'  => (int) $metric( 3 ),
        'revenue'    => round( $metric( 4 ), 2 ),
    );

    // Event counts are aggregated, not unique users or true funnel cohorts.
    try {
        $events = seo_google_analytics_run_report(
            array(
                'dateRanges' => $date_ranges,
                'dimensions' => array( array( 'name' => 'eventName' ) ),
                'metrics' => array( array( 'name' => 'eventCount' ) ),
                'limit' => 250,
            )
        );
        if ( ! is_wp_error( $events ) && is_array( $events ) ) {
            $event_counts = array_fill_keys(
                array( 'view_item', 'add_to_cart', 'begin_checkout', 'purchase' ),
                0
            );
            foreach ( (array) ( $events['rows'] ?? array() ) as $event_row ) {
                $name = (string) ( $event_row['dimensionValues'][0]['value'] ?? '' );
                if ( array_key_exists( $name, $event_counts ) ) {
                    $event_counts[ $name ] = max( 0, (int) ( $event_row['metricValues'][0]['value'] ?? 0 ) );
                }
            }
            $out['events'] = $event_counts;
        } else {
            $out['state'] = 'partial';
        }
    } catch ( Throwable $error ) {
        $out['state'] = 'partial';
    }

    if ( function_exists( 'set_transient' ) ) {
        set_transient( $cache_key, $out, 30 * MINUTE_IN_SECONDS );
    }
    return $out;
}

/**
 * Comparable change: percentage remains unknown for a zero baseline.
 *
 * @param float|int|null $current Current observation.
 * @param float|int|null $previous Prior observation.
 * @return array|null
 */
function taxocommerce_insights_indicators_delta( $current, $previous ) {
    if ( ! is_numeric( $current ) || ! is_numeric( $previous ) ) {
        return null;
    }
    $delta = (float) $current - (float) $previous;
    return array(
        'delta' => $delta,
        'growth_pct' => (float) $previous > 0 ? round( 100 * $delta / (float) $previous, 2 ) : null,
        'previous_zero' => 0.0 === (float) $previous,
    );
}

/**
 * Resolve a category only when a GSC URL matches its real WP term permalink.
 * We do not classify URLs by words, which causes false positive families.
 *
 * @param string $url GSC page URL.
 * @return object|null
 */
function taxocommerce_insights_indicators_category( $url ) {
    if ( ! function_exists( 'get_term_by' ) || ! function_exists( 'get_term_link' ) ) {
        return null;
    }
    $path = (string) wp_parse_url( $url, PHP_URL_PATH );
    $parts = array_values( array_filter( explode( '/', trim( $path, '/' ) ), 'strlen' ) );
    if ( ! $parts ) {
        return null;
    }
    $term = get_term_by( 'slug', sanitize_title( rawurldecode( end( $parts ) ) ), 'product_cat' );
    if ( ! is_object( $term ) || is_wp_error( $term ) ) {
        return null;
    }
    $link = get_term_link( $term );
    if ( is_wp_error( $link ) ) {
        return null;
    }
    $canonical_path = (string) wp_parse_url( $link, PHP_URL_PATH );
    return trim( $path, '/' ) === trim( $canonical_path, '/' ) ? $term : null;
}

/**
 * Aggregate evidence on existing category URLs, without prescribing content.
 *
 * @param array $pages     Current GSC page observations.
 * @param array $old_pages Previous GSC page observations.
 * @return array
 */
function taxocommerce_insights_indicators_categories( array $pages, array $old_pages ) {
    $old = array();
    foreach ( $old_pages as $row ) {
        $url = rtrim( strtolower( (string) ( $row['page_url'] ?? '' ) ), '/' );
        if ( '' !== $url ) {
            $old[ $url ] = $row;
        }
    }
    $result = array();
    foreach ( array_slice( $pages, 0, 250 ) as $row ) {
        $url = (string) ( $row['page_url'] ?? '' );
        $term = taxocommerce_insights_indicators_category( $url );
        if ( ! $term ) {
            continue;
        }
        $impressions = max( 0, (float) ( $row['impressions'] ?? 0 ) );
        $clicks = max( 0, (float) ( $row['clicks'] ?? 0 ) );
        $position = (float) ( $row['position'] ?? 0 );
        $old_url = rtrim( strtolower( $url ), '/' );
        $previous_exists = isset( $old[ $old_url ] );
        $previous = $previous_exists ? (array) $old[ $old_url ] : array();
        $change = $previous_exists
            ? taxocommerce_insights_indicators_delta( $impressions, (float) ( $previous['impressions'] ?? 0 ) ) : null;
        $signals = array();

        if ( $impressions >= 20 && $change && $change['delta'] >= 10 ) {
            $signals[] = 'impresiones_en_crecimiento';
        }
        if ( $impressions >= 50 && 0.0 === $clicks && $position > 0 && $position <= 20 ) {
            $signals[] = 'visibilidad_sin_clics_organicos';
        }
        $products = max( 0, (int) ( $term->count ?? 0 ) );
        if ( $products > 0 && $products <= 5 && $clicks >= 2 && $position > 0 && $position <= 25 ) {
            $signals[] = 'nicho_pequeno_con_clics';
        }
        if ( ! $signals ) {
            continue;
        }
        $result[] = array(
            'entity_type' => 'product_cat',
            'entity_id' => absint( $term->term_id ),
            'name' => (string) $term->name,
            'url' => $url,
            'product_count' => $products,
            'signals' => $signals,
            'source' => 'Google Search Console / WooCommerce',
            'metrics' => array(
                'impressions' => $impressions,
                'previous_impressions' => $previous_exists ? max( 0, (float) ( $previous['impressions'] ?? 0 ) ) : null,
                'impression_change' => $change,
                'clicks' => $clicks,
                'position' => $position > 0 ? round( $position, 2 ) : null,
                'ctr_pct' => $impressions > 0 ? round( $clicks * 100 / $impressions, 2 ) : null,
            ),
        );
        if ( count( $result ) >= 40 ) {
            break;
        }
    }
    return $result;
}

/**
 * Compile four complete weeks or three complete calendar months.
 * Original source cutoffs are recorded separately from the shared window.
 *
 * @param string $mode Calendar period.
 * @return array
 */
function taxocommerce_insights_indicators_build( $mode = 'week' ) {
    $mode = taxocommerce_insights_indicators_mode( $mode );
    $property = function_exists( 'seo_analista_resolve_property_id' )
        ? (string) seo_analista_resolve_property_id() : '';
    $gsc_latest = $property && function_exists( 'seo_google_latest_data_date' )
        ? (string) seo_google_latest_data_date( $property ) : '';
    $now = new DateTimeImmutable( 'today', function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' ) );
    $ga4_cutoff = $now->modify( '-1 day' )->format( 'Y-m-d' );

    $periods = taxocommerce_insights_indicators_periods( $mode, $gsc_latest, $ga4_cutoff, 'week' === $mode ? 4 : 3 );

    // If GSC has imported totals but not a complete selected interval, try
    // earlier closed intervals; never silently sum partial property rows.
    if ( $property && $gsc_latest && $periods ) {
        for ( $attempt = 0; $attempt < 4; $attempt++ ) {
            $first = taxocommerce_insights_indicators_gsc( $property, $periods[0] );
            if ( 'available' === $first['state'] ) {
                break;
            }
            $prior = taxocommerce_insights_indicators_date( $periods[0]['date_from'] );
            if ( ! $prior ) {
                break;
            }
            $end = $prior->modify( '-1 day' )->format( 'Y-m-d' );
            $periods = taxocommerce_insights_indicators_periods( $mode, $end, $ga4_cutoff, 'week' === $mode ? 4 : 3 );
        }
    }

    $series = array();
    foreach ( $periods as $period ) {
        $series[] = array(
            'date_from' => $period['date_from'],
            'date_to' => $period['date_to'],
            'days' => $period['days'],
            'label' => $period['label'],
            'gsc' => taxocommerce_insights_indicators_gsc( $property, $period ),
            'ga4' => taxocommerce_insights_indicators_ga4( $period ),
        );
    }

    $latest = (array) ( $series[0] ?? array() );
    $previous = (array) ( $series[1] ?? array() );
    $gsc_current = (array) ( $latest['gsc']['metrics'] ?? array() );
    $gsc_previous = (array) ( $previous['gsc']['metrics'] ?? array() );
    $ga4_current = (array) ( $latest['ga4']['metrics'] ?? array() );
    $ga4_previous = (array) ( $previous['ga4']['metrics'] ?? array() );
    $changes = array(
        'gsc_impressions' => taxocommerce_insights_indicators_delta( $gsc_current['impressions'] ?? null, $gsc_previous['impressions'] ?? null ),
        'gsc_clicks' => taxocommerce_insights_indicators_delta( $gsc_current['clicks'] ?? null, $gsc_previous['clicks'] ?? null ),
        'ga4_sessions' => taxocommerce_insights_indicators_delta( $ga4_current['sessions'] ?? null, $ga4_previous['sessions'] ?? null ),
    );

    $query_rows = array();
    $page_rows = array();
    $category_signals = array();
    $query_signals = array();
    $pages_current_complete = null;
    $pages_previous_complete = null;
    if ( $property && $latest && 'available' === ( $latest['gsc']['state'] ?? '' ) ) {
        $query_rows = function_exists( 'seo_analista_query_rows' )
            ? (array) seo_analista_query_rows( $property, $latest['date_from'], $latest['date_to'], 500 ) : array();
        $page_rows = function_exists( 'seo_analista_page_rows' )
            ? (array) seo_analista_page_rows( $property, $latest['date_from'], $latest['date_to'], 250 ) : array();
        $prior_query_rows = $previous && 'available' === ( $previous['gsc']['state'] ?? '' ) && function_exists( 'seo_analista_query_rows' )
            ? (array) seo_analista_query_rows( $property, $previous['date_from'], $previous['date_to'], 500 ) : array();
        $prior_page_rows = $previous && 'available' === ( $previous['gsc']['state'] ?? '' ) && function_exists( 'seo_analista_page_rows' )
            ? (array) seo_analista_page_rows( $property, $previous['date_from'], $previous['date_to'], 250 ) : array();
        // GSC page-query rows can be privacy-filtered or partially imported.
        // Do not call missing pages "no clicks" or compare incomplete periods.
        $pages_current_complete = function_exists( 'seo_google_pages_period_complete' )
            ? (bool) seo_google_pages_period_complete( $property, $latest['date_from'], $latest['date_to'] ) : false;
        $pages_previous_complete = $previous && function_exists( 'seo_google_pages_period_complete' )
            ? (bool) seo_google_pages_period_complete( $property, $previous['date_from'], $previous['date_to'] ) : false;
        if ( $pages_current_complete ) {
            // Keep positive current-period observations when the previous
            // page history is unavailable, but never invent a growth delta.
            $category_signals = taxocommerce_insights_indicators_categories(
                $page_rows, $pages_previous_complete ? $prior_page_rows : array()
            );
        }
        $prior_queries = array();
        foreach ( $prior_query_rows as $row ) {
            $name = trim( strtolower( (string) ( $row['query_text'] ?? '' ) ) );
            if ( '' !== $name ) {
                $prior_queries[ $name ] = $row;
            }
        }
        foreach ( $query_rows as $row ) {
            $query = trim( (string) ( $row['query_text'] ?? '' ) );
            if ( '' === $query ) {
                continue;
            }
            $impressions = max( 0, (float) ( $row['impressions'] ?? 0 ) );
            $clicks = max( 0, (float) ( $row['clicks'] ?? 0 ) );
            $position = (float) ( $row['position'] ?? 0 );
            $key = strtolower( $query );
            $old_exists = isset( $prior_queries[ $key ] );
            $old = $old_exists ? (array) $prior_queries[ $key ] : array();
            $change = $old_exists
                ? taxocommerce_insights_indicators_delta( $impressions, (float) ( $old['impressions'] ?? 0 ) ) : null;
            $codes = array();
            if ( $impressions >= 20 && $change && $change['delta'] >= 10 ) {
                $codes[] = 'consulta_con_impresiones_crecientes';
            }
            if ( $impressions >= 50 && 0.0 === $clicks && $position > 0 && $position <= 20 ) {
                $codes[] = 'consulta_visible_sin_clics_organicos';
            }
            if ( $codes ) {
                $query_signals[] = array(
                    'query' => $query,
                    'signals' => $codes,
                    'source' => 'Google Search Console',
                    'metrics' => array(
                        'impressions' => $impressions,
                        'previous_impressions' => $old_exists ? (float) ( $old['impressions'] ?? 0 ) : null,
                        'impression_change' => $change,
                        'clicks' => $clicks,
                        'position' => $position,
                        'ctr_pct' => $impressions > 0 ? round( 100 * $clicks / $impressions, 2 ) : null,
                    ),
                );
            }
            if ( count( $query_signals ) >= 40 ) {
                break;
            }
        }
    }

    return array(
        'schema' => array( 'name' => 'taxocommerce-insights-indicadores', 'version' => 1 ),
        'generated_at' => gmdate( 'c' ),
        'period_mode' => $mode,
        'period_label' => 'week' === $mode ? 'Semanas completas (lunes-domingo)' : 'Meses naturales completos',
        'source_cutoffs' => array(
            'gsc_latest_imported_date' => $gsc_latest ?: null,
            'ga4_latest_candidate_date' => $ga4_cutoff,
            'ga4_cutoff_method' => 'Día natural anterior; GA4 puede corregir datos posteriores. No equivale a fecha de evento con sesiones.',
        ),
        'periods' => $series,
        'changes' => $changes,
        'signals' => array(
            'categories' => $category_signals,
            'queries' => $query_signals,
        ),
        'evidence' => array(
            'gsc_pages_current_complete' => $pages_current_complete,
            'gsc_pages_previous_complete' => $pages_previous_complete,
            'top_queries' => array_slice( $query_rows, 0, 100 ),
            'top_pages' => array_slice( $page_rows, 0, 100 ),
        ),
        'limitations' => array(
            'Las fechas comparativas son idénticas para GSC y GA4. Las métricas de GSC y GA4 no son unidades intercambiables.',
            'Los datos GSC son comparables solo si existen todos los totales diarios de propiedad; en caso contrario se marcan parciales.',
            'Los eventos GA4 son recuentos de eventos, no personas únicas ni ventas reales de WooCommerce.',
            'Ausencia de clics orgánicos no demuestra ausencia de visitas a una página.',
            'Los cambios frente a una base cero no se expresan como porcentaje de crecimiento.',
            'Estos indicadores no recomiendan crear, eliminar ni modificar contenido.',
        ),
    );
}

/**
 * Render concise factual observations using the same data as the JSON.
 *
 * @param array $report Precomputed indicator snapshot.
 * @return void
 */
function taxocommerce_insights_indicators_render( array $report ) {
    echo '<section class="seo-analista-section"><h2>' . esc_html__( 'Indicadores para la acción', 'taxocommerce-google-bing' ) . '</h2>';
    echo '<p>' . esc_html__( 'Evidencias y patrones medidos; las decisiones estratégicas se realizan fuera de Analista.', 'taxocommerce-google-bing' ) . '</p>';
    echo '<p><strong>' . esc_html( (string) ( $report['period_label'] ?? '' ) ) . '</strong>';
    echo ' · ' . esc_html__( 'GSC disponible hasta:', 'taxocommerce-google-bing' ) . ' ' . esc_html( (string) ( $report['source_cutoffs']['gsc_latest_imported_date'] ?? '—' ) );
    echo '</p></section>';

    $periods = (array) ( $report['periods'] ?? array() );
    if ( ! $periods ) {
        echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No hay fechas suficientes para generar periodos completos.', 'taxocommerce-google-bing' ) . '</p></div>';
        return;
    }
    echo '<section class="seo-analista-section"><h3>' . esc_html__( 'Evolución por periodos completos', 'taxocommerce-google-bing' ) . '</h3>';
    echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Periodo', 'taxocommerce-google-bing' ) . '</th>';
    echo '<th>' . esc_html__( 'Impresiones GSC', 'taxocommerce-google-bing' ) . '</th><th>' . esc_html__( 'Clics GSC', 'taxocommerce-google-bing' ) . '</th>';
    echo '<th>' . esc_html__( 'CTR GSC', 'taxocommerce-google-bing' ) . '</th><th>' . esc_html__( 'Sesiones GA4', 'taxocommerce-google-bing' ) . '</th><th>' . esc_html__( 'Compras registradas GA4', 'taxocommerce-google-bing' ) . '</th></tr></thead><tbody>';
    foreach ( $periods as $period ) {
        $gsc = (array) ( $period['gsc']['metrics'] ?? array() );
        $ga4 = (array) ( $period['ga4']['metrics'] ?? array() );
        $gsc_state = (string) ( $period['gsc']['state'] ?? 'unavailable' );
        $ga4_state = (string) ( $period['ga4']['state'] ?? 'unavailable' );
        $gsc_missing = 'partial' === $gsc_state ? __( 'Parcial', 'taxocommerce-google-bing' ) : __( 'No disponible', 'taxocommerce-google-bing' );
        echo '<tr><td>' . esc_html( (string) $period['label'] ) . '</td>';
        echo '<td>' . esc_html( isset( $gsc['impressions'] ) ? number_format_i18n( $gsc['impressions'], 0 ) : $gsc_missing ) . '</td>';
        echo '<td>' . esc_html( isset( $gsc['clicks'] ) ? number_format_i18n( $gsc['clicks'], 0 ) : $gsc_missing ) . '</td>';
        echo '<td>' . esc_html( isset( $gsc['ctr_pct'] ) ? number_format_i18n( $gsc['ctr_pct'], 2 ) . ' %' : $gsc_missing ) . '</td>';
        echo '<td>' . esc_html( isset( $ga4['sessions'] ) ? number_format_i18n( $ga4['sessions'], 0 ) : __( 'No disponible', 'taxocommerce-google-bing' ) ) . '</td>';
        echo '<td>' . esc_html( isset( $ga4['purchases'] ) ? number_format_i18n( $ga4['purchases'], 0 ) : __( 'No disponible', 'taxocommerce-google-bing' ) ) . '</td></tr>';
    }
    echo '</tbody></table></section>';

    $changes = (array) ( $report['changes'] ?? array() );
    echo '<section class="seo-analista-section"><h3>' . esc_html__( 'Variación respecto al periodo anterior', 'taxocommerce-google-bing' ) . '</h3><p>';
    foreach ( array( 'gsc_impressions' => 'Impresiones GSC', 'gsc_clicks' => 'Clics GSC', 'ga4_sessions' => 'Sesiones GA4' ) as $code => $label ) {
        $change = $changes[ $code ] ?? null;
        if ( ! is_array( $change ) ) {
            echo '<span>' . esc_html( $label . ': ' . __( 'sin comparación completa', 'taxocommerce-google-bing' ) ) . '</span> · ';
            continue;
        }
        $value = isset( $change['growth_pct'] )
            ? number_format_i18n( (float) $change['growth_pct'], 1 ) . ' %'
            : __( 'base anterior cero', 'taxocommerce-google-bing' );
        echo '<span>' . esc_html( $label . ': ' . $value ) . '</span> · ';
    }
    echo '</p></section>';

    $categories = (array) ( $report['signals']['categories'] ?? array() );
    $queries = (array) ( $report['signals']['queries'] ?? array() );
    echo '<section class="seo-analista-section"><h3>' . esc_html__( 'Patrones observados', 'taxocommerce-google-bing' ) . '</h3>';
    echo '<p>' . esc_html( sprintf(
        /* translators: 1: categories, 2: queries */
        __( '%1$d categorías y %2$d consultas con indicadores medibles.', 'taxocommerce-google-bing' ),
        count( $categories ),
        count( $queries )
    ) ) . '</p>';
    if ( ! $categories && ! $queries ) {
        echo '<p>' . esc_html__( 'No se han detectado señales suficientes en los datos comparables; esto no significa que no existan oportunidades.', 'taxocommerce-google-bing' ) . '</p>';
    }
    foreach ( array_slice( $categories, 0, 8 ) as $category ) {
        $metrics = (array) $category['metrics'];
        echo '<p><strong>' . esc_html( (string) $category['name'] ) . '</strong> · ';
        echo esc_html( number_format_i18n( $metrics['impressions'], 0 ) . ' impresiones, ' . number_format_i18n( $metrics['clicks'], 0 ) . ' clics, ' . number_format_i18n( $category['product_count'], 0 ) . ' productos.' );
        echo ' ' . esc_html( implode( ', ', (array) $category['signals'] ) ) . '</p>';
    }
    foreach ( array_slice( $queries, 0, 8 ) as $query ) {
        $metrics = (array) $query['metrics'];
        echo '<p><strong>' . esc_html( (string) $query['query'] ) . '</strong> · ';
        echo esc_html( number_format_i18n( $metrics['impressions'], 0 ) . ' impresiones, ' . number_format_i18n( $metrics['clicks'], 0 ) . ' clics.' );
        echo ' ' . esc_html( implode( ', ', (array) $query['signals'] ) ) . '</p>';
    }
    echo '</section>';
}
