<?php

defined( 'ABSPATH' ) || exit;

/**
 * Connection registry for Google, Merchant, Cloud Run and Bing.
 *
 * This class intentionally does not download business/reporting data.
 * Connection tests are limited to authentication and account/property metadata.
 */
final class TaxoCommerce_Google_Bing_Connections {

    const GOOGLE_OPTION            = 'taxocommerce_google_bing_google_settings';
    const BING_OPTION              = 'taxocommerce_google_bing_bing_settings';
    const MERCHANT_ACCOUNT_OPTION  = 'taxocommerce_google_bing_merchant_account';
    const PYTHON_OPTION            = 'taxocommerce_google_bing_cloud_run_settings';
    const MIGRATION_OPTION         = 'taxocommerce_google_bing_connections_migration_v1';

    const LEGACY_GOOGLE_OPTION            = 'seo_google_search_settings';
    const LEGACY_BING_OPTION              = 'seo_analista_bing_settings';
    const LEGACY_MERCHANT_ACCOUNT_OPTION  = 'seo_ojeador_merchant_account_v1';
    const LEGACY_PYTHON_OPTION            = 'seo_google_python_runner_settings';

    public static function register() {
        self::migrate_legacy_connections();

        add_action( 'admin_post_tgb_save_google', array( __CLASS__, 'save_google' ) );
        add_action( 'admin_post_tgb_test_google', array( __CLASS__, 'test_google' ) );

        add_action( 'admin_post_tgb_save_merchant', array( __CLASS__, 'save_merchant' ) );
        add_action( 'admin_post_tgb_test_merchant', array( __CLASS__, 'test_merchant' ) );

        add_action( 'admin_post_tgb_google_python_save', array( __CLASS__, 'save_google_python' ) );
        add_action( 'admin_post_tgb_google_python_test', array( __CLASS__, 'test_google_python' ) );

        add_action( 'admin_post_tgb_save_market_providers', array( __CLASS__, 'save_market_providers' ) );
        add_action( 'admin_post_tgb_save_bing', array( __CLASS__, 'save_bing' ) );
        add_action( 'admin_post_tgb_test_bing', array( __CLASS__, 'test_bing' ) );
    }

    /**
     * Copies connection credentials from the legacy monolith to options owned by
     * this plugin. Existing modern values always win and are never overwritten.
     */
    public static function migrate_legacy_connections() {
        $copied = array();

        self::copy_legacy_option(
            self::LEGACY_GOOGLE_OPTION,
            self::GOOGLE_OPTION,
            'google_service_account',
            $copied
        );
        self::copy_legacy_option(
            self::LEGACY_BING_OPTION,
            self::BING_OPTION,
            'bing',
            $copied
        );
        self::copy_legacy_option(
            self::LEGACY_MERCHANT_ACCOUNT_OPTION,
            self::MERCHANT_ACCOUNT_OPTION,
            'merchant',
            $copied
        );
        self::copy_legacy_option(
            self::LEGACY_PYTHON_OPTION,
            self::PYTHON_OPTION,
            'cloud_run',
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
        if ( '' === $settings['site_url'] ) {
            $settings['site_url'] = home_url( '/' );
        }
        return $settings;
    }

    public static function bing_api_key_value() {
        return self::bing_api_key( self::bing_settings() );
    }

    public static function merchant_account() {
        $id = trim( (string) get_option( self::MERCHANT_ACCOUNT_OPTION, '' ) );
        return preg_match( '/^[0-9]{5,20}$/', $id ) ? $id : '';
    }

    public static function python_settings() {
        $defaults = array(
            'enabled'              => 0,
            'project_id'           => '',
            'region'               => 'europe-west1',
            'job_name'             => 'seo-supplier-scraper',
            'service_account_json' => '',
        );
        $saved = get_option( self::PYTHON_OPTION, array() );
        return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
    }

    /**
     * Returns a Google service-account access token for an explicit scope.
     * This is a connection primitive for future consumers; it does not query data.
     */
    public static function access_token( $scope = '', $force = false ) {
        $settings = self::google_settings();
        $account = self::decode_service_account( (string) $settings['service_account_json'] );
        if ( is_wp_error( $account ) ) {
            return $account;
        }

        if ( '' === trim( (string) $scope ) ) {
            $scope = 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly';
        }

        return self::service_account_access_token( $account, $scope, 'google', $force );
    }

    public static function render() {
        self::render_notice();

        echo '<div class="tgb-note"><strong>Modo conexión:</strong> este plugin únicamente guarda credenciales, obtiene/renueva tokens y verifica acceso mediante metadatos. No descarga visitas, búsquedas, consultas, rankings, ventas, productos ni informes.</div>';
        $migration = get_option( self::MIGRATION_OPTION, array() );
        if ( is_array( $migration ) && ! empty( $migration['copied'] ) ) {
            echo '<div class="notice notice-success inline"><p><strong>Credenciales antiguas migradas al plugin moderno.</strong> Conexiones copiadas: ' . esc_html( implode( ', ', array_map( 'sanitize_text_field', (array) $migration['copied'] ) ) ) . '.</p></div>';
        }

        TaxoCommerce_Google_Bing_OAuth::render();

        $google = self::google_settings();
        $google_account = self::decode_service_account( (string) $google['service_account_json'] );
        $google_ready = ! is_wp_error( $google_account );

        $merchant_account = self::merchant_account();

        $python = self::python_settings();
        $python_account = self::decode_service_account( (string) $python['service_account_json'] );
        $python_ready = '' !== trim( (string) $python['project_id'] )
            && '' !== trim( (string) $python['region'] )
            && '' !== trim( (string) $python['job_name'] )
            && ! is_wp_error( $python_account );

        $bing = self::bing_settings();
        $bing_key = self::bing_api_key( $bing );

        echo '<div class="tgb-grid">';

        echo '<section class="tgb-card">';
        echo '<div class="tgb-card-head"><div><h2>Google Analytics / Search Console · Cuenta de servicio</h2><p>Credencial compartida para APIs Google. La prueba consulta únicamente metadatos de acceso.</p></div>';
        echo self::badge( $google_ready ? 'Configurado' : 'Sin configurar', $google_ready ? 'ok' : 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';
        if ( $google_ready ) {
            echo '<p><strong>Cuenta de servicio:</strong> <code>' . esc_html( (string) $google_account['client_email'] ) . '</code></p>';
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tgb_save_google">';
        wp_nonce_field( 'tgb_save_google' );
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th><label for="tgb_gsc_site">Propiedad Search Console</label></th><td><input id="tgb_gsc_site" class="regular-text code" type="text" name="search_console_site_url" value="' . esc_attr( (string) $google['search_console_site_url'] ) . '" placeholder="https://www.ejemplo.es/ o sc-domain:ejemplo.es"></td></tr>';
        echo '<tr><th><label for="tgb_ga4_property">Propiedad GA4</label></th><td><input id="tgb_ga4_property" class="regular-text" type="text" inputmode="numeric" name="analytics_property_id" value="' . esc_attr( (string) $google['analytics_property_id'] ) . '" placeholder="123456789"></td></tr>';
        echo '<tr><th><label for="tgb_measurement">Measurement ID</label></th><td><input id="tgb_measurement" class="regular-text" type="text" name="measurement_id" value="' . esc_attr( (string) $google['measurement_id'] ) . '" placeholder="G-XXXXXXXXXX"><p class="description">Se guarda como identificador de configuración; este plugin no envía ni descarga eventos GA4.</p></td></tr>';
        echo '<tr><th><label for="tgb_google_service">Cuenta de servicio JSON</label></th><td><textarea id="tgb_google_service" class="large-text code" rows="7" name="service_account_json" placeholder="' . esc_attr( $google_ready ? 'Credencial ya configurada. Déjala vacía para conservarla.' : 'Pega aquí el JSON de la cuenta de servicio.' ) . '"></textarea><p class="description">El secreto no se vuelve a mostrar. La prueba obtiene un token y consulta sólo metadatos de las propiedades indicadas.</p></td></tr>';
        echo '</table>';
        submit_button( 'Guardar Google', 'primary', 'submit', false );
        echo '</form>';
        self::test_form( 'tgb_test_google', 'tgb_test_google', 'Probar conexión Google' );
        echo '</section>';

        echo '<section class="tgb-card">';
        echo '<div class="tgb-card-head"><div><h2>Google Merchant Center</h2><p>Identificación de la cuenta Merchant. No se consultan benchmarks, productos ni ventas.</p></div>';
        echo self::badge( $merchant_account ? 'Configurado' : 'Sin configurar', $merchant_account ? 'ok' : 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tgb_save_merchant">';
        wp_nonce_field( 'tgb_save_merchant' );
        echo '<table class="form-table" role="presentation"><tr><th><label for="tgb_merchant_account">ID de Merchant Center</label></th><td><input id="tgb_merchant_account" class="regular-text" type="text" name="merchant_account" value="' . esc_attr( $merchant_account ) . '" placeholder="ID numérico" pattern="[0-9]{5,20}"><p class="description">Usa la cuenta de servicio Google configurada arriba. La prueba sólo lee metadatos básicos de la cuenta.</p></td></tr></table>';
        submit_button( 'Guardar Merchant', 'primary', 'submit', false );
        echo '</form>';
        self::test_form( 'tgb_test_merchant', 'tgb_test_merchant', 'Probar conexión Merchant' );
        echo '</section>';

        echo '<section class="tgb-card">';
        echo '<div class="tgb-card-head"><div><h2>Google Cloud Run</h2><p>Credenciales y referencia del Job. El plugin no ejecuta el Job desde esta pantalla.</p></div>';
        echo self::badge( $python_ready ? 'Configurado' : 'Sin configurar', $python_ready ? 'ok' : 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';
        if ( ! is_wp_error( $python_account ) && ! empty( $python_account['client_email'] ) ) {
            echo '<p><strong>Cuenta de servicio:</strong> <code>' . esc_html( (string) $python_account['client_email'] ) . '</code></p>';
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tgb_google_python_save">';
        wp_nonce_field( 'tgb_google_python_save' );
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th><label for="tgb_python_project">Google Cloud Project ID</label></th><td><input id="tgb_python_project" type="text" class="regular-text" name="project_id" value="' . esc_attr( (string) $python['project_id'] ) . '" placeholder="mi-proyecto-google"></td></tr>';
        echo '<tr><th><label for="tgb_python_region">Región</label></th><td><input id="tgb_python_region" type="text" class="regular-text" name="region" value="' . esc_attr( (string) $python['region'] ) . '" placeholder="europe-west1"></td></tr>';
        echo '<tr><th><label for="tgb_python_job">Cloud Run Job</label></th><td><input id="tgb_python_job" type="text" class="regular-text" name="job_name" value="' . esc_attr( (string) $python['job_name'] ) . '" placeholder="seo-supplier-scraper"></td></tr>';
        echo '<tr><th><label for="tgb_python_json">Cuenta de servicio JSON</label></th><td><textarea id="tgb_python_json" class="large-text code" rows="7" name="service_account_json" placeholder="' . esc_attr( ! is_wp_error( $python_account ) ? 'Credencial ya configurada. Déjala vacía para conservarla.' : 'Pega aquí el JSON de la cuenta de servicio de Cloud Run.' ) . '"></textarea><p class="description">La prueba sólo lee la configuración del Job; no lo ejecuta.</p></td></tr>';
        echo '</table>';
        submit_button( 'Guardar Google Cloud Run', 'primary', 'submit', false );
        echo '</form>';
        self::test_form( 'tgb_google_python_test', 'tgb_google_python_test', 'Probar conexión Cloud Run' );
        echo '</section>';

        echo '<section class="tgb-card">';
        echo '<div class="tgb-card-head"><div><h2>Microsoft Bing Webmaster</h2><p>API key y sitio verificado. La prueba sólo obtiene la lista de sitios autorizados.</p></div>';
        echo self::badge( '' !== $bing_key ? 'Configurado' : 'Sin configurar', '' !== $bing_key ? 'ok' : 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';
        if ( defined( 'SEO_BING_WEBMASTER_API_KEY' ) && '' !== trim( (string) SEO_BING_WEBMASTER_API_KEY ) ) {
            echo '<p class="tgb-legacy"><strong>API key:</strong> definida mediante <code>SEO_BING_WEBMASTER_API_KEY</code> en wp-config.php.</p>';
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tgb_save_bing">';
        wp_nonce_field( 'tgb_save_bing' );
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th>Estado</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked( ! empty( $bing['enabled'] ), true, false ) . '> Conexión Bing disponible para otros módulos</label></td></tr>';
        echo '<tr><th><label for="tgb_bing_site">Sitio Bing Webmaster</label></th><td><input id="tgb_bing_site" class="regular-text code" type="url" name="site_url" value="' . esc_attr( (string) $bing['site_url'] ) . '" placeholder="https://www.ejemplo.es/"></td></tr>';
        echo '<tr><th><label for="tgb_bing_key">API key</label></th><td><input id="tgb_bing_key" class="regular-text" type="password" autocomplete="new-password" name="api_key" value="" placeholder="' . esc_attr( '' !== $bing_key ? 'Clave ya configurada. Déjala vacía para conservarla.' : 'API key de Bing Webmaster' ) . '">';
        if ( '' !== $bing_key && ! defined( 'SEO_BING_WEBMASTER_API_KEY' ) ) {
            echo '<p><label><input type="checkbox" name="clear_api_key" value="1"> Eliminar la API key guardada</label></p>';
        }
        echo '</td></tr></table>';
        submit_button( 'Guardar Bing', 'primary', 'submit', false );
        echo '</form>';
        self::test_form( 'tgb_test_bing', 'tgb_test_bing', 'Probar conexión Bing' );
        echo '</section>';

        echo '<section id="tgb-market-providers" class="tgb-card">';
        echo '<div class="tgb-card-head"><div><h2>Proveedores de Mercado · YouTube y SerpApi</h2><p>Las credenciales se administran aquí; Mercado muestra sus estados y realiza los análisis.</p></div></div>';
        echo '<p><strong>YouTube Data API v3:</strong> API de Google, pero requiere una clave propia habilitada en Google Cloud. La credencial de Google Analytics / Search Console no la sustituye.</p>';
        echo '<p><strong>SerpApi:</strong> proveedor externo y opcional para búsquedas web y ofertas Shopping. Puede generar costes y está sujeto a las cuotas del proveedor.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tgb_save_market_providers">';
        wp_nonce_field( 'tgb_save_market_providers' );
        echo '<table class="form-table" role="presentation">';
        foreach ( array( 'youtube' => 'YouTube Data API v3', 'serpapi' => 'SerpApi · búsqueda web y Shopping' ) as $provider => $label ) {
            $saved = '' !== (string) get_option( 'tgb_market_' . $provider . '_key', '' );
            echo '<tr><th><label for="tgb-provider-' . esc_attr( $provider ) . '">' . esc_html( $label ) . '</label></th><td>';
            echo self::badge( $saved ? 'Configurado' : 'Sin configurar', $saved ? 'ok' : 'warning' );
            echo '<p><input class="regular-text" id="tgb-provider-' . esc_attr( $provider ) . '" type="password" name="' . esc_attr( $provider ) . '_key" autocomplete="new-password" value="" placeholder="' . esc_attr( $saved ? 'Dejar vacío para conservar la clave' : 'Introducir API key' ) . '"></p>';
            if ( $saved ) {
                echo '<label><input type="checkbox" name="clear_' . esc_attr( $provider ) . '" value="1"> Eliminar la clave existente</label>';
            }
            echo '</td></tr>';
        }
        echo '</table>';
        submit_button( 'Guardar conexiones de Mercado', 'primary', 'submit', false );
        echo '</form><p class="description">No se muestran las claves guardadas. Esta pantalla no efectúa consultas de mercado.</p>';
        echo '</section>';

        echo '</div>';
        self::styles();
    }

    public static function save_google() {
        self::guard( 'tgb_save_google' );
        $current = self::google_settings();

        $site = isset( $_POST['search_console_site_url'] ) ? sanitize_text_field( wp_unslash( $_POST['search_console_site_url'] ) ) : '';
        if ( 0 !== strpos( $site, 'sc-domain:' ) ) {
            $site = esc_url_raw( $site );
        }

        $property = isset( $_POST['analytics_property_id'] )
            ? preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_POST['analytics_property_id'] ) ) )
            : '';

        $measurement = isset( $_POST['measurement_id'] )
            ? strtoupper( sanitize_text_field( wp_unslash( $_POST['measurement_id'] ) ) )
            : '';
        if ( '' !== $measurement && ! preg_match( '/^G-[A-Z0-9]+$/', $measurement ) ) {
            self::redirect( 'google_measurement_invalid', 'error' );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON must preserve PEM newlines and is structurally validated.
        $raw_service = isset( $_POST['service_account_json'] ) ? trim( (string) wp_unslash( $_POST['service_account_json'] ) ) : '';
        $service = (string) $current['service_account_json'];
        if ( '' !== $raw_service ) {
            $decoded = self::decode_service_account( $raw_service );
            if ( is_wp_error( $decoded ) ) {
                self::redirect( 'google_credentials_invalid', 'error' );
            }
            $service = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES );
        }

        update_option(
            self::GOOGLE_OPTION,
            array(
                // Preserve the historical flag but never toggle or use it here.
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

    public static function test_google() {
        self::guard( 'tgb_test_google' );

        $settings = self::google_settings();
        $token = self::access_token( '', true );
        if ( is_wp_error( $token ) ) {
            self::store_test_error( 'google', $token->get_error_message() );
            self::redirect( 'google_test_failed', 'error' );
        }

        $messages = array( 'Autenticación de cuenta de servicio correcta.' );

        $site = trim( (string) $settings['search_console_site_url'] );
        if ( '' !== $site ) {
            $response = wp_remote_get(
                'https://www.googleapis.com/webmasters/v3/sites',
                array(
                    'timeout' => 20,
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $token,
                        'Accept'        => 'application/json',
                    ),
                )
            );
            if ( is_wp_error( $response ) ) {
                self::test_failure( 'google', 'Search Console: ' . $response->get_error_message(), 'google_test_failed' );
            }
            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( $code < 200 || $code >= 300 ) {
                self::test_failure( 'google', 'Search Console respondió HTTP ' . $code . '.', 'google_test_failed' );
            }

            $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
            $found = false;
            foreach ( (array) ( $body['siteEntry'] ?? array() ) as $entry ) {
                if ( isset( $entry['siteUrl'] ) && (string) $entry['siteUrl'] === $site ) {
                    $found = true;
                    break;
                }
            }
            if ( ! $found ) {
                self::test_failure( 'google', 'La cuenta se autentica, pero no aparece acceso a la propiedad Search Console indicada.', 'google_test_failed' );
            }
            $messages[] = 'Search Console: propiedad accesible.';
        }

        $property = preg_replace( '/\D+/', '', (string) $settings['analytics_property_id'] );
        if ( '' !== $property ) {
            $response = wp_remote_get(
                'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode( $property ) . '/metadata',
                array(
                    'timeout'             => 20,
                    'limit_response_size' => 65536,
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $token,
                        'Accept'        => 'application/json',
                    ),
                )
            );
            if ( is_wp_error( $response ) ) {
                self::test_failure( 'google', 'Google Analytics: ' . $response->get_error_message(), 'google_test_failed' );
            }
            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( $code < 200 || $code >= 300 ) {
                self::test_failure( 'google', 'Google Analytics respondió HTTP ' . $code . '.', 'google_test_failed' );
            }
            $messages[] = 'GA4: propiedad accesible.';
        }

        set_transient( 'tgb_google_test_message_' . get_current_user_id(), implode( ' ', $messages ), 2 * MINUTE_IN_SECONDS );
        self::redirect( 'google_test_ok', 'success' );
    }

    public static function save_merchant() {
        self::guard( 'tgb_save_merchant' );

        $account = isset( $_POST['merchant_account'] )
            ? sanitize_text_field( wp_unslash( $_POST['merchant_account'] ) )
            : '';

        if ( '' !== $account && ! preg_match( '/^[0-9]{5,20}$/', $account ) ) {
            self::redirect( 'merchant_account_invalid', 'error' );
        }

        update_option( self::MERCHANT_ACCOUNT_OPTION, $account, false );
        self::redirect( 'merchant_saved', 'success' );
    }

    public static function test_merchant() {
        self::guard( 'tgb_test_merchant' );

        $account = self::merchant_account();
        if ( '' === $account ) {
            self::test_failure( 'merchant', 'Falta el ID de Merchant Center.', 'merchant_test_failed' );
        }

        $settings = self::google_settings();
        $service = self::decode_service_account( (string) $settings['service_account_json'] );
        if ( is_wp_error( $service ) ) {
            self::test_failure( 'merchant', 'Falta una cuenta de servicio Google válida.', 'merchant_test_failed' );
        }

        $token = self::service_account_access_token(
            $service,
            'https://www.googleapis.com/auth/content',
            'merchant',
            true
        );
        if ( is_wp_error( $token ) ) {
            self::test_failure( 'merchant', $token->get_error_message(), 'merchant_test_failed' );
        }

        $response = wp_remote_get(
            'https://merchantapi.googleapis.com/accounts/v1/accounts/' . rawurlencode( $account ),
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                ),
            )
        );
        if ( is_wp_error( $response ) ) {
            self::test_failure( 'merchant', $response->get_error_message(), 'merchant_test_failed' );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            self::test_failure( 'merchant', 'Merchant API respondió HTTP ' . $code . '.', 'merchant_test_failed' );
        }

        self::redirect( 'merchant_test_ok', 'success' );
    }

    public static function save_google_python() {
        self::guard( 'tgb_google_python_save' );

        $current = self::python_settings();
        $project = isset( $_POST['project_id'] ) ? sanitize_text_field( wp_unslash( $_POST['project_id'] ) ) : '';
        $region = isset( $_POST['region'] ) ? sanitize_key( wp_unslash( $_POST['region'] ) ) : '';
        $job = isset( $_POST['job_name'] ) ? sanitize_key( wp_unslash( $_POST['job_name'] ) ) : '';

        if ( '' === $project || '' === $region || '' === $job ) {
            self::redirect( 'google_python_fields_missing', 'error' );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON must preserve PEM newlines and is structurally validated.
        $raw = isset( $_POST['service_account_json'] ) ? trim( (string) wp_unslash( $_POST['service_account_json'] ) ) : '';
        $json = (string) $current['service_account_json'];
        if ( '' !== $raw ) {
            $decoded = self::decode_service_account( $raw );
            if ( is_wp_error( $decoded ) ) {
                self::redirect( 'google_python_credentials_invalid', 'error' );
            }
            $json = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES );
        }

        update_option(
            self::PYTHON_OPTION,
            array(
                // Preserve legacy execution preference, but this plugin never executes the job.
                'enabled'              => ! empty( $current['enabled'] ) ? 1 : 0,
                'project_id'           => $project,
                'region'               => $region,
                'job_name'             => $job,
                'service_account_json' => $json,
            ),
            false
        );

        self::redirect( 'google_python_saved', 'success' );
    }

    public static function test_google_python() {
        self::guard( 'tgb_google_python_test' );

        $settings = self::python_settings();
        $service = self::decode_service_account( (string) $settings['service_account_json'] );
        if ( is_wp_error( $service ) ) {
            self::test_failure( 'google_python', 'Falta una cuenta de servicio válida para Cloud Run.', 'google_python_test_failed' );
        }

        $token = self::service_account_access_token(
            $service,
            'https://www.googleapis.com/auth/cloud-platform',
            'cloud_run',
            true
        );
        if ( is_wp_error( $token ) ) {
            self::test_failure( 'google_python', $token->get_error_message(), 'google_python_test_failed' );
        }

        $url = 'https://run.googleapis.com/v2/projects/'
            . rawurlencode( (string) $settings['project_id'] )
            . '/locations/' . rawurlencode( (string) $settings['region'] )
            . '/jobs/' . rawurlencode( (string) $settings['job_name'] );

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                ),
            )
        );
        if ( is_wp_error( $response ) ) {
            self::test_failure( 'google_python', $response->get_error_message(), 'google_python_test_failed' );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            self::test_failure( 'google_python', 'Cloud Run respondió HTTP ' . $code . '.', 'google_python_test_failed' );
        }

        self::redirect( 'google_python_test_ok', 'success' );
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

    public static function test_bing() {
        self::guard( 'tgb_test_bing' );

        $settings = self::bing_settings();
        $key = self::bing_api_key( $settings );
        if ( '' === $key ) {
            self::test_failure( 'bing', 'Falta la API key de Bing Webmaster.', 'bing_test_failed' );
        }
        if ( empty( $settings['enabled'] ) ) {
            self::test_failure( 'bing', 'La conexión Bing está desactivada.', 'bing_test_failed' );
        }

        $url = add_query_arg(
            array( 'apikey' => $key ),
            'https://ssl.bing.com/webmaster/api.svc/json/GetUserSites'
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
            self::test_failure( 'bing', $response->get_error_message(), 'bing_test_failed' );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            self::test_failure( 'bing', 'Bing Webmaster respondió HTTP ' . $code . '.', 'bing_test_failed' );
        }

        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        $target = untrailingslashit( strtolower( (string) $settings['site_url'] ) );
        $found = false;
        foreach ( (array) ( $body['d'] ?? array() ) as $site ) {
            $url_value = untrailingslashit( strtolower( (string) ( $site['Url'] ?? '' ) ) );
            if ( '' !== $target && $url_value === $target ) {
                $found = true;
                break;
            }
        }

        if ( '' !== $target && ! $found ) {
            self::test_failure( 'bing', 'La API key es válida, pero el sitio configurado no aparece entre los sitios autorizados.', 'bing_test_failed' );
        }

        self::redirect( 'bing_test_ok', 'success' );
    }

    private static function decode_service_account( $raw ) {
        $raw = trim( (string) $raw );
        if ( '' === $raw ) {
            return new WP_Error( 'tgb_missing_service_account', 'Cuenta de servicio no configurada.' );
        }

        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) || empty( $data['client_email'] ) || empty( $data['private_key'] ) ) {
            return new WP_Error( 'tgb_invalid_service_account', 'El JSON de la cuenta de servicio no es válido.' );
        }

        return $data;
    }

    private static function service_account_access_token( $account, $scope, $cache_prefix, $force = false ) {
        if ( ! is_array( $account ) || empty( $account['client_email'] ) || empty( $account['private_key'] ) ) {
            return new WP_Error( 'tgb_invalid_service_account', 'La cuenta de servicio no es válida.' );
        }
        if ( ! function_exists( 'openssl_sign' ) ) {
            return new WP_Error( 'tgb_openssl_missing', 'OpenSSL no está disponible en PHP.' );
        }

        $scope = trim( (string) $scope );
        $cache_key = 'tgb_token_' . sanitize_key( $cache_prefix ) . '_' . md5( (string) $account['client_email'] . '|' . $scope );
        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( is_string( $cached ) && '' !== $cached ) {
                return $cached;
            }
        }

        $token_uri = ! empty( $account['token_uri'] )
            ? esc_url_raw( (string) $account['token_uri'] )
            : 'https://oauth2.googleapis.com/token';

        $now = time();
        $header = self::base64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
        $claims = self::base64url(
            wp_json_encode(
                array(
                    'iss'   => (string) $account['client_email'],
                    'scope' => $scope,
                    'aud'   => $token_uri,
                    'iat'   => $now,
                    'exp'   => $now + 3500,
                )
            )
        );

        $unsigned = $header . '.' . $claims;
        $signature = '';
        if ( ! openssl_sign( $unsigned, $signature, (string) $account['private_key'], OPENSSL_ALGO_SHA256 ) ) {
            return new WP_Error( 'tgb_token_sign_failed', 'No se pudo firmar la solicitud de token.' );
        }

        $response = wp_remote_post(
            $token_uri,
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
            $message = is_array( $data ) && ! empty( $data['error_description'] )
                ? sanitize_text_field( (string) $data['error_description'] )
                : 'El proveedor no ha emitido un access token.';
            return new WP_Error( 'tgb_token_failed', $message );
        }

        $ttl = max( 300, min( 3300, absint( $data['expires_in'] ?? 3600 ) - 60 ) );
        set_transient( $cache_key, (string) $data['access_token'], $ttl );

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

    private static function test_form( $action, $nonce, $label ) {
        echo '<form class="tgb-test-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
        wp_nonce_field( $nonce );
        submit_button( $label, 'secondary', 'submit', false );
        echo '</form>';
    }

    public static function save_market_providers() {
        self::guard( 'tgb_save_market_providers' );
        foreach ( array( 'youtube', 'serpapi' ) as $provider ) {
            $option = 'tgb_market_' . $provider . '_key';
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above with self::guard().
            if ( ! empty( $_POST[ 'clear_' . $provider ] ) ) {
                delete_option( $option );
                continue;
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above with self::guard().
            $raw = isset( $_POST[ $provider . '_key' ] )
                ? sanitize_text_field( wp_unslash( $_POST[ $provider . '_key' ] ) ) : '';
            if ( '' !== $raw && strlen( $raw ) <= 180 ) {
                update_option( $option, $raw, false );
            }
        }
        self::redirect( 'market_providers_saved', 'success' );
    }

    private static function guard( $action ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Acceso no autorizado.', 'taxocommerce-google-bing' ) );
        }
        check_admin_referer( $action );
    }

    private static function test_failure( $provider, $message, $notice ) {
        self::store_test_error( $provider, $message );
        self::redirect( $notice, 'error' );
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
        set_transient(
            'tgb_' . sanitize_key( $provider ) . '_test_error_' . get_current_user_id(),
            sanitize_text_field( (string) $message ),
            2 * MINUTE_IN_SECONDS
        );
    }

    public static function render_notice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect status.
        $code = isset( $_GET['tgb_notice'] ) ? sanitize_key( wp_unslash( $_GET['tgb_notice'] ) ) : '';
        if ( '' === $code ) {
            return;
        }

        $messages = array(
            'google_saved'                       => 'Configuración de Google guardada.',
            'google_test_ok'                     => 'Conexión Google verificada sin descargar datos de informes.',
            'google_measurement_invalid'         => 'El Measurement ID no tiene un formato válido.',
            'google_credentials_invalid'         => 'El JSON de la cuenta de servicio no es válido.',
            'google_test_failed'                 => 'La prueba de Google ha fallado.',

            'merchant_saved'                     => 'Configuración de Merchant guardada.',
            'merchant_test_ok'                   => 'Conexión Merchant verificada sin descargar datos de informes.',
            'merchant_account_invalid'           => 'El ID de Merchant Center no es válido.',
            'merchant_test_failed'               => 'La prueba de Merchant ha fallado.',

            'google_python_saved'                => 'Configuración de Google Cloud Run guardada.',
            'google_python_test_ok'              => 'Conexión Cloud Run verificada; el Job no se ha ejecutado.',
            'google_python_test_failed'          => 'La prueba de Google Cloud Run ha fallado.',
            'google_python_credentials_invalid'  => 'El JSON de la cuenta de servicio de Google Cloud Run no es válido.',
            'google_python_fields_missing'       => 'Project ID, región y Cloud Run Job son obligatorios.',

            'market_providers_saved'             => 'Conexiones de YouTube y SerpApi guardadas. Se conservan las claves que se han dejado en blanco.',
            'bing_saved'                         => 'Configuración de Bing guardada.',
            'bing_test_ok'                       => 'Conexión Bing verificada sin descargar métricas.',
            'bing_test_failed'                   => 'La prueba de Bing ha fallado.',
        );

        if ( ! isset( $messages[ $code ] ) ) {
            return;
        }

        $error = false !== strpos( $code, 'failed' ) || false !== strpos( $code, 'invalid' );
        $detail = '';

        if ( 'google_test_failed' === $code ) {
            $detail = (string) get_transient( 'tgb_google_test_error_' . get_current_user_id() );
            delete_transient( 'tgb_google_test_error_' . get_current_user_id() );
        } elseif ( 'merchant_test_failed' === $code ) {
            $detail = (string) get_transient( 'tgb_merchant_test_error_' . get_current_user_id() );
            delete_transient( 'tgb_merchant_test_error_' . get_current_user_id() );
        } elseif ( 'google_python_test_failed' === $code ) {
            $detail = (string) get_transient( 'tgb_google_python_test_error_' . get_current_user_id() );
            delete_transient( 'tgb_google_python_test_error_' . get_current_user_id() );
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
        .tgb-card-wide{margin-top:20px;max-width:none}
        .tgb-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:16px;padding-top:14px;border-top:1px solid #f0f0f1}
        .tgb-actions form{margin:0}
        .tgb-help{margin-top:18px;border-top:1px solid #dcdcde;padding-top:14px}
        .tgb-help summary{cursor:pointer;font-weight:600}
        .tgb-card-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;border-bottom:1px solid #f0f0f1;padding-bottom:12px;margin-bottom:10px}
        .tgb-card-head h2{margin:0 0 5px}
        .tgb-card-head p{margin:0;color:#646970}
        .tgb-badge{display:inline-block;padding:4px 9px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap}
        .tgb-ok{background:#edfaef;color:#116329}
        .tgb-warning{background:#fff8e5;color:#755b00}
        .tgb-test-form{margin-top:10px;padding-top:12px;border-top:1px solid #f0f0f1}
        .tgb-legacy,.tgb-note{background:#f6f7f7;border-left:4px solid #72aee6;padding:10px 12px}
        .tgb-note{margin:18px 0;max-width:1200px}
        @media (max-width:782px){.tgb-grid{grid-template-columns:1fr}.tgb-card{padding:15px}.tgb-card-head{flex-direction:column}.tgb-card input.regular-text,.tgb-card textarea{width:100%;max-width:100%}}
        </style>';
    }
}
