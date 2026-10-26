<?php
/**
 * Regression: L6 accepts legacy and modern FAQ schemas, and fails explicitly.
 * Run: php taxocommerce-dependiente/tests/faq-source-smoke.php
 */
define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
class WP_Error {
    private $code;
    private $message;
    public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_json_encode( $value, $options = 0 ) { return json_encode( $value, $options ); }
function number_format_i18n( $value ) { return number_format( (int) $value ); }
if ( ! function_exists( 'mb_substr' ) ) {
    function mb_substr( $value, $start, $length = null ) { return substr( $value, $start, $length ); }
}
class TCD_Faq_Test_DB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $schema = array();
    public $rows = array();
    public $exists = true;
    public $read_error = false;
    public function prepare( $sql, ...$values ) {
        foreach ( $values as $value ) {
            if ( ! preg_match( '/%[ids]/', $sql, $match ) ) throw new RuntimeException( 'Unexpected placeholder count' );
            if ( '%i' === $match[0] ) $replacement = chr(96) . (string) $value . chr(96);
            elseif ( '%d' === $match[0] ) $replacement = (string) (int) $value;
            else $replacement = "'" . addslashes( (string) $value ) . "'";
            $sql = preg_replace( '/%[ids]/', $replacement, $sql, 1 );
        }
        return $sql;
    }
    public function get_var( $sql ) {
        $this->last_error = '';
        if ( false !== strpos( $sql, 'SHOW TABLES LIKE' ) ) {
            return $this->exists && false !== strpos( $sql, 'wp_seo_faq' ) ? 'wp_seo_faq' : null;
        }
        if ( false !== strpos( $sql, 'COUNT(*)' ) ) {
            $rows = array_filter( $this->rows, static function ( $row ) { return 1 === (int) ( $row['active'] ?? 0 ); } );
            return count( $rows );
        }
        return null;
    }
    public function get_col( $sql ) {
        $this->last_error = '';
        return false !== strpos( $sql, 'SHOW COLUMNS FROM' ) ? $this->schema : array();
    }
    public function get_row( $sql, $output = null ) {
        $this->last_error = '';
        if ( false !== strpos( $sql, 'COUNT(*) AS total' ) ) {
            $active = 0;
            foreach ( $this->rows as $row ) if ( 1 === (int) ( $row['active'] ?? 0 ) ) $active++;
            return array( 'total' => count( $this->rows ), 'active_count' => $active );
        }
        if ( preg_match( '/WHERE id=([0-9]+) AND active=1/', $sql, $matched ) ) {
            foreach ( $this->rows as $row ) {
                if ( (int) $row['id'] === (int) $matched[1] && 1 === (int) $row['active'] ) return $row;
            }
        }
        return null;
    }
    public function get_results( $sql, $output = null ) {
        $this->last_error = '';
        if ( $this->read_error ) {
            $this->last_error = 'Simulated FAQ read error';
            return null;
        }
        if ( false === strpos( $sql, 'SELECT * FROM' ) || false === strpos( $sql, 'WHERE active=1' ) ) {
            throw new RuntimeException( 'Unexpected FAQ query: ' . $sql );
        }
        if ( ! preg_match( '/LIMIT ([0-9]+) OFFSET ([0-9]+)/', $sql, $match ) ) {
            throw new RuntimeException( 'Missing pagination' );
        }
        $rows = array_values( array_filter( $this->rows, static function ( $row ) {
            return 1 === (int) ( $row['active'] ?? 0 );
        } ) );
        usort( $rows, static function ( $left, $right ) { return (int) $left['id'] <=> (int) $right['id']; } );
        return array_slice( $rows, (int) $match[2], (int) $match[1] );
    }
}
$GLOBALS['wpdb'] = new TCD_Faq_Test_DB();
require dirname( __DIR__ ) . '/includes/class-tcd-sources.php';
function faq_expect( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, 'FAIL FAQ L6: ' . $message . PHP_EOL ); exit( 1 ); }
}
$db = $GLOBALS['wpdb'];
$db->schema = array( 'id', 'object_type', 'object_id', 'question', 'answer', 'active', 'unexpected_extra' );
$db->rows = array(
    array( 'id'=>11, 'object_type'=>2, 'object_id'=>91, 'question'=>'¿Cuándo se usa?', 'answer'=>'Para este uso.', 'active'=>1, 'unexpected_extra'=>'ok' ),
    array( 'id'=>12, 'object_type'=>3, 'object_id'=>321, 'question'=>'¿Compatible?', 'answer'=>'Sí, con modelo 321.', 'active'=>1 ),
    array( 'id'=>13, 'object_type'=>2, 'object_id'=>91, 'question'=>'Inactiva', 'answer'=>'No publicar.', 'active'=>0 ),
);
$diag = TCD_Sources::faq_diagnostics();
faq_expect( 'ready' === $diag['status'] && 3 === $diag['total'] && 2 === $diag['active'], 'Legacy counts' );
faq_expect( false === $diag['has_product_cat_id'], 'Legacy column optional' );
faq_expect( 2 === TCD_Sources::source_total( 6 ), 'L6 source count' );
$batch = TCD_Sources::batch( 6, 0, 1 );
faq_expect( ! is_wp_error( $batch ) && 1 === count( $batch['items'] ) && ! empty( $batch['more'] ), 'Legacy pagination' );
faq_expect( 91 === $batch['items'][0]['facts']['owner_id'] && 0 === $batch['items'][0]['facts']['product_cat_id'], 'Legacy owner fallback' );
$single = TCD_Sources::faq_item( 12 );
faq_expect( ! empty( $single ) && 'product' === $single['facts']['owner_type'] && 321 === $single['facts']['owner_id'], 'Legacy single FAQ' );

$db->schema = array( 'id', 'object_type', 'object_id', 'question', 'answer', 'active', 'sort_order', 'product_cat_id' );
$db->rows = array( array( 'id'=>21, 'object_type'=>2, 'object_id'=>91, 'question'=>'¿Dónde?', 'answer'=>'En categoría nueva.', 'active'=>1, 'sort_order'=>2, 'product_cat_id'=>777 ) );
$diag = TCD_Sources::faq_diagnostics();
$batch = TCD_Sources::batch( 6, 0, 20 );
faq_expect( 'ready' === $diag['status'] && true === $diag['has_product_cat_id'], 'Modern schema' );
faq_expect( 777 === $batch['items'][0]['facts']['owner_id'] && 2 === $batch['items'][0]['facts']['sort_order'], 'Modern owner' );

$db->rows = array();
$diag = TCD_Sources::faq_diagnostics();
$batch = TCD_Sources::batch( 6, 0, 20 );
faq_expect( 'empty_table' === $diag['status'], 'Empty table diagnosis' );
faq_expect( is_wp_error( $batch ) && 'tcd_faq_source_empty' === $batch->get_error_code(), 'Empty table explicit error' );

$db->rows = array( array( 'id'=>22, 'object_type'=>2, 'object_id'=>91, 'question'=>'Inactiva', 'answer'=>'Texto', 'active'=>0 ) );
$diag = TCD_Sources::faq_diagnostics();
faq_expect( 'no_active_rows' === $diag['status'], 'Inactive-only diagnosis' );

$db->rows = array( array( 'id'=>23, 'object_type'=>2, 'object_id'=>91, 'question'=>'', 'answer'=>'Texto', 'active'=>1 ) );
$batch = TCD_Sources::batch( 6, 0, 20 );
faq_expect( is_wp_error( $batch ) && 'tcd_faq_source_unusable' === $batch->get_error_code(), 'Unusable questions diagnosis' );

$db->rows = array( array( 'id'=>24, 'object_type'=>2, 'object_id'=>91, 'question'=>'Válida', 'answer'=>'Texto', 'active'=>1 ) );
$db->read_error = true;
$batch = TCD_Sources::batch( 6, 0, 20 );
faq_expect( is_wp_error( $batch ) && 'tcd_faq_source_query' === $batch->get_error_code(), 'SQL error diagnosis' );
$db->read_error = false;

$db->schema = array( 'id', 'object_type', 'object_id', 'question', 'active' );
$diag = TCD_Sources::faq_diagnostics();
faq_expect( 'schema_incompatible' === $diag['status'] && in_array( 'answer', $diag['missing_columns'], true ), 'Required column diagnosis' );

$db->exists = false;
$diag = TCD_Sources::faq_diagnostics();
$batch = TCD_Sources::batch( 6, 0, 20 );
faq_expect( 'missing_table' === $diag['status'], 'Missing table diagnosis' );
faq_expect( is_wp_error( $batch ) && 'tcd_faq_plugin_missing' === $batch->get_error_code(), 'Missing table explicit error' );
echo "FAQ L6 schema and data regression: OK\n";
