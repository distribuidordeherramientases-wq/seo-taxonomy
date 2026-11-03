<?php
/** Offline round-trip tests for native WP term categories and WooCommerce terms. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    public $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $text, $domain = '' ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_title( $value ) { return strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', (string) $value ), '-' ) ); }
function wp_kses_post( $value ) { return (string) $value; }
function wp_unslash( $value ) { return $value; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function get_term_meta( $id, $key, $single = true ) { return $GLOBALS['term_meta'][ $id ][ $key ] ?? ''; }
function update_term_meta( $id, $key, $value ) { $GLOBALS['term_meta'][ $id ][ $key ] = $value; }
function delete_term_meta( $id, $key ) { unset( $GLOBALS['term_meta'][ $id ][ $key ] ); }
function get_term( $id, $taxonomy ) {
    $term = $GLOBALS['terms'][ $taxonomy ][ absint( $id ) ] ?? null;
    return $term ? clone $term : null;
}
function get_term_by( $field, $value, $taxonomy ) {
    foreach ( $GLOBALS['terms'][ $taxonomy ] ?? array() as $term ) {
        if ( (string) $term->$field === (string) $value ) { return clone $term; }
    }
    return false;
}
function get_terms( $args ) {
    $terms = array_values( $GLOBALS['terms'][ $args['taxonomy'] ] ?? array() );
    if ( isset( $args['include'] ) && $args['include'] ) {
        $terms = array_values( array_filter( $terms, static function( $term ) use ( $args ) {
            return in_array( $term->term_id, $args['include'], true );
        } ) );
    }
    if ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) {
        return array_map( static function( $term ) { return $term->term_id; }, $terms );
    }
    return $terms;
}
function wp_count_terms( $args ) { return count( $GLOBALS['terms'][ $args['taxonomy'] ] ?? array() ); }
function wp_insert_term( $name, $taxonomy, $args = array() ) {
    $slug = $args['slug'] ?? sanitize_title( $name );
    $old = get_term_by( 'slug', $slug, $taxonomy );
    if ( $old ) { return new WP_Error( 'term_exists' ); }
    $id = ++$GLOBALS['next_id'];
    $GLOBALS['terms'][ $taxonomy ][ $id ] = (object) array(
        'term_id' => $id, 'taxonomy' => $taxonomy, 'name' => $name,
        'slug' => $slug, 'parent' => $args['parent'] ?? 0,
        'description' => $args['description'] ?? '',
    );
    return array( 'term_id' => $id );
}
function wp_update_term( $id, $taxonomy, $args ) {
    if ( ! isset( $GLOBALS['terms'][ $taxonomy ][ $id ] ) ) { return new WP_Error( 'missing' ); }
    $term = $GLOBALS['terms'][ $taxonomy ][ $id ];
    foreach ( $args as $key => $value ) { $term->$key = $value; }
    return array( 'term_id' => $id );
}
function assert_ie( $ok, $label ) {
    if ( ! $ok ) { fwrite( STDERR, "FAIL: " . $label . PHP_EOL ); exit( 1 ); }
}
$GLOBALS['next_id'] = 700;
$GLOBALS['terms'] = array(
    'category' => array(
        10 => (object) array( 'term_id' => 10, 'taxonomy' => 'category',
            'name' => 'Noticias', 'slug' => 'noticias', 'parent' => 0, 'description' => '<p>Descripción WP</p>' ),
        30 => (object) array( 'term_id' => 30, 'taxonomy' => 'category',
            'name' => 'Empresa', 'slug' => 'empresa', 'parent' => 0, 'description' => '' ),
    ),
    'product_cat' => array(
        10 => (object) array( 'term_id' => 10, 'taxonomy' => 'product_cat',
            'name' => 'Ropa', 'slug' => 'ropa', 'parent' => 0, 'description' => '<p>Catálogo</p>' ),
    ),
);
$GLOBALS['term_meta'] = array( 10 => array( 'seo_excerpt' => '<em>Extracto</em>' ) );
require dirname( __DIR__ ) . '/includes/class-taxocommerce-import-export-csv.php';
require dirname( __DIR__ ) . '/includes/class-taxocommerce-import-export-exporter.php';
$csv = new TaxoCommerce_Import_Export_CSV();
assert_ie( array_key_exists( 'wp_categories', TaxoCommerce_Import_Export_Exporter::types() ), 'Native dataset in UI registry' );
$exporter = new TaxoCommerce_Import_Export_Exporter( $csv );
$next_ids = new ReflectionMethod( $exporter, 'next_ids' );
$filtered = $next_ids->invoke( $exporter, 'wp_categories', 0, 15, array( 'wp_category_ids' => array( 30 ) ) );
assert_ie( $filtered === array( 30 ), 'WordPress-category selection is independent of WooCommerce term IDs' );
$all = $next_ids->invoke( $exporter, 'wp_categories', 0, 15, array() );
assert_ie( $all === array( 10, 30 ), 'Empty selection exports all native categories' );
$count = new ReflectionMethod( $exporter, 'count_total' );
assert_ie( $count->invoke( $exporter, 'wp_categories', array( 'wp_category_ids' => array( 30 ) ) ) === 1, 'Native filtered total' );
assert_ie( $count->invoke( $exporter, 'wp_categories', array() ) === 2, 'Native all total' );

$native_header = $csv->export_header_for_job( 'wp_categories' );
$product_header = $csv->export_header_for_job( 'product_categories' );
assert_ie( in_array( 'excerpt', $native_header, true ) && in_array( 'description', $native_header, true ), 'Native excerpt and body' );
assert_ie( in_array( 'excerpt', $product_header, true ) && in_array( 'parent_slug', $product_header, true ), 'Product categories gain portable fields' );
assert_ie( end( $product_header ) === 'parent_slug' && $product_header[7] === 'country_code', 'Legacy product category column order intact' );
assert_ie( $csv->normalize_headers_for_batch( array( 'excerpt' ) ) === array( 'excerpt' ), 'Excerpt is not silently mapped to product short description' );
$row = $csv->export_row_for_job( 'wp_categories', 10 );
assert_ie( $row[0] === 'wp_category' && $row[1] === 10 && $row[5] === '<p>Descripción WP</p>', 'Native category exported, not product category' );
assert_ie( $row[6] === '<em>Extracto</em>', 'Additional excerpt from seo_excerpt' );
$old = $csv->export_row_for_job( 'product_categories', 10 );
assert_ie( $old[0] === 'product_category' && $old[5] === '<p>Catálogo</p>', 'WooCommerce taxonomy remains independent' );
$legacy_product_cat = array(
    'record_type' => 'product_category', 'id' => 10, 'name' => 'Ropa',
    'slug' => 'ropa-nueva', 'description' => '<p>Catálogo actualizado</p>',
);
assert_ie( true === $csv->import_row_for_batch( $legacy_product_cat, array( 'type' => 'product_category' ) ), 'Legacy Woo category ID update remains supported' );
assert_ie( get_term( 10, 'product_cat' )->slug === 'ropa-nueva', 'Legacy product category slug editing preserved' );

$native = array_combine( $native_header, $row );
$native['name'] = 'Noticias renovadas';
assert_ie( true === $csv->import_row_for_batch( $native, array( 'type' => 'wp_category' ) ), 'Native import updates existing slug' );
assert_ie( get_term( 10, 'category' )->name === 'Noticias renovadas', 'Native update persisted' );
assert_ie( get_term( 10, 'product_cat' )->name === 'Ropa', 'Product category untouched by native update' );
// IDs from other sites must not overwrite a local category with a different slug.
$foreign = $native;
$foreign['id'] = '30';
$foreign['slug'] = 'noticias';
$foreign['name'] = 'Noticias ajustadas';
assert_ie( true === $csv->import_row_for_batch( $foreign, array( 'type' => 'wp_category' ) ), 'Foreign ID resolves native category by slug' );
assert_ie( get_term( 30, 'category' )->name === 'Empresa' && get_term( 10, 'category' )->name === 'Noticias ajustadas', 'Foreign ID cannot overwrite unrelated category' );
$new = $native;
$new['id'] = '30';
$new['slug'] = 'innovacion';
$new['name'] = 'Innovación';
$new['parent_slug'] = 'noticias';
$new['description'] = '<p>Contenido hijo</p>';
$new['excerpt'] = '<b>Nuevo extracto</b>';
assert_ie( true === $csv->import_row_for_batch( $new, array( 'type' => 'wp_category' ) ), 'New category imported despite foreign ID collision' );
$created = get_term_by( 'slug', 'innovacion', 'category' );
assert_ie( $created && $created->term_id !== 30 && $created->parent === 10, 'Hierarchy resolved from native parent slug' );
assert_ie( get_term_meta( $created->term_id, 'seo_excerpt' ) === '<b>Nuevo extracto</b>', 'Excerpt termmeta saved' );
assert_ie( $created->description === '<p>Contenido hijo</p>', 'Native HTML description persisted' );
$missing = $new;
$missing['slug'] = 'sin-padre';
$missing['parent_slug'] = 'no-existe';
assert_ie( is_wp_error( $csv->import_row_for_batch( $missing, array( 'type' => 'wp_category' ) ) ), 'Unknown parent cannot silently become root' );
assert_ie( ! get_term_by( 'slug', 'sin-padre', 'category' ), 'Failed parent validation must not create term' );
$old_format = array( 'record_type' => 'wp_category', 'id' => 10, 'name' => 'Noticias antiguas', 'slug' => 'noticias' );
assert_ie( true === $csv->import_row_for_batch( $old_format, array( 'type' => 'wp_category' ) ), 'Old partial format accepted' );
assert_ie( get_term( 10, 'category' )->description === '<p>Descripción WP</p>', 'Missing description preserves current value' );
assert_ie( get_term_meta( 10, 'seo_excerpt' ) === '<em>Extracto</em>', 'Missing excerpt preserves current value' );
$old_format['excerpt'] = '';
assert_ie( true === $csv->import_row_for_batch( $old_format, array( 'type' => 'wp_category' ) ), 'Explicit empty excerpt accepted' );
assert_ie( get_term_meta( 10, 'seo_excerpt' ) === '', 'Empty excerpt clears additional metadata' );
$native_row = $csv->export_row_for_job( 'wp_categories', $created->term_id );
assert_ie( $native_row[7] === 'noticias', 'Export parent slug for portable import' );
$GLOBALS['term_meta'][ $created->term_id ]['seo_excerpt'] = '';
$GLOBALS['term_meta'][ $created->term_id ]['excerpt'] = 'Extracto antiguo';
assert_ie( $csv->export_row_for_job( 'wp_categories', $created->term_id )[6] === 'Extracto antiguo', 'Legacy excerpt termmeta fallback works' );

echo "PASS: native WP and WooCommerce category CSV roundtrip, identity, hierarchy and excerpts.\n";
