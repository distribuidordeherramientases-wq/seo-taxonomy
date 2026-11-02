<?php
/** Isolated smoke test for header navigation detection and safe attribute-only edits. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $message;
    public function __construct( $code, $message ) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $string, $domain = '' ) { return $string; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function esc_url_raw( $value, $allowed = array() ) {
    return preg_match( '~^https?://~', $value ) ? $value : '';
}
function get_block_templates( $query, $type ) {
    if ( 'wp_template' === $type ) {
        return array( (object) array( 'slug' => 'home', 'title' => 'Home', 'content' => 'home-template' ) );
    }
    return array( (object) array( 'id' => 'testtheme//header', 'theme' => 'testtheme', 'wp_id' => 0, 'slug' => 'header', 'title' => 'Site header', 'source' => 'theme', 'content' => 'header-part' ) );
}
function parse_blocks( $source ) { return $GLOBALS['fixtures'][ $source ] ?? array(); }
function get_post( $id ) { return $GLOBALS['post_fixtures'][ $id ] ?? null; }
function get_stylesheet() { return 'testtheme'; }
$GLOBALS['post_fixtures'] = array(
    77 => (object) array( 'ID' => 77, 'post_type' => 'wp_navigation', 'post_status' => 'publish' ),
    88 => (object) array( 'ID' => 88, 'post_type' => 'page', 'post_status' => 'publish' ),
);
$GLOBALS['fixtures'] = array(
    'home-template' => array(
        array( 'blockName' => 'core/template-part', 'attrs' => array( 'slug' => 'header' ), 'innerBlocks' => array() ),
    ),
    'header-part' => array(
        array( 'blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array(
            array( 'blockName' => 'core/navigation', 'attrs' => array( 'ref' => 77 ), 'innerBlocks' => array() ),
        ) ),
    ),
);
require_once dirname( __DIR__, 3 ) . '/taxocommerce-core/includes/class-taxocommerce-core-block-navigation.php';
function ensure( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
$targets_method = new ReflectionMethod( 'TaxoCommerce_Core_Block_Navigation', 'editable_header_parts' );
$targets = $targets_method->invoke( null );
if ( 1 !== count( $targets ) || 'testtheme//header' !== $targets[0]['id'] ) {
    throw new RuntimeException( 'Theme-file header is not available for controlled activation' );
}
$found = TaxoCommerce_Core_Block_Navigation::header_references();
ensure( isset( $found['menus'][77] ), 'Must resolve navigation actually referenced by header' );
ensure( ! isset( $found['menus'][88] ), 'Must not treat arbitrary posts as navigation' );
ensure( ! $found['inline'], 'A referenced navigation must not be classified as inline' );
$blocks = array(
    array(
        'blockName' => 'core/navigation-submenu', 'attrs' => array( 'label' => 'About', 'url' => 'https://example.test/about', 'className' => 'keep-me' ),
        'innerHTML' => '', 'innerBlocks' => array(
            array( 'blockName' => 'core/navigation-link', 'attrs' => array( 'label' => 'Old title', 'url' => 'https://example.test/old' ),
                'innerHTML' => '', 'innerBlocks' => array() ),
            array( 'blockName' => 'core/search', 'attrs' => array( 'label' => 'Search' ), 'innerHTML' => '', 'innerBlocks' => array() ),
        ),
    ),
);
$method = new ReflectionMethod( 'TaxoCommerce_Core_Block_Navigation', 'update_links' );
$method->setAccessible( true );
$index = 0;
$updates = array(
    array( 'label' => 'About us', 'url' => 'https://example.test/about-us' ),
    array( 'label' => 'New title', 'url' => 'https://example.test/new' ),
);
$result = $method->invokeArgs( null, array( &$blocks, $updates, &$index ) );
ensure( true === $result, 'Expected successful block navigation edits' );
ensure( 2 === $index, 'Must count both supported navigation links' );
ensure( 'About us' === $blocks[0]['attrs']['label'], 'Submenu label must be updated' );
ensure( 'keep-me' === $blocks[0]['attrs']['className'], 'Block classes must be retained' );
ensure( 'New title' === $blocks[0]['innerBlocks'][0]['attrs']['label'], 'Nested navigation link must update' );
ensure( 'Search' === $blocks[0]['innerBlocks'][1]['attrs']['label'], 'Unsupported blocks must remain unchanged' );
$index = 0;
$error = $method->invokeArgs( null, array( &$blocks, array( array( 'label' => 'Only one', 'url' => 'https://example.test/' ) ), &$index ) );
ensure( is_wp_error( $error ), 'Incomplete form must not save a partial menu' );
$counter = new ReflectionMethod( 'TaxoCommerce_Core_Block_Navigation', 'count_navigation_blocks' );
$count = 0;
$counter->invokeArgs( null, array( $GLOBALS['fixtures']['header-part'], &$count ) );
ensure( 1 === $count, 'Single-header navigation detection failed' );
$setter = new ReflectionMethod( 'TaxoCommerce_Core_Block_Navigation', 'set_reference' );
$header_blocks = $GLOBALS['fixtures']['header-part'];
$updated = $setter->invokeArgs( null, array( &$header_blocks, 1234 ) );
ensure( true === $updated, 'Header activation must update exactly one navigation reference' );
ensure( 1234 === $header_blocks[0]['innerBlocks'][0]['attrs']['ref'], 'Header navigation reference mismatch' );
ensure( 'core/group' === $header_blocks[0]['blockName'], 'Header wrapper must be preserved' );
$refs = new ReflectionMethod( 'TaxoCommerce_Core_Block_Navigation', 'links' );
$loaded = array();
$refs->invokeArgs( null, array( $blocks, &$loaded ) );
ensure( 2 === count( $loaded ), 'Classic and nested navigation block links must be enumerable' );
echo "Block navigation detection and update smoke passed.\n";
