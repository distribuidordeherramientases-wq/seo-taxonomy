<?php
/** Isolated read-only SEO relation schema regression test. */
define( 'ABSPATH', __DIR__ );
define( 'TAXOCOMMERCE_CORE_VERSION', '0.4.55' );
define( 'ARRAY_A', 'ARRAY_A' );

class FakeRelationsDB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $rows = array();
    public function prepare( $query, ...$args ) { return $query; }
    public function esc_like( $value ) { return $value; }
    public function get_var( $query ) { return 'wp_seo_relations'; }
    public function get_results( $query, $output ) { return $this->rows; }
}
$GLOBALS['wpdb'] = new FakeRelationsDB();
$GLOBALS['posts'] = array(
    11 => (object) array( 'ID' => 11, 'post_type' => 'post' ),
    12 => (object) array( 'ID' => 12, 'post_type' => 'page' ),
    13 => (object) array( 'ID' => 13, 'post_type' => 'page' ),
);
$GLOBALS['terms'] = array(
    21 => (object) array( 'term_id' => 21, 'taxonomy' => 'product_cat' ),
    22 => (object) array( 'term_id' => 22, 'taxonomy' => 'product_cat' ),
);
$GLOBALS['country_checks'] = 0;
$GLOBALS['writes'] = 0;
function absint( $value ) { return abs( (int) $value ); }
function is_wp_error( $value ) { return false; }
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? false; }
function get_term( $id, $taxonomy ) { return $GLOBALS['terms'][ $id ] ?? false; }
function current_time( $type ) { return '2026-11-03 14:50:00'; }
function wp_insert_post() { ++$GLOBALS['writes']; throw new RuntimeException( 'Autocheck must be read-only' ); }
function wp_update_post() { ++$GLOBALS['writes']; throw new RuntimeException( 'Autocheck must be read-only' ); }
class TaxoCommerce_Core_Hierarchy_Classic {
    public static function definitions() {
        return array(
            'cluster_to_primary' => array( 'source_type' => 'cluster', 'target_type' => 'hub_primary' ),
            'hub_secondary_to_category' => array( 'source_type' => 'hub_secondary', 'target_type' => 'product_cat' ),
            'hub_secondary_to_landing' => array( 'source_type' => 'hub_secondary', 'target_type' => 'landing_page' ),
            'hub_secondary_to_post' => array( 'source_type' => 'hub_secondary', 'target_type' => 'post' ),
        );
    }
}
class TaxoCommerce_Core_Country {
    public static function relation( $row ) {
        ++$GLOBALS['country_checks'];
        return ! empty( $row['cross_market'] ) ? '' : 'ES';
    }
}
require dirname( __DIR__, 3 ) . '/taxocommerce-core/includes/class-taxocommerce-core-auto-test.php';
function row( $id, $relation, $source_type, $source_id, $target_type, $target_id, $cross = false ) {
    return array(
        'id' => $id, 'relation_type' => $relation,
        'source_type' => $source_type, 'source_id' => $source_id,
        'target_type' => $target_type, 'target_id' => $target_id,
        'cross_market' => $cross,
    );
}
function expect( $expected, $label ) {
    $result = TaxoCommerce_Core_Auto_Test::run_suite( array(
        'edges' => array( 'SEO Relations', 'Comprueba extremos' ),
    ) );
    $actual = $result['rows'][0];
    if ( $actual['status'] !== $expected ) {
        throw new RuntimeException( $label . ': expected ' . $expected . ', got ' .
            $actual['status'] . ' (' . $actual['message'] . ')' );
    }
}
$GLOBALS['wpdb']->rows = array(
    row( 41672, 'post_to_category', 'post', 11, 'product_cat', 21 ),
    row( 41673, 'landing_to_category', 'landing', 12, 'product_cat', 22 ),
    row( 41674, 'hub_secondary_to_category', 'hub_secondary', 13, 'product_cat', 21 ),
    row( 41675, 'hub_secondary_to_landing', 'hub_secondary', 13, 'landing_page', 12 ),
    row( 41676, 'hub_secondary_to_post', 'hub_secondary', 13, 'post', 11 ),
    row( 41677, 'category_translation', 'product_cat', 21, 'product_cat', 22, true ),
);
expect( 'pass', 'Valid editorial, structural and cross-country translation relations' );
if ( $GLOBALS['country_checks'] !== 5 ) {
    throw new RuntimeException( 'Cross-country translation wrongly checked against same-market policy' );
}
$GLOBALS['wpdb']->rows = array( row( 1, 'post_to_category', 'post', 12, 'product_cat', 21 ) );
expect( 'fail', 'Wrong post_type detected for editorial source' );
$GLOBALS['wpdb']->rows = array( row( 2, 'post_to_category', 'post', 11, 'page', 12 ) );
expect( 'fail', 'Wrong editorial target_type detected' );
$GLOBALS['wpdb']->rows = array( row( 3, 'landing_to_category', 'landing', 12, 'product_cat', 999 ) );
expect( 'fail', 'Missing product category detected' );
$GLOBALS['wpdb']->rows = array( row( 4, 'post_to_category', 'post', 11, 'product_cat', 21, true ) );
expect( 'fail', 'Cross-market editorial relation detected' );
$GLOBALS['wpdb']->rows = array( row( 5, 'some_external_relation', 'post', 11, 'product_cat', 21 ) );
expect( 'skip', 'Unknown type must be inconclusive, not a false failure/pass' );
$GLOBALS['wpdb']->rows = array(
    row( 6, 'some_external_relation', 'post', 11, 'product_cat', 21 ),
    row( 7, 'post_to_category', 'post', 11, 'product_cat', 21 ),
);
expect( 'skip', 'Mixed unknown and valid results remain inconclusive' );
$GLOBALS['wpdb']->rows = array();
expect( 'skip', 'Empty relation table is not a failure' );
if ( $GLOBALS['writes'] !== 0 ) {
    throw new RuntimeException( 'Autocheck mutated WordPress objects' );
}
echo "PASS: editorial and structural Autocheck relation checks.\n";
