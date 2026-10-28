<?php
/** Standalone country-scoping regression. No WordPress installation or database. */
if ( 'cli' !== PHP_SAPI ) { exit; }
define( 'ABSPATH', __DIR__ . '/' );
class WP_Term { public $term_id; public $slug; public function __construct( $id, $slug ) { $this->term_id = $id; $this->slug = $slug; } }
class WP_Post { public $ID; public $post_name; public function __construct( $id, $slug ) { $this->ID = $id; $this->post_name = $slug; } }
function __( $value, $domain = '' ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
$GLOBALS['post_meta'] = array( 2 => array( '_seo_pais_origen' => 'FR' ), 4 => array( '_seo_locale' => 'de-DE' ) );
$GLOBALS['term_meta'] = array( 11 => array( '_seo_locale' => 'fr-FR' ) );
$GLOBALS['posts'] = array( 1 => new WP_Post( 1, 'automocion' ), 2 => new WP_Post( 2, 'atelier' ), 3 => new WP_Post( 3, 'vevor-us-drills' ), 4 => new WP_Post( 4, 'werkstatt' ) );
$GLOBALS['terms'] = array( 11 => new WP_Term( 11, 'levage' ), 12 => new WP_Term( 12, 'hydraulic-jacks' ), 13 => new WP_Term( 13, 'gatos-hidraulicos' ), 14 => new WP_Term( 14, 'vevor-us' ) );
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['post_meta'][ $id ][ $key ] ?? ''; }
function get_term_meta( $id, $key, $single = true ) { return $GLOBALS['term_meta'][ $id ][ $key ] ?? ''; }
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function get_term( $id, $taxonomy = '' ) { return $GLOBALS['terms'][ $id ] ?? null; }
function get_ancestors( $id, $taxonomy, $type = 'taxonomy' ) { return 12 === $id ? array( 14 ) : array(); }
require dirname( __DIR__ ) . '/includes/class-taxocommerce-core-country.php';
function check( $yes, $label ) { if ( ! $yes ) { fwrite( STDERR, 'FAIL: ' . $label . "\n" ); exit( 1 ); } }
check( TaxoCommerce_Core_Country::post( 1 ) === 'ES', 'Spanish legacy page' );
check( TaxoCommerce_Core_Country::post( 2 ) === 'FR', 'Explicit French page' );
check( TaxoCommerce_Core_Country::post( 3 ) === 'US', 'Legacy US slug fallback' );
check( TaxoCommerce_Core_Country::post( 4 ) === 'DE', 'German page locale' );
check( TaxoCommerce_Core_Country::term( 11 ) === 'FR', 'French term locale' );
check( TaxoCommerce_Core_Country::term( 12 ) === 'US', 'US category inherited from pilot root' );
check( TaxoCommerce_Core_Country::term( 13 ) === 'ES', 'Spanish legacy category' );
check( TaxoCommerce_Core_Country::relation( array( 'source_type'=>'hub_secondary','source_id'=>2,'target_type'=>'product_cat','target_id'=>11 ) ) === 'FR', 'Legacy French relation' );
check( TaxoCommerce_Core_Country::relation( array( 'source_type'=>'hub_secondary','source_id'=>1,'target_type'=>'product_cat','target_id'=>13 ) ) === 'ES', 'Legacy Spanish relation' );
check( TaxoCommerce_Core_Country::relation( array( 'source_type'=>'hub_secondary','source_id'=>1,'target_type'=>'product_cat','target_id'=>11 ) ) === '', 'Mixed-country relation excluded' );
check( TaxoCommerce_Core_Country::relation( array( 'source_type'=>'hub_secondary','source_id'=>2,'target_type'=>'product_cat','target_id'=>11,'country_code'=>'ES' ) ) === '', 'Contradictory explicit country excluded' );
check( ! TaxoCommerce_Core_Country::valid( 'GB' ), 'Unknown country rejected' );
echo "PASS: TaxoCommerce countries and cross-market relation checks.\n";
