<?php

defined( 'ABSPATH' ) || exit;

/**
 * Google Search Console search performance snapshots.
 *
 * All methods called from an explicit, nonced admin_post handler. No cron and
 * no reports are fetched on admin screen loads.
 */
final class TaxoCommerce_Google_Bing_GSC {

    public static function download( $days, $ranges ) {
        $settings = TaxoCommerce_Google_Bing_Connections::google_settings();
        $oauth = TaxoCommerce_Google_Bing_OAuth::settings();
        $site = trim( (string) ( $settings['search_console_site_url'] ?? '' ) );
        if ( '' === $site ) {
            $site = trim( (string) ( $oauth['property_id'] ?? '' ) );
        }
        if ( '' === $site ) {
            return new WP_Error( 'tgb_gsc_missing_site', 'No hay propiedad de Google Search Console seleccionada en Conexiones.' );
        }

        // Prefer an authorized service account, otherwise use the OAuth connection.
        $token = new WP_Error( 'tgb_gsc_credentials', 'Faltan credenciales de Search Console.' );
        $using_service_account = ! empty( $settings['service_account_json'] );
        if ( $using_service_account ) {
            $token = TaxoCommerce_Google_Bing_Connections::access_token(
                'https://www.googleapis.com/auth/webmasters.readonly'
            );
        }
        if ( is_wp_error( $token ) ) {
            $token = TaxoCommerce_Google_Bing_OAuth::access_token();
        }
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $specs = array(
            'current_total'  => array( 'current', array(), 1 ),
            'previous_total' => array( 'previous', array(), 1 ),
            'current_days'   => array( 'current', array( 'date' ), 100 ),
            'previous_days'  => array( 'previous', array( 'date' ), 100 ),
            'queries'          => array( 'current', array( 'query' ), 1000 ),
            'previous_queries' => array( 'previous', array( 'query' ), 1000 ),
            'pages'            => array( 'current', array( 'page' ), 1000 ),
            'previous_pages'   => array( 'previous', array( 'page' ), 1000 ),
            'countries'        => array( 'current', array( 'country' ), 100 ),
            'devices'        => array( 'current', array( 'device' ), 10 ),
        );

        $results = array();
        foreach ( $specs as $name => $spec ) {
            $result = self::request( $site, $token, $ranges[ $spec[0] ], $spec[1], $spec[2] );
            if ( is_wp_error( $result ) && $using_service_account && 'current_total' === $name
                && in_array( (int) $result->get_error_data( 'status' ), array( 401, 403 ), true ) ) {
                // The service account may be authorized for GA4 but not this Search
                // Console property. Retry once with the explicitly linked OAuth user.
                $oauth_token = TaxoCommerce_Google_Bing_OAuth::access_token();
                if ( ! is_wp_error( $oauth_token ) ) {
                    $token = $oauth_token;
                    $using_service_account = false;
                    $result = self::request( $site, $token, $ranges[ $spec[0] ], $spec[1], $spec[2] );
                }
            }
            if ( is_wp_error( $result ) ) {
                return new WP_Error(
                    'tgb_gsc_incomplete',
                    'No se pudo recuperar «' . $name . '»: ' . $result->get_error_message()
                );
            }
            $results[ $name ] = (array) ( $result['rows'] ?? array() );
        }

        $current_total = self::summary( $results['current_total'] );
        $previous_total = self::summary( $results['previous_total'] );
        $axis_current = self::axis( $ranges['current']['start'], $days );
        $axis_previous = self::axis( $ranges['previous']['start'], $days );

        return array(
            'summary' => array( 'current' => $current_total, 'previous' => $previous_total ),
            'daily' => array(
                'labels' => $axis_current['labels'],
                'current_clicks' => self::daily_metric( $results['current_days'], $axis_current['indexes'], $days, 'clicks' ),
                'previous_clicks' => self::daily_metric( $results['previous_days'], $axis_previous['indexes'], $days, 'clicks' ),
                'current_impressions' => self::daily_metric( $results['current_days'], $axis_current['indexes'], $days, 'impressions' ),
                'previous_impressions' => self::daily_metric( $results['previous_days'], $axis_previous['indexes'], $days, 'impressions' ),
                'current_position' => self::daily_metric( $results['current_days'], $axis_current['indexes'], $days, 'position' ),
                'previous_position' => self::daily_metric( $results['previous_days'], $axis_previous['indexes'], $days, 'position' ),
            ),
            'queries' => self::top_rows( $results['queries'] ),
            'previous_queries' => self::top_rows( $results['previous_queries'] ),
            'pages' => self::top_rows( $results['pages'] ),
            'previous_pages' => self::top_rows( $results['previous_pages'] ),
            'countries' => self::top_rows( $results['countries'] ),
            'devices' => self::top_rows( $results['devices'] ),
            'note' => 'Search Console devuelve filas principales, no necesariamente todas las consultas (las anonimizadas se omiten). Los totales de la propiedad se solicitan por separado.',
        );
    }

    private static function request( $site, $token, $range, $dimensions, $limit ) {
        $payload = array(
            'startDate' => $range['start'],
            'endDate' => $range['end'],
            'dimensions' => $dimensions,
            'type' => 'web',
            'dataState' => 'final',
            'rowLimit' => $limit,
        );
        $url = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site ) . '/searchAnalytics/query';
        $response = wp_remote_post(
            $url,
            array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json; charset=utf-8',
                    'Accept' => 'application/json',
                ),
                'body' => wp_json_encode( $payload ),
            )
        );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
            $message = 'Google Search Console respondió HTTP ' . $code . '.';
            if ( is_array( $body ) && ! empty( $body['error']['message'] ) ) {
                $message .= ' ' . sanitize_text_field( (string) $body['error']['message'] );
            }
            return new WP_Error( 'tgb_gsc_http', $message, array( 'status' => $code ) );
        }
        return $body;
    }

    private static function summary( $rows ) {
        $row = (array) ( $rows[0] ?? array() );
        return array(
            'clicks' => (int) ( $row['clicks'] ?? 0 ),
            'impressions' => (int) ( $row['impressions'] ?? 0 ),
            'ctr' => (float) ( $row['ctr'] ?? 0 ),
            'position' => (float) ( $row['position'] ?? 0 ),
        );
    }

    private static function top_rows( $rows ) {
        $out = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $keys = (array) ( $row['keys'] ?? array() );
            $label = sanitize_text_field( (string) ( $keys[0] ?? '' ) );
            if ( '' === $label ) {
                continue;
            }
            $out[] = array(
                'name' => $label,
                'clicks' => (int) ( $row['clicks'] ?? 0 ),
                'impressions' => (int) ( $row['impressions'] ?? 0 ),
                'ctr' => (float) ( $row['ctr'] ?? 0 ),
                'position' => (float) ( $row['position'] ?? 0 ),
            );
        }
        return $out;
    }

    private static function axis( $start, $days ) {
        $from = new DateTimeImmutable( $start );
        $indexes = array();
        $labels = array();
        for ( $index = 0; $index < $days; $index++ ) {
            $date = $from->modify( '+' . $index . ' days' );
            $indexes[ $date->format( 'Y-m-d' ) ] = $index;
            $stride = $days <= 7 ? 1 : ( $days <= 30 ? 3 : 9 );
            $labels[] = 0 === $index % $stride ? $date->format( 'd/m' ) : '';
        }
        return array( 'indexes' => $indexes, 'labels' => $labels );
    }

    private static function daily_metric( $rows, $indexes, $days, $metric ) {
        $values = array_fill( 0, $days, 0 );
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $keys = (array) ( $row['keys'] ?? array() );
            $date = (string) ( $keys[0] ?? '' );
            if ( isset( $indexes[ $date ] ) ) {
                $values[ $indexes[ $date ] ] = 'position' === $metric
                    ? (float) ( $row[ $metric ] ?? 0 )
                    : (int) ( $row[ $metric ] ?? 0 );
            }
        }
        return $values;
    }
}
