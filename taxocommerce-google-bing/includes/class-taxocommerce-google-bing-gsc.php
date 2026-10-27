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

        // The historical Google Intelligence module uses OAuth + property_id.
        // A service-account URL saved in Insights may differ (URL-prefix vs
        // sc-domain:, or missing trailing slash) and is not necessarily
        // authorized for the OAuth user. Always test matched credential/site
        // pairs instead of retrying a second token with the first URL.
        $oauth_site = trim( (string) ( $oauth['property_id'] ?? '' ) );
        $service_site = trim( (string) ( $settings['search_console_site_url'] ?? '' ) );
        $legacy = get_option( 'seo_google_intelligence_settings', array() );
        $legacy_site = is_array( $legacy ) ? trim( (string) ( $legacy['property_id'] ?? '' ) ) : '';

        $candidates = array();
        if ( '' !== $oauth_site ) {
            $candidates[] = array( 'auth' => 'oauth', 'site' => $oauth_site );
        }
        if ( '' !== $legacy_site && $legacy_site !== $oauth_site ) {
            $candidates[] = array( 'auth' => 'oauth', 'site' => $legacy_site );
        }
        // Optional bridge while the old monolith still runs. No dependency:
        // when absent, the modern OAuth authorization remains independent.
        // Never copy, log, revoke or rewrite the old plugin's credentials.
        if ( '' !== $legacy_site && function_exists( 'seo_google_get_access_token' ) ) {
            $candidates[] = array( 'auth' => 'legacy_oauth', 'site' => $legacy_site );
        }
        if ( '' !== $service_site && ! empty( $settings['service_account_json'] ) ) {
            $candidates[] = array( 'auth' => 'service_account', 'site' => $service_site );
        }
        if ( '' === $oauth_site && '' !== $service_site ) {
            $candidates[] = array( 'auth' => 'oauth', 'site' => $service_site );
        }
        if ( '' === $service_site && '' !== $oauth_site && ! empty( $settings['service_account_json'] ) ) {
            $candidates[] = array( 'auth' => 'service_account', 'site' => $oauth_site );
        }
        if ( ! $candidates ) {
            return new WP_Error(
                'tgb_gsc_missing_site',
                'No hay una propiedad Search Console utilizable en la conexión OAuth, en la cuenta de servicio ni en la configuración histórica.'
            );
        }

        $tokens = array();
        $chosen = null;
        $initial_response = null;
        $denied = array();
        $last_status = 0;

        // Only the explicit "Actualizar datos" action runs these probes.
        // A successful first request is reused as current_total so normal
        // refreshes still perform exactly ten Search Console data queries.
        foreach ( $candidates as $candidate ) {
            $method = $candidate['auth'];
            if ( ! array_key_exists( $method, $tokens ) ) {
                if ( 'legacy_oauth' === $method ) {
                    $tokens[ $method ] = seo_google_get_access_token();
                } elseif ( 'oauth' === $method ) {
                    $tokens[ $method ] = TaxoCommerce_Google_Bing_OAuth::access_token();
                } else {
                    $tokens[ $method ] = TaxoCommerce_Google_Bing_Connections::access_token(
                        'https://www.googleapis.com/auth/webmasters.readonly'
                    );
                }
            }
            $token = $tokens[ $method ];
            if ( is_wp_error( $token ) || ! is_string( $token ) || '' === $token ) {
                continue;
            }

            $probe = self::request(
                $candidate['site'],
                $token,
                $ranges['current'],
                array(),
                1
            );
            if ( ! is_wp_error( $probe ) ) {
                $chosen = $candidate;
                $initial_response = $probe;
                break;
            }
            $last_status = (int) $probe->get_error_data( 'status' );
            if ( ! in_array( $last_status, array( 401, 403, 404 ), true ) ) {
                return new WP_Error(
                    'tgb_gsc_incomplete',
                    'No se pudo recuperar «current_total»: ' . $probe->get_error_message(),
                    array( 'status' => $last_status )
                );
            }
            $denied[] = $method . ' → ' . $candidate['site'];
        }

        if ( null === $chosen ) {
            return new WP_Error(
                'tgb_gsc_incomplete',
                'No hay una combinación autorizada de credenciales y propiedad Search Console. '
                . 'Se comprobaron las propiedades OAuth, histórica y de cuenta de servicio disponibles. '
                . ( $denied ? 'Accesos denegados: ' . implode( '; ', array_unique( $denied ) ) . '. ' : '' )
                . 'Revisa el identificador exacto de propiedad en Conexiones. '
                . 'Una conexión GA4 correcta no concede permisos de Search Console.',
                array( 'status' => $last_status )
            );
        }
        $site = $chosen['site'];
        $token = $tokens[ $chosen['auth'] ];
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

        $results = array(
            'current_total' => (array) ( $initial_response['rows'] ?? array() ),
        );
        foreach ( $specs as $name => $spec ) {
            if ( 'current_total' === $name ) {
                continue; // Already retrieved by the credential/property probe.
            }
            $result = self::request( $site, $token, $ranges[ $spec[0] ], $spec[1], $spec[2] );
            if ( is_wp_error( $result ) ) {
                $status = (int) $result->get_error_data( 'status' );
                $message = 'No se pudo recuperar «' . $name . '»: ' . $result->get_error_message();
                if ( 403 === $status ) {
                    $message .= ' La propiedad que funcionó en la comprobación inicial ha perdido acceso durante la descarga.';
                }
                return new WP_Error( 'tgb_gsc_incomplete', $message, array( 'status' => $status ) );
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
            'source_property' => $site,
            'credential_source' => $chosen['auth'],
            'note' => 'Propiedad utilizada: ' . $site . ' (' . $chosen['auth'] . '). Search Console devuelve filas principales, no necesariamente todas las consultas (las anonimizadas se omiten). Los totales de la propiedad se solicitan por separado.',
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
