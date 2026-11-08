<?php
/**
 * Read-only family audit regression, no WordPress installation or network.
 *
 * @package TaxoCommerceSystemCheck
 */
define( 'ABSPATH', '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'WP_PLUGIN_DIR', dirname( __DIR__, 2 ) );
define( 'TAXOCOMMERCE_SYSTEM_CHECK_VERSION', '0.2.20' );

$GLOBALS['tcs_family_mutations'] = 0;
function __( $text, $domain = '' ) { return $text; }
function sanitize_text_field( $text ) { return (string) $text; }
function sanitize_key( $text ) { return strtolower( (string) $text ); }
function wp_normalize_path( $text ) { return str_replace( '\\', '/', $text ); }
function is_plugin_active( $entry ) { return false; }
function get_plugins() {
    return array(
        'taxocommerce-scrappers/taxocommerce-scrappers.php' => array( 'Name' => 'Scrappers', 'Version' => '0.1.12', 'TextDomain' => 'taxocommerce-scrappers' ),
        'taxocommerce-system-check/taxocommerce-system-check.php' => array( 'Name' => 'System Check', 'Version' => TAXOCOMMERCE_SYSTEM_CHECK_VERSION, 'TextDomain' => 'taxocommerce-system-check' ),
        'seo-taxonomy/seo-taxonomy.php' => array( 'Name' => 'Old SEO', 'Version' => '2.4.1', 'TextDomain' => 'taxocommerce-seo' ),
        'woocommerce/woocommerce.php' => array( 'Name' => 'WooCommerce', 'Version' => '11.1.2', 'TextDomain' => 'woocommerce' ),
    );
}
function wp_mail() { ++$GLOBALS['tcs_family_mutations']; throw new RuntimeException( 'No mail permitted.' ); }
function wp_remote_get() { ++$GLOBALS['tcs_family_mutations']; throw new RuntimeException( 'No remote calls permitted.' ); }
function update_option() { ++$GLOBALS['tcs_family_mutations']; throw new RuntimeException( 'No option writes permitted.' ); }
function wp_schedule_event() { ++$GLOBALS['tcs_family_mutations']; throw new RuntimeException( 'No schedules permitted.' ); }
function tcs_expect( $yes, $message ) {
    if ( ! $yes ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
}
require_once dirname( __DIR__ ) . '/includes/class-taxocommerce-system-check-family.php';

$plugins = TaxoCommerce_System_Check_Family::inventory();
tcs_expect( count( $plugins ) === 2, 'Must enumerate two installed standalone plugins and no monolith.' );
tcs_expect( ! isset( $plugins['seo-taxonomy/seo-taxonomy.php'] ), 'The old monolith must never be included.' );
$speeds = TaxoCommerce_System_Check_Family::speeds();
tcs_expect( count( $speeds ) === 5 && $speeds[1]['batch'] === 1 && $speeds[5]['batch'] === 4
    && $speeds[1]['delay'] > $speeds[5]['delay'], 'Five bounded CPU speeds required.' );
$state = array( 'status' => 'running', 'speed' => 1, 'plugins' => $plugins, 'cursor' => 0, 'results' => array() );
$next = TaxoCommerce_System_Check_Family::process( $state, $plugins );
tcs_expect( $next['cursor'] === 1 && $next['status'] === 'running', 'Slow speed must process just one plugin per request.' );
$first = reset( $next['results'] );
tcs_expect( $first['official']['status'] === 'not_run', 'Never claim official PCP passed without running it.' );
tcs_expect( $first['runtime']['status'] === 'skip', 'Inactive plugin must not be treated as loaded.' );
tcs_expect( $first['static']['status'] === 'pass', 'Packaged Scrappers should have headers, readme and ABSPATH guard.' );
$next['status'] = 'paused';
$paused = TaxoCommerce_System_Check_Family::process( $next, $plugins );
tcs_expect( $paused['cursor'] === 1, 'Paused scan must not advance.' );
$next['status'] = 'running';
$done = TaxoCommerce_System_Check_Family::process( $next, $plugins );
tcs_expect( $done['cursor'] === 2 && $done['status'] === 'completed', 'Resume must finish without rechecking earlier plugins.' );
tcs_expect( $GLOBALS['tcs_family_mutations'] === 0, 'No remote request, schedule, email or option write permitted.' );
$changed = $plugins;
unset( $changed['taxocommerce-system-check/taxocommerce-system-check.php'] );
$state['speed'] = 5;
$partial = TaxoCommerce_System_Check_Family::process( $state, $changed );
tcs_expect( $partial['status'] === 'completed'
    && $partial['results']['taxocommerce-system-check/taxocommerce-system-check.php']['static']['status'] === 'skip',
    'Missing plugins during a scan must be reported as skipped, never falsely passed.' );
echo "TaxoCommerce System Check family audit: PASS\n";
