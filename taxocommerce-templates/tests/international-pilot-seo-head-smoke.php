<?php
/**
 * Regression of international VEVOR SEO head for FR/DE/NL/US.
 *
 * @package TaxoCommerce_Templates
 */
define( 'ABSPATH', __DIR__ );
function wp_head() { echo $GLOBALS['pilot_head']; }
function wp_strip_all_tags( $text, $remove_breaks = false ) { return strip_tags( $text ); }
function strip_shortcodes( $text ) { return $text; }
function wp_html_excerpt( $text, $length, $more = '' ) { return substr( $text, 0, $length ) . $more; }
function apply_filters( $hook, $value ) { return $value; }
function esc_attr( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url_raw( $text ) { return $text; }
function is_paged() { return ! empty( $GLOBALS['pilot_paged'] ); }
function get_query_var( $field ) { return $GLOBALS['pilot_paged']; }
function get_pagenum_link( $page ) { return 'https://example.test/tienda/vevor-fr-peinture/page/' . $page . '/'; }
function absint( $number ) { return abs( (int) $number ); }
function fail_pilot( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: " . $message . PHP_EOL );
		exit( 1 );
	}
}
require dirname( __DIR__ ) . '/templates/template-pilot-seo.php';
foreach ( array( 'fr-FR', 'de-DE', 'nl-NL', 'en-US' ) as $locale ) {
	$GLOBALS['pilot_paged'] = 0;
	$GLOBALS['pilot_head'] = '<meta content="width=device-width" name="viewport">'
		. '<meta content="code1" name="p:domain_verify">'
		. '<meta name="p:domain_verify" content="code1">'
		. '<link hreflang="' . $locale . '" rel="alternate" href="https://example.test/locale/">'
		. '<link href="https://example.test/old/" rel="canonical">'
		. '<meta content="existing SEO description" name="description">'
		. '<meta name="description" content="second SEO description">'
		. '<script>window.pilotHead = true;</script>';
	ob_start();
	TCT_Pilot_SEO_Head::render( 'https://example.test/correct/', 'Localized original description' );
	$out = ob_get_clean();
	fail_pilot( 1 === substr_count( $out, 'name="description"' ), $locale . ': duplicate descriptions' );
	fail_pilot( false === strpos( $out, 'second SEO description' ), $locale . ': second description persists' );
	fail_pilot( 1 === substr_count( $out, 'rel="canonical"' ), $locale . ': canonical absent or duplicate' );
	fail_pilot( false !== strpos( $out, 'https://example.test/correct/' ), $locale . ': wrong canonical' );
	fail_pilot( false === strpos( $out, 'name="viewport"' ), $locale . ': extra viewport' );
	fail_pilot( 1 === substr_count( $out, 'name="p:domain_verify"' ), $locale . ': duplicate Pinterest verification' );
	fail_pilot( false !== strpos( $out, 'hreflang="' . $locale . '"' ), $locale . ': hreflang removed' );
	fail_pilot( false !== strpos( $out, '<script>window.pilotHead' ), $locale . ': script removed' );

	$GLOBALS['pilot_head'] = '';
	ob_start();
	TCT_Pilot_SEO_Head::render( 'https://example.test/correct/', '<p>Una descripción propia en ' . $locale . '</p>' );
	$out = ob_get_clean();
	fail_pilot( false !== strpos( $out, 'name="description"' ), $locale . ': missing fallback description' );
	fail_pilot( false !== strpos( $out, 'Una descripción propia' ), $locale . ': missing localized content' );
}
$GLOBALS['pilot_paged'] = 2;
$GLOBALS['pilot_head']  = '';
ob_start();
TCT_Pilot_SEO_Head::render( 'https://example.test/tienda/vevor-fr-peinture/', 'Contenu en français' );
$out = ob_get_clean();
fail_pilot( false !== strpos( $out, '/page/2/' ), 'Pagination canonical must identify page 2' );

echo "International pilot SEO head regression: PASS\n";
