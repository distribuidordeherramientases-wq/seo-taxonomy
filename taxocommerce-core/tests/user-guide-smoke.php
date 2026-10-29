<?php
/** Read-only Core User Guide contract regression. Run: php taxocommerce-core/tests/user-guide-smoke.php */
$root = dirname( __DIR__ );
$entry = file_get_contents( $root . '/taxocommerce-core.php' );
$core = file_get_contents( $root . '/includes/class-taxocommerce-core.php' );
$home = file_get_contents( $root . '/includes/class-taxocommerce-core-dashboard.php' );
$guide = file_get_contents( $root . '/includes/class-taxocommerce-core-user-guide.php' );
$css = file_get_contents( $root . '/assets/admin.css' );
$checks = array(
	'Guide loaded by Core' => strpos( $entry, "require_once TAXOCOMMERCE_CORE_PATH . 'includes/class-taxocommerce-core-user-guide.php'" ) !== false,
	'Guide admin route exists' => strpos( $core, "TaxoCommerce_Core_User_Guide::PAGE_SLUG" ) !== false,
	'Guide restricted to Core access' => strpos( $guide, "current_user_can( apply_filters( 'taxocommerce_core_manage_capability', 'manage_options' ) )" ) !== false,
	'Guide is read-only' => strpos( $guide, '<form' ) === false && strpos( $guide, 'update_option(' ) === false,
	'Dashboard navigation includes Guide' => strpos( $home, "'title'       => __( 'User Guide'" ) !== false,
	'Dashboard tabs include Guide' => strpos( $home, 'nav-tab-wrapper' ) !== false && strpos( $home, 'TaxoCommerce_Core_User_Guide::PAGE_SLUG' ) !== false,
	'Guide heading hierarchy' => strpos( $guide, '<h1>' ) !== false && strpos( $guide, '<h2>' ) !== false && strpos( $guide, '<h3>' ) !== false,
	'Guide links all Core functions' => strpos( $guide, 'taxocommerce-core-hierarchy' ) !== false && strpos( $guide, 'taxocommerce-core-menu' ) !== false && strpos( $guide, 'taxocommerce-core-content' ) !== false,
	'Guide accessible index' => strpos( $guide, 'tcc-guide-index' ) !== false && strpos( $guide, 'aria-current="page"' ) !== false,
	'Guide responsive layout' => strpos( $css, '@media(max-width:900px){.tcc-guide-layout' ) !== false,
	'Menu labels recognize Guide' => strpos( $core, "'taxocommerce-core-user-guide'=> 'User Guide'" ) !== false,
);
foreach ( $checks as $message => $ok ) {
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL User Guide: ' . $message . PHP_EOL );
		exit( 1 );
	}
}
echo "TaxoCommerce Core User Guide smoke passed.\n";
