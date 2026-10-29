<?php
/**
 * Amazon shared connection.
 *
 * Partner Tag is the baseline capability. Creators API is optional enrichment.
 */

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Connections_Amazon {

	const OPTION        = 'taxocommerce_connections_amazon';
	const LEGACY_OPTION = 'seo_amazon_creators_recipe_settings';
	const TOKEN_PREFIX  = 'tc_conn_amz_token_';

	public static function boot() {
		self::migrate_legacy_settings();
		self::register_legacy_helpers();
	}

	public static function defaults() {
		return array(
			'client_id'          => '',
			'client_secret'      => '',
			'credential_version' => '3.2',
			'partner_tag'        => '',
			'marketplace'        => 'www.amazon.es',
			'language'           => 'es_ES',
			'search_index'       => 'All',
			'item_count'         => 10,
		);
	}

	public static function migrate_legacy_settings() {
		$current = get_option( self::OPTION, false );
		if ( false !== $current ) {
			return;
		}
		$legacy = get_option( self::LEGACY_OPTION, false );
		if ( is_array( $legacy ) ) {
			update_option( self::OPTION, wp_parse_args( $legacy, self::defaults() ), false );
		}
	}

	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );

		// During migration, if the old plugin changed the legacy option after the
		// first copy, prefer that newer value and keep both stores synchronized.
		$legacy = get_option( self::LEGACY_OPTION, false );
		if ( is_array( $legacy ) ) {
			$legacy = wp_parse_args( $legacy, self::defaults() );
			if ( $legacy !== $settings ) {
				$settings = $legacy;
				update_option( self::OPTION, $settings, false );
			}
		}

		if ( defined( 'SEO_AMAZON_CLIENT_ID' ) && SEO_AMAZON_CLIENT_ID ) {
			$settings['client_id'] = SEO_AMAZON_CLIENT_ID;
		}
		if ( defined( 'SEO_AMAZON_CLIENT_SECRET' ) && SEO_AMAZON_CLIENT_SECRET ) {
			$settings['client_secret'] = SEO_AMAZON_CLIENT_SECRET;
		}
		if ( defined( 'SEO_AMAZON_CREDENTIAL_VERSION' ) && SEO_AMAZON_CREDENTIAL_VERSION ) {
			$settings['credential_version'] = SEO_AMAZON_CREDENTIAL_VERSION;
		}
		if ( defined( 'SEO_AMAZON_PARTNER_TAG' ) && SEO_AMAZON_PARTNER_TAG ) {
			$settings['partner_tag'] = SEO_AMAZON_PARTNER_TAG;
		}

		return $settings;
	}

	public static function save( $input ) {
		$current = self::settings();
		$input   = is_array( $input ) ? $input : array();

		$new_secret = sanitize_text_field( (string) ( $input['client_secret'] ?? '' ) );
		$posted_tag = sanitize_text_field( (string) ( $input['partner_tag'] ?? '' ) );
		$clear_tag  = ! empty( $input['clear_partner_tag'] );

		$settings = array(
			'client_id'          => sanitize_text_field( (string) ( $input['client_id'] ?? '' ) ),
			'client_secret'      => '' !== $new_secret ? $new_secret : (string) ( $current['client_secret'] ?? '' ),
			'credential_version' => '3.2',
			'partner_tag'        => $clear_tag ? '' : ( '' !== trim( $posted_tag ) ? $posted_tag : (string) ( $current['partner_tag'] ?? '' ) ),
			'marketplace'        => 'www.amazon.es',
			'language'           => 'es_ES',
			'search_index'       => sanitize_text_field( (string) ( $input['search_index'] ?? 'All' ) ),
			'item_count'         => max( 1, min( 10, absint( $input['item_count'] ?? 10 ) ) ),
		);

		update_option( self::OPTION, $settings, false );

		// Keep the legacy option synchronized while old Templates/Assistant or
		// the monolith may still read it directly.
		update_option( self::LEGACY_OPTION, $settings, false );

		delete_transient( self::token_cache_key( $current ) );
		delete_transient( self::token_cache_key( $settings ) );

		return $settings;
	}

	public static function affiliate_ready() {
		$s = self::settings();
		return '' !== trim( (string) ( $s['partner_tag'] ?? '' ) );
	}

	public static function creators_ready() {
		$s = self::settings();
		return '' !== trim( (string) ( $s['partner_tag'] ?? '' ) )
			&& '' !== trim( (string) ( $s['client_id'] ?? '' ) )
			&& '' !== trim( (string) ( $s['client_secret'] ?? '' ) );
	}

	public static function affiliate_search_url( $query ) {
		$s = self::settings();
		$query = trim( wp_strip_all_tags( (string) $query ) );
		$tag   = sanitize_text_field( (string) ( $s['partner_tag'] ?? '' ) );

		if ( '' === $query || '' === $tag ) {
			return '';
		}

		$marketplace = trim( (string) ( $s['marketplace'] ?? 'www.amazon.es' ) );
		$marketplace = preg_replace( '#^https?://#i', '', $marketplace );
		$marketplace = trim( (string) $marketplace, "/ \t\n\r\0\x0B" );
		if ( '' === $marketplace ) {
			$marketplace = 'www.amazon.es';
		}

		return add_query_arg(
			array(
				'k'   => $query,
				'tag' => $tag,
			),
			'https://' . $marketplace . '/s'
		);
	}

	public static function token_endpoint() {
		return 'https://api.amazon.co.uk/auth/o2/token';
	}

	public static function token_cache_key( $settings = null ) {
		$s = is_array( $settings ) ? $settings : self::settings();
		return self::TOKEN_PREFIX . md5(
			(string) ( $s['client_id'] ?? '' ) . '|' .
			(string) ( $s['credential_version'] ?? '' ) . '|' .
			(string) ( $s['partner_tag'] ?? '' )
		);
	}

	public static function access_token( $force = false ) {
		$s = self::settings();

		if ( ! self::creators_ready() ) {
			return new WP_Error(
				'amazon_credentials_missing',
				__( 'Creators API is not configured. Affiliate links can still work with only a Partner Tag.', 'taxocommerce-google-bing' )
			);
		}

		$key = self::token_cache_key( $s );
		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		$response = wp_remote_post(
			self::token_endpoint(),
			array(
				'timeout' => 25,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'grant_type'    => 'client_credentials',
						'client_id'     => $s['client_id'],
						'client_secret' => $s['client_secret'],
						'scope'         => 'creatorsapi::default',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || empty( $data['access_token'] ) ) {
			$message = $data['error_description'] ?? ( $data['error'] ?? __( 'Amazon did not return an access_token.', 'taxocommerce-google-bing' ) );
			return new WP_Error( 'amazon_token_error', 'Amazon OAuth HTTP ' . $code . ': ' . sanitize_text_field( (string) $message ) );
		}

		$ttl = ! empty( $data['expires_in'] ) ? max( 60, (int) $data['expires_in'] - 120 ) : 3300;
		set_transient( $key, (string) $data['access_token'], $ttl );
		return (string) $data['access_token'];
	}

	public static function api_post( $path, $payload ) {
		$s = self::settings();
		if ( empty( $s['partner_tag'] ) ) {
			return new WP_Error( 'amazon_partner_tag_missing', __( 'Falta el Partner Tag de Amazon Associates.', 'taxocommerce-google-bing' ) );
		}

		$token = self::access_token( false );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$payload = is_array( $payload ) ? $payload : array();
		$payload['marketplace'] = $s['marketplace'];
		$payload['partnerTag']  = $s['partner_tag'];

		$response = wp_remote_post(
			'https://creatorsapi.amazon' . '/' . ltrim( (string) $path, '/' ),
			array(
				'timeout' => 35,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
					'x-marketplace' => $s['marketplace'],
				),
				'body' => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$message = $data['errors'][0]['message'] ?? ( $data['message'] ?? __( 'Error de Creators API.', 'taxocommerce-google-bing' ) );
			return new WP_Error( 'amazon_api_error', 'Amazon HTTP ' . $code . ': ' . sanitize_text_field( (string) $message ) );
		}

		return is_array( $data ) ? $data : array();
	}

	public static function search_items( $keywords, $args = array() ) {
		$s = self::settings();
		$args = wp_parse_args(
			$args,
			array(
				'search_index' => $s['search_index'],
				'item_count'   => $s['item_count'],
				'item_page'    => 1,
				'sort_by'      => 'Relevance',
			)
		);

		$keywords = trim( wp_strip_all_tags( (string) $keywords ) );
		if ( '' === $keywords ) {
			return new WP_Error( 'amazon_keywords_missing', __( 'Enter a search query for Amazon.', 'taxocommerce-google-bing' ) );
		}

		return self::api_post(
			'/catalog/v1/searchItems',
			array(
				'keywords'              => $keywords,
				'searchIndex'           => sanitize_text_field( (string) $args['search_index'] ),
				'itemCount'             => max( 1, min( 10, (int) $args['item_count'] ) ),
				'itemPage'              => max( 1, min( 10, (int) $args['item_page'] ) ),
				'sortBy'                => sanitize_text_field( (string) $args['sort_by'] ),
				'languagesOfPreference' => array( $s['language'] ),
				'resources'             => array(
					'images.primary.medium',
					'itemInfo.title',
					'itemInfo.features',
					'itemInfo.byLineInfo',
					'itemInfo.productInfo',
					'itemInfo.technicalInfo',
					'browseNodeInfo.browseNodes',
					'browseNodeInfo.browseNodes.ancestor',
					'browseNodeInfo.browseNodes.salesRank',
					'offersV2.listings.price',
					'parentASIN',
					'searchRefinements',
				),
			)
		);
	}

	private static function value( $array, $path, $default = '' ) {
		$current = $array;
		foreach ( explode( '.', (string) $path ) as $part ) {
			if ( ! is_array( $current ) || ! array_key_exists( $part, $current ) ) {
				return $default;
			}
			$current = $current[ $part ];
		}
		return $current;
	}

	private static function flatten_ancestor( $node ) {
		$names   = array();
		$current = $node['ancestor'] ?? null;
		$guard   = 0;

		while ( is_array( $current ) && $guard < 12 ) {
			if ( ! empty( $current['displayName'] ) ) {
				$names[] = $current['displayName'];
			}
			$current = $current['ancestor'] ?? null;
			$guard++;
		}
		return array_reverse( $names );
	}

	public static function normalize_preview( $response ) {
		$items = isset( $response['searchResult']['items'] ) && is_array( $response['searchResult']['items'] )
			? $response['searchResult']['items']
			: array();

		$out = array(
			'products'           => array(),
			'categories'         => array(),
			'total_result_count' => (int) ( $response['searchResult']['totalResultCount'] ?? 0 ),
			'search_url'         => (string) ( $response['searchResult']['searchURL'] ?? '' ),
		);

		foreach ( $items as $item ) {
			$asin     = (string) ( $item['asin'] ?? '' );
			$title    = self::value( $item, 'itemInfo.title.displayValue', '' );
			$features = self::value( $item, 'itemInfo.features.displayValues', array() );
			$image    = self::value( $item, 'images.primary.medium.url', '' );
			$brand    = self::value( $item, 'itemInfo.byLineInfo.brand.displayValue', '' );
			$price    = self::value( $item, 'offersV2.listings.0.price.displayAmount', '' );
			$url      = (string) ( $item['detailPageURL'] ?? '' );
			$nodes    = self::value( $item, 'browseNodeInfo.browseNodes', array() );

			if ( ! is_array( $features ) ) {
				$features = array();
			}
			if ( ! is_array( $nodes ) ) {
				$nodes = array();
			}

			$product_nodes = array();
			foreach ( $nodes as $node ) {
				if ( empty( $node['id'] ) || empty( $node['displayName'] ) ) {
					continue;
				}
				$path = array_merge( self::flatten_ancestor( $node ), array( $node['displayName'] ) );
				$category = array(
					'amazon_node_id' => (string) $node['id'],
					'name'           => (string) $node['displayName'],
					'context_name'   => ! empty( $node['contextFreeName'] ) ? (string) $node['contextFreeName'] : (string) $node['displayName'],
					'path'           => implode( ' > ', array_filter( $path ) ),
					'sales_rank'     => isset( $node['salesRank'] ) ? (int) $node['salesRank'] : null,
				);
				$product_nodes[] = $category;
				$out['categories'][ $category['amazon_node_id'] ] = $category;
			}

			$out['products'][] = array(
				'external_id' => $asin,
				'asin'        => $asin,
				'title'       => $title,
				'brand'       => $brand,
				'features'    => array_slice( array_values( array_filter( array_map( 'strval', $features ) ) ), 0, 8 ),
				'image_url'   => $image,
				'price'       => $price,
				'url'         => $url,
				'categories'  => $product_nodes,
				'parent_asin' => (string) ( $item['parentASIN'] ?? '' ),
			);
		}

		$out['categories'] = array_values( $out['categories'] );
		return $out;
	}

	public static function register_legacy_helpers() {
		if ( ! function_exists( 'seo_supplier_recipe_amazon_settings' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy callable name is used by existing modules; changing it requires a coordinated migration.
			function seo_supplier_recipe_amazon_settings() {
				return TaxoCommerce_Connections_Amazon::settings();
			}
		}
		if ( ! function_exists( 'seo_supplier_recipe_amazon_affiliate_ready' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy callable name is used by existing modules; changing it requires a coordinated migration.
			function seo_supplier_recipe_amazon_affiliate_ready() {
				return TaxoCommerce_Connections_Amazon::affiliate_ready();
			}
		}
		if ( ! function_exists( 'seo_supplier_recipe_amazon_creators_ready' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy callable name is used by existing modules; changing it requires a coordinated migration.
			function seo_supplier_recipe_amazon_creators_ready() {
				return TaxoCommerce_Connections_Amazon::creators_ready();
			}
		}
		if ( ! function_exists( 'seo_supplier_recipe_amazon_affiliate_search_url' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy callable name is used by existing modules; changing it requires a coordinated migration.
			function seo_supplier_recipe_amazon_affiliate_search_url( $query ) {
				return TaxoCommerce_Connections_Amazon::affiliate_search_url( $query );
			}
		}
		if ( ! function_exists( 'seo_supplier_recipe_amazon_get_access_token' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy callable name is used by existing modules; changing it requires a coordinated migration.
			function seo_supplier_recipe_amazon_get_access_token( $force = false ) {
				return TaxoCommerce_Connections_Amazon::access_token( $force );
			}
		}
		if ( ! function_exists( 'seo_supplier_recipe_amazon_search_items' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy callable name is used by existing modules; changing it requires a coordinated migration.
			function seo_supplier_recipe_amazon_search_items( $keywords, $args = array() ) {
				return TaxoCommerce_Connections_Amazon::search_items( $keywords, $args );
			}
		}
		if ( ! function_exists( 'seo_supplier_recipe_amazon_normalize_preview' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy callable name is used by existing modules; changing it requires a coordinated migration.
			function seo_supplier_recipe_amazon_normalize_preview( $response ) {
				return TaxoCommerce_Connections_Amazon::normalize_preview( $response );
			}
		}
	}
}
