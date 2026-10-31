<?php
/**
 * Deterministic standalone regression for the Core content inventory.
 *
 * Run: php .github/tests/taxocommerce-core/content-inventory-smoke.php
 */
if ( 'cli' !== PHP_SAPI ) {
    exit;
}
define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
$GLOBALS['tc_cache'] = array();
$GLOBALS['tc_calls'] = 0;
function absint( $x ) { return abs( (int) $x ); }
function post_type_exists( $name ) { return in_array( $name, array( 'product', 'post', 'page', 'attachment' ), true ); }
function taxonomy_exists( $name ) { return in_array( $name, array( 'product_cat', 'category' ), true ); }
function wp_count_posts( $name ) {
    $GLOBALS['tc_calls']++;
    if ( 'attachment' === $name ) { return (object) array( 'inherit' => 17, 'trash' => 2 ); }
    if ( 'product' === $name ) { return (object) array( 'publish' => 100, 'draft' => 4, 'pending' => 1, 'trash' => 3, 'auto-draft' => 13 ); }
    if ( 'post' === $name ) { return (object) array( 'publish' => 8, 'future' => 2 ); }
    return (object) array( 'publish' => 9, 'private' => 1 );
}
function wp_count_terms( $args ) {
    $GLOBALS['tc_calls']++;
    if ( 'product_cat' === $args['taxonomy'] ) { return $args['hide_empty'] ? 65 : 80; }
    return $args['hide_empty'] ? 5 : 8;
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error {}
function get_transient( $key ) { return $GLOBALS['tc_cache'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['tc_cache'][ $key ] = $value; return true; }
require ( dirname( __DIR__, 3 ) . '/taxocommerce-core' ) . '/includes/class-taxocommerce-core-content-inventory.php';
function verify( $condition, $label ) {
    if ( ! $condition ) { fwrite( STDERR, "FAILED: " . $label . "\n" ); exit( 1 ); }
}
$first = TaxoCommerce_Core_Content_Inventory::snapshot();
verify( 108 === $first['types']['products']['total'], 'Product total excludes auto-draft' );
verify( 100 === $first['types']['products']['publish'], 'Published products' );
verify( 10 === $first['types']['posts']['total'], 'Scheduled blog posts' );
verify( 10 === $first['types']['pages']['total'], 'Private pages' );
verify( 15 === $first['product_cats']['empty'], 'Empty WooCommerce categories' );
verify( 8 === $first['blog_cats']['total'], 'Native blog categories' );
verify( 19 === $first['media'], 'Media including trash' );
verify( null === $first['faqs'], 'No FAQ table query without module' );
$queries = $GLOBALS['tc_calls'];
$again = TaxoCommerce_Core_Content_Inventory::snapshot();
verify( $again === $first && $GLOBALS['tc_calls'] === $queries, 'Second request uses cached inventory' );
echo "Content inventory smoke OK\n";
