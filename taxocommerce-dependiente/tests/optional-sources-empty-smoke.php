<?php
/** Tests missing optional source tables separately from real query failures. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
class WP_Error {
    private $code;
    public function __construct( $code, $message ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function absint( $v ) { return max( 0, (int) $v ); }
function sanitize_text_field( $v ) { return (string) $v; }
function number_format_i18n( $v ) { return number_format( $v ); }
class TestOptionalDB {
    public $prefix = 'test_';
    public $last_error = '';
    public $tables = array();
    public $fail_table = '';
    public $fail_results = false;
    public $faq_active = 0;
    public function prepare( $sql, ...$args ) {
        if ( 0 === strpos( $sql, 'SHOW TABLES LIKE' ) ) return (string) $args[0];
        return $sql;
    }
    public function get_var( $table ) {
        if ( $this->fail_table === $table ) {
            $this->last_error = 'Simulated schema error';
            return null;
        }
        $this->last_error = '';
        return in_array( $table, $this->tables, true ) ? $table : null;
    }
    public function get_results( $query, $mode = null ) {
        $this->last_error = $this->fail_results ? 'Simulated query error' : '';
        return $this->fail_results ? false : array();
    }
    public function get_col( $query ) {
        $this->last_error = '';
        return array( 'id','object_type','object_id','question','answer','active' );
    }
    public function get_row( $query, $mode = null ) {
        $this->last_error = '';
        return array( 'total' => $this->faq_active, 'active_count' => $this->faq_active );
    }
}
$GLOBALS['wpdb'] = new TestOptionalDB();
require_once dirname( __DIR__ ) . '/includes/class-tcd-sources.php';
function assert_optional( $ok, $message ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}
function expect_empty_batch( $lesson, $message ) {
    $data = TCD_Sources::batch( $lesson, 0, 5 );
    assert_optional( is_array( $data ) && empty( $data['items'] ) && empty( $data['more'] ), $message );
}
expect_empty_batch( 3, 'Missing Vocabulary source tables must skip L3.' );
expect_empty_batch( 6, 'Missing FAQ table must skip L6.' );
expect_empty_batch( 7, 'Missing cross-reference tables must skip L7.' );
$GLOBALS['wpdb']->tables = array( 'test_seo_vocabulary' );
expect_empty_batch( 3, 'Vocabulary without object mapping and no terms must be empty.' );
expect_empty_batch( 7, 'Cross-reference learning requires actual source relationships.' );
$GLOBALS['wpdb']->tables = array( 'test_seo_faq' );
expect_empty_batch( 6, 'An existing but empty FAQ table must skip L6.' );
$GLOBALS['wpdb']->fail_table = 'test_seo_faq';
assert_optional( is_wp_error( TCD_Sources::batch( 6, 0, 5 ) ), 'SQL table inspection failure must stay an error.' );
$GLOBALS['wpdb']->fail_table = '';
$GLOBALS['wpdb']->fail_results = true;
assert_optional( is_wp_error( TCD_Sources::batch( 6, 0, 5 ) ), 'SQL FAQ read failure must stay an error.' );
$GLOBALS['wpdb']->tables = array( 'test_seo_vocabulary', 'test_seo_object_vocabulary' );
assert_optional( is_wp_error( TCD_Sources::batch( 3, 0, 5 ) ), 'SQL Vocabulary read failure must stay an error.' );
assert_optional( is_wp_error( TCD_Sources::batch( 7, 0, 5 ) ), 'SQL cross-reference failure must stay an error.' );
$GLOBALS['wpdb']->fail_results = false;
$GLOBALS['wpdb']->tables = array( 'test_seo_faq' );
$GLOBALS['wpdb']->faq_active = 1;
assert_optional( is_wp_error( TCD_Sources::batch( 6, 0, 5 ) ), 'An active FAQ missing from the batch is an inconsistency, not an empty lesson.' );
echo "PASS: missing optional sources skipped, SQL errors and inconsistent FAQs preserved.\n";
