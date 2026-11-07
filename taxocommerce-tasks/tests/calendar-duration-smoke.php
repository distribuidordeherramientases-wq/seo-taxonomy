<?php
/**
 * Isolated calendar layout and rendering regression tests; no WordPress needed.
 */

define( 'ABSPATH', __DIR__ . '/' );
$main = file_get_contents( dirname( __DIR__ ) . '/taxocommerce-tasks.php' );
preg_match( '/^ \* Version: (\d+\.\d+\.\d+)$/m', $main, $match );
define( 'TAXOCOMMERCE_TASKS_VERSION', $match[1] ?? '' );

function tctasks_date( $value ) {
    if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
        return null;
    }
    $parts = array_map( 'intval', explode( '-', $value ) );
    return checkdate( $parts[1], $parts[2], $parts[0] ) ? $value : null;
}
function tctasks_priorities() { return array( 'low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent' ); }
function tctasks_states() { return array( 'pending' => 'Pending', 'progress' => 'In progress', 'blocked' => 'Blocked', 'completed' => 'Completed' ); }
function tctasks_label( $value ) { return $value; }
function tctasks_tables() { return array( 'tasks' => 'wp_tctasks_tasks' ); }
function current_user_can( $capability ) { return 'manage_options' === $capability; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function wp_unslash( $value ) { return $value; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function wp_date( $format, $timestamp = null ) { return gmdate( $format, $timestamp ? $timestamp : strtotime( '2026-11-07 12:00:00 UTC' ) ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $value, $domain ) { return $value; }
function admin_url( $value ) { return 'https://example.test/wp-admin/' . $value; }
function add_query_arg( $values, $url ) { return $url . '?' . http_build_query( $values ); }
function plugins_url( $path, $plugin ) { return 'https://example.test/wp-content/plugins/taxocommerce-tasks/' . $path; }
function wp_enqueue_style( $handle, $url, $deps, $version ) {
    $GLOBALS['tctasks_styles'][] = array( $handle, $url, $version );
}
function assert_tasks( $result, $label ) {
    if ( ! $result ) {
        fwrite( STDERR, "FAIL: $label\n" );
        exit( 1 );
    }
}
class TCTasks_Calendar_TestDB {
    public $last_sql = '';
    public $last_args = array();
    public $rows = array();

    public function prepare( $sql, ...$values ) {
        $this->last_sql = $sql;
        $this->last_args = $values;
        return $sql;
    }
    public function get_results( $sql ) { return $this->rows; }
}
require_once dirname( __DIR__ ) . '/includes/class-tctasks-calendar.php';

$make = static function ( $id, $start, $end, $priority = 'normal', $status = 'pending', $title = 'Task' ) {
    return (object) array(
        'id' => $id, 'start_date' => $start, 'due_date' => $end,
        'priority' => $priority, 'status' => $status, 'title' => $title,
    );
};

$long = $make( 1, '2026-11-14', '2026-11-27', 'urgent', 'progress', 'Long task' );
$overlap = $make( 2, '2026-11-20', '2026-11-23', 'high', 'blocked', 'Overlapping task' );
$single = $make( 3, null, '2026-11-21', 'low', 'pending', 'Only due date' );
$start_only = $make( 4, '2026-11-19', null );
$undated = $make( 5, null, null );
$reverse = $make( 6, '2026-11-27', '2026-11-14' );
assert_tasks( TCTasks_Calendar::interval( $undated ) === null, 'tasks without dates excluded' );
assert_tasks( TCTasks_Calendar::interval( $single ) === array( '2026-11-21', '2026-11-21' ), 'due-date-only is one day' );
assert_tasks( TCTasks_Calendar::interval( $start_only ) === array( '2026-11-19', '2026-11-19' ), 'start-date-only is one day' );
assert_tasks( TCTasks_Calendar::interval( $reverse ) === array( '2026-11-14', '2026-11-27' ), 'legacy inverted dates normalized for display' );

$week = static function ( $date ) { return new DateTimeImmutable( $date, new DateTimeZone( 'UTC' ) ); };
$lanes = array();
$week1 = TCTasks_Calendar::week_layout( array( $long, $overlap, $single, $undated ), $week( '2026-11-09' ), $lanes );
assert_tasks( count( $week1['bars'] ) === 1, 'first week includes only the starting fragment' );
assert_tasks( $week1['bars'][0]['col'] === 6 && $week1['bars'][0]['span'] === 2 && $week1['bars'][0]['after'], 'first-week bar from Saturday to Sunday' );

$week2 = TCTasks_Calendar::week_layout( array( $long, $overlap, $single, $start_only, $undated ), $week( '2026-11-16' ), $lanes );
assert_tasks( $week2['lanes'] >= 2, 'overlapping tasks use multiple lanes' );
$by_id = array();
foreach ( $week2['bars'] as $bar ) { $by_id[ $bar['task']->id ] = $bar; }
assert_tasks( $by_id[1]['col'] === 1 && $by_id[1]['span'] === 7 && $by_id[1]['before'] && $by_id[1]['after'], 'full middle-week continuation' );
assert_tasks( $by_id[2]['col'] === 5 && $by_id[2]['span'] === 3 && $by_id[2]['after'], 'overlap split at next Monday' );
assert_tasks( $by_id[3]['col'] === 6 && $by_id[3]['span'] === 1, 'single-day event' );
assert_tasks( $by_id[1]['lane'] !== $by_id[2]['lane'], 'overlapping bars must never share a lane' );
assert_tasks( $by_id[1]['lane'] !== $by_id[3]['lane'], 'single date cannot overlay full-week duration' );
$week3 = TCTasks_Calendar::week_layout( array( $long, $overlap ), $week( '2026-11-23' ), $lanes );
assert_tasks( count( $week3['bars'] ) === 2 && $week3['bars'][0]['col'] === 1, 'next-week continuation included' );
assert_tasks( $week3['bars'][0]['span'] === 5, 'long task stops at Friday 27 November' );

// A duration crossing a month boundary must be visible in both months.
$cross = $make( 7, '2026-10-29', '2026-11-03', 'high', 'completed' );
$test = TCTasks_Calendar::week_layout( array( $cross ), $week( '2026-10-26' ), $lanes );
assert_tasks( count( $test['bars'] ) === 1 && $test['bars'][0]['col'] === 4 && $test['bars'][0]['span'] === 4, 'October segment' );
$test = TCTasks_Calendar::week_layout( array( $cross ), $week( '2026-11-02' ), $lanes );
assert_tasks( count( $test['bars'] ) === 1 && $test['bars'][0]['col'] === 1 && $test['bars'][0]['span'] === 2, 'November segment' );

// Rendering tests check escaping, every week, SQL interval bounds and edit links.
$wpdb = new TCTasks_Calendar_TestDB();
$wpdb->rows = array( $long, $overlap, $single, $cross, $make( 8, '2026-11-07', '2026-11-08', 'normal', 'pending', '<script>unsafe</script>' ) );
$_GET['month'] = '2026-11';
$_GET['page'] = 'taxocommerce-tasks';
$_GET['tab'] = 'calendar';
ob_start();
TCTasks_Calendar::render();
$output = ob_get_clean();
assert_tasks( strpos( $wpdb->last_sql, 'start_date IS NOT NULL' ) !== false, 'SQL includes start dates for cross-month tasks' );
assert_tasks( strpos( $wpdb->last_sql, 'start_date IS NOT NULL AND due_date IS NULL AND start_date BETWEEN' ) !== false, 'one-date tasks must be bounded by visible week grid' );
assert_tasks( $wpdb->last_args[0] === 'wp_tctasks_tasks' && $wpdb->last_args[1] === '2026-12-06' && $wpdb->last_args[2] === '2026-10-26', 'SQL query covers the complete visible grid' );
assert_tasks( strpos( $output, 'tctasks-calendar-bar tctasks-priority-urgent' ) !== false, 'priority class rendered' );
assert_tasks( strpos( $output, 'tctasks-status-blocked' ) !== false, 'blocked state rendered' );
assert_tasks( strpos( $output, 'tctasks-continues-right' ) !== false, 'cross-week indicator rendered' );
assert_tasks( strpos( $output, 'edit=1' ) !== false, 'task editing hyperlink present' );
assert_tasks( strpos( $output, '&lt;script&gt;unsafe&lt;/script&gt;' ) !== false && strpos( $output, '<script>unsafe</script>' ) === false, 'task title escaped' );
assert_tasks( substr_count( $output, 'class="tctasks-calendar-week"' ) === 6, 'November month displays six full week rows' );
TCTasks_Calendar::enqueue_assets();
assert_tasks( count( $GLOBALS['tctasks_styles'] ) === 1 && $GLOBALS['tctasks_styles'][0][2] === TAXOCOMMERCE_TASKS_VERSION, 'calendar CSS enqueued with correct version' );

echo "PASS: duration bars, collisions, month boundaries, missing dates, SQL range, security and scoped CSS.\n";
