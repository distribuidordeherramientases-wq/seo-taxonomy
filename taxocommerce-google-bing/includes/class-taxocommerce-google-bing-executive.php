<?php
/**
 * Consolidated executive report for TaxoCommerce Google/Bing.
 *
 * This layer never downloads external data. It combines the latest local
 * snapshots from Search Console, GA4, Bing, Trends, and Market and exposes a
 * stable read-only contract for Analista and other modules.
 *
 * @package TaxoCommerceGoogleBing
 */

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Google_Bing_Executive {

	const EXPORT_ACTION = 'tgb_export_executive_report';

	public static function register() {
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( __CLASS__, 'export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
		$page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
		$tab = isset( $_GET['tgb_tab'] ) && is_scalar( $_GET['tgb_tab'] ) ? sanitize_key( wp_unslash( $_GET['tgb_tab'] ) ) : '';
		if ( 'taxocommerce-google-bing' !== $page || 'informe' !== $tab ) {
			return;
		}
		wp_enqueue_style(
			'taxocommerce-google-bing-executive',
			TAXOCOMMERCE_GOOGLE_BING_URL . 'assets/executive-report.css',
			array(),
			TAXOCOMMERCE_GOOGLE_BING_VERSION
		);
	}

	/**
	 * Public, network-free report contract for Analista.
	 *
	 * @return array
	 */
	public static function report() {
		$search    = self::latest_snapshot( 'search' );
		$analytics = self::latest_snapshot( 'analytics' );
		$bing      = self::latest_snapshot( 'bing' );
		$trends    = class_exists( 'TaxoCommerce_Google_Bing_Trends' )
			? TaxoCommerce_Google_Bing_Trends::saved_snapshot( 'ES' )
			: array();
		$market    = self::market_summary();

		return array(
			'schema' => array(
				'name'    => 'taxocommerce-google-bing-executive',
				'version' => 1,
			),
			'generated_at' => time(),
			'sources'      => array(
				'search'    => self::snapshot_meta( $search ),
				'analytics' => self::snapshot_meta( $analytics ),
				'bing'      => self::snapshot_meta( $bing ),
				'trends'    => self::trends_meta( $trends ),
				'market'    => $market['meta'],
			),
			'overview'      => self::overview( $search, $analytics, $bing, $trends, $market ),
			'opportunities' => self::opportunities( $search, $analytics ),
			'content'       => self::content_performance( $search, $analytics ),
			'demand'        => array(
				'trends' => self::trends_summary( $trends ),
				'market' => $market,
			),
			'notes'         => array(
				'No external calls are made when opening or exporting this report.',
				'Metrics from different sources are compared only when they represent equivalent concepts.',
				'Search Console may omit anonymized queries, and GA4 depends on site instrumentation.',
				'Opportunities are an internal prioritization, not an official Google metric or a ranking prediction.',
			),
		);
	}

	public static function render() {
		$report = self::report();
		echo '<div class="tgb-executive">';

		echo '<section class="tgb-executive-head">';
		echo '<div><h2>Executive Report</h2>';
		echo '<p>A consolidated view of available signals. Provider tabs retain detailed charts; this report combines only indicators and analyses that add a distinct executive perspective.</p></div>';
		echo '<a class="button" href="' . esc_url( self::export_url() ) . '">Export JSON report</a>';
		echo '</section>';

		self::render_source_status( $report['sources'] );
		self::render_overview( $report['overview'] );
		self::render_opportunities( $report['opportunities'] );
		self::render_content( $report['content'] );
		self::render_demand( $report['demand'] );

		echo '<p class="description tgb-executive-note">This report is the read contract for downstream analysis. Consumers can summarize, prioritize, and visualize these data without accessing credentials or querying APIs.</p>';
		echo '</div>';
	}

	public static function export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export this report.', 'taxocommerce-google-bing' ) );
		}
		check_admin_referer( 'tgb_export_executive_report' );

		$report = self::report();
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="taxocommerce-google-bing-informe-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Explicit JSON download.
		exit;
	}

	private static function export_url() {
		return wp_nonce_url(
			add_query_arg(
				array( 'action' => self::EXPORT_ACTION ),
				admin_url( 'admin-post.php' )
			),
			'tgb_export_executive_report'
		);
	}

	private static function tab_url( $tab ) {
		return add_query_arg(
			array(
				'page'    => 'taxocommerce-google-bing',
				'tgb_tab' => sanitize_key( $tab ),
			),
			admin_url( 'admin.php' )
		);
	}

	private static function latest_snapshot( $source ) {
		if ( ! class_exists( 'TaxoCommerce_Google_Bing_Reports' ) ) {
			return array();
		}
		$reports = TaxoCommerce_Google_Bing_Reports::saved_reports( $source );
		if ( ! is_array( $reports ) || ! $reports ) {
			return array();
		}
		$latest = array();
		$latest_time = 0;
		foreach ( $reports as $report ) {
			if ( ! is_array( $report ) ) {
				continue;
			}
			$stamp = absint( $report['downloaded_at'] ?? 0 );
			if ( $stamp >= $latest_time ) {
				$latest_time = $stamp;
				$latest = $report;
			}
		}
		return $latest;
	}

	private static function snapshot_meta( $snapshot ) {
		if ( ! is_array( $snapshot ) || ! $snapshot ) {
			return array(
				'available'     => false,
				'downloaded_at' => 0,
				'current'       => array(),
				'previous'      => array(),
			);
		}
		return array(
			'available'     => true,
			'downloaded_at' => absint( $snapshot['downloaded_at'] ?? 0 ),
			'current'       => (array) ( $snapshot['current'] ?? array() ),
			'previous'      => (array) ( $snapshot['previous'] ?? array() ),
		);
	}

	private static function trends_meta( $snapshot ) {
		if ( ! is_array( $snapshot ) || ! $snapshot ) {
			return array( 'available' => false, 'downloaded_at' => 0, 'country' => 'ES', 'items' => 0 );
		}
		return array(
			'available'     => true,
			'downloaded_at' => absint( $snapshot['downloaded_at'] ?? 0 ),
			'country'       => sanitize_text_field( (string) ( $snapshot['country'] ?? 'ES' ) ),
			'items'         => count( (array) ( $snapshot['items'] ?? array() ) ),
		);
	}

	private static function overview( $search, $analytics, $bing, $trends, $market ) {
		$gsc_current = (array) ( $search['data']['summary']['current'] ?? array() );
		$gsc_previous = (array) ( $search['data']['summary']['previous'] ?? array() );
		$ga_current = (array) ( $analytics['data']['summary']['current'] ?? array() );
		$ga_previous = (array) ( $analytics['data']['summary']['previous'] ?? array() );
		$bing_current = (array) ( $bing['data']['traffic']['current'] ?? array() );
		$bing_previous = (array) ( $bing['data']['traffic']['previous'] ?? array() );

		return array(
			'google_search' => array(
				'available'   => ! empty( $search ),
				'clicks'      => (int) ( $gsc_current['clicks'] ?? 0 ),
				'impressions' => (int) ( $gsc_current['impressions'] ?? 0 ),
				'ctr'         => (float) ( $gsc_current['ctr'] ?? 0 ),
				'position'    => (float) ( $gsc_current['position'] ?? 0 ),
				'previous'    => array(
					'clicks'      => (int) ( $gsc_previous['clicks'] ?? 0 ),
					'impressions' => (int) ( $gsc_previous['impressions'] ?? 0 ),
					'ctr'         => (float) ( $gsc_previous['ctr'] ?? 0 ),
					'position'    => (float) ( $gsc_previous['position'] ?? 0 ),
				),
			),
			'analytics' => array(
				'available'  => ! empty( $analytics ),
				'users'      => (int) ( $ga_current['activeUsers'] ?? 0 ),
				'new_users'  => (int) ( $ga_current['newUsers'] ?? 0 ),
				'sessions'   => (int) ( $ga_current['sessions'] ?? 0 ),
				'views'      => (int) ( $ga_current['screenPageViews'] ?? 0 ),
				'purchases'  => (int) ( $ga_current['ecommercePurchases'] ?? 0 ),
				'engagement' => (float) ( $ga_current['engagementRate'] ?? 0 ),
				'previous'   => array(
					'users'     => (int) ( $ga_previous['activeUsers'] ?? 0 ),
					'sessions'  => (int) ( $ga_previous['sessions'] ?? 0 ),
					'views'     => (int) ( $ga_previous['screenPageViews'] ?? 0 ),
					'purchases' => (int) ( $ga_previous['ecommercePurchases'] ?? 0 ),
				),
			),
			'bing' => array(
				'available'   => ! empty( $bing ),
				'clicks'      => (int) ( $bing_current['clicks'] ?? 0 ),
				'impressions' => (int) ( $bing_current['impressions'] ?? 0 ),
				'ctr'         => (float) ( $bing_current['ctr'] ?? 0 ),
				'previous'    => array(
					'clicks'      => (int) ( $bing_previous['clicks'] ?? 0 ),
					'impressions' => (int) ( $bing_previous['impressions'] ?? 0 ),
					'ctr'         => (float) ( $bing_previous['ctr'] ?? 0 ),
				),
			),
			'trends' => array(
				'available' => ! empty( $trends ),
				'items'     => count( (array) ( $trends['items'] ?? array() ) ),
			),
			'market' => array(
				'available'           => ! empty( $market['meta']['available'] ),
				'categories'          => absint( $market['summary']['categories'] ?? 0 ),
				'analyzed_categories' => absint( $market['summary']['analyzed_categories'] ?? 0 ),
				'evidence'            => absint( $market['summary']['evidence'] ?? 0 ),
				'merchant_evidence'   => absint( $market['summary']['merchant_evidence'] ?? 0 ),
			),
		);
	}

	private static function opportunities( $search, $analytics ) {
		$pages = array_values( (array) ( $search['data']['pages'] ?? array() ) );
		if ( ! $pages ) {
			return array(
				'available' => false,
				'reason'    => 'No pages are stored in the latest Search Console snapshot.',
				'summary'   => array(),
				'rows'      => array(),
			);
		}

		$max_impressions = 1.0;
		$buckets = array();
		foreach ( $pages as $page ) {
			$impressions = max( 0, (float) ( $page['impressions'] ?? 0 ) );
			$max_impressions = max( $max_impressions, $impressions );
			$bucket = self::position_bucket( (float) ( $page['position'] ?? 0 ) );
			if ( ! isset( $buckets[ $bucket ] ) ) {
				$buckets[ $bucket ] = array( 'clicks' => 0.0, 'impressions' => 0.0 );
			}
			$buckets[ $bucket ]['clicks'] += max( 0, (float) ( $page['clicks'] ?? 0 ) );
			$buckets[ $bucket ]['impressions'] += $impressions;
		}

		$ga4_map = self::ga4_page_map( $analytics );
		$rows = array();
		$high = 0;
		$low_ctr = 0;
		$weak_content = 0;
		$prep_total = 0;

		foreach ( $pages as $page ) {
			$url = (string) ( $page['name'] ?? '' );
			if ( '' === $url || self::is_functional_url( $url ) ) {
				continue;
			}
			$impressions = max( 0, (float) ( $page['impressions'] ?? 0 ) );
			$clicks = max( 0, (float) ( $page['clicks'] ?? 0 ) );
			$ctr = max( 0, (float) ( $page['ctr'] ?? 0 ) );
			$position = max( 0, (float) ( $page['position'] ?? 0 ) );
			$bucket = self::position_bucket( $position );
			$bucket_ctr = ! empty( $buckets[ $bucket ]['impressions'] )
				? (float) $buckets[ $bucket ]['clicks'] / (float) $buckets[ $bucket ]['impressions']
				: 0.0;
			$ctr_gap = $bucket_ctr > 0 ? ( $ctr - $bucket_ctr ) / $bucket_ctr : 0.0;
			$content = self::content_signals( $url );
			$content_score = null !== $content['score'] ? (int) $content['score'] : 50;

			$demand = min( 100, ( log( 1 + $impressions ) / log( 1 + $max_impressions ) ) * 100 );
			$position_score = self::position_opportunity( $position );
			$ctr_need = self::ctr_need( $bucket_ctr, $ctr_gap );
			$content_need = 100 - $content_score;
			$priority = (int) round(
				( $demand * 0.38 )
				+ ( $position_score * 0.30 )
				+ ( $ctr_need * 0.15 )
				+ ( $content_need * 0.17 )
			);

			$action = 'Mantener y vigilar.';
			if ( $position >= 4 && $position <= 20 && $impressions >= 20 ) {
				$action = 'Strengthen: Google already places the URL near high-visibility positions.';
			}
			if ( $bucket_ctr > 0 && $ctr_gap <= -0.20 && $impressions >= 20 ) {
				$action = 'Review title and snippet: CTR is lower than similar-position pages on the same site.';
			}
			if ( $content_score < 65 && ! empty( $content['advice'][0] ) ) {
				$action = (string) $content['advice'][0];
			}

			$path = self::path_key( $url );
			$views = isset( $ga4_map[ $path ] ) ? (int) $ga4_map[ $path ]['views'] : 0;
			$row = array(
				'url'          => $url,
				'title'        => $content['post_id'] ? (string) get_the_title( $content['post_id'] ) : $url,
				'object_id'    => absint( $content['post_id'] ),
				'object_type'  => (string) $content['post_type'],
				'impressions'  => (int) round( $impressions ),
				'clicks'       => (int) round( $clicks ),
				'ctr'          => $ctr,
				'position'     => $position,
				'views_ga4'    => $views,
				'priority'     => max( 0, min( 100, $priority ) ),
				'preparation'  => $content_score,
				'action'       => $action,
				'movement'     => null,
				'query_fanout' => null,
			);
			$rows[] = $row;
			if ( $row['priority'] >= 70 ) {
				++$high;
			}
			if ( $bucket_ctr > 0 && $ctr_gap <= -0.20 ) {
				++$low_ctr;
			}
			if ( $content_score < 60 ) {
				++$weak_content;
			}
			$prep_total += $content_score;
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				$priority = (int) $b['priority'] <=> (int) $a['priority'];
				return 0 !== $priority ? $priority : (int) $b['impressions'] <=> (int) $a['impressions'];
			}
		);

		return array(
			'available' => true,
			'reason'    => '',
			'summary'   => array(
				'pages'            => count( $rows ),
				'high_priority'    => $high,
				'low_ctr'          => $low_ctr,
				'weak_content'     => $weak_content,
				'preparation_avg'  => $rows ? (int) round( $prep_total / count( $rows ) ) : 0,
			),
			'rows' => array_slice( $rows, 0, 50 ),
			'limitations' => array(
				'The current snapshot does not preserve query→URL relationships, so page-level fan-out is not inferred.',
				'The current snapshot does not preserve the previous period by URL, so page-level movement is left uncalculated.',
			),
		);
	}

	private static function content_performance( $search, $analytics ) {
		$landing_ids = self::landing_ids();
		$landing_map = array_fill_keys( $landing_ids, true );
		$rows = array();

		foreach ( (array) ( $search['data']['pages'] ?? array() ) as $page ) {
			$url = (string) ( $page['name'] ?? '' );
			$id = $url ? url_to_postid( $url ) : 0;
			if ( ! $id ) {
				continue;
			}
			$type = get_post_type( $id );
			$group = 'post' === $type ? 'posts' : ( isset( $landing_map[ $id ] ) ? 'landings' : '' );
			if ( '' === $group ) {
				continue;
			}
			if ( ! isset( $rows[ $group ][ $id ] ) ) {
				$rows[ $group ][ $id ] = self::base_content_row( $id );
			}
			$rows[ $group ][ $id ]['clicks'] = (int) ( $page['clicks'] ?? 0 );
			$rows[ $group ][ $id ]['impressions'] = (int) ( $page['impressions'] ?? 0 );
			$rows[ $group ][ $id ]['ctr'] = (float) ( $page['ctr'] ?? 0 );
			$rows[ $group ][ $id ]['position'] = (float) ( $page['position'] ?? 0 );
		}

		foreach ( (array) ( $analytics['data']['pages'] ?? array() ) as $page ) {
			$path = self::path_key( (string) ( $page['name'] ?? '' ) );
			$id = url_to_postid( home_url( $path ) );
			if ( ! $id ) {
				continue;
			}
			$type = get_post_type( $id );
			$group = 'post' === $type ? 'posts' : ( isset( $landing_map[ $id ] ) ? 'landings' : '' );
			if ( '' === $group ) {
				continue;
			}
			if ( ! isset( $rows[ $group ][ $id ] ) ) {
				$rows[ $group ][ $id ] = self::base_content_row( $id );
			}
			$rows[ $group ][ $id ]['views'] = (int) ( $page['views'] ?? 0 );
			$rows[ $group ][ $id ]['previous_views'] = (int) ( $page['previous_views'] ?? 0 );
		}

		$out = array();
		foreach ( array( 'posts', 'landings' ) as $group ) {
			$group_rows = array_values( (array) ( $rows[ $group ] ?? array() ) );
			usort(
				$group_rows,
				static function ( $a, $b ) {
					$sa = (int) $a['impressions'] + (int) $a['views'];
					$sb = (int) $b['impressions'] + (int) $b['views'];
					return $sb <=> $sa;
				}
			);
			$summary = array(
				'total'       => 'posts' === $group ? self::published_post_count() : count( $landing_ids ),
				'with_signal' => count( $group_rows ),
				'views'       => 0,
				'clicks'      => 0,
				'impressions' => 0,
			);
			foreach ( $group_rows as $row ) {
				$summary['views'] += (int) $row['views'];
				$summary['clicks'] += (int) $row['clicks'];
				$summary['impressions'] += (int) $row['impressions'];
			}
			$out[ $group ] = array(
				'summary' => $summary,
				'rows'    => array_slice( $group_rows, 0, 30 ),
			);
		}
		$out['coverage_note'] = 'GA4 keeps the top 100 viewed paths and Search Console up to 1,000 pages per snapshot; content coverage is observed traffic, not a census of zero-traffic URLs.';
		return $out;
	}

	private static function market_summary() {
		$empty = array(
			'meta' => array( 'available' => false, 'updated_at' => '', 'providers' => array() ),
			'summary' => array(
				'categories' => 0,
				'analyzed_categories' => 0,
				'pending_categories' => 0,
				'evidence' => 0,
				'merchant_evidence' => 0,
			),
			'providers' => array(),
			'merchant_latest' => array(),
		);
		if ( ! class_exists( 'TaxoCommerce_Google_Bing_Market' ) ) {
			return $empty;
		}

		global $wpdb;
		$tables = TaxoCommerce_Google_Bing_Market::tables();
		foreach ( array( 'categories', 'sources' ) as $key ) {
			$table = $tables[ $key ];
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $found !== $table ) {
				return $empty;
			}
		}

		$summary = array(
			'categories' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['categories']} WHERE scan_status <> 'archived'" ),
			'analyzed_categories' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['categories']} WHERE scan_status IN ('complete','partial')" ),
			'pending_categories' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['categories']} WHERE scan_status = 'pending'" ),
			'evidence' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['sources']}" ),
			'merchant_evidence' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['sources']} WHERE provider = %s", 'merchant' ) ),
		);

		$providers = (array) $wpdb->get_results(
			"SELECT provider, COUNT(*) AS total, MAX(updated_at) AS updated_at FROM {$tables['sources']} GROUP BY provider ORDER BY total DESC",
			ARRAY_A
		);
		$latest = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT title,evidence_text,evidence_level,metadata_json,updated_at FROM {$tables['sources']} WHERE provider = %s ORDER BY updated_at DESC LIMIT 10",
				'merchant'
			),
			ARRAY_A
		);
		foreach ( $latest as &$row ) {
			$row['metadata'] = json_decode( (string) ( $row['metadata_json'] ?? '' ), true );
			unset( $row['metadata_json'] );
		}
		unset( $row );

		$updated = '';
		foreach ( $providers as $provider ) {
			if ( (string) ( $provider['updated_at'] ?? '' ) > $updated ) {
				$updated = (string) $provider['updated_at'];
			}
		}

		return array(
			'meta' => array(
				'available'  => true,
				'updated_at' => $updated,
				'providers'  => array_values( wp_list_pluck( $providers, 'provider' ) ),
			),
			'summary' => $summary,
			'providers' => $providers,
			'merchant_latest' => $latest,
		);
	}

	private static function trends_summary( $snapshot ) {
		$items = array();
		foreach ( array_slice( (array) ( $snapshot['items'] ?? array() ), 0, 12 ) as $item ) {
			$items[] = array(
				'title'         => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
				'traffic_label' => sanitize_text_field( (string) ( $item['traffic_label'] ?? '' ) ),
				'published_at'  => absint( $item['published_at'] ?? 0 ),
			);
		}
		return array(
			'available'     => ! empty( $snapshot ),
			'country'       => sanitize_text_field( (string) ( $snapshot['country'] ?? 'ES' ) ),
			'downloaded_at' => absint( $snapshot['downloaded_at'] ?? 0 ),
			'items'         => $items,
		);
	}

	private static function ga4_page_map( $snapshot ) {
		$out = array();
		foreach ( (array) ( $snapshot['data']['pages'] ?? array() ) as $page ) {
			$path = self::path_key( (string) ( $page['name'] ?? '' ) );
			if ( '' === $path ) {
				continue;
			}
			$out[ $path ] = array(
				'views'          => (int) ( $page['views'] ?? 0 ),
				'previous_views' => (int) ( $page['previous_views'] ?? 0 ),
			);
		}
		return $out;
	}

	private static function landing_ids() {
		global $wpdb;
		$table = $wpdb->prefix . 'seo_nodes';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $found !== $table ) {
			return array();
		}
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Internal table and fixed query.
		$ids = $wpdb->get_col( "SELECT DISTINCT object_id FROM {$table} WHERE object_type = 'page' AND seo_role = 'landing' AND status = 1" );
		return array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
	}

	private static function published_post_count() {
		$counts = wp_count_posts( 'post' );
		return is_object( $counts ) && isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	private static function base_content_row( $id ) {
		return array(
			'id'             => absint( $id ),
			'title'          => (string) get_the_title( $id ),
			'url'            => (string) get_permalink( $id ),
			'views'          => 0,
			'previous_views' => 0,
			'clicks'         => 0,
			'impressions'    => 0,
			'ctr'            => 0.0,
			'position'       => 0.0,
		);
	}

	private static function content_signals( $url ) {
		$out = array(
			'post_id'        => 0,
			'post_type'      => '',
			'score'          => null,
			'word_count'     => 0,
			'h2_count'       => 0,
			'internal_links' => 0,
			'freshness_days' => null,
			'advice'         => array(),
		);
		$id = url_to_postid( $url );
		if ( ! $id ) {
			return $out;
		}
		$post = get_post( $id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return $out;
		}

		$raw = (string) $post->post_content;
		$plain = trim( wp_strip_all_tags( strip_shortcodes( $raw ) ) );
		$words = preg_split( '/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY );
		$word_count = is_array( $words ) ? count( $words ) : 0;
		$h2_count = preg_match_all( '/<h2\b[^>]*>/i', $raw, $unused_h2 );
		$paragraphs = preg_match_all( '/<p\b[^>]*>/i', $raw, $unused_p );
		$internal_links = 0;
		if ( preg_match_all( '/href\s*=\s*["\']([^"\']+)["\']/i', $raw, $matches ) ) {
			$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			foreach ( (array) $matches[1] as $href ) {
				$href = trim( (string) $href );
				if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === strpos( $href, 'mailto:' ) || 0 === strpos( $href, 'tel:' ) ) {
					continue;
				}
				$link_host = (string) wp_parse_url( $href, PHP_URL_HOST );
				if ( '' === $link_host || $host === $link_host ) {
					++$internal_links;
				}
			}
		}

		$title = (string) get_the_title( $id );
		$title_length = function_exists( 'mb_strlen' ) ? mb_strlen( $title, 'UTF-8' ) : strlen( $title );
		$modified = get_post_modified_time( 'U', true, $post );
		$freshness = $modified ? max( 0, (int) floor( ( current_time( 'timestamp', true ) - $modified ) / DAY_IN_SECONDS ) ) : null;

		$score = 0;
		$score += $word_count >= 900 ? 27 : ( $word_count >= 600 ? 23 : ( $word_count >= 350 ? 17 : ( $word_count >= 180 ? 10 : 4 ) ) );
		$score += $h2_count >= 4 ? 20 : ( $h2_count >= 2 ? 16 : ( 1 === $h2_count ? 8 : 0 ) );
		$score += $paragraphs >= 7 ? 10 : ( $paragraphs >= 4 ? 7 : ( $paragraphs >= 2 ? 4 : 0 ) );
		$score += ( $title_length >= 28 && $title_length <= 70 ) ? 15 : ( ( $title_length >= 18 && $title_length <= 85 ) ? 10 : 5 );
		$score += $internal_links >= 4 ? 13 : ( $internal_links >= 2 ? 9 : ( $internal_links >= 1 ? 5 : 0 ) );
		$score += null !== $freshness && $freshness <= 180 ? 10 : ( null !== $freshness && $freshness <= 365 ? 7 : ( null !== $freshness && $freshness <= 730 ? 3 : 0 ) );

		$advice = array();
		if ( $word_count < 350 ) {
			$advice[] = 'Expand the answer: more detail is needed to better cover intent.';
		}
		if ( $h2_count < 2 ) {
			$advice[] = 'Separar subtemas con H2 claros.';
		}
		if ( $internal_links < 2 ) {
			$advice[] = 'Add internal links to related categories, products, or content.';
		}
		if ( null !== $freshness && $freshness > 365 ) {
			$advice[] = 'Review freshness: this content has not been updated for more than a year.';
		}
		if ( ! $advice ) {
			$advice[] = 'The editorial baseline is reasonable; prioritize by demand, CTR, and position.';
		}

		return array(
			'post_id'        => (int) $id,
			'post_type'      => (string) $post->post_type,
			'score'          => max( 0, min( 100, (int) $score ) ),
			'word_count'     => $word_count,
			'h2_count'       => (int) $h2_count,
			'internal_links' => $internal_links,
			'freshness_days' => $freshness,
			'advice'         => $advice,
		);
	}

	private static function path_key( $url_or_path ) {
		$value = trim( (string) $url_or_path );
		if ( '' === $value ) {
			return '';
		}
		$path = (string) wp_parse_url( $value, PHP_URL_PATH );
		if ( '' === $path && 0 === strpos( $value, '/' ) ) {
			$path = $value;
		}
		$path = '/' . ltrim( $path, '/' );
		return '/' === $path ? '/' : untrailingslashit( $path );
	}

	private static function is_functional_url( $url ) {
		$slug = basename( trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) );
		return in_array(
			$slug,
			array(
				'carrito',
				'cart',
				'checkout',
				'finalizar-compra',
				'mi-cuenta',
				'my-account',
				'terminos-y-condiciones',
				'privacidad-de-datos',
				'devoluciones-y-reembolsos',
				'contacto',
				'blog',
				'tienda',
				'shop',
				'dependiente',
			),
			true
		);
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
			return 30;
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

	private static function render_source_status( $sources ) {
		echo '<section class="tgb-executive-section">';
		echo '<div class="tgb-executive-section-head"><div><h3>Sources and freshness</h3><p>The report only uses already-saved information. A missing source does not block the others.</p></div></div>';
		echo '<div class="tgb-source-grid">';
		foreach (
			array(
				'search'    => array( 'Google Search', 'google_search' ),
				'analytics' => array( 'Google Analytics', 'google_analytics' ),
				'bing'      => array( 'Bing', 'bing' ),
				'trends'    => array( 'Google Trends', 'google_trends' ),
				'market'    => array( 'Market / Merchant', 'mercado' ),
			) as $key => $definition
		) {
			$source = (array) ( $sources[ $key ] ?? array() );
			$available = ! empty( $source['available'] );
			$stamp = absint( $source['downloaded_at'] ?? 0 );
			$updated = (string) ( $source['updated_at'] ?? '' );
			$when = $stamp ? wp_date( 'd/m/Y H:i', $stamp ) : ( $updated ? $updated : 'No data' );
			echo '<a class="tgb-source-card ' . ( $available ? 'is-ready' : 'is-empty' ) . '" href="' . esc_url( self::tab_url( $definition[1] ) ) . '">';
			echo '<strong>' . esc_html( $definition[0] ) . '</strong><span>' . esc_html( $available ? $when : 'Sin snapshot' ) . '</span>';
			echo '</a>';
		}
		echo '</div></section>';
	}

	private static function render_overview( $overview ) {
		$gsc = (array) $overview['google_search'];
		$ga4 = (array) $overview['analytics'];
		$bing = (array) $overview['bing'];
		$market = (array) $overview['market'];

		echo '<section class="tgb-executive-section">';
		echo '<div class="tgb-executive-section-head"><div><h3>Executive view</h3><p>Key indicators without repeating provider-level detailed tables and charts.</p></div></div>';
		echo '<div class="tgb-kpi-grid tgb-kpi-grid--executive">';
		self::kpi( 'Google clicks', $gsc['available'] ? $gsc['clicks'] : null, $gsc['previous']['clicks'] ?? null );
		self::kpi( 'Google impressions', $gsc['available'] ? $gsc['impressions'] : null, $gsc['previous']['impressions'] ?? null );
		self::kpi( 'Google position', $gsc['available'] ? $gsc['position'] : null, $gsc['previous']['position'] ?? null, 'decimal', true );
		self::kpi( 'GA4 sessions', $ga4['available'] ? $ga4['sessions'] : null, $ga4['previous']['sessions'] ?? null );
		self::kpi( 'GA4 users', $ga4['available'] ? $ga4['users'] : null, $ga4['previous']['users'] ?? null );
		self::kpi( 'GA4 purchases', $ga4['available'] ? $ga4['purchases'] : null, $ga4['previous']['purchases'] ?? null );
		self::kpi( 'Bing clicks', $bing['available'] ? $bing['clicks'] : null, $bing['previous']['clicks'] ?? null );
		self::kpi( 'Market evidence', $market['available'] ? $market['evidence'] : null, null );
		echo '</div>';
		echo '<div class="tgb-executive-links">';
		echo '<a href="' . esc_url( self::tab_url( 'google_search' ) ) . '">Detalle Google Search →</a>';
		echo '<a href="' . esc_url( self::tab_url( 'google_analytics' ) ) . '">Detalle GA4 →</a>';
		echo '<a href="' . esc_url( self::tab_url( 'bing' ) ) . '">Detalle Bing →</a>';
		echo '</div></section>';
	}

	private static function render_opportunities( $data ) {
		echo '<section class="tgb-executive-section">';
		echo '<div class="tgb-executive-section-head"><div><h3>Content opportunities</h3><p>Preserves the most useful parts of the previous ranking report: demand, position, CTR, and editorial readiness, without inventing metrics not stored in the current snapshot.</p></div></div>';
		if ( empty( $data['available'] ) ) {
			echo '<p class="description">' . esc_html( (string) ( $data['reason'] ?? 'No data.' ) ) . '</p></section>';
			return;
		}
		$summary = (array) $data['summary'];
		echo '<div class="tgb-mini-kpis">';
		foreach (
			array(
				'URLs analizadas' => $summary['pages'] ?? 0,
				'Priority alta' => $summary['high_priority'] ?? 0,
				'CTR mejorable' => $summary['low_ctr'] ?? 0,
				'Weak content' => $summary['weak_content'] ?? 0,
				'Average readiness' => ( $summary['preparation_avg'] ?? 0 ) . '/100',
			) as $label => $value
		) {
			echo '<div><strong>' . esc_html( (string) $value ) . '</strong><span>' . esc_html( $label ) . '</span></div>';
		}
		echo '</div>';
		echo '<div class="tgb-table-scroll"><table class="widefat striped"><thead><tr><th>Priority</th><th>Page</th><th>Google</th><th>GA4</th><th>Readiness</th><th>Action</th></tr></thead><tbody>';
		foreach ( array_slice( (array) $data['rows'], 0, 15 ) as $row ) {
			echo '<tr><td><strong>' . esc_html( (string) $row['priority'] ) . '/100</strong></td>';
			echo '<td><a href="' . esc_url( (string) $row['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( (string) $row['title'] ) . '</a></td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $row['impressions'] ) ) . ' imp. · ' . esc_html( number_format_i18n( (int) $row['clicks'] ) ) . ' clicks<br><small>CTR ' . esc_html( number_format_i18n( (float) $row['ctr'] * 100, 1 ) ) . '% · pos. ' . esc_html( number_format_i18n( (float) $row['position'], 1 ) ) . '</small></td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $row['views_ga4'] ) ) . ' vistas</td>';
			echo '<td>' . esc_html( (string) $row['preparation'] ) . '/100</td>';
			echo '<td>' . esc_html( (string) $row['action'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<p class="description">URL movement and query→URL fan-out are deliberately left uncalculated until those data are part of the modern snapshot.</p>';
		echo '</section>';
	}

	private static function render_content( $content ) {
		echo '<section class="tgb-executive-section">';
		echo '<div class="tgb-executive-section-head"><div><h3>Performance by content type</h3><p>Recovers the usefulness of previous Posts and Landing Page reports without creating two separate reports.</p></div></div>';
		foreach ( array( 'posts' => 'Posts', 'landings' => 'Landing Pages' ) as $key => $label ) {
			$group = (array) ( $content[ $key ] ?? array() );
			$summary = (array) ( $group['summary'] ?? array() );
			echo '<div class="tgb-content-group"><h4>' . esc_html( $label ) . '</h4>';
			echo '<div class="tgb-mini-kpis">';
			foreach (
				array(
					'Total' => $summary['total'] ?? 0,
					'With signal' => $summary['with_signal'] ?? 0,
					'GA4 views' => $summary['views'] ?? 0,
					'Google clicks' => $summary['clicks'] ?? 0,
					'Google impressions' => $summary['impressions'] ?? 0,
				) as $metric => $value
			) {
				echo '<div><strong>' . esc_html( number_format_i18n( (int) $value ) ) . '</strong><span>' . esc_html( $metric ) . '</span></div>';
			}
			echo '</div>';
			$rows = (array) ( $group['rows'] ?? array() );
			if ( $rows ) {
				echo '<div class="tgb-table-scroll"><table class="widefat striped"><thead><tr><th>Content</th><th>GA4 views</th><th>Clics</th><th>Impressions</th><th>Position</th></tr></thead><tbody>';
				foreach ( array_slice( $rows, 0, 10 ) as $row ) {
					echo '<tr><td><a href="' . esc_url( (string) $row['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( (string) $row['title'] ) . '</a></td>';
					echo '<td>' . esc_html( number_format_i18n( (int) $row['views'] ) ) . '</td>';
					echo '<td>' . esc_html( number_format_i18n( (int) $row['clicks'] ) ) . '</td>';
					echo '<td>' . esc_html( number_format_i18n( (int) $row['impressions'] ) ) . '</td>';
					echo '<td>' . esc_html( (float) $row['position'] > 0 ? number_format_i18n( (float) $row['position'], 1 ) : '—' ) . '</td></tr>';
				}
				echo '</tbody></table></div>';
			}
			echo '</div>';
		}
		echo '<p class="description">' . esc_html( (string) ( $content['coverage_note'] ?? '' ) ) . '</p>';
		echo '</section>';
	}

	private static function render_demand( $demand ) {
		$trends = (array) ( $demand['trends'] ?? array() );
		$market = (array) ( $demand['market'] ?? array() );
		echo '<section class="tgb-executive-section">';
		echo '<div class="tgb-executive-section-head"><div><h3>Demand and market</h3><p>External signals for context. They are not mixed with site traffic or converted into an SEO score.</p></div></div>';
		echo '<div class="tgb-demand-grid">';
		echo '<div class="tgb-demand-card"><h4>Google Trends · Spain</h4>';
		if ( ! empty( $trends['available'] ) ) {
			echo '<ol>';
			foreach ( array_slice( (array) $trends['items'], 0, 8 ) as $item ) {
				echo '<li><strong>' . esc_html( (string) $item['title'] ) . '</strong>';
				if ( ! empty( $item['traffic_label'] ) ) {
					echo ' <span>' . esc_html( (string) $item['traffic_label'] ) . '</span>';
				}
				echo '</li>';
			}
			echo '</ol>';
		} else {
			echo '<p>Sin snapshot guardado.</p>';
		}
		echo '<p><a href="' . esc_url( self::tab_url( 'google_trends' ) ) . '">Abrir Trends →</a></p></div>';

		$summary = (array) ( $market['summary'] ?? array() );
		echo '<div class="tgb-demand-card"><h4>Market / Merchant</h4>';
		if ( ! empty( $market['meta']['available'] ) ) {
			echo '<p><strong>' . esc_html( number_format_i18n( (int) ( $summary['analyzed_categories'] ?? 0 ) ) ) . '</strong> categories analyzed out of ' . esc_html( number_format_i18n( (int) ( $summary['categories'] ?? 0 ) ) ) . '.</p>';
			echo '<p><strong>' . esc_html( number_format_i18n( (int) ( $summary['evidence'] ?? 0 ) ) ) . '</strong> evidencias guardadas · <strong>' . esc_html( number_format_i18n( (int) ( $summary['merchant_evidence'] ?? 0 ) ) ) . '</strong> procedentes de Merchant.</p>';
			$providers = array();
			foreach ( (array) ( $market['providers'] ?? array() ) as $provider ) {
				$providers[] = (string) ( $provider['provider'] ?? '' ) . ': ' . number_format_i18n( (int) ( $provider['total'] ?? 0 ) );
			}
			if ( $providers ) {
				echo '<p class="description">' . esc_html( implode( ' · ', $providers ) ) . '</p>';
			}
		} else {
			echo '<p>No Market data yet.</p>';
		}
		echo '<p><a href="' . esc_url( self::tab_url( 'mercado' ) ) . '">Open Market →</a></p></div>';
		echo '</div></section>';
	}

	private static function kpi( $label, $current, $previous = null, $format = 'number', $lower_better = false ) {
		echo '<div class="tgb-kpi">';
		echo '<span class="tgb-kpi-label">' . esc_html( $label ) . '</span>';
		if ( null === $current ) {
			echo '<strong>—</strong><small>No data</small></div>';
			return;
		}
		$display = 'decimal' === $format
			? number_format_i18n( (float) $current, 1 )
			: number_format_i18n( (float) $current, 0 );
		echo '<strong>' . esc_html( $display ) . '</strong>';
		if ( null !== $previous && is_numeric( $previous ) && (float) $previous != 0.0 ) {
			$delta = ( ( (float) $current - (float) $previous ) / abs( (float) $previous ) ) * 100;
			$good = $lower_better ? $delta < 0 : $delta > 0;
			echo '<small class="' . esc_attr( $good ? 'is-positive' : ( 0.0 === $delta ? '' : 'is-negative' ) ) . '">' . esc_html( ( $delta > 0 ? '+' : '' ) . number_format_i18n( $delta, 1 ) . '%' ) . ' vs. previous period</small>';
		} else {
			echo '<small>Latest snapshot</small>';
		}
		echo '</div>';
	}
}
