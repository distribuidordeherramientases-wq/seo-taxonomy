<?php
/**
 * Regression test: Core's read-only relation validator accepts editorial
 * associations while rejecting broken endpoints and reporting unknown types.
 */
define( 'ABSPATH', __DIR__ );
$GLOBALS['posts'] = array(
    11 => (object) array( 'ID' => 11, 'post_type' => 'post' ),
    12 => (object) array( 'ID' => 12, 'post_type' => 'page' ),
    13 => (object) array( 'ID' => 13, 'post_type' => 'page' ),
);
$GLOBALS['terms'] = array(
    24 => (object) array( 'term_id' => 24, 'taxonomy' => 'product_cat' ),
    25 => (object) array( 'term_id' => 25, 'taxonomy' => 'product_cat' ),
);
$GLOBALS['country_valid'] = true;
function absint( $value ) { return abs( (int) $value ); }
function __( $text, $domain = '' ) { return $text; }
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? false; }
function get_term( $id, $type = '' ) { return $GLOBALS['terms'][ $id ] ?? false; }
function is_wp_error( $value ) { return false; }
class TaxoCommerce_Core_Hierarchy {
    const PARENT_CLUSTER_META = '_parent_cluster';
    const PARENT_PRIMARY_META = '_parent_primary';
    const PARENT_SECONDARY_TERM_META = '_parent_secondary';
}
class TaxoCommerce_Core_Hierarchy_Classic {
    public static function definitions() {
        return array(
            'cluster_to_primary' => array( 'source_type' => 'cluster', 'target_type' => 'hub_primary' ),
            'hub_secondary_to_category' => array( 'source_type' => 'hub_secondary', 'target_type' => 'product_cat' ),
        );
    }
}
class TaxoCommerce_Core_Country {
    public static function relation( $row ) { return $GLOBALS['country_valid'] ? 'ES' : ''; }
}
require dirname( __DIR__, 3 ) . '/taxocommerce-core/includes/class-taxocommerce-core-auto-test.php';

function verify_relation( $id, $source_type, $source, $target_type, $target, $kind, $status, $label ) {
    $row = array( 'id' => $id, 'source_type' => $source_type, 'source_id' => $source,
        'target_type' => $target_type, 'target_id' => $target, 'relation_type' => $kind );
    $result = TaxoCommerce_Core_Auto_Test::inspect_relation( $row );
    if ( $status !== $result['status'] ) {
        throw new RuntimeException( $label . ': expected ' . $status . ', got ' . $result['status'] . ': ' . $result['message'] );
    }
}
verify_relation( 41672, 'post', 11, 'product_cat', 24, 'post_to_category', 'pass', 'Real STAGING pattern' );
verify_relation( 3, 'landing', 12, 'product_cat', 24, 'landing_to_category', 'pass', 'Landing to category' );
verify_relation( 4, 'cluster', 12, 'hub_primary', 13, 'cluster_to_primary', 'pass', 'Structural page to page' );
verify_relation( 5, 'hub_secondary', 13, 'product_cat', 24, 'hub_secondary_to_category', 'pass', 'Structural page to term' );
verify_relation( 6, 'product_cat', 24, 'product_cat', 25, 'category_translation', 'pass', 'Translation endpoints' );
verify_relation( 7, 'post', 12, 'product_cat', 24, 'post_to_category', 'fail', 'Wrong actual source post type' );
verify_relation( 8, 'page', 11, 'product_cat', 24, 'post_to_category', 'fail', 'Wrong declared type' );
verify_relation( 9, 'post', 11, 'product_cat', 404, 'post_to_category', 'fail', 'Missing term' );
verify_relation( 10, 'post', 404, 'product_cat', 24, 'post_to_category', 'fail', 'Missing post' );
verify_relation( 11, 'post', 11, 'product_cat', 24, 'unknown_plugin_relation', 'skip', 'Unknown relation never falsely passes' );
$GLOBALS['country_valid'] = false;
verify_relation( 12, 'post', 11, 'product_cat', 24, 'post_to_category', 'fail', 'Editorial country mismatch' );
verify_relation( 13, 'product_cat', 24, 'product_cat', 25, 'category_translation', 'pass', 'Cross-market translation valid' );
echo "Core relation Autocheck regression: 12 checks passed.\n";
