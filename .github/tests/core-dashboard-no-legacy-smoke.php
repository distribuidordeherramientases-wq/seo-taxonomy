<?php
/**
 * Regression for the Core dashboard after removing the legacy SEO card.
 * Execute: php .github/tests/core-dashboard-no-legacy-smoke.php
 */
define( 'ABSPATH', '/' );
define( 'TAXOCOMMERCE_CORE_VERSION', '0.4.57' );
class TaxoCommerce_Core_User_Guide { const PAGE_SLUG = 'taxocommerce-core-user-guide'; }
class TaxoCommerce_Core_Content_Manager { const PAGE_SLUG = 'taxocommerce-core-content'; }
function __( $s, $domain = '' ) { return $s; }
function esc_html_e( $s, $domain = '' ) { echo htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr_e( $s, $domain = '' ) { echo htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function admin_url( $path = '' ) { return '/wp-admin/' . $path; }
function number_format_i18n( $n ) { return number_format( $n ); }
require dirname( __DIR__, 2 ) . '/taxocommerce-core/includes/class-taxocommerce-core-dashboard.php';
function assert_true( $value, $why ) {
    if ( ! $value ) {
        throw new RuntimeException( $why );
    }
}
foreach ( array( true, false ) as $woo ) {
    $called = false;
    ob_start();
    TaxoCommerce_Core_Dashboard::render(
        array( 'module-1' => array(), 'module-2' => array() ),
        $woo,
        static function () use ( &$called ) { $called = true; echo '<div id="extension-catalog"></div>'; }
    );
    $html = ob_get_clean();
    assert_true( $called, 'Extension catalogue callback must still run' );
    assert_true( 3 === substr_count( $html, 'class="tcc-metric"' ), 'Expected exactly 3 useful metrics' );
    assert_true( false === strpos( $html, 'TaxoCommerce SEO actual' ), 'Legacy card must be removed' );
    assert_true( false === strpos( $html, 'Diseñado para convivir' ), 'Migration message must be removed' );
    assert_true( false !== strpos( $html, 'Versión del Core' ), 'Core version must remain' );
    assert_true( false !== strpos( $html, 'WooCommerce' ), 'WooCommerce status must remain' );
    assert_true( false !== strpos( $html, 'Extensiones registradas' ), 'Extension count must remain' );
    assert_true( false !== strpos( $html, 'id="extension-catalog"' ), 'Extension catalogue must remain' );
    foreach ( array( 'taxocommerce-core-user-guide', 'taxocommerce-core-content', 'taxocommerce-core-hierarchy', 'taxocommerce-core-menu' ) as $slug ) {
        assert_true( false !== strpos( $html, 'page=' . $slug ), 'Core module link broken: ' . $slug );
    }
    assert_true( false !== strpos( $html, 'page=taxocommerce-core&amp;tab=autocheck' ), 'Autocheck tab must remain' );
}
echo "OK: three cards, independent of legacy SEO; WooCommerce, extensions and navigation preserved.\n";
