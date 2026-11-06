<?php
/**
 * Regression for short native titles and useful ALT in homepage links.
 * Run: php tests/bing-title-home-alt-smoke.php
 */
define( 'ABSPATH', __DIR__ );
$GLOBALS['tct_is_admin'] = false;
function is_admin() { return $GLOBALS['tct_is_admin']; }
function apply_filters( $name, $value ) { return $value; }
require dirname( __DIR__ ) . '/includes/class-tct-seo-title.php';
function tct_title_assert( $condition, $label ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL );
        exit( 1 );
    }
}
$original = array( 'title' => 'Sensores domóticos', 'site' => 'Distribuidor de herramientas profesionales y de hogar en España' );
$reduced = TCT_SEO_Title::concise_parts( $original );
tct_title_assert( isset( $reduced['title'] ) && ! isset( $reduced['site'] ), 'Drops overlong site suffix without changing category title' );
$short = array( 'title' => 'Sensores domóticos', 'site' => 'Herramientas' );
tct_title_assert( TCT_SEO_Title::concise_parts( $short ) === $short, 'Keeps short title unchanged' );
$GLOBALS['tct_is_admin'] = true;
tct_title_assert( TCT_SEO_Title::concise_parts( $original ) === $original, 'Never changes admin titles' );
foreach ( array( 'desktop', 'mobile' ) as $variant ) {
    $filename = dirname( __DIR__ ) . '/templates/template-front-' . $variant . '.php';
    $code = file_get_contents( $filename );
    tct_title_assert( false !== $code, 'Homepage template is readable' );
    tct_title_assert( ! preg_match( '/<img\\b[^>]*\\balt=["\\x27]["\\x27]/i', $code ), 'No meaningless empty ALT on linked homepage images: ' . $variant );
    tct_title_assert( false !== strpos( $code, 'esc_attr($dht_interest_term->name)' ), 'Category card ALT uses category name: ' . $variant );
    tct_title_assert( false !== strpos( $code, 'esc_attr(get_the_title($dht_area_id))' ), 'Cluster/hub card ALT uses associated title: ' . $variant );
    tct_title_assert( false !== strpos( $code, 'esc_attr(get_the_title($article))' ), 'Article card ALT uses article title: ' . $variant );
}
echo "SEO title and homepage ALT regression: PASS\n";
