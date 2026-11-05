<?php
/**
 * First install: learn only from local published WooCommerce products.
 *
 * Run: php taxocommerce-dependiente/tests/first-install-catalog-smoke.php
 * WordPress is stubbed: no product, knowledge or site database is changed.
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['tcd_opts'] = array();
$GLOBALS['tcd_products'] = array();
$GLOBALS['tcd_has_product_type'] = true;
$GLOBALS['tcd_has_knowledge'] = false;
$GLOBALS['tcd_sql_error'] = '';
$GLOBALS['tcd_query_count'] = 0;
$GLOBALS['tcd_scheduled'] = array();
$GLOBALS['tcd_product_query'] = array();

function get_option( $name, $default = false ) {
    return array_key_exists( $name, $GLOBALS['tcd_opts'] ) ? $GLOBALS['tcd_opts'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
    $GLOBALS['tcd_opts'][ $name ] = $value;
    return true;
}
function delete_option( $name ) {
    unset( $GLOBALS['tcd_opts'][ $name ] );
    return true;
}
function wp_parse_args( $args, $defaults = array() ) {
    return array_merge( (array) $defaults, (array) $args );
}
function current_time( $format ) { return '2026-11-05 12:00:00'; }
function post_type_exists( $name ) { return 'product' === $name && $GLOBALS['tcd_has_product_type']; }
function get_posts( $args ) {
    $GLOBALS['tcd_product_query'] = $args;
    return $GLOBALS['tcd_products'];
}
function wp_generate_uuid4() { return 'test-first-install-uuid'; }
function absint( $value ) { return max( 0, (int) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function wp_json_encode( $data ) { return json_encode( $data ); }
function wp_next_scheduled( $hook ) {
    return isset( $GLOBALS['tcd_scheduled'][ $hook ] ) ? $GLOBALS['tcd_scheduled'][ $hook ] : false;
}
function wp_schedule_single_event( $when, $hook ) {
    $GLOBALS['tcd_scheduled'][ $hook ] = $when;
    return true;
}
function wp_clear_scheduled_hook( $hook ) {
    unset( $GLOBALS['tcd_scheduled'][ $hook ] );
}
final class TCD_Original_Interprete_DB {
    public static function table() { return 'local_seo_interprete_lexicon'; }
}
class TCD_First_Install_DB {
    public $prefix = 'local_';
    public $last_error = '';
    public function prepare( $sql, $table ) { return $sql . ' ' . $table; }
    public function get_var( $sql ) {
        ++$GLOBALS['tcd_query_count'];
        $this->last_error = $GLOBALS['tcd_sql_error'];
        return $GLOBALS['tcd_has_knowledge'] ? '1' : null;
    }
}
$GLOBALS['wpdb'] = new TCD_First_Install_DB();
function assert_tcd_install( $ok, $message ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL: ' . $message . "\n" );
        exit( 1 );
    }
}
require_once dirname( __DIR__ ) . '/includes/class-tcd-academy.php';

// No product => no scheduled background tasks and no new knowledge.
assert_tcd_install( false === TCD_Academy::start_first_install_catalog(), 'Empty catalogue must stay idle' );
assert_tcd_install( array() === $GLOBALS['tcd_scheduled'], 'Empty catalogue scheduled a job' );
assert_tcd_install( ! isset( $GLOBALS['tcd_opts'][ TCD_Academy::STATE ] ), 'Empty catalogue modified learning state' );

// One published local product => queue the existing bounded Academy worker.
$GLOBALS['tcd_products'] = array( 123 );
assert_tcd_install( true === TCD_Academy::start_first_install_catalog(), 'First local product did not start' );
assert_tcd_install( 1 === $GLOBALS['tcd_product_query']['posts_per_page'], 'Product discovery must be bounded' );
assert_tcd_install( 'publish' === $GLOBALS['tcd_product_query']['post_status'], 'Must only inspect published products' );
assert_tcd_install( 'ids' === $GLOBALS['tcd_product_query']['fields'], 'Should not load product content during activation' );
assert_tcd_install( 'running' === TCD_Academy::state()['status'], 'Learning must be scheduled in running state' );
assert_tcd_install( 'snapshot' === TCD_Academy::state()['phase'], 'Learning must start with a source snapshot' );
assert_tcd_install( ! empty( $GLOBALS['tcd_scheduled'][ TCD_Academy::CRON_HOOK ] ), 'Academy cron must be scheduled' );
assert_tcd_install( 4 === $GLOBALS['tcd_query_count'], 'Must check all existing knowledge/snapshot/lexicon tables' );
assert_tcd_install( false === TCD_Academy::start_first_install_catalog(), 'A second start must be idempotent' );

// Existing knowledge or historical state must never start or be reset.
$GLOBALS['tcd_opts'][ TCD_Academy::STATE ] = array( 'status' => 'idle', 'run_id' => '' );
$GLOBALS['tcd_scheduled'] = array();
$GLOBALS['tcd_has_knowledge'] = true;
assert_tcd_install( false === TCD_Academy::start_first_install_catalog(), 'Existing knowledge must prevent auto-start' );
assert_tcd_install( array() === $GLOBALS['tcd_scheduled'], 'Existing knowledge scheduled a task' );
$GLOBALS['tcd_has_knowledge'] = false;
$GLOBALS['tcd_sql_error'] = 'Table unreadable';
assert_tcd_install( false === TCD_Academy::start_first_install_catalog(), 'SQL errors must prevent auto-start' );
$GLOBALS['tcd_sql_error'] = '';
$GLOBALS['tcd_opts'][ TCD_Academy::STATE ] = array( 'status' => 'stopped', 'run_id' => 'previous-run' );
assert_tcd_install( false === TCD_Academy::start_first_install_catalog(), 'Prior stopped run must not restart' );
$GLOBALS['tcd_has_product_type'] = false;
assert_tcd_install( false === TCD_Academy::start_first_install_catalog(), 'Missing WooCommerce product type must remain idle' );

// Only a genuinely new activation can invoke this method.
$main = file_get_contents( dirname( __DIR__ ) . '/taxocommerce-dependiente.php' );
assert_tcd_install( false !== strpos( $main, '$first_install = false === get_option( TCD_Academy::STATE, false );' ), 'First-activation guard absent' );
assert_tcd_install( false !== strpos( $main, 'if ( $first_install ) {' ), 'Training must be guarded' );
assert_tcd_install( false !== strpos( $main, 'TCD_Academy::start_first_install_catalog();' ), 'Initial product learning not wired' );

echo "PASS: local-first bounded training scheduled only on genuine first activation with products.\n";
