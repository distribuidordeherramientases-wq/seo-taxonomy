<?php
/**
 * Runtime M11 cursor recovery and 33k-row indexed interpreter regression.
 * Isolated WordPress API stubs; never modifies a shop.
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['tcd_m11_options'] = array();

function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return (string) $value; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, (array) $args ); }
function get_option( $key, $default = false ) { return $GLOBALS['tcd_m11_options'][$key] ?? $default; }
function update_option( $key, $value, $autoload = false ) {
    $GLOBALS['tcd_m11_options'][$key] = $value;
    return true;
}
function esc_sql( $value ) { return $value; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function m11_assert( $ok, $name ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL: ' . $name . PHP_EOL );
        exit( 1 );
    }
}

final class M11_Lexicon_Test_DB {
    public $prefix = 'wp_';
    public $data = array();
    public $reads = 0;
    public function prepare( $sql, ...$args ) { return $sql; }
    public function esc_like( $value ) { return $value; }
    public function get_var( $sql ) { return $this->prefix . 'seo_interprete_lexicon'; }
    public function get_results( $sql, $mode = null ) {
        ++$this->reads;
        return $this->data;
    }
}
$GLOBALS['wpdb'] = new M11_Lexicon_Test_DB();
define( 'ARRAY_A', 'ARRAY_A' );
require dirname( __DIR__ ) . '/includes/interpreter/class-tcd-interpreter-lexicon.php';
require dirname( __DIR__ ) . '/includes/interpreter/class-tcd-linguist.php';

$academy = array( 'status' => 'completed', 'lesson' => 11, 'snapshot_id' => 99 );
$GLOBALS['tcd_m11_options']['tcd_academy_state'] = $academy;
for ( $id = 1; $id <= 33409; ++$id ) {
    $GLOBALS['wpdb']->data[] = array(
        'id' => $id,
        'normalized_expression' => 'expresion' . $id,
        'canonical_term' => 'concepto' . $id,
        'target_search' => 'herramienta' . $id,
        'relation_type' => 'verb_to_tool',
        'priority' => 5,
        'confidence' => 0.9,
        'context_terms' => '[]',
        'context_required' => 0,
        'active' => 1,
    );
}
$match = TCD_Original_Interprete_DB::matching_rows( 'necesito expresion348' );
m11_assert( 1 === count( $match ) && 348 === (int) $match[0]['id'], 'Indexed 33,409 expression match' );
$match = TCD_Original_Interprete_DB::action_rows_for_concept( 'herramienta348' );
m11_assert( 1 === count( $match ) && 348 === (int) $match[0]['id'], 'Indexed action target' );
m11_assert( 1 === $GLOBALS['wpdb']->reads, 'Single lexicon fetch for both indexes' );

$begin = new ReflectionMethod( 'TCD_Original_Linguista', 'exam_begin_case' );
$commit = new ReflectionMethod( 'TCD_Original_Linguista', 'exam_commit_case' );
m11_assert( true === $begin->invoke( null, 'active_regression', 348 ), 'First attempt starts' );
m11_assert( 348 === TCD_Original_Linguista::state()['exam_inflight_id'], 'In-flight ID persisted before processing' );
m11_assert( true === $begin->invoke( null, 'active_regression', 348 ), 'Interrupted first attempt retries once' );
m11_assert( false === $begin->invoke( null, 'active_regression', 348 ), 'Repeated hard interruption quarantined' );
$commit->invoke( null, 'active_regression', 348, array( 'fail'=>1,'invalid'=>1,'quarantined'=>1 ), 0.05 );
$state = TCD_Original_Linguista::state();
m11_assert( 348 === $state['cursor'] && 1 === $state['exam_quarantined'], 'Quarantine advances cursor without passing exam' );
m11_assert( 1 === $state['lesson_rejected'] && 1 === $state['exam_fail'], 'Quarantined case correctly rejected' );
m11_assert( 0 === $state['exam_inflight_id'], 'Completed record clears in-flight marker' );
m11_assert( true === $begin->invoke( null, 'active_regression', 349 ), 'Next lexicon case proceeds normally' );
$commit->invoke( null, 'active_regression', 349, array( 'pass'=>1,'exact'=>1 ), 0.02 );
$state = TCD_Original_Linguista::state();
m11_assert( 349 === $state['cursor'] && 2 === $state['lesson_processed'], 'Per-case cursor and counters persisted' );
m11_assert( 1 === $state['exam_pass'] && 1 === $state['exam_fail'], 'Regressions remain accurately classified' );
m11_assert( $academy === get_option( 'tcd_academy_state' ), 'L1-L10 progress untouched' );
echo "M11 runtime checkpoint and 33,409-row indexed lexicon: PASS" . PHP_EOL;
