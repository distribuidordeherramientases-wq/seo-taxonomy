<?php
/**
 * Shared Pinterest connection for TaxoCommerce.
 */

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Connections_Pinterest {

	const OPTION        = 'taxocommerce_connections_pinterest';
	const LEGACY_OPTION = 'seo_social_network_settings_v1';
	const API_BASE      = 'https://api.pinterest.com/v5';
	const SCOPES        = 'boards:read pins:read pins:write';

	public static function boot() {
		self::migrate_legacy_settings();
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_route' ) );
		add_action( 'admin_post_taxocommerce_connections_pinterest_oauth_start', array( __CLASS__, 'oauth_start' ) );
		add_action( 'wp_head', array( __CLASS__, 'domain_verify_head' ), 5 );
		self::register_legacy_helpers();
	}

	public static function defaults() {
		return array(
			'enabled'                  => 0,
			'app_id'                   => '',
			'app_secret_enc'           => '',
			'access_token_enc'         => '',
			'refresh_token_enc'        => '',
			'token_expires_at'         => 0,
			'refresh_token_expires_at' => 0,
			'scope'                    => self::SCOPES,
			'board_id'                 => '',
			'board_name'               => '',
			'boards'                   => array(),
			'account_username'         => '',
			'page_name'                => '',
			'page_link'                => '',
			'publish_mode'             => 'image',
			'domain_verify_enabled'    => 0,
			'domain_verify_code'       => '',
			'catalog_feed_url'         => '',
			'merchant_last_test_at'    => '',
			'merchant_last_test_ok'    => 0,
			'merchant_last_test_error' => '',
			'last_test_at'             => '',
			'last_test_ok'             => 0,
			'last_test_error'          => '',
		);
	}

	public static function migrate_legacy_settings() {
		if ( false !== get_option( self::OPTION, false ) ) {
			return;
		}
		$legacy = get_option( self::LEGACY_OPTION, array() );
		$provider = is_array( $legacy ) && isset( $legacy['providers']['pinterest'] ) && is_array( $legacy['providers']['pinterest'] )
			? $legacy['providers']['pinterest']
			: array();
		if ( $provider ) {
			update_option( self::OPTION, wp_parse_args( $provider, self::defaults() ), false );
		}
	}

	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );

		$legacy = get_option( self::LEGACY_OPTION, array() );
		$provider = is_array( $legacy ) && isset( $legacy['providers']['pinterest'] ) && is_array( $legacy['providers']['pinterest'] )
			? wp_parse_args( $legacy['providers']['pinterest'], self::defaults() )
			: array();
		if ( $provider && $provider !== $settings ) {
			$settings = $provider;
			update_option( self::OPTION, $settings, false );
		}
		return $settings;
	}

	private static function save_raw( $settings ) {
		$settings = wp_parse_args( is_array( $settings ) ? $settings : array(), self::defaults() );
		update_option( self::OPTION, $settings, false );

		$legacy = get_option( self::LEGACY_OPTION, array() );
		$legacy = is_array( $legacy ) ? $legacy : array();
		$legacy['providers'] = isset( $legacy['providers'] ) && is_array( $legacy['providers'] ) ? $legacy['providers'] : array();
		$legacy['providers']['pinterest'] = $settings;
		update_option( self::LEGACY_OPTION, $legacy, false );
		return $settings;
	}

	public static function save( $input ) {
		$current = self::settings();
		$input   = is_array( $input ) ? $input : array();

		$app_secret_enc = (string) ( $current['app_secret_enc'] ?? '' );
		$new_secret = trim( (string) ( $input['app_secret'] ?? '' ) );
		if ( '' !== $new_secret ) {
			$app_secret_enc = self::encrypt_secret( $new_secret );
			if ( is_wp_error( $app_secret_enc ) ) {
				return $app_secret_enc;
			}
		}

		$access_token_enc = (string) ( $current['access_token_enc'] ?? '' );
		$new_token = trim( (string) ( $input['access_token'] ?? '' ) );
		if ( '' !== $new_token ) {
			$access_token_enc = self::encrypt_secret( $new_token );
			if ( is_wp_error( $access_token_enc ) ) {
				return $access_token_enc;
			}
		}

		$board_id = preg_replace( '/[^0-9]/', '', (string) ( $input['board_id'] ?? ( $current['board_id'] ?? '' ) ) );
		$board_name = sanitize_text_field( (string) ( $current['board_name'] ?? '' ) );
		$account_username = sanitize_user( (string) ( $current['account_username'] ?? '' ), true );
		foreach ( (array) ( $current['boards'] ?? array() ) as $board ) {
			if ( is_array( $board ) && (string) ( $board['id'] ?? '' ) === $board_id ) {
				$board_name = sanitize_text_field( (string) ( $board['name'] ?? '' ) );
				$account_username = sanitize_user( (string) ( $board['owner_username'] ?? '' ), true );
				break;
			}
		}

		$current['app_id']           = sanitize_text_field( (string) ( $input['app_id'] ?? '' ) );
		$current['app_secret_enc']   = $app_secret_enc;
		$current['access_token_enc'] = $access_token_enc;
		$current['board_id']         = $board_id;
		$current['board_name']       = $board_name;
		$current['account_username'] = $account_username;
		$current['page_name']        = $board_name;
		$current['page_link']        = $account_username ? 'https://www.pinterest.com/' . rawurlencode( $account_username ) . '/' : '';
		$current['enabled']          = $board_id && $access_token_enc ? 1 : 0;
		$current['last_test_at']     = '';
		$current['last_test_ok']     = 0;
		$current['last_test_error']  = '';

		return self::save_raw( $current );
	}

	public static function encrypt_secret( $plain ) {
		$plain = trim( (string) $plain );
		if ( '' === $plain ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_random_pseudo_bytes' ) ) {
			return new WP_Error( 'openssl_missing', __( 'OpenSSL is not available.', 'taxocommerce-google-bing' ) );
		}
		$method = 'aes-256-gcm';
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv = openssl_random_pseudo_bytes( openssl_cipher_iv_length( $method ) );
		$tag = '';
		$cipher = openssl_encrypt( $plain, $method, $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( ! is_string( $cipher ) || '' === $cipher || '' === $tag ) {
			return new WP_Error( 'encrypt_failed', __( 'The secret could not be encrypted.', 'taxocommerce-google-bing' ) );
		}
		return 'enc1:' . base64_encode( $iv . $tag . $cipher );
	}

	public static function decrypt_secret( $encoded ) {
		$encoded = (string) $encoded;
		if ( 0 !== strpos( $encoded, 'enc1:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $encoded, 5 ), true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}
		$method = 'aes-256-gcm';
		$iv_length = openssl_cipher_iv_length( $method );
		$tag_length = 16;
		if ( strlen( $raw ) <= $iv_length + $tag_length ) {
			return '';
		}
		$iv = substr( $raw, 0, $iv_length );
		$tag = substr( $raw, $iv_length, $tag_length );
		$cipher = substr( $raw, $iv_length + $tag_length );
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$plain = openssl_decrypt( $cipher, $method, $key, OPENSSL_RAW_DATA, $iv, $tag );
		return is_string( $plain ) ? $plain : '';
	}

	public static function redirect_uri() {
		// Keep the historical callback stable so the Pinterest app does not need
		// reconfiguration when the old plugin is removed.
		return rest_url( 'seo-system/v1/pinterest/callback' );
	}

	private static function headers( $token ) {
		return array(
			'Authorization' => 'Bearer ' . $token,
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/json',
		);
	}

	private static function decode_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();
		if ( $code < 200 || $code >= 300 ) {
			$message = (string) ( $data['message'] ?? ( $data['error_description'] ?? ( $data['error'] ?? 'Pinterest HTTP ' . $code ) ) );
			return new WP_Error( 'pinterest_api_error', sanitize_text_field( $message ) );
		}
		return $data;
	}

	private static function apply_token_data( $config, $data ) {
		if ( empty( $data['access_token'] ) ) {
			return new WP_Error( 'pinterest_missing_access_token', __( 'Pinterest did not return an Access Token.', 'taxocommerce-google-bing' ) );
		}
		$access = self::encrypt_secret( (string) $data['access_token'] );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$config['access_token_enc'] = $access;
		$config['token_expires_at'] = ! empty( $data['expires_in'] ) ? time() + absint( $data['expires_in'] ) : 0;
		if ( ! empty( $data['refresh_token'] ) ) {
			$refresh = self::encrypt_secret( (string) $data['refresh_token'] );
			if ( is_wp_error( $refresh ) ) {
				return $refresh;
			}
			$config['refresh_token_enc'] = $refresh;
		}
		$config['refresh_token_expires_at'] = ! empty( $data['refresh_token_expires_at'] )
			? absint( $data['refresh_token_expires_at'] )
			: ( ! empty( $data['refresh_token_expires_in'] ) ? time() + absint( $data['refresh_token_expires_in'] ) : absint( $config['refresh_token_expires_at'] ?? 0 ) );
		if ( ! empty( $data['scope'] ) ) {
			$config['scope'] = sanitize_text_field( (string) $data['scope'] );
		}
		return $config;
	}

	public static function access_token( $config = null ) {
		$config = is_array( $config ) ? $config : self::settings();
		$token = self::decrypt_secret( $config['access_token_enc'] ?? '' );
		if ( '' === $token ) {
			return new WP_Error( 'pinterest_missing_token', __( 'Pinterest no tiene Access Token guardado.', 'taxocommerce-google-bing' ) );
		}
		$expires_at = absint( $config['token_expires_at'] ?? 0 );
		if ( 0 === $expires_at || $expires_at > time() + DAY_IN_SECONDS ) {
			return $token;
		}

		$refresh = self::decrypt_secret( $config['refresh_token_enc'] ?? '' );
		$secret  = self::decrypt_secret( $config['app_secret_enc'] ?? '' );
		$app_id  = trim( (string) ( $config['app_id'] ?? '' ) );
		if ( '' === $refresh || '' === $secret || '' === $app_id ) {
			return new WP_Error( 'pinterest_token_expired', __( 'The Pinterest token has expired; authorize the account again.', 'taxocommerce-google-bing' ) );
		}

		$response = wp_remote_post(
			self::API_BASE . '/oauth/token',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $app_id . ':' . $secret ),
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body' => array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh,
				),
			)
		);
		$data = self::decode_response( $response );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$updated = self::apply_token_data( $config, $data );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		self::save_raw( $updated );
		return self::decrypt_secret( $updated['access_token_enc'] );
	}

	public static function discover_boards( $token ) {
		$boards = array();
		$bookmark = '';
		$page = 0;
		do {
			$page++;
			$url = add_query_arg(
				array_filter(
					array( 'page_size' => 100, 'bookmark' => $bookmark ),
					static function ( $value ) { return '' !== $value && null !== $value; }
				),
				self::API_BASE . '/boards'
			);
			$data = self::decode_response(
				wp_remote_get( $url, array( 'timeout' => 20, 'headers' => self::headers( $token ) ) )
			);
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			foreach ( (array) ( $data['items'] ?? array() ) as $item ) {
				if ( ! is_array( $item ) || empty( $item['id'] ) ) {
					continue;
				}
				$privacy = strtoupper( (string) ( $item['privacy'] ?? '' ) );
				if ( $privacy && 'PUBLIC' !== $privacy ) {
					continue;
				}
				$boards[] = array(
					'id'             => preg_replace( '/[^0-9]/', '', (string) $item['id'] ),
					'name'           => sanitize_text_field( (string) ( $item['name'] ?? '' ) ),
					'privacy'        => sanitize_text_field( $privacy ),
					'owner_username' => sanitize_user( (string) ( $item['owner']['username'] ?? '' ), true ),
				);
			}
			$bookmark = sanitize_text_field( (string) ( $data['bookmark'] ?? '' ) );
		} while ( $bookmark && $page < 5 );
		return $boards;
	}

	public static function test_connection() {
		$config = self::settings();
		$board_id = preg_replace( '/[^0-9]/', '', (string) ( $config['board_id'] ?? '' ) );
		if ( '' === $board_id ) {
			return new WP_Error( 'pinterest_board_missing', __( 'Select a board before testing Pinterest.', 'taxocommerce-google-bing' ) );
		}
		$token = self::access_token( $config );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		return self::decode_response(
			wp_remote_get(
				self::API_BASE . '/boards/' . rawurlencode( $board_id ),
				array( 'timeout' => 20, 'headers' => self::headers( $token ) )
			)
		);
	}

	public static function oauth_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to connect Pinterest.', 'taxocommerce-google-bing' ) );
		}
		check_admin_referer( 'taxocommerce_connections_pinterest_oauth_start' );

		$config = self::settings();
		$app_id = trim( (string) ( $config['app_id'] ?? '' ) );
		$secret = self::decrypt_secret( $config['app_secret_enc'] ?? '' );
		if ( '' === $app_id || '' === $secret ) {
			wp_safe_redirect( TaxoCommerce_Connections_Admin::url( array( 'tc_conn_notice' => 'pinterest_app_missing' ) ) );
			exit;
		}

		$state = wp_generate_password( 48, false, false );
		set_transient(
			'seo_pi_state_' . substr( hash( 'sha256', $state ), 0, 32 ),
			array( 'user_id' => get_current_user_id(), 'created' => time() ),
			10 * MINUTE_IN_SECONDS
		);

		$url = add_query_arg(
			array(
				'client_id'     => $app_id,
				'redirect_uri'  => self::redirect_uri(),
				'response_type' => 'code',
				'scope'         => self::SCOPES,
				'state'         => $state,
			),
			'https://www.pinterest.com/oauth/'
		);
		wp_redirect( esc_url_raw( $url ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	public static function register_rest_route() {
		// The legacy plugin already owns this exact stable callback while both
		// plugins coexist. Do not replace its route registration.
		if ( function_exists( 'seo_social_pinterest_oauth_callback' ) ) {
			return;
		}
		register_rest_route(
			'seo-system/v1',
			'/pinterest/callback',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'oauth_callback' ),
				'permission_callback' => array( __CLASS__, 'oauth_permission' ),
			)
		);
	}

	public static function oauth_permission( WP_REST_Request $request ) {
		$state = sanitize_text_field( (string) $request->get_param( 'state' ) );
		$data = $state ? get_transient( 'seo_pi_state_' . substr( hash( 'sha256', $state ), 0, 32 ) ) : false;
		if ( ! is_array( $data ) || empty( $data['user_id'] ) ) {
			return new WP_Error( 'pinterest_oauth_state_invalid', __( 'The OAuth state is invalid or expired.', 'taxocommerce-google-bing' ), array( 'status' => 403 ) );
		}
		$user = get_user_by( 'id', absint( $data['user_id'] ) );
		return $user && user_can( $user, 'manage_options' ) ? true : new WP_Error( 'pinterest_oauth_user_forbidden', __( 'The OAuth user does not have permission.', 'taxocommerce-google-bing' ), array( 'status' => 403 ) );
	}

	public static function oauth_callback( WP_REST_Request $request ) {
		$state = sanitize_text_field( (string) $request->get_param( 'state' ) );
		$code  = sanitize_text_field( (string) $request->get_param( 'code' ) );
		$key   = $state ? 'seo_pi_state_' . substr( hash( 'sha256', $state ), 0, 32 ) : '';
		$data  = $key ? get_transient( $key ) : false;
		if ( $key ) {
			delete_transient( $key );
		}
		if ( ! is_array( $data ) || empty( $data['user_id'] ) || '' === $code ) {
			return new WP_REST_Response( array( 'error' => 'Invalid Pinterest OAuth response.' ), 400 );
		}

		$config = self::settings();
		$app_id = trim( (string) ( $config['app_id'] ?? '' ) );
		$secret = self::decrypt_secret( $config['app_secret_enc'] ?? '' );
		$response = wp_remote_post(
			self::API_BASE . '/oauth/token',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $app_id . ':' . $secret ),
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body' => array(
					'grant_type'   => 'authorization_code',
					'code'         => $code,
					'redirect_uri' => self::redirect_uri(),
				),
			)
		);
		$token_data = self::decode_response( $response );
		if ( is_wp_error( $token_data ) ) {
			return new WP_REST_Response( array( 'error' => $token_data->get_error_message() ), 400 );
		}

		$config = self::apply_token_data( $config, $token_data );
		if ( is_wp_error( $config ) ) {
			return new WP_REST_Response( array( 'error' => $config->get_error_message() ), 400 );
		}
		$boards = self::discover_boards( (string) $token_data['access_token'] );
		if ( is_wp_error( $boards ) ) {
			return new WP_REST_Response( array( 'error' => $boards->get_error_message() ), 400 );
		}
		$config['boards'] = $boards;
		if ( 1 === count( $boards ) ) {
			$config['board_id'] = (string) $boards[0]['id'];
			$config['board_name'] = (string) $boards[0]['name'];
			$config['account_username'] = (string) $boards[0]['owner_username'];
		}
		$config['enabled'] = ! empty( $config['board_id'] ) && ! empty( $config['access_token_enc'] ) ? 1 : 0;
		self::save_raw( $config );

		wp_safe_redirect( TaxoCommerce_Connections_Admin::url( array( 'tc_conn_notice' => 'pinterest_connected' ) ) );
		exit;
	}

	public static function extract_domain_verify( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( false !== stripos( $value, '<meta' ) ) {
			if ( preg_match( '/<meta\b[^>]*\bname\s*=\s*(["\'])p:domain_verify\1[^>]*\bcontent\s*=\s*(["\'])([^"\']+)\2[^>]*>/i', $value, $match ) ) {
				$value = $match[3];
			} elseif ( preg_match( '/<meta\b[^>]*\bcontent\s*=\s*(["\'])([^"\']+)\1[^>]*\bname\s*=\s*(["\'])p:domain_verify\3[^>]*>/i', $value, $match ) ) {
				$value = $match[2];
			} else {
				return '';
			}
		}
		$value = sanitize_text_field( $value );
		return preg_match( '/^[A-Za-z0-9._:-]{6,255}$/', $value ) ? $value : '';
	}

	public static function save_merchant( $input ) {
		$config = self::settings();
		$code = self::extract_domain_verify( (string) ( $input['domain_verify_code'] ?? '' ) );
		$enabled = ! empty( $input['domain_verify_enabled'] ) ? 1 : 0;
		if ( $enabled && '' === $code ) {
			return new WP_Error( 'pinterest_verify_code', __( 'Invalid domain verification code.', 'taxocommerce-google-bing' ) );
		}
		$config['domain_verify_enabled'] = $enabled;
		$config['domain_verify_code'] = $code;
		$config['catalog_feed_url'] = esc_url_raw( (string) ( $input['catalog_feed_url'] ?? '' ) );
		$config['merchant_last_test_at'] = '';
		$config['merchant_last_test_ok'] = 0;
		$config['merchant_last_test_error'] = '';
		return self::save_raw( $config );
	}

	public static function merchant_settings() {
		$s = self::settings();
		return array(
			'domain_verify_enabled'    => ! empty( $s['domain_verify_enabled'] ) ? 1 : 0,
			'domain_verify_code'       => self::extract_domain_verify( $s['domain_verify_code'] ?? '' ),
			'catalog_feed_url'         => esc_url_raw( (string) ( $s['catalog_feed_url'] ?? '' ) ),
			'merchant_last_test_at'    => sanitize_text_field( (string) ( $s['merchant_last_test_at'] ?? '' ) ),
			'merchant_last_test_ok'    => ! empty( $s['merchant_last_test_ok'] ) ? 1 : 0,
			'merchant_last_test_error' => sanitize_text_field( (string) ( $s['merchant_last_test_error'] ?? '' ) ),
		);
	}

	public static function domain_verify_head() {
		if ( is_admin() || function_exists( 'seo_social_pinterest_domain_verify_head' ) ) {
			return;
		}
		$m = self::merchant_settings();
		if ( empty( $m['domain_verify_enabled'] ) || '' === $m['domain_verify_code'] ) {
			return;
		}
		echo "\n<!-- TaxoCommerce Connections: Pinterest domain verification -->\n";
		echo '<meta name="p:domain_verify" content="' . esc_attr( $m['domain_verify_code'] ) . '">' . "\n";
	}

	public static function register_legacy_helpers() {
		if ( ! function_exists( 'seo_social_pinterest_merchant_settings' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy callable name is used by existing modules; changing it requires a coordinated migration.
			function seo_social_pinterest_merchant_settings() {
				return TaxoCommerce_Connections_Pinterest::merchant_settings();
			}
		}
		if ( ! function_exists( 'seo_social_pinterest_extract_domain_verify' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy callable name is used by existing modules; changing it requires a coordinated migration.
			function seo_social_pinterest_extract_domain_verify( $value ) {
				return TaxoCommerce_Connections_Pinterest::extract_domain_verify( $value );
			}
		}
		if ( ! function_exists( 'seo_social_pinterest_get_access_token' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy callable name is used by existing modules; changing it requires a coordinated migration.
			function seo_social_pinterest_get_access_token( $config = array() ) {
				return TaxoCommerce_Connections_Pinterest::access_token( $config );
			}
		}
		if ( ! function_exists( 'seo_social_pinterest_test_connection' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy callable name is used by existing modules; changing it requires a coordinated migration.
			function seo_social_pinterest_test_connection( $config = array() ) {
				return TaxoCommerce_Connections_Pinterest::test_connection();
			}
		}
	}
}
