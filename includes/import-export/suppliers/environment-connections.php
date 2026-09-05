<?php
/**
 * SEO System - Conexiones BBDD de entornos PRO / STAGING.
 *
 * Guarda dos conexiones MySQL de solo lectura para futuras comparaciones y
 * sincronizaciones entre entornos. No ejecuta escrituras remotas.
 *
 * Recomendacion operativa: usar usuarios MySQL exclusivos con permiso SELECT,
 * restriccion por IP/VPN y TLS cuando la conexion salga de una red privada.
 *
 * @package SEOSystem
 * @subpackage ImportExport
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'seo_environment_db_allowed_envs' ) ) {
    function seo_environment_db_allowed_envs() {
        return [
            'pro'     => 'Producción',
            'staging' => 'Staging',
        ];
    }
}

if ( ! function_exists( 'seo_environment_db_crypto_key' ) ) {
    function seo_environment_db_crypto_key() {
        return hash( 'sha256', wp_salt( 'auth' ) . '|seo-system-environment-db|v1', true );
    }
}

if ( ! function_exists( 'seo_environment_db_encrypt' ) ) {
    function seo_environment_db_encrypt( $plain ) {
        $plain = (string) $plain;
        if ( '' === $plain ) {
            return '';
        }
        if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'random_bytes' ) ) {
            return new WP_Error(
                'seo_environment_db_crypto_unavailable',
                'PHP no dispone de OpenSSL/random_bytes. Define la contraseña mediante constantes en wp-config.php.'
            );
        }

        try {
            $iv = random_bytes( 12 );
        } catch ( Exception $e ) {
            return new WP_Error( 'seo_environment_db_crypto_random', 'No se pudo generar un vector aleatorio para cifrar la contraseña.' );
        }

        $tag = '';
        $ciphertext = openssl_encrypt(
            $plain,
            'aes-256-gcm',
            seo_environment_db_crypto_key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'seo-system-environment-db-v1',
            16
        );

        if ( false === $ciphertext || 16 !== strlen( $tag ) ) {
            return new WP_Error( 'seo_environment_db_crypto_failed', 'No se pudo cifrar la contraseña de la base de datos.' );
        }

        return 'v1:' . base64_encode( $iv . $tag . $ciphertext );
    }
}

if ( ! function_exists( 'seo_environment_db_decrypt' ) ) {
    function seo_environment_db_decrypt( $stored ) {
        $stored = trim( (string) $stored );
        if ( '' === $stored || 0 !== strpos( $stored, 'v1:' ) || ! function_exists( 'openssl_decrypt' ) ) {
            return '';
        }

        $raw = base64_decode( substr( $stored, 3 ), true );
        if ( false === $raw || strlen( $raw ) <= 28 ) {
            return '';
        }

        $iv         = substr( $raw, 0, 12 );
        $tag        = substr( $raw, 12, 16 );
        $ciphertext = substr( $raw, 28 );
        $plain      = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            seo_environment_db_crypto_key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'seo-system-environment-db-v1'
        );

        return is_string( $plain ) ? $plain : '';
    }
}

if ( ! function_exists( 'seo_environment_db_option' ) ) {
    function seo_environment_db_option() {
        $saved = get_option( 'seo_environment_db_connections', [] );
        return is_array( $saved ) ? $saved : [];
    }
}

if ( ! function_exists( 'seo_environment_db_constant_name' ) ) {
    function seo_environment_db_constant_name( $env, $field ) {
        $env = 'staging' === $env ? 'STAGING' : 'PRO';
        $map = [
            'host'     => 'HOST',
            'port'     => 'PORT',
            'database' => 'NAME',
            'username' => 'USER',
            'password' => 'PASSWORD',
            'prefix'   => 'PREFIX',
            'ssl_ca'   => 'SSL_CA',
        ];
        return isset( $map[ $field ] ) ? 'SEO_SYSTEM_' . $env . '_DB_' . $map[ $field ] : '';
    }
}

if ( ! function_exists( 'seo_environment_db_constant_value' ) ) {
    function seo_environment_db_constant_value( $env, $field, &$defined = false ) {
        $defined = false;
        $name    = seo_environment_db_constant_name( $env, $field );
        if ( '' !== $name && defined( $name ) ) {
            $defined = true;
            return constant( $name );
        }
        return null;
    }
}

if ( ! function_exists( 'seo_environment_db_settings' ) ) {
    /**
     * Devuelve configuracion efectiva; las constantes prevalecen por campo.
     */
    function seo_environment_db_settings( $env ) {
        $allowed = seo_environment_db_allowed_envs();
        $env     = sanitize_key( $env );
        if ( ! isset( $allowed[ $env ] ) ) {
            return [];
        }

        $saved_all = seo_environment_db_option();
        $saved     = isset( $saved_all[ $env ] ) && is_array( $saved_all[ $env ] ) ? $saved_all[ $env ] : [];
        $settings  = wp_parse_args(
            $saved,
            [
                'enabled'          => 0,
                'host'             => '',
                'port'             => 3306,
                'database'         => '',
                'username'         => '',
                'password_cipher'  => '',
                'prefix'           => 'wp_',
                'ssl_ca'           => '',
                'last_test_at'     => 0,
                'last_test_ok'     => 0,
                'last_test_error'  => '',
                'last_site_url'    => '',
                'last_server'      => '',
                'last_database'    => '',
            ]
        );

        $sources = [];
        foreach ( [ 'host', 'port', 'database', 'username', 'prefix', 'ssl_ca' ] as $field ) {
            $is_constant = false;
            $constant    = seo_environment_db_constant_value( $env, $field, $is_constant );
            if ( $is_constant ) {
                $settings[ $field ] = $constant;
                $sources[ $field ]  = 'constant';
            } else {
                $sources[ $field ] = 'database';
            }
        }

        $password_constant = false;
        $password           = seo_environment_db_constant_value( $env, 'password', $password_constant );
        if ( $password_constant ) {
            $settings['password']      = (string) $password;
            $sources['password']       = 'constant';
        } else {
            $settings['password']      = seo_environment_db_decrypt( (string) ( $settings['password_cipher'] ?? '' ) );
            $sources['password']       = '' !== $settings['password'] ? 'database' : 'none';
        }

        $settings['enabled'] = ! empty( $settings['enabled'] );
        $settings['port']    = max( 1, min( 65535, absint( $settings['port'] ) ?: 3306 ) );
        $settings['prefix']  = preg_match( '/^[A-Za-z0-9_]+$/', (string) $settings['prefix'] ) ? (string) $settings['prefix'] : 'wp_';
        $settings['_sources']= $sources;

        return $settings;
    }
}

if ( ! function_exists( 'seo_environment_db_is_configured' ) ) {
    function seo_environment_db_is_configured( array $settings ) {
        return '' !== trim( (string) ( $settings['host'] ?? '' ) )
            && '' !== trim( (string) ( $settings['database'] ?? '' ) )
            && '' !== trim( (string) ( $settings['username'] ?? '' ) )
            && '' !== (string) ( $settings['password'] ?? '' );
    }
}

if ( ! function_exists( 'seo_environment_db_open' ) ) {
    /**
     * Abre una conexion mysqli independiente. El llamador debe cerrarla.
     * Solo se usa para consultas SELECT/SHOW en este modulo.
     *
     * @return mysqli|WP_Error
     */
    function seo_environment_db_open( $env ) {
        if ( ! extension_loaded( 'mysqli' ) || ! function_exists( 'mysqli_init' ) ) {
            return new WP_Error( 'seo_environment_db_no_mysqli', 'PHP no dispone de la extension mysqli.' );
        }

        $settings = seo_environment_db_settings( $env );
        if ( ! seo_environment_db_is_configured( $settings ) ) {
            return new WP_Error( 'seo_environment_db_missing', 'Faltan host, base de datos, usuario o contraseña.' );
        }

        $mysqli = mysqli_init();
        if ( ! $mysqli ) {
            return new WP_Error( 'seo_environment_db_init', 'No se pudo inicializar mysqli.' );
        }

        @mysqli_options( $mysqli, MYSQLI_OPT_CONNECT_TIMEOUT, 6 );

        $flags  = 0;
        $ssl_ca = trim( (string) ( $settings['ssl_ca'] ?? '' ) );
        if ( '' !== $ssl_ca ) {
            @mysqli_ssl_set( $mysqli, null, null, $ssl_ca, null, null );
            if ( defined( 'MYSQLI_CLIENT_SSL' ) ) {
                $flags |= MYSQLI_CLIENT_SSL;
            }
        }

        $driver = null;
        $previous_reporting = null;
        if ( class_exists( 'mysqli_driver' ) ) {
            $driver = new mysqli_driver();
            $previous_reporting = $driver->report_mode;
            $driver->report_mode = MYSQLI_REPORT_OFF;
        }

        $connected = @mysqli_real_connect(
            $mysqli,
            (string) $settings['host'],
            (string) $settings['username'],
            (string) $settings['password'],
            (string) $settings['database'],
            absint( $settings['port'] ),
            null,
            $flags
        );

        if ( $driver instanceof mysqli_driver && null !== $previous_reporting ) {
            $driver->report_mode = $previous_reporting;
        }

        if ( ! $connected ) {
            $message = trim( (string) mysqli_connect_error() );
            @mysqli_close( $mysqli );
            return new WP_Error(
                'seo_environment_db_connect',
                '' !== $message ? $message : 'No se pudo abrir la conexion MySQL.'
            );
        }

        @mysqli_set_charset( $mysqli, 'utf8mb4' );
        return $mysqli;
    }
}

if ( ! function_exists( 'seo_environment_db_test_connection' ) ) {
    function seo_environment_db_test_connection( $env ) {
        $settings = seo_environment_db_settings( $env );
        $mysqli   = seo_environment_db_open( $env );
        if ( is_wp_error( $mysqli ) ) {
            return $mysqli;
        }

        $server = '';
        $db     = '';
        $site   = '';

        $result = @mysqli_query( $mysqli, 'SELECT VERSION() AS server_version, DATABASE() AS database_name' );
        if ( $result ) {
            $row = mysqli_fetch_assoc( $result );
            $server = sanitize_text_field( (string) ( $row['server_version'] ?? '' ) );
            $db     = sanitize_text_field( (string) ( $row['database_name'] ?? '' ) );
            mysqli_free_result( $result );
        }

        $prefix = (string) ( $settings['prefix'] ?? 'wp_' );
        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
            @mysqli_close( $mysqli );
            return new WP_Error( 'seo_environment_db_prefix', 'El prefijo de tablas no es valido.' );
        }

        $options_table = '`' . $prefix . 'options`';
        $result = @mysqli_query(
            $mysqli,
            "SELECT option_value FROM {$options_table} WHERE option_name IN ('home','siteurl') ORDER BY FIELD(option_name,'home','siteurl') LIMIT 1"
        );
        if ( false === $result ) {
            $error = trim( (string) mysqli_error( $mysqli ) );
            @mysqli_close( $mysqli );
            return new WP_Error(
                'seo_environment_db_prefix_table',
                'La conexion funciona, pero no se pudo leer ' . $prefix . 'options. Revisa el prefijo y que el usuario tenga permiso SELECT.' . ( '' !== $error ? ' ' . $error : '' )
            );
        }
        $row = mysqli_fetch_assoc( $result );
        $site = esc_url_raw( (string) ( $row['option_value'] ?? '' ) );
        mysqli_free_result( $result );
        @mysqli_close( $mysqli );

        return [
            'ok'       => true,
            'server'   => $server,
            'database' => $db,
            'site_url' => $site,
        ];
    }
}

if ( ! function_exists( 'seo_environment_db_redirect_url' ) ) {
    function seo_environment_db_redirect_url( array $args = [] ) {
        $fallback = add_query_arg(
            [
                'page'       => 'seo-import-export',
                'seo_ie_tab' => 'conexiones-proveedores',
            ],
            admin_url( 'admin.php' )
        );
        $referer = wp_get_referer();
        $base    = $referer && false !== strpos( $referer, admin_url() ) ? $referer : $fallback;
        $base    = remove_query_arg( [ 'env_db_saved', 'env_db_test', 'env_db_status', 'env_db_message' ], $base );
        return add_query_arg( $args, $base );
    }
}

if ( ! function_exists( 'seo_environment_db_save_handler' ) ) {
    function seo_environment_db_save_handler() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para gestionar conexiones de entornos.', 'seo-system' ) );
        }

        check_admin_referer( 'seo_environment_db_save', 'seo_environment_db_nonce' );
        $env     = sanitize_key( wp_unslash( $_POST['env'] ?? '' ) );
        $allowed = seo_environment_db_allowed_envs();
        if ( ! isset( $allowed[ $env ] ) ) {
            wp_die( esc_html__( 'Entorno no valido.', 'seo-system' ) );
        }

        $all      = seo_environment_db_option();
        $current  = isset( $all[ $env ] ) && is_array( $all[ $env ] ) ? $all[ $env ] : [];
        $settings = wp_parse_args( $current, [ 'password_cipher' => '' ] );

        $settings['enabled']  = ! empty( $_POST['enabled'] ) ? 1 : 0;
        $settings['host']     = sanitize_text_field( wp_unslash( $_POST['host'] ?? '' ) );
        $settings['port']     = max( 1, min( 65535, absint( $_POST['port'] ?? 3306 ) ?: 3306 ) );
        $settings['database'] = sanitize_text_field( wp_unslash( $_POST['database'] ?? '' ) );
        $settings['username'] = sanitize_text_field( wp_unslash( $_POST['username'] ?? '' ) );
        $settings['prefix']   = sanitize_text_field( wp_unslash( $_POST['prefix'] ?? 'wp_' ) );
        $settings['ssl_ca']   = sanitize_text_field( wp_unslash( $_POST['ssl_ca'] ?? '' ) );

        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $settings['prefix'] ) ) {
            wp_safe_redirect( seo_environment_db_redirect_url( [ 'env_db_status' => 'error', 'env_db_message' => 'El prefijo solo puede contener letras, numeros y guion bajo.' ] ) );
            exit;
        }

        if ( ! empty( $_POST['clear_password'] ) ) {
            $settings['password_cipher'] = '';
        } else {
            $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
            if ( '' !== $password ) {
                $encrypted = seo_environment_db_encrypt( $password );
                if ( is_wp_error( $encrypted ) ) {
                    wp_safe_redirect( seo_environment_db_redirect_url( [ 'env_db_status' => 'error', 'env_db_message' => $encrypted->get_error_message() ] ) );
                    exit;
                }
                $settings['password_cipher'] = $encrypted;
            }
        }

        $all[ $env ] = $settings;
        update_option( 'seo_environment_db_connections', $all, false );

        wp_safe_redirect( seo_environment_db_redirect_url( [ 'env_db_saved' => $env ] ) );
        exit;
    }
}
add_action( 'admin_post_seo_environment_db_save', 'seo_environment_db_save_handler' );

if ( ! function_exists( 'seo_environment_db_test_handler' ) ) {
    function seo_environment_db_test_handler() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para probar conexiones de entornos.', 'seo-system' ) );
        }

        check_admin_referer( 'seo_environment_db_test', 'seo_environment_db_test_nonce' );
        $env     = sanitize_key( wp_unslash( $_POST['env'] ?? '' ) );
        $allowed = seo_environment_db_allowed_envs();
        if ( ! isset( $allowed[ $env ] ) ) {
            wp_die( esc_html__( 'Entorno no valido.', 'seo-system' ) );
        }

        $test = seo_environment_db_test_connection( $env );
        $all  = seo_environment_db_option();
        $row  = isset( $all[ $env ] ) && is_array( $all[ $env ] ) ? $all[ $env ] : [];
        $row['last_test_at'] = time();

        if ( is_wp_error( $test ) ) {
            $row['last_test_ok']    = 0;
            $row['last_test_error'] = sanitize_text_field( $test->get_error_message() );
            $status  = 'error';
            $message = $row['last_test_error'];
        } else {
            $row['last_test_ok']    = 1;
            $row['last_test_error'] = '';
            $row['last_site_url']   = esc_url_raw( (string) ( $test['site_url'] ?? '' ) );
            $row['last_server']     = sanitize_text_field( (string) ( $test['server'] ?? '' ) );
            $row['last_database']   = sanitize_text_field( (string) ( $test['database'] ?? '' ) );
            $status  = 'ok';
            $message = 'Conexion de solo lectura verificada.';
        }

        $all[ $env ] = $row;
        update_option( 'seo_environment_db_connections', $all, false );

        wp_safe_redirect(
            seo_environment_db_redirect_url(
                [
                    'env_db_test'    => $env,
                    'env_db_status'  => $status,
                    'env_db_message' => $message,
                ]
            )
        );
        exit;
    }
}
add_action( 'admin_post_seo_environment_db_test', 'seo_environment_db_test_handler' );

if ( ! function_exists( 'seo_environment_db_render_connections' ) ) {
    function seo_environment_db_render_connections() {
        $allowed = seo_environment_db_allowed_envs();

        echo '<section id="seo-environment-db-connections" style="border:2px solid #8c8f94;border-radius:7px;padding:18px;margin:22px 0;background:#f6f7f7;">';
        echo '<div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;">';
        echo '<div><p style="margin:0 0 5px;font-size:12px;text-transform:uppercase;color:#646970;font-weight:600;">Sincronización de entornos</p><h3 style="margin:0 0 6px;">Conexión a BBDD PRO y STAGING</h3><p style="margin:0;max-width:980px;">Estas conexiones quedan preparadas para comparar inventarios y, más adelante, sincronizar diferencias. El módulo solo realiza lecturas remotas. Usa usuarios MySQL exclusivos con permiso <code>SELECT</code> y restringidos por IP/VPN.</p></div>';
        echo '<span style="display:inline-block;padding:5px 9px;border-radius:12px;background:#fff8e5;">Solo lectura</span>';
        echo '</div>';

        if ( isset( $_GET['env_db_saved'] ) && isset( $allowed[ sanitize_key( $_GET['env_db_saved'] ) ] ) ) {
            echo '<div class="notice notice-success inline" style="margin:14px 0 0;"><p>Conexión de ' . esc_html( $allowed[ sanitize_key( $_GET['env_db_saved'] ) ] ) . ' guardada.</p></div>';
        }
        if ( isset( $_GET['env_db_status'], $_GET['env_db_message'] ) ) {
            $status  = 'ok' === sanitize_key( $_GET['env_db_status'] ) ? 'success' : 'error';
            $message = sanitize_text_field( (string) wp_unslash( $_GET['env_db_message'] ) );
            echo '<div class="notice notice-' . esc_attr( $status ) . ' inline" style="margin:14px 0 0;"><p>' . esc_html( $message ) . '</p></div>';
        }

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(390px,1fr));gap:16px;margin-top:18px;">';

        foreach ( $allowed as $env => $label ) {
            $settings   = seo_environment_db_settings( $env );
            $configured = seo_environment_db_is_configured( $settings );
            $tested_ok  = ! empty( $settings['last_test_ok'] );
            $enabled    = ! empty( $settings['enabled'] );
            $badge      = $tested_ok ? 'Conectado' : ( $configured ? 'Configurado, pendiente de prueba' : 'Pendiente de configurar' );
            $badge_bg   = $tested_ok ? '#edfaef' : '#fff8e5';
            $sources    = (array) ( $settings['_sources'] ?? [] );

            echo '<div style="border:1px solid #c3c4c7;border-radius:6px;padding:16px;background:#fff;">';
            echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">';
            echo '<div><h4 style="margin:0 0 5px;font-size:16px;">Conexión a ' . esc_html( $label ) . '</h4><p class="description" style="margin:0;">Base de datos del entorno ' . esc_html( strtolower( $label ) ) . '.</p></div>';
            echo '<span style="display:inline-block;padding:4px 8px;border-radius:12px;background:' . esc_attr( $badge_bg ) . ';white-space:nowrap;">' . esc_html( $badge ) . '</span>';
            echo '</div>';

            if ( $tested_ok ) {
                $last = absint( $settings['last_test_at'] ?? 0 );
                echo '<div style="margin-top:12px;padding:10px;background:#f6f7f7;border-radius:4px;font-size:12px;">';
                if ( ! empty( $settings['last_site_url'] ) ) {
                    echo '<strong>Sitio detectado:</strong> <code>' . esc_html( (string) $settings['last_site_url'] ) . '</code><br>';
                }
                echo '<strong>BBDD:</strong> <code>' . esc_html( (string) ( $settings['last_database'] ?? $settings['database'] ) ) . '</code>';
                if ( ! empty( $settings['last_server'] ) ) {
                    echo ' · <strong>MySQL:</strong> ' . esc_html( (string) $settings['last_server'] );
                }
                if ( $last ) {
                    echo '<br><strong>Última prueba:</strong> ' . esc_html( wp_date( 'Y-m-d H:i:s', $last ) );
                }
                echo '</div>';
            } elseif ( ! empty( $settings['last_test_error'] ) ) {
                echo '<p style="margin:12px 0 0;color:#b32d2e;"><strong>Última prueba:</strong> ' . esc_html( (string) $settings['last_test_error'] ) . '</p>';
            }

            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:grid;grid-template-columns:1fr 110px;gap:10px;margin-top:14px;">';
            echo '<input type="hidden" name="action" value="seo_environment_db_save">';
            echo '<input type="hidden" name="env" value="' . esc_attr( $env ) . '">';
            wp_nonce_field( 'seo_environment_db_save', 'seo_environment_db_nonce' );
            echo '<label style="grid-column:1/-1;"><input type="checkbox" name="enabled" value="1" ' . checked( $enabled, true, false ) . '> <strong>Activar esta conexión</strong></label>';

            $fields = [
                'host'     => [ 'Host', 'db.example.com', 'text' ],
                'port'     => [ 'Puerto', '3306', 'number' ],
                'database' => [ 'Base de datos', 'wordpress', 'text' ],
                'username' => [ 'Usuario solo lectura', 'seo_sync_reader', 'text' ],
                'prefix'   => [ 'Prefijo tablas', 'wp_', 'text' ],
                'ssl_ca'   => [ 'CA TLS (opcional)', '/ruta/ca.pem', 'text' ],
            ];

            foreach ( $fields as $field => $def ) {
                $is_port = 'port' === $field;
                $style   = $is_port ? '' : 'grid-column:1/-1;';
                $readonly = 'constant' === ( $sources[ $field ] ?? '' );
                echo '<label style="' . esc_attr( $style ) . '"><strong>' . esc_html( $def[0] ) . '</strong><br>';
                echo '<input type="' . esc_attr( $def[2] ) . '" name="' . esc_attr( $field ) . '" value="' . esc_attr( (string) ( $settings[ $field ] ?? '' ) ) . '" placeholder="' . esc_attr( $def[1] ) . '" ' . ( $is_port ? 'min="1" max="65535" ' : '' ) . ( $readonly ? 'readonly ' : '' ) . 'style="width:100%;">';
                if ( $readonly ) {
                    echo '<br><span class="description">Definido en <code>' . esc_html( seo_environment_db_constant_name( $env, $field ) ) . '</code>.</span>';
                }
                echo '</label>';
            }

            $password_source = (string) ( $sources['password'] ?? 'none' );
            if ( 'constant' === $password_source ) {
                echo '<div style="grid-column:1/-1;"><strong>Contraseña</strong><br><span style="display:inline-block;margin-top:5px;padding:4px 8px;border-radius:12px;background:#edfaef;">Definida en wp-config.php</span><br><span class="description"><code>' . esc_html( seo_environment_db_constant_name( $env, 'password' ) ) . '</code></span></div>';
            } else {
                echo '<label style="grid-column:1/-1;"><strong>Contraseña</strong><br><input type="password" name="password" value="" placeholder="' . esc_attr( 'database' === $password_source ? 'Guardada; deja vacío para conservarla' : 'Contraseña del usuario de solo lectura' ) . '" autocomplete="new-password" style="width:100%;"></label>';
                if ( 'database' === $password_source ) {
                    echo '<label style="grid-column:1/-1;"><input type="checkbox" name="clear_password" value="1"> Eliminar la contraseña guardada.</label>';
                }
            }

            echo '<div style="grid-column:1/-1;"><button type="submit" class="button button-primary">Guardar conexión ' . esc_html( $label ) . '</button></div>';
            echo '</form>';

            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px;">';
            echo '<input type="hidden" name="action" value="seo_environment_db_test">';
            echo '<input type="hidden" name="env" value="' . esc_attr( $env ) . '">';
            wp_nonce_field( 'seo_environment_db_test', 'seo_environment_db_test_nonce' );
            echo '<button type="submit" class="button" ' . disabled( ! $configured, true, false ) . '>Probar conexión ' . esc_html( $label ) . '</button>';
            echo '</form>';
            echo '</div>';
        }

        echo '</div>';
        echo '<details style="margin-top:14px;"><summary><strong>Seguridad recomendada</strong></summary><div style="padding:8px 0 0 18px;"><p>Para estas dos credenciales crea usuarios MySQL distintos con <code>SELECT</code> únicamente. Restringe el host origen en MySQL/firewall y evita exponer el puerto 3306 a Internet. Si la conexión cruza redes, usa VPN/túnel privado o TLS con CA.</p><p>También puedes definir cada campo en <code>wp-config.php</code> con constantes <code>SEO_SYSTEM_PRO_DB_*</code> y <code>SEO_SYSTEM_STAGING_DB_*</code>; las constantes prevalecen sobre los valores guardados.</p></div></details>';
        echo '</section>';
    }
}
