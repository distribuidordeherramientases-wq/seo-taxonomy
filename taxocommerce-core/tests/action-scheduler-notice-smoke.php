<?php
/**
 * Verify that native Action Scheduler overdue notices are not suppressed.
 *
 * @package TaxoCommerceCore
 */
$source = file_get_contents( dirname( __DIR__ ) . '/taxocommerce-core.php' );
if ( false === $source ) {
    fwrite( STDERR, "Core entrypoint is unreadable.\n" );
    exit( 1 );
}
if ( false !== strpos( $source, 'action_scheduler_check_pastdue_actions' ) ) {
    fwrite( STDERR, "Core must not override native overdue-action warnings.\n" );
    exit( 1 );
}
if ( false !== strpos( $source, "remove_all_actions( 'admin_notices'" ) ||
    false !== strpos( $source, "remove_all_filters( 'admin_notices'" ) ||
    false !== strpos( $source, "remove_action( 'action_scheduler_run_queue'" ) ) {
    fwrite( STDERR, "Broad notice or queue suppression is forbidden.\n" );
    exit( 1 );
}
echo "PASS: native Action Scheduler overdue-actions notices preserved.\n";
