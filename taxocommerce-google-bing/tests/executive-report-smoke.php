<?php
/**
 * Standalone regression check for the consolidated executive report.
 * Rendering and building the report must never perform network requests.
 */
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'TAXOCOMMERCE_GOOGLE_BING_URL', 'https://example.test/plugin/' );
define( 'TAXOCOMMERCE_GOOGLE_BING_VERSION', 'test' );

class WP_Error {
	private $message;
	public function __construct( $code, $message ) { $this->message = $message; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function wp_date( $format, $timestamp ) { return date( $format, $timestamp ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=test'; }
function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function number_format_i18n( $value, $decimals = 0 ) { return number_format( (float) $value, $decimals, ',', '.' ); }
function home_url( $path = '/' ) { return 'https://example.test' . ( '/' === $path ? '/' : $path ); }
function wp_parse_url( $url, $part = -1 ) { return parse_url( $url, $part ); }
function untrailingslashit( $value ) { return rtrim( (string) $value, '/' ); }
function strip_shortcodes( $value ) { return $value; }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function current_time( $type, $gmt = false ) { return 'timestamp' === $type ? time() : date( 'Y-m-d H:i:s' ); }
function get_post_modified_time( $format, $gmt, $post ) { return time() - 100 * DAY_IN_SECONDS; }
function wp_count_posts( $type ) { return (object) array( 'publish' => 1 ); }
function wp_remote_get() { throw new RuntimeException( 'Unexpected HTTP GET from executive report' ); }
function wp_remote_post() { throw new RuntimeException( 'Unexpected HTTP POST from executive report' ); }

$GLOBALS['tgb_posts'] = array(
	10 => (object) array(
		'ID' => 10,
		'post_type' => 'post',
		'post_status' => 'publish',
		'post_title' => 'Guía de taladros',
		'post_content' => '<h2>Elegir</h2><h2>Uso</h2><p>Contenido práctico para elegir herramienta.</p><p>Más contenido.</p><a href="/categoria/taladros/">Taladros</a>',
	),
	20 => (object) array(
		'ID' => 20,
		'post_type' => 'page',
		'post_status' => 'publish',
		'post_title' => 'Landing taladros',
		'post_content' => '<h2>Taladros</h2><h2>Aplicaciones</h2><p>Contenido de landing.</p><p>Más información.</p><a href="/producto/demo/">Producto</a>',
	),
);
function url_to_postid( $url ) {
	$path = parse_url( $url, PHP_URL_PATH );
	if ( '/blog/taladros/' === $path || '/blog/taladros' === $path ) return 10;
	if ( '/landing/taladros/' === $path || '/landing/taladros' === $path ) return 20;
	return 0;
}
function get_post( $id ) { return isset( $GLOBALS['tgb_posts'][ $id ] ) ? $GLOBALS['tgb_posts'][ $id ] : null; }
function get_post_type( $id ) { $post = get_post( $id ); return $post ? $post->post_type : ''; }
function get_the_title( $id ) { $post = get_post( $id ); return $post ? $post->post_title : ''; }
function get_permalink( $id ) { return 10 === (int) $id ? 'https://example.test/blog/taladros/' : 'https://example.test/landing/taladros/'; }

class TGB_Test_WPDB {
	public $prefix = 'wp_';
	public function prepare( $query ) {
		$args = func_get_args();
		array_shift( $args );
		foreach ( $args as $arg ) {
			$replacement = is_numeric( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
			$query = preg_replace( '/%[sd]/', $replacement, $query, 1 );
		}
		return $query;
	}
	public function esc_like( $value ) { return $value; }
	public function get_var( $query ) {
		if ( false !== strpos( $query, 'SHOW TABLES LIKE' ) && false !== strpos( $query, 'wp_seo_nodes' ) ) {
			return 'wp_seo_nodes';
		}
		return '';
	}
	public function get_col( $query ) {
		if ( false !== strpos( $query, 'wp_seo_nodes' ) ) return array( 20 );
		return array();
	}
	public function get_results( $query, $format = null ) { return array(); }
}
$GLOBALS['wpdb'] = new TGB_Test_WPDB();

$GLOBALS['tgb_reports'] = array(
	'search' => array(
		'one' => array(
			'source' => 'search',
			'downloaded_at' => 1792800000,
			'current' => array( 'start' => '2026-10-01', 'end' => '2026-10-07', 'label' => '01/10/2026 – 07/10/2026' ),
			'previous' => array( 'start' => '2026-09-24', 'end' => '2026-09-30', 'label' => '24/09/2026 – 30/09/2026' ),
			'data' => array(
				'summary' => array(
					'current' => array( 'clicks' => 10, 'impressions' => 1000, 'ctr' => 0.01, 'position' => 12.5 ),
					'previous' => array( 'clicks' => 8, 'impressions' => 800, 'ctr' => 0.01, 'position' => 14.0 ),
				),
				'pages' => array(
					array( 'name' => 'https://example.test/blog/taladros/', 'clicks' => 6, 'impressions' => 600, 'ctr' => 0.01, 'position' => 8.0 ),
					array( 'name' => 'https://example.test/landing/taladros/', 'clicks' => 4, 'impressions' => 400, 'ctr' => 0.01, 'position' => 18.0 ),
				),
				'queries' => array( array( 'name' => 'taladros', 'clicks' => 5, 'impressions' => 500, 'ctr' => 0.01, 'position' => 9.0 ) ),
			),
		),
	),
	'analytics' => array(
		'one' => array(
			'source' => 'analytics',
			'downloaded_at' => 1792800100,
			'current' => array( 'start' => '2026-10-01', 'end' => '2026-10-07', 'label' => '01/10/2026 – 07/10/2026' ),
			'previous' => array( 'start' => '2026-09-24', 'end' => '2026-09-30', 'label' => '24/09/2026 – 30/09/2026' ),
			'data' => array(
				'summary' => array(
					'current' => array( 'activeUsers' => 80, 'newUsers' => 50, 'sessions' => 120, 'screenPageViews' => 200, 'ecommercePurchases' => 3, 'engagementRate' => 0.6 ),
					'previous' => array( 'activeUsers' => 70, 'sessions' => 100, 'screenPageViews' => 180, 'ecommercePurchases' => 2 ),
				),
				'pages' => array(
					array( 'name' => '/blog/taladros/', 'views' => 60, 'previous_views' => 50 ),
					array( 'name' => '/landing/taladros/', 'views' => 40, 'previous_views' => 30 ),
				),
			),
		),
	),
	'bing' => array(),
);

class TaxoCommerce_Google_Bing_Reports {
	public static function saved_reports( $source ) {
		return isset( $GLOBALS['tgb_reports'][ $source ] ) ? $GLOBALS['tgb_reports'][ $source ] : array();
	}
}

require dirname( __DIR__ ) . '/includes/class-taxocommerce-google-bing-executive.php';

$report = TaxoCommerce_Google_Bing_Executive::report();
if ( 'taxocommerce-google-bing-executive' !== $report['schema']['name'] ) {
	fwrite( STDERR, "Unexpected executive schema\n" );
	exit( 1 );
}
if ( 10 !== $report['overview']['google_search']['clicks'] || 120 !== $report['overview']['analytics']['sessions'] ) {
	fwrite( STDERR, "Executive KPI aggregation failed\n" );
	exit( 1 );
}
if ( empty( $report['opportunities']['available'] ) || 2 !== count( $report['opportunities']['rows'] ) ) {
	fwrite( STDERR, "Opportunity migration failed\n" );
	exit( 1 );
}
if ( 1 !== $report['content']['posts']['summary']['with_signal'] || 1 !== $report['content']['landings']['summary']['with_signal'] ) {
	fwrite( STDERR, "Content-type aggregation failed\n" );
	exit( 1 );
}

ob_start();
TaxoCommerce_Google_Bing_Executive::render();
$html = ob_get_clean();
if ( false === strpos( $html, 'Executive Report' ) || false === strpos( $html, 'Content opportunities' ) ) {
	fwrite( STDERR, "Executive render failed\n" );
	exit( 1 );
}

echo "Executive report smoke test: OK (local snapshots only, no network requests)\n";
