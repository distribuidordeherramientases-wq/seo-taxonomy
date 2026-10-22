<?php

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Google_Bing_Connections {
    const GOOGLE_OPTION = 'seo_google_search_settings';
    const BING_OPTION   = 'seo_analista_bing_settings';

    public static function register() {
        add_action( 'admin_post_tgb_save_google', array( __CLASS__, 'save_google' ) );
        add_action( 'admin_post_tgb_test_google', array( __CLASS__, 'test_google' ) );
        add_action( 'admin_post_tgb_save_bing', array( __CLASS__, 'save_bing' ) );
        add_action( 'admin_post_tgb_test_bing', array( __CLASS__, 'test_bing' ) );
    }

    public static function google_settings() {
        $defaults = array(
            'tracking_enabled'        => 0,
            'measurement_id'          => '',
            'analytics_property_id'   => '',
            'search_console_site_url' => '',
            'service_account_json'    => '',
        );
        $saved = get_option( self::GOOGLE_OPTION, array() );
        return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
    }

    public static function bing_settings() {
        $defaults = array(
            'enabled'  => true,
            'site_url' => home_url( '/' ),
            'api_key'  => '',
        );
        $saved = get_option( self::BING_OPTION, array() );
        $settings = wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
        $settings['enabled'] = ! empty( $settings['enabled'] );
        $settings['site_url'] = esc_url_raw( (string) $settings['site_url'] );
        return $settings;
    }

    public static function render() {
        self::render_notice();

        $google = self::google_settings();
        $bing   = self::bing_settings();

        $google_account = self::google_service_account( $google );
        $google_ready   = ! is_wp_error( $google_account );
        $bing_key       = self::bing_api_key( $bing );
        $legacy_oauth   = self::legacy_google_oauth_status();

        echo '<div class="tgb-grid">';

        echo '<section class="tgb-card">';
        echo '<div class="tgb-card-head"><div><h2>Google</h2><p>Conexión central para Search Console, Google Analytics y futuras APIs de Google.</p></div>';
        echo self::badge( $google_ready ? 'Configurado' : 'Sin configurar', $google_ready ? 'ok' : 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';

        if ( $google_ready ) {
            echo '<p><strong>Cuenta de servicio:</strong> ' . esc_html( (string) $google_account['client_email'] ) . '</p>';
        }
        if ( $legacy_oauth['configured'] ) {
            echo '<p class="tgb-legacy"><strong>OAuth heredado detectado:</strong> ' . esc_html( $legacy_oauth['label'] ) . '. Se conserva para compatibilidad mientras migremos los consumidores.</p>';
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tgb_save_google">';
        wp_nonce_field( 'tgb_save_google' );

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th><label for="tgb_gsc_site">Propiedad Search Console</label></th><td>';
        echo '<input id="tgb_gsc_site" class="regular-text code" type="text" name="search_console_site_url" value="' . esc_attr( (string) $google['search_console_site_url'] ) . '" placeholder="https://www.ejemplo.es/ o sc-domain:ejemplo.es">';
        echo '</td></tr>';

        echo '<tr><th><label for="tgb_ga4_property">Propiedad GA4</label></th><td>';
        echo '<input id="tgb_ga4_property" class="regular-text" type="text" inputmode="numeric" name="analytics_property_id" value="' . esc_attr( (string) $google['analytics_property_id'] ) . '" placeholder="123456789">';
        echo '</td></tr>';

        echo '<tr><th><label for="tgb_measurement">Measurement ID</label></th><td>';
        echo '<input id="tgb_measurement" class="regular-text" type="text" name="measurement_id" value="' . esc_attr( (string) $google['measurement_id'] ) . '" placeholder="G-XXXXXXXXXX">';
        echo '<p class="description">Se conserva porque ya lo utilizan componentes existentes, aunque no es necesario para consultar las APIs.</p></td></tr>';

        echo '<tr><th><label for="tgb_google_service">Cuenta de servicio JSON</label></th><td>';
        echo '<textarea id="tgb_google_service" class="large-text code" rows="7" name="service_account_json" placeholder="' . esc_attr( $google_ready ? 'Credencial ya configurada. Déjalo vacío para conservarla.' : 'Pega aquí el JSON de la cuenta de servicio.' ) . '"></textarea>';
        echo '<p class="description">El JSON no se muestra de nuevo. Debe incluir client_email y private_key.</p></td></tr>';
        echo '</table>';

        submit_button( 'Guardar Google', 'primary', 'submit', false );
        echo '</form>';

        echo '<form class="tgb-test-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tgb_test_google">';
        wp_nonce_field( 'tgb_test_google' );
        submit_button( 'Probar conexión Google', 'secondary', 'submit', false );
        echo '</form>';
        echo '</section>';

        echo '<section class="tgb-card">';
        echo '<div class="tgb-card-head"><div><h2>Microsoft Bing</h2><p>Conexión central de solo lectura con Bing Webmaster.</p></div>';
        echo self::badge( '' !== $bing_key ? 'Configurado' : 'Sin configurar', '' !== $bing_key ? 'ok' : 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';

        if ( defined( 'SEO_BING_WEBMASTER_API_KEY' ) && '' !== trim( (string) SEO_BING_WEBMASTER_API_KEY ) ) {
            echo '<p class="tgb-legacy"><strong>API key:</strong> definida mediante <code>SEO_BING_WEBMASTER_API_KEY</code> en wp-config.php.</p>';
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tgb_save_bing">';
        wp_nonce_field( 'tgb_save_bing' );

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th>Estado</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked( ! empty( $bing['enabled'] ), true, false ) . '> Activar Bing Webmaster</label></td></tr>';
        echo '<tr><th><label for="tgb_bing_site">Sitio de Bing Webmaster</label></th><td>';
        echo '<input id="tgb_bing_site" class="regular-text code" type="url" name="site_url" value="' . esc_attr( (string) $bing['site_url'] ) . '" placeholder="https://www.ejemplo.es/">';
        echo '</td></tr>';
        echo '<tr><th><label for="tgb_bing_key">API key</label></th><td>';
        echo '<input id="tgb_bing_key" class="regular-text" type="password" autocomplete="new-password" name="api_key" value="" placeholder="' . esc_attr( '' !== $bing_key ? 'Clave ya configurada. Déjala vacía para conservarla.' : 'API key de Bing Webmaster' ) . '">';
        if ( '' !== $bing_key && ! defined( 'SEO_BING_WEBMASTER_API_KEY' ) ) {
            echo '<p><label><input type="checkbox" name="clear_api_key" value="1"> Eliminar la API key guardada</label></p>';
        }
        echo '</td></tr>';
        echo '</table>';

        submit_button( 'Guardar Bing', 'primary', 'submit', false );
        echo '</form>';

        echo '<form class="tgb-test-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tgb_test_bing">';
        wp_nonce_field( 'tgb_test_bing' );
        submit_button( 'Probar conexión Bing', 'secondary', 'submit', false );
        echo '</form>';
        echo '</section>';

        echo '</div>';

        echo '<div class="tgb-note"><strong>Compatibilidad inicial:</strong> esta versión usa las mismas opciones que el plugin antiguo para que Analista, Ojeador, Ingeniero, Mercado e informes no pierdan sus credenciales mientras trasladamos progresivamente el consumo de datos a este plugin.</div>';

        self::styles();
    }

    public static function save_google() {
        self::guard( 'tgb_save_google' );
        $current = self::google_settings();

        $site = isset( $_POST['search_console_site_url'] ) ? sanitize_text_field( wp_unslash( $_POST['search_console_site_url'] ) ) : '';
        if ( 0 !== strpos( $site, 'sc-domain:' ) ) {
            $site = esc_url_raw( $site );
        }

        $property = isset( $_POST['analytics_property_id'] ) ? preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_POST['analytics_property_id'] ) ) ) : '';
        $measurement = isset( $_POST['measurement_id'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['measurement_id'] ) ) ) : '';
        if ( '' !== $measurement && ! preg_match( '/^G-[A-Z0-9]+$/', $measurement ) ) {
            self::redirect( 'google_measurement_invalid', 'error' );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON must preserve PEM newlines and is structurally validated below.
        $raw_service = isset( $_POST['service_account_json'] ) ? trim( (string) wp_unslash( $_POST['service_account_json'] ) ) : '';
        $service = (string) $current['service_account_json'];
        if ( '' !== $raw_service ) {
            $decoded = json_decode( $raw_service, true );
            if ( ! is_array( $decoded ) || empty( $decoded['client_email'] ) || empty( $decoded['private_key'] ) ) {
                self::redirect( 'google_credentials_invalid', 'error' );
            }
            $service = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES );
        }

        update_option(
            self::GOOGLE_OPTION,
            array(
                'tracking_enabled'        => ! empty( $current['tracking_enabled'] ) ? 1 : 0,
                'measurement_id'          => $measurement,
                'analytics_property_id'   => $property,
                'search_console_site_url' => $site,
                'service_account_json'    => $service,
            ),
            false
        );

        self::redirect( 'google_saved', 'success' );
    }

    public static function save_bing() {
        self::guard( 'tgb_save_bing' );
        $current = self::bing_settings();

        $key = (string) $current['api_key'];
        if ( ! empty( $_POST['clear_api_key'] ) && ! defined( 'SEO_BING_WEBMASTER_API_KEY' ) ) {
            $key = '';
        }
        if ( isset( $_POST['api_key'] ) ) {
            $posted = trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) );
            if ( '' !== $posted ) {
                $key = $posted;
            }
        }

        update_option(
            self::BING_OPTION,
            array(
                'enabled'  => ! empty( $_POST['enabled'] ),
                'site_url' => isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : home_url( '/' ),
                'api_key'  => $key,
            ),
            false
        );

        self::redirect( 'bing_saved', 'success' );
    }

    public static function google_analytics_report( $request ) {
        $settings = self::google_settings();
        $property = preg_replace( '/\D+/', '', (string) ( $settings['analytics_property_id'] ?? '' ) );
        if ( '' === $property ) {
            return new WP_Error( 'tgb_google_no_analytics_property', 'No hay propiedad de Google Analytics configurada.' );
        }

        $token = self::google_access_token( $settings );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $response = wp_remote_post(
            'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode( $property ) . ':runReport',
            array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ),
                'body' => wp_json_encode( is_array( $request ) ? $request : array() ),
            )
        );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 ) {
            $message = 'Google Analytics respondió HTTP ' . $code . '.';
            if ( is_array( $data ) && ! empty( $data['error']['message'] ) ) {
                $message .= ' ' . sanitize_text_field( (string) $data['error']['message'] );
            }
            return new WP_Error( 'tgb_google_analytics_error', $message, array( 'status' => $code ) );
        }

        return is_array( $data ) ? $data : array();
    }

    public static function test_google() {
        self::guard( 'tgb_test_google' );
        $settings = self::google_settings();
        $token = self::google_access_token( $settings, true );
        if ( is_wp_error( $token ) ) {
            self::store_test_error( 'google', $token->get_error_message() );
            self::redirect( 'google_test_failed', 'error' );
        }

        $messages = array( 'Autenticación con Google correcta.' );

        $site = trim( (string) $settings['search_console_site_url'] );
        if ( '' !== $site ) {
            $response = wp_remote_get(
                'https://www.googleapis.com/webmasters/v3/sites',
                array(
                    'timeout' => 20,
                    'headers' => array( 'Authorization' => 'Bearer ' . $token ),
                )
            );
            if ( is_wp_error( $response ) ) {
                self::store_test_error( 'google', 'Search Console: ' . $response->get_error_message() );
                self::redirect( 'google_test_failed', 'error' );
            }
            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( $code < 200 || $code >= 300 ) {
                self::store_test_error( 'google', 'Search Console respondió HTTP ' . $code . '.' );
                self::redirect( 'google_test_failed', 'error' );
            }
            $messages[] = 'Search Console accesible.';
        }

        $property = trim( (string) $settings['analytics_property_id'] );
        if ( '' !== $property ) {
            $response = wp_remote_post(
                'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode( $property ) . ':runReport',
                array(
                    'timeout' => 20,
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type'  => 'application/json',
                    ),
                    'body' => wp_json_encode(
                        array(
                            'dateRanges' => array( array( 'startDate' => '7daysAgo', 'endDate' => 'yesterday' ) ),
                            'metrics'    => array( array( 'name' => 'sessions' ) ),
                            'limit'      => 1,
                        )
                    ),
                )
            );
            if ( is_wp_error( $response ) ) {
                self::store_test_error( 'google', 'Analytics: ' . $response->get_error_message() );
                self::redirect( 'google_test_failed', 'error' );
            }
            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( $code < 200 || $code >= 300 ) {
                self::store_test_error( 'google', 'Analytics respondió HTTP ' . $code . '.' );
                self::redirect( 'google_test_failed', 'error' );
            }
            $messages[] = 'Google Analytics accesible.';
        }

        set_transient( 'tgb_google_test_message_' . get_current_user_id(), implode( ' ', $messages ), 2 * MINUTE_IN_SECONDS );
        self::redirect( 'google_test_ok', 'success' );
    }

    public static function test_bing() {
        self::guard( 'tgb_test_bing' );
        $settings = self::bing_settings();
        $key = self::bing_api_key( $settings );
        if ( '' === $key ) {
            self::store_test_error( 'bing', 'Falta la API key de Bing Webmaster.' );
            self::redirect( 'bing_test_failed', 'error' );
        }
        if ( empty( $settings['enabled'] ) ) {
            self::store_test_error( 'bing', 'Bing está desactivado.' );
            self::redirect( 'bing_test_failed', 'error' );
        }

        $url = add_query_arg(
            array(
                'siteUrl' => (string) $settings['site_url'],
                'apikey'  => $key,
            ),
            'https://ssl.bing.com/webmaster/api.svc/json/GetRankAndTrafficStats'
        );

        $response = wp_remote_get(
            $url,
            array(
                'timeout'     => 20,
                'redirection' => 2,
                'headers'     => array( 'Accept' => 'application/json' ),
                'user-agent'  => 'TaxoCommerce-Google-Bing/' . TAXOCOMMERCE_GOOGLE_BING_VERSION,
            )
        );

        if ( is_wp_error( $response ) ) {
            self::store_test_error( 'bing', $response->get_error_message() );
            self::redirect( 'bing_test_failed', 'error' );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            self::store_test_error( 'bing', 'Bing Webmaster respondió HTTP ' . $code . '.' );
            self::redirect( 'bing_test_failed', 'error' );
        }

        self::redirect( 'bing_test_ok', 'success' );
    }

    private static function google_service_account( $settings ) {
        $raw = trim( (string) ( $settings['service_account_json'] ?? '' ) );
        if ( '' === $raw ) {
            return new WP_Error( 'missing', 'No configurado.' );
        }
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) || empty( $data['client_email'] ) || empty( $data['private_key'] ) ) {
            return new WP_Error( 'invalid', 'Credencial no válida.' );
        }
        return $data;
    }

    private static function google_access_token( $settings, $force = false ) {
        $account = self::google_service_account( $settings );
        if ( is_wp_error( $account ) ) {
            return $account;
        }
        if ( ! function_exists( 'openssl_sign' ) ) {
            return new WP_Error( 'openssl', 'OpenSSL no está disponible en PHP.' );
        }

        $cache_key = 'tgb_google_token_' . md5( (string) $account['client_email'] );
        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( is_string( $cached ) && '' !== $cached ) {
                return $cached;
            }
        }

        $now = time();
        $header = self::base64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
        $claims = self::base64url(
            wp_json_encode(
                array(
                    'iss'   => (string) $account['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly',
                    'aud'   => ! empty( $account['token_uri'] ) ? (string) $account['token_uri'] : 'https://oauth2.googleapis.com/token',
                    'iat'   => $now,
                    'exp'   => $now + 3500,
                )
            )
        );

        $unsigned = $header . '.' . $claims;
        $signature = '';
        if ( ! openssl_sign( $unsigned, $signature, (string) $account['private_key'], OPENSSL_ALGO_SHA256 ) ) {
            return new WP_Error( 'sign', 'No se pudo firmar el token de Google.' );
        }

        $response = wp_remote_post(
            ! empty( $account['token_uri'] ) ? esc_url_raw( $account['token_uri'] ) : 'https://oauth2.googleapis.com/token',
            array(
                'timeout' => 20,
                'body'    => array(
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $unsigned . '.' . self::base64url( $signature ),
                ),
            )
        );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 || ! is_array( $data ) || empty( $data['access_token'] ) ) {
            $message = is_array( $data ) && ! empty( $data['error_description'] ) ? (string) $data['error_description'] : 'Google no ha emitido un access token.';
            return new WP_Error( 'token', $message );
        }

        set_transient( $cache_key, (string) $data['access_token'], max( 300, min( 3300, (int) ( $data['expires_in'] ?? 3600 ) - 60 ) ) );
        return (string) $data['access_token'];
    }

    private static function base64url( $value ) {
        return rtrim( strtr( base64_encode( (string) $value ), '+/', '-_' ), '=' );
    }

    private static function bing_api_key( $settings ) {
        if ( defined( 'SEO_BING_WEBMASTER_API_KEY' ) && '' !== trim( (string) SEO_BING_WEBMASTER_API_KEY ) ) {
            return trim( (string) SEO_BING_WEBMASTER_API_KEY );
        }
        return trim( (string) ( $settings['api_key'] ?? '' ) );
    }

    private static function legacy_google_oauth_status() {
        $settings = get_option( 'seo_google_intelligence_settings', array() );
        $tokens   = get_option( 'seo_google_intelligence_tokens', array() );
        $configured = is_array( $settings ) && ! empty( $settings['client_id'] )
            && is_array( $tokens ) && ( ! empty( $tokens['refresh_token'] ) || ! empty( $tokens['access_token'] ) );

        return array(
            'configured' => $configured,
            'label'      => $configured ? 'Search Console autorizado' : 'No configurado',
        );
    }

    private static function guard( $action ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Acceso no autorizado.', 'taxocommerce-google-bing' ) );
        }
        check_admin_referer( $action );
    }

    private static function redirect( $code, $type ) {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'       => 'taxocommerce-google-bing',
                    'tgb_tab'    => 'connections',
                    'tgb_notice' => sanitize_key( $code ),
                    'tgb_type'   => sanitize_key( $type ),
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    private static function store_test_error( $provider, $message ) {
        set_transient( 'tgb_' . sanitize_key( $provider ) . '_test_error_' . get_current_user_id(), sanitize_text_field( (string) $message ), 2 * MINUTE_IN_SECONDS );
    }

    private static function render_notice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status message after admin-post redirect.
        $code = isset( $_GET['tgb_notice'] ) ? sanitize_key( wp_unslash( $_GET['tgb_notice'] ) ) : '';
        if ( '' === $code ) {
            return;
        }

        $messages = array(
            'google_saved'               => 'Configuración de Google guardada.',
            'google_test_ok'             => 'Conexión Google verificada.',
            'google_measurement_invalid' => 'El Measurement ID no tiene un formato válido.',
            'google_credentials_invalid' => 'El JSON de la cuenta de servicio no es válido.',
            'google_test_failed'         => 'La prueba de Google ha fallado.',
            'bing_saved'                 => 'Configuración de Bing guardada.',
            'bing_test_ok'               => 'Conexión Bing verificada.',
            'bing_test_failed'           => 'La prueba de Bing ha fallado.',
        );
        if ( ! isset( $messages[ $code ] ) ) {
            return;
        }

        $error = false !== strpos( $code, 'failed' ) || false !== strpos( $code, 'invalid' );
        $detail = '';
        if ( 'google_test_failed' === $code ) {
            $detail = (string) get_transient( 'tgb_google_test_error_' . get_current_user_id() );
            delete_transient( 'tgb_google_test_error_' . get_current_user_id() );
        } elseif ( 'bing_test_failed' === $code ) {
            $detail = (string) get_transient( 'tgb_bing_test_error_' . get_current_user_id() );
            delete_transient( 'tgb_bing_test_error_' . get_current_user_id() );
        } elseif ( 'google_test_ok' === $code ) {
            $detail = (string) get_transient( 'tgb_google_test_message_' . get_current_user_id() );
            delete_transient( 'tgb_google_test_message_' . get_current_user_id() );
        }

        echo '<div class="notice ' . ( $error ? 'notice-error' : 'notice-success' ) . ' inline"><p>';
        echo esc_html( $messages[ $code ] . ( $detail ? ' ' . $detail : '' ) );
        echo '</p></div>';
    }

    private static function badge( $label, $status ) {
        return '<span class="tgb-badge tgb-' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
    }

    private static function styles() {
        echo '<style>
        .tgb-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:18px;margin-top:20px}
        .tgb-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px;min-width:0}
        .tgb-card-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;border-bottom:1px solid #f0f0f1;padding-bottom:12px;margin-bottom:10px}
        .tgb-card-head h2{margin:0 0 5px}.tgb-card-head p{margin:0;color:#646970}
        .tgb-badge{display:inline-block;padding:4px 9px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap}
        .tgb-ok{background:#edfaef;color:#116329}.tgb-warning{background:#fff8e5;color:#755b00}
        .tgb-test-form{margin-top:10px;padding-top:12px;border-top:1px solid #f0f0f1}
        .tgb-legacy,.tgb-note{background:#f6f7f7;border-left:4px solid #72aee6;padding:10px 12px}
        .tgb-note{margin-top:18px;max-width:1100px}
        @media(max-width:782px){.tgb-grid{grid-template-columns:1fr}.tgb-card-head{flex-direction:column}}
        </style>';
    }
}
