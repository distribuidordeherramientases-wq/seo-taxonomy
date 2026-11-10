<?php
/**
 * Standalone smoke tests for the read-only executive report.
 *
 * Run: php taxocommerce-google-bing/tests/executive-smoke.php
 */
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'TAXOCOMMERCE_GOOGLE_BING_URL', 'https://example.org/plugins/insights/' );
define( 'TAXOCOMMERCE_GOOGLE_BING_VERSION', 'test' );
$GLOBALS['options'] = array();
$GLOBALS['calls'] = array( 'external' => 0, 'write' => 0 );

function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option() { ++$GLOBALS['calls']['write']; throw new RuntimeException( 'Unexpected option write' ); }
function wp_remote_get() { ++$GLOBALS['calls']['external']; throw new RuntimeException( 'Unexpected outbound call' ); }
function wp_remote_post() { ++$GLOBALS['calls']['external']; throw new RuntimeException( 'Unexpected outbound call' ); }
function current_user_can( $role ) { return 'manage_options' === $role; }
function wp_enqueue_style() {}
function __( $text ) { return $text; }
function esc_html__( $text ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function admin_url( $path ) { return 'https://example.org/wp-admin/' . $path; }
function wp_die( $message ) { throw new RuntimeException( (string) $message ); }
function check( $condition, $name ) { if ( ! $condition ) { throw new RuntimeException( $name ); } }
class TaxoCommerce_Google_Bing_Reports {
    public static function saved_reports( $source ) {
        return get_option( 'tgb_manual_report_v1_' . $source, array() );
    }
}
require dirname( __DIR__ ) . '/includes/class-taxocommerce-insights-executive.php';
$cls = 'TaxoCommerce_Insights_Executive';
check( null === $cls::snapshot( 'invalid' ), 'Reject unknown provider' );
check( null === $cls::snapshot( 'search' ), 'No snapshot is not zero' );
check( null === $cls::movement( 3, 0 ), 'Previous zero is not a trend' );
check( null === $cls::movement( 3, 1 ), 'Insufficient sample is not a trend' );
check( 'down' === $cls::movement( 80, 100 )['trend'], 'Sign of decline' );
$now = time();
$one = array(
    'downloaded_at' => $now,
    'current' => array( 'start' => '2026-10-10', 'end' => '2026-11-08' ),
    'previous' => array( 'start' => '2026-09-10', 'end' => '2026-10-09' ),
    'data' => array( 'summary' => array(
        'current' => array( 'clicks' => 120, 'impressions' => 1000 ),
        'previous' => array( 'clicks' => 100, 'impressions' => 750 ),
    ) ),
);
$GLOBALS['options']['tgb_manual_report_v1_search'] = array( 'x' => $one );
check( is_array( $cls::snapshot( 'search' ) ), 'Snapshot public read API' );
$empty = $cls::prepare( array(), array() );
check( ! $empty['sources']['search']['available'] && ! $empty['signals'], 'Empty sources do not create trends' );
$results = $cls::prepare( array( 'search' => $one ), array(
    'first' => array( 'status' => 'proposed', 'last_seen' => '2026-11-08', 'last_task' => array(
        'task_id' => 'abc', 'topic' => 'Revisar categoría', 'priority' => 80, 'work_bucket' => 'HACER_AHORA',
    ) ),
    'done' => array( 'status' => 'executed', 'last_task' => array(
        'task_id' => 'def', 'topic' => 'Ya realizado', 'priority' => 99, 'work_bucket' => 'HACER_AHORA',
    ) ),
) );
check( $results['sources']['search']['available'], 'Saved source is available' );
check( 2 === count( $results['signals'] ), 'Only two evidence-backed changes' );
check( 1 === count( $results['tasks'] ) && 'Revisar categoría' === $results['tasks'][0]['title'], 'Deduplicated active task history' );
$older = $one;
$older['downloaded_at'] = $now - 9 * DAY_IN_SECONDS;
$stale = $cls::prepare( array( 'search' => $older ), array() );
check( $stale['sources']['search']['stale'] && ! $stale['signals'], 'Stale snapshots do not trigger recommendations' );
$second = $one;
$second['current']['start'] = '2026-10-15';
$second['data']['summary']['current'] = array( 'sessions' => 80, 'ecommercePurchases' => 1 );
$second['data']['summary']['previous'] = array( 'sessions' => 100, 'ecommercePurchases' => 0 );
$mismatch = $cls::prepare( array( 'search' => $one, 'analytics' => $second ), array() );
check( ! $mismatch['aligned'], 'Mismatch in source dates is visible' );
ob_start();
$cls::render();
$html = ob_get_clean();
check( false !== strpos( $html, 'Informes ejecutivos' ), 'Executive tab title' );
check( false !== strpos( $html, 'Ver datos técnicos' ), 'Drill-down to technical report' );
check( 0 === $GLOBALS['calls']['external'] && 0 === $GLOBALS['calls']['write'], 'Rendering has zero outbound calls and zero writes' );
echo "Executive Insights: snapshot reuse, freshness, trend quality, permissions and read-only rendering: OK\n";
