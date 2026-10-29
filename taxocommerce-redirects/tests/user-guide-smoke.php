<?php
/**
 * Standalone user/technical guide regressions: render real admin routing
 * without loading Store, touching MySQL, or executing redirects/deletions.
 *
 * Run: php taxocommerce-redirects/tests/user-guide-smoke.php
 */
define( 'ABSPATH', __DIR__ );

function current_user_can( $capability ) { return isset( $GLOBALS['guide_allowed'] ) ? $GLOBALS['guide_allowed'] : true; }
function wp_die( $message, $title = '', $args = array() ) { throw new \RuntimeException( $message ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . ( false !== strpos( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); }
function esc_url( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_html_e( $value, $domain = '' ) { echo esc_html( $value ); }
function esc_attr_e( $value, $domain = '' ) { echo esc_attr( $value ); }
function plugins_url( $path, $plugin = '' ) { return 'https://example.test/wp-content/plugins/taxocommerce-redirects/' . $path; }
function wp_enqueue_style( $handle, $src, $deps, $version ) { $GLOBALS['guide_styles'][ $handle ] = $src; }
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['guide_hooks'][ $hook ][] = $callback; }

require dirname( __DIR__ ) . '/includes/class-user-guide.php';
require dirname( __DIR__ ) . '/includes/class-admin.php';

function verify_guide( $condition, $description ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $description . PHP_EOL );
        exit( 1 );
    }
}

\TaxoCommerce\Redirects\User_Guide::register();
verify_guide( ! empty( $GLOBALS['guide_hooks']['admin_enqueue_scripts'] ), 'Guide stylesheet hook registered' );

$_GET['tc_rd_tab'] = 'user-guide';
$_GET['tc_rd_manual'] = 'user';
\TaxoCommerce\Redirects\User_Guide::assets( 'toplevel_page_taxocommerce-redirects' );
verify_guide( isset( $GLOBALS['guide_styles']['tc-redirects-user-guide'] ), 'Guide CSS loaded only on its tab' );
ob_start();
\TaxoCommerce\Redirects\Admin::render();
$user = ob_get_clean();
verify_guide( false !== strpos( $user, 'Manual de usuario y manual técnico' ), 'Single documentation page' );
verify_guide( false !== strpos( $user, 'nav-tab-active' ), 'WordPress native tab active' );
verify_guide( false !== strpos( $user, 'User Manual' ) && false !== strpos( $user, 'Technical Manual' ), 'Both manual selectors visible' );
verify_guide( false !== strpos( $user, 'tc-rd-user-intro' ) && false !== strpos( $user, 'tc-rd-user-autotest' ), 'User manual sections rendered' );
verify_guide( false !== strpos( $user, '#tc-rd-user-faq' ), 'User manual index linked' );
verify_guide( 9 === substr_count( $user, '<section id="tc-rd-user-' ), 'Nine user manual sections' );
verify_guide( false !== strpos( $user, 'Borrado asistido' ) && false !== strpos( $user, 'Desinstalar' ), 'User manual covers deletion and lifecycle' );

$_GET['tc_rd_manual'] = 'technical';
ob_start();
\TaxoCommerce\Redirects\Admin::render();
$technical = ob_get_clean();
verify_guide( 12 === substr_count( $technical, '<section id="tc-rd-tech-' ), 'Twelve technical manual sections' );
verify_guide( false !== strpos( $technical, 'tc-rd-tech-runtime' ) && false !== strpos( $technical, 'tc-rd-tech-lifecycle' ), 'Technical implementation documented' );
verify_guide( false !== strpos( $technical, '#tc-rd-tech-security' ) && false !== strpos( $technical, 'Store::diagnostics' ), 'Technical index and architecture present' );
verify_guide( false !== strpos( $technical, 'aria-current="page"' ), 'Active manual accessibility indicator' );

$_GET['tc_rd_manual'] = 'unknown';
ob_start();
\TaxoCommerce\Redirects\Admin::render();
$default = ob_get_clean();
verify_guide( false !== strpos( $default, 'tc-rd-user-intro' ), 'Unknown manual falls back to user guide' );
$GLOBALS['guide_allowed'] = false;
try {
    \TaxoCommerce\Redirects\Admin::render();
    verify_guide( false, 'Guide must enforce manage_options' );
} catch ( \RuntimeException $e ) {
    verify_guide( false !== strpos( $e->getMessage(), 'permisos' ), 'Unauthorized access blocked' );
}

echo 'Redirects user and technical manual smoke passed.' . PHP_EOL;
