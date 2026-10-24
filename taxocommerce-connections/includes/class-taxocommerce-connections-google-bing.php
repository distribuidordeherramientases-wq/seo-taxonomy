<?php
/**
 * Shared Google / Microsoft Bing connection settings.
 *
 * Uses the existing TaxoCommerce Google/Bing option names deliberately so
 * Insights can consume the same credentials during migration without copies.
 */

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Connections_Google_Bing {

	const GOOGLE_OPTION           = 'taxocommerce_google_bing_google_settings';
	const BING_OPTION             = 'taxocommerce_google_bing_bing_settings';
	const MERCHANT_ACCOUNT_OPTION = 'taxocommerce_google_bing_merchant_account';

	public static function boot() {
		add_action( 'admin_post_taxocommerce_connections_save_google', array( __CLASS__, 'save_google' ) );
		add_action( 'admin_post_taxocommerce_connections_test_google', array( __CLASS__, 'test_google' ) );
		add_action( 'admin_post_taxocommerce_connections_save_merchant', array( __CLASS__, 'save_merchant' ) );
		add_action( 'admin_post_taxocommerce_connections_test_merchant', array( __CLASS__, 'test_merchant' ) );
		add_action( 'admin_post_taxocommerce_connections_save_bing', array( __CLASS__, 'save_bing' ) );
		add_action( 'admin_post_taxocommerce_connections_test_bing', array( __CLASS__, 'test_bing' ) );
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

	public static function merchant_account() {
		$id = trim( (string) get_option( self::MERCHANT_ACCOUNT_OPTION, '' ) );
		return preg_match( '/^[0-9]{5,20}$/', $id ) ? $id : '';
	}

	public static function google_oauth_available() {
		return class_exists( 'TaxoCommerce_Google_Bing_OAuth' );
	}

	public static function google_oauth_render() {
		if ( self::google_oauth_available() && method_exists( 'TaxoCommerce_Google_Bing_OAuth', 'render' ) ) {
			TaxoCommerce_Google_Bing_OAuth::render();
			return;
		}
		echo '<div class="notice notice-info inline"><p>OAuth de Google se migrará en la siguiente fase. La cuenta de servicio, Search Console, GA4, Merchant y Bing ya se gestionan desde Connections.</p></div>';
	}

	public static function save_google() {
		self::guard( 'taxocommerce_connections_save_google' );
		$old = self::google_settings();

		$measurement = isset( $_POST['measurement_id'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['measurement_id'] ) ) ) : '';
		if ( '' !== $measurement && ! preg_match( '/^G-[A-Z0-9]{6,20}$/', $measurement ) ) {
			self::redirect( 'google_invalid' );
		}

		$property = isset( $_POST['analytics_property_id'] ) ? preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_POST['analytics_property_id'] ) ) : '';
		$site = isset( $_POST['search_console_site_url'] ) ? sanitize_text_field( wp_unslash( $_POST['search_console_site_url'] ) ) : '';
		$json = isset( $_POST['service_account_json'] ) ? trim( (string) wp_unslash( $_POST['service_account_json'] ) ) : '';

		if ( '' === $json ) {
			$json = (string) ( $old['service_account_json'] ?? '' );
		} else {
			$decoded = json_decode( $json, true );
			if ( ! is_array( $decoded ) || empty( $decoded['client_email'] ) || empty( $decoded['private_key'] ) ) {
				self::redirect( 'google_credentials_invalid' );
			}
		}

		update_option(
			self::GOOGLE_OPTION,
			array(
				'tracking_enabled'        => ! empty( $old['tracking_enabled'] ) ? 1 : 0,
				'measurement_id'          => $measurement,
				'analytics_property_id'   => $property,
				'search_console_site_url' => $site,
				'service_account_json'    => $json,
			),
			false
		);
		self::redirect( 'google_saved' );
	}

	public static function test_google() {
		self::guard( 'taxocommerce_connections_test_google' );

		if ( class_exists( 'TaxoCommerce_Google_Bing_Connections' ) && method_exists( 'TaxoCommerce_Google_Bing_Connections', 'access_token' ) ) {
			$token = TaxoCommerce_Google_Bing_Connections::access_token( '', true );
			if ( is_wp_error( $token ) ) {
				self::test_result( 'google', false, $token->get_error_message() );
			}
			self::test_result( 'google', true, 'Cuenta de servicio Google autenticada correctamente.' );
		}

		$settings = self::google_settings();
		$account = json_decode( (string) ( $settings['service_account_json'] ?? '' ), true );
		if ( ! is_array( $account ) || empty( $account['client_email'] ) || empty( $account['private_key'] ) ) {
			self::test_result( 'google', false, 'Falta una cuenta de servicio Google válida.' );
		}
		self::test_result( 'google', true, 'Configuración Google válida. La prueba de token completa requiere TaxoCommerce Insights activo durante esta fase de transición.' );
	}

	public static function save_merchant() {
		self::guard( 'taxocommerce_connections_save_merchant' );
		$id = isset( $_POST['merchant_account'] ) ? preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_POST['merchant_account'] ) ) : '';
		if ( '' !== $id && ! preg_match( '/^[0-9]{5,20}$/', $id ) ) {
			self::redirect( 'merchant_invalid' );
		}
		update_option( self::MERCHANT_ACCOUNT_OPTION, $id, false );
		self::redirect( 'merchant_saved' );
	}

	public static function test_merchant() {
		self::guard( 'taxocommerce_connections_test_merchant' );
		$id = self::merchant_account();
		if ( '' === $id ) {
			self::test_result( 'merchant', false, 'Falta el ID de Google Merchant Center.' );
		}

		if ( class_exists( 'TaxoCommerce_Google_Bing_Connections' ) && method_exists( 'TaxoCommerce_Google_Bing_Connections', 'access_token' ) ) {
			$token = TaxoCommerce_Google_Bing_Connections::access_token( 'https://www.googleapis.com/auth/content', true );
			if ( is_wp_error( $token ) ) {
				self::test_result( 'merchant', false, $token->get_error_message() );
			}
			self::test_result( 'merchant', true, 'Credencial Google válida para Merchant Center.' );
		}

		self::test_result( 'merchant', true, 'ID Merchant configurado. La validación API completa requiere TaxoCommerce Insights activo durante la transición.' );
	}

	public static function save_bing() {
		self::guard( 'taxocommerce_connections_save_bing' );
		$old = self::bing_settings();
		$key = (string) ( $old['api_key'] ?? '' );

		if ( ! empty( $_POST['clear_api_key'] ) && ! defined( 'SEO_BING_WEBMASTER_API_KEY' ) ) {
			$key = '';
		}
		$posted = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';
		if ( '' !== $posted ) {
			$key = $posted;
		}

		$site = isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : home_url( '/' );
		if ( '' === $site ) {
			$site = home_url( '/' );
		}

		update_option(
			self::BING_OPTION,
			array(
				'enabled'  => ! empty( $_POST['enabled'] ),
				'site_url' => $site,
				'api_key'  => sanitize_text_field( $key ),
			),
			false
		);
		self::redirect( 'bing_saved' );
	}

	public static function test_bing() {
		self::guard( 'taxocommerce_connections_test_bing' );
		$settings = self::bing_settings();
		$key = defined( 'SEO_BING_WEBMASTER_API_KEY' ) && trim( (string) SEO_BING_WEBMASTER_API_KEY )
			? trim( (string) SEO_BING_WEBMASTER_API_KEY )
			: trim( (string) ( $settings['api_key'] ?? '' ) );

		if ( '' === $key ) {
			self::test_result( 'bing', false, 'Falta la API key de Bing Webmaster.' );
		}
		if ( empty( $settings['enabled'] ) ) {
			self::test_result( 'bing', false, 'Bing está desactivado.' );
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
				'user-agent'  => 'TaxoCommerce-Connections/' . TAXOCOMMERCE_CONNECTIONS_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			self::test_result( 'bing', false, $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			self::test_result( 'bing', false, 'Bing Webmaster respondió HTTP ' . $code . '.' );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$target = untrailingslashit( strtolower( (string) $settings['site_url'] ) );
		$found = false;
		foreach ( (array) ( $body['d'] ?? array() ) as $site ) {
			$value = untrailingslashit( strtolower( (string) ( $site['Url'] ?? '' ) ) );
			if ( $target && $value === $target ) {
				$found = true;
				break;
			}
		}
		if ( $target && ! $found ) {
			self::test_result( 'bing', false, 'La clave Bing funciona, pero el sitio configurado no figura entre los sitios autorizados.' );
		}
		self::test_result( 'bing', true, 'Conexión Bing Webmaster verificada.' );
	}

	public static function consume_test_result( $provider ) {
		$key = 'tc_conn_' . sanitize_key( $provider ) . '_test_' . get_current_user_id();
		$result = get_transient( $key );
		delete_transient( $key );
		return is_array( $result ) ? $result : array();
	}

	private static function test_result( $provider, $ok, $message ) {
		set_transient(
			'tc_conn_' . sanitize_key( $provider ) . '_test_' . get_current_user_id(),
			array( 'ok' => $ok ? 1 : 0, 'message' => sanitize_text_field( (string) $message ) ),
			90
		);
		wp_safe_redirect( TaxoCommerce_Connections_Admin::url( array( 'tc_conn_notice' => sanitize_key( $provider ) . '_test' ) ) );
		exit;
	}

	private static function guard( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para gestionar conexiones.', 'taxocommerce-connections' ) );
		}
		check_admin_referer( $nonce );
	}

	private static function redirect( $notice ) {
		wp_safe_redirect( TaxoCommerce_Connections_Admin::url( array( 'tc_conn_notice' => sanitize_key( $notice ) ) ) );
		exit;
	}
}
