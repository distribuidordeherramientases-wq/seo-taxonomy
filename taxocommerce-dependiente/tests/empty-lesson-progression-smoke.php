<?php
/**
 * Isolated runtime test for empty lessons and the bounded admin fallback.
 * WordPress, WooCommerce and the database are entirely stubbed.
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['tcd_opts'] = array();
$GLOBALS['tcd_scheduled'] = array();
$GLOBALS['tcd_uuid'] = 0;
function get_option( $name, $fallback = false ) {
    return array_key_exists( $name, $GLOBALS['tcd_opts'] ) ? $GLOBALS['tcd_opts'][ $name ] : $fallback;
}
function update_option( $name, $value, $autoload = false ) { $GLOBALS['tcd_opts'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['tcd_opts'][ $name ] ); return true; }
function add_option( $name, $value, $deprecated = '', $autoload = false ) {
    if ( array_key_exists( $name, $GLOBALS['tcd_opts'] ) ) return false;
    $GLOBALS['tcd_opts'][ $name ] = $value;
    return true;
}
function wp_parse_args( $args, $defaults = array() ) { return array_merge( (array) $defaults, (array) $args ); }
function absint( $v ) { return max( 0, (int) $v ); }
function current_time( $format ) { return '2026-11-05 21:20:00'; }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return (string) $v; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function is_wp_error( $v ) { return false; }
function wp_generate_uuid4() { return 'test-owner-' . ++$GLOBALS['tcd_uuid']; }
function wp_cache_delete( $key, $group = '' ) { return true; }
function wp_next_scheduled( $hook ) { return $GLOBALS['tcd_scheduled'][ $hook ] ?? false; }
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['tcd_scheduled'][ $hook ] ); }
function wp_schedule_single_event( $when, $hook ) { $GLOBALS['tcd_scheduled'][ $hook ] = $when; return true; }
function current_user_can( $permission ) { return in_array( $permission, array( 'manage_options', 'manage_woocommerce' ), true ); }
function post_type_exists( $type ) { return 'product' === $type; }
function get_posts( $query ) { return array(); }

class TestTCDDatabase {
    public $prefix = 'test_';
    public $options = 'test_options';
    public $insert_id = 0;
    public $last_error = '';
    public $last_query = '';
    public $snapshots = array();
    public $snapshot_items = array();
    public $fail_next_count = false;
    public function insert( $table, $row ) {
        ++$this->insert_id;
        $this->snapshots[ $this->insert_id ] = array_merge( $row, array( 'id' => $this->insert_id ) );
        return 1;
    }
    public function replace( $table, $row ) {
        $this->snapshot_items[ $this->insert_id ][] = $row;
        return 1;
    }
    public function prepare( $sql, ...$args ) { return $sql; }
    public function get_var( $sql ) {
        if ( $this->fail_next_count ) {
            $this->fail_next_count = false;
            $this->last_error = 'Simulated SQL failure';
            return 0;
        }
        $this->last_error = '';
        return count( $this->snapshot_items[ $this->insert_id ] ?? array() );
    }
    public function get_col( $sql ) {
        return array_map( static function ( $r ) { return $r['signature']; }, $this->snapshot_items[ $this->insert_id ] ?? array() );
    }
    public function update( $table, $values, $where ) {
        $id = absint( $where['id'] ?? 0 );
        if ( ! isset( $this->snapshots[ $id ] ) ) return false;
        $this->snapshots[ $id ] = array_merge( $this->snapshots[ $id ], $values );
        return 1;
    }
    public function query( $sql ) {
        unset( $GLOBALS['tcd_opts']['tcd_learning_tick_lock'] );
        return 1;
    }
}
$GLOBALS['wpdb'] = new TestTCDDatabase();

class TCD_Load {
    public static function batch_size() { return 4; }
    public static function delay() { return 1; }
    public static function begin() { return microtime( true ); }
    public static function finish( $a, $n ) { return array( 'priority' => 3, 'batch' => 4, 'next_batch' => 4, 'elapsed' => 0.001 ); }
}
class TCD_Sources {
    public static function syllabus() { return array( 1 => array( 'key' => 'categories' ), 2 => array( 'key' => 'products' ), 3 => array( 'key' => 'vocabulary' ) ); }
    public static function batch( $lesson, $cursor, $limit ) {
        if ( 1 === $lesson ) {
            return array( 'items' => array( array(
                'source_type' => 'termproduct_cat', 'source_id' => 7,
                'source_key' => 'termproduct_cat:7', 'signature' => 'cat-signature',
                'title' => 'Sin categorizar', 'facts' => array( 'products' => 0 ),
            ) ), 'cursor' => 1, 'more' => false );
        }
        return array( 'items' => array(), 'cursor' => 0, 'more' => false );
    }
}
class TCD_Intelligence {
    public static function backfill_next_missing( $lesson ) { return null; }
}
function require_test( $passed, $message ) {
    if ( ! $passed ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}
require_once dirname( __DIR__ ) . '/includes/class-tcd-academy.php';
$run = 'focazul-empty-run';
update_option( TCD_Academy::STATE, array(
    'run_id' => $run, 'lesson' => 1, 'phase' => 'snapshot', 'status' => 'running',
    'snapshot_id' => 0, 'snapshot_cursor' => 0, 'learning_cursor' => 0,
) );
$reflect = new ReflectionMethod( 'TCD_Academy', 'build_snapshot' );
$reflect->setAccessible( true );
require_test( ! TCD_Academy::has_published_catalog_products(), 'This fixture must have zero products.' );
$reflect->invoke( null, TCD_Academy::state() );
$one = TCD_Academy::state();
require_test( 1 === $one['lesson'] && 'learning' === $one['phase'], 'L1 category snapshot must proceed without products.' );
require_test( 'ready' === $GLOBALS['wpdb']->snapshots[1]['status'], 'L1 category snapshot must be sealed for learning.' );
require_test( 1 === $GLOBALS['wpdb']->snapshots[1]['source_count'], 'A category is still a valid L1 source.' );

// Simulate L1 passing quality (the quality engine is covered independently).
TCD_Academy::save( array( 'lesson' => 2, 'phase' => 'snapshot', 'snapshot_id' => 0, 'snapshot_cursor' => 0 ) );
$reflect->invoke( null, TCD_Academy::state() );
$after = TCD_Academy::state();
require_test( 3 === $after['lesson'] && 'running' === $after['status'], 'Zero-product L2 must advance immediately to L3.' );
require_test( 'skipped_empty' === $GLOBALS['wpdb']->snapshots[2]['status'], 'L2 must be recorded as skipped, not passed.' );
require_test( 0 === $GLOBALS['wpdb']->snapshots[2]['source_count'], 'Empty L2 must not fabricate sources.' );
require_test( ! empty( $GLOBALS['wpdb']->snapshots[2]['completed_at'] ), 'Skipped L2 must remain auditable.' );
require_test( ! empty( $GLOBALS['tcd_scheduled'][ TCD_Academy::CRON_HOOK ] ), 'Next lesson must be queued.' );
$reflect->invoke( null, $after );
$after = TCD_Academy::state();
require_test( 4 === $after['lesson'], 'Zero Vocabulary L3 must also advance.' );
require_test( 'skipped_empty' === $GLOBALS['wpdb']->snapshots[3]['status'], 'L3 must be recorded as skipped.' );
require_test( 0 === $after['learned'] && 0 === $after['unlearned'], 'No fake learned/unlearned records.' );
require_test( count( array_filter( TCD_Academy::logs(), static function ( $row ) {
    return 'lesson_skipped_empty' === $row['event'];
} ) ) === 2, 'Both skipped lessons must have audit events.' );

// When WP-Cron and the legacy supervisor cannot advance, the authorized
// overview may do a few lock-protected chunks, not an unbounded training job.
delete_option( TCD_Academy::LAST_TICK );
delete_option( TCD_Academy::ADMIN_FALLBACK );
$count = TCD_Academy::admin_fallback_tick();
require_test( $count >= 1 && $count <= 4, 'Admin fallback must be bounded.' );
require_test( TCD_Academy::state()['lesson'] > 4, 'Admin fallback must advance empty lessons.' );
require_test( 0 === TCD_Academy::admin_fallback_tick(), 'Fallback must not run again immediately.' );

// SQL failure is not an empty lesson: it is a real error and must not skip.
TCD_Academy::save( array( 'lesson' => 8, 'phase' => 'snapshot', 'snapshot_id' => 0, 'snapshot_cursor' => 0, 'status' => 'running' ) );
$GLOBALS['wpdb']->fail_next_count = true;
try {
    $reflect->invoke( null, TCD_Academy::state() );
    require_test( false, 'SQL errors must not become skipped_empty.' );
} catch ( RuntimeException $e ) {
    require_test( false !== strpos( $e->getMessage(), 'base de datos' ), 'The SQL error must remain visible.' );
}
require_test( 8 === TCD_Academy::state()['lesson'], 'SQL failure must not advance the lesson.' );
echo "PASS: L1 without products, L2/L3 skipped with audit, bounded admin fallback, SQL errors preserved.\n";
