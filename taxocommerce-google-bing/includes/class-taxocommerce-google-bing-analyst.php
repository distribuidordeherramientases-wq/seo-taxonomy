<?php
/**
 * Strategic analyst built on saved Insights data.
 *
 * It never downloads external data while rendering or exporting. It consumes
 * saved Search/Analytics/Bing snapshots, Market/Merchant evidence and the
 * Executive Report, then converts them into a short prioritized work plan.
 *
 * @package TaxoCommerceInsights
 */

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Google_Bing_Analyst {

	const EXPORT_ACTION = 'tgb_export_analyst';

	public static function register() {
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( __CLASS__, 'export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function enqueue_assets() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
		$page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
		$tab = isset( $_GET['tgb_tab'] ) && is_scalar( $_GET['tgb_tab'] ) ? sanitize_key( wp_unslash( $_GET['tgb_tab'] ) ) : '';
		if ( 'taxocommerce-google-bing' !== $page || 'analyst' !== $tab ) {
			return;
		}
		wp_enqueue_style(
			'taxocommerce-insights-analyst',
			TAXOCOMMERCE_GOOGLE_BING_URL . 'assets/analyst.css',
			array(),
			TAXOCOMMERCE_GOOGLE_BING_VERSION
		);
	}

	/**
	 * Stable local-only contract for future Editor and other consumers.
	 *
	 * @return array
	 */
	public static function report() {
		$executive = TaxoCommerce_Google_Bing_Executive::report();
		$search    = self::latest_snapshot( 'search' );

		$where = self::where_we_are( $executive, $search );
		$market = self::market_position();
		$directives = self::direction( $executive, $search, $market );

		return array(
			'schema' => array(
				'name'    => 'taxocommerce-insights-analyst',
				'version' => 1,
			),
			'generated_at' => time(),
			'where_we_are' => $where,
			'market'       => $market,
			'direction'    => $directives,
			'editor_contract' => array(
				'schema' => 'taxocommerce-editor-opportunities-v1',
				'rows'   => array_values(
					array_filter(
						(array) $directives['rows'],
						static function ( $row ) {
							return in_array( (string) ( $row['bucket'] ?? '' ), array( 'NOW', 'NEXT' ), true );
						}
					)
				),
			),
			'notes' => array(
				'The Analyst reads saved data only; opening this screen never calls Google, Bing, Merchant, SerpApi, YouTube or another provider.',
				'Market evidence describes saved external signals. It is not a direct competitor ranking unless the underlying source explicitly provides one.',
				'Search Console query snapshots do not preserve query-to-page pairs, so suggested destinations are semantic catalog matches, not claimed Google landing pages.',
				'NOW requires direct demand evidence; market-only or trend-only signals cannot be promoted to NOW by themselves.',
			),
		);
	}

	private static function latest_snapshot( $source ) {
		if ( ! class_exists( 'TaxoCommerce_Google_Bing_Reports' ) ) {
			return array();
		}
		$entries = TaxoCommerce_Google_Bing_Reports::saved_reports( $source );
		if ( ! is_array( $entries ) || ! $entries ) {
			return array();
		}
		$latest = array();
		$stamp  = 0;
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$current = absint( $entry['downloaded_at'] ?? 0 );
			if ( $current >= $stamp ) {
				$latest = $entry;
				$stamp  = $current;
			}
		}
		return $latest;
	}

	private static function where_we_are( $executive, $search ) {
		$overview = (array) ( $executive['overview'] ?? array() );
		$search_overview = (array) ( $overview['google_search'] ?? array() );
		$analytics = (array) ( $overview['analytics'] ?? array() );
		$bing = (array) ( $overview['bing'] ?? array() );

		$signals = array();
		if ( ! empty( $search_overview['available'] ) ) {
			$signals[] = self::directional_signal(
				(float) ( $search_overview['clicks'] ?? 0 ),
				(float) ( $search_overview['previous']['clicks'] ?? 0 ),
				false
			);
			$signals[] = self::directional_signal(
				(float) ( $search_overview['impressions'] ?? 0 ),
				(float) ( $search_overview['previous']['impressions'] ?? 0 ),
				false
			);
			$signals[] = self::directional_signal(
				(float) ( $search_overview['position'] ?? 0 ),
				(float) ( $search_overview['previous']['position'] ?? 0 ),
				true
			);
		}
		if ( ! empty( $analytics['available'] ) ) {
			$signals[] = self::directional_signal(
				(float) ( $analytics['sessions'] ?? 0 ),
				(float) ( $analytics['previous']['sessions'] ?? 0 ),
				false
			);
			$signals[] = self::directional_signal(
				(float) ( $analytics['purchases'] ?? 0 ),
				(float) ( $analytics['previous']['purchases'] ?? 0 ),
				false
			);
		}
		if ( ! empty( $bing['available'] ) ) {
			$signals[] = self::directional_signal(
				(float) ( $bing['clicks'] ?? 0 ),
				(float) ( $bing['previous']['clicks'] ?? 0 ),
				false
			);
		}
		$signals = array_values( array_filter( $signals, static function ( $value ) { return null !== $value; } ) );
		$balance = array_sum( $signals );
		$momentum = 'insufficient_data';
		if ( $signals ) {
			$momentum = $balance >= 2 ? 'improving' : ( $balance <= -2 ? 'declining' : 'mixed' );
		}

		$queries = array();
		foreach ( array_slice( (array) ( $search['data']['queries'] ?? array() ), 0, 15 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$queries[] = array(
				'query'       => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
				'clicks'      => absint( $row['clicks'] ?? 0 ),
				'impressions' => absint( $row['impressions'] ?? 0 ),
				'ctr'         => (float) ( $row['ctr'] ?? 0 ),
				'position'    => (float) ( $row['position'] ?? 0 ),
			);
		}

		return array(
			'momentum' => $momentum,
			'overview' => $overview,
			'content'  => (array) ( $executive['content'] ?? array() ),
			'opportunities' => (array) ( $executive['opportunities']['summary'] ?? array() ),
			'top_queries' => $queries,
			'snapshot' => array(
				'downloaded_at' => absint( $search['downloaded_at'] ?? 0 ),
				'current'       => (array) ( $search['current'] ?? array() ),
				'previous'      => (array) ( $search['previous'] ?? array() ),
			),
		);
	}

	private static function directional_signal( $current, $previous, $lower_is_better ) {
		if ( 0.0 === (float) $current && 0.0 === (float) $previous ) {
			return null;
		}
		if ( (float) $current === (float) $previous ) {
			return 0;
		}
		if ( $lower_is_better ) {
			return $current < $previous ? 1 : -1;
		}
		return $current > $previous ? 1 : -1;
	}

	private static function market_position() {
		$empty = array(
			'available'     => false,
			'summary'       => array(),
			'global_rollup' => array(),
			'categories'    => array(),
			'providers'     => array(),
		);
		if ( ! class_exists( 'TaxoCommerce_Google_Bing_Market' ) ) {
			return $empty;
		}

		global $wpdb;
		$tables = TaxoCommerce_Google_Bing_Market::tables();
		$categories_table = esc_sql( (string) ( $tables['categories'] ?? '' ) );
		$sources_table    = esc_sql( (string) ( $tables['sources'] ?? '' ) );
		if ( '' === $categories_table || '' === $sources_table ) {
			return $empty;
		}
		$categories_found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $categories_table ) ) );
		$sources_found    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $sources_table ) ) );
		if ( $categories_found !== $categories_table || $sources_found !== $sources_table ) {
			return $empty;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal plugin-owned table names are escaped above; query contains no user input.
		$rows = (array) $wpdb->get_results(
			"SELECT c.id,c.term_id,c.name,c.country,c.scan_status,c.last_scanned_at,
			        COUNT(s.id) AS evidence,
			        SUM(CASE WHEN s.provider='google_search' THEN 1 ELSE 0 END) AS own_search,
			        SUM(CASE WHEN s.provider='merchant' THEN 1 ELSE 0 END) AS merchant,
			        SUM(CASE WHEN s.source_type='offer' THEN 1 ELSE 0 END) AS offers,
			        SUM(CASE WHEN s.provider<>'google_search' THEN 1 ELSE 0 END) AS external_evidence,
			        GROUP_CONCAT(DISTINCT s.provider ORDER BY s.provider SEPARATOR ',') AS providers
			 FROM {$categories_table} c
			 LEFT JOIN {$sources_table} s ON s.category_id=c.id AND s.evidence_level<>'stale'
			 WHERE c.scan_status<>'archived'
			 GROUP BY c.id,c.term_id,c.name,c.country,c.scan_status,c.last_scanned_at
			 ORDER BY external_evidence DESC,merchant DESC,evidence DESC,c.id ASC
			 LIMIT 40",
			ARRAY_A
		);

		$categories = array();
		$provider_counts = array();
		foreach ( $rows as $row ) {
			$providers = array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) ( $row['providers'] ?? '' ) ) ) ) );
			foreach ( $providers as $provider ) {
				$provider_counts[ $provider ] = (int) ( $provider_counts[ $provider ] ?? 0 ) + 1;
			}
			$term_id = absint( $row['term_id'] ?? 0 );
			$term = $term_id ? get_term( $term_id, 'product_cat' ) : null;
			$product_count = ( $term && ! is_wp_error( $term ) ) ? absint( $term->count ) : 0;
			$external = absint( $row['external_evidence'] ?? 0 );
			$merchant = absint( $row['merchant'] ?? 0 );
			$link = $term && ! is_wp_error( $term ) ? get_term_link( $term ) : '';
			$categories[] = array(
				'market_category_id' => absint( $row['id'] ?? 0 ),
				'term_id'            => $term_id,
				'name'               => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
				'country'            => sanitize_text_field( (string) ( $row['country'] ?? '' ) ),
				'scan_status'        => sanitize_key( (string) ( $row['scan_status'] ?? '' ) ),
				'last_scanned_at'    => sanitize_text_field( (string) ( $row['last_scanned_at'] ?? '' ) ),
				'product_count'      => $product_count,
				'evidence'           => absint( $row['evidence'] ?? 0 ),
				'own_search'         => absint( $row['own_search'] ?? 0 ),
				'external_evidence'  => $external,
				'merchant'           => $merchant,
				'offers'             => absint( $row['offers'] ?? 0 ),
				'providers'          => $providers,
				'url'                => is_wp_error( $link ) ? '' : (string) $link,
			);
		}
		arsort( $provider_counts );

		// Merchant Best Sellers is a market-demand signal, not a claim about a
		// seller's complete market share. Keep the original rank/demand labels.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal plugin-owned tables, fixed read-only query, no user input.
		$merchant_rows = (array) $wpdb->get_results(
			"SELECT c.term_id,c.name,s.title,s.metadata_json,s.updated_at
			 FROM {$sources_table} s
			 INNER JOIN {$categories_table} c ON c.id=s.category_id
			 WHERE c.scan_status<>'archived'
			   AND s.provider='merchant'
			   AND s.evidence_level<>'stale'
			 ORDER BY s.updated_at DESC,s.id DESC
			 LIMIT 30",
			ARRAY_A
		);
		$merchant_leaders = array();
		foreach ( $merchant_rows as $merchant_row ) {
			$metadata = json_decode( (string) ( $merchant_row['metadata_json'] ?? '' ), true );
			$metadata = is_array( $metadata ) ? $metadata : array();
			$merchant_leaders[] = array(
				'term_id'         => absint( $merchant_row['term_id'] ?? 0 ),
				'category'        => sanitize_text_field( (string) ( $merchant_row['name'] ?? '' ) ),
				'title'           => sanitize_text_field( (string) ( $merchant_row['title'] ?? '' ) ),
				'brand'           => sanitize_text_field( (string) ( $metadata['brand'] ?? '' ) ),
				'rank'            => absint( $metadata['rank'] ?? 0 ),
				'relative_demand' => sanitize_text_field( (string) ( $metadata['relative_demand'] ?? '' ) ),
				'report_date'     => sanitize_text_field( (string) ( $metadata['report_date'] ?? '' ) ),
				'updated_at'      => sanitize_text_field( (string) ( $merchant_row['updated_at'] ?? '' ) ),
			);
		}

		// Presence of external domains in saved Market evidence. This is an
		// observation-frequency view, never a claimed ranking or market share.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal plugin-owned tables, fixed read-only query, no user input.
		$external_rows = (array) $wpdb->get_results(
			"SELECT c.id AS category_id,c.name,s.source_url
			 FROM {$sources_table} s
			 INNER JOIN {$categories_table} c ON c.id=s.category_id
			 WHERE c.scan_status<>'archived'
			   AND s.provider<>'google_search'
			   AND s.evidence_level<>'stale'
			   AND s.source_url<>''
			 ORDER BY s.updated_at DESC,s.id DESC
			 LIMIT 600",
			ARRAY_A
		);
		$own_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$domain_map = array();
		foreach ( $external_rows as $external_row ) {
			$host = strtolower( (string) wp_parse_url( (string) ( $external_row['source_url'] ?? '' ), PHP_URL_HOST ) );
			$host = preg_replace( '/^www\./', '', $host );
			if ( '' === $host || $host === preg_replace( '/^www\./', '', $own_host ) ) {
				continue;
			}
			if ( ! isset( $domain_map[ $host ] ) ) {
				$domain_map[ $host ] = array(
					'domain' => $host,
					'mentions' => 0,
					'categories' => array(),
				);
			}
			$domain_map[ $host ]['mentions']++;
			$category_name = sanitize_text_field( (string) ( $external_row['name'] ?? '' ) );
			if ( '' !== $category_name ) {
				$domain_map[ $host ]['categories'][ $category_name ] = true;
			}
		}
		$competitors = array();
		foreach ( $domain_map as $item ) {
			$item['category_count'] = count( $item['categories'] );
			$item['categories'] = array_slice( array_keys( $item['categories'] ), 0, 6 );
			$competitors[] = $item;
		}
		usort(
			$competitors,
			static function ( $left, $right ) {
				$count = (int) $right['mentions'] <=> (int) $left['mentions'];
				return 0 !== $count ? $count : (int) $right['category_count'] <=> (int) $left['category_count'];
			}
		);
		$competitors = array_slice( $competitors, 0, 20 );

		// Aggregate all saved category research into a global business view.
		// This is the Analyst layer: it summarizes stored product/category evidence
		// and never re-runs Market or calls providers.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal plugin-owned table, fixed aggregate query, no user input.
		$status_totals = (array) $wpdb->get_row(
			"SELECT
				COUNT(*) AS total,
				SUM(CASE WHEN scan_status='complete' THEN 1 ELSE 0 END) AS complete,
				SUM(CASE WHEN scan_status='partial' THEN 1 ELSE 0 END) AS partial,
				SUM(CASE WHEN scan_status='pending' THEN 1 ELSE 0 END) AS pending,
				SUM(CASE WHEN scan_status='error' THEN 1 ELSE 0 END) AS errors
			 FROM {$categories_table}
			 WHERE scan_status<>'archived'",
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal plugin-owned tables, fixed local-only query, no user input.
		$product_signal_rows = (array) $wpdb->get_results(
			"SELECT c.term_id,c.name AS category_name,s.source_type,s.provider,s.title,s.metadata_json,s.updated_at
			 FROM {$sources_table} s
			 INNER JOIN {$categories_table} c ON c.id=s.category_id
			 WHERE c.scan_status<>'archived'
			   AND s.evidence_level<>'stale'
			   AND s.source_type IN ('offer','market_signal')
			 ORDER BY s.updated_at DESC,s.id DESC
			 LIMIT 2000",
			ARRAY_A
		);

		$product_map = array();
		$brand_map = array();
		$seller_map = array();
		foreach ( $product_signal_rows as $signal ) {
			$title = sanitize_text_field( (string) ( $signal['title'] ?? '' ) );
			if ( '' === $title ) {
				continue;
			}
			$metadata = json_decode( (string) ( $signal['metadata_json'] ?? '' ), true );
			$metadata = is_array( $metadata ) ? $metadata : array();
			$key = self::normalize( $title );
			if ( '' === $key ) {
				continue;
			}
			if ( ! isset( $product_map[ $key ] ) ) {
				$product_map[ $key ] = array(
					'title'      => $title,
					'mentions'   => 0,
					'categories' => array(),
					'providers'  => array(),
					'brand'      => '',
					'best_rank'  => 0,
				);
			}
			$product_map[ $key ]['mentions']++;
			$category_name = sanitize_text_field( (string) ( $signal['category_name'] ?? '' ) );
			if ( '' !== $category_name ) {
				$product_map[ $key ]['categories'][ $category_name ] = true;
			}
			$provider = sanitize_key( (string) ( $signal['provider'] ?? '' ) );
			if ( '' !== $provider ) {
				$product_map[ $key ]['providers'][ $provider ] = true;
			}
			$brand = sanitize_text_field( (string) ( $metadata['brand'] ?? '' ) );
			if ( '' !== $brand ) {
				$product_map[ $key ]['brand'] = $brand;
				$brand_key = self::normalize( $brand );
				if ( '' !== $brand_key ) {
					if ( ! isset( $brand_map[ $brand_key ] ) ) {
						$brand_map[ $brand_key ] = array( 'brand' => $brand, 'mentions' => 0, 'products' => array(), 'categories' => array() );
					}
					$brand_map[ $brand_key ]['mentions']++;
					$brand_map[ $brand_key ]['products'][ $title ] = true;
					if ( '' !== $category_name ) {
						$brand_map[ $brand_key ]['categories'][ $category_name ] = true;
					}
				}
			}
			$rank = absint( $metadata['rank'] ?? 0 );
			if ( $rank && ( 0 === (int) $product_map[ $key ]['best_rank'] || $rank < (int) $product_map[ $key ]['best_rank'] ) ) {
				$product_map[ $key ]['best_rank'] = $rank;
			}
			$seller = sanitize_text_field( (string) ( $metadata['seller'] ?? '' ) );
			if ( '' !== $seller ) {
				$seller_key = self::normalize( $seller );
				if ( '' !== $seller_key ) {
					if ( ! isset( $seller_map[ $seller_key ] ) ) {
						$seller_map[ $seller_key ] = array( 'seller' => $seller, 'mentions' => 0, 'products' => array(), 'categories' => array() );
					}
					$seller_map[ $seller_key ]['mentions']++;
					$seller_map[ $seller_key ]['products'][ $title ] = true;
					if ( '' !== $category_name ) {
						$seller_map[ $seller_key ]['categories'][ $category_name ] = true;
					}
				}
			}
		}

		$product_clusters = array();
		foreach ( $product_map as $product ) {
			$product['category_count'] = count( $product['categories'] );
			$product['categories'] = array_slice( array_keys( $product['categories'] ), 0, 5 );
			$product['providers'] = array_values( array_keys( $product['providers'] ) );
			$product_clusters[] = $product;
		}
		usort(
			$product_clusters,
			static function ( $left, $right ) {
				$mentions = (int) $right['mentions'] <=> (int) $left['mentions'];
				if ( 0 !== $mentions ) {
					return $mentions;
				}
				$left_rank = (int) ( $left['best_rank'] ?: 999999 );
				$right_rank = (int) ( $right['best_rank'] ?: 999999 );
				return $left_rank <=> $right_rank;
			}
		);
		$product_clusters = array_slice( $product_clusters, 0, 25 );

		$top_brands = array();
		foreach ( $brand_map as $brand ) {
			$brand['product_count'] = count( $brand['products'] );
			$brand['category_count'] = count( $brand['categories'] );
			$brand['categories'] = array_slice( array_keys( $brand['categories'] ), 0, 5 );
			unset( $brand['products'] );
			$top_brands[] = $brand;
		}
		usort( $top_brands, static function ( $left, $right ) { return (int) $right['mentions'] <=> (int) $left['mentions']; } );
		$top_brands = array_slice( $top_brands, 0, 20 );

		$top_sellers = array();
		foreach ( $seller_map as $seller ) {
			$seller['product_count'] = count( $seller['products'] );
			$seller['category_count'] = count( $seller['categories'] );
			$seller['categories'] = array_slice( array_keys( $seller['categories'] ), 0, 5 );
			unset( $seller['products'] );
			$top_sellers[] = $seller;
		}
		usort( $top_sellers, static function ( $left, $right ) { return (int) $right['mentions'] <=> (int) $left['mentions']; } );
		$top_sellers = array_slice( $top_sellers, 0, 20 );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal plugin-owned tables, fixed local-only query, no user input.
		$gap_rows = (array) $wpdb->get_results(
			"SELECT c.term_id,c.name,c.country,
				SUM(CASE WHEN s.source_type IN ('offer','market_signal') THEN 1 ELSE 0 END) AS product_signals,
				SUM(CASE WHEN s.provider='merchant' THEN 1 ELSE 0 END) AS merchant_signals,
				SUM(CASE WHEN s.source_type='offer' THEN 1 ELSE 0 END) AS offers
			 FROM {$categories_table} c
			 LEFT JOIN {$sources_table} s ON s.category_id=c.id AND s.evidence_level<>'stale'
			 WHERE c.scan_status<>'archived'
			 GROUP BY c.id,c.term_id,c.name,c.country
			 HAVING product_signals>0
			 ORDER BY product_signals DESC,merchant_signals DESC,c.id ASC
			 LIMIT 80",
			ARRAY_A
		);
		$catalog_gaps = array();
		foreach ( $gap_rows as $gap ) {
			$term_id = absint( $gap['term_id'] ?? 0 );
			$term = $term_id ? get_term( $term_id, 'product_cat' ) : null;
			$local_products = ( $term && ! is_wp_error( $term ) ) ? absint( $term->count ) : 0;
			$product_signals = absint( $gap['product_signals'] ?? 0 );
			$merchant_signals = absint( $gap['merchant_signals'] ?? 0 );
			$offers = absint( $gap['offers'] ?? 0 );
			$score = min( 100, max( 0, ( $product_signals * 4 ) + ( $merchant_signals * 3 ) + ( $offers * 2 ) - min( 30, $local_products * 2 ) ) );
			$link = $term && ! is_wp_error( $term ) ? get_term_link( $term ) : '';
			$catalog_gaps[] = array(
				'term_id'          => $term_id,
				'category'         => sanitize_text_field( (string) ( $gap['name'] ?? '' ) ),
				'country'          => sanitize_text_field( (string) ( $gap['country'] ?? '' ) ),
				'local_products'   => $local_products,
				'product_signals'  => $product_signals,
				'merchant_signals' => $merchant_signals,
				'offers'           => $offers,
				'gap_score'        => $score,
				'url'              => is_wp_error( $link ) ? '' : (string) $link,
			);
		}
		usort( $catalog_gaps, static function ( $left, $right ) { return (int) $right['gap_score'] <=> (int) $left['gap_score']; } );
		$catalog_gaps = array_slice( $catalog_gaps, 0, 20 );

		$coverage_total = absint( $status_totals['total'] ?? 0 );
		$coverage_done = absint( $status_totals['complete'] ?? 0 ) + absint( $status_totals['partial'] ?? 0 );
		$global_rollup = array(
			'coverage' => array(
				'total'       => $coverage_total,
				'complete'    => absint( $status_totals['complete'] ?? 0 ),
				'partial'     => absint( $status_totals['partial'] ?? 0 ),
				'pending'     => absint( $status_totals['pending'] ?? 0 ),
				'errors'      => absint( $status_totals['errors'] ?? 0 ),
				'coverage_pct'=> $coverage_total > 0 ? round( ( $coverage_done / $coverage_total ) * 100, 1 ) : 0.0,
			),
			'product_intelligence' => array(
				'signals'         => count( $product_signal_rows ),
				'unique_products' => count( $product_map ),
				'brands'          => count( $brand_map ),
				'sellers'         => count( $seller_map ),
			),
			'product_clusters' => $product_clusters,
			'top_brands'       => $top_brands,
			'top_sellers'      => $top_sellers,
			'catalog_gaps'     => $catalog_gaps,
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal plugin-owned table name, no user input.
		$total_categories = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$categories_table} WHERE scan_status<>'archived'" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal plugin-owned table name, no user input.
		$analyzed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$categories_table} WHERE scan_status IN ('complete','partial')" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal plugin-owned tables, fixed aggregate query, no user input.
		$totals = (array) $wpdb->get_row(
			"SELECT
				SUM(CASE WHEN s.provider<>'google_search' THEN 1 ELSE 0 END) AS external_evidence,
				SUM(CASE WHEN s.provider='merchant' THEN 1 ELSE 0 END) AS merchant_signals
			 FROM {$sources_table} s
			 INNER JOIN {$categories_table} c ON c.id=s.category_id
			 WHERE c.scan_status<>'archived' AND s.evidence_level<>'stale'",
			ARRAY_A
		);

		return array(
			'available' => true,
			'summary' => array(
				'categories'          => $total_categories,
				'analyzed_categories' => $analyzed,
				'external_evidence'   => absint( $totals['external_evidence'] ?? 0 ),
				'merchant_signals'    => absint( $totals['merchant_signals'] ?? 0 ),
			),
			'global_rollup'    => $global_rollup,
			'categories'       => $categories,
			'providers'        => $provider_counts,
			'merchant_leaders' => $merchant_leaders,
			'competitors'      => $competitors,
		);
	}

	private static function direction( $executive, $search, $market ) {
		$rows = array();

		foreach ( (array) ( $executive['opportunities']['rows'] ?? array() ) as $opportunity ) {
			$directive = self::page_directive( $opportunity );
			if ( $directive ) {
				$rows[] = $directive;
			}
		}

		foreach ( self::search_directives( $search ) as $directive ) {
			$rows[] = $directive;
		}
		foreach ( self::market_directives( $market ) as $directive ) {
			$rows[] = $directive;
		}
		foreach ( self::trend_directives( (array) ( $executive['demand']['trends']['items'] ?? array() ) ) as $directive ) {
			$rows[] = $directive;
		}

		$rows = self::deduplicate_directives( $rows );
		foreach ( $rows as &$row ) {
			$row['bucket'] = self::bucket( $row );
		}
		unset( $row );

		$order = array( 'NOW' => 0, 'NEXT' => 1, 'INVESTIGATE' => 2, 'WATCH' => 3, 'NO_ACTION' => 4 );
		usort(
			$rows,
			static function ( $left, $right ) use ( $order ) {
				$lb = $order[ (string) ( $left['bucket'] ?? 'NO_ACTION' ) ] ?? 9;
				$rb = $order[ (string) ( $right['bucket'] ?? 'NO_ACTION' ) ] ?? 9;
				if ( $lb !== $rb ) {
					return $lb <=> $rb;
				}
				return (int) ( $right['priority'] ?? 0 ) <=> (int) ( $left['priority'] ?? 0 );
			}
		);

		$summary = array(
			'total'       => count( $rows ),
			'now'         => 0,
			'next'        => 0,
			'investigate' => 0,
			'watch'       => 0,
			'no_action'   => 0,
		);
		foreach ( $rows as $row ) {
			$key = strtolower( (string) ( $row['bucket'] ?? 'NO_ACTION' ) );
			if ( isset( $summary[ $key ] ) ) {
				$summary[ $key ]++;
			}
		}

		return array(
			'summary' => $summary,
			'rows'    => array_slice( $rows, 0, 80 ),
		);
	}

	private static function page_directive( $row ) {
		if ( ! is_array( $row ) || empty( $row['url'] ) ) {
			return array();
		}
		$object_id = absint( $row['object_id'] ?? 0 );
		$type = sanitize_key( (string) ( $row['object_type'] ?? '' ) );
		$role = $object_id ? sanitize_key( (string) get_post_meta( $object_id, '_taxocommerce_core_seo_role', true ) ) : '';
		$action = 'IMPROVE_PAGE';
		$channel = 'content';
		if ( 'post' === $type ) {
			$action = 'IMPROVE_POST';
		} elseif ( 'product' === $type ) {
			$action = 'IMPROVE_PRODUCT';
			$channel = 'catalog';
		} elseif ( 'landing' === $role ) {
			$action = 'IMPROVE_LANDING';
			$channel = 'landing';
		} elseif ( in_array( $role, array( 'cluster', 'hub_primary', 'hub_secondary' ), true ) ) {
			$action = 'IMPROVE_ARCHITECTURE';
			$channel = 'seo';
		}

		$priority = max( 0, min( 100, absint( $row['priority'] ?? 0 ) ) );
		return array(
			'key'          => 'url|' . md5( (string) $row['url'] ),
			'priority'     => $priority,
			'action'       => $action,
			'action_label' => self::action_label( $action ),
			'channel'      => $channel,
			'topic'        => sanitize_text_field( (string) ( $row['title'] ?? $row['url'] ) ),
			'reason'       => sanitize_text_field( (string) ( $row['action'] ?? 'Existing URL has measurable search demand and should be reviewed.' ) ),
			'confidence'   => 'HIGH',
			'confidence_score' => 80,
			'demand_backed' => true,
			'execution_ready'=> true,
			'target'       => array(
				'type' => $type,
				'id'   => $object_id,
				'role' => $role,
				'title'=> sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
				'url'  => esc_url_raw( (string) $row['url'] ),
			),
			'evidence' => array(
				'Search impressions: ' . absint( $row['impressions'] ?? 0 ),
				'Search position: ' . number_format_i18n( (float) ( $row['position'] ?? 0 ), 1 ),
				'GA4 views: ' . absint( $row['views_ga4'] ?? 0 ),
				'Editorial readiness: ' . absint( $row['preparation'] ?? 0 ) . '/100',
			),
			'sources' => array( 'Google Search', 'Google Analytics', 'Existing content' ),
			'metrics' => array(
				'impressions' => absint( $row['impressions'] ?? 0 ),
				'clicks'      => absint( $row['clicks'] ?? 0 ),
				'ctr'         => (float) ( $row['ctr'] ?? 0 ),
				'position'    => (float) ( $row['position'] ?? 0 ),
				'views'       => absint( $row['views_ga4'] ?? 0 ),
				'readiness'   => absint( $row['preparation'] ?? 0 ),
			),
		);
	}

	private static function search_directives( $search ) {
		$queries = array_values( (array) ( $search['data']['queries'] ?? array() ) );
		if ( ! $queries ) {
			return array();
		}
		$queries = array_slice( $queries, 0, 180 );
		$max_impressions = 1.0;
		$buckets = array();
		foreach ( $queries as $row ) {
			$impressions = max( 0.0, (float) ( $row['impressions'] ?? 0 ) );
			$max_impressions = max( $max_impressions, $impressions );
			$bucket = self::position_bucket( (float) ( $row['position'] ?? 0 ) );
			if ( ! isset( $buckets[ $bucket ] ) ) {
				$buckets[ $bucket ] = array( 'clicks' => 0.0, 'impressions' => 0.0 );
			}
			$buckets[ $bucket ]['clicks'] += max( 0.0, (float) ( $row['clicks'] ?? 0 ) );
			$buckets[ $bucket ]['impressions'] += $impressions;
		}

		$out = array();
		foreach ( $queries as $row ) {
			$query = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
			$impressions = max( 0.0, (float) ( $row['impressions'] ?? 0 ) );
			$clicks = max( 0.0, (float) ( $row['clicks'] ?? 0 ) );
			$position = max( 0.0, (float) ( $row['position'] ?? 0 ) );
			$ctr = max( 0.0, (float) ( $row['ctr'] ?? 0 ) );
			if ( '' === $query || $impressions < 5 || $position <= 0 ) {
				continue;
			}
			$bucket = self::position_bucket( $position );
			$reference_ctr = ! empty( $buckets[ $bucket ]['impressions'] )
				? (float) $buckets[ $bucket ]['clicks'] / (float) $buckets[ $bucket ]['impressions']
				: 0.0;
			$ctr_gap = $reference_ctr > 0 ? ( $ctr - $reference_ctr ) / $reference_ctr : 0.0;
			$category = self::best_category( $query );
			$match = (float) ( $category['similarity'] ?? 0 );
			$intent = self::intent( $query );
			$demand = min( 100.0, ( log( 1 + $impressions ) / log( 1 + $max_impressions ) ) * 100.0 );
			$priority = (int) round(
				( $demand * 0.42 )
				+ ( self::position_opportunity( $position ) * 0.30 )
				+ ( self::ctr_need( $reference_ctr, $ctr_gap ) * 0.13 )
				+ ( ( $match >= 0.50 ? 90 : ( $match >= 0.32 ? 65 : 35 ) ) * 0.15 )
			);

			$action = 'INVESTIGATE_CATALOG';
			$channel = 'catalog';
			$ready = false;
			$reason = 'Search demand exists, but the destination is not sufficiently resolved.';
			if ( 'news' === $intent ) {
				$action = 'CREATE_NEWS';
				$channel = 'content';
				$ready = true;
				$reason = 'Search demand has a current/news intent. Validate freshness and source before creating or updating a news item.';
			} elseif ( in_array( $intent, array( 'informational', 'comparison' ), true ) ) {
				$action = 'CREATE_OR_EXPAND_CONTENT';
				$channel = 'content';
				$ready = $match >= 0.32;
				$reason = 'Search demand is informational. Prefer expanding an existing related article before creating a new URL.';
			} elseif ( $match >= 0.48 ) {
				$action = 'STRENGTHEN_CATEGORY';
				$channel = 'catalog';
				$ready = true;
				$reason = 'Search demand is commercial and a sufficiently related WooCommerce category exists.';
			}

			$confidence = $impressions >= 100 && $match >= 0.48 ? 'HIGH' : ( $impressions >= 30 ? 'MEDIUM_HIGH' : 'MEDIUM' );
			$confidence_score = 'HIGH' === $confidence ? 80 : ( 'MEDIUM_HIGH' === $confidence ? 68 : 55 );
			$target = array();
			if ( $category ) {
				$target = array(
					'type'       => 'product_cat',
					'id'         => absint( $category['term_id'] ?? 0 ),
					'title'      => sanitize_text_field( (string) ( $category['name'] ?? '' ) ),
					'url'        => esc_url_raw( (string) ( $category['url'] ?? '' ) ),
					'similarity' => round( $match, 4 ),
				);
			}
			$out[] = array(
				'key'          => 'query|' . md5( self::normalize( $query ) ),
				'priority'     => max( 0, min( 100, $priority ) ),
				'action'       => $action,
				'action_label' => self::action_label( $action ),
				'channel'      => $channel,
				'topic'        => $query,
				'reason'       => $reason,
				'confidence'   => $confidence,
				'confidence_score' => $confidence_score,
				'demand_backed' => true,
				'execution_ready'=> $ready,
				'intent'       => $intent,
				'target'       => $target,
				'evidence'     => array(
					'Search impressions: ' . absint( $impressions ),
					'Search clicks: ' . absint( $clicks ),
					'Search position: ' . number_format_i18n( $position, 1 ),
					'CTR: ' . number_format_i18n( $ctr * 100, 2 ) . '%',
				),
				'sources' => array( 'Google Search' ),
				'metrics' => array(
					'impressions' => absint( $impressions ),
					'clicks'      => absint( $clicks ),
					'ctr'         => $ctr,
					'position'    => $position,
					'category_similarity' => $match,
				),
			);
		}
		usort( $out, static function ( $a, $b ) { return (int) $b['priority'] <=> (int) $a['priority']; } );
		return array_slice( $out, 0, 35 );
	}

	private static function market_directives( $market ) {
		if ( empty( $market['available'] ) ) {
			return array();
		}
		$out = array();
		foreach ( array_slice( (array) ( $market['categories'] ?? array() ), 0, 20 ) as $row ) {
			$external = absint( $row['external_evidence'] ?? 0 );
			$merchant = absint( $row['merchant'] ?? 0 );
			$offers = absint( $row['offers'] ?? 0 );
			if ( $external < 2 && $merchant < 1 ) {
				continue;
			}
			$products = absint( $row['product_count'] ?? 0 );
			$priority = min( 85, 35 + min( 25, $external * 3 ) + min( 15, $merchant * 3 ) + min( 10, $offers * 2 ) );
			$action = $products <= 5 ? 'REVIEW_CATALOG_DEPTH' : 'REVIEW_MARKET_CATEGORY';
			$reason = $products <= 5
				? 'External market evidence exists while the local category has limited product depth.'
				: 'External market evidence is concentrated in a category already present in the local catalog.';
			$provider_count = count( (array) ( $row['providers'] ?? array() ) );
			$confidence = $provider_count >= 3 || $merchant >= 3 ? 'MEDIUM_HIGH' : 'MEDIUM';
			$out[] = array(
				'key'          => 'market|' . absint( $row['term_id'] ?? 0 ),
				'priority'     => $priority,
				'action'       => $action,
				'action_label' => self::action_label( $action ),
				'channel'      => 'catalog',
				'topic'        => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
				'reason'       => $reason,
				'confidence'   => $confidence,
				'confidence_score' => 'MEDIUM_HIGH' === $confidence ? 68 : 55,
				'demand_backed' => false,
				'execution_ready'=> true,
				'target'       => array(
					'type'  => 'product_cat',
					'id'    => absint( $row['term_id'] ?? 0 ),
					'title' => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
					'url'   => esc_url_raw( (string) ( $row['url'] ?? '' ) ),
				),
				'evidence' => array(
					'External evidence: ' . $external,
					'Merchant signals: ' . $merchant,
					'Observed offers: ' . $offers,
					'Local products: ' . $products,
				),
				'sources' => array_values( (array) ( $row['providers'] ?? array() ) ),
				'metrics' => array(
					'external_evidence' => $external,
					'merchant_signals'  => $merchant,
					'offers'            => $offers,
					'local_products'    => $products,
				),
			);
		}
		return $out;
	}

	private static function trend_directives( $items ) {
		$out = array();
		foreach ( array_slice( (array) $items, 0, 12 ) as $item ) {
			$title = sanitize_text_field( (string) ( $item['title'] ?? '' ) );
			if ( '' === $title ) {
				continue;
			}
			$category = self::best_category( $title );
			$match = (float) ( $category['similarity'] ?? 0 );
			$action = $match >= 0.42 ? 'INVESTIGATE_TREND' : 'WATCH_TREND';
			$priority = $match >= 0.42 ? 52 : 40;
			$out[] = array(
				'key'          => 'trend|' . md5( self::normalize( $title ) ),
				'priority'     => $priority,
				'action'       => $action,
				'action_label' => self::action_label( $action ),
				'channel'      => 'watch',
				'topic'        => $title,
				'reason'       => $match >= 0.42
					? 'Google Trends shows a current signal related to an existing catalog category. Validate it with Search or Market before execution.'
					: 'Google Trends shows a current signal, but local commercial relevance is not yet strong enough to act.',
				'confidence'   => 'EXPLORATORY',
				'confidence_score' => 35,
				'demand_backed' => false,
				'execution_ready'=> false,
				'target'       => $category ? array(
					'type'       => 'product_cat',
					'id'         => absint( $category['term_id'] ?? 0 ),
					'title'      => sanitize_text_field( (string) ( $category['name'] ?? '' ) ),
					'url'        => esc_url_raw( (string) ( $category['url'] ?? '' ) ),
					'similarity' => round( $match, 4 ),
				) : array(),
				'evidence' => array_filter( array(
					'Trend: ' . $title,
					! empty( $item['traffic_label'] ) ? 'Traffic label: ' . sanitize_text_field( (string) $item['traffic_label'] ) : '',
				) ),
				'sources' => array( 'Google Trends' ),
				'metrics' => array(),
			);
		}
		return $out;
	}

	private static function bucket( $row ) {
		$action = (string) ( $row['action'] ?? '' );
		$priority = absint( $row['priority'] ?? 0 );
		$confidence = absint( $row['confidence_score'] ?? 0 );
		$demand_backed = ! empty( $row['demand_backed'] );
		$ready = ! empty( $row['execution_ready'] );

		if ( 0 === strpos( $action, 'INVESTIGATE_' ) || ! $ready && $priority >= 45 ) {
			return 'INVESTIGATE';
		}
		if ( 'WATCH_TREND' === $action || $priority < 35 ) {
			return $priority < 25 ? 'NO_ACTION' : 'WATCH';
		}
		if ( $priority >= 72 && $confidence >= 55 && $demand_backed && $ready ) {
			return 'NOW';
		}
		if ( $priority >= 52 ) {
			return 'NEXT';
		}
		if ( $priority >= 35 ) {
			return 'WATCH';
		}
		return 'NO_ACTION';
	}

	private static function deduplicate_directives( $rows ) {
		$groups = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['topic'] ) ) {
				continue;
			}
			$target_url = (string) ( $row['target']['url'] ?? '' );
			$key = $target_url ? 'target|' . md5( $target_url ) . '|' . (string) ( $row['channel'] ?? '' ) : (string) ( $row['key'] ?? '' );
			if ( '' === $key ) {
				$key = 'topic|' . md5( self::normalize( (string) $row['topic'] ) );
			}
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = $row;
				continue;
			}
			$current = $groups[ $key ];
			if ( (int) ( $row['priority'] ?? 0 ) > (int) ( $current['priority'] ?? 0 ) ) {
				$current['priority'] = (int) $row['priority'];
				$current['reason'] = (string) ( $row['reason'] ?? $current['reason'] );
				$current['action'] = (string) ( $row['action'] ?? $current['action'] );
				$current['action_label'] = self::action_label( $current['action'] );
			}
			$current['demand_backed'] = ! empty( $current['demand_backed'] ) || ! empty( $row['demand_backed'] );
			$current['execution_ready'] = ! empty( $current['execution_ready'] ) || ! empty( $row['execution_ready'] );
			$current['confidence_score'] = max( (int) ( $current['confidence_score'] ?? 0 ), (int) ( $row['confidence_score'] ?? 0 ) );
			$current['sources'] = array_values( array_unique( array_filter( array_merge( (array) ( $current['sources'] ?? array() ), (array) ( $row['sources'] ?? array() ) ) ) ) );
			$current['evidence'] = array_values( array_unique( array_filter( array_merge( (array) ( $current['evidence'] ?? array() ), (array) ( $row['evidence'] ?? array() ) ) ) ) );
			$current['metrics'] = array_merge( (array) ( $current['metrics'] ?? array() ), (array) ( $row['metrics'] ?? array() ) );
			$groups[ $key ] = $current;
		}
		return array_values( $groups );
	}

	private static function best_category( $text ) {
		static $categories = null;
		if ( null === $categories ) {
			$categories = array();
			if ( taxonomy_exists( 'product_cat' ) ) {
				$terms = get_terms(
					array(
						'taxonomy'   => 'product_cat',
						'hide_empty' => false,
						'number'     => 1500,
						'orderby'    => 'count',
						'order'      => 'DESC',
					)
				);
				if ( ! is_wp_error( $terms ) ) {
					foreach ( (array) $terms as $term ) {
						$link = get_term_link( $term );
						$categories[] = array(
							'term_id' => absint( $term->term_id ),
							'name'    => (string) $term->name,
							'search'  => trim( (string) $term->name . ' ' . str_replace( '-', ' ', (string) $term->slug ) ),
							'count'   => absint( $term->count ),
							'url'     => is_wp_error( $link ) ? '' : (string) $link,
						);
					}
				}
			}
		}
		$best = array();
		$score = 0.0;
		foreach ( $categories as $category ) {
			$current = self::similarity( $text, $category['search'] );
			if ( $current > $score ) {
				$score = $current;
				$best = $category;
			}
		}
		if ( ! $best || $score < 0.24 ) {
			return array();
		}
		$best['similarity'] = $score;
		return $best;
	}

	private static function similarity( $left, $right ) {
		$a = self::tokens( $left );
		$b = self::tokens( $right );
		if ( ! $a || ! $b ) {
			return 0.0;
		}
		$shared = count( array_intersect( $a, $b ) );
		if ( ! $shared ) {
			return 0.0;
		}
		$coverage = $shared / max( 1, min( count( $a ), count( $b ) ) );
		$union = count( array_unique( array_merge( $a, $b ) ) );
		$jaccard = $shared / max( 1, $union );
		return min( 1.0, ( $coverage * 0.72 ) + ( $jaccard * 0.28 ) );
	}

	private static function tokens( $text ) {
		$stop = array( 'de','del','la','las','el','los','y','para','con','sin','en','por','a','un','una','unos','unas','the','and','for','with','from' );
		$tokens = preg_split( '/\s+/u', self::normalize( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$out = array();
		foreach ( (array) $tokens as $token ) {
			if ( strlen( $token ) < 3 || in_array( $token, $stop, true ) ) {
				continue;
			}
			$out[ $token ] = true;
		}
		return array_keys( $out );
	}

	private static function normalize( $text ) {
		$text = remove_accents( wp_strip_all_tags( (string) $text ) );
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		return trim( (string) preg_replace( '/[^a-z0-9]+/u', ' ', $text ) );
	}

	private static function intent( $query ) {
		$value = ' ' . self::normalize( $query ) . ' ';
		foreach ( array( ' normativa ', ' ley ', ' regulacion ', ' homologacion ', ' lanzamiento ', ' novedad ', ' entra en vigor ', ' retirada ', ' alerta ' ) as $term ) {
			if ( false !== strpos( $value, $term ) ) {
				return 'news';
			}
		}
		foreach ( array( ' vs ', ' versus ', ' comparar ', ' comparativa ', ' diferencias ', ' diferencia ' ) as $term ) {
			if ( false !== strpos( $value, $term ) ) {
				return 'comparison';
			}
		}
		foreach ( array( ' como ', ' que es ', ' para que ', ' por que ', ' cuando ', ' guia ', ' consejos ', ' elegir ', ' instalar ', ' usar ', ' mantenimiento ', ' problema ', ' reparar ' ) as $term ) {
			if ( false !== strpos( $value, $term ) ) {
				return 'informational';
			}
		}
		foreach ( array( ' comprar ', ' precio ', ' oferta ', ' tienda ', ' proveedor ', ' stock ', ' venta ' ) as $term ) {
			if ( false !== strpos( $value, $term ) ) {
				return 'commercial';
			}
		}
		return 'product_category';
	}

	private static function position_bucket( $position ) {
		$position = (float) $position;
		if ( $position <= 0 ) {
			return 'none';
		}
		if ( $position <= 3 ) {
			return '1-3';
		}
		if ( $position <= 10 ) {
			return '4-10';
		}
		if ( $position <= 20 ) {
			return '11-20';
		}
		if ( $position <= 50 ) {
			return '21-50';
		}
		return '51+';
	}

	private static function position_opportunity( $position ) {
		$position = (float) $position;
		if ( $position > 0 && $position <= 3 ) {
			return 35;
		}
		if ( $position <= 10 && $position > 0 ) {
			return 100;
		}
		if ( $position <= 20 && $position > 0 ) {
			return 92;
		}
		if ( $position <= 35 && $position > 0 ) {
			return 72;
		}
		if ( $position <= 50 && $position > 0 ) {
			return 52;
		}
		return $position > 50 ? 30 : 20;
	}

	private static function ctr_need( $reference, $gap ) {
		if ( $reference <= 0 ) {
			return 45;
		}
		if ( $gap <= -0.45 ) {
			return 100;
		}
		if ( $gap <= -0.20 ) {
			return 80;
		}
		if ( $gap < 0 ) {
			return 60;
		}
		return 25;
	}

	private static function action_label( $action ) {
		$labels = array(
			'IMPROVE_POST'           => 'Improve post',
			'IMPROVE_PAGE'           => 'Improve page',
			'IMPROVE_PRODUCT'        => 'Improve product',
			'IMPROVE_LANDING'        => 'Improve landing',
			'IMPROVE_ARCHITECTURE'   => 'Improve hub / cluster',
			'CREATE_OR_EXPAND_CONTENT'=> 'Create or expand content',
			'CREATE_NEWS'            => 'Create / update news',
			'STRENGTHEN_CATEGORY'    => 'Strengthen category',
			'INVESTIGATE_CATALOG'    => 'Investigate catalog gap',
			'REVIEW_CATALOG_DEPTH'   => 'Review catalog depth',
			'REVIEW_MARKET_CATEGORY' => 'Review market category',
			'INVESTIGATE_TREND'      => 'Investigate trend',
			'WATCH_TREND'            => 'Watch trend',
		);
		return $labels[ $action ] ?? ucwords( strtolower( str_replace( '_', ' ', $action ) ) );
	}

	private static function export_url() {
		return wp_nonce_url(
			add_query_arg( array( 'action' => self::EXPORT_ACTION ), admin_url( 'admin-post.php' ) ),
			self::EXPORT_ACTION
		);
	}

	public static function export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export this report.', 'taxocommerce-google-bing' ) );
		}
		check_admin_referer( self::EXPORT_ACTION );
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="taxocommerce-insights-analyst-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo wp_json_encode( self::report(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Explicit JSON download.
		exit;
	}

	public static function render() {
		$report = self::report();
		echo '<div class="tgb-analyst">';

		echo '<section class="tgb-analyst-head"><div><h2>Analyst</h2>';
		echo '<p>Strategic view rebuilt from the former Analista: where we are, how the market is moving, and where to go next. It reads saved evidence only and never calls providers while this page is open.</p></div>';
		echo '<a class="button" href="' . esc_url( self::export_url() ) . '">Export Analyst JSON</a></section>';

		self::render_where_we_are( (array) $report['where_we_are'] );
		self::render_market( (array) $report['market'] );
		self::render_direction( (array) $report['direction'] );

		echo '<p class="description">This report is the strategic contract for the future TaxoCommerce Editor. Analyst decides what deserves work; Editor will draft or update the content.</p>';
		echo '</div>';
	}

	private static function render_where_we_are( $data ) {
		$overview = (array) ( $data['overview'] ?? array() );
		$gsc = (array) ( $overview['google_search'] ?? array() );
		$ga4 = (array) ( $overview['analytics'] ?? array() );
		$bing = (array) ( $overview['bing'] ?? array() );
		$momentum = (string) ( $data['momentum'] ?? 'insufficient_data' );

		$labels = array(
			'improving'        => 'Improving',
			'mixed'            => 'Mixed',
			'declining'        => 'Declining',
			'insufficient_data'=> 'Insufficient data',
		);
		echo '<section class="tgb-analyst-section"><div class="tgb-analyst-section-head"><div><h3>Where are we?</h3><p>Own visibility, traffic and conversion signals compared with the saved previous period.</p></div><span class="tgb-analyst-state is-' . esc_attr( $momentum ) . '">' . esc_html( $labels[ $momentum ] ?? $momentum ) . '</span></div>';
		echo '<div class="tgb-analyst-kpis">';
		self::kpi( 'Google clicks', $gsc['available'] ? $gsc['clicks'] : null, $gsc['previous']['clicks'] ?? null );
		self::kpi( 'Google impressions', $gsc['available'] ? $gsc['impressions'] : null, $gsc['previous']['impressions'] ?? null );
		self::kpi( 'Google position', $gsc['available'] ? $gsc['position'] : null, $gsc['previous']['position'] ?? null, true );
		self::kpi( 'GA4 sessions', $ga4['available'] ? $ga4['sessions'] : null, $ga4['previous']['sessions'] ?? null );
		self::kpi( 'GA4 purchases', $ga4['available'] ? $ga4['purchases'] : null, $ga4['previous']['purchases'] ?? null );
		self::kpi( 'Bing clicks', $bing['available'] ? $bing['clicks'] : null, $bing['previous']['clicks'] ?? null );
		echo '</div>';

		$queries = (array) ( $data['top_queries'] ?? array() );
		if ( $queries ) {
			echo '<h4>Current Search visibility</h4><div class="tgb-analyst-scroll"><table class="widefat striped"><thead><tr><th>Query</th><th>Clicks</th><th>Impressions</th><th>CTR</th><th>Position</th></tr></thead><tbody>';
			foreach ( array_slice( $queries, 0, 10 ) as $row ) {
				echo '<tr><td><strong>' . esc_html( (string) $row['query'] ) . '</strong></td><td>' . esc_html( number_format_i18n( (int) $row['clicks'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $row['impressions'] ) ) . '</td><td>' . esc_html( number_format_i18n( (float) $row['ctr'] * 100, 2 ) ) . '%</td><td>' . esc_html( number_format_i18n( (float) $row['position'], 1 ) ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</section>';
	}

	private static function render_market( $market ) {
		echo '<section class="tgb-analyst-section"><div class="tgb-analyst-section-head"><div><h3>How does the market look?</h3><p>Stored Market and Merchant evidence compared with our local category depth. This is market evidence, not an invented competitor ranking.</p></div></div>';
		if ( empty( $market['available'] ) ) {
			echo '<div class="notice notice-info inline"><p>No Market dataset is available yet.</p></div></section>';
			return;
		}
		$summary = (array) ( $market['summary'] ?? array() );
		echo '<div class="tgb-analyst-kpis">';
		self::simple_kpi( 'Market categories', absint( $summary['categories'] ?? 0 ) );
		self::simple_kpi( 'Analyzed', absint( $summary['analyzed_categories'] ?? 0 ) );
		self::simple_kpi( 'External evidence', absint( $summary['external_evidence'] ?? 0 ) );
		self::simple_kpi( 'Merchant signals', absint( $summary['merchant_signals'] ?? 0 ) );
		echo '</div>';

		$rollup = (array) ( $market['global_rollup'] ?? array() );
		if ( $rollup ) {
			$coverage = (array) ( $rollup['coverage'] ?? array() );
			$product_intelligence = (array) ( $rollup['product_intelligence'] ?? array() );
			echo '<div class="tgb-analyst-global"><h4>Global Market Summary</h4><p class="description">This is the aggregate view of all saved Market research. Analyst reads the accumulated category/product evidence and summarizes it; it does not repeat the individual analyses.</p>';
			echo '<div class="tgb-analyst-kpis">';
			self::simple_kpi( 'Research coverage', (float) ( $coverage['coverage_pct'] ?? 0 ), '%' );
			self::simple_kpi( 'Pending categories', absint( $coverage['pending'] ?? 0 ) );
			self::simple_kpi( 'Categories with errors', absint( $coverage['errors'] ?? 0 ) );
			self::simple_kpi( 'Product signals', absint( $product_intelligence['signals'] ?? 0 ) );
			self::simple_kpi( 'Unique products / clusters', absint( $product_intelligence['unique_products'] ?? 0 ) );
			self::simple_kpi( 'Observed brands', absint( $product_intelligence['brands'] ?? 0 ) );
			self::simple_kpi( 'Observed sellers', absint( $product_intelligence['sellers'] ?? 0 ) );
			echo '</div>';

			$gaps = (array) ( $rollup['catalog_gaps'] ?? array() );
			if ( $gaps ) {
				echo '<h4>Largest market / catalog gaps</h4><p class="description">Categories with accumulated product-level market signals but comparatively shallow local assortment. The score is an internal prioritization aid, not a market-share metric.</p>';
				echo '<div class="tgb-analyst-scroll"><table class="widefat striped"><thead><tr><th>Priority</th><th>Category</th><th>Local products</th><th>Product signals</th><th>Merchant</th><th>Offers</th></tr></thead><tbody>';
				foreach ( array_slice( $gaps, 0, 12 ) as $gap ) {
					echo '<tr><td><strong>' . esc_html( (string) $gap['gap_score'] ) . '/100</strong></td><td>';
					if ( ! empty( $gap['url'] ) ) {
						echo '<a href="' . esc_url( $gap['url'] ) . '" target="_blank" rel="noopener"><strong>' . esc_html( (string) $gap['category'] ) . '</strong></a>';
					} else {
						echo '<strong>' . esc_html( (string) $gap['category'] ) . '</strong>';
					}
					echo '</td><td>' . esc_html( number_format_i18n( (int) $gap['local_products'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $gap['product_signals'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $gap['merchant_signals'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $gap['offers'] ) ) . '</td></tr>';
				}
				echo '</tbody></table></div>';
			}

			$clusters = (array) ( $rollup['product_clusters'] ?? array() );
			if ( $clusters ) {
				echo '<h4>Products / clusters repeating across the research</h4><p class="description">Aggregated product-level signals from Merchant and stored shopping offers. Repetition indicates recurring visibility in the research set.</p>';
				echo '<div class="tgb-analyst-scroll"><table class="widefat striped"><thead><tr><th>Product / cluster</th><th>Brand</th><th>Mentions</th><th>Categories</th><th>Best Merchant rank</th><th>Sources</th></tr></thead><tbody>';
				foreach ( array_slice( $clusters, 0, 12 ) as $cluster ) {
					echo '<tr><td><strong>' . esc_html( (string) $cluster['title'] ) . '</strong></td><td>' . esc_html( (string) ( $cluster['brand'] ?: '—' ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $cluster['mentions'] ) ) . '</td><td>' . esc_html( implode( ' · ', (array) $cluster['categories'] ) ) . '</td><td>' . esc_html( (string) ( $cluster['best_rank'] ?: '—' ) ) . '</td><td>' . esc_html( implode( ', ', (array) $cluster['providers'] ) ) . '</td></tr>';
				}
				echo '</tbody></table></div>';
			}

			$brands = (array) ( $rollup['top_brands'] ?? array() );
			$sellers = (array) ( $rollup['top_sellers'] ?? array() );
			if ( $brands || $sellers ) {
				echo '<div class="tgb-analyst-two">';
				if ( $brands ) {
					echo '<div><h4>Observed brands</h4><table class="widefat striped"><thead><tr><th>Brand</th><th>Signals</th><th>Products</th><th>Categories</th></tr></thead><tbody>';
					foreach ( array_slice( $brands, 0, 10 ) as $brand ) {
						echo '<tr><td><strong>' . esc_html( (string) $brand['brand'] ) . '</strong></td><td>' . esc_html( number_format_i18n( (int) $brand['mentions'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $brand['product_count'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $brand['category_count'] ) ) . '</td></tr>';
					}
					echo '</tbody></table></div>';
				}
				if ( $sellers ) {
					echo '<div><h4>Observed sellers</h4><table class="widefat striped"><thead><tr><th>Seller</th><th>Offers</th><th>Products</th><th>Categories</th></tr></thead><tbody>';
					foreach ( array_slice( $sellers, 0, 10 ) as $seller ) {
						echo '<tr><td><strong>' . esc_html( (string) $seller['seller'] ) . '</strong></td><td>' . esc_html( number_format_i18n( (int) $seller['mentions'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $seller['product_count'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $seller['category_count'] ) ) . '</td></tr>';
					}
					echo '</tbody></table></div>';
				}
				echo '</div>';
			}
			echo '</div>';
		}

		echo '<h4>Detailed category evidence</h4>';
		echo '<div class="tgb-analyst-scroll"><table class="widefat striped"><thead><tr><th>Category</th><th>Country</th><th>Local products</th><th>External evidence</th><th>Merchant</th><th>Offers</th><th>Sources</th></tr></thead><tbody>';
		foreach ( array_slice( (array) ( $market['categories'] ?? array() ), 0, 15 ) as $row ) {
			echo '<tr><td>';
			if ( ! empty( $row['url'] ) ) {
				echo '<a href="' . esc_url( $row['url'] ) . '" target="_blank" rel="noopener"><strong>' . esc_html( (string) $row['name'] ) . '</strong></a>';
			} else {
				echo '<strong>' . esc_html( (string) $row['name'] ) . '</strong>';
			}
			echo '</td><td>' . esc_html( (string) $row['country'] ) . '</td><td>' . esc_html( number_format_i18n( (int) $row['product_count'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $row['external_evidence'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $row['merchant'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $row['offers'] ) ) . '</td><td>' . esc_html( implode( ', ', (array) $row['providers'] ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';

		$leaders = (array) ( $market['merchant_leaders'] ?? array() );
		if ( $leaders ) {
			echo '<h4>Merchant market leaders</h4><p class="description">Saved Google Merchant Best Sellers signals. Rank and relative demand come from the provider report; they are not calculated by TaxoCommerce.</p>';
			echo '<div class="tgb-analyst-scroll"><table class="widefat striped"><thead><tr><th>Category</th><th>Product / cluster</th><th>Brand</th><th>Rank</th><th>Relative demand</th><th>Report date</th></tr></thead><tbody>';
			foreach ( array_slice( $leaders, 0, 15 ) as $leader ) {
				echo '<tr><td>' . esc_html( (string) $leader['category'] ) . '</td><td><strong>' . esc_html( (string) $leader['title'] ) . '</strong></td><td>' . esc_html( (string) ( $leader['brand'] ?: '—' ) ) . '</td><td>' . esc_html( (string) ( $leader['rank'] ?: '—' ) ) . '</td><td>' . esc_html( (string) ( $leader['relative_demand'] ?: '—' ) ) . '</td><td>' . esc_html( (string) ( $leader['report_date'] ?: '—' ) ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}

		$competitors = (array) ( $market['competitors'] ?? array() );
		if ( $competitors ) {
			echo '<h4>Observed competing domains</h4><p class="description">Frequency of external domains in saved Market evidence. More mentions mean more observed presence in our research set, not a verified market-share or ranking position.</p>';
			echo '<div class="tgb-analyst-scroll"><table class="widefat striped"><thead><tr><th>Domain</th><th>Observed mentions</th><th>Categories</th><th>Sample areas</th></tr></thead><tbody>';
			foreach ( array_slice( $competitors, 0, 15 ) as $competitor ) {
				echo '<tr><td><strong>' . esc_html( (string) $competitor['domain'] ) . '</strong></td><td>' . esc_html( number_format_i18n( (int) $competitor['mentions'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $competitor['category_count'] ) ) . '</td><td>' . esc_html( implode( ' · ', (array) $competitor['categories'] ) ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</section>';
	}

	private static function render_direction( $direction ) {
		$summary = (array) ( $direction['summary'] ?? array() );
		echo '<section class="tgb-analyst-section"><div class="tgb-analyst-section-head"><div><h3>Where should we go?</h3><p>A short work queue. NOW is reserved for directly supported demand; exploratory Market/Trends signals stay in NEXT, INVESTIGATE or WATCH until stronger evidence exists.</p></div></div>';
		echo '<div class="tgb-analyst-kpis">';
		self::simple_kpi( 'Now', absint( $summary['now'] ?? 0 ) );
		self::simple_kpi( 'Next', absint( $summary['next'] ?? 0 ) );
		self::simple_kpi( 'Investigate', absint( $summary['investigate'] ?? 0 ) );
		self::simple_kpi( 'Watch', absint( $summary['watch'] ?? 0 ) );
		echo '</div>';

		$groups = array(
			'NOW'         => array( 'Now', 'Validated opportunities with direct demand evidence and a usable next action.' ),
			'NEXT'        => array( 'Next', 'Valid opportunities that should follow the immediate work or need one more commercial condition.' ),
			'INVESTIGATE' => array( 'Investigate', 'Destination, catalog coverage or current signal is not resolved enough for execution.' ),
			'WATCH'       => array( 'Watch', 'Real but still immature signals. Keep the baseline and review later.' ),
		);
		foreach ( $groups as $bucket => $meta ) {
			$rows = array_values( array_filter(
				(array) ( $direction['rows'] ?? array() ),
				static function ( $row ) use ( $bucket ) { return $bucket === (string) ( $row['bucket'] ?? '' ); }
			) );
			if ( ! $rows ) {
				continue;
			}
			echo '<div class="tgb-analyst-plan"><h4>' . esc_html( $meta[0] ) . '</h4><p class="description">' . esc_html( $meta[1] ) . '</p>';
			foreach ( array_slice( $rows, 0, 'NOW' === $bucket ? 10 : 20 ) as $row ) {
				self::render_directive( $row );
			}
			echo '</div>';
		}
		echo '</section>';
	}

	private static function render_directive( $row ) {
		$target = (array) ( $row['target'] ?? array() );
		echo '<article class="tgb-analyst-directive"><div class="tgb-analyst-priority">' . esc_html( absint( $row['priority'] ?? 0 ) ) . '<small>/100</small></div><div class="tgb-analyst-directive-body">';
		echo '<div class="tgb-analyst-directive-head"><span class="tgb-analyst-pill">' . esc_html( (string) ( $row['action_label'] ?? $row['action'] ?? '' ) ) . '</span><span class="tgb-analyst-pill is-muted">' . esc_html( (string) ( $row['confidence'] ?? '' ) ) . '</span><strong>' . esc_html( (string) ( $row['topic'] ?? '' ) ) . '</strong></div>';
		echo '<p>' . esc_html( (string) ( $row['reason'] ?? '' ) ) . '</p>';
		if ( ! empty( $target['title'] ) ) {
			echo '<p class="tgb-analyst-meta"><strong>Target:</strong> ';
			if ( ! empty( $target['url'] ) ) {
				echo '<a href="' . esc_url( $target['url'] ) . '" target="_blank" rel="noopener">' . esc_html( (string) $target['title'] ) . '</a>';
			} else {
				echo esc_html( (string) $target['title'] );
			}
			if ( isset( $target['similarity'] ) ) {
				echo ' · semantic match ' . esc_html( (string) round( (float) $target['similarity'] * 100 ) ) . '%';
			}
			echo '</p>';
		}
		if ( ! empty( $row['evidence'] ) ) {
			echo '<p class="tgb-analyst-meta"><strong>Evidence:</strong> ' . esc_html( implode( ' · ', array_slice( (array) $row['evidence'], 0, 5 ) ) ) . '</p>';
		}
		if ( ! empty( $row['sources'] ) ) {
			echo '<div class="tgb-analyst-sources">';
			foreach ( (array) $row['sources'] as $source ) {
				echo '<span>' . esc_html( (string) $source ) . '</span>';
			}
			echo '</div>';
		}
		echo '</div></article>';
	}

	private static function kpi( $label, $current, $previous, $lower_is_better = false ) {
		echo '<div class="tgb-analyst-kpi"><span>' . esc_html( $label ) . '</span>';
		if ( null === $current ) {
			echo '<strong>—</strong><small>No saved data</small></div>';
			return;
		}
		$is_decimal = false !== strpos( strtolower( $label ), 'position' );
		echo '<strong>' . esc_html( number_format_i18n( (float) $current, $is_decimal ? 1 : 0 ) ) . '</strong>';
		if ( null !== $previous ) {
			$delta = self::percent_delta( (float) $current, (float) $previous, $lower_is_better );
			echo '<small>' . esc_html( $delta ) . '</small>';
		}
		echo '</div>';
	}

	private static function simple_kpi( $label, $value, $suffix = '' ) {
		$decimals = is_float( $value ) && floor( $value ) !== $value ? 1 : 0;
		echo '<div class="tgb-analyst-kpi"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( number_format_i18n( (float) $value, $decimals ) . $suffix ) . '</strong></div>';
	}

	private static function percent_delta( $current, $previous, $lower_is_better = false ) {
		if ( 0.0 === (float) $previous ) {
			return 0.0 === (float) $current ? 'No change' : 'Previous baseline: 0';
		}
		$raw = ( ( $current - $previous ) / abs( $previous ) ) * 100;
		$good = $lower_is_better ? $raw < 0 : $raw > 0;
		$prefix = $raw > 0 ? '+' : '';
		return $prefix . number_format_i18n( $raw, 1 ) . '% · ' . ( 0.0 === $raw ? 'flat' : ( $good ? 'better' : 'worse' ) );
	}
}
