<?php
/**
 * Isolated native Code Check proof, no WordPress installation.
 *
 * @package TaxoCommerceImages
 */
define( 'ABSPATH', '/' );
function __( $message, $domain = '' ) { return $message; }
function absint( $value ) { return abs( (int) $value ); }
function tci_code_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . "\n" );
        exit( 1 );
    }
}
require dirname( __DIR__ ) . '/includes/class-taxocommerce-autotest-code.php';
$cls = 'TaxoCommerce_Images_Autotest_Code';
$root = realpath( dirname( __DIR__ ) );
$valid = $cls::analyze_source( "<?php defined('ABSPATH') || exit; class ImagePluginCheck {}", 'includes/fixture.php', $root );
tci_code_assert( 'pass' === $valid['php']['status'], 'Correct PHP must pass.' );
$invalid = $cls::analyze_source( '<?php function broken( {', 'includes/fixture.php', $root );
tci_code_assert( 'fail' === $invalid['php']['status'], 'Invalid PHP must fail.' );
$unsafe = $cls::analyze_source( "<?php eval('echo 1;'); shell_exec('whoami');", 'includes/fixture.php', $root );
tci_code_assert( 'warning' === $unsafe['security']['status'], 'Risky calls must be detected without executing them.' );
$missing = $cls::analyze_source( "<?php require_once __DIR__ . '/never-exists-2401.php';", 'includes/fixture.php', $root );
tci_code_assert( 'warning' === $missing['wordpress']['status'], 'Missing local require must be detected.' );
$files = $cls::files();
tci_code_assert( in_array( 'taxocommerce-images.php', $files, true ), 'Main file is included.' );
tci_code_assert( count( $cls::speeds() ) === 5, 'Five CPU speeds required.' );
echo "Images Code Check: PASS\n";
