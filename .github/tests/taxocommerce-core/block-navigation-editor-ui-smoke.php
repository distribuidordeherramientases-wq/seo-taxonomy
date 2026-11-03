<?php
/** Unified menu UI smoke: real Core classes against isolated WordPress stubs. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $message;
    public function __construct( $code, $message ) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
class TaxoCommerce_Core_Menu {
    public static function normalize_code( $value ) { return ''; }
    public static function country( $write = false ) { return ''; }
}
class TaxoCommerce_Core_Country { const META = '_seo_pais_origen'; }
class WP_Query {
    public $posts;
    public function __construct( $args ) {
        $this->posts = array_values( array_filter( $GLOBALS['posts'], static function( $post ) use ( $args ) {
            return $post->post_type === $args['post_type'] && 'publish' === $post->post_status;
        } ) );
    }
}
function __( $v, $domain = '' ) { return $v; }
function esc_html__( $v, $domain = '' ) { return esc_html( $v ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $v ) { return esc_html( $v ); }
function esc_url( $v ) { return esc_html( $v ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_specialchars_decode( $v, $flags ) { return html_entity_decode( $v, $flags, 'UTF-8' ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function taxonomy_exists( $type ) { return in_array( $type, array( 'category', 'product_cat' ), true ); }
function wp_is_block_theme() { return $GLOBALS['block_theme']; }
function apply_filters( $name, $value ) { return $value; }
function current_user_can( $cap, $id = 0 ) { return true; }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function selected( $a, $b, $echo = true ) {
    $value = (string) $a === (string) $b ? ' selected="selected"' : '';
    if ( $echo ) { echo $value; }
    return $value;
}
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="valid">'; }
function get_the_title( $post ) { return $post->post_title; }
function get_edit_post_link( $id, $context = '' ) { return admin_url( 'post.php?post=' . $id ); }
function get_stylesheet() { return 'testtheme'; }
function get_posts( $args ) {
    if ( 'wp_navigation' === ( $args['post_type'] ?? '' ) ) {
        return array_values( array_filter( $GLOBALS['posts'], static function( $post ) {
            return 'wp_navigation' === $post->post_type && 'publish' === $post->post_status;
        } ) );
    }
    return array();
}
function get_terms( $args ) {
    return array( (object) array( 'term_id' => 71, 'name' => 'Tools', 'taxonomy' => $args['taxonomy'] ) );
}
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function get_block_templates( $query, $type ) {
    if ( 'wp_template' === $type ) {
        return array( (object) array( 'slug' => 'index', 'title' => 'Index', 'content' => 'index-template' ) );
    }
    return array( (object) array(
        'id' => 'testtheme//header', 'theme' => 'testtheme', 'slug' => 'header',
        'title' => 'Header', 'source' => 'theme', 'wp_id' => 0, 'content' => 'header-template',
    ) );
}
function parse_blocks( $source ) { return $GLOBALS['blocks'][ $source ] ?? array(); }
$GLOBALS['posts'] = array(
    77 => (object) array( 'ID' => 77, 'post_type' => 'wp_navigation', 'post_status' => 'publish',
        'post_title' => 'My actual navigation', 'post_content' => 'navigation-data' ),
    88 => (object) array( 'ID' => 88, 'post_type' => 'page', 'post_status' => 'publish',
        'post_title' => 'About us', 'post_password' => '' ),
);
$GLOBALS['blocks'] = array(
    'index-template' => array( array( 'blockName' => 'core/template-part', 'attrs' => array( 'slug' => 'header' ), 'innerBlocks' => array() ) ),
    'header-template' => array( array( 'blockName' => 'core/navigation', 'attrs' => array( 'ref' => 77 ), 'innerBlocks' => array() ) ),
    'navigation-data' => array(
        array( 'blockName' => 'core/navigation-submenu', 'attrs' => array( 'label' => 'About', 'url' => 'https://example.test/about' ),
            'innerHTML' => '', 'innerBlocks' => array(
                array( 'blockName' => 'core/navigation-link', 'attrs' => array( 'label' => 'Staff', 'url' => 'https://example.test/staff' ),
                    'innerHTML' => '', 'innerBlocks' => array() ),
            ), 'innerContent' => array( '', null, '' ) ),
        array( 'blockName' => 'core/navigation-link', 'attrs' => array( 'label' => 'Blog', 'url' => 'https://example.test/blog' ),
            'innerHTML' => '', 'innerBlocks' => array() ),
    ),
);
$GLOBALS['block_theme'] = true;
$_GET = array( 'page' => 'taxocommerce-core-menu' );
require_once dirname( __DIR__, 3 ) . '/taxocommerce-core/includes/class-taxocommerce-core-menu-editor.php';
require_once dirname( __DIR__, 3 ) . '/taxocommerce-core/includes/class-taxocommerce-core-block-navigation.php';
function check( $cond, $why ) {
    if ( ! $cond ) { throw new RuntimeException( $why ); }
    $GLOBALS['checks'] = ( $GLOBALS['checks'] ?? 0 ) + 1;
}
ob_start();
TaxoCommerce_Core_Menu_Editor::render_wordpress_screen();
$html = ob_get_clean();
check( strpos( $html, 'Gestor de menús' ) !== false, 'Unified editor title' );
check( strpos( $html, 'class="tc-menu-load"' ) !== false, 'Shared native menu loader' );
check( strpos( $html, 'id="tc-block-menu-existing"' ) !== false, 'Real block menu load dropdown' );
check( strpos( $html, 'My actual navigation' ) !== false, 'Active referenced navigation shown' );
check( strpos( $html, 'id="tc-menu-save-form"' ) !== false, 'Same form contract as classic editor' );
check( substr_count( $html, '>Guardar menú<' ) === 2, 'Same top and bottom save controls' );
check( substr_count( $html, 'data-position=' ) === 2, 'Same top and bottom publication sections' );
check( strpos( $html, 'class="tc-menu-editor"' ) !== false, 'Same two-column menu structure' );
check( strpos( $html, 'class="tc-menu-structure"' ) !== false, 'Same nested structure container' );
check( substr_count( $html, 'class="tc-menu-box"' ) === 5, 'WordPress Pages/Posts/Custom/Category/WooCommerce object pickers' );
check( strpos( $html, 'name="object_ids[]"' ) !== false, 'Native object selection is available' );
check( strpos( $html, 'value="taxocommerce_core_add_block_navigation_objects"' ) !== false, 'Block object action actually submitted' );
check( strpos( $html, 'value="taxocommerce_core_add_block_navigation_link"' ) !== false, 'Custom links submitted to native block action' );
check( strpos( $html, 'class="tc-menu-depth-1"' ) !== false, 'Submenu children visually nested' );
check( strpos( $html, 'name="nav_links[1][label]"' ) !== false, 'Nested child editable in main save form' );
check( strpos( $html, 'data-tc-menu-remove' ) !== false, 'Leaf link removal available' );
check( strpos( $html, 'value="taxocommerce_core_remove_block_navigation_link"' ) !== false, 'Removal uses guarded block controller' );
check( strpos( $html, 'value="taxocommerce_core_activate_block_navigation"' ) !== false, 'Activation offered for actual header templates' );
check( strpos( $html, 'name="tc_menu_id"' ) === false, 'Must not confuse a wp_navigation post with a classic menu' );
preg_match_all( '/\bid="([^"]+)"/', $html, $ids );
check( count( $ids[1] ) === count( array_unique( $ids[1] ) ), 'Unique form/selector IDs' );

// An unreferenced navigation must not be falsely advertised as published.
$GLOBALS['blocks']['header-template'] = array( array( 'blockName' => 'core/navigation', 'attrs' => array(), 'innerBlocks' => array() ) );
$GLOBALS['posts'][100] = (object) array( 'ID' => 100, 'post_type' => 'wp_navigation', 'post_status' => 'publish',
    'post_title' => 'Another saved navigation', 'post_content' => 'navigation-data' );
ob_start();
TaxoCommerce_Core_Menu_Editor::render_wordpress_screen();
$ambiguous = ob_get_clean();
check( strpos( $ambiguous, 'class="tc-menu-editor"' ) === false, 'Never guess one navigation when several exist' );
check( strpos( $ambiguous, 'name="tc_nav_id"' ) !== false, 'Both menu choices still available' );
$_GET['tc_nav_id'] = '77';
ob_start();
TaxoCommerce_Core_Menu_Editor::render_wordpress_screen();
$chosen = ob_get_clean();
check( strpos( $chosen, 'class="tc-menu-editor"' ) !== false, 'Explicit navigation selection remains editable' );
echo 'PASS: ' . $GLOBALS['checks'] . " unified block menu UI checks (isolated WordPress stubs).\n";
