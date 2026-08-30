<?php
/**
 * SEO System - Google Search / Analytics.
 *
 * Configuracion dinamica por instalacion para GA4, Search Console y Analytics.
 * No contiene IDs, dominios ni credenciales de un cliente concreto.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'seo_google_search_settings' ) ) {
    function seo_google_search_settings() {
        $defaults = [
            'tracking_enabled'         => 0,
            'measurement_id'           => '',
            'analytics_property_id'    => '',
            'search_console_site_url'  => '',
            'service_account_json'     => '',
        ];

        $saved = get_option( 'seo_google_search_settings', [] );
        return wp_parse_args( is_array( $saved ) ? $saved : [], $defaults );
    }
}

if ( ! function_exists( 'seo_google_search_measurement_id' ) ) {
    function seo_google_search_measurement_id() {
        $settings = seo_google_search_settings();
        $id = strtoupper( trim( (string) ( $settings['measurement_id'] ?? '' ) ) );
        return preg_match( '/^G-[A-Z0-9]+$/', $id ) ? $id : '';
    }
}

if ( ! function_exists( 'seo_google_search_service_account' ) ) {
    function seo_google_search_service_account() {
        $settings = seo_google_search_settings();
        $raw = trim( (string) ( $settings['service_account_json'] ?? '' ) );
        if ( '' === $raw ) {
            return new WP_Error( 'seo_google_no_credentials', 'No hay credenciales de cuenta de servicio configuradas.' );
        }

        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) || empty( $data['client_email'] ) || empty( $data['private_key'] ) ) {
            return new WP_Error( 'seo_google_invalid_credentials', 'El JSON de la cuenta de servicio no es valido.' );
        }

        return $data;
    }
}

if ( ! function_exists( 'seo_google_base64url' ) ) {
    function seo_google_base64url( $value ) {
        return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
    }
}

if ( ! function_exists( 'seo_google_access_token' ) ) {
    function seo_google_access_token( $force = false ) {
        $account = seo_google_search_service_account();
        if ( is_wp_error( $account ) ) {
            return $account;
        }

        $cache_key = 'seo_google_token_' . md5( (string) $account['client_email'] );
        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( is_string( $cached ) && '' !== $cached ) {
                return $cached;
            }
        }

        if ( ! function_exists( 'openssl_sign' ) ) {
            return new WP_Error( 'seo_google_openssl_missing', 'OpenSSL no esta disponible en PHP.' );
        }

        $now = time();
        $header = seo_google_base64url( wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
        $claims = seo_google_base64url(
            wp_json_encode(
                [
                    'iss'   => (string) $account['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly',
                    'aud'   => ! empty( $account['token_uri'] ) ? (string) $account['token_uri'] : 'https://oauth2.googleapis.com/token',
                    'iat'   => $now,
                    'exp'   => $now + 3500,
                ]
            )
        );

        $unsigned = $header . '.' . $claims;
        $signature = '';
        $signed = openssl_sign( $unsigned, $signature, (string) $account['private_key'], OPENSSL_ALGO_SHA256 );
        if ( ! $signed ) {
            return new WP_Error( 'seo_google_sign_failed', 'No se pudo firmar el token de Google.' );
        }

        $assertion = $unsigned . '.' . seo_google_base64url( $signature );
        $token_uri = ! empty( $account['token_uri'] ) ? esc_url_raw( $account['token_uri'] ) : 'https://oauth2.googleapis.com/token';

        $response = wp_remote_post(
            $token_uri,
            [
                'timeout' => 20,
                'body'    => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $assertion,
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( $status < 200 || $status >= 300 || empty( $body['access_token'] ) ) {
            $message = is_array( $body ) && ! empty( $body['error_description'] ) ? $body['error_description'] : 'Google no ha emitido un access token.';
            return new WP_Error( 'seo_google_token_failed', sanitize_text_field( $message ) );
        }

        $token = sanitize_text_field( (string) $body['access_token'] );
        $ttl = ! empty( $body['expires_in'] ) ? max( 60, absint( $body['expires_in'] ) - 120 ) : 3300;
        set_transient( $cache_key, $token, $ttl );
        return $token;
    }
}

if ( ! function_exists( 'seo_google_api_request' ) ) {
    function seo_google_api_request( $method, $url, $body = null ) {
        $token = seo_google_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $args = [
            'method'  => strtoupper( (string) $method ),
            'timeout' => 25,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
        ];

        if ( null !== $body ) {
            $args['headers']['Content-Type'] = 'application/json; charset=utf-8';
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( esc_url_raw( $url ), $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $raw = (string) wp_remote_retrieve_body( $response );
        $decoded = json_decode( $raw, true );

        if ( $status < 200 || $status >= 300 ) {
            $message = 'Google API ha respondido HTTP ' . $status . '.';
            if ( is_array( $decoded ) && ! empty( $decoded['error']['message'] ) ) {
                $message .= ' ' . sanitize_text_field( $decoded['error']['message'] );
            }
            return new WP_Error( 'seo_google_api_error', $message, [ 'status' => $status ] );
        }

        return is_array( $decoded ) ? $decoded : [];
    }
}

if ( ! function_exists( 'seo_google_search_console_query' ) ) {
    function seo_google_search_console_query( $request = [] ) {
        $settings = seo_google_search_settings();
        $site = trim( (string) ( $settings['search_console_site_url'] ?? '' ) );
        if ( '' === $site ) {
            return new WP_Error( 'seo_google_no_search_console_site', 'No hay propiedad de Search Console configurada.' );
        }

        $defaults = [
            'startDate'  => gmdate( 'Y-m-d', strtotime( '-28 days' ) ),
            'endDate'    => gmdate( 'Y-m-d' ),
            'dimensions' => [ 'query', 'page' ],
            'rowLimit'   => 1000,
        ];
        $payload = wp_parse_args( is_array( $request ) ? $request : [], $defaults );
        $url = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site ) . '/searchAnalytics/query';
        return seo_google_api_request( 'POST', $url, $payload );
    }
}

if ( ! function_exists( 'seo_google_analytics_run_report' ) ) {
    function seo_google_analytics_run_report( $request = [] ) {
        $settings = seo_google_search_settings();
        $property_id = preg_replace( '/\D+/', '', (string) ( $settings['analytics_property_id'] ?? '' ) );
        if ( '' === $property_id ) {
            return new WP_Error( 'seo_google_no_analytics_property', 'No hay propiedad de Analytics configurada.' );
        }

        $defaults = [
            'dateRanges' => [ [ 'startDate' => '28daysAgo', 'endDate' => 'today' ] ],
            'metrics'    => [ [ 'name' => 'sessions' ] ],
            'limit'      => 1000,
        ];
        $payload = array_replace_recursive( $defaults, is_array( $request ) ? $request : [] );
        $url = 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode( $property_id ) . ':runReport';
        return seo_google_api_request( 'POST', $url, $payload );
    }
}

if ( ! function_exists( 'seo_google_analytics_diagnostic' ) ) {
    /** Diagnostico ligero y cacheado para reutilizarlo desde Marketing/Landings. */
    function seo_google_analytics_diagnostic( $force = false ) {
        $cache_key = 'seo_google_analytics_diagnostic_v1';
        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        $settings = seo_google_search_settings();
        if ( empty( $settings['analytics_property_id'] ) || empty( $settings['service_account_json'] ) ) {
            return [ 'ok' => false, 'message' => 'Falta Property ID o cuenta de servicio para Analytics.', 'sessions' => 0 ];
        }

        $report = seo_google_analytics_run_report(
            [
                'dateRanges' => [ [ 'startDate' => '7daysAgo', 'endDate' => 'today' ] ],
                'metrics'    => [ [ 'name' => 'sessions' ] ],
                'limit'      => 1,
            ]
        );
        if ( is_wp_error( $report ) ) {
            $result = [ 'ok' => false, 'message' => 'Error Analytics: ' . $report->get_error_message(), 'sessions' => 0 ];
            set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
            return $result;
        }

        $sessions = 0;
        if ( ! empty( $report['rows'][0]['metricValues'][0]['value'] ) ) {
            $sessions = (int) $report['rows'][0]['metricValues'][0]['value'];
        }
        $result = [
            'ok'       => true,
            'message'  => 'Analytics responde correctamente; sesiones ultimos 7 dias: ' . number_format_i18n( $sessions ),
            'sessions' => $sessions,
        ];
        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
        return $result;
    }
}

if ( ! function_exists( 'seo_google_search_test_connection' ) ) {
    function seo_google_search_test_connection() {
        $result = [ 'ok' => true, 'messages' => [] ];
        $token = seo_google_access_token( true );
        if ( is_wp_error( $token ) ) {
            return [ 'ok' => false, 'messages' => [ $token->get_error_message() ] ];
        }
        $result['messages'][] = 'Autenticacion Google correcta.';

        $settings = seo_google_search_settings();
        if ( '' !== trim( (string) ( $settings['search_console_site_url'] ?? '' ) ) ) {
            $sc = seo_google_search_console_query( [ 'dimensions' => [ 'page' ], 'rowLimit' => 1 ] );
            if ( is_wp_error( $sc ) ) {
                $result['ok'] = false;
                $result['messages'][] = 'Search Console: ' . $sc->get_error_message();
            } else {
                $result['messages'][] = 'Search Console: acceso correcto.';
            }
        }

        if ( '' !== trim( (string) ( $settings['analytics_property_id'] ?? '' ) ) ) {
            $ga = seo_google_analytics_run_report( [ 'limit' => 1 ] );
            if ( is_wp_error( $ga ) ) {
                $result['ok'] = false;
                $result['messages'][] = 'Analytics: ' . $ga->get_error_message();
            } else {
                $result['messages'][] = 'Analytics: acceso correcto.';
            }
        }

        return $result;
    }
}

if ( ! function_exists( 'seo_google_search_save_settings' ) ) {
    function seo_google_search_save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para guardar esta conexion.', 'seo-system' ) );
        }
        check_admin_referer( 'seo_google_search_save', 'seo_google_search_nonce' );

        $current = seo_google_search_settings();
        $measurement_id = strtoupper( sanitize_text_field( wp_unslash( $_POST['measurement_id'] ?? '' ) ) );
        if ( '' !== $measurement_id && ! preg_match( '/^G-[A-Z0-9]+$/', $measurement_id ) ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'seo-import-export', 'seo_ie_tab' => 'conexiones-proveedores', 'google_search_error' => 'measurement_id' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        $service_raw = trim( (string) wp_unslash( $_POST['service_account_json'] ?? '' ) );
        $service_json = (string) ( $current['service_account_json'] ?? '' );
        if ( '' !== $service_raw ) {
            $decoded = json_decode( $service_raw, true );
            if ( ! is_array( $decoded ) || empty( $decoded['client_email'] ) || empty( $decoded['private_key'] ) ) {
                wp_safe_redirect( add_query_arg( [ 'page' => 'seo-import-export', 'seo_ie_tab' => 'conexiones-proveedores', 'google_search_error' => 'credentials' ], admin_url( 'admin.php' ) ) );
                exit;
            }
            $service_json = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES );
        }

        $settings = [
            'tracking_enabled'        => empty( $_POST['tracking_enabled'] ) ? 0 : 1,
            'measurement_id'          => $measurement_id,
            'analytics_property_id'   => preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_POST['analytics_property_id'] ?? '' ) ) ),
            'search_console_site_url' => esc_url_raw( trim( (string) wp_unslash( $_POST['search_console_site_url'] ?? '' ) ) ),
            'service_account_json'    => $service_json,
        ];

        update_option( 'seo_google_search_settings', $settings, false );
        wp_safe_redirect( add_query_arg( [ 'page' => 'seo-import-export', 'seo_ie_tab' => 'conexiones-proveedores', 'google_search_saved' => 1 ], admin_url( 'admin.php' ) ) );
        exit;
    }
    add_action( 'admin_post_seo_google_search_save', 'seo_google_search_save_settings' );
}

if ( ! function_exists( 'seo_google_frontend_tracking' ) ) {
    function seo_google_frontend_tracking() {
        if ( is_admin() || current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = seo_google_search_settings();
        if ( empty( $settings['tracking_enabled'] ) ) {
            return;
        }

        $measurement_id = seo_google_search_measurement_id();
        if ( '' === $measurement_id ) {
            return;
        }

        if ( ! apply_filters( 'seo_google_tracking_allowed', true ) ) {
            return;
        }
        ?>
        <!-- SEO System: Google Analytics 4 -->
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $measurement_id ); ?>"></script>
        <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', <?php echo wp_json_encode( $measurement_id ); ?>);
        </script>
        <?php
    }
    add_action( 'wp_head', 'seo_google_frontend_tracking', 20 );
}
