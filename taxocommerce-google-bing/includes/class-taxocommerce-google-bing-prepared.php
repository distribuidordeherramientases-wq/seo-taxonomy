<?php
/**
 * Persisted prepared intelligence built from saved Insights reports.
 *
 * Provider reports remain the auditable source of truth. This layer performs
 * deterministic preprocessing when source data changes so Analyst can consume
 * normalized local data without calling providers or repeating report work.
 *
 * @package TaxoCommerceInsights
 */

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Google_Bing_Prepared {

	const OPTION = 'tgb_prepared_intelligence_v1';
	const SCHEMA_VERSION = 1;

	public static function register() {
		add_action( 'tgb_manual_report_saved', array( __CLASS__, 'refresh' ), 20, 3 );
		add_action( 'tgb_trends_snapshot_saved', array( __CLASS__, 'refresh' ), 20, 2 );
		add_action( 'tgb_market_evidence_saved', array( __CLASS__, 'refresh' ), 20, 3 );
	}

	/**
	 * Read the latest prepared dataset without external requests.
	 *
	 * If this is the first run after installation, build the dataset once from
	 * already-saved local reports and persist it for subsequent consumers.
	 */
	public static function current() {
		$stored = get_option( self::OPTION, array() );
		if ( is_array( $stored )
			&& self::SCHEMA_VERSION === absint( $stored['schema']['version'] ?? 0 )
			&& ! empty( $stored['prepared_at'] ) ) {
			return $stored;
		}
		return self::refresh();
	}

	/**
	 * Rebuild after any source report changes.
	 *
	 * Hook arguments are intentionally ignored; the complete dataset is rebuilt
	 * from the latest local snapshots to keep all source periods coherent.
	 */
	public static function refresh( ...$ignored ) {
		$prepared = self::build();
		update_option( self::OPTION, $prepared, false );
		do_action( 'tgb_prepared_intelligence_saved', $prepared );
		return $prepared;
	}

	private static function build() {
		$search = self::latest_snapshot( 'search' );
		$analytics = self::latest_snapshot( 'analytics' );
		$bing = self::latest_snapshot( 'bing' );
		$executive = class_exists( 'TaxoCommerce_Google_Bing_Executive' )
			? TaxoCommerce_Google_Bing_Executive::report()
			: array();

		$search_data = (array) ( $search['data'] ?? array() );
		$analytics_data = (array) ( $analytics['data'] ?? array() );
		$bing_data = (array) ( $bing['data'] ?? array() );
		$current_queries = array_values( (array) ( $search_data['queries'] ?? array() ) );
		$previous_queries = array_values( (array) ( $search_data['previous_queries'] ?? array() ) );
		$current_pages = array_values( (array) ( $search_data['pages'] ?? array() ) );

		$diagnostic = self::diagnostic_dashboard( $search, $analytics );
		$search_candidates = array_slice( $current_queries, 0, 180 );
		$top_queries = array_slice( $current_queries, 0, 15 );

		return array(
			'schema' => array(
				'name' => 'taxocommerce-insights-prepared',
				'version' => self::SCHEMA_VERSION,
			),
			'prepared_at' => time(),
			'sources' => (array) ( $executive['sources'] ?? array() ),
			'diagnostic' => $diagnostic,
			'executive' => $executive,
			'search' => array(
				'snapshot' => self::snapshot_meta( $search ),
				'summary' => (array) ( $search_data['summary'] ?? array() ),
				'daily' => (array) ( $search_data['daily'] ?? array() ),
				'distribution' => (array) ( $diagnostic['distribution'] ?? array() ),
				'previous_distribution' => (array) ( $diagnostic['previous_distribution'] ?? array() ),
				'movement' => (array) ( $diagnostic['movement'] ?? array() ),
				'page_types' => (array) ( $diagnostic['page_types'] ?? array() ),
				'top_queries' => $top_queries,
				'candidates' => $search_candidates,
				'countries' => array_slice( (array) ( $search_data['countries'] ?? array() ), 0, 100 ),
				'devices' => array_slice( (array) ( $search_data['devices'] ?? array() ), 0, 20 ),
				'pages' => array_slice( $current_pages, 0, 150 ),
			),
			'analytics' => array(
				'snapshot' => self::snapshot_meta( $analytics ),
				'summary' => (array) ( $analytics_data['summary'] ?? array() ),
				'users' => (array) ( $analytics_data['users'] ?? array() ),
				'funnel' => (array) ( $analytics_data['funnel'] ?? array() ),
				'countries' => (array) ( $analytics_data['countries'] ?? array() ),
				'channels' => (array) ( $analytics_data['channels'] ?? array() ),
				'devices' => (array) ( $analytics_data['devices'] ?? array() ),
				'pages' => array_slice( (array) ( $analytics_data['pages'] ?? array() ), 0, 150 ),
				'daily' => (array) ( $analytics_data['daily'] ?? array() ),
			),
			'bing' => array(
				'snapshot' => self::snapshot_meta( $bing ),
				'traffic' => (array) ( $bing_data['traffic'] ?? array() ),
				'crawl' => (array) ( $bing_data['crawl'] ?? array() ),
				'queries' => array_slice( (array) ( $bing_data['queries'] ?? array() ), 0, 150 ),
				'pages' => array_slice( (array) ( $bing_data['pages'] ?? array() ), 0, 150 ),
				'daily' => (array) ( $bing_data['daily'] ?? array() ),
				'warnings' => (array) ( $bing_data['warnings'] ?? array() ),
			),
			'trends' => (array) ( $executive['demand']['trends'] ?? array() ),
			'market' => (array) ( $executive['demand']['market'] ?? array() ),
			'notes' => array(
				'Provider APIs are queried only by their explicit report or research actions.',
				'Prepared Intelligence is rebuilt from saved local evidence after source updates.',
				'Analyst may interpret and prioritize these prepared signals but should not repeat provider retrieval or deterministic report preprocessing.',
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
		$stamp = 0;
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$current = absint( $entry['downloaded_at'] ?? 0 );
			if ( $current >= $stamp ) {
				$latest = $entry;
				$stamp = $current;
			}
		}
		return $latest;
	}

	private static function snapshot_meta( $snapshot ) {
		if ( ! is_array( $snapshot ) || ! $snapshot ) {
			return array(
				'available' => false,
				'downloaded_at' => 0,
				'current' => array(),
				'previous' => array(),
			);
		}
		return array(
			'available' => true,
			'downloaded_at' => absint( $snapshot['downloaded_at'] ?? 0 ),
			'current' => (array) ( $snapshot['current'] ?? array() ),
			'previous' => (array) ( $snapshot['previous'] ?? array() ),
		);
	}

	private static function diagnostic_dashboard( $search, $analytics ) {
		$search_data = (array) ( $search['data'] ?? array() );
		$analytics_data = (array) ( $analytics['data'] ?? array() );
		$current = (array) ( $search_data['summary']['current'] ?? array() );
		$previous = (array) ( $search_data['summary']['previous'] ?? array() );
		$current_queries = (array) ( $search_data['queries'] ?? array() );
		$previous_queries = (array) ( $search_data['previous_queries'] ?? array() );
		$current_pages = (array) ( $search_data['pages'] ?? array() );

		$distribution = self::position_distribution( $current_queries );
		$previous_distribution = self::position_distribution( $previous_queries );
		$movement = self::keyword_movement( $current_queries, $previous_queries );

		$ga4_current = (array) ( $analytics_data['summary']['current'] ?? array() );
		$ga4_previous = (array) ( $analytics_data['summary']['previous'] ?? array() );
		$funnel_current = (array) ( $analytics_data['funnel']['current'] ?? array() );
		$funnel_previous = (array) ( $analytics_data['funnel']['previous'] ?? array() );

		$country_labels = (array) ( $analytics_data['countries']['labels'] ?? array() );
		$country_current = (array) ( $analytics_data['countries']['current'] ?? array() );
		$country_previous = (array) ( $analytics_data['countries']['previous'] ?? array() );
		$spain_current = self::country_value( $country_labels, $country_current, array( 'spain', 'espana' ) );
		$spain_previous = self::country_value( $country_labels, $country_previous, array( 'spain', 'espana' ) );

		$impressions_change = self::raw_percent_delta(
			(float) ( $current['impressions'] ?? 0 ),
			(float) ( $previous['impressions'] ?? 0 )
		);
		$position_change = (float) ( $previous['position'] ?? 0 ) - (float) ( $current['position'] ?? 0 );

		$quick = array();
		$quick['coverage'] = null === $impressions_change
			? 'Google empieza a mostrar nuevas URLs y consultas frente a una base anterior de cero.'
			: 'Las impresiones cambian ' . number_format_i18n( $impressions_change, 1 ) . '% frente al periodo anterior.';
		$quick['ranking'] = $position_change > 0
			? 'La posición media mejora ' . number_format_i18n( $position_change, 1 ) . ' puestos.'
			: ( $position_change < 0
				? 'La posición media retrocede ' . number_format_i18n( abs( $position_change ), 1 ) . ' puestos.'
				: 'La posición media permanece estable.' );
		$quick['movement'] = number_format_i18n( (int) ( $movement['improved'] ?? 0 ) )
			. ' keywords suben y ' . number_format_i18n( (int) ( $movement['declined'] ?? 0 ) )
			. ' bajan respecto al periodo anterior.';
		if ( $funnel_current ) {
			$quick['funnel'] = number_format_i18n( (int) ( $funnel_current['view_item'] ?? 0 ) )
				. ' vistas de producto → ' . number_format_i18n( (int) ( $funnel_current['add_to_cart'] ?? 0 ) )
				. ' carritos → ' . number_format_i18n( (int) ( $funnel_current['begin_checkout'] ?? 0 ) )
				. ' checkout → ' . number_format_i18n( (int) ( $funnel_current['purchase'] ?? 0 ) ) . ' compras medidas.';
		}
		if ( $spain_current > 0 ) {
			$total_users = max( 0, (int) ( $ga4_current['activeUsers'] ?? 0 ) );
			$share = $total_users > 0 ? ( $spain_current / $total_users ) * 100 : 0;
			$quick['market'] = number_format_i18n( $spain_current ) . ' usuarios activos de España'
				. ( $share > 0 ? ' (' . number_format_i18n( $share, 1 ) . '% del total).' : '.' );
		}

		return array(
			'available' => ! empty( $search_data ),
			'needs_refresh' => ! empty( $current_queries ) && empty( $previous_queries ),
			'search_downloaded_at' => absint( $search['downloaded_at'] ?? 0 ),
			'analytics_downloaded_at' => absint( $analytics['downloaded_at'] ?? 0 ),
			'period' => array(
				'current' => (array) ( $search['current'] ?? array() ),
				'previous' => (array) ( $search['previous'] ?? array() ),
			),
			'metrics' => array(
				'visibility' => array( self::visibility_index( $distribution ), self::visibility_index( $previous_distribution ) ),
				'impressions' => array( (int) ( $current['impressions'] ?? 0 ), (int) ( $previous['impressions'] ?? 0 ) ),
				'position' => array( (float) ( $current['position'] ?? 0 ), (float) ( $previous['position'] ?? 0 ) ),
				'keywords' => array( count( $current_queries ), count( $previous_queries ) ),
				'pages' => array( count( $current_pages ), count( (array) ( $search_data['previous_pages'] ?? array() ) ) ),
				'ctr' => array( (float) ( $current['ctr'] ?? 0 ), (float) ( $previous['ctr'] ?? 0 ) ),
				'sessions' => array( (int) ( $ga4_current['sessions'] ?? 0 ), (int) ( $ga4_previous['sessions'] ?? 0 ) ),
				'users' => array( (int) ( $ga4_current['activeUsers'] ?? 0 ), (int) ( $ga4_previous['activeUsers'] ?? 0 ) ),
				'view_item' => array( (int) ( $funnel_current['view_item'] ?? 0 ), (int) ( $funnel_previous['view_item'] ?? 0 ) ),
				'add_to_cart' => array( (int) ( $funnel_current['add_to_cart'] ?? 0 ), (int) ( $funnel_previous['add_to_cart'] ?? 0 ) ),
				'begin_checkout' => array( (int) ( $funnel_current['begin_checkout'] ?? 0 ), (int) ( $funnel_previous['begin_checkout'] ?? 0 ) ),
				'purchases' => array(
					(int) ( $funnel_current['purchase'] ?? $ga4_current['ecommercePurchases'] ?? 0 ),
					(int) ( $funnel_previous['purchase'] ?? $ga4_previous['ecommercePurchases'] ?? 0 ),
				),
				'spain' => array( $spain_current, $spain_previous ),
			),
			'distribution' => $distribution,
			'previous_distribution' => $previous_distribution,
			'movement' => $movement,
			'daily' => (array) ( $search_data['daily'] ?? array() ),
			'page_types' => self::page_type_summary( $current_pages ),
			'architecture' => self::architecture_snapshot(),
			'quick_read' => $quick,
		);
	}

	private static function position_distribution( $rows ) {
		$out = array(
			'top3' => 0,
			'top10' => 0,
			'top20' => 0,
			'top50' => 0,
			'top100' => 0,
			'outside' => 0,
		);
		foreach ( (array) $rows as $row ) {
			$position = (float) ( $row['position'] ?? 0 );
			if ( $position <= 0 ) {
				continue;
			}
			if ( $position <= 3 ) {
				$out['top3']++;
			} elseif ( $position <= 10 ) {
				$out['top10']++;
			} elseif ( $position <= 20 ) {
				$out['top20']++;
			} elseif ( $position <= 50 ) {
				$out['top50']++;
			} elseif ( $position <= 100 ) {
				$out['top100']++;
			} else {
				$out['outside']++;
			}
		}
		return $out;
	}

	private static function visibility_index( $distribution ) {
		$total = array_sum( (array) $distribution );
		if ( $total < 1 ) {
			return 0.0;
		}
		$weighted = ( (int) ( $distribution['top3'] ?? 0 ) * 100 )
			+ ( (int) ( $distribution['top10'] ?? 0 ) * 72 )
			+ ( (int) ( $distribution['top20'] ?? 0 ) * 45 )
			+ ( (int) ( $distribution['top50'] ?? 0 ) * 18 )
			+ ( (int) ( $distribution['top100'] ?? 0 ) * 5 );
		return round( $weighted / $total, 1 );
	}

	private static function keyword_movement( $current_rows, $previous_rows ) {
		$current = self::query_map( $current_rows );
		$previous = self::query_map( $previous_rows );
		$out = array(
			'improved' => 0,
			'stable' => 0,
			'declined' => 0,
			'new' => 0,
			'lost' => 0,
			'net_top100' => 0,
			'net_top50' => 0,
			'net_top20' => 0,
			'net_top10' => 0,
			'net_top3' => 0,
		);
		foreach ( $current as $key => $row ) {
			$position = (float) ( $row['position'] ?? 0 );
			if ( $position <= 0 ) {
				continue;
			}
			if ( ! isset( $previous[ $key ] ) || (float) ( $previous[ $key ]['position'] ?? 0 ) <= 0 ) {
				$out['new']++;
				continue;
			}
			$movement = (float) $previous[ $key ]['position'] - $position;
			if ( $movement >= 1.0 ) {
				$out['improved']++;
			} elseif ( $movement <= -1.0 ) {
				$out['declined']++;
			} else {
				$out['stable']++;
			}
		}
		foreach ( $previous as $key => $row ) {
			if ( ! isset( $current[ $key ] ) && (float) ( $row['position'] ?? 0 ) > 0 ) {
				$out['lost']++;
			}
		}
		$current_distribution = self::position_distribution( $current_rows );
		$previous_distribution = self::position_distribution( $previous_rows );
		foreach ( array( 100, 50, 20, 10, 3 ) as $threshold ) {
			$out[ 'net_top' . $threshold ] = self::threshold_total( $current_distribution, $threshold )
				- self::threshold_total( $previous_distribution, $threshold );
		}
		return $out;
	}

	private static function query_map( $rows ) {
		$out = array();
		foreach ( (array) $rows as $row ) {
			$key = self::query_key( $row['name'] ?? '' );
			if ( '' !== $key ) {
				$out[ $key ] = (array) $row;
			}
		}
		return $out;
	}

	private static function threshold_total( $distribution, $threshold ) {
		$value = (int) ( $distribution['top3'] ?? 0 );
		if ( $threshold >= 10 ) $value += (int) ( $distribution['top10'] ?? 0 );
		if ( $threshold >= 20 ) $value += (int) ( $distribution['top20'] ?? 0 );
		if ( $threshold >= 50 ) $value += (int) ( $distribution['top50'] ?? 0 );
		if ( $threshold >= 100 ) $value += (int) ( $distribution['top100'] ?? 0 );
		return $value;
	}

	private static function query_key( $query ) {
		$query = remove_accents( wp_strip_all_tags( (string) $query ) );
		$query = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query, 'UTF-8' ) : strtolower( $query );
		$query = preg_replace( '/[^a-z0-9]+/i', ' ', $query );
		return trim( preg_replace( '/\s+/', ' ', (string) $query ) );
	}

	private static function raw_percent_delta( $current, $previous ) {
		if ( 0.0 === (float) $previous ) {
			return (float) $current > 0 ? null : 0.0;
		}
		return ( ( (float) $current - (float) $previous ) / abs( (float) $previous ) ) * 100;
	}

	private static function country_value( $labels, $values, $names ) {
		foreach ( (array) $labels as $index => $label ) {
			$normalized = remove_accents( (string) $label );
			$normalized = function_exists( 'mb_strtolower' )
				? mb_strtolower( $normalized, 'UTF-8' )
				: strtolower( $normalized );
			if ( in_array( $normalized, (array) $names, true ) ) {
				return (int) ( $values[ $index ] ?? 0 );
			}
		}
		return 0;
	}

	private static function page_type_summary( $pages ) {
		$out = array();
		foreach ( (array) $pages as $row ) {
			$url = (string) ( $row['name'] ?? '' );
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			$type = 'Landing / página';
			if ( '' === $path || '/' === $path ) {
				$type = 'Portada';
			} elseif ( 0 === strpos( $path, '/producto/' ) ) {
				$type = 'Producto';
			} elseif ( 0 === strpos( $path, '/tienda/' ) || 0 === strpos( $path, '/categoria-producto/' ) ) {
				$type = 'Categoría';
			} elseif ( 0 === strpos( $path, '/noticias/' ) ) {
				$type = 'Entrada';
			} elseif ( 0 === strpos( $path, '/marca/' ) ) {
				$type = 'Marca';
			}
			if ( ! isset( $out[ $type ] ) ) {
				$out[ $type ] = array( 'pages' => 0, 'clicks' => 0, 'impressions' => 0 );
			}
			$out[ $type ]['pages']++;
			$out[ $type ]['clicks'] += (int) ( $row['clicks'] ?? 0 );
			$out[ $type ]['impressions'] += (int) ( $row['impressions'] ?? 0 );
		}
		uasort(
			$out,
			static function ( $left, $right ) {
				return (int) $right['impressions'] <=> (int) $left['impressions'];
			}
		);
		return $out;
	}

	private static function architecture_snapshot() {
		$out = array(
			'clusters' => 0,
			'hub_primary' => 0,
			'hub_secondary' => 0,
			'categories' => 0,
			'products' => 0,
		);
		foreach ( array( 'cluster' => 'clusters', 'hub_primary' => 'hub_primary', 'hub_secondary' => 'hub_secondary' ) as $role => $key ) {
			$ids = get_posts(
				array(
					'post_type' => 'page',
					'post_status' => 'publish',
					'fields' => 'ids',
					'posts_per_page' => -1,
					'no_found_rows' => true,
					'meta_key' => '_taxocommerce_core_seo_role',
					'meta_value' => $role,
				)
			);
			$out[ $key ] = count( (array) $ids );
		}
		if ( taxonomy_exists( 'product_cat' ) ) {
			$count = wp_count_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
			$out['categories'] = is_wp_error( $count ) ? 0 : (int) $count;
		}
		if ( post_type_exists( 'product' ) ) {
			$counts = wp_count_posts( 'product' );
			$out['products'] = isset( $counts->publish ) ? (int) $counts->publish : 0;
		}
		return $out;
	}
}
