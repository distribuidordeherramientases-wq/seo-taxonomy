<?php
/**
 * Standalone regression: per-lesson quality settings and explicit acceptance.
 * This file uses in-memory WordPress stubs; it never writes to a live site.
 */
define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );

$options = array();
function get_option( $key, $default = false ) {
    global $options;
    return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
    global $options;
    $options[ $key ] = $value;
    return true;
}
function absint( $value ) { return abs( (int) $value ); }
function get_current_user_id() { return 42; }
function current_time( $type ) { return '2026-10-30 08:00:00'; }
function wp_json_encode( $data, $options = 0 ) { return json_encode( $data, $options ); }
class WP_Error {
    private $message;
    public function __construct( $code, $message ) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }

class TCD_Knowledge_Transfer {
    public static function rules_table() { return 'wp_tcd_rules'; }
    public static function l9_table() { return 'wp_tcd_l9'; }
}
class QualityTestDB {
    public $prefix = 'wp_';
    public $quality_row = array();
    public $activated = 0;
    public $updates = 0;
    public function prepare( $sql ) { return $sql; }
    public function get_row( $sql, $format ) { return $this->quality_row; }
    public function query( $sql ) { $this->activated++; return 1; }
    public function update( $table, $data, $where ) {
        if ( 'wp_tcd_quality_checks' !== $table || $where['id'] !== $this->quality_row['id']
            || $where['snapshot_id'] !== $this->quality_row['snapshot_id']
            || $where['status'] !== $this->quality_row['status'] ) return 0;
        $this->quality_row = array_merge( $this->quality_row, $data );
        $this->updates++;
        return 1;
    }
}
$wpdb = new QualityTestDB();
require_once dirname( __DIR__ ) . '/includes/class-tcd-intelligence.php';

function expect_quality( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL quality threshold: {$message}\n" );
        exit( 1 );
    }
}

$defaults = TCD_Intelligence::default_quality_thresholds();
expect_quality( count( $defaults ) === 10 && $defaults[1] === 94 && $defaults[9] === 88, 'backward-compatible defaults' );
expect_quality( TCD_Intelligence::quality_thresholds() === $defaults, 'default settings on existing installations' );

$values = $defaults;
$values[1] = '0';
$values[2] = '100';
$values[9] = '70.5';
expect_quality( ! is_wp_error( TCD_Intelligence::set_quality_thresholds( $values ) ), 'valid min/max/fractional settings' );
$saved = TCD_Intelligence::quality_thresholds();
expect_quality( $saved[1] === 0.0 && $saved[2] === 100.0 && $saved[9] === 70.5, 'settings persisted by lesson' );

foreach ( array( '-1', '101', '70.55', 'abc', '1e2' ) as $invalid ) {
    $bad = $values;
    $bad[9] = $invalid;
    expect_quality( is_wp_error( TCD_Intelligence::set_quality_thresholds( $bad ) ), 'invalid percentage rejected: ' . $invalid );
}
$bad = $values;
unset( $bad[10] );
expect_quality( is_wp_error( TCD_Intelligence::set_quality_thresholds( $bad ) ), 'incomplete request rejected' );
expect_quality( TCD_Intelligence::quality_thresholds()[9] === 70.5, 'invalid requests never overwrite saved settings' );

$report = array(
    'evaluated'=>180,
    'pass_top1'=>77,
    'pass_top3'=>105,
    'pass_top8'=>131,
    'failed'=>49,
    'failures'=>array( array( 'source_id'=>5817, 'diagnosis'=>array( 'origin'=>'datos_fuente' ) ) ),
    'pass_any_ratio'=>131 / 180,
    'threshold'=>0.88,
    'passed'=>false,
);
$wpdb->quality_row = array(
    'id'=>600, 'lesson'=>9, 'snapshot_id'=>501, 'evaluated'=>180,
    'pass_top1'=>77, 'pass_top3'=>105, 'pass_top8'=>131,
    'failed'=>49, 'pass_any_ratio'=>131 / 180, 'threshold'=>0.88,
    'status'=>'failed', 'report_json'=>json_encode( $report ),
);
$before = $wpdb->quality_row;
$accepted = TCD_Intelligence::accept_failed_quality( 9, 501 );
expect_quality( ! is_wp_error( $accepted ), 'owner accepts a completed exam already meeting the new minimum' );
expect_quality( $wpdb->quality_row['status'] === 'passed' && $wpdb->quality_row['threshold'] === 0.705, 'new gate persisted' );
expect_quality( $wpdb->quality_row['pass_top8'] === 131 && $wpdb->quality_row['failed'] === 49, 'actual scores and failures unchanged' );
$after = json_decode( $wpdb->quality_row['report_json'], true );
expect_quality( $after['failures'] === $report['failures'] && $after['pass_top8'] === 131, 'audit failures unchanged' );
expect_quality( $after['owner_acceptance']['accepted_by'] === 42, 'actor is audited' );
expect_quality( $after['owner_acceptance']['previous_threshold'] === 0.88, 'old threshold is audited' );
expect_quality( $wpdb->activated === 2, 'learned stage promoted on explicit acceptance' );
expect_quality( is_wp_error( TCD_Intelligence::accept_failed_quality( 9, 501 ) ), 'previously accepted exam cannot be accepted twice' );

$wpdb->quality_row = $before;
$strict = $values;
$strict[9] = '75';
TCD_Intelligence::set_quality_thresholds( $strict );
$updates = $wpdb->updates;
expect_quality( is_wp_error( TCD_Intelligence::accept_failed_quality( 9, 501 ) ), 'below-threshold exam not accepted' );
expect_quality( $wpdb->updates === $updates && $wpdb->quality_row['status'] === 'failed', 'rejected acceptance does not modify results' );

$academy = file_get_contents( dirname( __DIR__ ) . '/includes/class-tcd-academy.php' );
$admin = file_get_contents( dirname( __DIR__ ) . '/includes/class-tcd-admin.php' );
expect_quality( strpos( $academy, "elseif('accept_quality'===\$action)" ) !== false, 'accept action wired to training state' );
expect_quality( strpos( $academy, 'TCD_Intelligence::set_quality_thresholds' ) !== false, 'threshold save action exists' );
expect_quality( strpos( $admin, 'name="thresholds[' ) !== false, 'lesson-specific settings fields visible' );
expect_quality( strpos( $admin, 'tcd_action" value="accept_quality' ) !== false, 'manual acceptance button available' );
echo "Dependiente quality threshold regression passed.\n";
