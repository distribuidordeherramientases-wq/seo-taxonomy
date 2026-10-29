<?php
/**
 * Core Auto Test internal-navigation regression.
 * No WordPress runtime or database required.
 */
$root = dirname( __DIR__ );
$core = file_get_contents( $root . '/includes/class-taxocommerce-core.php' );
$home = file_get_contents( $root . '/includes/class-taxocommerce-core-dashboard.php' );
$test = file_get_contents( $root . '/includes/class-taxocommerce-core-auto-test.php' );
$guide = file_get_contents( $root . '/includes/class-taxocommerce-core-user-guide.php' );
$main = file_get_contents( $root . '/taxocommerce-core.php' );

function tc_assert_navigation( $ok, $message ) {
	if ( ! $ok ) {
		fwrite( STDERR, "FAIL Core Auto Test navigation: " . $message . PHP_EOL );
		exit( 1 );
	}
}

tc_assert_navigation(
	false === strpos( $core, "TaxoCommerce_Core_Auto_Test::PAGE_SLUG,\n\t\t\tarray( 'TaxoCommerce_Core_Auto_Test', 'render' )" ),
	'Auto Test must not register a WordPress sidebar submenu'
);
tc_assert_navigation( false !== strpos( $core, "'auto-test' === \$tab" ), 'Core dashboard handles its own Auto Test tab' );
tc_assert_navigation( false !== strpos( $core, "TaxoCommerce_Core_Auto_Test::render();" ), 'Auto Test is rendered by Core dashboard' );
tc_assert_navigation( false !== strpos( $home, "esc_html_e( 'Auto Test', 'taxocommerce-core' )" ), 'Core overview has Auto Test tab' );
tc_assert_navigation( false !== strpos( $test, "nav-tab nav-tab-active" ), 'Auto Test marks its own tab active' );
tc_assert_navigation( false !== strpos( $guide, "esc_html_e( 'Auto Test', 'taxocommerce-core' )" ), 'User Guide navigation includes Auto Test' );
tc_assert_navigation( false === strpos( $home, "'slug'        => TaxoCommerce_Core_Auto_Test::PAGE_SLUG" ), 'no duplicate Auto Test dashboard module' );
tc_assert_navigation( false !== strpos( $core, 'redirect_old_auto_test_page' ), 'legacy route redirected' );
tc_assert_navigation( false !== strpos( $core, "if ( TaxoCommerce_Core_Auto_Test::PAGE_SLUG !== \$page )" ), 'redirect recognizes legacy slug' );
tc_assert_navigation( false !== strpos( $test, "array( 'page' => 'taxocommerce-core', 'tab' => 'auto-test' )" ), 'run POST redirects to internal tab' );
tc_assert_navigation( false !== strpos( $test, "check_admin_referer( self::NONCE, 'tc_autotest_nonce' )" ), 'execution remains nonce-guarded' );
tc_assert_navigation( false !== strpos( $main, "TAXOCOMMERCE_CORE_VERSION', '0.4.37'" ), 'Core version bumped' );
echo "PASS Core Auto Test internal navigation: no sidebar, tabs, legacy redirects and POST preserved." . PHP_EOL;
