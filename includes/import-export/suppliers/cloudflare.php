<?php
/**
 * SEO System - Integracion Cloudflare.
 *
 * Gestiona una conexion Cloudflare de solo lectura para que otros modulos
 * (especialmente Estado del servidor > Seguridad) puedan reutilizarla.
 * Expone lectura de zona, ajustes SSL/TLS, DNSSEC y rulesets visibles.
 *
 * @package SEOSystem
 * @subpackage ImportExport
 * @version 1.1.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'seo_cloudflare_normalize_zone_name' ) ) {
    function seo_cloudflare_normalize_zone_name( $value ) {
        $value = strtolower( trim( sanitize_text_field( (string) $value ) ) );
        if ( preg_match( '#^https?://#i', $value ) ) {
            $host = wp_parse_url( $value, PHP_URL_HOST );
            $value = is_string( $host ) ? strtolower( $host ) : '';
        }
        $value = trim( $value, ". \t\n\r\0\x0B" );
        if ( '' === $value || strlen( $value ) > 253 || ! preg_match( '/^[a-z0-9.-]+$/', $value ) ) {
            return '';
        }
        return $value;
    }
}

if ( ! function_exists( 'seo_cloudflare_guess_zone_name' ) ) {
    function seo_cloudflare_guess_zone_name() {
        $host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        if ( 0 === strpos( $host, 'www.' ) ) {
            $host = substr( $host, 4 );
        }
        return seo_cloudflare_normalize_zone_name( $host );
    }
}

if ( ! function_exists( 'seo_cloudflare_crypto_key' ) ) {
    function seo_cloudflare_crypto_key() {
        return hash( 'sha256', wp_salt( 'auth' ) . '|seo-system-cloudflare|v1', true );
    }
}

if ( ! function_exists( 'seo_cloudflare_encrypt_token' ) ) {
    function seo_cloudflare_encrypt_token( $token ) {
        $token = trim( (string) $token );
        if ( '' === $token ) {
            return '';
        }
        if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'random_bytes' ) ) {
            return new WP_Error(
                'seo_cloudflare_crypto_unavailable',
                'PHP no dispone de OpenSSL/random_bytes. Define SEO_SYSTEM_CLOUDFLARE_API_TOKEN en wp-config.php en lugar de guardar el token en la base de datos.'
            );
        }

        try {
            $iv = random_bytes( 12 );
        } catch ( Exception $e ) {
            return new WP_Error( 'seo_cloudflare_crypto_random', 'No se pudo generar un vector aleatorio para proteger el token.' );
        }

        $tag = '';
        $ciphertext = openssl_encrypt(
            $token,
            'aes-256-gcm',
            seo_cloudflare_crypto_key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'seo-system-cloudflare-v1',
            16
        );

        if ( false === $ciphertext || 16 !== strlen( $tag ) ) {
            return new WP_Error( 'seo_cloudflare_crypto_failed', 'No se pudo cifrar el token de Cloudflare.' );
        }

        return 'v1:' . base64_encode( $iv . $tag . $ciphertext );
    }
}

if ( ! function_exists( 'seo_cloudflare_decrypt_token' ) ) {
    function seo_cloudflare_decrypt_token( $stored ) {
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
        $plain = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            seo_cloudflare_crypto_key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'seo-system-cloudflare-v1'
        );

        return is_string( $plain ) ? trim( $plain ) : '';
    }
}

if ( ! function_exists( 'seo_cloudflare_settings' ) ) {
    function seo_cloudflare_settings() {
        $defaults = [
            'enabled'          => 0,
            'zone_name'        => seo_cloudflare_guess_zone_name(),
            'zone_id'          => '',
            'zone_status'      => '',
            'zone_type'        => '',
            'account_id'       => '',
            'account_name'     => '',
            'token_cipher'     => '',
            'last_verified_at' => 0,
            'last_token_status'=> '',
        ];
        $saved = get_option( 'seo_cloudflare_settings', [] );
        return wp_parse_args( is_array( $saved ) ? $saved : [], $defaults );
    }
}

if ( ! function_exists( 'seo_cloudflare_api_token' ) ) {
    function seo_cloudflare_api_token() {
        if ( defined( 'SEO_SYSTEM_CLOUDFLARE_API_TOKEN' ) ) {
            $constant = trim( (string) SEO_SYSTEM_CLOUDFLARE_API_TOKEN );
            if ( '' !== $constant ) {
                return $constant;
            }
        }

        $settings = seo_cloudflare_settings();
        return seo_cloudflare_decrypt_token( (string) ( $settings['token_cipher'] ?? '' ) );
    }
}

if ( ! function_exists( 'seo_cloudflare_token_source' ) ) {
    function seo_cloudflare_token_source() {
        if ( defined( 'SEO_SYSTEM_CLOUDFLARE_API_TOKEN' ) && '' !== trim( (string) SEO_SYSTEM_CLOUDFLARE_API_TOKEN ) ) {
            return 'constant';
        }
        $settings = seo_cloudflare_settings();
        return '' !== trim( (string) ( $settings['token_cipher'] ?? '' ) ) ? 'database' : 'none';
    }
}

if ( ! function_exists( 'seo_cloudflare_connection_state' ) ) {
    function seo_cloudflare_connection_state() {
        $settings = seo_cloudflare_settings();
        $token_source = seo_cloudflare_token_source();
        return [
            'enabled'          => ! empty( $settings['enabled'] ),
            'configured'       => 'none' !== $token_source && '' !== trim( (string) ( $settings['zone_name'] ?? '' ) ),
            'token_source'     => $token_source,
            'zone_name'        => (string) ( $settings['zone_name'] ?? '' ),
            'zone_id'          => (string) ( $settings['zone_id'] ?? '' ),
            'zone_status'      => (string) ( $settings['zone_status'] ?? '' ),
            'zone_type'        => (string) ( $settings['zone_type'] ?? '' ),
            'account_id'       => (string) ( $settings['account_id'] ?? '' ),
            'account_name'     => (string) ( $settings['account_name'] ?? '' ),
            'last_verified_at' => absint( $settings['last_verified_at'] ?? 0 ),
            'last_token_status'=> sanitize_key( $settings['last_token_status'] ?? '' ),
        ];
    }
}

if ( ! function_exists( 'seo_cloudflare_api_request' ) ) {
    function seo_cloudflare_api_request( $method, $path, $query = [] ) {
        $token = seo_cloudflare_api_token();
        if ( '' === $token ) {
            return new WP_Error( 'seo_cloudflare_no_token', 'No hay un API Token de Cloudflare configurado.' );
        }

        $url = 'https://api.cloudflare.com/client/v4/' . ltrim( (string) $path, '/' );
        if ( ! empty( $query ) ) {
            $url = add_query_arg( $query, $url );
        }

        $response = wp_remote_request(
            esc_url_raw( $url ),
            [
                'method'      => strtoupper( (string) $method ),
                'timeout'     => 20,
                'redirection' => 0,
                'headers'     => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                    'User-Agent'    => 'SEO-System-Cloudflare/' . ( defined( 'SEO_SYSTEM_VERSION' ) ? SEO_SYSTEM_VERSION : '1.0' ),
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status  = (int) wp_remote_retrieve_response_code( $response );
        $decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        $success = is_array( $decoded ) && ! empty( $decoded['success'] );

        if ( $status < 200 || $status >= 300 || ! $success ) {
            $message = 'Cloudflare API ha respondido HTTP ' . $status . '.';
            if ( is_array( $decoded ) && ! empty( $decoded['errors'][0]['message'] ) ) {
                $message .= ' ' . sanitize_text_field( (string) $decoded['errors'][0]['message'] );
            }
            return new WP_Error( 'seo_cloudflare_api_error', $message, [ 'status' => $status ] );
        }

        return $decoded;
    }
}

if ( ! function_exists( 'seo_cloudflare_verify_token' ) ) {
    function seo_cloudflare_verify_token() {
        $response = seo_cloudflare_api_request( 'GET', 'user/tokens/verify' );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $result = is_array( $response['result'] ?? null ) ? $response['result'] : [];
        $status = sanitize_key( (string) ( $result['status'] ?? '' ) );
        if ( 'active' !== $status ) {
            return new WP_Error( 'seo_cloudflare_token_inactive', 'El API Token de Cloudflare no esta activo. Estado: ' . ( $status ?: 'desconocido' ) . '.' );
        }
        return $result;
    }
}

if ( ! function_exists( 'seo_cloudflare_find_zone' ) ) {
    function seo_cloudflare_find_zone( $zone_name = '' ) {
        $zone_name = seo_cloudflare_normalize_zone_name( $zone_name ?: ( seo_cloudflare_settings()['zone_name'] ?? '' ) );
        if ( '' === $zone_name ) {
            return new WP_Error( 'seo_cloudflare_zone_missing', 'Indica una zona valida de Cloudflare.' );
        }

        $response = seo_cloudflare_api_request(
            'GET',
            'zones',
            [
                'name'     => $zone_name,
                'per_page' => 1,
            ]
        );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $zones = is_array( $response['result'] ?? null ) ? $response['result'] : [];
        if ( empty( $zones[0] ) || ! is_array( $zones[0] ) ) {
            return new WP_Error(
                'seo_cloudflare_zone_not_found',
                'El token es valido, pero no se encontro la zona ' . $zone_name . '. Comprueba el dominio y que el token tenga Zone Read sobre esa zona.'
            );
        }

        return $zones[0];
    }
}

if ( ! function_exists( 'seo_cloudflare_test_connection' ) ) {
    function seo_cloudflare_test_connection() {
        $result = [ 'ok' => false, 'messages' => [] ];

        $verified = seo_cloudflare_verify_token();
        if ( is_wp_error( $verified ) ) {
            $result['messages'][] = $verified->get_error_message();
            return $result;
        }
        $result['messages'][] = 'API Token de Cloudflare valido y activo.';

        $settings = seo_cloudflare_settings();
        $zone = seo_cloudflare_find_zone( (string) ( $settings['zone_name'] ?? '' ) );
        if ( is_wp_error( $zone ) ) {
            $result['messages'][] = $zone->get_error_message();
            return $result;
        }

        $settings['zone_name']         = sanitize_text_field( (string) ( $zone['name'] ?? $settings['zone_name'] ) );
        $settings['zone_id']           = sanitize_text_field( (string) ( $zone['id'] ?? '' ) );
        $settings['zone_status']       = sanitize_key( (string) ( $zone['status'] ?? '' ) );
        $settings['zone_type']         = sanitize_key( (string) ( $zone['type'] ?? '' ) );
        $settings['account_id']        = sanitize_text_field( (string) ( $zone['account']['id'] ?? '' ) );
        $settings['account_name']      = sanitize_text_field( (string) ( $zone['account']['name'] ?? '' ) );
        $settings['last_verified_at']  = time();
        $settings['last_token_status'] = sanitize_key( (string) ( $verified['status'] ?? 'active' ) );
        update_option( 'seo_cloudflare_settings', $settings, false );
        delete_transient( 'seo_cloudflare_security_audit_v1' );

        $result['ok'] = true;
        $result['messages'][] = 'Zona accesible: ' . $settings['zone_name'] . ' (' . ( $settings['zone_status'] ?: 'estado desconocido' ) . ').';
        $result['zone'] = [
            'id'           => $settings['zone_id'],
            'name'         => $settings['zone_name'],
            'status'       => $settings['zone_status'],
            'type'         => $settings['zone_type'],
            'account_id'   => $settings['account_id'],
            'account_name' => $settings['account_name'],
        ];
        return $result;
    }
}


if ( ! function_exists( 'seo_cloudflare_refresh_zone_metadata' ) ) {
    /**
     * Refresca los metadatos publicos de la zona usando solo operaciones GET.
     * No modifica ninguna configuracion de Cloudflare.
     */
    function seo_cloudflare_refresh_zone_metadata() {
        $settings = seo_cloudflare_settings();
        $zone = seo_cloudflare_find_zone( (string) ( $settings['zone_name'] ?? '' ) );
        if ( is_wp_error( $zone ) ) {
            return $zone;
        }

        $settings['zone_name']         = sanitize_text_field( (string) ( $zone['name'] ?? $settings['zone_name'] ) );
        $settings['zone_id']           = sanitize_text_field( (string) ( $zone['id'] ?? '' ) );
        $settings['zone_status']       = sanitize_key( (string) ( $zone['status'] ?? '' ) );
        $settings['zone_type']         = sanitize_key( (string) ( $zone['type'] ?? '' ) );
        $settings['account_id']        = sanitize_text_field( (string) ( $zone['account']['id'] ?? '' ) );
        $settings['account_name']      = sanitize_text_field( (string) ( $zone['account']['name'] ?? '' ) );
        $settings['last_verified_at']  = time();
        $settings['last_token_status'] = 'active';
        update_option( 'seo_cloudflare_settings', $settings, false );

        return [
            'id'           => $settings['zone_id'],
            'name'         => $settings['zone_name'],
            'status'       => $settings['zone_status'],
            'type'         => $settings['zone_type'],
            'account_id'   => $settings['account_id'],
            'account_name' => $settings['account_name'],
        ];
    }
}

if ( ! function_exists( 'seo_cloudflare_get_zone_setting' ) ) {
    /**
     * Lee un ajuste individual de zona. Cloudflare recomienda actualmente
     * este endpoint frente al listado global de settings, que esta obsoleto.
     */
    function seo_cloudflare_get_zone_setting( $zone_id, $setting_id ) {
        $zone_id = trim( sanitize_text_field( (string) $zone_id ) );
        $setting_id = sanitize_key( (string) $setting_id );
        if ( '' === $zone_id || '' === $setting_id ) {
            return new WP_Error( 'seo_cloudflare_setting_args', 'Falta zone_id o setting_id para consultar Cloudflare.' );
        }

        $response = seo_cloudflare_api_request( 'GET', 'zones/' . rawurlencode( $zone_id ) . '/settings/' . rawurlencode( $setting_id ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $result = $response['result'] ?? null;
        if ( ! is_array( $result ) ) {
            return new WP_Error( 'seo_cloudflare_setting_invalid', 'Cloudflare no devolvio un ajuste interpretable para ' . $setting_id . '.' );
        }
        return $result;
    }
}

if ( ! function_exists( 'seo_cloudflare_get_dnssec' ) ) {
    /**
     * Devuelve el estado DNSSEC de la zona mediante GET /zones/{zone_id}/dnssec.
     */
    function seo_cloudflare_get_dnssec( $zone_id ) {
        $zone_id = trim( sanitize_text_field( (string) $zone_id ) );
        if ( '' === $zone_id ) {
            return new WP_Error( 'seo_cloudflare_dnssec_zone', 'Falta zone_id para consultar DNSSEC.' );
        }
        $response = seo_cloudflare_api_request( 'GET', 'zones/' . rawurlencode( $zone_id ) . '/dnssec' );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $result = $response['result'] ?? null;
        return is_array( $result ) ? $result : new WP_Error( 'seo_cloudflare_dnssec_invalid', 'Cloudflare no devolvio un estado DNSSEC interpretable.' );
    }
}

if ( ! function_exists( 'seo_cloudflare_get_security_rulesets' ) ) {
    /**
     * Inventario ligero de rulesets de seguridad visibles para el token.
     * No lee reglas completas ni realiza cambios.
     */
    function seo_cloudflare_get_security_rulesets( $zone_id ) {
        $zone_id = trim( sanitize_text_field( (string) $zone_id ) );
        if ( '' === $zone_id ) {
            return new WP_Error( 'seo_cloudflare_rulesets_zone', 'Falta zone_id para consultar rulesets.' );
        }
        $response = seo_cloudflare_api_request(
            'GET',
            'zones/' . rawurlencode( $zone_id ) . '/rulesets',
            [ 'per_page' => 50 ]
        );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $rows = is_array( $response['result'] ?? null ) ? $response['result'] : [];
        $summary = [
            'total'          => count( $rows ),
            'managed_waf'    => 0,
            'custom_firewall'=> 0,
            'rate_limit'     => 0,
            'bot_phase'      => 0,
        ];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $phase = sanitize_key( (string) ( $row['phase'] ?? '' ) );
            if ( 'http_request_firewall_managed' === $phase ) {
                $summary['managed_waf']++;
            } elseif ( 'http_request_firewall_custom' === $phase ) {
                $summary['custom_firewall']++;
            } elseif ( 'http_ratelimit' === $phase ) {
                $summary['rate_limit']++;
            } elseif ( 'http_request_sbfm' === $phase ) {
                $summary['bot_phase']++;
            }
        }
        return $summary;
    }
}

if ( ! function_exists( 'seo_cloudflare_security_audit' ) ) {
    /**
     * Snapshot de seguridad Cloudflare de solo lectura.
     *
     * Se cachea para evitar repetir llamadas al API al navegar por el panel.
     * Un chequeo completo de Server Status puede forzar el refresco.
     */
    function seo_cloudflare_security_audit( $refresh = false ) {
        $cache_key = 'seo_cloudflare_security_audit_v1';
        if ( ! $refresh ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) && ! empty( $cached['generated_at'] ) ) {
                return $cached;
            }
        }

        $state = seo_cloudflare_connection_state();
        $audit = [
            'generated_at' => time(),
            'enabled'      => ! empty( $state['enabled'] ),
            'configured'   => ! empty( $state['configured'] ),
            'zone'         => [],
            'settings'     => [],
            'dnssec'       => [ 'available' => false ],
            'rulesets'     => [ 'available' => false ],
            'error'        => '',
        ];

        if ( empty( $state['enabled'] ) || empty( $state['configured'] ) ) {
            set_transient( $cache_key, $audit, 15 * MINUTE_IN_SECONDS );
            return $audit;
        }

        $zone = seo_cloudflare_refresh_zone_metadata();
        if ( is_wp_error( $zone ) ) {
            $audit['error'] = $zone->get_error_message();
            set_transient( $cache_key, $audit, 5 * MINUTE_IN_SECONDS );
            return $audit;
        }
        $audit['zone'] = $zone;
        $zone_id = (string) ( $zone['id'] ?? '' );

        $setting_ids = [
            'ssl',
            'always_use_https',
            'min_tls_version',
            'tls_1_3',
            'browser_check',
            'security_level',
        ];
        foreach ( $setting_ids as $setting_id ) {
            $setting = seo_cloudflare_get_zone_setting( $zone_id, $setting_id );
            if ( is_wp_error( $setting ) ) {
                $audit['settings'][ $setting_id ] = [
                    'available' => false,
                    'error'     => $setting->get_error_message(),
                ];
                continue;
            }
            $audit['settings'][ $setting_id ] = [
                'available'   => true,
                'value'       => $setting['value'] ?? '',
                'editable'    => isset( $setting['editable'] ) ? (bool) $setting['editable'] : null,
                'modified_on' => sanitize_text_field( (string) ( $setting['modified_on'] ?? '' ) ),
            ];
        }

        $dnssec = seo_cloudflare_get_dnssec( $zone_id );
        if ( is_wp_error( $dnssec ) ) {
            $audit['dnssec'] = [ 'available' => false, 'error' => $dnssec->get_error_message() ];
        } else {
            $audit['dnssec'] = [
                'available' => true,
                'status'    => sanitize_key( (string) ( $dnssec['status'] ?? '' ) ),
                'flags'     => absint( $dnssec['flags'] ?? 0 ),
                'algorithm' => sanitize_text_field( (string) ( $dnssec['algorithm'] ?? '' ) ),
            ];
        }

        $rulesets = seo_cloudflare_get_security_rulesets( $zone_id );
        if ( is_wp_error( $rulesets ) ) {
            $audit['rulesets'] = [ 'available' => false, 'error' => $rulesets->get_error_message() ];
        } else {
            $audit['rulesets'] = array_merge( [ 'available' => true ], $rulesets );
        }

        set_transient( $cache_key, $audit, 15 * MINUTE_IN_SECONDS );
        return $audit;
    }
}

if ( ! function_exists( 'seo_cloudflare_save_settings' ) ) {
    function seo_cloudflare_save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para guardar esta conexion.', 'seo-system' ) );
        }
        check_admin_referer( 'seo_cloudflare_save', 'seo_cloudflare_nonce' );

        $current   = seo_cloudflare_settings();
        $zone_name = seo_cloudflare_normalize_zone_name( wp_unslash( $_POST['zone_name'] ?? '' ) );
        if ( '' === $zone_name ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'seo-import-export', 'seo_ie_tab' => 'conexiones-proveedores', 'cloudflare_error' => 'zone' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        $token_cipher = (string) ( $current['token_cipher'] ?? '' );
        $constant_token = defined( 'SEO_SYSTEM_CLOUDFLARE_API_TOKEN' ) && '' !== trim( (string) SEO_SYSTEM_CLOUDFLARE_API_TOKEN );

        if ( ! $constant_token && ! empty( $_POST['clear_token'] ) ) {
            $token_cipher = '';
        }

        if ( ! $constant_token ) {
            $token_raw = preg_replace( '/\s+/', '', (string) wp_unslash( $_POST['api_token'] ?? '' ) );
            if ( '' !== $token_raw ) {
                if ( strlen( $token_raw ) > 4096 ) {
                    wp_safe_redirect( add_query_arg( [ 'page' => 'seo-import-export', 'seo_ie_tab' => 'conexiones-proveedores', 'cloudflare_error' => 'token' ], admin_url( 'admin.php' ) ) );
                    exit;
                }
                $encrypted = seo_cloudflare_encrypt_token( $token_raw );
                if ( is_wp_error( $encrypted ) ) {
                    wp_safe_redirect( add_query_arg( [ 'page' => 'seo-import-export', 'seo_ie_tab' => 'conexiones-proveedores', 'cloudflare_error' => 'crypto' ], admin_url( 'admin.php' ) ) );
                    exit;
                }
                $token_cipher = $encrypted;
            }
        }

        $enabled = empty( $_POST['enabled'] ) ? 0 : 1;
        if ( $enabled && ! $constant_token && '' === trim( $token_cipher ) ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'seo-import-export', 'seo_ie_tab' => 'conexiones-proveedores', 'cloudflare_error' => 'token' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        $zone_changed = $zone_name !== (string) ( $current['zone_name'] ?? '' );
        $settings = [
            'enabled'           => $enabled,
            'zone_name'         => $zone_name,
            'zone_id'           => $zone_changed ? '' : sanitize_text_field( (string) ( $current['zone_id'] ?? '' ) ),
            'zone_status'       => $zone_changed ? '' : sanitize_key( (string) ( $current['zone_status'] ?? '' ) ),
            'zone_type'         => $zone_changed ? '' : sanitize_key( (string) ( $current['zone_type'] ?? '' ) ),
            'account_id'        => $zone_changed ? '' : sanitize_text_field( (string) ( $current['account_id'] ?? '' ) ),
            'account_name'      => $zone_changed ? '' : sanitize_text_field( (string) ( $current['account_name'] ?? '' ) ),
            'token_cipher'      => $constant_token ? '' : $token_cipher,
            'last_verified_at'  => $zone_changed ? 0 : absint( $current['last_verified_at'] ?? 0 ),
            'last_token_status' => $zone_changed ? '' : sanitize_key( (string) ( $current['last_token_status'] ?? '' ) ),
        ];
        update_option( 'seo_cloudflare_settings', $settings, false );
        delete_transient( 'seo_cloudflare_security_audit_v1' );

        wp_safe_redirect( add_query_arg( [ 'page' => 'seo-import-export', 'seo_ie_tab' => 'conexiones-proveedores', 'cloudflare_saved' => 1 ], admin_url( 'admin.php' ) ) );
        exit;
    }
    add_action( 'admin_post_seo_cloudflare_save', 'seo_cloudflare_save_settings' );
}
