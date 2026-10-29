<?php

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Google_Bing_OAuth {
    const SETTINGS_OPTION        = 'taxocommerce_google_bing_oauth_settings';
    const TOKENS_OPTION          = 'taxocommerce_google_bing_oauth_tokens';
    const MIGRATION_OPTION       = 'taxocommerce_google_bing_oauth_migration_v1';
    const LEGACY_SETTINGS_OPTION = 'seo_google_intelligence_settings';
    const LEGACY_TOKENS_OPTION   = 'seo_google_intelligence_tokens';
    const SCOPE                  = 'https://www.googleapis.com/auth/webmasters.readonly';

    public static function register() {
        self::migrate_legacy_oauth();

        add_action( 'admin_post_tgb_google_oauth_save', array( __CLASS__, 'save' ) );
        add_action( 'admin_post_tgb_google_oauth_connect', array( __CLASS__, 'connect' ) );
        add_action( 'admin_post_tgb_google_oauth_disconnect', array( __CLASS__, 'disconnect' ) );
        add_action( 'admin_post_tgb_google_oauth_test', array( __CLASS__, 'test' ) );

        // Keep the historical callback URI so Google Cloud does not need a new redirect URI.
        add_action( 'admin_post_seo_google_oauth_callback', array( __CLASS__, 'callback' ), 0 );
    }

    /**
     * Copies the legacy OAuth client configuration and tokens once the modern
     * plugin is loaded. Existing modern values are never overwritten.
     */
    public static function migrate_legacy_oauth() {
        $copied = array();

        self::copy_legacy_option(
            self::LEGACY_SETTINGS_OPTION,
            self::SETTINGS_OPTION,
            'oauth_settings',
            $copied
        );
        self::copy_legacy_option(
            self::LEGACY_TOKENS_OPTION,
            self::TOKENS_OPTION,
            'oauth_tokens',
            $copied
        );

        if ( $copied ) {
            update_option(
                self::MIGRATION_OPTION,
                array(
                    'version'     => 1,
                    'copied'      => array_values( $copied ),
                    'migrated_at' => current_time( 'mysql' ),
                ),
                false
            );
        }
    }

    private static function copy_legacy_option( $legacy_name, $modern_name, $label, &$copied ) {
        $sentinel = '__taxocommerce_missing_option__';
        $modern = get_option( $modern_name, $sentinel );

        if ( $sentinel !== $modern && ! self::connection_value_is_empty( $modern ) ) {
            return;
        }

        $legacy = get_option( $legacy_name, $sentinel );
        if ( $sentinel === $legacy || self::connection_value_is_empty( $legacy ) ) {
            return;
        }

        if ( $sentinel === $modern ) {
            add_option( $modern_name, $legacy, '', false );
        } else {
            update_option( $modern_name, $legacy, false );
        }

        $copied[] = $label;
    }

    private static function connection_value_is_empty( $value ) {
        if ( is_array( $value ) ) {
            foreach ( $value as $item ) {
                if ( ! self::connection_value_is_empty( $item ) ) {
                    return false;
                }
            }
            return true;
        }

        return null === $value || false === $value || '' === trim( (string) $value );
    }

    public static function settings() {
        $defaults = array(
            'client_id'      => '',
            'client_secret'  => '',
            'property_id'    => '',
            'sync_frequency' => 'manual',
            'last_sync'      => '',
        );
        $saved = get_option( self::SETTINGS_OPTION, array() );
        return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
    }

    public static function tokens() {
        $defaults = array(
            'access_token'  => '',
            'refresh_token' => '',
            'expires_at'    => 0,
            'scope'         => '',
            'token_type'    => 'Bearer',
        );
        $saved = get_option( self::TOKENS_OPTION, array() );
        return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
    }

    public static function redirect_uri() {
        return admin_url( 'admin-post.php?action=seo_google_oauth_callback' );
    }

    public static function status() {
        $settings = self::settings();
        $tokens   = self::tokens();

        if ( empty( $settings['client_id'] ) || empty( $settings['client_secret'] ) ) {
            return 'missing_credentials';
        }
        if ( empty( $tokens['refresh_token'] ) && empty( $tokens['access_token'] ) ) {
            return 'not_authorized';
        }
        if ( empty( $settings['property_id'] ) ) {
            return 'missing_property';
        }
        return 'connected';
    }

    public static function render() {
        $settings = self::settings();
        $tokens   = self::tokens();
        $status   = self::status();

        $labels = array(
            'missing_credentials' => array( 'Missing credentials', 'warning' ),
            'not_authorized'      => array( 'Credentials saved; Google authorization required', 'warning' ),
            'missing_property'    => array( 'Google connected; select a property', 'warning' ),
            'connected'           => array( 'Connected', 'ok' ),
        );
        $label = $labels[ $status ] ?? array( 'Unknown status', 'warning' );

        echo '<section class="tgb-card tgb-card-wide">';
        echo '<div class="tgb-card-head"><div><h2>Google Search Console · OAuth</h2><p>Credentials, authorization, and tokens. It does not download queries, clicks, impressions, or positions.</p></div>';
        echo '<span class="tgb-badge tgb-' . esc_attr( $label[1] ) . '">' . esc_html( $label[0] ) . '</span></div>';

        self::notice();

        $migration = get_option( self::MIGRATION_OPTION, array() );
        if ( is_array( $migration ) && ! empty( $migration['copied'] ) ) {
            echo '<div class="notice notice-success inline"><p><strong>Legacy OAuth migrated to the modern plugin.</strong></p></div>';
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tgb_google_oauth_save">';
        wp_nonce_field( 'tgb_google_oauth_save' );
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th><label for="tgb-google-client-id">Client ID</label></th><td>';
        echo '<input type="text" id="tgb-google-client-id" name="client_id" value="' . esc_attr( (string) $settings['client_id'] ) . '" class="regular-text" autocomplete="off">';
        echo '<p class="description">OAuth 2.0 client of type Web application.</p></td></tr>';

        echo '<tr><th><label for="tgb-google-client-secret">Client Secret</label></th><td>';
        echo '<input type="password" id="tgb-google-client-secret" name="client_secret" value="" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr( ! empty( $settings['client_secret'] ) ? 'Stored; leave blank to keep it' : '' ) . '">';
        echo '<p class="description">Stored in WordPress and never displayed again.</p></td></tr>';

        echo '<tr><th>Redirect URI</th><td>';
        echo '<input type="text" readonly value="' . esc_attr( self::redirect_uri() ) . '" class="large-text code" onclick="this.select();">';
        echo '<p class="description">Must exactly match the authorized URI in Google Cloud.</p></td></tr>';

        echo '<tr><th><label for="tgb-google-oauth-property">Search Console property</label></th><td>';
        echo '<input type="text" id="tgb-google-oauth-property" name="property_id" value="' . esc_attr( (string) $settings['property_id'] ) . '" class="regular-text" placeholder="https://www.example.com/ or sc-domain:example.com">';
        echo '<p class="description">Opening this screen does not query Search Console. The property is validated only when you click Test connection.</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
        submit_button( 'Save settings', 'primary', 'submit', false );
        echo '</form>';

        echo '<div class="tgb-actions">';
        if ( 'missing_credentials' !== $status ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tgb_google_oauth_connect">';
            wp_nonce_field( 'tgb_google_oauth_connect' );
            submit_button( in_array( $status, array( 'missing_property', 'connected' ), true ) ? 'Reauthorize Google' : 'Connect Google', 'primary', 'submit', false );
            echo '</form>';
        }

        if ( ! empty( $tokens['refresh_token'] ) || ! empty( $tokens['access_token'] ) ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tgb_google_oauth_test">';
            wp_nonce_field( 'tgb_google_oauth_test' );
            submit_button( 'Test connection', 'secondary', 'submit', false );
            echo '</form>';

            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'The stored Google OAuth authorization will be removed. Continue?\');"><input type="hidden" name="action" value="tgb_google_oauth_disconnect">';
            wp_nonce_field( 'tgb_google_oauth_disconnect' );
            submit_button( 'Disconnect', 'delete', 'submit', false );
            echo '</form>';
        }
        echo '</div>';

        echo '<details class="tgb-help"><summary>How to obtain these credentials</summary><ol>';
        echo '<li>Google Cloud Console → the corresponding project.</li>';
        echo '<li>Enable the Google Search Console API.</li>';
        echo '<li>Google Auth Platform → Clients.</li>';
        echo '<li>Create or open an OAuth 2.0 client of type Web application.</li>';
        echo '<li>Add exactly the Redirect URI shown above.</li>';
        echo '<li>Copy the Client ID and Client Secret, save them, then click Connect Google.</li>';
        echo '</ol></details>';

        echo '</section>';
    }

    public static function save() {
        self::guard( 'tgb_google_oauth_save' );
        $settings = self::settings();

        // The admin-post guard verifies capability and the action nonce before processing POST values.
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked by self::guard() above.
        $client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
        $client_secret = isset( $_POST['client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) : '';
        if ( '' !== $client_id ) {
            $settings['client_id'] = $client_id;
        }
        if ( '' !== $client_secret ) {
            $settings['client_secret'] = $client_secret;
        }

        $property = isset( $_POST['property_id'] ) ? sanitize_text_field( wp_unslash( $_POST['property_id'] ) ) : '';
        $settings['property_id'] = 0 === strpos( $property, 'sc-domain:' ) ? $property : esc_url_raw( $property );

        // Preserve the historical sync_frequency value. This connection-only plugin does not schedule synchronization.
        self::update_option( self::SETTINGS_OPTION, $settings );
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        self::redirect( 'oauth_saved' );
    }

    public static function connect() {
        self::guard( 'tgb_google_oauth_connect' );
        $settings = self::settings();
        if ( empty( $settings['client_id'] ) || empty( $settings['client_secret'] ) ) {
            self::redirect( 'oauth_missing_credentials', 'error' );
        }

        $state = wp_generate_password( 48, false, false );
        set_transient( 'tgb_google_oauth_state_' . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );

        $url = add_query_arg(
            array(
                'client_id'              => (string) $settings['client_id'],
                'redirect_uri'           => self::redirect_uri(),
                'response_type'          => 'code',
                'scope'                  => self::SCOPE,
                'access_type'            => 'offline',
                'include_granted_scopes' => 'true',
                'prompt'                 => 'consent',
                'state'                  => $state,
            ),
            'https://accounts.google.com/o/oauth2/v2/auth'
        );

        // Google is the only external redirect host permitted for this OAuth flow.
        add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'allow_google_oauth_host' ) );
        wp_safe_redirect( esc_url_raw( $url ), 302, 'TaxoCommerce Insights' );
        remove_filter( 'allowed_redirect_hosts', array( __CLASS__, 'allow_google_oauth_host' ) );
        exit;
    }

    /** Safelist only the exact Google OAuth authorization endpoint host. */
    public static function allow_google_oauth_host( $hosts ) {
        $hosts[] = 'accounts.google.com';
        return array_values( array_unique( $hosts ) );
    }

    public static function callback() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to complete this connection.', 'taxocommerce-google-bing' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback validates state below.
        if ( isset( $_GET['error'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            self::redirect( 'oauth_' . sanitize_key( wp_unslash( $_GET['error'] ) ), 'error' );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback validates state below.
        $received = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        $key = 'tgb_google_oauth_state_' . get_current_user_id();
        $stored = get_transient( $key );
        delete_transient( $key );

        if ( '' === $received || empty( $stored ) || ! hash_equals( (string) $stored, (string) $received ) ) {
            self::redirect( 'oauth_invalid_state', 'error' );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Authorization code accepted after state validation.
        $code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
        if ( '' === $code ) {
            self::redirect( 'oauth_missing_code', 'error' );
        }

        $settings = self::settings();
        $response = wp_remote_post(
            'https://oauth2.googleapis.com/token',
            array(
                'timeout' => 30,
                'body' => array(
                    'code'          => $code,
                    'client_id'     => (string) $settings['client_id'],
                    'client_secret' => (string) $settings['client_secret'],
                    'redirect_uri'  => self::redirect_uri(),
                    'grant_type'    => 'authorization_code',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            self::store_error( $response->get_error_message() );
            self::redirect( 'oauth_token_failed', 'error' );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( 200 !== $status || ! is_array( $body ) || empty( $body['access_token'] ) ) {
            $message = is_array( $body )
                ? sanitize_text_field( (string) ( $body['error_description'] ?? $body['error']['message'] ?? $body['error'] ?? '' ) )
                : '';
            self::store_error( $message ?: 'Google returned HTTP ' . $status . ' while exchanging the authorization code.' );
            self::redirect( 'oauth_token_failed', 'error' );
        }

        $old = self::tokens();
        $tokens = array(
            'access_token'  => sanitize_text_field( (string) $body['access_token'] ),
            'refresh_token' => ! empty( $body['refresh_token'] ) ? sanitize_text_field( (string) $body['refresh_token'] ) : (string) $old['refresh_token'],
            'expires_at'    => time() + max( 60, absint( $body['expires_in'] ?? 3600 ) - 60 ),
            'scope'         => sanitize_text_field( (string) ( $body['scope'] ?? self::SCOPE ) ),
            'token_type'    => sanitize_text_field( (string) ( $body['token_type'] ?? 'Bearer' ) ),
        );
        self::update_option( self::TOKENS_OPTION, $tokens );
        self::redirect( 'oauth_connected' );
    }

    public static function disconnect() {
        self::guard( 'tgb_google_oauth_disconnect' );
        $tokens = self::tokens();
        $token = ! empty( $tokens['refresh_token'] ) ? $tokens['refresh_token'] : $tokens['access_token'];
        if ( $token ) {
            wp_remote_post( 'https://oauth2.googleapis.com/revoke', array( 'timeout' => 15, 'body' => array( 'token' => $token ) ) );
        }
        delete_option( self::TOKENS_OPTION );
        $settings = self::settings();
        $settings['property_id'] = '';
        self::update_option( self::SETTINGS_OPTION, $settings );
        self::redirect( 'oauth_disconnected' );
    }

    public static function test() {
        self::guard( 'tgb_google_oauth_test' );
        $properties = self::properties( true );
        if ( is_wp_error( $properties ) ) {
            self::store_error( $properties->get_error_message() );
            self::redirect( 'oauth_test_failed', 'error' );
        }
        set_transient( 'tgb_google_oauth_test_' . get_current_user_id(), count( $properties ), 2 * MINUTE_IN_SECONDS );
        self::redirect( 'oauth_test_ok' );
    }

    public static function access_token( $force = false ) {
        $settings = self::settings();
        $tokens = self::tokens();

        if ( ! $force && ! empty( $tokens['access_token'] ) && (int) $tokens['expires_at'] > time() + 30 ) {
            return (string) $tokens['access_token'];
        }
        if ( empty( $tokens['refresh_token'] ) || empty( $settings['client_id'] ) || empty( $settings['client_secret'] ) ) {
            return new WP_Error( 'tgb_google_oauth_not_authorized', 'Google OAuth is not authorized or credentials are missing.' );
        }

        $response = wp_remote_post(
            'https://oauth2.googleapis.com/token',
            array(
                'timeout' => 30,
                'body' => array(
                    'client_id'     => (string) $settings['client_id'],
                    'client_secret' => (string) $settings['client_secret'],
                    'refresh_token' => (string) $tokens['refresh_token'],
                    'grant_type'    => 'refresh_token',
                ),
            )
        );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( 200 !== $status || ! is_array( $body ) || empty( $body['access_token'] ) ) {
            return new WP_Error( 'tgb_google_oauth_refresh_failed', 'Google no ha podido renovar el token OAuth.' );
        }

        $tokens['access_token'] = sanitize_text_field( (string) $body['access_token'] );
        $tokens['expires_at'] = time() + max( 60, absint( $body['expires_in'] ?? 3600 ) - 60 );
        $tokens['scope'] = sanitize_text_field( (string) ( $body['scope'] ?? $tokens['scope'] ) );
        $tokens['token_type'] = sanitize_text_field( (string) ( $body['token_type'] ?? 'Bearer' ) );
        self::update_option( self::TOKENS_OPTION, $tokens );

        return (string) $tokens['access_token'];
    }

    public static function properties( $force = false ) {
        $token = self::access_token( $force );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $response = wp_remote_get(
            'https://www.googleapis.com/webmasters/v3/sites',
            array(
                'timeout' => 25,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                ),
            )
        );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( $status < 200 || $status >= 300 ) {
            $message = is_array( $body ) && ! empty( $body['error']['message'] )
                ? sanitize_text_field( (string) $body['error']['message'] )
                : 'Search Console returned HTTP ' . $status . '.';
            return new WP_Error( 'tgb_google_oauth_sites_failed', $message );
        }

        $out = array();
        foreach ( (array) ( $body['siteEntry'] ?? array() ) as $row ) {
            $out[] = array(
                'site_url'         => sanitize_text_field( (string) ( $row['siteUrl'] ?? '' ) ),
                'permission_level' => sanitize_text_field( (string) ( $row['permissionLevel'] ?? '' ) ),
            );
        }
        return $out;
    }

    private static function guard( $nonce ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized access.', 'taxocommerce-google-bing' ) );
        }
        check_admin_referer( $nonce );
    }

    private static function update_option( $name, $value ) {
        if ( false === get_option( $name, false ) ) {
            add_option( $name, $value, '', false );
        } else {
            update_option( $name, $value, false );
        }
    }

    private static function store_error( $message ) {
        set_transient( 'tgb_google_oauth_error_' . get_current_user_id(), sanitize_text_field( (string) $message ), 2 * MINUTE_IN_SECONDS );
    }

    private static function redirect( $notice, $type = 'success' ) {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'           => 'taxocommerce-google-bing',
                    'tgb_tab'        => 'connections',
                    'tc_conn_notice' => 'google_oauth_' . sanitize_key( $notice ),
                    'tc_conn_type'   => sanitize_key( $type ),
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    private static function notice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice.
        $code = isset( $_GET['tgb_notice'] ) ? sanitize_key( wp_unslash( $_GET['tgb_notice'] ) ) : '';
        if ( 0 !== strpos( $code, 'oauth_' ) ) {
            return;
        }

        $messages = array(
            'oauth_saved'               => 'OAuth settings saved.',
            'oauth_connected'           => 'Google Search Console authorized successfully.',
            'oauth_disconnected'        => 'OAuth authorization removed.',
            'oauth_test_ok'             => 'OAuth connection verified.',
            'oauth_missing_credentials' => 'Client ID or Client Secret is missing.',
            'oauth_invalid_state'       => 'OAuth state validation failed.',
            'oauth_missing_code'        => 'Google did not return an authorization code.',
            'oauth_token_failed'        => 'OAuth authorization could not be completed.',
            'oauth_test_failed'         => 'OAuth test failed.',
            'oauth_access_denied'       => 'Google denied authorization.',
        );
        $message = $messages[ $code ] ?? $code;
        $detail = '';
        if ( in_array( $code, array( 'oauth_token_failed', 'oauth_test_failed' ), true ) ) {
            $detail = (string) get_transient( 'tgb_google_oauth_error_' . get_current_user_id() );
            delete_transient( 'tgb_google_oauth_error_' . get_current_user_id() );
        } elseif ( 'oauth_test_ok' === $code ) {
            $count = get_transient( 'tgb_google_oauth_test_' . get_current_user_id() );
            delete_transient( 'tgb_google_oauth_test_' . get_current_user_id() );
            if ( false !== $count ) {
                $detail = 'Accessible properties: ' . absint( $count ) . '.';
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect flag.
        $is_error = isset( $_GET['tgb_type'] ) && 'error' === sanitize_key( wp_unslash( $_GET['tgb_type'] ) );
        echo '<div class="notice ' . ( $is_error ? 'notice-error' : 'notice-success' ) . ' inline"><p>' . esc_html( trim( $message . ' ' . $detail ) ) . '</p></div>';
    }
}
