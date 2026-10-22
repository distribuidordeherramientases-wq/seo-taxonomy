<?php
/**
 * Dependency-free smoke test of category-scoped Media suggestions.
 *
 * Run: php .github/tests/taxocommerce-images-assignment-smoke.php
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Term {
	public $term_id;
	public $name;
	public function __construct( int $id, string $name ) {
		$this->term_id = $id;
		$this->name = $name;
	}
}

class TCI_Test_WPDB {
	public $prefix = 'wp_';
	public function prepare( $query, ...$args ) {
		return $query;
	}
	public function get_var( $query ) {
		return null; // The legacy SEO plugin is deliberately unavailable.
	}
	public function esc_like( $value ) {
		return $value;
	}
}

$GLOBALS['wpdb'] = new TCI_Test_WPDB();

function __( $text, $domain = '' ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function is_wp_error( $value ) { return false; }
function taxonomy_exists( $value ) { return in_array( $value, array( 'product_cat', 'category' ), true ); }
function post_type_exists( $value ) { return in_array( $value, array( 'product', 'page', 'post' ), true ); }
function wp_get_object_terms( $post_id, $taxonomy, $args = array() ) {
	if ( 42 === $post_id && 'product_cat' === $taxonomy ) {
		return array( 9 ); // Taladros.
	}
	if ( 42 === $post_id && 'category' === $taxonomy ) {
		return array( 7 ); // Blog general, not a valid product image source.
	}
	return array();
}
function term_exists( $term_id, $taxonomy = '' ) {
	return (int) $term_id > 0;
}
function get_term( $term_id, $taxonomy = '' ) {
	return 9 === (int) $term_id ? new WP_Term( 9, 'Taladros' ) : new WP_Term( 7, 'General' );
}
function get_term_meta( $term_id, $key, $single = false ) {
	if ( 'thumbnail_id' === $key ) {
		return 9 === (int) $term_id ? 91 : 93;
	}
	return '';
}
function get_post_meta( $post_id, $key, $single = false ) {
	return '_product_image_gallery' === $key && 201 === (int) $post_id ? '94' : '';
}
function apply_filters( $hook, $value, ...$args ) {
	return $value;
}
function get_posts( $args ) {
	if ( 'product' === ( $args['post_type'] ?? '' ) ) {
		return array( 201 );
	}
	return array();
}
function get_the_title( $post_id ) {
	return 201 === (int) $post_id ? 'Taladro de prueba' : 'Otro';
}
function get_post_thumbnail_id( $post_id ) {
	return 201 === (int) $post_id ? 92 : 0;
}
function wp_attachment_is_image( $id ) {
	return in_array( (int) $id, array( 91, 92, 93, 94 ), true );
}
function get_attached_file( $id ) {
	return __FILE__; // A readable test fixture: production code checks existence only.
}

require_once dirname( __DIR__, 2 ) . '/taxocommerce-images/includes/class-taxocommerce-images-assignment.php';

function tci_assert( $condition, $reason ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAILED: $reason\n" );
		exit( 1 );
	}
}

$scopes = TaxoCommerce_Images_Assignment::scopes();
tci_assert( array_keys( $scopes ) === array( 'page', 'post', 'product_cat', 'product' ), 'Four sections' );

$relations = TaxoCommerce_Images_Assignment::related_terms( 'page', 42 );
tci_assert( $relations['product_cat'] === array( 9 ), 'Real commercial category is identified' );
tci_assert( array() === $relations['category'], 'Generic WordPress category is excluded when product category exists' );

$suggestions = TaxoCommerce_Images_Assignment::candidates( 'page', 42, 4 );
$ids = array_column( $suggestions, 'id' );
tci_assert( $ids === array( 91, 92, 94 ), 'Prioritize category image, then product featured and gallery' );
tci_assert( ! in_array( 93, $ids, true ), 'Never suggest unrelated images' );

$unlinked = TaxoCommerce_Images_Assignment::candidates( 'page', 44, 4 );
tci_assert( array() === $unlinked, 'Content without a verified category receives no random image' );

$category = TaxoCommerce_Images_Assignment::candidates( 'product_cat', 9, 3 );
tci_assert( array_column( $category, 'id' ) === array( 91, 92, 94 ), 'Native category itself follows the same selection rules' );

echo "TaxoCommerce Images category-only assignment smoke passed.\n";
