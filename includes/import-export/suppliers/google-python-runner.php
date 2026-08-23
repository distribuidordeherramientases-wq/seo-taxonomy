<?php
/**
 * SEO System - Google Python Runner para catalogos de proveedores.
 *
 * WordPress inicia un Cloud Run Job y el proceso Python devuelve progreso y,
 * al terminar, un CSV estandar al mismo importador comun de proveedores.
 *
 * Google Colab clasico sigue siendo util para desarrollar/probar scripts, pero
 * la ejecucion remota desatendida se realiza mediante Cloud Run Jobs.
 *
 * @package SEOSystem
 * @subpackage SupplierImports
 * @version 0.1.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'seo_google_python_runner_settings' ) ) {
    function seo_google_python_runner_settings() {
        $defaults = [
            'enabled'              => 0,
            'project_id'           => '',
            'region'               => 'europe-west1',
            'job_name'             => 'seo-supplier-scraper',
            'service_account_json' => '',
        ];
        $saved = get_option( 'seo_google_python_runner_settings', [] );
        return wp_parse_args( is_array( $saved ) ? $saved : [], $defaults );
    }
}

if ( ! function_exists( 'seo_google_python_runner_service_account' ) ) {
    function seo_google_python_runner_service_account() {
        $settings = seo_google_python_runner_settings();
        $raw = trim( (string) ( $settings['service_account_json'] ?? '' ) );
        if ( '' === $raw ) {
            return new WP_Error( 'seo_python_google_no_credentials', 'No hay una cuenta de servicio configurada para Google Python Runner.' );
        }

        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) || empty( $data['client_email'] ) || empty( $data['private_key'] ) ) {
            return new WP_Error( 'seo_python_google_invalid_credentials', 'El JSON de la cuenta de servicio de Google Python Runner no es valido.' );
        }
        return $data;
    }
}

if ( ! function_exists( 'seo_google_python_runner_base64url' ) ) {
    function seo_google_python_runner_base64url( $value ) {
        return rtrim( strtr( base64_encode( (string) $value ), '+/', '-_' ), '=' );
    }
}

if ( ! function_exists( 'seo_google_python_runner_access_token' ) ) {
    function seo_google_python_runner_access_token( $force = false ) {
        $account = seo_google_python_runner_service_account();
        if ( is_wp_error( $account ) ) {
            return $account;
        }

        $cache_key = 'seo_google_python_token_' . md5( (string) $account['client_email'] );
        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( is_string( $cached ) && '' !== $cached ) {
                return $cached;
            }
        }

        if ( ! function_exists( 'openssl_sign' ) ) {
            return new WP_Error( 'seo_python_google_openssl_missing', 'OpenSSL no esta disponible en PHP.' );
        }

        $now = time();
        $token_uri = ! empty( $account['token_uri'] )
            ? esc_url_raw( (string) $account['token_uri'] )
            : 'https://oauth2.googleapis.com/token';

        $header = seo_google_python_runner_base64url(
            wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] )
        );
        $claims = seo_google_python_runner_base64url(
            wp_json_encode(
                [
                    'iss'   => (string) $account['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/cloud-platform',
                    'aud'   => $token_uri,
                    'iat'   => $now,
                    'exp'   => $now + 3500,
                ]
            )
        );

        $unsigned = $header . '.' . $claims;
        $signature = '';
        if ( ! openssl_sign( $unsigned, $signature, (string) $account['private_key'], OPENSSL_ALGO_SHA256 ) ) {
            return new WP_Error( 'seo_python_google_sign_failed', 'No se pudo firmar el token de Google Python Runner.' );
        }

        $assertion = $unsigned . '.' . seo_google_python_runner_base64url( $signature );
        $response = wp_remote_post(
            $token_uri,
            [
                'timeout' => 25,
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
            $message = 'Google no ha emitido un access token para Cloud Run.';
            if ( is_array( $body ) && ! empty( $body['error_description'] ) ) {
                $message = sanitize_text_field( (string) $body['error_description'] );
            }
            return new WP_Error( 'seo_python_google_token_failed', $message );
        }

        $token = sanitize_text_field( (string) $body['access_token'] );
        $ttl = ! empty( $body['expires_in'] ) ? max( 60, absint( $body['expires_in'] ) - 120 ) : 3300;
        set_transient( $cache_key, $token, $ttl );
        return $token;
    }
}

if ( ! function_exists( 'seo_google_python_runner_job_url' ) ) {
    function seo_google_python_runner_job_url( $run = false ) {
        $settings = seo_google_python_runner_settings();
        $project = trim( (string) ( $settings['project_id'] ?? '' ) );
        $region  = trim( (string) ( $settings['region'] ?? '' ) );
        $job     = trim( (string) ( $settings['job_name'] ?? '' ) );
        if ( '' === $project || '' === $region || '' === $job ) {
            return '';
        }

        $url = sprintf(
            'https://run.googleapis.com/v2/projects/%s/locations/%s/jobs/%s',
            rawurlencode( $project ),
            rawurlencode( $region ),
            rawurlencode( $job )
        );
        return $run ? $url . ':run' : $url;
    }
}

if ( ! function_exists( 'seo_google_python_runner_api_request' ) ) {
    function seo_google_python_runner_api_request( $method, $url, $body = null, $force_token = false ) {
        $token = seo_google_python_runner_access_token( $force_token );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $args = [
            'method'  => strtoupper( (string) $method ),
            'timeout' => 35,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
        ];

        if ( null !== $body ) {
            $args['headers']['Content-Type'] = 'application/json; charset=utf-8';
            $args['body'] = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        }

        $response = wp_remote_request( esc_url_raw( $url ), $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status  = (int) wp_remote_retrieve_response_code( $response );
        $raw     = (string) wp_remote_retrieve_body( $response );
        $decoded = json_decode( $raw, true );

        if ( $status < 200 || $status >= 300 ) {
            $message = 'Google Cloud Run ha respondido HTTP ' . $status . '.';
            if ( is_array( $decoded ) && ! empty( $decoded['error']['message'] ) ) {
                $message .= ' ' . sanitize_text_field( (string) $decoded['error']['message'] );
            }
            return new WP_Error( 'seo_python_google_api_error', $message, [ 'status' => $status ] );
        }

        return is_array( $decoded ) ? $decoded : [];
    }
}

if ( ! function_exists( 'seo_google_python_runner_test_connection' ) ) {
    function seo_google_python_runner_test_connection() {
        $settings = seo_google_python_runner_settings();
        if ( empty( $settings['project_id'] ) || empty( $settings['region'] ) || empty( $settings['job_name'] ) ) {
            return [ 'ok' => false, 'messages' => [ 'Faltan Project ID, region o nombre del Cloud Run Job.' ] ];
        }

        $url = seo_google_python_runner_job_url( false );
        $job = $url ? seo_google_python_runner_api_request( 'GET', $url, null, true ) : new WP_Error( 'seo_python_google_job_config', 'La ruta del Job no esta configurada.' );
        if ( is_wp_error( $job ) ) {
            return [ 'ok' => false, 'messages' => [ $job->get_error_message() ] ];
        }

        $name = sanitize_text_field( (string) ( $job['name'] ?? $settings['job_name'] ) );
        return [
            'ok'       => true,
            'messages' => [ 'Autenticacion Google correcta.', 'Cloud Run Job accesible: ' . $name ],
        ];
    }
}

if ( ! function_exists( 'seo_google_python_runner_save_settings' ) ) {
    function seo_google_python_runner_save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para guardar esta conexion.', 'seo-system' ) );
        }
        check_admin_referer( 'seo_google_python_runner_save', 'seo_google_python_runner_nonce' );

        $current = seo_google_python_runner_settings();
        $project = sanitize_text_field( wp_unslash( $_POST['project_id'] ?? '' ) );
        $region  = sanitize_key( wp_unslash( $_POST['region'] ?? '' ) );
        $job     = sanitize_key( wp_unslash( $_POST['job_name'] ?? '' ) );
        $service_raw = trim( (string) wp_unslash( $_POST['service_account_json'] ?? '' ) );
        $service_json = (string) ( $current['service_account_json'] ?? '' );

        if ( '' !== $service_raw ) {
            $decoded = json_decode( $service_raw, true );
            if ( ! is_array( $decoded ) || empty( $decoded['client_email'] ) || empty( $decoded['private_key'] ) ) {
                wp_safe_redirect(
                    add_query_arg(
                        [ 'page' => 'seo-import-export', 'seo_ie_tab' => 'conexiones-proveedores', 'google_python_error' => 'credentials' ],
                        admin_url( 'admin.php' )
                    )
                );
                exit;
            }
            $service_json = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES );
        }

        if ( '' === $project || '' === $region || '' === $job ) {
            wp_safe_redirect(
                add_query_arg(
                    [ 'page' => 'seo-import-export', 'seo_ie_tab' => 'conexiones-proveedores', 'google_python_error' => 'fields' ],
                    admin_url( 'admin.php' )
                )
            );
            exit;
        }

        update_option(
            'seo_google_python_runner_settings',
            [
                'enabled'              => ! empty( $_POST['enabled'] ) ? 1 : 0,
                'project_id'           => $project,
                'region'               => $region,
                'job_name'             => $job,
                'service_account_json' => $service_json,
            ],
            false
        );

        wp_safe_redirect(
            add_query_arg(
                [ 'page' => 'seo-import-export', 'seo_ie_tab' => 'conexiones-proveedores', 'google_python_saved' => '1' ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }
}
add_action( 'admin_post_seo_google_python_runner_save', 'seo_google_python_runner_save_settings' );

if ( ! function_exists( 'seo_google_python_runner_notice_key' ) ) {
    function seo_google_python_runner_notice_key() {
        return 'seo_google_python_runner_notice_' . get_current_user_id();
    }
}

if ( ! function_exists( 'seo_google_python_runner_set_notice' ) ) {
    function seo_google_python_runner_set_notice( $type, $message ) {
        set_transient(
            seo_google_python_runner_notice_key(),
            [ 'type' => sanitize_key( $type ), 'message' => sanitize_text_field( (string) $message ) ],
            5 * MINUTE_IN_SECONDS
        );
    }
}

if ( ! function_exists( 'seo_google_python_runner_get_notice' ) ) {
    function seo_google_python_runner_get_notice() {
        $key = seo_google_python_runner_notice_key();
        $notice = get_transient( $key );
        delete_transient( $key );
        return is_array( $notice ) ? $notice : [];
    }
}

if ( ! function_exists( 'seo_google_python_runner_statuses' ) ) {
    function seo_google_python_runner_statuses() {
        $statuses = get_option( 'seo_google_python_runner_statuses', [] );
        return is_array( $statuses ) ? $statuses : [];
    }
}

if ( ! function_exists( 'seo_google_python_runner_update_status' ) ) {
    function seo_google_python_runner_update_status( $remote_run_id, array $data ) {
        $remote_run_id = sanitize_text_field( (string) $remote_run_id );
        if ( '' === $remote_run_id ) {
            return;
        }

        $statuses = seo_google_python_runner_statuses();
        $previous = isset( $statuses[ $remote_run_id ] ) && is_array( $statuses[ $remote_run_id ] ) ? $statuses[ $remote_run_id ] : [];
        $statuses[ $remote_run_id ] = array_merge(
            $previous,
            $data,
            [ 'updated_at' => current_time( 'mysql' ) ]
        );

        uasort(
            $statuses,
            static function ( $a, $b ) {
                return strcmp( (string) ( $b['updated_at'] ?? '' ), (string) ( $a['updated_at'] ?? '' ) );
            }
        );
        $statuses = array_slice( $statuses, 0, 25, true );
        update_option( 'seo_google_python_runner_statuses', $statuses, false );
    }
}

if ( ! function_exists( 'seo_google_python_runner_start' ) ) {
    function seo_google_python_runner_start( $recipe_id, $catalog_complete = false ) {
        if ( ! function_exists( 'seo_proveedores_obtener_receta' ) ) {
            return new WP_Error( 'seo_python_importer_missing', 'El importador comun de proveedores no esta disponible.' );
        }

        $settings = seo_google_python_runner_settings();
        if ( empty( $settings['enabled'] ) ) {
            return new WP_Error( 'seo_python_runner_disabled', 'Google Python Runner esta desactivado.' );
        }

        $recipe_id = sanitize_key( $recipe_id );
        $recipe = seo_proveedores_obtener_receta( $recipe_id );
        if ( ! is_array( $recipe ) ) {
            return new WP_Error( 'seo_python_recipe_missing', 'La receta de proveedor seleccionada no existe.' );
        }

        $job_url = seo_google_python_runner_job_url( true );
        if ( '' === $job_url ) {
            return new WP_Error( 'seo_python_job_config', 'La conexion Google Python Runner esta incompleta.' );
        }

        $remote_run_id = wp_generate_uuid4();
        $callback_token = wp_generate_password( 64, false, false );
        $run_state = [
            'remote_run_id'     => $remote_run_id,
            'recipe_id'         => $recipe_id,
            'provider'          => sanitize_text_field( (string) $recipe['provider'] ),
            'catalog_complete'  => $catalog_complete ? 1 : 0,
            'callback_token'    => $callback_token,
            'started_at'        => time(),
        ];
        set_transient( 'seo_google_python_run_' . md5( $callback_token ), $run_state, 2 * DAY_IN_SECONDS );

        $env = [
            [ 'name' => 'SEO_SUPPLIER_RECIPE', 'value' => $recipe_id ],
            [ 'name' => 'SEO_SUPPLIER_PROVIDER', 'value' => (string) $recipe['provider'] ],
            [ 'name' => 'SEO_SUPPLIER_CATALOG_COMPLETE', 'value' => $catalog_complete ? '1' : '0' ],
            [ 'name' => 'SEO_SUPPLIER_REMOTE_RUN_ID', 'value' => $remote_run_id ],
            [ 'name' => 'SEO_SUPPLIER_CALLBACK_URL', 'value' => rest_url( 'seo-taxonomy/v1/supplier-runner/callback' ) ],
            [ 'name' => 'SEO_SUPPLIER_CALLBACK_TOKEN', 'value' => $callback_token ],
        ];

        $payload = [
            'overrides' => [
                'containerOverrides' => [
                    [ 'env' => $env ],
                ],
            ],
        ];

        $response = seo_google_python_runner_api_request( 'POST', $job_url, $payload );
        if ( is_wp_error( $response ) ) {
            delete_transient( 'seo_google_python_run_' . md5( $callback_token ) );
            return $response;
        }

        seo_google_python_runner_update_status(
            $remote_run_id,
            [
                'recipe_id'        => $recipe_id,
                'provider'         => sanitize_text_field( (string) $recipe['provider'] ),
                'catalog_complete' => $catalog_complete ? 1 : 0,
                'status'           => 'starting',
                'message'          => 'Cloud Run Job solicitado desde WordPress.',
                'google_operation' => sanitize_text_field( (string) ( $response['name'] ?? '' ) ),
            ]
        );

        return [
            'remote_run_id' => $remote_run_id,
            'operation'     => sanitize_text_field( (string) ( $response['name'] ?? '' ) ),
        ];
    }
}

if ( ! function_exists( 'seo_google_python_runner_handle_launch' ) ) {
    function seo_google_python_runner_handle_launch() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para iniciar el scraper Python.', 'seo-system' ) );
        }
        check_admin_referer( 'seo_google_python_runner_launch', 'seo_google_python_runner_launch_nonce' );

        $recipe_id = sanitize_key( wp_unslash( $_POST['recipe_id'] ?? '' ) );
        $catalog_complete = ! empty( $_POST['catalog_complete'] );
        $result = seo_google_python_runner_start( $recipe_id, $catalog_complete );

        if ( is_wp_error( $result ) ) {
            seo_google_python_runner_set_notice( 'error', $result->get_error_message() );
        } else {
            seo_google_python_runner_set_notice( 'success', 'Trabajo Python iniciado. ID remoto: ' . (string) $result['remote_run_id'] );
        }

        wp_safe_redirect(
            add_query_arg(
                [ 'page' => 'seo-import-export', 'seo_ie_tab' => 'conexiones-proveedores' ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }
}
add_action( 'admin_post_seo_google_python_runner_launch', 'seo_google_python_runner_handle_launch' );

if ( ! function_exists( 'seo_google_python_runner_authorize_callback' ) ) {
    function seo_google_python_runner_authorize_callback( WP_REST_Request $request ) {
        $authorization = trim( (string) $request->get_header( 'authorization' ) );
        if ( ! preg_match( '/^Bearer\s+(.+)$/i', $authorization, $match ) ) {
            return new WP_Error( 'seo_python_callback_auth', 'Falta autenticacion del runner.', [ 'status' => 401 ] );
        }

        $token = trim( (string) $match[1] );
        if ( '' === $token ) {
            return new WP_Error( 'seo_python_callback_auth', 'Token de callback vacio.', [ 'status' => 401 ] );
        }

        $state = get_transient( 'seo_google_python_run_' . md5( $token ) );
        if ( ! is_array( $state ) || empty( $state['callback_token'] ) || ! hash_equals( (string) $state['callback_token'], $token ) ) {
            return new WP_Error( 'seo_python_callback_auth', 'Token de callback no valido o caducado.', [ 'status' => 403 ] );
        }
        return $state;
    }
}

if ( ! function_exists( 'seo_google_python_runner_queue_import' ) ) {
    function seo_google_python_runner_queue_import( $remote_run_id, array $state ) {
        $queue_key = 'seo_google_python_import_' . md5( (string) $remote_run_id );
        set_transient( $queue_key, $state, 2 * DAY_IN_SECONDS );

        $args = [ (string) $remote_run_id ];
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( 'seo_google_python_runner_import_csv', $args, 'seo-google-python', true );
            return true;
        }
        if ( function_exists( 'wp_schedule_single_event' ) ) {
            $scheduled = wp_schedule_single_event( time() + 5, 'seo_google_python_runner_import_csv', $args );
            if ( false !== $scheduled ) {
                return true;
            }
        }

        seo_google_python_runner_process_import( (string) $remote_run_id );
        return true;
    }
}

if ( ! function_exists( 'seo_google_python_runner_process_import' ) ) {
    function seo_google_python_runner_process_import( $remote_run_id ) {
        $remote_run_id = sanitize_text_field( (string) $remote_run_id );
        $queue_key = 'seo_google_python_import_' . md5( $remote_run_id );
        $queued = get_transient( $queue_key );
        if ( ! is_array( $queued ) ) {
            seo_google_python_runner_update_status( $remote_run_id, [ 'status' => 'error', 'message' => 'No se encontro el estado de importacion del CSV recibido.' ] );
            return;
        }

        if ( ! function_exists( 'seo_proveedores_analizar_csv' ) || ! function_exists( 'seo_proveedores_importar_csv_estandar' ) ) {
            seo_google_python_runner_update_status( $remote_run_id, [ 'status' => 'error', 'message' => 'El importador comun de proveedores no esta disponible.' ] );
            return;
        }

        $path = (string) ( $queued['path'] ?? '' );
        $analysis = seo_proveedores_analizar_csv( $path );
        if ( is_wp_error( $analysis ) ) {
            seo_google_python_runner_update_status( $remote_run_id, [ 'status' => 'error', 'message' => $analysis->get_error_message() ] );
            return;
        }

        $required = [];
        foreach ( (array) seo_proveedores_campos_importacion() as $field => $definition ) {
            if ( ! empty( $definition['required'] ) ) {
                $required[] = $field;
            }
        }
        foreach ( $required as $required_field ) {
            if ( ! in_array( $required_field, (array) $analysis['header'], true ) ) {
                seo_google_python_runner_update_status( $remote_run_id, [ 'status' => 'error', 'message' => 'El CSV Python no contiene el campo obligatorio: ' . $required_field ] );
                return;
            }
        }

        $state = array_merge(
            $analysis,
            [
                'recipe_id'          => sanitize_key( (string) ( $queued['recipe_id'] ?? '' ) ),
                'recipe_label'       => sanitize_text_field( (string) ( $queued['recipe_label'] ?? '' ) ),
                'recipe_version'     => sanitize_text_field( (string) ( $queued['recipe_version'] ?? '' ) ),
                'proveedor'          => sanitize_text_field( (string) ( $queued['provider'] ?? '' ) ),
                'filename'           => sanitize_file_name( (string) ( $queued['filename'] ?? basename( $path ) ) ),
                'path'               => wp_normalize_path( $path ),
                'url'                => esc_url_raw( (string) ( $queued['url'] ?? '' ) ),
                'original_filename'  => sanitize_file_name( (string) ( $queued['source_name'] ?? '' ) ),
                'original_path'      => '',
                'created'            => time(),
                'automatic_source'   => true,
                'v2_source'          => 'google_python',
                'v2_catalog_complete'=> ! empty( $queued['catalog_complete'] ),
                'v2_auto_apply'      => true,
                'v2_auto_bajas'      => false,
                'v2_image_mode'      => 'external',
            ]
        );

        seo_google_python_runner_update_status(
            $remote_run_id,
            [ 'status' => 'importing', 'message' => 'CSV recibido; importador comun en ejecucion.', 'rows_total' => absint( $analysis['rows_total'] ?? 0 ) ]
        );

        $log = seo_proveedores_importar_csv_estandar(
            $state,
            [ 'preparados' => absint( $analysis['rows_total'] ?? 0 ), 'omitidos' => 0, 'errores' => 0, 'detalles' => [] ]
        );

        if ( is_wp_error( $log ) ) {
            seo_google_python_runner_update_status( $remote_run_id, [ 'status' => 'error', 'message' => $log->get_error_message() ] );
            return;
        }

        delete_transient( $queue_key );
        seo_google_python_runner_update_status(
            $remote_run_id,
            [
                'status'      => 'completed',
                'message'     => 'CSV Python importado y entregado a el importador comun.',
                'procesados'  => absint( $log['procesados'] ?? 0 ),
                'creados'     => absint( $log['creados'] ?? 0 ),
                'actualizados'=> absint( $log['actualizados'] ?? 0 ),
                'sin_cambios' => absint( $log['sin_cambios'] ?? 0 ),
                'errores'     => absint( $log['errores'] ?? 0 ),
            ]
        );
    }
}
add_action( 'seo_google_python_runner_import_csv', 'seo_google_python_runner_process_import', 10, 1 );

if ( ! function_exists( 'seo_google_python_runner_callback' ) ) {
    function seo_google_python_runner_callback( WP_REST_Request $request ) {
        $run = seo_google_python_runner_authorize_callback( $request );
        if ( is_wp_error( $run ) ) {
            return $run;
        }

        $remote_run_id = sanitize_text_field( (string) ( $run['remote_run_id'] ?? '' ) );
        $status = sanitize_key( (string) $request->get_param( 'status' ) );
        if ( ! in_array( $status, [ 'started', 'progress', 'completed', 'error' ], true ) ) {
            $status = 'progress';
        }

        $message = sanitize_text_field( (string) $request->get_param( 'message' ) );
        $progress = [
            'recipe_id' => sanitize_key( (string) ( $run['recipe_id'] ?? '' ) ),
            'provider'  => sanitize_text_field( (string) ( $run['provider'] ?? '' ) ),
            'status'    => $status,
            'message'   => $message,
        ];

        foreach ( [ 'categories_done', 'categories_total', 'urls_found', 'products_done', 'products_total', 'errors' ] as $metric ) {
            $value = $request->get_param( $metric );
            if ( null !== $value && '' !== $value ) {
                $progress[ $metric ] = absint( $value );
            }
        }
        seo_google_python_runner_update_status( $remote_run_id, $progress );

        if ( 'error' === $status ) {
            delete_transient( 'seo_google_python_run_' . md5( (string) $run['callback_token'] ) );
            return rest_ensure_response( [ 'ok' => true, 'accepted' => 'error' ] );
        }

        if ( 'completed' !== $status ) {
            return rest_ensure_response( [ 'ok' => true, 'accepted' => $status ] );
        }

        $files = $request->get_file_params();
        $file = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : [];
        if ( empty( $file['tmp_name'] ) || ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
            return new WP_Error( 'seo_python_callback_file', 'El runner ha marcado completed pero no ha enviado un CSV valido.', [ 'status' => 400 ] );
        }

        $source_name = sanitize_file_name( (string) ( $file['name'] ?? 'catalogo.csv' ) );
        if ( 'csv' !== strtolower( pathinfo( $source_name, PATHINFO_EXTENSION ) ) ) {
            return new WP_Error( 'seo_python_callback_extension', 'Solo se admite el CSV estandar de proveedores.', [ 'status' => 400 ] );
        }

        $recipe_id = sanitize_key( (string) ( $run['recipe_id'] ?? '' ) );
        $recipe = function_exists( 'seo_proveedores_obtener_receta' ) ? seo_proveedores_obtener_receta( $recipe_id ) : null;
        if ( ! is_array( $recipe ) ) {
            return new WP_Error( 'seo_python_callback_recipe', 'La receta asociada a la ejecucion ya no existe.', [ 'status' => 409 ] );
        }

        $storage = seo_proveedores_storage_receta( 'prepared', $recipe_id );
        if ( is_wp_error( $storage ) ) {
            return $storage;
        }

        $stored_name = wp_unique_filename( $storage['dir'], $source_name );
        $stored_path = trailingslashit( $storage['dir'] ) . $stored_name;
        $moved = is_uploaded_file( $file['tmp_name'] )
            ? move_uploaded_file( $file['tmp_name'], $stored_path )
            : @rename( $file['tmp_name'], $stored_path );
        if ( ! $moved ) {
            return new WP_Error( 'seo_python_callback_store', 'No se pudo conservar el CSV recibido desde Google.', [ 'status' => 500 ] );
        }

        $queued = [
            'recipe_id'        => $recipe_id,
            'recipe_label'     => (string) ( $recipe['label'] ?? $recipe_id ) . ' - Google Python',
            'recipe_version'   => (string) ( $recipe['version'] ?? '' ),
            'provider'         => (string) ( $recipe['provider'] ?? $run['provider'] ?? '' ),
            'catalog_complete' => ! empty( $run['catalog_complete'] ),
            'source_name'      => $source_name,
            'filename'         => $stored_name,
            'path'             => wp_normalize_path( $stored_path ),
            'url'              => trailingslashit( $storage['url'] ) . rawurlencode( $stored_name ),
        ];

        seo_google_python_runner_queue_import( $remote_run_id, $queued );
        seo_google_python_runner_update_status(
            $remote_run_id,
            [ 'status' => 'queued_import', 'message' => 'CSV recibido correctamente; importacion interna encolada.' ]
        );
        delete_transient( 'seo_google_python_run_' . md5( (string) $run['callback_token'] ) );

        return rest_ensure_response(
            [
                'ok'            => true,
                'accepted'      => 'completed',
                'remote_run_id' => $remote_run_id,
                'file'          => $stored_name,
            ]
        );
    }
}

if ( ! function_exists( 'seo_google_python_runner_register_rest' ) ) {
    function seo_google_python_runner_register_rest() {
        register_rest_route(
            'seo-taxonomy/v1',
            '/supplier-runner/callback',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => 'seo_google_python_runner_callback',
                'permission_callback' => '__return_true',
            ]
        );
    }
}
add_action( 'rest_api_init', 'seo_google_python_runner_register_rest' );
