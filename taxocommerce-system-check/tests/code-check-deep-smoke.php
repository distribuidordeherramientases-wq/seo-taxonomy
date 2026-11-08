<?php
/**
 * Regression fixtures for native Code Check static analysis.
 *
 * @package TaxoCommerceSystemCheck
 */
if ( 'cli' !== PHP_SAPI ) {
    exit( 1 );
}
define( 'ABSPATH', '/' );
define( 'TAXOCOMMERCE_SYSTEM_CHECK_PATH', dirname( __DIR__ ) . '/' );
function __( $value, $domain = '' ) { return $value; }
function tcs_deep_expect( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . "\n" );
        exit( 1 );
    }
}
require_once dirname( __DIR__ ) . '/includes/class-taxocommerce-system-check-code-rules.php';

$rules = TaxoCommerce_System_Check_Code_Rules::structure(
    "<?php\n require_once TAXOCOMMERCE_SYSTEM_CHECK_PATH . 'taxocommerce-system-check.php';\n",
    'includes/demo.php'
);
tcs_deep_expect( ! $rules['findings'], 'An existing local include must not fail.' );

$missing = TaxoCommerce_System_Check_Code_Rules::structure(
    "<?php\n require_once TAXOCOMMERCE_SYSTEM_CHECK_PATH . 'includes/does-not-exist-audit-2384.php';\n",
    'includes/demo.php'
);
tcs_deep_expect(
    'missing_required_file' === $missing['findings'][0]['rule']
    && 'fail' === $missing['findings'][0]['severity']
    && 2 === $missing['findings'][0]['line'],
    'Missing local includes must be proven, classified and line-numbered.'
);

$unknown = TaxoCommerce_System_Check_Code_Rules::structure(
    '<?php include $variable_from_runtime;',
    'includes/demo.php'
);
tcs_deep_expect(
    'dynamic_include' === $unknown['unverified'][0]['rule']
    && ! $unknown['findings'],
    'Dynamic includes must be marked unverified, not failed.'
);

$outside = TaxoCommerce_System_Check_Code_Rules::structure(
    "<?php require __DIR__ . '/../../../../elsewhere.php';",
    'includes/demo.php'
);
tcs_deep_expect(
    'include_outside_plugin' === $outside['findings'][0]['rule'],
    'Paths outside this module must never be treated as verified local dependencies.'
);

$declared = TaxoCommerce_System_Check_Code_Rules::structure(
    '<?php namespace Foo; class ExampleClass { public function action() {} } function example_func() {}',
    'includes/sample.php'
);
$names = array_column( $declared['declarations'], 'display' );
tcs_deep_expect(
    in_array( 'Foo\ExampleClass', $names, true )
    && in_array( 'Foo\example_func', $names, true )
    && ! in_array( 'Foo\action', $names, true ),
    'Named classes and global functions must be indexed without including methods.'
);

$parse = TaxoCommerce_System_Check_Code_Rules::compatibility(
    '<?php $x = match ($x) { 1 => 2, default => 3 }; str_contains("abc","a");'
);
$ids = array_column( $parse, 'rule' );
tcs_deep_expect(
    in_array( 'php_8_match', $ids, true ) && in_array( 'php_8_str_contains', $ids, true ),
    'PHP 8 indicators must be identified when the minimum supported PHP is 7.4.'
);
$comment = TaxoCommerce_System_Check_Code_Rules::compatibility(
    '<?php /* match ($x) {} */ echo "str_contains";'
);
tcs_deep_expect( ! $comment, 'Comments and strings cannot be reported as PHP 8 features.' );

$unsafe = TaxoCommerce_System_Check_Code_Rules::security_signals(
    '<?php $x=$_POST["name"]; echo $x; register_rest_route("tcs/v1","item",array());'
);
$signals = array_column( $unsafe, 'rule' );
tcs_deep_expect(
    in_array( 'input_sanitization_review', $signals, true )
    && in_array( 'output_escaping_review', $signals, true )
    && in_array( 'rest_permission_callback_review', $signals, true ),
    'Untrusted inputs, unescaped output and missing REST callback must raise review signals.'
);

$safer = TaxoCommerce_System_Check_Code_Rules::security_signals(
    '<?php $x = sanitize_text_field( wp_unslash( $_POST["name"] ) ); echo esc_html( $x );'
);
tcs_deep_expect( ! $safer, 'Recognized sanitation and escaping should avoid simple false positives.' );

// Test cross-file symbol collision without requiring any real plugin source.
require_once dirname( __DIR__ ) . '/includes/class-taxocommerce-system-check-code-check.php';
$symbol = $declared['declarations'][0];
$state = array(
    'results' => array(
        'one.php' => array( 'wordpress' => array( 'status' => 'pass', 'evidence' => array( 'declarations' => array( $symbol ), 'findings' => array() ) ) ),
        'two.php' => array( 'wordpress' => array( 'status' => 'pass', 'evidence' => array( 'declarations' => array( $symbol ), 'findings' => array() ) ) ),
    ),
);
$state = TaxoCommerce_System_Check_Code_Check::index_symbols( $state, 'one.php' );
$state = TaxoCommerce_System_Check_Code_Check::index_symbols( $state, 'two.php' );
tcs_deep_expect(
    'warning' === $state['results']['one.php']['wordpress']['status']
    && 'warning' === $state['results']['two.php']['wordpress']['status']
    && 'duplicate_symbol' === $state['results']['two.php']['wordpress']['evidence']['findings'][0]['rule'],
    'Duplicates must be reported in both files without treating conditional loading as confirmed fatal.'
);

echo "TaxoCommerce Code Check deep static rules: PASS\n";
