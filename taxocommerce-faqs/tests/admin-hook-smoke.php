<?php
// Regression guard: WP hooks may only point to public methods.
$faq = file_get_contents( dirname( __DIR__ ) . '/includes/class-taxocommerce-faqs.php' );
if ( false === strpos( $faq, "public static function ensure_category()" ) ||
    false !== strpos( $faq, "private static function ensure_category()" ) ||
    false === strpos( $faq, "add_action( 'admin_init', array( __CLASS__, 'ensure_category' ) )" ) ) {
    fwrite( STDERR, "FAQ callback visibility regression\n" );
    exit( 1 );
}
echo "FAQ admin_init callback visibility OK\n";
