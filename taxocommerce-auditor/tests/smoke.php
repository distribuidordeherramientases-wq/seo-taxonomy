<?php
/**
 * Lightweight non-WordPress smoke tests for pure rules and simple native flows.
 * Full integration tests still require WordPress + WooCommerce in STAGING.
 */

define( 'ABSPATH', __DIR__ );
function remove_accents( $text ) { return $text; }
function wp_strip_all_tags( $text ) { return strip_tags( $text ); }
function strip_shortcodes( $text ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function taxonomy_exists( $name ) { return 'tc_semantic_tag' === $name; }
function get_term_meta( $id, $key, $single = true ) { return $single ? array() : array(); }
function get_post_meta( $id, $key, $single = true ) { return ''; }
function wp_get_object_terms( $id, $tax, $args = array() ) { return array(); }
function is_wp_error( $value ) { return false; }
function apply_filters( $hook, $value ) { return $value; }

class WP_Post {
    public $ID;
    public $post_title;
    public $post_name;
    public $post_excerpt;
    public $post_content;
    public function __construct( $id, $title, $slug, $excerpt = '', $content = '' ) {
        $this->ID = $id;
        $this->post_title = $title;
        $this->post_name = $slug;
        $this->post_excerpt = $excerpt;
        $this->post_content = $content;
    }
}

class WP_Term {
    public $term_id = 11;
    public $name = 'Taladros de bateria';
    public $description = '';
    public $slug = 'taladros-de-bateria';
    public $count = 8;
    public $parent = 0;
}

class Fake_WC_Product {
    public function get_short_description() { return ''; }
    public function get_description() { return 'Taladro profesional con bateria y velocidad regulable para trabajo en madera y acero.'; }
    public function get_category_ids() { return array( 11 ); }
    public function get_sku() { return 'SKU-TEST'; }
    public function get_image_id() { return 0; }
}
function wc_get_product( $id ) { return new Fake_WC_Product(); }

require dirname( __DIR__ ) . '/includes/class-rules.php';
require dirname( __DIR__ ) . '/includes/class-runner.php';

use TaxoCommerce\Auditor\Rules;
use TaxoCommerce\Auditor\Runner;

function verify( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}
verify( array_sum( Rules::DIMENSIONS ) === 100, 'Dimension weights must total 100.' );
verify( Rules::band( 85 ) === 'fuerte', '85 strong' );
verify( Rules::band( 70 ) === 'correcta', '70 correct' );
verify( Rules::band( 55 ) === 'mejorable', '55 improvable' );
verify( Rules::band( 54 ) === 'debil', '54 weak' );
verify( Rules::band( null ) === 'no_aplica', 'Null is not zero' );
verify( Rules::identity_key( '  Taladro  Con  Bateria ' ) === 'taladro con bateria', 'Normalized title' );
verify( Rules::title_alignment( 'Taladro profesional bateria', 'Taladro profesional con bateria de litio' ) > 0.5, 'Title-description alignment' );
verify( Rules::title_alignment( 'X', 'X' ) === null, 'Too short title not assessed' );

$p = new WP_Post( 4, 'Taladro profesional bateria', 'taladro-profesional-bateria' );
$result = Rules::inspect( 'products', $p );
$codes = array_column( $result['findings'], 'code' );
verify( in_array( 'product_missing_excerpt', $codes, true ), 'Missing product excerpt detected' );
verify( ! in_array( 'product_without_category', $codes, true ), 'Existing category not incorrectly flagged' );
verify( isset( $result['quality']['score'] ), 'Internal quality available' );

$c = Rules::inspect( 'categories', new WP_Term() );
verify( in_array( 'category_missing_description', array_column( $c['findings'], 'code' ), true ), 'Missing category description detected' );

$corporate = Rules::inspect( 'pages', new WP_Post( 9, 'Contacto', 'contacto' ) );
verify( ! empty( $corporate['excluded'] ), 'Corporate page excluded' );

$faq = Rules::inspect( 'faqs', new WP_Post( 21, 'Como se utiliza', 'como-se-utiliza' ) );
verify( in_array( 'faq_thin_answer', array_column( $faq['findings'], 'code' ), true ), 'Empty FAQ answer detected' );
verify( ! in_array( 'faq_orphan_owner', array_column( $faq['findings'], 'code' ), true ), 'Missing owner schema never treated as orphan' );

verify( Runner::valid_scope( 'products' ), 'Valid scope' );
verify( ! Runner::valid_scope( 'invalid' ), 'Invalid scope rejected' );

echo "TaxoCommerce Auditor smoke tests passed.\n";
