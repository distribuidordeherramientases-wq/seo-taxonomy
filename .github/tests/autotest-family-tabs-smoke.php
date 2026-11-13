<?php
/**
 * Keep all modular TaxoCommerce Autotest screens consistent.
 *
 * @package TaxoCommerce
 */
if ( 'cli' !== PHP_SAPI ) {
    exit( 1 );
}
$repo = dirname( __DIR__, 2 );
$plugins = array(
    'auditor', 'campaigns', 'connections', 'core', 'database', 'dependiente',
    'distribution', 'facturacion', 'faqs', 'google-bing', 'images',
    'import-export', 'logistica', 'redirects', 'scrappers', 'social',
    'suppliers', 'system-check', 'tasks', 'templates', 'vocabulary',
);
foreach ( $plugins as $slug ) {
    $root = $repo . '/taxocommerce-' . $slug;
    $main = $root . '/taxocommerce-' . $slug . '.php';
    if ( ! is_file( $main ) ) {
        fwrite( STDERR, 'Missing modular plugin: ' . $slug . PHP_EOL );
        exit( 1 );
    }
    if ( 'system-check' === $slug ) {
        $functional = file_get_contents( $root . '/includes/class-taxocommerce-system-check-auto-test.php' );
        $code = file_get_contents( $root . '/includes/class-taxocommerce-system-check-code-check.php' );
        if ( false === strpos( $functional, 'tcs_subtab' )
            || false === strpos( $functional, 'TaxoCommerce_System_Check_Code_Check::render()' )
            || false === strpos( $code, 'function render(' ) ) {
            fwrite( STDERR, 'System Check must contain the two Autocheck subtabs.' . PHP_EOL );
            exit( 1 );
        }
        continue;
    }
    $entry = file_get_contents( $main );
    $inspector = $root . '/includes/class-taxocommerce-autotest-code.php';
    if ( ! is_file( $inspector ) || ! is_file( $root . '/assets/autotest-code.js' ) ) {
        fwrite( STDERR, 'Missing native Code Check in ' . $slug . PHP_EOL );
        exit( 1 );
    }
    if ( false === strpos( $entry, 'class-taxocommerce-autotest-code.php' )
        || false === strpos( $entry, 'Autotest_Code::boot()' ) ) {
        fwrite( STDERR, 'Code Check is not bootstrapped in ' . $slug . PHP_EOL );
        exit( 1 );
    }
    $checker = file_get_contents( $inspector );
    if ( false === strpos( $checker, 'function render_subtabs(' )
        || false === strpos( $checker, "'Functionality Check'" )
        || false === strpos( $checker, "'Code Check'" )
        || false === strpos( $checker, 'function analyze_source(' )
        || false === strpos( $checker, "'tc_autotest_section'" ) ) {
        fwrite( STDERR, 'Autotest code engine incomplete in ' . $slug . PHP_EOL );
        exit( 1 );
    }
    if ( false !== strpos( $checker, 'self::DOMAIN )' ) ) {
        fwrite( STDERR, 'Dynamic gettext textdomain in ' . $slug . PHP_EOL );
        exit( 1 );
    }
    // Core must render both choices inside its existing Autocheck dashboard, not above the header.
    if ( 'core' === $slug ) {
        $core_screen = (string) file_get_contents( $root . '/includes/class-taxocommerce-core-auto-test.php' );
        $shell = strpos( $core_screen, 'id="tc-core-autotest"' );
        $main_nav = strpos( $core_screen, 'Navegación de TaxoCommerce Core' );
        $subtabs = strpos( $core_screen, 'Autotest_Code::render_subtabs()' );
        $functional_form = strpos( $core_screen, 'tc_autotest_manual_run' );
        if ( false === $shell || false === $main_nav || false === $subtabs || false === $functional_form
            || ! ( $shell < $main_nav && $main_nav < $subtabs && $subtabs < $functional_form )
            || false === strpos( $core_screen, "if ( ! \\TaxoCommerce_Core_Autotest_Code::render_subtabs() ) :" ) ) {
            fwrite( STDERR, 'Core Code Check must appear inside Autocheck after its main navigation, independently of Functional Check.' . PHP_EOL );
            exit( 1 );
        }
    }
    // Each plugin must actually call the subtab renderer in its functional screen.
    $candidates = array_merge(
        glob( $root . '/includes/*.php' ) ?: array(),
        array( $main )
    );
    $wired = false;
    foreach ( $candidates as $file ) {
        if ( false !== strpos( (string) file_get_contents( $file ), 'Autotest_Code::render_subtabs()' ) ) {
            $wired = true;
            break;
        }
    }
    if ( ! $wired ) {
        fwrite( STDERR, 'Functional Autotest missing Code Check navigation in ' . $slug . PHP_EOL );
        exit( 1 );
    }
}
echo 'PASS: 21 autonomous TaxoCommerce Autotest screens, functional and code tabs.' . PHP_EOL;
