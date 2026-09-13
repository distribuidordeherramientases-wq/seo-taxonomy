<?php
/**
 * SEO System - GitHub Actions Python Runner para catalogos de proveedores.
 *
 * WordPress dispara un workflow_dispatch de GitHub Actions. El workflow
 * ejecuta el scraper Python y devuelve progreso y el CSV estandar a un
 * callback privado de WordPress. El CSV entra despues por el importador comun
 * y el importador comun.
 *
 * @package SEOSystem
 * @subpackage SupplierImports
 * @version 0.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'seo_github_python_runner_settings' ) ) {
    function seo_github_python_runner_settings() {
        $defaults = [
            'enabled'     => 0,
            'owner'       => '',
            'repo'        => '',
            'workflow_id' => 'supplier-scraper.yml',
            'ref'         => 'main',
            'token'       => '',
        ];
        $saved = get_option( 'seo_github_python_runner_settings', [] );
        return wp_parse_args( is_array( $saved ) ? $saved : [], $defaults );
    }
}

if ( ! function_exists( 'seo_github_python_runner_api_url' ) ) {
    function seo_github_python_runner_api_url( $workflow = true, $dispatch = false ) {
        $settings = seo_github_python_runner_settings();
        $owner = trim( (string) ( $settings['owner'] ?? '' ) );
        $repo = trim( (string) ( $settings['repo'] ?? '' ) );
        $workflow_id = trim( (string) ( $settings['workflow_id'] ?? '' ) );

        if ( '' === $owner || '' === $repo ) {
            return '';
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/%s',
            rawurlencode( $owner ),
            rawurlencode( $repo )
        );

        if ( ! $workflow ) {
            return $url;
        }

        if ( '' === $workflow_id ) {
            return '';
        }

        $url .= '/actions/workflows/' . rawurlencode( $workflow_id );
        return $dispatch ? $url . '/dispatches' : $url;
    }
}

if ( ! function_exists( 'seo_github_python_runner_api_request' ) ) {
    function seo_github_python_runner_api_request( $method, $url, $body = null ) {
        $settings = seo_github_python_runner_settings();
        $token = trim( (string) ( $settings['token'] ?? '' ) );
        if ( '' === $token ) {
            return new WP_Error( 'seo_github_python_no_token', 'No hay un token de GitHub configurado.' );
        }
        if ( '' === $url ) {
            return new WP_Error( 'seo_github_python_no_url', 'La ruta de GitHub Actions no esta configurada.' );
        }

        $args = [
            'method'      => strtoupper( (string) $method ),
            'timeout'     => 35,
            'redirection' => 3,
            'headers'     => [
                'Authorization'        => 'Bearer ' . $token,
                'Accept'               => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2026-03-10',
                'User-Agent'           => 'SEO-Taxonomy-Supplier-Runner',
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

        $status = (int) wp_remote_retrieve_response_code( $response );
        $raw = (string) wp_remote_retrieve_body( $response );
        $decoded = '' !== trim( $raw ) ? json_decode( $raw, true ) : [];

        if ( $status < 200 || $status >= 300 ) {
            $message = 'GitHub ha respondido HTTP ' . $status . '.';
            if ( is_array( $decoded ) && ! empty( $decoded['message'] ) ) {
                $message .= ' ' . sanitize_text_field( (string) $decoded['message'] );
            }
            if ( 401 === $status ) {
                $message .= ' Revisa el token.';
            } elseif ( 403 === $status ) {
                $message .= ' El token necesita permiso Actions: write sobre este repositorio.';
            } elseif ( 404 === $status ) {
                $message .= ' Revisa usuario/organizacion, repositorio, rama y nombre del workflow.';
            }
            return new WP_Error( 'seo_github_python_api_error', $message, [ 'status' => $status ] );
        }

        $result = is_array( $decoded ) ? $decoded : [];
        $result['_http_status'] = $status;
        return $result;
    }
}

if ( ! function_exists( 'seo_github_python_runner_test_connection' ) ) {
    function seo_github_python_runner_test_connection() {
        $settings = seo_github_python_runner_settings();
        foreach ( [ 'owner', 'repo', 'workflow_id', 'ref', 'token' ] as $field ) {
            if ( '' === trim( (string) ( $settings[ $field ] ?? '' ) ) ) {
                return [ 'ok' => false, 'messages' => [ 'La conexion GitHub esta incompleta. Falta: ' . $field . '.' ] ];
            }
        }

        $workflow = seo_github_python_runner_api_request( 'GET', seo_github_python_runner_api_url( true, false ) );
        if ( is_wp_error( $workflow ) ) {
            return [ 'ok' => false, 'messages' => [ $workflow->get_error_message() ] ];
        }

        $name = sanitize_text_field( (string) ( $workflow['name'] ?? $settings['workflow_id'] ) );
        $state = sanitize_key( (string) ( $workflow['state'] ?? '' ) );
        $message = 'Conexion GitHub correcta. Workflow accesible: ' . $name . '.';
        if ( '' !== $state ) {
            $message .= ' Estado: ' . $state . '.';
        }

        return [ 'ok' => true, 'messages' => [ $message ] ];
    }
}

if ( ! function_exists( 'seo_github_python_runner_save_settings' ) ) {
    function seo_github_python_runner_save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para guardar esta conexion.', 'seo-system' ) );
        }
        check_admin_referer( 'seo_github_python_runner_save', 'seo_github_python_runner_nonce' );

        $current = seo_github_python_runner_settings();
        $owner = sanitize_text_field( wp_unslash( $_POST['owner'] ?? '' ) );
        $repo = sanitize_text_field( wp_unslash( $_POST['repo'] ?? '' ) );
        $workflow_id = sanitize_file_name( wp_unslash( $_POST['workflow_id'] ?? '' ) );
        $ref = sanitize_text_field( wp_unslash( $_POST['ref'] ?? '' ) );
        $token_raw = trim( (string) wp_unslash( $_POST['token'] ?? '' ) );
        $token = (string) ( $current['token'] ?? '' );

        if ( '' !== $token_raw ) {
            $token = sanitize_text_field( $token_raw );
        }

        if ( '' === trim( $owner ) || '' === trim( $repo ) || '' === trim( $workflow_id ) || '' === trim( $ref ) ) {
            wp_safe_redirect(
                add_query_arg(
                    [ 'page' => 'seo-import-export', 'seo_ie_tab' => 'conexiones-proveedores', 'github_python_error' => 'fields' ],
                    admin_url( 'admin.php' )
                )
            );
            exit;
        }

        update_option(
            'seo_github_python_runner_settings',
            [
                'enabled'     => ! empty( $_POST['enabled'] ) ? 1 : 0,
                'owner'       => $owner,
                'repo'        => $repo,
                'workflow_id' => $workflow_id,
                'ref'         => $ref,
                'token'       => $token,
            ],
            false
        );

        wp_safe_redirect(
            add_query_arg(
                [ 'page' => 'seo-import-export', 'seo_ie_tab' => 'conexiones-proveedores', 'github_python_saved' => '1' ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }
}
add_action( 'admin_post_seo_github_python_runner_save', 'seo_github_python_runner_save_settings' );

if ( ! function_exists( 'seo_github_python_runner_notice_key' ) ) {
    function seo_github_python_runner_notice_key() {
        return 'seo_github_python_runner_notice_' . get_current_user_id();
    }
}

if ( ! function_exists( 'seo_github_python_runner_set_notice' ) ) {
    function seo_github_python_runner_set_notice( $type, $message ) {
        set_transient(
            seo_github_python_runner_notice_key(),
            [ 'type' => sanitize_key( $type ), 'message' => sanitize_text_field( (string) $message ) ],
            5 * MINUTE_IN_SECONDS
        );
    }
}

if ( ! function_exists( 'seo_github_python_runner_get_notice' ) ) {
    function seo_github_python_runner_get_notice() {
        $key = seo_github_python_runner_notice_key();
        $notice = get_transient( $key );
        delete_transient( $key );
        return is_array( $notice ) ? $notice : [];
    }
}

if ( ! function_exists( 'seo_github_python_runner_statuses' ) ) {
    function seo_github_python_runner_statuses() {
        $statuses = get_option( 'seo_github_python_runner_statuses', [] );
        return is_array( $statuses ) ? $statuses : [];
    }
}

if ( ! function_exists( 'seo_github_python_runner_update_status' ) ) {
    function seo_github_python_runner_update_status( $remote_run_id, array $data ) {
        $remote_run_id = sanitize_text_field( (string) $remote_run_id );
        if ( '' === $remote_run_id ) {
            return;
        }

        $statuses = seo_github_python_runner_statuses();
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
        update_option( 'seo_github_python_runner_statuses', $statuses, false );
    }
}

if ( ! function_exists( 'seo_github_python_runner_start' ) ) {
    /**
     * Inicia un workflow externo.
     *
     * El tercer parametro es opcional para mantener compatibilidad con los
     * scrapers web existentes. VEVOR lo usa para enviar el CSV preparado.
     *
     * @param string $recipe_id ID de la receta de importacion.
     * @param bool   $catalog_complete Catalogo completo.
     * @param array  $options Estado adicional del CSV preparado/V2.
     * @return array|WP_Error
     */
    function seo_github_python_runner_start( $recipe_id, $catalog_complete = false, array $options = [] ) {
        if ( ! function_exists( 'seo_proveedores_obtener_receta' ) ) {
            return new WP_Error( 'seo_github_python_importer_missing', 'El importador comun de proveedores no esta disponible.' );
        }

        $settings = seo_github_python_runner_settings();
        if ( empty( $settings['enabled'] ) ) {
            return new WP_Error( 'seo_github_python_disabled', 'GitHub Actions Python Runner esta desactivado.' );
        }

        foreach ( [ 'owner', 'repo', 'workflow_id', 'ref', 'token' ] as $field ) {
            if ( '' === trim( (string) ( $settings[ $field ] ?? '' ) ) ) {
                return new WP_Error( 'seo_github_python_config', 'La conexion GitHub Actions esta incompleta.' );
            }
        }

        $recipe_id = sanitize_key( $recipe_id );
        $recipe = seo_proveedores_obtener_receta( $recipe_id );
        if ( ! is_array( $recipe ) ) {
            return new WP_Error( 'seo_github_python_recipe_missing', 'La receta de proveedor seleccionada no existe.' );
        }

        $provider       = sanitize_text_field( (string) ( $recipe['provider'] ?? $recipe_id ) );
        $remote_run_id  = wp_generate_uuid4();
        $callback_token = wp_generate_password( 64, false, false );
        $source_url     = esc_url_raw( (string) ( $options['source_url'] ?? '' ) );

        $run_state = [
            'remote_run_id'       => $remote_run_id,
            'recipe_id'           => $recipe_id,
            'provider'            => $provider,
            'catalog_complete'    => $catalog_complete ? 1 : 0,
            'callback_token'      => $callback_token,
            'source_url'          => $source_url,
            'source_name'         => sanitize_file_name( (string) ( $options['source_name'] ?? '' ) ),
            'prepared_rows'       => absint( $options['prepared_rows'] ?? 0 ),
            'v2_auto_apply'       => array_key_exists( 'v2_auto_apply', $options ) ? ( ! empty( $options['v2_auto_apply'] ) ? 1 : 0 ) : 1,
            'v2_auto_bajas'       => ! empty( $options['v2_auto_bajas'] ) ? 1 : 0,
            'v2_image_mode'       => in_array( sanitize_key( (string) ( $options['v2_image_mode'] ?? 'external' ) ), [ 'external', 'local' ], true )
                ? sanitize_key( (string) ( $options['v2_image_mode'] ?? 'external' ) )
                : 'external',
            'v2_force_image_mode' => ! empty( $options['v2_force_image_mode'] ) ? 1 : 0,
            'started_at'          => time(),
        ];
        set_transient( 'seo_github_python_run_' . md5( $callback_token ), $run_state, 3 * DAY_IN_SECONDS );

        $payload = [
            'ref'    => (string) $settings['ref'],
            'inputs' => [
                'recipe_id'        => $recipe_id,
                'provider'         => $provider,
                'catalog_complete' => $catalog_complete ? '1' : '0',
                'remote_run_id'    => $remote_run_id,
                'callback_url'     => rest_url( 'seo-taxonomy/v1/supplier-runner/github-callback' ),
                'callback_token'   => $callback_token,
                'source_url'       => $source_url,
            ],
        ];

        $response = seo_github_python_runner_api_request( 'POST', seo_github_python_runner_api_url( true, true ), $payload );
        if ( is_wp_error( $response ) ) {
            delete_transient( 'seo_github_python_run_' . md5( $callback_token ) );
            return $response;
        }

        $github_run_id = absint( $response['workflow_run_id'] ?? 0 );
        $html_url = esc_url_raw( (string) ( $response['html_url'] ?? '' ) );

        seo_github_python_runner_update_status(
            $remote_run_id,
            [
                'recipe_id'        => $recipe_id,
                'provider'         => $provider,
                'catalog_complete' => $catalog_complete ? 1 : 0,
                'status'           => 'starting',
                'message'          => $source_url
                    ? 'CSV preparado enviado a GitHub Actions para enriquecimiento Python.'
                    : 'Workflow de GitHub Actions solicitado desde WordPress.',
                'github_run_id'    => $github_run_id,
                'github_run_url'   => $html_url,
            ]
        );

        return [
            'remote_run_id'  => $remote_run_id,
            'github_run_id'  => $github_run_id,
            'github_run_url' => $html_url,
        ];
    }
}

if ( ! function_exists( 'seo_github_python_runner_start_prepared' ) ) {
    /**
     * Envia a GitHub un CSV estandar ya preparado por una receta.
     */
    function seo_github_python_runner_start_prepared( array $prepared_state, array $preparation_log = [] ) {
        $recipe_id = sanitize_key( (string) ( $prepared_state['recipe_id'] ?? '' ) );
        $source_url = esc_url_raw( (string) ( $prepared_state['url'] ?? '' ) );

        if ( '' === $recipe_id || '' === $source_url ) {
            return new WP_Error( 'seo_github_python_prepared_missing', 'Falta la receta o la URL del CSV preparado.' );
        }

        return seo_github_python_runner_start(
            $recipe_id,
            ! empty( $prepared_state['v2_catalog_complete'] ),
            [
                'source_url'          => $source_url,
                'source_name'         => (string) ( $prepared_state['filename'] ?? '' ),
                'prepared_rows'       => absint( $preparation_log['preparados'] ?? $prepared_state['rows_total'] ?? 0 ),
                'v2_auto_apply'       => ! empty( $prepared_state['v2_auto_apply'] ),
                'v2_auto_bajas'       => ! empty( $prepared_state['v2_auto_bajas'] ),
                'v2_image_mode'       => (string) ( $prepared_state['v2_image_mode'] ?? 'external' ),
                'v2_force_image_mode' => ! empty( $prepared_state['v2_force_image_mode'] ),
            ]
        );
    }
}

if ( ! function_exists( 'seo_github_python_runner_handle_launch' ) ) {
    function seo_github_python_runner_handle_launch() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para iniciar el scraper Python.', 'seo-system' ) );
        }
        check_admin_referer( 'seo_github_python_runner_launch', 'seo_github_python_runner_launch_nonce' );

        $recipe_id = sanitize_key( wp_unslash( $_POST['recipe_id'] ?? '' ) );
        $catalog_complete = ! empty( $_POST['catalog_complete'] );
        $result = seo_github_python_runner_start( $recipe_id, $catalog_complete );

        if ( is_wp_error( $result ) ) {
            seo_github_python_runner_set_notice( 'error', $result->get_error_message() );
        } else {
            $message = 'Workflow Python iniciado. ID remoto: ' . (string) $result['remote_run_id'];
            if ( ! empty( $result['github_run_id'] ) ) {
                $message .= ' · GitHub run: ' . absint( $result['github_run_id'] );
            }
            seo_github_python_runner_set_notice( 'success', $message );
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
add_action( 'admin_post_seo_github_python_runner_launch', 'seo_github_python_runner_handle_launch' );

if ( ! function_exists( 'seo_github_python_runner_authorize_callback' ) ) {
    function seo_github_python_runner_authorize_callback( WP_REST_Request $request ) {
        $authorization = trim( (string) $request->get_header( 'authorization' ) );
        if ( ! preg_match( '/^Bearer\s+(.+)$/i', $authorization, $match ) ) {
            return new WP_Error( 'seo_github_python_callback_auth', 'Falta autenticacion del runner.', [ 'status' => 401 ] );
        }

        $token = trim( (string) $match[1] );
        if ( '' === $token ) {
            return new WP_Error( 'seo_github_python_callback_auth', 'Token de callback vacio.', [ 'status' => 401 ] );
        }

        $state = get_transient( 'seo_github_python_run_' . md5( $token ) );
        if ( ! is_array( $state ) || empty( $state['callback_token'] ) || ! hash_equals( (string) $state['callback_token'], $token ) ) {
            return new WP_Error( 'seo_github_python_callback_auth', 'Token de callback no valido o caducado.', [ 'status' => 403 ] );
        }
        return $state;
    }
}

if ( ! function_exists( 'seo_github_python_runner_queue_import' ) ) {
    function seo_github_python_runner_queue_import( $remote_run_id, array $state ) {
        $queue_key = 'seo_github_python_import_' . md5( (string) $remote_run_id );
        set_transient( $queue_key, $state, 2 * DAY_IN_SECONDS );

        $args = [ (string) $remote_run_id ];
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( 'seo_github_python_runner_import_csv', $args, 'seo-github-python', true );
            return true;
        }
        if ( function_exists( 'wp_schedule_single_event' ) ) {
            $scheduled = wp_schedule_single_event( time() + 5, 'seo_github_python_runner_import_csv', $args );
            if ( false !== $scheduled ) {
                return true;
            }
        }

        seo_github_python_runner_process_import( (string) $remote_run_id );
        return true;
    }
}

if ( ! function_exists( 'seo_github_python_runner_process_import' ) ) {
    function seo_github_python_runner_process_import( $remote_run_id ) {
        $remote_run_id = sanitize_text_field( (string) $remote_run_id );
        $queue_key = 'seo_github_python_import_' . md5( $remote_run_id );
        $queued = get_transient( $queue_key );
        if ( ! is_array( $queued ) ) {
            seo_github_python_runner_update_status( $remote_run_id, [ 'status' => 'error', 'message' => 'No se encontro el estado de importacion del CSV recibido.' ] );
            return;
        }

        if ( ! function_exists( 'seo_proveedores_analizar_csv' ) || ! function_exists( 'seo_proveedores_importar_csv_estandar' ) || ! function_exists( 'seo_proveedores_campos_importacion' ) ) {
            seo_github_python_runner_update_status( $remote_run_id, [ 'status' => 'error', 'message' => 'El importador comun de proveedores no esta disponible.' ] );
            return;
        }

        $path = (string) ( $queued['path'] ?? '' );
        $analysis = seo_proveedores_analizar_csv( $path );
        if ( is_wp_error( $analysis ) ) {
            seo_github_python_runner_update_status( $remote_run_id, [ 'status' => 'error', 'message' => $analysis->get_error_message() ] );
            return;
        }

        $required = [];
        foreach ( (array) seo_proveedores_campos_importacion() as $field => $definition ) {
            if ( ! empty( $definition['required'] ) ) {
                $required[] = $field;
            }
        }
        foreach ( $required as $required_field ) {
            if ( ! in_array( $required_field, (array) ( $analysis['header'] ?? [] ), true ) ) {
                seo_github_python_runner_update_status( $remote_run_id, [ 'status' => 'error', 'message' => 'El CSV Python no contiene el campo obligatorio: ' . $required_field ] );
                return;
            }
        }

        $state = array_merge(
            $analysis,
            [
                'recipe_id'           => sanitize_key( (string) ( $queued['recipe_id'] ?? '' ) ),
                'recipe_label'        => sanitize_text_field( (string) ( $queued['recipe_label'] ?? '' ) ),
                'recipe_version'      => sanitize_text_field( (string) ( $queued['recipe_version'] ?? '' ) ),
                'proveedor'           => sanitize_text_field( (string) ( $queued['provider'] ?? '' ) ),
                'filename'            => sanitize_file_name( (string) ( $queued['filename'] ?? basename( $path ) ) ),
                'path'                => wp_normalize_path( $path ),
                'url'                 => esc_url_raw( (string) ( $queued['url'] ?? '' ) ),
                'original_filename'   => sanitize_file_name( (string) ( $queued['source_name'] ?? '' ) ),
                'original_path'       => '',
                'created'             => time(),
                'automatic_source'    => true,
                'v2_source'           => 'github_python',
                'v2_catalog_complete' => ! empty( $queued['catalog_complete'] ),
                'v2_auto_apply'       => array_key_exists( 'v2_auto_apply', $queued ) ? ! empty( $queued['v2_auto_apply'] ) : true,
                'v2_auto_bajas'       => ! empty( $queued['v2_auto_bajas'] ),
                'v2_image_mode'       => in_array( sanitize_key( (string) ( $queued['v2_image_mode'] ?? 'external' ) ), [ 'external', 'local' ], true )
                    ? sanitize_key( (string) ( $queued['v2_image_mode'] ?? 'external' ) )
                    : 'external',
                'v2_force_image_mode' => ! empty( $queued['v2_force_image_mode'] ),
            ]
        );

        seo_github_python_runner_update_status(
            $remote_run_id,
            [ 'status' => 'importing', 'message' => 'CSV recibido; importador comun en ejecucion.', 'rows_total' => absint( $analysis['rows_total'] ?? 0 ) ]
        );

        $log = seo_proveedores_importar_csv_estandar(
            $state,
            [
                'preparados' => absint( $queued['prepared_rows'] ?? $analysis['rows_total'] ?? 0 ),
                'omitidos'   => 0,
                'errores'    => 0,
                'detalles'   => [],
            ]
        );

        if ( is_wp_error( $log ) ) {
            seo_github_python_runner_update_status( $remote_run_id, [ 'status' => 'error', 'message' => $log->get_error_message() ] );
            return;
        }

        delete_transient( $queue_key );
        seo_github_python_runner_update_status(
            $remote_run_id,
            [
                'status'       => 'completed',
                'message'      => 'CSV de GitHub importado y entregado a el importador comun.',
                'procesados'   => absint( $log['procesados'] ?? 0 ),
                'creados'      => absint( $log['creados'] ?? 0 ),
                'actualizados' => absint( $log['actualizados'] ?? 0 ),
                'sin_cambios'  => absint( $log['sin_cambios'] ?? 0 ),
                'errores'      => absint( $log['errores'] ?? 0 ),
            ]
        );
    }
}
add_action( 'seo_github_python_runner_import_csv', 'seo_github_python_runner_process_import', 10, 1 );

if ( ! function_exists( 'seo_github_python_runner_callback' ) ) {
    function seo_github_python_runner_callback( WP_REST_Request $request ) {
        $run = seo_github_python_runner_authorize_callback( $request );
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

        foreach ( [ 'categories_done', 'categories_total', 'urls_found', 'products_done', 'products_total', 'images_found', 'rows_enriched', 'rows_without_pattern', 'errors' ] as $metric ) {
            $value = $request->get_param( $metric );
            if ( null !== $value && '' !== $value ) {
                $progress[ $metric ] = absint( $value );
            }
        }
        seo_github_python_runner_update_status( $remote_run_id, $progress );

        if ( 'error' === $status ) {
            delete_transient( 'seo_github_python_run_' . md5( (string) $run['callback_token'] ) );
            return rest_ensure_response( [ 'ok' => true, 'accepted' => 'error' ] );
        }

        if ( 'completed' !== $status ) {
            return rest_ensure_response( [ 'ok' => true, 'accepted' => $status ] );
        }

        $files = $request->get_file_params();
        $file = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : [];
        if ( empty( $file['tmp_name'] ) || ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
            return new WP_Error( 'seo_github_python_callback_file', 'El runner ha marcado completed pero no ha enviado un CSV valido.', [ 'status' => 400 ] );
        }

        $source_name = sanitize_file_name( (string) ( $file['name'] ?? 'catalogo.csv' ) );
        if ( 'csv' !== strtolower( pathinfo( $source_name, PATHINFO_EXTENSION ) ) ) {
            return new WP_Error( 'seo_github_python_callback_extension', 'Solo se admite el CSV estandar de proveedores.', [ 'status' => 400 ] );
        }

        $recipe_id = sanitize_key( (string) ( $run['recipe_id'] ?? '' ) );
        $recipe = function_exists( 'seo_proveedores_obtener_receta' ) ? seo_proveedores_obtener_receta( $recipe_id ) : null;
        if ( ! is_array( $recipe ) ) {
            return new WP_Error( 'seo_github_python_callback_recipe', 'La receta asociada a la ejecucion ya no existe.', [ 'status' => 409 ] );
        }
        if ( ! function_exists( 'seo_proveedores_storage_receta' ) ) {
            return new WP_Error( 'seo_github_python_callback_storage', 'El almacenamiento del importador comun no esta disponible.', [ 'status' => 500 ] );
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
            return new WP_Error( 'seo_github_python_callback_store', 'No se pudo conservar el CSV recibido desde GitHub.', [ 'status' => 500 ] );
        }

        $queued = [
            'recipe_id'        => $recipe_id,
            'recipe_label'     => (string) ( $recipe['label'] ?? $recipe_id ) . ' - GitHub Python',
            'recipe_version'   => (string) ( $recipe['version'] ?? '' ),
            'provider'         => (string) ( $recipe['provider'] ?? $run['provider'] ?? '' ),
            'catalog_complete'    => ! empty( $run['catalog_complete'] ),
            'source_name'         => sanitize_file_name( (string) ( $run['source_name'] ?? $source_name ) ),
            'prepared_rows'       => absint( $run['prepared_rows'] ?? 0 ),
            'v2_auto_apply'       => array_key_exists( 'v2_auto_apply', $run ) ? ! empty( $run['v2_auto_apply'] ) : true,
            'v2_auto_bajas'       => ! empty( $run['v2_auto_bajas'] ),
            'v2_image_mode'       => (string) ( $run['v2_image_mode'] ?? 'external' ),
            'v2_force_image_mode' => ! empty( $run['v2_force_image_mode'] ),
            'filename'            => $stored_name,
            'path'                => wp_normalize_path( $stored_path ),
            'url'                 => trailingslashit( $storage['url'] ) . rawurlencode( $stored_name ),
        ];

        seo_github_python_runner_queue_import( $remote_run_id, $queued );
        seo_github_python_runner_update_status(
            $remote_run_id,
            [ 'status' => 'queued_import', 'message' => 'CSV recibido correctamente desde GitHub; importacion interna encolada.' ]
        );
        delete_transient( 'seo_github_python_run_' . md5( (string) $run['callback_token'] ) );

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

if ( ! function_exists( 'seo_github_python_runner_register_rest' ) ) {
    function seo_github_python_runner_register_rest() {
        register_rest_route(
            'seo-taxonomy/v1',
            '/supplier-runner/github-callback',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => 'seo_github_python_runner_callback',
                'permission_callback' => '__return_true',
            ]
        );
    }
}
add_action( 'rest_api_init', 'seo_github_python_runner_register_rest' );
