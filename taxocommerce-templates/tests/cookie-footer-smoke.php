<?php
/** Standalone regression for country-scoped public footer cookies. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'OBJECT', 'OBJECT' );
define( 'TCT_VERSION', '0.4.25' );
define( 'TCT_URL', 'https://example.test/wp-content/plugins/taxocommerce-templates/' );

class WP_Post {
    public $ID;
    public $post_status;
    public function __construct( $id, $status ) { $this->ID = $id; $this->post_status = $status; }
}
class TaxoCommerce_Insights_Consent {
    public static $enabled = false;
    public static function active() { return self::$enabled; }
}
$GLOBALS['pages'] = array();
$GLOBALS['markets'] = array();
$GLOBALS['scripts'] = array();
function get_page_by_path( $slug, $format = OBJECT, $type = 'page' ) {
    return $GLOBALS['pages'][ $slug ] ?? null;
}
function get_post_meta( $id, $key, $single ) { return $GLOBALS['markets'][ $id ] ?? ''; }
function get_permalink( $post ) { return 'https://example.test/' . $post->ID . '/'; }
function is_wp_error( $value ) { return false; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function wp_enqueue_script( $handle, $url, $deps, $ver, $footer ) {
    $GLOBALS['scripts'][ $handle ] = array( $url, $ver, $footer );
}
function check( $ok, $reason ) {
    if ( ! $ok ) { fwrite( STDERR, "FAIL: " . $reason . "\n" ); exit( 1 ); }
}
require dirname( __DIR__ ) . '/templates/template-cookie-footer.php';
$markets = dht_template_cookie_footer_markets();
check( count( $markets ) === 5, 'Five independent countries' );
$id = 100;
foreach ( $markets as $market => $row ) {
    $GLOBALS['pages'][ $row['slug'] ] = new WP_Post( ++$id, 'publish' );
    $GLOBALS['markets'][ $id ] = $market;
    check( dht_template_cookie_policy_url( $market ) === 'https://example.test/' . $id . '/',
        'Correct published policy for ' . $market );
    ob_start();
    dht_template_render_cookie_footer_links( $market );
    $html = ob_get_clean();
    check( strpos( $html, esc_html( $row['policy'] ) ) !== false, 'Translated policy label ' . $market );
    check( strpos( $html, 'data-tci-open-preferences' ) === false, 'No dead button if Insights disabled' );
}
check( dht_template_cookie_policy_url( 'XX' ) === '', 'No unknown country fallback' );
$GLOBALS['markets'][ $id ] = 'ES';
check( dht_template_cookie_policy_url( 'NL' ) === '', 'Country mismatch never links another market' );
$GLOBALS['markets'][ $id ] = 'NL';
$GLOBALS['pages'][ 'cookiebeleid-nl' ]->post_status = 'draft';
check( dht_template_cookie_policy_url( 'NL' ) === '', 'No public link to drafts' );
$GLOBALS['pages'][ 'cookiebeleid-nl' ]->post_status = 'publish';
TaxoCommerce_Insights_Consent::$enabled = true;
ob_start();
dht_template_render_cookie_footer_links( 'FR' );
$html = ob_get_clean();
check( strpos( $html, 'data-tci-open-preferences' ) !== false, 'Reopen button shown when Insights active' );
check( isset( $GLOBALS['scripts']['taxocommerce-templates-cookie-footer'] ), 'Script enqueued' );
check( strpos( $GLOBALS['scripts']['taxocommerce-templates-cookie-footer'][0], 'footer-cookie-preferences.js' ) !== false, 'JS correctly located' );
check( strpos( file_get_contents( dirname( __DIR__ ) . '/assets/footer-cookie-preferences.js' ),
    "getElementById('tci-consent-reopen')" ) !== false, 'Uses existing Insights reopen control' );
echo 'Templates cookie footer: five markets, publication rules and reopening PASS' . PHP_EOL;
