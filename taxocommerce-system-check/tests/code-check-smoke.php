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
define( 'TAXOCOMMERCE_SYSTEM_CHECK_VERSION', '0.2.23' );
define( 'TAXOCOMMERCE_SYSTEM_CHECK_PATH', dirname( __DIR__ ) . '/' );

function __( $value, $domain = '' ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function tcs_code_expect( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . "\n" );
        exit( 1 );
    }
}
require dirname( __DIR__ ) . '/includes/class-taxocommerce-system-check-code-rules.php';
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

// The two checks are separate top-level tabs, not nested inside Autocheck.
$main_source = file_get_contents( dirname( __DIR__ ) . '/taxocommerce-system-check.php' );
$auto_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-taxocommerce-system-check-auto-test.php' );
$code_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-taxocommerce-system-check-code-check.php' );
tcs_code_expect( false !== strpos( $main_source, "'code-check' => __( 'Code Check', 'taxocommerce-system-check' )" ), 'Code Check must have its own primary tab.' );
tcs_code_expect( false !== strpos( $main_source, "'code-check' === " . '$tab' ), 'Primary Code Check must render without Autocheck.' );
tcs_code_expect( false === strpos( $auto_source, 'tcs_subtab' ), 'Autocheck must not include a second nested code navigation.' );
tcs_code_expect( false !== strpos( $auto_source, "Run Functionality Check" ), 'Existing functionality check must stay intact.' );
tcs_code_expect( false !== strpos( $code_source, "taxocommerce_system_check_tab_url( 'code-check' )" ), 'Postback must return to primary Code Check tab.' );

$counts = TaxoCommerce_System_Check_Code_Check::summary( array(
    'first.php' => array(
        'php' => array( 'status' => 'pass' ),
        'wordpress' => array( 'status' => 'warning' ),
        'security' => array( 'status' => 'skip' ),
    ),
    'second.php' => array(
        'php' => array( 'status' => 'fail' ),
        'wordpress' => array( 'status' => 'pass' ),
        'security' => array( 'status' => 'warning' ),
    ),
) );
tcs_code_expect( 1 === $counts['php']['pass'] && 1 === $counts['php']['fail'], 'PHP syntax successes must not conceal failures.' );
tcs_code_expect( 1 === $counts['wordpress']['warning'] && 1 === $counts['security']['skip'] && 1 === $counts['security']['warning'], 'Unverified or risky findings must never count as passed.' );

// Render a real report without WordPress or installing other plugins.
function current_user_can( $capability ) { return 'manage_options' === $capability; }
function get_current_blog_id() { return 2; }
function get_current_user_id() { return 3; }
function get_transient( $key ) { return $GLOBALS['tcs_code_fixture'] ?? false; }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $value, $domain = '' ) { return esc_html( $value ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function selected( $a, $b, $echo = true ) { return $a === $b ? ' selected="selected"' : ''; }
function submit_button( $label, $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button>' . esc_html( $label ) . '</button>'; }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="test">'; }
$GLOBALS['tcs_code_fixture'] = array(
    'files' => array( 'includes/sample.php' ),
    'results' => array(
        'includes/sample.php' => array(
            'php' => array( 'status' => 'pass', 'message' => 'Parsed', 'evidence' => array() ),
            'wordpress' => array( 'status' => 'warning', 'message' => 'Review', 'evidence' => array( 'missing' => array( 'ABSPATH guard' ) ) ),
            'security' => array( 'status' => 'warning', 'message' => 'Review', 'evidence' => array( 'calls' => array( array( 'function' => 'exec', 'line' => 23 ) ) ) ),
        ),
    ),
    'cursor' => 1,
    'status' => 'completed',
    'speed' => 3,
);
ob_start();
TaxoCommerce_System_Check_Code_Check::render();
$html = ob_get_clean();
tcs_code_expect( false !== strpos( $html, 'Run Code Check' ) && false !== strpos( $html, 'includes/sample.php' ), 'Code Check must expose the real scan and files.' );
tcs_code_expect( false !== strpos( $html, 'ABSPATH guard' ) && false !== strpos( $html, 'exec #23' ), 'Code Check must present actionable evidence in its table.' );
tcs_code_expect( false !== strpos( $html, 'Download JSON report' ) && false === strpos( $html, 'tcs_subtab' ), 'Code Check must export JSON without old nested navigation.' );

echo "TaxoCommerce System Check Code Check: PASS\n";
