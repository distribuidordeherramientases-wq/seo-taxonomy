<?php
/**
 * SEO System - Conexiones con proveedores API.
 *
 * Gestiona credenciales y pruebas de conexion de proveedores remotos.
 * No importa productos ni crea tablas de catalogo virtual.
 *
 * @package SEOSystem
 * @subpackage ImportExport
 * @version 0.5.0
 */

defined( 'ABSPATH' ) || exit;


// Ejecutor Python remoto para catalogos largos. Se carga aqui para que la
// conexion este disponible aunque importer.php sea quien incluya este modulo.
$seo_google_python_runner_file = __DIR__ . '/google-python-runner.php';
if ( is_readable( $seo_google_python_runner_file ) ) {
    require_once $seo_google_python_runner_file;
}
unset( $seo_google_python_runner_file );

// Runner Python mediante GitHub Actions. Independiente de Google Cloud.
// Conservamos diagnostico de carga para poder detectar permisos/ruta desde admin.
$seo_github_python_runner_file = __DIR__ . '/github-python-runner.php';
$seo_github_python_runner_file_exists = file_exists( $seo_github_python_runner_file );
$seo_github_python_runner_file_readable = is_readable( $seo_github_python_runner_file );
if ( $seo_github_python_runner_file_exists && $seo_github_python_runner_file_readable ) {
    require_once $seo_github_python_runner_file;
}
$GLOBALS['seo_github_python_runner_loader'] = [
    'path'     => $seo_github_python_runner_file,
    'exists'   => $seo_github_python_runner_file_exists,
    'readable' => $seo_github_python_runner_file_readable,
    'loaded'   => function_exists( 'seo_github_python_runner_settings' ),
];
unset( $seo_github_python_runner_file, $seo_github_python_runner_file_exists, $seo_github_python_runner_file_readable );

// Integracion de infraestructura Cloudflare. Se centraliza en esta pantalla,
// pero sus datos podran ser consumidos despues por Estado del servidor > Seguridad.
$seo_cloudflare_file = __DIR__ . '/cloudflare.php';
if ( is_readable( $seo_cloudflare_file ) ) {
    require_once $seo_cloudflare_file;
}
unset( $seo_cloudflare_file );

if ( ! function_exists( 'seo_proveedores_api_connections' ) ) {
    function seo_proveedores_api_connections() {
        $connections = [];

        if ( function_exists( 'seo_supplier_recipe_amazon_settings' ) ) {
            $connections['amazon'] = [
                'id'       => 'amazon',
                'label'    => 'Amazon Creators API',
                'provider' => 'Amazon',
                'market'   => 'amazon.es',
            ];
        }

        if ( function_exists( 'seo_cloudflare_settings' ) ) {
            $connections['cloudflare'] = [
                'id'       => 'cloudflare',
                'label'    => 'Cloudflare',
                'provider' => 'Cloudflare',
                'market'   => '',
                'type'     => 'infrastructure',
            ];
        }

        if ( function_exists( 'seo_google_search_settings' ) ) {
            $connections['google_search'] = [
                'id'       => 'google_search',
                'label'    => 'Google Search / Analytics',
                'provider' => 'Google',
                'market'   => '',
            ];
        }


        if ( function_exists( 'seo_google_python_runner_settings' ) ) {
            $connections['google_python'] = [
                'id'       => 'google_python',
                'label'    => 'Google Python Runner',
                'provider' => 'Google Cloud',
                'market'   => '',
            ];
        }

        if ( function_exists( 'seo_github_python_runner_settings' ) ) {
            $connections['github_python'] = [
                'id'       => 'github_python',
                'label'    => 'GitHub Actions Python Runner',
                'provider' => 'GitHub',
                'market'   => '',
            ];
        }

        return apply_filters( 'seo_proveedores_api_connections', $connections );
    }
}

if ( ! function_exists( 'seo_proveedores_render_conexiones' ) ) {
    function seo_proveedores_render_conexiones() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para gestionar conexiones de proveedores.', 'seo-system' ) );
        }

        $connections = seo_proveedores_api_connections();
        $notice      = '';
        $error       = '';

        if ( isset( $_POST['seo_amazon_test_connection'] ) ) {
            check_admin_referer( 'seo_amazon_connection_test', 'seo_amazon_connection_nonce' );

            if ( ! function_exists( 'seo_supplier_recipe_amazon_get_access_token' ) ) {
                $error = 'La receta Amazon no esta cargada. Comprueba suppliers/recipes/import_amazon.php.';
            } else {
                $token = seo_supplier_recipe_amazon_get_access_token( true );
                if ( is_wp_error( $token ) ) {
                    $error = $token->get_error_message();
                } else {
                    $notice = 'Conexion correcta: Amazon ha emitido un access token OAuth valido.';
                }
            }
        }

        if ( isset( $_POST['seo_cloudflare_test_connection'] ) ) {
            check_admin_referer( 'seo_cloudflare_connection_test', 'seo_cloudflare_connection_nonce' );

            if ( ! function_exists( 'seo_cloudflare_test_connection' ) ) {
                $error = 'El servicio Cloudflare no esta cargado.';
            } else {
                $test = seo_cloudflare_test_connection();
                $messages = array_map( 'sanitize_text_field', (array) ( $test['messages'] ?? [] ) );
                if ( empty( $test['ok'] ) ) {
                    $error = implode( ' ', $messages );
                } else {
                    $notice = implode( ' ', $messages );
                }
            }
        }

        if ( isset( $_POST['seo_google_search_test_connection'] ) ) {
            check_admin_referer( 'seo_google_search_connection_test', 'seo_google_search_connection_nonce' );

            if ( ! function_exists( 'seo_google_search_test_connection' ) ) {
                $error = 'El servicio Google Search / Analytics no esta cargado.';
            } else {
                $test = seo_google_search_test_connection();
                $messages = array_map( 'sanitize_text_field', (array) ( $test['messages'] ?? [] ) );
                if ( empty( $test['ok'] ) ) {
                    $error = implode( ' ', $messages );
                } else {
                    $notice = implode( ' ', $messages );
                }
            }
        }


        if ( isset( $_POST['seo_google_python_test_connection'] ) ) {
            check_admin_referer( 'seo_google_python_connection_test', 'seo_google_python_connection_nonce' );

            if ( ! function_exists( 'seo_google_python_runner_test_connection' ) ) {
                $error = 'El servicio Google Python Runner no esta cargado.';
            } else {
                $test = seo_google_python_runner_test_connection();
                $messages = array_map( 'sanitize_text_field', (array) ( $test['messages'] ?? [] ) );
                if ( empty( $test['ok'] ) ) {
                    $error = implode( ' ', $messages );
                } else {
                    $notice = implode( ' ', $messages );
                }
            }
        }

        if ( isset( $_POST['seo_github_python_test_connection'] ) ) {
            check_admin_referer( 'seo_github_python_connection_test', 'seo_github_python_connection_nonce' );

            if ( ! function_exists( 'seo_github_python_runner_test_connection' ) ) {
                $error = 'El servicio GitHub Actions Python Runner no esta cargado.';
            } else {
                $test = seo_github_python_runner_test_connection();
                $messages = array_map( 'sanitize_text_field', (array) ( $test['messages'] ?? [] ) );
                if ( empty( $test['ok'] ) ) {
                    $error = implode( ' ', $messages );
                } else {
                    $notice = implode( ' ', $messages );
                }
            }
        }

        if ( function_exists( 'seo_google_python_runner_get_notice' ) ) {
            $runner_notice = seo_google_python_runner_get_notice();
            if ( ! empty( $runner_notice['message'] ) ) {
                if ( 'error' === ( $runner_notice['type'] ?? '' ) ) {
                    $error = (string) $runner_notice['message'];
                } else {
                    $notice = (string) $runner_notice['message'];
                }
            }
        }

        if ( function_exists( 'seo_github_python_runner_get_notice' ) ) {
            $runner_notice = seo_github_python_runner_get_notice();
            if ( ! empty( $runner_notice['message'] ) ) {
                if ( 'error' === ( $runner_notice['type'] ?? '' ) ) {
                    $error = (string) $runner_notice['message'];
                } else {
                    $notice = (string) $runner_notice['message'];
                }
            }
        }

        echo '<div class="card" style="max-width:none;padding:20px;margin-top:20px;">';
        echo '<h2 style="margin-top:0;">Conexiones e integraciones</h2>';
        echo '<p>Configura aqui APIs de proveedores, ejecutores externos y servicios de infraestructura. Cada credencial se define una sola vez y los modulos autorizados reutilizan la conexion.</p>';
        echo '<p><code>Modulo conexiones v0.5.0</code></p>';

        if ( ! function_exists( 'seo_github_python_runner_settings' ) ) {
            $gh_loader = isset( $GLOBALS['seo_github_python_runner_loader'] ) && is_array( $GLOBALS['seo_github_python_runner_loader'] )
                ? $GLOBALS['seo_github_python_runner_loader']
                : [];
            $gh_exists = ! empty( $gh_loader['exists'] ) ? 'SI' : 'NO';
            $gh_readable = ! empty( $gh_loader['readable'] ) ? 'SI' : 'NO';
            $gh_loaded = ! empty( $gh_loader['loaded'] ) ? 'SI' : 'NO';
            $gh_path = isset( $gh_loader['path'] ) ? (string) $gh_loader['path'] : __DIR__ . '/github-python-runner.php';
            echo '<div class="notice notice-warning inline"><p><strong>GitHub Runner no cargado.</strong> Archivo existe: ' . esc_html( $gh_exists ) . ' · Legible por PHP: ' . esc_html( $gh_readable ) . ' · Funcion cargada: ' . esc_html( $gh_loaded ) . '<br><code>' . esc_html( $gh_path ) . '</code><br>Comprueba que <code>github-python-runner.php</code> tenga permisos 0644.</p></div>';
        }

        if ( isset( $_GET['cloudflare_saved'] ) ) {
            echo '<div class="notice notice-success inline"><p>Configuracion de Cloudflare guardada. Usa "Probar conexion" para verificar el token y la zona.</p></div>';
        }
        if ( isset( $_GET['cloudflare_error'] ) ) {
            $cloudflare_error = sanitize_key( $_GET['cloudflare_error'] );
            $cloudflare_message = 'No se pudo guardar la configuracion de Cloudflare.';
            if ( 'zone' === $cloudflare_error ) {
                $cloudflare_message = 'Indica un dominio/zona valido de Cloudflare.';
            } elseif ( 'token' === $cloudflare_error ) {
                $cloudflare_message = 'Activa Cloudflare solo cuando exista un API Token guardado o definido en wp-config.php.';
            } elseif ( 'crypto' === $cloudflare_error ) {
                $cloudflare_message = 'No se pudo proteger el token en la base de datos. Puedes definir SEO_SYSTEM_CLOUDFLARE_API_TOKEN en wp-config.php.';
            }
            echo '<div class="notice notice-error inline"><p>' . esc_html( $cloudflare_message ) . '</p></div>';
        }

        if ( isset( $_GET['amazon_saved'] ) ) {
            echo '<div class="notice notice-success inline"><p>Configuracion de Amazon guardada.</p></div>';
        }
        if ( isset( $_GET['google_search_saved'] ) ) {
            echo '<div class="notice notice-success inline"><p>Configuracion de Google Search / Analytics guardada.</p></div>';
        }

        if ( isset( $_GET['google_python_saved'] ) ) {
            echo '<div class="notice notice-success inline"><p>Configuracion de Google Python Runner guardada.</p></div>';
        }
        if ( isset( $_GET['google_python_error'] ) ) {
            $python_error = sanitize_key( $_GET['google_python_error'] );
            $python_message = 'No se pudo guardar Google Python Runner.';
            if ( 'credentials' === $python_error ) {
                $python_message = 'El JSON de la cuenta de servicio de Google no es valido.';
            } elseif ( 'fields' === $python_error ) {
                $python_message = 'Project ID, region y nombre del Cloud Run Job son obligatorios.';
            }
            echo '<div class="notice notice-error inline"><p>' . esc_html( $python_message ) . '</p></div>';
        }
        if ( isset( $_GET['github_python_saved'] ) ) {
            echo '<div class="notice notice-success inline"><p>Configuracion de GitHub Actions Python Runner guardada.</p></div>';
        }
        if ( isset( $_GET['github_python_error'] ) ) {
            $github_error = sanitize_key( $_GET['github_python_error'] );
            $github_message = 'No se pudo guardar GitHub Actions Python Runner.';
            if ( 'fields' === $github_error ) {
                $github_message = 'Usuario/organizacion, repositorio, workflow y rama son obligatorios.';
            }
            echo '<div class="notice notice-error inline"><p>' . esc_html( $github_message ) . '</p></div>';
        }
        if ( isset( $_GET['google_search_error'] ) ) {
            $google_error = sanitize_key( $_GET['google_search_error'] );
            $google_error_message = 'No se pudo guardar la configuracion de Google.';
            if ( 'measurement_id' === $google_error ) {
                $google_error_message = 'El Measurement ID debe tener el formato G-XXXXXXXXXX.';
            } elseif ( 'credentials' === $google_error ) {
                $google_error_message = 'El JSON de la cuenta de servicio no es valido.';
            }
            echo '<div class="notice notice-error inline"><p>' . esc_html( $google_error_message ) . '</p></div>';
        }
        if ( $notice ) {
            echo '<div class="notice notice-success inline"><p>' . esc_html( $notice ) . '</p></div>';
        }
        if ( $error ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
        }

        if ( empty( $connections ) ) {
            echo '<div class="notice notice-warning inline"><p>No hay proveedores API registrados. Para Amazon debe existir <code>suppliers/recipes/import_amazon.php</code>.</p></div>';
            echo '</div>';
            return;
        }

        if ( isset( $connections['cloudflare'] ) && function_exists( 'seo_cloudflare_settings' ) ) {
            $cf = seo_cloudflare_settings();
            $cf_state = function_exists( 'seo_cloudflare_connection_state' ) ? seo_cloudflare_connection_state() : [];
            $cf_token_source = (string) ( $cf_state['token_source'] ?? 'none' );
            $cf_has_token = 'none' !== $cf_token_source;
            $cf_configured = $cf_has_token && '' !== trim( (string) ( $cf['zone_name'] ?? '' ) );
            $cf_enabled = ! empty( $cf['enabled'] );
            $cf_verified = ! empty( $cf['last_verified_at'] ) && 'active' === (string) ( $cf['last_token_status'] ?? '' ) && '' !== trim( (string) ( $cf['zone_id'] ?? '' ) );
            $cf_badge = $cf_verified ? 'Conectado' : ( $cf_configured ? 'Configurado, pendiente de prueba' : 'Pendiente de configurar' );
            $cf_badge_bg = $cf_verified ? '#edfaef' : '#fff8e5';

            echo '<div style="border:1px solid #8c8f94;border-radius:6px;padding:18px;margin-top:18px;background:#fff;">';
            echo '<div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;">';
            echo '<div><p style="margin:0 0 5px;"><strong style="font-size:12px;text-transform:uppercase;color:#646970;">Servicio e infraestructura</strong></p><h3 style="margin:0 0 6px;">Cloudflare</h3><p style="margin:0;max-width:850px;">Conexion de solo lectura para reutilizar la configuracion de Cloudflare en el diagnostico de seguridad. Esta fase verifica el API Token y localiza la zona; no modifica DNS, WAF, SSL ni reglas.</p></div>';
            echo '<span style="display:inline-block;padding:5px 9px;border-radius:12px;background:' . esc_attr( $cf_badge_bg ) . ';">' . esc_html( $cf_badge ) . '</span>';
            echo '</div>';

            if ( $cf_verified ) {
                $cf_last = absint( $cf['last_verified_at'] ?? 0 );
                $cf_last_text = $cf_last ? wp_date( 'Y-m-d H:i:s', $cf_last ) : 'nunca';
                echo '<p style="margin:14px 0 0;"><strong>Zona:</strong> <code>' . esc_html( (string) ( $cf['zone_name'] ?? '' ) ) . '</code> · <strong>Estado:</strong> ' . esc_html( (string) ( $cf['zone_status'] ?? 'desconocido' ) ) . ' · <strong>Cuenta:</strong> ' . esc_html( (string) ( $cf['account_name'] ?? 'no indicada' ) ) . ' · <strong>Ultima verificacion:</strong> ' . esc_html( $cf_last_text ) . '</p>';
            }

            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin-top:18px;">';
            echo '<input type="hidden" name="action" value="seo_cloudflare_save">';
            wp_nonce_field( 'seo_cloudflare_save', 'seo_cloudflare_nonce' );
            echo '<label style="grid-column:1/-1;"><input type="checkbox" name="enabled" value="1" ' . checked( $cf_enabled, true, false ) . '> <strong>Activar Cloudflare para los diagnosticos de SEO System</strong><br><span class="description">Si se desactiva, la credencial puede conservarse pero Seguridad no debera usar la API.</span></label>';
            echo '<label><strong>Zona / dominio</strong><br><input type="text" name="zone_name" value="' . esc_attr( (string) ( $cf['zone_name'] ?? '' ) ) . '" placeholder="ejemplo.com" class="regular-text" autocomplete="off" style="width:100%;"><br><span class="description">Usa la zona de Cloudflare, normalmente el dominio raiz sin www.</span></label>';

            if ( 'constant' === $cf_token_source ) {
                echo '<div><strong>API Token</strong><br><span style="display:inline-block;margin-top:6px;padding:5px 9px;border-radius:12px;background:#edfaef;">Definido externamente</span><br><span class="description">SEO_SYSTEM_CLOUDFLARE_API_TOKEN esta definido en wp-config.php. El token no se guarda en la base de datos.</span></div>';
            } else {
                echo '<label><strong>API Token</strong><br><input type="password" name="api_token" value="" placeholder="' . esc_attr( $cf_has_token ? 'Token guardado; deja vacio para conservarlo' : 'Pega aqui el API Token de Cloudflare' ) . '" class="regular-text" autocomplete="new-password" style="width:100%;"><br><span class="description">Se cifra antes de guardarse; nunca se vuelve a mostrar en pantalla.</span></label>';
                if ( $cf_has_token ) {
                    echo '<label style="grid-column:1/-1;"><input type="checkbox" name="clear_token" value="1"> Eliminar el token guardado al guardar esta configuracion.</label>';
                }
            }

            echo '<div style="grid-column:1/-1;"><button class="button button-primary" type="submit">Guardar conexion Cloudflare</button></div>';
            echo '</form>';

            echo '<form method="post" style="margin-top:12px;">';
            wp_nonce_field( 'seo_cloudflare_connection_test', 'seo_cloudflare_connection_nonce' );
            echo '<button class="button" type="submit" name="seo_cloudflare_test_connection" value="1" ' . disabled( ! $cf_configured, true, false ) . '>Probar conexion Cloudflare</button>';
            echo '</form>';

            echo '<details style="margin-top:14px;"><summary><strong>Permisos recomendados del API Token</strong></summary>';
            echo '<div style="padding:10px 0 0 18px;"><p style="margin-top:0;">Crea un <strong>API Token</strong>, no uses la Global API Key, y limita los recursos a esta zona. Para dejar preparada la futura auditoria de Seguridad: <code>Zone Read</code>, <code>Zone Settings Read</code>, <code>DNS Read</code>, <code>SSL and Certificates Read</code>, <code>Zone WAF Read</code> y <code>Analytics Read</code>. No concedas permisos Edit/Write.</p>';
            echo '<p class="description">La prueba actual solo exige poder validar el token y leer la zona. Los permisos adicionales se comprobaran cuando ampliemos la pestaña Seguridad.</p></div></details>';
            echo '</div>';
        }

        if ( isset( $connections['google_search'] ) && function_exists( 'seo_google_search_settings' ) ) {
            $g = seo_google_search_settings();
            $measurement_id = function_exists( 'seo_google_search_measurement_id' ) ? seo_google_search_measurement_id() : '';
            $service_account = function_exists( 'seo_google_search_service_account' ) ? seo_google_search_service_account() : null;
            $service_email = ( ! is_wp_error( $service_account ) && is_array( $service_account ) ) ? sanitize_email( $service_account['client_email'] ?? '' ) : '';
            $configured = '' !== $measurement_id || '' !== trim( (string) ( $g['analytics_property_id'] ?? '' ) ) || '' !== trim( (string) ( $g['search_console_site_url'] ?? '' ) ) || '' !== trim( (string) ( $g['service_account_json'] ?? '' ) );

            echo '<div style="border:1px solid #dcdcde;border-radius:6px;padding:18px;margin-top:18px;background:#fff;">';
            echo '<div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;">';
            echo '<div><h3 style="margin:0 0 6px;">Google Search / Analytics</h3><p style="margin:0;">GA4 para medicion publica y APIs de Search Console / Analytics para informes internos.</p></div>';
            echo '<span style="display:inline-block;padding:5px 9px;border-radius:12px;background:' . ( $configured ? '#edfaef' : '#fff8e5' ) . ';">' . ( $configured ? 'Configurada' : 'Pendiente de configurar' ) . '</span>';
            echo '</div>';

            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin-top:18px;">';
            echo '<input type="hidden" name="action" value="seo_google_search_save">';
            wp_nonce_field( 'seo_google_search_save', 'seo_google_search_nonce' );
            echo '<label style="grid-column:1/-1;"><input type="checkbox" name="tracking_enabled" value="1" ' . checked( ! empty( $g['tracking_enabled'] ), true, false ) . '> <strong>Activar medicion GA4 en el frontend</strong><br><span class="description">No se mide a administradores conectados. El ID se carga dinamicamente desde esta configuracion.</span></label>';
            echo '<label><strong>GA4 Measurement ID</strong><br><input type="text" name="measurement_id" value="' . esc_attr( $g['measurement_id'] ?? '' ) . '" placeholder="G-XXXXXXXXXX" class="regular-text" autocomplete="off" style="width:100%;"></label>';
            echo '<label><strong>Analytics Property ID</strong><br><input type="text" name="analytics_property_id" value="' . esc_attr( $g['analytics_property_id'] ?? '' ) . '" placeholder="123456789" class="regular-text" inputmode="numeric" style="width:100%;"></label>';
            echo '<label style="grid-column:1/-1;"><strong>Propiedad de Search Console</strong><br><input type="url" name="search_console_site_url" value="' . esc_attr( $g['search_console_site_url'] ?? '' ) . '" placeholder="https://www.ejemplo.com/" class="regular-text" style="width:100%;"><br><span class="description">Usa exactamente la propiedad dada de alta en Search Console. Tambien admite propiedades URL-prefix.</span></label>';
            echo '<label style="grid-column:1/-1;"><strong>JSON de cuenta de servicio Google</strong><br><textarea name="service_account_json" rows="8" class="large-text code" autocomplete="off" placeholder="' . esc_attr( ! empty( $g['service_account_json'] ) ? 'Credencial guardada; deja vacio para conservarla' : 'Pega aqui el JSON descargado de Google Cloud' ) . '"></textarea>';
            if ( '' !== $service_email ) {
                echo '<br><span class="description">Cuenta configurada: <code>' . esc_html( $service_email ) . '</code>. Debe tener acceso a la propiedad de Analytics y a Search Console.</span>';
            } else {
                echo '<br><span class="description">La credencial privada se usa solo en servidor y nunca se imprime en el frontend.</span>';
            }
            echo '</label>';
            echo '<div style="grid-column:1/-1;"><button class="button button-primary" type="submit">Guardar conexion Google</button></div>';
            echo '</form>';

            echo '<form method="post" style="margin-top:12px;">';
            wp_nonce_field( 'seo_google_search_connection_test', 'seo_google_search_connection_nonce' );
            echo '<button class="button" type="submit" name="seo_google_search_test_connection" value="1">Probar Search Console / Analytics</button>';
            echo '</form>';
            echo '<p class="description" style="margin-top:12px;">Los informes de landings podran reutilizar <code>seo_google_search_console_query()</code> y <code>seo_google_analytics_run_report()</code> sin conocer ni exponer las credenciales.</p>';
            echo '</div>';
        }


        if ( isset( $connections['google_python'] ) && function_exists( 'seo_google_python_runner_settings' ) ) {
            $p = seo_google_python_runner_settings();
            $account = function_exists( 'seo_google_python_runner_service_account' ) ? seo_google_python_runner_service_account() : null;
            $service_email = ( ! is_wp_error( $account ) && is_array( $account ) ) ? sanitize_email( $account['client_email'] ?? '' ) : '';
            $configured = ! empty( $p['enabled'] ) && '' !== trim( (string) ( $p['project_id'] ?? '' ) ) && '' !== trim( (string) ( $p['region'] ?? '' ) ) && '' !== trim( (string) ( $p['job_name'] ?? '' ) ) && '' !== trim( (string) ( $p['service_account_json'] ?? '' ) );

            echo '<div style="border:1px solid #dcdcde;border-radius:6px;padding:18px;margin-top:18px;background:#fff;">';
            echo '<div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;">';
            echo '<div><h3 style="margin:0 0 6px;">Google Python Runner <small style="font-weight:400;">(Cloud Run)</small></h3><p style="margin:0;">Ejecuta los scrapers Python en Google Cloud Run y devuelve automaticamente el CSV estandar al Catalogo de proveedores. Requiere facturacion habilitada en Google Cloud.</p></div>';
            echo '<span style="display:inline-block;padding:5px 9px;border-radius:12px;background:' . ( $configured ? '#edfaef' : '#fff8e5' ) . ';">' . ( $configured ? 'Configurado' : 'Pendiente de configurar' ) . '</span>';
            echo '</div>';

            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;margin-top:18px;">';
            echo '<input type="hidden" name="action" value="seo_google_python_runner_save">';
            wp_nonce_field( 'seo_google_python_runner_save', 'seo_google_python_runner_nonce' );
            echo '<label style="grid-column:1/-1;"><input type="checkbox" name="enabled" value="1" ' . checked( ! empty( $p['enabled'] ), true, false ) . '> <strong>Activar Google Python Runner</strong></label>';
            echo '<label><strong>Google Cloud Project ID</strong><br><input type="text" name="project_id" value="' . esc_attr( $p['project_id'] ?? '' ) . '" placeholder="mi-proyecto-google" class="regular-text" autocomplete="off" style="width:100%;"></label>';
            echo '<label><strong>Region</strong><br><input type="text" name="region" value="' . esc_attr( $p['region'] ?? 'europe-west1' ) . '" placeholder="europe-west1" class="regular-text" autocomplete="off" style="width:100%;"></label>';
            echo '<label><strong>Cloud Run Job</strong><br><input type="text" name="job_name" value="' . esc_attr( $p['job_name'] ?? 'seo-supplier-scraper' ) . '" placeholder="seo-supplier-scraper" class="regular-text" autocomplete="off" style="width:100%;"></label>';
            echo '<label style="grid-column:1/-1;"><strong>JSON de cuenta de servicio Google Cloud</strong><br><textarea name="service_account_json" rows="8" class="large-text code" autocomplete="off" placeholder="' . esc_attr( ! empty( $p['service_account_json'] ) ? 'Credencial guardada; deja vacio para conservarla' : 'Pega aqui el JSON de la cuenta de servicio con roles Cloud Run Jobs Executor With Overrides y Cloud Run Viewer' ) . '"></textarea>';
            if ( '' !== $service_email ) {
                echo '<br><span class="description">Cuenta configurada: <code>' . esc_html( $service_email ) . '</code>. El JSON privado no vuelve a mostrarse.</span>';
            } else {
                echo '<br><span class="description">Usa una cuenta de servicio dedicada al runner. No introduzcas tu usuario ni contrasena de Google.</span>';
            }
            echo '</label>';
            echo '<div style="grid-column:1/-1;"><button class="button button-primary" type="submit">Guardar conexion Python</button></div>';
            echo '</form>';

            echo '<form method="post" style="margin-top:12px;display:inline-block;margin-right:8px;">';
            wp_nonce_field( 'seo_google_python_connection_test', 'seo_google_python_connection_nonce' );
            echo '<button class="button" type="submit" name="seo_google_python_test_connection" value="1">Probar Google Cloud Run</button>';
            echo '</form>';

            if ( $configured && function_exists( 'seo_proveedores_recetas_importacion' ) ) {
                $recipes = seo_proveedores_recetas_importacion();
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="border-top:1px solid #dcdcde;margin-top:18px;padding-top:18px;">';
                echo '<input type="hidden" name="action" value="seo_google_python_runner_launch">';
                wp_nonce_field( 'seo_google_python_runner_launch', 'seo_google_python_runner_launch_nonce' );
                echo '<strong>Ejecutar scraper Python</strong><br><span class="description">El Cloud Run Job decide que script ejecutar segun la receta seleccionada.</span><br><br>';
                echo '<select name="recipe_id" required><option value="">Selecciona proveedor / receta</option>';
                foreach ( (array) $recipes as $recipe_id => $recipe ) {
                    echo '<option value="' . esc_attr( $recipe_id ) . '">' . esc_html( $recipe['label'] ?? $recipe_id ) . '</option>';
                }
                echo '</select> ';
                echo '<label style="margin-left:8px;"><input type="checkbox" name="catalog_complete" value="1"> Catalogo completo</label> ';
                echo '<button class="button button-primary" type="submit">Iniciar Python</button>';
                echo '<p class="description">Por seguridad, Supplier V2 mantiene las bajas automaticas desactivadas. Catalogo completo solo permite detectar bajas pendientes.</p>';
                echo '</form>';
            }

            echo '<p class="description" style="margin-top:12px;">Callback privado: <code>' . esc_html( rest_url( 'seo-taxonomy/v1/supplier-runner/callback' ) ) . '</code>. WordPress genera un token distinto para cada ejecucion y lo entrega al Job; no hay que copiarlo manualmente.</p>';

            if ( function_exists( 'seo_google_python_runner_statuses' ) ) {
                $statuses = seo_google_python_runner_statuses();
                if ( ! empty( $statuses ) ) {
                    echo '<h4 style="margin-bottom:6px;">Ultimas ejecuciones Python</h4><div style="overflow:auto;"><table class="widefat striped"><thead><tr><th>Fecha</th><th>Proveedor</th><th>Estado</th><th>Progreso</th><th>Mensaje</th></tr></thead><tbody>';
                    $shown = 0;
                    foreach ( $statuses as $run_id => $run ) {
                        if ( $shown++ >= 8 ) { break; }
                        $progress_bits = [];
                        if ( isset( $run['categories_done'] ) || isset( $run['categories_total'] ) ) {
                            $progress_bits[] = absint( $run['categories_done'] ?? 0 ) . '/' . absint( $run['categories_total'] ?? 0 ) . ' categorias';
                        }
                        if ( isset( $run['products_done'] ) || isset( $run['products_total'] ) ) {
                            $progress_bits[] = absint( $run['products_done'] ?? 0 ) . '/' . absint( $run['products_total'] ?? 0 ) . ' productos';
                        } elseif ( isset( $run['urls_found'] ) ) {
                            $progress_bits[] = absint( $run['urls_found'] ) . ' URLs';
                        }
                        echo '<tr><td>' . esc_html( $run['updated_at'] ?? '' ) . '</td><td>' . esc_html( $run['provider'] ?? $run['recipe_id'] ?? '' ) . '</td><td><code>' . esc_html( $run['status'] ?? '' ) . '</code></td><td>' . esc_html( implode( ' · ', $progress_bits ) ) . '</td><td>' . esc_html( $run['message'] ?? '' ) . '</td></tr>';
                    }
                    echo '</tbody></table></div>';
                }
            }

            echo '</div>';
        }

        if ( isset( $connections['github_python'] ) && function_exists( 'seo_github_python_runner_settings' ) ) {
            $gh = seo_github_python_runner_settings();
            $configured = ! empty( $gh['enabled'] )
                && '' !== trim( (string) ( $gh['owner'] ?? '' ) )
                && '' !== trim( (string) ( $gh['repo'] ?? '' ) )
                && '' !== trim( (string) ( $gh['workflow_id'] ?? '' ) )
                && '' !== trim( (string) ( $gh['ref'] ?? '' ) )
                && '' !== trim( (string) ( $gh['token'] ?? '' ) );

            echo '<div style="border:1px solid #dcdcde;border-radius:6px;padding:18px;margin-top:18px;background:#fff;">';
            echo '<div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;">';
            echo '<div><h3 style="margin:0 0 6px;">GitHub Actions Python Runner</h3><p style="margin:0;">Ejecuta los scrapers Python en GitHub Actions y devuelve automaticamente el CSV estandar al Catalogo de proveedores. No necesita Google Cloud.</p></div>';
            echo '<span style="display:inline-block;padding:5px 9px;border-radius:12px;background:' . ( $configured ? '#edfaef' : '#fff8e5' ) . ';">' . ( $configured ? 'Configurado' : 'Pendiente de configurar' ) . '</span>';
            echo '</div>';

            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;margin-top:18px;">';
            echo '<input type="hidden" name="action" value="seo_github_python_runner_save">';
            wp_nonce_field( 'seo_github_python_runner_save', 'seo_github_python_runner_nonce' );
            echo '<label style="grid-column:1/-1;"><input type="checkbox" name="enabled" value="1" ' . checked( ! empty( $gh['enabled'] ), true, false ) . '> <strong>Activar GitHub Actions Python Runner</strong></label>';
            echo '<label><strong>Usuario / organizacion GitHub</strong><br><input type="text" name="owner" value="' . esc_attr( $gh['owner'] ?? '' ) . '" placeholder="mi-usuario" class="regular-text" autocomplete="off" style="width:100%;"></label>';
            echo '<label><strong>Repositorio</strong><br><input type="text" name="repo" value="' . esc_attr( $gh['repo'] ?? '' ) . '" placeholder="seo-supplier-scrapers" class="regular-text" autocomplete="off" style="width:100%;"></label>';
            echo '<label><strong>Workflow</strong><br><input type="text" name="workflow_id" value="' . esc_attr( $gh['workflow_id'] ?? 'supplier-scraper.yml' ) . '" placeholder="supplier-scraper.yml" class="regular-text" autocomplete="off" style="width:100%;"><br><span class="description">Nombre del archivo dentro de <code>.github/workflows/</code>.</span></label>';
            echo '<label><strong>Rama / ref</strong><br><input type="text" name="ref" value="' . esc_attr( $gh['ref'] ?? 'main' ) . '" placeholder="main" class="regular-text" autocomplete="off" style="width:100%;"></label>';
            echo '<label style="grid-column:1/-1;"><strong>Token de acceso GitHub</strong><br><input type="password" name="token" value="" placeholder="' . esc_attr( ! empty( $gh['token'] ) ? 'Token guardado; deja vacio para conservarlo' : 'Fine-grained personal access token' ) . '" class="large-text" autocomplete="new-password"><br>';
            echo '<span class="description">Usa un token dedicado limitado a este repositorio con permiso <strong>Actions: Read and write</strong>. No introduzcas tu contrasena de GitHub. El token guardado no vuelve a mostrarse.</span></label>';
            echo '<div style="grid-column:1/-1;"><button class="button button-primary" type="submit">Guardar conexion GitHub</button></div>';
            echo '</form>';

            echo '<form method="post" style="margin-top:12px;display:inline-block;margin-right:8px;">';
            wp_nonce_field( 'seo_github_python_connection_test', 'seo_github_python_connection_nonce' );
            echo '<button class="button" type="submit" name="seo_github_python_test_connection" value="1">Probar GitHub Actions</button>';
            echo '</form>';

            if ( $configured && function_exists( 'seo_proveedores_recetas_importacion' ) ) {
                $recipes = seo_proveedores_recetas_importacion();
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="border-top:1px solid #dcdcde;margin-top:18px;padding-top:18px;">';
                echo '<input type="hidden" name="action" value="seo_github_python_runner_launch">';
                wp_nonce_field( 'seo_github_python_runner_launch', 'seo_github_python_runner_launch_nonce' );
                echo '<strong>Ejecutar scraper Python</strong><br><span class="description">WordPress dispara el workflow y le indica que receta/proveedor debe ejecutar.</span><br><br>';
                echo '<select name="recipe_id" required><option value="">Selecciona proveedor / receta</option>';
                foreach ( (array) $recipes as $recipe_id => $recipe ) {
                    echo '<option value="' . esc_attr( $recipe_id ) . '">' . esc_html( $recipe['label'] ?? $recipe_id ) . '</option>';
                }
                echo '</select> ';
                echo '<label style="margin-left:8px;"><input type="checkbox" name="catalog_complete" value="1"> Catalogo completo</label> ';
                echo '<button class="button button-primary" type="submit">Iniciar en GitHub</button>';
                echo '<p class="description">Por seguridad, Supplier V2 mantiene las bajas automaticas desactivadas. Catalogo completo solo permite detectar bajas pendientes.</p>';
                echo '</form>';
            }

            echo '<p class="description" style="margin-top:12px;">Callback privado GitHub: <code>' . esc_html( rest_url( 'seo-taxonomy/v1/supplier-runner/github-callback' ) ) . '</code>. WordPress genera un token temporal distinto para cada ejecucion y lo entrega al workflow.</p>';

            if ( function_exists( 'seo_github_python_runner_statuses' ) ) {
                $statuses = seo_github_python_runner_statuses();
                if ( ! empty( $statuses ) ) {
                    echo '<h4 style="margin-bottom:6px;">Ultimas ejecuciones GitHub</h4><div style="overflow:auto;"><table class="widefat striped"><thead><tr><th>Fecha</th><th>Proveedor</th><th>Estado</th><th>Progreso</th><th>Mensaje</th><th>GitHub</th></tr></thead><tbody>';
                    $shown = 0;
                    foreach ( $statuses as $run_id => $run ) {
                        if ( $shown++ >= 8 ) { break; }
                        $progress_bits = [];
                        if ( isset( $run['categories_done'] ) || isset( $run['categories_total'] ) ) {
                            $progress_bits[] = absint( $run['categories_done'] ?? 0 ) . '/' . absint( $run['categories_total'] ?? 0 ) . ' categorias';
                        }
                        if ( isset( $run['products_done'] ) || isset( $run['products_total'] ) ) {
                            $progress_bits[] = absint( $run['products_done'] ?? 0 ) . '/' . absint( $run['products_total'] ?? 0 ) . ' productos';
                        } elseif ( isset( $run['urls_found'] ) ) {
                            $progress_bits[] = absint( $run['urls_found'] ) . ' URLs';
                        }
                        $github_link = '';
                        if ( ! empty( $run['github_run_url'] ) ) {
                            $github_link = '<a href="' . esc_url( $run['github_run_url'] ) . '" target="_blank" rel="noopener noreferrer">Abrir</a>';
                        } elseif ( ! empty( $run['github_run_id'] ) ) {
                            $github_link = '#' . absint( $run['github_run_id'] );
                        }
                        echo '<tr><td>' . esc_html( $run['updated_at'] ?? '' ) . '</td><td>' . esc_html( $run['provider'] ?? $run['recipe_id'] ?? '' ) . '</td><td><code>' . esc_html( $run['status'] ?? '' ) . '</code></td><td>' . esc_html( implode( ' · ', $progress_bits ) ) . '</td><td>' . esc_html( $run['message'] ?? '' ) . '</td><td>' . $github_link . '</td></tr>';
                    }
                    echo '</tbody></table></div>';
                }
            }

            echo '</div>';
        }

        foreach ( $connections as $connection ) {
            if ( 'amazon' !== ( $connection['id'] ?? '' ) ) {
                continue;
            }

            if ( ! function_exists( 'seo_supplier_recipe_amazon_settings' ) ) {
                continue;
            }

            $s = seo_supplier_recipe_amazon_settings();
            $configured = ! empty( $s['client_id'] ) && ! empty( $s['client_secret'] ) && ! empty( $s['partner_tag'] );

            echo '<div style="border:1px solid #dcdcde;border-radius:6px;padding:18px;margin-top:18px;background:#fff;">';
            echo '<div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;">';
            echo '<div><h3 style="margin:0 0 6px;">Amazon Creators API</h3><p style="margin:0;">Marketplace: <strong>amazon.es</strong> · Credencial europea: <strong>3.2</strong></p></div>';
            echo '<span style="display:inline-block;padding:5px 9px;border-radius:12px;background:' . ( $configured ? '#edfaef' : '#fff8e5' ) . ';">' . ( $configured ? 'Configurada' : 'Pendiente de configurar' ) . '</span>';
            echo '</div>';

            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;margin-top:18px;">';
            echo '<input type="hidden" name="action" value="seo_amazon_recipe_save">';
            wp_nonce_field( 'seo_amazon_recipe_save', 'seo_amazon_recipe_nonce' );
            echo '<label><strong>Credential ID</strong><br><input type="text" name="client_id" value="' . esc_attr( $s['client_id'] ?? '' ) . '" class="regular-text" autocomplete="off" style="width:100%;"></label>';
            echo '<label><strong>Credential Secret</strong><br><input type="password" name="client_secret" value="" placeholder="' . esc_attr( ! empty( $s['client_secret'] ) ? 'Guardado; deja vacio para conservarlo' : 'Introduce el secret' ) . '" class="regular-text" autocomplete="new-password" style="width:100%;"></label>';
            echo '<label><strong>Partner Tag amazon.es</strong><br><input type="text" name="partner_tag" value="' . esc_attr( $s['partner_tag'] ?? '' ) . '" class="regular-text" style="width:100%;"></label>';
            echo '<input type="hidden" name="credential_version" value="3.2">';
            echo '<input type="hidden" name="search_index" value="' . esc_attr( $s['search_index'] ?? 'All' ) . '">';
            echo '<input type="hidden" name="item_count" value="' . absint( $s['item_count'] ?? 10 ) . '">';
            echo '<div style="grid-column:1/-1;"><button class="button button-primary" type="submit">Guardar conexion Amazon</button></div>';
            echo '</form>';

            echo '<form method="post" style="margin-top:12px;">';
            wp_nonce_field( 'seo_amazon_connection_test', 'seo_amazon_connection_nonce' );
            echo '<button class="button" type="submit" name="seo_amazon_test_connection" value="1">Probar conexion OAuth</button>';
            echo '</form>';

            echo '<p class="description" style="margin-top:12px;">Esta pantalla solo guarda credenciales y comprueba autenticacion. No descarga ni importa productos.</p>';
            echo '</div>';
        }

        echo '</div>';
    }
}

/**
 * Helpers comunes para enriquecimiento de fichas publicas de proveedores.
 * No contienen reglas comerciales ni mapeos de columnas.
 */
if ( ! function_exists( 'seo_supplier_web_text' ) ) {
    function seo_supplier_web_text( $value ) {
        $value = wp_strip_all_tags( (string) $value );
        return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
    }
}

if ( ! function_exists( 'seo_supplier_web_absolute_url' ) ) {
    function seo_supplier_web_absolute_url( $url, $base ) {
        $url = trim( html_entity_decode( (string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( '' === $url || 0 === strpos( $url, 'data:' ) || 0 === strpos( $url, 'javascript:' ) ) {
            return '';
        }
        if ( preg_match( '#^https?://#i', $url ) ) {
            return esc_url_raw( $url );
        }
        $scheme = (string) wp_parse_url( $base, PHP_URL_SCHEME );
        $host   = (string) wp_parse_url( $base, PHP_URL_HOST );
        if ( '' === $scheme || '' === $host ) {
            return '';
        }
        if ( 0 === strpos( $url, '//' ) ) {
            return esc_url_raw( $scheme . ':' . $url );
        }
        if ( 0 === strpos( $url, '/' ) ) {
            return esc_url_raw( $scheme . '://' . $host . $url );
        }
        $path = (string) wp_parse_url( $base, PHP_URL_PATH );
        $dir  = trailingslashit( dirname( $path ) );
        return esc_url_raw( $scheme . '://' . $host . $dir . ltrim( $url, '/' ) );
    }
}

if ( ! function_exists( 'seo_supplier_web_collect_jsonld_products' ) ) {
    function seo_supplier_web_collect_jsonld_products( $value, &$products ) {
        if ( ! is_array( $value ) ) {
            return;
        }
        $type  = $value['@type'] ?? '';
        $types = is_array( $type ) ? $type : [ $type ];
        foreach ( $types as $one_type ) {
            if ( 'product' === strtolower( (string) $one_type ) ) {
                $products[] = $value;
                break;
            }
        }
        foreach ( $value as $child ) {
            if ( is_array( $child ) ) {
                seo_supplier_web_collect_jsonld_products( $child, $products );
            }
        }
    }
}

if ( ! function_exists( 'seo_supplier_web_scrape_product' ) ) {
    function seo_supplier_web_scrape_product( $url, $cache_namespace = 'generic' ) {
        $empty = [
            'name' => '', 'description' => '', 'brand' => '', 'category' => '',
            'specifications' => [], 'images' => [], 'error' => '', 'http_status' => 0,
        ];
        $url = esc_url_raw( trim( (string) $url ) );
        if ( ! preg_match( '#^https?://#i', $url ) ) {
            $empty['error'] = 'URL de origen no valida.';
            return $empty;
        }

        $cache_key = 'seo_supplier_web_' . sanitize_key( $cache_namespace ) . '_' . md5( $url );
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return array_merge( $empty, $cached );
        }

        $response = wp_remote_get( $url, [
            'timeout' => 20,
            'redirection' => 5,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; SEOSystem/1.0; +' . home_url( '/' ) . ')',
                'Accept' => 'text/html,application/xhtml+xml',
            ],
        ] );
        if ( is_wp_error( $response ) ) {
            $empty['error'] = $response->get_error_message();
            return $empty;
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $html   = (string) wp_remote_retrieve_body( $response );
        $empty['http_status'] = $status;
        if ( $status < 200 || $status >= 400 || '' === trim( $html ) ) {
            $empty['error'] = 'HTTP ' . $status;
            return $empty;
        }
        if ( ! class_exists( 'DOMDocument' ) ) {
            $empty['error'] = 'DOMDocument no disponible en PHP.';
            return $empty;
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        if ( ! $loaded ) {
            $empty['error'] = 'No se pudo interpretar el HTML.';
            return $empty;
        }

        $xpath  = new DOMXPath( $dom );
        $result = $empty;
        $products = [];
        foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) as $script ) {
            $decoded = json_decode( trim( (string) $script->textContent ), true );
            if ( is_array( $decoded ) ) {
                seo_supplier_web_collect_jsonld_products( $decoded, $products );
            }
        }

        if ( ! empty( $products ) ) {
            $product = $products[0];
            $result['name']        = seo_supplier_web_text( $product['name'] ?? '' );
            $result['description'] = trim( wp_kses_post( (string) ( $product['description'] ?? '' ) ) );
            $brand = $product['brand'] ?? '';
            if ( is_array( $brand ) ) {
                $brand = $brand['name'] ?? '';
            }
            $result['brand']    = seo_supplier_web_text( $brand );
            $result['category'] = seo_supplier_web_text( $product['category'] ?? '' );

            $json_images = $product['image'] ?? [];
            if ( is_string( $json_images ) ) {
                $json_images = [ $json_images ];
            } elseif ( is_array( $json_images ) && isset( $json_images['url'] ) ) {
                $json_images = [ $json_images['url'] ];
            }
            foreach ( (array) $json_images as $image ) {
                if ( is_array( $image ) ) {
                    $image = $image['url'] ?? $image['contentUrl'] ?? '';
                }
                $absolute = seo_supplier_web_absolute_url( $image, $url );
                if ( '' !== $absolute ) {
                    $result['images'][] = $absolute;
                }
            }

            foreach ( (array) ( $product['additionalProperty'] ?? [] ) as $property ) {
                if ( ! is_array( $property ) ) {
                    continue;
                }
                $label = seo_supplier_web_text( $property['name'] ?? '' );
                $value = seo_supplier_web_text( $property['value'] ?? '' );
                if ( '' !== $label && '' !== $value ) {
                    $result['specifications'][ $label ] = $value;
                }
            }
        }

        if ( '' === $result['name'] ) {
            foreach ( [ '//h1[1]', '//meta[@property="og:title"]/@content' ] as $query ) {
                $nodes = $xpath->query( $query );
                if ( $nodes && $nodes->length ) {
                    $candidate = seo_supplier_web_text( $nodes->item( 0 )->nodeValue ?: $nodes->item( 0 )->textContent );
                    if ( '' !== $candidate ) {
                        $result['name'] = $candidate;
                        break;
                    }
                }
            }
        }

        if ( '' === trim( wp_strip_all_tags( $result['description'] ) ) ) {
            foreach ( [ '//meta[@property="og:description"]/@content', '//meta[@name="description"]/@content' ] as $query ) {
                $nodes = $xpath->query( $query );
                if ( $nodes && $nodes->length ) {
                    $candidate = seo_supplier_web_text( $nodes->item( 0 )->nodeValue );
                    if ( '' !== $candidate ) {
                        $result['description'] = wpautop( esc_html( $candidate ) );
                        break;
                    }
                }
            }
        }

        if ( '' === trim( wp_strip_all_tags( $result['description'] ) ) ) {
            foreach ( [
                '//*[contains(concat(" ", normalize-space(@class), " "), " product-description ")]',
                '//*[@id="description"]',
                '//*[contains(@class,"description")][1]',
            ] as $query ) {
                $nodes = $xpath->query( $query );
                if ( ! $nodes || ! $nodes->length ) {
                    continue;
                }
                $candidate = '';
                foreach ( $nodes->item( 0 )->childNodes as $child ) {
                    $candidate .= $dom->saveHTML( $child );
                }
                $candidate = trim( wp_kses_post( $candidate ) );
                if ( strlen( wp_strip_all_tags( $candidate ) ) >= 40 ) {
                    $result['description'] = $candidate;
                    break;
                }
            }
        }

        foreach ( $xpath->query( '//table//tr' ) as $tr ) {
            $cells = [];
            foreach ( $tr->childNodes as $child ) {
                if ( XML_ELEMENT_NODE === $child->nodeType && in_array( strtolower( $child->nodeName ), [ 'th', 'td' ], true ) ) {
                    $cells[] = seo_supplier_web_text( $child->textContent );
                }
            }
            if ( count( $cells ) >= 2 && '' !== $cells[0] && '' !== $cells[1] && strlen( $cells[0] ) <= 120 ) {
                $result['specifications'][ $cells[0] ] = $cells[1];
            }
        }
        foreach ( $xpath->query( '//dl/dt' ) as $dt ) {
            $dd = $dt->nextSibling;
            while ( $dd && ( XML_ELEMENT_NODE !== $dd->nodeType || 'dd' !== strtolower( $dd->nodeName ) ) ) {
                $dd = $dd->nextSibling;
            }
            if ( $dd ) {
                $label = seo_supplier_web_text( $dt->textContent );
                $value = seo_supplier_web_text( $dd->textContent );
                if ( '' !== $label && '' !== $value ) {
                    $result['specifications'][ $label ] = $value;
                }
            }
        }

        foreach ( [ '//meta[@property="og:image"]/@content', '//img[@src]/@src', '//img[@data-src]/@data-src', '//img[@data-large-file]/@data-large-file' ] as $query ) {
            foreach ( $xpath->query( $query ) as $node ) {
                $absolute = seo_supplier_web_absolute_url( $node->nodeValue, $url );
                if ( '' === $absolute || preg_match( '/(?:logo|icon|sprite|avatar|favicon|banner)/i', $absolute ) ) {
                    continue;
                }
                if ( ! preg_match( '/\.(?:jpe?g|png|webp|gif)(?:\?|$)/i', $absolute ) ) {
                    continue;
                }
                $result['images'][] = $absolute;
            }
        }

        $result['images'] = array_values( array_slice( array_unique( array_filter( $result['images'] ) ), 0, 20 ) );
        $result['specifications'] = array_slice( $result['specifications'], 0, 40, true );
        set_transient( $cache_key, $result, 7 * DAY_IN_SECONDS );
        return $result;
    }
}
