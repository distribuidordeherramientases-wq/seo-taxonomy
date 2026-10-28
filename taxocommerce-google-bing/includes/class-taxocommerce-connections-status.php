<?php
/**
 * Connection health dashboard. Never calls external services on GET requests.
 *
 * @package TaxoCommerceInsights
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Connections_Status {
    const OPTION = 'tgb_connection_health_v1';
    const VALID_FOR = 7 * DAY_IN_SECONDS;

    public static function register() {
        add_action( 'admin_post_tgb_check_connection', array( __CLASS__, 'check' ) );
    }

    private static function signature( $details ) {
        // Bind verification to the *current* credentials without storing secrets.
        return hash_hmac( 'sha256', wp_json_encode( $details ), wp_salt( 'auth' ) );
    }

    /** Current configuration, read-only; no network calls or credential output. */
    public static function definitions() {
        $g = TaxoCommerce_Connections_Google_Bing::google_settings();
        $oauth = TaxoCommerce_Google_Bing_OAuth::settings();
        $tokens = TaxoCommerce_Google_Bing_OAuth::tokens();
        $merchant = TaxoCommerce_Connections_Google_Bing::merchant_account();
        $bing = TaxoCommerce_Connections_Google_Bing::bing_settings();
        $bing_key = defined( 'SEO_BING_WEBMASTER_API_KEY' ) && trim( (string) SEO_BING_WEBMASTER_API_KEY )
            ? (string) SEO_BING_WEBMASTER_API_KEY : (string) ( $bing['api_key'] ?? '' );
        $amazon = TaxoCommerce_Connections_Amazon::settings();
        $pin = TaxoCommerce_Connections_Pinterest::settings();
        $pin_merchant = TaxoCommerce_Connections_Pinterest::merchant_settings();
        $service = (string) ( $g['service_account_json'] ?? '' );
        $service_ready = false;
        if ( '' !== $service ) {
            $json = json_decode( $service, true );
            $service_ready = is_array( $json ) && ! empty( $json['client_email'] ) && ! empty( $json['private_key'] );
        }
        $oauth_ready = ! empty( $oauth['client_id'] ) && ! empty( $oauth['client_secret'] )
            && ( ! empty( $tokens['refresh_token'] ) || ! empty( $tokens['access_token'] ) );
        $gsc_site = trim( (string) ( $g['search_console_site_url'] ?? '' ) );
        if ( '' === $gsc_site ) {
            $gsc_site = trim( (string) ( $oauth['property_id'] ?? '' ) );
        }
        $amazon_tag = trim( (string) ( $amazon['partner_tag'] ?? '' ) );
        $oauth_identity = ! empty( $tokens['refresh_token'] ) ? $tokens['refresh_token'] : ( $tokens['access_token'] ?? '' );
        $google_token_signature = $service_ready ? $service : array(
            $oauth['client_id'] ?? '', $oauth['client_secret'] ?? '', $oauth_identity,
        );

        return array(
            'gsc' => array( 'name' => 'Google Search Console', 'ready' => '' !== $gsc_site && ( $service_ready || $oauth_ready ), 'mode' => 'test',
                'sig' => self::signature( array( $gsc_site, $google_token_signature ) ) ),
            'ga4' => array( 'name' => 'Google Analytics 4', 'ready' => '' !== (string) ( $g['analytics_property_id'] ?? '' ) && $service_ready, 'mode' => 'test',
                'sig' => self::signature( array( $g['analytics_property_id'] ?? '', $service ) ) ),
            'merchant' => array( 'name' => 'Google Merchant Center', 'ready' => '' !== $merchant && $service_ready, 'mode' => 'test',
                'sig' => self::signature( array( $merchant, $service ) ) ),
            'oauth' => array( 'name' => 'Google OAuth', 'ready' => $oauth_ready, 'mode' => 'test',
                'sig' => self::signature( array( $oauth['client_id'] ?? '', $oauth['client_secret'] ?? '', $oauth_identity, $oauth['property_id'] ?? '' ) ) ),
            'bing' => array( 'name' => 'Bing Webmaster', 'ready' => '' !== trim( $bing_key ) && '' !== (string) ( $bing['site_url'] ?? '' ), 'disabled' => empty( $bing['enabled'] ), 'mode' => 'test',
                'sig' => self::signature( array( $bing['site_url'] ?? '', $bing_key ) ) ),
            'trends' => array( 'name' => 'Google Trends', 'ready' => true, 'mode' => 'public',
                'sig' => '' ),
            'amazon_tag' => array( 'name' => 'Amazon Afiliados', 'ready' => '' !== $amazon_tag, 'mode' => 'local',
                'sig' => self::signature( array( $amazon_tag ) ) ),
            'amazon_api' => array( 'name' => 'Amazon Creators API', 'ready' => TaxoCommerce_Connections_Amazon::creators_ready(), 'mode' => 'test',
                'sig' => self::signature( array( $amazon_tag, $amazon['client_id'] ?? '', $amazon['client_secret'] ?? '' ) ) ),
            'pinterest' => array( 'name' => 'Pinterest Business', 'ready' => ! empty( $pin['access_token_enc'] ) && ! empty( $pin['board_id'] ), 'mode' => 'test',
                'sig' => self::signature( array( $pin['access_token_enc'] ?? '', $pin['refresh_token_enc'] ?? '', $pin['board_id'] ?? '' ) ) ),
            'pinterest_catalog' => array( 'name' => 'Pinterest Merchant / Catalogs', 'ready' => ! empty( $pin_merchant['catalog_feed_url'] ) || ( ! empty( $pin_merchant['domain_verify_enabled'] ) && ! empty( $pin_merchant['domain_verify_code'] ) ), 'mode' => 'local',
                'sig' => self::signature( array( $pin_merchant['catalog_feed_url'] ?? '', $pin_merchant['domain_verify_enabled'] ?? 0, $pin_merchant['domain_verify_code'] ?? '' ) ) ),
        );
    }

    /**
     * Truthful labels: stored credentials alone are never a live "Connected".
     * $saved is a persisted explicit test result, not a background ping.
     */
    public static function state( $config, $saved = array(), $now = null ) {
        $now = null === $now ? time() : (int) $now;
        if ( 'public' === ( $config['mode'] ?? '' ) ) {
            return 'public';
        }
        if ( ! empty( $config['disabled'] ) ) {
            return 'disabled';
        }
        if ( empty( $config['ready'] ) ) {
            return 'pending';
        }
        if ( 'test' !== ( $config['mode'] ?? '' ) ) {
            return 'configured';
        }
        if ( ! is_array( $saved ) || empty( $saved['checked_at'] )
            || ! hash_equals( (string) ( $config['sig'] ?? '' ), (string) ( $saved['sig'] ?? '' ) ) ) {
            return 'configured';
        }
        if ( ( $now - (int) $saved['checked_at'] ) > self::VALID_FOR ) {
            return 'configured';
        }
        return ! empty( $saved['ok'] ) ? 'connected' : 'error';
    }

    private static function labels() {
        return array(
            'connected'  => array( 'Conectado', 'good' ),
            'configured' => array( 'Configurado · sin verificar', 'neutral' ),
            'pending'    => array( 'Pendiente de conexión', 'pending' ),
            'error'      => array( 'Error de conexión', 'bad' ),
            'disabled'   => array( 'Desactivado', 'muted' ),
            'public'     => array( 'No requiere conexión', 'neutral' ),
        );
    }

    private static function records() {
        $saved = get_option( self::OPTION, array() );
        return is_array( $saved ) ? $saved : array();
    }

    public static function badge( $id, $definitions = null ) {
        $definitions = is_array( $definitions ) ? $definitions : self::definitions();
        if ( ! isset( $definitions[ $id ] ) ) {
            return;
        }
        $stored = self::records();
        $entry = $definitions[ $id ];
        $state = self::state( $entry, $stored[ $id ] ?? array() );
        $label = self::labels()[ $state ];
        echo '<span class="tgb-health-badge tgb-health-' . esc_attr( $label[1] ) . '">' . esc_html( $label[0] ) . '</span>';
    }

    public static function render() {
        $definitions = self::definitions();
        $stored = self::records();
        echo '<section class="tgb-health-overview" aria-labelledby="tgb-health-heading">';
        echo '<div class="tgb-health-top"><div><h2 id="tgb-health-heading">Estado de las conexiones</h2>';
        echo '<p>Estados de credenciales y verificaciones manuales. Abrir esta pantalla no llama a proveedores externos.</p></div></div>';
        echo '<div class="tgb-health-grid">';
        foreach ( $definitions as $id => $config ) {
            $record = $stored[ $id ] ?? array();
            $state = self::state( $config, $record );
            echo '<article class="tgb-health-item"><div><strong>' . esc_html( $config['name'] ) . '</strong>';
            self::badge( $id, $definitions );
            if ( ! empty( $record['checked_at'] ) && hash_equals( (string) $config['sig'], (string) ( $record['sig'] ?? '' ) ) ) {
                echo '<small>Última prueba: ' . esc_html( wp_date( 'd/m/Y H:i', (int) $record['checked_at'] ) ) . '</small>';
                if ( 'error' === $state ) {
                    $code = sanitize_key( (string) ( $record['error_code'] ?? '' ) );
                    $help = array( 'property_access_denied' => 'La propiedad no está autorizada.', 'bing_site_denied' => 'El sitio no está autorizado.', 'no_credentials' => 'Faltan credenciales.', 'connection_unavailable' => 'El servicio no respondió.', 'invalid_response' => 'Respuesta no válida.' );
                    $detail = isset( $help[ $code ] ) ? $help[ $code ] : ( preg_match( '/^http_[0-9]{3}$/', $code ) ? 'Respuesta ' . strtoupper( str_replace( '_', ' ', $code ) ) : 'Revisa permisos, credenciales y acceso a la propiedad.' );
                    echo '<small>' . esc_html( $detail ) . '</small>';
                }
            } elseif ( 'configured' === $state && 'test' === $config['mode'] ) {
                echo '<small>Prueba la conexión para verificarla.</small>';
            } elseif ( 'local' === $config['mode'] && 'configured' === $state ) {
                echo '<small>Configuración local guardada; sin validación externa.</small>';
            }
            echo '</div>';
            if ( 'test' === $config['mode'] && ! empty( $config['ready'] ) && empty( $config['disabled'] ) ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="tgb_check_connection">';
                echo '<input type="hidden" name="provider" value="' . esc_attr( $id ) . '">';
                wp_nonce_field( 'tgb_check_connection_' . $id );
                echo '<button class="button button-small" type="submit">Comprobar</button></form>';
            }
            echo '</article>';
        }
        echo '</div><p class="description">«Conectado» significa que el proveedor respondió correctamente en una prueba manual reciente (máximo 7 días). Cambiar credenciales exige repetirla. Los enlaces de afiliados y los catálogos configurados no equivalen a una conexión API validada.</p>';
        echo '</section>';
    }

    private static function request( $url, $token ) {
        $response = wp_remote_get( $url, array(
            'timeout' => 12,
            'redirection' => 1,
            'limit_response_size' => 65536,
            'headers' => array( 'Accept' => 'application/json', 'Authorization' => 'Bearer ' . $token ),
        ) );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'connection_unavailable', 'Conexión no disponible.' );
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'http_' . $code, 'El proveedor rechazó la conexión.' );
        }
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        return is_array( $body ) ? $body : new WP_Error( 'invalid_response', 'Respuesta no válida.' );
    }

    private static function verify( $id ) {
        $google = TaxoCommerce_Connections_Google_Bing::google_settings();
        if ( 'oauth' === $id ) {
            $properties = TaxoCommerce_Google_Bing_OAuth::properties( true );
            return is_wp_error( $properties ) ? $properties : true;
        }
        if ( 'gsc' === $id ) {
            // Use the same credential/property combinations as the report downloader.
            $oauth = TaxoCommerce_Google_Bing_OAuth::settings();
            $legacy = get_option( 'seo_google_intelligence_settings', array() );
            $legacy_site = is_array( $legacy ) ? trim( (string) ( $legacy['property_id'] ?? '' ) ) : '';
            $oauth_site = trim( (string) ( $oauth['property_id'] ?? '' ) );
            $service_site = trim( (string) ( $google['search_console_site_url'] ?? '' ) );
            $candidates = array();
            if ( '' !== $oauth_site ) { $candidates[] = array( 'oauth', $oauth_site ); }
            if ( '' !== $legacy_site && $legacy_site !== $oauth_site ) { $candidates[] = array( 'oauth', $legacy_site ); }
            if ( '' !== $legacy_site && function_exists( 'seo_google_get_access_token' ) ) {
                $candidates[] = array( 'legacy_oauth', $legacy_site );
            }
            if ( '' !== $service_site && ! empty( $google['service_account_json'] ) ) {
                $candidates[] = array( 'service_account', $service_site );
            }
            if ( '' === $oauth_site && '' !== $service_site ) { $candidates[] = array( 'oauth', $service_site ); }
            if ( '' === $service_site && '' !== $oauth_site && ! empty( $google['service_account_json'] ) ) {
                $candidates[] = array( 'service_account', $oauth_site );
            }
            $tokens = array();
            $last_error = new WP_Error( 'property_access_denied', 'No authorized Search Console property was found.' );
            foreach ( $candidates as $candidate ) {
                list( $method, $site ) = $candidate;
                if ( ! array_key_exists( $method, $tokens ) ) {
                    if ( 'oauth' === $method ) {
                        $tokens[ $method ] = TaxoCommerce_Google_Bing_OAuth::access_token();
                    } elseif ( 'legacy_oauth' === $method ) {
                        $tokens[ $method ] = seo_google_get_access_token();
                    } else {
                        $tokens[ $method ] = TaxoCommerce_Google_Bing_Connections::access_token( 'https://www.googleapis.com/auth/webmasters.readonly' );
                    }
                }
                $token = $tokens[ $method ];
                if ( is_wp_error( $token ) ) { $last_error = $token; continue; }
                if ( ! is_string( $token ) || '' === $token ) { continue; }
                $url = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site ) . '/searchAnalytics/query';
                $response = wp_remote_post( $url, array(
                    'timeout' => 20,
                    'headers' => array( 'Accept' => 'application/json', 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
                    'body' => wp_json_encode( array( 'startDate' => gmdate( 'Y-m-d', strtotime( '-4 days' ) ), 'endDate' => gmdate( 'Y-m-d', strtotime( '-3 days' ) ), 'rowLimit' => 1 ) ),
                ) );
                if ( is_wp_error( $response ) ) { $last_error = $response; continue; }
                $code = (int) wp_remote_retrieve_response_code( $response );
                if ( $code >= 200 && $code < 300 ) {
                    $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
                    if ( is_array( $data ) ) { return true; }
                }
                $last_error = new WP_Error( 'http_' . $code, 'Search Console rejected the selected credential/property combination.' );
            }
            return $last_error;
        }
        if ( 'ga4' === $id || 'merchant' === $id ) {
            $scope = 'ga4' === $id ? 'https://www.googleapis.com/auth/analytics.readonly' : 'https://www.googleapis.com/auth/content';
            $service = (string) ( $google['service_account_json'] ?? '' );
            if ( '' === $service ) { return new WP_Error( 'no_credentials', 'Credenciales no configuradas.' ); }
            $token = TaxoCommerce_Google_Bing_Connections::access_token( $scope, true );
            if ( is_wp_error( $token ) ) { return $token; }
            if ( 'ga4' === $id ) {
                $idnum = preg_replace( '/\\D+/', '', (string) ( $google['analytics_property_id'] ?? '' ) );
                $response = wp_remote_post(
                    'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode( $idnum ) . ':runReport',
                    array(
                        'timeout' => 20,
                        'headers' => array(
                            'Authorization' => 'Bearer ' . $token,
                            'Content-Type' => 'application/json',
                            'Accept' => 'application/json',
                        ),
                        'body' => wp_json_encode( array(
                            'dateRanges' => array( array( 'startDate' => '4daysAgo', 'endDate' => '3daysAgo' ) ),
                            'metrics' => array( array( 'name' => 'sessions' ) ),
                            'limit' => 1,
                        ) ),
                    )
                );
                if ( is_wp_error( $response ) ) { return $response; }
                $code = (int) wp_remote_retrieve_response_code( $response );
                if ( $code < 200 || $code >= 300 ) {
                    return new WP_Error( 'http_' . $code, 'GA4 rejected the report request.' );
                }
                $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
                return is_array( $data ) ? true : new WP_Error( 'invalid_response', 'Invalid GA4 report response.' );
            }
            $merchant = TaxoCommerce_Connections_Google_Bing::merchant_account();
            return self::request( 'https://merchantapi.googleapis.com/accounts/v1/accounts/' . rawurlencode( $merchant ), $token );
        }
        if ( 'bing' === $id ) {
            $bing = TaxoCommerce_Connections_Google_Bing::bing_settings();
            $key = defined( 'SEO_BING_WEBMASTER_API_KEY' ) && trim( (string) SEO_BING_WEBMASTER_API_KEY )
                ? (string) SEO_BING_WEBMASTER_API_KEY : (string) ( $bing['api_key'] ?? '' );
            $url = add_query_arg( array( 'apikey' => $key ), 'https://ssl.bing.com/webmaster/api.svc/json/GetUserSites' );
            $response = wp_remote_get( $url, array( 'timeout' => 12, 'redirection' => 1, 'limit_response_size' => 65536 ) );
            if ( is_wp_error( $response ) ) { return new WP_Error( 'bing_unavailable', 'Bing no disponible.' ); }
            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( $code < 200 || $code >= 300 ) { return new WP_Error( 'http_' . $code, 'Bing rechazó la conexión.' ); }
            $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
            $site = untrailingslashit( strtolower( (string) ( $bing['site_url'] ?? '' ) ) );
            foreach ( (array) ( $body['d'] ?? array() ) as $entry ) {
                if ( untrailingslashit( strtolower( (string) ( $entry['Url'] ?? '' ) ) ) === $site ) { return true; }
            }
            return new WP_Error( 'bing_site_denied', 'Sitio no autorizado.' );
        }
        if ( 'amazon_api' === $id ) { return TaxoCommerce_Connections_Amazon::access_token( true ); }
        if ( 'pinterest' === $id ) { return TaxoCommerce_Connections_Pinterest::test_connection(); }
        return new WP_Error( 'unsupported_provider', 'Proveedor no comprobable.' );
    }

    public static function check() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sin permisos para comprobar conexiones.', 'taxocommerce-google-bing' ) );
        }
        $id = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
        check_admin_referer( 'tgb_check_connection_' . $id );
        $definitions = self::definitions();
        if ( ! isset( $definitions[ $id ] ) || 'test' !== $definitions[ $id ]['mode']
            || empty( $definitions[ $id ]['ready'] ) || ! empty( $definitions[ $id ]['disabled'] ) ) {
            wp_die( esc_html__( 'La conexión no está preparada para comprobarse.', 'taxocommerce-google-bing' ) );
        }
        $outcome = self::verify( $id );
        $records = self::records();
        // Token refresh during the explicit check may update the OAuth option.
        $current = self::definitions();
        // Do not persist API messages or tokens; only a code, boolean and timestamp.
        $records[ $id ] = array(
            'ok'         => ! is_wp_error( $outcome ) ? 1 : 0,
            'checked_at' => time(),
            'sig'        => $current[ $id ]['sig'],
            'error_code' => is_wp_error( $outcome ) ? sanitize_key( $outcome->get_error_code() ) : '',
        );
        update_option( self::OPTION, $records, false );
        $url = TaxoCommerce_Connections_Admin::url( array( 'tc_health_checked' => $id ) ) . '#tgb-health-heading';
        wp_safe_redirect( $url );
        exit;
    }

    public static function notice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only verification notice.
        $id = isset( $_GET['tc_health_checked'] ) ? sanitize_key( wp_unslash( $_GET['tc_health_checked'] ) ) : '';
        if ( '' === $id ) { return; }
        $definitions = self::definitions();
        if ( ! isset( $definitions[ $id ] ) ) { return; }
        $records = self::records();
        $state = self::state( $definitions[ $id ], $records[ $id ] ?? array() );
        echo '<div class="notice notice-' . esc_attr( 'connected' === $state ? 'success' : 'warning' ) . ' inline"><p>';
        echo esc_html( $definitions[ $id ]['name'] . ': ' . self::labels()[ $state ][0] ) . '</p></div>';
    }
}
