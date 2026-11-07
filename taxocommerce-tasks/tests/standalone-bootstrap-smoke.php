<?php
/**
 * Standalone, deliberately minimal boot test for CI.
 * WordPress is not loaded; the ABSPATH guard must prevent registration.
 */
$source = file_get_contents( dirname( __DIR__ ) . '/taxocommerce-tasks.php' );
$required = array( 'defined( \'ABSPATH\' ) || exit;', 'dbDelta(', 'admin_post_tctasks_save_task', 'admin_post_tctasks_delete_task', 'admin_post_tctasks_save_category', 'admin_post_tctasks_delete_category', 'admin_post_tctasks_autocheck_download', 'check_admin_referer', 'current_user_can', 'wp_json_encode', "'executed_at'", "'rows'", "'totals'" );
foreach ( $required as $needle ) {
    if ( false === strpos( $source, $needle ) ) {
        fwrite( STDERR, "FAIL: $needle\n" );
        exit( 1 );
    }
}
if ( preg_match( '/register_post_type|register_taxonomy|add_shortcode|register_rest_route/', $source ) ) {
    fwrite( STDERR, "FAIL: public or post-based storage detected\n" );
    exit( 1 );
}
echo "PASS: standalone agenda boot/static safety contract.\n";
