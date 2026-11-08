<?php
/**
 * Isolated Code Check regression: PHP parser, WordPress checks and security.
 *
 * @package TaxoCommerceSystemCheck
 */
if ( 'cli' !== PHP_SAPI ) {
    exit( 1 );
}
define( 'ABSPATH', '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'TAXOCOMMERCE_SYSTEM_CHECK_VERSION', '0.2.21' );
define( 'TAXOCOMMERCE_SYSTEM_CHECK_PATH', dirname( __DIR__ ) . '/' );

function __( $value, $domain = '' ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function tcs_code_expect( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . "\n" );
        exit( 1 );
    }
}
require dirname( __DIR__ ) . '/includes/class-taxocommerce-system-check-code-check.php';

$valid = TaxoCommerce_System_Check_Code_Check::php_check( '<?php function tcs_example() { return 2; }' );
$invalid = TaxoCommerce_System_Check_Code_Check::php_check( '<?php function tcs_example( { return 2; }' );
tcs_code_expect( 'pass' === $valid['status'], 'Valid PHP must pass native syntax parse.' );
tcs_code_expect( 'fail' === $invalid['status'], 'Invalid PHP must fail native syntax parse.' );

$unsafe = TaxoCommerce_System_Check_Code_Check::security_check( "<?php\n exec('id');\n" );
$comment = TaxoCommerce_System_Check_Code_Check::security_check( "<?php /* exec('id') */\n echo 'shell_exec';\n" );
tcs_code_expect( 'warning' === $unsafe['status'] && 'exec' === $unsafe['evidence']['calls'][0]['function'], 'Real unsafe PHP calls must be flagged.' );
tcs_code_expect( 'pass' === $comment['status'], 'Comments and literal strings must not produce false warnings.' );

$wp_good = TaxoCommerce_System_Check_Code_Check::wordpress_check( "<?php defined( 'ABSPATH' ) || exit;", 'includes/test.php' );
$wp_bad = TaxoCommerce_System_Check_Code_Check::wordpress_check( "<?php echo 'hello';", 'includes/test.php' );
tcs_code_expect( 'pass' === $wp_good['status'] && 'warning' === $wp_bad['status'], 'WordPress ABSPATH guard must be checked.' );
tcs_code_expect( in_array( 'nonce_flow', $wp_good['evidence']['not_verified'], true ), 'Unverified WordPress security rules must never be marked certified.' );

$files = TaxoCommerce_System_Check_Code_Check::files();
tcs_code_expect( in_array( 'taxocommerce-system-check.php', $files, true ), 'Only this plugin must be scanned.' );
foreach ( $files as $file ) {
    tcs_code_expect( false === strpos( $file, '..' ) && false === strpos( $file, 'seo-taxonomy/' ), 'No monolith or parent paths may be scanned.' );
}
tcs_code_expect( count( TaxoCommerce_System_Check_Code_Check::speeds() ) === 5, 'Exactly five CPU speeds.' );
$state = array( 'status' => 'running', 'files' => $files, 'cursor' => 0, 'results' => array(), 'speed' => 1 );
$next = TaxoCommerce_System_Check_Code_Check::process( $state );
tcs_code_expect( 1 === $next['cursor'], 'Very low speed must inspect exactly one file.' );
$next['status'] = 'paused';
$paused = TaxoCommerce_System_Check_Code_Check::process( $next );
tcs_code_expect( 1 === $paused['cursor'], 'Pause must prevent additional file reads.' );
$next['status'] = 'running';
$next = TaxoCommerce_System_Check_Code_Check::process( $next );
tcs_code_expect( 2 === $next['cursor'], 'Resume must continue from the prior cursor.' );
$outside = TaxoCommerce_System_Check_Code_Check::check_file( '../../seo-taxonomy/bootstrap.php' );
tcs_code_expect( 'skip' === $outside['php']['status'], 'Never read outside System Check.' );
echo "TaxoCommerce System Check Code Check: PASS\n";
