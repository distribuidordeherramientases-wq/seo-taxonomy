<?php
/**
 * Admin UI for shared TaxoCommerce connections.
 */

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Connections_Admin {

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 43 );
		add_action( 'admin_post_taxocommerce_connections_save_amazon', array( __CLASS__, 'save_amazon' ) );
		add_action( 'admin_post_taxocommerce_connections_test_amazon', array( __CLASS__, 'test_amazon' ) );
		add_action( 'admin_post_taxocommerce_connections_save_pinterest', array( __CLASS__, 'save_pinterest' ) );
		add_action( 'admin_post_taxocommerce_connections_test_pinterest', array( __CLASS__, 'test_pinterest' ) );
		add_action( 'admin_post_taxocommerce_connections_save_pinterest_merchant', array( __CLASS__, 'save_pinterest_merchant' ) );
	}

	public static function url( $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => 'taxocommerce-connections' ), is_array( $args ) ? $args : array() ),
			admin_url( 'admin.php' )
		);
	}

	public static function menu() {
		$capability = 'manage_options';
		if ( function_exists( 'taxocommerce_core' ) ) {
			add_submenu_page(
				'taxocommerce-core',
				__( 'Connections', 'taxocommerce-connections' ),
				__( 'Connections', 'taxocommerce-connections' ),
				$capability,
				'taxocommerce-connections',
				array( __CLASS__, 'render' )
			);
			return;
		}

		add_menu_page(
			__( 'TaxoCommerce Connections', 'taxocommerce-connections' ),
			__( 'Connections', 'taxocommerce-connections' ),
			$capability,
			'taxocommerce-connections',
			array( __CLASS__, 'render' ),
			'dashicons-admin-links',
			58
		);
	}

	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage connections.', 'taxocommerce-connections' ) );
		}
	}

	public static function save_amazon() {
		self::guard();
		check_admin_referer( 'taxocommerce_connections_save_amazon' );
		$result = TaxoCommerce_Connections_Amazon::save( wp_unslash( $_POST ) );
		$notice = is_wp_error( $result ) ? 'amazon_error' : 'amazon_saved';
		wp_safe_redirect( self::url( array( 'tc_conn_notice' => $notice ) ) );
		exit;
	}

	public static function test_amazon() {
		self::guard();
		check_admin_referer( 'taxocommerce_connections_test_amazon' );
		$result = TaxoCommerce_Connections_Amazon::access_token( true );
		$key = 'tc_conn_amazon_test_' . get_current_user_id();
		set_transient(
			$key,
			is_wp_error( $result )
				? array( 'ok' => 0, 'message' => $result->get_error_message() )
				: array( 'ok' => 1, 'message' => __( 'Amazon Creators API responded successfully.', 'taxocommerce-connections' ) ),
			90
		);
		wp_safe_redirect( self::url( array( 'tc_conn_notice' => 'amazon_test' ) ) );
		exit;
	}

	public static function save_pinterest() {
		self::guard();
		check_admin_referer( 'taxocommerce_connections_save_pinterest' );
		$result = TaxoCommerce_Connections_Pinterest::save( wp_unslash( $_POST ) );
		$notice = is_wp_error( $result ) ? 'pinterest_error' : 'pinterest_saved';
		wp_safe_redirect( self::url( array( 'tc_conn_notice' => $notice ) ) );
		exit;
	}

	public static function test_pinterest() {
		self::guard();
		check_admin_referer( 'taxocommerce_connections_test_pinterest' );
		$result = TaxoCommerce_Connections_Pinterest::test_connection();
		$key = 'tc_conn_pinterest_test_' . get_current_user_id();
		set_transient(
			$key,
			is_wp_error( $result )
				? array( 'ok' => 0, 'message' => $result->get_error_message() )
				: array( 'ok' => 1, 'message' => __( 'Pinterest responded successfully and the selected board is accessible.', 'taxocommerce-connections' ) ),
			90
		);
		wp_safe_redirect( self::url( array( 'tc_conn_notice' => 'pinterest_test' ) ) );
		exit;
	}

	public static function save_pinterest_merchant() {
		self::guard();
		check_admin_referer( 'taxocommerce_connections_save_pinterest_merchant' );
		$result = TaxoCommerce_Connections_Pinterest::save_merchant( wp_unslash( $_POST ) );
		$notice = is_wp_error( $result ) ? 'pinterest_merchant_error' : 'pinterest_merchant_saved';
		wp_safe_redirect( self::url( array( 'tc_conn_notice' => $notice ) ) );
		exit;
	}

	private static function notice() {
		$notice = isset( $_GET['tc_conn_notice'] ) ? sanitize_key( wp_unslash( $_GET['tc_conn_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'google_saved'              => array( 'success', __( 'Google settings saved.', 'taxocommerce-connections' ) ),
			'google_invalid'            => array( 'error', __( 'The Google Measurement ID format is invalid.', 'taxocommerce-connections' ) ),
			'google_credentials_invalid'=> array( 'error', __( 'The Google service account JSON is invalid.', 'taxocommerce-connections' ) ),
			'merchant_saved'            => array( 'success', __( 'Google Merchant Center settings saved.', 'taxocommerce-connections' ) ),
			'merchant_invalid'          => array( 'error', __( 'The Google Merchant Center ID is invalid.', 'taxocommerce-connections' ) ),
			'bing_saved'                => array( 'success', __( 'Bing Webmaster settings saved.', 'taxocommerce-connections' ) ),
			'amazon_saved'              => array( 'success', __( 'Amazon settings saved.', 'taxocommerce-connections' ) ),
			'amazon_error'              => array( 'error', __( 'Amazon settings could not be saved.', 'taxocommerce-connections' ) ),
			'pinterest_saved'           => array( 'success', __( 'Pinterest settings saved.', 'taxocommerce-connections' ) ),
			'pinterest_error'           => array( 'error', __( 'Pinterest settings could not be saved.', 'taxocommerce-connections' ) ),
			'pinterest_connected'       => array( 'success', __( 'Pinterest authorized through OAuth.', 'taxocommerce-connections' ) ),
			'pinterest_app_missing'     => array( 'error', __( 'Save the Pinterest App ID and App Secret first.', 'taxocommerce-connections' ) ),
			'pinterest_merchant_saved'  => array( 'success', __( 'Pinterest Merchant settings saved.', 'taxocommerce-connections' ) ),
			'pinterest_merchant_error'  => array( 'error', __( 'Pinterest Merchant verification is invalid.', 'taxocommerce-connections' ) ),
		);

		if ( 'amazon_test' === $notice ) {
			$result = get_transient( 'tc_conn_amazon_test_' . get_current_user_id() );
			delete_transient( 'tc_conn_amazon_test_' . get_current_user_id() );
			if ( is_array( $result ) ) {
				echo '<div class="notice notice-' . esc_attr( ! empty( $result['ok'] ) ? 'success' : 'error' ) . ' inline"><p>' . esc_html( (string) ( $result['message'] ?? '' ) ) . '</p></div>';
			}
			return;
		}
		foreach ( array( 'google', 'merchant', 'bing' ) as $provider ) {
			if ( $provider . '_test' !== $notice ) {
				continue;
			}
			$result = TaxoCommerce_Connections_Google_Bing::consume_test_result( $provider );
			if ( is_array( $result ) && $result ) {
				echo '<div class="notice notice-' . esc_attr( ! empty( $result['ok'] ) ? 'success' : 'error' ) . ' inline"><p>' . esc_html( (string) ( $result['message'] ?? '' ) ) . '</p></div>';
			}
			return;
		}

		if ( 'pinterest_test' === $notice ) {
			$result = get_transient( 'tc_conn_pinterest_test_' . get_current_user_id() );
			delete_transient( 'tc_conn_pinterest_test_' . get_current_user_id() );
			if ( is_array( $result ) ) {
				echo '<div class="notice notice-' . esc_attr( ! empty( $result['ok'] ) ? 'success' : 'error' ) . ' inline"><p>' . esc_html( (string) ( $result['message'] ?? '' ) ) . '</p></div>';
			}
			return;
		}
		if ( isset( $messages[ $notice ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $messages[ $notice ][0] ) . ' inline"><p>' . esc_html( $messages[ $notice ][1] ) . '</p></div>';
		}
	}

	public static function render() {
		self::guard();
		$google = TaxoCommerce_Connections_Google_Bing::google_settings();
		$google_merchant = TaxoCommerce_Connections_Google_Bing::merchant_account();
		$bing = TaxoCommerce_Connections_Google_Bing::bing_settings();
		$amazon = TaxoCommerce_Connections_Amazon::settings();
		$pinterest = TaxoCommerce_Connections_Pinterest::settings();
		$merchant = TaxoCommerce_Connections_Pinterest::merchant_settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'TaxoCommerce Connections', 'taxocommerce-connections' ); ?></h1>
			<p><?php esc_html_e( 'Shared connections used by multiple modules. Credentials and OAuth live here; reports, feeds, and publishing remain owned by their functional plugins.', 'taxocommerce-connections' ); ?></p>
			<?php self::notice(); ?>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:18px;max-width:1300px;">
				<div class="card" style="max-width:none;padding:20px;">
					<h2 style="margin-top:0;">Google · Search Console / Analytics / Merchant</h2>
					<p>Connections owns the credentials; <strong>TaxoCommerce Insights</strong> consumes the data for reporting. Existing internal option names are preserved to avoid losing configuration.</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="taxocommerce_connections_save_google">
						<?php wp_nonce_field( 'taxocommerce_connections_save_google' ); ?>
						<p><label><strong>Search Console property</strong><br><input class="large-text code" type="text" name="search_console_site_url" value="<?php echo esc_attr( (string) ( $google['search_console_site_url'] ?? '' ) ); ?>" placeholder="sc-domain:ejemplo.es o https://www.ejemplo.es/"></label></p>
						<p><label><strong>GA4 property</strong><br><input class="regular-text" inputmode="numeric" type="text" name="analytics_property_id" value="<?php echo esc_attr( (string) ( $google['analytics_property_id'] ?? '' ) ); ?>" placeholder="123456789"></label></p>
						<p><label><strong>Measurement ID</strong><br><input class="regular-text" type="text" name="measurement_id" value="<?php echo esc_attr( (string) ( $google['measurement_id'] ?? '' ) ); ?>" placeholder="G-XXXXXXXXXX"></label></p>
						<p><label><strong>Service account JSON</strong><br><textarea class="large-text code" rows="6" name="service_account_json" placeholder="<?php echo esc_attr( ! empty( $google['service_account_json'] ) ? 'Credential already stored; leave blank to keep it' : 'Paste the service account JSON' ); ?>"></textarea></label></p>
						<?php submit_button( __( 'Save Google', 'taxocommerce-connections' ), 'primary', 'submit', false ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px;">
						<input type="hidden" name="action" value="taxocommerce_connections_test_google">
						<?php wp_nonce_field( 'taxocommerce_connections_test_google' ); ?>
						<?php submit_button( __( 'Test Google', 'taxocommerce-connections' ), 'secondary', 'submit', false ); ?>
					</form>
					<hr>
					<h3>Google Merchant Center</h3>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="taxocommerce_connections_save_merchant">
						<?php wp_nonce_field( 'taxocommerce_connections_save_merchant' ); ?>
						<p><label><strong>Merchant Center ID</strong><br><input class="regular-text" type="text" name="merchant_account" value="<?php echo esc_attr( (string) $google_merchant ); ?>" pattern="[0-9]{5,20}"></label></p>
						<?php submit_button( __( 'Save Merchant', 'taxocommerce-connections' ), 'secondary', 'submit', false ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px;">
						<input type="hidden" name="action" value="taxocommerce_connections_test_merchant">
						<?php wp_nonce_field( 'taxocommerce_connections_test_merchant' ); ?>
						<?php submit_button( __( 'Test Merchant', 'taxocommerce-connections' ), 'secondary', 'submit', false ); ?>
					</form>
					<div style="margin-top:18px;padding-top:14px;border-top:1px solid #ddd;">
						<h3>Google OAuth</h3>
						<?php TaxoCommerce_Connections_Google_Bing::google_oauth_render(); ?>
					</div>
				</div>

				<div class="card" style="max-width:none;padding:20px;">
					<h2 style="margin-top:0;">Microsoft Bing Webmaster</h2>
					<p>Complementary search source for queries, pages, crawl, and index data. Insights uses this connection for reporting.</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="taxocommerce_connections_save_bing">
						<?php wp_nonce_field( 'taxocommerce_connections_save_bing' ); ?>
						<p><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $bing['enabled'] ) ); ?>> Enable Bing as a data source</label></p>
						<p><label><strong>Verified URL</strong><br><input class="large-text code" type="url" name="site_url" value="<?php echo esc_attr( (string) ( $bing['site_url'] ?? home_url( '/' ) ) ); ?>"></label></p>
						<p><label><strong>API key Bing Webmaster</strong><br><input class="regular-text" type="password" name="api_key" value="" placeholder="<?php echo esc_attr( ! empty( $bing['api_key'] ) ? 'Key already stored; leave blank to keep it' : 'Paste the API key' ); ?>"></label></p>
						<?php if ( ! empty( $bing['api_key'] ) && ! defined( 'SEO_BING_WEBMASTER_API_KEY' ) ) : ?>
							<p><label><input type="checkbox" name="clear_api_key" value="1"> Remove the stored API key.</label></p>
						<?php endif; ?>
						<?php submit_button( __( 'Save Bing', 'taxocommerce-connections' ), 'primary', 'submit', false ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px;">
						<input type="hidden" name="action" value="taxocommerce_connections_test_bing">
						<?php wp_nonce_field( 'taxocommerce_connections_test_bing' ); ?>
						<?php submit_button( __( 'Test Bing', 'taxocommerce-connections' ), 'secondary', 'submit', false ); ?>
					</form>
				</div>

				<div class="card" style="max-width:none;padding:20px;">
					<h2 style="margin-top:0;">Amazon Affiliates</h2>
					<p>The <strong>Partner Tag</strong> is enough for affiliate search links used by Templates and Assistant. Creators API only enriches them with product data, images, and pricing.</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="taxocommerce_connections_save_amazon">
						<?php wp_nonce_field( 'taxocommerce_connections_save_amazon' ); ?>
						<p><label><strong>amazon.es Partner Tag</strong><br><input class="regular-text" type="text" name="partner_tag" value="<?php echo esc_attr( (string) ( $amazon['partner_tag'] ?? '' ) ); ?>"></label></p>
						<?php if ( ! empty( $amazon['partner_tag'] ) ) : ?>
							<p><label><input type="checkbox" name="clear_partner_tag" value="1"> Explicitly remove the stored Partner Tag.</label></p>
						<?php endif; ?>
						<p><label><strong>Credential / Client ID Creators</strong><br><input class="regular-text" type="text" name="client_id" value="<?php echo esc_attr( (string) ( $amazon['client_id'] ?? '' ) ); ?>"></label></p>
						<p><label><strong>Credential Secret Creators</strong><br><input class="regular-text" type="password" name="client_secret" value="" placeholder="<?php echo esc_attr( ! empty( $amazon['client_secret'] ) ? 'Stored; leave blank to keep it' : 'Optional' ); ?>"></label></p>
						<input type="hidden" name="search_index" value="<?php echo esc_attr( (string) ( $amazon['search_index'] ?? 'All' ) ); ?>">
						<input type="hidden" name="item_count" value="<?php echo esc_attr( (string) absint( $amazon['item_count'] ?? 10 ) ); ?>">
						<?php submit_button( __( 'Save Amazon', 'taxocommerce-connections' ), 'primary', 'submit', false ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px;">
						<input type="hidden" name="action" value="taxocommerce_connections_test_amazon">
						<?php wp_nonce_field( 'taxocommerce_connections_test_amazon' ); ?>
						<?php submit_button( __( 'Test Creators API', 'taxocommerce-connections' ), 'secondary', 'submit', false, TaxoCommerce_Connections_Amazon::creators_ready() ? array() : array( 'disabled' => 'disabled' ) ); ?>
					</form>
				</div>

				<div class="card" style="max-width:none;padding:20px;">
					<h2 style="margin-top:0;">Pinterest Business</h2>
					<p>One connection supports both social publishing and Merchant/Catalogs configuration. Distribution publishes the feed; Connections owns OAuth, board selection, and domain verification.</p>
					<p><strong>Callback OAuth:</strong><br><code><?php echo esc_html( TaxoCommerce_Connections_Pinterest::redirect_uri() ); ?></code></p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="taxocommerce_connections_save_pinterest">
						<?php wp_nonce_field( 'taxocommerce_connections_save_pinterest' ); ?>
						<p><label><strong>Pinterest App ID</strong><br><input class="regular-text" type="text" name="app_id" value="<?php echo esc_attr( (string) ( $pinterest['app_id'] ?? '' ) ); ?>"></label></p>
						<p><label><strong>App Secret</strong><br><input class="regular-text" type="password" name="app_secret" value="" placeholder="<?php echo esc_attr( ! empty( $pinterest['app_secret_enc'] ) ? 'Stored; leave blank to keep it' : '' ); ?>"></label></p>
						<p><label><strong>Manual Access Token</strong><br><input class="regular-text" type="password" name="access_token" value="" placeholder="<?php echo esc_attr( ! empty( $pinterest['access_token_enc'] ) ? 'Stored; leave blank to keep it' : 'Optional; OAuth recomendado' ); ?>"></label></p>
						<?php if ( ! empty( $pinterest['boards'] ) ) : ?>
							<p><label><strong>Destination board</strong><br><select name="board_id">
								<option value="">Select board</option>
								<?php foreach ( (array) $pinterest['boards'] as $board ) : ?>
									<option value="<?php echo esc_attr( (string) ( $board['id'] ?? '' ) ); ?>" <?php selected( (string) ( $pinterest['board_id'] ?? '' ), (string) ( $board['id'] ?? '' ) ); ?>><?php echo esc_html( (string) ( $board['name'] ?? $board['id'] ?? '' ) ); ?></option>
								<?php endforeach; ?>
							</select></label></p>
						<?php endif; ?>
						<?php submit_button( __( 'Save Pinterest', 'taxocommerce-connections' ), 'primary', 'submit', false ); ?>
					</form>

					<p style="display:flex;gap:8px;flex-wrap:wrap;">
						<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'taxocommerce_connections_pinterest_oauth_start', admin_url( 'admin-post.php' ) ), 'taxocommerce_connections_pinterest_oauth_start' ) ); ?>">Authorize with Pinterest OAuth</a>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="taxocommerce_connections_test_pinterest">
						<?php wp_nonce_field( 'taxocommerce_connections_test_pinterest' ); ?>
						<?php submit_button( __( 'Test Pinterest', 'taxocommerce-connections' ), 'secondary', 'submit', false, ! empty( $pinterest['board_id'] ) && ! empty( $pinterest['access_token_enc'] ) ? array() : array( 'disabled' => 'disabled' ) ); ?>
					</form>

					<hr>
					<h3>Pinterest Merchant / Catalogs</h3>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="taxocommerce_connections_save_pinterest_merchant">
						<?php wp_nonce_field( 'taxocommerce_connections_save_pinterest_merchant' ); ?>
						<p><label><input type="checkbox" name="domain_verify_enabled" value="1" <?php checked( ! empty( $merchant['domain_verify_enabled'] ) ); ?>> Enable domain verification</label></p>
						<p><label><strong>p:domain_verify code</strong><br><input class="large-text" type="text" name="domain_verify_code" value="<?php echo esc_attr( (string) ( $merchant['domain_verify_code'] ?? '' ) ); ?>"></label></p>
						<p><label><strong>Pinterest Catalogs feed URL</strong><br><input class="large-text" type="url" name="catalog_feed_url" value="<?php echo esc_attr( (string) ( $merchant['catalog_feed_url'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( home_url( '/wp-content/uploads/seo-system-feeds/pinterest-catalog.csv' ) ); ?>"></label></p>
						<?php submit_button( __( 'Save Pinterest Merchant', 'taxocommerce-connections' ), 'secondary', 'submit', false ); ?>
					</form>
				</div>
			</div>

			<div class="card" style="max-width:1300px;padding:20px;margin-top:18px;">
				<h2 style="margin-top:0;">Responsibility split</h2>
				<p><strong>Connections:</strong> Google, Bing, Amazon, and Pinterest credentials, OAuth, tokens, accounts, and connection tests.</p>
				<p><strong>Insights:</strong> Google Search, Analytics, Bing, Trends, Market, opportunities, and executive reporting. It does not own credentials.</p>
				<p><strong>Distribution:</strong> commercial feeds and sitemaps. Pinterest Catalogs is published there; Connections only owns its connection and verification data.</p>
				<p><strong>Cloudflare:</strong> not migrated. It is disabled and currently has no required modular consumer.</p>
			</div>
		</div>
		<?php
	}
}
