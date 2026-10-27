<?php
/**
 * Read-only, provider-specific setup instructions for the Insights Connections tab.
 *
 * Links point to official provider consoles/help pages. The illustrated menu
 * paths are guidance, never representations of a user's actual provider account.
 */

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Connections_Guide {

	/**
	 * Show the safe, non-secret identity of a configured service account.
	 *
	 * @param array $settings Existing Google settings.
	 * @return void
	 */
	public static function service_identity( $settings ) {
		$account = json_decode( (string) ( $settings['service_account_json'] ?? '' ), true );
		$email   = is_array( $account ) && ! empty( $account['client_email'] )
			? sanitize_email( (string) $account['client_email'] )
			: '';

		if ( $email ) {
			echo '<p class="tgb-connection-hint"><strong>Saved service account:</strong> <code>' . esc_html( $email ) . '</code>';
			echo '<br>Keep the JSON box empty to preserve this key. Give this email access to your Search Console property if you use service-account authentication.</p>';
		} else {
			echo '<p class="tgb-connection-hint">No saved service-account identity found. Google Search Console can also be connected using OAuth below.</p>';
		}
	}

	/**
	 * Render the right-hand instructions for one group of connection fields.
	 *
	 * @param string $provider google|bing|market|amazon|pinterest.
	 * @return void
	 */
	public static function render( $provider ) {
		$titles = array(
			'google'    => 'Google setup guide',
			'bing'      => 'Bing Webmaster setup guide',
			'market'    => 'Market providers setup guide',
			'amazon'    => 'Amazon Affiliates setup guide',
			'pinterest' => 'Pinterest setup guide',
		);
		$sections = self::topics( $provider );
		if ( ! $sections ) {
			return;
		}

		echo '<div class="tgb-guide-header">';
		echo '<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>';
		echo '<div><h2>' . esc_html( $titles[ $provider ] ?? 'Connection guide' ) . '</h2>';
		echo '<p>Follow the steps here, enter the requested values in the form on the left, then Save and Test. Existing secrets stay stored when their fields are left blank.</p></div>';
		echo '</div>';

		foreach ( $sections as $topic ) {
			echo '<details class="tgb-guide-topic"' . ( ! empty( $topic['open'] ) ? ' open' : '' ) . '>';
			echo '<summary>' . esc_html( $topic['title'] ) . '</summary>';
			echo '<div class="tgb-guide-topic-body">';
			if ( ! empty( $topic['intro'] ) ) {
				echo '<p>' . esc_html( $topic['intro'] ) . '</p>';
			}
			if ( ! empty( $topic['path'] ) ) {
				self::path( $topic['path'], $topic['platform'] ?? '' );
			}
			if ( ! empty( $topic['steps'] ) ) {
				echo '<ol class="tgb-guide-steps">';
				foreach ( $topic['steps'] as $step ) {
					echo '<li>' . esc_html( $step ) . '</li>';
				}
				echo '</ol>';
			}
			if ( ! empty( $topic['links'] ) ) {
				echo '<div class="tgb-guide-links">';
				foreach ( $topic['links'] as $link ) {
					echo '<a class="button button-secondary" target="_blank" rel="noopener noreferrer" href="' . esc_url( $link[1] ) . '">';
					echo esc_html( $link[0] ) . ' <span class="dashicons dashicons-external" aria-hidden="true"></span></a>';
				}
				echo '</div>';
			}
			if ( ! empty( $topic['note'] ) ) {
				echo '<p class="tgb-guide-note"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span> ' . esc_html( $topic['note'] ) . '</p>';
			}
			echo '</div></details>';
		}
		echo '<p class="tgb-guide-disclaimer">Navigation illustrations are not live screenshots. Provider menus may change; the linked official pages are the source of truth.</p>';
	}

	/**
	 * Render a small annotated menu-path illustration with recognisable
	 * button-like labels. These are not misleading screenshots or live controls.
	 *
	 * @param array  $path Click targets in order.
	 * @param string $platform Provider interface label.
	 * @return void
	 */
	private static function path( $path, $platform ) {
		echo '<figure class="tgb-guide-illustration">';
		echo '<div class="tgb-guide-illustration-head"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span> ';
		echo esc_html( $platform ?: 'Where to click' ) . '</div>';
		echo '<div class="tgb-guide-clicks" aria-label="Illustrated menu navigation">';
		foreach ( $path as $index => $target ) {
			if ( $index ) {
				echo '<span class="tgb-guide-chevron" aria-hidden="true">&rsaquo;</span>';
			}
			echo '<span class="tgb-guide-click-target">' . esc_html( $target ) . '</span>';
		}
		echo '</div><figcaption>Illustrated click path (not a live screenshot).</figcaption></figure>';
	}

	/**
	 * Static, public-reference instructions; do not request credentials here.
	 *
	 * @param string $provider Group identifier.
	 * @return array
	 */
	private static function topics( $provider ) {
		switch ( $provider ) {
			case 'google':
				return array(
					array(
						'title'    => '1. Search Console property',
						'open'     => true,
						'intro'    => 'Use the exact property selected in Search Console, not merely the domain that appears in your browser.',
						'platform' => 'Google Search Console',
						'path'     => array( 'Property selector (top left)', 'Select property', 'Copy exact URL' ),
						'steps'    => array(
							'Open Search Console and use the property selector in the upper-left corner.',
							'For a URL-prefix property, enter its full URL, including https://, www when present, and the final / (for example https://www.example.com/).',
							'For a Domain property, enter sc-domain:example.com. Do not convert a URL-prefix property into a Domain property.',
							'Paste the exact value into "Search Console property" on the left and click Save Google.',
						),
						'links'    => array(
							array( 'Open Search Console', 'https://search.google.com/search-console' ),
						),
						'note'     => 'The OAuth property and the service-account property are separate settings. Check which identity will be used for the report download.',
					),
					array(
						'title'    => '2. Search Console permissions (HTTP 403)',
						'open'     => true,
						'platform' => 'Search Console property settings',
						'path'     => array( 'Settings', 'Users and permissions', 'Add user' ),
						'steps'    => array(
							'If Google reports HTTP 403, open the selected Search Console property and go to Settings > Users and permissions.',
							'When using a service account, find its email in "Saved service account" on the left. Add that exact address as a user with sufficient read access, if it is not already listed.',
							'When using OAuth, the signed-in Google user must have access instead. Authorization for one identity does not automatically authorize the other.',
							'Save permissions in Google, then use the Google Search report tab to download data again.',
						),
						'links'    => array(
							array( 'Open Search Console settings', 'https://search.google.com/search-console' ),
							array( 'Official users and permissions guide', 'https://support.google.com/webmasters/answer/7687615?hl=en' ),
						),
						'note'     => 'A successful "Test Google" confirms the service-account token only; it does not prove access to this Search Console property. A report refresh is the end-to-end check.',
					),
					array(
						'title'    => '3. Google Analytics (GA4)',
						'open'     => true,
						'platform' => 'Google Analytics',
						'path'     => array( 'Admin', 'Property details / Data streams', 'Web' ),
						'steps'    => array(
							'Open Google Analytics and select the correct GA4 property.',
							'In Admin > Property details, copy the numeric Property ID to the "GA4 property" field.',
							'In Admin > Data streams > Web, copy the Measurement ID starting with G- to the "Measurement ID" field.',
							'For Analytics reporting, grant the configured service-account email access to this GA4 property as a Viewer (or higher) in Property access management.',
						),
						'links'    => array(
							array( 'Open Google Analytics', 'https://analytics.google.com/' ),
							array( 'GA4 Measurement ID help', 'https://support.google.com/analytics/answer/9539598?hl=en' ),
						),
						'note'     => 'The numeric property ID is for Analytics Data API reports. The G- measurement ID is for browser tracking. They are not interchangeable.',
					),
					array(
						'title'    => '4. Service account JSON (alternative authentication)',
						'platform' => 'Google Cloud',
						'path'     => array( 'IAM & Admin', 'Service Accounts', 'Keys', 'Add key' ),
						'steps'    => array(
							'If "Saved service account" appears on the left, keep the JSON field EMPTY. Never rotate a working key merely to view it.',
							'Only for a new setup: open the correct Google Cloud project, create/select a Service Account, then Keys > Add key > Create new key > JSON.',
							'Store the downloaded JSON privately and paste it into the form. It contains a private key: never share it in tickets, screenshots or public repositories.',
							'Enable the required Google APIs in that Cloud project and grant the service-account email access in Search Console and GA4 as described above.',
						),
						'links'    => array(
							array( 'Google Cloud Service Accounts', 'https://console.cloud.google.com/iam-admin/serviceaccounts' ),
							array( 'Official JSON key instructions', 'https://cloud.google.com/iam/docs/keys-create-delete' ),
							array( 'Search Console API', 'https://console.cloud.google.com/apis/library/searchconsole.googleapis.com' ),
						),
						'note'     => 'Google does not let you download an existing private key again. If a key is already stored, leave it unchanged.',
					),
					array(
						'title'    => '5. Google OAuth (recommended for an existing connected Google user)',
						'platform' => 'Google Cloud',
						'path'     => array( 'Google Auth Platform', 'Clients', 'Web application', 'Redirect URIs' ),
						'steps'    => array(
							'If Google OAuth is already connected below, keep the existing Client ID and Client Secret.',
							'For a new OAuth setup, configure the consent screen and an OAuth 2.0 Web application client in Google Cloud.',
							'Add the exact Redirect URI displayed in the Google OAuth form on the left to "Authorized redirect URIs" in Google Cloud.',
							'Save Client ID and Client Secret in this plugin, then click Connect Google and authorize the Google account that owns or can read the Search Console property.',
						),
						'links'    => array(
							array( 'Google Cloud OAuth clients', 'https://console.cloud.google.com/auth/clients' ),
							array( 'OAuth setup documentation', 'https://developers.google.com/identity/protocols/oauth2/web-server' ),
						),
						'note'     => 'OAuth and service-account credentials are different authentication methods. Do not paste an OAuth secret into the service-account JSON field.',
					),
					array(
						'title'    => '6. Google Merchant Center',
						'platform' => 'Merchant Center',
						'path'     => array( 'Merchant Center', 'Account', 'Merchant ID' ),
						'steps'    => array(
							'Open Merchant Center and select your merchant account.',
							'Find its numeric Merchant ID in the account information or interface header. Enter it under "Merchant Center ID" on the left.',
							'Grant the Google identity used for Merchant requests access to that Merchant account and ensure the required Merchant API is enabled if applicable.',
							'Click Save Merchant; a credential test does not guarantee that product offers are available in every Merchant API.',
						),
						'links'    => array(
							array( 'Open Merchant Center', 'https://merchants.google.com/' ),
							array( 'Merchant Center help', 'https://support.google.com/merchants/' ),
						),
					),
				);
			case 'bing':
				return array(
					array(
						'title'    => 'Bing Webmaster Tools: verified site + API key',
						'open'     => true,
						'platform' => 'Bing Webmaster Tools',
						'path'     => array( 'Settings (top right)', 'API Access', 'API Key' ),
						'steps'    => array(
							'Sign in to Bing Webmaster Tools and add/verify your site if needed.',
							'Copy the exact verified site address into "Verified URL" on the left.',
							'Open Settings (top right) > API Access > API Key. Accept the terms or generate a key only if one does not exist.',
							'Paste the key on the left, click Save Bing, and then Test Bing.',
						),
						'links'    => array(
							array( 'Open Bing Webmaster Tools', 'https://www.bing.com/webmasters/home' ),
							array( 'Official Bing API key instructions', 'https://learn.microsoft.com/en-us/bingwebmaster/getting-access' ),
						),
						'note'     => 'Keys belong to the Bing user, not a single website. An existing saved key should be kept: leave its password field blank.',
					),
				);
			case 'market':
				return array(
					array(
						'title'    => 'YouTube Data API v3',
						'open'     => true,
						'platform' => 'Google Cloud',
						'path'     => array( 'APIs & Services', 'Library', 'YouTube Data API v3', 'Enable' ),
						'steps'    => array(
							'Select your Google Cloud project, open APIs & Services > Library and enable YouTube Data API v3.',
							'Open APIs & Services > Credentials, create an API key, and restrict it to the necessary API where supported.',
							'Copy the key into the YouTube field on the left and click Save Market providers.',
						),
						'links'    => array(
							array( 'Enable YouTube API', 'https://console.cloud.google.com/apis/library/youtube.googleapis.com' ),
							array( 'Open Google Cloud credentials', 'https://console.cloud.google.com/apis/credentials' ),
						),
					),
					array(
						'title'    => 'SerpApi (optional / external quota)',
						'open'     => true,
						'platform' => 'SerpApi',
						'path'     => array( 'Dashboard', 'API Key', 'Copy' ),
						'steps'    => array(
							'Sign in to your SerpApi dashboard, then open the API Key section.',
							'Copy your private API key into the SerpApi field on the left.',
							'Save Market providers and review SerpApi quotas or billing before running Market research.',
						),
						'links'    => array(
							array( 'Open SerpApi API key page', 'https://serpapi.com/manage-api-key' ),
							array( 'SerpApi documentation', 'https://serpapi.com/search-api' ),
						),
						'note'     => 'These providers are optional. Leave unused keys blank. Opening this screen does not call either API.',
					),
				);
			case 'amazon':
				return array(
					array(
						'title'    => 'Amazon Associates Partner Tag (basic links)',
						'open'     => true,
						'platform' => 'Amazon Associates',
						'path'     => array( 'Associates Central', 'Tracking IDs', 'Copy Partner Tag' ),
						'steps'    => array(
							'Sign in to Amazon.es Associates Central and locate your Store/Tracking ID (also called Partner Tag).',
							'Paste the exact tag into "amazon.es Partner Tag" on the left and click Save Amazon.',
							'This tag alone is enough for tracked Amazon search/referral links; Creators API credentials are optional.',
						),
						'links'    => array(
							array( 'Amazon.es Associates Central', 'https://afiliados.amazon.es/' ),
							array( 'Official tracking tag help', 'https://afiliados.amazon.es/help/node/topic/G6253GFSARDQENZR' ),
						),
					),
					array(
						'title'    => 'Amazon Creators API (optional product information)',
						'platform' => 'Amazon Associates',
						'path'     => array( 'API access', 'Creators API credentials', 'Save' ),
						'steps'    => array(
							'If your Associates account is eligible for Creators API, obtain the Client ID and Client Secret from its API credentials area.',
							'Enter Client ID and Secret on the left, save them and click Test Creators API.',
							'If you only need affiliate links, do not create API credentials just to populate optional fields.',
						),
						'links'    => array(
							array( 'Amazon Associates portal', 'https://afiliados.amazon.es/' ),
						),
						'note'     => 'Never place affiliate API secrets in page templates or public code.',
					),
				);
			case 'pinterest':
				return array(
					array(
						'title'    => 'Pinterest Business OAuth and destination board',
						'open'     => true,
						'platform' => 'Pinterest Developers',
						'path'     => array( 'My apps', 'Manage app', 'Redirect URIs', 'Add' ),
						'steps'    => array(
							'Use a Pinterest Business account, then register or open your app in the developer portal.',
							'Copy the App ID and App Secret and add the exact callback URL shown on the left in your Pinterest app Redirect URIs.',
							'Click Save Pinterest, then Authorize with Pinterest OAuth. Do not generate a manual token unless you specifically need that alternative.',
							'After authorization, select a destination board when available, save again, then Test Pinterest.',
						),
						'links'    => array(
							array( 'Pinterest developer apps', 'https://developers.pinterest.com/apps/' ),
							array( 'Official Pinterest app setup', 'https://developers.pinterest.com/docs/getting-started/connect-app/' ),
						),
						'note'     => 'Pinterest reviews developer apps for API access. Exact callback URLs must match; if OAuth is already working, preserve its secrets.',
					),
					array(
						'title'    => 'Pinterest Merchant / Catalogs',
						'platform' => 'Pinterest Business settings',
						'path'     => array( 'Settings', 'Link to Pinterest', 'Claim website' ),
						'steps'    => array(
							'Open Pinterest Business settings and claim/verify your website using an approved method (HTML tag, DNS TXT, HTML file, or supported Merchant connection).',
							'If Pinterest provides a p:domain_verify value, enter only the verification code in the matching field on the left.',
							'Obtain the public Catalogs feed URL from your TaxoCommerce Distribution feed, then paste it into Pinterest Catalogs feed URL.',
							'Save Pinterest Merchant. Domain verification and catalog publishing are different operations.',
						),
						'links'    => array(
							array( 'Pinterest Business', 'https://www.pinterest.com/business/hub/' ),
							array( 'Official website claim instructions', 'https://help.pinterest.com/en/business/article/claim-your-website' ),
						),
					),
				);
		}
		return array();
	}
}
